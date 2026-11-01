import SwiftUI
import UIKit

// androidApp `ui/screens/chat/MessageActions.kt` — long-press on a message: the conversation blurs and dims behind, the
// message lifts forward with a spring, the reaction bar opens (each emoji popping in one after another) and the
// action menu slides up under it. Tap outside to put it all back. Plus the sheets it opens: delete for me / everyone,
// pin for how long, forward to up to 5 chats, star into a folder, every emoji, a reminder time.

private let quickReactions = ["❤️", "😂", "👍", "😮", "😢", "🙏"]
private let moreReactions = [
    "❤️", "😂", "👍", "😮", "😢", "🙏", "🔥", "🎉", "😍", "🥰", "😎", "🤔", "👏", "💯", "😅", "😡",
    "🤣", "😊", "😘", "😭", "🥺", "🙌", "💪", "👌", "✅", "❌", "💔", "🤝", "🌹", "⭐", "💸", "🎁",
]

/// A deadline from the server that hasn't passed yet.
private func stillOpen(_ until: String?) -> Bool { parseRideTime(until).map { $0 > Date() } ?? false }

private let noActions = BubbleActions(onReply: { _ in }, onLongPress: { _ in }, onJumpTo: { _ in }, onRetry: { _ in }, onOpenMedia: { _, _ in }, onPayQr: { _ in }, onMessageContact: { _ in })

struct MessageFocusOverlay: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var ai = ChatAi.shared
    @ObservedObject var host: ChatHost
    @ObservedObject var state: ConversationState
    let onOpenInfo: (MessageDto) -> Void

    @State private var appear: CGFloat = 0
    @State private var sheet: String?

    var body: some View {
        if let focused = state.focused {
            GeometryReader { geo in
                ZStack {
                    Color.black.opacity(0.42 * appear).ignoresSafeArea().onTapGesture { close() }
                    VStack(alignment: focused.isMine ? .trailing : .leading, spacing: 10) {
                        reactions(focused)
                        // The message itself: a long one scrolls in at most a third of the screen.
                        ScrollView {
                            MessageRow(m: UiMessage(dto: focused.dto, local: focused.local, localFiles: focused.localFiles, fresh: false, progress: nil, localThumb: focused.localThumb, ai: focused.ai),
                                       firstInRun: true, lastInRun: true, isGroup: false, actions: noActions)
                                .allowsHitTesting(false)
                        }
                        .frame(maxWidth: 340, maxHeight: geo.size.height * 0.32)
                        .fixedSize(horizontal: false, vertical: true)
                        .scaleEffect(0.94 + 0.06 * appear)
                        menu(focused)
                            .frame(maxHeight: geo.size.height * 0.5)
                            .offset(y: (1 - appear) * 40)
                            .opacity(appear)
                    }
                    .padding(.horizontal, 18).padding(.vertical, 14)
                    .frame(maxWidth: .infinity, alignment: focused.isMine ? .trailing : .leading)
                }
            }
            .task(id: focused.id) { withAnimation(.spring(response: 0.38, dampingFraction: 0.7)) { appear = 1 } }
            .dorrDialog(isPresented: Binding(get: { sheet != nil }, set: { if !$0 { sheet = nil } }), bottom: true) { sheetView(focused) }
        }
    }

    private func close() {
        withAnimation(.easeOut(duration: 0.2)) { state.focused = nil }
    }

    // MARK: Reactions

    @ViewBuilder
    private func reactions(_ focused: UiMessage) -> some View {
        let dto = focused.dto
        let allowed = state.conversation?.isChannel == true ? state.conversation?.group?.allowedReactions : nil
        if !["call", "system"].contains(dto.type) && allowed?.isEmpty != true {
            HStack(spacing: 2) {
                ForEach(Array((allowed.map { Array($0.prefix(8)) } ?? quickReactions).enumerated()), id: \.offset) { i, emoji in
                    ReactionEmoji(emoji: emoji, index: i, selected: dto.reactions?.mine == emoji) {
                        state.react(dto, dto.reactions?.mine == emoji ? nil : emoji)
                        close()
                    }
                }
                if allowed == nil {
                    Button { sheet = "emoji" } label: {
                        Image(systemName: "plus").font(.system(size: 18, weight: .semibold)).foregroundStyle(ch.mut)
                            .frame(width: 38, height: 38).background(ch.surfaceMuted, in: Circle())
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 8).padding(.vertical, 6)
            .background(ch.surface, in: Capsule())
            .shadow(color: .black.opacity(0.2), radius: 12, y: 4)
            .scaleEffect(0.6 + 0.4 * appear)
            .opacity(appear)
        }
    }

    // MARK: Menu

    private func menu(_ focused: UiMessage) -> some View {
        let dto = focused.dto
        let mine = focused.isMine
        let c = state.conversation
        let body = dto.body ?? ""
        // Only what the server will still accept (the admin sets both time limits).
        let canEdit = mine && ["text", "image", "video", "document"].contains(dto.type) && stillOpen(dto.editUntil)
        let canPin = !["system", "call"].contains(dto.type)
        let canForward = !["wallet_transfer", "call", "system", "story_reply"].contains(dto.type)
        let aiText = !body.isEmpty && dto.viewOnce != true && dto.sealed != true && dto.type != "system"
        let isPinned = state.pinned.contains { $0.id == dto.id }
        return ScrollView {
            VStack(spacing: 0) {
                row("arrowshape.turn.up.left.fill", L("ch_reply")) { state.replyTo = dto; close() }
                // A side discussion under this message (spec 122).
                if !["system", "call"].contains(dto.type) && dto.isDeleted != true && dto.threadRoot == nil {
                    row("bubble.left.and.bubble.right.fill", L("ch_thread_reply")) {
                        close()
                        host.push(.thread(conversationId: dto.conversationId, rootId: dto.id))
                    }
                }
                // Into something to do (spec 148): commitment, handoff, agreement, task, appointment, reminder, decision.
                if !["system", "call", "commitment", "handoff", "agreement"].contains(dto.type) && dto.isDeleted != true && state.conversation?.isChannel != true {
                    row("bolt.fill", L("lf_turn_into")) { sheet = "toAction" }
                }
                // In a group: put it to a vote, for the decisions log (spec 119–120).
                if state.conversation?.type == "group" && !["system", "call", "poll"].contains(dto.type) && dto.isDeleted != true {
                    row("hammer.fill", L("ch_make_decision")) {
                        close()
                        host.makeDecision(dto)
                    }
                }
                if !body.isEmpty {
                    row("doc.on.doc.fill", L("ch_copy")) {
                        UIPasteboard.general.string = plainChatText(body)
                        host.showToast(L("ch_copied"))
                        close()
                    }
                }
                if canForward { row("arrowshape.turn.up.right.fill", L("ch_forward")) { sheet = "forward" } }
                // AI, on a tap: a question about this message (spec 125).
                if ai.ask && !body.isEmpty && dto.viewOnce != true && dto.type != "system" {
                    row("sparkles", L("ch_ai_ask_this")) { state.askAbout = [dto.id]; close() }
                }
                // DORR AI tools on this message (spec 37–39, 42, 46) — each one tap, suggestions only.
                if aiText && !focused.isMine && ai.understand { row("brain.head.profile", L("ct_understand")) { state.aiTool = ("understand", dto); close() } }
                if aiText && ai.simplify && body.count > 220 { row("text.alignleft", L("ct_simplify")) { state.aiTool = ("simplify", dto); close() } }
                if aiText && ai.tasks { row("checkmark.circle.fill", L("ct_make_tasks")) { state.aiTool = ("tasks", dto); close() } }
                // An image in the chat, changed as I describe (spec 51) — a preview first, sent only if I want.
                if ai.imageEdit && dto.type == "image" && dto.viewOnce != true && dto.sealed != true && dto.isDeleted != true {
                    row("sparkles", L("ch_ai_image_edit_title")) { state.aiTool = ("edit_image", dto); close() }
                }
                if aiText && ai.notes { row("doc.text.fill", L("ct_make_note")) { state.aiNote([dto.id]); close() } }
                // AI, on a tap: translate the text (never a view-once message).
                if ai.translate && aiText { row("character.bubble.fill", L("ch_ai_translate")) { state.translate(focused); close() } }
                row(dto.isStarred == true ? "star.fill" : "star", L(dto.isStarred == true ? "ch_unstar" : "ch_star")) {
                    if dto.isStarred == true { state.star(dto); close() } else { sheet = "star" }
                }
                // Set it aside to come back to, or keep a copy in my own notes.
                if dto.type != "system" {
                    row("bookmark.fill", L(dto.isReadLater == true ? "ch_read_later_done" : "ch_read_later")) { state.readLater(dto); close() }
                }
                if canForward && dto.viewOnce != true && c?.isSelf != true {
                    row("note.text.badge.plus", L("ch_save_to_notes")) { state.saveToNotes(dto, done: L("ch_saved_to_notes")); close() }
                }
                // DORR Moments capsules: keep a copy of it in an album for an occasion.
                if dto.viewOnce != true && dto.sealed != true && !["system", "call"].contains(dto.type) {
                    row("archivebox.fill", L("mo_capsule_keep")) { sheet = "capsule" }
                }
                // Someone else's message I owe an answer to; a reminder at a time I pick.
                if !mine && dto.type != "system" {
                    row("flag.fill", L(dto.isFollowUp == true ? "ch_needs_reply_done" : "ch_needs_reply")) { state.followUp(dto); close() }
                }
                if dto.type != "system" {
                    row("alarm.fill", L(dto.reminderAt != nil ? "ch_reminder_remove" : "ch_reminder")) {
                        if dto.reminderAt != nil { state.remind(dto, at: nil); close() } else { sheet = "remind" }
                    }
                }
                if canPin {
                    row("pin.fill", L(isPinned ? "ch_unpin" : "ch_pin")) {
                        if isPinned { state.unpin(dto); close() } else { sheet = "pin" }
                    }
                }
                if canEdit { row("pencil", L("ch_edit")) { state.editing = dto; state.replyTo = nil; close() } }
                if mine { row("info.circle.fill", L("ch_info")) { onOpenInfo(dto); close() } }
                row("trash.fill", L("ch_delete"), danger: true) { sheet = "delete" }
            }
        }
        .frame(width: 240)
        .fixedSize(horizontal: false, vertical: true)
        .background(ch.surface)
        .clipShape(RoundedRectangle(cornerRadius: 22))
        .shadow(color: .black.opacity(0.2), radius: 14, y: 5)
    }

    private func row(_ icon: String, _ text: String, danger: Bool = false, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack {
                Text(verbatim: text).font(.dorr(14.5, .semibold)).foregroundStyle(danger ? ch.danger : ch.ink)
                Spacer()
                Image(systemName: icon).font(.system(size: 17)).foregroundStyle(danger ? ch.danger : ch.mut)
            }
            .padding(.horizontal, 16).padding(.vertical, 12)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    // MARK: Sheets

    @ViewBuilder
    private func sheetView(_ focused: UiMessage) -> some View {
        let dto = focused.dto
        let done = { sheet = nil; close() }
        switch sheet {
        case "delete":
            // Mine within the time limit, or anyone's when I'm a group admin.
            let forEveryone = focused.isMine ? stillOpen(dto.deleteUntil) : (state.conversation?.isAdmin == true && state.conversation?.isGroup == true)
            ChoiceSheet(
                title: L("ch_delete_title"),
                options: (forEveryone ? [(L("ch_delete_for_everyone"), { state.deleteForEveryone(dto) })] : []) + [(L("ch_delete_for_me"), { state.deleteForMe(dto) })],
                danger: true, onDismiss: done
            )
        case "pin":
            ChoiceSheet(title: L("ch_pin_for"), options: [
                (L("ch_pin_24h"), { state.pin(dto, seconds: 86400) }),
                (L("ch_pin_7d"), { state.pin(dto, seconds: 604_800) }),
                (L("ch_pin_30d"), { state.pin(dto, seconds: 2_592_000) }),
            ], onDismiss: done)
        case "forward":
            ForwardSheet(host: host, exclude: state.id, onDismiss: done) { targets in
                state.forward(dto, to: targets) { ok in if ok { host.showToast(L("ch_forwarded_done")) } }
            }
        case "star":
            StarFolderSheet(onDismiss: done) { folder in state.star(dto, folderId: folder) }
        case "remind":
            ScheduleSheet(onDismiss: done) { at in state.remind(dto, at: at) }
        case "toAction":
            ToActionSheet(host: host, state: state, message: dto, onDismiss: done) { state.aiTool = ("remind", dto) }
        case "capsule":
            CapsulePickerSheet(messageId: dto.id, onDismiss: done)
        case "emoji":
            EmojiSheet { emoji in
                state.react(dto, emoji)
                done()
            }
        default:
            EmptyView()
        }
    }
}

private struct ReactionEmoji: View {
    @ObservedObject private var ch = Ch.shared
    let emoji: String
    let index: Int
    let selected: Bool
    let onTap: () -> Void
    @State private var pop: CGFloat = 0

    var body: some View {
        Button(action: onTap) {
            Text(verbatim: emoji).font(.system(size: 25))
                .frame(width: 40, height: 40)
                .background(selected ? ch.red.opacity(0.14) : .clear, in: Circle())
        }
        .buttonStyle(.plain)
        .scaleEffect(pop)
        .offset(y: (1 - pop) * 14)
        .task {
            try? await Task.sleep(nanoseconds: UInt64(60 + index * 38) * 1_000_000)
            withAnimation(.spring(response: 0.35, dampingFraction: 0.35)) { pop = 1 }
        }
    }
}

private struct EmojiSheet: View {
    let onPick: (String) -> Void

    var body: some View {
        ChSheetCard {
            LazyVGrid(columns: Array(repeating: GridItem(.flexible()), count: 8), spacing: 6) {
                ForEach(moreReactions, id: \.self) { emoji in
                    Button { onPick(emoji) } label: { Text(verbatim: emoji).font(.system(size: 26)).frame(width: 44, height: 44) }.buttonStyle(.plain)
                }
            }
        }
    }
}

/// Pick up to 5 chats to forward to; the send button rises in once something is selected.
struct ForwardSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let exclude: String
    let onDismiss: () -> Void
    let onSend: ([String]) -> Void
    @State private var selected: [String] = []

    var body: some View {
        ChSheetCard {
            Text(L("ch_forward_to")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 10)
            ScrollView {
                LazyVStack(spacing: 0) {
                    ForEach(host.conversations.filter { $0.id != exclude && $0.canSend != false }) { c in
                        let on = selected.contains(c.id)
                        HStack(spacing: 12) {
                            ChAvatar(url: c.avatar, name: c.title, key: c.peer?.key ?? c.id, size: 44, isGroup: c.isGroup)
                            Text(verbatim: c.title ?? "").font(.dorr(15, .bold)).foregroundStyle(ch.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                            Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 24)).foregroundStyle(on ? ch.red : ch.soft)
                                .animation(.spring(response: 0.3, dampingFraction: 0.5), value: on)
                        }
                        .padding(.vertical, 8).padding(.horizontal, 6)
                        .contentShape(Rectangle())
                        .onTapGesture {
                            if on { selected.removeAll { $0 == c.id } } else if selected.count < 5 { selected.append(c.id) }
                        }
                    }
                }
            }
            .frame(maxHeight: 440)
            if !selected.isEmpty {
                ChPrimaryButton(text: L("ch_forward") + " (\(selected.count))", icon: "arrowshape.turn.up.right.fill") {
                    onSend(selected)
                    onDismiss()
                }
                .padding(.top, 12)
                .transition(.move(edge: .bottom).combined(with: .opacity))
            }
        }
        .animation(.spring(response: 0.35, dampingFraction: 0.8), value: selected.isEmpty)
    }
}

/// Star into a folder: «Favourites» (no folder), one of mine, or a new one.
struct StarFolderSheet: View {
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    let onPick: (Int?) -> Void
    @State private var data: StarFoldersDto?
    @State private var creating = false
    @State private var name = ""

    var body: some View {
        ChSheetCard {
            Text(L(creating ? "cp_new_star_folder" : "cp_star_into")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 10)
            if creating { createForm } else { list }
        }
        .task { data = (try? await ChatMoreApi.starFolders().data) ?? StarFoldersDto() }
    }

    private var createForm: some View {
        VStack(spacing: 14) {
            ChField(placeholder: L("cp_new_star_folder"), text: $name, icon: "folder.fill", limit: 40)
            ChPrimaryButton(text: L("ch_save"), enabled: !name.trimmingCharacters(in: .whitespaces).isEmpty) { create() }
        }
    }

    private var list: some View {
        VStack(spacing: 0) {
            folderRow("⭐", L("cp_favourites")) { onPick(nil); onDismiss() }
            ForEach(data?.folders ?? []) { f in folderRow(f.emoji ?? "📁", f.name) { onPick(f.id); onDismiss() } }
            Button { creating = true } label: {
                HStack(spacing: 10) {
                    Image(systemName: "plus").font(.system(size: 18, weight: .semibold))
                    Text(L("cp_new_star_folder")).font(.dorr(15, .bold))
                }
                .foregroundStyle(ch.red).padding(.vertical, 12).padding(.horizontal, 6).frame(maxWidth: .infinity, alignment: .leading)
            }
            .buttonStyle(.plain)
        }
    }

    private func create() {
        let n = name.trimmingCharacters(in: .whitespaces)
        Task {
            if let f = try? await ChatMoreApi.createStarFolder(["name": .string(n)]).data { onPick(f.id) }
            onDismiss()
        }
    }

    private func folderRow(_ emoji: String, _ title: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 12) {
                Text(verbatim: emoji).font(.system(size: 20)).frame(width: 40, height: 40).background(ch.surfaceMuted, in: Circle())
                Text(verbatim: title).font(.dorr(15, .bold)).foregroundStyle(ch.ink)
                Spacer()
            }
            .padding(.vertical, 6).contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}
