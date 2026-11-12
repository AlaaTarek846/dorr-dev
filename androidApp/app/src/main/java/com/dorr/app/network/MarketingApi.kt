package com.dorr.app.network

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query
import retrofit2.http.Streaming

/**
 * DORR AI Marketing Director (docs/marketing_tasks.md, docs/modules/marketing/API.md) under /api/user/v1/marketing,
 * like the other AI tools. Money is an integer in minor units with the project's `currency`. Long jobs (research,
 * plan writing) answer 202 with a job; its moves arrive as the realtime `marketing.job.changed` (no polling).
 */
interface MarketingApi {
    /** Open here? The package and its prices; works even when the service is off (`available: false`). */
    @GET("user/v1/marketing/status")
    suspend fun status(@Header("Authorization") auth: String): ApiEnvelope<MkStatusDto>

    @GET("user/v1/marketing/projects")
    suspend fun projects(
        @Header("Authorization") auth: String,
        @Query("status") status: String? = null,
        @Query("q") q: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15,
    ): ApiEnvelope<List<MkProjectDto>>

    /** `marketing_package_required` (402) without a package; `marketing_limit_reached` (429) at the package's limit. */
    @POST("user/v1/marketing/projects")
    suspend fun createProject(@Header("Authorization") auth: String, @Body body: MkProjectRequest): ApiEnvelope<MkProjectDto>

    @GET("user/v1/marketing/projects/{id}")
    suspend fun project(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<MkProjectDto>

    /** `lock_version` must be the one read, or `marketing_version_conflict` (409). */
    @PATCH("user/v1/marketing/projects/{id}")
    suspend fun updateProject(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: MkProjectRequest): ApiEnvelope<MkProjectDto>

    @POST("user/v1/marketing/projects/{id}/archive")
    suspend fun archiveProject(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: MkArchiveRequest): ApiEnvelope<MkProjectDto>

    @DELETE("user/v1/marketing/projects/{id}")
    suspend fun deleteProject(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    /** The latest jobs: the catch-up on open and on reconnect. */
    @GET("user/v1/marketing/projects/{id}/jobs")
    suspend fun jobs(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<MkJobDto>>

    // ------------------------------------------------------------------ discovery

    @GET("user/v1/marketing/projects/{id}/discovery")
    suspend fun discovery(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<MkDiscoveryDto>

    /** `{answers: {key: value}, clear: [key]}` — `clear` empties an answer ("I don't know yet"). */
    @PUT("user/v1/marketing/projects/{id}/discovery")
    suspend fun answer(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<MkDiscoveryDto>

    @POST("user/v1/marketing/projects/{id}/discovery/suggest")
    suspend fun suggestQuestions(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<MkDiscoveryDto>

    // ------------------------------------------------------------------ team

    @GET("user/v1/marketing/projects/{id}/members")
    suspend fun members(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<MkMemberDto>>

    @POST("user/v1/marketing/projects/{id}/members")
    suspend fun addMember(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: MkMemberRequest): ApiEnvelope<MkMemberDto>

    @PATCH("user/v1/marketing/projects/{id}/members/{member}")
    suspend fun changeRole(@Header("Authorization") auth: String, @Path("id") id: String, @Path("member") member: Long, @Body body: MkRoleRequest): ApiEnvelope<MkMemberDto>

    @DELETE("user/v1/marketing/projects/{id}/members/{member}")
    suspend fun removeMember(@Header("Authorization") auth: String, @Path("id") id: String, @Path("member") member: Long): ApiEnvelope<JsonElement?>

    @POST("user/v1/marketing/projects/{id}/leave")
    suspend fun leave(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    // ------------------------------------------------------------------ research

    @GET("user/v1/marketing/projects/{id}/research-runs")
    suspend fun researchRuns(@Header("Authorization") auth: String, @Path("id") id: String, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 15): ApiEnvelope<List<MkRunDto>>

    /** 202: `{run, job}`. */
    @POST("user/v1/marketing/projects/{id}/research-runs")
    suspend fun startResearch(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: MkResearchRequest): ApiEnvelope<MkStartedRunDto>

    @GET("user/v1/marketing/research-runs/{run}")
    suspend fun researchRun(@Header("Authorization") auth: String, @Path("run") run: String): ApiEnvelope<MkRunDto>

    // ------------------------------------------------------------------ plans

    @GET("user/v1/marketing/projects/{id}/plans")
    suspend fun plans(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<MkPlanDto>>

    /** 202: `{plan, job}`; `marketing_job_running` (409) while one of the same type is being written. */
    @POST("user/v1/marketing/projects/{id}/plans")
    suspend fun generatePlan(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: MkPlanRequest): ApiEnvelope<MkStartedPlanDto>

    @GET("user/v1/marketing/plans/{plan}")
    suspend fun plan(@Header("Authorization") auth: String, @Path("plan") plan: String): ApiEnvelope<MkPlanDto>

    @GET("user/v1/marketing/plans/{plan}/versions")
    suspend fun versions(@Header("Authorization") auth: String, @Path("plan") plan: String): ApiEnvelope<List<MkVersionRowDto>>

    @GET("user/v1/marketing/plans/{plan}/versions/{version}")
    suspend fun version(@Header("Authorization") auth: String, @Path("plan") plan: String, @Path("version") version: Int): ApiEnvelope<MkVersionDto>

    /** `{version, content}`: a new version; the plan goes back to DRAFT and an old approval stops counting. */
    @PUT("user/v1/marketing/plans/{plan}")
    suspend fun editPlan(@Header("Authorization") auth: String, @Path("plan") plan: String, @Body body: JsonObject): ApiEnvelope<MkPlanDto>

    /** `{version, inputs}`: a new version whose three scenarios the server computes. */
    @PUT("user/v1/marketing/plans/{plan}/finance")
    suspend fun setFinance(@Header("Authorization") auth: String, @Path("plan") plan: String, @Body body: JsonObject): ApiEnvelope<MkPlanDto>

    @POST("user/v1/marketing/plans/{plan}/submit")
    suspend fun submitPlan(@Header("Authorization") auth: String, @Path("plan") plan: String, @Body body: MkVersionRequest): ApiEnvelope<MkPlanDto>

    @POST("user/v1/marketing/plans/{plan}/approve")
    suspend fun approvePlan(@Header("Authorization") auth: String, @Path("plan") plan: String, @Body body: MkVersionRequest): ApiEnvelope<MkPlanDto>

    @POST("user/v1/marketing/plans/{plan}/return")
    suspend fun returnPlan(@Header("Authorization") auth: String, @Path("plan") plan: String, @Body body: MkVersionRequest): ApiEnvelope<MkPlanDto>

    @POST("user/v1/marketing/plans/{plan}/archive")
    suspend fun archivePlan(@Header("Authorization") auth: String, @Path("plan") plan: String): ApiEnvelope<MkPlanDto>

    @Streaming
    @GET("user/v1/marketing/plans/{plan}/versions/{version}/export")
    suspend fun export(@Header("Authorization") auth: String, @Path("plan") plan: String, @Path("version") version: Int, @Query("format") format: String): retrofit2.Response<okhttp3.ResponseBody>
    // ------------------------------------------------------------------ phase B: calendar + ad studio (U06/U07)

    @GET("user/v1/marketing/projects/{id}/calendar")
    suspend fun calendar(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @Query("from") from: String,
        @Query("to") to: String,
    ): ApiEnvelope<MkCalendarDto>

    /** 202 + job (`calendar`); `marketing_approved_plan_required` (409) without an approved GTM / marketing plan. */
    @POST("user/v1/marketing/projects/{id}/calendar/generate")
    suspend fun generateCalendar(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: MkCalendarRequest): ApiEnvelope<MkJobStartDto>

    @GET("user/v1/marketing/projects/{id}/content")
    suspend fun contentList(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @Query("status") status: String? = null,
        @Query("kind") kind: String? = null,
        @Query("per_page") perPage: Int = 20,
    ): ApiEnvelope<List<MkContentDto>>

    @POST("user/v1/marketing/projects/{id}/content")
    suspend fun createContent(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<MkContentDto>

    /** The ad studio: A/B(/C) variants written by the AI — 202 + job (`content`). */
    @POST("user/v1/marketing/projects/{id}/content/generate")
    suspend fun generateContent(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: MkStudioRequest): ApiEnvelope<MkJobStartDto>

    @GET("user/v1/marketing/content/{item}")
    suspend fun content(@Header("Authorization") auth: String, @Path("item") item: String): ApiEnvelope<MkContentDto>

    /** `lock_version` must be the one read; new words on an approved item send it back to draft. */
    @PATCH("user/v1/marketing/content/{item}")
    suspend fun updateContent(@Header("Authorization") auth: String, @Path("item") item: String, @Body body: JsonObject): ApiEnvelope<MkContentDto>

    @DELETE("user/v1/marketing/content/{item}")
    suspend fun deleteContent(@Header("Authorization") auth: String, @Path("item") item: String): ApiEnvelope<JsonElement?>

    @POST("user/v1/marketing/content/{item}/submit")
    suspend fun submitContent(@Header("Authorization") auth: String, @Path("item") item: String): ApiEnvelope<MkContentDto>

    @POST("user/v1/marketing/content/{item}/approve")
    suspend fun approveContent(@Header("Authorization") auth: String, @Path("item") item: String, @Body body: MkLockRequest): ApiEnvelope<MkContentDto>

    @POST("user/v1/marketing/content/{item}/reject")
    suspend fun rejectContent(@Header("Authorization") auth: String, @Path("item") item: String, @Body body: MkNoteRequest): ApiEnvelope<MkContentDto>

    @POST("user/v1/marketing/content/{item}/schedule")
    suspend fun scheduleContent(@Header("Authorization") auth: String, @Path("item") item: String, @Body body: MkScheduleRequest): ApiEnvelope<MkContentDto>

    /** The person posted it (or could not) — DORR never posts in phase B. */
    @POST("user/v1/marketing/content/{item}/outcome")
    suspend fun contentOutcome(@Header("Authorization") auth: String, @Path("item") item: String, @Body body: MkOutcomeRequest): ApiEnvelope<MkContentDto>

    @POST("user/v1/marketing/content/{item}/done")
    suspend fun taskDone(@Header("Authorization") auth: String, @Path("item") item: String, @Body body: MkDoneRequest): ApiEnvelope<MkContentDto>

    // ------------------------------------------------------------------ budgets (U08)

    @GET("user/v1/marketing/projects/{id}/budgets")
    suspend fun budgets(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<MkBudgetDto>>

    @POST("user/v1/marketing/projects/{id}/budgets")
    suspend fun createBudget(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: MkBudgetRequest): ApiEnvelope<MkBudgetDto>

    @PATCH("user/v1/marketing/budgets/{budget}")
    suspend fun updateBudget(@Header("Authorization") auth: String, @Path("budget") budget: String, @Body body: MkBudgetUpdateRequest): ApiEnvelope<MkBudgetDto>

    @POST("user/v1/marketing/budgets/{budget}/approve")
    suspend fun approveBudget(@Header("Authorization") auth: String, @Path("budget") budget: String, @Body body: MkLockRequest): ApiEnvelope<MkBudgetDto>

    @POST("user/v1/marketing/budgets/{budget}/close")
    suspend fun closeBudget(@Header("Authorization") auth: String, @Path("budget") budget: String): ApiEnvelope<MkBudgetDto>

    // ------------------------------------------------------------------ results, KPI board, report (U11/U12)

    @GET("user/v1/marketing/projects/{id}/metrics")
    suspend fun metrics(@Header("Authorization") auth: String, @Path("id") id: String, @Query("per_page") perPage: Int = 30): ApiEnvelope<List<MkMetricRowDto>>

    @POST("user/v1/marketing/projects/{id}/metrics")
    suspend fun saveMetrics(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: MkMetricsRequest): ApiEnvelope<MkImportResultDto>

    /** A CSV file (date, channel, impressions, clicks, spend, conversions, revenue) — all or nothing. */
    @Multipart
    @POST("user/v1/marketing/projects/{id}/metrics/import")
    suspend fun importMetrics(@Header("Authorization") auth: String, @Path("id") id: String, @Part file: MultipartBody.Part): ApiEnvelope<MkImportResultDto>

    @DELETE("user/v1/marketing/metrics/{metric}")
    suspend fun deleteMetric(@Header("Authorization") auth: String, @Path("metric") metric: Long): ApiEnvelope<JsonElement?>

    @GET("user/v1/marketing/projects/{id}/kpis")
    suspend fun kpis(@Header("Authorization") auth: String, @Path("id") id: String, @Query("from") from: String?, @Query("to") to: String?): ApiEnvelope<MkKpiBoardDto>

    @GET("user/v1/marketing/projects/{id}/executive-report")
    suspend fun report(@Header("Authorization") auth: String, @Path("id") id: String, @Query("period") period: String): ApiEnvelope<MkReportDto>
    // ------------------------------------------------------------------ phase C: ad platforms + campaigns (U09/U10)

    @GET("user/v1/marketing/projects/{id}/integrations")
    suspend fun integrations(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<MkIntegrationsDto>

    /** Owner. `authorize_url` to open in the browser (OAuth), or already CONNECTED (sandbox). */
    @POST("user/v1/marketing/projects/{id}/integrations/{provider}/connect")
    suspend fun connectPlatform(@Header("Authorization") auth: String, @Path("id") id: String, @Path("provider") provider: String): ApiEnvelope<MkConnectDto>

    @POST("user/v1/marketing/integrations/{integration}/account")
    suspend fun chooseAdAccount(@Header("Authorization") auth: String, @Path("integration") integration: String, @Body body: MkAccountRequest): ApiEnvelope<MkIntegrationDto>

    @POST("user/v1/marketing/integrations/{integration}/disconnect")
    suspend fun disconnectPlatform(@Header("Authorization") auth: String, @Path("integration") integration: String): ApiEnvelope<MkIntegrationDto>

    @GET("user/v1/marketing/projects/{id}/campaigns")
    suspend fun campaigns(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<MkCampaignDto>>

    @POST("user/v1/marketing/projects/{id}/campaigns")
    suspend fun createCampaign(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<MkCampaignDto>

    @GET("user/v1/marketing/campaigns/{campaign}")
    suspend fun campaign(@Header("Authorization") auth: String, @Path("campaign") campaign: String): ApiEnvelope<MkCampaignDto>

    /** `version` must be the current one; any change is a new version and the old approval stops counting. */
    @PATCH("user/v1/marketing/campaigns/{campaign}")
    suspend fun updateCampaign(@Header("Authorization") auth: String, @Path("campaign") campaign: String, @Body body: JsonObject): ApiEnvelope<MkCampaignDto>

    @DELETE("user/v1/marketing/campaigns/{campaign}")
    suspend fun deleteCampaign(@Header("Authorization") auth: String, @Path("campaign") campaign: String): ApiEnvelope<JsonElement?>

    /** 422 `marketing_campaign_invalid` with `data.issues[{code, detail}]`. */
    @POST("user/v1/marketing/campaigns/{campaign}/validate")
    suspend fun validateCampaign(@Header("Authorization") auth: String, @Path("campaign") campaign: String): ApiEnvelope<MkCampaignDto>

    @POST("user/v1/marketing/campaigns/{campaign}/approval-requests")
    suspend fun requestCampaignApproval(@Header("Authorization") auth: String, @Path("campaign") campaign: String): ApiEnvelope<MkCampaignDto>

    @POST("user/v1/marketing/campaigns/{campaign}/approve")
    suspend fun approveCampaign(@Header("Authorization") auth: String, @Path("campaign") campaign: String, @Body body: MkCampaignVersionRequest): ApiEnvelope<MkCampaignDto>

    @POST("user/v1/marketing/campaigns/{campaign}/reject")
    suspend fun rejectCampaign(@Header("Authorization") auth: String, @Path("campaign") campaign: String, @Body body: MkNoteRequest): ApiEnvelope<MkCampaignDto>

    /** The same Idempotency-Key for every retry of one publish (a repeat answers the first result, AT-14). */
    @POST("user/v1/marketing/campaigns/{campaign}/publish")
    suspend fun publishCampaign(
        @Header("Authorization") auth: String,
        @Header("Idempotency-Key") key: String,
        @Path("campaign") campaign: String,
        @Body body: MkPublishRequest,
    ): ApiEnvelope<MkPublishDto>

    @POST("user/v1/marketing/campaigns/{campaign}/reconcile")
    suspend fun reconcileCampaign(@Header("Authorization") auth: String, @Path("campaign") campaign: String): ApiEnvelope<MkCampaignDto>

    @POST("user/v1/marketing/campaigns/{campaign}/metrics/sync")
    suspend fun syncCampaignMetrics(@Header("Authorization") auth: String, @Path("campaign") campaign: String): ApiEnvelope<JsonElement?>

    @GET("user/v1/marketing/campaigns/{campaign}/metrics")
    suspend fun campaignMetrics(@Header("Authorization") auth: String, @Path("campaign") campaign: String): ApiEnvelope<MkCampaignMetricsDto>

    @GET("user/v1/marketing/projects/{id}/delegations")
    suspend fun delegations(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<MkDelegationDto>>

    @POST("user/v1/marketing/projects/{id}/delegations")
    suspend fun createDelegation(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: MkDelegationRequest): ApiEnvelope<MkDelegationDto>

    @DELETE("user/v1/marketing/delegations/{delegation}")
    suspend fun revokeDelegation(@Header("Authorization") auth: String, @Path("delegation") delegation: String): ApiEnvelope<MkDelegationDto>
    // ------------------------------------------------------------------ phase D: optimisation (nothing changes spending)

    @GET("user/v1/marketing/projects/{id}/optimisations")
    suspend fun optimisations(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<MkOptimisationsDto>

    @GET("user/v1/marketing/projects/{id}/alert-rules")
    suspend fun alertRules(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<MkAlertRuleDto>>

    @POST("user/v1/marketing/projects/{id}/alert-rules")
    suspend fun createAlertRule(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: MkAlertRuleRequest): ApiEnvelope<MkAlertRuleDto>

    @PATCH("user/v1/marketing/alert-rules/{rule}")
    suspend fun toggleAlertRule(@Header("Authorization") auth: String, @Path("rule") rule: String, @Body body: MkActiveRequest): ApiEnvelope<MkAlertRuleDto>

    @DELETE("user/v1/marketing/alert-rules/{rule}")
    suspend fun deleteAlertRule(@Header("Authorization") auth: String, @Path("rule") rule: String): ApiEnvelope<JsonElement?>

    @GET("user/v1/marketing/projects/{id}/experiments")
    suspend fun experiments(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<MkExperimentDto>>

    @POST("user/v1/marketing/projects/{id}/experiments")
    suspend fun createExperiment(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: MkExperimentRequest): ApiEnvelope<MkExperimentDto>

    @POST("user/v1/marketing/experiments/{experiment}/results")
    suspend fun experimentResults(@Header("Authorization") auth: String, @Path("experiment") experiment: String, @Body body: MkExperimentResultRequest): ApiEnvelope<MkExperimentDto>

    @POST("user/v1/marketing/experiments/{experiment}/conclude")
    suspend fun concludeExperiment(@Header("Authorization") auth: String, @Path("experiment") experiment: String): ApiEnvelope<MkExperimentDto>

    @POST("user/v1/marketing/plans/{plan}/simulate")
    suspend fun simulate(@Header("Authorization") auth: String, @Path("plan") plan: String, @Body body: MkSimulateRequest): ApiEnvelope<MkSimulationDto>
}

// =============================================================================== DTOs

data class MkStatusDto(
    val available: Boolean = false,
    val features: Map<String, Boolean> = emptyMap(),
    @SerializedName("research_ready") val researchReady: Boolean = false,
    val country: MkCountryDto? = null,
    val subscription: MkSubscriptionDto? = null,
    val packages: List<MkPackageDto> = emptyList(),
    @SerializedName("checkout_purpose") val checkoutPurpose: String = "marketing_package",
)

data class MkCountryDto(val code: String? = null, @SerializedName("currency_code") val currencyCode: String? = null)

data class MkSubscriptionDto(
    val active: Boolean = false,
    @SerializedName("package") val pkg: MkPackageRefDto? = null,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    @SerializedName("paid_until") val paidUntil: String? = null,
    val usage: Map<String, Int> = emptyMap(),
    val limits: Map<String, Int?>? = null,
)

data class MkPackageRefDto(val code: String, val name: String? = null)

data class MkPackageDto(
    val code: String,
    val name: String? = null,
    val description: String? = null,
    @SerializedName("period_days") val periodDays: Int = 30,
    val limits: Map<String, Int?> = emptyMap(),
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("currency_code") val currencyCode: String? = null,
)

data class MkAbilitiesDto(
    val edit: Boolean = false,
    val research: Boolean = false,
    val approve: Boolean = false,
    @SerializedName("manage_team") val manageTeam: Boolean = false,
    @SerializedName("approve_budget") val approveBudget: Boolean = false,
    val results: Boolean = false,
)

data class MkProfileDto(
    val offer: String? = null,
    @SerializedName("customer_segments") val customerSegments: List<String> = emptyList(),
    val channels: List<String> = emptyList(),
    @SerializedName("team_size") val teamSize: Int? = null,
    @SerializedName("monthly_budget_minor") val monthlyBudgetMinor: Long? = null,
)

data class MkProjectDto(
    val id: String,
    val name: String,
    val industry: String,
    val stage: String = "idea",
    val goals: List<String> = emptyList(),
    val cities: List<String> = emptyList(),
    val currency: String = "",
    val status: String = "active",
    @SerializedName("lock_version") val lockVersion: Int = 1,
    @SerializedName("my_role") val myRole: String? = null,
    val abilities: MkAbilitiesDto? = null,
    @SerializedName("members_count") val membersCount: Int? = null,
    val profile: MkProfileDto? = null,
    @SerializedName("updated_at") val updatedAt: String? = null,
)

data class MkProjectRequest(
    val name: String? = null,
    val industry: String? = null,
    val stage: String? = null,
    val cities: List<String>? = null,
    val goals: List<String>? = null,
    @SerializedName("lock_version") val lockVersion: Int? = null,
)

data class MkArchiveRequest(val archived: Boolean)

data class MkQuestionDto(
    val key: String,
    val type: String = "text",
    val origin: String = "core",
    val required: Boolean = false,
    val text: String = "",
    val answered: Boolean = false,
    val value: JsonElement? = null,
)

data class MkDiscoveryDto(
    val complete: Boolean = false,
    val answered: Int = 0,
    val total: Int = 0,
    val questions: List<MkQuestionDto> = emptyList(),
)

data class MkMemberDto(
    val id: Long,
    val role: String,
    val name: String? = null,
    val phone: String? = null,
    @SerializedName("is_me") val isMe: Boolean = false,
)

data class MkMemberRequest(val phone: String, val role: String)

data class MkRoleRequest(val role: String)

data class MkJobDto(
    val id: String,
    val type: String,
    val status: String,
    @SerializedName("progress_pct") val progressPct: Int = 0,
    @SerializedName("error_code") val errorCode: String? = null,
    val retryable: Boolean = false,
    @SerializedName("plan_type") val planType: String? = null,
    val created: List<String>? = null,
) {
    val finished: Boolean get() = status in setOf("COMPLETED", "PARTIAL", "FAILED", "CANCELLED")
}

data class MkSourceDto(
    val ref: String,
    @SerializedName("plan_ref") val planRef: String? = null,
    val url: String,
    val title: String? = null,
    val publisher: String? = null,
    @SerializedName("published_at") val publishedAt: String? = null,
    @SerializedName("retrieved_at") val retrievedAt: String? = null,
    val excerpt: String? = null,
    val credibility: String = "unknown",
)

data class MkFindingDto(
    val id: Long,
    val claim: String,
    val sources: List<String> = emptyList(),
    val confidence: String = "low",
    @SerializedName("is_assumption") val isAssumption: Boolean = false,
    @SerializedName("is_quantitative") val isQuantitative: Boolean = false,
)

data class MkRunDto(
    val id: String,
    val kind: String,
    val status: String,
    val summary: String? = null,
    val gaps: List<String> = emptyList(),
    @SerializedName("quality_score") val qualityScore: Int? = null,
    @SerializedName("error_code") val errorCode: String? = null,
    @SerializedName("sources_count") val sourcesCount: Int? = null,
    @SerializedName("findings_count") val findingsCount: Int? = null,
    val sources: List<MkSourceDto>? = null,
    val findings: List<MkFindingDto>? = null,
    @SerializedName("created_at") val createdAt: String? = null,
) {
    val finished: Boolean get() = status in setOf("COMPLETED", "PARTIAL", "FAILED", "CANCELLED")
}

data class MkResearchRequest(val kind: String, @SerializedName("max_sources") val maxSources: Int? = null)

data class MkStartedRunDto(val run: MkRunDto, val job: MkJobDto)

data class MkCriticDto(val code: String, val severity: String = "warning")

data class MkMetricDto(val value: Double? = null, val reason: String? = null, val basis: String = "estimated")

data class MkScenarioDto(
    val inputs: Map<String, Double?> = emptyMap(),
    val outputs: Map<String, MkMetricDto> = emptyMap(),
    @SerializedName("calculation_version") val calculationVersion: String? = null,
)

data class MkVersionDto(
    val version: Int,
    val origin: String = "ai",
    val content: JsonObject = JsonObject(),
    @SerializedName("content_hash") val contentHash: String = "",
    val critic: List<MkCriticDto> = emptyList(),
    val scenarios: Map<String, MkScenarioDto> = emptyMap(),
    @SerializedName("created_at") val createdAt: String? = null,
)

data class MkVersionRowDto(val version: Int, val origin: String = "ai", @SerializedName("created_at") val createdAt: String? = null)

data class MkApprovalDto(val id: String, val version: Int, @SerializedName("approved_at") val approvedAt: String? = null)

data class MkPlanDto(
    val id: String,
    val type: String,
    val status: String,
    @SerializedName("versions_count") val versionsCount: Int? = null,
    @SerializedName("current_version") val currentVersion: MkVersionDto? = null,
    val approval: MkApprovalDto? = null,
)

data class MkPlanRequest(val type: String, val instructions: String? = null)

data class MkStartedPlanDto(val plan: MkPlanDto, val job: MkJobDto)

data class MkVersionRequest(val version: Int)

// =============================================================================== phase B DTOs

data class MkJobStartDto(val job: MkJobDto? = null, @SerializedName("plan_id") val planId: String? = null)

data class MkCalendarDto(val from: String = "", val to: String = "", val items: List<MkContentDto> = emptyList(), val unplanned: Int = 0)

data class MkCalendarRequest(
    val days: Int,
    val from: String? = null,
    val channels: List<String>? = null,
    @SerializedName("replace_drafts") val replaceDrafts: Boolean = true,
    val timezone: String? = null,
)

data class MkStudioRequest(
    val channel: String,
    val brief: String,
    val kind: String = "ad",
    val format: String? = null,
    val objective: String? = null,
    val variants: Int = 2,
)

data class MkVariantDto(
    val key: String = "A",
    val headline: String? = null,
    val body: String? = null,
    val cta: String? = null,
    val hashtags: List<String> = emptyList(),
    @SerializedName("visual_brief") val visualBrief: String? = null,
)

data class MkClaimDto(val code: String, val severity: String = "warning", val variant: String? = null, val detail: String? = null)

data class MkContentDto(
    val id: String,
    val kind: String = "post",
    val channel: String = "other",
    val format: String? = null,
    val title: String = "",
    val objective: String? = null,
    val variants: List<MkVariantDto> = emptyList(),
    val selected: String? = null,
    val claims: List<MkClaimDto> = emptyList(),
    @SerializedName("has_blocking_issues") val hasBlockingIssues: Boolean = false,
    val status: String = "DRAFT",
    @SerializedName("planned_at") val plannedAt: String? = null,
    @SerializedName("cost_estimate_minor") val costEstimateMinor: Long? = null,
    val origin: String = "manual",
    @SerializedName("review_note") val reviewNote: String? = null,
    @SerializedName("published_url") val publishedUrl: String? = null,
    @SerializedName("lock_version") val lockVersion: Int = 1,
) {
    val isTask: Boolean get() = kind == "task"
}

data class MkLockRequest(@SerializedName("lock_version") val lockVersion: Int)

data class MkNoteRequest(val note: String? = null)

data class MkScheduleRequest(@SerializedName("planned_at") val plannedAt: String)

data class MkOutcomeRequest(val published: Boolean, val url: String? = null, val note: String? = null)

data class MkDoneRequest(val done: Boolean)

data class MkBudgetDto(
    val id: String,
    val kind: String = "ads",
    @SerializedName("period_start") val periodStart: String = "",
    @SerializedName("period_end") val periodEnd: String = "",
    val currency: String? = null,
    val cap: Long = 0,
    val used: Long = 0,
    val estimated: Long? = null,
    val remaining: Long = 0,
    val pct: Double? = null,
    val level: String = "ok",
    @SerializedName("alert_pct") val alertPct: Int = 80,
    val status: String = "PENDING",
    val note: String? = null,
    val active: Boolean = false,
    @SerializedName("lock_version") val lockVersion: Int = 1,
)

data class MkBudgetRequest(
    val kind: String,
    @SerializedName("period_start") val periodStart: String,
    @SerializedName("period_end") val periodEnd: String,
    @SerializedName("cap_minor") val capMinor: Long? = null,
    @SerializedName("cap_calls") val capCalls: Long? = null,
    @SerializedName("alert_pct") val alertPct: Int = 80,
)

data class MkBudgetUpdateRequest(
    @SerializedName("lock_version") val lockVersion: Int,
    @SerializedName("cap_minor") val capMinor: Long? = null,
    @SerializedName("cap_calls") val capCalls: Long? = null,
    @SerializedName("alert_pct") val alertPct: Int? = null,
)

data class MkMetricRowDto(
    val id: Long,
    val date: String = "",
    val channel: String = "other",
    val source: String = "manual",
    val basis: String = "imported",
    val impressions: Long? = null,
    val clicks: Long? = null,
    @SerializedName("spend_minor") val spendMinor: Long? = null,
    val conversions: Long? = null,
    @SerializedName("revenue_minor") val revenueMinor: Long? = null,
    val currency: String? = null,
)

data class MkMetricInput(
    val date: String,
    val channel: String,
    val basis: String = "imported",
    val impressions: Long? = null,
    val clicks: Long? = null,
    @SerializedName("spend_minor") val spendMinor: Long? = null,
    val conversions: Long? = null,
    @SerializedName("revenue_minor") val revenueMinor: Long? = null,
)

data class MkMetricsRequest(val rows: List<MkMetricInput>)

data class MkImportResultDto(val created: Int = 0, val updated: Int = 0, val rows: Int? = null)

data class MkRateDto(val value: Double? = null, val reason: String? = null)

data class MkKpiGroupDto(val rows: Int = 0, val totals: Map<String, Long?> = emptyMap(), val rates: Map<String, MkRateDto> = emptyMap())

data class MkChannelKpiDto(
    val channel: String,
    val measured: MkKpiGroupDto = MkKpiGroupDto(),
    val estimated: MkKpiGroupDto? = null,
    @SerializedName("last_date") val lastDate: String? = null,
)

data class MkKpiGapsDto(
    val days: Int = 0,
    @SerializedName("days_with_data") val daysWithData: Int = 0,
    @SerializedName("days_without_data") val daysWithoutData: Int = 0,
    @SerializedName("channels_without_revenue") val channelsWithoutRevenue: List<String> = emptyList(),
)

data class MkSeriesPointDto(val date: String, @SerializedName("spend_minor") val spendMinor: Long? = null, val conversions: Long? = null, @SerializedName("revenue_minor") val revenueMinor: Long? = null)

data class MkKpiBoardDto(
    val from: String = "",
    val to: String = "",
    val currency: String = "",
    @SerializedName("last_updated_at") val lastUpdatedAt: String? = null,
    val measured: MkKpiGroupDto = MkKpiGroupDto(),
    val estimated: MkKpiGroupDto? = null,
    @SerializedName("by_channel") val byChannel: List<MkChannelKpiDto> = emptyList(),
    val series: List<MkSeriesPointDto> = emptyList(),
    val gaps: MkKpiGapsDto = MkKpiGapsDto(),
)

data class MkReportLineDto(val code: String, val level: String = "info", val text: String = "", @SerializedName("item_id") val itemId: String? = null)

data class MkReportResultsDto(
    val current: MkKpiGroupDto = MkKpiGroupDto(),
    val previous: MkKpiGroupDto = MkKpiGroupDto(),
    @SerializedName("change_pct") val changePct: Map<String, Double?> = emptyMap(),
    val estimated: MkKpiGroupDto? = null,
    @SerializedName("last_updated_at") val lastUpdatedAt: String? = null,
    @SerializedName("days_with_data") val daysWithData: Int = 0,
)

data class MkReportDto(
    val period: String = "week",
    val from: String = "",
    val to: String = "",
    val currency: String = "",
    val decisions: List<MkReportLineDto> = emptyList(),
    val results: MkReportResultsDto = MkReportResultsDto(),
    val risks: List<MkReportLineDto> = emptyList(),
    val recommendations: List<MkReportLineDto> = emptyList(),
    val note: String? = null,
)

// =============================================================================== phase C DTOs

data class MkAdAccountDto(val id: String, val name: String? = null, val currency: String? = null)

data class MkIntegrationDto(
    val id: String,
    val provider: String,
    val status: String,
    val account: MkAdAccountDto? = null,
    @SerializedName("available_accounts") val availableAccounts: List<MkAdAccountDto>? = null,
    val sandbox: Boolean = false,
    @SerializedName("token_expires_at") val tokenExpiresAt: String? = null,
    @SerializedName("last_error") val lastError: String? = null,
    @SerializedName("reconnect_needed") val reconnectNeeded: Boolean = false,
)

data class MkPlatformDto(val provider: String, val available: Boolean = false, @SerializedName("coming_soon") val comingSoon: Boolean = false, val configured: Boolean = false)

data class MkIntegrationsDto(val integrations: List<MkIntegrationDto> = emptyList(), val platforms: List<MkPlatformDto> = emptyList())

data class MkConnectDto(val integration: MkIntegrationDto? = null, @SerializedName("authorize_url") val authorizeUrl: String? = null)

data class MkAccountRequest(@SerializedName("account_id") val accountId: String)

data class MkIssueDto(val code: String, val detail: String? = null)

data class MkCampaignApprovalDto(
    val id: String,
    val version: Int,
    @SerializedName("cap_minor") val capMinor: Long = 0,
    @SerializedName("expires_at") val expiresAt: String? = null,
    @SerializedName("by_delegation") val byDelegation: Boolean = false,
)

data class MkActionDto(val id: String, val type: String? = null, val status: String, @SerializedName("error_code") val errorCode: String? = null)

data class MkCampaignDto(
    val id: String,
    val provider: String = "meta",
    @SerializedName("integration_id") val integrationId: String? = null,
    @SerializedName("content_id") val contentId: String? = null,
    val name: String = "",
    val objective: String = "traffic",
    val status: String = "DRAFT",
    val version: Int = 1,
    @SerializedName("daily_budget_minor") val dailyBudgetMinor: Long = 0,
    @SerializedName("total_budget_minor") val totalBudgetMinor: Long = 0,
    val currency: String = "",
    @SerializedName("start_at") val startAt: String? = null,
    @SerializedName("end_at") val endAt: String? = null,
    val targeting: JsonObject? = null,
    val validation: List<MkIssueDto> = emptyList(),
    val approval: MkCampaignApprovalDto? = null,
    @SerializedName("platform_ref") val platformRef: String? = null,
    @SerializedName("platform_status") val platformStatus: String? = null,
    @SerializedName("last_error") val lastError: String? = null,
    @SerializedName("published_at") val publishedAt: String? = null,
    @SerializedName("metrics_synced_at") val metricsSyncedAt: String? = null,
    @SerializedName("last_action") val lastAction: MkActionDto? = null,
)

data class MkCampaignVersionRequest(val version: Int)

data class MkPublishRequest(
    @SerializedName("approval_id") val approvalId: String,
    @SerializedName("campaign_version") val campaignVersion: Int,
    @SerializedName("expected_budget_minor") val expectedBudgetMinor: Long,
    val currency: String,
)

data class MkPublishDto(val campaign: MkCampaignDto? = null, val action: MkActionDto? = null, val replayed: Boolean = false)

data class MkCampaignMetricsDto(@SerializedName("synced_at") val syncedAt: String? = null, val rows: List<MkMetricRowDto> = emptyList())

data class MkDelegationDto(
    val id: String,
    @SerializedName("member_id") val memberId: Long? = null,
    val name: String? = null,
    @SerializedName("cap_minor") val capMinor: Long = 0,
    @SerializedName("used_minor") val usedMinor: Long = 0,
    @SerializedName("remaining_minor") val remainingMinor: Long = 0,
    val currency: String? = null,
    val status: String = "active",
    @SerializedName("expires_at") val expiresAt: String? = null,
)

data class MkDelegationRequest(@SerializedName("member_id") val memberId: Long, @SerializedName("cap_minor") val capMinor: Long, @SerializedName("expires_at") val expiresAt: String)

// =============================================================================== phase D DTOs

data class MkSuggestionDto(val code: String, val priority: String = "low", val channel: String? = null, val text: String = "")

data class MkOptimisationsDto(val suggestions: List<MkSuggestionDto> = emptyList(), val note: String? = null)

data class MkAlertRuleDto(
    val id: String,
    val metric: String,
    val comparison: String,
    val threshold: Double = 0.0,
    @SerializedName("window_days") val windowDays: Int = 7,
    val channel: String? = null,
    val active: Boolean = true,
    val current: MkRateDto? = null,
    @SerializedName("last_triggered_at") val lastTriggeredAt: String? = null,
)

data class MkAlertRuleRequest(val metric: String, val comparison: String, val threshold: Double, @SerializedName("window_days") val windowDays: Int, val channel: String? = null)

data class MkActiveRequest(val active: Boolean)

data class MkExperimentVariantDto(val key: String, val label: String? = null)

data class MkVerdictDto(
    val verdict: String = "missing_data",
    val winner: String? = null,
    @SerializedName("p_value") val pValue: Double? = null,
    @SerializedName("lift_pct") val liftPct: Double? = null,
    val rates: Map<String, Double?> = emptyMap(),
    val text: String = "",
)

data class MkExperimentDto(
    val id: String,
    val name: String = "",
    val hypothesis: String = "",
    @SerializedName("success_metric") val successMetric: String = "ctr",
    val variants: List<MkExperimentVariantDto> = emptyList(),
    val results: Map<String, Map<String, Long?>> = emptyMap(),
    val status: String = "DRAFT",
    val conclusion: MkVerdictDto? = null,
    val preview: MkVerdictDto? = null,
)

data class MkExperimentRequest(val name: String, val hypothesis: String, @SerializedName("success_metric") val successMetric: String, val content: String? = null)

data class MkExperimentResultRequest(val variant: String, val impressions: Long? = null, val clicks: Long? = null, val conversions: Long? = null)

data class MkSimulateRequest(@SerializedName("swing_pct") val swingPct: Int)

data class MkTornadoDto(val input: String, @SerializedName("low_profit_minor") val lowProfitMinor: Long, @SerializedName("high_profit_minor") val highProfitMinor: Long, @SerializedName("swing_minor") val swingMinor: Long)

data class MkSimulationDto(@SerializedName("base_profit_minor") val baseProfitMinor: Long? = null, @SerializedName("swing_pct") val swingPct: Int = 20, val tornado: List<MkTornadoDto> = emptyList())

