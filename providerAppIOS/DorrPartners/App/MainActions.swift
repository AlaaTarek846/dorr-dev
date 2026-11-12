import SwiftUI

/// providerApp `MainScreen.kt` MainActions — where the tabs send the provider: a page over the tabs, or back through the
/// workspace gate.
struct MainActions {
    let openDocuments: () -> Void
    let openInvitations: () -> Void
    let openNotifications: () -> Void
    /// Creating my own establishment (when I only work in others').
    let openOnboarding: () -> Void
    /// The chosen establishment is gone (I left or was removed): the gate picks again.
    let openWorkspace: () -> Void
    // C-03: running the establishment.
    let openBranches: () -> Void
    let openTeam: () -> Void
    let openVehicles: () -> Void
    /// A driver's licence page (the membership uuid; my own from the Account tab).
    let openDriverDocs: (String) -> Void
    // C-04: the moving work.
    let openRequests: () -> Void
    /// true: straight on the offers waiting for approval.
    let openOffers: (Bool) -> Void
    /// true: straight on the jobs still without a driver.
    let openJobs: (Bool) -> Void
    let openMyJobs: () -> Void
    let openTickets: () -> Void

    @MainActor
    static func navigating(_ nav: Nav) -> MainActions {
        MainActions(
            openDocuments: { nav.pushSingleTop(.documents) },
            openInvitations: { nav.pushSingleTop(.invitations) },
            openNotifications: { nav.pushSingleTop(.notifications) },
            openOnboarding: { nav.pushSingleTop(.onboarding) },
            openWorkspace: { nav.reset(to: .workspace) },
            openBranches: { nav.pushSingleTop(.branches) },
            openTeam: { nav.pushSingleTop(.team) },
            openVehicles: { nav.pushSingleTop(.vehicles) },
            openDriverDocs: { nav.push(.driverDocs($0)) },
            openRequests: { nav.pushSingleTop(.requests) },
            openOffers: { nav.push(.offers(approval: $0)) },
            openJobs: { nav.push(.jobs(unassigned: $0)) },
            openMyJobs: { nav.pushSingleTop(.myJobs) },
            openTickets: { nav.pushSingleTop(.tickets) }
        )
    }
}
