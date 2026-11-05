import SwiftUI

/// providerApp `MainScreen.kt` — the app once an establishment is chosen, like the DORR app: inside the shared shell (header +
/// bottom bar, drawn by RootView) the pages are tabs — Home, Wallet (with `wallet.view`), Account. The person, the workspace and
/// the establishment with my seat in it are read once when this opens, again when the connection comes back, when a live notice
/// arrives and when another establishment is chosen — never on a timer.
@MainActor
struct MainScreen: View {
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var shell = ShellState.shared
    @ObservedObject private var session = Session.shared
    let actions: MainActions
    @State private var establishment: EstablishmentDto?
    @State private var failed: String?
    @State private var previousTab = tabHome

    var body: some View {
        // The open tab lives in `ShellState` so the pages over the tabs keep it lit; a tab my seat may not see falls back to Home.
        let tab = shownTab(shell.tab, session.membership)
        ZStack {
            switch tab {
            case tabWallet: WalletRoute(onBack: { shell.tab = tabHome })
            case tabAccount: AccountScreen(actions: actions)
            default: HomeScreen(establishment: establishment, failed: failed, actions: actions, onReload: { await loadEstablishment() })
            }
        }
        // A tab slides in from the side it sits on, with a fade, like the pages do. Another establishment chosen: every tab
        // starts over for it (its wallet, its papers).
        .id("\(tab)-\(session.establishment ?? "")")
        .transition(.asymmetric(
            insertion: .opacity.combined(with: .offset(x: (tab > previousTab ? 1 : -1) * UIScreen.main.bounds.width / 6)),
            removal: .opacity
        ))
        .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32), value: tab)
        .onChange(of: tab) { [tab] _ in previousTab = tab }
        .task(id: network.reconnectTick) {
            if let me = await AuthApi.me().value { Session.shared.setMe(me) }
            await Workspace.shared.reload()
        }
        .task(id: "\(network.reconnectTick)-\(session.establishment ?? "")") {
            if session.establishment == nil { actions.openWorkspace() } else { await loadEstablishment() }
        }
        // A notice arrived (live): the establishment's status, my seat or my invitations may have changed — one reload, no timer.
        .onReceive(Realtime.shared.changed) { _ in
            Task {
                await Workspace.shared.reload()
                await loadEstablishment()
            }
        }
        // A driver with a running job: the location tracking back on if the phone killed the app, off if the job ended meanwhile.
        .task(id: "\(network.reconnectTick)-\(session.membership?.uuid ?? "")") {
            if session.membership.can("driving.work") { await MoveTracking.sync() }
        }
        // Phone notifications are asked for once, after the first screen is up.
        .onAppear { Realtime.shared.requestPushPermission() }
    }

    private func loadEstablishment() async {
        let result = await WorkspaceApi.establishment()
        switch result {
        case .ok(let data, _):
            establishment = data.establishment
            Session.shared.setMembership(data.membership)
            failed = nil
        case .failed(let error):
            // My seat there ended (I left, was removed, or the establishment is gone): no choice any more, so the task above
            // sends me through the gate to pick again.
            if error.errorCode == "provider_no_establishment" || error.errorCode == "provider_pick_establishment" {
                Session.shared.chooseEstablishment(nil)
            } else if establishment == nil {
                failed = result.errorText(L("login_error_offline")) ?? L("home_loading_failed")
            }
        // A 401 already cleared the session (the navigation goes back to sign-in by itself).
        case .offline:
            if establishment == nil { failed = result.errorText(L("login_error_offline")) ?? L("home_loading_failed") }
        }
    }
}
