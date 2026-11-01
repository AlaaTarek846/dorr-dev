import Foundation

// androidApp `network/BacklogApi.kt` — manage storage (WhatsApp list 46–48): my chats' files, biggest first; the large
// ones, the forwarded ones, all, or one chat's; deleted for me only. (Export, click-to-chat and resharing a status live
// in `ChatApi`.)

enum BacklogApi {
    private static let base = "mobile/v1/chat"

    static func storage() async throws -> ApiEnvelope<StorageDto> { try await ApiClient.snake("\(base)/storage") }

    /// `{reactions: [..] | null}` — null: any emoji, []: none.
    static func channelReactions(_ conversationId: String, _ reactions: [String]?) async throws -> ApiEnvelope<ConversationDto> {
        try await ApiClient.snake("\(base)/channels/\(conversationId)/reactions", method: "PATCH", body: ["reactions": reactions.map { .array($0.map { .string($0) }) } ?? .null])
    }

    /// `kind` all · large · forwarded. Pages of 15.
    static func storageFiles(kind: String, conversationId: String?, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<StorageFilesDto> {
        try await ApiClient.snake("\(base)/storage/files", query: ["kind": kind, "conversation_id": conversationId, "page": "\(page)", "per_page": "\(perPage)"])
    }

    /// A channel's followers, posts, views and reach over `days`, with its top posts.
    static func channelStats(_ conversationId: String, days: Int) async throws -> ApiEnvelope<ChannelStatsDto> {
        try await ApiClient.snake("\(base)/channels/\(conversationId)/stats", query: ["days": String(days)])
    }

    /// My messages to customers and theirs to me over `days` (WhatsApp Business's statistics, backlog 116).
    static func businessStats(days: Int) async throws -> ApiEnvelope<BusinessStatsDto> {
        try await ApiClient.snake("\(base)/business/stats", query: ["days": String(days)])
    }

    /// Deleted for me only.
    static func deleteStorage(_ messageIds: [String]) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("\(base)/storage/delete", method: "POST", body: ["messages": .array(messageIds.map { .string($0) })])
    }
}

struct ChannelTopPostDto: Codable, Equatable {
    var id: String?
    var type: String?
    var preview: String?
    var views: Int?
    var createdAt: String?
}

struct ChannelStatsDto: Codable, Equatable {
    var days: Int?
    var followers: Int?
    var joined: Int?
    var left: Int?
    var posts: Int?
    var views: Int?
    var averageViews: Int?
    var reachPercent: Int?
    var topPosts: [ChannelTopPostDto]?
}

struct BusinessStatsDto: Codable, Equatable {
    var days: Int?
    var sent: Int?
    var delivered: Int?
    var read: Int?
    var received: Int?
    var chats: Int?
}

struct StorageChatDto: Codable, Equatable {
    var conversationId: String = ""
    var title: String?
    var type: String?
    var bytes: Int64?
    var files: Int?
}

struct StorageDto: Codable, Equatable {
    var totalBytes: Int64?
    var files: Int?
    var large: Int?
    var forwarded: Int?
    var chats: [StorageChatDto]?
}

struct StorageFileDto: Codable, Equatable {
    var messageId: String = ""
    var conversationId: String?
    var chat: String?
    var type: String?
    var bytes: Int64?
    var files: Int?
    var name: String?
    var mimeType: String?
    var thumbnail: String?
    var forwarded: Bool?
    var createdAt: String?
}

struct StorageFilesDto: Codable, Equatable {
    var items: [StorageFileDto]?
    var hasMore: Bool?
}
