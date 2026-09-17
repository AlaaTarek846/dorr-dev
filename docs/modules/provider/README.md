# Provider Module

**Path:** `Modules/Provider/`  
**Namespace:** `Modules\Provider\`  
**Purpose:** Business service provider profiles managed by admins.

---

## Components

| Layer | Classes |
|-------|---------|
| Models | `Provider`, `ProviderService` |
| Controller | `ProviderController` |
| Service | `ProvidersService` |
| Repository | `ProviderRepository` |
| Routes | `routes/admin.php` |

---

## Frontend

- Admin: `/admin/providers` → `views/provider/index.vue` + modal
- Composable: `useProviders.js`
- Store: `stores/providers.js`

**No user-facing provider portal exists.**

See [API.md](./API.md).
