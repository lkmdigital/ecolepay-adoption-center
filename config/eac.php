<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Périmètre géographique
    |---------------------------------------------------------------------------
    */

    'country_code' => env('EAC_COUNTRY', 'CI'),
    'currency' => env('EAC_CURRENCY', 'XOF'),
    'timezone' => env('EAC_TIMEZONE', 'Africa/Abidjan'),

    /*
    |---------------------------------------------------------------------------
    | Confidentialité
    |---------------------------------------------------------------------------
    |
    | Clé HMAC des téléphones parents. Un simple hachage serait cassable par force
    | brute (un numéro tient dans moins d'un milliard de valeurs) : le secret rend
    | l'empreinte incassable. Il est NON rotatif sans recalcul complet de la colonne
    | phone_hash — le fixer une fois pour toutes.
    |
    */

    'phone_hash_key' => env('EAC_PHONE_HASH_KEY', ''),

    /*
    |---------------------------------------------------------------------------
    | Règle d'adoption
    |---------------------------------------------------------------------------
    |
    | EcolePay est une app de paiement SAISONNIÈRE, sans autre fonctionnalité pour
    | l'instant : un parent qui a payé n'a plus rien à y faire jusqu'à l'an prochain.
    | L'inactivité en jours n'est donc PAS un signal de décrochage. Le statut vivant
    | se calcule par ANNÉE SCOLAIRE : a-t-il payé via l'app cette année ?
    |
    |   - écart 0 (payé cette année scolaire)              → engagé (ou adoptant si 1re année)
    |   - écart 1, dans la fenêtre de paiement (sept→janv) → engagé (tolérance)
    |   - écart 1, fenêtre fermée                          → à risque
    |   - écart >= 2 années                                → perdu
    |
    | Versionné dans `dim_adoption_rule_versions` : re-réglable plus tard (ex. quand
    | les notes/devoirs ajouteront un signal d'usage hors paiement).
    |
    */

    'adoption' => [
        // Frontière d'année scolaire (rentrée nationale ivoirienne).
        'school_year_start_month' => env('EAC_SCHOOL_YEAR_START_MONTH', 9),
        // Fin de la fenêtre de tolérance de renouvellement (janvier).
        'payment_window_end_month' => env('EAC_PAYMENT_WINDOW_END_MONTH', 1),

        // Anciens seuils en jours — conservés pour compatibilité, non utilisés par
        // le calcul par année scolaire.
        'at_risk_after_days' => env('EAC_AT_RISK_DAYS', 60),
        'lost_after_days' => env('EAC_LOST_DAYS', 120),
        'engaged_min_payments' => env('EAC_ENGAGED_MIN_PAYMENTS', 2),

        // Codes de `dim_event_types` entrant dans le calcul d'inactivité.
        'qualifying_events' => [
            'login',
            'view_invoice',
            'download_receipt',
            'open_notification',
            'payment_initiated',
            'payment_completed',
            'contact_support',
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Fenêtres de reprise
    |---------------------------------------------------------------------------
    |
    | Aucun traitement n'est strictement incrémental : les données arrivent en
    | retard. Chaque agrégat recalcule ces N derniers jours.
    |
    */

    'restatement_days' => [
        'payments' => 7,
        'activities' => 7,
        'campaigns' => 30,
    ],

    /*
    |---------------------------------------------------------------------------
    | Calendrier scolaire
    |---------------------------------------------------------------------------
    |
    | Alimente `dim_dates`. Sans ces repères, les comparaisons « ce trimestre
    | contre le précédent » deviennent des requêtes écrites à la main.
    |
    */

    'calendar' => [
        'start_year' => env('EAC_CALENDAR_START', 2023),
        'end_year' => env('EAC_CALENDAR_END', 2035),

        // Début de l'année scolaire : tout ce qui précède appartient à l'année
        // scolaire précédente.
        'school_year_start' => ['month' => 9, 'day' => 15],

        // Trimestres, en jour de l'année scolaire. `end` exclu.
        'terms' => [
            1 => ['from' => ['month' => 9, 'day' => 15], 'to' => ['month' => 12, 'day' => 20], 'label' => 'Premier trimestre'],
            2 => ['from' => ['month' => 1, 'day' => 5], 'to' => ['month' => 3, 'day' => 31], 'label' => 'Deuxième trimestre'],
            3 => ['from' => ['month' => 4, 'day' => 8], 'to' => ['month' => 7, 'day' => 10], 'label' => 'Troisième trimestre'],
        ],

        'enrollment_period' => ['from' => ['month' => 8, 'day' => 1], 'to' => ['month' => 9, 'day' => 30]],

        // Échéances de paiement : début de chaque trimestre.
        'payment_periods' => [
            ['from' => ['month' => 9, 'day' => 15], 'to' => ['month' => 10, 'day' => 15]],
            ['from' => ['month' => 1, 'day' => 5], 'to' => ['month' => 2, 'day' => 5]],
            ['from' => ['month' => 4, 'day' => 8], 'to' => ['month' => 5, 'day' => 8]],
        ],

        // Jours fériés à date fixe.
        'fixed_holidays' => [
            '01-01' => 'Nouvel An',
            '05-01' => 'Fête du Travail',
            '08-07' => "Fête de l'Indépendance",
            '08-15' => 'Assomption',
            '11-01' => 'Toussaint',
            '11-15' => 'Journée nationale de la paix',
            '12-25' => 'Noël',
        ],

        // Fêtes chrétiennes mobiles, calculées depuis Pâques.
        'easter_offsets' => [
            1 => 'Lundi de Pâques',
            39 => 'Ascension',
            50 => 'Lundi de Pentecôte',
        ],

        // Fêtes musulmanes : calendrier lunaire, non calculable arithmétiquement.
        // À renseigner par année, au format 'AAAA-MM-JJ' => 'libellé'.
        'lunar_holidays' => [
            // '2026-03-20' => 'Aïd el-Fitr',
        ],
    ],

];
