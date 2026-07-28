# Déploiement — EcolePay Adoption Center (EAC)

Runbook de mise en production sur **VPS Hostinger (KVM)**, avec synchronisation
depuis la **base EcolePay** (hébergement mutualisé, lecture seule).

> ⚠️ Les commandes serveur et la saisie des identifiants se font **par vous**.
> Ne committez jamais un `.env` rempli. La base EcolePay est lue en **SELECT
> uniquement** — EAC ne doit jamais y écrire.

---

## 0. Prérequis sur le VPS

- PHP **8.3+** avec extensions : `pdo_mysql`, `mbstring`, `bcmath`, `intl`, `gd`, `zip`, `curl`, `openssl`.
- **MySQL/MariaDB**, **nginx**, **composer**, **Node 20+** (pour compiler les assets), **git**, **certbot**.
- Un nom de domaine pointant vers l'IP du VPS (ex. `adoption.votre-domaine.ci`).
- Accès SSH au VPS.

Notez l'**IP publique du VPS** (`curl -s ifconfig.me`) : elle devra être autorisée côté EcolePay.

---

## 1. Récupérer le code

```bash
sudo mkdir -p /var/www/eac && sudo chown $USER:$USER /var/www/eac
git clone <url-du-repo> /var/www/eac
cd /var/www/eac
git checkout main            # ou la branche de release
```

## 2. Dépendances + assets

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build                # compile public/build (Vite)
```

## 3. Configuration `.env`

```bash
cp .env.production.example .env
php artisan key:generate
# Clé HMAC des téléphones (à fixer UNE fois pour toutes) :
php -r "echo bin2hex(random_bytes(32));"     # → collez la valeur dans EAC_PHONE_HASH_KEY
nano .env                                    # remplissez APP_URL, DB_*, ECOLEPAY_DB_*, EAC_PHONE_HASH_KEY
```

## 4. Base de données EAC (l'entrepôt) + utilisateur applicatif

```sql
CREATE DATABASE ecolepay_adoption_center CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'eac_app'@'localhost' IDENTIFIED BY 'MOT_DE_PASSE_FORT';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON ecolepay_adoption_center.* TO 'eac_app'@'localhost';
FLUSH PRIVILEGES;
```

Renseignez `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` dans `.env`.

## 5. Connexion à la source EcolePay (lecture seule)

1. **Côté EcolePay (hPanel de l'hébergement mutualisé)** : `Bases MySQL > MySQL distant` → **autoriser l'IP du VPS**.
2. Créez (ou récupérez) un utilisateur MySQL **SELECT uniquement** sur la base EcolePay.
3. Renseignez `ECOLEPAY_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD` dans `.env`.
4. Testez la connexion :

```bash
php artisan tinker --execute="echo app(App\Infrastructure\EcolePay\EcolePaySource::class)->isReachable() ? 'OK source joignable' : 'INJOIGNABLE';"
```

> Si « INJOIGNABLE » : vérifiez le whitelisting de l'IP, l'hôte/port, et que l'utilisateur a bien accès.

## 6. Migrations + données de référence

```bash
php artisan migrate --force
php artisan db:seed --force            # ReferenceDataSeeder : rôles, permissions, calendrier…
```

> `db:seed` charge **uniquement** la donnée de référence (pas les données de démo `is_test`).

## 7. Première synchronisation depuis EcolePay

```bash
php artisan eac:sync all               # écoles → roster → comptes → paiements
php artisan eac:compute journeys       # recalcule les parcours d'adoption
```

Vérifiez le résultat (volumes) dans la sortie, ou :

```bash
php artisan tinker --execute="echo 'écoles='.DB::table('dim_schools')->count().' parents='.DB::table('dim_parents')->count();"
```

## 8. Créer le premier administrateur

```bash
php artisan tinker
```
```php
$u = new App\Domains\Users\Models\User();
$u->forceFill([
    'name' => 'Votre Nom', 'email' => 'admin@votre-domaine.ci',
    'password' => bcrypt('MOT_DE_PASSE_ADMIN'), 'is_active' => true,
    'job_title' => 'Direction', 'department' => 'Direction',
])->save();
$u->assignRole('super-admin');   // les rôles viennent du seed de l'étape 6
```

## 9. Permissions fichiers + optimisation

```bash
sudo chown -R www-data:www-data /var/www/eac/storage /var/www/eac/bootstrap/cache
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 10. nginx + HTTPS

`/etc/nginx/sites-available/eac` :

```nginx
server {
    listen 80;
    server_name adoption.votre-domaine.ci;
    root /var/www/eac/public;

    index index.php;
    charset utf-8;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/eac /etc/nginx/sites-enabled/eac
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d adoption.votre-domaine.ci     # SSL Let's Encrypt
```

## 11. Cron (planificateur) + worker de file

**Cron du planificateur** (indispensable pour la synchro automatique de nuit) :

```bash
crontab -e
```
```
* * * * * cd /var/www/eac && php artisan schedule:run >> /dev/null 2>&1
```

**Worker de file** (optionnel tant qu'aucun job asynchrone n'est utilisé) — service systemd `/etc/systemd/system/eac-queue.service` :

```ini
[Unit]
Description=EAC queue worker
After=network.target

[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/eac/artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```
```bash
sudo systemctl enable --now eac-queue
```

## 12. Vérifications post-déploiement

- [ ] `https://adoption.votre-domaine.ci/login` s'affiche (HTTPS valide).
- [ ] Connexion avec le compte admin créé (étape 8).
- [ ] Dashboard : les KPI reflètent les **vrais** volumes (pas les données de démo).
- [ ] `php artisan schedule:list` montre `eac:sync all` planifié.
- [ ] (Optionnel) Paramètres > KATIA : coller la clé API Anthropic pour activer l'assistant.

---

## Mises à jour ultérieures

```bash
cd /var/www/eac
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

## Rappels de sécurité

- `APP_DEBUG=false`, `APP_ENV=production`.
- Utilisateur MySQL EcolePay = **SELECT only**. EAC n'écrit jamais dans la source.
- `EAC_PHONE_HASH_KEY` fixe et secret (les empreintes téléphone en dépendent).
- Sauvegardes : dump quotidien de `ecolepay_adoption_center` (l'entrepôt est reconstructible par re-synchro, mais les utilisateurs, favoris, rapports et conversations KATIA, non).
