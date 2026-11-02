import PhotosUI
import SwiftUI
import UIKit

// freelanceApp `ui/screens/SupportChatScreen.kt` — one support ticket as a live conversation: the customer's messages on one
// side, the support team's on the other, the status (opened, resolved, closed, reopened) at the top, and the actions that fit
// it: close it, or reopen it when it is finished. New messages and status changes made by support arrive on their own
// (Pusher, `Realtime.shared.events`), no refreshing.

private enum ConversationRow: Identifiable {
    case day(String)
    case msg(SupportMessageDto)

    var id: String {
        switch self {
        case .day(let label): return "d-\(label)"
        case .msg(let message): return "m-\(message.id ?? 0)"
        }
    }
}

private func conversationRows(_ messages: [SupportMessageDto]) -> [ConversationRow] {
    var out: [ConversationRow] = []
    var lastDay: String?
    let formatter = supportDateFormatter(date: .medium, time: .none)
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

/// One support ticket as a live conversation (see the file header).
@MainActor
struct SupportChatScreen: View {
    @Environment(\.wa) private var wa
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
    @State private var pickerItem: PhotosPickerItem?
    @State private var image: UIImage?
    @State private var sending = false
    @State private var statusBusy = false
    @State private var confirmClose = false

    init(initial: SupportTicketDto, onBack: @escaping () -> Void, onTicketChanged: @escaping (SupportTicketDto) -> Void = { _ in }) {
        self.onBack = onBack
        self.onTicketChanged = onTicketChanged
        _ticket = State(initialValue: initial)
    }

    private var ticketId: Int { ticket.id ?? 0 }
    private var acceptsReplies: Bool { ticket.acceptsReplies ?? true }

    var body: some View {
        let rows = conversationRows(messages)
        // The feedback buttons belong to the last message only when it is an automatic FAQ answer.
        let lastFaqId = messages.last.flatMap { ($0.isAuto ?? false) && $0.autoKind == "faq" ? $0.id : nil }
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                TicketStatusBar(ticket: ticket, busy: statusBusy, onBack: onBack, onClose: { confirmClose = true }, onReopen: { setStatus("reopened") })
                if loading {
                    ScrollView { SkeletonList(count: 3).padding(.horizontal, 14).padding(.vertical, 8) }
                        .scrollDisabled(true)
                        .frame(maxHeight: .infinity)
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
                                            ProgressView().tint(wa.red).scaleEffect(0.8)
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
                                        if message.id == lastFaqId && acceptsReplies && !(ticket.autoReplyStopped ?? false) {
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
                        }
                    }
                }
                Composer(ticket: ticket, draft: Binding(get: { draft }, set: { if $0.count <= 4000 { draft = $0 } }), image: image,
                         pickerItem: $pickerItem, onClearImage: { image = nil; pickerItem = nil },
                         sending: sending, reopenBusy: statusBusy, onSend: send, onReopen: { setStatus("reopened") })
            }
        }
        // The chat gets the whole screen: the main bottom bar steps aside while it is open.
        .onAppear { ShellState.shared.immersive = true }
        .onDisappear { ShellState.shared.immersive = false }
        .task(id: "\(network.reconnectTick)-\(ticketId)") { await load() }
        // Everything on screen counts as read.
        .onChange(of: messages.count) { _ in
            if let at = messages.last?.createdAt { SupportSeen.mark(ticketId, at) }
        }
        // Live: support's replies and status changes land here the moment they happen.
        .onReceive(Realtime.shared.events) { event in
            guard event.name.hasPrefix("support."),
                  let fresh = supportDecode(event.data["ticket"], as: SupportTicketDto.self), fresh.id == ticket.id else { return }
            update(fresh)
            if event.name == "support.message", let message = supportDecode(event.data["message"], as: SupportMessageDto.self) { add(message) }
        }
        .onChange(of: pickerItem) { item in
            guard let item else { return }
            Task {
                if let raw = try? await item.loadTransferable(type: Data.self) { image = UIImage(data: raw) }
            }
        }
        .dorrDialog(isPresented: Binding(get: { confirmClose }, set: { if !$0 && !statusBusy { confirmClose = false } })) {
            ConfirmDialog(icon: "lock.fill", title: L("support_close_title"), message: L("support_close_message"), loading: statusBusy,
                          confirmLabel: L("common_yes"), onConfirm: { setStatus("closed") }, onDismiss: { if !statusBusy { confirmClose = false } })
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
        if let envelope = try? await SupportClient.messages(ticketId, page: 1) {
            messages = (envelope.data ?? []).reversed()
            hasEarlier = envelope.pagination?.hasMorePages == true
            nextPage = 2
            if let at = messages.last?.createdAt { SupportSeen.mark(ticketId, at) }
        }
        loading = false
    }

    private func loadEarlier() {
        guard !loadingEarlier else { return }
        loadingEarlier = true
        Task {
            if let envelope = try? await SupportClient.messages(ticketId, page: nextPage) {
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
        guard !(text.isEmpty && pickerItem == nil), !sending, acceptsReplies else { return }
        sending = true
        Task {
            do {
                var part: FilePart?
                if let pickerItem { part = await supportImagePart(pickerItem, name: "support-message") }
                let created = try await SupportClient.sendMessage(ticketId, body: text.isEmpty ? nil : text, image: part).data
                draft = ""
                image = nil
                pickerItem = nil
                if let created { add(created) } else { await load() }
            } catch {
                AppToast.show(error.apiFailure().message ?? L("support_chat_failed"), long: true)
            }
            sending = false
        }
    }

    private func autoFeedback(_ solved: Bool) {
        guard !statusBusy else { return }
        statusBusy = true
        Task {
            do {
                if let fresh = try await SupportClient.autoReplyFeedback(ticketId, SupportAutoReplyFeedbackRequest(solved: solved)).data { update(fresh) }
            } catch {
                AppToast.show(error.apiFailure().message ?? L("support_chat_failed"), long: true)
            }
            statusBusy = false
        }
    }

    private func setStatus(_ status: String) {
        guard !statusBusy else { return }
        statusBusy = true
        Task {
            do {
                if let fresh = try await SupportClient.setStatus(ticketId, SupportStatusRequest(status: status)).data { update(fresh) }
            } catch {
                AppToast.show(error.apiFailure().message ?? L("support_chat_failed"), long: true)
            }
            statusBusy = false
            confirmClose = false
        }
    }
}

/// The status under the header: a coloured chip, the ticket's title, and the one action that fits.
private struct TicketStatusBar: View {
    @Environment(\.wa) private var wa
    let ticket: SupportTicketDto
    let busy: Bool
    let onBack: () -> Void
    let onClose: () -> Void
    let onReopen: () -> Void

    var body: some View {
        let title = (ticket.title ?? "").trimmingCharacters(in: .whitespaces)
        VStack(spacing: 0) {
            // Row 1: back, the support icon and the ticket's title.
            HStack(spacing: 10) {
                Button(action: onBack) {
                    Image(systemName: "arrow.backward").font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.settingsInk)
                        .frame(width: 38, height: 38)
                        .background(wa.settingsMut.opacity(0.10), in: Circle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("common_back"))
                Image(systemName: "headphones").font(.system(size: 18, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 38, height: 38)
                    .background(wa.red.opacity(0.14), in: Circle())
                Text(verbatim: title.isEmpty ? L("support_agent_default") : (ticket.title ?? ""))
                    .font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                Spacer(minLength: 0)
            }
            wa.settingsMut.opacity(0.12).frame(height: 1).padding(.vertical, 10)
            // Row 2: the ticket's number, its status, and the one action that fits.
            HStack(spacing: 8) {
                Text(verbatim: "#\(ticket.displayNumber)").font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
                    .padding(.horizontal, 8).padding(.vertical, 3)
                    .background(wa.settingsMut.opacity(0.10), in: RoundedRectangle(cornerRadius: 8, style: .continuous))
                SupportStatusChip(status: ticket.status)
                Spacer(minLength: 0)
                if busy {
                    ProgressView().tint(wa.red).frame(width: 20, height: 20)
                } else if ticket.acceptsReplies ?? true {
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
        let isAuto = message.isAuto ?? false
        let textColor: Color = mine ? .white : wa.settingsInk
        let tailOnRight = mine == (direction == .leftToRight)
        let shape = SupportBubbleCorners(topLeft: 20, topRight: 20, bottomRight: tailOnRight ? 5 : 20, bottomLeft: tailOnRight ? 20 : 5)
        let fill: Color = mine ? wa.red : (isAuto ? wa.red.opacity(0.09) : (wa.night ? wa.darkCard : wa.settingsCard))
        let border: Color = mine ? .clear : (isAuto ? wa.red.opacity(0.35) : wa.settingsMut.opacity(0.18))
        let text = (message.body ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        HStack {
            if mine { Spacer(minLength: 0) }
            VStack(alignment: .leading, spacing: 0) {
                if isAuto {
                    let kind = message.autoKind == "away" ? "support_auto_away" : (message.autoKind == "faq" ? "support_auto_faq" : "support_auto_ack")
                    Text(verbatim: L("support_auto_reply") + " · " + L(kind)).font(.dorr(11, .heavy)).foregroundStyle(wa.red).padding(.bottom, 3)
                } else if !mine {
                    let agent = (message.agentName ?? "").trimmingCharacters(in: .whitespaces)
                    Text(verbatim: agent.isEmpty ? L("support_agent_default") : agent)
                        .font(.dorr(11, .heavy)).foregroundStyle(wa.red).padding(.bottom, 3)
                }
                if let url = Api.mediaUrl(message.imageUrl) {
                    AsyncImage(url: url) { phase in
                        if let image = phase.image { image.resizable().scaledToFill() } else { wa.settingsMut.opacity(0.1) }
                    }
                    .frame(maxWidth: 240, minHeight: 120, maxHeight: 220)
                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .padding(.bottom, text.isEmpty ? 0 : 6)
                    .onTapGesture { UIApplication.shared.open(url) }
                    .accessibilityLabel(L("support_photo"))
                }
                if !text.isEmpty {
                    Text(verbatim: message.body ?? "").font(.dorr(14.5)).lineSpacing(4).foregroundStyle(textColor)
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
        return supportDateFormatter(date: .none, time: .short).string(from: date)
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
        if !(ticket.acceptsReplies ?? true) {
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
            let canSend = !sending && (!draft.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || pickerItem != nil)
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
                    DorrTextField(value: $draft, placeholder: L("support_chat_hint"), enabled: !sending)
                        .submitLabel(.send)
                    Button(action: onSend) {
                        ZStack {
                            if sending { ProgressView().tint(.white).scaleEffect(0.8) } else {
                                Image(systemName: "paperplane.fill").font(.system(size: 17)).foregroundStyle(.white)
                                    .flipsForRightToLeftLayoutDirection(true)
                            }
                        }
                        .frame(width: 46, height: 46)
                        .background(wa.red.opacity(canSend ? 1 : 0.45), in: Circle())
                    }
                    .buttonStyle(.plain)
                    .allowsHitTesting(canSend)
                    .accessibilityLabel(L("support_chat_hint"))
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .background(UnevenRoundedRectangleCompat(topRadius: 24).fill(wa.night ? wa.darkCard : wa.settingsCard).ignoresSafeArea(edges: .bottom))
        }
    }
}

/// A rectangle with its own radius at each corner (the chat bubbles' tail corner).
struct SupportBubbleCorners: Shape {
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
