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

## Safety layer (spec 350–362)

`Modules/AI/app/Safety/` — enforced by the system, never left to the model:

| Step | Who | What |
|---|---|---|
| 1. Classify | `RiskClassifier::classify($request, $provider)` | A separate, short call that answers JSON only `{domain, specific}`, plus fixed keyword rules (Arabic and English; phrases weigh more than single words). The rules decide alone when the call fails; a "specific" signal from the rules counts when both agree on the domain |
| 2. Instruct | `SafetyPolicyEngine::instruct()` | One more system message: general information with its source; for a personal case no final ruling / fatwa / legal opinion / diagnosis / treatment; structural design = concept only; production code = "not tested"; never "if needed"-style wording; never its own disclaimer |
| 3. Finish | `SafetyPolicyEngine::finish()` | Removes softeners ("if needed", "عند الحاجة"…) from clauses about consulting / checking / testing, drops the model's own copy of the fixed sentence, and returns `safety {domain, specific, disclaimer, notice}` |

Domains: `religion`, `law`, `medicine` (HIGH STAKES: the disclaimer always; the referral when specific), `engineering` (the referral when it touches structure, wiring or plumbing), `code` (the "not tested + code review" notice when it's for production, money, security or real user data). The texts live in `lang/{ar,en}/ai.php` `safety.*` — the Arabic ones are the spec's approved wording, word for word.

Applied to: the AI chat (`AiChatService`), and in DORR Chat to every free answer (`ChatAiService::askSafely`: ask about the chat, the assistant, understand a message). Conversions of the person's own content (translate, transcribe, proofread, summarise, simplify) add no advice and are not classified.

**Not built yet (NEEDS-DECISION):** `AnswerVerifier` / `CitationValidator` (spec 362) — sources are requested from the model but not validated, and no numeric accuracy threshold exists.

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
