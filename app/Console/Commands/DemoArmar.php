<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Enums\MessageRole;
use App\Enums\SummaryStatus;
use App\Http\Controllers\DocumentController;
use App\Jobs\RunSummaryStep;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Project;
use App\Models\Summary;
use App\Models\User;
use App\Services\AI\LocallyThrottledException;
use App\Services\AI\ProviderManager;
use App\Services\AI\QuotaExceededException;
use App\Services\Chat\PromptBuilder;
use App\Services\Retrieval\RetrievedChunk;
use App\Services\Retrieval\Retriever;
use App\Services\Summary\SummaryPlanner;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Arma el contenido de la demo con la IA de verdad y lo congela en
 * database/demo/fixture.json. Corre UNA vez, en la máquina de desarrollo:
 *
 *     php artisan docbrainer:demo-armar --env=demoarmado
 *
 * `.env.demoarmado` (gitignoreado) apunta a una base local aparte cuyo nombre
 * termina en `_demo` —nunca la de desarrollo, que este comando vaciaría— con
 * QUEUE_CONNECTION=database y una GEMINI_API_KEY.
 *
 * Sube los documentos de database/demo/corpus.php por el mismo pipeline que la
 * app (extraer → trocear → vectorizar), pide un resumen por proyecto, hace las
 * preguntas de ejemplo por el mismo camino que el chat y exporta todo. Es
 * retomable: si se corta (por ejemplo, por el cupo diario), al volver a
 * correrlo sigue desde donde quedó.
 */
class DemoArmar extends Command
{
    protected $signature = 'docbrainer:demo-armar
        {--desde-cero : Vacía la base de armado y empieza de nuevo}';

    protected $description = 'Arma el fixture de la demo con la IA real (solo en local, con --env=demoarmado)';

    /** Tablas que viajan en el fixture, en orden de inserción. */
    public const TABLAS = ['projects', 'documents', 'document_chunks', 'summaries', 'conversations', 'messages'];

    public function handle(): int
    {
        if (! $this->entornoSeguro()) {
            return self::FAILURE;
        }

        if ($this->option('desde-cero')) {
            $this->call('migrate:fresh', ['--force' => true]);
            Storage::disk('local')->deleteDirectory('projects');
        } else {
            $this->call('migrate', ['--force' => true]);
        }

        $user = $this->usuarioDemo();
        $corpus = require database_path('demo/corpus.php');

        foreach ($corpus as $def) {
            $project = $this->proyecto($user, $def);
            $this->info("Proyecto «{$project->name}»");

            foreach ($def['documents'] as $doc) {
                $this->subir($project, $doc);
            }
        }

        if (! $this->procesar('documentos', fn () => $this->documentosListos())) {
            return self::FAILURE;
        }

        foreach ($corpus as $def) {
            $this->pedirResumen($user, Project::where('name', $def['name'])->firstOrFail(), $def['summary']);
        }

        if (! $this->procesar('resúmenes', fn () => $this->resumenesListos())) {
            return self::FAILURE;
        }

        foreach ($corpus as $def) {
            $project = Project::where('name', $def['name'])->firstOrFail();

            foreach ($def['conversations'] as $turnos) {
                if (! $this->conversar($user, $project, $turnos)) {
                    return self::FAILURE;
                }
            }
        }

        $this->exportar($user);

        return self::SUCCESS;
    }

    private function entornoSeguro(): bool
    {
        $base = (string) config('database.connections.'.config('database.default').'.database');

        $problema = match (true) {
            app()->isProduction() || (bool) config('demo.enabled') => 'Esto es solo para la máquina de desarrollo.',
            ! str_ends_with($base, '_demo') => "La base «{$base}» no termina en _demo. Corré con --env=demoarmado (ver el docblock).",
            config('queue.default') !== 'database' => 'Hace falta QUEUE_CONNECTION=database.',
            blank(config('docbrainer.gemini.api_key')) => 'Falta GEMINI_API_KEY.',
            default => null,
        };

        if ($problema !== null) {
            $this->error($problema.' No se tocó nada.');

            return false;
        }

        return true;
    }

    private function usuarioDemo(): User
    {
        $user = User::firstOrNew(['email' => config('demo.email')]);
        $user->forceFill([
            'name' => config('demo.name'),
            'password' => Hash::make(config('demo.password')),
            'role' => User::ROLE_USER,
            'is_active' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'ai_settings' => ['chat_provider' => 'gemini', 'default_index_provider' => 'gemini'],
        ])->save();

        return $user;
    }

    /** @param array{name: string, description: string} $def */
    private function proyecto(User $user, array $def): Project
    {
        $project = Project::firstOrNew(['user_id' => $user->id, 'name' => $def['name']]);
        $project->description = $def['description'];
        $project->user_id = $user->id;
        $project->save();

        return $project;
    }

    /**
     * Lo mismo que DocumentController::storeOne, desde un archivo del corpus.
     *
     * @param  array{file: string, title: string}  $def
     */
    private function subir(Project $project, array $def): void
    {
        if ($project->documents()->where('original_name', $def['file'])->exists()) {
            return;
        }

        $origen = database_path('demo/corpus/'.$def['file']);
        $extension = strtolower(pathinfo($def['file'], PATHINFO_EXTENSION));

        $document = $project->documents()->create([
            'title' => $def['title'],
            'original_name' => $def['file'],
            'stored_path' => '',
            'mime_type' => mime_content_type($origen) ?: null,
            'extension' => $extension,
            'size_bytes' => filesize($origen),
            'status' => DocumentStatus::Pending,
            'embedding_provider' => 'gemini',
        ]);

        $path = Storage::disk('local')->putFileAs(
            "projects/{$project->id}/documents/{$document->id}",
            new File($origen),
            "original.{$extension}",
        );

        $document->forceFill(['stored_path' => $path])->save();
        DocumentController::dispatchPipeline($document);

        $this->line("  subido: {$def['file']}");
    }

    private function documentosListos(): bool
    {
        $fallido = Document::where('status', DocumentStatus::Failed)->first();

        if ($fallido !== null) {
            throw new \RuntimeException("El documento «{$fallido->title}» falló: {$fallido->failure_reason}");
        }

        return Document::where('status', '!=', DocumentStatus::Ready)->doesntExist();
    }

    /** @param array{length: string, style: string, language: string} $params */
    private function pedirResumen(User $user, Project $project, array $params): void
    {
        $existe = Summary::where('project_id', $project->id)
            ->whereNull('document_id')
            ->whereIn('status', [SummaryStatus::Pending, SummaryStatus::Running, SummaryStatus::Completed])
            ->exists();

        if ($existe) {
            return;
        }

        // Lo mismo que SummaryController::store.
        $summary = new Summary(['params' => $params, 'status' => SummaryStatus::Pending]);
        $summary->project_id = $project->id;
        $summary->document_id = null;
        $summary->user_id = $user->id;
        $summary->save();

        $summary->forceFill(['progress_total' => app(SummaryPlanner::class)->plan($summary)])->save();
        RunSummaryStep::dispatch($summary->id);

        $this->line("  resumen pedido: {$project->name}");
    }

    private function resumenesListos(): bool
    {
        $fallido = Summary::where('status', SummaryStatus::Failed)->first();

        if ($fallido !== null) {
            throw new \RuntimeException("Un resumen falló: {$fallido->failure_reason}. Borralo de la base de armado y volvé a correr.");
        }

        return Summary::where('status', '!=', SummaryStatus::Completed)->doesntExist();
    }

    /**
     * Corre la cola hasta que $listo() dé true. Los jobs que se liberan por el
     * throttling quedan con available_at en el futuro: se espera a que venzan.
     */
    private function procesar(string $que, callable $listo): bool
    {
        $this->info("Procesando {$que}…");

        try {
            for ($vuelta = 0; ! $listo(); $vuelta++) {
                $this->callSilently('queue:work', ['--stop-when-empty' => true]);

                if ($listo()) {
                    break;
                }

                $proximo = DB::table('jobs')->min('available_at');

                if ($proximo === null) {
                    $this->error("La cola quedó vacía y los {$que} no terminaron. Revisá la tabla failed_jobs.");

                    return false;
                }

                $espera = max(5, min(120, (int) $proximo - time() + 1));

                if ((int) $proximo - time() > 3600) {
                    $this->warn('Los jobs esperan al próximo día de cupo. Volvé a correr el comando mañana: sigue desde acá.');

                    return false;
                }

                $this->line("  esperando {$espera} s (throttling de la API)…");
                sleep($espera);
            }
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Una conversación de ejemplo, por el mismo camino que ChatStreamController
     * (retrieval → prompt → generación → mensaje con citas), sin streaming.
     *
     * @param  list<string>  $turnos
     */
    private function conversar(User $user, Project $project, array $turnos): bool
    {
        $titulo = Str::limit(trim($turnos[0]), 60);
        $conversation = Conversation::where('project_id', $project->id)->where('title', $titulo)->first();

        if ($conversation !== null && $conversation->messages()->where('role', MessageRole::Assistant)->count() === count($turnos)) {
            return true;
        }

        // A medias (se cortó en una vuelta anterior): se rehace entera.
        $conversation?->delete();

        $conversation = new Conversation(['title' => $titulo]);
        $conversation->project_id = $project->id;
        $conversation->user_id = $user->id;
        $conversation->save();

        $history = [];

        foreach ($turnos as $pregunta) {
            $conversation->messages()->create(['role' => MessageRole::User, 'content' => $pregunta]);

            $retrieved = app(Retriever::class)->retrieve($project, $pregunta);
            $prompts = app(PromptBuilder::class);
            $messages = [...$history, ['role' => 'user', 'content' => $prompts->userTurn($retrieved, $pregunta)]];

            $result = $this->generarConReintentos($prompts->systemInstruction(), $messages);

            if ($result === null) {
                return false;
            }

            $conversation->messages()->create([
                'role' => MessageRole::Assistant,
                'content' => $result['text'],
                'citations' => array_map(fn (RetrievedChunk $r) => $r->toCitation(), $retrieved),
                'model' => $result['model'],
                'prompt_tokens' => $result['prompt_tokens'],
                'completion_tokens' => $result['completion_tokens'],
            ]);

            $history[] = ['role' => 'user', 'content' => $pregunta];
            $history[] = ['role' => 'assistant', 'content' => $result['text']];

            $this->line("  respondida: {$pregunta}");
        }

        return true;
    }

    /** @return array{text: string, prompt_tokens: ?int, completion_tokens: ?int, model: string}|null */
    private function generarConReintentos(string $system, array $messages): ?array
    {
        for ($intento = 1; $intento <= 5; $intento++) {
            try {
                return app(ProviderManager::class)->chat('gemini')->generate($system, $messages);
            } catch (LocallyThrottledException) {
                $this->line('  esperando 65 s (throttling de la API)…');
                sleep(65);
            } catch (QuotaExceededException $e) {
                $this->warn($e->getMessage().' Volvé a correr el comando mañana: sigue desde acá.');

                return null;
            }
        }

        $this->error('La API siguió rechazando la generación después de 5 intentos.');

        return null;
    }

    /**
     * Congela las tablas de la demo en el fixture, con los ids tal cual (las
     * citas de los mensajes apuntan a ids de chunks y documentos).
     */
    private function exportar(User $user): void
    {
        $tablas = [];

        foreach (self::TABLAS as $tabla) {
            $tablas[$tabla] = DB::table($tabla)->orderBy('id')->get()
                ->map(function ($fila) use ($tabla) {
                    $fila = (array) $fila;

                    if ($tabla === 'document_chunks' && $fila['embedding'] !== null) {
                        $fila['embedding'] = base64_encode($fila['embedding']);
                    }

                    return $fila;
                })
                ->all();
        }

        $fixture = [
            'exported_at' => Carbon::now()->toIso8601String(),
            'user_id' => $user->id,
            'embedding_model' => config('docbrainer.gemini.embedding_model'),
            'tables' => $tablas,
        ];

        file_put_contents(
            config('demo.fixture_path'),
            json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );

        $resumen = collect($tablas)->map(fn ($filas, $tabla) => "{$tabla}: ".count($filas))->implode(', ');
        $this->info('Fixture exportado: '.$resumen);
    }
}
