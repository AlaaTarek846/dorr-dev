# AI — Technical Specification

## Architecture

```
AiChatController → AiChatService → AiGateway → Connectors
AiProviderController → AiProviderService → AiProviderRepository
```

## Connectors (`Modules/AI/app/Services/Connectors/`)

- `Contracts/AiConnector.php`
- `AbstractHttpConnector.php`
- `OpenAiConnector`, `AnthropicConnector`, `GoogleConnector`, `GroqConnector`
- Shared concern: `SendsOpenAiCompatibleChat`

## AiGateway

Routes chat requests to configured default (or selected) provider connector.

## Models

- **AiProvider** — configuration row per provider key; `live_models_cache` JSON; `is_default`
- **AiConversation** — belongs to User; hasMany messages
- **AiMessage** — role + content stored per message

## Repositories

- `AiProviderRepository`
- `AiConversationRepository`

## Seeding

- `AI_GROQ_SEED_API_KEY` env for seeder (optional)

## Frontend

- `ChatPanel.vue`, `ConversationSidebar.vue`
- User chat calls `/api/user/v1/ai-chat/*`
- Admin settings uses `AiProviderCard.vue`
