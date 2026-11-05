// providerApp `ui/screens/HomeScreen.kt`
import SwiftUI

/// The Home tab, in the look of the DORR app home (under the shared header of `AppHeader`): where the establishment stands
/// (draft, under review, approved, refused with the reason, suspended), the papers still missing with the way to upload them,
/// sending it for review, the invitations waiting for me, and the moving work (C-04): a tile for each number the seat may see.
@MainActor
struct HomeScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var session = Session.shared
    @ObservedObject private var workspace = Workspace.shared
    let establishment: EstablishmentDto?
    let failed: String?
    let actions: MainActions
    let onReload: () async -> Void

    // The papers are the owner's (the server refuses them to anyone else), read with the home and after any notice.
    @State private var docs: DocumentsDto?
    @State private var busy = false
    @State private var error: String?
    @State private var info: String?

    private var isOwner: Bool { session.membership?.isOwner == true }
    private var status: String? { establishment?.status }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                let invitations = workspace.state?.invitations ?? []
                if !invitations.isEmpty {
                    BannerRow(icon: "envelope.badge.fill", text: L("home_invitations_banner", invitations.count), onClick: actions.openInvitations)
                }

                if let establishment {
                    StatusCard(establishment: establishment)

                    // What is still missing before the review, and the way there (the owner's part).
                    let required = docs?.required ?? []
                    let missing = docs?.missing ?? []
                    let missingNames = required.filter { missing.contains($0.code ?? "") }.map { $0.name ?? "" }
                    if isOwner && (status == "draft" || status == "rejected") {
                        PapersCard(missing: missingNames, hasRequired: !required.isEmpty, busy: busy, onOpen: actions.openDocuments, onSubmit: submit)
                    } else if isOwner {
                        BannerRow(icon: "doc.text.fill", text: L("home_papers_open"), onClick: actions.openDocuments)
                    }

                    if let services = establishment.services, !services.isEmpty { ServicesRow(services: services) }

                    // The moving work (C-04): a tile for each number the seat may see.
                    MovingDashboard(actions: actions)
                } else if failed == nil {
                    SkeletonCard()
                    SkeletonCard()
                }
                if let info { Text(verbatim: info).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                Spacer().frame(height: 20)
            }
            .padding(.horizontal, 20)
            .padding(.top, 14)
        }
        .messageToast(error ?? failed)
        .task(id: "\(network.reconnectTick)-\(isOwner)-\(status ?? "")") { await loadDocs() }
        .onReceive(Realtime.shared.changed) { _ in Task { await loadDocs() } }
    }

    private func loadDocs() async {
        if isOwner, let data = await WorkspaceApi.documents().value { docs = data }
    }

    private func submit() {
        busy = true
        error = nil
        info = nil
        Task {
            let result = await WorkspaceApi.submit()
            busy = false
            if result.value != nil { info = L("pd_submitted_ok") } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
            await onReload()
            await loadDocs()
        }
    }
}

/// The establishment, its type and where its review stands; the reason when it was refused or suspended.
private struct StatusCard: View {
    @Environment(\.wa) private var wa
    let establishment: EstablishmentDto

    var body: some View {
        let status = establishment.status
        let tone = establishmentStatusColor(status)
        let icon: String = {
            switch status {
            case "approved": return "checkmark.seal.fill"
            case "under_review": return "hourglass"
            case "rejected", "suspended": return "exclamationmark.triangle.fill"
            default: return "doc.text.fill"
            }
        }()
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 12) {
                Image(systemName: establishment.type == "company" ? "building.2.fill" : "person.fill")
                    .font(.system(size: 20, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 44, height: 44)
                    .background(wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: establishment.name ?? "").font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Text(verbatim: establishmentTypeLabel(establishment.type)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                Spacer(minLength: 0)
            }
            HStack(spacing: 8) {
                Image(systemName: icon).font(.system(size: 17, weight: .semibold)).foregroundStyle(tone)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: establishmentStatusLabel(status)).font(.dorr(14, .heavy)).foregroundStyle(tone)
                    Text(verbatim: statusHint(status)).font(.dorr(12)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                    if status == "rejected" || status == "suspended",
                       let reason = establishment.statusReason, !reason.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(L("pd_reason", reason)).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                    }
                }
                Spacer(minLength: 0)
            }
            .padding(12)
            .background(tone.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20)
    }

    private func statusHint(_ status: String?) -> String {
        switch status {
        case "draft": return L("home_status_draft_hint")
        case "under_review": return L("home_status_review_hint")
        case "approved": return L("home_status_approved_hint")
        case "rejected": return L("home_status_rejected_hint")
        case "suspended": return L("home_status_suspended_hint")
        default: return ""
        }
    }
}

/// The papers still missing (by name), the page to upload them, and sending the establishment for review once none is missing.
private struct PapersCard: View {
    @Environment(\.wa) private var wa
    let missing: [String]
    let hasRequired: Bool
    let busy: Bool
    let onOpen: () -> Void
    let onSubmit: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                Image(systemName: "doc.text.fill").font(.system(size: 18, weight: .semibold)).foregroundStyle(wa.red)
                Text(L("home_papers_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                Spacer(minLength: 0)
            }
            if !missing.isEmpty {
                Text(L("home_papers_missing")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                ForEach(Array(missing.enumerated()), id: \.offset) { _, name in
                    Text(verbatim: "• " + name).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                }
            } else if hasRequired {
                Text(L("home_papers_complete")).font(.dorr(13, .semibold)).foregroundStyle(Color(hex: 0x16A34A))
            } else {
                Text(L("pd_none_required")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
            }
            ProfileTextLink(text: L("home_papers_open"), action: onOpen)
            SettingsPrimaryButton(text: L("pd_submit"), enabled: missing.isEmpty, loading: busy, action: onSubmit)
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20)
    }
}

/// The services the establishment registered in, with their images.
private struct ServicesRow: View {
    @Environment(\.wa) private var wa
    let services: [EstablishmentServiceDto]

    var body: some View {
        Text(L("home_services_title")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
        FlowLayout(spacing: 8) {
            ForEach(Array(services.enumerated()), id: \.offset) { _, service in
                HStack(spacing: 6) {
                    if let image = Api.mediaUrl(service.image) {
                        AsyncImage(url: image) { phase in
                            if let loaded = phase.image { loaded.resizable().scaledToFit() } else { Color.clear }
                        }
                        .frame(width: 24, height: 24)
                    }
                    Text(verbatim: service.name ?? "").font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk)
                }
                .padding(.leading, 6)
                .padding(.trailing, 12)
                .padding(.vertical, 5)
                .background(wa.settingsCard, in: Capsule())
                .overlay(Capsule().stroke(wa.night ? wa.line : Color(hex: 0xE5E7EB), lineWidth: 1))
            }
        }
    }
}

/// The numbers of the moving work (`moving/dashboard`): a number the seat's permissions don't cover is null and its tile doesn't show.
@MainActor
private struct MovingDashboard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var session = Session.shared
    let actions: MainActions
    @State private var numbers: MovingDashboardDto?

    private struct Tile {
        let icon: String
        let count: Int
        let label: String
        let open: () -> Void
    }

    private var tiles: [Tile] {
        guard let d = numbers else { return [] }
        var out: [Tile] = []
        if let n = d.newRequests { out.append(Tile(icon: "tray.fill", count: n, label: L("dash_new_requests"), open: actions.openRequests)) }
        if let n = d.offersAwaitingApproval { out.append(Tile(icon: "doc.text.fill", count: n, label: L("dash_offers_approval"), open: { actions.openOffers(true) })) }
        if let n = d.jobsUnassigned { out.append(Tile(icon: "person.crop.circle.badge.checkmark", count: n, label: L("dash_jobs_unassigned"), open: { actions.openJobs(true) })) }
        if let n = d.jobsRunning { out.append(Tile(icon: "box.truck.fill", count: n, label: L("dash_jobs_running"), open: { actions.openJobs(false) })) }
        if let n = d.jobsToday { out.append(Tile(icon: "calendar", count: n, label: L("dash_jobs_today"), open: { actions.openJobs(false) })) }
        if let n = d.myJobs { out.append(Tile(icon: "briefcase.fill", count: n, label: L("dash_my_jobs"), open: actions.openMyJobs)) }
        if let n = d.openTickets { out.append(Tile(icon: "ticket.fill", count: n, label: L("dash_open_tickets"), open: actions.openTickets)) }
        return out
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            if numbers != nil {
                let shown = tiles
                Text(L("home_requests_title")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                LazyVGrid(columns: [GridItem(.flexible(), spacing: 8, alignment: .top), GridItem(.flexible(), spacing: 8, alignment: .top)], spacing: 8) {
                    ForEach(Array(shown.enumerated()), id: \.offset) { _, tile in
                        CountTile(icon: tile.icon, count: tile.count, label: tile.label, onClick: tile.open)
                    }
                }
                // The offers themselves (no number on the dashboard): for whoever sees the requests.
                if session.membership.can("requests.view") {
                    BannerRow(icon: "doc.text.fill", text: L("dash_my_offers"), onClick: { actions.openOffers(false) })
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .task(id: "\(network.reconnectTick)-\(session.membership?.uuid ?? "")") { await load() }
        .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
    }

    private func load() async {
        if let data = await MovingApi.dashboard().value { numbers = data }
    }
}

/// A one-line notice on the home that opens a page.
private struct BannerRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 16, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 38, height: 38)
                    .background(wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                Text(verbatim: text).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                Image(systemName: "chevron.forward").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut)
            }
            .padding(14)
            .settingsSurface(20)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}
