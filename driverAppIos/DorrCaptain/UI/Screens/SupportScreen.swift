import PhotosUI
import SwiftUI
import UIKit

// driverApp `ui/SupportScreen.kt` — the captain's support (D-285..D-293) and the riders' lost items (D-294..D-297).
// `SupportPrefill` (a ticket to open from elsewhere) lives in App/Routes.swift.

private enum SupportPage { case home, new, ticket }

/// The captain's support (docs/rides_tasks.md D-285..D-293): whether the team is there now, the help centre with
/// a search, my tickets, a new ticket (category, my last trip, a photo / PDF) and the conversation inside each.
@MainActor
struct SupportScreen: View {
    let onBack: () -> Void
    let onLostItems: () -> Void

    @Environment(\.pal) private var pal
    @ObservedObject private var prefill = SupportPrefill.shared
    @State private var page: SupportPage
    @State private var ticket: SupportTicketDto?
    @State private var help: SupportHelpDto?
    @State private var tickets: [SupportTicketDto]?

    init(onBack: @escaping () -> Void, onLostItems: @escaping () -> Void) {
        self.onBack = onBack
        self.onLostItems = onLostItems
        _page = State(initialValue: SupportPrefill.shared.open != nil ? .new : .home)
    }

    private func reload() async {
        help = await DriverApi.supportHelp(q: nil).value ?? help
        tickets = await DriverApi.supportTickets().value ?? []
    }

    private func back() {
        if page != .home { withAnimation { page = .home } } else { onBack() }
    }

    var body: some View {
        ScreenScaffold(L("sp_title"), onBack: back) {
            ZStack {
                switch page {
                case .home:
                    SupportHome(help: help, tickets: tickets, onNew: { withAnimation { page = .new } },
                                onOpen: { t in ticket = t; withAnimation { page = .ticket } }, onLostItems: onLostItems)
                        .transition(.opacity)
                case .new:
                    NewTicket(help: help, prefill: prefill.open, onDone: { t in
                        SupportPrefill.shared.open = nil
                        ticket = t
                        withAnimation { page = .ticket }
                        Task { await reload() }
                    })
                    .transition(.opacity)
                case .ticket:
                    if let ticket { TicketConversation(initial: ticket).id(ticket.id).transition(.opacity) }
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
        .task { await reload() }
        .onReceive(DriverLive.shared.events) { e in
            if e.name.hasPrefix("support") { Task { await reload() } }
        }
    }
}

private struct SupportHome: View {
    let help: SupportHelpDto?
    let tickets: [SupportTicketDto]?
    let onNew: () -> Void
    let onOpen: (SupportTicketDto) -> Void
    let onLostItems: () -> Void

    @Environment(\.pal) private var pal
    @State private var query = ""
    @State private var results: [HelpNodeDto] = []

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                // D-285: is the team there now.
                if let h = help {
                    let openNow = h.openNow ?? true
                    HStack(spacing: 0) {
                        Circle().fill(openNow ? pal.success : pal.warning).frame(width: 12, height: 12)
                        HSpace(10)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L(openNow ? "sp_open_now" : "sp_closed_now")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                            if let hours = h.hours { Text(hours).font(.dorr(12)).foregroundStyle(pal.mut) }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        DorrIcon("person.wave.2.fill", tint: pal.accent)
                    }
                    .padding(14)
                    .frame(maxWidth: .infinity)
                    .surface(18)
                }
                PrimaryButton(L("sp_new"), icon: "person.wave.2.fill", onClick: onNew)
                HStack(spacing: 0) {
                    DorrIcon("shippingbox.fill", tint: pal.accent)
                    HSpace(10)
                    Text(L("lf_title")).font(.dorr(16, .bold)).foregroundStyle(pal.accent).frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(14)
                .frame(maxWidth: .infinity)
                .background(RoundedRectangle(cornerRadius: 16).fill(pal.accent.opacity(0.08)))
                .contentShape(RoundedRectangle(cornerRadius: 16))
                .onTapGesture(perform: onLostItems)
                // D-286, D-287: the help centre and its search.
                Text(L("sp_help")).font(.dorr(16, .black)).foregroundStyle(pal.ink)
                DorrField(value: query, onValueChange: { query = String($0.prefix(60)) }, placeholder: L("sp_search"))
                let trimmed = query.trimmingCharacters(in: .whitespacesAndNewlines)
                let shown = trimmed.count >= 2 ? results : (help?.items ?? [])
                if shown.isEmpty {
                    Text(L(query.trimmingCharacters(in: .whitespaces).isEmpty ? "sp_no_help" : "sp_no_results"))
                        .font(.dorr(13)).foregroundStyle(pal.mut)
                }
                ForEach(Array(shown.enumerated()), id: \.offset) { _, n in HelpNode(n: n, depth: 0) }
                // My tickets.
                Text(L("sp_tickets")).font(.dorr(16, .black)).foregroundStyle(pal.ink)
                if let tickets {
                    if tickets.isEmpty {
                        Text(L("sp_no_tickets")).font(.dorr(13)).foregroundStyle(pal.mut)
                    } else {
                        ForEach(Array(tickets.enumerated()), id: \.offset) { _, t in TicketRow(t: t) { onOpen(t) } }
                    }
                } else {
                    CircularProgress(color: pal.accent, size: 40, lineWidth: 4)
                }
            }
            .padding(16)
        }
        .task(id: query) {
            let q = query.trimmingCharacters(in: .whitespacesAndNewlines)
            results = q.count < 2 ? [] : (await DriverApi.supportHelp(q: q).value?.results ?? [])
        }
    }
}

private struct HelpNode: View {
    let n: HelpNodeDto
    let depth: Int
    @Environment(\.pal) private var pal
    @State private var open = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                DorrIcon("questionmark.circle", size: 18, tint: pal.accent)
                HSpace(8)
                Text(n.title ?? "").font(.dorr(16, .semibold)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                DorrIcon("chevron.down", tint: pal.mut)
            }
            if open {
                if let answer = n.answer, !answer.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                    Text(answer).font(.dorr(13)).foregroundStyle(pal.mut).padding(.top, 8)
                }
                ForEach(Array((n.children ?? []).enumerated()), id: \.offset) { _, c in HelpNode(n: c, depth: depth + 1) }
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface(14)
        .contentShape(Rectangle())
        .onTapGesture { open.toggle() }
        .padding(.leading, CGFloat(depth * 12))
    }
}

private struct TicketRow: View {
    let t: SupportTicketDto
    let onClick: () -> Void
    @Environment(\.pal) private var pal

    var body: some View {
        let (label, color) = statusLook(t.status ?? "opened", pal)
        HStack(spacing: 0) {
            VStack(alignment: .leading, spacing: 0) {
                Text(t.title ?? "").font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                Text("#\(t.number ?? "") · \(String((t.lastMessage?.body ?? "").prefix(40)))").font(.dorr(12)).foregroundStyle(pal.mut).lineLimit(1)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            Text(L(label)).font(.dorr(11, .bold)).foregroundStyle(color)
                .padding(.horizontal, 10).padding(.vertical, 4)
                .background(Capsule().fill(color.opacity(0.12)))
        }
        .padding(14)
        .frame(maxWidth: .infinity)
        .surface(16)
        .contentShape(Rectangle())
        .onTapGesture(perform: onClick)
    }
}

private func statusLook(_ status: String, _ pal: Pal) -> (String, Color) {
    switch status {
    case "resolved": return ("sp_st_resolved", pal.success)
    case "closed": return ("sp_st_closed", pal.mut)
    case "reopened": return ("sp_st_reopened", pal.warning)
    default: return ("sp_st_opened", pal.accent)
    }
}

/// D-288..D-291: a new ticket — the category, my last trip if it's about it, a photo / PDF.
private struct NewTicket: View {
    let help: SupportHelpDto?
    let prefill: (category: String, reference: String?, text: String)?
    let onDone: (SupportTicketDto) -> Void

    @Environment(\.pal) private var pal
    @State private var category: String
    @State private var title: String
    @State private var body_ = ""
    @State private var aboutLastTrip = false
    @State private var file: PhotosPickerItem?
    @State private var picking = false
    @State private var busy = false
    @State private var error: String?

    init(help: SupportHelpDto?, prefill: (category: String, reference: String?, text: String)?, onDone: @escaping (SupportTicketDto) -> Void) {
        self.help = help
        self.prefill = prefill
        self.onDone = onDone
        _category = State(initialValue: prefill?.category ?? "trip")
        _title = State(initialValue: prefill?.text ?? "")
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(L("sp_category")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(Array((help?.categories ?? []).enumerated()), id: \.offset) { _, c in
                            let on = c.key == category
                            Text(c.label ?? "").font(.dorr(13, .semibold)).foregroundStyle(on ? Color.white : pal.ink)
                                .padding(.horizontal, 14).padding(.vertical, 8)
                                .background(Capsule().fill(on ? pal.accent : pal.card))
                                .overlay(Capsule().stroke(on ? pal.accent : pal.line, lineWidth: 1))
                                .contentShape(Capsule())
                                .onTapGesture { category = c.key ?? "" }
                        }
                    }
                }
                DorrField(value: title, onValueChange: { title = String($0.prefix(150)) }, placeholder: L("sp_subject"))
                DorrField(value: body_, onValueChange: { body_ = String($0.prefix(5000)) }, placeholder: L("sp_body"), singleLine: false, minLines: 4)
                // D-290: about my last trip.
                HStack(spacing: 0) {
                    DorrIcon(aboutLastTrip ? "checkmark.circle.fill" : "questionmark.circle", tint: aboutLastTrip ? pal.accent : pal.mut)
                    HSpace(8)
                    Text(L("sp_about_last_trip")).font(.dorr(13)).foregroundStyle(pal.ink)
                    Spacer(minLength: 0)
                }
                .padding(6)
                .contentShape(RoundedRectangle(cornerRadius: 14))
                .onTapGesture { aboutLastTrip.toggle() }
                // D-291: a photo or a document.
                HStack(spacing: 0) {
                    DorrIcon("paperclip", size: 18, tint: pal.accent)
                    HSpace(6)
                    Text(L(file == nil ? "sp_attach" : "sp_attached")).font(.dorr(13, .bold)).foregroundStyle(pal.accent)
                }
                .padding(.horizontal, 14).padding(.vertical, 8)
                .background(Capsule().fill(pal.accent.opacity(0.1)))
                .contentShape(Capsule())
                .onTapGesture { picking = true }
                Banner(error)
                PrimaryButton(L("sp_send"), enabled: !title.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
                              && body_.trimmingCharacters(in: .whitespacesAndNewlines).count >= 5, busy: busy, onClick: send)
            }
            .padding(16)
        }
        .scrollDismissesKeyboard(.interactively)
        .photosPicker(isPresented: $picking, selection: Binding(get: { file }, set: { if $0 != nil { file = $0 } }), matching: .images)
    }

    private func send() {
        busy = true; error = nil
        Task {
            let rideUuid = aboutLastTrip ? await DriverApi.rideHistory(page: 1).value?.items?.first?.uuid : nil
            var fields: [String: String] = [
                "title": title.trimmingCharacters(in: .whitespacesAndNewlines),
                "body": body_.trimmingCharacters(in: .whitespacesAndNewlines),
                "category": category,
            ]
            if let rideUuid { fields["ride_uuid"] = rideUuid }
            if let reference = prefill?.reference { fields["reference"] = reference }
            var part: FilePart?
            if let file { part = await imagePart(file, "image") }
            switch await DriverApi.openSupportTicket(fields: fields, image: part) {
            case .ok(let t, _): onDone(t)
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }
}

/// D-292, D-293: the conversation inside a ticket — my messages and the team's, a photo, close / reopen.
private struct TicketConversation: View {
    @Environment(\.pal) private var pal
    @State private var ticket: SupportTicketDto
    @State private var messages: [SupportMessageDto] = []
    @State private var text = ""
    @State private var busy = false
    @State private var picking = false
    @State private var picked: PhotosPickerItem?

    init(initial: SupportTicketDto) { _ticket = State(initialValue: initial) }

    private var id: Int { ticket.id ?? 0 }

    private func reload() async {
        messages = await DriverApi.supportMessages(id: id).value ?? []
        if let t = await DriverApi.supportTicket(id: id).value { ticket = t }
    }

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 0) {
                VStack(alignment: .leading, spacing: 0) {
                    Text(ticket.title ?? "").font(.dorr(16, .black)).foregroundStyle(pal.ink)
                    Text("#\(ticket.number ?? "")").font(.dorr(12)).foregroundStyle(pal.mut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                let closed = ticket.status == "closed" || ticket.status == "resolved"
                Text(L(closed ? "sp_reopen" : "sp_close")).font(.dorr(13, .bold)).foregroundStyle(pal.accent)
                    .padding(.horizontal, 10).padding(.vertical, 6)
                    .contentShape(Capsule())
                    .onTapGesture {
                        Task {
                            _ = await DriverApi.setSupportStatus(id: id, body: ["status": closed ? "reopened" : "closed"])
                            await reload()
                        }
                    }
            }
            .padding(.horizontal, 16).padding(.vertical, 8)
            ScrollView {
                VStack(spacing: 8) {
                    ForEach(Array(messages.enumerated()), id: \.offset) { _, m in
                        let mine = (m.sender ?? "user") == "user"
                        HStack(spacing: 0) {
                            if mine { Spacer(minLength: 0) }
                            VStack(alignment: .leading, spacing: 0) {
                                if !mine { Text(m.agentName ?? L("sp_team")).font(.dorr(11, .bold)).foregroundStyle(pal.accent) }
                                if let b = m.body, !b.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                                    Text(b).font(.dorr(14)).foregroundStyle(mine ? Color.white : pal.ink)
                                }
                                if m.imageUrl != nil {
                                    Text("📎 " + L("sp_attached")).font(.dorr(12)).foregroundStyle(mine ? Color.white : pal.accent)
                                }
                                Text(whenText(m.createdAt)).font(.dorr(10)).foregroundStyle((mine ? Color.white : pal.mut).opacity(0.7))
                            }
                            .padding(12)
                            .background(RoundedRectangle(cornerRadius: 16).fill(mine ? pal.accent : pal.card))
                            .frame(maxWidth: 300, alignment: mine ? .trailing : .leading)
                            if !mine { Spacer(minLength: 0) }
                        }
                    }
                }
                .padding(.horizontal, 12)
            }
            .frame(maxHeight: .infinity)
            if ticket.acceptsReplies ?? true {
                HStack(spacing: 0) {
                    DorrIcon("paperclip", tint: pal.mut)
                        .frame(width: 42, height: 42)
                        .background(Circle().fill(pal.card))
                        .contentShape(Circle())
                        .onTapGesture { picking = true }
                    HSpace(8)
                    DorrField(value: text, onValueChange: { text = String($0.prefix(5000)) }, placeholder: L("sp_write"))
                        .frame(maxWidth: .infinity)
                    HSpace(8)
                    let can = !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty && !busy
                    DorrIcon("paperplane.fill", tint: .white)
                        .flipsForRightToLeftLayoutDirection(true)
                        .frame(width: 44, height: 44)
                        .background(Circle().fill(can ? pal.accent : pal.line))
                        .contentShape(Circle())
                        .onTapGesture {
                            guard can else { return }
                            busy = true
                            Task {
                                _ = await DriverApi.sendSupportMessage(id: id, fields: ["body": text.trimmingCharacters(in: .whitespacesAndNewlines)], image: nil)
                                text = ""
                                await reload()
                                busy = false
                            }
                        }
                }
                .padding(10)
            }
        }
        .task(id: ticket.id) { await reload() }
        .onReceive(DriverLive.shared.events) { e in
            if e.name.hasPrefix("support") { Task { await reload() } }
        }
        .photosPicker(isPresented: $picking, selection: $picked, matching: .images)
        .onChange(of: picked) { item in
            guard let item else { return }
            picked = nil
            Task {
                _ = await DriverApi.sendSupportMessage(id: id, fields: [:], image: await imagePart(item, "image"))
                messages = await DriverApi.supportMessages(id: id).value ?? []
            }
        }
    }
}

/// D-294..D-297: riders' things left in my car — answer, agree the return in messages, record it with a photo.
struct LostItemsScreen: View {
    let onBack: () -> Void

    @Environment(\.pal) private var pal
    @State private var items: [LostItemDto]?
    @State private var open: LostItemDto?

    private func reload() async {
        let list = await DriverApi.lostItems().value ?? []
        items = list
        open = open.flatMap { o in list.first { $0.uuid == o.uuid } }
    }

    var body: some View {
        ScreenScaffold(L("lf_title"), onBack: { if open != nil { open = nil } else { onBack() } }) {
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    if let chosen = open {
                        LostItemDetail(i: chosen, onChanged: { Task { await reload() } }).id(chosen.uuid)
                    } else if let list = items {
                        if list.isEmpty {
                            Text(L("lf_none")).font(.dorr(16)).foregroundStyle(pal.mut)
                        } else {
                            ForEach(Array(list.enumerated()), id: \.offset) { _, i in
                                HStack(spacing: 0) {
                                    DorrIcon("shippingbox.fill", tint: pal.accent)
                                    HSpace(10)
                                    VStack(alignment: .leading, spacing: 0) {
                                        Text(i.description ?? "").font(.dorr(16, .bold)).foregroundStyle(pal.ink).lineLimit(1)
                                        Text(L("lf_ride", i.ride?.number ?? "")).font(.dorr(12)).foregroundStyle(pal.mut)
                                    }
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    Text(L(lostStatus(i.status ?? "reported"))).font(.dorr(11, .bold)).foregroundStyle(pal.accent)
                                }
                                .padding(14)
                                .frame(maxWidth: .infinity)
                                .surface(16)
                                .contentShape(Rectangle())
                                .onTapGesture { open = i }
                            }
                        }
                    } else {
                        CircularProgress(color: pal.accent, size: 40, lineWidth: 4)
                    }
                }
                .padding(16)
            }
            .scrollDismissesKeyboard(.interactively)
        }
        .task { await reload() }
        .onReceive(DriverLive.shared.events) { e in
            if e.name.hasPrefix("lost.") { Task { await reload() } }
        }
    }
}

private struct LostItemDetail: View {
    let i: LostItemDto
    let onChanged: () -> Void

    @Environment(\.pal) private var pal
    @State private var plan: String
    @State private var text = ""
    @State private var error: String?
    @State private var picking = false
    @State private var picked: PhotosPickerItem?
    @State private var handled = false

    init(i: LostItemDto, onChanged: @escaping () -> Void) {
        self.i = i
        self.onChanged = onChanged
        _plan = State(initialValue: i.returnPlan ?? "")
    }

    private var uuid: String { i.uuid ?? "" }
    private var status: String { i.status ?? "reported" }

    /// The picker's answer — a photo, or none when it was closed (the return is still recorded, as on Android).
    private func returned(_ item: PhotosPickerItem?) {
        Task {
            let part: FilePart? = item == nil ? nil : await imagePart(item!, "photo")
            switch await DriverApi.lostItemReturned(uuid: uuid, photo: part) {
            case .ok: onChanged()
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
        }
    }

    private func answer(_ found: Bool) {
        Task {
            var body: JsonBody = ["found": found]
            if !plan.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { body["plan"] = plan.trimmingCharacters(in: .whitespacesAndNewlines) }
            switch await DriverApi.answerLostItem(uuid: uuid, body: body) {
            case .ok: onChanged()
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
        }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            VStack(alignment: .leading, spacing: 0) {
                Text(i.description ?? "").font(.dorr(17, .black)).foregroundStyle(pal.ink)
                Text(L("lf_ride", i.ride?.number ?? "") + " · " + L(lostStatus(status))).font(.dorr(12)).foregroundStyle(pal.mut)
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .surface(18)
            Banner(error)
            if ["reported", "found", "not_found"].contains(status) {
                DorrField(value: plan, onValueChange: { plan = String($0.prefix(300)) }, placeholder: L("lf_plan"))
                HStack(spacing: 8) {
                    PrimaryButton(L("lf_found"), color: pal.success) { answer(true) }.frame(maxWidth: .infinity)
                    PrimaryButton(L("lf_not_found"), color: pal.danger) { answer(false) }.frame(maxWidth: .infinity)
                }
            }
            if status == "found" {
                PrimaryButton(L("lf_returned"), icon: "checkmark.circle.fill") { handled = false; picking = true }
            }
            // D-296: messages, never numbers.
            Text(L("lf_messages")).font(.dorr(16, .black)).foregroundStyle(pal.ink)
            ForEach(Array((i.messages ?? []).enumerated()), id: \.offset) { _, m in
                let mine = m.mine ?? false
                HStack(spacing: 0) {
                    if mine { Spacer(minLength: 0) }
                    Text(m.body ?? "").font(.dorr(14)).foregroundStyle(mine ? Color.white : pal.ink)
                        .padding(10)
                        .background(RoundedRectangle(cornerRadius: 14).fill(mine ? pal.accent : pal.card))
                        .frame(maxWidth: 280, alignment: mine ? .trailing : .leading)
                    if !mine { Spacer(minLength: 0) }
                }
            }
            if !["returned", "closed"].contains(status) {
                HStack(spacing: 0) {
                    DorrField(value: text, onValueChange: { text = String($0.prefix(1000)) }, placeholder: L("sp_write")).frame(maxWidth: .infinity)
                    HSpace(8)
                    let can = !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
                    DorrIcon("paperplane.fill", tint: .white)
                        .flipsForRightToLeftLayoutDirection(true)
                        .frame(width: 44, height: 44)
                        .background(Circle().fill(can ? pal.accent : pal.line))
                        .contentShape(Circle())
                        .onTapGesture {
                            guard can else { return }
                            Task {
                                _ = await DriverApi.lostItemMessage(uuid: uuid, body: ["body": text.trimmingCharacters(in: .whitespacesAndNewlines)])
                                text = ""
                                onChanged()
                            }
                        }
                }
            }
        }
        .photosPicker(isPresented: $picking, selection: $picked, matching: .images)
        .onChange(of: picked) { item in
            guard let item, !handled else { return }
            handled = true
            picked = nil
            returned(item)
        }
        .onChange(of: picking) { showing in
            // Closed without a photo: Android's launcher answers with no uri and the return is recorded without one.
            guard !showing else { return }
            Task {
                try? await Task.sleep(nanoseconds: 400_000_000)
                if !handled { handled = true; returned(nil) }
            }
        }
    }
}

private func lostStatus(_ s: String) -> String {
    switch s {
    case "found": return "lf_st_found"
    case "not_found": return "lf_st_not_found"
    case "returned": return "lf_st_returned"
    case "closed": return "lf_st_closed"
    default: return "lf_st_reported"
    }
}

/// A picked photo as one multipart part (`imagePart`): png / webp / jpeg as they are; anything else (HEIC…) as JPEG.
private func imagePart(_ item: PhotosPickerItem, _ name: String) async -> FilePart? {
    guard var data = try? await item.loadTransferable(type: Data.self) else { return nil }
    var type = item.supportedContentTypes.first?.preferredMIMEType ?? "image/jpeg"
    if !(type.contains("png") || type.contains("webp") || type.contains("jpeg") || type.contains("pdf")) {
        if let jpeg = UIImage(data: data)?.jpegData(compressionQuality: 0.9) { data = jpeg }
        type = "image/jpeg"
    }
    let ext = type.contains("png") ? "png" : type.contains("webp") ? "webp" : type.contains("pdf") ? "pdf" : "jpg"
    return FilePart(field: name, filename: "file.\(ext)", mimeType: type, data: data)
}
