<?php

namespace App\Models;

use App\Enums\MessageRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'role',
        'content',
        'citations',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'citations' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
