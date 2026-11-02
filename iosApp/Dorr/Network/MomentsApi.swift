import Foundation

// androidApp `network/MomentsApi.kt` — occasions, my preferences, my own dates; and (`MomentsChatApi`) greeting cards,
// group cards and capsules, sent inside chats.

struct MomentDto: Codable, Equatable, Identifiable {
    var id: Int
    var key: String
    var name: String?
    var greeting: String?
    var kind: String
    var start: String
    var end: String
    var daysLeft: Int
    var isToday: Bool
    var isVisible: Bool
    var theme: String?
    var primaryColor: String?
    var secondaryColor: String?
    var emoji: String?
    var animation: String?
    var cardImage: String?

    enum CodingKeys: String, CodingKey {
        case id, key, name, greeting, kind, start, end, theme, emoji, animation
        case daysLeft = "days_left", isToday = "is_today", isVisible = "is_visible"
        case primaryColor = "primary_color", secondaryColor = "secondary_color", cardImage = "card_image"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        key = c.value(.key, "")
        name = c.value(.name, nil)
        greeting = c.value(.greeting, nil)
        kind = c.value(.kind, "")
        start = c.value(.start, "")
        end = c.value(.end, "")
        daysLeft = c.value(.daysLeft, 0)
        isToday = c.value(.isToday, false)
        isVisible = c.value(.isVisible, false)
        theme = c.value(.theme, nil)
        primaryColor = c.value(.primaryColor, nil)
        secondaryColor = c.value(.secondaryColor, nil)
        emoji = c.value(.emoji, nil)
        animation = c.value(.animation, nil)
        cardImage = c.value(.cardImage, nil)
    }
}

struct MomentLookDto: Codable, Equatable {
    var primaryColor: String?
    var secondaryColor: String?
    var emoji: String?
    var animation: String?

    enum CodingKeys: String, CodingKey {
        case emoji, animation
        case primaryColor = "primary_color", secondaryColor = "secondary_color"
    }
}

struct PersonalMomentDto: Codable, Equatable, Identifiable {
    var id: String
    var kind: String
    var title: String
    var month: Int
    var day: Int
    var year: Int?
    var next: String
    var daysLeft: Int
    var turns: Int?
    var contact: ProfileDto?
    var remindDaysBefore: Int
    var look: MomentLookDto?

    enum CodingKeys: String, CodingKey {
        case id, kind, title, month, day, year, next, turns, contact, look
        case daysLeft = "days_left", remindDaysBefore = "remind_days_before"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        if let s = try? c.decode(String.self, forKey: .id) { id = s } else { id = String(c.value(.id, 0)) }
        kind = c.value(.kind, "other")
        title = c.value(.title, "")
        month = c.value(.month, 1)
        day = c.value(.day, 1)
        year = c.value(.year, nil)
        next = c.value(.next, "")
        daysLeft = c.value(.daysLeft, 0)
        turns = c.value(.turns, nil)
        contact = c.value(.contact, nil)
        remindDaysBefore = c.value(.remindDaysBefore, 1)
        look = c.value(.look, nil)
    }
}

struct MomentCountryDto: Codable, Equatable {
    var id: Int
    var code: String
    var name: String?
}

struct MomentsCenterDto: Codable, Equatable {
    var enabled: Bool
    /// full · light · off
    var effects: String
    var country: MomentCountryDto?
    var active: [MomentDto]
    var upcoming: [MomentDto]
    var personal: [PersonalMomentDto]

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        enabled = c.value(.enabled, true)
        effects = c.value(.effects, "full")
        country = c.value(.country, nil)
        active = c.value(.active, [])
        upcoming = c.value(.upcoming, [])
        personal = c.value(.personal, [])
    }
}

struct MomentCatalogItemDto: Codable, Equatable, Identifiable {
    var id: Int
    var key: String
    var name: String?
    var kind: String
    var emoji: String?
    var next: String?
    var defaultOn: Bool
    var on: Bool

    enum CodingKeys: String, CodingKey {
        case id, key, name, kind, emoji, next, on
        case defaultOn = "default_on"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        key = c.value(.key, "")
        name = c.value(.name, nil)
        kind = c.value(.kind, "")
        emoji = c.value(.emoji, nil)
        next = c.value(.next, nil)
        defaultOn = c.value(.defaultOn, true)
        on = c.value(.on, false)
    }
}

enum MomentsApi {
    static func center() async throws -> ApiEnvelope<MomentsCenterDto> {
        try await ApiClient.get("mobile/v1/chat/moments", auth: true)
    }

    static func catalog(countryId: Int? = nil) async throws -> ApiEnvelope<[MomentCatalogItemDto]> {
        try await ApiClient.get("mobile/v1/chat/moments/catalog", query: ["country_id": countryId.map(String.init)], auth: true)
    }

    /// `{enabled?, country_id?, effects?, on?[], off?[]}` → the center again.
    static func preferences(_ body: [String: JSONValue]) async throws -> ApiEnvelope<MomentsCenterDto> {
        try await ApiClient.put("mobile/v1/chat/moments/preferences", body: body)
    }

    static func addPersonal(_ body: [String: JSONValue]) async throws -> ApiEnvelope<[PersonalMomentDto]> {
        try await ApiClient.post("mobile/v1/chat/moments/personal", body: body, auth: true)
    }

    static func updatePersonal(_ id: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<[PersonalMomentDto]> {
        try await ApiClient.patch("mobile/v1/chat/moments/personal/\(id)", body: body)
    }

    static func deletePersonal(_ id: String) async throws -> ApiEnvelope<[PersonalMomentDto]> {
        try await ApiClient.delete("mobile/v1/chat/moments/personal/\(id)")
    }
}

// MARK: - The bits of androidApp `network/ChatApi.kt` other sections need before the chat itself

/// A key that matches a JSON field as written ("account_name") or camel-cased by the snake decoder ("accountName"),
/// so the chat's DTOs read the same through either decoder.
struct DualKey: CodingKey {
    var stringValue: String
    var intValue: Int? { nil }
    init(_ s: String) { stringValue = s }
    init?(stringValue: String) { self.stringValue = stringValue }
    init?(intValue: Int) { nil }
}

extension KeyedDecodingContainer where K == DualKey {
    /// The field under its snake name or its camel one, else the fallback (never throws).
    func dual<T: Decodable>(_ snake: String, _ fallback: T) -> T {
        if let v = try? decodeIfPresent(T.self, forKey: DualKey(snake)) { return v }
        let camel = snake.split(separator: "_").enumerated().map { $0 == 0 ? String($1) : $1.prefix(1).uppercased() + $1.dropFirst() }.joined()
        if camel != snake, let v = try? decodeIfPresent(T.self, forKey: DualKey(camel)) { return v }
        return fallback
    }
}

/// A personal status: an emoji and a few words, until a time (or until cleared).
struct UserStatusDto: Codable, Equatable {
    var emoji: String?
    var text: String?
    var until: String?
}

/// A person (or channel) as the chat shows them.
struct ProfileDto: Codable, Equatable, Hashable {
    var type: String
    var id: Int
    var key: String
    var name: String?
    var accountName: String?
    /// Their @username.
    var username: String?
    var phone: String?
    var avatar: String?
    var isContact: Bool
    var isMe: Bool
    var isDeleted: Bool
    var status: UserStatusDto?

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: DualKey.self)
        type = c.dual("type", "user")
        id = c.dual("id", 0)
        key = c.dual("key", "")
        name = c.dual("name", nil)
        accountName = c.dual("account_name", nil)
        username = c.dual("username", nil)
        phone = c.dual("phone", nil)
        avatar = c.dual("avatar", nil)
        isContact = c.dual("is_contact", false)
        isMe = c.dual("is_me", false)
        isDeleted = c.dual("is_deleted", false)
        status = c.dual("status", nil)
    }

    static func == (a: ProfileDto, b: ProfileDto) -> Bool { a.key == b.key && a.name == b.name && a.avatar == b.avatar && a.status == b.status }
    func hash(into h: inout Hasher) { h.combine(key) }
}

extension UserStatusDto: Hashable {}

struct ContactDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String
    var phone: String
    var isFavorite: Bool
    var source: String
    var isRegistered: Bool
    var profile: ProfileDto?

    init(id: Int, name: String, phone: String, isFavorite: Bool, source: String, isRegistered: Bool, profile: ProfileDto?) {
        self.id = id
        self.name = name
        self.phone = phone
        self.isFavorite = isFavorite
        self.source = source
        self.isRegistered = isRegistered
        self.profile = profile
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: DualKey.self)
        id = c.dual("id", 0)
        name = c.dual("name", "")
        phone = c.dual("phone", "")
        isFavorite = c.dual("is_favorite", false)
        source = c.dual("source", "")
        isRegistered = c.dual("is_registered", false)
        profile = c.dual("profile", nil)
    }
}

enum ChatContactsApi {
    static func contacts(registered: Int = 0, q: String? = nil, page: Int? = nil, perPage: Int? = nil) async throws -> ApiEnvelope<[ContactDto]> {
        try await ApiClient.get("mobile/v1/chat/contacts", query: [
            "registered": String(registered), "q": q, "page": page.map(String.init), "per_page": perPage.map(String.init),
        ], auth: true)
    }
}

// MARK: - greeting cards, group cards, capsules (sent inside chats — androidApp `MomentsApi.kt`)

struct GreetingsDto: Codable, Equatable {
    var greetings: [String]?
}

/// A card's look (snapshotted on the message's `meta.card` and on a group card).
struct CardLookDto: Codable, Equatable, Hashable {
    var momentId: Int?
    var kind: String?
    var theme: String?
    var title: String?
    var primaryColor: String?
    var secondaryColor: String?
    var emoji: String?
    var animation: String?
    var cardImage: String?
}

struct CollabFileDto: Codable, Equatable {
    var url: String
    var mimeType: String?
}

struct CollabMemberDto: Codable, Equatable {
    var profile: ProfileDto?
    var signed: Bool?
    var text: String?
    var files: [CollabFileDto]?
}

struct CollabMineDto: Codable, Equatable {
    var text: String?
    var signed: Bool?
}

struct CollabCardDto: Codable, Equatable, Identifiable {
    var id: String
    var title: String
    var look: CardLookDto?
    /// collecting · sent · cancelled
    var status: String?
    var deadlineAt: String?
    var organiser: ProfileDto?
    var recipient: ProfileDto?
    var isOrganiser: Bool?
    var members: [CollabMemberDto]?
    var mine: CollabMineDto?
}

struct CapsuleItemDto: Codable, Equatable, Identifiable {
    var id: Int
    var type: String
    var text: String?
    var senderName: String?
    var note: String?
    var originalAt: String?
    var files: [CollabFileDto]?
}

struct CapsuleDto: Codable, Equatable, Identifiable {
    var id: String
    var title: String
    var emoji: String?
    var itemsCount: Int?
    var items: [CapsuleItemDto]?
}

enum MomentsChatApi {
    private static let base = "mobile/v1/chat"

    /// A card in a chat: `moment_id | personal_kind, title?, text?, reveal_at?, send_at? (Y-m-d H:i), schedule_zone?,
    /// gift_amount_minor?` + `voice`, `photos[]`. The wallet PIN goes in `X-Wallet-Pin` with a gift. Back: the message —
    /// or `{scheduled}`.
    static func sendCard(_ conversationId: String, pin: String?, fields: [String: String], files: [ApiClient.FilePart]) async throws -> ApiEnvelope<JSONAny?> {
        let data = try await ApiClient.uploadRaw("\(base)/conversations/\(conversationId)/moment-card", fields: fields, files: files, headers: pin.map { ["X-Wallet-Pin": $0] } ?? [:])
        return try ApiClient.snakeDecoder.decode(ApiEnvelope<JSONAny?>.self, from: data)
    }
    /// `{moment_id? | kind?, relation, tone, name?}` → three greetings to start from.
    static func greetings(_ body: [String: JSONValue]) async throws -> ApiEnvelope<GreetingsDto> {
        try await ApiClient.snake("\(base)/moments/greetings", method: "POST", body: body)
    }

    static func collabCards() async throws -> ApiEnvelope<[CollabCardDto]> { try await ApiClient.snake("\(base)/collab-cards") }
    /// `{recipient_id, moment_id? | personal_kind?, title?, deadline_at?, members[]}`
    static func createCollab(_ body: [String: JSONValue]) async throws -> ApiEnvelope<CollabCardDto> {
        try await ApiClient.snake("\(base)/collab-cards", method: "POST", body: body)
    }
    static func collabCard(_ id: String) async throws -> ApiEnvelope<CollabCardDto> { try await ApiClient.snake("\(base)/collab-cards/\(id)") }
    static func inviteCollab(_ id: String, members: [Int]) async throws -> ApiEnvelope<CollabCardDto> {
        try await ApiClient.snake("\(base)/collab-cards/\(id)/members", method: "POST", body: ["members": .array(members.map { .int($0) })])
    }
    static func removeCollabMember(_ id: String, user: Int) async throws -> ApiEnvelope<CollabCardDto> {
        try await ApiClient.snake("\(base)/collab-cards/\(id)/members/\(user)", method: "DELETE")
    }
    /// My part: `text?` + `voice?`, `photo?`.
    static func contribute(_ id: String, fields: [String: String], files: [ApiClient.FilePart]) async throws -> ApiEnvelope<CollabCardDto> {
        let data = try await ApiClient.uploadRaw("\(base)/collab-cards/\(id)/contribution", fields: fields, files: files)
        return try ApiClient.snakeDecoder.decode(ApiEnvelope<CollabCardDto>.self, from: data)
    }
    static func sendCollab(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/collab-cards/\(id)/send", method: "POST", body: [:]) }
    static func cancelCollab(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/collab-cards/\(id)", method: "DELETE") }

    static func capsules() async throws -> ApiEnvelope<[CapsuleDto]> { try await ApiClient.snake("\(base)/capsules") }
    static func createCapsule(title: String, emoji: String) async throws -> ApiEnvelope<CapsuleDto> {
        try await ApiClient.snake("\(base)/capsules", method: "POST", body: ["title": .string(title), "emoji": .string(emoji)])
    }
    static func capsule(_ id: String) async throws -> ApiEnvelope<CapsuleDto> { try await ApiClient.snake("\(base)/capsules/\(id)") }
    static func deleteCapsule(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/capsules/\(id)", method: "DELETE") }
    /// `{message_id, note?}` — keep a copy of that message here.
    static func addToCapsule(_ id: String, messageId: String, note: String?) async throws -> ApiEnvelope<CapsuleDto> {
        var body: [String: JSONValue] = ["message_id": .string(messageId)]
        if let note { body["note"] = .string(note) }
        return try await ApiClient.snake("\(base)/capsules/\(id)/items", method: "POST", body: body)
    }
    static func removeFromCapsule(_ id: String, item: Int) async throws -> ApiEnvelope<CapsuleDto> {
        try await ApiClient.snake("\(base)/capsules/\(id)/items/\(item)", method: "DELETE")
    }
}
