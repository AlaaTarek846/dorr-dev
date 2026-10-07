package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
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
)

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
