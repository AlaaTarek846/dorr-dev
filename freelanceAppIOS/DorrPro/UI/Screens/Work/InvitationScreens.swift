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
        HStack(spacing: 12) {
            SettingsPrimaryButton(text: L("inv_accept"), loading: busy) { answer(true) }
                .frame(maxWidth: .infinity)
            Button { answer(false) } label: {
                Text(L("inv_decline")).font(.dorr(14, .bold)).foregroundStyle(Color(hex: 0xDC2626)).padding(12)
            }
            .buttonStyle(.plain)
            .disabled(busy)
        }
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

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var filter: String?
    @StateObject private var paged = PagedRows<InvitationDto>(perPage: 15, key: { $0.uuid ?? "" })
    @State private var error: String?

    var body: some View {
        Page(title: L("inv_title"), onBack: onBack, onNearEnd: { Task { await paged.loadMore() } }) {
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
                ForEach(list) { i in invitationCard(i) }
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

    private func invitationCard(_ i: InvitationDto) -> some View {
        Card {
            HStack(spacing: 0) {
                Image(systemName: "envelope.badge.fill").font(.system(size: 18)).foregroundStyle(wa.red)
                    .frame(width: 42, height: 42)
                    .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                Spacer().frame(width: 12)
                Text(verbatim: i.project?.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    .frame(maxWidth: .infinity, alignment: .leading)
                Spacer().frame(width: 8)
                Text(verbatim: invitationStatusLabel(i.status ?? "sent")).font(.dorr(11, .bold)).foregroundStyle(wa.red)
                    .padding(.horizontal, 10).padding(.vertical, 4)
                    .background(wa.red.opacity(0.12), in: Capsule())
            }
            .contentShape(Rectangle())
            .onTapGesture { if let uuid = i.project?.uuid { onOpenProject(uuid) } }
            if let summary = i.project?.summary, !summary.trimmingCharacters(in: .whitespaces).isEmpty {
                Text(verbatim: summary).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineLimit(3)
            }
            if i.open != true && (i.status ?? "sent") == "sent" {
                Text(L("inv_closed")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            if i.can?.accept == true, let uuid = i.uuid {
                InvitationAnswer(
                    uuid: uuid,
                    onAnswered: { s in
                        paged.update(where: { $0.uuid == uuid }) { row in
                            var copy = row
                            copy.status = s
                            copy.can = InvitationCanDto(accept: false, decline: false, offer: s == "accepted" && row.offered != true)
                            return copy
                        }
                    },
                    onError: { error = $0 }
                )
            }
            if i.can?.offer == true {
                Button { if let uuid = i.project?.uuid { onOpenProject(uuid) } } label: {
                    Text(L("inv_send_offer")).font(.dorr(13, .bold)).foregroundStyle(wa.red).padding(.vertical, 4)
                }
                .buttonStyle(.plain)
            }
        }
    }
}
