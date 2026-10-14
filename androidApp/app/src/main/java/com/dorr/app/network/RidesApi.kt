package com.dorr.app.network

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Passenger rides, the rider's side (Modules/Rides, docs/rides-plan.md §15): what is switched on
 * here, prices for a trip, booking, following the ride, its chat, cancelling and rating. Money is
 * always in minor units (halalas); the server prices everything — the app never sends a price.
 */
interface RidesApi {
    @GET("mobile/v1/rides/features")
    suspend fun features(@Header("Authorization") auth: String, @Query("lat") lat: Double?, @Query("lng") lng: Double?): ApiEnvelope<RideFeaturesDto>

    /** `{origin{lat,lng,address}, destination?{…}, stops?[…], category?}` → one quote per category. */
    @POST("mobile/v1/rides/quotes")
    suspend fun quotes(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<List<RideQuoteDto>>

    /** `{quote_uuid, fingerprint, payment_method}`. A card ride comes back `awaiting_payment`. */
    @POST("mobile/v1/rides")
    suspend fun book(@Header("Authorization") auth: String, @Header("Idempotency-Key") key: String, @Body body: JsonObject): ApiEnvelope<RideDto>

    @GET("mobile/v1/rides/current")
    suspend fun current(@Header("Authorization") auth: String): ApiEnvelope<JsonElement?>

    @GET("mobile/v1/rides/{uuid}")
    suspend fun ride(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<RideDto>

    /** My rides (R-163..165): `tab` = current | scheduled | past. */
    @GET("mobile/v1/rides")
    suspend fun history(@Header("Authorization") auth: String, @Query("tab") tab: String? = null, @Query("page") page: Int = 1): ApiEnvelope<RideHistoryDto>

    @GET("mobile/v1/rides/{uuid}/cancel/preview")
    suspend fun cancelPreview(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<RideCancelPreviewDto>

    @POST("mobile/v1/rides/{uuid}/cancel")
    suspend fun cancel(@Header("Authorization") auth: String, @Header("Idempotency-Key") key: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<RideDto>

    @POST("mobile/v1/rides/{uuid}/rate")
    suspend fun rate(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<JsonElement?>

    @GET("mobile/v1/rides/cancel-reasons")
    suspend fun cancelReasons(@Header("Authorization") auth: String): ApiEnvelope<List<RideReasonDto>>

    @GET("mobile/v1/rides/rating-tags")
    suspend fun ratingTags(@Header("Authorization") auth: String): ApiEnvelope<List<RideTagDto>>

    @GET("mobile/v1/rides/{uuid}/messages")
    suspend fun messages(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<RideChatDto>

    /** `{body}` or `{quick}`, with a `client_id` so a re-send lands once. */
    @POST("mobile/v1/rides/{uuid}/messages")
    suspend fun send(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<RideMessageDto>

    // ---- bidding (§9): the drivers' offers, take / decline one, raise my price
    /** The family link (R-86, R-111): made once per ride, then the same. */
    @POST("mobile/v1/rides/{uuid}/share")
    suspend fun share(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<RideShareDto>

    /** SOS (R-110): where I am; the answer carries the number to call. */
    @POST("mobile/v1/rides/{uuid}/sos")
    suspend fun sos(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<RideSosDto>

    /** A new destination / one more stop during the ride: its effect first (R-92), then send it (R-94). */
    @POST("mobile/v1/rides/{uuid}/changes")
    suspend fun changePreview(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<RideChangeDto>

    @POST("mobile/v1/rides/{uuid}/changes/{change}/send")
    suspend fun changeSend(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("change") change: String): ApiEnvelope<RideChangeDto>

    // ---------------------------------------------------------------- the Safety Center (R-219..230)
    @GET("mobile/v1/rides/safety")
    suspend fun safety(@Header("Authorization") auth: String): ApiEnvelope<SafetyCenterDto>

    @POST("mobile/v1/rides/safety/contacts")
    suspend fun addContact(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<List<TrustedContactDto>>

    @PUT("mobile/v1/rides/safety/contacts/{id}")
    suspend fun updateContact(@Header("Authorization") auth: String, @Path("id") id: Int, @Body body: JsonObject): ApiEnvelope<List<TrustedContactDto>>

    @DELETE("mobile/v1/rides/safety/contacts/{id}")
    suspend fun deleteContact(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<List<TrustedContactDto>>

    @PUT("mobile/v1/rides/safety/preferences")
    suspend fun savePreferences(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<SafetyPreferencesDto>

    @DELETE("mobile/v1/rides/safety/blocks/{id}")
    suspend fun unblock(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<JsonElement?>

    @DELETE("mobile/v1/rides/safety/favorites/{driver}")
    suspend fun unfavorite(@Header("Authorization") auth: String, @Path("driver") driver: Int): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/rides/{uuid}/contacts/share")
    suspend fun shareWithContacts(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/rides/{uuid}/block")
    suspend fun blockDriver(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/rides/{uuid}/favorite")
    suspend fun favorite(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/rides/{uuid}/report")
    suspend fun report(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/rides/{uuid}/vehicle-mismatch")
    suspend fun vehicleMismatch(@Header("Authorization") auth: String, @Header("Idempotency-Key") key: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/rides/{uuid}/safety-checks/{check}")
    suspend fun answerCheck(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("check") check: String, @Body body: JsonObject): ApiEnvelope<RideSosDto>

    @GET("mobile/v1/rides/{uuid}/bids")
    suspend fun bids(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<RideBidBoardDto>

    @POST("mobile/v1/rides/{uuid}/bids/{bid}/accept")
    suspend fun acceptBid(@Header("Authorization") auth: String, @Header("Idempotency-Key") key: String, @Path("uuid") uuid: String, @Path("bid") bid: String): ApiEnvelope<RideDto>

    @POST("mobile/v1/rides/{uuid}/bids/{bid}/decline")
    suspend fun declineBid(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("bid") bid: String): ApiEnvelope<JsonElement?>

    /** `{price_minor}` higher than my current price. */
    @POST("mobile/v1/rides/{uuid}/raise")
    suspend fun raise(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<RideDto>
}

data class RideBidQuoteDto(
    @SerializedName("suggested_minor") val suggestedMinor: Long,
    @SerializedName("floor_minor") val floorMinor: Long,
    @SerializedName("step_minor") val stepMinor: Long = 100,
)

data class RideBiddingDto(
    @SerializedName("rider_price_minor") val riderPriceMinor: Long? = null,
    @SerializedName("floor_minor") val floorMinor: Long? = null,
    @SerializedName("suggested_minor") val suggestedMinor: Long? = null,
)

data class RideBidDriverDto(
    val name: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val rating: Double? = null,
    @SerializedName("trips_count") val tripsCount: Int = 0,
    val vehicle: RideVehicleDto? = null,
)

data class RideBidDto(
    val uuid: String,
    @SerializedName("price_minor") val priceMinor: Long,
    @SerializedName("eta_s") val etaS: Int = 0,
    @SerializedName("seconds_left") val secondsLeft: Int = 0,
    @SerializedName("ttl_s") val ttlS: Int = 20,
    val cheapest: Boolean = false,
    val nearest: Boolean = false,
    val driver: RideBidDriverDto = RideBidDriverDto(),
)

data class RideBidBoardDto(
    @SerializedName("rider_price_minor") val riderPriceMinor: Long? = null,
    @SerializedName("floor_minor") val floorMinor: Long? = null,
    val bids: List<RideBidDto> = emptyList(),
    val queued: Int = 0,
)

data class RideFeaturesDto(
    @SerializedName("country_id") val countryId: Int? = null,
    @SerializedName("city_id") val cityId: Int? = null,
    val service: Boolean = false,
    val categories: Map<String, Boolean> = emptyMap(),
    val features: Map<String, Boolean> = emptyMap(),
)

data class RidePlaceDto(val lat: Double, val lng: Double, val address: String? = null)

data class RideLineDto(
    val code: String,
    val label: String? = null,
    @SerializedName("amount_minor") val amountMinor: Long = 0,
)

data class RideRouteDto(
    @SerializedName("distance_m") val distanceM: Int? = null,
    @SerializedName("duration_s") val durationS: Int? = null,
    val polyline: String? = null,
    val estimated: Boolean? = null,
)

data class RideQuoteCategoryDto(
    val slug: String? = null,
    val name: String? = null,
    val tagline: String? = null,
    val color: String? = null,
    val capacity: Int? = null,
    @SerializedName("icon_url") val iconUrl: String? = null,
    @SerializedName("cash_allowed") val cashAllowed: Boolean? = true,
)

data class RideRangeDto(@SerializedName("min_minor") val minMinor: Long = 0, @SerializedName("max_minor") val maxMinor: Long = 0)

data class RideQuoteDto(
    val uuid: String,
    val fingerprint: String,
    @SerializedName("expires_at") val expiresAt: String? = null,
    val category: RideQuoteCategoryDto = RideQuoteCategoryDto(),
    val currency: RideCurrencyDto? = null,
    @SerializedName("pricing_mode") val pricingMode: String = "upfront",
    @SerializedName("open_destination") val openDestination: Boolean = false,
    @SerializedName("total_minor") val totalMinor: Long? = null,
    val estimate: RideRangeDto? = null,
    @SerializedName("hold_minor") val holdMinor: Long? = null,
    val route: RideRouteDto? = null,
    val lines: List<RideLineDto> = emptyList(),
    val bid: RideBidQuoteDto? = null,
    /** Booking ahead is possible for this category (null for bidding), with the policy's limits. */
    val scheduling: RideSchedulingDto? = null,
)

data class RideSchedulingDto(
    @SerializedName("min_ahead_s") val minAheadS: Long = 3600,
    @SerializedName("max_ahead_s") val maxAheadS: Long = 604800,
    @SerializedName("free_cancel_before_s") val freeCancelBeforeS: Long = 3600,
    @SerializedName("cancel_fee_minor") val cancelFeeMinor: Long = 0,
)

/** A ride booked ahead: its time, the free-cancel limit, and whether a captain reserved it. */
data class RideScheduledDto(
    val at: String? = null,
    @SerializedName("free_cancel_until") val freeCancelUntil: String? = null,
    @SerializedName("starts_search_at") val startsSearchAt: String? = null,
    val reserved: Boolean = false,
)

data class RideChangeRouteDto(@SerializedName("distance_m") val distanceM: Int? = null, @SerializedName("duration_s") val durationS: Int? = null)

/** A change of the trip during the ride and its effect (R-91..95). */
data class RideChangeDto(
    val uuid: String,
    val type: String = "destination",
    val status: String,
    val place: RidePlaceDto? = null,
    @SerializedName("distance_delta_m") val distanceDeltaM: Int = 0,
    @SerializedName("duration_delta_s") val durationDeltaS: Int = 0,
    val route: RideChangeRouteDto? = null,
    @SerializedName("price_delta_minor") val priceDeltaMinor: Long? = null,
    @SerializedName("new_total_minor") val newTotalMinor: Long? = null,
    @SerializedName("seconds_left") val secondsLeft: Int = 0,
)

data class TrustedContactDto(
    val id: Int,
    val name: String = "",
    val phone: String = "",
    @SerializedName("share_mode") val shareMode: String = "manual",
    @SerializedName("notify_arrival") val notifyArrival: Boolean = true,
)

data class SafetyPreferencesDto(
    @SerializedName("pin_mode") val pinMode: String = "policy",
    @SerializedName("checkin_after_min") val checkinAfterMin: Int? = null,
    @SerializedName("seatbelt_reminder") val seatbeltReminder: Boolean = true,
)

data class BlockedPersonDto(val id: Int, val name: String? = null, val since: String? = null)

data class FavoriteDriverDto(
    @SerializedName("driver_id") val driverId: Int,
    val name: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val rating: Double? = null,
)

/** "Are you OK?" waiting for an answer (R-222, R-223). */
data class SafetyCheckDto(
    val uuid: String,
    val kind: String = "ride_check",
    val reason: String? = null,
    @SerializedName("ride_uuid") val rideUuid: String? = null,
)

data class SafetyCenterDto(
    val contacts: List<TrustedContactDto> = emptyList(),
    val preferences: SafetyPreferencesDto = SafetyPreferencesDto(),
    val blocked: List<BlockedPersonDto> = emptyList(),
    val favorites: List<FavoriteDriverDto> = emptyList(),
    @SerializedName("emergency_number") val emergencyNumber: String = "911",
    @SerializedName("max_contacts") val maxContacts: Int = 5,
    @SerializedName("pending_check") val pendingCheck: SafetyCheckDto? = null,
)

data class RideShareDto(val url: String, val views: Int = 0)

data class RideSosDto(@SerializedName("incident_uuid") val incidentUuid: String? = null, @SerializedName("emergency_number") val emergencyNumber: String? = null)

data class RideSafetyDto(@SerializedName("emergency_number") val emergencyNumber: String? = null)

data class RideCurrencyDto(val code: String? = null, val symbol: String? = null, @SerializedName("decimal_places") val decimals: Int = 2)

data class RideStopDto(val seq: Int, val lat: Double, val lng: Double, val address: String? = null, val status: String? = null)

data class RideVehicleDto(
    val make: String? = null,
    val model: String? = null,
    val color: String? = null,
    @SerializedName("color_hex") val colorHex: String? = null,
    @SerializedName("plate_letters") val plateLetters: String? = null,
    @SerializedName("plate_number") val plateNumber: String? = null,
    val year: Int? = null,
)

data class RideDriverLocationDto(val lat: Double, val lng: Double, val heading: Int? = null, val at: String? = null)

data class RideDriverDto(
    val name: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val rating: Double? = null,
    @SerializedName("trips_count") val tripsCount: Int = 0,
    val vehicle: RideVehicleDto? = null,
    val location: RideDriverLocationDto? = null,
)

data class RideTimesDto(
    @SerializedName("requested_at") val requestedAt: String? = null,
    @SerializedName("accepted_at") val acceptedAt: String? = null,
    @SerializedName("arrived_at") val arrivedAt: String? = null,
    /** The free waiting counts from here (a scheduled ride's time when the captain came early). */
    @SerializedName("waiting_from") val waitingFrom: String? = null,
    @SerializedName("started_at") val startedAt: String? = null,
    @SerializedName("completed_at") val completedAt: String? = null,
    @SerializedName("canceled_at") val canceledAt: String? = null,
)

data class RideWaitingDto(
    @SerializedName("free_s") val freeS: Int = 180,
    @SerializedName("per_min_minor") val perMinMinor: Long = 0,
    @SerializedName("no_show_after_s") val noShowAfterS: Int = 300,
)

data class RideCategoryRefDto(val slug: String? = null, val name: String? = null)

data class RideDto(
    val uuid: String,
    val number: String? = null,
    val status: String,
    val version: Int = 0,
    val category: RideCategoryRefDto? = null,
    @SerializedName("pricing_mode") val pricingMode: String? = null,
    @SerializedName("open_destination") val openDestination: Boolean = false,
    @SerializedName("payment_method") val paymentMethod: String? = null,
    @SerializedName("payment_status") val paymentStatus: String? = null,
    val currency: RideCurrencyDto? = null,
    val origin: RidePlaceDto? = null,
    val destination: RidePlaceDto? = null,
    val stops: List<RideStopDto> = emptyList(),
    val route: RideRouteDto? = null,
    val scheduled: RideScheduledDto? = null,
    val safety: RideSafetyDto? = null,
    val times: RideTimesDto? = null,
    val waiting: RideWaitingDto? = null,
    val estimate: RideRangeDto? = null,
    @SerializedName("agreed_price_minor") val agreedPriceMinor: Long? = null,
    val bidding: RideBiddingDto? = null,
    @SerializedName("final_total_minor") val finalTotalMinor: Long? = null,
    val lines: List<RideLineDto> = emptyList(),
    @SerializedName("checkout_id") val checkoutId: String? = null,
    val pin: String? = null,
    val driver: RideDriverDto? = null,
    @SerializedName("reserved_driver") val reservedDriver: RideDriverDto? = null,
    @SerializedName("change_request") val changeRequest: RideChangeDto? = null,
    @SerializedName("safety_check") val safetyCheck: SafetyCheckDto? = null,
    @SerializedName("favorite_driver") val favoriteDriver: Boolean? = null,
    val rated: Boolean? = null,
)

data class RideHistoryItemDto(
    val uuid: String,
    val number: String? = null,
    val status: String,
    val category: String? = null,
    @SerializedName("origin_address") val originAddress: String? = null,
    @SerializedName("destination_address") val destinationAddress: String? = null,
    @SerializedName("total_minor") val totalMinor: Long? = null,
    val currency: String? = null,
    @SerializedName("driver_name") val driverName: String? = null,
    @SerializedName("requested_at") val requestedAt: String? = null,
    @SerializedName("scheduled_at") val scheduledAt: String? = null,
    @SerializedName("free_cancel_until") val freeCancelUntil: String? = null,
)

data class RideHistoryDto(val items: List<RideHistoryItemDto> = emptyList())

data class RideCancelPreviewDto(val stage: String, @SerializedName("fee_minor") val feeMinor: Long = 0, @SerializedName("free_until") val freeUntil: String? = null)

data class RideReasonDto(val id: Int, val label: String? = null, @SerializedName("requires_note") val requiresNote: Boolean = false)

data class RideTagDto(val id: Int, val label: String? = null, val sentiment: String? = null)

data class RideMessageDto(
    val id: Long,
    val from: String,
    val mine: Boolean? = null,
    val kind: String = "text",
    val body: String = "",
    @SerializedName("client_id") val clientId: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class RideQuickReplyDto(val key: String, val text: String)

data class RideChatDto(
    val open: Boolean = false,
    @SerializedName("quick_replies") val quickReplies: List<RideQuickReplyDto> = emptyList(),
    val messages: List<RideMessageDto> = emptyList(),
)
