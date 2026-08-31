<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Extraction\ExtractionFailedException;
use App\Services\Extraction\ExtractorManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ExtractDocumentText implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(
        public int $documentId,
    ) {}

    public function handle(ExtractorManager $extractor): void
    {
        $document = Document::find($this->documentId);

        // Borrado o ya falló antes de llegar acá: nada que hacer
        if ($document === null || $document->status === DocumentStatus::Failed) {
            return;
        }

        $disk = Storage::disk('local');

        // Idempotencia: si ya existe el artefacto (re-ejecución del chain),
        // no repetir la extracción.
        if ($disk->exists($document->extractedJsonPath()) && $document->status !== DocumentStatus::Pending) {
            return;
        }

        $document->forceFill(['status' => DocumentStatus::Extracting, 'failure_reason' => null])->save();

        try {
            $extracted = $extractor->extract($document, $disk->path($document->stored_path));

            if ($extracted->isEmpty()) {
                throw new ExtractionFailedException('El documento no contiene texto extraíble.');
            }

            $disk->put(
                $document->extractedJsonPath(),
                json_encode($extracted->toArray(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            );

            $document->forceFill([
                'status' => DocumentStatus::Chunking,
                'page_count' => $extracted->pageCount,
                'char_count' => $extracted->charCount(),
            ])->save();
        } catch (ExtractionFailedException $e) {
            // Falla "de negocio" (escaneado, corrupto, vacío): sin reintentos.
            // fail() marca el job como fallido y corta la cadena Extract→Chunk→Embed.
            $document->markFailed($e->getMessage());

            $this->fail($e);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $document = Document::find($this->documentId);

        // No pisar una razón de falla ya registrada (p. ej. por ExtractionFailedException)
        if ($document !== null && $document->status !== DocumentStatus::Failed) {
            $document->markFailed(
                'Error inesperado durante la extracción: '.($exception?->getMessage() ?? 'desconocido')
            );
        }
    }
}
