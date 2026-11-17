<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El semáforo de la GPU del encabezado (GET /gpu/estado).
 */
class GpuIndicatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'docbrainer.ollama.enabled' => true,
            'docbrainer.ollama.base_url' => 'https://gpu.example.com',
            'docbrainer.ollama.embedding_model' => 'embeddinggemma',
            'docbrainer.ollama.chat_model' => 'gemma4:e4b',
        ]);

        Cache::forget('docbrainer:gpu-status');
    }

    /**
     * @param  list<string>  $models
     */
    private function fakeOllama(array $models = ['embeddinggemma:latest', 'gemma4:e4b']): void
    {
        Http::fake([
            '*/api/version' => Http::response(['version' => '0.30.6']),
            '*/api/tags' => Http::response(['models' => array_map(fn ($m) => ['name' => $m], $models)]),
        ]);
    }

    public function test_verde_cuando_la_gpu_responde_con_los_dos_modelos(): void
    {
        $this->fakeOllama();

        $this->actingAs(User::factory()->create())
            ->getJson('/gpu/estado')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('state', 'available')
            ->assertJsonMissingPath('details');
    }

    public function test_amarillo_mientras_indexa_documentos_con_gpu(): void
    {
        $this->fakeOllama();

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        foreach ([['ollama', DocumentStatus::Embedding], ['ollama', DocumentStatus::Ready], ['gemini', DocumentStatus::Embedding]] as $i => [$provider, $status]) {
            $project->documents()->create([
                'title' => "Doc {$i}",
                'original_name' => "doc{$i}.txt",
                'stored_path' => 'x',
                'extension' => 'txt',
                'size_bytes' => 1,
                'status' => $status,
                'embedding_provider' => $provider,
            ]);
        }

        // Solo cuenta el que se está indexando con la GPU
        $this->actingAs(User::factory()->create())
            ->getJson('/gpu/estado')
            ->assertJsonPath('state', 'busy')
            ->assertJsonPath('working', 1);
    }

    public function test_amarillo_si_falta_un_modelo(): void
    {
        $this->fakeOllama(['gemma4:e4b']);

        $this->actingAs(User::factory()->create())
            ->getJson('/gpu/estado')
            ->assertJsonPath('state', 'incomplete')
            ->assertJsonPath('message', 'La GPU responde, pero falta descargar embeddinggemma.');
    }

    public function test_rojo_si_la_pc_no_responde(): void
    {
        // Túnel sin origen: Cloudflare contesta 530
        Http::fake(['*' => Http::response('', 530)]);

        $this->actingAs(User::factory()->create())
            ->getJson('/gpu/estado')
            ->assertJsonPath('state', 'offline');
    }

    public function test_el_admin_ve_host_version_y_modelos(): void
    {
        $this->fakeOllama();

        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/gpu/estado')
            ->assertJsonPath('details.host', 'gpu.example.com')
            ->assertJsonPath('details.version', '0.30.6')
            ->assertJsonPath('details.models', ['embeddinggemma:latest', 'gemma4:e4b']);
    }

    public function test_sin_ollama_habilitado_no_sondea_ni_se_dibuja(): void
    {
        config(['docbrainer.ollama.enabled' => false]);
        Http::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/gpu/estado')
            ->assertJsonPath('state', 'disabled');

        Http::assertNothingSent();

        $this->actingAs($user)
            ->get('/projects')
            ->assertInertia(fn ($page) => $page->where('gpuIndicator', false));
    }

    public function test_con_ollama_habilitado_el_encabezado_lo_dibuja(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/projects')
            ->assertInertia(fn ($page) => $page->where('gpuIndicator', true));
    }

    public function test_pide_sesion(): void
    {
        $this->getJson('/gpu/estado')->assertUnauthorized();
    }
}
