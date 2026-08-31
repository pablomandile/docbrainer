<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'document_id',
        'project_id',
        'chunk_index',
        'section_title',
        'section_path',
        'page_start',
        'page_end',
        'content',
        'token_estimate',
        'embedding',
        'embedding_model',
        'embedding_dimensions',
        'embedded_at',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'page_start' => 'integer',
            'page_end' => 'integer',
            'token_estimate' => 'integer',
            'embedding_dimensions' => 'integer',
            'embedded_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
