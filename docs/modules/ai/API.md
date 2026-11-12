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

Send message, safety rule "require confirmation" (2026-11-11): same response keys. The first time, nothing reaches the AI: `status` = `cancelled`, the assistant message asks the person to confirm (`ai.safety_confirmation_required`, `is_error` false). Sending the same text again in that conversation within `ai.chat.safety_confirmation_minutes` (30) confirms it and the turn runs normally. One-shot tasks (`AiTaskRunner`) refuse it with `content_blocked`.

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

## Admin — AI plans (catalog, 2026-11-10)

Prefix `/api/admin/v1/ai-plans`, guard `admin_api`, permissions `ai-plans.{view,create,update,delete,change-status,multiple-delete}`.

| Method | Path | Notes |
|--------|------|-------|
| GET | `/` | catalog list: `search`, status filter, `trashed=1`, `status_counts=1` (top-level `status_counts`) |
| POST | `/` | `code`, limits, price…, `status`, `translations[]` = `{locale, name, description?, badge?, features?[]}` — one per stored language |
| GET/PUT/DELETE | `/{plan}` | DELETE moves to the trash |
| PATCH | `/{plan}/status` | `{status}` |
| POST | `/delete-multiple` | `{ids[]}` |
| POST | `/{plan}/restore` | |
| DELETE | `/{plan}/force` | 409 while subscribed |
| GET/POST | `/{plan}/prices`, DELETE `/{plan}/prices/{price}` | view / update |

Admin responses add `translations[]`. The apps' `GET /api/{user,provider,driver}/v1/ai-subscription/plans` keep the same keys (`name`, `description`, `badge`, `features`, `is_active`) in the caller's `X-Locale`, without `translations`.

`GET /api/admin/v1/ai-subscriptions/overview` (2026-11-11): money is per currency — `mrr_by_currency` and `revenue_this_month_by_currency` = `[{currency, amount}]` (a subscription's currency = its last successful payment's, else its plan's). `mrr` / `revenue_this_month` stay, but are `null` when more than one currency is present. A used-up free trial now ends its trial subscription (`expired`); `AiChatUsageGuard` answers `trial_ended` and never reopens it after the cooldown.

## Admin — website offers / edit packs and hosting plans (catalog, 2026-11-10)

Prefixes `/api/admin/v1/ai-site-offers` (permissions `ai-site-offers.*`) and `/api/admin/v1/ai-site-hosting-plans` (`ai-site-hosting-plans.*`), guard `admin_api`, actions `view, create, update, delete, change-status, multiple-delete` checked by the controllers (`CatalogController`).

| Method | Path | Notes |
|--------|------|-------|
| GET | `/` | catalog list: `search`, status filter, `trashed=1`, `status_counts=1`; offers also `kind=site\|edit_pack` (the `source_download` row is never listed) |
| POST | `/` | offers: `code`, `kind`, `generations_included`, `sort_order`, `status`; plans: `code`, `period`, `sort_order`, `status`; both `translations[]` = `{locale, name, description?}` — one per stored language (`is_active` is still accepted as `status`) |
| GET/PUT/DELETE | `/{id}` | DELETE moves to the trash |
| PATCH | `/{id}/status` | `{status}` |
| POST | `/delete-multiple` | `{ids[]}` |
| POST | `/{id}/restore` | |
| DELETE | `/{id}/force` | 409 while purchases (offers) / hostings (plans) point at it |
| GET/PUT | `/{id}/prices` | view / update (PUT replaces the whole list). The source-download screen uses the offers one with its offer id |

Admin responses add `translations[]`, `prices_count`, dates. The apps' `GET /api/{user,provider}/v1/ai-sites/offers`, `/{project}/edit-packs` and `/hosting/plans` keep the same keys (`name`, `description`, `is_active`, `price`, `currency`, `available`…) in the caller's `X-Locale`, plus `status`; no `translations`.

## Admin — routing screens (catalog, 2026-11-10)

Prefixes `/api/admin/v1/ai-intents`, `ai-learned-intents`, `ai-routing-policies`, `ai-gateways`, `ai-routing-rules`, guard `admin_api`. Each route group is guarded by `admin.screen:<group>[,readers]` (`AiAdminScreens::middleware`): GET = `view`, store = `create`, destroy / force = `delete`, status = `change-status`, delete-multiple = `multiple-delete`, any other write (update, restore, the phrase tester) = `update`. Readers may only GET: `ai-routing-rules.view` reads intents; `ai-gateways.view` and `ai-routing-rules.view` read routing policies.

| Method | Path | Notes |
|--------|------|-------|
| GET | `/` | catalog list: `search`, status filter (`filterColumns`), `trashed=1`, `status_counts=1`. Learned phrases also `state=pending\|conflict`, `intent`, `mode`, `source` |
| POST | `/` | intents: `key`, `status`, `translations[]` = `{locale, name, description?}`; policies: scope / codes / plan / `selection_strategy` (priority, round_robin, latency_optimized — `cost_optimized` is refused now: no prices to compare) / `fallback_enabled` / `priority` / `status` + `translations[]` = `{locale, name}`; gateways: `environment`, `default_policy_id`, `status` + `translations[]` = `{locale, name}`; rules: `routing_policy_id`, `intent_id`, `provider_id`, `model_key`, `priority`, `selection_config`, `status`; phrases: `phrase`, `match_mode`, `intent`, `file_format?`, `status?`. One translation per stored language. `is_active` is still accepted as `status` |
| GET | `/dropdown` | intents (`id, key, name, status`) and policies (`id, name, scope_type, status`) — not trashed, inactive included, name in `X-Locale` |
| GET/PUT/DELETE | `/{id}` | DELETE moves to the trash. Phrases: PUT changes `intent`, `file_format`, `status` only |
| PATCH | `/{id}/status` | `{status}`. Phrases: off sets `conflicts ≥ 1` ("needs review"), on clears it |
| POST | `/delete-multiple` | `{ids[]}` |
| POST | `/{id}/restore` | |
| DELETE | `/{id}/force` | 409 — intents while rules or requests point at them; policies while they have rules or are a gateway's default; gateways while requests point at them |
| GET | `/ai-learned-intents/stats`, POST `/ai-learned-intents/test` | stats = view; tester = update |

Responses keep their old keys plus `status`, `deleted_at`; `is_active` = `status`. Intents / policies / gateways add `translations[]` (admin). Rules' `policy` / `intent` and gateways' `default_policy` add `status` and `deleted`. Policies add `rules_count`. **Learned phrases: `status` is now the catalog boolean; the router state (`active|pending|conflict`) moved to `state`.** A trashed policy / intent can't be picked in a form unless the row already points at it.

## Admin — feature flags, domain and safety policies (catalog, 2026-11-10)

Prefixes `/api/admin/v1/ai-feature-flags`, `ai-domain-policies`, `ai-safety-policies`, `ai-safety-rules`, guard `admin_api`. Each route group is guarded by `admin.screen:<group>[,readers]` (`AiAdminScreens::middleware`), same action mapping as the routing screens. Readers may only GET: `ai-safety-rules.view` reads safety policies (`/dropdown`).

| Method | Path | Notes |
|--------|------|-------|
| GET | `/` | catalog list: `search`, status filter (`filterColumns`), `trashed=1`, `status_counts=1` |
| POST | `/` | flags: `key`, `target_type` (provider, model, tool, service), `provider_id`, `model_key`, `tool_key`, `country_code` (2 letters, stored upper case), `domain` (the intent key), `environment` (empty = production), `status`, `translations[]` = `{locale, description?}` (optional; a flat `description` is still accepted and written into every language). `is_enabled` / `is_active` are still accepted as `status`. Domain policies: `domain_key` (unique, trash included), `risk_level`, the four switches, `system_prompt_addition`, `disclaimer_text`, `status` + `translations[]` = `{locale, name, description?}`. Safety policies: `risk_level`, `applies_to[]`, `status` + `translations[]` = `{locale, name, description?}`. Safety rules: `safety_policy_id` (not trashed, unless the rule already belongs to it), `condition`, `action`, `priority` (empty = 0), `status` + `translations[]` = `{locale, name}`. One translation per stored language |
| GET | `/dropdown` | safety policies only (`id, name, risk_level, status`) — not trashed, inactive included, name in `X-Locale` |
| GET/PUT/DELETE | `/{id}` | DELETE moves to the trash |
| PATCH | `/{id}/status` | `{status}` — for a flag, `false` turns its target off |
| POST | `/delete-multiple` | `{ids[]}` |
| POST | `/{id}/restore` | |
| DELETE | `/{id}/force` | 409 — safety policies while they have rules (trash included) or safety events; safety rules while safety events name them |

Responses keep their old keys plus `status`, `deleted_at`, `translations[]` (admin). Flags: `is_enabled` = `status`, plus `provider_id`. Domain / safety policies and rules: `is_active` = `status`. Safety policies add `rules_count`; a rule's `policy` adds `status` and `deleted`.

## Admin — security / data policies, provider data rules, conversation / project instructions (catalog, 2026-11-11)

Prefixes `/api/admin/v1/ai-security-policies`, `ai-data-policies`, `ai-provider-data-rules`, `ai-conversation-instructions`, `ai-project-instructions`, guard `admin_api`. Each route group is guarded by `admin.screen:<group>` (`AiAdminScreens::middleware`), same action mapping as the routing screens.

| Method | Path | Notes |
|--------|------|-------|
| GET | `/` | catalog list: `search`, status filter (`filterColumns`), `trashed=1`, `status_counts=1` |
| POST | `/` | security policies: the four switches (still accepted and returned, no longer in the admin form — always enforced in code), `status` + `translations[]` = `{locale, name, description?}`. Data policies: `data_classification` (public, internal, confidential, personal, secret), `retention_days` (≥ 0, empty = no limit), `consent_required`, `minimization_enabled`, `external_provider_allowed`, `status` + `translations[]`. Provider data rules: `provider_id` (one rule per provider, the trash included — 422 `ai.provider_data_rule_exists`), `sanitize_pii`, `sanitize_secrets`, `transformation_rules` (object: `mask_fields[]` of email / phone / id_number / secrets, `redact_terms[]` 2–100 chars; other keys kept but ignored — applied by `AiGateway::applyDataRules` even with both switches off), `status`. Conversation instructions: `conversation_id`, `source_type` (user, system, template), `instruction` (≤ 5000), `priority` (empty = 0), `status`. Project instructions: `project_id`, `instruction`, `priority`, `status`. `is_active` is still accepted as `status`. One translation per stored language |
| GET/PUT/DELETE | `/{id}` | DELETE moves to the trash (provider data rules: the parameter is `{rule}`) |
| PATCH | `/{id}/status` | `{status}` |
| POST | `/delete-multiple` | `{ids[]}` |
| POST | `/{id}/restore` | |
| DELETE | `/{id}/force` | always allowed (nothing points at these rows) |

Responses keep their old keys plus `status`, `deleted_at`; `is_active` = `status`. Security / data policies add `translations[]` (admin); `name` / `description` are in `X-Locale`.

## Admin — language variants, language evaluations, benchmark cases (catalog, 2026-11-11)

Prefixes `/api/admin/v1/ai-language-variants`, `ai-language-evaluations`, `ai-benchmark-cases`, guard `admin_api`. Each route group is guarded by `admin.screen:<group>[,readers]` (`AiAdminScreens::middleware`), same action mapping as the routing screens. Readers may only GET: `ai-language-evaluations.view` and `ai-user-language-preferences.view` read language variants (`/dropdown`).

| Method | Path | Notes |
|--------|------|-------|
| GET | `/` | catalog list: `search`, status filter (`filterColumns`), `trashed=1`, `status_counts=1` |
| POST | `/` | variants: `language_id`, `code` (≤ 50), `style` (formal, conversational), `is_default` (turns off the language's other defaults), `status` + `translations[]` = `{locale, name}`. Evaluations: `language_id`, `variant_id` (not trashed, unless already the evaluation's), `test_case_count` (empty = 0), `pass_rate` (0–100), **`evaluation_status`** (pending, running, passed, failed), `evaluated_at`, `notes` (not translated), `status` + `translations[]` = `{locale, name}`. Benchmark cases (no translations): `prompt`, `difficulty`, `expected_behavior`, `expected_answer_keywords[]`, `domain_key`, `country_code` (2), `language`, `task_type`, `risk_level`, `notes`, `status`. `is_active` is still accepted as `status`. One translation per stored language |
| GET | `/dropdown` | variants only (`id, code, name, style, is_default, language_id, language{id,code,name}, status, is_active`) — not trashed, inactive included, name in `X-Locale` |
| GET/PUT/DELETE | `/{id}` | DELETE moves to the trash |
| PATCH | `/{id}/status` | `{status}` |
| POST | `/delete-multiple` | `{ids[]}` |
| POST | `/{id}/restore` | |
| DELETE | `/{id}/force` | 409 — variants while a user preference or an evaluation uses them; benchmark cases while past runs have results for them |

Responses keep their old keys plus `status`, `deleted_at`, `translations[]` (admin; variants, evaluations); `is_active` = `status`. **Evaluations: the result moved from `status` to `evaluation_status`** (`status` is now the boolean switch); `variant` adds `status` and `deleted`. Language variants: a `code` already used in the same language (the trash included) is a 422 `ai.language_variant_code_taken`. Benchmark cases add `results_count`. User app `GET /api/user/v1/ai-language-preference`: `variants[]` keeps its keys (name in `X-Locale`), only switched-on variants not in the trash; `PUT` refuses a trashed `variant_id`.
## Admin — AI settings permissions (2026-11-10)

`ai-settings.view` opens the screen; `ai-settings.update` is required for `POST /ai-providers/{provider}`, `/test`, `/set-default` and every `/{provider}/models` write. `GET /ai-providers` and `GET /{provider}/models` stay readable by any admin (dropdowns in feature flags, routing rules, provider data rules).