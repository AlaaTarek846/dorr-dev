import SwiftUI
import UIKit

// driverApp `ui/DriverAiScreen.kt` — DORR Driver AI (Block 06, D-394) and where the work is.

private let AiGlow: [Color] = [Color(hex: 0x6D5BFF), Color(hex: 0xB54BFF), Color(hex: 0xFF7A59)]

/// DORR Driver AI (Block 06, D-394) and where the work is: the assistant's cards — each with why and how
/// sure — and asking it; hot zones now for everyone; the next hour and booked-ahead demand on a DORR AI
/// plan (the same plans as the DORR app, bought from the captain's wallet with the PIN). Loaded when it opens,
/// and again after subscribing or changing the goal — nothing asks the server on a timer.
struct DriverAiScreen: View {
    let onBack: () -> Void

    @Environment(\.pal) private var pal
    @State private var ai: DriverAiDto?
    @State private var demand: DemandDto?
    @State private var here: (Double, Double)?
    @State private var reload = 0
    @State private var plansOpen = false
    @State private var goalOpen = false
    @State private var error: String?

    var body: some View {
        ScreenScaffold(L("dai_title"), onBack: onBack) {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    AiHero()
                    if let error { Text(error).font(.dorr(13)).foregroundStyle(pal.danger) }
                    if let a = ai, a.allowed != true { LockedCard { plansOpen = true } }
                    if let a = ai, a.allowed == true {
                        let cards = a.cards ?? []
                        if cards.isEmpty { Text(L("dai_no_cards")).font(.dorr(13)).foregroundStyle(pal.mut) }
                        ForEach(Array(cards.enumerated()), id: \.offset) { i, card in
                            AiCard(card: card, onGoal: { goalOpen = true }).staggerIn(i)
                        }
                        if !cards.contains(where: { $0.type == "goal" }) {
                            Text(L("dai_set_goal")).font(.dorr(14, .bold)).foregroundStyle(pal.accent)
                                .padding(6)
                                .bounceClick { goalOpen = true }
                        }
                        AskBox(here: here)
                    }
                    DemandSection(demand: demand, onUnlock: { plansOpen = true })
                }
                .padding(16)
            }
            .scrollDismissesKeyboard(.interactively)
        }
        .task(id: reload) {
            here = await lastPosition()
            switch await DriverApi.aiAssistant(lat: here?.0, lng: here?.1) {
            case .ok(let a, _): ai = a
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            if let (lat, lng) = here, let d = await DriverApi.demand(lat: lat, lng: lng).value { demand = d }
        }
        .sheet(isPresented: $plansOpen) {
            PlansSheet(onClose: { plansOpen = false }, onSubscribed: { plansOpen = false; reload += 1 })
                .environment(\.pal, pal)
                .presentationDetents([.large])
        }
        .dorrDialog(isPresented: $goalOpen) {
            GoalDialog(current: ai?.goalMinor, onClose: { goalOpen = false }) { minor in
                goalOpen = false
                Task {
                    _ = await DriverApi.savePreferences(body: ["daily_goal_minor": minor])
                    reload += 1
                }
            }
            .environment(\.pal, pal)
        }
    }
}

private struct AiHero: View {
    @State private var spin = false

    var body: some View {
        HStack(spacing: 0) {
            Image(systemName: "sparkles")
                .font(.system(size: 28 * 0.82, weight: .semibold))
                .foregroundStyle(.white)
                .frame(width: 28, height: 28)
                .rotationEffect(.degrees(spin ? 360 : 0))
                .frame(width: 52, height: 52)
                .background(Circle().fill(Color.white.opacity(0.18)))
            HSpace(14)
            VStack(alignment: .leading, spacing: 0) {
                Text(L("dai_title")).font(.dorr(19, .heavy)).foregroundStyle(.white)
                Text(L("dai_sub")).font(.dorr(12)).foregroundStyle(Color.white.opacity(0.9))
            }
            Spacer(minLength: 0)
        }
        .padding(18)
        .frame(maxWidth: .infinity)
        .background(RoundedRectangle(cornerRadius: 22).fill(LinearGradient(colors: AiGlow, startPoint: .topLeading, endPoint: .bottomTrailing)))
        .onAppear { withAnimation(.linear(duration: 6).repeatForever(autoreverses: false)) { spin = true } }
    }
}

private struct LockedCard: View {
    let onPlans: () -> Void
    @Environment(\.pal) private var pal

    var body: some View {
        VStack(spacing: 0) {
            DorrIcon("lock.fill", tint: pal.orange)
                .frame(width: 48, height: 48)
                .background(Circle().fill(pal.orange.opacity(0.14)))
            VSpace(8)
            Text(L("dai_locked_title")).font(.dorr(15, .bold)).foregroundStyle(pal.ink)
            Text(L("dai_locked_body")).font(.dorr(13)).foregroundStyle(pal.mut).padding(.top, 4)
            VSpace(12)
            PrimaryButton(L("dai_see_plans"), color: pal.orange, icon: "rosette", onClick: onPlans)
        }
        .padding(16)
        .frame(maxWidth: .infinity)
        .surface()
    }
}

private struct AiCard: View {
    let card: DriverAiCardDto
    let onGoal: () -> Void
    @Environment(\.pal) private var pal
    @State private var shown: Double = 0

    var body: some View {
        let (icon, tint): (String, Color) = {
            switch card.type {
            case "zone": return ("mappin.circle.fill", pal.success)
            case "conflict": return ("calendar.badge.exclamationmark", pal.warning)
            default: return ("flag.fill", pal.accent)
            }
        }()
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                DorrIcon(icon, size: 20, tint: tint)
                    .frame(width: 38, height: 38)
                    .background(RoundedRectangle(cornerRadius: 12).fill(tint.opacity(0.14)))
                HSpace(10)
                Text(card.title ?? "").font(.dorr(15, .bold)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                if let c = card.confidence { ConfidencePill(confidence: c) }
            }
            if let t = card.text { Text(t).font(.dorr(14)).foregroundStyle(pal.ink).padding(.top, 8) }
            if card.type == "goal", let progress = card.progress {
                let p = min(max(progress, 0), 1)
                GeometryReader { geo in
                    ZStack(alignment: .leading) {
                        Capsule().fill(pal.line)
                        Capsule().fill(LinearGradient(colors: [pal.success, pal.accent], startPoint: .leading, endPoint: .trailing))
                            .frame(width: geo.size.width * shown)
                    }
                }
                .frame(height: 10)
                .padding(.top, 10)
                .onAppear { withAnimation(.tween(900)) { shown = p } }
                .onChange(of: p) { v in withAnimation(.tween(900)) { shown = v } }
            }
            if let why = card.why { Text("• \(why)").font(.dorr(12)).foregroundStyle(pal.mut).padding(.top, 6) }
            HStack(spacing: 8) {
                if let lat = card.lat, let lng = card.lng { Chip(L("dai_go_there")) { openMaps(lat, lng) } }
                if card.type == "goal" { Chip(L("dai_change_goal"), onClick: onGoal) }
            }
            .padding(.top, 8)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface()
    }
}

private struct ConfidencePill: View {
    let confidence: Double
    @Environment(\.pal) private var pal

    var body: some View {
        let pct = Int(confidence * 100)
        let color = pct >= 70 ? pal.success : (pct >= 45 ? pal.warning : pal.mut)
        Text(L("dai_confidence", pct)).font(.dorr(11, .bold)).foregroundStyle(color)
            .padding(.horizontal, 8).padding(.vertical, 3)
            .background(Capsule().fill(color.opacity(0.12)))
    }
}

private struct AskBox: View {
    let here: (Double, Double)?
    @Environment(\.pal) private var pal
    @State private var question = ""
    @State private var answer: (String, Double?)?
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(L("dai_ask_title")).font(.dorr(15, .bold)).foregroundStyle(pal.ink)
            VSpace(8)
            DorrTextField(value: question, onValueChange: { question = String($0.prefix(400)) }, placeholder: L("dai_ask_hint"), singleLine: false, minLines: 2)
            VSpace(10)
            PrimaryButton(L("dai_ask_send"), enabled: question.trimmingCharacters(in: .whitespacesAndNewlines).count >= 2, busy: busy, icon: "paperplane.fill", onClick: ask)
            if let error { Text(error).font(.dorr(13)).foregroundStyle(pal.danger).padding(.top, 8) }
            if let (text, confidence) = answer {
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 0) {
                        DorrIcon("sparkles", size: 16, tint: AiGlow[0])
                        HSpace(6)
                        Text(L("dai_title")).font(.dorr(12, .bold)).foregroundStyle(AiGlow[0]).frame(maxWidth: .infinity, alignment: .leading)
                        if let confidence { ConfidencePill(confidence: confidence) }
                    }
                    Text(text).font(.dorr(14)).foregroundStyle(pal.ink).padding(.top, 6)
                }
                .padding(12)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(RoundedRectangle(cornerRadius: 14).fill(AiGlow[0].opacity(0.08)))
                .padding(.top, 12)
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface()
    }

    private func ask() {
        busy = true
        error = nil
        Task {
            var body: JsonBody = ["question": question.trimmingCharacters(in: .whitespacesAndNewlines)]
            if let here { body["lat"] = here.0; body["lng"] = here.1 }
            switch await DriverApi.aiAsk(body: body) {
            case .ok(let r, _): withAnimation { answer = (r.answer ?? "", r.confidence) }
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }
}

private struct DemandSection: View {
    let demand: DemandDto?
    let onUnlock: () -> Void
    @Environment(\.pal) private var pal
    @State private var slot = 0

    var body: some View {
        if let d = demand {
            SectionLabel(L("dai_hot_now"))
            let hot = d.hotZones ?? []
            if hot.isEmpty { Text(L("dai_hot_none")).font(.dorr(13)).foregroundStyle(pal.mut) }
            ForEach(Array(hot.enumerated()), id: \.offset) { i, z in
                let shortage = z.shortage ?? false
                let tint = shortage ? pal.danger : pal.orange
                HStack(spacing: 0) {
                    DorrIcon("flame.fill", size: 20, tint: tint)
                        .frame(width: 38, height: 38)
                        .background(Circle().fill(tint.opacity(0.14)))
                    HSpace(10)
                    VStack(alignment: .leading, spacing: 0) {
                        HStack(spacing: 0) {
                            Text(L("dai_km_away", String(format: "%.1f", Double(z.distanceM ?? 0) / 1000.0))).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                            if shortage {
                                HSpace(6)
                                Text(L("dai_shortage")).font(.dorr(11, .bold)).foregroundStyle(pal.danger)
                                    .padding(.horizontal, 7).padding(.vertical, 2)
                                    .background(Capsule().fill(pal.danger.opacity(0.12)))
                            }
                        }
                        if let why = z.why { Text(why).font(.dorr(12)).foregroundStyle(pal.mut) }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    DorrIcon("location.north.fill", size: 20, tint: pal.accent)
                }
                .padding(12)
                .frame(maxWidth: .infinity)
                .surface()
                .bounceClick { openMaps(z.lat ?? 0, z.lng ?? 0) }
                .staggerIn(i)
            }

            SectionLabel(L("dai_forecast"))
            if let forecast = d.forecast {
                CasesLikeFlow(spacing: 8) {
                    ForEach(Array(forecast.enumerated()), id: \.offset) { i, s in
                        Chip(L("dai_in_min", s.inMin ?? 0), selected: slot == i) { slot = i }
                    }
                }
                let zones = forecast.indices.contains(slot) ? (forecast[slot].zones ?? []) : []
                if zones.isEmpty { Text(L("dai_forecast_none")).font(.dorr(13)).foregroundStyle(pal.mut) }
                ForEach(Array(zones.enumerated()), id: \.offset) { _, z in
                    HStack(spacing: 0) {
                        DorrIcon("chart.line.uptrend.xyaxis", size: 20, tint: pal.success)
                        HSpace(10)
                        Text(L("dai_expected", String(format: "%.1f", z.expected ?? 0), String(format: "%.1f", Double(z.distanceM ?? 0) / 1000.0)))
                            .font(.dorr(13, .semibold)).foregroundStyle(pal.ink)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        ConfidencePill(confidence: z.confidence ?? 0)
                    }
                    .padding(12)
                    .frame(maxWidth: .infinity)
                    .surface()
                    .bounceClick { openMaps(z.lat ?? 0, z.lng ?? 0) }
                }
                if let hours = d.scheduled, !hours.isEmpty {
                    SectionLabel(L("dai_scheduled"))
                    VStack(alignment: .leading, spacing: 6) {
                        ForEach(Array(hours.enumerated()), id: \.offset) { _, h in
                            HStack(spacing: 0) {
                                DorrIcon("clock", size: 16, tint: pal.accent)
                                HSpace(8)
                                Text(instantOf(h.hour).map { timeOfDay($0) } ?? (h.hour ?? "")).font(.dorr(13, .bold)).foregroundStyle(pal.ink)
                                    .frame(width: 70, alignment: .leading)
                                Text(L("dai_scheduled_row", h.booked ?? 0, String(format: "%.1f", h.usual ?? 0))).font(.dorr(12)).foregroundStyle(pal.mut)
                                Spacer(minLength: 0)
                            }
                        }
                    }
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .surface()
                }
            } else {
                HStack(spacing: 0) {
                    DorrIcon("lock.fill", size: 18, tint: pal.orange)
                    HSpace(8)
                    Text(L("dai_forecast_locked")).font(.dorr(13)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                    Text(L("dai_see_plans")).font(.dorr(13, .bold)).foregroundStyle(pal.orange)
                }
                .padding(12)
                .frame(maxWidth: .infinity)
                .surface()
                .bounceClick(onClick: onUnlock)
            }
        }
    }
}

private struct PlansSheet: View {
    let onClose: () -> Void
    let onSubscribed: () -> Void
    @Environment(\.pal) private var pal
    @State private var plans: [AiPlanDto]?
    @State private var picked: AiPlanDto?
    @State private var pin = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 10) {
                Text(L("dai_plans_title")).font(.dorr(18, .heavy)).foregroundStyle(pal.ink)
                Text(L("dai_plans_sub")).font(.dorr(12)).foregroundStyle(pal.mut)
                ForEach(Array((plans ?? []).enumerated()), id: \.offset) { _, plan in
                    let on = picked?.id == plan.id
                    VStack(alignment: .leading, spacing: 0) {
                        HStack(spacing: 0) {
                            Text(plan.name ?? "").font(.dorr(15, .bold)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                            if let badge = plan.badge { Chip(badge, selected: true, tint: pal.orange) }
                        }
                        Text([plan.price.map { "\(String(format: "%.2f", $0)) \(plan.currency ?? "")" }, plan.durationDays.map { L("dai_days", $0) }]
                            .compactMap { $0 }.joined(separator: " · "))
                            .font(.dorr(15, .heavy)).foregroundStyle(pal.accent).padding(.top, 4)
                        if let d = plan.description { Text(d).font(.dorr(12)).foregroundStyle(pal.mut).padding(.top, 4) }
                    }
                    .padding(14)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(RoundedRectangle(cornerRadius: 16).fill(on ? pal.accent.opacity(0.1) : pal.bg))
                    .bounceClick { picked = plan }
                }
                if let plan = picked {
                    DorrTextField(value: pin, onValueChange: { v in pin = String(v.filter(\.isNumber).prefix(6)) }, placeholder: L("dai_pin"),
                                  password: true, keyboardOptions: KeyboardOptions(keyboardType: .numberPad))
                    PrimaryButton(L("dai_subscribe", plan.name ?? ""), enabled: pin.count >= 4, busy: busy) { subscribe(plan) }
                }
                if let error { Text(error).font(.dorr(13)).foregroundStyle(pal.danger) }
            }
            .padding(.horizontal, 18)
            .padding(.top, 24)
            .padding(.bottom, 24)
        }
        .background(pal.card.ignoresSafeArea())
        .task {
            switch await DriverApi.aiPlans() {
            case .ok(let list, _): plans = list.filter { $0.isTrial != true }
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
        }
    }

    private func subscribe(_ plan: AiPlanDto) {
        busy = true
        error = nil
        Task {
            switch await DriverApi.aiSubscribe(pin: pin, body: ["plan_id": plan.id]) {
            case .ok: onSubscribed()
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }
}

/// Material's `AlertDialog` for the daily goal: the flag, the title, the hint and the field, Cancel / Save.
private struct GoalDialog: View {
    let current: Int?
    let onClose: () -> Void
    let onSave: (Int?) -> Void
    @Environment(\.pal) private var pal
    @State private var text: String

    init(current: Int?, onClose: @escaping () -> Void, onSave: @escaping (Int?) -> Void) {
        self.current = current
        self.onClose = onClose
        self.onSave = onSave
        _text = State(initialValue: current.map { String($0 / 100) } ?? "")
    }

    var body: some View {
        VStack(spacing: 0) {
            DorrIcon("flag.fill", tint: pal.accent)
            VSpace(16)
            Text(L("dai_goal_title")).font(.dorr(24)).foregroundStyle(pal.ink).multilineTextAlignment(.center)
            VSpace(16)
            VStack(alignment: .leading, spacing: 0) {
                Text(L("dai_goal_hint")).font(.dorr(13)).foregroundStyle(pal.mut)
                VSpace(10)
                DorrTextField(value: text, onValueChange: { v in text = String(v.filter(\.isNumber).prefix(7)) },
                              keyboardOptions: KeyboardOptions(keyboardType: .numberPad))
            }
            VSpace(24)
            HStack(spacing: 8) {
                Spacer(minLength: 0)
                Text(L("dai_cancel")).font(.dorr(14, .semibold)).foregroundStyle(pal.accent)
                    .padding(.horizontal, 12).padding(.vertical, 10)
                    .contentShape(Rectangle())
                    .onTapGesture(perform: onClose)
                Text(L("dai_save")).font(.dorr(14, .semibold)).foregroundStyle(pal.accent)
                    .padding(.horizontal, 12).padding(.vertical, 10)
                    .contentShape(Rectangle())
                    .onTapGesture {
                        let v = Int(text).flatMap { $0 > 0 ? $0 * 100 : nil }
                        onSave(v)
                    }
            }
        }
        .padding(24)
        .frame(maxWidth: 400)
        .background(RoundedRectangle(cornerRadius: 28).fill(pal.card))
        .padding(.horizontal, 32)
    }
}

/// Compose's `FlowRow`: children from the start edge, wrapping onto new lines.
private struct CasesLikeFlow: Layout {
    var spacing: CGFloat

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? .infinity
        var x: CGFloat = 0, y: CGFloat = 0, line: CGFloat = 0, widest: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > 0 && x + size.width > width { y += line + spacing; x = 0; line = 0 }
            x += size.width + spacing
            line = max(line, size.height)
            widest = max(widest, x - spacing)
        }
        return CGSize(width: proposal.width ?? widest, height: y + line)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x = bounds.minX, y = bounds.minY, line: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > bounds.minX && x + size.width > bounds.maxX { y += line + spacing; x = bounds.minX; line = 0 }
            s.place(at: CGPoint(x: x, y: y), proposal: ProposedViewSize(size))
            x += size.width + spacing
            line = max(line, size.height)
        }
    }
}

/// Google Maps directions to the place (`Intent.ACTION_VIEW` on the web link; the Maps app takes it when installed).
private func openMaps(_ lat: Double, _ lng: Double) {
    guard let url = URL(string: "https://www.google.com/maps/dir/?api=1&destination=\(lat),\(lng)&travelmode=driving") else { return }
    UIApplication.shared.open(url)
}
