# Provider — API

Base: `/api/admin/v1` (middleware: `locale`, `auth:admin_api`)

| Method | Endpoint |
|--------|----------|
| GET | `/providers` |
| POST | `/providers` |
| GET | `/providers/{provider}` |
| PUT/PATCH | `/providers/{provider}` |
| DELETE | `/providers/{provider}` |
| POST | `/providers/delete-multiple` |
| PATCH | `/providers/{provider}/status` |

Full spec: [../../06-API-SPECIFICATION.md](../../06-API-SPECIFICATION.md)

## Provider Portal — `/api/provider/v1`

Guest: `POST /login`, `/check-token`, `/register`, `/verify-email`, `/resend-verification`, `/create-password`, `/forgot-password`, `/reset-password`

Authenticated (`auth:provider_api`): `GET /me`, `POST /logout`, `POST /profile`, `PUT /profile/password`, `GET /countries/dropdown`

Provider payload (login/check-token/me) includes `services[]` — each row has `service_category_id` + `category` (`id`, `module_name`, `name`, `requires_provider`, `image`, `translations`). See [ProviderServiceResource](../../../Modules/Provider/app/Http/Resources/ProviderServiceResource.php).

## PLANNED

Public provider listing for website — **NEEDS-DECISION**, not implemented.
