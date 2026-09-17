# User — Requirements

## Implemented

### User-facing
- Login/logout/check-token/me
- Register → verify email (OTP) → create password
- Resend verification OTP with cooldown
- Forgot/reset password
- Profile update + password change
- Social login (Google, Apple)
- Countries dropdown (authenticated)
- AI chat access (via AI module routes)

### Admin-facing
- User CRUD from admin panel (`UserController` in admin routes)
- Status management (Active, Inactive, Blocked)
- Bulk delete

## Business Rules

- Password nullable until registration complete
- `UserStatus` enum: Active, Inactive, Blocked
- Verification codes polymorphic on User
- Social accounts linked polymorphically
- User has `HasRoles` trait (Spatie) — roles not enforced on routes

## NEEDS-DECISION

- Email verification required before login policy
- Phone verification (VerificationType exists)
- User self-registration approval workflow
