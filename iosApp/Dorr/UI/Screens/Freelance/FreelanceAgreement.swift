import SwiftUI
import UIKit

// androidApp `freelance/FreelanceAgreement.kt`, `FreelanceChanges.kt`, `FreelanceDeliveries.kt`, `FreelanceDispute.kt`,
// `FreelanceRecords.kt`, `FreelanceReview.kt` — the agreement with the chosen freelancer and everything after it.

func agreementStatusLabel(_ status: String) -> String {
    switch status {
    case "pending_approval": return L("fl_ag_st_pending")
    case "active": return L("fl_ag_st_active")
    case "declined": return L("fl_ag_st_declined")
    case "cancelled": return L("fl_ag_st_cancelled")
    case "expired": return L("fl_ag_st_expired")
    case "completed": return L("fl_ag_st_completed")
    default: return L("fl_ag_st_draft")
    }
}

func agreementStatusColor(_ status: String) -> Color {
    switch status {
    case "active", "completed": return flGreen
    case "pending_approval": return flAmber
    case "declined", "cancelled", "expired": return flRed
    default: return Color(hex: 0x6B7280)
    }
}

private func agreementHint(_ status: String) -> String {
    switch status {
    case "pending_approval": return L("fl_ag_hint_pending")
    case "active": return L("fl_ag_hint_active")
    case "completed": return L("fl_ag_hint_completed")
    case "declined", "cancelled", "expired": return L("fl_ag_hint_ended")
    default: return L("fl_ag_hint_draft")
    }
}

func milestoneStatusLabel(_ status: String) -> String {
    switch status {
    case "funded": return L("fl_mst_funded")
    case "in_progress": return L("fl_mst_in_progress")
    case "delivered": return L("fl_mst_delivered")
    case "revision": return L("fl_mst_revision")
    case "approved": return L("fl_mst_approved")
    case "disputed": return L("fl_mst_disputed")
    case "cancelled": return L("fl_mst_cancelled")
    default: return L("fl_mst_unfunded")
    }
}

func milestoneStatusColor(_ status: String) -> Color {
    switch status {
    case "approved": return flGreen
    case "delivered": return Color(hex: 0x2563EB)
    case "revision", "disputed": return flAmber
    case "cancelled": return flRed
    case "funded", "in_progress": return Color(hex: 0x0EA5E9)
    default: return Color(hex: 0x6B7280)
    }
}

/// The agreement with the chosen freelancer (FL-401): the price and the days come from the offer and the terms from
/// the policy as it was; the client writes what the work covers, what it includes and what it doesn't, and sends it —
/// which is their approval. The freelancer approves it (the work starts) or declines it (the client chooses again).
/// Read once on open and on reconnect.
@MainActor
struct AgreementPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let onBack: () -> Void
    let onEnded: () -> Void
    let onDispute: (_ milestone: String, _ dispute: String?) -> Void
    @State private var agreement: FreelanceAgreementDto?
    @State private var scopeText = ""
    @State private var includes = ""
    @State private var excludes = ""
    // FL-605: how often I ask for a progress report (0 = none).
    @State private var reportDays = 0
    @State private var busy = false
    @State private var error: String?
    @State private var notice: String?
    @State private var confirmCancel = false
    // FL-403: the part the client is sending back, and why.
    @State private var revisionFor: String?
    @State private var revisionReason = ""
    // FL-501: the part being funded (the confirm sheet).
    @State private var fundFor: FreelanceMilestoneDto?
    // FL-506: the part being called off (the sheet says what comes back).
    @State private var cancelFor: FreelanceMilestoneDto?
    // FL-607: why (a picked reason and a few words).
    @State private var cancelCode: String?
    @State private var cancelNote = ""
    @State private var reloads = 0
    // The part on show (nil: the parts when one waits for me — funding, approving — else the overview).
    @State private var tab: Int?
    // FL-402: the parts, while the client splits them (title, amount as typed).
    @State private var splitting = false
    @State private var parts: [(String, String)] = []

    private func take(_ a: FreelanceAgreementDto?) {
        agreement = a
        if let a {
            scopeText = a.scope ?? ""
            includes = a.includes ?? ""
            excludes = a.excludes ?? ""
            reportDays = a.reports?.everyDays ?? 0
        }
    }

    private func act(_ block: @escaping () async throws -> FreelanceAgreementDto?, after: (() -> Void)? = nil) {
        busy = true
        error = nil
        notice = nil
        Task {
            do {
                take(try await block())
                after?()
            } catch {
                self.error = flError(error)
            }
            busy = false
        }
    }

    private var changed: Bool {
        guard let a = agreement else { return false }
        func t(_ s: String) -> String { s.trimmingCharacters(in: .whitespacesAndNewlines) }
        return t(scopeText) != (a.scope ?? "") || t(includes) != (a.includes ?? "") || t(excludes) != (a.excludes ?? "") || reportDays != (a.reports?.everyDays ?? 0)
    }

    private func saveBody() async throws -> ApiEnvelope<FreelanceAgreementDto> {
        func clean(_ s: String) -> String? { let t = s.trimmingCharacters(in: .whitespacesAndNewlines); return t.isEmpty ? nil : t }
        return try await FreelanceApi.updateAgreement(projectUuid, scope: scopeText.trimmingCharacters(in: .whitespacesAndNewlines), includes: clean(includes),
                                                      excludes: clean(excludes), reportEveryDays: reportDays)
    }

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: L("fl_agreement"), onBack: onBack)
            if let a = agreement {
                ScrollView { content(a).padding(.horizontal, 16) }.scrollDismissesKeyboard(.interactively)
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: "\(network.reconnectTick)|\(reloads)") {
            do { take(try await FreelanceApi.agreement(projectUuid).data) } catch { self.error = flError(error) }
        }
        .dorrDialog(isPresented: Binding(get: { cancelFor != nil }, set: { if !$0 && !busy { cancelFor = nil } }), bottom: true) {
            if let m = cancelFor { cancelSheet(m) }
        }
        .dorrDialog(isPresented: Binding(get: { fundFor != nil }, set: { if !$0 && !busy { fundFor = nil } }), bottom: true) {
            if let m = fundFor { fundSheet(m) }
        }
        .dorrDialog(isPresented: Binding(get: { revisionFor != nil }, set: { if !$0 && !busy { revisionFor = nil } }), bottom: true) {
            if let target = revisionFor { revisionSheet(target) }
        }
        .dorrDialog(isPresented: $confirmCancel) {
            ConfirmDialog(icon: "xmark", title: L("fl_ag_cancel"), message: L("fl_ag_cancel_confirm"), loading: busy,
                          onConfirm: { act({ try await FreelanceApi.cancelAgreement(projectUuid).data }, after: { confirmCancel = false; onEnded() }) },
                          onDismiss: { confirmCancel = false })
        }
    }

    @ViewBuilder
    private func content(_ a: FreelanceAgreementDto) -> some View {
        let milestones = a.milestones ?? []
        let recordsKey = "\(a.state):\(a.version ?? 1):" + milestones.map(\.state).joined(separator: ",")
        let activity = a.state == "active" || a.state == "completed"
        let sc = agreementStatusColor(a.state)
        let money = a.money ?? FreelanceAgreementMoneyDto()
        let waits = milestones.contains { m in m.can?.fund == true || m.can?.approve == true || m.can?.revision == true || m.can?.cancelAccept == true }
        let current = tab ?? (waits ? 1 : 0)
        VStack(alignment: .leading, spacing: 12) {
            // The head: a band of its status colour, who, where it stands, the figures and the parts as steps.
            VStack(alignment: .leading, spacing: 0) {
                sc.frame(height: 5)
                VStack(alignment: .leading, spacing: 10) {
                HStack(alignment: .top, spacing: 12) {
                    Image(systemName: "person.2.fill").font(.system(size: 19)).foregroundStyle(sc)
                        .frame(width: 48, height: 48)
                        .background(sc.opacity(0.12), in: RoundedRectangle(cornerRadius: 15, style: .continuous))
                    VStack(alignment: .leading, spacing: 5) {
                        Text(verbatim: a.freelancer?.name ?? "—").font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk)
                        FlPill(text: agreementStatusLabel(a.state), color: sc)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
                Text(verbatim: agreementHint(a.state)).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                if let exp = a.expiresAt { FlNotice(text: L("fl_ag_answer_by", "\u{200E}" + String(exp.prefix(10))), icon: "hourglass", color: flAmber) }
                HStack(spacing: 8) {
                    FlStat(icon: "banknote", label: L("fl_total"), value: flAmount(money.totalMinor, a.currency)).layoutPriority(1)
                    FlStat(icon: "clock", label: L("fl_duration"), value: L("fl_days", a.durationDays ?? 0))
                }
                if !milestones.isEmpty && activity { StageSteps(parts: milestones) { tab = 1 } }
                }
                .padding(16)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .settingsSurface(FlCorners.card)
            .clipShape(RoundedRectangle(cornerRadius: FlCorners.card, style: .continuous))
            FlTabs(tabs: [
                FlTab(icon: "doc.text", label: L("fl_tab_overview")),
                FlTab(icon: "flag.fill", label: L("fl_tab_parts"), badge: milestones.count),
                FlTab(icon: "clock.arrow.circlepath", label: L("fl_tab_activity")),
            ], selected: current) { tab = $0 }
            switch current {
            case 0: overview(a)
            case 1: partsTab(a)
            default: activityTab(a, recordsKey: recordsKey, activity: activity)
            }
            Spacer(minLength: 20)
        }
    }

    @ViewBuilder
    private func overview(_ a: FreelanceAgreementDto) -> some View {
        let money = a.money ?? FreelanceAgreementMoneyDto()
        let terms = a.terms ?? FreelanceAgreementTermsDto()
        let can = a.can ?? FreelanceAgreementCanDto()
        FlCard(icon: "banknote", title: L("fl_ag_money")) {
            FlField(label: L("fl_price"), value: flAmount(money.priceMinor, a.currency))
            if (money.clientFeeMinor ?? 0) > 0 { FlField(label: L("fl_client_fee"), value: flAmount(money.clientFeeMinor, a.currency)) }
            FlField(label: L("fl_total"), value: flAmount(money.totalMinor, a.currency))
            // FL-510: the split made the total differ from the one told before choosing (a minimum fee on each part).
            if let q = money.quotedTotalMinor, q != money.totalMinor {
                Text(verbatim: L("fl_quoted_total", flAmount(q, a.currency))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            if let d = terms.reviewDays { FlField(label: L("fl_ag_review_days"), value: L("fl_days", d)) }
            if let r = terms.freeRevisions { FlField(label: L("fl_ag_free_revisions"), value: "\(r)") }
        }
        // FL-L13: who owns the work and how far the rights have passed; the portfolio leave is the client's alone.
        if let r = a.rights {
            AgreementRightsCard(r: r, busy: busy) { allowed in
                act({
                    let updated = try await FreelanceRightsApi.setPortfolio(projectUuid, allowed: allowed)
                    notice = updated.message
                    return updated.data
                })
            }
        }
        if can.edit == true {
            // The terms are a form: the same look as the organizer form.
            FlIntro(icon: "square.and.pencil", title: L("fl_ag_terms"), text: L("fl_ag_form_intro"))
            VStack(alignment: .leading, spacing: 14) {
                DorrTextArea(value: Binding(get: { scopeText }, set: { scopeText = String($0.prefix(8000)) }), label: L("fl_ag_scope"), icon: "text.alignleft", minHeight: 110)
                DorrTextArea(value: Binding(get: { includes }, set: { includes = String($0.prefix(3000)) }), label: L("fl_ag_includes"), icon: "checkmark.circle.fill", minHeight: 70)
                DorrTextArea(value: Binding(get: { excludes }, set: { excludes = String($0.prefix(3000)) }), label: L("fl_ag_excludes"), icon: "nosign", minHeight: 70)
            }
            // FL-605: optional progress reports.
            FlHeading(icon: "calendar.badge.clock", text: L("fl_rp_ask"), hint: L("fl_rp_hint"))
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    FlChip(text: L("fl_rp_none"), selected: reportDays == 0, icon: "nosign") { reportDays = 0 }
                    ForEach(a.reports?.allowedDays ?? [3, 7, 14], id: \.self) { d in
                        FlChip(text: L("fl_rp_every", d), selected: reportDays == d, icon: "calendar.badge.clock") { reportDays = d }
                    }
                }
            }
            if a.state == "pending_approval" { FlNotice(text: L("fl_ag_edit_resets"), icon: "info.circle.fill", color: wa.accent) }
        } else {
            FlCard(icon: "doc.text", title: L("fl_ag_terms")) {
                FlField(label: L("fl_ag_scope"), value: a.scope)
                FlField(label: L("fl_ag_includes"), value: a.includes)
                FlField(label: L("fl_ag_excludes"), value: a.excludes)
            }
        }
        FlNotices(notice: notice, error: error)
        if can.edit == true && changed { FlAction(text: L("fl_ag_save"), icon: "square.and.arrow.down") { act({ try await saveBody().data }) } }
        if can.send == true || (can.edit == true && changed) {
            FlAction(text: L("fl_ag_send"), icon: "paperplane.fill", tone: .primary, enabled: !scopeText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty, loading: busy) {
                let wasChanged = changed
                act({
                    if wasChanged { _ = try await saveBody() }
                    let sent = try await FreelanceApi.sendAgreement(projectUuid)
                    notice = sent.message
                    return sent.data
                })
            }
        }
        if can.cancel == true { FlAction(text: L("fl_ag_cancel"), icon: "xmark.circle.fill", tone: .danger) { confirmCancel = true } }
    }

    @ViewBuilder
    private func partsTab(_ a: FreelanceAgreementDto) -> some View {
        let milestones = a.milestones ?? []
        if a.can?.split == true && !splitting {
            FlAction(text: L(milestones.count > 1 ? "fl_ms_edit" : "fl_ms_split"), icon: "arrow.triangle.branch") { startSplit(a) }
        }
        if !splitting {
            ForEach(milestones) { m in milestoneRow(a, m) }
            FlNotices(notice: notice, error: error)
        } else {
            splitEditor(a)
        }
    }

    @ViewBuilder
    private func activityTab(_ a: FreelanceAgreementDto, recordsKey: String, activity: Bool) -> some View {
        // FL-511 / FL-406: the receipts of this project and its record of approvals.
        ReceiptsCard(projectUuid: projectUuid, refreshKey: recordsKey)
        TrailCard(projectUuid: projectUuid, refreshKey: recordsKey)
        // FL-701: rating the freelancer once it is completed.
        if let rv = a.review {
            ReviewCard(review: rv, busy: busy, onObject: { reason, note in
                act({
                    _ = try await FreelanceApi.objectToReview(projectUuid, reason: reason, note: note)
                    return try await FreelanceApi.agreement(projectUuid).data
                })
            }) { scores, comment in
                act({
                    _ = try await FreelanceApi.review(projectUuid, scores: scores, comment: comment)
                    return try await FreelanceApi.agreement(projectUuid).data
                })
            }
        }
        // FL-702: how satisfied, once it is over.
        if a.state == "completed" || a.state == "cancelled" { SurveyCard(projectUuid: projectUuid, refreshKey: recordsKey) }
        // FL-605: the progress reports the freelancer sent.
        if activity { ProgressReportsCard(projectUuid: projectUuid, summary: a.reports ?? FreelanceReportSummaryDto(), refreshKey: recordsKey) }
        // FL-404 / FL-405: changes after the agreement started.
        if a.state == "active" {
            ChangesCard(changes: a.changes ?? [], milestones: a.milestones ?? [], currency: a.currency ?? "", canAsk: true, busy: busy,
                        onAsk: { body in
                            act({
                                _ = try await FreelanceApi.requestChange(projectUuid, body)
                                return try await FreelanceApi.agreement(projectUuid).data
                            })
                        },
                        onAnswer: { change, action in
                            act({
                                _ = try await FreelanceApi.answerChange(projectUuid, change, action: action)
                                return try await FreelanceApi.agreement(projectUuid).data
                            })
                        })
        }
        if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed) }
    }

    private func startSplit(_ a: FreelanceAgreementDto) {
        let milestones = a.milestones ?? []
        parts = milestones.map { ($0.title ?? "", flMoney($0.amountMinor)) }
        if parts.count < 2 && (a.milestoneRules?.maxCount ?? 5) > 1 {
            let price = a.priceMinor ?? 0
            let half = price / 2
            parts = [("", flMoney(half)), ("", flMoney(price - half))]
        }
        splitting = true
    }

    @ViewBuilder
    private func milestoneRow(_ a: FreelanceAgreementDto, _ m: FreelanceMilestoneDto) -> some View {
        let can = m.can ?? FreelanceMilestoneCanDto()
        let mc = milestoneStatusColor(m.state)
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 10) {
                Text(verbatim: "\(m.position ?? 1)").font(.dorr(13, .heavy)).foregroundStyle(.white).frame(width: 28, height: 28).background(mc, in: Circle())
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: m.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: flAmount(m.amountMinor, a.currency)).font(.dorr(13, .bold)).foregroundStyle(wa.accent)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if a.state == "active" { FlPill(text: milestoneStatusLabel(m.state), color: mc) }
            }
            if a.state == "active", (m.heldMinor ?? 0) > 0 { FlNotice(text: L("fl_held", flAmount(m.heldMinor, a.currency)), icon: "lock.fill", color: Color(hex: 0x0EA5E9)) }
            // FL-602 / FL-604: the versions delivered (opened in place) and the checklist for the part.
            if a.state == "active" || a.state == "completed" { DeliveryReview(projectUuid: projectUuid, m: m) }
            // FL-407: the free rounds of changes.
            if m.state == "delivered", let max = m.revisionsMax, max > 0 {
                Text(verbatim: (m.revisionsLeft ?? 0) > 0 ? L("fl_ms_rounds_left", m.revisionsLeft ?? 0, max) : L("fl_ms_rounds_none", m.revisionsLeft ?? 0, max))
                    .font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
            }
            // FL-506: a request to end a started part waits for an answer; what went back after it ended.
            if m.cancelRequestedBy == "freelancer" { FlNotice(text: L("fl_ms_cancel_asked_them"), icon: "hourglass", color: flAmber) }
            if m.cancelRequestedBy == "client" { FlNotice(text: L("fl_ms_cancel_asked_you"), icon: "hourglass", color: flAmber) }
            if m.state == "cancelled", (m.refundedMinor ?? 0) > 0 {
                FlNotice(text: L("fl_ms_refunded", flAmount(m.refundedMinor, a.currency)), icon: "checkmark.circle.fill", color: flGreen)
            }
            if let auto = m.autoApproveAt { FlNotice(text: L("fl_auto_approve_on", "\u{200E}" + String(auto.prefix(10))), icon: "clock", color: flAmber) }
            if can.fund == true { FlAction(text: L("fl_fund"), icon: "wallet.pass.fill", tone: .primary) { fundFor = m } }
            if can.approve == true {
                FlAction(text: L("fl_ms_approve"), icon: "checkmark.circle.fill", tone: .primary) { act({ try await FreelanceApi.milestoneAction(projectUuid, m.uuid, action: "approve").data }) }
            }
            if can.revision == true { FlAction(text: L("fl_ms_revision"), icon: "arrow.counterclockwise") { revisionFor = m.uuid; revisionReason = "" } }
            if can.cancelAccept == true {
                FlAction(text: L("fl_ms_cancel_accept"), icon: "checkmark.circle.fill") { act({ try await FreelanceApi.milestoneAction(projectUuid, m.uuid, action: "cancel-accept").data }) }
            }
            if can.cancelReject == true {
                FlAction(text: L("fl_ms_cancel_reject"), icon: "xmark") { act({ try await FreelanceApi.milestoneAction(projectUuid, m.uuid, action: "cancel-reject").data }) }
            }
            // FL-704: open a dispute, or see it.
            if let dsp = m.dispute { FlAction(text: L("fl_dispute_see"), icon: "hammer.fill", tone: .warn) { onDispute(m.uuid, dsp.uuid) } }
            if can.dispute == true && (m.dispute == nil || m.dispute?.status == "resolved") {
                FlAction(text: L("fl_dispute_open"), icon: "hammer.fill", tone: .warn) { onDispute(m.uuid, nil) }
            }
            if can.cancel == true {
                FlAction(text: L(m.state == "unfunded" ? "fl_ms_cancel" : "fl_ms_call_off"), icon: "xmark.circle.fill", tone: .danger) { cancelCode = nil; cancelNote = ""; cancelFor = m }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(14)
        .settingsSurface(20)
    }

    @ViewBuilder
    private func splitEditor(_ a: FreelanceAgreementDto) -> some View {
        let sum = parts.reduce(0) { $0 + (flParseMoney($1.1) ?? 0) }
        let left = (a.priceMinor ?? 0) - sum
        let rules = a.milestoneRules ?? FreelanceMilestoneRulesDto()
        FlIntro(icon: "arrow.triangle.branch", title: L("fl_ms_title"), text: L("fl_ms_split_intro"))
        ForEach(parts.indices, id: \.self) { i in
            HStack(spacing: 6) {
                DorrTextArea(value: Binding(get: { parts.indices.contains(i) ? parts[i].0 : "" }, set: { if parts.indices.contains(i) { parts[i].0 = String($0.prefix(120)) } }),
                             placeholder: L("fl_ms_part", i + 1), icon: "flag.fill", singleLine: true)
                    .layoutPriority(1.4)
                DorrTextArea(value: Binding(get: { parts.indices.contains(i) ? parts[i].1 : "" }, set: { if parts.indices.contains(i) { parts[i].1 = String($0.prefix(12)) } }),
                             placeholder: "0.00", icon: "banknote", singleLine: true)
                    .keyboardType(.decimalPad)
                    .frame(maxWidth: 130)
                if parts.count > 1 { RemoveButton { parts.remove(at: i) } }
            }
        }
        if parts.count < (rules.maxCount ?? 5) { FlAction(text: L("fl_ms_add"), icon: "plus") { parts.append(("", flMoney(Swift.max(left, 0)))) } }
        FlNotice(text: left == 0 ? L("fl_ms_balanced") : L("fl_ms_left", flAmount(left, a.currency)),
                 icon: left == 0 ? "checkmark.circle.fill" : "exclamationmark.triangle.fill", color: left == 0 ? flGreen : flAmber)
        if let minPart = rules.minAmountMinor {
            Text(verbatim: L("fl_ms_rules", rules.maxCount ?? 5, flAmount(minPart, a.currency))).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
        }
        FlAction(text: L("fl_ms_save"), icon: "square.and.arrow.down", tone: .primary, loading: busy) {
            let items = parts.enumerated().map { i, p -> (title: String, amountMinor: Int) in
                let t = p.0.trimmingCharacters(in: .whitespaces)
                return (t.isEmpty ? "\(i + 1)" : t, flParseMoney(p.1) ?? 0)
            }
            act({ try await FreelanceApi.setMilestones(projectUuid, items).data }, after: { splitting = false })
        }
        FlAction(text: L("fl_ms_cancel_edit")) { splitting = false }
    }

    private func cancelSheet(_ m: FreelanceMilestoneDto) -> some View {
        let note: String
        switch m.state {
        case "unfunded": note = L("fl_ms_cancel_note_unfunded")
        case "funded": note = L("fl_ms_cancel_note_funded")
        default: note = L("fl_ms_cancel_note_started")
        }
        return FlSheet(icon: "xmark.circle.fill", title: L("fl_ms_call_off_title", m.title ?? ""), text: note) {
            CancelReasonPicker(code: cancelCode, note: $cancelNote) { cancelCode = $0 }
            FlAction(text: L(m.state == "in_progress" || m.state == "revision" ? "fl_ms_cancel_ask" : "fl_ms_cancel_confirm"), icon: "xmark.circle.fill", tone: .danger,
                     enabled: flCancelReasonReady(cancelCode, cancelNote), loading: busy) {
                let text = cancelNote.trimmingCharacters(in: .whitespacesAndNewlines)
                act({ try await FreelanceApi.milestoneAction(projectUuid, m.uuid, action: "cancel", reason: text.isEmpty ? nil : text, reasonCode: cancelCode).data },
                    after: { cancelFor = nil })
            }
            FlAction(text: L("fl_not_now")) { cancelFor = nil }
        }
    }

    private func fundSheet(_ m: FreelanceMilestoneDto) -> some View {
        let cur = agreement?.currency
        let total = (m.amountMinor ?? 0) + (m.clientFeeMinor ?? 0)
        let available = agreement?.walletAvailableMinor ?? 0
        let enough = available >= total
        return FlSheet(icon: "wallet.pass.fill", title: L("fl_fund_title", m.title ?? ""), text: L(enough ? "fl_fund_note" : "fl_fund_short")) {
            VStack(alignment: .leading, spacing: 8) {
                FlField(label: L("fl_price"), value: flAmount(m.amountMinor, cur))
                if (m.clientFeeMinor ?? 0) > 0 { FlField(label: L("fl_client_fee"), value: flAmount(m.clientFeeMinor, cur)) }
                FlField(label: L("fl_total"), value: flAmount(total, cur))
                FlField(label: L("fl_wallet_available"), value: flAmount(available, cur))
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(14)
            .background(wa.accent.opacity(0.07), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            if enough {
                FlAction(text: L("fl_fund_confirm"), icon: "wallet.pass.fill", tone: .primary, loading: busy) {
                    act({ try await FreelanceApi.milestoneAction(projectUuid, m.uuid, action: "fund").data }, after: { fundFor = nil })
                }
            } else {
                FlAction(text: L("fl_topup"), icon: "creditcard.fill", tone: .primary) {
                    fundFor = nil
                    // Top up what is missing; coming back reads the wallet once more.
                    TopupLauncher.shared.open(total - available) { reloads += 1 }
                }
            }
            FlAction(text: L("fl_not_now")) { fundFor = nil }
        }
    }

    private func revisionSheet(_ target: String) -> some View {
        FlSheet(icon: "arrow.counterclockwise", title: L("fl_ms_revision"), text: L("fl_ms_revision_intro")) {
            DorrTextArea(value: Binding(get: { revisionReason }, set: { revisionReason = String($0.prefix(1000)) }), placeholder: L("fl_ms_revision_hint"), icon: "text.alignleft", minHeight: 90)
            FlAction(text: L("fl_ms_revision_send"), icon: "paperplane.fill", tone: .primary,
                     enabled: revisionReason.trimmingCharacters(in: .whitespacesAndNewlines).count >= 5, loading: busy) {
                act({ try await FreelanceApi.milestoneAction(projectUuid, target, action: "revision", reason: revisionReason.trimmingCharacters(in: .whitespacesAndNewlines)).data },
                    after: { revisionFor = nil })
            }
            FlAction(text: L("fl_not_now")) { revisionFor = nil }
        }
    }
}

// MARK: - Changes (FL-404 / FL-405)

/// What a change does, in one line.
private func changeText(_ c: FreelanceChangeDto, _ currency: String) -> String {
    let p = c.payload
    switch c.type {
    case "extend_days": return L("fl_ch_extend", p?.int("days") ?? 0) + (c.milestone.map { " · \($0.title ?? "")" } ?? "")
    case "milestone_price":
        return L("fl_ch_price", c.milestone?.title ?? "", "\u{200E}" + flMoney(p?.int("from") ?? 0), flAmount(p?.int("to") ?? 0, currency))
    case "add_milestone": return L("fl_ch_add", p?.str("title") ?? "", flAmount(p?.int("amount_minor") ?? 0, currency))
    default: return L("fl_ch_scope")
    }
}

private func changeStatus(_ status: String?) -> String {
    switch status {
    case "accepted": return L("fl_ch_s_accepted")
    case "rejected": return L("fl_ch_s_rejected")
    case "withdrawn": return L("fl_ch_s_withdrawn")
    case "expired": return L("fl_ch_s_expired")
    case "cancelled": return L("fl_ch_s_cancelled")
    default: return L("fl_ch_s_pending")
    }
}

/// Changes to an active agreement: what was asked and its answer, the buttons to answer or take one back, and a short
/// form to ask: more days, a new scope, a new price for a part not funded yet, or a new part for more work.
@MainActor
private struct ChangesCard: View {
    @Environment(\.wa) private var wa
    let changes: [FreelanceChangeDto]
    let milestones: [FreelanceMilestoneDto]
    let currency: String
    let canAsk: Bool
    let busy: Bool
    let onAsk: (FreelanceChangeRequest) -> Void
    let onAnswer: (String, String) -> Void
    @State private var asking = false
    @State private var type = "extend_days"
    @State private var part: String?
    @State private var days = ""
    @State private var amount = ""
    @State private var title = ""
    @State private var scope = ""
    @State private var includes = ""
    @State private var excludes = ""
    @State private var reason = ""

    private var ready: Bool {
        switch type {
        case "extend_days": return (Int(days) ?? 0) > 0
        case "milestone_price": return part != nil && flParseMoney(amount) != nil
        case "add_milestone": return !title.trimmingCharacters(in: .whitespaces).isEmpty && flParseMoney(amount) != nil
        default: return [scope, includes, excludes].contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
        }
    }

    var body: some View {
        FlCard(icon: "arrow.left.arrow.right", title: L("fl_ch_title")) {
            if changes.isEmpty { Text(L("fl_ch_none")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            ForEach(changes, id: \.uuid) { c in
                VStack(alignment: .leading, spacing: 6) {
                    Text(verbatim: changeText(c, currency)).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: L(c.mine == true ? "fl_ch_by_me" : "fl_ch_by_them") + "  ·  " + changeStatus(c.status)).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    if let r = c.reason { Text(verbatim: r).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    HStack(spacing: 16) {
                        if c.can?.accept == true { FlTextAction(text: L("fl_ch_accept"), fill: false) { onAnswer(c.uuid, "accept") } }
                        if c.can?.reject == true { FlTextAction(text: L("fl_ch_reject"), danger: true, fill: false) { onAnswer(c.uuid, "reject") } }
                        if c.can?.withdraw == true { FlTextAction(text: L("fl_ch_withdraw"), fill: false) { onAnswer(c.uuid, "withdraw") } }
                    }
                }
                .padding(12)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(wa.accent.opacity(0.07), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            if canAsk && !asking { FlTextAction(text: L("fl_ch_ask")) { asking = true } }
            if canAsk && asking { form }
        }
    }

    @ViewBuilder
    private var form: some View {
        FlowLayout(spacing: 6) {
            ForEach([("extend_days", "fl_ch_t_extend"), ("scope", "fl_ch_t_scope"), ("milestone_price", "fl_ch_t_price"), ("add_milestone", "fl_ch_t_add")], id: \.0) { t, label in
                FlChip(text: L(label), selected: type == t) { type = t; part = nil }
            }
        }
        let unfunded = milestones.filter { $0.state == "unfunded" }
        let open = milestones.filter { !["approved", "cancelled", "disputed"].contains($0.state) }
        switch type {
        case "extend_days":
            FlowLayout(spacing: 6) {
                FlChip(text: L("fl_ch_whole"), selected: part == nil) { part = nil }
                ForEach(open) { m in FlChip(text: m.title ?? "", selected: part == m.uuid) { part = m.uuid } }
            }
            DorrTextArea(value: Binding(get: { days }, set: { days = String($0.filter(\.isNumber).prefix(3)) }), label: L("fl_ch_days"), singleLine: true).keyboardType(.numberPad)
        case "milestone_price":
            if unfunded.isEmpty { Text(L("fl_ch_no_unfunded")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            FlowLayout(spacing: 6) {
                ForEach(unfunded) { m in FlChip(text: m.title ?? "", selected: part == m.uuid) { part = m.uuid } }
            }
            DorrTextArea(value: Binding(get: { amount }, set: { amount = String($0.prefix(12)) }), label: L("fl_ch_new_price", currency), singleLine: true).keyboardType(.decimalPad)
        case "add_milestone":
            DorrTextArea(value: Binding(get: { title }, set: { title = String($0.prefix(120)) }), label: L("fl_ch_part_title"), singleLine: true)
            DorrTextArea(value: Binding(get: { amount }, set: { amount = String($0.prefix(12)) }), label: L("fl_ch_new_price", currency), singleLine: true).keyboardType(.decimalPad)
        default:
            // Only what is filled in changes.
            Text(L("fl_ch_scope_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            DorrTextArea(value: Binding(get: { scope }, set: { scope = String($0.prefix(8000)) }), label: L("fl_ag_scope"), minHeight: 70)
            DorrTextArea(value: Binding(get: { includes }, set: { includes = String($0.prefix(3000)) }), label: L("fl_ag_includes"), minHeight: 70)
            DorrTextArea(value: Binding(get: { excludes }, set: { excludes = String($0.prefix(3000)) }), label: L("fl_ag_excludes"), minHeight: 70)
        }
        DorrTextArea(value: Binding(get: { reason }, set: { reason = String($0.prefix(1000)) }), label: L("fl_ch_reason"), minHeight: 70)
        HStack(spacing: 16) {
            FlTextAction(text: L("fl_ch_send"), fill: false) {
                guard ready && !busy else { return }
                func clean(_ s: String) -> String? { let t = s.trimmingCharacters(in: .whitespacesAndNewlines); return t.isEmpty ? nil : t }
                onAsk(FreelanceChangeRequest(type: type, milestone: part, days: Int(days), amountMinor: flParseMoney(amount), title: clean(title),
                                             scope: type == "scope" ? clean(scope) : nil, includes: type == "scope" ? clean(includes) : nil,
                                             excludes: type == "scope" ? clean(excludes) : nil, reason: clean(reason)))
                asking = false; days = ""; amount = ""; title = ""; scope = ""; includes = ""; excludes = ""; reason = ""
            }
            FlTextAction(text: L("fl_not_now"), fill: false) { asking = false }
        }
        if !ready { Text(L("fl_ch_fill")).font(.dorr(11)).foregroundStyle(flAmber) }
    }
}

// MARK: - Deliveries and the checklist (FL-602 / FL-604)

private let maxChecks = 10

/// What the freelancer delivered for a part and what I check before approving. The versions open in place — images
/// are shown, anything else is downloaded with my sign-in and handed to Quick Look; nothing runs. Ticking a line
/// never approves anything.
@MainActor
private struct DeliveryReview: View {
    @Environment(\.wa) private var wa
    let projectUuid: String
    let m: FreelanceMilestoneDto
    @State private var open: Bool?
    @State private var versions: [FreelanceDeliveryDto] = []
    @State private var checks: [FreelanceChecklistItemDto]?
    @State private var editing = false
    @State private var error: String?

    var body: some View {
        let isOpen = open ?? (m.state == "delivered")
        let list = checks ?? m.checklist ?? []
        let count = m.deliveriesCount ?? 0
        VStack(alignment: .leading, spacing: 4) {
            if !list.isEmpty {
                Text(L("fl_ck_title")).font(.dorr(11, .bold)).foregroundStyle(wa.settingsMut)
                ForEach(list, id: \.uuid) { c in
                    Button {
                        Task {
                            do {
                                checks = try await FreelanceApi.tickChecklist(projectUuid, m.uuid, item: c.uuid ?? "", state: c.checked == true ? "uncheck" : "check").data ?? []
                            } catch {
                                self.error = flError(error)
                            }
                        }
                    } label: {
                        Text(verbatim: (c.checked == true ? "☑ " : "☐ ") + (c.text ?? "")).font(.dorr(13))
                            .foregroundStyle(c.checked == true ? flGreen : wa.settingsInk)
                            .frame(maxWidth: .infinity, alignment: .leading).padding(.vertical, 3)
                    }
                    .buttonStyle(.plain)
                    .disabled(m.can?.checklistTick != true)
                }
                if m.can?.checklistTick == true { Text(L("fl_ck_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
            }
            if m.can?.checklistEdit == true { FlTextLink(text: L(list.isEmpty ? "fl_ck_set" : "fl_ck_edit")) { editing = true } }
            if count > 0 {
                FlTextLink(text: L(isOpen ? "fl_dl_hide" : "fl_dl_show", count)) { open = !isOpen }
                if isOpen {
                    ForEach(Array(versions.enumerated()), id: \.offset) { _, v in
                        VStack(alignment: .leading, spacing: 3) {
                            Text(verbatim: L("fl_dl_version", v.number ?? 1) + (v.deliveredAt.map { " · " + String($0.prefix(10)) } ?? ""))
                                .font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                            if let note = v.note, !note.isEmpty { Text(verbatim: note).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                            // FL-L13: the freelancer's word that the work is theirs, and the outside material they listed.
                            DeliveryRightsLines(rights: v.rights)
                            ForEach(Array((v.files ?? []).enumerated()), id: \.offset) { _, f in
                                if f.previewOnly == true && f.isImage != true {
                                    PreviewOnlyFile(f: f)
                                } else if f.isImage == true {
                                    DeliveredImage(f: f)
                                } else {
                                    Button {
                                        Task { if !(await flOpenFile(path: f.path ?? "", name: "\(f.id ?? 0)_\(f.name ?? "file")")) { error = L("fl_dl_open_failed") } }
                                    } label: {
                                        Text(verbatim: "📎 \(f.name ?? "")").font(.dorr(12)).foregroundStyle(wa.accent).padding(.vertical, 3)
                                    }
                                    .buttonStyle(.plain)
                                }
                            }
                            if let r = v.revisionReason { Text(verbatim: L("fl_dl_asked", r)).font(.dorr(12)).foregroundStyle(flAmber) }
                        }
                        .padding(.leading, 8).padding(.bottom, 6)
                    }
                }
            }
            if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(flRed) }
        }
        // Read when opened and again when a new version or a status change arrives with the agreement.
        .task(id: "\(isOpen)|\(count)|\(m.state)") {
            guard isOpen, count > 0 else { return }
            do { versions = try await FreelanceApi.deliveries(projectUuid, m.uuid).data ?? [] } catch { self.error = flError(error) }
        }
        .onChange(of: m.checklist) { _ in checks = nil }
        .dorrDialog(isPresented: $editing, bottom: true) {
            ChecklistEditor(initial: list.compactMap(\.text), onDismiss: { editing = false }) { texts in
                Task {
                    do { checks = try await FreelanceApi.setChecklist(projectUuid, m.uuid, items: texts).data ?? [] } catch { self.error = flError(error) }
                    editing = false
                }
            }
        }
    }
}

/// An image of a delivery, downloaded with my sign-in and shown in place (tap to open it in Quick Look).
@MainActor
private struct DeliveredImage: View {
    @Environment(\.wa) private var wa
    let f: FreelanceDeliveryFileDto
    @State private var image: UIImage?
    @State private var zoom = false

    var body: some View {
        Group {
            if let image {
                Image(uiImage: image).resizable().scaledToFit()
                    .frame(maxWidth: .infinity, maxHeight: 320)
                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                    // FL-L13: a preview stays in the app (no Quick Look, so no share or save); an approved file opens as before.
                    .onTapGesture { if f.previewOnly == true { zoom = true } else { Task { _ = await flOpenFile(path: f.path ?? "", name: "\(f.id ?? 0)_\(f.name ?? "file")") } } }
                    .fullScreenCover(isPresented: $zoom) { RightsImageViewer(image: image) { zoom = false } }
            } else {
                Text(verbatim: "🖼 \(f.name ?? "")").font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
        }
        .task(id: f.id) {
            guard let data = try? await FreelanceApi.file(f.path ?? "") else { return }
            // Decoded at a size that fits a phone card; a huge picture never fills the memory.
            image = Self.downsample(data, max: 1200)
        }
    }

    private static func downsample(_ data: Data, max: CGFloat) -> UIImage? {
        guard let source = CGImageSourceCreateWithData(data as CFData, nil) else { return nil }
        let options: [CFString: Any] = [kCGImageSourceCreateThumbnailFromImageAlways: true, kCGImageSourceCreateThumbnailWithTransform: true,
                                        kCGImageSourceThumbnailMaxPixelSize: max]
        return CGImageSourceCreateThumbnailAtIndex(source, 0, options as CFDictionary).map { UIImage(cgImage: $0) }
    }
}

/// Up to ten lines: what I will check before approving this part.
private struct ChecklistEditor: View {
    @Environment(\.wa) private var wa
    let initial: [String]
    let onDismiss: () -> Void
    let onSave: ([String]) -> Void
    @State private var lines: [String] = []

    var body: some View {
        FlSheet(icon: "checklist", title: L("fl_ck_set"), text: L("fl_ck_editor_hint")) {
            ForEach(lines.indices, id: \.self) { i in
                HStack(spacing: 6) {
                    DorrTextArea(value: Binding(get: { lines.indices.contains(i) ? lines[i] : "" }, set: { if lines.indices.contains(i) { lines[i] = String($0.prefix(200)) } }),
                                 placeholder: L("fl_ck_line"), icon: "checkmark.circle.fill", singleLine: true)
                    Button { lines.remove(at: i) } label: {
                        Image(systemName: "xmark").font(.system(size: 14, weight: .bold)).foregroundStyle(flRed).frame(width: 34, height: 34).contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
            }
            if lines.count < maxChecks { FlAction(text: L("fl_ck_add"), icon: "plus") { lines.append("") } }
            FlAction(text: L("fl_ck_save"), icon: "square.and.arrow.down", tone: .primary) {
                onSave(lines.map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }.filter { !$0.isEmpty })
            }
            FlAction(text: L("common_cancel"), action: onDismiss)
        }
        .onAppear { if lines.isEmpty { lines = initial.isEmpty ? [""] : initial } }
    }
}

// MARK: - A dispute (FL-704)

/// The reasons a dispute may give (as the server names them).
private let disputeReasons = ["not_as_agreed", "not_delivered", "late", "quality", "out_of_scope", "no_response", "rights", "other"]

private func disputeReasonLabel(_ reason: String) -> String {
    switch reason {
    case "not_as_agreed": return L("fl_dr_not_as_agreed")
    case "not_delivered": return L("fl_dr_not_delivered")
    case "late": return L("fl_dr_late")
    case "quality": return L("fl_dr_quality")
    case "out_of_scope": return L("fl_dr_out_of_scope")
    case "no_response": return L("fl_dr_no_response")
    case "rights": return L("fl_dr_rights")
    default: return L("fl_dr_other")
    }
}

private func disputeStatusLabel(_ status: String?) -> String {
    switch status {
    case "decided": return L("fl_ds_decided")
    case "appealed": return L("fl_ds_appealed")
    case "resolved": return L("fl_ds_resolved")
    default: return L("fl_ds_open")
    }
}

/// Opening one (`disputeUuid` nil: the reason and what happened) freezes the part and its money until DORR decides.
/// Then: the status, what each side added (and adding more while it is open), the decision with when it takes effect,
/// and one appeal while it waits. Read on open and when the connection comes back.
@MainActor
struct DisputePage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let milestoneUuid: String?
    let disputeUuid: String?
    let onBack: () -> Void
    let onOpened: (String) -> Void
    @State private var dispute: FreelanceDisputeDto?
    @State private var error: String?
    @State private var notice: String?
    @State private var busy = false
    @State private var reason: String?
    @State private var text = ""
    @State private var appealing = false

    private func run(_ block: @escaping () async throws -> Void) {
        busy = true
        error = nil
        notice = nil
        Task {
            do { try await block() } catch { self.error = flError(error) }
            busy = false
        }
    }

    private var trimmed: String { text.trimmingCharacters(in: .whitespacesAndNewlines) }

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: L("fl_dispute"), onBack: onBack)
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    if disputeUuid == nil {
                        opening
                    } else if let d = dispute {
                        details(d)
                    } else {
                        FlLoading(error: error).padding(24)
                    }
                    Spacer(minLength: 20)
                }
                .padding(.horizontal, 16)
            }
        }
        .task(id: network.reconnectTick) {
            guard let disputeUuid else { return }
            do { dispute = try await FreelanceApi.dispute(projectUuid, disputeUuid).data } catch { self.error = flError(error) }
        }
    }

    @ViewBuilder
    private var opening: some View {
        // Opening one: the same look as the organizer form.
        FlIntro(icon: "hammer.fill", title: L("fl_dispute_open_title"), text: L("fl_dispute_open_note"))
        FlHeading(icon: "flag.fill", text: L("fl_dispute_reason"))
        FlowLayout(spacing: 6) {
            ForEach(disputeReasons, id: \.self) { r in FlChip(text: disputeReasonLabel(r), selected: reason == r, icon: "flag.fill") { reason = r } }
        }
        DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(3000)) }), label: L("fl_dispute_details"), placeholder: L("fl_dispute_details_hint"),
                     icon: "text.alignleft", minHeight: 110)
        if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed) }
        FlAction(text: L("fl_dispute_open"), icon: "hammer.fill", tone: .warn, enabled: reason != nil && trimmed.count >= 20 && milestoneUuid != nil, loading: busy) {
            guard let milestoneUuid, let reason else { return }
            let details = trimmed
            run {
                if let opened = try await FreelanceApi.openDispute(projectUuid, milestoneUuid, reason: reason, details: details).data {
                    text = ""
                    onOpened(opened.uuid)
                }
            }
        }
    }

    @ViewBuilder
    private func details(_ d: FreelanceDisputeDto) -> some View {
        let sc = d.status == "resolved" ? flGreen : flAmber
        FlCard {
            HStack(spacing: 12) {
                Image(systemName: "hammer.fill").font(.system(size: 19)).foregroundStyle(sc)
                    .frame(width: 46, height: 46)
                    .background(sc.opacity(0.12), in: RoundedRectangle(cornerRadius: 15, style: .continuous))
                VStack(alignment: .leading, spacing: 3) {
                    Text(verbatim: disputeStatusLabel(d.status)).font(.dorr(16, .heavy)).foregroundStyle(sc)
                    Text(verbatim: L(d.mine == true ? "fl_dispute_opened_by_me" : "fl_dispute_opened_by_them") + "  ·  " + (d.reasonLabel ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            if let m = d.milestone { FlField(label: L("fl_ms_title"), value: "\(m.title ?? "")  ·  " + flAmount(m.amountMinor, d.currency)) }
            Text(verbatim: d.details ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(5)
            if d.status == "open" || d.status == nil, let by = d.respondBy {
                FlNotice(text: L("fl_dispute_respond_by", "\u{200E}" + String(by.prefix(10))), icon: "clock", color: flAmber)
            }
            FlNotice(text: L("fl_dispute_frozen"), icon: "lock.fill", color: Color(hex: 0x0EA5E9))
        }
        if let dec = d.decision {
            FlCard(icon: "scalemass.fill", title: L("fl_dispute_decision")) {
                Text(verbatim: dec.type == "refund" ? L("fl_dd_refund") : (dec.type == "release" ? L("fl_dd_release") : L("fl_dd_split", (dec.freelancerBp ?? 0) / 100)))
                    .font(.dorr(14, .heavy)).foregroundStyle(wa.accent)
                if let note = dec.note { Text(verbatim: note).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(5) }
                if d.status == "decided", let at = dec.executesAt {
                    FlNotice(text: L("fl_dispute_runs_at", "\u{200E}" + String(at.prefix(10))), icon: "clock", color: flAmber)
                }
                if dec.final == true { Text(L("fl_dispute_final")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            }
        }
        if d.appeal != nil { FlNotice(text: L("fl_dispute_appealed_note"), icon: "hourglass", color: flAmber) }
        FlCard(icon: "bubble.left.and.bubble.right.fill", title: L("fl_dispute_evidence")) {
            let evidence = d.evidence ?? []
            if evidence.isEmpty { Text(L("fl_dispute_no_evidence")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            ForEach(Array(evidence.enumerated()), id: \.offset) { _, e in
                VStack(alignment: .leading, spacing: 3) {
                    Text(verbatim: L(e.mine == true ? "fl_dispute_you" : "fl_dispute_them") + "  ·  \u{200E}" + String((e.at ?? "").prefix(10))).font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: e.body ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(5)
                    if let file = e.file { Text(verbatim: "📎 " + (file.name ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                }
                .padding(12)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(e.mine == true ? wa.accent.opacity(0.08) : Color(hex: 0x6B7280).opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            if d.can?.evidence == true {
                DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(3000)) }), placeholder: L("fl_dispute_add_hint"), icon: "text.alignleft", minHeight: 90)
                FlAction(text: L("fl_dispute_add"), icon: "paperplane.fill", tone: .primary) {
                    let body = trimmed
                    guard body.count >= 5 else { return }
                    run {
                        if let updated = try await FreelanceApi.disputeEvidence(projectUuid, d.uuid, body: body).data { dispute = updated }
                        text = ""
                    }
                }
            }
        }
        FlNotices(notice: notice, error: error)
        if d.can?.appeal == true {
            if !appealing {
                FlAction(text: L("fl_dispute_appeal"), icon: "hammer.fill", tone: .danger) { appealing = true; text = "" }
            } else {
                FlIntro(icon: "hammer.fill", title: L("fl_dispute_appeal"), text: L("fl_dispute_appeal_note"))
                DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(2000)) }), placeholder: L("fl_dispute_appeal_hint"), icon: "text.alignleft", minHeight: 90)
                FlAction(text: L("fl_dispute_appeal_send"), icon: "paperplane.fill", tone: .primary, enabled: trimmed.count >= 20, loading: busy) {
                    let reasonText = trimmed
                    run {
                        let r = try await FreelanceApi.appealDispute(projectUuid, d.uuid, reason: reasonText)
                        if let updated = r.data { dispute = updated }
                        notice = r.message
                        appealing = false
                        text = ""
                    }
                }
            }
        }
    }
}


// MARK: - Receipts and the record of approvals (FL-511 / FL-406)

/// The project's receipts: one per money movement that concerns the client, newest first. Read when shown, when the
/// agreement changes (`refreshKey`) and when the connection comes back; a receipt opens with its details and can be
/// shared as text.
@MainActor
/// The receipts (FL-511) of one project, or of all my work when `projectUuid` is nil («My receipts»); `showEmpty`: say so when
/// there is none (else nothing is drawn).
struct ReceiptsCard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String?
    let refreshKey: String
    var showEmpty = false
    @State private var list: [FreelanceReceiptDto] = []
    @State private var loaded = false
    @State private var open: FreelanceReceiptDto?

    var body: some View {
        Group {
            if list.isEmpty && showEmpty {
                if loaded {
                    WaEmpty(icon: "doc.text.fill", tone: .blue, title: L("flr_empty_title"), text: L("flr_empty_body"))
                } else {
                    FlSkeletonList(count: 3, height: 64)
                }
            } else if !list.isEmpty {
                FlCard(icon: "doc.text.fill", title: L("fl_rcp_title")) {
                    ForEach(list) { r in
                        Button {
                            Task { open = (try? await FreelanceApi.receipt(r.uuid).data) ?? r }
                        } label: {
                            HStack {
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(verbatim: r.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                                    Text(verbatim: "\u{200E}\(r.number ?? "") · \(r.milestone?.title ?? "") · \(String((r.issuedAt ?? "").prefix(10)))").font(.dorr(11)).foregroundStyle(wa.settingsMut)
                                }
                                Spacer()
                                Text(verbatim: flAmount((r.totalMinor ?? 0) > 0 ? r.totalMinor : r.refundedMinor, r.currency)).font(.dorr(14, .heavy)).foregroundStyle(wa.accent)
                            }
                            .padding(.vertical, 6)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
        .task(id: "\(refreshKey)|\(network.reconnectTick)") {
            if let all = try? await FreelanceApi.receipts(project: projectUuid).data { list = all }
            loaded = true
        }
        .dorrDialog(isPresented: Binding(get: { open != nil }, set: { if !$0 { open = nil } }), bottom: true) {
            if let r = open {
                FlBottomPanel(onDismiss: { open = nil }) {
                    Text(verbatim: r.title ?? "").font(.dorr(18, .heavy)).foregroundStyle(wa.accent)
                    FlField(label: L("fl_rcp_number"), value: "\u{200E}" + (r.number ?? ""))
                    FlField(label: L("fl_rcp_date"), value: "\u{200E}" + String((r.issuedAt ?? "").prefix(16)).replacingOccurrences(of: "T", with: " "))
                    FlField(label: L("fl_rcp_part"), value: r.milestone?.title)
                    if (r.amountMinor ?? 0) > 0 { FlField(label: L("fl_price"), value: flAmount(r.amountMinor, r.currency)) }
                    if (r.feeMinor ?? 0) > 0 { FlField(label: L("fl_client_fee"), value: flAmount(r.feeMinor, r.currency)) }
                    if (r.totalMinor ?? 0) > 0 { FlField(label: L("fl_total"), value: flAmount(r.totalMinor, r.currency)) }
                    if (r.refundedMinor ?? 0) > 0 { FlField(label: L("fl_rcp_refunded"), value: flAmount(r.refundedMinor, r.currency)) }
                    if let reason = r.reasonText { FlField(label: L("fl_rcp_reason"), value: reason) }
                    SettingsPrimaryButton(text: L("fl_rcp_share")) { shareText(r.text ?? r.number ?? "") }
                }
            }
        }
    }
}

/// Every step both sides agreed to, oldest first; folded until opened.
@MainActor
private struct TrailCard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let refreshKey: String
    @State private var list: [FreelanceTrailDto] = []
    @State private var shown = false

    var body: some View {
        Group {
            if !list.isEmpty {
                FlCard {
                    Button { shown.toggle() } label: {
                        HStack(spacing: 8) {
                            FlHeading(icon: "clock.arrow.circlepath", text: L("fl_trail_title", list.count))
                            Text(L(shown ? "fl_trail_hide" : "fl_trail_show")).font(.dorr(13, .bold)).foregroundStyle(wa.accent)
                            Spacer()
                        }
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    if shown {
                        ForEach(Array(list.enumerated()), id: \.offset) { _, s in
                            VStack(alignment: .leading, spacing: 0) {
                                Text(verbatim: s.label ?? "").font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                                Text(verbatim: [actor(s), "\u{200E}" + String((s.at ?? "").prefix(16)).replacingOccurrences(of: "T", with: " ")].joined(separator: " · "))
                                    .font(.dorr(11)).foregroundStyle(wa.settingsMut)
                            }
                            .padding(.vertical, 4)
                        }
                    }
                }
            }
        }
        .task(id: "\(refreshKey)|\(network.reconnectTick)") {
            if let all = try? await FreelanceApi.trail(projectUuid).data { list = all }
        }
    }

    private func actor(_ s: FreelanceTrailDto) -> String {
        if s.mine == true { return L("fl_trail_by_you") }
        switch s.actor {
        case "freelancer": return L("fl_trail_by_freelancer")
        case "admin": return L("fl_trail_by_admin")
        case "system": return L("fl_trail_by_system")
        default: return L("fl_trail_by_client")
        }
    }
}

// MARK: - Rating (FL-701)

private let gold = Color(hex: 0xF59E0B)

private func criterionLabel(_ c: String) -> String {
    switch c {
    case "quality": return L("fl_rc_quality")
    case "timeliness": return L("fl_rc_timeliness")
    case "communication": return L("fl_rc_communication")
    case "professionalism": return L("fl_rc_professionalism")
    case "clarity": return L("fl_rc_clarity")
    case "responsiveness": return L("fl_rc_responsiveness")
    case "payment": return L("fl_rc_payment")
    default: return L("fl_rc_conduct")
    }
}

struct FlStars: View {
    let value: Int
    var size: CGFloat = 26
    var onPick: ((Int) -> Void)?

    var body: some View {
        HStack(spacing: 2) {
            ForEach(1...5, id: \.self) { n in
                Image(systemName: n <= value ? "star.fill" : "star").font(.system(size: size * 0.8)).foregroundStyle(gold)
                    .frame(width: size, height: size)
                    .contentShape(Rectangle())
                    .onTapGesture { onPick?(n) }
            }
        }
    }
}

/// One to five stars on each criterion and a comment. Theirs about me shows once both rated, or when the rating
/// days are over; I may object once to theirs (FL-706).
@MainActor
private struct ReviewCard: View {
    @Environment(\.wa) private var wa
    let review: FreelanceReviewDto
    let busy: Bool
    let onObject: (_ reason: String, _ note: String) -> Void
    let onSend: ([String: Int], String?) -> Void
    @State private var scores: [String: Int] = [:]
    @State private var comment = ""
    @State private var objecting = false

    var body: some View {
        let criteria = review.criteria ?? []
        FlCard(icon: "star.fill", title: L("fl_rv_title")) {
            if review.canRate == true {
                Text(verbatim: L("fl_rv_note", "\u{200E}" + String((review.rateUntil ?? "").prefix(10)))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                ForEach(criteria, id: \.self) { c in
                    HStack {
                        Text(verbatim: criterionLabel(c)).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                        Spacer()
                        FlStars(value: scores[c] ?? 0) { scores[c] = $0 }
                    }
                }
                DorrTextArea(value: Binding(get: { comment }, set: { comment = String($0.prefix(1000)) }), label: L("fl_rv_comment"), minHeight: 70)
                SettingsPrimaryButton(text: L("fl_rv_send"), enabled: criteria.allSatisfy { (scores[$0] ?? 0) > 0 }, loading: busy) {
                    let t = comment.trimmingCharacters(in: .whitespacesAndNewlines)
                    onSend(scores, t.isEmpty ? nil : t)
                }
            }
            if let r = review.mine {
                Text(L("fl_rv_mine")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                rating(r)
                if let c = r.comment { Text(verbatim: c).font(.dorr(13)).foregroundStyle(wa.settingsInk) }
            }
            if let theirs = review.theirs {
                VStack(alignment: .leading, spacing: 2) {
                    Text(L("fl_rv_theirs")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    rating(theirs)
                    if theirs.commentHidden == true {
                        Text(L("fl_ob_hidden")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    } else if let c = theirs.comment {
                        Text(verbatim: c).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                    }
                    if let o = review.objection {
                        Text(verbatim: objectionStatus(o.status)).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
                        if let note = o.decisionNote { Text(verbatim: note).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    }
                    if review.canObject == true {
                        FlTextLink(text: L("fl_ob_action")) { objecting = true }
                        Text(verbatim: L("fl_ob_until", "\u{200E}" + String((review.objectUntil ?? "").prefix(10)))).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    }
                }
                .padding(.top, 6)
            } else if review.theirsWaiting == true {
                Text(L("fl_rv_waiting")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            } else if review.canRate != true && review.mine == nil {
                Text(L("fl_rv_closed")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
        }
        .dorrDialog(isPresented: Binding(get: { objecting }, set: { if !$0, !busy { objecting = false } }), bottom: true) {
            ObjectionSheet(reasons: review.objectionReasons ?? [], busy: busy, onSend: { r, n in objecting = false; onObject(r, n) }, onDismiss: { objecting = false })
        }
    }

    private func rating(_ r: FreelanceRatingDto) -> some View {
        HStack(spacing: 0) {
            FlStars(value: Int((r.stars ?? 0).rounded()), size: 18)
            Text(verbatim: "  \(r.stars ?? 0)").font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
        }
    }

    private func objectionStatus(_ status: String?) -> String {
        switch status {
        case "rejected": return L("fl_ob_rejected")
        case "comment_hidden": return L("fl_ob_comment_hidden")
        case "rating_removed": return L("fl_ob_rating_removed")
        default: return L("fl_ob_open")
        }
    }
}

private struct ObjectionSheet: View {
    let reasons: [String]
    let busy: Bool
    let onSend: (String, String) -> Void
    let onDismiss: () -> Void
    @State private var code: String?
    @State private var note = ""

    var body: some View {
        FlSheet(icon: "hammer.fill", title: L("fl_ob_title"), text: L("fl_ob_hint")) {
            ForEach(reasons, id: \.self) { r in FlChip(text: objectionReasonLabel(r), selected: code == r, icon: "flag.fill") { code = r } }
            DorrTextArea(value: Binding(get: { note }, set: { note = String($0.prefix(500)) }), placeholder: L("fl_ob_note"), icon: "text.alignleft", minHeight: 70)
            FlAction(text: L("fl_ob_send"), icon: "paperplane.fill", tone: .danger,
                     enabled: code != nil && note.trimmingCharacters(in: .whitespacesAndNewlines).count >= 5, loading: busy) {
                onSend(code ?? "", note.trimmingCharacters(in: .whitespacesAndNewlines))
            }
            FlAction(text: L("fl_not_now"), action: onDismiss)
        }
    }

    private func objectionReasonLabel(_ r: String) -> String {
        switch r {
        case "abusive": return L("fl_ob_r_abusive")
        case "false": return L("fl_ob_r_false")
        case "unfair": return L("fl_ob_r_unfair")
        default: return L("fl_ob_r_other")
        }
    }
}

private func surveyReasonLabel(_ r: String) -> String {
    switch r {
    case "quality": return L("fl_sv_r_quality")
    case "late": return L("fl_sv_r_late")
    case "communication": return L("fl_sv_r_communication")
    case "price": return L("fl_sv_r_price")
    case "scope": return L("fl_sv_r_scope")
    case "payment": return L("fl_sv_r_payment")
    case "platform": return L("fl_sv_r_platform")
    default: return L("fl_sv_r_other")
    }
}

/// FL-702: a short survey once the project is over — how satisfied, and when not (3 stars or less) what went wrong. Shown only
/// while it can be answered, and afterwards as a thank-you. The other side never sees it. Read on open and again when the
/// connection returns.
@MainActor
private struct SurveyCard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let refreshKey: String
    @State private var survey: FreelanceSurveyDto?
    @State private var score = 0
    @State private var picked: [String] = []
    @State private var comment = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        Group {
            if let s = survey, s.canAnswer == true || s.mine != nil {
                let max = s.notSatisfiedMax ?? 3
                FlCard(icon: "chart.bar.fill", title: L("fl_sv_title")) {
                    if let mine = s.mine {
                        HStack(spacing: 0) {
                            FlStars(value: mine.score ?? 0, size: 18)
                            Text(verbatim: "  " + L("fl_sv_thanks")).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                        }
                    } else {
                        Text(verbatim: L("fl_sv_hint", "\u{200E}" + String((s.answerUntil ?? "").prefix(10)))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        FlStars(value: score, size: 30) { score = $0 }
                        if (1...max).contains(score) {
                            Text(L("fl_sv_why")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                            ForEach(s.reasons ?? [], id: \.self) { r in
                                FlChip(text: surveyReasonLabel(r), selected: picked.contains(r)) {
                                    if let i = picked.firstIndex(of: r) { picked.remove(at: i) } else { picked.append(r) }
                                }
                            }
                        }
                        if score > 0 {
                            DorrTextArea(value: Binding(get: { comment }, set: { comment = String($0.prefix(500)) }), label: L("fl_sv_comment"), minHeight: 70)
                        }
                        if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(flRed) }
                        SettingsPrimaryButton(text: L("fl_sv_send"), enabled: score > 0 && (score > max || !picked.isEmpty), loading: busy) {
                            busy = true
                            error = nil
                            let t = comment.trimmingCharacters(in: .whitespacesAndNewlines)
                            Task {
                                do {
                                    survey = try await FreelanceApi.answerSurvey(projectUuid, score: score, reasons: score <= max ? picked : [], comment: t.isEmpty ? nil : t).data
                                } catch {
                                    self.error = flError(error)
                                }
                                busy = false
                            }
                        }
                    }
                }
            }
        }
        .task(id: "\(projectUuid)|\(refreshKey)|\(network.reconnectTick)") {
            if let fresh = try? await FreelanceApi.survey(projectUuid).data { survey = fresh }
        }
    }
}

/// A short line under the freelancer's name: the average and its stars.
struct ReviewStars: View {
    @Environment(\.wa) private var wa
    let stars: Double

    var body: some View {
        HStack(spacing: 0) {
            FlStars(value: Int(stars.rounded()), size: 14)
            Text(verbatim: " \(stars)").font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk).frame(width: 36, alignment: .leading)
        }
    }
}

/// The parts of the work as steps in a row — a circle each on its status colour (a tick when approved), joined by a line — and,
/// under them, the part being worked on now and where it stands. A tap opens the parts.
private struct StageSteps: View {
    @Environment(\.wa) private var wa
    let parts: [FreelanceMilestoneDto]
    let onOpen: () -> Void

    var body: some View {
        let done = parts.filter { $0.state == "approved" }.count
        let now = parts.first { $0.state != "approved" && $0.state != "cancelled" }
        Button(action: onOpen) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(spacing: 0) {
                    ForEach(Array(parts.enumerated()), id: \.offset) { i, m in
                        let mc = milestoneStatusColor(m.state)
                        let isNow = m.uuid == now?.uuid
                        ZStack {
                            Circle().fill(m.state == "approved" || isNow ? mc : mc.opacity(0.16))
                            if m.state == "approved" {
                                Image(systemName: "checkmark").font(.system(size: 12, weight: .heavy)).foregroundStyle(.white)
                            } else {
                                Text(verbatim: "\(m.position ?? i + 1)").font(.dorr(12, .heavy)).foregroundStyle(isNow ? .white : mc)
                            }
                        }
                        .frame(width: isNow ? 30 : 26, height: isNow ? 30 : 26)
                        if i < parts.count - 1 {
                            Capsule().fill(m.state == "approved" ? milestoneStatusColor("approved") : wa.settingsMut.opacity(0.25))
                                .frame(height: 3).padding(.horizontal, 3).frame(maxWidth: .infinity)
                        }
                    }
                }
                Text(verbatim: now.map { L("fla_now", $0.title ?? "", milestoneStatusLabel($0.state)) + "  ·  " + L("fl_progress_parts", done, parts.count) }
                     ?? L("fla_all_done", done, parts.count))
                    .font(.dorr(12.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2)
            }
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .combine)
    }
}

/// «My receipts» (the client redesign, batch G): every receipt of my freelance work in one list; a pull reads it again.
@MainActor
struct ReceiptsPage: View {
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: L("flr_title"), onBack: onBack, subtitle: L("flr_line"))
            ScrollView {
                ReceiptsCard(projectUuid: nil, refreshKey: "all", showEmpty: true).padding(.horizontal, 16).padding(.vertical, 8)
            }
            .refreshable { network.noticeArrived() }
        }
    }
}
