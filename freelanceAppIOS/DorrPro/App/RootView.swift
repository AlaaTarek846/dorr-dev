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
    // The bar's tabs and badges follow the home answer and the community switch (`shellTabs`).
    @ObservedObject private var homeHub = HomeHub.shared
    @ObservedObject private var communityHub = CommunityHub.shared

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
        // FL-110: a request that needs a verified account was refused — say which step they are at, once, from any screen.
        .dorrDialog(isPresented: Binding(get: { gate.step != nil }, set: { if !$0 { gate.dismiss() } })) {
            AlertDialog(
                onDismissRequest: { gate.dismiss() },
                title: { Text(L("gate_title")) },
                text: { Text(stepText(gate.step ?? "", nil)) },
                confirmButton: {
                    DialogButton(onClick: {
                        gate.dismiss()
                        nav.pushSingleTop(.verification)
                    }) { Text(L("gate_open")) }
                },
                dismissButton: { DialogButton(onClick: { gate.dismiss() }) { Text(L("common_cancel")) } }
            )
        }
        // A tab tapped over a page goes back to the tabs on that tab.
        .safeAreaInset(edge: .bottom, spacing: 0) {
            if showBar {
                BottomBar(tabs: shellTabs, current: shell.tab) { key in
                    shell.open(key)
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
                shell.tab = Tabs.HOME
                CommunityHub.shared.clear()
                HomeHub.shared.clear()
                if nav.stage == .home { nav.reset(to: .login) }
            }
        }
        // A notification tapped in the phone's tray: the screen of what it is about from wherever the app was left; one
        // without such a screen, the notifications page from the home.
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
                if shell.tab == Tabs.HOME && !shell.immersive {
                    AppHeader(onOpenWallet: { nav.pushSingleTop(.wallet) }, onOpenNotifications: { nav.pushSingleTop(.notifications) },
                              onOpenMe: { shell.open(Tabs.ME) })
                }
                MainScreen(actions: MainActions.navigating(nav, community: communityNav)).frame(maxHeight: .infinity)
            }
            .background(PinkBackdrop())
        }
    }

    private func afterWelcome() -> RootStage { session.signedIn ? .home : .login }

    /// FL-L12: where the community screens lead.
    private var communityNav: CommunityNav {
        CommunityNav(
            openPost: { nav.push(.communityPost($0)) },
            openMember: { nav.push(.communityMember($0)) },
            editPost: { nav.push(.communityCompose(edit: $0)) },
            compose: { nav.pushSingleTop(.communityCompose(kind: $0)) },
            openPeople: { uuid, which in nav.push(.communityPeople(uuid: uuid, which: which)) },
            openVerification: { nav.pushSingleTop(.verification) },
            openPortfolio: { nav.pushSingleTop(.portfolio) }
        )
    }

    /// The store's pages swap in place (the switch at their top): the open one leaves the back stack.
    private func switchStore(_ key: String) {
        let route: Route
        switch key {
        case "services": route = .services
        case "consultations": route = .consultations
        case "special_offers": route = .specialOffers
        default: route = .loyalty
        }
        nav.replaceTop(route)
    }

    @ViewBuilder
    private func page(_ route: Route) -> some View {
        let back = { nav.pop() }
        Group {
            switch route {
            case let .otp(dial, phone, cooldown):
                OtpScreen(dialCode: dial, phoneNumber: phone, cooldownSeconds: cooldown, onBack: back, onVerified: { nav.reset(to: .home) })
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
            case .teams: TeamsScreen(onBack: back, onOpenTeam: { nav.push(.team($0)) })
            case let .team(uuid):
                TeamScreen(
                    uuid: uuid, onBack: back, onOpenProject: { nav.push(.agreement($0)) },
                    onOpenThread: { nav.push(.teamThread(team: uuid, thread: $0)) },
                    onOpenPortfolio: { nav.push(.teamPortfolio(uuid)) },
                    onOpenVerification: { nav.push(.teamVerification(uuid)) },
                    onOpenPlan: { nav.push(.teamPlan(uuid)) }
                )
            case let .teamThread(team, thread): TeamThreadScreen(teamUuid: team, threadUuid: thread, onBack: back)
            case let .teamPortfolio(team):
                PortfolioTab(onBack: back, onOpenItem: { nav.push(.teamPortfolioItem(team: team, id: $0)) }, team: team)
            case let .teamPortfolioItem(team, id): PortfolioFormScreen(itemId: id, onBack: back, team: team)
            case let .teamVerification(team): TeamVerificationScreen(teamUuid: team, onBack: back)
            case let .teamPlan(team): TeamPlanScreen(teamUuid: team, onBack: back, onOpenWallet: { nav.pushSingleTop(.wallet) })
            case .portfolio: PortfolioTab(onBack: back, onOpenItem: { nav.push(.portfolioItem($0)) })
            case let .project(uuid):
                ProjectDetailScreen(uuid: uuid, onBack: back, onOffer: { nav.push(.offerForm(uuid)) },
                                    onOpenOffer: { nav.push(.offerDetail(uuid)) }, onAgreement: { nav.push(.agreement(uuid)) })
            case let .offerForm(uuid):
                OfferFormScreen(projectUuid: uuid, onBack: back, onSent: back,
                                onOpenProfile: {
                                    shell.openProfileHub = true
                                    shell.open(Tabs.ME)
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
                }, onWorkroom: { nav.push(.workroom(project: uuid, tab: "tasks")) })
            // FS-150e: a project's Workroom and one file of it.
            case let .workroom(project, tab):
                WorkroomScreen(project: project, tab: tab, onBack: back, onOpenFile: { nav.push(.workroomFile(project: project, file: $0)) })
            case let .workroomFile(project, file): WorkroomFileScreen(project: project, fileUuid: file, onBack: back)
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
                MyServicesScreen(onBack: back, onOpenService: { nav.push(.service($0)) }, onOpenOrders: { nav.pushSingleTop(.serviceOrders) },
                                 onSwitchStore: switchStore)
            case let .service(uuid): ServiceEditorScreen(uuid: uuid, onBack: back)
            case .serviceOrders: ServiceOrdersScreen(onBack: back, onOpenOrder: { nav.push(.serviceOrder($0)) })
            case let .serviceOrder(uuid): ServiceOrderScreen(uuid: uuid, onBack: back, onOpenWork: { nav.push(.agreement($0)) })
            case .consultations:
                ConsultationsScreen(onBack: back, onOpenEditor: { nav.pushSingleTop(.consultationEdit($0)) }, onOpenConsultation: { nav.push(.consultation($0)) },
                                    onSwitchStore: switchStore)
            case let .consultationEdit(uuid): ConsultationEditorScreen(uuid: uuid, onBack: back)
            case let .consultation(uuid):
                ConsultationScreen(uuid: uuid, onBack: back, onOpenSession: { nav.pushSingleTop(.consultationSession($0)) })
            // FL-L16: full screen, outside the shell (no bar: `Route.wantsBar`).
            case let .consultationSession(uuid): ConsultationSessionScreen(uuid: uuid, onBack: back)
            case .specialOffers:
                SpecialOffersScreen(onBack: back, onCompose: { nav.pushSingleTop(.specialOfferNew) }, onOpen: { nav.push(.specialOffer($0)) },
                                    onSwitchStore: switchStore)
            case .loyalty: LoyaltyScreen(onBack: back, onSwitchStore: switchStore)
            // FL-L12: the professional community.
            case .community: CommunityScreen(nav: communityNav, onBack: back)
            case let .communityPost(uuid): CommunityPostScreen(uuid: uuid, nav: communityNav, onBack: back)
            case let .communityMember(uuid): CommunityMemberScreen(uuid: uuid, nav: communityNav, onBack: back)
            case let .communityPeople(uuid, which): CommunityPeopleScreen(uuid: uuid, startWhich: which, nav: communityNav, onBack: back)
            case let .communityCompose(kind, edit): CommunityComposeScreen(startKind: kind, editUuid: edit, nav: communityNav, onBack: back)
            case .specialOfferNew:
                SpecialOfferComposeScreen(onBack: back, onSent: { nav.replaceTop(.specialOffer($0)) })
            case let .specialOffer(uuid):
                SpecialOfferScreen(uuid: uuid, onBack: back, onOpenWork: { nav.push(.agreement($0)) },
                                   onOpenConsultation: { nav.push(.consultation($0)) }, onOpenOrder: { nav.push(.serviceOrder($0)) })
            // Never pushed (`Nav.push(.home)` goes back to the tabs).
            case .home: EmptyView()
            }
        }
        .background(PinkBackdrop())
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
