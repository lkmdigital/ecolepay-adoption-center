<?php

namespace App\Domains\AI\Models;

use App\Domains\Reports\Models\GeneratedReport;
use App\Domains\Users\Models\User;
use App\Shared\Models\CalendarDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Table `ai_diagnostics`, grain : une analyse générée.
 *
 * `input_snapshot` est la colonne qui donne sa valeur à la table : une
 * recommandation doit rester explicable alors que les données ont changé.
 *
 * Discipline de confidentialité : les instantanés stockent des agrégats et des clés
 * de substitution, jamais d'identifiants en clair. La pseudonymisation d'un parent
 * devient ainsi automatiquement effective dans toutes les productions passées.
 */
class Diagnostic extends Model
{
    protected $table = 'ai_diagnostics';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'structured_output' => 'array',
            'confidence' => 'decimal:3',
            'input_snapshot' => 'array',
            'input_watermark_at' => 'datetime',
            'model_parameters' => 'array',
            'is_pinned' => 'boolean',
            'published_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function generationLog(): BelongsTo
    {
        return $this->belongsTo(GenerationLog::class, 'generation_log_id');
    }

    /**
     * Relancer une analyse ne modifie pas l'ancienne : elle est remplacée, jamais
     * écrasée. Un utilisateur ayant cité l'ancienne doit pouvoir la retrouver.
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class, 'diagnostic_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class, 'diagnostic_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(GeneratedReport::class, 'ai_diagnostic_id');
    }

    public function periodStart(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'period_start_date_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', 'completed')->whereNull('superseded_by_id');
    }

    public function scopeForScope(Builder $query, string $type, ?string $id = null): Builder
    {
        return $query->where('scope_type', $type)
            ->when($id !== null, fn (Builder $inner) => $inner->where('scope_id', $id));
    }

    /**
     * Épinglé ou publié : archive d'entreprise, exclue de toute purge.
     */
    public function scopeProtected(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('is_pinned', true)
            ->orWhereNotNull('published_at'));
    }

    public function isPurgeable(): bool
    {
        return ! $this->is_pinned
            && $this->published_at === null
            && $this->recommendations()->whereIn('status', ['accepted', 'in_progress', 'done'])->doesntExist();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
