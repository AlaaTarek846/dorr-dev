import Foundation

/// androidApp `network/WatchEarnApi.kt` — Watch & Earn (docs/modules/watch-earn/API.md) under /api/mobile/v1/watch-earn:
/// the viewer (feed, the watching session, comments, reactions, reports, my rewards), the advertiser (the wizard's
/// options, my ads, one ad's stats and its actions) and the ad wallet (fund / return, each previewed first).
///
/// Money is always an integer in minor units with the `currency.decimals` sent next to it. These requests carry the
/// wallet's `X-Country` like [WalletApi] (see `WalletCountry.headerFor`).
enum WatchEarnApi {
    // MARK: - the viewer

    /// `{enabled, player: {mode, image_seconds, interaction_window_minutes}, ads}` — my city's ads first.
    static func feed() async throws -> ApiEnvelope<WeFeedDto> {
        try await ApiClient.get("mobile/v1/watch-earn/feed", auth: true)
    }

    /// One ad I already watched or commented on (opened from a push): `{ad, watched, can_interact, interaction_deadline_at}`.
    /// No session, no reward; anyone else gets `not_found` (404).
    static func feedAd(_ ad: String) async throws -> ApiEnvelope<WeAdPageDto> {
        try await ApiClient.get("mobile/v1/watch-earn/ads/\(ad)", auth: true)
    }

    /// Fails with `watch_earn_not_eligible` (403) or `watch_earn_already_watched` (409).
    static func start(_ ad: String, _ body: WeStartRequest = WeStartRequest()) async throws -> ApiEnvelope<WeStartDto> {
        try await ApiClient.post("mobile/v1/watch-earn/ads/\(ad)/start", body: body, auth: true)
    }

    /// `watch_earn_too_fast` (422) when less than `seconds` passed since start; `watch_earn_ad_unavailable` (409). Safe to repeat.
    static func complete(_ view: String) async throws -> ApiEnvelope<WeCompleteDto> {
        try await ApiClient.send("mobile/v1/watch-earn/views/\(view)/complete", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    static func comments(_ ad: String, cursor: String? = nil) async throws -> ApiEnvelope<WeCommentsDto> {
        try await ApiClient.get("mobile/v1/watch-earn/ads/\(ad)/comments", query: ["cursor": cursor], auth: true)
    }

    /// Only after watching the ad to the end (`watch_earn_watch_first`).
    static func comment(_ ad: String, _ body: WeCommentRequest) async throws -> ApiEnvelope<WeCommentDto> {
        try await ApiClient.post("mobile/v1/watch-earn/ads/\(ad)/comments", body: body, auth: true)
    }

    /// Answers 204 with no body: an empty answer is a success; a refusal still throws [ApiError].
    static func deleteComment(_ ad: String, _ id: Int) async throws {
        do {
            let _: ApiEnvelope<Ignored> = try await ApiClient.delete("mobile/v1/watch-earn/ads/\(ad)/comments/\(id)")
        } catch is DecodingError {
            // 204: nothing to read.
        }
    }

    static func react(_ ad: String, _ body: WeReactionRequest) async throws -> ApiEnvelope<WeReactionsDto> {
        try await ApiClient.post("mobile/v1/watch-earn/ads/\(ad)/reaction", body: body, auth: true)
    }

    static func unreact(_ ad: String) async throws -> ApiEnvelope<WeReactionsDto> {
        try await ApiClient.delete("mobile/v1/watch-earn/ads/\(ad)/reaction")
    }

    /// `reason`: misleading, offensive, adult, violence, scam, other. One report per viewer.
    static func reportAd(_ ad: String, _ body: WeReportRequest) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.post("mobile/v1/watch-earn/ads/\(ad)/report", body: body, auth: true)
    }

    static func rewards(cursor: String? = nil) async throws -> ApiEnvelope<WeRewardsDto> {
        try await ApiClient.get("mobile/v1/watch-earn/rewards", query: ["cursor": cursor], auth: true)
    }

    // MARK: - the advertiser

    static func options() async throws -> ApiEnvelope<WeOptionsDto> {
        try await ApiClient.get("mobile/v1/watch-earn/advertiser/options", auth: true)
    }

    static func audience(_ body: WeAudienceRequest) async throws -> ApiEnvelope<WeAudienceDto> {
        try await ApiClient.post("mobile/v1/watch-earn/advertiser/audience", body: body, auth: true)
    }

    static func ads(status: String? = nil, page: Int = 1) async throws -> ApiEnvelope<[WeAdDto]> {
        try await ApiClient.get("mobile/v1/watch-earn/advertiser/ads", query: ["status": status, "page": String(page)], auth: true)
    }

    /// Multipart: `media`, `media_seconds?`, `title`, `body?`, `link_url?`, `cities[]?`, `target_gender?`,
    /// `target_locale?`, `starts_at`, `ends_at`, `budget_minor`. Reserves the budget; the ad waits for the review.
    static func createAd(fields: [String: String], files: [ApiClient.FilePart]) async throws -> ApiEnvelope<WeAdDto> {
        try await ApiClient.upload("mobile/v1/watch-earn/advertiser/ads", fields: fields, files: files)
    }

    static func ad(_ ad: String) async throws -> ApiEnvelope<WeAdDto> {
        try await ApiClient.get("mobile/v1/watch-earn/advertiser/ads/\(ad)", auth: true)
    }

    static func stats(_ ad: String) async throws -> ApiEnvelope<WeStatsResponseDto> {
        try await ApiClient.get("mobile/v1/watch-earn/advertiser/ads/\(ad)/stats", auth: true)
    }

    /// The stats as a file (`format`: pdf / xlsx), sent as an attachment — the raw bytes and the answer (its
    /// `Content-Disposition`), not the JSON envelope.
    static func export(_ ad: String, format: String) async throws -> (Data, HTTPURLResponse?) {
        try await ApiClient.rawResponse("mobile/v1/watch-earn/advertiser/ads/\(ad)/export", query: ["format": format], auth: true)
    }

    /// A content edit (multipart, the file optional): the ad goes back to the review.
    static func updateAd(_ ad: String, fields: [String: String], files: [ApiClient.FilePart]) async throws -> ApiEnvelope<WeAdDto> {
        try await ApiClient.upload("mobile/v1/watch-earn/advertiser/ads/\(ad)", fields: fields, files: files)
    }

    static func addBudget(_ ad: String, _ body: WeAmountRequest) async throws -> ApiEnvelope<WeAdDto> {
        try await ApiClient.post("mobile/v1/watch-earn/advertiser/ads/\(ad)/budget", body: body, auth: true)
    }

    static func extend(_ ad: String, _ body: WeExtendRequest) async throws -> ApiEnvelope<WeAdDto> {
        try await ApiClient.post("mobile/v1/watch-earn/advertiser/ads/\(ad)/extend", body: body, auth: true)
    }

    static func pause(_ ad: String) async throws -> ApiEnvelope<WeAdDto> {
        try await ApiClient.send("mobile/v1/watch-earn/advertiser/ads/\(ad)/pause", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    static func resume(_ ad: String) async throws -> ApiEnvelope<WeAdDto> {
        try await ApiClient.send("mobile/v1/watch-earn/advertiser/ads/\(ad)/resume", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    /// Permanent: the rest of the budget goes back to the free balance.
    static func stop(_ ad: String) async throws -> ApiEnvelope<WeAdDto> {
        try await ApiClient.send("mobile/v1/watch-earn/advertiser/ads/\(ad)/stop", method: "POST", body: Optional<Empty>.none, auth: true)
    }

    /// `reason`: offensive, spam, misleading, harassment, other. The advertiser reports, never deletes (D21).
    static func reportComment(_ ad: String, _ comment: Int, _ body: WeReportRequest) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.post("mobile/v1/watch-earn/advertiser/ads/\(ad)/comments/\(comment)/report", body: body, auth: true)
    }

    // MARK: - the ad wallet

    /// Free / locked by bucket, the ads holding money, the main wallet by bucket, and the statement (cursor).
    static func adWallet(cursor: String? = nil) async throws -> ApiEnvelope<WeAdWalletDto> {
        try await ApiClient.get("mobile/v1/watch-earn/ad-wallet", query: ["cursor": cursor], auth: true)
    }

    static func fundPreview(_ body: WeAmountRequest) async throws -> ApiEnvelope<WeFundPreviewDto> {
        try await ApiClient.post("mobile/v1/watch-earn/ad-wallet/fund/preview", body: body, auth: true)
    }

    /// Needs the wallet PIN; the `idempotency_key` is made once per attempt. Answers the ad wallet again.
    static func fund(pin: String, _ body: WeMoveRequest) async throws -> ApiEnvelope<WeAdWalletDto> {
        try await ApiClient.post("mobile/v1/watch-earn/ad-wallet/fund", body: body, auth: true, headers: ["X-Wallet-Pin": pin])
    }

    static func returnPreview(_ body: WeAmountRequest) async throws -> ApiEnvelope<WeReturnPreviewDto> {
        try await ApiClient.post("mobile/v1/watch-earn/ad-wallet/return/preview", body: body, auth: true)
    }

    /// The free balance only; what ads hold comes back when they stop or end.
    static func returnToWallet(_ body: WeMoveRequest) async throws -> ApiEnvelope<WeAdWalletDto> {
        try await ApiClient.post("mobile/v1/watch-earn/ad-wallet/return", body: body, auth: true)
    }
}

// MARK: - shared (CodingKeys = @SerializedName; Kotlin defaults kept through `value(_:_:)`)

/// Every response that shows money sends its currency: the code and how many decimals a minor unit has.
struct WeCurrencyDto: Codable, Equatable {
    var code: String?
    var decimals: Int

    init(code: String? = nil, decimals: Int = 2) {
        self.code = code
        self.decimals = decimals
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        code = c.value(.code, nil)
        decimals = c.value(.decimals, 2)
    }
}

/// An amount split by the wallet bucket it came from (reward → spend_only → withdrawable).
struct WeBucketsDto: Codable, Equatable {
    var rewardMinor: Int
    var spendOnlyMinor: Int
    var withdrawableMinor: Int

    var totalMinor: Int { rewardMinor + spendOnlyMinor + withdrawableMinor }

    enum CodingKeys: String, CodingKey {
        case rewardMinor = "reward_minor", spendOnlyMinor = "spend_only_minor", withdrawableMinor = "withdrawable_minor"
    }

    init(rewardMinor: Int = 0, spendOnlyMinor: Int = 0, withdrawableMinor: Int = 0) {
        self.rewardMinor = rewardMinor
        self.spendOnlyMinor = spendOnlyMinor
        self.withdrawableMinor = withdrawableMinor
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        rewardMinor = c.value(.rewardMinor, 0)
        spendOnlyMinor = c.value(.spendOnlyMinor, 0)
        withdrawableMinor = c.value(.withdrawableMinor, 0)
    }
}

struct WeAdRefDto: Codable, Equatable {
    var id: String
    var title: String?

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, "")
        title = c.value(.title, nil)
    }
}

struct WeMediaDto: Codable, Equatable {
    /// image / video
    var type: String
    var url: String?
    var seconds: Int?
    var size: Int?
    var version: Int?

    init(type: String = "image", url: String? = nil) {
        self.type = type
        self.url = url
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        type = c.value(.type, "image")
        url = c.value(.url, nil)
        seconds = c.value(.seconds, nil)
        size = c.value(.size, nil)
        version = c.value(.version, nil)
    }
}

struct WeCountsDto: Codable, Equatable {
    var impressions = 0
    var completed = 0
    var interactions = 0
    var comments = 0
    var reactions = 0
    var reports = 0

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        impressions = c.value(.impressions, 0)
        completed = c.value(.completed, 0)
        interactions = c.value(.interactions, 0)
        comments = c.value(.comments, 0)
        reactions = c.value(.reactions, 0)
        reports = c.value(.reports, 0)
    }
}

// MARK: - the viewer

struct WeFeedDto: Codable, Equatable {
    var enabled: Bool
    var player: WePlayerDto
    var ads: [WeFeedAdDto]

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        enabled = c.value(.enabled, false)
        player = c.value(.player, WePlayerDto())
        ads = c.value(.ads, [])
    }
}

struct WePlayerDto: Codable, Equatable {
    /// auto: on to the next ad by itself; manual: the ad closes at the end and a tap goes on.
    var mode = "auto"
    var imageSeconds = 8
    var interactionWindowMinutes = 10

    enum CodingKeys: String, CodingKey {
        case mode
        case imageSeconds = "image_seconds", interactionWindowMinutes = "interaction_window_minutes"
    }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        mode = c.value(.mode, "auto")
        imageSeconds = c.value(.imageSeconds, 8)
        interactionWindowMinutes = c.value(.interactionWindowMinutes, 10)
    }
}

struct WeFeedAdDto: Codable, Equatable, Identifiable {
    var id: String
    var title: String
    var body: String?
    var linkUrl: String?
    var media: WeMediaDto
    var advertiser: WeAdvertiserDto?
    var inMyCity: Bool?
    var currency: WeCurrencyDto
    var earn: WeEarnDto
    var counts: WeCountsDto

    enum CodingKeys: String, CodingKey {
        case id, title, body, media, advertiser, currency, earn, counts
        case linkUrl = "link_url", inMyCity = "in_my_city"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, "")
        title = c.value(.title, "")
        body = c.value(.body, nil)
        linkUrl = c.value(.linkUrl, nil)
        media = c.value(.media, WeMediaDto())
        advertiser = c.value(.advertiser, nil)
        inMyCity = c.value(.inMyCity, nil)
        currency = c.value(.currency, WeCurrencyDto())
        earn = c.value(.earn, WeEarnDto())
        counts = c.value(.counts, WeCountsDto())
    }
}

struct WeAdvertiserDto: Codable, Equatable {
    var name: String?
    var avatar: String?
}

/// What the viewer earns: for watching to the end, and for the first comment or reaction in the window.
struct WeEarnDto: Codable, Equatable {
    var viewMinor = 0
    var interactionMinor = 0

    enum CodingKeys: String, CodingKey {
        case viewMinor = "view_minor", interactionMinor = "interaction_minor"
    }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        viewMinor = c.value(.viewMinor, 0)
        interactionMinor = c.value(.interactionMinor, 0)
    }
}

/// `platform`: android on Android, ios here (the advertiser's «by platform» chart).
struct WeStartRequest: Encodable {
    var platform = "ios"
}

struct WeStartDto: Codable, Equatable {
    var viewId: String
    var startedAt: String?
    var seconds: Int?

    enum CodingKeys: String, CodingKey {
        case seconds
        case viewId = "view_id", startedAt = "started_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        // The id may come as a number or a string.
        if let s = try? c.decode(String.self, forKey: .viewId) { viewId = s } else { viewId = (try? c.decode(Int.self, forKey: .viewId)).map(String.init) ?? "" }
        startedAt = c.value(.startedAt, nil)
        seconds = c.value(.seconds, nil)
    }
}

struct WeCompleteDto: Codable, Equatable {
    var viewId: String?
    var completedAt: String?
    var interactionDeadlineAt: String?
    var canInteract: Bool

    enum CodingKeys: String, CodingKey {
        case viewId = "view_id", completedAt = "completed_at", interactionDeadlineAt = "interaction_deadline_at", canInteract = "can_interact"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        viewId = (try? c.decode(String.self, forKey: .viewId)) ?? (try? c.decode(Int.self, forKey: .viewId)).map(String.init)
        completedAt = c.value(.completedAt, nil)
        interactionDeadlineAt = c.value(.interactionDeadlineAt, nil)
        canInteract = c.value(.canInteract, false)
    }
}

/// `GET ads/{ad}`: the ad as the feed shows it, whether I watched it, and whether interacting still earns.
struct WeAdPageDto: Codable, Equatable {
    var ad: WeFeedAdDto
    var watched: Bool
    var canInteract: Bool
    var interactionDeadlineAt: String?

    enum CodingKeys: String, CodingKey {
        case ad, watched
        case canInteract = "can_interact", interactionDeadlineAt = "interaction_deadline_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ad = try c.decode(WeFeedAdDto.self, forKey: .ad)
        watched = c.value(.watched, false)
        canInteract = c.value(.canInteract, false)
        interactionDeadlineAt = c.value(.interactionDeadlineAt, nil)
    }
}

struct WeCommentUserDto: Codable, Equatable {
    var id: Int
    var name: String?
    var avatar: String?

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        name = c.value(.name, nil)
        avatar = c.value(.avatar, nil)
    }
}

struct WeCommentDto: Codable, Equatable, Identifiable {
    var id: Int
    var body: String
    var createdAt: String?
    var user: WeCommentUserDto?

    enum CodingKeys: String, CodingKey {
        case id, body, user
        case createdAt = "created_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        body = c.value(.body, "")
        createdAt = c.value(.createdAt, nil)
        user = c.value(.user, nil)
    }
}

struct WeCommentsDto: Codable, Equatable {
    var comments: [WeCommentDto]
    var nextCursor: String?
    /// emoji → count (an empty PHP array arrives as `[]`, which reads as an empty map).
    var reactions: [String: Int]
    var myReaction: String?

    enum CodingKeys: String, CodingKey {
        case comments, reactions
        case nextCursor = "next_cursor", myReaction = "my_reaction"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        comments = c.value(.comments, [])
        nextCursor = c.value(.nextCursor, nil)
        reactions = c.value(.reactions, [:])
        myReaction = c.value(.myReaction, nil)
    }
}

struct WeCommentRequest: Encodable {
    var body: String
}

struct WeReactionRequest: Encodable {
    var emoji: String
}

struct WeReactionsDto: Codable, Equatable {
    var reactions: [String: Int]
    var myReaction: String?

    enum CodingKeys: String, CodingKey {
        case reactions
        case myReaction = "my_reaction"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        reactions = c.value(.reactions, [:])
        myReaction = c.value(.myReaction, nil)
    }
}

struct WeReportRequest: Encodable {
    var reason: String
    var note: String? = nil
}

struct WeRewardsDto: Codable, Equatable {
    var currency: WeCurrencyDto
    var waitingMinor: Int
    var paidMinor: Int
    var items: [WeRewardItemDto]
    var nextCursor: String?

    enum CodingKeys: String, CodingKey {
        case currency, items
        case waitingMinor = "waiting_minor", paidMinor = "paid_minor", nextCursor = "next_cursor"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        currency = c.value(.currency, WeCurrencyDto())
        waitingMinor = c.value(.waitingMinor, 0)
        paidMinor = c.value(.paidMinor, 0)
        items = c.value(.items, [])
        nextCursor = c.value(.nextCursor, nil)
    }
}

struct WeRewardItemDto: Codable, Equatable, Identifiable {
    var id: Int
    /// view / interaction
    var kind: String
    var amountMinor: Int
    var ad: WeAdRefDto?
    var availableAt: String?
    var releasedAt: String?
    var createdAt: String?

    enum CodingKeys: String, CodingKey {
        case id, kind, ad
        case amountMinor = "amount_minor", availableAt = "available_at", releasedAt = "released_at", createdAt = "created_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        kind = c.value(.kind, "view")
        amountMinor = c.value(.amountMinor, 0)
        ad = c.value(.ad, nil)
        availableAt = c.value(.availableAt, nil)
        releasedAt = c.value(.releasedAt, nil)
        createdAt = c.value(.createdAt, nil)
    }
}

// MARK: - the advertiser

struct WeOptionsDto: Codable, Equatable {
    var enabled: Bool
    var country: WeCountryDto?
    var currency: WeCurrencyDto
    var pricePerViewMinor: Int
    var minAdWalletMinor: Int
    /// The ad wallet's free balance.
    var freeMinor: Int
    var media: WeMediaLimitsDto
    var cities: [WeCityDto]
    var genders: [String]
    var locales: [String]

    enum CodingKeys: String, CodingKey {
        case enabled, country, currency, media, cities, genders, locales
        case pricePerViewMinor = "price_per_view_minor", minAdWalletMinor = "min_ad_wallet_minor", freeMinor = "free_minor"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        enabled = c.value(.enabled, false)
        country = c.value(.country, nil)
        currency = c.value(.currency, WeCurrencyDto())
        pricePerViewMinor = c.value(.pricePerViewMinor, 0)
        minAdWalletMinor = c.value(.minAdWalletMinor, 0)
        freeMinor = c.value(.freeMinor, 0)
        media = c.value(.media, WeMediaLimitsDto())
        cities = c.value(.cities, [])
        genders = c.value(.genders, [])
        locales = c.value(.locales, [])
    }
}

struct WeCountryDto: Codable, Equatable {
    var id: Int
    var code: String?
    var name: String?

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        code = c.value(.code, nil)
        name = c.value(.name, nil)
    }
}

struct WeMediaLimitsDto: Codable, Equatable {
    var imageMimes = ["image/jpeg", "image/png", "image/webp"]
    var videoMimes = ["video/mp4", "video/quicktime", "video/webm"]
    var imageSeconds = 8
    var videoMaxSeconds = 60
    var videoMaxMb = 50

    enum CodingKeys: String, CodingKey {
        case imageMimes = "image_mimes", videoMimes = "video_mimes", imageSeconds = "image_seconds"
        case videoMaxSeconds = "video_max_seconds", videoMaxMb = "video_max_mb"
    }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        let base = WeMediaLimitsDto()
        imageMimes = c.value(.imageMimes, base.imageMimes)
        videoMimes = c.value(.videoMimes, base.videoMimes)
        imageSeconds = c.value(.imageSeconds, 8)
        videoMaxSeconds = c.value(.videoMaxSeconds, 60)
        videoMaxMb = c.value(.videoMaxMb, 50)
    }
}

struct WeCityDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        name = c.value(.name, nil)
    }
}

struct WeAudienceRequest: Encodable {
    var cities: [Int]?
    var gender: String?
    var locale: String?
}

struct WeAudienceDto: Codable, Equatable {
    var people: Int

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        people = c.value(.people, 0)
    }
}

struct WeAdDto: Codable, Equatable, Identifiable {
    var id: String
    /// draft, pending_review, rejected, active, paused, exhausted, suspended, ended, stopped
    var status: String
    /// Active, but its start date is still ahead.
    var scheduled: Bool
    var title: String
    var body: String?
    var linkUrl: String?
    var media: WeMediaDto
    var audience: WeAudienceInfoDto
    var startsAt: String?
    var endsAt: String?
    var currency: WeCurrencyDto
    var budget: WeBudgetDto
    var counts: WeCountsDto
    var rejectReason: String?
    var submittedAt: String?
    var reviewedAt: String?
    var suspendedAt: String?
    var closedAt: String?
    var createdAt: String?

    enum CodingKeys: String, CodingKey {
        case id, status, scheduled, title, body, media, audience, currency, budget, counts
        case linkUrl = "link_url", startsAt = "starts_at", endsAt = "ends_at", rejectReason = "reject_reason"
        case submittedAt = "submitted_at", reviewedAt = "reviewed_at", suspendedAt = "suspended_at", closedAt = "closed_at", createdAt = "created_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, "")
        status = c.value(.status, "pending_review")
        scheduled = c.value(.scheduled, false)
        title = c.value(.title, "")
        body = c.value(.body, nil)
        linkUrl = c.value(.linkUrl, nil)
        media = c.value(.media, WeMediaDto())
        audience = c.value(.audience, WeAudienceInfoDto())
        startsAt = c.value(.startsAt, nil)
        endsAt = c.value(.endsAt, nil)
        currency = c.value(.currency, WeCurrencyDto())
        budget = c.value(.budget, WeBudgetDto())
        counts = c.value(.counts, WeCountsDto())
        rejectReason = c.value(.rejectReason, nil)
        submittedAt = c.value(.submittedAt, nil)
        reviewedAt = c.value(.reviewedAt, nil)
        suspendedAt = c.value(.suspendedAt, nil)
        closedAt = c.value(.closedAt, nil)
        createdAt = c.value(.createdAt, nil)
    }
}

struct WeAudienceInfoDto: Codable, Equatable {
    var countryId: Int?
    /// City ids; empty = the whole country.
    var cities: [Int]?
    var gender: String?
    var locale: String?

    enum CodingKeys: String, CodingKey {
        case cities, gender, locale
        case countryId = "country_id"
    }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        countryId = c.value(.countryId, nil)
        cities = c.value(.cities, nil)
        gender = c.value(.gender, nil)
        locale = c.value(.locale, nil)
    }
}

struct WeBudgetDto: Codable, Equatable {
    var totalMinor = 0
    var spentMinor = 0
    var remainingMinor = 0
    var pricePerViewMinor = 0
    var viewsLeft = 0
    var remainingByBucket = WeBucketsDto()

    enum CodingKeys: String, CodingKey {
        case totalMinor = "total_minor", spentMinor = "spent_minor", remainingMinor = "remaining_minor"
        case pricePerViewMinor = "price_per_view_minor", viewsLeft = "views_left", remainingByBucket = "remaining_by_bucket"
    }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        totalMinor = c.value(.totalMinor, 0)
        spentMinor = c.value(.spentMinor, 0)
        remainingMinor = c.value(.remainingMinor, 0)
        pricePerViewMinor = c.value(.pricePerViewMinor, 0)
        viewsLeft = c.value(.viewsLeft, 0)
        remainingByBucket = c.value(.remainingByBucket, WeBucketsDto())
    }
}

struct WeStatsResponseDto: Codable, Equatable {
    var ad: WeAdDto
    var stats: WeStatsDto

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ad = try c.decode(WeAdDto.self, forKey: .ad)
        stats = c.value(.stats, WeStatsDto())
    }
}

struct WeStatsDto: Codable, Equatable {
    var totals = WeTotalsDto()
    var money = WeStatsMoneyDto()
    var daily: [WeDailyDto] = []
    var byCity: [WeCityStatDto] = []
    /// male / female / unknown → completed views
    var byGender: [String: Int] = [:]
    /// android / ios / web / unknown → completed views
    var byPlatform: [String: Int] = [:]
    /// 24 numbers, hour 0 to 23.
    var byHour: [Int] = []
    /// emoji → count
    var reactions: [String: Int] = [:]

    enum CodingKeys: String, CodingKey {
        case totals, money, daily, reactions
        case byCity = "by_city", byGender = "by_gender", byPlatform = "by_platform", byHour = "by_hour"
    }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        totals = c.value(.totals, WeTotalsDto())
        money = c.value(.money, WeStatsMoneyDto())
        daily = c.value(.daily, [])
        byCity = c.value(.byCity, [])
        byGender = c.value(.byGender, [:])
        byPlatform = c.value(.byPlatform, [:])
        byHour = c.value(.byHour, [])
        reactions = c.value(.reactions, [:])
    }
}

struct WeTotalsDto: Codable, Equatable {
    var impressions = 0
    var completed = 0
    var completionRate = 0.0
    var interactions = 0
    var interactionRate = 0.0
    var comments = 0
    var reactions = 0
    var reports = 0
    var uniqueViewers = 0

    enum CodingKeys: String, CodingKey {
        case impressions, completed, interactions, comments, reactions, reports
        case completionRate = "completion_rate", interactionRate = "interaction_rate", uniqueViewers = "unique_viewers"
    }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        impressions = c.value(.impressions, 0)
        completed = c.value(.completed, 0)
        completionRate = c.value(.completionRate, 0.0)
        interactions = c.value(.interactions, 0)
        interactionRate = c.value(.interactionRate, 0.0)
        comments = c.value(.comments, 0)
        reactions = c.value(.reactions, 0)
        reports = c.value(.reports, 0)
        uniqueViewers = c.value(.uniqueViewers, 0)
    }
}

struct WeStatsMoneyDto: Codable, Equatable {
    var budgetMinor = 0
    var spentMinor = 0
    var remainingMinor = 0
    var pricePerViewMinor = 0
    var viewsLeft = 0
    var costPerInteractionMinor: Int?
    var spentPercent = 0.0
    var dailyAverageMinor = 0

    enum CodingKeys: String, CodingKey {
        case budgetMinor = "budget_minor", spentMinor = "spent_minor", remainingMinor = "remaining_minor"
        case pricePerViewMinor = "price_per_view_minor", viewsLeft = "views_left"
        case costPerInteractionMinor = "cost_per_interaction_minor", spentPercent = "spent_percent", dailyAverageMinor = "daily_average_minor"
    }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        budgetMinor = c.value(.budgetMinor, 0)
        spentMinor = c.value(.spentMinor, 0)
        remainingMinor = c.value(.remainingMinor, 0)
        pricePerViewMinor = c.value(.pricePerViewMinor, 0)
        viewsLeft = c.value(.viewsLeft, 0)
        costPerInteractionMinor = c.value(.costPerInteractionMinor, nil)
        spentPercent = c.value(.spentPercent, 0.0)
        dailyAverageMinor = c.value(.dailyAverageMinor, 0)
    }
}

struct WeDailyDto: Codable, Equatable {
    /// yyyy-MM-dd
    var date: String
    var completed: Int
    var interactions: Int

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        date = c.value(.date, "")
        completed = c.value(.completed, 0)
        interactions = c.value(.interactions, 0)
    }
}

struct WeCityStatDto: Codable, Equatable {
    var cityId: Int?
    var name: String?
    var completed: Int

    enum CodingKeys: String, CodingKey {
        case name, completed
        case cityId = "city_id"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        cityId = c.value(.cityId, nil)
        name = c.value(.name, nil)
        completed = c.value(.completed, 0)
    }
}

struct WeAmountRequest: Encodable {
    var amountMinor: Int
    enum CodingKeys: String, CodingKey { case amountMinor = "amount_minor" }
}

/// `ends_at`: a later end date ("yyyy-MM-dd HH:mm:ss").
struct WeExtendRequest: Encodable {
    var endsAt: String
    enum CodingKeys: String, CodingKey { case endsAt = "ends_at" }
}

struct WeMoveRequest: Encodable {
    var amountMinor: Int
    var idempotencyKey: String
    enum CodingKeys: String, CodingKey { case amountMinor = "amount_minor", idempotencyKey = "idempotency_key" }
}

// MARK: - the ad wallet

struct WeAdWalletDto: Codable, Equatable {
    var currency: WeCurrencyDto
    var free: WeBucketsDto
    var locked: WeBucketsDto
    var totalMinor: Int
    var ads: [WeHoldingAdDto]
    /// The main wallet's available balance by bucket — what a fund can take from.
    var mainWallet: WeBucketsDto
    var entries: [WeEntryDto]
    var nextCursor: String?

    enum CodingKeys: String, CodingKey {
        case currency, free, locked, ads, entries
        case totalMinor = "total_minor", mainWallet = "main_wallet", nextCursor = "next_cursor"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        currency = c.value(.currency, WeCurrencyDto())
        free = c.value(.free, WeBucketsDto())
        locked = c.value(.locked, WeBucketsDto())
        totalMinor = c.value(.totalMinor, 0)
        ads = c.value(.ads, [])
        mainWallet = c.value(.mainWallet, WeBucketsDto())
        entries = c.value(.entries, [])
        nextCursor = c.value(.nextCursor, nil)
    }
}

struct WeHoldingAdDto: Codable, Equatable, Identifiable {
    var id: String
    var title: String
    var status: String
    var lockedMinor: Int
    var budgetMinor: Int
    var spentMinor: Int

    enum CodingKeys: String, CodingKey {
        case id, title, status
        case lockedMinor = "locked_minor", budgetMinor = "budget_minor", spentMinor = "spent_minor"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, "")
        title = c.value(.title, "")
        status = c.value(.status, "")
        lockedMinor = c.value(.lockedMinor, 0)
        budgetMinor = c.value(.budgetMinor, 0)
        spentMinor = c.value(.spentMinor, 0)
    }
}

struct WeEntryDto: Codable, Equatable, Identifiable {
    var id: Int
    /// fund, topup, return, reserve, release
    var type: String
    var amountMinor: Int
    var byBucket: WeBucketsDto
    var freeAfterMinor: Int
    var ad: WeAdRefDto?
    var createdAt: String?

    enum CodingKeys: String, CodingKey {
        case id, type, ad
        case amountMinor = "amount_minor", byBucket = "by_bucket", freeAfterMinor = "free_after_minor", createdAt = "created_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        type = c.value(.type, "")
        amountMinor = c.value(.amountMinor, 0)
        byBucket = c.value(.byBucket, WeBucketsDto())
        freeAfterMinor = c.value(.freeAfterMinor, 0)
        ad = c.value(.ad, nil)
        createdAt = c.value(.createdAt, nil)
    }
}

struct WeFundPreviewDto: Codable, Equatable {
    var take: WeBucketsDto

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        take = c.value(.take, WeBucketsDto())
    }
}

struct WeReturnPreviewDto: Codable, Equatable {
    var give: WeBucketsDto

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        give = c.value(.give, WeBucketsDto())
    }
}
