import SwiftUI

// freelanceApp `navigation/FreelanceNavGraph.kt` — the routes: splash → welcome (once, on the first launch after
// install) → sign-in (phone → code) → home. A signed-in freelancer who has not agreed to the terms is sent to `.terms`
// before the home.

/// A page pushed over the home (the Android routes after `home`).
enum Route: Hashable {
    case otp(dial: String, phone: String, cooldown: Int)
    case terms
    case profile
    case specialties
    case skills
    case experiences
    case availability
    case plans
    case insights
    case coach
    case skillTests
    case capacity
    case portfolio
    case project(String)
    case offerForm(String)
    case offerEdit(String)
    case offerDetail(String)
    case myOffers
    case invitations
    case chat(String)
    case agreement(String)
    case dispute(project: String, dispute: String)
    case disputeNew(project: String, milestone: String)
    case certificates
    case verification
    case notifications
    case wallet
    case portfolioItem(Int)
    // FL-L04: my ready-made services and the orders on them.
    /// `nil` = a new service.
    case service(String?)
    case services
    case serviceOrders
    case serviceOrder(String)
    // FL-L07: paid consultations.
    case consultations
    /// The editor of one consultation I list (nil: a new one).
    case consultationEdit(String?)
    case consultation(String)

    /// Pages drawn without the bottom bar (`shell(false)` on Android is the same frame; the chat is full screen there).
    var wantsBar: Bool {
        switch self {
        case .otp, .terms: return false
        default: return true
        }
    }
}

/// Where the app stands before the pages: the splash, the welcome, sign-in, or the signed-in home.
enum RootStage: Equatable {
    case splash, start, login, home
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

    func reset(to stage: RootStage) {
        path.removeAll()
        withAnimation(.easeInOut(duration: 0.34)) { self.stage = stage }
    }
}

/// What the signed-in frame shows: the tab that is open (kept while a page is open over the tabs, so the tab the page was
/// opened from stays lit) and whether a full-screen layer has hidden the header and the bar.
@MainActor
final class ShellState: ObservableObject {
    static let shared = ShellState()

    @Published var tab = 0
    @Published var immersive = false
    /// Asked from a page over the tabs: open the Account tab straight on «ملفي». The account page takes it once.
    @Published var openProfileHub = false
}
