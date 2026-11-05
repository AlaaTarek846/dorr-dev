// providerApp `ui/screens/OffersScreen.kt`
import SwiftUI

/// The establishment's offers (C-04): all of them, or those waiting for the approver (`offers.approve`) — an offer above its sender's
/// limit waits there (§17). An offer the customer hasn't answered can be changed or taken back (`offers.manage`). Read on open, when
/// the connection returns and on every notice.
@MainActor
struct OffersScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var session = Session.shared
    let onBack: () -> Void
    let onOpenRequest: (String) -> Void

    @State private var filter: String
    @State private var editing: MovingOfferDto?
    @State private var withdrawing: MovingOfferDto?
    @State private var busy = false
    @State private var error: String?
    @State private var info: String?
    @StateObject private var paged = PagedRows<MovingOfferDto>(perPage: 20, key: { $0.uuid })

    init(startOnApproval: Bool, onBack: @escaping () -> Void, onOpenRequest: @escaping (String) -> Void) {
        self.onBack = onBack
        self.onOpenRequest = onOpenRequest
        _filter = State(initialValue: startOnApproval ? "awaiting_approval" : "")
    }

    var body: some View {
        ZStack {
            if let offer = editing {
                OfferForm(move: nil, offer: offer, onBack: { editing = nil }, onDone: { message in
                    info = message
                    editing = nil
                    Task { await paged.reload() }
                })
            } else {
                list
            }
        }
        .task(id: "\(network.reconnectTick)|\(filter)") {
            let status = filter.isEmpty ? nil : filter
            paged.reset { page in await MovingApi.offers(status: status, page: page) }
            await paged.reload()
        }
        .onReceive(Realtime.shared.changed) { _ in Task { await paged.reload() } }
    }

    private var list: some View {
        Page(title: L("mv_offers_title"), onBack: onBack, onNearEnd: { Task { await paged.loadMore() } }) {
            if session.membership.can("offers.approve") {
                ChipRow(
                    options: [(value: "", label: L("mv_filter_all")), (value: "awaiting_approval", label: L("mv_offer_awaiting_approval"))],
                    selected: [filter],
                    onToggle: { filter = $0 }
                )
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            if let info {
                Text(verbatim: info).font(.dorr(13, .semibold)).foregroundStyle(Green).frame(maxWidth: .infinity, alignment: .leading)
            }
            if !paged.loaded {
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
            } else if paged.rows.isEmpty {
                EmptyState(icon: "doc.text.fill", title: L("mv_offers_empty_title"), body: L("mv_offers_empty_body")).padding(.top, 30)
            } else {
                ForEach(paged.rows) { offer in
                    OfferCard(
                        offer: offer, busy: busy,
                        canManage: session.membership.can("offers.manage") && ["sent", "awaiting_approval"].contains(offer.status ?? ""),
                        canApprove: session.membership.can("offers.approve") && offer.status == "awaiting_approval",
                        onEdit: { editing = offer },
                        onWithdraw: { withdrawing = offer },
                        onApprove: { approve(offer) },
                        onOpenRequest: onOpenRequest
                    )
                }
            }
            PagedFooter(paged: paged) { Task { await paged.loadMore() } }
        }
        .messageToast(error ?? paged.failure?.errorText(L("login_error_offline")))
        .dorrDialog(isPresented: Binding(get: { withdrawing != nil }, set: { if !$0 && !busy { withdrawing = nil } })) {
            if let offer = withdrawing {
                ConfirmDialog(icon: "arrow.uturn.backward", title: L("mv_withdraw_title"), message: L("mv_withdraw_message"), loading: busy,
                              confirmLabel: L("mv_withdraw"), onConfirm: { withdraw(offer) }, onDismiss: { if !busy { withdrawing = nil } })
            }
        }
    }

    private func approve(_ offer: MovingOfferDto) {
        busy = true
        error = nil
        Task {
            let result = await MovingApi.approveOffer(offer.uuid)
            busy = false
            if result.value != nil {
                info = L("mv_offer_approved_ok")
                await paged.reload()
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    private func withdraw(_ offer: MovingOfferDto) {
        busy = true
        Task {
            let failed = await MovingApi.withdrawOffer(offer.uuid, offline: L("login_error_offline"), generic: L("login_error_generic"))
            busy = false
            withdrawing = nil
            if let failed { error = failed } else { await paged.reload() }
        }
    }
}

private struct OfferCard: View {
    @Environment(\.wa) private var wa
    let offer: MovingOfferDto
    let busy: Bool
    let canManage: Bool
    let canApprove: Bool
    let onEdit: () -> Void
    let onWithdraw: () -> Void
    let onApprove: () -> Void
    let onOpenRequest: (String) -> Void

    var body: some View {
        let includes = offer.included()
        Card {
            HStack {
                Text(verbatim: formatMinor(offer.priceMinor ?? 0, offer.currencyCode)).font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk)
                    .frame(maxWidth: .infinity, alignment: .leading)
                StatusPill(text: offerStatusLabel(offer.status), color: offerStatusColor(offer.status))
            }
            InfoLine(L("mv_arrival"), dateTimeText(offer.arrivalAt))
            InfoLine(L("mv_valid_until"), dateTimeText(offer.validUntil))
            InfoLine(L("mv_offer_vehicle"), offer.vehicle?.type?.name)
            InfoLine(L("mv_workers"), offer.workersCount.map { String($0) })
            InfoLine(L("mv_trips"), offer.tripsCount.map { String($0) })
            InfoLine(L("mv_branch"), offer.branch)
            if !includes.isEmpty {
                Text(verbatim: includes.sorted().map { includeLabel($0) }.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            if offer.status == "awaiting_approval" {
                Text(L("mv_awaiting_approval_hint")).font(.dorr(12)).foregroundStyle(Amber).lineSpacing(3)
            }
            if let request = offer.requestUuid {
                ProfileTextLink(text: L("mv_open_request")) { onOpenRequest(request) }
            }
            if canManage || canApprove {
                HStack(spacing: 20) {
                    if canApprove { colouredLink(L("mv_approve"), Green, onApprove) }
                    if canManage {
                        ProfileTextLink(text: L("common_edit"), enabled: !busy, action: onEdit)
                        colouredLink(L("mv_withdraw"), Red, onWithdraw)
                    }
                }
            }
        }
    }

    private func colouredLink(_ text: String, _ color: Color, _ action: @escaping () -> Void) -> some View {
        Button(action: action) { Text(verbatim: text).font(.dorr(14, .bold)).foregroundStyle(color) }
            .buttonStyle(.plain)
            .disabled(busy)
    }
}
