<?php

namespace App\Domains\Campaigns\Actions;

use App\Domains\Campaigns\Models\Campaign;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre les contacts d'une campagne : résout chaque numéro vers un parent
 * EcolePay (par empreinte) et fige s'il avait déjà un compte au moment de la
 * campagne, pour distinguer plus tard les inscriptions vraiment attribuables.
 */
final class ImportCampaignContacts
{
    /**
     * @param  list<array{phone: string, hash: string, name: ?string}>  $contacts
     * @param  array{total:int, valid:int, invalid:int, duplicates:int}  $stats
     */
    public function handle(Campaign $campaign, array $contacts, array $stats): void
    {
        $campaignDate = $campaign->campaign_date ? Carbon::parse($campaign->campaign_date) : Carbon::parse($campaign->created_at);

        // Rapprochement en masse : empreinte → (id parent, date de compte).
        $hashes = array_column($contacts, 'hash');
        $parents = DB::table('dim_parents')->where('is_test', false)->whereIn('phone_hash', $hashes)
            ->get(['id', 'phone_hash', 'account_created_at'])
            ->keyBy(fn ($p) => bin2hex($p->phone_hash));

        $now = now();
        $rows = array_map(function ($c) use ($campaign, $parents, $campaignDate, $now) {
            $match = $parents[bin2hex($c['hash'])] ?? null;
            $hadAccount = $match && $match->account_created_at && Carbon::parse($match->account_created_at) < $campaignDate;

            return [
                'campaign_id' => $campaign->id,
                'raw_phone' => $c['phone'],
                'phone_e164' => $c['phone'],
                'phone_hash' => $c['hash'],
                'parent_id' => $match?->id,
                'full_name' => $c['name'],
                'is_valid' => true,
                'had_account_before' => $hadAccount,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $contacts);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('fact_campaign_contacts')->insert($chunk);
        }

        $campaign->update([
            'contacts_count' => $stats['total'],
            'valid_count' => $stats['valid'],
            'invalid_count' => $stats['invalid'],
            'duplicate_count' => $stats['duplicates'],
        ]);
    }
}
