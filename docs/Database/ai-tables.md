# Dictionnaire des tables d'intelligence artificielle

> Spécification de conception. Voir [`facts.md`](facts.md) et
> [`aggregates.md`](aggregates.md) pour les sources qu'elles consomment.

## Quatre principes préalables

### 1. Une production IA n'est jamais source de vérité

Un diagnostic est une **opinion sur des données**, pas une donnée. Si un chiffre
n'existe que dans un texte généré, il est invérifiable. Toute affirmation produite ici
doit être reconstituable depuis les faits et les agrégats.

### 2. Reproductibilité : quatre éléments, ou aucun

Rejouer une production suppose de connaître **les entrées, la version du prompt, le
modèle et ses paramètres**. Trois sur quatre ne suffisent pas : les mêmes données avec
un modèle différent donnent un autre texte. Ces quatre colonnes accompagnent donc
chaque production.

### 3. Ne jamais figer de donnée personnelle dans une charge utile

C'est le défaut de conformité le plus fréquent de ce type de tables, et le plus
difficile à rattraper.

Lorsqu'un parent exerce son droit à l'effacement, `parents` est **pseudonymisée** —
mais un instantané JSON figé six mois plus tôt conserve son nom et son numéro. La
donnée effacée survit dans une colonne que personne ne pense à balayer.

> **Règle : les instantanés stockent des agrégats et des clés de substitution, jamais
> des identifiants en clair.** Conserver `parent_id`, pas le nom ni le téléphone.
> La pseudonymisation se propage alors d'elle-même, par jointure.

### 4. Distinguer le cycle de vie du fichier de celui de l'enregistrement

Un rapport exporté est deux choses : un fichier, et la trace qu'un utilisateur a
extrait ces données à cette date. Le fichier peut être supprimé ; **la trace doit
survivre**, la permission `audit.view` reposant dessus.

---

## 1. `ai_diagnostics`

### Objectif

Conserver chaque analyse produite, avec de quoi l'expliquer et la reproduire des mois
plus tard, alors que les données sous-jacentes auront changé.

### Grain

**Une analyse générée.**

### Colonnes

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `BIGINT UNSIGNED` | non | |
| `uuid` | `CHAR(36)` | non | Référence publique |
| `diagnostic_type` | `VARCHAR(40)` | non | `adoption_analysis`, `campaign_review`, `anomaly_detection`, `school_health` |
| `scope_type` | `VARCHAR(20)` | non | `global`, `region`, `school`, `campaign`, `parent_segment` |
| `scope_id` | `VARCHAR(64)` | oui | Identifiant du périmètre |
| `scope_label` | `VARCHAR(180)` | non | Libellé figé, reste lisible si l'entité change de nom |
| `period_start_date_id` | `INT UNSIGNED` | oui | → `calendar` |
| `period_end_date_id` | `INT UNSIGNED` | oui | → `calendar` |
| `title` | `VARCHAR(200)` | non | |
| `summary` | `TEXT` | oui | Synthèse courte, affichée en liste |
| `body` | `MEDIUMTEXT` | oui | Analyse complète |
| `structured_output` | `JSON` | oui | Constats sous forme exploitable |
| `confidence` | `DECIMAL(4,3)` | oui | Indice de confiance déclaré |
| **Reproductibilité** | | | |
| `input_snapshot` | `JSON` | non | **Métriques exactes ayant servi** |
| `input_watermark_at` | `DATETIME` | non | Fraîcheur des données utilisées |
| `prompt_version` | `VARCHAR(20)` | non | |
| `model_name` | `VARCHAR(60)` | non | |
| `model_parameters` | `JSON` | oui | Température, longueur maximale… |
| `generation_log_id` | `BIGINT UNSIGNED` | oui | → `ai_generation_logs` |
| **Cycle de vie** | | | |
| `status` | `VARCHAR(20)` | non | `pending`, `completed`, `failed`, `superseded` |
| `superseded_by_id` | `BIGINT UNSIGNED` | oui | → `ai_diagnostics`, version remplaçante |
| `is_pinned` | `BOOLEAN` | non | Épinglé : exclu de toute purge |
| `published_at` | `DATETIME` | oui | Diffusé hors de la plateforme |
| `view_count` | `INT UNSIGNED` | non | |
| `last_viewed_at` | `DATETIME` | oui | |
| `requested_by_user_id` | `BIGINT UNSIGNED` | oui | → `users` |
| `created_at`, `updated_at` | `DATETIME` | non | |

### Relations

- **Vers** `calendar`, `users`, `ai_generation_logs`, et elle-même (`superseded_by_id`).
- **Depuis** `ai_recommendations`, `ai_feedback`, `generated_reports`.
- Le périmètre est décrit par un couple (`scope_type`, `scope_id`) sans clé étrangère :
  il désigne indifféremment une école, une campagne ou un segment.

### Durée de conservation

| Cas | Durée |
|---|---|
| Épinglé ou publié | **Indéfinie** — archive d'entreprise |
| Ayant produit une recommandation acceptée | Indéfinie, par rattachement |
| Consulté au moins une fois | 24 mois |
| Généré et jamais consulté | 6 mois |
| En échec (`status = failed`) | 90 jours |

### Stratégie d'archivage

Trois phases, la volumétrie tenant presque entièrement dans deux colonnes
(`body` et `input_snapshot`) :

| Phase | Âge | Traitement |
|---|---|---|
| **Chaude** | 0–12 mois | Intégralement en base |
| **Tiède** | 12–36 mois | `body` et `input_snapshot` déplacés vers le stockage objet, référencés par chemin ; métadonnées, `summary` et `structured_output` conservés en base |
| **Froide** | > 36 mois | Métadonnées seules ; purge si ni épinglé, ni publié, ni rattaché à une recommandation |

La liste et la recherche restant servies par les métadonnées et le `summary`, le
déplacement du corps est invisible tant que l'utilisateur n'ouvre pas l'analyse.

### Points de vigilance

**`input_snapshot` est la colonne qui donne sa valeur à la table.** Une recommandation
doit rester explicable alors que les données ont changé. Sans les métriques exactes
qui l'ont produite, un diagnostic devient une affirmation invérifiable — précisément
ce qu'on ne veut pas présenter en comité.

**`scope_label` est dénormalisé volontairement.** Une école renommée, ou dont la ligne
courante a changé du fait de l'historisation, ne doit pas rendre illisible une analyse
d'il y a un an.

**Le remplacement plutôt que l'écrasement.** Relancer une analyse ne modifie pas
l'ancienne : elle passe en `superseded` et pointe vers la nouvelle. Un utilisateur
ayant cité l'ancienne version doit pouvoir la retrouver.

---

## 2. `ai_recommendations`

### Objectif

Transformer un diagnostic en action suivie, puis **mesurée**. C'est cette table qui
permet de savoir si la fonctionnalité IA produit réellement de l'adoption.

### Grain

**Une recommandation.**

### Colonnes

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `BIGINT UNSIGNED` | non | |
| `uuid` | `CHAR(36)` | non | |
| `diagnostic_id` | `BIGINT UNSIGNED` | oui | → `ai_diagnostics` |
| `source` | `VARCHAR(20)` | non | `ai`, `rule`, `manual` |
| `scope_type` | `VARCHAR(20)` | non | |
| `scope_id` | `VARCHAR(64)` | oui | |
| `scope_label` | `VARCHAR(180)` | non | |
| `action_type` | `VARCHAR(40)` | non | `launch_campaign`, `contact_school`, `add_payment_method`, `train_staff`, `investigate` |
| `title` | `VARCHAR(200)` | non | |
| `rationale` | `TEXT` | non | Justification |
| `priority` | `TINYINT UNSIGNED` | non | 1 à 5 |
| `effort_estimate` | `VARCHAR(20)` | oui | `low`, `medium`, `high` |
| **Impact attendu** | | | |
| `expected_metric` | `VARCHAR(60)` | oui | Indicateur visé |
| `expected_value` | `DECIMAL(12,4)` | oui | Valeur cible |
| **Décision** | | | |
| `status` | `VARCHAR(20)` | non | `new`, `accepted`, `rejected`, `in_progress`, `done`, `expired` |
| `rejection_reason` | `VARCHAR(200)` | oui | |
| `assigned_to_user_id` | `BIGINT UNSIGNED` | oui | → `users` |
| `decided_by_user_id` | `BIGINT UNSIGNED` | oui | → `users` |
| `decided_at` | `DATETIME` | oui | |
| `due_at` | `DATETIME` | oui | |
| `completed_at` | `DATETIME` | oui | |
| `expires_at` | `DATETIME` | oui | Péremption automatique |
| **Boucle de retour** | | | |
| `linked_campaign_id` | `BIGINT UNSIGNED` | oui | → `campaigns`, si elle a donné lieu à une campagne |
| `outcome_status` | `VARCHAR(20)` | oui | `pending`, `successful`, `partial`, `unsuccessful`, `inconclusive` |
| `metric_before` | `DECIMAL(12,4)` | oui | Valeur avant action |
| `metric_after` | `DECIMAL(12,4)` | oui | Valeur après action |
| `outcome_measured_at` | `DATETIME` | oui | |
| `outcome_notes` | `TEXT` | oui | |
| `created_at`, `updated_at` | `DATETIME` | non | |

### Relations

- **Vers** `ai_diagnostics`, `users` (deux fois), `campaigns`.
- **Depuis** `ai_feedback`.
- `linked_campaign_id` permet de rapprocher une recommandation de `campaign_kpis` et
  d'en mesurer le rendement réel.

### Durée de conservation

**Cinq ans au minimum, sans exception pour les recommandations décidées.**

Le volume est faible — quelques milliers de lignes — et la valeur analytique
s'apprécie avec le temps : mesurer si les recommandations IA fonctionnent suppose un
historique long. Les archiver serait une économie de quelques mégaoctets contre la
perte du seul indicateur de qualité du dispositif.

| Cas | Durée |
|---|---|
| Acceptée, réalisée ou rejetée | 5 ans |
| Expirée sans décision | 24 mois |

### Stratégie d'archivage

**Aucun archivage à court terme.** La table reste intégralement en base.

Au-delà de cinq ans, les lignes closes peuvent être versées dans une table
d'historique de même structure, uniquement pour alléger les index de la table active.
Une purge n'est envisageable que pour les recommandations expirées sans décision.

### Points de vigilance

**`expires_at` est indispensable.** Une recommandation portant sur une école
désormais partie est du bruit. Sans péremption, la liste se remplit de conseils
obsolètes et cesse d'être consultée — l'échec le plus courant de ce type de
fonctionnalité.

**`metric_before` et `metric_after` figés.** Recalculer la valeur de départ après
coup ferait disparaître l'effet mesuré. Ces deux nombres sont capturés au moment de
la décision et de la mesure, jamais dérivés à la lecture.

**`source` distingue l'IA du reste.** Toutes les recommandations ne viennent pas d'un
modèle ; certaines relèvent d'une règle métier simple. Les mélanger empêcherait
d'évaluer l'apport propre de l'IA.

---

## 3. `ai_predictions`

### Objectif

Porter les scores calculés par lot — risque de départ, propension à convertir — et
permettre a posteriori de **mesurer la justesse du modèle**.

### Grain

**Une cible × un modèle × un cycle de calcul.**

### Colonnes

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `BIGINT UNSIGNED` | non | |
| `target_type` | `VARCHAR(20)` | non | `parent`, `school`, `campaign` |
| `target_id` | `BIGINT UNSIGNED` | non | Clé de la cible |
| `school_id` | `BIGINT UNSIGNED` | oui | → `schools`, dénormalisé pour filtrer |
| `model_key` | `VARCHAR(40)` | non | `churn_risk`, `conversion_propensity`, `payment_delay` |
| `model_version` | `VARCHAR(20)` | non | |
| `scored_at` | `DATETIME` | non | |
| `scored_date_id` | `INT UNSIGNED` | non | → `calendar`, clé de partitionnement |
| **Prédiction** | | | |
| `score` | `DECIMAL(6,5)` | oui | 0 à 1 |
| `score_band` | `VARCHAR(20)` | non | `low`, `medium`, `high`, `critical` |
| `predicted_value` | `DECIMAL(12,2)` | oui | Ex. jours avant conversion |
| `horizon_days` | `SMALLINT UNSIGNED` | non | Portée de la prédiction |
| `top_features` | `JSON` | oui | Facteurs explicatifs, pour restitution |
| **Suivi de variation** | | | |
| `previous_score` | `DECIMAL(6,5)` | oui | |
| `score_delta` | `DECIMAL(6,5)` | oui | |
| `is_full_snapshot` | `BOOLEAN` | non | Cycle complet mensuel, ou variation |
| `is_current` | `BOOLEAN` | oui | `1` ou `NULL` — un score courant par cible et modèle |
| **Résolution** | | | |
| `resolution_status` | `VARCHAR(20)` | non | `pending`, `resolved`, `unresolvable` |
| `resolved_at` | `DATETIME` | oui | À l'échéance de l'horizon |
| `actual_outcome` | `BOOLEAN` | oui | Ce qui s'est réellement produit |
| `actual_value` | `DECIMAL(12,2)` | oui | |
| `is_correct` | `BOOLEAN` | oui | Prédiction vérifiée |
| `created_at` | `DATETIME` | non | |

### Relations

- **Vers** `calendar`, `schools`.
- La cible est désignée par (`target_type`, `target_id`), sans clé étrangère : elle
  pointe selon les cas vers `parents`, `schools` ou `campaigns`.
- **Alimente** `ai_model_performance` et le ciblage de campagnes.

### Durée de conservation

C'est **la table volumineuse** du dispositif. À 100 000 parents scorés chaque jour,
elle produirait 36 millions de lignes par an — davantage que toute autre table du
schéma.

Deux mesures ramènent ce volume à une fraction :

1. **N'enregistrer que les variations significatives.** Un score qui ne bouge pas
   n'apporte rien. Seuil proposé : variation absolue supérieure à 0,05.
2. **Un instantané complet mensuel**, marqué `is_full_snapshot`, qui garantit une
   photo exhaustive à intervalle régulier.

Le volume attendu passe alors de 36 millions à environ 3 à 5 millions de lignes par
an.

| Cas | Durée |
|---|---|
| Prédictions résolues | 24 mois — nécessaires à la mesure d'exactitude |
| Prédictions non résolues, hors instantané | 13 mois |
| Instantanés complets mensuels | 5 ans |
| Score courant (`is_current`) | Jamais purgé tant que la cible existe |

### Stratégie d'archivage

**Partitionnement mensuel sur `scored_date_id`** — le format `AAAAMMJJ` de `calendar`
a précisément été retenu pour permettre cela sans colonne supplémentaire.

| Phase | Âge | Traitement |
|---|---|---|
| **Chaude** | 0–13 mois | Partitions actives |
| **Consolidation** | 13 mois | Les métriques d'exactitude sont versées dans `ai_model_performance` **avant** toute suppression |
| **Purge** | > 13 mois | Suppression de partition entière, hors instantanés mensuels et lignes courantes |

La suppression par partition est instantanée, là où un effacement ligne à ligne sur
plusieurs millions d'enregistrements bloquerait la base.

### Points de vigilance

**Conserver l'historique est ce qui rend le modèle évaluable.** Écraser le score
précédent rendrait impossible toute mesure de justesse : évaluer un modèle suppose de
comparer ce qu'il prédisait *avant* à ce qui s'est produit *après*. Une table ne
gardant que le score courant ne s'audite pas.

**`is_current` en `1` / `NULL`.** Même mécanisme que pour les dimensions historisées :
MySQL n'appliquant pas l'unicité aux `NULL`, un index unique sur
(`target_type`, `target_id`, `model_key`, `is_current`) garantit un seul score courant
par cible et par modèle, et évite une sous-requête à chaque lecture.

**`top_features` sert la restitution, pas le calcul.** Présenter un score de risque
sans ses facteurs explicatifs le rend inactionnable : le Commercial a besoin de savoir
*pourquoi* ce parent est à risque pour savoir quoi lui dire.

**Un changement de `model_version` n'invalide pas l'historique.** Les anciennes
prédictions restent nécessaires à la comparaison entre versions.

---

## 4. `generated_reports`

### Objectif

Conserver la trace des rapports produits et diffusés : qui a généré quoi, à partir de
quelles données, et ce qui est sorti de la plateforme.

> **Périmètre plus large que l'IA.** Votre matrice de permissions distingue
> `reports.generate` de `ai.generate` : tous les rapports ne sont pas rédigés par un
> modèle. Cette table couvre les deux, `is_ai_generated` faisant la distinction.

### Grain

**Un rapport généré.**

### Colonnes

| Colonne | Type | Null | Description |
|---|---|:-:|---|
| `id` | `BIGINT UNSIGNED` | non | |
| `uuid` | `CHAR(36)` | non | |
| `report_key` | `VARCHAR(60)` | non | `adoption_monthly`, `school_review`, `campaign_report` |
| `title` | `VARCHAR(200)` | non | |
| `scope_type` | `VARCHAR(20)` | non | |
| `scope_id` | `VARCHAR(64)` | oui | |
| `scope_label` | `VARCHAR(180)` | non | |
| `period_start_date_id` | `INT UNSIGNED` | oui | → `calendar` |
| `period_end_date_id` | `INT UNSIGNED` | oui | → `calendar` |
| `parameters` | `JSON` | oui | Filtres appliqués — permet de régénérer |
| **Fichier** | | | |
| `format` | `VARCHAR(10)` | non | `pdf`, `xlsx`, `csv`, `html` |
| `storage_disk` | `VARCHAR(30)` | oui | Disque de stockage |
| `file_path` | `VARCHAR(255)` | oui | `NULL` une fois le fichier purgé |
| `file_size_bytes` | `INT UNSIGNED` | oui | |
| `file_hash` | `BINARY(32)` | oui | Intégrité et dédoublonnage |
| `file_deleted_at` | `DATETIME` | oui | Fichier supprimé, enregistrement conservé |
| **Contenu** | | | |
| `row_count` | `INT UNSIGNED` | oui | Volume de données extrait |
| `includes_personal_data` | `BOOLEAN` | non | **Détermine la classe de rétention** |
| `data_watermark_at` | `DATETIME` | non | Fraîcheur des données au moment du tirage |
| `is_ai_generated` | `BOOLEAN` | non | |
| `ai_diagnostic_id` | `BIGINT UNSIGNED` | oui | → `ai_diagnostics` |
| **Production** | | | |
| `generation_status` | `VARCHAR(20)` | non | `queued`, `generating`, `completed`, `failed` |
| `generation_duration_ms` | `INT UNSIGNED` | oui | |
| `error_message` | `VARCHAR(255)` | oui | |
| `requested_by_user_id` | `BIGINT UNSIGNED` | oui | → `users` |
| `generated_at` | `DATETIME` | oui | |
| **Diffusion** | | | |
| `distribution` | `JSON` | oui | Destinataires |
| `download_count` | `INT UNSIGNED` | non | |
| `last_downloaded_at` | `DATETIME` | oui | |
| **Rétention** | | | |
| `retention_class` | `VARCHAR(20)` | non | `transient`, `standard`, `archival` |
| `expires_at` | `DATETIME` | oui | |
| `created_at`, `updated_at` | `DATETIME` | non | |

### Relations

- **Vers** `users`, `calendar`, `ai_diagnostics`.
- Périmètre décrit par (`scope_type`, `scope_id`).
- **Lue par** le journal d'audit, la permission `audit.view` reposant en partie sur
  cette table.

### Durée de conservation

Deux horloges distinctes, et c'est le point structurant :

| | Fichier | Enregistrement |
|---|---|---|
| `transient` — export ponctuel | 30 jours | 24 mois |
| `standard` — rapport récurrent | 12 mois | 5 ans |
| `archival` — comité, conseil | 5 ans | **Indéfinie** |
| Contenant des données personnelles | **90 jours maximum** | Indéfinie |
| En échec | Sans objet | 90 jours |

### Stratégie d'archivage

1. **Le fichier part en stockage froid** à l'échéance de sa classe, ou est supprimé.
   `file_deleted_at` est renseignée, `file_path` mise à `NULL`.
2. **L'enregistrement demeure**, y compris pour un fichier détruit. Il porte alors la
   trace : qui, quoi, quand, combien de lignes, contenait ou non des données
   personnelles.
3. **`parameters` permet la régénération.** Un rapport standard dont le fichier a été
   purgé peut être reproduit à l'identique tant que les données sources existent — ce
   qui justifie une rétention de fichier courte.
4. Les rapports comportant des données personnelles sont **purgés en priorité** :
   c'est la principale surface d'exposition de la plateforme, un fichier exporté
   échappant à tout contrôle d'accès une fois téléchargé.

### Points de vigilance

**Ne jamais stocker le fichier en base.** Un `BLOB` de plusieurs mégaoctets par
rapport alourdit les sauvegardes, la réplication et la moindre lecture de la table.
Le fichier va sur disque objet, la base ne porte qu'un chemin.

**`data_watermark_at` évite un malentendu coûteux.** Un rapport tiré à 9 h sur des
données synchronisées à 3 h ne reflète pas la matinée. Sans cette information imprimée
sur le document, un écart avec l'écran temps réel passe pour une erreur.

**`includes_personal_data` doit être calculé, pas déclaré.** L'indicateur se déduit
des colonnes réellement exportées, non d'une case cochée par l'utilisateur.

---

# Compléments nécessaires

## `ai_generation_logs`

**Objectif.** Maîtriser le coût et diagnostiquer les incidents.

**Colonnes.** Modèle, version de prompt, jetons entrants et sortants, latence, coût,
statut, message d'erreur, nombre de tentatives, utilisateur demandeur, type de
production visée, horodatage.

**Pourquoi distincte d'`ai_diagnostics`.** Les appels en échec et les reprises n'ont
produit aucune analyse mais ont produit un coût. Les journaliser à part garde la table
métier propre tout en rendant la facture lisible.

**Conservation.** 13 mois de détail, agrégats mensuels de coût conservés
indéfiniment. Purge par partition mensuelle.

## `ai_feedback`

**Objectif.** Recueillir le jugement humain sur une production.

**Colonnes.** Élément concerné (diagnostic ou recommandation), utilisateur, jugement
d'utilité, motif, commentaire libre, date.

**Conservation.** Même durée que l'élément jugé. Volume négligeable.

## `ai_model_performance`

**Objectif.** Survivre à la purge d'`ai_predictions`.

**Colonnes.** Modèle, version, période, effectif évalué, taux de justesse, précision,
rappel, répartition par tranche de score, date de calcul.

**Pourquoi.** Les métriques d'exactitude doivent être consolidées **avant** la
suppression des partitions de prédictions. Sans cette table, purger les prédictions
détruirait l'historique de qualité des modèles.

**Conservation.** Indéfinie — quelques centaines de lignes par an.

---

# Ce qui sort de la plateforme

Un prompt adressé à un modèle externe est une transmission de données à un tiers.
Trois règles s'imposent :

1. **Les prompts transportent des agrégats**, pas des listes nominatives. « 340
   parents à risque dans cette école » est légitime ; la liste de leurs numéros ne
   l'est pas.
2. **Toute transmission est journalisée** dans `ai_generation_logs`, avec la nature
   des données envoyées.
3. **`input_snapshot` conserve la même discipline** que le prompt : clés de
   substitution et agrégats, jamais d'identifiants en clair.

Cette discipline a un effet secondaire précieux : elle rend la pseudonymisation d'un
parent **automatiquement effective** dans toutes les productions IA passées, sans
balayage ni réécriture.

---

# Synthèse

| Table | Volume annuel | Conservation détail | Archivage | Purgeable |
|---|---|---|---|---|
| `ai_diagnostics` | Milliers | 12 mois | Corps en stockage objet à 12 mois | Si ni épinglé ni publié |
| `ai_recommendations` | Milliers | 5 ans | Aucun | Expirées sans décision |
| `ai_predictions` | 3–5 millions | 13 mois | Partitions mensuelles | Oui, par partition |
| `generated_reports` | Milliers | Fichier 30 j–5 ans | Fichier en froid, trace conservée | **Fichier oui, trace jamais** |
| `ai_generation_logs` | Dizaines de milliers | 13 mois | Agrégats de coût | Oui, par partition |
| `ai_feedback` | Centaines | Suit l'élément jugé | Aucun | Non |
| `ai_model_performance` | Centaines | Indéfinie | Aucun | Non |

**Ordre de purge.** `ai_model_performance` se calcule **avant** la purge
d'`ai_predictions`. Inverser détruirait l'historique de qualité des modèles sans
qu'aucune erreur ne soit levée.

---

# Décisions ouvertes

1. **Fournisseur de modèle et localisation des traitements.** Détermine ce qui peut
   figurer dans un prompt, et si un engagement de non-conservation côté fournisseur
   est nécessaire.
2. **Seuil de variation de score** — 0,05 est proposé. Trop bas, le volume explose ;
   trop haut, des dégradations progressives passent inaperçues.
3. **Horizon des prédictions de risque** — 30, 60 ou 90 jours. Doit s'aligner sur les
   seuils d'`adoption_rule_versions`, faute de quoi le modèle prédirait un événement
   défini différemment de celui qu'il observe.
4. **Rétention des rapports contenant des données personnelles** — 90 jours est
   proposé pour le fichier. À valider avec les obligations applicables.
5. **Un diagnostic épinglé est-il opposable ?** S'il peut être cité dans une décision
   contractuelle, sa conservation relève de l'archivage légal et non d'une politique
   applicative.
6. **Budget mensuel d'appels au modèle** — conditionne la fréquence de génération
   automatique et l'opportunité d'un plafond bloquant dans
   `ai_generation_logs`.
