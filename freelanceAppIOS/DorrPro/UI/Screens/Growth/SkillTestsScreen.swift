import SwiftUI

// freelanceApp `ui/screens/SkillTestsScreen.kt`.

/// FL-L08: the test being taken, or the answer to it, on top of the list. Nothing here is kept after the screen is left but the attempt on the server.
private enum Stage {
    case listing
    case running(attempt: SkillAttemptDto, endsAt: Date)
    case done(result: SkillResultDto, name: String?)
    /// FS-602: the form that sends a practical challenge for the next level of this skill.
    case challenge(test: SkillTestDto)

    var isListing: Bool { if case .listing = self { return true } else { return false } }
}

private func formatted(_ iso: String?, _ pattern: String) -> String {
    guard let date = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.timeZone = .current
    f.dateFormat = pattern
    return f.string(from: date)
}

private func day(_ iso: String?) -> String { formatted(iso, "d MMM yyyy") }

private func dayTime(_ iso: String?) -> String { formatted(iso, "EEE d MMM, HH:mm") }

/// Skill tests and badges: pick a skill of mine that has a test, answer a timed multiple-choice test, and see the mark. The server draws,
/// times and marks — the countdown here is only the clock of this phone counting down to the end the server gave. The list is read when the
/// page opens, when the connection returns and when a notice arrives, never on a timer.
struct SkillTestsScreen: View {
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpenSkills: () -> Void

    @State private var overview: SkillTestsDto?
    @State private var failure: String?
    @State private var stage: Stage = .listing
    @State private var busySkill = 0
    @State private var notice: String?
    // FS-602: the challenge asked to be withdrawn (the question is open) and the one being withdrawn.
    @State private var askWithdraw: SkillChallengeDto?
    @State private var withdrawing: String?

    private var offlineText: String { L("login_error_offline") }
    private var genericError: String { L("login_error_generic") }

    private func load() async {
        let r = await SkillTestsApi.overview()
        if let data = r.value {
            overview = data
            failure = nil
        } else if overview == nil {
            failure = r.errorText(offlineText) ?? genericError
        }
    }

    private func start(_ test: SkillTestDto) {
        let skillId = test.skillId ?? 0
        busySkill = skillId
        notice = nil
        Task {
            let r = await SkillTestsApi.start(skillId)
            if let attempt = r.value {
                stage = .running(attempt: attempt, endsAt: Date().addingTimeInterval(TimeInterval(attempt.secondsLeft ?? 0)))
            } else {
                notice = r.errorText(offlineText) ?? genericError
                await load()
            }
            busySkill = 0
        }
    }

    private func withdraw(_ c: SkillChallengeDto) {
        withdrawing = c.uuid
        notice = nil
        Task {
            let r = await SkillTestsApi.withdrawChallenge(c.uuid ?? "")
            if r.value != nil {
                await load()
            } else {
                notice = r.errorText(offlineText) ?? genericError
            }
            withdrawing = nil
        }
    }

    private var isChallenge: Bool { if case .challenge = stage { return true } else { return false } }

    /// Leaving a running test keeps it on the server: opening it again resumes the same questions until the time is up.
    private func back() {
        if stage.isListing {
            onBack()
        } else {
            stage = .listing
            Task { await load() }
        }
    }

    var body: some View {
        Page(title: L(isChallenge ? "skl_ch_title" : "skt_title"), onBack: back) {
            switch stage {
            case .running(let attempt, let endsAt):
                TestRunner(
                    attempt: attempt, endsAt: endsAt,
                    onResult: { result, name in
                        stage = .done(result: result, name: name)
                        Task { await load() }
                    },
                    offlineText: offlineText, genericError: genericError,
                    name: overview?.tests?.first { $0.skillId == attempt.skillId }?.name
                )
                .id(attempt.attemptId ?? 0)
            case .done(let result, let name):
                TestResult(result: result, name: name, onDone: { stage = .listing })
            case .challenge(let test):
                SkillChallengeForm(test: test, onSent: { text in
                    notice = text
                    stage = .listing
                    Task { await load() }
                })
                .id(test.skillId ?? 0)
            case .listing:
                if let data = overview {
                    TestList(
                        data: data, busySkill: busySkill, withdrawing: withdrawing, onStart: start, onOpenSkills: onOpenSkills,
                        onChallenge: { test in
                            notice = nil
                            stage = .challenge(test: test)
                        },
                        onWithdraw: { askWithdraw = $0 }
                    )
                } else if let failure {
                    EmptyState(icon: "questionmark.bubble.fill", title: L("skt_title"), body: failure).padding(.top, 70)
                } else {
                    SkeletonDetail()
                }
            }
        }
        .task(id: network.reconnectTick) { await load() }
        .onReceive(Realtime.shared.changed) { if stage.isListing { Task { await load() } } }
        .messageToast(stage.isListing ? (notice ?? (overview != nil ? failure : nil)) : nil)
        .dorrDialog(isPresented: Binding(get: { askWithdraw != nil }, set: { if !$0 { askWithdraw = nil } })) {
            AlertDialog(
                onDismissRequest: { askWithdraw = nil },
                text: { Text(L("skl_ch_withdraw_q")) },
                confirmButton: {
                    DialogButton(onClick: {
                        let c = askWithdraw
                        askWithdraw = nil
                        if let c { withdraw(c) }
                    }) { Text(L("skl_ch_withdraw")) }
                },
                dismissButton: { DialogButton(onClick: { askWithdraw = nil }) { Text(L("common_cancel")) } }
            )
        }
    }
}

private struct TestList: View {
    @Environment(\.wa) private var wa
    let data: SkillTestsDto
    let busySkill: Int
    let withdrawing: String?
    let onStart: (SkillTestDto) -> Void
    let onOpenSkills: () -> Void
    let onChallenge: (SkillTestDto) -> Void
    let onWithdraw: (SkillChallengeDto) -> Void

    var body: some View {
        let tests = data.tests ?? []
        Hero(data: data)
        if !(data.enabled ?? false) {
            EmptyState(icon: "questionmark.bubble.fill", title: L("skt_off_title"), body: L("skt_off_body")).padding(.top, 8)
        } else if tests.isEmpty {
            EmptyState(icon: "questionmark.bubble.fill", title: L("skt_none_title"), body: L("skt_none_body")).padding(.top, 8)
            Button(action: onOpenSkills) {
                Text(L("skt_open_skills")).font(.dorr(14, .bold)).foregroundStyle(wa.red).padding(12).contentShape(Rectangle())
            }
            .buttonStyle(.plain)
        } else {
            ForEach(Array(tests.enumerated()), id: \.offset) { _, test in
                TestRow(
                    test: test, loading: busySkill != 0 && busySkill == test.skillId,
                    withdrawing: withdrawing != nil && withdrawing == test.challenge?.current?.uuid,
                    onStart: { onStart(test) }, onChallenge: { onChallenge(test) }, onWithdraw: onWithdraw
                )
            }
        }
    }
}

/// What I hold: a trophy, how many badges, and a chip for each.
private struct Hero: View {
    @Environment(\.wa) private var wa
    let data: SkillTestsDto

    var body: some View {
        let badges = data.badges ?? []
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 14) {
                Image(systemName: "trophy.fill").font(.system(size: 26)).foregroundStyle(.white)
                    .frame(width: 54, height: 54).background(.white.opacity(0.2), in: Circle())
                VStack(alignment: .leading, spacing: 3) {
                    Text(verbatim: badges.isEmpty ? L("skt_hero_none") : L("skt_hero_count", badges.count))
                        .font(.dorr(18, .heavy)).foregroundStyle(.white)
                    Text(L("skt_hero_sub")).font(.dorr(12)).foregroundStyle(.white.opacity(0.9)).lineSpacing(3)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            if !badges.isEmpty {
                HStack(spacing: 6) {
                    ForEach(Array(badges.prefix(3).enumerated()), id: \.offset) { _, b in
                        HStack(spacing: 4) {
                            Image(systemName: "checkmark.seal.fill").font(.system(size: 12)).foregroundStyle(.white)
                            Text(verbatim: (b.name ?? "") + (b.level.map { " · " + skillLevelLabel($0) } ?? "")).font(.dorr(12, .bold)).foregroundStyle(.white).lineLimit(1)
                        }
                        .padding(.horizontal, 10).padding(.vertical, 5)
                        .background(.white.opacity(0.2), in: Capsule())
                    }
                    Spacer(minLength: 0)
                }
            }
        }
        .padding(20)
        .frame(maxWidth: 520, alignment: .leading)
        .background(LinearGradient(colors: [wa.red, wa.red.opacity(0.72)], startPoint: .topLeading, endPoint: .bottomTrailing),
                    in: RoundedRectangle(cornerRadius: 24, style: .continuous))
        .frame(maxWidth: .infinity)
    }
}

/// One skill: its test, the badge I hold as a level pill and a four-step meter, renewing a badge that ends soon (FS-602), and the practical
/// challenge for the next level.
private struct TestRow: View {
    @Environment(\.wa) private var wa
    let test: SkillTestDto
    let loading: Bool
    let withdrawing: Bool
    let onStart: () -> Void
    let onChallenge: () -> Void
    let onWithdraw: (SkillChallengeDto) -> Void

    var body: some View {
        let inProgress = test.inProgress ?? false
        let renewable = test.renewable ?? false
        let tint = test.badge != nil ? skillLevelColor(test.badge?.level) : wa.red
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 12) {
                Image(systemName: test.badge != nil ? "checkmark.seal.fill" : "questionmark.bubble.fill")
                    .font(.system(size: 18, weight: .semibold)).foregroundStyle(tint)
                    .frame(width: 42, height: 42).background(tint.opacity(0.12), in: Circle())
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: test.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(L("skt_meta", test.questions ?? 0, test.minutes ?? 0, test.passPercent ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if let badge = test.badge {
                    SkillLevelPill(level: badge.level)
                } else if inProgress {
                    SvcPill(text: L("skt_in_progress"), tone: serviceTone("awaiting", night: wa.night))
                }
            }
            if let badge = test.badge {
                SkillLevelMeter(level: badge.level)
                HStack(spacing: 8) {
                    SvcPill(text: L("skt_badge_held", badge.scorePercent ?? 0), tone: serviceTone("active", night: wa.night))
                    Text(verbatim: badge.expiresAt != nil ? L("skt_badge_until", day(badge.expiresAt)) : L("skt_badge_forever"))
                        .font(.dorr(12)).foregroundStyle(renewable ? QualityAmber : wa.settingsMut)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
            } else if let intermediate = test.intermediatePercent, intermediate > 0 {
                Text(L("skl_intermediate_hint", intermediate)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
            }
            if test.badge != nil && renewable {
                NoteBanner(icon: "arrow.triangle.2.circlepath", text: L("skl_renew_body"), tint: QualityAmber, title: L("skl_renew_title"))
                if let retakeAt = test.retakeAt, !inProgress {
                    Text(L("skt_retake_at", dayTime(retakeAt))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                } else {
                    SettingsPrimaryButton(
                        text: L(inProgress ? "skt_resume" : "skl_renew"),
                        enabled: (test.canStart ?? false) || inProgress, loading: loading, action: onStart
                    )
                }
            } else if test.badge != nil {
                EmptyView()
            } else if let retakeAt = test.retakeAt {
                Text(L("skt_retake_at", dayTime(retakeAt))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            } else {
                SettingsPrimaryButton(
                    text: L(inProgress ? "skt_resume" : "skt_start"),
                    enabled: (test.canStart ?? false) || inProgress, loading: loading, action: onStart
                )
            }
            if let challenge = test.challenge, challenge.shown {
                SkillChallengeCard(state: challenge, withdrawing: withdrawing, onSend: onChallenge, onWithdraw: onWithdraw)
            }
        }
        .padding(16)
        .frame(maxWidth: 520, alignment: .leading)
        .settingsSurface(20)
        .frame(maxWidth: .infinity)
    }
}

/// One question at a time with a countdown to the end the server gave; the last question sends the answers, and so does the end of the time.
private struct TestRunner: View {
    @Environment(\.wa) private var wa
    let attempt: SkillAttemptDto
    let endsAt: Date
    let onResult: (SkillResultDto, String?) -> Void
    let offlineText: String
    let genericError: String
    let name: String?

    @State private var answers: [Int: Int] = [:]
    @State private var index = 0
    @State private var sending = false
    @State private var error: String?
    @State private var left = 0

    private func secondsLeft() -> Int { max(Int(endsAt.timeIntervalSinceNow), 0) }

    private func send() {
        if sending { return }
        sending = true
        error = nil
        let body = SkillSubmitRequest(answers: Dictionary(uniqueKeysWithValues: answers.map { (String($0.key), $0.value) }))
        Task {
            let r = await SkillTestsApi.submit(attempt.attemptId ?? 0, body)
            if let result = r.value {
                onResult(result, name)
            } else {
                error = r.errorText(offlineText) ?? genericError
                sending = false
            }
        }
    }

    var body: some View {
        let questions = attempt.questions ?? []
        Group {
            if index < questions.count {
                let q = questions[index]
                content(q, questions)
            }
        }
        // The phone's clock counting down to a time the server already gave — nothing is asked of the server meanwhile.
        .task {
            while !Task.isCancelled {
                left = secondsLeft()
                if left <= 0 { send(); break }
                try? await Task.sleep(nanoseconds: 1_000_000_000)
            }
        }
        .messageToast(error)
    }

    @ViewBuilder
    private func content(_ q: SkillQuestionDto, _ questions: [SkillQuestionDto]) -> some View {
        let lowTime = (1...59).contains(left)
        let tone = lowTime ? Color(hex: 0xDC2626) : wa.red
        HStack(spacing: 10) {
            Text(L("skt_question_of", index + 1, questions.count)).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                .frame(maxWidth: .infinity, alignment: .leading)
            HStack(spacing: 4) {
                Image(systemName: "timer").font(.system(size: 13, weight: .semibold)).foregroundStyle(tone)
                Text(verbatim: String(format: "%d:%02d", left / 60, left % 60)).font(.dorr(13, .heavy)).foregroundStyle(tone)
            }
            .padding(.horizontal, 10).padding(.vertical, 5)
            .background(tone.opacity(0.12), in: Capsule())
            .environment(\.layoutDirection, .leftToRight)
        }
        .frame(maxWidth: 520)
        GeometryReader { geo in
            ZStack(alignment: .leading) {
                Capsule().fill(wa.red.opacity(0.14))
                Capsule().fill(wa.red).frame(width: geo.size.width * CGFloat(index + 1) / CGFloat(max(questions.count, 1)))
            }
        }
        .frame(maxWidth: 520)
        .frame(height: 6)
        Text(verbatim: q.prompt ?? "").font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(6)
            .frame(maxWidth: 520, alignment: .leading).padding(.top, 6)
        ForEach(Array((q.options ?? []).enumerated()), id: \.offset) { i, text in
            let picked = answers[q.id ?? 0] == i
            let border = picked ? wa.red : wa.settingsMut.opacity(0.25)
            Button { answers[q.id ?? 0] = i } label: {
                HStack(spacing: 12) {
                    ZStack {
                        Circle().stroke(border, lineWidth: 2).frame(width: 22, height: 22)
                        if picked { Circle().fill(wa.red).frame(width: 12, height: 12) }
                    }
                    Text(verbatim: text).font(.dorr(14)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(14)
                .background(picked ? wa.red.opacity(0.10) : .clear, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(border, lineWidth: 1.5))
                .contentShape(Rectangle())
                .animation(.easeOut(duration: 0.2), value: picked)
            }
            .buttonStyle(.plain)
            .frame(maxWidth: 520)
        }
        Spacer().frame(height: 4)
        let last = index == questions.count - 1
        HStack(spacing: 10) {
            if index > 0 {
                Button { index -= 1 } label: {
                    Text(L("skt_prev")).font(.dorr(15, .heavy)).foregroundStyle(wa.red)
                        .padding(.horizontal, 22).padding(.vertical, 15)
                        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.red, lineWidth: 1.5))
                        .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
            SettingsPrimaryButton(text: L(last ? "skt_finish" : "skt_next"), loading: sending) {
                if last { send() } else { index += 1 }
            }
        }
        .frame(maxWidth: 520)
        if last && answers.count < questions.count {
            Text(L("skt_unanswered", questions.count - answers.count)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
        }
    }
}

private struct TestResult: View {
    @Environment(\.wa) private var wa
    let result: SkillResultDto
    let name: String?
    let onDone: () -> Void

    var body: some View {
        let ok = result.passed ?? false
        let tone = ok ? Color(hex: 0x16A34A) : Color(hex: 0xD97706)
        VStack(spacing: 12) {
            CoachRing(percent: result.scorePercent ?? 0, size: 132, track: tone.opacity(0.16), bar: tone, ink: tone)
            Image(systemName: ok ? "checkmark.circle.fill" : "timer").font(.system(size: 30)).foregroundStyle(tone).frame(width: 34, height: 34)
            Text(L(result.status == "passed" ? "skt_passed" : (result.status == "expired" ? "skt_expired" : "skt_failed")))
                .font(.dorr(20, .heavy)).foregroundStyle(wa.settingsInk)
            Text(L("skt_score_line", result.correctCount ?? 0, result.total ?? 0, result.passPercent ?? 0)).font(.dorr(13)).foregroundStyle(wa.settingsMut)
            // FS-602: the level the mark gave, on the four-step meter.
            if ok, let level = result.badge?.level {
                SkillLevelPill(level: level)
                SkillLevelMeter(level: level).padding(.horizontal, 12)
            }
            if ok {
                Text(L("skt_passed_body", name ?? "")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4).multilineTextAlignment(.center)
            } else if let retakeAt = result.retakeAt {
                Text(L("skt_retake_at", dayTime(retakeAt))).font(.dorr(13)).foregroundStyle(wa.settingsMut)
            }
            Spacer().frame(height: 6)
            SettingsPrimaryButton(text: L("skt_done"), action: onDone)
        }
        .frame(maxWidth: 520)
        .padding(.top, 16)
        .frame(maxWidth: .infinity)
    }
}
