<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Models\Project;
use App\Services\AI\GpuStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $projects = $request->user()
            ->projects()
            ->withCount('documents')
            ->latest('updated_at')
            ->get();

        return Inertia::render('projects/Index', [
            'projects' => $projects,
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = $request->user()->projects()->create($request->validated());

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'Proyecto creado.');
    }

    public function show(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Show', [
            'project' => $project,
            'documents' => $project->documents()
                ->latest()
                ->get()
                ->map(fn ($doc) => [
                    ...$doc->toArray(),
                    'status_label' => $doc->status->label(),
                    'is_processing' => $doc->status->isProcessing(),
                ]),
            'conversations' => $project->conversations()
                ->where('user_id', $request->user()->id)
                ->latest('updated_at')
                ->get(['id', 'title', 'updated_at']),
            'summaries' => SummaryController::summariesPayload($project, $request->user()->id),
            'ai' => [
                'default_index_provider' => $request->user()->defaultIndexProvider(),
                'ollama_available' => app(GpuStatus::class)->check()['available'],
            ],
        ]);
    }

    public function update(StoreProjectRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->update($request->validated());

        return back()->with('success', 'Proyecto actualizado.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        // Borra los archivos del proyecto (originales + artefactos)
        Storage::disk('local')->deleteDirectory("projects/{$project->id}");

        $project->delete();

        return redirect()
            ->route('projects.index')
            ->with('success', 'Proyecto eliminado.');
    }
}
