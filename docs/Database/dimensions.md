# Dictionnaire des dimensions

> Spécification de conception. Cible MySQL 8+.

## Périmètre de ce document

**Dimensions cœur** — détaillées ici colonne par colonne :
`calendar`, `schools`, `parents`, `students`, `campaigns`, `users`.

**Dimensions de référence** — petites tables stables, spécifiées en fin de document :
`adoption_stages`, `adoption_rule_versions`, `channels`, `payment_methods`,
`event_types`.

**Table de liaison** — `student_parents`, structurellement nécessaire, ni dimension
ni fait.

### Note de nommage

Ce document emploie les noms courts demandés (`schools`, `calendar`…). Les tables
physiques portent le préfixe `dim_` (`dim_schools`, `dim_dates`), pour une raison
que le présent périmètre illustre : **`users` existe déjà** comme table
d'authentification Laravel. Sans préfixe, rien ne distingue une table d'entrepôt
d'une table applicative, et la collision devient inévitable dès qu'un nom métier est
partagé.

`calendar` correspond à la table physique `dim_dates`.

---

## Conventions communes

**Clés de substitution dimensionnées à l'usage.** Une clé de dimension est recopiée
dans chaque ligne de fait. Les dimensions de référence sont donc en `TINYINT` ou
`SMALLINT`, les dimensions à forte cardinalité en `BIGINT UNSIGNED`.

**Ligne « inconnu ».** Chaque dimension reçoit une ligne « Inconnu ». Un fait dont la
dimension est absente pointe vers elle au lieu de porter un `NULL` : sans cela, toute
jointure interne fait disparaître les lignes concernées et le chiffre affiché devient
faux **et** silencieux.

> MySQL n'autorise pas l'insertion d'un auto-incrément à `0` sans modifier
> `sql_mode`. Cette ligne sera donc créée comme première ligne ordinaire (`id = 1`)
> par les seeders, et référencée par une constante applicative.

**Colonnes de traçabilité.** Toute dimension synchronisée porte `source_created_at`,
`source_updated_at`, `synced_at` et `row_hash` (`BINARY(32)`, empreinte des attributs
suivis). Le `row_hash` évite de comparer vingt colonnes à chaque synchronisation.

**Indicateur `is_test`.** Les dimensions peuplées en développement le portent. La
permission `diagnostics.manage` sert à manipuler ces lignes ; un filtre vaut mieux
qu'une suppression.

**Trois contraintes MySQL qui façonnent la conception :**

1. **Pas d'index unique partiel.** La règle « une seule ligne courante par entité »
   ne s'écrit pas directement. Parade : stocker l'indicateur courant en `1` / `NULL`
   plutôt qu'en `1` / `0`, MySQL n'appliquant pas l'unicité aux `NULL`.
2. **Collation par défaut insensible à la casse et aux accents.** La base est en
   `utf8mb4_0900_ai_ci` : `ECOLE` et `école` y sont identiques. Acceptable pour un
   nom, inacceptable pour un code technique, qui doit être déclaré en collation
   binaire.
3. **Pas de contrainte d'exclusion.** Le non-chevauchement des périodes de validité
   relève de la couche applicative.

---

# Dimensions cœur

## 1. `calendar`

*Table physique : `dim_dates`.*

### Objectif métier

Rendre toute question temporelle exprimable sans calcul de dates, y compris dans le
référentiel scolaire — qui ne coïncide pas avec le calendrier civil.

### Colonnes

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `INT UNSIGNED` | non | Clé au format `AAAAMMJJ` |
| `full_date` | `DATE` | non | La date elle-même |
| `day_of_month` | `TINYINT UNSIGNED` | non | 1–31 |
| `day_of_week` | `TINYINT UNSIGNED` | non | 1 = lundi … 7 = dimanche (ISO 8601) |
| `day_name` | `VARCHAR(10)` | non | Libellé français |
| `day_of_year` | `SMALLINT UNSIGNED` | non | 1–366 |
| `week_of_year` | `TINYINT UNSIGNED` | non | Semaine ISO, 1–53 |
| `iso_year` | `SMALLINT UNSIGNED` | non | Année ISO de la semaine |
| `month_number` | `TINYINT UNSIGNED` | non | 1–12 |
| `month_name` | `VARCHAR(10)` | non | Libellé français |
| `quarter` | `TINYINT UNSIGNED` | non | Trimestre civil, 1–4 |
| `year` | `SMALLINT UNSIGNED` | non | Année civile |
| `first_day_of_month` | `DATE` | non | Confort de regroupement |
| `last_day_of_month` | `DATE` | non | Confort de regroupement |
| `is_weekend` | `BOOLEAN` | non | |
| `is_public_holiday` | `BOOLEAN` | non | |
| `holiday_name` | `VARCHAR(60)` | oui | |
| `school_year_label` | `CHAR(9)` | non | Ex. `2025-2026` |
| `school_year_start` | `SMALLINT UNSIGNED` | non | Année de rentrée, pour trier |
| `school_term` | `TINYINT UNSIGNED` | oui | Trimestre scolaire 1–3 |
| `school_term_label` | `VARCHAR(24)` | oui | |
| `is_school_day` | `BOOLEAN` | non | |
| `is_school_holiday` | `BOOLEAN` | non | |
| `is_enrollment_period` | `BOOLEAN` | non | Période de rentrée |
| `is_payment_period` | `BOOLEAN` | non | Échéance de paiement scolaire |

### Clé primaire

`id`, au format `AAAAMMJJ`.

### Clés étrangères

Aucune. Dimension racine.

### Contraintes

- Unicité de `full_date`.
- Domaines vérifiables : `month_number` 1–12, `quarter` 1–4, `day_of_week` 1–7,
  `school_term` 1–3.
- Table pré-remplie, en lecture seule ensuite.

### Index recommandés

| Index | Usage |
|---|---|
| `full_date` (unique) | Résolution depuis une date applicative |
| (`year`, `month_number`) | Agrégations mensuelles |
| (`school_year_label`, `school_term`) | Comparaisons par trimestre scolaire |
| (`iso_year`, `week_of_year`) | Cohortes hebdomadaires |

### Relations

Référencée par **toutes** les tables de faits, souvent plusieurs fois depuis la même
table. `fact_adoption_journey` la référence sept fois — une par jalon du parcours.

### Justification des choix

**La clé `AAAAMMJJ` plutôt qu'un auto-incrément.** Elle est lisible sans jointure,
triable, et permet de partitionner les tables de faits volumineuses par plage de
dates sans colonne supplémentaire. C'est une rupture assumée avec la convention
Laravel, justifiée par un usage exclusivement analytique.

**`iso_year` distinct de `year`.** La semaine 1 de 2026 commence fin décembre 2025.
Regrouper par (`year`, `week_of_year`) produit des cohortes hebdomadaires fausses au
passage d'année — erreur silencieuse et difficile à repérer.

**Le calendrier scolaire en dimension.** Les paiements scolaires sont massivement
saisonniers. Sans année et trimestre scolaires stockés, chaque comparaison « ce
trimestre contre le précédent » devient une requête écrite à la main, et les
comparaisons annuelles glissent d'un mois.

**Aucune colonne relative au présent.** Pas de « jours écoulés » ni de « est
aujourd'hui » : ces colonnes exigeraient une réécriture nocturne de la table et se
désynchroniseraient dès qu'un traitement serait manqué.

**Volume négligeable** : environ 3 650 lignes pour dix ans.

---

## 2. `schools`

*Table physique : `dim_schools`. Historisation de type 2.*

### Objectif métier

Décrire l'établissement, permettre les regroupements géographiques et commerciaux, et
conserver l'historique de ses caractéristiques contractuelles.

### Colonnes

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `BIGINT UNSIGNED` | non | Clé de substitution |
| `source_school_id` | `VARCHAR(64)` | non | Identifiant EcolePay |
| `school_code` | `VARCHAR(32)` | oui | Code lisible, collation binaire |
| `name` | `VARCHAR(180)` | non | |
| `legal_name` | `VARCHAR(180)` | oui | Raison sociale |
| `school_type` | `VARCHAR(30)` | non | Public, privé, confessionnel, international |
| `has_preschool` | `BOOLEAN` | non | Maternelle |
| `has_primary` | `BOOLEAN` | non | Primaire |
| `has_secondary` | `BOOLEAN` | non | Collège / lycée |
| `country_code` | `CHAR(2)` | non | ISO 3166-1 |
| `region` | `VARCHAR(80)` | oui | |
| `city` | `VARCHAR(80)` | oui | |
| `district` | `VARCHAR(80)` | oui | Commune ou quartier |
| `latitude` | `DECIMAL(10,7)` | oui | |
| `longitude` | `DECIMAL(10,7)` | oui | |
| `student_count` | `INT UNSIGNED` | oui | Effectif déclaré |
| `size_band` | `VARCHAR(20)` | oui | Tranche dérivée, pour regrouper |
| `onboarded_at` | `DATE` | oui | Mise en service EcolePay |
| `contract_tier` | `VARCHAR(40)` | oui | Formule contractuelle |
| `status` | `VARCHAR(20)` | non | Prospect, active, suspendue, partie |
| `account_manager_user_id` | `BIGINT UNSIGNED` | oui | Commercial référent |
| `is_test` | `BOOLEAN` | non | |
| `is_current` | `BOOLEAN` | oui | `1` ou `NULL` |
| `valid_from` | `DATETIME` | non | Début de validité de la version |
| `valid_to` | `DATETIME` | oui | `NULL` si version courante |
| `version` | `INT UNSIGNED` | non | Numéro de version, incrémental |
| `row_hash` | `BINARY(32)` | non | Empreinte des attributs suivis |
| `source_created_at` | `DATETIME` | oui | |
| `source_updated_at` | `DATETIME` | oui | |
| `synced_at` | `DATETIME` | non | |

### Clé primaire

`id`.

### Clés étrangères

| Colonne | Cible | Comportement | Motif |
|---|---|---|---|
| `account_manager_user_id` | `users.id` | mise à `NULL` | Un commercial qui quitte l'entreprise ne doit pas entraîner la perte de ses écoles |

### Contraintes

- Unicité (`source_school_id`, `valid_from`) — une version par date de début.
- Unicité (`source_school_id`, `is_current`) — grâce au `NULL`, garantit **une seule
  ligne courante par école**.
- Domaines : latitude dans [-90, 90], longitude dans [-180, 180], `valid_to`
  postérieure à `valid_from`, `student_count` positif.

### Index recommandés

| Index | Usage |
|---|---|
| (`status`, `region`) | Tableaux de bord régionaux |
| (`region`, `city`) | Filtres géographiques |
| (`account_manager_user_id`) | Portefeuille d'un commercial |
| (`source_school_id`) | Résolution lors de la synchronisation |
| (`is_test`) | Exclusion des données de développement |

### Relations

- **Vers** `users` — commercial référent.
- **Depuis** `students` — une école a plusieurs élèves.
- **Depuis** toutes les tables de faits.

### Justification des choix

**Historisation de type 2.** Une école qui change de formule ou de commercial ne doit
pas réécrire son passé : un KPI de mars doit rester rattaché à la formule de mars.

**Le commercial référent est une colonne, pas une table de liaison.** C'est ce champ
qui permettra de restreindre le rôle Commercial à son portefeuille. Sans lui,
`schools.view` reste une permission binaire — tout ou rien.

**Trois booléens plutôt qu'une liste de niveaux.** Une école peut cumuler maternelle,
primaire et secondaire. Trois indicateurs s'indexent et se filtrent ; un type
ensembliste MySQL ne s'indexe pas utilement.

**Piège de l'historisation.** Les faits pointent vers une version précise, donc vers
un `id` figé. Analyser « les écoles telles qu'elles sont aujourd'hui » suppose de
joindre sur la ligne courante via `source_school_id`, **pas** sur la clé portée par
le fait. C'est la principale source d'erreur d'un schéma historisé.

---

## 3. `parents`

*Table physique : `dim_parents`. Historisation de type 1.*

### Objectif métier

Décrire la personne, **indépendamment de l'existence d'un compte EcolePay**. C'est
cette dimension qui rend le premier étage de l'entonnoir mesurable.

### Colonnes

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `BIGINT UNSIGNED` | non | Clé de substitution |
| `source_parent_id` | `VARCHAR(64)` | **oui** | Identifiant EcolePay — `NULL` tant qu'aucun compte |
| `phone_hash` | `BINARY(32)` | non | Empreinte **à clé** du numéro normalisé |
| `phone_e164` | `VARCHAR(20)` | oui | Numéro en clair — voir décision ouverte |
| `phone_country` | `CHAR(2)` | oui | |
| `full_name` | `VARCHAR(160)` | oui | |
| `email` | `VARCHAR(180)` | oui | |
| `preferred_language` | `CHAR(5)` | oui | `fr`, `en` |
| `preferred_channel_id` | `TINYINT UNSIGNED` | oui | Canal de contact préféré |
| `first_known_at` | `DATETIME` | non | Première apparition dans une liste d'école |
| `account_created_at` | `DATETIME` | **oui** | `NULL` si jamais inscrit |
| `last_platform` | `VARCHAR(20)` | oui | Android, iOS, web |
| `last_app_version` | `VARCHAR(20)` | oui | |
| `marketing_consent` | `BOOLEAN` | non | |
| `marketing_consent_at` | `DATETIME` | oui | |
| `is_pseudonymized` | `BOOLEAN` | non | Suite à une demande d'effacement |
| `pseudonymized_at` | `DATETIME` | oui | |
| `retention_until` | `DATE` | oui | Échéance de conservation |
| `is_test` | `BOOLEAN` | non | |
| `row_hash` | `BINARY(32)` | non | |
| `source_created_at` | `DATETIME` | oui | |
| `source_updated_at` | `DATETIME` | oui | |
| `synced_at` | `DATETIME` | non | |

### Clé primaire

`id`.

### Clés étrangères

| Colonne | Cible | Comportement |
|---|---|---|
| `preferred_channel_id` | `channels.id` | mise à `NULL` |

### Contraintes

- Unicité de `phone_hash` — la véritable clé d'identité.
- Unicité de `source_parent_id`, dont les `NULL` multiples sont **voulus** : plusieurs
  parents connus coexistent sans compte EcolePay.
- `pseudonymized_at` renseignée si et seulement si `is_pseudonymized` est vrai.

### Index recommandés

| Index | Usage |
|---|---|
| `phone_hash` (unique) | Rapprochement lors de la synchronisation |
| (`account_created_at`) | Cohortes d'inscription |
| (`marketing_consent`) | Ciblage de campagne |
| (`retention_until`) | Purges réglementaires |
| (`is_test`) | Exclusion des données de développement |

### Relations

- **Vers** `channels` — canal préféré.
- **Depuis** `student_parents` — liaison vers les élèves.
- **Depuis** `fact_adoption_journey`, `fact_stage_transition`, `fact_payment`,
  `fact_parent_activity`, `fact_campaign_delivery`, `ai_prediction`.

### Justification des choix

**`source_parent_id` nullable est le cœur du dispositif.** Un « parent connu » est un
numéro figurant dans la liste d'une école, sans compte EcolePay. Peupler cette
dimension seulement à l'inscription supprimerait le premier étage de l'entonnoir et
fausserait tous les taux de conversion **par le dénominateur** — l'erreur la plus
coûteuse possible, car le chiffre reste plausible.

**L'empreinte du numéro doit être à clé, jamais un hachage nu.** Un numéro de
téléphone appartient à un espace de moins d'un milliard de valeurs : un SHA-256 nu se
casse par force brute en quelques minutes, ce qui annulerait tout l'intérêt de la
pseudonymisation. Le secret applicatif devient alors non rotatif sans recalcul complet
de la colonne — à décider avant la mise en production.

**L'effacement se fait par pseudonymisation, pas par suppression.** Les faits
référencent cette clé ; supprimer la ligne détruirait des historiques agrégés et
casserait des rapports déjà diffusés. On vide les attributs identifiants, on conserve
la clé et les mesures.

**Type 1 et non type 2.** Les changements de canal préféré ou de version applicative
n'ont pas de valeur analytique rétroactive. Historiser cette dimension multiplierait
son volume sans bénéfice.

---

## 4. `students`

*Table physique : `dim_students`.*

### Objectif métier

Relier le parent à l'école et permettre l'analyse par niveau d'enseignement. C'est
l'élève qui porte la relation, jamais un lien direct parent-école.

### Colonnes

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `BIGINT UNSIGNED` | non | Clé de substitution |
| `source_student_id` | `VARCHAR(64)` | non | Identifiant EcolePay |
| `school_id` | `BIGINT UNSIGNED` | non | École de rattachement |
| `display_reference` | `VARCHAR(60)` | oui | Matricule ou libellé technique |
| `education_level` | `VARCHAR(40)` | oui | CP, CE1, 6e, Terminale… |
| `level_rank` | `TINYINT UNSIGNED` | oui | Rang ordinal du niveau |
| `class_label` | `VARCHAR(40)` | oui | Ex. `6e B` |
| `school_year_label` | `CHAR(9)` | non | Ex. `2025-2026` |
| `enrollment_status` | `VARCHAR(20)` | non | Inscrit, parti, diplômé |
| `enrolled_at` | `DATE` | oui | |
| `left_at` | `DATE` | oui | |
| `is_test` | `BOOLEAN` | non | |
| `row_hash` | `BINARY(32)` | non | |
| `source_created_at` | `DATETIME` | oui | |
| `source_updated_at` | `DATETIME` | oui | |
| `synced_at` | `DATETIME` | non | |

### Clé primaire

`id`.

### Clés étrangères

| Colonne | Cible | Comportement | Motif |
|---|---|---|---|
| `school_id` | `schools.id` | suppression interdite | Un élève sans école n'a pas de sens ; la suppression doit être traitée explicitement |

### Contraintes

- Unicité (`source_student_id`, `school_year_label`) — un élève réinscrit l'année
  suivante est une **nouvelle ligne**.
- `left_at` postérieure à `enrolled_at`.

### Index recommandés

| Index | Usage |
|---|---|
| (`school_id`, `school_year_label`) | Effectifs d'une école sur une année |
| (`education_level`) | Analyse par niveau |
| (`level_rank`) | Tri et comparaison inter-établissements |
| (`enrollment_status`) | Exclusion des élèves partis |

### Relations

- **Vers** `schools`.
- **Depuis** `student_parents` — liaison vers les parents.
- **Depuis** `fact_payment` — un paiement concerne un élève précis.

### Justification des choix

**Une ligne par élève et par année scolaire.** Cela permet de suivre la progression
d'un élève et de conserver l'effectif exact d'une année passée, sans écraser
l'historique à chaque rentrée.

**`level_rank` n'est pas redondant avec `education_level`.** Les nomenclatures ne se
trient pas alphabétiquement : « CM2 » précède « 6e », « Terminale » suit « 1re ». Un
rang ordinal explicite est la seule façon de comparer des cohortes entre
établissements aux nomenclatures différentes.

**Aucun nom d'enfant.** Ces données concernent des mineurs et n'ont aucune valeur
analytique : les analyses portent sur le niveau, l'école et le parent payeur.
`display_reference` reste une référence technique.

**Suppression interdite plutôt que cascade.** Supprimer une école ne doit pas effacer
silencieusement ses élèves, et donc l'historique de ses paiements.

---

## 5. `campaigns`

*Table physique : `dim_campaigns`. Lignée native : créée dans EAC, non synchronisée.*

### Objectif métier

Décrire une campagne d'activation, son ciblage, son coût et sa règle d'attribution.

### Colonnes

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `BIGINT UNSIGNED` | non | Clé de substitution |
| `uuid` | `CHAR(36)` | non | Identifiant public, exposé en URL |
| `name` | `VARCHAR(160)` | non | |
| `slug` | `VARCHAR(180)` | non | |
| `objective` | `VARCHAR(40)` | non | Activation, réactivation, conversion, information |
| `target_stage_id` | `TINYINT UNSIGNED` | oui | État d'adoption visé |
| `channel_id` | `TINYINT UNSIGNED` | non | Canal d'envoi |
| `target_segment` | `JSON` | non | Définition du filtre de ciblage |
| `message_template` | `TEXT` | oui | Contenu ou référence du modèle |
| `status` | `VARCHAR(20)` | non | Brouillon, planifiée, en cours, envoyée, annulée |
| `scheduled_at` | `DATETIME` | oui | |
| `started_at` | `DATETIME` | oui | |
| `completed_at` | `DATETIME` | oui | |
| `recipient_count` | `INT UNSIGNED` | oui | Effectif ciblé, **figé au lancement** |
| `estimated_cost` | `DECIMAL(12,2)` | oui | |
| `actual_cost` | `DECIMAL(12,2)` | oui | Consolidé depuis les faits de remise |
| `currency` | `CHAR(3)` | non | ISO 4217 |
| `attribution_window_days` | `SMALLINT UNSIGNED` | non | Fenêtre d'attribution propre à la campagne |
| `created_by_user_id` | `BIGINT UNSIGNED` | oui | Auteur |
| `approved_by_user_id` | `BIGINT UNSIGNED` | oui | Validateur |
| `created_at` | `DATETIME` | non | |
| `updated_at` | `DATETIME` | non | |
| `deleted_at` | `DATETIME` | oui | Suppression logique |

### Clé primaire

`id`.

### Clés étrangères

| Colonne | Cible | Comportement | Motif |
|---|---|---|---|
| `channel_id` | `channels.id` | suppression interdite | Un canal utilisé ne se supprime pas |
| `target_stage_id` | `adoption_stages.id` | mise à `NULL` | Ciblage indicatif |
| `created_by_user_id` | `users.id` | **à revoir** | Voir justification |
| `approved_by_user_id` | `users.id` | **à revoir** | Voir justification |

### Contraintes

- Unicité de `uuid` et de `slug`.
- `completed_at` postérieure à `started_at`.
- Coûts positifs.

### Index recommandés

| Index | Usage |
|---|---|
| (`status`, `scheduled_at`) | File des campagnes à envoyer |
| (`objective`) | Comparaison par type de campagne |
| (`channel_id`) | Analyse par canal |
| (`created_by_user_id`) | Activité d'un utilisateur |
| (`deleted_at`) | Exclusion des campagnes supprimées |

### Relations

- **Vers** `channels`, `adoption_stages`, `users` (deux fois).
- **Depuis** `fact_campaign_delivery` et `agg_campaign_performance`.

### Justification des choix

**Suppression logique obligatoire.** La permission `campaigns.delete` existe dans la
matrice de rôles ; une suppression physique orphelinerait `fact_campaign_delivery` et
effacerait des coûts réellement engagés. On masque, on ne détruit pas.

**La fenêtre d'attribution est portée par la campagne.** Une relance de paiement se
juge à quelques jours, une campagne de notoriété à plusieurs semaines. Placer ce
paramètre ici évite de trancher une valeur globale unique et rend chaque calcul de
retour sur investissement auto-documenté.

**`recipient_count` figé au lancement.** Recalculer l'effectif ciblé plus tard
donnerait un dénominateur mouvant : le taux de conversion d'une campagne varierait
sans qu'aucun message n'ait été envoyé.

**Comportement des clés vers `users` à revoir.** Une mise à `NULL` fait perdre
définitivement la paternité d'une campagne si l'utilisateur est supprimé. Sur une
plateforme dotée d'une permission `audit.view`, c'est incohérent. La bonne réponse est
la suppression logique côté `users` — développée ci-dessous.

---

## 6. `users`

*Table physique : `users`. **Dimension conforme** : c'est la table d'authentification
Laravel, utilisée telle quelle comme dimension.*

### Objectif métier

Décrire l'utilisateur interne de LKM Digital : qui a créé une campagne, généré un
rapport, demandé un diagnostic IA, ou gère quel portefeuille d'écoles. C'est la
dimension d'**imputation** de toutes les actions natives d'EAC.

### Colonnes

Les colonnes marquées ✱ **n'existent pas encore** et sont proposées.

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `BIGINT UNSIGNED` | non | Clé primaire |
| `name` | `VARCHAR(255)` | non | Nom affiché |
| `email` | `VARCHAR(255)` | non | Identifiant de connexion |
| `email_verified_at` | `DATETIME` | oui | |
| `password` | `VARCHAR(255)` | non | Empreinte du mot de passe |
| `remember_token` | `VARCHAR(100)` | oui | |
| ✱ `job_title` | `VARCHAR(80)` | oui | Intitulé de poste |
| ✱ `department` | `VARCHAR(40)` | oui | Direction, Marketing, Commercial, Support, Analytics, Technique |
| ✱ `primary_role_code` | `VARCHAR(30)` | oui | Rôle courant dénormalisé |
| ✱ `phone` | `VARCHAR(20)` | oui | Contact interne |
| ✱ `locale` | `CHAR(5)` | oui | Langue d'interface |
| ✱ `timezone` | `VARCHAR(40)` | oui | Fuseau d'affichage |
| ✱ `is_active` | `BOOLEAN` | non | Compte utilisable |
| ✱ `deactivated_at` | `DATETIME` | oui | Date de départ |
| ✱ `last_login_at` | `DATETIME` | oui | Dernière connexion |
| ✱ `login_count` | `INT UNSIGNED` | non | Adoption de l'outil lui-même |
| `created_at` | `DATETIME` | non | |
| `updated_at` | `DATETIME` | non | |
| ✱ `deleted_at` | `DATETIME` | oui | Suppression logique |

### Clé primaire

`id`.

### Clés étrangères

Aucune sortante. Les rôles sont portés par les tables Spatie
(`model_has_roles`, `roles`, `permissions`), qui restent la source de vérité des
autorisations.

### Contraintes

- Unicité de `email`.
- `deactivated_at` renseignée si et seulement si `is_active` est faux.
- `primary_role_code` doit correspondre à un rôle existant — cohérence applicative,
  non exprimable en contrainte.

### Index recommandés

| Index | Usage |
|---|---|
| `email` (unique) | Authentification |
| (`is_active`) | Liste des utilisateurs actifs |
| (`department`) | Agrégations par service |
| (`primary_role_code`) | Filtrage par rôle sans jointure Spatie |
| (`last_login_at`) | Suivi d'usage de la plateforme |
| (`deleted_at`) | Exclusion des comptes supprimés |

### Relations

- **Depuis** `schools.account_manager_user_id` — portefeuille commercial.
- **Depuis** `campaigns.created_by_user_id` et `approved_by_user_id`.
- **Depuis** `adoption_rule_versions.created_by_user_id`.
- **Depuis** `ai_analysis`, `ai_recommendation`, `ai_feedback`, `audit_log`.
- **Vers** les tables Spatie, hors périmètre dimensionnel.

### Justification des choix

**Dimension conforme, pas copie.** `users` est d'abord une table d'authentification :
Laravel, Spatie et Sanctum pointent tous dessus. En créer une copie `dim_users`
imposerait une synchronisation permanente pour quelques dizaines de lignes, avec un
risque de divergence sans aucun bénéfice. On étend la table existante.

**Suppression logique indispensable.** C'est le point structurant. Aujourd'hui, les
clés étrangères vers `users` sont en « mise à `NULL` » : supprimer un utilisateur
effacerait définitivement la paternité de ses campagnes et de ses diagnostics IA. Sur
une plateforme qui expose une permission `audit.view`, perdre l'imputation est un
défaut fonctionnel. Avec `deleted_at` et `is_active`, un départ se traduit par une
désactivation, jamais par une suppression, et les clés étrangères peuvent passer en
« suppression interdite ».

**`primary_role_code` dénormalisé, en connaissance de cause.** Spatie répartit les
rôles sur trois tables. Grouper des campagnes par rôle de leur auteur imposerait cette
triple jointure à chaque requête analytique. La colonne dénormalisée l'évite, au prix
d'une cohérence à maintenir applicativement — arbitrage classique, acceptable ici
parce que l'écriture est rare et la lecture fréquente.

**Pas d'historisation de type 2, et c'est un choix discutable.** Si un utilisateur
passe de Marketing à Direction, ses campagnes passées apparaîtront rétroactivement
comme relevant de la Direction. Historiser `users` casserait l'authentification, qui
exige une ligne stable par personne. La bonne parade, le jour où cette imputation
comptera, est de **figer le rôle et le service sur la ligne de fait** au moment de
l'événement, comme attribut dégénéré — pas de transformer la table d'authentification
en dimension historisée.

**`last_login_at` et `login_count`.** EAC mesure l'adoption d'EcolePay ; il serait
paradoxal de ne pas mesurer l'adoption d'EAC lui-même. Ces deux colonnes permettent de
savoir si les tableaux de bord produits sont réellement consultés.

---

# Dimensions de référence

Petites tables stables, spécifiées ici en synthèse. Elles complètent le schéma sans
faire partie du périmètre détaillé demandé.

| Table | Clé | Objectif | Colonnes structurantes |
|---|---|---|---|
| `adoption_stages` | `TINYINT` | Les six états de l'entonnoir | `code`, `funnel_rank`, `is_converted`, `is_derived` |
| `adoption_rule_versions` | `SMALLINT` | Versionner la règle « à risque » / « perdu » | `at_risk_after_days`, `lost_after_days`, `effective_from`, `is_current` |
| `channels` | `TINYINT` | Canaux de communication | `code`, `default_unit_cost`, `requires_opt_in` |
| `payment_methods` | `TINYINT` | Moyens de paiement | `code`, `category`, `provider`, `country_code` |
| `event_types` | `SMALLINT` | Catalogue des événements d'usage | `code`, `counts_as_activity`, `activity_weight` |

Deux principes les gouvernent :

**Les coûts et frais n'y sont qu'indicatifs.** Les tarifs évoluent ; recalculer une
ancienne campagne au tarif d'aujourd'hui falsifierait sa rentabilité. Le montant
réellement facturé est figé sur la ligne de fait.

**`adoption_rule_versions` rend les courbes interprétables.** Sans elle, une inflexion
du nombre de parents « à risque » ne se distingue pas d'un simple changement de seuil.

---

# Table de liaison

## `student_parents`

*Table physique : `bridge_student_parents`. Ni dimension, ni fait.*

Un élève a souvent **deux responsables**, et ce n'est pas toujours le principal qui
paie. Une clé étrangère unique depuis `students` rendrait le second invisible, y
compris lorsque c'est lui qui déclenche la conversion.

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `student_id` | `BIGINT UNSIGNED` | non | → `students` |
| `parent_id` | `BIGINT UNSIGNED` | non | → `parents` |
| `relationship` | `VARCHAR(30)` | oui | Père, mère, tuteur |
| `is_primary_payer` | `BOOLEAN` | non | Responsable financier principal |
| `valid_from` | `DATE` | non | |
| `valid_to` | `DATE` | oui | |

- **Clé primaire** : (`student_id`, `parent_id`, `valid_from`).
- **Index** : (`parent_id`, `valid_to`) pour parcourir dans l'autre sens.
- **Règle applicative** : au plus un `is_primary_payer` vrai par élève à une date
  donnée.

---

# Diagramme relationnel

## Liens entre les six dimensions cœur

```
                        ┌───────────────────────────┐
                        │          users            │
                        │  (dimension conforme)     │
                        │  id, name, email,         │
                        │  department, is_active    │
                        └─────────┬─────────────────┘
                                  │
              ┌───────────────────┼──────────────────────┐
              │ 1                 │ 1                    │ 1
              │                   │                      │
              ▼ N                 ▼ N                    ▼ N
    ┌───────────────────┐   ┌──────────────┐   ┌───────────────────┐
    │  schools          │   │  campaigns   │   │  campaigns        │
    │  .account_        │   │  .created_   │   │  .approved_       │
    │   manager_user_id │   │   by_user_id │   │   by_user_id      │
    └─────────┬─────────┘   └──────────────┘   └───────────────────┘
              │ 1
              │
              ▼ N
    ┌───────────────────┐
    │  students         │
    │  .school_id       │
    └─────────┬─────────┘
              │ N
              │
     ┌────────▼─────────┐
     │  student_parents │   ← table de liaison
     │  (student_id,    │      résout le « plusieurs à plusieurs »
     │   parent_id)     │
     └────────┬─────────┘
              │ N
              │
              ▼ 1
    ┌───────────────────┐
    │  parents          │
    │  id, phone_hash,  │
    │  source_parent_id │
    └───────────────────┘


    ┌───────────────────┐
    │  calendar         │   Aucun lien direct avec les autres dimensions.
    │  id = AAAAMMJJ    │   Référencée uniquement par les tables de faits,
    └───────────────────┘   souvent plusieurs fois depuis la même table.
```

## Convergence dans les faits

Les dimensions ne se joignent pas entre elles pour produire un KPI : elles convergent
dans les tables de faits.

```
   calendar ─────┐
   schools ──────┤
   parents ──────┼──────► fact_adoption_journey      (parent × école)
   adoption_stages ┘

   calendar ─────┐
   parents ──────┤
   schools ──────┼──────► fact_payment               (une transaction)
   students ─────┤
   payment_methods ┘

   calendar ─────┐
   campaigns ────┤
   parents ──────┼──────► fact_campaign_delivery     (un message × un parent)
   channels ─────┘

   users ────────────────► ai_analysis, audit_log, campaigns
                           (imputation des actions natives)
```

## Cardinalités

| Relation | Cardinalité | Lecture |
|---|---|---|
| `users` → `schools` | 1 : N | Un commercial gère plusieurs écoles |
| `users` → `campaigns` | 1 : N | Un utilisateur crée plusieurs campagnes |
| `schools` → `students` | 1 : N | Une école accueille plusieurs élèves |
| `students` ↔ `parents` | N : N | Via `student_parents` |
| `calendar` → faits | 1 : N | Une date porte plusieurs événements |
| `campaigns` → faits | 1 : N | Une campagne produit plusieurs remises |

---

# Décisions ouvertes

1. **Type réel des identifiants EcolePay** — entier, UUID ou chaîne. Détermine le
   type de toutes les colonnes `source_*_id`. Le `VARCHAR(64)` actuel est un choix
   d'attente ; un UUID en `BINARY(16)` occupe quatre fois moins de place.
2. **Données personnelles dans `parents`** — `phone_e164`, `full_name` et `email`
   sont-ils nécessaires à l'analyse, ou seulement à l'action commerciale ? Leur
   suppression réduirait fortement l'exposition mais rendrait `parents.export`
   inutilisable pour le Commercial.
3. **Nom des élèves** — recommandation : ne pas le stocker. À confirmer, le Support
   pouvant en avoir besoin pour traiter une réclamation.
4. **Suppression logique sur `users`** — à valider, car elle conditionne le
   comportement des clés étrangères d'imputation.
5. **Calendrier scolaire de référence** — dates de rentrée, trimestres et vacances,
   par pays. Nécessaire pour peupler `calendar` au-delà du calendrier civil.
6. **Périmètre pays** — si plusieurs, `schools` doit porter devise et fuseau horaire,
   et tous les horodatages passer en UTC avec conversion à l'affichage.
