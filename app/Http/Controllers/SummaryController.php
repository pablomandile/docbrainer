<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\SummaryStatus;
use App\Jobs\RunSummaryStep;
use App\Models\Project;
use App\Models\Summary;
use App\Services\Summary\SummaryPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SummaryController extends Controller
{
    public function store(Request $request, Project $project, SummaryPlanner $planner): RedirectResponse
    {
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'document_id' => [
                'nullable',
                Rule::exists('documents', 'id')
                    ->where('project_id', $project->id)
                    ->where('status', DocumentStatus::Ready->value),
            ],
            'length' => ['required', Rule::in(['corto', 'medio', 'extenso'])],
            'style' => ['required', Rule::in(['ejecutivo', 'academico', 'divulgativo'])],
            'language' => ['required', Rule::in(['es', 'en'])],
        ], [
            'document_id.exists' => 'El documento elegido no está listo para resumir.',
        ]);

        $summary = new Summary([
            'params' => [
                'length' => $validated['length'],
                'style' => $validated['style'],
                'language' => $validated['language'],
            ],
            'status' => SummaryStatus::Pending,
        ]);
        $summary->project_id = $project->id;
        $summary->document_id = $validated['document_id'] ?? null;
        $summary->user_id = $request->user()->id;
        $summary->save();

        $total = $planner->plan($summary);

        if ($total === 0) {
            $summary->markFailed('No hay documentos listos para resumir en este proyecto.');

            return back()->with('error', 'No hay documentos listos para resumir.');
        }

        $summary->forceFill(['progress_total' => $total])->save();

        RunSummaryStep::dispatch($summary->id);

        return back()->with('success', 'Resumen en cola. Podés seguir el progreso acá.');
    }

    /**
     * Estado liviano para el polling del SummaryTab.
     */
    public function status(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'summaries' => $this->summariesPayload($project, $request->user()->id),
        ]);
    }

    public function destroy(Request $request, Project $project, Summary $summary): RedirectResponse
    {
        Gate::authorize('view', $project);

        abort_unless(
            $summary->project_id === $project->id && $summary->user_id === $request->user()->id,
            404,
        );

        $summary->delete();

        return back()->with('success', 'Resumen eliminado.');
    }

    /**
     * Payload compartido entre la prop de Inertia y el endpoint de polling.
     */
    public static function summariesPayload(Project $project, int $userId): array
    {
        return $project->summaries()
            ->with('document:id,title')
            ->where('user_id', $userId)
            ->latest()
            ->get()
            ->map(fn (Summary $s) => [
                'id' => $s->id,
                'document_id' => $s->document_id,
                'document_title' => $s->document?->title,
                'params' => $s->params,
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'progress_total' => $s->progress_total,
                'progress_done' => $s->progress_done,
                'result' => $s->status === SummaryStatus::Completed ? $s->result : null,
                'failure_reason' => $s->failure_reason,
                'created_at' => $s->created_at?->toISOString(),
            ])
            ->all();
    }
}
