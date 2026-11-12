import SwiftUI

// androidApp `freelance/FreelanceHealth.kt` — FS-106: how a running project is doing, in a small card at the top of the agreement:
// a coloured state, the reasons in plain words and one next step (what I can do now, or what I am waiting for). Information only:
// nothing here moves money or counts against anyone. Drawn from the agreement that is read once on open (and again when a health
// notice arrives); nothing is asked on a timer. Hidden when the agreement is not running; a healthy project with nothing to say shows
// just «On track».

struct HealthCard: View {
    @Environment(\.wa) private var wa
    let health: FreelanceHealthDto?

    var body: some View {
        if let health {
            let state = health.state ?? "healthy"
            let color = flHealthColor(state)
            let reasons = health.reasons ?? []
            if state == "healthy" && reasons.isEmpty {
                HStack(spacing: 6) {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 14)).foregroundStyle(color)
                    Text(L("fs_hl_on_track")).font(.dorr(12.5, .bold)).foregroundStyle(color)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 4)
            } else {
                VStack(alignment: .leading, spacing: 10) {
                    HStack(spacing: 8) {
                        Image(systemName: "waveform.path.ecg").font(.system(size: 17, weight: .semibold)).foregroundStyle(color)
                        Text(L("fs_hl_title")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                        HStack(spacing: 4) {
                            Image(systemName: flHealthIcon(state)).font(.system(size: 11, weight: .semibold))
                            Text(flHealthLabel(state)).font(.dorr(11.5, .heavy))
                        }
                        .foregroundStyle(color)
                        .padding(.horizontal, 10).padding(.vertical, 3)
                        .background(color.opacity(0.14), in: Capsule())
                    }
                    ForEach(Array(reasons.enumerated()), id: \.offset) { _, r in
                        HStack(alignment: .top, spacing: 8) {
                            Image(systemName: "exclamationmark.circle").font(.system(size: 12)).foregroundStyle(flHealthColor(r.level ?? "")).padding(.top, 3)
                            Text(verbatim: flHealthReasonText(r)).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                                .frame(maxWidth: .infinity, alignment: .leading)
                        }
                    }
                    if let step = health.nextStep {
                        // The step carries no part name: it is the one of the reason with the same code.
                        let part = reasons.first { $0.code == step.code }?.data?.part ?? ""
                        let mine = step.mine ?? false
                        FlNotice(text: flHealthStepText(step.code ?? "", mine: mine, part: part), icon: mine ? "clock" : "hourglass", color: mine ? color : wa.settingsMut)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(14)
                .settingsSurface(FlCorners.card)
                .accessibilityElement(children: .combine)
            }
        }
    }
}

func flHealthColor(_ state: String) -> Color {
    switch state {
    case "at_risk": return Color(hex: 0xF59E0B)
    case "delayed": return flAmber
    case "blocked": return flRed
    default: return flGreen
    }
}

private func flHealthIcon(_ state: String) -> String {
    switch state {
    case "at_risk": return "exclamationmark.triangle.fill"
    case "delayed": return "clock.fill"
    case "blocked": return "nosign"
    default: return "checkmark.circle.fill"
    }
}

private func flHealthLabel(_ state: String) -> String {
    switch state {
    case "at_risk": return L("fs_hl_state_at_risk")
    case "delayed": return L("fs_hl_state_delayed")
    case "blocked": return L("fs_hl_state_blocked")
    default: return L("fs_hl_state_healthy")
    }
}

/// The reason in plain words; a code the app does not know yet says nothing alarming.
private func flHealthReasonText(_ r: FreelanceHealthReasonDto) -> String {
    let part = r.data?.part ?? ""
    let days = flHealthDays(r.data?.days ?? 1)
    switch r.code ?? "" {
    case "dispute_open": return L("fs_hl_r_dispute_open", part)
    case "part_unfunded": return L("fs_hl_r_part_unfunded", part)
    case "part_overdue": return L("fs_hl_r_part_overdue", part, days)
    case "part_due_soon": return L("fs_hl_r_part_due_soon", part)
    case "part_not_started": return L("fs_hl_r_part_not_started", part)
    case "review_waiting": return L("fs_hl_r_review_waiting", part, days)
    case "report_late": return L("fs_hl_r_report_late")
    case "change_waiting": return L("fs_hl_r_change_waiting")
    case "task_overdue": return L("fs_hl_r_task_overdue", part, days, r.data?.count ?? 1)
    default: return L("fs_hl_r_other")
    }
}

/// `mine`: what I can do now; else what I am waiting for.
private func flHealthStepText(_ code: String, mine: Bool, part: String) -> String {
    let p = mine ? "fs_hl_n_" : "fs_hl_w_"
    switch code {
    case "dispute_open", "part_unfunded", "part_overdue", "part_due_soon", "part_not_started", "review_waiting", "task_overdue":
        return L(p + code, part)
    case "report_late", "change_waiting":
        return L(p + code)
    default:
        return L(p + "other")
    }
}

/// "1 day", "2 days", "3-10 days" and the rest - Arabic counts them differently.
private func flHealthDays(_ n: Int) -> String {
    switch n {
    case 1: return L("fs_hl_days_1")
    case 2: return L("fs_hl_days_2")
    case 3...10: return L("fs_hl_days_few", n)
    default: return L("fs_hl_days_many", n)
    }
}
