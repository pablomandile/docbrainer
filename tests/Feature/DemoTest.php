<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Project;
use App\Models\Summary;
use App\Models\User;
use App\Services\AI\ApiQuota;
use App\Services\Retrieval\Retriever;
use App\Services\Vector\VectorMath;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El modo demo (APP_DEMO=true) de la copia de pablomandile: el reset, la
 * siembra desde el fixture y lo que la demo no deja hacer.
 *
 * El camino completo de `docbrainer:demo-reset` no se puede correr acá:
 * `migrate:fresh` hace un VACUUM y SQLite no lo permite dentro de la
 * transacción de RefreshDatabase. Se testea la salvaguarda y la siembra por
 * separado; el reset entero se verifica en el server, contra MySQL.
 */
class DemoTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/docbrainer-demo-test-'.uniqid();
        mkdir($this->dir.'/corpus', 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/corpus/*') ?: []);
        @unlink($this->dir.'/fixture.json');
        @rmdir($this->dir.'/corpus');
        @rmdir($this->dir);

        parent::tearDown();
    }

    // ── Salvaguardas ────────────────────────────────────────────────

    public function test_el_reset_se_niega_sin_el_flag_y_no_borra_nada(): void
    {
        config(['demo.enabled' => false]);
        $user = User::factory()->create();

        $this->artisan('docbrainer:demo-reset')
            ->expectsOutputToContain('No se borró nada')
            ->assertFailed();

        $this->assertModelExists($user);
    }

    public function test_el_armado_se_niega_en_la_demo(): void
    {
        config(['demo.enabled' => true]);
        $user = User::factory()->create();

        $this->artisan('docbrainer:demo-armar')
            ->expectsOutputToContain('No se tocó nada')
            ->assertFailed();

        $this->assertModelExists($user);
    }

    public function test_el_armado_se_niega_con_una_base_que_no_es_la_de_armado(): void
    {
        // La base de los tests (:memory:) no termina en _demo.
        $user = User::factory()->create();

        $this->artisan('docbrainer:demo-armar')
            ->expectsOutputToContain('no termina en _demo')
            ->assertFailed();

        $this->assertModelExists($user);
    }

    // ── Siembra ─────────────────────────────────────────────────────

    public function test_el_seeder_carga_el_fixture_con_sus_ids_archivos_y_fechas_al_dia(): void
    {
        Storage::fake('local');
        $this->fixtureDePrueba();

        $this->seed(DemoSeeder::class);

        $user = User::where('email', config('demo.email'))->firstOrFail();
        $this->assertSame(7, $user->id);
        $this->assertTrue(Hash::check(config('demo.password'), $user->password));
        $this->assertTrue((bool) $user->is_active);
        $this->assertFalse($user->isAdmin());
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('gemini', $user->chatProvider());

        $document = Document::findOrFail(21);
        $this->assertSame(DocumentStatus::Ready, $document->status);
        Storage::disk('local')->assertExists($document->stored_path);
        $this->assertSame('Artículo 14.- Todos los habitantes…', Storage::disk('local')->get($document->stored_path));

        $chunk = DocumentChunk::findOrFail(301);
        $this->assertCount(768, VectorMath::unpack($chunk->embedding));

        $this->assertSame(1, Summary::count());

        // Las citas siguen apuntando a chunks que existen
        $assistant = Conversation::findOrFail(5)->messages()->where('role', 'assistant')->firstOrFail();
        foreach ($assistant->citations as $cita) {
            $this->assertNotNull(DocumentChunk::find($cita['chunk_id']));
        }

        // Lo más nuevo del fixture (de hace meses) quedó de hace una hora
        $this->assertTrue(Conversation::findOrFail(5)->updated_at->between(now()->subHours(2), now()));
        $this->assertTrue(Project::findOrFail(3)->created_at->lessThan(Conversation::findOrFail(5)->updated_at));
    }

    public function test_lo_sembrado_se_puede_consultar_con_el_retrieval(): void
    {
        Storage::fake('local');
        $this->fixtureDePrueba();
        $this->seed(DemoSeeder::class);

        config(['docbrainer.gemini.api_key' => 'test-key']);
        RateLimiter::clear('gemini:'.ApiQuota::METRIC_EMBED);
        Http::fake([
            '*batchEmbedContents*' => Http::response(['embeddings' => [['values' => array_fill(0, 768, 0.5)]]]),
        ]);

        $resultados = app(Retriever::class)->retrieve(Project::findOrFail(3), '¿Qué dice sobre la prensa?');

        $this->assertNotEmpty($resultados);
        $this->assertSame(21, $resultados[0]->chunk->document_id);
    }

    public function test_el_fixture_real_es_coherente(): void
    {
        $path = database_path('demo/fixture.json');

        if (! is_file($path)) {
            $this->markTestSkipped('Todavía no se armó el fixture (docbrainer:demo-armar).');
        }

        $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $tablas = $fixture['tables'];

        $this->assertSame(config('docbrainer.gemini.embedding_model'), $fixture['embedding_model']);
        $this->assertNotEmpty($tablas['projects']);

        foreach ($tablas['documents'] as $doc) {
            $this->assertSame('ready', $doc['status'], $doc['title']);
            $this->assertFileExists(database_path('demo/corpus/'.$doc['original_name']));
        }

        $chunkIds = [];
        foreach ($tablas['document_chunks'] as $chunk) {
            $this->assertCount(768, VectorMath::unpack(base64_decode($chunk['embedding'], true)));
            $chunkIds[$chunk['id']] = true;
        }

        foreach ($tablas['summaries'] as $summary) {
            $this->assertSame('completed', $summary['status']);
        }

        foreach ($tablas['messages'] as $message) {
            foreach (json_decode($message['citations'] ?? '[]', true) as $cita) {
                $this->assertArrayHasKey($cita['chunk_id'], $chunkIds);
            }
        }
    }

    // ── Lo que la demo no deja hacer ────────────────────────────────

    public function test_en_la_demo_no_se_entra_por_otro_lado(): void
    {
        config(['demo.enabled' => true]);

        $this->get('/register')->assertRedirect('/login');
        $this->post('/register', ['email' => 'x@y.z'])->assertRedirect('/login');
        $this->get('/forgot-password')->assertRedirect('/login');
        $this->get('/auth/google/redirect')->assertRedirect('/login');
        $this->assertSame(0, User::count());
    }

    public function test_en_la_demo_la_cuenta_compartida_no_se_toca(): void
    {
        config(['demo.enabled' => true]);
        $user = User::factory()->create(['name' => 'Visitante', 'is_active' => true]);
        $user->forceFill(['ai_settings' => ['chat_provider' => 'gemini']])->save();

        $this->actingAs($user)->from('/settings/profile')
            ->patch('/settings/profile', ['name' => 'Otro', 'email' => 'otro@example.com'])
            ->assertRedirect('/settings/profile')
            ->assertSessionHas('error');

        $this->actingAs($user)->from('/settings/password')
            ->put('/settings/password', ['current_password' => 'password', 'password' => 'x', 'password_confirmation' => 'x'])
            ->assertSessionHas('error');

        $this->actingAs($user)->from('/settings/ia')
            ->patch('/settings/ia', ['chat_provider' => 'ollama', 'default_index_provider' => 'ollama'])
            ->assertSessionHas('error');

        $this->actingAs($user)->from('/settings/profile')
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertSessionHas('error');

        $user->refresh();
        $this->assertSame('Visitante', $user->name);
        $this->assertSame('gemini', $user->chatProvider());
        $this->assertModelExists($user);
    }

    public function test_el_login_de_la_demo_muestra_los_datos_y_esconde_registro_y_google(): void
    {
        config([
            'demo.enabled' => true,
            'services.google.client_id' => 'id',
            'services.google.client_secret' => 'secreto',
        ]);

        $this->get('/login')->assertInertia(fn ($page) => $page
            ->where('demo.email', config('demo.email'))
            ->where('demo.password', config('demo.password'))
            ->where('canRegister', false)
            ->where('canResetPassword', false)
            ->where('googleLoginEnabled', false));
    }

    public function test_sin_el_flag_nada_de_esto_actua(): void
    {
        config(['demo.enabled' => false]);
        $user = User::factory()->create(['is_active' => true]);

        $this->get('/login')->assertInertia(fn ($page) => $page->where('demo', null));

        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => 'Nuevo nombre', 'email' => $user->email])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $this->assertSame('Nuevo nombre', $user->refresh()->name);
    }

    /**
     * Un fixture chico con el mismo formato que exporta docbrainer:demo-armar,
     * con ids salteados (para ver que se respetan) y fechas viejas.
     */
    private function fixtureDePrueba(): void
    {
        $viejo = now()->subMonths(5);
        $f = fn ($minutos) => $viejo->copy()->addMinutes($minutos)->format('Y-m-d H:i:s');
        $modelo = config('docbrainer.gemini.embedding_model');
        $vector = fn ($v) => base64_encode(VectorMath::pack(VectorMath::normalize(array_fill(0, 768, $v))));

        file_put_contents($this->dir.'/corpus/constitucion.md', 'Artículo 14.- Todos los habitantes…');

        $fixture = [
            'exported_at' => $f(100),
            'user_id' => 7,
            'embedding_model' => $modelo,
            'tables' => [
                'projects' => [[
                    'id' => 3, 'user_id' => 7, 'name' => 'Constituciones', 'description' => null,
                    'created_at' => $f(0), 'updated_at' => $f(0),
                ]],
                'documents' => [[
                    'id' => 21, 'project_id' => 3, 'title' => 'Constitución', 'original_name' => 'constitucion.md',
                    'stored_path' => 'projects/3/documents/21/original.md', 'mime_type' => 'text/markdown',
                    'extension' => 'md', 'size_bytes' => 40, 'status' => 'ready', 'embedding_provider' => 'gemini',
                    'failure_reason' => null, 'page_count' => null, 'char_count' => 40, 'chunk_count' => 2,
                    'embedded_chunk_count' => 2, 'processed_at' => $f(5), 'created_at' => $f(1), 'updated_at' => $f(5),
                ]],
                'document_chunks' => array_map(fn ($i) => [
                    'id' => 300 + $i, 'document_id' => 21, 'project_id' => 3, 'chunk_index' => $i,
                    'section_title' => 'Capítulo Primero', 'section_path' => 'Primera parte > Capítulo Primero',
                    'page_start' => null, 'page_end' => null, 'content' => "Artículo 1{$i}.- …", 'token_estimate' => 5,
                    'embedding' => $vector(0.5 - $i * 0.1), 'embedding_model' => $modelo, 'embedding_dimensions' => 768,
                    'embedded_at' => $f(5), 'created_at' => $f(3),
                ], [1, 2]),
                'summaries' => [[
                    'id' => 2, 'project_id' => 3, 'document_id' => null, 'user_id' => 7,
                    'params' => json_encode(['length' => 'medio', 'style' => 'divulgativo', 'language' => 'es']),
                    'status' => 'completed', 'progress_total' => 1, 'progress_done' => 1, 'result' => '# Resumen',
                    'failure_reason' => null, 'model' => 'gemini-test', 'created_at' => $f(10), 'updated_at' => $f(20),
                ]],
                'conversations' => [[
                    'id' => 5, 'project_id' => 3, 'user_id' => 7, 'title' => '¿Libertad de prensa?',
                    'created_at' => $f(30), 'updated_at' => $f(100),
                ]],
                'messages' => [
                    [
                        'id' => 11, 'conversation_id' => 5, 'role' => 'user', 'content' => '¿Libertad de prensa?',
                        'citations' => null, 'model' => null, 'prompt_tokens' => null, 'completion_tokens' => null,
                        'status' => 'complete', 'created_at' => $f(30), 'updated_at' => $f(30),
                    ],
                    [
                        'id' => 12, 'conversation_id' => 5, 'role' => 'assistant', 'content' => 'El artículo 14 [1].',
                        'citations' => json_encode([['n' => 1, 'chunk_id' => 301, 'document_id' => 21, 'document_title' => 'Constitución']]),
                        'model' => 'gemini-test', 'prompt_tokens' => 10, 'completion_tokens' => 5,
                        'status' => 'complete', 'created_at' => $f(31), 'updated_at' => $f(31),
                    ],
                ],
            ],
        ];

        file_put_contents($this->dir.'/fixture.json', json_encode($fixture, JSON_UNESCAPED_UNICODE));
        config(['demo.fixture_path' => $this->dir.'/fixture.json', 'demo.corpus_path' => $this->dir.'/corpus']);
    }
}
