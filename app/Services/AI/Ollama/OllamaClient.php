<?php

namespace App\Services\AI\Ollama;

use App\Services\AI\OllamaException;
use App\Services\AI\OllamaUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP para Ollama (runtime local de modelos en GPU vía CUDA).
 *
 * Sin cuotas: es tu hardware. La concurrencia efectiva es 1 (el worker de
 * cola procesa en serie), así la GPU no se satura.
 */
class OllamaClient
{
    /**
     * Embeddings en lote.
     *
     * @param  list<string>  $inputs  textos YA formateados con su prefijo
     * @return list<list<float>>
     */
    public function embed(array $inputs): array
    {
        $model = (string) config('docbrainer.ollama.embedding_model');

        $response = $this->send('/api/embed', [
            'model' => $model,
            'input' => $inputs,
        ]);

        $embeddings = $response->json('embeddings');

        if (! is_array($embeddings) || count($embeddings) !== count($inputs)) {
            throw new OllamaException(
                'Respuesta de embeddings inválida de Ollama: se esperaban '.count($inputs).' vectores.'
            );
        }

        return array_map(fn (array $e) => array_map('floatval', $e), $embeddings);
    }

    /**
     * Chat sin streaming.
     *
     * @param  list<array{role: string, content: string}>  $messages  incluye el turno system
     * @return array{text: string, prompt_tokens: ?int, completion_tokens: ?int, model: string}
     */
    public function chat(array $messages): array
    {
        // Siempre por streaming: detrás de un túnel de Cloudflare, una respuesta
        // que tarda más de 100 s en empezar se corta con un 524. El stream
        // manda bytes desde el primer token.
        return $this->streamChat($messages, fn (string $delta) => null);
    }

    /**
     * Chat con streaming NDJSON (una línea JSON por fragmento).
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  callable(string): void  $onDelta
     * @return array{text: string, prompt_tokens: ?int, completion_tokens: ?int, model: string}
     */
    public function streamChat(array $messages, callable $onDelta): array
    {
        $model = $this->chatModel();

        $response = $this->send('/api/chat', [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
        ], stream: true);

        $body = $response->toPsrResponse()->getBody();

        $fullText = '';
        $promptTokens = null;
        $completionTokens = null;
        $buffer = '';

        $handleLine = function (string $line) use (&$fullText, &$promptTokens, &$completionTokens, $onDelta, $model): void {
            $json = json_decode(trim($line), true);

            if (! is_array($json)) {
                return;
            }

            if (isset($json['error'])) {
                throw new OllamaException($this->modelErrorMessage((string) $json['error'], $model));
            }

            $delta = (string) ($json['message']['content'] ?? '');

            if ($delta !== '') {
                $fullText .= $delta;
                $onDelta($delta);
            }

            if ($json['done'] ?? false) {
                $promptTokens = $json['prompt_eval_count'] ?? null;
                $completionTokens = $json['eval_count'] ?? null;
            }
        };

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $handleLine(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
            }
        }

        // Última línea sin "\n" final (o respuesta no-stream de un proxy)
        $handleLine($buffer);

        if ($fullText === '') {
            throw new OllamaException('Ollama no devolvió texto en el stream.');
        }

        return [
            'text' => $fullText,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'model' => 'ollama:'.$model,
        ];
    }

    public function chatModel(): string
    {
        return (string) config('docbrainer.ollama.chat_model');
    }

    private function send(string $url, array $payload, bool $stream = false): Response
    {
        try {
            $response = $this->pending($stream)->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new OllamaUnavailableException(
                'No se pudo conectar con Ollama en '.config('docbrainer.ollama.base_url')
                .'. ¿Está corriendo? ('.$e->getMessage().')'
            );
        }

        if ($response->successful()) {
            return $response;
        }

        if (in_array($response->status(), [302, 401, 403], true)) {
            throw new OllamaUnavailableException(
                'El túnel de Cloudflare rechazó la conexión con Ollama (HTTP '.$response->status().'). '
                .'Revisá OLLAMA_CF_ACCESS_CLIENT_ID y OLLAMA_CF_ACCESS_CLIENT_SECRET.'
            );
        }

        // Túnel sin origen (PC apagada / cloudflared caído) o timeout del borde:
        // se trata como "no disponible" para que los jobs reintenten.
        if (in_array($response->status(), [502, 503, 504, 524, 530], true)) {
            throw new OllamaUnavailableException(
                'Ollama no responde a través del túnel (HTTP '.$response->status().'). '
                .'¿Está encendida la PC con GPU y corriendo cloudflared?'
            );
        }

        $error = (string) $response->json('error', 'HTTP '.$response->status());

        throw new OllamaException($this->modelErrorMessage($error, (string) ($payload['model'] ?? '')));
    }

    private function modelErrorMessage(string $error, string $model): string
    {
        if (str_contains($error, 'not found')) {
            return "El modelo «{$model}» no está descargado en Ollama. "
                ."Ejecutá: ollama pull {$model}";
        }

        return 'Error de Ollama: '.$error;
    }

    /**
     * Request base contra Ollama, con el service token de Cloudflare Access
     * si está configurado (Ollama expuesto por túnel desde la PC con GPU).
     */
    public static function http(): PendingRequest
    {
        // Sin redirects: Cloudflare Access responde a un token inválido con un
        // 302 a su login, que seguido daría un 200 con HTML.
        $request = Http::baseUrl((string) config('docbrainer.ollama.base_url'))
            ->acceptJson()
            ->withoutRedirecting();

        $clientId = (string) config('docbrainer.ollama.cf_access_client_id');

        if ($clientId !== '') {
            $request->withHeaders([
                'CF-Access-Client-Id' => $clientId,
                'CF-Access-Client-Secret' => (string) config('docbrainer.ollama.cf_access_client_secret'),
            ]);
        }

        return $request;
    }

    private function pending(bool $stream): PendingRequest
    {
        $request = self::http()
            ->timeout(600)       // la primera carga del modelo en VRAM puede tardar
            ->connectTimeout(5);

        return $stream ? $request->withOptions(['stream' => true]) : $request;
    }
}
