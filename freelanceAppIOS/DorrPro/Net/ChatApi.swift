import Foundation

// freelanceApp `net/ChatApi.kt`.

/// The talk with a client about an offer (FL-308), on the client-freelancer chat of FL-006 under `/api/freelance/v1/chat`. The client opens
/// the room from their side; I find it on my offer (`chat.uuid`) and answer in it. New messages arrive live (one reload per notice).
enum ChatApi {
    private static let base = Api.freelance

    static func room(_ uuid: String) async -> ApiResult<ChatRoomDto> { await Api.call("GET", base + "chat/conversations/\(uuid)") }

    /// Newest first; `before` is the id to continue from.
    static func messages(_ uuid: String, limit: Int = 40, before: Int? = nil) async -> ApiResult<[ChatMessageDto]> {
        await Api.call("GET", base + "chat/conversations/\(uuid)/messages", query: ["limit": String(limit), "before": before.map { String($0) }])
    }

    static func send(_ uuid: String, _ body: ChatSendRequest) async -> ApiResult<ChatMessageDto> {
        await Api.call("POST", base + "chat/conversations/\(uuid)/messages", body: body.json)
    }

    /// FL-310: the interviews of this room; propose a time; confirm / decline / cancel one.
    static func interviews(_ uuid: String) async -> ApiResult<[InterviewDto]> { await Api.call("GET", base + "chat/conversations/\(uuid)/interviews") }

    static func proposeInterview(_ uuid: String, _ body: InterviewProposeRequest) async -> ApiResult<[InterviewDto]> {
        await Api.call("POST", base + "chat/conversations/\(uuid)/interviews", body: body.json)
    }

    static func answerInterview(_ uuid: String, _ interview: String, _ action: String) async -> ApiResult<[InterviewDto]> {
        await Api.call("POST", base + "chat/conversations/\(uuid)/interviews/\(interview)/\(action)")
    }

    /// FL-709: report the other person's message.
    static func reportMessage(_ id: Int, _ body: ReportMessageRequest) async -> ApiResult<Ignored> {
        await Api.call("POST", base + "chat/messages/\(id)/report", body: body.json)
    }

    static func read(_ uuid: String) async -> ApiResult<Ignored> { await Api.call("POST", base + "chat/conversations/\(uuid)/read") }
}

struct ChatRoomDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
    var canWrite: Bool?
    /// Before the agreement phone numbers, emails and links are not allowed.
    var guarded: Bool?
    var peer: ChatPeerDto?
    var project: ChatProjectDto?
    var unread: Int?
}

struct ChatPeerDto: Decodable, Equatable {
    var name: String?
}

struct ChatProjectDto: Decodable, Equatable {
    var uuid: String?
    var title: String?
}

struct ChatMessageDto: Decodable, Equatable, Identifiable {
    var id: Int
    var mine: Bool?
    var kind: String?
    var body: String?
    var createdAt: String?
    var readAt: String?
}

struct ChatSendRequest {
    let body: String
    let clientId: String
    var json: JsonBody { ["body": body, "client_id": clientId] }
}

struct ReportMessageRequest {
    let reason: String
    var note: String?
    var json: JsonBody { agreementBody(["reason": reason, "note": note]) }
}

/// What my offer shows of the room (null: the client has not opened one).
struct OfferChatDto: Decodable, Equatable {
    var uuid: String?
    var unread: Int?
    var canWrite: Bool?
}

struct InterviewDto: Decodable, Equatable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    /// proposed, confirmed, declined, cancelled or superseded.
    var status: String?
    var proposedBy: String?
    var startsAt: String?
    var durationMinutes: Int?
    var note: String?
}

struct InterviewProposeRequest {
    let startsAt: String
    var durationMinutes: Int = 15
    var note: String?
    var json: JsonBody { agreementBody(["starts_at": startsAt, "duration_minutes": durationMinutes, "note": note]) }
}
