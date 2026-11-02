import SwiftUI

// freelanceApp `ui/screens/ProfileCoachScreen.kt`.

/// FL-L11, «improve my profile»: what to do next to be found and chosen more. The server decides what is worth saying (the same rules for
/// every plan) and ranks it; this writes each step in plain words and opens the screen that fixes it. Read when the page opens, when the
/// connection returns and when a notice arrives — never on a timer — so a step ticks off as soon as the profile changes.
///
/// `rememberCoach()` + `CoachState`: what was read — `coach` is nil until the first answer, and `failure` says why it did not come.
/// Hold it as a `@StateObject` and attach `.coachLoading(state)` to the view that shows it.
@MainActor
final class CoachState: ObservableObject {
    @Published private(set) var coach: CoachDto?
    @Published private(set) var failure: String?

    func load() async {
        let result = await ProfileCoachApi.coach()
        if let data = result.value {
            coach = data
            failure = nil
        } else if coach == nil {
            failure = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }
}

/// The two triggers of `rememberCoach`: the connection coming back (and the first appearance), and a notice arriving.
private struct CoachLoading: ViewModifier {
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject var state: CoachState

    func body(content: Content) -> some View {
        content
            .task(id: network.reconnectTick) { await state.load() }
            .onReceive(Realtime.shared.changed) { Task { await state.load() } }
    }
}

extension View {
    func coachLoading(_ state: CoachState) -> some View { modifier(CoachLoading(state: state)) }
}

/// The full page: the ring, a line for where the profile stands, and every step with what it is worth.
struct ProfileCoachScreen: View {
    let onBack: () -> Void
    let onOpen: (String) -> Void
    @StateObject private var state = CoachState()

    var body: some View {
        Page(title: L("coach_title"), onBack: onBack) {
            if let coach = state.coach {
                CoachBody(coach: coach, onOpen: onOpen)
            } else if let failure = state.failure {
                EmptyState(icon: "person.crop.circle.fill", title: L("coach_title"), body: failure).padding(.top, 70)
            } else {
                SkeletonDetail()
            }
        }
        .coachLoading(state)
        .messageToast(state.coach != nil ? state.failure : nil)
    }
}

private struct CoachBody: View {
    @Environment(\.wa) private var wa
    let coach: CoachDto
    let onOpen: (String) -> Void

    var body: some View {
        let tips = coach.tips ?? []
        CoachHero(coach: coach)
        if tips.isEmpty {
            EmptyState(icon: "trophy.fill", title: L("coach_great_title"), body: L("coach_great_body")).padding(.top, 8)
        } else {
            ForEach(["high", "medium", "low"], id: \.self) { impact in
                let group = tips.filter { ($0.impact ?? "low") == impact }
                if !group.isEmpty {
                    Text(verbatim: impactTitle(impact)).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                        .frame(maxWidth: .infinity, alignment: .leading).padding(.top, 4)
                    ForEach(Array(group.enumerated()), id: \.offset) { _, tip in
                        TipRow(tip: tip) { onOpen(tip.action ?? "profile") }
                    }
                }
            }
        }
    }
}

/// The ring on the accent colour with where the profile stands in words, and how many steps of the bar are done.
private struct CoachHero: View {
    @Environment(\.wa) private var wa
    let coach: CoachDto

    var body: some View {
        HStack(spacing: 18) {
            CoachRing(percent: coach.percent ?? 0, size: 92, track: .white.opacity(0.25), bar: .white, ink: .white)
            VStack(alignment: .leading, spacing: 4) {
                Text(verbatim: levelTitle(coach.level ?? "start")).font(.dorr(18, .heavy)).foregroundStyle(.white)
                Text(L("coach_steps_done", coach.done ?? 0, coach.total ?? 0)).font(.dorr(12)).foregroundStyle(.white.opacity(0.88))
                if !(coach.tips ?? []).isEmpty {
                    Text(L("coach_next_hint")).font(.dorr(12)).foregroundStyle(.white.opacity(0.88)).lineSpacing(3)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(20)
        .frame(maxWidth: 520)
        .background(LinearGradient(colors: [wa.red, wa.red.opacity(0.72)], startPoint: .topLeading, endPoint: .bottomTrailing),
                    in: RoundedRectangle(cornerRadius: 24, style: .continuous))
        .frame(maxWidth: .infinity)
    }
}

/// A ring that fills to the percent when it appears; the number sits in the middle (left to right in either language).
/// The colours default to the accent track / accent bar / ink, like the Kotlin defaults.
struct CoachRing: View {
    @Environment(\.wa) private var wa
    let percent: Int
    let size: CGFloat
    var track: Color?
    var bar: Color?
    var ink: Color?
    @State private var sweep: CGFloat = 0

    var body: some View {
        let stroke = size * 0.11
        ZStack {
            Circle().stroke(track ?? wa.red.opacity(0.14), style: StrokeStyle(lineWidth: stroke, lineCap: .round))
            Circle().trim(from: 0, to: sweep)
                .stroke(bar ?? wa.red, style: StrokeStyle(lineWidth: stroke, lineCap: .round))
                .rotationEffect(.degrees(-90))
            Text(verbatim: "\(percent)%").font(.dorr(size * 0.22, .heavy)).foregroundStyle(ink ?? wa.settingsInk)
        }
        .padding(stroke / 2)
        .frame(width: size, height: size)
        .environment(\.layoutDirection, .leftToRight)
        .onAppear { withAnimation(.easeOut(duration: 0.9)) { sweep = CGFloat(min(max(percent, 0), 100)) / 100 } }
        .onChange(of: percent) { p in withAnimation(.easeOut(duration: 0.9)) { sweep = CGFloat(min(max(p, 0), 100)) / 100 } }
    }
}

private struct TipRow: View {
    @Environment(\.wa) private var wa
    let tip: CoachTipDto
    let onClick: () -> Void

    var body: some View {
        if let text = tipText(tip.key ?? "") {
            let tone = impactTone(tip.impact ?? "low", wa)
            let points = tip.points ?? 0
            Button(action: onClick) {
                HStack(spacing: 12) {
                    Image(systemName: tipIcon(tip.key ?? "")).font(.system(size: 18, weight: .semibold)).foregroundStyle(tone)
                        .frame(width: 42, height: 42).background(tone.opacity(0.14), in: Circle())
                    VStack(alignment: .leading, spacing: 3) {
                        Text(L(text.0)).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                        Text(L(text.1)).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                        if points > 0 {
                            Text(verbatim: "+\(points)%").font(.dorr(12, .heavy)).foregroundStyle(tone)
                                .padding(.horizontal, 8).padding(.vertical, 2)
                                .background(tone.opacity(0.12), in: Capsule())
                                .environment(\.layoutDirection, .leftToRight)
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Image(systemName: "chevron.forward").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 22, height: 22)
                }
                .padding(14)
                .frame(maxWidth: 520)
                .settingsSurface(18)
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .frame(maxWidth: .infinity)
        }
    }
}

/// The card on Home: the ring and the next two steps, and the way to all of them. Once nothing is left to do it shrinks to a line of
/// thanks; before the first answer it shows the percent the account already carries, so the card never jumps in.
struct CoachCard: View {
    @Environment(\.wa) private var wa
    let fallbackPercent: Int
    let onAll: () -> Void
    let onOpen: (String) -> Void
    @StateObject private var state = CoachState()

    var body: some View {
        let coach = state.coach
        let percent = coach?.percent ?? fallbackPercent
        let tips = coach?.tips ?? []
        VStack(alignment: .leading, spacing: 12) {
            Button(action: onAll) {
                HStack(spacing: 14) {
                    CoachRing(percent: percent, size: 64)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(L("coach_card_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: coach == nil ? L("home_completeness") : (tips.isEmpty ? L("coach_great_title") : L("coach_card_sub", tips.count)))
                            .font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    if !tips.isEmpty {
                        Image(systemName: "chevron.forward").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 22, height: 22)
                    }
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            ForEach(Array(tips.prefix(2).enumerated()), id: \.offset) { _, tip in
                if let text = tipText(tip.key ?? "") {
                    let tone = impactTone(tip.impact ?? "low", wa)
                    Button { onOpen(tip.action ?? "profile") } label: {
                        HStack(spacing: 10) {
                            Image(systemName: tipIcon(tip.key ?? "")).font(.system(size: 16, weight: .semibold)).foregroundStyle(tone).frame(width: 20, height: 20)
                            Text(L(text.0)).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).lineLimit(2)
                                .frame(maxWidth: .infinity, alignment: .leading)
                            if (tip.points ?? 0) > 0 {
                                Text(verbatim: "+\(tip.points ?? 0)%").font(.dorr(12, .heavy)).foregroundStyle(tone)
                                    .environment(\.layoutDirection, .leftToRight)
                            }
                        }
                        .padding(.horizontal, 12).padding(.vertical, 10)
                        .background(tone.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
            }
            if tips.count > 2 {
                Button(action: onAll) {
                    Text(L("coach_see_all", tips.count)).font(.dorr(13, .bold)).foregroundStyle(wa.red).multilineTextAlignment(.center)
                        .frame(maxWidth: .infinity).padding(.vertical, 6).contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20)
        .coachLoading(state)
    }
}

// MARK: - what each step says

private func levelTitle(_ level: String) -> String {
    switch level {
    case "great": return L("coach_level_great")
    case "good": return L("coach_level_good")
    default: return L("coach_level_start")
    }
}

private func impactTitle(_ impact: String) -> String {
    switch impact {
    case "high": return L("coach_impact_high")
    case "medium": return L("coach_impact_medium")
    default: return L("coach_impact_low")
    }
}

/// High is the accent, medium amber, low grey-blue: how much a step matters is readable before the words.
private func impactTone(_ impact: String, _ wa: WaPalette) -> Color {
    switch impact {
    case "high": return wa.red
    case "medium": return Color(hex: 0xD97706)
    default: return Color(hex: 0x64748B)
    }
}

/// The title and the one-line why of a step, or nil for one this version of the app does not know (it is then left out).
private func tipText(_ key: String) -> (String, String)? {
    let known: Set<String> = [
        "name", "avatar", "headline", "bio", "email", "languages", "experience", "specialties", "skills", "work", "portfolio",
        "more_skills", "more_portfolio", "verification", "first_service",
    ]
    guard known.contains(key) else { return nil }
    return ("coach_tip_\(key)", "coach_tip_\(key)_body")
}

private func tipIcon(_ key: String) -> String {
    switch key {
    case "name": return "person.fill"
    case "avatar": return "camera.fill"
    case "headline": return "captions.bubble.fill"
    case "bio": return "text.alignleft"
    case "email": return "at"
    case "languages": return "globe"
    case "experience": return "clock.fill"
    case "specialties": return "square.grid.2x2.fill"
    case "skills", "more_skills": return "bolt.fill"
    case "work", "portfolio", "more_portfolio": return "briefcase.fill"
    case "verification": return "checkmark.seal.fill"
    case "first_service": return "storefront.fill"
    default: return "checkmark.circle.fill"
    }
}
