<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\Contracts\ChatProvider;
use App\Services\AI\Contracts\EmbeddingProvider;
use App\Services\Vector\VectorMath;

/**
 * Proveedor Gemini (API). Encapsula el formato de embedding según el modelo:
 *
 *  - gemini-embedding-001 (y familia -00x): parámetros taskType + title.
 *  - gemini-embedding-2 en adelante: taskType está deprecado y se ignora;
 *    el formato va como PREFIJO dentro del texto:
 *        indexar:  "title: {título} | text: {chunk}"
 *        consultar: "task: search result | query: {pregunta}"
 *
 * El formato debe ser CONSISTENTE entre indexación y consulta: por eso el
 * modelId() se guarda junto a cada vector y el retrieval filtra por él.
 */
class GeminiProvider implements ChatProvider, EmbeddingProvider
{
    public function __construct(
        private readonly GeminiClient $client,
    ) {}

    public function embedForIndex(array $items): array
    {
        $requests = array_map(function (array $item) {
            $title = trim($item['title'] ?? '');

            if ($this->usesPromptPrefixes()) {
                return [
                    'text' => 'title: '.($title !== '' ? $title : 'none').' | text: '.$item['text'],
                ];
            }

            $request = [
                'text' => $item['text'],
                'taskType' => 'RETRIEVAL_DOCUMENT',
            ];

            if ($title !== '') {
                $request['title'] = $title;
            }

            return $request;
        }, $items);

        return array_map(
            fn (array $vector) => VectorMath::normalize($vector),
            $this->client->embedBatch($requests),
        );
    }

    public function embedForQuery(string $query): array
    {
        $request = $this->usesPromptPrefixes()
            ? ['text' => 'task: search result | query: '.$query]
            : ['text' => $query, 'taskType' => 'RETRIEVAL_QUERY'];

        $vectors = $this->client->embedBatch([$request]);

        return VectorMath::normalize($vectors[0]);
    }

    public function modelId(): string
    {
        return (string) config('docbrainer.gemini.embedding_model');
    }

    public function dimensions(): int
    {
        return (int) config('docbrainer.gemini.embedding_dimensions');
    }

    public function batchSize(): int
    {
        return max(1, (int) config('docbrainer.gemini.embed_batch_size'));
    }

    public function generate(string $systemInstruction, array $messages): array
    {
        return $this->client->generate($systemInstruction, $messages);
    }

    public function streamGenerate(string $systemInstruction, array $messages, callable $onDelta): array
    {
        return $this->client->streamGenerate($systemInstruction, $messages, $onDelta);
    }

    /**
     * Los modelos de embedding "gemini-embedding-2" en adelante usan prefijos
     * en el texto; la familia -001 usa los parámetros taskType/title.
     */
    private function usesPromptPrefixes(): bool
    {
        return ! preg_match('/-0\d+$/', $this->modelId());
    }
}
