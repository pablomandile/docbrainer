<?php

use App\Enums\TextSize;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La preferencia vive también en la cuenta y no solo en una cookie: la cookie
 * evita el salto de tamaño al cargar, pero la columna es lo que hace que la
 * preferencia siga a la persona a otro dispositivo.
 *
 * `string` y no ENUM de MySQL: sumar un tamaño después no pide cambiar el tipo
 * de la columna, y el enum de PHP ya valida la entrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('text_size', 20)->default(TextSize::DEFAULT)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('text_size');
        });
    }
};
