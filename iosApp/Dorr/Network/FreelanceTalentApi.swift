import Foundation

/// FS-103, the client's talent lists (`mobile/v1/freelance/talent-lists`) — the twin of Android's `network/FreelanceTalentApi.kt`: lists of
/// freelancers kept by a specialty, a note and a private reminder for each one, and inviting a list to one of my private published
/// projects. The freelancer is never told who saved him. Read once when a screen opens and again after each action. A reminder is turned
/// off by an explicit `null` (`remind_in_days`), which `JSONValue.null` sends. DTOs are plain optional structs read with `ApiClient.snake`.
enum FreelanceTalentApi {
    private static let base = "mobile/v1/freelance/talent-lists"

    static func lists(specialtyId: Int? = nil) async throws -> ApiEnvelope<TalentListsDto> {
        try await ApiClient.snake(base, query: ["specialty_id": specialtyId.map { String($0) }])
    }

    static func create(name: String, specialtyId: Int?) async throws -> ApiEnvelope<TalentListDetailDto> {
        try await ApiClient.snake(base, method: "POST", body: ["name": .string(name), "specialty_id": specialtyId.map(JSONValue.int) ?? .null])
    }

    static func show(_ uuid: String) async throws -> ApiEnvelope<TalentListDetailDto> {
        try await ApiClient.snake("\(base)/\(uuid)")
    }

    static func update(_ uuid: String, name: String, specialtyId: Int?) async throws -> ApiEnvelope<TalentListDetailDto> {
        try await ApiClient.snake("\(base)/\(uuid)", method: "PUT", body: ["name": .string(name), "specialty_id": specialtyId.map(JSONValue.int) ?? .null])
    }

    static func delete(_ uuid: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/\(uuid)", method: "DELETE")
    }

    static func addMember(_ uuid: String, freelancer: String, note: String? = nil) async throws -> ApiEnvelope<TalentListDetailDto> {
        var body: [String: JSONValue] = ["freelancer": .string(freelancer)]
        if let note { body["note"] = .string(note) }
        return try await ApiClient.snake("\(base)/\(uuid)/members", method: "POST", body: body)
    }

    /// Only the keys sent change: `note`, and/or `remind_in_days` (30 · 60 · 90 · 180, or `.null` for off).
    static func updateMember(_ uuid: String, freelancer: String, body: [String: JSONValue]) async throws -> ApiEnvelope<TalentListDetailDto> {
        try await ApiClient.snake("\(base)/\(uuid)/members/\(freelancer)", method: "PUT", body: body)
    }

    static func removeMember(_ uuid: String, freelancer: String) async throws -> ApiEnvelope<TalentListDetailDto> {
        try await ApiClient.snake("\(base)/\(uuid)/members/\(freelancer)", method: "DELETE")
    }

    /// Which of my lists hold this freelancer (the list uuids).
    static func containing(_ freelancer: String) async throws -> ApiEnvelope<TalentContainingDto> {
        try await ApiClient.snake("\(base)/containing/\(freelancer)")
    }

    /// `freelancers` nil: everyone in the list.
    static func invite(_ uuid: String, project: String, freelancers: [String]?) async throws -> ApiEnvelope<TalentInviteResultDto> {
        var body: [String: JSONValue] = ["project": .string(project)]
        if let freelancers { body["freelancers"] = .array(freelancers.map(JSONValue.string)) }
        return try await ApiClient.snake("\(base)/\(uuid)/invite", method: "POST", body: body)
    }
}

struct TalentSpecialtyDto: Codable, Equatable, Hashable {
    var id: Int
    var name: String?
}

struct TalentListDto: Codable, Equatable, Identifiable {
    var uuid: String
    var name: String?
    var specialty: TalentSpecialtyDto?
    var count: Int?
    var createdAt: String?

    var id: String { uuid }
}

struct TalentListsDto: Codable, Equatable {
    var lists: [TalentListDto]?
    var maxLists: Int?
}

struct TalentMemberDto: Codable, Equatable, Identifiable {
    var uuid: String
    var name: String?
    var headline: String?
    var avatarUrl: String?
    var verified: Bool?
    var completeness: Int?
    var note: String?
    var remindAt: String?
    var workedTogether: Bool?
    var addedAt: String?

    var id: String { uuid }
}

/// A list with its members (the answer of show, create, update and every member call).
struct TalentListDetailDto: Codable, Equatable {
    var uuid: String
    var name: String?
    var specialty: TalentSpecialtyDto?
    var count: Int?
    var createdAt: String?
    var members: [TalentMemberDto]?
    var remindOptions: [Int]?
}

struct TalentContainingDto: Codable, Equatable {
    var lists: [String]?
}

/// `reason` = not_found · already_offered · already_invited · declined.
struct TalentSkippedDto: Codable, Equatable {
    var uuid: String?
    var reason: String?
}

struct TalentInviteResultDto: Codable, Equatable {
    var invited: Int?
    var skipped: [TalentSkippedDto]?
}

/// FS-106: how a running project is doing; nil when the agreement is not running. `state` = healthy · at_risk · delayed · blocked.
struct FreelanceHealthDto: Codable, Equatable {
    var state: String?
    var reasons: [FreelanceHealthReasonDto]?
    var nextStep: FreelanceHealthStepDto?
}

/// `mine`: this side is the one who has to act.
struct FreelanceHealthReasonDto: Codable, Equatable {
    var code: String?
    var level: String?
    var mine: Bool?
    var data: FreelanceHealthDataDto?
}

struct FreelanceHealthDataDto: Codable, Equatable {
    var part: String?
    var days: Int?
    var dueAt: String?
}

struct FreelanceHealthStepDto: Codable, Equatable {
    var code: String?
    var mine: Bool?
}
