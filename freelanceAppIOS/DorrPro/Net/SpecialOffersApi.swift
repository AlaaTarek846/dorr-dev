import Foundation

// freelanceApp `net/SpecialOffersApi.kt`.

/// FL-L09, special offers to clients I finished work with, under `/api/freelance/v1/special-offers`: who I can offer, what I can offer
/// (custom work, one of my services at a lower price, one of my consultations at a discount), what a price leaves me, sending, my offers
/// and taking one back before it is answered. The client's acceptance starts the work at once.
enum SpecialOffersApi {
    private static let base = Api.freelance

    /// `tab` = open · accepted · past
    static func list(tab: String? = nil, page: Int = 1, perPage: Int = 15) async -> ApiResult<[SpecialOfferDto]> {
        await Api.call("GET", base + "special-offers", query: ["tab": tab, "page": "\(page)", "per_page": "\(perPage)"])
    }

    static func clients(_ search: String? = nil) async -> ApiResult<[PastClientDto]> {
        await Api.call("GET", base + "special-offers/clients", query: ["search": search])
    }

    /// My services and consultations that can be offered, my specialties, the rules, today's room and how my offers did.
    static func compose() async -> ApiResult<SpecialOfferComposeDto> { await Api.call("GET", base + "special-offers/compose") }

    static func quote(_ priceMinor: Int, specialtyId: Int? = nil) async -> ApiResult<SpecialOfferQuoteDto> {
        await Api.call("GET", base + "special-offers/quote", query: ["price_minor": "\(priceMinor)", "specialty_id": specialtyId.map { "\($0)" }])
    }

    static func send(_ body: SpecialOfferRequest) async -> ApiResult<SpecialOfferDto> { await Api.call("POST", base + "special-offers", body: body.json) }

    static func show(_ uuid: String) async -> ApiResult<SpecialOfferDto> { await Api.call("GET", base + "special-offers/\(uuid)") }

    static func withdraw(_ uuid: String) async -> ApiResult<SpecialOfferDto> { await Api.call("POST", base + "special-offers/\(uuid)/withdraw") }
}

struct PastClientDto: Decodable, Equatable, Identifiable {
    var key: String?
    var name: String?
    var avatarUrl: String?
    var works: Int?
    var projects: Int?
    var consultations: Int?
    var lastTitle: String?
    var lastAt: String?
    var openOffer: String?
    var canSend: Bool?
    /// has_open · cooldown
    var blockedReason: String?
    var blockedUntil: String?

    var id: String { key ?? "" }
}

struct SpecialOfferComposeDto: Decodable, Equatable {
    @CurrencyLabel var currency: String?
    var decimals: Int?
    /// Which kinds can be sent now (custom · service · consultation).
    var kinds: [String: Bool]?
    var services: [SpecialServiceDto]?
    var consultations: [SpecialConsultationDto]?
    var specialties: [SpecialSpecialtyDto]?
    var rules: SpecialOfferRulesDto?
    var minPriceMinor: Int?
    var todayLeft: Int?
    var stats: SpecialOfferStatsDto?
    var counts: [String: Int]?
}

struct SpecialServiceDto: Decodable, Equatable {
    var uuid: String?
    var title: String?
    var levels: [SpecialLevelDto]?
}

struct SpecialLevelDto: Decodable, Equatable {
    var level: String?
    var label: String?
    var name: String?
    var priceMinor: Int?
    var deliveryDays: Int?
    var revisions: Int?
    var features: [String]?
}

struct SpecialConsultationDto: Decodable, Equatable {
    var uuid: String?
    var title: String?
    var lengths: [SpecialLengthDto]?
}

struct SpecialLengthDto: Decodable, Equatable {
    var minutes: Int?
    var priceMinor: Int?
    var listPriceMinor: Int?
    var clientFeeMinor: Int?
    var totalMinor: Int?
}

struct SpecialSpecialtyDto: Decodable, Equatable {
    var id: Int?
    var name: String?
    var isPrimary: Bool?
}

struct SpecialOfferRulesDto: Decodable, Equatable {
    var dailyLimit: Int?
    var openPerClient: Int?
    var cooldownDays: Int?
    var maxValidDays: Int?
    var defaultValidDays: Int?
    var minDiscountBp: Int?
    var maxDiscountBp: Int?
}

struct SpecialOfferStatsDto: Decodable, Equatable {
    var sent: Int?
    var seen: Int?
    var accepted: Int?
    var acceptRate: Int?
}

struct SpecialOfferQuoteDto: Decodable, Equatable {
    var priceMinor: Int?
    var commissionMinor: Int?
    var netMinor: Int?
    var clientFeeMinor: Int?
    var clientTotalMinor: Int?
}

/// Gson leaves a null field out of the body.
struct SpecialOfferRequest {
    let client: String
    let kind: String
    var title: String? = nil
    var description: String? = nil
    var message: String? = nil
    var specialtyId: Int? = nil
    var service: String? = nil
    var level: String? = nil
    var consultation: String? = nil
    var priceMinor: Int? = nil
    var discountBp: Int? = nil
    var deliveryDays: Int? = nil
    var revisions: Int? = nil
    var validDays: Int? = nil
    let requestId: String

    var json: JsonBody {
        var body: JsonBody = ["client": client, "kind": kind, "request_id": requestId]
        if let title { body["title"] = title }
        if let description { body["description"] = description }
        if let message { body["message"] = message }
        if let specialtyId { body["specialty_id"] = specialtyId }
        if let service { body["service"] = service }
        if let level { body["level"] = level }
        if let consultation { body["consultation"] = consultation }
        if let priceMinor { body["price_minor"] = priceMinor }
        if let discountBp { body["discount_bp"] = discountBp }
        if let deliveryDays { body["delivery_days"] = deliveryDays }
        if let revisions { body["revisions"] = revisions }
        if let validDays { body["valid_days"] = validDays }
        return body
    }
}

struct SpecialOfferDto: Decodable, Equatable, Identifiable {
    var uuid: String?
    /// custom · service · consultation
    var kind: String?
    var kindLabel: String?
    /// sent · accepted · declined · withdrawn · expired
    var status: String?
    var statusLabel: String?
    var title: String?
    var message: String?
    var description: String?
    var specialty: SpecialSpecialtyDto?
    var service: SpecialOfferServiceDto?
    var consultation: SpecialOfferConsultationDto?
    var priceMinor: Int?
    var listPriceMinor: Int?
    var savingMinor: Int?
    var discountBp: Int?
    var deliveryDays: Int?
    var revisions: Int?
    @CurrencyLabel var currency: String?
    var decimals: Int?
    var money: SpecialOfferMoneyDto?
    var expiresAt: String?
    var sentAt: String?
    var viewedAt: String?
    var answeredAt: String?
    var declineReason: String?
    var client: SpecialOfferClientDto?
    var result: SpecialOfferResultDto?
    var can: SpecialOfferCanDto?

    var id: String { uuid ?? "" }
}

struct SpecialOfferServiceDto: Decodable, Equatable {
    var uuid: String?
    var title: String?
    var level: String?
    var levelLabel: String?
    var tierName: String?
    var features: [String]?
}

struct SpecialOfferConsultationDto: Decodable, Equatable {
    var uuid: String?
    var title: String?
    var lengths: [SpecialLengthDto]?
}

struct SpecialOfferMoneyDto: Decodable, Equatable {
    var priceMinor: Int?
    var commissionMinor: Int?
    var netMinor: Int?
}

struct SpecialOfferClientDto: Decodable, Equatable {
    var key: String?
    var name: String?
    var avatarUrl: String?
}

struct SpecialOfferResultDto: Decodable, Equatable {
    var project: String?
    var serviceOrder: String?
    var consultation: String?
}

struct SpecialOfferCanDto: Decodable, Equatable {
    var withdraw: Bool?
}
