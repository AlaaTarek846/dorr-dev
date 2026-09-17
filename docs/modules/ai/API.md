# AI — API

## Admin — `/api/admin/v1/ai-providers`

Middleware: `locale`, `auth:admin_api`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/` | List providers |
| POST | `/{provider}` | Update config |
| POST | `/{provider}/test` | Test connection |
| POST | `/{provider}/set-default` | Set default |

`{provider}` ∈ `openai`, `anthropic`, `google`, `groq`

## User — `/api/user/v1/ai-chat`

Middleware: `locale`, `auth:user_api`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/status` | AI availability |
| GET | `/conversations` | List |
| POST | `/conversations` | Create |
| GET | `/conversations/{conversation}` | Show |
| DELETE | `/conversations/{conversation}` | Delete |
| POST | `/conversations/{conversation}/messages` | Send message |

Full spec: [../../06-API-SPECIFICATION.md](../../06-API-SPECIFICATION.md)
