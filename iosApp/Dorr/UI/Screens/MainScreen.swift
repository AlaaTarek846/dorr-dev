import SwiftUI

/// androidApp `MainScreen.kt` — 4 tabs (Home, Services, History, Account) with the AI button in the middle.
struct MainScreen: View {
    @EnvironmentObject private var router: AppRouter
    @Environment(\.palette) private var palette
    @Environment(\.wa) private var wa
    @EnvironmentObject private var appearance: AppearanceState
    @State private var showAi = false
    @State private var showChat = false
    @Environment(\.scenePhase) private var scenePhase
    @State private var showNotifications = false
    @State private var showPortals = false
    @State private var showMoments = false
    // Freelance work (FL-201): my projects and the project form, over everything.
    @State private var showFreelance = false
    // Passenger rides open their own map screen, not the generic service page.
    @State private var showRides = false
    #if DEBUG
    @State private var showMapPreview = false
    #endif
    @State private var showCalendar = false
    @ObservedObject private var eventsLink = EventsLink.shared
    @ObservedObject private var sportsLink = SportsLink.shared
    @ObservedObject private var aiGate = AiPlanGate.shared
    @State private var serviceDetail: (service: ServiceDto, color: Color)?

    private let tabs: [(title: String, icon: String, selectedIcon: String)] = [
        ("tab_home", "house", "house.fill"),
        ("tab_services", "square.grid.2x2", "square.grid.2x2.fill"),
        ("tab_history", "doc.text", "doc.text.fill"),
        ("tab_account", "person", "person.fill"),
    ]

    var body: some View {
        ZStack {
            VStack(spacing: 0) {
                ZStack {
                    switch router.mainTab {
                    case 0: HomeScreen(onOpenServices: { router.mainTab = 1 }, onOpenAccount: { router.mainTab = 3 }, onOpenWallet: openWallet,
                                       onOpenChat: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42)) { showChat = true } },
                                       onOpenNotifications: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { showNotifications = true } },
                                       onOpenService: { openServiceDetail($0, servicePalette[0]) },
                                       onOpenAi: { showAi = true },
                                       onOpenPortals: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42)) { showPortals = true } },
                                       onOpenMoments: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42)) { showMoments = true } },
                                       onOpenCalendar: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42)) { showCalendar = true } })
                    case 1: ServicesScreen(onBack: { router.mainTab = 0 }, onOpenService: openServiceDetail, onOpenAi: { showAi = true })
                    case 3: ProfileScreen(onOpenWallet: openWallet)
                    default: PlaceholderScreen()
                    }
                    // The wallet sits above the tab content and below the tab bar, so the bar stays visible.
                    if router.walletOpen {
                        WalletScreen(onExit: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { router.walletOpen = false } })
                            .transition(.move(edge: .bottom).combined(with: .opacity))
                            .zIndex(1)
                    }
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .animation(.easeInOut(duration: 0.25), value: router.mainTab)

                if !router.supportChatOpen {
                    tabBar.transition(.move(edge: .bottom).combined(with: .opacity))
                }
            }
            .animation(.easeInOut(duration: 0.25), value: router.supportChatOpen)
            .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42), value: eventsLink.open)
            .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42), value: sportsLink.open)
            .background((wa.night ? wa.bg : appearance.color("background", .white, dark: false)).ignoresSafeArea())

            // A service's page and the notifications cover the whole shell, tab bar included (Android draws the
            // detail over the Scaffold and pushes notifications as their own route).
            if let detail = serviceDetail {
                ServiceDetailScreen(service: detail.service, accentColor: detail.color,
                                    onBack: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { serviceDetail = nil } },
                                    onOpenChild: { child in serviceDetail = (child.asService, detail.color) })
                    .id(detail.service.id)
                    .transition(.dorrSlide)
                    .zIndex(1)
            }
            if showNotifications {
                NotificationsScreen(onBack: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { showNotifications = false } })
                    .transition(.dorrSlide)
                    .zIndex(1)
            }

            // Merchant portals, opened from the home page's circles.
            if showPortals {
                PortalsScreen(onExit: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { showPortals = false } })
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .zIndex(1)
            }

            // DORR Calendar & Today.
            if showCalendar {
                CalendarScreen(onExit: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { showCalendar = false } },
                               onOpenMoments: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42)) { showMoments = true } })
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .zIndex(1)
            }

            // DORR Discover, opened from anywhere through EventsLink.
            if eventsLink.open {
                EventsScreen(onExit: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { eventsLink.close() } })
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .zIndex(1)
            }

            // DORR Sports, opened from Home, the calendar or a goal alert through SportsLink.
            if sportsLink.open {
                SportsScreen(onExit: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { sportsLink.close() } })
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .zIndex(1)
            }

            // DORR Rides (passenger): the map, booking and the ride.
            if showRides {
                RidesHost(onExit: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { showRides = false } })
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .zIndex(1)
            }

            // Freelance work: my projects and the project form (FL-201 / FL-202).
            if showFreelance {
                FreelanceHost(onExit: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { showFreelance = false } })
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .zIndex(1)
            }

            // DORR Moments: occasions, my own dates, preferences.
            if showMoments {
                MomentsScreen(onExit: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { showMoments = false } })
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .zIndex(1)
            }

            // The chat (androidApp ChatScreen), opened from the home page's chat button or a chat notification.
            if showChat {
                ChatScreen(onExit: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { showChat = false } },
                           openWalletQr: { _ in withAnimation { showChat = false; router.walletOpen = true } })
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .zIndex(1)
            }

            // DORR AI, opened from the tab bar's AI button — over the tabs like the other services.
            if showAi {
                AiChatHost(onExit: { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { showAi = false } })
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .zIndex(1)
            }

            #if DEBUG
            if showMapPreview { MapPreview(onBack: { showMapPreview = false }).zIndex(4) }
            #endif

            // The one payment screen and the "top up what's missing" page, opened from anywhere.
            CheckoutOverlay().zIndex(2)
            TopupOverlay().zIndex(3)
            // A goal for my team, a win, a quiet banner — over any screen (androidApp MainScreen).
            SportsCelebrationHost().zIndex(4)
            // Home's public stories: the viewer, the composer and the «add» sheet, over the main screen.
            HomeStoriesLayer().zIndex(5)
            // The call screen, over everything (its own window): a call can ring on any screen of the app.
            CallWindowInstaller()
        }
        // An AI tool refused for want of a DORR AI plan (chat, images, Discover, rides…): «Subscribe» opens the
        // DORR AI subscription page.
        .dorrDialog(isPresented: Binding(get: { aiGate.prompt != nil }, set: { if !$0 { aiGate.dismiss() } })) {
            if let text = aiGate.prompt {
                AiPlanPromptDialog(text: text) {
                    router.walletOpen = false
                    withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42)) { showAi = true }
                }
            }
        }
        .task { await refreshSession() }
        // The chat's one real-time connection and «online», and the notification permission (androidApp MainScreen).
        .task {
            ChatRealtime.shared.start()
            ChatRealtime.shared.onForeground()
            ChatPush.shared.requestPermission()
            // Messages sent offline go now; live locations I'm sharing pick up again.
            await ChatOutbox.shared.flush()
            await LiveLocationSharing.shared.resume()
        }
        .onChange(of: NetworkMonitor.shared.reconnectTick) { _ in Task { await ChatOutbox.shared.flush() } }
        // After a cold start the saved user can still be filling in from the server: connect again once it has (start is idempotent).
        .onReceive(AuthSession.shared.$sessionVersion.dropFirst()) { _ in ChatRealtime.shared.start() }
        // A tapped chat notification opens the chat (which opens the conversation itself).
        .onReceive(ChatPush.shared.$deepLink) { link in
            switch link {
            case .conversation, .join, .callLink, .chatLink, .tasks: withAnimation { showChat = true }
            // A ringing call's notification tapped: the ringing page, if it still rings.
            case .call(let id, _):
                CallController.shared.loadIncoming(id)
                ChatPush.shared.consumeDeepLink()
            case .freelance(let page):
                router.walletOpen = false
                showChat = false
                FreelanceLink.shared.show(page)
                withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42)) { showFreelance = true }
                ChatPush.shared.consumeDeepLink()
            default: break
            }
        }
        .onChange(of: scenePhase) { phase in
            if phase == .active { ChatRealtime.shared.onForeground() } else if phase == .background { ChatRealtime.shared.onBackground() }
        }
        #if DEBUG
        // -DorrMainRoute services|notifications|detail (with -DorrFixtures YES): open that page for screenshots.
        .task {
            switch UserDefaults.standard.string(forKey: "DorrMainRoute") {
            case "services": router.mainTab = 1
            case "notifications": showNotifications = true
            case "portals": showPortals = true
            case "moments": showMoments = true
            case "calendar": showCalendar = true
            case "events": eventsLink.show()
            case "event": eventsLink.show("d1")
            case "sports": sportsLink.show()
            case "freelance": showFreelance = true
            case "rides": showRides = true
            case "ai": showAi = true
            case "chat": showChat = true
            case "map": showMapPreview = true
            case "match": sportsLink.show("m1")
            case "goal":
                sportsLink.show()
                try? await Task.sleep(nanoseconds: 1_500_000_000)
                if let m = try? await SportsApi.match("m1", timezone: "UTC").data { SportsLive.shared.latest["m1"] = m }
                SportsLive.shared.celebration = SportsAlert(matchId: "m1", type: "goal", hidden: false, homeScore: 2, awayScore: 1, side: "home",
                                                            scorer: "ميتروفيتش", minute: 63, celebrate: "festive")
            case "detail":
                try? await Task.sleep(nanoseconds: 800_000_000)
                if let first = try? await ServiceApi.list(home: nil).data?.first { serviceDetail = (first, servicePalette[0]) }
            default: break
            }
        }
        #endif
    }

    /// The cached user can miss fields filled in later on the server (an avatar added from another device,
    /// the country set after the first login), and the profile screens read the cache — so auth/me and the
    /// appearance are refreshed once here, in the shell, at launch.
    private func refreshSession() async {
        guard let token = AuthSession.shared.token, !token.isEmpty else { return }
        if let me = try? await MobileAuthApi.me().data, AuthSession.shared.token == token { AuthSession.shared.user = me }
        if let dto = try? await AppearanceApi.show().data, AuthSession.shared.token == token { appearance.apply(dto) }
    }

    private func openServiceDetail(_ service: ServiceDto, _ color: Color) {
        if service.moduleName == "passenger_ride" {
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42)) { showRides = true }
            return
        }
        if service.moduleName == "freelance" {
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42)) { showFreelance = true }
            return
        }
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { serviceDetail = (service, color) }
    }

    private func openWallet() {
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42)) { router.walletOpen = true }
    }

    /// androidApp `DorrBottomNavigationBar` — a flat bar: Home, Services, the AI assistant (same size and level
    /// as the rest), History, Account; the page colour, a hairline on top, a soft shadow.
    private var tabBar: some View {
        let barColor = wa.night ? wa.bg : AppearanceState.shared.color("background", .white, dark: false)
        return HStack(spacing: 0) {
            tabItem(0)
            tabItem(1)
            // The AI assistant opens over the tabs, so it is never the "current" tab.
            TabButton(title: L("tab_ai"), icon: "sparkles", activeIcon: "sparkles", selected: false) {
                router.walletOpen = false
                showAi = true
            }
            tabItem(2)
            tabItem(3)
        }
        .padding(.horizontal, 4)
        .frame(height: 64)
        .background {
            barColor
                .overlay(alignment: .top) { (wa.night ? Color.white.opacity(0.10) : Color.black.opacity(0.07)).frame(height: 1) }
                .shadow(color: .black.opacity(0.10), radius: 16, y: 0)
                .ignoresSafeArea(edges: .bottom)
        }
    }

    private func tabItem(_ index: Int) -> some View {
        let tab = tabs[index]
        // No tab is "current" while the wallet is open over the tabs.
        return TabButton(title: L(tab.title), icon: tab.icon, activeIcon: tab.selectedIcon, selected: router.mainTab == index && !router.walletOpen) {
            router.mainTab = index
            withAnimation(.easeOut(duration: 0.3)) { router.walletOpen = false }
        }
    }
}

/// androidApp `TabItem` — a 23pt icon over an 11pt label, the brand colour when selected.
private struct TabButton: View {
    @Environment(\.wa) private var wa
    let title: String
    let icon: String
    let activeIcon: String
    let selected: Bool
    let action: () -> Void

    var body: some View {
        let tint = selected ? wa.red : Color(hex: 0x8E9BAE)
        Button(action: action) {
            VStack(spacing: 4) {
                Image(systemName: selected ? activeIcon : icon).font(.system(size: 20, weight: .medium)).frame(height: 23)
                Text(title).font(.dorr(11, selected ? .bold : .medium)).lineLimit(1)
            }
            .foregroundStyle(tint)
            .frame(maxWidth: .infinity, minHeight: 58)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(title)
    }
}

/// androidApp `PlaceholderScreen.kt` — stand-in for tabs not built yet (History on Android too).
struct PlaceholderScreen: View {
    @Environment(\.wa) private var wa

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                Image(systemName: "hammer").font(.system(size: 34)).foregroundStyle(wa.settingsMut)
                Text(L("placeholder_title")).font(.dorr(16, .medium)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary).padding(.top, 12)
                Text(L("placeholder_body")).font(.dorr(12)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).padding(.top, 4)
            }
            .padding(24)
        }
    }
}
