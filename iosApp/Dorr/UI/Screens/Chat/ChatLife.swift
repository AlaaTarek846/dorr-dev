import SwiftUI

// androidApp `chat/ChatLifeUi.kt` — daily intelligence in the chat, batch A (spec 148, 149, 152, 154): «turn it into…»
// on any message, and the three cards it makes — a commitment (who does what, by when), a handoff (taken on by the one
// it's handed to), an agreement (terms every party approves; a record only). Nothing happens before the right person
// taps (AT-LIFE-01); the card follows every answer live. And «My commitments».

private let lifeAmber = Color(hex: 0xF59E0B)
private let lifeBlue = Color(hex: 0x2563EB)
private let lifeGreen = Color(hex: 0x16A34A)
private let lifePurple = Color(hex: 0x7C3AED)

/// «Thu 30 Oct · 14:00» in the app's language.
func lifeWhen(_ iso: String?) -> String? {
    guard let iso, let d = parseRideTime(iso) else { return nil }
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.dateFormat = "EEE d MMM · HH:mm"
    return f.string(from: d)
}

@MainActor private func statusText(_ status: String?) -> (String, Color) {
    switch status {
    case "proposed", "pending": return (L("lf_st_waiting"), lifeAmber)
    case "accepted": return (L("lf_st_accepted"), lifeBlue)
    case "agreed": return (L("lf_st_agreed"), lifeGreen)
    case "done": return (L("lf_st_done"), lifeGreen)
    case "declined": return (L("lf_st_declined"), Ch.shared.danger)
    default: return (L("lf_st_cancelled"), Ch.shared.soft)
    }
}

private struct StatusPill: View {
    let status: String?
    var body: some View {
        let (label, color) = statusText(status)
        Text(verbatim: label).font(.dorr(11, .heavy)).foregroundStyle(color).padding(.horizontal, 9).padding(.vertical, 3).background(color.opacity(0.12), in: Capsule())
    }
}

private struct CardHeader: View {
    let emoji: String
    let title: String
    let color: Color
    let status: String?
    var body: some View {
        HStack(spacing: 8) {
            Text(verbatim: emoji).font(.system(size: 15)).frame(width: 30, height: 30).background(color.opacity(0.14), in: RoundedRectangle(cornerRadius: 10))
            Text(verbatim: title).font(.dorr(12.5, .heavy)).foregroundStyle(color).frame(maxWidth: .infinity, alignment: .leading)
            StatusPill(status: status)
        }
    }
}

private struct PersonLine: View {
    @ObservedObject private var ch = Ch.shared
    let label: String
    let p: LifePersonDto?
    var body: some View {
        if let p {
            HStack(spacing: 6) {
                ChAvatar(url: p.avatar, name: p.name, key: p.key, size: 22)
                Text(verbatim: label).font(.dorr(12)).foregroundStyle(ch.mut)
                Text(verbatim: p.key == myKey() ? L("lf_you") : p.name ?? "").font(.dorr(12.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
            }
            .padding(.top, 6)
        }
    }
}

private struct CardButton: View {
    let text: String
    let color: Color
    let filled: Bool
    let action: () -> Void
    var body: some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(13, .heavy)).foregroundStyle(filled ? .white : color).lineLimit(1)
                .frame(maxWidth: .infinity).padding(.vertical, 9).padding(.horizontal, 10)
                .background(filled ? color : color.opacity(0.1), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

private struct SourceLink: View {
    @ObservedObject private var ch = Ch.shared
    let preview: String?
    let id: String?
    let onJump: (String) -> Void
    var body: some View {
        if let id {
            Button { onJump(id) } label: {
                HStack(spacing: 7) {
                    Capsule().fill(ch.red).frame(width: 3, height: 16)
                    Text(verbatim: preview ?? L("lf_source")).font(.dorr(11.5)).foregroundStyle(ch.mut).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                    Text(verbatim: "↗").font(.dorr(12, .bold)).foregroundStyle(ch.red)
                }
                .padding(.horizontal, 9).padding(.vertical, 6)
                .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 10))
            }
            .buttonStyle(.plain)
            .padding(.top, 8)
        }
    }
}

/// The card of a commitment, a handoff or an agreement, in the chat.
struct LifeCardBubble<Footer: View>: View {
    let dto: MessageDto
    let onJump: (String) -> Void
    @ViewBuilder let footer: () -> Footer

    var body: some View {
        let kind = dto.meta?.str("kind") ?? dto.type
        VStack(alignment: .leading, spacing: 0) {
            switch kind {
            case "commitment": if let c = dto.meta?.decode(LifeCommitmentDto.self) { CommitmentCard(initial: c, onJump: onJump) }
            case "handoff": if let h = dto.meta?.decode(HandoffDto.self) { HandoffCard(initial: h, onJump: onJump) }
            case "agreement": if let a = dto.meta?.decode(AgreementDto.self) { AgreementCard(initial: a, conversationId: dto.conversationId, onJump: onJump) }
            default: EmptyView()
            }
            footer()
        }
        .frame(minWidth: 250, alignment: .leading)
        .padding(12)
    }
}

private struct CommitmentCard: View {
    @EnvironmentObject private var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let initial: LifeCommitmentDto
    let onJump: (String) -> Void
    @State private var c: LifeCommitmentDto?
    @State private var busy = false

    var body: some View {
        let c = self.c ?? initial
        let me = myKey()
        let assignee = c.assignee?.key == me
        let creator = c.by?.key == me
        VStack(alignment: .leading, spacing: 0) {
            CardHeader(emoji: "📌", title: L("lf_commitment"), color: lifeAmber, status: c.status)
            Text(verbatim: c.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(ch.ink).padding(.top, 8)
            PersonLine(label: L("lf_on"), p: c.assignee)
            if let w = lifeWhen(c.dueAt) { Text(verbatim: "🗓️ \(w)").font(.dorr(12)).foregroundStyle(ch.mut).padding(.top, 4) }
            SourceLink(preview: c.source?.preview, id: c.source?.id, onJump: onJump)
            HStack(spacing: 6) {
                if assignee && c.status == "proposed" {
                    CardButton(text: L("lf_accept"), color: lifeGreen, filled: true) { act { try await LifeApi.respond(c.id, accept: true).data } }
                    CardButton(text: L("lf_decline"), color: ch.danger, filled: false) { act { try await LifeApi.respond(c.id, accept: false).data } }
                }
                if assignee && c.status == "accepted" { CardButton(text: L("lf_mark_done"), color: lifeGreen, filled: true) { act { try await LifeApi.done(c.id).data } } }
                if creator && ["proposed", "accepted"].contains(c.status ?? "") && !assignee {
                    CardButton(text: L("lf_cancel"), color: ch.mut, filled: false) { act { try await LifeApi.cancel(c.id).data } }
                }
            }
            .padding(.top, 10)
        }
        .onChange(of: initial) { self.c = $0 }
    }

    private func act(_ call: @escaping () async throws -> LifeCommitmentDto?) {
        guard !busy else { return }
        busy = true
        Task {
            do { if let r = try await call() { c = r } } catch { host.showToast(error.apiFailure.message ?? "", error: true) }
            busy = false
        }
    }
}

private struct HandoffCard: View {
    @EnvironmentObject private var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let initial: HandoffDto
    let onJump: (String) -> Void
    @State private var h: HandoffDto?
    @State private var busy = false

    var body: some View {
        let h = self.h ?? initial
        let me = myKey()
        let receiver = h.to?.key == me
        VStack(alignment: .leading, spacing: 0) {
            CardHeader(emoji: "🤝", title: L("lf_handoff"), color: lifeBlue, status: h.status)
            PersonLine(label: L("lf_from"), p: h.from)
            PersonLine(label: L("lf_to"), p: h.to)
            if let n = h.note { Text(verbatim: "“\(n)”").font(.dorr(13.5, .semibold)).foregroundStyle(ch.ink).padding(.top, 8) }
            SourceLink(preview: h.source?.preview, id: h.source?.id, onJump: onJump)
            HStack(spacing: 6) {
                if receiver && h.status == "pending" {
                    CardButton(text: L("lf_take_it"), color: lifeGreen, filled: true) { act(h.id, "accept") }
                    CardButton(text: L("lf_hand_back"), color: ch.danger, filled: false) { act(h.id, "decline") }
                }
                if receiver && h.status == "accepted" { CardButton(text: L("lf_mark_done"), color: lifeGreen, filled: true) { act(h.id, "done") } }
                if h.from?.key == me && h.status == "pending" { CardButton(text: L("lf_take_back"), color: ch.mut, filled: false) { act(h.id, "cancel") } }
            }
            .padding(.top, 10)
        }
        .onChange(of: initial) { self.h = $0 }
    }

    private func act(_ id: String, _ action: String) {
        guard !busy else { return }
        busy = true
        Task {
            do { if let r = try await LifeApi.moveHandoff(id, action: action).data { h = r } } catch { host.showToast(error.apiFailure.message ?? "", error: true) }
            busy = false
        }
    }
}

private struct AgreementCard: View {
    @EnvironmentObject private var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let initial: AgreementDto
    let conversationId: String
    let onJump: (String) -> Void
    @State private var a: AgreementDto?
    @State private var busy = false
    @State private var editing = false
    @State private var history = false

    var body: some View {
        let a = self.a ?? initial
        let me = myKey()
        let version = a.version ?? 1
        let myParty = (a.parties ?? []).first { $0.key == me }
        VStack(alignment: .leading, spacing: 0) {
            CardHeader(emoji: "📝", title: L("lf_agreement") + (version > 1 ? " · v\(version)" : ""), color: lifePurple, status: a.status)
            Text(verbatim: a.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(ch.ink).padding(.top, 8)
            VStack(spacing: 0) {
                ForEach(Array((a.terms ?? []).enumerated()), id: \.offset) { _, t in
                    HStack(alignment: .top) {
                        Text(verbatim: t.label).font(.dorr(12)).foregroundStyle(ch.mut).frame(maxWidth: .infinity, alignment: .leading)
                        Text(verbatim: t.value).font(.dorr(12.5, .bold)).foregroundStyle(ch.ink).frame(maxWidth: .infinity, alignment: .leading).layoutPriority(1)
                    }
                    .padding(.vertical, 3)
                }
            }
            .padding(10)
            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 12))
            .padding(.top, 8)
            // Who agreed to this version.
            FlowLayout(spacing: 6) {
                ForEach(Array((a.parties ?? []).enumerated()), id: \.offset) { _, p in
                    let (mark, color): (String, Color) = p.decision == "accepted" ? ("✓", lifeGreen) : p.decision == "declined" ? ("✕", ch.danger) : ("…", lifeAmber)
                    HStack(spacing: 5) {
                        ChAvatar(url: p.avatar, name: p.name, key: p.key, size: 20)
                        Text(verbatim: (p.key == me ? L("lf_you") : p.name ?? "") + " \(mark)").font(.dorr(11.5, .bold)).foregroundStyle(color).lineLimit(1)
                    }
                    .padding(.leading, 3).padding(.trailing, 9).padding(.vertical, 3)
                    .background(color.opacity(0.1), in: Capsule())
                }
            }
            .padding(.top, 8)
            Text(L("lf_record_only")).font(.dorr(10.5)).foregroundStyle(ch.soft).padding(.top, 6)
            SourceLink(preview: a.source?.preview, id: a.source?.id, onJump: onJump)
            if let myParty, a.status == "proposed", myParty.decision != "accepted" {
                HStack(spacing: 6) {
                    CardButton(text: L("lf_agree"), color: lifeGreen, filled: true) { act { try await LifeApi.answer(a.id, accept: true, version: version).data } }
                    CardButton(text: L("lf_disagree"), color: ch.danger, filled: false) { act { try await LifeApi.answer(a.id, accept: false, version: version).data } }
                }
                .padding(.top, 10)
            }
            HStack(spacing: 6) {
                if myParty != nil && ["proposed", "declined"].contains(a.status ?? "") { CardButton(text: L("lf_change_terms"), color: lifePurple, filled: false) { editing = true } }
                CardButton(text: L("lf_history"), color: ch.mut, filled: false) { history = true }
                if a.by?.key == me && a.status != "cancelled" { CardButton(text: L("lf_cancel"), color: ch.mut, filled: false) { act { try await LifeApi.cancelAgreement(a.id).data } } }
            }
            .padding(.top, 6)
        }
        .onChange(of: initial) { self.a = $0 }
        .dorrDialog(isPresented: $editing, bottom: true) {
            AgreementSheet(host: host, conversationId: conversationId, members: [], existing: a, source: nil, onDismiss: { editing = false }) { self.a = $0 }
        }
        .dorrDialog(isPresented: $history, bottom: true) { AgreementHistorySheet(id: a.id) }
    }

    private func act(_ call: @escaping () async throws -> AgreementDto?) {
        guard !busy else { return }
        busy = true
        Task {
            do { if let r = try await call() { a = r } } catch { host.showToast(error.apiFailure.message ?? "", error: true) }
            busy = false
        }
    }
}

// MARK: - «turn it into…» (148)

private struct ActionTile {
    let icon: String
    let label: String
    let color: Color
    let key: String
}

/// Every way a message becomes something to do — each keeps a link back to it.
struct ToActionSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject var state: ConversationState
    @ObservedObject private var ch = Ch.shared
    let message: MessageDto
    let onDismiss: () -> Void
    let onReminder: () -> Void
    @State private var open: String?

    var body: some View {
        let isGroup = state.conversation?.type == "group"
        Group {
            switch open {
            case "commitment": CommitmentSheet(host: host, state: state, message: message, onDismiss: onDismiss)
            case "handoff": HandoffSheet(host: host, state: state, message: message, onDismiss: onDismiss)
            case "agreement":
                AgreementSheet(host: host, conversationId: state.id, members: state.members.map { LifePersonDto(participantId: $0.participantId, key: $0.profile?.key, name: $0.profile?.name, avatar: $0.profile?.avatar) },
                               existing: nil, source: message, onDismiss: onDismiss) { _ in }
            case "calendar":
                ScheduleSheet(onDismiss: onDismiss) { at in
                    onDismiss()
                    Task {
                        let title = message.body.map { String($0.prefix(160)) }.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? "📅"
                        do {
                            _ = try await CalendarApi.create(["title": .string(title), "starts_at": .string(isoWithOffset(at)), "timezone": .string(TimeZone.current.identifier), "message_id": .string(message.id)])
                            host.showToast(L("lf_calendar_added"))
                        } catch { host.showToast(error.apiFailure.message ?? "", error: true) }
                    }
                }
            default: tiles(isGroup)
            }
        }
        .task(id: isGroup) { if isGroup { await state.loadMembers() } }
    }

    private func tiles(_ isGroup: Bool) -> some View {
        var tiles = [
            ActionTile(icon: "pin.fill", label: L("lf_commitment"), color: lifeAmber, key: "commitment"),
            ActionTile(icon: "figure.walk.arrival", label: L("lf_handoff"), color: lifeBlue, key: "handoff"),
            ActionTile(icon: "hands.sparkles.fill", label: L("lf_agreement"), color: lifePurple, key: "agreement"),
            ActionTile(icon: "checkmark.circle.fill", label: L("lf_task"), color: lifeGreen, key: "task"),
            ActionTile(icon: "calendar.badge.plus", label: L("lf_appointment"), color: Color(hex: 0x0891B2), key: "calendar"),
            ActionTile(icon: "alarm.fill", label: L("ch_reminder"), color: Color(hex: 0xEA580C), key: "reminder"),
        ]
        if isGroup && !["poll", "system"].contains(message.type) {
            tiles.append(ActionTile(icon: "hammer.fill", label: L("ch_make_decision"), color: Color(hex: 0x475569), key: "decision"))
        }
        return ChSheetCard {
            Text(L("lf_turn_into")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            Text(L("lf_turn_into_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut)
            if let b = message.body, !b.trimmingCharacters(in: .whitespaces).isEmpty {
                Text(verbatim: "“\(b.prefix(120))”").font(.dorr(13)).foregroundStyle(ch.ink).lineLimit(2).padding(.top, 8)
            }
            LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 8), count: 3), spacing: 8) {
                ForEach(tiles, id: \.key) { t in
                    Button { pick(t.key) } label: {
                        VStack(spacing: 6) {
                            Image(systemName: t.icon).font(.system(size: 22)).foregroundStyle(t.color)
                            Text(verbatim: t.label).font(.dorr(12.5, .bold)).foregroundStyle(ch.ink).multilineTextAlignment(.center).lineLimit(2)
                        }
                        .frame(maxWidth: .infinity).padding(.vertical, 14)
                        .background(t.color.opacity(0.08), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.top, 14)
        }
    }

    private func pick(_ key: String) {
        switch key {
        case "reminder":
            onDismiss()
            onReminder()
        case "decision":
            onDismiss()
            host.makeDecision(message)
        case "task":
            onDismiss()
            Task {
                let text = message.body.map { String($0.prefix(300)) }.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? "✅"
                do {
                    _ = try await AiToolsApi.createTasks([TaskSuggestionDto(text: text)], messageId: message.id)
                    host.showToast(L("lf_task_added"))
                } catch { host.showToast(error.apiFailure.message ?? "", error: true) }
            }
        default:
            withAnimation { open = key }
        }
    }
}

private struct LifeChip: View {
    @ObservedObject private var ch = Ch.shared
    let text: String
    let on: Bool
    let action: () -> Void
    var body: some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(on ? .white : ch.ink).lineLimit(1)
                .padding(.horizontal, 13).padding(.vertical, 8)
                .background(on ? ch.red : ch.surfaceMuted, in: Capsule())
                .overlay(Capsule().stroke(on ? ch.red : ch.line, lineWidth: 1))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - the three sheets

struct CommitmentSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject var state: ConversationState
    @ObservedObject private var ch = Ch.shared
    let message: MessageDto?
    let onDismiss: () -> Void
    @State private var title = ""
    /// nil = me; -1 = the other one (a direct chat); a participant id otherwise.
    @State private var who: Int?
    @State private var due: Date?
    @State private var picking = false
    @State private var remind = 60
    @State private var busy = false
    @State private var started = false

    var body: some View {
        let isGroup = state.conversation?.type == "group"
        if picking {
            ScheduleSheet(onDismiss: { picking = false }) { due = $0; picking = false }
        } else {
            ChSheetCard {
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: "📌 " + L("lf_commitment")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                        Text(L("lf_commitment_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut).padding(.bottom, 12)
                        ChField(placeholder: L("lf_what"), text: $title, lines: 2...5, limit: 300)
                        label(L("lf_who"))
                        FlowLayout(spacing: 6) {
                            LifeChip(text: L("lf_me"), on: who == nil) { who = nil }
                            if !isGroup { LifeChip(text: state.conversation?.peer?.name ?? L("lf_them"), on: who == -1) { who = -1 } }
                            ForEach(state.members.filter { $0.profile?.key != myKey() }) { m in LifeChip(text: m.profile?.name ?? "", on: who == m.participantId) { who = m.participantId } }
                        }
                        label(L("lf_by_when"))
                        HStack(spacing: 6) {
                            LifeChip(text: due.flatMap { lifeWhen(isoWithOffset($0)) } ?? L("lf_pick_time"), on: due != nil) { picking = true }
                            if due != nil { LifeChip(text: "✕", on: false) { due = nil } }
                        }
                        if due != nil {
                            label(L("lf_remind_before"))
                            FlowLayout(spacing: 6) {
                                ForEach([(0, "lf_no_reminder"), (15, "lf_15m"), (60, "lf_1h"), (1440, "lf_1d")], id: \.0) { m, l in LifeChip(text: L(l), on: remind == m) { remind = m } }
                            }
                        }
                        Text(L("lf_commitment_note")).font(.dorr(11.5)).foregroundStyle(ch.soft).padding(.top, 12)
                        ChPrimaryButton(text: L("lf_send"), enabled: !title.trimmingCharacters(in: .whitespaces).isEmpty && !busy) { send(isGroup) }.padding(.top, 14)
                    }
                }
                .frame(maxHeight: 640)
            }
            .onAppear {
                guard !started else { return }
                started = true
                title = String((message?.body ?? "").prefix(300))
                who = isGroup ? nil : -1
            }
            .task(id: isGroup) { if isGroup { await state.loadMembers() } }
        }
    }

    private func label(_ text: String) -> some View {
        Text(verbatim: text).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).padding(.top, 14).padding(.bottom, 6)
    }

    private func send(_ isGroup: Bool) {
        busy = true
        Task {
            var body: [String: JSONValue] = ["title": .string(title.trimmingCharacters(in: .whitespacesAndNewlines))]
            switch who {
            case nil:
                // «Me» means me: in a direct chat the server's default is the other one.
                if let mine = await myParticipantId() { body["assignee"] = .int(mine) } else if !isGroup { body["assignee"] = .int(0) }
            case -1: break
            case let w?: body["assignee"] = .int(w)
            }
            if let due {
                body["due_at"] = .string(isoWithOffset(due))
                if remind > 0 { body["remind_minutes"] = .int(remind) }
            }
            do {
                if let message { _ = try await LifeApi.commitFromMessage(message.id, body) } else { _ = try await LifeApi.commit(state.id, body) }
                host.showToast(L("lf_commitment_sent"))
                onDismiss()
            } catch { host.showToast(error.apiFailure.message ?? "", error: true) }
            busy = false
        }
    }

    /// My own participant id in this chat (members list, else asked for).
    private func myParticipantId() async -> Int? {
        if let m = state.members.first(where: { $0.profile?.key == myKey() }) { return m.participantId }
        return (try? await ChatApi.members(state.id).data)?.first { $0.profile?.key == myKey() }?.participantId
    }
}

struct HandoffSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject var state: ConversationState
    @ObservedObject private var ch = Ch.shared
    let message: MessageDto
    let onDismiss: () -> Void
    @State private var to: Int?
    @State private var outside: Int?
    @State private var picking = false
    @State private var note = ""
    @State private var busy = false

    var body: some View {
        if picking {
            PeoplePickerSheet(initial: [], onDismiss: { picking = false }) { ids in
                picking = false
                if let first = ids.first { outside = first; to = nil }
            }
        } else {
            ChSheetCard {
                Text(verbatim: "🤝 " + L("lf_handoff")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                Text(L("lf_handoff_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut)
                Text(L("lf_to_whom")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).padding(.top, 14).padding(.bottom, 6)
                FlowLayout(spacing: 6) {
                    ForEach(state.members.filter { $0.profile?.key != myKey() }) { m in LifeChip(text: m.profile?.name ?? "", on: to == m.participantId) { to = m.participantId; outside = nil } }
                    LifeChip(text: outside != nil ? "👤 " + L("lf_someone_picked") : "＋ " + L("lf_someone_else"), on: outside != nil) { picking = true }
                }
                if outside != nil { Text(L("lf_forward_note")).font(.dorr(11.5)).foregroundStyle(ch.soft).padding(.top, 6) }
                ChField(placeholder: L("lf_handoff_note"), text: $note, lines: 2...5, limit: 500).padding(.top, 12)
                ChPrimaryButton(text: L("lf_hand_it"), enabled: (to != nil || outside != nil) && !busy, action: send).padding(.top, 14)
            }
            .task { if state.conversation?.type == "group" { await state.loadMembers() } }
        }
    }

    private func send() {
        busy = true
        Task {
            var body: [String: JSONValue] = [:]
            if let to { body["to_participant_id"] = .int(to) } else if let outside { body["to_user_id"] = .int(outside) }
            let n = note.trimmingCharacters(in: .whitespacesAndNewlines)
            if !n.isEmpty { body["note"] = .string(n) }
            do {
                _ = try await LifeApi.handOff(message.id, body)
                host.showToast(L("lf_handoff_sent"))
                onDismiss()
            } catch { host.showToast(error.apiFailure.message ?? "", error: true) }
            busy = false
        }
    }
}

/// Propose an agreement — or, with `existing`, change its terms (a new version).
struct AgreementSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    let members: [LifePersonDto]
    let existing: AgreementDto?
    let source: MessageDto?
    let onDismiss: () -> Void
    let onSaved: (AgreementDto) -> Void
    @State private var title = ""
    @State private var terms: [(String, String)] = []
    @State private var parties: [Int] = []
    @State private var busy = false
    @State private var started = false

    private var presets: [String] { ["lf_t_scope", "lf_t_date", "lf_t_price", "lf_t_place", "lf_t_notes"].map { L($0) } }

    var body: some View {
        let ready = !title.trimmingCharacters(in: .whitespaces).isEmpty && terms.contains { !$0.0.trimmingCharacters(in: .whitespaces).isEmpty && !$0.1.trimmingCharacters(in: .whitespaces).isEmpty }
            && (existing != nil || members.isEmpty || !parties.isEmpty)
        ChSheetCard {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: "📝 " + L(existing == nil ? "lf_agreement" : "lf_change_terms")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                    Text(L(existing == nil ? "lf_agreement_sub" : "lf_change_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut).padding(.bottom, 12)
                    ChField(placeholder: L("lf_agreement_title"), text: $title, limit: 200)
                    Text(L("lf_terms")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).padding(.top, 14).padding(.bottom, 6)
                    ForEach(terms.indices, id: \.self) { i in
                        HStack(spacing: 6) {
                            ChField(placeholder: L("lf_term_label"), text: Binding(get: { terms.indices.contains(i) ? terms[i].0 : "" }, set: { if terms.indices.contains(i) { terms[i].0 = String($0.prefix(60)) } }), limit: 60)
                                .frame(maxWidth: .infinity)
                            ChField(placeholder: L("lf_term_value"), text: Binding(get: { terms.indices.contains(i) ? terms[i].1 : "" }, set: { if terms.indices.contains(i) { terms[i].1 = String($0.prefix(500)) } }), limit: 500)
                                .frame(maxWidth: .infinity).layoutPriority(1)
                            Button { terms.remove(at: i) } label: { Image(systemName: "xmark").font(.system(size: 14, weight: .bold)).foregroundStyle(ch.soft) }.buttonStyle(.plain)
                        }
                        .padding(.bottom, 6)
                    }
                    FlowLayout(spacing: 6) {
                        ForEach(presets.filter { p in !terms.contains { $0.0 == p } }, id: \.self) { p in LifeChip(text: "＋ \(p)", on: false) { if terms.count < 12 { terms.append((p, "")) } } }
                        LifeChip(text: "＋ " + L("lf_term_other"), on: false) { if terms.count < 12 { terms.append(("", "")) } }
                    }
                    if existing == nil && !members.isEmpty {
                        Text(L("lf_who_agrees")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).padding(.top, 14).padding(.bottom, 6)
                        FlowLayout(spacing: 6) {
                            ForEach(Array(members.filter { $0.key != myKey() }.enumerated()), id: \.offset) { _, m in
                                let id = m.participantId ?? 0
                                LifeChip(text: m.name ?? "", on: parties.contains(id)) { if parties.contains(id) { parties.removeAll { $0 == id } } else { parties.append(id) } }
                            }
                        }
                    }
                    Text(L("lf_record_only")).font(.dorr(11.5)).foregroundStyle(ch.soft).padding(.top, 12)
                    ChPrimaryButton(text: L(existing == nil ? "lf_send" : "lf_save_version"), enabled: ready && !busy, action: send).padding(.top, 14)
                }
            }
            .frame(maxHeight: 660)
        }
        .onAppear {
            guard !started else { return }
            started = true
            title = existing?.title ?? String((source?.body ?? "").prefix(120))
            terms = (existing?.terms ?? []).map { ($0.label, $0.value) }
            if terms.isEmpty { terms = [(presets[0], ""), (presets[1], "")] }
        }
    }

    private func send() {
        busy = true
        Task {
            var body: [String: JSONValue] = [
                "title": .string(title.trimmingCharacters(in: .whitespaces)),
                "terms": .array(terms.filter { !$0.0.trimmingCharacters(in: .whitespaces).isEmpty && !$0.1.trimmingCharacters(in: .whitespaces).isEmpty }
                    .map { .object(["label": .string($0.0.trimmingCharacters(in: .whitespaces)), "value": .string($0.1.trimmingCharacters(in: .whitespaces))]) }),
            ]
            if existing == nil && !parties.isEmpty { body["parties"] = .array(parties.map { .int($0) }) }
            if let source { body["source"] = .string(source.id) }
            do {
                let saved = existing == nil ? try await LifeApi.propose(conversationId, body).data : try await LifeApi.amend(existing!.id, body).data
                if let saved { onSaved(saved) }
                host.showToast(L("lf_agreement_sent"))
                onDismiss()
            } catch { host.showToast(error.apiFailure.message ?? "", error: true) }
            busy = false
        }
    }
}

/// An agreement's whole history: who proposed, changed, agreed, declined.
private struct AgreementHistorySheet: View {
    @ObservedObject private var ch = Ch.shared
    let id: String
    @State private var a: AgreementDto?

    var body: some View {
        ChSheetCard {
            Text(L("lf_history")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 10)
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    ForEach(Array((a?.log ?? []).enumerated()), id: \.offset) { _, l in
                        HStack(alignment: .top, spacing: 10) {
                            Circle().fill(l.action == "accepted" ? lifeGreen : l.action == "declined" ? ch.danger : lifePurple).frame(width: 10, height: 10).padding(.top, 4)
                            VStack(alignment: .leading, spacing: 1) {
                                Text(verbatim: [l.by?.name, logLabel(l.action)].compactMap { $0 }.joined(separator: " · ")).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink)
                                if let v = l.data?.at("version")?.dbl { Text(verbatim: "v\(Int(v))").font(.dorr(11.5)).foregroundStyle(ch.mut) }
                                if let w = lifeWhen(l.at) { Text(verbatim: w).font(.dorr(11)).foregroundStyle(ch.soft) }
                            }
                        }
                        .padding(.vertical, 6)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .frame(maxHeight: 520)
        }
        .task { a = try? await LifeApi.agreement(id).data }
    }

    private func logLabel(_ action: String?) -> String {
        switch action {
        case "proposed", "created": return L("lf_log_proposed")
        case "amended": return L("lf_log_amended")
        case "accepted": return L("lf_st_accepted")
        case "declined": return L("lf_st_declined")
        case "done": return L("lf_st_done")
        default: return L("lf_st_cancelled")
        }
    }
}

// MARK: - my commitments page

/// My commitments and handoffs: on me, and what I'm waiting for from others. Pages of 15.
struct CommitmentsPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var tab = 0
    @State private var commitments: [LifeCommitmentDto]?
    @State private var handoffs: [HandoffDto] = []
    @State private var cPage = 1
    @State private var hPage = 1
    @State private var cHasMore = false
    @State private var hHasMore = false
    @State private var loadingMore = false
    @State private var query = ""

    var body: some View {
        ChSettingsPage(title: L("lf_my_commitments"), onBack: { host.pop() }) {
            ChField(placeholder: L("lf_search"), text: $query, icon: "magnifyingglass", limit: 80).padding(.horizontal, 16).padding(.vertical, 4)
            ScrollView {
                LazyVStack(spacing: 0) {
                    HStack(spacing: 0) {
                        ForEach(Array(["lf_tab_on_me", "lf_tab_waiting"].enumerated()), id: \.offset) { i, l in
                            Button { tab = i } label: {
                                Text(L(l)).font(.dorr(13.5, .bold)).foregroundStyle(tab == i ? .white : ch.ink).frame(maxWidth: .infinity).padding(.vertical, 10)
                                    .background(tab == i ? ch.red : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .padding(4)
                    .background(ch.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .padding(.top, 8).padding(.bottom, 12)
                    if let cs = commitments {
                        if cs.isEmpty && handoffs.isEmpty {
                            ChEmptyState(icon: "person.text.rectangle", title: L("lf_empty_title"), text: L(tab == 0 ? "lf_empty_on_me" : "lf_empty_waiting")).frame(height: 320)
                        }
                        ForEach(Array(cs.enumerated()), id: \.element.id) { i, c in
                            LifeRow(emoji: "📌", title: c.title ?? "", sub: [tab == 0 ? c.by?.name : c.assignee?.name, lifeWhen(c.dueAt)].compactMap { $0 }.joined(separator: " · "),
                                    status: c.status, conversationId: c.conversation?.id) { host.push(.conversation($0)) }
                                .onAppear { if i >= cs.count - 3 { Task { await more() } } }
                        }
                        ForEach(Array(handoffs.enumerated()), id: \.element.id) { i, h in
                            LifeRow(emoji: "🤝", title: h.note ?? h.source?.preview ?? L("lf_handoff"), sub: (tab == 0 ? h.from?.name : h.to?.name) ?? "",
                                    status: h.status, conversationId: h.conversation?.id) { host.push(.conversation($0)) }
                                .onAppear { if i >= handoffs.count - 3 { Task { await more() } } }
                        }
                    } else {
                        ForEach(0..<3, id: \.self) { _ in RoundedRectangle(cornerRadius: 18).fill(ch.surfaceMuted).frame(height: 70).padding(.bottom, 8) }
                    }
                    if loadingMore { ProgressView().tint(ch.red).padding(16) }
                }
                .padding(.horizontal, 16).padding(.bottom, 24)
            }
        }
        .task(id: "\(tab)|\(query)") {
            if !query.trimmingCharacters(in: .whitespaces).isEmpty { try? await Task.sleep(nanoseconds: 280_000_000) }
            guard !Task.isCancelled else { return }
            commitments = nil
            handoffs = []
            await loadCommitments(reset: true)
            await loadHandoffs(reset: true)
        }
    }

    private var q: String? { let t = query.trimmingCharacters(in: .whitespaces); return t.isEmpty ? nil : t }

    private func loadCommitments(reset: Bool) async {
        if !reset && (loadingMore || !cHasMore) { return }
        if !reset { loadingMore = true }
        let next = reset ? 1 : cPage + 1
        let envelope = try? await LifeApi.commitments(scope: tab == 0 ? "mine" : "theirs", page: next, q: q)
        let chunk = envelope?.data ?? []
        if reset { commitments = chunk } else {
            let known = Set((commitments ?? []).map(\.id))
            commitments = (commitments ?? []) + chunk.filter { !known.contains($0.id) }
        }
        cPage = next
        cHasMore = envelope?.pagination?.hasMorePages == true
        if !reset { loadingMore = false }
    }

    private func loadHandoffs(reset: Bool) async {
        if !reset && (loadingMore || !hHasMore) { return }
        if !reset { loadingMore = true }
        let next = reset ? 1 : hPage + 1
        let envelope = try? await LifeApi.handoffs(scope: tab == 0 ? "received" : "sent", page: next, q: q)
        let chunk = envelope?.data ?? []
        if reset { handoffs = chunk } else {
            let known = Set(handoffs.map(\.id))
            handoffs += chunk.filter { !known.contains($0.id) }
        }
        hPage = next
        hHasMore = envelope?.pagination?.hasMorePages == true
        if !reset { loadingMore = false }
    }

    private func more() async { if cHasMore { await loadCommitments(reset: false) } else { await loadHandoffs(reset: false) } }
}

private struct LifeRow: View {
    @ObservedObject private var ch = Ch.shared
    let emoji: String
    let title: String
    let sub: String
    let status: String?
    let conversationId: String?
    let onOpen: (String) -> Void

    var body: some View {
        Button { if let c = conversationId { onOpen(c) } } label: {
            HStack(spacing: 12) {
                Text(verbatim: emoji).font(.system(size: 18)).frame(width: 40, height: 40)
                    .background(LinearGradient(colors: [lifeAmber.opacity(0.2), lifePurple.opacity(0.15)], startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 13))
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: title).font(.dorr(14.5, .bold)).foregroundStyle(ch.ink).lineLimit(2).multilineTextAlignment(.leading)
                    if !sub.isEmpty { Text(verbatim: sub).font(.dorr(12)).foregroundStyle(ch.mut).lineLimit(1) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                StatusPill(status: status)
            }
            .padding(14)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(.plain)
        .disabled(conversationId == nil)
        .padding(.bottom, 8)
    }
}
