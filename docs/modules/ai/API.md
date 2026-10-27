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

`/status` also returns `plan {allowed, reason, plan_name, is_trial}` — read-only (`AiChatUsageGuard::preview()`, starts no trial or usage window) — for the app's DORR AI home. It says `available: false` when the admin switched the DORR AI chat off for the person's country (`ai_feature_flags`, `target_type = service`, `tool_key = ai_chat`); sending then answers 403. Nothing is deleted.

### DORR AI's own tools (2026-10-14)

Same prefix and auth, for the user (`/api/user/v1/ai-chat`) and the provider (`/api/provider/v1/ai-chat`). Everything is the person's own, found by owner (another person's id is a 404). Errors: `ai_{reason}` (`AiAssistantException`, text `ai.assistant_{reason}`).

| Method | Endpoint | Body / query | Description |
|--------|----------|--------------|-------------|
| GET | `/memories` | | `{enabled, limit, memories[{id, content, source_conversation_id, created_at}]}` (spec 143) |
| POST | `/memories` | `content` (≤500) | Remember something. Refused: memory off (`ai_memory_disabled`), a secret — password, key, card number, code (`ai_memory_secret_refused`), full (100, `ai_memory_full`) |
| PUT | `/memories/{id}` | `content` | Edit |
| DELETE | `/memories/{id}` | | Delete one |
| DELETE | `/memories` | | Delete all → `{deleted}` |
| PUT | `/memories/settings` | `enabled` | Memory on / off. Off: nothing saved, nothing sent to the model |
| GET | `/search` | `q` (2–100) | **Send limiter.** `{query, terms[], results[{type: title\|message\|file\|project, match: lexical\|semantic, term, conversation{id,title}, message_id, role, snippet, highlights[{start,length}], created_at, project?}]}` (spec 134–139) |
| GET | `/outputs` | `kind?` image\|video\|audio\|document | What DORR AI made: `[{kind, name, mime_type, url, conversation{id,title}, message_id, created_at}]` (spec 344) |
| POST | `/conversations/continue` | `conversation_ids[]` (1–5) | **Send limiter.** New conversation with a Context Capsule → `{conversation, capsule{goal, decisions[], facts[], open_tasks[], done[], files[], sources[{id,title}]}}` (spec 123) |
| GET | `/conversations/{c}/capsule` | | `{capsule}` or `{capsule: null}` |
| DELETE | `/conversations/{c}/capsule` | | Remove it — no longer sent to the model (spec 130) |
| GET | `/projects` | | `[{id, name, description, instructions, conversations_count, files_count}]` |
| POST | `/projects` | `name`, `description?`, `instructions?` | Create (max 50) |
| GET/PUT/DELETE | `/projects/{p}` | | Show (with `conversations[]`, `files[]`) / update / delete (its conversations and files stay) |
| POST/DELETE | `/projects/{p}/conversations/{c}` | | Put a conversation in / take it out |
| POST/DELETE | `/projects/{p}/files/{file}` | | Same for one of the person's AI files |

A message that starts with «افتكر إن…» / «remember that…» is saved to memory (when on) and the answer confirms it. Assistant messages' `verification_warnings` (and the send response's `warnings`) also carry the CitationValidator's findings.

**Safety (spec 350–362):** every assistant message carries `safety` — `null`, or `{domain: religion|law|medicine|engineering|code, specific, disclaimer, notice}` — and its `content` ends with the same approved texts (for screens without the alert card). See [TECHNICAL-SPECIFICATION.md](TECHNICAL-SPECIFICATION.md#safety-layer-spec-350362).

Full spec: [../../06-API-SPECIFICATION.md](../../06-API-SPECIFICATION.md)
