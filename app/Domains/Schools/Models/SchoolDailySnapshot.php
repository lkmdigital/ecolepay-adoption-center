<?php

namespace App\Domains\Schools\Models;

use App\Shared\Models\AdoptionRuleVersion;
use App\Shared\Models\CalendarDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Instantané périodique — table `fact_school_daily_snapshots`, grain : une école
 * × un jour.
 *
 * Rend les courbes de tendance immuables : si une donnée EcolePay est corrigée trois
 * mois plus tard, la photo du jour reste le reflet de ce qui était connu ce jour-là.
 */
class SchoolDailySnapshot extends Model
{
    protected $table = 'fact_school_daily_snapshots';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payment_amount' => 'decimal:2',
            'computed_at' => 'datetime',
            'source_watermark_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function date(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'date_id');
    }

    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(AdoptionRuleVersion::class, 'rule_version_id');
    }

    public function scopeBetween(Builder $query, int $fromDateKey, int $toDateKey): Builder
    {
        return $query->whereBetween('date_id', [$fromDateKey, $toDateKey]);
    }

    /**
     * Un taux n'est pas additif : il se calcule depuis son couple numérateur /
     * dénominateur, jamais en moyennant des taux stockés.
     */
    public function adoptionRate(): ?float
    {
        return $this->eligible_count > 0
            ? round($this->converted_count / $this->eligible_count, 4)
            : null;
    }
}
