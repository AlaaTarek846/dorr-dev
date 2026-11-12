import SwiftUI

/// driverApp `ui/DriverNavHost.kt` — splash, then sign-in (phone → code) or the shift: four tabs (home, earnings, wallet,
/// account) on a bottom bar, and the rest opening over them, with a request card over all of it while the driver is online.
/// A 401 anywhere means sign-in again.
@MainActor
struct DriverNavHost: View {
    @EnvironmentObject private var prefs: DriverPrefs
    @EnvironmentObject private var backend: DriverBackend
    @Environment(\.colorScheme) private var scheme
    @ObservedObject private var nav = Nav.shared
    @ObservedObject private var duty = Duty.shared
    @ObservedObject private var push = DriverPush.shared
    @ObservedObject private var prefill = SupportPrefill.shared

    var body: some View {
        let pal = Pal.current
        ZStack(alignment: .bottomTrailing) {
            VStack(spacing: 0) {
                NavigationStack(path: $nav.path) {
                    root
                        .toolbar(.hidden, for: .navigationBar)
                        .navigationDestination(for: Route.self) { route in
                            page(route).toolbar(.hidden, for: .navigationBar)
                        }
                }
                // O-82: the bottom bar stays on every screen; it slides away only on the sign-in and the live trip.
                if showBar {
                    DriverBottomBar(Self.tabs, selected: nav.litTab.rawValue, onSelect: { nav.goTab(DriverTab(rawValue: $0) ?? .home) })
                        .transition(.move(edge: .bottom).combined(with: .opacity))
                }
            }
            .animation(.easeOut(duration: 0.26), value: showBar)
            if backend.isSignedIn {
                // O-36: the requests put aside wait behind this icon (with how many), above every screen.
                OffersInboxButton()
                    .padding(.trailing, 18)
                    .padding(.bottom, 96)
                OfferOverlay(onAccepted: { nav.push(.trip) })
            }
            AppToastHost()
        }
        .background(pal.bg.ignoresSafeArea())
        .environment(\.pal, pal)
        .environment(\.layoutDirection, prefs.isRtl ? .rightToLeft : .leftToRight)
        .environment(\.locale, Locale(identifier: DriverPrefs.locale))
        // A language change rebuilds every screen (Android recreates the activity).
        .id("\(prefs.language)-\(prefs.fontScale)")
        .preferredColorScheme(prefs.colorScheme)
        .onAppear { prefs.systemDark = scheme == .dark }
        .onChange(of: scheme) { prefs.systemDark = $0 == .dark }
        .onChange(of: backend.signedOut) { ticks in
            if ticks > 0 { nav.reset(to: .login) }
        }
        // Accepted from the notification while the app was elsewhere: open the ride. (On the splash, Home finds the running
        // ride itself.)
        .onChange(of: duty.rideChanged) { tick in
            if tick > 0 && backend.isSignedIn && nav.path.last != .trip && nav.stage != .splash { nav.push(.trip) }
        }
        // D-214, D-298: «not me» on a wallet transaction opens a support ticket about it.
        .onChange(of: prefill.open?.text) { _ in
            if prefill.open != nil && nav.path.last != .support { nav.push(.support) }
        }
        // A tapped push opens what it's about (after the splash has decided where to start).
        .onChange(of: push.tapped?.event) { _ in openTapped() }
        .onChange(of: nav.stage) { _ in openTapped() }
    }

    private static var tabs: [NavTab] {
        [
            NavTab(route: DriverTab.home.rawValue, label: "nav_home", outlined: "house", filled: "house.fill"),
            NavTab(route: DriverTab.earnings.rawValue, label: "nav_earnings", outlined: "banknote", filled: "banknote.fill"),
            NavTab(route: DriverTab.wallet.rawValue, label: "nav_wallet", outlined: "wallet.pass", filled: "wallet.pass.fill"),
            NavTab(route: DriverTab.account.rawValue, label: "nav_account", outlined: "person", filled: "person.fill"),
        ]
    }

    private var showBar: Bool {
        guard nav.stage == .tabs || !nav.path.isEmpty else { return false }
        if nav.stage == .splash { return false }
        if let top = nav.path.last { return !top.hidesBar }
        return nav.stage == .tabs
    }

    @ViewBuilder
    private var root: some View {
        switch nav.stage {
        case .splash:
            SplashScreen(onFinished: { nav.reset(to: backend.isSignedIn ? .tabs : .login) })
        case .login:
            LoginScreen(onCodeSent: { dial, phone, masked, cooldown in
                nav.push(.otp(dial: dial, phone: phone, masked: masked, cooldown: cooldown))
            })
        case .tabs:
            tabPage
        }
    }

    @ViewBuilder
    private var tabPage: some View {
        switch nav.tab {
        case .home:
            ZStack {
                TermsGate()
                HomeScreen(
                    onOpenSignup: { nav.push(.signup) },
                    onOpenRide: { if nav.path.last != .trip { nav.push(.trip) } },
                    onOpenEarnings: { nav.goTab(.earnings) },
                    onOpenAirport: { nav.push(.airport) },
                    onOpenNotifications: { nav.push(.notifications) }
                )
            }
        case .earnings:
            EarningsScreen(onBack: nil)
        case .wallet:
            // O-35: the DORR app's wallet, the same in every detail (PIN, recovery, settings, top-up) — plus withdrawing.
            // O-46: the wallet (copied from the DORR app, where its host keeps it under the status bar) starts below it here too.
            WalletScreen(onExit: { nav.goTab(.home) })
                .background(Wa.Bg.ignoresSafeArea())
        case .account:
            AccountScreen(
                onOpenSignup: { nav.push(.signup) },
                onOpenPreferences: { nav.push(.preferences) },
                onOpenScheduled: { nav.push(.scheduled) },
                onOpenAirport: { nav.push(.airport) },
                onOpenCases: { nav.push(.cases) },
                onOpenSafety: { nav.push(.safety) },
                onOpenNotifications: { nav.push(.notifications) },
                onSignedOut: { nav.reset(to: .login) },
                onOpenBus: { nav.push(.bus) },
                onOpenMarine: { nav.push(.marine) },
                onOpenHire: { nav.push(.hire) },
                onOpenLessons: { nav.push(.lessons) },
                onOpenWithMe: { nav.push(.withme) },
                onOpenRank: { nav.push(.rank) },
                onOpenSupport: { nav.push(.support) },
                onOpenSettings: { nav.push(.settings) },
                onOpenHistory: { nav.push(.history) },
                onOpenDriverAi: { nav.push(.driverAi) }
            )
        }
    }

    @ViewBuilder
    private func page(_ route: Route) -> some View {
        let back = { nav.pop() }
        Group {
            switch route {
            case let .otp(dial, phone, masked, cooldown):
                OtpScreen(dialCode: dial, phone: phone, maskedPhone: masked, initialCooldown: cooldown, onBack: back,
                          onSignedIn: { nav.reset(to: .tabs) })
            case .signup: SignupScreen(onBack: back)
            case .trip: TripScreen(onDone: { nav.popToHome() })
            case let .tripRide(uuid): TripScreen(onDone: back, rideUuid: uuid)
            case .rank: RankScreen(onBack: back)
            case .history: RideHistoryScreen(onBack: back)
            case .settings:
                SettingsScreen(onBack: back, onClosed: {
                    backend.clear()
                    nav.reset(to: .login)
                })
            case .support: SupportScreen(onBack: back, onLostItems: { nav.push(.lost) })
            case .lost: LostItemsScreen(onBack: back)
            case .withme: WithMeScreen(onBack: back, onOpenRide: { nav.push(.tripRide($0)) })
            case .hire: HireJobsScreen(onBack: back)
            case .lessons: TrainerScreen(onBack: back)
            case .marine: MarineListingsScreen(onBack: back, onOpen: { nav.push(.marineListing($0)) })
            case let .marineListing(uuid): MarineListingScreen(uuid: uuid, onBack: back)
            case .bus: BusTripsScreen(onBack: back, onOpen: { nav.push(.busTrip($0)) })
            case let .busTrip(uuid): BusTripDetailScreen(uuid: uuid, onBack: back)
            case .notifications:
                NotificationsScreen(onBack: back, onOpen: { n in
                    // A tap goes where the notification is about.
                    openFor(n.event ?? "") { key in n.data?[snake: key]?.str }
                })
            case .safety: SafetyCenterScreen(onBack: back)
            case .airport: AirportScreen(onBack: back)
            case .preferences: PreferencesScreen(onBack: back)
            // Block 06: DORR Driver AI and where the work is.
            case .driverAi: DriverAiScreen(onBack: back)
            case .cases: CasesScreen(onBack: back)
            case .scheduled:
                ScheduledScreen(onBack: back, onStarted: {
                    nav.path.removeAll()
                    nav.push(.trip)
                })
            }
        }
    }

    /// A tapped notification (the list or a push) opens what it's about — the trip / listing itself when it names one.
    private func openFor(_ event: String, _ data: (String) -> String?) {
        func id(_ key: String) -> String? { data(key).flatMap { $0.isEmpty || $0 == "null" ? nil : $0 } }
        if event.hasPrefix("wallet") || event.contains("earning") { nav.goTab(.wallet) }
        else if event.contains("safety") || event.contains("contact") { nav.push(.safety) }
        else if event.hasPrefix("ride.scheduled") { nav.push(.scheduled) }
        else if event.hasPrefix("bus.") { if let t = id("trip_uuid") { nav.push(.busTrip(t)) } else { nav.push(.bus) } }
        else if event.hasPrefix("marine.") { if let l = id("listing_uuid") { nav.push(.marineListing(l)) } else { nav.push(.marine) } }
        else if event.hasPrefix("hire.") { nav.push(.hire) }
        else if event.hasPrefix("lesson.") { nav.push(.lessons) }
        else if event == "driver.rank" || event == "driver.note" || event == "driver.appeal" { nav.push(.rank) }
        else if event.hasPrefix("support.") { nav.push(.support) }
        else if event.hasPrefix("lost.") { nav.push(.lost) }
        else if event.hasPrefix("driver.") { nav.push(.signup) }
        else if event.hasPrefix("ride.zone_") { nav.push(.airport) }
        else if event.hasPrefix("ride.case_") { nav.push(.cases) }
        else if event.hasPrefix("ride.") && duty.onTrip { nav.push(.trip) }
    }

    private func openTapped() {
        guard let tapped = push.tapped, nav.stage == .tabs, backend.isSignedIn else { return }
        push.tapped = nil
        openFor(tapped.event) { tapped.data[$0] }
    }
}
