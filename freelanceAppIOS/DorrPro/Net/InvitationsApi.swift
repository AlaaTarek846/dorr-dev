import Foundation

// freelanceApp `net/InvitationsApi.kt`.

/// FL-205: the invitations clients sent me to their private projects, and my yes or no (a verified account is needed to answer).
enum InvitationsApi {
    private static let base = Api.freelance

    static func list(page: Int = 1, perPage: Int = 15, status: String? = nil) async -> ApiResult<[InvitationDto]> {
        await Api.call("GET", base + "invitations", query: ["page": String(page), "per_page": String(perPage), "status": status])
    }

    static func accept(_ uuid: String) async -> ApiResult<InvitationRefDto> { await Api.call("POST", base + "invitations/\(uuid)/accept") }

    static func decline(_ uuid: String) async -> ApiResult<InvitationRefDto> { await Api.call("POST", base + "invitations/\(uuid)/decline") }
}

struct InvitationRefDto: Decodable {
    var uuid: String?
    /// Default `sent`.
    var status: String?
    /// FS-213: the client invited my team (I offer in its name).
    var team: InvitationTeamDto?
}

/// FS-213: the team a client invited through me (its Owner).
struct InvitationTeamDto: Decodable {
    var uuid: String?
    var name: String?
    var logoUrl: String?
}

struct InvitationDto: Decodable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var status: String?
    /// The project can still take offers.
    var open: Bool?
    var project: ProjectDto?
    var offered: Bool?
    var can: InvitationCanDto?
    /// FS-213: the client invited my team, not me alone.
    var team: InvitationTeamDto?
}

struct InvitationCanDto: Decodable {
    var accept: Bool?
    var decline: Bool?
    var offer: Bool?
}
