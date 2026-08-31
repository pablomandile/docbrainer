<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Project;
use App\Models\Summary;
use App\Models\User;
use App\Services\AI\ApiQuota;
use App\Services\Summary\SummaryPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['docbrainer.gemini.api_key' => 'test-key']);
        RateLimiter::clear('gemini:'.ApiQuota::METRIC_GENERATE);
    }

    private function readyDocument(Project $project, string $title, int $chunkCount, int $tokensPerChunk = 400): Document
    {
        $document = $project->documents()->create([
            'title' => $title,
            'original_name' => "{$title}.txt",
            'stored_path' => 'x',
            'extension' => 'txt',
            'size_bytes' => 1,
            'status' => DocumentStatus::Ready,
            'chunk_count' => $chunkCount,
            'embedded_chunk_count' => $chunkCount,
        ]);

        for ($i = 0; $i < $chunkCount; $i++) {
            $document->chunks()->create([
                'project_id' => $project->id,
                'chunk_index' => $i,
                'section_path' => "Sección {$i}",
                'content' => "Contenido del fragmento {$i} de {$title}.",
                'token_estimate' => $tokensPerChunk,
            ]);
        }

        return $document;
    }

    private function makeSummary(Project $project, User $user, ?int $documentId = null): Summary
    {
        $summary = new Summary([
            'params' => ['length' => 'medio', 'style' => 'divulgativo', 'language' => 'es'],
        ]);
        $summary->project_id = $project->id;
        $summary->document_id = $documentId;
        $summary->user_id = $user->id;
        $summary->save();

        return $summary;
    }

    public function test_planner_builds_single_node_for_short_document(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $document = $this->readyDocument($project, 'Corto', 2);

        $summary = $this->makeSummary($project, $user, $document->id);

        $total = app(SummaryPlanner::class)->plan($summary);

        // 2 chunks × 400 tokens < 3000 → un solo nodo (map = raíz)
        $this->assertSame(1, $total);
        $this->assertSame(1, $summary->nodes()->count());
        $this->assertSame(0, $summary->nodes()->first()->level);
    }

    public function test_planner_builds_reduce_tree_with_bounded_fan_in(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        // 200 chunks × 400 tokens = 80.000 tokens → ~27 unidades map
        $document = $this->readyDocument($project, 'Libro', 200);

        $summary = $this->makeSummary($project, $user, $document->id);
        app(SummaryPlanner::class)->plan($summary);

        $level0 = $summary->nodes()->where('level', 0)->count();
        $this->assertGreaterThan(10, $level0);

        // Cada unidad map respeta el máximo de tokens
        $summary->nodes()->where('level', 0)->get()->each(function ($node) {
            $this->assertLessThanOrEqual(
                (int) config('docbrainer.summary.map_unit_tokens'),
                count($node->input_ref['chunk_ids']) * 400,
            );
        });

        // Fan-in ≤ 10 en los niveles reduce y raíz única en el nivel máximo
        $maxLevel = $summary->nodes()->max('level');
        $this->assertGreaterThan(0, $maxLevel);
        $this->assertSame(1, $summary->nodes()->where('level', $maxLevel)->count());

        $summary->nodes()->where('level', '>', 0)->get()->each(function ($node) {
            $this->assertLessThanOrEqual(10, count($node->input_ref['node_ids']));
        });
    }

    public function test_project_scope_includes_all_ready_documents(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $this->readyDocument($project, 'Uno', 2);
        $this->readyDocument($project, 'Dos', 2);

        // Documento no listo: no debe entrar al plan
        $project->documents()->create([
            'title' => 'Fallido', 'original_name' => 'f.txt', 'stored_path' => 'x',
            'extension' => 'txt', 'size_bytes' => 1, 'status' => DocumentStatus::Failed,
        ]);

        $summary = $this->makeSummary($project, $user);
        app(SummaryPlanner::class)->plan($summary);

        $labels = $summary->nodes()->where('level', 0)->get()
            ->map(fn ($n) => $n->input_ref['label'])->implode(' | ');

        $this->assertStringContainsString('Uno', $labels);
        $this->assertStringContainsString('Dos', $labels);
        $this->assertStringNotContainsString('Fallido', $labels);
    }

    public function test_summary_endpoint_runs_map_reduce_to_completion(): void
    {
        Http::fake([
            '*generateContent*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Resumen generado de prueba.']]]]],
                'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 50],
            ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        // 20 chunks × 400 tokens → ~3 unidades map + 1 reduce raíz
        $document = $this->readyDocument($project, 'Manual', 20);

        // Con QUEUE_CONNECTION=sync el job corre inline
        $this->actingAs($user)->post("/projects/{$project->id}/summaries", [
            'document_id' => $document->id,
            'length' => 'corto',
            'style' => 'ejecutivo',
            'language' => 'es',
        ])->assertRedirect();

        $summary = Summary::first();

        $this->assertSame('completed', $summary->status->value);
        $this->assertSame('Resumen generado de prueba.', $summary->result);
        $this->assertSame($summary->progress_total, $summary->progress_done);
        $this->assertSame(0, $summary->nodes()->where('status', 'pending')->count());

        // El prompt final incluyó los parámetros pedidos
        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($body, 'RESUMEN FINAL') === false
                || (str_contains($body, 'CORTO') && str_contains($body, 'EJECUTIVO'));
        });
    }

    public function test_summary_requires_ready_document_of_same_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $otherProject = Project::factory()->for($user)->create();
        $foreignDoc = $this->readyDocument($otherProject, 'Ajeno', 1);

        $this->actingAs($user)->post("/projects/{$project->id}/summaries", [
            'document_id' => $foreignDoc->id,
            'length' => 'medio',
            'style' => 'divulgativo',
            'language' => 'es',
        ])->assertSessionHasErrors('document_id');
    }

    public function test_other_users_cannot_create_summaries(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $this->actingAs($intruder)->post("/projects/{$project->id}/summaries", [
            'length' => 'medio',
            'style' => 'divulgativo',
            'language' => 'es',
        ])->assertForbidden();
    }
}
