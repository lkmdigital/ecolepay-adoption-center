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
    | Règle d'adoption
    |---------------------------------------------------------------------------
    |
    | Ces seuils définissent deux des six états de l'entonnoir. Ils alimentent la
    | première ligne de `dim_adoption_rule_versions` ; les modifier ensuite suppose
    | de créer une nouvelle version, jamais d'écraser l'existante.
    |
    | Calibrage : les paiements scolaires sont trimestriels, donc espacés de ~90
    | jours. Un seuil fondé sur les seuls paiements classerait tout le monde « à
    | risque » entre deux trimestres. C'est pourquoi l'activité comptabilisée inclut
    | les consultations et exclut la réception passive de notifications.
    |
    */

    'adoption' => [
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
