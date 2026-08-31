<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Libro mayor de cuota diaria de la API de Gemini.
        // En DB (no cache) a propósito: debe sobrevivir reinicios — una cuota
        // free-tier reventada puede quedar clavada en 0 del lado de Google.
        Schema::create('api_usage', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            // embed_requests | generate_requests | embed_items
            $table->string('metric', 30);
            $table->unsignedInteger('count')->default(0);

            $table->unique(['date', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage');
    }
};
