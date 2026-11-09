import Foundation

/// FS-104, a project's team — the twin of Android's `network/FreelanceTeamApi.kt` (docs/modules/freelance/PERMISSIONS.md): the owner brings
/// Dorr users into one of his projects, each with one role (manager · reviewer · collaborator · finance · delegate); the person invited
/// answers; the projects I was brought into. A member opens the owner's project pages as usual — the server hides what his role may not see
/// or do. Read when a screen opens and after each action. DTOs are plain optional structs read with `ApiClient.snake`.
enum FreelanceTeamApi {
    private static let projects = "mobile/v1/freelance/projects"
    private static let base = "mobile/v1/freelance/team"

    static func team(_ project: String) async throws -> ApiEnvelope<TeamPageDto> {
        try await ApiClient.snake("\(projects)/\(project)/team")
    }

    static func invite(_ project: String, phone: String, role: String, maxAmountMinor: Int?, days: Int?) async throws -> ApiEnvelope<TeamPageDto> {
        try await ApiClient.snake("\(projects)/\(project)/team", method: "POST", body: roleBody(role, maxAmountMinor, days).merging(["phone": .string(phone)]) { $1 })
    }

    static func update(_ project: String, member: String, role: String, maxAmountMinor: Int?, days: Int?) async throws -> ApiEnvelope<TeamPageDto> {
        try await ApiClient.snake("\(projects)/\(project)/team/\(member)", method: "PUT", body: roleBody(role, maxAmountMinor, days))
    }

    static func remove(_ project: String, member: String) async throws -> ApiEnvelope<TeamPageDto> {
        try await ApiClient.snake("\(projects)/\(project)/team/\(member)", method: "DELETE")
    }

    static func invitations() async throws -> ApiEnvelope<TeamInvitationsDto> {
        try await ApiClient.snake("\(base)/invitations")
    }

    /// `answer` = accept · decline.
    static func respond(_ invitation: String, answer: String) async throws -> ApiEnvelope<TeamAnswerDto> {
        try await ApiClient.snake("\(base)/invitations/\(invitation)/\(answer)", method: "POST")
    }

    /// The projects I am in, each with my `access`.
    static func shared(page: Int = 1, perPage: Int = 30) async throws -> ApiEnvelope<[FreelanceProjectDto]> {
        try await ApiClient.snake("\(base)/projects", query: ["page": String(page), "per_page": String(perPage)])
    }

    static func leave(_ project: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/projects/\(project)/leave", method: "POST")
    }

    private static func roleBody(_ role: String, _ max: Int?, _ days: Int?) -> [String: JSONValue] {
        var body: [String: JSONValue] = ["role": .string(role)]
        if let max { body["max_amount_minor"] = .int(max) }
        if let days { body["days"] = .int(days) }
        return body
    }
}

/// Who I am on a project page: `owner`, or my role on the owner's team, with what that role may do.
struct FreelanceAccessDto: Codable, Equatable {
    var role: String?
    var abilities: [String]?
    var money: Bool?
    var owner: FreelanceAccessOwnerDto?
    var maxAmountMinor: Int?
    var endsAt: String?
    var delegationOver: Bool?

    var isOwner: Bool { (role ?? "owner") == "owner" }
    func can(_ ability: String) -> Bool { isOwner || (abilities ?? []).contains(ability) }
}

struct FreelanceAccessOwnerDto: Codable, Equatable {
    var name: String?
}

/// The owner's team at a glance (on his project page).
struct FreelanceTeamSummaryDto: Codable, Equatable {
    var members: Int?
    var invited: Int?
    var max: Int?
}

/// A step a member of the client's team took for the owner (the record names him, nothing else).
struct FreelanceTrailByDto: Codable, Equatable {
    var name: String?
    var team: Bool?
}

struct FlHomeTeamDto: Codable, Equatable {
    var shared: Int?
    var invitations: Int?
}

struct TeamPageDto: Codable, Equatable {
    var members: [TeamMemberDto]?
    var max: Int?
    var roles: [String]?
    var maxDelegateDays: Int?
    var currency: String?
}

struct TeamMemberDto: Codable, Equatable, Identifiable {
    var uuid: String
    var name: String?
    var phone: String?
    var role: String?
    /// invited · active · expired
    var status: String?
    var maxAmountMinor: Int?
    var endsAt: String?
    var delegationOver: Bool?
    var invitedAt: String?

    var id: String { uuid }
}

struct TeamInvitationsDto: Codable, Equatable {
    var invitations: [TeamInvitationDto]?
}

struct TeamInvitationDto: Codable, Equatable, Identifiable {
    var uuid: String
    var role: String?
    var project: TeamInvitationProjectDto?
    var owner: FreelanceAccessOwnerDto?
    var maxAmountMinor: Int?
    var currency: String?
    var endsAt: String?
    var expiresAt: String?

    var id: String { uuid }
}

struct TeamInvitationProjectDto: Codable, Equatable {
    var uuid: String?
    var title: String?
}

struct TeamAnswerDto: Codable, Equatable {
    var status: String?
    var project: String?
}
