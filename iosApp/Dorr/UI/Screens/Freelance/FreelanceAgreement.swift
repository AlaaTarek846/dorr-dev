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
    @State private var busy = false
    @State private var error: String?
    @State private var notice: String?
    @State private var confirmCancel = false
    // FL-403: the part the client is sending back, and why.
    @State private var revisionFor: String?
    @State private var revisionReason = ""
    // FL-501: the part being funded (the confirm dialog).
    @State private var fundFor: FreelanceMilestoneDto?
    // FL-506: the part being called off (the dialog says what comes back).
    @State private var cancelFor: FreelanceMilestoneDto?
    @State private var reloads = 0
    // FL-402: the parts, while the client splits them (title, amount as typed).
    @State private var splitting = false
    @State private var parts: [(String, String)] = []

    private func take(_ a: FreelanceAgreementDto?) {
        agreement = a
        if let a {
            scopeText = a.scope ?? ""
            includes = a.includes ?? ""
            excludes = a.excludes ?? ""
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
        return t(scopeText) != (a.scope ?? "") || t(includes) != (a.includes ?? "") || t(excludes) != (a.excludes ?? "")
    }

    private func saveBody() async throws -> ApiEnvelope<FreelanceAgreementDto> {
        func clean(_ s: String) -> String? { let t = s.trimmingCharacters(in: .whitespacesAndNewlines); return t.isEmpty ? nil : t }
        return try await FreelanceApi.updateAgreement(projectUuid, scope: scopeText.trimmingCharacters(in: .whitespacesAndNewlines), includes: clean(includes), excludes: clean(excludes))
    }

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: L("fl_agreement"), onBack: onBack)
            if let a = agreement {
                ScrollView { content(a).padding(.horizontal, 16) }
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: "\(network.reconnectTick)|\(reloads)") {
            do { take(try await FreelanceApi.agreement(projectUuid).data) } catch { self.error = flError(error) }
        }
        .dorrDialog(isPresented: Binding(get: { cancelFor != nil }, set: { if !$0 && !busy { cancelFor = nil } })) {
            if let m = cancelFor { cancelDialog(m) }
        }
        .dorrDialog(isPresented: Binding(get: { fundFor != nil }, set: { if !$0 && !busy { fundFor = nil } })) {
            if let m = fundFor { fundDialog(m) }
        }
        .dorrDialog(isPresented: Binding(get: { revisionFor != nil }, set: { if !$0 && !busy { revisionFor = nil } })) {
            if let target = revisionFor { revisionDialog(target) }
        }
        .dorrDialog(isPresented: $confirmCancel) {
            ConfirmDialog(icon: "xmark", title: L("fl_ag_cancel"), message: L("fl_ag_cancel_confirm"), loading: busy,
                          onConfirm: { act({ try await FreelanceApi.cancelAgreement(projectUuid).data }, after: { confirmCancel = false; onEnded() }) },
                          onDismiss: { confirmCancel = false })
        }
    }

    @ViewBuilder
    private func content(_ a: FreelanceAgreementDto) -> some View {
        let money = a.money ?? FreelanceAgreementMoneyDto()
        let terms = a.terms ?? FreelanceAgreementTermsDto()
        let can = a.can ?? FreelanceAgreementCanDto()
        let milestones = a.milestones ?? []
        VStack(alignment: .leading, spacing: 12) {
            FlCard {
                HStack {
                    Text(verbatim: a.freelancer?.name ?? "—").font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
                    Spacer()
                    Text(verbatim: agreementStatusLabel(a.state)).font(.dorr(12, .bold)).foregroundStyle(agreementStatusColor(a.state))
                }
                Text(verbatim: agreementHint(a.state)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                if let exp = a.expiresAt { Text(verbatim: L("fl_ag_answer_by", "\u{200E}" + String(exp.prefix(10)))).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            }
            FlCard {
                FlField(label: L("fl_price"), value: flAmount(money.priceMinor, a.currency))
                if (money.clientFeeMinor ?? 0) > 0 { FlField(label: L("fl_client_fee"), value: flAmount(money.clientFeeMinor, a.currency)) }
                FlField(label: L("fl_total"), value: flAmount(money.totalMinor, a.currency))
                // FL-510: the split made the total differ from the one told before choosing (a minimum fee on each part).
                if let q = money.quotedTotalMinor, q != money.totalMinor {
                    Text(verbatim: L("fl_quoted_total", flAmount(q, a.currency))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                FlField(label: L("fl_duration"), value: L("fl_days", a.durationDays ?? 0))
                if let d = terms.reviewDays { FlField(label: L("fl_ag_review_days"), value: L("fl_days", d)) }
                if let r = terms.freeRevisions { FlField(label: L("fl_ag_free_revisions"), value: "\(r)") }
            }
            FlCard {
                HStack {
                    FlLabel(text: L("fl_ms_title"))
                    Spacer()
                    if can.split == true && !splitting {
                        FlTextLink(text: L(milestones.count > 1 ? "fl_ms_edit" : "fl_ms_split")) { startSplit(a) }
                    }
                }
                if !splitting {
                    ForEach(milestones) { m in milestoneRow(a, m) }
                } else {
                    splitEditor(a)
                }
            }
            // FL-511 / FL-406: the receipts of this project and its record of approvals.
            let recordsKey = "\(a.state):\(a.version ?? 1):" + milestones.map(\.state).joined(separator: ",")
            ReceiptsCard(projectUuid: projectUuid, refreshKey: recordsKey)
            TrailCard(projectUuid: projectUuid, refreshKey: recordsKey)
            // FL-701: rating the freelancer once it is completed.
            if let rv = a.review {
                ReviewCard(review: rv, busy: busy) { scores, comment in
                    act({
                        _ = try await FreelanceApi.review(projectUuid, scores: scores, comment: comment)
                        return try await FreelanceApi.agreement(projectUuid).data
                    })
                }
            }
            // FL-404 / FL-405: changes after the agreement started.
            if a.state == "active" {
                ChangesCard(changes: a.changes ?? [], milestones: milestones, currency: a.currency ?? "", canAsk: true, busy: busy,
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
            FlCard {
                if can.edit == true {
                    DorrTextArea(value: Binding(get: { scopeText }, set: { scopeText = String($0.prefix(8000)) }), label: L("fl_ag_scope"), minHeight: 110)
                    DorrTextArea(value: Binding(get: { includes }, set: { includes = String($0.prefix(3000)) }), label: L("fl_ag_includes"), minHeight: 70)
                    DorrTextArea(value: Binding(get: { excludes }, set: { excludes = String($0.prefix(3000)) }), label: L("fl_ag_excludes"), minHeight: 70)
                    if a.state == "pending_approval" { Text(L("fl_ag_edit_resets")).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
                } else {
                    FlField(label: L("fl_ag_scope"), value: a.scope)
                    FlField(label: L("fl_ag_includes"), value: a.includes)
                    FlField(label: L("fl_ag_excludes"), value: a.excludes)
                }
            }
            FlNotices(notice: notice, error: error)
            if can.edit == true && changed { FlTextAction(text: L("fl_ag_save")) { act({ try await saveBody().data }) } }
            if can.send == true || (can.edit == true && changed) {
                SettingsPrimaryButton(text: L("fl_ag_send"), enabled: !scopeText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty, loading: busy) {
                    let wasChanged = changed
                    act({
                        if wasChanged { _ = try await saveBody() }
                        let sent = try await FreelanceApi.sendAgreement(projectUuid)
                        notice = sent.message
                        return sent.data
                    })
                }
            }
            if can.cancel == true { FlTextAction(text: L("fl_ag_cancel"), danger: true) { confirmCancel = true } }
            Spacer(minLength: 20)
        }
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
        VStack(alignment: .leading, spacing: 2) {
            HStack {
                Text(verbatim: "\(m.position ?? 1). \(m.title ?? "")").font(.dorr(14)).foregroundStyle(wa.settingsInk)
                Spacer()
                Text(verbatim: flAmount(m.amountMinor, a.currency)).font(.dorr(14, .bold)).foregroundStyle(wa.accent)
            }
            if a.state == "active" {
                Text(verbatim: milestoneStatusLabel(m.state) + ((m.heldMinor ?? 0) > 0 ? "  ·  " + L("fl_held", flAmount(m.heldMinor, a.currency)) : ""))
                    .font(.dorr(12, .bold)).foregroundStyle(milestoneStatusColor(m.state))
            }
            // FL-602 / FL-604: the versions delivered (opened in place) and the checklist for the part.
            if a.state == "active" || a.state == "completed" { DeliveryReview(projectUuid: projectUuid, m: m) }
            // FL-407: the free rounds of changes.
            if m.state == "delivered", let max = m.revisionsMax, max > 0 {
                Text(verbatim: (m.revisionsLeft ?? 0) > 0 ? L("fl_ms_rounds_left", m.revisionsLeft ?? 0, max) : L("fl_ms_rounds_none", m.revisionsLeft ?? 0, max))
                    .font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
            // FL-506: a request to end a started part waits for an answer; what went back after it ended.
            if m.cancelRequestedBy == "freelancer" { Text(L("fl_ms_cancel_asked_them")).font(.dorr(12)).foregroundStyle(flAmber) }
            if m.cancelRequestedBy == "client" { Text(L("fl_ms_cancel_asked_you")).font(.dorr(12)).foregroundStyle(flAmber) }
            if m.state == "cancelled", (m.refundedMinor ?? 0) > 0 {
                Text(verbatim: L("fl_ms_refunded", flAmount(m.refundedMinor, a.currency))).font(.dorr(12)).foregroundStyle(flGreen)
            }
            if let auto = m.autoApproveAt { Text(verbatim: L("fl_auto_approve_on", "\u{200E}" + String(auto.prefix(10)))).font(.dorr(11)).foregroundStyle(flAmber) }
            FlowLayout(spacing: 16) {
                if can.fund == true { FlTextLink(text: L("fl_fund")) { fundFor = m } }
                if can.approve == true { FlTextLink(text: L("fl_ms_approve")) { act({ try await FreelanceApi.milestoneAction(projectUuid, m.uuid, action: "approve").data }) } }
                if can.revision == true { FlTextLink(text: L("fl_ms_revision")) { revisionFor = m.uuid; revisionReason = "" } }
                if can.cancel == true { FlTextLink(text: L(m.state == "unfunded" ? "fl_ms_cancel" : "fl_ms_call_off")) { cancelFor = m } }
                if can.cancelAccept == true {
                    FlTextLink(text: L("fl_ms_cancel_accept")) { act({ try await FreelanceApi.milestoneAction(projectUuid, m.uuid, action: "cancel-accept").data }) }
                }
                // FL-704: open a dispute, or see it.
                if let dsp = m.dispute { FlTextLink(text: L("fl_dispute_see")) { onDispute(m.uuid, dsp.uuid) } }
                if can.dispute == true && (m.dispute == nil || m.dispute?.status == "resolved") {
                    FlTextLink(text: L("fl_dispute_open")) { onDispute(m.uuid, nil) }
                }
                if can.cancelReject == true {
                    FlTextLink(text: L("fl_ms_cancel_reject")) { act({ try await FreelanceApi.milestoneAction(projectUuid, m.uuid, action: "cancel-reject").data }) }
                }
            }
        }
        .padding(.vertical, 4)
    }

    @ViewBuilder
    private func splitEditor(_ a: FreelanceAgreementDto) -> some View {
        let sum = parts.reduce(0) { $0 + (flParseMoney($1.1) ?? 0) }
        let left = (a.priceMinor ?? 0) - sum
        let rules = a.milestoneRules ?? FreelanceMilestoneRulesDto()
        ForEach(parts.indices, id: \.self) { i in
            HStack(spacing: 6) {
                DorrTextArea(value: Binding(get: { parts.indices.contains(i) ? parts[i].0 : "" }, set: { if parts.indices.contains(i) { parts[i].0 = String($0.prefix(120)) } }),
                             placeholder: L("fl_ms_part", i + 1), singleLine: true)
                    .layoutPriority(1.4)
                DorrTextArea(value: Binding(get: { parts.indices.contains(i) ? parts[i].1 : "" }, set: { if parts.indices.contains(i) { parts[i].1 = String($0.prefix(12)) } }),
                             placeholder: "0.00", singleLine: true)
                    .keyboardType(.decimalPad)
                    .frame(maxWidth: 110)
                if parts.count > 1 { RemoveButton { parts.remove(at: i) } }
            }
        }
        if parts.count < (rules.maxCount ?? 5) { FlTextLink(text: L("fl_ms_add")) { parts.append(("", flMoney(Swift.max(left, 0)))) } }
        Text(verbatim: left == 0 ? L("fl_ms_balanced") : L("fl_ms_left", flAmount(left, a.currency))).font(.dorr(12, .bold)).foregroundStyle(left == 0 ? flGreen : flAmber)
        if let minPart = rules.minAmountMinor {
            Text(verbatim: L("fl_ms_rules", rules.maxCount ?? 5, flAmount(minPart, a.currency))).font(.dorr(11)).foregroundStyle(wa.settingsMut)
        }
        HStack(spacing: 16) {
            FlTextLink(text: L("fl_ms_save")) {
                let items = parts.enumerated().map { i, p -> (title: String, amountMinor: Int) in
                    let t = p.0.trimmingCharacters(in: .whitespaces)
                    return (t.isEmpty ? "\(i + 1)" : t, flParseMoney(p.1) ?? 0)
                }
                act({ try await FreelanceApi.setMilestones(projectUuid, items).data }, after: { splitting = false })
            }
            FlTextLink(text: L("fl_ms_cancel_edit")) { splitting = false }
        }
    }

    private func cancelDialog(_ m: FreelanceMilestoneDto) -> some View {
        let note: String
        switch m.state {
        case "unfunded": note = L("fl_ms_cancel_note_unfunded")
        case "funded": note = L("fl_ms_cancel_note_funded")
        default: note = L("fl_ms_cancel_note_started")
        }
        return FlAlert(title: L("fl_ms_call_off_title", m.title ?? ""), content: {
            Text(verbatim: note).font(.dorr(13)).foregroundStyle(wa.settingsMut)
        }, confirm: L(m.state == "in_progress" || m.state == "revision" ? "fl_ms_cancel_ask" : "fl_ms_cancel_confirm"), confirmColor: flRed, confirmEnabled: !busy,
           onConfirm: { act({ try await FreelanceApi.milestoneAction(projectUuid, m.uuid, action: "cancel").data }, after: { cancelFor = nil }) },
           onDismiss: { cancelFor = nil })
    }

    private func fundDialog(_ m: FreelanceMilestoneDto) -> some View {
        let cur = agreement?.currency
        let total = (m.amountMinor ?? 0) + (m.clientFeeMinor ?? 0)
        let available = agreement?.walletAvailableMinor ?? 0
        let enough = available >= total
        return FlAlert(title: L("fl_fund_title", m.title ?? ""), content: {
            VStack(alignment: .leading, spacing: 6) {
                FlField(label: L("fl_price"), value: flAmount(m.amountMinor, cur))
                if (m.clientFeeMinor ?? 0) > 0 { FlField(label: L("fl_client_fee"), value: flAmount(m.clientFeeMinor, cur)) }
                FlField(label: L("fl_total"), value: flAmount(total, cur))
                FlField(label: L("fl_wallet_available"), value: flAmount(available, cur))
                Text(L(enough ? "fl_fund_note" : "fl_fund_short")).font(.dorr(12)).foregroundStyle(enough ? wa.settingsMut : flAmber)
            }
        }, confirm: L(enough ? "fl_fund_confirm" : "fl_topup"), confirmEnabled: !busy, onConfirm: {
            if enough {
                act({ try await FreelanceApi.milestoneAction(projectUuid, m.uuid, action: "fund").data }, after: { fundFor = nil })
            } else {
                fundFor = nil
                // Top up what is missing; coming back reads the wallet once more.
                TopupLauncher.shared.open(total - available) { reloads += 1 }
            }
        }, onDismiss: { fundFor = nil })
    }

    private func revisionDialog(_ target: String) -> some View {
        FlAlert(title: L("fl_ms_revision"), content: {
            DorrTextArea(value: Binding(get: { revisionReason }, set: { revisionReason = String($0.prefix(1000)) }), placeholder: L("fl_ms_revision_hint"), minHeight: 90)
        }, confirm: L("fl_ms_revision_send"), confirmEnabled: revisionReason.trimmingCharacters(in: .whitespacesAndNewlines).count >= 5 && !busy, onConfirm: {
            act({ try await FreelanceApi.milestoneAction(projectUuid, target, action: "revision", reason: revisionReason.trimmingCharacters(in: .whitespacesAndNewlines)).data },
                after: { revisionFor = nil })
        }, onDismiss: { revisionFor = nil })
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
        FlCard {
            FlLabel(text: L("fl_ch_title"))
            if changes.isEmpty { Text(L("fl_ch_none")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            ForEach(changes, id: \.uuid) { c in
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: changeText(c, currency)).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: L(c.mine == true ? "fl_ch_by_me" : "fl_ch_by_them") + "  ·  " + changeStatus(c.status)).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    if let r = c.reason { Text(verbatim: r).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    HStack(spacing: 16) {
                        if c.can?.accept == true { FlTextAction(text: L("fl_ch_accept"), fill: false) { onAnswer(c.uuid, "accept") } }
                        if c.can?.reject == true { FlTextAction(text: L("fl_ch_reject"), danger: true, fill: false) { onAnswer(c.uuid, "reject") } }
                        if c.can?.withdraw == true { FlTextAction(text: L("fl_ch_withdraw"), fill: false) { onAnswer(c.uuid, "withdraw") } }
                    }
                }
                .padding(10)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(wa.accent.opacity(0.05), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
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
                            ForEach(Array((v.files ?? []).enumerated()), id: \.offset) { _, f in
                                if f.isImage == true {
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
        .dorrDialog(isPresented: $editing) {
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

    var body: some View {
        Group {
            if let image {
                Image(uiImage: image).resizable().scaledToFit()
                    .frame(maxWidth: .infinity, maxHeight: 320)
                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .onTapGesture { Task { _ = await flOpenFile(path: f.path ?? "", name: "\(f.id ?? 0)_\(f.name ?? "file")") } }
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
        FlAlert(title: L("fl_ck_set"), content: {
            VStack(alignment: .leading, spacing: 6) {
                Text(L("fl_ck_editor_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                ForEach(lines.indices, id: \.self) { i in
                    HStack(spacing: 0) {
                        DorrTextArea(value: Binding(get: { lines.indices.contains(i) ? lines[i] : "" }, set: { if lines.indices.contains(i) { lines[i] = String($0.prefix(200)) } }),
                                     placeholder: L("fl_ck_line"), singleLine: true)
                        Button { lines.remove(at: i) } label: { Text(verbatim: "✕").font(.dorr(16)).foregroundStyle(flRed).padding(10) }.buttonStyle(.plain)
                    }
                }
                if lines.count < maxChecks { FlTextLink(text: L("fl_ck_add")) { lines.append("") } }
            }
        }, confirm: L("fl_ck_save"), onConfirm: {
            onSave(lines.map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }.filter { !$0.isEmpty })
        }, dismiss: L("common_cancel"), onDismiss: onDismiss)
        .onAppear { lines = initial.isEmpty ? [""] : initial }
    }
}

// MARK: - A dispute (FL-704)

/// The reasons a dispute may give (as the server names them).
private let disputeReasons = ["not_as_agreed", "not_delivered", "late", "quality", "out_of_scope", "no_response", "other"]

private func disputeReasonLabel(_ reason: String) -> String {
    switch reason {
    case "not_as_agreed": return L("fl_dr_not_as_agreed")
    case "not_delivered": return L("fl_dr_not_delivered")
    case "late": return L("fl_dr_late")
    case "quality": return L("fl_dr_quality")
    case "out_of_scope": return L("fl_dr_out_of_scope")
    case "no_response": return L("fl_dr_no_response")
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
            SubHeader(title: L("fl_dispute"), onBack: onBack)
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
        FlCard {
            FlLabel(text: L("fl_dispute_open_title"))
            Text(L("fl_dispute_open_note")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            FlowLayout(spacing: 6) {
                ForEach(disputeReasons, id: \.self) { r in FlChip(text: disputeReasonLabel(r), selected: reason == r) { reason = r } }
            }
            DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(3000)) }), label: L("fl_dispute_details"), placeholder: L("fl_dispute_details_hint"), minHeight: 110)
        }
        if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(flRed) }
        SettingsPrimaryButton(text: L("fl_dispute_open"), enabled: reason != nil && trimmed.count >= 20 && milestoneUuid != nil, loading: busy) {
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
        FlCard {
            Text(verbatim: disputeStatusLabel(d.status)).font(.dorr(15, .heavy)).foregroundStyle(d.status == "resolved" ? flGreen : flAmber)
            if let m = d.milestone { Text(verbatim: "\(m.title ?? "")  ·  " + flAmount(m.amountMinor, d.currency)).font(.dorr(13)).foregroundStyle(wa.settingsInk) }
            Text(verbatim: L(d.mine == true ? "fl_dispute_opened_by_me" : "fl_dispute_opened_by_them") + "  ·  " + (d.reasonLabel ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            Text(verbatim: d.details ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(4)
            if d.status == "open" || d.status == nil, let by = d.respondBy {
                Text(verbatim: L("fl_dispute_respond_by", "\u{200E}" + String(by.prefix(10)))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            Text(L("fl_dispute_frozen")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
        }
        if let dec = d.decision {
            FlCard {
                FlLabel(text: L("fl_dispute_decision"))
                Text(verbatim: dec.type == "refund" ? L("fl_dd_refund") : (dec.type == "release" ? L("fl_dd_release") : L("fl_dd_split", (dec.freelancerBp ?? 0) / 100)))
                    .font(.dorr(14, .bold)).foregroundStyle(wa.accent)
                if let note = dec.note { Text(verbatim: note).font(.dorr(13)).foregroundStyle(wa.settingsInk) }
                if d.status == "decided", let at = dec.executesAt {
                    Text(verbatim: L("fl_dispute_runs_at", "\u{200E}" + String(at.prefix(10)))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                if dec.final == true { Text(L("fl_dispute_final")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            }
        }
        if d.appeal != nil { Text(L("fl_dispute_appealed_note")).font(.dorr(12)).foregroundStyle(flAmber) }
        FlCard {
            FlLabel(text: L("fl_dispute_evidence"))
            let evidence = d.evidence ?? []
            if evidence.isEmpty { Text(L("fl_dispute_no_evidence")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            ForEach(Array(evidence.enumerated()), id: \.offset) { _, e in
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: L(e.mine == true ? "fl_dispute_you" : "fl_dispute_them") + "  ·  \u{200E}" + String((e.at ?? "").prefix(10))).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: e.body ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk)
                    if let file = e.file { Text(verbatim: "📎 " + (file.name ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                }
                .padding(10)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(e.mine == true ? wa.accent.opacity(0.06) : Color(hex: 0x6B7280).opacity(0.06), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            if d.can?.evidence == true {
                DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(3000)) }), placeholder: L("fl_dispute_add_hint"), minHeight: 90)
                FlTextAction(text: L("fl_dispute_add")) {
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
                FlTextAction(text: L("fl_dispute_appeal"), danger: true) { appealing = true; text = "" }
            } else {
                FlCard {
                    Text(L("fl_dispute_appeal_note")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(2000)) }), placeholder: L("fl_dispute_appeal_hint"), minHeight: 90)
                    SettingsPrimaryButton(text: L("fl_dispute_appeal_send"), enabled: trimmed.count >= 20, loading: busy) {
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
}

// MARK: - Receipts and the record of approvals (FL-511 / FL-406)

/// The project's receipts: one per money movement that concerns the client, newest first. Read when shown, when the
/// agreement changes (`refreshKey`) and when the connection comes back; a receipt opens with its details and can be
/// shared as text.
@MainActor
private struct ReceiptsCard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let refreshKey: String
    @State private var list: [FreelanceReceiptDto] = []
    @State private var open: FreelanceReceiptDto?

    var body: some View {
        Group {
            if !list.isEmpty {
                FlCard {
                    FlLabel(text: L("fl_rcp_title"))
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
            if let all = try? await FreelanceApi.receipts().data { list = all.filter { $0.project?.uuid == projectUuid } }
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
                            FlLabel(text: L("fl_trail_title", list.count))
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

private struct FlStars: View {
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
/// days are over.
@MainActor
private struct ReviewCard: View {
    @Environment(\.wa) private var wa
    let review: FreelanceReviewDto
    let busy: Bool
    let onSend: ([String: Int], String?) -> Void
    @State private var scores: [String: Int] = [:]
    @State private var comment = ""

    var body: some View {
        let criteria = review.criteria ?? []
        FlCard {
            FlLabel(text: L("fl_rv_title"))
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
            }
            if let theirs = review.theirs {
                VStack(alignment: .leading, spacing: 2) {
                    Text(L("fl_rv_theirs")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    rating(theirs)
                }
                .padding(.top, 6)
            } else if review.theirsWaiting == true {
                Text(L("fl_rv_waiting")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            } else if review.canRate != true && review.mine == nil {
                Text(L("fl_rv_closed")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
        }
    }

    @ViewBuilder
    private func rating(_ r: FreelanceRatingDto) -> some View {
        HStack(spacing: 0) {
            FlStars(value: Int((r.stars ?? 0).rounded()), size: 18)
            Text(verbatim: "  \(r.stars ?? 0)").font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
        }
        if let c = r.comment { Text(verbatim: c).font(.dorr(13)).foregroundStyle(wa.settingsInk) }
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
