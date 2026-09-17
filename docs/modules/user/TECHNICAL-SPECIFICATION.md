# User — Technical Specification

## Model: `Modules\User\Models\User`

- Table: `users` (core migration + module updates)
- Authenticatable + Sanctum + HasRoles (Spatie)
- Traits: `HasSocialAccounts`, `HasVerificationCodes`
- `belongsTo` Country
- Casts: `UserStatus`, `Gender`
- Media: avatar

## Auth Services (app layer)

- `VerificationCodeService` — OTP generation/validation
- `AuthFlowTokenService` — multi-step flow tokens
- `SocialAuthService` — OAuth user creation/linking

## Registration Flow

1. `UserRegistrationController@register`
2. Sends verification code email
3. `verifyEmail` validates OTP
4. `createPassword` sets password
5. User can login

## Social Auth

Web routes in `routes/web.php`:
- `/auth/user/{google|apple}/redirect`
- `/auth/user/{provider}/callback`

Handled by `UserSocialAuthController` → redirects to user SPA `/oauth/callback`

## User CRUD (Admin)

`UserController` mounted in Admin routes — same CRUD pattern as Admin/Provider entities.

## Frontend

Views: Login, SignUp, VerifyEmail, CreatePassword, ForgotPassword, ResetPassword, OAuthCallback, Dashboard, Profile, Chat

Validation: Vuelidate on profile; auth pages use manual/API validation
