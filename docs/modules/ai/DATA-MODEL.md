# AI — Data Model

## ai_providers

Migration: `Modules/AI/database/migrations/2026_09_16_090000_create_ai_providers_table.php`

Alter migrations:
- `add_live_models_cache_to_ai_providers_table`
- `add_is_default_to_ai_providers_table`

Fields include: key, api_key, model, enabled, is_default, live_models_cache

## ai_conversations / ai_messages

Migration: `Modules/AI/database/migrations/2026_09_16_150000_create_ai_conversations_table.php`

- `ai_conversations`: user_id, title, timestamps
- `ai_messages`: conversation_id, role, content, metadata, `safety` (json, 2026-10-08: the answer's class and the approved texts added to it), timestamps

## ai_requests

One row per AI request (owner, provider, model, minimized prompt, status, correlation id), with its `ai_responses`, `ai_usage`, `ai_failovers` and `ai_provider_logs`.

- `source` (string 64, nullable, indexed — 2026-10-14, `add_source_to_ai_requests_table`): `null` = the DORR AI chat; otherwise the tool that asked through `AiTaskRunner` (`chat.translate`, `chat.summary`, `discover.ask`…). The admin list filters on it with `?source=ai_chat|tools|<tool>`.

## ai_memories / ai_owner_settings (2026-10-14, `create_ai_memories_tables`)

- `ai_memories`: owner (morph), `content` (text — only what the person explicitly asked to keep), `source_conversation_id` (nullable, null on delete), timestamps
- `ai_owner_settings`: owner (morph, unique), `memory_enabled` (bool, default true). No row = the defaults

## Other uses of existing tables (2026-10-14)

- `ai_conversation_contexts.context_type = context_capsule`: a Context Capsule as JSON (`goal, decisions, facts, open_tasks, done, files, sources`)
- `ai_feature_flags.target_type = service` with `tool_key = ai_chat`: the DORR AI chat switched off for a country
- `ai_projects` / `ai_project_conversations` / `ai_project_files` / `ai_project_instructions`: now also owned and edited by the person (`AiUserProjectController`)

## Relationships

```
User ──< AiConversation ──< AiMessage
AiProvider (standalone config table)
```
