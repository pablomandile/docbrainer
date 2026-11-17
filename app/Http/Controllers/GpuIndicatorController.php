<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\AI\GpuStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estado de la GPU para el semáforo del encabezado (lo consulta cada 30 s).
 *
 *  - available (verde): Ollama responde y tiene los dos modelos.
 *  - busy (amarillo): responde y está indexando documentos.
 *  - incomplete (amarillo): responde, pero le falta algún modelo.
 *  - offline (rojo): la PC no responde (apagada, Ollama cerrado o túnel caído).
 *
 * GpuStatus guarda el sondeo 30 s en caché, así que muchos usuarios mirando
 * no multiplican los pedidos por el túnel. Los detalles técnicos (host,
 * versión, modelos) son solo para el admin.
 */
class GpuIndicatorController extends Controller
{
    public function __invoke(Request $request, GpuStatus $gpuStatus): JsonResponse
    {
        if (! config('docbrainer.ollama.enabled')) {
            return $this->json(['state' => 'disabled', 'message' => 'La GPU no está habilitada en este entorno.']);
        }

        $status = $gpuStatus->check();
        $models = $status['ollama']['models'];

        $missing = collect([
            config('docbrainer.ollama.embedding_model'),
            config('docbrainer.ollama.chat_model'),
        ])->reject(fn (string $model) => $this->installed($model, $models))->values()->all();

        // Documentos de cualquier usuario indexándose con la GPU: la placa es una sola.
        $working = Document::query()
            ->where('embedding_provider', 'ollama')
            ->whereIn('status', collect(DocumentStatus::cases())->filter->isProcessing()->map->value->all())
            ->count();

        [$state, $message] = match (true) {
            ! $status['ollama']['available'] => [
                'offline',
                'La PC con GPU no responde. Los documentos que elijas indexar con GPU esperan y siguen solos cuando vuelva; mientras tanto podés usar Gemini.',
            ],
            $missing !== [] => [
                'incomplete',
                'La GPU responde, pero falta descargar '.implode(' y ', $missing).'.',
            ],
            $working > 0 => [
                'busy',
                $working === 1
                    ? 'La GPU está indexando 1 documento.'
                    : "La GPU está indexando {$working} documentos.",
            ],
            default => ['available', 'La GPU está lista para indexar y chatear.'],
        };

        $payload = [
            'state' => $state,
            'message' => $message,
            'working' => $working,
            'checked_at' => now()->toIso8601String(),
        ];

        if ($request->user()->isAdmin()) {
            $payload['details'] = [
                'host' => parse_url((string) config('docbrainer.ollama.base_url'), PHP_URL_HOST),
                'version' => $status['ollama']['version'],
                'models' => $models,
            ];
        }

        return $this->json($payload);
    }

    /**
     * Ollama lista los modelos con su tag («embeddinggemma:latest»).
     *
     * @param  list<string>  $installed
     */
    private function installed(string $model, array $installed): bool
    {
        $wanted = str_contains($model, ':') ? $model : $model.':latest';

        return in_array($wanted, $installed, true) || in_array($model, $installed, true);
    }

    private function json(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-store');
    }
}
