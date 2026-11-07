import SwiftUI

/// freelanceApp `MainScreen.kt` — the app once signed in: inside the shared shell (header + bottom bar) the tabs — Home (a
/// dashboard), Opportunities (open projects, invitations), My work (running projects, my offers, service orders,
/// consultations), Community (while it is on in my country) and Me. The account is asked for once when this opens (and again
/// when the connection comes back); a 401 already sends the freelancer back to sign-in.
/// (Android's «back from another tab returns to Home first» has no iOS twin: there is no system back key.)
@MainActor
struct MainScreen: View {
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var shell = ShellState.shared
    @ObservedObject private var homeHub = HomeHub.shared
    @ObservedObject private var communityHub = CommunityHub.shared
    let actions: MainActions
    @State private var failed: String?
    @State private var previousIndex = 0

    var body: some View {
        let tab = shell.tab
        let tabs = shellTabs
        let index = tabs.firstIndex { $0.key == tab } ?? 0
        ZStack {
            switch tab {
            case Tabs.OPPORTUNITIES: OpportunitiesTab(actions: actions)
            case Tabs.WORK: WorkTab(actions: actions)
            case Tabs.COMMUNITY: CommunityScreen(nav: actions.community, onBack: { shell.tab = Tabs.HOME })
            case Tabs.ME: AccountScreen(actions: actions, onBack: { shell.tab = Tabs.HOME })
            default: HomeScreen(actions: actions, failed: failed)
            }
        }
        // A tab slides in from the side it sits on, with a fade, like the pages do.
        .id(tab)
        .transition(.asymmetric(
            insertion: .opacity.combined(with: .offset(x: (index > previousIndex ? 1 : -1) * UIScreen.main.bounds.width / 6)),
            removal: .opacity
        ))
        .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32), value: tab)
        .onChange(of: index) { [index] _ in previousIndex = index }
        // The community's switch went off while its tab was open: back home.
        .onChange(of: tabs.map(\.key)) { keys in if !keys.contains(shell.tab) { shell.tab = Tabs.HOME } }
        .task(id: network.reconnectTick) { await loadMe() }
        // A notice arrived (live): the account may have changed (a verification decision) — one reload, no timer.
        .onReceive(Realtime.shared.changed) { _ in
            Task {
                if let me = await AuthApi.me().value { Session.shared.me = me }
            }
        }
        // Phone notifications are asked for once, after the first screen is up.
        .onAppear { Realtime.shared.requestPushPermission() }
    }

    private func loadMe() async {
        let result = await AuthApi.me()
        switch result {
        case .ok(let me, _):
            Session.shared.me = me
            failed = nil
        default:
            // A 401 already cleared the session (the navigation goes back to sign-in by itself).
            if Session.shared.me == nil { failed = result.errorText(L("login_error_offline")) ?? L("home_loading_failed") }
        }
    }
}

/// `rememberSegment`: the part a shortcut asked for (taken once), else the one open before, else `fallback`.
private struct SegmentHost<Content: View>: View {
    @ObservedObject private var shell = ShellState.shared
    let allowed: [String]
    let fallback: String
    @ViewBuilder let content: (_ segment: String, _ select: @escaping (String) -> Void) -> Content
    @State private var segment: String?

    var body: some View {
        // A part whose feature went off falls back to the first one.
        let chosen = segment ?? shell.segment.flatMap { allowed.contains($0) ? $0 : nil } ?? fallback
        let current = allowed.contains(chosen) ? chosen : fallback
        content(current) { segment = $0 }
            .onAppear { take(shell.segment) }
            .onChange(of: shell.segment) { take($0) }
    }

    private func take(_ asked: String?) {
        guard let asked else { return }
        if allowed.contains(asked) { segment = asked }
        shell.segment = nil
    }
}

/// Opportunities: the open projects (search, filter, saved) and the clients' invitations to private projects.
private struct OpportunitiesTab: View {
    @ObservedObject private var hub = HomeHub.shared
    let actions: MainActions

    var body: some View {
        let home = hub.home
        let withInvitations = home.map { $0.on("invitations") } != false
        var segments = [Segment(key: "projects", label: L("opp_projects"), icon: "magnifyingglass")]
        if withInvitations {
            segments.append(Segment(key: "invitations", label: L("opp_invitations"), icon: "envelope.badge.fill", count: home?.attention?.invitationsPending ?? 0))
        }
        return SegmentHost(allowed: segments.map(\.key), fallback: "projects") { segment, select in
            HubTabFrame(title: L("tab_opportunities"), subtitle: L("opp_subtitle"), segments: segments, selected: segment, onSelect: select,
                        onBack: { ShellState.shared.tab = Tabs.HOME }) {
                switch segment {
                case "invitations": InvitationsScreen(onBack: {}, onOpenProject: actions.openProject, embedded: true)
                default: ProjectsTab(onBack: {}, onOpenProject: actions.openProject, embedded: true)
                }
            }
        }
    }
}

/// My work: the projects I was chosen for, my offers, the orders on my ready-made services and my consultations.
private struct WorkTab: View {
    @ObservedObject private var hub = HomeHub.shared
    let actions: MainActions

    var body: some View {
        let home = hub.home
        let a = home?.attention
        var segments = [
            Segment(key: "projects", label: L("work_seg_projects"), icon: "hammer.fill", count: a?.revisions ?? 0),
            Segment(key: "offers", label: L("work_seg_offers"), icon: "tag.fill"),
        ]
        if home.map({ $0.on("services") }) != false {
            segments.append(Segment(key: "orders", label: L("work_seg_orders"), icon: "tray.fill", count: a?.ordersAwaiting ?? 0))
        }
        if home.map({ $0.on("consultations") }) != false {
            segments.append(Segment(key: "consultations", label: L("work_seg_consultations"), icon: "video.fill", count: a?.consultationsPending ?? 0))
        }
        return SegmentHost(allowed: segments.map(\.key), fallback: "projects") { segment, select in
            HubTabFrame(title: L("tab_work"), subtitle: L("work_subtitle"), segments: segments, selected: segment, onSelect: select,
                        onBack: { ShellState.shared.tab = Tabs.HOME }) {
                switch segment {
                case "offers": MyOffersScreen(onBack: nil, onOpenProject: actions.openOffer, embedded: true)
                case "orders": ServiceOrdersScreen(onBack: {}, onOpenOrder: actions.openServiceOrder, embedded: true)
                case "consultations":
                    ConsultationsScreen(onBack: {}, onOpenEditor: actions.openConsultationEditor, onOpenConsultation: actions.openConsultation, embedded: true)
                default: MyWorkTab(onBack: {}, onOpenProject: actions.openProject, onOpenAgreement: actions.openAgreement, embedded: true)
                }
            }
        }
    }
}
