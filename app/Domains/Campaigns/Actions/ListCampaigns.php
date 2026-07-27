<?php

namespace App\Domains\Campaigns\Actions;

use App\Domains\Campaigns\Models\Campaign;
use Illuminate\Support\Collection;

/**
 * Liste des campagnes avec leur mesure d'impact, plus les KPI marketing agrégés.
 *
 * À l'échelle attendue (dizaines de campagnes), on mesure chaque campagne à la
 * volée ; on introduira un agrégat matérialisé si le volume l'exige un jour.
 */
final class ListCampaigns
{
    public function __construct(private readonly MeasureCampaign $measure) {}

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, kpis: array<string, mixed>}
     */
    public function __invoke(): array
    {
        $rows = Campaign::with('school')->orderByDesc('campaign_date')->orderByDesc('id')->get()
            ->map(function (Campaign $c) {
                $m = ($this->measure)($c);

                return [
                    'model' => $c,
                    'id' => $c->id,
                    'name' => $c->name,
                    'school' => $c->school?->name,
                    'owner' => $c->owner,
                    'channel' => $c->channel,
                    'status' => $c->status,
                    'date' => $c->campaign_date?->toDateString(),
                    'contacts' => $m['contacts'],
                    'newAccounts' => $m['newAccounts'],
                    'newPayments' => $m['newPayments'],
                    'conversion' => $m['conversion'],
                    'revenue' => $m['revenue'],
                ];
            });

        $contacts = (int) $rows->sum('contacts');
        $newAccounts = (int) $rows->sum('newAccounts');
        $newPayments = (int) $rows->sum('newPayments');

        return [
            'rows' => $rows,
            'kpis' => [
                'campaigns' => $rows->count(),
                'contacts' => $contacts,
                'newAccounts' => $newAccounts,
                'newActive' => $newPayments,
                'conversion' => $contacts > 0 ? round($newPayments / $contacts * 100, 1) : 0.0,
                'revenue' => (int) $rows->sum('revenue'),
            ],
        ];
    }
}
