import Foundation

// androidApp `network/BusApi.kt`, `MarineApi.kt`, `HireApi.kt`, `LessonsApi.kt`, `LoyaltyApi.kt` — the rides service's
// other ways to move (docs/rides_tasks.md O-22..O-26, O-67, O-68) and DORR points (R-146..R-155). Read with
// `ApiClient.snake`; Android's data-class defaults are the `?? …` where they are read.

// MARK: - Bus (O-22, O-23)

struct BusPlaceDto: Codable, Equatable {
    var lat: Double
    var lng: Double
    var address: String?
}

struct BusPricesDto: Codable, Equatable {
    var outboundMinor: Int?
    var returnMinor: Int?
    var roundMinor: Int?
}

struct BusFreeDto: Codable, Equatable {
    var outbound: Int?
    var `return`: Int?
}

struct BusTakenDto: Codable, Equatable {
    var outbound: [Int]?
    var `return`: [Int]?
}

struct BusInfoDto: Codable, Equatable {
    var make: String?
    var model: String?
    var color: String?
    var colorHex: String?
    var plate: String?
    var seats: Int?
}

struct BusCaptainDto: Codable, Equatable {
    var name: String?
    var avatar: String?
}

struct BusTripDto: Codable, Equatable, Identifiable {
    var uuid: String
    var status: String?
    /// On a search result: `outbound` (origin → destination) or `return` (destination → origin).
    var leg: String?
    var origin: BusPlaceDto
    var destination: BusPlaceDto
    var departAt: String?
    var returnAt: String?
    var prices: BusPricesDto?
    var currency: RideCurrencyDto?
    var seatsTotal: Int?
    var seatsPerRow: Int?
    var free: BusFreeDto?
    var taken: BusTakenDto?
    var note: String?
    var bus: BusInfoDto?
    var captain: BusCaptainDto?
    var captainPhone: String?
    var fromDistanceM: Int?
    var toDistanceM: Int?

    var id: String { uuid }

    /// A bus trip's leg as the rider travels it (the coming-back leg runs destination → origin).
    func fromPlace(_ leg: String?) -> BusPlaceDto { leg == "return" ? destination : origin }
    func toPlace(_ leg: String?) -> BusPlaceDto { leg == "return" ? origin : destination }
    func leavesAt(_ leg: String?) -> String? { leg == "return" ? returnAt : departAt }
}

struct BusTicketDto: Codable, Equatable {
    var leg: String
    var seat: Int
    var qr: String?
    var boarded: Bool?
}

struct BusBookingDto: Codable, Equatable, Identifiable {
    var uuid: String
    var status: String
    /// O-67: «unpaid» — held for the payment screen until then.
    var paymentExpiresAt: String?
    var discountMinor: Int?
    var tripType: String?
    var amountMinor: Int?
    var tickets: [BusTicketDto]?
    /// O-68: the captain's messages not read yet, and a call going on.
    var unread: Int?
    var call: RideCallDto?
    var createdAt: String?
    var trip: BusTripDto?

    var id: String { uuid }
}

enum BusApi {
    private static let base = "mobile/v1/rides/bus"

    /// By where I leave from, where I go, or both (each optional), and optionally the day (YYYY-MM-DD).
    static func search(fromLat: Double? = nil, fromLng: Double? = nil, toLat: Double? = nil, toLng: Double? = nil, date: String? = nil) async throws -> ApiEnvelope<[BusTripDto]> {
        try await ApiClient.snake("\(base)/trips", query: [
            "from_lat": fromLat.map { String($0) }, "from_lng": fromLng.map { String($0) },
            "to_lat": toLat.map { String($0) }, "to_lng": toLng.map { String($0) }, "date": date,
        ])
    }

    static func trip(_ uuid: String) async throws -> ApiEnvelope<BusTripDto> { try await ApiClient.snake("\(base)/trips/\(uuid)") }

    /// `{trip_type: outbound|return|round, seats_outbound: [..], seats_return: [..]}` — held «unpaid», paid on the payment screen (O-67).
    static func book(_ uuid: String, _ body: [String: JSONValue], key: String) async throws -> ApiEnvelope<BusBookingDto> {
        try await ApiClient.snake("\(base)/trips/\(uuid)/book", method: "POST", body: body, headers: ["Idempotency-Key": key])
    }

    static func bookings() async throws -> ApiEnvelope<[BusBookingDto]> { try await ApiClient.snake("\(base)/bookings") }

    static func booking(_ uuid: String) async throws -> ApiEnvelope<BusBookingDto> { try await ApiClient.snake("\(base)/bookings/\(uuid)") }

    /// O-68: the chat with the captain about my ticket (no numbers exchanged).
    static func messages(_ uuid: String) async throws -> ApiEnvelope<RideChatDto> { try await ApiClient.snake("\(base)/bookings/\(uuid)/messages") }

    static func send(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<RideMessageDto> {
        try await ApiClient.snake("\(base)/bookings/\(uuid)/messages", method: "POST", body: body)
    }

    /// O-68: an in-app voice call with the captain (LiveKit, like a ride's).
    static func startCall(_ uuid: String) async throws -> ApiEnvelope<RideCallStartDto> {
        try await ApiClient.snake("\(base)/bookings/\(uuid)/calls", method: "POST", body: [:])
    }

    static func acceptCall(_ uuid: String, _ call: String) async throws -> ApiEnvelope<RideCallStartDto> {
        try await ApiClient.snake("\(base)/bookings/\(uuid)/calls/\(call)/accept", method: "POST", body: [:])
    }

    static func endCall(_ uuid: String, _ call: String) async throws -> ApiEnvelope<RideCallDto> {
        try await ApiClient.snake("\(base)/bookings/\(uuid)/calls/\(call)/end", method: "POST", body: [:])
    }

    static func cancel(_ uuid: String) async throws -> ApiEnvelope<BusBookingDto> {
        try await ApiClient.snake("\(base)/bookings/\(uuid)/cancel", method: "POST", body: [:])
    }
}

// MARK: - Boats & yachts (O-25)

struct MarinePricesDto: Codable, Equatable {
    var seatMinor: Int?
    var privateMinor: Int?
    var hourMinor: Int?
    var minHours: Int?
}

struct MarineBoatDto: Codable, Equatable {
    var type: String?
    var make: String?
    var model: String?
    var color: String?
    var seats: Int?
}

struct MarineSlotDto: Codable, Equatable {
    var from: String
    var to: String
}

struct MarineListingDto: Codable, Equatable, Identifiable {
    var uuid: String
    /// trip (a sea trip at a set time) or rental (a yacht by the hour).
    var kind: String
    var status: String?
    var title: String
    var description: String?
    var marina: BusPlaceDto
    var routeNote: String?
    var amenities: [String]?
    var capacity: Int?
    var seatsLeft: Int?
    var departAt: String?
    var durationMin: Int?
    var prices: MarinePricesDto?
    var currency: RideCurrencyDto?
    var busy: [MarineSlotDto]?
    var boat: MarineBoatDto?
    var captain: BusCaptainDto?
    var captainPhone: String?

    var id: String { uuid }
}

struct MarineExtensionDto: Codable, Equatable {
    var hours: Int?
    var amountMinor: Int?
    var status: String?
}

struct MarineBookingDto: Codable, Equatable, Identifiable {
    var uuid: String
    var mode: String
    var status: String
    /// O-67: «unpaid» — held for the payment screen until then.
    var paymentExpiresAt: String?
    var discountMinor: Int?
    var guests: Int?
    var startsAt: String?
    var endsAt: String?
    var amountMinor: Int?
    var extensionMinor: Int?
    var `extension`: MarineExtensionDto?
    var qr: String?
    var createdAt: String?
    var listing: MarineListingDto?

    var id: String { uuid }
}

enum MarineApi {
    private static let base = "mobile/v1/rides/marine"

    /// `kind` = trip | rental (or both); near a place, or everywhere in my country.
    static func listings(kind: String? = nil, lat: Double? = nil, lng: Double? = nil) async throws -> ApiEnvelope<[MarineListingDto]> {
        try await ApiClient.snake("\(base)/listings", query: ["kind": kind, "lat": lat.map { String($0) }, "lng": lng.map { String($0) }])
    }

    static func listing(_ uuid: String) async throws -> ApiEnvelope<MarineListingDto> { try await ApiClient.snake("\(base)/listings/\(uuid)") }

    /// `{mode: shared|private|rental, guests, starts_at?, hours?}`.
    static func book(_ uuid: String, _ body: [String: JSONValue], key: String) async throws -> ApiEnvelope<MarineBookingDto> {
        try await ApiClient.snake("\(base)/listings/\(uuid)/book", method: "POST", body: body, headers: ["Idempotency-Key": key])
    }

    static func bookings() async throws -> ApiEnvelope<[MarineBookingDto]> { try await ApiClient.snake("\(base)/bookings") }

    static func booking(_ uuid: String) async throws -> ApiEnvelope<MarineBookingDto> { try await ApiClient.snake("\(base)/bookings/\(uuid)") }

    static func cancel(_ uuid: String) async throws -> ApiEnvelope<MarineBookingDto> {
        try await ApiClient.snake("\(base)/bookings/\(uuid)/cancel", method: "POST", body: [:])
    }

    /// R-42: `{hours}` more on a hire — the captain agrees or not.
    static func extend(_ uuid: String, hours: Int) async throws -> ApiEnvelope<MarineBookingDto> {
        try await ApiClient.snake("\(base)/bookings/\(uuid)/extend", method: "POST", body: ["hours": .int(hours)])
    }
}

// MARK: - Find a driver (O-26c)

struct HireLimitsDto: Codable, Equatable {
    var hourPriceMinor: Int?
    var dayPriceMinor: Int?
    var minHours: Int?
    var maxHours: Int?
    var maxDays: Int?
    var overtimePerMinMinor: Int?
    var freeCancelBeforeS: Int?
}

struct HireQuoteDto: Codable, Equatable {
    var amountMinor: Int?
    var limits: HireLimitsDto?
    var currency: RideCurrencyDto?
}

struct HirePersonDto: Codable, Equatable {
    var name: String?
    var avatar: String?
    var phone: String?
}

struct HireDto: Codable, Equatable, Identifiable {
    var uuid: String
    /// unpaid / lapsed / open / accepted / arrived / started / completed / canceled / expired
    var status: String
    var paymentExpiresAt: String?
    var discountMinor: Int?
    var pickup: BusPlaceDto
    var startsAt: String?
    var endsAt: String?
    var unit: String?
    var quantity: Int?
    var transmission: String?
    var car: String?
    var note: String?
    var amountMinor: Int?
    var extraMinor: Int?
    var currency: RideCurrencyDto?
    var startedAt: String?
    var captain: HirePersonDto?

    var id: String { uuid }
}

enum HireApi {
    private static let base = "mobile/v1/rides/hire"

    static func quote(lat: Double, lng: Double, unit: String, quantity: Int) async throws -> ApiEnvelope<HireQuoteDto> {
        try await ApiClient.snake("\(base)/quote", query: ["lat": String(lat), "lng": String(lng), "unit": unit, "quantity": String(quantity)])
    }

    /// `{pickup{lat,lng,address}, starts_at?, unit: hour|day, quantity, transmission, car?, note?}`.
    static func request(_ body: [String: JSONValue], key: String) async throws -> ApiEnvelope<HireDto> {
        try await ApiClient.snake(base, method: "POST", body: body, headers: ["Idempotency-Key": key])
    }

    static func mine() async throws -> ApiEnvelope<[HireDto]> { try await ApiClient.snake(base) }

    static func show(_ uuid: String) async throws -> ApiEnvelope<HireDto> { try await ApiClient.snake("\(base)/\(uuid)") }

    static func cancel(_ uuid: String) async throws -> ApiEnvelope<HireDto> { try await ApiClient.snake("\(base)/\(uuid)/cancel", method: "POST", body: [:]) }
}

// MARK: - Driving lessons (O-26b)

struct LessonTrainerDto: Codable, Equatable {
    var name: String?
    var avatar: String?
    var gender: String?
}

struct LessonPackDto: Codable, Equatable, Identifiable {
    var uuid: String
    var status: String?
    var title: String
    var description: String?
    var transmission: String?
    var lessons: Int?
    var minutesPerLesson: Int?
    var priceMinor: Int?
    var perLessonMinor: Int?
    var ownCar: Bool?
    var area: BusPlaceDto
    var currency: RideCurrencyDto?
    var trainer: LessonTrainerDto?

    var id: String { uuid }
}

struct LessonSessionDto: Codable, Equatable, Identifiable {
    var uuid: String
    var number: Int
    /// requested / confirmed / done / declined / canceled
    var status: String
    var startsAt: String?
    var pickup: BusPlaceDto?

    var id: String { uuid }
}

struct LessonEnrollmentDto: Codable, Equatable, Identifiable {
    var uuid: String
    var status: String
    var paymentExpiresAt: String?
    var discountMinor: Int?
    var lessonsTotal: Int?
    var lessonsDone: Int?
    var lessonsLeft: Int?
    var amountMinor: Int?
    var refundedMinor: Int?
    var pack: LessonPackDto
    var trainerPhone: String?
    var sessions: [LessonSessionDto]?

    var id: String { uuid }
}

enum LessonsApi {
    private static let base = "mobile/v1/rides/lessons"

    static func packs(lat: Double, lng: Double, transmission: String? = nil) async throws -> ApiEnvelope<[LessonPackDto]> {
        try await ApiClient.snake("\(base)/packs", query: ["lat": String(lat), "lng": String(lng), "transmission": transmission])
    }

    static func enroll(_ uuid: String, key: String) async throws -> ApiEnvelope<LessonEnrollmentDto> {
        try await ApiClient.snake("\(base)/packs/\(uuid)/enroll", method: "POST", body: [:], headers: ["Idempotency-Key": key])
    }

    static func mine() async throws -> ApiEnvelope<[LessonEnrollmentDto]> { try await ApiClient.snake("\(base)/enrollments") }

    static func enrollment(_ uuid: String) async throws -> ApiEnvelope<LessonEnrollmentDto> { try await ApiClient.snake("\(base)/enrollments/\(uuid)") }

    /// `{starts_at, pickup{lat,lng,address}}`
    static func book(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<LessonEnrollmentDto> {
        try await ApiClient.snake("\(base)/enrollments/\(uuid)/sessions", method: "POST", body: body)
    }

    static func quit(_ uuid: String) async throws -> ApiEnvelope<LessonEnrollmentDto> {
        try await ApiClient.snake("\(base)/enrollments/\(uuid)/quit", method: "POST", body: [:])
    }

    static func cancelSession(_ uuid: String) async throws -> ApiEnvelope<LessonEnrollmentDto> {
        try await ApiClient.snake("\(base)/sessions/\(uuid)/cancel", method: "POST", body: [:])
    }
}

// MARK: - DORR points (R-146..R-155)

struct LoyaltyTierDto: Codable, Equatable {
    var id: Int
    var name: String?
    var color: String?
    var minPoints: Int?
    var multiplierBp: Int?
    var benefits: [String]?
    var current: Bool?
    var pointsToGo: Int?
}

struct LoyaltyCommittedDto: Codable, Equatable {
    var active: Bool?
    var bonusBp: Int?
    var windowDays: Int?
}

struct LoyaltyBadgeDto: Codable, Equatable {
    var id: Int
    var name: String?
    var description: String?
    var icon: String?
    var threshold: Int?
    var progress: Int?
    var rewardPoints: Int?
    var earnedAt: String?
}

struct LoyaltyChallengeDto: Codable, Equatable {
    var id: Int
    var title: String?
    var description: String?
    var rule: String?
    var target: Int?
    var progress: Int?
    var rewardPoints: Int?
    var endsAt: String?
    var live: Bool?
    var failed: Bool?
    var completedAt: String?
}

struct LoyaltyEntryDto: Codable, Equatable, Identifiable {
    var id: Int
    var points: Int
    var balanceAfter: Int?
    var kind: String?
    var title: String?
    var createdAt: String?
}

struct LoyaltyDashboardDto: Codable, Equatable {
    var enabled: Bool?
    var balance: Int?
    var lifetime: Int?
    var pointsPerUnit: Int?
    var windowDays: Int?
    var windowPoints: Int?
    var tier: LoyaltyTierDto?
    var nextTier: LoyaltyTierDto?
    var progress: Double?
    var tiers: [LoyaltyTierDto]?
    var committed: LoyaltyCommittedDto?
    var badges: [LoyaltyBadgeDto]?
    var challenges: [LoyaltyChallengeDto]?
    var recent: [LoyaltyEntryDto]?
}

struct LoyaltyRewardDto: Codable, Equatable, Identifiable {
    var id: Int
    var title: String?
    var description: String?
    var costPoints: Int?
    var kind: String?
    var valueMinor: Int?
    var couponPercent: Int?
    var couponDays: Int?
    var stock: Int?
    var minTier: String?
    var currency: RideCurrencyDto?
    var available: Bool?
    /// out_of_stock / limit / tier / points
    var reason: String?
}

struct LoyaltyRedemptionResultDto: Codable, Equatable {
    var couponCode: String?
    var expiresAt: String?
    var creditMinor: Int?
}

struct LoyaltyRedemptionDto: Codable, Equatable, Identifiable {
    var uuid: String
    var points: Int?
    var title: String?
    var kind: String?
    var result: LoyaltyRedemptionResultDto?
    var createdAt: String?

    var id: String { uuid }
}

enum LoyaltyApi {
    private static let base = "mobile/v1/rides/loyalty"

    static func dashboard() async throws -> ApiEnvelope<LoyaltyDashboardDto> { try await ApiClient.snake(base) }

    /// `kind`: earned / spent / nil = all.
    static func history(kind: String? = nil) async throws -> ApiEnvelope<[LoyaltyEntryDto]> { try await ApiClient.snake("\(base)/history", query: ["kind": kind]) }

    static func rewards() async throws -> ApiEnvelope<[LoyaltyRewardDto]> { try await ApiClient.snake("\(base)/rewards") }

    static func redeem(_ id: Int, key: String) async throws -> ApiEnvelope<LoyaltyRedemptionDto> {
        try await ApiClient.snake("\(base)/rewards/\(id)/redeem", method: "POST", body: [:], headers: ["Idempotency-Key": key])
    }

    static func redemptions() async throws -> ApiEnvelope<[LoyaltyRedemptionDto]> { try await ApiClient.snake("\(base)/redemptions") }
}
