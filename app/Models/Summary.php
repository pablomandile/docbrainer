<?php

namespace App\Models;

use App\Enums\SummaryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Summary extends Model
{
    protected $fillable = [
        'params',
        'status',
        'progress_total',
        'progress_done',
        'result',
        'failure_reason',
        'model',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'status' => SummaryStatus::class,
            'progress_total' => 'integer',
            'progress_done' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(SummaryNode::class);
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => SummaryStatus::Failed,
            'failure_reason' => $reason,
        ])->save();
    }
}
