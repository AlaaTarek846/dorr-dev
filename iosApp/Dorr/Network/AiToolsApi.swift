import Foundation

// androidApp `network/AiToolsApi.kt` (+ OrganizeApi's ask / commitments) — DORR AI tools in the chat (spec 31, 36–42, 46,
// 48, 49) and my tasks (38). Each call is one explicit tap; every free answer comes with `safety` (spec 350–362) when it
// applies.

struct MeaningSearchDto: Codable, Equatable {
    var terms: [String]?
    var messages: [MessageDto]?
    /// Message id → the word that found it.
    var matches: [String: String]?
}

struct ChatCapsuleDto: Codable, Equatable {
    var text: String?
    var messages: Int?
}

struct AiImageQuotaDto: Codable, Equatable {
    var allowed: Bool?
    /// no_plan · trial_ended · subscription_suspended · blocked · not_in_plan · daily_limit_reached
    var reason: String?
    /// nil = unlimited.
    var limit: Int?
    var used: Int?
    var remaining: Int?
    var styles: [String]?
}

struct AiImageDraftDto: Codable, Equatable {
    var draft: String
    var url: String
    /// generate · edit
    var kind: String?
}

/// DORR AI safety (spec 350–362) for one answer: its domain, whether it's a specific case, and the approved texts.
struct SafetyDto: Codable, Equatable {
    var domain: String
    var specific: Bool?
    var disclaimer: String?
    var notice: String?
}

/// DORR AI's quality gate on a free answer (spec 362): passed · unverified · abstained · skipped.
struct AiVerificationDto: Codable, Equatable {
    var status: String?
    var confidence: Double?
    var notice: String?
}

struct AskReferenceDto: Codable, Equatable, Identifiable {
    var id: String
    var sender: String?
    var excerpt: String?
    var createdAt: String?
}

struct AskAnswerDto: Codable, Equatable {
    var answer: String
    var references: [AskReferenceDto]?
    var messages: Int?
    var safety: SafetyDto?
    var verification: AiVerificationDto?
}

struct CommitmentDto: Codable, Equatable {
    var text: String
    /// The other person's name (nil when it's mine).
    var owner: String?
    var isMine: Bool?
    var dueAt: String?
    var messageId: String?
}

struct CommitmentsDto: Codable, Equatable {
    var commitments: [CommitmentDto]?
    var messages: Int?
}

struct AssistantAnswerDto: Codable, Equatable {
    var answer: String
    var safety: SafetyDto?
    var verification: AiVerificationDto?
    var references: [AskReferenceDto]?
}

struct ProofreadDto: Codable, Equatable {
    var text: String
    var changed: Bool?
}

struct UnderstandDto: Codable, Equatable {
    var intent: String?
    var tone: String?
    var summary: String?
    var toneNote: String?
    var actions: [String]?
    var replies: [String]?
    var safety: SafetyDto?
}

struct SimplifyDto: Codable, Equatable {
    var short: String?
    var points: [String]?
}

struct TaskSuggestionDto: Codable, Equatable {
    var text: String
    var dueAt: String?
}

struct TaskSuggestionsDto: Codable, Equatable {
    var tasks: [TaskSuggestionDto]?
}

struct TaskDto: Codable, Equatable, Identifiable {
    var id: String
    var text: String
    var dueAt: String?
    var done: Bool?
    var messageId: String?
    var conversationId: String?
}

struct DateFoundDto: Codable, Equatable {
    var title: String
    var at: String
    var allDay: Bool?
    var messageId: String?
}

struct DatesDto: Codable, Equatable {
    var dates: [DateFoundDto]?
    var messages: Int?
}

/// A message the AI pointed at (important / today), with where it lives.
struct AiMessageRefDto: Codable, Equatable {
    var messageId: String
    var conversationId: String?
    var chat: String?
    var sender: String?
    var excerpt: String?
    var createdAt: String?
    var level: String?
    var why: String?
    var text: String?
}

struct ImportantDto: Codable, Equatable {
    var items: [AiMessageRefDto]?
    var messages: Int?
    var chats: Int?
    var hasMore: Bool?
}

struct TodayDto: Codable, Equatable {
    var summary: [String]?
    var highlights: [AiMessageRefDto]?
    var messages: Int?
    var chats: Int?
}

struct RelatedFileDto: Codable, Equatable {
    var messageId: String
    var type: String
    var name: String?
    var url: String?
    var createdAt: String?
    var why: String?
}

struct RelatedFilesDto: Codable, Equatable {
    var files: [RelatedFileDto]?
}

enum AiToolsApi {
    private static let base = "mobile/v1/chat"
    private static var zone: JSONValue { .string(TimeZone.current.identifier) }

    /// DORR AI inside this chat, only for me: `{question, messages?[], history?[{q, a}]}`.
    static func assistant(_ conversationId: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<AssistantAnswerDto> {
        try await ApiClient.snake("\(base)/conversations/\(conversationId)/assistant", method: "POST", body: body)
    }
    /// The text I'm writing, spelling and grammar fixed.
    static func proofread(_ text: String) async throws -> ApiEnvelope<ProofreadDto> {
        try await ApiClient.snake("\(base)/ai/proofread", method: "POST", body: ["text": .string(text)])
    }
    static func understand(_ messageId: String) async throws -> ApiEnvelope<UnderstandDto> {
        try await ApiClient.snake("\(base)/messages/\(messageId)/understand", method: "POST", body: [:])
    }
    static func simplify(_ messageId: String) async throws -> ApiEnvelope<SimplifyDto> {
        try await ApiClient.snake("\(base)/messages/\(messageId)/simplify", method: "POST", body: [:])
    }
    /// Suggested tasks from a message (saved only with `createTasks`).
    static func tasksFrom(_ messageId: String) async throws -> ApiEnvelope<TaskSuggestionsDto> {
        try await ApiClient.snake("\(base)/messages/\(messageId)/tasks", method: "POST", body: ["timezone": zone])
    }
    /// A short note, saved into «Notes (you)».
    static func note(_ conversationId: String, messages: [String]) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("\(base)/conversations/\(conversationId)/note", method: "POST", body: ["messages": .array(messages.map { .string($0) })])
    }
    /// Dates mentioned in the chat (suggestions).
    static func dates(_ conversationId: String, messages: [String]? = nil) async throws -> ApiEnvelope<DatesDto> {
        var body: [String: JSONValue] = ["timezone": zone]
        if let messages { body["messages"] = .array(messages.map { .string($0) }) }
        return try await ApiClient.snake("\(base)/conversations/\(conversationId)/dates", method: "POST", body: body)
    }
    /// What deserves attention: in one chat, or across my unread chats.
    static func important(conversationId: String? = nil, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<ImportantDto> {
        var body: [String: JSONValue] = ["page": .int(page), "per_page": .int(perPage)]
        if let conversationId { body["conversation_id"] = .string(conversationId) }
        return try await ApiClient.snake("\(base)/ai/important", method: "POST", body: body)
    }
    static func relatedFiles(_ conversationId: String) async throws -> ApiEnvelope<RelatedFilesDto> {
        try await ApiClient.snake("\(base)/conversations/\(conversationId)/related-files", method: "POST", body: [:])
    }
    /// Today across my chats.
    static func today() async throws -> ApiEnvelope<TodayDto> {
        try await ApiClient.snake("\(base)/ai/today", method: "POST", body: ["timezone": zone])
    }

    static func tasks(status: String? = nil, page: Int? = nil, perPage: Int? = nil) async throws -> ApiEnvelope<[TaskDto]> {
        try await ApiClient.snake("\(base)/tasks", query: ["status": status, "page": page.map(String.init), "per_page": perPage.map(String.init)])
    }
    /// `{tasks: [{text, due_at?}], message_id?}`
    static func createTasks(_ tasks: [TaskSuggestionDto], messageId: String?) async throws -> ApiEnvelope<[TaskDto]> {
        var body: [String: JSONValue] = ["tasks": .array(tasks.map { t in .object(["text": .string(t.text), "due_at": t.dueAt.map { .string($0) } ?? .null]) })]
        if let messageId { body["message_id"] = .string(messageId) }
        return try await ApiClient.snake("\(base)/tasks", method: "POST", body: body)
    }
    /// `{text?, due_at?, done?}`
    static func updateTask(_ id: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<TaskDto> {
        try await ApiClient.snake("\(base)/tasks/\(id)", method: "PATCH", body: body)
    }
    static func deleteTask(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/tasks/\(id)", method: "DELETE") }

    // Search by meaning, continue elsewhere, the AI images gallery.

    static func searchByMeaning(_ conversationId: String, q: String) async throws -> ApiEnvelope<MeaningSearchDto> {
        try await ApiClient.snake("\(base)/conversations/\(conversationId)/ai/search", query: ["q": q])
    }
    static func capsule(_ conversationId: String, messages: [String]? = nil) async throws -> ApiEnvelope<ChatCapsuleDto> {
        try await ApiClient.snake("\(base)/conversations/\(conversationId)/ai/capsule", method: "POST", body: messages.map { ["messages": .array($0.map { .string($0) })] } ?? [:])
    }
    static func aiImages() async throws -> ApiEnvelope<[MessageDto]> { try await ApiClient.snake("\(base)/ai/images") }

    // Images with DORR AI (spec 50, 51): a draft only I see, then sent as an ordinary image marked `meta.ai_generated`.

    static func imageQuota() async throws -> ApiEnvelope<AiImageQuotaDto> { try await ApiClient.snake("\(base)/ai/image/quota") }
    static func generateImage(_ conversationId: String, prompt: String, style: String?) async throws -> ApiEnvelope<AiImageDraftDto> {
        var body: [String: JSONValue] = ["prompt": .string(prompt)]
        if let style { body["style"] = .string(style) }
        return try await ApiClient.snake("\(base)/conversations/\(conversationId)/ai/image", method: "POST", body: body)
    }
    static func editImage(_ messageId: String, prompt: String) async throws -> ApiEnvelope<AiImageDraftDto> {
        try await ApiClient.snake("\(base)/messages/\(messageId)/ai/edit-image", method: "POST", body: ["prompt": .string(prompt)])
    }
    static func sendImage(_ conversationId: String, draft: String, body text: String?, uuid: String?) async throws -> ApiEnvelope<MessageDto> {
        var body: [String: JSONValue] = ["draft": .string(draft)]
        if let text { body["body"] = .string(text) }
        if let uuid { body["uuid"] = .string(uuid) }
        return try await ApiClient.snake("\(base)/conversations/\(conversationId)/ai/image/send", method: "POST", body: body)
    }

    // About a chat (OrganizeApi on Android).

    /// `{question, messages?[]}` — only the picked messages (or the latest) go to the AI.
    static func ask(_ conversationId: String, question: String, messages: [String]?) async throws -> ApiEnvelope<AskAnswerDto> {
        var body: [String: JSONValue] = ["question": .string(question)]
        if let messages, !messages.isEmpty { body["messages"] = .array(messages.map { .string($0) }) }
        return try await ApiClient.snake("\(base)/conversations/\(conversationId)/ask", method: "POST", body: body)
    }
    /// Suggestions only.
    static func commitments(_ conversationId: String) async throws -> ApiEnvelope<CommitmentsDto> {
        try await ApiClient.snake("\(base)/conversations/\(conversationId)/commitments", method: "POST", body: ["timezone": zone])
    }
}
