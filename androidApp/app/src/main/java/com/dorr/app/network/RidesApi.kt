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

    /** R-16..R-18: the approved pickup / drop-off points around a place (`purpose` = pickup | dropoff). */
    @GET("mobile/v1/rides/pickup-points")
    suspend fun pickupPoints(@Header("Authorization") auth: String, @Query("lat") lat: Double, @Query("lng") lng: Double, @Query("purpose") purpose: String): ApiEnvelope<RidePickupPointsDto>

    /** R-INN-07 DORR Circle: my favourite captains, to choose from when booking. */
    @GET("mobile/v1/rides/circle")
    suspend fun circle(@Header("Authorization") auth: String): ApiEnvelope<RideCircleDriversDto>

    /** "Search all captains" once the circle had no one. */
    @POST("mobile/v1/rides/{uuid}/circle/widen")
    suspend fun widenCircle(@Header("Authorization") auth: String, @Header("Idempotency-Key") key: String, @Path("uuid") uuid: String): ApiEnvelope<RideDto>

    /** Complaints and disputes (R-133..R-140): what can be opened, my cases, one case, and its conversation. */
    @GET("mobile/v1/rides/cases/options")
    suspend fun caseOptions(@Header("Authorization") auth: String): ApiEnvelope<RideCaseOptionsDto>

    @GET("mobile/v1/rides/cases")
    suspend fun cases(@Header("Authorization") auth: String): ApiEnvelope<List<RideCaseDto>>

    @POST("mobile/v1/rides/cases")
    suspend fun openCase(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<RideCaseDto>

    @GET("mobile/v1/rides/cases/{uuid}")
    suspend fun caseOf(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<RideCaseDto>

    @POST("mobile/v1/rides/cases/{uuid}/messages")
    suspend fun caseMessage(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<RideCaseDto>

    @POST("mobile/v1/rides/cases/{uuid}/appeal")
    suspend fun appealCase(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<RideCaseDto>

    /** R-5, R-174: Home, Work, favourite places and recent destinations. */
    @GET("mobile/v1/rides/places")
    suspend fun places(@Header("Authorization") auth: String): ApiEnvelope<RidePlacesDto>

    /** O-57: places as the rider types (from two letters), nearest first — the admin's map provider. */
    @GET("mobile/v1/rides/places/suggest")
    suspend fun placeSuggest(@Header("Authorization") auth: String, @Query("q") q: String, @Query("lat") lat: Double?, @Query("lng") lng: Double?): ApiEnvelope<List<PlaceHitDto>>

    /** O-59: the free captains' cars around me now, and the area channels to follow for their moves. */
    @GET("mobile/v1/rides/nearby-cars")
    suspend fun nearbyCars(@Header("Authorization") auth: String, @Query("lat") lat: Double, @Query("lng") lng: Double): ApiEnvelope<NearbyCarsDto>

    /** O-57: before anything is typed — where people around here go most, and well-known places (≤ 50 km). */
    @GET("mobile/v1/rides/places/popular")
    suspend fun placePopular(@Header("Authorization") auth: String, @Query("lat") lat: Double, @Query("lng") lng: Double): ApiEnvelope<List<PlaceHitDto>>

    /** `{origin{lat,lng,address,point_id?}, destination?{…}, stops?[…], category?}` → one quote per category. */
    @POST("mobile/v1/rides/quotes")
    suspend fun quotes(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<List<RideQuoteDto>>

    /** `{quote_uuid, fingerprint, payment_method}`. A card ride comes back `awaiting_payment`. */
    @POST("mobile/v1/rides")
    suspend fun book(@Header("Authorization") auth: String, @Header("Idempotency-Key") key: String, @Body body: JsonObject): ApiEnvelope<RideDto>

    /** O-03: which map to draw — Google, or Mapbox with its public token and style. */
    @GET("mobile/v1/rides/map-config")
    suspend fun mapConfig(@Header("Authorization") auth: String): ApiEnvelope<MapConfigDto>

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

    /** R-61: wallet <-> cash before the trip starts. */
    @POST("mobile/v1/rides/{uuid}/payment-method")
    suspend fun changePayment(@Header("Authorization") auth: String, @Header("Idempotency-Key") key: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<RideDto>

    /** R-144: a tip after the ride, from the wallet — all of it the captain's. */
    @POST("mobile/v1/rides/{uuid}/tip")
    suspend fun tip(@Header("Authorization") auth: String, @Header("Idempotency-Key") key: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<RideDto>

    @GET("mobile/v1/rides/cancel-reasons")
    suspend fun cancelReasons(@Header("Authorization") auth: String): ApiEnvelope<List<RideReasonDto>>

    @GET("mobile/v1/rides/rating-tags")
    suspend fun ratingTags(@Header("Authorization") auth: String): ApiEnvelope<List<RideTagDto>>

    /** O-09: an in-app voice call with the captain (LiveKit). */
    @POST("mobile/v1/rides/{uuid}/calls")
    suspend fun startCall(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<RideCallStartDto>

    @POST("mobile/v1/rides/{uuid}/calls/{call}/accept")
    suspend fun acceptCall(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("call") call: String): ApiEnvelope<RideCallStartDto>

    @POST("mobile/v1/rides/{uuid}/calls/{call}/end")
    suspend fun endCall(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("call") call: String): ApiEnvelope<RideCallDto>

    @GET("mobile/v1/rides/{uuid}/messages")
    suspend fun messages(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<RideChatDto>

    /** D-294..D-297: something I left in the car. */
    @POST("mobile/v1/rides/{uuid}/lost-item")
    suspend fun reportLostItem(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<LostItemDto>

    @GET("mobile/v1/rides/lost-items")
    suspend fun lostItems(@Header("Authorization") auth: String): ApiEnvelope<List<LostItemDto>>

    @POST("mobile/v1/rides/lost-items/{lost}/messages")
    suspend fun lostItemMessage(@Header("Authorization") auth: String, @Path("lost") lost: String, @Body body: JsonObject): ApiEnvelope<LostItemDto>

    @POST("mobile/v1/rides/lost-items/{lost}/close")
    suspend fun closeLostItem(@Header("Authorization") auth: String, @Path("lost") lost: String): ApiEnvelope<LostItemDto>

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

    // Block 06 — DORR AI in rides.

    /** «دُرر يفهم مقصدك»: is it here, and does my DORR AI plan allow it (the button is locked otherwise). */
    @GET("mobile/v1/rides/ai/status")
    suspend fun aiStatus(@Header("Authorization") auth: String, @Query("lat") lat: Double?, @Query("lng") lng: Double?): ApiEnvelope<RideAiStatusDto>

    /** `{text, lat?, lng?}` — the trip in my own words → places on the admin's map + what else I said. */
    @POST("mobile/v1/rides/ai/understand")
    suspend fun aiUnderstand(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<RideAiUnderstandDto>

    /** AI-09 `{distance_m?, passengers?, categories?[]}` — the category I usually take for a trip like this. */
    @POST("mobile/v1/rides/ai/suggest")
    suspend fun aiSuggest(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<RideAiSuggestDto>

    /** «راحتي»: my trip preferences and the choices. */
    @GET("mobile/v1/rides/comfort")
    suspend fun comfort(@Header("Authorization") auth: String, @Query("lat") lat: Double?, @Query("lng") lng: Double?): ApiEnvelope<RideComfortScreenDto>

    /** `{quiet, ac, music, notes[]}` */
    @PUT("mobile/v1/rides/comfort")
    suspend fun saveComfort(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<RideComfortScreenDto>
}

data class RideBidQuoteDto(
    @SerializedName("suggested_minor") val suggestedMinor: Long,
    @SerializedName("floor_minor") val floorMinor: Long,
    @SerializedName("step_minor") val stepMinor: Long = 100,
    /** AI-05: the suggestion moved with demand around the pickup (null = the rate card as is). */
    val smart: RideSmartPriceDto? = null,
)

data class RideSmartPriceDto(
    @SerializedName("base_minor") val baseMinor: Long = 0,
    @SerializedName("adjust_bp") val adjustBp: Int = 0,
    val why: String? = null,
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
    /** Why `service` is off: `no_country` (we can't tell where you are) or `not_enabled` (nobody switched rides on there). */
    val reason: String? = null,
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
    /** O-37: what the wallet in this ride's country has free — offered with cash. */
    @SerializedName("wallet_available_minor") val walletAvailableMinor: Long = 0,
    val route: RideRouteDto? = null,
    val lines: List<RideLineDto> = emptyList(),
    val bid: RideBidQuoteDto? = null,
    /** R-45: the code typed — what it took off this category, or why not. */
    val coupon: RideCouponDto? = null,
    /** R-48: what each way of paying adds (wallet / card / cash). */
    @SerializedName("payment_fees") val paymentFees: Map<String, Long>? = null,
    /** R-52, R-53: cancellation / no-show / refund, read before confirming. */
    @SerializedName("policy_summary") val policySummary: RidePolicySummaryDto? = null,
    /** R-179: the terms in force for booking (the rides service's own, else the general ones). */
    val terms: List<RideTermDto> = emptyList(),
    /** Booking ahead is possible for this category (null for bidding), with the policy's limits. */
    val scheduling: RideSchedulingDto? = null,
    /** O-17: this quote is by the hour, or null. */
    val hourly: RideHourlyDto? = null,
    /** O-17: the category can also be booked by the hour (its hour price and limits). */
    @SerializedName("hourly_offer") val hourlyOffer: RideHourlyOfferDto? = null,
    /** R-233..R-237, R-NEW-06: the add-ons on offer here with their prices, and the ones priced in. */
    @SerializedName("addons_offer") val addonsOffer: RideAddonsOfferDto? = null,
    val options: List<RideAddonDto> = emptyList(),
)

data class RideCallDto(val uuid: String, val status: String, val caller: String)

data class LostRideRefDto(val uuid: String? = null, val number: String? = null)

data class LostMessageDto(val id: Long, val mine: Boolean = false, val body: String = "")

data class LostItemDto(
    val uuid: String,
    val status: String = "reported",
    val description: String = "",
    @SerializedName("return_plan") val returnPlan: String? = null,
    val ride: LostRideRefDto = LostRideRefDto(),
    val messages: List<LostMessageDto> = emptyList(),
)

/** One add-on as both apps show it (`key`: wait_save / priority / child_seats / pet / service_animal / luggage). */
data class RideAddonDto(val key: String, val label: String = "", val count: Int? = null, val small: Int? = null, val large: Int? = null)

data class RideAddonFeeDto(@SerializedName("fee_minor") val feeMinor: Long = 0, val max: Int = 2)

data class RideWaitSaveDto(@SerializedName("discount_bp") val discountBp: Int = 0, @SerializedName("extra_wait_s") val extraWaitS: Int = 0)

data class RideLuggageDto(val capacity: Int = 4, @SerializedName("large_fee_minor") val largeFeeMinor: Long = 0)

data class RideAddonsOfferDto(
    @SerializedName("wait_save") val waitSave: RideWaitSaveDto? = null,
    val priority: RideAddonFeeDto? = null,
    @SerializedName("child_seats") val childSeats: RideAddonFeeDto? = null,
    val pet: RideAddonFeeDto? = null,
    @SerializedName("service_animal") val serviceAnimal: RideAddonFeeDto? = null,
    val luggage: RideLuggageDto? = null,
) {
    val isEmpty get() = waitSave == null && priority == null && childSeats == null && pet == null && serviceAnimal == null && luggage == null
}

data class RideCallJoinDto(val url: String, val room: String, val token: String)

data class RideCallStartDto(val uuid: String, val status: String, val caller: String, val join: RideCallJoinDto)

data class RideHourlyDto(
    val hours: Int = 0,
    @SerializedName("hour_price_minor") val hourPriceMinor: Long = 0,
    @SerializedName("overtime_per_min_minor") val overtimePerMinMinor: Long = 0,
    /** On a ride: when the hours bought run out (from the start). */
    @SerializedName("ends_at") val endsAt: String? = null,
)

data class RideHourlyOfferDto(
    @SerializedName("hour_price_minor") val hourPriceMinor: Long = 0,
    @SerializedName("min_hours") val minHours: Int = 1,
    @SerializedName("max_hours") val maxHours: Int = 8,
    @SerializedName("overtime_per_min_minor") val overtimePerMinMinor: Long = 0,
)

data class RideCouponDto(
    val code: String = "",
    @SerializedName("discount_minor") val discountMinor: Long = 0,
    val applied: Boolean = false,
    val reason: String? = null,
)

data class RideTermDto(
    val type: String = "term",
    val title: String = "",
    @SerializedName("service_id") val serviceId: Int? = null,
    val scope: String = "general",
    @SerializedName("updated_at") val updatedAt: String? = null,
)

data class RidePolicyItemDto(val code: String = "", val text: String = "")

data class RidePolicySummaryDto(
    val items: List<RidePolicyItemDto> = emptyList(),
    val refund: Map<String, String> = emptyMap(),
)

/** R-144: the tip offered after the ride — or the one given (`allowed` false, `amount_minor` set). */
data class RideTipDto(
    val allowed: Boolean = false,
    @SerializedName("amount_minor") val amountMinor: Long? = null,
    @SerializedName("presets_minor") val presetsMinor: List<Long> = emptyList(),
    @SerializedName("max_minor") val maxMinor: Long = 0,
    val until: String? = null,
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

data class NearbyCarDto(val car: String, val lat: Double, val lng: Double, val heading: Int? = null)

data class NearbyCarsDto(val cells: List<String> = emptyList(), val cars: List<NearbyCarDto> = emptyList())

data class PlaceHitDto(val name: String = "", val address: String = "", val lat: Double = 0.0, val lng: Double = 0.0)

data class MapConfigDto(
    val provider: String? = "google",
    @SerializedName("mapbox_token") val mapboxToken: String? = null,
    @SerializedName("mapbox_style") val mapboxStyle: String? = null,
)

data class RidePoolDto(@SerializedName("discount_minor") val discountMinor: Long = 0, val riders: Int = 1, @SerializedName("share_with") val shareWith: String? = null)

data class RatingCriterionDto(val key: String, val label: String? = null)

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
    /** R-81: new / bronze / silver / gold / diamond, and its name in the app's language. */
    val tier: String? = null,
    @SerializedName("tier_label") val tierLabel: String? = null,
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
    /** D-122: the countdown turns to a warning this long before the free minutes end. */
    @SerializedName("warn_before_s") val warnBeforeS: Int = 60,
)

/** D-129: how far the captain still is from the next point (pickup / stop / destination). */
data class RideProgressDto(val to: String = "pickup", @SerializedName("distance_m") val distanceM: Int = 0, @SerializedName("eta_s") val etaS: Int = 0)

data class RideCategoryRefDto(val slug: String? = null, val name: String? = null)

data class RidePickupPointDto(
    val id: Int = 0,
    val kind: String? = null,
    @SerializedName("kind_label") val kindLabel: String? = null,
    val name: String? = null,
    val instructions: String? = null,
    val lat: Double = 0.0,
    val lng: Double = 0.0,
    @SerializedName("distance_m") val distanceM: Int? = null,
)

data class RidePickupZoneDto(val id: Int = 0, val type: String? = null, val name: String? = null)

/** R-16..R-18: what is around a place — the zone, whether choosing a point is a must, the points. */
data class RidePickupPointsDto(val zone: RidePickupZoneDto? = null, val required: Boolean = false, val points: List<RidePickupPointDto> = emptyList())

/** A shortcut under "where to?": a saved address of the account (home / work / favourite) or a recent destination. */
data class RideSavedPlaceDto(
    val id: Long? = null,
    val kind: String = "recent",
    val title: String? = null,
    val address: String? = null,
    val lat: Double = 0.0,
    val lng: Double = 0.0,
)

data class RidePlacesDto(
    val home: RideSavedPlaceDto? = null,
    val work: RideSavedPlaceDto? = null,
    val favorites: List<RideSavedPlaceDto> = emptyList(),
    val recent: List<RideSavedPlaceDto> = emptyList(),
)

data class RideCircleDriversDto(val drivers: List<FavoriteDriverDto> = emptyList())

/** A ride booked for the favourite captains: still only them (`active`), how many, and whether the rider was asked. */
data class RideCircleDto(val active: Boolean = false, val count: Int = 0, val asked: Boolean = false)

data class RideBeneficiaryDto(val name: String? = null, val phone: String? = null)

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
    /** R-61: the ways of paying the rider may switch to now. */
    @SerializedName("payment_change") val paymentChange: List<String> = emptyList(),
    /** D-60, D-61. */
    val passengers: Int? = null,
    @SerializedName("pickup_note") val pickupNote: String? = null,
    /** R-233..R-237: what the rider added to this trip. */
    val options: List<RideAddonDto> = emptyList(),
    /** R-146: the DORR points this ride earned. */
    @SerializedName("loyalty_points") val loyaltyPoints: Long? = null,
    /** R-13: booked for someone else — whom, as the rider typed it. */
    val beneficiary: RideBeneficiaryDto? = null,
    /** R-INN-07: booked for the favourite captains. */
    val circle: RideCircleDto? = null,
    /** R-16..R-18: the approved points the trip starts / ends at. */
    @SerializedName("pickup_point") val pickupPoint: RidePickupPointDto? = null,
    @SerializedName("dropoff_point") val dropoffPoint: RidePickupPointDto? = null,
    val progress: RideProgressDto? = null,
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
    /** O-30..O-32: sharing the car (With Me) — what came off the fare, and how many ride now. */
    val pool: RidePoolDto? = null,
    /** O-17: booked by the hour. */
    val hourly: RideHourlyDto? = null,
    /** O-09: the in-app call ringing or going on now. */
    val call: RideCallDto? = null,
    /** O-10: the captain's messages not read yet (the badge on the chat tile). */
    @SerializedName("unread_messages") val unreadMessages: Int = 0,
    /** O-09: the captain's number, to call from outside the app while the ride is on. */
    @SerializedName("call_phone") val callPhone: String? = null,
    /** O-41: what the rider scores the captain on (speed, cleanliness…), each with its own stars. */
    @SerializedName("rating_criteria") val ratingCriteria: List<RatingCriterionDto> = emptyList(),
    val tip: RideTipDto? = null,
    /** «راحتي»: what the rider asked for on this trip, and what the captain said can't be done. */
    val comfort: RideComfortDto? = null,
    /** Block 06: the captain is finishing a ride nearby and comes right after it. */
    @SerializedName("driver_finishing") val driverFinishing: RideFinishingDto? = null,
    /** Block 06: a booking's captain may be late — by about how much, and why. */
    @SerializedName("late_risk") val lateRisk: RideLateRiskDto? = null,
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

// ---------------------------------------------------------------- cases: complaints and disputes (R-133..R-140)

data class RideCaseOptionDto(val code: String, val label: String = "")

data class RideCaseOptionsDto(
    val available: Boolean = false,
    val kinds: List<RideCaseOptionDto> = emptyList(),
    @SerializedName("complaint_categories") val complaintCategories: List<RideCaseOptionDto> = emptyList(),
    @SerializedName("dispute_reasons") val disputeReasons: List<RideCaseOptionDto> = emptyList(),
)

data class RideCaseRefDto(val uuid: String = "", val number: String? = null, val status: String? = null)

data class RideCaseMessageDto(val id: Long = 0, val from: String = "", val body: String = "", val at: String? = null)

data class RideCaseDecisionDto(
    val outcome: String = "",
    val reason: String? = null,
    @SerializedName("refund_minor") val refundMinor: Long? = null,
    @SerializedName("refund_applied") val refundApplied: Boolean? = null,
)

data class RideCaseMilestoneDto(
    val event: String = "",
    val at: String? = null,
    @SerializedName("distance_to_pickup_m") val distanceToPickupM: Int? = null,
)

data class RideCaseLineDto(val code: String = "", @SerializedName("amount_minor") val amountMinor: Long = 0)

data class RideCaseWaitingDto(@SerializedName("pickup_s") val pickupS: Long = 0, @SerializedName("free_s") val freeS: Long = 0)

data class RideCaseRouteDto(@SerializedName("planned_m") val plannedM: Long? = null, @SerializedName("driven_m") val drivenM: Long? = null)

data class RideCaseEvidenceDto(
    val timeline: List<RideCaseMilestoneDto> = emptyList(),
    val waiting: RideCaseWaitingDto? = null,
    val route: RideCaseRouteDto? = null,
    val lines: List<RideCaseLineDto> = emptyList(),
)

/** A case as the rider sees it: the list carries the headline only, the detail adds the rest. */
data class RideCaseDto(
    val uuid: String = "",
    val number: String = "",
    val kind: String = "",
    @SerializedName("kind_label") val kindLabel: String = "",
    @SerializedName("category_label") val categoryLabel: String = "",
    val status: String = "submitted",
    @SerializedName("status_label") val statusLabel: String = "",
    val decided: Boolean = false,
    val summary: String? = null,
    val ride: RideCaseRefDto? = null,
    @SerializedName("disputed_minor") val disputedMinor: Long? = null,
    val currency: RideCurrencyDto? = null,
    val evidence: RideCaseEvidenceDto? = null,
    val messages: List<RideCaseMessageDto> = emptyList(),
    val decision: RideCaseDecisionDto? = null,
    @SerializedName("can_message") val canMessage: Boolean = false,
    @SerializedName("can_appeal") val canAppeal: Boolean = false,
    @SerializedName("appealed_at") val appealedAt: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

// Block 06 — DORR AI in rides.

data class RideAiStatusDto(val available: Boolean = false, val allowed: Boolean = false, val reason: String? = null)

data class RideAiUnderstoodDto(
    val destination: String? = null,
    val saved: String? = null,
    val category: String? = null,
    @SerializedName("category_name") val categoryName: String? = null,
    /** ISO time with offset, for a ride later. */
    val `when`: String? = null,
    val passengers: Int? = null,
    val payment: String? = null,
    val note: String? = null,
)

data class RideAiPlaceDto(val name: String? = null, val address: String = "", val lat: Double, val lng: Double, val source: String? = null)

data class RideAiUnderstandDto(
    val understood: RideAiUnderstoodDto = RideAiUnderstoodDto(),
    val clarify: String? = null,
    val places: List<RideAiPlaceDto> = emptyList(),
    @SerializedName("confirm_on_map") val confirmOnMap: Boolean = true,
)

data class RideAiSuggestDto(
    val allowed: Boolean = false,
    val reason: String? = null,
    val category: String? = null,
    val name: String? = null,
    val confidence: Double? = null,
    val why: String? = null,
)

data class RideComfortPrefsDto(val quiet: Boolean = false, val ac: String = "any", val music: String = "any", val notes: List<String> = emptyList())

data class RideChoiceDto(val value: String, val label: String = "")

data class RideComfortOptionsDto(val ac: List<RideChoiceDto> = emptyList(), val music: List<RideChoiceDto> = emptyList(), val notes: List<RideChoiceDto> = emptyList())

data class RideComfortScreenDto(
    val enabled: Boolean = false,
    val prefs: RideComfortPrefsDto = RideComfortPrefsDto(),
    val options: RideComfortOptionsDto = RideComfortOptionsDto(),
    val disclaimer: String? = null,
)

data class RideComfortItemDto(val item: String, val label: String = "")

data class RideComfortUnableDto(
    val item: String? = null,
    val label: String? = null,
    val reason: String? = null,
    @SerializedName("reason_label") val reasonLabel: String? = null,
)

data class RideComfortDto(
    val quiet: Boolean = false,
    val ac: String = "any",
    val music: String = "any",
    val notes: List<String> = emptyList(),
    val items: List<RideComfortItemDto> = emptyList(),
    val unable: List<RideComfortUnableDto> = emptyList(),
    val disclaimer: String? = null,
)

data class RideFinishingDto(val finishing: Boolean = false, @SerializedName("eta_s") val etaS: Int = 0)

data class RideLateRiskDto(
    @SerializedName("late_s") val lateS: Int = 0,
    val minutes: Int = 0,
    val reason: String? = null,
    @SerializedName("reason_label") val reasonLabel: String? = null,
)
