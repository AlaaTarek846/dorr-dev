package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.ResponseBody
import retrofit2.http.Multipart
import retrofit2.http.PartMap
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query
import retrofit2.http.Streaming
import retrofit2.http.Url

/** The agreement on my chosen offer (FL-401), under `/api/freelance/v1/projects/{uuid}/agreement`. */
interface AgreementsApi {
    @GET("${ApiClient.FREELANCE}projects/{uuid}/agreement")
    suspend fun show(@Path("uuid") uuid: String): Response<ApiEnvelope<AgreementDto>>

    /** My work: the projects I was chosen for, `group` = active, completed or closed. */
    @GET("${ApiClient.FREELANCE}my-projects")
    suspend fun myProjects(@Query("page") page: Int = 1, @Query("per_page") perPage: Int = 15, @Query("group") group: String? = null): Response<ApiEnvelope<List<MyProjectDto>>>

    /** FL-605: progress reports - the client's asking and what I sent. */
    @GET("${ApiClient.FREELANCE}projects/{uuid}/reports")
    suspend fun reports(@Path("uuid") uuid: String): Response<ApiEnvelope<ReportsDto>>

    @POST("${ApiClient.FREELANCE}projects/{uuid}/reports")
    suspend fun sendReport(@Path("uuid") uuid: String, @Body body: ReportRequest): Response<ApiEnvelope<ReportsDto>>

    @POST("${ApiClient.FREELANCE}projects/{uuid}/agreement/approve")
    suspend fun approve(@Path("uuid") uuid: String): Response<ApiEnvelope<AgreementDto>>

    /** FL-403: action = start · deliver {note}. */
    @POST("${ApiClient.FREELANCE}projects/{uuid}/milestones/{milestone}/{action}")
    suspend fun milestone(@Path("uuid") uuid: String, @Path("milestone") milestone: String, @Path("action") action: String, @Body body: MilestoneActionRequest): Response<ApiEnvelope<AgreementDto>>

    /** FL-601: a delivery is a new version of the part - `note` and up to 10 `files[]`. */
    @Multipart
    @POST("${ApiClient.FREELANCE}projects/{uuid}/milestones/{milestone}/deliver")
    suspend fun deliver(@Path("uuid") uuid: String, @Path("milestone") milestone: String, @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>, @Part files: List<MultipartBody.Part>): Response<ApiEnvelope<AgreementDto>>

    /** The versions delivered for a part, newest first. */
    @GET("${ApiClient.FREELANCE}projects/{uuid}/milestones/{milestone}/deliveries")
    suspend fun deliveries(@Path("uuid") uuid: String, @Path("milestone") milestone: String): Response<ApiEnvelope<List<DeliveryDto>>>

    /** A delivered file: private, only the two people of the agreement, signed in. */
    @Streaming
    @GET
    suspend fun file(@Url path: String): Response<ResponseBody>

    /** FL-701: rate the client after the project. */
    @POST("${ApiClient.FREELANCE}projects/{uuid}/review")
    suspend fun review(@Path("uuid") uuid: String, @Body body: ReviewRequest): Response<ApiEnvelope<ReviewDto>>

    /** FL-706: object to the client's rating of me (once). FL-702: the satisfaction survey after the project. */
    @POST("${ApiClient.FREELANCE}projects/{uuid}/review/objection")
    suspend fun objectToReview(@Path("uuid") uuid: String, @Body body: ObjectionRequest): Response<ApiEnvelope<ReviewDto>>

    @GET("${ApiClient.FREELANCE}projects/{uuid}/survey")
    suspend fun survey(@Path("uuid") uuid: String): Response<ApiEnvelope<SurveyDto>>

    @POST("${ApiClient.FREELANCE}projects/{uuid}/survey")
    suspend fun answerSurvey(@Path("uuid") uuid: String, @Body body: SurveyRequest): Response<ApiEnvelope<SurveyDto>>

    /** FL-511: my receipts of the project money, newest first; one with its text to share. */
    @GET("${ApiClient.FREELANCE}receipts")
    suspend fun receipts(@Query("project") project: String? = null, @Query("per_page") perPage: Int = 50): Response<ApiEnvelope<List<ReceiptDto>>>

    @GET("${ApiClient.FREELANCE}receipts/{uuid}")
    suspend fun receipt(@Path("uuid") uuid: String): Response<ApiEnvelope<ReceiptDto>>

    /** FL-406: the project's record of approvals. */
    @GET("${ApiClient.FREELANCE}projects/{uuid}/agreement/trail")
    suspend fun trail(@Path("uuid") uuid: String): Response<ApiEnvelope<List<TrailStepDto>>>

    /** FL-703: my numbers and the latest ratings clients gave me. */
    @GET("${ApiClient.FREELANCE}reviews")
    suspend fun reviews(): Response<ApiEnvelope<MyReviewsDto>>

    /** FL-404 / FL-405: ask for a change to the agreement, or answer one (accept · reject · withdraw). */
    @POST("${ApiClient.FREELANCE}projects/{uuid}/agreement/changes")
    suspend fun requestChange(@Path("uuid") uuid: String, @Body body: ChangeRequest): Response<ApiEnvelope<List<ChangeDto>>>

    @POST("${ApiClient.FREELANCE}projects/{uuid}/agreement/changes/{change}/{action}")
    suspend fun answerChange(@Path("uuid") uuid: String, @Path("change") change: String, @Path("action") action: String): Response<ApiEnvelope<List<ChangeDto>>>

    /** FL-509: my earnings from projects. */
    @GET("${ApiClient.FREELANCE}earnings")
    suspend fun earnings(): Response<ApiEnvelope<EarningsDto>>

    /** FL-704: a dispute on a part. */
    @POST("${ApiClient.FREELANCE}projects/{uuid}/milestones/{milestone}/dispute")
    suspend fun openDispute(@Path("uuid") uuid: String, @Path("milestone") milestone: String, @Body body: DisputeOpenRequest): Response<ApiEnvelope<DisputeDto>>

    @GET("${ApiClient.FREELANCE}projects/{uuid}/disputes/{dispute}")
    suspend fun dispute(@Path("uuid") uuid: String, @Path("dispute") dispute: String): Response<ApiEnvelope<DisputeDto>>

    @POST("${ApiClient.FREELANCE}projects/{uuid}/disputes/{dispute}/evidence")
    suspend fun disputeEvidence(@Path("uuid") uuid: String, @Path("dispute") dispute: String, @Body body: TextRequest): Response<ApiEnvelope<DisputeDto>>

    @POST("${ApiClient.FREELANCE}projects/{uuid}/disputes/{dispute}/appeal")
    suspend fun appealDispute(@Path("uuid") uuid: String, @Path("dispute") dispute: String, @Body body: TextRequest): Response<ApiEnvelope<DisputeDto>>

    @POST("${ApiClient.FREELANCE}projects/{uuid}/agreement/decline")
    suspend fun decline(@Path("uuid") uuid: String, @Body body: DeclineRequest): Response<ApiEnvelope<AgreementDto>>
}

data class AgreementDto(
    val uuid: String,
    val status: String = "pending_approval",
    val scope: String? = null,
    val includes: String? = null,
    val excludes: String? = null,
    val currency: String = "",
    @SerializedName("duration_days") val durationDays: Int = 0,
    val money: AgreementMoneyDto = AgreementMoneyDto(),
    val terms: AgreementTermsDto = AgreementTermsDto(),
    @SerializedName("expires_at") val expiresAt: String? = null,
    val milestones: List<MilestoneDto> = emptyList(),
    /** FL-404 / FL-405: the changes asked, newest first. */
    val changes: List<ChangeDto> = emptyList(),
    /** FL-701: rating each other once it is completed. */
    val review: ReviewDto? = null,
    val can: AgreementCanDto = AgreementCanDto(),
    /** FL-605: how often the client asked for a progress report, and whether the next one is late. */
    val reports: ReportSummaryDto = ReportSummaryDto(),
)

data class ReportSummaryDto(
    @SerializedName("every_days") val everyDays: Int? = null,
    @SerializedName("next_due_at") val nextDueAt: String? = null,
    @SerializedName("last_at") val lastAt: String? = null,
    val late: Boolean = false,
)

data class ReportDto(val uuid: String = "", val note: String = "", val percent: Int? = null, @SerializedName("created_at") val createdAt: String? = null)

data class ReportsDto(val summary: ReportSummaryDto = ReportSummaryDto(), val reports: List<ReportDto> = emptyList())

data class ReportRequest(val note: String, val percent: Int? = null)

/** One part of the agreement (FL-402) and what it leaves me. */
data class MilestoneDto(
    val uuid: String = "",
    val position: Int = 1,
    val title: String = "",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("net_minor") val netMinor: Long = 0,
    val status: String = "unfunded",
    @SerializedName("revision_reason") val revisionReason: String? = null,
    /** FL-503: what reached my wallet. */
    @SerializedName("paid_minor") val paidMinor: Long? = null,
    @SerializedName("auto_approve_at") val autoApproveAt: String? = null,
    @SerializedName("cancel_requested_by") val cancelRequestedBy: String? = null,
    @SerializedName("revisions_left") val revisionsLeft: Int? = null,
    val dispute: DisputeRefDto? = null,
    /** FL-601 / FL-604: how many versions were delivered, and what the client checks before approving. */
    @SerializedName("deliveries_count") val deliveriesCount: Int = 0,
    val checklist: List<ChecklistItemDto> = emptyList(),
    val can: MilestoneCanDto = MilestoneCanDto(),
)

data class ChecklistItemDto(val uuid: String = "", val text: String = "", val checked: Boolean = false)

data class DeliveryDto(
    val uuid: String = "",
    val number: Int = 1,
    val note: String? = null,
    @SerializedName("delivered_at") val deliveredAt: String? = null,
    @SerializedName("revision_reason") val revisionReason: String? = null,
    val files: List<DeliveryFileDto> = emptyList(),
)

data class DeliveryFileDto(
    val id: Long = 0,
    val name: String = "",
    val mime: String = "",
    val size: Long = 0,
    @SerializedName("is_image") val isImage: Boolean = false,
    /** Under freelance/v1/. */
    val path: String = "",
)

data class MilestoneCanDto(
    val start: Boolean = false,
    val deliver: Boolean = false,
    val withdraw: Boolean = false,
    @SerializedName("cancel_accept") val cancelAccept: Boolean = false,
    @SerializedName("cancel_reject") val cancelReject: Boolean = false,
    val dispute: Boolean = false,
)

data class MilestoneActionRequest(val note: String? = null, @SerializedName("reason_code") val reasonCode: String? = null)

data class AgreementMoneyDto(
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("commission_minor") val commissionMinor: Long = 0,
    @SerializedName("net_minor") val netMinor: Long = 0,
)

data class AgreementTermsDto(@SerializedName("review_days") val reviewDays: Int? = null, @SerializedName("free_revisions") val freeRevisions: Int? = null)

data class AgreementCanDto(val approve: Boolean = false, val decline: Boolean = false, val report: Boolean = false)

data class DeclineRequest(val reason: String?)

/** What my offer shows of the agreement. */
data class OfferAgreementDto(val uuid: String, val status: String = "pending_approval")

data class DisputeRefDto(val uuid: String, val status: String = "open")

data class DisputeOpenRequest(val reason: String, val details: String)

/** `body` for evidence, `reason` for an appeal. */
data class TextRequest(val body: String? = null, val reason: String? = null)

data class DisputeDto(
    val uuid: String,
    val status: String = "open",
    @SerializedName("reason_label") val reasonLabel: String = "",
    val details: String = "",
    val mine: Boolean = false,
    val milestone: DisputePartDto? = null,
    val currency: String? = null,
    val decision: DisputeDecisionDto? = null,
    val evidence: List<DisputeEvidenceDto> = emptyList(),
    val can: DisputeCanDto = DisputeCanDto(),
)

data class DisputePartDto(val title: String = "", @SerializedName("amount_minor") val amountMinor: Long = 0)

data class DisputeDecisionDto(val type: String = "", @SerializedName("freelancer_bp") val freelancerBp: Int? = null, val note: String? = null, @SerializedName("executes_at") val executesAt: String? = null)

data class DisputeEvidenceDto(val id: Int = 0, val mine: Boolean = false, val body: String = "", val at: String? = null)

data class DisputeCanDto(val evidence: Boolean = false, val appeal: Boolean = false)

data class EarningsDto(
    val currency: String? = null,
    @SerializedName("available_minor") val availableMinor: Long = 0,
    @SerializedName("pending_minor") val pendingMinor: Long = 0,
    @SerializedName("next_release_at") val nextReleaseAt: String? = null,
    @SerializedName("expected_minor") val expectedMinor: Long = 0,
    @SerializedName("earned_minor") val earnedMinor: Long = 0,
    val recent: List<EarningDto> = emptyList(),
)

data class EarningDto(
    val id: Int = 0,
    val kind: String = "",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    val currency: String = "",
    val status: String = "",
    val project: EarningProjectDto? = null,
    val milestone: String? = null,
    @SerializedName("release_at") val releaseAt: String? = null,
    @SerializedName("released_at") val releasedAt: String? = null,
)

data class EarningProjectDto(val uuid: String? = null, val title: String? = null)

data class ChangeDto(
    val uuid: String,
    val type: String = "",
    val status: String = "pending",
    val mine: Boolean = false,
    val milestone: DisputePartDto? = null,
    val payload: Map<String, Any?> = emptyMap(),
    val reason: String? = null,
    val can: ChangeCanDto = ChangeCanDto(),
)

data class ChangeCanDto(val accept: Boolean = false, val reject: Boolean = false, val withdraw: Boolean = false)

data class ChangeRequest(
    val type: String,
    val milestone: String? = null,
    val days: Int? = null,
    @SerializedName("amount_minor") val amountMinor: Long? = null,
    val title: String? = null,
    val scope: String? = null,
    val includes: String? = null,
    val excludes: String? = null,
    val reason: String? = null,
)

data class ReviewDto(
    val criteria: List<String> = emptyList(),
    @SerializedName("can_rate") val canRate: Boolean = false,
    @SerializedName("rate_until") val rateUntil: String? = null,
    val mine: RatingDto? = null,
    val theirs: RatingDto? = null,
    @SerializedName("theirs_waiting") val theirsWaiting: Boolean = false,
    /** FL-706: I may object once to their rating of me; what became of it. */
    @SerializedName("can_object") val canObject: Boolean? = null,
    @SerializedName("object_until") val objectUntil: String? = null,
    val objection: ObjectionDto? = null,
    @SerializedName("objection_reasons") val objectionReasons: List<String>? = null,
)

data class RatingDto(
    val stars: Double = 0.0,
    val comment: String? = null,
    val scores: Map<String, Int> = emptyMap(),
    @SerializedName("comment_hidden") val commentHidden: Boolean? = null,
    val removed: Boolean? = null,
)

data class ObjectionDto(val reason: String? = null, val note: String? = null, val status: String = "open", @SerializedName("decision_note") val decisionNote: String? = null)

data class ObjectionRequest(val reason: String, val note: String)

/** FL-702 */
data class SurveyDto(
    @SerializedName("can_answer") val canAnswer: Boolean? = null,
    @SerializedName("answer_until") val answerUntil: String? = null,
    val reasons: List<String>? = null,
    @SerializedName("not_satisfied_max") val notSatisfiedMax: Int? = null,
    val mine: SurveyMineDto? = null,
)

data class SurveyMineDto(val score: Int = 0, val reasons: List<String>? = null, val comment: String? = null)

data class SurveyRequest(val score: Int, val reasons: List<String>, val comment: String?)

data class ReviewRequest(val scores: Map<String, Int>, val comment: String?)

data class MyReviewsDto(
    val rating: Double? = null,
    @SerializedName("reviews_count") val reviewsCount: Int = 0,
    @SerializedName("completed_projects") val completedProjects: Int = 0,
    @SerializedName("completion_rate") val completionRate: Int? = null,
    @SerializedName("on_time_rate") val onTimeRate: Int? = null,
    val latest: List<ReviewLineDto> = emptyList(),
)

data class ReviewLineDto(val stars: Double = 0.0, val comment: String? = null, val project: String? = null, val at: String? = null)

/** FL-511: a receipt of one money movement of a part. `text`/`reason_text` come with the single receipt. */
data class ReceiptDto(
    val uuid: String = "",
    val number: String = "",
    val kind: String = "",
    val title: String? = null,
    @SerializedName("reason_text") val reasonText: String? = null,
    val project: RefDto? = null,
    val milestone: RefDto? = null,
    val currency: String = "",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("fee_minor") val feeMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
    @SerializedName("issued_at") val issuedAt: String? = null,
    val text: String? = null,
)

data class RefDto(val uuid: String? = null, val title: String? = null)

/** FL-406: one step of the record of approvals. */
data class TrailStepDto(val id: Long = 0, val action: String = "", val label: String = "", val actor: String = "", val mine: Boolean = false, val at: String? = null)

data class MyProjectRef(val uuid: String? = null, val title: String? = null)

data class MyProjectDto(
    val uuid: String,
    val status: String,
    val project: MyProjectRef = MyProjectRef(),
    @SerializedName("price_minor") val priceMinor: Long = 0,
    val currency: String = "",
    @SerializedName("duration_days") val durationDays: Int = 0,
    @SerializedName("milestones_count") val milestonesCount: Int = 0,
    @SerializedName("milestones_done") val milestonesDone: Int = 0,
    @SerializedName("has_dispute") val hasDispute: Boolean = false,
)
