package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.PUT
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Offers (FL-301), under `/api/freelance/v1`: whether I may offer on a project (and if not, why), my monthly quota and what a price
 * leaves me after the commission, sending the offer with its files, my offer on a project, and all my offers.
 */
interface OffersApi {
    @GET("${ApiClient.FREELANCE}projects/{uuid}/offer-preview")
    suspend fun preview(@Path("uuid") uuid: String, @Query("price_minor") priceMinor: Long? = null): Response<ApiEnvelope<OfferPreviewDto>>

    /** FL-304: what to fix (error) or think about (warning) before sending. */
    @POST("${ApiClient.FREELANCE}projects/{uuid}/offer-check")
    suspend fun check(@Path("uuid") uuid: String, @Body body: OfferCheckRequest): Response<ApiEnvelope<OfferChecksDto>>

    @GET("${ApiClient.FREELANCE}offer-templates")
    suspend fun templates(): Response<ApiEnvelope<List<OfferTemplateDto>>>

    @POST("${ApiClient.FREELANCE}offer-templates")
    suspend fun saveTemplate(@Body body: OfferTemplateRequest): Response<ApiEnvelope<OfferTemplateDto>>

    @retrofit2.http.DELETE("${ApiClient.FREELANCE}offer-templates/{id}")
    suspend fun deleteTemplate(@Path("id") id: Int): Response<ApiEnvelope<Map<String, Boolean>>>

    @GET("${ApiClient.FREELANCE}projects/{uuid}/my-offer")
    suspend fun mine(@Path("uuid") uuid: String): Response<ApiEnvelope<MyOfferDto>>

    /** Multipart: `price_minor`, `duration_days`, `details` as [fields]; up to 5 `files[]`. */
    @Multipart
    @POST("${ApiClient.FREELANCE}projects/{uuid}/offers")
    suspend fun send(@Path("uuid") uuid: String, @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>, @Part files: List<MultipartBody.Part>): Response<ApiEnvelope<OfferDto>>

    /** Changes an offer still open: only what is sent changes. */
    @PUT("${ApiClient.FREELANCE}offers/{uuid}")
    suspend fun update(@Path("uuid") uuid: String, @Body body: OfferUpdateRequest): Response<ApiEnvelope<OfferDto>>

    @POST("${ApiClient.FREELANCE}offers/{uuid}/withdraw")
    suspend fun withdraw(@Path("uuid") uuid: String): Response<ApiEnvelope<OfferDto>>

    /** What the offer said before each change, oldest first. */
    @GET("${ApiClient.FREELANCE}offers/{uuid}/revisions")
    suspend fun revisions(@Path("uuid") uuid: String): Response<ApiEnvelope<List<OfferRevisionDto>>>

    @GET("${ApiClient.FREELANCE}offers")
    suspend fun list(@Query("page") page: Int = 1, @Query("per_page") perPage: Int = 15): Response<ApiEnvelope<List<OfferDto>>>
}

data class OfferPreviewDto(
    /** Null when I may offer; else the reason (`code` + a message to show). */
    val blocked: OfferBlockedDto? = null,
    val quota: OfferQuotaDto = OfferQuotaDto(),
    @SerializedName("min_price_minor") val minPriceMinor: Long? = null,
    val breakdown: OfferBreakdownDto? = null,
)

data class OfferBlockedDto(val code: String = "", val message: String = "")

data class OfferQuotaDto(val enabled: Boolean = false, val limit: Int? = null, val used: Int = 0, val left: Int? = null)

data class OfferBreakdownDto(
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("commission_minor") val commissionMinor: Long = 0,
    @SerializedName("net_minor") val netMinor: Long = 0,
    val currency: String = "",
)

data class OfferCheckRequest(
    @SerializedName("price_minor") val priceMinor: Long?,
    @SerializedName("duration_days") val durationDays: Int?,
    val details: String,
    val files: Int,
)

data class OfferChecksDto(val checks: List<OfferCheckDto> = emptyList())

/** level: `error` (the server will refuse it) or `warning` (advice). */
data class OfferCheckDto(val code: String = "", val level: String = "warning", val message: String = "")

data class OfferTemplateDto(val id: Int, val title: String = "", val details: String = "")

data class OfferTemplateRequest(val title: String, val details: String)

data class MyOfferDto(val offer: OfferDto? = null)

data class OfferDto(
    val uuid: String,
    val status: String = "submitted",
    @SerializedName("price_minor") val priceMinor: Long = 0,
    val currency: String = "",
    @SerializedName("duration_days") val durationDays: Int = 0,
    val details: String = "",
    val answers: List<OfferAnswerDto> = emptyList(),
    @SerializedName("commission_minor") val commissionMinor: Long = 0,
    @SerializedName("net_minor") val netMinor: Long = 0,
    val attachments: List<OfferFileDto> = emptyList(),
    val project: OfferProjectDto? = null,
    val can: OfferCanDto = OfferCanDto(),
    val revisions: Int = 0,
    /** Why the client turned it down, when they said. */
    @SerializedName("status_reason") val statusReason: String? = null,
    val timeline: List<OfferEventDto> = emptyList(),
    /** The client's room about this offer (FL-308): null until they open it. */
    val chat: OfferChatDto? = null,
    /** FL-401: the agreement the client sent on it. */
    val agreement: OfferAgreementDto? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class OfferAnswerDto(val question: String = "", val answer: String? = null)

data class OfferEventDto(val type: String = "", val to: String? = null, val at: String? = null)

data class OfferCanDto(val edit: Boolean = false, val withdraw: Boolean = false)

data class OfferUpdateRequest(
    @SerializedName("price_minor") val priceMinor: Long,
    @SerializedName("duration_days") val durationDays: Int,
    val details: String,
)

data class OfferRevisionDto(
    val number: Int = 0,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("duration_days") val durationDays: Int = 0,
    val details: String = "",
    @SerializedName("net_minor") val netMinor: Long = 0,
    val at: String? = null,
)

data class OfferFileDto(val id: Int, val name: String = "", val path: String = "")

data class OfferProjectDto(val uuid: String, val title: String? = null)
