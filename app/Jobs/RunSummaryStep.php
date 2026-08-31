<?php

namespace App\Jobs;

use App\Enums\SummaryStatus;
use App\Models\DocumentChunk;
use App\Models\Summary;
use App\Models\SummaryNode;
use App\Services\AI\ApiQuota;
use App\Services\AI\GeminiException;
use App\Services\AI\GeminiRateLimitedException;
use App\Services\AI\LocallyThrottledException;
use App\Services\AI\OllamaException;
use App\Services\AI\OllamaUnavailableException;
use App\Services\AI\ProviderManager;
use App\Services\AI\QuotaExceededException;
use App\Services\Summary\SummaryPrompts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Resuelve nodos del árbol map-reduce de un resumen, uno por llamada al modelo.
 *
 * Procesa nodos en orden (nivel, índice): cuando llega a un nodo "reduce",
 * sus hijos ya están resueltos. El gate de RPM marca el ritmo: al saturarse,
 * el job hace release() y continúa después — nunca martilla la API.
 *
 * tries=0 (ilimitado) porque el release por throttling consume intentos;
 * maxExceptions acota los errores reales.
 */
class RunSummaryStep implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 900;

    public function __construct(
        public int $summaryId,
    ) {}

    public function handle(ProviderManager $providers, SummaryPrompts $prompts, ApiQuota $quota): void
    {
        $summary = Summary::find($this->summaryId);

        if ($summary === null || in_array($summary->status, [SummaryStatus::Completed, SummaryStatus::Failed], true)) {
            return;
        }

        if ($summary->status === SummaryStatus::Pending) {
            $summary->forceFill(['status' => SummaryStatus::Running])->save();
        }

        // Si el usuario eligió un modelo local específico, usarlo
        $ollamaModel = data_get($summary->user?->ai_settings, 'ollama_chat_model');

        if (is_string($ollamaModel) && $ollamaModel !== '') {
            config(['docbrainer.ollama.chat_model' => $ollamaModel]);
        }

        $chatProvider = $providers->chat($summary->user?->chatProvider() ?? 'gemini');

        $maxLevel = (int) $summary->nodes()->max('level');

        while (true) {
            /** @var SummaryNode|null $node */
            $node = $summary->nodes()
                ->where('status', 'pending')
                ->orderBy('level')
                ->orderBy('node_index')
                ->first();

            if ($node === null) {
                $this->finalize($summary, $maxLevel);

                return;
            }

            $isRoot = $node->level === $maxLevel;
            $prompt = $this->buildPrompt($summary, $node, $isRoot, $prompts);

            try {
                $result = $chatProvider->generate($prompts->systemInstruction(), [
                    ['role' => 'user', 'content' => $prompt],
                ]);
            } catch (LocallyThrottledException) {
                $this->release(65);

                return;
            } catch (QuotaExceededException) {
                $this->release(min($quota->secondsUntilReset() + 60, 60 * 60 * 24));

                return;
            } catch (GeminiRateLimitedException) {
                $this->release(120);

                return;
            } catch (OllamaUnavailableException) {
                $this->release(60);

                return;
            } catch (GeminiException|OllamaException $e) {
                $node->update(['status' => 'failed']);
                $summary->markFailed('Error generando el resumen: '.$e->getMessage());
                $this->fail($e);

                return;
            }

            $node->update(['content' => $result['text'], 'status' => 'done']);

            $summary->forceFill([
                'progress_done' => $summary->nodes()->where('status', 'done')->count(),
                'model' => $result['model'],
            ])->save();
        }
    }

    private function buildPrompt(Summary $summary, SummaryNode $node, bool $isRoot, SummaryPrompts $prompts): string
    {
        if ($node->level === 0) {
            $chunkIds = $node->input_ref['chunk_ids'] ?? [];

            $chunks = DocumentChunk::whereIn('id', $chunkIds)->get()->keyBy('id');

            // Respetar el orden original de los chunks dentro de la unidad
            $material = collect($chunkIds)
                ->map(fn (int $id) => $chunks[$id]?->content)
                ->filter()
                ->implode("\n\n");

            return $isRoot
                ? $prompts->finalPrompt($summary, $material, fromPartials: false)
                : $prompts->mapPrompt($node->input_ref['label'] ?? 'documento', $material);
        }

        $nodeIds = $node->input_ref['node_ids'] ?? [];

        $children = SummaryNode::whereIn('id', $nodeIds)
            ->orderBy('node_index')
            ->pluck('content')
            ->filter()
            ->implode("\n\n---\n\n");

        return $isRoot
            ? $prompts->finalPrompt($summary, $children, fromPartials: true)
            : $prompts->reducePrompt($children);
    }

    private function finalize(Summary $summary, int $maxLevel): void
    {
        $root = $summary->nodes()
            ->where('level', $maxLevel)
            ->where('status', 'done')
            ->first();

        if ($root === null || $root->content === null) {
            $summary->markFailed('El árbol de resumen quedó incompleto. Reintentá la generación.');

            return;
        }

        $summary->forceFill([
            'status' => SummaryStatus::Completed,
            'result' => $root->content,
            'progress_done' => $summary->progress_total,
        ])->save();
    }

    public function failed(?\Throwable $exception): void
    {
        $summary = Summary::find($this->summaryId);

        if ($summary !== null && $summary->status !== SummaryStatus::Failed) {
            $summary->markFailed(
                'Error inesperado generando el resumen: '.($exception?->getMessage() ?? 'desconocido')
            );
        }
    }
}
