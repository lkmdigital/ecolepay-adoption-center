<?php

namespace App\Domains\Parents\Actions;

use App\Domains\Parents\Models\ParentProfile;
use App\Shared\Support\PhoneHasher;
use App\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recherche et segmentation des parents pour l'écran Parents.
 *
 * Grain de retour : un parent (dédoublonné de ses écoles). L'étape retenue est la
 * plus avancée que le parent atteint, toutes écoles confondues — un parent adoptant
 * dans une école et connu dans une autre est un adoptant.
 *
 * La recherche par téléphone passe par l'empreinte HMAC : on ne compare jamais un
 * numéro en clair, seul le hash sert de clé d'identité.
 */
final class SearchParents
{
    /** Nombre de lignes ramenées : les segments comptent des dizaines de milliers de parents. */
    private const LIMIT = 150;

    public function __construct(private readonly PhoneHasher $hasher) {}

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, total: int, truncated: bool}
     */
    public function __invoke(string $term = '', string $segment = ''): array
    {
        $term = trim($term);

        $base = ParentProfile::query()
            ->leftJoin('fact_parent_journeys as j', function ($join) {
                $join->on('j.parent_id', '=', 'dim_parents.id')->where('j.is_test', false);
            })
            ->where('dim_parents.is_test', false)
            ->groupBy('dim_parents.id', 'dim_parents.full_name', 'dim_parents.phone_e164', 'dim_parents.account_created_at', 'dim_parents.first_known_at');

        // Recherche : un terme numérique est traité comme un téléphone (via l'empreinte),
        // sinon comme un nom.
        if ($term !== '') {
            $phone = PhoneNumber::tryFrom($term);
            if ($phone !== null) {
                $base->where('dim_parents.phone_hash', $this->hasher->hash($phone));
            } else {
                $base->where('dim_parents.full_name', 'like', '%'.$term.'%');
            }
        }

        // Segment = étape la plus avancée du parent (having sur l'agrégat).
        $stageId = self::segmentStageId($segment);
        if ($stageId !== null) {
            $base->havingRaw('MAX(j.current_stage_id) = ?', [$stageId]);
        } elseif ($segment === 'adoptants') {
            $base->havingRaw('MAX(j.has_ever_paid) = 1');
        }

        // Compte des lignes agrégées (une par parent) sans les ramener toutes.
        $total = DB::query()->fromSub((clone $base)->selectRaw('dim_parents.id'), 'sub')->count();

        $rows = $base
            ->selectRaw('dim_parents.id, dim_parents.full_name, dim_parents.phone_e164, dim_parents.account_created_at, dim_parents.first_known_at')
            ->selectRaw('MAX(j.current_stage_id) as stage_id')
            ->selectRaw('COUNT(DISTINCT j.school_id) as school_count')
            ->selectRaw('COALESCE(MAX(j.has_ever_paid), 0) as has_paid')
            ->selectRaw('COALESCE(SUM(j.successful_payment_count), 0) as payments')
            ->selectRaw('COALESCE(SUM(j.total_amount), 0) as total_amount')
            ->selectRaw('MAX(j.last_activity_at) as last_activity_at')
            ->orderByDesc('total_amount')
            ->orderByRaw('MAX(j.current_stage_id) desc')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->full_name ?: 'Parent sans nom',
                'phone' => $r->phone_e164,
                'stage_id' => $r->stage_id ? (int) $r->stage_id : null,
                'school_count' => (int) $r->school_count,
                'has_paid' => (bool) $r->has_paid,
                'payments' => (int) $r->payments,
                'total_amount' => (int) $r->total_amount,
                'account_created_at' => $r->account_created_at,
                'last_activity_at' => $r->last_activity_at,
            ]);

        return [
            'rows' => $rows,
            'total' => $total,
            'truncated' => $total > self::LIMIT,
        ];
    }

    /**
     * Compte des parents par segment de l'entonnoir (étape la plus avancée).
     *
     * Deux niveaux d'agrégation en SQL : un parent par ligne dans la sous-requête,
     * puis comptage par segment — jamais de chargement des ~30 000 parents en mémoire.
     */
    public function segmentCounts(): array
    {
        $perParent = DB::table('dim_parents')
            ->leftJoin('fact_parent_journeys as j', function ($join) {
                $join->on('j.parent_id', '=', 'dim_parents.id')->where('j.is_test', false);
            })
            ->where('dim_parents.is_test', false)
            ->groupBy('dim_parents.id')
            ->selectRaw('MAX(j.current_stage_id) as stage_id, MAX(j.has_ever_paid) as has_paid');

        $c = DB::query()->fromSub($perParent, 'sub')->selectRaw(
            'COUNT(*) as tous,
             SUM(stage_id = 1) as relance,
             SUM(stage_id = 2) as inscrits,
             SUM(has_paid = 1) as adoptants,
             SUM(stage_id = 5) as risque'
        )->first();

        return [
            'tous' => (int) $c->tous,
            'relance' => (int) $c->relance,
            'inscrits' => (int) $c->inscrits,
            'adoptants' => (int) $c->adoptants,
            'risque' => (int) $c->risque,
        ];
    }

    private static function segmentStageId(string $segment): ?int
    {
        return match ($segment) {
            'relance' => 1,   // connus, non inscrits
            'inscrits' => 2,  // inscrits, non payeurs
            'risque' => 5,    // à risque
            default => null,
        };
    }
}
