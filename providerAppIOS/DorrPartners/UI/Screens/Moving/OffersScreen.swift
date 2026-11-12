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
                SkeletonList(count: 3)
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
        let color = offerStatusColor(offer.status)
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                MvVehicleImage(image: offer.vehicle?.type?.image, size: 30)
                    .frame(width: 40, height: 40)
                    .background(color.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: formatMinor(offer.priceMinor ?? 0, offer.currencyCode)).font(.dorr(19, .black)).foregroundStyle(wa.settingsInk)
                    if let type = offer.vehicle?.type?.name { Text(verbatim: type).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                StatusPill(text: offerStatusLabel(offer.status), color: color)
            }
            FlowLayout(spacing: 6) {
                if let arrival = notBlank(dateTimeText(offer.arrivalAt)) { MvChip(icon: "clock.fill", text: arrival) }
                if let workers = offer.workersCount, workers > 0 { MvChip(icon: "person.3.fill", text: L("mv_workers_n", workers), color: Slate) }
                if let trips = offer.tripsCount, trips > 1 { MvChip(icon: nil, text: L("mv_trips_n", trips), color: Slate) }
                if let branch = notBlank(offer.branch) { MvChip(icon: "building.2.fill", text: branch, color: Slate) }
                ForEach(offer.included().filter { $0 != "tax" }.sorted(), id: \.self) { key in MvChip(icon: includeIcon(key), text: includeLabel(key), color: Slate) }
            }
            InfoLine(L("mv_valid_until"), dateTimeText(offer.validUntil))
            if offer.status == "awaiting_approval" {
                Text(L("mv_awaiting_approval_hint")).font(.dorr(12)).foregroundStyle(Amber).lineSpacing(3)
            }
            if canManage || canApprove {
                HStack(spacing: 8) {
                    if canApprove { MvOutlineButton(text: L("mv_approve"), color: Green, busy: busy, action: onApprove) }
                    if canManage {
                        MvOutlineButton(text: L("common_edit"), color: wa.red, busy: busy, action: onEdit)
                        MvOutlineButton(text: L("mv_withdraw"), color: Red, busy: busy, action: onWithdraw)
                    }
                }
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(22)
        .contentShape(Rectangle())
        .onTapGesture { if let request = offer.requestUuid { onOpenRequest(request) } }
    }
}
