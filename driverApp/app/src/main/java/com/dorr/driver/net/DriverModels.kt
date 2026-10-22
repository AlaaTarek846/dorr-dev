package com.dorr.driver.net

import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName

// ---------------------------------------------------------------- presence

data class PresenceDto(
    val status: String = "offline",
    @SerializedName("online_since") val onlineSince: String? = null,
    @SerializedName("last_ride_requested") val lastRideRequested: Boolean = false,
    @SerializedName("gps_enabled") val gpsEnabled: Boolean = true,
    @SerializedName("can_go_online") val canGoOnline: Boolean = false,
    val reason: String? = null,
    @SerializedName("active_ride_id") val activeRideId: Long? = null,
    @SerializedName("paused_until") val pausedUntil: String? = null,
)

// ---------------------------------------------------------------- signing up

data class DocTypeDto(
    val id: Int,
    val code: String,
    val name: String? = null,
    val instructions: String? = null,
    @SerializedName("applies_to") val appliesTo: String = "driver",
    @SerializedName("has_number") val hasNumber: Boolean = false,
    @SerializedName("has_expiry") val hasExpiry: Boolean = false,
    @SerializedName("needs_back_side") val needsBackSide: Boolean = false,
)

data class MyDocumentDto(
    val id: Long,
    val status: String,
    @SerializedName("rejection_reason") val rejectionReason: String? = null,
    val number: String? = null,
    @SerializedName("expires_at") val expiresAt: String? = null,
    @SerializedName("front_url") val frontUrl: String? = null,
    @SerializedName("back_url") val backUrl: String? = null,
)

data class RequiredDocDto(val type: DocTypeDto, val document: MyDocumentDto? = null)

data class OnboardingVehicleDto(
    val id: Int,
    @SerializedName("make_id") val makeId: Int? = null,
    @SerializedName("model_id") val modelId: Int? = null,
    @SerializedName("color_id") val colorId: Int? = null,
    val make: String? = null,
    val model: String? = null,
    val color: String? = null,
    @SerializedName("color_hex") val colorHex: String? = null,
    val year: Int? = null,
    @SerializedName("body_type") val bodyType: String? = null,
    val seats: Int? = null,
    @SerializedName("plate_letters") val plateLetters: String? = null,
    @SerializedName("plate_number") val plateNumber: String? = null,
    @SerializedName("plate_type") val plateType: Int? = null,
    @SerializedName("sequence_number") val sequenceNumber: String? = null,
    val status: String = "pending",
)

data class ProfileDto(
    val name: String? = null,
    val email: String? = null,
    val gender: String? = null,
    @SerializedName("date_of_birth") val dateOfBirth: String? = null,
    @SerializedName("national_id") val nationalId: String? = null,
    @SerializedName("id_type") val idType: String? = null,
    @SerializedName("nationality_country_id") val nationalityCountryId: Int? = null,
    @SerializedName("city_id") val cityId: Int? = null,
    val locked: Boolean = false,
)

data class StepsDto(val profile: Boolean = false, val vehicle: Boolean = false, val documents: Boolean = false)

data class OnboardingDto(
    val driver: DriverDto,
    val profile: ProfileDto = ProfileDto(),
    val steps: StepsDto = StepsDto(),
    val vehicle: OnboardingVehicleDto? = null,
    @SerializedName("required_documents") val requiredDocuments: List<RequiredDocDto> = emptyList(),
    val missing: List<String> = emptyList(),
    @SerializedName("can_submit") val canSubmit: Boolean = false,
    @SerializedName("work_problem") val workProblem: String? = null,
    /** O-64: the captain signed up without a vehicle (riders' own cars, lessons). */
    @SerializedName("no_vehicle") val noVehicle: Boolean = false,
)

data class NamedDto(val id: Int, val name: String? = null, val code: String? = null)

data class ModelOptionDto(val id: Int, val name: String? = null, @SerializedName("body_type") val bodyType: String? = null, val seats: Int? = null)

data class MakeOptionDto(val id: Int, val name: String? = null, val models: List<ModelOptionDto> = emptyList())

data class ColorOptionDto(val id: Int, val name: String? = null, val hex: String? = null)

data class OnboardingOptionsDto(
    val makes: List<MakeOptionDto> = emptyList(),
    val colors: List<ColorOptionDto> = emptyList(),
    @SerializedName("body_types") val bodyTypes: List<String> = emptyList(),
    val genders: List<String> = emptyList(),
    @SerializedName("id_types") val idTypes: List<String> = emptyList(),
    val nationalities: List<NamedDto> = emptyList(),
    val cities: List<NamedDto> = emptyList(),
)

data class ReasonMessageDto(val code: String, val about: String? = null, val message: String? = null)

data class RegulatorDto(
    val regulator: String? = null,
    val required: Boolean = false,
    @SerializedName("driver_status") val driverStatus: String? = null,
    @SerializedName("vehicle_status") val vehicleStatus: String? = null,
    @SerializedName("driver_expires_at") val driverExpiresAt: String? = null,
    @SerializedName("criminal_record_status") val criminalRecordStatus: String? = null,
    val reasons: List<ReasonMessageDto> = emptyList(),
    @SerializedName("blocked_until") val blockedUntil: String? = null,
    @SerializedName("can_register") val canRegister: Boolean = true,
    @SerializedName("work_problem") val workProblem: String? = null,
)

// ---------------------------------------------------------------- requests & rides

data class PlaceDto(val lat: Double, val lng: Double, val address: String? = null)

data class RatingCriterionDto(val key: String, val label: String? = null)

data class OfferMoneyDto(
    @SerializedName("fare_minor") val fareMinor: Long = 0,
    @SerializedName("service_fee_minor") val serviceFeeMinor: Long = 0,
    @SerializedName("tax_minor") val taxMinor: Long = 0,
    @SerializedName("tax_on_driver") val taxOnDriver: Boolean = true,
    @SerializedName("net_minor") val netMinor: Long = 0,
)

data class MapConfigDto(val provider: String? = "google", @SerializedName("mapbox_token") val mapboxToken: String? = null, @SerializedName("mapbox_style") val mapboxStyle: String? = null)

data class TripPoolDto(@SerializedName("discount_minor") val discountMinor: Long = 0, val riders: Int = 1, @SerializedName("share_with") val shareWith: String? = null)

data class PoolLimitsDto(@SerializedName("min_m") val minM: Int = 1000, @SerializedName("max_m") val maxM: Int = 5000, val editable: Boolean = true)

data class PoolDto(val uuid: String, val mode: String = "route", val destination: PlaceDto, @SerializedName("radius_m") val radiusM: Int = 0, val seats: Int = 4, val rides: List<TripDto> = emptyList())

data class PoolStateDto(val radius: PoolLimitsDto = PoolLimitsDto(), val pool: PoolDto? = null)

data class CurrencyDto(val code: String? = null, val symbol: String? = null, @SerializedName("decimal_places") val decimals: Int = 2)

data class CategoryRefDto(val slug: String? = null, val name: String? = null)

data class StopDto(val seq: Int, val lat: Double, val lng: Double, val address: String? = null, val status: String? = null)

data class OfferDto(
    val uuid: String,
    @SerializedName("ride_uuid") val rideUuid: String,
    @SerializedName("expires_at") val expiresAt: String? = null,
    @SerializedName("seconds_left") val secondsLeft: Int = 0,
    @SerializedName("ttl_s") val ttlS: Int = 15,
    @SerializedName("pickup_distance_m") val pickupDistanceM: Int = 0,
    @SerializedName("pickup_eta_s") val pickupEtaS: Int = 0,
    @SerializedName("earning_minor") val earningMinor: Long? = null,
    /** O-28: the fare, DORR's service fee and the tax behind the earning. */
    val money: OfferMoneyDto? = null,
    @SerializedName("rider_rating") val riderRating: Double? = null,
    val category: CategoryRefDto? = null,
    @SerializedName("payment_method") val paymentMethod: String? = null,
    /** D-60, D-61: how many ride and the rider's pickup note. */
    val passengers: Int? = null,
    @SerializedName("pickup_note") val pickupNote: String? = null,
    /** R-233..R-237, D-59, D-390: what the rider added — a pet, child seats, a service animal, bags, priority… */
    val options: List<AddonDto> = emptyList(),
    /** R-13: booked for someone else — whom to pick up (first name only). */
    val beneficiary: BeneficiaryDto? = null,
    /** D-INN-07: the rider chose this captain from their favourites. */
    @SerializedName("from_circle") val fromCircle: Boolean = false,
    /** R-16..R-18, D-111: the approved points to meet / drop at. */
    @SerializedName("pickup_point") val pickupPoint: PickupPointDto? = null,
    @SerializedName("dropoff_point") val dropoffPoint: PickupPointDto? = null,
    val currency: CurrencyDto? = null,
    val origin: PlaceDto? = null,
    val destination: PlaceDto? = null,
    val stops: List<StopDto> = emptyList(),
    val route: RouteDto? = null,
    val bid: BidCardDto? = null,
    /** «راحتي»: how the rider likes the trip (requests, not guarantees). */
    val comfort: ComfortDto? = null,
    /** Block 06: offered while I'm finishing a ride — it starts after that ride ends. */
    @SerializedName("next_trip") val nextTrip: NextTripOfferDto? = null,
)

data class NextTripOfferDto(@SerializedName("after_ride_uuid") val afterRideUuid: String? = null, @SerializedName("finish_in_s") val finishInS: Int? = null)

data class CommissionDto(val type: String = "percent", val value: Long = 0)

data class TaxRuleDto(val base: String? = null, @SerializedName("rate_bp") val rateBp: Long = 0, @SerializedName("borne_by") val borneBy: String? = null)

data class MyBidDto(val uuid: String, val status: String, @SerializedName("price_minor") val priceMinor: Long, @SerializedName("expires_at") val expiresAt: String? = null)

/** What a driver may offer on a bidding request (§9.2). */
data class BidCardDto(
    @SerializedName("rider_price_minor") val riderPriceMinor: Long,
    @SerializedName("cap_minor") val capMinor: Long,
    @SerializedName("steps_minor") val stepsMinor: List<Long> = listOf(500, 1000, 1500),
    @SerializedName("net_at_rider_price_minor") val netAtRiderPriceMinor: Long? = null,
    val commission: CommissionDto = CommissionDto(),
    val tax: TaxRuleDto? = null,
    @SerializedName("my_bid") val myBid: MyBidDto? = null,
    @SerializedName("attempts_left") val attemptsLeft: Int? = null,
) {
    /** "What you'd earn" at a price — the same sum as the server (commission, its tax when the driver carries it). */
    fun netAt(price: Long): Long {
        val commission = if (commission.type == "fixed") minOf(commission.value, price) else price * commission.value / 10000
        val tax = if (tax != null && tax.borneBy != "rider") {
            val base = when (tax.base) { "fare", "fare_and_fees" -> price; else -> commission }
            Math.round(base * tax.rateBp / 10000.0)
        } else 0
        return price - commission - tax
    }
}

data class RouteDto(@SerializedName("distance_m") val distanceM: Int? = null, @SerializedName("duration_s") val durationS: Int? = null, val polyline: String? = null)

data class RiderDto(val name: String? = null, val rating: Double? = null, @SerializedName("trips_count") val tripsCount: Int = 0)

data class BeneficiaryDto(val name: String? = null)

/** D-276..D-281: the Airport screen — where the captain is, their place in line, the estimate, the official points. */
data class AirportQueueDto(
    val zone: ZoneRefDto? = null,
    val inside: Boolean = false,
    val queued: Boolean = false,
    val entry: String? = null,
    @SerializedName("entry_mode") val entryMode: String? = null,
    val position: Int? = null,
    val size: Int = 0,
    @SerializedName("estimated_wait_s") val estimatedWaitS: Int? = null,
    @SerializedName("joined_at") val joinedAt: String? = null,
    @SerializedName("can_join") val canJoin: Boolean = false,
    @SerializedName("cannot_join_reason") val cannotJoinReason: String? = null,
    @SerializedName("pickup_points") val pickupPoints: List<PickupPointDto> = emptyList(),
    val rules: AirportRulesDto? = null,
)

data class ZoneRefDto(val id: Int = 0, val name: String? = null)

data class AirportRulesDto(
    @SerializedName("leave_grace_s") val leaveGraceS: Int = 0,
    @SerializedName("max_wait_s") val maxWaitS: Int = 0,
    @SerializedName("stale_after_s") val staleAfterS: Int = 0,
)

/** D-282: an area where pickups aren't allowed (`blocked`) or only at approved points (`pickup_only`). */
data class RestrictedZoneDto(val id: Int = 0, val type: String? = null, val name: String? = null)

/** An approved pickup / drop-off point: a gate, terminal, entrance or parking, and how to find it. */
data class PickupPointDto(
    val id: Int = 0,
    val kind: String? = null,
    @SerializedName("kind_label") val kindLabel: String? = null,
    val name: String? = null,
    val instructions: String? = null,
    val lat: Double = 0.0,
    val lng: Double = 0.0,
)

/** O-56: `waypoints` = the stops still ahead (Google Maps' link carries them before the destination). */
data class NavigationDto(val lat: Double, val lng: Double, @SerializedName("google_maps_url") val googleMapsUrl: String, val waypoints: List<WaypointDto> = emptyList())

data class WaypointDto(val lat: Double, val lng: Double)

data class LineDto(val code: String, val label: String? = null, @SerializedName("amount_minor") val amountMinor: Long = 0)

data class TimesDto(
    @SerializedName("arrived_at") val arrivedAt: String? = null,
    /** The free waiting counts from here: a booked ride's time when the captain came early (D-119). */
    @SerializedName("waiting_from") val waitingFrom: String? = null,
    @SerializedName("started_at") val startedAt: String? = null,
)

/** How to connect to real-time and push (from the server, never built into the app). */
data class RealtimeConfigDto(
    val enabled: Boolean = false,
    val key: String? = null,
    val cluster: String? = null,
    val host: String? = null,
    val port: Int? = null,
    @SerializedName("use_tls") val useTls: Boolean = true,
    @SerializedName("auth_path") val authPath: String = "/broadcasting/auth",
    val channel: String? = null,
    @SerializedName("onesignal_app_id") val onesignalAppId: String? = null,
)

/** One row of the captain's notification list — already in the app's language. */
data class NotificationDto(
    val id: String,
    val title: String = "",
    val message: String = "",
    val type: String? = null,
    val event: String? = null,
    val data: JsonObject? = null,
    @SerializedName("created_at_iso") val createdAtIso: String? = null,
    @SerializedName("read_at") val readAt: String? = null,
)

data class UnreadCountDto(val count: Int = 0)

// ---------------------------------------------------------------- the captain's wallet

data class WalletDto(
    @SerializedName("currency_code") val currencyCode: String? = null,
    @SerializedName("currency_symbol") val currencySymbol: String? = null,
    @SerializedName("wallet_number_formatted") val walletNumber: String? = null,
    @SerializedName("total_minor") val totalMinor: Long = 0,
    @SerializedName("withdrawable_minor") val withdrawableMinor: Long = 0,
    @SerializedName("spend_only_minor") val spendOnlyMinor: Long = 0,
    @SerializedName("held_minor") val heldMinor: Long = 0,
)

data class PaymentMethodDto(
    val id: Int,
    val name: String? = null,
    val gateway: String? = null,
    @SerializedName("logo_url") val logoUrl: String? = null,
    @SerializedName("coming_soon") val comingSoon: Boolean = false,
)

data class TopupQuoteDto(
    @SerializedName("paid_amount_minor") val paidAmountMinor: Long = 0,
    @SerializedName("fee_minor") val feeMinor: Long = 0,
    @SerializedName("bonus_minor") val bonusMinor: Long = 0,
    @SerializedName("total_credited_minor") val totalCreditedMinor: Long = 0,
)

data class TopupDto(
    val uuid: String,
    val status: String = "pending",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("redirect_url") val redirectUrl: String? = null,
    @SerializedName("requires_otp") val requiresOtp: Boolean = false,
)

data class WalletRowDto(
    val uuid: String,
    val type: String = "",
    @SerializedName("type_label") val typeLabel: String = "",
    val direction: String = "credit",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    val note: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

/** One statement row in full (D-212): what it points at, the other rows of the same operation, whether it was reversed. */
data class WalletRefDto(val type: String = "", val uuid: String? = null, val number: String? = null, val status: String? = null)

data class WalletRelatedDto(
    val uuid: String,
    @SerializedName("type_label") val typeLabel: String = "",
    val direction: String = "credit",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class WalletDetailDto(
    val uuid: String,
    val type: String = "",
    @SerializedName("type_label") val typeLabel: String = "",
    val direction: String = "credit",
    val bucket: String = "",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("balance_after_minor") val balanceAfterMinor: Long? = null,
    @SerializedName("total_balance_after_minor") val totalBalanceAfterMinor: Long? = null,
    val note: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    val reference: WalletRefDto? = null,
    val related: List<WalletRelatedDto> = emptyList(),
    val reversed: Boolean = false,
    @SerializedName("reverses_uuid") val reversesUuid: String? = null,
)

data class WalletPinDto(
    @SerializedName("has_pin") val hasPin: Boolean = false,
    @SerializedName("must_change") val mustChange: Boolean = false,
    @SerializedName("is_frozen") val isFrozen: Boolean = false,
    @SerializedName("locked_until") val lockedUntil: String? = null,
)

data class WithdrawalMethodDto(
    val id: Int,
    val type: String = "bank",
    val label: String? = null,
    val display: String? = null,
    @SerializedName("is_favorite") val isFavorite: Boolean = false,
)

data class WithdrawalMethodShortDto(val type: String = "bank", val label: String? = null, val display: String? = null)

data class WithdrawalDto(
    val id: Int,
    val status: String = "pending",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("currency_code") val currencyCode: String? = null,
    val method: WithdrawalMethodShortDto? = null,
    @SerializedName("rejection_reason") val rejectionReason: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class TrustedContactDto(
    val id: Int,
    val name: String = "",
    val phone: String = "",
    @SerializedName("share_mode") val shareMode: String = "manual",
    @SerializedName("notify_arrival") val notifyArrival: Boolean = true,
)

data class BlockedPersonDto(val id: Int, val name: String? = null)

/** "Are you OK?" waiting for the captain (D-144). */
data class SafetyCheckDto(val uuid: String, val kind: String = "ride_check", val reason: String? = null)

data class DriverSafetyDto(
    val contacts: List<TrustedContactDto> = emptyList(),
    val blocked: List<BlockedPersonDto> = emptyList(),
    @SerializedName("emergency_number") val emergencyNumber: String = "911",
    @SerializedName("max_contacts") val maxContacts: Int = 5,
)

/** The rider asks for a new destination / one more stop (D-131..133). */
data class ChangeRequestDto(
    val uuid: String,
    val type: String = "destination",
    val status: String = "pending",
    val place: PlaceDto? = null,
    @SerializedName("distance_delta_m") val distanceDeltaM: Int = 0,
    @SerializedName("duration_delta_s") val durationDeltaS: Int = 0,
    @SerializedName("earning_delta_minor") val earningDeltaMinor: Long? = null,
    @SerializedName("new_earning_minor") val newEarningMinor: Long? = null,
    @SerializedName("seconds_left") val secondsLeft: Int = 30,
)

data class ShareDto(val url: String, val views: Int = 0)

data class SosDto(@SerializedName("incident_uuid") val incidentUuid: String? = null, @SerializedName("emergency_number") val emergencyNumber: String? = null)

data class SafetyDto(@SerializedName("emergency_number") val emergencyNumber: String? = null)

/** A ride booked ahead, in the scheduled list (D-260..269). */
data class ScheduledRideDto(
    val uuid: String,
    val number: String? = null,
    @SerializedName("scheduled_at") val scheduledAt: String? = null,
    @SerializedName("online_from") val onlineFrom: String? = null,
    @SerializedName("earning_minor") val earningMinor: Long? = null,
    @SerializedName("pickup_distance_m") val pickupDistanceM: Int? = null,
    @SerializedName("rider_rating") val riderRating: Double? = null,
    val mine: Boolean = false,
    @SerializedName("can_start") val canStart: Boolean = false,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("release_counts_as_cancel") val releaseCountsAsCancel: Boolean? = null,
    val category: CategoryRefDto? = null,
    @SerializedName("payment_method") val paymentMethod: String? = null,
    val currency: CurrencyDto? = null,
    val origin: PlaceDto? = null,
    val destination: PlaceDto? = null,
    val stops: List<StopDto> = emptyList(),
    val route: RouteDto? = null,
)

data class WaitingDto(
    @SerializedName("free_s") val freeS: Int = 180,
    @SerializedName("no_show_after_s") val noShowAfterS: Int = 300,
    /** D-122: the clock turns to a warning this long before the free minutes end. */
    @SerializedName("warn_before_s") val warnBeforeS: Int = 60,
)

/** D-129: the time and distance left to the next point (pickup / stop / destination). */
data class ProgressDto(val to: String = "pickup", @SerializedName("distance_m") val distanceM: Int = 0, @SerializedName("eta_s") val etaS: Int = 0)

/** O-58: one hot area — the requests in the last hour there, and how hot (0..1). */
data class HeatCellDto(val lat: Double, val lng: Double, val weight: Double = 0.0, val requests: Int = 0)

data class DispatchCategoryDto(val slug: String, val name: String? = null, val ok: Boolean = false, val reason: String? = null)

/** One dispatch condition (O-47); `detail` depends on the key (the categories list, the open requests…). */
data class DispatchCheckItemDto(val key: String, val ok: Boolean, val detail: com.google.gson.JsonElement? = null) {
    val categories: List<DispatchCategoryDto>
        get() = if (key == "categories" && detail?.isJsonArray == true) com.google.gson.Gson().fromJson(detail, Array<DispatchCategoryDto>::class.java).toList() else emptyList()
}

data class DispatchCheckDto(val ready: Boolean = false, val checks: List<DispatchCheckItemDto> = emptyList())

data class TripCallDto(val uuid: String, val status: String, val caller: String)

data class TripCallJoinDto(val url: String, val room: String, val token: String)

data class TripCallStartDto(val uuid: String, val status: String, val caller: String, val join: TripCallJoinDto)

data class TripHourlyDto(
    val hours: Int = 0,
    @SerializedName("hour_price_minor") val hourPriceMinor: Long = 0,
    @SerializedName("overtime_per_min_minor") val overtimePerMinMinor: Long = 0,
    @SerializedName("ends_at") val endsAt: String? = null,
)

data class TripDto(
    val uuid: String,
    val number: String? = null,
    val status: String,
    /** O-34: the planned route, drawn on the trip map. */
    val route: RouteDto? = null,
    /** O-30..O-32: sharing the car — what came off this fare, and how many ride now. */
    val pool: TripPoolDto? = null,
    /** O-17: booked by the hour — the hours, the extra per minute after them, when they run out. */
    val hourly: TripHourlyDto? = null,
    /** O-09: the in-app call ringing or going on now. */
    val call: TripCallDto? = null,
    /** O-10: the rider's messages not read yet (the badge on the chat button). */
    @SerializedName("unread_messages") val unreadMessages: Int = 0,
    /** O-09: the rider's number, to call from outside the app while the ride is on. */
    @SerializedName("call_phone") val callPhone: String? = null,
    val version: Int = 0,
    val category: CategoryRefDto? = null,
    @SerializedName("payment_method") val paymentMethod: String? = null,
    @SerializedName("payment_status") val paymentStatus: String? = null,
    val currency: CurrencyDto? = null,
    val origin: PlaceDto? = null,
    val destination: PlaceDto? = null,
    @SerializedName("open_destination") val openDestination: Boolean = false,
    val stops: List<StopDto> = emptyList(),
    val times: TimesDto? = null,
    val waiting: WaitingDto? = null,
    val rider: RiderDto? = null,
    val passengers: Int? = null,
    @SerializedName("pickup_note") val pickupNote: String? = null,
    /** R-233..R-237, D-59, D-390: what the rider added — a pet, child seats, a service animal, bags, priority… */
    val options: List<AddonDto> = emptyList(),
    val beneficiary: BeneficiaryDto? = null,
    @SerializedName("from_circle") val fromCircle: Boolean = false,
    @SerializedName("pickup_point") val pickupPoint: PickupPointDto? = null,
    @SerializedName("dropoff_point") val dropoffPoint: PickupPointDto? = null,
    val progress: ProgressDto? = null,
    @SerializedName("needs_pin") val needsPin: Boolean = false,
    @SerializedName("earning_minor") val earningMinor: Long? = null,
    @SerializedName("cash_to_collect_minor") val cashToCollectMinor: Long? = null,
    @SerializedName("final_total_minor") val finalTotalMinor: Long? = null,
    /** D-182: the rider's tip, apart from the fare — all of it the captain's. */
    @SerializedName("tip_minor") val tipMinor: Long? = null,
    val lines: List<LineDto> = emptyList(),
    val navigation: NavigationDto? = null,
    val safety: SafetyDto? = null,
    @SerializedName("change_request") val changeRequest: ChangeRequestDto? = null,
    @SerializedName("safety_check") val safetyCheck: SafetyCheckDto? = null,
    val rated: Boolean? = null,
    /** O-41: what the captain scores the rider on (manners, punctuality…), each with its own stars. */
    @SerializedName("rating_criteria") val ratingCriteria: List<RatingCriterionDto> = emptyList(),
    /** «راحتي»: what the rider asked for, and what I said can't be done. */
    val comfort: ComfortDto? = null,
    /** Block 06: the next trip I took while driving this one. */
    @SerializedName("next_trip") val nextTrip: NextTripDto? = null,
    /** Block 06: on a next trip — the ride it waits for. */
    @SerializedName("waiting_for_ride") val waitingForRide: RideRefDto? = null,
)

data class NextTripDto(
    val uuid: String,
    val number: String? = null,
    val rider: String? = null,
    val pickup: PlaceDto? = null,
    @SerializedName("earning_minor") val earningMinor: Long? = null,
)

data class RideRefDto(val uuid: String, val number: String? = null)

data class ComfortItemDto(val item: String, val label: String = "")

data class ComfortReasonDto(val value: String, val label: String = "")

data class ComfortUnableDto(val item: String? = null, val label: String? = null, val reason: String? = null, @SerializedName("reason_label") val reasonLabel: String? = null)

data class ComfortDto(
    val quiet: Boolean = false,
    val ac: String = "any",
    val music: String = "any",
    val items: List<ComfortItemDto> = emptyList(),
    val unable: List<ComfortUnableDto> = emptyList(),
    val reasons: List<ComfortReasonDto> = emptyList(),
    val disclaimer: String? = null,
)

// ---------------------------------------------------------------- Block 06: demand and DORR Driver AI

data class HotZoneDto(
    val lat: Double, val lng: Double,
    val requests: Int = 0, val unmet: Int = 0, val captains: Int = 0,
    val score: Double = 0.0, val shortage: Boolean = false,
    @SerializedName("distance_m") val distanceM: Int = 0,
    val why: String? = null,
)

data class ForecastZoneDto(val lat: Double, val lng: Double, val expected: Double = 0.0, val confidence: Double = 0.0, @SerializedName("distance_m") val distanceM: Int = 0)

data class ForecastSlotDto(@SerializedName("in_min") val inMin: Int, val zones: List<ForecastZoneDto> = emptyList())

data class ScheduledDemandDto(val hour: String, val booked: Int = 0, val usual: Double = 0.0)

data class AiAccessDto(val available: Boolean = false, val allowed: Boolean = false, val reason: String? = null)

data class DemandDto(
    @SerializedName("hot_zones") val hotZones: List<HotZoneDto> = emptyList(),
    val ai: AiAccessDto = AiAccessDto(),
    val forecast: List<ForecastSlotDto>? = null,
    val scheduled: List<ScheduledDemandDto>? = null,
)

data class DriverAiCardDto(
    val type: String,
    val title: String? = null,
    val text: String? = null,
    val why: String? = null,
    val confidence: Double? = null,
    val lat: Double? = null,
    val lng: Double? = null,
    @SerializedName("ride_uuid") val rideUuid: String? = null,
    @SerializedName("goal_minor") val goalMinor: Long? = null,
    @SerializedName("earned_minor") val earnedMinor: Long? = null,
    @SerializedName("left_minor") val leftMinor: Long? = null,
    val progress: Double? = null,
)

data class DriverAiDto(val allowed: Boolean = false, val reason: String? = null, val cards: List<DriverAiCardDto> = emptyList(), @SerializedName("goal_minor") val goalMinor: Long? = null)

data class DriverAiAnswerDto(val answer: String = "", val confidence: Double? = null)

data class AiPlanDto(
    val id: Int,
    val name: String = "",
    val description: String? = null,
    val price: Double? = null,
    @SerializedName("original_price") val originalPrice: Double? = null,
    val currency: String? = null,
    @SerializedName("duration_days") val durationDays: Int? = null,
    @SerializedName("is_trial") val isTrial: Boolean = false,
    @SerializedName("is_featured") val isFeatured: Boolean = false,
    val badge: String? = null,
    /** Whatever the admin wrote for the plan (a list of lines, or objects). */
    val features: com.google.gson.JsonElement? = null,
)

data class ReasonDto(val id: Int, val label: String? = null)

data class TagDto(val id: Int, val label: String? = null, val sentiment: String? = null)

data class MessageDto(val id: Long, val from: String, val mine: Boolean? = null, val body: String = "", @SerializedName("created_at") val createdAt: String? = null)

data class QuickDto(val key: String, val text: String)

data class ChatDto(val open: Boolean = false, @SerializedName("quick_replies") val quickReplies: List<QuickDto> = emptyList(), val messages: List<MessageDto> = emptyList())

// ---------------------------------------------------------------- earnings

data class PeriodDto(
    val trips: Int = 0,
    @SerializedName("net_minor") val netMinor: Long = 0,
    @SerializedName("gross_minor") val grossMinor: Long = 0,
    @SerializedName("tips_minor") val tipsMinor: Long = 0,
    @SerializedName("online_s") val onlineS: Long = 0,
    @SerializedName("distance_m") val distanceM: Long = 0,
    @SerializedName("acceptance_bp") val acceptanceBp: Int? = null,
)

data class DayDto(val date: String, val trips: Int = 0, @SerializedName("net_minor") val netMinor: Long = 0)

data class ProfileStatsDto(
    val rating: Double? = null,
    @SerializedName("rating_count") val ratingCount: Int = 0,
    @SerializedName("trips_count") val tripsCount: Int = 0,
    val tier: String? = null,
    val points: Int = 0,
)

data class PointEntryDto(val event: String, val label: String? = null, val points: Int = 0, val at: String? = null)

data class EarningsDto(
    val currency: CurrencyDto? = null,
    val today: PeriodDto = PeriodDto(),
    val week: PeriodDto = PeriodDto(),
    val month: PeriodDto = PeriodDto(),
    val days: List<DayDto> = emptyList(),
    @SerializedName("pending_minor") val pendingMinor: Long = 0,
    @SerializedName("wallet_minor") val walletMinor: Long = 0,
    val profile: ProfileStatsDto = ProfileStatsDto(),
    @SerializedName("recent_points") val recentPoints: List<PointEntryDto> = emptyList(),
)

// ---------------------------------------------------------------- earnings report (D-193..D-202)

data class ReportPeriodDto(val key: String = "3m", val from: String = "", val to: String = "", val days: Int = 0)

/** This period against the one before: the difference, its share in basis points (none when there was nothing before) and a word for the direction. */
data class ChangeDto(val value: Long = 0, @SerializedName("pct_bp") val pctBp: Int? = null, val direction: String = "same")

data class ReportChangesDto(
    @SerializedName("net_minor") val net: ChangeDto = ChangeDto(),
    @SerializedName("gross_minor") val gross: ChangeDto = ChangeDto(),
    val trips: ChangeDto = ChangeDto(),
    @SerializedName("tips_minor") val tips: ChangeDto = ChangeDto(),
    @SerializedName("online_s") val online: ChangeDto = ChangeDto(),
)

data class ReportPreviousDto(val from: String = "", val to: String = "", val totals: PeriodDto = PeriodDto())

data class SeriesPointDto(val key: String, val trips: Int = 0, @SerializedName("net_minor") val netMinor: Long = 0)

data class SeriesDto(val unit: String = "day", val points: List<SeriesPointDto> = emptyList())

data class EarningsReportDto(
    val currency: CurrencyDto? = null,
    val period: ReportPeriodDto = ReportPeriodDto(),
    val totals: PeriodDto = PeriodDto(),
    val previous: ReportPreviousDto = ReportPreviousDto(),
    val change: ReportChangesDto = ReportChangesDto(),
    val series: SeriesDto = SeriesDto(),
)

// ---------------------------------------------------------------- driving preferences (D-32..D-37)

data class PrefCategoryDto(val slug: String, val name: String? = null, val eligible: Boolean = false, val selected: Boolean = false)

data class PriceFilterDto(
    val allowed: Boolean = false,
    @SerializedName("min_fare_minor") val minFareMinor: Long? = null,
    @SerializedName("min_per_km_minor") val minPerKmMinor: Long? = null,
)

data class PaymentFilterDto(val allowed: Boolean = false, val methods: List<String> = emptyList(), val options: List<String> = emptyList())

data class AutoAcceptDto(
    val available: Boolean = false,
    val enabled: Boolean = false,
    @SerializedName("max_pickup_m") val maxPickupM: Int? = null,
    @SerializedName("price_filter") val priceFilter: PriceFilterDto = PriceFilterDto(),
    @SerializedName("payment_filter") val paymentFilter: PaymentFilterDto = PaymentFilterDto(),
)

/** One add-on of a request (`key`: wait_save / priority / child_seats / pet / service_animal / luggage). */
data class AddonDto(val key: String, val label: String = "", val count: Int? = null)

/** R-235, R-236, D-390: what the car has / takes, and whether those add-ons are open in the country. */
data class EquipmentDto(
    @SerializedName("pet_friendly") val petFriendly: Boolean = false,
    @SerializedName("child_seats") val childSeats: Int = 0,
    @SerializedName("pets_offered") val petsOffered: Boolean = false,
    @SerializedName("child_seats_offered") val childSeatsOffered: Boolean = false,
)

data class PreferencesDto(
    val currency: CurrencyDto? = null,
    val equipment: EquipmentDto = EquipmentDto(),
    val categories: List<PrefCategoryDto> = emptyList(),
    @SerializedName("pickup_radius_m") val pickupRadiusM: Int? = null,
    @SerializedName("pickup_radius_max_m") val pickupRadiusMaxM: Int? = null,
    @SerializedName("auto_accept") val autoAccept: AutoAcceptDto = AutoAcceptDto(),
    /** Block 06: a next trip near where my ride ends (when the country has it), and my daily earnings goal. */
    @SerializedName("next_trips") val nextTrips: NextTripsPrefDto = NextTripsPrefDto(),
    @SerializedName("daily_goal_minor") val dailyGoalMinor: Long? = null,
)

data class NextTripsPrefDto(val available: Boolean = false, val enabled: Boolean = true)

// ---------------------------------------------------------------- driving time (D-42, D-43)

data class DrivingHoursDto(
    val enabled: Boolean = false,
    @SerializedName("window_h") val windowH: Int = 24,
    @SerializedName("used_s") val usedS: Long = 0,
    @SerializedName("warn_s") val warnS: Long = 0,
    @SerializedName("max_s") val maxS: Long = 0,
    @SerializedName("remaining_s") val remainingS: Long? = null,
    /** ok | warn | limit */
    val level: String = "ok",
    @SerializedName("free_at") val freeAt: String? = null,
)

// ---------------------------------------------------------------- cases: disputes and unpaid-ride reports (D-229..D-238)

data class CaseOptionDto(val code: String, val label: String = "")

data class CaseOptionsDto(
    val available: Boolean = false,
    val kinds: List<CaseOptionDto> = emptyList(),
    @SerializedName("dispute_reasons") val disputeReasons: List<CaseOptionDto> = emptyList(),
    @SerializedName("max_files") val maxFiles: Int = 6,
)

data class CaseRideRefDto(val uuid: String = "", val number: String? = null, val status: String? = null)

data class CaseMessageDto(val id: Long = 0, val from: String = "", val body: String = "", val at: String? = null)

data class CaseFileDto(val id: Long = 0, val name: String = "", val mime: String? = null, val size: Long = 0, val url: String = "", val mine: Boolean = false)

data class CaseDecisionDto(
    val outcome: String = "",
    val reason: String? = null,
    @SerializedName("decided_at") val decidedAt: String? = null,
)

data class CaseMilestoneDto(
    val event: String = "",
    val at: String? = null,
    @SerializedName("distance_to_pickup_m") val distanceToPickupM: Int? = null,
)

data class CaseLineDto(val code: String = "", @SerializedName("amount_minor") val amountMinor: Long = 0)

data class CaseWaitingDto(@SerializedName("pickup_s") val pickupS: Long = 0, @SerializedName("free_s") val freeS: Long = 0)

data class CaseRouteDto(@SerializedName("planned_m") val plannedM: Long? = null, @SerializedName("driven_m") val drivenM: Long? = null)

data class CaseEvidenceDto(
    val timeline: List<CaseMilestoneDto> = emptyList(),
    val waiting: CaseWaitingDto? = null,
    val route: CaseRouteDto? = null,
    val lines: List<CaseLineDto> = emptyList(),
)

/** A case as the captain sees it: the list carries the headline only, the detail adds the rest. */
data class CaseDto(
    val uuid: String = "",
    val number: String = "",
    val kind: String = "",
    @SerializedName("kind_label") val kindLabel: String = "",
    @SerializedName("category_label") val categoryLabel: String = "",
    val status: String = "submitted",
    @SerializedName("status_label") val statusLabel: String = "",
    val decided: Boolean = false,
    val summary: String? = null,
    val ride: CaseRideRefDto? = null,
    @SerializedName("disputed_minor") val disputedMinor: Long? = null,
    val currency: CurrencyDto? = null,
    val evidence: CaseEvidenceDto? = null,
    val messages: List<CaseMessageDto> = emptyList(),
    val files: List<CaseFileDto> = emptyList(),
    val decision: CaseDecisionDto? = null,
    @SerializedName("can_message") val canMessage: Boolean = false,
    @SerializedName("can_appeal") val canAppeal: Boolean = false,
    @SerializedName("appealed_at") val appealedAt: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

/** One finished ride in the captain's recent list — what a dispute or an unpaid report is about. */
data class DriverRideItemDto(
    val uuid: String = "",
    val number: String? = null,
    val status: String = "",
    val category: String? = null,
    @SerializedName("origin_address") val originAddress: String? = null,
    @SerializedName("destination_address") val destinationAddress: String? = null,
    @SerializedName("earning_minor") val earningMinor: Long? = null,
    @SerializedName("payment_method") val paymentMethod: String? = null,
    val currency: String? = null,
    @SerializedName("completed_at") val completedAt: String? = null,
)

data class DriverRideListDto(val items: List<DriverRideItemDto> = emptyList())

// ---------------------------------------------------------------- rank and performance (D-239..D-259)

data class RankDto(
    val id: Long,
    val slug: String = "",
    val name: String? = null,
    val color: String? = null,
    val probation: Boolean = false,
    val benefits: List<String> = emptyList(),
    val thresholds: Map<String, Int> = emptyMap(),
    val current: Boolean = false,
    val missing: List<RankMissingDto> = emptyList(),
)

data class RankMissingDto(val metric: String, val need: Int = 0, val have: Int = 0, val min: Boolean = true)

data class RankMetricsDto(
    val trips: Int = 0,
    val rating: Int = 0,
    val cleanliness: Int = 0,
    val behavior: Int = 0,
    @SerializedName("commitment_bp") val commitmentBp: Int = 10000,
    @SerializedName("completion_bp") val completionBp: Int = 10000,
    @SerializedName("cancel_bp") val cancelBp: Int = 0,
    val warnings: Int = 0,
    @SerializedName("window_days") val windowDays: Int = 90,
)

data class ProbationDto(val active: Boolean = false, @SerializedName("days_left") val daysLeft: Int = 0, @SerializedName("ends_at") val endsAt: String? = null)

data class RankFallingDto(val to: RankDto? = null, @SerializedName("due_at") val dueAt: String? = null, val missing: List<RankMissingDto> = emptyList())

data class PerformanceNoteDto(
    val id: Long,
    val kind: String = "note",
    val title: String = "",
    val body: String? = null,
    @SerializedName("appeal_status") val appealStatus: String = "none",
    @SerializedName("appeal_text") val appealText: String? = null,
    @SerializedName("appeal_answer") val appealAnswer: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class RankInfoDto(
    val probation: ProbationDto = ProbationDto(),
    val rank: RankDto? = null,
    val next: RankDto? = null,
    val metrics: RankMetricsDto = RankMetricsDto(),
    val falling: RankFallingDto? = null,
    val ranks: List<RankDto> = emptyList(),
    @SerializedName("appeal_allowed") val appealAllowed: Boolean = false,
    val notes: List<PerformanceNoteDto> = emptyList(),
)

// ---------------------------------------------------------------- payouts and statements (D-216..D-228)

data class PayoutIntlDto(val status: String = "none", val answer: String? = null, @SerializedName("requested_at") val requestedAt: String? = null)

data class PayoutStatusDto(
    @SerializedName("kyc_ok") val kycOk: Boolean = true,
    @SerializedName("kyc_reason") val kycReason: String? = null,
    @SerializedName("method_hold_hours") val methodHoldHours: Int = 0,
    @SerializedName("third_party_allowed") val thirdPartyAllowed: Boolean = true,
    val international: PayoutIntlDto = PayoutIntlDto(),
    val frozen: Boolean = false,
)

data class InvoiceLineDto(
    @SerializedName("ride_number") val rideNumber: String? = null,
    val date: String? = null,
    @SerializedName("fare_minor") val fareMinor: Long = 0,
    @SerializedName("commission_minor") val commissionMinor: Long = 0,
    @SerializedName("tax_minor") val taxMinor: Long = 0,
    @SerializedName("net_minor") val netMinor: Long = 0,
)

data class InvoiceNoteDto(val kind: String = "credit", val type: String? = null, @SerializedName("amount_minor") val amountMinor: Long = 0, val title: String? = null, val date: String? = null)

data class InvoiceDto(
    val month: String,
    val number: String = "",
    val trips: Int = 0,
    @SerializedName("fares_minor") val faresMinor: Long = 0,
    @SerializedName("commission_minor") val commissionMinor: Long = 0,
    @SerializedName("tax_minor") val taxMinor: Long = 0,
    @SerializedName("net_minor") val netMinor: Long = 0,
    @SerializedName("credit_notes_minor") val creditNotesMinor: Long = 0,
    @SerializedName("debit_notes_minor") val debitNotesMinor: Long = 0,
    @SerializedName("notes_count") val notesCount: Int = 0,
    val lines: List<InvoiceLineDto> = emptyList(),
    val notes: List<InvoiceNoteDto> = emptyList(),
)

// ---------------------------------------------------------------- support and lost items (D-285..D-297)

data class HelpNodeDto(val id: Long, val title: String? = null, val answer: String? = null, val children: List<HelpNodeDto> = emptyList())

data class SupportCategoryDto(val key: String, val label: String = "")

data class SupportHelpDto(
    @SerializedName("open_now") val openNow: Boolean = true,
    val hours: String? = null,
    val categories: List<SupportCategoryDto> = emptyList(),
    val results: List<HelpNodeDto> = emptyList(),
    val items: List<HelpNodeDto> = emptyList(),
)

data class SupportLastMessageDto(val sender: String? = null, val body: String? = null)

data class SupportTicketDto(
    val id: Long,
    val number: String = "",
    val title: String = "",
    val status: String = "opened",
    @SerializedName("accepts_replies") val acceptsReplies: Boolean = true,
    @SerializedName("last_message") val lastMessage: SupportLastMessageDto? = null,
)

data class SupportMessageDto(
    val id: Long,
    val sender: String = "user",
    val body: String? = null,
    @SerializedName("image_url") val imageUrl: String? = null,
    @SerializedName("agent_name") val agentName: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

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

// ---------------------------------------------------------------- settings and account (D-304..D-318)

data class AlertsDto(val sound: Boolean = true, val vibration: Boolean = true, val voice: Boolean = false)

data class DeviceDto(val id: Long, val device: String = "", val current: Boolean = false, @SerializedName("last_seen_at") val lastSeenAt: String? = null)

data class CloseBlockerDto(val code: String, val label: String = "")

data class CloseCheckDto(@SerializedName("can_close") val canClose: Boolean = false, val blockers: List<CloseBlockerDto> = emptyList())

data class LegalDocDto(
    val type: String,
    val version: String = "",
    @SerializedName("updated_at") val updatedAt: String? = null,
    val title: String? = null,
    val content: String? = null,
    @SerializedName("needs_acceptance") val needsAcceptance: Boolean = false,
)

// ---------------------------------------------------------------- exceptional states (D-329..D-344)

data class PausedCategoryDto(val slug: String, val name: String = "")

data class EmergencyReviewDto(@SerializedName("ride_number") val rideNumber: String? = null, val since: String? = null, val until: String? = null)

data class LastWithdrawalDto(
    val id: Long,
    val state: String = "pending",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    val reason: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class ServiceStatusDto(
    val maintenance: Boolean = false,
    @SerializedName("maintenance_message") val maintenanceMessage: String? = null,
    @SerializedName("safe_mode") val safeMode: Boolean = false,
    @SerializedName("service_on") val serviceOn: Boolean = true,
    @SerializedName("paused_categories") val pausedCategories: List<PausedCategoryDto> = emptyList(),
    @SerializedName("emergency_review") val emergencyReview: EmergencyReviewDto? = null,
    @SerializedName("last_withdrawal") val lastWithdrawal: LastWithdrawalDto? = null,
)
