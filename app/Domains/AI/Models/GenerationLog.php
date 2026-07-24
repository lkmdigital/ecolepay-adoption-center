<?php

namespace App\Domains\AI\Models;

use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Table `ai_generation_logs` — chaque invocation du modèle.
 *
 * Distincte des diagnostics : les appels en échec et les reprises n'ont produit
 * aucune analyse mais ont produit un coût.
 */
class GenerationLog extends Model
{
    protected $table = 'ai_generation_logs';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:6',
            'called_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', ['failed', 'timeout', 'rate_limited']);
    }

    public function scopeBillable(Builder $query): Builder
    {
        return $query->whereNotNull('cost');
    }

    /**
     * Un prompt adressé à un modèle externe est une transmission à un tiers :
     * repérer les appels ayant transporté des identifiants.
     */
    public function scopeCarriedIdentifiers(Builder $query): Builder
    {
        return $query->where('data_classification', 'includes_identifiers');
    }

    public function totalTokens(): int
    {
        return (int) $this->input_tokens + (int) $this->output_tokens;
    }
}
