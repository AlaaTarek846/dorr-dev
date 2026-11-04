import SwiftUI

// driverApp `ui/TipViews.kt` — the tips: their own card on the earnings (D-204) and the banner on the trip's receipt (D-182).

/// D-204: the tips of the chosen period on their own card — all of them the captain's, no commission.
/// The amount counts up when the period changes.
struct TipsCard: View {
    @Environment(\.pal) private var pal
    let tipsMinor: Int
    let currency: CurrencyDto?

    init(tipsMinor: Int, currency: CurrencyDto?) {
        self.tipsMinor = tipsMinor
        self.currency = currency
    }

    var body: some View {
        Card {
            HStack(spacing: 0) {
                DorrIcon("hands.sparkles.fill", size: 24, tint: pal.orange)
                    .frame(width: 44, height: 44)
                    .background(pal.orange.opacity(0.14), in: Circle())
                Spacer().frame(width: 12)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("earn_tips")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                    CountingMoney(value: Double(tipsMinor), currency: currency)
                        .font(.dorr(20, .heavy)).foregroundStyle(pal.orange)
                        .animation(.tween(700), value: tipsMinor)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Text(L("earn_tips_all_yours")).font(.dorr(11, .semibold)).foregroundStyle(pal.success)
                    .padding(.horizontal, 10).padding(.vertical, 4)
                    .background(pal.success.opacity(0.12), in: Capsule())
            }
        }
    }
}

/// `animateFloatAsState(tipsMinor)` shown as money: the number runs from the old amount to the new one.
private struct CountingMoney: View, Animatable {
    var value: Double
    let currency: CurrencyDto?

    var animatableData: Double {
        get { value }
        set { value = newValue }
    }

    var body: some View { Text(verbatim: money(Int(value), currency)) }
}

/// D-182: "The rider tipped you" on the trip's receipt, apart from the fare — the heart pops in.
struct TipBanner: View {
    @Environment(\.pal) private var pal
    let tipMinor: Int
    let currency: CurrencyDto?
    @State private var pop: CGFloat = 0.4

    init(tipMinor: Int, currency: CurrencyDto?) {
        self.tipMinor = tipMinor
        self.currency = currency
    }

    var body: some View {
        HStack(spacing: 0) {
            DorrIcon("heart.fill", size: 28, tint: pal.orange).scaleEffect(pop)
            Spacer().frame(width: 10)
            VStack(alignment: .leading, spacing: 0) {
                Text(L("trip_tip_received", money(tipMinor, currency))).font(.dorr(15, .bold)).foregroundStyle(pal.ink)
                Text(L("trip_tip_all_yours")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
            }
            Spacer(minLength: 0)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(pal.orange.opacity(0.1), in: RoundedRectangle(cornerRadius: 16))
        // spring(DampingRatioHighBouncy = 0.2, StiffnessLow = 200).
        .task(id: tipMinor) { withAnimation(.interpolatingSpring(mass: 1, stiffness: 200, damping: 2 * 0.2 * 200.0.squareRoot())) { pop = 1 } }
    }
}
