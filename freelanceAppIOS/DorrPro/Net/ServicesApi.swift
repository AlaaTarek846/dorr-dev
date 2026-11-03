import Foundation

// freelanceApp `net/ServicesApi.kt`.

/// FL-L04, ready-made services under `/api/freelance/v1`: my services (levels, extras, images; sending for review, pausing, deleting)
/// and the orders clients placed on them (accepting one starts its agreement; declining ends it).
enum ServicesApi {
    private static let base = Api.freelance

    static func mine(status: String? = nil) async -> ApiResult<MyServicesDto> { await Api.call("GET", base + "services", query: ["status": status]) }

    static func show(_ uuid: String) async -> ApiResult<ServiceDto> { await Api.call("GET", base + "services/\(uuid)") }

    static func create(_ body: ServiceRequest) async -> ApiResult<ServiceDto> { await Api.call("POST", base + "services", body: body.json) }

    static func update(_ uuid: String, _ body: ServiceRequest) async -> ApiResult<ServiceDto> { await Api.call("PUT", base + "services/\(uuid)", body: body.json) }

    /// `apiDone`: nil when it went through, else the text to show.
    static func delete(_ uuid: String, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base + "services/\(uuid)", offline: offline, generic: generic)
    }

    /// `action` = submit · pause · resume
    static func act(_ uuid: String, _ action: String) async -> ApiResult<ServiceDto> { await Api.call("POST", base + "services/\(uuid)/\(action)") }

    static func addImage(_ uuid: String, _ image: FilePart) async -> ApiResult<ServiceDto> { await Api.upload(base + "services/\(uuid)/images", files: [image]) }

    static func deleteImage(_ uuid: String, _ media: Int) async -> ApiResult<ServiceDto> { await Api.call("DELETE", base + "services/\(uuid)/images/\(media)") }

    /// `status` = awaiting · active · completed · ended
    static func orders(status: String? = nil, page: Int = 1, perPage: Int = 15) async -> ApiResult<[ServiceOrderDto]> {
        await Api.call("GET", base + "service-orders", query: ["status": status, "page": "\(page)", "per_page": "\(perPage)"])
    }

    static func order(_ uuid: String) async -> ApiResult<ServiceOrderDto> { await Api.call("GET", base + "service-orders/\(uuid)") }

    /// `action` = accept · decline
    static func answer(_ uuid: String, _ action: String, _ body: OrderAnswerRequest = OrderAnswerRequest()) async -> ApiResult<ServiceOrderDto> {
        await Api.call("POST", base + "service-orders/\(uuid)/\(action)", body: body.json)
    }

    /// FL-L05: the monthly subscriptions to my services. `status` = pending · active · ended
    static func subscriptions(status: String? = nil, page: Int = 1, perPage: Int = 15) async -> ApiResult<[ServiceSubscriptionDto]> {
        await Api.call("GET", base + "service-subscriptions", query: ["status": status, "page": "\(page)", "per_page": "\(perPage)"])
    }

    /// `action` = cancel (with notice) · keep (take my ending back)
    static func subscriptionAct(_ uuid: String, _ action: String) async -> ApiResult<ServiceSubscriptionDto> {
        await Api.call("POST", base + "service-subscriptions/\(uuid)/\(action)")
    }
}

struct MyServicesDto: Decodable {
    var services: [ServiceDto]?
    var counts: [String: Int]?
    var limits: ServiceLimitsDto?
    var currency: String?
    var decimals: Int?
    var price: PriceRuleDto?
    /// The staff look at a service before clients see it.
    var review: Bool?
    var answerHours: Int?
}

struct ServiceLimitsDto: Decodable {
    var maxImages: Int?
    var maxAddons: Int?
    var maxFeatures: Int?
    var maxActive: Int?
}

struct PriceRuleDto: Decodable {
    var minMinor: Int?
    var maxMinor: Int?
}

struct ServiceDto: Decodable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var title: String?
    var description: String?
    var requirements: String?
    /// FL-L05: once · monthly (the prices are then per month).
    var billing: String?
    var specialty: ServiceSpecialtyDto?
    var currency: String?
    var decimals: Int?
    var startingPriceMinor: Int?
    var tiers: [ServiceTierDto]?
    var addons: [ServiceAddonDto]?
    var images: [ServiceImageDto]?
    /// draft · pending_review · published · paused · rejected · suspended
    var status: String?
    var statusLabel: String?
    var statusReason: String?
    var orders: ServiceOrderCountsDto?
    var can: ServiceCanDto?
    var publishedAt: String?
    var updatedAt: String?
}

struct ServiceSpecialtyDto: Decodable {
    var id: Int?
    var name: String?
}

struct ServiceTierDto: Decodable {
    /// basic · standard · premium
    var level: String?
    var levelLabel: String?
    var name: String?
    var description: String?
    var priceMinor: Int?
    var deliveryDays: Int?
    var revisions: Int?
    var features: [String]?
}

struct ServiceAddonDto: Decodable {
    var id: Int?
    var title: String?
    var priceMinor: Int?
    var extraDays: Int?
}

struct ServiceImageDto: Decodable {
    var id: Int?
    var name: String?
    var path: String?
}

struct ServiceOrderCountsDto: Decodable {
    var awaiting: Int?
    var active: Int?
    var completed: Int?
}

struct ServiceCanDto: Decodable {
    var edit: Bool?
    var submit: Bool?
    var pause: Bool?
    var resume: Bool?
    var delete: Bool?
}

/// The whole service each time: `tiers` and `addons` replace what was there. (Gson leaves a null field out of the body.)
struct ServiceRequest {
    let title: String
    let description: String
    let requirements: String?
    let specialtyId: Int?
    let billing: String
    let tiers: [ServiceTierRequest]
    let addons: [ServiceAddonRequest]

    var json: JsonBody {
        var body: JsonBody = [
            "title": title, "description": description, "billing": billing,
            "tiers": tiers.map(\.json), "addons": addons.map(\.json),
        ]
        if let requirements { body["requirements"] = requirements }
        if let specialtyId { body["specialty_id"] = specialtyId }
        return body
    }
}

struct ServiceTierRequest {
    let level: String
    let name: String?
    let description: String?
    let priceMinor: Int
    let deliveryDays: Int
    let revisions: Int
    let features: [String]

    var json: [String: Any] {
        var body: [String: Any] = ["level": level, "price_minor": priceMinor, "delivery_days": deliveryDays, "revisions": revisions, "features": features]
        if let name { body["name"] = name }
        if let description { body["description"] = description }
        return body
    }
}

struct ServiceAddonRequest {
    let title: String
    let priceMinor: Int
    let extraDays: Int

    var json: [String: Any] { ["title": title, "price_minor": priceMinor, "extra_days": extraDays] }
}

struct ServiceOrderDto: Decodable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    /// awaiting · active · completed · declined · expired · cancelled
    var status: String?
    var service: ServiceOrderServiceDto?
    var level: String?
    var levelLabel: String?
    var tierName: String?
    var features: [String]?
    var addons: [ServiceOrderAddonDto]?
    var priceMinor: Int?
    var currency: String?
    var decimals: Int?
    var deliveryDays: Int?
    var revisions: Int?
    var note: String?
    /// The project the order runs as — after accepting, its agreement screen.
    var project: String?
    /// FL-L05: the monthly subscription this order is one month of.
    var subscription: OrderSubscriptionDto?
    var answerBy: String?
    var money: ServiceOrderMoneyDto?
    var client: ServiceOrderClientDto?
    var can: ServiceOrderCanDto?
    var createdAt: String?
}

struct ServiceOrderServiceDto: Decodable {
    var uuid: String?
    var title: String?
}

struct ServiceOrderAddonDto: Decodable {
    var title: String?
    var priceMinor: Int?
    var extraDays: Int?
}

struct ServiceOrderMoneyDto: Decodable {
    var priceMinor: Int?
    var commissionMinor: Int?
    var netMinor: Int?
}

struct ServiceOrderClientDto: Decodable {
    var name: String?
}

struct ServiceOrderCanDto: Decodable {
    var accept: Bool?
    var decline: Bool?
}

struct OrderAnswerRequest {
    var reason: String?

    var json: JsonBody {
        var body: JsonBody = [:]
        if let reason { body["reason"] = reason }
        return body
    }
}

struct OrderSubscriptionDto: Decodable {
    var uuid: String?
    var cycle: Int?
}

/// FL-L05: a client's monthly subscription to one of my services, at the terms kept from its start.
struct ServiceSubscriptionDto: Decodable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    /// pending · active · ended
    var status: String?
    /// first_declined · first_expired · first_cancelled · cancelled_client · cancelled_freelancer · not_renewed
    var endReason: String?
    var service: ServiceOrderServiceDto?
    var level: String?
    var tierName: String?
    var priceMinor: Int?
    var currency: String?
    var decimals: Int?
    var deliveryDays: Int?
    var money: ServiceOrderMoneyDto?
    var cycle: Int?
    var periodEndsAt: String?
    var renew: SubscriptionRenewDto?
    var cancel: SubscriptionCancelDto?
    var noticeDays: Int?
    var current: SubscriptionMonthDto?
    var client: ServiceOrderClientDto?
    var can: SubscriptionCanDto?
    var endedAt: String?
}

struct SubscriptionRenewDto: Decodable {
    var opensAt: String?
    var dueBy: String?
    var open: Bool?
}

/// `by` client · freelancer; it ends on `endsAt`.
struct SubscriptionCancelDto: Decodable {
    var by: String?
    var endsAt: String?
}

struct SubscriptionMonthDto: Decodable {
    var uuid: String?
    var cycle: Int?
    var status: String?
    var project: String?
}

struct SubscriptionCanDto: Decodable {
    var cancel: Bool?
    var keep: Bool?
}
