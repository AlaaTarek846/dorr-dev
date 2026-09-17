# User Module

**Path:** `Modules/User/`  
**Namespace:** `Modules\User\`  
**Purpose:** End-user authentication, registration, profile, social login, and user management (admin-facing CRUD).

---

## Components

| Layer | Classes |
|-------|---------|
| Model | `User` |
| Controllers | `UserAuthController`, `UserRegistrationController`, `UserPasswordResetController`, `UserProfileController`, `UserSocialAuthController`, `UserController` |
| Services | `UserService` |
| Repositories | `UserRepository` |
| Routes | `routes/dashboard.php` (user API), `routes/web.php` |

---

## Frontend

- SPA: `/user` → `resources/js/modules/user/`
- Auth store: `stores/userAuth.js`
- Axios: `api/userAxios.js`

---

## Special Flows

- Email OTP registration → password creation
- Forgot/reset password with flow tokens
- Google/Apple OAuth via web redirect

See [API.md](./API.md).
