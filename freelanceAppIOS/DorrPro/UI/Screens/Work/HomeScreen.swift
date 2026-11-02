// freelanceApp `ui/screens/HomeScreen.kt`
import SwiftUI

/// The Home tab, in the look of the DORR app home (under the shared header of AppShell: cards, a grid of shortcuts) but with what a
/// freelancer needs: how complete the profile is, where verification stands, the shortcuts to the profile lists and what is still missing.
@MainActor
struct HomeScreen: View {
    let actions: MainActions
    let failed: String?
    let onOpenPortfolio: () -> Void
    let onOpenProfileTab: () -> Void

    @Environment(\.wa) private var wa
    @ObservedObject private var session = Session.shared

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                if let profile = session.me {
                    // FL-708: shown only while a warning / restriction is active or its appeal waits.
                    SanctionsCard()
                    if profile.availability?.canReceiveOffers == false { PausedCard(onOpen: actions.openAvailability) }
                    WaitingOrdersBanner(onOpen: actions.openServiceOrders)
                    // FL-L07: the next consultation, when one is booked.
                    NextConsultationBanner(onOpen: actions.openConsultations)
                    // FL-L11: how complete the profile is and the next steps worth taking (the server ranks them).
                    CoachCard(fallbackPercent: profile.completeness?.percent ?? 0, onAll: actions.openCoach, onOpen: { action in
                        switch action {
                        case "skills": actions.openSkills()
                        case "specialties": actions.openSpecialties()
                        case "portfolio": onOpenPortfolio()
                        case "verification": actions.openVerification()
                        case "services": actions.openServices()
                        default: actions.openProfile()
                        }
                    })
                    VerificationCard(status: profile.status, reason: profile.statusReason, onOpen: actions.openVerification)

                    Text(L("home_quick_title")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                    quickRow(("square.grid.2x2.fill", L("home_open_specialties"), actions.openSpecialties), ("bolt.fill", L("home_open_skills"), actions.openSkills))
                    quickRow(("wrench.and.screwdriver.fill", L("home_open_experiences"), actions.openExperiences), ("graduationcap.fill", L("home_open_certificates"), actions.openCertificates))
                    quickRow(("briefcase.fill", L("home_open_portfolio"), onOpenPortfolio), ("wallet.pass.fill", L("wl_title"), actions.openWallet))
                    // FL-L04: my ready-made services and the orders on them.
                    quickRow(("storefront.fill", L("svc_home_card"), actions.openServices), ("tray.fill", L("svc_orders_home_card"), actions.openServiceOrders))
                    // FL-L01 phase 4: my numbers, and my plan beside them.
                    quickRow(("chart.line.uptrend.xyaxis", L("insights_home_card"), actions.openInsights), ("rosette", L("plans_title"), actions.openPlans))
                    // FL-L08: prove a skill with a test and carry its badge. FL-L10: how many projects I take at once, and who waits.
                    quickRow(("questionmark.square.fill", L("skt_home_card"), actions.openSkillTests), ("speedometer", L("cap_home_card"), actions.openCapacity))
                    // FL-L07: paid consultations, and the hours I keep for them.
                    quickRow(("video.fill", L("cns_home_card"), actions.openConsultations), ("calendar.badge.checkmark", L("availability_title"), actions.openAvailability))
                }
                Spacer().frame(height: 20)
            }
            .padding(.horizontal, 20)
            .padding(.top, 14)
        }
        .messageToast(failed)
    }

    private func quickRow(_ a: (String, String, () -> Void), _ b: (String, String, () -> Void)) -> some View {
        HStack(spacing: 8) {
            QuickCard(icon: a.0, label: a.1, onClick: a.2)
            QuickCard(icon: b.0, label: b.1, onClick: b.2)
        }
    }
}

private struct VerificationCard: View {
    @Environment(\.wa) private var wa
    let status: String?
    let reason: String?
    let onOpen: () -> Void

    var body: some View {
        Button(action: onOpen) {
            HStack(spacing: 12) {
                Image(systemName: status == "verified" ? "checkmark.seal.fill" : "person.text.rectangle.fill").font(.system(size: 24)).foregroundStyle(wa.red)
                    .frame(width: 28, height: 28)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("home_open_verification")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: statusLabel(status)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    // Why the account was put on hold, as staff wrote it.
                    if status == "suspended", let reason, !reason.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(L("status_reason_line", reason)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(16)
            .settingsSurface(20)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

private struct QuickCard: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            VStack(spacing: 8) {
                Image(systemName: icon).font(.system(size: 16, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 34, height: 34)
                    .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
            }
            .padding(12)
            .frame(maxWidth: .infinity, minHeight: 84)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay { if wa.night { RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.line, lineWidth: 1) } }
            .shadow(color: wa.night ? .clear : wa.red.opacity(0.07), radius: 6, y: 2)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity)
    }
}

/// The account status in the app language; an unknown one is shown as the server sent it.
func statusLabel(_ status: String?) -> String {
    switch status {
    case "registered": return L("status_registered")
    case "profile_ready": return L("status_profile_ready")
    case "verification_submitted": return L("status_verification_submitted")
    case "verified": return L("status_verified")
    case "verification_rejected": return L("status_verification_rejected")
    case "changes_requested": return L("status_changes_requested")
    case "suspended": return L("status_suspended")
    case "blocked": return L("status_blocked")
    default: return status ?? ""
    }
}

/// A short notice on the home while the freelancer is not taking new offers (paused or on time off); a tap opens the availability page.
private struct PausedCard: View {
    @Environment(\.wa) private var wa
    let onOpen: () -> Void

    var body: some View {
        Button(action: onOpen) {
            HStack(spacing: 10) {
                Image(systemName: "calendar.badge.exclamationmark").font(.system(size: 19)).foregroundStyle(wa.red).frame(width: 22, height: 22)
                Text(L("availability_paused_banner")).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(16)
            .settingsSurface(20)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// FL-L07: the next consultation (a request waiting for my answer first). Read when the home opens, when the connection returns and
/// when a notice arrives, never on a timer.
@MainActor
private struct NextConsultationBanner: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onOpen: () -> Void
    @State private var next: ConsultationDto?

    var body: some View {
        Group {
            if let c = next {
                let at = parseInstant(c.startsAt)
                let time = at.map { Self.format($0, "HH:mm", Locale(identifier: "en_US_POSIX")) } ?? ""
                let day = at.map { Self.format($0, "EEEE d MMM", AppLocale.locale) } ?? ""
                Button(action: onOpen) {
                    HStack(spacing: 12) {
                        Image(systemName: "video.fill").font(.system(size: 18)).foregroundStyle(wa.red)
                            .frame(width: 42, height: 42)
                            .background(wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L(c.phase == "pending" ? "cns_pending_home" : "cns_next_banner", c.client?.name ?? ""))
                                .font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                            Text(verbatim: "\(day) · \u{200E}\(time)").font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        Image(systemName: "chevron.forward").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 20, height: 20)
                    }
                    .padding(14)
                    .settingsSurface(20)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
        }
        .task(id: network.reconnectTick) { await load() }
        .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
    }

    private func load() async {
        // A request waiting for my answer comes first.
        if let list = await ConsultationsApi.list(tab: "upcoming", perPage: 10).value { next = list.first { $0.phase == "pending" } ?? list.first }
    }

    private static func format(_ date: Date, _ pattern: String, _ locale: Locale) -> String {
        let f = DateFormatter()
        f.calendar = Calendar(identifier: .gregorian)
        f.locale = locale
        f.dateFormat = pattern
        return f.string(from: date)
    }
}

/// FL-L04: the orders waiting for my answer, on top of the home — shown only when there are some. Read when the home opens, when the
/// connection returns and when a notice arrives (a new order is one), never on a timer.
@MainActor
private struct WaitingOrdersBanner: View {
    @EnvironmentObject private var network: NetworkMonitor
    let onOpen: () -> Void
    @State private var waiting = 0

    var body: some View {
        Group {
            if waiting > 0 { OrdersBanner(count: waiting, onOpen: onOpen) }
        }
        .task(id: network.reconnectTick) { await load() }
        .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
    }

    private func load() async {
        if let list = await ServicesApi.orders(status: "awaiting", perPage: 50).value { waiting = list.count }
    }
}
