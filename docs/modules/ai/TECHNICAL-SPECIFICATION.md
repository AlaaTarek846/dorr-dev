# AI — Technical Specification

## Architecture

```
AiChatController → AiChatService → AiGateway → Connectors
AiProviderController → AiProviderService → AiProviderRepository
Other modules (ChatAiService, DiscoverAiService…) → AiTaskRunner → AiGateway → Connectors
```

## AiTaskRunner — one system for every AI call (2026-10-14)

`Modules/AI/app/Services/AiTaskRunner.php` runs a single AI task for another module through the same pipeline as the DORR AI chat, so the admin's AI settings apply everywhere, not only in the DORR AI chat:

| Step | Same as the AI chat | What |
|---|---|---|
| Owner block | `ai_trial_control.abuse_status = blocked` | No AI at all → `owner_blocked` |
| Safety rules | `AiSafetyGuard::evaluate()` on the person's text | `block` → `content_blocked` (+ `ai_safety_events`, audit); `sanitize` replaces the text; `review` is logged |
| Routing | `AiRoutingEngine::resolve()` | Routing policy (global / country / plan), rules per intent, per-country provider and model flags, capability preferences |
| Fallback | `AiCircuitBreaker` + `ai_failovers` | An open circuit is skipped; a failure moves on when the policy allows it |
| Log | `ai_requests` (`source`), `ai_responses`, `ai_usage` (estimated), `ai_provider_logs` | The prompt and answer are minimized per `ai_data_policies` |
| Voice to text | `AiModelResolver` `speech_to_text` | The model registered by the admin (`gateway->transcribeAudio()`), else the old `resolveForTranscription()` path |

`chat($messages, $source, $owner, $free = false)`, `transcribe(...)`, `generateImage($prompt, $source, $owner)` and `editImage($bytes, $mime, $prompt, $source, $owner)` never throw for a provider problem; the result carries `reason` (`unavailable`, `owner_blocked`, `content_blocked`, `plan_required`, `image_not_in_plan`, `image_daily_limit`, `failed`) and `detail` (`usage_reason`, `cooldown_seconds_left`, `limit`). With no owner (a background job) there is no routing, plan or log: the default chat provider answers.

**Plans (2026-10-15, ADR-013):** every task for a person goes through `AiChatUsageGuard::evaluate()` like a DORR AI chat message (it starts the free trial or a usage window when due) and `recordConsumption()` on success. `$free` exempts support's automatic FAQ answer. `entitlement($owner)` / `AiChatUsageGuard::preview()` answer read-only (for locking buttons). Images use the `image_generation` model from the Model Registry, `AiMediaQuotaService::reserve()` against the plan's `image_daily_limit` (settled completed / failed), one retry, and the circuit breaker.

`source` names the caller: `chat.translate`, `chat.translate_fields`, `chat.transcribe`, `chat.summary`, `chat.smart_replies`, `chat.ask_about`, `chat.commitments`, `chat.greetings`, `chat.assistant`, `chat.proofread`, `chat.understand`, `chat.simplify`, `chat.tasks`, `chat.note`, `chat.dates`, `chat.important`, `chat.related_files`, `chat.today`, `discover.ask`; `….correction` for the quality gate's second try. `null` is the DORR AI chat itself.

## Connectors (`Modules/AI/app/Services/Connectors/`)

- `Contracts/AiConnector.php`
- `AbstractHttpConnector.php`
- `OpenAiConnector`, `AnthropicConnector`, `GoogleConnector`, `GroqConnector`
- Shared concern: `SendsOpenAiCompatibleChat`

## DORR AI's own tools (2026-10-14)

All wired into `AiChatService::sendMessage()` / `systemMessages()` — one path, so the streaming endpoint gets them too.

| Spec | Service | What |
|---|---|---|
| 140–144, 318, 328 MemoryService | `AiMemoryService` | Only explicit memories: the memory screen, or a message starting «افتكر/تذكر/احفظ إن…» / "remember that…". Secrets refused (`AiPiiSanitizer::redactSecrets` + card numbers + password/PIN/OTP words). Off (`ai_owner_settings.memory_enabled`) = nothing saved or sent. The newest 40 go in as one system message |
| 121–132, 329 ContextContinuationService | `AiContextContinuationService` | `continueFrom()` reads only the picked conversations (≤5, ~14 000 chars), asks the model (via `AiTaskRunner`, `ai.capsule`) for `{goal, decisions, facts, open_tasks, done}`; file names come from the records. Stored as an included `ai_conversation_contexts` row (`context_type = context_capsule`, JSON), rendered as text by `systemMessages()` |
| 133–139, 150, 314, 330 SemanticHistorySearch | `AiHistorySearchService` | Lexical: titles, messages, attachment names, projects, with أ/إ/آ/ا, ة/ه, ى/ي the same and diacritics ignored — both in SQL (`replace()`, MySQL and SQLite) and in PHP (exact match + highlight positions in the original text). Semantic: the model widens the query (synonyms, dialect, the other language; `ai.search`, cached a day); lexical hits first. No AI → lexical only |
| 151, 315 projects | `AiUserProjectService` | The person's `ai_projects` with `ai_project_conversations` / `ai_project_files` / one `ai_project_instructions` row; a conversation in a project gets its name, description, instructions and file names as a system message |
| 344 outputs gallery | `AiOutputGalleryService` | Assistant-message attachments (image / video / audio / document) and `generated_file`, newest first |
| 5, 9, 16–19, 79–112, 275–283, 324 EvidencePlanner | `AiEvidencePlanner` | Always: no 100% claim, show disagreement, primary sources first, check dates/jurisdiction, units/math, names, quotes. Per topic (`RiskClassifier::byRules` + keywords): law, religion (Qur'an/hadith/fiqh), dreams, finance, data, media. Appended to the domain guidance |
| 88, 97, 280–283, 332 CitationValidator | `AiCitationValidator` | After the answer: Qur'an references against a fixed 114-surah table (6236 verses), `[n]` markers past the sources given (removed), `a op b = c` and `x% of y = z` recomputed, impossible dates, a legal answer with no country, «والله أعلم» after an Arabic dream interpretation. Warnings join `verification_warnings` |
| Requirement 7–8 | `AiFeatureFlagGate::isServiceAllowed()` | `target_type = service`, `tool_key = ai_chat`, per country: `/status` `available=false`, send 403, nothing deleted |

The chat's free answers (`ChatAiService::askSafely`) also get the evidence rules, and the quality-gated ones (assistant, ask about the chat) the validator's warnings in `verification.warnings`. Exported (`data-export`: `memories`, `projects`) and erased (`DELETE data`) with the rest of the person's AI data.

Not built, by decision or dependency: `SensitiveActionGate` (334) — no AI-initiated sensitive action exists yet (the only tool is read-only; payments go through wallet endpoints with the PIN); it belongs with Service Orchestration. City and category flags — the AI module has no location or category source (the chat's `ChatFeatures` has city for the chat tools). Team subscriptions (367) — spec says not now.

## Safety layer (spec 350–362)

`Modules/AI/app/Safety/` — enforced by the system, never left to the model:

| Step | Who | What |
|---|---|---|
| 1. Classify | `RiskClassifier::classify($request, $provider)` | A separate, short call that answers JSON only `{domain, specific}`, plus fixed keyword rules (Arabic and English; phrases weigh more than single words). The rules decide alone when the call fails; a "specific" signal from the rules counts when both agree on the domain |
| 2. Instruct | `SafetyPolicyEngine::instruct()` | One more system message: general information with its source; for a personal case no final ruling / fatwa / legal opinion / diagnosis / treatment; structural design = concept only; production code = "not tested"; never "if needed"-style wording; never its own disclaimer |
| 3. Finish | `SafetyPolicyEngine::finish()` | Removes softeners ("if needed", "عند الحاجة"…) from clauses about consulting / checking / testing, drops the model's own copy of the fixed sentence, and returns `safety {domain, specific, disclaimer, notice}` |

Domains: `religion`, `law`, `medicine` (HIGH STAKES: the disclaimer always; the referral when specific), `engineering` (the referral when it touches structure, wiring or plumbing), `code` (the "not tested + code review" notice when it's for production, money, security or real user data). The texts live in `lang/{ar,en}/ai.php` `safety.*` — the Arabic ones are the spec's approved wording, word for word.

Applied to: the AI chat (`AiChatService`), and in DORR Chat to every free answer (`ChatAiService::askSafely`: ask about the chat, the assistant, understand a message). Suggested replies are new text too, so they are classified and written under the rules (`instruct`), with nothing appended — the person sends them as their own words (2026-10-14). Conversions and extractions of the chat's own content (translate, transcribe, proofread, summarise, simplify, notes, dates, tasks, today) add no advice and are not classified: a referral appended to a translation or a summary would change it.

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
