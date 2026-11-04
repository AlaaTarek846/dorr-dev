import Foundation

// driverApp `net/DriverModels.kt` — every DTO of the captain's API, same names and field names. Every field is optional (the
// Kotlin default goes at the use site: `dto.secondsLeft ?? 0`); Kotlin `Long` → `Int`, Gson `JsonElement` / `JsonObject` → `JSONAny`.
// Read with the snake_case decoder (`online_since` → `onlineSince`); `CodingKeys` only where the Kotlin name differs from the
// server's key. `PaymentMethodDto` / `TopupQuoteDto` live in `DriverApi` here (`DriverApi.PaymentMethodDto`): the wallet's
// `wallet/net` declares its own under the same names.

// MARK: - presence

struct PresenceDto: Decodable, Equatable {
    var status: String?
    var onlineSince: String?
    var lastRideRequested: Bool?
    var gpsEnabled: Bool?
    var canGoOnline: Bool?
    var reason: String?
    var activeRideId: Int?
    var pausedUntil: String?
}

// MARK: - signing up

struct DocTypeDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var code: String?
    var name: String?
    var instructions: String?
    var appliesTo: String?
    var hasNumber: Bool?
    var hasExpiry: Bool?
    var needsBackSide: Bool?
}

struct MyDocumentDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var status: String?
    var rejectionReason: String?
    var number: String?
    var expiresAt: String?
    var frontUrl: String?
    var backUrl: String?
}

struct RequiredDocDto: Decodable, Equatable {
    var type: DocTypeDto?
    var document: MyDocumentDto?
}

struct OnboardingVehicleDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var makeId: Int?
    var modelId: Int?
    var colorId: Int?
    var make: String?
    var model: String?
    var color: String?
    var colorHex: String?
    var year: Int?
    var bodyType: String?
    var seats: Int?
    var plateLetters: String?
    var plateNumber: String?
    var plateType: Int?
    var sequenceNumber: String?
    var status: String?
}

struct ProfileDto: Decodable, Equatable {
    var name: String?
    var email: String?
    var gender: String?
    var dateOfBirth: String?
    var nationalId: String?
    var idType: String?
    var nationalityCountryId: Int?
    var cityId: Int?
    var locked: Bool?
}

struct StepsDto: Decodable, Equatable {
    var profile: Bool?
    var vehicle: Bool?
    var documents: Bool?
}

struct OnboardingDto: Decodable, Equatable {
    var driver: DriverDto?
    var profile: ProfileDto?
    var steps: StepsDto?
    var vehicle: OnboardingVehicleDto?
    var requiredDocuments: [RequiredDocDto]?
    var missing: [String]?
    var canSubmit: Bool?
    var workProblem: String?
    /// O-64: the captain signed up without a vehicle (riders' own cars, lessons).
    var noVehicle: Bool?
}

struct NamedDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var name: String?
    var code: String?
}

struct ModelOptionDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var name: String?
    var bodyType: String?
    var seats: Int?
}

struct MakeOptionDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var name: String?
    var models: [ModelOptionDto]?
}

struct ColorOptionDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var name: String?
    var hex: String?
}

struct OnboardingOptionsDto: Decodable, Equatable {
    var makes: [MakeOptionDto]?
    var colors: [ColorOptionDto]?
    var bodyTypes: [String]?
    var genders: [String]?
    var idTypes: [String]?
    var nationalities: [NamedDto]?
    var cities: [NamedDto]?
}

struct ReasonMessageDto: Decodable, Equatable {
    var code: String?
    var about: String?
    var message: String?
}

struct RegulatorDto: Decodable, Equatable {
    var regulator: String?
    var required: Bool?
    var driverStatus: String?
    var vehicleStatus: String?
    var driverExpiresAt: String?
    var criminalRecordStatus: String?
    var reasons: [ReasonMessageDto]?
    var blockedUntil: String?
    var canRegister: Bool?
    var workProblem: String?
}

// MARK: - requests & rides

struct PlaceDto: Decodable, Equatable {
    var lat: Double?
    var lng: Double?
    var address: String?
}

struct RatingCriterionDto: Decodable, Equatable {
    var key: String?
    var label: String?
}

struct OfferMoneyDto: Decodable, Equatable {
    var fareMinor: Int?
    var serviceFeeMinor: Int?
    var taxMinor: Int?
    var taxOnDriver: Bool?
    var netMinor: Int?
}

struct MapConfigDto: Decodable, Equatable {
    var provider: String?
    var mapboxToken: String?
    var mapboxStyle: String?
}

struct TripPoolDto: Decodable, Equatable {
    var discountMinor: Int?
    var riders: Int?
    var shareWith: String?
}

struct PoolLimitsDto: Decodable, Equatable {
    var minM: Int?
    var maxM: Int?
    var editable: Bool?
}

struct PoolDto: Decodable, Equatable {
    var uuid: String?
    var mode: String?
    var destination: PlaceDto?
    var radiusM: Int?
    var seats: Int?
    var rides: [TripDto]?
}

struct PoolStateDto: Decodable, Equatable {
    var radius: PoolLimitsDto?
    var pool: PoolDto?
}

struct CurrencyDto: Decodable, Equatable {
    var code: String?
    var symbol: String?
    var decimals: Int?

    enum CodingKeys: String, CodingKey {
        case code
        case symbol
        case decimals = "decimalPlaces"
    }
}

struct CategoryRefDto: Decodable, Equatable {
    var slug: String?
    var name: String?
}

struct StopDto: Decodable, Equatable {
    var seq: Int?
    var lat: Double?
    var lng: Double?
    var address: String?
    var status: String?
}

struct OfferDto: Decodable, Equatable {
    var uuid: String?
    var rideUuid: String?
    var expiresAt: String?
    var secondsLeft: Int?
    var ttlS: Int?
    var pickupDistanceM: Int?
    var pickupEtaS: Int?
    var earningMinor: Int?
    /// O-28: the fare, DORR's service fee and the tax behind the earning.
    var money: OfferMoneyDto?
    var riderRating: Double?
    var category: CategoryRefDto?
    var paymentMethod: String?
    /// D-60, D-61: how many ride and the rider's pickup note.
    var passengers: Int?
    var pickupNote: String?
    /// R-233..R-237, D-59, D-390: what the rider added — a pet, child seats, a service animal, bags, priority…
    var options: [AddonDto]?
    /// R-13: booked for someone else — whom to pick up (first name only).
    var beneficiary: BeneficiaryDto?
    /// D-INN-07: the rider chose this captain from their favourites.
    var fromCircle: Bool?
    /// R-16..R-18, D-111: the approved points to meet / drop at.
    var pickupPoint: PickupPointDto?
    var dropoffPoint: PickupPointDto?
    var currency: CurrencyDto?
    var origin: PlaceDto?
    var destination: PlaceDto?
    var stops: [StopDto]?
    var route: RouteDto?
    var bid: BidCardDto?
    /// «راحتي»: how the rider likes the trip (requests, not guarantees).
    var comfort: ComfortDto?
    /// Block 06: offered while I'm finishing a ride — it starts after that ride ends.
    var nextTrip: NextTripOfferDto?
}

struct NextTripOfferDto: Decodable, Equatable {
    var afterRideUuid: String?
    var finishInS: Int?
}

struct CommissionDto: Decodable, Equatable {
    var type: String?
    var value: Int?
}

struct TaxRuleDto: Decodable, Equatable {
    var base: String?
    var rateBp: Int?
    var borneBy: String?
}

struct MyBidDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
    var priceMinor: Int?
    var expiresAt: String?
}

/// What a driver may offer on a bidding request (§9.2).
struct BidCardDto: Decodable, Equatable {
    var riderPriceMinor: Int?
    var capMinor: Int?
    var stepsMinor: [Int]?
    var netAtRiderPriceMinor: Int?
    var commission: CommissionDto?
    var tax: TaxRuleDto?
    var myBid: MyBidDto?
    var attemptsLeft: Int?

    /// "What you'd earn" at a price — the same sum as the server (commission, its tax when the driver carries it).
    func netAt(_ price: Int) -> Int {
        let rule = self.commission ?? CommissionDto()
        let commission = (rule.type ?? "percent") == "fixed" ? min(rule.value ?? 0, price) : price * (rule.value ?? 0) / 10000
        var taxed = 0
        if let tax, tax.borneBy != "rider" {
            let base: Int
            switch tax.base {
            case "fare", "fare_and_fees": base = price
            default: base = commission
            }
            // Math.round: half up.
            taxed = Int((Double(base * (tax.rateBp ?? 0)) / 10000.0 + 0.5).rounded(.down))
        }
        return price - commission - taxed
    }
}

struct RouteDto: Decodable, Equatable {
    var distanceM: Int?
    var durationS: Int?
    var polyline: String?
}

/// [isVerified]: the rider's account verification tick (Dorr account verification, AV-028).
struct RiderDto: Decodable, Equatable {
    var name: String?
    var rating: Double?
    var tripsCount: Int?
    var isVerified: Bool?
}

struct BeneficiaryDto: Decodable, Equatable {
    var name: String?
}

/// D-276..D-281: the Airport screen — where the captain is, their place in line, the estimate, the official points.
struct AirportQueueDto: Decodable, Equatable {
    var zone: ZoneRefDto?
    var inside: Bool?
    var queued: Bool?
    var entry: String?
    var entryMode: String?
    var position: Int?
    var size: Int?
    var estimatedWaitS: Int?
    var joinedAt: String?
    var canJoin: Bool?
    var cannotJoinReason: String?
    var pickupPoints: [PickupPointDto]?
    var rules: AirportRulesDto?
}

struct ZoneRefDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var name: String?
}

struct AirportRulesDto: Decodable, Equatable {
    var leaveGraceS: Int?
    var maxWaitS: Int?
    var staleAfterS: Int?
}

/// D-282: an area where pickups aren't allowed (`blocked`) or only at approved points (`pickup_only`).
struct RestrictedZoneDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var type: String?
    var name: String?
}

/// An approved pickup / drop-off point: a gate, terminal, entrance or parking, and how to find it.
struct PickupPointDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var kind: String?
    var kindLabel: String?
    var name: String?
    var instructions: String?
    var lat: Double?
    var lng: Double?
}

/// O-56: `waypoints` = the stops still ahead (Google Maps' link carries them before the destination).
struct NavigationDto: Decodable, Equatable {
    var lat: Double?
    var lng: Double?
    var googleMapsUrl: String?
    var waypoints: [WaypointDto]?
}

struct WaypointDto: Decodable, Equatable {
    var lat: Double?
    var lng: Double?
}

struct LineDto: Decodable, Equatable {
    var code: String?
    var label: String?
    var amountMinor: Int?
}

struct TimesDto: Decodable, Equatable {
    var arrivedAt: String?
    /// The free waiting counts from here: a booked ride's time when the captain came early (D-119).
    var waitingFrom: String?
    var startedAt: String?
}

/// How to connect to real-time and push (from the server, never built into the app).
struct RealtimeConfigDto: Decodable, Equatable {
    var enabled: Bool?
    var key: String?
    var cluster: String?
    var host: String?
    var port: Int?
    var useTls: Bool?
    var authPath: String?
    var channel: String?
    var onesignalAppId: String?
}

/// One row of the captain's notification list — already in the app's language.
struct NotificationDto: Decodable, Equatable, Identifiable {
    var id: String?
    var title: String?
    var message: String?
    var type: String?
    var event: String?
    var data: JSONAny?
    var createdAtIso: String?
    var readAt: String?
}

struct UnreadCountDto: Decodable, Equatable {
    var count: Int?
}

// MARK: - the captain's wallet

struct WalletDto: Decodable, Equatable {
    var currencyCode: String?
    var currencySymbol: String?
    var walletNumber: String?
    var totalMinor: Int?
    var withdrawableMinor: Int?
    var spendOnlyMinor: Int?
    var heldMinor: Int?

    enum CodingKeys: String, CodingKey {
        case currencyCode
        case currencySymbol
        case walletNumber = "walletNumberFormatted"
        case totalMinor
        case withdrawableMinor
        case spendOnlyMinor
        case heldMinor
    }
}

extension DriverApi {
    /// Nested: `wallet/net` declares its own `PaymentMethodDto` (another Kotlin package, the same Swift module).
    struct PaymentMethodDto: Decodable, Equatable, Identifiable {
        var id: Int?
        var name: String?
        var gateway: String?
        var logoUrl: String?
        var comingSoon: Bool?
    }
}

extension DriverApi {
    /// Nested: `wallet/net` declares its own `TopupQuoteDto` (another Kotlin package, the same Swift module).
    struct TopupQuoteDto: Decodable, Equatable {
        var paidAmountMinor: Int?
        var feeMinor: Int?
        var bonusMinor: Int?
        var totalCreditedMinor: Int?
    }
}

struct TopupDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
    var amountMinor: Int?
    var redirectUrl: String?
    var requiresOtp: Bool?
}

struct WalletRowDto: Decodable, Equatable {
    var uuid: String?
    var type: String?
    var typeLabel: String?
    var direction: String?
    var amountMinor: Int?
    var note: String?
    var createdAt: String?
}

/// One statement row in full (D-212): what it points at, the other rows of the same operation, whether it was reversed.
struct WalletRefDto: Decodable, Equatable {
    var type: String?
    var uuid: String?
    var number: String?
    var status: String?
}

struct WalletRelatedDto: Decodable, Equatable {
    var uuid: String?
    var typeLabel: String?
    var direction: String?
    var amountMinor: Int?
    var createdAt: String?
}

struct WalletDetailDto: Decodable, Equatable {
    var uuid: String?
    var type: String?
    var typeLabel: String?
    var direction: String?
    var bucket: String?
    var amountMinor: Int?
    var balanceAfterMinor: Int?
    var totalBalanceAfterMinor: Int?
    var note: String?
    var createdAt: String?
    var reference: WalletRefDto?
    var related: [WalletRelatedDto]?
    var reversed: Bool?
    var reversesUuid: String?
}

struct WalletPinDto: Decodable, Equatable {
    var hasPin: Bool?
    var mustChange: Bool?
    var isFrozen: Bool?
    var lockedUntil: String?
}

struct WithdrawalMethodDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var type: String?
    var label: String?
    var display: String?
    var isFavorite: Bool?
}

struct WithdrawalMethodShortDto: Decodable, Equatable {
    var type: String?
    var label: String?
    var display: String?
}

struct WithdrawalDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var status: String?
    var amountMinor: Int?
    var currencyCode: String?
    var method: WithdrawalMethodShortDto?
    var rejectionReason: String?
    var createdAt: String?
}

struct TrustedContactDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var name: String?
    var phone: String?
    var shareMode: String?
    var notifyArrival: Bool?
}

struct BlockedPersonDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var name: String?
}

/// "Are you OK?" waiting for the captain (D-144).
struct SafetyCheckDto: Decodable, Equatable {
    var uuid: String?
    var kind: String?
    var reason: String?
}

struct DriverSafetyDto: Decodable, Equatable {
    var contacts: [TrustedContactDto]?
    var blocked: [BlockedPersonDto]?
    var emergencyNumber: String?
    var maxContacts: Int?
}

/// The rider asks for a new destination / one more stop (D-131..133).
struct ChangeRequestDto: Decodable, Equatable {
    var uuid: String?
    var type: String?
    var status: String?
    var place: PlaceDto?
    var distanceDeltaM: Int?
    var durationDeltaS: Int?
    var earningDeltaMinor: Int?
    var newEarningMinor: Int?
    var secondsLeft: Int?
}

struct ShareDto: Decodable, Equatable {
    var url: String?
    var views: Int?
}

struct SosDto: Decodable, Equatable {
    var incidentUuid: String?
    var emergencyNumber: String?
}

struct SafetyDto: Decodable, Equatable {
    var emergencyNumber: String?
}

/// A ride booked ahead, in the scheduled list (D-260..269).
struct ScheduledRideDto: Decodable, Equatable {
    var uuid: String?
    var number: String?
    var scheduledAt: String?
    var onlineFrom: String?
    var earningMinor: Int?
    var pickupDistanceM: Int?
    var riderRating: Double?
    var mine: Bool?
    var canStart: Bool?
    var startsAt: String?
    var releaseCountsAsCancel: Bool?
    var category: CategoryRefDto?
    var paymentMethod: String?
    var currency: CurrencyDto?
    var origin: PlaceDto?
    var destination: PlaceDto?
    var stops: [StopDto]?
    var route: RouteDto?
}

struct WaitingDto: Decodable, Equatable {
    var freeS: Int?
    var noShowAfterS: Int?
    /// D-122: the clock turns to a warning this long before the free minutes end.
    var warnBeforeS: Int?
}

/// D-129: the time and distance left to the next point (pickup / stop / destination).
struct ProgressDto: Decodable, Equatable {
    var to: String?
    var distanceM: Int?
    var etaS: Int?
}

/// O-58: one hot area — the requests in the last hour there, and how hot (0..1).
struct HeatCellDto: Decodable, Equatable {
    var lat: Double?
    var lng: Double?
    var weight: Double?
    var requests: Int?
}

struct DispatchCategoryDto: Decodable, Equatable {
    var slug: String?
    var name: String?
    var ok: Bool?
    var reason: String?
}

/// One dispatch condition (O-47); `detail` depends on the key (the categories list, the open requests…).
struct DispatchCheckItemDto: Decodable, Equatable {
    var key: String?
    var ok: Bool?
    var detail: JSONAny?

    var categories: [DispatchCategoryDto] {
        guard key == "categories", case .array = detail else { return [] }
        return detail?.decoded([DispatchCategoryDto].self) ?? []
    }
}

struct DispatchCheckDto: Decodable, Equatable {
    var ready: Bool?
    var checks: [DispatchCheckItemDto]?
}

struct TripCallDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
    var caller: String?
}

struct TripCallJoinDto: Decodable, Equatable {
    var url: String?
    var room: String?
    var token: String?
}

struct TripCallStartDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
    var caller: String?
    var join: TripCallJoinDto?
}

struct TripHourlyDto: Decodable, Equatable {
    var hours: Int?
    var hourPriceMinor: Int?
    var overtimePerMinMinor: Int?
    var endsAt: String?
}

struct TripDto: Decodable, Equatable {
    var uuid: String?
    var number: String?
    var status: String?
    /// O-34: the planned route, drawn on the trip map.
    var route: RouteDto?
    /// O-30..O-32: sharing the car — what came off this fare, and how many ride now.
    var pool: TripPoolDto?
    /// O-17: booked by the hour — the hours, the extra per minute after them, when they run out.
    var hourly: TripHourlyDto?
    /// O-09: the in-app call ringing or going on now.
    var call: TripCallDto?
    /// O-10: the rider's messages not read yet (the badge on the chat button).
    var unreadMessages: Int?
    /// O-09: the rider's number, to call from outside the app while the ride is on.
    var callPhone: String?
    var version: Int?
    var category: CategoryRefDto?
    var paymentMethod: String?
    var paymentStatus: String?
    var currency: CurrencyDto?
    var origin: PlaceDto?
    var destination: PlaceDto?
    var openDestination: Bool?
    var stops: [StopDto]?
    var times: TimesDto?
    var waiting: WaitingDto?
    var rider: RiderDto?
    var passengers: Int?
    var pickupNote: String?
    /// R-233..R-237, D-59, D-390: what the rider added — a pet, child seats, a service animal, bags, priority…
    var options: [AddonDto]?
    var beneficiary: BeneficiaryDto?
    var fromCircle: Bool?
    var pickupPoint: PickupPointDto?
    var dropoffPoint: PickupPointDto?
    var progress: ProgressDto?
    var needsPin: Bool?
    var earningMinor: Int?
    var cashToCollectMinor: Int?
    var finalTotalMinor: Int?
    /// D-182: the rider's tip, apart from the fare — all of it the captain's.
    var tipMinor: Int?
    var lines: [LineDto]?
    var navigation: NavigationDto?
    var safety: SafetyDto?
    var changeRequest: ChangeRequestDto?
    var safetyCheck: SafetyCheckDto?
    var rated: Bool?
    /// O-41: what the captain scores the rider on (manners, punctuality…), each with its own stars.
    var ratingCriteria: [RatingCriterionDto]?
    /// «راحتي»: what the rider asked for, and what I said can't be done.
    var comfort: ComfortDto?
    /// Block 06: the next trip I took while driving this one.
    var nextTrip: NextTripDto?
    /// Block 06: on a next trip — the ride it waits for.
    var waitingForRide: RideRefDto?
}

struct NextTripDto: Decodable, Equatable {
    var uuid: String?
    var number: String?
    var rider: String?
    /// The next trip's rider is a verified Dorr account.
    var riderVerified: Bool?
    var pickup: PlaceDto?
    var earningMinor: Int?
}

struct RideRefDto: Decodable, Equatable {
    var uuid: String?
    var number: String?
}

struct ComfortItemDto: Decodable, Equatable {
    var item: String?
    var label: String?
}

struct ComfortReasonDto: Decodable, Equatable {
    var value: String?
    var label: String?
}

struct ComfortUnableDto: Decodable, Equatable {
    var item: String?
    var label: String?
    var reason: String?
    var reasonLabel: String?
}

struct ComfortDto: Decodable, Equatable {
    var quiet: Bool?
    var ac: String?
    var music: String?
    var items: [ComfortItemDto]?
    var unable: [ComfortUnableDto]?
    var reasons: [ComfortReasonDto]?
    var disclaimer: String?
}

// MARK: - Block 06: demand and DORR Driver AI

struct HotZoneDto: Decodable, Equatable {
    var lat: Double?
    var lng: Double?
    var requests: Int?
    var unmet: Int?
    var captains: Int?
    var score: Double?
    var shortage: Bool?
    var distanceM: Int?
    var why: String?
}

struct ForecastZoneDto: Decodable, Equatable {
    var lat: Double?
    var lng: Double?
    var expected: Double?
    var confidence: Double?
    var distanceM: Int?
}

struct ForecastSlotDto: Decodable, Equatable {
    var inMin: Int?
    var zones: [ForecastZoneDto]?
}

struct ScheduledDemandDto: Decodable, Equatable {
    var hour: String?
    var booked: Int?
    var usual: Double?
}

struct AiAccessDto: Decodable, Equatable {
    var available: Bool?
    var allowed: Bool?
    var reason: String?
}

struct DemandDto: Decodable, Equatable {
    var hotZones: [HotZoneDto]?
    var ai: AiAccessDto?
    var forecast: [ForecastSlotDto]?
    var scheduled: [ScheduledDemandDto]?
}

struct DriverAiCardDto: Decodable, Equatable {
    var type: String?
    var title: String?
    var text: String?
    var why: String?
    var confidence: Double?
    var lat: Double?
    var lng: Double?
    var rideUuid: String?
    var goalMinor: Int?
    var earnedMinor: Int?
    var leftMinor: Int?
    var progress: Double?
}

struct DriverAiDto: Decodable, Equatable {
    var allowed: Bool?
    var reason: String?
    var cards: [DriverAiCardDto]?
    var goalMinor: Int?
}

struct DriverAiAnswerDto: Decodable, Equatable {
    var answer: String?
    var confidence: Double?
}

struct AiPlanDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var name: String?
    var description: String?
    var price: Double?
    var originalPrice: Double?
    var currency: String?
    var durationDays: Int?
    var isTrial: Bool?
    var isFeatured: Bool?
    var badge: String?
    /// Whatever the admin wrote for the plan (a list of lines, or objects).
    var features: JSONAny?
}

struct ReasonDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var label: String?
}

struct TagDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var label: String?
    var sentiment: String?
}

struct MessageDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var from: String?
    var mine: Bool?
    var body: String?
    var createdAt: String?
}

struct QuickDto: Decodable, Equatable {
    var key: String?
    var text: String?
}

struct ChatDto: Decodable, Equatable {
    var open: Bool?
    var quickReplies: [QuickDto]?
    var messages: [MessageDto]?
}

// MARK: - earnings

struct PeriodDto: Decodable, Equatable {
    var trips: Int?
    var netMinor: Int?
    var grossMinor: Int?
    var tipsMinor: Int?
    var onlineS: Int?
    var distanceM: Int?
    var acceptanceBp: Int?
}

struct DayDto: Decodable, Equatable {
    var date: String?
    var trips: Int?
    var netMinor: Int?
}

struct ProfileStatsDto: Decodable, Equatable {
    var rating: Double?
    var ratingCount: Int?
    var tripsCount: Int?
    var tier: String?
    var points: Int?
}

struct PointEntryDto: Decodable, Equatable {
    var event: String?
    var label: String?
    var points: Int?
    var at: String?
}

struct EarningsDto: Decodable, Equatable {
    var currency: CurrencyDto?
    var today: PeriodDto?
    var week: PeriodDto?
    var month: PeriodDto?
    var days: [DayDto]?
    var pendingMinor: Int?
    var walletMinor: Int?
    var profile: ProfileStatsDto?
    var recentPoints: [PointEntryDto]?
}

// MARK: - earnings report (D-193..D-202)

struct ReportPeriodDto: Decodable, Equatable {
    var key: String?
    var from: String?
    var to: String?
    var days: Int?
}

/// This period against the one before: the difference, its share in basis points (none when there was nothing before) and a word for the direction.
struct ChangeDto: Decodable, Equatable {
    var value: Int?
    var pctBp: Int?
    var direction: String?
}

struct ReportChangesDto: Decodable, Equatable {
    var net: ChangeDto?
    var gross: ChangeDto?
    var trips: ChangeDto?
    var tips: ChangeDto?
    var online: ChangeDto?

    enum CodingKeys: String, CodingKey {
        case net = "netMinor"
        case gross = "grossMinor"
        case trips
        case tips = "tipsMinor"
        case online = "onlineS"
    }
}

struct ReportPreviousDto: Decodable, Equatable {
    var from: String?
    var to: String?
    var totals: PeriodDto?
}

struct SeriesPointDto: Decodable, Equatable {
    var key: String?
    var trips: Int?
    var netMinor: Int?
}

struct SeriesDto: Decodable, Equatable {
    var unit: String?
    var points: [SeriesPointDto]?
}

struct EarningsReportDto: Decodable, Equatable {
    var currency: CurrencyDto?
    var period: ReportPeriodDto?
    var totals: PeriodDto?
    var previous: ReportPreviousDto?
    var change: ReportChangesDto?
    var series: SeriesDto?
}

// MARK: - driving preferences (D-32..D-37)

struct PrefCategoryDto: Decodable, Equatable {
    var slug: String?
    var name: String?
    var eligible: Bool?
    var selected: Bool?
}

struct PriceFilterDto: Decodable, Equatable {
    var allowed: Bool?
    var minFareMinor: Int?
    var minPerKmMinor: Int?
}

struct PaymentFilterDto: Decodable, Equatable {
    var allowed: Bool?
    var methods: [String]?
    var options: [String]?
}

struct AutoAcceptDto: Decodable, Equatable {
    var available: Bool?
    var enabled: Bool?
    var maxPickupM: Int?
    var priceFilter: PriceFilterDto?
    var paymentFilter: PaymentFilterDto?
}

/// One add-on of a request (`key`: wait_save / priority / child_seats / pet / service_animal / luggage).
struct AddonDto: Decodable, Equatable {
    var key: String?
    var label: String?
    var count: Int?
}

/// R-235, R-236, D-390: what the car has / takes, and whether those add-ons are open in the country.
struct EquipmentDto: Decodable, Equatable {
    var petFriendly: Bool?
    var childSeats: Int?
    var petsOffered: Bool?
    var childSeatsOffered: Bool?
}

struct PreferencesDto: Decodable, Equatable {
    var currency: CurrencyDto?
    var equipment: EquipmentDto?
    var categories: [PrefCategoryDto]?
    var pickupRadiusM: Int?
    var pickupRadiusMaxM: Int?
    var autoAccept: AutoAcceptDto?
    /// Block 06: a next trip near where my ride ends (when the country has it), and my daily earnings goal.
    var nextTrips: NextTripsPrefDto?
    var dailyGoalMinor: Int?
}

struct NextTripsPrefDto: Decodable, Equatable {
    var available: Bool?
    var enabled: Bool?
}

// MARK: - driving time (D-42, D-43)

struct DrivingHoursDto: Decodable, Equatable {
    var enabled: Bool?
    var windowH: Int?
    var usedS: Int?
    var warnS: Int?
    var maxS: Int?
    var remainingS: Int?
    /// ok | warn | limit
    var level: String?
    var freeAt: String?
}

// MARK: - cases: disputes and unpaid-ride reports (D-229..D-238)

struct CaseOptionDto: Decodable, Equatable {
    var code: String?
    var label: String?
}

struct CaseOptionsDto: Decodable, Equatable {
    var available: Bool?
    var kinds: [CaseOptionDto]?
    var disputeReasons: [CaseOptionDto]?
    var maxFiles: Int?
}

struct CaseRideRefDto: Decodable, Equatable {
    var uuid: String?
    var number: String?
    var status: String?
}

struct CaseMessageDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var from: String?
    var body: String?
    var at: String?
}

struct CaseFileDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var name: String?
    var mime: String?
    var size: Int?
    var url: String?
    var mine: Bool?
}

struct CaseDecisionDto: Decodable, Equatable {
    var outcome: String?
    var reason: String?
    var decidedAt: String?
}

struct CaseMilestoneDto: Decodable, Equatable {
    var event: String?
    var at: String?
    var distanceToPickupM: Int?
}

struct CaseLineDto: Decodable, Equatable {
    var code: String?
    var amountMinor: Int?
}

struct CaseWaitingDto: Decodable, Equatable {
    var pickupS: Int?
    var freeS: Int?
}

struct CaseRouteDto: Decodable, Equatable {
    var plannedM: Int?
    var drivenM: Int?
}

struct CaseEvidenceDto: Decodable, Equatable {
    var timeline: [CaseMilestoneDto]?
    var waiting: CaseWaitingDto?
    var route: CaseRouteDto?
    var lines: [CaseLineDto]?
}

/// A case as the captain sees it: the list carries the headline only, the detail adds the rest.
struct CaseDto: Decodable, Equatable {
    var uuid: String?
    var number: String?
    var kind: String?
    var kindLabel: String?
    var categoryLabel: String?
    var status: String?
    var statusLabel: String?
    var decided: Bool?
    var summary: String?
    var ride: CaseRideRefDto?
    var disputedMinor: Int?
    var currency: CurrencyDto?
    var evidence: CaseEvidenceDto?
    var messages: [CaseMessageDto]?
    var files: [CaseFileDto]?
    var decision: CaseDecisionDto?
    var canMessage: Bool?
    var canAppeal: Bool?
    var appealedAt: String?
    var createdAt: String?
}

/// One finished ride in the captain's recent list — what a dispute or an unpaid report is about.
struct DriverRideItemDto: Decodable, Equatable {
    var uuid: String?
    var number: String?
    var status: String?
    var category: String?
    var originAddress: String?
    var destinationAddress: String?
    var earningMinor: Int?
    var paymentMethod: String?
    var currency: String?
    var completedAt: String?
}

struct DriverRideListMetaDto: Decodable, Equatable {
    var currentPage: Int?
    var lastPage: Int?
    var total: Int?
}

struct DriverRideListDto: Decodable, Equatable {
    var items: [DriverRideItemDto]?
    var meta: DriverRideListMetaDto?
}

// MARK: - rank and performance (D-239..D-259)

struct RankDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var slug: String?
    var name: String?
    var color: String?
    var probation: Bool?
    var benefits: [String]?
    /// Keyed as the server writes them (`cancel_bp`, `warnings`…) — see the decoding below.
    var thresholds: [String: Int]?
    var current: Bool?
    var missing: [RankMissingDto]?
}

extension RankDto {
    private enum CodingKeys: String, CodingKey { case id, slug, name, color, probation, benefits, thresholds, current, missing }

    /// The snake_case decoder turns a map's keys to camelCase too (`cancel_bp` → `cancelBp`); put them back, as Gson keeps them.
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decodeIfPresent(Int.self, forKey: .id)
        slug = try c.decodeIfPresent(String.self, forKey: .slug)
        name = try c.decodeIfPresent(String.self, forKey: .name)
        color = try c.decodeIfPresent(String.self, forKey: .color)
        probation = try c.decodeIfPresent(Bool.self, forKey: .probation)
        benefits = try c.decodeIfPresent([String].self, forKey: .benefits)
        thresholds = (try? c.decodeIfPresent([String: Int].self, forKey: .thresholds))?
            .reduce(into: [String: Int]()) { $0[camelToSnake($1.key)] = $1.value }
        current = try c.decodeIfPresent(Bool.self, forKey: .current)
        missing = try c.decodeIfPresent([RankMissingDto].self, forKey: .missing)
    }
}

struct RankMissingDto: Decodable, Equatable {
    var metric: String?
    var need: Int?
    var have: Int?
    var min: Bool?
}

struct RankMetricsDto: Decodable, Equatable {
    var trips: Int?
    var rating: Int?
    var cleanliness: Int?
    var behavior: Int?
    var commitmentBp: Int?
    var completionBp: Int?
    var cancelBp: Int?
    var warnings: Int?
    var windowDays: Int?
}

struct ProbationDto: Decodable, Equatable {
    var active: Bool?
    var daysLeft: Int?
    var endsAt: String?
}

struct RankFallingDto: Decodable, Equatable {
    var to: RankDto?
    var dueAt: String?
    var missing: [RankMissingDto]?
}

struct PerformanceNoteDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var kind: String?
    var title: String?
    var body: String?
    var appealStatus: String?
    var appealText: String?
    var appealAnswer: String?
    var createdAt: String?
}

struct RankInfoDto: Decodable, Equatable {
    var probation: ProbationDto?
    var rank: RankDto?
    var next: RankDto?
    var metrics: RankMetricsDto?
    var falling: RankFallingDto?
    var ranks: [RankDto]?
    var appealAllowed: Bool?
    var notes: [PerformanceNoteDto]?
}

// MARK: - payouts and statements (D-216..D-228)

struct PayoutIntlDto: Decodable, Equatable {
    var status: String?
    var answer: String?
    var requestedAt: String?
}

struct PayoutStatusDto: Decodable, Equatable {
    var kycOk: Bool?
    var kycReason: String?
    var methodHoldHours: Int?
    var thirdPartyAllowed: Bool?
    var international: PayoutIntlDto?
    var frozen: Bool?
}

struct InvoiceLineDto: Decodable, Equatable {
    var rideNumber: String?
    var date: String?
    var fareMinor: Int?
    var commissionMinor: Int?
    var taxMinor: Int?
    var netMinor: Int?
}

struct InvoiceNoteDto: Decodable, Equatable {
    var kind: String?
    var type: String?
    var amountMinor: Int?
    var title: String?
    var date: String?
}

struct InvoiceDto: Decodable, Equatable {
    var month: String?
    var number: String?
    var trips: Int?
    var faresMinor: Int?
    var commissionMinor: Int?
    var taxMinor: Int?
    var netMinor: Int?
    var creditNotesMinor: Int?
    var debitNotesMinor: Int?
    var notesCount: Int?
    var lines: [InvoiceLineDto]?
    var notes: [InvoiceNoteDto]?
}

// MARK: - support and lost items (D-285..D-297)

struct HelpNodeDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var title: String?
    var answer: String?
    var children: [HelpNodeDto]?
}

struct SupportCategoryDto: Decodable, Equatable {
    var key: String?
    var label: String?
}

struct SupportHelpDto: Decodable, Equatable {
    var openNow: Bool?
    var hours: String?
    var categories: [SupportCategoryDto]?
    var results: [HelpNodeDto]?
    var items: [HelpNodeDto]?
}

struct SupportLastMessageDto: Decodable, Equatable {
    var sender: String?
    var body: String?
}

struct SupportTicketDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var number: String?
    var title: String?
    var status: String?
    var acceptsReplies: Bool?
    var lastMessage: SupportLastMessageDto?
}

struct SupportMessageDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var sender: String?
    var body: String?
    var imageUrl: String?
    var agentName: String?
    var createdAt: String?
}

struct LostRideRefDto: Decodable, Equatable {
    var uuid: String?
    var number: String?
}

struct LostMessageDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var mine: Bool?
    var body: String?
}

struct LostItemDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
    var description: String?
    var returnPlan: String?
    var ride: LostRideRefDto?
    var messages: [LostMessageDto]?
}

// MARK: - settings and account (D-304..D-318)

struct AlertsDto: Decodable, Equatable {
    var sound: Bool?
    var vibration: Bool?
    var voice: Bool?
}

struct DeviceDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var device: String?
    var current: Bool?
    var lastSeenAt: String?
}

struct CloseBlockerDto: Decodable, Equatable {
    var code: String?
    var label: String?
}

struct CloseCheckDto: Decodable, Equatable {
    var canClose: Bool?
    var blockers: [CloseBlockerDto]?
}

struct LegalDocDto: Decodable, Equatable {
    var type: String?
    var version: String?
    var updatedAt: String?
    var title: String?
    var content: String?
    var needsAcceptance: Bool?
}

// MARK: - exceptional states (D-329..D-344)

struct PausedCategoryDto: Decodable, Equatable {
    var slug: String?
    var name: String?
}

struct EmergencyReviewDto: Decodable, Equatable {
    var rideNumber: String?
    var since: String?
    var until: String?
}

struct LastWithdrawalDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var state: String?
    var amountMinor: Int?
    var reason: String?
    var createdAt: String?
}

struct ServiceStatusDto: Decodable, Equatable {
    var maintenance: Bool?
    var maintenanceMessage: String?
    var safeMode: Bool?
    var serviceOn: Bool?
    var pausedCategories: [PausedCategoryDto]?
    var emergencyReview: EmergencyReviewDto?
    var lastWithdrawal: LastWithdrawalDto?
}

/// O-89: one service the captain's vehicle can work in here — drawn by the dashboard, with the captain's switch.
struct WorkServiceDto: Decodable, Equatable {
    var key: String?
    var name: String?
    var description: String?
    var icon: String?
    var color: String?
    var comingSoon: Bool?
    var on: Bool?
}

/// O-89: the captain's kind of vehicle (car / motorcycle / minibus / bus / boat / yacht / none) and its services.
struct WorkServicesDto: Decodable, Equatable {
    var kind: String?
    var services: [WorkServiceDto]?
}

// MARK: - Gson-like reading of free JSON

/// The snake_case decoder reads the keys of a free JSON object (`JSONAny`) as camelCase too (`ride_uuid` → `rideUuid`). These
/// take the server's own key names, like Gson's `JsonObject`.
extension JSONAny {
    /// Gson `isJsonObject`.
    var isObject: Bool { if case .object = self { return true } else { return false } }

    /// Gson `isJsonNull`.
    var isNull: Bool { self == .null }

    /// Gson `asJsonObject.has(key)`, with the key as the server writes it.
    func has(_ key: String) -> Bool { self[snake: key] != nil }

    /// Gson `asJsonObject.get(key)`, with the key as the server writes it (`data[snake: "ride_uuid"]`).
    subscript(snake key: String) -> JSONAny? { self[snakeToCamel(key)] ?? self[key] }

    /// `Gson().fromJson(element, T::class.java)`: this JSON read as a DTO (nil when it isn't one).
    func decoded<T: Decodable>(_ type: T.Type) -> T? {
        guard let data = try? JSONEncoder().encode(self) else { return nil }
        return try? Api.decoder.decode(T.self, from: data)
    }
}

/// `JSONDecoder.KeyDecodingStrategy.convertFromSnakeCase` on one key.
func snakeToCamel(_ key: String) -> String {
    guard key.contains("_") else { return key }
    let parts = key.split(separator: "_", omittingEmptySubsequences: true)
    guard let first = parts.first else { return key }
    return String(first) + parts.dropFirst().map { $0.prefix(1).uppercased() + $0.dropFirst().lowercased() }.joined()
}

/// Back from the decoder's camelCase to the server's snake_case (`cancelBp` → `cancel_bp`).
func camelToSnake(_ key: String) -> String {
    var out = ""
    for ch in key {
        if ch.isUppercase { out += "_" + ch.lowercased() } else { out.append(ch) }
    }
    return out
}
