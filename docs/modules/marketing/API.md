# AI Marketing Director — API

Module `Modules/Marketing` · tasks [docs/marketing_tasks.md](../../marketing_tasks.md) · spec `DORR_AI_Marketing_Director_Technical_Spec_AR.docx` v1.0.

Same routes for both owners: **`/api/user/v1/marketing/*`** (`user_api`: user SPA and the mobile apps) and
**`/api/provider/v1/marketing/*`** (`provider_api`). Admin: **`/api/admin/v1/marketing/*`** (`admin_api`).
Envelope: `ApiResponse`. Errors: `error_code = marketing_<code>`, messages in `lang/{ar,en}/marketing.php`.

## Rules

- **Country**: read from the account (user: `logged_in_country_id` then `country_id`; provider: `country_id`), never from a header.
  Every route except `status` needs the `marketing` switch on there (`marketing.feature` middleware) and the dashboard's
  service-availability row for `module_name = marketing` not closed. Features: `research`, `plans`, `finance`, `export`, `team`.
  No rule = off.
- **Package**: making something new needs the project owner's current package (`marketing_package_required`, 402). Limits per
  period: `projects` (active at once), `research_runs`, `plan_generations`, `members` (per project) — `marketing_limit_reached` (429,
  `data: {limit_key, limit, used}`). Reading never needs a package.
- **Team** (per project): `owner` (all, approves, manages team, approves budgets), `manager` (edits, writes plans, approves plans
  and content — owner decision 2026-11-11), `analyst` (reads, research, enters results), `viewer` (reads). `abilities`:
  `edit, research, approve, manage_team, approve_budget, results`. Not on the team → `marketing_not_member` (403, audited); role too low → `marketing_role_forbidden` (403).
- **Jobs**: research and plan writing answer **202** with a job. Each move is pushed as `marketing.job.changed`
  (`{job_id, project_id, job_type, status, progress_pct, error_code}`) on every member's private account channel
  (`Modules.User.Models.User.{id}` / `Modules.Provider.Models.Provider.{id}`). Apps reload once; `GET projects/{id}/jobs` is the catch-up.
- **Money** is integers in minor units, in the project's `currency`.
- **Throttles** (per user): projects create 20/min, discovery suggest 10/min, members 30/min, research 10/min, plans 10/min, export 20/min,
  calendar 6/min, studio 10/min, content 60/min, budgets 30/min, results 30/min, results file 10/min.
- **Package limits** also include `content_generations` (calendar + ad studio jobs per period).

## Endpoints

| Method | Path | Notes |
|---|---|---|
| GET | `status` | `{available, features, research_ready, country, subscription{active, package, starts_at, ends_at, paid_until, usage, limits}, packages[{code,name,description,period_days,limits,price_minor,currency_code}], checkout_purpose}` |
| GET | `projects?status=&q=&page=&per_page=` | my projects (any role), pages of 15 |
| POST | `projects` | `{name, industry, stage?: idea\|launch\|growth, goals?, cities?}` — the caller is the owner |
| GET | `projects/{id}` | `{id, name, industry, stage, goals, cities, currency, status, lock_version, my_role, abilities, members_count, profile}` |
| PATCH | `projects/{id}` | same fields + **`lock_version`** (409 `version_conflict` with the current one) |
| POST | `projects/{id}/archive` | `{archived: bool}` (owner) |
| DELETE | `projects/{id}` | owner |
| GET | `projects/{id}/jobs` | latest 20 jobs |
| GET | `jobs/{job}` | `{id, type, status, progress_pct, error_code, retryable, plan_type}` |
| GET | `projects/{id}/discovery` | `{complete, answered, total, questions[{key, type: text\|list\|money\|number, origin: core\|ai, required, text, answered, value}]}` |
| PUT | `projects/{id}/discovery` | `{answers: {key: value}, clear?: [key]}` — `null` / `clear` = "I don't know yet" (asked again) |
| POST | `projects/{id}/discovery/suggest` | up to 5 AI questions about the business (never country / city / budget) |
| GET/POST | `projects/{id}/members` | POST `{phone, role: manager\|analyst\|viewer}` (owner; same account kind as the owner) |
| PATCH/DELETE | `projects/{id}/members/{member}` | owner; the owner row can't change |
| POST | `projects/{id}/leave` | any member but the owner |
| GET | `projects/{id}/research-runs?kind=` | with `sources_count`, `findings_count` |
| POST | `projects/{id}/research-runs` | `{kind: market\|competitor\|audience, scope?: {cities, segments, keywords}, max_sources?: 3..15}` → 202 `{run, job}` |
| GET | `research-runs/{run}` | `{status, summary, gaps, quality_score, error_code, sources[{ref, plan_ref, url, title, publisher, published_at, retrieved_at, excerpt, credibility}], findings[{claim, sources, confidence, is_assumption, is_quantitative}]}` |
| GET | `projects/{id}/plans` | one plan per type |
| POST | `projects/{id}/plans` | `{type: business\|marketing\|gtm, instructions?}` → 202 `{plan, job}`; 409 `job_running` |
| GET | `plans/{plan}` | `{id, type, status, versions_count, current_version{version, origin, content, content_hash, critic, scenarios}, approval}` |
| GET | `plans/{plan}/versions`, `plans/{plan}/versions/{n}` | history (versions never change) |
| PUT | `plans/{plan}` | `{version, content}` — new version (`origin: edit`); 409 if `version` isn't current; 422 `plan_incomplete` / `no_changes` |
| PUT | `plans/{plan}/finance` | `{version, inputs{price_minor, variable_cost_minor, fixed_costs_minor, marketing_spend_minor, expected_new_customers, expected_conversions, attributed_revenue_minor, units_per_customer, monthly_churn_pct}}` — new version, scenarios computed |
| POST | `plans/{plan}/submit` | `{version}` DRAFT → READY_FOR_REVIEW (refused with blocking critic issues) |
| POST | `plans/{plan}/approve` | `{version}` owner or manager; READY_FOR_REVIEW → APPROVED; approval bound to the version's `content_hash` |
| POST | `plans/{plan}/return` | `{version, note?}` owner or manager; back to DRAFT |
| POST | `plans/{plan}/archive` | owner; active approval → `revoked` |
| GET | `plans/{plan}/versions/{n}/export?format=pdf\|docx` | file download (RTL handled by the AI module's renderers) |

**States.** Research: `QUEUED → RUNNING → EVIDENCE_CHECK → COMPLETED | PARTIAL | FAILED`. Plan: `DRAFT → READY_FOR_REVIEW → APPROVED`, `ARCHIVED`.
Any new version (AI, edit, finance) puts the plan back to `DRAFT` and marks active approvals `superseded`.

**Evidence.** A run with no source never calls the AI (`PARTIAL`, `insufficient_sources`). A finding citing a ref the run doesn't have
loses it; a finding left without sources is kept as an assumption, unless it has a number (Latin or Arabic digits) — then it is
dropped. Under 3 sources = `PARTIAL`. `quality_score = 100 × sourced/kept × min(1, sources/3)`. Source text is stripped of markup
and fenced as data. Plans cite project-wide refs `S<source id>`; unknown refs are removed by the critic.

**Finance.** Code only (`FinancialCalculator`, `calculation_version` `fin-1`). Each output `{value, reason, basis: estimated}`;
`reason`: `missing_input`, `non_positive_margin`, `no_customers`, `no_conversions`, `no_spend`, `no_churn`. Scenarios: conservative
(volume ×0.7, spend ×1.1), base, optimistic (volume ×1.3).

## Phase B — execution (no publishing, no spend)

Features (country switches): `calendar`, `content`, `budgets`, `results`. DORR never posts or pays: "published" is the person
saying they posted it.

| Method | Path | Notes |
|---|---|---|
| GET | `projects/{id}/calendar?from=&to=` | items by `planned_at` (≤ 62 days, default 7) + `unplanned` count |
| POST | `projects/{id}/calendar/generate` | `{days: 7\|14\|30, from?, plan?, channels?, replace_drafts? (true), timezone?}` → 202 `{job (type calendar), plan_id}`; needs an APPROVED gtm/marketing plan (`approved_plan_required` 409); one at a time (`job_running`). Items land as DRAFT (tasks OPEN) with their claims review; `replace_drafts` deletes only calendar drafts nobody edited (`lock_version` 1) |
| GET | `projects/{id}/content?status=A,B&kind=&channel=` | pages of 20 |
| POST | `projects/{id}/content` | `{kind: post\|ad\|task, channel, format?, title, objective?, variants[{headline, body, cta, hashtags, visual_brief}] (≤ 3), selected?, planned_at?, cost_estimate_minor?, plan?}` |
| POST | `projects/{id}/content/generate` | ad studio `{channel, brief, kind?, format?, objective?, variants: 2\|3, planned_at?, plan?}` → 202 `{job (type content)}`; the finished job's `created` lists the new item ids |
| GET/PATCH/DELETE | `content/{item}` | PATCH needs `lock_version` (409 `version_conflict`); new words (title, channel, format, variants, selected) on a reviewed/approved item → DRAFT and its approval `superseded`; time and cost estimate don't. PUBLISHED can't change or be deleted |
| POST | `content/{item}/submit` | DRAFT/REJECTED → POLICY_REVIEW; refused with blocking claims (`content_has_blocking_issues` 422, `data.claims`) |
| POST | `content/{item}/approve` | `{lock_version}` owner or manager → APPROVED; approval bound to the hash of the words |
| POST | `content/{item}/reject` | `{note?}` → REJECTED |
| POST | `content/{item}/schedule` | `{planned_at}` (future) APPROVED/SCHEDULED → SCHEDULED, only while the approval matches the words (`approval_required` 409) |
| POST | `content/{item}/outcome` | `{published: bool, url?, note?}` → PUBLISHED / FAILED (same approval check) |
| POST | `content/{item}/done` | tasks: `{done: bool}` → DONE / OPEN |
| GET/POST | `projects/{id}/budgets` | `{kind: ads\|ai, period_start, period_end (≤ 1 year), cap_minor (ads) \| cap_calls (ai), alert_pct 50..100 (80), note?}`; owner → APPROVED, anyone else → PENDING; overlapping same kind → `budget_overlap` 409. Each: `{cap, used, estimated, remaining, pct, level: ok\|warning\|exceeded, status, active, lock_version}` |
| PATCH | `budgets/{budget}` | `{lock_version, cap_*?, period_end?, alert_pct?, note?}`; a new cap by a non-owner goes back to PENDING (AT-13) |
| POST | `budgets/{budget}/approve`, `budgets/{budget}/close` | owner |
| GET | `projects/{id}/metrics?from=&to=&channel=` | entered results, pages of 50 |
| POST | `projects/{id}/metrics` | `{rows: [{date, channel, basis: imported\|estimated, impressions?, clicks?, spend_minor?, conversions?, revenue_minor?, content_id?, note?}]}` (≤ 200) → `{created, updated}`; same project/channel/day/basis/content = update; impossible rows (clicks > impressions, future date, all empty) → `invalid_rows` 422 `data.errors[{line, field}]`, nothing saved |
| POST | `projects/{id}/metrics/import` | multipart `file` (CSV ≤ 1 MB, ≤ 2000 rows; `,` `;` or tab; UTF-8 or Windows-1256; English/Arabic headers: date, channel, impressions, clicks, spend, conversions, revenue; money in major units) → `{created, updated, rows}`; any bad line → `invalid_file` 422 with the lines, nothing saved |
| DELETE | `metrics/{id}` | |
| GET | `projects/{id}/kpis?from=&to=` | default last 30 days: `{measured{rows, totals, rates}, estimated (apart, never added), by_channel[], series[], gaps{days, days_with_data, days_without_data, channels_without_revenue, channels_without_spend}, last_updated_at, sources}`. Rates `ctr_pct, cpc_minor, cpa_minor, conversion_rate_pct, roas` are `{value, reason}`, computed from the rows that have both numbers; a missing number is null, never 0 |
| GET | `projects/{id}/executive-report?period=day\|week\|month` | `{decisions[], results{current, previous, change_pct, estimated, last_updated_at, days_with_data}, risks[], recommendations[], note}`; each line `{code, level, text (reader's language), item_id?}`. Rules only — opens with the AI down |

Claims review (in code, `ClaimsReviewer`): `guaranteed_result` and `empty_text` block; `absolute_claim`, `number_needs_proof`,
`price_claim`, `too_long_for_channel` (x 280, sms 160, google 90), `headline_too_long` (60) warn.

AI budget: every marketing AI call of a project is counted (`marketing_ai_calls`); an APPROVED `ai` budget covering today
refuses the next call once used up (`ai_budget_reached` 429). Crossing a budget's alert line or cap notifies the team once
(`marketing.budget.alert`, in-app + push) and is audited.

## Phase C — ad platforms and campaigns (Meta first)

Features: `platforms`, `campaigns`, and one per platform (`platform_meta`; AT-18 → `platform_unavailable` 403). The AI never
publishes or spends; campaigns are created **PAUSED** on the platform and the person starts them in the platform's own tools.

| Method | Path | Notes |
|---|---|---|
| GET | `projects/{id}/integrations` | `{integrations[{id, provider, status: PENDING\|CONNECTED\|EXPIRED\|REVOKED\|ERROR, account{id, name, currency}, available_accounts, sandbox, reconnect_needed}], platforms[{provider, available, coming_soon, configured}]}` — never the token |
| POST | `projects/{id}/integrations/{provider}/connect` | owner. Sandbox mode: connects now with the configured sandbox token. OAuth: `{authorize_url}` (one-time `state`, 10 min, stored hashed; scopes `ads_management,ads_read`) |
| GET | `/api/marketing/v1/oauth/{provider}/callback` | public; exchanges the code (long-lived token, stored encrypted), one ad account → CONNECTED, several → `available_accounts`; answers a small "go back to the app" page; pushes `marketing.integration.changed` |
| POST | `integrations/{id}/account` `{account_id}` · `integrations/{id}/disconnect` | owner; disconnect revokes the token on the platform and wipes it |
| GET/POST | `projects/{id}/campaigns` | `{integration?, content?, name, objective: awareness\|traffic\|engagement\|leads\|sales, daily_budget_minor, start_at, end_at, targeting{countries[ISO2], age_min?, age_max?}}` → DRAFT; `total_budget_minor = daily × days`; currency = the ad account's |
| GET/PATCH/DELETE | `campaigns/{id}` | PATCH `{version, …}` (409 `version_conflict`) → version + 1, DRAFT, approvals superseded (POL-02, AT-13); no change/delete while SUBMITTING/PUBLISHED/UNKNOWN |
| POST | `campaigns/{id}/validate` | → VALIDATED + `payload_hash`, or 422 `campaign_invalid` `data.issues[{code, detail}]`: `platform_off, integration_not_connected (AT-04), integration_expired, currency_mismatch, budget_too_low (< 100 minor/day), start_in_past, bad_dates (≤ 90 days), no_audience, content_not_approved, content_has_blocking_issues` — the campaign stays a draft |
| POST | `campaigns/{id}/approval-requests` | VALIDATED → AWAITING_APPROVAL; notifies the owner and active delegates |
| POST | `campaigns/{id}/approve` `{version}` | owner, or a manager with an active delegation whose remaining cap covers `total_budget_minor` (`spend_approval_forbidden` / `delegation_cap_exceeded` 403). Approval = version + payload hash + cap + currency, valid 7 days (or until the end date) |
| POST | `campaigns/{id}/reject` `{note?}` | → REJECTED |
| POST | `campaigns/{id}/publish` | header **`Idempotency-Key`** (8–80 chars, `idempotency_required` 422) + `{approval_id, campaign_version, expected_budget_minor, currency}`. Same key again → the first result (`replayed: true`, nothing sent, AT-14). Checks (POL-01): APPROVED, approval active/not expired, same version, same payload hash, budget ≤ cap and = expected, currency, delegation still valid (AT-11) → else 409 `approval_invalid` `data.reason`. Then SUBMITTING → platform (campaign + ad set, PAUSED, a `dorr-…` marker in the name) → PUBLISHED; no answer → **UNKNOWN** (AT-06); token rejected → integration EXPIRED, campaign back to APPROVED (AT-08); refused → FAILED with the platform's reason |
| POST | `campaigns/{id}/reconcile` | UNKNOWN: finds the campaign by its marker → PUBLISHED, or not created → APPROVED (publish again with a new key). PUBLISHED: refreshes `platform_status`. While UNKNOWN, publish answers `reconcile_required` 409 |
| POST | `campaigns/{id}/metrics/sync` · GET `campaigns/{id}/metrics` | the platform's daily insights → results with `source: platform`, `basis: actual` (`synced_at`) |
| GET/POST | `projects/{id}/delegations` | owner: `{member_id (a manager), cap_minor, expires_at (≤ 90 days)}`; each `{cap_minor, used_minor, remaining_minor, status, expires_at}` |
| DELETE | `delegations/{id}` | owner; campaign approvals given under it and not published → revoked, campaigns back to AWAITING_APPROVAL (AT-11) |
| GET/POST | `/api/marketing/v1/webhooks/{provider}` | public; GET = Meta subscription check (`hub.verify_token`); POST signed `X-Hub-Signature-256` (403 otherwise), a repeated delivery is ignored (POL-15); updates `platform_status` |

Realtime: `marketing.integration.changed`, `marketing.campaign.changed` (`{project_id, …_id, status}`) on each member's channel.

## Phase D — optimisation (nothing changes spending)

Feature `optimisation`.

| Method | Path | Notes |
|---|---|---|
| GET/POST | `projects/{id}/experiments` | `{name, hypothesis, success_metric: ctr\|conversion_rate, content? (uses its A/B/C versions), labels?}`; each has `preview` (live verdict) and `conclusion` |
| POST | `experiments/{id}/results` `{variant, impressions?, clicks?, conversions?, spend_minor?}` · `experiments/{id}/start` · `experiments/{id}/conclude` | verdict in code (two-proportion z-test): `missing_data`, `too_small` (< 100 trials per version), `no_difference` (p ≥ 0.05), `winner` (+ `lift_pct`, `p_value`), with a text that never claims more than the test shows |
| GET/POST | `projects/{id}/alert-rules` `{metric: spend\|cpa\|roas\|ctr\|conversions, comparison: above\|below, threshold, window_days: 1\|7\|14\|30, channel?}` | checked whenever results arrive (by hand, file or platform), measured results only, at most once a day; notifies the team (`marketing.alert.fired`) and is audited |
| PATCH/DELETE | `alert-rules/{id}` | `{active}` |
| GET | `projects/{id}/optimisations` | `{suggestions[{code, priority, channel, evidence, text}], note}`: `need_results, spend_without_results, expensive_channel (> 2× average CPA), test_shift_budget, creative_fatigue (CTR −30% week on week), track_revenue, pacing_over_cap` |
| POST | `plans/{plan}/simulate` `{swing_pct: 10\|20\|30\|50, inputs?}` | from the plan's base finance inputs: `tornado` (each input ± swing, sorted by effect on monthly profit) and a spend × customers profit `grid`; `basis: estimated` |

## Checkout

Purpose **`marketing_package`**, reference `{package: code}` on `POST {mobile|provider}/v1/wallet/checkouts`. Priced per country
(`marketing_package_prices`); sold only where the service is on. A purchase before the end starts the next period after the paid one.

## Admin (`/api/admin/v1/marketing`)

| Path | Permission |
|---|---|
| `GET feature-rules/options`, `GET feature-rules?country_id=`, `POST feature-rules`, `POST feature-rules/copy` | `marketing-feature-rules.view/update` |
| `GET/POST packages`, `GET/PUT/DELETE packages/{id}` (`prices: [{country_id, price_minor, status}]`) | `marketing-packages.*` |
| `GET subscriptions?active=`, `GET projects?status=`, `GET jobs?status=`, `GET audit?project=` | `marketing-operations.view` — numbers and states only, never plan or research content |

## Configuration (`.env`)

Ad platforms: `MARKETING_META_APP_ID`, `MARKETING_META_APP_SECRET` (OAuth + webhook signatures), `MARKETING_META_SANDBOX=true` with
`MARKETING_META_SANDBOX_TOKEN` and `MARKETING_META_SANDBOX_ACCOUNT` (a Meta sandbox ad account: no funding, no delivery, no
spend — the easiest way to test together), `MARKETING_META_WEBHOOK_VERIFY_TOKEN`, optional `MARKETING_META_GRAPH_VERSION` (v21.0).
OAuth redirect to register in the Meta app: `{APP_URL}/api/marketing/v1/oauth/meta/callback`; webhook: `{APP_URL}/api/marketing/v1/webhooks/meta`.

`MARKETING_RESEARCH_DRIVER=tavily|none`, `TAVILY_API_KEY` (free key at https://tavily.com), optional `TAVILY_API_URL`,
`TAVILY_SEARCH_DEPTH=basic`, `TAVILY_TIMEOUT=20`. AI calls go through `AiTaskRunner` (default provider, e.g. OpenAI) with
`free = true`: the marketing package pays, no DORR AI plan is asked for.

## Tables

`marketing_feature_rules`, `marketing_packages`, `marketing_package_prices`, `marketing_subscriptions`, `marketing_projects`,
`marketing_project_profiles`, `marketing_project_members`, `marketing_jobs`, `marketing_research_runs`, `marketing_sources`,
`marketing_findings`, `marketing_plans`, `marketing_plan_versions`, `marketing_financial_scenarios`, `marketing_approvals`,
`marketing_audit_events` (append-only) — migration `2026_11_09_100000_create_marketing_tables`; phase B:
`marketing_content_items`, `marketing_budgets`, `marketing_metrics`, `marketing_ai_calls` — `2026_11_11_100000_create_marketing_execution_tables`;
admin permissions — `2026_11_11_120000_add_marketing_admin_permissions`; phase C: `marketing_integrations`, `marketing_campaigns`,
`marketing_campaign_actions` (unique project + Idempotency-Key + action), `marketing_webhook_events`, delegation columns on
`marketing_approvals` — `2026_11_11_130000_create_marketing_platform_tables`; phase D: `marketing_experiments`, `marketing_alert_rules` —
`2026_11_11_140000_create_marketing_optimisation_tables`.
