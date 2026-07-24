<?php

namespace App\Domains\Analytics\Models;

use App\Domains\Schools\Models\School;
use App\Domains\Users\Models\User;
use App\Shared\Models\CalendarDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agrégat — table `agg_school_kpis`, grain : une école × une période.
 *
 * Les colonnes `rank_*` imposent un calcul en deux passages : elles dépendent de
 * toutes les autres écoles et ne peuvent être renseignées qu'après le calcul complet
 * de la période. Un seul passage produirait des rangs faux.
 */
class SchoolKpi extends Model
{
    protected $table = 'agg_school_kpis';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payment_amount' => 'decimal:2',
            'adoption_rate_previous_period' => 'decimal:4',
            'computed_at' => 'datetime',
            'source_watermark_at' => 'datetime',
            'is_final' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_user_id');
    }

    public function periodStart(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'period_start_date_id');
    }

    public function scopeMonthly(Builder $query): Builder
    {
        return $query->where('period_type', 'month');
    }

    public function scopeForPeriod(Builder $query, string $type, int $startDateKey): Builder
    {
        return $query->where('period_type', $type)->where('period_start_date_id', $startDateKey);
    }

    public function scopeManagedBy(Builder $query, User|int $user): Builder
    {
        return $query->where('account_manager_user_id', $user instanceof User ? $user->id : $user);
    }

    public function adoptionRate(): ?float
    {
        return $this->eligible_count > 0
            ? round($this->converted_count / $this->eligible_count, 4)
            : null;
    }

    public function paymentSuccessRate(): ?float
    {
        return $this->payment_attempt_count > 0
            ? round($this->payment_success_count / $this->payment_attempt_count, 4)
            : null;
    }

    /**
     * Écart avec la période précédente. La valeur de référence est figée à la
     * production : la recalculer ferait changer rétroactivement l'écart affiché.
     */
    public function adoptionRateDelta(): ?float
    {
        $current = $this->adoptionRate();

        return $current !== null && $this->adoption_rate_previous_period !== null
            ? round($current - (float) $this->adoption_rate_previous_period, 4)
            : null;
    }
}
