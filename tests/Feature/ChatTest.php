<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\ApiQuota;
use App\Services\Vector\VectorMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['docbrainer.gemini.api_key' => 'test-key']);
        RateLimiter::clear('gemini:'.ApiQuota::METRIC_EMBED);
        RateLimiter::clear('gemini:'.ApiQuota::METRIC_GENERATE);
    }

    /**
     * Proyecto con un documento listo y chunks vectorizados conocidos.
     */
    private function readyProject(User $user): Project
    {
        $project = Project::factory()->for($user)->create();

        $document = $project->documents()->create([
            'title' => 'Manual de obra',
            'original_name' => 'manual.pdf',
            'stored_path' => 'x',
            'extension' => 'pdf',
            'size_bytes' => 1,
            'status' => DocumentStatus::Ready,
            'chunk_count' => 2,
            'embedded_chunk_count' => 2,
        ]);

        $model = config('docbrainer.gemini.embedding_model');

        $document->chunks()->create([
            'project_id' => $project->id,
            'chunk_index' => 0,
            'section_path' => 'Capítulo 1 > Presupuesto',
            'page_start' => 3,
            'page_end' => 3,
            'content' => 'La cocina cuesta 5 millones de pesos.',
            'token_estimate' => 10,
            'embedding' => VectorMath::pack(VectorMath::normalize(array_fill(0, 768, 0.5))),
            'embedding_model' => $model,
            'embedding_dimensions' => 768,
            'embedded_at' => now(),
        ]);

        $document->chunks()->create([
            'project_id' => $project->id,
            'chunk_index' => 1,
            'section_path' => 'Capítulo 2 > Diseño',
            'page_start' => 10,
            'page_end' => 11,
            'content' => 'La casa tendrá dos plantas con quincho.',
            'token_estimate' => 10,
            'embedding' => VectorMath::pack(VectorMath::normalize([1, ...array_fill(0, 767, 0.1)])),
            'embedding_model' => $model,
            'embedding_dimensions' => 768,
            'embedded_at' => now(),
        ]);

        return $project;
    }

    private function fakeGemini(): void
    {
        $sse = implode('', [
            'data: '.json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'La cocina cuesta ']]]]],
            ])."\n\n",
            'data: '.json_encode([
                'candidates' => [['content' => ['parts' => [['text' => '5 millones [1].']]]]],
                'usageMetadata' => ['promptTokenCount' => 120, 'candidatesTokenCount' => 15],
            ])."\n\n",
        ]);

        Http::fake([
            '*batchEmbedContents*' => Http::response([
                'embeddings' => [['values' => array_fill(0, 768, 0.5)]],
            ]),
            '*streamGenerateContent*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']),
        ]);
    }

    public function test_chat_streams_answer_and_persists_messages_with_citations(): void
    {
        $this->fakeGemini();

        $user = User::factory()->create();
        $project = $this->readyProject($user);

        $response = $this->actingAs($user)->post("/projects/{$project->id}/chat", [
            'message' => '¿Cuánto cuesta la cocina?',
        ]);

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('event: meta', $content);
        $this->assertStringContainsString('event: delta', $content);
        $this->assertStringContainsString('event: done', $content);

        // Conversación creada con título derivado de la pregunta
        $conversation = Conversation::first();
        $this->assertSame($user->id, $conversation->user_id);
        $this->assertStringContainsString('cocina', $conversation->title);

        // Mensajes persistidos: pregunta + respuesta con citas
        $this->assertSame(2, $conversation->messages()->count());

        $assistant = $conversation->messages()->where('role', 'assistant')->first();
        $this->assertSame('La cocina cuesta 5 millones [1].', $assistant->content);
        $this->assertSame(120, $assistant->prompt_tokens);
        $this->assertNotEmpty($assistant->citations);
        $this->assertSame('Manual de obra', $assistant->citations[0]['document_title']);
        $this->assertSame('Capítulo 1 > Presupuesto', $assistant->citations[0]['section_path']);
    }

    public function test_chat_reuses_existing_conversation(): void
    {
        $this->fakeGemini();

        $user = User::factory()->create();
        $project = $this->readyProject($user);

        $conversation = new Conversation(['title' => 'Charla previa']);
        $conversation->project_id = $project->id;
        $conversation->user_id = $user->id;
        $conversation->save();

        $this->actingAs($user)->post("/projects/{$project->id}/chat", [
            'message' => 'Segunda pregunta',
            'conversation_id' => $conversation->id,
        ])->assertOk()->streamedContent();

        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_chat_without_ready_documents_emits_error_event(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $content = $this->actingAs($user)->post("/projects/{$project->id}/chat", [
            'message' => 'Hola',
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString('event: error', $content);
        $this->assertStringContainsString('No hay documentos listos', $content);
        Http::assertNothingSent();
    }

    public function test_other_users_cannot_chat_in_foreign_project(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->readyProject($owner);

        $this->actingAs($intruder)->post("/projects/{$project->id}/chat", [
            'message' => 'Hola',
        ])->assertForbidden();
    }

    public function test_conversation_endpoints_enforce_ownership(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->readyProject($owner);

        $conversation = new Conversation(['title' => 'Privada']);
        $conversation->project_id = $project->id;
        $conversation->user_id = $owner->id;
        $conversation->save();

        $this->actingAs($intruder)
            ->get("/projects/{$project->id}/conversations/{$conversation->id}")
            ->assertForbidden();

        // Otro usuario del mismo proyecto es imposible (owner-only), pero una
        // conversación de otro usuario dentro del proyecto devuelve 404
        $this->actingAs($owner)
            ->get("/projects/{$project->id}/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('conversation.title', 'Privada');
    }
}
