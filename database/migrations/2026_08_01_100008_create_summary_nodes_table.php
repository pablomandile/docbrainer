<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Árbol map-reduce persistido: es lo que hace reanudable un resumen.
        // Nivel 0 = "map" (resume texto fuente); niveles >0 = "reduce"
        // (resumen de resúmenes), hasta un único nodo raíz.
        Schema::create('summary_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('summary_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->unsignedInteger('node_index');
            // Nivel 0: {label, chunk_ids: [...]} — Nivel >0: {node_ids: [...]}
            $table->json('input_ref');
            $table->mediumText('content')->nullable();
            $table->string('status', 15)->default('pending'); // pending|done|failed
            $table->timestamps();

            $table->unique(['summary_id', 'level', 'node_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summary_nodes');
    }
};
