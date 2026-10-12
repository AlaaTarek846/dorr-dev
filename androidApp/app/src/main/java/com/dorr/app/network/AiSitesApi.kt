package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.ResponseBody
import okhttp3.RequestBody
import retrofit2.http.DELETE
import retrofit2.http.Field
import retrofit2.http.FormUrlEncoded
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query
import retrofit2.http.Streaming

/**
 * The AI website builder under /api/user/v1/ai-sites - Modules/AI, AiSiteProjectController.
 * The brief is sent as multipart fields (nested ones as `contact[phone]`) because the logo
 * and photos travel in the same request. A purchase moves wallet money, so it carries
 * X-Wallet-Pin exactly like the AI subscription calls do.
 */
interface AiSitesApi {
    @GET("user/v1/ai-sites/offers")
    suspend fun offers(@Header("Authorization") auth: String): ApiEnvelope<AiSiteOffersDto>

    @GET("user/v1/ai-sites")
    suspend fun projects(@Header("Authorization") auth: String): ApiEnvelope<List<AiSiteProjectDto>>

    @GET("user/v1/ai-sites/{id}")
    suspend fun project(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiSiteProjectDto>

    /** Build from the plan's allowance. */
    @Multipart
    @POST("user/v1/ai-sites")
    suspend fun create(
        @Header("Authorization") auth: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part logo: MultipartBody.Part?,
        @Part images: List<MultipartBody.Part>,
    ): ApiEnvelope<AiSiteProjectDto>

    /** Build a standalone paid site (offer_id in [fields]). */
    @Multipart
    @POST("user/v1/ai-sites/purchase")
    suspend fun purchase(
        @Header("Authorization") auth: String,
        @Header("X-Wallet-Pin") pin: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part logo: MultipartBody.Part?,
        @Part images: List<MultipartBody.Part>,
    ): ApiEnvelope<AiSiteProjectDto>

    @FormUrlEncoded
    @POST("user/v1/ai-sites/{id}/edit")
    suspend fun edit(
        @Header("Authorization") auth: String,
        @Path("id") id: Int,
        @Field("instruction") instruction: String,
    ): ApiEnvelope<AiSiteProjectDto>

    @POST("user/v1/ai-sites/{id}/retry")
    suspend fun retry(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiSiteProjectDto>

    @POST("user/v1/ai-sites/{id}/versions/{number}/restore")
    suspend fun restore(
        @Header("Authorization") auth: String,
        @Path("id") id: Int,
        @Path("number") number: Int,
    ): ApiEnvelope<AiSiteProjectDto>

    // ---- Hosting (sub-domain, monthly/yearly). subscribe + renew move wallet money -> X-Wallet-Pin.

    @GET("user/v1/ai-sites/hosting/plans")
    suspend fun hostingPlans(@Header("Authorization") auth: String): ApiEnvelope<AiSiteHostingPlansDto>

    @GET("user/v1/ai-sites/hosting/check")
    suspend fun hostingCheck(@Header("Authorization") auth: String, @Query("name") name: String): ApiEnvelope<AiSiteNameCheckDto>

    @GET("user/v1/ai-sites/{id}/hosting")
    suspend fun hosting(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiSiteHostingShowDto>

    @FormUrlEncoded
    @POST("user/v1/ai-sites/{id}/hosting")
    suspend fun hostingSubscribe(
        @Header("Authorization") auth: String,
        @Header("X-Wallet-Pin") pin: String,
        @Path("id") id: Int,
        @Field("plan_id") planId: Int,
        @Field("subdomain") subdomain: String,
    ): ApiEnvelope<AiSiteHostingDto>

    /** What is left to edit this site with, and the edit packs on sale in this country. */
    @GET("user/v1/ai-sites/{id}/edit-packs")
    suspend fun editPacks(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiSiteEditPacksDto>

    /** Buy an edit pack for this site from the wallet (X-Wallet-Pin). */
    @FormUrlEncoded
    @POST("user/v1/ai-sites/{id}/edit-packs")
    suspend fun buyEditPack(
        @Header("Authorization") auth: String,
        @Header("X-Wallet-Pin") pin: String,
        @Path("id") id: Int,
        @Field("offer_id") offerId: Int,
    ): ApiEnvelope<AiSiteEditPacksDto>

    /** Whether the site's source ZIP can be downloaded, and what it costs. */
    @GET("user/v1/ai-sites/{id}/source-download")
    suspend fun sourceDownload(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiSiteSourceDownloadDto>

    /** Pay once from the wallet to unlock the ZIP download (X-Wallet-Pin). */
    @POST("user/v1/ai-sites/{id}/source-download")
    suspend fun buySourceDownload(
        @Header("Authorization") auth: String,
        @Header("X-Wallet-Pin") pin: String,
        @Path("id") id: Int,
    ): ApiEnvelope<AiSiteSourceDownloadDto>

    /** The site as a ZIP, streamed so a big site never sits in memory as one string. */
    @Streaming
    @GET("user/v1/ai-sites/{id}/download")
    suspend fun downloadSource(@Header("Authorization") auth: String, @Path("id") id: Int): ResponseBody

    // ------------------------------------------------------------------ human-assisted requests

    /** Whether the "build it with a person" option is on (the app hides it otherwise). */
    @GET("user/v1/ai-site-requests/info")
    suspend fun requestInfo(@Header("Authorization") auth: String): ApiEnvelope<AiSiteRequestInfoDto>

    @GET("user/v1/ai-site-requests")
    suspend fun requests(@Header("Authorization") auth: String): ApiEnvelope<List<AiSiteRequestDto>>

    @GET("user/v1/ai-site-requests/{id}")
    suspend fun request(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiSiteRequestDto>

    @Multipart
    @POST("user/v1/ai-site-requests")
    suspend fun createRequest(
        @Header("Authorization") auth: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part files: List<MultipartBody.Part>,
    ): ApiEnvelope<AiSiteRequestDto>

    @POST("user/v1/ai-site-requests/{id}/cancel")
    suspend fun cancelRequest(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiSiteRequestDto>

    @FormUrlEncoded
    @POST("user/v1/ai-site-requests/{id}/decline")
    suspend fun declineQuote(@Header("Authorization") auth: String, @Path("id") id: Int, @Field("reason") reason: String): ApiEnvelope<AiSiteRequestDto>

    /** Accepting takes the first payment from the wallet, so it carries the PIN. */
    @POST("user/v1/ai-site-requests/{id}/accept")
    suspend fun acceptQuote(@Header("Authorization") auth: String, @Header("X-Wallet-Pin") pin: String, @Path("id") id: Int): ApiEnvelope<AiSiteRequestDto>

    @POST("user/v1/ai-site-requests/{id}/installments/{installment}/pay")
    suspend fun payInstallment(
        @Header("Authorization") auth: String,
        @Header("X-Wallet-Pin") pin: String,
        @Path("id") id: Int,
        @Path("installment") installment: Int,
    ): ApiEnvelope<AiSiteRequestDto>

    @GET("user/v1/ai-site-requests/{id}/messages")
    suspend fun requestMessages(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<List<AiRequestMessageDto>>

    @FormUrlEncoded
    @POST("user/v1/ai-site-requests/{id}/messages")
    suspend fun sendRequestMessage(@Header("Authorization") auth: String, @Path("id") id: Int, @Field("body") body: String): ApiEnvelope<List<AiRequestMessageDto>>

    @POST("user/v1/ai-sites/{id}/hosting/publish")
    suspend fun hostingPublish(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiSiteHostingDto>

    @FormUrlEncoded
    @PUT("user/v1/ai-sites/{id}/hosting/auto-renew")
    suspend fun hostingAutoRenew(
        @Header("Authorization") auth: String,
        @Path("id") id: Int,
        @Field("auto_renew") autoRenew: Int,
    ): ApiEnvelope<AiSiteHostingDto>

    @POST("user/v1/ai-sites/{id}/hosting/renew")
    suspend fun hostingRenew(
        @Header("Authorization") auth: String,
        @Header("X-Wallet-Pin") pin: String,
        @Path("id") id: Int,
    ): ApiEnvelope<AiSiteHostingDto>

    @DELETE("user/v1/ai-sites/{id}")
    suspend fun delete(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<Any?>
}

data class AiSiteOffersDto(
    val enabled: Boolean = true,
    val plan: AiSitePlanDto? = null,
    val offers: List<AiSiteOfferDto> = emptyList(),
)

data class AiSitePlanDto(
    val included: Boolean = false,
    @SerializedName("can_create") val canCreate: Boolean = false,
    val refusal: String? = null,
    @SerializedName("daily_used") val dailyUsed: Int = 0,
    @SerializedName("daily_limit") val dailyLimit: Int = 0,
    @SerializedName("projects_limit") val projectsLimit: Int = 0,
)

data class AiSiteOfferDto(
    val id: Int,
    val name: String,
    val description: String? = null,
    @SerializedName("generations_included") val generationsIncluded: Int = 0,
    val price: Double? = null,
    val currency: String? = null,
    val available: Boolean = false,
)

data class AiSiteRequestInfoDto(val enabled: Boolean? = false, val intro: String? = null)

data class AiRequestStatusDto(
    val code: String? = null,
    val name: String? = null,
    val color: String? = null,
    @SerializedName("final") val isFinal: Boolean? = false,
)

data class AiRequestQuoteDto(
    val amount: Double? = null,
    @SerializedName("duration_days") val durationDays: Int? = null,
    @SerializedName("valid_until") val validUntil: String? = null,
    val expired: Boolean? = false,
    val notes: String? = null,
    @SerializedName("can_accept") val canAccept: Boolean? = false,
)

data class AiRequestTimelineDto(
    @SerializedName("accepted_at") val acceptedAt: String? = null,
    @SerializedName("due_at") val dueAt: String? = null,
    @SerializedName("remaining_seconds") val remainingSeconds: Long? = null,
    val overdue: Boolean? = false,
    @SerializedName("delivered_at") val deliveredAt: String? = null,
    @SerializedName("completed_at") val completedAt: String? = null,
)

data class AiRequestPaymentsDto(val total: Double? = 0.0, val paid: Double? = 0.0, val remaining: Double? = 0.0)

data class AiRequestMilestoneDto(
    val id: Int = 0,
    val title: String? = null,
    val description: String? = null,
    @SerializedName("due_at") val dueAt: String? = null,
    val status: String? = null,
)

data class AiRequestInstallmentDto(
    val id: Int = 0,
    val title: String? = null,
    val amount: Double? = 0.0,
    val trigger: String? = null,
    val status: String? = null,
    @SerializedName("paid_at") val paidAt: String? = null,
    @SerializedName("can_pay") val canPay: Boolean? = false,
)

data class AiRequestDeliveryDto(val url: String? = null, val notes: String? = null)

data class AiSiteRequestDto(
    val id: Int = 0,
    val reference: String? = null,
    val title: String? = null,
    val status: AiRequestStatusDto? = null,
    val currency: String? = null,
    val quote: AiRequestQuoteDto? = null,
    val timeline: AiRequestTimelineDto? = null,
    val payments: AiRequestPaymentsDto? = null,
    val unread: Int? = 0,
    @SerializedName("decline_reason") val declineReason: String? = null,
    val milestones: List<AiRequestMilestoneDto>? = null,
    val installments: List<AiRequestInstallmentDto>? = null,
    val delivery: AiRequestDeliveryDto? = null,
) {
    /** Gson skips Kotlin defaults, so these lists can be null at runtime. */
    val milestoneList: List<AiRequestMilestoneDto> get() = milestones ?: emptyList()
    val installmentList: List<AiRequestInstallmentDto> get() = installments ?: emptyList()
    val code: String get() = status?.code.orEmpty()
}

data class AiRequestMessageDto(
    val id: Int = 0,
    val sender: String? = null,
    val body: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class AiSiteSourceDownloadDto(
    val enabled: Boolean = false,
    val free: Boolean = true,
    val unlocked: Boolean = false,
    @SerializedName("can_download") val canDownload: Boolean = false,
    val price: Double? = null,
    val currency: String? = null,
    val purchasable: Boolean = false,
)

data class AiSiteEditsDto(
    val base: Int = 0,
    val packs: Int = 0,
    val total: Int = 0,
    val blocked: Boolean = false,
    val reason: String? = null,
)

data class AiSiteEditPacksDto(
    val edits: AiSiteEditsDto? = null,
    val packs: List<AiSiteOfferDto>? = null,
)

data class AiSiteVersionDto(
    val id: Int,
    val number: Int,
    val kind: String,
    val status: String,
    val instruction: String? = null,
    @SerializedName("error_message") val errorMessage: String? = null,
    @SerializedName("is_current") val isCurrent: Boolean = false,
)

data class AiSiteProjectDto(
    val id: Int,
    val title: String,
    val status: String,
    @SerializedName("access_type") val accessType: String,
    @SerializedName("preview_url") val previewUrl: String? = null,
    @SerializedName("is_disabled") val isDisabled: Boolean = false,
    @SerializedName("last_error") val lastError: String? = null,
    @SerializedName("last_error_message") val lastErrorMessage: String? = null,
    @SerializedName("generations_left") val generationsLeft: Int? = null,
    val versions: List<AiSiteVersionDto> = emptyList(),
) {
    /** Gson bypasses Kotlin defaults: the list endpoint omits `versions`, so the field can be null at runtime. */
    @Suppress("USELESS_CAST", "UNCHECKED_CAST")
    val versionList: List<AiSiteVersionDto> get() = (versions as List<AiSiteVersionDto>?).orEmpty()
}

data class AiSiteHostingPlansDto(
    val enabled: Boolean = true,
    /** "subdomain" (name.domain) or "path" (/sites/name on the app host). */
    val mode: String = "path",
    val domain: String? = null,
    val plans: List<AiSiteHostingPlanDto> = emptyList(),
)

data class AiSiteHostingPlanDto(
    val id: Int,
    val name: String,
    val period: String,
    val description: String? = null,
    val price: Double? = null,
    val currency: String? = null,
    val available: Boolean = false,
)

data class AiSiteNameCheckDto(
    val name: String = "",
    val available: Boolean = false,
    val reason: String? = null,
    val message: String? = null,
)

data class AiSiteHostingShowDto(val hosting: AiSiteHostingDto? = null)

data class AiSiteHostingDto(
    val id: Int,
    val subdomain: String,
    val url: String,
    val status: String,
    @SerializedName("is_live") val isLive: Boolean = false,
    val period: String = "monthly",
    val amount: Double = 0.0,
    val currency: String = "",
    @SerializedName("auto_renew") val autoRenew: Boolean = true,
    @SerializedName("ends_at") val endsAt: String? = null,
    @SerializedName("has_unpublished_changes") val hasUnpublishedChanges: Boolean = false,
)
