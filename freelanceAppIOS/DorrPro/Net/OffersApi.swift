import Foundation

// freelanceApp `net/OffersApi.kt`.

/// Offers (FL-301), under `/api/freelance/v1`: whether I may offer on a project (and if not, why), my monthly quota and what a price
/// leaves me after the commission, sending the offer with its files, my offer on a project, and all my offers.
enum OffersApi {
    private static let base = Api.freelance

    static func preview(_ uuid: String, priceMinor: Int? = nil) async -> ApiResult<OfferPreviewDto> {
        await Api.call("GET", base + "projects/\(uuid)/offer-preview", query: ["price_minor": priceMinor.map(String.init)])
    }

    /// FL-304: what to fix (error) or think about (warning) before sending.
    static func check(_ uuid: String, _ body: OfferCheckRequest) async -> ApiResult<OfferChecksDto> {
        await Api.call("POST", base + "projects/\(uuid)/offer-check", body: body.json)
    }

    static func templates() async -> ApiResult<[OfferTemplateDto]> { await Api.call("GET", base + "offer-templates") }

    static func saveTemplate(_ body: OfferTemplateRequest) async -> ApiResult<OfferTemplateDto> {
        await Api.call("POST", base + "offer-templates", body: ["title": body.title, "details": body.details])
    }

    static func deleteTemplate(_ id: Int) async -> ApiResult<Ignored> { await Api.call("DELETE", base + "offer-templates/\(id)") }

    static func mine(_ uuid: String) async -> ApiResult<MyOfferDto> { await Api.call("GET", base + "projects/\(uuid)/my-offer") }

    /// Multipart: `price_minor`, `duration_days`, `details` (and `answers[i]`) as [fields]; up to 5 `files[]`.
    static func send(_ uuid: String, fields: [String: String], files: [FilePart]) async -> ApiResult<OfferDto> {
        await Api.upload(base + "projects/\(uuid)/offers", fields: fields, files: files)
    }

    /// Changes an offer still open: only what is sent changes.
    static func update(_ uuid: String, _ body: OfferUpdateRequest) async -> ApiResult<OfferDto> {
        await Api.call("PUT", base + "offers/\(uuid)", body: ["price_minor": body.priceMinor, "duration_days": body.durationDays, "details": body.details])
    }

    static func withdraw(_ uuid: String) async -> ApiResult<OfferDto> { await Api.call("POST", base + "offers/\(uuid)/withdraw") }

    /// What the offer said before each change, oldest first.
    static func revisions(_ uuid: String) async -> ApiResult<[OfferRevisionDto]> { await Api.call("GET", base + "offers/\(uuid)/revisions") }

    static func list(page: Int = 1, perPage: Int = 15) async -> ApiResult<[OfferDto]> {
        await Api.call("GET", base + "offers", query: ["page": String(page), "per_page": String(perPage)])
    }
}

struct OfferPreviewDto: Decodable {
    /// Nil when I may offer; else the reason (`code` + a message to show).
    var blocked: OfferBlockedDto?
    var quota: OfferQuotaDto?
    var minPriceMinor: Int?
    var breakdown: OfferBreakdownDto?
}

struct OfferBlockedDto: Decodable {
    var code: String?
    var message: String?
}

struct OfferQuotaDto: Decodable {
    var enabled: Bool?
    var limit: Int?
    var used: Int?
    var left: Int?
    /// Bought (extra) offers I still have; they are spent after the free ones.
    var credits: Int?
    /// Whether I can buy more (the switch is on and my plan has a limit).
    var packs: Bool?
}

struct OfferBreakdownDto: Decodable {
    var priceMinor: Int?
    var commissionMinor: Int?
    var netMinor: Int?
    var currency: String?
}

struct OfferCheckRequest {
    let priceMinor: Int?
    let durationDays: Int?
    let details: String
    let files: Int

    var json: JsonBody { ["price_minor": priceMinor, "duration_days": durationDays, "details": details, "files": files] }
}

struct OfferChecksDto: Decodable {
    var checks: [OfferCheckDto]?
}

/// level: `error` (the server will refuse it) or `warning` (advice, the default).
struct OfferCheckDto: Decodable {
    var code: String?
    var level: String?
    var message: String?
}

struct OfferTemplateDto: Decodable, Identifiable {
    var id: Int?
    var title: String?
    var details: String?
}

struct OfferTemplateRequest {
    let title: String
    let details: String
}

struct MyOfferDto: Decodable {
    var offer: OfferDto?
}

struct OfferDto: Decodable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    /// Default `submitted`.
    var status: String?
    var priceMinor: Int?
    var currency: String?
    var durationDays: Int?
    var details: String?
    var answers: [OfferAnswerDto]?
    var commissionMinor: Int?
    var netMinor: Int?
    var attachments: [OfferFileDto]?
    var project: OfferProjectDto?
    var can: OfferCanDto?
    var revisions: Int?
    /// Why the client turned it down, when they said.
    var statusReason: String?
    var timeline: [OfferEventDto]?
    /// The client's room about this offer (FL-308): nil until they open it.
    var chat: OfferChatDto?
    /// FL-401: the agreement the client sent on it.
    var agreement: OfferAgreementDto?
    var createdAt: String?
}

struct OfferAnswerDto: Decodable {
    var question: String?
    var answer: String?
}

struct OfferEventDto: Decodable {
    var type: String?
    var to: String?
    var at: String?
}

struct OfferCanDto: Decodable {
    var edit: Bool?
    var withdraw: Bool?
}

struct OfferUpdateRequest {
    let priceMinor: Int
    let durationDays: Int
    let details: String
}

struct OfferRevisionDto: Decodable {
    var number: Int?
    var priceMinor: Int?
    var durationDays: Int?
    var details: String?
    var netMinor: Int?
    var at: String?
}

struct OfferFileDto: Decodable, Identifiable {
    var id: Int?
    var name: String?
    var path: String?
}

struct OfferProjectDto: Decodable {
    var uuid: String?
    var title: String?
}
