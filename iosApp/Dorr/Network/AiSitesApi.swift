import Foundation

// androidApp `network/AiSitesApi.kt` — the AI website builder under /api/user/v1/ai-sites (Modules/AI): offers, my sites
// and their versions, build from the plan or buy a standalone site (wallet PIN), edits / retry / restore, hosting on a
// sub-domain (subscribe and renew with the PIN, publish, auto-renew), edit packs, the source ZIP; and the
// human-assisted requests (quote, accept with the first payment, installments, messages). The brief travels as
// multipart fields (nested ones as `contact[phone]`) with the logo and photos.

struct AiSiteOffersDto: Codable, Equatable {
    var enabled: Bool?
    var plan: AiSitePlanDto?
    var offers: [AiSiteOfferDto]?
}

struct AiSitePlanDto: Codable, Equatable {
    var included: Bool?
    var canCreate: Bool?
    var refusal: String?
    var dailyUsed: Int?
    var dailyLimit: Int?
    var projectsLimit: Int?
}

struct AiSiteOfferDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String
    var description: String?
    var generationsIncluded: Int?
    var price: Double?
    var currency: String?
    var available: Bool?
}

struct AiSiteRequestInfoDto: Codable, Equatable {
    var enabled: Bool?
    var intro: String?
}

struct AiRequestStatusDto: Codable, Equatable {
    var code: String?
    var name: String?
    var color: String?
    var final: Bool?
}

struct AiRequestQuoteDto: Codable, Equatable {
    var amount: Double?
    var durationDays: Int?
    var validUntil: String?
    var expired: Bool?
    var notes: String?
    var canAccept: Bool?
}

struct AiRequestTimelineDto: Codable, Equatable {
    var acceptedAt: String?
    var dueAt: String?
    var remainingSeconds: Int?
    var overdue: Bool?
    var deliveredAt: String?
    var completedAt: String?
}

struct AiRequestPaymentsDto: Codable, Equatable {
    var total: Double?
    var paid: Double?
    var remaining: Double?
}

struct AiRequestMilestoneDto: Codable, Equatable, Identifiable {
    var id: Int
    var title: String?
    var description: String?
    var dueAt: String?
    var status: String?
}

struct AiRequestInstallmentDto: Codable, Equatable, Identifiable {
    var id: Int
    var title: String?
    var amount: Double?
    var trigger: String?
    var status: String?
    var paidAt: String?
    var canPay: Bool?
}

struct AiRequestDeliveryDto: Codable, Equatable {
    var url: String?
    var notes: String?
}

struct AiSiteRequestDto: Codable, Equatable, Identifiable {
    var id: Int
    var reference: String?
    var title: String?
    var status: AiRequestStatusDto?
    var currency: String?
    var quote: AiRequestQuoteDto?
    var timeline: AiRequestTimelineDto?
    var payments: AiRequestPaymentsDto?
    var unread: Int?
    var declineReason: String?
    var milestones: [AiRequestMilestoneDto]?
    var installments: [AiRequestInstallmentDto]?
    var delivery: AiRequestDeliveryDto?

    var code: String { status?.code ?? "" }
}

struct AiRequestMessageDto: Codable, Equatable, Identifiable {
    var id: Int
    var sender: String?
    var body: String?
    var createdAt: String?
}

struct AiSiteSourceDownloadDto: Codable, Equatable {
    var enabled: Bool?
    var free: Bool?
    var unlocked: Bool?
    var canDownload: Bool?
    var price: Double?
    var currency: String?
    var purchasable: Bool?
}

struct AiSiteEditsDto: Codable, Equatable {
    var base: Int?
    var packs: Int?
    var total: Int?
    var blocked: Bool?
    var reason: String?
}

struct AiSiteEditPacksDto: Codable, Equatable {
    var edits: AiSiteEditsDto?
    var packs: [AiSiteOfferDto]?
}

struct AiSiteVersionDto: Codable, Equatable, Identifiable {
    var id: Int
    var number: Int
    var kind: String
    var status: String
    var instruction: String?
    var errorMessage: String?
    var isCurrent: Bool?
}

struct AiSiteProjectDto: Codable, Equatable, Identifiable {
    var id: Int
    var title: String
    var status: String
    var accessType: String
    var previewUrl: String?
    var isDisabled: Bool?
    var lastError: String?
    var lastErrorMessage: String?
    var generationsLeft: Int?
    var versions: [AiSiteVersionDto]?

    var versionList: [AiSiteVersionDto] { versions ?? [] }
}

struct AiSiteHostingPlansDto: Codable, Equatable {
    var enabled: Bool?
    /// "subdomain" (name.domain) or "path" (/sites/name on the app host).
    var mode: String?
    var domain: String?
    var plans: [AiSiteHostingPlanDto]?
}

struct AiSiteHostingPlanDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String
    var period: String
    var description: String?
    var price: Double?
    var currency: String?
    var available: Bool?
}

struct AiSiteNameCheckDto: Codable, Equatable {
    var name: String?
    var available: Bool?
    var reason: String?
    var message: String?
}

struct AiSiteHostingShowDto: Codable, Equatable {
    var hosting: AiSiteHostingDto?
}

struct AiSiteHostingDto: Codable, Equatable {
    var id: Int
    var subdomain: String
    var url: String
    var status: String
    var isLive: Bool?
    var period: String?
    var amount: Double?
    var currency: String?
    var autoRenew: Bool?
    var endsAt: String?
    var hasUnpublishedChanges: Bool?
}

enum AiSitesApi {
    private static let base = "user/v1/ai-sites"
    private static let requests = "user/v1/ai-site-requests"

    private static func multipart<T: Decodable>(_ path: String, fields: [String: String], files: [ApiClient.FilePart], headers: [String: String] = [:]) async throws -> ApiEnvelope<T> {
        let data = try await ApiClient.uploadRaw(path, fields: fields, files: files, headers: headers)
        return try ApiClient.snakeDecoder.decode(ApiEnvelope<T>.self, from: data)
    }

    static func offers() async throws -> ApiEnvelope<AiSiteOffersDto> { try await ApiClient.snake("\(base)/offers") }
    static func projects() async throws -> ApiEnvelope<[AiSiteProjectDto]> { try await ApiClient.snake(base) }
    static func project(_ id: Int) async throws -> ApiEnvelope<AiSiteProjectDto> { try await ApiClient.snake("\(base)/\(id)") }

    /// Build from the plan's allowance (`logo` and `images[]` among the files).
    static func create(fields: [String: String], files: [ApiClient.FilePart]) async throws -> ApiEnvelope<AiSiteProjectDto> {
        try await multipart(base, fields: fields, files: files)
    }

    /// Build a standalone paid site (`offer_id` in the fields).
    static func purchase(pin: String, fields: [String: String], files: [ApiClient.FilePart]) async throws -> ApiEnvelope<AiSiteProjectDto> {
        try await multipart("\(base)/purchase", fields: fields, files: files, headers: ["X-Wallet-Pin": pin])
    }

    static func edit(_ id: Int, instruction: String) async throws -> ApiEnvelope<AiSiteProjectDto> {
        try await ApiClient.snake("\(base)/\(id)/edit", method: "POST", body: ["instruction": .string(instruction)])
    }
    static func retry(_ id: Int) async throws -> ApiEnvelope<AiSiteProjectDto> { try await ApiClient.snake("\(base)/\(id)/retry", method: "POST", body: [:]) }
    static func restore(_ id: Int, number: Int) async throws -> ApiEnvelope<AiSiteProjectDto> {
        try await ApiClient.snake("\(base)/\(id)/versions/\(number)/restore", method: "POST", body: [:])
    }

    // Hosting — subscribe and renew move wallet money (X-Wallet-Pin).
    static func hostingPlans() async throws -> ApiEnvelope<AiSiteHostingPlansDto> { try await ApiClient.snake("\(base)/hosting/plans") }
    static func hostingCheck(_ name: String) async throws -> ApiEnvelope<AiSiteNameCheckDto> { try await ApiClient.snake("\(base)/hosting/check", query: ["name": name]) }
    static func hosting(_ id: Int) async throws -> ApiEnvelope<AiSiteHostingShowDto> { try await ApiClient.snake("\(base)/\(id)/hosting") }
    static func hostingSubscribe(_ id: Int, pin: String, planId: Int, subdomain: String) async throws -> ApiEnvelope<AiSiteHostingDto> {
        try await ApiClient.snake("\(base)/\(id)/hosting", method: "POST", body: ["plan_id": .int(planId), "subdomain": .string(subdomain)], headers: ["X-Wallet-Pin": pin])
    }
    static func hostingPublish(_ id: Int) async throws -> ApiEnvelope<AiSiteHostingDto> { try await ApiClient.snake("\(base)/\(id)/hosting/publish", method: "POST", body: [:]) }
    static func hostingAutoRenew(_ id: Int, _ on: Bool) async throws -> ApiEnvelope<AiSiteHostingDto> {
        try await ApiClient.snake("\(base)/\(id)/hosting/auto-renew", method: "PUT", body: ["auto_renew": .int(on ? 1 : 0)])
    }
    static func hostingRenew(_ id: Int, pin: String) async throws -> ApiEnvelope<AiSiteHostingDto> {
        try await ApiClient.snake("\(base)/\(id)/hosting/renew", method: "POST", body: [:], headers: ["X-Wallet-Pin": pin])
    }

    // Edit packs and the source ZIP.
    static func editPacks(_ id: Int) async throws -> ApiEnvelope<AiSiteEditPacksDto> { try await ApiClient.snake("\(base)/\(id)/edit-packs") }
    static func buyEditPack(_ id: Int, pin: String, offerId: Int) async throws -> ApiEnvelope<AiSiteEditPacksDto> {
        try await ApiClient.snake("\(base)/\(id)/edit-packs", method: "POST", body: ["offer_id": .int(offerId)], headers: ["X-Wallet-Pin": pin])
    }
    static func sourceDownload(_ id: Int) async throws -> ApiEnvelope<AiSiteSourceDownloadDto> { try await ApiClient.snake("\(base)/\(id)/source-download") }
    static func buySourceDownload(_ id: Int, pin: String) async throws -> ApiEnvelope<AiSiteSourceDownloadDto> {
        try await ApiClient.snake("\(base)/\(id)/source-download", method: "POST", body: [:], headers: ["X-Wallet-Pin": pin])
    }
    /// The site as a ZIP.
    static func downloadSource(_ id: Int) async throws -> Data { try await ApiClient.raw("\(base)/\(id)/download", auth: true) }

    static func delete(_ id: Int) async throws -> ApiEnvelope<AiNothing?> { try await ApiClient.snake("\(base)/\(id)", method: "DELETE") }

    // Human-assisted requests.
    static func requestInfo() async throws -> ApiEnvelope<AiSiteRequestInfoDto> { try await ApiClient.snake("\(requests)/info") }
    static func requestList() async throws -> ApiEnvelope<[AiSiteRequestDto]> { try await ApiClient.snake(requests) }
    static func request(_ id: Int) async throws -> ApiEnvelope<AiSiteRequestDto> { try await ApiClient.snake("\(requests)/\(id)") }
    static func createRequest(fields: [String: String], files: [ApiClient.FilePart]) async throws -> ApiEnvelope<AiSiteRequestDto> {
        try await multipart(requests, fields: fields, files: files)
    }
    static func cancelRequest(_ id: Int) async throws -> ApiEnvelope<AiSiteRequestDto> { try await ApiClient.snake("\(requests)/\(id)/cancel", method: "POST", body: [:]) }
    static func declineQuote(_ id: Int, reason: String) async throws -> ApiEnvelope<AiSiteRequestDto> {
        try await ApiClient.snake("\(requests)/\(id)/decline", method: "POST", body: ["reason": .string(reason)])
    }
    /// Accepting takes the first payment from the wallet, so it carries the PIN.
    static func acceptQuote(_ id: Int, pin: String) async throws -> ApiEnvelope<AiSiteRequestDto> {
        try await ApiClient.snake("\(requests)/\(id)/accept", method: "POST", body: [:], headers: ["X-Wallet-Pin": pin])
    }
    static func payInstallment(_ id: Int, installment: Int, pin: String) async throws -> ApiEnvelope<AiSiteRequestDto> {
        try await ApiClient.snake("\(requests)/\(id)/installments/\(installment)/pay", method: "POST", body: [:], headers: ["X-Wallet-Pin": pin])
    }
    static func requestMessages(_ id: Int) async throws -> ApiEnvelope<[AiRequestMessageDto]> { try await ApiClient.snake("\(requests)/\(id)/messages") }
    static func sendRequestMessage(_ id: Int, body: String) async throws -> ApiEnvelope<[AiRequestMessageDto]> {
        try await ApiClient.snake("\(requests)/\(id)/messages", method: "POST", body: ["body": .string(body)])
    }
}
