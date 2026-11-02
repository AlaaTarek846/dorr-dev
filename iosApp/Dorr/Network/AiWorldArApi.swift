import Foundation

// androidApp `network/AiWorldApi.kt`, `AiArApi.kt` — DORR AI WORLD (a trip idea as a cinematic video: places, the
// Director's scenes, an identity profile with consent, paid and regenerated behind the wallet PIN) and DORR AR (life-
// size models, designs sent to a provider for review, paid into escrow behind the PIN, delivered / confirmed /
// disputed). Money is in minor units of `currency`. Read with `ApiClient.snake` (map keys come back camel-cased).

// MARK: - World

struct AiWorldInfoDto: Codable, Equatable {
    var enabled: Bool?
    var modes: [String: Bool]?
    var limits: AiWorldLimitsDto?
}

struct AiWorldLimitsDto: Codable, Equatable {
    var maxScenes: Int?
    var sceneSeconds: [Int]?
}

struct AiWorldFidelityDto: Codable, Equatable {
    var tier: String?
    var label: String?
    var description: String?
}

struct AiWorldLocationDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String
    var city: String?
    var address: String?
    var fidelity: AiWorldFidelityDto?
}

struct AiWorldSceneDraftDto: Codable, Equatable {
    var prompt: String
    var seconds: Int
    var locationId: Int?
    var locationName: String?
}

struct AiWorldPlanDto: Codable, Equatable {
    var scenes: [AiWorldSceneDraftDto]?
    var totalSeconds: Int?
    var estimateMinor: Int?
    var currency: String?
    var source: String?
}

struct AiWorldSceneDto: Codable, Equatable, Identifiable {
    var id: Int
    var position: Int
    var prompt: String
    var seconds: Int
    var status: String
    var priceMinor: Int?
    var refundedMinor: Int?
}

struct AiWorldProjectDto: Codable, Equatable, Identifiable {
    var id: Int
    var title: String?
    var status: String
    var totalSeconds: Int?
    var estimatedMinor: Int?
    var chargedMinor: Int?
    var refundedMinor: Int?
    var currency: String?
    var hasFinalVideo: Bool?
    var scenes: [AiWorldSceneDto]?
}

struct AiWorldConsentTextDto: Codable, Equatable {
    var version: String?
    var text: String?
    var attestations: [String: String]?
}

struct AiWorldProfileDto: Codable, Equatable, Identifiable {
    var id: Int
    var label: String
    var subjectType: String
    var status: String
    var photos: Int?
}

enum AiWorldApi {
    private static let base = "user/v1/ai-world"

    static func info() async throws -> ApiEnvelope<AiWorldInfoDto> { try await ApiClient.snake("\(base)/info") }
    static func searchLocations(_ q: String) async throws -> ApiEnvelope<[AiWorldLocationDto]> { try await ApiClient.snake("\(base)/locations/search", query: ["q": q]) }

    /// The Director: proposes scenes and a price. Charges nothing, creates nothing.
    static func plan(brief: String, locationIds: [Int], quality: String = "standard") async throws -> ApiEnvelope<AiWorldPlanDto> {
        try await ApiClient.snake("\(base)/projects/plan", method: "POST", body: [
            "brief": .string(brief), "location_ids": .array(locationIds.map { .int($0) }), "quality": .string(quality),
        ])
    }

    static func projects() async throws -> ApiEnvelope<[AiWorldProjectDto]> { try await ApiClient.snake("\(base)/projects") }
    static func project(_ id: Int) async throws -> ApiEnvelope<AiWorldProjectDto> { try await ApiClient.snake("\(base)/projects/\(id)") }

    static func create(title: String?, brief: String?, quality: String = "standard", identityProfileId: Int?, scenes: [AiWorldSceneDraftDto]) async throws -> ApiEnvelope<AiWorldProjectDto> {
        try await ApiClient.snake("\(base)/projects", method: "POST", body: [
            "title": title.map { .string($0) } ?? .null,
            "brief": brief.map { .string($0) } ?? .null,
            "quality": .string(quality),
            "identity_profile_id": identityProfileId.map { .int($0) } ?? .null,
            "scenes": .array(scenes.map { s in
                .object(["prompt": .string(s.prompt), "seconds": .int(s.seconds), "location_id": s.locationId.map { .int($0) } ?? .null])
            }),
        ])
    }

    static func consentText() async throws -> ApiEnvelope<AiWorldConsentTextDto> { try await ApiClient.snake("\(base)/consent-text") }
    static func profiles() async throws -> ApiEnvelope<[AiWorldProfileDto]> { try await ApiClient.snake("\(base)/identity-profiles") }

    static func createProfile(fields: [String: String], photos: [ApiClient.FilePart]) async throws -> ApiEnvelope<AiWorldProfileDto> {
        let data = try await ApiClient.uploadRaw("\(base)/identity-profiles", fields: fields, files: photos)
        return try ApiClient.snakeDecoder.decode(ApiEnvelope<AiWorldProfileDto>.self, from: data)
    }

    static func consent(_ id: Int, attestation: String) async throws -> ApiEnvelope<AiWorldProfileDto> {
        try await ApiClient.snake("\(base)/identity-profiles/\(id)/consent", method: "POST", body: ["attestation": .string(attestation)])
    }

    static func deleteProfile(_ id: Int) async throws -> ApiEnvelope<AiNothing?> { try await ApiClient.snake("\(base)/identity-profiles/\(id)", method: "DELETE") }
    static func deleteProject(_ id: Int) async throws -> ApiEnvelope<AiNothing?> { try await ApiClient.snake("\(base)/projects/\(id)", method: "DELETE") }

    static func report(_ id: Int, type: String, notes: String?) async throws -> ApiEnvelope<AiNothing?> {
        try await ApiClient.snake("\(base)/projects/\(id)/report", method: "POST", body: ["type": .string(type), "notes": notes.map { .string($0) } ?? .null])
    }

    static func confirm(_ id: Int, pin: String) async throws -> ApiEnvelope<AiWorldProjectDto> {
        try await ApiClient.snake("\(base)/projects/\(id)/confirm", method: "POST", body: [:], headers: ["X-Wallet-Pin": pin])
    }

    static func cancel(_ id: Int) async throws -> ApiEnvelope<AiWorldProjectDto> { try await ApiClient.snake("\(base)/projects/\(id)/cancel", method: "POST", body: [:]) }

    static func approve(_ id: Int, scene: Int) async throws -> ApiEnvelope<AiWorldProjectDto> {
        try await ApiClient.snake("\(base)/projects/\(id)/scenes/\(scene)/approve", method: "POST", body: [:])
    }

    static func regenerate(_ id: Int, scene: Int, pin: String) async throws -> ApiEnvelope<AiWorldProjectDto> {
        try await ApiClient.snake("\(base)/projects/\(id)/scenes/\(scene)/regenerate", method: "POST", body: [:], headers: ["X-Wallet-Pin": pin])
    }
}

// MARK: - AR

struct AiArExperienceDto: Codable, Equatable, Identifiable {
    var id: Int
    var type: String?
    var title: String?
    /// draft | active | submitted | approved | accepted
    var status: String?
    var notice: String?
}

struct AiArProviderDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?
}

struct AiArReviewDto: Codable, Equatable, Identifiable {
    var id: Int
    /// pending | approved | changes_requested | rejected | withdrawn
    var status: String?
    var providerId: Int?
    var customerNote: String?
    var providerNote: String?
    var quoteMinor: Int?
    var currency: String?
}

struct AiArInfoDto: Codable, Equatable {
    var enabled: Bool?
    var measurementNotice: String?
}

struct AiArDimensionsDto: Codable, Equatable {
    var width: Double?
    var height: Double?
    var depth: Double?
}

struct AiArAssetDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?
    var category: String?
    var license: String?
    var attribution: String?
    var dimensions: AiArDimensionsDto?
    var downloadUrl: String?
}

struct AiArOrderDto: Codable, Equatable, Identifiable {
    var id: Int
    var experienceId: Int?
    /// paid | delivered | disputed | released | refunded
    var status: String?
    var currency: String?
    var amountMinor: Int?
    var deliveryNote: String?
    var disputeReason: String?
    var resolutionNote: String?
    var releaseAt: String?
}

enum AiArApi {
    private static let base = "user/v1/ai-ar"

    static func info() async throws -> ApiEnvelope<AiArInfoDto> { try await ApiClient.snake("\(base)/info") }
    static func assets() async throws -> ApiEnvelope<[AiArAssetDto]> { try await ApiClient.snake("\(base)/assets") }
    /// With a signed `download_url` that expires in about 10 minutes — ask right before opening the model.
    static func asset(_ id: Int) async throws -> ApiEnvelope<AiArAssetDto> { try await ApiClient.snake("\(base)/assets/\(id)") }
    static func experiences() async throws -> ApiEnvelope<[AiArExperienceDto]> { try await ApiClient.snake("\(base)/experiences") }
    static func createExperience(type: String, title: String) async throws -> ApiEnvelope<AiArExperienceDto> {
        try await ApiClient.snake("\(base)/experiences", method: "POST", body: ["type": .string(type), "title": .string(title)])
    }
    static func reviews(_ id: Int) async throws -> ApiEnvelope<[AiArReviewDto]> { try await ApiClient.snake("\(base)/experiences/\(id)/reviews") }
    static func searchProviders(_ q: String) async throws -> ApiEnvelope<[AiArProviderDto]> { try await ApiClient.snake("\(base)/providers/search", query: ["q": q]) }
    static func submit(_ id: Int, providerId: Int, note: String?) async throws -> ApiEnvelope<AiArReviewDto> {
        try await ApiClient.snake("\(base)/experiences/\(id)/submit", method: "POST", body: ["provider_id": .int(providerId), "note": note.map { .string($0) } ?? .null])
    }
    static func withdraw(_ id: Int, review: Int) async throws -> ApiEnvelope<AiArReviewDto> {
        try await ApiClient.snake("\(base)/experiences/\(id)/reviews/\(review)/withdraw", method: "POST", body: [:])
    }
    /// Moves wallet money, so it carries X-Wallet-Pin.
    static func acceptAndPay(_ id: Int, pin: String) async throws -> ApiEnvelope<AiArOrderDto> {
        try await ApiClient.snake("\(base)/experiences/\(id)/accept-and-pay", method: "POST", body: [:], headers: ["X-Wallet-Pin": pin])
    }
    /// The latest order of a design, or nil when it was never paid.
    static func order(_ id: Int) async throws -> ApiEnvelope<AiArOrderDto?> { try await ApiClient.snake("\(base)/experiences/\(id)/order") }
    static func cancelOrder(_ id: Int) async throws -> ApiEnvelope<AiArOrderDto> { try await ApiClient.snake("\(base)/orders/\(id)/cancel", method: "POST", body: [:]) }
    static func confirmOrder(_ id: Int) async throws -> ApiEnvelope<AiArOrderDto> { try await ApiClient.snake("\(base)/orders/\(id)/confirm", method: "POST", body: [:]) }
    static func disputeOrder(_ id: Int, reason: String) async throws -> ApiEnvelope<AiArOrderDto> {
        try await ApiClient.snake("\(base)/orders/\(id)/dispute", method: "POST", body: ["reason": .string(reason)])
    }
    static func accept(_ id: Int) async throws -> ApiEnvelope<AiArExperienceDto> { try await ApiClient.snake("\(base)/experiences/\(id)/accept", method: "POST", body: [:]) }
}
