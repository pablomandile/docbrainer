<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\ChatProvider;
use App\Services\AI\Contracts\EmbeddingProvider;
use App\Services\AI\Gemini\GeminiProvider;
use App\Services\AI\Ollama\OllamaProvider;
use InvalidArgumentException;

/**
 * Resuelve el proveedor de IA por nombre ('gemini' | 'ollama').
 *
 * El proveedor de embeddings se decide POR DOCUMENTO
 * (documents.embedding_provider, elegido al subir o re-indexar);
 * el de chat/resúmenes, por configuración del usuario (users.ai_settings).
 */
class ProviderManager
{
    public function embedding(string $provider): EmbeddingProvider
    {
        return match ($provider) {
            'gemini' => app(GeminiProvider::class),
            'ollama' => app(OllamaProvider::class),
            default => throw new InvalidArgumentException("Proveedor desconocido: {$provider}"),
        };
    }

    public function chat(string $provider): ChatProvider
    {
        return match ($provider) {
            'gemini' => app(GeminiProvider::class),
            'ollama' => app(OllamaProvider::class),
            default => throw new InvalidArgumentException("Proveedor desconocido: {$provider}"),
        };
    }
}
