import SwiftUI

// driverApp `ui/DriverNavHost.kt` — the routes. Splash, then sign-in (phone → code) or the shift: four tabs (home, earnings,
// wallet, account) on a bottom bar, and the rest — the ride, signing up, preferences, cases… — opening over them.

/// The four tabs of the bottom bar; every other screen opens over them.
enum DriverTab: String, CaseIterable {
    case home, earnings, wallet, account
}

/// A screen opened over the tabs (the Android routes other than splash / login / the tabs).
enum Route: Hashable {
    case otp(dial: String, phone: String, masked: String, cooldown: Int)
    case signup
    case trip
    case tripRide(String)
    case rank
    case history
    case settings
    case support
    case lost
    case withme
    case hire
    case lessons
    case marine
    case marineListing(String)
    case bus
    case busTrip(String)
    case notifications
    case safety
    case airport
    case preferences
    case driverAi
    case cases
    case scheduled

    /// O-82: the bar shows everywhere except the splash, sign-in, signing up and the live trip (Android's `NoBarRoutes`
    /// holds the route names "splash", "login", "signup", "trip" — `trip/{uuid}` and the code page are other routes, so
    /// the bar stays on them, as on Android).
    var hidesBar: Bool {
        switch self {
        case .signup, .trip: return true
        default: return false
        }
    }

    /// A screen opened from a tab keeps that tab lit (`tabOf`).
    var tab: DriverTab {
        switch self {
        case .notifications, .airport, .scheduled, .withme: return .home
        default: return .account
        }
    }
}

/// Where the app stands under the pages: the splash, sign-in, or the tabs.
enum RootStage: Equatable {
    case splash, login, tabs
}

/// The navigation (`NavController`).
@MainActor
final class Nav: ObservableObject {
    static let shared = Nav()

    @Published var stage: RootStage = .splash
    @Published var tab: DriverTab = .home
    @Published var path: [Route] = []

    func push(_ route: Route) { path.append(route) }

    func pop() { if !path.isEmpty { path.removeLast() } }

    /// `goTab`: the tab, with the pages over the tabs closed (`popUpTo("home") { saveState }`, `restoreState`).
    func goTab(_ to: DriverTab) {
        path.removeAll()
        withAnimation(.easeInOut(duration: 0.26)) { tab = to }
    }

    /// `popBackStack("home", inclusive = false)`.
    func popToHome() {
        path.removeAll()
        tab = .home
    }

    /// `navigate(x) { popUpTo(0) { inclusive = true } }`.
    func reset(to stage: RootStage) {
        path.removeAll()
        tab = .home
        withAnimation(.easeInOut(duration: 0.26)) { self.stage = stage }
    }

    /// The tab lit now: the open tab, or the one the page on top belongs to.
    var litTab: DriverTab { path.last?.tab ?? tab }
}

/// D-214, D-298: «not me» on a wallet transaction opens a support ticket about it (driverApp `SupportPrefill` in
/// SupportScreen.kt): (category, transaction reference, text).
@MainActor
final class SupportPrefill: ObservableObject {
    static let shared = SupportPrefill()
    @Published var open: (category: String, reference: String?, text: String)?
}
