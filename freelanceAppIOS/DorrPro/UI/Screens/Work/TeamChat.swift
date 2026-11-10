import SwiftUI

// freelanceApp `ui/screens/TeamChat.kt`.

private let tcAmber = Color(hex: 0xD97706)
private let tcRed = Color(hex: 0xDC2626)

/// A message may be taken back by its sender only for a few minutes (the server decides; this only hides the entry afterwards).
private let takeBackMinutes: TimeInterval = 5

/// FS-209c: the «Chat» part of a team's page — the team's inside talk (never seen by a client): the general thread first, then one thread
/// per project the team works on. A thread opens from here; an Owner may open the thread of a project that has none yet. The numbers come
/// from the page that holds this part (it reads again when a notice arrives and when the connection returns).
struct TeamChatSection: View {
    @Environment(\.wa) private var wa
    let teamUuid: String
    let data: TeamThreadsDto?
    let failure: String?
    let onOpenThread: (String) -> Void
    let onReload: () async -> Void

    @State private var picking = false
    /// Kept here (not in the sheet) so the sheet stays open while a thread is being opened.
    @State private var pickerBusy = false

    var body: some View {
        VStack(spacing: 12) { content }
            .frame(maxWidth: .infinity)
            .cmSheet(isPresented: $picking, locked: pickerBusy) { pickerSheet }
    }

    @ViewBuilder
    private var content: some View {
        if let list = data {
            let threads = list.threads ?? []
            TeamChatNotice(notice: list.notice)
            if list.canWrite != true {
                Text(L("tc_read_only")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            if list.canOpenProject == true {
                SettingsPrimaryButton(text: L("tc_open_project")) { picking = true }
            }
            if threads.isEmpty {
                EmptyState(icon: "bubble.left.and.bubble.right.fill", title: L("tc_empty_title"), body: L("tc_empty_body")).padding(.top, 20)
            }
            ForEach(threads) { t in
                ThreadRow(t: t) { onOpenThread(t.uuid ?? "") }
            }
        } else if let failure {
            InlineErrorBanner(message: failure)
        } else {
            SkeletonList(count: 3)
        }
    }

    private var pickerSheet: some View {
        ProjectPickerSheet(
            teamUuid: teamUuid,
            taken: Set((data?.threads ?? []).compactMap { $0.project?.uuid }),
            busy: $pickerBusy,
            onOpened: { thread in
                picking = false
                onOpenThread(thread)
            },
            onReload: onReload
        )
    }
}

/// What the team is told about its chat (for example that the staff may read a thread while a dispute is decided).
private struct TeamChatNotice: View {
    @Environment(\.wa) private var wa
    let notice: String?

    var body: some View {
        let text = (notice ?? "").agreementTrimmed.isEmpty ? L("tc_private_hint") : (notice ?? "")
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: "info.circle.fill").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red).frame(width: 18, height: 18)
            Text(verbatim: text).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .appCard(Corners.card)
    }
}

private struct ThreadRow: View {
    @Environment(\.wa) private var wa
    let t: TeamThreadDto
    let onClick: () -> Void

    var body: some View {
        let tint = wa.red
        let unread = t.unread ?? 0
        let general = t.general == true
        let preview = (t.lastMessagePreview ?? "").agreementTrimmed
        Button(action: onClick) {
            HStack(spacing: 12) {
                Image(systemName: general ? "person.3.fill" : "hammer.fill").font(.system(size: 17, weight: .semibold)).foregroundStyle(tint)
                    .frame(width: 42, height: 42)
                    .background(tint.opacity(0.12), in: RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
                VStack(alignment: .leading, spacing: 3) {
                    HStack(spacing: 6) {
                        Text(verbatim: general ? L("tc_general") : (t.project?.title ?? "")).font(.dorr(14.5, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        if t.underReview == true { StatusPill(text: L("tc_under_review"), tint: tcAmber) }
                    }
                    Text(verbatim: preview.isEmpty ? L("tc_no_messages") : preview)
                        .font(.dorr(12.5, unread > 0 ? .semibold : .regular))
                        .foregroundStyle(unread > 0 ? wa.settingsInk : wa.settingsMut)
                        .lineLimit(1)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                VStack(alignment: .trailing, spacing: 6) {
                    if let when = tcWhen(t.lastMessageAt) { Text(verbatim: when).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
                    if unread > 0 {
                        Text(verbatim: unread > 99 ? "99+" : String(unread)).font(.dorr(11, .black)).foregroundStyle(.white)
                            .padding(.horizontal, 7)
                            .padding(.vertical, 2)
                            .frame(minHeight: 16)
                            .background(tint, in: Capsule())
                            .environment(\.layoutDirection, .leftToRight)
                    } else {
                        Image(systemName: "chevron.forward").font(.system(size: 12, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 18, height: 18)
                    }
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .appCard(Corners.card)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// An Owner opens the thread of a project the team works on (those that have none yet are listed). Shown with `.cmSheet` (Android
/// `LocaleAwareBottomSheet`), which closes itself — `onOpened` is where the page moves on.
private struct ProjectPickerSheet: View {
    @Environment(\.wa) private var wa
    let teamUuid: String
    let taken: Set<String>
    @Binding var busy: Bool
    let onOpened: (String) -> Void
    let onReload: () async -> Void

    @State private var projects: [TeamProjectDto]?
    @State private var error: String?

    private func open(_ project: String) {
        busy = true
        error = nil
        Task {
            let result = await TeamsApi.openChatThread(teamUuid, TeamThreadRequest(project: project))
            busy = false
            if let thread = result.value?.thread?.uuid {
                await onReload()
                onOpened(thread)
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    var body: some View {
        let free = (projects ?? []).filter { p in
            if let uuid = p.project?.uuid { return !taken.contains(uuid) }
            return false
        }
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(L("tc_open_project_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                Text(L("tc_open_project_hint")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                if projects == nil && error == nil {
                    SkeletonList(count: 2)
                } else if projects != nil && free.isEmpty {
                    Text(L("tc_open_project_none")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                } else {
                    ForEach(free) { p in
                        Button { if let uuid = p.project?.uuid { open(uuid) } } label: {
                            HStack(spacing: 10) {
                                Image(systemName: "hammer.fill").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red).frame(width: 20, height: 20)
                                Text(verbatim: p.project?.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2)
                                    .multilineTextAlignment(.leading).frame(maxWidth: .infinity, alignment: .leading)
                            }
                            .padding(14)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .appCard(Corners.card)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .disabled(busy)
                    }
                }
                if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(tcRed).frame(maxWidth: .infinity, alignment: .leading) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 18)
            .padding(.top, 24)
            .padding(.bottom, 24)
        }
        .background(wa.settingsCard.ignoresSafeArea())
        .task(id: teamUuid) {
            let result = await TeamsApi.projects(teamUuid)
            if let list = result.value {
                projects = list
            } else if !Task.isCancelled {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}

// MARK: - one thread

/// One of the team's threads (the general one or a project's): the messages newest first from the bottom, older pages when I scroll up, the
/// composer for those who may write. It reads once when it opens, once when a notice arrives and once when the connection returns; while
/// it is open it also listens to the team's channel, which brings each new message (or a taken-back one) as it happens — nothing on a timer.
struct TeamThreadScreen: View {
    let teamUuid: String
    let threadUuid: String
    let onBack: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var thread: TeamThreadDto?
    @State private var notice: String?
    /// Newest first, as the server gives them (drawn oldest at the top, newest at the bottom).
    @State private var messages: [TeamMessageDto] = []
    @State private var nextBefore: Int?
    @State private var loadingOlder = false
    @State private var olderFailed = false
    @State private var input = ""
    @State private var sending = false
    @State private var error: String?
    @State private var loaded = false
    /// What I sent from this screen: a live copy of my own message must not be shown as somebody else's.
    @State private var sentIds: Set<String> = []
    @State private var listening = false

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(alignment: .leading, spacing: 0) {
                SubHeader(title: headerTitle, onBack: onBack)
                Text(verbatim: (notice ?? "").agreementTrimmed.isEmpty ? L("tc_private_hint") : (notice ?? ""))
                    .font(.dorr(11)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                    .padding(.horizontal, 18).padding(.vertical, 2)
                Group {
                    if !loaded && error == nil {
                        SkeletonList(count: 3).padding(14)
                        Spacer(minLength: 0)
                    } else {
                        list
                    }
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
                if let error {
                    Text(verbatim: error).font(.dorr(12)).foregroundStyle(tcRed).padding(.horizontal, 18).padding(.vertical, 2)
                }
                if thread?.canWrite == true {
                    composer
                } else if thread != nil {
                    Text(L("tc_closed")).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading).padding(16)
                }
            }
        }
        .navigationBarHidden(true)
        // The page is a chat: it takes the whole screen (no tab bar over the keyboard), and it listens to the team's channel while it is open.
        .onAppear {
            ShellState.shared.immersive = true
            if !listening {
                listening = true
                Realtime.shared.listenTeam(teamUuid)
            }
        }
        .onDisappear {
            ShellState.shared.immersive = false
            if listening {
                listening = false
                Realtime.shared.stopListeningTeam(teamUuid)
            }
        }
        .task(id: "\(teamUuid)|\(threadUuid)|\(network.reconnectTick)") { await load() }
        .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
        .onReceive(Realtime.shared.teamEvents) { handleLive($0) }
    }

    private var headerTitle: String {
        guard let t = thread else { return L("tc_title") }
        if t.general == true { return L("tc_general") }
        return t.project?.title ?? L("tc_title")
    }

    private var list: some View {
        ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(spacing: 6) {
                    if loaded && messages.isEmpty {
                        Text(L("tc_empty_thread")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(24)
                    }
                    if nextBefore != nil {
                        // Older messages come by themselves when I scroll up to the oldest one loaded (nothing on a timer).
                        if olderFailed {
                            Text(L("common_retry")).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .padding(12)
                                .contentShape(Rectangle())
                                .onTapGesture { olderFailed = false; Task { await loadOlder() } }
                        } else {
                            ProgressView().tint(wa.red).frame(maxWidth: .infinity).padding(12)
                                .onAppear { Task { await loadOlder() } }
                        }
                    }
                    ForEach(messages.reversed()) { m in
                        TeamBubble(m: m) { takeBack(m) }.id(m.id)
                    }
                }
                .padding(.horizontal, 14)
                .padding(.vertical, 8)
            }
            .scrollDismissesKeyboard(.interactively)
            // Drawn from the bottom: the newest message stays in view when one arrives.
            .onChange(of: messages.first?.id) { newest in
                if let newest { withAnimation { proxy.scrollTo(newest, anchor: .bottom) } }
            }
            .onAppear { if let newest = messages.first?.id { proxy.scrollTo(newest, anchor: .bottom) } }
        }
    }

    private var composer: some View {
        HStack(spacing: 8) {
            DorrTextField(value: Binding(get: { input }, set: { if $0.count <= 2000 { input = $0 } }), placeholder: L("tc_hint"), singleLine: false)
                .frame(maxWidth: .infinity)
            let ready = !input.agreementTrimmed.isEmpty && !sending
            Button(action: send) {
                Image(systemName: "paperplane.fill").font(.system(size: 17, weight: .semibold)).foregroundStyle(.white)
                    .flipsForRightToLeftLayoutDirection(true)
                    .frame(width: 44, height: 44)
                    .background(wa.red.opacity(ready ? 1 : 0.4), in: Circle())
            }
            .buttonStyle(.plain)
            .disabled(!ready)
            .accessibilityLabel(L("tc_send"))
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
    }

    // MARK: reading

    private func load() async {
        let result = await TeamsApi.chatMessages(teamUuid, threadUuid)
        if let page = result.value {
            if let t = page.thread { thread = t }
            notice = page.notice
            // The newest page replaces what is shown; the older messages I scrolled up to stay under it.
            let fresh = page.messages ?? []
            let older = fresh.last.map { oldest in messages.filter { $0.id < oldest.id } } ?? []
            messages = fresh + older
            if older.isEmpty { nextBefore = page.nextBefore }
            error = nil
            if (page.thread?.unread ?? 0) > 0 { _ = await TeamsApi.readChat(teamUuid, threadUuid) }
        } else if !loaded {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
        loaded = true
    }

    private func loadOlder() async {
        guard loaded, !loadingOlder, !olderFailed, let before = nextBefore else { return }
        loadingOlder = true
        olderFailed = false
        let more = await TeamsApi.chatMessages(teamUuid, threadUuid, before: before)
        if let page = more.value {
            messages += (page.messages ?? []).filter { n in !messages.contains { $0.id == n.id } }
            nextBefore = page.nextBefore
        } else {
            olderFailed = true
        }
        loadingOlder = false
    }

    // MARK: live

    private func myFreelancerId() -> Int? { messages.first { $0.mine == true }?.from?.id }

    /// A message that came live has no `mine` (it was made for everybody): it is mine when I sent it, or when it carries my id.
    private func withMine(_ m: TeamMessageDto) -> TeamMessageDto {
        guard m.mine == nil else { return m }
        var out = m
        let id = myFreelancerId()
        let sent = m.clientMsgId.map { sentIds.contains($0) } ?? false
        out.mine = sent || (id != nil && m.from?.id == id)
        return out
    }

    private func put(_ m: TeamMessageDto) {
        if let at = messages.firstIndex(where: { $0.id == m.id }) { messages[at] = m } else { messages.insert(m, at: 0) }
    }

    /// The team's channel: a new message arrives with its body, a taken-back one with its marker (the thread's reads are not shown).
    private func handleLive(_ event: LiveEvent) {
        guard event.data["thread"]?.str == threadUuid else { return }
        switch event.data["action"]?.str {
        case "message":
            guard let live = TeamMessageDto(live: event.data["message"]) else { return }
            let m = withMine(live)
            put(m)
            if m.mine != true { Task { _ = await TeamsApi.readChat(teamUuid, threadUuid) } }
        case "deleted":
            guard let gone = TeamMessageDto(live: event.data["message"]), let at = messages.firstIndex(where: { $0.id == gone.id }) else { return }
            messages[at].kind = "deleted"
            messages[at].body = nil
        default:
            break
        }
    }

    // MARK: writing

    private func send() {
        let text = input.agreementTrimmed
        guard !text.isEmpty, !sending else { return }
        sending = true
        error = nil
        let clientId = UUID().uuidString.lowercased()
        sentIds.insert(clientId)
        Task {
            let result = await TeamsApi.sendChat(teamUuid, threadUuid, TeamMessageRequest(body: text, clientMsgId: clientId))
            if let m = result.value?.message {
                put(m)
                input = ""
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
            sending = false
        }
    }

    private func takeBack(_ m: TeamMessageDto) {
        Task {
            let problem = await TeamsApi.deleteChatMessage(teamUuid, m.id, offline: L("login_error_offline"), generic: L("login_error_generic"))
            if let problem {
                AppToast.show(problem)
            } else if let at = messages.firstIndex(where: { $0.id == m.id }) {
                messages[at].kind = "deleted"
                messages[at].body = nil
            }
        }
    }
}

/// One message: the sender's name over somebody else's, the time under it; mine can be taken back for a few minutes (long press — iOS shows
/// it as a context menu).
private struct TeamBubble: View {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    let m: TeamMessageDto
    let onTakeBack: () -> Void

    var body: some View {
        let mine = m.mine == true
        let deleted = m.kind == "deleted"
        let canTakeBack = mine && !deleted && withinTakeBack(m.createdAt)
        let name = (m.from?.name ?? "").agreementTrimmed
        HStack(alignment: .center, spacing: 0) {
            if mine { Spacer(minLength: 0) }
            VStack(alignment: .leading, spacing: 2) {
                if !mine && !name.isEmpty {
                    Text(verbatim: name).font(.dorr(11.5, .heavy)).foregroundStyle(wa.red).lineLimit(1)
                }
                if deleted {
                    Text(L("tc_deleted")).font(.dorr(13)).italic().foregroundStyle(mine ? Color.white.opacity(0.85) : wa.settingsMut)
                } else {
                    Text(verbatim: m.body ?? "").font(.dorr(14)).foregroundStyle(mine ? .white : wa.settingsInk).lineSpacing(4)
                }
                if let t = clockText(m.createdAt) {
                    Text(verbatim: "\u{200E}" + t).font(.dorr(10)).foregroundStyle(mine ? Color.white.opacity(0.8) : wa.settingsMut)
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background {
                if mine { RoundedRectangle(cornerRadius: 16, style: .continuous).fill(wa.red) } else { Color.clear.settingsSurface(16) }
            }
            .frame(maxWidth: 290, alignment: mine ? .trailing : .leading)
            .environment(\.layoutDirection, direction)
            .contextMenu {
                if canTakeBack {
                    Button(role: .destructive, action: onTakeBack) { Label(L("tc_take_back"), systemImage: "trash") }
                }
            }
            if !mine { Spacer(minLength: 0) }
        }
        // Arrangement.Absolute: mine on the right, theirs on the left, in Arabic too.
        .environment(\.layoutDirection, .leftToRight)
    }
}

private func withinTakeBack(_ iso: String?) -> Bool {
    guard let date = parseInstant(iso) else { return false }
    return Date().timeIntervalSince(date) < takeBackMinutes * 60
}

private func clockText(_ iso: String?) -> String? {
    guard let date = parseInstant(iso) else { return nil }
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.timeZone = .current
    f.dateFormat = "HH:mm"
    return f.string(from: date)
}

/// The time of today's messages, the date of the older ones.
private func tcWhen(_ iso: String?) -> String? {
    guard let date = parseInstant(iso) else { return nil }
    let f = DateFormatter()
    f.timeZone = .current
    if Calendar.current.isDateInToday(date) {
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "HH:mm"
    } else {
        f.locale = AppLocale.locale
        f.dateFormat = "d MMM"
    }
    return f.string(from: date)
}
