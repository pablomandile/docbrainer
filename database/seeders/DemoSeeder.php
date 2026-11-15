<?php

namespace Database\Seeders;

use App\Console\Commands\DemoArmar;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Siembra la demo desde database/demo/fixture.json, sin llamar a la IA: los
 * documentos ya vienen troceados y vectorizados, con sus resúmenes y las
 * conversaciones de ejemplo (lo arma `docbrainer:demo-armar`).
 *
 * - Los ids se insertan tal cual: las citas de los mensajes apuntan a ids de
 *   chunks y documentos.
 * - Las fechas se corren para que lo más nuevo quede de hace un rato, así la
 *   demo siempre se ve al día.
 * - Los originales se copian al disco local, para que se puedan descargar.
 *
 * Sin factories ni Faker: en el server no están (composer --no-dev).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(config('demo.fixture_path')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $corrimiento = $this->corrimiento($fixture);
        $this->usuario($fixture['user_id']);

        foreach (DemoArmar::TABLAS as $tabla) {
            $filas = array_map(fn (array $fila) => $this->preparar($tabla, $fila, $corrimiento), $fixture['tables'][$tabla]);

            // De a pocos: cada chunk lleva su texto y 3 KB de vector.
            foreach (array_chunk($filas, 50) as $bloque) {
                DB::table($tabla)->insert($bloque);
            }
        }

        $this->copiarOriginales($fixture['tables']['documents']);
    }

    /** Segundos a sumar a cada fecha para que lo último quede de hace una hora. */
    private function corrimiento(array $fixture): int
    {
        $ultima = collect($fixture['tables'])->flatten(1)
            // array_values: si no, las filas se pisan por nombre de columna.
            ->flatMap(fn (array $fila) => array_values(array_filter(
                $fila,
                fn ($valor, $columna) => str_ends_with($columna, '_at') && $valor !== null,
                ARRAY_FILTER_USE_BOTH,
            )))
            ->map(fn (string $valor) => Carbon::parse($valor)->getTimestamp())
            ->max();

        return $ultima === null ? 0 : now()->subHour()->getTimestamp() - $ultima;
    }

    private function usuario(int $id): void
    {
        $user = new User;
        $user->forceFill([
            'id' => $id,
            'name' => config('demo.name'),
            'email' => config('demo.email'),
            'password' => Hash::make(config('demo.password')),
            'role' => User::ROLE_USER,
            'is_active' => true,
            'email_verified_at' => now()->subMonths(2),
            'ai_settings' => ['chat_provider' => 'gemini', 'default_index_provider' => 'gemini'],
        ])->save();
    }

    private function preparar(string $tabla, array $fila, int $corrimiento): array
    {
        foreach ($fila as $columna => $valor) {
            if (str_ends_with($columna, '_at') && $valor !== null) {
                $fila[$columna] = Carbon::parse($valor)->addSeconds($corrimiento)->format('Y-m-d H:i:s');
            }
        }

        if ($tabla === 'document_chunks' && $fila['embedding'] !== null) {
            $fila['embedding'] = base64_decode($fila['embedding'], true)
                ?: throw new RuntimeException("Embedding ilegible en el chunk {$fila['id']}.");
        }

        return $fila;
    }

    private function copiarOriginales(array $documentos): void
    {
        $disco = Storage::disk('local');

        foreach ($documentos as $documento) {
            $origen = config('demo.corpus_path').'/'.$documento['original_name'];

            if (! is_file($origen)) {
                throw new RuntimeException("Falta el original del documento «{$documento['title']}»: {$origen}");
            }

            $disco->put($documento['stored_path'], (string) file_get_contents($origen));
        }
    }
}
