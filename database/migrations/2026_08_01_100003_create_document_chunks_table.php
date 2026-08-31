<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            // Denormalizado: el retrieval barre todos los chunks de un proyecto
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->string('section_title')->nullable();
            // Breadcrumb estructural, ej: "Capítulo 2 > 2.3 Métodos"
            $table->string('section_path', 500)->nullable();
            $table->unsignedSmallInteger('page_start')->nullable();
            $table->unsignedSmallInteger('page_end')->nullable();
            $table->mediumText('content');
            $table->unsignedInteger('token_estimate');
            // Vector como binario: 768 floats × 4 bytes = 3 KB (pack('f*'))
            $table->binary('embedding')->nullable();
            $table->string('embedding_model', 100)->nullable();
            $table->unsignedSmallInteger('embedding_dimensions')->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['document_id', 'chunk_index']);
            $table->index('project_id');
        });

        // FULLTEXT solo en MySQL/MariaDB (la búsqueda híbrida lo usa como
        // booster); SQLite —usado en tests— no lo soporta.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('document_chunks', function (Blueprint $table) {
                $table->fullText('content');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
