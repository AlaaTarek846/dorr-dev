package com.dorr.driver.net

import retrofit2.http.PATCH
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
import retrofit2.http.HTTP
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query

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

    /** The countries open for sign-in (flag, dial code, phone length / first digits), the same list as the DORR app's. */
    @GET("general/v1/countries/dropdown")
    suspend fun countries(): Response<ApiEnvelope<List<CountryDto>>>

    /** The caller's country by IP (the default one when it can't be told), in the same shape. */
    @GET("general/v1/countries/detect")
    suspend fun detectCountry(): Response<ApiEnvelope<CountryDto>>

    /** The platform's colours, the same ones the DORR app reads (public — also before signing in). */
    @GET("general/v1/mobile-appearance-defaults")
    suspend fun appearanceDefaults(): Response<ApiEnvelope<AppearanceDefaultsDto>>

    @POST("driver/v1/notifications/devices")
    suspend fun registerDevice(@Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @HTTP(method = "DELETE", path = "driver/v1/notifications/devices", hasBody = true)
    suspend fun unregisterDevice(@Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    // ---------------------------------------------------------------- real-time + the notification list
    @GET("driver/v1/realtime-config")
    suspend fun realtimeConfig(): Response<ApiEnvelope<RealtimeConfigDto>>

    @GET("driver/v1/notifications")
    suspend fun notifications(@Query("page") page: Int = 1, @Query("per_page") perPage: Int = 30): Response<ApiEnvelope<List<NotificationDto>>>

    @GET("driver/v1/notifications/unread-count")
    suspend fun unreadCount(): Response<ApiEnvelope<UnreadCountDto>>

    @POST("driver/v1/notifications/{id}/read")
    suspend fun markRead(@Path("id") id: String): Response<ApiEnvelope<UnreadCountDto>>

    @POST("driver/v1/notifications/read-all")
    suspend fun markAllRead(): Response<ApiEnvelope<UnreadCountDto>>

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

    /** O-64: `{on}` — no vehicle (the vehicle step and its documents are skipped). */
    @POST("driver/v1/onboarding/no-vehicle")
    suspend fun noVehicle(@Body body: JsonObject): Response<ApiEnvelope<OnboardingDto>>

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

    @POST("driver/v1/presence/pause")
    suspend fun pause(@Body body: JsonObject): Response<ApiEnvelope<PresenceDto>>

    @POST("driver/v1/presence/last-ride")
    suspend fun lastRide(@Body body: JsonObject): Response<ApiEnvelope<PresenceDto>>

    // The server answers an empty list here, not a presence — read it as plain JSON.
    @POST("driver/v1/presence/gps")
    suspend fun gps(@Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

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

    // ---------------------------------------------------------------- the captain's wallet (same wallet as the rider's)
    @GET("driver/v1/wallet")
    suspend fun wallet(): Response<ApiEnvelope<WalletDto>>

    @GET("driver/v1/wallet/transactions")
    suspend fun walletTransactions(
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 25,
        @Query("type") type: String? = null,
        @Query("direction") direction: String? = null,
        @Query("from") from: String? = null,
        @Query("to") to: String? = null,
    ): Response<ApiEnvelope<List<WalletRowDto>>>

    @GET("driver/v1/wallet/transactions/{uuid}")
    suspend fun walletTransaction(@Path("uuid") uuid: String): Response<ApiEnvelope<WalletDetailDto>>

    @GET("driver/v1/wallet/pin")
    suspend fun walletPin(): Response<ApiEnvelope<WalletPinDto>>

    @POST("driver/v1/wallet/pin")
    suspend fun createWalletPin(@Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @POST("driver/v1/wallet/pin/verify")
    suspend fun verifyWalletPin(@Header("X-Wallet-Pin") pin: String): Response<ApiEnvelope<JsonElement>>

    // Top-up with a card or a gateway — paid on the gateway's page; the result arrives as a real-time event.
    @GET("driver/v1/wallet/payment-methods")
    suspend fun paymentMethods(): Response<ApiEnvelope<List<PaymentMethodDto>>>

    @POST("driver/v1/wallet/topups/quote")
    suspend fun topupQuote(@Body body: JsonObject): Response<ApiEnvelope<TopupQuoteDto>>

    @POST("driver/v1/wallet/topups")
    suspend fun topup(@Header("X-Wallet-Pin") pin: String, @Header("Idempotency-Key") key: String, @Body body: JsonObject): Response<ApiEnvelope<TopupDto>>

    @GET("driver/v1/wallet/topups/{uuid}")
    suspend fun topupStatus(@Path("uuid") uuid: String): Response<ApiEnvelope<TopupDto>>

    @POST("driver/v1/wallet/topups/{uuid}/confirm")
    suspend fun confirmTopup(@Header("X-Wallet-Pin") pin: String, @Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<TopupDto>>

    @GET("driver/v1/wallet/withdrawal-methods")
    suspend fun withdrawalMethods(): Response<ApiEnvelope<List<WithdrawalMethodDto>>>

    @POST("driver/v1/wallet/withdrawal-methods")
    suspend fun addWithdrawalMethod(@Header("X-Wallet-Pin") pin: String, @Body body: JsonObject): Response<ApiEnvelope<WithdrawalMethodDto>>

    @DELETE("driver/v1/wallet/withdrawal-methods/{id}")
    suspend fun deleteWithdrawalMethod(@Path("id") id: Int): Response<ApiEnvelope<JsonElement>>

    @GET("driver/v1/wallet/withdrawals")
    suspend fun withdrawals(@Query("page") page: Int = 1, @Query("per_page") perPage: Int = 25): Response<ApiEnvelope<List<WithdrawalDto>>>

    @POST("driver/v1/wallet/withdrawals")
    suspend fun withdraw(@Header("X-Wallet-Pin") pin: String, @Header("Idempotency-Key") key: String, @Body body: JsonObject): Response<ApiEnvelope<WithdrawalDto>>

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

    // ---------------------------------------------------------------- airport queue (D-276..D-283)
    @GET("driver/v1/airport-queue")
    suspend fun airportQueue(): Response<ApiEnvelope<AirportQueueDto>>

    @POST("driver/v1/airport-queue/join")
    suspend fun joinAirportQueue(): Response<ApiEnvelope<AirportQueueDto>>

    @POST("driver/v1/airport-queue/leave")
    suspend fun leaveAirportQueue(): Response<ApiEnvelope<AirportQueueDto>>

    @GET("driver/v1/restricted-zones")
    suspend fun restrictedZones(): Response<ApiEnvelope<List<RestrictedZoneDto>>>

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

    /** O-03: which map to draw — Google, or Mapbox with its public token and style. */
    @GET("driver/v1/map-config")
    suspend fun mapConfig(): Response<ApiEnvelope<MapConfigDto>>

    // O-30: With Me — where I'm going, how far off my road, the riders in the car.
    @GET("driver/v1/pool")
    suspend fun pool(): Response<ApiEnvelope<PoolStateDto>>

    @POST("driver/v1/pool")
    suspend fun openPool(@Body body: JsonObject): Response<ApiEnvelope<PoolStateDto>>

    @DELETE("driver/v1/pool")
    suspend fun closePool(): Response<ApiEnvelope<PoolStateDto>>

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

    /** O-58: where requests come from — the last hour's requests around me, in ~1 km cells. */
    @GET("driver/v1/demand-heat")
    suspend fun demandHeat(@retrofit2.http.Query("lat") lat: Double, @retrofit2.http.Query("lng") lng: Double): Response<ApiEnvelope<List<HeatCellDto>>>

    /** O-47: «why don't requests reach me?» — every dispatch condition, passed or not. */
    @GET("driver/v1/dispatch-check")
    suspend fun dispatchCheck(): Response<ApiEnvelope<DispatchCheckDto>>

    /** O-09: an in-app voice call with the rider (LiveKit). */
    @POST("driver/v1/rides/{uuid}/calls")
    suspend fun startCall(@Path("uuid") uuid: String): Response<ApiEnvelope<TripCallStartDto>>

    @POST("driver/v1/rides/{uuid}/calls/{call}/accept")
    suspend fun acceptCall(@Path("uuid") uuid: String, @Path("call") call: String): Response<ApiEnvelope<TripCallStartDto>>

    @POST("driver/v1/rides/{uuid}/calls/{call}/end")
    suspend fun endCall(@Path("uuid") uuid: String, @Path("call") call: String): Response<ApiEnvelope<TripCallDto>>

    @GET("driver/v1/rides/{uuid}/messages")
    suspend fun messages(@Path("uuid") uuid: String): Response<ApiEnvelope<ChatDto>>

    @POST("driver/v1/rides/{uuid}/messages")
    suspend fun send(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<MessageDto>>

    /** D-304, D-313, D-314, D-316..D-318: settings and the account. */
    @GET("driver/v1/account/alerts")
    suspend fun alerts(): Response<ApiEnvelope<AlertsDto>>

    @PUT("driver/v1/account/alerts")
    suspend fun saveAlerts(@Body body: JsonObject): Response<ApiEnvelope<AlertsDto>>

    @GET("driver/v1/account/devices")
    suspend fun devices(): Response<ApiEnvelope<List<DeviceDto>>>

    @DELETE("driver/v1/account/devices/{id}")
    suspend fun forgetDevice(@Path("id") id: Long): Response<ApiEnvelope<List<DeviceDto>>>

    @POST("driver/v1/account/phone")
    suspend fun startPhoneChange(@Header("X-Wallet-Pin") pin: String?, @Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @POST("driver/v1/account/phone/confirm")
    suspend fun confirmPhoneChange(@Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @GET("driver/v1/account/close")
    suspend fun closeCheck(): Response<ApiEnvelope<CloseCheckDto>>

    @POST("driver/v1/account/close")
    suspend fun closeAccount(@Body body: JsonObject): Response<ApiEnvelope<JsonElement>>

    @GET("driver/v1/account/legal")
    suspend fun legal(): Response<ApiEnvelope<List<LegalDocDto>>>

    @POST("driver/v1/account/legal/accept")
    suspend fun acceptLegal(@Body body: JsonObject): Response<ApiEnvelope<List<LegalDocDto>>>

    /** D-303: delete a notification, or every read one. */
    @DELETE("driver/v1/notifications/{id}")
    suspend fun deleteNotification(@Path("id") id: String): Response<ApiEnvelope<JsonElement>>

    @DELETE("driver/v1/notifications/read")
    suspend fun clearReadNotifications(): Response<ApiEnvelope<JsonElement>>

    /** D-285..D-293: the help centre, my tickets and their conversations. */
    @GET("driver/v1/support/help")
    suspend fun supportHelp(@Query("q") q: String?): Response<ApiEnvelope<SupportHelpDto>>

    @GET("driver/v1/support/tickets")
    suspend fun supportTickets(): Response<ApiEnvelope<List<SupportTicketDto>>>

    @GET("driver/v1/support/tickets/{id}")
    suspend fun supportTicket(@Path("id") id: Long): Response<ApiEnvelope<SupportTicketDto>>

    @Multipart
    @POST("driver/v1/support/tickets")
    suspend fun openSupportTicket(@PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>, @Part image: MultipartBody.Part?): Response<ApiEnvelope<SupportTicketDto>>

    @PATCH("driver/v1/support/tickets/{id}/status")
    suspend fun setSupportStatus(@Path("id") id: Long, @Body body: JsonObject): Response<ApiEnvelope<SupportTicketDto>>

    @GET("driver/v1/support/tickets/{id}/messages")
    suspend fun supportMessages(@Path("id") id: Long): Response<ApiEnvelope<List<SupportMessageDto>>>

    @Multipart
    @POST("driver/v1/support/tickets/{id}/messages")
    suspend fun sendSupportMessage(@Path("id") id: Long, @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>, @Part image: MultipartBody.Part?): Response<ApiEnvelope<JsonElement>>

    /** D-294..D-297: riders' lost items in my car. */
    @GET("driver/v1/lost-items")
    suspend fun lostItems(): Response<ApiEnvelope<List<LostItemDto>>>

    @POST("driver/v1/lost-items/{uuid}/answer")
    suspend fun answerLostItem(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<LostItemDto>>

    @POST("driver/v1/lost-items/{uuid}/messages")
    suspend fun lostItemMessage(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<LostItemDto>>

    @Multipart
    @POST("driver/v1/lost-items/{uuid}/returned")
    suspend fun lostItemReturned(@Path("uuid") uuid: String, @Part photo: MultipartBody.Part?): Response<ApiEnvelope<LostItemDto>>

    /** D-216..D-224: my payout safeguards; D-227, D-228: monthly statements. */
    /** O-85: what this captain's vehicle can do — the app shows only these services. */
    @GET("driver/v1/work-services")
    suspend fun workServices(): Response<ApiEnvelope<WorkServicesDto>>

    /** D-329..D-344: maintenance, safe mode, switched-off service / categories, emergency review, the last withdrawal. */
    @GET("driver/v1/service-status")
    suspend fun serviceStatus(): Response<ApiEnvelope<ServiceStatusDto>>

    @GET("driver/v1/payouts/status")
    suspend fun payoutStatus(): Response<ApiEnvelope<PayoutStatusDto>>

    @POST("driver/v1/payouts/international")
    suspend fun requestInternationalPayouts(@Body body: JsonObject): Response<ApiEnvelope<PayoutStatusDto>>

    @GET("driver/v1/invoices")
    suspend fun invoices(): Response<ApiEnvelope<List<InvoiceDto>>>

    @GET("driver/v1/invoices/{month}")
    suspend fun invoice(@Path("month") month: String): Response<ApiEnvelope<InvoiceDto>>

    /** D-299: no sign-in — a code to the number, then the freeze. */
    @POST("driver/v1/emergency/freeze/request")
    suspend fun emergencyFreezeRequest(@Body body: JsonObject): Response<ApiEnvelope<JsonObject>>

    @POST("driver/v1/emergency/freeze/confirm")
    suspend fun emergencyFreezeConfirm(@Body body: JsonObject): Response<ApiEnvelope<JsonObject>>

    /** D-5, D-6, D-239..D-259: my rank, my numbers, my record. */
    @GET("driver/v1/rank")
    suspend fun rank(): Response<ApiEnvelope<RankInfoDto>>

    /** `{text}` — one appeal on a note / warning / rank change. */
    @POST("driver/v1/rank/notes/{id}/appeal")
    suspend fun appealNote(@Path("id") id: Long, @Body body: JsonObject): Response<ApiEnvelope<PerformanceNoteDto>>

    @GET("driver/v1/earnings")
    suspend fun earnings(): Response<ApiEnvelope<EarningsDto>>

    @GET("driver/v1/driving-hours")
    suspend fun drivingHours(): Response<ApiEnvelope<DrivingHoursDto>>

    // ---------------------------------------------------------------- cases (D-229..D-238)
    @GET("driver/v1/rides")
    suspend fun rideHistory(@Query("page") page: Int = 1): Response<ApiEnvelope<DriverRideListDto>>

    @GET("driver/v1/cases/options")
    suspend fun caseOptions(): Response<ApiEnvelope<CaseOptionsDto>>

    @GET("driver/v1/cases")
    suspend fun cases(): Response<ApiEnvelope<List<CaseDto>>>

    @POST("driver/v1/cases")
    suspend fun openCase(@Body body: JsonObject): Response<ApiEnvelope<CaseDto>>

    @GET("driver/v1/cases/{uuid}")
    suspend fun caseOf(@Path("uuid") uuid: String): Response<ApiEnvelope<CaseDto>>

    @POST("driver/v1/cases/{uuid}/messages")
    suspend fun caseMessage(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<CaseDto>>

    @Multipart
    @POST("driver/v1/cases/{uuid}/files")
    suspend fun caseFile(@Path("uuid") uuid: String, @Part file: MultipartBody.Part): Response<ApiEnvelope<CaseDto>>

    @POST("driver/v1/cases/{uuid}/appeal")
    suspend fun appealCase(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<CaseDto>>

    // ---------------------------------------------------------------- driving preferences (D-32..D-37)
    @GET("driver/v1/preferences")
    suspend fun preferences(): Response<ApiEnvelope<PreferencesDto>>

    @PUT("driver/v1/preferences")
    suspend fun savePreferences(@Body body: JsonObject): Response<ApiEnvelope<PreferencesDto>>

    @GET("driver/v1/earnings/report")
    suspend fun earningsReport(@Query("period") period: String, @Query("from") from: String? = null, @Query("to") to: String? = null): Response<ApiEnvelope<EarningsReportDto>>

    // ---------------------------------------------------------------- Block 06: DORR AI for the captain

    /** Hot zones now (everyone); the forecast and booked-ahead demand on a DORR AI plan. */
    @GET("driver/v1/demand")
    suspend fun demand(@Query("lat") lat: Double, @Query("lng") lng: Double): Response<ApiEnvelope<DemandDto>>

    /** DORR Driver AI's cards: where to work, clashes with my bookings, my daily goal. */
    @GET("driver/v1/ai/assistant")
    suspend fun aiAssistant(@Query("lat") lat: Double?, @Query("lng") lng: Double?): Response<ApiEnvelope<DriverAiDto>>

    /** `{question, lat?, lng?}` */
    @POST("driver/v1/ai/ask")
    suspend fun aiAsk(@Body body: JsonObject): Response<ApiEnvelope<DriverAiAnswerDto>>

    /** «راحتي»: `{item, reason}` — one of the rider's requests can't be done here. */
    @POST("driver/v1/rides/{uuid}/comfort/unable")
    suspend fun comfortUnable(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<TripDto>>

    /** The same DORR AI plans as the DORR app, paid from my wallet with my PIN. */
    @GET("driver/v1/ai-subscription/plans")
    suspend fun aiPlans(): Response<ApiEnvelope<List<AiPlanDto>>>

    @POST("driver/v1/ai-subscription/subscribe")
    suspend fun aiSubscribe(@Header("X-Wallet-Pin") pin: String, @Body body: JsonObject): Response<ApiEnvelope<JsonElement>>
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

data class FlagDto(val id: Int = 0, val code: String = "")

data class CountryDto(
    val id: Int,
    val code: String,
    val name: String = "",
    @SerializedName("dial_code") val dialCode: String = "",
    @SerializedName("phone_length") val phoneLength: Int? = null,
    @SerializedName("phone_starts_with") val phoneStartsWith: String? = null,
    @SerializedName("is_default") val isDefault: Boolean = false,
    val flag: FlagDto? = null,
)

data class AppearanceDefaultsDto(
    @SerializedName("light_tokens") val lightTokens: Map<String, String>? = null,
    @SerializedName("dark_tokens") val darkTokens: Map<String, String>? = null,
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
