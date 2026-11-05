// providerApp `ui/screens/TicketScreens.kt`
import SwiftUI

/// The customers' tickets on the establishment's moves (C-04, `tickets.handle`), newest first.
@MainActor
struct TicketsScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpenTicket: (String) -> Void
    @StateObject private var paged = PagedRows<TicketDto>(
        perPage: 20, key: { $0.uuid }, fetch: { page in await MovingApi.tickets(page: page) }
    )

    var body: some View {
        Page(title: L("mv_tickets_title"), onBack: onBack, onNearEnd: { Task { await paged.loadMore() } }) {
            if !paged.loaded {
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
            } else if paged.rows.isEmpty {
                EmptyState(icon: "ticket.fill", title: L("mv_tickets_empty_title"), body: L("mv_tickets_empty_body")).padding(.top, 30)
            } else {
                ForEach(paged.rows) { ticket in
                    RowCard(
                        icon: "ticket.fill",
                        title: notBlank(ticket.subject) ?? L("mv_ticket"),
                        subtitle: dateTimeText(ticket.createdAt),
                        pill: AnyView(StatusPill(text: ticketStatusLabel(ticket.status), color: ticketStatusColor(ticket.status))),
                        onClick: { onOpenTicket(ticket.uuid) }
                    )
                }
            }
            PagedFooter(paged: paged) { Task { await paged.loadMore() } }
        }
        .messageToast(paged.failure?.errorText(L("login_error_offline")))
        .task(id: network.reconnectTick) { await paged.reload() }
        .onReceive(Realtime.shared.changed) { _ in Task { await paged.reload() } }
    }
}

/// One ticket: its messages (the customer's, ours and Dorr's) and our reply. Replying does not close it: the customer accepts the
/// answer, or Dorr decides after the waiting time — said under the field.
@MainActor
struct TicketScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let uuid: String
    let onBack: () -> Void
    let onOpenJob: (String) -> Void

    @State private var ticket: TicketDto?
    @State private var reply = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        Page(title: L("mv_ticket"), onBack: onBack) {
            if let current = ticket {
                Card {
                    HStack {
                        Text(verbatim: current.subject ?? L("mv_ticket")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        StatusPill(text: ticketStatusLabel(current.status), color: ticketStatusColor(current.status))
                    }
                    InfoLine(L("mv_opened_at"), dateTimeText(current.createdAt))
                    InfoLine(L("mv_escalation"), current.escalationReason)
                    if let job = current.requestUuid {
                        ProfileTextLink(text: L("mv_open_job")) { onOpenJob(job) }
                    }
                }
                ForEach(Array((current.messages ?? []).enumerated()), id: \.offset) { _, message in
                    MessageBubble(author: message.author, text: message.body ?? "", at: message.at)
                }
                if !["resolved", "unresolved"].contains(current.status ?? "") {
                    DorrTextField(value: $reply.profileTake(3000), label: L("mv_reply"), icon: "text.alignleft", singleLine: false, minLines: 3)
                    Text(L("mv_reply_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
                    SettingsPrimaryButton(text: L("mv_send_reply"), enabled: !reply.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty, loading: busy, action: send)
                }
            } else if error == nil {
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
            }
        }
        .messageToast(error)
        .task(id: network.reconnectTick) { await reload() }
        .onReceive(Realtime.shared.changed) { _ in Task { await reload() } }
    }

    private func reload() async {
        let result = await MovingApi.ticket(uuid)
        if let data = result.value {
            ticket = data
            error = nil
        } else {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    private func send() {
        busy = true
        error = nil
        let text = reply.trimmingCharacters(in: .whitespacesAndNewlines)
        Task {
            let result = await MovingApi.reply(uuid, body: text)
            busy = false
            if let data = result.value {
                ticket = data
                reply = ""
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}

/// One message: ours on the leading side in the app colour, the customer's and Dorr's in a card, each with who and when.
private struct MessageBubble: View {
    @Environment(\.wa) private var wa
    let author: String?
    let text: String
    let at: String?

    var body: some View {
        let ours = author == "provider"
        let who: String = {
            switch author {
            case "provider": return L("mv_author_us")
            case "admin": return L("mv_author_dorr")
            default: return L("mv_author_customer")
            }
        }()
        VStack(alignment: .leading, spacing: 4) {
            Text(verbatim: who).font(.dorr(11, .bold)).foregroundStyle(wa.red)
            Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(4)
            Text(verbatim: dateTimeText(at)).font(.dorr(10)).foregroundStyle(wa.settingsMut)
        }
        .padding(12)
        .frame(maxWidth: 320, alignment: .leading)
        .background(ours ? wa.red.opacity(0.12) : wa.settingsCard, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .frame(maxWidth: .infinity, alignment: ours ? .leading : .trailing)
    }
}
