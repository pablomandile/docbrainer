<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\ApiQuota;
use App\Services\Retrieval\Retriever;
use App\Services\Vector\VectorMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RetrieverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['docbrainer.gemini.api_key' => 'test-key']);
        RateLimiter::clear('gemini:'.ApiQuota::METRIC_EMBED);
    }

    private function makeDocument(Project $project, string $provider, string $title): Document
    {
        return $project->documents()->create([
            'title' => $title,
            'original_name' => "{$title}.txt",
            'stored_path' => 'x',
            'extension' => 'txt',
            'size_bytes' => 1,
            'status' => DocumentStatus::Ready,
            'embedding_provider' => $provider,
        ]);
    }

    private function makeChunk(Document $doc, int $index, string $content, array $vector, string $model): void
    {
        $doc->chunks()->create([
            'project_id' => $doc->project_id,
            'chunk_index' => $index,
            'content' => $content,
            'token_estimate' => 10,
            'embedding' => VectorMath::pack(VectorMath::normalize($vector)),
            'embedding_model' => $model,
            'embedding_dimensions' => count($vector),
            'embedded_at' => now(),
        ]);
    }

    public function test_single_space_retrieval_ranks_by_similarity(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $doc = $this->makeDocument($project, 'gemini', 'Manual');

        $model = config('docbrainer.gemini.embedding_model');

        // El vector de la query (fake) apunta al eje X
        $queryVector = [1.0, ...array_fill(0, 767, 0.0)];

        Http::fake([
            '*batchEmbedContents*' => Http::response([
                'embeddings' => [['values' => $queryVector]],
            ]),
        ]);

        $this->makeChunk($doc, 0, 'Muy relevante', [1.0, ...array_fill(0, 767, 0.0)], $model);
        $this->makeChunk($doc, 1, 'Poco relevante', [0.0, 1.0, ...array_fill(0, 766, 0.0)], $model);

        $retrieved = app(Retriever::class)->retrieve($project, '¿qué es lo más relevante?');

        $this->assertNotEmpty($retrieved);
        $this->assertSame('Muy relevante', $retrieved[0]->chunk->content);
        $this->assertSame(1, $retrieved[0]->n);
        // Numeración consecutiva
        $this->assertSame(range(1, count($retrieved)), array_map(fn ($r) => $r->n, $retrieved));
    }

    public function test_mixed_provider_project_fuses_both_spaces(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $geminiDoc = $this->makeDocument($project, 'gemini', 'DocGemini');
        $ollamaDoc = $this->makeDocument($project, 'ollama', 'DocOllama');

        $geminiModel = config('docbrainer.gemini.embedding_model');
        $ollamaModel = 'ollama:'.config('docbrainer.ollama.embedding_model');

        $queryVector = [1.0, ...array_fill(0, 767, 0.0)];

        Http::fake([
            '*batchEmbedContents*' => Http::response(['embeddings' => [['values' => $queryVector]]]),
            '*/api/embed' => Http::response(['embeddings' => [$queryVector]]),
        ]);

        $this->makeChunk($geminiDoc, 0, 'Chunk del espacio Gemini', $queryVector, $geminiModel);
        $this->makeChunk($ollamaDoc, 0, 'Chunk del espacio Ollama', $queryVector, $ollamaModel);

        $retrieved = app(Retriever::class)->retrieve($project, 'pregunta');

        $contents = array_map(fn ($r) => $r->chunk->content, $retrieved);

        // Ambos espacios aportan resultados fusionados por RRF
        $this->assertContains('Chunk del espacio Gemini', $contents);
        $this->assertContains('Chunk del espacio Ollama', $contents);
    }

    public function test_returns_empty_when_no_ready_documents(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->assertSame([], app(Retriever::class)->retrieve($project, 'hola'));
        Http::assertNothingSent();
    }
}
