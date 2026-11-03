package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * FL-L09, special offers on the client's side (`mobile/v1/freelance/special-offers`): what freelancers I finished work with offered me
 * (custom work, a service level at a lower price, a consultation at a discount), taking one — the work starts at once, a consultation with
 * the time I pick — saying no, and stopping a freelancer's special offers.
 */
interface FreelanceSpecialOffersApi {
    /** `tab` = open · accepted · past */
    @GET("mobile/v1/freelance/special-offers")
    suspend fun list(@Header("Authorization") auth: String, @Query("tab") tab: String? = null, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 15): ApiEnvelope<List<ClientSpecialOfferDto>>

    /** How many wait for me (the badge on the freelance home). */
    @GET("mobile/v1/freelance/special-offers/waiting")
    suspend fun waiting(@Header("Authorization") auth: String): ApiEnvelope<SpecialOfferWaitingDto>

    @GET("mobile/v1/freelance/special-offers/{uuid}")
    suspend fun show(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<ClientSpecialOfferDto>

    @POST("mobile/v1/freelance/special-offers/{uuid}/accept")
    suspend fun accept(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: SpecialOfferAcceptRequest): ApiEnvelope<ClientSpecialOfferDto>

    @POST("mobile/v1/freelance/special-offers/{uuid}/decline")
    suspend fun decline(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: SpecialOfferDeclineRequest): ApiEnvelope<ClientSpecialOfferDto>

    @PUT("mobile/v1/freelance/special-offers/freelancers/{uuid}/mute")
    suspend fun mute(@Header("Authorization") auth: String, @Path("uuid") freelancer: String, @Body body: SpecialOfferMuteRequest): ApiEnvelope<Any>
}

data class SpecialOfferWaitingDto(val count: Int = 0)

data class SpecialOfferAcceptRequest(
    @SerializedName("request_id") val requestId: String,
    val minutes: Int? = null,
    @SerializedName("starts_at") val startsAt: String? = null,
    val topic: String? = null,
    /** FL-L16: a consultation is recorded; accepting one needs the client's yes (null for the other kinds). */
    @SerializedName("recording_consent") val recordingConsent: Boolean? = null,
)

data class SpecialOfferDeclineRequest(val reason: String? = null, val mute: Boolean = false)

data class SpecialOfferMuteRequest(val on: Boolean)

data class ClientSpecialOfferDto(
    val uuid: String = "",
    /** custom · service · consultation */
    val kind: String = "custom",
    /** sent · accepted · declined · withdrawn · expired */
    val status: String = "sent",
    @SerializedName("status_label") val statusLabel: String? = null,
    val title: String? = null,
    val message: String? = null,
    val description: String? = null,
    val specialty: SpecialOfferSpecialtyDto? = null,
    val service: SpecialOfferServiceDto? = null,
    val consultation: SpecialOfferConsultationDto? = null,
    @SerializedName("price_minor") val priceMinor: Long? = null,
    @SerializedName("list_price_minor") val listPriceMinor: Long? = null,
    @SerializedName("saving_minor") val savingMinor: Long? = null,
    @SerializedName("discount_bp") val discountBp: Int? = null,
    @SerializedName("delivery_days") val deliveryDays: Int? = null,
    val revisions: Int? = null,
    val currency: String? = null,
    val decimals: Int = 2,
    val money: SpecialOfferClientMoneyDto? = null,
    @SerializedName("expires_at") val expiresAt: String? = null,
    @SerializedName("sent_at") val sentAt: String? = null,
    @SerializedName("answered_at") val answeredAt: String? = null,
    val freelancer: SpecialOfferFreelancerDto = SpecialOfferFreelancerDto(),
    val result: SpecialOfferResultDto? = null,
    val muted: Boolean = false,
    val can: SpecialOfferClientCanDto = SpecialOfferClientCanDto(),
)

data class SpecialOfferSpecialtyDto(val id: Int = 0, val name: String? = null)

data class SpecialOfferServiceDto(
    val uuid: String? = null,
    val title: String? = null,
    val level: String? = null,
    @SerializedName("level_label") val levelLabel: String? = null,
    @SerializedName("tier_name") val tierName: String? = null,
    val features: List<String> = emptyList(),
)

data class SpecialOfferConsultationDto(val uuid: String? = null, val title: String? = null, val lengths: List<SpecialOfferLengthDto> = emptyList())

/** One length of a consultation at the offered price (and the usual one), with the fee and the total. */
data class SpecialOfferLengthDto(
    val minutes: Int = 0,
    @SerializedName("list_price_minor") val listPriceMinor: Long = 0,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("client_fee_minor") val clientFeeMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
)

data class SpecialOfferClientMoneyDto(
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("client_fee_minor") val clientFeeMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
)

data class SpecialOfferFreelancerDto(
    val uuid: String? = null,
    val name: String? = null,
    val headline: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val verified: Boolean = false,
)

data class SpecialOfferResultDto(
    val project: String? = null,
    @SerializedName("service_order") val serviceOrder: String? = null,
    val consultation: String? = null,
)

data class SpecialOfferClientCanDto(val accept: Boolean = false, val decline: Boolean = false)
