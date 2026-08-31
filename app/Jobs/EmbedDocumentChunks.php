<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AI\ApiQuota;
use App\Services\AI\GeminiException;
use App\Services\AI\GeminiRateLimitedException;
use App\Services\AI\LocallyThrottledException;
use App\Services\AI\OllamaException;
use App\Services\AI\OllamaUnavailableException;
use App\Services\AI\ProviderManager;
use App\Services\AI\QuotaExceededException;
use App\Services\Vector\VectorStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Vectoriza los chunks pendientes de un documento, en lotes.
 *
 * Reanudable e idempotente: `embedded_at IS NULL` es la fuente de verdad.
 * Si el worker muere a mitad de camino, el próximo run continúa donde quedó.
 * Ante throttling o cuota agotada hace release() — nunca insiste contra la API.
 */
class EmbedDocumentChunks implements ShouldQueue
{
    use Queueable;

    // release() por throttling/cuota consume intentos: tries=0 (ilimitado)
    // y maxExceptions acota los errores reales.
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 900;

    public function __construct(
        public int $documentId,
    ) {}

    public function handle(ProviderManager $providers, VectorStore $store, ApiQuota $quota): void
    {
        $document = Document::find($this->documentId);

        if ($document === null || $document->status === DocumentStatus::Failed) {
            return;
        }

        if ($document->status !== DocumentStatus::Embedding) {
            $document->forceFill(['status' => DocumentStatus::Embedding])->save();
        }

        try {
            $provider = $providers->embedding($document->embedding_provider);
        } catch (\InvalidArgumentException $e) {
            $document->markFailed($e->getMessage());
            $this->fail($e);

            return;
        }

        $batchSize = $provider->batchSize();

        while (true) {
            /** @var Collection<int, DocumentChunk> $batch */
            $batch = $document->chunks()
                ->whereNull('embedded_at')
                ->orderBy('chunk_index')
                ->limit($batchSize)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $items = $batch->map(fn (DocumentChunk $chunk) => [
                'title' => $document->title.($chunk->section_path ? ' — '.$chunk->section_path : ''),
                'text' => $chunk->content,
            ])->all();

            try {
                $vectors = $provider->embedForIndex($items);
            } catch (LocallyThrottledException) {
                $this->release(65);

                return;
            } catch (QuotaExceededException $e) {
                Log::info('Cuota diaria de embeddings agotada; se reanuda tras el reset.', [
                    'document_id' => $document->id,
                ]);

                $this->release(min($quota->secondsUntilReset() + 60, 60 * 60 * 24));

                return;
            } catch (GeminiRateLimitedException) {
                $this->release(120);

                return;
            } catch (OllamaUnavailableException $e) {
                // Ollama caído a mitad de un job: release, nunca fail definitivo
                Log::info('Ollama no disponible; se reintenta en 60 s.', [
                    'document_id' => $document->id,
                ]);

                $this->release(60);

                return;
            } catch (GeminiException|OllamaException $e) {
                // Error no recuperable (clave inválida, modelo no descargado...)
                $document->markFailed('Error vectorizando: '.$e->getMessage());
                $this->fail($e);

                return;
            }

            foreach ($batch->values() as $i => $chunk) {
                $store->store(
                    chunkId: $chunk->id,
                    vector: $vectors[$i],
                    modelId: $provider->modelId(),
                    dimensions: $provider->dimensions(),
                );
            }

            $document->forceFill([
                'embedded_chunk_count' => $document->chunks()->whereNotNull('embedded_at')->count(),
            ])->save();
        }

        $document->forceFill([
            'status' => DocumentStatus::Ready,
            'embedded_chunk_count' => $document->chunk_count,
            'processed_at' => now(),
        ])->save();
    }

    public function failed(?\Throwable $exception): void
    {
        $document = Document::find($this->documentId);

        if ($document !== null && $document->status !== DocumentStatus::Failed) {
            $document->markFailed(
                'Error inesperado durante la vectorización: '.($exception?->getMessage() ?? 'desconocido')
            );
        }
    }
}
