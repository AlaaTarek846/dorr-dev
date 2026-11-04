import Foundation

// freelanceApp `net/LoyaltyApi.kt`.

/// FL-L09 (part B), my loyalty program under `/api/freelance/v1/loyalty`: the steps of discount a client earns by coming back (after this many
/// works finished together → this much off), within the platform's limits; on / off; my clients on a step and what the discounts came to.
/// The discount is taken by itself on a project offer, a service order and a consultation, and it is mine to give: it comes off my price.
enum LoyaltyApi {
    private static let base = Api.freelance

    static func show() async -> ApiResult<LoyaltyDashDto> { await Api.call("GET", base + "loyalty") }

    /// Always the whole ladder.
    static func save(_ body: LoyaltyRequest) async -> ApiResult<LoyaltyDashDto> { await Api.call("PUT", base + "loyalty", body: body.json) }

    /// `enable` · `disable` — without touching the steps.
    static func toggle(_ action: String) async -> ApiResult<LoyaltyDashDto> { await Api.call("POST", base + "loyalty/\(action)") }
}

struct LoyaltyDashDto: Decodable, Equatable {
    var available: Bool?
    @CurrencyLabel var currency: String?
    var decimals: Int?
    /// Null until I set one up.
    var program: LoyaltyProgramDto?
    var rules: LoyaltyRulesDto?
    var presets: [LoyaltyPresetDto]?
    var stats: LoyaltyStatsDto?
    var clients: [LoyaltyClientDto]?
}

struct LoyaltyProgramDto: Decodable, Equatable {
    var enabled: Bool?
    var tiers: [LoyaltyTierDto]?
}

/// One step: after `after` works finished together the client gets `discountBp` off (basis points: 1000 = 10%). `clients` is how many stand on it.
struct LoyaltyTierDto: Decodable, Equatable {
    var after: Int?
    var discountBp: Int?
    var clients: Int?

    init(after: Int?, discountBp: Int?, clients: Int? = nil) {
        self.after = after
        self.discountBp = discountBp
        self.clients = clients
    }
}

struct LoyaltyRulesDto: Decodable, Equatable {
    var minDiscountBp: Int?
    var maxDiscountBp: Int?
    var maxTiers: Int?
    var maxAfter: Int?
}

struct LoyaltyPresetDto: Decodable, Equatable {
    var key: String?
    var name: String?
    var tiers: [LoyaltyTierDto]?
}

struct LoyaltyStatsDto: Decodable, Equatable {
    var pastClients: Int?
    var inProgram: Int?
    var uses: Int?
    var savedMinor: Int?
    var byTier: [LoyaltyTierDto]?
}

struct LoyaltyClientDto: Decodable, Equatable {
    var name: String?
    var avatarUrl: String?
    var works: Int?
    var current: LoyaltyTierDto?
    var next: LoyaltyNextDto?
}

struct LoyaltyNextDto: Decodable, Equatable {
    var after: Int?
    var discountBp: Int?
    var remaining: Int?
}

struct LoyaltyRequest {
    let enabled: Bool
    let tiers: [LoyaltyStepRequest]

    var json: JsonBody { ["enabled": enabled, "tiers": tiers.map { ["after": $0.after, "discount_bp": $0.discountBp] }] }
}

struct LoyaltyStepRequest {
    let after: Int
    let discountBp: Int
}
