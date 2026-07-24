# Architecture

## Principe

Le code est découpé par **domaine métier**, pas par type technique. Un développeur
qui travaille sur les campagnes trouve tout dans `app/Domains/Campaigns` au lieu de
naviguer entre `app/Models`, `app/Http/Controllers` et `app/Services`.

## Arborescence

```
app/
├── Domains/              Le métier. Un dossier = un domaine.
│   ├── Dashboard/
│   ├── Schools/
│   ├── Parents/
│   ├── Campaigns/
│   ├── Analytics/
│   ├── AI/
│   ├── Reports/
│   ├── Users/
│   └── Settings/
│
├── Infrastructure/       Le monde extérieur : API tierces, passerelles, adaptateurs.
│   ├── AI/               Clients LLM.
│   ├── Messaging/        SMS, WhatsApp, e-mail.
│   ├── Payments/         Passerelles de paiement.
│   ├── Export/           Adaptateurs Excel / PDF.
│   └── Persistence/      Accès données spécifiques (vues SQL, requêtes lourdes).
│
├── Shared/               Briques *métier* réutilisées par plusieurs domaines.
│   ├── Concerns/         Traits métier.
│   ├── Contracts/        Interfaces transverses.
│   ├── Data/             DTO partagés.
│   ├── Models/           Modèles de base.
│   └── ValueObjects/     Montant, NuméroTéléphone, etc.
│
└── Support/              Utilitaires *techniques*, sans métier.
    ├── Enums/
    ├── Helpers/
    └── Macros/
```

La distinction `Shared` / `Support` : si la classe parlerait à un responsable
métier, elle va dans `Shared`. Sinon, `Support`.

## Contenu d'un domaine

```
app/Domains/Schools/
├── Actions/     Cas d'usage. Une classe, une responsabilité, invocable.
├── Data/        DTO. Structures figées passées aux vues.
├── Enums/       Statuts, types.
├── Livewire/    Composants Livewire à classe (si un SFC ne suffit pas).
├── Models/      Modèles Eloquent.
└── Policies/    Autorisations, à côté du modèle qu'elles protègent.
```

Ajoutez `Events/`, `Jobs/`, `Listeners/`, `Exceptions/`, `Http/` au besoin —
inutile de les créer vides à l'avance.

## Vues

```
resources/views/
├── layouts/       Layouts. `layouts::app` (Livewire) et `<x-layouts::app>` (Blade).
├── components/    Composants Blade anonymes partagés.
├── pages/         Pages Livewire hors domaine.
├── dashboard/     Un dossier par domaine, en minuscules.
├── schools/
└── ...
```

Chaque dossier de domaine est enregistré comme **namespace Livewire** dans
[`config/livewire.php`](../../config/livewire.php) :

```blade
<livewire:schools::index />
<livewire:campaigns::create-campaign />
```

## Routes

Un fichier par domaine dans `routes/domains/`, chargés automatiquement par la
boucle en fin de [`routes/web.php`](../../routes/web.php). Ajouter un domaine ne
demande aucune modification de `web.php`.

```php
// routes/domains/schools.php
Route::livewire('/schools', 'schools::index')->name('schools.index');
```

## Ce que le DomainServiceProvider répare

Laravel déduit certains noms de classes en supposant `App\Models\`. Cette
convention n'existant plus ici, [`DomainServiceProvider`](../../app/Providers/DomainServiceProvider.php)
réenregistre trois résolutions :

| Résolution | Correspondance |
|---|---|
| Policies | `App\Domains\Schools\Models\School` → `App\Domains\Schools\Policies\SchoolPolicy` |
| Factories | `App\Domains\Users\Models\User` ↔ `Database\Factories\Users\UserFactory` |
| Layouts | `resources/views/layouts` → `<x-layouts::app>` |

Les factories suivent donc l'arborescence des domaines :

```
database/factories/
└── Users/
    └── UserFactory.php     namespace Database\Factories\Users
```

## Conventions à respecter

**Un domaine n'appelle pas les `Models` d'un autre domaine directement.** Il passe
par une `Action` du domaine cible. Cela garde les frontières lisibles quand le
projet grossit.

**Les DTO ne sont pas des propriétés publiques Livewire.** Livewire ne sait pas
les sérialiser et lève `Property type not supported`. Utilisez une propriété
calculée (voir [`resources/views/dashboard/index.blade.php`](../../resources/views/dashboard/index.blade.php))
ou faites implémenter `Livewire\Wireable` au DTO s'il doit vraiment persister
entre deux requêtes.

**`Parent` est un mot réservé de PHP.** Le modèle du domaine `Parents` ne peut pas
s'appeler `Parent` — utilisez `Guardian`, `ParentProfile` ou équivalent. Le
namespace `App\Domains\Parents` reste valide.

## Rôles et permissions

La matrice est déclarée **une seule fois**, dans les enums du domaine `Users` :

| Fichier | Rôle |
|---|---|
| [`Permission.php`](../../app/Domains/Users/Enums/Permission.php) | Les 29 permissions, une par cas d'enum. |
| [`Role.php`](../../app/Domains/Users/Enums/Role.php) | Les 6 rôles et la liste de permissions de chacun. |
| [`Module.php`](../../app/Domains/Users/Enums/Module.php) | Regroupement pour l'affichage. |

[`RolePermissionSeeder`](../../database/seeders/Users/RolePermissionSeeder.php) se
contente de reporter cette matrice en base. Il est idempotent : relancez-le après
toute modification des enums.

```bash
php artisan db:seed --class="Database\\Seeders\\Users\\RolePermissionSeeder"
```

Dans le code, référencez les enums plutôt que des chaînes :

```php
$user->can(Permission::CampaignsSend->value);

// Blade
@can(Permission::SchoolsExport->value) ... @endcan
```

### Matrice

👁️ voir · ✏️ modifier · ➕ créer · 🗑️ supprimer · 📤 exporter · ▶️ exécuter

| Permission | Super Admin | Développeur | Direction | Marketing | Commercial | Support | Analyste |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `dashboard.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `schools.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `schools.update` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `schools.export` | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ |
| `parents.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `parents.export` | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ |
| `campaigns.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `campaigns.create` | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `campaigns.update` | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `campaigns.delete` | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `campaigns.send` | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `analytics.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ |
| `analytics.export` | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ |
| `reports.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ |
| `reports.generate` | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ |
| `reports.export` | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ |
| `ai.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ |
| `ai.generate` | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ |
| `users.view` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `users.create` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `users.update` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `users.delete` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `roles.view` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `roles.manage` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `settings.view` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `settings.update` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `audit.view` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `diagnostics.view` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `diagnostics.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Total** | **29** | **27** | **15** | **17** | **8** | **4** | **13** |

Les écoles et les parents viennent d'EcolePay : `schools.create`, `schools.delete`,
`parents.create` et `parents.delete` n'existent pas. Une donnée qu'on ne crée pas
ici ne doit pas avoir de permission de création.

### Le rôle Développeur

Défini **par soustraction** dans [`Role.php`](../../app/Domains/Users/Enums/Role.php) :
il reçoit toute permission existante sauf `roles.manage` et `settings.update`.
Ajouter une permission plus tard la lui accorde automatiquement — c'est voulu,
un développeur doit pouvoir exercer toutes les fonctionnalités.

`diagnostics.view` pilote réellement l'accès aux outils :

- [`TelescopeServiceProvider::gate()`](../../app/Providers/TelescopeServiceProvider.php) → `viewTelescope`
- [`AppServiceProvider::boot()`](../../app/Providers/AppServiceProvider.php) → `viewPulse`

Les deux restent ouverts en environnement `local` pour ne pas imposer une
connexion en développement ; hors local, la permission fait foi.

⚠️ **`users.create` sans `roles.manage` reste une porte d'escalade.** Le formulaire
de création d'utilisateur devra gater l'attribution des rôles sur `roles.manage`,
sinon un développeur pourra se créer un compte Super Admin. Spatie ne protège pas
`assignRole()` tout seul.

[`RolePermissionTest`](../../tests/Feature/Users/RolePermissionTest.php) verrouille
la matrice entière, y compris les interdictions (le support n'exporte rien, le
commercial ne génère ni n'exporte, l'analyste n'écrit rien, seul le Super Admin
reconfigure la plateforme).

**Piège Spatie :** `syncPermissions()` résout les noms depuis un cache. Sur une
base vierge, il faut appeler `forgetCachedPermissions()` *entre* la création des
permissions et leur rattachement aux rôles, sinon le premier passage lève
`PermissionDoesNotExist`.

## Exemple de bout en bout

Le domaine `Dashboard` sert de référence : route → composant Livewire namespacé →
`Action` → `Data`, sans modèle intermédiaire.

- [`routes/domains/dashboard.php`](../../routes/domains/dashboard.php)
- [`resources/views/dashboard/index.blade.php`](../../resources/views/dashboard/index.blade.php)
- [`app/Domains/Dashboard/Actions/BuildAdoptionSummary.php`](../../app/Domains/Dashboard/Actions/BuildAdoptionSummary.php)
- [`app/Domains/Dashboard/Data/AdoptionSummary.php`](../../app/Domains/Dashboard/Data/AdoptionSummary.php)
