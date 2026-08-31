<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->probe());
    }

    private function probe(): array
    {
        $gpu = $this->probeGpu();
        $ollama = $this->probeOllama();

        [$level, $message] = $this->verdict($gpu, $ollama);

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
        $baseUrl = (string) config('docbrainer.ollama.base_url');

        try {
            $version = Http::timeout(3)->connectTimeout(2)->get($baseUrl.'/api/version');

            if (! $version->successful()) {
                return ['available' => false, 'version' => null, 'models' => []];
            }

            $tags = Http::timeout(3)->connectTimeout(2)->get($baseUrl.'/api/tags');

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
