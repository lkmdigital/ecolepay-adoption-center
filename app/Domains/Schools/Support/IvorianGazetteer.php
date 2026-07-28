<?php

namespace App\Domains\Schools\Support;

/**
 * Répertoire de localités ivoiriennes (vraies coordonnées) pour DÉDUIRE la
 * localisation d'une école à partir de son nom — les colonnes latitude/longitude
 * et region/city de dim_schools ne sont pas alimentées par la synchro EcolePay.
 *
 * C'est une INFÉRENCE assumée (jamais un GPS réel) : `locate()` cherche un nom de
 * localité dans le nom de l'établissement. Les correspondances les plus longues
 * priment (GRAND-BASSAM avant BASSAM) pour éviter les faux positifs.
 *
 * Coordonnées = repères réels des localités ; la précision est celle de la ville /
 * commune, pas de l'établissement.
 */
final class IvorianGazetteer
{
    /**
     * Localité (motif recherché) => [lat, lng, région, ville affichée].
     * Quartiers d'Abidjan rattachés au district « Abidjan ».
     *
     * @var array<string, array{0: float, 1: float, 2: string, 3: string}>
     */
    private const PLACES = [
        // --- Abidjan (communes & quartiers) ---
        'DEUX PLATEAUX' => [5.36, -3.99, 'Abidjan', 'Cocody'],
        '2 PLATEAUX' => [5.36, -3.99, 'Abidjan', 'Cocody'],
        'II PLATEAUX' => [5.36, -3.99, 'Abidjan', 'Cocody'],
        'RIVIERA' => [5.36, -3.93, 'Abidjan', 'Cocody'],
        'PALMERAIE' => [5.38, -3.95, 'Abidjan', 'Cocody'],
        'ANGRE' => [5.39, -3.96, 'Abidjan', 'Cocody'],
        'AGOUAPI' => [5.37, -3.94, 'Abidjan', 'Cocody'],
        'ABATTA' => [5.35, -3.85, 'Abidjan', 'Bingerville'],
        'ABBATA' => [5.35, -3.85, 'Abidjan', 'Bingerville'],
        'BINGERVILLE' => [5.35, -3.89, 'Abidjan', 'Bingerville'],
        'COCODY' => [5.35, -3.98, 'Abidjan', 'Cocody'],
        'NIANGON' => [5.35, -4.10, 'Abidjan', 'Yopougon'],
        'YOPOUGON' => [5.34, -4.07, 'Abidjan', 'Yopougon'],
        'ABADJIN' => [5.31, -4.18, 'Abidjan', 'Songon'],
        'SONGON' => [5.30, -4.25, 'Abidjan', 'Songon'],
        'ADJAME' => [5.36, -4.02, 'Abidjan', 'Adjamé'],
        'WILLIAMSVILLE' => [5.37, -4.01, 'Abidjan', 'Adjamé'],
        'ATTECOUBE' => [5.34, -4.04, 'Abidjan', 'Attécoubé'],
        'PLATEAU' => [5.32, -4.02, 'Abidjan', 'Plateau'],
        'TREICHVILLE' => [5.29, -4.01, 'Abidjan', 'Treichville'],
        'ZONE 4' => [5.28, -3.99, 'Abidjan', 'Marcory'],
        'BIETRY' => [5.28, -3.99, 'Abidjan', 'Marcory'],
        'MARCORY' => [5.30, -3.98, 'Abidjan', 'Marcory'],
        'KOUMASSI' => [5.29, -3.95, 'Abidjan', 'Koumassi'],
        'GONZAGUEVILLE' => [5.26, -3.90, 'Abidjan', 'Port-Bouët'],
        'PORT-BOUET' => [5.26, -3.93, 'Abidjan', 'Port-Bouët'],
        'PORT BOUET' => [5.26, -3.93, 'Abidjan', 'Port-Bouët'],
        'ABOBO' => [5.42, -4.02, 'Abidjan', 'Abobo'],
        'ANYAMA' => [5.49, -4.05, 'Abidjan', 'Anyama'],
        'ABIDJAN' => [5.35, -4.00, 'Abidjan', 'Abidjan'],

        // --- Sud-Comoé / littoral est ---
        'GRAND-BASSAM' => [5.21, -3.74, 'Sud-Comoé', 'Grand-Bassam'],
        'GRAND BASSAM' => [5.21, -3.74, 'Sud-Comoé', 'Grand-Bassam'],
        'MOOSSOU' => [5.19, -3.73, 'Sud-Comoé', 'Grand-Bassam'],
        'BASSAM' => [5.21, -3.74, 'Sud-Comoé', 'Grand-Bassam'],
        'BONOUA' => [5.27, -3.60, 'Sud-Comoé', 'Bonoua'],
        'ABOISSO' => [5.47, -3.21, 'Sud-Comoé', 'Aboisso'],
        'ADIAKE' => [5.29, -3.30, 'Sud-Comoé', 'Adiaké'],

        // --- Grands-Ponts / Agnéby-Tiassa / La Mé ---
        'GRAND-LAHOU' => [5.14, -5.01, 'Grands-Ponts', 'Grand-Lahou'],
        'GRAND LAHOU' => [5.14, -5.01, 'Grands-Ponts', 'Grand-Lahou'],
        'JACQUEVILLE' => [5.21, -4.41, 'Grands-Ponts', 'Jacqueville'],
        'DABOU' => [5.32, -4.38, 'Grands-Ponts', 'Dabou'],
        'AGBOVILLE' => [5.93, -4.22, 'Agnéby-Tiassa', 'Agboville'],
        'TIASSALE' => [5.90, -4.83, 'Agnéby-Tiassa', 'Tiassalé'],
        'ADZOPE' => [6.11, -3.86, 'La Mé', 'Adzopé'],
        'AKOUPE' => [6.38, -3.89, 'La Mé', 'Akoupé'],
        'ALEPE' => [5.50, -3.66, 'La Mé', 'Alépé'],

        // --- Lacs / Bélier / Bas-Sassandra / Centre ---
        'YAMOUSSOUKRO' => [6.82, -5.28, 'Lacs', 'Yamoussoukro'],
        'TOUMODI' => [6.55, -5.02, 'Bélier', 'Toumodi'],
        'DIMBOKRO' => [6.65, -4.70, 'N\'Zi', 'Dimbokro'],
        'BONGOUANOU' => [6.65, -4.20, 'Moronou', 'Bongouanou'],
        'BOUAKE' => [7.69, -5.03, 'Gbêkê', 'Bouaké'],
        'KATIOLA' => [8.13, -5.10, 'Hambol', 'Katiola'],
        'DABAKALA' => [8.36, -4.43, 'Hambol', 'Dabakala'],

        // --- Ouest / Centre-Ouest ---
        'DALOA' => [6.88, -6.45, 'Haut-Sassandra', 'Daloa'],
        'ISSIA' => [6.49, -6.59, 'Haut-Sassandra', 'Issia'],
        'VAVOUA' => [7.38, -6.48, 'Haut-Sassandra', 'Vavoua'],
        'BOUAFLE' => [6.99, -5.75, 'Marahoué', 'Bouaflé'],
        'SINFRA' => [6.62, -5.92, 'Marahoué', 'Sinfra'],
        'GAGNOA' => [6.13, -5.95, 'Gôh', 'Gagnoa'],
        'OUME' => [6.38, -5.42, 'Gôh', 'Oumé'],
        'DIVO' => [5.84, -5.36, 'Lôh-Djiboua', 'Divo'],
        'LAKOTA' => [5.85, -5.68, 'Lôh-Djiboua', 'Lakota'],

        // --- Sud-Ouest ---
        'SAN-PEDRO' => [4.75, -6.64, 'San-Pédro', 'San-Pédro'],
        'SAN PEDRO' => [4.75, -6.64, 'San-Pédro', 'San-Pédro'],
        'SASSANDRA' => [4.95, -6.08, 'Gbôklé', 'Sassandra'],
        'SOUBRE' => [5.78, -6.60, 'Nawa', 'Soubré'],
        'TABOU' => [4.42, -7.35, 'San-Pédro', 'Tabou'],

        // --- Montagnes (Ouest) ---
        'MAN' => [7.41, -7.55, 'Tonkpi', 'Man'],
        'BIANKOUMA' => [7.74, -7.61, 'Tonkpi', 'Biankouma'],
        'DANANE' => [7.26, -8.16, 'Tonkpi', 'Danané'],
        'DUEKOUE' => [6.74, -7.34, 'Guémon', 'Duékoué'],
        'BANGOLO' => [7.01, -7.49, 'Guémon', 'Bangolo'],
        'GUIGLO' => [6.54, -7.49, 'Cavally', 'Guiglo'],
        'TOULEPLEU' => [6.58, -8.42, 'Cavally', 'Toulepleu'],

        // --- Est ---
        'ABENGOUROU' => [6.73, -3.49, 'Indénié-Djuablin', 'Abengourou'],
        'AGNIBILEKROU' => [7.13, -3.20, 'Indénié-Djuablin', 'Agnibilékrou'],
        'BONDOUKOU' => [8.04, -2.80, 'Gontougo', 'Bondoukou'],
        'TANDA' => [7.80, -3.17, 'Gontougo', 'Tanda'],
        'BOUNA' => [9.27, -3.00, 'Bounkani', 'Bouna'],

        // --- Nord ---
        'KORHOGO' => [9.46, -5.63, 'Poro', 'Korhogo'],
        'FERKESSEDOUGOU' => [9.59, -5.20, 'Tchologo', 'Ferkessédougou'],
        'BOUNDIALI' => [9.52, -6.49, 'Bagoué', 'Boundiali'],
        'ODIENNE' => [9.51, -7.56, 'Kabadougou', 'Odienné'],
        'MINIGNAN' => [10.02, -7.64, 'Folon', 'Minignan'],
        'SEGUELA' => [7.96, -6.67, 'Worodougou', 'Séguéla'],
        'MANKONO' => [8.06, -6.19, 'Béré', 'Mankono'],
    ];

    /**
     * Déduit la localisation d'une école depuis son nom.
     *
     * @return array{lat: float, lng: float, region: string, city: string}|null
     */
    public static function locate(string $schoolName): ?array
    {
        $name = self::normalize($schoolName);

        // Motifs les plus longs d'abord (spécificité maximale).
        $places = self::PLACES;
        uksort($places, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($places as $pattern => [$lat, $lng, $region, $city]) {
            if (str_contains($name, self::normalize($pattern))) {
                return ['lat' => $lat, 'lng' => $lng, 'region' => $region, 'city' => $city];
            }
        }

        return null;
    }

    private static function normalize(string $s): string
    {
        $s = strtr($s, [
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ä' => 'A', 'à' => 'A', 'â' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'é' => 'E', 'è' => 'E', 'ê' => 'E',
            'Ô' => 'O', 'Ö' => 'O', 'ô' => 'O', 'Û' => 'U', 'Ü' => 'U', 'û' => 'U',
            'Ç' => 'C', 'ç' => 'C', 'Î' => 'I', 'Ï' => 'I', 'î' => 'I', 'ï' => 'I',
        ]);

        return strtoupper($s);
    }
}
