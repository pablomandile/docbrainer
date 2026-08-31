<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\ChunkDocument;
use App\Jobs\EmbedDocumentChunks;
use App\Jobs\ExtractDocumentText;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function store(StoreDocumentRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $provider = $request->validated('embedding_provider', 'gemini');

        foreach ($request->file('files', []) as $file) {
            $this->storeOne($project, $file, $provider);
        }

        return back()->with('success', 'Documentos subidos. El procesamiento comenzó.');
    }

    private function storeOne(Project $project, UploadedFile $file, string $provider): void
    {
        $extension = strtolower($file->getClientOriginalExtension());

        $document = $project->documents()->create([
            'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => '', // se completa tras mover el archivo
            'mime_type' => $file->getMimeType(),
            'extension' => $extension,
            'size_bytes' => $file->getSize(),
            'status' => DocumentStatus::Pending,
            'embedding_provider' => $provider,
        ]);

        $path = $file->storeAs(
            "projects/{$project->id}/documents/{$document->id}",
            "original.{$extension}",
            'local',
        );

        $document->forceFill(['stored_path' => $path])->save();

        self::dispatchPipeline($document);
    }

    /**
     * Encadena el pipeline de procesamiento completo:
     * extraer → trocear → vectorizar.
     */
    public static function dispatchPipeline(Document $document): void
    {
        $documentId = $document->id;

        Bus::chain([
            new ExtractDocumentText($documentId),
            new ChunkDocument($documentId),
            new EmbedDocumentChunks($documentId),
        ])->catch(function (\Throwable $e) use ($documentId) {
            $doc = Document::find($documentId);

            if ($doc !== null && $doc->status !== DocumentStatus::Failed) {
                $doc->markFailed('Error en el procesamiento: '.$e->getMessage());
            }
        })->dispatch();
    }

    /**
     * Reintenta el procesamiento completo de un documento fallido.
     */
    public function retry(Project $project, Document $document): RedirectResponse
    {
        Gate::authorize('update', $project);

        abort_unless($document->project_id === $project->id, 404);

        if ($document->status !== DocumentStatus::Failed) {
            return back()->with('error', 'Solo se pueden reintentar documentos fallidos.');
        }

        // Limpia artefactos para que la extracción re-ejecute desde cero
        Storage::disk('local')->delete($document->extractedJsonPath());

        $document->forceFill([
            'status' => DocumentStatus::Pending,
            'failure_reason' => null,
            'chunk_count' => 0,
            'embedded_chunk_count' => 0,
        ])->save();

        self::dispatchPipeline($document);

        return back()->with('success', 'Reprocesando documento.');
    }

    /**
     * Re-indexa un documento con el proveedor elegido: borra los vectores
     * y re-embebe los chunks existentes (el troceado no cambia).
     */
    public function reindex(Request $request, Project $project, Document $document): RedirectResponse
    {
        Gate::authorize('update', $project);

        abort_unless($document->project_id === $project->id, 404);

        $validated = $request->validate([
            'embedding_provider' => ['required', 'in:gemini,ollama'],
        ]);

        if ($document->status->isProcessing()) {
            return back()->with('error', 'El documento todavía se está procesando.');
        }

        // Documento fallido sin chunks: reintento completo del pipeline
        if ($document->chunks()->count() === 0) {
            $document->forceFill(['embedding_provider' => $validated['embedding_provider']])->save();

            return $this->retry($project, $document);
        }

        $document->chunks()->update([
            'embedding' => null,
            'embedding_model' => null,
            'embedding_dimensions' => null,
            'embedded_at' => null,
        ]);

        $document->forceFill([
            'embedding_provider' => $validated['embedding_provider'],
            'status' => DocumentStatus::Embedding,
            'embedded_chunk_count' => 0,
            'failure_reason' => null,
        ])->save();

        EmbedDocumentChunks::dispatch($document->id);

        return back()->with('success', 'Re-indexando documento.');
    }

    public function download(Project $project, Document $document): StreamedResponse
    {
        Gate::authorize('view', $project);

        abort_unless($document->project_id === $project->id, 404);

        return Storage::disk('local')->download($document->stored_path, $document->original_name);
    }

    public function destroy(Project $project, Document $document): RedirectResponse
    {
        Gate::authorize('update', $project);

        abort_unless($document->project_id === $project->id, 404);

        Storage::disk('local')->deleteDirectory(dirname($document->stored_path));

        $document->delete();

        return back()->with('success', 'Documento eliminado.');
    }

    /**
     * Estado liviano para el polling de la tabla de documentos.
     */
    public function status(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'documents' => $project->documents()
                ->get(['id', 'status', 'failure_reason', 'chunk_count', 'embedded_chunk_count', 'page_count'])
                ->map(fn ($doc) => [
                    'id' => $doc->id,
                    'status' => $doc->status->value,
                    'status_label' => $doc->status->label(),
                    'is_processing' => $doc->status->isProcessing(),
                    'failure_reason' => $doc->failure_reason,
                    'chunk_count' => $doc->chunk_count,
                    'embedded_chunk_count' => $doc->embedded_chunk_count,
                    'page_count' => $doc->page_count,
                ]),
        ]);
    }
}
