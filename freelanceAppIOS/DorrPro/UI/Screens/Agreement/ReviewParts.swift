// freelanceApp `ui/screens/ReviewParts.kt`
import SwiftUI

private let gold = Color(hex: 0xF59E0B)

private func criterion(_ c: String) -> String {
    switch c {
    case "clarity": return L("rv_c_clarity")
    case "responsiveness": return L("rv_c_responsiveness")
    case "payment": return L("rv_c_payment")
    case "conduct": return L("rv_c_conduct")
    case "quality": return L("rv_c_quality")
    case "timeliness": return L("rv_c_timeliness")
    case "communication": return L("rv_c_communication")
    default: return L("rv_c_professionalism")
    }
}

/// Kotlin `Math.round(stars).toInt()`.
private func roundStars(_ v: Double?) -> Int { Int((v ?? 0).rounded()) }

struct Stars: View {
    let value: Int
    var size: CGFloat = 26
    var onPick: ((Int) -> Void)?

    var body: some View {
        HStack(spacing: 2) {
            ForEach(1...5, id: \.self) { n in
                Image(systemName: n <= value ? "star.fill" : "star")
                    .font(.system(size: size * 0.8))
                    .foregroundStyle(gold)
                    .frame(width: size, height: size)
                    .contentShape(Rectangle())
                    .onTapGesture { onPick?(n) }
                    .allowsHitTesting(onPick != nil)
            }
        }
    }
}

/// Rating the client after the project (FL-701); the client's rating of me shows once both rated, or when the time is over.
struct ReviewClientCard: View {
    @Environment(\.wa) private var wa
    let review: ReviewDto
    let busy: Bool
    let onObject: (ObjectionRequest) -> Void
    let onSend: (ReviewRequest) -> Void
    @State private var objecting = false
    @State private var scores: [String: Int] = [:]
    @State private var comment = ""

    var body: some View {
        let criteria = review.criteria ?? []
        Card {
            FieldLabel(text: L("rv_title"))
            if review.canRate == true {
                Text(verbatim: L("rv_note", "\u{200E}" + agreementTake(review.rateUntil, 10))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                ForEach(criteria, id: \.self) { c in
                    HStack {
                        Text(verbatim: criterion(c)).font(.dorr(13)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                        Stars(value: scores[c] ?? 0) { scores[c] = $0 }
                    }
                }
                DorrTextField(value: agreementLimit($comment, 1000), placeholder: L("rv_comment"), singleLine: false, minLines: 2)
                SettingsPrimaryButton(text: L("rv_send"), enabled: criteria.allSatisfy { (scores[$0] ?? 0) > 0 }, loading: busy) {
                    onSend(ReviewRequest(scores: scores, comment: comment.agreementTrimmedOrNil))
                }
            }
            if let r = review.mine {
                Text(L("rv_mine")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                Stars(value: roundStars(r.stars), size: 18)
            }
            if let theirs = review.theirs {
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("rv_theirs")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    HStack(spacing: 0) {
                        Stars(value: roundStars(theirs.stars), size: 18)
                        Text(verbatim: "  \(theirs.stars ?? 0)").font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    }
                    if theirs.commentHidden == true {
                        Text(L("ob_hidden")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    } else if let c = theirs.comment {
                        Text(verbatim: c).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                    }
                    // The server's order: the criteria first, as asked, then any other.
                    ForEach(orderedScores(theirs.scores ?? [:], criteria), id: \.0) { c, v in
                        Text(verbatim: "\(criterion(c)): \(v)/5").font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    }
                    if let o = review.objection {
                        Text(L(objectionStatusKey(o.status))).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
                        if let note = o.decisionNote { Text(verbatim: note).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    }
                    if review.canObject == true {
                        Text(L("ob_action")).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                            .padding(.vertical, 6)
                            .contentShape(Rectangle())
                            .onTapGesture { objecting = true }
                        Text(verbatim: L("ob_until", "\u{200E}" + agreementTake(review.objectUntil, 10))).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    }
                }
                .padding(.top, 6)
            } else if review.theirsWaiting == true {
                Text(L("rv_waiting")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            } else if review.canRate != true && review.mine == nil {
                Text(L("rv_closed")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
        }
        .dorrDialog(isPresented: $objecting) {
            ObjectionDialog(reasons: review.objectionReasons ?? [], busy: busy,
                            onSend: { objecting = false; onObject($0) }, onDismiss: { objecting = false })
        }
    }

    private func orderedScores(_ scores: [String: Int], _ criteria: [String]) -> [(String, Int)] {
        scores.sorted { a, b in
            let ia = criteria.firstIndex(of: a.key) ?? Int.max, ib = criteria.firstIndex(of: b.key) ?? Int.max
            return ia == ib ? a.key < b.key : ia < ib
        }.map { ($0.key, $0.value) }
    }

    private func objectionStatusKey(_ status: String?) -> String {
        switch status {
        case "rejected": return "ob_rejected"
        case "comment_hidden": return "ob_comment_hidden"
        case "rating_removed": return "ob_rating_removed"
        default: return "ob_open"
        }
    }
}

private struct ObjectionDialog: View {
    @Environment(\.wa) private var wa
    let reasons: [String]
    let busy: Bool
    let onSend: (ObjectionRequest) -> Void
    let onDismiss: () -> Void
    @State private var code: String?
    @State private var note = ""

    var body: some View {
        AgreementAlertCard(
            title: L("ob_title"), confirmLabel: L("ob_send"), confirmColor: Color(hex: 0xDC2626),
            confirmEnabled: code != nil && note.agreementTrimmed.count >= 5 && !busy,
            onConfirm: { onSend(ObjectionRequest(reason: code ?? "", note: note.agreementTrimmed)) },
            dismissLabel: L("common_cancel"), onDismiss: onDismiss
        ) {
            Text(L("ob_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            ForEach(reasons, id: \.self) { r in AgreementPill(text: L(objectionReasonLabel(r)), on: code == r) { code = r } }
            DorrTextField(value: agreementLimit($note, 500), placeholder: L("ob_note"), singleLine: false, minLines: 2)
        }
    }
}

private func objectionReasonLabel(_ r: String) -> String {
    switch r {
    case "abusive": return "ob_r_abusive"
    case "false": return "ob_r_false"
    case "unfair": return "ob_r_unfair"
    default: return "ob_r_other"
    }
}

private func surveyReasonLabel(_ r: String) -> String {
    switch r {
    case "quality": return "sv_r_quality"
    case "late": return "sv_r_late"
    case "communication": return "sv_r_communication"
    case "price": return "sv_r_price"
    case "scope": return "sv_r_scope"
    case "payment": return "sv_r_payment"
    case "platform": return "sv_r_platform"
    default: return "sv_r_other"
    }
}

/// FL-702: a short survey once the project is over - how satisfied, and when not (3 stars or less) what went wrong. Shown only while it can
/// be answered, then as a thank-you. The other side never sees it. Read on open and again when the connection returns.
struct ProjectSurveyCard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let refreshKey: String
    @State private var survey: SurveyDto?
    @State private var score = 0
    @State private var picked: [String] = []
    @State private var comment = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        VStack(spacing: 0) {
            if let s = survey, s.canAnswer == true || s.mine != nil { card(s) }
        }
        .task(id: "\(projectUuid)|\(refreshKey)|\(network.reconnectTick)") {
            if let s = await AgreementsApi.survey(projectUuid).value { survey = s }
        }
    }

    private func card(_ s: SurveyDto) -> some View {
        let max = s.notSatisfiedMax ?? 3
        return Card {
            FieldLabel(text: L("sv_title"))
            if let mine = s.mine {
                HStack(spacing: 0) {
                    Stars(value: mine.score ?? 0, size: 18)
                    Text(verbatim: "  " + L("sv_thanks")).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                }
            } else {
                Text(verbatim: L("sv_hint", "\u{200E}" + agreementTake(s.answerUntil, 10))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                Stars(value: score, size: 30) { score = $0 }
                if (1...max).contains(score) {
                    Text(L("sv_why")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    ForEach(s.reasons ?? [], id: \.self) { r in
                        AgreementPill(text: L(surveyReasonLabel(r)), on: picked.contains(r)) {
                            if let i = picked.firstIndex(of: r) { picked.remove(at: i) } else { picked.append(r) }
                        }
                    }
                }
                if score > 0 { DorrTextField(value: agreementLimit($comment, 500), placeholder: L("sv_comment"), singleLine: false, minLines: 2) }
                if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(Color(hex: 0xDC2626)) }
                SettingsPrimaryButton(text: L("sv_send"), enabled: score > 0 && (score > max || !picked.isEmpty), loading: busy) {
                    busy = true
                    error = nil
                    Task {
                        let r = await AgreementsApi.answerSurvey(projectUuid, SurveyRequest(score: score, reasons: score <= max ? picked : [], comment: comment.agreementTrimmedOrNil))
                        if let data = r.value { survey = data } else { error = r.errorText(L("login_error_offline")) ?? L("login_error_generic") }
                        busy = false
                    }
                }
            }
        }
    }
}

/// My numbers (FL-703): the average once there are three ratings, finished projects, on time, and what clients said.
struct MyRatingCard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var data: MyReviewsDto?

    var body: some View {
        VStack(spacing: 0) {
            if let d = data, (d.reviewsCount ?? 0) != 0 || (d.completedProjects ?? 0) != 0 { card(d) }
        }
        .task(id: network.reconnectTick) { await load() }
        .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
    }

    private func load() async {
        if let d = await AgreementsApi.reviews().value { data = d }
    }

    private func card(_ d: MyReviewsDto) -> some View {
        WaCard(padding: 16) {
            Text(L("rv_my_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.ink)
            HStack(spacing: 0) {
                if let rating = d.rating {
                    Stars(value: roundStars(rating), size: 18)
                    Text(verbatim: "  \(rating)").font(.dorr(15, .bold)).foregroundStyle(wa.ink)
                } else {
                    Text(verbatim: L("rv_my_few", d.reviewsCount ?? 0)).font(.dorr(12)).foregroundStyle(wa.mut)
                }
            }
            .padding(.top, 6)
            Text(verbatim: [
                L("rv_my_completed", d.completedProjects ?? 0),
                d.onTimeRate.map { L("rv_my_on_time", $0) },
                d.completionRate.map { L("rv_my_completion", $0) },
            ].compactMap { $0 }.joined(separator: "  ·  "))
                .font(.dorr(12)).foregroundStyle(wa.mut).padding(.top, 4)
            ForEach(Array((d.latest ?? []).prefix(3).enumerated()), id: \.offset) { _, r in
                VStack(alignment: .leading, spacing: 0) {
                    Stars(value: roundStars(r.stars), size: 14)
                    if let c = r.comment { Text(verbatim: c).font(.dorr(13)).foregroundStyle(wa.ink) }
                    let meta = [r.kind == "consultation" ? L("cns_review_kind") : nil, r.project].compactMap { $0 }
                    if !meta.isEmpty { Text(verbatim: meta.joined(separator: " · ")).font(.dorr(11)).foregroundStyle(wa.mut) }
                }
                .padding(.top, 8)
            }
        }
        .padding(.top, 12)
    }
}
