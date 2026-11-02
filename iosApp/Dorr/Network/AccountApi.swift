import Foundation

// The Account section's endpoints — androidApp `network/ProfileApi.kt`, `AddressApi.kt`, `NotificationApi.kt`,
// `SecurityApi.kt`, `RatingApi.kt`, `ReferralApi.kt`, `SupportApi.kt`. Same paths, same field names.

/// Any JSON value, for free-form payloads (`JsonObject` on Android): read with [obj], [str], [int]…, never
/// crashing on a missing field or a JSON null.
indirect enum JSONAny: Codable, Equatable {
    case string(String), number(Double), bool(Bool), object([String: JSONAny]), array([JSONAny]), null

    init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if c.decodeNil() { self = .null }
        else if let v = try? c.decode(Bool.self) { self = .bool(v) }
        else if let v = try? c.decode(Double.self) { self = .number(v) }
        else if let v = try? c.decode(String.self) { self = .string(v) }
        else if let v = try? c.decode([String: JSONAny].self) { self = .object(v) }
        else if let v = try? c.decode([JSONAny].self) { self = .array(v) }
        else { self = .null }
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.singleValueContainer()
        switch self {
        case .string(let v): try c.encode(v)
        case .number(let v): try c.encode(v)
        case .bool(let v): try c.encode(v)
        case .object(let v): try c.encode(v)
        case .array(let v): try c.encode(v)
        case .null: try c.encodeNil()
        }
    }

    subscript(_ key: String) -> JSONAny? {
        if case .object(let map) = self { return map[key] }
        return nil
    }

    var str: String? {
        switch self {
        case .string(let v): return v
        case .number(let v): return v == v.rounded() ? String(Int(v)) : String(v)
        case .bool(let v): return v ? "true" : "false"
        default: return nil
        }
    }

    var int: Int? {
        switch self {
        case .number(let v): return Int(v)
        case .string(let v): return Int(v)
        default: return nil
        }
    }

    var bool: Bool? {
        switch self {
        case .bool(let v): return v
        case .number(let v): return v != 0
        case .string(let v): return v == "true" || v == "1"
        default: return nil
        }
    }
}

// MARK: - Profile

struct PhoneChangeRequest: Encodable {
    var dialCode: String
    var phone: String
    enum CodingKeys: String, CodingKey { case phone, dialCode = "dial_code" }
}

struct EmailChangeRequest: Encodable { var email: String }

struct ConfirmCodeBody: Encodable { var code: String }

struct UpdateIdentityBody: Encodable {
    var name: String
    var gender: String
}

/// What a `request` step returns: the masked destination the code went to and how long resend stays off.
struct ChannelOtpDto: Codable {
    var maskedPhone: String?
    var maskedEmail: String?
    var resendCooldownSeconds: Int?
    enum CodingKeys: String, CodingKey {
        case maskedPhone = "masked_phone", maskedEmail = "masked_email", resendCooldownSeconds = "resend_cooldown_seconds"
    }
}

/// androidApp `ProfileApi.kt` — phone and e-mail changes are two-step (request sends a code to the new value,
/// confirm swaps it in); identity and avatar apply at once.
enum ProfileApi {
    static func requestPhoneChange(_ body: PhoneChangeRequest) async throws -> ApiEnvelope<ChannelOtpDto> {
        try await ApiClient.post("mobile/v1/profile/phone/request", body: body, auth: true)
    }

    static func confirmPhoneChange(_ body: ConfirmCodeBody) async throws -> ApiEnvelope<UserDto> {
        try await ApiClient.post("mobile/v1/profile/phone/confirm", body: body, auth: true)
    }

    static func updateIdentity(_ body: UpdateIdentityBody) async throws -> ApiEnvelope<UserDto> {
        try await ApiClient.put("mobile/v1/profile/identity", body: body)
    }

    static func updateAvatar(_ jpeg: Data) async throws -> ApiEnvelope<UserDto> {
        try await ApiClient.upload("mobile/v1/profile/avatar", files: [ApiClient.FilePart(field: "avatar", filename: "avatar.jpg", mimeType: "image/jpeg", data: jpeg)])
    }

    static func deleteAvatar() async throws -> ApiEnvelope<UserDto> {
        try await ApiClient.delete("mobile/v1/profile/avatar")
    }

    static func requestEmailChange(_ body: EmailChangeRequest) async throws -> ApiEnvelope<ChannelOtpDto> {
        try await ApiClient.post("mobile/v1/profile/email/request", body: body, auth: true)
    }

    static func confirmEmailChange(_ body: ConfirmCodeBody) async throws -> ApiEnvelope<UserDto> {
        try await ApiClient.post("mobile/v1/profile/email/confirm", body: body, auth: true)
    }

    /// Soft-deletes the account and revokes every token server-side; signing in again with the same phone restores it.
    static func deleteAccount() async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.delete("mobile/v1/profile/account")
    }
}

// MARK: - Addresses

struct AddressDto: Codable, Equatable, Identifiable {
    var id: Int
    var type: String
    var title: String?
    var buildingNumber: String?
    var floor: String?
    var addressDetails: String?
    var landmark: String?
    var latitude: Double?
    var longitude: Double?
    var isDefault: Bool

    enum CodingKeys: String, CodingKey {
        case id, type, title, floor, landmark, latitude, longitude
        case buildingNumber = "building_number", addressDetails = "address_details", isDefault = "is_default"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        type = c.value(.type, "")
        title = c.value(.title, nil)
        buildingNumber = c.value(.buildingNumber, nil)
        floor = c.value(.floor, nil)
        addressDetails = c.value(.addressDetails, nil)
        landmark = c.value(.landmark, nil)
        latitude = c.value(.latitude, nil)
        longitude = c.value(.longitude, nil)
        isDefault = c.value(.isDefault, false)
    }
}

struct SaveAddressRequest: Encodable {
    var type: String
    var title: String?
    var buildingNumber: String?
    var floor: String?
    var addressDetails: String?
    var landmark: String?
    var latitude: Double?
    var longitude: Double?
    var isDefault: Bool = false

    enum CodingKeys: String, CodingKey {
        case type, title, floor, landmark, latitude, longitude
        case buildingNumber = "building_number", addressDetails = "address_details", isDefault = "is_default"
    }
}

struct SetDefaultRequest: Encodable {
    var isDefault: Bool
    enum CodingKeys: String, CodingKey { case isDefault = "is_default" }
}

/// androidApp `AddressApi.kt` — scoped to the token's user server-side.
enum AddressApi {
    static func list(search: String? = nil, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[AddressDto]> {
        try await ApiClient.get("mobile/v1/addresses", query: ["search": search, "page": String(page), "per_page": String(perPage)], auth: true)
    }

    static func show(_ id: Int) async throws -> ApiEnvelope<AddressDto> {
        try await ApiClient.get("mobile/v1/addresses/\(id)", auth: true)
    }

    static func store(_ body: SaveAddressRequest) async throws -> ApiEnvelope<AddressDto> {
        try await ApiClient.post("mobile/v1/addresses", body: body, auth: true)
    }

    static func update(_ id: Int, _ body: SaveAddressRequest) async throws -> ApiEnvelope<AddressDto> {
        try await ApiClient.put("mobile/v1/addresses/\(id)", body: body)
    }

    static func delete(_ id: Int) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.delete("mobile/v1/addresses/\(id)")
    }

    static func setDefault(_ id: Int, _ body: SetDefaultRequest) async throws -> ApiEnvelope<AddressDto> {
        try await ApiClient.patch("mobile/v1/addresses/\(id)/set-default", body: body)
    }
}

// MARK: - Notifications

struct NotificationDto: Codable, Equatable, Identifiable {
    var id: String
    var title: String
    var message: String
    /// e.g. "job_update" — falls back to event.
    var type: String?
    /// e.g. "wallet.transfer.received" — what the app keys its icon and deep link on.
    var event: String?
    var data: JSONAny?
    var createdAtIso: String?
    var readAt: String?

    enum CodingKeys: String, CodingKey {
        case id, title, message, type, event, data
        case createdAtIso = "created_at_iso", readAt = "read_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, "")
        title = c.value(.title, "")
        message = c.value(.message, "")
        type = c.value(.type, nil)
        event = c.value(.event, nil)
        data = c.value(.data, nil)
        createdAtIso = c.value(.createdAtIso, nil)
        readAt = c.value(.readAt, nil)
    }
}

extension NotificationApi {
    static func list(perPage: Int = 40, page: Int = 1) async throws -> ApiEnvelope<[NotificationDto]> {
        try await ApiClient.get("mobile/v1/notifications", query: ["per_page": String(perPage), "page": String(page)], auth: true)
    }

    static func markRead(_ id: String) async throws -> ApiEnvelope<UnreadCountDto> {
        try await ApiClient.send("mobile/v1/notifications/\(id)/read", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    static func markAllRead() async throws -> ApiEnvelope<UnreadCountDto> {
        try await ApiClient.send("mobile/v1/notifications/read-all", method: "POST", body: Optional<Empty>.none, auth: true)
    }
}

// MARK: - Security

struct SecurityDeviceDto: Codable, Equatable, Identifiable {
    var id: Int
    var deviceName: String?
    var platform: String?
    var platformLabel: String?
    var appVersion: String?
    var ip: String?
    var countryCode: String?
    var current: Bool
    var signedInAt: String?
    var lastActiveAt: String?

    enum CodingKeys: String, CodingKey {
        case id, platform, ip, current
        case deviceName = "device_name", platformLabel = "platform_label", appVersion = "app_version"
        case countryCode = "country_code", signedInAt = "signed_in_at", lastActiveAt = "last_active_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        deviceName = c.value(.deviceName, nil)
        platform = c.value(.platform, nil)
        platformLabel = c.value(.platformLabel, nil)
        appVersion = c.value(.appVersion, nil)
        ip = c.value(.ip, nil)
        countryCode = c.value(.countryCode, nil)
        current = c.value(.current, false)
        signedInAt = c.value(.signedInAt, nil)
        lastActiveAt = c.value(.lastActiveAt, nil)
    }
}

struct SignOutResultDto: Codable {
    var signedOutCurrent: Bool?
    var count: Int?
    var devices: [SecurityDeviceDto]?
    enum CodingKeys: String, CodingKey { case count, devices, signedOutCurrent = "signed_out_current" }
}

struct SecurityEventDto: Codable, Equatable, Identifiable {
    var id: Int
    var type: String
    var label: String
    var description: String?
    var warning: Bool
    var deviceName: String?
    var platformLabel: String?
    var ip: String?
    var countryCode: String?
    var createdAt: String?

    enum CodingKeys: String, CodingKey {
        case id, type, label, description, warning, ip
        case deviceName = "device_name", platformLabel = "platform_label", countryCode = "country_code", createdAt = "created_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        type = c.value(.type, "")
        label = c.value(.label, "")
        description = c.value(.description, nil)
        warning = c.value(.warning, false)
        deviceName = c.value(.deviceName, nil)
        platformLabel = c.value(.platformLabel, nil)
        ip = c.value(.ip, nil)
        countryCode = c.value(.countryCode, nil)
        createdAt = c.value(.createdAt, nil)
    }
}

/// androidApp `SecurityApi.kt` — the devices this account is signed in on, and its security log.
enum SecurityApi {
    static func devices() async throws -> ApiEnvelope<[SecurityDeviceDto]> {
        try await ApiClient.get("mobile/v1/security/devices", auth: true)
    }

    static func signOut(_ id: Int) async throws -> ApiEnvelope<SignOutResultDto> {
        try await ApiClient.delete("mobile/v1/security/devices/\(id)")
    }

    static func signOutOthers() async throws -> ApiEnvelope<SignOutResultDto> {
        try await ApiClient.send("mobile/v1/security/devices/logout-others", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    static func events(page: Int = 1, warnings: Int? = nil) async throws -> ApiEnvelope<[SecurityEventDto]> {
        try await ApiClient.get("mobile/v1/security/events", query: ["page": String(page), "warnings": warnings.map(String.init)], auth: true)
    }
}

// MARK: - Rating

struct SubmitRatingRequest: Encodable {
    var stars: Double
    var comment: String?
}

struct RatingDto: Codable, Equatable {
    var id: Int
    var stars: Double
    var comment: String?
    var type: String?
    /// True for 4–5 stars: the app may ask for the store review.
    var promptStoreReview: Bool

    enum CodingKeys: String, CodingKey { case id, stars, comment, type, promptStoreReview = "prompt_store_review" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        stars = c.value(.stars, 0)
        comment = c.value(.comment, nil)
        type = c.value(.type, nil)
        promptStoreReview = c.value(.promptStoreReview, false)
    }
}

struct MyRatingDto: Codable {
    var rated: Bool
    var rating: RatingDto?

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        rated = c.value(.rated, false)
        rating = c.value(.rating, nil)
    }
}

/// androidApp `RatingApi.kt` — one row per user: GET tells whether it exists, POST creates or updates it.
enum RatingApi {
    static func mine() async throws -> ApiEnvelope<MyRatingDto> {
        try await ApiClient.get("mobile/v1/ratings/mine", auth: true)
    }

    static func submit(_ body: SubmitRatingRequest) async throws -> ApiEnvelope<RatingDto> {
        try await ApiClient.post("mobile/v1/ratings", body: body, auth: true)
    }
}

// MARK: - Referrals

struct MyReferralCodeDto: Codable {
    var code: String?
    var isActive: Bool
    var applied: AppliedReferralDto?

    enum CodingKeys: String, CodingKey { case code, applied, isActive = "is_active" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        code = c.value(.code, nil)
        isActive = c.value(.isActive, true)
        applied = c.value(.applied, nil)
    }
}

struct AppliedReferralDto: Codable {
    var code: String?
    var status: String?
}

struct TrackReferralRequest: Encodable {
    var referralCode: String
    enum CodingKeys: String, CodingKey { case referralCode = "referral_code" }
}

struct TrackedReferralDto: Codable {
    var id: Int?
    var status: String?
}

/// androidApp `ReferralApi.kt`.
enum ReferralApi {
    static func myCode() async throws -> ApiEnvelope<MyReferralCodeDto> {
        try await ApiClient.get("mobile/v1/referrals/my-code", auth: true)
    }

    static func track(_ body: TrackReferralRequest) async throws -> ApiEnvelope<TrackedReferralDto> {
        try await ApiClient.post("mobile/v1/referrals/track", body: body, auth: true)
    }
}

// MARK: - Support

struct SupportLastMessageDto: Codable, Equatable {
    var sender: String?
    var body: String?
    var hasImage: Bool
    var createdAt: String?

    enum CodingKeys: String, CodingKey { case sender, body, hasImage = "has_image", createdAt = "created_at" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        sender = c.value(.sender, nil)
        body = c.value(.body, nil)
        hasImage = c.value(.hasImage, false)
        createdAt = c.value(.createdAt, nil)
    }
}

struct SupportTicketDto: Codable, Equatable, Identifiable {
    var id: Int
    /// The random number the customer and support quote (the id stays internal).
    var number: String?
    var title: String?
    var body: String?
    var imageUrl: String?
    /// `opened`, `reopened`, `resolved` or `closed`.
    var status: String?
    var acceptsReplies: Bool
    /// The customer said an automatic answer was not enough: no more automatic replies.
    var autoReplyStopped: Bool
    var lastMessage: SupportLastMessageDto?
    var lastMessageAt: String?
    var createdAt: String?

    /// What is shown as "#…": the ticket's number, or its id for an old payload without one.
    var displayNumber: String { number ?? String(id) }

    enum CodingKeys: String, CodingKey {
        case id, number, title, body, status
        case imageUrl = "image_url", acceptsReplies = "accepts_replies", autoReplyStopped = "auto_reply_stopped"
        case lastMessage = "last_message", lastMessageAt = "last_message_at", createdAt = "created_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        number = c.value(.number, nil)
        title = c.value(.title, nil)
        body = c.value(.body, nil)
        imageUrl = c.value(.imageUrl, nil)
        status = c.value(.status, nil)
        acceptsReplies = c.value(.acceptsReplies, true)
        autoReplyStopped = c.value(.autoReplyStopped, false)
        lastMessage = c.value(.lastMessage, nil)
        lastMessageAt = c.value(.lastMessageAt, nil)
        createdAt = c.value(.createdAt, nil)
    }
}

struct SupportMessageDto: Codable, Equatable, Identifiable {
    var id: Int
    var ticketId: Int?
    /// `user` (me), `support` or `system` (an automatic reply).
    var sender: String?
    var body: String?
    var imageUrl: String?
    var agentName: String?
    var isAuto: Bool
    /// `ack`, `away` or `faq`.
    var autoKind: String?
    var createdAt: String?

    enum CodingKeys: String, CodingKey {
        case id, sender, body
        case ticketId = "ticket_id", imageUrl = "image_url", agentName = "agent_name", isAuto = "is_auto"
        case autoKind = "auto_kind", createdAt = "created_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        ticketId = c.value(.ticketId, nil)
        sender = c.value(.sender, nil)
        body = c.value(.body, nil)
        imageUrl = c.value(.imageUrl, nil)
        agentName = c.value(.agentName, nil)
        isAuto = c.value(.isAuto, false)
        autoKind = c.value(.autoKind, nil)
        createdAt = c.value(.createdAt, nil)
    }
}

struct SupportStatusRequest: Encodable { var status: String }

struct SupportAutoReplyFeedbackRequest: Encodable { var solved: Bool }

struct SupportHelpDto: Codable {
    var greeting: String?
    var items: [SupportHelpNodeDto]

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        greeting = c.value(.greeting, nil)
        items = c.value(.items, [])
    }
}

/// A topic: with `children` it is a menu, without any it is an answer.
struct SupportHelpNodeDto: Codable, Equatable, Identifiable {
    var id: Int
    var title: String?
    var answer: String?
    var children: [SupportHelpNodeDto]

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        title = c.value(.title, nil)
        answer = c.value(.answer, nil)
        children = c.value(.children, [])
    }
}

/// androidApp `SupportApi.kt` — tickets, the conversation inside one, the guided help menu.
enum SupportApi {
    static func listTickets(page: Int = 1, perPage: Int = 20) async throws -> ApiEnvelope<[SupportTicketDto]> {
        try await ApiClient.get("mobile/v1/support-tickets", query: ["page": String(page), "per_page": String(perPage)], auth: true)
    }

    static func ticket(_ id: Int) async throws -> ApiEnvelope<SupportTicketDto> {
        try await ApiClient.get("mobile/v1/support-tickets/\(id)", auth: true)
    }

    static func createTicket(title: String, body: String, image: Data?) async throws -> ApiEnvelope<SupportTicketDto> {
        try await ApiClient.upload("mobile/v1/support-tickets", fields: ["title": title, "body": body],
                                   files: image.map { [ApiClient.FilePart(field: "image", filename: "image.jpg", mimeType: "image/jpeg", data: $0)] } ?? [])
    }

    static func setStatus(_ id: Int, _ body: SupportStatusRequest) async throws -> ApiEnvelope<SupportTicketDto> {
        try await ApiClient.patch("mobile/v1/support-tickets/\(id)/status", body: body)
    }

    /// The guided help menu (the whole active tree, in the customer's language).
    static func help() async throws -> ApiEnvelope<SupportHelpDto> {
        try await ApiClient.get("mobile/v1/support-help", auth: true)
    }

    /// What was pressed at the end of a help topic (counted on the dashboard).
    static func helpFeedback(_ id: Int, _ body: SupportAutoReplyFeedbackRequest) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.post("mobile/v1/support-help/\(id)/feedback", body: body, auth: true)
    }

    static func autoReplyFeedback(_ id: Int, _ body: SupportAutoReplyFeedbackRequest) async throws -> ApiEnvelope<SupportTicketDto> {
        try await ApiClient.post("mobile/v1/support-tickets/\(id)/auto-reply-feedback", body: body, auth: true)
    }

    /// Newest page first (`order=desc`): a long conversation opens at its end, older pages follow.
    static func messages(_ id: Int, page: Int = 1, perPage: Int = 30, order: String = "desc") async throws -> ApiEnvelope<[SupportMessageDto]> {
        try await ApiClient.get("mobile/v1/support-tickets/\(id)/messages", query: ["page": String(page), "per_page": String(perPage), "order": order], auth: true)
    }

    static func sendMessage(_ id: Int, body: String?, image: Data?) async throws -> ApiEnvelope<SupportMessageDto> {
        try await ApiClient.upload("mobile/v1/support-tickets/\(id)/messages", fields: body.map { ["body": $0] } ?? [:],
                                   files: image.map { [ApiClient.FilePart(field: "image", filename: "image.jpg", mimeType: "image/jpeg", data: $0)] } ?? [])
    }
}
