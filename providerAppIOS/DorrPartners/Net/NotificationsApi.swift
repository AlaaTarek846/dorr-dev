import Foundation

// providerApp `net/NotificationsApi.kt` — the provider's notifications and how they reach the phone, under `/api/provider/v1`
// (routes/notifications.php).

struct RealtimeConfigDto: Decodable {
    var enabled: Bool?
    var key: String?
    var cluster: String?
    var host: String?
    var port: Int?
    var useTls: Bool?
    var authPath: String?
    /// The provider's own private channel.
    var channel: String?
    var onesignalAppId: String?
}

struct NotificationDto: Decodable, Identifiable, Equatable {
    var id: String
    var title: String?
    var message: String?
    var type: String?
    /// e.g. `provider.establishment.reviewed` — what the app picks the icon and the screen from.
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
    private static let base = Api.provider

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

/// providerApp `net/NoticeRoute.kt` — where a notice leads: the screen of what it is about. The same answer serves a card in
/// the notifications list and a push tapped in the phone's tray. `nil`: the notice has no screen of its own (it then opens the
/// list).
enum NoticeRoute {
    /// `event`: the notice's event (`wallet.topup.completed`, `provider.establishment.reviewed`…); `field`: a value of its data.
    static func resolve(_ event: String?, field: (String) -> String?) -> Route? {
        guard let e = event else { return nil }
        // A moving notice carries the request uuid (`request`).
        let request = field("request")
        if e.hasPrefix("wallet.") { return .wallet }
        if e.hasPrefix("provider.invitation") { return .invitations }
        if e.hasPrefix("provider.document") { return .documents }
        if e == "moving.published" { return request.map(Route.request) ?? .requests }
        // To a driver: a job handed to them.
        if e == "moving.assigned" { return .myJobs }
        if e == "moving.ticket_opened" || e == "moving.reported" { return .tickets }
        if e.hasPrefix("moving.") { return request.map(Route.job) ?? .home }
        if e.hasPrefix("provider.") { return .home }
        return nil
    }
}
