package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.ResponseBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.Response
import retrofit2.http.Streaming
import retrofit2.http.Url
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Freelance work, the client's side (Modules/Freelance, FL-201 / FL-202): the specialty and skill catalogs to pick from, and my
 * projects — a draft saved as it is typed, its files, sending it for review, taking it back, cancelling it. Money is in minor units
 * of the country's currency; the server checks the budget against the country's (and the specialty's) limits.
 */
interface FreelanceApi {
    @GET("mobile/v1/freelance/specialties")
    suspend fun specialties(@Header("Authorization") auth: String): ApiEnvelope<List<FreelanceSpecialtyNodeDto>>

    @GET("mobile/v1/freelance/skills")
    suspend fun skills(@Header("Authorization") auth: String, @Query("q") q: String?, @Query("per_page") perPage: Int = 30): ApiEnvelope<List<FreelanceSkillDto>>

    @GET("mobile/v1/freelance/projects")
    suspend fun projects(@Header("Authorization") auth: String, @Query("status") status: String? = null, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 15): ApiEnvelope<List<FreelanceProjectDto>>

    @GET("mobile/v1/freelance/projects/budget-rules")
    suspend fun budgetRules(@Header("Authorization") auth: String, @Query("specialty_id") specialtyId: Int?): ApiEnvelope<FreelanceBudgetRulesDto>

    @GET("mobile/v1/freelance/projects/{uuid}")
    suspend fun project(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<FreelanceProjectDto>

    @POST("mobile/v1/freelance/projects")
    suspend fun create(@Header("Authorization") auth: String, @Body body: FreelanceProjectRequest): ApiEnvelope<FreelanceProjectDto>

    @PUT("mobile/v1/freelance/projects/{uuid}")
    suspend fun update(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: FreelanceProjectRequest): ApiEnvelope<FreelanceProjectDto>

    @DELETE("mobile/v1/freelance/projects/{uuid}")
    suspend fun delete(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<Any?>

    @POST("mobile/v1/freelance/projects/{uuid}/submit")
    suspend fun submit(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<FreelanceProjectDto>

    @POST("mobile/v1/freelance/projects/{uuid}/withdraw")
    suspend fun withdraw(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<FreelanceProjectDto>

    /** The offers on my project (FL-305): sort = newest · cheapest · fastest · completeness; status = shortlisted … (null: all). */
    @GET("mobile/v1/freelance/projects/{uuid}/offers")
    suspend fun offers(
        @Header("Authorization") auth: String,
        @Path("uuid") uuid: String,
        @Query("sort") sort: String? = null,
        @Query("status") status: String? = null,
    ): ApiEnvelope<List<FreelanceOfferDto>>

    /** Two or three offers side by side (ids comma separated). */
    @GET("mobile/v1/freelance/projects/{uuid}/offers/compare")
    suspend fun compareOffers(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Query("ids") ids: String): ApiEnvelope<FreelanceCompareDto>

    @GET("mobile/v1/freelance/projects/{uuid}/offers/{offer}")
    suspend fun offer(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("offer") offer: String): ApiEnvelope<FreelanceOfferDto>

    /** The freelancer who sent it, as a client may read them (no phone, no email). */
    @GET("mobile/v1/freelance/projects/{uuid}/offers/{offer}/freelancer")
    suspend fun offerFreelancer(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("offer") offer: String): ApiEnvelope<FreelancerProfileDto>

    /** action = shortlist · reject (reason optional) · choose (FL-303 / FL-309). */
    @POST("mobile/v1/freelance/projects/{uuid}/offers/{offer}/{action}")
    suspend fun decideOffer(
        @Header("Authorization") auth: String,
        @Path("uuid") uuid: String,
        @Path("offer") offer: String,
        @Path("action") action: String,
        @Body body: FreelanceDecisionRequest,
    ): ApiEnvelope<FreelanceOfferDto>

    /** FL-401: the agreement with the chosen freelancer. */
    @GET("mobile/v1/freelance/projects/{uuid}/agreement")
    suspend fun agreement(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<FreelanceAgreementDto>

    @PUT("mobile/v1/freelance/projects/{uuid}/agreement")
    suspend fun updateAgreement(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: FreelanceAgreementRequest): ApiEnvelope<FreelanceAgreementDto>

    /** FL-402: the parts it is paid in — the whole list each time. */
    @PUT("mobile/v1/freelance/projects/{uuid}/agreement/milestones")
    suspend fun setMilestones(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: FreelanceMilestonesRequest): ApiEnvelope<FreelanceAgreementDto>

    /** FL-403: action = approve · revision {reason} · cancel (a part not funded yet). */
    @POST("mobile/v1/freelance/projects/{uuid}/milestones/{milestone}/{action}")
    suspend fun milestoneAction(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("milestone") milestone: String, @Path("action") action: String, @Body body: FreelanceDecisionRequest): ApiEnvelope<FreelanceAgreementDto>

    @POST("mobile/v1/freelance/projects/{uuid}/agreement/send")
    suspend fun sendAgreement(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<FreelanceAgreementDto>

    @POST("mobile/v1/freelance/projects/{uuid}/agreement/cancel")
    suspend fun cancelAgreement(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<FreelanceAgreementDto>

    /** FL-511: my receipts of the project money, newest first; one with its text to share. */
    @GET("mobile/v1/freelance/receipts")
    suspend fun receipts(@Header("Authorization") auth: String): ApiEnvelope<List<FreelanceReceiptDto>>

    @GET("mobile/v1/freelance/receipts/{uuid}")
    suspend fun receipt(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<FreelanceReceiptDto>

    /** FL-406: the project's record of approvals. */
    @GET("mobile/v1/freelance/projects/{uuid}/agreement/trail")
    suspend fun trail(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<List<FreelanceTrailDto>>

    /** FL-701: rate the freelancer after the project. */
    @POST("mobile/v1/freelance/projects/{uuid}/review")
    suspend fun review(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: FreelanceReviewRequest): ApiEnvelope<FreelanceReviewDto>

    /** FL-404 / FL-405: ask for a change to the agreement, or answer one (accept · reject · withdraw). */
    @POST("mobile/v1/freelance/projects/{uuid}/agreement/changes")
    suspend fun requestChange(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: FreelanceChangeRequest): ApiEnvelope<List<FreelanceChangeDto>>

    @POST("mobile/v1/freelance/projects/{uuid}/agreement/changes/{change}/{action}")
    suspend fun answerChange(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("change") change: String, @Path("action") action: String): ApiEnvelope<List<FreelanceChangeDto>>

    /** FL-704: a dispute on a part. */
    @POST("mobile/v1/freelance/projects/{uuid}/milestones/{milestone}/dispute")
    suspend fun openDispute(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("milestone") milestone: String, @Body body: FreelanceDisputeOpenRequest): ApiEnvelope<FreelanceDisputeDto>

    @GET("mobile/v1/freelance/projects/{uuid}/disputes/{dispute}")
    suspend fun dispute(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("dispute") dispute: String): ApiEnvelope<FreelanceDisputeDto>

    @POST("mobile/v1/freelance/projects/{uuid}/disputes/{dispute}/evidence")
    suspend fun disputeEvidence(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("dispute") dispute: String, @Body body: FreelanceTextRequest): ApiEnvelope<FreelanceDisputeDto>

    @POST("mobile/v1/freelance/projects/{uuid}/disputes/{dispute}/appeal")
    suspend fun appealDispute(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("dispute") dispute: String, @Body body: FreelanceTextRequest): ApiEnvelope<FreelanceDisputeDto>

    /** FL-306: the freelancers I keep. */
    @GET("mobile/v1/freelance/favorites")
    suspend fun favorites(@Header("Authorization") auth: String): ApiEnvelope<List<FreelanceFavoriteDto>>

    @POST("mobile/v1/freelance/favorites/{uuid}")
    suspend fun addFavorite(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<Any?>

    @retrofit2.http.DELETE("mobile/v1/freelance/favorites/{uuid}")
    suspend fun removeFavorite(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<Any?>

    /** The talk about an offer (FL-308): opens the room (or finds it). */
    @POST("mobile/v1/freelance/projects/{uuid}/offers/{offer}/chat")
    suspend fun openChat(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("offer") offer: String): ApiEnvelope<FreelanceChatRoomDto>

    @GET("mobile/v1/freelance/chat/conversations/{uuid}")
    suspend fun chatRoom(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<FreelanceChatRoomDto>

    /** Newest first; `before` is the id to continue from. */
    @GET("mobile/v1/freelance/chat/conversations/{uuid}/messages")
    suspend fun chatMessages(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Query("before") before: Int? = null, @Query("limit") limit: Int = 40): ApiEnvelope<List<FreelanceChatMessageDto>>

    @POST("mobile/v1/freelance/chat/conversations/{uuid}/messages")
    suspend fun chatSend(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: FreelanceChatSend): ApiEnvelope<FreelanceChatMessageDto>

    /** FL-310: the interviews of this room; propose a time; confirm / decline / cancel one. */
    @GET("mobile/v1/freelance/chat/conversations/{uuid}/interviews")
    suspend fun interviews(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<List<FreelanceInterviewDto>>

    @POST("mobile/v1/freelance/chat/conversations/{uuid}/interviews")
    suspend fun proposeInterview(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: FreelanceInterviewRequest): ApiEnvelope<List<FreelanceInterviewDto>>

    @POST("mobile/v1/freelance/chat/conversations/{uuid}/interviews/{interview}/{action}")
    suspend fun answerInterview(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("interview") interview: String, @Path("action") action: String): ApiEnvelope<List<FreelanceInterviewDto>>

    @POST("mobile/v1/freelance/chat/conversations/{uuid}/read")
    suspend fun chatRead(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<Any?>

    /** FL-602 / FL-604: the versions delivered for a part, the checklist I write before the work, and ticking a line while reviewing. */
    @GET("mobile/v1/freelance/projects/{uuid}/milestones/{milestone}/deliveries")
    suspend fun deliveries(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("milestone") milestone: String): ApiEnvelope<List<FreelanceDeliveryDto>>

    @PUT("mobile/v1/freelance/projects/{uuid}/milestones/{milestone}/checklist")
    suspend fun setChecklist(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("milestone") milestone: String, @Body body: FreelanceChecklistRequest): ApiEnvelope<List<FreelanceChecklistItemDto>>

    @POST("mobile/v1/freelance/projects/{uuid}/milestones/{milestone}/checklist/{item}/{state}")
    suspend fun tickChecklist(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("milestone") milestone: String, @Path("item") item: String, @Path("state") state: String): ApiEnvelope<List<FreelanceChecklistItemDto>>

    /** A file the server gave as a `path` (under mobile/v1/freelance/), downloaded with the sign-in. */
    @Streaming
    @GET
    suspend fun file(@Header("Authorization") auth: String, @Url url: String): Response<ResponseBody>

    /** FL-205: the invitations to a private project; who to invite; inviting; taking one back; opening the project to everyone. */
    @GET("mobile/v1/freelance/projects/{uuid}/invitations")
    suspend fun invitations(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<FreelanceInvitationsDto>

    @GET("mobile/v1/freelance/projects/{uuid}/invitees")
    suspend fun invitees(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Query("q") q: String?, @Query("all") all: Int?): ApiEnvelope<List<FreelanceInviteeDto>>

    @POST("mobile/v1/freelance/projects/{uuid}/invitations")
    suspend fun invite(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: FreelanceInviteRequest): ApiEnvelope<FreelanceInvitationsDto>

    @DELETE("mobile/v1/freelance/projects/{uuid}/invitations/{invitation}")
    suspend fun withdrawInvitation(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("invitation") invitation: String): ApiEnvelope<FreelanceInvitationsDto>

    @POST("mobile/v1/freelance/projects/{uuid}/make-public")
    suspend fun makePublic(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<FreelanceProjectDto>

    @POST("mobile/v1/freelance/projects/{uuid}/offers/close")
    suspend fun closeOffers(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<FreelanceProjectDto>

    @POST("mobile/v1/freelance/projects/{uuid}/offers/reopen")
    suspend fun reopenOffers(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<FreelanceProjectDto>

    @POST("mobile/v1/freelance/projects/{uuid}/cancel")
    suspend fun cancel(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: FreelanceCancelRequest): ApiEnvelope<FreelanceProjectDto>

    @Multipart
    @POST("mobile/v1/freelance/projects/{uuid}/attachments")
    suspend fun addAttachment(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Part file: MultipartBody.Part): ApiEnvelope<FreelanceProjectDto>

    @DELETE("mobile/v1/freelance/projects/{uuid}/attachments/{media}")
    suspend fun removeAttachment(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("media") media: Int): ApiEnvelope<FreelanceProjectDto>
}

data class FreelanceSpecialtyNodeDto(
    val id: Int,
    val name: String = "",
    val description: String? = null,
    val children: List<FreelanceSpecialtyNodeDto> = emptyList(),
)

data class FreelanceSkillDto(val id: Int, val name: String = "")

data class FreelanceBudgetRulesDto(
    @SerializedName("min_minor") val minMinor: Long? = null,
    @SerializedName("max_minor") val maxMinor: Long? = null,
    val currency: String = "",
)

data class FreelanceProjectDto(
    val uuid: String,
    val status: String = "draft",
    @SerializedName("status_label") val statusLabel: String = "",
    @SerializedName("status_reason") val statusReason: String? = null,
    val title: String? = null,
    val description: String? = null,
    /** FL-307: the same questions for every freelancer who offers. */
    val questions: List<String> = emptyList(),
    val specialty: FreelanceNamedDto? = null,
    val skills: List<FreelanceNamedDto> = emptyList(),
    val budget: FreelanceBudgetDto = FreelanceBudgetDto(),
    @SerializedName("duration_days") val durationDays: Int? = null,
    val visibility: String = "public",
    val attachments: List<FreelanceAttachmentDto> = emptyList(),
    val missing: List<String> = emptyList(),
    val can: FreelanceProjectCanDto = FreelanceProjectCanDto(),
    val offers: FreelanceOffersDto = FreelanceOffersDto(),
    /** FL-401: the agreement with the chosen freelancer. */
    val agreement: FreelanceAgreementRefDto? = null,
    /** FL-205: the numbers of a private project's invitations (null on a public one). */
    val invitations: FreelanceInvitationsSummaryDto? = null,
    val timeline: List<FreelanceProjectEventDto>? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("submitted_at") val submittedAt: String? = null,
)

/** The window in which a published project takes offers (FL-204). */
data class FreelanceOffersDto(
    val accepting: Boolean = false,
    @SerializedName("deadline_at") val deadlineAt: String? = null,
    @SerializedName("closed_at") val closedAt: String? = null,
    @SerializedName("close_reason") val closeReason: String? = null,
    @SerializedName("can_close") val canClose: Boolean = false,
    @SerializedName("can_reopen") val canReopen: Boolean = false,
    @SerializedName("reopens_left") val reopensLeft: Int = 0,
    /** How many offers the project has (not counting the ones taken back). */
    val count: Int = 0,
    /** The offer the client chose (FL-309). */
    val chosen: String? = null,
)

/** An offer on my project (FL-301). */
data class FreelanceOfferDto(
    val uuid: String,
    val status: String = "submitted",
    @SerializedName("price_minor") val priceMinor: Long = 0,
    val currency: String = "",
    @SerializedName("duration_days") val durationDays: Int = 0,
    val details: String = "",
    /** FL-307: each of the project's questions with this offer's answer. */
    val answers: List<FreelanceAnswerDto> = emptyList(),
    val attachments: List<FreelanceAttachmentDto> = emptyList(),
    val freelancer: FreelanceOfferFreelancerDto? = null,
    /** The freelancer changed it after sending (FL-302). */
    val edited: Boolean = false,
    @SerializedName("created_at") val createdAt: String? = null,
    val can: FreelanceOfferCanDto = FreelanceOfferCanDto(),
    /** What the client pays if they choose it: the price and the client fee (FL-309). */
    @SerializedName("client_cost") val clientCost: FreelanceClientCostDto? = null,
    /** The room about this offer (FL-308): null until the client opens it. */
    val chat: FreelanceOfferChatDto? = null,
)

data class FreelanceOfferChatDto(val uuid: String, val unread: Int = 0, @SerializedName("can_write") val canWrite: Boolean = false)

data class FreelanceChatRoomDto(
    val uuid: String,
    @SerializedName("can_write") val canWrite: Boolean = false,
    /** Before the agreement phone numbers, emails and links are not allowed. */
    val guarded: Boolean = false,
    val peer: FreelanceChatPeerDto = FreelanceChatPeerDto(),
    val project: FreelanceChatProjectDto? = null,
)

data class FreelanceChatPeerDto(val name: String? = null)

data class FreelanceChatProjectDto(val uuid: String? = null, val title: String? = null)

data class FreelanceChatMessageDto(
    val id: Int,
    val mine: Boolean? = null,
    val kind: String = "text",
    val body: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("read_at") val readAt: String? = null,
)

data class FreelanceChatSend(val body: String, @SerializedName("client_id") val clientId: String)

data class FreelanceAnswerDto(val question: String = "", val answer: String? = null)

data class FreelanceOfferCanDto(val chat: Boolean = false, val shortlist: Boolean = false, val unshortlist: Boolean = false, val reject: Boolean = false, val choose: Boolean = false)

data class FreelanceClientCostDto(
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("client_fee_minor") val clientFeeMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
    val currency: String = "",
)

data class FreelanceCompareDto(val offers: List<FreelanceOfferDto> = emptyList(), val best: FreelanceCompareBestDto = FreelanceCompareBestDto())

/** The offer that stands out in each row (its uuid). */
data class FreelanceCompareBestDto(val price: String? = null, val duration: String? = null, val completeness: String? = null, val skills: String? = null)

data class FreelanceDecisionRequest(val reason: String? = null)

data class FreelanceOfferFreelancerDto(
    val uuid: String? = null,
    val name: String? = null,
    val headline: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val verified: Boolean = false,
    /** 0–100 (FL-102). */
    val completeness: Int = 0,
    @SerializedName("years_of_experience") val yearsOfExperience: Int? = null,
    @SerializedName("portfolio_count") val portfolioCount: Int = 0,
    @SerializedName("matching_skills") val matchingSkills: List<FreelanceNamedDto> = emptyList(),
    @SerializedName("project_skills_count") val projectSkillsCount: Int = 0,
    /** The average once there are three ratings (FL-701), else null and the app says «new». */
    val rating: Double? = null,
    @SerializedName("reviews_count") val reviewsCount: Int? = null,
    /** FL-703: finished projects, how many on time and finished, and projects like this one. */
    @SerializedName("completed_projects") val completedProjects: Int? = null,
    @SerializedName("on_time_rate") val onTimeRate: Int? = null,
    @SerializedName("completion_rate") val completionRate: Int? = null,
    @SerializedName("similar_projects") val similarProjects: Int? = null,
    /** I keep this freelancer (FL-306). */
    @SerializedName("is_favorite") val isFavorite: Boolean = false,
)

data class FreelanceFavoriteDto(
    val uuid: String,
    val name: String? = null,
    val headline: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val verified: Boolean = false,
    val completeness: Int = 0,
)

/** The freelancer's profile as the client of one of their offers reads it (FL-305). */
data class FreelancerProfileDto(
    val uuid: String? = null,
    val name: String? = null,
    val headline: String? = null,
    val bio: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val verified: Boolean = false,
    val city: String? = null,
    val languages: List<String> = emptyList(),
    @SerializedName("member_since") val memberSince: String? = null,
    val completeness: Int = 0,
    @SerializedName("years_of_experience") val yearsOfExperience: Int? = null,
    val specialties: List<FreelanceNamedDto> = emptyList(),
    val skills: List<FreelanceNamedDto> = emptyList(),
    @SerializedName("matching_skills") val matchingSkills: List<FreelanceNamedDto> = emptyList(),
    val experiences: List<FreelanceExperienceDto> = emptyList(),
    val certificates: List<FreelanceCertificateDto> = emptyList(),
    val portfolio: List<FreelancePortfolioDto> = emptyList(),
    val rating: Double? = null,
    @SerializedName("reviews_count") val reviewsCount: Int? = null,
    @SerializedName("completed_projects") val completedProjects: Int? = null,
    @SerializedName("on_time_rate") val onTimeRate: Int? = null,
    /** FL-701: what clients said. */
    val reviews: List<FreelanceReviewLineDto> = emptyList(),
    @SerializedName("is_favorite") val isFavorite: Boolean = false,
)

data class FreelanceReviewLineDto(val stars: Double = 0.0, val comment: String? = null, val project: String? = null, val at: String? = null)

data class FreelanceExperienceDto(
    val title: String? = null,
    val company: String? = null,
    @SerializedName("started_on") val startedOn: String? = null,
    @SerializedName("ended_on") val endedOn: String? = null,
    @SerializedName("is_current") val isCurrent: Boolean = false,
    val description: String? = null,
)

data class FreelanceCertificateDto(val title: String? = null, val issuer: String? = null, @SerializedName("issued_on") val issuedOn: String? = null)

data class FreelancePortfolioDto(val uuid: String? = null, val title: String? = null, val description: String? = null, val images: List<FreelancePortfolioImageDto> = emptyList())

/** A signed link that expires (FL-105). */
data class FreelancePortfolioImageDto(val id: Int = 0, val url: String? = null)

data class FreelanceNamedDto(val id: Int, val name: String = "")

data class FreelanceBudgetDto(
    val type: String = "fixed",
    @SerializedName("min_minor") val minMinor: Long? = null,
    @SerializedName("max_minor") val maxMinor: Long? = null,
    val currency: String = "",
)

data class FreelanceAttachmentDto(val id: Int, val name: String = "", val mime: String? = null, val size: Long = 0, val path: String? = null)

data class FreelanceProjectCanDto(
    val edit: Boolean = false,
    val submit: Boolean = false,
    val withdraw: Boolean = false,
    val cancel: Boolean = false,
    val delete: Boolean = false,
)

data class FreelanceProjectEventDto(val type: String = "", val from: String? = null, val to: String? = null, val at: String? = null)

/** The whole form, sent as it is: an empty field is sent as null (a draft may be half done). */
data class FreelanceProjectRequest(
    val title: String?,
    val description: String?,
    @SerializedName("specialty_id") val specialtyId: Int?,
    @SerializedName("skill_ids") val skillIds: List<Int>,
    @SerializedName("budget_type") val budgetType: String,
    @SerializedName("budget_min_minor") val budgetMinMinor: Long?,
    @SerializedName("budget_max_minor") val budgetMaxMinor: Long?,
    @SerializedName("duration_days") val durationDays: Int?,
    val visibility: String,
    val questions: List<String>,
)

data class FreelanceAgreementRefDto(val uuid: String, val status: String = "draft")

data class FreelanceAgreementDto(
    val uuid: String,
    val status: String = "draft",
    val version: Int = 1,
    val scope: String? = null,
    val includes: String? = null,
    val excludes: String? = null,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    val currency: String = "",
    @SerializedName("duration_days") val durationDays: Int = 0,
    val money: FreelanceAgreementMoneyDto = FreelanceAgreementMoneyDto(),
    val terms: FreelanceAgreementTermsDto = FreelanceAgreementTermsDto(),
    @SerializedName("expires_at") val expiresAt: String? = null,
    val freelancer: FreelanceChatPeerDto? = null,
    val milestones: List<FreelanceMilestoneDto> = emptyList(),
    /** FL-501: what I can spend now (for funding a part). */
    @SerializedName("wallet_available_minor") val walletAvailableMinor: Long? = null,
    /** FL-404 / FL-405: the changes asked, newest first. */
    val changes: List<FreelanceChangeDto> = emptyList(),
    /** FL-701: rating each other once it is completed. */
    val review: FreelanceReviewDto? = null,
    @SerializedName("milestone_rules") val milestoneRules: FreelanceMilestoneRulesDto = FreelanceMilestoneRulesDto(),
    val can: FreelanceAgreementCanDto = FreelanceAgreementCanDto(),
)

data class FreelanceMilestoneDto(
    val uuid: String = "",
    val position: Int = 1,
    val title: String = "",
    val description: String? = null,
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("duration_days") val durationDays: Int? = null,
    @SerializedName("client_fee_minor") val clientFeeMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
    val status: String = "unfunded",
    @SerializedName("delivery_note") val deliveryNote: String? = null,
    @SerializedName("revisions_used") val revisionsUsed: Int = 0,
    /** FL-501: held for it in my wallet. */
    @SerializedName("held_minor") val heldMinor: Long = 0,
    /** FL-504: an unanswered delivery is approved and paid by itself then. */
    @SerializedName("auto_approve_at") val autoApproveAt: String? = null,
    /** FL-407: the free rounds of changes. */
    @SerializedName("revisions_left") val revisionsLeft: Int? = null,
    @SerializedName("revisions_max") val revisionsMax: Int? = null,
    /** FL-506: who asked to end it, and what went back to my wallet. */
    @SerializedName("cancel_requested_by") val cancelRequestedBy: String? = null,
    @SerializedName("refunded_minor") val refundedMinor: Long? = null,
    /** FL-704: its dispute, if any. */
    val dispute: FreelanceDisputeRefDto? = null,
    /** FL-601 / FL-604: how many versions were delivered, and what I check before approving. */
    @SerializedName("deliveries_count") val deliveriesCount: Int = 0,
    val checklist: List<FreelanceChecklistItemDto> = emptyList(),
    val can: FreelanceMilestoneCanDto = FreelanceMilestoneCanDto(),
)

data class FreelanceChecklistItemDto(val uuid: String = "", val text: String = "", val checked: Boolean = false)

data class FreelanceChecklistRequest(val items: List<String>)

data class FreelanceDeliveryDto(
    val uuid: String = "",
    val number: Int = 1,
    val note: String? = null,
    @SerializedName("delivered_at") val deliveredAt: String? = null,
    @SerializedName("revision_reason") val revisionReason: String? = null,
    val files: List<FreelanceDeliveryFileDto> = emptyList(),
)

data class FreelanceDeliveryFileDto(
    val id: Long = 0,
    val name: String = "",
    val mime: String = "",
    val size: Long = 0,
    @SerializedName("is_image") val isImage: Boolean = false,
    /** Under mobile/v1/freelance/. */
    val path: String = "",
)

data class FreelanceMilestoneCanDto(
    val fund: Boolean = false,
    val approve: Boolean = false,
    val revision: Boolean = false,
    @SerializedName("checklist_edit") val checklistEdit: Boolean = false,
    @SerializedName("checklist_tick") val checklistTick: Boolean = false,
    val cancel: Boolean = false,
    @SerializedName("cancel_accept") val cancelAccept: Boolean = false,
    @SerializedName("cancel_reject") val cancelReject: Boolean = false,
    val dispute: Boolean = false,
)

data class FreelanceMilestoneRulesDto(@SerializedName("max_count") val maxCount: Int = 5, @SerializedName("min_amount_minor") val minAmountMinor: Long? = null)

data class FreelanceMilestoneInput(val title: String, @SerializedName("amount_minor") val amountMinor: Long, @SerializedName("duration_days") val durationDays: Int? = null)

data class FreelanceMilestonesRequest(val milestones: List<FreelanceMilestoneInput>)

data class FreelanceAgreementMoneyDto(
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("client_fee_minor") val clientFeeMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
    /** FL-510: the total told before choosing (a split can change it). */
    @SerializedName("quoted_total_minor") val quotedTotalMinor: Long? = null,
)

data class FreelanceAgreementTermsDto(@SerializedName("review_days") val reviewDays: Int? = null, @SerializedName("free_revisions") val freeRevisions: Int? = null)

data class FreelanceAgreementCanDto(val edit: Boolean = false, val send: Boolean = false, val cancel: Boolean = false, val split: Boolean = false)

data class FreelanceAgreementRequest(val scope: String, val includes: String?, val excludes: String?)

data class FreelanceCancelRequest(val reason: String?)

data class FreelanceDisputeRefDto(val uuid: String, val status: String = "open")

data class FreelanceDisputeOpenRequest(val reason: String, val details: String)

/** `body` for evidence, `reason` for an appeal. */
data class FreelanceTextRequest(val body: String? = null, val reason: String? = null)

data class FreelanceDisputeDto(
    val uuid: String,
    val status: String = "open",
    val reason: String = "",
    @SerializedName("reason_label") val reasonLabel: String = "",
    val details: String = "",
    @SerializedName("opened_by") val openedBy: String = "",
    val mine: Boolean = false,
    @SerializedName("respond_by") val respondBy: String? = null,
    val milestone: FreelanceDisputePartDto? = null,
    val currency: String? = null,
    val decision: FreelanceDisputeDecisionDto? = null,
    val appeal: FreelanceDisputeAppealDto? = null,
    @SerializedName("resolved_at") val resolvedAt: String? = null,
    val evidence: List<FreelanceDisputeEvidenceDto> = emptyList(),
    val can: FreelanceDisputeCanDto = FreelanceDisputeCanDto(),
)

data class FreelanceDisputePartDto(val uuid: String = "", val title: String = "", @SerializedName("amount_minor") val amountMinor: Long = 0)

data class FreelanceDisputeDecisionDto(
    val type: String = "",
    @SerializedName("freelancer_bp") val freelancerBp: Int? = null,
    val note: String? = null,
    @SerializedName("executes_at") val executesAt: String? = null,
    val final: Boolean = false,
)

data class FreelanceDisputeAppealDto(val by: String = "", val reason: String? = null)

data class FreelanceDisputeEvidenceDto(val id: Int = 0, val side: String = "", val mine: Boolean = false, val body: String = "", val file: FreelanceAttachmentDto? = null, val at: String? = null)

data class FreelanceDisputeCanDto(val evidence: Boolean = false, val appeal: Boolean = false)

data class FreelanceChangeDto(
    val uuid: String,
    val type: String = "",
    val status: String = "pending",
    val mine: Boolean = false,
    val milestone: FreelanceDisputePartDto? = null,
    val payload: Map<String, Any?> = emptyMap(),
    val reason: String? = null,
    @SerializedName("respond_by") val respondBy: String? = null,
    val can: FreelanceChangeCanDto = FreelanceChangeCanDto(),
)

data class FreelanceChangeCanDto(val accept: Boolean = false, val reject: Boolean = false, val withdraw: Boolean = false)

data class FreelanceChangeRequest(
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

data class FreelanceReviewDto(
    val criteria: List<String> = emptyList(),
    @SerializedName("can_rate") val canRate: Boolean = false,
    @SerializedName("rate_until") val rateUntil: String? = null,
    val mine: FreelanceRatingDto? = null,
    val theirs: FreelanceRatingDto? = null,
    @SerializedName("theirs_waiting") val theirsWaiting: Boolean = false,
)

data class FreelanceRatingDto(val stars: Double = 0.0, val comment: String? = null, val scores: Map<String, Int> = emptyMap())

data class FreelanceReviewRequest(val scores: Map<String, Int>, val comment: String?)

/** FL-511: a receipt of one money movement of a part. `text`/`reason_text` come with the single receipt. */
data class FreelanceReceiptDto(
    val uuid: String = "",
    val number: String = "",
    val kind: String = "",
    val title: String? = null,
    val reason: String? = null,
    @SerializedName("reason_text") val reasonText: String? = null,
    val project: FreelanceRefDto? = null,
    val milestone: FreelanceRefDto? = null,
    val currency: String = "",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("fee_minor") val feeMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
    @SerializedName("refunded_minor") val refundedMinor: Long = 0,
    @SerializedName("issued_at") val issuedAt: String? = null,
    val text: String? = null,
)

data class FreelanceRefDto(val uuid: String? = null, val title: String? = null)

/** FL-406: one step of the record of approvals. */
data class FreelanceTrailDto(val id: Long = 0, val action: String = "", val label: String = "", val actor: String = "", val mine: Boolean = false, val at: String? = null)

/** FL-205: invitations to a private project. */
data class FreelanceInvitationsSummaryDto(
    val sent: Int = 0,
    val accepted: Int = 0,
    val declined: Int = 0,
    val max: Int = 10,
    @SerializedName("can_invite") val canInvite: Boolean = false,
    @SerializedName("can_make_public") val canMakePublic: Boolean = false,
)

data class FreelanceInvitationsDto(val summary: FreelanceInvitationsSummaryDto = FreelanceInvitationsSummaryDto(), val invitations: List<FreelanceInvitationDto> = emptyList())

data class FreelanceInvitationDto(
    val uuid: String = "",
    val status: String = "sent",
    val freelancer: FreelanceInviteeDto? = null,
    /** The uuid of their offer, once they sent one. */
    val offer: String? = null,
    @SerializedName("can_withdraw") val canWithdraw: Boolean = false,
)

data class FreelanceInviteeDto(
    val uuid: String = "",
    val name: String = "",
    val headline: String? = null,
    val specialties: List<FreelanceNamedDto> = emptyList(),
    val rating: Double? = null,
    @SerializedName("reviews_count") val reviewsCount: Int = 0,
    @SerializedName("completed_projects") val completedProjects: Int = 0,
)

data class FreelanceInviteRequest(val freelancers: List<String>)

/** FL-310: a short interview in a chat room. */
data class FreelanceInterviewDto(
    val uuid: String = "",
    /** proposed, confirmed, declined, cancelled or superseded. */
    val status: String = "proposed",
    @SerializedName("proposed_by") val proposedBy: String = "client",
    @SerializedName("starts_at") val startsAt: String = "",
    @SerializedName("duration_minutes") val durationMinutes: Int = 15,
    val note: String? = null,
)

data class FreelanceInterviewRequest(
    @SerializedName("starts_at") val startsAt: String,
    @SerializedName("duration_minutes") val durationMinutes: Int = 15,
    val note: String? = null,
)
