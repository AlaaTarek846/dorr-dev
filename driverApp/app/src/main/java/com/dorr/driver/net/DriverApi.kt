package com.dorr.driver.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path

/** `/api/driver/v1/…` — the driver's own API (guard `driver_api`, Modules/Driver + Modules/Rides). */
interface DriverApi {
    // ---------------------------------------------------------------- account
    @POST("driver/v1/auth/otp")
    suspend fun requestOtp(@Body body: PhoneBody): Response<ApiEnvelope<OtpSent>>

    @POST("driver/v1/auth/resend")
    suspend fun resendOtp(@Body body: PhoneBody): Response<ApiEnvelope<OtpSent>>

    @POST("driver/v1/auth/verify")
    suspend fun verify(@Body body: VerifyBody): Response<ApiEnvelope<SignedIn>>

    @GET("driver/v1/auth/me")
    suspend fun me(): Response<ApiEnvelope<DriverDto>>

    @POST("driver/v1/auth/logout")
    suspend fun logout(): Response<ApiEnvelope<Any>>

    @POST("driver/v1/notifications/devices")
    suspend fun registerDevice(@Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    // ---------------------------------------------------------------- signing up
    @GET("driver/v1/onboarding")
    suspend fun onboarding(): Response<ApiEnvelope<OnboardingDto>>

    @GET("driver/v1/onboarding/options")
    suspend fun onboardingOptions(): Response<ApiEnvelope<OnboardingOptionsDto>>

    @PUT("driver/v1/profile")
    suspend fun updateProfile(@Body body: JsonObject): Response<ApiEnvelope<OnboardingDto>>

    @POST("driver/v1/vehicles")
    suspend fun addVehicle(@Body body: JsonObject): Response<ApiEnvelope<OnboardingDto>>

    @PUT("driver/v1/vehicles/{id}")
    suspend fun updateVehicle(@Path("id") id: Int, @Body body: JsonObject): Response<ApiEnvelope<OnboardingDto>>

    @Multipart
    @POST("driver/v1/documents")
    suspend fun uploadDocument(@PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>, @Part front: MultipartBody.Part, @Part back: MultipartBody.Part?): Response<ApiEnvelope<OnboardingDto>>

    @POST("driver/v1/onboarding/submit")
    suspend fun submitOnboarding(): Response<ApiEnvelope<OnboardingDto>>

    @GET("driver/v1/regulator")
    suspend fun regulator(): Response<ApiEnvelope<RegulatorDto>>

    @POST("driver/v1/regulator/register")
    suspend fun registerRegulator(): Response<ApiEnvelope<RegulatorDto>>

    @POST("driver/v1/regulator/check")
    suspend fun checkRegulator(): Response<ApiEnvelope<RegulatorDto>>

    // ---------------------------------------------------------------- presence & location
    @GET("driver/v1/presence")
    suspend fun presence(): Response<ApiEnvelope<PresenceDto>>

    @POST("driver/v1/presence/online")
    suspend fun goOnline(@Body body: JsonObject): Response<ApiEnvelope<PresenceDto>>

    @POST("driver/v1/presence/offline")
    suspend fun goOffline(): Response<ApiEnvelope<PresenceDto>>

    @POST("driver/v1/presence/last-ride")
    suspend fun lastRide(@Body body: JsonObject): Response<ApiEnvelope<PresenceDto>>

    @POST("driver/v1/presence/gps")
    suspend fun gps(@Body body: JsonObject): Response<ApiEnvelope<PresenceDto>>

    @POST("driver/v1/locations/batch")
    suspend fun locations(@Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    // ---------------------------------------------------------------- requests & rides
    @GET("driver/v1/offers")
    suspend fun offers(): Response<ApiEnvelope<List<OfferDto>>>

    @POST("driver/v1/offers/{uuid}/accept")
    suspend fun accept(@Header("Idempotency-Key") key: String, @Path("uuid") uuid: String): Response<ApiEnvelope<TripDto>>

    /** Bidding: my price for the request (`{price_minor}`; equal to the rider's = accepting it). */
    @POST("driver/v1/offers/{uuid}/bid")
    suspend fun bid(@Header("Idempotency-Key") key: String, @Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @POST("driver/v1/offers/{uuid}/decline")
    suspend fun decline(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @GET("driver/v1/rides/current")
    suspend fun currentRide(): Response<ApiEnvelope<JsonElement>>

    // ---------------------------------------------------------------- safety (D-141, D-142)
    @POST("driver/v1/rides/{uuid}/share")
    suspend fun shareRide(@Path("uuid") uuid: String): Response<ApiEnvelope<ShareDto>>

    @POST("driver/v1/rides/{uuid}/sos")
    suspend fun sos(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<SosDto>>

    // ---------------------------------------------------------------- the Safety Center (D-140..160)
    @GET("driver/v1/safety")
    suspend fun safety(): Response<ApiEnvelope<DriverSafetyDto>>

    @POST("driver/v1/safety/contacts")
    suspend fun addContact(@Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @PUT("driver/v1/safety/contacts/{id}")
    suspend fun updateContact(@Path("id") id: Int, @Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @DELETE("driver/v1/safety/contacts/{id}")
    suspend fun deleteContact(@Path("id") id: Int): Response<ApiEnvelope<JsonElement>>

    @DELETE("driver/v1/safety/blocks/{id}")
    suspend fun unblock(@Path("id") id: Int): Response<ApiEnvelope<JsonElement>>

    @POST("driver/v1/rides/{uuid}/contacts/share")
    suspend fun shareWithContacts(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @POST("driver/v1/rides/{uuid}/block")
    suspend fun blockRider(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @POST("driver/v1/rides/{uuid}/report")
    suspend fun report(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @POST("driver/v1/rides/{uuid}/safety-checks/{check}")
    suspend fun answerCheck(@Path("uuid") uuid: String, @Path("check") check: String, @Body body: JsonObject): Response<ApiEnvelope<SosDto>>

    // ---------------------------------------------------------------- the rider's change of the trip (D-131..133)
    @POST("driver/v1/rides/{uuid}/changes/{change}/accept")
    suspend fun acceptChange(@Path("uuid") uuid: String, @Path("change") change: String): Response<ApiEnvelope<JsonElement>>

    @POST("driver/v1/rides/{uuid}/changes/{change}/reject")
    suspend fun rejectChange(@Path("uuid") uuid: String, @Path("change") change: String): Response<ApiEnvelope<JsonElement>>

    // ---------------------------------------------------------------- scheduled rides (D-260..269)
    @GET("driver/v1/scheduled")
    suspend fun scheduled(): Response<ApiEnvelope<List<ScheduledRideDto>>>

    @GET("driver/v1/scheduled/mine")
    suspend fun scheduledMine(): Response<ApiEnvelope<List<ScheduledRideDto>>>

    @POST("driver/v1/scheduled/{uuid}/reserve")
    suspend fun reserve(@Header("Idempotency-Key") key: String, @Path("uuid") uuid: String): Response<ApiEnvelope<ScheduledRideDto>>

    @POST("driver/v1/scheduled/{uuid}/release")
    suspend fun releaseReservation(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @POST("driver/v1/scheduled/{uuid}/start")
    suspend fun startScheduled(@Header("Idempotency-Key") key: String, @Path("uuid") uuid: String): Response<ApiEnvelope<TripDto>>

    @GET("driver/v1/rides/{uuid}")
    suspend fun ride(@Path("uuid") uuid: String): Response<ApiEnvelope<TripDto>>

    @POST("driver/v1/rides/{uuid}/arrive")
    suspend fun arrive(@Header("Idempotency-Key") key: String, @Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<TripDto>>

    @POST("driver/v1/rides/{uuid}/start")
    suspend fun start(@Header("Idempotency-Key") key: String, @Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<TripDto>>

    @POST("driver/v1/rides/{uuid}/stops/{seq}")
    suspend fun stop(@Path("uuid") uuid: String, @Path("seq") seq: Int, @Body body: JsonObject): Response<ApiEnvelope<TripDto>>

    @POST("driver/v1/rides/{uuid}/end")
    suspend fun end(@Header("Idempotency-Key") key: String, @Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<TripDto>>

    @POST("driver/v1/rides/{uuid}/collect-cash")
    suspend fun collectCash(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<TripDto>>

    @POST("driver/v1/rides/{uuid}/cancel")
    suspend fun cancel(@Header("Idempotency-Key") key: String, @Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @POST("driver/v1/rides/{uuid}/no-show")
    suspend fun noShow(@Header("Idempotency-Key") key: String, @Path("uuid") uuid: String): Response<ApiEnvelope<TripDto>>

    @POST("driver/v1/rides/{uuid}/rate")
    suspend fun rate(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @GET("driver/v1/rides/cancel-reasons")
    suspend fun cancelReasons(): Response<ApiEnvelope<List<ReasonDto>>>

    @GET("driver/v1/rides/rating-tags")
    suspend fun ratingTags(): Response<ApiEnvelope<List<TagDto>>>

    @GET("driver/v1/rides/{uuid}/messages")
    suspend fun messages(@Path("uuid") uuid: String): Response<ApiEnvelope<ChatDto>>

    @POST("driver/v1/rides/{uuid}/messages")
    suspend fun send(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<MessageDto>>

    @GET("driver/v1/earnings")
    suspend fun earnings(): Response<ApiEnvelope<EarningsDto>>
}

data class PhoneBody(
    @SerializedName("dial_code") val dialCode: String,
    val phone: String,
)

data class VerifyBody(
    @SerializedName("dial_code") val dialCode: String,
    val phone: String,
    val code: String,
)

data class OtpSent(
    @SerializedName("masked_phone") val maskedPhone: String? = null,
    @SerializedName("is_new_driver") val isNewDriver: Boolean = false,
    @SerializedName("resend_cooldown_seconds") val resendCooldownSeconds: Int = 60,
)

data class SignedIn(
    val driver: DriverDto,
    val token: String,
)

data class DriverDto(
    val uuid: String,
    val name: String? = null,
    val phone: String,
    val status: String? = null,
    @SerializedName("status_reason") val statusReason: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val residency: String? = null,
)
