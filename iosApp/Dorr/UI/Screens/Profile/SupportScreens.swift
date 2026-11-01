import PhotosUI
import SwiftUI
import UIKit

// androidApp `profile/SupportTicketScreen.kt`, `SupportChatScreen.kt`, `SupportHelpFlowScreen.kt`.
//
// Live updates (support replies arriving over Pusher while the screen is open) wait for the realtime client
// (section 7 of the port); until then the list and the conversation reload on open and on every reconnect.

/// Which tickets have a support reply the customer has not looked at yet (kept on the phone).
enum SupportSeen {
    private static func key(_ id: Int) -> String { "dorr_support_seen.t\(id)" }

    static func mark(_ ticketId: Int, _ lastMessageAt: String) {
        UserDefaults.standard.set(lastMessageAt, forKey: key(ticketId))
    }

    static func hasNewReply(_ ticket: SupportTicketDto) -> Bool {
        guard let last = ticket.lastMessage, last.sender == "support" else { return false }
        guard let seen = UserDefaults.standard.string(forKey: key(ticket.id)) else { return true }
        return (last.createdAt ?? "") > seen
    }
}

/// A picked photo as JPEG bytes (the multipart part named `image`).
private func jpegData(_ item: PhotosPickerItem) async -> Data? {
    guard let raw = try? await item.loadTransferable(type: Data.self) else { return nil }
    return UIImage(data: raw)?.jpegData(compressionQuality: 0.85)
}

/// Status colours shared by the list and the conversation: opened brand, reopened teal, resolved green, closed red.
private func supportStatusColor(_ status: String?, _ wa: WaPalette) -> Color {
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

/// The app language's date formatting (Android `AppLocale.java()`).
private func appLocaleFormatter(date: DateFormatter.Style, time: DateFormatter.Style) -> DateFormatter {
    let f = DateFormatter()
    // java.time keeps Western digits in Arabic too ("28/10/2026 4:32 م"), so the same here.
    f.locale = Locale(identifier: AppLocale.current + "@numbers=latn")
    f.dateStyle = date
    f.timeStyle = time
    return f
}

// MARK: - Tickets

/// Support, the whole flow in one place: my tickets, opening a new one, and each ticket's conversation.
/// [openTicketId] jumps straight into a ticket (a tapped notification).
@MainActor
struct SupportTicketScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    /// Opens on the quick chat (guided help) instead of the ticket list.
    var startQuickChat = false
    var openTicketId: Int?
    var onTicketOpened: () -> Void = {}

    @State private var helping = false
    @State private var creating = false
    @State private var started = false
    /// Where the customer was in the quick chat when they asked for an agent (back from the form returns there).
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
        // Live: a reply or a status change from support updates the list on its own.
        .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { event in
            guard event.name.hasPrefix("support."), let fresh = event.data["ticket"]?.plain(SupportTicketDto.self) else { return }
            upsert(fresh)
            if event.name == "support.message" && fresh.lastMessage?.sender == "support" && chat?.id != fresh.id {
                AppToast.show(L("support_reply_toast", fresh.displayNumber))
            }
        }
        // A tapped notification: straight into that ticket.
        .task(id: openTicketId) {
            guard let id = openTicketId else { return }
            if let ticket = try? await SupportApi.ticket(id).data {
                upsert(ticket)
                chat = ticket
            }
            onTicketOpened()
        }
    }

    private var list: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("support_ticket_title"), onBack: onBack)
                if loading && tickets.isEmpty {
                    ProgressView().tint(wa.red).frame(maxWidth: .infinity, maxHeight: .infinity)
                } else if tickets.isEmpty {
                    SupportTicketEmpty { creating = true }
                } else {
                    ScrollView {
                        LazyVStack(spacing: 8) {
                            ForEach(tickets) { ticket in
                                SupportTicketCard(ticket: ticket) { chat = ticket }
                            }
                            if hasMore {
                                Button { Task { await load(page + 1) } } label: {
                                    ZStack {
                                        if loadingMore { ProgressView().tint(wa.red) } else {
                                            Text(L("support_ticket_more")).font(.dorr(14, .bold)).foregroundStyle(wa.red)
                                        }
                                    }
                                    .frame(maxWidth: .infinity)
                                    .padding(.vertical, 12)
                                }
                                .buttonStyle(.plain)
                                .allowsHitTesting(!loadingMore)
                            }
                        }
                        .padding(.horizontal, 14)
                        .padding(.bottom, 16)
                        .animation(.easeInOut(duration: 0.25), value: tickets)
                    }
                    SettingsPrimaryButton(text: L("support_ticket_new")) { creating = true }
                        .padding(.horizontal, 14)
                        .padding(.bottom, 18)
                }
            }
        }
    }

    // Back from the ticket form: into the quick chat it came from (at the same topic), or out when there was none.
    private func leaveForm() {
        // Leaving the screen for good must not flip the state first: the ticket list would flash on the way out.
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
        if let envelope = try? await SupportApi.listTickets(page: pageToLoad) {
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
        }
        .buttonStyle(.plain)
    }

    private func ticketWhen(_ iso: String?) -> String? {
        guard let date = parseInstant(iso) else { return nil }
        return appLocaleFormatter(date: .medium, time: .short).string(from: date)
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
    @State private var image: UIImage?
    @State private var imageData: Data?
    @State private var pickerItem: PhotosPickerItem?
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
                        DorrTextArea(value: Binding(get: { title }, set: { if $0.count <= 120 { title = $0 } }),
                                     label: L("support_ticket_subject"), placeholder: L("support_ticket_subject_hint"), icon: "textformat",
                                     singleLine: true, enabled: !sending)
                        DorrTextArea(value: Binding(get: { bodyText }, set: { if $0.count <= 4000 { bodyText = $0 } }),
                                     label: L("support_ticket_body"), placeholder: L("support_ticket_body_hint"), icon: "text.alignleft",
                                     minHeight: 140, enabled: !sending)
                            .padding(.top, 14)
                        Text(L("support_ticket_photo")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 14).padding(.bottom, 8)
                        if let image {
                            ZStack(alignment: .topTrailing) {
                                Image(uiImage: image).resizable().scaledToFill()
                                    .frame(maxWidth: .infinity).frame(height: 160).clipped()
                                Button { self.image = nil; imageData = nil } label: {
                                    Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(.white)
                                        .frame(width: 28, height: 28)
                                        .background(Color.black.opacity(0.5), in: RoundedRectangle(cornerRadius: 8, style: .continuous))
                                }
                                .buttonStyle(.plain)
                                .padding(8)
                                .allowsHitTesting(!sending)
                            }
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
            pickerItem = nil
            Task {
                guard let data = await jpegData(item) else { return }
                imageData = data
                image = UIImage(data: data)
            }
        }
    }

    private func submit() {
        let cleanTitle = title.trimmingCharacters(in: .whitespacesAndNewlines)
        let cleanBody = bodyText.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !cleanTitle.isEmpty, !cleanBody.isEmpty else { AppToast.show(L("support_ticket_need_fields")); return }
        sending = true
        Task {
            do {
                let response = try await SupportApi.createTicket(title: cleanTitle, body: cleanBody, image: imageData)
                onSubmitted((response.message ?? "").isEmpty ? L("support_ticket_sent") : response.message!, response.data)
            } catch {
                AppToast.show(error.apiFailure.message ?? L("support_ticket_failed"), long: true)
            }
            sending = false
        }
    }
}

// MARK: - Conversation

private enum ConversationRow: Identifiable, Equatable {
    case day(String)
    case msg(SupportMessageDto)

    var id: String {
        switch self {
        case .day(let label): return "d-\(label)"
        case .msg(let message): return "m-\(message.id)"
        }
    }
}

private func conversationRows(_ messages: [SupportMessageDto]) -> [ConversationRow] {
    var out: [ConversationRow] = []
    var lastDay: String?
    let formatter = appLocaleFormatter(date: .medium, time: .none)
    for message in messages {
        if let date = parseInstant(message.createdAt) {
            let day = formatter.string(from: date)
            if day != lastDay {
                out.append(.day(day))
                lastDay = day
            }
        }
        out.append(.msg(message))
    }
    return out
}

/// One support ticket as a conversation: the customer's messages on one side, the team's on the other, the
/// status at the top, and the action that fits it — close it, or reopen it when it is finished.
@MainActor
struct SupportChatScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var router: AppRouter
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onTicketChanged: (SupportTicketDto) -> Void

    @State private var ticket: SupportTicketDto
    @State private var messages: [SupportMessageDto] = []
    @State private var loading = true
    @State private var nextPage = 2
    @State private var hasEarlier = false
    @State private var loadingEarlier = false
    @State private var draft = ""
    @State private var image: UIImage?
    @State private var imageData: Data?
    @State private var pickerItem: PhotosPickerItem?
    @State private var sending = false
    @State private var statusBusy = false
    @State private var confirmClose = false

    init(initial: SupportTicketDto, onBack: @escaping () -> Void, onTicketChanged: @escaping (SupportTicketDto) -> Void = { _ in }) {
        self.onBack = onBack
        self.onTicketChanged = onTicketChanged
        _ticket = State(initialValue: initial)
    }

    var body: some View {
        let rows = conversationRows(messages)
        // The feedback buttons belong to the last message only when it is an automatic FAQ answer.
        let lastFaqId = messages.last.flatMap { $0.isAuto && $0.autoKind == "faq" ? $0.id : nil }
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                TicketStatusBar(ticket: ticket, busy: statusBusy, onBack: onBack, onClose: { confirmClose = true }, onReopen: { setStatus("reopened") })
                if loading {
                    ProgressView().tint(wa.red).frame(maxWidth: .infinity, maxHeight: .infinity)
                } else if rows.isEmpty {
                    VStack(spacing: 14) {
                        PinkIcon(icon: "headphones", box: 56)
                        Text(L("support_conv_empty")).font(.dorr(14)).lineSpacing(4).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
                    }
                    .padding(.horizontal, 28)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else {
                    ScrollViewReader { proxy in
                        ScrollView {
                            LazyVStack(spacing: 6) {
                                if hasEarlier {
                                    Group {
                                        if loadingEarlier {
                                            ProgressView().tint(wa.red)
                                        } else {
                                            Button(action: loadEarlier) {
                                                Text(L("support_load_earlier")).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                                                    .padding(.horizontal, 12).padding(.vertical, 6)
                                            }
                                            .buttonStyle(.plain)
                                        }
                                    }
                                    .frame(maxWidth: .infinity)
                                    .padding(.bottom, 6)
                                }
                                ForEach(rows) { row in
                                    switch row {
                                    case .day(let label): DayChip(label: label)
                                    case .msg(let message):
                                        SupportBubble(message: message)
                                        // Under the newest FAQ answer: did it solve the problem?
                                        if message.id == lastFaqId && ticket.acceptsReplies && !ticket.autoReplyStopped {
                                            HStack(spacing: 8) {
                                                TextAction(label: L("support_auto_solved"), icon: "lock.fill") { if !statusBusy { autoFeedback(true) } }
                                                TextAction(label: L("support_auto_need_agent"), icon: "headphones") { if !statusBusy { autoFeedback(false) } }
                                                Spacer(minLength: 0)
                                            }
                                            .padding(.top, 2)
                                        }
                                    }
                                }
                                Color.clear.frame(height: 1).id("bottom")
                            }
                            .padding(.horizontal, 14)
                            .padding(.vertical, 8)
                        }
                        .scrollDismissesKeyboard(.interactively)
                        .onAppear { proxy.scrollTo("bottom", anchor: .bottom) }
                        .onChange(of: rows.count) { _ in
                            withAnimation(.easeOut(duration: 0.25)) { proxy.scrollTo("bottom", anchor: .bottom) }
                            // Everything on screen counts as read.
                            if let at = messages.last?.createdAt { SupportSeen.mark(ticket.id, at) }
                        }
                    }
                }
                Composer(ticket: ticket, draft: $draft, image: image, pickerItem: $pickerItem, onClearImage: { image = nil; imageData = nil },
                         sending: sending, reopenBusy: statusBusy, onSend: send, onReopen: { setStatus("reopened") })
            }
        }
        .onAppear { router.supportChatOpen = true }
        .onDisappear { router.supportChatOpen = false }
        .task(id: "\(network.reconnectTick)-\(ticket.id)") { await load() }
        // Live: support's replies and status changes land here the moment they happen.
        .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { event in
            guard event.name.hasPrefix("support."), let fresh = event.data["ticket"]?.plain(SupportTicketDto.self), fresh.id == ticket.id else { return }
            update(fresh)
            if event.name == "support.message", let message = event.data["message"]?.plain(SupportMessageDto.self) { add(message) }
        }
        .onChange(of: pickerItem) { item in
            guard let item else { return }
            pickerItem = nil
            Task {
                guard let data = await jpegData(item) else { return }
                imageData = data
                image = UIImage(data: data)
            }
        }
        .dorrDialog(isPresented: Binding(get: { confirmClose }, set: { if !$0 && !statusBusy { confirmClose = false } })) {
            ConfirmDialog(icon: "lock.fill", title: L("support_close_title"), message: L("support_close_message"), loading: statusBusy,
                          onConfirm: { setStatus("closed") }, onDismiss: { if !statusBusy { confirmClose = false } })
        }
    }

    private func update(_ fresh: SupportTicketDto) {
        ticket = fresh
        onTicketChanged(fresh)
    }

    private func add(_ message: SupportMessageDto) {
        if !messages.contains(where: { $0.id == message.id }) { messages.append(message) }
    }

    private func load() async {
        if let envelope = try? await SupportApi.messages(ticket.id, page: 1) {
            messages = (envelope.data ?? []).reversed()
            hasEarlier = envelope.pagination?.hasMorePages == true
            nextPage = 2
            if let at = messages.last?.createdAt { SupportSeen.mark(ticket.id, at) }
        }
        loading = false
    }

    private func loadEarlier() {
        guard !loadingEarlier else { return }
        loadingEarlier = true
        Task {
            if let envelope = try? await SupportApi.messages(ticket.id, page: nextPage) {
                let older = Array((envelope.data ?? []).reversed())
                messages = older.filter { o in !messages.contains { $0.id == o.id } } + messages
                hasEarlier = envelope.pagination?.hasMorePages == true
                nextPage += 1
            }
            loadingEarlier = false
        }
    }

    private func send() {
        let text = draft.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !(text.isEmpty && imageData == nil), !sending, ticket.acceptsReplies else { return }
        sending = true
        Task {
            do {
                let created = try await SupportApi.sendMessage(ticket.id, body: text.isEmpty ? nil : text, image: imageData).data
                draft = ""
                image = nil
                imageData = nil
                if let created { add(created) } else { await load() }
            } catch {
                AppToast.show(error.apiFailure.message ?? L("support_chat_failed"), long: true)
            }
            sending = false
        }
    }

    private func autoFeedback(_ solved: Bool) {
        guard !statusBusy else { return }
        statusBusy = true
        Task {
            do {
                if let fresh = try await SupportApi.autoReplyFeedback(ticket.id, SupportAutoReplyFeedbackRequest(solved: solved)).data { update(fresh) }
            } catch {
                AppToast.show(error.apiFailure.message ?? L("support_chat_failed"), long: true)
            }
            statusBusy = false
        }
    }

    private func setStatus(_ status: String) {
        guard !statusBusy else { return }
        statusBusy = true
        Task {
            do {
                if let fresh = try await SupportApi.setStatus(ticket.id, SupportStatusRequest(status: status)).data { update(fresh) }
            } catch {
                AppToast.show(error.apiFailure.message ?? L("support_chat_failed"), long: true)
            }
            statusBusy = false
            confirmClose = false
        }
    }
}

/// The status under the header: back, the ticket's title, its number and status, and the one action that fits.
private struct TicketStatusBar: View {
    @Environment(\.wa) private var wa
    let ticket: SupportTicketDto
    let busy: Bool
    let onBack: () -> Void
    let onClose: () -> Void
    let onReopen: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 10) {
                Button(action: onBack) {
                    Image(systemName: "arrow.backward").font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.settingsInk)
                        .frame(width: 38, height: 38)
                        .background(wa.settingsMut.opacity(0.10), in: Circle())
                }
                .buttonStyle(.plain)
                Image(systemName: "headphones").font(.system(size: 18, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 38, height: 38)
                    .background(wa.red.opacity(0.14), in: Circle())
                Text(verbatim: (ticket.title ?? "").trimmingCharacters(in: .whitespaces).isEmpty ? L("support_agent_default") : ticket.title!)
                    .font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                Spacer(minLength: 0)
            }
            wa.settingsMut.opacity(0.12).frame(height: 1).padding(.vertical, 10)
            HStack(spacing: 8) {
                Text(verbatim: "#\(ticket.displayNumber)").font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
                    .padding(.horizontal, 8).padding(.vertical, 3)
                    .background(wa.settingsMut.opacity(0.10), in: RoundedRectangle(cornerRadius: 8, style: .continuous))
                SupportStatusChip(status: ticket.status)
                Spacer(minLength: 0)
                if busy {
                    ProgressView().tint(wa.red).frame(width: 20, height: 20)
                } else if ticket.acceptsReplies {
                    TextAction(label: L("support_close"), icon: "lock.fill", action: onClose)
                } else {
                    TextAction(label: L("support_reopen"), icon: "lock.open.fill", action: onReopen)
                }
            }
        }
        .padding(10)
        .background(wa.night ? wa.darkCard : wa.settingsCard, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(wa.settingsMut.opacity(0.15), lineWidth: 1))
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
    }
}

private struct TextAction: View {
    @Environment(\.wa) private var wa
    let label: String
    let icon: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 12, weight: .semibold))
                Text(verbatim: label).font(.dorr(12, .bold))
            }
            .foregroundStyle(wa.red)
            .padding(.horizontal, 12)
            .padding(.vertical, 7)
            .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

private struct DayChip: View {
    @Environment(\.wa) private var wa
    let label: String

    var body: some View {
        Text(verbatim: label).font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut)
            .padding(.horizontal, 10).padding(.vertical, 3)
            .background(wa.settingsMut.opacity(0.12), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            .frame(maxWidth: .infinity)
            .padding(.vertical, 6)
    }
}

private struct SupportBubble: View {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    let message: SupportMessageDto
    @State private var shown = false

    var body: some View {
        let mine = message.sender == "user"
        let textColor: Color = mine ? .white : wa.settingsInk
        let tailOnRight = mine == (direction == .leftToRight)
        let shape = BubbleCorners(topLeft: 20, topRight: 20, bottomRight: tailOnRight ? 5 : 20, bottomLeft: tailOnRight ? 20 : 5)
        let fill: Color = mine ? wa.red : (message.isAuto ? wa.red.opacity(0.09) : (wa.night ? wa.darkCard : wa.settingsCard))
        let border: Color = mine ? .clear : (message.isAuto ? wa.red.opacity(0.35) : wa.settingsMut.opacity(0.18))
        HStack {
            if mine { Spacer(minLength: 0) }
            VStack(alignment: .leading, spacing: 0) {
                if message.isAuto {
                    let kind = message.autoKind == "away" ? "support_auto_away" : (message.autoKind == "faq" ? "support_auto_faq" : "support_auto_ack")
                    Text(verbatim: L("support_auto_reply") + " · " + L(kind)).font(.dorr(11, .heavy)).foregroundStyle(wa.red).padding(.bottom, 3)
                } else if !mine {
                    Text(verbatim: (message.agentName ?? "").trimmingCharacters(in: .whitespaces).isEmpty ? L("support_agent_default") : message.agentName!)
                        .font(.dorr(11, .heavy)).foregroundStyle(wa.red).padding(.bottom, 3)
                }
                if let url = ApiClient.mediaUrl(message.imageUrl) {
                    AsyncImage(url: url) { phase in
                        if let image = phase.image { image.resizable().scaledToFill() } else { wa.settingsMut.opacity(0.1) }
                    }
                    .frame(maxWidth: 240, maxHeight: 220)
                    .frame(minHeight: 120)
                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .padding(.bottom, (message.body ?? "").trimmingCharacters(in: .whitespaces).isEmpty ? 0 : 6)
                    .onTapGesture { UIApplication.shared.open(url) }
                    .accessibilityLabel(L("support_photo"))
                }
                if let text = message.body, !text.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: text).font(.dorr(14.5)).lineSpacing(4).foregroundStyle(textColor)
                }
                Text(verbatim: timeOf(message.createdAt)).font(.dorr(10)).foregroundStyle(textColor.opacity(0.6))
                    .frame(maxWidth: .infinity, alignment: .trailing)
                    .padding(.top, 3)
            }
            .fixedSize(horizontal: false, vertical: true)
            .padding(.horizontal, 14)
            .padding(.vertical, 10)
            .background(fill, in: shape)
            .overlay(shape.stroke(border, lineWidth: 1))
            .frame(maxWidth: 290, alignment: mine ? .trailing : .leading)
            if !mine { Spacer(minLength: 0) }
        }
        .scaleEffect(shown ? 1 : 0.92)
        .offset(y: shown ? 0 : 8)
        .opacity(shown ? 1 : 0)
        .onAppear { withAnimation(.easeOut(duration: 0.22)) { shown = true } }
    }

    private func timeOf(_ iso: String?) -> String {
        guard let date = parseInstant(iso) else { return "" }
        return appLocaleFormatter(date: .none, time: .short).string(from: date)
    }
}

private struct Composer: View {
    @Environment(\.wa) private var wa
    let ticket: SupportTicketDto
    @Binding var draft: String
    let image: UIImage?
    @Binding var pickerItem: PhotosPickerItem?
    let onClearImage: () -> Void
    let sending: Bool
    let reopenBusy: Bool
    let onSend: () -> Void
    let onReopen: () -> Void

    var body: some View {
        if !ticket.acceptsReplies {
            VStack(spacing: 10) {
                Text(L(ticket.status == "resolved" ? "support_conv_resolved" : "support_conv_closed"))
                    .font(.dorr(13)).lineSpacing(4).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
                SettingsPrimaryButton(text: L("support_reopen"), enabled: !reopenBusy, loading: reopenBusy, action: onReopen)
            }
            .padding(14)
            .frame(maxWidth: .infinity)
            .background(wa.red.opacity(0.10), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .padding(.horizontal, 14)
            .padding(.top, 6)
            .padding(.bottom, 14)
        } else {
            let canSend = !sending && (!draft.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || image != nil)
            VStack(alignment: .leading, spacing: 0) {
                if let image {
                    ZStack(alignment: .topTrailing) {
                        Image(uiImage: image).resizable().scaledToFill().frame(width: 76, height: 76).clipped()
                        Button(action: onClearImage) {
                            Image(systemName: "xmark").font(.system(size: 11, weight: .bold)).foregroundStyle(.white)
                                .frame(width: 22, height: 22)
                                .background(Color.black.opacity(0.55), in: Circle())
                        }
                        .buttonStyle(.plain)
                        .padding(4)
                    }
                    .frame(width: 76, height: 76)
                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .padding(.bottom, 8)
                }
                HStack(alignment: .bottom, spacing: 8) {
                    PhotosPicker(selection: $pickerItem, matching: .images) {
                        Image(systemName: "photo.badge.plus").font(.system(size: 18, weight: .semibold)).foregroundStyle(wa.red)
                            .frame(width: 46, height: 46)
                            .background(wa.red.opacity(0.12), in: Circle())
                    }
                    .buttonStyle(.plain)
                    .disabled(sending)
                    .accessibilityLabel(L("support_attach"))
                    DorrTextArea(value: Binding(get: { draft }, set: { if $0.count <= 4000 { draft = $0 } }), placeholder: L("support_chat_hint"),
                                 singleLine: true, enabled: !sending, onSubmit: onSend)
                    Button(action: onSend) {
                        ZStack {
                            if sending { ProgressView().tint(.white) } else {
                                Image(systemName: "paperplane.fill").font(.system(size: 17)).foregroundStyle(.white)
                                    .flipsForRightToLeftLayoutDirection(true)
                            }
                        }
                        .frame(width: 46, height: 46)
                        .background(wa.red.opacity(canSend ? 1 : 0.45), in: Circle())
                    }
                    .buttonStyle(.plain)
                    .allowsHitTesting(canSend)
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .background(UnevenRoundedRectangleCompat(topRadius: 24).fill(wa.night ? wa.darkCard : wa.settingsCard).ignoresSafeArea(edges: .bottom))
        }
    }
}

// MARK: - Quick chat (guided help)

private enum HelpLine: Identifiable {
    case bot(String, String)
    case me(String, String)

    var id: String {
        switch self {
        case .bot(let key, _), .me(let key, _): return key
        }
    }
}

/// The guided help before a ticket: the assistant greets, shows the topics as a list, and each pick is answered
/// and either opens the topic's own sub-list or ends the path. At the end: "that solved it" (no ticket) or
/// "I need an agent" (on to the ticket form, the topic as its title). With no menu (or no connection) it goes
/// straight to the ticket form.
@MainActor
struct SupportHelpFlowScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var router: AppRouter
    let onBack: () -> Void
    let onSolved: () -> Void
    let onNeedAgent: (String?) -> Void
    /// The topics picked before (ids from the main menu down), so coming back from the ticket form lands where it left.
    var initialPath: [Int] = []
    var onPathChange: ([Int]) -> Void = { _ in }

    @State private var loading = true
    @State private var greeting = ""
    @State private var root: [SupportHelpNodeDto] = []
    /// The topics picked so far, from the main menu down.
    @State private var steps: [SupportHelpNodeDto] = []

    var body: some View {
        let current = steps.last
        let options = current?.children ?? root
        let finished = current != nil && current!.children.isEmpty
        var lines: [HelpLine] = []
        if !greeting.trimmingCharacters(in: .whitespaces).isEmpty { lines.append(.bot("greeting", greeting)) }
        for step in steps {
            lines.append(.me("me-\(step.id)", step.title ?? ""))
            if let answer = step.answer, !answer.trimmingCharacters(in: .whitespaces).isEmpty { lines.append(.bot("bot-\(step.id)", answer)) }
        }
        return ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                // Back steps up the menu one topic at a time; at the top it leaves the flow.
                SubHeader(title: L("support_help_title"), onBack: { if steps.isEmpty { onBack() } else { steps.removeLast() } })
                if loading {
                    ProgressView().tint(wa.red).frame(maxWidth: .infinity, maxHeight: .infinity)
                } else {
                    ScrollViewReader { proxy in
                        ScrollView {
                            VStack(spacing: 8) {
                                ForEach(lines) { line in
                                    switch line {
                                    case .bot(_, let text): HelpBubble(text: text, mine: false)
                                    case .me(_, let text): HelpBubble(text: text, mine: true)
                                    }
                                }
                                Group {
                                    if finished {
                                        FinalChoice(onSolved: { report(current, true); onSolved() },
                                                    onNeedAgent: { report(current, false); onNeedAgent(current?.title) })
                                    } else {
                                        OptionsCard(options: options) { steps.append($0) }
                                    }
                                }
                                .id("tail")
                                Spacer().frame(height: 12)
                            }
                            .padding(.horizontal, 14)
                            .padding(.vertical, 8)
                        }
                        // The newest line (and the options under it) stay in view.
                        .onChange(of: steps.count) { _ in
                            withAnimation(.easeOut(duration: 0.3)) { proxy.scrollTo("tail", anchor: .bottom) }
                        }
                        .onAppear { proxy.scrollTo("tail", anchor: .bottom) }
                    }
                }
            }
        }
        // The quick chat gets the whole screen: the bottom bar steps aside while it is open.
        .onAppear { router.supportChatOpen = true }
        .onDisappear { router.supportChatOpen = false }
        .task { await load() }
        .onChange(of: steps.map(\.id)) { ids in if !loading { onPathChange(ids) } }
    }

    private func load() async {
        guard let data = try? await SupportApi.help().data, !data.items.isEmpty else {
            onNeedAgent(nil)
            return
        }
        greeting = data.greeting ?? ""
        root = data.items
        // Back to where the customer was: walk the saved path down the tree.
        var level = data.items
        var restored: [SupportHelpNodeDto] = []
        for id in initialPath {
            guard let node = level.first(where: { $0.id == id }) else { break }
            restored.append(node)
            level = node.children
        }
        steps = restored
        loading = false
    }

    /// What the customer pressed is counted for the team (best effort: it never holds the flow up).
    private func report(_ topic: SupportHelpNodeDto?, _ solved: Bool) {
        guard let id = topic?.id else { return }
        Task.detached { _ = try? await SupportApi.helpFeedback(id, SupportAutoReplyFeedbackRequest(solved: solved)) }
    }
}

/// Text lines up by its own language (Arabic right, English left) whatever the screen's direction.
private func contentAlignment(_ text: String) -> TextAlignment {
    let rtl = text.unicodeScalars.first { CharacterSet.letters.contains($0) }.map { (0x0590...0x08FF).contains($0.value) } ?? false
    return rtl ? .trailing : .leading
}

private struct HelpBubble: View {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    let text: String
    let mine: Bool
    @State private var shown = false

    var body: some View {
        let tailOnRight = mine == (direction == .leftToRight)
        let shape = BubbleCorners(topLeft: 20, topRight: 20, bottomRight: tailOnRight ? 5 : 20, bottomLeft: tailOnRight ? 20 : 5)
        HStack {
            if mine { Spacer(minLength: 0) }
            Text(verbatim: text).font(.dorr(14.5)).lineSpacing(4).foregroundStyle(mine ? .white : wa.settingsInk)
                .multilineTextAlignment(contentAlignment(text))
                .padding(.horizontal, 14)
                .padding(.vertical, 10)
                .background(mine ? wa.red : (wa.night ? wa.darkCard : wa.settingsCard), in: shape)
                .overlay(shape.stroke(mine ? .clear : wa.settingsMut.opacity(0.18), lineWidth: 1))
                .frame(maxWidth: 300, alignment: mine ? .trailing : .leading)
            if !mine { Spacer(minLength: 0) }
        }
        .scaleEffect(shown ? 1 : 0.94)
        .offset(y: shown ? 0 : 8)
        .opacity(shown ? 1 : 0)
        .onAppear { withAnimation(.easeOut(duration: 0.22)) { shown = true } }
    }
}

/// The topics as one rounded card of rows with a chevron.
private struct OptionsCard: View {
    @Environment(\.wa) private var wa
    let options: [SupportHelpNodeDto]
    let onPick: (SupportHelpNodeDto) -> Void

    var body: some View {
        VStack(spacing: 0) {
            ForEach(Array(options.enumerated()), id: \.element.id) { index, option in
                Button { onPick(option) } label: {
                    HStack {
                        Text(verbatim: option.title ?? "").font(.dorr(15, .semibold)).foregroundStyle(wa.settingsInk)
                            .multilineTextAlignment(.leading)
                        Spacer(minLength: 8)
                        Image(systemName: "chevron.forward").font(.system(size: 14, weight: .bold)).foregroundStyle(wa.settingsInk)
                    }
                    .padding(.horizontal, 18)
                    .padding(.vertical, 16)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                if index != options.count - 1 {
                    wa.settingsMut.opacity(0.15).frame(height: 1)
                }
            }
        }
        .background(wa.night ? wa.darkCard : wa.settingsCard, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(wa.settingsMut.opacity(0.18), lineWidth: 1))
        .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
    }
}

/// The end of the path: there is no way on but one of these two.
private struct FinalChoice: View {
    @Environment(\.wa) private var wa
    let onSolved: () -> Void
    let onNeedAgent: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(L("support_help_final_question")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.horizontal, 4)
            Button(action: onSolved) {
                Text(L("support_help_solved")).font(.dorr(15, .heavy)).foregroundStyle(.white)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 15)
                    .background(wa.red, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            }
            .buttonStyle(.plain)
            Button(action: onNeedAgent) {
                HStack(spacing: 8) {
                    Image(systemName: "headphones").font(.system(size: 17, weight: .semibold))
                    Text(L("support_help_need_agent")).font(.dorr(15, .heavy))
                }
                .foregroundStyle(wa.red)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 15)
                .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            }
            .buttonStyle(.plain)
        }
    }
}

/// A rectangle with its own radius at each corner (the chat bubbles' tail corner).
private struct BubbleCorners: Shape {
    let topLeft: CGFloat
    let topRight: CGFloat
    let bottomRight: CGFloat
    let bottomLeft: CGFloat

    func path(in rect: CGRect) -> Path {
        var p = Path()
        p.move(to: CGPoint(x: rect.minX + topLeft, y: rect.minY))
        p.addLine(to: CGPoint(x: rect.maxX - topRight, y: rect.minY))
        p.addArc(center: CGPoint(x: rect.maxX - topRight, y: rect.minY + topRight), radius: topRight, startAngle: .degrees(-90), endAngle: .degrees(0), clockwise: false)
        p.addLine(to: CGPoint(x: rect.maxX, y: rect.maxY - bottomRight))
        p.addArc(center: CGPoint(x: rect.maxX - bottomRight, y: rect.maxY - bottomRight), radius: bottomRight, startAngle: .degrees(0), endAngle: .degrees(90), clockwise: false)
        p.addLine(to: CGPoint(x: rect.minX + bottomLeft, y: rect.maxY))
        p.addArc(center: CGPoint(x: rect.minX + bottomLeft, y: rect.maxY - bottomLeft), radius: bottomLeft, startAngle: .degrees(90), endAngle: .degrees(180), clockwise: false)
        p.addLine(to: CGPoint(x: rect.minX, y: rect.minY + topLeft))
        p.addArc(center: CGPoint(x: rect.minX + topLeft, y: rect.minY + topLeft), radius: topLeft, startAngle: .degrees(180), endAngle: .degrees(270), clockwise: false)
        p.closeSubpath()
        return p
    }
}
