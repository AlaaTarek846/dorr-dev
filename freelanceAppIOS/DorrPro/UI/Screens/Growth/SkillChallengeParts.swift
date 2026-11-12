import SwiftUI

// freelanceApp `ui/screens/SkillChallengeParts.kt`.

// FS-602: the practical challenge of a skill — its card on the skill's row (the brief, where my challenge stands as a timeline, the
// reviewer's note, when I may send again) and the form that sends one (a link to the work and a note).

private let noteMin = 20
private let noteMax = 2000

private func challengeDay(_ iso: String?) -> String {
    guard let date = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.timeZone = .current
    f.dateFormat = "d MMM yyyy"
    return f.string(from: date)
}

/// An http(s) address with a host (the server's `url:http,https` rule decides; this only keeps the button off for an obvious miss).
private func isHttpLink(_ text: String) -> Bool {
    text.range(of: #"^https?://[^\s/$.?#][^\s]*$"#, options: [.regularExpression, .caseInsensitive]) != nil
}

extension SkillChallengeStateDto {
    /// Whether a row has a challenge to show: one sent before, or a level I may ask for now.
    var shown: Bool { current != nil || ((enabled ?? false) && nextLevel != nil) }
}

/// A skill's challenge on its row: the level it is for, the brief (before sending, and after a refusal), my challenge as a three-step
/// timeline with the reviewer's note, the date I may send again, and the buttons (send / withdraw / open my work).
struct SkillChallengeCard: View {
    @Environment(\.wa) private var wa
    @Environment(\.openURL) private var openURL
    let state: SkillChallengeStateDto
    let withdrawing: Bool
    let onSend: () -> Void
    let onWithdraw: (SkillChallengeDto) -> Void

    var body: some View {
        let current = state.current
        let target = (current?.status == "submitted" ? current?.targetLevel : nil) ?? state.nextLevel ?? current?.targetLevel
        let tint = skillLevelColor(target)
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 10) {
                Image(systemName: "trophy.fill").font(.system(size: 16, weight: .semibold)).foregroundStyle(tint)
                    .frame(width: 36, height: 36)
                    .background(tint.opacity(0.16), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: target != nil ? L("skl_ch_for", skillLevelLabel(target)) : L("skl_ch_title"))
                        .font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                    if current == nil {
                        Text(L("skl_ch_sub")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            if let current {
                SkillChallengeTimeline(challenge: current)
                if current.status == "accepted", let granted = current.grantedLevel {
                    HStack(spacing: 8) {
                        Text(verbatim: L("skl_ch_granted", skillLevelLabel(granted))).font(.dorr(13, .bold)).foregroundStyle(QualityGreen)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        SkillLevelPill(level: granted)
                    }
                }
                if let note = current.decisionNote, !note.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                    NoteBanner(icon: "text.bubble.fill", text: note, tint: current.status == "rejected" ? QualityRed : QualityGreen, title: L("skl_ch_note_from"))
                }
                if let link = current.link, !link.isEmpty, let url = URL(string: link) {
                    Button { openURL(url) } label: {
                        HStack(spacing: 6) {
                            Image(systemName: "arrow.up.forward.square").font(.system(size: 14, weight: .semibold))
                            Text(L("skl_ch_my_link")).font(.dorr(13, .bold))
                        }
                        .foregroundStyle(wa.red)
                        .padding(.vertical, 4)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
            }
            if let brief = state.brief, !brief.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty, state.nextLevel != nil,
               current == nil || current?.status != "submitted" {
                NoteBanner(icon: "doc.text.fill", text: brief, tint: wa.red, title: L("skl_ch_brief"))
            }
            if let at = state.retryAt {
                HStack(spacing: 6) {
                    Image(systemName: "clock").font(.system(size: 13)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: L("skl_ch_retry", challengeDay(at))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
            }
            if let current, current.canWithdraw == true {
                ActionButton(L("skl_ch_withdraw"), "arrow.uturn.backward", .danger, loading: withdrawing) { onWithdraw(current) }
            }
            if state.canSend == true, let next = state.nextLevel {
                ActionButton(L("skl_ch_send", skillLevelLabel(next)), "paperplane.fill", .primary, action: onSend)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(tint.opacity(wa.night ? 0.12 : 0.06), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
    }
}

private struct TimelineStep {
    let label: String
    let sub: String?
    let tint: Color
    let icon: String
    let reached: Bool
}

/// Sent → under review → the decision, side by side; the steps reached are filled in their colour, the line between them too.
private struct SkillChallengeTimeline: View {
    @Environment(\.wa) private var wa
    let challenge: SkillChallengeDto

    private var steps: [TimelineStep] {
        let c = challenge
        func dateOrNil(_ iso: String?) -> String? { let d = challengeDay(iso); return d.isEmpty ? nil : d }
        let review = c.status == "submitted"
            ? TimelineStep(label: L("skl_ch_step_review"), sub: L("skl_ch_submitted"), tint: QualityAmber, icon: "hourglass", reached: true)
            : TimelineStep(label: L("skl_ch_step_review"), sub: nil, tint: QualityGreen, icon: "checkmark", reached: true)
        let decision: TimelineStep
        switch c.status {
        case "accepted": decision = TimelineStep(label: L("skl_ch_accepted"), sub: dateOrNil(c.decidedAt), tint: QualityGreen, icon: "checkmark", reached: true)
        case "rejected": decision = TimelineStep(label: L("skl_ch_rejected"), sub: dateOrNil(c.decidedAt), tint: QualityRed, icon: "xmark", reached: true)
        case "withdrawn": decision = TimelineStep(label: L("skl_ch_withdrawn"), sub: dateOrNil(c.decidedAt), tint: QualitySlate, icon: "arrow.uturn.backward", reached: true)
        default: decision = TimelineStep(label: L("skl_ch_step_decided"), sub: nil, tint: wa.settingsMut, icon: "trophy.fill", reached: false)
        }
        return [
            TimelineStep(label: L("skl_ch_step_sent"), sub: dateOrNil(c.sentAt), tint: QualityGreen, icon: "checkmark", reached: true),
            review,
            decision,
        ]
    }

    var body: some View {
        let all = steps
        let track = wa.settingsMut.opacity(0.2)
        HStack(alignment: .top, spacing: 0) {
            ForEach(0..<all.count, id: \.self) { i in
                let step = all[i]
                VStack(spacing: 5) {
                    HStack(spacing: 0) {
                        Rectangle().fill(i == 0 ? Color.clear : (step.reached ? all[i - 1].tint : track)).frame(height: 2)
                        Image(systemName: step.icon).font(.system(size: 12, weight: .bold))
                            .foregroundStyle(step.reached ? Color.white : step.tint)
                            .frame(width: 28, height: 28)
                            .background(step.reached ? step.tint : step.tint.opacity(0.14), in: Circle())
                        Rectangle().fill(i == all.count - 1 ? Color.clear : (all[i + 1].reached ? step.tint : track)).frame(height: 2)
                    }
                    Text(verbatim: step.label).font(.dorr(11.5, .bold)).foregroundStyle(step.reached ? wa.settingsInk : wa.settingsMut)
                        .lineLimit(1).multilineTextAlignment(.center)
                    if let sub = step.sub {
                        Text(verbatim: sub).font(.dorr(10.5)).foregroundStyle(wa.settingsMut).lineLimit(1).multilineTextAlignment(.center)
                    }
                }
                .frame(maxWidth: .infinity)
            }
        }
    }
}

/// The form that sends a challenge for the next level of `test`'s skill: where I stand → the level asked for, the brief, a link to the work
/// (http/https) and a note of 20..2000 characters with its count. `onSent` gets the line to show once the server took it.
struct SkillChallengeForm: View {
    @Environment(\.wa) private var wa
    let test: SkillTestDto
    let onSent: (String) -> Void

    @State private var link = ""
    @State private var note = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        if let target = test.challenge?.nextLevel {
            form(target)
        }
    }

    @ViewBuilder
    private func form(_ target: String) -> some View {
        let trimmedLink = link.trimmingCharacters(in: .whitespacesAndNewlines)
        let linkOk = isHttpLink(trimmedLink)
        let badLink = !trimmedLink.isEmpty && !linkOk
        let noteLength = note.trimmingCharacters(in: .whitespacesAndNewlines).count
        VStack(alignment: .leading, spacing: 16) {
            FormIntro(icon: "trophy.fill", title: L("skl_ch_send", skillLevelLabel(target)), text: L("skl_ch_form_intro", test.name ?? ""))
            HStack(spacing: 8) {
                SkillLevelPill(level: test.badge?.level)
                Image(systemName: "arrow.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut)
                SkillLevelPill(level: target)
            }
            if let brief = test.challenge?.brief, !brief.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                NoteBanner(icon: "doc.text.fill", text: brief, tint: wa.red, title: L("skl_ch_brief"))
            }
            VStack(alignment: .leading, spacing: 6) {
                DorrTextField(value: $link.profileTake(255), label: L("skl_ch_link"), placeholder: "https://", icon: "link", keyboard: .URL, error: badLink)
                    .environment(\.layoutDirection, .leftToRight)
                Text(L(badLink ? "skl_ch_bad_link" : "skl_ch_link_hint")).font(.dorr(11.5)).foregroundStyle(badLink ? QualityRed : wa.settingsMut)
                    .lineSpacing(2).fixedSize(horizontal: false, vertical: true)
            }
            VStack(alignment: .leading, spacing: 6) {
                DorrTextField(value: $note.profileTake(noteMax), label: L("skl_ch_note"), placeholder: L("skl_ch_note_ph"), icon: "text.alignleft",
                              singleLine: false, minLines: 5)
                HStack {
                    Text(verbatim: L("skl_ch_note_min", noteMin)).font(.dorr(11.5))
                        .foregroundStyle((1..<noteMin).contains(noteLength) ? QualityAmber : wa.settingsMut)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    Text(verbatim: "\(note.count)/\(noteMax)").font(.dorr(11.5, .semibold)).foregroundStyle(wa.settingsMut)
                        .environment(\.layoutDirection, .leftToRight)
                }
            }
            if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(QualityRed) }
            SettingsPrimaryButton(
                text: L("skl_ch_send", skillLevelLabel(target)),
                enabled: linkOk && (noteMin...noteMax).contains(noteLength) && !busy, loading: busy
            ) { send(target, link: trimmedLink) }
        }
        .frame(maxWidth: 520, alignment: .leading)
        .frame(maxWidth: .infinity)
    }

    private func send(_ target: String, link: String) {
        busy = true
        error = nil
        let body = SkillChallengeRequest(targetLevel: target, link: link, note: note.trimmingCharacters(in: .whitespacesAndNewlines))
        Task {
            let r = await SkillTestsApi.challenge(test.skillId ?? 0, body)
            if r.value != nil {
                onSent(L("skl_ch_sent"))
            } else {
                error = r.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
            busy = false
        }
    }
}
