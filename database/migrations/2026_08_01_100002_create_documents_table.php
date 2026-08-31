<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Título editable: alimenta el prefijo contextual de los embeddings
            $table->string('title');
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type')->nullable();
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->string('status', 20)->default('pending');
            // Proveedor de embeddings elegido al subir o re-indexar: gemini | ollama
            $table->string('embedding_provider', 10)->default('gemini');
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedBigInteger('char_count')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            // Avanza durante el embedding: barra de progreso = embedded/chunk_count
            $table->unsignedInteger('embedded_chunk_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
