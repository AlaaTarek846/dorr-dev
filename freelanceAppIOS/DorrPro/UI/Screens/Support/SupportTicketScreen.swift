import PhotosUI
import SwiftUI
import UIKit

// freelanceApp `ui/screens/SupportTicketScreen.kt` — Support, the whole flow in one place: my tickets (live), opening a new
// one, and each ticket's conversation (SupportChatScreen.swift), with the quick chat (SupportHelpFlowScreen.swift) before it.

/// Which tickets have a support reply the customer has not looked at yet (kept on the phone).
enum SupportSeen {
    private static func key(_ id: Int) -> String { "dorr_support_seen.t\(id)" }

    static func mark(_ ticketId: Int, _ lastMessageAt: String) {
        UserDefaults.standard.set(lastMessageAt, forKey: key(ticketId))
    }

    static func hasNewReply(_ ticket: SupportTicketDto) -> Bool {
        guard let last = ticket.lastMessage, last.sender == "support" else { return false }
        guard let seen = UserDefaults.standard.string(forKey: key(ticket.id ?? 0)) else { return true }
        return last.createdAt != nil && last.createdAt! > seen
    }
}

/// `cacheImagePart`: a picked photo as JPEG, the multipart part named `image`.
func supportImagePart(_ item: PhotosPickerItem, name: String) async -> FilePart? {
    guard let raw = try? await item.loadTransferable(type: Data.self),
          let jpeg = UIImage(data: raw)?.jpegData(compressionQuality: 0.85) else { return nil }
    return FilePart(field: "image", filename: "\(name).jpg", mimeType: "image/jpeg", data: jpeg)
}

/// Status colours shared by the list and the conversation: opened brand, reopened teal, resolved green, closed red.
func supportStatusColor(_ status: String?, _ wa: WaPalette) -> Color {
    switch status {
    case "resolved": return Color(hex: 0x16A34A)
    case "closed": return Color(hex: 0xDC2626)
    case "reopened": return Color(hex: 0x0891B2)
    default: return wa.red
    }
}

private func supportStatusLabel(_ status: String?) -> String {
    switch status {
    case "opened": return L("support_status_opened")
    case "reopened": return L("support_status_reopened")
    case "resolved": return L("support_status_resolved")
    case "closed": return L("support_status_closed")
    default: return status ?? ""
    }
}

struct SupportStatusChip: View {
    @Environment(\.wa) private var wa
    let status: String?

    var body: some View {
        let color = supportStatusColor(status, wa)
        Text(verbatim: supportStatusLabel(status)).font(.dorr(10, .bold)).foregroundStyle(color)
            .padding(.horizontal, 8).padding(.vertical, 3)
            .background(color.opacity(0.13), in: RoundedRectangle(cornerRadius: 7, style: .continuous))
    }
}

/// The app language's date formatting (Android `AppLocale.java()`; java.time keeps Western digits in Arabic too).
func supportDateFormatter(date: DateFormatter.Style, time: DateFormatter.Style) -> DateFormatter {
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current + "@numbers=latn")
    f.dateStyle = date
    f.timeStyle = time
    return f
}

// MARK: - Tickets

/// Support, the whole flow in one place: my tickets (live), opening a new one, and each ticket's conversation.
/// `openTicketId` jumps straight into a ticket (a tapped push notification).
@MainActor
struct SupportTicketScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    var openTicketId: Int? = nil
    var onTicketOpened: () -> Void = {}
    /// Opens on the quick chat (guided help) instead of the ticket list.
    var startQuickChat = false

    @State private var helping = false
    @State private var creating = false
    @State private var started = false
    /// Where the customer was in the quick chat when they asked for an agent (so back from the form returns there).
    @State private var helpPath: [Int] = []
    @State private var topic = ""
    @State private var chat: SupportTicketDto?
    @State private var tickets: [SupportTicketDto] = []
    @State private var loading = true
    @State private var page = 1
    @State private var hasMore = false
    @State private var loadingMore = false

    var body: some View {
        Group {
            // The quick chat (guided help) is its own entry; a ticket from it is only opened when the customer asks for
            // an agent. "New ticket" in the list goes straight to the form.
            if helping {
                SupportHelpFlowScreen(
                    onBack: { if startQuickChat { onBack() } else { helping = false } },
                    onSolved: {
                        AppToast.show(L("support_help_solved_toast"))
                        if startQuickChat { onBack() } else { helping = false }
                    },
                    onNeedAgent: { picked in
                        topic = picked ?? ""
                        helping = false
                        creating = true
                    },
                    initialPath: helpPath,
                    onPathChange: { helpPath = $0 }
                )
            } else if let current = chat {
                SupportChatScreen(
                    initial: current,
                    // A ticket opened from the quick chat goes back to the Support menu, not to a list never visited.
                    onBack: { if startQuickChat { onBack() } else { chat = nil } },
                    onTicketChanged: upsert
                )
                .id(current.id)
            } else if creating {
                SupportTicketCreateForm(initialTitle: topic, onBack: leaveForm) { message, ticket in
                    creating = false
                    AppToast.show(message)
                    if let ticket {
                        upsert(ticket)
                        chat = ticket
                    } else {
                        Task { await load(1) }
                    }
                }
            } else {
                list
            }
        }
        .onAppear {
            guard !started else { return }
            started = true
            helping = startQuickChat
        }
        .task(id: network.reconnectTick) { await load(1) }
        // A tapped notification: straight into that ticket.
        .task(id: openTicketId) {
            guard let id = openTicketId else { return }
            if let ticket = try? await SupportClient.ticket(id).data {
                upsert(ticket)
                chat = ticket
            }
            onTicketOpened()
        }
        // Live: a reply or a status change from support updates the list on its own.
        .onReceive(Realtime.shared.events) { event in
            guard event.name.hasPrefix("support."), let fresh = supportDecode(event.data["ticket"], as: SupportTicketDto.self) else { return }
            upsert(fresh)
            if event.name == "support.message" && fresh.lastMessage?.sender == "support" && chat?.id != fresh.id {
                AppToast.show(L("support_reply_toast", fresh.displayNumber))
            }
        }
    }

    private var list: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("support_ticket_title"), onBack: onBack)
                if loading && tickets.isEmpty {
                    ScrollView { SkeletonList().padding(.horizontal, 14).padding(.vertical, 8) }
                        .scrollDisabled(true)
                        .frame(maxHeight: .infinity)
                } else if tickets.isEmpty {
                    SupportTicketEmpty { creating = true }
                } else {
                    ScrollView {
                        LazyVStack(spacing: 8) {
                            ForEach(tickets, id: \.id) { ticket in
                                SupportTicketCard(ticket: ticket) { chat = ticket }
                            }
                            if hasMore {
                                Button { Task { await load(page + 1) } } label: {
                                    ZStack {
                                        if loadingMore { ProgressView().tint(wa.red).scaleEffect(0.8) } else {
                                            Text(L("support_ticket_more")).font(.dorr(14, .bold)).foregroundStyle(wa.red)
                                        }
                                    }
                                    .frame(maxWidth: .infinity)
                                    .padding(.vertical, 12)
                                    .contentShape(Rectangle())
                                }
                                .buttonStyle(.plain)
                                .allowsHitTesting(!loadingMore)
                            }
                        }
                        .padding(.horizontal, 14)
                        .padding(.bottom, 16)
                        .animation(.easeInOut(duration: 0.25), value: tickets)
                    }
                    .frame(maxHeight: .infinity)
                    SettingsPrimaryButton(text: L("support_ticket_new")) { creating = true }
                        .padding(.horizontal, 14)
                        .padding(.bottom, 18)
                }
            }
        }
    }

    // Back from the ticket form: into the quick chat it came from (at the same topic), or out when there was none.
    private func leaveForm() {
        // Leaving the screen for good must not flip the state first: the ticket list would flash while it animates out.
        if startQuickChat && topic.trimmingCharacters(in: .whitespaces).isEmpty {
            onBack()
            return
        }
        creating = false
        if startQuickChat { helping = true }
    }

    private func upsert(_ ticket: SupportTicketDto) {
        tickets = [ticket] + tickets.filter { $0.id != ticket.id }
    }

    private func load(_ pageToLoad: Int) async {
        if pageToLoad == 1 { loading = true } else { loadingMore = true }
        if let envelope = try? await SupportClient.listTickets(page: pageToLoad) {
            let rows = envelope.data ?? []
            tickets = pageToLoad == 1 ? rows : tickets + rows.filter { r in !tickets.contains { $0.id == r.id } }
            hasMore = envelope.pagination?.hasMorePages == true
            page = pageToLoad
        }
        loading = false
        loadingMore = false
    }
}

private struct SupportTicketEmpty: View {
    @Environment(\.wa) private var wa
    let onCreate: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            PinkIcon(icon: "ticket.fill", box: 56)
            Text(L("support_ticket_empty")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).padding(.top, 16)
            Text(L("support_ticket_empty_sub")).font(.dorr(14)).lineSpacing(4).foregroundStyle(wa.settingsMut)
                .multilineTextAlignment(.center).padding(.top, 6)
            SettingsPrimaryButton(text: L("support_ticket_new"), action: onCreate).padding(.top, 22)
        }
        .padding(.horizontal, 28)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

private struct SupportTicketCard: View {
    @Environment(\.wa) private var wa
    let ticket: SupportTicketDto
    let onOpen: () -> Void

    var body: some View {
        let color = supportStatusColor(ticket.status, wa)
        let unread = SupportSeen.hasNewReply(ticket)
        let meta = [L("support_ticket_number", ticket.displayNumber), ticketWhen(ticket.lastMessageAt ?? ticket.createdAt)]
            .compactMap { $0 }.joined(separator: "  ·  ")
        Button(action: onOpen) {
            HStack(spacing: 0) {
                // The status colour runs down the card's edge.
                color.frame(width: 4)
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 8) {
                        Text(verbatim: ticket.title ?? "").font(.dorr(14, unread ? .heavy : .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        Spacer(minLength: 0)
                        SupportStatusChip(status: ticket.status)
                    }
                    Text(verbatim: meta).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
                .padding(.horizontal, 12)
                .padding(.vertical, 8)
                if unread {
                    Circle().fill(wa.red).frame(width: 10, height: 10).padding(.trailing, 14)
                }
            }
            .fixedSize(horizontal: false, vertical: true)
            .frame(maxWidth: .infinity)
            .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
            .settingsSurface(14)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    private func ticketWhen(_ iso: String?) -> String? {
        guard let date = parseInstant(iso) else { return nil }
        return supportDateFormatter(date: .medium, time: .short).string(from: date)
    }
}

// MARK: - New ticket

@MainActor
private struct SupportTicketCreateForm: View {
    @Environment(\.wa) private var wa
    let initialTitle: String
    let onBack: () -> Void
    let onSubmitted: (String, SupportTicketDto?) -> Void

    @State private var title = ""
    @State private var bodyText = ""
    @State private var pickerItem: PhotosPickerItem?
    @State private var image: UIImage?
    @State private var sending = false
    @State private var started = false

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("support_ticket_new"), onBack: onBack)
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("support_ticket_intro")).font(.dorr(14)).lineSpacing(4).foregroundStyle(wa.settingsMut).padding(.bottom, 16)
                        DorrTextField(value: Binding(get: { title }, set: { if $0.count <= 120 { title = $0 } }),
                                      label: L("support_ticket_subject"), placeholder: L("support_ticket_subject_hint"), icon: "textformat",
                                      enabled: !sending)
                        DorrTextField(value: Binding(get: { bodyText }, set: { if $0.count <= 4000 { bodyText = $0 } }),
                                      label: L("support_ticket_body"), placeholder: L("support_ticket_body_hint"), icon: "text.alignleft",
                                      singleLine: false, minLines: 5, enabled: !sending, minHeight: 140)
                            .padding(.top, 14)
                        Text(L("support_ticket_photo")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 14).padding(.bottom, 8)
                        if let image {
                            ZStack(alignment: .topTrailing) {
                                Image(uiImage: image).resizable().scaledToFill()
                                    .frame(maxWidth: .infinity).frame(height: 160).clipped()
                                Button {
                                    self.image = nil
                                    pickerItem = nil
                                } label: {
                                    Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(.white)
                                        .frame(width: 28, height: 28)
                                        .background(Color.black.opacity(0.5), in: RoundedRectangle(cornerRadius: 8, style: .continuous))
                                }
                                .buttonStyle(.plain)
                                .padding(8)
                                .allowsHitTesting(!sending)
                            }
                            .frame(height: 160)
                            .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
                        } else {
                            PhotosPicker(selection: $pickerItem, matching: .images) {
                                HStack(spacing: 12) {
                                    Image(systemName: "photo.badge.plus").font(.system(size: 17)).foregroundStyle(wa.red)
                                    Text(L("support_ticket_photo_add")).font(.dorr(14)).foregroundStyle(wa.settingsMut)
                                    Spacer(minLength: 0)
                                }
                                .padding(.horizontal, 14)
                                .padding(.vertical, 12)
                                .background(wa.night ? wa.bg : Color(hex: 0xFBF7F8), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                                .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(wa.night ? wa.line : Color(hex: 0xF3D5DB), lineWidth: 1))
                            }
                            .buttonStyle(.plain)
                            .disabled(sending)
                        }
                        SettingsPrimaryButton(text: L("support_ticket_send"), enabled: !sending, loading: sending, action: submit)
                            .padding(.top, 22)
                    }
                    .padding(.horizontal, 14)
                    .padding(.bottom, 24)
                }
                .scrollDismissesKeyboard(.interactively)
            }
        }
        .onAppear {
            guard !started else { return }
            started = true
            title = initialTitle
        }
        .onChange(of: pickerItem) { item in
            guard let item else { return }
            Task {
                if let raw = try? await item.loadTransferable(type: Data.self) { image = UIImage(data: raw) }
            }
        }
    }

    private func submit() {
        let cleanTitle = title.trimmingCharacters(in: .whitespacesAndNewlines)
        let cleanBody = bodyText.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !cleanTitle.isEmpty, !cleanBody.isEmpty else {
            AppToast.show(L("support_ticket_need_fields"))
            return
        }
        sending = true
        Task {
            do {
                var part: FilePart?
                if let pickerItem { part = await supportImagePart(pickerItem, name: "support-ticket") }
                let response = try await SupportClient.createTicket(title: cleanTitle, body: cleanBody, image: part)
                onSubmitted(response.message.trimmingCharacters(in: .whitespaces).isEmpty ? L("support_ticket_sent") : response.message, response.data)
            } catch {
                AppToast.show(error.apiFailure().message ?? L("support_ticket_failed"), long: true)
            }
            sending = false
        }
    }
}
