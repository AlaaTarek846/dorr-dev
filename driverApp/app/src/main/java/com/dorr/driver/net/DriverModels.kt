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
)

data class NamedDto(val id: Int, val name: String? = null)

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
    @SerializedName("rider_rating") val riderRating: Double? = null,
    val category: CategoryRefDto? = null,
    @SerializedName("payment_method") val paymentMethod: String? = null,
    /** D-60, D-61: how many ride and the rider's pickup note. */
    val passengers: Int? = null,
    @SerializedName("pickup_note") val pickupNote: String? = null,
    /** R-13: booked for someone else — whom to pick up (first name only). */
    val beneficiary: BeneficiaryDto? = null,
    /** R-16..R-18, D-111: the approved points to meet / drop at. */
    @SerializedName("pickup_point") val pickupPoint: PickupPointDto? = null,
    @SerializedName("dropoff_point") val dropoffPoint: PickupPointDto? = null,
    val currency: CurrencyDto? = null,
    val origin: PlaceDto? = null,
    val destination: PlaceDto? = null,
    val stops: List<StopDto> = emptyList(),
    val route: RouteDto? = null,
    val bid: BidCardDto? = null,
)

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

data class RouteDto(@SerializedName("distance_m") val distanceM: Int? = null, @SerializedName("duration_s") val durationS: Int? = null)

data class RiderDto(val name: String? = null, val rating: Double? = null, @SerializedName("trips_count") val tripsCount: Int = 0)

data class BeneficiaryDto(val name: String? = null)

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

data class NavigationDto(val lat: Double, val lng: Double, @SerializedName("google_maps_url") val googleMapsUrl: String)

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

data class TripDto(
    val uuid: String,
    val number: String? = null,
    val status: String,
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
    val beneficiary: BeneficiaryDto? = null,
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
)

data class ReasonDto(val id: Int, val label: String? = null)

data class TagDto(val id: Int, val label: String? = null, val sentiment: String? = null)

data class MessageDto(val id: Long, val from: String, val mine: Boolean? = null, val body: String = "")

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
