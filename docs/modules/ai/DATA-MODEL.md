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
- `ai_messages`: conversation_id, role, content, metadata, timestamps

## Relationships

```
User ──< AiConversation ──< AiMessage
AiProvider (standalone config table)
```
