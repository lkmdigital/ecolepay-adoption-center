# Déploiement par import de dépôt Git (Railway / Render / Coolify / Dokploy…)

L'app se déploie via son **Dockerfile** (à la racine). Toute plateforme qui
« importe un dépôt Git » et sait construire un Dockerfile fonctionne.

> ⚠️ **Base de données : MySQL/MariaDB obligatoire.** L'app utilise du SQL
> spécifique MySQL (`DATE_FORMAT`, etc.). Les plateformes qui n'offrent que du
> PostgreSQL (ex. Render managed DB) ne conviennent pas telles quelles — il faut
> alors brancher une MySQL externe. **Railway, Coolify, Dokploy, DigitalOcean**
> proposent MySQL nativement.

---

## 1. Créer la base MySQL sur la plateforme
Ajoute une ressource **MySQL** (8.x) et récupère : hôte, port, base, utilisateur, mot de passe.

## 2. Importer le dépôt
Nouvelle app → **Deploy from GitHub** → repo `lkmdigital/ecolepay-adoption-center`, branche `main`.
La plateforme détecte le **Dockerfile** et construit l'image (PHP 8.4 + nginx, assets compilés, port dynamique `$PORT`).

## 3. Variables d'environnement à définir

**À générer d'abord** (en local ou dans une console) :
```bash
# Clé d'application Laravel
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
# Clé HMAC des téléphones (à fixer UNE fois pour toutes)
php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"
```

| Variable | Valeur |
|---|---|
| `APP_NAME` | `Adoption Center` |
| `APP_ENV` | `production` |
| `APP_KEY` | la clé `base64:…` générée |
| `APP_DEBUG` | `false` |
| `APP_URL` | l'URL publique fournie par la plateforme (domaine généré) |
| `APP_LOCALE` | `fr` |
| `APP_TIMEZONE` | `Africa/Abidjan` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | la MySQL de l'étape 1 |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE` | `true` si l'URL est en HTTPS (cas général), sinon `false` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `database` |
| `EAC_PHONE_HASH_KEY` | la valeur hex générée |
| `LOG_CHANNEL` | `stderr` (recommandé en conteneur) |

**Source EcolePay (pour la synchro des vraies données — optionnel au 1ᵉʳ déploiement) :**

| Variable | Valeur |
|---|---|
| `ECOLEPAY_DB_HOST` / `ECOLEPAY_DB_PORT` | MySQL prod EcolePay |
| `ECOLEPAY_DB_DATABASE` | `u698699576_ecole` |
| `ECOLEPAY_DB_USERNAME` / `ECOLEPAY_DB_PASSWORD` | utilisateur **SELECT only** |

> La prod EcolePay (mutualisée) doit autoriser l'**IP de sortie** de la plateforme
> (MySQL distant). Sur les PaaS managés, cette IP peut être variable : privilégier
> une plateforme à IP de sortie fixe, ou un import par dump.

**KATIA (optionnel) :** `ANTHROPIC_API_KEY` — sinon la clé se saisit dans l'UI (Paramètres › KATIA).

## 4. Déployer
Lance le déploiement. Au démarrage, le conteneur exécute **automatiquement** :
`package:discover` → `migrate --force` → `config/route/view:cache` → sert l'app.

## 5. Une seule fois après le 1ᵉʳ déploiement (console/shell de la plateforme)
```bash
# Données de référence (rôles, permissions, calendrier…)
php artisan db:seed --force

# Créer le compte administrateur
php artisan tinker
```
```php
$u = new App\Domains\Users\Models\User();
$u->forceFill([
    'name' => 'Votre Nom', 'email' => 'admin@exemple.com',
    'password' => bcrypt('MOT_DE_PASSE'), 'is_active' => true,
    'job_title' => 'Direction', 'department' => 'Direction',
])->save();
$u->assignRole('super-admin');
```

## 6. (Quand l'accès source est prêt) charger les vraies données EcolePay
```bash
php artisan eac:sync all       # écoles → roster → comptes → paiements
php artisan eac:compute journeys
```
Automatisé ensuite par le planificateur (`routes/console.php`) si la plateforme
lance un cron `php artisan schedule:run` chaque minute (worker/cron séparé).

---

## Notes par plateforme
- **Railway / Render / Fly** : détectent le Dockerfile, injectent `PORT` → l'app écoute dessus (géré). Render : prévoir une **MySQL externe** (managed = Postgres).
- **Coolify / Dokploy** (auto-hébergés) : « New Resource → Application → Dockerfile », ajoutent une base MySQL, exposent un domaine (sslip.io). Idéal pour un VPS déjà en place.
- **PHP** : ne pas laisser la plateforme choisir 8.5 — le Dockerfile fige déjà **8.4**.
