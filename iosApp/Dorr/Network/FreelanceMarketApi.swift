import Foundation

// androidApp `network/FreelanceServicesApi.kt` and `FreelanceConsultationsApi.kt` — the client's side of ready-made services
// (FL-L04: finding them in my country, what a level with extras comes to with the fee, ordering one — a `request_id` makes a double
// tap one order — and my orders; FL-L05 monthly subscriptions; FL-L10 waiting in line when the freelancer is full) and of paid
// consultations (FL-L07: the ones on offer, free times as UTC instants shown on the phone's clock, booking with the money held at
// once, my consultations, cancelling, confirming, rating and complaining). DTOs are optional structs read with `ApiClient.snake`.

/// FL-L01 phase 4: the badge of the plan a freelancer holds (`plan` = plus, pro…; `name` in my language).
struct FreelancePlanBadgeDto: Codable, Equatable {
    var plan: String?
    var name: String?
}

/// FL-L08: a skill the freelancer passed the test of.
struct FreelanceSkillBadgeDto: Codable, Equatable {
    var skillId: Int?
    var name: String?
    var scorePercent: Int?
}

// MARK: - services (FL-L04 / L05 / L10)

struct MarketFreelancerDto: Codable, Equatable {
    var uuid: String?
    var name: String?
    var headline: String?
    var avatarUrl: String?
    var rating: Double?
    var reviewsCount: Int?
    var completedProjects: Int?
    var onTimeRate: Int?
    var badge: FreelancePlanBadgeDto?
    var skillBadges: [FreelanceSkillBadgeDto]?
}

struct MarketSpecialtyDto: Codable, Equatable {
    var id: Int?
    var name: String?
}

struct MarketServiceCardDto: Codable, Equatable, Identifiable {
    var uuid: String
    var title: String?
    var specialty: MarketSpecialtyDto?
    var currency: String?
    var decimals: Int?
    var startingPriceMinor: Int?
    var deliveryDays: Int?
    var levels: Int?
    var cover: String?
    var completedOrders: Int?
    var freelancer: MarketFreelancerDto?
    /// FL-L10: the freelancer is at their cap of running projects.
    var atCapacity: Bool?
    /// FL-L05: once · monthly (the price is then per month).
    var billing: String?

    var id: String { uuid }
}

struct MarketTierDto: Codable, Equatable {
    var level: String?
    var name: String?
    var description: String?
    var priceMinor: Int?
    var deliveryDays: Int?
    var revisions: Int?
    var features: [String]?
    /// FL-L09: this level's price after my loyalty step (nil: none).
    var loyaltyPriceMinor: Int?
}

struct MarketAddonDto: Codable, Equatable {
    var id: Int
    var title: String?
    var priceMinor: Int?
    var extraDays: Int?
}

struct MarketImageDto: Codable, Equatable {
    var id: Int?
    var url: String?
}

struct MarketSubscriptionTermsDto: Codable, Equatable {
    var periodDays: Int?
    var renewWindowDays: Int?
    var graceDays: Int?
    var noticeDays: Int?
}

/// [status] waiting (at [position]) or offered (the place is kept for me until [holdUntil]).
struct MarketWaitDto: Codable, Equatable {
    var status: String?
    var position: Int?
    var holdUntil: String?
}

/// FL-L10: whether there is room for me with this freelancer, how many wait, and my own place (nil when I am not in line).
struct MarketCapacityDto: Codable, Equatable {
    var full: Bool?
    var waiting: Int?
    var mine: MarketWaitDto?
}

struct MarketServiceDto: Codable, Equatable {
    var uuid: String
    var title: String?
    var description: String?
    var requirements: String?
    var specialty: MarketSpecialtyDto?
    var currency: String?
    var decimals: Int?
    var startingPriceMinor: Int?
    var tiers: [MarketTierDto]?
    var addons: [MarketAddonDto]?
    var images: [MarketImageDto]?
    var completedOrders: Int?
    var freelancer: MarketFreelancerDto?
    var answerHours: Int?
    var capacity: MarketCapacityDto?
    var billing: String?
    /// FL-L05: how a monthly one runs; nil for one sold once.
    var subscription: MarketSubscriptionTermsDto?
    /// FL-L09: the freelancer's loyalty program as it stands for me (nil: none, or a monthly service).
    var loyalty: FlLoyaltyViewDto?
    /// FL-L13: who owns the work once it is paid and what I may do with it (nil: the feature is off).
    var rights: FreelanceRightsSummaryDto?
}

struct MarketQuoteDto: Codable, Equatable {
    var level: String?
    var priceMinor: Int?
    var clientFeeMinor: Int?
    var totalMinor: Int?
    var deliveryDays: Int?
    var revisions: Int?
    var currency: String?
    var decimals: Int?
    /// FL-L09: the usual price, the discount (basis points) and what it saves me; nil: none.
    var listPriceMinor: Int?
    var loyaltyBp: Int?
    var savingMinor: Int?
}

struct MarketOrderServiceDto: Codable, Equatable {
    var uuid: String?
    var title: String?
}

struct MarketOrderAddonDto: Codable, Equatable {
    var title: String?
    var priceMinor: Int?
    var extraDays: Int?
}

struct MarketOrderMoneyDto: Codable, Equatable {
    var priceMinor: Int?
    var clientFeeMinor: Int?
    var totalMinor: Int?
}

struct MarketOrderFreelancerDto: Codable, Equatable {
    var uuid: String?
    var name: String?
}

struct MarketOrderCanDto: Codable, Equatable {
    var cancel: Bool?
}

struct MarketOrderSubDto: Codable, Equatable {
    var uuid: String?
    var cycle: Int?
}

struct MarketOrderDto: Codable, Equatable, Identifiable {
    var uuid: String
    /// awaiting · active · completed · declined · expired · cancelled
    var status: String?
    var service: MarketOrderServiceDto?
    var level: String?
    var tierName: String?
    var features: [String]?
    var addons: [MarketOrderAddonDto]?
    var priceMinor: Int?
    /// FL-L09: the usual price and the loyalty discount taken off it; nil: none.
    var listPriceMinor: Int?
    var loyaltyBp: Int?
    var savingMinor: Int?
    var currency: String?
    var decimals: Int?
    var deliveryDays: Int?
    var revisions: Int?
    var note: String?
    var project: String?
    /// FL-L05: the monthly subscription this order is one month of.
    var subscription: MarketOrderSubDto?
    var answerBy: String?
    var money: MarketOrderMoneyDto?
    var freelancer: MarketOrderFreelancerDto?
    var can: MarketOrderCanDto?
    var createdAt: String?

    var id: String { uuid }
    var state: String { status ?? "awaiting" }
}

struct MarketRenewDto: Codable, Equatable {
    var opensAt: String?
    var dueBy: String?
    var open: Bool?
}

struct MarketSubCancelDto: Codable, Equatable {
    var by: String?
    var endsAt: String?
}

struct MarketSubMonthDto: Codable, Equatable {
    var uuid: String?
    var cycle: Int?
    var status: String?
}

struct MarketSubCanDto: Codable, Equatable {
    var renew: Bool?
    var cancel: Bool?
    var keep: Bool?
}

/// FL-L05: my monthly subscription to a service, at the terms kept from its start.
struct MarketSubscriptionDto: Codable, Equatable, Identifiable {
    var uuid: String
    /// pending · active · ended
    var status: String?
    var endReason: String?
    var service: MarketOrderServiceDto?
    var level: String?
    var tierName: String?
    var priceMinor: Int?
    var currency: String?
    var decimals: Int?
    var money: MarketOrderMoneyDto?
    var cycle: Int?
    var periodEndsAt: String?
    var renew: MarketRenewDto?
    var cancel: MarketSubCancelDto?
    var noticeDays: Int?
    var current: MarketSubMonthDto?
    var freelancer: MarketOrderFreelancerDto?
    var can: MarketSubCanDto?

    var id: String { uuid }
}

enum FreelanceServicesApi {
    private static let base = "mobile/v1/freelance"

    static func browse(search: String? = nil, specialtyId: Int? = nil, maxPrice: Int? = nil, maxDays: Int? = nil, sort: String? = nil,
                       freelancer: String? = nil, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[MarketServiceCardDto]> {
        try await ApiClient.snake("\(base)/services", query: [
            "search": search, "specialty_id": specialtyId.map(String.init), "max_price": maxPrice.map(String.init), "max_days": maxDays.map(String.init),
            "sort": sort, "freelancer": freelancer, "page": String(page), "per_page": String(perPage),
        ])
    }

    static func service(_ uuid: String) async throws -> ApiEnvelope<MarketServiceDto> {
        try await ApiClient.snake("\(base)/services/\(uuid)")
    }

    /// The addons go as `addons[0]=…&addons[1]=…`, which the server reads as a list.
    static func quote(_ uuid: String, level: String, addons: [Int]) async throws -> ApiEnvelope<MarketQuoteDto> {
        var query: [String: String?] = ["level": level]
        for (i, a) in addons.enumerated() { query["addons[\(i)]"] = String(a) }
        return try await ApiClient.snake("\(base)/services/\(uuid)/quote", query: query)
    }

    static func order(_ uuid: String, level: String, addons: [Int], note: String?, requestId: String) async throws -> ApiEnvelope<MarketOrderDto> {
        try await ApiClient.snake("\(base)/services/\(uuid)/order", method: "POST", body: [
            "level": .string(level), "addons": .array(addons.map(JSONValue.int)), "note": note.map(JSONValue.string) ?? .null, "request_id": .string(requestId),
        ])
    }

    /// FL-L10: wait in line while the freelancer is full, or step out of it. Both answer where I stand now.
    static func joinWaitlist(_ uuid: String) async throws -> ApiEnvelope<MarketCapacityDto> {
        try await ApiClient.snake("\(base)/services/\(uuid)/waitlist", method: "POST", body: [:])
    }

    static func leaveWaitlist(_ uuid: String) async throws -> ApiEnvelope<MarketCapacityDto> {
        try await ApiClient.snake("\(base)/services/\(uuid)/waitlist", method: "DELETE")
    }

    /// `status` = awaiting · active · completed · ended
    static func orders(status: String? = nil, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[MarketOrderDto]> {
        try await ApiClient.snake("\(base)/service-orders", query: ["status": status, "page": String(page), "per_page": String(perPage)])
    }

    static func orderOf(_ uuid: String) async throws -> ApiEnvelope<MarketOrderDto> {
        try await ApiClient.snake("\(base)/service-orders/\(uuid)")
    }

    static func cancel(_ uuid: String) async throws -> ApiEnvelope<MarketOrderDto> {
        try await ApiClient.snake("\(base)/service-orders/\(uuid)/cancel", method: "POST", body: [:])
    }

    /// FL-L05: my monthly subscriptions (active first).
    static func subscriptions(page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[MarketSubscriptionDto]> {
        try await ApiClient.snake("\(base)/service-subscriptions", query: ["page": String(page), "per_page": String(perPage)])
    }

    /// Confirm the next month: made, started and its money held from the wallet in one step.
    static func renewSubscription(_ uuid: String) async throws -> ApiEnvelope<MarketSubscriptionDto> {
        try await ApiClient.snake("\(base)/service-subscriptions/\(uuid)/renew", method: "POST", body: [:])
    }

    /// `action` = cancel (with notice) · keep (take my ending back)
    static func subscriptionAct(_ uuid: String, action: String) async throws -> ApiEnvelope<MarketSubscriptionDto> {
        try await ApiClient.snake("\(base)/service-subscriptions/\(uuid)/\(action)", method: "POST", body: [:])
    }
}

// MARK: - consultations (FL-L07)

/// On a freelancer's profile: one of the consultations they list now, from this total, in these lengths.
struct ConsultTeaserDto: Codable, Equatable {
    var uuid: String
    var title: String?
    var fromMinor: Int?
    var currency: String?
    var minutes: [Int]?
}

struct ConsultFreelancerDto: Codable, Equatable {
    var uuid: String?
    var name: String?
    var headline: String?
    var avatarUrl: String?
    var rating: Double?
    var reviewsCount: Int?
    var completedProjects: Int?
    var consultationsDone: Int?
    /// What clients said (on the page before booking), from projects and consultations.
    var reviews: [FreelanceReviewLineDto]?
}

/// One consultation as a card in the list: who, what, from what price and the first free time.
struct ConsultCardDto: Codable, Equatable, Identifiable {
    var uuid: String
    var title: String?
    var about: String?
    var minutes: [Int]?
    var fromMinor: Int?
    var currency: String?
    var decimals: Int?
    var nextSlot: String?
    var freelancer: ConsultFreelancerDto?

    var id: String { uuid }
}

struct ConsultOptionDto: Codable, Equatable {
    var minutes: Int
    var priceMinor: Int?
    var clientFeeMinor: Int?
    var totalMinor: Int?
    /// FL-L09: the length's usual price and the loyalty discount (basis points) taken off `priceMinor`; nil: none.
    var listPriceMinor: Int?
    var loyaltyBp: Int?
}

struct ConsultRulesDto: Codable, Equatable {
    var freeCancelHours: Int?
    var complaintHours: Int?
    var responseHours: Int?
    var maxDaysAhead: Int?
}

struct ConsultOfferDto: Codable, Equatable {
    var uuid: String?
    var freelancer: ConsultFreelancerDto?
    var title: String?
    var about: String?
    var options: [ConsultOptionDto]?
    var currency: String?
    var decimals: Int?
    var timezone: String?
    var nextSlot: String?
    var rules: ConsultRulesDto?
    /// FL-L09: the freelancer's loyalty program as it stands for me (nil: none).
    var loyalty: FlLoyaltyViewDto?
}

struct ConsultSlotsDto: Codable, Equatable {
    var minutes: Int?
    var slots: [String]?
}

struct ConsultMoneyDto: Codable, Equatable {
    var priceMinor: Int?
    var clientFeeMinor: Int?
    var totalMinor: Int?
}

struct ConsultBookingRulesDto: Codable, Equatable {
    var freeCancelUntil: String?
    var releaseAt: String?
    var freeCancelHours: Int?
    var complaintHours: Int?
}

struct ConsultCancelDto: Codable, Equatable {
    var by: String?
    var reason: String?
    var late: Bool?
}

struct ConsultComplaintDto: Codable, Equatable {
    var text: String?
}

struct ConsultResolutionDto: Codable, Equatable {
    var decision: String?
    var note: String?
}

struct ConsultRatingDto: Codable, Equatable {
    var stars: Double?
    var comment: String?
    var removed: Bool?
    var at: String?
}

/// The client's rating of one consultation: whether it can be rated now (and until when), and the one given.
struct ConsultReviewDto: Codable, Equatable {
    var canRate: Bool?
    var rateUntil: String?
    var rating: ConsultRatingDto?
}

struct ConsultCanDto: Codable, Equatable {
    var cancel: Bool?
    var cancelFree: Bool?
    var confirm: Bool?
    var complain: Bool?
}

struct ConsultDto: Codable, Equatable, Identifiable {
    var uuid: String
    /// booked · completed · cancelled · disputed · refunded
    var status: String?
    /// upcoming · live · ended while booked, else the status
    var phase: String?
    var offer: String?
    var title: String?
    var minutes: Int?
    var startsAt: String?
    var endsAt: String?
    var topic: String?
    var currency: String?
    var decimals: Int?
    /// FL-L09: the usual price and the loyalty discount taken off it; nil: none.
    var listPriceMinor: Int?
    var loyaltyBp: Int?
    var savingMinor: Int?
    var money: ConsultMoneyDto?
    var freelancer: ConsultFreelancerDto?
    var rules: ConsultBookingRulesDto?
    var respondBy: String?
    var cancel: ConsultCancelDto?
    var complaint: ConsultComplaintDto?
    var resolution: ConsultResolutionDto?
    var review: ConsultReviewDto?
    var can: ConsultCanDto?
    /// FL-L16: the video session (nil on older servers).
    var session: ConsultSessionDto?

    var id: String { uuid }
}

// MARK: - FL-L16: the video session

/// The session as one side sees it: the room's hours, who is in, who came when, and the recorded parts.
struct ConsultSessionDto: Codable, Equatable {
    /// LiveKit is set up on the server.
    var available: Bool?
    var roomOpen: Bool?
    var opensAt: String?
    var startsAt: String?
    var endsAt: String?
    /// by = time · staff
    var closed: ConsultSessionClosedDto?
    var recorded: Bool?
    /// The recording bucket is set up (without it the session runs unrecorded).
    var recordingAvailable: Bool?
    var inRoom: ConsultSidesDto?
    var attendance: [ConsultAttendanceDto]?
    var recordings: [ConsultRecordingDto]?
    var recordingsDeleteAt: String?
    var recordingsHeld: Bool?
    var recordingsDeletedAt: String?
    var can: ConsultSessionCanDto?
}

struct ConsultSessionClosedDto: Codable, Equatable {
    var by: String?
    var at: String?
}

struct ConsultSidesDto: Codable, Equatable {
    var client: Bool?
    var freelancer: Bool?
}

struct ConsultAttendanceDto: Codable, Equatable {
    /// client · freelancer
    var side: String?
    var joinedAt: String?
    var leftAt: String?
}

struct ConsultRecordingDto: Codable, Equatable, Identifiable {
    var uuid: String
    var part: Int?
    /// recording · processing · moving · stored · deleted
    var status: String?
    var durationSeconds: Int?
    var sizeBytes: Int?
    var startedAt: String?
    var stoppedAt: String?
    /// Signed for me, good for 15 minutes; only while `stored`.
    var downloadUrl: String?
    var watchUrl: String?

    var id: String { uuid }
}

struct ConsultSessionCanDto: Codable, Equatable {
    var join: Bool?
    var noShow: Bool?
}

/// A ticket into the room: LiveKit's address and a token good until the end, the hours on the server's clock, and the other side.
struct ConsultJoinDto: Codable, Equatable {
    var url: String
    var token: String
    var room: String?
    var identity: String?
    var startsAt: String?
    var endsAt: String?
    var serverTime: String?
    var warnBeforeEndMinutes: Int?
    var recording: Bool?
    var other: ConsultJoinOtherDto?
}

struct ConsultJoinOtherDto: Codable, Equatable {
    var side: String?
    var name: String?
    var inRoom: Bool?
}

enum FreelanceConsultationsApi {
    private static let base = "mobile/v1/freelance"

    static func market(search: String? = nil, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[ConsultCardDto]> {
        try await ApiClient.snake("\(base)/consultation-offers", query: ["search": search, "page": String(page), "per_page": String(perPage)])
    }

    static func offer(_ uuid: String) async throws -> ApiEnvelope<ConsultOfferDto> {
        try await ApiClient.snake("\(base)/consultation-offers/\(uuid)")
    }

    static func slots(_ uuid: String, minutes: Int) async throws -> ApiEnvelope<ConsultSlotsDto> {
        try await ApiClient.snake("\(base)/consultation-offers/\(uuid)/slots", query: ["minutes": String(minutes)])
    }

    /// `tab` = upcoming · past · cancelled
    static func list(tab: String? = nil, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[ConsultDto]> {
        try await ApiClient.snake("\(base)/consultations", query: ["tab": tab, "page": String(page), "per_page": String(perPage)])
    }

    static func show(_ uuid: String) async throws -> ApiEnvelope<ConsultDto> {
        try await ApiClient.snake("\(base)/consultations/\(uuid)")
    }

    static func book(offer: String, minutes: Int, startsAt: String, topic: String, requestId: String) async throws -> ApiEnvelope<ConsultDto> {
        try await ApiClient.snake("\(base)/consultations", method: "POST", body: [
            "offer": .string(offer), "minutes": .int(minutes), "starts_at": .string(startsAt), "topic": .string(topic), "request_id": .string(requestId),
            // FL-L16: the session is recorded; booking needs the client's yes.
            "recording_consent": .bool(true),
        ])
    }

    static func cancel(_ uuid: String, reason: String? = nil) async throws -> ApiEnvelope<ConsultDto> {
        try await ApiClient.snake("\(base)/consultations/\(uuid)/cancel", method: "POST", body: ["reason": reason.map(JSONValue.string) ?? .null])
    }

    static func confirm(_ uuid: String) async throws -> ApiEnvelope<ConsultDto> {
        try await ApiClient.snake("\(base)/consultations/\(uuid)/confirm", method: "POST", body: [:])
    }

    /// Once it is over and paid: 1–5 stars and a comment, once; it shows on the freelancer's profile at once.
    static func review(_ uuid: String, stars: Int, comment: String?) async throws -> ApiEnvelope<ConsultDto> {
        try await ApiClient.snake("\(base)/consultations/\(uuid)/review", method: "POST", body: ["stars": .int(stars), "comment": comment.map(JSONValue.string) ?? .null])
    }

    static func complain(_ uuid: String, text: String) async throws -> ApiEnvelope<ConsultDto> {
        try await ApiClient.snake("\(base)/consultations/\(uuid)/complain", method: "POST", body: ["text": .string(text)])
    }

    // FL-L16: the consultation's video session (a LiveKit room, recorded).

    /// The room's state, who came, and the recordings with fresh signed links (good for 15 minutes).
    static func session(_ uuid: String) async throws -> ApiEnvelope<ConsultSessionDto> {
        try await ApiClient.snake("\(base)/consultations/\(uuid)/session")
    }

    /// A ticket into the room. 409 `freelance_consultation_session_not_yet` (`data.opens_at`) · `_over` · `_closed` · `_not_booked`; 503 `_unavailable`.
    static func joinSession(_ uuid: String) async throws -> ApiEnvelope<ConsultJoinDto> {
        try await ApiClient.snake("\(base)/consultations/\(uuid)/session/join", method: "POST", body: [:])
    }

    /// The app is in the room now: attendance starts, the recording starts, the other side is told the first time.
    static func sessionConnected(_ uuid: String) async throws -> ApiEnvelope<ConsultSessionDto> {
        try await ApiClient.snake("\(base)/consultations/\(uuid)/session/connected", method: "POST", body: [:])
    }

    /// The app left the room (coming back while time is left is fine).
    static func leaveSession(_ uuid: String) async throws -> ApiEnvelope<ConsultSessionDto> {
        try await ApiClient.snake("\(base)/consultations/\(uuid)/session/leave", method: "POST", body: [:])
    }

    /// The freelancer never came in (`can.no_show`): a complaint for the staff with the attendance as proof.
    static func noShow(_ uuid: String) async throws -> ApiEnvelope<ConsultDto> {
        try await ApiClient.snake("\(base)/consultations/\(uuid)/no-show", method: "POST", body: [:])
    }
}
