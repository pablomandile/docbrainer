<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // null = resumen del proyecto completo
            $table->foreignId('document_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // {length: corto|medio|extenso, style: ejecutivo|academico|divulgativo, language: es|en}
            $table->json('params');
            $table->string('status', 15)->default('pending'); // pending|running|completed|failed
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedInteger('progress_done')->default(0);
            $table->longText('result')->nullable(); // markdown
            $table->text('failure_reason')->nullable();
            $table->string('model', 100)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summaries');
    }
};
