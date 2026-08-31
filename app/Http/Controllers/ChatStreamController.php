<?php

namespace App\Http\Controllers;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Project;
use App\Services\AI\GeminiException;
use App\Services\AI\GeminiRateLimitedException;
use App\Services\AI\LocallyThrottledException;
use App\Services\AI\OllamaException;
use App\Services\AI\OllamaUnavailableException;
use App\Services\AI\ProviderManager;
use App\Services\AI\QuotaExceededException;
use App\Services\Chat\PromptBuilder;
use App\Services\Retrieval\RetrievedChunk;
use App\Services\Retrieval\Retriever;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Endpoint de chat RAG con respuesta streaming (SSE sobre POST).
 *
 * Eventos emitidos:
 *   meta  → {conversation_id, conversation_title, user_message_id}
 *   delta → {text}                     (fragmentos de la respuesta)
 *   done  → {message}                  (mensaje del asistente persistido)
 *   error → {message}                  (error legible para el usuario)
 *
 * Si el servidor bufferea (Apache), el cliente recibe todos los eventos
 * juntos al final: mismo protocolo, sin streaming visual.
 */
class ChatStreamController extends Controller
{
    public function __invoke(
        Request $request,
        Project $project,
        Retriever $retriever,
        PromptBuilder $prompts,
        ProviderManager $providers,
    ): StreamedResponse {
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'integer'],
        ], [], ['message' => 'mensaje']);

        $conversation = $this->resolveConversation($request, $project, $validated['conversation_id'] ?? null);

        return response()->stream(
            function () use ($validated, $conversation, $project, $retriever, $prompts, $providers, $request): void {
                $question = $validated['message'];

                $userMessage = $conversation->messages()->create([
                    'role' => MessageRole::User,
                    'content' => $question,
                ]);

                $this->emit('meta', [
                    'conversation_id' => $conversation->id,
                    'conversation_title' => $conversation->title,
                    'user_message_id' => $userMessage->id,
                ]);

                try {
                    $retrieved = $retriever->retrieve($project, $question);
                } catch (Throwable $e) {
                    $this->emit('error', ['message' => $this->friendlyError($e)]);

                    return;
                }

                if ($retrieved === []) {
                    $this->emit('error', [
                        'message' => 'No hay documentos listos para consultar en este proyecto. '
                            .'Subí documentos y esperá a que terminen de procesarse.',
                    ]);

                    return;
                }

                $history = $conversation->messages()
                    ->where('id', '<', $userMessage->id)
                    ->where('status', 'complete')
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get()
                    ->reverse()
                    ->map(fn ($m) => ['role' => $m->role->value, 'content' => $m->content])
                    ->values()
                    ->all();

                $messages = [
                    ...$history,
                    ['role' => 'user', 'content' => $prompts->userTurn($retrieved, $question)],
                ];

                // Si el usuario eligió un modelo local específico, usarlo
                $ollamaModel = data_get($request->user()->ai_settings, 'ollama_chat_model');

                if (is_string($ollamaModel) && $ollamaModel !== '') {
                    config(['docbrainer.ollama.chat_model' => $ollamaModel]);
                }

                $chatProvider = $providers->chat($request->user()->chatProvider());

                try {
                    $result = $chatProvider->streamGenerate(
                        $prompts->systemInstruction(),
                        $messages,
                        fn (string $delta) => $this->emit('delta', ['text' => $delta]),
                    );
                } catch (Throwable $e) {
                    $this->emit('error', ['message' => $this->friendlyError($e)]);

                    return;
                }

                $assistantMessage = $conversation->messages()->create([
                    'role' => MessageRole::Assistant,
                    'content' => $result['text'],
                    'citations' => array_map(fn (RetrievedChunk $r) => $r->toCitation(), $retrieved),
                    'model' => $result['model'],
                    'prompt_tokens' => $result['prompt_tokens'],
                    'completion_tokens' => $result['completion_tokens'],
                ]);

                $conversation->touch();

                $this->emit('done', ['message' => $assistantMessage->toArray()]);
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function resolveConversation(Request $request, Project $project, ?int $conversationId): Conversation
    {
        if ($conversationId !== null) {
            return Conversation::where('project_id', $project->id)
                ->where('user_id', $request->user()->id)
                ->findOrFail($conversationId);
        }

        $conversation = new Conversation([
            'title' => Str::limit(trim($request->input('message')), 60),
        ]);
        $conversation->project_id = $project->id;
        $conversation->user_id = $request->user()->id;
        $conversation->save();

        return $conversation;
    }

    private function emit(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    private function friendlyError(Throwable $e): string
    {
        return match (true) {
            $e instanceof QuotaExceededException => 'Se alcanzó el límite diario de consultas a la IA. Probá de nuevo mañana.',
            $e instanceof LocallyThrottledException,
            $e instanceof GeminiRateLimitedException => 'Demasiadas consultas seguidas. Esperá un minuto y volvé a intentar.',
            $e instanceof OllamaUnavailableException => 'Ollama no está corriendo. Inicialo (o cambiá al proveedor Gemini en Configuración) y volvé a intentar.',
            $e instanceof OllamaException => $e->getMessage(),
            $e instanceof GeminiException => 'Error del servicio de IA: '.$e->getMessage(),
            default => 'Ocurrió un error inesperado procesando tu pregunta.',
        };
    }
}
