import Foundation

// freelanceApp `net/ConsultationsApi.kt`.

/// FL-L07, paid consultations under `/api/freelance/v1/consultations`: my consultation listings (like ready-made services: a title,
/// lengths with a price each and weekly hours in my time zone, reviewed by the staff before clients see them), the bookings clients
/// made, and answering or cancelling one. The session itself is a video call (FL-L16), so there is no room.
enum ConsultationsApi {
    private static let base = Api.freelance

    /// What the form needs: lengths, limits, the rules and my numbers.
    static func info() async -> ApiResult<ConsultationInfoDto> { await Api.call("GET", base + "consultations/info") }

    static func offers() async -> ApiResult<[ConsultationOfferDto]> { await Api.call("GET", base + "consultations/offers") }

    static func offer(_ uuid: String) async -> ApiResult<ConsultationOfferDto> { await Api.call("GET", base + "consultations/offers/\(uuid)") }

    static func create(_ body: ConsultationOfferRequest) async -> ApiResult<ConsultationOfferDto> {
        await Api.call("POST", base + "consultations/offers", body: body.json)
    }

    static func update(_ uuid: String, _ body: ConsultationOfferRequest) async -> ApiResult<ConsultationOfferDto> {
        await Api.call("PUT", base + "consultations/offers/\(uuid)", body: body.json)
    }

    static func submit(_ uuid: String) async -> ApiResult<ConsultationOfferDto> { await Api.call("POST", base + "consultations/offers/\(uuid)/submit") }

    static func pause(_ uuid: String) async -> ApiResult<ConsultationOfferDto> { await Api.call("POST", base + "consultations/offers/\(uuid)/pause") }

    static func resume(_ uuid: String) async -> ApiResult<ConsultationOfferDto> { await Api.call("POST", base + "consultations/offers/\(uuid)/resume") }

    static func delete(_ uuid: String) async -> ApiResult<Ignored> { await Api.call("DELETE", base + "consultations/offers/\(uuid)") }

    /// `tab` = upcoming · past · cancelled
    static func list(tab: String? = nil, page: Int = 1, perPage: Int = 15) async -> ApiResult<[ConsultationDto]> {
        await Api.call("GET", base + "consultations", query: ["tab": tab, "page": "\(page)", "per_page": "\(perPage)"])
    }

    static func show(_ uuid: String) async -> ApiResult<ConsultationDto> { await Api.call("GET", base + "consultations/\(uuid)") }

    /// A pending request: take the time (the others for those hours go back).
    static func accept(_ uuid: String) async -> ApiResult<ConsultationDto> { await Api.call("POST", base + "consultations/\(uuid)/accept") }

    /// A pending request: say no; the client gets everything back.
    static func decline(_ uuid: String, _ body: ConsultationCancelRequest) async -> ApiResult<ConsultationDto> {
        await Api.call("POST", base + "consultations/\(uuid)/decline", body: body.json)
    }

    static func cancel(_ uuid: String, _ body: ConsultationCancelRequest) async -> ApiResult<ConsultationDto> {
        await Api.call("POST", base + "consultations/\(uuid)/cancel", body: body.json)
    }
}

struct ConsultationInfoDto: Decodable {
    var featureOn: Bool?
    var lengths: [Int]?
    var maxOptions: Int?
    /// The most consultations listed (published or waiting) at once.
    var maxActive: Int?
    /// The staff look at a new or changed one before clients see it.
    var review: Bool?
    var currency: String?
    var decimals: Int?
    var price: ConsultationPriceLimitsDto?
    var suggestedWeekly: [ConsultationHoursDto]?
    var rules: ConsultationRulesDto?
    var stats: ConsultationStatsDto?
}

/// One consultation I list. `status`: draft · pending_review · published · paused · rejected · suspended.
struct ConsultationOfferDto: Decodable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var title: String?
    var about: String?
    var status: String?
    var statusReason: String?
    var options: [ConsultationOptionDto]?
    var weekly: [ConsultationHoursDto]?
    var timezone: String?
    var currency: String?
    var decimals: Int?
    /// Open to clients right now.
    var live: Bool?
    var bookings: ConsultationOfferBookingsDto?
    var can: ConsultationOfferCanDto?
}

struct ConsultationOfferBookingsDto: Decodable {
    var pending: Int?
    var upcoming: Int?
    var completed: Int?
}

struct ConsultationOfferCanDto: Decodable {
    var edit: Bool?
    var submit: Bool?
    var pause: Bool?
    var resume: Bool?
    var delete: Bool?
}

struct ConsultationOptionDto: Decodable {
    var minutes: Int?
    var priceMinor: Int?
    var commissionMinor: Int?
    var netMinor: Int?
}

/// `day` 0 = Sunday … 6 = Saturday; `from` / `to` as HH:mm in my time zone.
struct ConsultationHoursDto: Decodable {
    var day: Int?
    var from: String?
    var to: String?

    var json: [String: Any] { ["day": day ?? 0, "from": from ?? "", "to": to ?? ""] }
}

struct ConsultationPriceLimitsDto: Decodable {
    var minMinor: Int?
    var maxMinor: Int?
}

struct ConsultationRulesDto: Decodable {
    var freeCancelHours: Int?
    var complaintHours: Int?
    var responseHours: Int?
    var minNoticeHours: Int?
    var maxDaysAhead: Int?
}

struct ConsultationStatsDto: Decodable {
    /// Requests waiting for my answer.
    var pending: Int?
    var upcoming: Int?
    var completed: Int?
    var earnedMinor: Int?
}

struct ConsultationOfferRequest {
    let title: String
    let about: String?
    let options: [ConsultationOptionRequest]
    let weekly: [ConsultationHoursDto]
    let timezone: String

    /// Gson leaves a null field out of the body.
    var json: JsonBody {
        var body: JsonBody = ["title": title, "options": options.map(\.json), "weekly": weekly.map(\.json), "timezone": timezone]
        if let about { body["about"] = about }
        return body
    }
}

struct ConsultationOptionRequest {
    let minutes: Int
    let priceMinor: Int

    var json: [String: Any] { ["minutes": minutes, "price_minor": priceMinor] }
}

struct ConsultationCancelRequest {
    var reason: String?

    var json: JsonBody {
        var body: JsonBody = [:]
        if let reason { body["reason"] = reason }
        return body
    }
}

struct ConsultationDto: Decodable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    /// pending · booked · declined · completed · cancelled · disputed · refunded
    var status: String?
    /// upcoming · live · ended while booked, else the status
    var phase: String?
    var title: String?
    var minutes: Int?
    var startsAt: String?
    var endsAt: String?
    var offer: String?
    var topic: String?
    var currency: String?
    var decimals: Int?
    var money: ConsultationMoneyDto?
    var client: ConsultationPersonDto?
    var rules: ConsultationBookingRulesDto?
    var cancel: ConsultationCancelDto?
    var complaint: ConsultationComplaintDto?
    var resolution: ConsultationResolutionDto?
    var settledAt: String?
    var respondBy: String?
    /// The client's stars once it is over (they show on my profile at once).
    var review: ConsultationReviewDto?
    var can: ConsultationCanDto?
    var createdAt: String?
}

struct ConsultationReviewDto: Decodable {
    var canRate: Bool?
    var rateUntil: String?
    var rating: ConsultationRatingDto?
}

struct ConsultationRatingDto: Decodable {
    var stars: Double?
    var comment: String?
    var removed: Bool?
    var at: String?
}

struct ConsultationMoneyDto: Decodable {
    var priceMinor: Int?
    var commissionMinor: Int?
    var netMinor: Int?
}

struct ConsultationPersonDto: Decodable {
    var name: String?
}

struct ConsultationBookingRulesDto: Decodable {
    var freeCancelUntil: String?
    var releaseAt: String?
    var complaintHours: Int?
    var responseHours: Int?
}

struct ConsultationCancelDto: Decodable {
    var by: String?
    var reason: String?
    var late: Bool?
    var at: String?
}

struct ConsultationComplaintDto: Decodable {
    var text: String?
    var at: String?
}

struct ConsultationResolutionDto: Decodable {
    var decision: String?
    var note: String?
    var at: String?
}

struct ConsultationCanDto: Decodable {
    var cancel: Bool?
    var accept: Bool?
    var decline: Bool?
}
