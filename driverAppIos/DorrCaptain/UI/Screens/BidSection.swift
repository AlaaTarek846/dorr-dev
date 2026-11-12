import SwiftUI

// driverApp `ui/BidSection.kt`.

/// A bidding request (docs/rides-plan.md §9.2): "Accept for <rider's price>" gives the ride at once;
/// or the driver offers more — +5 / +10 / +15 or their own number, never over the cap — and sees
/// what they'd earn as the price moves. While the rider decides, the card says so; declined or
/// timed out, they can offer again (even lower, still above the rider).
@MainActor
struct BidSection: View {
    @Environment(\.pal) private var pal
    let offer: OfferDto
    let onAccepted: () -> Void
    let onDecline: () -> Void

    @State private var price: Int
    @State private var busy = false
    @State private var error: String?

    init(offer: OfferDto, onAccepted: @escaping () -> Void, onDecline: @escaping () -> Void) {
        self.offer = offer
        self.onAccepted = onAccepted
        self.onDecline = onDecline
        _price = State(initialValue: BidSection.startPrice(offer))
    }

    private static func steps(_ bid: BidCardDto) -> [Int] { bid.stepsMinor ?? [500, 1000, 1500] }

    /// `remember(offer.uuid, rider) { rider + first step }`.
    private static func startPrice(_ offer: OfferDto) -> Int {
        guard let bid = offer.bid else { return 0 }
        return (bid.riderPriceMinor ?? 0) + (steps(bid).first ?? 500)
    }

    var body: some View {
        if let bid = offer.bid {
            content(bid)
                .onChange(of: "\(offer.uuid ?? "")|\(bid.riderPriceMinor ?? 0)") { _ in price = Self.startPrice(offer) }
        }
    }

    private func content(_ bid: BidCardDto) -> some View {
        let rider = bid.riderPriceMinor ?? 0
        let cap = bid.capMinor ?? 0
        let mine = bid.myBid
        let waiting = mine?.status == "pending" || mine?.status == "queued"
        return VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("bid_rider_price")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                    Text(money(rider, offer.currency)).font(.dorr(24, .heavy)).foregroundStyle(pal.ink)
                    Text(L("bid_net", money(bid.netAt(rider), offer.currency))).font(.dorr(12)).foregroundStyle(pal.success)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(14)
            .frame(maxWidth: .infinity)
            .background(RoundedRectangle(cornerRadius: 16).fill(pal.navy.opacity(0.06)))
            VSpace(10)

            if waiting {
                Text(L("bid_waiting", money(mine?.priceMinor, offer.currency)))
                    .font(.dorr(16, .bold)).foregroundStyle(pal.orange)
                    .padding(.vertical, 8)
            } else {
                switch mine?.status {
                case "declined": Text(L("bid_declined")).font(.dorr(13)).foregroundStyle(pal.danger)
                case "expired": Text(L("bid_expired")).font(.dorr(13)).foregroundStyle(pal.warning)
                default: EmptyView()
                }
                offerControls(bid, rider: rider, cap: cap)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .animation(.tween(300), value: mine?.status)
    }

    @ViewBuilder
    private func offerControls(_ bid: BidCardDto, rider: Int, cap: Int) -> some View {
        BigButton(L("bid_accept_at", money(rider, offer.currency)), color: pal.success, busy: busy) { send(rider) }
        VSpace(12)
        Text(L("bid_or_offer")).font(.dorr(16, .semibold)).foregroundStyle(pal.ink)
        HStack(spacing: 8) {
            ForEach(Self.steps(bid), id: \.self) { step in
                let target = min(rider + step, cap)
                let on = price == target
                Text("+" + money(step, symbolless(offer.currency)))
                    .font(.dorr(16, .bold)).foregroundStyle(on ? Color.white : pal.navy)
                    .padding(.horizontal, 14).padding(.vertical, 8)
                    .background(Capsule().fill(on ? pal.navy : Color.clear))
                    .overlay(Capsule().strokeBorder(pal.navy, lineWidth: 1))
                    .bounceClick { price = target }
            }
        }
        .padding(.vertical, 8)
        // Typed straight in, or − / + by the first step (5) — never one unit per tap (O-43).
        let step = Self.steps(bid).first ?? 500
        PriceInput(priceMinor: price, currency: offer.currency, stepMinor: step, minMinor: rider + 1, maxMinor: cap) { price = $0 }
        VStack(spacing: 0) {
            Text(L("bid_net", money(bid.netAt(price), offer.currency))).font(.dorr(12)).foregroundStyle(pal.success)
            Text(L("bid_max", money(cap, offer.currency)))
                .font(.dorr(11))
                .foregroundStyle(price > cap || price <= rider ? pal.danger : pal.ink.opacity(0.5))
        }
        .frame(maxWidth: .infinity)
        .padding(.top, 6)
        if let attempts = bid.attemptsLeft {
            Text(L("bid_attempts", attempts)).font(.dorr(11)).foregroundStyle(pal.ink).padding(.top, 4)
        }
        if let error {
            Text(error).font(.dorr(13)).foregroundStyle(pal.danger).padding(.top, 6)
        }
        VSpace(10)
        WaWeightedRow(spacing: 12) {
            Text(L("offer_decline")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                .frame(maxWidth: .infinity)
                .frame(height: 58)
                .background(RoundedRectangle(cornerRadius: 18).fill(pal.ink.opacity(0.08)))
                .bounceClick(enabled: !busy, onClick: onDecline)
                .layoutWeight(1)
            BigButton(L("bid_send", money(price, offer.currency)), color: pal.orange, enabled: price > rider && price <= cap, busy: busy) { send(price) }
                .layoutWeight(2)
        }
    }

    /// `currency?.copy(symbol = "")`.
    private func symbolless(_ currency: CurrencyDto?) -> CurrencyDto? {
        guard var c = currency else { return nil }
        c.symbol = ""
        return c
    }

    private func send(_ amount: Int) {
        guard let uuid = offer.uuid else { return }
        busy = true
        error = nil
        Task {
            switch await DriverApi.bid(key: UUID().uuidString.lowercased(), uuid: uuid, body: ["price_minor": amount]) {
            case let .ok(data, _):
                // The rider's price taken (or an offer the rider had already agreed to): the ride is mine.
                if data.isObject, data[snake: "ride"]?.isObject == true {
                    Duty.shared.clearOffer(uuid)
                    Duty.shared.setOnTrip(true)
                    onAccepted()
                }
            case let .failed(e):
                error = e.displayMessage
            case .offline:
                error = L("error_offline")
            }
            busy = false
        }
    }
}
