# Deployment

> Documents what exists in the repository. Gaps marked **TODO** or **NEEDS-DECISION**.

---

## Local Development

### Requirements

- PHP ^8.2 with extensions required by Laravel 12
- Composer
- Node.js + npm
- MySQL (default in `.env.example`) or SQLite

### Quick Setup

```bash
composer setup
```

Runs: `composer install`, `.env` copy, `key:generate`, `migrate`, `npm install`, `npm run build`

### Development Server (all services)

```bash
composer dev
```

Runs concurrently:
- `php artisan serve`
- `php artisan queue:listen`
- `php artisan pail` (logs)
- `npm run dev` (Vite on `127.0.0.1:5173`)

### Manual Setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
npm install
npm run dev   # or npm run build
php artisan serve
```

### Local URLs

| URL | Purpose |
|-----|---------|
| `http://localhost:8000/admin` | Admin SPA |
| `http://localhost:8000/user` | User SPA |
| `http://127.0.0.1:5173` | Vite HMR (dev) |

**Note:** Laragon users may use virtual host (e.g., `dorr.test`) — adjust `APP_URL` accordingly.

### Laragon / Apache on Windows: always run `php artisan config:cache`

Apache on Windows (`mpm_winnt`) serves requests on threads, and reading `.env` per request is not thread-safe there. Two requests at the same moment (the mobile chat sends "stopped typing" and the message together) can lose the environment. One of them then runs as `production` on the default `sqlite` database and answers 500. In the log this shows as `production.ERROR: Database file at path [...database.sqlite] does not exist`. The fix is to cache the config, so `.env` isn't read per request:

```bash
php artisan config:cache      # again after every .env change
```

Code must read settings through `config()`, never `env()` outside `config/` (a cached config makes `env()` return null). Tests are unaffected: `phpunit.xml` points `APP_CONFIG_CACHE` at a file that doesn't exist, so they always load fresh config on the in-memory database. For that reason `composer test` no longer runs `config:clear`. As a last line of defence, `tests/TestCase.php` refuses to start (before the first query) unless the app is on the in-memory SQLite and booted from this project's folder. On 2026-10-03, a test run from a second checkout that shared this `vendor` folder booted this project with its cached config and `RefreshDatabase` wiped the dev database. It was restored from the MySQL binary log (`mysqlbinlog --rewrite-db … --stop-position`). Never point tests at a real database, and never share `vendor` between checkouts.

### Phone over ngrok / LAN

- `TRUSTED_PROXIES=127.0.0.1,::1` in `.env` (ngrok connects from this machine), so the server sees the phone's real IP (`App\Http\Middleware\TrustProxies` → `config('app.trusted_proxies')`).
- The Android app's backend host is per developer, set in `androidApp/local.properties` (not committed): `dorr.apiHost=<your-tunnel>.ngrok-free.dev` and `dorr.apiScheme=https` (`http` for a LAN IP).
- Uploaded files (chat photos, voice notes, stories) are served through `public/storage`. If it is missing, every media URL answers 403 and voice notes and stories don't play: run `php artisan storage:link` once per machine.
- After pulling a new module (`Modules/*`), run `composer dump-autoload`, or every request fails with `Class "Modules\…\Providers\…ServiceProvider" not found`.

---

## Environment Configuration

**Template:** `.env.example`

Critical groups:
- `APP_*` — application identity and debug
- `DB_*` — database connection
- `MAIL_*` — mail for OTP/notifications
- `GOOGLE_*`, `APPLE_*` — OAuth
- `AUTH_OTP_*`, `AUTH_FLOW_TOKEN_*` — verification flows
- `AI_GROQ_SEED_API_KEY` — optional AI seeder
- `VITE_APP_NAME` — frontend env

**Never commit `.env` to version control.**

---

## Build Process

### Backend

```bash
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Frontend

```bash
npm ci
npm run build
```

Vite outputs to `public/build/` (Laravel Vite plugin).

---

## Database

### Migrations

```bash
php artisan migrate --force
```

Includes:
- `database/migrations/`
- Module migrations (auto-loaded by modules)

### Seeders

```bash
php artisan db:seed
```

General seeders in `database/seeders/General/`.

**TODO:** Document full seed order and production seeding policy.

---

## Storage

```bash
php artisan storage:link
```

Media files via Spatie Media Library stored on configured disk.

---

## Queues

Default (`.env.example`): `QUEUE_CONNECTION=database`

```bash
php artisan queue:work
```

Dev uses `queue:listen` via `composer dev`.

**Note:** No custom jobs implemented yet — queue infrastructure ready.

---

## Scheduler

**TODO** — no scheduled tasks confirmed in `routes/console.php` during analysis.

```bash
php artisan schedule:work   # local
# Cron: * * * * * php artisan schedule:run
```

---

## Cache

Default (`.env.example`): `CACHE_STORE=database`

```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

---

## Production Setup

**NEEDS-DECISION** — production hosting not documented in repo.

### General Laravel Checklist

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] Strong `APP_KEY`
- [ ] HTTPS enabled
- [ ] Database backups configured
- [ ] Queue worker supervised (systemd, Horizon, etc.)
- [ ] Log rotation configured
- [ ] OAuth redirect URIs updated for production domain

---

## Deployment Steps

**TODO** — define project-specific deployment pipeline.

Suggested manual flow:

1. Pull latest code
2. `composer install --no-dev --optimize-autoloader`
3. `npm ci && npm run build`
4. `php artisan migrate --force`
5. `php artisan config:cache && php artisan route:cache`
6. Reload PHP-FPM / restart workers
7. Verify `/up` health endpoint

---

## Rollback

**TODO** — document rollback procedure.

Suggested:
1. Redeploy previous release tag
2. `php artisan migrate:rollback` (if migration reversible — prefer backup restore for production)
3. Clear caches

---

## Health Checks

| Endpoint | Purpose |
|----------|---------|
| `GET /up` | Laravel health check |

**TODO:** Add DB/cache connectivity checks if needed.

---

## Docker / Sail

Laravel Sail included in dev dependencies.

**TODO:** Document if team uses Sail (`./vendor/bin/sail up`).
