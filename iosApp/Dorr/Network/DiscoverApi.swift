import Foundation

// androidApp `network/DiscoverApi.kt` — merchant portals and the categories they pick from. Paying goes
// through the wallet's checkout.

struct CategoryDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?
    var icon: String?
}

struct PortalTranslationDto: Codable, Equatable {
    var locale: String
    var name: String?
    var description: String?
}

struct PortalDto: Codable, Equatable, Identifiable {
    var id: String
    var name: String?
    var description: String?
    var logo: String?
    var websiteUrl: String
    var category: CategoryDto?
    var viewsCount: Int
    // Mine only:
    var status: Bool?
    var isListed: Bool?
    var listedUntil: String?
    var translations: [PortalTranslationDto]?
    /// `portals/{id}` only: every period paid for it, newest first.
    var history: [PortalSubscriptionDto]?

    enum CodingKeys: String, CodingKey {
        case id, name, description, logo, category, status, translations, history
        case websiteUrl = "website_url", viewsCount = "views_count", isListed = "is_listed", listedUntil = "listed_until"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        // The id is a UUID string; an old payload could send a number.
        if let s = try? c.decode(String.self, forKey: .id) { id = s } else { id = String(c.value(.id, 0)) }
        name = c.value(.name, nil)
        description = c.value(.description, nil)
        logo = c.value(.logo, nil)
        websiteUrl = c.value(.websiteUrl, "")
        category = c.value(.category, nil)
        viewsCount = c.value(.viewsCount, 0)
        status = c.value(.status, nil)
        isListed = c.value(.isListed, nil)
        listedUntil = c.value(.listedUntil, nil)
        translations = c.value(.translations, nil)
        history = c.value(.history, nil)
    }
}

/// One paid period of a portal: its package, what was paid, from when to when, and running · upcoming · ended.
struct PortalSubscriptionDto: Codable, Equatable {
    var `package`: String?
    var amountMinor: Int
    var currencyCode: String?
    var paidAt: String?
    var startsAt: String?
    var endsAt: String?
    /// running · upcoming · ended
    var state: String?

    enum CodingKeys: String, CodingKey {
        case `package`, state
        case amountMinor = "amount_minor", currencyCode = "currency_code", paidAt = "paid_at", startsAt = "starts_at", endsAt = "ends_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        `package` = c.value(.package, nil)
        amountMinor = c.value(.amountMinor, 0)
        currencyCode = c.value(.currencyCode, nil)
        paidAt = c.value(.paidAt, nil)
        startsAt = c.value(.startsAt, nil)
        endsAt = c.value(.endsAt, nil)
        state = c.value(.state, nil)
    }
}

struct PortalLanguageDto: Codable, Equatable {
    var code: String
    var name: String
    var direction: String?

    init(code: String, name: String, direction: String?) {
        self.code = code
        self.name = name
        self.direction = direction
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        code = c.value(.code, "")
        name = c.value(.name, "")
        direction = c.value(.direction, nil)
    }
}

struct PackageDto: Codable, Equatable, Identifiable {
    var id: Int
    var kind: String
    var name: String?
    var description: String?
    /// week · month · year
    var period: String
    var periodCount: Int
    var amountMinor: Int?
    var currencyCode: String?

    enum CodingKeys: String, CodingKey {
        case id, kind, name, description, period
        case periodCount = "period_count", amountMinor = "amount_minor", currencyCode = "currency_code"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        kind = c.value(.kind, "")
        name = c.value(.name, nil)
        description = c.value(.description, nil)
        period = c.value(.period, "month")
        periodCount = c.value(.periodCount, 1)
        amountMinor = c.value(.amountMinor, nil)
        currencyCode = c.value(.currencyCode, nil)
    }
}

/// The channels directory: one category and its channels (most followed first).
struct ChannelGroupDto: Codable, Equatable {
    var category: CategoryDto?
    var channels: [ChannelCardDto]
}

enum DiscoverApi {
    static func channelDirectory(search: String? = nil) async throws -> ApiEnvelope<[ChannelGroupDto]> {
        try await ApiClient.snake("mobile/v1/chat/channels/directory", query: ["search": search])
    }
    static func channelCategory(_ id: String, categoryId: Int?) async throws -> ApiEnvelope<ConversationDto> {
        try await ApiClient.snake("mobile/v1/chat/channels/\(id)/category", method: "PATCH", body: ["category_id": categoryId.map { .int($0) } ?? .null])
    }
    static func categories() async throws -> ApiEnvelope<[CategoryDto]> {
        try await ApiClient.get("mobile/v1/chat/categories", auth: true)
    }

    /// The portals page, one list a page at a time (most viewed first): 15, the next 15 as it scrolls. No categories = all.
    static func portals(page: Int, search: String? = nil, categoryIds: [Int] = [], perPage: Int = 15) async throws -> ApiEnvelope<[PortalDto]> {
        var query: [String: String?] = ["page": String(page), "per_page": String(perPage), "search": search]
        // `category_ids[0]=…&category_ids[1]=…` — Laravel reads it as the same array as Android's `category_ids[]`.
        for (i, id) in categoryIds.enumerated() { query["category_ids[\(i)]"] = String(id) }
        return try await ApiClient.get("mobile/v1/chat/portals", query: query, auth: true)
    }

    static func myPortals() async throws -> ApiEnvelope<[PortalDto]> {
        try await ApiClient.get("mobile/v1/chat/portals/mine", auth: true)
    }

    static func portalPackages() async throws -> ApiEnvelope<[PackageDto]> {
        try await ApiClient.get("mobile/v1/chat/portals/packages", auth: true)
    }

    static func portalLanguages() async throws -> ApiEnvelope<[PortalLanguageDto]> {
        try await ApiClient.get("mobile/v1/chat/portals/languages", auth: true)
    }

    static func createPortal(fields: [String: String], logo: Data?) async throws -> ApiEnvelope<PortalDto> {
        try await ApiClient.upload("mobile/v1/chat/portals", fields: fields, files: logoPart(logo))
    }

    static func updatePortal(_ id: String, fields: [String: String], logo: Data?) async throws -> ApiEnvelope<PortalDto> {
        try await ApiClient.upload("mobile/v1/chat/portals/\(id)", fields: fields, files: logoPart(logo))
    }

    /// One of mine, with its subscription history (newest first).
    static func portal(_ id: String) async throws -> ApiEnvelope<PortalDto> {
        try await ApiClient.get("mobile/v1/chat/portals/\(id)", auth: true)
    }

    static func deletePortal(_ id: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.delete("mobile/v1/chat/portals/\(id)")
    }

    /// Counts a view (once a day per person) and answers the website to open.
    static func openPortal(_ id: String) async throws -> ApiEnvelope<PortalDto> {
        try await ApiClient.send("mobile/v1/chat/portals/\(id)/open", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    /// `{from, name, description}` → `{"ar": {"name": …, "description": …}, …}` for the other languages.
    static func translatePortal(_ body: [String: String?]) async throws -> ApiEnvelope<[String: [String: String]]> {
        try await ApiClient.post("mobile/v1/chat/portals/translate", body: body, auth: true)
    }

    private static func logoPart(_ logo: Data?) -> [ApiClient.FilePart] {
        logo.map { [ApiClient.FilePart(field: "logo", filename: "logo.jpg", mimeType: "image/jpeg", data: $0)] } ?? []
    }
}

/// Where a channel's ✔ stands and the packages to buy it with.
struct VerificationDto: Codable, Equatable {
    var isVerified: Bool
    var verifiedUntil: String?
    var verifiedByAdmin: Bool
    var packages: [PackageDto]

    enum CodingKeys: String, CodingKey {
        case isVerified = "is_verified", verifiedUntil = "verified_until", verifiedByAdmin = "verified_by_admin", packages
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        isVerified = (try? c.decode(Bool.self, forKey: .isVerified)) ?? false
        verifiedUntil = try? c.decode(String.self, forKey: .verifiedUntil)
        verifiedByAdmin = (try? c.decode(Bool.self, forKey: .verifiedByAdmin)) ?? false
        packages = (try? c.decode([PackageDto].self, forKey: .packages)) ?? []
    }
}

extension DiscoverApi {
    static func channelVerification(_ id: String) async throws -> ApiEnvelope<VerificationDto> {
        try await ApiClient.get("mobile/v1/chat/channels/\(id)/verification", auth: true)
    }
}
