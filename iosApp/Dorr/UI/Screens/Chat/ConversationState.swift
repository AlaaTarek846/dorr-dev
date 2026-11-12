import Combine
import SwiftUI

// androidApp `chat/ConversationState.kt` — everything one open conversation knows and does: its messages (newest
// last), paging back through history (and forward after a jump), sending (optimistic — the bubble appears at once with
// ⏱ and turns into ✓ when the server answers; a failed one retries with the *same* uuid, by itself after 5 / 15 / 45 s
// and when the connection comes back), and the live updates for this chat (new messages, ticks, reactions, polls,
// edits, deletions, pins, a live location moving, a view-once opened).

/// A translation / voice transcript I asked the AI for, shown under the bubble.
struct AiNote: Equatable {
    let kind: String
    var text: String?
    var loading = false
    var failed: String?
}

/// One row in the conversation: the server's message plus what only this phone knows.
struct UiMessage: Identifiable, Equatable {
    var dto: MessageDto
    /// "pending" while uploading, "failed" when it didn't go; nil once the server has it.
    var local: String?
    /// Files picked on this phone, shown before the upload finishes.
    var localFiles: [ChatLocalFile] = []
    /// Plays the arrival animation once.
    var fresh = false
    /// Upload progress 0…1 while a file message goes up.
    var progress: Double?
    /// A video's poster made on this phone.
    var localThumb: URL?
    var ai: AiNote?

    var id: String { dto.id }
    @MainActor var isMine: Bool { dto.isMine ?? (dto.sender?.key == myKey()) }
    var status: String? { local ?? dto.status }
}

@MainActor
final class ConversationState: ObservableObject {
    let id: String
    private unowned let host: ChatHost

    @Published var conversation: ConversationDto?
    @Published var messages: [UiMessage] = []
    @Published var loading = true
    @Published var loadingOlder = false
    @Published var hasMoreBefore = false
    @Published var failed = false

    @Published var replyTo: MessageDto?
    /// «Ask about this chat»: the picked messages (empty = the latest), while the sheet is open.
    @Published var askAbout: [String]?
    /// A picked video being made into a GIF (the trim screen is open).
    @Published var gifSource: ChatLocalFile?
    /// A DORR AI tool open on one message: understand · simplify · tasks · remind.
    @Published var aiTool: (String, MessageDto)?
    @Published var editing: MessageDto?
    /// Text for the composer (a reply picked from an AI suggestion, a quick reply…).
    @Published var composerInsert: String?
    @Published var focused: UiMessage?
    @Published var pinned: [MessageDto] = []
    /// How many arrived while the list was scrolled up (on the ↓ button).
    @Published var unseenBelow = 0
    var atBottom = true
    /// After jumping to an old message, newer ones load as you scroll down.
    @Published var hasMoreAfter = false
    private var loadingNewer = false
    /// Slow mode: when I may send again; nil = now. Admins never wait.
    @Published var slowUntil: Date?
    /// Messages I unlocked (sensitive) or uncovered (blurred media) — until they hide again.
    @Published var revealed: [String: Date] = [:]
    /// My scheduled messages here (waiting or failed), soonest first.
    @Published private(set) var scheduled: [ScheduledMessageDto] = []
    /// Group members, for @mention suggestions (loaded on the first «@»).
    @Published private(set) var members: [MemberDto] = []
    @Published private(set) var smartReplies: [String] = []
    @Published private(set) var loadingReplies = false

    private var typingTask: Task<Void, Never>?
    private var lastTypingSent: Date?
    /// Messages that failed for lack of a connection → automatic tries so far.
    private var autoRetries: [String: Int] = [:]
    private static let autoRetryDelays: [UInt64] = [5, 15, 45]
    private var bag = Set<AnyCancellable>()

    init(id: String, host: ChatHost) {
        self.id = id
        self.host = host
        conversation = host.find(id)
        // While this chat is open it sends its own messages; the outbox leaves it alone.
        ChatOutbox.shared.opened(id)
        ChatRealtime.shared.events.receive(on: DispatchQueue.main).sink { [weak self] e in self?.onEvent(e) }.store(in: &bag)
        // Back online: everything that failed for lack of a connection goes again, by itself.
        NetworkMonitor.shared.$reconnectTick.dropFirst().receive(on: DispatchQueue.main).sink { [weak self] _ in
            guard let self else { return }
            for uuid in self.autoRetries.keys {
                self.autoRetries[uuid] = 0
                if let m = self.messages.first(where: { $0.id == uuid && $0.local == "failed" }) { self.retry(m, quiet: true) }
            }
        }.store(in: &bag)
        Task { await load() }
    }

    /// The page left: anything still unsent goes on in the background.
    func close() { ChatOutbox.shared.closed(id) }

    func reveal(_ id: String) {
        let at = Date()
        revealed[id] = at
        let seconds = ChatShield.shared.rehideSeconds
        // 0 = until I leave the chat (this state goes with it).
        if seconds > 0 {
            Task {
                try? await Task.sleep(nanoseconds: UInt64(seconds) * 1_000_000_000)
                if revealed[id] == at { revealed[id] = nil }
            }
        }
    }

    private func scheduleAutoRetry(_ uuid: String) {
        let tries = autoRetries[uuid] ?? 0
        guard tries < Self.autoRetryDelays.count else { return }
        autoRetries[uuid] = tries + 1
        Task {
            try? await Task.sleep(nanoseconds: Self.autoRetryDelays[tries] * 1_000_000_000)
            if let m = messages.first(where: { $0.id == uuid && $0.local == "failed" }) { retry(m, quiet: true) }
        }
    }

    // MARK: Loading

    func load() async {
        // Offline first: what this phone saved last time shows at once.
        if messages.isEmpty, let cached = ChatStore.shared.messages(id), !cached.isEmpty { messages = cached.map { UiMessage(dto: $0) } }
        // What I sent here that the server doesn't have yet: back in place, and sent again below.
        var restored: [UiMessage] = []
        for item in ChatOutbox.shared.pending(for: id) where !messages.contains(where: { $0.id == item.uuid }) {
            let ui = UiMessage(dto: item.message, local: "failed", localFiles: item.out.files, localThumb: item.out.thumbnail)
            messages.append(ui)
            if !item.rejected { restored.append(ui) }
        }
        loading = messages.isEmpty
        do {
            if let fresh = try await ChatApi.conversation(id).data { conversation = fresh }
            if let page = try await ChatApi.messages(id).data {
                let serverIds = Set((page.messages ?? []).map(\.id))
                // Keep bubbles still on their way up; replace the rest with the server's truth.
                let unsent = messages.filter { $0.local != nil && !serverIds.contains($0.id) }
                for p in page.messages ?? [] where p.isMine == true { ChatOutbox.shared.sent(p.id) }
                messages = (page.messages ?? []).map { UiMessage(dto: $0) } + unsent
                hasMoreBefore = page.hasMoreBefore == true
                hasMoreAfter = false
                persist()
            }
            failed = false
            await refreshPinned()
            await refreshScheduled()
            markRead()
        } catch {
            failed = messages.isEmpty
        }
        loading = false
        for ui in restored { if let m = messages.first(where: { $0.id == ui.id && $0.local != nil }) { retry(m, quiet: true) } }
    }

    /// Open the page around one message (a search result / pinned message not loaded yet).
    func loadAround(_ messageId: String) async -> Bool {
        guard let page = try? await ChatApi.messages(id, around: messageId).data else { return false }
        messages = (page.messages ?? []).map { UiMessage(dto: $0) }
        hasMoreBefore = page.hasMoreBefore == true
        hasMoreAfter = page.hasMoreAfter == true
        return true
    }

    func loadNewer() async {
        guard !loadingNewer, hasMoreAfter, let last = messages.last(where: { $0.local == nil }) else { return }
        loadingNewer = true
        if let page = try? await ChatApi.messages(id, after: last.id).data {
            messages += (page.messages ?? []).map { UiMessage(dto: $0) }
            hasMoreAfter = page.hasMoreAfter == true
        }
        loadingNewer = false
    }

    func loadOlder() async {
        guard !loadingOlder, hasMoreBefore, let first = messages.first(where: { $0.local == nil }) else { return }
        loadingOlder = true
        if let page = try? await ChatApi.messages(id, before: first.id).data {
            messages.insert(contentsOf: (page.messages ?? []).map { UiMessage(dto: $0) }, at: 0)
            hasMoreBefore = page.hasMoreBefore == true
        }
        loadingOlder = false
    }

    func loadMembers() async {
        guard members.isEmpty, conversation?.isGroup == true else { return }
        members = (try? await ChatApi.members(id).data) ?? []
    }

    /// Save the latest messages for offline reading.
    private func persist() {
        if !hasMoreAfter { ChatStore.shared.saveMessages(id, messages.filter { $0.local == nil }.map(\.dto)) }
    }

    func markRead() {
        host.markReadLocally(id)
        guard let c = conversation, c.isRequest != true else { return }
        Task { _ = try? await ChatApi.markRead(id) }
    }

    func refreshPinned() async { pinned = (try? await ChatApi.pinned(id).data) ?? [] }
    func refreshScheduled() async { if let s = try? await ChatApi.scheduled(id).data { scheduled = s } }

    /// Write now, send at `sendAt` (the server sends it, even with this phone off).
    func schedule(_ body: String, sendAt: Date, silent: Bool = false) async -> Bool {
        do {
            var b: [String: Any?] = ["body": body, "send_at": isoWithOffset(sendAt)]
            if silent { b["silent"] = true }
            if let row = try await ChatApi.schedule(id, b).data { scheduled = (scheduled + [row]).sorted { $0.sendAt < $1.sendAt } }
            return true
        } catch {
            host.showToast(error.apiFailure.message ?? L("ch_error_network"))
            return false
        }
    }

    // MARK: AI (each one tap)

    func translate(_ m: UiMessage) { askAi(m, "translation") { try await ChatApi.translate(m.id, to: AppLocale.current).data?.text } }
    func transcribe(_ m: UiMessage) { askAi(m, "transcript") { try await ChatApi.transcribe(m.id).data?.text } }
    func hideAi(_ m: UiMessage) { setAi(m.id, nil) }

    private func askAi(_ m: UiMessage, _ kind: String, _ call: @escaping () async throws -> String?) {
        setAi(m.id, AiNote(kind: kind, loading: true))
        Task {
            do { setAi(m.id, AiNote(kind: kind, text: try await call())) } catch { setAi(m.id, AiNote(kind: kind, failed: error.apiFailure.message ?? L("ch_error_network"))) }
        }
    }

    private func setAi(_ id: String, _ note: AiNote?) {
        guard let i = messages.firstIndex(where: { $0.id == id }) else { return }
        messages[i].ai = note
        messages[i].fresh = false
    }

    func loadSmartReplies() {
        guard !loadingReplies else { return }
        loadingReplies = true
        Task {
            do { smartReplies = try await ChatApi.smartReplies(id).data?.replies ?? [] } catch { host.showToast(error.apiFailure.message ?? L("ch_error_network")) }
            loadingReplies = false
        }
    }

    func clearSmartReplies() { smartReplies = [] }

    /// Slow mode applies to me here: a member (not an admin) of a group that has it on.
    var slowModeSeconds: Int {
        guard let c = conversation, c.isGroup, !c.isChannel, !c.isAdmin else { return 0 }
        return c.group?.slowModeSeconds ?? 0
    }

    // MARK: Sending

    /// `linkPreview`: the card of the message's link, already shown while typing — drawn with it at once.
    func send(_ out: Outgoing, linkPreview: LinkPreviewDto? = nil) {
        let uuid = UUID().uuidString.lowercased()
        let me = AuthSession.shared.user
        var meta: JSONAny?
        if !out.extra.isEmpty { meta = .object(out.extra) }
        let ints = { (k: String) -> [String] in out.extra[k]?.arr?.compactMap(\.str) ?? [] }
        var optimistic = MessageDto(id: uuid, conversationId: id, type: out.type, body: out.body, meta: meta, attachments: [],
                                    sender: profileOf(me), isMine: true, status: nil,
                                    replyTo: replyTo.map { ReplyPreviewDto(id: $0.id, type: $0.type, body: $0.body, sender: $0.sender, thumbnail: nil, isDeleted: false) },
                                    expiresAt: nil, system: nil, createdAt: isoWithOffset(Date()))
        // Drawn right away while it uploads: the poll's options, the «1» of a view-once, a money card, a link's card.
        optimistic.viewOnce = out.extra["view_once"] != nil
        optimistic.linkPreview = linkPreview
        if out.type == "poll" {
            optimistic.poll = PollDto(question: out.body, multiple: out.extra["poll_multiple"]?.bool == true,
                                      options: ints("poll_options").enumerated().map { PollOptionDto(id: $0.offset + 1, text: $0.element, votes: 0) }, myVotes: [], voters: 0)
        }
        if out.type == "interactive" {
            let descriptions = ints("interactive_descriptions")
            optimistic.interactive = InteractiveDto(kind: out.extra["interactive_kind"]?.str ?? "buttons",
                                                    options: ints("interactive_options").enumerated().map { InteractiveOptionDto(id: $0.offset + 1, title: $0.element, description: descriptions[safe: $0.offset].flatMap { $0.isEmpty ? nil : $0 }) },
                                                    button: out.extra["interactive_button"]?.str, footer: out.extra["interactive_footer"]?.str)
        }
        let amount = Int64(out.extra["amount_minor"]?.int ?? 0)
        if out.type == "money_request" { optimistic.payment = PaymentDto(kind: "request", status: "pending", amountMinor: amount, isRequester: true) }
        if out.type == "bill_split" { optimistic.payment = PaymentDto(kind: "split", status: "open", totalMinor: amount, isRequester: true) }
        let replyId = replyTo?.id
        replyTo = nil
        // Saved on the phone first: it survives no connection and the app being closed.
        let stored = ChatOutbox.shared.add(uuid: uuid, conversationId: id, out: out, replyId: replyId, message: optimistic)
        withAnimation(.spring(response: 0.4, dampingFraction: 0.7)) {
            messages.append(UiMessage(dto: optimistic, local: "pending", localFiles: stored.files, fresh: true, localThumb: stored.thumbnail))
        }
        sendTyping(stop: true)
        if slowModeSeconds > 0 { slowUntil = Date().addingTimeInterval(TimeInterval(slowModeSeconds)) }
        upload(uuid, stored, replyId)
    }

    /// `quiet`: an automatic try — no toast if it fails again.
    func retry(_ m: UiMessage, quiet: Bool = false) {
        guard let i = messages.firstIndex(where: { $0.id == m.id }) else { return }
        messages[i].local = "pending"
        ChatOutbox.shared.retrying(m.id)
        let kept = ChatOutbox.shared.pending(for: id).first { $0.uuid == m.id }
        let out = kept?.out ?? Outgoing(type: m.dto.type, body: m.dto.body, files: m.localFiles, extra: m.dto.meta?.obj ?? [:], thumbnail: m.localThumb)
        upload(m.id, out, kept?.replyTo ?? m.dto.replyTo?.id, quiet: quiet)
    }

    private func upload(_ uuid: String, _ out: Outgoing, _ replyId: String?, quiet: Bool = false) {
        Task {
            do {
                let sent = try await ChatSender.send(conversationId: id, uuid: uuid, out: out, replyId: replyId) { p in
                    Task { @MainActor in
                        if let i = self.messages.firstIndex(where: { $0.id == uuid }), self.messages[i].local == "pending" { self.messages[i].progress = p }
                    }
                }
                ChatOutbox.shared.sent(uuid)
                autoRetries[uuid] = nil
                if let dto = sent.data {
                    replaceMessage(uuid, UiMessage(dto: dto))
                    // A live location starts its position updates as soon as the server has it.
                    if dto.liveLocation?.active == true, let until = dto.liveLocation?.liveUntil { LiveLocationSharing.shared.start(dto.id, until: until) }
                }
            } catch {
                let failure = error.apiFailure
                if let i = messages.firstIndex(where: { $0.id == uuid }) { messages[i].local = "failed" }
                if error is VideoTooBigError {
                    autoRetries[uuid] = nil
                    ChatOutbox.shared.rejected(uuid)
                    if !quiet { host.showToast(L("bk_video_too_big_slow")) }
                    return
                }
                // Slow mode: it goes by itself once the wait is over, the send button counting down meanwhile.
                if failure.errorCode == "chat_slow_mode" {
                    let wait = max(failure.retryAfter ?? slowModeSeconds, 1)
                    slowUntil = Date().addingTimeInterval(TimeInterval(wait))
                    Task {
                        try? await Task.sleep(nanoseconds: UInt64(wait) * 1_000_000_000 + 500_000_000)
                        if let m = messages.first(where: { $0.id == uuid && $0.local == "failed" }) { retry(m, quiet: true) }
                    }
                    if !quiet, let msg = failure.message { host.showToast(msg) }
                    return
                }
                // No answer at all (offline, timeout): again by itself. A real «no» from the server is final.
                if failure.httpStatus == nil {
                    scheduleAutoRetry(uuid)
                } else {
                    autoRetries[uuid] = nil
                    ChatOutbox.shared.rejected(uuid)
                }
                if !quiet { host.showToast(failure.message ?? L("ch_error_network")) }
            }
        }
    }

    func edit(_ m: MessageDto, body: String) {
        editing = nil
        Task {
            do { if let dto = try await ChatApi.edit(m.id, body: body).data { replaceMessage(dto.id, UiMessage(dto: dto)) } } catch { if let msg = error.apiFailure.message { host.showToast(msg) } }
        }
    }

    func deleteForEveryone(_ m: MessageDto) {
        Task {
            do { if let dto = try await ChatApi.deleteForEveryone(m.id).data { replaceMessage(dto.id, UiMessage(dto: dto)) } } catch { if let msg = error.apiFailure.message { host.showToast(msg) } }
        }
    }

    func deleteForMe(_ m: MessageDto) {
        let index = messages.firstIndex { $0.id == m.id }
        let removed = index.map { messages[$0] }
        withAnimation { messages.removeAll { $0.id == m.id } }
        // An unsent one is simply dropped from the outbox (never sent).
        ChatOutbox.shared.sent(m.id)
        // The phone's saved copy too, or it shows again on the next open before the server answers.
        persist()
        Task {
            do {
                _ = try await ChatApi.deleteForMe(id, ids: [m.id])
            } catch {
                if let removed, let index, !messages.contains(where: { $0.id == removed.id }) {
                    messages.insert(removed, at: min(index, messages.count))
                    persist()
                }
                host.showToast(error.apiFailure.message ?? L("ch_error_network"))
            }
        }
    }

    func react(_ m: MessageDto, _ emoji: String?) {
        // Instant feedback; the server's answer (and everyone else's reactions) follow.
        if let i = messages.firstIndex(where: { $0.id == m.id }) { messages[i].dto.reactions = localReact(messages[i].dto.reactions, emoji) }
        Task { if let dto = try? await ChatApi.react(m.id, emoji: emoji).data { replaceMessage(dto.id, UiMessage(dto: dto)) } }
    }

    private func localReact(_ r: ReactionsDto?, _ emoji: String?) -> ReactionsDto {
        var counts: [String: Int] = [:]
        for s in r?.summary ?? [] { counts[s.emoji] = s.count }
        if let old = r?.mine { counts[old] = (counts[old] ?? 1) - 1 }
        let new = emoji == r?.mine ? nil : emoji
        if let new { counts[new] = (counts[new] ?? 0) + 1 }
        let summary = counts.filter { $0.value > 0 }.map { ReactionSummaryDto(emoji: $0.key, count: $0.value) }.sorted { $0.count > $1.count }
        return ReactionsDto(summary: summary, mine: new, total: summary.reduce(0) { $0 + $1.count })
    }

    /// Star (into one of my favourites folders) or unstar.
    func star(_ m: MessageDto, folderId: Int? = nil) {
        let on = m.isStarred != true
        var copy = m
        copy.isStarred = on
        replaceMessage(m.id, UiMessage(dto: copy))
        Task { _ = try? await ChatApi.star(m.id, on, folderId: folderId) }
    }

    func readLater(_ m: MessageDto) {
        var copy = m
        copy.isReadLater = m.isReadLater != true
        replaceMessage(m.id, UiMessage(dto: copy))
        Task { _ = try? await ChatApi.readLater(m.id, copy.isReadLater == true) }
    }

    func followUp(_ m: MessageDto) {
        var copy = m
        copy.isFollowUp = m.isFollowUp != true
        replaceMessage(m.id, UiMessage(dto: copy))
        Task { _ = try? await ChatApi.followUp(m.id, copy.isFollowUp == true) }
    }

    /// A short note from these messages, saved into «Notes (you)» (spec 39).
    func aiNote(_ messageIds: [String]) {
        Task {
            do {
                _ = try await AiToolsApi.note(id, messages: messageIds)
                host.showToast(L("ct_note_saved"))
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
        }
    }

    /// Remind me about this message at `at` (nil: take the reminder off).
    func remind(_ m: MessageDto, at: Date?) {
        Task {
            do {
                var copy = m
                if let at {
                    let iso = isoWithOffset(at)
                    _ = try await ChatApi.setReminder(m.id, remindAt: iso)
                    copy.reminderAt = iso
                    host.showToast(L("ch_reminder_set", scheduleLabel(at)))
                } else {
                    _ = try await ChatApi.clearReminder(m.id)
                    copy.reminderAt = nil
                }
                replaceMessage(m.id, UiMessage(dto: copy))
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"))
            }
        }
    }

    /// «Save to my notes»: a copy in my note-to-self chat.
    func saveToNotes(_ m: MessageDto, done: String) {
        Task {
            do {
                guard let notes = try await ChatApi.openSelf().data else { return }
                _ = try await ChatApi.forward(messageIds: [m.id], conversationIds: [notes.id])
                host.showToast(done)
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"))
            }
        }
    }

    func pin(_ m: MessageDto, seconds: Int) {
        Task {
            do { pinned = try await ChatApi.pin(m.id, durationSeconds: seconds).data ?? [] } catch { if let msg = error.apiFailure.message { host.showToast(msg) } }
        }
    }

    func unpin(_ m: MessageDto) {
        Task { pinned = (try? await ChatApi.unpin(m.id).data) ?? pinned.filter { $0.id != m.id } }
    }

    func forward(_ m: MessageDto, to targets: [String], done: @escaping (Bool) -> Void) {
        Task {
            do {
                _ = try await ChatApi.forward(messageIds: [m.id], conversationIds: targets)
                done(true)
            } catch {
                if let msg = error.apiFailure.message { host.showToast(msg) }
                done(false)
            }
        }
    }

    // MARK: Polls, buttons, view once, money, live location

    /// Tap an option: ticks it (single choice replaces, multiple toggles), new totals at once, then the server's.
    func vote(_ m: UiMessage, option: Int) {
        guard let poll = m.dto.poll else { return }
        let mine = poll.myVotes ?? []
        let picked: [Int] = mine.contains(option) ? mine.filter { $0 != option } : poll.multiple == true ? mine + [option] : [option]
        let before = Set(mine)
        var optimistic = poll
        optimistic.options = (poll.options ?? []).map { o in
            var o = o
            let delta = (picked.contains(o.id) ? 1 : 0) - (before.contains(o.id) ? 1 : 0)
            o.votes = max((o.votes ?? 0) + delta, 0)
            return o
        }
        optimistic.myVotes = picked
        optimistic.voters = (poll.voters ?? 0) + (before.isEmpty && !picked.isEmpty ? 1 : 0) - (!before.isEmpty && picked.isEmpty ? 1 : 0)
        var copy = m
        copy.dto.poll = optimistic
        replaceMessage(m.id, copy)
        Task {
            do {
                if let fresh = try await ChatApi.vote(m.id, options: picked).data, var current = messages.first(where: { $0.id == m.id }) {
                    current.dto.poll = fresh.poll
                    replaceMessage(m.id, current)
                }
            } catch {
                replaceMessage(m.id, m)
                CrashReports.record(error)
                // Never silent: a vote that didn't count says why.
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
        }
    }

    /// Tap a button / pick from a list: my pick shows at once, and my answer lands in the chat as a reply.
    func choose(_ m: UiMessage, option: Int) {
        guard var ui = m.dto.interactive else { return }
        ui.myChoice = option
        var copy = m
        copy.dto.interactive = ui
        replaceMessage(m.id, copy)
        Task {
            do { if let reply = try await ChatApi.chooseOption(m.id, option: option).data { replaceMessage(reply.id, UiMessage(dto: reply)) } } catch {
                replaceMessage(m.id, m)
                CrashReports.record(error)
                host.showToast(error.apiFailure.message ?? L("ch_error_network"))
            }
        }
    }

    /// The files of a view-once message, this one time — nil when it can't be opened.
    func openViewOnce(_ m: UiMessage) async -> [AttachmentDto]? {
        do {
            let files = try await ChatApi.openViewOnce(m.id).data?.attachments
            var copy = m
            copy.dto.viewOnceOpened = true
            replaceMessage(m.id, copy)
            return files
        } catch {
            if error.apiFailure.httpStatus == 410 {
                var copy = m
                copy.dto.viewOnceOpened = true
                replaceMessage(m.id, copy)
            }
            if let msg = error.apiFailure.message { host.showToast(msg) }
            return nil
        }
    }

    /// A money request / split answered on the server: keep its new state.
    func paymentUpdated(_ fresh: MessageDto) {
        guard var current = messages.first(where: { $0.id == fresh.id }) else { return }
        current.dto.payment = fresh.payment
        current.dto.meta = fresh.meta
        replaceMessage(fresh.id, current)
    }

    func declineRequest(_ m: UiMessage) {
        Task { do { if let d = try await ChatApi.declineRequest(m.id).data { paymentUpdated(d) } } catch { if let msg = error.apiFailure.message { host.showToast(msg) } } }
    }

    func cancelRequest(_ m: UiMessage) {
        Task { do { if let d = try await ChatApi.cancelRequest(m.id).data { paymentUpdated(d) } } catch { if let msg = error.apiFailure.message { host.showToast(msg) } } }
    }

    func stopLive(_ m: UiMessage) {
        Task {
            if var fresh = try? await ChatApi.stopLive(m.id).data {
                fresh.isMine = true
                fresh.status = m.dto.status
                replaceMessage(m.id, UiMessage(dto: fresh))
            }
            LiveLocationSharing.shared.stop(m.id)
        }
    }

    /// Tell the others we're typing — at most every 3 s, and «stopped» after a pause.
    func sendTyping(stop: Bool = false, state: String = "typing") {
        guard let c = conversation, c.isRequest != true, c.isSelf != true else { return }
        typingTask?.cancel()
        if stop {
            if lastTypingSent != nil { host.sendTyping(id, "stopped") }
            lastTypingSent = nil
            return
        }
        if lastTypingSent.map({ Date().timeIntervalSince($0) > 3 }) ?? true {
            lastTypingSent = Date()
            host.sendTyping(id, state)
        }
        typingTask = Task {
            try? await Task.sleep(nanoseconds: 4_000_000_000)
            guard !Task.isCancelled else { return }
            host.sendTyping(id, "stopped")
            lastTypingSent = nil
        }
    }

    // MARK: Real-time

    private func onEvent(_ event: ChatEvent) {
        let data = event.data
        guard data.at("conversation_id")?.str == id else { return }
        switch event.name {
        case "chat.scheduled.changed":
            Task { await refreshScheduled() }
        case "chat.message.sent":
            guard var dto = data.at("message")?.decode(MessageDto.self) else { return }
            // A reply in a thread stays in the thread; its message counts one more.
            if let root = dto.threadRoot {
                if let i = messages.firstIndex(where: { $0.dto.id == root }) {
                    messages[i].dto.thread = ThreadInfoDto(count: (messages[i].dto.thread?.count ?? 0) + 1, lastAt: dto.createdAt)
                }
                return
            }
            let mine = dto.sender?.key == myKey()
            dto.isMine = mine
            dto.status = mine ? "sent" : nil
            if messages.contains(where: { $0.id == dto.id }) {
                // Our own optimistic bubble: keep the server's version (with its files).
                if mine { replaceMessage(dto.id, UiMessage(dto: dto)) }
            } else if hasMoreAfter {
                // Reading an older stretch: don't glue it on out of order.
                if !mine { unseenBelow += 1 }
            } else {
                withAnimation(.spring(response: 0.4, dampingFraction: 0.7)) { messages.append(UiMessage(dto: dto, fresh: true)) }
                persist()
                if !mine { if atBottom { markRead() } else { unseenBelow += 1 } }
            }
        case "chat.message.updated", "chat.message.deleted":
            guard var dto = data.at("message")?.decode(MessageDto.self), let old = messages.first(where: { $0.id == dto.id }) else { return }
            // A money card is drawn per viewer: read my own version of it.
            if dto.payment != nil && event.name == "chat.message.updated" {
                Task {
                    if let mine = try? await ChatApi.messages(id, around: dto.id, limit: 1).data?.messages?.first(where: { $0.id == dto.id }) { paymentUpdated(mine) }
                }
                return
            }
            dto.isMine = old.isMine
            dto.status = old.dto.status
            dto.isStarred = old.dto.isStarred
            dto.reactions = event.name == "chat.message.deleted" ? ReactionsDto() : old.dto.reactions
            replaceMessage(dto.id, UiMessage(dto: dto))
        case "chat.receipt":
            guard data.at("participant")?.str != myKey() else { return }
            applyReceipt(data.at("delivered_up_to")?.str, "delivered")
            applyReceipt(data.at("read_up_to")?.str, "read")
        case "chat.reaction":
            guard let mid = data.at("message_id")?.str, let i = messages.firstIndex(where: { $0.id == mid }) else { return }
            let summary = (data.at("summary")?.arr ?? []).compactMap { $0.decode(ReactionSummaryDto.self) }
            let mine = data.at("participant")?.str == myKey() ? data.at("emoji")?.str : messages[i].dto.reactions?.mine
            messages[i].dto.reactions = ReactionsDto(summary: summary, mine: mine, total: summary.reduce(0) { $0 + $1.count })
        case "chat.poll.updated":
            guard let i = messages.firstIndex(where: { $0.id == data.at("message_id")?.str }), var poll = messages[i].dto.poll else { return }
            let counts = data.at("counts")
            if data.at("participant")?.str == myKey() { poll.myVotes = data.at("option_ids")?.arr?.compactMap(\.int) ?? poll.myVotes }
            poll.options = (poll.options ?? []).map { o in var o = o; o.votes = counts?[String(o.id)]?.int ?? 0; return o }
            poll.voters = data.at("voters")?.int ?? poll.voters
            messages[i].dto.poll = poll
        case "chat.view_once.opened":
            guard let i = messages.firstIndex(where: { $0.id == data.at("message_id")?.str }) else { return }
            if messages[i].isMine || data.at("participant")?.str == myKey() { messages[i].dto.viewOnceOpened = true }
        case "chat.location.moved":
            guard let i = messages.firstIndex(where: { $0.id == data.at("message_id")?.str }) else { return }
            var meta = messages[i].dto.meta?.obj ?? [:]
            if let lat = data.at("latitude") { meta["latitude"] = lat }
            if let lng = data.at("longitude") { meta["longitude"] = lng }
            if let acc = data.at("accuracy"), acc.dbl != nil { meta["accuracy"] = acc }
            messages[i].dto.meta = .object(meta)
            messages[i].dto.liveLocation?.updatedAt = data.at("updated_at")?.str
        case "chat.pins.updated":
            Task { await refreshPinned() }
        case "chat.conversation.updated":
            Task { if let c = try? await ChatApi.conversation(id).data { conversation = c } }
        default: break
        }
    }

    /// Everything I sent up to `uuid` gets `status` (never downgrading read → delivered).
    private func applyReceipt(_ uuid: String?, _ status: String) {
        guard let uuid, let upTo = messages.firstIndex(where: { $0.id == uuid }) else { return }
        for i in 0...upTo {
            let m = messages[i]
            guard m.isMine, m.local == nil, m.dto.system == nil, m.dto.status != "read", m.dto.status != status else { continue }
            messages[i].dto.status = status
        }
    }

    /// Fetch one message again (a surprise card opening at its time) and swap it in place.
    func refreshOne(_ messageId: String) async {
        guard let fresh = try? await ChatApi.messages(id, around: messageId).data?.messages?.first(where: { $0.id == messageId }),
              let i = messages.firstIndex(where: { $0.id == messageId }) else { return }
        messages[i].dto = fresh
        messages[i].fresh = true
    }

    func replaceMessage(_ id: String, _ m: UiMessage) {
        var m = m
        m.fresh = false
        if let i = messages.firstIndex(where: { $0.id == id }) {
            // The server adds a link's card a few seconds after sending: until then keep the one this phone already
            // showed (same words — an edit that dropped the link drops the card).
            let old = messages[i].dto
            if m.dto.linkPreview == nil, old.linkPreview != nil, old.body == m.dto.body { m.dto.linkPreview = old.linkPreview }
            messages[i] = m
        } else {
            messages.append(m)
        }
        persist()
    }
}

/// My own profile as the chat shows it (the optimistic bubble's sender).
@MainActor func profileOf(_ me: UserDto?) -> ProfileDto? {
    let json = "{\"type\":\"user\",\"id\":\(me?.id ?? 0),\"key\":\"\(myKey())\",\"is_me\":true}"
    guard var p = try? JSONDecoder().decode(ProfileDto.self, from: Data(json.utf8)) else { return nil }
    p.name = me?.name
    p.avatar = me?.avatar
    return p
}

/// ISO-8601 with the phone's offset ("2026-10-31T14:05:00+03:00").
func isoWithOffset(_ d: Date) -> String {
    let f = ISO8601DateFormatter()
    f.timeZone = .current
    f.formatOptions = [.withInternetDateTime]
    return f.string(from: d)
}

/// The phone's medium date + short time in the app's language (androidApp `scheduleLabel`).
func scheduleLabel(_ d: Date) -> String {
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current)
    f.dateStyle = .medium
    f.timeStyle = .short
    return f.string(from: d)
}

func scheduleLabel(_ iso: String) -> String { parseRideTime(iso).map { scheduleLabel($0) } ?? iso }

func typeForMime(_ mime: String) -> String {
    if mime.hasPrefix("image/") { return "image" }
    if mime.hasPrefix("video/") { return "video" }
    if mime.hasPrefix("audio/") { return "audio" }
    return "document"
}
