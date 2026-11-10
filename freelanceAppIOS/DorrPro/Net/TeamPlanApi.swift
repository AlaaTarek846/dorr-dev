import Foundation

// freelanceApp `net/TeamPlanApi.kt`.

/// A team's plan (FS-211), under `/api/freelance/v1/teams/{team}/subscription`: the plans on sale for the team with what each opens, the team's
/// subscription with its invoices, and what the team holds against its room. Owners only: an Owner pays out of their own wallet. Every action
/// answers with the same body as `show`.
enum TeamPlanApi {
    private static let base = Api.freelance

    static func show(_ team: String) async -> ApiResult<TeamPlanPageDto> { await Api.call("GET", base + "teams/\(team)/subscription") }

    /// Takes a plan; when one is live this changes it (a better plan starts now, a lower one at the end of the period).
    static func take(_ team: String, _ body: TakePlanRequest) async -> ApiResult<TeamPlanPageDto> {
        await Api.call("POST", base + "teams/\(team)/subscription", body: ["plan": body.plan, "period": body.period])
    }

    /// Renewal off - any Owner.
    static func cancel(_ team: String) async -> ApiResult<TeamPlanPageDto> { await Api.call("POST", base + "teams/\(team)/subscription/cancel") }

    /// Renewal back on - the Owner who pays.
    static func resume(_ team: String) async -> ApiResult<TeamPlanPageDto> { await Api.call("POST", base + "teams/\(team)/subscription/resume") }

    /// A failed renewal tried again now - the Owner who pays.
    static func renew(_ team: String) async -> ApiResult<TeamPlanPageDto> { await Api.call("POST", base + "teams/\(team)/subscription/renew") }
}

struct TeamPlanPageDto: Decodable {
    var catalog: TeamPlanCatalogDto?
    /// The live one, else the last; nil when the team never had one.
    var subscription: TeamSubscriptionDto?
    var invoices: [SubscriptionInvoiceDto]?
    var usage: TeamUsageDto?
}

/// The first plan is always the free baseline (what the team has with no plan); the others are only the plans that open more room here.
struct TeamPlanCatalogDto: Decodable {
    @CurrencyLabel var currency: String?
    var currencySymbol: String?
    /// Decimal places of `currency`: prices are whole minor units of it.
    var decimals: Int?
    var plans: [TeamPlanDto]?
}

struct TeamPlanDto: Decodable, Identifiable, Equatable {
    var id: String { slug ?? "" }
    var slug: String?
    var name: String?
    var description: String?
    var rank: Int?
    var free: Bool?
    /// The plan the team is on now (the free baseline when it has none).
    var current: Bool?
    var prices: PlanPricesDto?
    var yearlySavingMinor: Int?
    var benefits: TeamPlanBenefitsDto?
    /// Days of free trial the team would get (0 when it used its trial, or the plan has none).
    var trialDays: Int?
}

/// What a plan opens for the whole team; nil = no cap.
struct TeamPlanBenefitsDto: Decodable, Equatable {
    var membersMax: Int?
    var projectsMax: Int?
    var offersMonthly: Int?
}

struct TeamSubscriptionDto: Decodable {
    var uuid: String?
    var plan: PlanRefDto?
    /// `monthly` or `yearly`.
    var period: String?
    /// `trialing`, `active`, `past_due` (a renewal failed; the plan stays through the grace days) or `expired`.
    var status: String?
    /// Whether it gives its room now.
    var active: Bool?
    var autoRenew: Bool?
    var priceMinor: Int?
    @CurrencyLabel var currency: String?
    var periodEnd: String?
    var trialEndsAt: String?
    var graceUntil: String?
    /// A lower plan (or a shorter period) that starts when this one ends.
    var pending: PendingPlanDto?
    /// The Owner whose wallet pays; `paidByMe` says whether the buttons that spend (change, resume, pay now) are mine.
    var paidBy: TeamPayerDto?
    var paidByMe: Bool?
}

struct TeamPayerDto: Decodable {
    var name: String?
}

/// What the team holds against its room right now.
struct TeamUsageDto: Decodable {
    var plan: TeamUsagePlanDto?
    var members: TeamMeterDto?
    var projects: TeamMeterDto?
    var offers: TeamOffersMeterDto?
}

struct TeamUsagePlanDto: Decodable {
    var slug: String?
    var name: String?
    var free: Bool?
}

/// `used` of `max`; a nil `max` is no cap.
struct TeamMeterDto: Decodable {
    var used: Int?
    var max: Int?
}

struct TeamOffersMeterDto: Decodable {
    var used: Int?
    var limit: Int?
    var left: Int?
}
