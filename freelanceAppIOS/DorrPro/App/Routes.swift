import SwiftUI

// freelanceApp `navigation/FreelanceNavGraph.kt` — the routes: splash → welcome (once, on the first launch after
// install) → sign-in (phone → code) → home.

/// A page pushed over the home (the Android routes after `home`).
enum Route: Hashable {
    case otp(dial: String, phone: String, cooldown: Int)
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
    // FS-209: my freelancers' teams and one team's page (its uuid).
    case teams
    case team(String)
    // FS-209c: a team's inside chat thread, its works (the list and one work: id 0 = a new one) and its verification.
    case teamThread(team: String, thread: String)
    case teamPortfolio(String)
    case teamPortfolioItem(team: String, id: Int)
    case teamVerification(String)
    // FS-211: a team's plan (its Owners read it and subscribe).
    case teamPlan(String)
    case portfolio
    case project(String)
    case offerForm(String)
    case offerEdit(String)
    case offerDetail(String)
    case myOffers
    case invitations
    case chat(String)
    case agreement(String)
    // FS-150e: a project's Workroom (opens on `tab` = tasks · files · decisions · timeline) and one file of it.
    case workroom(project: String, tab: String)
    case workroomFile(project: String, file: String)
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
    /// FL-L16: the consultation's video session (full screen, outside the shell).
    case consultationSession(String)
    // FL-L09: special offers to past clients.
    case specialOffers
    case specialOfferNew
    case specialOffer(String)
    // FL-L09 (part B): my loyalty program.
    case loyalty
    // FL-L12: the professional community.
    case community
    case communityPost(String)
    case communityMember(String)
    case communityPeople(uuid: String, which: String)
    /// A new post of `kind`, or (with `edit`) editing that post of mine.
    case communityCompose(kind: String = "post", edit: String? = nil)
    /// Android `Routes.HOME` reached from a notice (a sanction): back to the tabs, on Home — `Nav.push` never stacks it.
    case home

    /// Pages drawn without the bottom bar (`shell(false)` on Android is the same frame; the chat is full screen there).
    var wantsBar: Bool {
        switch self {
        case .otp, .consultationSession: return false
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

    func push(_ route: Route) {
        if route == .home { return goHome() }
        path.append(route)
    }

    /// `launchSingleTop`: no second copy of the page on top.
    func pushSingleTop(_ route: Route) {
        if route == .home { return goHome() }
        if path.last != route { path.append(route) }
    }

    /// `nav.popBackStack(); nav.navigate(route) { launchSingleTop = true }` — the open page swapped for another in place.
    func replaceTop(_ route: Route) {
        if !path.isEmpty { path.removeLast() }
        if path.last != route { path.append(route) }
    }

    private func goHome() {
        ShellState.shared.tab = Tabs.HOME
        popToHome()
    }

    func pop() { if !path.isEmpty { path.removeLast() } }

    /// Back to the tabs (`popBackStack(HOME, inclusive = false)`).
    func popToHome() { path.removeAll() }

    func reset(to stage: RootStage) {
        path.removeAll()
        withAnimation(.easeInOut(duration: 0.34)) { self.stage = stage }
    }
}

/// The tabs of the bottom bar, by name (the community's comes and goes with its country switch, so a number would move).
enum Tabs {
    static let HOME = "home"
    static let OPPORTUNITIES = "opportunities"
    static let WORK = "work"
    static let COMMUNITY = "community"
    static let ME = "me"
}

/// What the signed-in frame shows: the tab that is open (kept while a page is open over the tabs, so the tab the page was
/// opened from stays lit), whether a full-screen layer (the test payment page) has hidden the header and the bar, and the
/// part of a tab a shortcut asked to open (taken once by that tab).
@MainActor
final class ShellState: ObservableObject {
    static let shared = ShellState()

    @Published var tab = Tabs.HOME
    @Published var immersive = false
    /// Asked from a page over the tabs: open the «Me» tab straight on «My profile». The tab takes it once.
    @Published var openProfileHub = false
    /// A part of «Opportunities» (`projects` / `invitations`) or «My work» (`projects` / `offers` / `orders` / `consultations`) to open.
    @Published var segment: String?

    func open(_ tab: String, segment: String? = nil) {
        self.segment = segment
        self.tab = tab
    }
}
