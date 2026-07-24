<?php

namespace App\Domains\Schools\Actions;

use App\Domains\Schools\Models\School;
use App\Infrastructure\EcolePay\RosterReader;
use App\Infrastructure\Sync\Models\SyncRun;
use App\Shared\Support\PhoneHasher;
use App\Shared\Support\SchoolYear;
use App\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Synchronise le roster `tb_lkmdigital` → `dim_students`, `dim_parents` (parents
 * connus), `bridge_student_parents`.
 *
 * Produit le premier étage de l'entonnoir : chaque numéro valide du roster devient
 * un « parent connu » (`source_parent_id = NULL`) tant qu'aucun compte EcolePay ne
 * lui correspond — le rapprochement se fait à la synchro des comptes.
 *
 * Les numéros invalides (~11 % de la source) partent dans `sync_rejects`, jamais
 * ignorés en silence.
 *
 * Traitement par lots : la mémoire reste bornée à un lot + le cache des parents,
 * indispensable sur ~54 k lignes. Une seule lecture de la source.
 */
final class SyncRoster
{
    private const CHUNK = 2000;

    public function __construct(
        private readonly RosterReader $reader,
        private readonly PhoneHasher $hasher,
    ) {}

    /**
     * @return array{read: int, students: int, known_parents: int, links: int, rejected: int}
     */
    public function __invoke(SyncRun $run): array
    {
        $now = Carbon::now();
        $yearLabel = SchoolYear::current()->label();
        $schoolMap = $this->schoolMap();
        $parentCache = $this->existingParents(); // hex(phone_hash) => parent_id ; grandit au fil des lots

        $stats = ['read' => 0, 'students' => 0, 'known_parents' => 0, 'links' => 0, 'rejected' => 0];

        $this->reader->chunk(self::CHUNK, function (Collection $rows) use (
            &$stats, &$parentCache, $schoolMap, $yearLabel, $now, $run
        ) {
            $this->processChunk($rows, $stats, $parentCache, $schoolMap, $yearLabel, $now, $run->id);
        });

        return $stats;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  array{read:int,students:int,known_parents:int,links:int,rejected:int}  $stats
     * @param  array<string, int>  $parentCache
     * @param  array<string, int>  $schoolMap
     */
    private function processChunk(
        Collection $rows,
        array &$stats,
        array &$parentCache,
        array $schoolMap,
        string $yearLabel,
        Carbon $now,
        int $runId,
    ): void {
        $students = [];      // source_student_id => row
        $newParents = [];    // hex => row (uniquement les inconnus)
        $rejects = [];

        foreach ($rows as $row) {
            $stats['read']++;

            $schoolId = $schoolMap[(string) $row->id_ecole] ?? null;
            if ($schoolId === null) {
                $rejects[] = $this->reject($row, 'unknown_school', "École {$row->id_ecole} absente de dim_schools", $now);

                continue;
            }

            $students[(string) $row->id] = $this->studentRow($row, $schoolId, $yearLabel, $now);

            foreach ($this->phones($row) as [$phone, $raw, $isPrimary]) {
                if ($phone === null) {
                    if ($raw !== null && trim((string) $raw) !== '') {
                        $rejects[] = $this->reject($row, 'invalid_phone', 'Numéro invalide : '.$raw, $now);
                    }

                    continue;
                }

                $hex = bin2hex($hash = $this->hasher->hash($phone));
                if (! isset($parentCache[$hex]) && ! isset($newParents[$hex])) {
                    $newParents[$hex] = $this->knownParentRow($hash, $phone, $now);
                }
            }
        }

        DB::transaction(function () use ($students, $newParents, $rejects, &$stats, &$parentCache, $rows, $schoolMap, $yearLabel, $now, $runId) {
            // Parents connus, puis résolution des identifiants.
            if ($newParents !== []) {
                $stats['known_parents'] += DB::table('dim_parents')->insertOrIgnore(array_values($newParents));
                $parentCache += $this->reloadParents(array_keys($newParents));
            }

            // Élèves, puis résolution.
            if ($students !== []) {
                $stats['students'] += DB::table('dim_students')->insertOrIgnore(array_values($students));
            }
            $studentMap = $this->reloadStudents($yearLabel, array_keys($students));

            // Liens élève ↔ parent (identifiants désormais connus).
            $links = $this->buildLinks($rows, $schoolMap, $studentMap, $parentCache, $now);
            if ($links !== []) {
                $stats['links'] += DB::table('bridge_student_parents')->insertOrIgnore($links);
            }

            // Rejets.
            if ($rejects !== []) {
                $stats['rejected'] += count($rejects);
                DB::table('sync_rejects')->insert(array_map(fn ($r) => [...$r, 'sync_run_id' => $runId], $rejects));
            }
        });
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<string, int>  $schoolMap
     * @param  array<string, int>  $studentMap
     * @param  array<string, int>  $parentCache
     * @return list<array<string, mixed>>
     */
    private function buildLinks(Collection $rows, array $schoolMap, array $studentMap, array $parentCache, Carbon $now): array
    {
        $links = [];
        $validFrom = $now->toDateString();

        foreach ($rows as $row) {
            if (! isset($schoolMap[(string) $row->id_ecole])) {
                continue;
            }
            $studentId = $studentMap[(string) $row->id] ?? null;
            if ($studentId === null) {
                continue;
            }

            foreach ($this->phones($row) as [$phone, , $isPrimary]) {
                if ($phone === null) {
                    continue;
                }
                $parentId = $parentCache[bin2hex($this->hasher->hash($phone))] ?? null;
                if ($parentId === null) {
                    continue;
                }

                $links[$studentId.':'.$parentId] = [
                    'student_id' => $studentId,
                    'parent_id' => $parentId,
                    'relationship' => null,
                    'is_primary_payer' => $isPrimary,
                    'valid_from' => $validFrom,
                    'valid_to' => null,
                ];
            }
        }

        return array_values($links);
    }

    /**
     * Les deux numéros parent d'une ligne : [PhoneNumber|null, brut, estPrincipal].
     * `telephone` est le payeur principal, `telephone2` le secondaire.
     *
     * @return list<array{0: ?PhoneNumber, 1: ?string, 2: bool}>
     */
    private function phones(object $row): array
    {
        return [
            [PhoneNumber::tryFrom($row->telephone), $row->telephone, true],
            [PhoneNumber::tryFrom($row->telephone2), $row->telephone2, false],
        ];
    }

    /** @return array<string, int> source_school_id => dim_schools.id (version courante) */
    private function schoolMap(): array
    {
        return School::query()->where('is_test', false)->current()
            ->pluck('id', 'source_school_id')->all();
    }

    /** @return array<string, int> hex(phone_hash) => parent_id */
    private function existingParents(): array
    {
        $map = [];
        DB::table('dim_parents')->select('id', 'phone_hash')->orderBy('id')
            ->chunk(5000, function ($rows) use (&$map) {
                foreach ($rows as $r) {
                    $map[bin2hex($r->phone_hash)] = $r->id;
                }
            });

        return $map;
    }

    /**
     * @param  list<string>  $hexes
     * @return array<string, int>
     */
    private function reloadParents(array $hexes): array
    {
        $map = [];
        $binaries = array_map(fn (string $h) => hex2bin($h), $hexes);
        DB::table('dim_parents')->select('id', 'phone_hash')
            ->whereIn('phone_hash', $binaries)
            ->get()
            ->each(function ($r) use (&$map) {
                $map[bin2hex($r->phone_hash)] = $r->id;
            });

        return $map;
    }

    /**
     * @param  list<string>  $sourceIds
     * @return array<string, int>
     */
    private function reloadStudents(string $yearLabel, array $sourceIds): array
    {
        $map = [];
        DB::table('dim_students')->select('id', 'source_student_id')
            ->where('school_year_label', $yearLabel)
            ->whereIn('source_student_id', $sourceIds)
            ->get()
            ->each(function ($r) use (&$map) {
                $map[(string) $r->source_student_id] = $r->id;
            });

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function studentRow(object $row, int $schoolId, string $yearLabel, Carbon $now): array
    {
        $classe = $row->classe ? Str::limit(trim((string) $row->classe), 40, '') : null;

        return [
            'source_student_id' => (string) $row->id,
            'school_id' => $schoolId,
            'display_reference' => $row->matricule ? Str::limit(trim((string) $row->matricule), 60, '') : null,
            'education_level' => $classe,
            'class_label' => $classe,
            'school_year_label' => $yearLabel,
            'enrollment_status' => 'enrolled',
            'is_test' => false,
            'row_hash' => hash('sha256', $row->id.'|'.$schoolId.'|'.$classe, true),
            'synced_at' => $now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function knownParentRow(string $hash, PhoneNumber $phone, Carbon $now): array
    {
        return [
            // source_parent_id NULL = parent connu, pas encore inscrit.
            'source_parent_id' => null,
            'phone_hash' => $hash,
            // Le numéro en clair : indispensable pour cibler les campagnes de
            // relance des parents connus non inscrits.
            'phone_e164' => $phone->canonical,
            'phone_country' => config('eac.country_code'),
            'first_known_at' => $now,
            'marketing_consent' => false,
            'is_pseudonymized' => false,
            'is_test' => false,
            'row_hash' => hash('sha256', $hash, true),
            'synced_at' => $now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reject(object $row, string $code, string $detail, Carbon $now): array
    {
        return [
            'entity' => 'roster',
            'source_identifier' => (string) $row->id,
            'reason_code' => $code,
            'reason_detail' => Str::limit($detail, 255, ''),
            'is_resolved' => false,
            'rejected_at' => $now,
        ];
    }
}
