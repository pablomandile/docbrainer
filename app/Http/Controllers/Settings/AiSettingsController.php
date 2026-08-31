<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AI\ApiQuota;
use App\Services\AI\GpuStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiSettingsController extends Controller
{
    public function edit(Request $request, GpuStatus $gpuStatus, ApiQuota $quota): Response
    {
        return Inertia::render('settings/Ai', [
            'settings' => [
                'chat_provider' => $request->user()->chatProvider(),
                'default_index_provider' => $request->user()->defaultIndexProvider(),
                'ollama_chat_model' => data_get($request->user()->ai_settings, 'ollama_chat_model'),
            ],
            'gpu' => $gpuStatus->check(),
            'ollamaDefaults' => [
                'embedding_model' => config('docbrainer.ollama.embedding_model'),
                'chat_model' => config('docbrainer.ollama.chat_model'),
            ],
            'usage' => [
                'embed_used' => $quota->usedToday(ApiQuota::METRIC_EMBED),
                'embed_limit' => (int) config('docbrainer.gemini.embed_daily_limit'),
                'generate_used' => $quota->usedToday(ApiQuota::METRIC_GENERATE),
                'generate_limit' => (int) config('docbrainer.gemini.generate_daily_limit'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'chat_provider' => ['required', Rule::in(['gemini', 'ollama'])],
            'default_index_provider' => ['required', Rule::in(['gemini', 'ollama'])],
            'ollama_chat_model' => ['nullable', 'string', 'max:100'],
        ]);

        $request->user()->forceFill([
            'ai_settings' => [
                'chat_provider' => $validated['chat_provider'],
                'default_index_provider' => $validated['default_index_provider'],
                'ollama_chat_model' => $validated['ollama_chat_model'] ?? null,
            ],
        ])->save();

        return back()->with('success', 'Configuración de IA guardada.');
    }

    /**
     * Estado de GPU/Ollama para el botón "volver a comprobar".
     */
    public function gpuStatus(GpuStatus $gpuStatus): JsonResponse
    {
        return response()->json($gpuStatus->check(fresh: true));
    }
}
