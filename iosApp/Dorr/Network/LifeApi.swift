import Foundation

// androidApp `network/LifeApi.kt` — daily intelligence in the chat, batch A (spec 146–155): commitments, handoffs and
// agreements (each made from a message or in a chat, with its card there — the card's `meta` is the same shape as
// these answers, sent again on every change), my communication rules and contexts, and the life inbox.

struct LifePersonDto: Codable, Equatable {
    var participantId: Int?
    var key: String?
    var name: String?
    var avatar: String?
}

struct LifeSourceDto: Codable, Equatable {
    var id: String?
    var type: String?
    var preview: String?
}

struct LifeConversationDto: Codable, Equatable {
    var id: String?
    var type: String?
    var name: String?
}

struct LifeLogDto: Codable, Equatable {
    var action: String?
    var by: LifePersonDto?
    var data: JSONAny?
    var at: String?
}

/// `status`: proposed · accepted · declined · done · cancelled
struct LifeCommitmentDto: Codable, Equatable, Identifiable {
    var id: String
    var title: String?
    var status: String?
    var dueAt: String?
    var remindMinutes: Int?
    var by: LifePersonDto?
    var assignee: LifePersonDto?
    var source: LifeSourceDto?
    var doneAt: String?
    var conversation: LifeConversationDto?
    var log: [LifeLogDto]?
}

/// `status`: pending · accepted · declined · done · cancelled
struct HandoffDto: Codable, Equatable, Identifiable {
    var id: String
    var note: String?
    var status: String?
    var from: LifePersonDto?
    var to: LifePersonDto?
    var source: LifeSourceDto?
    var doneAt: String?
    var conversation: LifeConversationDto?
}

struct AgreementTermDto: Codable, Equatable {
    var label: String
    var value: String
}

struct AgreementPartyDto: Codable, Equatable {
    var participantId: Int?
    var key: String?
    var name: String?
    var avatar: String?
    /// accepted · declined · nil (not yet) — for this version
    var decision: String?
    var at: String?
}

/// `status`: proposed · agreed · declined · cancelled. A record only: nothing is paid or booked.
struct AgreementDto: Codable, Equatable, Identifiable {
    var id: String
    var title: String?
    var terms: [AgreementTermDto]?
    var version: Int?
    var status: String?
    var by: LifePersonDto?
    var parties: [AgreementPartyDto]?
    var source: LifeSourceDto?
    var agreedAt: String?
    var log: [LifeLogDto]?
}

/// One thing to do. `kind`: needs_reply · mention · money_request · bill_split · commitment · handoff · agreement ·
/// join_request · decision · my_commitment · my_handoff · waiting_for · appointment · task.
struct LifeItemDto: Codable, Equatable {
    var kind: String?
    var id: String?
    var title: String?
    var by: String?
    var dueAt: String?
    var amountMinor: Int64?
    var currency: String?
    var messageId: String?
    var conversationId: String?
    var chat: String?
    var at: String?
    var late: Bool?
    var status: String?
    var version: Int?
    var allDay: Bool?
    var date: String?
    var location: String?
}

struct LifeInboxCountsDto: Codable, Equatable {
    var reply: Int?
    var pay: Int?
    var approve: Int?
    var follow: Int?
    var appointments: Int?
    var messageRequests: Int?
}

struct LifeInboxDto: Codable, Equatable {
    var reply: [LifeItemDto]?
    var pay: [LifeItemDto]?
    var approve: [LifeItemDto]?
    var follow: [LifeItemDto]?
    var appointments: [LifeItemDto]?
    var counts: LifeInboxCountsDto?
    var messageRequests: Int?
    var total: Int?
}

struct ActiveContextDto: Codable, Equatable {
    var key: String?
    var until: String?
}

/// A context's effect: `disclosure` all · name · none · nil (as usual), `calls` everyone · through · nobody.
struct ContextDto: Codable, Equatable {
    var key: String
    var attention: [String: String]?
    var disclosure: String?
    var calls: String?
    var custom: Bool?
}

struct ThroughDto: Codable, Equatable {
    var key: String
    var name: String?
    var avatar: String?
}

struct CallHoursDto: Codable, Equatable {
    var from: String?
    var to: String?
    var days: [Int]?
    var timezone: String?
}

struct QuietStateDto: Codable, Equatable {
    var on: Bool?
    var scope: String?
}

struct AttentionDto: Codable, Equatable {
    /// source (contacts · strangers · groups · mentions · channels · money · urgent) → now · digest · later
    var attention: [String: String]?
    var digestMinutes: Int?
    var context: ActiveContextDto?
    var contexts: [ContextDto]?
    var alwaysThrough: [ThroughDto]?
    var callHours: CallHoursDto?
    var whoCanCall: String?
    var quiet: QuietStateDto?
    var sources: [String]?
}

enum LifeApi {
    private static let base = "mobile/v1/chat"

    /// `{title?, assignee? (participant id), due_at?, remind_minutes?}`
    static func commitFromMessage(_ messageId: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<LifeCommitmentDto> {
        try await ApiClient.snake("\(base)/messages/\(messageId)/commitment", method: "POST", body: body)
    }
    static func commit(_ conversationId: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<LifeCommitmentDto> {
        try await ApiClient.snake("\(base)/conversations/\(conversationId)/commitment", method: "POST", body: body)
    }
    /// `scope`: mine · theirs · all. Pages of 15.
    static func commitments(scope: String, page: Int = 1, perPage: Int = 15, q: String? = nil) async throws -> ApiEnvelope<[LifeCommitmentDto]> {
        try await ApiClient.snake("\(base)/commitments", query: ["scope": scope, "open": "1", "page": String(page), "per_page": String(perPage), "q": q])
    }
    static func respond(_ id: String, accept: Bool) async throws -> ApiEnvelope<LifeCommitmentDto> {
        try await ApiClient.snake("\(base)/commitments/\(id)/respond", method: "POST", body: ["accept": .bool(accept)])
    }
    static func done(_ id: String) async throws -> ApiEnvelope<LifeCommitmentDto> { try await ApiClient.snake("\(base)/commitments/\(id)/done", method: "POST", body: [:]) }
    static func cancel(_ id: String) async throws -> ApiEnvelope<LifeCommitmentDto> { try await ApiClient.snake("\(base)/commitments/\(id)/cancel", method: "POST", body: [:]) }

    /// `{to_participant_id}` (a member of the chat) or `{to_user_id}`, `note?`
    static func handOff(_ messageId: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<HandoffDto> {
        try await ApiClient.snake("\(base)/messages/\(messageId)/handoff", method: "POST", body: body)
    }
    /// `action`: accept · decline · done · cancel
    static func moveHandoff(_ id: String, action: String) async throws -> ApiEnvelope<HandoffDto> {
        try await ApiClient.snake("\(base)/handoffs/\(id)/\(action)", method: "POST", body: [:])
    }
    /// `scope`: received · sent. Pages of 15.
    static func handoffs(scope: String, page: Int = 1, perPage: Int = 15, q: String? = nil) async throws -> ApiEnvelope<[HandoffDto]> {
        try await ApiClient.snake("\(base)/handoffs", query: ["scope": scope, "open": "1", "page": String(page), "per_page": String(perPage), "q": q])
    }

    /// `{title, terms[{label, value}], parties?[], source? (message id)}`
    static func propose(_ conversationId: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<AgreementDto> {
        try await ApiClient.snake("\(base)/conversations/\(conversationId)/agreements", method: "POST", body: body)
    }
    static func agreement(_ id: String) async throws -> ApiEnvelope<AgreementDto> { try await ApiClient.snake("\(base)/agreements/\(id)") }
    static func answer(_ id: String, accept: Bool, version: Int) async throws -> ApiEnvelope<AgreementDto> {
        try await ApiClient.snake("\(base)/agreements/\(id)/answer", method: "POST", body: ["accept": .bool(accept), "version": .int(version)])
    }
    /// A new version; everyone answers again.
    static func amend(_ id: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<AgreementDto> {
        try await ApiClient.snake("\(base)/agreements/\(id)", method: "PUT", body: body)
    }
    static func cancelAgreement(_ id: String) async throws -> ApiEnvelope<AgreementDto> { try await ApiClient.snake("\(base)/agreements/\(id)/cancel", method: "POST", body: [:]) }

    // My communication rules (146, 151, 155)

    static func attention() async throws -> ApiEnvelope<AttentionDto> { try await ApiClient.snake("\(base)/attention") }
    static func saveAttention(_ body: [String: JSONValue]) async throws -> ApiEnvelope<AttentionDto> {
        try await ApiClient.snake("\(base)/attention", method: "PUT", body: body)
    }
    /// `{context: home|work|meeting|travel|private|null, minutes?}`
    static func setContext(_ key: String?, minutes: Int? = nil) async throws -> ApiEnvelope<AttentionDto> {
        var body: [String: JSONValue] = ["context": key.map { .string($0) } ?? .null]
        if let minutes { body["minutes"] = .int(minutes) }
        return try await ApiClient.snake("\(base)/attention/context", method: "PUT", body: body)
    }

    /// What needs something from me (spec 147): reply · pay · approve · follow · appointments. Pages of 15.
    static func lifeInbox(section: String? = nil, page: Int = 1, perPage: Int = 15, q: String? = nil) async throws -> ApiEnvelope<LifeInboxDto> {
        try await ApiClient.snake("\(base)/life-inbox", query: [
            "timezone": TimeZone.current.identifier, "section": section, "page": String(page), "per_page": String(perPage), "q": q,
        ])
    }
}
