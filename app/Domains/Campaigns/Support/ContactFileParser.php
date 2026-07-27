<?php

namespace App\Domains\Campaigns\Support;

use App\Shared\Support\PhoneHasher;
use App\Shared\ValueObjects\PhoneNumber;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Lit un fichier de contacts (Excel ou CSV), détecte la colonne téléphone,
 * normalise/valide/déduplique les numéros et rend un audit prêt pour l'aperçu
 * du wizard comme pour l'import.
 *
 * Le rapprochement avec les parents EcolePay se fait par l'empreinte HMAC du
 * numéro — jamais par le numéro en clair.
 */
final class ContactFileParser
{
    public function __construct(private readonly PhoneHasher $hasher) {}

    /**
     * @return array{
     *   total: int, valid: int, invalid: int, duplicates: int,
     *   contacts: list<array{phone: string, hash: string, name: ?string}>,
     *   preview: list<array{phone: string, name: ?string, status: string}>
     * }
     */
    public function parse(string $absolutePath): array
    {
        $rows = $this->readRows($absolutePath);
        $rows = $this->stripHeader($rows);
        $phoneCol = $this->detectPhoneColumn($rows);
        $nameCol = $this->detectNameColumn($rows, $phoneCol);

        $total = 0;
        $invalid = 0;
        $duplicates = 0;
        $seen = [];
        $contacts = [];
        $preview = [];

        foreach ($rows as $row) {
            $rawPhone = trim((string) ($row[$phoneCol] ?? ''));
            if ($rawPhone === '') {
                continue;
            }
            $total++;
            $name = $nameCol !== null ? trim((string) ($row[$nameCol] ?? '')) : null;
            $phone = PhoneNumber::tryFrom($rawPhone);

            if ($phone === null) {
                $invalid++;
                $status = 'invalide';
            } elseif (isset($seen[$phone->canonical])) {
                $duplicates++;
                $status = 'doublon';
            } else {
                $seen[$phone->canonical] = true;
                $contacts[] = ['phone' => $phone->canonical, 'hash' => $this->hasher->hash($phone), 'name' => $name ?: null];
                $status = 'valide';
            }

            if (count($preview) < 6) {
                $preview[] = ['phone' => $rawPhone, 'name' => $name ?: null, 'status' => $status];
            }
        }

        return [
            'total' => $total,
            'valid' => count($contacts),
            'invalid' => $invalid,
            'duplicates' => $duplicates,
            'contacts' => $contacts,
            'preview' => $preview,
        ];
    }

    /** @return list<array<int, mixed>> */
    private function readRows(string $absolutePath): array
    {
        $sheets = Excel::toArray(new class implements ToArray
        {
            public function array(array $rows): array
            {
                return $rows;
            }
        }, $absolutePath);

        return array_values(array_filter($sheets[0] ?? [], fn ($r) => is_array($r) && trim(implode('', array_map('strval', $r))) !== ''));
    }

    /**
     * Si la première ligne ne contient aucun numéro valide, c'est un en-tête.
     *
     * @param  list<array<int, mixed>>  $rows
     * @return list<array<int, mixed>>
     */
    private function stripHeader(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $first = $rows[0];
        $hasPhone = collect($first)->contains(fn ($cell) => PhoneNumber::tryFrom((string) $cell) !== null);

        return $hasPhone ? $rows : array_slice($rows, 1);
    }

    /**
     * Colonne téléphone = celle qui contient le plus de numéros valides sur un échantillon.
     *
     * @param  list<array<int, mixed>>  $rows
     */
    private function detectPhoneColumn(array $rows): int
    {
        $sample = array_slice($rows, 0, 40);
        $scores = [];
        foreach ($sample as $row) {
            foreach (array_values($row) as $col => $cell) {
                if (PhoneNumber::tryFrom((string) $cell) !== null) {
                    $scores[$col] = ($scores[$col] ?? 0) + 1;
                }
            }
        }

        if ($scores === []) {
            return 0;
        }
        arsort($scores);

        return (int) array_key_first($scores);
    }

    /**
     * Colonne nom = première colonne majoritairement textuelle, hors colonne téléphone.
     *
     * @param  list<array<int, mixed>>  $rows
     */
    private function detectNameColumn(array $rows, int $phoneCol): ?int
    {
        $sample = array_slice($rows, 0, 40);
        $textScores = [];
        foreach ($sample as $row) {
            foreach (array_values($row) as $col => $cell) {
                if ($col === $phoneCol) {
                    continue;
                }
                $v = trim((string) $cell);
                if ($v !== '' && preg_match('/\p{L}{2,}/u', $v)) {
                    $textScores[$col] = ($textScores[$col] ?? 0) + 1;
                }
            }
        }
        if ($textScores === []) {
            return null;
        }
        arsort($textScores);

        return (int) array_key_first($textScores);
    }
}
