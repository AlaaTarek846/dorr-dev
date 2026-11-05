import Foundation

// androidApp `network/RidesApi.kt` — passenger rides, the rider's side (Modules/Rides, docs/rides-plan.md §15):
// what is switched on here, prices for a trip, booking, following the ride, its chat, cancelling and rating.
// Money is always in minor units (halalas); the server prices everything — the app never sends a price.
// DTOs are plain optional structs read with `ApiClient.snake`; Android's data-class defaults are the `?? …` where
// they are read. (`map-config` lives with the map layer: `RidesMapApi`.)

struct RideBidQuoteDto: Codable, Equatable {
    var suggestedMinor: Int?
    var floorMinor: Int?
    var stepMinor: Int?
    /** AI-05: the suggestion moved with demand around the pickup (nil = the rate card as is). */
    var smart: RideSmartPriceDto?
}

struct RideSmartPriceDto: Codable, Equatable {
    var baseMinor: Int?
    var adjustBp: Int?
    var why: String?
}

struct RideBiddingDto: Codable, Equatable {
    var riderPriceMinor: Int?
    var floorMinor: Int?
    var suggestedMinor: Int?
}

struct RideBidDriverDto: Codable, Equatable {
    var name: String?
    var avatarUrl: String?
    var rating: Double?
    var tripsCount: Int?
    var vehicle: RideVehicleDto?
}

struct RideBidDto: Codable, Equatable, Identifiable {
    var uuid: String
    var priceMinor: Int?
    var etaS: Int?
    var secondsLeft: Int?
    var ttlS: Int?
    var cheapest: Bool?
    var nearest: Bool?
    var driver: RideBidDriverDto?

    var id: String { uuid }
}

struct RideBidBoardDto: Codable, Equatable {
    var riderPriceMinor: Int?
    var floorMinor: Int?
    var bids: [RideBidDto]?
    var queued: Int?
}

struct RideFeaturesDto: Codable, Equatable {
    var countryId: Int?
    var cityId: Int?
    var service: Bool?
    /// Why `service` is off: `no_country` (we can't tell where you are) or `not_enabled`.
    var reason: String?
    /// Keys are camel-cased by the snake decoder ("find_driver" → "findDriver"): read with `RidesState.feature`.
    var categories: [String: Bool]?
    var features: [String: Bool]?
}

struct RidePlaceDto: Codable, Equatable {
    var lat: Double
    var lng: Double
    var address: String?
}

struct RideLineDto: Codable, Equatable {
    var code: String
    var label: String?
    var amountMinor: Int?
}

struct RideRouteDto: Codable, Equatable {
    var distanceM: Int?
    var durationS: Int?
    var polyline: String?
    var estimated: Bool?
}

struct RideQuoteCategoryDto: Codable, Equatable {
    var slug: String?
    var name: String?
    var tagline: String?
    var color: String?
    var capacity: Int?
    var iconUrl: String?
    var cashAllowed: Bool?
}

struct RideRangeDto: Codable, Equatable {
    var minMinor: Int?
    var maxMinor: Int?
}

struct RideQuoteDto: Codable, Equatable, Identifiable {
    var uuid: String
    var fingerprint: String
    var expiresAt: String?
    var category: RideQuoteCategoryDto?
    var currency: RideCurrencyDto?
    var pricingMode: String?
    var openDestination: Bool?
    var totalMinor: Int?
    var estimate: RideRangeDto?
    var holdMinor: Int?
    /// O-37: what the wallet in this ride's country has free — offered with cash.
    var walletAvailableMinor: Int?
    var route: RideRouteDto?
    var lines: [RideLineDto]?
    var bid: RideBidQuoteDto?
    /// R-45: the code typed — what it took off this category, or why not.
    var coupon: RideCouponDto?
    /// R-48: what each way of paying adds (wallet / card / cash).
    var paymentFees: [String: Int]?
    /// R-52, R-53: cancellation / no-show / refund, read before confirming.
    var policySummary: RidePolicySummaryDto?
    /// R-179: the terms in force for booking.
    var terms: [RideTermDto]?
    /// Booking ahead is possible for this category (nil for bidding), with the policy's limits.
    var scheduling: RideSchedulingDto?
    /// O-17: this quote is by the hour, or nil.
    var hourly: RideHourlyDto?
    /// O-17: the category can also be booked by the hour.
    var hourlyOffer: RideHourlyOfferDto?
    /// R-233..R-237: the add-ons on offer here with their prices, and the ones priced in.
    var addonsOffer: RideAddonsOfferDto?
    var options: [RideAddonDto]?

    var id: String { uuid }
    var mode: String { pricingMode ?? "upfront" }
}

struct RideCallDto: Codable, Equatable {
    var uuid: String
    var status: String?
    var caller: String?
}

struct LostRideRefDto: Codable, Equatable {
    var uuid: String?
    var number: String?
}

struct LostMessageDto: Codable, Equatable {
    var id: Int
    var mine: Bool?
    var body: String?
}

struct LostItemDto: Codable, Equatable {
    var uuid: String
    var status: String?
    var description: String?
    var returnPlan: String?
    var ride: LostRideRefDto?
    var messages: [LostMessageDto]?
}

/// One add-on as both apps show it (`key`: wait_save / priority / child_seats / pet / service_animal / luggage).
struct RideAddonDto: Codable, Equatable {
    var key: String
    var label: String?
    var count: Int?
    var small: Int?
    var large: Int?
}

struct RideAddonFeeDto: Codable, Equatable {
    var feeMinor: Int?
    var max: Int?
}

struct RideWaitSaveDto: Codable, Equatable {
    var discountBp: Int?
    var extraWaitS: Int?
}

struct RideLuggageDto: Codable, Equatable {
    var capacity: Int?
    var largeFeeMinor: Int?
}

struct RideAddonsOfferDto: Codable, Equatable {
    var waitSave: RideWaitSaveDto?
    var priority: RideAddonFeeDto?
    var childSeats: RideAddonFeeDto?
    var pet: RideAddonFeeDto?
    var serviceAnimal: RideAddonFeeDto?
    var luggage: RideLuggageDto?

    var isEmpty: Bool { waitSave == nil && priority == nil && childSeats == nil && pet == nil && serviceAnimal == nil && luggage == nil }
}

struct RideCallJoinDto: Codable, Equatable {
    var url: String
    var room: String?
    var token: String
}

struct RideCallStartDto: Codable, Equatable {
    var uuid: String
    var status: String?
    var caller: String?
    var join: RideCallJoinDto
}

struct RideHourlyDto: Codable, Equatable {
    var hours: Int?
    var hourPriceMinor: Int?
    var overtimePerMinMinor: Int?
    /// On a ride: when the hours bought run out (from the start).
    var endsAt: String?
}

struct RideHourlyOfferDto: Codable, Equatable {
    var hourPriceMinor: Int?
    var minHours: Int?
    var maxHours: Int?
    var overtimePerMinMinor: Int?
}

struct RideCouponDto: Codable, Equatable {
    var code: String?
    var discountMinor: Int?
    var applied: Bool?
    var reason: String?
}

struct RideTermDto: Codable, Equatable {
    var type: String?
    var title: String?
    var serviceId: Int?
    var scope: String?
    var updatedAt: String?
}

struct RidePolicyItemDto: Codable, Equatable {
    var code: String?
    var text: String?
}

struct RidePolicySummaryDto: Codable, Equatable {
    var items: [RidePolicyItemDto]?
    var refund: [String: String]?
}

/// R-144: the tip offered after the ride — or the one given (`allowed` false, `amountMinor` set).
struct RideTipDto: Codable, Equatable {
    var allowed: Bool?
    var amountMinor: Int?
    var presetsMinor: [Int]?
    var maxMinor: Int?
    var until: String?
}

struct RideSchedulingDto: Codable, Equatable {
    var minAheadS: Int?
    var maxAheadS: Int?
    var freeCancelBeforeS: Int?
    var cancelFeeMinor: Int?
}

/// A ride booked ahead: its time, the free-cancel limit, and whether a captain reserved it.
struct RideScheduledDto: Codable, Equatable {
    var at: String?
    var freeCancelUntil: String?
    var startsSearchAt: String?
    var reserved: Bool?
}

struct RideChangeRouteDto: Codable, Equatable {
    var distanceM: Int?
    var durationS: Int?
}

/// A change of the trip during the ride and its effect (R-91..95).
struct RideChangeDto: Codable, Equatable {
    var uuid: String
    var type: String?
    var status: String?
    var place: RidePlaceDto?
    var distanceDeltaM: Int?
    var durationDeltaS: Int?
    var route: RideChangeRouteDto?
    var priceDeltaMinor: Int?
    var newTotalMinor: Int?
    var secondsLeft: Int?
}

struct TrustedContactDto: Codable, Equatable {
    var id: Int
    var name: String?
    var phone: String?
    var shareMode: String?
    var notifyArrival: Bool?
}

struct SafetyPreferencesDto: Codable, Equatable {
    var pinMode: String?
    var checkinAfterMin: Int?
    var seatbeltReminder: Bool?
}

struct BlockedPersonDto: Codable, Equatable {
    var id: Int
    var name: String?
    var since: String?
}

struct FavoriteDriverDto: Codable, Equatable {
    var driverId: Int
    var name: String?
    var avatarUrl: String?
    var rating: Double?
}

/// "Are you OK?" waiting for an answer (R-222, R-223).
struct SafetyCheckDto: Codable, Equatable {
    var uuid: String
    var kind: String?
    var reason: String?
    var rideUuid: String?
}

struct SafetyCenterDto: Codable, Equatable {
    var contacts: [TrustedContactDto]?
    var preferences: SafetyPreferencesDto?
    var blocked: [BlockedPersonDto]?
    var favorites: [FavoriteDriverDto]?
    var emergencyNumber: String?
    var maxContacts: Int?
    var pendingCheck: SafetyCheckDto?
}

struct RideShareDto: Codable, Equatable {
    var url: String
    var views: Int?
}

struct RideSosDto: Codable, Equatable {
    var incidentUuid: String?
    var emergencyNumber: String?
}

struct RideSafetyDto: Codable, Equatable {
    var emergencyNumber: String?
}

struct NearbyCarDto: Codable, Equatable {
    var car: String
    var lat: Double
    var lng: Double
    var heading: Int?
}

struct NearbyCarsDto: Codable, Equatable {
    var cells: [String]?
    var cars: [NearbyCarDto]?
}

struct PlaceHitDto: Codable, Equatable {
    var name: String?
    var address: String?
    var lat: Double?
    var lng: Double?
}

struct RidePoolDto: Codable, Equatable {
    var discountMinor: Int?
    var riders: Int?
    var shareWith: String?
}

struct RatingCriterionDto: Codable, Equatable {
    var key: String
    var label: String?
}

struct RideCurrencyDto: Codable, Equatable {
    var code: String?
    var symbol: String?
    var decimalPlaces: Int?

    var decimals: Int { decimalPlaces ?? 2 }
}

struct RideStopDto: Codable, Equatable {
    var seq: Int?
    var lat: Double
    var lng: Double
    var address: String?
    var status: String?
}

struct RideVehicleDto: Codable, Equatable {
    var make: String?
    var model: String?
    var color: String?
    var colorHex: String?
    var plateLetters: String?
    var plateNumber: String?
    var year: Int?
}

struct RideDriverLocationDto: Codable, Equatable {
    var lat: Double
    var lng: Double
    var heading: Int?
    var at: String?
}

struct RideDriverDto: Codable, Equatable {
    var name: String?
    var avatarUrl: String?
    var rating: Double?
    var tripsCount: Int?
    /// R-81: new / bronze / silver / gold / diamond, and its name in the app's language.
    var tier: String?
    var tierLabel: String?
    var vehicle: RideVehicleDto?
    var location: RideDriverLocationDto?
}

struct RideTimesDto: Codable, Equatable {
    var requestedAt: String?
    var acceptedAt: String?
    var arrivedAt: String?
    /// The free waiting counts from here (a scheduled ride's time when the captain came early).
    var waitingFrom: String?
    var startedAt: String?
    var completedAt: String?
    var canceledAt: String?
}

struct RideWaitingDto: Codable, Equatable {
    var freeS: Int?
    var perMinMinor: Int?
    var noShowAfterS: Int?
    /// D-122: the countdown turns to a warning this long before the free minutes end.
    var warnBeforeS: Int?
}

/// D-129: how far the captain still is from the next point (pickup / stop / destination).
struct RideProgressDto: Codable, Equatable {
    var to: String?
    var distanceM: Int?
    var etaS: Int?
}

struct RideCategoryRefDto: Codable, Equatable {
    var slug: String?
    var name: String?
}

struct RidePickupPointDto: Codable, Equatable {
    var id: Int
    var kind: String?
    var kindLabel: String?
    var name: String?
    var instructions: String?
    var lat: Double?
    var lng: Double?
    var distanceM: Int?
}

struct RidePickupZoneDto: Codable, Equatable {
    var id: Int?
    var type: String?
    var name: String?
}

/// R-16..R-18: what is around a place — the zone, whether choosing a point is a must, the points.
struct RidePickupPointsDto: Codable, Equatable {
    var zone: RidePickupZoneDto?
    var required: Bool?
    var points: [RidePickupPointDto]?
}

/// A shortcut under "where to?": a saved address (home / work / favourite) or a recent destination.
struct RideSavedPlaceDto: Codable, Equatable {
    var id: Int?
    var kind: String?
    var title: String?
    var address: String?
    var lat: Double?
    var lng: Double?
}

struct RidePlacesDto: Codable, Equatable {
    var home: RideSavedPlaceDto?
    var work: RideSavedPlaceDto?
    var favorites: [RideSavedPlaceDto]?
    var recent: [RideSavedPlaceDto]?
}

struct RideCircleDriversDto: Codable, Equatable {
    var drivers: [FavoriteDriverDto]?
}

/// A ride booked for the favourite captains: still only them (`active`), how many, and whether the rider was asked.
struct RideCircleDto: Codable, Equatable {
    var active: Bool?
    var count: Int?
    var asked: Bool?
}

struct RideBeneficiaryDto: Codable, Equatable {
    var name: String?
    var phone: String?
}

struct RideDto: Codable, Equatable, Identifiable {
    var uuid: String
    var number: String?
    var status: String
    var version: Int?
    var category: RideCategoryRefDto?
    var pricingMode: String?
    var openDestination: Bool?
    var paymentMethod: String?
    var paymentStatus: String?
    /// R-61: the ways of paying the rider may switch to now.
    var paymentChange: [String]?
    var passengers: Int?
    var pickupNote: String?
    var options: [RideAddonDto]?
    var loyaltyPoints: Int?
    var beneficiary: RideBeneficiaryDto?
    var circle: RideCircleDto?
    var pickupPoint: RidePickupPointDto?
    var dropoffPoint: RidePickupPointDto?
    var progress: RideProgressDto?
    var currency: RideCurrencyDto?
    var origin: RidePlaceDto?
    var destination: RidePlaceDto?
    var stops: [RideStopDto]?
    var route: RideRouteDto?
    var scheduled: RideScheduledDto?
    var safety: RideSafetyDto?
    var times: RideTimesDto?
    var waiting: RideWaitingDto?
    var estimate: RideRangeDto?
    var agreedPriceMinor: Int?
    var bidding: RideBiddingDto?
    var finalTotalMinor: Int?
    var lines: [RideLineDto]?
    var checkoutId: String?
    var pin: String?
    var driver: RideDriverDto?
    var reservedDriver: RideDriverDto?
    var changeRequest: RideChangeDto?
    var safetyCheck: SafetyCheckDto?
    var favoriteDriver: Bool?
    var rated: Bool?
    /// O-30..O-32: sharing the car (With Me).
    var pool: RidePoolDto?
    /// O-17: booked by the hour.
    var hourly: RideHourlyDto?
    /// O-09: the in-app call ringing or going on now.
    var call: RideCallDto?
    /// O-10: the captain's messages not read yet.
    var unreadMessages: Int?
    /// O-09: the captain's number, to call from outside the app while the ride is on.
    var callPhone: String?
    /// O-41: what the rider scores the captain on, each with its own stars.
    var ratingCriteria: [RatingCriterionDto]?
    var tip: RideTipDto?
    /// «راحتي»: what the rider asked for on this trip, and what the captain said can't be done.
    var comfort: RideComfortDto?
    /// Block 06: the captain is finishing a ride nearby and comes right after it.
    var driverFinishing: RideFinishingDto?
    /// Block 06: a booking's captain may be late — by about how much, and why.
    var lateRisk: RideLateRiskDto?

    var id: String { uuid }
}

struct RideHistoryItemDto: Codable, Equatable, Identifiable {
    var uuid: String
    var number: String?
    var status: String
    var category: String?
    var originAddress: String?
    var destinationAddress: String?
    var totalMinor: Int?
    var currency: String?
    var driverName: String?
    var requestedAt: String?
    var scheduledAt: String?
    var freeCancelUntil: String?

    var id: String { uuid }
}

struct RideHistoryDto: Codable, Equatable {
    var items: [RideHistoryItemDto]?
}

struct RideCancelPreviewDto: Codable, Equatable {
    var stage: String?
    var feeMinor: Int?
    var freeUntil: String?
}

struct RideReasonDto: Codable, Equatable {
    var id: Int
    var label: String?
    var requiresNote: Bool?
}

struct RideTagDto: Codable, Equatable {
    var id: Int
    var label: String?
    var sentiment: String?
}

struct RideMessageDto: Codable, Equatable, Identifiable {
    var id: Int
    var from: String?
    var mine: Bool?
    var kind: String?
    var body: String?
    var clientId: String?
    var createdAt: String?
}

struct RideQuickReplyDto: Codable, Equatable {
    var key: String
    var text: String?
}

struct RideChatDto: Codable, Equatable {
    var open: Bool?
    var quickReplies: [RideQuickReplyDto]?
    var messages: [RideMessageDto]?
}

// MARK: - Cases: complaints and disputes (R-133..R-140)

struct RideCaseOptionDto: Codable, Equatable {
    var code: String
    var label: String?
}

struct RideCaseOptionsDto: Codable, Equatable {
    var available: Bool?
    var kinds: [RideCaseOptionDto]?
    var complaintCategories: [RideCaseOptionDto]?
    var disputeReasons: [RideCaseOptionDto]?
}

struct RideCaseRefDto: Codable, Equatable {
    var uuid: String?
    var number: String?
    var status: String?
}

struct RideCaseMessageDto: Codable, Equatable {
    var id: Int?
    var from: String?
    var body: String?
    var at: String?
}

struct RideCaseDecisionDto: Codable, Equatable {
    var outcome: String?
    var reason: String?
    var refundMinor: Int?
    var refundApplied: Bool?
}

struct RideCaseMilestoneDto: Codable, Equatable {
    var event: String?
    var at: String?
    var distanceToPickupM: Int?
}

struct RideCaseLineDto: Codable, Equatable {
    var code: String?
    var amountMinor: Int?
}

struct RideCaseWaitingDto: Codable, Equatable {
    var pickupS: Int?
    var freeS: Int?
}

struct RideCaseRouteDto: Codable, Equatable {
    var plannedM: Int?
    var drivenM: Int?
}

struct RideCaseEvidenceDto: Codable, Equatable {
    var timeline: [RideCaseMilestoneDto]?
    var waiting: RideCaseWaitingDto?
    var route: RideCaseRouteDto?
    var lines: [RideCaseLineDto]?
}

/// A case as the rider sees it: the list carries the headline only, the detail adds the rest.
struct RideCaseDto: Codable, Equatable {
    var uuid: String?
    var number: String?
    var kind: String?
    var kindLabel: String?
    var categoryLabel: String?
    var status: String?
    var statusLabel: String?
    var decided: Bool?
    var summary: String?
    var ride: RideCaseRefDto?
    var disputedMinor: Int?
    var currency: RideCurrencyDto?
    var evidence: RideCaseEvidenceDto?
    var messages: [RideCaseMessageDto]?
    var decision: RideCaseDecisionDto?
    var canMessage: Bool?
    var canAppeal: Bool?
    var appealedAt: String?
    var createdAt: String?
}

// MARK: - Block 06: DORR AI in rides

struct RideAiStatusDto: Codable, Equatable {
    var available: Bool?
    var allowed: Bool?
    var reason: String?
}

struct RideAiUnderstoodDto: Codable, Equatable {
    var destination: String?
    var saved: String?
    var category: String?
    var categoryName: String?
    /// ISO time with offset, for a ride later.
    var when: String?
    var passengers: Int?
    var payment: String?
    var note: String?
}

struct RideAiPlaceDto: Codable, Equatable {
    var name: String?
    var address: String?
    var lat: Double
    var lng: Double
    var source: String?
}

struct RideAiUnderstandDto: Codable, Equatable {
    var understood: RideAiUnderstoodDto?
    var clarify: String?
    var places: [RideAiPlaceDto]?
    var confirmOnMap: Bool?
}

struct RideAiSuggestDto: Codable, Equatable {
    var allowed: Bool?
    var reason: String?
    var category: String?
    var name: String?
    var confidence: Double?
    var why: String?
}

struct RideComfortPrefsDto: Codable, Equatable {
    var quiet: Bool?
    var ac: String?
    var music: String?
    var notes: [String]?
}

struct RideChoiceDto: Codable, Equatable {
    var value: String
    var label: String?
}

struct RideComfortOptionsDto: Codable, Equatable {
    var ac: [RideChoiceDto]?
    var music: [RideChoiceDto]?
    var notes: [RideChoiceDto]?
}

struct RideComfortScreenDto: Codable, Equatable {
    var enabled: Bool?
    var prefs: RideComfortPrefsDto?
    var options: RideComfortOptionsDto?
    var disclaimer: String?
}

struct RideComfortItemDto: Codable, Equatable {
    var item: String
    var label: String?
}

struct RideComfortUnableDto: Codable, Equatable {
    var item: String?
    var label: String?
    var reason: String?
    var reasonLabel: String?
}

struct RideComfortDto: Codable, Equatable {
    var quiet: Bool?
    var ac: String?
    var music: String?
    var notes: [String]?
    var items: [RideComfortItemDto]?
    var unable: [RideComfortUnableDto]?
    var disclaimer: String?
}

struct RideFinishingDto: Codable, Equatable {
    var finishing: Bool?
    var etaS: Int?
}

struct RideLateRiskDto: Codable, Equatable {
    var lateS: Int?
    var minutes: Int?
    var reason: String?
    var reasonLabel: String?
}

// MARK: - Endpoints

enum RidesApi {
    private static let base = "mobile/v1/rides"

    /// A fresh `Idempotency-Key` for a money-moving call (one per tap; a retry of the same tap reuses it).
    static func key() -> String { UUID().uuidString }

    private static func idem(_ key: String) -> [String: String] { ["Idempotency-Key": key] }

    private static func at(_ lat: Double?, _ lng: Double?) -> [String: String?] {
        ["lat": lat.map { String($0) }, "lng": lng.map { String($0) }]
    }

    static func features(lat: Double?, lng: Double?) async throws -> ApiEnvelope<RideFeaturesDto> {
        try await ApiClient.snake("\(base)/features", query: at(lat, lng))
    }

    /// R-16..R-18: the approved pickup / drop-off points around a place (`purpose` = pickup | dropoff).
    static func pickupPoints(lat: Double, lng: Double, purpose: String) async throws -> ApiEnvelope<RidePickupPointsDto> {
        var q = at(lat, lng)
        q["purpose"] = purpose
        return try await ApiClient.snake("\(base)/pickup-points", query: q)
    }

    /// R-INN-07 DORR Circle: my favourite captains, to choose from when booking.
    static func circle() async throws -> ApiEnvelope<RideCircleDriversDto> { try await ApiClient.snake("\(base)/circle") }

    /// "Search all captains" once the circle had no one.
    static func widenCircle(_ uuid: String, key: String) async throws -> ApiEnvelope<RideDto> {
        try await ApiClient.snake("\(base)/\(uuid)/circle/widen", method: "POST", body: [:], headers: idem(key))
    }

    static func caseOptions() async throws -> ApiEnvelope<RideCaseOptionsDto> { try await ApiClient.snake("\(base)/cases/options") }

    static func cases() async throws -> ApiEnvelope<[RideCaseDto]> { try await ApiClient.snake("\(base)/cases") }

    static func openCase(_ body: [String: JSONValue]) async throws -> ApiEnvelope<RideCaseDto> {
        try await ApiClient.snake("\(base)/cases", method: "POST", body: body)
    }

    static func caseOf(_ uuid: String) async throws -> ApiEnvelope<RideCaseDto> { try await ApiClient.snake("\(base)/cases/\(uuid)") }

    static func caseMessage(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<RideCaseDto> {
        try await ApiClient.snake("\(base)/cases/\(uuid)/messages", method: "POST", body: body)
    }

    static func appealCase(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<RideCaseDto> {
        try await ApiClient.snake("\(base)/cases/\(uuid)/appeal", method: "POST", body: body)
    }

    /// R-5, R-174: Home, Work, favourite places and recent destinations.
    static func places() async throws -> ApiEnvelope<RidePlacesDto> { try await ApiClient.snake("\(base)/places") }

    /// O-57: places as the rider types (from two letters), nearest first — the admin's map provider.
    static func placeSuggest(q: String, lat: Double?, lng: Double?) async throws -> ApiEnvelope<[PlaceHitDto]> {
        var query = at(lat, lng)
        query["q"] = q
        return try await ApiClient.snake("\(base)/places/suggest", query: query)
    }

    /// The chat's «send location»: the places right around a point (the admin's provider, then popular), nearest first.
    static func placeNearby(lat: Double, lng: Double) async throws -> ApiEnvelope<[PlaceHitDto]> {
        try await ApiClient.snake("\(base)/places/nearby", query: at(lat, lng))
    }

    /// O-59: the free captains' cars around me now, and the area channels to follow for their moves.
    static func nearbyCars(lat: Double, lng: Double) async throws -> ApiEnvelope<NearbyCarsDto> {
        try await ApiClient.snake("\(base)/nearby-cars", query: at(lat, lng))
    }

    /// O-57: before anything is typed — where people around here go most, and well-known places (≤ 50 km).
    static func placePopular(lat: Double, lng: Double) async throws -> ApiEnvelope<[PlaceHitDto]> {
        try await ApiClient.snake("\(base)/places/popular", query: at(lat, lng))
    }

    /// `{origin{lat,lng,address,point_id?}, destination?{…}, stops?[…], category?}` → one quote per category.
    static func quotes(_ body: [String: JSONValue]) async throws -> ApiEnvelope<[RideQuoteDto]> {
        try await ApiClient.snake("\(base)/quotes", method: "POST", body: body)
    }

    /// `{quote_uuid, fingerprint, payment_method}`. A card ride comes back `awaiting_payment`.
    static func book(_ body: [String: JSONValue], key: String) async throws -> ApiEnvelope<RideDto> {
        try await ApiClient.snake(base, method: "POST", body: body, headers: idem(key))
    }

    /// The ride going on now, or nil (`data` is null / empty when there is none).
    static func current() async throws -> RideDto? {
        let envelope: ApiEnvelope<RideDto>? = try? await ApiClient.snake("\(base)/current")
        return envelope?.data
    }

    static func ride(_ uuid: String) async throws -> ApiEnvelope<RideDto> { try await ApiClient.snake("\(base)/\(uuid)") }

    /// My rides (R-163..165): `tab` = current | scheduled | past.
    static func history(tab: String? = nil, page: Int = 1) async throws -> ApiEnvelope<RideHistoryDto> {
        try await ApiClient.snake(base, query: ["tab": tab, "page": String(page)])
    }

    static func cancelPreview(_ uuid: String) async throws -> ApiEnvelope<RideCancelPreviewDto> {
        try await ApiClient.snake("\(base)/\(uuid)/cancel/preview")
    }

    static func cancel(_ uuid: String, _ body: [String: JSONValue], key: String) async throws -> ApiEnvelope<RideDto> {
        try await ApiClient.snake("\(base)/\(uuid)/cancel", method: "POST", body: body, headers: idem(key))
    }

    static func rate(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/\(uuid)/rate", method: "POST", body: body)
    }

    /// R-61: wallet <-> cash before the trip starts.
    static func changePayment(_ uuid: String, _ body: [String: JSONValue], key: String) async throws -> ApiEnvelope<RideDto> {
        try await ApiClient.snake("\(base)/\(uuid)/payment-method", method: "POST", body: body, headers: idem(key))
    }

    /// R-144: a tip after the ride, from the wallet — all of it the captain's.
    static func tip(_ uuid: String, _ body: [String: JSONValue], key: String) async throws -> ApiEnvelope<RideDto> {
        try await ApiClient.snake("\(base)/\(uuid)/tip", method: "POST", body: body, headers: idem(key))
    }

    static func cancelReasons() async throws -> ApiEnvelope<[RideReasonDto]> { try await ApiClient.snake("\(base)/cancel-reasons") }

    static func ratingTags() async throws -> ApiEnvelope<[RideTagDto]> { try await ApiClient.snake("\(base)/rating-tags") }

    /// O-09: an in-app voice call with the captain (LiveKit).
    static func startCall(_ uuid: String) async throws -> ApiEnvelope<RideCallStartDto> {
        try await ApiClient.snake("\(base)/\(uuid)/calls", method: "POST", body: [:])
    }

    static func acceptCall(_ uuid: String, _ call: String) async throws -> ApiEnvelope<RideCallStartDto> {
        try await ApiClient.snake("\(base)/\(uuid)/calls/\(call)/accept", method: "POST", body: [:])
    }

    static func endCall(_ uuid: String, _ call: String) async throws -> ApiEnvelope<RideCallDto> {
        try await ApiClient.snake("\(base)/\(uuid)/calls/\(call)/end", method: "POST", body: [:])
    }

    static func messages(_ uuid: String) async throws -> ApiEnvelope<RideChatDto> { try await ApiClient.snake("\(base)/\(uuid)/messages") }

    /// D-294..D-297: something I left in the car.
    static func reportLostItem(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<LostItemDto> {
        try await ApiClient.snake("\(base)/\(uuid)/lost-item", method: "POST", body: body)
    }

    static func lostItems() async throws -> ApiEnvelope<[LostItemDto]> { try await ApiClient.snake("\(base)/lost-items") }

    static func lostItemMessage(_ lost: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<LostItemDto> {
        try await ApiClient.snake("\(base)/lost-items/\(lost)/messages", method: "POST", body: body)
    }

    static func closeLostItem(_ lost: String) async throws -> ApiEnvelope<LostItemDto> {
        try await ApiClient.snake("\(base)/lost-items/\(lost)/close", method: "POST", body: [:])
    }

    /// `{body}` or `{quick}`, with a `client_id` so a re-send lands once.
    static func send(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<RideMessageDto> {
        try await ApiClient.snake("\(base)/\(uuid)/messages", method: "POST", body: body)
    }

    /// The family link (R-86, R-111): made once per ride, then the same.
    static func share(_ uuid: String) async throws -> ApiEnvelope<RideShareDto> {
        try await ApiClient.snake("\(base)/\(uuid)/share", method: "POST", body: [:])
    }

    /// SOS (R-110): where I am; the answer carries the number to call.
    static func sos(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<RideSosDto> {
        try await ApiClient.snake("\(base)/\(uuid)/sos", method: "POST", body: body)
    }

    /// A new destination / one more stop during the ride: its effect first (R-92), then send it (R-94).
    static func changePreview(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<RideChangeDto> {
        try await ApiClient.snake("\(base)/\(uuid)/changes", method: "POST", body: body)
    }

    static func changeSend(_ uuid: String, _ change: String) async throws -> ApiEnvelope<RideChangeDto> {
        try await ApiClient.snake("\(base)/\(uuid)/changes/\(change)/send", method: "POST", body: [:])
    }

    // The Safety Center (R-219..230)

    static func safety() async throws -> ApiEnvelope<SafetyCenterDto> { try await ApiClient.snake("\(base)/safety") }

    static func addContact(_ body: [String: JSONValue]) async throws -> ApiEnvelope<[TrustedContactDto]> {
        try await ApiClient.snake("\(base)/safety/contacts", method: "POST", body: body)
    }

    static func updateContact(_ id: Int, _ body: [String: JSONValue]) async throws -> ApiEnvelope<[TrustedContactDto]> {
        try await ApiClient.snake("\(base)/safety/contacts/\(id)", method: "PUT", body: body)
    }

    static func deleteContact(_ id: Int) async throws -> ApiEnvelope<[TrustedContactDto]> {
        try await ApiClient.snake("\(base)/safety/contacts/\(id)", method: "DELETE")
    }

    static func savePreferences(_ body: [String: JSONValue]) async throws -> ApiEnvelope<SafetyPreferencesDto> {
        try await ApiClient.snake("\(base)/safety/preferences", method: "PUT", body: body)
    }

    static func unblock(_ id: Int) async throws -> ApiEnvelope<Ignored> { try await ApiClient.snake("\(base)/safety/blocks/\(id)", method: "DELETE") }

    static func unfavorite(_ driver: Int) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/safety/favorites/\(driver)", method: "DELETE")
    }

    static func shareWithContacts(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/\(uuid)/contacts/share", method: "POST", body: body)
    }

    static func blockDriver(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/\(uuid)/block", method: "POST", body: body)
    }

    static func favorite(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/\(uuid)/favorite", method: "POST", body: body)
    }

    static func report(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/\(uuid)/report", method: "POST", body: body)
    }

    static func vehicleMismatch(_ uuid: String, _ body: [String: JSONValue], key: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/\(uuid)/vehicle-mismatch", method: "POST", body: body, headers: idem(key))
    }

    static func answerCheck(_ uuid: String, _ check: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<RideSosDto> {
        try await ApiClient.snake("\(base)/\(uuid)/safety-checks/\(check)", method: "POST", body: body)
    }

    // Bidding (§9): the drivers' offers, take / decline one, raise my price

    static func bids(_ uuid: String) async throws -> ApiEnvelope<RideBidBoardDto> { try await ApiClient.snake("\(base)/\(uuid)/bids") }

    static func acceptBid(_ uuid: String, _ bid: String, key: String) async throws -> ApiEnvelope<RideDto> {
        try await ApiClient.snake("\(base)/\(uuid)/bids/\(bid)/accept", method: "POST", body: [:], headers: idem(key))
    }

    static func declineBid(_ uuid: String, _ bid: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/\(uuid)/bids/\(bid)/decline", method: "POST", body: [:])
    }

    /// `{price_minor}` higher than my current price.
    static func raise(_ uuid: String, priceMinor: Int) async throws -> ApiEnvelope<RideDto> {
        try await ApiClient.snake("\(base)/\(uuid)/raise", method: "POST", body: ["price_minor": .int(priceMinor)])
    }

    // Block 06 — DORR AI in rides

    /// «دُرر يفهم مقصدك»: is it here, and does my DORR AI plan allow it.
    static func aiStatus(lat: Double?, lng: Double?) async throws -> ApiEnvelope<RideAiStatusDto> {
        try await ApiClient.snake("\(base)/ai/status", query: at(lat, lng))
    }

    /// `{text, lat?, lng?}` — the trip in my own words → places on the admin's map + what else I said.
    static func aiUnderstand(_ body: [String: JSONValue]) async throws -> ApiEnvelope<RideAiUnderstandDto> {
        try await ApiClient.snake("\(base)/ai/understand", method: "POST", body: body)
    }

    /// AI-09 `{distance_m?, passengers?, categories?[]}` — the category I usually take for a trip like this.
    static func aiSuggest(_ body: [String: JSONValue]) async throws -> ApiEnvelope<RideAiSuggestDto> {
        try await ApiClient.snake("\(base)/ai/suggest", method: "POST", body: body)
    }

    /// «راحتي»: my trip preferences and the choices.
    static func comfort(lat: Double?, lng: Double?) async throws -> ApiEnvelope<RideComfortScreenDto> {
        try await ApiClient.snake("\(base)/comfort", query: at(lat, lng))
    }

    /// `{quiet, ac, music, notes[]}`
    static func saveComfort(_ body: [String: JSONValue]) async throws -> ApiEnvelope<RideComfortScreenDto> {
        try await ApiClient.snake("\(base)/comfort", method: "PUT", body: body)
    }
}
