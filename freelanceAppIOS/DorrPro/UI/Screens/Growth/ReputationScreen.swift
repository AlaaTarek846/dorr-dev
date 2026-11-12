import SwiftUI

// freelanceApp `ui/screens/ReputationScreen.kt`.

private let askMin = 10
private let askMax = 1000

/// The figures whose projects I see (and may ask to correct).
private let correctable: Set<String> = ["delivery", "completion"]

private func repDay(_ iso: String?) -> String {
    guard let date = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.timeZone = .current
    f.dateFormat = "d MMM yyyy"
    return f.string(from: date)
}

private func repIndicatorTitle(_ key: String?) -> String {
    switch key {
    case "quality": return L("rep_ind_quality")
    case "delivery": return L("rep_ind_delivery")
    case "communication": return L("rep_ind_communication")
    case "completion": return L("rep_ind_completion")
    case "repeat_clients": return L("rep_ind_repeat_clients")
    default: return L("rep_ind_technical")
    }
}

private func repIndicatorIcon(_ key: String?) -> String {
    switch key {
    case "quality": return "star.fill"
    case "delivery": return "clock.fill"
    case "communication": return "bubble.left.and.bubble.right.fill"
    case "completion": return "checkmark.circle.fill"
    case "repeat_clients": return "arrow.triangle.2.circlepath"
    default: return "checkmark.seal.fill"
    }
}

private func repIndicatorTint(_ key: String?) -> Color {
    switch key {
    case "quality": return Tints.amber
    case "delivery": return Tints.blue
    case "communication": return Tints.teal
    case "completion": return Tints.green
    case "repeat_clients": return Tints.violet
    default: return Tints.rose
    }
}

/// excellent (85+) · good (70+) · fair (50+) · low · new — the server's own bands, for the overall score.
private func repLevelOf(_ value: Int?) -> String {
    guard let value else { return "new" }
    if value >= 85 { return "excellent" }
    if value >= 70 { return "good" }
    if value >= 50 { return "fair" }
    return "low"
}

private func repLevelLabel(_ level: String?) -> String {
    switch level {
    case "excellent": return L("rep_lv_excellent")
    case "good": return L("rep_lv_good")
    case "fair": return L("rep_lv_fair")
    case "low": return L("rep_lv_low")
    default: return L("rep_lv_new")
    }
}

private func repLevelColor(_ level: String?) -> Color {
    switch level {
    case "excellent": return QualityGreen
    case "good": return Tints.teal
    case "fair": return QualityAmber
    case "low": return QualityRed
    default: return QualitySlate
    }
}

private func repReasonLabel(_ reason: String?) -> String {
    switch reason {
    case "client_delay": return L("rep_reason_client_delay")
    case "client_ended": return L("rep_reason_client_ended")
    case "scope_changed": return L("rep_reason_scope_changed")
    case "wrong_data": return L("rep_reason_wrong_data")
    default: return L("rep_reason_other")
    }
}

/// Where a figure comes from, in plain words: «From 4 parts, 3 on time · a project taken out after a correction».
private func repSourceLine(_ ind: ReputationIndicatorDto) -> String {
    let s = ind.source
    let count = s?.count ?? 0
    var parts: [String] = []
    if ind.value == nil { parts.append(L("rep_no_data")) }
    switch s?.kind {
    case "ratings": if count > 0 { parts.append(L("rep_src_ratings", count)) }
    case "parts": if count > 0 { parts.append(L("rep_src_parts", count, s?.hits ?? 0)) }
    case "projects": if count > 0 { parts.append(L("rep_src_projects", count, s?.hits ?? 0)) }
    case "clients": if count > 0 { parts.append(L("rep_src_clients", count, s?.hits ?? 0)) }
    case "certifications":
        if count > 0 { parts.append(L("rep_src_certs", count)) }
        let best = (s?.best ?? []).filter { !($0.name ?? "").isEmpty }.prefix(2)
        if !best.isEmpty {
            let names = best.map { "\($0.name ?? "") (\(skillLevelLabel($0.level)))" }.joined(separator: AppLocale.isArabic ? "، " : ", ")
            parts.append(L("rep_src_best", names))
        }
        if let works = s?.verifiedWorks, works > 0 { parts.append(L("rep_src_verified", works)) }
    default: break
    }
    if let excluded = s?.excluded, excluded > 0 { parts.append(Lp("rep_src_excluded", excluded, excluded)) }
    return parts.joined(separator: " · ")
}

/// FS-604: my reputation — the overall score, the six figures clients see (each with its level and where it comes from), the projects behind
/// delivery and completion with «ask for a correction», and my requests with staff's answers. Read when the page opens, when the connection
/// returns and when a notice arrives (staff answered a request), never on a timer.
struct ReputationScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void

    @State private var data: ReputationDto?
    @State private var failure: String?
    @State private var notice: String?
    /// The figure whose projects are open (delivery / completion).
    @State private var openKey: String?
    // The correction being asked for (Android keeps these in the sheet; here they are the page's, so `locked:` can read `busy`).
    @State private var asking: ReputationProjectDto?
    @State private var reason: String?
    @State private var note = ""
    @State private var busy = false
    @State private var sheetError: String?

    private var offlineText: String { L("login_error_offline") }
    private var genericError: String { L("login_error_generic") }

    private func load() async {
        let r = await ReputationApi.show()
        if let value = r.value {
            data = value
            failure = nil
        } else if data == nil {
            failure = r.errorText(offlineText) ?? genericError
        }
    }

    private var open: ReputationIndicatorDto? {
        guard let openKey, correctable.contains(openKey) else { return nil }
        return data?.indicators?.first { $0.key == openKey }
    }

    var body: some View {
        let shown = open
        Page(title: shown.map { repIndicatorTitle($0.key) } ?? L("rep_title"), onBack: { if openKey != nil { openKey = nil } else { onBack() } }) {
            if let current = data {
                if let shown {
                    ProjectsPart(ind: shown, data: current, onAsk: startAsking)
                } else {
                    Overview(data: current, onOpen: { openKey = $0 })
                }
            } else if let failure {
                EmptyState(icon: "checkmark.shield.fill", title: L("rep_title"), body: failure).padding(.top, 70)
            } else {
                SkeletonDetail()
            }
        }
        .task(id: network.reconnectTick) { await load() }
        .onReceive(Realtime.shared.changed) { Task { await load() } }
        .messageToast(notice ?? (data != nil ? failure : nil))
        .formSheet(isPresented: Binding(get: { asking != nil }, set: { if !$0 { asking = nil } }), icon: "square.and.pencil",
                   title: L("rep_ask"), text: L("rep_ask_intro"), locked: busy) {
            sheet
        } footer: {
            sheetFooter
        }
    }

    private func startAsking(_ project: ReputationProjectDto) {
        reason = nil
        note = ""
        sheetError = nil
        notice = nil
        asking = project
    }

    /// Asking staff to correct one project's data: the reason as chips and a note of 10..1000 characters.
    @ViewBuilder
    private var sheet: some View {
        let indicator = openKey ?? "delivery"
        let length = note.trimmingCharacters(in: .whitespacesAndNewlines).count
        let options = (data?.reasons ?? []).isEmpty ? ["client_delay", "client_ended", "scope_changed", "wrong_data", "other"] : (data?.reasons ?? [])
        HStack(spacing: 10) {
            Image(systemName: repIndicatorIcon(indicator)).font(.system(size: 16, weight: .semibold)).foregroundStyle(repIndicatorTint(indicator))
                .frame(width: 22)
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: asking?.title ?? "").font(.dorr(13.5, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                Text(verbatim: repIndicatorTitle(indicator)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(12)
        .background(wa.settingsMut.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        FieldLabel(text: L("rep_ask_reason"))
        ChipRow(options: options.map { (value: $0, label: repReasonLabel($0)) }, selected: Set([reason].compactMap { $0 }), onToggle: { reason = $0 })
        DorrTextField(value: agreementLimit($note, askMax), label: L("rep_ask_note"), placeholder: L("rep_ask_note_ph"), icon: "text.alignleft",
                      singleLine: false, minLines: 4)
        HStack {
            Text(verbatim: L("skl_ch_note_min", askMin)).font(.dorr(11.5))
                .foregroundStyle((1..<askMin).contains(length) ? QualityAmber : wa.settingsMut)
                .frame(maxWidth: .infinity, alignment: .leading)
            Text(verbatim: "\(note.count)/\(askMax)").font(.dorr(11.5, .semibold)).foregroundStyle(wa.settingsMut)
                .environment(\.layoutDirection, .leftToRight)
        }
    }

    /// The send button, standing above the keyboard (the sheet's footer).
    @ViewBuilder
    private var sheetFooter: some View {
        let indicator = openKey ?? "delivery"
        let length = note.trimmingCharacters(in: .whitespacesAndNewlines).count
        if let sheetError { Text(verbatim: sheetError).font(.dorr(12)).foregroundStyle(QualityRed) }
        ActionButton(L("rep_ask_send"), "paperplane.fill", .primary, enabled: reason != nil && (askMin...askMax).contains(length), loading: busy) {
            send(indicator: indicator)
        }
    }

    private func send(indicator: String) {
        guard let project = asking, let reason else { return }
        busy = true
        sheetError = nil
        let body = ReputationCorrectionRequest(agreement: project.agreement ?? "", indicator: indicator, reason: reason,
                                               note: note.trimmingCharacters(in: .whitespacesAndNewlines))
        Task {
            let r = await ReputationApi.ask(body)
            busy = false
            if let saved = r.value {
                data?.corrections = saved.corrections ?? []
                asking = nil
                notice = L("rep_ask_sent")
                await load()
            } else {
                sheetError = r.errorText(offlineText) ?? genericError
            }
        }
    }
}

private struct Overview: View {
    @Environment(\.wa) private var wa
    let data: ReputationDto
    let onOpen: (String) -> Void

    var body: some View {
        let corrections = data.corrections ?? []
        OverallHero(overall: data.overall)
        SectionHeader(title: L("rep_indicators")).frame(maxWidth: 520)
        ForEach(Array((data.indicators ?? []).enumerated()), id: \.offset) { _, ind in
            let key = ind.key ?? ""
            let opener: (() -> Void)? = correctable.contains(key) && !(ind.projects ?? []).isEmpty ? { onOpen(key) } : nil
            IndicatorCard(ind: ind, onOpen: opener)
        }
        SectionHeader(title: L("rep_requests")).frame(maxWidth: 520)
        if corrections.isEmpty {
            Text(L("rep_requests_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: 520, alignment: .leading)
        } else {
            ForEach(Array(corrections.enumerated()), id: \.offset) { _, c in CorrectionRow(c: c) }
        }
    }
}

/// The overall score on the app colour: a white ring that fills to it (an hourglass while unknown), its level and who sees it.
private struct OverallHero: View {
    @Environment(\.wa) private var wa
    let overall: Int?

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 16) {
                if let overall {
                    CoachRing(percent: overall, size: 96, track: .white.opacity(0.25), bar: .white, ink: .white)
                } else {
                    Image(systemName: "hourglass").font(.system(size: 30, weight: .semibold)).foregroundStyle(.white)
                        .frame(width: 96, height: 96).background(.white.opacity(0.18), in: Circle())
                }
                VStack(alignment: .leading, spacing: 6) {
                    Text(L("rep_overall")).font(.dorr(12.5, .semibold)).foregroundStyle(.white.opacity(0.9))
                    if let overall {
                        Text(verbatim: repLevelLabel(repLevelOf(overall))).font(.dorr(22, .black)).foregroundStyle(.white)
                    } else {
                        Text(L("rep_overall_none")).font(.dorr(17, .heavy)).foregroundStyle(.white)
                        Text(L("rep_overall_none_body")).font(.dorr(12)).foregroundStyle(.white.opacity(0.9)).lineSpacing(2)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            HStack(spacing: 8) {
                Image(systemName: "eye.fill").font(.system(size: 13)).foregroundStyle(.white)
                Text(L("rep_clients_see")).font(.dorr(12)).foregroundStyle(.white).lineSpacing(2)
                    .fixedSize(horizontal: false, vertical: true)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .background(.white.opacity(0.14), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        }
        .padding(20)
        .frame(maxWidth: 520, alignment: .leading)
        .background(LinearGradient(colors: [wa.red, wa.red.opacity(0.72)], startPoint: .topLeading, endPoint: .bottomTrailing),
                    in: RoundedRectangle(cornerRadius: Corners.hero, style: .continuous))
        .frame(maxWidth: .infinity)
    }
}

/// One figure: its icon and name, the value (and a bar filling to it), its level, and where it comes from; `onOpen` opens its projects.
private struct IndicatorCard: View {
    @Environment(\.wa) private var wa
    let ind: ReputationIndicatorDto
    let onOpen: (() -> Void)?
    @State private var fill: CGFloat = 0

    var body: some View {
        let tint = repIndicatorTint(ind.key)
        let tone = repLevelColor(ind.level)
        let line = repSourceLine(ind)
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 12) {
                Image(systemName: repIndicatorIcon(ind.key)).font(.system(size: 16, weight: .semibold)).foregroundStyle(tint)
                    .frame(width: 38, height: 38)
                    .background(tint.opacity(wa.night ? 0.22 : 0.12), in: RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
                VStack(alignment: .leading, spacing: 3) {
                    Text(verbatim: repIndicatorTitle(ind.key)).font(.dorr(14.5, .heavy)).foregroundStyle(wa.settingsInk)
                    StatusPill(text: repLevelLabel(ind.level), tint: tone)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: ind.value.map { "\($0)%" } ?? "—").font(.dorr(22, .black))
                    .foregroundStyle(ind.value != nil ? tone : wa.settingsMut)
                    .environment(\.layoutDirection, .leftToRight)
            }
            if let value = ind.value {
                GeometryReader { geo in
                    ZStack(alignment: .leading) {
                        Capsule().fill(tone.opacity(0.14))
                        Capsule().fill(tone).frame(width: geo.size.width * fill)
                    }
                }
                .frame(height: 8)
                .onAppear { withAnimation(.easeOut(duration: 0.8)) { fill = CGFloat(min(max(value, 0), 100)) / 100 } }
                .onChange(of: value) { v in withAnimation(.easeOut(duration: 0.8)) { fill = CGFloat(min(max(v, 0), 100)) / 100 } }
            }
            if !line.isEmpty {
                Text(verbatim: line).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2).fixedSize(horizontal: false, vertical: true)
            }
            if onOpen != nil {
                HStack(spacing: 0) {
                    Text(L("rep_see_projects")).font(.dorr(12.5, .bold))
                    Image(systemName: "chevron.forward").font(.system(size: 11, weight: .bold)).frame(width: 18, height: 18)
                }
                .foregroundStyle(wa.red)
            }
        }
        .padding(14)
        .frame(maxWidth: 520, alignment: .leading)
        .appCard(Corners.card, elevation: 4)
        .contentShape(Rectangle())
        .onTapGesture { onOpen?() }
        .frame(maxWidth: .infinity)
    }
}

/// The projects behind delivery or completion: each with its outcome, and «ask for a correction» on the ones that count against me.
private struct ProjectsPart: View {
    @Environment(\.wa) private var wa
    let ind: ReputationIndicatorDto
    let data: ReputationDto
    let onAsk: (ReputationProjectDto) -> Void

    var body: some View {
        let open = (data.corrections ?? []).filter { $0.status == "open" }
        let openMax = data.openMax ?? 0
        let full = openMax > 0 && open.count >= openMax
        let projects = ind.projects ?? []
        IndicatorCard(ind: ind, onOpen: nil)
        Text(L(ind.key == "delivery" ? "rep_projects_intro_delivery" : "rep_projects_intro_completion"))
            .font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
            .frame(maxWidth: 520, alignment: .leading)
        if full {
            NoteBanner(icon: "hourglass", text: L("rep_ask_limit", open.count), tint: QualityAmber).frame(maxWidth: 520)
        }
        if projects.isEmpty {
            Text(L("rep_projects_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
        }
        ForEach(Array(projects.enumerated()), id: \.offset) { _, p in
            let asked = open.contains { $0.indicator == ind.key && $0.project?.agreement == p.agreement }
            ProjectRow(p: p, indicator: ind.key ?? "", asked: asked, canAsk: !full, onAsk: { onAsk(p) })
        }
    }
}

private struct ProjectRow: View {
    @Environment(\.wa) private var wa
    let p: ReputationProjectDto
    let indicator: String
    let asked: Bool
    let canAsk: Bool
    let onAsk: () -> Void

    var body: some View {
        let excluded = p.excluded ?? false
        let against = !excluded && (p.outcome == "late" || p.outcome == "ended_by_me")
        let parts = p.parts ?? 0
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                Image(systemName: "briefcase.fill").font(.system(size: 14)).foregroundStyle(wa.settingsMut).frame(width: 18)
                Text(verbatim: p.title ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            FlowLayout(spacing: 6) {
                switch p.outcome {
                case "on_time": StatusPill(text: L("rep_out_on_time"), tint: QualityGreen, icon: "checkmark.circle.fill")
                case "late":
                    StatusPill(text: parts > 0 && p.late != nil ? L("rep_out_late_n", p.late ?? 0, parts) : L("rep_out_late"), tint: QualityAmber, icon: "clock.fill")
                case "completed": StatusPill(text: L("rep_out_completed"), tint: QualityGreen, icon: "checkmark.circle.fill")
                case "ended_by_me": StatusPill(text: L("rep_out_ended_by_me"), tint: QualityRed, icon: "xmark")
                default: EmptyView()
                }
                if indicator == "delivery" && p.outcome == "on_time" && parts > 0 {
                    StatusPill(text: L("rep_parts_n", parts), tint: QualitySlate)
                }
                if excluded { StatusPill(text: L("rep_out_excluded"), tint: QualitySlate, icon: "minus.circle") }
            }
            if asked {
                StatusPill(text: L("rep_asked"), tint: QualityAmber, icon: "hourglass")
            } else if against {
                ActionButton(L("rep_ask"), "square.and.pencil", .soft, enabled: canAsk, action: onAsk)
            }
        }
        .padding(14)
        .frame(maxWidth: 520, alignment: .leading)
        .appCard(Corners.card, elevation: 4)
        .frame(maxWidth: .infinity)
    }
}

/// One request of mine: the project, the figure and the reason, where it stands, what I wrote and staff's reply.
private struct CorrectionRow: View {
    @Environment(\.wa) private var wa
    let c: ReputationCorrectionDto

    var body: some View {
        let tone: Color = c.status == "accepted" ? QualityGreen : (c.status == "rejected" ? QualityRed : QualityAmber)
        let label = c.status == "accepted" ? L("rep_st_accepted") : (c.status == "rejected" ? L("rep_st_rejected") : L("rep_st_open"))
        let icon = c.status == "accepted" ? "checkmark.circle.fill" : (c.status == "rejected" ? "xmark" : "hourglass")
        let reply = (c.decisionNote ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        let sent = repDay(c.sentAt)
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 10) {
                Text(verbatim: c.project?.title ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    .frame(maxWidth: .infinity, alignment: .leading)
                StatusPill(text: label, tint: tone, icon: icon)
            }
            Text(verbatim: repIndicatorTitle(c.indicator) + " · " + repReasonLabel(c.reason)).font(.dorr(12, .semibold)).foregroundStyle(wa.red)
            if let note = c.note, !note.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                Text(verbatim: note).font(.dorr(12.5)).foregroundStyle(wa.settingsInk).lineSpacing(3).lineLimit(4)
            }
            if !reply.isEmpty {
                NoteBanner(icon: "text.bubble.fill", text: reply, tint: tone, title: L("rep_reply"))
            } else if c.status == "accepted" {
                Text(L("rep_st_accepted_body")).font(.dorr(12, .semibold)).foregroundStyle(QualityGreen)
            }
            if !sent.isEmpty { Text(verbatim: L("rep_sent_on", sent)).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
        }
        .padding(14)
        .frame(maxWidth: 520, alignment: .leading)
        .appCard(Corners.card, elevation: 4)
        .frame(maxWidth: .infinity)
    }
}
