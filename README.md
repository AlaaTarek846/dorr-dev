# Dorr

Laravel 12 + Vue 3 platform with three dashboard SPAs (Admin + User + Provider), modular architecture, shared catalog layer, AI chat (User), and provider management.

---

## Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP ^8.2, Laravel ^12, Sanctum, nwidart/laravel-modules |
| Frontend | Vue ^3.5, Pinia, Vue Router, PrimeVue (admin), vue-i18n |
| Packages | Spatie Media Library, Spatie Permission, Socialite |

---

## Modules

| Module | Purpose |
|--------|---------|
| **General** (`app/.../General/`) | Shared catalog: countries, flags, languages, currencies, service categories, platform settings |
| **Admin** | Admin auth + admin management |
| **User** | User auth, registration, profile, social login |
| **AI** | AI provider config + user chat |
| **Provider** | Business provider profiles (admin CRUD + `/provider` self-service SPA, no AI chat) |

---

## Quick Start

```bash
composer setup    # install, .env, migrate, npm build
composer dev      # server + queue + logs + vite
composer test     # PHPUnit
```

| URL | App |
|-----|-----|
| `/admin` | Admin dashboard SPA |
| `/user` | User dashboard SPA |
| `/provider` | Provider dashboard SPA |
| `/api/admin/v1/*` | Admin API |
| `/api/user/v1/*` | User API |
| `/api/provider/v1/*` | Provider portal API |

See [docs/14-DEPLOYMENT.md](./docs/14-DEPLOYMENT.md) for full setup.

---

## Documentation

Professional documentation system for developers and AI assistants:

### Core

| Document | Description |
|----------|-------------|
| [01-PRODUCT-REQUIREMENTS.md](./docs/01-PRODUCT-REQUIREMENTS.md) | Product requirements |
| [02-PRODUCT-SPECIFICATION.md](./docs/02-PRODUCT-SPECIFICATION.md) | How the product works |
| [03-TECHNICAL-SPECIFICATION.md](./docs/03-TECHNICAL-SPECIFICATION.md) | Technical implementation |
| [04-ARCHITECTURE.md](./docs/04-ARCHITECTURE.md) | System architecture |
| [05-DATA-MODEL.md](./docs/05-DATA-MODEL.md) | Database schema |
| [06-API-SPECIFICATION.md](./docs/06-API-SPECIFICATION.md) | API reference |
| [07-IMPLEMENTATION-PLAN.md](./docs/07-IMPLEMENTATION-PLAN.md) | Feature workflow |
| [08-ROADMAP.md](./docs/08-ROADMAP.md) | Roadmap |
| [09-DECISIONS.md](./docs/09-DECISIONS.md) | Architecture decisions |
| [10-CHANGELOG.md](./docs/10-CHANGELOG.md) | Changelog |
| [11-CHECKPOINT.md](./docs/11-CHECKPOINT.md) | Current project state |
| [12-TESTING.md](./docs/12-TESTING.md) | Testing guide |
| [13-SECURITY.md](./docs/13-SECURITY.md) | Security |
| [14-DEPLOYMENT.md](./docs/14-DEPLOYMENT.md) | Deployment |
| [15-CONTRIBUTING.md](./docs/15-CONTRIBUTING.md) | Contributing |
| [16-STYLEGUIDE.md](./docs/16-STYLEGUIDE.md) | Coding standards |
| [AI-INSTRUCTIONS.md](./docs/AI-INSTRUCTIONS.md) | **AI assistants — read first** |

### Module Docs

| Module | README |
|--------|--------|
| General (shared catalog) | [docs/modules/general/README.md](./docs/modules/general/README.md) |
| Admin | [docs/modules/admin/README.md](./docs/modules/admin/README.md) |
| User | [docs/modules/user/README.md](./docs/modules/user/README.md) |
| AI | [docs/modules/ai/README.md](./docs/modules/ai/README.md) |
| Provider | [docs/modules/provider/README.md](./docs/modules/provider/README.md) |

---

## For AI Assistants

**Automatic enforcement:** Cursor loads [`.cursor/rules/`](./.cursor/rules/) on every session. Other agents should read [AGENTS.md](./AGENTS.md) first.

Before changing code, read:

1. [AGENTS.md](./AGENTS.md) — quick agent entry point
2. [docs/AI-INSTRUCTIONS.md](./docs/AI-INSTRUCTIONS.md) — full 9-step workflow
3. [docs/11-CHECKPOINT.md](./docs/11-CHECKPOINT.md)
4. [docs/04-ARCHITECTURE.md](./docs/04-ARCHITECTURE.md)

The codebase is the source of truth. Do not invent features or APIs.

---

## License

MIT (Laravel framework components). See project license terms as applicable.
