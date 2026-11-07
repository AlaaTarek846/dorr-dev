import Foundation

// freelanceApp `net/OffersApi.kt`.

/// Offers (FL-301), under `/api/freelance/v1`: whether I may offer on a project (and if not, why), my monthly quota and what a price
/// leaves me after the commission, sending the offer with its files, my offer on a project, and all my offers.
enum OffersApi {
    private static let base = Api.freelance

    /// `team`: FS-209b - a team I am Owner or PM of (the team's quota and checks decide then, not mine); `signer`: the Owner who signs,
    /// when a PM sends for the team.
    static func preview(_ uuid: String, priceMinor: Int? = nil, team: String? = nil, signer: String? = nil) async -> ApiResult<OfferPreviewDto> {
        await Api.call("GET", base + "projects/\(uuid)/offer-preview", query: ["price_minor": priceMinor.map(String.init), "team": team, "signer": signer])
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
        await Api.call("PUT", base + "offers/\(uuid)", body: {
            var json: JsonBody = ["price_minor": body.priceMinor, "duration_days": body.durationDays, "details": body.details]
            if let r = body.rights {
                // Gson leaves out the empty ones (no time limit: no `term_months`).
                let fields: [String: Any?] = ["model": r.model, "commercial": r.commercial, "exclusive": r.exclusive, "term_months": r.termMonths,
                                              "territory": r.territory, "portfolio": r.portfolio]
                json["rights"] = fields.compactMapValues { $0 }
            }
            // FS-209b: a team's offer changes who works on it (the whole list each time); nil leaves it as it is.
            if let staff = body.staff { json["staff"] = staff.map { $0.json } }
            return json
        }())
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
    /// FL-L09: set when this project's client earned a step of my loyalty program (it comes off the price I ask).
    var loyalty: OfferLoyaltyDto?
    /// FS-209b: the team's offers this month (asked with `team`; `limit` nil = no cap).
    var teamQuota: TeamOfferQuotaDto?
}

struct TeamOfferQuotaDto: Decodable {
    var limit: Int?
    var used: Int?
    var left: Int?
}

struct OfferLoyaltyDto: Decodable {
    var bp: Int?
    var after: Int?
    var works: Int?
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
    @CurrencyLabel var currency: String?
    /// FL-L09: the price I asked and the loyalty discount taken off it (`priceMinor` is what the client pays).
    var listPriceMinor: Int?
    var loyaltyBp: Int?
    var savingMinor: Int?
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
    /// FL-L09: the price I asked and the loyalty discount taken off it (nil: none).
    var listPriceMinor: Int?
    var loyaltyBp: Int?
    var savingMinor: Int?
    @CurrencyLabel var currency: String?
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
    /// FL-L13: who owns the work once it is paid and what the client may do with it (nil where the feature is off).
    var rights: RightsSummaryDto?
    var createdAt: String?
    /// FS-209b: set when it is in a team's name - the team and the people on it.
    var team: OfferTeamDto?
    /// FS-209b: the PM who sent it, when it was not the Owner who signs.
    var submittedBy: TeamPersonRefDto?
}

/// FS-209b: a team's offer - who works on it (`freelancer` is the person's uuid) and what each takes on; `role` = pm | executor.
struct OfferTeamDto: Decodable, Equatable {
    var uuid: String?
    var name: String?
    var members: [OfferTeamMemberDto]?
}

struct OfferTeamMemberDto: Decodable, Equatable {
    var name: String?
    /// Default `executor`.
    var role: String?
    var responsibility: String?
    var freelancer: String?
}

/// FS-209b: one person put on a team's offer.
struct OfferStaffInput {
    let freelancer: String
    let role: String
    var responsibility: String? = nil

    /// As Gson writes it: no `responsibility` when there is none (and no optional inside, so it goes through JSONSerialization).
    var json: [String: Any] {
        var out: [String: Any] = ["freelancer": freelancer, "role": role]
        if let responsibility { out["responsibility"] = responsibility }
        return out
    }
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
    /// FL-L13: sent only when the feature is on.
    var rights: RightsInputDto? = nil
    /// FS-209b: a team's offer changes who works on it (the whole list each time); nil leaves it as it is.
    var staff: [OfferStaffInput]? = nil
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
