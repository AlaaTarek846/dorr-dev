// freelanceApp `ui/screens/AgreementChanges.kt`
import SwiftUI
import UniformTypeIdentifiers

/// FS-101: at most 3 files of up to 10 MB each go with a change.
private let maxChangeFiles = 3
private let maxChangeFileBytes = 10 * 1024 * 1024

/// Kotlin `changeWhen`: "12/10 14:05" in the phone's own time zone.
private func changeWhen(_ iso: String) -> String {
    guard let date = parseInstant(iso) else { return String(iso.prefix(10)) }
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.timeZone = .current
    f.dateFormat = "d/M HH:mm"
    return f.string(from: date)
}

/// Kotlin `num(p[key])`: a number of the change's payload (the decoder may have turned `amount_minor` into `amountMinor`).
private func num(_ p: JSONAny?, _ key: String, _ camel: String? = nil) -> Int {
    (p?[key] ?? camel.flatMap { p?[$0] })?.int ?? 0
}

/// Changes to my active agreement (FL-404 / FL-405): what was asked, the answers, and a short form to ask for one. FS-101: an accepted
/// change reads as the agreement's addendum N with the version it reached and what it moved; a change carries up to 3 files.
struct AgreementChangesCard: View {
    @Environment(\.wa) private var wa
    let projectUuid: String
    let changes: [ChangeDto]
    let milestones: [MilestoneDto]
    let currency: String
    let busy: Bool
    let onAsk: (ChangeRequest, [AgreementPickedFile]) -> Void
    let onAnswer: (String, String) -> Void
    @State private var openError: String?
    @State private var picks: [AgreementPickedFile] = []
    @State private var pickError: String?
    @State private var picking = false
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
            if let openError { Text(verbatim: openError).font(.dorr(12)).foregroundStyle(Color(hex: 0xDC2626)) }
            ActionButton(L("ch_ask"), "square.and.pencil", .primary) { asking = true }
        }
        .formSheet(isPresented: $asking, icon: "square.and.pencil", title: L("ch_ask"), text: L("ch_form_intro")) {
            form
        } footer: {
            // The send button stays above the keyboard.
            ActionButton(L("ch_send"), "paperplane.fill", .primary, enabled: ready && !busy) { send() }
        }
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
        let green = Color(hex: 0x16A34A)
        let files = c.files ?? []
        return VStack(alignment: .leading, spacing: 0) {
            // FS-101: an accepted change is addendum N of the agreement.
            if let n = c.addendumNo {
                Text(verbatim: L("fs_ch_addendum", n)).font(.dorr(11, .heavy)).foregroundStyle(green)
                    .padding(.horizontal, 10).padding(.vertical, 3)
                    .background(green.opacity(0.12), in: Capsule())
                    .padding(.bottom, 4)
            }
            Text(verbatim: headline).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
            Text(verbatim: L(c.mine == true ? "ch_by_me" : "ch_by_them") + "  ·  " + L(statusKey)).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            if let r = c.reason { Text(verbatim: r).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            // FS-101: the deadline while it waits (it expires by itself after that).
            if c.status == "pending", let by = c.respondBy {
                Text(verbatim: L("fs_ch_respond_by", "\u{200E}" + changeWhen(by))).font(.dorr(12, .bold)).foregroundStyle(Color(hex: 0xD97706)).padding(.top, 2)
            }
            // FS-101: the version the agreement reached and what moved, before → after.
            if c.status == "accepted" {
                if let v = c.appliedVersion { Text(verbatim: L("fs_ch_version", v)).font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut).padding(.top, 2) }
                if let price = c.effectOf("price_minor", "priceMinor") {
                    Text(verbatim: L("fs_ch_effect_price", "\u{200E}\(formatMinor(price.0, nil)) \(currency)", "\u{200E}\(formatMinor(price.1, nil)) \(currency)"))
                        .font(.dorr(12)).foregroundStyle(wa.settingsInk)
                }
                if let d = c.effectOf("duration_days", "durationDays") { Text(verbatim: L("fs_ch_effect_days", d.0, d.1)).font(.dorr(12)).foregroundStyle(wa.settingsInk) }
                if let parts = c.effectOf("milestones") { Text(verbatim: L("fs_ch_effect_parts", parts.0, parts.1)).font(.dorr(12)).foregroundStyle(wa.settingsInk) }
            }
            // FS-101: the files it carries - downloaded with my sign-in and opened.
            if !files.isEmpty {
                FlowLayout(spacing: 6) {
                    ForEach(Array(files.enumerated()), id: \.offset) { _, f in fileChip(f) }
                }
                .padding(.top, 6)
            }
            // Real buttons, side by side: the answer is the important move on this card (a small link was easy to miss).
            HStack(spacing: 10) {
                if c.can?.accept == true { ActionButton(text: L("ch_accept"), icon: nil, tone: .primary) { onAnswer(uuid, "accept") } }
                if c.can?.reject == true { ActionButton(text: L("ch_reject"), icon: nil, tone: .danger) { onAnswer(uuid, "reject") } }
                if c.can?.withdraw == true { ActionButton(text: L("ch_withdraw"), icon: nil, tone: .soft) { onAnswer(uuid, "withdraw") } }
            }
        }
        .padding(10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.red.opacity(0.05), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
    }

    private func fileChip(_ f: DeliveryFileDto) -> some View {
        HStack(spacing: 4) {
            Image(systemName: "paperclip").font(.system(size: 12, weight: .semibold)).foregroundStyle(wa.red)
            Text(verbatim: "\u{200E}\(f.name ?? "")").font(.dorr(12, .semibold)).foregroundStyle(wa.red).lineLimit(1)
        }
        .padding(.horizontal, 10).padding(.vertical, 6)
        .background(wa.red.opacity(0.08), in: Capsule())
        .contentShape(Capsule())
        .onTapGesture {
            Task { openError = await openDeliveryFile(f, base: Api.freelance + "projects/\(projectUuid)/") ? nil : L("ag_dl_open_failed") }
        }
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
        // FS-101: up to 3 files (a design, a sketch, a document) go with the request.
        VStack(alignment: .leading, spacing: 6) {
            ForEach(picks) { f in
                HStack(spacing: 8) {
                    Image(systemName: "paperclip").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red).frame(width: 18, height: 18)
                    Text(verbatim: f.name).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                    Button { picks.removeAll { $0.id == f.id } } label: {
                        Image(systemName: "xmark").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut)
                            .frame(width: 28, height: 28).contentShape(Circle())
                    }
                    .buttonStyle(.plain)
                    .disabled(busy)
                    .accessibilityLabel(L("ag_dl_remove"))
                }
            }
            if picks.count < maxChangeFiles {
                ActionButton(L("fs_ch_attach", picks.count, maxChangeFiles), "paperclip", .soft, enabled: !busy) { picking = true }
            }
            Text(L("fs_ch_attach_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            if let pickError { Text(verbatim: pickError).font(.dorr(12, .bold)).foregroundStyle(Color(hex: 0xDC2626)) }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .fileImporter(isPresented: $picking, allowedContentTypes: [.item]) { result in
            guard case .success(let url) = result, picks.count < maxChangeFiles, let f = AgreementPickedFile.read(url) else { return }
            if f.bytes.count > maxChangeFileBytes { pickError = L("fs_ch_file_too_big") } else { pickError = nil; picks.append(f) }
        }
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
        ), picks)
        asking = false
        days = ""; amount = ""; title = ""; reason = ""; scope = ""; includes = ""; excludes = ""
        picks = []; pickError = nil
    }
}
