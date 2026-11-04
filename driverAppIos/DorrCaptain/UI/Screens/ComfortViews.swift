import SwiftUI

// driverApp `ui/ComfortViews.kt`.

/* Block 06 on the captain's request card and trip: the rider's «راحتي» (requests, never above safety) with
 * "can't do this" and why, and the next trip taken while finishing a ride. */

/// On the request card: offered while finishing a ride — it starts after that ride ends.
struct NextTripBadge: View {
    @Environment(\.pal) private var pal
    let next: NextTripOfferDto?

    init(_ next: NextTripOfferDto?) { self.next = next }

    var body: some View {
        if next != nil {
            HStack(spacing: 0) {
                DorrIcon("arrow.forward.circle.fill", size: 20, tint: pal.success)
                HSpace(8)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("nt_offer_title")).font(.dorr(13, .bold)).foregroundStyle(pal.success)
                    Text(L("nt_offer_body")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.7))
                }
                Spacer(minLength: 0)
            }
            .padding(10)
            .frame(maxWidth: .infinity)
            .background(RoundedRectangle(cornerRadius: 14).fill(pal.success.opacity(0.12)))
            .padding(.top, 8)
        }
    }
}

/// The rider's «راحتي», read-only (the request card) — or, with `onItem`, each item tappable (the trip).
struct ComfortChips: View {
    @Environment(\.pal) private var pal
    let comfort: ComfortDto?
    var unable: Set<String> = []
    var onItem: ((ComfortItemDto) -> Void)? = nil

    init(_ comfort: ComfortDto?, unable: Set<String> = [], onItem: ((ComfortItemDto) -> Void)? = nil) {
        self.comfort = comfort
        self.unable = unable
        self.onItem = onItem
    }

    var body: some View {
        if let c = comfort, let items = c.items, !items.isEmpty {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 0) {
                    DorrIcon("leaf.fill", size: 16, tint: pal.success)
                    HSpace(6)
                    Text(L("cf_title")).font(.dorr(13, .bold)).foregroundStyle(pal.ink)
                }
                VSpace(6)
                ComfortFlowLayout(spacing: 6) {
                    ForEach(Array(items.enumerated()), id: \.offset) { _, item in
                        chip(item)
                    }
                }
                if let disclaimer = c.disclaimer {
                    HStack(spacing: 0) {
                        DorrIcon("info.circle.fill", size: 13, tint: pal.ink.opacity(0.5))
                        HSpace(4)
                        Text(disclaimer).font(.dorr(11)).foregroundStyle(pal.ink.opacity(0.6))
                    }
                    .padding(.top, 6)
                }
            }
            .padding(10)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(RoundedRectangle(cornerRadius: 14).fill(pal.success.opacity(0.07)))
            .padding(.top, 10)
        }
    }

    @ViewBuilder
    private func chip(_ item: ComfortItemDto) -> some View {
        let off = unable.contains(item.item ?? "")
        let text = Text(item.label ?? "")
            .font(.dorr(12))
            .strikethrough(off)
            .foregroundStyle(off ? pal.ink.opacity(0.45) : pal.ink)
            .padding(.horizontal, 10)
            .padding(.vertical, 5)
            .background(Capsule().fill(pal.card))
        if let onItem, !off {
            text.bounceClick { onItem(item) }
        } else {
            text
        }
    }
}

/// On the trip: the same, and a tap on an item says it can't be done (with why) — the rider is told.
struct TripComfortCard: View {
    @Environment(\.pal) private var pal
    let trip: TripDto
    let onUpdated: (TripDto) -> Void
    @State private var asking: ComfortItemDto?
    @State private var error: String?

    init(_ trip: TripDto, onUpdated: @escaping (TripDto) -> Void) {
        self.trip = trip
        self.onUpdated = onUpdated
    }

    var body: some View {
        if let c = trip.comfort {
            let open = ["accepted", "arrived", "in_progress"].contains(trip.status ?? "")
            let unable = c.unable ?? []
            VStack(alignment: .leading, spacing: 0) {
                ComfortChips(c, unable: Set(unable.compactMap { $0.item }), onItem: open ? { asking = $0 } : nil)
                if open && unable.count < (c.items ?? []).count {
                    Text(L("cf_tap_hint")).font(.dorr(11)).foregroundStyle(pal.ink.opacity(0.55)).padding(.top, 4)
                }
                ForEach(Array(unable.enumerated()), id: \.offset) { _, u in
                    Text(L("cf_told", u.label ?? "", u.reasonLabel ?? ""))
                        .font(.dorr(12, .semibold)).foregroundStyle(pal.warning).padding(.top, 4)
                }
                if let error {
                    Text(error).font(.dorr(12)).foregroundStyle(pal.danger).padding(.top, 4)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .dorrDialog(isPresented: Binding(get: { asking != nil }, set: { if !$0 { asking = nil } })) {
                if let item = asking { unableDialog(item, c) }
            }
        }
    }

    /// Android's AlertDialog: the leaf, "Can't do «…»", why (one tap each), and Cancel.
    private func unableDialog(_ item: ComfortItemDto, _ c: ComfortDto) -> some View {
        VStack(spacing: 0) {
            DorrIcon("leaf.fill", size: 24, tint: pal.success)
            Text(L("cf_unable_title", item.label ?? ""))
                .font(.dorr(22)).foregroundStyle(pal.ink).multilineTextAlignment(.center)
                .padding(.top, 16)
            VStack(alignment: .leading, spacing: 6) {
                Text(L("cf_unable_body")).font(.dorr(13)).foregroundStyle(pal.mut)
                ForEach(Array((c.reasons ?? []).enumerated()), id: \.offset) { _, reason in
                    Text(reason.label ?? "")
                        .font(.dorr(14, .semibold)).foregroundStyle(pal.ink)
                        .padding(12)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(RoundedRectangle(cornerRadius: 12).fill(pal.ink.opacity(0.06)))
                        .bounceClick { choose(item, reason) }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.top, 16)
            HStack {
                Spacer(minLength: 0)
                Text(L("cf_cancel")).font(.dorr(14, .semibold)).foregroundStyle(pal.accent)
                    .padding(.horizontal, 12).padding(.vertical, 10)
                    .bounceClick { asking = nil }
            }
            .padding(.top, 20)
        }
        .padding(24)
        .background(RoundedRectangle(cornerRadius: 28).fill(pal.card))
        .padding(.horizontal, 24)
    }

    private func choose(_ item: ComfortItemDto, _ reason: ComfortReasonDto) {
        asking = nil
        guard let uuid = trip.uuid else { return }
        Task {
            let body: JsonBody = ["item": item.item, "reason": reason.value]
            switch await DriverApi.comfortUnable(uuid: uuid, body: body) {
            case let .ok(updated, _): onUpdated(updated)
            case let .failed(e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
        }
    }
}

/// On the ride being driven: the next trip already taken — who, where, what it pays. On that trip: it waits for this one.
struct NextTripCard: View {
    @Environment(\.pal) private var pal
    let trip: TripDto

    init(_ trip: TripDto) { self.trip = trip }

    var body: some View {
        VStack(spacing: 0) {
            if let next = trip.nextTrip {
                HStack(spacing: 0) {
                    DorrIcon("arrow.forward.circle.fill", size: 22, tint: pal.success)
                    HSpace(10)
                    VStack(alignment: .leading, spacing: 0) {
                        HStack(spacing: 0) {
                            Text(L("nt_card_title", next.rider ?? "")).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                            RiderTick(verified: next.riderVerified == true, size: 14)
                        }
                        if let address = next.pickup?.address {
                            Text(address).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.65)).lineLimit(1)
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Text(money(next.earningMinor, trip.currency)).font(.dorr(14, .heavy)).foregroundStyle(pal.success)
                }
                .padding(12)
                .frame(maxWidth: .infinity)
                .background(RoundedRectangle(cornerRadius: 14).fill(pal.success.opacity(0.1)))
                .padding(.top, 10)
            }
            if let waiting = trip.waitingForRide {
                HStack(spacing: 0) {
                    DorrIcon("arrow.forward.circle.fill", size: 20, tint: pal.warning)
                    HSpace(8)
                    Text(L("nt_waiting", waiting.number ?? "")).font(.dorr(13, .semibold)).foregroundStyle(pal.ink)
                    Spacer(minLength: 0)
                }
                .padding(12)
                .frame(maxWidth: .infinity)
                .background(RoundedRectangle(cornerRadius: 14).fill(pal.warning.opacity(0.12)))
                .padding(.top, 10)
            }
        }
    }
}

/// Compose's `FlowRow`: the chips in lines, wrapping to the next when one is full.
private struct ComfortFlowLayout: Layout {
    var spacing: CGFloat

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? .infinity
        let rows = arrange(width, subviews)
        let height = rows.map(\.height).reduce(0, +) + spacing * CGFloat(max(rows.count - 1, 0))
        let used = rows.map(\.width).max() ?? 0
        return CGSize(width: proposal.width ?? used, height: height)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var y = bounds.minY
        for row in arrange(bounds.width, subviews) {
            var x = bounds.minX
            for index in row.items {
                let size = subviews[index].sizeThatFits(.unspecified)
                subviews[index].place(at: CGPoint(x: x, y: y), anchor: .topLeading, proposal: ProposedViewSize(size))
                x += size.width + spacing
            }
            y += row.height + spacing
        }
    }

    private struct Row { var items: [Int] = []; var width: CGFloat = 0; var height: CGFloat = 0 }

    private func arrange(_ maxWidth: CGFloat, _ subviews: Subviews) -> [Row] {
        var rows: [Row] = []
        var current = Row()
        for (i, view) in subviews.enumerated() {
            let size = view.sizeThatFits(.unspecified)
            let next = current.items.isEmpty ? size.width : current.width + spacing + size.width
            if !current.items.isEmpty && next > maxWidth {
                rows.append(current)
                current = Row()
                current.items = [i]
                current.width = size.width
                current.height = size.height
            } else {
                current.items.append(i)
                current.width = next
                current.height = max(current.height, size.height)
            }
        }
        if !current.items.isEmpty { rows.append(current) }
        return rows
    }
}
