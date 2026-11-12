import Foundation

// androidApp `network/AiChatApi.kt`, `AiChatModels.kt`, `AiAssistantApi.kt` — DORR AI under /api/user/v1 (Modules/AI):
// status, conversations, messages (always multipart, a fresh Idempotency-Key per tap), the conversation's files, the
// realtime voice session, the wallet-paid subscription, the reply language, and the assistant's own tools (memory,
// search, outputs, continue with a Context Capsule, projects). Read with `ApiClient.snake`; Android's data-class
// defaults are the `?? …` where they are read.

// MARK: - Chat

struct AiStatusDto: Codable, Equatable {
    var available: Bool?
    var brandName: String?
    var fileLimits: AiFileLimitsDto?
    /// The person's plan, read-only — the DORR AI home's plan chip.
    var plan: AiPlanPreviewDto?
}

struct AiPlanPreviewDto: Codable, Equatable {
    var allowed: Bool?
    var reason: String?
    var planName: String?
    var isTrial: Bool?
}

struct AiFileLimitsDto: Codable, Equatable {
    var maxSizeBytes: Int64?
    var allowedMimeTypes: [String]?
    var maxConversationFiles: Int?
}

struct AiUsageDto: Codable, Equatable {
    var allowed: Bool?
    var reason: String?
    var planName: String?
    var planIsTrial: Bool?
    var trialStatus: String?
    var remainingSeconds: Int64?
    var cooldownSecondsLeft: Int64?
}

struct AiConversationDto: Codable, Equatable, Identifiable {
    var id: Int
    var title: String?
    var providerKey: String?
    var messagesCount: Int?
    var lastMessagePreview: String?
    var messages: [AiMessageDto]?
    var createdAt: String?
    var updatedAt: String?
}

struct AiMessageDto: Codable, Equatable, Identifiable {
    var id: Int
    /// "user" | "assistant".
    var role: String
    var content: String
    var isError: Bool?
    var model: String?
    var providerKey: String?
    var attachments: [AiAttachmentDto]?
    var generatedFile: AiGeneratedFileDto?
    var confidenceScore: Double?
    var verificationWarnings: [String]?
    var citations: [AiFileCitationDto]?
    /// DORR's own services offered with this answer (Modules/AI AiServiceActions) — e.g. a ride to book.
    var actions: [AiServiceActionDto]?
    var createdAt: String?

    var isUser: Bool { role == "user" }
}

/// androidApp `AiServiceActionDto` — a DORR service card under an answer. `ride_booking`: the person wants to go
/// somewhere — the place from the admin's map and what else was understood, in the same shape as
/// «دُرر يفهم مقصدك» (rides `ai/understand`).
struct AiServiceActionDto: Codable, Equatable {
    var type: String
    var service: String?
    var title: String?
    var subtitle: String?
    var text: String?
    var destination: RideAiPlaceDto?
    var places: [RideAiPlaceDto]?
    var understood: RideAiUnderstoodDto?
    var clarify: String?
}

struct AiFileCitationDto: Codable, Equatable, Identifiable {
    var id: Int
    var number: Int?
    var fileId: Int
    var fileName: String?
    var excerpt: String?
    var score: Double?
    var retrievalMethod: String?
    var location: AiCitationLocationDto?
}

struct AiCitationLocationDto: Codable, Equatable {
    var page: Int?
    var section: String?
    var sheet: String?
    var rowStart: Int?
    var rowEnd: Int?
    var slide: Int?
    var timestampStart: Double?
    var timestampEnd: Double?
}

struct AiFileDto: Codable, Equatable, Identifiable {
    var id: Int
    var fileName: String?
    var mimeType: String?
    var fileSize: Int64?
    /// "uploading" | "processing" | "ready" | "failed".
    var status: String?
    var processingError: String?
}

struct AiAttachmentDto: Codable, Equatable {
    var id: Int
    var messageId: Int?
    var fileName: String?
    var mimeType: String?
    var fileSize: Int64?
    var isImage: Bool?
    var url: String?
    /// Client-only: the just-picked image / just-recorded note shown in the pending bubble before the server answers.
    var localUrl: URL?

    enum CodingKeys: String, CodingKey { case id, messageId, fileName, mimeType, fileSize, isImage, url }
}

struct AiGeneratedFileDto: Codable, Equatable {
    var name: String?
    var url: String?
}

struct AiSourceDto: Codable, Equatable {
    var source: String?
    var publisher: String?
    var position: Int?
    var score: Double?
}

struct AiSendMessageResultDto: Codable, Equatable {
    var userMessage: AiMessageDto?
    var assistantMessage: AiMessageDto?
    var conversation: AiConversationDto?
    var usage: AiUsageDto?
    var traceId: String?
    var status: String?
    var confidence: Double?
    var sources: [AiSourceDto]?
    var warnings: [String]?
}

struct AiRealtimeSessionDto: Codable, Equatable {
    var sessionId: Int
    var clientSecret: String
    var expiresAt: Int64?
    var model: String?
    var webrtcEndpoint: String
}

// MARK: - Subscription (wallet-paid plans)

struct AiPlanDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String
    var code: String?
    var description: String?
    var usageMinutes: Int?
    var cooldownMinutes: Int?
    var durationDays: Int?
    var price: String
    var originalPrice: String?
    var discountPercent: Int?
    var currency: String
    var badge: String?
    var isFeatured: Bool?
    var features: [String]?
    var isTrial: Bool?
    var isActive: Bool?
    var sortOrder: Int?
    var isCountrySpecificPrice: Bool?
}

struct AiSubscriptionDto: Codable, Equatable {
    var id: Int
    var plan: AiPlanDto?
    var startsAt: String?
    var endsAt: String?
    var status: String
    var autoRenew: Bool?
    var inGracePeriod: Bool?
    var graceEndsAt: String?
    var daysRemaining: Int?
    var currentPlanPrice: String?
}

struct AiSubscriptionPaymentDto: Codable, Equatable, Identifiable {
    var id: Int
    var plan: AiPlanDto?
    /// "initial" | "renewal" | "upgrade" | "downgrade".
    var type: String
    /// "succeeded" | "failed".
    var status: String
    var amount: String
    var currency: String
    var failureReason: String?
    var createdAt: String?
}

// Plan prices come as JSON numbers (`AiPlanPricingResource` casts them to float) while other money fields come as
// decimal strings. Gson on Android turns a number into a String field by itself; here both shapes are read the same way,
// otherwise one number fails the whole plan list.
extension AiPlanDto {
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = try c.decode(String.self, forKey: .name)
        code = try c.decodeIfPresent(String.self, forKey: .code)
        description = try c.decodeIfPresent(String.self, forKey: .description)
        usageMinutes = try c.decodeIfPresent(Int.self, forKey: .usageMinutes)
        cooldownMinutes = try c.decodeIfPresent(Int.self, forKey: .cooldownMinutes)
        durationDays = try c.decodeIfPresent(Int.self, forKey: .durationDays)
        price = c.lenientString(.price) ?? "0"
        originalPrice = c.lenientString(.originalPrice)
        discountPercent = try c.decodeIfPresent(Int.self, forKey: .discountPercent)
        currency = try c.decodeIfPresent(String.self, forKey: .currency) ?? ""
        badge = try c.decodeIfPresent(String.self, forKey: .badge)
        isFeatured = try c.decodeIfPresent(Bool.self, forKey: .isFeatured)
        features = try c.decodeIfPresent([String].self, forKey: .features)
        isTrial = try c.decodeIfPresent(Bool.self, forKey: .isTrial)
        isActive = try c.decodeIfPresent(Bool.self, forKey: .isActive)
        sortOrder = try c.decodeIfPresent(Int.self, forKey: .sortOrder)
        isCountrySpecificPrice = try c.decodeIfPresent(Bool.self, forKey: .isCountrySpecificPrice)
    }
}

extension AiSubscriptionDto {
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        plan = try c.decodeIfPresent(AiPlanDto.self, forKey: .plan)
        startsAt = try c.decodeIfPresent(String.self, forKey: .startsAt)
        endsAt = try c.decodeIfPresent(String.self, forKey: .endsAt)
        status = try c.decode(String.self, forKey: .status)
        autoRenew = try c.decodeIfPresent(Bool.self, forKey: .autoRenew)
        inGracePeriod = try c.decodeIfPresent(Bool.self, forKey: .inGracePeriod)
        graceEndsAt = try c.decodeIfPresent(String.self, forKey: .graceEndsAt)
        daysRemaining = try c.decodeIfPresent(Int.self, forKey: .daysRemaining)
        currentPlanPrice = c.lenientString(.currentPlanPrice)
    }
}

extension AiSubscriptionPaymentDto {
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        plan = try c.decodeIfPresent(AiPlanDto.self, forKey: .plan)
        type = try c.decode(String.self, forKey: .type)
        status = try c.decode(String.self, forKey: .status)
        amount = c.lenientString(.amount) ?? "0"
        currency = try c.decodeIfPresent(String.self, forKey: .currency) ?? ""
        failureReason = try c.decodeIfPresent(String.self, forKey: .failureReason)
        createdAt = try c.decodeIfPresent(String.self, forKey: .createdAt)
    }
}

extension KeyedDecodingContainer {
    /// A string field the server may also send as a number (Gson's coercion on Android).
    func lenientString(_ key: Key) -> String? {
        if let text = try? decodeIfPresent(String.self, forKey: key) { return text }
        if let int = try? decodeIfPresent(Int.self, forKey: key) { return String(int) }
        if let number = try? decodeIfPresent(Double.self, forKey: key) { return String(number) }
        return nil
    }
}

// MARK: - Reply language

struct AiLanguageDto: Codable, Equatable, Identifiable {
    var id: Int
    var code: String
    var name: String
    var direction: String?
    var aiEnabled: Bool?
}

struct AiLanguageVariantDto: Codable, Equatable, Identifiable {
    var id: Int
    var languageId: Int?
    var code: String
    var name: String
    /// "formal" | "conversational".
    var style: String?
    var isDefault: Bool?
    var isActive: Bool?
}

struct AiLanguageRefDto: Codable, Equatable {
    var id: Int
    var code: String?
    var name: String?
}

struct AiUserLanguagePreferenceDto: Codable, Equatable {
    var id: Int?
    var language: AiLanguageRefDto?
    var variant: AiLanguageRefDto?
    var autoDetect: Bool?
    /// "follow_input" | "fixed".
    var responseLanguageMode: String?
}

struct AiLanguagePreferenceShowDto: Codable, Equatable {
    var preference: AiUserLanguagePreferenceDto
    var languages: [AiLanguageDto]?
    var variants: [AiLanguageVariantDto]?
}

// MARK: - The assistant's tools

struct AiMemoriesDto: Codable, Equatable {
    var enabled: Bool?
    var limit: Int?
    var memories: [AiMemoryDto]?
}

struct AiMemoryDto: Codable, Equatable, Identifiable {
    var id: Int
    var content: String
    var createdAt: String?
}

struct AiMemorySettingsDto: Codable, Equatable {
    var enabled: Bool
}

struct AiSearchResultDto: Codable, Equatable {
    var query: String?
    var terms: [String]?
    var results: [AiSearchHitDto]?
}

struct AiSearchHitDto: Codable, Equatable {
    /// "title" | "message" | "file" | "project".
    var type: String
    /// "lexical" | "semantic".
    var match: String
    var term: String?
    var conversation: AiRefDto
    var messageId: Int?
    var snippet: String?
    var highlights: [AiHighlightDto]?
    var createdAt: String?
    var project: AiRefDto?
}

struct AiRefDto: Codable, Equatable, Identifiable {
    var id: Int
    var title: String?
    var name: String?
}

struct AiHighlightDto: Codable, Equatable {
    var start: Int
    var length: Int
}

struct AiOutputDto: Codable, Equatable {
    /// "image" | "video" | "audio" | "document".
    var kind: String
    var name: String?
    var mimeType: String?
    var url: String?
    var conversation: AiRefDto?
    var messageId: Int?
    var createdAt: String?
}

struct AiContinueResultDto: Codable, Equatable {
    var conversation: AiConversationDto
    var capsule: AiCapsuleDto?
}

struct AiCapsuleHolderDto: Codable, Equatable {
    var capsule: AiCapsuleDto?
}

struct AiCapsuleDto: Codable, Equatable {
    var goal: String?
    var decisions: [String]?
    var facts: [String]?
    var openTasks: [String]?
    var done: [String]?
    var files: [String]?
    var sources: [AiRefDto]?
}

struct AiProjectDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String
    var description: String?
    var instructions: String?
    var conversationsCount: Int?
    var filesCount: Int?
    var conversations: [AiRefDto]?
}

struct AiEraseDataResultDto: Codable, Equatable {
    var deletedConversations: Int?
}

/// An empty `data` (deletes and the like) — anything decodes.
struct AiNothing: Codable, Equatable {
    init() {}
    init(from decoder: Decoder) throws {}
}

enum AiChatApi {
    private static let base = "user/v1/ai-chat"

    static func status() async throws -> ApiEnvelope<AiStatusDto> { try await ApiClient.snake("\(base)/status") }
    static func usage() async throws -> ApiEnvelope<AiUsageDto> { try await ApiClient.snake("\(base)/usage") }
    static func conversations() async throws -> ApiEnvelope<[AiConversationDto]> { try await ApiClient.snake("\(base)/conversations") }
    static func createConversation() async throws -> ApiEnvelope<AiConversationDto> { try await ApiClient.snake("\(base)/conversations", method: "POST", body: [:]) }
    static func conversation(_ id: Int) async throws -> ApiEnvelope<AiConversationDto> { try await ApiClient.snake("\(base)/conversations/\(id)") }
    static func deleteConversation(_ id: Int) async throws -> ApiEnvelope<AiNothing?> { try await ApiClient.snake("\(base)/conversations/\(id)", method: "DELETE") }

    /// The files available to this conversation (distinct from a message's one quick attachment).
    static func conversationFiles(_ id: Int) async throws -> ApiEnvelope<[AiFileDto]> { try await ApiClient.snake("\(base)/conversations/\(id)/files") }
    static func attachConversationFile(_ id: Int, fileId: Int) async throws -> ApiEnvelope<AiFileDto> {
        try await ApiClient.snake("\(base)/conversations/\(id)/files", method: "POST", body: ["file_id": .int(fileId)])
    }
    static func detachConversationFile(_ id: Int, fileId: Int) async throws -> ApiEnvelope<AiNothing?> {
        try await ApiClient.snake("\(base)/conversations/\(id)/files/\(fileId)", method: "DELETE")
    }

    /// Uploading with `conversation_id` already attaches the file server-side.
    static func uploadConversationFile(_ file: ApiClient.FilePart, conversationId: Int) async throws -> ApiEnvelope<AiFileDto> {
        let data = try await ApiClient.uploadRaw("user/v1/ai-files", fields: ["conversation_id": String(conversationId)], files: [file])
        return try ApiClient.snakeDecoder.decode(ApiEnvelope<AiFileDto>.self, from: data)
    }
    static func fileStatus(_ id: Int) async throws -> ApiEnvelope<AiFileDto> { try await ApiClient.snake("user/v1/ai-files/\(id)/status") }

    /// Always multipart: `attachment` is left out when there is no file. A fresh Idempotency-Key per tap lets a
    /// retried request replay the one saved answer instead of a second (billed) AI call.
    static func sendMessage(_ id: Int, key: String, fields: [String: String], attachment: ApiClient.FilePart?) async throws -> ApiEnvelope<AiSendMessageResultDto> {
        let data = try await ApiClient.uploadRaw("\(base)/conversations/\(id)/messages", fields: fields, files: attachment.map { [$0] } ?? [], headers: ["Idempotency-Key": key])
        return try ApiClient.snakeDecoder.decode(ApiEnvelope<AiSendMessageResultDto>.self, from: data)
    }

    /// A short-lived Realtime credential, used only to open the call straight to the provider's `webrtc_endpoint`.
    static func createRealtimeSession(conversationId: Int?) async throws -> ApiEnvelope<AiRealtimeSessionDto> {
        try await ApiClient.snake("user/v1/ai-realtime/session", method: "POST", body: ["conversation_id": conversationId.map { .int($0) } ?? .null])
    }
    static func endRealtimeSession(_ id: Int, durationSeconds: Int?) async throws -> ApiEnvelope<AiNothing?> {
        try await ApiClient.snake("user/v1/ai-realtime/session/\(id)/end", method: "POST", body: ["duration_seconds": durationSeconds.map { .int($0) } ?? .null])
    }

    // Subscription: subscribe and change-plan move real money and carry X-Wallet-Pin.
    static func subscriptionPlans() async throws -> ApiEnvelope<[AiPlanDto]> { try await ApiClient.snake("user/v1/ai-subscription/plans") }
    static func currentSubscription() async throws -> ApiEnvelope<AiSubscriptionDto?> { try await ApiClient.snake("user/v1/ai-subscription") }
    static func subscriptionPayments() async throws -> ApiEnvelope<[AiSubscriptionPaymentDto]> { try await ApiClient.snake("user/v1/ai-subscription/payments") }
    static func updateAutoRenew(_ on: Bool) async throws -> ApiEnvelope<AiSubscriptionDto> {
        try await ApiClient.snake("user/v1/ai-subscription/auto-renew", method: "PUT", body: ["auto_renew": .bool(on)])
    }
    static func subscribe(planId: Int, autoRenew: Bool = true, pin: String) async throws -> ApiEnvelope<AiSubscriptionDto> {
        try await ApiClient.snake("user/v1/ai-subscription/subscribe", method: "POST", body: ["plan_id": .int(planId), "auto_renew": .bool(autoRenew)], headers: ["X-Wallet-Pin": pin])
    }
    static func changePlan(planId: Int, pin: String) async throws -> ApiEnvelope<AiSubscriptionDto> {
        try await ApiClient.snake("user/v1/ai-subscription/change-plan", method: "POST", body: ["plan_id": .int(planId)], headers: ["X-Wallet-Pin": pin])
    }

    // The reply language / dialect.
    static func languagePreference() async throws -> ApiEnvelope<AiLanguagePreferenceShowDto> { try await ApiClient.snake("user/v1/ai-language-preference") }
    static func updateLanguagePreference(mode: String, languageId: Int?, variantId: Int?) async throws -> ApiEnvelope<AiUserLanguagePreferenceDto> {
        try await ApiClient.snake("user/v1/ai-language-preference", method: "PUT", body: [
            "response_language_mode": .string(mode),
            "language_id": languageId.map { .int($0) } ?? .null,
            "variant_id": variantId.map { .int($0) } ?? .null,
        ])
    }
}

enum AiAssistantApi {
    private static let base = "user/v1/ai-chat"

    // Memory
    static func memories() async throws -> ApiEnvelope<AiMemoriesDto> { try await ApiClient.snake("\(base)/memories") }
    static func addMemory(_ content: String) async throws -> ApiEnvelope<AiMemoryDto> { try await ApiClient.snake("\(base)/memories", method: "POST", body: ["content": .string(content)]) }
    static func updateMemory(_ id: Int, _ content: String) async throws -> ApiEnvelope<AiMemoryDto> {
        try await ApiClient.snake("\(base)/memories/\(id)", method: "PUT", body: ["content": .string(content)])
    }
    static func deleteMemory(_ id: Int) async throws -> ApiEnvelope<AiNothing?> { try await ApiClient.snake("\(base)/memories/\(id)", method: "DELETE") }
    static func deleteAllMemories() async throws -> ApiEnvelope<AiNothing?> { try await ApiClient.snake("\(base)/memories", method: "DELETE") }
    static func memorySettings(enabled: Bool) async throws -> ApiEnvelope<AiMemorySettingsDto> {
        try await ApiClient.snake("\(base)/memories/settings", method: "PUT", body: ["enabled": .bool(enabled)])
    }

    // History
    static func search(_ q: String) async throws -> ApiEnvelope<AiSearchResultDto> { try await ApiClient.snake("\(base)/search", query: ["q": q]) }
    static func outputs(kind: String? = nil) async throws -> ApiEnvelope<[AiOutputDto]> { try await ApiClient.snake("\(base)/outputs", query: ["kind": kind]) }
    static func continueConversations(_ ids: [Int]) async throws -> ApiEnvelope<AiContinueResultDto> {
        try await ApiClient.snake("\(base)/conversations/continue", method: "POST", body: ["conversation_ids": .array(ids.map { .int($0) })])
    }
    static func capsule(_ id: Int) async throws -> ApiEnvelope<AiCapsuleHolderDto> { try await ApiClient.snake("\(base)/conversations/\(id)/capsule") }
    static func deleteCapsule(_ id: Int) async throws -> ApiEnvelope<AiNothing?> { try await ApiClient.snake("\(base)/conversations/\(id)/capsule", method: "DELETE") }

    // Projects
    static func projects() async throws -> ApiEnvelope<[AiProjectDto]> { try await ApiClient.snake("\(base)/projects") }
    static func project(_ id: Int) async throws -> ApiEnvelope<AiProjectDto> { try await ApiClient.snake("\(base)/projects/\(id)") }
    private static func projectBody(_ name: String, _ description: String?, _ instructions: String?) -> [String: JSONValue] {
        ["name": .string(name), "description": description.map { .string($0) } ?? .null, "instructions": instructions.map { .string($0) } ?? .null]
    }
    static func createProject(name: String, description: String?, instructions: String?) async throws -> ApiEnvelope<AiProjectDto> {
        try await ApiClient.snake("\(base)/projects", method: "POST", body: projectBody(name, description, instructions))
    }
    static func updateProject(_ id: Int, name: String, description: String?, instructions: String?) async throws -> ApiEnvelope<AiProjectDto> {
        try await ApiClient.snake("\(base)/projects/\(id)", method: "PUT", body: projectBody(name, description, instructions))
    }
    static func deleteProject(_ id: Int) async throws -> ApiEnvelope<AiNothing?> { try await ApiClient.snake("\(base)/projects/\(id)", method: "DELETE") }
    static func addProjectConversation(_ id: Int, conversation: Int) async throws -> ApiEnvelope<AiProjectDto> {
        try await ApiClient.snake("\(base)/projects/\(id)/conversations/\(conversation)", method: "POST", body: [:])
    }
    static func removeProjectConversation(_ id: Int, conversation: Int) async throws -> ApiEnvelope<AiNothing?> {
        try await ApiClient.snake("\(base)/projects/\(id)/conversations/\(conversation)", method: "DELETE")
    }
}
