import Combine
import SwiftUI

// androidApp `chat/ChatHost.kt` — the chat's own page stack and the state every chat page shares for one visit: the
// chat list (kept live from real-time events — a new message moves a chat to the top), who is typing where, who is
// online, the stories bar, and a toast.

/// The chat's pages.
enum ChRoute: Hashable {
    case list
    case conversation(String)
    case info(String)
    /// Everything shared in a chat — `tab` opens on photos & videos, files, links or voice.
    case media(String, tab: String = "media")
    case chatTheme(String)
    case chatMoney(String)
    case groupMembers(String)
    case newChat
    case channels
    /// A new group — or, with `addTo`, more members for an existing one.
    case newGroup(addTo: String? = nil)
    case myQr
    case myStatus
    case chatSettings
    case privacy
    case blockedContacts
    case business
    case starred
    case readLater
    case calls
    case communities
    case community(String)
    case storyComposer(media: String? = nil)
    case storyPrivacy
    case thread(conversationId: String, rootId: String)
    case decisions(conversationId: String, isAdmin: Bool)
    case broadcasts
    case broadcast(String)
    case tasks
    case catchUp
    case important
    case today
    case privacyCenter
    case decisionRoom(String)
    case commitments
    case commRules
    case contextRules(String)
    case lifeInbox
    case dataPerformance
    case usage
    case storage(conversationId: String? = nil, title: String? = nil)
    case channelStats(String)
    case voiceRoom(conversationId: String, title: String?)
    case liveViewer(String)
}

/// The full-screen story viewer: whose stories it pages through, and where it starts.
struct StoryViewerSession: Equatable {
    let groups: [StoryGroupDto]
    let start: Int
}

/// What someone is doing right now in a conversation.
struct ChatActivity: Equatable {
    let participant: String
    let state: String
}

/// The answer to my request to join a group.
struct JoinDecision: Equatable {
    let conversationId: String
    let groupName: String
    let approved: Bool
}

extension JSONAny {
    var obj: [String: JSONAny]? { if case .object(let m) = self { return m } else { return nil } }
    var arr: [JSONAny]? { if case .array(let a) = self { return a } else { return nil } }
    var dbl: Double? {
        switch self {
        case .number(let v): return v
        case .string(let s): return Double(s)
        default: return nil
        }
    }
    var isNull: Bool { if case .null = self { return true } else { return false } }

    /// Decoded as a DTO that names its own keys (`CodingKeys` with the API's snake names).
    func plain<T: Decodable>(_ type: T.Type) -> T? {
        guard let data = try? JSONEncoder().encode(self) else { return nil }
        return try? JSONDecoder().decode(T.self, from: data)
    }

    /// Decoded as a DTO (through the snake decoder, so nested names read like the API's).
    func decode<T: Decodable>(_ type: T.Type) -> T? {
        guard let data = try? JSONEncoder().encode(self) else { return nil }
        return try? ApiClient.snakeDecoder.decode(T.self, from: data)
    }
}

@MainActor
final class ChatHost: ObservableObject {
    static let page = 15

    @Published private(set) var stack: [ChRoute] = [.list]
    @Published private(set) var forward = true
    var onExit: () -> Void
    let openWalletQr: (String) -> Void

    var current: ChRoute { stack.last ?? .list }
    /// A wide screen (iPad, landscape — spec 89): the chat list stays on the side and every other page opens beside
    /// it. Set by ChatScreen from the space it really has.
    var twoPane = false
    /// What opens beside the list: the page on top, unless that's the list itself.
    var detail: ChRoute? { current == .list ? nil : current }
    /// The conversation open (to keep its badge clear).
    var openConversationId: String? {
        for r in stack.reversed() { if case .conversation(let id) = r { return id } }
        return nil
    }

    @Published var conversations: [ConversationDto] = []
    @Published var requestsCount = ChatStore.shared.requestsCount
    @Published var listLoaded = false
    @Published var listFailed = false
    @Published var filter = "all"
    @Published var listHasMore = false
    @Published var listLoadingMore = false
    private var listPage = 1

    /// conversation id → people typing / recording there.
    @Published var activity: [String: [ChatActivity]] = [:]
    /// participant key → online / last seen.
    @Published var presence: [String: PresenceDto] = [:]

    @Published var stories: StoryFeedDto?
    @Published var storiesLoadingMore = false
    private var storiesPage = 1
    @Published var storyViewer: StoryViewerSession?
    @Published var storyUploading = false
    /// The home page's host: stories posted from it are public, and «refresh» reloads the home circles.
    var publicMode = false
    var onRefreshStories: (() async -> Void)?

    @Published var toast: String?
    @Published private(set) var toastError = false
    private var toastTask: Task<Void, Never>?
    private var typingTasks: [String: Task<Void, Never>] = [:]

    /// My folders and privacy circles (the chips after All / Unread / Groups / Archived).
    @Published var folders: [FolderDto] = []
    @Published var circles: [CircleDto] = []
    @Published var fillingFolder: FolderDto?

    /// A message the conversation should scroll to and flash once open (conversation id, message id).
    @Published var focusRequest: (String, String)?
    @Published var joinToken: String?
    @Published var callLinkToken: String?
    /// The recordings of this call I agreed to (spec 92), in a sheet.
    @Published var recordingsCall: String?
    @Published var joinDecided: JoinDecision?
    /// Which chat-settings group is open, kept while QR / starred / privacy sit on top of it.
    @Published var chatSettingsSection = "menu"

    private var bag = Set<AnyCancellable>()

    init(onExit: @escaping () -> Void, openWalletQr: @escaping (String) -> Void) {
        self.onExit = onExit
        self.openWalletQr = openWalletQr
        ChatRealtime.shared.events
            .receive(on: DispatchQueue.main)
            .sink { [weak self] e in self?.handle(e) }
            .store(in: &bag)
    }

    // MARK: Stack

    func push(_ route: ChRoute) {
        // Side by side, a conversation takes the place of whatever was open beside the list (like WhatsApp on a
        // tablet) — or, already open there, it comes back to the front.
        if twoPane, case .conversation(let id) = route {
            if let open = stack.firstIndex(of: .conversation(id)) {
                forward = false
                withAnimation(.easeOut(duration: 0.28)) { stack.removeSubrange((open + 1)...) }
                return
            }
            stack.removeSubrange(1...)
        }
        forward = true
        withAnimation(.spring(response: 0.38, dampingFraction: 0.9)) { stack.append(route) }
    }

    func pop() {
        if stack.count <= 1 { onExit(); return }
        forward = false
        let leaving = stack.last
        withAnimation(.easeOut(duration: 0.28)) { _ = stack.removeLast() }
        if leaving == .chatSettings { chatSettingsSection = "menu" }
    }

    /// Replace the top page (e.g. «New chat» → the conversation it opened).
    func replace(_ route: ChRoute) {
        if twoPane, case .conversation = route {
            push(route)
            return
        }
        forward = true
        withAnimation(.spring(response: 0.38, dampingFraction: 0.9)) { stack[stack.count - 1] = route }
    }

    /// Open the message-requests list.
    func showRequests() {
        filter = "requests"
        chatSettingsSection = "menu"
        forward = false
        withAnimation { stack = [.list] }
        Task { await refreshList() }
    }

    /// «Show in chat»: back to that conversation if it's open under this page, or open it; then scroll to the message.
    func showInChat(_ conversationId: String, _ messageId: String) {
        focusRequest = (conversationId, messageId)
        if let open = stack.lastIndex(of: .conversation(conversationId)) {
            forward = false
            withAnimation { stack = Array(stack.prefix(open + 1)) }
        } else {
            push(.conversation(conversationId))
        }
    }

    /// Opens (or creates) the direct chat with someone and moves to it.
    func openChatWith(_ profile: ProfileDto, draft: String? = nil, keepCurrent: Bool = false) {
        Task {
            do {
                guard let conversation = try await ChatApi.openDirect(participantId: profile.id, participantType: profile.type).data else { return }
                // A «click to chat» link's message waits in the composer (backlog 130).
                if let draft, !draft.isEmpty { ChatStore.shared.saveDraft(conversation.id, draft) }
                upsert(conversation)
                if keepCurrent { push(.conversation(conversation.id)) } else { replace(.conversation(conversation.id)) }
            } catch {
                if let msg = error.apiFailure.message { showToast(msg, error: true) }
            }
        }
    }

    /// A «click to chat» link or QR (dorr://chat/{token}?text=…): that person's chat, the message ready.
    func openChatLink(_ payload: String, keepCurrent: Bool = true) {
        Task {
            do {
                guard let profile = try await ChatApi.resolveQr(payload).data else { return }
                let text = URLComponents(string: payload)?.queryItems?.first { $0.name == "text" }?.value
                openChatWith(profile, draft: text, keepCurrent: keepCurrent)
            } catch {
                if let msg = error.apiFailure.message { showToast(msg, error: true) }
            }
        }
    }

    /// Opens my «note to self» chat (created on first use).
    func openNoteToSelf() {
        Task {
            do {
                guard let conversation = try await ChatApi.openSelf().data else { return }
                upsert(conversation)
                replace(.conversation(conversation.id))
            } catch {
                if let msg = error.apiFailure.message { showToast(msg, error: true) }
            }
        }
    }

    func showToast(_ message: String, error: Bool = false) {
        guard !message.trimmingCharacters(in: .whitespaces).isEmpty else { return }
        toastError = error
        withAnimation(.spring(response: 0.4, dampingFraction: 0.6)) { toast = message }
        toastTask?.cancel()
        toastTask = Task {
            try? await Task.sleep(nanoseconds: 2_300_000_000)
            guard !Task.isCancelled else { return }
            withAnimation { toast = nil }
        }
    }

    // MARK: Stories

    func refreshStories() async {
        if let onRefreshStories { await onRefreshStories(); return }
        if let s = try? await ChatApi.stories(page: 1, perPage: Self.page).data {
            stories = s
            storiesPage = 1
        }
    }

    func loadMoreStories() async {
        guard !publicMode, let current = stories, !storiesLoadingMore, current.hasMore == true else { return }
        storiesLoadingMore = true
        defer { storiesLoadingMore = false }
        guard let next = try? await ChatApi.stories(page: storiesPage + 1, perPage: Self.page).data else { return }
        let known = Set(((current.recent ?? []) + (current.muted ?? [])).compactMap { $0.owner?.key })
        var merged = current
        merged.recent = (current.recent ?? []) + (next.recent ?? []).filter { !known.contains($0.owner?.key ?? "") }
        merged.muted = (current.muted ?? []) + (next.muted ?? []).filter { !known.contains($0.owner?.key ?? "") }
        merged.hasMore = next.hasMore
        stories = merged
        storiesPage += 1
    }

    func openStories(_ groups: [StoryGroupDto], start: Int) {
        guard !groups.isEmpty else { return }
        storyViewer = StoryViewerSession(groups: groups, start: min(max(start, 0), groups.count - 1))
    }

    // MARK: List

    func refreshFolders() async {
        if let f = try? await ChatApi.folders().data { folders = f }
    }

    private func listQuery() -> (filter: String?, folder: Int?, circle: String?) {
        let folder = filter.hasPrefix("folder:") ? Int(filter.dropFirst(7)) : nil
        let circle = filter.hasPrefix("circle:") ? String(filter.dropFirst(7)) : nil
        return (filter != "all" && folder == nil && circle == nil ? filter : nil, folder, circle)
    }

    func refreshList() async {
        // Offline first: the last list saved on the phone shows at once (and without internet).
        if !listLoaded && filter == "all", let cached = ChatStore.shared.conversations(), !cached.isEmpty {
            conversations = cached
            requestsCount = ChatStore.shared.requestsCount
            listLoaded = true
        }
        do {
            let q = listQuery()
            let result = try await ChatApi.conversations(filter: q.filter, folder: q.folder, page: 1, circle: q.circle, perPage: Self.page)
            conversations = result.data ?? []
            requestsCount = result.requestsCount ?? 0
            listPage = 1
            listHasMore = result.pagination?.hasMorePages == true
            listFailed = false
            if filter == "all" {
                ChatStore.shared.saveConversations(conversations)
                ChatStore.shared.requestsCount = requestsCount
            }
        } catch {
            if !listLoaded { listFailed = true }
        }
        listLoaded = true
    }

    func loadMoreList() async {
        guard !listLoadingMore, listHasMore else { return }
        listLoadingMore = true
        defer { listLoadingMore = false }
        let q = listQuery()
        guard let result = try? await ChatApi.conversations(filter: q.filter, folder: q.folder, page: listPage + 1, circle: q.circle, perPage: Self.page) else { return }
        let known = Set(conversations.map(\.id))
        conversations += (result.data ?? []).filter { !known.contains($0.id) }
        listPage += 1
        listHasMore = result.pagination?.hasMorePages == true
    }

    /// Put one updated conversation back in the list, pinned chats on top.
    func upsert(_ c: ConversationDto) {
        withAnimation(.spring(response: 0.4, dampingFraction: 0.85)) {
            conversations.removeAll { $0.id == c.id }
            let archived = c.isArchived == true, request = c.isRequest == true, locked = c.isLocked == true
            let belongs: Bool = {
                switch filter {
                case "archived": return archived
                case "requests": return request
                case "locked": return locked
                case "groups": return c.isGroup && !c.isChannel && !archived && !locked
                case "channels": return c.isChannel && !archived && !locked
                case "unread": return ((c.unreadCount ?? 0) > 0 || c.markedUnread == true) && !archived && !locked
                default:
                    if filter.hasPrefix("folder:") { return folders.first { "folder:\($0.id)" == filter }?.conversationIds?.contains(c.id) == true }
                    return !archived && !request && !locked
                }
            }()
            guard belongs else { return }
            let at = c.isPinned == true ? 0 : (conversations.firstIndex { $0.isPinned != true } ?? conversations.count)
            conversations.insert(c, at: at)
        }
        if filter == "all" { ChatStore.shared.saveConversations(conversations) }
    }

    func remove(_ id: String) { withAnimation { conversations.removeAll { $0.id == id } } }
    func find(_ id: String) -> ConversationDto? { conversations.first { $0.id == id } }

    /// The open conversation read everything: clear its badge at once.
    func markReadLocally(_ id: String) {
        guard let i = conversations.firstIndex(where: { $0.id == id }) else { return }
        conversations[i].unreadCount = 0
        conversations[i].markedUnread = false
        conversations[i].hasUnreadMention = false
    }

    func reload(_ id: String) async {
        do {
            if let fresh = try await ChatApi.conversation(id).data, find(id) != nil || fresh.lastMessage != nil || fresh.isGroup { upsert(fresh) }
        } catch {
            // Gone (deleted for everyone): off the list.
            if error.apiFailure.httpStatus == 404 { remove(id) }
        }
    }

    // MARK: Real-time

    func handle(_ event: ChatEvent) {
        let data = event.data
        let conversationId = data.at("conversation_id")?.str
        switch event.name {
        case "chat.message.sent":
            guard let message = data.at("message"), let id = conversationId else { return }
            let senderKey = message.at("sender")?.at("key")?.str
            let mine = senderKey == myKey()
            if !mine && message.at("system")?.obj == nil { ChatUsage.messageReceived() }
            if let existing = find(id) {
                let last = LastMessageDto(
                    id: message.at("id")?.str ?? "", type: message.at("type")?.str ?? "text", body: message.at("body")?.str,
                    sender: message.at("sender")?.decode(ProfileDto.self), isMine: mine, status: mine ? "sent" : nil, isDeleted: false,
                    system: message.at("system")?.at("event")?.str, createdAt: message.at("created_at")?.str
                )
                var updated = existing
                updated.lastMessage = last
                updated.lastMessageAt = last.createdAt
                if !mine && id != openConversationId && senderKey != nil { updated.unreadCount = (existing.unreadCount ?? 0) + 1 }
                upsert(updated)
            } else {
                Task { await reload(id) }
            }
            if let senderKey { clearActivity(id, senderKey) }
        case "chat.message.deleted", "chat.message.updated", "chat.conversation.updated", "chat.group.join_requests":
            if let id = conversationId { Task { await reload(id) } }
        case "chat.reminder.due":
            showToast("⏰ " + (data.at("note")?.str ?? find(conversationId ?? "")?.title ?? ""))
        case "chat.receipt":
            guard let id = conversationId, let i = conversations.firstIndex(where: { $0.id == id }), let last = conversations[i].lastMessage, last.isMine == true else { return }
            let status: String
            if last.id == data.at("read_up_to")?.str { status = "read" }
            else if last.id == data.at("delivered_up_to")?.str { status = last.status == "read" ? "read" : "delivered" }
            else { return }
            conversations[i].lastMessage?.status = status
        case "chat.typing":
            guard let id = conversationId, let who = data.at("participant")?.str else { return }
            let state = data.at("state")?.str ?? "stopped"
            if state == "stopped" { clearActivity(id, who) } else { setActivity(id, ChatActivity(participant: who, state: state)) }
        case "chat.story.posted", "chat.story.deleted", "chat.story.viewed", "chat.story.reaction":
            Task { await refreshStories() }
        case "chat.presence":
            guard let who = data.at("participant")?.str else { return }
            presence[who] = PresenceDto(online: data.at("online")?.bool == true, lastSeenAt: data.at("last_seen_at")?.str)
        case "chat.group.join_decided":
            guard let id = conversationId else { return }
            let name = data.at("group_name")?.str ?? ""
            let approved = data.at("status")?.str == "approved"
            if approved { Task { await reload(id) } }
            withAnimation { joinDecided = JoinDecision(conversationId: id, groupName: name, approved: approved) }
        default: break
        }
    }

    func activity(_ conversationId: String) -> [ChatActivity] { activity[conversationId] ?? [] }

    private func setActivity(_ conversationId: String, _ a: ChatActivity) {
        activity[conversationId] = activity(conversationId).filter { $0.participant != a.participant } + [a]
        let key = "\(conversationId)|\(a.participant)"
        typingTasks[key]?.cancel()
        // The sender re-announces every few seconds; silence means they stopped.
        typingTasks[key] = Task {
            try? await Task.sleep(nanoseconds: 6_000_000_000)
            guard !Task.isCancelled else { return }
            clearActivity(conversationId, a.participant)
        }
    }

    private func clearActivity(_ conversationId: String, _ participant: String) {
        let left = activity(conversationId).filter { $0.participant != participant }
        activity[conversationId] = left.isEmpty ? nil : left
    }

    func sendTyping(_ conversationId: String, _ state: String) {
        Task { _ = try? await ChatApi.typing(conversationId, state: state) }
    }
}

/// The code in a group / channel invite: the web link (…/j/CODE), the app's (dorr://chat/join/CODE) or the code alone.
func inviteTokenOf(_ text: String) -> String? {
    let t = text.trimmingCharacters(in: .whitespacesAndNewlines)
    if let r = try? NSRegularExpression(pattern: "(?:dorr://chat/join/|https?://[^\\s/]+/j/)([A-Za-z0-9]{8,64})"),
       let m = r.firstMatch(in: t, range: NSRange(t.startIndex..., in: t)), let g = Range(m.range(at: 1), in: t) { return String(t[g]) }
    return t.range(of: "^[A-Za-z0-9]{8,64}$", options: .regularExpression) != nil ? t : nil
}

/// The code in a call link: …/call/CODE or dorr://chat/call/CODE.
func callLinkTokenOf(_ text: String) -> String? {
    let t = text.trimmingCharacters(in: .whitespacesAndNewlines)
    guard let r = try? NSRegularExpression(pattern: "(?:dorr://chat/call/|https?://[^\\s/]+/call/)([A-Za-z0-9]{8,64})"),
          let m = r.firstMatch(in: t, range: NSRange(t.startIndex..., in: t)), let g = Range(m.range(at: 1), in: t) else { return nil }
    return String(t[g])
}
