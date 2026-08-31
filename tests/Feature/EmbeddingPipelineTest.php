<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\AI\ApiQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmbeddingPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['docbrainer.gemini.api_key' => 'test-key']);
        RateLimiter::clear('gemini:'.ApiQuota::METRIC_EMBED);
    }

    private function fakeGeminiEmbeddings(): void
    {
        Http::fake([
            '*batchEmbedContents*' => function ($request) {
                $count = count($request->data()['requests']);

                return Http::response([
                    'embeddings' => array_fill(0, $count, [
                        'values' => array_map(fn ($i) => sin($i), range(1, 768)),
                    ]),
                ]);
            },
        ]);
    }

    public function test_full_pipeline_reaches_ready_with_embedded_chunks(): void
    {
        Storage::fake('local');
        $this->fakeGeminiEmbeddings();

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $md = "# Presupuesto\n\nLa cocina cuesta 5 millones de pesos argentinos.\n\n# Diseño\n\nDos plantas con quincho y parrilla.";

        $this->actingAs($user)->post("/projects/{$project->id}/documents", [
            'files' => [UploadedFile::fake()->createWithContent('obra.md', $md)],
        ])->assertRedirect();

        $document = $project->documents()->first();

        $this->assertSame('ready', $document->status->value);
        $this->assertNotNull($document->processed_at);
        $this->assertGreaterThan(0, $document->chunk_count);
        $this->assertSame($document->chunk_count, $document->embedded_chunk_count);

        // Todos los chunks tienen vector + modelo + dimensiones
        $document->chunks()->get()->each(function ($chunk) {
            $this->assertNotNull($chunk->embedding);
            $this->assertNotNull($chunk->embedded_at);
            $this->assertSame(config('docbrainer.gemini.embedding_model'), $chunk->embedding_model);
            $this->assertSame(768, $chunk->embedding_dimensions);
            // 768 floats × 4 bytes
            $this->assertSame(768 * 4, strlen($chunk->embedding));
        });

        // Contadores de cuota registrados
        $quota = new ApiQuota;
        $this->assertGreaterThan(0, $quota->usedToday(ApiQuota::METRIC_EMBED));
        $this->assertSame($document->chunk_count, $quota->usedToday(ApiQuota::METRIC_EMBED_ITEMS));
    }

    public function test_document_prefix_style_is_sent_for_legacy_model(): void
    {
        Storage::fake('local');
        config(['docbrainer.gemini.embedding_model' => 'gemini-embedding-001']);
        $this->fakeGeminiEmbeddings();

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user)->post("/projects/{$project->id}/documents", [
            'files' => [UploadedFile::fake()->createWithContent('notas.md', "# Tema\n\nContenido de prueba.")],
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'batchEmbedContents')) {
                return false;
            }

            $first = $request->data()['requests'][0];

            // Familia -001: taskType + title como parámetros
            return ($first['taskType'] ?? null) === 'RETRIEVAL_DOCUMENT'
                && str_contains($first['title'] ?? '', 'notas')
                && ($first['outputDimensionality'] ?? null) === 768;
        });
    }

    public function test_quota_exhaustion_leaves_document_resumable(): void
    {
        Storage::fake('local');
        $this->fakeGeminiEmbeddings();

        // Tope diario 0 → el embedding no puede ni empezar
        config(['docbrainer.gemini.embed_daily_limit' => 0]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user)->post("/projects/{$project->id}/documents", [
            'files' => [UploadedFile::fake()->createWithContent('notas.md', "# Tema\n\nContenido.")],
        ]);

        $document = $project->documents()->first();

        // No falló: quedó en 'embedding' esperando el reset de cuota,
        // con todos los chunks pendientes (embedded_at IS NULL)
        $this->assertSame('embedding', $document->status->value);
        $this->assertNull($document->failure_reason);
        $this->assertGreaterThan(0, $document->chunk_count);
        $this->assertSame(0, $document->chunks()->whereNotNull('embedded_at')->count());

        Http::assertNothingSent();
    }

    public function test_invalid_api_key_marks_document_failed(): void
    {
        Storage::fake('local');
        config(['docbrainer.gemini.api_key' => '']);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user)->post("/projects/{$project->id}/documents", [
            'files' => [UploadedFile::fake()->createWithContent('notas.md', "# Tema\n\nContenido.")],
        ]);

        $document = $project->documents()->first();

        $this->assertSame('failed', $document->status->value);
        $this->assertStringContainsString('GEMINI_API_KEY', $document->failure_reason);
    }
}
