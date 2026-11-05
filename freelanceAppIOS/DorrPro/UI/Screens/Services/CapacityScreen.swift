import SwiftUI

// freelanceApp `ui/screens/CapacityScreen.kt`.

private func whenText(_ iso: String?) -> String {
    guard let date = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    // Android formats with the phone's own locale here.
    f.locale = .current
    f.timeZone = .current
    f.dateFormat = "EEE d MMM, HH:mm"
    return f.string(from: date)
}

/// FL-L10, «my capacity»: how many projects I take at once (only running work counts), how full I am now, and who waits in line for my
/// services. Read when the page opens, when the connection returns and when a notice arrives (someone joins the line, work ends) — never
/// on a timer.
struct CapacityScreen: View {
    let onBack: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var data: CapacityDto?
    @State private var failure: String?
    @State private var limited = false
    @State private var draft = 3
    @State private var saving = false
    @State private var saved = false

    private func apply(_ d: CapacityDto) {
        data = d
        limited = d.max != nil
        draft = d.max ?? max(draft, max(1, d.running ?? 0))
    }

    private func load() async {
        let r = await CapacityApi.show()
        if let d = r.value {
            apply(d)
            failure = nil
        } else if data == nil {
            failure = r.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    private func save() {
        saving = true
        Task {
            let r = await CapacityApi.update(CapacityRequest(max: limited ? draft : nil))
            if let d = r.value {
                apply(d)
                saved = true
                failure = nil
            } else {
                failure = r.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
            saving = false
        }
    }

    var body: some View {
        Page(title: L("cap_title"), onBack: onBack) {
            if let d = data {
                let running = d.running ?? 0
                CapacityGauge(d: d)
                VStack(alignment: .leading, spacing: 14) {
                    HStack(spacing: 0) {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(L("cap_limit_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                            Text(L("cap_limit_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        RedToggle(on: limited) { limited.toggle(); saved = false }
                    }
                    if limited {
                        HStack(spacing: 0) {
                            CapStepButton(icon: "minus", enabled: draft > 1) { draft -= 1; saved = false }
                            VStack(spacing: 0) {
                                Text(verbatim: "\(draft)").font(.dorr(40, .heavy)).foregroundStyle(wa.red)
                                Text(L("cap_projects_at_once")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                            }
                            .padding(.horizontal, 26)
                            CapStepButton(icon: "plus", enabled: draft < (d.maxChoice ?? 20)) { draft += 1; saved = false }
                        }
                        .frame(maxWidth: .infinity)
                        if draft < running {
                            Text(L("cap_below_running", running)).font(.dorr(12)).foregroundStyle(Color(hex: 0xD97706)).lineSpacing(2)
                        }
                    }
                    SettingsPrimaryButton(text: saved ? L("cap_saved") : L("cap_save"),
                                          enabled: !saved && (limited != (d.max != nil) || (limited && draft != d.max)),
                                          loading: saving, action: save)
                }
                .padding(16)
                .frame(maxWidth: 520, alignment: .leading)
                .settingsSurface(20)
                .frame(maxWidth: .infinity)
                WaitLine(d: d)
            } else if let failure {
                EmptyState(icon: "speedometer", title: L("cap_title"), body: failure).padding(.top, 70)
            } else {
                SkeletonDetail()
            }
        }
        .messageToast(data != nil ? failure : nil)
        .task(id: network.reconnectTick) { await load() }
        .onReceive(Realtime.shared.changed) { Task { await load() } }
    }
}

/// How full I am: a bar of the running projects and the places kept, against my cap.
private struct CapacityGauge: View {
    @Environment(\.wa) private var wa
    let d: CapacityDto
    @State private var fill: CGFloat = 0

    var body: some View {
        let max = d.max
        let used = (d.running ?? 0) + (d.holds ?? 0)
        let target: CGFloat = max == nil || max == 0 ? 0 : min(Swift.max(CGFloat(used) / CGFloat(max!), 0), 1)
        VStack(alignment: .leading, spacing: 10) {
            Text(verbatim: max == nil ? L("cap_state_open") : (d.full ?? false) ? L("cap_state_full") : L("cap_state_room", max! - used))
                .font(.dorr(18, .heavy)).foregroundStyle(.white)
            HStack(spacing: 8) {
                GaugeStat(value: d.running ?? 0, label: L("cap_stat_running"))
                GaugeStat(value: d.holds ?? 0, label: L("cap_stat_holds"))
                GaugeStat(value: d.waiting ?? 0, label: L("cap_stat_waiting"))
            }
            if let max {
                GeometryReader { proxy in
                    ZStack(alignment: .leading) {
                        Capsule().fill(Color.white.opacity(0.25))
                        Capsule().fill(Color.white).frame(width: proxy.size.width * fill)
                    }
                }
                .frame(height: 10)
                Text(L("cap_used_of", used, max)).font(.dorr(12)).foregroundStyle(.white.opacity(0.9))
            }
        }
        .padding(20)
        .frame(maxWidth: 520, alignment: .leading)
        .background(LinearGradient(colors: [wa.red, wa.red.opacity(0.72)], startPoint: .topLeading, endPoint: .bottomTrailing),
                    in: RoundedRectangle(cornerRadius: 24, style: .continuous))
        .frame(maxWidth: .infinity)
        .onAppear { withAnimation(.easeInOut(duration: 0.8)) { fill = target } }
        .onChange(of: target) { value in withAnimation(.easeInOut(duration: 0.8)) { fill = value } }
    }
}

private struct GaugeStat: View {
    let value: Int
    let label: String

    var body: some View {
        VStack(spacing: 0) {
            Text("\(value)").font(.dorr(22, .heavy)).foregroundStyle(.white)
            Text(label).font(.dorr(11.5)).foregroundStyle(.white.opacity(0.85))
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 10)
        .background(Color.white.opacity(0.16), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

private struct CapStepButton: View {
    @Environment(\.wa) private var wa
    let icon: String
    let enabled: Bool
    let action: () -> Void

    var body: some View {
        let tone = enabled ? wa.red : wa.settingsMut.opacity(0.4)
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 20, weight: .semibold)).foregroundStyle(tone)
                .frame(width: 48, height: 48)
                .overlay(Circle().stroke(tone, lineWidth: 1.5))
                .contentShape(Circle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

/// The clients in line for my services: those holding a freed place first, then the rest by when they joined.
private struct WaitLine: View {
    @Environment(\.wa) private var wa
    let d: CapacityDto

    var body: some View {
        let line = d.line ?? []
        HStack(spacing: 8) {
            Image(systemName: "person.2.fill").font(.system(size: 16)).foregroundStyle(wa.settingsInk)
            Text(L("cap_line_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
            if !line.isEmpty { SvcPill(text: "\(line.count)", tone: serviceTone("awaiting", night: wa.night)) }
        }
        .padding(.top, 4)
        .frame(maxWidth: 520)
        .frame(maxWidth: .infinity)
        if line.isEmpty {
            VStack(spacing: 10) {
                Image(systemName: "person.2.fill").font(.system(size: 22)).foregroundStyle(wa.red)
                    .frame(width: 52, height: 52).background(Circle().fill(wa.red.opacity(0.12)))
                Text(L("cap_line_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(3).multilineTextAlignment(.center)
            }
            .padding(20)
            .frame(maxWidth: 520)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .frame(maxWidth: .infinity)
        } else {
            ForEach(Array(line.enumerated()), id: \.offset) { _, e in LineRow(e: e) }
        }
    }
}

private struct LineRow: View {
    @Environment(\.wa) private var wa
    let e: WaitlistEntryDto

    var body: some View {
        let holding = e.status == "offered"
        HStack(spacing: 12) {
            ZStack {
                Circle().fill(wa.red.opacity(0.12))
                if holding {
                    Image(systemName: "hourglass").font(.system(size: 17)).foregroundStyle(wa.red)
                } else {
                    Text(verbatim: e.position.map { "\($0)" } ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.red)
                }
            }
            .frame(width: 40, height: 40)
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: e.client?.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                Text(verbatim: e.service?.title ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                if holding {
                    Text(L("cap_holding_until", whenText(e.holdUntil))).font(.dorr(12, .semibold)).foregroundStyle(wa.red)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if holding { SvcPill(text: L("cap_holding"), tone: serviceTone("active", night: wa.night)) }
        }
        .padding(14)
        .frame(maxWidth: 520)
        .settingsSurface(18)
        .frame(maxWidth: .infinity)
    }
}
