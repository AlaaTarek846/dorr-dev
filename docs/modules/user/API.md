# User — API

Base: `/api/user/v1` (middleware: `locale`)

## Guest

| Method | Endpoint | Controller |
|--------|----------|------------|
| POST | `/login` | UserAuthController |
| POST | `/check-token` | UserAuthController |
| POST | `/register` | UserRegistrationController |
| POST | `/verify-email` | UserRegistrationController |
| POST | `/resend-verification` | UserRegistrationController |
| POST | `/create-password` | UserRegistrationController |
| POST | `/forgot-password` | UserPasswordResetController |
| POST | `/reset-password` | UserPasswordResetController |

## Authenticated (auth:user_api)

| Method | Endpoint |
|--------|----------|
| GET | `/countries/dropdown` |
| GET | `/me` |
| POST | `/logout` |
| POST | `/profile` |
| PUT | `/profile/password` |

## Admin-managed Users

See [../admin/API.md](../admin/API.md) — `/api/admin/v1/users/*`

## AI Chat

See [../ai/API.md](../ai/API.md) — `/api/user/v1/ai-chat/*`

Full spec: [../../06-API-SPECIFICATION.md](../../06-API-SPECIFICATION.md)
