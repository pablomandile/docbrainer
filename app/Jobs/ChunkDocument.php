<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Chunking\Chunker;
use App\Services\Extraction\ExtractedDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ChunkDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public int $documentId,
    ) {}

    public function handle(Chunker $chunker): void
    {
        $document = Document::find($this->documentId);

        if ($document === null || $document->status === DocumentStatus::Failed) {
            return;
        }

        $disk = Storage::disk('local');
        $artifactPath = $document->extractedJsonPath();

        if (! $disk->exists($artifactPath)) {
            $document->markFailed('Falta el artefacto de extracción (extracted.json). Reintentá el procesamiento.');
            $this->fail();

            return;
        }

        $extracted = ExtractedDocument::fromArray(
            json_decode($disk->get($artifactPath), true) ?? [],
        );

        $chunks = $chunker->chunk($extracted);

        if ($chunks === []) {
            $document->markFailed('El documento no produjo fragmentos de texto.');
            $this->fail();

            return;
        }

        $now = now();

        $rows = array_map(fn (array $chunk) => [
            ...$chunk,
            'document_id' => $document->id,
            'project_id' => $document->project_id,
            'created_at' => $now,
        ], $chunks);

        DB::transaction(function () use ($document, $rows) {
            // Idempotente: re-ejecutar borra y regenera los chunks
            $document->chunks()->delete();

            foreach (array_chunk($rows, 100) as $batch) {
                DB::table('document_chunks')->insert($batch);
            }

            $document->forceFill([
                'status' => DocumentStatus::Embedding,
                'chunk_count' => count($rows),
                'embedded_chunk_count' => 0,
            ])->save();
        });
    }

    public function failed(?\Throwable $exception): void
    {
        $document = Document::find($this->documentId);

        if ($document !== null && $document->status !== DocumentStatus::Failed) {
            $document->markFailed(
                'Error inesperado durante el troceado: '.($exception?->getMessage() ?? 'desconocido')
            );
        }
    }
}
