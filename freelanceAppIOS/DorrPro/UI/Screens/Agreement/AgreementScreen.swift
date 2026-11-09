// freelanceApp `ui/screens/AgreementScreen.kt`
import SwiftUI

func agreementStatusText(_ status: String?) -> String {
    switch status {
    case "active": return L("ag_st_active")
    case "declined": return L("ag_st_declined")
    case "cancelled": return L("ag_st_cancelled")
    case "expired": return L("ag_st_expired")
    case "completed": return L("ag_st_completed")
    default: return L("ag_st_pending")
    }
}

func milestoneStatusText(_ status: String?) -> String {
    switch status {
    case "funded": return L("ag_mst_funded")
    case "in_progress": return L("ag_mst_in_progress")
    case "delivered": return L("ag_mst_delivered")
    case "revision": return L("ag_mst_revision")
    case "approved": return L("ag_mst_approved")
    case "disputed": return L("ag_mst_disputed")
    case "cancelled": return L("ag_mst_cancelled")
    default: return L("ag_mst_unfunded")
    }
}

/// The agreement the client sent on my chosen offer (FL-401): what the work covers, the price and what it leaves me after the
/// commission (as it was when the agreement was made), the review days and the free revisions. I approve it — the work starts — or
/// decline it with a reason. Read on open, when a notice arrives and when the connection comes back.
struct AgreementScreen: View {
    let projectUuid: String
    let onBack: () -> Void
    let onDispute: (String, String?) -> Void
    /// FS-150e: opens the project's Workroom (tasks, files, decisions, timeline).
    var onWorkroom: () -> Void = {}

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var hub = HomeHub.shared
    @State private var agreement: AgreementDto?
    @State private var error: String?
    @State private var busy = false
    @State private var declining = false
    @State private var reason = ""
    // FL-403: the part being delivered.
    @State private var deliverFor: String?
    // FL-506: stepping back from a part.
    @State private var withdrawFor: String?
    // FL-607: why I step back (a picked reason and a few words).
    @State private var withdrawCode: String?
    @State private var withdrawNote = ""
    // FS-209b: the part whose people an Owner or a PM is changing.
    @State private var assigning: MilestoneDto?
    @State private var tab = 0

    private var offline: String { L("login_error_offline") }
    private var generic: String { L("login_error_generic") }

    var body: some View {
        Page(title: L("ag_title"), onBack: onBack) {
            if let a = agreement {
                content(a)
            } else if error == nil {
                SkeletonDetail()
            }
        }
        .messageToast(error)
        .task(id: "\(projectUuid)|\(network.reconnectTick)") { await load() }
        .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
        .formSheet(isPresented: agreementPresence($withdrawFor), icon: "rectangle.portrait.and.arrow.right", title: L("ag_ms_withdraw"),
                   text: L("ag_ms_withdraw_note"), locked: busy) {
            CancelReasonPicker(code: withdrawCode, note: $withdrawNote, onCode: { withdrawCode = $0 })
            ActionButton(L("ag_ms_withdraw"), "rectangle.portrait.and.arrow.right", .danger, enabled: !busy && cancelReasonReady(withdrawCode, withdrawNote)) {
                if let target = withdrawFor {
                    milestone(target, "withdraw", withdrawNote.agreementTrimmedOrNil, withdrawCode)
                    withdrawFor = nil
                }
            }
        }
        .formSheet(isPresented: agreementPresence($deliverFor), icon: "icloud.and.arrow.up.fill", title: L("ag_ms_deliver"),
                   text: L("ag_form_deliver_intro"), fullHeight: true, locked: busy) {
            DeliverDialog(busy: busy, error: error, rightsRequired: agreement?.rights != nil) { text, files, materials in
                if let target = deliverFor { deliver(target, text, files, materials) }
            }
        }
        .formSheet(isPresented: $declining, icon: "xmark.circle.fill", title: L("ag_decline"), text: L("ag_decline_hint"), locked: busy) {
            DorrTextField(value: agreementLimit($reason, 500), label: L("ag_reason"), icon: "text.alignleft", singleLine: false, minLines: 3)
            if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(Color(hex: 0xDC2626)) }
            ActionButton(L("ag_decline"), "xmark.circle.fill", .danger, enabled: !busy, loading: busy) { act(false) }
        }
        .cmSheet(isPresented: agreementPresence($assigning)) { assigneesSheet }
    }

    /// FS-209b: an Owner's or a PM's sheet to put people of the team on the part (shown with `.cmSheet`, which closes itself).
    @ViewBuilder
    private var assigneesSheet: some View {
        if let m = assigning, let team = agreement?.teamAccess?.team?.uuid {
            AssigneesSheet(
                projectUuid: projectUuid, milestoneUuid: m.uuid ?? "", milestoneTitle: m.title ?? "", teamUuid: team, current: m.assignees ?? [],
                onSaved: {
                    assigning = nil
                    Task { await load() }
                }
            )
        }
    }

    @ViewBuilder private func content(_ a: AgreementDto) -> some View {
        let tint = agreementColor(a.status)
        let milestones = a.milestones ?? []
        let live = milestones.filter { $0.status != "cancelled" }
        let done = live.filter { $0.status == "approved" }.count
        let cur = a.currency ?? ""
        let money = a.money

        // FS-209b: a team's project - whose team, my place in it, and why parts of the page may be missing.
        if let access = a.teamAccess { TeamAccessBanner(access: access) }
        // What it is and where it stands - with the answer buttons while it waits for me.
        VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 12) {
                Image(systemName: "hands.sparkles.fill").font(.system(size: 19, weight: .semibold)).foregroundStyle(tint)
                    .frame(width: 44, height: 44)
                    .background(tint.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 4) {
                    Text(L("ag_title")).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: agreementStatusText(a.status)).font(.dorr(11, .bold)).foregroundStyle(tint)
                        .padding(.horizontal, 10).padding(.vertical, 3)
                        .background(tint.opacity(0.12), in: Capsule())
                }
                Spacer(minLength: 0)
            }
            HStack(spacing: 8) {
                if a.showMoney { AgreementStat(value: "\u{200E}" + formatMinor(money?.priceMinor ?? 0, nil) + " " + cur, label: L("ag_price")) }
                if a.showNet { AgreementStat(value: "\u{200E}" + formatMinor(money?.netMinor ?? 0, nil) + " " + cur, label: L("ag_net")) }
                AgreementStat(value: L("projects_days", a.durationDays ?? 0), label: L("ag_days"))
            }
            if !live.isEmpty && a.status != "pending_approval" {
                let fraction = Double(done) / Double(live.count)
                VStack(spacing: 6) {
                    HStack {
                        Text(verbatim: L("work_parts_done", done, live.count)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        Text(verbatim: "\u{200E}\(Int(fraction * 100))%").font(.dorr(11.5, .heavy)).foregroundStyle(tint)
                    }
                    GeometryReader { proxy in
                        ZStack(alignment: .leading) {
                            Capsule().fill(wa.settingsMut.opacity(0.18))
                            Capsule().fill(tint).frame(width: proxy.size.width * fraction)
                        }
                    }
                    .frame(height: 6)
                }
            }
            if a.status == "pending_approval", let expires = a.expiresAt {
                Text(verbatim: L("ag_answer_by", "\u{200E}" + String(expires.prefix(10)))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            if a.can?.approve == true { ActionButton(L("ag_approve"), "checkmark.circle.fill", .primary, loading: busy && !declining) { act(true) } }
            if a.can?.decline == true { ActionButton(L("ag_decline"), "xmark.circle.fill", .danger, enabled: !busy) { declining = true } }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20)

        // FS-106: how the running project is doing (it reloads with the agreement, on the health notices too).
        if a.status == "active", let health = a.health { ProjectHealthCard(h: health) }

        // FS-150e: the project's Workroom (tasks, files, decisions, timeline) — while it runs, and to read once it is over.
        if (a.status == "active" || a.status == "completed") && hub.home?.on("workroom") == true {
            WorkroomEntryCard(readOnly: a.status == "completed", onClick: onWorkroom)
        }

        AgreementTabs(tabs: [
            AgreementTab(icon: "doc.text.fill", label: L("ag_tab_overview")),
            AgreementTab(icon: "checklist", label: L("ag_tab_parts"), badge: milestones.count),
            AgreementTab(icon: "clock.arrow.circlepath", label: L("ag_tab_activity")),
        ], selected: tab) { tab = $0 }

        let recordsKey = (a.status ?? "") + ":" + milestones.map { $0.status ?? "" }.joined(separator: ",")
        switch tab {
        case 0:
            AgreementSection(title: L("ag_terms")) {
                if a.showNet { AgreementLine(label: L("ag_commission"), value: "\u{200E}\(formatMinor(money?.commissionMinor ?? 0, nil)) \(cur)") }
                if let d = a.terms?.reviewDays { AgreementLine(label: L("ag_review_days"), value: L("projects_days", d)) }
                if let r = a.terms?.freeRevisions { AgreementLine(label: L("ag_free_revisions"), value: String(r)) }
            }
            AgreementSection(title: L("ag_scope")) {
                Text(verbatim: a.scope ?? "").font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(5)
                if let inc = a.includes, !inc.agreementTrimmed.isEmpty {
                    FieldLabel(text: L("ag_includes"))
                    Text(verbatim: inc).font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(5)
                }
                if let exc = a.excludes, !exc.agreementTrimmed.isEmpty {
                    FieldLabel(text: L("ag_excludes"))
                    Text(verbatim: exc).font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(5)
                }
            }
            // FL-L13: who owns the work, where the passing of the rights stands, the portfolio leave.
            if let r = a.rights { AgreementRightsCard(r: r, projectUuid: projectUuid) { updated in agreement = updated } }
        case 1:
            if milestones.isEmpty { Text(L("ag_no_parts")).font(.dorr(13)).foregroundStyle(wa.settingsMut) }
            // FL-402: the parts it is paid in.
            ForEach(Array(milestones.enumerated()), id: \.offset) { _, m in
                let uuid = m.uuid ?? ""
                MilestoneCard(
                    m: m, currency: cur, active: a.status == "active", busy: busy, projectUuid: projectUuid,
                    showMoney: a.showMoney, showNet: a.showNet, teamParts: a.team != nil,
                    canAssign: a.status == "active" && a.teamAccess?.can("assign") == true,
                    onAssign: { assigning = m },
                    onAction: { milestone(uuid, $0) },
                    onDeliver: { deliverFor = uuid },
                    onWithdraw: { withdrawCode = nil; withdrawNote = ""; withdrawFor = uuid },
                    onDispute: { onDispute(uuid, $0) }
                )
            }
        default:
            // FL-605: progress reports (optional; the client may ask for one every few days).
            if a.status == "active" || a.status == "completed" {
                AgreementReportsCard(projectUuid: projectUuid, summary: a.reports, canSend: a.can?.report == true,
                                     refreshKey: recordsKey + ":" + (a.reports?.lastAt ?? "null"))
            }
            // FL-404 / FL-405: changes after the agreement started.
            if a.status == "active" {
                AgreementChangesCard(
                    projectUuid: projectUuid, changes: a.changes ?? [], milestones: milestones, currency: cur, busy: busy,
                    onAsk: { body, files in
                        // FS-101: one multipart request - the fields and up to 3 `files[]`.
                        let parts = files.map { FilePart(field: "files[]", filename: $0.name, mimeType: $0.type, data: $0.bytes) }
                        run { await AgreementsApi.requestChange(projectUuid, fields: body.fields, files: parts).error(L("login_error_offline")) }
                    },
                    onAnswer: { change, action in run { await AgreementsApi.answerChange(projectUuid, change, action).error(L("login_error_offline")) } }
                )
            }
            // FL-511 / FL-406: my receipts of this project and its record of approvals.
            // FS-209b: the receipts, the rating and the survey are the signing Owner's, not a team member's.
            let signs = a.teamAccess?.signer != false
            if signs { ProjectReceiptsCard(projectUuid: projectUuid, refreshKey: recordsKey) }
            ApprovalTrailCard(projectUuid: projectUuid, refreshKey: recordsKey)
            // FL-701: rating the client once it is completed.
            if signs, let rv = a.review {
                ReviewClientCard(
                    review: rv, busy: busy,
                    onObject: { body in run { await AgreementsApi.objectToReview(projectUuid, body).error(L("login_error_offline")) } },
                    onSend: { body in run { await AgreementsApi.review(projectUuid, body).error(L("login_error_offline")) } }
                )
            }
            // FL-702: how satisfied, once it is over.
            if signs && (a.status == "completed" || a.status == "cancelled") { ProjectSurveyCard(projectUuid: projectUuid, refreshKey: recordsKey) }
        }
    }

    private func load() async {
        let result = await AgreementsApi.show(projectUuid)
        if let data = result.value { agreement = data } else if agreement == nil { error = result.errorText(offline) ?? generic }
    }

    /// A call whose answer is not the agreement: on success the agreement is read again, else its error is shown.
    private func run(_ call: @escaping () async -> String??) {
        busy = true
        Task {
            if let failed = await call() { error = failed ?? generic } else { await load() }
            busy = false
        }
    }

    // FL-601: a delivery carries files; it goes as one multipart request.
    private func deliver(_ uuid: String, _ text: String, _ files: [AgreementPickedFile], _ materials: [MaterialRow]?) {
        busy = true
        error = nil
        Task {
            let parts = files.map { FilePart(field: "files[]", filename: $0.name, mimeType: $0.type, data: $0.bytes) }
            var fields = ["note": text]
            // FL-L13: my word that the work is mine to hand over, and the outside material I listed.
            if let materials {
                fields["rights_confirmed"] = "1"
                for (i, m) in materials.enumerated() {
                    fields["materials[\(i)][name]"] = m.name.agreementTrimmed
                    if !m.source.agreementTrimmed.isEmpty { fields["materials[\(i)][source]"] = m.source.agreementTrimmed }
                    if !m.license.agreementTrimmed.isEmpty { fields["materials[\(i)][license]"] = m.license.agreementTrimmed }
                }
            }
            let result = await AgreementsApi.deliver(projectUuid, uuid, fields: fields, files: parts)
            if let data = result.value { agreement = data; deliverFor = nil } else { error = result.errorText(offline) ?? generic }
            busy = false
        }
    }

    private func milestone(_ uuid: String, _ action: String, _ text: String? = nil, _ reasonCode: String? = nil) {
        busy = true
        error = nil
        Task {
            let result = await AgreementsApi.milestone(projectUuid, uuid, action, MilestoneActionRequest(note: text, reasonCode: reasonCode))
            if let data = result.value { agreement = data; deliverFor = nil } else { error = result.errorText(offline) ?? generic }
            busy = false
        }
    }

    private func act(_ approve: Bool) {
        busy = true
        error = nil
        Task {
            let result = approve ? await AgreementsApi.approve(projectUuid) : await AgreementsApi.decline(projectUuid, DeclineRequest(reason: reason.agreementTrimmedOrNil))
            if let data = result.value {
                agreement = data
                declining = false
            } else {
                error = result.errorText(offline) ?? generic
            }
            busy = false
        }
    }
}

extension ApiResult {
    /// For [AgreementScreen.run]: nil when it went through, else `.some(text)` (the text nil when there is none to show).
    fileprivate func error(_ offline: String) -> String?? {
        if case .ok = self { return nil }
        return .some(errorText(offline))
    }
}

/// One part of the work: its number, name and state, the money, what to do next, and its deliveries and checklist.
private struct MilestoneCard: View {
    @Environment(\.wa) private var wa
    let m: MilestoneDto
    let currency: String
    let active: Bool
    let busy: Bool
    let projectUuid: String
    // FS-209b: a team member without `money` sees no amounts, only an Owner sees the net; a team's part shows who is on it.
    let showMoney: Bool
    let showNet: Bool
    let teamParts: Bool
    let canAssign: Bool
    let onAssign: () -> Void
    let onAction: (String) -> Void
    let onDeliver: () -> Void
    let onWithdraw: () -> Void
    let onDispute: (String?) -> Void

    /// The part's amount, with what it leaves me when the net may be shown.
    private var moneyLine: String {
        var line = "\u{200E}\(formatMinor(m.amountMinor ?? 0, nil)) \(currency)"
        if showNet { line += "  ·  " + L("ag_part_net", "\u{200E}\(formatMinor(m.netMinor ?? 0, nil)) \(currency)") }
        return line
    }

    var body: some View {
        let tint = milestoneColor(m.status)
        let amber = Color(hex: 0xD97706)
        let can = m.can
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                Text(verbatim: String(m.position ?? 1)).font(.dorr(14, .heavy)).foregroundStyle(tint)
                    .frame(width: 36, height: 36)
                    .background(tint.opacity(0.14), in: Circle())
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: m.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    if showMoney { Text(verbatim: moneyLine).font(.dorr(11.5)).foregroundStyle(wa.settingsMut) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if active {
                    Text(verbatim: milestoneStatusText(m.status)).font(.dorr(11, .bold)).foregroundStyle(tint)
                        .padding(.horizontal, 10).padding(.vertical, 3)
                        .background(tint.opacity(0.12), in: Capsule())
                }
            }
            if let paid = m.paidMinor {
                Text(verbatim: L("ag_ms_paid", "\u{200E}\(formatMinor(paid, nil)) \(currency)")).font(.dorr(12, .bold)).foregroundStyle(Color(hex: 0x16A34A))
            }
            if let auto = m.autoApproveAt {
                Text(verbatim: L("ag_ms_auto", "\u{200E}" + String(auto.prefix(10)))).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
            if let revision = m.revisionReason {
                Text(verbatim: L("ag_ms_revision_reason", revision)).font(.dorr(12)).foregroundStyle(amber).lineSpacing(4)
                    .padding(10)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(amber.opacity(0.08), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            // FS-209b: who of the team is on this part (the team's own - the client never sees it).
            if teamParts && m.status != "cancelled" { AssigneesRow(people: m.assignees ?? [], canAssign: canAssign, onAssign: onAssign) }
            ChecklistView(m: m)
            DeliveriesBlock(projectUuid: projectUuid, m: m)
            if m.cancelRequestedBy == "client" { Text(L("ag_ms_cancel_asked")).font(.dorr(12, .semibold)).foregroundStyle(amber) }
            if m.cancelRequestedBy == "freelancer" { Text(L("ag_ms_cancel_waiting")).font(.dorr(12, .semibold)).foregroundStyle(amber) }
            // What I can do with it now: the main step filled, the rest tinted.
            if can?.start == true { ActionButton(L("ag_ms_start"), "play.fill", .primary, enabled: !busy) { onAction("start") } }
            if can?.deliver == true { ActionButton(L("ag_ms_deliver"), "icloud.and.arrow.up.fill", .primary, enabled: !busy) { onDeliver() } }
            if can?.cancelAccept == true { ActionButton(L("ag_ms_cancel_accept"), "checkmark.circle.fill", .soft, enabled: !busy) { onAction("cancel-accept") } }
            if can?.cancelReject == true { ActionButton(L("ag_ms_cancel_reject"), "xmark.circle.fill", .danger, enabled: !busy) { onAction("cancel-reject") } }
            // FL-704: open a dispute, or follow it.
            if let dsp = m.dispute { ActionButton(L("dp_see"), "hammer.fill", .soft) { onDispute(dsp.uuid) } }
            if can?.dispute == true && (m.dispute == nil || m.dispute?.status == "resolved") {
                ActionButton(L("dp_open"), "hammer.fill", .warn) { onDispute(nil) }
            }
            if can?.withdraw == true { ActionButton(L("ag_ms_withdraw"), "rectangle.portrait.and.arrow.right", .danger, enabled: !busy) { onWithdraw() } }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20)
    }
}

/// `OfferSection` of OfferScreens.kt — a titled card.
private struct AgreementSection<Content: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(verbatim: title).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
            content()
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(18)
    }
}

/// `OfferStat` of OfferScreens.kt — one number on a soft tile.
private struct AgreementStat: View {
    @Environment(\.wa) private var wa
    let value: String
    let label: String

    var body: some View {
        VStack(spacing: 3) {
            Text(verbatim: value).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1).minimumScaleFactor(0.8).multilineTextAlignment(.center)
            Text(verbatim: label).font(.dorr(10.5)).foregroundStyle(wa.settingsMut).lineLimit(1)
        }
        .padding(.horizontal, 6)
        .padding(.vertical, 10)
        .frame(maxWidth: .infinity)
        .background(wa.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// `Line` — a label and its value on one row.
private struct AgreementLine: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String

    var body: some View {
        HStack {
            Text(verbatim: label).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
            Text(verbatim: value).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
        }
    }
}
