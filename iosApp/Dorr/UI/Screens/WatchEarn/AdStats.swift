import SwiftUI

/// androidApp `ui/screens/watchearn/AdStats.kt` — one ad's page (D15): its media, title, status and the actions its
/// status allows; the numbers (counting up), the money (a ring of spent vs remaining, the price, what is left by
/// bucket) and the charts — per day, by city, gender, platform, hour and reaction — drawn with Canvas and shapes. A
/// comments tab lets the advertiser report a comment. It reloads once when the server says this ad changed; there is
/// no timer.
@MainActor
struct AdStats: View {
    @EnvironmentObject private var host: WeHost
    @Environment(\.wa) private var wa
    let adId: String
    @State private var data: WeStatsResponseDto?
    @State private var failed = false
    @State private var reload = 0
    @State private var tab = 0
    @State private var busy: String?
    @State private var budgetOpen = false
    @State private var reportFor: WeCommentDto?
    @State private var exporting = false

    var body: some View {
        WaPage(title: L("we_ad_stats"), onBack: { host.pop() }, actions: { exportAction }) {
            if let d = data {
                StatsHeader(ad: d.ad, busy: busy,
                            onPause: { act("pause") { _ = try await WatchEarnApi.pause(d.ad.id) } },
                            onResume: { act("resume") { _ = try await WatchEarnApi.resume(d.ad.id) } },
                            onStop: { confirmStop(d.ad) },
                            onBudget: { budgetOpen = true },
                            onExtend: { extend(d.ad) },
                            onEdit: { host.push(.wizard(AdForm(edit: d.ad))) })
                    .waRise(0)
                HStack(spacing: 8) {
                    WeChip(text: L("we_tab_stats"), selected: tab == 0) { withAnimation(.easeOut(duration: 0.24)) { tab = 0 } }
                    WeChip(text: L("we_tab_comments"), selected: tab == 1, icon: "bubble.left") { withAnimation(.easeOut(duration: 0.24)) { tab = 1 } }
                }
                .padding(.top, 14)
                .padding(.bottom, 12)
                .waRise(1)
                Group {
                    if tab == 0 {
                        StatsBody(d: d)
                    } else {
                        AdComments(adId: d.ad.id) { reportFor = $0 }
                    }
                }
                .id(tab)
                .transition(.opacity)
            } else if failed {
                WaEmpty(icon: "exclamationmark.triangle.fill", tone: .red, title: L("we_load_failed"), text: L("we_load_failed_hint")) {
                    WaButton(text: L("we_retry")) { reload += 1 }.padding(.horizontal, 40)
                }
            } else {
                VStack(spacing: 12) {
                    WaSkeleton(cornerRadius: 22).frame(height: 170)
                    HStack(spacing: 10) { ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 96) } }
                    WaSkeleton(cornerRadius: 22).frame(height: 200)
                }
            }
        }
        .task(id: reload) { await load() }
        .onReceive(ChatRealtime.shared.events) { event in
            if event.name == "watch_earn.ad.changed" && event.str("ad_id") == adId { reload += 1 }
        }
        .weSheet(budgetOpen && data != nil, onDismiss: { budgetOpen = false }) {
            if let ad = data?.ad {
                BudgetSheet(ad: ad, onDismiss: { budgetOpen = false }) {
                    budgetOpen = false
                    reload += 1
                }
            }
        }
        .weSheet(reportFor != nil, onDismiss: { reportFor = nil }) {
            if let c = reportFor {
                WeReportCommentSheet(adId: adId, commentId: c.id) { message in
                    reportFor = nil
                    host.showToast(message)
                }
            }
        }
    }

    /// ⤓: the stats as PDF or Excel.
    @ViewBuilder
    private var exportAction: some View {
        if data != nil {
            if exporting {
                ProgressView().tint(wa.red)
                    .frame(width: 34, height: 34)
                    .background(wa.night ? wa.darkCard : wa.surface, in: Circle())
            } else {
                Menu {
                    Button { export("pdf") } label: { Label(L("we_export_pdf"), systemImage: "doc.richtext") }
                    Button { export("xlsx") } label: { Label(L("we_export_excel"), systemImage: "tablecells") }
                } label: {
                    Image(systemName: "square.and.arrow.down")
                        .font(.system(size: 13, weight: .bold))
                        .foregroundStyle(wa.red)
                        .frame(width: 34, height: 34)
                        .background(wa.night ? wa.darkCard : wa.surface, in: Circle())
                        .overlay { if wa.night { Circle().stroke(wa.line, lineWidth: 1) } }
                        .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.08), radius: 6, y: 2)
                }
                .accessibilityLabel(L("we_export"))
            }
        }
    }

    /// The stats as a file (pdf / xlsx), downloaded with my sign-in into the caches' `exports/`, under the server's
    /// name, then the system share sheet (Android: the FileProvider + `ACTION_SEND`).
    private func export(_ format: String) {
        guard !exporting else { return }
        exporting = true
        Task {
            do {
                let (bytes, response) = try await WatchEarnApi.export(adId, format: format)
                let caches = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first ?? FileManager.default.temporaryDirectory
                let dir = caches.appendingPathComponent("exports", isDirectory: true)
                try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
                let file = dir.appendingPathComponent(weExportName(response, adId: adId, ext: format == "pdf" ? "pdf" : "xlsx"))
                try bytes.write(to: file, options: .atomic)
                exporting = false
                presentShare(file)
            } catch {
                exporting = false
                host.showToast(L("we_export_failed"), error: true)
            }
        }
    }

    private func load() async {
        do {
            if let fresh = try await WatchEarnApi.stats(adId).data {
                data = fresh
                failed = false
            } else if data == nil {
                failed = true
            }
        } catch {
            if data == nil { failed = true }
            host.showToast(error.weMessage(L("we_error_generic")), error: true)
        }
    }

    /// An action on the ad (pause, resume, stop…): the answer is the ad itself, then the numbers are read once more.
    private func act(_ name: String, _ call: @escaping () async throws -> Void) {
        busy = name
        Task {
            do {
                try await call()
                reload += 1
            } catch {
                host.showToast(error.weMessage(L("we_error_generic")), error: true)
            }
            busy = nil
        }
    }

    private func confirmStop(_ ad: WeAdDto) {
        host.confirm(WeDialogSpec(title: L("we_stop_title"), text: L("we_stop_text"), confirm: L("we_stop"), danger: true) {
            act("stop") { _ = try await WatchEarnApi.stop(ad.id) }
        })
    }

    private func extend(_ ad: WeAdDto) {
        let end = weInstant(ad.endsAt).map { Calendar.current.startOfDay(for: $0) } ?? weToday()
        let earliest = max(wePlusDays(end, 1), wePlusDays(weToday(), 1))
        host.pickDate(WeDatePickerSpec(initial: wePlusDays(end, 7), min: earliest) { day in
            act("extend") { _ = try await WatchEarnApi.extend(ad.id, WeExtendRequest(endsAt: weServerDate(day, endOfDay: true))) }
        })
    }
}

/// The server's file name from `Content-Disposition` (`filename="…"` or `filename*=UTF-8''…`), or a fallback.
private func weExportName(_ response: HTTPURLResponse?, adId: String, ext: String) -> String {
    let header = response?.value(forHTTPHeaderField: "Content-Disposition") ?? ""
    var raw: String?
    if let regex = try? NSRegularExpression(pattern: #"filename\*?=(?:UTF-8'')?"?([^";]+)"?"#, options: [.caseInsensitive]),
       let match = regex.firstMatch(in: header, range: NSRange(header.startIndex..., in: header)),
       let range = Range(match.range(at: 1), in: header) {
        let value = String(header[range])
        raw = value.removingPercentEncoding ?? value
    }
    var cleaned = ""
    if let raw, let last = raw.split(whereSeparator: { $0 == "/" || $0 == "\\" }).last {
        cleaned = String(last).filter { $0.isLetter || $0.isNumber || "._- ".contains($0) }.trimmingCharacters(in: .whitespaces)
    }
    if cleaned.contains("."), !cleaned.hasPrefix(".") { return cleaned }
    return "DORR-ad-\(adId).\(ext)"
}

// MARK: - header and actions

private struct StatsHeader: View {
    @Environment(\.wa) private var wa
    let ad: WeAdDto
    let busy: String?
    let onPause: () -> Void
    let onResume: () -> Void
    let onStop: () -> Void
    let onBudget: () -> Void
    let onExtend: () -> Void
    let onEdit: () -> Void

    var body: some View {
        let actions = actionsFor(ad.status)
        WaCard(padding: 14) {
            HStack(spacing: 12) {
                if ad.media.type == "image" && ad.media.url != nil {
                    WeRemoteImage(url: ad.media.url).frame(width: 78, height: 78)
                        .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
                } else {
                    AdThumb(ad: ad, size: 78)
                }
                VStack(alignment: .leading, spacing: 6) {
                    Text(verbatim: ad.title).font(.dorr(15.5, .heavy)).foregroundStyle(wa.ink).lineLimit(2)
                    WeStatusBadge(status: ad.status, scheduled: ad.scheduled)
                    Text(verbatim: weDate(ad.startsAt) + " – " + weDate(ad.endsAt)).font(.dorr(11.5)).foregroundStyle(wa.soft)
                }
                Spacer(minLength: 0)
            }
            if ad.status == "rejected", let reason = ad.rejectReason, !reason.trimmingCharacters(in: .whitespaces).isEmpty {
                WaNote(text: reason, icon: "exclamationmark.triangle.fill").padding(.top, 10)
            }
            if !actions.isEmpty {
                WeFlow(spacing: 8) {
                    ForEach(actions, id: \.key) { a in
                        WaButton(text: a.label, style: a.key == "stop" ? .quiet : .ghost, icon: a.icon, loading: busy == a.key, compact: true, action: a.action)
                            .frame(width: 150)
                    }
                }
                .padding(.top, 12)
            }
        }
    }

    private struct Action {
        let key: String
        let icon: String
        let label: String
        let action: () -> Void
    }

    private func actionsFor(_ status: String) -> [Action] {
        switch status {
        case "active":
            return [
                Action(key: "pause", icon: "pause.fill", label: L("we_pause"), action: onPause),
                Action(key: "budget", icon: "plus", label: L("we_add_budget"), action: onBudget),
                Action(key: "extend", icon: "calendar.badge.plus", label: L("we_extend"), action: onExtend),
                Action(key: "stop", icon: "stop.fill", label: L("we_stop"), action: onStop),
            ]
        case "paused": return [Action(key: "resume", icon: "play.fill", label: L("we_resume"), action: onResume)]
        case "exhausted": return [Action(key: "budget", icon: "plus", label: L("we_add_budget"), action: onBudget)]
        case "pending_review", "rejected": return [Action(key: "edit", icon: "pencil", label: L("we_edit"), action: onEdit)]
        default: return []
        }
    }
}

// MARK: - the numbers and the charts

private struct StatsBody: View {
    @Environment(\.wa) private var wa
    let d: WeStatsResponseDto

    var body: some View {
        let t = d.stats.totals
        let m = d.stats.money
        let currency = d.ad.currency
        VStack(alignment: .leading, spacing: 0) {
            // Number cards, counting up.
            HStack(spacing: 10) {
                WeNumberCard(label: L("we_n_opens"), value: t.impressions, icon: "eye.fill", tint: We.spendOnly)
                WeNumberCard(label: L("we_n_completed"), value: t.completed, icon: "checkmark.circle.fill", tint: We.withdrawable)
                WeNumberCard(label: L("we_n_completion"), value: Int(t.completionRate), icon: "chart.line.uptrend.xyaxis", suffix: "%", tint: We.withdrawable)
            }
            HStack(spacing: 10) {
                WeNumberCard(label: L("we_n_interactions"), value: t.interactions, icon: "hand.tap.fill", tint: We.interaction)
                WeNumberCard(label: L("we_n_interaction_rate"), value: Int(t.interactionRate), icon: "chart.line.uptrend.xyaxis", suffix: "%", tint: We.interaction)
            }
            .padding(.top, 10)
            HStack(spacing: 10) {
                WeNumberCard(label: L("we_n_comments"), value: t.comments, icon: "bubble.left", tint: wa.red)
                WeNumberCard(label: L("we_n_reactions"), value: t.reactions, icon: "heart.fill", tint: Color(hex: 0xDB2777))
            }
            .padding(.top, 10)

            // The money.
            WaSectionTitle(title: L("we_money"))
            WaCard(padding: 16) {
                HStack(spacing: 16) {
                    ZStack {
                        SpentRing(spent: m.spentMinor, budget: m.budgetMinor).frame(width: 118, height: 118)
                        VStack(spacing: 0) {
                            WeCountUp(target: Int(m.spentPercent), format: { "\($0)%" }, size: 20)
                            Text(L("we_spent")).font(.dorr(11)).foregroundStyle(wa.mut)
                        }
                    }
                    VStack(spacing: 8) {
                        LegendLine(color: wa.red, label: L("we_spent"), value: weMoney(m.spentMinor, currency))
                        LegendLine(color: wa.line, label: L("we_remaining"), value: weMoney(m.remainingMinor, currency))
                        LegendLine(color: wa.ink, label: L("we_budget"), value: weMoney(m.budgetMinor, currency))
                    }
                }
                WaDivider().padding(.top, 10)
                WaKeyValue(label: L("we_price_per_view"), value: weMoney(m.pricePerViewMinor, currency), ltr: true)
                WaKeyValue(label: L("we_views_left"), value: weCount(m.viewsLeft), ltr: true)
                WaKeyValue(label: L("we_cost_per_interaction"), value: m.costPerInteractionMinor.map { weMoney($0, currency) } ?? "—", ltr: true)
                WaKeyValue(label: L("we_daily_average"), value: weMoney(m.dailyAverageMinor, currency), ltr: true)
                WaDivider()
                Text(L("we_remaining_by_bucket")).font(.dorr(13, .heavy)).foregroundStyle(wa.ink).padding(.top, 12).padding(.bottom, 8)
                WeBucketSplit(buckets: d.ad.budget.remainingByBucket, currency: currency)
            }

            // Per day.
            WaSectionTitle(title: L("we_chart_daily"))
            WaCard(padding: 16) {
                let days = d.stats.daily
                if days.allSatisfy({ $0.completed == 0 && $0.interactions == 0 }) {
                    EmptyChart()
                } else {
                    DailyChart(days: days).frame(height: 170)
                    HStack {
                        Text(verbatim: shortDay(days.first?.date)).font(.dorr(10.5)).foregroundStyle(wa.soft)
                        Spacer(minLength: 6)
                        Text(verbatim: shortDay(days.last?.date)).font(.dorr(10.5)).foregroundStyle(wa.soft)
                    }
                    .padding(.top, 8)
                    HStack(spacing: 14) {
                        LegendDot(color: We.series[0], label: L("we_n_completed"))
                        LegendDot(color: We.series[1], label: L("we_n_interactions"))
                    }
                    .padding(.top, 8)
                }
            }

            // By city.
            WaSectionTitle(title: L("we_chart_cities"))
            WaCard(padding: 16) {
                let rows = d.stats.byCity
                if rows.isEmpty {
                    EmptyChart()
                } else {
                    let top = max(rows.map(\.completed).max() ?? 0, 1)
                    ForEach(Array(rows.prefix(10).enumerated()), id: \.offset) { i, r in
                        HBar(label: r.name ?? L("we_unknown_city"), value: r.completed, fraction: CGFloat(r.completed) / CGFloat(top), color: We.series[i % 2], index: i)
                    }
                }
            }

            // Gender and platform.
            WaSectionTitle(title: L("we_chart_audience"))
            HStack(alignment: .top, spacing: 10) {
                WaCard(padding: 14) {
                    Text(L("we_gender")).font(.dorr(13, .heavy)).foregroundStyle(wa.ink).padding(.bottom, 10)
                    DonutWithLegend(slices: ordered(d.stats.byGender, ["male", "female", "unknown"]).map { (genderLabel($0.0), $0.1) })
                }
                WaCard(padding: 14) {
                    Text(L("we_platform")).font(.dorr(13, .heavy)).foregroundStyle(wa.ink).padding(.bottom, 10)
                    DonutWithLegend(slices: ordered(d.stats.byPlatform, ["android", "ios", "web", "unknown"]).map { (platformLabel($0.0), $0.1) })
                }
            }

            // By hour.
            WaSectionTitle(title: L("we_chart_hours"))
            WaCard(padding: 16) {
                let hours = d.stats.byHour
                if hours.isEmpty || hours.allSatisfy({ $0 == 0 }) {
                    EmptyChart()
                } else {
                    HourBars(hours: hours).frame(height: 120)
                    HStack(spacing: 0) {
                        ForEach(Array(["0", "6", "12", "18", "23"].enumerated()), id: \.offset) { i, h in
                            Text(verbatim: h).font(.dorr(10)).foregroundStyle(wa.soft)
                                .frame(maxWidth: .infinity, alignment: i == 4 ? .trailing : .leading)
                        }
                    }
                    .padding(.top, 6)
                }
            }

            // Reactions.
            WaSectionTitle(title: L("we_chart_reactions"))
            WaCard(padding: 14) {
                let reactions = d.stats.reactions.sorted { $0.value > $1.value }
                if reactions.isEmpty {
                    EmptyChart()
                } else {
                    HStack(spacing: 8) {
                        ForEach(Array(reactions.prefix(6)), id: \.key) { pair in
                            VStack(spacing: 2) {
                                Text(verbatim: pair.key).font(.system(size: 22))
                                WeCountUp(target: pair.value, format: { "\($0)" }, size: 13)
                            }
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 10)
                            .background(wa.field, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                    }
                }
            }
            WaNote(text: L("we_stats_privacy")).padding(.top, 12)
        }
    }

    /// The server's map in a steady order: the known keys first, then the rest by name.
    private func ordered(_ map: [String: Int], _ known: [String]) -> [(String, Int)] {
        let first = known.compactMap { k in map[k].map { (k, $0) } }
        let rest = map.filter { !known.contains($0.key) }.sorted { $0.key < $1.key }.map { ($0.key, $0.value) }
        return first + rest
    }

    private func genderLabel(_ key: String) -> String {
        switch key {
        case "male": return L("we_gender_male")
        case "female": return L("we_gender_female")
        default: return L("we_unknown")
        }
    }

    private func platformLabel(_ key: String) -> String {
        switch key {
        case "android": return "Android"
        case "ios": return "iOS"
        case "web": return L("we_platform_web")
        default: return L("we_unknown")
        }
    }

    private func shortDay(_ iso: String?) -> String {
        guard let day = weParseDay(iso) else { return "" }
        return weDate(day: day)
    }
}

private struct EmptyChart: View {
    @Environment(\.wa) private var wa

    var body: some View {
        Text(L("we_no_data_yet")).font(.dorr(12.5)).foregroundStyle(wa.soft).multilineTextAlignment(.center)
            .frame(maxWidth: .infinity)
            .padding(.vertical, 18)
    }
}

private struct LegendLine: View {
    @Environment(\.wa) private var wa
    let color: Color
    let label: String
    let value: String

    var body: some View {
        HStack(spacing: 6) {
            WeDot(color: color)
            Text(verbatim: label).font(.dorr(12)).foregroundStyle(wa.mut)
            Spacer(minLength: 4)
            Text(verbatim: value).font(.dorr(12, .bold)).foregroundStyle(wa.ink).weLtr()
        }
    }
}

private struct LegendDot: View {
    @Environment(\.wa) private var wa
    let color: Color
    let label: String

    var body: some View {
        HStack(spacing: 5) {
            WeDot(color: color, size: 8)
            Text(verbatim: label).font(.dorr(11)).foregroundStyle(wa.mut)
        }
    }
}

/// A Canvas redrawn with a value that grows from 0 to 1 once, when the chart first shows.
private struct WeGrowCanvas: View {
    var duration: Double = 0.9
    let draw: (inout GraphicsContext, CGSize, CGFloat) -> Void
    @State private var grow: CGFloat = 0

    var body: some View {
        Color.clear
            .modifier(GrowDraw(grow: grow, draw: draw))
            .onAppear { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: duration)) { grow = 1 } }
    }
}

private struct GrowDraw: AnimatableModifier {
    var grow: CGFloat
    let draw: (inout GraphicsContext, CGSize, CGFloat) -> Void
    var animatableData: CGFloat {
        get { grow }
        set { grow = newValue }
    }

    func body(content: Content) -> some View {
        Canvas { ctx, size in draw(&ctx, size, grow) }
    }
}

/// The ring: what is spent, against the whole budget.
private struct SpentRing: View {
    @Environment(\.wa) private var wa
    let spent: Int
    let budget: Int
    @State private var grow: CGFloat = 0

    var body: some View {
        let fraction = budget > 0 ? min(max(CGFloat(spent) / CGFloat(budget), 0), 1) : 0
        GeometryReader { g in
            let stroke = min(g.size.width, g.size.height) * 0.12
            ZStack {
                Circle().stroke(wa.line, lineWidth: stroke)
                Circle().trim(from: 0, to: fraction * grow)
                    .stroke(wa.red, style: StrokeStyle(lineWidth: stroke, lineCap: .round))
                    .rotationEffect(.degrees(-90))
            }
            .padding(stroke / 2)
        }
        .onAppear { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.9)) { grow = 1 } }
    }
}

/// Completed views and interactions per day: two lines with soft fills, drawn from the reading start to the end.
private struct DailyChart: View {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    let days: [WeDailyDto]

    var body: some View {
        let grid = wa.line
        let c1 = We.series[0]
        let c2 = We.series[1]
        let rtl = direction == .rightToLeft
        let days = self.days
        WeGrowCanvas(duration: 1.1) { ctx, size, reveal in
            let top = max(days.map { max($0.completed, $0.interactions) }.max() ?? 0, 1)
            let w = size.width
            let h = size.height
            for i in 0...3 {
                let y = h * CGFloat(i) / 3
                var line = Path()
                line.move(to: CGPoint(x: 0, y: y))
                line.addLine(to: CGPoint(x: w, y: y))
                ctx.stroke(line, with: .color(grid), lineWidth: 1)
            }
            func yOf(_ v: Int) -> CGFloat { h - h * CGFloat(v) / CGFloat(top) }
            if days.count == 1 {
                // One day: two bars side by side.
                let bw = w * 0.14
                let d = days[0]
                let h1 = (h - yOf(d.completed)) * reveal
                let h2 = (h - yOf(d.interactions)) * reveal
                ctx.fill(Path(roundedRect: CGRect(x: w / 2 - bw - 4, y: h - h1, width: bw, height: h1), cornerRadius: 6), with: .color(c1))
                ctx.fill(Path(roundedRect: CGRect(x: w / 2 + 4, y: h - h2, width: bw, height: h2), cornerRadius: 6), with: .color(c2))
                return
            }
            let step = w / CGFloat(days.count - 1)
            func xOf(_ i: Int) -> CGFloat { rtl ? w - CGFloat(i) * step : CGFloat(i) * step }
            func pathOf(_ value: (WeDailyDto) -> Int) -> Path {
                var p = Path()
                for (i, d) in days.enumerated() {
                    let point = CGPoint(x: xOf(i), y: yOf(value(d)))
                    if i == 0 { p.move(to: point) } else { p.addLine(to: point) }
                }
                return p
            }
            func fillOf(_ line: Path) -> Path {
                var p = line
                p.addLine(to: CGPoint(x: xOf(days.count - 1), y: h))
                p.addLine(to: CGPoint(x: xOf(0), y: h))
                p.closeSubpath()
                return p
            }
            let p1 = pathOf { $0.completed }
            let p2 = pathOf { $0.interactions }
            // Revealed from the reading start to the end as it appears.
            let shown = w * reveal
            ctx.clip(to: Path(CGRect(x: rtl ? w - shown : 0, y: 0, width: shown, height: h)))
            ctx.fill(fillOf(p1), with: .color(c1.opacity(0.12)))
            ctx.fill(fillOf(p2), with: .color(c2.opacity(0.10)))
            let style = StrokeStyle(lineWidth: 2.5, lineCap: .round, lineJoin: .round)
            ctx.stroke(p1, with: .color(c1), style: style)
            ctx.stroke(p2, with: .color(c2), style: style)
            if days.count <= 31 {
                for (i, d) in days.enumerated() {
                    ctx.fill(Path(ellipseIn: CGRect(x: xOf(i) - 3, y: yOf(d.completed) - 3, width: 6, height: 6)), with: .color(c1))
                    ctx.fill(Path(ellipseIn: CGRect(x: xOf(i) - 3, y: yOf(d.interactions) - 3, width: 6, height: 6)), with: .color(c2))
                }
            }
        }
    }
}

/// One horizontal bar (by city), its label above, growing in a little after the one before.
private struct HBar: View {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    let label: String
    let value: Int
    let fraction: CGFloat
    let color: Color
    let index: Int

    var body: some View {
        let track = wa.line
        let rtl = direction == .rightToLeft
        let part = min(max(fraction, 0), 1)
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text(verbatim: label).font(.dorr(12.5, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                Spacer(minLength: 6)
                Text(verbatim: "\(value)").font(.dorr(12, .bold)).foregroundStyle(wa.mut)
            }
            WeGrowCanvas(duration: 0.7 + Double(index) * 0.08) { ctx, size, grow in
                let r = size.height / 2
                ctx.fill(Path(roundedRect: CGRect(origin: .zero, size: size), cornerRadius: r), with: .color(track))
                // Mirrors in Arabic: the bar starts at the reading start.
                let width = size.width * part * grow
                let x = rtl ? size.width - width : 0
                ctx.fill(Path(roundedRect: CGRect(x: x, y: 0, width: width, height: size.height), cornerRadius: r), with: .color(color))
            }
            .frame(height: 9)
        }
        .padding(.vertical, 5)
    }
}

/// A donut of shares (gender, platform) with its legend under it.
private struct DonutWithLegend: View {
    @Environment(\.wa) private var wa
    let slices: [(String, Int)]
    @State private var grow: CGFloat = 0

    var body: some View {
        let total = slices.reduce(0) { $0 + $1.1 }
        if total == 0 {
            EmptyChart()
        } else {
            VStack(spacing: 0) {
                ZStack {
                    ForEach(Array(slices.enumerated()), id: \.offset) { i, slice in
                        let before = slices.prefix(i).reduce(0) { $0 + $1.1 }
                        let start = CGFloat(before) / CGFloat(total) * grow
                        let sweep = CGFloat(slice.1) / CGFloat(total) * grow
                        Circle()
                            .trim(from: start, to: max(start, start + sweep - 0.004))
                            .stroke(We.series[i % We.series.count], lineWidth: 104 * 0.18)
                            .rotationEffect(.degrees(-90))
                    }
                    WeCountUp(target: total, format: { "\($0)" }, size: 16)
                }
                .frame(width: 104 - 104 * 0.18, height: 104 - 104 * 0.18)
                .padding(104 * 0.09)
                .frame(maxWidth: .infinity)
                ForEach(Array(slices.enumerated()), id: \.offset) { i, slice in
                    HStack(spacing: 5) {
                        WeDot(color: We.series[i % We.series.count], size: 8)
                        Text(verbatim: slice.0).font(.dorr(11)).foregroundStyle(wa.mut).lineLimit(1)
                        Spacer(minLength: 4)
                        Text(verbatim: "\(slice.1 * 100 / total)%").font(.dorr(11, .bold)).foregroundStyle(wa.ink)
                    }
                    .padding(.vertical, 2)
                }
                .padding(.top, 10)
            }
            .onAppear { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 1.0)) { grow = 1 } }
        }
    }
}

/// 24 bars, one per hour of the day; the busiest hour in the brand colour.
private struct HourBars: View {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    let hours: [Int]

    var body: some View {
        let top = max(hours.max() ?? 0, 1)
        let busiest = hours.firstIndex(of: hours.max() ?? 0) ?? -1
        let soft = We.series[0].opacity(0.45)
        let strong = wa.red
        let rtl = direction == .rightToLeft
        let hours = self.hours
        WeGrowCanvas(duration: 0.9) { ctx, size, grow in
            let n = 24
            let gap: CGFloat = 3
            let bw = (size.width - gap * CGFloat(n - 1)) / CGFloat(n)
            for i in 0..<n {
                let v = i < hours.count ? hours[i] : 0
                let bh = max(size.height * CGFloat(v) / CGFloat(top) * grow, v > 0 ? 2 : 0)
                let slot = rtl ? n - 1 - i : i
                let rect = CGRect(x: CGFloat(slot) * (bw + gap), y: size.height - bh, width: bw, height: bh)
                ctx.fill(Path(roundedRect: rect, cornerRadius: bw / 3), with: .color(i == busiest && v > 0 ? strong : soft))
            }
        }
    }
}

// MARK: - comments (advertiser)

/// The comments on my ad; ⋮ → report a comment (the advertiser can't delete it — D21).
@MainActor
private struct AdComments: View {
    @EnvironmentObject private var host: WeHost
    let adId: String
    let onReport: (WeCommentDto) -> Void
    @State private var comments: [WeCommentDto] = []
    @State private var nextCursor: String?
    @State private var loaded = false
    @State private var loadingMore = false

    var body: some View {
        VStack(spacing: 0) {
            WaCard(padding: 12) {
                if !loaded {
                    VStack(spacing: 10) { ForEach(0..<3, id: \.self) { _ in WaSkeleton().frame(height: 46) } }
                } else if comments.isEmpty {
                    EmptyChart()
                } else {
                    ForEach(Array(comments.enumerated()), id: \.element.id) { i, c in
                        WeCommentRow(comment: c, menuLabel: L("we_report_comment"), onMenu: { onReport(c) })
                        if i < comments.count - 1 { WaDivider() }
                    }
                }
            }
            if let cursor = nextCursor {
                WaButton(text: L("we_load_more"), style: .ghost, loading: loadingMore, compact: true) {
                    loadingMore = true
                    Task {
                        if let page = try? await WatchEarnApi.comments(adId, cursor: cursor).data {
                            let known = Set(comments.map(\.id))
                            comments.append(contentsOf: page.comments.filter { !known.contains($0.id) })
                            nextCursor = page.nextCursor
                        }
                        loadingMore = false
                    }
                }
                .padding(.top, 10)
            }
        }
        .task {
            do {
                if let page = try await WatchEarnApi.comments(adId).data {
                    comments = page.comments
                    nextCursor = page.nextCursor
                }
            } catch {
                host.showToast(error.weMessage(L("we_error_generic")), error: true)
            }
            loaded = true
        }
    }
}

// MARK: - more budget

/// «Increase the budget»: an amount from the ad wallet's free balance — or a way to fund it first.
@MainActor
private struct BudgetSheet: View {
    @EnvironmentObject private var host: WeHost
    @Environment(\.wa) private var wa
    let ad: WeAdDto
    let onDismiss: () -> Void
    let onDone: () -> Void
    @State private var free: Int?
    @State private var text = ""
    @State private var busy = false

    var body: some View {
        let currency = ad.currency
        let amount = weParseAmount(text, currency.decimals)
        let short = amount != nil && free != nil && (amount ?? 0) > (free ?? 0)
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(L("we_add_budget")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                WeTextField(
                    value: Binding(get: { text }, set: { text = weAmountChars($0) }),
                    label: L("we_amount"), placeholder: "0", icon: "wallet.pass.fill", keyboard: .decimalPad,
                    error: !text.trimmingCharacters(in: .whitespaces).isEmpty && amount == nil, trailing: currency.code, ltr: true
                )
                WaCard(padding: 12) {
                    WaKeyValue(label: L("we_free_balance"), value: free.map { weMoney($0, currency) } ?? "…", bold: true, valueColor: short ? wa.danger : We.withdrawable, ltr: true)
                    if let amount, ad.budget.pricePerViewMinor > 0 {
                        WaKeyValue(label: L("we_enough_for"), value: weCount(amount / ad.budget.pricePerViewMinor) + " " + L("we_views_word"))
                    }
                }
                if short {
                    WaNote(text: L("we_wallet_short", weMoney(amount ?? 0, currency)), icon: "exclamationmark.triangle.fill")
                    WaButton(text: L("we_fund_ad_wallet"), style: .ghost, icon: "wallet.pass.fill", compact: true) {
                        onDismiss()
                        host.push(.adWallet(fundMinor: max((amount ?? 0) - (free ?? 0), 1)))
                    }
                }
                WaButton(text: L("we_add_budget"), icon: "plus", enabled: amount != nil && !short, loading: busy) {
                    guard let minor = amount else { return }
                    busy = true
                    Task {
                        do {
                            _ = try await WatchEarnApi.addBudget(ad.id, WeAmountRequest(amountMinor: minor))
                            host.showToast(L("we_budget_added"))
                            onDone()
                        } catch {
                            host.showToast(error.apiFailure.message ?? L("we_error_generic"), error: true)
                        }
                        busy = false
                    }
                }
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 20)
        }
        .frame(maxHeight: 560)
        .fixedSize(horizontal: false, vertical: true)
        .task { free = try? await WatchEarnApi.options().data?.freeMinor }
    }
}
