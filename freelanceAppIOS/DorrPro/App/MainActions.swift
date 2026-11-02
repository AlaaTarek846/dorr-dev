import SwiftUI

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
    // FL-L01 phase 4: my performance numbers
    let openInsights: () -> Void
    // FL-L11: improve my profile
    let openCoach: () -> Void
    // FL-L08: skill tests and badges
    let openSkillTests: () -> Void
    // FL-L10: my capacity and the line on my services
    let openCapacity: () -> Void
    // FL-L07: paid consultations
    let openConsultations: () -> Void

    @MainActor
    static func navigating(_ nav: Nav) -> MainActions {
        MainActions(
            openProfile: { nav.push(.profile) },
            openSpecialties: { nav.push(.specialties) },
            openSkills: { nav.push(.skills) },
            openExperiences: { nav.push(.experiences) },
            openAvailability: { nav.push(.availability) },
            openPlans: { nav.push(.plans) },
            openPortfolio: { nav.push(.portfolio) },
            openProject: { nav.push(.project($0)) },
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
            openInsights: { nav.push(.insights) },
            openCoach: { nav.push(.coach) },
            openSkillTests: { nav.push(.skillTests) },
            openCapacity: { nav.push(.capacity) },
            openConsultations: { nav.push(.consultations) }
        )
    }
}
