<?php

namespace App\Services\AI\Ollama;

use App\Services\AI\Contracts\ChatProvider;
use App\Services\AI\Contracts\EmbeddingProvider;
use App\Services\Vector\VectorMath;

/**
 * Proveedor GPU local vía Ollama.
 *
 * embeddinggemma usa el mismo formato de prompts que gemini-embedding
 * (es el modelo local de Google), pero Ollama NO los agrega solo:
 * se aplican acá manualmente.
 *
 *   indexar:  "title: {título} | text: {chunk}"
 *   consultar: "task: search result | query: {pregunta}"
 */
class OllamaProvider implements ChatProvider, EmbeddingProvider
{
    public function __construct(
        private readonly OllamaClient $client,
    ) {}

    public function embedForIndex(array $items): array
    {
        $inputs = array_map(function (array $item) {
            $title = trim($item['title'] ?? '');

            return 'title: '.($title !== '' ? $title : 'none').' | text: '.$item['text'];
        }, $items);

        return array_map(
            fn (array $vector) => VectorMath::normalize($this->truncate($vector)),
            $this->client->embed($inputs),
        );
    }

    public function embedForQuery(string $query): array
    {
        $vectors = $this->client->embed(['task: search result | query: '.$query]);

        return VectorMath::normalize($this->truncate($vectors[0]));
    }

    public function modelId(): string
    {
        return 'ollama:'.config('docbrainer.ollama.embedding_model');
    }

    public function dimensions(): int
    {
        return (int) config('docbrainer.ollama.embedding_dimensions');
    }

    public function batchSize(): int
    {
        return max(1, (int) config('docbrainer.ollama.embed_batch_size'));
    }

    public function generate(string $systemInstruction, array $messages): array
    {
        return $this->client->chat($this->withSystem($systemInstruction, $messages));
    }

    public function streamGenerate(string $systemInstruction, array $messages, callable $onDelta): array
    {
        return $this->client->streamChat($this->withSystem($systemInstruction, $messages), $onDelta);
    }

    /**
     * Ollama recibe la instrucción de sistema como primer mensaje.
     */
    private function withSystem(string $systemInstruction, array $messages): array
    {
        return [
            ['role' => 'system', 'content' => $systemInstruction],
            ...array_map(fn (array $m) => [
                'role' => $m['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $m['content'],
            ], $messages),
        ];
    }

    /**
     * Trunca al tamaño configurado (Matryoshka) si el modelo devuelve más.
     */
    private function truncate(array $vector): array
    {
        $dimensions = $this->dimensions();

        return count($vector) > $dimensions
            ? array_slice($vector, 0, $dimensions)
            : $vector;
    }
}
