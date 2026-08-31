<?php

namespace App\Services\Vector;

readonly class VectorHit
{
    public function __construct(
        public int $chunkId,
        public float $score,
    ) {}
}
