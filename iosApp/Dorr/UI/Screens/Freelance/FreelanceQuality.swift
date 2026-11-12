import SwiftUI

// androidApp `freelance/FreelanceQuality.kt` — Phase 6, quality and trust as a client reads a freelancer: the reputation card (FS-604)
// with «how is it worked out?», the level of a skill badge (FS-602), and a portfolio work that is «Verified by Dorr» with its case
// study (FS-601).

private let qGreen = Color(hex: 0x16A34A)
private let qTeal = Color(hex: 0x0D9488)
private let qAmber = Color(hex: 0xD97706)
private let qRed = Color(hex: 0xDC2626)

/// excellent · good · fair · low; «new» is the quiet grey.
func reputationLevelColor(_ level: String?, mut: Color) -> Color {
    switch level {
    case "excellent": return qGreen
    case "good": return qTeal
    case "fair": return qAmber
    case "low": return qRed
    default: return mut
    }
}

func reputationLevelName(_ level: String?) -> String {
    switch level {
    case "excellent": return L("fl_rep_level_excellent")
    case "good": return L("fl_rep_level_good")
    case "fair": return L("fl_rep_level_fair")
    case "low": return L("fl_rep_level_low")
    default: return L("fl_rep_level_new")
    }
}

private func indicatorName(_ key: String?) -> String {
    switch key {
    case "quality": return L("fl_rep_quality")
    case "delivery": return L("fl_rep_delivery")
    case "communication": return L("fl_rep_communication")
    case "completion": return L("fl_rep_completion")
    case "repeat_clients": return L("fl_rep_repeat_clients")
    default: return L("fl_rep_technical")
    }
}

/// The level a skill badge was certified at (FS-602), or nil when the server sent none.
func skillLevelName(_ level: String?) -> String? {
    switch level {
    case "beginner": return L("fl_skill_level_beginner")
    case "intermediate": return L("fl_skill_level_intermediate")
    case "advanced": return L("fl_skill_level_advanced")
    case "expert": return L("fl_skill_level_expert")
    default: return nil
    }
}

/// 1 (beginner) … 4 (expert); 0 when unknown.
func skillLevelRank(_ level: String?) -> Int {
    switch level {
    case "beginner": return 1
    case "intermediate": return 2
    case "advanced": return 3
    case "expert": return 4
    default: return 0
    }
}

/// Four dots, filled up to the level.
struct SkillLevelDots: View {
    let level: String?
    let tint: Color
    var dot: CGFloat = 5

    var body: some View {
        let rank = skillLevelRank(level)
        if rank > 0 {
            HStack(spacing: 2) {
                ForEach(0..<4, id: \.self) { i in
                    Circle().fill(i < rank ? tint : tint.opacity(0.22)).frame(width: dot, height: dot)
                }
            }
        }
    }
}

/// Where a figure comes from, in plain words («From 12 ratings», «4 parts, 3 on time», «3 certified skills, 2 verified works»).
private func sourceLine(_ i: FlReputationIndicatorDto) -> String? {
    let s = i.source
    let count = s?.count ?? 0
    let hits = s?.hits ?? 0
    var parts: [String] = []
    switch s?.kind {
    case "ratings": if count > 0 { parts.append(L("fl_rep_src_ratings", count)) }
    case "parts": if count > 0 { parts.append(L("fl_rep_src_parts", count, hits)) }
    case "projects": if count > 0 { parts.append(L("fl_rep_src_projects", count, hits)) }
    case "clients": if count > 0 { parts.append(L("fl_rep_src_clients", count, hits)) }
    case "certifications":
        if count > 0 { parts.append(L("fl_rep_src_certs", count)) }
        if let works = s?.verifiedWorks, works > 0 { parts.append(L("fl_rep_src_works", works)) }
    default: break
    }
    if let excluded = s?.excluded, excluded > 0 { parts.append(L("fl_rep_src_excluded", excluded)) }
    return parts.isEmpty ? nil : parts.joined(separator: "، ")
}

/// FS-604: the freelancer's reputation — the overall score on a ring, then the six figures, each a bar in its level's colour and a line
/// saying what it was worked out from. «How is it worked out?» opens the honest explanation.
struct ReputationCard: View {
    @Environment(\.wa) private var wa
    let rep: FlReputationDto?
    let onHow: () -> Void

    var body: some View {
        if let rep, let rows = rep.indicators, !rows.isEmpty {
            FlCard(icon: "chart.bar.xaxis", title: L("fl_rep_title")) {
                overallRow(rep.overall)
                Rectangle().fill(wa.settingsMut.opacity(0.15)).frame(height: 1)
                ForEach(Array(rows.enumerated()), id: \.offset) { _, i in ReputationRow(i: i) }
                Button(action: onHow) {
                    HStack(spacing: 6) {
                        Image(systemName: "questionmark.circle").font(.system(size: 14, weight: .semibold))
                        Text(L("fl_rep_how")).font(.dorr(12.5, .bold))
                    }
                    .foregroundStyle(wa.accent)
                    .padding(.horizontal, 12).padding(.vertical, 7)
                    .background(wa.accent.opacity(0.08), in: Capsule())
                }
                .buttonStyle(.plain)
            }
        }
    }

    @ViewBuilder
    private func overallRow(_ overall: Int?) -> some View {
        let level: String = {
            guard let o = overall else { return "new" }
            if o >= 85 { return "excellent" }
            if o >= 70 { return "good" }
            if o >= 50 { return "fair" }
            return "low"
        }()
        let tone = reputationLevelColor(level, mut: wa.settingsMut)
        HStack(spacing: 14) {
            ZStack {
                Circle().stroke(tone.opacity(0.15), lineWidth: 5)
                Circle().trim(from: 0, to: CGFloat(overall ?? 0) / 100).stroke(tone, style: StrokeStyle(lineWidth: 5, lineCap: .round)).rotationEffect(.degrees(-90))
                Text(verbatim: overall.map { "\($0)" } ?? "—").font(.dorr(19, .heavy)).foregroundStyle(tone)
            }
            .frame(width: 64, height: 64)
            VStack(alignment: .leading, spacing: 4) {
                Text(L("fl_rep_overall")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                if overall != nil {
                    FlDotPill(text: reputationLevelName(level), color: tone)
                } else {
                    Text(L("fl_rep_overall_new_line")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                }
                Text(L("fl_rep_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }
}

private struct ReputationRow: View {
    @Environment(\.wa) private var wa
    let i: FlReputationIndicatorDto

    var body: some View {
        let name = indicatorName(i.key)
        let tone = reputationLevelColor(i.level, mut: wa.settingsMut)
        let fresh = i.value == nil || i.level == "new" || i.level == nil
        let value = min(max(i.value ?? 0, 0), 100)
        let source = sourceLine(i)
        let levelName = reputationLevelName(i.level)
        let said = [fresh ? L("fl_rep_new_line") : "\(value)% · \(levelName)", source].compactMap { $0 }.joined(separator: "، ")
        VStack(alignment: .leading, spacing: 5) {
            HStack(spacing: 8) {
                Text(verbatim: name).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                Spacer(minLength: 0)
                if fresh {
                    Text(verbatim: levelName).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
                } else {
                    Text(verbatim: levelName).font(.dorr(11.5, .bold)).foregroundStyle(tone)
                    Text(verbatim: "\(value)").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                }
            }
            if fresh {
                Text(L("fl_rep_new_line")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
            } else {
                GeometryReader { g in
                    ZStack(alignment: .leading) {
                        Capsule().fill(tone.opacity(wa.night ? 0.22 : 0.13))
                        Capsule().fill(tone).frame(width: g.size.width * CGFloat(value) / 100)
                    }
                }
                .frame(height: 7)
            }
            if let source { Text(verbatim: source).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineSpacing(2) }
            // The best certifications behind «technical quality», each with its level.
            if i.key == "technical", let best = i.source?.best, !best.isEmpty {
                FlowLayout(spacing: 5, lineSpacing: 4) {
                    ForEach(Array(best.enumerated()), id: \.offset) { _, b in
                        HStack(spacing: 5) {
                            Text(verbatim: [b.name, skillLevelName(b.level)].compactMap { $0 }.joined(separator: " · ")).font(.dorr(11, .bold)).lineLimit(1)
                            SkillLevelDots(level: b.level, tint: qTeal, dot: 4)
                        }
                        .foregroundStyle(qTeal)
                        .padding(.horizontal, 8).padding(.vertical, 3)
                        .background(qTeal.opacity(0.10), in: Capsule())
                    }
                }
            }
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(L("fl_rep_row_desc", name, said))
    }
}

/// «How is it worked out?»: each figure in plain words, with what never counts against the freelancer.
struct ReputationHowSheet: View {
    @Environment(\.wa) private var wa
    let onDismiss: () -> Void

    /// Each figure's name and how it is worked out.
    private static let rows: [(title: String, text: String)] = [
        ("fl_rep_quality", "fl_rep_how_quality"),
        ("fl_rep_delivery", "fl_rep_how_delivery"),
        ("fl_rep_communication", "fl_rep_how_communication"),
        ("fl_rep_completion", "fl_rep_how_completion"),
        ("fl_rep_repeat_clients", "fl_rep_how_repeat"),
        ("fl_rep_technical", "fl_rep_how_technical"),
        ("fl_rep_overall", "fl_rep_how_overall"),
    ]

    var body: some View {
        FlSheet(icon: "chart.bar.xaxis", title: L("fl_rep_how_title"), text: L("fl_rep_how_body")) {
            ForEach(Array(Self.rows.enumerated()), id: \.offset) { _, row in
                VStack(alignment: .leading, spacing: 3) {
                    Text(L(row.title)).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(L(row.text)).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            FlNotice(text: L("fl_rep_how_levels"), icon: "trophy.fill", color: wa.accent)
            FlNotice(text: L("fl_rep_how_fix"), icon: "exclamationmark.bubble.fill", color: qAmber)
            FlAction(text: L("fl_rep_how_ok"), tone: .primary, action: onDismiss)
        }
    }
}

// MARK: - Portfolio works (FS-601)

/// «YYYY-MM» as the month's name and year, with Latin digits («October 2026», «أكتوبر 2026»).
func workMonth(_ raw: String?) -> String? {
    guard let raw, !raw.isEmpty else { return nil }
    let ym = String(raw.prefix(7))
    let parse = DateFormatter()
    parse.locale = Locale(identifier: "en_US_POSIX")
    parse.dateFormat = "yyyy-MM"
    guard let date = parse.date(from: ym) else { return ym }
    let out = DateFormatter()
    out.locale = AppLocale.locale
    out.dateFormat = "LLLL yyyy"
    return out.string(from: date)
}

private extension FreelanceCaseStudyDto {
    var hasText: Bool { [problem, solution, role, tools, results].contains { !($0 ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty } }
}

/// The green «Verified by Dorr» mark on a work.
struct VerifiedWorkBadge: View {
    @Environment(\.wa) private var wa
    var compact = true

    var body: some View {
        HStack(spacing: 3) {
            Image(systemName: "checkmark.seal.fill").font(.system(size: compact ? 10 : 13))
            Text(L("fl_work_verified")).font(.dorr(compact ? 10.5 : 12, .heavy)).lineLimit(1)
        }
        .foregroundStyle(qGreen)
        .padding(.horizontal, compact ? 7 : 10).padding(.vertical, compact ? 2 : 4)
        .background(qGreen.opacity(wa.night ? 0.22 : 0.12), in: Capsule())
    }
}

/// A portfolio work in a profile's list: cover, title, «Verified by Dorr · month», a line, and the report flag.
struct PortfolioWorkRow: View {
    @Environment(\.wa) private var wa
    let work: FreelancePortfolioDto
    let onOpen: () -> Void
    let onReport: (String) -> Void

    var body: some View {
        let w = work
        HStack(spacing: 10) {
            Button(action: onOpen) {
                HStack(spacing: 10) {
                    ZStack {
                        wa.accent.opacity(0.08)
                        if let cover = w.images?.first?.url, let u = URL(string: cover) {
                            AsyncImage(url: u) { $0.resizable().scaledToFill() } placeholder: { Color.clear }
                        } else {
                            Image(systemName: "photo.on.rectangle").font(.system(size: 18)).foregroundStyle(wa.accent.opacity(0.5))
                        }
                    }
                    .frame(width: 64, height: 64)
                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .overlay {
                        if w.verified == true { RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(qGreen.opacity(0.55), lineWidth: 1.5) }
                    }
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: w.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        if w.verified == true {
                            HStack(spacing: 6) {
                                VerifiedWorkBadge()
                                if let m = workMonth(w.verifiedOn) { Text(verbatim: m).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                            }
                        }
                        if let d = w.description, !d.isEmpty { Text(verbatim: d).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(2) }
                        if w.caseStudy?.hasText == true { Text(L("fl_work_case_study")).font(.dorr(11, .bold)).foregroundStyle(wa.accent) }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .accessibilityHint(L("fl_work_open"))
            if let id = w.uuid {
                Button { onReport(id) } label: {
                    Image(systemName: "flag").font(.system(size: 14)).foregroundStyle(wa.settingsMut).frame(width: 34, height: 34).contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("rights_report"))
            }
            Image(systemName: "chevron.forward").font(.system(size: 12, weight: .semibold)).foregroundStyle(wa.settingsMut.opacity(0.6))
        }
        .padding(.vertical, 4)
    }
}

/// One work opened: its images, the «Verified by Dorr» note with the month it was done, what it is, and the case study told in
/// sections — the problem, the solution, my role, the tools and the results (only the parts the freelancer wrote).
struct PortfolioWorkSheet: View {
    @Environment(\.wa) private var wa
    let work: FreelancePortfolioDto
    /// The freelancer whose profile this work was opened from (FS-704); nil on a team's page — a team's work is not counted.
    var freelancerUuid: String? = nil
    let onDismiss: () -> Void
    let onReport: (String) -> Void

    /// FS-704: one open of this work, counted for the freelancer's «Portfolio Analytics» (the server keeps one per client per day).
    /// Fire-and-forget: nothing shown, any failure ignored.
    private func recordOpened() async {
        guard let freelancer = freelancerUuid, let item = work.uuid else { return }
        _ = try? await FreelanceApi.recordWorkOpened(freelancer, item)
    }

    var body: some View {
        let w = work
        let title = (w.title ?? "").isEmpty ? L("fl_portfolio") : (w.title ?? "")
        FlSheet(icon: "photo.on.rectangle", title: title, text: "") {
            let images = (w.images ?? []).compactMap { $0.url }.compactMap { URL(string: $0) }
            if !images.isEmpty {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(Array(images.enumerated()), id: \.offset) { _, u in
                            ZStack {
                                wa.accent.opacity(0.08)
                                AsyncImage(url: u) { $0.resizable().scaledToFill() } placeholder: { Color.clear }
                            }
                            .frame(width: images.count == 1 ? 320 : 240, height: 170)
                            .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
                        }
                    }
                }
            }
            if w.verified == true {
                VStack(alignment: .leading, spacing: 5) {
                    HStack(spacing: 8) {
                        VerifiedWorkBadge(compact: false)
                        Text(verbatim: workMonth(w.verifiedOn).map { L("fl_work_verified_on", $0) } ?? L("fl_work_verified_line"))
                            .font(.dorr(12, .bold)).foregroundStyle(qGreen)
                    }
                    Text(L("fl_work_verified_note")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(12)
                .background(qGreen.opacity(wa.night ? 0.18 : 0.09), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            if let d = w.description, !d.isEmpty {
                CaseSection(icon: "doc.text.fill", title: L("fl_work_about"), text: d, tint: wa.accent)
            }
            if let cs = w.caseStudy, cs.hasText {
                Text(L("fl_work_case_study")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).padding(.top, 2)
                if let t = cs.problem, !t.isEmpty { CaseSection(icon: "exclamationmark.triangle.fill", title: L("fl_cs_problem"), text: t, tint: qAmber) }
                if let t = cs.solution, !t.isEmpty { CaseSection(icon: "lightbulb.fill", title: L("fl_cs_solution"), text: t, tint: wa.accent) }
                if let t = cs.role, !t.isEmpty { CaseSection(icon: "person.fill", title: L("fl_cs_role"), text: t, tint: Color(hex: 0x8B5CF6)) }
                if let t = cs.tools, !t.isEmpty { CaseSection(icon: "wrench.and.screwdriver.fill", title: L("fl_cs_tools"), text: t, tint: Color(hex: 0x0EA5E9)) }
                if let t = cs.results, !t.isEmpty { CaseSection(icon: "trophy.fill", title: L("fl_cs_results"), text: t, tint: qGreen) }
            }
            if let id = w.uuid {
                FlAction(text: L("rights_report"), icon: "flag.fill") { onReport(id) }
            }
        }
        // Once per work opened (not on a re-render): the sheet is built when it opens and `id:` is the work.
        .task(id: w.uuid) { await recordOpened() }
    }
}

/// A part of the story: a tinted icon, its name and the text.
private struct CaseSection: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let text: String
    let tint: Color

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: icon).font(.system(size: 14, weight: .semibold)).foregroundStyle(tint)
                .frame(width: 32, height: 32)
                .background(tint.opacity(0.12), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: title).font(.dorr(13.5, .heavy)).foregroundStyle(wa.settingsInk)
                Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(5)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }
}
