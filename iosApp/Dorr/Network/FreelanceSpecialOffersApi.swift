import Foundation

/// FL-L09, special offers on the client's side (`mobile/v1/freelance/special-offers`) — the twin of Android's
/// `network/FreelanceSpecialOffersApi.kt`: what freelancers I finished work with offered me (custom work, a service level at a lower
/// price, a consultation at a discount), taking one — the work starts at once, a consultation with the time I pick — saying no, and
/// stopping a freelancer's special offers.
enum FreelanceSpecialOffersApi {
    private static let base = "mobile/v1/freelance/special-offers"

    /// `tab` = open · accepted · past
    static func list(tab: String? = nil, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[ClientSpecialOfferDto]> {
        try await ApiClient.snake(base, query: ["tab": tab, "page": String(page), "per_page": String(perPage)])
    }

    /// How many wait for me (the badge on the freelance home).
    static func waiting() async throws -> ApiEnvelope<SpecialOfferWaitingDto> {
        try await ApiClient.snake("\(base)/waiting")
    }

    static func show(_ uuid: String) async throws -> ApiEnvelope<ClientSpecialOfferDto> {
        try await ApiClient.snake("\(base)/\(uuid)")
    }

    /// Custom work and a service start at once; a consultation needs the time picked (`minutes`, `startsAt`, `topic`).
    /// `recordingConsent`: FL-L16 — a consultation is recorded; accepting one needs the client's yes (nil for the other kinds).
    static func accept(_ uuid: String, requestId: String, minutes: Int? = nil, startsAt: String? = nil, topic: String? = nil, recordingConsent: Bool? = nil) async throws -> ApiEnvelope<ClientSpecialOfferDto> {
        var body: [String: JSONValue] = ["request_id": .string(requestId)]
        if let minutes { body["minutes"] = .int(minutes) }
        if let startsAt { body["starts_at"] = .string(startsAt) }
        if let topic { body["topic"] = .string(topic) }
        if let recordingConsent { body["recording_consent"] = .bool(recordingConsent) }
        return try await ApiClient.snake("\(base)/\(uuid)/accept", method: "POST", body: body)
    }

    static func decline(_ uuid: String, reason: String?, mute: Bool) async throws -> ApiEnvelope<ClientSpecialOfferDto> {
        try await ApiClient.snake("\(base)/\(uuid)/decline", method: "POST", body: ["reason": reason.map(JSONValue.string) ?? .null, "mute": .bool(mute)])
    }

    static func mute(freelancer: String, on: Bool) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/freelancers/\(freelancer)/mute", method: "PUT", body: ["on": .bool(on)])
    }
}

struct SpecialOfferWaitingDto: Codable, Equatable {
    var count: Int?
}

struct ClientSpecialOfferDto: Codable, Equatable, Identifiable {
    var uuid: String
    var id: String { uuid }
    /// custom · service · consultation
    var kind: String?
    /// sent · accepted · declined · withdrawn · expired
    var status: String?
    var statusLabel: String?
    var title: String?
    var message: String?
    var description: String?
    var specialty: SpecialOfferSpecialtyDto?
    var service: SpecialOfferServiceDto?
    var consultation: SpecialOfferConsultationDto?
    var priceMinor: Int?
    var listPriceMinor: Int?
    var savingMinor: Int?
    var discountBp: Int?
    var deliveryDays: Int?
    var revisions: Int?
    var currency: String?
    var decimals: Int?
    var money: SpecialOfferClientMoneyDto?
    var expiresAt: String?
    var sentAt: String?
    var answeredAt: String?
    var freelancer: SpecialOfferFreelancerDto?
    var result: SpecialOfferResultDto?
    var muted: Bool?
    var can: SpecialOfferClientCanDto?
}

struct SpecialOfferSpecialtyDto: Codable, Equatable {
    var id: Int?
    var name: String?
}

struct SpecialOfferServiceDto: Codable, Equatable {
    var uuid: String?
    var title: String?
    var level: String?
    var levelLabel: String?
    var tierName: String?
    var features: [String]?
}

struct SpecialOfferConsultationDto: Codable, Equatable {
    var uuid: String?
    var title: String?
    var lengths: [SpecialOfferLengthDto]?
}

/// One length of a consultation at the offered price (and the usual one), with the fee and the total.
struct SpecialOfferLengthDto: Codable, Equatable {
    var minutes: Int
    var listPriceMinor: Int?
    var priceMinor: Int?
    var clientFeeMinor: Int?
    var totalMinor: Int?
}

struct SpecialOfferClientMoneyDto: Codable, Equatable {
    var priceMinor: Int?
    var clientFeeMinor: Int?
    var totalMinor: Int?
}

struct SpecialOfferFreelancerDto: Codable, Equatable {
    var uuid: String?
    var name: String?
    var headline: String?
    var avatarUrl: String?
    var verified: Bool?
}

struct SpecialOfferResultDto: Codable, Equatable {
    var project: String?
    var serviceOrder: String?
    var consultation: String?
}

struct SpecialOfferClientCanDto: Codable, Equatable {
    var accept: Bool?
    var decline: Bool?
}
