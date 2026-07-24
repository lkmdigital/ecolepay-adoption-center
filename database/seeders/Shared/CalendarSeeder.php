<?php

namespace Database\Seeders\Shared;

use App\Shared\Models\CalendarDate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Génère `dim_dates` sur la plage configurée.
 *
 * Le référentiel scolaire est la raison d'être de cette table : les paiements
 * scolaires sont massivement saisonniers, et sans année ni trimestre scolaires
 * stockés, chaque comparaison « ce trimestre contre le précédent » deviendrait une
 * requête écrite à la main.
 *
 * Idempotent : réexécuter met à jour les lignes existantes, ce qui permet
 * d'enrichir le calendrier (fêtes lunaires, ajustements de trimestres) sans
 * reconstruire la table.
 */
class CalendarSeeder extends Seeder
{
    private const DAY_NAMES = [
        1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi',
        5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche',
    ];

    private const MONTH_NAMES = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    /** @var array<string, string> */
    private array $holidays = [];

    public function run(): void
    {
        $config = config('eac.calendar');

        $start = Carbon::create($config['start_year'], 1, 1)->startOfDay();
        $end = Carbon::create($config['end_year'], 12, 31)->startOfDay();

        $this->holidays = $this->buildHolidayMap($config, $config['start_year'], $config['end_year']);

        $rows = [];
        $cursor = $start->copy();

        while ($cursor->lessThanOrEqualTo($end)) {
            $rows[] = $this->buildRow($cursor, $config);

            if (count($rows) === 500) {
                $this->flush($rows);
                $rows = [];
            }

            $cursor->addDay();
        }

        $this->flush($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function flush(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        CalendarDate::query()->upsert($rows, ['id'], array_keys($rows[0]));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function buildRow(Carbon $date, array $config): array
    {
        $key = $date->format('m-d');
        $schoolYearStart = $this->schoolYearStartFor($date, $config);
        $term = $this->termFor($date, $config, $schoolYearStart);

        return [
            'id' => (int) $date->format('Ymd'),
            'full_date' => $date->toDateString(),
            'day_of_month' => $date->day,
            'day_of_week' => $date->dayOfWeekIso,
            'day_name' => self::DAY_NAMES[$date->dayOfWeekIso],
            'day_of_year' => $date->dayOfYear,
            'week_of_year' => (int) $date->isoWeek(),
            // Distinct de l'année civile : la semaine 1 de 2026 commence fin
            // décembre 2025. Regrouper par année civile produirait des cohortes
            // hebdomadaires fausses au passage d'année.
            'iso_year' => (int) $date->isoWeekYear(),
            'month_number' => $date->month,
            'month_name' => self::MONTH_NAMES[$date->month],
            'quarter' => $date->quarter,
            'year' => $date->year,
            'first_day_of_month' => $date->copy()->startOfMonth()->toDateString(),
            'last_day_of_month' => $date->copy()->endOfMonth()->toDateString(),
            'is_weekend' => $date->isWeekend(),
            'is_public_holiday' => isset($this->holidays[$date->toDateString()]),
            'holiday_name' => $this->holidays[$date->toDateString()] ?? null,
            'school_year_label' => sprintf('%d-%d', $schoolYearStart, $schoolYearStart + 1),
            'school_year_start' => $schoolYearStart,
            'school_term' => $term['number'],
            'school_term_label' => $term['label'],
            'is_school_day' => $term['number'] !== null
                && ! $date->isWeekend()
                && ! isset($this->holidays[$date->toDateString()]),
            'is_school_holiday' => $term['number'] === null,
            'is_enrollment_period' => $this->inMonthDayRange($key, $config['enrollment_period']),
            'is_payment_period' => $this->inAnyMonthDayRange($key, $config['payment_periods']),
        ];
    }

    /**
     * Année de rentrée à laquelle la date se rattache. Tout ce qui précède le
     * 15 septembre appartient à l'année scolaire précédente.
     *
     * @param  array<string, mixed>  $config
     */
    private function schoolYearStartFor(Carbon $date, array $config): int
    {
        $boundary = Carbon::create(
            $date->year,
            $config['school_year_start']['month'],
            $config['school_year_start']['day'],
        );

        return $date->greaterThanOrEqualTo($boundary) ? $date->year : $date->year - 1;
    }

    /**
     * Trimestre scolaire, ou null pendant les vacances.
     *
     * Le premier trimestre se situe dans l'année civile de la rentrée, les deux
     * suivants dans la suivante.
     *
     * @param  array<string, mixed>  $config
     * @return array{number: int|null, label: string|null}
     */
    private function termFor(Carbon $date, array $config, int $schoolYearStart): array
    {
        foreach ($config['terms'] as $number => $term) {
            $calendarYear = $number === 1 ? $schoolYearStart : $schoolYearStart + 1;

            $from = Carbon::create($calendarYear, $term['from']['month'], $term['from']['day']);
            $to = Carbon::create($calendarYear, $term['to']['month'], $term['to']['day']);

            if ($date->betweenIncluded($from, $to)) {
                return ['number' => $number, 'label' => $term['label']];
            }
        }

        return ['number' => null, 'label' => null];
    }

    /**
     * @param  array<string, array<string, int>>  $range
     */
    private function inMonthDayRange(string $monthDay, array $range): bool
    {
        $from = sprintf('%02d-%02d', $range['from']['month'], $range['from']['day']);
        $to = sprintf('%02d-%02d', $range['to']['month'], $range['to']['day']);

        // Plage qui franchit le 31 décembre.
        return $from <= $to
            ? $monthDay >= $from && $monthDay <= $to
            : $monthDay >= $from || $monthDay <= $to;
    }

    /**
     * @param  array<int, array<string, array<string, int>>>  $ranges
     */
    private function inAnyMonthDayRange(string $monthDay, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($this->inMonthDayRange($monthDay, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fêtes fixes, fêtes mobiles calculées depuis Pâques, et fêtes lunaires
     * renseignées manuellement.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function buildHolidayMap(array $config, int $startYear, int $endYear): array
    {
        $map = [];

        for ($year = $startYear; $year <= $endYear; $year++) {
            foreach ($config['fixed_holidays'] as $monthDay => $label) {
                $map[sprintf('%d-%s', $year, $monthDay)] = $label;
            }

            $easter = $this->easterFor($year);

            foreach ($config['easter_offsets'] as $offset => $label) {
                $map[$easter->copy()->addDays($offset)->toDateString()] = $label;
            }
        }

        // Calendrier lunaire : non calculable arithmétiquement, donc renseigné
        // année par année dans la configuration.
        return [...$map, ...$config['lunar_holidays']];
    }

    /**
     * Dimanche de Pâques, algorithme grégorien anonyme.
     *
     * Implémenté ici plutôt qu'avec easter_date() : cette fonction dépend de
     * l'extension calendar, absente de nombreuses installations PHP.
     */
    private function easterFor(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);

        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day)->startOfDay();
    }
}
