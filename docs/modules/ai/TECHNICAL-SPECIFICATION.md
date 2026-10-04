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

**Speech to text (2026-10-04):** `AiGateway::transcribe($provider, $path, $mime)` for connectors that implement `Contracts\TranscribesAudio` — OpenAI and Groq through `Concerns\TranscribesOpenAiCompatibleAudio` (`/audio/transcriptions`, model `ai.providers.{key}.transcription_model`, default `whisper-1` / `whisper-large-v3-turbo`), Google by sending the audio inline to Gemini. Anthropic takes no audio. `AiProviderRepository::resolveForTranscription()` picks the chat provider when it takes audio, else the first enabled one that does. Timeout `ai.transcription_timeout` (60 s). Used by the chat's "voice to text" (`Modules\Chat\Services\ChatAiService`, which also uses `chat()` for translate, summary and suggested replies).

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
