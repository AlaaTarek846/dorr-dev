import Foundation

/// androidApp `network/MarketingApi.kt` — DORR AI Marketing Director (docs/marketing_tasks.md) under
/// /api/user/v1/marketing, like the other AI tools. Money is an integer in minor units with the project's `currency`.
/// Long jobs (research, plan writing) answer 202 with a job; its moves arrive as the realtime `marketing.job.changed`.
enum MarketingApi {
    /// Open here? The package and its prices; works even when the service is off (`available: false`).
    static func status() async throws -> ApiEnvelope<MkStatusDto> {
        try await ApiClient.get("user/v1/marketing/status", auth: true)
    }

    static func projects(status: String? = nil, q: String? = nil, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[MkProjectDto]> {
        try await ApiClient.get("user/v1/marketing/projects", query: ["status": status, "q": q, "page": String(page), "per_page": String(perPage)], auth: true)
    }

    /// `marketing_package_required` (402) without a package; `marketing_limit_reached` (429) at the package's limit.
    static func createProject(_ body: MkProjectRequest) async throws -> ApiEnvelope<MkProjectDto> {
        try await ApiClient.post("user/v1/marketing/projects", body: body, auth: true)
    }

    static func project(_ id: String) async throws -> ApiEnvelope<MkProjectDto> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)", auth: true)
    }

    /// `lock_version` must be the one read, or `marketing_version_conflict` (409).
    static func updateProject(_ id: String, _ body: MkProjectRequest) async throws -> ApiEnvelope<MkProjectDto> {
        try await ApiClient.patch("user/v1/marketing/projects/\(id)", body: body)
    }

    static func archiveProject(_ id: String, archived: Bool) async throws -> ApiEnvelope<MkProjectDto> {
        try await ApiClient.post("user/v1/marketing/projects/\(id)/archive", body: MkArchiveRequest(archived: archived), auth: true)
    }

    static func deleteProject(_ id: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.delete("user/v1/marketing/projects/\(id)")
    }

    /// The latest jobs: the catch-up on open and on reconnect.
    static func jobs(_ id: String) async throws -> ApiEnvelope<[MkJobDto]> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/jobs", auth: true)
    }

    // MARK: - discovery

    static func discovery(_ id: String) async throws -> ApiEnvelope<MkDiscoveryDto> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/discovery", auth: true)
    }

    /// `{answers: {key: value}, clear: [key]}` — `clear` empties an answer ("I don't know yet").
    static func answer(_ id: String, answers: [String: JSONValue], clear: [String]) async throws -> ApiEnvelope<MkDiscoveryDto> {
        try await ApiClient.put("user/v1/marketing/projects/\(id)/discovery", body: MkAnswerBody(answers: answers, clear: clear))
    }

    static func suggestQuestions(_ id: String) async throws -> ApiEnvelope<MkDiscoveryDto> {
        try await ApiClient.send("user/v1/marketing/projects/\(id)/discovery/suggest", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    // MARK: - team

    static func members(_ id: String) async throws -> ApiEnvelope<[MkMemberDto]> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/members", auth: true)
    }

    static func addMember(_ id: String, _ body: MkMemberRequest) async throws -> ApiEnvelope<MkMemberDto> {
        try await ApiClient.post("user/v1/marketing/projects/\(id)/members", body: body, auth: true)
    }

    static func changeRole(_ id: String, member: Int, role: String) async throws -> ApiEnvelope<MkMemberDto> {
        try await ApiClient.patch("user/v1/marketing/projects/\(id)/members/\(member)", body: MkRoleRequest(role: role))
    }

    static func removeMember(_ id: String, member: Int) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.delete("user/v1/marketing/projects/\(id)/members/\(member)")
    }

    static func leave(_ id: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.send("user/v1/marketing/projects/\(id)/leave", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    // MARK: - research

    static func researchRuns(_ id: String, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[MkRunDto]> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/research-runs", query: ["page": String(page), "per_page": String(perPage)], auth: true)
    }

    /// 202: `{run, job}`.
    static func startResearch(_ id: String, _ body: MkResearchRequest) async throws -> ApiEnvelope<MkStartedRunDto> {
        try await ApiClient.post("user/v1/marketing/projects/\(id)/research-runs", body: body, auth: true)
    }

    static func researchRun(_ run: String) async throws -> ApiEnvelope<MkRunDto> {
        try await ApiClient.get("user/v1/marketing/research-runs/\(run)", auth: true)
    }

    // MARK: - plans

    static func plans(_ id: String) async throws -> ApiEnvelope<[MkPlanDto]> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/plans", auth: true)
    }

    /// 202: `{plan, job}`; `marketing_job_running` (409) while one of the same type is being written.
    static func generatePlan(_ id: String, _ body: MkPlanRequest) async throws -> ApiEnvelope<MkStartedPlanDto> {
        try await ApiClient.post("user/v1/marketing/projects/\(id)/plans", body: body, auth: true)
    }

    static func plan(_ plan: String) async throws -> ApiEnvelope<MkPlanDto> {
        try await ApiClient.get("user/v1/marketing/plans/\(plan)", auth: true)
    }

    static func versions(_ plan: String) async throws -> ApiEnvelope<[MkVersionRowDto]> {
        try await ApiClient.get("user/v1/marketing/plans/\(plan)/versions", auth: true)
    }

    static func version(_ plan: String, _ version: Int) async throws -> ApiEnvelope<MkVersionDto> {
        try await ApiClient.get("user/v1/marketing/plans/\(plan)/versions/\(version)", auth: true)
    }

    /// `{version, content}`: a new version; the plan goes back to DRAFT and an old approval stops counting.
    static func editPlan(_ plan: String, version: Int, content: JSONAny) async throws -> ApiEnvelope<MkPlanDto> {
        try await ApiClient.put("user/v1/marketing/plans/\(plan)", body: MkEditBody(version: version, content: content))
    }

    /// `{version, inputs}`: a new version whose three scenarios the server computes.
    static func setFinance(_ plan: String, version: Int, inputs: [String: JSONValue]) async throws -> ApiEnvelope<MkPlanDto> {
        try await ApiClient.put("user/v1/marketing/plans/\(plan)/finance", body: MkFinanceBody(version: version, inputs: inputs))
    }

    static func submitPlan(_ plan: String, version: Int) async throws -> ApiEnvelope<MkPlanDto> {
        try await ApiClient.post("user/v1/marketing/plans/\(plan)/submit", body: MkVersionRequest(version: version), auth: true)
    }

    static func approvePlan(_ plan: String, version: Int) async throws -> ApiEnvelope<MkPlanDto> {
        try await ApiClient.post("user/v1/marketing/plans/\(plan)/approve", body: MkVersionRequest(version: version), auth: true)
    }

    static func returnPlan(_ plan: String, version: Int) async throws -> ApiEnvelope<MkPlanDto> {
        try await ApiClient.post("user/v1/marketing/plans/\(plan)/return", body: MkVersionRequest(version: version), auth: true)
    }

    static func archivePlan(_ plan: String) async throws -> ApiEnvelope<MkPlanDto> {
        try await ApiClient.send("user/v1/marketing/plans/\(plan)/archive", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    /// The version as a file (pdf / docx), with my sign-in.
    static func export(_ plan: String, version: Int, format: String) async throws -> (Data, HTTPURLResponse?) {
        try await ApiClient.rawResponse("user/v1/marketing/plans/\(plan)/versions/\(version)/export", query: ["format": format], auth: true)
    }

    // MARK: - phase B: calendar + ad studio (U06/U07)

    static func calendar(_ id: String, from: String, to: String) async throws -> ApiEnvelope<MkCalendarDto> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/calendar", query: ["from": from, "to": to], auth: true)
    }

    /// 202 + job (`calendar`); `marketing_approved_plan_required` (409) without an approved GTM / marketing plan.
    static func generateCalendar(_ id: String, _ body: MkCalendarRequest) async throws -> ApiEnvelope<MkJobStartDto> {
        try await ApiClient.post("user/v1/marketing/projects/\(id)/calendar/generate", body: body, auth: true)
    }

    static func contentList(_ id: String, status: String? = nil, kind: String? = nil, perPage: Int = 20) async throws -> ApiEnvelope<[MkContentDto]> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/content", query: ["status": status, "kind": kind, "per_page": String(perPage)], auth: true)
    }

    static func createContent(_ id: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<MkContentDto> {
        try await ApiClient.post("user/v1/marketing/projects/\(id)/content", body: body, auth: true)
    }

    /// The ad studio: A/B(/C) variants written by the AI — 202 + job (`content`).
    static func generateContent(_ id: String, _ body: MkStudioRequest) async throws -> ApiEnvelope<MkJobStartDto> {
        try await ApiClient.post("user/v1/marketing/projects/\(id)/content/generate", body: body, auth: true)
    }

    static func content(_ item: String) async throws -> ApiEnvelope<MkContentDto> {
        try await ApiClient.get("user/v1/marketing/content/\(item)", auth: true)
    }

    /// `lock_version` must be the one read; new words on an approved item send it back to draft.
    static func updateContent(_ item: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<MkContentDto> {
        try await ApiClient.patch("user/v1/marketing/content/\(item)", body: body)
    }

    static func deleteContent(_ item: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.delete("user/v1/marketing/content/\(item)")
    }

    static func submitContent(_ item: String) async throws -> ApiEnvelope<MkContentDto> {
        try await ApiClient.send("user/v1/marketing/content/\(item)/submit", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    static func approveContent(_ item: String, _ body: MkLockRequest) async throws -> ApiEnvelope<MkContentDto> {
        try await ApiClient.post("user/v1/marketing/content/\(item)/approve", body: body, auth: true)
    }

    static func rejectContent(_ item: String, _ body: MkNoteRequest) async throws -> ApiEnvelope<MkContentDto> {
        try await ApiClient.post("user/v1/marketing/content/\(item)/reject", body: body, auth: true)
    }

    static func scheduleContent(_ item: String, _ body: MkScheduleRequest) async throws -> ApiEnvelope<MkContentDto> {
        try await ApiClient.post("user/v1/marketing/content/\(item)/schedule", body: body, auth: true)
    }

    /// The person posted it (or could not) — DORR never posts in phase B.
    static func contentOutcome(_ item: String, _ body: MkOutcomeRequest) async throws -> ApiEnvelope<MkContentDto> {
        try await ApiClient.post("user/v1/marketing/content/\(item)/outcome", body: body, auth: true)
    }

    static func taskDone(_ item: String, _ body: MkDoneRequest) async throws -> ApiEnvelope<MkContentDto> {
        try await ApiClient.post("user/v1/marketing/content/\(item)/done", body: body, auth: true)
    }

    // MARK: - budgets (U08)

    static func budgets(_ id: String) async throws -> ApiEnvelope<[MkBudgetDto]> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/budgets", auth: true)
    }

    static func createBudget(_ id: String, _ body: MkBudgetRequest) async throws -> ApiEnvelope<MkBudgetDto> {
        try await ApiClient.post("user/v1/marketing/projects/\(id)/budgets", body: body, auth: true)
    }

    static func updateBudget(_ budget: String, _ body: MkBudgetUpdateRequest) async throws -> ApiEnvelope<MkBudgetDto> {
        try await ApiClient.patch("user/v1/marketing/budgets/\(budget)", body: body)
    }

    static func approveBudget(_ budget: String, _ body: MkLockRequest) async throws -> ApiEnvelope<MkBudgetDto> {
        try await ApiClient.post("user/v1/marketing/budgets/\(budget)/approve", body: body, auth: true)
    }

    static func closeBudget(_ budget: String) async throws -> ApiEnvelope<MkBudgetDto> {
        try await ApiClient.send("user/v1/marketing/budgets/\(budget)/close", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    // MARK: - results, KPI board, report (U11/U12)

    static func metrics(_ id: String, perPage: Int = 30) async throws -> ApiEnvelope<[MkMetricRowDto]> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/metrics", query: ["per_page": String(perPage)], auth: true)
    }

    static func saveMetrics(_ id: String, _ body: MkMetricsRequest) async throws -> ApiEnvelope<MkImportResultDto> {
        try await ApiClient.post("user/v1/marketing/projects/\(id)/metrics", body: body, auth: true)
    }

    /// A CSV file (date, channel, impressions, clicks, spend, conversions, revenue) — all or nothing.
    static func importMetrics(_ id: String, file: ApiClient.FilePart) async throws -> ApiEnvelope<MkImportResultDto> {
        try await ApiClient.upload("user/v1/marketing/projects/\(id)/metrics/import", files: [file])
    }

    static func deleteMetric(_ metric: Int) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.delete("user/v1/marketing/metrics/\(metric)")
    }

    static func kpis(_ id: String, from: String?, to: String?) async throws -> ApiEnvelope<MkKpiBoardDto> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/kpis", query: ["from": from, "to": to], auth: true)
    }

    static func report(_ id: String, period: String) async throws -> ApiEnvelope<MkReportDto> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/executive-report", query: ["period": period], auth: true)
    }


    // MARK: - phase C: ad platforms + campaigns (U09/U10)

    static func integrations(_ id: String) async throws -> ApiEnvelope<MkIntegrationsDto> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/integrations", auth: true)
    }

    /// Owner. `authorize_url` to open in the browser (OAuth), or already CONNECTED (sandbox).
    static func connectPlatform(_ id: String, provider: String) async throws -> ApiEnvelope<MkConnectDto> {
        try await ApiClient.send("user/v1/marketing/projects/\(id)/integrations/\(provider)/connect", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    static func chooseAdAccount(_ integration: String, _ body: MkAccountRequest) async throws -> ApiEnvelope<MkIntegrationDto> {
        try await ApiClient.post("user/v1/marketing/integrations/\(integration)/account", body: body, auth: true)
    }

    static func disconnectPlatform(_ integration: String) async throws -> ApiEnvelope<MkIntegrationDto> {
        try await ApiClient.send("user/v1/marketing/integrations/\(integration)/disconnect", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    static func campaigns(_ id: String) async throws -> ApiEnvelope<[MkCampaignDto]> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/campaigns", auth: true)
    }

    static func createCampaign(_ id: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<MkCampaignDto> {
        try await ApiClient.post("user/v1/marketing/projects/\(id)/campaigns", body: body, auth: true)
    }

    static func campaign(_ campaign: String) async throws -> ApiEnvelope<MkCampaignDto> {
        try await ApiClient.get("user/v1/marketing/campaigns/\(campaign)", auth: true)
    }

    /// `version` must be the current one; any change is a new version and the old approval stops counting.
    static func updateCampaign(_ campaign: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<MkCampaignDto> {
        try await ApiClient.patch("user/v1/marketing/campaigns/\(campaign)", body: body)
    }

    static func deleteCampaign(_ campaign: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.delete("user/v1/marketing/campaigns/\(campaign)")
    }

    /// 422 `marketing_campaign_invalid` with `data.issues[{code, detail}]`.
    static func validateCampaign(_ campaign: String) async throws -> ApiEnvelope<MkCampaignDto> {
        try await ApiClient.send("user/v1/marketing/campaigns/\(campaign)/validate", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    static func requestCampaignApproval(_ campaign: String) async throws -> ApiEnvelope<MkCampaignDto> {
        try await ApiClient.send("user/v1/marketing/campaigns/\(campaign)/approval-requests", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    static func approveCampaign(_ campaign: String, _ body: MkCampaignVersionRequest) async throws -> ApiEnvelope<MkCampaignDto> {
        try await ApiClient.post("user/v1/marketing/campaigns/\(campaign)/approve", body: body, auth: true)
    }

    static func rejectCampaign(_ campaign: String, _ body: MkNoteRequest) async throws -> ApiEnvelope<MkCampaignDto> {
        try await ApiClient.post("user/v1/marketing/campaigns/\(campaign)/reject", body: body, auth: true)
    }

    /// The same Idempotency-Key for every retry of one publish (a repeat answers the first result, AT-14).
    static func publishCampaign(key: String, _ campaign: String, _ body: MkPublishRequest) async throws -> ApiEnvelope<MkPublishDto> {
        try await ApiClient.post("user/v1/marketing/campaigns/\(campaign)/publish", body: body, auth: true, headers: ["Idempotency-Key": key])
    }

    static func reconcileCampaign(_ campaign: String) async throws -> ApiEnvelope<MkCampaignDto> {
        try await ApiClient.send("user/v1/marketing/campaigns/\(campaign)/reconcile", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    static func syncCampaignMetrics(_ campaign: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.send("user/v1/marketing/campaigns/\(campaign)/metrics/sync", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    static func campaignMetrics(_ campaign: String) async throws -> ApiEnvelope<MkCampaignMetricsDto> {
        try await ApiClient.get("user/v1/marketing/campaigns/\(campaign)/metrics", auth: true)
    }

    static func delegations(_ id: String) async throws -> ApiEnvelope<[MkDelegationDto]> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/delegations", auth: true)
    }

    static func createDelegation(_ id: String, _ body: MkDelegationRequest) async throws -> ApiEnvelope<MkDelegationDto> {
        try await ApiClient.post("user/v1/marketing/projects/\(id)/delegations", body: body, auth: true)
    }

    static func revokeDelegation(_ delegation: String) async throws -> ApiEnvelope<MkDelegationDto> {
        try await ApiClient.delete("user/v1/marketing/delegations/\(delegation)")
    }

    // MARK: - phase D: optimisation (nothing changes spending)

    static func optimisations(_ id: String) async throws -> ApiEnvelope<MkOptimisationsDto> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/optimisations", auth: true)
    }

    static func alertRules(_ id: String) async throws -> ApiEnvelope<[MkAlertRuleDto]> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/alert-rules", auth: true)
    }

    static func createAlertRule(_ id: String, _ body: MkAlertRuleRequest) async throws -> ApiEnvelope<MkAlertRuleDto> {
        try await ApiClient.post("user/v1/marketing/projects/\(id)/alert-rules", body: body, auth: true)
    }

    static func toggleAlertRule(_ rule: String, _ body: MkActiveRequest) async throws -> ApiEnvelope<MkAlertRuleDto> {
        try await ApiClient.patch("user/v1/marketing/alert-rules/\(rule)", body: body)
    }

    static func deleteAlertRule(_ rule: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.delete("user/v1/marketing/alert-rules/\(rule)")
    }

    static func experiments(_ id: String) async throws -> ApiEnvelope<[MkExperimentDto]> {
        try await ApiClient.get("user/v1/marketing/projects/\(id)/experiments", auth: true)
    }

    static func createExperiment(_ id: String, _ body: MkExperimentRequest) async throws -> ApiEnvelope<MkExperimentDto> {
        try await ApiClient.post("user/v1/marketing/projects/\(id)/experiments", body: body, auth: true)
    }

    static func experimentResults(_ experiment: String, _ body: MkExperimentResultRequest) async throws -> ApiEnvelope<MkExperimentDto> {
        try await ApiClient.post("user/v1/marketing/experiments/\(experiment)/results", body: body, auth: true)
    }

    static func concludeExperiment(_ experiment: String) async throws -> ApiEnvelope<MkExperimentDto> {
        try await ApiClient.send("user/v1/marketing/experiments/\(experiment)/conclude", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    static func simulate(_ plan: String, _ body: MkSimulateRequest) async throws -> ApiEnvelope<MkSimulationDto> {
        try await ApiClient.post("user/v1/marketing/plans/\(plan)/simulate", body: body, auth: true)
    }
}

// MARK: - DTOs (same field names as Android)

struct MkStatusDto: Decodable {
    let available: Bool?
    let features: [String: Bool]?
    let researchReady: Bool?
    let country: MkCountryDto?
    let subscription: MkSubscriptionDto?
    let packages: [MkPackageDto]?
    let checkoutPurpose: String?

    enum CodingKeys: String, CodingKey {
        case available, features, country, subscription, packages
        case researchReady = "research_ready"
        case checkoutPurpose = "checkout_purpose"
    }
}

struct MkCountryDto: Decodable {
    let code: String?
    let currencyCode: String?
    enum CodingKeys: String, CodingKey { case code, currencyCode = "currency_code" }
}

struct MkSubscriptionDto: Decodable {
    let active: Bool?
    let pkg: MkPackageRefDto?
    let startsAt: String?
    let endsAt: String?
    let paidUntil: String?
    let usage: [String: Int]?
    let limits: [String: Int?]?

    enum CodingKeys: String, CodingKey {
        case active, usage, limits
        case pkg = "package"
        case startsAt = "starts_at"
        case endsAt = "ends_at"
        case paidUntil = "paid_until"
    }
}

struct MkPackageRefDto: Decodable {
    let code: String
    let name: String?
}

struct MkPackageDto: Decodable, Identifiable {
    let code: String
    let name: String?
    let description: String?
    let periodDays: Int?
    let limits: [String: Int?]?
    let priceMinor: Int?
    let currencyCode: String?

    var id: String { code }

    enum CodingKeys: String, CodingKey {
        case code, name, description, limits
        case periodDays = "period_days"
        case priceMinor = "price_minor"
        case currencyCode = "currency_code"
    }
}

struct MkAbilitiesDto: Decodable {
    let edit: Bool?
    let research: Bool?
    let approve: Bool?
    let manageTeam: Bool?
    let approveBudget: Bool?
    let results: Bool?
    enum CodingKeys: String, CodingKey { case edit, research, approve, results, manageTeam = "manage_team", approveBudget = "approve_budget" }
}

struct MkProfileDto: Decodable {
    let offer: String?
    let customerSegments: [String]?
    let channels: [String]?
    let teamSize: Int?
    let monthlyBudgetMinor: Int?

    enum CodingKeys: String, CodingKey {
        case offer, channels
        case customerSegments = "customer_segments"
        case teamSize = "team_size"
        case monthlyBudgetMinor = "monthly_budget_minor"
    }
}

struct MkProjectDto: Decodable, Identifiable {
    let id: String
    let name: String
    let industry: String
    let stage: String?
    let goals: [String]?
    let cities: [String]?
    let currency: String?
    let status: String?
    let lockVersion: Int?
    let myRole: String?
    let abilities: MkAbilitiesDto?
    let membersCount: Int?
    let profile: MkProfileDto?
    let updatedAt: String?

    enum CodingKeys: String, CodingKey {
        case id, name, industry, stage, goals, cities, currency, status, abilities, profile
        case lockVersion = "lock_version"
        case myRole = "my_role"
        case membersCount = "members_count"
        case updatedAt = "updated_at"
    }
}

struct MkProjectRequest: Encodable {
    var name: String?
    var industry: String?
    var stage: String?
    var cities: [String]?
    var goals: [String]?
    var lockVersion: Int?

    enum CodingKeys: String, CodingKey {
        case name, industry, stage, cities, goals
        case lockVersion = "lock_version"
    }
}

struct MkArchiveRequest: Encodable { let archived: Bool }

struct MkQuestionDto: Decodable, Identifiable {
    let key: String
    let type: String?
    let origin: String?
    let required: Bool?
    let text: String?
    let answered: Bool?
    let value: JSONAny?

    var id: String { key }
}

struct MkDiscoveryDto: Decodable {
    let complete: Bool?
    let answered: Int?
    let total: Int?
    let questions: [MkQuestionDto]?
}

struct MkAnswerBody: Encodable {
    let answers: [String: JSONValue]
    let clear: [String]
}

struct MkMemberDto: Decodable, Identifiable {
    let id: Int
    let role: String
    let name: String?
    let phone: String?
    let isMe: Bool?
    enum CodingKeys: String, CodingKey { case id, role, name, phone, isMe = "is_me" }
}

struct MkMemberRequest: Encodable {
    let phone: String
    let role: String
}

struct MkRoleRequest: Encodable { let role: String }

struct MkJobDto: Decodable, Identifiable {
    let id: String
    let type: String
    let status: String
    let progressPct: Int?
    let errorCode: String?
    let retryable: Bool?
    let planType: String?
    /// The content items a finished `content` job wrote (the ad studio opens the first).
    let created: [String]?

    var finished: Bool { ["COMPLETED", "PARTIAL", "FAILED", "CANCELLED"].contains(status) }

    enum CodingKeys: String, CodingKey {
        case id, type, status, retryable, created
        case progressPct = "progress_pct"
        case errorCode = "error_code"
        case planType = "plan_type"
    }
}

struct MkSourceDto: Decodable, Identifiable {
    let ref: String
    let planRef: String?
    let url: String
    let title: String?
    let publisher: String?
    let publishedAt: String?
    let retrievedAt: String?
    let excerpt: String?
    let credibility: String?

    var id: String { ref }

    enum CodingKeys: String, CodingKey {
        case ref, url, title, publisher, excerpt, credibility
        case planRef = "plan_ref"
        case publishedAt = "published_at"
        case retrievedAt = "retrieved_at"
    }
}

struct MkFindingDto: Decodable, Identifiable {
    let id: Int
    let claim: String
    let sources: [String]?
    let confidence: String?
    let isAssumption: Bool?
    let isQuantitative: Bool?

    enum CodingKeys: String, CodingKey {
        case id, claim, sources, confidence
        case isAssumption = "is_assumption"
        case isQuantitative = "is_quantitative"
    }
}

struct MkRunDto: Decodable, Identifiable {
    let id: String
    let kind: String
    let status: String
    let summary: String?
    let gaps: [String]?
    let qualityScore: Int?
    let errorCode: String?
    let sourcesCount: Int?
    let findingsCount: Int?
    let sources: [MkSourceDto]?
    let findings: [MkFindingDto]?
    let createdAt: String?

    var finished: Bool { ["COMPLETED", "PARTIAL", "FAILED", "CANCELLED"].contains(status) }

    enum CodingKeys: String, CodingKey {
        case id, kind, status, summary, gaps, sources, findings
        case qualityScore = "quality_score"
        case errorCode = "error_code"
        case sourcesCount = "sources_count"
        case findingsCount = "findings_count"
        case createdAt = "created_at"
    }
}

struct MkResearchRequest: Encodable {
    let kind: String
    var maxSources: Int?
    enum CodingKeys: String, CodingKey { case kind, maxSources = "max_sources" }
}

struct MkStartedRunDto: Decodable {
    let run: MkRunDto
    let job: MkJobDto
}

struct MkCriticDto: Decodable {
    let code: String
    let severity: String?
}

struct MkMetricDto: Decodable {
    let value: Double?
    let reason: String?
    let basis: String?
}

struct MkScenarioDto: Decodable {
    let inputs: [String: Double?]?
    let outputs: [String: MkMetricDto]?
    let calculationVersion: String?
    enum CodingKeys: String, CodingKey { case inputs, outputs, calculationVersion = "calculation_version" }
}

struct MkVersionDto: Decodable {
    let version: Int
    let origin: String?
    let content: JSONAny?
    let contentHash: String?
    let critic: [MkCriticDto]?
    let scenarios: [String: MkScenarioDto]?
    let createdAt: String?

    enum CodingKeys: String, CodingKey {
        case version, origin, content, critic, scenarios
        case contentHash = "content_hash"
        case createdAt = "created_at"
    }

    /// `scenarios` comes as `[]` (a PHP empty array) when none were computed: read that as no scenarios.
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        version = try c.decode(Int.self, forKey: .version)
        origin = try c.decodeIfPresent(String.self, forKey: .origin)
        content = try c.decodeIfPresent(JSONAny.self, forKey: .content)
        contentHash = try c.decodeIfPresent(String.self, forKey: .contentHash)
        critic = try c.decodeIfPresent([MkCriticDto].self, forKey: .critic)
        scenarios = (try? c.decodeIfPresent([String: MkScenarioDto].self, forKey: .scenarios)) ?? nil
        createdAt = try c.decodeIfPresent(String.self, forKey: .createdAt)
    }
}

struct MkVersionRowDto: Decodable, Identifiable {
    let version: Int
    let origin: String?
    let createdAt: String?
    var id: Int { version }
    enum CodingKeys: String, CodingKey { case version, origin, createdAt = "created_at" }
}

struct MkApprovalDto: Decodable {
    let id: String
    let version: Int
    let approvedAt: String?
    enum CodingKeys: String, CodingKey { case id, version, approvedAt = "approved_at" }
}

struct MkPlanDto: Decodable, Identifiable {
    let id: String
    let type: String
    let status: String
    let versionsCount: Int?
    let currentVersion: MkVersionDto?
    let approval: MkApprovalDto?

    enum CodingKeys: String, CodingKey {
        case id, type, status, approval
        case versionsCount = "versions_count"
        case currentVersion = "current_version"
    }
}

struct MkPlanRequest: Encodable {
    let type: String
    var instructions: String?
}

struct MkStartedPlanDto: Decodable {
    let plan: MkPlanDto
    let job: MkJobDto
}

struct MkVersionRequest: Encodable { let version: Int }

struct MkEditBody: Encodable {
    let version: Int
    let content: JSONAny
}

struct MkFinanceBody: Encodable {
    let version: Int
    let inputs: [String: JSONValue]
}

// MARK: - phase B DTOs (same field names as Android; a missing field takes Android's default)

private extension KeyedDecodingContainer {
    /// The value, or [fallback] when it is missing, null or of another type (Gson's defaults on Android).
    func mkOr<T: Decodable>(_ key: Key, _ fallback: T) -> T {
        (try? decodeIfPresent(T.self, forKey: key)) ?? fallback
    }

    func mkOpt<T: Decodable>(_ type: T.Type, _ key: Key) -> T? {
        (try? decodeIfPresent(type, forKey: key)) ?? nil
    }
}

struct MkJobStartDto: Decodable {
    let job: MkJobDto?
    let planId: String?
    enum CodingKeys: String, CodingKey { case job, planId = "plan_id" }
}

struct MkCalendarDto: Decodable {
    let from: String
    let to: String
    let items: [MkContentDto]
    let unplanned: Int

    enum CodingKeys: String, CodingKey { case from, to, items, unplanned }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        from = c.mkOr(.from, "")
        to = c.mkOr(.to, "")
        items = c.mkOr(.items, [])
        unplanned = c.mkOr(.unplanned, 0)
    }
}

struct MkCalendarRequest: Encodable {
    let days: Int
    var from: String?
    var channels: [String]?
    var replaceDrafts = true
    var timezone: String?

    enum CodingKeys: String, CodingKey {
        case days, from, channels, timezone
        case replaceDrafts = "replace_drafts"
    }
}

struct MkStudioRequest: Encodable {
    let channel: String
    let brief: String
    var kind = "ad"
    var format: String?
    var objective: String?
    var variants = 2
}

struct MkVariantDto: Decodable {
    let key: String
    let headline: String?
    let body: String?
    let cta: String?
    let hashtags: [String]
    let visualBrief: String?

    enum CodingKeys: String, CodingKey {
        case key, headline, body, cta, hashtags
        case visualBrief = "visual_brief"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        key = c.mkOr(.key, "A")
        headline = c.mkOpt(String.self, .headline)
        body = c.mkOpt(String.self, .body)
        cta = c.mkOpt(String.self, .cta)
        hashtags = c.mkOr(.hashtags, [])
        visualBrief = c.mkOpt(String.self, .visualBrief)
    }
}

struct MkClaimDto: Decodable {
    let code: String
    let severity: String
    let variant: String?
    let detail: String?

    enum CodingKeys: String, CodingKey { case code, severity, variant, detail }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        code = c.mkOr(.code, "")
        severity = c.mkOr(.severity, "warning")
        variant = c.mkOpt(String.self, .variant)
        detail = c.mkOpt(String.self, .detail)
    }
}

struct MkContentDto: Decodable, Identifiable {
    let id: String
    let kind: String
    let channel: String
    let format: String?
    let title: String
    let objective: String?
    let variants: [MkVariantDto]
    let selected: String?
    let claims: [MkClaimDto]
    let hasBlockingIssues: Bool
    let status: String
    let plannedAt: String?
    let costEstimateMinor: Int?
    let origin: String
    let reviewNote: String?
    let publishedUrl: String?
    let lockVersion: Int

    var isTask: Bool { kind == "task" }

    enum CodingKeys: String, CodingKey {
        case id, kind, channel, format, title, objective, variants, selected, claims, status, origin
        case hasBlockingIssues = "has_blocking_issues"
        case plannedAt = "planned_at"
        case costEstimateMinor = "cost_estimate_minor"
        case reviewNote = "review_note"
        case publishedUrl = "published_url"
        case lockVersion = "lock_version"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(String.self, forKey: .id)
        kind = c.mkOr(.kind, "post")
        channel = c.mkOr(.channel, "other")
        format = c.mkOpt(String.self, .format)
        title = c.mkOr(.title, "")
        objective = c.mkOpt(String.self, .objective)
        variants = c.mkOr(.variants, [])
        selected = c.mkOpt(String.self, .selected)
        claims = c.mkOr(.claims, [])
        hasBlockingIssues = c.mkOr(.hasBlockingIssues, false)
        status = c.mkOr(.status, "DRAFT")
        plannedAt = c.mkOpt(String.self, .plannedAt)
        costEstimateMinor = c.mkOpt(Int.self, .costEstimateMinor)
        origin = c.mkOr(.origin, "manual")
        reviewNote = c.mkOpt(String.self, .reviewNote)
        publishedUrl = c.mkOpt(String.self, .publishedUrl)
        lockVersion = c.mkOr(.lockVersion, 1)
    }
}

struct MkLockRequest: Encodable {
    let lockVersion: Int
    enum CodingKeys: String, CodingKey { case lockVersion = "lock_version" }
}

struct MkNoteRequest: Encodable { var note: String? }

struct MkScheduleRequest: Encodable {
    let plannedAt: String
    enum CodingKeys: String, CodingKey { case plannedAt = "planned_at" }
}

struct MkOutcomeRequest: Encodable {
    let published: Bool
    var url: String?
    var note: String?
}

struct MkDoneRequest: Encodable { let done: Bool }

struct MkBudgetDto: Decodable, Identifiable {
    let id: String
    let kind: String
    let periodStart: String
    let periodEnd: String
    let currency: String?
    let cap: Int
    let used: Int
    let estimated: Int?
    let remaining: Int
    let pct: Double?
    let level: String
    let alertPct: Int
    let status: String
    let note: String?
    let active: Bool
    let lockVersion: Int

    enum CodingKeys: String, CodingKey {
        case id, kind, currency, cap, used, estimated, remaining, pct, level, status, note, active
        case periodStart = "period_start"
        case periodEnd = "period_end"
        case alertPct = "alert_pct"
        case lockVersion = "lock_version"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(String.self, forKey: .id)
        kind = c.mkOr(.kind, "ads")
        periodStart = c.mkOr(.periodStart, "")
        periodEnd = c.mkOr(.periodEnd, "")
        currency = c.mkOpt(String.self, .currency)
        cap = c.mkOr(.cap, 0)
        used = c.mkOr(.used, 0)
        estimated = c.mkOpt(Int.self, .estimated)
        remaining = c.mkOr(.remaining, 0)
        pct = c.mkOpt(Double.self, .pct)
        level = c.mkOr(.level, "ok")
        alertPct = c.mkOr(.alertPct, 80)
        status = c.mkOr(.status, "PENDING")
        note = c.mkOpt(String.self, .note)
        active = c.mkOr(.active, false)
        lockVersion = c.mkOr(.lockVersion, 1)
    }
}

struct MkBudgetRequest: Encodable {
    let kind: String
    let periodStart: String
    let periodEnd: String
    var capMinor: Int?
    var capCalls: Int?
    var alertPct = 80

    enum CodingKeys: String, CodingKey {
        case kind
        case periodStart = "period_start"
        case periodEnd = "period_end"
        case capMinor = "cap_minor"
        case capCalls = "cap_calls"
        case alertPct = "alert_pct"
    }
}

struct MkBudgetUpdateRequest: Encodable {
    let lockVersion: Int
    var capMinor: Int?
    var capCalls: Int?
    var alertPct: Int?

    enum CodingKeys: String, CodingKey {
        case lockVersion = "lock_version"
        case capMinor = "cap_minor"
        case capCalls = "cap_calls"
        case alertPct = "alert_pct"
    }
}

struct MkMetricRowDto: Decodable, Identifiable {
    let id: Int
    let date: String
    let channel: String
    let source: String
    let basis: String
    let impressions: Int?
    let clicks: Int?
    let spendMinor: Int?
    let conversions: Int?
    let revenueMinor: Int?
    let currency: String?

    enum CodingKeys: String, CodingKey {
        case id, date, channel, source, basis, impressions, clicks, conversions, currency
        case spendMinor = "spend_minor"
        case revenueMinor = "revenue_minor"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        date = c.mkOr(.date, "")
        channel = c.mkOr(.channel, "other")
        source = c.mkOr(.source, "manual")
        basis = c.mkOr(.basis, "imported")
        impressions = c.mkOpt(Int.self, .impressions)
        clicks = c.mkOpt(Int.self, .clicks)
        spendMinor = c.mkOpt(Int.self, .spendMinor)
        conversions = c.mkOpt(Int.self, .conversions)
        revenueMinor = c.mkOpt(Int.self, .revenueMinor)
        currency = c.mkOpt(String.self, .currency)
    }
}

struct MkMetricInput: Encodable {
    let date: String
    let channel: String
    var basis = "imported"
    var impressions: Int?
    var clicks: Int?
    var spendMinor: Int?
    var conversions: Int?
    var revenueMinor: Int?

    enum CodingKeys: String, CodingKey {
        case date, channel, basis, impressions, clicks, conversions
        case spendMinor = "spend_minor"
        case revenueMinor = "revenue_minor"
    }
}

struct MkMetricsRequest: Encodable { let rows: [MkMetricInput] }

struct MkImportResultDto: Decodable {
    let created: Int?
    let updated: Int?
    let rows: Int?
}

struct MkRateDto: Decodable {
    let value: Double?
    let reason: String?
}

/// Totals (counts and minor units; a missing one is null, never 0) and rates (a rate that can't be computed says why).
struct MkKpiGroupDto: Decodable {
    let rows: Int
    let totals: [String: Int?]
    let rates: [String: MkRateDto]

    enum CodingKeys: String, CodingKey { case rows, totals, rates }

    init() {
        rows = 0
        totals = [:]
        rates = [:]
    }

    /// An empty map comes as `[]` (a PHP empty array): read that as nothing.
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        rows = c.mkOr(.rows, 0)
        let raw: [String: JSONAny] = c.mkOr(.totals, [:])
        totals = raw.mapValues { $0.int }
        rates = c.mkOr(.rates, [:])
    }

    func total(_ key: String) -> Int? { totals[key] ?? nil }
}

struct MkChannelKpiDto: Decodable {
    let channel: String
    let measured: MkKpiGroupDto
    let estimated: MkKpiGroupDto?
    let lastDate: String?

    enum CodingKeys: String, CodingKey { case channel, measured, estimated, lastDate = "last_date" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        channel = c.mkOr(.channel, "other")
        measured = c.mkOr(.measured, MkKpiGroupDto())
        estimated = c.mkOpt(MkKpiGroupDto.self, .estimated)
        lastDate = c.mkOpt(String.self, .lastDate)
    }
}

struct MkKpiGapsDto: Decodable {
    let days: Int
    let daysWithData: Int
    let daysWithoutData: Int
    let channelsWithoutRevenue: [String]

    enum CodingKeys: String, CodingKey {
        case days
        case daysWithData = "days_with_data"
        case daysWithoutData = "days_without_data"
        case channelsWithoutRevenue = "channels_without_revenue"
    }

    init() {
        days = 0
        daysWithData = 0
        daysWithoutData = 0
        channelsWithoutRevenue = []
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        days = c.mkOr(.days, 0)
        daysWithData = c.mkOr(.daysWithData, 0)
        daysWithoutData = c.mkOr(.daysWithoutData, 0)
        channelsWithoutRevenue = c.mkOr(.channelsWithoutRevenue, [])
    }
}

struct MkSeriesPointDto: Decodable {
    let date: String
    let spendMinor: Int?
    let conversions: Int?
    let revenueMinor: Int?

    enum CodingKeys: String, CodingKey {
        case date, conversions
        case spendMinor = "spend_minor"
        case revenueMinor = "revenue_minor"
    }
}

struct MkKpiBoardDto: Decodable {
    let from: String
    let to: String
    let currency: String
    let lastUpdatedAt: String?
    let measured: MkKpiGroupDto
    let estimated: MkKpiGroupDto?
    let byChannel: [MkChannelKpiDto]
    let series: [MkSeriesPointDto]
    let gaps: MkKpiGapsDto

    enum CodingKeys: String, CodingKey {
        case from, to, currency, measured, estimated, series, gaps
        case lastUpdatedAt = "last_updated_at"
        case byChannel = "by_channel"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        from = c.mkOr(.from, "")
        to = c.mkOr(.to, "")
        currency = c.mkOr(.currency, "")
        lastUpdatedAt = c.mkOpt(String.self, .lastUpdatedAt)
        measured = c.mkOr(.measured, MkKpiGroupDto())
        estimated = c.mkOpt(MkKpiGroupDto.self, .estimated)
        byChannel = c.mkOr(.byChannel, [])
        series = c.mkOr(.series, [])
        gaps = c.mkOr(.gaps, MkKpiGapsDto())
    }
}

struct MkReportLineDto: Decodable {
    let code: String
    let level: String
    let text: String
    let itemId: String?

    enum CodingKeys: String, CodingKey { case code, level, text, itemId = "item_id" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        code = c.mkOr(.code, "")
        level = c.mkOr(.level, "info")
        text = c.mkOr(.text, "")
        itemId = c.mkOpt(String.self, .itemId)
    }
}

struct MkReportResultsDto: Decodable {
    let current: MkKpiGroupDto
    let previous: MkKpiGroupDto
    let changePct: [String: Double?]
    let estimated: MkKpiGroupDto?
    let lastUpdatedAt: String?
    let daysWithData: Int

    enum CodingKeys: String, CodingKey {
        case current, previous, estimated
        case changePct = "change_pct"
        case lastUpdatedAt = "last_updated_at"
        case daysWithData = "days_with_data"
    }

    init() {
        current = MkKpiGroupDto()
        previous = MkKpiGroupDto()
        changePct = [:]
        estimated = nil
        lastUpdatedAt = nil
        daysWithData = 0
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        current = c.mkOr(.current, MkKpiGroupDto())
        previous = c.mkOr(.previous, MkKpiGroupDto())
        let raw: [String: JSONAny] = c.mkOr(.changePct, [:])
        changePct = raw.mapValues { value -> Double? in
            if case .number(let v) = value { return v }
            return nil
        }
        estimated = c.mkOpt(MkKpiGroupDto.self, .estimated)
        lastUpdatedAt = c.mkOpt(String.self, .lastUpdatedAt)
        daysWithData = c.mkOr(.daysWithData, 0)
    }

    func change(_ key: String) -> Double? { changePct[key] ?? nil }
}

struct MkReportDto: Decodable {
    let period: String
    let from: String
    let to: String
    let currency: String
    let decisions: [MkReportLineDto]
    let results: MkReportResultsDto
    let risks: [MkReportLineDto]
    let recommendations: [MkReportLineDto]
    let note: String?

    enum CodingKeys: String, CodingKey { case period, from, to, currency, decisions, results, risks, recommendations, note }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        period = c.mkOr(.period, "week")
        from = c.mkOr(.from, "")
        to = c.mkOr(.to, "")
        currency = c.mkOr(.currency, "")
        decisions = c.mkOr(.decisions, [])
        results = c.mkOr(.results, MkReportResultsDto())
        risks = c.mkOr(.risks, [])
        recommendations = c.mkOr(.recommendations, [])
        note = c.mkOpt(String.self, .note)
    }
}

// MARK: - phase C DTOs

struct MkAdAccountDto: Decodable {
    let id: String
    let name: String?
    let currency: String?
}

struct MkIntegrationDto: Decodable, Identifiable {
    let id: String
    let provider: String
    let status: String
    let account: MkAdAccountDto?
    let availableAccounts: [MkAdAccountDto]?
    let sandbox: Bool
    let tokenExpiresAt: String?
    let lastError: String?
    let reconnectNeeded: Bool

    enum CodingKeys: String, CodingKey {
        case id, provider, status, account, sandbox
        case availableAccounts = "available_accounts"
        case tokenExpiresAt = "token_expires_at"
        case lastError = "last_error"
        case reconnectNeeded = "reconnect_needed"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(String.self, forKey: .id)
        provider = c.mkOr(.provider, "meta")
        status = c.mkOr(.status, "PENDING")
        account = c.mkOpt(MkAdAccountDto.self, .account)
        availableAccounts = c.mkOpt([MkAdAccountDto].self, .availableAccounts)
        sandbox = c.mkOr(.sandbox, false)
        tokenExpiresAt = c.mkOpt(String.self, .tokenExpiresAt)
        lastError = c.mkOpt(String.self, .lastError)
        reconnectNeeded = c.mkOr(.reconnectNeeded, false)
    }
}

struct MkPlatformDto: Decodable {
    let provider: String
    let available: Bool
    let comingSoon: Bool
    let configured: Bool

    enum CodingKeys: String, CodingKey { case provider, available, configured, comingSoon = "coming_soon" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        provider = c.mkOr(.provider, "meta")
        available = c.mkOr(.available, false)
        comingSoon = c.mkOr(.comingSoon, false)
        configured = c.mkOr(.configured, false)
    }
}

struct MkIntegrationsDto: Decodable {
    let integrations: [MkIntegrationDto]
    let platforms: [MkPlatformDto]

    enum CodingKeys: String, CodingKey { case integrations, platforms }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        integrations = c.mkOr(.integrations, [])
        platforms = c.mkOr(.platforms, [])
    }
}

struct MkConnectDto: Decodable {
    let integration: MkIntegrationDto?
    let authorizeUrl: String?
    enum CodingKeys: String, CodingKey { case integration, authorizeUrl = "authorize_url" }
}

struct MkAccountRequest: Encodable {
    let accountId: String
    enum CodingKeys: String, CodingKey { case accountId = "account_id" }
}

struct MkIssueDto: Decodable {
    let code: String
    let detail: String?

    enum CodingKeys: String, CodingKey { case code, detail }

    init(code: String, detail: String?) {
        self.code = code
        self.detail = detail
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        code = c.mkOr(.code, "")
        detail = c.mkOpt(String.self, .detail)
    }
}

struct MkCampaignApprovalDto: Decodable {
    let id: String
    let version: Int
    let capMinor: Int
    let expiresAt: String?
    let byDelegation: Bool

    enum CodingKeys: String, CodingKey {
        case id, version
        case capMinor = "cap_minor"
        case expiresAt = "expires_at"
        case byDelegation = "by_delegation"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(String.self, forKey: .id)
        version = c.mkOr(.version, 1)
        capMinor = c.mkOr(.capMinor, 0)
        expiresAt = c.mkOpt(String.self, .expiresAt)
        byDelegation = c.mkOr(.byDelegation, false)
    }
}

struct MkActionDto: Decodable {
    let id: String
    let type: String?
    let status: String
    let errorCode: String?
    enum CodingKeys: String, CodingKey { case id, type, status, errorCode = "error_code" }
}

struct MkCampaignDto: Decodable, Identifiable {
    let id: String
    let provider: String
    let integrationId: String?
    let contentId: String?
    let name: String
    let objective: String
    let status: String
    let version: Int
    let dailyBudgetMinor: Int
    let totalBudgetMinor: Int
    let currency: String
    let startAt: String?
    let endAt: String?
    /// `{countries: [..], age_min, age_max}` — kept as it came.
    let targeting: JSONAny?
    let validation: [MkIssueDto]
    let approval: MkCampaignApprovalDto?
    let platformRef: String?
    let platformStatus: String?
    let lastError: String?
    let publishedAt: String?
    let metricsSyncedAt: String?
    let lastAction: MkActionDto?

    enum CodingKeys: String, CodingKey {
        case id, provider, name, objective, status, version, currency, targeting, validation, approval
        case integrationId = "integration_id"
        case contentId = "content_id"
        case dailyBudgetMinor = "daily_budget_minor"
        case totalBudgetMinor = "total_budget_minor"
        case startAt = "start_at"
        case endAt = "end_at"
        case platformRef = "platform_ref"
        case platformStatus = "platform_status"
        case lastError = "last_error"
        case publishedAt = "published_at"
        case metricsSyncedAt = "metrics_synced_at"
        case lastAction = "last_action"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(String.self, forKey: .id)
        provider = c.mkOr(.provider, "meta")
        integrationId = c.mkOpt(String.self, .integrationId)
        contentId = c.mkOpt(String.self, .contentId)
        name = c.mkOr(.name, "")
        objective = c.mkOr(.objective, "traffic")
        status = c.mkOr(.status, "DRAFT")
        version = c.mkOr(.version, 1)
        dailyBudgetMinor = c.mkOr(.dailyBudgetMinor, 0)
        totalBudgetMinor = c.mkOr(.totalBudgetMinor, 0)
        currency = c.mkOr(.currency, "")
        startAt = c.mkOpt(String.self, .startAt)
        endAt = c.mkOpt(String.self, .endAt)
        targeting = c.mkOpt(JSONAny.self, .targeting)
        validation = c.mkOr(.validation, [])
        approval = c.mkOpt(MkCampaignApprovalDto.self, .approval)
        platformRef = c.mkOpt(String.self, .platformRef)
        platformStatus = c.mkOpt(String.self, .platformStatus)
        lastError = c.mkOpt(String.self, .lastError)
        publishedAt = c.mkOpt(String.self, .publishedAt)
        metricsSyncedAt = c.mkOpt(String.self, .metricsSyncedAt)
        lastAction = c.mkOpt(MkActionDto.self, .lastAction)
    }
}

struct MkCampaignVersionRequest: Encodable { let version: Int }

struct MkPublishRequest: Encodable {
    let approvalId: String
    let campaignVersion: Int
    let expectedBudgetMinor: Int
    let currency: String

    enum CodingKeys: String, CodingKey {
        case currency
        case approvalId = "approval_id"
        case campaignVersion = "campaign_version"
        case expectedBudgetMinor = "expected_budget_minor"
    }
}

struct MkPublishDto: Decodable {
    let campaign: MkCampaignDto?
    let action: MkActionDto?
    let replayed: Bool?
}

struct MkCampaignMetricsDto: Decodable {
    let syncedAt: String?
    let rows: [MkMetricRowDto]

    enum CodingKeys: String, CodingKey { case rows, syncedAt = "synced_at" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        syncedAt = c.mkOpt(String.self, .syncedAt)
        rows = c.mkOr(.rows, [])
    }
}

struct MkDelegationDto: Decodable, Identifiable {
    let id: String
    let memberId: Int?
    let name: String?
    let capMinor: Int
    let usedMinor: Int
    let remainingMinor: Int
    let currency: String?
    let status: String
    let expiresAt: String?

    enum CodingKeys: String, CodingKey {
        case id, name, currency, status
        case memberId = "member_id"
        case capMinor = "cap_minor"
        case usedMinor = "used_minor"
        case remainingMinor = "remaining_minor"
        case expiresAt = "expires_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(String.self, forKey: .id)
        memberId = c.mkOpt(Int.self, .memberId)
        name = c.mkOpt(String.self, .name)
        capMinor = c.mkOr(.capMinor, 0)
        usedMinor = c.mkOr(.usedMinor, 0)
        remainingMinor = c.mkOr(.remainingMinor, 0)
        currency = c.mkOpt(String.self, .currency)
        status = c.mkOr(.status, "active")
        expiresAt = c.mkOpt(String.self, .expiresAt)
    }
}

struct MkDelegationRequest: Encodable {
    let memberId: Int
    let capMinor: Int
    let expiresAt: String

    enum CodingKeys: String, CodingKey {
        case memberId = "member_id"
        case capMinor = "cap_minor"
        case expiresAt = "expires_at"
    }
}

// MARK: - phase D DTOs

struct MkSuggestionDto: Decodable {
    let code: String
    let priority: String
    let channel: String?
    let text: String

    enum CodingKeys: String, CodingKey { case code, priority, channel, text }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        code = c.mkOr(.code, "")
        priority = c.mkOr(.priority, "low")
        channel = c.mkOpt(String.self, .channel)
        text = c.mkOr(.text, "")
    }
}

struct MkOptimisationsDto: Decodable {
    let suggestions: [MkSuggestionDto]
    let note: String?

    enum CodingKeys: String, CodingKey { case suggestions, note }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        suggestions = c.mkOr(.suggestions, [])
        note = c.mkOpt(String.self, .note)
    }
}

struct MkAlertRuleDto: Decodable, Identifiable {
    let id: String
    let metric: String
    let comparison: String
    let threshold: Double
    let windowDays: Int
    let channel: String?
    let active: Bool
    let current: MkRateDto?
    let lastTriggeredAt: String?

    enum CodingKeys: String, CodingKey {
        case id, metric, comparison, threshold, channel, active, current
        case windowDays = "window_days"
        case lastTriggeredAt = "last_triggered_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(String.self, forKey: .id)
        metric = c.mkOr(.metric, "cpa")
        comparison = c.mkOr(.comparison, "above")
        threshold = c.mkOr(.threshold, 0.0)
        windowDays = c.mkOr(.windowDays, 7)
        channel = c.mkOpt(String.self, .channel)
        active = c.mkOr(.active, true)
        current = c.mkOpt(MkRateDto.self, .current)
        lastTriggeredAt = c.mkOpt(String.self, .lastTriggeredAt)
    }
}

struct MkAlertRuleRequest: Encodable {
    let metric: String
    let comparison: String
    let threshold: Double
    let windowDays: Int
    var channel: String?

    enum CodingKeys: String, CodingKey {
        case metric, comparison, threshold, channel
        case windowDays = "window_days"
    }
}

struct MkActiveRequest: Encodable { let active: Bool }

struct MkExperimentVariantDto: Decodable {
    let key: String
    let label: String?
}

struct MkVerdictDto: Decodable {
    let verdict: String
    let winner: String?
    let pValue: Double?
    let liftPct: Double?
    let rates: [String: Double?]
    let text: String

    enum CodingKeys: String, CodingKey {
        case verdict, winner, rates, text
        case pValue = "p_value"
        case liftPct = "lift_pct"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        verdict = c.mkOr(.verdict, "missing_data")
        winner = c.mkOpt(String.self, .winner)
        pValue = c.mkOpt(Double.self, .pValue)
        liftPct = c.mkOpt(Double.self, .liftPct)
        let raw: [String: JSONAny] = c.mkOr(.rates, [:])
        rates = raw.mapValues { value -> Double? in
            if case .number(let v) = value { return v }
            return nil
        }
        text = c.mkOr(.text, "")
    }

    func rate(_ key: String) -> Double? { rates[key] ?? nil }
}

struct MkExperimentDto: Decodable, Identifiable {
    let id: String
    let name: String
    let hypothesis: String
    let successMetric: String
    let variants: [MkExperimentVariantDto]
    /// variant → {impressions, clicks, conversions}.
    let results: [String: [String: Int?]]
    let status: String
    let conclusion: MkVerdictDto?
    let preview: MkVerdictDto?

    enum CodingKeys: String, CodingKey {
        case id, name, hypothesis, variants, results, status, conclusion, preview
        case successMetric = "success_metric"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(String.self, forKey: .id)
        name = c.mkOr(.name, "")
        hypothesis = c.mkOr(.hypothesis, "")
        successMetric = c.mkOr(.successMetric, "ctr")
        variants = c.mkOr(.variants, [])
        let raw: [String: JSONAny] = c.mkOr(.results, [:])
        results = raw.mapValues { value -> [String: Int?] in
            guard case .object(let map) = value else { return [:] }
            return map.mapValues { $0.int }
        }
        status = c.mkOr(.status, "DRAFT")
        conclusion = c.mkOpt(MkVerdictDto.self, .conclusion)
        preview = c.mkOpt(MkVerdictDto.self, .preview)
    }
}

struct MkExperimentRequest: Encodable {
    let name: String
    let hypothesis: String
    let successMetric: String
    var content: String?

    enum CodingKeys: String, CodingKey {
        case name, hypothesis, content
        case successMetric = "success_metric"
    }
}

struct MkExperimentResultRequest: Encodable {
    let variant: String
    var impressions: Int?
    var clicks: Int?
    var conversions: Int?
}

struct MkSimulateRequest: Encodable {
    let swingPct: Int
    enum CodingKeys: String, CodingKey { case swingPct = "swing_pct" }
}

struct MkTornadoDto: Decodable {
    let input: String
    let lowProfitMinor: Int
    let highProfitMinor: Int
    let swingMinor: Int

    enum CodingKeys: String, CodingKey {
        case input
        case lowProfitMinor = "low_profit_minor"
        case highProfitMinor = "high_profit_minor"
        case swingMinor = "swing_minor"
    }
}

struct MkSimulationDto: Decodable {
    let baseProfitMinor: Int?
    let swingPct: Int
    let tornado: [MkTornadoDto]

    enum CodingKeys: String, CodingKey {
        case tornado
        case baseProfitMinor = "base_profit_minor"
        case swingPct = "swing_pct"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        baseProfitMinor = c.mkOpt(Int.self, .baseProfitMinor)
        swingPct = c.mkOr(.swingPct, 20)
        tornado = c.mkOr(.tornado, [])
    }
}
