<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Vacía la base y vuelve a sembrar la demo desde el fixture. Corre todas las
 * noches en la copia de pablomandile (ver routes/console.php), y solo ahí.
 */
class DemoReset extends Command
{
    protected $signature = 'docbrainer:demo-reset';

    protected $description = 'Borra todos los datos y resiembra la demo (solo con APP_DEMO=true)';

    public function handle(): int
    {
        // Sin el flag no se toca nada: este comando borra la base entera.
        if (! config('demo.enabled')) {
            $this->error('La demo no está activa (APP_DEMO). No se borró nada.');

            return self::FAILURE;
        }

        /*
         * El AppServiceProvider prohíbe los comandos destructivos en producción,
         * y la demo corre con APP_ENV=production. Ojo: `migrate:fresh` prohibido
         * **no falla**, sale con un aviso; por eso además se mira su código de
         * salida antes de borrar archivos o sembrar encima de lo que haya.
         */
        DB::prohibitDestructiveCommands(false);

        if ($this->call('migrate:fresh', ['--force' => true]) !== self::SUCCESS) {
            $this->error('migrate:fresh no corrió. No se borraron archivos ni se sembró nada.');

            return self::FAILURE;
        }

        // Lo que subieron los visitantes (y los originales de la siembra anterior).
        Storage::disk('local')->deleteDirectory('projects');

        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        return self::SUCCESS;
    }
}
