import Foundation

// androidApp `network/ChatMoreApi.kt` — the chat items that were partial (docs/remaining_chat.md): favourites folders,
// a PIN for one chat, @usernames, «what I missed», money in a chat, the decision room and the privacy center.

struct StarFolderDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String
    var emoji: String?
    var color: String?
    var count: Int?
}

struct StarFoldersDto: Codable, Equatable {
    var unsortedCount: Int?
    var folders: [StarFolderDto]?
}

struct UsernameDto: Codable, Equatable {
    var username: String?
}

/// A message an overview points at, with where it lives.
struct MsgRefDto: Codable, Equatable {
    var messageId: String
    var conversationId: String?
    var chat: String?
    var sender: String?
    var excerpt: String?
    var note: String?
    var createdAt: String?
}

struct MissedCallDto: Codable, Equatable, Identifiable {
    var id: String
    var type: String
    var conversationId: String?
    var chat: String?
    var caller: String?
    var createdAt: String?
}

struct OpenDecisionDto: Codable, Equatable, Identifiable {
    var id: String
    var title: String
    var conversationId: String?
    var chat: String?
    var deadlineAt: String?
}

struct UnreadChatDto: Codable, Equatable {
    var conversationId: String?
    var chat: String?
    var unread: Int?
    var mention: Bool?
}

struct CatchUpDto: Codable, Equatable {
    var since: String?
    var mentions: [MsgRefDto]?
    var replies: [MsgRefDto]?
    var urgent: [MsgRefDto]?
    var owed: [MsgRefDto]?
    var missedCalls: [MissedCallDto]?
    var reminders: [MsgRefDto]?
    var decisions: [OpenDecisionDto]?
    var unreadChats: [UnreadChatDto]?
    var hasMore: Bool?
}

struct MoneyItemDto: Codable, Equatable {
    var messageId: String
    var type: String
    var amountMinor: Int64?
    var currency: String?
    var status: String?
    var isMine: Bool?
    var note: String?
    var createdAt: String?
}

struct MoneyTotalsDto: Codable, Equatable {
    var sentMinor: Int64?
    var receivedMinor: Int64?
    var iOweMinor: Int64?
    var owedToMeMinor: Int64?
    var currency: String?
}

struct ChatMoneyDto: Codable, Equatable {
    var items: [MoneyItemDto]?
    var totals: MoneyTotalsDto?
    var hasMore: Bool?
}

/// The privacy center at a glance (map keys come back camel-cased by the snake decoder).
struct PrivacyCenterDto: Codable, Equatable {
    var whoSees: [String: JSONAny]?
    var whoCan: [String: String]?
    var notifications: String?
    var privacyMode: [String: Bool]?
    var quiet: [String: Bool]?
    var blockScreenshots: Bool?
    var circles: [String: Int]?
    var lockedChats: Int?
    var pinLockedChats: Int?
    var blocked: Int?
    var username: String?
    var ai: [String: JSONAny]?
}

struct DecisionOptionDto: Codable, Equatable, Identifiable {
    var id: Int
    var text: String
    var votes: Int?
}

struct DecisionArgumentDto: Codable, Equatable, Identifiable {
    var id: Int
    var stance: String
    var optionId: String?
    var text: String
    var by: ProfileDto?
    var isMine: Bool?
    var createdAt: String?
}

struct DecisionRoomDto: Codable, Equatable {
    var id: String
    var title: String
    var status: String
    var outcome: String?
    var description: String?
    var deadlineAt: String?
    var isClosed: Bool?
    var canDecide: Bool?
    var pollMessageId: String?
    var options: [DecisionOptionDto]?
    var voters: Int?
    var createdBy: ProfileDto?
    var arguments: [DecisionArgumentDto]?
}

enum ChatMoreApi {
    private static let base = "mobile/v1/chat"

    static func starFolders() async throws -> ApiEnvelope<StarFoldersDto> { try await ApiClient.snake("\(base)/star-folders") }
    static func createStarFolder(_ body: [String: JSONValue]) async throws -> ApiEnvelope<StarFolderDto> { try await ApiClient.snake("\(base)/star-folders", method: "POST", body: body) }
    static func updateStarFolder(_ id: Int, _ body: [String: JSONValue]) async throws -> ApiEnvelope<StarFolderDto> {
        try await ApiClient.snake("\(base)/star-folders/\(id)", method: "PATCH", body: body)
    }
    static func deleteStarFolder(_ id: Int) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/star-folders/\(id)", method: "DELETE") }
    /// `folder` = a folder id, or "none" (starred in no folder). Pages of 15.
    static func starred(folder: String? = nil, q: String? = nil, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[MessageDto]> {
        try await ApiClient.snake("\(base)/messages/starred", query: ["folder": folder, "q": q, "page": String(page), "per_page": String(perPage)])
    }
    /// `{pin, current_pin?}` — 4 digits.
    static func setLockPin(_ id: String, pin: String, currentPin: String?) async throws -> ApiEnvelope<JSONAny?> {
        var body: [String: JSONValue] = ["pin": .string(pin)]
        if let currentPin { body["current_pin"] = .string(currentPin) }
        return try await ApiClient.snake("\(base)/conversations/\(id)/lock-pin", method: "PUT", body: body)
    }
    static func removeLockPin(_ id: String, pin: String) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("\(base)/conversations/\(id)/lock-pin", method: "DELETE", body: ["pin": .string(pin)])
    }
    static func unlock(_ id: String, pin: String) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("\(base)/conversations/\(id)/unlock", method: "POST", body: ["pin": .string(pin)])
    }
    static func username() async throws -> ApiEnvelope<UsernameDto> { try await ApiClient.snake("\(base)/username") }
    /// nil removes it.
    static func setUsername(_ username: String?) async throws -> ApiEnvelope<UsernameDto> {
        try await ApiClient.snake("\(base)/username", method: "PUT", body: ["username": username.map { .string($0) } ?? .null])
    }
    static func byUsername(_ u: String) async throws -> ApiEnvelope<ProfileDto> { try await ApiClient.snake("\(base)/users/by-username", query: ["u": u]) }
    static func catchUp(since: String? = nil, q: String? = nil, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<CatchUpDto> {
        try await ApiClient.snake("\(base)/catch-up", query: ["since": since, "q": q, "page": String(page), "per_page": String(perPage)])
    }
    static func money(_ id: String, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<ChatMoneyDto> {
        try await ApiClient.snake("\(base)/conversations/\(id)/money", query: ["page": String(page), "per_page": String(perPage)])
    }
    static func privacyCenter() async throws -> ApiEnvelope<PrivacyCenterDto> { try await ApiClient.snake("\(base)/privacy/center") }
    static func decisionRoom(_ id: String) async throws -> ApiEnvelope<DecisionRoomDto> { try await ApiClient.snake("\(base)/decisions/\(id)") }
    /// `{stance: pro|con|note, text, option_id?}`
    static func argue(_ id: String, stance: String, text: String, optionId: String?) async throws -> ApiEnvelope<DecisionRoomDto> {
        var body: [String: JSONValue] = ["stance": .string(stance), "text": .string(text)]
        if let optionId { body["option_id"] = .string(optionId) }
        return try await ApiClient.snake("\(base)/decisions/\(id)/arguments", method: "POST", body: body)
    }
    static func removeArgument(_ id: String, argument: Int) async throws -> ApiEnvelope<DecisionRoomDto> {
        try await ApiClient.snake("\(base)/decisions/\(id)/arguments/\(argument)", method: "DELETE")
    }
}
