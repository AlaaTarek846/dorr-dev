import SwiftUI

// androidApp `freelance/workroom/WorkroomDecisions.kt` — the Workroom's decision log.

private let decisionFilters = ["all", "waiting", "agreed", "declined"]

/// The Workroom's decision log: a detail the two sides settle («the logo is blue») instead of losing it in the chat. One side proposes (and is
/// agreed by that act), the other agrees or declines with an optional note; an agreed decision is the record and is never edited — a change is
/// a new decision that replaces it. Read once when it opens, on a live event, on a pull and when the connection returns.
@MainActor
struct DecisionsTab: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let project: String
    let parts: [WorkPartDto]
    let freelancer: String
    @ObservedObject var live: WorkroomLive
    /// Whether this tab is the one on show: a hidden tab keeps what it holds but does not read again until it is back (Android drops the tab).
    let active: Bool
    let onSummary: (WorkDecisionSummaryDto) -> Void

    @State private var filter = "all"
    @State private var version = 0
    @State private var busy = false
    @State private var error: String?
    @State private var proposing = false
    // A new decision that replaces an agreed one, or the one of mine that is being changed.
    @State private var replacing: WorkDecisionDto?
    @State private var editing: WorkDecisionDto?
    @State private var answering: WorkDecisionDto?
    @State private var agreeing = true
    @State private var withdrawing: WorkDecisionDto?
    /// The first page's whole answer: the numbers for the chips, what I `can` do, whether the work is over.
    @State private var answer: WorkDecisionsDto?
    /// The filter the list was last started for (a new filter starts again from its skeleton; a live event only reads again).
    @State private var applied: String?
    @StateObject private var paged = PagedRows<WorkDecisionDto>(key: { $0.uuid })

    private func act(_ block: @escaping () async throws -> Void) {
        busy = true
        error = nil
        Task {
            do { try await block() } catch { self.error = flError(error) }
            busy = false
        }
    }

    private func filterLabel(_ f: String, _ summary: WorkDecisionSummaryDto) -> String {
        switch f {
        case "waiting": return L("wr_dec_filter_waiting") + ((summary.waitingForMe ?? 0) > 0 ? "  \(summary.waitingForMe ?? 0)" : "")
        case "agreed": return decisionLabel("agreed") + "  \(summary.agreed ?? 0)"
        case "declined": return decisionLabel("declined") + "  \(summary.declined ?? 0)"
        default: return L("wr_all")
        }
    }

    private func filterIcon(_ f: String) -> String {
        switch f {
        case "waiting": return "hourglass"
        case "agreed": return "checkmark.circle"
        case "declined": return "xmark"
        default: return "hammer"
        }
    }

    var body: some View {
        let summary = answer?.summary
        let canPropose = answer != nil && answer?.can?.decisionCreate == true && answer?.readOnly != true
        let filtered = filter != "all"
        ScrollView {
            LazyVStack(spacing: 10) {
                if let summary { DecisionsSummary(s: summary) }
                if let summary, (summary.waitingForMe ?? 0) > 0 {
                    FlNotice(text: L("wr_dec_waiting_notice", summary.waitingForMe ?? 0), icon: "hourglass", color: wrAmber)
                }
                if canPropose { FlAction(text: L("wr_dec_propose"), icon: "plus", tone: .primary) { proposing = true } }
                if let summary, (summary.total ?? 0) > 0 {
                    WrChipRow {
                        ForEach(decisionFilters, id: \.self) { f in
                            FlChip(text: filterLabel(f, summary), selected: filter == f, icon: filterIcon(f)) { filter = f }
                        }
                    }
                }
                if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: wrRed) }
                if answer?.readOnly == true { FlNotice(text: L("wr_read_only"), icon: "lock.fill", color: wrAmber) }
                if !paged.loaded && paged.error == nil {
                    WrSkeleton(count: 3, height: 120)
                } else if paged.rows.isEmpty, let failed = paged.error {
                    FlRetry(message: failed)
                } else if paged.rows.isEmpty {
                    WrEmpty(icon: "hammer", title: L(filtered ? "wr_dec_none_filtered" : "wr_dec_none"),
                            text: L(filtered ? "wr_dec_none_filtered_body" : "wr_dec_none_body"))
                }
                ForEach(Array(paged.rows.enumerated()), id: \.element.uuid) { i, d in
                    DecisionCard(d: d, freelancer: freelancer, busy: busy,
                                 onAgree: { agreeing = true; answering = d },
                                 onDecline: { agreeing = false; answering = d },
                                 onReplace: { replacing = d },
                                 onEdit: { editing = d },
                                 onWithdraw: { withdrawing = d })
                        .onAppear { paged.appeared(i) }
                }
                PagedSpinner(paged: paged)
                PagedError(paged: paged)
            }
            .padding(.horizontal, 16).padding(.top, 4).padding(.bottom, 28)
        }
        // Pulling down reads the list again (the client's own gesture, not a timer).
        .refreshable { await paged.reload() }
        // When it opens, on a live event (`freelance.workroom`), after a change of mine and when the connection returns — once each.
        .task(id: "\(filter)|\(network.reconnectTick)|\(live.decisions)|\(version)|\(active)") {
            if !active { return }
            if applied != filter {
                applied = filter
                let status: String? = filter == "agreed" || filter == "declined" ? filter : nil
                let waiting: Int? = filter == "waiting" ? 1 : nil
                paged.reset { page in
                    let reply = try await FreelanceWorkroomApi.decisions(project, status: status, waiting: waiting, perPage: workPage, page: page)
                    return workRows(reply, page: page, meta: { answer = $0 }, rows: { $0.decisions ?? [] })
                }
            }
            await paged.reload()
        }
        .onChange(of: answer) { now in if let summary = now?.summary { onSummary(summary) } }
        .dorrDialog(isPresented: Binding(get: { proposing || replacing != nil }, set: { if !$0 && !busy { proposing = false; replacing = nil } }), bottom: true) {
            DecisionSheet(initial: nil, replaces: replacing, parts: parts, busy: busy, error: error, onDismiss: { if !busy { proposing = false; replacing = nil } }) { fields in
                act {
                    _ = try await FreelanceWorkroomApi.proposeDecision(project, body: fields)
                    proposing = false
                    replacing = nil
                    filter = "all"
                    version += 1
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { editing != nil }, set: { if !$0 && !busy { editing = nil } }), bottom: true) {
            if let d = editing {
                DecisionSheet(initial: d, replaces: nil, parts: parts, busy: busy, error: error, onDismiss: { if !busy { editing = nil } }) { fields in
                    act {
                        _ = try await FreelanceWorkroomApi.updateDecision(project, decision: d.uuid, body: fields)
                        editing = nil
                        version += 1
                    }
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { answering != nil }, set: { if !$0 && !busy { answering = nil } }), bottom: true) {
            if let d = answering {
                let agree = agreeing
                AnswerSheet(d: d, agree: agree, busy: busy, error: error, onDismiss: { if !busy { answering = nil } }) { note in
                    act {
                        let request = WorkNoteRequest(note: note.isEmpty ? nil : note)
                        if agree {
                            _ = try await FreelanceWorkroomApi.agreeDecision(project, decision: d.uuid, request: request)
                        } else {
                            _ = try await FreelanceWorkroomApi.declineDecision(project, decision: d.uuid, request: request)
                        }
                        answering = nil
                        version += 1
                    }
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { withdrawing != nil }, set: { if !$0 && !busy { withdrawing = nil } })) {
            if let d = withdrawing {
                ConfirmDialog(icon: "arrow.uturn.backward", title: L("wr_dec_withdraw_title"), message: L("wr_dec_withdraw_body", d.title ?? ""),
                              loading: busy, confirmLabel: L("wr_dec_withdraw"), confirmTint: wrRed,
                              onConfirm: { act { _ = try await FreelanceWorkroomApi.withdrawDecision(project, decision: d.uuid); withdrawing = nil; version += 1 } },
                              onDismiss: { if !busy { withdrawing = nil } })
            }
        }
    }
}

private struct DecisionsSummary: View {
    let s: WorkDecisionSummaryDto

    var body: some View {
        HStack(spacing: 8) {
            FlStat(icon: "hourglass", label: L("wr_dec_for_me"), value: "\(s.waitingForMe ?? 0)")
            FlStat(icon: "checkmark.circle", label: decisionLabel("agreed"), value: "\(s.agreed ?? 0)")
            FlStat(icon: "xmark", label: decisionLabel("declined"), value: "\(s.declined ?? 0)")
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(FlCorners.card)
    }
}

private struct DecisionCard: View {
    @Environment(\.wa) private var wa
    let d: WorkDecisionDto
    let freelancer: String
    let busy: Bool
    let onAgree: () -> Void
    let onDecline: () -> Void
    let onReplace: () -> Void
    let onEdit: () -> Void
    let onWithdraw: () -> Void

    var body: some View {
        let status = d.status ?? "proposed"
        // A decision that waits for my answer stands out.
        let mineToAnswer = d.can?.decisionAgree == true || d.can?.decisionDecline == true
        VStack(alignment: .leading, spacing: 9) {
            HStack(alignment: .top, spacing: 8) {
                Text(verbatim: d.title ?? "").font(.dorr(15.5, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(4).frame(maxWidth: .infinity, alignment: .leading)
                FlDotPill(text: decisionLabel(status), color: decisionTone(status))
            }
            Text(verbatim: d.body ?? "").font(.dorr(13.5)).foregroundStyle(wa.settingsInk).lineSpacing(4).lineLimit(8)
            FlowLayout(spacing: 6) {
                if let part = d.milestone { WrTag(text: wrPartName(part), icon: "flag.fill", color: wa.accent) }
                WrTag(text: L("wr_dec_by", sideName(d.proposedBySide, freelancer)), icon: "person.fill")
                if mineToAnswer { WrTag(text: L("wr_dec_your_answer"), icon: "hourglass", color: wrAmber) }
            }
            // Who has agreed so far (a proposal is agreed by whoever made it).
            if status == "proposed" || status == "agreed" {
                HStack(spacing: 8) {
                    agreedTag(L("wr_you"), d.clientAgreed == true)
                    agreedTag(freelancer.isEmpty ? L("wr_freelancer") : freelancer, d.freelancerAgreed == true)
                }
            }
            if let note = d.answerNote, !wrTrim(note).isEmpty {
                Text(verbatim: "“\(note)”").font(.dorr(12.5)).italic().foregroundStyle(wa.settingsMut).lineSpacing(4)
                    .padding(10).frame(maxWidth: .infinity, alignment: .leading)
                    .background(wa.settingsMut.opacity(0.08), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            if let replaced = d.supersedes {
                Text(verbatim: L("wr_dec_replaces", replaced.title ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            if let by = d.supersededBy {
                Text(verbatim: L("wr_dec_replaced_by", by.title ?? "")).font(.dorr(12, .semibold)).foregroundStyle(wrAmber)
            }
            Text(verbatim: wrLocalDateTime(d.answeredAt ?? d.createdAt)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
            if d.can?.decisionAgree == true || d.can?.decisionDecline == true {
                HStack(spacing: 10) {
                    if d.can?.decisionAgree == true { FlAction(text: L("wr_dec_agree"), icon: "checkmark", tone: .primary, enabled: !busy, action: onAgree) }
                    if d.can?.decisionDecline == true { FlAction(text: L("wr_dec_decline"), icon: "xmark", tone: .danger, enabled: !busy, action: onDecline) }
                }
            }
            if d.can?.decisionSupersede == true || d.can?.decisionEdit == true || d.can?.decisionWithdraw == true {
                HStack(spacing: 14) {
                    if d.can?.decisionSupersede == true { WrLink(text: L("wr_dec_replace"), icon: "arrow.left.arrow.right", color: wa.accent, action: onReplace) }
                    if d.can?.decisionEdit == true { WrLink(text: L("wr_edit"), icon: "pencil", color: wa.accent, action: onEdit) }
                    if d.can?.decisionWithdraw == true { WrLink(text: L("wr_dec_withdraw"), icon: "trash", color: wrRed, action: onWithdraw) }
                }
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(FlCorners.card)
        .overlay {
            if mineToAnswer {
                RoundedRectangle(cornerRadius: FlCorners.card, style: .continuous).stroke(wrAmber.opacity(0.7), lineWidth: 1.5)
            }
        }
    }

    /// One side's part in a decision: a check when it agreed, an hourglass while it has not answered.
    private func agreedTag(_ name: String, _ agreed: Bool) -> some View {
        WrTag(text: name, icon: agreed ? "checkmark.seal.fill" : "hourglass", color: agreed ? wrGreen : wrGray)
    }
}

/// The form of a decision: a new one, one that replaces an agreed decision, or the one being changed (while it still waits).
private struct DecisionSheet: View {
    @Environment(\.wa) private var wa
    let initial: WorkDecisionDto?
    let replaces: WorkDecisionDto?
    let parts: [WorkPartDto]
    let busy: Bool
    let error: String?
    let onDismiss: () -> Void
    let onSave: ([String: JSONValue]) -> Void
    @State private var title: String
    @State private var text: String
    @State private var part: String?

    init(initial: WorkDecisionDto?, replaces: WorkDecisionDto?, parts: [WorkPartDto], busy: Bool, error: String?,
         onDismiss: @escaping () -> Void, onSave: @escaping ([String: JSONValue]) -> Void) {
        self.initial = initial
        self.replaces = replaces
        self.parts = parts
        self.busy = busy
        self.error = error
        self.onDismiss = onDismiss
        self.onSave = onSave
        _title = State(initialValue: initial?.title ?? replaces?.title ?? "")
        _text = State(initialValue: initial?.body ?? replaces?.body ?? "")
        _part = State(initialValue: initial?.milestone?.uuid ?? replaces?.milestone?.uuid)
    }

    var body: some View {
        FlSheet(icon: "hammer.fill", title: L(initial != nil ? "wr_dec_edit" : (replaces != nil ? "wr_dec_replace" : "wr_dec_new")), text: L("wr_dec_form_body"),
                footer: AnyView(VStack(spacing: 10) {
                    if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: wrRed) }
                    FlAction(text: L(initial != nil ? "common_save" : "wr_dec_send"), icon: "checkmark", tone: .primary,
                             enabled: wrTrim(title).count >= 3 && !wrTrim(text).isEmpty, loading: busy) {
                        var fields: [String: JSONValue] = [
                            "title": .string(wrTrim(title)),
                            "body": .string(wrTrim(text)),
                            "milestone": .string(part ?? ""),
                        ]
                        if let replaces { fields["supersedes"] = .string(replaces.uuid) }
                        onSave(fields)
                    }
                    FlAction(text: L("common_cancel"), enabled: !busy, action: onDismiss)
                })) {
            if let replaces { FlNotice(text: L("wr_dec_replacing", replaces.title ?? ""), icon: "arrow.left.arrow.right", color: wa.accent) }
            DorrTextArea(value: Binding(get: { title }, set: { title = String($0.prefix(160)) }), label: L("wr_dec_title"), icon: "hammer", singleLine: true)
            DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(2000)) }), label: L("wr_dec_text"), minHeight: 110)
            if !parts.isEmpty {
                VStack(alignment: .leading, spacing: 8) {
                    Text(verbatim: L("wr_part")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    PartPicker(parts: parts, selected: part) { part = $0 }
                }
            }
        }
    }
}

/// Agreeing or declining: the decision again, and a few words (optional) that stay with the answer.
private struct AnswerSheet: View {
    @Environment(\.wa) private var wa
    let d: WorkDecisionDto
    let agree: Bool
    let busy: Bool
    let error: String?
    let onDismiss: () -> Void
    let onSend: (String) -> Void
    @State private var note = ""

    var body: some View {
        FlSheet(icon: agree ? "checkmark" : "xmark", title: L(agree ? "wr_dec_agree_title" : "wr_dec_decline_title"), text: d.title ?? "",
                footer: AnyView(VStack(spacing: 10) {
                    if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: wrRed) }
                    FlAction(text: L(agree ? "wr_dec_agree" : "wr_dec_decline"), icon: agree ? "checkmark" : "xmark", tone: agree ? .primary : .danger, loading: busy) {
                        onSend(wrTrim(note))
                    }
                    FlAction(text: L("common_cancel"), enabled: !busy, action: onDismiss)
                })) {
            Text(verbatim: d.body ?? "").font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4).lineLimit(6)
            DorrTextArea(value: Binding(get: { note }, set: { note = String($0.prefix(300)) }), label: L("wr_dec_note"),
                         placeholder: L(agree ? "wr_dec_note_hint_agree" : "wr_dec_note_hint_decline"), minHeight: 90)
        }
    }
}
