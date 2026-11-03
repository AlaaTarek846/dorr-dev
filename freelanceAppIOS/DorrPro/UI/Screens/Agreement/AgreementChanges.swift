// freelanceApp `ui/screens/AgreementChanges.kt`
import SwiftUI

/// Kotlin `num(p[key])`: a number of the change's payload (the decoder may have turned `amount_minor` into `amountMinor`).
private func num(_ p: JSONAny?, _ key: String, _ camel: String? = nil) -> Int {
    (p?[key] ?? camel.flatMap { p?[$0] })?.int ?? 0
}

private struct ChangeLink: View {
    @Environment(\.wa) private var wa
    let text: String
    var color: Color?
    let action: () -> Void

    var body: some View {
        Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(color ?? wa.red)
            .padding(.vertical, 6)
            .contentShape(Rectangle())
            .onTapGesture(perform: action)
    }
}

/// Changes to my active agreement (FL-404 / FL-405): what was asked, the answers, and a short form to ask for one.
struct AgreementChangesCard: View {
    @Environment(\.wa) private var wa
    let changes: [ChangeDto]
    let milestones: [MilestoneDto]
    let currency: String
    let busy: Bool
    let onAsk: (ChangeRequest) -> Void
    let onAnswer: (String, String) -> Void
    @State private var asking = false
    @State private var type = "extend_days"
    @State private var part: String?
    @State private var days = ""
    @State private var amount = ""
    @State private var title = ""
    @State private var reason = ""
    @State private var scope = ""
    @State private var includes = ""
    @State private var excludes = ""

    var body: some View {
        Card {
            FieldLabel(text: L("ch_title"))
            if changes.isEmpty { Text(L("ch_none")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            ForEach(Array(changes.enumerated()), id: \.offset) { _, c in changeRow(c) }
            ActionButton(L("ch_ask"), "square.and.pencil", .primary) { asking = true }
        }
        .formSheet(isPresented: $asking, icon: "square.and.pencil", title: L("ch_ask"), text: L("ch_form_intro")) { form }
    }

    private func changeRow(_ c: ChangeDto) -> some View {
        let p = c.payload
        let headline: String
        switch c.type {
        case "extend_days":
            headline = L("ch_extend", num(p, "days")) + (c.milestone.map { " · \($0.title ?? "")" } ?? "")
        case "milestone_price":
            headline = L("ch_price", c.milestone?.title ?? "", "\u{200E}\(formatMinor(num(p, "from"), nil))", "\u{200E}\(formatMinor(num(p, "to"), nil)) \(currency)")
        case "add_milestone":
            headline = L("ch_add", p?["title"]?.str ?? "", "\u{200E}\(formatMinor(num(p, "amount_minor", "amountMinor"), nil)) \(currency)")
        default:
            headline = L("ch_scope")
        }
        let statusKey: String
        switch c.status {
        case "accepted": statusKey = "ch_s_accepted"
        case "rejected": statusKey = "ch_s_rejected"
        case "withdrawn": statusKey = "ch_s_withdrawn"
        case "expired": statusKey = "ch_s_expired"
        case "cancelled": statusKey = "ch_s_cancelled"
        default: statusKey = "ch_s_pending"
        }
        let uuid = c.uuid ?? ""
        return VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: headline).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
            Text(verbatim: L(c.mine == true ? "ch_by_me" : "ch_by_them") + "  ·  " + L(statusKey)).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            if let r = c.reason { Text(verbatim: r).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            HStack(spacing: 16) {
                if c.can?.accept == true { ChangeLink(text: L("ch_accept")) { onAnswer(uuid, "accept") } }
                if c.can?.reject == true { ChangeLink(text: L("ch_reject"), color: Color(hex: 0xDC2626)) { onAnswer(uuid, "reject") } }
                if c.can?.withdraw == true { ChangeLink(text: L("ch_withdraw")) { onAnswer(uuid, "withdraw") } }
            }
        }
        .padding(10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.red.opacity(0.05), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
    }

    private func pick(_ text: String, _ selected: Bool, _ action: @escaping () -> Void) -> some View {
        AgreementPill(text: text, on: selected, size: 12, horizontal: 12, vertical: 6, action: action)
    }

    @ViewBuilder private var form: some View {
        FlowLayout(spacing: 6) {
            ForEach([("extend_days", "ch_t_extend"), ("scope", "ch_t_scope"), ("milestone_price", "ch_t_price"), ("add_milestone", "ch_t_add")], id: \.0) { t, label in
                pick(L(label), type == t) { type = t; part = nil }
            }
        }
        switch type {
        case "extend_days":
            FlowLayout(spacing: 6) {
                pick(L("ch_whole"), part == nil) { part = nil }
                ForEach(milestones.filter { !["approved", "cancelled", "disputed"].contains($0.status ?? "unfunded") }) { m in
                    pick(m.title ?? "", part == m.uuid) { part = m.uuid }
                }
            }
            DorrTextField(value: agreementLimit($days, 3, digits: true), label: L("ch_days"), icon: "clock", keyboard: .numberPad)
        case "milestone_price":
            let unfunded = milestones.filter { ($0.status ?? "unfunded") == "unfunded" }
            if unfunded.isEmpty { Text(L("ch_no_unfunded")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            FlowLayout(spacing: 6) {
                ForEach(unfunded) { m in pick(m.title ?? "", part == m.uuid) { part = m.uuid } }
            }
            DorrTextField(value: agreementLimit($amount, 12), label: L("ch_new_price", currency), icon: "banknote", keyboard: .decimalPad)
        case "scope":
            Text(L("ch_scope_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            DorrTextField(value: agreementLimit($scope, 8000), label: L("ag_scope"), icon: "text.alignleft", singleLine: false, minLines: 2)
            DorrTextField(value: agreementLimit($includes, 3000), label: L("ag_includes"), icon: "text.alignleft", singleLine: false, minLines: 2)
            DorrTextField(value: agreementLimit($excludes, 3000), label: L("ag_excludes"), icon: "text.alignleft", singleLine: false, minLines: 2)
        default:
            DorrTextField(value: agreementLimit($title, 120), label: L("ch_part_title"), icon: "textformat")
            DorrTextField(value: agreementLimit($amount, 12), label: L("ch_new_price", currency), icon: "banknote", keyboard: .decimalPad)
        }
        DorrTextField(value: agreementLimit($reason, 1000), label: L("ch_reason"), icon: "text.alignleft", singleLine: false, minLines: 2)
        ActionButton(L("ch_send"), "paperplane.fill", .primary, enabled: ready && !busy) { send() }
    }

    private var ready: Bool {
        let minor = parseMinor(amount)
        switch type {
        case "extend_days": return (Int(days) ?? 0) > 0
        case "milestone_price": return part != nil && minor != nil
        case "scope": return !scope.agreementTrimmed.isEmpty || !includes.agreementTrimmed.isEmpty || !excludes.agreementTrimmed.isEmpty
        default: return !title.agreementTrimmed.isEmpty && minor != nil
        }
    }

    private func send() {
        guard ready, !busy else { return }
        let isScope = type == "scope"
        onAsk(ChangeRequest(
            type: type, milestone: part, days: Int(days), amountMinor: parseMinor(amount), title: title.agreementTrimmedOrNil,
            scope: isScope ? scope.agreementTrimmedOrNil : nil, includes: isScope ? includes.agreementTrimmedOrNil : nil,
            excludes: isScope ? excludes.agreementTrimmedOrNil : nil, reason: reason.agreementTrimmedOrNil
        ))
        asking = false
        days = ""; amount = ""; title = ""; reason = ""; scope = ""; includes = ""; excludes = ""
    }
}
