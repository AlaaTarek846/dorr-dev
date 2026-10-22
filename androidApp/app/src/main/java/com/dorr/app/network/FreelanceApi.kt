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

    /** A file the server gave as a `path` (under mobile/v1/freelance/), downloaded with the sign-in. */
    @Streaming
    @GET
    suspend fun file(@Header("Authorization") auth: String, @Url url: String): Response<ResponseBody>

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
    val specialty: FreelanceNamedDto? = null,
    val skills: List<FreelanceNamedDto> = emptyList(),
    val budget: FreelanceBudgetDto = FreelanceBudgetDto(),
    @SerializedName("duration_days") val durationDays: Int? = null,
    val visibility: String = "public",
    val attachments: List<FreelanceAttachmentDto> = emptyList(),
    val missing: List<String> = emptyList(),
    val can: FreelanceProjectCanDto = FreelanceProjectCanDto(),
    val offers: FreelanceOffersDto = FreelanceOffersDto(),
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
    val attachments: List<FreelanceAttachmentDto> = emptyList(),
    val freelancer: FreelanceOfferFreelancerDto? = null,
    /** The freelancer changed it after sending (FL-302). */
    val edited: Boolean = false,
    @SerializedName("created_at") val createdAt: String? = null,
    val can: FreelanceOfferCanDto = FreelanceOfferCanDto(),
    /** What the client pays if they choose it: the price and the client fee (FL-309). */
    @SerializedName("client_cost") val clientCost: FreelanceClientCostDto? = null,
)

data class FreelanceOfferCanDto(val shortlist: Boolean = false, val reject: Boolean = false, val choose: Boolean = false)

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
    /** Null until ratings exist (FL-701): the app says «new». */
    val rating: Double? = null,
    @SerializedName("reviews_count") val reviewsCount: Int? = null,
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
)

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
)

data class FreelanceCancelRequest(val reason: String?)
