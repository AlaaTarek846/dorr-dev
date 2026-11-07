import Foundation

// freelanceApp `net/TeamsApi.kt`.

/// My freelancers' teams (FS-209), under `/api/freelance/v1` behind the `teams` switch: the teams I am in, making one, its profile and
/// people, and the invitations into a team (the team's side and mine). A person who is not in a team gets 404 for it, like the server's
/// other routes. The calls whose success carries no data (leave, take a person out, withdraw an invitation) answer the text to show, or nil.
enum TeamsApi {
    private static let base = Api.freelance

    static func mine() async -> ApiResult<TeamListDto> { await Api.call("GET", base + "teams") }

    static func create(_ body: TeamCreateRequest) async -> ApiResult<TeamEnvelopeDto> { await Api.call("POST", base + "teams", body: body.json) }

    static func profile(_ team: String) async -> ApiResult<TeamEnvelopeDto> { await Api.call("GET", base + "teams/\(team)/profile") }

    static func update(_ team: String, _ body: TeamProfileRequest) async -> ApiResult<TeamEnvelopeDto> {
        await Api.call("PUT", base + "teams/\(team)/profile", body: body.json)
    }

    static func consent(_ team: String, _ body: TeamConsentRequest) async -> ApiResult<TeamConsentDto> {
        await Api.call("PUT", base + "teams/\(team)/profile/me", body: body.json)
    }

    /// `@Multipart` — the part's field is `logo`.
    static func logo(_ team: String, _ part: FilePart) async -> ApiResult<TeamLogoDto> {
        await Api.upload(base + "teams/\(team)/profile/logo", files: [part])
    }

    static func removeLogo(_ team: String) async -> ApiResult<TeamLogoDto> { await Api.call("DELETE", base + "teams/\(team)/profile/logo") }

    static func members(_ team: String) async -> ApiResult<TeamMembersDto> { await Api.call("GET", base + "teams/\(team)/members") }

    static func setPermissions(_ team: String, _ member: String, _ body: TeamPermissionsRequest) async -> ApiResult<TeamMemberEnvelopeDto> {
        await Api.call("PUT", base + "teams/\(team)/members/\(member)/permissions", body: body.json)
    }

    static func setRole(_ team: String, _ member: String, _ body: TeamRoleRequest) async -> ApiResult<TeamMemberEnvelopeDto> {
        await Api.call("PUT", base + "teams/\(team)/members/\(member)/role", body: body.json)
    }

    static func leave(_ team: String, offline: String, generic: String) async -> String? {
        await Api.done("POST", base + "teams/\(team)/leave", offline: offline, generic: generic)
    }

    static func remove(_ team: String, _ member: String, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base + "teams/\(team)/members/\(member)", offline: offline, generic: generic)
    }

    static func invitations(_ team: String) async -> ApiResult<TeamInvitationsDto> { await Api.call("GET", base + "teams/\(team)/invitations") }

    static func invite(_ team: String, _ body: TeamInviteRequest) async -> ApiResult<TeamInvitationEnvelopeDto> {
        await Api.call("POST", base + "teams/\(team)/invitations", body: body.json)
    }

    static func cancelInvitation(_ team: String, _ invitation: String, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base + "teams/\(team)/invitations/\(invitation)", offline: offline, generic: generic)
    }

    /// The invitations waiting for me.
    static func myInvitations() async -> ApiResult<TeamInvitationsDto> { await Api.call("GET", base + "team-invitations") }

    static func accept(_ invitation: String) async -> ApiResult<TeamInvitationEnvelopeDto> {
        await Api.call("POST", base + "team-invitations/\(invitation)/accept")
    }

    static func decline(_ invitation: String) async -> ApiResult<TeamInvitationEnvelopeDto> {
        await Api.call("POST", base + "team-invitations/\(invitation)/decline")
    }

    /// Which team and role an invitation code is for, before I accept.
    static func preview(_ token: String) async -> ApiResult<TeamInvitationEnvelopeDto> {
        await Api.call("GET", base + "team-invitations/by-link/\(token)")
    }

    static func acceptCode(_ token: String) async -> ApiResult<TeamInvitationEnvelopeDto> {
        await Api.call("POST", base + "team-invitations/by-link/\(token)/accept")
    }

    /// FS-209b: the team's projects I reach (every one for an Owner or a PM, the ones I am on for a member), each with my place on it.
    static func projects(_ team: String) async -> ApiResult<[TeamProjectDto]> { await Api.call("GET", base + "teams/\(team)/projects") }

    /// FS-209b: what each member carries on the team's running projects (an Owner or a PM reads everybody, a member his own row).
    static func workload(_ team: String) async -> ApiResult<TeamWorkloadDto> { await Api.call("GET", base + "teams/\(team)/workload") }

    /// FS-209b: who of the team is on a part of a team's project.
    static func assignees(_ uuid: String, _ milestone: String) async -> ApiResult<MilestoneAssigneesDto> {
        await Api.call("GET", base + "projects/\(uuid)/milestones/\(milestone)/assignees")
    }

    /// FS-209b: exactly these people are on the part (`[]` takes everybody off). Owner or PM.
    static func setAssignees(_ uuid: String, _ milestone: String, _ body: AssigneesRequest) async -> ApiResult<MilestoneAssigneesDto> {
        await Api.call("PUT", base + "projects/\(uuid)/milestones/\(milestone)/assignees", body: body.json)
    }

    // MARK: - FS-209c: the team's inside chat (never seen by the client)

    /// The general thread first, then the projects' threads, the newest message first.
    static func chatThreads(_ team: String) async -> ApiResult<TeamThreadsDto> { await Api.call("GET", base + "teams/\(team)/chat/threads") }

    /// An Owner opens (or gets) the thread about a project the team works on.
    static func openChatThread(_ team: String, _ body: TeamThreadRequest) async -> ApiResult<TeamThreadEnvelopeDto> {
        await Api.call("POST", base + "teams/\(team)/chat/threads", body: body.json)
    }

    /// Newest first; `nextBefore` is the id to ask the older page with.
    static func chatMessages(_ team: String, _ thread: String, before: Int? = nil, limit: Int? = nil) async -> ApiResult<TeamMessagesDto> {
        await Api.call("GET", base + "teams/\(team)/chat/threads/\(thread)/messages", query: ["before": before.map(String.init), "limit": limit.map(String.init)])
    }

    static func sendChat(_ team: String, _ thread: String, _ body: TeamMessageRequest) async -> ApiResult<TeamMessageEnvelopeDto> {
        await Api.call("POST", base + "teams/\(team)/chat/threads/\(thread)/messages", body: body.json)
    }

    static func readChat(_ team: String, _ thread: String) async -> ApiResult<TeamReadDto> {
        await Api.call("POST", base + "teams/\(team)/chat/threads/\(thread)/read")
    }

    /// Its sender only, within a few minutes: the text to show when it did not go through, or nil.
    static func deleteChatMessage(_ team: String, _ id: Int, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base + "teams/\(team)/chat/messages/\(id)", offline: offline, generic: generic)
    }

    // MARK: - FS-209c: the team's verification (optional; the Owners)

    static func verification(_ team: String) async -> ApiResult<TeamVerificationDto> { await Api.call("GET", base + "teams/\(team)/verification") }

    /// Multipart: `title` and the part `file` (jpg / png / pdf, up to 8 MB).
    static func addVerificationDocument(_ team: String, title: String, _ file: FilePart) async -> ApiResult<TeamVerificationDocDto> {
        await Api.upload(base + "teams/\(team)/verification/documents", fields: ["title": title], files: [file.asField("file")])
    }

    static func deleteVerificationDocument(_ team: String, _ uuid: String, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base + "teams/\(team)/verification/documents/\(uuid)", offline: offline, generic: generic)
    }

    /// One of the papers (private, signed in): a draft's or a sent request's.
    static func verificationFile(_ team: String, _ uuid: String) async -> Data? {
        await Api.bytes(base + "teams/\(team)/verification/documents/\(uuid)")
    }

    static func submitVerification(_ team: String) async -> ApiResult<TeamVerificationDto> {
        await Api.call("POST", base + "teams/\(team)/verification/submit")
    }
}

// MARK: - FS-209c: inside chat DTOs

struct TeamThreadsDto: Decodable {
    var threads: [TeamThreadDto]?
    var canWrite: Bool?
    /// An Owner may open the thread of a project.
    var canOpenProject: Bool?
    /// What the team is told about its chat (the staff may read it while a dispute is decided).
    var notice: String?
}

struct TeamThreadDto: Decodable, Equatable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var general: Bool?
    var project: TeamProjectRefDto?
    var unread: Int?
    var lastMessagePreview: String?
    var lastMessageAt: String?
    var canWrite: Bool?
    /// A dispute on the project is being decided: the staff may read the thread.
    var underReview: Bool?
}

struct TeamThreadEnvelopeDto: Decodable {
    var thread: TeamThreadDto?
}

struct TeamThreadRequest {
    let project: String

    var json: JsonBody { ["project": project] }
}

struct TeamMessagesDto: Decodable {
    var thread: TeamThreadDto?
    var messages: [TeamMessageDto]?
    var nextBefore: Int?
    var notice: String?
}

/// `kind` = text | deleted; `mine` is nil on a message that arrived live.
struct TeamMessageDto: Decodable, Equatable, Identifiable {
    var id: Int
    var from: TeamMessageFromDto?
    var mine: Bool?
    var kind: String?
    var body: String?
    var clientMsgId: String?
    var createdAt: String?
}

extension TeamMessageDto {
    /// A message that came on the team's channel (`freelance.internal`, read with the plain decoder: the keys keep their wire spelling).
    init?(live json: JSONAny?) {
        guard let json, let id = json["id"]?.int else { return nil }
        self.init(
            id: id,
            from: json["from"].map { TeamMessageFromDto(id: $0["id"]?.int, name: $0["name"]?.str) },
            mine: json["mine"]?.bool,
            kind: json["kind"]?.str,
            body: json["body"]?.str,
            clientMsgId: json["client_msg_id"]?.str,
            createdAt: json["created_at"]?.str
        )
    }
}

struct TeamMessageFromDto: Decodable, Equatable {
    var id: Int?
    var name: String?
}

struct TeamMessageEnvelopeDto: Decodable {
    var message: TeamMessageDto?
}

struct TeamMessageRequest {
    let body: String
    var clientMsgId: String?

    var json: JsonBody {
        var out: JsonBody = ["body": body]
        if let clientMsgId { out["client_msg_id"] = clientMsgId }
        return out
    }
}

struct TeamReadDto: Decodable {
    var read: Int?
}

// MARK: - FS-209c: verification DTOs

/// `status` = none | submitted | rejected | verified.
struct TeamVerificationDto: Decodable {
    var status: String?
    var verifiedAt: String?
    /// The papers prepared and not sent yet.
    var documents: [TeamVerificationDocDto]?
    var canEdit: Bool?
    var canSubmit: Bool?
    var lastRequest: TeamVerificationRequestDto?
}

struct TeamVerificationRequestDto: Decodable {
    var uuid: String?
    var status: String?
    /// Why it was turned down.
    var reason: String?
    var submittedAt: String?
    var decidedAt: String?
    var documents: [TeamVerificationDocDto]?
}

struct TeamVerificationDocDto: Decodable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var title: String?
    var file: TeamVerificationFileDto?
    var createdAt: String?
}

struct TeamVerificationFileDto: Decodable {
    var name: String?
    var mime: String?
    var size: Int64?
    var path: String?
}

/// FS-209b: one of the team's projects with my place on it (`priceMinor` is nil when my place may not see money).
struct TeamProjectDto: Decodable, Identifiable {
    var id: String { agreement?.uuid ?? project?.uuid ?? "" }
    var project: TeamProjectRefDto?
    var agreement: TeamAgreementRefDto?
    var signedBy: TeamPersonRefDto?
    var teamAccess: TeamAccessDto?
}

struct TeamProjectRefDto: Decodable, Equatable {
    var uuid: String?
    var title: String?
    var status: String?
}

struct TeamAgreementRefDto: Decodable, Equatable {
    var uuid: String?
    /// Default `pending_approval`.
    var status: String?
    var priceMinor: Int?
    @CurrencyLabel var currency: String?
}

struct TeamPersonRefDto: Decodable, Equatable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var name: String?
}

/// FS-209b: my place on a team's project (on the project page, the agreement and the part actions). `abilities` are view · money · net · start
/// · report · deliver · manage · assign; the answer already hides what they do not allow, so these only decide what to explain.
struct TeamAccessDto: Decodable, Equatable {
    var team: TeamRefDto?
    /// owner | pm | member (default member).
    var role: String?
    var signer: Bool?
    var abilities: [String]?

    func can(_ ability: String) -> Bool { abilities?.contains(ability) == true }
}

struct TeamWorkloadDto: Decodable {
    var members: [TeamLoadDto]?
}

struct TeamLoadDto: Decodable, Identifiable {
    var id: String { freelancer?.uuid ?? "" }
    var freelancer: TeamPersonRefDto?
    var role: String?
    var projects: Int?
    var partsOpen: Int?
    var tasksOpen: Int?
    var tasksOverdue: Int?
    var tasksBlocked: Int?
    var tasksDone: Int?
}

struct MilestoneAssigneesDto: Decodable {
    var milestone: TeamPersonRefDto?
    var assignees: [TeamPersonRefDto]?
}

struct AssigneesRequest {
    let freelancers: [String]

    var json: JsonBody { ["freelancers": freelancers] }
}

struct TeamCreateRequest {
    let name: String

    var json: JsonBody { ["name": name] }
}

/// Only what is set is sent: the server leaves the rest as it is.
struct TeamProfileRequest {
    var name: String?
    var bio: String?
    var visibility: String?
    var acceptingProjects: Bool?

    var json: JsonBody {
        var body: JsonBody = [:]
        if let name { body["name"] = name }
        if let bio { body["bio"] = bio }
        if let visibility { body["visibility"] = visibility }
        if let acceptingProjects { body["accepting_projects"] = acceptingProjects }
        return body
    }
}

struct TeamConsentRequest {
    let showInProfile: Bool

    var json: JsonBody { ["show_in_profile": showInProfile] }
}

struct TeamPermissionsRequest {
    let permissions: [String: Bool]

    var json: JsonBody { ["permissions": permissions] }
}

struct TeamRoleRequest {
    let role: String

    var json: JsonBody { ["role": role] }
}

/// `phone` nil = an invitation by code (no phone number needed).
struct TeamInviteRequest {
    var phone: String?
    let role: String

    var json: JsonBody {
        var body: JsonBody = ["role": role]
        if let phone { body["phone"] = phone }
        return body
    }
}

struct TeamListDto: Decodable {
    var teams: [TeamSummaryDto]?
}

/// A card of «my teams».
struct TeamSummaryDto: Decodable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var name: String?
    /// active | suspended | archived
    var status: String?
    /// owner | pm | member
    var role: String?
    var joinedAt: String?
    var logoUrl: String?
    var verified: Bool?
    var membersCount: Int?
    var acceptingProjects: Bool?
    /// public | private
    var visibility: String?
}

struct TeamEnvelopeDto: Decodable {
    var team: TeamDto?
}

/// The team's own view of its profile (`teams/{uuid}/profile` for somebody in it).
struct TeamDto: Decodable {
    var uuid: String?
    var name: String?
    var bio: String?
    var logoUrl: String?
    var verified: Bool?
    var acceptingProjects: Bool?
    var runningProjects: Int?
    var since: String?
    var membersCount: Int?
    var members: [TeamPersonDto]?
    var specialties: [TeamNamedDto]?
    var skills: [TeamNamedDto]?
    var languages: [String]?
    var stats: TeamStatsDto?
    var status: String?
    var visibility: String?
    /// none | submitted | rejected | verified
    var verificationStatus: String?
    var me: TeamMeDto?
}

struct TeamPersonDto: Decodable {
    /// The person's uuid.
    var freelancer: String?
    var name: String?
    var role: String?
    var showInProfile: Bool?
}

struct TeamNamedDto: Decodable, Identifiable {
    var id: Int?
    var name: String?
}

struct TeamStatsDto: Decodable {
    var rating: Double?
    var reviewsCount: Int?
    var completedProjects: Int?
    var completionRate: Int?
    var onTimeRate: Int?
}

struct TeamMeDto: Decodable {
    var role: String?
    var showInProfile: Bool?
    var canEdit: Bool?
}

struct TeamConsentDto: Decodable {
    var showInProfile: Bool?
}

struct TeamLogoDto: Decodable {
    var logoUrl: String?
}

struct TeamMembersDto: Decodable {
    var members: [TeamMemberDto]?
    var me: TeamMembersMeDto?
    /// The abilities an Owner may switch for a PM or a member.
    var switches: [String]?
}

struct TeamMembersMeDto: Decodable {
    var role: String?
    var abilities: [String: Bool]?
    /// FS-212: the agreements of this team that I signed and that have not ended - while there are any I can not leave or step down.
    var signedAgreements: Int?
    /// FS-212: the offers in the team's name that I signed and that are still open - I take them back before I can leave or step down.
    var openOffers: Int?
}

struct TeamMemberEnvelopeDto: Decodable {
    var member: TeamMemberDto?
}

struct TeamMemberDto: Decodable, Identifiable {
    var id: String { freelancer?.uuid ?? "" }
    var freelancer: TeamMemberPersonDto?
    var role: String?
    var joinedAt: String?
    var abilities: [String: Bool]?
    /// FS-212: for an Owner, the agreements of the team he signed that have not ended (he stays an Owner until they do).
    var signedAgreements: Int?
    /// FS-212: and the offers in the team's name he signed that are still open (he stays an Owner until they end or are taken back).
    var openOffers: Int?

    /// One ability of this person (see [teamAbility]).
    func can(_ key: String) -> Bool { teamAbility(abilities, key) }
}

struct TeamMemberPersonDto: Decodable {
    var uuid: String?
    var name: String?
}

struct TeamInvitationsDto: Decodable {
    var invitations: [TeamInvitationDto]?
    /// Days an invitation stays open (the team's side only).
    var days: Int?
}

struct TeamInvitationEnvelopeDto: Decodable {
    var invitation: TeamInvitationDto?
}

struct TeamInvitationDto: Decodable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var team: TeamRefDto?
    var role: String?
    /// `by_link` on the wire: an invitation by code.
    var byLink: Bool?
    var invited: TeamMemberPersonDto?
    var invitedBy: TeamInviterDto?
    /// pending | accepted | declined | cancelled | expired
    var status: String?
    var expiresAt: String?
    var createdAt: String?
    /// The code of an invitation by code, for the people who may invite (while it waits).
    var token: String?

    var byCode: Bool { byLink == true }
}

struct TeamRefDto: Decodable, Equatable {
    var uuid: String?
    var name: String?
}

struct TeamInviterDto: Decodable {
    var name: String?
}

/// `abilities` is read with the API's snake_case decoder, which turns the map's keys too (`view_price` → `viewPrice`): a key is looked up in
/// both spellings.
func teamAbility(_ abilities: [String: Bool]?, _ key: String) -> Bool {
    guard let abilities else { return false }
    if let on = abilities[key] { return on }
    let parts = key.split(separator: "_").map(String.init)
    let camel = parts.enumerated().map { $0.offset == 0 ? $0.element : $0.element.capitalized }.joined()
    return abilities[camel] == true
}
