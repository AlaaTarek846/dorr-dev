import Combine
import Foundation

// freelanceApp `net/CommunityApi.kt`.

/**
 * FL-L12, the professional community under `/api/freelance/v1/community`: a LinkedIn-like feed for freelancers only (per country), posts
 * (a post, a question, or a finished work from my portfolio) with up to 4 images, comments with one level of replies, reactions, saves,
 * follows and reports. Reading is open to any freelancer; writing needs a verified account and no mute from the staff. Lists page with a
 * `cursor`: the answer carries `next_cursor` / `has_more` next to `data` (see [cursorCall]). The switch is the country feature `community`
 * (`freelance_feature_off` when it is off).
 */
enum CommunityApi {
    private static let base = Api.freelance + "community"

    static func home() async -> ApiResult<CommunityHomeDto> { await Api.call("GET", base) }

    /// `tab`: for_you · following · specialty · mine · saved; `kind`: post · question · work; `member`: one member's posts.
    static func feed(tab: String? = nil, kind: String? = nil, specialtyId: Int? = nil, search: String? = nil, member: String? = nil,
                     cursor: String? = nil, perPage: Int? = 15) async -> ApiResult<CursorPage<[CommunityPostDto]>> {
        await cursorCall(base + "/feed", query: [
            "tab": tab, "kind": kind, "specialty_id": specialtyId.map(String.init), "search": search, "member": member,
            "cursor": cursor, "per_page": perPage.map(String.init),
        ])
    }

    /// The post and its first comments (the best answer first, then the oldest), each with its first replies.
    static func post(_ uuid: String) async -> ApiResult<CursorPage<CommunityPostDetailDto>> { await cursorCall(base + "/posts/\(uuid)") }

    /// Multipart: `kind`, `body`, `specialty_id?`, `portfolio_item?` (kind work) as [fields]; up to 4 `images[]`.
    static func create(_ fields: [String: String], _ images: [FilePart]) async -> ApiResult<CommunityPostDto> {
        await Api.upload(base + "/posts", fields: fields, files: images)
    }

    static func update(_ uuid: String, _ body: CommunityPostUpdate) async -> ApiResult<CommunityPostDto> {
        await Api.call("PUT", base + "/posts/\(uuid)", body: body.json)
    }

    static func delete(_ uuid: String, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base + "/posts/\(uuid)", offline: offline, generic: generic)
    }

    static func comments(_ uuid: String, cursor: String? = nil) async -> ApiResult<CursorPage<[CommunityCommentDto]>> {
        await cursorCall(base + "/posts/\(uuid)/comments", query: ["cursor": cursor])
    }

    static func comment(_ uuid: String, _ body: CommunityCommentRequest) async -> ApiResult<CommunityCommentDto> {
        await Api.call("POST", base + "/posts/\(uuid)/comments", body: body.json)
    }

    /// A question's best answer (`comment` nil takes the mark off). The question's author only.
    static func accept(_ uuid: String, _ body: CommunityAcceptRequest) async -> ApiResult<CommunityPostDto> {
        await Api.call("POST", base + "/posts/\(uuid)/accept", body: body.json)
    }

    static func save(_ uuid: String) async -> ApiResult<CommunitySavedDto> { await Api.call("POST", base + "/posts/\(uuid)/save") }

    static func unsave(_ uuid: String) async -> ApiResult<CommunitySavedDto> { await Api.call("DELETE", base + "/posts/\(uuid)/save") }

    /// [type]: `posts` · `comments`; `kind` nil takes my reaction back.
    static func react(_ type: String, _ uuid: String, _ body: CommunityReactionRequest) async -> ApiResult<CommunityReactionDto> {
        await Api.call("PUT", base + "/\(type)/\(uuid)/reaction", body: body.json)
    }

    static func replies(_ uuid: String, cursor: String? = nil) async -> ApiResult<CursorPage<[CommunityCommentDto]>> {
        await cursorCall(base + "/comments/\(uuid)/replies", query: ["cursor": cursor])
    }

    static func updateComment(_ uuid: String, _ body: CommunityCommentRequest) async -> ApiResult<CommunityCommentDto> {
        await Api.call("PUT", base + "/comments/\(uuid)", body: body.json)
    }

    static func deleteComment(_ uuid: String, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base + "/comments/\(uuid)", offline: offline, generic: generic)
    }

    static func member(_ uuid: String) async -> ApiResult<CommunityMemberDto> { await Api.call("GET", base + "/members/\(uuid)") }

    static func follow(_ uuid: String) async -> ApiResult<CommunityMemberDto> { await Api.call("POST", base + "/members/\(uuid)/follow") }

    static func unfollow(_ uuid: String) async -> ApiResult<CommunityMemberDto> { await Api.call("DELETE", base + "/members/\(uuid)/follow") }

    /// [which]: `followers` · `following`.
    static func people(_ uuid: String, _ which: String, cursor: String? = nil) async -> ApiResult<CursorPage<[CommunityAuthorDto]>> {
        await cursorCall(base + "/members/\(uuid)/\(which)", query: ["cursor": cursor])
    }

    /// 409 `freelance_community_report_exists` when already reported; 422 `freelance_community_report_own` on my own.
    static func report(_ body: CommunityReportRequest) async -> ApiResult<CommunityReportDto> {
        await Api.call("POST", base + "/reports", body: body.json)
    }
}

// MARK: - DTOs

struct CommunityHomeDto: Decodable, Equatable {
    var me: CommunityMemberDto?
    var canWrite: Bool?
    var muted: CommunityMuteDto?
    var limits: CommunityLimitsDto?
    var kinds: [CommunityKeyLabel]?
    var reactions: [CommunityKeyLabel]?
    var reportReasons: [CommunityKeyLabel]?
    var rules: [String]?
    var specialties: [CommunitySpecialtyDto]?
    var mySpecialties: [Int]?
}

struct CommunityMuteDto: Decodable, Equatable {
    var until: String?
    var reason: String?
}

struct CommunityLimitsDto: Decodable, Equatable {
    var bodyMin: Int?
    var bodyMax: Int?
    var commentMax: Int?
    var maxImages: Int?
    var postsPerDay: Int?
    var postsToday: Int?
    var commentsPerDay: Int?
    var commentsToday: Int?
}

struct CommunityKeyLabel: Decodable, Equatable {
    var key: String?
    var label: String?
}

struct CommunitySpecialtyDto: Decodable, Equatable {
    var id: Int?
    var name: String?
    var primary: Bool?
}

/// The plan badge (`nil` = the member's plan has none): `plan` key and its name in my language.
struct CommunityBadgeDto: Decodable, Equatable {
    var plan: String?
    var name: String?
}

/// A member's card (`GET members/{uuid}`, the follow answer, and `me` in the home).
struct CommunityMemberDto: Decodable, Equatable {
    var uuid: String?
    var name: String?
    var headline: String?
    var bio: String?
    var avatarUrl: String?
    var badge: CommunityBadgeDto?
    var verified: Bool?
    var specialties: [CommunitySpecialtyDto]?
    var posts: Int?
    var followers: Int?
    var following: Int?
    var isMe: Bool?
    var isFollowing: Bool?
    /// In the home's `me` only.
    var saved: Int?
}

/// Who wrote a post or a comment (and a row of followers / following). [isFollowing] is nil where the server does not say.
struct CommunityAuthorDto: Decodable, Equatable {
    var uuid: String?
    var name: String?
    var headline: String?
    var avatarUrl: String?
    var badge: CommunityBadgeDto?
    var isMe: Bool?
    var isFollowing: Bool?
}

struct CommunityImageDto: Decodable, Equatable {
    var id: Int?
    var url: String?
}

/// The finished work a `work` post shares (from the author's portfolio).
struct CommunityWorkDto: Decodable, Equatable {
    var uuid: String?
    var title: String?
    var imageUrl: String?
}

struct CommunityHiddenDto: Decodable, Equatable {
    var reason: String?
    var underReview: Bool?
}

struct CommunityPostCan: Decodable, Equatable {
    var edit: Bool?
    var delete: Bool?
    var report: Bool?
    var accept: Bool?
}

struct CommunityPostDto: Decodable, Equatable {
    var uuid: String?
    /// post · question · work
    var kind: String?
    var kindLabel: String?
    var body: String?
    var author: CommunityAuthorDto?
    var specialty: CommunitySpecialtyDto?
    var images: [CommunityImageDto]?
    var work: CommunityWorkDto?
    var reactions: Int?
    var topReactions: [String]?
    var myReaction: String?
    var comments: Int?
    var saved: Bool?
    /// The uuid of a question's best answer.
    var acceptedComment: String?
    /// Shown to the author only: why it is hidden, or that it waits for the staff after reports.
    var hidden: CommunityHiddenDto?
    var edited: Bool?
    var createdAt: String?
    var can: CommunityPostCan?
}

struct CommunityCommentCan: Decodable, Equatable {
    var edit: Bool?
    var delete: Bool?
    var report: Bool?
}

struct CommunityCommentDto: Decodable, Equatable {
    var uuid: String?
    var body: String?
    var author: CommunityAuthorDto?
    /// The uuid of the comment this replies to (one level only).
    var parent: String?
    var reactions: Int?
    var myReaction: String?
    var replies: Int?
    var accepted: Bool?
    var hidden: CommunityHiddenDto?
    var edited: Bool?
    var createdAt: String?
    var can: CommunityCommentCan?
    /// Up to three, on a comment of the post's page.
    var firstReplies: [CommunityCommentDto]?
}

struct CommunityPostDetailDto: Decodable, Equatable {
    var post: CommunityPostDto?
    var comments: [CommunityCommentDto]?
}

struct CommunityReactionDto: Decodable, Equatable {
    var reactions: Int?
    var myReaction: String?
    var top: [String]?
}

struct CommunitySavedDto: Decodable, Equatable {
    var saved: Bool?
}

struct CommunityReportDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
}

/// Like Gson, nil fields are left out: a field left nil is not changed.
struct CommunityPostUpdate {
    var body: String?
    var specialtyId: Int?
    var removeImages: [Int]?

    var json: JsonBody {
        var out: JsonBody = [:]
        if let body { out["body"] = body }
        if let specialtyId { out["specialty_id"] = specialtyId }
        if let removeImages { out["remove_images"] = removeImages }
        return out
    }
}

struct CommunityCommentRequest {
    let body: String
    var parent: String?

    var json: JsonBody {
        var out: JsonBody = ["body": body]
        if let parent { out["parent"] = parent }
        return out
    }
}

/// A nil [comment] is left out, which the server reads as "take the mark off".
struct CommunityAcceptRequest {
    let comment: String?

    var json: JsonBody { comment.map { ["comment": $0] } ?? [:] }
}

/// A nil [kind] is left out, which the server reads as "take my reaction back".
struct CommunityReactionRequest {
    let kind: String?

    var json: JsonBody { kind.map { ["kind": $0] } ?? [:] }
}

struct CommunityReportRequest {
    let subjectType: String
    let subject: String
    let reason: String
    var details: String?

    var json: JsonBody {
        var out: JsonBody = ["subject_type": subjectType, "subject": subject, "reason": reason]
        if let details { out["details"] = details }
        return out
    }
}

// MARK: - cursor pages

/// The envelope of a cursor page: `next_cursor` / `has_more` sit next to `data` (ApiResponse merges its meta into the root).
struct CursorEnvelope<T: Decodable>: Decodable {
    var success: Bool?
    var message: String?
    var data: T?
    var nextCursor: String?
    var hasMore: Bool?
}

/// One page and where the next one starts (nil: this was the last).
struct CursorPage<T> {
    let data: T
    let next: String?
    let hasMore: Bool
}

/**
 * [Api.call] for a cursor page (GET): the data with its cursor, or what went wrong. The shared client only hands back an envelope's
 * `data`, so the page is read raw (same headers, token and 401 / gate handling); a refusal is read once more through [Api.call] for the
 * server's message and `error_code` (rare: the feature off, a post gone).
 */
func cursorCall<T: Decodable>(_ path: String, query: [String: String?] = [:]) async -> ApiResult<CursorPage<T>> {
    switch await Api.raw(path, query: query) {
    case .failure(let failure):
        if failure.status == 0 { return .offline }
        // The refusal's own message and error_code (one request, as on Android).
        return .failed(Api.parseError(failure.status, failure.body, nil))
    case .success(let raw):
        guard let body = try? Api.decoder.decode(CursorEnvelope<T>.self, from: raw) else {
            return .failed(ApiError(httpStatus: 0, message: "Unreadable response", errorCode: nil, fieldErrors: [:], data: nil))
        }
        if body.success == true, let data = body.data {
            let next = body.nextCursor.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 }
            return .ok(CursorPage(data: data, next: next, hasMore: (body.hasMore ?? (next != nil)) && next != nil), message: body.message)
        }
        return .failed(Api.parseError(200, raw, body.message))
    }
}

/**
 * A list that fills itself page by page with the server's cursor while the freelancer scrolls (nothing on a timer). [reload] reads the
 * first page and replaces what is shown only once it arrives (a pull-to-refresh keeps the rows meanwhile); [loadMore] adds the next page.
 * One request at a time; a row that moved between pages is not shown twice.
 */
@MainActor
final class CursorRows<T>: ObservableObject {
    @Published private(set) var rows: [T] = []
    /// The first page has been answered (rows or an error).
    @Published private(set) var loaded = false
    @Published private(set) var loading = false
    /// A reload of rows already shown (pull-to-refresh).
    @Published private(set) var refreshing = false
    @Published private(set) var hasMore = false
    @Published private(set) var failure: ApiResult<CursorPage<[T]>>?

    private let key: (T) -> AnyHashable
    /// What reads a page from a cursor (a screen whose filter lives in its view model sets it once that exists).
    var fetch: @MainActor (String?) async -> ApiResult<CursorPage<[T]>>
    private var cursor: String?
    /// Bumped by [reset]: an answer for an older filter is dropped.
    private var generation = 0

    init(key: @escaping (T) -> AnyHashable, fetch: @escaping @MainActor (String?) async -> ApiResult<CursorPage<[T]>> = { _ in .offline }) {
        self.key = key
        self.fetch = fetch
    }

    func reload() async {
        if loading { return }
        refreshing = loaded
        await load(nil, first: true)
        refreshing = false
    }

    /// Back to "nothing loaded" (a new filter): the screen shows its skeleton until [reload] answers.
    func reset() {
        generation += 1
        rows.removeAll()
        loaded = false
        hasMore = false
        failure = nil
        cursor = nil
        loading = false
        refreshing = false
    }

    func loadMore() async {
        if loaded && hasMore && failure == nil && !loading { await load(cursor, first: false) }
    }

    func retry() { failure = nil }

    private func load(_ from: String?, first: Bool) async {
        let gen = generation
        loading = true
        let result = await fetch(from)
        guard gen == generation else { return }
        switch result {
        case .ok(let page, _):
            failure = nil
            var now = first ? [] : rows
            for row in page.data where !now.contains(where: { key($0) == key(row) }) { now.append(row) }
            rows = now
            cursor = page.next
            hasMore = page.hasMore
        default:
            failure = result
        }
        loaded = true
        loading = false
    }

    func update(where match: (T) -> Bool, _ change: (T) -> T) {
        rows = rows.map { match($0) ? change($0) : $0 }
    }

    func remove(where match: (T) -> Bool) { rows.removeAll(where: match) }

    func prepend(_ row: T) {
        rows.removeAll { key($0) == key(row) }
        rows.insert(row, at: 0)
    }
}

// MARK: - shared state

/// Something one screen changed that the others showing the same post or member should follow (no reload needed).
enum CommunityChange {
    case postCreated(CommunityPostDto)
    case postUpdated(CommunityPostDto)
    case postDeleted(String)
    case followChanged(member: String, following: Bool)
}

/**
 * What the community screens share: the home answer (my card, the limits, the reasons, the rules…) read once when the app's home opens
 * (and when the connection returns or after my own actions), whether the feature is on in my country ([available]: nil until known),
 * and the stream of [CommunityChange]s.
 */
@MainActor
final class CommunityHub: ObservableObject {
    static let shared = CommunityHub()

    @Published private(set) var home: CommunityHomeDto?
    @Published private(set) var available: Bool?
    let changes = PassthroughSubject<CommunityChange, Never>()

    @discardableResult
    func refresh() async -> ApiResult<CommunityHomeDto> {
        let result = await CommunityApi.home()
        switch result {
        case .ok(let data, _):
            home = data
            available = true
        case .failed(let e):
            if e.errorCode == "freelance_feature_off" {
                available = false
                home = nil
            }
        case .offline:
            break
        }
        return result
    }

    func emit(_ change: CommunityChange) { changes.send(change) }

    func clear() {
        home = nil
        available = nil
    }

    private static let rulesKey = "dorr_freelance_community.rules_seen"

    /// The community rules are shown once, the first time the composer opens.
    func rulesSeen() -> Bool { UserDefaults.standard.bool(forKey: Self.rulesKey) }

    func markRulesSeen() { UserDefaults.standard.set(true, forKey: Self.rulesKey) }
}

/// My reaction moved from [before] to [after]: the count and the top icons as they will be, before the server answers.
func optimisticReaction(count: Int, top: [String], before: String?, after: String?) -> (Int, [String]) {
    let n: Int
    if before == nil && after != nil { n = count + 1 }
    else if before != nil && after == nil { n = max(count - 1, 0) }
    else { n = count }
    let icons: [String]
    if n == 0 { icons = [] }
    else if let after, !top.contains(after) { icons = Array(([after] + top).prefix(3)) }
    else { icons = top }
    return (n, icons)
}
