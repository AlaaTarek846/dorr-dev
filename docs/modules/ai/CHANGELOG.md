# AI — Changelog

## [Unreleased]

### Added
- **DORR AI safety layer (spec 350–362), 2026-10-08:** `Modules/AI/app/Safety/` — `RiskClassifier` (a separate JSON classification call plus fixed Arabic/English keyword rules that decide alone when the model can't), `RiskAssessment`, `SafetyPolicyEngine` (rules as instructions before the answer; after it, the approved referral sentence and opening disclaimer from `lang/*/ai.php` `safety.*`, "optional" softeners removed, the model's own copy of the fixed sentence dropped). Applied to the AI chat (`AiChatService::sendMessage`) and to every free answer in DORR Chat. Migration `2026_10_08_100000_add_safety_to_ai_messages` (`ai_messages.safety` json)
- Speech to text: `AiGateway::transcribe()` (OpenAI / Groq Whisper, Google Gemini), `AiProviderRepository::resolveForTranscription()`, config `transcription_model` per provider and `transcription_timeout` (2026-10-04)
- Module documentation

## Historical

See git log for `Modules/AI/` changes.
