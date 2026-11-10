import SwiftUI

// freelanceApp `ui/screens/workroom/WorkroomDecisions.kt`.

/// The decisions log: the read, the numbers (and how many wait for me) and what I may do.
@MainActor
final class DecisionsState: ObservableObject {
    let project: String
    @Published var filter = "all"
    @Published var summary = WorkDecisionSummaryDto()
    @Published var readOnly = false
    @Published var canCreate = false
    let feed = PagedRows<WorkDecisionDto>(perPage: WORK_PAGE, key: { $0.uuid ?? "" })

    init(project: String) {
        self.project = project
        feed.reset { [weak self] page in
            guard let self else { return .offline }
            return await self.fetch(page)
        }
    }

    private func fetch(_ page: Int) async -> ApiResult<[WorkDecisionDto]> {
        let f = filter
        let r = await WorkroomApi.decisions(
            project, status: DECISION_FILTERS.contains(f) ? f : nil, waiting: f == "waiting" ? 1 : nil, perPage: WORK_PAGE, page: page
        )
        if let data = r.value {
            summary = data.summary ?? WorkDecisionSummaryDto()
            readOnly = data.readOnly ?? false
            canCreate = data.can?.decisionCreate ?? false
        }
        return r.rows { $0.decisions }
    }
}

private let DECISION_FILTERS = ["proposed", "agreed", "declined", "superseded"]

/// What a new proposal starts from: nothing, or the decision it replaces.
private struct ProposeSeed {
    var supersedes: WorkDecisionDto?
}

/// A decision I am answering: agreeing to it, or declining it.
private struct DecisionAnswer {
    let decision: WorkDecisionDto
    let agree: Bool
}

/// The tab of the decisions: what was agreed between the two sides, in writing, with the answer of each.
struct DecisionsTab: View {
    @ObservedObject var state: DecisionsState
    let project: String
    let parts: [WorkPartDto]
    let onChanged: () -> Void

    @State private var proposing: ProposeSeed?
    @State private var editing: WorkDecisionDto?
    @State private var answering: DecisionAnswer?
    @State private var withdrawing: WorkDecisionDto?
    @State private var busy = false
    /// Kept here (not in the sheet) so the sheet stays open while a request runs.
    @State private var sheetBusy = false

    private var canAdd: Bool { state.canCreate && !state.readOnly }

    var body: some View {
        FeedList(
            feed: state.feed, keyOf: { $0.uuid ?? "" },
            empty: WorkEmpty(icon: "hammer.fill", title: L("wr_dec_empty_title"), text: L("wr_dec_empty_body")),
            fabLabel: canAdd ? L("wr_dec_propose") : nil, onFab: addAction,
            header: { header },
            row: { _, d in
                DecisionCard(
                    d: d, readOnly: state.readOnly,
                    onAgree: { answering = DecisionAnswer(decision: d, agree: true) },
                    onDecline: { answering = DecisionAnswer(decision: d, agree: false) },
                    onWithdraw: { withdrawing = d }, onEdit: { editing = d },
                    onSupersede: { proposing = ProposeSeed(supersedes: d) }
                )
            }
        )
        .cmSheet(isPresented: cmPresence($proposing), fullHeight: true, locked: sheetBusy) { proposeSheet }
        .cmSheet(isPresented: cmPresence($editing), fullHeight: true, locked: sheetBusy) { editSheet }
        .cmSheet(isPresented: cmPresence($answering), locked: sheetBusy) { answerSheet }
        .dorrDialog(isPresented: cmPresence($withdrawing)) { withdrawDialog }
    }

    private var addAction: (() -> Void)? {
        guard canAdd else { return nil }
        return { proposing = ProposeSeed() }
    }

    @ViewBuilder
    private var header: some View {
        let waiting = state.summary.waitingForMe ?? 0
        VStack(spacing: 12) {
            if state.readOnly { ReadOnlyBanner() }
            if waiting > 0 { waitingBanner(waiting) }
            FilterChips(options: filters, selected: state.filter, onSelect: select)
        }
    }

    private func waitingBanner(_ waiting: Int) -> some View {
        WaitingBanner(count: waiting) { select("waiting") }
    }

    private var filters: [FilterOption] {
        [
            FilterOption(key: "all", label: L("wr_f_all")),
            FilterOption(key: "waiting", label: L("wr_f_waiting"), dot: (state.summary.waitingForMe ?? 0) > 0),
            FilterOption(key: "proposed", label: L("wr_dec_proposed")),
            FilterOption(key: "agreed", label: L("wr_dec_agreed")),
            FilterOption(key: "declined", label: L("wr_dec_declined")),
            FilterOption(key: "superseded", label: L("wr_dec_superseded")),
        ]
    }

    private func select(_ key: String) {
        guard key != state.filter else { return }
        state.filter = key
        Task { await state.feed.reload() }
    }

    private func reload() {
        Task { await state.feed.reload() }
        onChanged()
    }

    @ViewBuilder
    private var proposeSheet: some View {
        if let seed = proposing {
            DecisionSheet(
                project: project, decision: nil, supersedes: seed.supersedes, parts: parts, busy: $sheetBusy,
                onSaved: { proposing = nil; reload() }
            )
        }
    }

    @ViewBuilder
    private var editSheet: some View {
        if let d = editing {
            DecisionSheet(project: project, decision: d, supersedes: nil, parts: parts, busy: $sheetBusy, onSaved: { editing = nil; reload() })
        }
    }

    @ViewBuilder
    private var answerSheet: some View {
        if let a = answering {
            AnswerSheet(project: project, d: a.decision, agree: a.agree, busy: $sheetBusy, onDone: { answering = nil; reload() })
        }
    }

    @ViewBuilder
    private var withdrawDialog: some View {
        if let d = withdrawing {
            ConfirmDialog(
                icon: "arrow.uturn.backward", title: L("wr_dec_withdraw"), message: L("wr_dec_withdraw_ask"), loading: busy,
                confirmLabel: L("wr_dec_withdraw"), onConfirm: { withdraw(d) }, onDismiss: { if !busy { withdrawing = nil } }
            )
        }
    }

    private func withdraw(_ d: WorkDecisionDto) {
        guard let uuid = d.uuid else { return }
        busy = true
        Task {
            let r = await WorkroomApi.withdrawDecision(project, uuid)
            busy = false
            withdrawing = nil
            if case .ok = r { reload() } else { AppToast.show(r.errorText(L("login_error_offline")) ?? L("login_error_generic")) }
        }
    }
}

/// «Decisions are waiting for your answer»: a tap filters to them.
private struct WaitingBanner: View {
    @Environment(\.wa) private var wa
    let count: Int
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(spacing: 10) {
                CountDot(count: count, tint: Tints.amber)
                Text(L("wr_dec_waiting_me")).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(14)
            .frame(maxWidth: .infinity)
            .background(Tints.amber.opacity(0.13), in: RoundedRectangle(cornerRadius: Corners.card, style: .continuous))
            .contentShape(RoundedRectangle(cornerRadius: Corners.card, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

/// One decision: its state, the words, both answers, the link to what it replaces, and what I may do (agree · decline · edit · withdraw · replace).
private struct DecisionCard: View {
    @Environment(\.wa) private var wa
    let d: WorkDecisionDto
    let readOnly: Bool
    let onAgree: () -> Void
    let onDecline: () -> Void
    let onWithdraw: () -> Void
    let onEdit: () -> Void
    let onSupersede: () -> Void

    @State private var expanded = false

    var body: some View {
        let status = d.status ?? "proposed"
        let tint = decisionTint(status)
        let waitingMe = status == "proposed" && d.can?.decisionAgree == true
        WorkroomCard(accent: waitingMe ? Tints.amber : nil, onClick: { expanded.toggle() }) {
            HStack(alignment: .top, spacing: 12) {
                Image(systemName: decisionIcon(status)).font(.system(size: 17, weight: .semibold)).foregroundStyle(tint)
                    .frame(width: 36, height: 36)
                    .background(tint.opacity(0.14), in: Circle())
                    .accessibilityLabel(decisionLabel(status))
                VStack(alignment: .leading, spacing: 4) {
                    Text(verbatim: d.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(expanded ? 6 : 2)
                    Text(verbatim: d.body ?? "").font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4).lineLimit(expanded ? nil : 3)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            pills(status: status, tint: tint)
            if let part = d.milestone { PartTag(part: part) }
            // Each side's answer: a decision stands when both agreed.
            HStack(spacing: 14) {
                AnswerMark(who: L("wr_side_me"), agreed: d.freelancerAgreed == true)
                AnswerMark(who: L("wr_side_client"), agreed: d.clientAgreed == true)
                Spacer(minLength: 0)
            }
            notes
            if !readOnly { actions }
        }
    }

    private func pills(status: String, tint: Color) -> some View {
        let side = d.proposedBySide ?? MY_SIDE
        return HStack(spacing: 6) {
            StatusPill(text: decisionLabel(status), tint: tint, icon: decisionIcon(status))
            StatusPill(text: L("wr_dec_by", sideName(side)), tint: side == MY_SIDE ? Tints.teal : Tints.violet)
            if let when = workWhen(d.answeredAt ?? d.createdAt) {
                Text(verbatim: when).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
            Spacer(minLength: 0)
        }
    }

    @ViewBuilder
    private var notes: some View {
        if let note = d.answerNote, !note.agreementTrimmed.isEmpty {
            Text(verbatim: L("wr_dec_note", note)).font(.dorr(12.5)).foregroundStyle(wa.settingsInk).lineSpacing(3)
        }
        if let old = d.supersedes {
            Text(verbatim: L("wr_dec_replaces", old.title ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
        }
        if let next = d.supersededBy {
            Text(verbatim: L("wr_dec_replaced_by", next.title ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
        }
    }

    @ViewBuilder
    private var actions: some View {
        let can = d.can
        if can?.decisionAgree == true || can?.decisionDecline == true || can?.decisionEdit == true || can?.decisionWithdraw == true || can?.decisionSupersede == true {
            HStack(spacing: 8) {
                if can?.decisionAgree == true { WorkAction(icon: "checkmark", label: L("wr_dec_agree"), tint: Tints.green, onClick: onAgree) }
                if can?.decisionDecline == true { WorkAction(icon: "xmark", label: L("wr_dec_decline"), tint: WorkRed, onClick: onDecline) }
                if can?.decisionEdit == true { WorkAction(icon: "pencil", label: L("wr_edit"), tint: wa.red, onClick: onEdit) }
                if can?.decisionWithdraw == true { WorkAction(icon: "arrow.uturn.backward", label: L("wr_dec_withdraw"), tint: wa.settingsMut, onClick: onWithdraw) }
                if can?.decisionSupersede == true { WorkAction(icon: "arrow.left.arrow.right", label: L("wr_dec_replace"), tint: wa.red, onClick: onSupersede) }
            }
        }
    }
}

private struct AnswerMark: View {
    @Environment(\.wa) private var wa
    let who: String
    let agreed: Bool

    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: agreed ? "checkmark.circle.fill" : "circle").font(.system(size: 13, weight: .semibold))
                .foregroundStyle(agreed ? Tints.green : wa.settingsMut.opacity(0.6)).frame(width: 15, height: 15)
            Text(verbatim: who).font(.dorr(12, agreed ? .bold : .regular)).foregroundStyle(agreed ? wa.settingsInk : wa.settingsMut)
        }
    }
}

/// A new proposal (or the replacement of an older decision), or editing my own proposal while it waits.
private struct DecisionSheet: View {
    let project: String
    let decision: WorkDecisionDto?
    let supersedes: WorkDecisionDto?
    let parts: [WorkPartDto]
    @Binding var busy: Bool
    let onSaved: () -> Void

    @Environment(\.wa) private var wa
    @State private var title: String
    @State private var text: String
    @State private var part: String?
    @State private var error: String?

    init(project: String, decision: WorkDecisionDto?, supersedes: WorkDecisionDto?, parts: [WorkPartDto], busy: Binding<Bool>, onSaved: @escaping () -> Void) {
        self.project = project
        self.decision = decision
        self.supersedes = supersedes
        self.parts = parts
        _busy = busy
        self.onSaved = onSaved
        _title = State(initialValue: decision?.title ?? supersedes?.title ?? "")
        _text = State(initialValue: decision?.body ?? supersedes?.body ?? "")
        _part = State(initialValue: decision?.milestone?.uuid ?? supersedes?.milestone?.uuid)
    }

    private var sheetTitle: String {
        if decision != nil { return L("wr_dec_edit") }
        return L(supersedes != nil ? "wr_dec_replace" : "wr_dec_propose")
    }

    var body: some View {
        WorkSheet(title: sheetTitle) {
            if let old = supersedes {
                Text(verbatim: L("wr_dec_replaces", old.title ?? "")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            }
            Text(L("wr_dec_hint")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            DorrTextField(value: agreementLimit($title, 160), label: L("wr_dec_title"))
            DorrTextField(value: agreementLimit($text, 2000), label: L("wr_dec_body"), singleLine: false, minLines: 4)
            if !parts.isEmpty {
                WorkLabel(text: L("wr_part"))
                PartPicker(parts: parts, selected: part) { part = $0 }
            }
            WorkError(text: error)
            SettingsPrimaryButton(text: L("wr_send"), enabled: !title.agreementTrimmed.isEmpty && !text.agreementTrimmed.isEmpty, loading: busy, action: save)
        }
    }

    private func save() {
        busy = true
        error = nil
        Task {
            let r: ApiResult<WorkDecisionAnswerDto>
            if let uuid = decision?.uuid {
                // An update sends everything; an empty text clears the part.
                let body: JsonBody = ["title": title.agreementTrimmed, "body": text.agreementTrimmed, "milestone": part ?? ""]
                r = await WorkroomApi.updateDecision(project, uuid, body)
            } else {
                var body: JsonBody = ["title": title.agreementTrimmed, "body": text.agreementTrimmed]
                if let part { body["milestone"] = part }
                if let old = supersedes?.uuid { body["supersedes"] = old }
                r = await WorkroomApi.proposeDecision(project, body)
            }
            busy = false
            if case .ok = r { onSaved() } else { error = r.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}

/// Agreeing to, or declining, the other side's proposal — with a short note when I want to say why.
private struct AnswerSheet: View {
    @Environment(\.wa) private var wa
    let project: String
    let d: WorkDecisionDto
    let agree: Bool
    @Binding var busy: Bool
    let onDone: () -> Void

    @State private var note = ""
    @State private var error: String?

    var body: some View {
        WorkSheet(title: L(agree ? "wr_dec_agree" : "wr_dec_decline")) {
            Text(verbatim: d.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
            Text(verbatim: d.body ?? "").font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            DorrTextField(
                value: agreementLimit($note, 300), label: L("wr_dec_note_label"),
                placeholder: L(agree ? "wr_dec_note_agree_hint" : "wr_dec_note_decline_hint"), singleLine: false, minLines: 2
            )
            WorkError(text: error)
            SettingsPrimaryButton(text: L(agree ? "wr_dec_agree" : "wr_dec_decline"), loading: busy, action: send)
        }
    }

    private func send() {
        guard let uuid = d.uuid else { return }
        busy = true
        error = nil
        Task {
            let answer = note.agreementTrimmed
            let body = WorkNoteRequest(note: answer.isEmpty ? nil : answer)
            let r: ApiResult<WorkDecisionAnswerDto>
            if agree {
                r = await WorkroomApi.agreeDecision(project, uuid, body)
            } else {
                r = await WorkroomApi.declineDecision(project, uuid, body)
            }
            busy = false
            if case .ok = r { onDone() } else { error = r.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}
