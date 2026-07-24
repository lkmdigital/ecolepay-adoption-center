# Dictionnaire des tables de faits

> Spécification de conception. Cible MySQL 8+. Voir [`dimensions.md`](dimensions.md)
> pour les dimensions référencées.

## Qu'est-ce qui fait une table de faits

Une table est une table de faits lorsqu'elle réunit trois propriétés :

1. **Un grain unique et explicite** — une ligne représente une et une seule chose,
   énonçable en une phrase.
2. **Des mesures numériques** — montants, durées, compteurs, indicateurs — sur
   lesquelles on agrège.
3. **Des clés vers des dimensions** — le contexte descriptif n'est pas répété dans la
   table, il est référencé.

Une dimension répond à « qui, quoi, où, quand ». Un fait répond à « combien, combien
de fois, en combien de temps ».

### Les trois formes de faits présentes ici

| Forme | Comportement | Tables concernées |
|---|---|---|
| **Transactionnel** | Une ligne par événement, insérée puis figée | `payments`, `adoption_events`, `parent_activities` |
| **Instantané cumulatif** | Une ligne par entité, mise à jour au fil des jalons | `parent_journeys`, `campaign_results` |
| **Instantané périodique** | Une ligne par entité et par période | `school_daily_snapshots` |

`campaign_contacts` est un cas particulier : transactionnel à l'insertion, mais
**mutable** ensuite, les accusés de remise arrivant en différé.

---

## 1. `payments`

### Objectif

Porter le fait qui **définit la conversion**. Le passage de « parent inscrit » à
« parent adoptant » n'a pas d'autre déclencheur que le premier paiement abouti.

### Pourquoi c'est une table de faits

Grain unique (une transaction), mesures additives (montant, frais, net), et contexte
entièrement porté par des clés dimensionnelles. C'est le cas d'école du fait
transactionnel.

### Grain

**Une tentative de paiement**, aboutie ou non.

### Colonnes

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `BIGINT UNSIGNED` | non | Clé de substitution |
| `source_payment_id` | `VARCHAR(64)` | non | Référence EcolePay — garantit l'idempotence |
| `date_id` | `INT UNSIGNED` | non | → `calendar` |
| `paid_at` | `DATETIME` | non | Horodatage précis |
| `parent_id` | `BIGINT UNSIGNED` | non | → `parents` |
| `school_id` | `BIGINT UNSIGNED` | non | → `schools` |
| `student_id` | `BIGINT UNSIGNED` | oui | → `students` |
| `payment_method_id` | `TINYINT UNSIGNED` | non | → `payment_methods` |
| `amount` | `DECIMAL(14,2)` | non | Montant brut |
| `fee_amount` | `DECIMAL(12,2)` | oui | Frais réellement prélevés |
| `net_amount` | `DECIMAL(14,2)` | oui | Net encaissé par l'école |
| `currency` | `CHAR(3)` | non | ISO 4217 |
| `status` | `VARCHAR(20)` | non | `success`, `failed`, `pending`, `refunded`, `cancelled` |
| `failure_reason` | `VARCHAR(120)` | oui | Motif d'échec fourni par le prestataire |
| `is_first_payment` | `BOOLEAN` | non | **Calculé** — déclenche la conversion |
| `payment_sequence` | `INT UNSIGNED` | oui | Rang du paiement pour ce parent × école |
| `days_since_previous_payment` | `SMALLINT UNSIGNED` | oui | Mesure de régularité |
| `installment_label` | `VARCHAR(40)` | oui | Tranche ou échéance concernée |
| `school_year_label` | `CHAR(9)` | oui | Dimension dégénérée, évite une jointure |
| `is_test` | `BOOLEAN` | non | |
| `source_created_at` | `DATETIME` | oui | |
| `synced_at` | `DATETIME` | non | |
| `sync_run_id` | `BIGINT UNSIGNED` | oui | → `sync_runs`, traçabilité |

### Clés

- **Primaire** : `id`.
- **Unicité** : `source_payment_id` — rejoue une synchronisation sans doublon.
- **Étrangères** : `date_id`, `parent_id`, `school_id`, `student_id`,
  `payment_method_id`. Toutes en suppression interdite : un fait ne doit jamais
  perdre son contexte.

### Index recommandés

| Index | Usage |
|---|---|
| `source_payment_id` (unique) | Synchronisation idempotente |
| (`school_id`, `date_id`) | Volume de paiements par école et période |
| (`parent_id`, `paid_at`) | Historique d'un parent, calcul de séquence |
| (`date_id`, `status`) | Agrégats quotidiens |
| (`is_first_payment`, `date_id`) | Conversions par période |
| (`status`, `school_id`) | Liste d'appel des échecs |

### Relations

Vers `calendar`, `parents`, `schools`, `students`, `payment_methods`.
Alimente `parent_journeys`, `adoption_events`, `campaign_results`,
`school_daily_snapshots`.

### Fréquence d'alimentation

**Incrémentale, idéalement horaire.** Un premier paiement est l'événement le plus
actionnable de la plateforme : le détecter le lendemain fait perdre une journée
d'action commerciale. Une reprise nocturne complète sur une fenêtre glissante de 7
jours rattrape les arrivées tardives.

### Source des données

| Élément | Provenance |
|---|---|
| Transaction, montant, statut, moyen | **EcolePay**, via ETL |
| `fee_amount`, `net_amount` | EcolePay ou prestataire de paiement |
| `is_first_payment`, `payment_sequence`, `days_since_previous_payment` | **Calcul interne** |

### Points de vigilance

**Les échecs doivent être conservés.** Un premier paiement échoué est le signal le
plus exploitable du dispositif : le parent a essayé et n'y est pas parvenu, il est à
un geste de la conversion. Ne garder que les succès priverait le Commercial et le
Support de leur meilleure liste d'appels.

**`is_first_payment` est fragile par nature.** Ce n'est pas une donnée source mais une
déduction, et une transaction arrivée en retard peut se révéler antérieure à celle
déjà marquée. L'indicateur doit être **recalculé** sur la fenêtre de reprise, jamais
figé à l'insertion.

**Le remboursement d'un premier paiement est un cas non tranché.** Le parent
redevient-il « inscrit » ? Voir décision ouverte n° 2.

---

## 2. `adoption_events`

### Objectif

Conserver l'**histoire** des changements d'état du parcours d'adoption. C'est le
registre de la machine à états.

### Pourquoi c'est une table de faits

Chaque ligne est un événement daté, rattaché à des dimensions, porteur d'une mesure
(`days_in_previous_stage`). Les questions qu'elle sert — « combien de conversions en
mars », « quelle vélocité d'entonnoir » — sont des agrégations sur des événements.

### Grain

**Une transition d'état** pour un parent × une école.

### Colonnes

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `BIGINT UNSIGNED` | non | Clé de substitution |
| `date_id` | `INT UNSIGNED` | non | → `calendar` |
| `occurred_at` | `DATETIME` | non | Horodatage de la transition |
| `parent_id` | `BIGINT UNSIGNED` | non | → `parents` |
| `school_id` | `BIGINT UNSIGNED` | non | → `schools` |
| `from_stage_id` | `TINYINT UNSIGNED` | oui | → `adoption_stages`, `NULL` à l'entrée dans l'entonnoir |
| `to_stage_id` | `TINYINT UNSIGNED` | non | → `adoption_stages` |
| `trigger_type` | `VARCHAR(30)` | non | `payment`, `registration`, `inactivity_rule`, `activity`, `sync`, `manual` |
| `trigger_reference` | `VARCHAR(64)` | oui | Référence de l'événement déclencheur |
| `rule_version_id` | `SMALLINT UNSIGNED` | oui | → `adoption_rule_versions` |
| `days_in_previous_stage` | `SMALLINT UNSIGNED` | oui | Mesure de vélocité |
| `is_progression` | `BOOLEAN` | non | Rang d'entonnoir croissant |
| `is_regression` | `BOOLEAN` | non | Rang décroissant |
| `is_reactivation` | `BOOLEAN` | non | Retour depuis « à risque » ou « perdu » |
| `is_test` | `BOOLEAN` | non | |
| `computed_at` | `DATETIME` | non | Date de production de la ligne |

### Clés

- **Primaire** : `id`.
- **Unicité** : (`parent_id`, `school_id`, `occurred_at`, `to_stage_id`) — permet de
  rejouer un calcul sans dupliquer.
- **Étrangères** : `date_id`, `parent_id`, `school_id`, `from_stage_id`,
  `to_stage_id`, `rule_version_id`.

### Index recommandés

| Index | Usage |
|---|---|
| (`parent_id`, `school_id`, `occurred_at`) | Reconstitution d'un parcours |
| (`to_stage_id`, `date_id`) | Entrants par état et par période |
| (`date_id`, `trigger_type`) | Volume quotidien par déclencheur |
| (`is_reactivation`, `date_id`) | Suivi des réactivations |
| (`rule_version_id`) | Recalcul après changement de règle |

### Relations

Vers `calendar`, `parents`, `schools`, `adoption_stages` (**deux fois**),
`adoption_rule_versions`. Alimente `parent_journeys` et `school_daily_snapshots`.

### Fréquence d'alimentation

Deux régimes distincts, à ne pas confondre :

| Déclencheur | Fréquence |
|---|---|
| `payment`, `registration`, `activity` | À la synchronisation, en continu |
| `inactivity_rule` | **Traitement planifié quotidien** |

### Source des données

| Élément | Provenance |
|---|---|
| Transitions vers « inscrit » et « adoptant » | Déduites d'**EcolePay** (compte créé, paiement abouti) |
| Transitions vers « engagé » | **Calcul interne** sur l'activité |
| Transitions vers « à risque » et « perdu » | **Calcul interne** — aucune source ne les annonce |

### Points de vigilance

**Deux des six états n'existent nulle part dans les données sources.** Personne
n'émet d'événement « ce parent est devenu à risque » : c'est un traitement planifié
qui le déduit d'un seuil d'inactivité. D'où `rule_version_id` — sans lui, une
inflexion de courbe ne se distingue pas d'un changement de définition.

**`trigger_type` n'est pas décoratif.** Une transition produite par un paiement
constaté et une transition produite par un franchissement de seuil n'ont pas la même
fiabilité. Les présenter à l'identique dans un rapport serait trompeur.

**Cette table doit rester reconstructible** à partir de `payments` et
`parent_activities`. C'est ce qui permet de rejouer l'historique après un changement
de règle.

**Ne pas y verser l'activité brute.** Un parcours compte cinq ou six transitions dans
toute sa vie ; l'usage se compte en milliers d'événements. Mélanger les deux ferait
exploser le volume d'une table censée rester légère et lisible.

---

## 3. `campaign_contacts`

### Objectif

Tracer chaque message envoyé à chaque parent, avec son cycle de vie de remise et son
coût réel.

### Pourquoi c'est une table de faits

Grain d'événement (un envoi), mesure monétaire (`actual_cost`), mesures de délai
(remise, ouverture, clic), et clés vers `campaigns`, `parents`, `channels`,
`calendar`.

### Grain

**Un message, pour un parent, dans une campagne.**

### Colonnes

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `BIGINT UNSIGNED` | non | Clé de substitution |
| `campaign_id` | `BIGINT UNSIGNED` | non | → `campaigns` |
| `parent_id` | `BIGINT UNSIGNED` | non | → `parents` |
| `school_id` | `BIGINT UNSIGNED` | oui | → `schools`, si la campagne cible un établissement |
| `channel_id` | `TINYINT UNSIGNED` | non | → `channels` |
| `date_id` | `INT UNSIGNED` | non | → `calendar`, date d'envoi |
| `attempt_number` | `TINYINT UNSIGNED` | non | Rang de la tentative |
| `sent_at` | `DATETIME` | oui | |
| `delivered_at` | `DATETIME` | oui | |
| `opened_at` | `DATETIME` | oui | |
| `clicked_at` | `DATETIME` | oui | |
| `failed_at` | `DATETIME` | oui | |
| `delivery_status` | `VARCHAR(20)` | non | `queued`, `sent`, `delivered`, `opened`, `clicked`, `failed`, `bounced` |
| `failure_reason` | `VARCHAR(120)` | oui | |
| `provider_message_id` | `VARCHAR(120)` | oui | Rapprochement avec le prestataire |
| `actual_cost` | `DECIMAL(10,4)` | oui | **Figé à l'envoi** |
| `currency` | `CHAR(3)` | oui | |
| `stage_id_at_send` | `TINYINT UNSIGNED` | oui | → `adoption_stages`, état du parent **au moment du ciblage** |
| `minutes_to_delivery` | `SMALLINT UNSIGNED` | oui | Mesure de performance du canal |
| `is_test` | `BOOLEAN` | non | |
| `created_at` | `DATETIME` | non | |
| `updated_at` | `DATETIME` | non | Mis à jour à chaque accusé |

### Clés

- **Primaire** : `id`.
- **Unicité** : (`campaign_id`, `parent_id`, `attempt_number`) — empêche le double
  envoi.
- **Étrangères** : `campaign_id`, `parent_id`, `school_id`, `channel_id`, `date_id`,
  `stage_id_at_send`.

### Index recommandés

| Index | Usage |
|---|---|
| (`campaign_id`, `delivery_status`) | Tableau de bord d'une campagne |
| (`parent_id`, `sent_at`) | Pression marketing subie par un parent |
| (`date_id`, `channel_id`) | Volume et coût par canal |
| `provider_message_id` | Traitement des accusés entrants |
| (`stage_id_at_send`) | Qualité du ciblage |

### Relations

Vers `campaigns`, `parents`, `schools`, `channels`, `calendar`, `adoption_stages`.
Alimente `campaign_results` et `agg_campaign_performance`.

### Fréquence d'alimentation

**Deux temps distincts :**

1. **À l'envoi** — insertion en temps réel, via la file de traitement.
2. **En différé** — mise à jour par les accusés du prestataire, qui peuvent arriver
   plusieurs heures, voire plusieurs jours plus tard.

### Source des données

| Élément | Provenance |
|---|---|
| Ciblage, envoi, coût, état à l'envoi | **EAC**, natif |
| Remise, ouverture, clic, échec | **Prestataire**, via ETL de rappels HTTP |

### Points de vigilance

**C'est la seule table de faits réellement mutable après insertion.** Les accusés de
remise arrivent en retard, ce qui rompt l'hypothèse habituelle « un fait est
immuable ». Conséquence directe : tout agrégat calculé sur cette table doit être
recalculable sur une fenêtre glissante, sinon les taux d'ouverture restent
sous-évalués pour toujours.

**`stage_id_at_send` est indispensable et non reconstituable.** Sans lui, impossible
de juger a posteriori si une campagne visait juste : l'état du parent aura changé,
éventuellement grâce à la campagne elle-même.

**`school_id` peut être ambigu.** Un parent ayant des enfants dans deux écoles, et
une campagne ciblant un segment plutôt qu'un établissement, laissent la colonne à
`NULL`. C'est acceptable ; la forcer produirait une imputation arbitraire.

---

## 4. `campaign_results`

### Objectif

Porter la **décision d'attribution** : ce contact a-t-il produit une conversion,
une fois la fenêtre d'attribution refermée ?

### Pourquoi c'est une table de faits

Grain explicite (un parent × une campagne), mesures (`days_to_conversion`,
`attributed_amount`), clés dimensionnelles. C'est un instantané cumulatif : la ligne
naît à l'envoi et se complète à la clôture de la fenêtre.

> **Si vous entendiez par « résultats » les totaux par campagne** — envoyés, ouverts,
> conversions, coût par conversion — alors ce n'est pas une table de faits mais une
> **table d'agrégation** (`agg_campaign_performance`), dérivée et reconstructible. Les
> deux sont utiles et ne s'excluent pas ; voir décision ouverte n° 3.

### Grain

**Un parent × une campagne**, évalué après clôture de la fenêtre d'attribution.

### Colonnes

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `BIGINT UNSIGNED` | non | Clé de substitution |
| `campaign_id` | `BIGINT UNSIGNED` | non | → `campaigns` |
| `parent_id` | `BIGINT UNSIGNED` | non | → `parents` |
| `school_id` | `BIGINT UNSIGNED` | oui | → `schools` |
| `contact_id` | `BIGINT UNSIGNED` | oui | → `campaign_contacts`, contact retenu |
| `attribution_window_days` | `SMALLINT UNSIGNED` | non | Recopiée de la campagne à l'évaluation |
| `window_closed_at` | `DATETIME` | non | Fin de la fenêtre |
| `stage_at_send_id` | `TINYINT UNSIGNED` | oui | → `adoption_stages` |
| `stage_at_close_id` | `TINYINT UNSIGNED` | oui | → `adoption_stages` |
| `has_progressed` | `BOOLEAN` | non | Rang d'entonnoir en hausse |
| `converted` | `BOOLEAN` | non | A atteint « adoptant » dans la fenêtre |
| `is_reactivation` | `BOOLEAN` | non | Retour d'un état à risque ou perdu |
| `conversion_date_id` | `INT UNSIGNED` | oui | → `calendar` |
| `days_to_conversion` | `SMALLINT UNSIGNED` | oui | Mesure de latence |
| `attributed_payment_id` | `BIGINT UNSIGNED` | oui | → `payments` |
| `attributed_amount` | `DECIMAL(14,2)` | oui | Montant rattaché |
| `attribution_model` | `VARCHAR(30)` | non | `last_touch`, `first_touch`, `any_touch` |
| `competing_contacts` | `TINYINT UNSIGNED` | oui | Autres campagnes dans la fenêtre |
| `computed_at` | `DATETIME` | non | |
| `computation_version` | `VARCHAR(20)` | non | Version de l'algorithme d'attribution |

### Clés

- **Primaire** : `id`.
- **Unicité** : (`campaign_id`, `parent_id`) — une conclusion par couple.
- **Étrangères** : `campaign_id`, `parent_id`, `school_id`, `contact_id`,
  `conversion_date_id`, `attributed_payment_id`, les deux références d'état.

### Index recommandés

| Index | Usage |
|---|---|
| (`campaign_id`, `converted`) | Taux de conversion d'une campagne |
| (`parent_id`) | Historique d'exposition d'un parent |
| (`conversion_date_id`) | Conversions attribuées par période |
| (`computation_version`) | Repérage des lignes à recalculer |

### Relations

Vers `campaigns`, `parents`, `schools`, `campaign_contacts`, `payments`, `calendar`,
`adoption_stages`. Alimente `agg_campaign_performance` et les analyses IA.

### Fréquence d'alimentation

**Par lots, à la clôture de chaque fenêtre d'attribution.** Une campagne à fenêtre de
14 jours est évaluable 14 jours après son dernier envoi. Un traitement quotidien
balaie les fenêtres échues.

### Source des données

**Calcul interne à 100 %.** Aucune donnée n'est importée ici : la table est produite
par croisement de `campaign_contacts`, `payments` et `adoption_events`.

### Points de vigilance

**Pourquoi séparer des `campaign_contacts` ?** Quatre raisons, chacune suffisante :

| | `campaign_contacts` | `campaign_results` |
|---|---|---|
| Moment d'écriture | À l'envoi | À la clôture de fenêtre |
| Lignée | Observé | Calculé |
| Mutabilité | Mises à jour par accusés | Recalcul complet possible |
| Effet d'un changement de règle | Aucun | Recalcul intégral |

Les fusionner ferait qu'un changement de modèle d'attribution réécrirait des faits de
remise réellement observés. C'est exactement ce qu'il faut éviter.

**Le problème du multi-contact est réel.** Si un parent a reçu trois campagnes avant
de payer, laquelle mérite le crédit ? `attribution_model` rend le choix explicite et
`competing_contacts` signale les cas ambigus. Sans ces deux colonnes, la somme des
conversions attribuées peut dépasser le nombre de conversions réelles — un chiffre
faux et flatteur.

**`computation_version` permet le recalcul sélectif.** Changer d'algorithme n'oblige
pas à tout reprendre : on cible les lignes produites par l'ancienne version.

---

## 5. `parent_journeys`

### Objectif

Répondre en une seule lecture à la quasi-totalité des questions d'entonnoir. C'est la
table de travail des tableaux de bord.

### Pourquoi c'est une table de faits

Instantané cumulatif : une ligne par parent × école, riche en mesures (durées,
compteurs, montants) et référençant les dimensions. Elle est mise à jour plutôt
qu'insérée, ce qui reste une forme de fait — le grain est stable, seules les mesures
évoluent.

### Grain

**Un parent × une école.**

### Colonnes

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `BIGINT UNSIGNED` | non | Clé de substitution |
| `parent_id` | `BIGINT UNSIGNED` | non | → `parents` |
| `school_id` | `BIGINT UNSIGNED` | non | → `schools` |
| `current_stage_id` | `TINYINT UNSIGNED` | non | → `adoption_stages` |
| `rule_version_id` | `SMALLINT UNSIGNED` | oui | → `adoption_rule_versions` |
| **Jalons — dates** | | | *Sept références vers `calendar`* |
| `known_date_id` | `INT UNSIGNED` | oui | Entrée dans l'entonnoir |
| `registered_date_id` | `INT UNSIGNED` | oui | Création du compte |
| `first_payment_date_id` | `INT UNSIGNED` | oui | **Conversion** |
| `last_activity_date_id` | `INT UNSIGNED` | oui | Dernier usage constaté |
| `at_risk_date_id` | `INT UNSIGNED` | oui | Passage à risque |
| `lost_date_id` | `INT UNSIGNED` | oui | Perte |
| `reactivated_date_id` | `INT UNSIGNED` | oui | Dernière réactivation |
| **Jalons — horodatages** | | | |
| `known_at` … `reactivated_at` | `DATETIME` | oui | Précision infra-journalière |
| **Mesures de délai** | | | |
| `days_known_to_registered` | `SMALLINT UNSIGNED` | oui | |
| `days_registered_to_first_payment` | `SMALLINT UNSIGNED` | oui | |
| `days_to_adoption` | `SMALLINT UNSIGNED` | oui | Délai total, connu → adoptant |
| `days_since_last_activity` | `SMALLINT UNSIGNED` | oui | Alimente la règle d'inactivité |
| `days_in_current_stage` | `SMALLINT UNSIGNED` | oui | |
| **Mesures de volume** | | | |
| `payment_count` | `INT UNSIGNED` | non | |
| `successful_payment_count` | `INT UNSIGNED` | non | |
| `failed_payment_count` | `INT UNSIGNED` | non | |
| `total_amount` | `DECIMAL(14,2)` | non | |
| `avg_payment_amount` | `DECIMAL(12,2)` | oui | |
| `activity_event_count` | `INT UNSIGNED` | non | |
| `campaign_contact_count` | `INT UNSIGNED` | non | Pression marketing subie |
| `reactivation_count` | `TINYINT UNSIGNED` | non | |
| **Indicateurs** | | | |
| `is_converted` | `BOOLEAN` | non | A dépassé « adoptant » |
| `is_active` | `BOOLEAN` | non | |
| `has_ever_paid` | `BOOLEAN` | non | |
| `is_test` | `BOOLEAN` | non | |
| **Traçabilité** | | | |
| `first_built_at` | `DATETIME` | non | |
| `last_recomputed_at` | `DATETIME` | non | |

### Clés

- **Primaire** : `id`.
- **Unicité** : (`parent_id`, `school_id`) — **c'est cette contrainte qui matérialise
  le grain**.
- **Étrangères** : `parent_id`, `school_id`, `current_stage_id`, `rule_version_id`,
  et les sept références vers `calendar`.

### Index recommandés

| Index | Usage |
|---|---|
| (`parent_id`, `school_id`) (unique) | Grain, et mise à jour ciblée |
| (`school_id`, `current_stage_id`) | Entonnoir d'une école |
| (`current_stage_id`) | Effectifs nationaux par état |
| (`days_since_last_activity`) | Détection des parents à risque |
| (`first_payment_date_id`) | Cohortes de conversion |
| (`registered_date_id`) | Cohortes d'inscription |
| (`is_converted`, `school_id`) | Taux d'adoption par école |

### Relations

Vers `parents`, `schools`, `adoption_stages`, `adoption_rule_versions`, `calendar`
(sept fois). Alimente `school_daily_snapshots`, les agrégats et `ai_prediction`.

### Fréquence d'alimentation

**Quotidienne**, par reconstruction incrémentale : seules les lignes touchées par un
paiement, une activité ou une transition depuis la veille sont recalculées.
Reconstruction **intégrale** lors d'un changement de version de règle.

### Source des données

**Calcul interne à 100 %.** Table entièrement dérivée de `payments`,
`parent_activities` et `adoption_events`.

### Points de vigilance

**Elle est dérivée, donc jamais source de vérité.** C'est une propriété, pas une
limite : elle peut être détruite et reconstruite à tout moment. Aucune donnée ne doit
y exister sans pouvoir être régénérée.

**Sept références vers `calendar` depuis la même table.** Chaque jalon est une
dimension à rôle multiple. Les nommer explicitement dès la conception est
indispensable : sans cela, les jointures deviennent illisibles et les erreurs
indétectables.

**Le grain parent × école est le choix structurant de tout le schéma.** Un parent
ayant des enfants dans deux écoles peut avoir payé dans l'une et pas dans l'autre. Au
grain « parent » seul, les KPI par école deviennent faux — et le défaut n'est pas
rattrapable sans reconstruction complète.

---

# Tables de faits absentes de la liste

Votre énumération est ouverte, et deux tables manquent. La première est bloquante.

## `parent_activities` — indispensable

**Sans elle, trois des cinq tables ci-dessus ne peuvent pas être calculées.**

Les états « engagé », « à risque » et « perdu » se déduisent de l'inactivité. Or
aucune des cinq tables listées ne porte l'usage : `payments` ne voit que l'argent,
`campaign_contacts` que les messages sortants. `adoption_events.trigger_type =
'inactivity_rule'` n'a aucune source, et `parent_journeys.last_activity_date_id`
reste vide.

| Élément | Valeur |
|---|---|
| **Grain** | Un événement d'usage |
| **Colonnes** | `id`, `date_id`, `parent_id`, `school_id`, `event_type_id`, `occurred_at`, `platform`, `app_version`, `session_reference`, `is_test` |
| **Clés** | Primaire `id` ; étrangères vers `calendar`, `parents`, `schools`, `event_types` |
| **Index** | (`parent_id`, `occurred_at`) ; (`date_id`, `event_type_id`) ; (`school_id`, `date_id`) |
| **Fréquence** | Continue ou horaire |
| **Source** | **EcolePay**, via ETL |

**C'est la table volumineuse du schéma** — la seule dont le volume croît avec l'usage
et non avec la population. Elle appelle un partitionnement mensuel et une rétention
explicite : détail sur 13 mois glissants (permet la comparaison annuelle), agrégats
au-delà.

## `school_daily_snapshots` — fortement recommandée

| Élément | Valeur |
|---|---|
| **Grain** | Une école × un jour |
| **Colonnes** | Effectifs par état (six colonnes), parents actifs, nouvelles inscriptions, nouveaux adoptants, pertes, paiements, montant, taux d'adoption, `rule_version_id` |
| **Fréquence** | Quotidienne, en fin de journée |
| **Source** | Calcul interne, depuis `parent_journeys` |

Elle rend les courbes de tendance instantanées et **immuables** : si une donnée
EcolePay est corrigée trois mois plus tard, la photo du jour reste le reflet de ce qui
était connu ce jour-là — ce qu'on veut pour un rapport déjà présenté en comité.

---

# Synthèse

| Table | Forme | Grain | Croissance | Mutabilité | Source |
|---|---|---|---|---|---|
| `payments` | Transactionnel | Une transaction | Usage | Statuts tardifs | EcolePay |
| `adoption_events` | Transactionnel | Une transition | ~6 par parcours | Reconstructible | Calcul |
| `parent_activities` | Transactionnel | Un événement | **Forte** | Figée | EcolePay |
| `campaign_contacts` | Transactionnel | Un message × un parent | Campagnes | **Mutable** | EAC + prestataire |
| `campaign_results` | Instantané cumulatif | Un parent × une campagne | Campagnes | Recalculable | Calcul |
| `parent_journeys` | Instantané cumulatif | Un parent × une école | Population | Recalculable | Calcul |
| `school_daily_snapshots` | Instantané périodique | Une école × un jour | Temps | Figée | Calcul |

## Chaîne de dépendance

```
   EcolePay ──► payments ───────────┐
            │                       │
            └─► parent_activities ──┤
                                    ├──► adoption_events ──┐
                                    │                      │
                                    └──────────────────────┼──► parent_journeys
                                                           │         │
   EAC ─────► campaign_contacts ───────────────────────────┘         │
                     │                                               │
                     └──► campaign_results ◄────── payments          │
                                                                     ▼
                                                       school_daily_snapshots
                                                                     │
                                                                     ▼
                                                          agrégats & IA
```

Deux niveaux seulement sont irremplaçables : ce qui vient d'EcolePay et ce qui naît
dans EAC. Tout le reste se reconstruit.

## Principes transverses

**Idempotence.** Toute table alimentée depuis EcolePay porte une clé source unique.
Rejouer une synchronisation ne doit jamais créer de doublon.

**Fenêtre de reprise.** Les données arrivent en retard : accusés de remise,
transactions différées, corrections. Aucun traitement ne doit être strictement
incrémental — chacun reprend une fenêtre glissante, sinon les chiffres dérivent en
silence.

**Suppression interdite sur toutes les clés dimensionnelles.** Un fait qui perd son
contexte devient inexploitable, et le perdre silencieusement est pire que d'échouer.

**Partitionnement.** Seules `parent_activities` et `campaign_contacts` le justifient,
par mois sur `date_id` — dont le format `AAAAMMJJ` a précisément été retenu pour cela.

---

# Décisions ouvertes

1. **`adoption_events` couvre-t-elle uniquement les transitions d'état ?** Ce document
   le suppose, et place l'usage brut dans `parent_activities`. Confirmer.
2. **Remboursement d'un premier paiement** — le parent redevient-il « inscrit », ou
   reste-t-il « adoptant » ? Détermine si `adoption_events` accepte une régression
   déclenchée par un remboursement.
3. **`campaign_results` : par parent ou par campagne ?** Par parent, c'est un fait.
   Par campagne, c'est un agrégat. Les deux peuvent coexister.
4. **Modèle d'attribution par défaut** — dernier contact, premier contact, ou tout
   contact ? Conditionne la comparabilité des campagnes entre elles.
5. **Rétention de `parent_activities`** — 13 mois de détail est une proposition, à
   valider au regard du volume réel.
6. **Fraîcheur attendue de `payments`** — horaire ou quotidienne ? Un premier paiement
   détecté le lendemain fait perdre une journée d'action commerciale.
