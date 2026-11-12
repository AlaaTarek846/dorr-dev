import SwiftUI

// androidApp `freelance/FreelanceAi.kt` — FS-401 / FS-501 (light): DORR AI for the freelance client, on the client's DORR AI plan.
//  - «خلّي دُرر AI يكتب مشروعك»: the idea in the client's own words (or voice) becomes a professional project — the form is filled in,
//    the client checks and saves. Nothing is saved or published by AI.
//  - «أنسب ناس لمشروعك»: the best freelancers and teams for a project, each with a match score, the reasons and DORR AI's words; a
//    private project's invitations go out from the list.
//  - FS-801 «ملخص مشروعك»: DORR AI's summary of a running project for its owner.

private let aiViolet = Color(hex: 0x7C3AED)
private let aiPink = Color(hex: 0xDB2777)
private let okGreen = Color(hex: 0x16A34A)

/// The glowing card that opens one of the tools — locked (it offers the DORR AI plans) when the plan does not allow AI now.
struct FlAiEntry: View {
    @Environment(\.wa) private var wa
    let title: String
    let text: String
    let action: String
    let status: FreelanceAiStatusDto
    let onOpen: () -> Void
    @State private var pulse = false

    var body: some View {
        let allowed = status.allowed == true
        Button {
            if allowed { onOpen() } else { AiPlanGate.shared.offer(L("ai_plan_required_title")) }
        } label: {
            VStack(alignment: .leading, spacing: 8) {
                HStack(spacing: 10) {
                    Image(systemName: "sparkles").font(.system(size: 18, weight: .semibold)).foregroundStyle(.white)
                        .scaleEffect(pulse ? 1.08 : 0.92)
                        .frame(width: 38, height: 38)
                        .background(LinearGradient(colors: [aiViolet, aiPink], startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    VStack(alignment: .leading, spacing: 1) {
                        Text(verbatim: title).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: text).font(.dorr(12)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.leading)
                    }
                    Spacer(minLength: 0)
                }
                HStack(spacing: 6) {
                    Image(systemName: allowed ? "sparkles" : "lock.fill").font(.system(size: 12, weight: .bold))
                    Text(verbatim: allowed ? action : L("fla_locked")).font(.dorr(12.5, .bold))
                }
                .foregroundStyle(allowed ? .white : wa.settingsInk)
                .padding(.horizontal, 14).padding(.vertical, 7)
                .background(allowed ? aiViolet : wa.settingsMut.opacity(0.18), in: Capsule())
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(LinearGradient(colors: [aiViolet.opacity(0.14), aiPink.opacity(0.10)], startPoint: .topLeading, endPoint: .bottomTrailing),
                        in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        }
        .buttonStyle(WaPressStyle())
        .onAppear { withAnimation(.easeInOut(duration: 1.1).repeatForever(autoreverses: true)) { pulse = true } }
    }
}

// MARK: - AI Project Builder

/// «اوصف فكرتك»: the idea typed or spoken, DORR AI writes the project, asks what is still unclear (answers make it sharper), shows the
/// brief, and `onUse` fills the project form with it.
struct ProjectBuilderSheet: View {
    @Environment(\.wa) private var wa
    let onDismiss: () -> Void
    let onUse: (FreelanceAiBuildDto) -> Void
    @StateObject private var speech = SpeechListener()
    @State private var idea = ""
    @State private var result: FreelanceAiBuildDto?
    @State private var answers: [String] = []
    @State private var busy = false
    @State private var error: String?

    private var ready: Bool { idea.trimmingCharacters(in: .whitespacesAndNewlines).count >= 10 }

    private func write() {
        guard ready, !busy else { return }
        busy = true
        error = nil
        let asked = result?.questions ?? []
        let pairs: [(String, String)] = asked.enumerated().compactMap { i, q in
            let a = i < answers.count ? answers[i].trimmingCharacters(in: .whitespacesAndNewlines) : ""
            return a.isEmpty ? nil : (q, a)
        }
        Task {
            do {
                if let built = try await FreelanceApi.aiBuild(idea: idea.trimmingCharacters(in: .whitespacesAndNewlines), answers: pairs).data {
                    result = built
                    answers = Array(repeating: "", count: built.questions?.count ?? 0)
                }
            } catch {
                self.error = flError(error)
            }
            busy = false
        }
    }

    var body: some View {
        let built = result
        FlSheet(icon: "sparkles", title: L("fla_builder_title"), text: L("fla_builder_sheet_sub"), footer: AnyView(VStack(spacing: 10) {
            if let built, !busy { SettingsPrimaryButton(text: L("fla_use")) { onUse(built) } }
            FlAction(text: L((built?.questions ?? []).isEmpty ? "fla_write" : "fla_rewrite"), icon: "sparkles",
                     tone: built == nil ? .primary : .soft, enabled: ready, loading: busy) { write() }
        })) {
            HStack(alignment: .top, spacing: 8) {
                DorrTextArea(value: Binding(get: { idea }, set: { idea = String($0.prefix(2000)) }), placeholder: L("fla_idea_hint"), minHeight: 96)
                Button {
                    if speech.listening {
                        speech.stop()
                    } else {
                        let before = idea
                        speech.start(onText: { heard, _ in idea = String((before.isEmpty ? heard : before + " " + heard).prefix(2000)) },
                                     onFail: { error = L("fla_no_voice") })
                    }
                } label: {
                    Image(systemName: speech.listening ? "waveform" : "mic.fill").font(.system(size: 20)).foregroundStyle(aiViolet)
                        .frame(width: 48, height: 48)
                        .background(aiViolet.opacity(speech.listening ? 0.3 : 0.14), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                }
                .buttonStyle(WaPressStyle())
                .accessibilityLabel(L("fla_listen"))
            }
            if busy {
                HStack(spacing: 10) {
                    ProgressView().tint(aiViolet)
                    Text(L("fla_writing")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                }
            }
            if let error { FlNotice(text: error, icon: "exclamationmark.circle.fill", color: flRed) }
            if let built {
                // What is still unclear: answering is optional, and "rewrite" sends the answers with the idea.
                let qs = built.questions ?? []
                if !qs.isEmpty {
                    FlHeading(icon: "questionmark.bubble.fill", text: L("fla_questions_title"))
                    ForEach(Array(qs.enumerated()), id: \.offset) { i, q in
                        Text(verbatim: q).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                        DorrTextArea(value: Binding(get: { i < answers.count ? answers[i] : "" },
                                                    set: { v in if i < answers.count { answers[i] = String(v.prefix(500)) } }),
                                     placeholder: L("fla_answer_hint"), singleLine: true)
                    }
                }
                BriefPreview(built: built)
            }
        }
        .onDisappear { speech.stop() }
    }
}

/// The project DORR AI wrote, as the client will see it before using it.
private struct BriefPreview: View {
    @Environment(\.wa) private var wa
    let built: FreelanceAiBuildDto

    var body: some View {
        let d = built.draft
        let b = built.brief
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 6) {
                Image(systemName: "checkmark.circle.fill").foregroundStyle(okGreen)
                Text(L("fla_preview_title")).font(.dorr(12.5, .bold)).foregroundStyle(okGreen)
            }
            if let t = d?.title { Text(verbatim: t).font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk) }
            if let s = b?.summary { Text(verbatim: s).font(.dorr(13.5)).foregroundStyle(wa.settingsInk).lineSpacing(4) }
            let tags = [d?.specialty?.name].compactMap { $0 } + (d?.skills ?? []).compactMap(\.name)
            if !tags.isEmpty {
                FlowLayout(spacing: 6) {
                    ForEach(Array(tags.enumerated()), id: \.offset) { i, t in
                        AiPill(text: t, color: i == 0 && d?.specialty != nil ? aiViolet : wa.accent)
                    }
                }
            }
            if let g = b?.goal { line(L("fla_goal"), g) }
            if let a = b?.audience { line(L("fla_audience"), a) }
            bullets(L("fla_features"), b?.features)
            bullets(L("fla_deliverables"), b?.deliverables)
            bullets(L("fla_acceptance"), b?.acceptance)
            bullets(L("fla_out_of_scope"), b?.outOfScope)
            bullets(L("fla_assumptions"), b?.assumptions)
            let money: String? = d?.budget?.minMinor.map { low in
                (d?.budget?.type == "range" && d?.budget?.maxMinor != nil ? "\(flMoney(low)) – \(flMoney(d?.budget?.maxMinor))" : flMoney(low)) + " " + flCurrencySign(d?.budget?.currency)
            }
            let days: String? = d?.durationDays.map { L("fla_days", $0) }
            if money != nil || days != nil {
                HStack(spacing: 6) {
                    Image(systemName: "banknote.fill").foregroundStyle(wa.accent)
                    Text(verbatim: L("fla_estimate", money ?? "—", days ?? "—")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                }
                Text(L("fla_estimate_note")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
            }
            bullets(L("fla_for_freelancers"), d?.questions)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }

    private func line(_ label: String, _ text: String) -> some View {
        VStack(alignment: .leading, spacing: 1) {
            Text(verbatim: label).font(.dorr(11.5, .bold)).foregroundStyle(wa.settingsMut)
            Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsInk)
        }
    }

    @ViewBuilder
    private func bullets(_ label: String, _ lines: [String]?) -> some View {
        if let lines, !lines.isEmpty {
            VStack(alignment: .leading, spacing: 3) {
                Text(verbatim: label).font(.dorr(11.5, .bold)).foregroundStyle(wa.settingsMut)
                ForEach(Array(lines.enumerated()), id: \.offset) { _, l in
                    Text(verbatim: "•  " + l).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                }
            }
        }
    }
}

private struct AiPill: View {
    let text: String
    let color: Color
    var icon: String?

    var body: some View {
        HStack(spacing: 4) {
            if let icon { Image(systemName: icon).font(.system(size: 10, weight: .bold)) }
            Text(verbatim: text).font(.dorr(11.5, .semibold)).lineLimit(1)
        }
        .foregroundStyle(color)
        .padding(.horizontal, 10).padding(.vertical, 4)
        .background(color.opacity(0.12), in: Capsule())
    }
}

// MARK: - Smart Matching

/// «أنسب ناس لمشروعك»: the lists, each candidate's score and reasons, DORR AI's words; invitations from here on a private project.
struct MatchesPage: View {
    @Environment(\.wa) private var wa
    let projectUuid: String
    let onBack: () -> Void
    let onTeam: (String) -> Void
    @State private var data: FreelanceMatchesDto?
    @State private var error: String?
    @State private var notice: String?
    @State private var tab = "best"
    @State private var inviting: String?
    @State private var reload = 0

    private func invite(freelancer: String? = nil, team: String? = nil) {
        guard let key = freelancer ?? team else { return }
        inviting = key
        Task {
            do {
                _ = try await FreelanceApi.invite(projectUuid, freelancers: freelancer.map { [$0] } ?? [], teams: team.map { [$0] } ?? [])
                if let freelancer, var d = data {
                    d.candidates = d.candidates?.map { c in var c = c; if c.uuid == freelancer { c.invited = true }; return c }
                    data = d
                }
                notice = L("fla_invite_done")
            } catch {
                self.error = flError(error)
            }
            inviting = nil
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: L("fla_matches_title"), onBack: onBack, trailing: data == nil ? nil : AnyView(
                Button { data = nil; reload += 1 } label: {
                    Image(systemName: "arrow.clockwise").font(.system(size: 16, weight: .semibold)).foregroundStyle(wa.settingsInk).frame(width: 40, height: 40)
                }
                .accessibilityLabel(L("fla_refresh"))
            ))
            if let d = data {
                ScrollView { content(d).padding(.horizontal, 16).padding(.bottom, 24) }
            } else if let error {
                FlNotice(text: error, icon: "exclamationmark.circle.fill", color: flRed).padding(16)
                Spacer()
            } else {
                MatchesLoading()
            }
        }
        .task(id: reload) {
            error = nil
            do { data = try await FreelanceApi.aiMatches(projectUuid, refresh: reload > 0).data } catch { self.error = flError(error) }
        }
    }

    @ViewBuilder
    private func content(_ d: FreelanceMatchesDto) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            if d.needs == "specialty" {
                FlNotice(text: L("fla_needs_specialty"), icon: "info.circle.fill", color: flAmber)
            } else {
                // DORR AI's recommendation, on top.
                if let s = d.summary {
                    HStack(alignment: .top, spacing: 10) {
                        Image(systemName: "sparkles").font(.system(size: 20)).foregroundStyle(.white)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(L("fla_ai_says")).font(.dorr(11.5, .bold)).foregroundStyle(.white.opacity(0.85))
                            Text(verbatim: s).font(.dorr(13.5, .semibold)).foregroundStyle(.white).lineSpacing(4)
                        }
                        Spacer(minLength: 0)
                    }
                    .padding(14)
                    .background(LinearGradient(colors: [aiViolet, aiPink], startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                }
                if let notice { FlNotice(text: notice, icon: "checkmark.circle.fill", color: okGreen) }
                if let error { FlNotice(text: error, icon: "exclamationmark.circle.fill", color: flRed) }
                if d.canInvite != true { FlHint(text: L("fla_invite_public")) }

                let keys = ["best", "quality", "value", "fastest"] + ((d.teams ?? []).isEmpty ? [] : ["teams"])
                let icons = ["best": "sparkles", "quality": "star.fill", "value": "banknote.fill", "fastest": "bolt.fill", "teams": "person.3.fill"]
                FlowLayout(spacing: 8) {
                    ForEach(keys, id: \.self) { k in
                        FlChip(text: L("fla_tab_" + k), selected: tab == k, icon: icons[k]) { tab = k }
                    }
                }
                if tab == "teams" {
                    ForEach(d.teams ?? []) { t in
                        TeamMatchCard(team: t, canInvite: d.canInvite == true, busy: inviting == t.uuid, onOpen: { onTeam(t.uuid) }) { invite(team: t.uuid) }
                    }
                } else {
                    let byId = Dictionary((d.candidates ?? []).map { ($0.uuid, $0) }, uniquingKeysWith: { a, _ in a })
                    let shown = (d.lists?[tab] ?? []).compactMap { byId[$0] }
                    if shown.isEmpty { Text(L("fla_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.vertical, 18) }
                    ForEach(Array(shown.enumerated()), id: \.element.uuid) { i, c in
                        MatchCard(match: c, rank: i + 1, canInvite: d.canInvite == true, busy: inviting == c.uuid) { invite(freelancer: c.uuid) }
                    }
                }
                HStack(spacing: 6) {
                    Image(systemName: "info.circle").font(.system(size: 12))
                    Text(L("fla_fair")).font(.dorr(11.5))
                }
                .foregroundStyle(wa.settingsMut)
            }
        }
        .padding(.top, 4)
    }
}

private struct MatchesLoading: View {
    @Environment(\.wa) private var wa
    @State private var pulse = false

    var body: some View {
        VStack(spacing: 12) {
            Image(systemName: "sparkles").font(.system(size: 28, weight: .semibold)).foregroundStyle(.white)
                .frame(width: 64, height: 64)
                .background(LinearGradient(colors: [aiViolet, aiPink], startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                .scaleEffect(pulse ? 1.15 : 0.85)
            Text(L("fla_matches_entry")).font(.dorr(13)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).padding(.horizontal, 32)
            FlSkeletonList(count: 3, height: 150)
        }
        .padding(.top, 40)
        .onAppear { withAnimation(.easeInOut(duration: 0.9).repeatForever(autoreverses: true)) { pulse = true } }
    }
}

/// A freelancer: rank, picture, name, the match score ring, why DORR AI picked them, the reasons, and the invitation.
private struct MatchCard: View {
    @Environment(\.wa) private var wa
    let match: FreelanceMatchDto
    let rank: Int
    let canInvite: Bool
    let busy: Bool
    let onInvite: () -> Void

    var body: some View {
        let c = match
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                ZStack(alignment: .bottomTrailing) {
                    FlAvatar(name: c.name, url: c.avatarUrl, size: 48)
                    Text(verbatim: "\(rank)").font(.dorr(10, .heavy)).foregroundStyle(.white)
                        .frame(width: 20, height: 20)
                        .background(rank == 1 ? Color(hex: 0xF59E0B) : wa.accent, in: Circle())
                }
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: c.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    if let h = c.headline, !h.isEmpty { Text(verbatim: h).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                    HStack(spacing: 8) {
                        if let r = c.rating { Text(verbatim: "★ \(r)").font(.dorr(12, .bold)).foregroundStyle(Color(hex: 0xF59E0B)) }
                        if c.availableNow == true { Text(verbatim: "● " + L("fla_tab_fastest")).font(.dorr(11.5, .semibold)).foregroundStyle(okGreen) }
                    }
                }
                Spacer(minLength: 0)
                ScoreRing(score: c.score ?? 0)
            }
            if let why = c.why {
                HStack(alignment: .top, spacing: 6) {
                    Image(systemName: "sparkles").font(.system(size: 13)).foregroundStyle(aiViolet)
                    Text(verbatim: why).font(.dorr(12.5)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                    Spacer(minLength: 0)
                }
                .padding(10)
                .background(aiViolet.opacity(0.08), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            if let reasons = c.reasons, !reasons.isEmpty {
                FlowLayout(spacing: 6) {
                    ForEach(Array(reasons.enumerated()), id: \.offset) { _, r in AiPill(text: r, color: wa.accent, icon: "checkmark.circle.fill") }
                }
            }
            if c.offered == true {
                AiPill(text: L("fla_offered"), color: okGreen, icon: "rosette")
            } else if c.invited == true {
                AiPill(text: L("fla_invited"), color: okGreen, icon: "checkmark.circle.fill")
            } else if canInvite {
                FlAction(text: L("fla_invite"), icon: "person.badge.plus", tone: .primary, loading: busy, action: onInvite)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.night ? Color(hex: 0x1F2937) : .white, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
    }
}

private struct TeamMatchCard: View {
    @Environment(\.wa) private var wa
    let team: FreelanceTeamMatchDto
    let canInvite: Bool
    let busy: Bool
    let onOpen: () -> Void
    let onInvite: () -> Void

    var body: some View {
        let t = team
        VStack(alignment: .leading, spacing: 10) {
            Button(action: onOpen) {
                HStack(spacing: 10) {
                    FlAvatar(name: t.name, url: t.logoUrl, size: 48)
                    VStack(alignment: .leading, spacing: 2) {
                        HStack(spacing: 4) {
                            Text(verbatim: t.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                            if t.verified == true { Image(systemName: "checkmark.seal.fill").font(.system(size: 14)).foregroundStyle(Color(hex: 0x2563EB)) }
                        }
                        Text(verbatim: L("fla_team_members", t.membersCount ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                    Spacer(minLength: 0)
                    ScoreRing(score: t.score ?? 0)
                }
            }
            .buttonStyle(.plain)
            if let why = t.why { Text(verbatim: why).font(.dorr(12.5)).foregroundStyle(wa.settingsInk) }
            if let reasons = t.reasons, !reasons.isEmpty {
                FlowLayout(spacing: 6) {
                    ForEach(Array(reasons.enumerated()), id: \.offset) { _, r in AiPill(text: r, color: wa.accent, icon: "checkmark.circle.fill") }
                }
            }
            if canInvite { FlAction(text: L("fla_invite"), icon: "person.badge.plus", tone: .primary, loading: busy, action: onInvite) }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.night ? Color(hex: 0x1F2937) : .white, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
    }
}

/// The match score as a ring: green from 75, amber from 50.
private struct ScoreRing: View {
    @Environment(\.wa) private var wa
    let score: Int

    var body: some View {
        let color: Color = score >= 75 ? okGreen : (score >= 50 ? Color(hex: 0xD97706) : wa.settingsMut)
        ZStack {
            Circle().stroke(color.opacity(0.15), lineWidth: 4)
            Circle().trim(from: 0, to: CGFloat(score) / 100).stroke(color, style: StrokeStyle(lineWidth: 4, lineCap: .round)).rotationEffect(.degrees(-90))
            VStack(spacing: -2) {
                Text(verbatim: "\(score)").font(.dorr(15, .heavy))
                Text(verbatim: "%").font(.dorr(9))
            }
            .foregroundStyle(color)
        }
        .frame(width: 52, height: 52)
    }
}

// MARK: - AI Project Manager (FS-801)

/// The card on a running agreement that opens DORR AI's summary — shown only when the tool is on in the client's country.
struct FlPmEntry: View {
    let onOpen: () -> Void
    @State private var status: FreelanceAiStatusDto?

    var body: some View {
        Group {
            if let st = status, st.manager == true {
                FlAiEntry(title: L("fla_pm_title"), text: L("fla_pm_entry"), action: L("fla_pm_open"), status: st, onOpen: onOpen)
            }
        }
        .task { status = try? await FreelanceApi.aiStatus().data }
    }
}

/// «ملخص مشروعك»: what happened on the running project today, this week or this month — DORR AI's headline and lists (done, decisions,
/// changes, what may slip, the next step of each side), the numbers DORR counted, and the project's health. Read when it opens and when
/// the period changes (the server keeps the answer while nothing new happens); the refresh button asks DORR AI again.
struct ProjectSummaryPage: View {
    @Environment(\.wa) private var wa
    let projectUuid: String
    let onBack: () -> Void
    @State private var days = 7
    @State private var data: FreelancePmSummaryDto?
    @State private var error: String?
    @State private var refresh = 0

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: L("fla_pm_title"), onBack: onBack, trailing: data?.quiet == false ? AnyView(
                Button { refresh += 1 } label: {
                    Image(systemName: "arrow.clockwise").font(.system(size: 16, weight: .semibold)).foregroundStyle(wa.settingsInk).frame(width: 40, height: 40)
                }
                .accessibilityLabel(L("fla_refresh"))
            ) : nil)
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    HStack(spacing: 8) {
                        ForEach([(1, "fla_pm_day"), (7, "fla_pm_week"), (30, "fla_pm_month")], id: \.0) { period, label in
                            FlChip(text: L(label), selected: days == period, icon: nil) { days = period }
                        }
                    }
                    if let error {
                        FlNotice(text: error, icon: "exclamationmark.circle.fill", color: flRed)
                    } else if let d = data {
                        PmBody(d: d)
                    } else {
                        PmLoading()
                    }
                    HStack(spacing: 6) {
                        Image(systemName: "info.circle").font(.system(size: 12))
                        Text(L("fla_pm_note")).font(.dorr(11.5))
                    }
                    .foregroundStyle(wa.settingsMut)
                }
                .padding(.horizontal, 16).padding(.top, 4).padding(.bottom, 24)
            }
        }
        .task(id: "\(days)|\(refresh)") {
            data = nil
            error = nil
            do { data = try await FreelanceApi.aiSummary(projectUuid, days: days, refresh: refresh > 0).data } catch { self.error = flError(error) }
        }
    }
}

private struct PmLoading: View {
    @Environment(\.wa) private var wa
    @State private var pulse = false

    var body: some View {
        VStack(spacing: 12) {
            Image(systemName: "sparkles").font(.system(size: 28, weight: .semibold)).foregroundStyle(.white)
                .frame(width: 64, height: 64)
                .background(LinearGradient(colors: [aiViolet, aiPink], startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                .scaleEffect(pulse ? 1.15 : 0.85)
            Text(L("fla_pm_reading")).font(.dorr(13)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).padding(.horizontal, 32)
            FlSkeletonList(count: 2, height: 120)
        }
        .frame(maxWidth: .infinity)
        .padding(.top, 24)
        .onAppear { withAnimation(.easeInOut(duration: 0.9).repeatForever(autoreverses: true)) { pulse = true } }
    }
}

private struct PmBody: View {
    @Environment(\.wa) private var wa
    let d: FreelancePmSummaryDto

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            if let headline = d.summary?.headline {
                HStack(alignment: .top, spacing: 10) {
                    Image(systemName: "sparkles").font(.system(size: 20)).foregroundStyle(.white)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(L("fla_ai_says")).font(.dorr(11.5, .bold)).foregroundStyle(.white.opacity(0.85))
                        Text(verbatim: headline).font(.dorr(14, .semibold)).foregroundStyle(.white).lineSpacing(4)
                    }
                    Spacer(minLength: 0)
                }
                .padding(14)
                .background(LinearGradient(colors: [aiViolet, aiPink], startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            }
            // The numbers DORR counted itself (never the model's).
            let facts = factPills
            if !facts.isEmpty {
                FlowLayout(spacing: 6) {
                    ForEach(Array(facts.enumerated()), id: \.offset) { _, f in AiPill(text: f.0, color: f.1) }
                }
            }
            if d.quiet == true || d.summary == nil {
                FlNotice(text: L("fla_pm_quiet"), icon: "checkmark.circle.fill", color: okGreen)
            } else if let s = d.summary {
                PmSection(icon: "checkmark.circle.fill", color: okGreen, title: L("fla_pm_progress"), lines: s.progress)
                PmSection(icon: "hammer.fill", color: wa.accent, title: L("fla_pm_decisions"), lines: s.decisions)
                PmSection(icon: "arrow.triangle.2.circlepath", color: flAmber, title: L("fla_pm_changes"), lines: s.changes)
                PmSection(icon: "exclamationmark.triangle.fill", color: flRed, title: L("fla_pm_risks"), lines: s.risks)
                PmSection(icon: "flag.fill", color: aiViolet, title: L("fla_pm_next_client"), lines: s.nextClient)
                PmSection(icon: "hourglass", color: wa.settingsMut, title: L("fla_pm_next_freelancer"), lines: s.nextFreelancer)
            }
            if let health = d.health { HealthCard(health: health) }
        }
    }

    private var factPills: [(String, Color)] {
        let f = d.facts ?? FreelancePmFactsDto()
        var out: [(String, Color)] = []
        if let parts = f.parts, parts > 0 { out.append((L("fla_pm_f_parts", f.partsDone ?? 0, parts), wa.accent)) }
        if let p = f.percent { out.append((L("fla_pm_f_percent", p), aiViolet)) }
        if let n = f.tasksDone, n > 0 { out.append((L("fla_pm_f_done", n), okGreen)) }
        if let n = f.tasksOverdue, n > 0 { out.append((L("fla_pm_f_overdue", n), flRed)) }
        if let n = f.files, n > 0 { out.append((L("fla_pm_f_files", n), wa.accent)) }
        if let n = f.messages, n > 0 { out.append((L("fla_pm_f_messages", n), wa.settingsMut)) }
        return out
    }
}

private struct PmSection: View {
    @Environment(\.wa) private var wa
    let icon: String
    let color: Color
    let title: String
    let lines: [String]?

    var body: some View {
        if let lines, !lines.isEmpty {
            VStack(alignment: .leading, spacing: 6) {
                HStack(spacing: 8) {
                    Image(systemName: icon).font(.system(size: 13, weight: .bold)).foregroundStyle(color)
                        .frame(width: 28, height: 28)
                        .background(color.opacity(0.14), in: RoundedRectangle(cornerRadius: 9, style: .continuous))
                    Text(verbatim: title).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                }
                ForEach(Array(lines.enumerated()), id: \.offset) { _, l in
                    Text(verbatim: "•  " + l).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(wa.night ? Color(hex: 0x1F2937) : .white, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
    }
}
