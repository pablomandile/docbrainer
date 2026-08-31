<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\GpuStatus;
use App\Services\AI\Ollama\OllamaProvider;
use App\Services\Vector\VectorMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class OllamaProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_embed_for_index_applies_prompt_prefixes_and_normalizes(): void
    {
        Http::fake([
            '*/api/embed' => Http::response([
                'embeddings' => [array_fill(0, 768, 2.0)],
            ]),
        ]);

        $provider = app(OllamaProvider::class);

        $vectors = $provider->embedForIndex([
            ['title' => 'Manual — Capítulo 1', 'text' => 'Contenido del chunk.'],
        ]);

        // Prefijo estilo embeddinggemma aplicado manualmente
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/embed')
                && $request->data()['input'][0] === 'title: Manual — Capítulo 1 | text: Contenido del chunk.'
                && $request->data()['model'] === config('docbrainer.ollama.embedding_model');
        });

        // Normalizado L2
        $this->assertEqualsWithDelta(1.0, VectorMath::dot($vectors[0], $vectors[0]), 0.0001);
    }

    public function test_embed_for_query_uses_search_prefix(): void
    {
        Http::fake([
            '*/api/embed' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]]),
        ]);

        app(OllamaProvider::class)->embedForQuery('¿cuánto cuesta?');

        Http::assertSent(fn ($request) => $request->data()['input'][0] === 'task: search result | query: ¿cuánto cuesta?');
    }

    public function test_reindex_switches_provider_and_reembeds(): void
    {
        Http::fake([
            '*/api/embed' => fn ($request) => Http::response([
                'embeddings' => array_fill(0, count($request->data()['input']), array_fill(0, 768, 0.5)),
            ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $document = $project->documents()->create([
            'title' => 'Doc',
            'original_name' => 'doc.txt',
            'stored_path' => 'x',
            'extension' => 'txt',
            'size_bytes' => 1,
            'status' => DocumentStatus::Ready,
            'embedding_provider' => 'gemini',
            'chunk_count' => 2,
            'embedded_chunk_count' => 2,
        ]);

        foreach ([0, 1] as $i) {
            $document->chunks()->create([
                'project_id' => $project->id,
                'chunk_index' => $i,
                'content' => "Fragmento {$i}",
                'token_estimate' => 10,
                'embedding' => VectorMath::pack(array_fill(0, 768, 0.1)),
                'embedding_model' => 'gemini-embedding-001',
                'embedding_dimensions' => 768,
                'embedded_at' => now(),
            ]);
        }

        // Con QUEUE_CONNECTION=sync el re-embedding corre inline
        $this->actingAs($user)->post("/projects/{$project->id}/documents/{$document->id}/reindex", [
            'embedding_provider' => 'ollama',
        ])->assertRedirect();

        $document->refresh();

        $this->assertSame('ollama', $document->embedding_provider);
        $this->assertSame('ready', $document->status->value);

        $document->chunks()->get()->each(function ($chunk) {
            $this->assertSame('ollama:'.config('docbrainer.ollama.embedding_model'), $chunk->embedding_model);
            $this->assertNotNull($chunk->embedded_at);
        });
    }

    public function test_gpu_status_reports_green_when_gpu_and_ollama_available(): void
    {
        Process::fake([
            'nvidia-smi*' => Process::result(output: "NVIDIA GeForce RTX 3060, 12288, 566.14\n"),
        ]);

        Http::fake([
            '*/api/version' => Http::response(['version' => '0.9.0']),
            '*/api/tags' => Http::response(['models' => [
                ['name' => 'embeddinggemma:latest'],
                ['name' => 'gemma3:4b'],
            ]]),
        ]);

        $status = app(GpuStatus::class)->check(fresh: true);

        $this->assertTrue($status['available']);
        $this->assertSame('success', $status['level']);
        $this->assertStringContainsString('RTX 3060', $status['message']);
        $this->assertStringContainsString('12 GB', $status['message']);
        $this->assertContains('gemma3:4b', $status['ollama']['models']);
    }

    public function test_gpu_status_warns_when_ollama_missing(): void
    {
        Process::fake([
            'nvidia-smi*' => Process::result(output: "NVIDIA GeForce RTX 3060, 12288, 566.14\n"),
        ]);

        Http::fake(fn () => throw new ConnectionException('refused'));

        $status = app(GpuStatus::class)->check(fresh: true);

        $this->assertFalse($status['available']);
        $this->assertSame('warn', $status['level']);
        $this->assertStringContainsString('Ollama no está corriendo', $status['message']);
    }

    public function test_gpu_status_short_circuits_when_ollama_disabled(): void
    {
        // En producción (hosting compartido) no hay GPU: no debe sondear nada
        config(['docbrainer.ollama.enabled' => false]);
        Process::fake();
        Http::fake();

        $status = app(GpuStatus::class)->check(fresh: true);

        $this->assertFalse($status['available']);
        $this->assertSame('error', $status['level']);
        $this->assertStringContainsString('desactivado', $status['message']);

        Process::assertNothingRan();
        Http::assertNothingSent();
    }

    public function test_ai_settings_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/ia', [
            'chat_provider' => 'ollama',
            'default_index_provider' => 'gemini',
            'ollama_chat_model' => 'gemma3:4b',
        ])->assertRedirect();

        $user->refresh();

        $this->assertSame('ollama', $user->chatProvider());
        $this->assertSame('gemini', $user->defaultIndexProvider());
        $this->assertSame('gemma3:4b', $user->ai_settings['ollama_chat_model']);
    }
}
