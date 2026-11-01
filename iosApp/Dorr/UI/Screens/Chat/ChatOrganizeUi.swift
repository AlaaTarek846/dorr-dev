import PhotosUI
import SwiftUI

// androidApp `chat/ChatOrganizeUi.kt` — organising chats (docs/remaining_chat.md): threads (spec 122), group decisions
// (119–120), privacy circles (98–103) and broadcast lists (like WhatsApp's).

// MARK: - threads

/// A thread: the message it hangs from, its replies, and a box to answer in it. New replies arrive live.
struct ThreadPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    let rootId: String
    @State private var root: MessageDto?
    @State private var replies: [MessageDto] = []
    @State private var text = ""
    @State private var sending = false

    var body: some View {
        ChPage(title: L("ch_thread"), onBack: { host.pop() }) {
            VStack(spacing: 0) {
                ScrollViewReader { proxy in
                    ScrollView {
                        LazyVStack(spacing: 8) {
                            if let r = root {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(verbatim: r.sender?.name ?? "").font(.dorr(13, .heavy)).foregroundStyle(ch.red)
                                    Text(verbatim: r.body ?? L("ch_attachment")).font(.dorr(15)).foregroundStyle(ch.ink)
                                    Text(verbatim: clockTime(r.createdAt)).font(.dorr(11)).foregroundStyle(ch.soft).frame(maxWidth: .infinity, alignment: .trailing)
                                }
                                .padding(14)
                                .background(ch.red.opacity(0.07), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                                Text(L("ch_thread_replies", replies.count)).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut)
                                    .frame(maxWidth: .infinity, alignment: .leading).padding(.vertical, 4)
                            }
                            ForEach(replies, id: \.id) { m in ThreadReply(m: m).id(m.id) }
                        }
                        .padding(14)
                    }
                    .onChange(of: replies.count) { _ in
                        if let last = replies.last { withAnimation { proxy.scrollTo(last.id, anchor: .bottom) } }
                    }
                }
                HStack(spacing: 8) {
                    ChField(placeholder: L("ch_thread_reply_hint"), text: $text, lines: 1...4, limit: 4000)
                    Button(action: send) {
                        Image(systemName: "paperplane.fill").font(.system(size: 19)).foregroundStyle(.white)
                            .frame(width: 46, height: 46).background(text.trimmingCharacters(in: .whitespaces).isEmpty || sending ? ch.soft : ch.red, in: Circle())
                    }
                    .buttonStyle(.plain)
                    .disabled(text.trimmingCharacters(in: .whitespaces).isEmpty || sending)
                }
                .padding(10)
                .background(ch.surface)
            }
        }
        .task {
            if let page = try? await OrganizeApi.thread(conversationId, root: rootId).data {
                root = page.root
                replies = page.messages ?? []
            }
        }
        .onReceive(ChatRealtime.shared.events) { e in
            guard e.name == "chat.message.sent", e.str("conversation_id") == conversationId,
                  let dto = e.data.at("message")?.decode(MessageDto.self), dto.threadRoot == rootId, !replies.contains(where: { $0.id == dto.id }) else { return }
            replies.append(dto)
        }
    }

    private func send() {
        let body = text.trimmingCharacters(in: .whitespacesAndNewlines)
        sending = true
        Task {
            do {
                let sent = try await ChatApi.send(conversationId, ["type": "text", "body": body, "thread": rootId]).data
                text = ""
                if let sent, !replies.contains(where: { $0.id == sent.id }) { replies.append(sent) }
            } catch {
                host.showToast(error.apiFailure.message ?? "", error: true)
            }
            sending = false
        }
    }
}

private struct ThreadReply: View {
    @ObservedObject private var ch = Ch.shared
    let m: MessageDto

    var body: some View {
        let mine = m.sender?.key == myKey()
        HStack(alignment: .bottom, spacing: 6) {
            if mine { Spacer(minLength: 40) } else { ChAvatar(url: m.sender?.avatar, name: m.sender?.name, key: m.sender?.key, size: 30) }
            VStack(alignment: .leading, spacing: 1) {
                if !mine { Text(verbatim: m.sender?.name ?? "").font(.dorr(12, .bold)).foregroundStyle(ch.red) }
                Text(verbatim: m.body ?? L("ch_attachment")).font(.dorr(14.5)).foregroundStyle(mine ? .white : ch.ink)
                Text(verbatim: clockTime(m.createdAt)).font(.dorr(10.5)).foregroundStyle(mine ? .white.opacity(0.7) : ch.soft).frame(maxWidth: .infinity, alignment: .trailing)
            }
            .padding(.horizontal, 12).padding(.vertical, 8)
            .frame(maxWidth: 290, alignment: .leading)
            .background(mine ? ch.red : ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .fixedSize(horizontal: false, vertical: true)
            if !mine { Spacer(minLength: 40) }
        }
    }
}

// MARK: - decisions

extension ChatHost {
    /// «Make it a decision»: a vote answering this message (agree / disagree), in a group.
    func makeDecision(_ message: MessageDto) {
        Task {
            do {
                _ = try await OrganizeApi.makeDecision(message.id)
                showToast(L("ch_decision_made"))
            } catch {
                showToast(error.apiFailure.message ?? "", error: true)
            }
        }
    }
}

/// The group's decisions log: approved ones with their outcome and date, open ones with their votes.
struct DecisionsPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    let isAdmin: Bool
    @State private var rows: [DecisionDto]?

    var body: some View {
        ChPage(title: L("ch_decisions"), onBack: { host.pop() }) {
            if let list = rows {
                if list.isEmpty {
                    ChEmptyState(icon: "hammer.fill", title: L("ch_decisions_empty"), text: L("ch_decisions_empty_text"))
                } else {
                    ScrollView {
                        LazyVStack(spacing: 10) {
                            ForEach(Array(list.enumerated()), id: \.element.id) { i, d in
                                // A tap opens its room: arguments for and against (spec 153).
                                DecisionCard(d: d, isAdmin: isAdmin) { approve in
                                    Task {
                                        do { _ = try await OrganizeApi.decide(d.id, approve: approve) } catch { host.showToast(error.apiFailure.message ?? "", error: true) }
                                        await load()
                                    }
                                }
                                .chStagger(i)
                                .onTapGesture { host.push(.decisionRoom(d.id)) }
                            }
                        }
                        .padding(14)
                    }
                }
            } else {
                VStack(spacing: 10) { ForEach(0..<3, id: \.self) { _ in WaSkeleton().frame(height: 110).clipShape(RoundedRectangle(cornerRadius: 20)) } }.padding(14)
                Spacer()
            }
        }
        .task { await load() }
    }

    private func load() async { rows = (try? await OrganizeApi.decisions(conversationId).data) ?? [] }
}

private struct DecisionCard: View {
    @ObservedObject private var ch = Ch.shared
    let d: DecisionDto
    let isAdmin: Bool
    let onDecide: (Bool) -> Void

    var body: some View {
        let (tint, label): (Color, String) = {
            switch d.status {
            case "approved": return (Color(hex: 0x16A34A), L("ch_decision_approved"))
            case "rejected": return (ch.danger, L("ch_decision_rejected"))
            default: return (Color(hex: 0xF59E0B), L("ch_decision_open"))
            }
        }()
        let options = d.options ?? []
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 8) {
                Image(systemName: d.status == "open" ? "checkmark.square.fill" : "hammer.fill").font(.system(size: 17)).foregroundStyle(tint)
                Text(verbatim: label).font(.dorr(12.5, .heavy)).foregroundStyle(tint).frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: listTime(d.decidedAt ?? d.createdAt)).font(.dorr(11.5)).foregroundStyle(ch.soft)
            }
            Text(verbatim: d.title).font(.dorr(15, .bold)).foregroundStyle(ch.ink).padding(.top, 6)
            if let o = d.outcome { Text(verbatim: "→ \(o)").font(.dorr(14, .heavy)).foregroundStyle(tint).padding(.top, 4) }
            if !options.isEmpty {
                let total = max(options.reduce(0) { $0 + ($1.votes ?? 0) }, 1)
                VStack(spacing: 4) {
                    ForEach(options) { o in
                        HStack(spacing: 4) {
                            Text(verbatim: o.text).font(.dorr(13)).foregroundStyle(ch.ink).lineLimit(1).frame(width: 110, alignment: .leading)
                            GeometryReader { g in
                                ZStack(alignment: .leading) {
                                    Capsule().fill(ch.surfaceMuted)
                                    Capsule().fill(tint).frame(width: g.size.width * CGFloat(o.votes ?? 0) / CGFloat(total))
                                }
                            }
                            .frame(height: 8)
                            Text(verbatim: " \(o.votes ?? 0)").font(.dorr(12, .bold)).foregroundStyle(ch.mut)
                        }
                    }
                }
                .padding(.top, 8)
            }
            if let who = d.decidedBy?.name ?? d.createdBy?.name {
                Text(L(d.decidedBy != nil ? "ch_decision_by" : "ch_decision_proposed_by", who)).font(.dorr(11.5)).foregroundStyle(ch.soft).padding(.top, 6)
            }
            if isAdmin && d.status == "open" {
                HStack(spacing: 8) {
                    ChPrimaryButton(text: L("ch_decision_approve"), icon: "checkmark") { onDecide(true) }
                    Button { onDecide(false) } label: {
                        Text(L("ch_decision_reject")).font(.dorr(15, .heavy)).foregroundStyle(ch.danger)
                            .frame(maxWidth: .infinity).frame(height: 46).background(ch.danger.opacity(0.1), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    .buttonStyle(.plain)
                }
                .padding(.top, 10)
            }
        }
        .padding(14)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .contentShape(Rectangle())
    }
}

// MARK: - circles

extension ChatHost {
    func refreshCircles() async {
        if let c = try? await OrganizeApi.circles().data { circles = c }
    }
}

/// P0 … P4, in words.
func disclosureLabel(_ level: String?) -> String {
    switch level {
    case "all": return L("ch_disclosure_all")
    case "name": return L("ch_disclosure_name")
    case "none": return L("ch_disclosure_none")
    case "hidden": return L("ch_disclosure_hidden")
    default: return L("ch_disclosure_circle")
    }
}

/// Which circle a chat sits in (or none) — and a new circle from here.
struct CirclePickerSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let conversation: ConversationDto
    let onDismiss: () -> Void
    @State private var creating = false

    var body: some View {
        if creating {
            CircleEditorSheet(host: host, circle: nil, onDismiss: { creating = false; onDismiss() }) { created in
                if let created { assign(created.id) }
            }
        } else {
            ChSheetCard {
                Text(L("ch_circle_pick")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                Text(L("ch_circle_pick_sub")).font(.dorr(13)).foregroundStyle(ch.mut).padding(.bottom, 12)
                ForEach(host.circles) { c in
                    Button { assign(c.id) } label: {
                        HStack(spacing: 12) {
                            Text(verbatim: c.emoji ?? "⭕").font(.system(size: 19)).frame(width: 40, height: 40).background(ch.red.opacity(0.1), in: Circle())
                            VStack(alignment: .leading, spacing: 0) {
                                HStack(spacing: 4) {
                                    Text(verbatim: c.name).font(.dorr(15, .bold)).foregroundStyle(ch.ink)
                                    if c.locked == true { Image(systemName: "lock.fill").font(.system(size: 12)).foregroundStyle(ch.mut) }
                                    if c.hideFromList != false { Image(systemName: "eye.slash.fill").font(.system(size: 12)).foregroundStyle(ch.mut) }
                                }
                                Text(verbatim: disclosureLabel(c.disclosure)).font(.dorr(12)).foregroundStyle(ch.mut)
                            }
                            Spacer()
                            if conversation.circle?.id == c.id { Image(systemName: "checkmark.circle.fill").font(.system(size: 21)).foregroundStyle(ch.red) }
                        }
                        .padding(.vertical, 10).padding(.horizontal, 4).contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
                if conversation.circle != nil { plainRow("xmark", L("ch_circle_none")) { assign(nil) } }
                plainRow("plus", L("ch_circle_new")) { creating = true }
            }
            .task { await host.refreshCircles() }
        }
    }

    private func plainRow(_ icon: String, _ label: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 16, weight: .semibold)).foregroundStyle(ch.mut).frame(width: 40, height: 40).background(ch.surfaceMuted, in: Circle())
                Text(verbatim: label).font(.dorr(15, .bold)).foregroundStyle(ch.ink)
                Spacer()
            }
            .padding(.vertical, 12).padding(.horizontal, 4).contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    private func assign(_ id: String?) {
        onDismiss()
        Task {
            do {
                let updated = try await OrganizeApi.setCircle(conversation.id, circleId: id).data
                if let updated { host.upsert(updated) }
                // A circle that hides its chats takes this one out of the main list.
                if updated?.circle?.hideFromList == true && !host.filter.hasPrefix("circle:") { host.remove(conversation.id) }
                await host.refreshCircles()
            } catch {
                host.showToast(error.apiFailure.message ?? "", error: true)
            }
        }
    }
}

/// A circle's settings: name, stand-in name and emoji, what its notifications show, hide, lock.
struct CircleEditorSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let circle: CircleDto?
    let onDismiss: () -> Void
    let onSaved: (CircleDto?) -> Void
    @State private var name = ""
    @State private var masked = ""
    @State private var emoji = "⭐"
    @State private var disclosure = "circle"
    @State private var hide = true
    @State private var locked = false
    @State private var busy = false
    @State private var started = false

    private static let levels = ["all", "name", "circle", "none", "hidden"]

    var body: some View {
        ChSheetCard {
            ScrollView {
                VStack(alignment: .leading, spacing: 8) {
                    Text(L(circle == nil ? "ch_circle_new" : "ch_circle_edit")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                    Text(L("ch_circle_sub")).font(.dorr(13)).foregroundStyle(ch.mut).padding(.bottom, 4)
                    HStack(spacing: 8) {
                        ChField(placeholder: "⭐", text: $emoji, limit: 4).frame(width: 76)
                        ChField(placeholder: L("ch_circle_name"), text: $name, limit: 60)
                    }
                    ChField(placeholder: L("ch_circle_masked"), text: $masked, limit: 60)
                    Text(L("ch_circle_masked_sub")).font(.dorr(11.5)).foregroundStyle(ch.soft).padding(.leading, 6)
                    Text(L("ch_circle_notifications")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).padding(.top, 6)
                    ForEach(Array(Self.levels.enumerated()), id: \.offset) { i, level in
                        let on = disclosure == level
                        Button { disclosure = level } label: {
                            HStack {
                                Text(verbatim: "P\(i)").font(.dorr(13, .heavy)).foregroundStyle(on ? ch.red : ch.soft).frame(width: 30, alignment: .leading)
                                Text(verbatim: disclosureLabel(level)).font(.dorr(14)).foregroundStyle(ch.ink).frame(maxWidth: .infinity, alignment: .leading)
                                if on { Image(systemName: "checkmark.circle.fill").font(.system(size: 19)).foregroundStyle(ch.red) }
                            }
                            .padding(10)
                            .background(on ? ch.red.opacity(0.1) : .clear, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .animation(.easeOut(duration: 0.2), value: on)
                    }
                    ToggleRow(icon: "eye.slash.fill", title: L("ch_circle_hide"), checked: hide, subtitle: L("ch_circle_hide_sub")) { hide = $0 }
                    ToggleRow(icon: "lock.fill", title: L("ch_circle_lock"), checked: locked, subtitle: L("ch_circle_lock_sub")) { locked = $0 }
                    ChPrimaryButton(text: L("ch_save"), enabled: !name.trimmingCharacters(in: .whitespaces).isEmpty && !busy, action: save).padding(.top, 6)
                    if let circle {
                        Button { delete(circle) } label: {
                            Text(L("ch_circle_delete")).font(.dorr(15, .bold)).foregroundStyle(ch.danger).frame(maxWidth: .infinity).padding(12)
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
            .frame(maxHeight: 660)
        }
        .onAppear {
            guard !started else { return }
            started = true
            name = circle?.name ?? ""
            masked = circle?.maskedName ?? ""
            emoji = circle?.emoji ?? "⭐"
            disclosure = circle?.disclosure ?? "circle"
            hide = circle?.hideFromList ?? true
            locked = circle?.locked ?? false
        }
    }

    private func save() {
        busy = true
        let body: [String: JSONValue] = [
            "name": .string(name.trimmingCharacters(in: .whitespaces)), "masked_name": .string(masked.trimmingCharacters(in: .whitespaces)),
            "emoji": .string(emoji.trimmingCharacters(in: .whitespaces)), "disclosure": .string(disclosure), "hide_from_list": .bool(hide), "locked": .bool(locked),
        ]
        Task {
            do {
                let saved = try await (circle == nil ? OrganizeApi.createCircle(body) : OrganizeApi.updateCircle(circle!.id, body)).data
                await host.refreshCircles()
                await host.refreshList()
                onSaved(saved)
                onDismiss()
            } catch {
                host.showToast(error.apiFailure.message ?? "", error: true)
                busy = false
            }
        }
    }

    private func delete(_ c: CircleDto) {
        Task {
            _ = try? await OrganizeApi.deleteCircle(c.id)
            if host.filter == "circle:\(c.id)" { host.filter = "all" }
            await host.refreshCircles()
            await host.refreshList()
            onSaved(nil)
            onDismiss()
        }
    }
}

// MARK: - broadcast lists

/// My broadcast lists (like WhatsApp's) and a new one — or one from a folder.
struct BroadcastsPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var lists: [BroadcastDto]?
    @State private var creating = false
    @State private var fromFolder = false

    var body: some View {
        ChPage(title: L("ch_broadcasts"), onBack: { host.pop() }) {
            ScrollView {
                LazyVStack(spacing: 8) {
                    Button { creating = true } label: {
                        HStack(spacing: 12) {
                            Image(systemName: "megaphone.fill").font(.system(size: 24)).foregroundStyle(.white).frame(width: 52, height: 52).background(Color.white.opacity(0.18), in: Circle())
                            VStack(alignment: .leading, spacing: 1) {
                                Text(L("ch_broadcast_new")).font(.dorr(16, .heavy)).foregroundStyle(.white)
                                Text(L("ch_broadcast_hint")).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.85)).multilineTextAlignment(.leading)
                            }
                            Spacer()
                            Image(systemName: "plus").font(.system(size: 18, weight: .semibold)).foregroundStyle(.white)
                        }
                        .padding(16)
                        .background(ch.headerBrush, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
                    }
                    .buttonStyle(WaPressStyle())
                    if !host.folders.isEmpty {
                        Button { fromFolder = true } label: {
                            HStack(spacing: 10) {
                                Image(systemName: "folder.fill").font(.system(size: 19)).foregroundStyle(ch.red)
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(L("bk_broadcast_from_folder")).font(.dorr(14.5, .bold)).foregroundStyle(ch.ink)
                                    Text(L("bk_broadcast_from_folder_sub")).font(.dorr(12)).foregroundStyle(ch.mut).multilineTextAlignment(.leading)
                                }
                                Spacer()
                            }
                            .padding(14)
                            .background(ch.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                        }
                        .buttonStyle(.plain)
                    }
                    if let items = lists {
                        if items.isEmpty {
                            Text(L("ch_broadcast_none")).font(.dorr(14)).foregroundStyle(ch.mut).multilineTextAlignment(.center).frame(maxWidth: .infinity).padding(30)
                        }
                        ForEach(Array(items.enumerated()), id: \.element.id) { i, b in
                            Button { host.push(.broadcast(b.id)) } label: {
                                HStack(spacing: 12) {
                                    Image(systemName: "megaphone.fill").font(.system(size: 19)).foregroundStyle(ch.red).frame(width: 48, height: 48).background(ch.red.opacity(0.1), in: Circle())
                                    VStack(alignment: .leading, spacing: 1) {
                                        Text(verbatim: b.title).font(.dorr(15, .heavy)).foregroundStyle(ch.ink).lineLimit(1)
                                        Text(verbatim: b.lastSent?.body ?? L("ch_broadcast_people", b.membersCount ?? 0)).font(.dorr(12.5)).foregroundStyle(ch.mut).lineLimit(1)
                                    }
                                    Spacer()
                                    Text(verbatim: listTime(b.lastSent?.at)).font(.dorr(11.5)).foregroundStyle(ch.soft)
                                }
                                .padding(12)
                                .background(ch.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                            }
                            .buttonStyle(.plain)
                            .chStagger(i)
                        }
                    } else {
                        ForEach(0..<3, id: \.self) { _ in WaSkeleton().frame(height: 70).clipShape(RoundedRectangle(cornerRadius: 20)) }
                    }
                }
                .padding(14)
            }
        }
        .task {
            await host.refreshFolders()
            lists = (try? await OrganizeApi.broadcasts().data) ?? []
        }
        .dorrDialog(isPresented: $fromFolder, bottom: true) {
            ChoiceSheet(title: L("bk_broadcast_from_folder"), subtitle: L("bk_broadcast_from_folder_sub"), options: host.folders.map { f in
                let open: () -> Void = { fromFolderPick(f.id) }
                return ([f.emoji, f.name].compactMap { $0 }.joined(separator: " "), open)
            }, onDismiss: { fromFolder = false })
        }
        .dorrDialog(isPresented: $creating, bottom: true) {
            PeoplePickerSheet(initial: [], onDismiss: { creating = false }) { ids in
                creating = false
                Task {
                    do { if let b = try await OrganizeApi.createBroadcast(members: ids).data { host.push(.broadcast(b.id)) } } catch {
                        host.showToast(error.apiFailure.message ?? "", error: true)
                    }
                }
            }
        }
    }
}

extension BroadcastsPage {
    /// A list made from one of my folders, then open it.
    fileprivate func fromFolderPick(_ folderId: Int) {
        Task {
            do { if let b = try await OrganizeApi.broadcastFromFolder(folderId).data { host.push(.broadcast(b.id)) } } catch {
                if let m = error.apiFailure.message { host.showToast(m, error: true) }
            }
        }
    }
}

/// One broadcast list: what I sent to it, and a box to send the next one (text or a photo).
struct BroadcastPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let id: String
    @State private var list: BroadcastDto?
    @State private var text = ""
    @State private var item: PhotosPickerItem?
    @State private var photo: UIImage?
    @State private var sending = false
    @State private var sheet: String?

    var body: some View {
        ChPage(title: list?.title ?? L("ch_broadcasts"), onBack: { host.pop() }, actions: {
            DarkHeroButton(icon: "person.2.fill", label: L("ch_broadcast_pick")) { sheet = "people" }
            DarkHeroButton(icon: "pencil", label: L("ch_broadcast_name")) { sheet = "rename" }
            DarkHeroButton(icon: "trash", label: L("ch_broadcast_delete")) { sheet = "delete" }
        }) {
            VStack(spacing: 0) {
                ScrollView {
                    LazyVStack(spacing: 8) {
                        Text(L("ch_broadcast_rule", list?.membersCount ?? 0)).font(.dorr(12.5)).foregroundStyle(ch.mut).multilineTextAlignment(.center)
                            .frame(maxWidth: .infinity).padding(10).background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        ForEach(list?.sent ?? []) { s in sentBubble(s) }
                    }
                    .padding(14)
                }
                if let photo {
                    HStack {
                        Image(uiImage: photo).resizable().scaledToFill().frame(width: 54, height: 54).clipShape(RoundedRectangle(cornerRadius: 10))
                        Spacer()
                        Button { self.photo = nil; item = nil } label: {
                            Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(ch.mut).frame(width: 32, height: 32).background(ch.surfaceMuted, in: Circle())
                        }
                        .buttonStyle(.plain)
                    }
                    .padding(.horizontal, 12).padding(.vertical, 6).background(ch.surface)
                }
                HStack(spacing: 8) {
                    PhotosPicker(selection: $item, matching: .images) {
                        Image(systemName: "photo.badge.plus").font(.system(size: 18)).foregroundStyle(ch.mut).frame(width: 42, height: 42).background(ch.surfaceMuted, in: Circle())
                    }
                    ChField(placeholder: L("ch_broadcast_type"), text: $text, lines: 1...5, limit: 4000)
                    let canSend = (!text.trimmingCharacters(in: .whitespaces).isEmpty || photo != nil) && !sending
                    Button(action: send) {
                        Image(systemName: "paperplane.fill").font(.system(size: 19)).foregroundStyle(.white).frame(width: 46, height: 46).background(canSend ? ch.red : ch.soft, in: Circle())
                    }
                    .buttonStyle(.plain)
                    .disabled(!canSend)
                }
                .padding(10)
                .background(ch.surface)
            }
        }
        .task { await load() }
        .onChange(of: item) { picked in
            Task { if let data = try? await picked?.loadTransferable(type: Data.self) { photo = UIImage(data: data) } }
        }
        .dorrDialog(isPresented: Binding(get: { sheet != nil }, set: { if !$0 { sheet = nil } }), bottom: true) { sheetView }
    }

    private func load() async { list = try? await OrganizeApi.broadcast(id).data }

    private func sentBubble(_ s: BroadcastSentDto) -> some View {
        HStack {
            Spacer(minLength: 50)
            VStack(alignment: .leading, spacing: 4) {
                if let t = s.thumbnail {
                    AsyncImage(url: ApiClient.mediaUrl(t)) { p in p.image?.resizable().scaledToFill() }
                        .frame(height: 160).frame(maxWidth: .infinity).clipShape(RoundedRectangle(cornerRadius: 12))
                }
                if let b = s.body, !b.trimmingCharacters(in: .whitespaces).isEmpty { Text(verbatim: b).font(.dorr(14.5)).foregroundStyle(.white) }
                Text(verbatim: L("ch_broadcast_sent_to", s.sentCount ?? 0) + ((s.skippedCount ?? 0) > 0 ? " · " + L("ch_broadcast_not_reached", s.skippedCount ?? 0) : "") + "  " + clockTime(s.createdAt))
                    .font(.dorr(10.5)).foregroundStyle(.white.opacity(0.75)).frame(maxWidth: .infinity, alignment: .trailing)
            }
            .padding(10)
            .frame(maxWidth: 290)
            .background(ch.red, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
    }

    private func send() {
        sending = true
        Task {
            do {
                let budget = ChatPrefs.shared.photoBudget()
                let files = photo.flatMap { $0.jpegData(compressionQuality: budget.quality) }.map { [ChatFile(field: "files[]", filename: "photo.jpg", mimeType: "image/jpeg", data: $0)] } ?? []
                let result = try await OrganizeApi.sendBroadcast(id, fields: ["type": photo != nil ? "image" : "text", "body": text.trimmingCharacters(in: .whitespacesAndNewlines)], files: files).data
                text = ""
                photo = nil
                item = nil
                await load()
                if let r = result {
                    let skipped = r.skipped ?? []
                    host.showToast(L("ch_broadcast_sent_to", r.sent) + (skipped.isEmpty ? "" : " · " + L("ch_broadcast_skipped", skipped.compactMap(\.name).joined(separator: "، "))))
                }
            } catch {
                host.showToast(error.apiFailure.message ?? "", error: true)
            }
            sending = false
        }
    }

    @ViewBuilder
    private var sheetView: some View {
        let close = { sheet = nil }
        switch sheet {
        case "people":
            PeoplePickerSheet(initial: Set((list?.members ?? []).map(\.id)), onDismiss: close) { ids in
                close()
                Task {
                    do { list = try await OrganizeApi.updateBroadcast(id, ["members": .array(ids.map { .int($0) })]).data } catch { host.showToast(error.apiFailure.message ?? "", error: true) }
                }
            }
        case "rename":
            TextInputSheet(title: L("ch_broadcast_name"), initial: list?.name ?? "", action: L("ch_save"), onDismiss: close) { value in
                close()
                Task {
                    do { list = try await OrganizeApi.updateBroadcast(id, ["name": .string(value)]).data } catch { host.showToast(error.apiFailure.message ?? "", error: true) }
                }
            }
        case "delete":
            ChoiceSheet(title: L("ch_broadcast_delete"), options: [(L("ch_broadcast_delete"), {
                Task {
                    _ = try? await OrganizeApi.deleteBroadcast(id)
                    host.pop()
                }
            })], danger: true, onDismiss: close)
        default:
            EmptyView()
        }
    }
}
