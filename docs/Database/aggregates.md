# Dictionnaire des tables d'agrégation

> Spécification de conception. Voir [`facts.md`](facts.md) pour les tables sources et
> [`dimensions.md`](dimensions.md) pour les dimensions référencées.

## Les trois règles qui gouvernent tous les agrégats

### 1. Un agrégat n'est jamais source de vérité

Toute table de ce document est **dérivée et reconstructible**. Elle peut être
détruite et régénérée depuis les faits. Aucune donnée ne doit y exister sans pouvoir
être recalculée — sans quoi ce n'est plus un agrégat, c'est un fait mal rangé.

### 2. Ne jamais stocker un taux seul

C'est l'erreur qui produit les chiffres faux les plus difficiles à repérer.

Un taux **n'est pas additif**. Si `school_kpis` ne stocke qu'un `adoption_rate` par
école, le taux national n'est **pas** la moyenne de ces taux : une école de
30 parents et une école de 3 000 y pèseraient pareil.

> **Règle : stocker le numérateur et le dénominateur, calculer le taux à la lecture.**
> `converted_count` et `eligible_count`, jamais `adoption_rate` seul.

Le même raisonnement s'applique aux moyennes (`avg_days_to_adoption` doit
s'accompagner de son effectif) et aux **médianes**, qui ne se recomposent pas du tout
— une médiane nationale ne se déduit d'aucune médiane par école.

### 3. Recalculer une fenêtre glissante, jamais seulement la veille

Les données arrivent en retard : accusés de remise, transactions différées,
corrections EcolePay. Un traitement strictement incrémental grave définitivement les
valeurs partielles du jour J.

> **Règle : chaque nuit, recalculer les N derniers jours, pas seulement le dernier.**
> Proposition : 7 jours pour les paiements, 30 jours pour les campagnes.

Un indicateur `is_final` marque les périodes dont la fenêtre est close et qui ne
seront plus recalculées.

### Corollaire : les comptes distincts ne s'additionnent pas

Le nombre de parents distincts au niveau national n'est pas la somme des parents
distincts par école : un parent ayant des enfants dans deux établissements serait
compté deux fois. Chaque périmètre doit être **calculé séparément**, jamais sommé.

---

## Colonnes de traçabilité communes

Toute table d'agrégation porte :

| Colonne | Type | Rôle |
|---|---|---|
| `computed_at` | `DATETIME` | Date de production de la ligne |
| `source_watermark_at` | `DATETIME` | Fraîcheur des données utilisées |
| `computation_version` | `VARCHAR(20)` | Version de l'algorithme, pour recalcul sélectif |
| `is_final` | `BOOLEAN` | Période close, ne sera plus recalculée |

`source_watermark_at` alimente le « données à jour au… » affiché sur les tableaux de
bord — la première question posée devant un chiffre surprenant.

---

## 1. `daily_kpis`

### Rôle

Alimenter les courbes de tendance de l'écran principal : entonnoir dans le temps,
volumes de paiement, activité. C'est la table lue à chaque chargement du tableau de
bord national.

### Grain

**Un jour × un périmètre géographique.**

Le périmètre est explicite plutôt qu'implicite : `scope_level` vaut `global`,
`country` ou `region`. Les lignes de totalisation sont donc de vraies lignes, calculées
indépendamment — jamais des sommes de lignes plus fines (voir corollaire ci-dessus).

### Colonnes

| Colonne | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Clé de substitution |
| `date_id` | `INT UNSIGNED` | → `calendar` |
| `scope_level` | `VARCHAR(10)` | `global`, `country`, `region` |
| `scope_code` | `VARCHAR(80)` | Code pays ou nom de région ; `ALL` si global |
| **Effectifs en fin de journée** | | |
| `known_count` | `INT UNSIGNED` | Parents connus |
| `registered_count` | `INT UNSIGNED` | Parents inscrits |
| `adopter_count` | `INT UNSIGNED` | Parents adoptants |
| `engaged_count` | `INT UNSIGNED` | Parents engagés |
| `at_risk_count` | `INT UNSIGNED` | Parents à risque |
| `lost_count` | `INT UNSIGNED` | Parents perdus |
| `distinct_parent_count` | `INT UNSIGNED` | Parents distincts, calculé sur ce périmètre |
| **Mouvements du jour** | | |
| `new_known` | `INT UNSIGNED` | Entrées dans l'entonnoir |
| `new_registered` | `INT UNSIGNED` | Nouvelles inscriptions |
| `new_adopters` | `INT UNSIGNED` | **Conversions du jour** |
| `new_at_risk` | `INT UNSIGNED` | |
| `new_lost` | `INT UNSIGNED` | |
| `reactivations` | `INT UNSIGNED` | Retours depuis « à risque » ou « perdu » |
| **Couples taux** | | |
| `converted_count` | `INT UNSIGNED` | Numérateur du taux d'adoption |
| `eligible_count` | `INT UNSIGNED` | Dénominateur du taux d'adoption |
| `registered_to_adopter_count` | `INT UNSIGNED` | Numérateur du taux de conversion |
| **Paiements** | | |
| `payment_count` | `INT UNSIGNED` | Toutes tentatives |
| `successful_payment_count` | `INT UNSIGNED` | |
| `failed_payment_count` | `INT UNSIGNED` | |
| `first_payment_count` | `INT UNSIGNED` | |
| `payment_amount` | `DECIMAL(16,2)` | Montant abouti |
| `currency` | `CHAR(3)` | |
| **Activité** | | |
| `active_parent_count` | `INT UNSIGNED` | Au moins un événement qualifiant |
| `activity_event_count` | `BIGINT UNSIGNED` | |
| **Délais** | | |
| `sum_days_to_adoption` | `BIGINT UNSIGNED` | Somme, pour recomposer la moyenne |
| `adoption_delay_sample_count` | `INT UNSIGNED` | Effectif associé |
| **Traçabilité** | | |
| `rule_version_id` | `SMALLINT UNSIGNED` | → `adoption_rule_versions` |
| `computed_at`, `source_watermark_at`, `computation_version`, `is_final` | | |

### Fréquence de calcul

| Période | Cadence |
|---|---|
| Journée en cours | Toutes les heures, si la fraîcheur horaire est retenue |
| J-1 à J-7 | Chaque nuit, recalcul complet |
| Au-delà de J-7 | Figée, `is_final = vrai` |

### Index

| Index | Usage |
|---|---|
| (`date_id`, `scope_level`, `scope_code`) — **unique** | Grain, et cible de l'upsert |
| (`scope_level`, `scope_code`, `date_id`) | Série temporelle d'un périmètre |
| (`date_id`) | Coupe transversale à une date |
| (`is_final`) | Repérage des périodes à recalculer |

### Règles de mise à jour

1. **Upsert sur la clé naturelle** (`date_id`, `scope_level`, `scope_code`). Le
   traitement est rejouable sans produire de doublon.
2. **Chaque périmètre est calculé séparément.** La ligne `global` n'est pas la somme
   des lignes `region` — les parents multi-écoles y seraient comptés plusieurs fois.
3. **Un changement de version de règle** invalide toutes les lignes non finales et
   déclenche un recalcul sur la période concernée.
4. `is_final` passe à vrai une fois la fenêtre de reprise dépassée. Ces lignes sont
   alors exclues des traitements nocturnes.

### Relations

Lit `parent_journeys`, `adoption_events`, `payments`, `parent_activities`.
Référence `calendar` et `adoption_rule_versions`.

---

## 2. `school_kpis`

### Rôle

Piloter le portefeuille : classement des écoles, suivi de progression, détection des
établissements en retard d'adoption. C'est la table du rôle Commercial et des revues
de Direction.

### Grain

**Une école × une période**, la période étant paramétrable.

`period_type` vaut `day`, `week` ou `month`. Une seule table sert les trois
granularités plutôt que trois tables quasi identiques — au prix d'un grain mixte,
compensé par l'unicité qui inclut `period_type`.

### Colonnes

| Colonne | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | |
| `school_id` | `BIGINT UNSIGNED` | → `schools` |
| `period_type` | `VARCHAR(10)` | `day`, `week`, `month` |
| `period_start_date_id` | `INT UNSIGNED` | → `calendar` |
| `period_end_date_id` | `INT UNSIGNED` | → `calendar` |
| **Effectifs** | | |
| `known_count` … `lost_count` | `INT UNSIGNED` | Six colonnes, une par état |
| `distinct_parent_count` | `INT UNSIGNED` | |
| `student_count` | `INT UNSIGNED` | Effectif de l'école sur la période |
| **Mouvements** | | |
| `new_registered`, `new_adopters`, `new_at_risk`, `new_lost`, `reactivations` | `INT UNSIGNED` | |
| **Couples taux** | | |
| `converted_count` | `INT UNSIGNED` | Numérateur |
| `eligible_count` | `INT UNSIGNED` | Dénominateur |
| `payment_success_count` | `INT UNSIGNED` | Numérateur du taux de réussite |
| `payment_attempt_count` | `INT UNSIGNED` | Dénominateur |
| **Paiements** | | |
| `payment_amount` | `DECIMAL(16,2)` | |
| `first_payment_count` | `INT UNSIGNED` | |
| `failed_payment_count` | `INT UNSIGNED` | |
| **Délais** | | |
| `sum_days_to_adoption`, `adoption_delay_sample_count` | | Pour recomposer la moyenne |
| **Comparaison** | | |
| `adoption_rate_previous_period` | `DECIMAL(6,4)` | Valeur figée, pour l'écart |
| `rank_national` | `INT UNSIGNED` | Rang, calculé en second passage |
| `rank_regional` | `INT UNSIGNED` | |
| **Dénormalisation de filtrage** | | |
| `account_manager_user_id` | `BIGINT UNSIGNED` | → `users` |
| `region` | `VARCHAR(80)` | |
| `country_code` | `CHAR(2)` | |
| **Traçabilité** | | |
| `rule_version_id`, `computed_at`, `source_watermark_at`, `computation_version`, `is_final` | | |

### Fréquence de calcul

| `period_type` | Cadence | Fenêtre de reprise |
|---|---|---|
| `day` | Chaque nuit | 7 jours |
| `week` | Chaque nuit, semaine courante et précédente | 2 semaines |
| `month` | Chaque nuit, mois courant ; figé au 5 du mois suivant | 1 mois |

### Index

| Index | Usage |
|---|---|
| (`school_id`, `period_type`, `period_start_date_id`) — **unique** | Grain, cible de l'upsert |
| (`period_type`, `period_start_date_id`, `converted_count`) | Classements |
| (`account_manager_user_id`, `period_type`, `period_start_date_id`) | Portefeuille d'un commercial |
| (`region`, `period_type`, `period_start_date_id`) | Analyse régionale |
| (`school_id`, `period_type`) | Série temporelle d'une école |

### Règles de mise à jour

1. **Upsert** sur (`school_id`, `period_type`, `period_start_date_id`).
2. **Calcul en deux passages.** Les colonnes `rank_*` dépendent de toutes les autres
   écoles : elles ne peuvent être renseignées qu'après le calcul de l'ensemble des
   lignes de la période. Un seul passage produirait des rangs faux.
3. `adoption_rate_previous_period` est **figé à la production**, pas recalculé — sinon
   l'écart affiché changerait rétroactivement.
4. Les colonnes dénormalisées (`region`, `account_manager_user_id`) reflètent l'état
   de l'école **à la clôture de la période**, ce qui permet de filtrer sans jointure
   sur la dimension historisée.

### Relations

Lit `parent_journeys`, `school_daily_snapshots`, `payments`. Référence `schools`,
`calendar`, `users`.

---

## 3. `parent_kpis`

### Rôle

Répondre aux questions **par personne**, que `parent_journeys` ne peut pas traiter.

C'est le point important de cette table : `parent_journeys` a pour grain
« parent × école ». Un parent ayant des enfants dans deux établissements y occupe deux
lignes. Compter les parents adoptants au niveau national en sommant ces lignes le
compterait deux fois.

`parent_kpis` **déduplique** : une ligne par personne, tous établissements confondus.

> Si vous entendiez autre chose par `parent_kpis` — des agrégats par segment de
> parents (par état, par ancienneté, par région) — c'est une table distincte, à
> nommer `parent_segment_kpis`. Voir décision ouverte n° 1.

### Grain

**Un parent.**

### Colonnes

| Colonne | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | |
| `parent_id` | `BIGINT UNSIGNED` | → `parents` |
| `school_count` | `TINYINT UNSIGNED` | Établissements concernés |
| `primary_school_id` | `BIGINT UNSIGNED` | École de rattachement principale |
| **État consolidé** | | |
| `overall_stage_id` | `TINYINT UNSIGNED` | → `adoption_stages`, état le plus avancé |
| `worst_stage_id` | `TINYINT UNSIGNED` | État le moins avancé |
| `is_converted_anywhere` | `BOOLEAN` | A payé dans au moins une école |
| `is_active_anywhere` | `BOOLEAN` | |
| `is_at_risk_everywhere` | `BOOLEAN` | Signal de départ réel |
| **Jalons consolidés** | | |
| `first_known_at` | `DATETIME` | Le plus ancien des établissements |
| `first_registered_at` | `DATETIME` | |
| `first_payment_at` | `DATETIME` | Première conversion, tous établissements |
| `last_activity_at` | `DATETIME` | Le plus récent |
| `days_to_first_adoption` | `SMALLINT UNSIGNED` | |
| `days_since_last_activity` | `SMALLINT UNSIGNED` | |
| **Volumes cumulés** | | |
| `total_payment_count` | `INT UNSIGNED` | |
| `total_successful_payment_count` | `INT UNSIGNED` | |
| `total_amount` | `DECIMAL(14,2)` | |
| `total_activity_event_count` | `INT UNSIGNED` | |
| `total_campaign_contact_count` | `INT UNSIGNED` | Pression marketing subie |
| `reactivation_count` | `TINYINT UNSIGNED` | |
| **Dénormalisation de filtrage** | | |
| `region` | `VARCHAR(80)` | De l'école principale |
| `preferred_channel_id` | `TINYINT UNSIGNED` | |
| `marketing_consent` | `BOOLEAN` | Ciblage sans jointure |
| **Traçabilité** | | |
| `computed_at`, `source_watermark_at`, `computation_version` | | |

### Fréquence de calcul

**Quotidienne**, immédiatement après `parent_journeys`, dont elle est dérivée.

Recalcul incrémental : seuls les parents dont au moins une ligne de
`parent_journeys` a changé depuis la veille sont retraités.

### Index

| Index | Usage |
|---|---|
| `parent_id` — **unique** | Grain, cible de l'upsert |
| (`overall_stage_id`) | Effectifs nationaux dédoublonnés |
| (`is_converted_anywhere`) | Taux d'adoption par personne |
| (`days_since_last_activity`) | Listes de parents à relancer |
| (`region`, `overall_stage_id`) | Ciblage régional |
| (`marketing_consent`, `overall_stage_id`) | Constitution de segments de campagne |

### Règles de mise à jour

1. **Upsert** sur `parent_id`.
2. **La règle de consolidation d'état doit être explicite.** Un parent « adoptant » à
   l'école A et « perdu » à l'école B est-il adoptant ou perdu ? Ce document retient
   l'état de **rang le plus élevé** pour `overall_stage_id`, en conservant
   `worst_stage_id` pour ne pas masquer le second cas. Voir décision ouverte n° 2.
3. Un parent supprimé de `parents` — pseudonymisé, jamais supprimé — conserve sa ligne
   d'agrégat, les mesures restant valides.
4. Cette table sert aussi de **plan de ciblage** pour les campagnes : les colonnes
   dénormalisées évitent une jointure sur plusieurs millions de lignes au moment de
   constituer un segment.

### Relations

Lit `parent_journeys`. Référence `parents`, `schools`, `adoption_stages`, `channels`.
Alimente le ciblage de `campaigns` et les entrées de `ai_prediction`.

---

## 4. `campaign_kpis`

### Rôle

Mesurer la performance et la rentabilité des campagnes : remise, engagement,
conversions attribuées, coût par conversion.

### Grain

**Une campagne × une période.** `period_type` vaut `total` — bilan consolidé — ou
`day`, pour la courbe d'envoi et de remise.

### Colonnes

| Colonne | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | |
| `campaign_id` | `BIGINT UNSIGNED` | → `campaigns` |
| `period_type` | `VARCHAR(10)` | `total`, `day` |
| `period_start_date_id` | `INT UNSIGNED` | → `calendar` |
| **Remise** | | |
| `targeted_count` | `INT UNSIGNED` | Effectif ciblé |
| `sent_count` | `INT UNSIGNED` | |
| `delivered_count` | `INT UNSIGNED` | |
| `opened_count` | `INT UNSIGNED` | |
| `clicked_count` | `INT UNSIGNED` | |
| `failed_count` | `INT UNSIGNED` | |
| `bounced_count` | `INT UNSIGNED` | |
| **Attribution** | | |
| `evaluated_count` | `INT UNSIGNED` | Contacts dont la fenêtre est close |
| `pending_evaluation_count` | `INT UNSIGNED` | Fenêtre encore ouverte |
| `attributed_conversion_count` | `INT UNSIGNED` | Numérateur du taux de conversion |
| `attributed_amount` | `DECIMAL(16,2)` | |
| `reactivation_count` | `INT UNSIGNED` | |
| `sum_days_to_conversion` | `INT UNSIGNED` | Pour recomposer la moyenne |
| **Coût** | | |
| `total_cost` | `DECIMAL(14,2)` | Somme des coûts réels figés |
| `currency` | `CHAR(3)` | |
| **Dénormalisation de filtrage** | | |
| `channel_id` | `TINYINT UNSIGNED` | |
| `objective` | `VARCHAR(40)` | |
| `target_stage_id` | `TINYINT UNSIGNED` | |
| `attribution_model` | `VARCHAR(30)` | |
| **Traçabilité** | | |
| `computed_at`, `source_watermark_at`, `computation_version`, `is_final` | | |

### Fréquence de calcul

| Phase | Cadence |
|---|---|
| Campagne en cours d'envoi | Toutes les heures |
| Fenêtre d'attribution ouverte | Chaque nuit |
| Fenêtre close pour tous les contacts | Calcul final, `is_final = vrai` |

Une campagne à fenêtre de 14 jours n'est donc définitive que 14 jours après son
dernier envoi.

### Index

| Index | Usage |
|---|---|
| (`campaign_id`, `period_type`, `period_start_date_id`) — **unique** | Grain |
| (`period_type`, `period_start_date_id`) | Comparaison entre campagnes |
| (`channel_id`, `period_type`) | Performance par canal |
| (`objective`) | Comparaison par type d'objectif |
| (`is_final`) | Repérage des campagnes à recalculer |

### Règles de mise à jour

1. **Le taux de conversion se calcule sur `evaluated_count`, jamais sur
   `sent_count`.** C'est le piège principal de cette table : pendant la fenêtre
   d'attribution, aucune conversion n'est encore attribuée. Diviser par les envois
   ferait apparaître toute campagne récente comme un échec total, et le tableau de
   bord inciterait à couper une campagne qui fonctionne.
2. **`pending_evaluation_count` doit être affiché**, pas seulement stocké : il
   explique à l'utilisateur pourquoi le chiffre est encore provisoire.
3. **Les coûts sont sommés depuis `campaign_contacts`**, où ils ont été figés à
   l'envoi — jamais recalculés depuis le tarif courant du canal, sous peine de
   falsifier la rentabilité d'anciennes campagnes.
4. Un changement de `attribution_model` déclenche le recalcul de `campaign_results`
   **puis** de cette table. Les deux versions ne doivent jamais coexister dans un même
   graphique comparatif.
5. `delivered_count` et suivants continuent d'évoluer après l'envoi, les accusés
   arrivant en différé : la fenêtre de reprise de 30 jours est plus longue ici
   qu'ailleurs.

### Relations

Lit `campaign_contacts` et `campaign_results`. Référence `campaigns`, `channels`,
`calendar`, `adoption_stages`.

---

## 5. `dashboard_snapshots`

### Rôle

Servir en une lecture le contenu déjà mis en forme d'un écran. C'est un **cache**, de
nature différente des quatre tables précédentes : non typé, non joignable, jetable.

Sa raison d'être est le temps d'affichage des tuiles d'en-tête, qui doivent apparaître
immédiatement alors qu'elles agrègent plusieurs sources.

### Grain

**Un écran × un périmètre × une date de référence.**

### Colonnes

| Colonne | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | |
| `dashboard_key` | `VARCHAR(60)` | `main`, `school_detail`, `campaign_overview`, `adoption_funnel` |
| `scope_type` | `VARCHAR(20)` | `global`, `country`, `region`, `school`, `campaign` |
| `scope_id` | `VARCHAR(64)` | Identifiant du périmètre ; `ALL` si global |
| `as_of_date_id` | `INT UNSIGNED` | → `calendar`, date de référence |
| `period_type` | `VARCHAR(10)` | Granularité représentée |
| `payload` | `JSON` | Contenu prêt à afficher : tuiles, séries, classements |
| `payload_size_bytes` | `INT UNSIGNED` | Surveillance de dérive |
| `computed_at` | `DATETIME` | |
| `expires_at` | `DATETIME` | Fin de validité |
| `source_watermark_at` | `DATETIME` | Affiché tel quel dans l'interface |
| `computation_version` | `VARCHAR(20)` | |
| `is_stale` | `BOOLEAN` | Invalidé, à régénérer |

### Fréquence de calcul

| Écran | Cadence |
|---|---|
| Tableau de bord principal | Toutes les 15 minutes, ou à la fin de chaque synchronisation |
| Détail d'une école | À la demande, avec durée de validité d'une heure |
| Vue campagne | À la demande, invalidée à chaque lot d'accusés |

### Index

| Index | Usage |
|---|---|
| (`dashboard_key`, `scope_type`, `scope_id`, `as_of_date_id`) — **unique** | Lecture directe, cible de l'upsert |
| (`expires_at`) | Purge des entrées périmées |
| (`is_stale`) | File de régénération |
| (`computed_at`) | Diagnostic de fraîcheur |

### Règles de mise à jour

1. **Ne jamais interroger l'intérieur du `payload`.** Aucun filtre, aucune jointure,
   aucun tri sur son contenu. Le jour où l'on a besoin de le faire, c'est qu'il
   fallait une table typée — l'une des quatre précédentes.
2. **Aucun chiffre ne doit exister uniquement ici.** Cette table est reconstructible à
   partir des agrégats typés ; si une valeur n'existe nulle part ailleurs, elle est au
   mauvais endroit.
3. **Invalidation par la synchronisation.** La fin d'un lot de synchronisation marque
   `is_stale` sur les périmètres touchés plutôt que d'attendre l'expiration.
4. **Pas de variante par rôle.** Le contenu dépend du périmètre, pas de l'utilisateur.
   Les permissions déterminent **quels périmètres** un utilisateur peut lire — un
   Commercial n'accède qu'aux écoles de son portefeuille. Dupliquer le contenu par
   rôle multiplierait le stockage et les risques d'incohérence.
5. **`source_watermark_at` est repris tel quel dans l'interface.** C'est ce qui permet
   d'afficher « données à jour au… » et d'éviter qu'un utilisateur prenne une décision
   sur un écran périmé sans le savoir.

### Relations

Lit `daily_kpis`, `school_kpis`, `parent_kpis`, `campaign_kpis`. Ne référence aucune
dimension par clé étrangère : le périmètre est décrit par un couple
(`scope_type`, `scope_id`) volontairement souple.

---

## 6. `cohort_kpis` — proposition d'ajout

### Rôle

Répondre à la question stratégique que les quatre tables précédentes ne savent pas
traiter : **« nos parents restent-ils ? »**

Un graphique période sur période peut monter alors que chaque génération de parents se
comporte plus mal que la précédente — la croissance du volume masque la dégradation.
Seule une analyse par cohorte le révèle.

### Grain

**Une cohorte × un nombre de mois écoulés × un périmètre.**

### Colonnes

| Colonne | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | |
| `cohort_month_date_id` | `INT UNSIGNED` | → `calendar`, mois d'entrée |
| `cohort_basis` | `VARCHAR(20)` | `known`, `registered`, `first_payment` |
| `months_since` | `TINYINT UNSIGNED` | 0, 1, 2… |
| `scope_level` | `VARCHAR(10)` | `global`, `country`, `region`, `school` |
| `scope_code` | `VARCHAR(80)` | |
| `cohort_size` | `INT UNSIGNED` | Dénominateur, figé à la constitution |
| `still_active_count` | `INT UNSIGNED` | |
| `converted_count` | `INT UNSIGNED` | Conversions cumulées |
| `at_risk_count` | `INT UNSIGNED` | |
| `lost_count` | `INT UNSIGNED` | |
| `cumulative_amount` | `DECIMAL(16,2)` | |
| `computed_at`, `source_watermark_at`, `computation_version` | | |

### Fréquence de calcul

**Mensuelle**, en début de mois, pour toutes les cohortes encore suivies. Les cohortes
au-delà de 24 mois peuvent être figées.

### Index

| Index | Usage |
|---|---|
| (`cohort_month_date_id`, `cohort_basis`, `months_since`, `scope_level`, `scope_code`) — **unique** | Grain |
| (`cohort_basis`, `months_since`) | Courbes de rétention superposées |
| (`scope_level`, `scope_code`) | Comparaison entre périmètres |

### Règles de mise à jour

1. **`cohort_size` est figé à la constitution de la cohorte** et n'est jamais
   recalculé. Un dénominateur mouvant rendrait la courbe de rétention ininterprétable.
2. Chaque mois ajoute une ligne `months_since + 1` par cohorte vivante, sans modifier
   les lignes antérieures.
3. `cohort_basis` permet trois lectures distinctes — rétention depuis la connaissance,
   depuis l'inscription, ou depuis le premier paiement. La troisième est la plus
   parlante commercialement.

---

# Ordre d'exécution

Les dépendances imposent un ordre strict. L'inverser produirait des agrégats calculés
sur des sources non encore rafraîchies — sans aucune erreur visible.

```
  1. Synchronisation EcolePay
     └─► payments, parent_activities

  2. Calculs de faits dérivés
     └─► adoption_events  ──►  parent_journeys

  3. Instantané périodique
     └─► school_daily_snapshots

  4. Agrégats typés            (parallélisables entre eux)
     ├─► daily_kpis
     ├─► school_kpis           (deux passages : mesures, puis rangs)
     ├─► parent_kpis
     ├─► campaign_kpis
     └─► cohort_kpis           (mensuel)

  5. Cache d'affichage
     └─► dashboard_snapshots
```

`dashboard_snapshots` est nécessairement **dernier** : il lit les quatre agrégats
typés. Le régénérer avant eux servirait des chiffres de la veille avec un horodatage
du jour — l'incohérence la plus difficile à diagnostiquer.

---

# Synthèse

| Table | Grain | Cadence | Fenêtre de reprise | Nature |
|---|---|---|---|---|
| `daily_kpis` | Jour × périmètre | Nuit + horaire | 7 jours | Typée |
| `school_kpis` | École × période | Nuit | 7 à 30 jours | Typée |
| `parent_kpis` | Parent | Nuit | Incrémentale | Typée |
| `campaign_kpis` | Campagne × période | Nuit + horaire | 30 jours | Typée |
| `cohort_kpis` | Cohorte × mois écoulés | Mensuelle | Cohortes vivantes | Typée |
| `dashboard_snapshots` | Écran × périmètre | 15 min ou à la demande | Sans objet | Cache |

**Volumétrie indicative**, pour 500 écoles et 100 000 parents :

| Table | Lignes par an |
|---|---|
| `daily_kpis` | ~ 10 000 (365 × périmètres) |
| `school_kpis` | ~ 200 000 (jour + semaine + mois) |
| `parent_kpis` | ~ 100 000, stable |
| `campaign_kpis` | quelques milliers |
| `cohort_kpis` | ~ 20 000 |
| `dashboard_snapshots` | quelques milliers, purgés |

Aucune de ces tables n'appelle de partitionnement. Le poste de coût est le **temps de
calcul nocturne**, pas le stockage.

---

# Décisions ouvertes

1. **`parent_kpis` : consolidation par personne, ou agrégats par segment ?** Ce
   document retient la première lecture, qui répond à un besoin réel — dédoublonner
   les parents multi-écoles. La seconde justifierait une table distincte.
2. **Règle de consolidation d'état.** Un parent « adoptant » à l'école A et « perdu » à
   l'école B : quel est son état national ? Ce document retient l'état le plus avancé,
   avec `worst_stage_id` en garde-fou.
3. **Fenêtres de reprise.** 7 jours pour les paiements, 30 pour les campagnes sont des
   propositions. À caler sur le retard réel constaté après quelques semaines
   d'exploitation.
4. **Fraîcheur du tableau de bord principal.** Quinze minutes est proposé ; une
   régénération à chaque fin de synchronisation serait plus cohérente si celle-ci
   devient horaire.
5. **Granularités de `school_kpis`.** Jour, semaine et mois sont proposés. Si la revue
   commerciale est mensuelle, la granularité hebdomadaire peut être écartée et
   diviserait le volume par trois.
6. **Devise unique ou multiple.** Toutes les colonnes de montant supposent une devise
   par ligne. En cas de pluralité de pays, il faudra soit une devise de consolidation
   et un taux de change historisé, soit renoncer aux totaux inter-pays.
