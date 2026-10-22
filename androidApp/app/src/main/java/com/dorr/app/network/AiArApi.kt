package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/** DORR AR under /api/user/v1/ai-ar - Modules/AI, AiArController. */
interface AiArApi {
    @GET("user/v1/ai-ar/info")
    suspend fun info(@Header("Authorization") auth: String): ApiEnvelope<AiArInfoDto>

    @GET("user/v1/ai-ar/assets")
    suspend fun assets(@Header("Authorization") auth: String): ApiEnvelope<List<AiArAssetDto>>

    /** Includes a signed download_url that expires in about 10 minutes - ask for it right before opening the model. */
    @GET("user/v1/ai-ar/assets/{id}")
    suspend fun asset(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiArAssetDto>

    @GET("user/v1/ai-ar/experiences")
    suspend fun experiences(@Header("Authorization") auth: String): ApiEnvelope<List<AiArExperienceDto>>

    @POST("user/v1/ai-ar/experiences")
    suspend fun createExperience(@Header("Authorization") auth: String, @Body body: AiArCreateExperienceRequest): ApiEnvelope<AiArExperienceDto>

    @GET("user/v1/ai-ar/experiences/{id}/reviews")
    suspend fun reviews(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<List<AiArReviewDto>>

    @GET("user/v1/ai-ar/providers/search")
    suspend fun searchProviders(@Header("Authorization") auth: String, @Query("q") query: String): ApiEnvelope<List<AiArProviderDto>>

    @POST("user/v1/ai-ar/experiences/{id}/submit")
    suspend fun submit(@Header("Authorization") auth: String, @Path("id") id: Int, @Body body: AiArSubmitRequest): ApiEnvelope<AiArReviewDto>

    @POST("user/v1/ai-ar/experiences/{id}/reviews/{reviewId}/withdraw")
    suspend fun withdraw(@Header("Authorization") auth: String, @Path("id") id: Int, @Path("reviewId") reviewId: Int): ApiEnvelope<AiArReviewDto>

    /** Moves wallet money, so it carries X-Wallet-Pin like every other payment call. */
    @POST("user/v1/ai-ar/experiences/{id}/accept-and-pay")
    suspend fun acceptAndPay(@Header("Authorization") auth: String, @Header("X-Wallet-Pin") pin: String, @Path("id") id: Int): ApiEnvelope<AiArOrderDto>

    /** The latest order of a design, or null data when it was never paid. */
    @GET("user/v1/ai-ar/experiences/{id}/order")
    suspend fun order(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiArOrderDto?>

    @POST("user/v1/ai-ar/orders/{id}/cancel")
    suspend fun cancelOrder(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiArOrderDto>

    @POST("user/v1/ai-ar/orders/{id}/confirm")
    suspend fun confirmOrder(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiArOrderDto>

    @POST("user/v1/ai-ar/orders/{id}/dispute")
    suspend fun disputeOrder(@Header("Authorization") auth: String, @Path("id") id: Int, @Body body: AiArDisputeRequest): ApiEnvelope<AiArOrderDto>

    @POST("user/v1/ai-ar/experiences/{id}/accept")
    suspend fun accept(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiArExperienceDto>
}

data class AiArExperienceDto(
    val id: Int,
    val type: String = "free",
    val title: String? = null,
    /** draft | active | submitted | approved | accepted */
    val status: String = "draft",
    val notice: String? = null,
)

data class AiArCreateExperienceRequest(val type: String, val title: String)

data class AiArProviderDto(val id: Int, val name: String? = null)

data class AiArSubmitRequest(
    @SerializedName("provider_id") val providerId: Int,
    val note: String? = null,
)

data class AiArReviewDto(
    val id: Int,
    /** pending | approved | changes_requested | rejected | withdrawn */
    val status: String = "pending",
    @SerializedName("provider_id") val providerId: Int = 0,
    @SerializedName("customer_note") val customerNote: String? = null,
    @SerializedName("provider_note") val providerNote: String? = null,
    /** Minor units of [currency] (1000 = 10.00). */
    @SerializedName("quote_minor") val quoteMinor: Long? = null,
    val currency: String? = null,
)

data class AiArInfoDto(
    val enabled: Boolean = false,
    @SerializedName("measurement_notice") val measurementNotice: String? = null,
)

data class AiArDimensionsDto(val width: Double = 0.0, val height: Double = 0.0, val depth: Double = 0.0)

data class AiArAssetDto(
    val id: Int,
    val name: String? = null,
    val category: String? = null,
    val license: String? = null,
    val attribution: String? = null,
    val dimensions: AiArDimensionsDto? = null,
    @SerializedName("download_url") val downloadUrl: String? = null,
)

data class AiArDisputeRequest(val reason: String)

data class AiArOrderDto(
    val id: Int,
    @SerializedName("experience_id") val experienceId: Int = 0,
    /** paid | delivered | disputed | released | refunded */
    val status: String = "paid",
    val currency: String? = null,
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("delivery_note") val deliveryNote: String? = null,
    @SerializedName("dispute_reason") val disputeReason: String? = null,
    @SerializedName("resolution_note") val resolutionNote: String? = null,
    @SerializedName("release_at") val releaseAt: String? = null,
)
