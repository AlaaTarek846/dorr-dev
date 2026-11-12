import SwiftUI

// providerApp `navigation/ProviderNavGraph.kt` — the routes: splash → sign-in (phone → code) → the workspace gate, which
// opens the establishment I work in (home), the invitations waiting for me, or the onboarding that creates my own
// establishment (C-02) → home; over the home the running of the establishment (C-03) and the moving work (C-04, C-05).

/// A page pushed over the stage (the Android routes after the gate). `.home` and `.wallet` are not pages: the home and the
/// wallet are tabs, and `RootView.openRoute` lights them (a notice about money leads to the wallet).
enum Route: Hashable {
    case otp(dial: String, phone: String, cooldown: Int)
    case onboarding
    case invitations
    case documents
    case notifications
    case wallet
    case home
    // C-03: running the establishment.
    case branches
    case team
    case vehicles
    case vehicle(String)
    case driverDocs(String)
    // C-04: the moving work.
    case requests
    case request(String)
    /// true: straight on the offers waiting for approval.
    case offers(approval: Bool)
    /// true: straight on the jobs still without a driver.
    case jobs(unassigned: Bool)
    case job(String)
    case myJobs
    case tickets
    case ticket(String)

    /// Pages drawn with the bottom bar (`shell { }` on Android): every page over the tabs; only the sign-in's code and the two pages
    /// that can come straight after the gate stand alone.
    var wantsBar: Bool {
        switch self {
        case .otp, .onboarding, .invitations: return false
        default: return true
        }
    }
}

/// Where the app stands before the pages: the splash, sign-in, the workspace gate (one read of `workspace`), the onboarding
/// or the invitations when they are the first page after the gate, the first vehicle (registration's 4th step when moving is
/// chosen — Android `Routes.FIRST_VEHICLE`), or the signed-in tabs.
enum RootStage: Equatable {
    case splash, login, workspace, onboarding, firstVehicle, invitations, home
}

/// The navigation (`NavController`): the stage at the root and the pages pushed over it.
@MainActor
final class Nav: ObservableObject {
    static let shared = Nav()

    @Published var stage: RootStage = .splash
    @Published var path: [Route] = []

    func push(_ route: Route) { path.append(route) }

    /// `launchSingleTop`: no second copy of the page on top.
    func pushSingleTop(_ route: Route) {
        if path.last != route { path.append(route) }
    }

    func pop() { if !path.isEmpty { path.removeLast() } }

    /// Back to the tabs (`popBackStack(HOME, inclusive = false)`).
    func popToHome() { path.removeAll() }

    /// `navigate(stage) { popUpTo(0) { inclusive = true } }`.
    func reset(to stage: RootStage) {
        path.removeAll()
        withAnimation(.easeInOut(duration: 0.34)) { self.stage = stage }
    }
}

/// The tabs, by key (not a position, since the wallet tab shows only with `wallet.view`).
let tabHome = 0
let tabWallet = 1
let tabAccount = 2

/// What the signed-in frame shows: the tab that is open (kept while a page is open over the tabs, so the tab the page was
/// opened from stays lit) and whether a full-screen layer (the test payment page) has hidden the header and the bar.
/// `tab` is one of `tabHome`, `tabWallet`, `tabAccount`.
@MainActor
final class ShellState: ObservableObject {
    static let shared = ShellState()

    @Published var tab = tabHome
    @Published var immersive = false
}
