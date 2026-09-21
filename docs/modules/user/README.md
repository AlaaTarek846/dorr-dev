# User Module

**Path:** `Modules/User/`  
**Namespace:** `Modules\User\`  
**Last updated:** 2026-09-20  
**Purpose:** End-user authentication, registration, profile, social login, AI chat, and user management (admin-facing CRUD).

---

## Components

| Layer | Classes |
|-------|---------|
| Model | `User` |
| Controllers | `UserAuthController`, `UserRegistrationController`, `UserPasswordResetController`, `UserProfileController`, `UserSocialAuthController`, `UserController` |
| Services | `UserService` |
| Repositories | `UserRepository` |
| Routes | `admin.php` (admin API users CRUD), `dashboard.php` (user SPA API), OAuth in `routes/web.php` |

---

## Frontend

- SPA: `/user` → `resources/js/apps/user/user-app.js`
- Router base: `/user` → `resources/js/router/user-index.js`
- Routes: `resources/js/modules/user/routes.js` (`resolveShell('user')`, `resolvePage('user', …)`)
- Themed views: `resources/js/modules/user/themes/{path}/views/` (default: `theme-1`)
- Shell: `resources/js/layouts/themes/{path}/UserShell.vue` — includes **AI Chat** navigation
- Auth store: `stores/userAuth.js` (`user_token`)
- Axios: `api/userAxios.js`
- Blade: `resources/views/user.blade.php` → `dashboard/shell.blade.php`

---

## User vs Provider portal

| Feature | User (`/user`) | Provider (`/provider`) |
|---------|----------------|------------------------|
| Auth flows (login, register, verify, password) | Yes | Yes (parallel API under `/api/provider/v1`) |
| Profile | Yes | Yes |
| OAuth Google/Apple | `/auth/user/{provider}/redirect` | `/auth/provider/{provider}/redirect`; **shared** callback URI `/auth/user/{provider}/callback` |
| AI Chat | Yes (`/user/chat`, `/api/user/v1/ai-chat/*`) | **No** |
| Service header / multi-service sidebar | No | Yes (`services[]` on provider payload) |
| Admin CRUD of accounts | `/api/admin/v1/users*` | `/api/admin/v1/providers*` |
| Token key | `user_token` | `provider_token` |
| Guard | `user_api` | `provider_api` |
| i18n prefix | `user_dashboard.*` (and shared keys) | `provider_dashboard.*` |

See [Provider module README](../provider/README.md) for provider portal details.

---

## Special Flows

- Email OTP registration → password creation
- Forgot/reset password with flow tokens
- Google/Apple OAuth via web redirect (callback delegates to Provider when `social_auth_panel=provider`)

See [API.md](./API.md).
