# DORR AI Marketing Director — tasks

Source: `DORR_AI_Marketing_Director_Technical_Spec_AR.docx` (v1.0, 2026-11-05). Module: `Modules/Marketing`.
Reserve a task with your name before starting; tick it when done. API: [docs/modules/marketing/API.md](modules/marketing/API.md).

## Owner decisions (2026-11-09)

| # | Question | Decision |
|---|----------|----------|
| D1 | Who uses it | DORR users (`user_api`, web + mobile app), providers (`provider_api`, web), DORR staff (admin oversight) |
| D2 | Organisations / branches | **Not now** — a team per project (owner + invited members with a role) |
| D3 | Research source | Tavily search API (free tier, 1,000 credits/month) behind a driver interface; the AI layer (ChatGPT/OpenAI through `AiTaskRunner`) reads the sources. `MARKETING_RESEARCH_DRIVER=none` keeps research honest ("no sources") |
| D4 | Availability | Per country, like the other services: admin switches per country (`marketing-feature-rules`) **and** the dashboard's service-availability page (`module_name = marketing`) |
| D5 | Pricing | A **separate package** (`marketing_packages` + price per country), bought on the wallet checkout screen (purpose `marketing_package`). It pays for its own AI calls (`free=true` on `AiTaskRunner`) — no DORR AI plan needed |
| D6 | Ad platforms | All of them eventually; **Meta first** (Marketing API sandbox ad account: no funding source, no delivery, no spend) |
| D7 | Spend approval | Per campaign **and** a delegation with an expiry and a cap |

Rules kept from the spec: the AI never publishes or spends; every number needs a source or is marked an assumption;
financial figures come from code, not from the model; an approved plan is never edited silently (new version, old approval superseded).
No polling (AGENTS.md): job progress is pushed as `marketing.job.changed`.

## Phase A — MVP (plans only, no publishing, no spend)

| ID | Task | Owner | Status |
|----|------|-------|--------|
| MK-A01 | Module skeleton, config, errors (`marketing_<code>`), lang ar/en | Claude | ✅ |
| MK-A02 | Country switches (`MarketingFeatures`, `marketing.feature:<x>` middleware, admin API) | Claude | ✅ |
| MK-A03 | Package + per-country price + subscription + checkout purpose + limits per period | Claude | ✅ |
| MK-A04 | Projects CRUD with optimistic `lock_version`, project team (owner/manager/analyst/viewer) | Claude | ✅ |
| MK-A05 | Discovery: fixed missing-question set + optional AI extra questions, answers into the profile | Claude | ✅ |
| MK-A06 | Research runs (market/competitor/audience): job, sources, findings, evidence guard, prompt-injection fencing | Claude | ✅ |
| MK-A07 | Plans business/marketing/gtm: schema check, versions, review → approve, edit = new version | Claude | ✅ |
| MK-A08 | Deterministic finance (CAC/CPA/ROAS/contribution margin/break-even/LTV) + 3 scenarios | Claude | ✅ |
| MK-A09 | Export PDF/DOCX (RTL) through `AiDocumentRenderer` | Claude | ✅ |
| MK-A10 | Audit trail + realtime `marketing.job.changed` | Claude | ✅ |
| MK-A11 | Admin API: switches, packages + prices, subscriptions, projects overview | Claude | ✅ |
| MK-A12 | Acceptance tests AT-01/02/03/07/09/12/16/17 + package/switch/team tests | Claude | ✅ |
| MK-A13 | Android screens (entry, packages, projects, discovery, research, plans, finance, team) | Claude | ✅ compiles (`compileDebugKotlin`); not tried on a device |
| MK-A14 | iOS twin of MK-A13 | Claude | ✅ code only — not built (no Xcode on this machine) |
| MK-A15 | Admin Vue pages (switches, packages, subscriptions, projects) | Claude | ✅ `views/marketing/{countries,packages,operations}` + sidebar "AI Marketing" (header service = marketing) + guide map; `vite build` passes |
| MK-A16 | User + provider web SPA screens (U01–U05) | Claude | ✅ `npm run build` passes; not clicked through in a browser |

## Phase B — manual execution

| ID | Task | Status |
|----|------|--------|
| MK-B01 | Daily calendar (U06) generated from an approved GTM plan | ✅ API + tests + Android + iOS (code) + web |
| MK-B02 | Ad studio (U07): A/B copy, claims review | ✅ API + tests + Android + iOS (code) + web |
| MK-B03 | Budgets (U08): AI cap separate from ad-spend cap, alerts | ✅ API + tests + Android + iOS (code) + web |
| MK-B04 | Manual results import + KPI board (U11) with actual / estimated / imported labels | ✅ API + tests + Android + iOS (code) + web |
| MK-B05 | Executive report (U12) | ✅ API + tests + Android + iOS (code) + web |

## Phase C — platforms (Meta first)

| ID | Task | Status |
|----|------|--------|
| MK-C01 | OAuth vault (encrypted `credential_ref`, PKCE when supported), connect / disconnect, `EXPIRED` handling | ✅ API + tests (fake Graph API) + Android + iOS (code) + web |
| MK-C02 | Meta adapter (sandbox ad account), campaigns created `PAUSED` | ✅ API + tests (fake Graph API) + Android + iOS (code) + web |
| MK-C03 | Campaign state machine DRAFT → VALIDATED → AWAITING_APPROVAL → APPROVED → SUBMITTING → PUBLISHED/FAILED/UNKNOWN, Idempotency-Key, reconcile | ✅ API + tests (fake Graph API) + Android + iOS (code) + web |
| MK-C04 | Spend approvals: per campaign (payload hash + version + cap) and time/cap delegations (D7) | ✅ API + tests (fake Graph API) + Android + iOS (code) + web |
| MK-C05 | Metrics import with timestamps, signed webhooks | ✅ API + tests (fake Graph API) + Android + iOS (code) + web |
| MK-C06 | Google, TikTok, Snapchat adapters | ☐ |

## Phase D — optimisation

| ID | Task | Status |
|----|------|--------|
| MK-D01 | A/B experiments judged in code (z-test, minimum sample) | ✅ API + tests |
| MK-D02 | Alert rules on measured KPIs (once a day, notify only) | ✅ API + tests |
| MK-D03 | Suggested optimisations with their evidence (never applied automatically) | ✅ API + tests |
| MK-D04 | Advanced simulation (tornado + spend × customers grid) | ✅ API + tests |
| MK-D05 | Phase D screens (mobile + web) | Android ✅; iOS ✅ code (not built); web ✅ build passes |

No automatic spend changes. Multi-branch stays out (D2).

## Open (NEEDS-DECISION)

- Package limits per period (projects, research runs, plan generations, members) — defaults are admin-set per package.
- ~~Who may approve a plan besides the owner~~ — decided 2026-11-11: the **manager approves too** (plans and content). Budgets (money) stay owner-only.
- A Tavily API key (free) must be put in `.env` (`TAVILY_API_KEY`); without it research honestly ends with "no sources".
- Meta: a Meta developer app + a **sandbox ad account** (Marketing API → Tools) to test together — put `MARKETING_META_SANDBOX=true`, the sandbox token and account id in `.env`. Real accounts later need the app's `ads_management` access (Meta app review) and `MARKETING_META_APP_ID/SECRET`.
- A real campaign needs an ad creative (a Facebook Page + image) — phase C creates the campaign and ad set only (paused); creating the ad itself is the next step once a Page is linked.
- The admin has to switch the service on per country and price a package before anyone can use it (admin → AI Marketing → Countries & switches, Packages).
