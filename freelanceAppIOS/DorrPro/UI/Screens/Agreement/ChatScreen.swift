// freelanceApp `ui/screens/ChatScreen.kt`
import SwiftUI

private let red = Color(hex: 0xDC2626)

/// The talk with a client about one of my offers (FL-308). The client opens the room; here I read and answer. Until the agreement the
/// server refuses phone numbers, emails and links, and says so. The page reads once when it opens, once when a notice arrives and
/// once when the connection comes back — never on a timer.
struct ChatScreen: View {
    let conversationUuid: String
    let onBack: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var room: ChatRoomDto?
    /// Newest first, as the server gives them (drawn oldest at the top, newest at the bottom).
    @State private var messages: [ChatMessageDto] = []
    @State private var hasMore = false
    @State private var loadingOlder = false
    @State private var olderFailed = false
    @State private var input = ""
    @State private var sending = false
    @State private var error: String?
    @State private var loaded = false
    @State private var interviews: [InterviewDto] = []
    // FL-709: the message being reported (from the menu beside the other person's).
    @State private var reporting: Int?
    @State private var reportNotice: String?

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(alignment: .leading, spacing: 0) {
                SubHeader(title: room?.peer?.name ?? L("chat_title"), onBack: onBack)
                if let title = room?.project?.title {
                    Text(verbatim: title).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.horizontal, 18)
                }
                if room?.guarded == true {
                    Text(L("chat_guard_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut).padding(.horizontal, 18).padding(.vertical, 4)
                }
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
                    Text(verbatim: error).font(.dorr(12)).foregroundStyle(red).padding(.horizontal, 18).padding(.vertical, 2)
                }
                if room != nil {
                    InterviewPanel(roomUuid: conversationUuid, canWrite: room?.canWrite == true, items: interviews,
                                   onChanged: { Task { await load() } }, onError: { error = $0 })
                }
                if room?.canWrite == true {
                    composer
                } else if room != nil {
                    Text(L("chat_closed")).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading).padding(16)
                }
            }
        }
        .navigationBarHidden(true)
        .messageToast(reportNotice)
        // The page is a chat: it takes the whole screen (no tab bar over the keyboard).
        .onAppear { ShellState.shared.immersive = true }
        .onDisappear { ShellState.shared.immersive = false }
        .task(id: "\(conversationUuid)|\(network.reconnectTick)") { await load() }
        .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
        .dorrDialog(isPresented: agreementPresence($reporting)) {
            if let id = reporting {
                ReportMessageDialog(messageId: id, onDone: { reportNotice = $0; reporting = nil }, onDismiss: { reporting = nil })
            }
        }
    }

    private var list: some View {
        ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(spacing: 6) {
                    if loaded && messages.isEmpty {
                        Text(L("chat_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(24)
                    }
                    if hasMore {
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
                        Bubble(m: m) { reporting = m.id }.id(m.id)
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
            DorrTextField(value: Binding(get: { input }, set: { if $0.count <= 2000 { input = $0 } }), placeholder: L("chat_hint"), singleLine: false)
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
            .accessibilityLabel(L("chat_send"))
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
    }

    private func load() async {
        if let r = await ChatApi.room(conversationUuid).value { room = r }
        if let list = await ChatApi.interviews(conversationUuid).value { interviews = list }
        let result = await ChatApi.messages(conversationUuid)
        if let data = result.value {
            // The newest page replaces what is shown; the older messages I scrolled up to stay under it.
            let older = data.last.map { oldest in messages.filter { $0.id < oldest.id } } ?? []
            messages = data + older
            if older.isEmpty { hasMore = data.count >= 40 }
            error = nil
            if data.contains(where: { $0.mine == false && $0.readAt == nil }) { _ = await ChatApi.read(conversationUuid) }
        } else if !loaded {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
        loaded = true
    }

    private func loadOlder() async {
        guard loaded, !loadingOlder, hasMore, !olderFailed, let before = messages.last?.id else { return }
        loadingOlder = true
        olderFailed = false
        let more = await ChatApi.messages(conversationUuid, before: before)
        if let data = more.value {
            messages += data.filter { n in !messages.contains { $0.id == n.id } }
            hasMore = data.count >= 40
        } else {
            olderFailed = true
        }
        loadingOlder = false
    }

    private func send() {
        let text = input.agreementTrimmed
        guard !text.isEmpty, !sending else { return }
        sending = true
        error = nil
        Task {
            let result = await ChatApi.send(conversationUuid, ChatSendRequest(body: text, clientId: UUID().uuidString.lowercased()))
            if let m = result.value {
                if !messages.contains(where: { $0.id == m.id }) { messages.insert(m, at: 0) }
                input = ""
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
            sending = false
        }
    }
}

private struct Bubble: View {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    let m: ChatMessageDto
    let onReport: () -> Void

    var body: some View {
        if m.kind == "system" {
            SystemLine(text: m.body ?? "")
        } else {
            let mine = m.mine == true
            HStack(alignment: .center, spacing: 0) {
                if mine { Spacer(minLength: 0) }
                VStack(alignment: .leading, spacing: 2) {
                    let text = m.kind == "deleted" ? L("chat_deleted") : (m.kind ?? "text") != "text" ? L("chat_file") : (m.body ?? "")
                    Text(verbatim: text).font(.dorr(14)).foregroundStyle(mine ? .white : wa.settingsInk).lineSpacing(4)
                    if let t = timeOf(m.createdAt) {
                        Text(verbatim: "\u{200E}" + t).font(.dorr(10)).foregroundStyle(mine ? Color.white.opacity(0.8) : wa.settingsMut)
                    }
                }
                .padding(.horizontal, 12)
                .padding(.vertical, 8)
                .background {
                    if mine { RoundedRectangle(cornerRadius: 16, style: .continuous).fill(wa.red) } else { Color.clear.settingsSurface(16) }
                }
                .frame(maxWidth: 280, alignment: mine ? .trailing : .leading)
                .environment(\.layoutDirection, direction)
                // FL-709: the three dots beside the other person's message open a small menu (report).
                if !mine && m.kind != "deleted" {
                    Menu {
                        Button(L("rm_title"), action: onReport)
                    } label: {
                        Image(systemName: "ellipsis").rotationEffect(.degrees(90)).font(.system(size: 14, weight: .semibold))
                            .foregroundStyle(wa.settingsMut).frame(width: 32, height: 32).contentShape(Circle())
                    }
                }
                if !mine { Spacer(minLength: 0) }
            }
            // Arrangement.Absolute: mine on the right, theirs on the left, in Arabic too.
            .environment(\.layoutDirection, .leftToRight)
        }
    }

    private func timeOf(_ iso: String?) -> String? {
        guard let date = parseInstant(iso) else { return nil }
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = .current
        f.dateFormat = "HH:mm"
        return f.string(from: date)
    }
}
