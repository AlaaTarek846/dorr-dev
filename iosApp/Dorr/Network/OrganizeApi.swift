import Foundation

// androidApp `network/OrganizeApi.kt` — organising chats: privacy circles (spec 98–103), threads (122), group
// decisions (119–120) and broadcast lists (like WhatsApp's). The AI questions about a chat (ask / commitments) join
// with the AI tools.

struct CircleDto: Codable, Equatable, Identifiable {
    var id: String
    var name: String
    var maskedName: String?
    var shownName: String?
    var emoji: String?
    var color: String?
    /// all · name · circle · none · hidden (P0 … P4)
    var disclosure: String?
    var hideFromList: Bool?
    var locked: Bool?
    var chatsCount: Int?
    var unread: Int?
}

struct ThreadPageDto: Codable, Equatable {
    var root: MessageDto?
    var messages: [MessageDto]?
}

struct DecisionDto: Codable, Equatable, Identifiable {
    var id: String
    var title: String
    /// open · approved · rejected
    var status: String
    var outcome: String?
    var sourceMessageId: String?
    var pollMessageId: String?
    var options: [DecisionOptionDto]?
    var voters: Int?
    var createdBy: ProfileDto?
    var decidedBy: ProfileDto?
    var createdAt: String?
    var decidedAt: String?
}

struct BroadcastSentDto: Codable, Equatable, Identifiable {
    var id: Int
    var type: String
    var body: String?
    var thumbnail: String?
    var sentCount: Int?
    var skippedCount: Int?
    var createdAt: String?
}

struct BroadcastLastDto: Codable, Equatable {
    var body: String?
    var type: String?
    var at: String?
}

struct BroadcastDto: Codable, Equatable, Identifiable {
    var id: String
    var name: String?
    var title: String
    var membersCount: Int?
    var lastSent: BroadcastLastDto?
    var members: [ProfileDto]?
    var sent: [BroadcastSentDto]?
}

struct BroadcastResultDto: Codable, Equatable {
    var sent: Int
    var skipped: [ProfileDto]?
    var broadcast: BroadcastSentDto?
}

enum OrganizeApi {
    private static let base = "mobile/v1/chat"

    // Circles

    static func circles() async throws -> ApiEnvelope<[CircleDto]> { try await ApiClient.snake("\(base)/circles") }
    static func createCircle(_ body: [String: JSONValue]) async throws -> ApiEnvelope<CircleDto> { try await ApiClient.snake("\(base)/circles", method: "POST", body: body) }
    static func updateCircle(_ id: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<CircleDto> {
        try await ApiClient.snake("\(base)/circles/\(id)", method: "PATCH", body: body)
    }
    static func deleteCircle(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/circles/\(id)", method: "DELETE") }
    /// `circle_id` nil takes the chat out of its circle.
    static func setCircle(_ conversationId: String, circleId: String?) async throws -> ApiEnvelope<ConversationDto> {
        try await ApiClient.snake("\(base)/conversations/\(conversationId)/circle", method: "PUT", body: ["circle_id": circleId.map { .string($0) } ?? .null])
    }

    // Threads

    static func thread(_ conversationId: String, root: String, limit: Int = 100) async throws -> ApiEnvelope<ThreadPageDto> {
        try await ApiClient.snake("\(base)/conversations/\(conversationId)/messages", query: ["thread": root, "limit": String(limit)])
    }

    // Decisions

    static func makeDecision(_ messageId: String) async throws -> ApiEnvelope<DecisionDto> {
        try await ApiClient.snake("\(base)/messages/\(messageId)/decision", method: "POST", body: [:])
    }
    static func decide(_ id: String, approve: Bool) async throws -> ApiEnvelope<DecisionDto> {
        try await ApiClient.snake("\(base)/decisions/\(id)/decide", method: "POST", body: ["approve": .bool(approve)])
    }
    static func decisions(_ conversationId: String, status: String? = nil) async throws -> ApiEnvelope<[DecisionDto]> {
        try await ApiClient.snake("\(base)/groups/\(conversationId)/decisions", query: ["status": status])
    }

    // Broadcasts

    static func broadcasts() async throws -> ApiEnvelope<[BroadcastDto]> { try await ApiClient.snake("\(base)/broadcasts") }
    static func createBroadcast(members: [Int]) async throws -> ApiEnvelope<BroadcastDto> {
        try await ApiClient.snake("\(base)/broadcasts", method: "POST", body: ["members": .array(members.map { .int($0) })])
    }
    static func broadcast(_ id: String) async throws -> ApiEnvelope<BroadcastDto> { try await ApiClient.snake("\(base)/broadcasts/\(id)") }
    static func updateBroadcast(_ id: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<BroadcastDto> {
        try await ApiClient.snake("\(base)/broadcasts/\(id)", method: "PATCH", body: body)
    }
    static func deleteBroadcast(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/broadcasts/\(id)", method: "DELETE") }
    static func sendBroadcast(_ id: String, fields: [String: String], files: [ChatFile]) async throws -> ApiEnvelope<BroadcastResultDto> {
        let data = try await ApiClient.uploadRaw("\(base)/broadcasts/\(id)/messages", fields: fields, files: files, headers: [:])
        return try ApiClient.snakeDecoder.decode(ApiEnvelope<BroadcastResultDto>.self, from: data)
    }
    /// A list from one of my folders (labels) — backlog 129.
    static func broadcastFromFolder(_ folderId: Int) async throws -> ApiEnvelope<BroadcastDto> {
        try await ApiClient.snake("\(base)/broadcasts/from-folder", method: "POST", body: ["folder_id": .int(folderId)])
    }
}
