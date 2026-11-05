import SwiftUI

// driverApp `ui/EarningsScreen.kt`.

/// Today / this week / this month, the last 7 days as bars that grow in, the pending money, points.
struct EarningsScreen: View {
    @Environment(\.pal) private var pal
    let onBack: (() -> Void)?

    @State private var data: EarningsDto?
    @State private var tab = 0

    init(onBack: (() -> Void)?) { self.onBack = onBack }

    var body: some View {
        let currency = data?.currency
        ScreenScaffold(L("earn_title"), onBack: onBack) {
            // The period and its net, big — the one number a captain opens this screen for.
            let shown: PeriodDto? = tab == 1 ? data?.week : tab == 2 ? data?.month : data?.today
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 8) {
                    ForEach(Array(["earn_today", "earn_week", "earn_month"].enumerated()), id: \.offset) { i, label in
                        Chip(L(label), selected: tab == i, onClick: { tab = i })
                    }
                }
                Text(money(shown?.netMinor ?? 0, currency))
                    .font(.dorr(36, .heavy)).foregroundStyle(pal.navy)
                    .padding(.top, 14)
                HStack(spacing: 18) {
                    let online = shown?.onlineS ?? 0
                    Text("\(shown?.trips ?? 0) \(L("home_trips"))")
                    Text(L("earn_hours", online / 3600, (online % 3600) / 60))
                    if let bp = shown?.acceptanceBp { Text("\(L("earn_acceptance")) \(bp / 100)%") }
                }
                .font(.dorr(13)).foregroundStyle(pal.ink.opacity(0.7))
                .padding(.top, 4)
            }
            .padding(18)
            .frame(maxWidth: .infinity, alignment: .leading)
            .surface(24, elevation: 8)
            .padding(.horizontal, 16)

            if let d = data {
                let period: PeriodDto? = tab == 1 ? d.week : tab == 2 ? d.month : d.today
                ScrollView {
                    VStack(alignment: .leading, spacing: 14) {
                        // D-204: the period's tips on their own — all of them the captain's, no commission.
                        TipsCard(tipsMinor: period?.tipsMinor ?? 0, currency: currency)
                        Card {
                            Text(L("earn_last7")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                            VSpace(12)
                            LastSevenBars(days: d.days ?? [])
                        }
                        // D-193..D-202: 3 / 6 / 9 / 12 months or two dates, against the period before.
                        EarningsReportCard(currency)
                        HStack(spacing: 12) {
                            Card {
                                Text(L("earn_pending")).font(.dorr(12)).foregroundStyle(pal.ink)
                                Text(money(d.pendingMinor ?? 0, currency)).font(.dorr(18, .heavy)).foregroundStyle(pal.ink)
                            }
                            Card {
                                Text(L("earn_wallet")).font(.dorr(12)).foregroundStyle(pal.ink)
                                Text(money(d.walletMinor ?? 0, currency)).font(.dorr(18, .heavy))
                                    .foregroundStyle((d.walletMinor ?? 0) < 0 ? pal.danger : pal.ink)
                            }
                        }
                        HStack(spacing: 12) {
                            Card {
                                Text(L("earn_points")).font(.dorr(12)).foregroundStyle(pal.ink)
                                Text(String(d.profile?.points ?? 0)).font(.dorr(22, .heavy)).foregroundStyle(pal.orange)
                            }
                            Card {
                                Text(L("earn_rating")).font(.dorr(12)).foregroundStyle(pal.ink)
                                Text(d.profile?.rating.map { localDecimal($0, places: 1) + " ★" } ?? "—")
                                    .font(.dorr(22, .heavy)).foregroundStyle(pal.ink)
                            }
                        }
                        let points = d.recentPoints ?? []
                        if !points.isEmpty {
                            Card {
                                ForEach(Array(points.enumerated()), id: \.offset) { _, p in
                                    let n = p.points ?? 0
                                    HStack(spacing: 0) {
                                        Text(p.label ?? p.event ?? "").font(.dorr(16)).foregroundStyle(pal.ink)
                                            .frame(maxWidth: .infinity, alignment: .leading)
                                        Text(n >= 0 ? "+\(n)" : String(n)).font(.dorr(16, .bold))
                                            .foregroundStyle(n >= 0 ? pal.success : pal.danger)
                                    }
                                    .padding(.vertical, 4)
                                }
                            }
                        }
                    }
                    .padding(16)
                }
            }
        }
        .task {
            if case .ok(let v, _) = await DriverApi.earnings() { data = v }
        }
    }
}

/// The last 7 days: one orange bar each (its share of the best day), the day of the month under it.
private struct LastSevenBars: View {
    @Environment(\.pal) private var pal
    let days: [DayDto]

    var body: some View {
        let max = Swift.max(days.map { $0.netMinor ?? 0 }.max() ?? 0, 1)
        HStack(alignment: .bottom, spacing: 0) {
            ForEach(Array(days.enumerated()), id: \.offset) { _, day in
                Spacer(minLength: 0)
                let target = Double(day.netMinor ?? 0) / Double(max)
                VStack(spacing: 0) {
                    Spacer(minLength: 0)
                    RoundedRectangle(cornerRadius: 6).fill(pal.orange)
                        .frame(width: 22, height: 130 * 0.85 * Swift.max(target, 0.03))
                        .animation(.tween(700), value: target)
                    Text(String((day.date ?? "").suffix(2))).font(.dorr(10)).foregroundStyle(pal.ink)
                }
                .frame(maxHeight: .infinity)
            }
            Spacer(minLength: 0)
        }
        .frame(maxWidth: .infinity)
        .frame(height: 130)
    }
}

/// `String.format(Locale.getDefault(), "%.Nf", value)` — the app language's digits.
func localDecimal(_ value: Double, places: Int) -> String {
    let f = NumberFormatter()
    f.locale = Locale(identifier: DriverPrefs.locale)
    f.numberStyle = .decimal
    f.usesGroupingSeparator = false
    f.minimumFractionDigits = places
    f.maximumFractionDigits = places
    f.roundingMode = .halfUp
    return f.string(from: NSNumber(value: value)) ?? String(format: "%.\(places)f", value)
}
