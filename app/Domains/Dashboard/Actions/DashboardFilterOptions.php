<?php

namespace App\Domains\Dashboard\Actions;

use App\Shared\Enums\AdoptionStageCode;
use Illuminate\Support\Facades\DB;

/**
 * Fournit les valeurs disponibles pour chaque filtre du Dashboard.
 *
 * Tout est lu dans l'entrepôt : on ne propose que des options qui existent
 * réellement (pas de région vide, pas d'opérateur jamais utilisé…), pour éviter
 * des filtres qui ne renverraient rien.
 */
final class DashboardFilterOptions
{
    /** @return array<string, array<int|string, string>> */
    public function __invoke(): array
    {
        return [
            'schools' => $this->schools(),
            'regions' => $this->regions(),
            'schoolTypes' => $this->schoolTypes(),
            'campaigns' => $this->campaigns(),
            'operators' => $this->operators(),
            'stages' => $this->stages(),
            'schoolYears' => $this->schoolYears(),
        ];
    }

    /** @return array<int, string> */
    private function schools(): array
    {
        return DB::table('dim_schools')->where('is_test', false)->whereNotNull('is_current')
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    private function regions(): array
    {
        return DB::table('dim_schools')->where('is_test', false)->whereNotNull('is_current')
            ->whereNotNull('region')->where('region', '!=', '')
            ->distinct()->orderBy('region')->pluck('region', 'region')->all();
    }

    /** @return array<string, string> */
    private function schoolTypes(): array
    {
        $labels = ['public' => 'Public', 'prive' => 'Privé', 'confessionnel' => 'Confessionnel', 'international' => 'International'];
        $present = DB::table('dim_schools')->where('is_test', false)->whereNotNull('is_current')
            ->whereNotNull('school_type')->where('school_type', '!=', '')
            ->distinct()->pluck('school_type')->all();

        $out = [];
        foreach ($present as $code) {
            $out[$code] = $labels[$code] ?? ucfirst((string) $code);
        }
        asort($out);

        return $out;
    }

    /** @return array<int, string> */
    private function campaigns(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('dim_campaigns')) {
            return [];
        }

        return DB::table('dim_campaigns')->when(
            DB::getSchemaBuilder()->hasColumn('dim_campaigns', 'is_test'),
            fn ($q) => $q->where('is_test', false)
        )->orderByDesc('id')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    private function operators(): array
    {
        // Uniquement les moyens réellement utilisés par des paiements réussis.
        return DB::table('dim_payment_methods as m')
            ->join('fact_payments as p', 'p.payment_method_id', '=', 'm.id')
            ->where('p.is_test', false)->where('p.is_manual', false)->where('p.status', 'success')
            ->distinct()->orderBy('m.label_fr')->pluck('m.label_fr', 'm.code')->all();
    }

    /** @return array<int, string> */
    private function stages(): array
    {
        $out = [];
        foreach (AdoptionStageCode::cases() as $stage) {
            $out[$stage->funnelRank()] = $stage->label();
        }

        return $out;
    }

    /** @return array<string, string> */
    private function schoolYears(): array
    {
        return DB::table('fact_payments')->where('is_test', false)
            ->whereNotNull('school_year_label')->where('school_year_label', '!=', '')
            ->distinct()->orderByDesc('school_year_label')
            ->pluck('school_year_label', 'school_year_label')->all();
    }
}
