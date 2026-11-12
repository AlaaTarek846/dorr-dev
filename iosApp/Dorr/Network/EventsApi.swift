import Foundation

// androidApp `network/EventsApi.kt` — DORR Discover: trusted public events by city, interest and date. Every
// event carries its own local time (`local_date` / `local_time` in `timezone`) and mine (`my_time`) when I'm
// somewhere else; `timezone` on each call is where I am now. (Sharing into a chat and "go together" rooms join
// with the Chat section.)

struct DiscCategoryDto: Codable, Equatable, Identifiable {
    var id: Int
    var key: String
    var name: String?
    var emoji: String?
    var color: String?

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        key = c.value(.key, "")
        name = c.value(.name, nil)
        emoji = c.value(.emoji, nil)
        color = c.value(.color, nil)
    }
}

struct DiscRegionDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?
    var countryId: Int?
    var eventsCount: Int

    enum CodingKeys: String, CodingKey { case id, name, countryId = "country_id", eventsCount = "events_count" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        name = c.value(.name, nil)
        countryId = c.value(.countryId, nil)
        eventsCount = c.value(.eventsCount, 0)
    }
}

struct DiscRegionsMetaDto: Codable, Equatable {
    var currentPage: Int = 1
    var hasMorePages: Bool = false
    enum CodingKeys: String, CodingKey { case currentPage = "current_page", hasMorePages = "has_more_pages" }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        currentPage = c.value(.currentPage, 1)
        hasMorePages = c.value(.hasMorePages, false)
    }
}

/// The home's first batch of regions; more come from GET regions.
struct DiscRegionsDto: Codable, Equatable {
    var items: [DiscRegionDto] = []
    var meta = DiscRegionsMetaDto()

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        items = c.value(.items, [])
        meta = c.value(.meta, DiscRegionsMetaDto())
    }
}

struct DiscCountryDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?
    var code: String?
}

struct DiscCurrencyDto: Codable, Equatable {
    var code: String?
    var symbol: String?
    var decimalPlaces: Int?
    enum CodingKeys: String, CodingKey { case code, symbol, decimalPlaces = "decimal_places" }
}

struct DiscCityDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?
    var country: String?
    var countryId: Int?
    var regionId: Int?
    var timezone: String?
    var currency: DiscCurrencyDto?

    enum CodingKeys: String, CodingKey {
        case id, name, country, timezone, currency
        case countryId = "country_id", regionId = "region_id"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        name = c.value(.name, nil)
        country = c.value(.country, nil)
        countryId = c.value(.countryId, nil)
        regionId = c.value(.regionId, nil)
        timezone = c.value(.timezone, nil)
        currency = c.value(.currency, nil)
    }
}

struct DiscOrganizerRefDto: Codable, Equatable {
    var name: String?
    var verified: Bool?
}

struct DiscHistoryDto: Codable, Equatable {
    var from: String?
    var to: String?
    var note: String?
    var oldStartsAt: String?
    var at: String?
    enum CodingKeys: String, CodingKey { case from, to, note, at, oldStartsAt = "old_starts_at" }
}

/// One event. `status`: confirmed · postponed · cancelled · sold_out · ended. `verified` only for trusted sources.
struct DiscEventDto: Codable, Equatable, Identifiable {
    var id: String
    var title: String
    var category: DiscCategoryDto?
    var city: DiscCityDto?
    var country: String?
    var venue: String?
    var address: String?
    var lat: Double?
    var lng: Double?
    var startsAt: String?
    var endsAt: String?
    var timezone: String?
    var localDate: String?
    var localTime: String?
    var localEnd: String?
    var myTime: String?
    var isFree: Bool
    var priceText: String?
    var priceDisplay: String?
    var currency: DiscCurrencyDto?
    var familyFriendly: Bool
    var status: String
    var verified: Bool
    /// organizer | admin
    var source: String?
    var organizer: DiscOrganizerRefDto?
    var cover: String?
    var interestedCount: Int
    var interested: Bool
    var notify: Bool
    var distanceKm: Double?
    var description: String?
    var sourceUrl: String?
    var bookingUrl: String?
    var lastVerifiedAt: String?
    var statusNote: String?
    var oldStartsAt: String?
    var history: [DiscHistoryDto]?
    var mine: Bool
    var reviewStatus: String?
    var reviewNote: String?

    enum CodingKeys: String, CodingKey {
        case id, title, category, city, country, venue, address, lat, lng, timezone, currency, status, verified, source, organizer, cover
        case interested, notify, description, history, mine
        case startsAt = "starts_at", endsAt = "ends_at", localDate = "local_date", localTime = "local_time", localEnd = "local_end"
        case myTime = "my_time", isFree = "is_free", priceText = "price_text", priceDisplay = "price_display", familyFriendly = "family_friendly"
        case interestedCount = "interested_count", distanceKm = "distance_km", sourceUrl = "source_url", bookingUrl = "booking_url"
        case lastVerifiedAt = "last_verified_at", statusNote = "status_note", oldStartsAt = "old_starts_at"
        case reviewStatus = "review_status", reviewNote = "review_note"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        if let s = try? c.decode(String.self, forKey: .id) { id = s } else { id = String(c.value(.id, 0)) }
        title = c.value(.title, "")
        category = c.value(.category, nil)
        city = c.value(.city, nil)
        country = c.value(.country, nil)
        venue = c.value(.venue, nil)
        address = c.value(.address, nil)
        lat = c.value(.lat, nil)
        lng = c.value(.lng, nil)
        startsAt = c.value(.startsAt, nil)
        endsAt = c.value(.endsAt, nil)
        timezone = c.value(.timezone, nil)
        localDate = c.value(.localDate, nil)
        localTime = c.value(.localTime, nil)
        localEnd = c.value(.localEnd, nil)
        myTime = c.value(.myTime, nil)
        isFree = c.value(.isFree, false)
        priceText = c.value(.priceText, nil)
        priceDisplay = c.value(.priceDisplay, nil)
        currency = c.value(.currency, nil)
        familyFriendly = c.value(.familyFriendly, false)
        status = c.value(.status, "confirmed")
        verified = c.value(.verified, false)
        source = c.value(.source, nil)
        organizer = c.value(.organizer, nil)
        cover = c.value(.cover, nil)
        interestedCount = c.value(.interestedCount, 0)
        interested = c.value(.interested, false)
        notify = c.value(.notify, false)
        distanceKm = c.value(.distanceKm, nil)
        description = c.value(.description, nil)
        sourceUrl = c.value(.sourceUrl, nil)
        bookingUrl = c.value(.bookingUrl, nil)
        lastVerifiedAt = c.value(.lastVerifiedAt, nil)
        statusNote = c.value(.statusNote, nil)
        oldStartsAt = c.value(.oldStartsAt, nil)
        history = c.value(.history, nil)
        mine = c.value(.mine, false)
        reviewStatus = c.value(.reviewStatus, nil)
        reviewNote = c.value(.reviewNote, nil)
    }
}

struct DiscSectionDto: Codable, Equatable {
    var key: String
    var items: [DiscEventDto]
    var hasMore: Bool
    enum CodingKeys: String, CodingKey { case key, items, hasMore = "has_more" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        key = c.value(.key, "")
        items = c.value(.items, [])
        hasMore = c.value(.hasMore, false)
    }
}

struct DiscPrefsDto: Codable, Equatable {
    var categories: [Int] = []
    var alerts = true
    var alertDays = 14
    var familyOnly = false
    enum CodingKeys: String, CodingKey { case categories, alerts, alertDays = "alert_days", familyOnly = "family_only" }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        categories = c.value(.categories, [])
        alerts = c.value(.alerts, true)
        alertDays = c.value(.alertDays, 14)
        familyOnly = c.value(.familyOnly, false)
    }
}

struct DiscFollowDto: Codable, Equatable, Identifiable {
    var id: Int
    var kind: String
    var targetId: Int
    var name: String?
    var country: String?
    enum CodingKeys: String, CodingKey { case id, kind, name, country, targetId = "target_id" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        kind = c.value(.kind, "city")
        targetId = c.value(.targetId, 0)
        name = c.value(.name, nil)
        country = c.value(.country, nil)
    }
}

struct DiscHomeDto: Codable, Equatable {
    var country: String?
    var categories: [DiscCategoryDto]
    var cities: [DiscCityDto]
    var regions: DiscRegionsDto
    var follows: [DiscFollowDto]
    var preferences: DiscPrefsDto?
    var canSubmit: Bool
    var ai: Bool
    var sections: [DiscSectionDto]
    enum CodingKeys: String, CodingKey { case country, categories, cities, regions, follows, preferences, ai, sections, canSubmit = "can_submit" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        country = c.value(.country, nil)
        categories = c.value(.categories, [])
        cities = c.value(.cities, [])
        regions = c.value(.regions, DiscRegionsDto())
        follows = c.value(.follows, [])
        preferences = c.value(.preferences, nil)
        canSubmit = c.value(.canSubmit, false)
        ai = c.value(.ai, false)
        sections = c.value(.sections, [])
    }
}

/// Where the search ran: its time zone (the days are cut in it).
struct DiscTravelPlaceDto: Codable, Equatable { var timezone: String? }

struct DiscTravelDto: Codable, Equatable {
    var city: DiscCityDto?
    var place: DiscTravelPlaceDto?
    var from: String?
    var to: String?
    var items: [DiscEventDto]

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        city = c.value(.city, nil)
        place = c.value(.place, nil)
        from = c.value(.from, nil)
        to = c.value(.to, nil)
        items = c.value(.items, [])
    }
}

struct DiscAskFiltersDto: Codable, Equatable {
    var cityId: Int?
    var city: String?
    var categories: [String]?
    var from: String?
    var to: String?
    var free: Bool?
    var family: Bool?
    enum CodingKeys: String, CodingKey { case city, categories, from, to, free, family, cityId = "city_id" }
}

struct DiscAskDto: Codable, Equatable {
    var filters: DiscAskFiltersDto?
    var items: [DiscEventDto]

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        filters = c.value(.filters, nil)
        items = c.value(.items, [])
    }
}

/// `status`: pending · verified · rejected · suspended.
struct DiscOrganizerDto: Codable, Equatable {
    var id: Int
    var name: String
    var about: String?
    var website: String?
    var phone: String?
    var email: String?
    var status: String
    var reviewNote: String?
    var verified: Bool
    enum CodingKeys: String, CodingKey { case id, name, about, website, phone, email, status, verified, reviewNote = "review_note" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        name = c.value(.name, "")
        about = c.value(.about, nil)
        website = c.value(.website, nil)
        phone = c.value(.phone, nil)
        email = c.value(.email, nil)
        status = c.value(.status, "pending")
        reviewNote = c.value(.reviewNote, nil)
        verified = c.value(.verified, false)
    }
}

struct DiscOrganizerHomeDto: Codable, Equatable {
    var organizer: DiscOrganizerDto?
    var canSubmit = false
    var events: [DiscEventDto] = []
    enum CodingKeys: String, CodingKey { case organizer, events, canSubmit = "can_submit" }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        organizer = c.value(.organizer, nil)
        canSubmit = c.value(.canSubmit, false)
        events = c.value(.events, [])
    }
}

enum EventsApi {
    /// Share in a chat as a card, maybe with «who's going?» (178).
    static func share(_ id: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("mobile/v1/discover/events/\(id)/share", method: "POST", body: body)
    }
    /// «Go together»: a group with the people I pick, the event's card inside.
    static func room(_ id: String, members: [Int]) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("mobile/v1/discover/events/\(id)/room", method: "POST", body: ["members": .array(members.map { .int($0) })])
    }

    static func home(timezone: String) async throws -> ApiEnvelope<DiscHomeDto> {
        try await ApiClient.get("mobile/v1/discover/home", query: ["timezone": timezone], auth: true)
    }

    /// The next 15 of a home strip — the row scrolls; no new page opens.
    static func section(_ key: String, timezone: String, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[DiscEventDto]> {
        try await ApiClient.get("mobile/v1/discover/sections/\(key)", query: ["timezone": timezone, "page": String(page), "per_page": String(perPage)], auth: true)
    }

    static func events(timezone: String, cityId: Int? = nil, categoryId: Int? = nil, regionId: Int? = nil, countryId: Int? = nil,
                       from: String? = nil, to: String? = nil, free: Int? = nil, family: Int? = nil, q: String? = nil, sort: String? = nil,
                       page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[DiscEventDto]> {
        try await ApiClient.get("mobile/v1/discover/events", query: [
            "timezone": timezone, "city_id": cityId.map(String.init), "category_ids[]": categoryId.map(String.init),
            "region_id": regionId.map(String.init), "country_id": countryId.map(String.init), "from": from, "to": to,
            "free": free.map(String.init), "family": family.map(String.init), "q": q, "sort": sort,
            "page": String(page), "per_page": String(perPage),
        ], auth: true)
    }

    /// What's on where I'm going, on my dates — the days cut in that place's own zone.
    static func travel(from: String, to: String, timezone: String, cityId: Int? = nil, regionId: Int? = nil, countryId: Int? = nil) async throws -> ApiEnvelope<DiscTravelDto> {
        try await ApiClient.get("mobile/v1/discover/travel", query: [
            "from": from, "to": to, "timezone": timezone, "city_id": cityId.map(String.init),
            "region_id": regionId.map(String.init), "country_id": countryId.map(String.init),
        ], auth: true)
    }

    /// `{text}` — DORR AI turns it into filters; the events are real ones.
    static func ask(_ text: String, timezone: String) async throws -> ApiEnvelope<DiscAskDto> {
        try await ApiClient.post("mobile/v1/discover/ask", query: ["timezone": timezone], body: ["text": text], auth: true)
    }

    static func categories(page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[DiscCategoryDto]> {
        try await ApiClient.get("mobile/v1/discover/categories", query: ["page": String(page), "per_page": String(perPage)], auth: true)
    }

    static func cities(countryId: Int? = nil, regionId: Int? = nil, withEvents: Int? = nil) async throws -> ApiEnvelope<[DiscCityDto]> {
        try await ApiClient.get("mobile/v1/discover/cities", query: [
            "country_id": countryId.map(String.init), "region_id": regionId.map(String.init), "with_events": withEvents.map(String.init),
        ], auth: true)
    }

    static func regions(countryId: Int? = nil, page: Int = 1, perPage: Int = 10, withEvents: Int? = nil) async throws -> ApiEnvelope<[DiscRegionDto]> {
        try await ApiClient.get("mobile/v1/discover/regions", query: [
            "country_id": countryId.map(String.init), "page": String(page), "per_page": String(perPage), "with_events": withEvents.map(String.init),
        ], auth: true)
    }

    static func countries() async throws -> ApiEnvelope<[DiscCountryDto]> {
        try await ApiClient.get("mobile/v1/discover/countries", auth: true)
    }

    static func event(_ id: String, timezone: String) async throws -> ApiEnvelope<DiscEventDto> {
        try await ApiClient.get("mobile/v1/discover/events/\(id)", query: ["timezone": timezone], auth: true)
    }

    /// `{notify}` — saved, in my calendar, told when it changes.
    static func interest(_ id: String, notify: Bool, timezone: String) async throws -> ApiEnvelope<DiscEventDto> {
        try await ApiClient.post("mobile/v1/discover/events/\(id)/interest", query: ["timezone": timezone], body: ["notify": notify], auth: true)
    }

    static func uninterest(_ id: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.delete("mobile/v1/discover/events/\(id)/interest")
    }

    static func interests(timezone: String, past: Int = 0, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[DiscEventDto]> {
        try await ApiClient.get("mobile/v1/discover/interests", query: [
            "timezone": timezone, "past": String(past), "page": String(page), "per_page": String(perPage),
        ], auth: true)
    }

    static func savePreferences(_ body: [String: JSONValue]) async throws -> ApiEnvelope<DiscPrefsDto> {
        try await ApiClient.put("mobile/v1/discover/preferences", body: body)
    }

    /// `{kind: city | country | region, target_id}`
    static func follow(kind: String, targetId: Int) async throws -> ApiEnvelope<[DiscFollowDto]> {
        try await ApiClient.post("mobile/v1/discover/follows", body: ["kind": JSONValue.string(kind), "target_id": .int(targetId)], auth: true)
    }

    static func unfollow(_ id: Int) async throws -> ApiEnvelope<[DiscFollowDto]> {
        try await ApiClient.delete("mobile/v1/discover/follows/\(id)")
    }

    static func organizer(timezone: String, page: Int = 1, perPage: Int = 15, q: String? = nil) async throws -> ApiEnvelope<DiscOrganizerHomeDto> {
        try await ApiClient.get("mobile/v1/discover/organizer", query: ["timezone": timezone, "page": String(page), "per_page": String(perPage), "q": q], auth: true)
    }

    /// `{name, about?, website?, phone?, email?}`
    static func applyOrganizer(_ body: [String: String]) async throws -> ApiEnvelope<DiscOrganizerDto> {
        try await ApiClient.post("mobile/v1/discover/organizer", body: body, auth: true)
    }

    /// The event's own local time in `starts_at` ("2026-11-05 20:00"), read in the city's zone. With a cover photo
    /// it goes as multipart (Laravel's "boolean" rule takes 1 / 0 there).
    static func submit(_ body: [String: JSONValue], cover: Data?, timezone: String) async throws -> ApiEnvelope<DiscEventDto> {
        guard let cover else {
            return try await ApiClient.post("mobile/v1/discover/organizer/events", query: ["timezone": timezone], body: body, auth: true)
        }
        return try await ApiClient.upload("mobile/v1/discover/organizer/events", query: ["timezone": timezone], fields: multipartFields(body),
                                          files: [ApiClient.FilePart(field: "cover", filename: "cover.jpg", mimeType: "image/jpeg", data: cover)])
    }

    /// An organizer's own event: any field, and the cover (multipart).
    static func updateEvent(_ id: String, fields: [String: JSONValue], cover: Data?, timezone: String) async throws -> ApiEnvelope<DiscEventDto> {
        try await ApiClient.upload("mobile/v1/discover/organizer/events/\(id)", query: ["timezone": timezone], fields: multipartFields(fields),
                                   files: cover.map { [ApiClient.FilePart(field: "cover", filename: "cover.jpg", mimeType: "image/jpeg", data: $0)] } ?? [])
    }

    /// `{status, note?, starts_at?}`
    static func organizerStatus(_ id: String, _ body: [String: String], timezone: String) async throws -> ApiEnvelope<DiscEventDto> {
        try await ApiClient.post("mobile/v1/discover/organizer/events/\(id)/status", query: ["timezone": timezone], body: body, auth: true)
    }

    private static func multipartFields(_ body: [String: JSONValue]) -> [String: String] {
        body.compactMapValues { value in
            switch value {
            case .string(let s): return s
            case .int(let n): return String(n)
            case .double(let d): return String(d)
            case .bool(let b): return b ? "1" : "0"
            default: return nil
            }
        }
    }
}
