import SwiftUI
import UserNotifications

// androidApp `chat/ChatScreen.kt` — the chat as one visit: a stack of pages that slide over each other, a toast on top,
// the offline bar, and a tapped notification opening its conversation. The chat follows the appearance and its own
// light / dark choice; what's on where I am (ChatFeatures) and the AI tools are read when it opens.

struct ChatScreen: View {
    @StateObject private var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var push = ChatPush.shared
    @ObservedObject private var network = NetworkMonitor.shared
    @ObservedObject private var voice = VoiceRoomController.shared
    @State private var privateSummary: PrivacySummaryDto?
    @ObservedObject private var viewers = ChatViewers.shared
    @State private var explainLocation = false

    init(onExit: @escaping () -> Void, openWalletQr: @escaping (String) -> Void = { _ in }, initialConversation: String? = nil) {
        let h = ChatHost(onExit: onExit, openWalletQr: openWalletQr)
        if let initialConversation { h.push(.conversation(initialConversation)) }
        #if DEBUG
        // -DorrChatConversation c1 (with fixtures): straight into that conversation for screenshots.
        if let debug = UserDefaults.standard.string(forKey: "DorrChatConversation") { h.push(.conversation(debug)) }
        // -DorrChatPage info|newChat|settings|starred (after the conversation, if any).
        switch UserDefaults.standard.string(forKey: "DorrChatPage") {
        case "info": h.push(.info(UserDefaults.standard.string(forKey: "DorrChatConversation") ?? "c1"))
        case "newChat": h.push(.newChat)
        case "settings": h.push(.chatSettings)
        case "privacy": h.push(.privacy)
        case "myQr": h.push(.myQr)
        case "calls": h.push(.calls)
        case "data": h.push(.dataPerformance)
        case "usage": h.push(.usage)
        case "storyText": h.push(.storyComposer())
        case "storyVoice": h.push(.storyComposer(media: storyVoice))
        case "storyPrivacy": h.push(.storyPrivacy)
        case "voiceRoom": h.push(.voiceRoom(conversationId: "c1", title: "فريق دُرر"))
        default: break
        }
        #endif
        _host = StateObject(wrappedValue: h)
    }

    var body: some View {
        ZStack {
            ch.bg.ignoresSafeArea()
            // Room for two (an iPad, landscape — spec 89): the list stays on the side and pages open beside it.
            // Measured from the real space, so Split View and Slide Over follow too.
            GeometryReader { geo in
                let twoPane = geo.size.width >= 700
                Group {
                    if twoPane {
                        HStack(spacing: 0) {
                            ChatPage(host: host, route: .list).frame(width: min(max(geo.size.width * 0.38, 320), 420))
                            Rectangle().fill(ch.line).frame(width: 1)
                            pages(host.detail).frame(maxWidth: .infinity)
                        }
                    } else {
                        pages(host.current)
                    }
                }
                .onAppear { host.twoPane = twoPane }
                .onChange(of: twoPane) { host.twoPane = $0 }
            }
            offlineBanner
            // In a voice room while elsewhere in the chat: it stays with me (spec 91).
            VStack {
                VoiceMiniBar(host: host)
                Spacer()
            }
            .animation(.spring(response: 0.4, dampingFraction: 0.85), value: voice.room?.id)
            StoryViewerOverlay(host: host)
            ChatToastView(host: host)
            // The admins answered my request to join.
            VStack {
                if let d = host.joinDecided {
                    JoinDecisionBanner(decision: d, onOpen: {
                        host.joinDecided = nil
                        host.push(.conversation(d.conversationId))
                    }, onDismiss: { withAnimation { host.joinDecided = nil } })
                    .transition(.move(edge: .top).combined(with: .opacity))
                }
                Spacer()
            }
            .animation(.spring(response: 0.4, dampingFraction: 0.85), value: host.joinDecided)
        }
        // A PDF read inside the chat.
        .fullScreenCover(item: $viewers.pdf) { target in PdfViewerView(target: target) { viewers.pdf = nil } }
        // A group invite link was tapped: preview + «Join» / «Ask to join».
        .dorrDialog(isPresented: Binding(get: { host.joinToken != nil }, set: { if !$0 { host.joinToken = nil } }), bottom: true) {
            if let token = host.joinToken { JoinGroupSheet(host: host, token: token) { host.joinToken = nil } }
        }
        // A call link opened from a message, a code, a QR or a notification: who made it, then «Join».
        .dorrDialog(isPresented: Binding(get: { host.callLinkToken != nil }, set: { if !$0 { host.callLinkToken = nil } }), bottom: true) {
            if let token = host.callLinkToken { CallLinkSheet(host: host, token: token) { host.callLinkToken = nil } }
        }
        .dorrDialog(isPresented: Binding(get: { host.recordingsCall != nil }, set: { if !$0 { host.recordingsCall = nil } }), bottom: true) {
            if let id = host.recordingsCall { CallRecordingsSheet(callId: id) { host.recordingsCall = nil } }
        }
        .chatAppearance()
        .environmentObject(host)
        .task { await privacySummaryOnOpen() }
        .modifier(HideFromRecents())
        .dorrDialog(isPresented: Binding(get: { privateSummary != nil }, set: { if !$0 { privateSummary = nil } })) {
            if let summary = privateSummary { PrivacySummaryDialog(summary: summary) { privateSummary = nil } }
        }
        // What's on where I am (country / city rules) — the city from the phone's approximate location, asked for once
        // after our own explanation.
        .dorrDialog(isPresented: $explainLocation, bottom: true) {
            ChoiceSheet(title: L("cf_location_title"), subtitle: L("cf_location_sub"), options: [
                (L("cf_location_ok"), {
                    ChatFeatures.shared.markAsked()
                    FeatureLocationAsk.shared.ask()
                }),
                (L("cf_location_later"), { ChatFeatures.shared.markAsked() }),
            ], onDismiss: {
                explainLocation = false
                ChatFeatures.shared.markAsked()
            })
        }
        .task {
            await ChatFeatures.shared.refresh()
            if ChatFeatures.shared.shouldAsk { explainLocation = true }
            await ChatAi.shared.load()
            await host.refreshStories()
        }
        .onAppear {
            ChatStore.shared.screenOpen = true
            // The chat is open: the private count starts over (`ChatShield.clearPrivate` on Android).
            LocalIntelligence.privateCount = 0
            UNUserNotificationCenter.current().getDeliveredNotifications { list in
                let ids = list.filter { $0.request.content.threadIdentifier == "dorr.private" }.map(\.request.identifier)
                UNUserNotificationCenter.current().removeDeliveredNotifications(withIdentifiers: ids)
            }
        }
        #if DEBUG
        // -DorrChatPage stories: the viewer on the first person's statuses, for screenshots.
        .onChange(of: host.stories?.recent?.count ?? 0) { n in
            if n > 0, host.storyViewer == nil, UserDefaults.standard.string(forKey: "DorrChatPage") == "stories" { host.openStories(host.stories?.recent ?? [], start: 0) }
        }
        #endif
        // PIN-locked chats ask again after the app has been in the background.
        .onReceive(NotificationCenter.default.publisher(for: UIApplication.didEnterBackgroundNotification)) { _ in ChatPinLock.unlocked.removeAll() }
        .onDisappear {
            ChatStore.shared.screenOpen = false
            ChatStore.shared.immersive = false
        }
        .onChange(of: host.current) { r in ChatStore.shared.immersive = r != .list || host.storyViewer != nil }
        .onChange(of: host.storyViewer != nil) { open in ChatStore.shared.immersive = open || host.current != .list }
        // A notification tapped for a conversation: open it on top of whatever chat page is showing.
        .onReceive(push.$deepLink) { link in
            switch link {
            case .conversation(let id):
                if host.current != .conversation(id) { host.push(.conversation(id)) }
                push.consumeDeepLink()
            case .join(let token):
                host.joinToken = token
                push.consumeDeepLink()
            case .callLink(let token):
                host.callLinkToken = token
                push.consumeDeepLink()
            case .chatLink(let payload):
                host.openChatLink(payload)
                push.consumeDeepLink()
            case .tasks:
                if host.current != .tasks { host.push(.tasks) }
                push.consumeDeepLink()
            default: break
            }
        }
    }

    /// The page on top — or, beside the list on a wide screen, nil until something is opened there.
    @ViewBuilder
    private func pages(_ target: ChRoute?) -> some View {
        ZStack {
            if let target {
                ChatPage(host: host, route: target)
                    .id(target)
                    .transition(.asymmetric(
                        insertion: .move(edge: host.forward ? .trailing : .leading).combined(with: .opacity),
                        removal: .opacity
                    ))
            } else {
                ChatPanePlaceholder()
            }
        }
    }

    /// No connection: a slim bar slides down — everything saved on the phone stays readable.
    private var offlineBanner: some View {
        VStack {
            if network.isOffline {
                HStack(spacing: 8) {
                    Image(systemName: "icloud.slash.fill").font(.system(size: 14))
                    Text(L("ch_offline")).font(.dorr(12.5, .semibold))
                }
                .foregroundStyle(ch.bg)
                .padding(.horizontal, 16).padding(.vertical, 9)
                .background(ch.ink, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .shadow(radius: 8)
                .padding(.top, 8)
                .transition(.move(edge: .top).combined(with: .opacity))
            }
            Spacer()
        }
        .animation(.spring(response: 0.4, dampingFraction: 0.8), value: network.isOffline)
        .allowsHitTesting(false)
    }
}

/// One chat page.
private struct ChatPage: View {
    @ObservedObject var host: ChatHost
    let route: ChRoute

    var body: some View {
        switch route {
        case .list: ChatListPage(host: host)
        case .conversation(let id): ConversationPage(host: host, id: id)
        case .newChat: NewChatPage(host: host)
        case .newGroup(let addTo): NewGroupPage(host: host, addTo: addTo)
        case .myQr: MyQrPage(host: host)
        case .info(let id): ChatInfoPage(host: host, id: id)
        case .groupMembers(let id): GroupMembersPage(host: host, id: id)
        case .media(let id, let tab): ChatMediaPage(host: host, id: id, startTab: tab)
        case .chatSettings: ChatSettingsPage(host: host)
        case .privacy: ChatPrivacyPage(host: host)
        case .blockedContacts: BlockedContactsPage(host: host)
        case .starred: StarredPage(host: host)
        case .myStatus: MyStatusPage(host: host)
        case .calls: CallsPage(host: host)
        case .voiceRoom(let id, let title): VoiceRoomPage(host: host, conversationId: id, title: title)
        case .liveViewer(let token): LiveViewerPage(host: host, token: token)
        case .dataPerformance: DataPerformancePage(host: host)
        case .usage: UsagePage(host: host)
        case .storage(let id, let title): StoragePage(host: host, conversationId: id, title: title)
        case .storyComposer(let media): StoryComposerPage(host: host, media: media)
        case .storyPrivacy: StoryPrivacyPage(host: host)
        case .channels: ChannelsPage(host: host)
        case .communities: CommunitiesPage(host: host)
        case .community(let id): CommunityPage(host: host, id: id)
        case .thread(let cid, let root): ThreadPage(host: host, conversationId: cid, rootId: root)
        case .decisions(let cid, let admin): DecisionsPage(host: host, conversationId: cid, isAdmin: admin)
        case .broadcasts: BroadcastsPage(host: host)
        case .business: BusinessPage(host: host)
        case .tasks: TasksPage(host: host)
        case .important: ImportantPage(host: host)
        case .today: TodayPage(host: host)
        case .catchUp: CatchUpPage(host: host)
        case .chatMoney(let id): ChatMoneyPage(host: host, conversationId: id)
        case .decisionRoom(let id): DecisionRoomPage(host: host, id: id)
        case .privacyCenter: PrivacyCenterPage(host: host)
        case .chatTheme(let id): ChatThemePage(host: host, id: id)
        case .readLater: FollowUpsPage(host: host)
        case .commitments: CommitmentsPage(host: host)
        case .lifeInbox: LifeInboxPage(host: host)
        case .commRules: CommRulesPage(host: host)
        case .contextRules(let key): ContextRulesPage(host: host, key: key)
        case .channelStats(let id): ChannelStatsPage(host: host, conversationId: id)
        case .broadcast(let id): BroadcastPage(host: host, id: id)
        }
    }
}

extension ChatScreen {
    /// A timed privacy mode ended while I was away: what came in meanwhile (spec 113), once.
    fileprivate func privacySummaryOnOpen() async {
        let shield = ChatShield.shared
        let started = shield.privacyStartedAt
        guard started > 0, let mode = try? await ChatApi.privacy().data?.privacyMode, mode.on != true else { return }
        let from = ISO8601DateFormatter().string(from: Date(timeIntervalSince1970: started / 1000))
        privateSummary = try? await ChatApi.privacySummary(from: from).data
        shield.privacyStartedAt = 0
        shield.setSafeView(false)
    }
}

/// Beside the list, before a chat is opened.
private struct ChatPanePlaceholder: View {
    @ObservedObject private var ch = Ch.shared

    var body: some View {
        VStack(spacing: 0) {
            Image(systemName: "bubble.left.and.bubble.right.fill").font(.system(size: 36)).foregroundStyle(ch.red)
                .frame(width: 84, height: 84).background(ch.red.opacity(0.08), in: Circle())
            Text(L("ch_pane_title")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.top, 18)
            Text(L("ch_pane_sub")).font(.dorr(14)).foregroundStyle(ch.mut).multilineTextAlignment(.center).padding(.top, 6)
        }
        .padding(32)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(ch.bg)
    }
}
