import Foundation

// freelanceApp `net/RightsApi.kt`.

/// FL-L13, ownership and licences, under `/api/freelance/v1`: what the offer and service forms need (the three ways to hand over the work
/// with the standard text of my country), the finished works whose client let me show them in my portfolio, and asking that leave.
/// The terms themselves ride on the offer, the service, the agreement and each delivery (`rights`, null where the feature is off).
enum RightsApi {
    private static let base = Api.freelance

    static func options() async -> ApiResult<RightsOptionsDto> { await Api.call("GET", base + "rights") }

    /// My finished works a client allowed me to show.
    static func portfolioSources() async -> ApiResult<[PortfolioSourceDto]> { await Api.call("GET", base + "rights/portfolio-sources") }

    /// A notice to the client asking their leave (not again for a few days); answers with the agreement.
    static func askPortfolio(_ uuid: String) async -> ApiResult<AgreementDto> {
        await Api.call("POST", base + "projects/\(uuid)/agreement/rights/portfolio-request")
    }
}

/// What I say on an offer or a service (and the platform's default in `RightsOptionsDto.default`).
struct RightsInputDto: Decodable, Equatable {
    /// transfer · license · retained
    var model: String?
    var commercial: Bool?
    var exclusive: Bool?
    /// Null: no time limit.
    var termMonths: Int?
    /// worldwide · country
    var territory: String?
    /// My wish to show the finished work in my portfolio (the client still decides).
    var portfolio: Bool?

    init(model: String? = nil, commercial: Bool? = nil, exclusive: Bool? = nil, termMonths: Int? = nil, territory: String? = nil, portfolio: Bool? = nil) {
        self.model = model
        self.commercial = commercial
        self.exclusive = exclusive
        self.termMonths = termMonths
        self.territory = territory
        self.portfolio = portfolio
    }

    /// The body Gson writes (Kotlin's defaults for a missing field; `term_months` left out when there is no limit).
    var json: JsonBody {
        var r: JsonBody = [
            "model": model ?? "transfer", "commercial": commercial ?? true, "exclusive": exclusive ?? true,
            "territory": territory ?? "worldwide", "portfolio": portfolio ?? false,
        ]
        if let termMonths { r["term_months"] = termMonths }
        return r
    }
}

struct RightsOptionsDto: Decodable, Equatable {
    var enabled: Bool?
    var `default`: RightsInputDto?
    var models: [RightsModelDto]?
    var limits: RightsLimitsDto?
}

struct RightsModelDto: Decodable, Equatable {
    var model: String?
    var label: String?
    var summary: String?
    /// The standard text of my country for this model, in the language of the request.
    var terms: RightsTextDto?
}

struct RightsTextDto: Decodable, Equatable {
    var title: String?
    var body: String?
    var version: Int?
}

struct RightsLimitsDto: Decodable, Equatable {
    var maxTermMonths: Int?
    var maxMaterials: Int?
}

struct RightsDetailsDto: Decodable, Equatable {
    var commercial: Bool?
    var exclusive: Bool?
    var termMonths: Int?
    var territory: String?
}

/// The terms of an offer or a service as its screen shows them (already in the language of the request).
struct RightsSummaryDto: Decodable, Equatable {
    var model: String?
    var modelLabel: String?
    var summary: String?
    var clauses: [String]?
    var details: RightsDetailsDto?
    var portfolio: Bool?
}

/// The terms an agreement keeps, with where the passing of the rights stands.
struct AgreementRightsDto: Decodable, Equatable {
    var model: String?
    var modelLabel: String?
    var summary: String?
    var clauses: [String]?
    var details: RightsDetailsDto?
    /// The standard text of the day the agreement was made.
    var terms: RightsTextDto?
    var portfolio: RightsPortfolioDto?
    var parts: RightsPartsDto?
    /// pending · partial · granted
    var state: String?
    var can: RightsCanDto?
}

struct RightsPortfolioDto: Decodable, Equatable {
    var wanted: Bool?
    /// The client's leave to show the finished work.
    var allowed: Bool?
    var askedAt: String?
}

struct RightsPartsDto: Decodable, Equatable {
    var total: Int?
    var granted: Int?
}

struct RightsCanDto: Decodable, Equatable {
    var portfolioToggle: Bool?
    var portfolioRequest: Bool?
}

/// What a delivery keeps: my word that the work is mine to hand over, and the outside material I listed.
struct DeliveryRightsDto: Decodable, Equatable {
    var confirmedAt: String?
    var materials: [RightsMaterialDto]?
}

struct RightsMaterialDto: Decodable, Equatable {
    var name: String?
    var source: String?
    var license: String?
}

/// A finished work I may show; `used` once a portfolio item already comes from it.
struct PortfolioSourceDto: Decodable, Equatable, Identifiable {
    var uuid: String?
    var title: String?
    var completedAt: String?
    var used: Bool?

    var id: String { uuid ?? "" }
}
