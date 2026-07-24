<?php

namespace App\Shared\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Dimension calendaire — table `dim_dates`.
 *
 * Clé au format AAAAMMJJ, non auto-incrémentée : lisible sans jointure, triable,
 * et exploitable pour partitionner les tables de faits.
 */
class CalendarDate extends Model
{
    protected $table = 'dim_dates';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'full_date' => 'date',
            'first_day_of_month' => 'date',
            'last_day_of_month' => 'date',
            'is_weekend' => 'boolean',
            'is_public_holiday' => 'boolean',
            'is_school_day' => 'boolean',
            'is_school_holiday' => 'boolean',
            'is_enrollment_period' => 'boolean',
            'is_payment_period' => 'boolean',
        ];
    }

    /**
     * Convertit une date en clé de dimension.
     */
    public static function keyFor(Carbon|string $date): int
    {
        return (int) Carbon::parse($date)->format('Ymd');
    }

    public function scopeSchoolYear(Builder $query, string $label): Builder
    {
        return $query->where('school_year_label', $label);
    }

    public function scopeBetweenDates(Builder $query, Carbon|string $from, Carbon|string $to): Builder
    {
        return $query->whereBetween('id', [self::keyFor($from), self::keyFor($to)]);
    }

    /**
     * Cohortes hebdomadaires : c'est (iso_year, week_of_year) qu'il faut utiliser.
     * La semaine 1 de 2026 commence fin décembre 2025 — regrouper par année civile
     * produirait des cohortes fausses au passage d'année.
     */
    public function scopeIsoWeek(Builder $query, int $isoYear, int $week): Builder
    {
        return $query->where('iso_year', $isoYear)->where('week_of_year', $week);
    }
}
