<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $fillable = [
        'title',
        'original_name',
        'stored_path',
        'mime_type',
        'extension',
        'size_bytes',
        'status',
        'embedding_provider',
        'failure_reason',
        'page_count',
        'char_count',
        'chunk_count',
        'embedded_chunk_count',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'size_bytes' => 'integer',
            'page_count' => 'integer',
            'char_count' => 'integer',
            'chunk_count' => 'integer',
            'embedded_chunk_count' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    /**
     * Ruta (relativa al disco local) del artefacto extracted.json
     * que produce el job de extracción.
     */
    public function extractedJsonPath(): string
    {
        return dirname($this->stored_path).'/extracted.json';
    }

    /**
     * Marca el documento como fallido con una razón legible para el usuario.
     */
    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => DocumentStatus::Failed,
            'failure_reason' => $reason,
        ])->save();
    }
}
