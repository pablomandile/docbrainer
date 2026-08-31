<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConversationController extends Controller
{
    /**
     * Mensajes de una conversación (para el hilo del ChatTab).
     */
    public function show(Request $request, Project $project, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $project);

        abort_unless(
            $conversation->project_id === $project->id
            && $conversation->user_id === $request->user()->id,
            404,
        );

        return response()->json([
            'conversation' => $conversation->only(['id', 'title']),
            'messages' => $conversation->messages()->orderBy('id')->get(),
        ]);
    }

    public function destroy(Request $request, Project $project, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $project);

        abort_unless(
            $conversation->project_id === $project->id
            && $conversation->user_id === $request->user()->id,
            404,
        );

        $conversation->delete();

        return response()->json(['ok' => true]);
    }
}
