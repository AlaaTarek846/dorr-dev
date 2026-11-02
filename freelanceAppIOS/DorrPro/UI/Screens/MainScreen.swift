import SwiftUI

/// freelanceApp `MainScreen.kt` — the app once signed in: inside the shared shell (header + bottom bar) the pages are tabs —
/// Home, Projects, My offers, My work, Account. The account is asked for once when this opens (and again when the connection
/// comes back); a 401 already sends the freelancer back to sign-in.
@MainActor
struct MainScreen: View {
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var shell = ShellState.shared
    let actions: MainActions
    @State private var failed: String?
    @State private var previousTab = 0

    var body: some View {
        let tab = shell.tab
        ZStack {
            switch tab {
            case 0: HomeScreen(actions: actions, failed: failed, onOpenPortfolio: actions.openPortfolio, onOpenProfileTab: { shell.tab = 4 })
            case 1: ProjectsTab(onBack: { shell.tab = 0 }, onOpenProject: actions.openProject)
            case 2: MyOffersScreen(onBack: { shell.tab = 0 }, onOpenProject: actions.openOffer, asTab: true)
            case 3: MyWorkTab(onBack: { shell.tab = 0 }, onOpenProject: actions.openProject)
            default: AccountScreen(actions: actions)
            }
        }
        // A tab slides in from the side it sits on, with a fade, like the pages do.
        .id(tab)
        .transition(.asymmetric(
            insertion: .opacity.combined(with: .offset(x: (tab > previousTab ? 1 : -1) * UIScreen.main.bounds.width / 6)),
            removal: .opacity
        ))
        .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32), value: tab)
        .onChange(of: tab) { [tab] _ in previousTab = tab }
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
