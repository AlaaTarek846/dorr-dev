import SwiftUI

/// freelanceApp `MainActivity` + `FreelanceNavGraph` — the language, the theme, the routes and the offline overlay.
@MainActor
struct RootView: View {
    @EnvironmentObject private var appearance: AppearanceState
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var language = AppLanguage.shared
    @ObservedObject private var session = Session.shared
    @ObservedObject private var nav = Nav.shared
    @ObservedObject private var shell = ShellState.shared
    @ObservedObject private var gate = Gate.shared
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
                BottomBar(tabs: shellTabs, current: shell.tab) { index in
                    shell.tab = index
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
        // A session that ended (the server answered 401, or the freelancer signed out) goes back to sign-in by itself.
        .onChange(of: session.signedIn) { signedIn in
            if signedIn {
                Realtime.shared.start()
                Task { await loadAppearance() }
            } else {
                Realtime.shared.stop()
                appearance.clear()
                shell.tab = 0
                if nav.stage == .home { nav.reset(to: .login) }
            }
        }
        // The terms come first: as soon as the account is known and they are not accepted, from the home.
        .onChange(of: session.me?.terms?.accepted) { _ in showTermsIfNeeded() }
        .onChange(of: nav.path) { _ in showTermsIfNeeded() }
        // A notification tapped in the phone's tray: the screen of what it is about from wherever the app was left; one
        // without such a screen, the notifications page from the home.
        .onChange(of: realtime.opened) { _ in openTapped() }
        .onChange(of: nav.stage) { _ in openTapped() }
        // FL-110: a request that needs a verified account was refused — say which step they are at, once, from any screen.
        .alert(L("gate_title"), isPresented: Binding(get: { gate.step != nil }, set: { if !$0 { gate.dismiss() } })) {
            Button(L("gate_open")) {
                gate.dismiss()
                nav.pushSingleTop(.verification)
            }
            Button(L("common_cancel"), role: .cancel) { gate.dismiss() }
        } message: {
            Text(stepText(gate.step ?? "", nil))
        }
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

    private var showBar: Bool {
        guard nav.stage == .home, !shell.immersive, !keyboard.visible else { return false }
        return nav.path.last?.wantsBar ?? true
    }

    @ViewBuilder
    private var stage: some View {
        switch nav.stage {
        case .splash:
            SplashScreen(onFinished: { nav.reset(to: Welcome.seen ? afterWelcome() : .start) })
        case .start:
            StartScreen(onStart: {
                Welcome.markSeen()
                nav.reset(to: afterWelcome())
            })
        case .login:
            LoginScreen(onOtpSent: { dial, phone, cooldown in nav.push(.otp(dial: dial, phone: phone, cooldown: cooldown)) })
        case .home:
            VStack(spacing: 0) {
                if shell.tab == 0 && !shell.immersive {
                    AppHeader(onOpenWallet: { nav.pushSingleTop(.wallet) }, onOpenNotifications: { nav.pushSingleTop(.notifications) },
                              onOpenInvitations: { nav.pushSingleTop(.invitations) })
                }
                MainScreen(actions: MainActions.navigating(nav)).frame(maxHeight: .infinity)
            }
            .background(PinkBackdrop())
        }
    }

    private func afterWelcome() -> RootStage { session.signedIn ? .home : .login }

    @ViewBuilder
    private func page(_ route: Route) -> some View {
        let back = { nav.pop() }
        Group {
            switch route {
            case let .otp(dial, phone, cooldown):
                OtpScreen(dialCode: dial, phoneNumber: phone, cooldownSeconds: cooldown, onBack: back, onVerified: { nav.reset(to: .home) })
            case .terms:
                // The name is not asked for here: it can be added later from the profile.
                TermsScreen(version: session.me?.terms?.currentVersion ?? "1", onAccepted: back)
            case .profile:
                ProfileScreen(onBack: back, onSaved: { nav.popToHome() })
            case .specialties: SpecialtiesScreen(onBack: back)
            case .skills: SkillsScreen(onBack: back)
            case .experiences: ExperiencesScreen(onBack: back)
            case .availability: AvailabilityScreen(onBack: back)
            case .plans:
                PlansScreen(onBack: back, onOpenWallet: { nav.pushSingleTop(.wallet) }, onOpenInsights: { nav.pushSingleTop(.insights) })
            case .insights: InsightsScreen(onBack: back, onOpenPlans: { nav.pushSingleTop(.plans) })
            case .coach:
                ProfileCoachScreen(onBack: back, onOpen: { action in
                    switch action {
                    case "skills": nav.push(.skills)
                    case "specialties": nav.push(.specialties)
                    case "portfolio": nav.push(.portfolio)
                    case "verification": nav.push(.verification)
                    case "services": nav.push(.services)
                    default: nav.push(.profile)
                    }
                })
            case .skillTests: SkillTestsScreen(onBack: back, onOpenSkills: { nav.push(.skills) })
            case .capacity: CapacityScreen(onBack: back)
            case .portfolio: PortfolioTab(onBack: back, onOpenItem: { nav.push(.portfolioItem($0)) })
            case let .project(uuid):
                ProjectDetailScreen(uuid: uuid, onBack: back, onOffer: { nav.push(.offerForm(uuid)) },
                                    onOpenOffer: { nav.push(.offerDetail(uuid)) }, onAgreement: { nav.push(.agreement(uuid)) })
            case let .offerForm(uuid):
                OfferFormScreen(projectUuid: uuid, onBack: back, onSent: back,
                                onOpenProfile: {
                                    shell.openProfileHub = true
                                    shell.tab = 4
                                    nav.popToHome()
                                },
                                onOpenPlans: { nav.pushSingleTop(.plans) })
            case let .offerEdit(uuid): EditOfferScreen(projectUuid: uuid, onBack: back, onDone: back)
            case let .offerDetail(uuid):
                OfferDetailScreen(projectUuid: uuid, onBack: back, onOpenProject: { nav.push(.project(uuid)) }, onEdit: { nav.push(.offerEdit(uuid)) },
                                  onChat: { nav.push(.chat($0)) }, onAgreement: { nav.push(.agreement(uuid)) })
            case .myOffers: MyOffersScreen(onBack: back, onOpenProject: { nav.push(.offerDetail($0)) })
            case .invitations: InvitationsScreen(onBack: back, onOpenProject: { nav.push(.project($0)) })
            case let .chat(uuid): ChatScreen(conversationUuid: uuid, onBack: back)
            case let .agreement(uuid):
                AgreementScreen(projectUuid: uuid, onBack: back, onDispute: { milestone, dispute in
                    if let dispute { nav.push(.dispute(project: uuid, dispute: dispute)) } else { nav.push(.disputeNew(project: uuid, milestone: milestone)) }
                })
            case let .dispute(project, dispute):
                DisputeScreen(projectUuid: project, milestoneUuid: nil, disputeUuid: dispute, onBack: back, onOpened: { _ in })
            case let .disputeNew(project, milestone):
                DisputeScreen(projectUuid: project, milestoneUuid: milestone, disputeUuid: nil, onBack: back, onOpened: { dispute in
                    nav.pop()
                    nav.push(.dispute(project: project, dispute: dispute))
                })
            case .certificates: CertificatesScreen(onBack: back)
            case .verification: VerificationScreen(onBack: back, onOpenProfile: { nav.push(.profile) })
            case .notifications: NotificationsScreen(onBack: back, onOpenRoute: { nav.push($0) })
            // The wallet draws its own page header (title, back).
            case .wallet: WalletRoute(onBack: back)
            case let .portfolioItem(id): PortfolioFormScreen(itemId: id, onBack: back)
            case .services:
                MyServicesScreen(onBack: back, onOpenService: { nav.push(.service($0)) }, onOpenOrders: { nav.pushSingleTop(.serviceOrders) })
            case let .service(uuid): ServiceEditorScreen(uuid: uuid, onBack: back)
            case .serviceOrders: ServiceOrdersScreen(onBack: back, onOpenOrder: { nav.push(.serviceOrder($0)) })
            case let .serviceOrder(uuid): ServiceOrderScreen(uuid: uuid, onBack: back, onOpenWork: { nav.push(.agreement($0)) })
            case .consultations:
                ConsultationsScreen(onBack: back, onOpenEditor: { nav.pushSingleTop(.consultationEdit($0)) }, onOpenConsultation: { nav.push(.consultation($0)) })
            case let .consultationEdit(uuid): ConsultationEditorScreen(uuid: uuid, onBack: back)
            case let .consultation(uuid): ConsultationScreen(uuid: uuid, onBack: back)
            }
        }
        .background(PinkBackdrop())
    }

    private func showTermsIfNeeded() {
        if session.signedIn && session.me?.terms?.accepted == false && nav.stage == .home && nav.path.isEmpty {
            nav.push(.terms)
        }
    }

    private func openTapped() {
        guard realtime.opened != nil, session.signedIn, nav.stage == .home else { return }
        let target = realtime.openedRoute
        realtime.consumeOpened()
        nav.push(target ?? .notifications)
    }

    /// The account's own appearance is read once when the session starts (cold start or sign-in) and dropped at sign-out;
    /// the platform's stays for the sign-in pages.
    private func loadAppearance() async {
        if let dto = await AppearanceApi.show().value, Session.shared.signedIn { appearance.apply(dto) }
    }
}
