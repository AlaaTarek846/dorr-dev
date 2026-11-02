import SwiftUI

// androidApp `freelance/FreelanceChat.kt`, `FreelanceChatInterview.kt`, `FreelanceInvitations.kt`.

private func chatTime(_ iso: String?) -> String? {
    guard let date = iso.flatMap(parseIsoDate) else { return nil }
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.dateFormat = "HH:mm"
    return f.string(from: date)
}

private func parseIsoDate(_ iso: String) -> Date? {
    let f = ISO8601DateFormatter()
    f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
    if let d = f.date(from: iso) { return d }
    f.formatOptions = [.withInternetDateTime]
    return f.date(from: iso)
}

/// "EEE d MMM" in the app's language with Western digits (java.time's default on Android).
private func dayFormatter(_ pattern: String) -> DateFormatter {
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current + "@numbers=latn")
    f.dateFormat = pattern
    return f
}

/// The talk with a freelancer about their offer (FL-308). Until the agreement the server refuses phone numbers,
/// emails and links and says so. The page reads once when it opens and when the connection comes back; live
/// messages on the room's private channel arrive with the realtime client (Chat section) — nothing on a timer.
@MainActor
struct FreelanceChatPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let roomUuid: String
    let onBack: () -> Void
    @State private var room: FreelanceChatRoomDto?
    // Newest first, as the server gives them (drawn from the bottom).
    @State private var messages: [FreelanceChatMessageDto] = []
    @State private var hasMore = false
    @State private var loadingOlder = false
    @State private var olderFailed = false
    @State private var input = ""
    @State private var sending = false
    @State private var error: String?
    @State private var loaded = false
    @State private var interviews: [FreelanceInterviewDto] = []
    // FL-709: the message being reported (the ⋯ beside the other person's).
    @State private var reporting: Int?
    @State private var reportNotice: String?

    private func load() async {
        do {
            room = try await FreelanceApi.chatRoom(roomUuid).data
            if let list = try? await FreelanceApi.interviews(roomUuid).data { interviews = list }
            let list = try await FreelanceApi.chatMessages(roomUuid).data ?? []
            // The newest page replaces what is shown; the older messages I scrolled up to stay under it.
            let older = list.last.map { oldest in messages.filter { $0.id < oldest.id } } ?? []
            messages = list + older
            if older.isEmpty { hasMore = list.count >= 40 }
            error = nil
            if list.contains(where: { $0.mine == false && $0.readAt == nil }) { _ = try? await FreelanceApi.chatRead(roomUuid) }
        } catch {
            if !loaded { self.error = flError(error) }
        }
        loaded = true
    }

    /// Older messages come by themselves when I scroll up to the oldest one loaded (nothing on a timer).
    private func loadOlder() async {
        guard !loadingOlder, hasMore, let before = messages.last?.id else { return }
        loadingOlder = true
        olderFailed = false
        if let more = try? await FreelanceApi.chatMessages(roomUuid, before: before).data {
            messages += more.filter { n in !messages.contains { $0.id == n.id } }
            hasMore = more.count >= 40
        } else {
            olderFailed = true
        }
        loadingOlder = false
    }

    private func send() {
        let text = input.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty, !sending else { return }
        sending = true
        error = nil
        Task {
            do {
                if let sent = try await FreelanceApi.chatSend(roomUuid, body: text, clientId: UUID().uuidString).data, !messages.contains(where: { $0.id == sent.id }) {
                    messages.insert(sent, at: 0)
                }
                input = ""
            } catch {
                self.error = flError(error)
            }
            sending = false
        }
    }

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(alignment: .leading, spacing: 0) {
                SubHeader(title: room?.peer?.name ?? L("fl_chat_title"), onBack: onBack)
                if let title = room?.project?.title {
                    HStack(spacing: 8) {
                        Image(systemName: "briefcase.fill").font(.system(size: 13)).foregroundStyle(wa.accent)
                        Text(verbatim: title).font(.dorr(12.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        Spacer(minLength: 0)
                    }
                    .padding(.horizontal, 12).padding(.vertical, 8)
                    .background(wa.accent.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .padding(.horizontal, 16)
                }
                if room?.guarded == true { FlNotice(text: L("fl_chat_guard_hint"), icon: "shield.fill", color: flAmber).padding(.horizontal, 16).padding(.vertical, 6) }
                ZStack {
                    if !loaded && error == nil {
                        VStack(alignment: .leading, spacing: 10) {
                            WaSkeleton(cornerRadius: 16).frame(width: 220, height: 48)
                            WaSkeleton(cornerRadius: 16).frame(width: 180, height: 40).frame(maxWidth: .infinity, alignment: .trailing)
                            WaSkeleton(cornerRadius: 16).frame(width: 250, height: 56)
                            Spacer(minLength: 0)
                        }
                        .padding(14)
                    } else {
                        list
                    }
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed).padding(.horizontal, 16).padding(.vertical, 2) }
                if let reportNotice { FlNotice(text: reportNotice, icon: "checkmark.circle.fill", color: flGreen).padding(.horizontal, 16).padding(.vertical, 2) }
                if room != nil {
                    InterviewPanel(roomUuid: roomUuid, canWrite: room?.canWrite == true, items: interviews,
                                   onChanged: { Task { await load() } }, onError: { error = $0 })
                }
                if room?.canWrite == true {
                    HStack(alignment: .bottom, spacing: 8) {
                        DorrTextArea(value: Binding(get: { input }, set: { if $0.count <= 2000 { input = $0 } }), placeholder: L("fl_chat_hint"), minHeight: 46)
                        let ready = !input.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty && !sending
                        Button(action: send) {
                            Image(systemName: "paperplane.fill").font(.system(size: 17)).foregroundStyle(.white)
                                .flipsForRightToLeftLayoutDirection(true)
                                .frame(width: 44, height: 44)
                                .background(wa.accent.opacity(ready ? 1 : 0.4), in: Circle())
                        }
                        .buttonStyle(.plain)
                        .disabled(!ready)
                        .accessibilityLabel(L("fl_chat_send"))
                    }
                    .padding(.horizontal, 12).padding(.vertical, 8)
                } else if room != nil {
                    Text(L("fl_chat_closed")).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading).padding(16)
                }
            }
        }
        .task(id: network.reconnectTick) { await load() }
        // The room's own channel while the page is open: a new message is one reload.
        .onAppear { ChatRealtime.shared.watchFreelanceRoom(roomUuid) }
        .onDisappear { ChatRealtime.shared.unwatchFreelanceRoom(roomUuid) }
        .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { e in if e.name == "freelance.message" { Task { await load() } } }
        .dorrDialog(isPresented: Binding(get: { reporting != nil }, set: { if !$0 { reporting = nil } }), bottom: true) {
            if let id = reporting {
                ReportMessageDialog(messageId: id, onDone: { reportNotice = $0; reporting = nil }, onDismiss: { reporting = nil })
            }
        }
    }

    private var list: some View {
        ScrollView {
            LazyVStack(spacing: 6) {
                ForEach(Array(messages.enumerated()), id: \.element.id) { i, m in
                    ChatBubble(m: m) { reporting = m.id }
                        .scaleEffect(x: 1, y: -1)
                        .onAppear { if i >= messages.count - 4 && hasMore && !olderFailed { Task { await loadOlder() } } }
                }
                if hasMore {
                    Group {
                        if olderFailed {
                            Button { olderFailed = false; Task { await loadOlder() } } label: {
                                Text(L("fl_error_generic")).font(.dorr(13, .bold)).foregroundStyle(wa.accent).frame(maxWidth: .infinity).padding(12)
                            }
                            .buttonStyle(.plain)
                        } else {
                            ProgressView().tint(wa.accent).frame(maxWidth: .infinity).padding(12)
                                .onAppear { Task { await loadOlder() } }
                        }
                    }
                    .scaleEffect(x: 1, y: -1)
                }
                if loaded && messages.isEmpty {
                    WaEmpty(icon: "bubble.left.and.bubble.right.fill", tone: .blue, title: L("fl_chat_title"), text: L("fl_chat_empty")).scaleEffect(x: 1, y: -1)
                }
            }
            .padding(.horizontal, 14).padding(.vertical, 8)
        }
        // reverseLayout: the newest at the bottom, the list starting there.
        .scaleEffect(x: 1, y: -1)
    }
}

private struct ChatBubble: View {
    @Environment(\.wa) private var wa
    let m: FreelanceChatMessageDto
    let onReport: () -> Void

    var body: some View {
        if m.kind == "system" {
            InterviewSystemLine(text: m.body ?? "")
        } else {
            let mine = m.mine == true
            HStack {
                if mine { Spacer(minLength: 0) }
                VStack(alignment: .leading, spacing: 2) {
                    let text = m.kind == "deleted" ? L("fl_chat_deleted") : ((m.kind ?? "text") != "text" ? L("fl_chat_file") : (m.body ?? ""))
                    Text(verbatim: text).font(.dorr(14)).foregroundStyle(mine ? .white : wa.settingsInk).lineSpacing(4)
                    if let t = chatTime(m.createdAt) { Text(verbatim: "\u{200E}" + t).font(.dorr(10)).foregroundStyle(mine ? .white.opacity(0.8) : wa.settingsMut) }
                }
                .padding(.horizontal, 12).padding(.vertical, 8)
                .frame(maxWidth: 280, alignment: .leading)
                .background { if mine { RoundedRectangle(cornerRadius: 16, style: .continuous).fill(wa.accent) } }
                .modifier(SurfaceIf(on: !mine))
                .fixedSize(horizontal: true, vertical: false)
                // FL-709: the three dots beside the other person's message open a small menu (report).
                if !mine && m.kind != "deleted" {
                    Menu {
                        Button(L("fl_rm_title"), action: onReport)
                    } label: {
                        Image(systemName: "ellipsis").rotationEffect(.degrees(90)).font(.system(size: 15, weight: .bold)).foregroundStyle(wa.settingsMut)
                            .frame(width: 32, height: 32).contentShape(Circle())
                    }
                }
                if !mine { Spacer(minLength: 0) }
            }
        }
    }
}

private struct SurfaceIf: ViewModifier {
    let on: Bool
    func body(content: Content) -> some View {
        if on { content.settingsSurface(16) } else { content }
    }
}

// MARK: - Interviews (FL-310)

private func interviewWhen(_ iso: String) -> String {
    guard let d = parseIsoDate(iso) else { return iso }
    return dayFormatter("EEE d MMM, HH:mm").string(from: d)
}

/// A short interview with the freelancer, arranged in the room: the open one (confirm / decline what the freelancer
/// proposed, or cancel) and a picker to propose a time. A confirmed interview also lands in the Dorr calendar.
@MainActor
private struct InterviewPanel: View {
    @Environment(\.wa) private var wa
    let roomUuid: String
    let canWrite: Bool
    let items: [FreelanceInterviewDto]
    let onChanged: () -> Void
    let onError: (String) -> Void
    @State private var busy = false
    @State private var picking = false

    private func run(_ block: @escaping () async throws -> Void) {
        busy = true
        Task {
            do {
                try await block()
                picking = false
                onChanged()
            } catch {
                onError(flError(error))
            }
            busy = false
        }
    }

    var body: some View {
        let open = items.first { $0.status == "proposed" || $0.status == "confirmed" }
        if open != nil || canWrite {
            VStack(alignment: .leading, spacing: 8) {
                if let open, let id = open.uuid {
                    let toAnswer = open.status == "proposed" && open.proposedBy != "client"
                    FlHeading(icon: "calendar", text: L("fl_iv_title"))
                    Text(verbatim: interviewWhen(open.startsAt ?? "") + " · " + L("fl_iv_minutes", open.durationMinutes ?? 15)).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                    if let note = open.note, !note.isEmpty { Text(verbatim: note).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    Text(L(open.status == "confirmed" ? "fl_iv_confirmed" : (toAnswer ? "fl_iv_asks_you" : "fl_iv_waiting"))).font(.dorr(12, .semibold)).foregroundStyle(wa.accent)
                    VStack(spacing: 8) {
                        if toAnswer {
                            FlAction(text: L("fl_iv_confirm"), icon: "checkmark.circle.fill", tone: .primary, enabled: !busy) { run { _ = try await FreelanceApi.answerInterview(roomUuid, id, action: "confirm") } }
                            FlAction(text: L("fl_iv_decline"), icon: "xmark.circle.fill", tone: .danger, enabled: !busy) { run { _ = try await FreelanceApi.answerInterview(roomUuid, id, action: "decline") } }
                        } else {
                            FlAction(text: L("fl_iv_cancel"), icon: "xmark.circle.fill", tone: .danger, enabled: !busy) { run { _ = try await FreelanceApi.answerInterview(roomUuid, id, action: "cancel") } }
                        }
                        if canWrite { FlAction(text: L("fl_iv_other_time"), icon: "clock") { picking.toggle() } }
                    }
                } else {
                    FlAction(text: L("fl_iv_schedule"), icon: "calendar.badge.plus") { picking.toggle() }
                }
                if picking && canWrite {
                    InterviewPicker(busy: busy) { startsAt, minutes in
                        run { _ = try await FreelanceApi.proposeInterview(roomUuid, startsAt: startsAt, durationMinutes: minutes) }
                    }
                }
            }
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .settingsSurface(16)
            .padding(.horizontal, 12).padding(.vertical, 4)
        }
    }
}

private struct InterviewPicker: View {
    let busy: Bool
    let onSend: (String, Int) -> Void
    private let today = Calendar.current.startOfDay(for: Date())
    @State private var day = Calendar.current.date(byAdding: .day, value: 1, to: Calendar.current.startOfDay(for: Date()))!
    @State private var time = 10 * 60
    @State private var minutes = 15
    private let slots = (8...22).flatMap { [$0 * 60, $0 * 60 + 30] }

    var body: some View {
        let dayText = dayFormatter("EEE d MMM")
        VStack(alignment: .leading, spacing: 8) {
            FlHeading(icon: "calendar", text: L("fl_iv_title"))
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    ForEach(0..<14, id: \.self) { n in
                        let d = Calendar.current.date(byAdding: .day, value: n, to: today)!
                        FlChip(text: dayText.string(from: d), selected: d == day, icon: "calendar") { day = d }
                    }
                }
            }
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    ForEach(slots, id: \.self) { t in FlChip(text: String(format: "%02d:%02d", t / 60, t % 60), selected: t == time, icon: "clock") { time = t } }
                }
            }
            HStack(spacing: 6) {
                ForEach([10, 15, 30, 45, 60], id: \.self) { m in FlChip(text: L("fl_iv_minutes", m), selected: m == minutes, icon: "timer") { minutes = m } }
            }
            SettingsPrimaryButton(text: L("fl_iv_send"), loading: busy) {
                let start = Calendar.current.date(byAdding: .minute, value: time, to: day) ?? day
                let f = ISO8601DateFormatter()
                f.timeZone = .current
                f.formatOptions = [.withInternetDateTime]
                onSend(f.string(from: start), minutes)
            }
        }
    }
}

/// The line the server writes in the room when an interview is proposed, answered or cancelled.
private struct InterviewSystemLine: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        Text(verbatim: text).font(.dorr(12)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).frame(maxWidth: .infinity).padding(.vertical, 4)
    }
}

// MARK: - Invitations (FL-205)

private func inviteStatusLabel(_ status: String?) -> String {
    switch status {
    case "accepted": return L("fl_inv_s_accepted")
    case "declined": return L("fl_inv_s_declined")
    case "withdrawn": return L("fl_inv_s_withdrawn")
    case "expired": return L("fl_inv_s_expired")
    default: return L("fl_inv_s_sent")
    }
}

private func inviteStatusColor(_ status: String?) -> Color {
    switch status {
    case "accepted": return flGreen
    case "declined": return flRed
    case "withdrawn", "expired": return Color(hex: 0x6B7280)
    default: return flAmber
    }
}

private func inviteeLine(_ f: FreelanceInviteeDto) -> String {
    [f.rating.map { L("fl_inv_rating", String($0)) },
     (f.completedProjects ?? 0) > 0 ? L("fl_inv_done", f.completedProjects ?? 0) : nil,
     f.specialties?.first?.name].compactMap { $0 }.joined(separator: " · ")
}

/// The invitations of a private project: who was invited and what they said, inviting more (a search, the project's
/// specialty first), taking one back, and opening the project to everyone when nobody has accepted.
@MainActor
struct InvitationsPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let onBack: () -> Void
    let onOpenOffer: (String) -> Void
    let onMadePublic: () -> Void
    @State private var data: FreelanceInvitationsDto?
    @State private var reloads = 0
    @State private var busy = false
    @State private var error: String?
    @State private var notice: String?
    @State private var confirmPublic = false
    // The picker: the freelancers found, the ones ticked, the search.
    @State private var picking = false
    @State private var query = ""
    @State private var all = false
    @State private var found: [FreelanceInviteeDto] = []
    @State private var searching = false
    @State private var ticked: [String] = []

    private func act(_ block: @escaping () async throws -> Void) {
        busy = true
        error = nil
        notice = nil
        Task {
            do { try await block() } catch { self.error = flError(error) }
            busy = false
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: L("fl_inv_title"), onBack: onBack)
            if let d = data {
                ScrollView { content(d).padding(.horizontal, 16) }
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: "\(network.reconnectTick)|\(reloads)") {
            do { data = try await FreelanceApi.invitations(projectUuid).data } catch { self.error = flError(error) }
        }
        // Searching runs when the picker opens and when the text or the "everyone" switch changes — not on a timer.
        .task(id: "\(picking)|\(query)|\(all)|\(reloads)") {
            guard picking else { return }
            searching = true
            do {
                let q = query.trimmingCharacters(in: .whitespaces)
                found = try await FreelanceApi.invitees(projectUuid, q: q.isEmpty ? nil : q, all: all).data ?? []
            } catch is CancellationError {
                return
            } catch {
                self.error = flError(error)
            }
            searching = false
        }
        .dorrDialog(isPresented: $confirmPublic) {
            ConfirmDialog(icon: "xmark", title: L("fl_inv_public"), message: L("fl_inv_public_confirm"), loading: busy,
                          onConfirm: { act { _ = try await FreelanceApi.makePublic(projectUuid); confirmPublic = false; onMadePublic() } },
                          onDismiss: { confirmPublic = false })
        }
    }

    @ViewBuilder
    private func content(_ d: FreelanceInvitationsDto) -> some View {
        let s = d.summary ?? FreelanceInvitationsSummaryDto()
        let invitations = d.invitations ?? []
        VStack(alignment: .leading, spacing: 12) {
            FlIntro(icon: "envelope.badge.fill", title: L("fl_inv_title"), text: L("fl_inv_how", s.max ?? 10))
            HStack(spacing: 8) {
                FlStat(icon: "paperplane.fill", label: L("fl_inv_s_sent"), value: "\(s.sent ?? 0)")
                FlStat(icon: "checkmark.circle.fill", label: L("fl_inv_s_accepted"), value: "\(s.accepted ?? 0)")
                FlStat(icon: "xmark.circle.fill", label: L("fl_inv_s_declined"), value: "\(s.declined ?? 0)")
            }
            if (s.declined ?? 0) > 0 && (s.accepted ?? 0) == 0 && (s.sent ?? 0) == 0 {
                FlNotice(text: L("fl_inv_none_left"), icon: "exclamationmark.triangle.fill", color: flAmber)
            }
            FlNotices(notice: notice, error: error)
            ForEach(invitations, id: \.uuid) { i in
                let c = inviteStatusColor(i.status)
                VStack(alignment: .leading, spacing: 8) {
                    HStack(spacing: 10) {
                        Image(systemName: "person.fill").font(.system(size: 18)).foregroundStyle(c)
                            .frame(width: 42, height: 42)
                            .background(c.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        VStack(alignment: .leading, spacing: 0) {
                            HStack(spacing: 6) {
                                Text(verbatim: i.freelancer?.name ?? "—").font(.dorr(14.5, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                                PlanBadge(badge: i.freelancer?.badge)
                            }
                            if let f = i.freelancer { Text(verbatim: inviteeLine(f)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut) }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        FlPill(text: inviteStatusLabel(i.status), color: c)
                    }
                    if let offer = i.offer { FlAction(text: L("fl_inv_see_offer"), icon: "doc.text.fill", tone: .primary) { onOpenOffer(offer) } }
                    if i.canWithdraw == true {
                        FlAction(text: L("fl_inv_withdraw"), icon: "arrow.uturn.backward", tone: .danger) {
                            act { data = try await FreelanceApi.withdrawInvitation(projectUuid, i.uuid).data }
                        }
                    }
                }
                .padding(14)
                .frame(maxWidth: .infinity, alignment: .leading)
                .settingsSurface(20)
            }
            if s.canInvite == true {
                if !picking {
                    FlAction(text: L("fl_inv_add"), icon: "person.badge.plus", tone: .primary) { picking = true }
                } else {
                    FlHeading(icon: "person.crop.circle.badge.questionmark", text: L("fl_inv_pick"))
                    DorrTextArea(value: Binding(get: { query }, set: { query = String($0.prefix(100)) }), placeholder: L("fl_inv_search"), icon: "magnifyingglass", singleLine: true)
                    HStack(spacing: 8) {
                        FlChip(text: L("fl_inv_same_specialty"), selected: !all, icon: "square.grid.2x2") { all = false }
                        FlChip(text: L("fl_inv_all"), selected: all, icon: "square.grid.2x2.fill") { all = true }
                    }
                    FlHint(text: L("fl_inv_order_hint"))
                    if searching {
                        ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 14).frame(height: 56) }
                    } else if found.isEmpty {
                        FlNotice(text: L("fl_inv_none_found"), icon: "info.circle.fill", color: wa.settingsMut)
                    }
                    ForEach(found, id: \.uuid) { f in
                        let on = ticked.contains(f.uuid)
                        Button {
                            if on { ticked.removeAll { $0 == f.uuid } } else { ticked.append(f.uuid) }
                        } label: {
                            HStack(spacing: 10) {
                                Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 20)).foregroundStyle(on ? wa.accent : wa.settingsMut)
                                VStack(alignment: .leading, spacing: 0) {
                                    HStack(spacing: 6) {
                                        Text(verbatim: f.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                                        PlanBadge(badge: f.badge)
                                    }
                                    Text(verbatim: inviteeLine(f)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                                }
                                Spacer(minLength: 0)
                            }
                            .padding(.horizontal, 12).padding(.vertical, 10)
                            .background(wa.accent.opacity(on ? 0.14 : 0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                    FlAction(text: L("fl_inv_send", ticked.count), icon: "paperplane.fill", tone: .primary, enabled: !ticked.isEmpty, loading: busy) {
                        let chosen = ticked
                        act {
                            data = try await FreelanceApi.invite(projectUuid, freelancers: chosen).data
                            ticked = []
                            picking = false
                            notice = L("fl_inv_sent")
                            reloads += 1
                        }
                    }
                    FlAction(text: L("fl_not_now")) { picking = false; ticked = [] }
                }
            }
            if s.canMakePublic == true {
                FlCard(icon: "globe", title: L("fl_inv_public_title")) {
                    Text(L("fl_inv_public_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                    FlAction(text: L("fl_inv_public"), icon: "globe", tone: .warn) { confirmPublic = true }
                }
            }
            Spacer(minLength: 20)
        }
    }
}
