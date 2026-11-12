import Foundation

// freelanceApp `net/SubscriptionApi.kt`.

/// My plan (FL-L01), under `/api/freelance/v1`: the plans with their prices in my country, my subscription with its invoices and the free
/// offers I have left, and taking / changing / cancelling / resuming / renewing it. Every action answers with the same body as `show`.
enum SubscriptionApi {
    private static let base = Api.freelance

    static func plans() async -> ApiResult<PlansDto> { await Api.call("GET", base + "plans") }

    static func show() async -> ApiResult<MySubscriptionDto> { await Api.call("GET", base + "subscription") }

    static func take(_ body: TakePlanRequest) async -> ApiResult<MySubscriptionDto> {
        await Api.call("POST", base + "subscription", body: ["plan": body.plan, "period": body.period])
    }

    static func cancel() async -> ApiResult<MySubscriptionDto> { await Api.call("POST", base + "subscription/cancel") }

    static func resume() async -> ApiResult<MySubscriptionDto> { await Api.call("POST", base + "subscription/resume") }

    static func renew() async -> ApiResult<MySubscriptionDto> { await Api.call("POST", base + "subscription/renew") }

    /// Extra offers for sale (phase 2): the packs here, what I have left, my last purchases.
    static func offerPacks() async -> ApiResult<OfferPacksDto> { await Api.call("GET", base + "offer-packs") }

    /// `BuyPackRequest.requestId` makes a double tap one purchase.
    static func buyPack(_ pack: String, _ body: BuyPackRequest) async -> ApiResult<OfferPacksDto> {
        await Api.call("POST", base + "offer-packs/\(pack)/buy", body: ["request_id": body.requestId])
    }
}

/// What the server sells here: `enabled` is false when the service has no plans in my country (the page then says so).
struct PlansDto: Decodable {
    var enabled: Bool?
    @CurrencyLabel var currency: String?
    var currencySymbol: String?
    /// Decimal places of `currency`: prices are whole minor units of it.
    var decimals: Int?
    /// FS-705c: the rows of the comparison table (only what works today); each plan's `perks` has a value per row.
    var features: [PlanFeatureDto]?
    var plans: [PlanDto]?
}

/// One row of the comparison: `type` is `switch`, `level` (0...max), `limit` (nil = no cap) or `fee_bp`; `name` and `hint` come in my language.
struct PlanFeatureDto: Decodable, Equatable {
    var key: String?
    var type: String?
    var group: String?
    var max: Int?
    var name: String?
    var hint: String?
}

/// What a plan gives for one comparison row: on/off (switch), a number (level, limit, fee), or nothing (limit: no cap; fee: the country's own).
enum PlanPerk: Decodable, Equatable {
    case on(Bool)
    case number(Int)
    case none

    init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if c.decodeNil() { self = .none }
        else if let b = try? c.decode(Bool.self) { self = .on(b) }
        else if let n = try? c.decode(Int.self) { self = .number(n) }
        else { self = .none }
    }
}

struct PlanDto: Decodable, Identifiable, Equatable {
    var id: String { slug ?? "" }
    var slug: String?
    var name: String?
    var description: String?
    var rank: Int?
    var free: Bool?
    /// The plan I am on now (Free when I have none).
    var current: Bool?
    var prices: PlanPricesDto?
    var yearlySavingMinor: Int?
    var benefits: PlanBenefitsDto?
    /// What the plan gives per `PlansDto.features` row (the keys come back camel-cased by the decoder: see `PlanComparison.perk`).
    var perks: [String: PlanPerk]?
    /// Days of free trial I would get (0 when I used mine, or the plan has none).
    var trialDays: Int?
}

/// The two prices of a paid plan; both absent for Free.
struct PlanPricesDto: Decodable, Equatable {
    var monthly: Int?
    var yearly: Int?
}

struct PlanBenefitsDto: Decodable, Equatable {
    var offersUnlimited: Bool?
    /// Free offers per month; nil when unlimited.
    var offersMonthly: Int?
    /// Hundredths of a percent (800 = 8%); nil = the country's commission.
    var commissionBp: Int?
    /// Phase 4: the plan's badge by my name, my weight (0-3) in the «Recommended» order, my performance numbers (0 none, 1 basic, 2 full) and the ready-made services I may have at once.
    var badge: Bool?
    var boost: Int?
    var analytics: Int?
    var servicesMax: Int?
    /// FS-703: my clients (0 none, 1 clients, star and notes, 2 + labels and follow-up reminders).
    var crm: Int?
}

struct MySubscriptionDto: Decodable {
    /// The live one, else the last; nil when I never had one.
    var subscription: SubscriptionDto?
    var invoices: [SubscriptionInvoiceDto]?
    var quota: OfferQuotaDto?
}

struct SubscriptionDto: Decodable {
    var uuid: String?
    var plan: PlanRefDto?
    /// `monthly` or `yearly`.
    var period: String?
    /// `trialing`, `active`, `past_due` (a renewal failed; the plan stays through the grace days) or `expired`.
    var status: String?
    /// Whether it gives its benefits now.
    var active: Bool?
    var autoRenew: Bool?
    var priceMinor: Int?
    @CurrencyLabel var currency: String?
    var currencySymbol: String?
    var periodEnd: String?
    var trialEndsAt: String?
    var graceUntil: String?
    /// A lower plan (or a shorter period) that starts when this one ends.
    var pending: PendingPlanDto?
}

struct PlanRefDto: Decodable {
    var slug: String?
    var name: String?
    var rank: Int?
}

struct PendingPlanDto: Decodable {
    var plan: PlanRefDto?
    var period: String?
}

struct SubscriptionInvoiceDto: Decodable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var plan: String?
    var period: String?
    /// `purchase`, `upgrade` or `renewal`.
    var kind: String?
    /// `paid` or `failed`.
    var status: String?
    var amountMinor: Int?
    var creditMinor: Int?
    @CurrencyLabel var currency: String?
    var currencySymbol: String?
    var createdAt: String?
}

struct TakePlanRequest {
    let plan: String
    let period: String
}

/// The extra-offer packs sold in my country (FL-L01, phase 2). `unlimited`: my plan has no limit, so there is nothing to buy.
struct OfferPacksDto: Decodable {
    var enabled: Bool?
    @CurrencyLabel var currency: String?
    var currencySymbol: String?
    var decimals: Int?
    var unlimited: Bool?
    /// Days a bought offer lasts (0 = no end).
    var validDays: Int?
    var credits: OfferCreditsDto?
    var packs: [OfferPackDto]?
    var purchases: [OfferPurchaseDto]?
    var quota: OfferQuotaDto?
}

struct OfferCreditsDto: Decodable {
    /// Bought offers I can still send.
    var available: Int?
    var nextExpiresAt: String?
    var batches: [OfferBatchDto]?
}

struct OfferBatchDto: Decodable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var quantity: Int?
    var remaining: Int?
    var expiresAt: String?
}

struct OfferPackDto: Decodable, Identifiable, Equatable {
    var id: String { slug ?? "" }
    var slug: String?
    var name: String?
    var offers: Int?
    var priceMinor: Int?
}

struct OfferPurchaseDto: Decodable {
    var uuid: String?
    var pack: String?
    var quantity: Int?
    var priceMinor: Int?
    @CurrencyLabel var currency: String?
    var currencySymbol: String?
    var createdAt: String?
}

struct BuyPackRequest {
    let requestId: String
}
