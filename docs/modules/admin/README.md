# Admin Module

**Path:** `Modules/Admin/`  
**Namespace:** `Modules\Admin\`  
**Purpose:** Platform administrator authentication, admin account management, and admin API route mounting for shared catalog.

---

## Components

| Layer | Classes |
|-------|---------|
| Model | `Admin` |
| Controllers | `AdminAuthController`, `AdminController`, `AdminProfileController` |
| Services | `AdminService` |
| Repositories | `AdminRepository` |
| Routes | `routes/admin.php` (primary API), `routes/web.php` (stub) |

---

## Frontend

- SPA: `/admin` → `resources/js/modules/admin/`
- Auth store: `stores/auth.js`
- Axios: `api/adminAxios.js`

---

## Key Routes

- `POST /api/admin/v1/login`
- Admin CRUD: `/api/admin/v1/admins/*`
- Also mounts: General catalog, User CRUD (cross-module), AI providers, Providers

See [API.md](./API.md).
