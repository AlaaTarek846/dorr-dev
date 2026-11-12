# AI — Data Model

## ai_providers

Migration: `Modules/AI/database/migrations/2026_09_16_090000_create_ai_providers_table.php`

Alter migrations:
- `add_live_models_cache_to_ai_providers_table`
- `add_is_default_to_ai_providers_table`

Fields include: key, api_key, model, enabled, is_default, live_models_cache

## ai_plans / ai_plan_translations (catalog, 2026-11-10 `make_ai_plans_translatable`)

AI subscription plans, managed like countries (catalog CRUD, language tabs, trash).

- `ai_plans`: code (unique), usage_minutes, cooldown_minutes, image/video/site limits, duration_days, price, original_price, currency_id, is_featured, is_trial, **`status`** (was `is_active`), sort_order, **`deleted_at`** (soft deletes)
- `ai_plan_translations`: ai_plan_id (cascade), locale, **name**, description, badge, **features** (json list) — unique (ai_plan_id, locale). One row per stored language (`languages.stores_translation`); the migration copied the old single-language text into every stored language
- `AiPlan` keeps `$plan->name / description / badge / features` (current language, else any) and `$plan->is_active` (= status) as accessors; setting them flat writes every stored language (seeders, tests)
- Trash: subscribers keep a trashed plan (`plan()` relations are `withTrashed()`), but `AiPlan::isAvailable()` (status && not trashed) blocks new subscriptions, plan changes and renewals. Force delete is blocked while `subscriptions` exist (409)

## ai_site_offers / ai_site_offer_translations (catalog, 2026-11-10 `make_ai_site_offers_translatable`)

Website-builder products sold from the wallet: `kind` = `site` (a whole site), `edit_pack` (more edits for an existing site) or `source_download` (the one ZIP switch, its own screen). Managed like countries.

- `ai_site_offers`: code (unique), kind, generations_included, is_free, **`status`** (was `is_active`), sort_order, **`deleted_at`** (soft deletes)
- `ai_site_offer_translations`: ai_site_offer_id (cascade), locale, **name**, description — unique (ai_site_offer_id, locale). The migration copied the old text into every stored language
- `AiSiteOffer` keeps `name / description` (current language, else any) and `is_active` (= status) as accessors (`Concerns\HasFlatTranslatedText`); flat values write every stored language
- Trash: purchases keep a trashed offer (`AiSitePurchase::offer()` is `withTrashed()`); the customer lists and `purchase()` only take `status = true` rows that are not trashed. Force delete is blocked while `purchases` exist (409). The admin catalog never touches the `source_download` row

## ai_site_hosting_plans / ai_site_hosting_plan_translations (catalog, 2026-11-10 `make_ai_site_hosting_plans_translatable`)

- `ai_site_hosting_plans`: code (unique), period (monthly|yearly), **`status`** (was `is_active`), sort_order, **`deleted_at`**
- `ai_site_hosting_plan_translations`: ai_site_hosting_plan_id (cascade), locale, **name**, description — unique per (plan, locale)
- Same accessors as offers. `AiSiteHosting::plan()` is `withTrashed()`; `AiSiteHostingPlan::isAvailable()` (status && not trashed) blocks new subscriptions **and renewals** (`AiSiteHostingService::subscribe/renew` → `plan_unavailable`). Force delete is blocked while `hostings` exist (409)

## Routing tables as catalogs (2026-11-10 `2026_11_10_140000`…`140400`)

The five routing screens are managed like countries. Only the text admins read is translated; keys, phrases, codes, model ids and JSON settings are not.

- `ai_intents`: key (unique, the classifier's code), **`status`** (was `is_active`), **`deleted_at`**. `ai_intent_translations`: ai_intent_id (cascade), locale, **name**, description — unique `ai_intent_translations_intent_locale_unique`
- `ai_routing_policies`: scope_type, country_code, service_key, plan_id, selection_strategy, fallback_enabled, priority, **`status`**, **`deleted_at`**. `ai_routing_policy_translations`: ai_routing_policy_id (cascade), locale, **name** — unique `ai_routing_policy_tr_policy_locale_unique` (the default name is 65 chars)
- `ai_gateways`: environment, default_policy_id, **`status`**, **`deleted_at`**. `ai_gateway_translations`: ai_gateway_id (cascade), locale, **name** — unique `ai_gateway_translations_gateway_locale_unique`
- `ai_routing_rules`: **`status`**, **`deleted_at`** — no translation table (no text to read)
- `ai_learned_intents`: **`status`** (index `ai_learned_intents_active_idx` follows the column), **`deleted_at`** — no translation table (the phrase is matched, not translated)
- The migrations copied the old `name` / `description` into every stored language, then dropped the columns; each `down()` puts them back
- Models: `AiIntent`, `AiRoutingPolicy`, `AiGateway` use `HasFlatTranslatedText` (`name` / `description` in the current language, `is_active` = status, flat values write every stored language); `AiRoutingRule`, `AiLearnedIntent` use `Concerns\HasIsActiveAlias` (`is_active` = status). Query builders use `status`
- Engine: switched off **or trashed** = not used. `AiIntentClassifier` only returns intents with `status`; `AiRoutingEngine` takes gateways / policies / rules with `status` (SoftDeletes hides trashed), and a gateway's default policy only while it is on and not trashed; `AiLearnedIntentStore` matches `status` rows only and never re-creates or switches on a trashed phrase (`withTrashed` upserts)
- `withTrashed` relations (to name a trashed row): `AiRoutingRule::policy/intent`, `AiGateway::defaultPolicy`, `AiRequest::gateway/intent`, `AiReliabilityMetric::intent`
- Force delete blocked (409): intent while `routingRules` (trash included) or `requests`; policy while `routingRules` (cascade) or `gateways` (trash included); gateway while `requests`. Rules and phrases: always allowed from the trash

## Feature flags, domain and safety policies as catalogs (2026-11-10 `2026_11_10_150000`…`150300`)

The four control screens are managed like countries. Only the labels admins read are translated; keys, scopes, prompts, disclaimers, conditions, actions and priorities are not.

- `ai_feature_flags`: key, target_type, provider_id, model_key, tool_key, country_code, domain, environment, **`status`** (was `is_enabled`; off = the kill switch is on), **`deleted_at`**. `ai_feature_flag_translations`: ai_feature_flag_id (cascade), locale, **description** — unique `ai_feature_flag_translations_flag_locale_unique`. The index `ai_feature_flags_target_enabled_index` follows the renamed column
- `ai_domain_policies`: domain_key (unique), risk_level, requires_jurisdiction, requires_triage, sandbox_required, allowlist_enforced, system_prompt_addition, disclaimer_text, **`status`**, **`deleted_at`**. `ai_domain_policy_translations`: ai_domain_policy_id (cascade), locale, **name**, description — unique `ai_domain_policy_translations_policy_locale_unique`
- `ai_safety_policies`: risk_level, applies_to (json), **`status`** (index `ai_safety_policies_is_active_index` follows the column), **`deleted_at`**. `ai_safety_policy_translations`: ai_safety_policy_id (cascade), locale, **name**, description — unique `ai_safety_policy_translations_policy_locale_unique`
- `ai_safety_rules`: safety_policy_id, condition (json), action, priority, **`status`**, **`deleted_at`**. `ai_safety_rule_translations`: ai_safety_rule_id (cascade), locale, **name** — unique `ai_safety_rule_translations_rule_locale_unique`
- The migrations copied the old text into every stored language, then dropped the columns; each `down()` puts them back
- Models use `HasFlatTranslatedText` (`name` / `description` in the current language, `is_active` = status, flat values write every stored language; a translation table without `name` gets no name placeholder). `AiFeatureFlag` also keeps **`is_enabled` = status** (read and write). Query builders use `status`
- Engine: `AiFeatureFlagGate` reads flags with `status = false` (the kill switch); a **trashed flag is never applied**, so its target works again (as a deleted flag before). Every save / trash / restore / force delete of a flag clears the gate's 30 s cache (`AiFeatureFlagGate::flushCache`). `AiDomainClassifier` and `AiBenchmarkRunner` take domain policies with `status`; `AiSafetyGuard` applies rules with `status` whose policy has `status` and is not trashed. Switched off **or trashed** = not applied
- `withTrashed` relations (to name a trashed row): `AiSafetyRule::policy`, `AiSafetyEvent::policy/rule`
- Force delete blocked (409): safety policy while `safetyRules` (trash included — cascade) or `safetyEvents`; safety rule while `safetyEvents`. Flags and domain policies: always allowed from the trash

## Security / data policies, provider data rules and instructions as catalogs (2026-11-11 `2026_11_10_160000`…`160400`)

Managed like countries. Only the names / descriptions admins read are translated; switches, classifications, JSON rules and the instruction prompts (sent to the AI word for word) are not.

- `ai_security_policies`: authentication_required, authorization_required, tenant_isolation_required, rate_limit_enabled, **`status`** (was `is_active`; index `ai_security_policies_is_active_index` follows the column), **`deleted_at`**. `ai_security_policy_translations`: ai_security_policy_id (cascade), locale, **name**, description — unique `ai_security_policy_translations_policy_locale_unique`. Not read by the engine (a record of the policy)
- `ai_data_policies`: data_classification, retention_days, consent_required, minimization_enabled, external_provider_allowed, **`status`**, **`deleted_at`**. `ai_data_policy_translations`: ai_data_policy_id (cascade), locale, **name**, description — unique `ai_data_policy_translations_policy_locale_unique`
- `ai_provider_data_rules`: provider_id (unique — the trash included), sanitize_pii, sanitize_secrets, transformation_rules (json), **`status`**, **`deleted_at`** — no translation table
- `ai_conversation_instructions`: conversation_id, source_type, instruction, priority, **`status`** (index `ai_conversation_instructions_conversation_active_index` follows the column), **`deleted_at`** — no translation table
- `ai_project_instructions`: project_id, instruction, priority, **`status`**, **`deleted_at`** — no translation table
- The policy migrations copied the old `name` / `description` into every stored language, then dropped the columns; each `down()` puts them back
- Models: `AiSecurityPolicy`, `AiDataPolicy` use `HasFlatTranslatedText`; the other three `HasIsActiveAlias` (`is_active` = status). Query builders use `status`
- Engine: `AiDataPolicy::activePersonal()` (personal, `status`, newest; trash excluded) feeds `ai:enforce-retention` and the log minimization (`AiChatService`, `AiTaskRunner`); `AiGateway::dataRuleFor` reads rules with `status`; conversation instructions (`AiChatService::systemMessages`) and project instructions (`AiUserProjectService` list / show / systemMessage) with `status`. Switched off **or trashed** = not used. `AiUserProjectService::setInstructions` force-deletes the project's old instructions (trash included) before writing the person's new one
- Force delete: always allowed from the trash (nothing points at these rows)

## Language variants, language evaluations and benchmark cases as catalogs (2026-11-11 `2026_11_10_170000`…`170200`)

Managed like countries. Only the names people read are translated; codes, styles, notes and benchmark test data are not.

- `ai_language_variants`: language_id, code (unique per language), style, is_default (one per language — `AiLanguageVariantService`, trash included), **`status`** (was `is_active`; index `ai_language_variants_language_active_index` follows the column), **`deleted_at`**. `ai_language_variant_translations`: ai_language_variant_id (cascade, FK `ai_language_variant_translations_variant_fk`), locale, **name** — unique `ai_language_variant_translations_variant_locale_unique`
- `ai_language_evaluations`: language_id, variant_id, test_case_count, pass_rate, **`evaluation_status`** (the result pending / running / passed / failed — this column was called `status` before; index `ai_language_evaluations_language_status_index` follows it), evaluated_at, notes (free admin text, not translated), **`status`** (new boolean on/off, existing rows on), **`deleted_at`**. `ai_language_evaluation_translations`: ai_language_evaluation_id (cascade, FK `ai_language_evaluation_translations_evaluation_fk`), locale, **name** — unique `ai_language_evaluation_translations_eval_locale_unique`
- `ai_benchmark_cases`: **`status`** (was `is_active`), **`deleted_at`** — no translation table (the question, keywords and notes are test data)
- The migrations copied the old `name` into every stored language, then dropped the column; each `down()` puts it back (evaluations: drops the new `status` and renames `evaluation_status` back)
- Models: `AiLanguageVariant`, `AiLanguageEvaluation` use `HasFlatTranslatedText` (`name` in the current language, `is_active` = status, flat `name` writes every stored language); `AiBenchmarkCase` uses `HasIsActiveAlias`. Query builders use `status`
- Engine: `AiChatLanguageResolver` applies the user's variant only while `isAvailable()` (on and not trashed); `GET user/v1/ai-language-preference` lists variants with `status`; `AiBenchmarkRunner` runs cases with `status` (trashed hidden by SoftDeletes). Switched off **or trashed** = not used
- `withTrashed` relations (to name a trashed row): `AiUserLanguagePreference::variant`, `AiLanguageEvaluation::variant`, `AiLanguageVariant::evaluations`, `AiBenchmarkResult::case`
- Force delete blocked (409): variant while `preferences` or `evaluations` (trash included) point at it (the FKs would null them); benchmark case while `results` (cascade). Evaluations: always allowed from the trash
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

## Logs, monitoring and privacy writers (2026-11-11, no schema change)

- `ai_reliability_metrics`: written by `ai:aggregate-reliability {--hours=1}` (`Services/Monitoring/AiReliabilityAggregator`, scheduled `hourlyAt(5)`) from `ai_provider_logs` (+ `ai_requests.intent_id`): one row per provider per hour (`intent_id` null) and one per provider + intent; success = `error_code` null; p50/p95/p99 nearest-rank; re-running an hour updates its rows; `model_key` / `region` / `plan_id` stay null
- `ai_safety_scans`: one row per `AiSafetyGuard::evaluate()` (`Services/Monitoring/AiSafetyScanRecorder`, from `AiChatService::sendMessage` with `request_id` and `AiTaskRunner::chat/image`): `target_type` input, `scan_type` harm, decision passed / blocked / sanitized / review_required, `findings` = {rule_id, policy_id, condition_type, action} (never the text)
- `ai_security_events`: `Services/Monitoring/AiSecurityEventRecorder` — `rate_limit_exceeded` (low; `ai-chat-send` / `ai-chat-general` limiter response callback, still throws `ThrottleRequestsException`), `blocked_owner_attempt` (medium; `AiChatService::sendMessage` on usage reason blocked, `AiTaskRunner::ownerBlocked`); at most one row per type + owner a minute (`Cache::add`)
- `ai_project_context`: `AiUserProjectService::rememberCapsule()` (from `AiContextContinuationService::continueFrom`) for projects holding a source conversation — one `summary` row per project (goal + done, updated in place), one `decision` row per decision (deduplicated, newest 30 kept); read back by `AiUserProjectService::systemMessage()`
- `ai_provider_logs.http_status`: the provider's last HTTP answer (`AbstractHttpConnector::client()` response middleware → `lastHttpStatus()`, passed to `AiGateway::logCall`); null = no HTTP answer
- `ai_provider_health`: `AiCircuitBreaker` writes a row only on a state change (degraded / unhealthy rows are updated in place while the state holds); `recovering` = half-open (open_until passed) — first success → healthy, first failure → unhealthy
- `ai_requests`: `retry_count` +1 per failover (`AiChatService::dispatchWithFallback`, `AiTaskRunner::dispatch`); `error_code` from `AiRequest::failureCode()` / constants: provider_error, circuit_open, safety_blocked, image_quota, request_failed
- Redaction: `ai_safety_events.reason` and `ai_verifications.draft_content` go through `minimizedForLog` (same as `ai_requests.prompt`)
- `ai:enforce-retention` also purges `ai_safety_scans`, `ai_provider_health` (the latest row per provider + check type kept), `ai_code_executions` (`config ai.retention.code_executions_days` / `AI_RETENTION_CODE_EXECUTIONS_DAYS`, empty = policy days) and `ai_audit_events` (`ai.retention.audit_events_days` / `AI_RETENTION_AUDIT_EVENTS_DAYS`; query-builder delete)
- `ai_benchmark_runs`: `AiBenchmarkRunner::run` marks the run `failed` (partial counts, `finished_at`) on an exception, and a shutdown guard does the same after a fatal / time-out
- Admin log resources (requests, usage, safety events / scans, security / audit events, files, document generations, language preferences) add `owner.phone` on `api/admin/*` only
