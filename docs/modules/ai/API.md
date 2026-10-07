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

**Safety (spec 350–362):** every assistant message carries `safety` — `null`, or `{domain: religion|law|medicine|engineering|code, specific, disclaimer, notice}` — and its `content` ends with the same approved texts (for screens without the alert card). See [TECHNICAL-SPECIFICATION.md](TECHNICAL-SPECIFICATION.md#safety-layer-spec-350362).

Full spec: [../../06-API-SPECIFICATION.md](../../06-API-SPECIFICATION.md)
