import Foundation

/// FL-L13, ownership and licences on the client's side (`mobile/v1/freelance/...`) — the twin of Android's
/// `network/FreelanceRightsApi.kt`. The terms come with the offer, the service and the agreement (the `rights` fields of those DTOs; nil
/// where the feature is off); these are the two things a client can do: allow (or take back) the showing of a finished work in the
/// freelancer's portfolio, and report a work shown on Dorr that is not the freelancer's own.
enum FreelanceRightsApi {
    private static let base = "mobile/v1/freelance"

    /// The agreement as it is after the change; the portfolio switch is the client's alone and starts off.
    static func setPortfolio(_ uuid: String, allowed: Bool) async throws -> ApiEnvelope<FreelanceAgreementDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/agreement/rights/portfolio", method: "PUT", body: ["allowed": .bool(allowed)])
    }

    /// subjectType = portfolio_item · service; a repeat by the same person is refused (409), a work that is not shown (404).
    static func claim(subjectType: String, subject: String, details: String) async throws -> ApiEnvelope<FreelanceRightsClaimDto> {
        try await ApiClient.snake("\(base)/rights/claims", method: "POST", body: ["subject_type": .string(subjectType), "subject": .string(subject), "details": .string(details)])
    }
}

struct FreelanceRightsClaimDto: Codable, Equatable {
    var uuid: String?
    var status: String?
}

/// What the client may do with the work, in the freelancer's words: commercial or personal, exclusive or not, how long, where.
struct FreelanceRightsDetailsDto: Codable, Equatable {
    var commercial: Bool?
    var exclusive: Bool?
    var termMonths: Int?
    var territory: String?
}

/// The terms an offer or a ready-made service carries (nil: the feature is off in the country). `model` = transfer · license · retained;
/// `clauses` are the lines to print, already in my language; `portfolio` is the freelancer's wish to show the finished work.
struct FreelanceRightsSummaryDto: Codable, Equatable {
    var model: String?
    var modelLabel: String?
    var summary: String?
    var clauses: [String]?
    var details: FreelanceRightsDetailsDto?
    var portfolio: Bool?
}

/// The standard text the agreement was made with (kept with it, in my language).
struct FreelanceRightsTermsDto: Codable, Equatable {
    var title: String?
    var body: String?
    var version: Int?
}

struct FreelanceRightsPortfolioDto: Codable, Equatable {
    var wanted: Bool?
    /// The client's leave to show the finished work in the portfolio (starts off).
    var allowed: Bool?
    var askedAt: String?
}

/// Parts whose rights have passed (approved and paid) out of all the live parts.
struct FreelanceRightsPartsDto: Codable, Equatable {
    var total: Int?
    var granted: Int?
}

struct FreelanceRightsCanDto: Codable, Equatable {
    var portfolioToggle: Bool?
    var portfolioRequest: Bool?
}

/// The terms inside the agreement: pending (nothing passed — I only preview and review) · partial · granted.
struct FreelanceAgreementRightsDto: Codable, Equatable {
    var model: String?
    var modelLabel: String?
    var summary: String?
    var clauses: [String]?
    var details: FreelanceRightsDetailsDto?
    var terms: FreelanceRightsTermsDto?
    var portfolio: FreelanceRightsPortfolioDto?
    var parts: FreelanceRightsPartsDto?
    var state: String?
    var can: FreelanceRightsCanDto?
}

/// One outside material the freelancer listed with a delivery (a font, a stock photo…).
struct FreelanceRightsMaterialDto: Codable, Equatable {
    var name: String?
    var source: String?
    var license: String?
}

/// The freelancer's word with a delivery that the work is theirs, and what they listed from outside.
struct FreelanceDeliveryRightsDto: Codable, Equatable {
    var confirmedAt: String?
    var materials: [FreelanceRightsMaterialDto]?
}
