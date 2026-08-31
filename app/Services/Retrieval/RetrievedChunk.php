<?php

namespace App\Services\Retrieval;

use App\Models\DocumentChunk;

readonly class RetrievedChunk
{
    public function __construct(
        public int $n,              // número de cita [1..k]
        public DocumentChunk $chunk,
        public float $score,
    ) {}

    /**
     * Estructura persistida en messages.citations.
     */
    public function toCitation(): array
    {
        return [
            'n' => $this->n,
            'chunk_id' => $this->chunk->id,
            'document_id' => $this->chunk->document_id,
            'document_title' => $this->chunk->document?->title,
            'section_path' => $this->chunk->section_path,
            'page_start' => $this->chunk->page_start,
            'page_end' => $this->chunk->page_end,
            'score' => round($this->score, 4),
        ];
    }
}
