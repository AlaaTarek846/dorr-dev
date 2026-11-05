import PhotosUI
import SwiftUI
import UIKit

// androidApp `moving/MovingAfter.kt` — after the move and around it: the establishment's changes and new times (nothing
// is done or charged before a yes), the report with the amount claimed, the inventory photos, the invoice and the
// tickets. Every refusal shows the server's own words.

// MARK: - Changes and new times

/// A value of an applied change's `before` / `after` map.
private func minorOf(_ map: JSONAny?, _ key: String) -> Int? { map?[key]?.int }

private func textOf(_ map: JSONAny?, _ key: String) -> String? { map?[key]?.str }

@MainActor
struct MovingChangesPage: View {
    @Environment(\.wa) private var wa
    let uuid: String
    @ObservedObject var nav: MovingNav
    @State private var changes: [MovingChangeDto]?
    @State private var request: MovingRequestDto?
    @State private var busy: String?
    @State private var error: String?
    @State private var timeOpen = false
    @State private var newTime: Date?
    @State private var reason = ""
    @State private var askReason = false

    private func reload() async {
        // The request first: the cards need its currency and decimals.
        if let fresh = try? await MovingApi.show(uuid).data?.request { request = fresh }
        do {
            changes = try await MovingApi.changes(uuid).data ?? []
        } catch {
            self.error = error.apiFailure.message ?? L("rd_error")
            if changes == nil { changes = [] }
        }
    }

    private func pay(_ change: MovingChangeDto, _ checkoutId: String?) {
        CheckoutLauncher.shared.open(CheckoutRequest(purpose: "moving_change", reference: ["change_uuid": .string(change.uuid)], checkoutId: checkoutId)) {
            Task { await reload() }
        }
    }

    private func respond(_ change: MovingChangeDto, _ approve: Bool) {
        busy = change.uuid
        error = nil
        Task {
            do {
                let envelope = try await MovingApi.respondChange(uuid, change: change.uuid, ["approve": .bool(approve)])
                // A yes that costs more: the difference is paid on the payment screen, and only then counts.
                if let checkout = envelope.data?.checkout {
                    pay(change, checkout.id)
                } else if let message = envelope.message, !message.isEmpty {
                    AppToast.show(message)
                }
                await reload()
            } catch {
                self.error = error.apiFailure.message ?? L("rd_error")
            }
            busy = nil
        }
    }

    var body: some View {
        let status = request?.status
        let canAskTime = status == "confirmed" || status == "assigned"
        let open = (changes ?? []).contains { $0.status == "pending" || $0.status == "awaiting_payment" }
        MvPage(title: L("mv_changes_title"), onBack: { nav.pop() }) {
            if let list = changes {
                MvScroll {
                    MvError(text: error)
                    if canAskTime && !open {
                        MvAction(icon: "calendar", title: L("mv_new_time"), sub: L("mv_new_time_hint")) { timeOpen = true }
                    }
                    if list.isEmpty {
                        MvEmpty(icon: "arrow.triangle.2.circlepath", text: L("mv_changes_empty")).frame(height: 260)
                    }
                    ForEach(Array(list.enumerated()), id: \.element.uuid) { i, change in
                        ChangeCard(change: change, request: request, busy: busy == change.uuid,
                                   onRespond: { respond(change, $0) }, onPay: { pay(change, nil) })
                            .staggerIn(i)
                    }
                }
            } else {
                MvLoading()
            }
        }
        .movingLive(uuid, onChanged: { await reload() })
        .sheet(isPresented: $timeOpen, onDismiss: { if newTime != nil && !askReason { askReason = true } }) {
            MovingTimeSheet(initial: mvTime(request?.scheduledAt), title: L("mv_new_time"), onClose: { timeOpen = false; newTime = nil }) { at in
                newTime = at
                timeOpen = false
            }
            .dorrThemed().presentationDetents([.large])
        }
        .sheet(isPresented: $askReason, onDismiss: { newTime = nil }) {
            MvSheet {
                Text(L("mv_new_time")).font(.dorr(20, .black)).foregroundStyle(wa.settingsInk)
                if let at = newTime { Text(verbatim: rideWhenText(at)).font(.dorr(15, .bold)).foregroundStyle(wa.accent) }
                Text(L("mv_new_time_needs_yes")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut)
                DorrTextArea(value: Binding(get: { reason }, set: { reason = String($0.prefix(500)) }), label: L("mv_new_time_reason"), minHeight: 70)
                MvError(text: error)
                AccentButton(text: L("mv_send"), enabled: !reason.trimmingCharacters(in: .whitespaces).isEmpty && newTime != nil, busy: busy == "time") { reschedule() }
            }
            .dorrThemed().presentationDetents([.medium, .large])
        }
    }

    private func reschedule() {
        guard let at = newTime else { return }
        busy = "time"
        error = nil
        Task {
            let f = ISO8601DateFormatter()
            f.timeZone = .current
            let body: [String: JSONValue] = ["scheduled_at": .string(f.string(from: at)), "reason": .string(reason.trimmingCharacters(in: .whitespacesAndNewlines))]
            do {
                _ = try await MovingApi.reschedule(uuid, body)
                askReason = false
                reason = ""
                await reload()
            } catch {
                self.error = error.apiFailure.message ?? L("rd_error")
            }
            busy = nil
        }
    }
}

private struct ChangeCard: View {
    @Environment(\.wa) private var wa
    let change: MovingChangeDto
    let request: MovingRequestDto?
    let busy: Bool
    let onRespond: (Bool) -> Void
    let onPay: () -> Void

    private var look: (String, Color) {
        switch change.status {
        case "pending": return (L("mv_change_pending"), AppColors.warning)
        case "awaiting_payment": return (L("mv_change_awaiting_payment"), AppColors.warning)
        case "approved": return (L("mv_change_approved"), AppColors.success)
        default: return (L("mv_change_rejected"), AppColors.danger)
        }
    }

    var body: some View {
        let code = request?.currencyCode
        let places = request?.currencyDecimals ?? 2
        let waitingMinutes = change.reason.flatMap { $0.hasPrefix("waiting:") ? String($0.dropFirst("waiting:".count)) : nil }
        let (label, color) = look
        MvCard {
            HStack {
                MvCardTitle(text: L(change.kind == "waiting" ? "mv_change_waiting" : (change.kind == "reschedule" ? "mv_change_time" : "mv_change_price")))
                Spacer(minLength: 0)
                MvChip(text: label, color: color)
            }
            Group {
                if let waitingMinutes {
                    Text(verbatim: L("mv_waiting_minutes", waitingMinutes)).font(.dorr(14)).foregroundStyle(wa.settingsInk)
                } else if let r = change.reason, !r.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: r).font(.dorr(14)).foregroundStyle(wa.settingsInk)
                }
            }
            .padding(.top, 6)
            if change.kind == "reschedule" {
                // Before and after: the time.
                let before = mvTime(textOf(change.before, "scheduled_at")) ?? mvTime(request?.scheduledAt)
                MvLine(label: L("mv_before"), value: before.map { rideWhenText($0) } ?? "—")
                MvLine(label: L("mv_after"), value: mvTime(change.newScheduledAt).map { rideWhenText($0) } ?? "—")
            } else {
                // Before and after: the price (kept with an applied change; worked out from the difference until then).
                let before = minorOf(change.before, "price_minor") ?? request?.money?.priceMinor
                let after = minorOf(change.after, "price_minor") ?? before.map { $0 + change.priceDeltaMinor }
                MvLine(label: L("mv_before"), value: mvMoney(before, code, places))
                MvLine(label: L("mv_after"), value: mvMoney(after, code, places))
                MvLine(label: L("mv_change_difference"), value: mvMoney(change.priceDeltaMinor, code, places))
                if let extra = change.extraTotalMinor, extra > 0 { MvLine(label: L("mv_change_to_pay"), value: mvMoney(extra, code, places), bold: true) }
                if let w = change.workersCount { MvLine(label: L("mv_workers"), value: String(w)) }
                if let t = change.tripsCount { MvLine(label: L("mv_trips"), value: String(t)) }
            }
            if change.status == "pending" && change.proposedBy == "provider" {
                HStack(spacing: 8) {
                    MvOutlineButton(text: L("mv_refuse"), color: AppColors.danger, enabled: !busy) { onRespond(false) }
                    AccentButton(text: L("mv_approve"), busy: busy) { onRespond(true) }
                }
                .padding(.top, 10)
            }
            if change.status == "pending" && change.proposedBy == "user" {
                Text(L("mv_change_wait_establishment")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).padding(.top, 6)
            }
            if change.status == "awaiting_payment" {
                AccentButton(text: L("mv_pay_difference"), action: onPay).padding(.top, 10)
            }
        }
    }
}

// MARK: - The report

/// A picked photo as a `photos[]` part: shrunk so it always fits the server's limit (the events cover way).
private func reportPhotoPart(_ image: UIImage) -> ApiClient.FilePart? {
    let maxSide: CGFloat = 1600
    let longest = max(image.size.width, image.size.height)
    var picture = image
    if longest > maxSide {
        let scale = maxSide / longest
        let size = CGSize(width: (image.size.width * scale).rounded(), height: (image.size.height * scale).rounded())
        let format = UIGraphicsImageRendererFormat.default()
        format.scale = 1
        picture = UIGraphicsImageRenderer(size: size, format: format).image { _ in image.draw(in: CGRect(origin: .zero, size: size)) }
    }
    guard let data = picture.jpegData(compressionQuality: 0.85) else { return nil }
    return ApiClient.FilePart(field: "photos[]", filename: "photo_\(UUID().uuidString.prefix(8)).jpg", mimeType: "image/jpeg", data: data)
}

private struct ReportPhoto: Identifiable {
    let id = UUID()
    let image: UIImage
}

/// A report: why, the photos, and how much is claimed. It says plainly that only that amount stays held.
@MainActor
struct MovingReportPage: View {
    @Environment(\.wa) private var wa
    let uuid: String
    let currency: String?
    let decimals: Int
    @ObservedObject var nav: MovingNav
    @State private var reason: String?
    @State private var details = ""
    @State private var claim = ""
    @State private var photos: [ReportPhoto] = []
    @State private var busy = false
    @State private var error: String?
    @State private var pickerOpen = false
    @State private var items: [PhotosPickerItem] = []

    var body: some View {
        // The amount is typed in the currency; the server takes minor units, with this request's decimals.
        let claimMinor = parseMinor(claim, decimals)
        let ready = reason != nil && (claimMinor ?? 0) > 0 && !photos.isEmpty
        MvPage(title: L("mv_report_title"), onBack: { nav.pop() }, bottom: AnyView(
            VStack(spacing: 8) {
                MvError(text: error).frame(maxWidth: .infinity, alignment: .leading)
                AccentButton(text: L("mv_report_send"), enabled: ready, busy: busy) { send(claimMinor) }
            }
        )) {
            MvScroll(pull: false) {
                MvNote(text: L("mv_report_hold_note"), color: AppColors.warning)
                MvCard {
                    MvCardTitle(text: L("mv_report_reason")).padding(.bottom, 8)
                    FlowLayout(spacing: 8, lineSpacing: 8) {
                        ForEach([("damage", "mv_report_damage"), ("missing_item", "mv_report_missing"),
                                 ("incomplete_service", "mv_report_incomplete"), ("other", "mv_reason_other")], id: \.0) { key, label in
                            MvPill(label: L(label), selected: reason == key) { reason = key }
                        }
                    }
                }
                MvCard {
                    DorrTextArea(value: Binding(get: { claim }, set: { claim = limitAmount($0, decimals) }), label: L("mv_report_claim", currency ?? ""), singleLine: true)
                        .keyboardType(.decimalPad)
                    DorrTextArea(value: Binding(get: { details }, set: { details = String($0.prefix(2000)) }), label: L("mv_report_details"), minHeight: 96)
                        .padding(.top, 10)
                }
                MvCard {
                    MvCardTitle(text: L("mv_report_photos"))
                    Text(L("mv_report_photos_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    FlowLayout(spacing: 8, lineSpacing: 8) {
                        ForEach(photos) { photo in
                            ZStack(alignment: .topTrailing) {
                                Image(uiImage: photo.image).resizable().scaledToFill().frame(width: 92, height: 92).clipped()
                                Button { photos.removeAll { $0.id == photo.id } } label: {
                                    Image(systemName: "xmark").font(.system(size: 11, weight: .bold)).foregroundStyle(.white)
                                        .frame(width: 26, height: 26).background(Color.black.opacity(0.55), in: Circle())
                                }
                                .buttonStyle(.plain)
                                .padding(4)
                            }
                            .frame(width: 92, height: 92)
                            .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
                        }
                        if photos.count < 10 {
                            Button { pickerOpen = true } label: {
                                Image(systemName: "photo.badge.plus").font(.system(size: 26)).foregroundStyle(wa.accent)
                                    .frame(width: 92, height: 92)
                                    .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                                    .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.settingsInk.opacity(0.12), lineWidth: 1.5))
                            }
                            .buttonStyle(WaPressStyle())
                        }
                    }
                    .padding(.top, 10)
                }
            }
        }
        .photosPicker(isPresented: $pickerOpen, selection: $items, maxSelectionCount: max(10 - photos.count, 1), matching: .images)
        .onChange(of: items) { picked in
            guard !picked.isEmpty else { return }
            items = []
            Task {
                for item in picked {
                    guard photos.count < 10, let data = try? await item.loadTransferable(type: Data.self), let image = UIImage(data: data) else { continue }
                    photos.append(ReportPhoto(image: image))
                }
            }
        }
    }

    private func send(_ claimMinor: Int?) {
        guard let why = reason, let amount = claimMinor else { return }
        busy = true
        error = nil
        Task {
            var fields = ["reason": why, "claim_minor": String(amount)]
            let text = details.trimmingCharacters(in: .whitespacesAndNewlines)
            if !text.isEmpty { fields["details"] = text }
            let parts = photos.compactMap { reportPhotoPart($0.image) }
            if parts.isEmpty {
                error = L("rd_error")
            } else {
                do {
                    let envelope = try await MovingApi.report(uuid, fields: fields, photos: parts)
                    if let message = envelope.message, !message.isEmpty { AppToast.show(message, long: true) }
                    nav.pop()
                } catch {
                    self.error = error.apiFailure.message ?? L("rd_error")
                }
            }
            busy = false
        }
    }
}

// MARK: - The inventory

/// The numbered pieces with their photo before loading and after delivery.
@MainActor
struct MovingInventoryPage: View {
    @Environment(\.wa) private var wa
    let items: [MovingInventoryItemDto]
    @ObservedObject var nav: MovingNav

    var body: some View {
        MvPage(title: L("mv_inventory_title"), onBack: { nav.pop() }) {
            if items.isEmpty {
                MvEmpty(icon: "shippingbox.fill", text: L("mv_inventory_empty"))
            } else {
                MvScroll(pull: false) {
                    ForEach(Array(items.enumerated()), id: \.element.uuid) { i, item in
                        MvCard {
                            HStack(spacing: 10) {
                                Text(verbatim: item.number.map(String.init) ?? "").font(.dorr(13, .bold)).foregroundStyle(wa.accent)
                                    .frame(width: 32, height: 32).background(wa.accent.opacity(0.12), in: Circle())
                                Text(verbatim: item.label ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                                Spacer(minLength: 0)
                                if let trip = item.tripNo { MvChip(text: L("mv_trip_no", trip), color: wa.settingsMut) }
                            }
                            HStack(alignment: .top, spacing: 8) {
                                InventoryPhoto(title: L("mv_before"), url: item.before, note: item.conditionNote)
                                InventoryPhoto(title: L("mv_after"), url: item.after, note: item.deliveredNote)
                            }
                            .padding(.top, 10)
                        }
                        .staggerIn(i)
                    }
                }
            }
        }
    }
}

private struct InventoryPhoto: View {
    @Environment(\.wa) private var wa
    let title: String
    let url: String?
    let note: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(verbatim: title).font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut)
            Color.clear
                .aspectRatio(1, contentMode: .fit)
                .overlay {
                    if url != nil {
                        RemoteImage(url: url, placeholder: "photo")
                    } else {
                        Text(verbatim: "—").foregroundStyle(wa.settingsMut)
                    }
                }
                .background(wa.settingsBackground)
                .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
            if let note, !note.trimmingCharacters(in: .whitespaces).isEmpty {
                Text(verbatim: note).font(.dorr(12)).foregroundStyle(wa.settingsInk)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

// MARK: - The invoice

@MainActor
struct MovingInvoicePage: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var network = NetworkMonitor.shared
    let uuid: String
    let decimals: Int
    @ObservedObject var nav: MovingNav
    @State private var invoice: MovingInvoiceDto?
    @State private var error: String?

    var body: some View {
        MvPage(title: L("mv_invoice_title"), onBack: { nav.pop() }) {
            if let i = invoice {
                MvScroll {
                    MvCard {
                        if let n = i.number { MvLine(label: L("mv_invoice_number"), value: n) }
                        if let at = mvTime(i.issuedAt) { MvLine(label: L("mv_invoice_date"), value: rideWhenText(at)) }
                        if let name = i.establishment?.name { MvLine(label: L("mv_invoice_establishment"), value: name) }
                        if let tax = i.establishment?.taxNumber, !tax.trimmingCharacters(in: .whitespaces).isEmpty { MvLine(label: L("mv_invoice_tax_number"), value: tax) }
                        if let w = i.workersCount { MvLine(label: L("mv_workers"), value: String(w)) }
                        if let t = i.tripsCount { MvLine(label: L("mv_trips"), value: String(t)) }
                    }
                    let included = includedKeys(i.includes)
                    if !included.isEmpty {
                        MvCard {
                            MvCardTitle(text: L("mv_included")).padding(.bottom, 6)
                            FlowLayout(spacing: 6, lineSpacing: 6) { ForEach(included, id: \.self) { MvChip(text: extraLabel($0), color: wa.accent) } }
                        }
                    }
                    MvCard {
                        ForEach(Array(i.lines.enumerated()), id: \.offset) { _, line in
                            MvLine(label: lineLabel(line), value: mvMoney(line.amountMinor, i.currencyCode, decimals))
                        }
                        MvLine(label: L("mv_price"), value: mvMoney(i.priceMinor, i.currencyCode, decimals))
                        MvLine(label: L("mv_tax"), value: mvMoney(i.taxMinor, i.currencyCode, decimals))
                        MvLine(label: L("mv_total"), value: mvMoney(i.totalMinor, i.currencyCode, decimals), bold: true)
                        if i.refundedMinor > 0 {
                            MvLine(label: L("mv_invoice_refunded"), value: mvMoney(i.refundedMinor, i.currencyCode, decimals), valueColor: AppColors.success)
                        }
                    }
                }
            } else if let error {
                MvEmpty(icon: "doc.plaintext.fill", text: error)
            } else {
                MvLoading()
            }
        }
        .task(id: "\(uuid)|\(network.reconnectTick)") {
            do {
                invoice = try await MovingApi.invoice(uuid).data
                error = nil
            } catch {
                self.error = error.apiFailure.message ?? L("rd_error")
            }
        }
    }

    private func lineLabel(_ line: MovingInvoiceLineDto) -> String {
        let minutes = line.reason.flatMap { $0.hasPrefix("waiting:") ? String($0.dropFirst("waiting:".count)) : nil }
        switch line.key {
        case "waiting": return minutes.map { L("mv_invoice_waiting_minutes", $0) } ?? L("mv_change_waiting")
        case "change":
            if let r = line.reason, !r.trimmingCharacters(in: .whitespaces).isEmpty { return r }
            return L("mv_change_price")
        default: return L("mv_invoice_moving")
        }
    }
}

// MARK: - Tickets

private func ticketTypeLabel(_ type: String?) -> String {
    switch type {
    case "suggestion": return L("mv_ticket_suggestion")
    case "inquiry": return L("mv_ticket_inquiry")
    case "complaint": return L("mv_ticket_complaint")
    case "safety": return L("mv_ticket_safety")
    case "inventory": return L("mv_ticket_inventory")
    case "delay": return L("mv_ticket_delay")
    case "financial": return L("mv_ticket_financial")
    default: return L("mv_ticket_note")
    }
}

private func ticketStatusLook(_ status: String?, _ wa: WaPalette) -> (String, Color) {
    switch status {
    case "awaiting_company": return (L("mv_ticket_st_awaiting_company"), AppColors.warning)
    case "company_replied": return (L("mv_ticket_st_company_replied"), wa.accent)
    case "escalated": return (L("mv_ticket_st_escalated"), AppColors.brandNavy)
    case "awaiting_customer": return (L("mv_ticket_st_awaiting_customer"), AppColors.warning)
    case "resolved": return (L("mv_ticket_st_resolved"), AppColors.success)
    default: return (L("mv_ticket_st_unresolved"), AppColors.danger)
    }
}

@MainActor
struct MovingTicketsPage: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    @ObservedObject private var network = NetworkMonitor.shared
    let uuid: String
    @ObservedObject var nav: MovingNav
    @State private var tickets: [MovingTicketDto]?
    @State private var error: String?

    var body: some View {
        MvPage(title: L("mv_tickets_title"), onBack: { nav.pop() },
               trailing: AnyView(RideRoundButton(icon: "plus") { nav.push(.newTicket(uuid)) })) {
            if let list = tickets {
                MvScroll {
                    MvNote(text: L("mv_tickets_hint"))
                    MvError(text: error)
                    if list.isEmpty {
                        MvEmpty(icon: "headphones", text: L("mv_tickets_empty")).frame(height: 260)
                    }
                    ForEach(Array(list.enumerated()), id: \.element.uuid) { i, t in
                        let (label, color) = ticketStatusLook(t.status, wa)
                        Button { nav.push(.ticket(uuid, ticket: t.uuid)) } label: {
                            HStack(spacing: 8) {
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(verbatim: t.subject ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                                    Text(verbatim: ticketTypeLabel(t.type)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                                }
                                Spacer(minLength: 0)
                                MvChip(text: label, color: color)
                            }
                            .padding(14)
                            .background(wa.mvCard(onPanel), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                        }
                        .buttonStyle(WaPressStyle())
                        .staggerIn(i)
                    }
                }
            } else {
                MvLoading()
            }
        }
        .task(id: "\(uuid)|\(network.reconnectTick)") {
            do {
                tickets = try await MovingApi.tickets(uuid).data ?? []
                error = nil
            } catch {
                self.error = error.apiFailure.message ?? L("rd_error")
                if tickets == nil { tickets = [] }
            }
        }
    }
}

@MainActor
struct MovingNewTicketPage: View {
    @Environment(\.wa) private var wa
    let uuid: String
    @ObservedObject var nav: MovingNav
    @State private var type: String?
    @State private var subject = ""
    @State private var text = ""
    @State private var busy = false
    @State private var error: String?

    private let types = ["complaint", "inquiry", "delay", "inventory", "financial", "safety", "suggestion", "note"]

    var body: some View {
        let ready = type != nil && !subject.trimmingCharacters(in: .whitespaces).isEmpty && !text.trimmingCharacters(in: .whitespaces).isEmpty
        MvPage(title: L("mv_ticket_new"), onBack: { nav.pop() }, bottom: AnyView(
            VStack(spacing: 8) {
                MvError(text: error).frame(maxWidth: .infinity, alignment: .leading)
                AccentButton(text: L("mv_send"), enabled: ready, busy: busy) { send() }
            }
        )) {
            MvScroll(pull: false) {
                MvNote(text: L("mv_tickets_hint"))
                MvCard {
                    MvCardTitle(text: L("mv_ticket_type")).padding(.bottom, 8)
                    FlowLayout(spacing: 8, lineSpacing: 8) {
                        ForEach(types, id: \.self) { key in MvPill(label: ticketTypeLabel(key), selected: type == key) { type = key } }
                    }
                }
                MvCard {
                    DorrTextArea(value: Binding(get: { subject }, set: { subject = String($0.prefix(200)) }), label: L("mv_ticket_subject"), singleLine: true)
                    DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(3000)) }), label: L("mv_ticket_body"), minHeight: 110)
                        .padding(.top, 10)
                }
            }
        }
    }

    private func send() {
        guard let kind = type else { return }
        busy = true
        error = nil
        Task {
            let body: [String: JSONValue] = [
                "type": .string(kind),
                "subject": .string(subject.trimmingCharacters(in: .whitespacesAndNewlines)),
                "body": .string(text.trimmingCharacters(in: .whitespacesAndNewlines)),
            ]
            do {
                if let ticket = try await MovingApi.openTicket(uuid, body).data { nav.replace(.ticket(uuid, ticket: ticket.uuid)) } else { nav.pop() }
            } catch {
                self.error = error.apiFailure.message ?? L("rd_error")
            }
            busy = false
        }
    }
}

@MainActor
struct MovingTicketPage: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var network = NetworkMonitor.shared
    let ticketUuid: String
    @ObservedObject var nav: MovingNav
    @State private var ticket: MovingTicketDto?
    @State private var text = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        MvPage(title: ticket?.subject ?? L("mv_tickets_title"), onBack: { nav.pop() }) {
            if let t = ticket {
                MvScroll { content(t) }
            } else if let error {
                MvEmpty(icon: "headphones", text: error)
            } else {
                MvLoading()
            }
        }
        .task(id: "\(ticketUuid)|\(network.reconnectTick)") {
            do {
                ticket = try await MovingApi.ticket(ticketUuid).data
                error = nil
            } catch {
                self.error = error.apiFailure.message ?? L("rd_error")
            }
        }
    }

    @ViewBuilder
    private func content(_ t: MovingTicketDto) -> some View {
        let closed = t.status == "resolved" || t.status == "unresolved"
        let (label, color) = ticketStatusLook(t.status, wa)
        MvCard {
            HStack {
                Text(verbatim: ticketTypeLabel(t.type)).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                Spacer(minLength: 0)
                MvChip(text: label, color: color)
            }
            if let r = t.closingReason, !r.trimmingCharacters(in: .whitespaces).isEmpty {
                Text(verbatim: r).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).padding(.top, 6)
            }
            if let r = t.escalationReason, !r.trimmingCharacters(in: .whitespaces).isEmpty {
                Text(verbatim: L("mv_ticket_escalation_reason", r)).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).padding(.top, 6)
            }
        }
        MvNote(text: L("mv_tickets_hint"))
        MvCard {
            MvCardTitle(text: L("rd_cases_conversation"))
            ForEach(Array(t.messages.enumerated()), id: \.offset) { _, m in
                let mine = m.author == "user"
                VStack(alignment: mine ? .trailing : .leading, spacing: 2) {
                    Text(verbatim: m.body ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk)
                        .padding(.horizontal, 12).padding(.vertical, 8)
                        .background(mine ? wa.accent.opacity(0.12) : wa.settingsBackground, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    if !mine { Text(L(m.author == "admin" ? "mv_ticket_from_dorr" : "mv_ticket_from_company")).font(.dorr(10)).foregroundStyle(wa.settingsMut) }
                }
                .frame(maxWidth: .infinity, alignment: mine ? .trailing : .leading)
                .padding(.top, 8)
            }
        }
        if !closed {
            MvCard {
                DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(3000)) }), label: L("mv_ticket_write"), minHeight: 70)
                AccentButton(text: L("mv_ticket_reply"), enabled: !text.trimmingCharacters(in: .whitespaces).isEmpty, busy: busy) { act("reply") }
                    .padding(.top, 10)
                HStack(spacing: 8) {
                    MvOutlineButton(text: L("mv_ticket_resolve"), color: AppColors.success, enabled: !busy) { act("resolve") }
                    if t.status != "escalated" {
                        MvOutlineButton(text: L("mv_ticket_escalate"), color: AppColors.warning, enabled: !busy) { act("escalate") }
                    }
                }
                .padding(.top, 8)
                Text(L("mv_ticket_escalate_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 6)
            }
        }
        MvError(text: error)
    }

    private func act(_ action: String) {
        busy = true
        error = nil
        Task {
            var body: [String: JSONValue] = ["action": .string(action)]
            let typed = text.trimmingCharacters(in: .whitespacesAndNewlines)
            if !typed.isEmpty { body["body"] = .string(typed) }
            do {
                if let fresh = try await MovingApi.actOnTicket(ticketUuid, body).data { ticket = fresh }
                text = ""
            } catch {
                self.error = error.apiFailure.message ?? L("rd_error")
            }
            busy = false
        }
    }
}
