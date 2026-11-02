// freelanceApp `ui/screens/MyWorkScreen.kt`
import SwiftUI

private let groups = ["all", "active", "completed", "disputes", "closed"]

/// My work (a tab): the projects I was chosen for - the ones I am working on, the finished ones and the ones that ended without being done -
/// newest first, fifteen at a time while I scroll. Read when the tab opens, when a notice arrives and when the connection returns.
@MainActor
struct MyWorkTab: View {
    let onBack: () -> Void
    let onOpenProject: (String) -> Void

    @EnvironmentObject private var network: NetworkMonitor
    @State private var group = "all"
    @StateObject private var paged = PagedRows<MyProjectDto>(perPage: 15, key: { $0.uuid ?? "" })

    var body: some View {
        TabPage(title: L("work_title"), onBack: onBack, onNearEnd: { Task { await paged.loadMore() } }) {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(groups, id: \.self) { g in
                        ProjectChip(text: L(workGroupLabel(g)), selected: group == g, icon: workGroupIcon(g)) { group = g }
                    }
                }
            }
            if !paged.loaded && paged.failure == nil {
                SkeletonList()
            } else if paged.loaded && paged.rows.isEmpty && paged.failure == nil {
                EmptyState(icon: "hammer.fill", title: L(workEmptyTitle(group)), body: L(workEmptyBody(group))).padding(.top, 90)
            } else {
                ForEach(paged.rows) { p in
                    MyProjectCard(p: p) { if let uuid = p.project?.uuid { onOpenProject(uuid) } }
                }
            }
            PagedFooter(paged: paged) { Task { await paged.loadMore() } }
        }
        .messageToast(paged.failure.map { $0.errorText(L("login_error_offline")) ?? L("login_error_generic") })
        .task(id: "\(network.reconnectTick)|\(group)") {
            let g = group
            paged.reset { page in await AgreementsApi.myProjects(page: page, perPage: 15, group: g) }
            await paged.reload()
        }
        .onReceive(Realtime.shared.changed) { _ in Task { await paged.reload() } }
    }
}

private func workGroupIcon(_ group: String) -> String {
    switch group {
    case "active": return "play.circle.fill"
    case "completed": return "checkmark.circle.fill"
    case "disputes": return "hammer.fill"
    case "closed": return "nosign"
    default: return "square.grid.2x2.fill"
    }
}

private func workGroupLabel(_ group: String) -> String {
    switch group {
    case "all": return "work_all"
    case "disputes": return "work_disputes"
    case "completed": return "work_completed"
    case "closed": return "work_closed"
    default: return "work_active"
    }
}

private func workEmptyTitle(_ group: String) -> String {
    switch group {
    case "all": return "work_empty_all_title"
    case "disputes": return "work_empty_disputes_title"
    case "completed": return "work_empty_completed_title"
    case "closed": return "work_empty_closed_title"
    default: return "work_empty_active_title"
    }
}

private func workEmptyBody(_ group: String) -> String {
    switch group {
    case "all": return "work_empty_all_body"
    case "disputes": return "work_empty_disputes_body"
    case "completed": return "work_empty_completed_body"
    case "closed": return "work_empty_closed_body"
    default: return "work_empty_active_body"
    }
}

private func workStatusColor(_ status: String) -> Color {
    switch status {
    case "active": return Color(hex: 0x2563EB)
    case "completed": return Color(hex: 0x16A34A)
    case "pending_approval": return Color(hex: 0xD97706)
    default: return Color(hex: 0x6B7280)
    }
}

/// `statusLabel` of MyWorkScreen.kt (private there; named apart from the account status label of HomeScreen).
private func workStatusLabel(_ status: String) -> String {
    switch status {
    case "active": return L("work_status_active")
    case "completed": return L("work_status_completed")
    case "pending_approval": return L("work_status_pending")
    case "declined": return L("work_status_declined")
    case "cancelled": return L("work_status_cancelled")
    default: return L("work_status_expired")
    }
}

private struct MyProjectCard: View {
    @Environment(\.wa) private var wa
    let p: MyProjectDto
    let onClick: () -> Void

    var body: some View {
        let status = p.status ?? ""
        let tint = workStatusColor(status)
        let red = Color(hex: 0xDC2626)
        let count = p.milestonesCount ?? 0
        let done = p.milestonesDone ?? 0
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 12) {
                HStack(spacing: 0) {
                    Image(systemName: "hammer.fill").font(.system(size: 18)).foregroundStyle(tint)
                        .frame(width: 42, height: 42)
                        .background(tint.opacity(0.12), in: RoundedRectangle(cornerRadius: 13, style: .continuous))
                    Spacer().frame(width: 10)
                    VStack(alignment: .leading, spacing: 4) {
                        Text(verbatim: p.project?.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                        HStack(spacing: 6) {
                            Text(verbatim: workStatusLabel(status)).font(.dorr(11, .bold)).foregroundStyle(tint)
                                .padding(.horizontal, 10).padding(.vertical, 3)
                                .background(tint.opacity(0.12), in: Capsule())
                            if p.hasDispute == true {
                                HStack(spacing: 4) {
                                    Image(systemName: "hammer.fill").font(.system(size: 10))
                                    Text(L("work_dispute_badge")).font(.dorr(11, .bold))
                                }
                                .foregroundStyle(red)
                                .padding(.horizontal, 8).padding(.vertical, 3)
                                .background(red.opacity(0.12), in: Capsule())
                            }
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 20, height: 20)
                }
                HStack(spacing: 8) {
                    OfferStat(value: "\u{200E}\(formatMinor(p.priceMinor ?? 0, nil)) \(p.currency ?? "")", label: L("offer_stat_price")).frame(maxWidth: .infinity)
                    OfferStat(value: L("projects_days", p.durationDays ?? 0), label: L("offer_stat_time")).frame(maxWidth: .infinity)
                }
                if count > 0 {
                    let fraction = CGFloat(done) / CGFloat(count)
                    VStack(spacing: 6) {
                        HStack(spacing: 0) {
                            Text(L("work_parts_done", done, count)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                            Text(verbatim: "\u{200E}\(Int(fraction * 100))%").font(.dorr(11.5, .heavy)).foregroundStyle(tint)
                        }
                        GeometryReader { proxy in
                            ZStack(alignment: .leading) {
                                Capsule().fill(wa.settingsMut.opacity(0.18))
                                Capsule().fill(tint).frame(width: proxy.size.width * min(max(fraction, 0), 1))
                            }
                        }
                        .frame(height: 6)
                    }
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .settingsSurface(20)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}
