import Foundation

// freelanceApp `net/SupportApi.kt` — rate the app, the FAQs and support, as in the DORR app (`mobile/v1/ratings|faqs|support-*`),
// under `/api/freelance/v1/`: the guided help, my tickets, the conversation inside each, and closing / reopening one. Everything
// is scoped to the signed-in freelancer. Live changes arrive on the account's Pusher channel (`support.message`,
// `support.ticket.updated`), see `Realtime.shared.events`. Also the legal pages, Settings > Security and the invite code.
//
// `SupportApi` answers `ApiResult`s like every other API here; `SupportClient` is the DORR app's calling style the support
// screens use (return the answer, throw on a refusal), exactly as on Android.

enum SupportApi {
    static func listTickets(page: Int = 1, perPage: Int = 20) async -> ApiResult<[SupportTicketDto]> {
        await Api.call("GET", Api.freelance + "support-tickets", query: ["page": "\(page)", "per_page": "\(perPage)"])
    }

    static func ticket(_ id: Int) async -> ApiResult<SupportTicketDto> {
        await Api.call("GET", Api.freelance + "support-tickets/\(id)")
    }

    /// Multipart: `title`, `body` and an optional `image`.
    static func createTicket(title: String, body: String, image: FilePart?) async -> ApiResult<SupportTicketDto> {
        await Api.upload(Api.freelance + "support-tickets", fields: ["title": title, "body": body], files: image.map { [$0] } ?? [])
    }

    static func setStatus(_ id: Int, _ body: SupportStatusRequest) async -> ApiResult<SupportTicketDto> {
        await Api.call("PATCH", Api.freelance + "support-tickets/\(id)/status", body: ["status": body.status])
    }

    /// The guided help menu (the whole active tree for freelancers, in the request's language).
    static func help() async -> ApiResult<SupportHelpDto> {
        await Api.call("GET", Api.freelance + "support-help")
    }

    /// What was pressed at the end of a help topic (counted on the dashboard).
    static func helpFeedback(_ id: Int, _ body: SupportAutoReplyFeedbackRequest) async -> ApiResult<Ignored> {
        await Api.call("POST", Api.freelance + "support-help/\(id)/feedback", body: ["solved": body.solved])
    }

    static func autoReplyFeedback(_ id: Int, _ body: SupportAutoReplyFeedbackRequest) async -> ApiResult<SupportTicketDto> {
        await Api.call("POST", Api.freelance + "support-tickets/\(id)/auto-reply-feedback", body: ["solved": body.solved])
    }

    /// Newest page first (`order=desc`): a long conversation opens at its end, older pages follow.
    static func messages(_ id: Int, page: Int = 1, perPage: Int = 30, order: String = "desc") async -> ApiResult<[SupportMessageDto]> {
        await Api.call("GET", Api.freelance + "support-tickets/\(id)/messages",
                       query: ["page": "\(page)", "per_page": "\(perPage)", "order": order])
    }

    /// Multipart: an optional `body` and an optional `image`.
    static func sendMessage(_ id: Int, body: String?, image: FilePart?) async -> ApiResult<SupportMessageDto> {
        await Api.upload(Api.freelance + "support-tickets/\(id)/messages", fields: body.map { ["body": $0] } ?? [:], files: image.map { [$0] } ?? [])
    }

    /// The Freelance service's legal page (`privacy` / `term`), public; the server falls back to the general page.
    static func legalPage(_ type: String) async -> ApiResult<LegalPageDto> {
        await Api.call("GET", Api.freelance + "legal-pages", query: ["type": type])
    }

    /// Settings > Security: the devices signed in on this account (any can be signed out, or all but this one) and the security log.
    static func securityDevices() async -> ApiResult<[SecurityDeviceDto]> {
        await Api.call("GET", Api.freelance + "security/devices")
    }

    static func signOutDevice(_ id: Int64) async -> ApiResult<SignOutResultDto> {
        await Api.call("DELETE", Api.freelance + "security/devices/\(id)")
    }

    static func signOutOtherDevices() async -> ApiResult<SignOutResultDto> {
        await Api.call("POST", Api.freelance + "security/devices/logout-others")
    }

    static func securityEvents(page: Int = 1, warnings: Int? = nil) async -> ApiResult<[SecurityEventDto]> {
        await Api.call("GET", Api.freelance + "security/events", query: ["page": "\(page)", "warnings": warnings.map { "\($0)" }])
    }

    /// The freelancer's own invite code (Account > Share the app with friends).
    static func myReferralCode() async -> ApiResult<MyReferralCodeDto> {
        await Api.call("GET", Api.freelance + "referrals/my-code")
    }

    static func faqs() async -> ApiResult<[FaqDto]> {
        await Api.call("GET", Api.freelance + "faqs")
    }

    /// One rating per freelancer: GET tells whether it exists, POST creates it or updates the same stars and comment.
    static func myRating() async -> ApiResult<MyAppRatingDto> {
        await Api.call("GET", Api.freelance + "ratings/mine")
    }

    static func submitRating(_ body: SubmitRatingRequest) async -> ApiResult<AppRatingDto> {
        await Api.call("POST", Api.freelance + "ratings", body: ["stars": body.stars, "comment": body.comment])
    }
}

// MARK: - DTOs

struct SupportLastMessageDto: Decodable, Equatable, Hashable {
    var sender: String?
    var body: String?
    var hasImage: Bool?
    var createdAt: String?
}

struct SupportTicketDto: Decodable, Identifiable, Equatable, Hashable {
    var id: Int?
    /// The random number the freelancer and support quote (the id stays internal).
    var number: String?
    var title: String?
    var body: String?
    var imageUrl: String?
    /// `opened`, `reopened`, `resolved` or `closed`.
    var status: String?
    var acceptsReplies: Bool?
    /// The person said an automatic answer was not enough: no more automatic replies.
    var autoReplyStopped: Bool?
    var lastMessage: SupportLastMessageDto?
    var lastMessageAt: String?
    var createdAt: String?

    /// What is shown as "#…": the ticket's number, or its id for an old payload without one.
    var displayNumber: String { number ?? "\(id ?? 0)" }
}

struct SupportMessageDto: Decodable, Identifiable, Equatable, Hashable {
    var id: Int?
    var ticketId: Int?
    /// `user` (me), `support` or `system` (an automatic reply).
    var sender: String?
    var body: String?
    var imageUrl: String?
    var agentName: String?
    var isAuto: Bool?
    /// `ack`, `away` or `faq`.
    var autoKind: String?
    var createdAt: String?
}

struct SupportStatusRequest { let status: String }
struct SupportAutoReplyFeedbackRequest { let solved: Bool }

struct SupportHelpDto: Decodable {
    var greeting: String?
    var items: [SupportHelpNodeDto]?
}

/// A topic: with `children` it is a menu, without any it is an answer.
struct SupportHelpNodeDto: Decodable, Identifiable, Equatable {
    var id: Int?
    var title: String?
    var answer: String?
    var children: [SupportHelpNodeDto]?
}

struct FaqDto: Decodable, Identifiable, Equatable {
    var id: Int?
    var question: String?
    var answer: String?
    var status: Bool?
    var sortOrder: Int?
}

struct LegalPageDto: Decodable {
    var id: Int?
    var type: String?
    var content: String?
    var status: Bool?
}

struct SubmitRatingRequest {
    let stars: Double
    var comment: String?
}

/// The app rating (not a project review).
struct AppRatingDto: Decodable {
    var id: Int?
    var stars: Double?
    var comment: String?
    var type: String?
    /// True for 4–5 stars: the app may show the store's in-app review.
    var promptStoreReview: Bool?
}

struct MyAppRatingDto: Decodable {
    var rated: Bool?
    var rating: AppRatingDto?
}

struct SecurityDeviceDto: Decodable, Identifiable, Equatable {
    var id: Int64?
    var deviceName: String?
    var platform: String?
    var platformLabel: String?
    var appVersion: String?
    var ip: String?
    var countryCode: String?
    var current: Bool?
    var signedInAt: String?
    var lastActiveAt: String?
}

struct SignOutResultDto: Decodable {
    var signedOutCurrent: Bool?
    var count: Int?
    var devices: [SecurityDeviceDto]?
}

struct SecurityEventDto: Decodable, Identifiable, Equatable {
    var id: Int64?
    var type: String?
    var label: String?
    var description: String?
    var warning: Bool?
    var deviceName: String?
    var platformLabel: String?
    var ip: String?
    var countryCode: String?
    var createdAt: String?
}

struct MyReferralCodeDto: Decodable { var code: String? }

/// A live event's JSON (`event.data["ticket"]`) read as a DTO with the API's snake_case decoder (Gson `fromJson` on Android).
func supportDecode<T: Decodable>(_ json: JSONAny?, as type: T.Type = T.self) -> T? {
    guard let json, json != .null, let data = try? JSONEncoder().encode(json) else { return nil }
    return try? Api.decoder.decode(T.self, from: data)
}

// MARK: - SupportClient

/// An answer in the shape the ported screens read: the data and (for a list) whether another page follows.
struct SupportEnvelope<T> {
    let data: T?
    var pagination: SupportPagination?
    var message: String = ""
}

struct SupportPagination { let hasMorePages: Bool }

/// A refused call: `message` is the server's own text in the request's language (nil for a connection problem or an
/// unreadable answer — the screen shows its own text).
struct SupportFailure: Error { let message: String? }

struct ApiFailure { let message: String? }

extension Error {
    /// The text of a failed `SupportClient` call (nil for a connection problem: the screen shows its own).
    func apiFailure() -> ApiFailure { ApiFailure(message: (self as? SupportFailure)?.message) }
}

/// `SupportApi` with the DORR app's calling style (return the answer, throw on a refusal) so the support screens read the
/// same as the DORR app's. A list answer carries `pagination.hasMorePages` (a full page means another one follows, as in
/// `PagedRows`).
enum SupportClient {
    static func listTickets(page: Int = 1, perPage: Int = 20) async throws -> SupportEnvelope<[SupportTicketDto]> {
        try list(perPage, await SupportApi.listTickets(page: page, perPage: perPage))
    }
    static func ticket(_ id: Int) async throws -> SupportEnvelope<SupportTicketDto> { try one(await SupportApi.ticket(id)) }
    static func createTicket(title: String, body: String, image: FilePart?) async throws -> SupportEnvelope<SupportTicketDto> {
        try one(await SupportApi.createTicket(title: title, body: body, image: image))
    }
    static func setStatus(_ id: Int, _ body: SupportStatusRequest) async throws -> SupportEnvelope<SupportTicketDto> {
        try one(await SupportApi.setStatus(id, body))
    }
    static func help() async throws -> SupportEnvelope<SupportHelpDto> { try one(await SupportApi.help()) }
    static func helpFeedback(_ id: Int, _ body: SupportAutoReplyFeedbackRequest) async throws -> SupportEnvelope<Ignored> {
        try one(await SupportApi.helpFeedback(id, body))
    }
    static func autoReplyFeedback(_ id: Int, _ body: SupportAutoReplyFeedbackRequest) async throws -> SupportEnvelope<SupportTicketDto> {
        try one(await SupportApi.autoReplyFeedback(id, body))
    }
    static func messages(_ id: Int, page: Int = 1, perPage: Int = 30) async throws -> SupportEnvelope<[SupportMessageDto]> {
        try list(perPage, await SupportApi.messages(id, page: page, perPage: perPage))
    }
    static func sendMessage(_ id: Int, body: String?, image: FilePart?) async throws -> SupportEnvelope<SupportMessageDto> {
        try one(await SupportApi.sendMessage(id, body: body, image: image))
    }
    static func faqs() async throws -> SupportEnvelope<[FaqDto]> { try one(await SupportApi.faqs()) }
    static func myReferralCode() async throws -> SupportEnvelope<MyReferralCodeDto> { try one(await SupportApi.myReferralCode()) }
    static func securityDevices() async throws -> SupportEnvelope<[SecurityDeviceDto]> { try list(Int.max, await SupportApi.securityDevices()) }
    static func signOutDevice(_ id: Int64) async throws -> SupportEnvelope<SignOutResultDto> { try one(await SupportApi.signOutDevice(id)) }
    static func signOutOtherDevices() async throws -> SupportEnvelope<SignOutResultDto> { try one(await SupportApi.signOutOtherDevices()) }
    static func securityEvents(page: Int, warnings: Bool) async throws -> SupportEnvelope<[SecurityEventDto]> {
        try list(20, await SupportApi.securityEvents(page: page, warnings: warnings ? 1 : nil))
    }
    static func legalPage(_ type: String) async throws -> SupportEnvelope<LegalPageDto> { try one(await SupportApi.legalPage(type)) }
    static func myRating() async throws -> SupportEnvelope<MyAppRatingDto> { try one(await SupportApi.myRating()) }
    static func submitRating(_ body: SubmitRatingRequest) async throws -> SupportEnvelope<AppRatingDto> { try one(await SupportApi.submitRating(body)) }

    private static func one<T>(_ result: ApiResult<T>) throws -> SupportEnvelope<T> {
        switch result {
        case .ok(let data, let message):
            return SupportEnvelope(data: data, pagination: nil, message: message ?? "")
        case .offline:
            throw SupportFailure(message: nil)
        case .failed(let error):
            // A 2xx without `data` (Retrofit hands it over with a null body): the answer, with no data.
            if error.httpStatus == 200 { return SupportEnvelope(data: nil, pagination: nil, message: error.message) }
            // An answer the app cannot read (Gson would throw): no server text to show.
            if error.httpStatus == 0 { throw SupportFailure(message: nil) }
            let text = error.displayMessage.trimmingCharacters(in: .whitespacesAndNewlines)
            throw SupportFailure(message: text.isEmpty ? nil : text)
        }
    }

    private static func list<T>(_ perPage: Int, _ result: ApiResult<[T]>) throws -> SupportEnvelope<[T]> {
        let answer = try one(result)
        let rows = answer.data ?? []
        return SupportEnvelope(data: rows, pagination: SupportPagination(hasMorePages: rows.count >= perPage), message: answer.message)
    }
}
