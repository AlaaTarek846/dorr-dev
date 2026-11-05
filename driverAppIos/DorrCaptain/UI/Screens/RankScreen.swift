import SwiftUI

// driverApp `ui/RankScreen.kt`.

/**
 * «My rank» (docs/rides_tasks.md D-5, D-6, D-239..D-259): the probation days left, the rank and the way to the
 * next one (what's missing, from the server's thresholds), my numbers, a pending fall with its date, every rank
 * side by side, and my record of notes / warnings with their appeals. One load on open; `driver.rank` /
 * `driver.note` / `driver.appeal` events reload it.
 */
struct RankScreen: View {
    let onBack: () -> Void

    @Environment(\.pal) private var pal
    @State private var info: RankInfoDto?
    @State private var error: String?
    @State private var appealing: PerformanceNoteDto?
    @State private var appealText = ""
    @State private var appealBusy = false

    init(onBack: @escaping () -> Void) { self.onBack = onBack }

    private func reload() async {
        switch await DriverApi.rank() {
        case .ok(let d, _): info = d
        case .failed(let e): error = e.displayMessage
        case .offline: error = L("error_offline")
        }
    }

    var body: some View {
        ScreenScaffold(L("rk_title"), onBack: onBack) {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    if let error { Text(error).font(.dorr(16)).foregroundStyle(pal.danger) }
                    if let d = info {
                        RankHero(d: d)
                        if let f = d.falling {
                            VStack(alignment: .leading, spacing: 0) {
                                HStack(spacing: 0) {
                                    DorrIcon("exclamationmark.triangle.fill", size: 24, tint: pal.warning)
                                    HSpace(8)
                                    Text(L("rk_falling", f.to?.name ?? "", daysUntil(f.dueAt)))
                                        .font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                                }
                                ForEach(Array((f.missing ?? []).enumerated()), id: \.offset) { _, m in MissingLine(m: m) }
                            }
                            .padding(14)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .background(RoundedRectangle(cornerRadius: 18).fill(pal.warning.opacity(0.12)))
                            .overlay(RoundedRectangle(cornerRadius: 18).strokeBorder(pal.warning.opacity(0.5), lineWidth: 1))
                        }
                        // My numbers.
                        let m = d.metrics ?? RankMetricsDto()
                        Text(L("rk_numbers", m.windowDays ?? 90)).font(.dorr(16, .black)).foregroundStyle(pal.ink)
                        let rating = m.rating ?? 0, cleanliness = m.cleanliness ?? 0, behavior = m.behavior ?? 0
                        let commitment = m.commitmentBp ?? 10000, completion = m.completionBp ?? 10000, cancel = m.cancelBp ?? 0
                        let warnings = m.warnings ?? 0
                        let metrics: [(String, String, Double)] = [
                            (L("rk_m_trips"), "\(m.trips ?? 0)", 1),
                            (L("rk_m_rating"), stars(rating), Double(rating) / 500),
                            (L("rk_m_cleanliness"), stars(cleanliness), Double(cleanliness) / 500),
                            (L("rk_m_behavior"), stars(behavior), Double(behavior) / 500),
                            (L("rk_m_commitment"), pct(commitment), Double(commitment) / 10000),
                            (L("rk_m_completion"), pct(completion), Double(completion) / 10000),
                            (L("rk_m_cancel"), pct(cancel), 1 - Double(cancel) / 10000),
                            (L("rk_m_warnings"), "\(warnings)", warnings == 0 ? 1 : 0.3),
                        ]
                        ForEach(Array(stride(from: 0, to: metrics.count, by: 2)), id: \.self) { i in
                            HStack(spacing: 10) {
                                ForEach(i..<min(i + 2, metrics.count), id: \.self) { j in
                                    MetricTile(label: metrics[j].0, value: metrics[j].1, share: metrics[j].2).frame(maxWidth: .infinity)
                                }
                            }
                        }
                        // Every rank side by side.
                        Text(L("rk_all")).font(.dorr(16, .black)).foregroundStyle(pal.ink)
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(alignment: .top, spacing: 10) {
                                ForEach(Array((d.ranks ?? []).enumerated()), id: \.offset) { _, r in RankCard(r: r) }
                            }
                        }
                        // The record.
                        Text(L("rk_record")).font(.dorr(16, .black)).foregroundStyle(pal.ink)
                        let notes = d.notes ?? []
                        if notes.isEmpty { Text(L("rk_record_empty")).font(.dorr(13)).foregroundStyle(pal.mut) }
                        ForEach(Array(notes.enumerated()), id: \.offset) { _, n in
                            NoteCard(n: n, appealAllowed: d.appealAllowed ?? false) {
                                appealText = ""
                                appealBusy = false
                                appealing = n
                            }
                        }
                    }
                }
                .padding(16)
            }
        }
        .task { await reload() }
        .onReceive(DriverLive.shared.events) { e in
            if e.name == "driver.rank" || e.name == "driver.note" || e.name == "driver.appeal" { Task { await reload() } }
        }
        .dorrDialog(isPresented: Binding(get: { appealing != nil }, set: { if !$0 { appealing = nil } }), dismissible: !appealBusy) {
            if let note = appealing {
                AlertDialogCard(title: { Text(L("rk_appeal_title")).font(.dorr(24, .bold)) }, text: {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(note.title ?? "").font(.dorr(13)).foregroundStyle(pal.mut)
                        VSpace(8)
                        DorrField(value: appealText, onValueChange: { appealText = String($0.prefix(1000)) }, placeholder: L("rk_appeal_hint"))
                    }
                }, buttons: {
                    AlertTextButton(L("cancel")) { appealing = nil }
                    AlertTextButton(L("rk_appeal_send"), enabled: appealText.trimmingCharacters(in: .whitespacesAndNewlines).count >= 5 && !appealBusy) {
                        appealBusy = true
                        Task {
                            switch await DriverApi.appealNote(id: note.id ?? 0, body: ["text": appealText.trimmingCharacters(in: .whitespacesAndNewlines)]) {
                            case .ok: appealing = nil; await reload()
                            case .failed(let e): error = e.displayMessage
                            case .offline: error = L("error_offline")
                            }
                            appealBusy = false
                        }
                    }
                })
            }
        }
    }
}

private struct RankHero: View {
    @Environment(\.pal) private var pal
    let d: RankInfoDto
    @State private var glow: CGFloat = 0.85
    @State private var pop: CGFloat = 0.6

    var body: some View {
        let color = parseColor(d.rank?.color) ?? pal.accent
        VStack(spacing: 0) {
            ZStack {
                Circle().fill(Color.white.opacity(0.2))
                DorrIcon("medal.fill", size: 52, tint: .white)
            }
            .frame(width: 84, height: 84)
            .scaleEffect(pop * glow)
            Spacer().frame(height: 8)
            Text(d.rank?.name ?? "").font(.dorr(26, .black)).foregroundStyle(.white)
            if d.probation?.active == true {
                Text(L("rk_probation", d.probation?.daysLeft ?? 0)).font(.dorr(13)).foregroundStyle(Color.white.opacity(0.9))
                    .multilineTextAlignment(.center)
            }
            if let next = d.next {
                let missing = next.missing ?? []
                Spacer().frame(height: 12)
                Text(L("rk_to_next", next.name ?? "")).font(.dorr(14, .bold)).foregroundStyle(.white)
                if missing.isEmpty {
                    Text(L("rk_next_ready")).font(.dorr(12)).foregroundStyle(Color.white.opacity(0.9))
                } else {
                    VStack(alignment: .leading, spacing: 0) {
                        ForEach(Array(missing.enumerated()), id: \.offset) { _, m in MissingLine(m: m, onDark: true) }
                    }
                    .padding(10)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(RoundedRectangle(cornerRadius: 14).fill(Color.white.opacity(0.16)))
                    .padding(.top, 6)
                }
            } else {
                Text(L("rk_top")).font(.dorr(13)).foregroundStyle(Color.white.opacity(0.9)).padding(.top, 8)
            }
        }
        .padding(18)
        .frame(maxWidth: .infinity)
        .background(
            RoundedRectangle(cornerRadius: 26).fill(
                LinearGradient(colors: [color, color.opacity(0.65), pal.accent], startPoint: .topLeading, endPoint: .bottomTrailing)
            )
        )
        .onAppear {
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 1.6).repeatForever(autoreverses: true)) { glow = 1.08 }
        }
        .task(id: d.rank?.slug) {
            withAnimation(.interpolatingSpring(mass: 1, stiffness: 1500, damping: 2 * 0.45 * sqrt(1500))) { pop = 1 }
        }
    }
}

private struct MissingLine: View {
    @Environment(\.pal) private var pal
    let m: RankMissingDto
    var onDark = false

    var body: some View {
        let ink = onDark ? Color.white : pal.ink
        let metric = m.metric ?? ""
        let label = metricLabel(metric)
        let have = m.have ?? 0, need = m.need ?? 0, min = m.min ?? true
        let text: String = {
            switch metric {
            case "trips", "warnings": return L(min ? "rk_need_min" : "rk_need_max", label, "\(have)", "\(need)")
            case "rating", "cleanliness", "behavior": return L("rk_need_min", label, stars(have), stars(need))
            default: return L(min ? "rk_need_min" : "rk_need_max", label, pct(have), pct(need))
            }
        }()
        Text("• \(text)").font(.dorr(12.5)).foregroundStyle(ink).padding(.top, 3)
    }
}

private struct MetricTile: View {
    @Environment(\.pal) private var pal
    let label: String
    let value: String
    let share: Double
    @State private var ring: Double = 0

    var body: some View {
        let target = Swift.min(Swift.max(share, 0), 1)
        let tint = share >= 0.85 ? pal.success : (share >= 0.6 ? pal.warning : pal.danger)
        HStack(spacing: 0) {
            ZStack {
                // Stroke 5 px, the track 3 px inside, the arc on the whole box (Android's pixels, ~3 per point).
                Circle().inset(by: 1).stroke(tint.opacity(0.15), lineWidth: 1.7)
                Circle().trim(from: 0, to: ring)
                    .stroke(tint, style: StrokeStyle(lineWidth: 1.7, lineCap: .round))
                    .rotationEffect(.degrees(-90))
            }
            .frame(width: 42, height: 42)
            .environment(\.layoutDirection, .leftToRight)
            HSpace(8)
            VStack(alignment: .leading, spacing: 0) {
                Text(value).font(.dorr(16, .black)).foregroundStyle(pal.ink)
                Text(label).font(.dorr(11)).foregroundStyle(pal.mut)
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface(18)
        .onAppear { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.9)) { ring = target } }
        .onChange(of: target) { t in withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.9)) { ring = t } }
    }
}

private struct RankCard: View {
    @Environment(\.pal) private var pal
    let r: RankDto

    /// The thresholds in the server's order of metrics (a Swift dictionary keeps none).
    private static let order = ["trips", "rating", "cleanliness", "behavior", "commitment_bp", "completion_bp", "cancel_bp", "warnings"]

    var body: some View {
        let color = parseColor(r.color) ?? pal.accent
        let current = r.current ?? false
        let thresholds = (r.thresholds ?? [:])
            .filter { k, v in v > 0 && !(k == "cancel_bp" && v >= 10000) && !(k == "warnings" && v >= 99) }
            .map { $0.key }
            .sorted { (Self.order.firstIndex(of: $0) ?? 99, $0) < (Self.order.firstIndex(of: $1) ?? 99, $1) }
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                DorrIcon("medal.fill", size: 24, tint: color)
                HSpace(6)
                Text(r.name ?? "").font(.dorr(16, .black)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                if current {
                    Text(L("rk_you")).font(.dorr(10, .bold)).foregroundStyle(.white)
                        .padding(.horizontal, 8).padding(.vertical, 2)
                        .background(Capsule().fill(color))
                }
            }
            if r.probation == true { Text(L("rk_probation_rank")).font(.dorr(12)).foregroundStyle(pal.mut) }
            ForEach(thresholds, id: \.self) { k in
                let v = r.thresholds?[k] ?? 0
                let need: String = {
                    switch k {
                    case "rating", "cleanliness", "behavior": return stars(v)
                    case "trips", "warnings": return "\(v)"
                    default: return pct(v)
                    }
                }()
                Text("• " + L(k == "cancel_bp" || k == "warnings" ? "rk_th_max" : "rk_th_min", metricLabel(k), need))
                    .font(.dorr(11.5)).foregroundStyle(pal.mut)
            }
            ForEach(Array((r.benefits ?? []).enumerated()), id: \.offset) { _, b in
                HStack(alignment: .top, spacing: 0) {
                    DorrIcon("checkmark.circle.fill", size: 14, tint: pal.success)
                    HSpace(4)
                    Text(b).font(.dorr(12)).foregroundStyle(pal.ink)
                }
                .padding(.top, 3)
            }
        }
        .padding(14)
        .frame(width: 210, alignment: .topLeading)
        .background(RoundedRectangle(cornerRadius: 20).fill(pal.card))
        .overlay(RoundedRectangle(cornerRadius: 20).strokeBorder(current ? color : color.opacity(0.3), lineWidth: current ? 2 : 1))
        .clipShape(RoundedRectangle(cornerRadius: 20))
    }
}

private struct NoteCard: View {
    @Environment(\.pal) private var pal
    let n: PerformanceNoteDto
    let appealAllowed: Bool
    let onAppeal: () -> Void

    var body: some View {
        let kind = n.kind ?? "note"
        let (icon, tint): (String, Color) = {
            switch kind {
            case "warning": return ("exclamationmark.triangle.fill", pal.danger)
            case "praise": return ("hand.thumbsup.fill", pal.success)
            case "rank_change": return ("medal.fill", pal.warning)
            default: return ("info.circle.fill", pal.accent)
            }
        }()
        let status = n.appealStatus ?? "none"
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                DorrIcon(icon, size: 20, tint: tint)
                HSpace(8)
                Text(n.title ?? "").font(.dorr(14, .bold)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                Text(String((n.createdAt ?? "").prefix(10))).font(.dorr(11)).foregroundStyle(pal.mut)
            }
            if let body = n.body, !body.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                Text(body).font(.dorr(12.5)).foregroundStyle(pal.mut).padding(.top, 4)
            }
            switch status {
            case "pending":
                Text(L("rk_appeal_pending")).font(.dorr(12, .semibold)).foregroundStyle(pal.warning).padding(.top, 6)
            case "accepted", "rejected":
                Text(L(status == "accepted" ? "rk_appeal_accepted" : "rk_appeal_rejected", n.appealAnswer ?? ""))
                    .font(.dorr(12)).foregroundStyle(status == "accepted" ? pal.success : pal.danger).padding(.top, 6)
            default:
                if appealAllowed && kind != "praise" {
                    HStack(spacing: 0) {
                        DorrIcon("hammer.fill", size: 16, tint: pal.accent)
                        HSpace(6)
                        Text(L("rk_appeal")).font(.dorr(12.5, .bold)).foregroundStyle(pal.accent)
                    }
                    .padding(.horizontal, 12)
                    .padding(.vertical, 6)
                    .background(Capsule().fill(pal.accent.opacity(0.1)))
                    .contentShape(Capsule())
                    .onTapGesture(perform: onAppeal)
                    .padding(.top, 8)
                }
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface(18)
    }
}

private func metricLabel(_ key: String) -> String {
    switch key {
    case "trips": return L("rk_m_trips")
    case "rating": return L("rk_m_rating")
    case "cleanliness": return L("rk_m_cleanliness")
    case "behavior": return L("rk_m_behavior")
    case "commitment_bp": return L("rk_m_commitment")
    case "completion_bp": return L("rk_m_completion")
    case "cancel_bp": return L("rk_m_cancel")
    default: return L("rk_m_warnings")
    }
}

/// `"%.2f★".format(x / 100.0)` / `"%d%%".format(bp / 100)` — `String.format` in the app's language (its digits).
private func rankFormat(_ value: Double, places: Int) -> String {
    let f = NumberFormatter()
    f.locale = Locale(identifier: DriverPrefs.locale)
    f.numberStyle = .decimal
    f.usesGroupingSeparator = false
    f.minimumFractionDigits = places
    f.maximumFractionDigits = places
    f.roundingMode = .halfEven
    return f.string(from: NSNumber(value: value)) ?? String(format: "%.\(places)f", value)
}

private func stars(_ x100: Int) -> String { x100 <= 0 ? "—" : rankFormat(Double(x100) / 100, places: 2) + "★" }

private func pct(_ bp: Int) -> String { rankFormat(Double(bp / 100), places: 0) + "%" }

private func daysUntil(_ iso: String?) -> Int {
    guard let at = parseInstant(iso) else { return 0 }
    return max(0, Int(at.timeIntervalSinceNow / 3600) / 24 + 1)
}

private func parseColor(_ hex: String?) -> Color? {
    guard var h = hex?.trimmingCharacters(in: .whitespaces), h.hasPrefix("#") else { return nil }
    h.removeFirst()
    guard let v = UInt32(h, radix: 16) else { return nil }
    switch h.count {
    case 6: return Color(hex: v)
    // `Color.parseColor` reads #AARRGGBB too.
    case 8: return Color(hex: v & 0xFFFFFF, alpha: Double((v >> 24) & 0xFF) / 255)
    default: return nil
    }
}
