import AVFoundation
import AVKit
import SwiftUI
import UIKit

// androidApp `ui/screens/chat/ConversationPage.kt` — one open conversation: the header (avatar, name, typing /
// online / last seen, calls, more), search inside the chat, the pinned banner, the messages anchored to the bottom with
// day chips and runs, the ↓ button with what arrived while scrolled up, and at the bottom the composer — or the request
// / blocked / not-a-member bar. Long-press lifts a message into the actions overlay; photos open full screen.

/// A row in the conversation list: a date chip or a message with its place in a run.
private enum Line: Identifiable {
    case day(Date)
    case msg(UiMessage, first: Bool, last: Bool)

    var id: String {
        switch self {
        case .day(let d): return "day-\(d.timeIntervalSince1970)"
        case .msg(let m, _, _): return m.id
        }
    }
}

/// Message kinds to search for (spec 21).
private let searchKinds: [(String, String)] = [
    ("image", "cp_k_photos"), ("video", "cp_k_videos"), ("voice", "cp_k_voice"), ("document", "cp_k_files"),
    ("link", "cp_k_links"), ("location", "cp_k_places"), ("poll", "cp_k_polls"), ("money", "cp_k_money"),
]

struct ConversationPage: View {
    @ObservedObject var host: ChatHost
    @StateObject private var state: ConversationState
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var ai = ChatAi.shared

    @State private var viewer: (MessageDto, Int)?
    @State private var highlight: String?
    @State private var infoFor: MessageDto?
    @State private var votesFor: MessageDto?
    /// «View live location»: the live location message whose map is open.
    @State private var liveFor: String?
    @State private var viewOnceFiles: [AttachmentDto]?
    @State private var atBottom = true
    /// The message box's height: it floats over the list, which leaves that much room at its end.
    @State private var bottomHeight: CGFloat = 0
    @State private var lockPassed = false

    @State private var searching = false
    @State private var searchQuery = ""
    @State private var searchResults: [MessageDto]?
    /// By meaning (DORR AI search, on the AI plan): «الكورة» also finds «الماتش».
    @State private var byMeaning = false
    @State private var meaningTerms: [String] = []
    @State private var kinds = Set<String>()
    @State private var scroller: ScrollViewProxy?
    @State private var aiMenu = false
    /// A money request / my share of a bill, being paid with the wallet PIN.
    @State private var payFor: MessageDto?
    /// The summary sheet, starting on the unread messages (true) or the latest (false).
    @State private var summaryOpen: Bool?
    @State private var summaryOffered = false
    @State private var commitmentsOpen = false
    @State private var capsuleOpen = false
    @State private var memoryOpen = false
    /// assistant · important · dates · files
    @State private var aiPanel: String?

    init(host: ChatHost, id: String) {
        self.host = host
        _state = StateObject(wrappedValue: ConversationState(id: id, host: host))
    }

    var body: some View {
        let c = state.conversation
        Group {
            if let c, c.isLocked == true, c.hasLockPin == true, !ChatPinLock.unlocked.contains(state.id), !lockPassed {
                // This chat's own PIN (spec 25), not the phone's lock.
                PinGate(conversationId: state.id, title: c.title ?? "") { lockPassed = true }
            } else if c?.isLocked == true && c?.hasLockPin != true && !ChatLock.unlocked && !lockPassed {
                // A locked chat stays behind the phone's lock (Face ID / passcode) until it's passed.
                LockedGate { lockPassed = true }
            } else {
                page(c)
            }
        }
        .onAppear {
            // The notification for this chat stays silent while it's on screen.
            ChatPush.shared.openConversationId = state.id
        }
        .onDisappear {
            if ChatPush.shared.openConversationId == state.id { ChatPush.shared.openConversationId = nil }
            // Leaving with a message still on its way: the background outbox finishes it.
            state.close()
            Ch.shared.bubbleTheme = nil
            VoicePlayer.shared.stop()
        }
        // The chat's theme (admin-made, picked in chat info) colours the bubbles — Ch reads it — while open.
        .onChange(of: c?.theme?.applied) { Ch.shared.bubbleTheme = $0 }
        .task { Ch.shared.bubbleTheme = c?.theme?.applied }
    }

    private func page(_ c: ConversationDto?) -> some View {
        ZStack {
            ThemedWallpaper(theme: c?.theme?.applied).ignoresSafeArea()
            VStack(spacing: 0) {
                Group {
                    if searching {
                        ConversationSearchBar(query: $searchQuery) { closeSearch() }
                            .transition(.move(edge: .top).combined(with: .opacity))
                    } else {
                        ConversationHeader(host: host, state: state, onSearch: { withAnimation { searching = true } }, onAi: { aiMenu = true })
                            .transition(.move(edge: .top).combined(with: .opacity))
                    }
                }
                .animation(.easeInOut(duration: 0.22), value: searching)
                // Above what follows: the pinned bar tucks up under its rounded bottom.
                .zIndex(1)
                if searching {
                    SearchKindChips(selected: kinds) { k in if kinds.contains(k) { kinds.remove(k) } else { kinds.insert(k) } }
                    // Search by what the words mean (DORR AI), not only the words themselves.
                    if ai.searchMeaning {
                        HStack {
                            Button { byMeaning.toggle() } label: {
                                HStack(spacing: 6) {
                                    Image(systemName: "sparkles").font(.system(size: 13))
                                    Text(L("ch_ai_meaning_toggle")).font(.dorr(12.5, .semibold))
                                }
                                .foregroundStyle(byMeaning ? .white : ch.red)
                                .padding(.horizontal, 12).padding(.vertical, 6)
                                .background(byMeaning ? ch.red : ch.red.opacity(0.10), in: Capsule())
                            }
                            .buttonStyle(.plain)
                            Spacer()
                        }
                        .padding(.horizontal, 16).padding(.vertical, 2)
                        if byMeaning && !meaningTerms.isEmpty {
                            Text(L("ch_ai_meaning_terms", meaningTerms.joined(separator: " · "))).font(.dorr(12, .medium)).foregroundStyle(ch.mut)
                                .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 16).padding(.vertical, 4)
                        }
                    }
                }
                PinnedBanner(state: state) { jumpTo($0) }
                SummarizePill(visible: summaryOffered && ai.summarize) {
                    summaryOffered = false
                    summaryOpen = true
                }
                .animation(.spring(response: 0.4, dampingFraction: 0.8), value: summaryOffered)
                // A group's voice room with people in it (spec 91).
                if c?.isGroup == true && ChatFeatures.shared.allows("voice_rooms") { VoiceRoomBar(host: host, conversationId: state.id, title: c?.title) }
                // Someone I haven't saved: «Add» (then they see my stories too).
                if let c { NotInContactsBanner(host: host, conversation: c) { fresh in state.conversation = fresh; host.upsert(fresh) }.id(c.id) }
                // The message box floats over the end of the list (as in WhatsApp): the messages and the wallpaper show
                // beside it and scroll under it; the list keeps its height free at the end.
                ZStack(alignment: .bottomTrailing) {
                    messagesArea
                    BottomArea(host: host, state: state)
                        .frame(maxWidth: .infinity)
                        .background(GeometryReader { g in
                            Color.clear
                                .onAppear { bottomHeight = g.size.height }
                                .onChange(of: g.size.height) { bottomHeight = $0 }
                        })
                    if searching, let results = searchResults {
                        SearchResults(query: searchQuery, results: results) { id in
                            closeSearch()
                            jumpTo(id)
                        }
                        .transition(.opacity)
                    }
                    if !atBottom { downButton.transition(.scale.combined(with: .opacity)) }
                }
                .frame(maxHeight: .infinity)
                .animation(.spring(response: 0.35, dampingFraction: 0.6), value: atBottom)
            }
            .blur(radius: state.focused != nil ? 14 : 0)
            .animation(.easeOut(duration: 0.26), value: state.focused != nil)

            if state.focused != nil {
                MessageFocusOverlay(host: host, state: state) { infoFor = $0 }
                    .transition(.opacity)
            }
            if let (m, i) = viewer {
                MediaViewer(message: m, start: i) { withAnimation { viewer = nil } }
                    .transition(.opacity.combined(with: .scale(scale: 0.92)))
                    .zIndex(3)
            }
            if let files = viewOnceFiles {
                ViewOnceViewer(files: files) { withAnimation { viewOnceFiles = nil } }.zIndex(4)
            }
        }
        .background(ch.bg.ignoresSafeArea())
        .dorrDialog(isPresented: Binding(get: { infoFor != nil }, set: { if !$0 { infoFor = nil } }), bottom: true) {
            if let m = infoFor { MessageInfoSheet(message: m) { infoFor = nil } }
        }
        .fullScreenCover(isPresented: Binding(get: { liveFor != nil }, set: { if !$0 { liveFor = nil } })) {
            if let id = liveFor { LiveLocationPage(state: state, messageId: id) { liveFor = nil } }
        }
        .dorrDialog(isPresented: Binding(get: { votesFor != nil }, set: { if !$0 { votesFor = nil } }), bottom: true) {
            if let m = votesFor { PollVotesSheet(message: m) { votesFor = nil } }
        }
        .task(id: "\(searchQuery)|\(kinds.sorted())|\(byMeaning)") { await search() }
        .dorrDialog(isPresented: Binding(get: { payFor != nil }, set: { if !$0 { payFor = nil } }), bottom: true) {
            if let m = payFor { ChatPaySheet(host: host, message: m, onDismiss: { payFor = nil }) { state.paymentUpdated($0) } }
        }
        .fullScreenCover(isPresented: Binding(get: { state.gifSource != nil }, set: { if !$0 { state.gifSource = nil } })) {
            if let src = state.gifSource {
                GifMakerView(host: host, source: src, onDismiss: { state.gifSource = nil }) { clip, length, poster in
                    state.gifSource = nil
                    state.send(Outgoing(type: "video", files: [clip], extra: ["gif": .from(1), "duration_ms": .from(Int(length))], thumbnail: poster))
                }
            }
        }
        // Coming back to many unread messages: offer a summary of what's new.
        .task(id: state.id) { summaryOffered = (host.find(state.id)?.unreadCount ?? 0) >= 15 }
        .modifier(ConversationAiSheets(
            host: host, state: state, aiMenu: $aiMenu, summaryOpen: $summaryOpen, commitmentsOpen: $commitmentsOpen,
            capsuleOpen: $capsuleOpen, memoryOpen: $memoryOpen, aiPanel: $aiPanel, onJump: { jumpTo($0) }
        ))
        // Opened from «show in chat» (media page, starred…): scroll to that message once loaded.
        .onChange(of: state.loading) { loading in
            if !loading, let focus = host.focusRequest, focus.0 == state.id {
                host.focusRequest = nil
                jumpTo(focus.1)
            }
        }
    }

    // MARK: Messages

    /// Newest first in a list flipped upside down: the conversation is anchored to the bottom like every messenger.
    private var lines: [Line] {
        var out: [Line] = []
        let msgs = state.messages
        var lastDay: Date?
        func sameRun(_ a: UiMessage?, _ b: UiMessage) -> Bool {
            guard let a, a.dto.sender?.key == b.dto.sender?.key, a.dto.type != "system", b.dto.type != "system",
                  let ta = parseRideTime(a.dto.createdAt), let tb = parseRideTime(b.dto.createdAt) else { return false }
            return abs(tb.timeIntervalSince(ta)) < 300 && dayOf(a.dto.createdAt) == dayOf(b.dto.createdAt)
        }
        for (i, m) in msgs.enumerated() {
            let day = dayOf(m.dto.createdAt) ?? Calendar.current.startOfDay(for: Date())
            if day != lastDay {
                out.append(.day(day))
                lastDay = day
            }
            let prev = i > 0 ? msgs[i - 1] : nil
            let next = i + 1 < msgs.count ? msgs[i + 1] : nil
            out.append(.msg(m, first: !sameRun(prev, m), last: next == nil || !sameRun(m, next!)))
        }
        return out.reversed()
    }

    @ViewBuilder
    private var messagesArea: some View {
        let c = state.conversation
        if state.loading && state.messages.isEmpty {
            ProgressView().tint(ch.red).frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if state.failed && state.messages.isEmpty {
            ChEmptyState(icon: "exclamationmark.triangle.fill", title: L("ch_load_failed"), text: L("ch_error_network"), action: L("ch_retry")) {
                Task { await state.load() }
            }
        } else {
            ScrollViewReader { proxy in
                ScrollView(showsIndicators: false) {
                    LazyVStack(spacing: 0) {
                        // Where the bottom is: anything below -60 means I scrolled up.
                        GeometryReader { g in
                            Color.clear.preference(key: BottomOffsetKey.self, value: g.frame(in: .named("conv")).minY)
                        }
                        .frame(height: 8)
                        if host.activity(state.id).first != nil {
                            TypingBubble(label: nil).flipped().id("typing")
                        }
                        ForEach(lines) { line in
                            switch line {
                            case .day(let d):
                                ChChip(text: dayLabel(d)).padding(.vertical, 10).frame(maxWidth: .infinity).flipped()
                            case .msg(let m, let first, let last):
                                // A channel's posts come from the channel, not from a person: no names / avatars.
                                MessageRow(m: m, firstInRun: first, lastInRun: last, isGroup: c?.isGroup == true && !(c?.isChannel ?? false),
                                           actions: actions, highlighted: highlight == m.id)
                                    .flipped()
                                    .id(m.id)
                            }
                        }
                        if state.loadingOlder {
                            ProgressView().tint(ch.red).padding(12).flipped()
                        }
                        if !state.hasMoreBefore && state.messages.isEmpty {
                            ChChip(text: L("ch_empty_conversation")).padding(40).flipped()
                        }
                        // Page back in history when the top comes into view.
                        Color.clear.frame(height: 1).onAppear { Task { await state.loadOlder() } }
                    }
                    // Flipped: `top` is the visual bottom — room for the message box floating over it.
                    .padding(.top, 8 + bottomHeight).padding(.bottom, 12)
                    .animation(.spring(response: 0.35, dampingFraction: 0.85), value: state.messages.map(\.id))
                }
                .flipped()
                .coordinateSpace(name: "conv")
                .scrollDismissesKeyboard(.interactively)
                .onPreferenceChange(BottomOffsetKey.self) { y in
                    let bottom = y > -60
                    if bottom != atBottom { atBottom = bottom }
                }
                .onAppear { scroller = proxy }
                // Keep the newest message in view when I send / when I'm already at the bottom.
                .onChange(of: state.messages.last?.id) { _ in
                    guard let last = state.messages.last, last.isMine || atBottom else { return }
                    withAnimation(.easeOut(duration: 0.25)) { proxy.scrollTo(last.id, anchor: .bottom) }
                }
            }
            .onChange(of: atBottom) { bottom in
                state.atBottom = bottom
                // After a jump to an old message, reaching the bottom loads what came after it.
                if bottom && state.hasMoreAfter { Task { await state.loadNewer() } }
                if bottom && state.unseenBelow > 0 && !state.hasMoreAfter {
                    state.unseenBelow = 0
                    state.markRead()
                }
            }
        }
    }

    /// ↓ with the number of messages that arrived while scrolled up.
    private var downButton: some View {
        Button {
            if let last = state.messages.last { withAnimation { scroller?.scrollTo(last.id, anchor: .bottom) } }
        } label: {
            Image(systemName: "chevron.down").font(.system(size: 18, weight: .bold)).foregroundStyle(ch.red)
                .frame(width: 46, height: 46).background(ch.surface, in: Circle())
                .shadow(color: .black.opacity(0.18), radius: 8, y: 3)
                .overlay(alignment: .topTrailing) {
                    if state.unseenBelow > 0 { ChBadge(count: state.unseenBelow).offset(x: 4, y: -4) }
                }
        }
        .buttonStyle(.plain)
        .padding(14)
        .padding(.bottom, bottomHeight)
    }

    private var actions: BubbleActions {
        let state = state
        let host = host
        return BubbleActions(
            onReply: { state.replyTo = $0; state.editing = nil },
            onLongPress: { m in withAnimation(.spring(response: 0.35, dampingFraction: 0.75)) { state.focused = m } },
            onJumpTo: { jumpTo($0) },
            onRetry: { state.retry($0) },
            onOpenMedia: { m, i in
                let a = m.files[safe: i]
                // Videos play in the system player; photos open in the viewer.
                if let a, a.mimeType?.hasPrefix("video/") == true, let url = ApiClient.mediaUrl(a.url) {
                    presentVideo(url)
                } else {
                    withAnimation(.easeOut(duration: 0.2)) { viewer = (m, i) }
                }
            },
            onPayQr: { host.openWalletQr($0) },
            onMessageContact: { phone in
                Task {
                    do {
                        guard let who = try await ChatApi.lookup(["phone": phone]).data else { return }
                        host.openChatWith(who, keepCurrent: true)
                    } catch {
                        host.showToast(L("ch_not_on_dorr"))
                    }
                }
            },
            onVote: { m, option in state.vote(m, option: option) },
            onShowVotes: { votesFor = $0 },
            onChoose: { m, option in state.choose(m, option: option) },
            onOpenViewOnce: { m in
                Task {
                    if let files = await state.openViewOnce(m), !files.isEmpty { withAnimation { viewOnceFiles = files } }
                }
            },
            onStopLive: { state.stopLive($0) },
            onOpenLive: { liveFor = $0.id },
            onJoinGroup: { host.joinToken = $0 },
            onPay: { payFor = $0.dto },
            onDeclineRequest: { state.declineRequest($0) },
            onCancelRequest: { state.cancelRequest($0) },
            onTranscribe: { state.transcribe($0) },
            onHideAi: { state.hideAi($0) },
            onOpenThread: { host.push(.thread(conversationId: $0.conversationId, rootId: $0.id)) },
            isRevealed: { state.revealed[$0] != nil },
            // A sensitive message needs Face ID / the passcode; blurred media just a tap.
            onReveal: { m in
                if m.dto.isSensitive == true { ChatLock.unlock { state.reveal(m.id) } } else { state.reveal(m.id) }
            },
            onOpenSealed: { m in Task { await state.refreshOne(m.id) } }
        )
    }

    private func jumpTo(_ messageId: String) {
        Task {
            // Not loaded yet (an old search result / pin): load the page around it first.
            if !state.messages.contains(where: { $0.id == messageId }) {
                guard await state.loadAround(messageId) else { return }
                try? await Task.sleep(nanoseconds: 120_000_000)
            }
            withAnimation(.easeInOut(duration: 0.3)) { scroller?.scrollTo(messageId, anchor: .center) }
            highlight = messageId
            try? await Task.sleep(nanoseconds: 1_400_000_000)
            if highlight == messageId { highlight = nil }
        }
    }

    private func closeSearch() {
        withAnimation {
            searching = false
            searchQuery = ""
            kinds = []
            byMeaning = false
            searchResults = nil
        }
    }

    private func search() async {
        let q = searchQuery.trimmingCharacters(in: .whitespaces)
        meaningTerms = []
        if q.count < 2 && kinds.isEmpty {
            searchResults = nil
            return
        }
        if byMeaning && q.count >= 2 {
            try? await Task.sleep(nanoseconds: 500_000_000)
            guard !Task.isCancelled else { return }
            let found = try? await AiToolsApi.searchByMeaning(state.id, q: q).data
            guard !Task.isCancelled else { return }
            meaningTerms = found?.terms ?? []
            withAnimation { searchResults = found?.messages ?? [] }
            return
        }
        try? await Task.sleep(nanoseconds: 300_000_000)
        guard !Task.isCancelled else { return }
        let found = try? await ChatApi.searchIn(state.id, q: q.count >= 2 ? q : nil, types: kinds.isEmpty ? nil : Array(kinds)).data
        guard !Task.isCancelled else { return }
        withAnimation { searchResults = found ?? [] }
    }
}

private struct BottomOffsetKey: PreferenceKey {
    static var defaultValue: CGFloat = 0
    static func reduce(value: inout CGFloat, nextValue: () -> CGFloat) { value = nextValue() }
}

extension View {
    /// Upside down (for the bottom-anchored message list and each of its rows).
    func flipped() -> some View { scaleEffect(x: 1, y: -1, anchor: .center) }
}

/// Plays a video full screen in the system player.
@MainActor func presentVideo(_ url: URL) {
    let asset = AVURLAsset(url: url, options: ["AVURLAssetHTTPHeaderFieldsKey": ["ngrok-skip-browser-warning": "1"]])
    let player = AVPlayer(playerItem: AVPlayerItem(asset: asset))
    let controller = AVPlayerViewController()
    controller.player = player
    var top = UIApplication.shared.connectedScenes.compactMap { ($0 as? UIWindowScene)?.keyWindow }.first?.rootViewController
    while let presented = top?.presentedViewController { top = presented }
    top?.present(controller, animated: true) { player.play() }
}


/// The conversation background for a theme: its image (cropped, slightly dimmed so bubbles stay readable), or its
/// colour with the doodle pattern, or — no theme — Dorr's own wallpaper.
struct ThemedWallpaper: View {
    @ObservedObject private var ch = Ch.shared
    let theme: ChatThemeDto?

    var body: some View {
        ZStack {
            if let t = theme {
                (hexColor(t.backgroundColor) ?? ch.bg)
                if let wallpaper = t.wallpaper {
                    ChatImage(url: ApiClient.mediaUrl(wallpaper))
                    // My own picture: darkened as much as I chose; an admin theme: a light standard veil.
                    let veil = t.isCustom == true ? Double(t.dim ?? 0) / 100 : (t.isDark == true || ch.dark) ? 0.28 : 0.06
                    Color.black.opacity(min(max(veil, 0), 0.8))
                } else {
                    ChWallpaper(tint: hexColor(t.senderColor).map { t.isDark == true ? .white : $0.scaled(0.6) })
                }
            } else {
                ChWallpaper()
            }
        }
        .animation(.easeInOut(duration: 0.42), value: theme)
    }
}

// MARK: - Header

private struct ConversationHeader: View {
    @ObservedObject var host: ChatHost
    @ObservedObject var state: ConversationState
    @ObservedObject private var features = ChatFeatures.shared
    let onSearch: () -> Void
    var onAi: () -> Void = {}
    @ObservedObject private var ai = ChatAi.shared
    @State private var pop: CGFloat = 0
    @State private var linkTypePicker = false
    @State private var showingLink: CallLinkDto?

    var body: some View {
        let c = state.conversation
        let peerKey = c?.peer?.key
        let presence = peerKey.flatMap { host.presence[$0] } ?? c?.presence
        let activity = host.activity(state.id).first
        DarkHeroPanel {
            HStack(spacing: 8) {
                HStack(spacing: 8) {
                    ChAvatar(url: c?.avatar, name: c?.title, key: peerKey ?? state.id, size: 34, isGroup: c?.isGroup == true, online: presence?.online == true)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: ltrTitle(c?.title)).font(.dorr(16, .bold)).foregroundStyle(.white).lineLimit(1)
                        HStack(spacing: 5) {
                            Text(verbatim: subtitle(c, presence, activity)).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.85)).lineLimit(1)
                            if activity != nil { TypingDots(color: .white, dot: 4) }
                        }
                        .id(subtitle(c, presence, activity))
                        .transition(.asymmetric(insertion: .move(edge: .bottom).combined(with: .opacity), removal: .move(edge: .top).combined(with: .opacity)))
                        .animation(.easeInOut(duration: 0.25), value: subtitle(c, presence, activity))
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
                .contentShape(Rectangle())
                .onTapGesture { host.push(.info(state.id)) }
                // No calls with myself, nor where calls are switched off, nor while a request I sent waits.
                if let c, c.status == "accepted", c.canSend == true, c.isSelf != true, c.canCall != false {
                    Menu {
                        Button { startCall(state.id, video: false, title: c.title ?? "", avatar: c.avatar, peerKey: peerKey) } label: { Label(L("ch_call_voice"), systemImage: "phone.fill") }
                        Button { startCall(state.id, video: true, title: c.title ?? "", avatar: c.avatar, peerKey: peerKey) } label: { Label(L("ch_call_video"), systemImage: "video.fill") }
                        Divider()
                        Button { linkTypePicker = true } label: { Label(L("ch_send_call_link"), systemImage: "link.badge.plus") }
                    } label: {
                        HStack(spacing: 0) {
                            Image(systemName: "phone.fill").font(.system(size: 13))
                            Image(systemName: "arrowtriangle.down.fill").font(.system(size: 7)).padding(.leading, 3)
                        }
                        .foregroundStyle(.white)
                        .padding(.leading, 10).padding(.trailing, 8).frame(height: 34)
                        .background(Color.white.opacity(0.12), in: Capsule())
                        .environment(\.layoutDirection, .leftToRight)
                    }
                    .scaleEffect(pop)
                    .accessibilityLabel(L("ch_calls_title"))
                }
                if ai.caps?.enabled == true || ai.summarize || ai.ask || ai.assistant {
                    DarkHeroButton(icon: "sparkles", label: L("ch_ai_menu"), action: onAi)
                }
                Menu {
                    Button(action: onSearch) { Label(L("ch_search_in_chat"), systemImage: "magnifyingglass") }
                    Button { host.push(.info(state.id)) } label: { Label(L("ch_info"), systemImage: "info.circle.fill") }
                    Button { host.push(.media(state.id)) } label: { Label(L("ch_media"), systemImage: "photo.on.rectangle") }
                    if let c, c.isGroup, c.isRequest != true, c.canCall != false, features.allows("voice_rooms") {
                        Button { host.push(.voiceRoom(conversationId: state.id, title: c.title)) } label: { Label(L("vr_join_room"), systemImage: "waveform") }
                    }
                } label: {
                    Image(systemName: "ellipsis").font(.system(size: 14, weight: .bold)).foregroundStyle(.white).rotationEffect(.degrees(90))
                        .frame(width: 34, height: 34).background(Color.white.opacity(0.12), in: Circle())
                }
                .accessibilityLabel(L("ch_share_more"))
                DarkHeroButton(icon: "chevron.right", label: L("common_back")) { host.pop() }
            }
            if c?.disappearingSeconds != nil {
                Image(systemName: "timer").font(.system(size: 11)).foregroundStyle(.white.opacity(0.7)).frame(maxWidth: .infinity).padding(.top, 2)
            }
        }
        .padding(.horizontal, 16)
        .task { withAnimation(.spring(response: 0.45, dampingFraction: 0.6)) { pop = 1 } }
        .dorrDialog(isPresented: $linkTypePicker, bottom: true) {
            CallLinkTypeSheet(onPick: makeLink) { linkTypePicker = false }
        }
        .dorrDialog(isPresented: Binding(get: { showingLink != nil }, set: { if !$0 { showingLink = nil } }), bottom: true) {
            if let l = showingLink { CallLinkShareSheet(host: host, link: l, onCancelled: {}) { showingLink = nil } }
        }
    }

    /// A call link to send (WhatsApp list 72): made, then shown to copy / share / join.
    private func makeLink(_ type: String) {
        Task {
            do {
                let made = try await ChatApi.createCallLink(type: type).data
                try? await Task.sleep(nanoseconds: 350_000_000)
                showingLink = made
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
        }
    }

    private func subtitle(_ c: ConversationDto?, _ presence: PresenceDto?, _ activity: ChatActivity?) -> String {
        let online = presence?.online == true ? " · " + L("ch_online") : ""
        if let activity { return L(activity.state == "recording" ? "ch_recording" : "ch_typing") }
        if c?.isChannel == true { return L("ch_followers", c?.group?.membersCount ?? 0) }
        if c?.isGroup == true { return L("ch_members", c?.group?.membersCount ?? 0) }
        if c?.isSelf == true { return L("ch_note_to_self_sub") }
        // Their status («🏖️ On holiday»), with «online» when they are.
        if let status = statusLine(c?.peer?.status) { return status + online }
        // A business: open or closed now (and online, when it is).
        if let business = c?.business { return businessStatus(business) + online }
        if presence?.online == true { return L("ch_online") }
        if let seen = presence?.lastSeenAt { return lastSeenText(seen) }
        return L("ch_tap_info")
    }

    private func lastSeenText(_ iso: String) -> String {
        dayOf(iso) == Calendar.current.startOfDay(for: Date()) ? L("ch_last_seen_today", clockTime(iso)) : L("ch_last_seen", listTime(iso) + " " + clockTime(iso))
    }
}

/// «🏖️ On holiday» for a profile's status, or nil.
func statusLine(_ status: UserStatusDto?) -> String? {
    guard let status else { return nil }
    let text = [status.emoji, status.text].compactMap { $0 }.joined(separator: " ").trimmingCharacters(in: .whitespaces)
    return text.isEmpty ? nil : text
}

private struct ConversationSearchBar: View {
    @Binding var query: String
    let onClose: () -> Void
    @FocusState private var focused: Bool

    var body: some View {
        DarkHeroPanel {
            HStack(spacing: 8) {
                HStack(spacing: 8) {
                    Image(systemName: "magnifyingglass").font(.system(size: 15)).foregroundStyle(.white.opacity(0.85))
                    ZStack(alignment: .leading) {
                        if query.isEmpty { Text(L("ch_search_in_chat")).font(.dorr(14)).foregroundStyle(.white.opacity(0.55)).lineLimit(1) }
                        TextField("", text: $query).font(.dorr(14.5, .medium)).foregroundStyle(.white).tint(.white).focused($focused).submitLabel(.search)
                    }
                    if !query.isEmpty {
                        Button { query = "" } label: { Image(systemName: "xmark.circle.fill").foregroundStyle(.white.opacity(0.7)) }.buttonStyle(.plain)
                    }
                }
                .padding(.horizontal, 14).frame(height: 42)
                .background(Color.white.opacity(0.14), in: RoundedRectangle(cornerRadius: 16))
                DarkHeroButton(icon: "xmark", label: L("ch_close_sheet"), action: onClose)
            }
        }
        .padding(.horizontal, 16)
        .onAppear { focused = true }
    }
}

/// Under the search bar: narrow to kinds of message (with or without words).
private struct SearchKindChips: View {
    @ObservedObject private var ch = Ch.shared
    let selected: Set<String>
    let onToggle: (String) -> Void

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 6) {
                ForEach(searchKinds, id: \.0) { key, label in
                    let on = selected.contains(key)
                    Text(L(label)).font(.dorr(12.5, .bold)).foregroundStyle(on ? .white : ch.ink)
                        .padding(.horizontal, 12).padding(.vertical, 7)
                        .background(on ? ch.red : ch.surface, in: Capsule())
                        .onTapGesture { onToggle(key) }
                }
            }
            .padding(.horizontal, 12).padding(.vertical, 8)
        }
        .background(ch.bg)
    }
}

/// Search results over the conversation: sender, the text with the match in bold, the date.
private struct SearchResults: View {
    @ObservedObject private var ch = Ch.shared
    let query: String
    let results: [MessageDto]
    let onPick: (String) -> Void

    var body: some View {
        ScrollView {
            LazyVStack(spacing: 6) {
                if results.isEmpty {
                    Text(L("ch_no_results")).font(.dorr(14)).foregroundStyle(ch.soft).padding(30)
                }
                ForEach(Array(results.enumerated()), id: \.element.id) { i, m in
                    VStack(alignment: .leading, spacing: 3) {
                        HStack {
                            Text(verbatim: m.sender?.isMe == true ? L("ch_you") : m.sender?.name ?? "").font(.dorr(13, .heavy)).foregroundStyle(Ch.colorFor(m.sender?.key))
                            Spacer()
                            Text(verbatim: listTime(m.createdAt)).font(.dorr(11.5)).foregroundStyle(ch.soft)
                        }
                        Text(highlighted(plainChatText(m.body))).font(.dorr(14)).foregroundStyle(ch.ink).lineLimit(3)
                    }
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(ch.surface, in: RoundedRectangle(cornerRadius: 16))
                    .contentShape(Rectangle())
                    .onTapGesture { onPick(m.id) }
                    .chStagger(i)
                }
            }
            .padding(12)
        }
        .background(ch.bg.opacity(0.97))
    }

    private func highlighted(_ body: String) -> AttributedString {
        var a = AttributedString(body)
        let q = query.trimmingCharacters(in: .whitespaces)
        if !q.isEmpty, let r = a.range(of: q, options: .caseInsensitive) {
            a[r].foregroundColor = ch.red
            a[r].font = .dorr(14, .heavy)
        }
        return a
    }
}

/// One line: a short mark per pin (the shown one lit), the pin (every pinned message in a list), and the message as
/// its kind reads (androidApp `PinnedBanner`). Tap: go to it, and the bar moves on to the next pin; swipe sideways:
/// the next / previous pin without leaving my place.
private struct PinnedBanner: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var state: ConversationState
    let onJump: (String) -> Void
    @State private var index = 0
    @State private var step = 1
    @State private var listOpen = false
    /// How far the bar slides up under the header.
    private let tuck: CGFloat = 30
    @Environment(\.layoutDirection) private var direction
    /// Swiped left (step +1): the next one comes in from the screen's right.
    private var fromRight: Bool { step > 0 }
    /// The screen's right / left as SwiftUI edges (leading is the right in Arabic).
    private var rightEdge: Edge { direction == .rightToLeft ? .leading : .trailing }
    private var leftEdge: Edge { direction == .rightToLeft ? .trailing : .leading }

    var body: some View {
        let pinned = state.pinned
        if !pinned.isEmpty {
            let at = ((index % pinned.count) + pinned.count) % pinned.count
            let current = pinned[at]
            HStack(spacing: 0) {
                if pinned.count > 1 {
                    VStack(spacing: 2) {
                        ForEach(pinned.indices, id: \.self) { i in
                            RoundedRectangle(cornerRadius: 2).fill(i == at ? ch.red : ch.red.opacity(0.28))
                        }
                    }
                    .frame(width: 3).padding(.vertical, 2).padding(.trailing, 8)
                }
                Button { listOpen = true } label: {
                    Image(systemName: "pin.fill").font(.system(size: 15)).foregroundStyle(ch.red).rotationEffect(.degrees(35))
                        .frame(width: 34, height: 34).background(ch.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 10))
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("ch_pinned_list"))
                .padding(.trailing, 10)
                PinnedPreview(m: current)
                    .id(current.id)
                    // Sideways, the way the finger went: swiped left, the next one comes in from the right.
                    .transition(.asymmetric(insertion: .move(edge: fromRight ? rightEdge : leftEdge).combined(with: .opacity),
                                            removal: .move(edge: fromRight ? leftEdge : rightEdge).combined(with: .opacity)))
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .clipped()
            }
            .fixedSize(horizontal: false, vertical: true)
            // Full width, tucked up under the header's rounded bottom (the header draws over it): it reads as
            // hanging from the header instead of a separate card.
            .padding(.leading, 18).padding(.trailing, 20).padding(.top, 6 + tuck).padding(.bottom, 8)
            .background(ch.surface, in: BottomRoundedRectangle(radius: 22))
            .shadow(color: .black.opacity(0.1), radius: 5, y: 2)
            .padding(.top, -tuck)
            .contentShape(Rectangle())
            // Tap: go to the pin shown. Swiping only changes which one is shown.
            .onTapGesture { onJump(current.id) }
            .gesture(DragGesture(minimumDistance: 20).onEnded { v in
                guard pinned.count > 1, abs(v.translation.width) > 48, abs(v.translation.width) > abs(v.translation.height) else { return }
                step = v.translation.width < 0 ? 1 : -1
                withAnimation(.easeOut(duration: 0.25)) { index += step }
            })
            .transition(.move(edge: .top).combined(with: .opacity))
            .dorrDialog(isPresented: $listOpen, bottom: true) {
                PinnedListSheet(state: state) { id in listOpen = false; onJump(id) }
            }
        }
    }
}

/// One pinned message in a line: the chat list's icon for its kind, its words, a small picture when it has one.
private struct PinnedPreview: View {
    @ObservedObject private var ch = Ch.shared
    let m: MessageDto

    var body: some View {
        let s = replySummary(m)
        HStack(spacing: 8) {
            HStack(spacing: 4) {
                if let icon = s.icon { Image(systemName: icon).font(.system(size: 13)).foregroundStyle(ch.mut) }
                Text(verbatim: m.isDeleted == true ? L("ch_deleted") : s.text).font(.dorr(13.5)).foregroundStyle(ch.ink).lineLimit(1)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if let thumb = s.thumb {
                ChatImage(url: ApiClient.mediaUrl(thumb)).frame(width: 34, height: 34).clipShape(RoundedRectangle(cornerRadius: 8))
            } else if s.map {
                MiniMap().frame(width: 34, height: 34).clipShape(RoundedRectangle(cornerRadius: 8))
            }
        }
    }
}

/// Every pinned message: tap one to go to it, or take its pin off.
private struct PinnedListSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var state: ConversationState
    let onJump: (String) -> Void

    var body: some View {
        ChSheetCard {
            Text(L("ch_pinned_list")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 10)
            ForEach(state.pinned, id: \.id) { m in
                let me = m.sender?.key == myKey()
                HStack(spacing: 4) {
                    VStack(alignment: .leading, spacing: 1) {
                        Text(verbatim: me ? L("ch_you") : m.sender?.name ?? "").font(.dorr(12.5, .heavy))
                            .foregroundStyle(me ? ch.red : Ch.colorFor(m.sender?.key)).lineLimit(1)
                        PinnedPreview(m: m)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Button { state.unpin(m) } label: {
                        Image(systemName: "xmark").font(.system(size: 15, weight: .semibold)).foregroundStyle(ch.mut).frame(width: 40, height: 40)
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("ch_unpin"))
                }
                .padding(.leading, 12).padding(.trailing, 4).padding(.vertical, 8)
                .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 14))
                .contentShape(Rectangle())
                .onTapGesture { onJump(m.id) }
                .padding(.vertical, 4)
            }
        }
    }
}

// MARK: - Bottom

private struct BottomArea: View {
    @ObservedObject var host: ChatHost
    @ObservedObject var state: ConversationState

    var body: some View {
        if let c = state.conversation {
            if c.isRequest == true {
                RequestBar(host: host, state: state, c: c)
            } else if c.iBlocked == true {
                NoticeBar(text: L("ch_blocked_bar"), icon: "nosign") {
                    guard let peer = c.peer else { return }
                    Task {
                        _ = try? await ChatApi.unblock(participantId: peer.id)
                        state.conversation = (try? await ChatApi.conversation(c.id).data) ?? c
                    }
                }
            } else if c.isMember == false {
                // A channel I don't (or no longer) follow: one big «Follow».
                if c.isChannel { ChannelFollowBar(host: host, c: c) { state.conversation = $0 } } else { NoticeBar(text: L("ch_not_member"), icon: nil) {} }
            } else if c.isChannel && c.canSend == false {
                // A follower: mute and share instead of a composer.
                ChannelFollowerBar(host: host, c: c) { state.conversation = $0 }
            } else if c.blockedMe == true || c.status == "rejected" {
                NoticeBar(text: L("ch_cant_send"), icon: nil) {}
            } else if c.canSend == false {
                NoticeBar(text: L("ch_only_admins"), icon: nil) {}
            } else {
                ChatComposer(host: host, state: state)
            }
        } else {
            Color.clear.frame(height: 1)
        }
    }
}

private struct NoticeBar: View {
    @ObservedObject private var ch = Ch.shared
    let text: String
    let icon: String?
    let onTap: () -> Void

    var body: some View {
        HStack(spacing: 8) {
            if let icon { Image(systemName: icon).font(.system(size: 16)).foregroundStyle(ch.red) }
            Text(verbatim: text).font(.dorr(13.5, .semibold)).foregroundStyle(ch.mut).multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity).padding(16)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 18))
        .padding(12)
        .contentShape(Rectangle())
        .onTapGesture(perform: onTap)
    }
}

/// A stranger's first message: who wants to talk, two plain facts, then accept — or delete / block.
private struct RequestBar: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    @ObservedObject var state: ConversationState
    let c: ConversationDto
    @State private var accepting = false

    var body: some View {
        VStack(spacing: 0) {
            Image(systemName: "person").font(.system(size: 19, weight: .medium)).foregroundStyle(ch.mut)
                .frame(width: 40, height: 40).background(ch.surfaceMuted, in: Circle())
            Text(L("ch_request_label")).font(.dorr(12)).foregroundStyle(ch.mut).padding(.top, 6)
            Text(verbatim: spacedPhone(c.title ?? "")).font(.dorr(16.5, .heavy)).foregroundStyle(ch.ink).lineLimit(1)
            // Two short facts side by side; the second wraps under the first only on a narrow phone.
            ViewThatFits(in: .horizontal) {
                HStack(spacing: 6) { facts }
                VStack(spacing: 6) { facts }
            }
            .padding(.top, 8)
            // One tap only: a second accept would hit a conversation that's no longer a request.
            button(L("ch_accept"), icon: "checkmark", fg: .white, primary: true) {
                guard !accepting else { return }
                accepting = true
                Task {
                    if let fresh = try? await ChatApi.accept(c.id).data {
                        state.conversation = fresh
                        host.upsert(fresh)
                        state.markRead()
                    }
                    accepting = false
                }
            }
            .opacity(accepting ? 0.5 : 1)
            .disabled(accepting)
            .padding(.top, 12)
            HStack(spacing: 8) {
                button(L("ch_reject"), icon: "trash", fg: ch.ink) { reject(block: false) }
                button(L("ch_block"), icon: "nosign", fg: ch.danger) { reject(block: true) }
            }
            .padding(.top, 8)
        }
        .padding(.horizontal, 14).padding(.vertical, 14)
        .frame(maxWidth: .infinity)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 22))
        .shadow(color: .black.opacity(0.12), radius: 10, y: 3)
        .padding(.horizontal, 12).padding(.vertical, 10)
    }

    @ViewBuilder
    private var facts: some View {
        fact("exclamationmark.circle", L("ch_request_not_contact"))
        fact("eye.slash", L("ch_request_no_read"))
    }

    private func fact(_ icon: String, _ text: String) -> some View {
        HStack(spacing: 4) {
            Image(systemName: icon).font(.system(size: 11))
            Text(verbatim: text).font(.dorr(11, .semibold)).lineLimit(1)
        }
        .foregroundStyle(ch.mut)
        .padding(.horizontal, 9).padding(.vertical, 5)
        .background(ch.surfaceMuted, in: Capsule())
    }

    private func button(_ text: String, icon: String, fg: Color, primary: Bool = false, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 15, weight: .semibold))
                Text(verbatim: text).font(.dorr(14, .heavy))
            }
            .foregroundStyle(fg)
            .frame(maxWidth: .infinity).padding(.vertical, 10)
            .background {
                if primary {
                    RoundedRectangle(cornerRadius: 14).fill(LinearGradient(colors: [ch.red, ch.redDeep], startPoint: .leading, endPoint: .trailing))
                } else {
                    RoundedRectangle(cornerRadius: 14).fill(ch.surfaceMuted)
                }
            }
        }
        .buttonStyle(.plain)
    }

    /// A number shown as «+20 127 773 8694» (country code from the cached countries, then the rest in groups: the
    /// last four, threes before them). Anything else, or a code we don't know, stays as it is. Kept left-to-right.
    private func spacedPhone(_ raw: String) -> String {
        let digits = raw.filter(\.isNumber)
        guard raw.trimmingCharacters(in: .whitespaces).range(of: #"^\+?[\d\s\-()]{8,20}$"#, options: .regularExpression) != nil else { return raw }
        let dial = CountryCache.resolveForPhone(digits)?.dialCode.filter(\.isNumber) ?? ""
        guard !dial.isEmpty else { return "\u{2066}+\(digits)\u{2069}" }
        var rest = Substring(digits.dropFirst(dial.count))
        var groups: [Substring] = []
        if rest.count > 4 { groups.append(rest.suffix(4)); rest = rest.dropLast(4) }
        while rest.count > 3 { groups.append(rest.suffix(3)); rest = rest.dropLast(3) }
        if !rest.isEmpty { groups.append(rest) }
        return "\u{2066}+\(dial) \(groups.reversed().joined(separator: " "))\u{2069}"
    }

    private func reject(block: Bool) {
        Task {
            _ = try? await ChatApi.reject(c.id, block: block)
            host.remove(c.id)
            host.pop()
        }
    }
}

/// Shown instead of a locked conversation until the phone's lock is passed.
private struct LockedGate: View {
    @ObservedObject private var ch = Ch.shared
    let onUnlocked: () -> Void

    var body: some View {
        VStack(spacing: 18) {
            Image(systemName: "lock.fill").font(.system(size: 40)).foregroundStyle(.white)
                .frame(width: 96, height: 96).background(ch.headerBrush, in: Circle())
                .shadow(color: ch.red.opacity(0.45), radius: 16, y: 6)
            Text(L("ch_locked_note")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            ChPrimaryButton(text: L("ch_unlock"), icon: "lock.fill", full: false) { ChatLock.unlock(onSuccess: onUnlocked) }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(ch.bg.ignoresSafeArea())
        .task { ChatLock.unlock(onSuccess: onUnlocked) }
    }
}

// MARK: - Viewers & sheets

/// Full-screen photos: swipe between them, pinch to zoom, drag down to close.
struct MediaViewer: View {
    let message: MessageDto
    let start: Int
    let onClose: () -> Void
    @State private var page = 0
    @State private var drag: CGFloat = 0

    var body: some View {
        let images = message.files.filter { $0.mimeType?.hasPrefix("image/") ?? true }
        ZStack {
            Color.black.opacity(Double(max(0.2, 1 - abs(drag) / 900))).ignoresSafeArea()
            TabView(selection: $page) {
                ForEach(Array(images.enumerated()), id: \.offset) { i, a in
                    ZoomableImage(url: ApiClient.mediaUrl(a.url)).tag(i)
                }
            }
            .tabViewStyle(.page(indexDisplayMode: .never))
            .offset(y: drag)
            .scaleEffect(1 - abs(drag) / 3000)
            .simultaneousGesture(DragGesture(minimumDistance: 20)
                .onChanged { v in if abs(v.translation.height) > abs(v.translation.width) { drag = v.translation.height } }
                .onEnded { _ in
                    if abs(drag) > 260 { onClose() } else { withAnimation(.spring(response: 0.35, dampingFraction: 0.6)) { drag = 0 } }
                })
            VStack {
                HStack(spacing: 12) {
                    Button(action: onClose) {
                        Image(systemName: "xmark").font(.system(size: 17, weight: .semibold)).foregroundStyle(.white)
                            .frame(width: 42, height: 42).background(Color.white.opacity(0.15), in: Circle())
                    }
                    .buttonStyle(.plain)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: message.sender?.name ?? "").font(.dorr(15, .bold)).foregroundStyle(.white)
                        Text(verbatim: listTime(message.createdAt) + " " + clockTime(message.createdAt)).font(.dorr(12)).foregroundStyle(.white.opacity(0.7))
                    }
                    Spacer()
                    if images.count > 1 { Text(verbatim: "\(page + 1)/\(images.count)").font(.dorr(15, .bold)).foregroundStyle(.white) }
                }
                .padding(16)
                Spacer()
                if let body = message.body, !body.isEmpty {
                    Text(verbatim: plainChatText(body)).font(.dorr(15)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity, alignment: .leading).padding(20).background(Color.black.opacity(0.45))
                }
            }
        }
        .onAppear { page = min(max(start, 0), max(images.count - 1, 0)) }
    }
}

private struct ZoomableImage: View {
    let url: URL?
    @State private var scale: CGFloat = 1
    @State private var last: CGFloat = 1

    var body: some View {
        AsyncImage(url: url) { phase in
            if let image = phase.image { image.resizable().scaledToFit() } else { ProgressView().tint(.white) }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .scaleEffect(scale)
        .gesture(MagnificationGesture()
            .onChanged { scale = min(max(last * $0, 1), 5) }
            .onEnded { _ in last = scale })
        .onTapGesture(count: 2) { withAnimation { scale = scale > 1 ? 1 : 2.5; last = scale } }
    }
}

/// The view-once file, full screen, this one time: closing it is final. Images show; videos and voice notes play once.
struct ViewOnceViewer: View {
    @ObservedObject private var ch = Ch.shared
    let files: [AttachmentDto]
    let onClose: () -> Void
    @State private var appear: CGFloat = 0.9
    @State private var player: AVPlayer?

    var body: some View {
        let file = files.first
        let mime = file?.mimeType ?? ""
        let url = file.flatMap { ApiClient.mediaUrl($0.url) }
        ZStack {
            Color.black.ignoresSafeArea()
            if mime.hasPrefix("image/") {
                AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFit() }.scaleEffect(appear)
            } else if mime.hasPrefix("audio/") {
                Image(systemName: "mic.fill").font(.system(size: 56)).foregroundStyle(.white)
                    .frame(width: 140, height: 140).background(ch.headerBrush, in: Circle())
            } else if let player {
                VideoPlayer(player: player)
            }
            VStack {
                ZStack {
                    HStack(spacing: 8) {
                        Text(verbatim: "1").font(.dorr(11, .heavy)).foregroundStyle(.white).frame(width: 20, height: 20).background(ch.red, in: Circle())
                        Text(L("ch_view_once_title")).font(.dorr(13, .bold)).foregroundStyle(.white)
                    }
                    .padding(.horizontal, 14).padding(.vertical, 7)
                    .background(Color.white.opacity(0.14), in: RoundedRectangle(cornerRadius: 16))
                    HStack {
                        Spacer()
                        Button(action: onClose) {
                            Image(systemName: "xmark").font(.system(size: 20, weight: .semibold)).foregroundStyle(.white)
                                .frame(width: 42, height: 42).background(Color.white.opacity(0.16), in: Circle())
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(14)
                Spacer()
            }
        }
        .onAppear {
            withAnimation(.spring(response: 0.4, dampingFraction: 0.7)) { appear = 1 }
            if !mime.hasPrefix("image/"), let url {
                let p = AVPlayer(playerItem: AVPlayerItem(asset: AVURLAsset(url: url, options: ["AVURLAssetHTTPHeaderFieldsKey": ["ngrok-skip-browser-warning": "1"]])))
                player = p
                p.play()
                NotificationCenter.default.addObserver(forName: .AVPlayerItemDidPlayToEndTime, object: p.currentItem, queue: .main) { _ in
                    Task { @MainActor in onClose() }
                }
            }
        }
        .onDisappear { player?.pause() }
    }
}

private struct MessageInfoSheet: View {
    @ObservedObject private var ch = Ch.shared
    let message: MessageDto
    let onDismiss: () -> Void
    @State private var info: MessageInfoDto?

    var body: some View {
        ChSheetCard {
            Text(L("ch_message_info")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 12)
            if let info {
                group(L("ch_read_by"), "read", (info.readBy ?? []).map { ($0.participant?.name ?? "", $0.readAt) })
                group(L("ch_delivered_to"), "delivered", (info.deliveredTo ?? []).map { ($0.participant?.name ?? "", nil) })
                group(L("ch_waiting"), "sent", (info.pending ?? []).map { ($0.participant?.name ?? "", nil) })
            } else {
                RoundedRectangle(cornerRadius: 16).fill(ch.surfaceMuted).frame(height: 120)
            }
        }
        .task { info = try? await ChatApi.info(message.id).data }
    }

    @ViewBuilder
    private func group(_ title: String, _ status: String, _ rows: [(String, String?)]) -> some View {
        if !rows.isEmpty {
            HStack(spacing: 6) {
                ChTicks(status: status, onBubble: false)
                Text(verbatim: title).font(.dorr(13, .bold)).foregroundStyle(ch.mut)
            }
            .padding(.top, 10).padding(.bottom, 4)
            ForEach(Array(rows.enumerated()), id: \.offset) { i, row in
                HStack(spacing: 10) {
                    ChAvatar(url: nil, name: row.0, key: row.0, size: 36)
                    Text(verbatim: row.0).font(.dorr(14.5, .semibold)).foregroundStyle(ch.ink).frame(maxWidth: .infinity, alignment: .leading)
                    if let at = row.1 { Text(verbatim: clockTime(at)).font(.dorr(12)).foregroundStyle(ch.soft) }
                }
                .padding(.vertical, 6)
                .chStagger(i)
            }
        }
    }
}

/// Who voted for what — one card per option, the most voted first. The first card stays open; the arrow on the others drops the people out underneath, a page at a time.
private struct PollVotesSheet: View {
    @ObservedObject private var ch = Ch.shared
    let message: MessageDto
    let onDismiss: () -> Void
    @State private var rows: [PollOptionVotersDto]?
    @State private var open: Set<Int> = []
    @State private var pages: [Int: Int] = [:]
    private let pageSize = 8

    var body: some View {
        let sorted = (rows ?? []).sorted { ($0.voters?.count ?? 0) > ($1.voters?.count ?? 0) }
        let firstId = sorted.first?.id
        ChSheetCard {
            HStack(spacing: 12) {
                Image(systemName: "chart.bar.fill").font(.system(size: 18)).foregroundStyle(.white).frame(width: 40, height: 40).background(ch.red, in: Circle())
                Text(verbatim: message.poll?.question ?? message.body ?? "").font(.dorr(17, .heavy)).foregroundStyle(ch.ink).lineLimit(2)
            }
            if rows != nil {
                ScrollView {
                    VStack(alignment: .leading, spacing: 8) {
                        ForEach(Array(sorted.enumerated()), id: \.element.id) { i, option in
                            let voters = option.voters ?? []
                            let expanded = open.contains(option.id)
                            let pagesCount = max(1, (voters.count + pageSize - 1) / pageSize)
                            let page = min(pages[option.id] ?? 0, pagesCount - 1)
                            let slice = Array(voters.dropFirst(page * pageSize).prefix(pageSize))
                            VStack(alignment: .leading, spacing: 0) {
                                Button {
                                    if option.id == firstId { return }
                                    if expanded { open.remove(option.id) } else { open.insert(option.id) }
                                } label: {
                                    HStack(spacing: 8) {
                                        Text(verbatim: option.text).font(.dorr(14.5, .heavy)).foregroundStyle(ch.ink).frame(maxWidth: .infinity, alignment: .leading)
                                        Text(L("ch_poll_votes_count", voters.count)).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut)
                                        Image(systemName: "chevron.down").font(.system(size: 14, weight: .bold))
                                            .foregroundStyle(option.id == firstId ? ch.mut : ch.ink)
                                            .rotationEffect(.degrees(expanded ? 180 : 0))
                                    }
                                    .padding(.horizontal, 12).padding(.vertical, 12)
                                }
                                .buttonStyle(.plain)
                                if expanded {
                                    VStack(alignment: .leading, spacing: 0) {
                                        ForEach(slice, id: \.key) { person in
                                            HStack(spacing: 10) {
                                                ChAvatar(url: person.avatar, name: person.name, key: person.key, size: 36)
                                                Text(verbatim: person.isMe ? L("ch_you") : person.name ?? "").font(.dorr(14, .semibold)).foregroundStyle(ch.ink)
                                            }
                                            .padding(.vertical, 5)
                                        }
                                        if pagesCount > 1 {
                                            HStack(spacing: 10) {
                                                Button { pages[option.id] = page - 1 } label: {
                                                    Image(systemName: "chevron.left").font(.system(size: 14, weight: .bold)).foregroundStyle(page > 0 ? ch.ink : ch.mut)
                                                }
                                                .buttonStyle(.plain).disabled(page == 0)
                                                Text(L("ch_pdf_page", page + 1, pagesCount)).font(.dorr(12, .bold)).foregroundStyle(ch.mut)
                                                Button { pages[option.id] = page + 1 } label: {
                                                    Image(systemName: "chevron.right").font(.system(size: 14, weight: .bold)).foregroundStyle(page < pagesCount - 1 ? ch.ink : ch.mut)
                                                }
                                                .buttonStyle(.plain).disabled(page >= pagesCount - 1)
                                            }
                                            .frame(maxWidth: .infinity)
                                            .padding(.top, 4)
                                            .environment(\.layoutDirection, .leftToRight)
                                        }
                                    }
                                    .padding(.horizontal, 12).padding(.bottom, 10)
                                }
                            }
                            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                            .chStagger(i)
                        }
                    }
                }
                .frame(maxHeight: 460)
            } else {
                ProgressView().tint(ch.red).frame(maxWidth: .infinity).frame(height: 120)
            }
        }
        .task {
            let loaded = (try? await ChatApi.votes(message.id).data) ?? []
            rows = loaded
            if let id = loaded.sorted(by: { ($0.voters?.count ?? 0) > ($1.voters?.count ?? 0) }).first?.id {
                open.insert(id)
            }
        }
    }
}

// MARK: - DORR AI

/// The conversation's AI menu and the sheets it opens (androidApp ConversationPage: aiMenu, summary, ask, commitments,
/// capsule, memory, the assistant / important / dates / files panels, and the tools on one message).
private struct ConversationAiSheets: ViewModifier {
    @ObservedObject var host: ChatHost
    @ObservedObject var state: ConversationState
    @ObservedObject private var ai = ChatAi.shared
    @Binding var aiMenu: Bool
    @Binding var summaryOpen: Bool?
    @Binding var commitmentsOpen: Bool
    @Binding var capsuleOpen: Bool
    @Binding var memoryOpen: Bool
    @Binding var aiPanel: String?
    let onJump: (String) -> Void

    func body(content: Content) -> some View {
        content
            .dorrDialog(isPresented: $aiMenu, bottom: true) {
                ChoiceSheet(title: L("ch_ai_menu"), subtitle: L("ch_ai_privacy_note"), options: menu, fixedHeight: UIScreen.main.bounds.height * 0.58) { aiMenu = false }
            }
            .dorrDialog(isPresented: Binding(get: { summaryOpen != nil }, set: { if !$0 { summaryOpen = nil } }), bottom: true) {
                if let unread = summaryOpen { AiSummarySheet(conversationId: state.id, unreadOnly: unread) }
            }
            .dorrDialog(isPresented: Binding(get: { state.askAbout != nil }, set: { if !$0 { state.askAbout = nil } }), bottom: true) {
                if let ids = state.askAbout { AiAskSheet(conversationId: state.id, messageIds: ids, onDismiss: { state.askAbout = nil }, onJump: onJump) }
            }
            .dorrDialog(isPresented: $commitmentsOpen, bottom: true) {
                AiCommitmentsSheet(host: host, conversationId: state.id, onDismiss: { commitmentsOpen = false }, onJump: onJump)
            }
            .dorrDialog(isPresented: $capsuleOpen, bottom: true) {
                ChatCapsuleSheet(host: host, conversationId: state.id) { capsuleOpen = false }
            }
            .fullScreenCover(isPresented: $memoryOpen) { AiMemoryScreen(onExit: { memoryOpen = false }, settingsHeader: true) }
            .dorrDialog(isPresented: Binding(get: { aiPanel != nil }, set: { if !$0 { aiPanel = nil } }), bottom: true) {
                let close = { aiPanel = nil }
                switch aiPanel {
                case "assistant": AiAssistantSheet(conversationId: state.id, messageIds: nil, onDismiss: close, onJump: onJump) { state.composerInsert = $0 }
                case "important": AiImportantSheet(conversationId: state.id, onDismiss: close) { _, m in onJump(m) }
                case "dates": AiDatesSheet(host: host, conversationId: state.id, onDismiss: close, onJump: onJump)
                case "files": AiRelatedFilesSheet(conversationId: state.id, onDismiss: close, onJump: onJump)
                default: EmptyView()
                }
            }
            .dorrDialog(isPresented: Binding(get: { state.aiTool != nil }, set: { if !$0 { state.aiTool = nil } }), bottom: true) {
                if let (kind, m) = state.aiTool {
                    let close = { state.aiTool = nil }
                    switch kind {
                    case "understand":
                        AiUnderstandSheet(message: m, onDismiss: close, onReply: { state.composerInsert = $0 }, onRemind: {
                            Task { try? await Task.sleep(nanoseconds: 350_000_000); state.aiTool = ("remind", m) }
                        }, onTasks: {
                            Task { try? await Task.sleep(nanoseconds: 350_000_000); state.aiTool = ("tasks", m) }
                        }, onNote: { state.aiNote([m.id]) })
                    case "simplify": AiSimplifySheet(message: m)
                    case "tasks": AiTasksFromSheet(host: host, message: m, onDismiss: close)
                    case "remind": ScheduleSheet(onDismiss: close) { at in state.remind(m, at: at); state.aiTool = nil }
                    case "edit_image": ChatAiImageSheet(state: state, source: m, onDismiss: close)
                    default: EmptyView()
                    }
                }
            }
    }

    private var menu: [(String, () -> Void)] {
        var out: [(String, () -> Void)] = []
        func later(_ action: @escaping () -> Void) -> () -> Void {
            { Task { try? await Task.sleep(nanoseconds: 350_000_000); action() } }
        }
        if ai.summarize { out.append((L("ch_ai_summary"), later { summaryOpen = false })) }
        if ai.ask { out.append((L("ch_ai_ask"), later { state.askAbout = [] })) }
        if ai.commitments { out.append((L("ch_ai_commitments"), later { commitmentsOpen = true })) }
        if ai.assistant { out.append((L("ct_assistant"), later { aiPanel = "assistant" })) }
        if ai.important { out.append((L("ct_important_here"), later { aiPanel = "important" })) }
        if ai.dates { out.append((L("ct_dates"), later { aiPanel = "dates" })) }
        if ai.relatedFiles { out.append((L("ct_files"), later { aiPanel = "files" })) }
        if ai.capsule { out.append((L("ch_ai_capsule_title"), later { capsuleOpen = true })) }
        if ai.memory { out.append((L("ch_ai_memory"), later { memoryOpen = true })) }
        return out
    }
}
