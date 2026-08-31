<?php

namespace Tests\Feature;

use App\Jobs\ChunkDocument;
use App\Jobs\EmbedDocumentChunks;
use App\Jobs\ExtractDocumentText;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_project(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/projects', [
            'name' => 'Ampliación',
            'description' => 'Obra de la casa',
        ]);

        $project = Project::first();

        $response->assertRedirect("/projects/{$project->id}");
        $this->assertSame($user->id, $project->user_id);
    }

    public function test_user_only_sees_own_projects(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Project::factory()->for($user)->create(['name' => 'Mío']);
        Project::factory()->for($other)->create(['name' => 'Ajeno']);

        $this->actingAs($user)
            ->get('/projects')
            ->assertInertia(fn ($page) => $page
                ->component('projects/Index')
                ->has('projects', 1)
                ->where('projects.0.name', 'Mío'));
    }

    public function test_other_users_cannot_access_a_project(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $this->actingAs($intruder)->get("/projects/{$project->id}")->assertForbidden();
        $this->actingAs($intruder)->put("/projects/{$project->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($intruder)->delete("/projects/{$project->id}")->assertForbidden();
    }

    public function test_document_upload_stores_file_and_dispatches_pipeline(): void
    {
        Storage::fake('local');
        Bus::fake();

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $response = $this->actingAs($user)->post("/projects/{$project->id}/documents", [
            'files' => [UploadedFile::fake()->create('manual.pdf', 500, 'application/pdf')],
        ]);

        $response->assertRedirect();

        $document = $project->documents()->first();
        $this->assertNotNull($document);
        $this->assertSame('manual', $document->title);
        $this->assertSame('pdf', $document->extension);
        Storage::disk('local')->assertExists($document->stored_path);

        Bus::assertChained([
            ExtractDocumentText::class,
            ChunkDocument::class,
            EmbedDocumentChunks::class,
        ]);
    }

    public function test_document_upload_rejects_unsupported_extensions(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user)->post("/projects/{$project->id}/documents", [
            'files' => [UploadedFile::fake()->create('virus.exe', 10)],
        ])->assertSessionHasErrors();

        $this->assertSame(0, $project->documents()->count());
    }

    public function test_other_users_cannot_upload_documents(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $this->actingAs($intruder)->post("/projects/{$project->id}/documents", [
            'files' => [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')],
        ])->assertForbidden();
    }

    public function test_full_extraction_pipeline_produces_artifact_for_markdown(): void
    {
        Storage::fake('local');
        // Sin Bus::fake(): con QUEUE_CONNECTION=sync la cadena corre inline
        config(['docbrainer.gemini.api_key' => 'test-key']);
        Http::fake([
            '*batchEmbedContents*' => fn ($request) => Http::response([
                'embeddings' => array_fill(0, count($request->data()['requests']), [
                    'values' => array_fill(0, 768, 0.1),
                ]),
            ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $md = "# Presupuesto\n\nLa cocina cuesta 5 millones.\n\n# Diseño\n\nDos plantas y quincho.";

        $this->actingAs($user)->post("/projects/{$project->id}/documents", [
            'files' => [UploadedFile::fake()->createWithContent('notas.md', $md)],
        ])->assertRedirect();

        $document = $project->documents()->first();

        // La cadena completa corrió: extraer → trocear → vectorizar
        $this->assertSame('ready', $document->status->value);
        $this->assertNull($document->failure_reason);
        $this->assertGreaterThan(0, $document->char_count);

        Storage::disk('local')->assertExists($document->extractedJsonPath());

        $artifact = json_decode(Storage::disk('local')->get($document->extractedJsonPath()), true);
        $titles = array_column($artifact['sections'], 'title');

        $this->assertContains('Presupuesto', $titles);
        $this->assertContains('Diseño', $titles);
    }
}
