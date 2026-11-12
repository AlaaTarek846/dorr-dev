// freelanceApp `ui/screens/InvitationScreens.kt`
import SwiftUI

func invitationStatusLabel(_ status: String) -> String {
    switch status {
    case "accepted": return L("inv_s_accepted")
    case "declined": return L("inv_s_declined")
    default: return L("inv_s_sent")
    }
}

/// The yes / no buttons of an invitation; [onAnswered] gets the new status. Used on the list and on the project page.
struct InvitationAnswer: View {
    @Environment(\.wa) private var wa
    let uuid: String
    let onAnswered: (String) -> Void
    let onError: (String) -> Void
    @State private var busy = false

    var body: some View {
        let red = Color(hex: 0xDC2626)
        // Decline (outlined) and accept side by side, 1 : 1.4.
        GeometryReader { proxy in
            let free = max(proxy.size.width - 10, 0)
            HStack(spacing: 10) {
                Button { answer(false) } label: {
                    Text(L("inv_decline")).font(.dorr(15, .heavy)).foregroundStyle(red)
                        .frame(width: free / 2.4, height: 54)
                        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(red.opacity(0.45), lineWidth: 1.5))
                        .contentShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
                }
                .buttonStyle(.plain)
                .disabled(busy)
                SettingsPrimaryButton(text: L("inv_accept"), loading: busy) { answer(true) }
                    .frame(width: free * 1.4 / 2.4)
            }
        }
        .frame(height: 54)
        .frame(maxWidth: .infinity)
    }

    private func answer(_ accept: Bool) {
        busy = true
        Task {
            let result = accept ? await InvitationsApi.accept(uuid) : await InvitationsApi.decline(uuid)
            if let data = result.value { onAnswered(data.status ?? "sent") } else { onError(result.errorText(L("login_error_offline")) ?? L("login_error_generic")) }
            busy = false
        }
    }
}

/// My invitations (FL-205), fifteen at a time while I scroll. Read when shown, when a notice arrives and when the connection comes
/// back - nothing on a timer.
struct InvitationsScreen: View {
    let onBack: () -> Void
    let onOpenProject: (String) -> Void
    var embedded = false

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var filter: String?
    @StateObject private var paged = PagedRows<InvitationDto>(perPage: 15, key: { $0.uuid ?? "" })
    @State private var error: String?

    var body: some View {
        Page(title: L("inv_title"), onBack: onBack, onNearEnd: { Task { await paged.loadMore() } }, embedded: embedded) {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach([String?.none, "sent", "accepted", "declined"], id: \.self) { f in
                        let icon: String = {
                            switch f { case "sent": return "hourglass"; case "accepted": return "checkmark.circle.fill"; case "declined": return "xmark.circle.fill"; default: return "square.grid.2x2.fill" }
                        }()
                        let label: String = {
                            switch f { case "sent": return L("inv_s_sent"); case "accepted": return L("inv_s_accepted"); case "declined": return L("inv_s_declined"); default: return L("work_all") }
                        }()
                        ProjectChip(text: label, selected: filter == f, icon: icon) { filter = f }
                    }
                }
            }
            let list: [InvitationDto]? = paged.loaded ? paged.rows : nil
            if list == nil && paged.failure == nil {
                SkeletonList()
            } else if let list, list.isEmpty, paged.failure == nil {
                EmptyState(
                    icon: "envelope.badge.fill",
                    title: L(filter == nil ? "inv_empty_title" : "inv_empty_filtered_title"),
                    body: L(filter == nil ? "inv_empty_body" : "inv_empty_filtered_body")
                )
                .padding(.top, 110)
            } else if let list {
                ForEach(list) { i in
                    InvitationCard(
                        i: i, onOpen: { if let uuid = i.project?.uuid { onOpenProject(uuid) } },
                        onAnswered: { s in
                            paged.update(where: { $0.uuid == i.uuid }) { row in
                                var copy = row
                                copy.status = s
                                copy.can = InvitationCanDto(accept: false, decline: false, offer: s == "accepted" && row.offered != true)
                                return copy
                            }
                            // The tab badge and the counts follow the answer (one read, not a timer).
                            Task { await HomeHub.shared.refresh() }
                        },
                        onError: { error = $0 }
                    )
                }
            }
            PagedFooter(paged: paged) { Task { await paged.loadMore() } }
        }
        .messageToast(error ?? paged.failure.map { $0.errorText(L("login_error_offline")) ?? L("login_error_generic") })
        .task(id: "\(network.reconnectTick)|\(filter ?? "")") {
            let status = filter
            paged.reset { page in await InvitationsApi.list(page: page, perPage: 15, status: status) }
            await paged.reload()
        }
        .onReceive(Realtime.shared.changed) { _ in Task { await paged.reload() } }
    }
}

/// One invitation: who it is from (a private project), its status, the project's title and short text with the budget and the days,
/// then the answer buttons while it waits, or the step to send my offer once I said yes.
private struct InvitationCard: View {
    @Environment(\.wa) private var wa
    let i: InvitationDto
    let onOpen: () -> Void
    let onAnswered: (String) -> Void
    let onError: (String) -> Void

    var body: some View {
        let blue = Color(hex: 0x3B82F6)
        let status = i.status ?? "sent"
        let tint: Color = status == "accepted" ? Color(hex: 0x16A34A) : status == "declined" ? Color(hex: 0xDC2626) : blue
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 0) {
                Image(systemName: i.team != nil ? "person.3.fill" : "envelope.badge.fill").font(.system(size: 16)).foregroundStyle(blue)
                    .frame(width: 38, height: 38)
                    .background(blue.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                Spacer().frame(width: 10)
                // FS-213: the client invited my team - the offer goes in its name.
                Text(verbatim: i.team.map { L("inv_team", $0.name ?? "") } ?? L("inv_private"))
                    .font(.dorr(12, .bold)).foregroundStyle(i.team != nil ? blue : wa.settingsMut).lineLimit(1)
                    .frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: invitationStatusLabel(status)).font(.dorr(11, .bold)).foregroundStyle(tint)
                    .padding(.horizontal, 10).padding(.vertical, 4)
                    .background(tint.opacity(0.12), in: Capsule())
            }
            Text(verbatim: i.project?.title ?? "").font(.dorr(15.5, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2).lineSpacing(3)
            if let summary = i.project?.summary, !summary.trimmingCharacters(in: .whitespaces).isEmpty {
                Text(verbatim: summary).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4).lineLimit(2)
            }
            if let p = i.project {
                HStack(spacing: 14) {
                    if let budget = budgetText(p.budget) { FactLine(icon: "banknote.fill", text: budget, strong: true) }
                    if let days = p.durationDays { FactLine(icon: "clock", text: L("projects_days", days)) }
                }
            }
            if i.open != true && status == "sent" {
                Text(L("inv_closed")).font(.dorr(12, .semibold)).foregroundStyle(Color(hex: 0xD97706))
            }
            if i.can?.accept == true, let uuid = i.uuid { InvitationAnswer(uuid: uuid, onAnswered: onAnswered, onError: onError) }
            if i.can?.offer == true { SettingsPrimaryButton(text: L("inv_send_offer"), action: onOpen) }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20)
        .contentShape(Rectangle())
        .onTapGesture(perform: onOpen)
    }
}
