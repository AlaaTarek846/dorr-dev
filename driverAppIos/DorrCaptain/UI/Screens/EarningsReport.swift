import SwiftUI

// driverApp `ui/EarningsReport.kt`.

private let PRESETS: [(String, String)] = [
    ("3m", "earn_rp_3m"), ("6m", "earn_rp_6m"), ("9m", "earn_rp_9m"), ("12m", "earn_rp_12m"), ("custom", "earn_rp_custom"),
]

/// The earnings report (D-193..D-202): the last 3 / 6 / 9 / 12 months or two dates the captain picks, the
/// figures of that period, and each set against the period of the same length right before it — the
/// difference in money and in percent, said in words with an arrow, so it never rests on colour alone.
struct EarningsReportCard: View {
    @Environment(\.pal) private var pal
    let currency: CurrencyDto?

    @State private var period = "3m"
    @State private var from: String?
    @State private var to: String?
    @State private var picking: String?
    @State private var report: EarningsReportDto?
    @State private var error: String?
    @State private var loading = false

    init(_ currency: CurrencyDto?) { self.currency = currency }

    var body: some View {
        Card {
            Text(L("earn_report_title")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
            VSpace(10)
            ReportFlowRow(spacing: 8, lineSpacing: 8) {
                ForEach(PRESETS, id: \.0) { key, label in
                    PeriodChip(label: L(label), selected: period == key) { period = key }
                }
            }
            if period == "custom" {
                VSpace(10)
                HStack(spacing: 8) {
                    DateChip(label: L("earn_rp_from"), value: from) { picking = "from" }
                    DateChip(label: L("earn_rp_to"), value: to) { picking = "to" }
                }
            }
            VSpace(14)

            if loading && report == nil {
                CircularProgress(color: pal.orange, size: 24, lineWidth: 2)
                    .frame(maxWidth: .infinity)
                    .padding(16)
            } else if let error {
                Text(error).font(.dorr(13)).foregroundStyle(pal.danger)
            } else if let r = report {
                ReportBody(r: r, currency: currency ?? r.currency)
            }
        }
        // A preset loads when it is chosen; custom dates when both are picked.
        .task(id: "\(period)|\(from ?? "")|\(to ?? "")") {
            let custom = period == "custom"
            if custom && (from == nil || to == nil) { return }
            loading = true
            let r = await DriverApi.earningsReport(period: period, from: custom ? from : nil, to: custom ? to : nil)
            if Task.isCancelled { return }
            switch r {
            case .ok(let v, _): report = v; error = nil
            case .failed(let e): report = nil; error = e.displayMessage
            case .offline: report = nil; error = L("error_offline")
            }
            loading = false
        }
        .dorrDialog(isPresented: Binding(get: { picking != nil }, set: { if !$0 { picking = nil } })) {
            DatePickDialog(
                onPicked: { if picking == "from" { from = $0 } else { to = $0 } },
                onDismiss: { picking = nil }
            )
        }
    }
}

private struct ReportBody: View {
    @Environment(\.pal) private var pal
    let r: EarningsReportDto
    let currency: CurrencyDto?

    var body: some View {
        let totals = r.totals
        let change = r.change
        let empty = (totals?.netMinor ?? 0) == 0 && (totals?.trips ?? 0) == 0 && (r.previous?.totals?.netMinor ?? 0) == 0
        VStack(alignment: .leading, spacing: 0) {
            Text("\(dateLabel(r.period?.from ?? "")) – \(dateLabel(r.period?.to ?? ""))")
                .font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
            Text(money(totals?.netMinor ?? 0, currency)).font(.dorr(28, .heavy)).foregroundStyle(pal.ink)
            ChangeLine(change: change?.net) { signed($0, currency) }
            Text(L("earn_rp_vs", r.period?.days ?? 0, dateLabel(r.previous?.from ?? ""), dateLabel(r.previous?.to ?? "")))
                .font(.dorr(11)).foregroundStyle(pal.ink.opacity(0.5))
                .padding(.top, 2)
            if empty {
                Text(L("earn_rp_empty")).font(.dorr(13)).foregroundStyle(pal.ink).padding(.top, 12)
            } else {
                VSpace(14)
                Series(r: r)
                VSpace(14)
                StatRow(label: L("earn_rp_gross"), value: money(totals?.grossMinor ?? 0, currency), change: change?.gross) { signed($0, currency) }
                StatRow(label: L("earn_rp_trips"), value: L("earn_rp_trips_count", totals?.trips ?? 0), change: change?.trips) { signedCount($0) }
                StatRow(label: L("earn_rp_tips"), value: money(totals?.tipsMinor ?? 0, currency), change: change?.tips) { signed($0, currency) }
                StatRow(label: L("earn_rp_online"), value: hoursText(totals?.onlineS ?? 0), change: change?.online) {
                    ($0 < 0 ? "−" : "+") + hoursText(abs($0))
                }
            }
        }
    }
}

/// The period's bars, by month or by day; every slot has its place, empty ones stay short.
private struct Series: View {
    @Environment(\.pal) private var pal
    let r: EarningsReportDto

    var body: some View {
        let points = r.series?.points ?? []
        if !points.isEmpty {
            VStack(alignment: .leading, spacing: 0) {
                Text(L(r.series?.unit == "month" ? "earn_rp_by_month" : "earn_rp_by_day"))
                    .font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                VSpace(6)
                let max = Swift.max(points.map { $0.netMinor ?? 0 }.max() ?? 0, 1)
                HStack(alignment: .bottom, spacing: points.count > 20 ? 1 : 4) {
                    ForEach(Array(points.enumerated()), id: \.offset) { i, p in
                        let grow = Double(p.netMinor ?? 0) / Double(max)
                        VStack(spacing: 0) {
                            Spacer(minLength: 0)
                            RoundedRectangle(cornerRadius: 3).fill(pal.orange)
                                .frame(maxWidth: .infinity)
                                .frame(height: 110 * 0.8 * Swift.max(grow, 0.03))
                                .animation(.tween(600), value: grow)
                            // An empty label keeps its line, as Compose's empty Text does.
                            Text(points.count <= 12 || i % 5 == 0 ? String((p.key ?? "").suffix(2)) : " ")
                                .font(.dorr(9)).foregroundStyle(pal.ink).lineLimit(1)
                        }
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                    }
                }
                .frame(height: 110)
            }
        }
    }
}

private struct StatRow: View {
    @Environment(\.pal) private var pal
    let label: String
    let value: String
    let change: ChangeDto?
    let valueText: (Int) -> String

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                Text(label).font(.dorr(13)).foregroundStyle(pal.ink.opacity(0.7))
                    .frame(maxWidth: .infinity, alignment: .leading)
                Text(value).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
            }
            ChangeLine(change: change, valueText: valueText)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.vertical, 6)
    }
}

/// An arrow, and the same thing in words — "Up 12.5% (+30.00 SAR)" — so colour is never the only signal.
private struct ChangeLine: View {
    @Environment(\.pal) private var pal
    let change: ChangeDto?
    let valueText: (Int) -> String

    var body: some View {
        let direction = change?.direction
        let value = change?.value ?? 0
        let color: Color = {
            switch direction {
            case "up", "new": return pal.success
            case "down": return pal.danger
            default: return pal.ink.opacity(0.55)
            }
        }()
        let icon: String = {
            switch direction {
            case "up": return "arrow.up"
            case "down": return "arrow.down"
            case "new": return "sparkles"
            default: return "minus"
            }
        }()
        let pct = change?.pctBp.map { localDecimal(Double(abs($0)) / 100.0, places: 1) + "%" }
        let text: String = {
            switch direction {
            case "up": return pct.map { L("earn_rp_up", $0, valueText(value)) } ?? L("earn_rp_up_plain", valueText(value))
            case "down": return pct.map { L("earn_rp_down", $0, valueText(value)) } ?? L("earn_rp_down_plain", valueText(value))
            case "new": return L("earn_rp_new")
            default: return L("earn_rp_same")
            }
        }()
        HStack(spacing: 0) {
            DorrIcon(icon, size: 15, tint: color)
            HSpace(4)
            Text(text).font(.dorr(12, .semibold)).foregroundStyle(color)
        }
    }
}

private struct PeriodChip: View {
    @Environment(\.pal) private var pal
    let label: String
    let selected: Bool
    let onClick: () -> Void

    var body: some View {
        Text(label)
            .font(.dorr(13, .semibold))
            .foregroundStyle(selected ? Color.white : pal.navy)
            .padding(.horizontal, 14)
            .padding(.vertical, 7)
            .background(Capsule().fill(selected ? pal.navy : pal.navy.opacity(0.07)))
            .animation(.tween(200), value: selected)
            .contentShape(Capsule())
            .onTapGesture(perform: onClick)
    }
}

private struct DateChip: View {
    @Environment(\.pal) private var pal
    let label: String
    let value: String?
    let onClick: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(label).font(.dorr(11)).foregroundStyle(pal.ink.opacity(0.6))
            Text(value.map { dateLabel($0) } ?? L("earn_rp_pick")).font(.dorr(13, .semibold)).foregroundStyle(pal.ink)
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(RoundedRectangle(cornerRadius: 14).fill(pal.navy.opacity(0.07)))
        .contentShape(RoundedRectangle(cornerRadius: 14))
        .onTapGesture(perform: onClick)
    }
}

/// Compose's `FlowRow`: items left to right (in the reading direction), wrapping to a new line when full.
private struct ReportFlowRow: Layout {
    var spacing: CGFloat
    var lineSpacing: CGFloat

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let rows = arrange(subviews, width: proposal.width ?? .infinity)
        let width = rows.map { $0.width }.max() ?? 0
        let height = rows.map { $0.height }.reduce(0, +) + lineSpacing * CGFloat(Swift.max(rows.count - 1, 0))
        return CGSize(width: proposal.width ?? width, height: height)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var y = bounds.minY
        for row in arrange(subviews, width: bounds.width) {
            var x = bounds.minX
            for i in row.items {
                let size = subviews[i].sizeThatFits(.unspecified)
                subviews[i].place(at: CGPoint(x: x, y: y), proposal: ProposedViewSize(size))
                x += size.width + spacing
            }
            y += row.height + lineSpacing
        }
    }

    private func arrange(_ subviews: Subviews, width: CGFloat) -> [(items: [Int], width: CGFloat, height: CGFloat)] {
        var rows: [(items: [Int], width: CGFloat, height: CGFloat)] = []
        var current: (items: [Int], width: CGFloat, height: CGFloat) = ([], 0, 0)
        for i in subviews.indices {
            let size = subviews[i].sizeThatFits(.unspecified)
            let next = current.items.isEmpty ? size.width : current.width + spacing + size.width
            if !current.items.isEmpty && next > width {
                rows.append(current)
                current = ([i], size.width, size.height)
            } else {
                current = (current.items + [i], next, Swift.max(current.height, size.height))
            }
        }
        if !current.items.isEmpty { rows.append(current) }
        return rows
    }
}

/// "+30.00 SAR" / "−12.50 SAR" — the sign is part of the text, not only the colour.
private func signed(_ minor: Int, _ currency: CurrencyDto?) -> String { (minor < 0 ? "−" : "+") + money(abs(minor), currency) }

private func signedCount(_ n: Int) -> String { (n < 0 ? "−" : "+") + String(abs(n)) }

private func hoursText(_ seconds: Int) -> String { L("earn_hours", seconds / 3600, (seconds % 3600) / 60) }
