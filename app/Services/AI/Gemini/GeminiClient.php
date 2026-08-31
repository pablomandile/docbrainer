<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\ApiQuota;
use App\Services\AI\GeminiException;
use App\Services\AI\GeminiRateLimitedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP artesanal para la API de Gemini.
 *
 * Los datos (clave, URL, modelos, límites) vienen del .env vía config.
 * Cada llamada pasa por ApiQuota (gate RPM + tope diario del lado cliente):
 * en el free tier, reventar la cuota real de Google puede dejarla clavada en 0.
 */
class GeminiClient
{
    public function __construct(
        private readonly ApiQuota $quota,
    ) {}

    /**
     * Embeddings en lote: una sola request HTTP para N textos.
     *
     * @param  list<array{text: string, taskType?: string, title?: string}>  $items
     * @return list<list<float>> vectores crudos (sin normalizar), en orden
     */
    public function embedBatch(array $items): array
    {
        $this->quota->guard(ApiQuota::METRIC_EMBED);

        $model = config('docbrainer.gemini.embedding_model');
        $dimensions = (int) config('docbrainer.gemini.embedding_dimensions');

        $requests = array_map(function (array $item) use ($model, $dimensions) {
            $request = [
                'model' => "models/{$model}",
                'content' => ['parts' => [['text' => $item['text']]]],
                'outputDimensionality' => $dimensions,
            ];

            if (isset($item['taskType'])) {
                $request['taskType'] = $item['taskType'];
            }

            if (isset($item['title'])) {
                $request['title'] = $item['title'];
            }

            return $request;
        }, $items);

        $response = $this->send("/models/{$model}:batchEmbedContents", ['requests' => $requests]);

        $embeddings = $response->json('embeddings');

        if (! is_array($embeddings) || count($embeddings) !== count($items)) {
            throw new GeminiException(
                'Respuesta de embeddings inválida: se esperaban '.count($items).' vectores.'
            );
        }

        $this->quota->recordItems(ApiQuota::METRIC_EMBED_ITEMS, count($items));

        return array_map(fn (array $e) => array_map('floatval', $e['values'] ?? []), $embeddings);
    }

    /**
     * Generación completa (sin streaming).
     *
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @return array{text: string, prompt_tokens: ?int, completion_tokens: ?int, model: string}
     */
    public function generate(string $systemInstruction, array $messages): array
    {
        $this->quota->guard(ApiQuota::METRIC_GENERATE);

        $model = config('docbrainer.gemini.chat_model');

        $response = $this->send(
            "/models/{$model}:generateContent",
            $this->generatePayload($systemInstruction, $messages),
        );

        $text = collect($response->json('candidates.0.content.parts', []))
            ->pluck('text')
            ->filter()
            ->implode('');

        if ($text === '') {
            $reason = $response->json('candidates.0.finishReason', 'desconocida');

            throw new GeminiException("Gemini no devolvió texto (razón: {$reason}).");
        }

        return [
            'text' => $text,
            'prompt_tokens' => $response->json('usageMetadata.promptTokenCount'),
            'completion_tokens' => $response->json('usageMetadata.candidatesTokenCount'),
            'model' => $model,
        ];
    }

    /**
     * Generación con streaming SSE. Invoca $onDelta con cada fragmento.
     *
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  callable(string): void  $onDelta
     * @return array{text: string, prompt_tokens: ?int, completion_tokens: ?int, model: string}
     */
    public function streamGenerate(string $systemInstruction, array $messages, callable $onDelta): array
    {
        $this->quota->guard(ApiQuota::METRIC_GENERATE);

        $model = config('docbrainer.gemini.chat_model');

        $response = $this->send(
            "/models/{$model}:streamGenerateContent?alt=sse",
            $this->generatePayload($systemInstruction, $messages),
            stream: true,
        );

        $body = $response->toPsrResponse()->getBody();

        $fullText = '';
        $promptTokens = null;
        $completionTokens = null;
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            // Cada evento SSE termina en doble salto de línea
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                foreach (explode("\n", $event) as $line) {
                    if (! str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $json = json_decode(trim(substr($line, 5)), true);

                    if (! is_array($json)) {
                        continue;
                    }

                    $delta = collect($json['candidates'][0]['content']['parts'] ?? [])
                        ->pluck('text')
                        ->filter()
                        ->implode('');

                    if ($delta !== '') {
                        $fullText .= $delta;
                        $onDelta($delta);
                    }

                    $promptTokens = $json['usageMetadata']['promptTokenCount'] ?? $promptTokens;
                    $completionTokens = $json['usageMetadata']['candidatesTokenCount'] ?? $completionTokens;
                }
            }
        }

        if ($fullText === '') {
            throw new GeminiException('Gemini no devolvió texto en el stream.');
        }

        return [
            'text' => $fullText,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'model' => $model,
        ];
    }

    private function generatePayload(string $systemInstruction, array $messages): array
    {
        return [
            'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            'contents' => array_map(fn (array $m) => [
                'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $m['content']]],
            ], $messages),
        ];
    }

    /**
     * POST con reintentos: backoff exponencial + jitter en 429/5xx/conexión,
     * respetando RetryInfo.retryDelay cuando Google lo envía.
     */
    private function send(string $url, array $payload, bool $stream = false): Response
    {
        $attempts = max(1, (int) config('docbrainer.gemini.max_retries'));
        $lastResponse = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->pending($stream)->post($url, $payload);
            } catch (ConnectionException $e) {
                if ($attempt === $attempts) {
                    throw new GeminiException('No se pudo conectar con la API de Gemini: '.$e->getMessage());
                }

                $this->sleepBackoff($attempt, null);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $lastResponse = $response;
            $status = $response->status();
            $retryable = $status === 429 || $status >= 500;

            if (! $retryable || $attempt === $attempts) {
                throw $this->toException($response);
            }

            $this->sleepBackoff($attempt, $response);
        }

        throw $this->toException($lastResponse);
    }

    private function pending(bool $stream): PendingRequest
    {
        $apiKey = (string) config('docbrainer.gemini.api_key');

        if ($apiKey === '') {
            throw new GeminiException(
                'Falta la clave de la API de Gemini: configurá GEMINI_API_KEY en el archivo .env.'
            );
        }

        $request = Http::baseUrl((string) config('docbrainer.gemini.base_url'))
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->acceptJson()
            ->timeout(180)
            ->connectTimeout(15);

        return $stream ? $request->withOptions(['stream' => true]) : $request;
    }

    private function sleepBackoff(int $attempt, ?Response $response): void
    {
        $seconds = $response !== null ? $this->retryDelaySeconds($response) : null;

        if ($seconds === null) {
            // 2^n segundos + jitter, tope 30 s
            $seconds = min(2 ** $attempt, 30) + mt_rand(0, 1000) / 1000;
        }

        usleep((int) (min($seconds, 45) * 1_000_000));
    }

    /**
     * Extrae error.details[].retryDelay ("14s") de una respuesta 429 de Google.
     */
    private function retryDelaySeconds(Response $response): ?float
    {
        foreach ((array) $response->json('error.details', []) as $detail) {
            if (str_contains($detail['@type'] ?? '', 'RetryInfo') && isset($detail['retryDelay'])) {
                return (float) rtrim((string) $detail['retryDelay'], 's');
            }
        }

        return null;
    }

    private function toException(?Response $response): GeminiException
    {
        if ($response === null) {
            return new GeminiException('Sin respuesta de la API de Gemini.');
        }

        $message = $response->json('error.message', 'Error HTTP '.$response->status());

        Log::warning('Gemini API error', [
            'status' => $response->status(),
            'message' => $message,
        ]);

        if ($response->status() === 429) {
            return new GeminiRateLimitedException(
                'La API de Gemini limitó las requests (429): '.$message
            );
        }

        return new GeminiException("Error de la API de Gemini ({$response->status()}): {$message}");
    }
}
