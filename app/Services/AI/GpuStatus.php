<?php

namespace App\Services\AI;

use App\Services\AI\Ollama\OllamaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Detección del modo GPU local:
 *  - nvidia-smi → placa, VRAM y versión de driver (CUDA).
 *  - Ollama → versión y modelos instalados.
 *
 * El "mensaje verde" exige AMBAS cosas: GPU detectada Y Ollama respondiendo.
 * Ollama sin GPU funciona (CPU) pero lento → advertencia amarilla.
 */
class GpuStatus
{
    private const CACHE_KEY = 'docbrainer:gpu-status';

    private const CACHE_SECONDS = 30;

    public function check(bool $fresh = false): array
    {
        // En servidores sin GPU (hosting compartido) no tiene sentido lanzar
        // nvidia-smi ni sondear Ollama en cada request.
        if (! config('docbrainer.ollama.enabled')) {
            return $this->disabled();
        }

        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->probe());
    }

    private function disabled(): array
    {
        return [
            'gpu' => ['available' => false, 'name' => null, 'vram_gb' => null, 'driver' => null],
            'ollama' => ['available' => false, 'version' => null, 'models' => []],
            'available' => false,
            'level' => 'error',
            'message' => 'El modo GPU local está desactivado en este entorno: solo está disponible el proveedor Gemini (API).',
        ];
    }

    private function probe(): array
    {
        // Ollama remoto (túnel a la PC con GPU): nvidia-smi correría en este
        // servidor, que no tiene GPU, así que no se consulta.
        $gpu = $this->isRemote()
            ? ['available' => false, 'name' => null, 'vram_gb' => null, 'driver' => null]
            : $this->probeGpu();
        $ollama = $this->probeOllama();

        [$level, $message] = $this->isRemote()
            ? $this->remoteVerdict($ollama)
            : $this->verdict($gpu, $ollama);

        return [
            'gpu' => $gpu,
            'ollama' => $ollama,
            'available' => $ollama['available'],
            'level' => $level,
            'message' => $message,
        ];
    }

    /**
     * @return array{available: bool, name: ?string, vram_gb: ?float, driver: ?string}
     */
    private function probeGpu(): array
    {
        try {
            $result = Process::timeout(5)->run(
                'nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader,nounits'
            );

            if ($result->successful() && trim($result->output()) !== '') {
                // "NVIDIA GeForce RTX 3060, 12288, 566.14"
                $parts = array_map('trim', explode(',', trim(explode("\n", trim($result->output()))[0])));

                return [
                    'available' => true,
                    'name' => $parts[0] ?? 'GPU NVIDIA',
                    'vram_gb' => isset($parts[1]) ? round(((float) $parts[1]) / 1024, 1) : null,
                    'driver' => $parts[2] ?? null,
                ];
            }
        } catch (Throwable) {
            // nvidia-smi no está en el PATH o falló: no hay GPU utilizable
        }

        return ['available' => false, 'name' => null, 'vram_gb' => null, 'driver' => null];
    }

    /**
     * @return array{available: bool, version: ?string, models: list<string>}
     */
    private function probeOllama(): array
    {
        // Por túnel la latencia es mayor que contra localhost
        $timeout = $this->isRemote() ? 8 : 3;

        try {
            $version = OllamaClient::http()->timeout($timeout)->connectTimeout(4)->get('/api/version');

            if (! $version->successful()) {
                return ['available' => false, 'version' => null, 'models' => []];
            }

            $tags = OllamaClient::http()->timeout($timeout)->connectTimeout(4)->get('/api/tags');

            $models = collect($tags->json('models', []))
                ->pluck('name')
                ->filter()
                ->values()
                ->all();

            return [
                'available' => true,
                'version' => $version->json('version'),
                'models' => $models,
            ];
        } catch (Throwable) {
            return ['available' => false, 'version' => null, 'models' => []];
        }
    }

    private function isRemote(): bool
    {
        $host = parse_url((string) config('docbrainer.ollama.base_url'), PHP_URL_HOST);

        return ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    /**
     * @return array{0: string, 1: string} [nivel, mensaje]
     */
    private function remoteVerdict(array $ollama): array
    {
        $host = parse_url((string) config('docbrainer.ollama.base_url'), PHP_URL_HOST);

        if ($ollama['available']) {
            return [
                'success',
                "Ollama v{$ollama['version']} disponible en la PC con GPU ({$host}) — listo para usar.",
            ];
        }

        return [
            'error',
            "La PC con GPU no responde ({$host}): verificá que esté encendida y que Ollama y cloudflared estén corriendo. "
            .'Mientras tanto, solo está disponible el proveedor Gemini (API).',
        ];
    }

    /**
     * @return array{0: string, 1: string} [nivel, mensaje]
     */
    private function verdict(array $gpu, array $ollama): array
    {
        if ($gpu['available'] && $ollama['available']) {
            $vram = $gpu['vram_gb'] !== null ? " ({$gpu['vram_gb']} GB)" : '';

            return [
                'success',
                "GPU disponible: {$gpu['name']}{$vram} — Ollama v{$ollama['version']} listo para usar.",
            ];
        }

        if ($ollama['available']) {
            return [
                'warn',
                'Ollama responde pero no se detectó GPU NVIDIA (nvidia-smi): los modelos correrán en CPU, mucho más lento.',
            ];
        }

        if ($gpu['available']) {
            return [
                'warn',
                "GPU detectada ({$gpu['name']}) pero Ollama no está corriendo. "
                .'Instalalo desde ollama.com y ejecutá: ollama pull '
                .config('docbrainer.ollama.embedding_model').' && ollama pull '
                .config('docbrainer.ollama.chat_model'),
            ];
        }

        return [
            'error',
            'Sin GPU NVIDIA ni Ollama detectados: solo está disponible el proveedor Gemini (API).',
        ];
    }
}
