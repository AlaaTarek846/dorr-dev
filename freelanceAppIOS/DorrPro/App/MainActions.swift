import SwiftUI

// freelanceApp `ui/screens/MainScreen.kt` (MainActions) + the `MainActions(...)` built in `navigation/FreelanceNavGraph.kt`.

/// Where the tabs send the freelancer: a page over the tabs.
struct MainActions {
    let openProfile: () -> Void
    let openSpecialties: () -> Void
    let openSkills: () -> Void
    let openExperiences: () -> Void
    let openAvailability: () -> Void
    let openPlans: () -> Void
    let openPortfolio: () -> Void
    let openProject: (String) -> Void
    /// FS-209b: a team's project opens its agreement page (the project page is the signer's own offer view).
    let openAgreement: (String) -> Void
    let openMyOffers: () -> Void
    let openOffer: (String) -> Void
    let openInvitations: () -> Void
    let openCertificates: () -> Void
    let openVerification: () -> Void
    let openPortfolioItem: (Int) -> Void
    let openNotifications: () -> Void
    let openWallet: () -> Void
    // FL-L04
    let openServices: () -> Void
    let openServiceOrders: () -> Void
    let openServiceOrder: (String) -> Void
    // FL-L01 phase 4: my performance numbers
    let openInsights: () -> Void
    // FS-703: my clients (the private CRM)
    let openCrm: () -> Void
    // FL-L11: improve my profile
    let openCoach: () -> Void
    // FL-L08: skill tests and badges
    let openSkillTests: () -> Void
    // FS-604: my reputation figures
    let openReputation: () -> Void
    // FL-L10: my capacity and the line on my services
    let openCapacity: () -> Void
    // FS-209: my freelancers' teams
    let openTeams: () -> Void
    // FL-L07: paid consultations
    let openConsultations: () -> Void
    let openConsultation: (String) -> Void
    let openConsultationEditor: (String?) -> Void
    // FL-L09: special offers to past clients
    let openSpecialOffers: () -> Void
    let openLoyalty: () -> Void
    // FL-L12: the professional community (a tab of the bar)
    let community: CommunityNav

    @MainActor
    static func navigating(_ nav: Nav, community: CommunityNav) -> MainActions {
        MainActions(
            openProfile: { nav.push(.profile) },
            openSpecialties: { nav.push(.specialties) },
            openSkills: { nav.push(.skills) },
            openExperiences: { nav.push(.experiences) },
            openAvailability: { nav.push(.availability) },
            openPlans: { nav.push(.plans) },
            openPortfolio: { nav.push(.portfolio) },
            openProject: { nav.push(.project($0)) },
            openAgreement: { nav.push(.agreement($0)) },
            openMyOffers: { nav.push(.myOffers) },
            openOffer: { nav.push(.offerDetail($0)) },
            openInvitations: { nav.push(.invitations) },
            openCertificates: { nav.push(.certificates) },
            openVerification: { nav.push(.verification) },
            openPortfolioItem: { nav.push(.portfolioItem($0)) },
            openNotifications: { nav.push(.notifications) },
            openWallet: { nav.push(.wallet) },
            openServices: { nav.push(.services) },
            openServiceOrders: { nav.push(.serviceOrders) },
            openServiceOrder: { nav.push(.serviceOrder($0)) },
            openInsights: { nav.push(.insights) },
            openCrm: { nav.push(.crm) },
            openCoach: { nav.push(.coach) },
            openSkillTests: { nav.push(.skillTests) },
            openReputation: { nav.push(.reputation) },
            openCapacity: { nav.push(.capacity) },
            openTeams: { nav.push(.teams) },
            openConsultations: { nav.push(.consultations) },
            openConsultation: { nav.push(.consultation($0)) },
            openConsultationEditor: { nav.pushSingleTop(.consultationEdit($0)) },
            openSpecialOffers: { nav.push(.specialOffers) },
            openLoyalty: { nav.push(.loyalty) },
            community: community
        )
    }
}
