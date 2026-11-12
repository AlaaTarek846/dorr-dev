import Foundation

// freelanceApp `net/NotificationsApi.kt` — the freelancer's notifications and how they reach the phone (FL-007).

struct RealtimeConfigDto: Decodable {
    var enabled: Bool?
    var key: String?
    var cluster: String?
    var host: String?
    var port: Int?
    var useTls: Bool?
    var authPath: String?
    /// The freelancer's own private channel.
    var channel: String?
    var onesignalAppId: String?
}

struct NotificationDto: Decodable, Identifiable, Equatable {
    var id: String
    var title: String?
    var message: String?
    var type: String?
    /// e.g. `freelance.verification.approved` — what the app picks the icon and the screen from.
    var event: String?
    var data: JSONAny?
    var createdAtIso: String?
    var readAt: String?

    static func == (a: NotificationDto, b: NotificationDto) -> Bool { a.id == b.id && a.readAt == b.readAt }
}

struct UnreadCountDto: Decodable {
    var count: Int?
}

enum NotificationsApi {
    private static let base = Api.freelance

    /// The settings of the live connection and the push app, served by the API so moving servers needs no app update.
    static func realtimeConfig() async -> ApiResult<RealtimeConfigDto> { await Api.call("GET", base + "realtime-config") }

    static func list(perPage: Int = 40, page: Int = 1) async -> ApiResult<[NotificationDto]> {
        await Api.call("GET", base + "notifications", query: ["per_page": "\(perPage)", "page": "\(page)"])
    }

    static func unreadCount() async -> ApiResult<UnreadCountDto> { await Api.call("GET", base + "notifications/unread-count") }

    static func markRead(_ id: String) async -> ApiResult<UnreadCountDto> { await Api.call("POST", base + "notifications/\(id)/read") }

    static func markAllRead() async -> ApiResult<UnreadCountDto> { await Api.call("POST", base + "notifications/read-all") }

    /// This phone's push id, so pushes reach it (asked on every open: the id can move between accounts).
    static func registerDevice(_ playerId: String) async -> ApiResult<Ignored> {
        await Api.call("POST", base + "notifications/devices", body: ["player_id": playerId, "platform": "ios"])
    }

    static func unregisterDevice(_ playerId: String) async -> ApiResult<Ignored> {
        await Api.call("DELETE", base + "notifications/devices", body: ["player_id": playerId, "platform": "ios"])
    }
}

/// freelanceApp `net/NoticeRoute.kt` — where a notice leads: the screen of what it is about. The same answer serves a card
/// in the notifications list and a push tapped in the phone's tray. `nil`: the notice has no screen of its own.
enum NoticeRoute {
    static func resolve(_ event: String?, field: (String) -> String?) -> Route? {
        // FL-L12: a community notice (`freelance_community_*`) opens the post it is about, or the member who followed me.
        if event?.hasPrefix("freelance.community") == true || field("type")?.hasPrefix("freelance_community_") == true {
            return field("community_post").map(Route.communityPost) ?? field("community_member").map(Route.communityMember) ?? .community
        }
        // FS-703: a follow-up reminder that fell due (`type` = crm_reminder, `client` = the client's CRM uuid) opens that client's page, where it waits.
        if field("type") == "crm_reminder" || event?.hasPrefix("freelance.crm") == true {
            return field("client").map(Route.crmClient) ?? .crm
        }
        guard let e = event else { return nil }
        let project = field("project")
        if e == "freelance.message" || e.hasPrefix("freelance.interview") { return field("conversation_uuid").map(Route.chat) }
        if e.hasPrefix("wallet.") || e == "freelance.payment.available" { return .wallet }
        if e.hasPrefix("freelance.verification") { return .verification }
        if e.hasPrefix("freelance.skill") { return .skills }
        // FS-601: staff took the «Verified by Dorr» badge off one of my works - my works, where its card says why.
        if e == "freelance.portfolio.hidden" || e == "freelance.portfolio.unverified" { return .portfolio }
        // FS-602: the reviewer's answer on my practical challenge opens the skill tests, where its card shows the decision.
        if e.hasPrefix("freelance.challenge") { return .skillTests }
        // FS-604: staff answered my request to correct a reputation figure.
        if e.hasPrefix("freelance.reputation") { return .reputation }
        if e == "freelance.quota.low" { return .myOffers }
        if e.hasPrefix("freelance.subscription") { return .plans }
        // FL-L13: a client's leave to show a finished work (or taking it back) opens that agreement.
        if e.hasPrefix("freelance.rights") { return project.map(Route.agreement) }
        // FS-213: a client invited my team (I am its Owner) - the same list, the card says which team.
        if e == "freelance.invitation.received" || e == "freelance.invitation.team_received" { return .invitations }
        // FS-150e: the other side's step in the Workroom opens that project's Workroom, on the part it is about
        // (task_assigned → tasks, file_shared / comment_added → files, decision_* → decisions).
        if e.hasPrefix("freelance.workroom") {
            guard let project else { return nil }
            if e.contains("decision") { return .workroom(project: project, tab: "decisions") }
            if e.contains("file") || e.contains("comment") { return .workroom(project: project, tab: "files") }
            return .workroom(project: project, tab: "tasks")
        }
        // FS-209: an invitation into a team opens my teams (where it waits for my answer); the other team notices open the team.
        if e == "freelance.team_invited" { return .teams }
        // FS-209b: being put on a part opens that project (the agreement page, where the part and its people are).
        if e == "freelance.team_assigned" { return project.map(Route.agreement) ?? field("team").map(Route.team) ?? .teams }
        // FS-209c: a message in the team's inside chat opens that thread (the team's page when the notice has no thread).
        if e == "freelance.internal_message" {
            guard let team = field("team") else { return .teams }
            return field("thread").map { Route.teamThread(team: team, thread: $0) } ?? .team(team)
        }
        // FS-209c: the staff's answer on the team's papers (told to the Owners only) opens the verification page.
        if e == "freelance.team_verified" || e == "freelance.team_verification_rejected" || e == "freelance.team_verification_revoked" {
            return field("team").map(Route.teamVerification) ?? .teams
        }
        if e.hasPrefix("freelance.team_") { return field("team").map(Route.team) ?? .teams }
        // FL-L09: an answer to my special offer opens it.
        if e.hasPrefix("freelance.special_offer") { return field("special_offer").map(Route.specialOffer) ?? .specialOffers }
        // FL-L04: a new order opens it; a decision on a service, my services.
        if e == "freelance.order.received" { return field("order").map(Route.serviceOrder) ?? .serviceOrders }
        // FL-L07: a listing the staff decided on opens its editor; a booking opens the booking.
        if e.hasPrefix("freelance.consultation.listing") { return field("consultation_offer").map { .consultationEdit($0) } ?? .consultations }
        // FL-L16: the session's notices (session_joined · session_ending · session_closed · session_reopened · recording_ready) open
        // the booking too — its session card has "join", the time left and the recordings.
        if e.hasPrefix("freelance.consultation") { return field("consultation").map(Route.consultation) ?? .consultations }
        if e.hasPrefix("freelance.service.") { return field("service").map { .service($0) } ?? .services }
        // A client's monthly service: a renewal opens its order (or my orders); someone waiting for a place opens the service.
        if e.hasPrefix("freelance.retainer") { return field("order").map(Route.serviceOrder) ?? .serviceOrders }
        if e.hasPrefix("freelance.waitlist") { return field("service").map { .service($0) } ?? .services }
        // A sanction (and its appeal) is shown on Home, in the card above the numbers.
        if e.hasPrefix("freelance.sanction") { return .home }
        if e.hasPrefix("freelance.dispute") {
            if let project, let dispute = field("dispute") { return .dispute(project: project, dispute: dispute) }
            return project.map(Route.agreement)
        }
        // The work itself: the agreement, its parts, their payments and changes.
        // FS-106: a health notice (health.at_risk · delayed · blocked) opens the agreement, where its card explains it.
        if e.hasPrefix("freelance.milestone") || e.hasPrefix("freelance.report") || e.hasPrefix("freelance.change") || e.hasPrefix("freelance.payment")
            || e.hasPrefix("freelance.health")
            || e == "freelance.agreement.received" || e == "freelance.project.completed" {
            return project.map(Route.agreement)
        }
        // Before the work: the project and my offer on it.
        if e.hasPrefix("freelance.offer") || e.hasPrefix("freelance.project") || e.hasPrefix("freelance.agreement") || e.hasPrefix("freelance.review") {
            return project.map(Route.project)
        }
        return nil
    }
}
