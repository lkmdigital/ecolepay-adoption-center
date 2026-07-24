<?php

namespace App\Domains\AI\Models;

use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Table `ai_feedback` — jugement humain sur une production IA.
 */
class Feedback extends Model
{
    protected $table = 'ai_feedback';

    protected $fillable = [
        'diagnostic_id', 'recommendation_id', 'user_id',
        'is_useful', 'rating', 'reason_code', 'comment',
    ];

    protected function casts(): array
    {
        return [
            'is_useful' => 'boolean',
        ];
    }

    public function diagnostic(): BelongsTo
    {
        return $this->belongsTo(Diagnostic::class, 'diagnostic_id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class, 'recommendation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeUseful(Builder $query): Builder
    {
        return $query->where('is_useful', true);
    }
}
