<?php

namespace App\Domains\Help\Support;

/**
 * Base de connaissances du Centre d'aide. Le contenu (guides, glossaire, FAQ,
 * documentation technique, nouveautés, académie) est du texte réel décrivant
 * la plateforme EAC telle qu'elle est construite — versionné ici, pas en base.
 *
 * Les messages utilisateurs (support, retours d'article) vont, eux, en base
 * (table help_messages).
 */
final class HelpContent
{
    /** Catégories d'accès rapide. Le nombre d'articles est calculé dynamiquement. */
    public static function categories(): array
    {
        return [
            ['key' => 'premiers-pas', 'label' => 'Premiers pas', 'icon' => 'M10 3l6 3v4c0 3.5-2.6 5.6-6 6.6-3.4-1-6-3.1-6-6.6V6z'],
            ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'M4 4h5v5H4zM11 4h5v5h-5zM4 11h5v5H4zM11 11h5v5h-5z'],
            ['key' => 'ecoles', 'label' => 'Gestion des écoles', 'icon' => 'M10 3l7 4H3zM4 8h12v9H4zM9 12h2v5H9z'],
            ['key' => 'campagnes', 'label' => 'Campagnes', 'icon' => 'M3 7h10l4-3v12l-4-3H3z'],
            ['key' => 'parents', 'label' => 'Parents', 'icon' => 'M7 9a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM3 16c0-2.5 1.8-4 4-4s4 1.5 4 4M13 9.5a2 2 0 100-4M12.5 12c2 0 3.5 1.3 3.5 3.5'],
            ['key' => 'analytics', 'label' => 'Analytics', 'icon' => 'M4 16V4M4 16h12M8 13V9M11 13V6M14 13v-3'],
            ['key' => 'rapports', 'label' => 'Rapports', 'icon' => 'M6 3h8v14H6zM8 7h4M8 10h4M8 13h2'],
            ['key' => 'assistant', 'label' => 'Assistant IA', 'icon' => 'M5 6h10v7H8l-3 3zM8 9h.01M11 9h.01'],
            ['key' => 'parametres', 'label' => 'Paramètres', 'icon' => 'M10 7a3 3 0 100 6 3 3 0 000-6zM10 3v2M10 15v2M3 10h2M15 10h2'],
            ['key' => 'securite', 'label' => 'Sécurité', 'icon' => 'M10 3l6 2v5c0 4-3 6-6 7-3-1-6-3-6-7V5z'],
        ];
    }

    /** Guides pas à pas (articles). `body` = blocs {p|h|steps|note}. */
    public static function guides(): array
    {
        return [
            [
                'key' => 'decouvrir', 'category' => 'premiers-pas', 'title' => "Découvrir l'Adoption Center",
                'level' => 'Débutant', 'minutes' => 5, 'updated' => '2026-07-27',
                'excerpt' => "Ce que mesure la plateforme, et comment naviguer entre les modules.",
                'body' => [
                    ['p' => "L'Adoption Center (EAC) est la plateforme interne de LKM Digital pour mesurer et faire progresser l'adoption d'EcolePay dans les écoles. Ce n'est ni un CRM, ni l'application EcolePay elle-même : c'est un centre de pilotage."],
                    ['h' => 'La question centrale'],
                    ['p' => "Un parent « adopte » EcolePay lorsqu'il effectue son PREMIER paiement via l'app — pas quand il crée un compte. Toute la plateforme est organisée autour de cette définition."],
                    ['h' => 'Se repérer'],
                    ['steps' => [
                        "Dashboard exécutif : la situation d'ensemble et les priorités du jour.",
                        "Écoles : le pilotage établissement par établissement, avec un score de santé.",
                        "Parents, Campagnes, Analytics, Rapports : l'analyse détaillée.",
                        "Assistant IA : posez une question métier, obtenez une réponse chiffrée.",
                    ]],
                    ['note' => "Astuce : épinglez vos écoles et rapports les plus consultés depuis Mon profil › Favoris."],
                ],
            ],
            [
                'key' => 'comprendre-kpi', 'category' => 'dashboard', 'title' => "Comprendre les KPI d'adoption",
                'level' => 'Débutant', 'minutes' => 6, 'updated' => '2026-07-26',
                'excerpt' => "Les trois taux clés et comment les lire sans se tromper.",
                'body' => [
                    ['p' => "L'adoption se lit à travers trois taux successifs, du plus large au plus décisif."],
                    ['steps' => [
                        "Taux d'inscription = parents inscrits / parents connus. Combien ont activé leur compte.",
                        "Taux d'activation = parents adoptants / parents inscrits. Parmi les inscrits, combien ont franchi le premier paiement.",
                        "Taux d'adoption = parents adoptants / parents connus. LE KPI clé : la part de tout le vivier qui paie via l'app.",
                    ]],
                    ['note' => "Un fort taux d'inscription avec un faible taux d'activation signale un blocage au moment du paiement, pas à l'inscription."],
                ],
            ],
            [
                'key' => 'fiche-ecole', 'category' => 'ecoles', 'title' => "Lire une fiche école et son score de santé",
                'level' => 'Intermédiaire', 'minutes' => 7, 'updated' => '2026-07-25',
                'excerpt' => "Interpréter le score de santé et repérer les écoles à accompagner.",
                'body' => [
                    ['p' => "Chaque école affiche un score de santé sur 100, composite de plusieurs critères : adoption, activation/paiements, qualité des données, évolution et activité récente."],
                    ['p' => "Un score bas n'est pas une sanction : c'est une invitation à agir. Comparez le score au volume de parents connus pour prioriser — une grande école à faible taux représente le plus fort potentiel."],
                    ['note' => "Le modèle d'abonnement compte : une école « intégré/bundled » n'a pas de revenu parent à capter, son potentiel de revenu est nul même si l'adoption compte toujours."],
                ],
            ],
            [
                'key' => 'importer-campagne', 'category' => 'campagnes', 'title' => "Importer une opération marketing",
                'level' => 'Intermédiaire', 'minutes' => 8, 'updated' => '2026-07-24',
                'excerpt' => "Mesurer l'impact d'une campagne WhatsApp/SMS par attribution.",
                'body' => [
                    ['p' => "Les campagnes sont exécutées dans Perfect CX. EAC n'envoie pas de messages : il importe la liste des contacts touchés pour mesurer combien sont ensuite devenus adoptants."],
                    ['steps' => [
                        "Depuis Campagnes, créez une opération et importez le fichier de contacts (téléphones).",
                        "Les numéros sont appariés par empreinte sécurisée (aucun numéro en clair n'est stocké).",
                        "La plateforme calcule les premiers paiements survenus après le contact : ce sont les adoptants attribués.",
                    ]],
                    ['note' => "Le coût par adoptant se calcule si vous renseignez le coût de l'opération."],
                ],
            ],
            [
                'key' => 'generer-rapport', 'category' => 'rapports', 'title' => "Générer un rapport",
                'level' => 'Débutant', 'minutes' => 4, 'updated' => '2026-07-23',
                'excerpt' => "Produire un rapport exécutif prêt à partager, ou un modèle sur mesure.",
                'body' => [
                    ['p' => "Le module Rapports assemble automatiquement les chiffres du moment en un document lisible."],
                    ['steps' => [
                        "Choisissez un modèle (exécutif, adoption, école, marketing, financier) et une période.",
                        "Ou lancez le « Rapport Exécutif Intelligent » en un clic pour une synthèse immédiate.",
                        "Exportez ou imprimez : la mise en page est optimisée pour l'impression.",
                    ]],
                ],
            ],
            [
                'key' => 'laboratoire', 'category' => 'analytics', 'title' => "Explorer avec le Laboratoire d'analyses",
                'level' => 'Avancé', 'minutes' => 9, 'updated' => '2026-07-22',
                'excerpt' => "Construire une analyse sans code : dimension × mesure.",
                'body' => [
                    ['p' => "Le Laboratoire permet de croiser une dimension (école, mois, campagne) avec une mesure (adoptants, taux, revenu…) sans écrire de requête."],
                    ['steps' => [
                        "Choisissez une dimension et une mesure, puis un type de visualisation.",
                        "Affinez avec les filtres disponibles.",
                        "Enregistrez l'analyse pour la retrouver — elle apparaîtra dans le Journal d'activité.",
                    ]],
                    ['note' => "Certaines dimensions (région, ville, commercial) ne sont pas encore disponibles : les données correspondantes ne sont pas encore alimentées."],
                ],
            ],
            [
                'key' => 'notifications', 'category' => 'parametres', 'title' => "Configurer les notifications",
                'level' => 'Débutant', 'minutes' => 4, 'updated' => '2026-07-21',
                'excerpt' => "Choisir ce que la plateforme surveille et vous signale.",
                'body' => [
                    ['p' => "Deux niveaux se combinent : les règles globales (Paramètres › Notifications) et vos préférences personnelles (Mon profil › Notifications)."],
                    ['steps' => [
                        "Dans Paramètres, activez la détection et fixez les seuils (chute des premiers paiements, écoles critiques, jalons de revenus).",
                        "Dans Mon profil, choisissez les types, canaux et la fréquence qui vous concernent.",
                    ]],
                    ['note' => "L'envoi par e-mail/SMS nécessite un connecteur de messagerie (à venir). Les alertes restent visibles dans le module Notifications."],
                ],
            ],
        ];
    }

    /** Glossaire métier — définitions réelles, alignées sur les règles de calcul. */
    public static function glossary(): array
    {
        return [
            ['term' => 'Parent connu', 'def' => "Parent présent dans les données EcolePay, rattaché à au moins une école.", 'example' => "Le vivier total : dénominateur du taux d'adoption."],
            ['term' => 'Parent inscrit', 'def' => "Parent qui a activé son compte sur l'application EcolePay.", 'example' => "Il peut consulter ses factures, mais n'a pas forcément payé."],
            ['term' => 'Parent adoptant ⭐', 'def' => "Parent ayant effectué son PREMIER paiement via l'app. C'est la définition de l'adoption.", 'example' => "Créer un compte ne suffit pas : seul le premier paiement fait l'adoptant."],
            ['term' => 'Parent engagé', 'def' => "Parent qui paie de façon récurrente (au moins deux paiements réussis).", 'example' => "Il est revenu payer une seconde fois : l'usage s'installe."],
            ['term' => 'Parent à risque', 'def' => "Adoptant d'une année scolaire qui n'a pas renouvelé dans la fenêtre de paiement.", 'example' => "A payé l'an dernier, rien cette rentrée : à relancer."],
            ['term' => 'Parent perdu', 'def' => "Parent sans paiement via l'app depuis au moins deux années scolaires.", 'example' => "L'usage s'est interrompu durablement."],
            ['term' => "Taux d'inscription", 'def' => "Parents inscrits ÷ parents connus.", 'example' => "1 200 inscrits sur 3 000 connus → 40 %."],
            ['term' => "Taux d'activation", 'def' => "Parents adoptants ÷ parents inscrits.", 'example' => "480 adoptants sur 1 200 inscrits → 40 %."],
            ['term' => "Taux d'adoption", 'def' => "Parents adoptants ÷ parents connus. Le KPI clé de la plateforme.", 'example' => "480 adoptants sur 3 000 connus → 16 %."],
            ['term' => 'Score de santé', 'def' => "Note composite sur 100 combinant adoption, paiements/activation, qualité des données, évolution et activité récente.", 'example' => "🔴 32/100 : école à accompagner en priorité."],
            ['term' => 'Potentiel de croissance', 'def' => "Parents connus pas encore adoptants dans les écoles où le parent paie.", 'example' => "Le réservoir d'adoptants encore à convertir."],
            ['term' => 'Revenu potentiel', 'def' => "Revenu additionnel si les parents restants adoptaient, dans les écoles « parent payant ».", 'example' => "Nul pour une école en abonnement intégré : le parent n'y paie pas."],
        ];
    }

    /** Foire aux questions. */
    public static function faq(): array
    {
        return [
            ['q' => "Pourquoi un parent inscrit n'est-il pas considéré comme adoptant ?", 'a' => "Parce que l'adoption se mesure à l'usage réel, pas à la création de compte. Tant qu'un parent n'a pas effectué son premier paiement via l'app, il reste « inscrit » : il a ouvert la porte, mais n'est pas entré."],
            ['q' => "Pourquoi une école affiche-t-elle un faible taux d'adoption ?", 'a' => "Souvent parce que beaucoup de parents sont connus/inscrits mais peu ont payé via l'app. Comparez taux d'inscription et taux d'activation : si l'inscription est forte et l'activation faible, le blocage est au moment du paiement (habitude, confiance, accompagnement)."],
            ['q' => "Comment fonctionne le Score de santé ?", 'a' => "C'est une note sur 100 qui agrège plusieurs signaux : niveau d'adoption, paiements/activation, qualité des données, évolution récente et activité. Aucun critère isolé ne fait le score : il donne une vue d'ensemble pour prioriser."],
            ['q' => "Comment importer une campagne ?", 'a' => "Depuis le module Campagnes, créez une opération et importez le fichier des contacts touchés. EAC apparie les numéros par empreinte sécurisée et mesure les premiers paiements survenus après le contact."],
            ['q' => "Comment générer un rapport ?", 'a' => "Module Rapports : choisissez un modèle et une période, ou lancez le Rapport Exécutif Intelligent en un clic. Le document est prêt à exporter ou imprimer."],
            ['q' => "Comment interpréter les Analytics ?", 'a' => "Les Analytics montrent les tendances (30 jours glissants), le funnel connu → inscrit → adoptant → engagé, et les comparaisons. Le Laboratoire permet de croiser librement une dimension et une mesure."],
        ];
    }

    /** Documentation technique (réservée aux administrateurs). */
    public static function techDocs(): array
    {
        return [
            ['key' => 'architecture', 'title' => "Architecture de l'application", 'excerpt' => "Laravel, Livewire, Flux UI et une organisation par domaines.",
                'body' => [
                    ['p' => "EAC est bâti sur Laravel avec Livewire (composants monofichiers) et Flux UI, dans une architecture « DDD-light » : chaque domaine métier (Schools, Parents, Campaigns, Analytics, Reports…) regroupe ses modèles, actions et vues."],
                    ['p' => "Les routes sont déclarées par domaine sous routes/domains/ et agrégées automatiquement ; les composants Livewire sont exposés par namespace."],
                ]],
            ['key' => 'donnees', 'title' => "Structure des données", 'excerpt' => "Un entrepôt en étoile : dimensions et faits.",
                'body' => [
                    ['p' => "Les données suivent un schéma en étoile : des tables de dimension (dim_schools, dim_parents, dim_students…) et des tables de faits (fact_payments, fact_parent_journeys…)."],
                    ['p' => "Les dimensions historisées suivent une logique SCD2 (is_current, valid_from/valid_to) pour conserver l'historique des changements."],
                ]],
            ['key' => 'sync', 'title' => "Synchronisation avec EcolePay", 'excerpt' => "Reprises glissantes plutôt qu'incréments stricts.",
                'body' => [
                    ['p' => "Les données arrivant parfois en retard, chaque agrégat recalcule une fenêtre glissante des derniers jours (paiements, activités, campagnes) plutôt qu'un incrément strict."],
                    ['note' => "Certaines dates (première connaissance, onboarding) sont des artefacts de synchronisation et ne doivent pas être lues comme des dates métier exactes."],
                ]],
            ['key' => 'identite', 'title' => "Identité téléphone (empreinte HMAC)", 'excerpt' => "Apparier les contacts sans stocker de numéro en clair.",
                'body' => [
                    ['p' => "Pour mesurer l'attribution des campagnes sans conserver de numéros, les téléphones sont normalisés puis transformés en empreinte HMAC avec un secret. L'empreinte est incassable par force brute et non réversible."],
                ]],
            ['key' => 'roles', 'title' => "Gestion des rôles", 'excerpt' => "Sept rôles, une matrice de permissions unique.",
                'body' => [
                    ['p' => "Sept rôles sont prévus (Super Admin, Développeur, Direction, Marketing, Commercial, Support, Analyste). La matrice rôle → permissions est définie en un seul endroit et reportée en base par un seeder."],
                    ['note' => "L'authentification (connexion, sessions) n'est pas encore posée : les rôles existent mais ne filtrent pas encore l'accès."],
                ]],
            ['key' => 'test-data', 'title' => "Isolation des données de test", 'excerpt' => "Séparer nettement démonstration et production.",
                'body' => [
                    ['p' => "Les enregistrements de démonstration portent un indicateur is_test et sont exclus par défaut des mesures via un filtre commun, pour ne jamais mêler données factices et données réelles."],
                ]],
        ];
    }

    /** Nouveautés — chronologie des modules réellement livrés. */
    public static function changelog(): array
    {
        return [
            ['date' => '2026-07-27', 'type' => 'feature', 'title' => "Centre d'aide", 'desc' => "Base de connaissances : guides, glossaire, FAQ, documentation et académie."],
            ['date' => '2026-07-27', 'type' => 'feature', 'title' => "Profil utilisateur", 'desc' => "Espace personnel : identité, préférences, favoris, activité, confidentialité."],
            ['date' => '2026-07-27', 'type' => 'feature', 'title' => "Paramètres", 'desc' => "Centre de configuration : règles métier, seuils KPI, intégrations, maintenance."],
            ['date' => '2026-07-27', 'type' => 'feature', 'title' => "Journal d'activité", 'desc' => "Deux journaux : événements métier détectés et traces techniques."],
            ['date' => '2026-07-26', 'type' => 'feature', 'title' => "Notifications & alertes", 'desc' => "Détection automatique des anomalies (écoles critiques, chutes, jalons)."],
            ['date' => '2026-07-26', 'type' => 'feature', 'title' => "Rapports", 'desc' => "Centre de rapports et Rapport Exécutif Intelligent en un clic."],
            ['date' => '2026-07-25', 'type' => 'amelioration', 'title' => "Laboratoire d'analyses", 'desc' => "Constructeur d'analyses sans code : dimension × mesure."],
            ['date' => '2026-07-24', 'type' => 'amelioration', 'title' => "Vocabulaire d'adoption", 'desc' => "« Parents adoptants » et « engagés » remplacent « parents actifs » partout."],
        ];
    }

    /** Académie EcolePay — parcours de formation par profil. */
    public static function academy(): array
    {
        return [
            ['role' => 'Direction', 'color' => '#2554C7', 'desc' => "Interpréter les KPI et piloter l'adoption.",
                'lessons' => ["Lire le Dashboard exécutif", "Les trois taux d'adoption", "Prioriser les écoles par potentiel", "Le Rapport Exécutif Intelligent"]],
            ['role' => 'Marketing', 'color' => '#7C3AED', 'desc' => "Optimiser les campagnes et analyser les conversions.",
                'lessons' => ["Importer une opération marketing", "Mesurer l'attribution", "Coût par adoptant", "Comparer les canaux"]],
            ['role' => 'Commercial', 'color' => '#189B57', 'desc' => "Identifier les écoles prioritaires et préparer les rendez-vous.",
                'lessons' => ["Lire une fiche école", "Le score de santé", "Repérer les écoles critiques", "Le diagnostic d'adoption"]],
            ['role' => 'Support', 'color' => '#D97706', 'desc' => "Accompagner les établissements et les parents.",
                'lessons' => ["Rechercher un parent", "Comprendre le parcours d'adoption", "Statuts à risque et perdu"]],
            ['role' => 'Administrateurs', 'color' => '#5B6472', 'desc' => "Gérer les paramètres, les utilisateurs et la sécurité.",
                'lessons' => ["Configurer les règles métier", "Gérer les rôles", "Intégrations et maintenance", "Isolation des données de test"]],
        ];
    }

    /** Sujets des tutoriels vidéo (les vidéos elles-mêmes sont à venir). */
    public static function videoTopics(): array
    {
        return [
            ['title' => "Visite guidée de l'Adoption Center", 'level' => 'Débutant', 'minutes' => 6],
            ['title' => "Comprendre les KPI d'adoption", 'level' => 'Débutant', 'minutes' => 8],
            ['title' => "Importer et mesurer une campagne", 'level' => 'Intermédiaire', 'minutes' => 10],
            ['title' => "Construire une analyse au Laboratoire", 'level' => 'Avancé', 'minutes' => 12],
        ];
    }

    /** Types de demande de support. */
    public static function supportTypes(): array
    {
        return ['probleme' => 'Signaler un problème', 'question' => 'Poser une question', 'suggestion' => 'Suggérer une amélioration', 'donnees' => 'Signaler une donnée incorrecte'];
    }

    /** Tous les articles consultables (guides + doc technique) indexés par clé. */
    public static function articles(): array
    {
        $all = [];
        foreach (self::guides() as $g) {
            $all[$g['key']] = $g + ['kind' => 'guide'];
        }
        foreach (self::techDocs() as $d) {
            $all[$d['key']] = $d + ['kind' => 'doc', 'category' => 'securite', 'level' => 'Admin', 'minutes' => 5, 'updated' => '2026-07-27'];
        }

        return $all;
    }
}
