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
