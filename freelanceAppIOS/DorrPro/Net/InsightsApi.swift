import Foundation

// freelanceApp `net/InsightsApi.kt`.

/// My performance (FL-L01, phase 4), under `/api/freelance/v1`: my offers, the orders on my ready-made services, what I earned and my name
/// with clients over the last days. How much shows is my plan's: a plan with none answers 200 with `InsightsDto.locked` and the plan that
/// opens it, so the screen can offer the upgrade instead of showing an error.
enum InsightsApi {
    /// `days` is asked for; the answer says the days it was worked out for (the plan's nearest window).
    static func insights(_ days: Int? = nil) async -> ApiResult<InsightsDto> {
        await Api.call("GET", Api.freelance + "insights", query: ["days": days.map(String.init)])
    }
}

struct InsightsDto: Decodable {
    /// 0 = none (locked), 1 = a summary of 30 days, 2 = the full numbers.
    var level: Int?
    var locked: Bool?
    /// False when plans are not sold in my country, so there is nothing to upgrade to.
    var subscriptionsEnabled: Bool?
    /// The windows my plan can look back over (30 / 90 / 365).
    var allowedDays: [Int]?
    /// The days the numbers are for.
    var days: Int?
    @CurrencyLabel var currency: String?
    var decimals: Int?
    /// The lowest plan that opens more than mine; nil at the top or where plans are not sold.
    var upgrade: InsightsUpgradeDto?
    var data: InsightsDataDto?
}

struct InsightsUpgradeDto: Decodable {
    var slug: String?
    var name: String?
}

struct InsightsDataDto: Decodable {
    var offers: InsightOffersDto?
    var services: InsightServicesDto?
    var earnings: InsightEarningsDto?
    var reputation: InsightReputationDto?
    /// How often my profile and ready-made services were looked at (FL-L11).
    var views: InsightViewsDto?
    /// FS-103: how many client lists I am in (absent in older answers). Never says whose.
    var saved: InsightSavedDto?
    /// The full level only.
    var specialties: [InsightSpecialtyDto]?
    /// FS-704, the full level only: how clients look at my works.
    var portfolio: InsightPortfolioDto?
}

/// FS-704: my portfolio in numbers — how many works there are, how many are shown and verified, the visits to the portfolio and the works opened
/// against the period before, and the works opened most (`top`). Counted per day, never per person.
struct InsightPortfolioDto: Decodable {
    var works: Int?
    var shown: Int?
    var verified: Int?
    var visits: Int?
    var opened: Int?
    var openedBefore: Int?
    /// Whole percent against the period before; nil when there was nothing before.
    var openedChangePercent: Int?
    var top: [InsightTopWorkDto]?
}

/// `visibility` is `public`, `clients_only` or `private`.
struct InsightTopWorkDto: Decodable {
    var uuid: String?
    var title: String?
    var verified: Bool?
    var visibility: String?
    var views: Int?
}

/// `lists` = the lists I am in in total; `new` = how many of them were added in the period.
struct InsightSavedDto: Decodable {
    var lists: Int?
    var new: Int?
}

/// Visits to my profile (seen from an offer, or from my portfolio) and to my ready-made services, in the period and in the period of the same
/// length before it — only numbers, never who looked. `series` and `topServices` come with the full level.
struct InsightViewsDto: Decodable {
    var profile: Int?
    var service: Int?
    var profileBefore: Int?
    var serviceBefore: Int?
    /// Where the visits came from: `offer`, `portfolio`, `market`.
    var bySource: [String: Int]?
    /// `week` or `month`.
    var bucket: String?
    var series: [InsightViewPointDto]?
    var topServices: [InsightTopServiceDto]?
}

struct InsightViewPointDto: Decodable {
    var from: String?
    var profile: Int?
    var service: Int?
}

struct InsightTopServiceDto: Decodable {
    var id: Int?
    var title: String?
    var views: Int?
    var orders: Int?
}

/// The funnel of the offers I sent in the period; the rates are whole percents of the offers I did not take back (nil when there are none).
struct InsightOffersDto: Decodable {
    var sent: Int?
    var withdrawn: Int?
    var viewed: Int?
    var shortlisted: Int?
    var accepted: Int?
    var rejected: Int?
    var viewRate: Int?
    var acceptRate: Int?
}

struct InsightServicesDto: Decodable {
    var received: Int?
    var awaiting: Int?
    var active: Int?
    var completed: Int?
    var declined: Int?
    var expired: Int?
    var cancelled: Int?
    /// Of the orders I answered, the percent I accepted.
    var acceptRate: Int?
}

struct InsightEarningsDto: Decodable {
    var earnedMinor: Int?
    /// `week` (30 days) or `month`; the full level only, with `series`.
    var bucket: String?
    var series: [InsightPointDto]?
    /// FS-704, the full level only: against the period before, by source, specialty and client.
    var analysis: InsightEarningsAnalysisDto?
}

/// What the earnings of the period are made of. `changePercent` is against the stretch of the same length before (nil = nothing before to
/// compare with, shown as «new»); `sources` are amounts in minor units; a `share` is a whole percent of the period's earnings.
struct InsightEarningsAnalysisDto: Decodable {
    var beforeMinor: Int?
    var changePercent: Int?
    var sources: InsightSourcesDto?
    var bySpecialty: [InsightEarnedSpecialtyDto]?
    var topClients: [InsightTopClientDto]?
    var projectsPaid: Int?
    var averageProjectMinor: Int?
}

struct InsightSourcesDto: Decodable {
    var projects: Int?
    var services: Int?
    var consultations: Int?
}

struct InsightEarnedSpecialtyDto: Decodable {
    var id: Int?
    var name: String?
    var earnedMinor: Int?
    var share: Int?
}

struct InsightTopClientDto: Decodable {
    var name: String?
    var earnedMinor: Int?
    var share: Int?
}

/// One bar: `from` is the first day of its week or month (`2026-10-01`).
struct InsightPointDto: Decodable {
    var from: String?
    var earnedMinor: Int?
}

struct InsightReputationDto: Decodable {
    /// The average, from three ratings; nil before that.
    var rating: Double?
    var reviewsCount: Int?
    var completedProjects: Int?
    var completionRate: Int?
    var onTimeRate: Int?
    var completedInPeriod: Int?
}

struct InsightSpecialtyDto: Decodable {
    var id: Int?
    var name: String?
    var sent: Int?
    var accepted: Int?
    var acceptRate: Int?
}

/// «Improve my profile» (FL-L11): the same for every plan, under `/api/freelance/v1`.
enum ProfileCoachApi {
    static func coach() async -> ApiResult<CoachDto> { await Api.call("GET", Api.freelance + "profile-coach") }
}

/// `level` is `start`, `good` or `great`; `done` of `total` steps of the bar are done.
struct CoachDto: Decodable {
    var percent: Int?
    var level: String?
    var done: Int?
    var total: Int?
    var tips: [CoachTipDto]?
}

/// One thing to do next. `key` says what (the app writes the sentence), `impact` is `high` / `medium` / `low`, `points` what the bar gains
/// (0 for the ones it does not count) and `action` the screen to open: `profile`, `skills`, `specialties`, `portfolio`, `verification`, `services`.
struct CoachTipDto: Decodable {
    var key: String?
    var impact: String?
    var points: Int?
    var action: String?
}
