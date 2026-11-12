import Foundation

// androidApp `network/VoiceApi.kt` — standing voice rooms (spec 91): every group and channel has one. Join gives a
// LiveKit token (a listener's can't publish); hosts change who speaks live. `chat.voice.updated` tells the chat who's
// in it. Also a call broadcast live (spec 93).

enum VoiceApi {
    private static let base = "mobile/v1/chat"

    static func room(_ conversationId: String) async throws -> ApiEnvelope<VoiceRoomDto> { try await ApiClient.snake("\(base)/conversations/\(conversationId)/voice-room") }
    static func join(_ conversationId: String) async throws -> ApiEnvelope<VoiceJoinDto> {
        try await ApiClient.snake("\(base)/conversations/\(conversationId)/voice-room/join", method: "POST", body: [:])
    }
    static func leave(_ roomId: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/voice-rooms/\(roomId)/leave", method: "POST", body: [:]) }
    static func ping(_ roomId: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/voice-rooms/\(roomId)/ping", method: "POST", body: [:]) }
    static func hand(_ roomId: String, raised: Bool) async throws -> ApiEnvelope<VoiceRoomDto> {
        try await ApiClient.snake("\(base)/voice-rooms/\(roomId)/hand", method: "POST", body: ["raised": .bool(raised)])
    }
    /// `role`: speaker · listener — hosts.
    static func role(_ roomId: String, participant: Int, role: String) async throws -> ApiEnvelope<VoiceRoomDto> {
        try await ApiClient.snake("\(base)/voice-rooms/\(roomId)/members/\(participant)/role", method: "POST", body: ["role": .string(role)])
    }
    static func remove(_ roomId: String, participant: Int) async throws -> ApiEnvelope<VoiceRoomDto> {
        try await ApiClient.snake("\(base)/voice-rooms/\(roomId)/members/\(participant)/remove", method: "POST", body: [:])
    }
    /// `speak_policy`: everyone · approved — hosts.
    static func update(_ roomId: String, speakPolicy: String) async throws -> ApiEnvelope<VoiceRoomDto> {
        try await ApiClient.snake("\(base)/voice-rooms/\(roomId)", method: "PATCH", body: ["speak_policy": .string(speakPolicy)])
    }

    // A call broadcast live (spec 93)

    /// `audience`: members · link — someone in the call (a group's admin).
    static func startLive(_ callId: String, title: String?, audience: String) async throws -> ApiEnvelope<LiveDto> {
        var body: [String: JSONValue] = ["audience": .string(audience)]
        if let title { body["title"] = .string(title) }
        return try await ApiClient.snake("\(base)/calls/\(callId)/live", method: "POST", body: body)
    }
    static func endLive(_ liveId: String) async throws -> ApiEnvelope<LiveDto> { try await ApiClient.snake("\(base)/lives/\(liveId)/end", method: "POST", body: [:]) }
    static func watch(_ token: String) async throws -> ApiEnvelope<LiveWatchDto> { try await ApiClient.snake("\(base)/lives/watch/\(token)", method: "POST", body: [:]) }
    static func pingLive(_ token: String) async throws -> ApiEnvelope<LiveDto> { try await ApiClient.snake("\(base)/lives/watch/\(token)/ping", method: "POST", body: [:]) }
    static func shareLive(_ token: String, conversationIds: [String]) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("\(base)/lives/watch/\(token)/share", method: "POST", body: ["conversation_ids": .array(conversationIds.map { .string($0) })])
    }
}

struct LiveHostDto: Codable, Equatable {
    var name: String?
    var avatar: String?
}

/// `status`: live · ended. `audience`: members · link.
struct LiveDto: Codable, Equatable {
    var id: String = ""
    var token: String = ""
    var link: String?
    var title: String?
    var audience: String = "members"
    var status: String = "live"
    var host: LiveHostDto?
    var viewers: Int = 0
}

struct LiveWatchDto: Codable, Equatable {
    var live: LiveDto
    var join: JoinDto?
}

struct VoiceMemberDto: Codable, Equatable, Identifiable {
    var participantId: Int
    var key: String
    var name: String?
    var avatar: String?
    /// host · speaker · listener
    var role: String
    var handRaised: Bool?

    var id: String { key }
}

struct VoiceMeDto: Codable, Equatable {
    var inRoom: Bool?
    var role: String?
    var canManage: Bool?
}

struct VoiceRoomDto: Codable, Equatable {
    var id: String
    var conversationId: String
    var title: String?
    /// everyone · approved
    var speakPolicy: String?
    var count: Int?
    var members: [VoiceMemberDto]?
    var me: VoiceMeDto?
}

struct VoiceJoinDto: Codable, Equatable {
    var room: VoiceRoomDto
    var join: JoinDto
}
