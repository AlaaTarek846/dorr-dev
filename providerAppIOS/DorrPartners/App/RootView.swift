import SwiftUI

/// providerApp `MainActivity` + `ProviderNavGraph` — the language, the theme, the routes and the offline overlay.
@MainActor
struct RootView: View {
    @EnvironmentObject private var appearance: AppearanceState
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var language = AppLanguage.shared
    @ObservedObject private var session = Session.shared
    @ObservedObject private var nav = Nav.shared
    @ObservedObject private var shell = ShellState.shared
    @ObservedObject private var realtime = Realtime.shared
    @ObservedObject private var keyboard = KeyboardState.shared

    var body: some View {
        ZStack {
            NavigationStack(path: $nav.path) {
                stage
                    .toolbar(.hidden, for: .navigationBar)
                    .navigationDestination(for: Route.self) { route in
                        page(route).toolbar(.hidden, for: .navigationBar)
                    }
            }
            if !network.isOnline {
                NoInternetScreen().transition(.opacity)
            }
            AppToastHost()
        }
        .animation(.easeInOut(duration: 0.3), value: network.isOnline)
        .safeAreaInset(edge: .bottom, spacing: 0) {
            if showBar {
                let tabs = visibleTabs(session.membership)
                let current = tabs.firstIndex { $0.key == shownTab(shell.tab, session.membership) } ?? 0
                BottomBar(tabs: tabs.map { $0.tab }, current: current) { index in
                    shell.tab = tabs[index].key
                    nav.popToHome()
                }
            }
        }
        .waThemed()
        .dorrThemed()
        .environment(\.layoutDirection, language.isRtl ? .rightToLeft : .leftToRight)
        .environment(\.locale, AppLocale.locale)
        .id("\(language.code)-\(language.stringsRevision)-\(appearance.fontRevision)")
        .preferredColorScheme(appearance.colorSchemeOverride)
        // A session that ended (the server answered 401, or the person signed out) goes back to sign-in by itself.
        .onChange(of: session.signedIn) { signedIn in
            if signedIn {
                Realtime.shared.start()
                Task { await loadAppearance() }
            } else {
                Realtime.shared.stop()
                // No position is sent for a session that ended.
                MoveTracking.stop()
                appearance.clear()
                shell.tab = tabHome
                if nav.stage != .splash && nav.stage != .login { nav.reset(to: .login) }
            }
        }
        // A notification tapped in the phone's tray: the screen of what it is about, or the notifications page — once signed
        // in and past the workspace gate.
        .onChange(of: realtime.opened) { _ in openTapped() }
        .onChange(of: nav.stage) { _ in openTapped() }
        .task {
            if session.signedIn {
                Realtime.shared.start()
                await loadAppearance()
            }
            await appearance.loadFont()
            await language.syncWithServer()
        }
        .onChange(of: appearance.snapshot?.font?.id) { _ in Task { await appearance.loadFont() } }
    }

    /// Every page over the tabs sits in the same frame as the tabs (the bottom bar); the header is on the Home tab only.
    private var showBar: Bool {
        guard nav.stage == .home, !shell.immersive, !keyboard.visible else { return false }
        return nav.path.last?.wantsBar ?? true
    }

    @ViewBuilder
    private var stage: some View {
        switch nav.stage {
        case .splash:
            SplashScreen(onFinished: { nav.reset(to: session.signedIn ? .workspace : .login) })
        case .login:
            LoginScreen(onOtpSent: { dial, phone, cooldown in nav.push(.otp(dial: dial, phone: phone, cooldown: cooldown)) })
        case .workspace:
            WorkspaceGateScreen(
                onHome: { nav.reset(to: .home) },
                onInvitations: { nav.reset(to: .invitations) },
                onOnboarding: { nav.reset(to: .onboarding) }
            )
        case .onboarding:
            onboarding
        case .invitations:
            invitations
        case .home:
            VStack(spacing: 0) {
                if shownTab(shell.tab, session.membership) == tabHome && !shell.immersive {
                    AppHeader(onOpenWallet: { shell.tab = tabWallet }, onOpenNotifications: { nav.pushSingleTop(.notifications) },
                              onOpenInvitations: { nav.pushSingleTop(.invitations) })
                }
                MainScreen(actions: MainActions.navigating(nav)).frame(maxHeight: .infinity)
            }
            .background(PinkBackdrop())
        }
    }

    @ViewBuilder
    private func page(_ route: Route) -> some View {
        let back = { nav.pop() }
        Group {
            switch route {
            case let .otp(dial, phone, cooldown):
                OtpScreen(dialCode: dial, phoneNumber: phone, cooldownSeconds: cooldown, onBack: back, onVerified: { nav.reset(to: .workspace) })
            case .onboarding: onboarding
            case .invitations: invitations
            case .documents: DocumentsScreen(onBack: back)
            // C-03
            case .branches: BranchesScreen(onBack: back)
            case .team: TeamScreen(onBack: back, onOpenDriverDocs: { nav.push(.driverDocs($0)) })
            case .vehicles: VehiclesScreen(onBack: back, onOpenVehicle: { nav.push(.vehicle($0)) })
            case let .vehicle(uuid): VehicleScreen(uuid: uuid, onBack: back)
            case let .driverDocs(uuid): DocumentsScreen(onBack: back, driverUuid: uuid)
            // C-04
            case .requests: RequestsScreen(onBack: back, onOpenRequest: { nav.push(.request($0)) })
            case let .request(uuid): RequestScreen(uuid: uuid, canOffer: session.membership.can("offers.send"), onBack: back)
            case let .offers(approval): OffersScreen(startOnApproval: approval, onBack: back, onOpenRequest: { nav.push(.request($0)) })
            case let .jobs(unassigned): JobsScreen(startUnassigned: unassigned, onBack: back, onOpenJob: { nav.push(.job($0)) })
            case let .job(uuid): JobScreen(uuid: uuid, onBack: back)
            case .myJobs: MyJobsScreen(onBack: back, onOpenJob: { nav.push(.job($0)) })
            case .tickets: TicketsScreen(onBack: back, onOpenTicket: { nav.push(.ticket($0)) })
            case let .ticket(uuid): TicketScreen(uuid: uuid, onBack: back, onOpenJob: { nav.push(.job($0)) })
            case .notifications: NotificationsScreen(onBack: back, onOpenRoute: { openRoute($0) })
            // Not pages: `openRoute` turns them into a tab.
            case .wallet, .home: EmptyView()
            }
        }
        .background(PinkBackdrop())
    }

    /// The onboarding, as the first page after the gate or over the tabs / the invitations.
    private var onboarding: some View {
        OnboardingScreen(
            onExit: backOrLeave,
            // The new establishment is chosen already: the home, with its papers page on top.
            onCreated: {
                nav.reset(to: .home)
                nav.push(.documents)
            }
        )
    }

    private var invitations: some View {
        InvitationsScreen(
            onBack: backOrLeave,
            onJoined: { nav.reset(to: .home) },
            onCreateOwn: { nav.pushSingleTop(.onboarding) },
            // Nothing left to answer and no seat anywhere: the gate decides again (the onboarding).
            onNothingLeft: { nav.reset(to: .workspace) }
        )
    }

    /// Back from a page that may be the only one on the stack (the onboarding or the invitations opened from the gate): on
    /// Android the system back then leaves the app; iOS has no such thing, so the page simply stays.
    private func backOrLeave() {
        if !nav.path.isEmpty { nav.pop() }
    }

    /// Opens `route` from anywhere: the wallet and the home are tabs (the home frame on the right tab), the rest pages over the tabs.
    private func openRoute(_ route: Route) {
        switch route {
        case .home:
            shell.tab = tabHome
            if nav.stage == .home { nav.popToHome() }
        case .wallet:
            shell.tab = tabWallet
            if nav.stage == .home { nav.popToHome() }
        default:
            nav.pushSingleTop(route)
        }
    }

    private func openTapped() {
        let ready = (nav.stage == .home || nav.stage == .invitations) && nav.path.last != .onboarding
        guard realtime.opened != nil, session.signedIn, ready else { return }
        let target = realtime.openedRoute
        realtime.consumeOpened()
        openRoute(target ?? .notifications)
    }

    /// The account's own appearance is read once when the session starts (cold start or sign-in) and dropped at sign-out;
    /// the platform's stays for the sign-in pages.
    private func loadAppearance() async {
        if let dto = await AppearanceApi.show().value, Session.shared.signedIn { appearance.apply(dto) }
    }
}
