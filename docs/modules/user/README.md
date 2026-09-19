# User Module

**Path:** `Modules/User/`  
**Namespace:** `Modules\User\`  
**Last updated:** 2026-09-17  
**Purpose:** End-user authentication, registration, profile, social login, AI chat, and user management (admin-facing CRUD).

---

## Components

| Layer | Classes |
|-------|---------|
| Model | `User` |
| Controllers | `UserAuthController`, `UserRegistrationController`, `UserPasswordResetController`, `UserProfileController`, `UserSocialAuthController`, `UserController` |
| Services | `UserService` |
| Repositories | `UserRepository` |
| Routes | `admin.php` (admin API CRUD), `dashboard.php` (user SPA API), OAuth in `routes/web.php` |

---

## Frontend

- SPA: `/user` → `resources/js/apps/user/user-app.js`
- Router base: `/user`
- Auth store: `stores/userAuth.js` (`user_token`)
- Axios: `api/userAxios.js`
- Layout: `UserLayout.vue` — includes **AI Chat** navigation

---

## User vs Provider portal

| Feature | User (`/user`) | Provider (`/provider`) |
|---------|----------------|------------------------|
| Auth flows (login, register, verify, password) | Yes | Yes (parallel API under `/api/provider/v1`) |
| Profile | Yes | Yes |
| OAuth Google/Apple | `/auth/user/...` | `/auth/provider/...` redirect; **shared** callback URI |
| AI Chat | Yes (`/api/user/v1/ai-chat/*`) | **No** |
| Admin CRUD of accounts | `/api/admin/v1/users*` | `/api/admin/v1/providers*` |
| Token key | `user_token` | `provider_token` |
| Guard | `user_api` | `provider_api` |

See [Provider module README](../provider/README.md) for provider portal details.

---

## Special Flows

- Email OTP registration → password creation
- Forgot/reset password with flow tokens
- Google/Apple OAuth via web redirect (callback may delegate to Provider when `social_auth_panel=provider`)

See [API.md](./API.md).
