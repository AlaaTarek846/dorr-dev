import Foundation

// androidApp `network/ChatApi.kt` — the chat under /api/mobile/v1/chat (docs/modules/chat/API.md): conversations,
// messages, polls, view once, live location, money requests, stickers and GIFs, channels, invites, groups,
// communities, scheduled messages, the AI tools, business tools, contacts, privacy, folders, stories and calls.
// Conversations, messages and calls are addressed by uuid; every message the app sends carries its own uuid, so a
// retry is the same message, never a duplicate. Read with `ApiClient.snake`; Android's defaults are the `?? …` where
// they are read. (ProfileDto / ContactDto / UserStatusDto live in MomentsApi.swift.)

// MARK: - DTOs

struct RealtimeConfigDto: Codable, Equatable {
    var enabled: Bool?
    var key: String?
    var cluster: String?
    var host: String?
    var port: Int?
    var useTls: Bool?
    var authPath: String?
    var onesignalAppId: String?
}

struct FolderDto: Codable, Equatable, Identifiable, Hashable {
    var id: Int
    var name: String
    var color: String?
    var emoji: String?
    var sortOrder: Int?
    var conversationsCount: Int?
    var conversationIds: [String]?
}

/// The chat list answer — the envelope plus the requests badge next to `data`.
struct ConversationListEnvelope: Decodable {
    var success: Bool?
    var data: [ConversationDto]?
    var pagination: PaginationDto?
    var requestsCount: Int?
}

/// My own status as I set it (also once it has ended).
struct MyStatusDto: Codable, Equatable {
    var emoji: String?
    var text: String?
    var until: String?
    var audience: String?
    var except: [String]?
    var active: Bool?
}

struct ReminderDto: Codable, Equatable {
    var remindAt: String
    var note: String?
    var message: MessageDto
}

struct GroupInfoDto: Codable, Equatable {
    var name: String?
    var description: String?
    var avatar: String?
    var membersCount: Int?
    var onlyAdminsSend: Bool?
    var onlyAdminsEditInfo: Bool?
    var onlyAdminsAddMembers: Bool?
    var approveJoins: Bool?
    var pendingJoinRequests: Int?
    var handle: String?
    var isPublic: Bool?
    var slowModeSeconds: Int?
    var bannedWords: [String]?
    var isVerified: Bool?
    var category: CategoryDto?
    /// A channel: the emojis followers may react with (nil = any, empty = none).
    var allowedReactions: [String]?
    var community: CommunityBadgeDto?
}

struct CommunityBadgeDto: Codable, Equatable {
    var id: String
    var name: String
    var isAnnouncement: Bool?
}

struct CommunityDto: Codable, Equatable, Identifiable {
    var id: String
    var name: String
    var description: String?
    var avatar: String?
    var announcementId: String?
    var membersCount: Int?
    var groupsCount: Int?
    var myRole: String?
    var isAdmin: Bool?
    var isCreator: Bool?
    var adminsCount: Int?
    var maxAdmins: Int?
    var groups: [CommunityGroupDto]?
}

struct CommunityGroupDto: Codable, Equatable, Identifiable {
    var id: String
    var name: String
    var description: String?
    var avatar: String?
    var membersCount: Int?
    var isMember: Bool?
    var approveJoins: Bool?
    var requestPending: Bool?
}

struct CommunityJoinDto: Codable, Equatable {
    var status: String
    var conversationId: String
}

struct StickerDto: Codable, Equatable, Identifiable, Hashable {
    var id: Int
    var packId: Int?
    var emoji: String?
    var url: String?
    var width: Int?
    var height: Int?
}

struct StickerPackDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?
    var cover: String?
    var stickers: [StickerDto]?
}

struct StickerLibraryDto: Codable, Equatable {
    var packs: [StickerPackDto]?
    var mine: [StickerDto]?
    var libraryEnabled: Bool?
}

struct GifDto: Codable, Equatable, Identifiable, Hashable {
    var id: String
    var kind: String?
    var title: String?
    var url: String
    var webp: String?
    var preview: String?
    var width: Int?
    var height: Int?
}

struct GifPageDto: Codable, Equatable {
    var items: [GifDto]?
    var nextOffset: Int?
}

struct ChannelCardDto: Codable, Equatable, Identifiable {
    var id: String
    var name: String?
    var description: String?
    var avatar: String?
    var handle: String?
    var isPublic: Bool?
    var followersCount: Int?
    var isFollowing: Bool?
    var lastPostAt: String?
    var isVerified: Bool?
    var category: CategoryDto?
}

struct LastMessageDto: Codable, Equatable {
    var id: String
    var type: String
    var body: String?
    var sender: ProfileDto?
    var isMine: Bool?
    var status: String?
    var isDeleted: Bool?
    var system: String?
    var createdAt: String?
    var isSensitive: Bool?
}

struct PresenceDto: Codable, Equatable {
    var online: Bool?
    var lastSeenAt: String?
}

struct ConversationDto: Codable, Equatable, Identifiable {
    var id: String
    var type: String
    var status: String?
    var isRequest: Bool?
    /// «Note to self»: only me in it — no calls, no presence, no peer.
    var isSelf: Bool?
    var title: String?
    var avatar: String?
    var peer: ProfileDto?
    var group: GroupInfoDto?
    var myRole: String?
    var isMember: Bool?
    var canSend: Bool?
    var lastMessage: LastMessageDto?
    var lastMessageAt: String?
    var unreadCount: Int?
    var markedUnread: Bool?
    var hasUnreadMention: Bool?
    var isPinned: Bool?
    var isArchived: Bool?
    var isLocked: Bool?
    var hasLockPin: Bool?
    /// Why it's in the priority inbox: mention · urgent · owed · direct.
    var priority: [String]?
    var circle: ConversationCircleDto?
    var isMuted: Bool?
    var mutedUntil: String?
    var disappearingSeconds: Int?
    var theme: ConversationThemeDto?
    // Only on the single-chat screen:
    var presence: PresenceDto?
    var iBlocked: Bool?
    var blockedMe: Bool?
    var blockScreenshots: Bool?
    /// Calls work here. Nil in lists = assume yes.
    var canCall: Bool?
    var business: BusinessHoursDto?

    /// Group-like (a channel too: it has a group row, roles, an invite link).
    var isGroup: Bool { type == "group" || type == "channel" }
    var isChannel: Bool { type == "channel" }
    var isAdmin: Bool { myRole == "admin" || myRole == "owner" }
}

/// A look the admin made: wallpaper (image or colour) + the two bubble colours.
struct ChatThemeDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?
    var wallpaper: String?
    var backgroundColor: String?
    var senderColor: String?
    var receiverColor: String?
    var isDark: Bool?
    var isDefault: Bool?
    var isCustom: Bool?
    /// How much my own wallpaper is darkened, 0–80 (%).
    var dim: Int?
}

/// My own look for one chat, as I edit it — only I see it. Nil fields = the theme's.
struct CustomThemeDto: Codable, Equatable {
    var wallpaper: String?
    var senderColor: String?
    var receiverColor: String?
    var backgroundColor: String?
    var dim: Int?
}

struct ConversationThemeDto: Codable, Equatable {
    var themeId: Int?
    var custom: CustomThemeDto?
    var applied: ChatThemeDto?
}

struct ReportTypeDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?
}

struct AttachmentDto: Codable, Equatable, Identifiable, Hashable {
    var id: String
    var url: String
    var name: String?
    var mimeType: String?
    var size: Int64?
    var width: Int?
    var height: Int?
    var durationMs: Int64?
    /// Videos: the poster frame.
    var thumbnail: String?
    /// Photos: a tiny JPEG (base64), shown while data saver holds the photo back.
    var preview: String?
}

struct ReplyPreviewDto: Codable, Equatable {
    var id: String?
    var type: String?
    var body: String?
    var sender: ProfileDto?
    var thumbnail: String?
    var isDeleted: Bool?
}

struct ReactionSummaryDto: Codable, Equatable, Hashable {
    var emoji: String
    var count: Int
}

struct ReactionsDto: Codable, Equatable {
    var summary: [ReactionSummaryDto]?
    var mine: String?
    var total: Int?
}

struct SystemDto: Codable, Equatable {
    var event: String?
    var actor: ProfileDto?
    var targets: [ProfileDto]?
    var text: String?
}

struct MessageDto: Codable, Equatable, Identifiable {
    var id: String
    var conversationId: String
    var type: String
    var body: String?
    /// Free-form per type (location, contact, card, call…). Keys come back camel-cased by the snake decoder.
    var meta: JSONAny?
    var attachments: [AttachmentDto]?
    var sender: ProfileDto?
    var isMine: Bool?
    var status: String?
    var replyTo: ReplyPreviewDto?
    /// Threads: under a message, its replies; on a reply, the message it hangs from.
    var thread: ThreadInfoDto?
    var threadRoot: String?
    var isForwarded: Bool?
    var forwardedManyTimes: Bool?
    var mentions: [ProfileDto]?
    var isEdited: Bool?
    var isDeleted: Bool?
    var expiresAt: String?
    var reactions: ReactionsDto?
    var isStarred: Bool?
    var starFolderId: Int?
    var isReadLater: Bool?
    /// A surprise greeting card not opened yet.
    var sealed: Bool?
    var isFollowUp: Bool?
    var reminderAt: String?
    var isUrgent: Bool?
    var isSensitive: Bool?
    var system: SystemDto?
    var createdAt: String?
    var viewOnce: Bool?
    var viewOnceOpened: Bool?
    var linkPreview: LinkPreviewDto?
    var poll: PollDto?
    var interactive: InteractiveDto?
    var liveLocation: LiveLocationDto?
    var payment: PaymentDto?
    /// Channel posts: how many followers saw it.
    var views: Int?
    var isSilent: Bool?
    var editUntil: String?
    var deleteUntil: String?

    var files: [AttachmentDto] { attachments ?? [] }
}

struct PaymentShareDto: Codable, Equatable {
    var profile: ProfileDto?
    var amountMinor: Int64
    /// pending · paid · declined · owner
    var status: String
    var paidAt: String?
}

struct MyShareDto: Codable, Equatable {
    var amountMinor: Int64
    var status: String
}

struct PaymentDto: Codable, Equatable {
    /// request · split
    var kind: String
    var status: String
    var amountMinor: Int64?
    var totalMinor: Int64?
    var paidMinor: Int64?
    var shares: [PaymentShareDto]?
    var myShare: MyShareDto?
    var currency: String?
    var currencySymbol: String?
    var isRequester: Bool?
    var canPay: Bool?
    var canCancel: Bool?
    var paidAt: String?

    var due: Int64 { kind == "request" ? (amountMinor ?? 0) : (myShare?.amountMinor ?? 0) }
}

struct LinkPreviewDto: Codable, Equatable {
    var url: String
    var title: String?
    var description: String?
    var image: String?
    var siteName: String?
}

struct PollOptionDto: Codable, Equatable, Identifiable {
    var id: Int
    var text: String
    var votes: Int?
}

struct PollDto: Codable, Equatable {
    var question: String?
    var multiple: Bool?
    var options: [PollOptionDto]?
    var myVotes: [Int]?
    var voters: Int?
}

struct InteractiveOptionDto: Codable, Equatable, Identifiable {
    var id: Int
    var title: String
    var description: String?
}

struct InteractiveDto: Codable, Equatable {
    /// `buttons` (up to 3) or `list` (up to 10, opened from `button`).
    var kind: String?
    var options: [InteractiveOptionDto]?
    var button: String?
    var footer: String?
    var myChoice: Int?
    var answers: Int?
}

struct PollOptionVotersDto: Codable, Equatable, Identifiable {
    var id: Int
    var text: String
    var voters: [ProfileDto]?
}

struct LiveLocationDto: Codable, Equatable {
    var active: Bool?
    var liveUntil: String?
    var updatedAt: String?
}

struct MyLiveLocationDto: Codable, Equatable {
    var messageId: String
    var conversationId: String
    var liveUntil: String
}

struct ViewOnceFilesDto: Codable, Equatable {
    var attachments: [AttachmentDto]?
}

struct InvitePreviewDto: Codable, Equatable {
    var conversationId: String
    var name: String
    var description: String?
    var avatar: String?
    var membersCount: Int?
    var isMember: Bool?
    var approveJoins: Bool?
    var requestStatus: String?
}

struct JoinRequestDto: Codable, Equatable, Identifiable {
    var id: Int
    var profile: ProfileDto?
    var requestedAt: String?
}

struct MessagePageDto: Codable, Equatable {
    var messages: [MessageDto]?
    var hasMoreBefore: Bool?
    var hasMoreAfter: Bool?
}

struct GalleryPageDto: Codable, Equatable {
    var messages: [MessageDto]?
    var hasMore: Bool?
}

struct ReceiptRowDto: Codable, Equatable {
    var participant: ProfileDto?
    var deliveredAt: JSONAny?
    var readAt: String?
}

struct MessageInfoDto: Codable, Equatable {
    var readBy: [ReceiptRowDto]?
    var deliveredTo: [ReceiptRowDto]?
    var pending: [ReceiptRowDto]?
}

struct MemberDto: Codable, Equatable, Identifiable {
    var participantId: Int
    var role: String
    var joinedAt: String?
    var profile: ProfileDto?

    var id: Int { participantId }
}

struct CreatedGroupDto: Codable, Equatable {
    var conversation: ConversationDto
    var notAdded: [String]?
}

struct InviteDto: Codable, Equatable {
    var token: String
    var link: String
    var expiresAt: String?
}

struct ChatLinkDto: Codable, Equatable {
    var token: String
    var payload: String
    var link: String
    var text: String?
}

struct QrDto: Codable, Equatable {
    var token: String
    var payload: String
}

struct PrivacyDto: Codable, Equatable {
    var lastSeen: String?
    var profilePhoto: String?
    var whoCanMessage: String?
    var whoCanAddToGroups: String?
    var whoCanCall: String?
    var readReceipts: Bool?
    var blockScreenshots: Bool?
    var notificationPreview: Bool?
    /// all · name · none
    var notificationPrivacy: String?
    var quiet: QuietDto?
    var whoCanUrgent: String?
    var status: MyStatusDto?
    var privacyMode: PrivacyModeDto?
    var defaultDisappearingSeconds: Int?
    var silenceUnknownCallers: Bool?
}

struct PrivacyModeDto: Codable, Equatable {
    var on: Bool?
    var until: String?
    var startedAt: String?
    var schedule: PrivacyScheduleDto?
}

struct PrivacyScheduleDto: Codable, Equatable {
    var from: String?
    var to: String?
    var days: [Int]?
    var timezone: String?
}

struct PrivacySummaryDto: Codable, Equatable {
    var messages: Int?
    var conversations: Int?
    var from: String?
}

struct PrivacyOffDto: Codable, Equatable {
    var settings: PrivacyDto
    var summary: PrivacySummaryDto
}

/// A text written now, sent by the server at `send_at` (or `failed`, with the reason).
struct ScheduledMessageDto: Codable, Equatable, Identifiable {
    var id: String
    var conversationId: String?
    var body: String
    var isSilent: Bool?
    var sendAt: String
    var status: String
    var errorCode: String?
}

/// Which AI tools the server offers now.
struct AiCapabilitiesDto: Codable, Equatable {
    var enabled: Bool?
    var translate: Bool?
    var summarize: Bool?
    var smartReplies: Bool?
    var transcribe: Bool?
    var ask: Bool?
    var commitments: Bool?
    var assistant: Bool?
    var proofread: Bool?
    var understand: Bool?
    var simplify: Bool?
    var tasks: Bool?
    var notes: Bool?
    var dates: Bool?
    var important: Bool?
    var relatedFiles: Bool?
    var today: Bool?
    var imageGenerate: Bool?
    var imageEdit: Bool?
    var searchMeaning: Bool?
    var memory: Bool?
    var capsule: Bool?
    var aiImages: Bool?
    var plan: AiPlanStateDto?
}

struct AiPlanStateDto: Codable, Equatable {
    var allowed: Bool?
    var reason: String?
}

struct AiTextDto: Codable, Equatable {
    var text: String
    var to: String?
}

struct AiSummaryDto: Codable, Equatable {
    var text: String
    var messages: Int?
}

struct AiRepliesDto: Codable, Equatable {
    var replies: [String]?
}

/// One day of opening hours (the list is 7 days, Sunday first).
struct BusinessDayDto: Codable, Equatable {
    var open: Bool
    var from: String
    var to: String
}

struct BusinessProfileDto: Codable, Equatable {
    var welcomeEnabled: Bool?
    var welcomeMessage: String?
    var awayEnabled: Bool?
    var awayMessage: String?
    /// always · outside_hours
    var awayMode: String?
    var hours: [BusinessDayDto]?
    var timezone: String?
    var about: String?
    var address: String?
    var email: String?
    var website: String?
}

struct QuickReplyDto: Codable, Equatable, Identifiable {
    var id: Int
    var shortcut: String
    var body: String
}

struct BusinessDto: Codable, Equatable {
    var profile: BusinessProfileDto
    var quickReplies: [QuickReplyDto]?
}

/// What a customer sees about a business on the chat screen.
struct BusinessHoursDto: Codable, Equatable {
    var hours: [BusinessDayDto]?
    var timezone: String?
    var openNow: Bool?
    var about: String?
    var address: String?
    var email: String?
    var website: String?
}

struct BlockDto: Codable, Equatable {
    var profile: ProfileDto?
    var blockedAt: String?
}

struct CallParticipantDto: Codable, Equatable {
    var profile: ProfileDto?
    var status: String
}

struct CallDto: Codable, Equatable, Identifiable {
    var id: String
    var conversationId: String
    var conversationType: String?
    var groupName: String?
    var type: String
    var status: String
    var isOutgoing: Bool?
    var initiator: ProfileDto?
    var participants: [CallParticipantDto]?
    var answeredAt: String?
    var endedAt: String?
    var durationSeconds: Int?
    var createdAt: String?
    var recordingAvailable: Bool?
    var recording: CallRecordingStateDto?
    var recordingsCount: Int?
    /// Didn't ring for me: an unknown caller, silenced by my setting.
    var silenced: Bool?
}

/// A call recording being asked for or made — participant keys of who asked, agreed, declined, and who is left.
/// `status`: pending · recording · processing · ready · declined · expired · cancelled · failed · deleted.
struct CallRecordingStateDto: Codable, Equatable {
    var id: String?
    var callId: String?
    var status: String?
    var requestedBy: String?
    var agreed: [String]?
    var declined: [String]?
    var waitingFor: [String]?
    var consentUntil: String?
    var startedAt: String?
    var stoppedAt: String?
}

struct CallRecordingDto: Codable, Equatable, Identifiable {
    var id: String
    var callId: String?
    /// audio · video
    var type: String?
    var status: String?
    var durationSeconds: Int?
    var sizeBytes: Int64?
    var startedAt: String?
    var expiresAt: String?
    var url: String?
}

struct StoryStyleDto: Codable, Equatable {
    var background: String?
    var font: String?
    var align: String?
    /// A voice status's bars (0–100).
    var waveform: [Int]?
    /// A photo / video's width ÷ height — the frame the layers sit in.
    var aspect: Double?
    var layers: [StoryLayerDto]?
}

/// One thing on top of a status; positions are fractions of the frame. `k`: t text · s sticker / GIF · d drawing.
struct StoryLayerDto: Codable, Equatable {
    var k: String
    var x: Double?
    var y: Double?
    var s: Double?
    var r: Double?
    var text: String?
    var c: String?
    var f: String?
    var bg: Int?
    var url: String?
    var a: Double?
    var w: Double?
    var p: [Double]?
}

struct StoryMediaDto: Codable, Equatable {
    var url: String
    var mimeType: String?
}

struct StoryDto: Codable, Equatable, Identifiable {
    var id: String
    var type: String
    var body: String?
    var style: StoryStyleDto?
    var media: StoryMediaDto?
    var durationMs: Int64?
    var allowReplies: Bool?
    var isMine: Bool?
    var seen: Bool?
    var myReaction: String?
    var views: Int?
    var reactions: Int?
    var createdAt: String?
    var expiresAt: String?
    var isPublic: Bool?
    var isDorr: Bool?
    var linkUrl: String?
    var linkLabel: String?
    var mentions: Int?
    var mentionedMe: Bool?
    var reshared: Bool?
}

struct PublicStoryFeedDto: Codable, Equatable {
    var enabled: Bool?
    var quota: StoryQuotaDto?
    var mine: StoryGroupDto?
    var dorr: StoryGroupDto?
    var people: [StoryGroupDto]?
    var hasMore: Bool?
}

struct StoryQuotaDto: Codable, Equatable {
    var free: Int
    var used: Int
}

struct StoryGroupDto: Codable, Equatable {
    var owner: ProfileDto?
    var stories: [StoryDto]
    var allSeen: Bool?
    var lastAt: String?
    var blockScreenshots: Bool?
}

struct StoryFeedDto: Codable, Equatable {
    var mine: StoryGroupDto?
    var recent: [StoryGroupDto]?
    var muted: [StoryGroupDto]?
    var hasMore: Bool?
}

struct StoryViewerDto: Codable, Equatable {
    var viewer: ProfileDto?
    var viewedAt: String?
    var reaction: String?
}

struct StoryPrivacyDto: Codable, Equatable {
    var audience: String
    var except: [ProfileDto]?
    var only: [ProfileDto]?
}

struct JoinDto: Codable, Equatable {
    var url: String
    var room: String
    var token: String
}

struct CallSessionDto: Codable, Equatable {
    var call: CallDto
    var join: JoinDto?
}

/// A call link: `link` for sharing anywhere, `payload` for the app.
struct CallLinkDto: Codable, Equatable, Identifiable {
    var token: String
    var type: String
    var link: String
    var payload: String
    var owner: ProfileDto?
    var isMine: Bool?
    var expiresAt: String?
    var lastJoinedAt: String?

    var id: String { token }
}

struct CallLinkJoinDto: Codable, Equatable {
    var link: CallLinkDto
    var join: JoinDto
}

struct ConversationCircleDto: Codable, Equatable {
    var id: String
    var name: String
    var shownName: String?
    var emoji: String?
    var locked: Bool?
    var hideFromList: Bool?
}

struct ThreadInfoDto: Codable, Equatable {
    var count: Int
    var lastAt: String?
}

struct QuietDto: Codable, Equatable {
    var on: Bool?
    var schedule: PrivacyScheduleDto?
    var scope: String?
}

struct FeatureCityDto: Codable, Equatable {
    var id: Int?
    var name: String?
}

/// `features`: calls · voice_rooms · live · stories · ai → on / off where I am (keys camel-cased by the decoder).
struct ChatFeaturesDto: Codable, Equatable {
    var features: [String: Bool]?
    var city: FeatureCityDto?

    func on(_ key: String) -> Bool { features?[key] ?? features?[camel(key)] ?? true }
}

// MARK: - The API

/// One file of a message / group / story upload.
typealias ChatFile = ApiClient.FilePart

enum ChatApi {
    private static let base = "mobile/v1/chat"

    private static func json(_ values: [String: Any?]) -> [String: JSONValue] {
        var out: [String: JSONValue] = [:]
        for (k, v) in values { out[k] = JSONValue.of(v) }
        return out
    }

    private static func multipart<T: Decodable>(_ path: String, fields: [String: String], files: [ChatFile], headers: [String: String] = [:]) async throws -> ApiEnvelope<T> {
        let data = try await ApiClient.uploadRaw(path, fields: fields, files: files, headers: headers)
        return try ApiClient.snakeDecoder.decode(ApiEnvelope<T>.self, from: data)
    }

    static func realtimeConfig() async throws -> ApiEnvelope<RealtimeConfigDto> { try await ApiClient.snake("\(base)/realtime-config") }

    // Conversations

    static func conversations(filter: String? = nil, search: String? = nil, folder: Int? = nil, page: Int = 1, circle: String? = nil, perPage: Int = 30) async throws -> ConversationListEnvelope {
        let data = try await ApiClient.raw("\(base)/conversations", query: [
            "filter": filter, "search": search, "folder": folder.map(String.init), "page": String(page), "circle": circle, "per_page": String(perPage),
        ], auth: true)
        return try ApiClient.snakeDecoder.decode(ConversationListEnvelope.self, from: data)
    }

    static func openDirect(participantId: Int, participantType: String = "user") async throws -> ApiEnvelope<ConversationDto> {
        try await ApiClient.snake("\(base)/conversations/direct", method: "POST", body: ["participant_id": .int(participantId), "participant_type": .string(participantType)])
    }
    static func reshareStory(_ storyId: String) async throws -> ApiEnvelope<JSONAny> { try await ApiClient.snake("\(base)/stories/\(storyId)/reshare", method: "POST", body: [:]) }
    static func openSelf() async throws -> ApiEnvelope<ConversationDto> { try await ApiClient.snake("\(base)/conversations/self", method: "POST", body: [:]) }
    static func conversation(_ id: String) async throws -> ApiEnvelope<ConversationDto> { try await ApiClient.snake("\(base)/conversations/\(id)") }
    /// Keeps `null`s (e.g. `theme_id: null`, back to Dorr's look).
    static func updateSettings(_ id: String, _ body: [String: Any?]) async throws -> ApiEnvelope<ConversationDto> {
        try await ApiClient.snake("\(base)/conversations/\(id)/settings", method: "PATCH", body: json(body))
    }
    static func uploadWallpaper(_ id: String, image: ChatFile) async throws -> ApiEnvelope<ConversationDto> { try await multipart("\(base)/conversations/\(id)/wallpaper", fields: [:], files: [image]) }
    static func themes() async throws -> ApiEnvelope<[ChatThemeDto]> { try await ApiClient.snake("\(base)/themes") }
    static func reportTypes() async throws -> ApiEnvelope<[ReportTypeDto]> { try await ApiClient.snake("\(base)/report-types") }
    static func report(_ id: String, _ body: [String: Any?]) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/conversations/\(id)/report", method: "POST", body: json(body)) }
    static func clear(_ id: String) async throws -> ApiEnvelope<ConversationDto> { try await ApiClient.snake("\(base)/conversations/\(id)/clear", method: "POST", body: [:]) }
    static func deleteConversation(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/conversations/\(id)", method: "DELETE") }
    static func markRead(_ id: String, _ body: [String: String] = [:]) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("\(base)/conversations/\(id)/read", method: "POST", body: body.mapValues { .string($0) })
    }
    static func markDelivered() async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/conversations/delivered", method: "POST", body: [:]) }
    static func typing(_ id: String, state: String) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("\(base)/conversations/\(id)/typing", method: "POST", body: ["state": .string(state)])
    }
    static func disappearing(_ id: String, seconds: Int?) async throws -> ApiEnvelope<ConversationDto> {
        try await ApiClient.snake("\(base)/conversations/\(id)/disappearing", method: "PUT", body: ["seconds": seconds.map { .int($0) } ?? .null])
    }
    static func accept(_ id: String) async throws -> ApiEnvelope<ConversationDto> { try await ApiClient.snake("\(base)/conversations/\(id)/accept", method: "POST", body: [:]) }
    static func reject(_ id: String, block: Bool = false) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("\(base)/conversations/\(id)/reject", method: "POST", body: block ? ["block": .bool(true)] : [:])
    }

    // Messages

    static func messages(_ id: String, before: String? = nil, after: String? = nil, around: String? = nil, limit: Int = 50) async throws -> ApiEnvelope<MessagePageDto> {
        try await ApiClient.snake("\(base)/conversations/\(id)/messages", query: ["before": before, "after": after, "around": around, "limit": String(limit)])
    }
    static func send(_ id: String, _ body: [String: Any?]) async throws -> ApiEnvelope<MessageDto> {
        try await ApiClient.snake("\(base)/conversations/\(id)/messages", method: "POST", body: json(body))
    }
    static func sendWithFiles(_ id: String, fields: [String: String], files: [ChatFile]) async throws -> ApiEnvelope<MessageDto> {
        try await multipart("\(base)/conversations/\(id)/messages", fields: fields, files: files)
    }
    static func edit(_ id: String, body: String) async throws -> ApiEnvelope<MessageDto> { try await ApiClient.snake("\(base)/messages/\(id)", method: "PATCH", body: ["body": .string(body)]) }
    static func deleteForEveryone(_ id: String) async throws -> ApiEnvelope<MessageDto> { try await ApiClient.snake("\(base)/messages/\(id)", method: "DELETE") }
    static func deleteForMe(_ conversation: String, ids: [String]) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("\(base)/conversations/\(conversation)/messages/delete-for-me", method: "POST", body: ["messages": .array(ids.map { .string($0) })])
    }
    static func forward(messageIds: [String], conversationIds: [String]) async throws -> ApiEnvelope<[MessageDto]> {
        try await ApiClient.snake("\(base)/messages/forward", method: "POST", body: [
            "messages": .array(messageIds.map { .string($0) }), "conversations": .array(conversationIds.map { .string($0) }),
        ])
    }
    static func react(_ id: String, emoji: String?) async throws -> ApiEnvelope<MessageDto> {
        try await ApiClient.snake("\(base)/messages/\(id)/reaction", method: "PUT", body: ["emoji": emoji.map { .string($0) } ?? .null])
    }
    static func star(_ id: String, _ on: Bool, folderId: Int? = nil) async throws -> ApiEnvelope<JSONAny?> {
        var body: [String: JSONValue] = ["starred": .bool(on)]
        if let folderId { body["folder_id"] = .int(folderId) }
        return try await ApiClient.snake("\(base)/messages/\(id)/star", method: "PUT", body: body)
    }
    static func readLater(_ id: String, _ on: Bool) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/messages/\(id)/read-later", method: "PUT", body: ["on": .bool(on)]) }
    static func readLaterList() async throws -> ApiEnvelope<[MessageDto]> { try await ApiClient.snake("\(base)/messages/read-later") }
    static func followUp(_ id: String, _ on: Bool) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/messages/\(id)/follow-up", method: "PUT", body: ["on": .bool(on)]) }
    static func followUpList() async throws -> ApiEnvelope<[MessageDto]> { try await ApiClient.snake("\(base)/messages/follow-up") }
    /// `remind_at` (ISO-8601 with offset), `note?`.
    static func setReminder(_ id: String, remindAt: String, note: String? = nil) async throws -> ApiEnvelope<JSONAny?> {
        var body: [String: JSONValue] = ["remind_at": .string(remindAt)]
        if let note { body["note"] = .string(note) }
        return try await ApiClient.snake("\(base)/messages/\(id)/reminder", method: "PUT", body: body)
    }
    static func clearReminder(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/messages/\(id)/reminder", method: "DELETE") }
    static func reminders() async throws -> ApiEnvelope<[ReminderDto]> { try await ApiClient.snake("\(base)/reminders") }
    /// `emoji?`, `text?`, `until?` (ISO), `audience?`.
    static func setStatus(_ body: [String: Any?]) async throws -> ApiEnvelope<PrivacyDto> { try await ApiClient.snake("\(base)/status", method: "PUT", body: json(body)) }
    static func clearStatus() async throws -> ApiEnvelope<PrivacyDto> { try await ApiClient.snake("\(base)/status", method: "DELETE") }
    static func starred(page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[MessageDto]> {
        try await ApiClient.snake("\(base)/messages/starred", query: ["page": String(page), "per_page": String(perPage)])
    }
    static func pin(_ id: String, durationSeconds: Int) async throws -> ApiEnvelope<[MessageDto]> {
        try await ApiClient.snake("\(base)/messages/\(id)/pin", method: "POST", body: ["duration_seconds": .int(durationSeconds)])
    }
    static func unpin(_ id: String) async throws -> ApiEnvelope<[MessageDto]> { try await ApiClient.snake("\(base)/messages/\(id)/pin", method: "DELETE") }
    static func pinned(_ conversation: String) async throws -> ApiEnvelope<[MessageDto]> { try await ApiClient.snake("\(base)/conversations/\(conversation)/pinned") }
    static func info(_ id: String) async throws -> ApiEnvelope<MessageInfoDto> { try await ApiClient.snake("\(base)/messages/\(id)/info") }

    // Polls, view once, live location, link cards

    static func vote(_ id: String, options: [Int]) async throws -> ApiEnvelope<MessageDto> {
        try await ApiClient.snake("\(base)/messages/\(id)/vote", method: "PUT", body: ["options": .array(options.map { .int($0) })])
    }
    static func votes(_ id: String) async throws -> ApiEnvelope<[PollOptionVotersDto]> { try await ApiClient.snake("\(base)/messages/\(id)/votes") }
    static func chooseOption(_ id: String, option: Int) async throws -> ApiEnvelope<MessageDto> {
        try await ApiClient.snake("\(base)/messages/\(id)/choose", method: "POST", body: ["option_id": .int(option)])
    }
    static func openViewOnce(_ id: String) async throws -> ApiEnvelope<ViewOnceFilesDto> { try await ApiClient.snake("\(base)/messages/\(id)/open", method: "POST", body: [:]) }
    static func moveLive(_ id: String, latitude: Double, longitude: Double, accuracy: Double?) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("\(base)/messages/\(id)/live-location", method: "PUT", body: [
            "latitude": .double(latitude), "longitude": .double(longitude), "accuracy": accuracy.map { .double($0) } ?? .null,
        ])
    }
    static func stopLive(_ id: String) async throws -> ApiEnvelope<MessageDto> { try await ApiClient.snake("\(base)/messages/\(id)/live-location/stop", method: "POST", body: [:]) }
    static func myLiveLocations() async throws -> ApiEnvelope<[MyLiveLocationDto]> { try await ApiClient.snake("\(base)/live-locations") }
    static func linkPreview(_ url: String) async throws -> ApiEnvelope<LinkPreviewDto?> { try await ApiClient.snake("\(base)/link-preview", query: ["url": url]) }

    // Money requests & bill splits

    static func payRequest(_ id: String, pin: String) async throws -> ApiEnvelope<MessageDto> {
        try await ApiClient.snake("\(base)/messages/\(id)/pay", method: "POST", body: [:], headers: ["X-Wallet-Pin": pin])
    }
    /// `amount_minor`, `note?`, `uuid`.
    static func sendMoney(_ conversation: String, pin: String, _ body: [String: Any?]) async throws -> ApiEnvelope<MessageDto> {
        try await ApiClient.snake("\(base)/conversations/\(conversation)/send-money", method: "POST", body: json(body), headers: ["X-Wallet-Pin": pin])
    }
    static func declineRequest(_ id: String) async throws -> ApiEnvelope<MessageDto> { try await ApiClient.snake("\(base)/messages/\(id)/decline-request", method: "POST", body: [:]) }
    static func cancelRequest(_ id: String) async throws -> ApiEnvelope<MessageDto> { try await ApiClient.snake("\(base)/messages/\(id)/cancel-request", method: "POST", body: [:]) }

    // Stickers & GIFs

    static func stickers() async throws -> ApiEnvelope<StickerLibraryDto> { try await ApiClient.snake("\(base)/stickers") }
    static func uploadMySticker(_ image: ChatFile) async throws -> ApiEnvelope<StickerDto> { try await multipart("\(base)/stickers/mine", fields: [:], files: [image]) }
    static func deleteMySticker(_ id: Int) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/stickers/mine/\(id)", method: "DELETE") }
    /// Giphy: trending (no q) or search. `kind`: gifs · stickers.
    static func gifs(kind: String, q: String? = nil, offset: Int = 0) async throws -> ApiEnvelope<GifPageDto> {
        try await ApiClient.snake("\(base)/gifs", query: ["kind": kind, "q": q, "offset": String(offset)])
    }

    // Channels

    static func createChannel(fields: [String: String], avatar: ChatFile?) async throws -> ApiEnvelope<ConversationDto> {
        try await multipart("\(base)/channels", fields: fields, files: avatar.map { [$0] } ?? [])
    }
    static func discoverChannels(search: String? = nil, perPage: Int = 30) async throws -> ApiEnvelope<[ChannelCardDto]> {
        try await ApiClient.snake("\(base)/channels/discover", query: ["search": search, "per_page": String(perPage)])
    }
    static func followChannel(_ id: String) async throws -> ApiEnvelope<ConversationDto> { try await ApiClient.snake("\(base)/channels/\(id)/follow", method: "POST", body: [:]) }
    static func unfollowChannel(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/channels/\(id)/unfollow", method: "POST", body: [:]) }
    static func channelHandle(_ id: String, handle: String?) async throws -> ApiEnvelope<ConversationDto> {
        try await ApiClient.snake("\(base)/channels/\(id)/handle", method: "PATCH", body: ["handle": handle.map { .string($0) } ?? .null])
    }

    // Joining groups by link

    static func previewInvite(_ token: String) async throws -> ApiEnvelope<InvitePreviewDto> { try await ApiClient.snake("\(base)/invites/\(token)") }
    /// The conversation, or `{status: pending}` when the admins approve new members.
    static func joinByInvite(_ token: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/invites/\(token)/join", method: "POST", body: [:]) }
    static func cancelJoin(_ token: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/invites/\(token)/cancel", method: "POST", body: [:]) }
    static func joinRequests(_ group: String) async throws -> ApiEnvelope<[JoinRequestDto]> { try await ApiClient.snake("\(base)/groups/\(group)/join-requests") }
    static func approveJoin(_ group: String, request: Int) async throws -> ApiEnvelope<[JoinRequestDto]> {
        try await ApiClient.snake("\(base)/groups/\(group)/join-requests/\(request)/approve", method: "POST", body: [:])
    }
    static func rejectJoin(_ group: String, request: Int) async throws -> ApiEnvelope<[JoinRequestDto]> {
        try await ApiClient.snake("\(base)/groups/\(group)/join-requests/\(request)/reject", method: "POST", body: [:])
    }
    /// Only some kinds: text, image, video, voice, document, link, location, poll, contact, sticker, money.
    static func searchIn(_ id: String, q: String?, types: [String]? = nil) async throws -> ApiEnvelope<[MessageDto]> {
        var path = "\(base)/conversations/\(id)/messages/search"
        if let types, !types.isEmpty {
            path += "?" + types.map { "types%5B%5D=\($0)" }.joined(separator: "&")
        }
        return try await ApiClient.snake(path, query: ["q": q])
    }
    static func gallery(_ id: String, kind: String, before: String? = nil, date: String? = nil, fileKind: String? = nil, minSize: Int64? = nil, sort: String? = nil) async throws -> ApiEnvelope<GalleryPageDto> {
        try await ApiClient.snake("\(base)/conversations/\(id)/gallery", query: [
            "kind": kind, "before": before, "date": date, "file_kind": fileKind, "min_size": minSize.map { String($0) }, "sort": sort,
        ])
    }

    // Groups

    static func createGroup(fields: [String: String], avatar: ChatFile?) async throws -> ApiEnvelope<CreatedGroupDto> {
        try await multipart("\(base)/groups", fields: fields, files: avatar.map { [$0] } ?? [])
    }
    static func updateGroup(_ id: String, fields: [String: String], avatar: ChatFile?) async throws -> ApiEnvelope<ConversationDto> {
        try await multipart("\(base)/groups/\(id)", fields: fields, files: avatar.map { [$0] } ?? [])
    }
    static func groupSettings(_ id: String, _ body: [String: Any?]) async throws -> ApiEnvelope<ConversationDto> {
        try await ApiClient.snake("\(base)/groups/\(id)/settings", method: "PATCH", body: json(body))
    }
    static func communities(page: Int = 1, perPage: Int = 15, q: String? = nil) async throws -> ApiEnvelope<[CommunityDto]> {
        try await ApiClient.snake("\(base)/communities", query: ["page": String(page), "per_page": String(perPage), "q": q])
    }
    static func createCommunity(_ body: [String: Any?]) async throws -> ApiEnvelope<CommunityDto> { try await ApiClient.snake("\(base)/communities", method: "POST", body: json(body)) }
    static func community(_ id: String) async throws -> ApiEnvelope<CommunityDto> { try await ApiClient.snake("\(base)/communities/\(id)") }
    static func updateCommunity(_ id: String, _ body: [String: String]) async throws -> ApiEnvelope<CommunityDto> {
        try await ApiClient.snake("\(base)/communities/\(id)", method: "POST", body: body.mapValues { .string($0) })
    }
    static func addCommunityGroups(_ id: String, _ body: [String: Any?]) async throws -> ApiEnvelope<CommunityDto> {
        try await ApiClient.snake("\(base)/communities/\(id)/groups", method: "POST", body: json(body))
    }
    static func removeCommunityGroup(_ id: String, group: String) async throws -> ApiEnvelope<CommunityDto> { try await ApiClient.snake("\(base)/communities/\(id)/groups/\(group)", method: "DELETE") }
    static func joinCommunityGroup(_ id: String, group: String) async throws -> ApiEnvelope<CommunityJoinDto> {
        try await ApiClient.snake("\(base)/communities/\(id)/groups/\(group)/join", method: "POST", body: [:])
    }
    static func communityMembers(_ id: String) async throws -> ApiEnvelope<[MemberDto]> { try await ApiClient.snake("\(base)/communities/\(id)/members") }
    static func removeCommunityMember(_ id: String, participant: Int) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("\(base)/communities/\(id)/members/\(participant)", method: "DELETE")
    }
    static func communityRole(_ id: String, participant: Int, role: String) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("\(base)/communities/\(id)/members/\(participant)/role", method: "PATCH", body: ["role": .string(role)])
    }
    static func leaveCommunity(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/communities/\(id)/leave", method: "POST", body: [:]) }
    static func deleteCommunity(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/communities/\(id)", method: "DELETE") }
    static func members(_ group: String) async throws -> ApiEnvelope<[MemberDto]> { try await ApiClient.snake("\(base)/groups/\(group)/members") }
    static func addMembers(_ group: String, participants: [Int]) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("\(base)/groups/\(group)/members", method: "POST", body: ["members": .array(participants.map { .int($0) })])
    }
    static func removeMember(_ group: String, participant: Int) async throws -> ApiEnvelope<[MemberDto]> { try await ApiClient.snake("\(base)/groups/\(group)/members/\(participant)", method: "DELETE") }
    static func setRole(_ group: String, participant: Int, role: String) async throws -> ApiEnvelope<[MemberDto]> {
        try await ApiClient.snake("\(base)/groups/\(group)/members/\(participant)/role", method: "PATCH", body: ["role": .string(role)])
    }
    static func leave(_ group: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/groups/\(group)/leave", method: "POST", body: [:]) }
    /// The owner hands the group / channel to another member (and stays an admin).
    static func transferOwnership(_ group: String, participant: Int) async throws -> ApiEnvelope<ConversationDto> {
        try await ApiClient.snake("\(base)/groups/\(group)/owner", method: "POST", body: ["participant_id": .int(participant)])
    }
    static func deleteGroup(_ group: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/groups/\(group)", method: "DELETE") }
    static func invite(_ group: String) async throws -> ApiEnvelope<InviteDto> { try await ApiClient.snake("\(base)/groups/\(group)/invite") }
    /// A new link (the old one stops working), lasting `expires_in_hours` (1, 24, 168, 720) or forever (0).
    static func resetInvite(_ group: String, expiresInHours: Int?) async throws -> ApiEnvelope<InviteDto> {
        try await ApiClient.snake("\(base)/groups/\(group)/invite/reset", method: "POST", body: expiresInHours.map { ["expires_in_hours": .int($0)] } ?? [:])
    }

    // Scheduled messages

    static func scheduled(_ conversation: String) async throws -> ApiEnvelope<[ScheduledMessageDto]> { try await ApiClient.snake("\(base)/conversations/\(conversation)/scheduled") }
    /// `send_at`: ISO-8601 with its offset.
    static func schedule(_ conversation: String, _ body: [String: Any?]) async throws -> ApiEnvelope<ScheduledMessageDto> {
        try await ApiClient.snake("\(base)/conversations/\(conversation)/scheduled", method: "POST", body: json(body))
    }
    static func updateScheduled(_ id: String, _ body: [String: Any?]) async throws -> ApiEnvelope<ScheduledMessageDto> {
        try await ApiClient.snake("\(base)/scheduled/\(id)", method: "PATCH", body: json(body))
    }
    static func deleteScheduled(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/scheduled/\(id)", method: "DELETE") }
    static func sendScheduledNow(_ id: String) async throws -> ApiEnvelope<MessageDto> { try await ApiClient.snake("\(base)/scheduled/\(id)/send", method: "POST", body: [:]) }

    // AI (each call is one tap)

    static func aiCapabilities() async throws -> ApiEnvelope<AiCapabilitiesDto> { try await ApiClient.snake("\(base)/ai") }
    /// What's on where I am (the admin's rules by country and city).
    static func features() async throws -> ApiEnvelope<ChatFeaturesDto> { try await ApiClient.snake("\(base)/features") }
    /// The server keeps only the nearest city.
    static func locate(latitude: Double, longitude: Double) async throws -> ApiEnvelope<ChatFeaturesDto> {
        try await ApiClient.snake("\(base)/location", method: "PUT", body: ["latitude": .double(latitude), "longitude": .double(longitude)])
    }
    static func translate(_ id: String, to: String? = nil) async throws -> ApiEnvelope<AiTextDto> {
        try await ApiClient.snake("\(base)/messages/\(id)/translate", method: "POST", body: to.map { ["to": .string($0)] } ?? [:])
    }
    static func transcribe(_ id: String) async throws -> ApiEnvelope<AiTextDto> { try await ApiClient.snake("\(base)/messages/\(id)/transcribe", method: "POST", body: [:]) }
    static func summarize(_ id: String, unreadOnly: Bool) async throws -> ApiEnvelope<AiSummaryDto> {
        try await ApiClient.snake("\(base)/conversations/\(id)/summarize", method: "POST", body: ["unread_only": .bool(unreadOnly)])
    }
    static func smartReplies(_ id: String) async throws -> ApiEnvelope<AiRepliesDto> { try await ApiClient.snake("\(base)/conversations/\(id)/smart-replies", method: "POST", body: [:]) }

    // Business tools

    static func business() async throws -> ApiEnvelope<BusinessDto> { try await ApiClient.snake("\(base)/business") }
    static func updateBusiness(_ body: [String: JSONValue]) async throws -> ApiEnvelope<BusinessProfileDto> { try await ApiClient.snake("\(base)/business", method: "PATCH", body: body) }
    static func quickReplies() async throws -> ApiEnvelope<[QuickReplyDto]> { try await ApiClient.snake("\(base)/quick-replies") }
    static func addQuickReply(shortcut: String, body: String) async throws -> ApiEnvelope<QuickReplyDto> {
        try await ApiClient.snake("\(base)/quick-replies", method: "POST", body: ["shortcut": .string(shortcut), "body": .string(body)])
    }
    static func updateQuickReply(_ id: Int, shortcut: String, body: String) async throws -> ApiEnvelope<QuickReplyDto> {
        try await ApiClient.snake("\(base)/quick-replies/\(id)", method: "PATCH", body: ["shortcut": .string(shortcut), "body": .string(body)])
    }
    static func deleteQuickReply(_ id: Int) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/quick-replies/\(id)", method: "DELETE") }

    // Contacts, privacy, presence

    static func contacts(registered: Int = 0, q: String? = nil, page: Int? = nil, perPage: Int? = nil) async throws -> ApiEnvelope<[ContactDto]> {
        try await ApiClient.snake("\(base)/contacts", query: ["registered": String(registered), "q": q, "page": page.map(String.init), "per_page": perPage.map(String.init)])
    }
    static func syncContacts(_ contacts: [(name: String, phone: String)], full: Bool) async throws -> ApiEnvelope<[ContactDto]> {
        try await ApiClient.snake("\(base)/contacts/sync", method: "POST", body: [
            "contacts": .array(contacts.map { .object(["name": .string($0.name), "phone": .string($0.phone)]) }), "full": .bool(full),
        ])
    }
    static func addContact(name: String, phone: String) async throws -> ApiEnvelope<ContactDto> {
        try await ApiClient.snake("\(base)/contacts", method: "POST", body: ["name": .string(name), "phone": .string(phone)])
    }
    static func lookup(_ body: [String: String]) async throws -> ApiEnvelope<ProfileDto> {
        try await ApiClient.snake("\(base)/contacts/lookup", method: "POST", body: body.mapValues { .string($0) })
    }
    static func myQr() async throws -> ApiEnvelope<QrDto> { try await ApiClient.snake("\(base)/contacts/qr") }
    /// «Click to chat» (backlog 130): my link + QR, with a message ready when `text` is given.
    static func chatLink(text: String?) async throws -> ApiEnvelope<ChatLinkDto> { try await ApiClient.snake("\(base)/contacts/chat-link", query: ["text": text]) }
    static func resetQr() async throws -> ApiEnvelope<QrDto> { try await ApiClient.snake("\(base)/contacts/qr/reset", method: "POST", body: [:]) }
    static func resolveQr(_ payload: String) async throws -> ApiEnvelope<ProfileDto> {
        try await ApiClient.snake("\(base)/contacts/qr/resolve", method: "POST", body: ["payload": .string(payload)])
    }
    static func privacy() async throws -> ApiEnvelope<PrivacyDto> { try await ApiClient.snake("\(base)/privacy") }
    /// Keeps `null`s (e.g. `privacy_schedule: null` turns the schedule off).
    static func updatePrivacy(_ body: [String: Any?]) async throws -> ApiEnvelope<PrivacyDto> { try await ApiClient.snake("\(base)/privacy", method: "PATCH", body: json(body)) }
    /// Every chat notification shows nothing for `minutes`.
    static func privacyModeOn(minutes: Int) async throws -> ApiEnvelope<PrivacyDto> { try await ApiClient.snake("\(base)/privacy-mode", method: "PUT", body: ["minutes": .int(minutes)]) }
    static func privacyModeOff() async throws -> ApiEnvelope<PrivacyOffDto> { try await ApiClient.snake("\(base)/privacy-mode", method: "DELETE") }
    static func privacySummary(from: String) async throws -> ApiEnvelope<PrivacySummaryDto> { try await ApiClient.snake("\(base)/privacy-mode/summary", query: ["from": from]) }
    static func blocks(q: String? = nil, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[BlockDto]> {
        try await ApiClient.snake("\(base)/blocks", query: ["q": q, "page": String(page), "per_page": String(perPage)])
    }
    static func block(participantId: Int) async throws -> ApiEnvelope<[BlockDto]> { try await ApiClient.snake("\(base)/blocks", method: "POST", body: ["participant_id": .int(participantId)]) }
    static func unblock(participantId: Int) async throws -> ApiEnvelope<[BlockDto]> {
        try await ApiClient.snake("\(base)/blocks/remove", method: "POST", body: ["participant_id": .int(participantId)])
    }
    static func presence(online: Bool) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/presence", method: "POST", body: ["online": .bool(online)]) }

    // Folders

    static func folders() async throws -> ApiEnvelope<[FolderDto]> { try await ApiClient.snake("\(base)/folders") }
    static func createFolder(_ body: [String: String]) async throws -> ApiEnvelope<FolderDto> { try await ApiClient.snake("\(base)/folders", method: "POST", body: body.mapValues { .string($0) }) }
    static func renameFolder(_ id: Int, _ body: [String: String]) async throws -> ApiEnvelope<FolderDto> {
        try await ApiClient.snake("\(base)/folders/\(id)", method: "PATCH", body: body.mapValues { .string($0) })
    }
    static func deleteFolder(_ id: Int) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/folders/\(id)", method: "DELETE") }
    static func folderConversations(_ id: Int, conversationIds: [String]) async throws -> ApiEnvelope<FolderDto> {
        try await ApiClient.snake("\(base)/folders/\(id)/conversations", method: "PUT", body: ["conversations": .array(conversationIds.map { .string($0) })])
    }

    // Stories

    static func stories(page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<StoryFeedDto> {
        try await ApiClient.snake("\(base)/stories", query: ["page": String(page), "per_page": String(perPage)])
    }
    static func postStory(fields: [String: String], file: ChatFile?) async throws -> ApiEnvelope<JSONAny?> {
        try await multipart("\(base)/stories", fields: fields, files: file.map { [$0] } ?? [])
    }
    static func deleteStory(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/stories/\(id)", method: "DELETE") }
    static func viewStory(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/stories/\(id)/view", method: "POST", body: [:]) }
    static func publicStories(page: Int = 1, perPage: Int = 10) async throws -> ApiEnvelope<PublicStoryFeedDto> {
        try await ApiClient.snake("\(base)/stories/public", query: ["page": String(page), "per_page": String(perPage)])
    }
    static func viewDorrStory(_ id: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/stories/dorr/\(id)/view", method: "POST", body: [:]) }
    static func reactStory(_ id: String, emoji: String?) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("\(base)/stories/\(id)/reaction", method: "PUT", body: ["emoji": emoji.map { .string($0) } ?? .null])
    }
    static func replyStory(_ id: String, body: String) async throws -> ApiEnvelope<MessageDto> {
        try await ApiClient.snake("\(base)/stories/\(id)/reply", method: "POST", body: ["body": .string(body)])
    }
    static func storyViewers(_ id: String) async throws -> ApiEnvelope<[StoryViewerDto]> { try await ApiClient.snake("\(base)/stories/\(id)/viewers") }
    static func storyPrivacy() async throws -> ApiEnvelope<StoryPrivacyDto> { try await ApiClient.snake("\(base)/stories/privacy") }
    static func updateStoryPrivacy(_ body: [String: Any?]) async throws -> ApiEnvelope<StoryPrivacyDto> { try await ApiClient.snake("\(base)/stories/privacy", method: "PUT", body: json(body)) }
    static func muteStories(_ body: [String: Any?]) async throws -> ApiEnvelope<StoryFeedDto> { try await ApiClient.snake("\(base)/stories/mute", method: "POST", body: json(body)) }

    // Calls

    static func calls(page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[CallDto]> {
        try await ApiClient.snake("\(base)/calls", query: ["page": String(page), "per_page": String(perPage)])
    }
    static func startCall(_ conversation: String, type: String, recordingConsent: Bool = false) async throws -> ApiEnvelope<CallSessionDto> {
        var body: [String: JSONValue] = ["type": .string(type)]
        if recordingConsent { body["recording_consent"] = .string("1") }
        return try await ApiClient.snake("\(base)/conversations/\(conversation)/calls", method: "POST", body: body)
    }
    static func call(_ id: String) async throws -> ApiEnvelope<CallDto> { try await ApiClient.snake("\(base)/calls/\(id)") }
    static func callLinks(page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[CallLinkDto]> {
        try await ApiClient.snake("\(base)/call-links", query: ["page": String(page), "per_page": String(perPage)])
    }
    static func createCallLink(type: String) async throws -> ApiEnvelope<CallLinkDto> { try await ApiClient.snake("\(base)/call-links", method: "POST", body: ["type": .string(type)]) }
    static func callLink(_ token: String) async throws -> ApiEnvelope<CallLinkDto> { try await ApiClient.snake("\(base)/call-links/\(token)") }
    static func joinCallLink(_ token: String) async throws -> ApiEnvelope<CallLinkJoinDto> { try await ApiClient.snake("\(base)/call-links/\(token)/join", method: "POST", body: [:]) }
    static func revokeCallLink(_ token: String) async throws -> ApiEnvelope<JSONAny?> { try await ApiClient.snake("\(base)/call-links/\(token)", method: "DELETE") }
    /// `recording_consent`: my yes, needed when the call records or asks to.
    static func acceptCall(_ id: String, recordingConsent: Bool? = nil) async throws -> ApiEnvelope<CallSessionDto> {
        try await ApiClient.snake("\(base)/calls/\(id)/accept", method: "POST", body: recordingConsent == true ? ["recording_consent": .bool(true)] : [:])
    }
    static func requestRecording(_ id: String) async throws -> ApiEnvelope<CallRecordingStateDto> { try await ApiClient.snake("\(base)/calls/\(id)/recording", method: "POST", body: [:]) }
    static func answerRecording(_ id: String, agree: Bool) async throws -> ApiEnvelope<CallRecordingStateDto> {
        try await ApiClient.snake("\(base)/calls/\(id)/recording/consent", method: "POST", body: ["accept": .bool(agree)])
    }
    static func stopRecording(_ id: String) async throws -> ApiEnvelope<CallRecordingStateDto> { try await ApiClient.snake("\(base)/calls/\(id)/recording/stop", method: "POST", body: [:]) }
    static func callRecordings(_ id: String) async throws -> ApiEnvelope<[CallRecordingDto]> { try await ApiClient.snake("\(base)/calls/\(id)/recordings") }
    static func declineCall(_ id: String) async throws -> ApiEnvelope<CallSessionDto> { try await ApiClient.snake("\(base)/calls/\(id)/decline", method: "POST", body: [:]) }
    static func leaveCall(_ id: String) async throws -> ApiEnvelope<CallSessionDto> { try await ApiClient.snake("\(base)/calls/\(id)/leave", method: "POST", body: [:]) }
}

extension JSONValue {
    /// A Swift value as JSON (strings, numbers, bools, arrays, maps; nil → null).
    static func of(_ v: Any?) -> JSONValue {
        switch v {
        case nil: return .null
        case let x as JSONValue: return x
        case let x as String: return .string(x)
        case let x as Bool: return .bool(x)
        case let x as Int: return .int(x)
        case let x as Int64: return .int(Int(x))
        case let x as Double: return .double(x)
        case let x as [Any?]: return .array(x.map { of($0) })
        case let x as [String: Any?]: return .object(x.mapValues { of($0) })
        case let x as [String]: return .array(x.map { .string($0) })
        case let x as [Int]: return .array(x.map { .int($0) })
        default:
            if case Optional<Any>.none = v as Any? { return .null }
            return .string("\(v!)")
        }
    }
}
