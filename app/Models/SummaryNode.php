<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SummaryNode extends Model
{
    protected $fillable = [
        'level',
        'node_index',
        'input_ref',
        'content',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'node_index' => 'integer',
            'input_ref' => 'array',
        ];
    }

    public function summary(): BelongsTo
    {
        return $this->belongsTo(Summary::class);
    }
}
