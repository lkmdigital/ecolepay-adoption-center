# EcolePay Adoption Center — État des lieux & Plan de développement

> Document de référence, tenu à jour à chaque phase. Il décrit **ce qui est fait**,
> **ce qui reste**, et **ce dont j'ai besoin de toi** pour continuer.
> Dernière mise à jour : 2026-07-24.

---

## 0. Décisions figées (le socle)

Ces choix sont arrêtés. Tout le reste s'y conforme.

| Sujet | Décision | Note |
|---|---|---|
| Backend | **Laravel 13.21** (PHP 8.4) | Les docs disaient « Laravel 12 » ; c'est 13 qui est installé, syntaxe identique. |
| Frontend | **Livewire 4 + Flux UI** | ✅ Décision confirmée : on garde l'existant, **pas** React/Inertia. |
| Base analytique | **Schéma en étoile** `dim_*` / `fact_*` / `agg_*` | Clés de substitution, jamais le téléphone en clé de jointure. |
| Confidentialité | **Téléphone haché (HMAC)** + pseudonymisation | Effacement RGPD par pseudonymisation, pas suppression. |
| Base de données | **MySQL 8**, hébergée sur **Hostinger** (créée depuis hPanel) | La BD `ecolepay_adoption_center` existe déjà en local. |
| Architecture code | DDD-léger : `app/Domains/{Domaine}/{Actions,Data,Enums,Livewire,Models,Policies}` | Documenté dans [`docs/Architecture/README.md`](Architecture/README.md). |
| Données EcolePay | **Lecture seule**, synchronisées, jamais modifiées | Deux lignées : synchronisée vs native EAC. |

---

## 1. Ce qui est déjà fait (Phases 1 → 3)

### Phase 1 — Fondations & packages ✅
- Packages : Livewire 4.3, Flux 2.15, Sanctum 4.3, Spatie Permission 8.3, Laravel Excel 3.1, Pulse 1.7, Telescope 5.21 (dev), ECharts 6 (npm).
- Horizon **non installé** (dépend de Redis — voir §4, contrainte d'hébergement).
- Architecture par domaines, résolveurs de policies/factories/layouts personnalisés
  ([`DomainServiceProvider`](../app/Providers/DomainServiceProvider.php)).
- Routes éclatées par domaine (`routes/domains/*.php`), namespaces Livewire par domaine.

### Phase 1bis — Rôles & permissions ✅
- **6 rôles + Développeur**, **29 permissions**, matrice testée
  ([`docs/Architecture/README.md`](Architecture/README.md)).
- Enums typés : [`Role`](../app/Domains/Users/Enums/Role.php),
  [`Permission`](../app/Domains/Users/Enums/Permission.php),
  [`Module`](../app/Domains/Users/Enums/Module.php).
- Gates Telescope/Pulse branchés sur `diagnostics.view`.

### Phase 2 — Schéma de base (44 migrations, 37 tables analytiques) ✅
- **11 dimensions** : `dim_dates`, `dim_schools` (historisée type 2), `dim_parents`,
  `dim_students`, `bridge_student_parents`, `dim_campaigns`, `dim_adoption_stages`,
  `dim_adoption_rule_versions`, `dim_channels`, `dim_payment_methods`, `dim_event_types`.
- **7 faits** : `fact_payments`, `fact_parent_activities`, `fact_adoption_events`,
  `fact_parent_journeys`, `fact_campaign_contacts`, `fact_campaign_results`,
  `fact_school_daily_snapshots`.
- **6 agrégats** : `agg_daily_kpis`, `agg_school_kpis`, `agg_parent_kpis`,
  `agg_campaign_kpis`, `agg_cohort_kpis`, `dashboard_snapshots`.
- **7 tables IA** : `ai_diagnostics`, `ai_recommendations`, `ai_predictions`,
  `ai_model_performance`, `ai_generation_logs`, `ai_feedback`, `generated_reports`.
- **5 tables infrastructure** : `sync_runs`, `sync_watermarks`, `sync_rejects`,
  `source_entity_maps`, `audit_logs`.
- Documentées : [dimensions](Database/dimensions.md), [faits](Database/facts.md),
  [agrégats](Database/aggregates.md), [IA](Database/ai-tables.md).

### Phase 2bis — Modèles Eloquent (36 modèles) ✅
- Un modèle par table, relations, casts, scopes encodant les règles métier.
- Traits partagés : `HasCurrentVersion` (historisation type 2, astuce du NULL),
  `ExcludesTestData` (scope `production()`).
- `User` en dimension conforme + suppression logique.

### Phase 3 — Seeders ✅
- Données de référence : 6 états, 5 canaux, 8 moyens de paiement, 16 types
  d'événements, règle d'adoption v1, calendrier scolaire ivoirien 2023-2035.
- Configuration externalisée dans [`config/eac.php`](../config/eac.php)
  (seuils, calendrier, fenêtres de reprise).
- Jeu de démonstration cohérent (`DemoDataSeeder`) : 25 écoles, 600 parents,
  ~900 paiements, marqués `is_test`.

### Bilan qualité
- **61 tests, 549 assertions, tous verts.**
- Pint propre. Migrations réversibles (rollback + remigration vérifiés).

> ⚠️ **Git** : tout est encore non commité sur `main` (109 fichiers). À committer
> avant d'attaquer la suite — voir la stratégie de branches §6.

---

## 2. Écarts à intégrer (raffinements issus des specs)

Les documents projet précisent des points que le schéma actuel ne couvre pas encore.
Ce sont des **ajouts**, pas des refontes.

| # | À intégrer | Où | Effort |
|---|---|---|---|
| 1 | **Trois taux nommés** : inscription (inscrits/connus), adoption (adoptants/connus, KPI DG), activation (adoptants/inscrits) | Actions de calcul + `agg_*` | Moyen |
| 2 | **Modèle d'abonnement école** (intégré à la scolarité vs payé par le parent) | Colonne sur `dim_schools` | Faible |
| 3 | **Deux flux de revenus** : ticket d'entrée établissement + abonnement parent | `fact_payments` (type) + `dim_schools` | Faible |
| 4 | **Score de santé école** sur 4 niveaux (référence / satisfaisante / fragile / prioritaire) | `agg_school_kpis` + Action | Moyen |
| 5 | **Qualité des contacts de campagne** (`VALID/INVALID/NO_WHATSAPP/DUPLICATE/UNKNOWN`) + suivi d'import | Nouvelles tables opérationnelles | Moyen |
| 6 | **Assistant IA conversationnel** (`ai_conversations`, `ai_messages`) + briefing quotidien | Nouveau domaine AI | Élevé (Sprint 11) |
| 7 | **Vues SQL** de confort (`parent_journey`, `school_dashboard`, `campaign_dashboard`) | Migrations de vues | Faible |
| 8 | **Potentiel de revenus perdus** (revenu si tous adoptaient) | Action de calcul | Faible |

**Point ouvert — rôles** : les specs parlent de **4 profils** (Administrateur,
Commercial, Marketing + 1). On a construit **6 rôles + Développeur**. Recommandation :
**garder les 6**, ils sont un sur-ensemble ; on mappe simplement les 4 profils dessus.
À confirmer.

---

## 3. La connexion à EcolePay (le gros morceau à venir)

C'est le cœur du Sprint 2 et ce qui rend la plateforme réelle. Rien n'est encore
branché : tout tourne aujourd'hui sur le jeu de démonstration.

### Principe
EAC ouvre **deux connexions MySQL** :
- `mysql` → la base EAC (lecture/écriture) — celle qu'on a construite.
- `ecolepay` → la base EcolePay (**lecture seule stricte**).

### Mapping réel des sources (dump `u698699576_ecole`, MariaDB 11.8) ✅ confirmé

| Source EcolePay | Colonnes clés | Alimente EAC |
|---|---|---|
| `tb_ecole` | id, code, nom, email, `abonnement`, actif | `dim_schools` |
| `tb_lkmdigital` | id_ecole, matricule, nom, prenom, classe, **telephone**, **telephone2** | `dim_students`, `bridge_student_parents`, et **source des « parents connus »** |
| `users` | id_user, id_ecole *(varchar)*, nom, prenom, telephone *(text)*, code_famille, status | `dim_parents` (comptes créés) |
| `payer` | id_ecole, id_eleve, id_user, mode_paiement, montant, `statut`, `type`, `abonnement`, `is_manuel`, annee_scolaire | `fact_payments` |
| `tb_subscription` | id_user, id_ecole, montant, statut *(enum)*, date_debut, date_fin | flux abonnement de `fact_payments` |
| `login_history` | user_id, login_time | `fact_parent_activities` (signal d'engagement) |

### 🔴 Découverte critique : l'adoption exclut les paiements manuels

`payer.is_manuel = 1` signifie **paiement espèces / chèque / virement saisi
manuellement par l'école** — le parent n'a **pas** utilisé EcolePay. C'est
exactement le problème « paiements en espèces » du cahier des charges.

> **Règle d'adoption affinée : premier paiement avec `is_manuel = 0` ET `statut = 1`.**
> Un paiement en espèces ne déclenche jamais l'adoption. Il faut néanmoins le
> conserver dans `fact_payments` (revenu réel de l'école) avec un indicateur, mais
> l'exclure de la détection d'adoption et des taux.

### 🟠 Écart : pas de géographie dans la source

`tb_ecole` ne contient **ni ville, ni région, ni zone**. Or les KPI par région/zone
(`agg_*`, `analytics_region`) en dépendent. Trois options : saisie manuelle dans EAC,
dérivation depuis `code`, ou source complémentaire. À trancher — voir §7.

### Autres points relevés
- **Identité parent = téléphone** (stocké en `text`). Un `users` appartient à une
  seule école ; le téléphone est l'identité inter-écoles → valide notre `phone_hash`.
- **« Parent connu »** = numéro présent dans `tb_lkmdigital` (telephone/telephone2)
  sans compte `users` correspondant.
- **Piège de jointure** : `id_ecole` est `int` dans `tb_ecole`/`payer` mais
  `varchar(50)` dans `users` — cast explicite obligatoire.
- **Montants en `int` (XOF)** : pas de décimales, cohérent avec nos `decimal(_,2)`
  (on stockera `.00`).

### Chaîne de synchronisation (déjà conçue, à implémenter)
```
tb_ecole / users / payer / tb_subscription   (EcolePay, lecture seule)
        │  Job nocturne + à la demande, par entité
        ▼
source_entity_maps   ← résout téléphone/id source → clé de substitution
        ▼
dim_* / fact_*        ← upsert idempotent, fenêtre de reprise (config/eac.php)
        ▼
Actions de calcul     → fact_parent_journeys, fact_adoption_events
        ▼
agg_* + snapshots     → tableaux de bord instantanés
```
Les tables `sync_runs` / `sync_watermarks` / `sync_rejects` existent déjà pour
tracer, reprendre et diagnostiquer.

### Sécurité de la connexion
- Utilisateur MySQL EcolePay **en lecture seule** (`GRANT SELECT` uniquement).
- Le téléphone est haché (HMAC) à l'entrée dans `dim_parents` — jamais stocké en
  clair au-delà de ce qui est strictement nécessaire à l'action commerciale.

---

## 4. Hébergement — Hostinger VPS KVM 2 ✅ confirmé

**Décision : VPS KVM 2** (2 vCPU, ~8 Go RAM). L'architecture complète s'applique :
- **Redis** → cache, sessions, files d'attente, Horizon. À installer.
- **Horizon** → à ajouter (`composer require laravel/horizon`) + Supervisor.
- **Scheduler à la minute** (`* * * * *`) → orchestration de la synchro nocturne.
- **Workers en tâche de fond** via Supervisor.

### Reste à clarifier — accès à EcolePay
La base EcolePay est sur MariaDB (`127.0.0.1` dans le dump, hébergement d'origine).
Deux scénarios :
- **EcolePay accessible depuis le VPS** (même réseau, ou accès MySQL distant autorisé
  avec l'IP du VPS whitelistée) → connexion `ecolepay` directe en lecture seule. Idéal.
- **Sinon** → **import de dump nocturne** dans une base miroir sur le VPS, puis
  synchro locale. Plus robuste, découple EAC des incidents EcolePay.

### Note de dimensionnement
KVM 2 suffit **si la synchro reste incrémentale** (watermark + fenêtre de reprise,
déjà conçu). Un recalcul complet des 50 M de paiements serait lent — c'est pourquoi
`agg_*` se recalcule par fenêtre glissante et non intégralement. `fact_parent_activities`
et `fact_payments` devront être partitionnés par mois quand le volume montera.

---

## 5. Modules fonctionnels (13, depuis le cahier des charges)

Dashboard Exécutif · Écoles · Fiche École · Campagnes · Parents · Analytics ·
Rapports · Notifications · Utilisateurs · Paramètres · Audit · Assistant IA ·
Centre d'aide.

Chacun se construit sur les données déjà modélisées : aucune nouvelle table de fond
n'est nécessaire pour les 8 premiers, seulement des Actions de calcul, des composants
Livewire et des écrans Flux.

---

## 6. Plan de développement — sprints réconciliés

Adapté de ta roadmap, corrigé pour **Livewire (pas React)** et pour ce qui est **déjà fait**.

| Sprint | Objet | État | Reste à faire |
|---|---|---|---|
| **0** | Fondation, architecture, packages | ✅ **Fait** | Committer, CI GitHub Actions |
| **1** | Auth, rôles, permissions, profil, logs | 🟡 **Partiel** | Écrans login/profil Flux, `login_history`, activity log |
| **2** | **Data Warehouse — synchro EcolePay** | ⬜ À faire | 2ᵉ connexion, Jobs de sync, mapping sources, Actions de calcul des parcours |
| **3** | Dashboard exécutif | ⬜ | KPI (3 taux), widgets ECharts, cache |
| **4** | Écoles + fiche + score santé | ⬜ | Listes, filtres, score 4 niveaux, classement |
| **5** | Parents | ⬜ | Recherche par n°, timeline, statuts, import |
| **6** | Campagnes | ⬜ | Import CSV/Excel, qualité contacts, ROI, suivi |
| **7** | Analytics | ⬜ | Tous KPI, cohortes, heatmaps, top écoles/régions |
| **8** | Rapports | ⬜ | PDF/Excel/CSV, planification, `report_downloads` |
| **9** | Notifications & alertes | ⬜ | `notifications`, `alerts`, détection d'anomalies |
| **10** | Administration | ⬜ | `settings`, `user_preferences`, `favorites`, audit UI |
| **11** | Assistant IA (chat) | ⬜ | `ai_conversations`/`ai_messages`, briefing quotidien |
| **12** | Optimisation | ⬜ | Cache, index, pagination, responsive |
| **13** | Tests & déploiement Hostinger | ⬜ | Tests archi, déploiement, sauvegardes, monitoring |

### Stratégie Git (depuis ta roadmap)
```
main ← develop ← sprint-N-xxx
```
Une branche par sprint, revue, testée, fusionnée dans `develop`.
**À faire maintenant** : commit initial de tout l'existant sur `develop`.

---

## 7. Ce dont j'ai besoin de toi (entrées bloquantes)

Rien de tout §3-§6 ne peut démarrer sans ces réponses.

### ✅ Résolus
- ~~Schéma EcolePay~~ → fourni (dump `u698699576_ecole`).
- ~~Hébergement~~ → **VPS KVM 2**.

### Bloquant pour le Sprint 2 (connexion EcolePay)
1. **Accès à EcolePay depuis le VPS** : connexion MySQL directe possible (IP du VPS
   autorisée), ou faut-il passer par un **dump nocturne** ? (§4)
2. **Identifiants de lecture seule** côté EcolePay — c'est toi qui les crées et les
   mets dans le `.env` (je ne manipule pas de credentials).

### Nouvelles questions soulevées par le schéma réel
3. **Confirmation `is_manuel`** : un paiement `is_manuel = 1` (espèces) ne compte
   jamais comme adoption — c'est bien l'intention ? (§3)
4. **Géographie des écoles** : `tb_ecole` n'a ni ville ni région ni zone. Comment
   veux-tu peupler les analyses régionales ? (saisie manuelle EAC / dérivation du
   `code` / autre source)
5. **Modèle d'abonnement par école** : comment lire, dans les données, qu'une école
   intègre l'abonnement à la scolarité vs le fait payer au parent ? (colonne
   `tb_ecole.abonnement` ? valeur `payer.abonnement` ?)

### Bloquant pour la justesse métier (inchangé)
6. **Seuils d'inactivité définitifs** (« à risque » / « perdu ») — proposés : 60 / 120 j.
7. **Calendrier scolaire officiel** — le calendrier actuel est une hypothèse ivoirienne.
8. **Rôles** : on garde les 6 + Développeur, ou on réduit aux 4 profils des specs ?

---

## 8. Prochaine étape immédiate recommandée

1. **Committer l'existant** sur `develop` (rien n'est sauvegardé en git à ce jour).
2. **Répondre aux questions §7.1 à §7.4** (schéma EcolePay + type d'hébergement) —
   ce sont les deux verrous du Sprint 2.
3. Dès que le schéma EcolePay est connu : j'écris la **2ᵉ connexion + les Jobs de
   synchronisation + le mapping**, et on remplace le jeu de démonstration par les
   vraies données.

En attendant tes réponses, je peux avancer **sans risque** sur ce qui n'en dépend
pas : intégrer les raffinements KPI (§2.1), le champ modèle d'abonnement (§2.2), les
Actions de calcul des parcours, et les écrans d'authentification Flux (Sprint 1).
