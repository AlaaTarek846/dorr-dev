import SwiftUI

// FL-L09 (part B), the client's side of the loyalty program — the twin of Android's `ui/screens/freelance/FreelanceLoyalty.kt`: a freelancer
// rewards clients who come back with a discount that grows with the jobs finished together. The server applies it to a project offer, a
// ready-made service and a consultation; here the client sees it — "My discounts" (every freelancer they worked with who runs a program),
// the badge and the struck-through usual price wherever a price shows, and a card with the ladder and the progress to the next step on a
// service, a consultation and a profile. Read on open and when the connection returns — never on a timer.

private let loycGreen = Color(hex: 0x16A34A)

/// 1000 basis points → "10", 1250 → "12.5".
func loycPercent(_ bp: Int) -> String {
    bp % 100 == 0 ? String(bp / 100) : String(format: "%.1f", Double(bp) / 100)
}

/// A small green pill: "10% off".
struct LoyaltyBadge: View {
    @Environment(\.wa) private var wa
    let bp: Int

    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: "gift.fill").font(.system(size: 10, weight: .bold)).foregroundStyle(loycGreen)
            Text(L("loyc_badge", loycPercent(bp))).font(.dorr(11, .heavy)).foregroundStyle(loycGreen).lineLimit(1)
        }
        .padding(.horizontal, 8).padding(.vertical, 3)
        .background(loycGreen.opacity(wa.night ? 0.22 : 0.12), in: Capsule())
    }
}

/// The price I pay, and (when a loyalty step took something off) the usual one struck through beside it.
struct LoyaltyPrice: View {
    @Environment(\.wa) private var wa
    let price: String
    var list: String?
    let color: Color
    var size: CGFloat = 15

    var body: some View {
        HStack(spacing: 6) {
            Text(verbatim: price).font(.dorr(size, .black)).foregroundStyle(color)
            if let list { Text(verbatim: list).font(.dorr(size * 0.78)).strikethrough().foregroundStyle(wa.settingsMut) }
        }
    }
}

/// Under a price: the badge and the usual price struck through (nothing when there is no discount).
struct LoyaltyWasLine: View {
    @Environment(\.wa) private var wa
    let bp: Int?
    let list: String?

    var body: some View {
        if let bp, let list {
            HStack(spacing: 8) {
                LoyaltyBadge(bp: bp)
                Text(verbatim: list).font(.dorr(12)).strikethrough().foregroundStyle(wa.settingsMut)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }
}

/// "Includes your loyalty discount of 10%." in the green notice style, for a sheet that confirms a price.
struct LoyaltyOfferNote: View {
    let bp: Int?

    var body: some View {
        if let bp { FlNotice(text: L("loyc_offer_note", loycPercent(bp)), icon: "gift.fill", color: loycGreen) }
    }
}

/// A line of a price breakdown: "Loyalty discount 10%  −50.00 SAR", in green.
struct LoyaltyDiscountLine: View {
    let bp: Int?
    let saving: String

    var body: some View {
        if let bp {
            HStack {
                Text(L("loyc_line_discount", loycPercent(bp))).font(.dorr(13, .bold)).foregroundStyle(loycGreen).frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: "−\(saving)").font(.dorr(13, .heavy)).foregroundStyle(loycGreen)
            }
        }
    }
}

/// The discount in a few words under a length's price, on a chip that is filled (white) or not (green).
struct LoyaltyMini: View {
    let bp: Int?
    let onAccent: Bool

    var body: some View {
        if let bp {
            Text(L("loyc_badge", loycPercent(bp))).font(.dorr(10, .heavy)).foregroundStyle(onAccent ? Color.white : loycGreen).lineLimit(1)
        }
    }
}

/// The bar toward the next step (0–1), filling in when it shows.
private struct LoycBar: View {
    let progress: Double
    @State private var shown = 0.0

    var body: some View {
        GeometryReader { geo in
            ZStack(alignment: .leading) {
                Capsule().fill(loycGreen.opacity(0.14))
                Capsule().fill(loycGreen).frame(width: geo.size.width * max(0.04, min(1, shown)))
            }
        }
        .frame(height: 8)
        .onAppear { withAnimation(.easeOut(duration: 0.6)) { shown = progress } }
        .onChange(of: progress) { new in withAnimation(.easeOut(duration: 0.6)) { shown = new } }
    }
}

/// The steps as chips: the ones I reached are filled.
private struct LoycLadder: View {
    @Environment(\.wa) private var wa
    let view: FlLoyaltyViewDto

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(Array((view.tiers ?? []).enumerated()), id: \.offset) { _, t in
                    let on = t.reached == true
                    HStack(spacing: 5) {
                        if on { Image(systemName: "checkmark.circle.fill").font(.system(size: 12)).foregroundStyle(.white) }
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("loyc_step_percent", loycPercent(t.discountBp ?? 0))).font(.dorr(13, .heavy)).foregroundStyle(on ? Color.white : loycGreen)
                            Text(L("loyc_step_after", t.after ?? 0)).font(.dorr(10.5)).foregroundStyle(on ? Color.white.opacity(0.9) : wa.settingsMut)
                        }
                    }
                    .padding(.horizontal, 10).padding(.vertical, 6)
                    .background(on ? loycGreen : loycGreen.opacity(0.08), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
            }
        }
    }
}

/// What I hold now and what is next, as one line.
private func loycStatus(_ view: FlLoyaltyViewDto) -> String {
    if let current = view.current, view.next == nil { return L("loyc_status_top", loycPercent(current.discountBp ?? 0)) }
    if let current = view.current { return L("loyc_status_current", loycPercent(current.discountBp ?? 0)) }
    if let next = view.next, (view.works ?? 0) == 0 { return L("loyc_status_start", next.after ?? 0, loycPercent(next.discountBp ?? 0)) }
    return L("loyc_status_none")
}

/// The program as a card — on a service, a consultation booking and a profile: what I get, how far I am from the next step and the whole
/// ladder. Nothing is drawn when the freelancer runs no program.
struct LoyaltyCard: View {
    @Environment(\.wa) private var wa
    let view: FlLoyaltyViewDto?

    var body: some View {
        if let view, !(view.tiers ?? []).isEmpty {
            VStack(alignment: .leading, spacing: 10) {
                HStack(spacing: 10) {
                    Image(systemName: "gift.fill").font(.system(size: 17, weight: .semibold)).foregroundStyle(loycGreen)
                        .frame(width: 38, height: 38)
                        .background(loycGreen.opacity(0.18), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    VStack(alignment: .leading, spacing: 1) {
                        Text(L("loyc_card_title")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: loycStatus(view)).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    if let current = view.current { LoyaltyBadge(bp: current.discountBp ?? 0) }
                }
                if let next = view.next {
                    LoycBar(progress: view.progress ?? 0)
                    Text(L("loyc_next_line", next.remaining ?? max(1, (next.after ?? 0) - (view.works ?? 0)), loycPercent(next.discountBp ?? 0)))
                        .font(.dorr(12, .bold)).foregroundStyle(loycGreen)
                }
                LoycLadder(view: view)
                Text(L("loyc_card_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineSpacing(2)
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(loycGreen.opacity(wa.night ? 0.18 : 0.08), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        }
    }
}

// MARK: - My discounts

@MainActor
struct LoyaltyPage: View {
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    @State private var cards: [FlLoyaltyCardDto]?
    @State private var error: String?

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: L("loyc_title"), onBack: onBack)
            if let rows = cards {
                ScrollView {
                    LazyVStack(spacing: 12) {
                        FlIntro(icon: "gift.fill", title: L("loyc_intro_title"), text: L("loyc_intro_body"))
                        if rows.isEmpty {
                            WaEmpty(icon: "gift.fill", tone: .green, title: L("loyc_empty_title"), text: L("loyc_empty_body"))
                        }
                        ForEach(rows) { c in LoycFreelancerCard(c: c) }
                    }
                    .padding(.horizontal, 16).padding(.vertical, 10)
                }
            } else if error == nil {
                FlSkeletonList()
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: network.reconnectTick) {
            do {
                cards = try await FreelanceLoyaltyApi.mine().data ?? []
                error = nil
            } catch {
                self.error = flError(error)
            }
        }
    }
}

private struct LoycFreelancerCard: View {
    @Environment(\.wa) private var wa
    let c: FlLoyaltyCardDto

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 10) {
                MkAvatar(url: c.freelancer?.avatarUrl, name: c.freelancer?.name, size: 44)
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: c.freelancer?.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Text(L("loyc_works_n", c.works ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if let current = c.current {
                    LoyaltyBadge(bp: current.discountBp ?? 0)
                } else {
                    Text(L("loyc_no_discount")).font(.dorr(11.5, .semibold)).foregroundStyle(wa.settingsMut)
                }
            }
            if let next = c.next {
                VStack(alignment: .leading, spacing: 6) {
                    LoycBar(progress: c.progress ?? 0)
                    Text(L("loyc_next_line", next.remaining ?? max(1, (next.after ?? 0) - (c.works ?? 0)), loycPercent(next.discountBp ?? 0)))
                        .font(.dorr(12, .bold)).foregroundStyle(loycGreen)
                }
            } else {
                Text(L("loyc_status_top", loycPercent(c.current?.discountBp ?? 0))).font(.dorr(12, .bold)).foregroundStyle(loycGreen)
            }
            LoycLadder(view: c.view)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20)
    }
}
