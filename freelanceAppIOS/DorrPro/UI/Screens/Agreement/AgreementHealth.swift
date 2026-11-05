// freelanceApp `ui/screens/AgreementHealth.kt`
import SwiftUI

/// FS-106: the colour of a project health state (the colours the agreement screens already use).
func healthColor(_ state: String?) -> Color {
    switch state {
    case "at_risk": return Color(hex: 0xF59E0B)
    case "delayed": return Color(hex: 0xD97706)
    case "blocked": return Color(hex: 0xDC2626)
    default: return Color(hex: 0x16A34A)
    }
}

private func healthStateKey(_ state: String?) -> String {
    switch state {
    case "at_risk": return "fs_health_state_at_risk"
    case "delayed": return "fs_health_state_delayed"
    case "blocked": return "fs_health_state_blocked"
    default: return "fs_health_state_healthy"
    }
}

private func healthReasonKey(_ code: String?, hasDays: Bool) -> String? {
    switch code {
    case "dispute_open": return "fs_health_r_dispute_open"
    case "part_unfunded": return "fs_health_r_part_unfunded"
    case "part_overdue": return hasDays ? "fs_health_r_part_overdue" : "fs_health_r_part_overdue_nodays"
    case "part_due_soon": return "fs_health_r_part_due_soon"
    case "part_not_started": return "fs_health_r_part_not_started"
    case "review_waiting": return "fs_health_r_review_waiting"
    case "report_late": return "fs_health_r_report_late"
    case "change_waiting": return "fs_health_r_change_waiting"
    default: return nil
    }
}

/// The next step in its two variants: `mine` = something for me to do, else what I wait for.
private func healthNextKey(_ code: String?, mine: Bool, hasDays: Bool) -> String? {
    let prefix = mine ? "fs_health_n_mine_" : "fs_health_n_wait_"
    switch code {
    case "dispute_open": return prefix + "dispute_open"
    case "part_unfunded": return prefix + "part_unfunded"
    case "part_overdue": return prefix + (hasDays ? "part_overdue" : "part_overdue_nodays")
    case "part_due_soon": return prefix + "part_due_soon"
    case "part_not_started": return prefix + "part_not_started"
    case "review_waiting": return prefix + "review_waiting"
    case "report_late": return prefix + "report_late"
    case "change_waiting": return prefix + "change_waiting"
    default: return nil
    }
}

private func healthPartName(_ data: HealthReasonDataDto?) -> String {
    if let part = data?.part, !part.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { return part }
    return L("fs_health_part_fallback")
}

private func healthDaysText(_ data: HealthReasonDataDto?) -> String {
    if let days = data?.days { return healthDays(days) }
    return ""
}

/// FS-106: how the running project is doing - a coloured state, the reasons in plain words and ONE next-step line (what I can do now,
/// else what I am waiting for). Informational only: it moves no money and says nothing about my score. Healthy with nothing to say is
/// a small "on track" line; a project that is not running has none (the card is not drawn).
struct ProjectHealthCard: View {
    @Environment(\.wa) private var wa
    let h: ProjectHealthDto

    var body: some View {
        let tint = healthColor(h.state)
        let reasons = (h.reasons ?? []).filter { healthReasonKey($0.code, hasDays: false) != nil }
        if h.state == "healthy" && reasons.isEmpty {
            HStack(spacing: 6) {
                Image(systemName: "checkmark.circle.fill").font(.system(size: 14, weight: .semibold)).foregroundStyle(tint)
                Text(L("fs_health_on_track")).font(.dorr(12.5, .semibold)).foregroundStyle(tint)
                Spacer(minLength: 0)
            }
            .padding(.horizontal, 4)
        } else {
            card(tint: tint, reasons: reasons)
        }
    }

    @ViewBuilder private func card(tint: Color, reasons: [HealthReasonDto]) -> some View {
        let step = h.nextStep
        let stepReason: HealthReasonDto? = step.flatMap { s in
            reasons.first(where: { $0.code == s.code && ($0.mine ?? false) == (s.mine ?? false) }) ?? reasons.first(where: { $0.code == s.code })
        }
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Text(L("fs_health_title")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                    .frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: L(healthStateKey(h.state))).font(.dorr(11, .bold)).foregroundStyle(tint)
                    .padding(.horizontal, 10).padding(.vertical, 3)
                    .background(tint.opacity(0.12), in: Capsule())
            }
            ForEach(Array(reasons.enumerated()), id: \.offset) { _, r in
                if let key = healthReasonKey(r.code, hasDays: r.data?.days != nil) {
                    HStack(alignment: .top, spacing: 8) {
                        Circle().fill(healthColor(r.level)).frame(width: 7, height: 7).padding(.top, 6)
                        Text(verbatim: L(key, healthPartName(r.data), healthDaysText(r.data)))
                            .font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                }
            }
            if let step, let key = healthNextKey(step.code, mine: step.mine ?? false, hasDays: stepReason?.data?.days != nil) {
                let mine = step.mine ?? false
                let accent = mine ? wa.red : wa.settingsMut
                HStack(spacing: 8) {
                    Image(systemName: "arrow.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(accent)
                    Text(verbatim: L(key, healthPartName(stepReason?.data), healthDaysText(stepReason?.data)))
                        .font(.dorr(13, mine ? .bold : .regular)).foregroundStyle(mine ? wa.settingsInk : wa.settingsMut).lineSpacing(4)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(10)
                .background(accent.opacity(0.08), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20)
    }
}

/// "1 day", "2 days", "3-10 days" and the rest - Arabic counts them differently.
private func healthDays(_ n: Int) -> String {
    switch n {
    case 1: return L("fs_health_days_1")
    case 2: return L("fs_health_days_2")
    case 3...10: return L("fs_health_days_few", n)
    default: return L("fs_health_days_many", n)
    }
}
