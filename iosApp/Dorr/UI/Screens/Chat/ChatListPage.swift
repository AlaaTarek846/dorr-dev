import SwiftUI

// androidApp `chat/ChatListPage.kt`, `ChatOrganize.kt` (ChatActionsSheet, FolderChatsSheet) and ChatTools'
// FolderPickerSheet — the chat list: the navy header (title, Safe View, the menu, back), search, filter chips (all,
// unread, priority, groups, channels, archived, my folders), the message-requests banner, rows that swipe to pin /
// archive and long-press for every action, pull to refresh, the next page as you scroll, and the speed dial.

/// Lists with their own title and a back arrow (not chips).
private let specialLists: Set<String> = ["requests", "locked"]

struct ChatListPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var shield = ChatShield.shared
    @ObservedObject private var features = ChatFeatures.shared
    @ObservedObject private var store = ChatStore.shared
    @State private var query = ""
    @State private var searchResults: [ConversationDto]?
    @State private var listMenu = false
    @State private var actionsFor: ConversationDto?
    @State private var addStory = false
    @Environment(\.layoutDirection) private var direction

    private var searching: Bool { !query.trimmingCharacters(in: .whitespaces).isEmpty }

    var body: some View {
        let title: String = host.filter == "requests" ? L("ch_requests_banner") : host.filter == "locked" ? L("ch_locked_chats") : L("ch_title")
        ZStack(alignment: .bottomTrailing) {
            VStack(spacing: 0) {
                header(title)
                if !searching && !specialLists.contains(host.filter) && features.allows("stories") {
                    StoriesBar(host: host) { addStory = true }
                }
                if !searching && !specialLists.contains(host.filter) {
                    FilterChips(host: host).transition(.move(edge: .top).combined(with: .opacity))
                }
                list
            }
            SpeedDial(host: host).padding(.trailing, 20).padding(.bottom, 24)
        }
        .background(ch.bg.ignoresSafeArea())
        .task(id: host.filter) { await host.refreshList() }
        .task { await host.refreshStories() }
        // Debounced server search (names, numbers and message text).
        .task(id: "\(query)|\(host.filter)") {
            guard searching else { searchResults = nil; return }
            try? await Task.sleep(nanoseconds: 280_000_000)
            guard !Task.isCancelled else { return }
            let f = specialLists.contains(host.filter) ? host.filter : nil
            searchResults = (try? await ChatApi.conversations(filter: f, search: query, perPage: 30))?.data
        }
        .dorrDialog(isPresented: Binding(get: { actionsFor != nil }, set: { if !$0 { actionsFor = nil } }), bottom: true) {
            if let c = actionsFor { ChatActionsSheet(host: host, conversation: c) { actionsFor = nil } }
        }
        .dorrDialog(isPresented: Binding(get: { host.fillingFolder != nil }, set: { if !$0 { host.fillingFolder = nil } }), bottom: true) {
            if let f = host.fillingFolder { FolderChatsSheet(host: host, folder: f) { host.fillingFolder = nil } }
        }
        .dorrDialog(isPresented: $addStory, bottom: true) {
            StoryAddSheet(host: host) { addStory = false }
        }
    }

    // MARK: Header

    private func header(_ title: String) -> some View {
        ZStack(alignment: .bottom) {
            DarkHeroPanel {
                HStack(spacing: 8) {
                    Text(verbatim: title).font(.dorr(22, .black)).foregroundStyle(.white).lineLimit(2).frame(maxWidth: .infinity, alignment: .leading)
                    DarkHeroButton(icon: shield.safeView ? "eye.slash.fill" : "eye.fill", label: L("ch_safe_view")) { shield.setSafeView(!shield.safeView) }
                    Menu {
                        Button { host.push(.starred) } label: { Label(L("ch_starred_title"), systemImage: "star.fill") }
                        Button { ChatLock.unlock { host.filter = "locked" } } label: { Label(L("ch_locked_chats"), systemImage: "lock.fill") }
                        Button { host.push(.communities) } label: { Label(L("cm_title"), systemImage: "person.3.fill") }
                        Button { host.push(.chatSettings) } label: { Label(L("ch_settings"), systemImage: "gearshape.fill") }
                    } label: {
                        Image(systemName: "ellipsis").font(.system(size: 14, weight: .bold)).foregroundStyle(.white).rotationEffect(.degrees(90))
                            .frame(width: 34, height: 34).background(Color.white.opacity(0.12), in: Circle())
                    }
                    .accessibilityLabel(L("ch_share_more"))
                    DarkHeroButton(icon: "chevron.right", label: L("common_back")) {
                        if specialLists.contains(host.filter) { host.filter = "all" } else { host.pop() }
                    }
                }
                searchField.padding(.top, 10)
            }
            .padding(.horizontal, 16)
            ChLoadingBar(visible: !host.listLoaded).padding(.horizontal, 16)
        }
    }

    private var searchField: some View {
        HStack(spacing: 8) {
            Image(systemName: "magnifyingglass").font(.system(size: 15)).foregroundStyle(.white.opacity(0.85))
            ZStack(alignment: .leading) {
                if query.isEmpty {
                    Text(L(host.filter == "locked" ? "ch_locked_search" : "ch_search_hint")).font(.dorr(14)).foregroundStyle(.white.opacity(0.55)).lineLimit(1)
                }
                TextField("", text: $query).font(.dorr(14.5, .medium)).foregroundStyle(.white).tint(.white).submitLabel(.search)
            }
            if !query.isEmpty {
                Button { query = "" } label: { Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(.white.opacity(0.8)) }
                    .buttonStyle(.plain).accessibilityLabel(L("ch_close_sheet"))
            }
        }
        .padding(.horizontal, 12).frame(height: 42)
        .background(Color.white.opacity(0.16), in: Capsule())
    }

    // MARK: List

    @ViewBuilder private var list: some View {
        // Safe View: the chats I marked stay out of the list; a circle that hides its chats keeps them out of all but itself.
        let rows = (searchResults ?? host.conversations)
            .filter { !shield.safeView || !shield.safeHidden.contains($0.id) }
            .filter { host.filter.hasPrefix("circle:") || $0.circle?.hideFromList != true }
        if !host.listLoaded {
            ChSkeletonRows()
        } else if host.listFailed && rows.isEmpty {
            ChEmptyState(icon: "exclamationmark.triangle.fill", title: L("ch_load_failed"), text: L("ch_error_network"), action: L("ch_retry")) { Task { await host.refreshList() } }
        } else if rows.isEmpty && host.filter.hasPrefix("folder:") && searchResults == nil {
            let folder = host.folders.first { "folder:\($0.id)" == host.filter }
            ChEmptyState(icon: "folder.fill", title: L("ch_folder_empty_title"), text: L("ch_folder_empty_text"), action: L("ch_folder_add_chats"), onAction: { host.fillingFolder = folder }, animated: true)
        } else if rows.isEmpty && host.filter == "locked" && searchResults == nil {
            ChEmptyState(icon: "lock.fill", title: L("ch_locked_chats"), text: L("ch_no_locked"), animated: true)
        } else if rows.isEmpty && host.requestsCount == 0 {
            ChEmptyState(icon: "bubble.left.fill", title: L("ch_empty_title"), text: L("ch_empty_text"), action: L("ch_start_chat"), onAction: { host.push(.newChat) }, animated: true)
        } else {
            List {
                if host.requestsCount > 0 && searchResults == nil && host.filter == "all" {
                    requestsBanner.environment(\.layoutDirection, direction).listRowInsets(EdgeInsets()).listRowSeparator(.hidden).listRowBackground(ch.bg)
                }
                ForEach(Array(rows.enumerated()), id: \.element.id) { i, c in
                    // The list itself stays left-to-right (a SwiftUI List flips twice under a forced RTL); each row
                    // reads in the app's direction, and «swipe from the start» pins like Android.
                    ConversationRow(host: host, c: c, index: i) { host.push(.conversation(c.id)) } onLongPress: { actionsFor = c }
                        .environment(\.layoutDirection, direction)
                        .listRowInsets(EdgeInsets()).listRowSeparator(.hidden).listRowBackground(ch.bg)
                        .swipeActions(edge: direction == .rightToLeft ? .trailing : .leading, allowsFullSwipe: true) {
                            Button { pin(c) } label: { Label(L(c.isPinned == true ? "ch_unpin_chat" : "ch_pin_chat"), systemImage: "pin.fill") }.tint(Color(hex: 0xF59E0B))
                        }
                        .swipeActions(edge: direction == .rightToLeft ? .leading : .trailing, allowsFullSwipe: true) {
                            Button { archive(c) } label: { Label(L(c.isArchived == true ? "ch_unarchive" : "ch_archive"), systemImage: "archivebox.fill") }.tint(Color(hex: 0x6B7280))
                        }
                        .onAppear { if searchResults == nil && i >= rows.count - 3 { Task { await host.loadMoreList() } } }
                }
                Color.clear.frame(height: 120).listRowInsets(EdgeInsets()).listRowSeparator(.hidden).listRowBackground(ch.bg)
            }
            .listStyle(.plain)
            .environment(\.layoutDirection, .leftToRight)
            .scrollContentBackground(.hidden)
            .environment(\.defaultMinListRowHeight, 0)
            .refreshable { await host.refreshList() }
            .animation(.spring(response: 0.4, dampingFraction: 0.8), value: rows.map(\.id))
        }
    }

    private func pin(_ c: ConversationDto) {
        UIImpactFeedbackGenerator(style: .medium).impactOccurred()
        Task { if let u = try? await ChatApi.updateSettings(c.id, ["pinned": !(c.isPinned ?? false)]).data { host.upsert(u) } }
    }

    private func archive(_ c: ConversationDto) {
        UIImpactFeedbackGenerator(style: .medium).impactOccurred()
        host.remove(c.id)
        Task {
            _ = try? await ChatApi.updateSettings(c.id, ["archived": !(c.isArchived ?? false)])
            await host.refreshList()
        }
    }

    private var requestsBanner: some View {
        Button { host.filter = "requests" } label: {
            HStack(spacing: 12) {
                Image(systemName: "bubble.left.and.exclamationmark.bubble.right.fill").font(.system(size: 14)).foregroundStyle(ch.red)
                    .frame(width: 32, height: 32).background(ch.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("ch_requests_banner")).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink)
                    Text(verbatim: L("ch_requests_count", host.requestsCount)).font(.dorr(11.5)).foregroundStyle(ch.mut)
                }
                Spacer(minLength: 0)
                ChBadge(count: host.requestsCount)
            }
            .padding(.horizontal, 16).padding(.vertical, 10)
            .background(ch.red.opacity(0.1))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Filter chips

private struct FilterChips: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var creating = false
    @State private var managing: FolderDto?
    @State private var lookFor: FolderDto?
    @State private var renaming: FolderDto?
    @State private var editingCircle: CircleDto?

    var body: some View {
        let options: [(String, String)] = [
            ("all", L("ch_filter_all")), ("unread", L("ch_filter_unread")), ("priority", L("cp_priority")),
            ("groups", L("ch_filter_groups")), ("channels", L("ch_filter_channels")), ("archived", L("ch_filter_archived")),
        ] + host.folders.map { ("folder:\($0.id)", [$0.emoji, $0.name].compactMap { $0 }.joined(separator: " ")) }
            + host.circles.map { c in
                ("circle:\(c.id)", [c.emoji, c.shownName ?? c.name, c.locked == true ? "🔒" : nil, (c.unread ?? 0) > 0 ? "(\(c.unread ?? 0))" : nil].compactMap { $0 }.joined(separator: " "))
            }
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(options, id: \.0) { key, label in
                    let active = key == host.filter
                    let folder = host.folders.first { "folder:\($0.id)" == key }
                    let look = hexColor(folder?.color)
                    Text(verbatim: label).font(.dorr(13.5, .bold)).foregroundStyle(active ? .white : ch.mut)
                        .padding(.horizontal, 18).frame(height: 38)
                        .background {
                            if active {
                                Capsule().fill(look.map { LinearGradient(colors: [$0, $0], startPoint: .leading, endPoint: .trailing) }
                                    ?? LinearGradient(colors: [ch.red, ch.redDeep], startPoint: .leading, endPoint: .trailing))
                            } else {
                                Capsule().fill(look.map { $0.opacity(0.12) } ?? ch.surface)
                            }
                        }
                        .shadow(color: active ? ch.red.opacity(0.4) : .black.opacity(0.08), radius: active ? 6 : 1, y: active ? 3 : 1)
                        .scaleEffect(active ? 1 : 0.96)
                        .animation(.spring(response: 0.3, dampingFraction: 0.45), value: active)
                        .onTapGesture {
                            let circle = host.circles.first { "circle:\($0.id)" == key }
                            if circle?.locked == true, host.filter != key, let circle { ChatLock.unlockCircle(circle.id) { host.filter = key } } else { host.filter = key }
                        }
                        .onLongPressGesture {
                            if let folder { managing = folder } else if let circle = host.circles.first(where: { "circle:\($0.id)" == key }) {
                                if circle.locked == true { ChatLock.unlockCircle(circle.id) { editingCircle = circle } } else { editingCircle = circle }
                            }
                        }
                }
                Button { creating = true } label: {
                    Image(systemName: "plus").font(.system(size: 17, weight: .semibold)).foregroundStyle(ch.red).frame(width: 38, height: 38).background(ch.surface, in: Circle())
                        .shadow(color: .black.opacity(0.08), radius: 1, y: 1)
                }
                .buttonStyle(.plain)
            }
            .padding(.horizontal, 16).padding(.vertical, 12)
        }
        .task {
            await host.refreshFolders()
            await host.refreshCircles()
        }
        .dorrDialog(isPresented: Binding(get: { editingCircle != nil }, set: { if !$0 { editingCircle = nil } }), bottom: true) {
            if let c = editingCircle { CircleEditorSheet(host: host, circle: c, onDismiss: { editingCircle = nil }) { _ in } }
        }
        .dorrDialog(isPresented: $creating, bottom: true) {
            TextInputSheet(title: L("ch_new_folder"), action: L("ch_save"), onDismiss: { creating = false }) { name in
                Task {
                    do {
                        if let f = try await ChatApi.createFolder(["name": name]).data {
                            host.folders.append(f)
                            host.filter = "folder:\(f.id)"
                            host.fillingFolder = f
                        }
                    } catch {
                        if let m = error.apiFailure.message { host.showToast(m) }
                    }
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { managing != nil }, set: { if !$0 { managing = nil } }), bottom: true) {
            if let f = managing {
                ChoiceSheet(title: f.name, options: [
                    (L("ch_folder_edit_chats"), { host.fillingFolder = f }),
                    (L("ch_rename_folder"), { Task { try? await Task.sleep(nanoseconds: 350_000_000); renaming = f } }),
                    (L("cp_folder_look"), { Task { try? await Task.sleep(nanoseconds: 350_000_000); lookFor = f } }),
                    (L("ch_delete_folder"), {
                        Task {
                            _ = try? await ChatApi.deleteFolder(f.id)
                            host.folders.removeAll { $0.id == f.id }
                            if host.filter == "folder:\(f.id)" { host.filter = "all" }
                        }
                    }),
                ], onDismiss: { managing = nil })
            }
        }
        .dorrDialog(isPresented: Binding(get: { lookFor != nil }, set: { if !$0 { lookFor = nil } }), bottom: true) {
            if let f = lookFor {
                FolderLookSheet(folder: f, onDismiss: { lookFor = nil }) { updated in
                    if let i = host.folders.firstIndex(where: { $0.id == updated.id }) { host.folders[i] = updated }
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { renaming != nil }, set: { if !$0 { renaming = nil } }), bottom: true) {
            if let f = renaming {
                TextInputSheet(title: L("ch_rename_folder"), initial: f.name, action: L("ch_save"), onDismiss: { renaming = nil }) { name in
                    Task {
                        if var updated = try? await ChatApi.renameFolder(f.id, ["name": name]).data, let i = host.folders.firstIndex(where: { $0.id == updated.id }) {
                            updated.conversationIds = f.conversationIds
                            host.folders[i] = updated
                        }
                    }
                }
            }
        }
    }
}

// MARK: - A row

/// One chat: the avatar (with the online dot); the name (and its lock / ✔ / priority marks) over the last message; a
/// fixed end column with the time and under it the mention mark, mute / pin icons and the unread count.
private struct ConversationRow: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var prefs = ChatPrefs.shared
    let c: ConversationDto
    let index: Int
    let onTap: () -> Void
    let onLongPress: () -> Void

    var body: some View {
        let peerKey = c.peer?.key
        let online = peerKey.flatMap { host.presence[$0]?.online } ?? false
        let activity = host.activity(c.id).first
        let unread = (c.unreadCount ?? 0) > 0 || c.markedUnread == true
        let compact = prefs.compactList
        VStack(spacing: 0) {
            HStack(spacing: 12) {
                ChAvatar(url: c.avatar, name: c.title, key: peerKey ?? c.id, size: compact ? 44 : 50, isGroup: c.isGroup, online: online)
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 4) {
                        Text(verbatim: c.title ?? "").font(.dorr(compact ? 14 : 15, .semibold)).foregroundStyle(ch.ink).lineLimit(1)
                        if c.group?.isVerified == true { Image(systemName: "checkmark.seal.fill").font(.system(size: 13)).foregroundStyle(Color(hex: 0x0EA5E9)) }
                        if c.isLocked == true { Image(systemName: "lock.fill").font(.system(size: 11)).foregroundStyle(ch.soft) }
                    }
                    Group {
                        if let activity {
                            HStack(spacing: 6) {
                                Text(L(activity.state == "recording" ? "ch_recording" : "ch_typing")).font(.dorr(12.5, .semibold)).foregroundStyle(ch.red)
                                TypingDots(dot: 4)
                            }
                            .transition(.move(edge: .bottom).combined(with: .opacity))
                        } else {
                            LastMessagePreview(c: c).transition(.move(edge: .top).combined(with: .opacity))
                        }
                    }
                    .animation(.easeInOut(duration: 0.25), value: activity)
                }
                Spacer(minLength: 8)
                VStack(alignment: .trailing, spacing: 4) {
                    Text(verbatim: listTime(c.lastMessageAt)).font(.dorr(11.5, .medium)).foregroundStyle(ch.mut).lineLimit(1)
                    HStack(spacing: 4) {
                        if c.isPinned == true { Image(systemName: "pin.fill").font(.system(size: 12)).foregroundStyle(ch.soft).rotationEffect(.degrees(35)) }
                        if c.isMuted == true { Image(systemName: "bell.slash.fill").font(.system(size: 12)).foregroundStyle(ch.soft) }
                        if c.hasUnreadMention == true {
                            Image(systemName: "at").font(.system(size: 11, weight: .bold)).foregroundStyle(.white).frame(width: 20, height: 20).background(Ch.mention, in: Circle())
                        }
                        if let why = c.priority?.first {
                            Text(L(why == "mention" ? "cp_why_mention" : why == "urgent" ? "cp_why_urgent" : why == "owed" ? "cp_why_owed" : "cp_why_direct"))
                                .font(.dorr(10, .heavy)).foregroundStyle(ch.red).lineLimit(1)
                                .padding(.horizontal, 6).padding(.vertical, 2).background(ch.red.opacity(0.1), in: Capsule())
                        }
                        if unread {
                            Group {
                                if (c.unreadCount ?? 0) > 0 { ChBadge(count: c.unreadCount ?? 0, muted: c.isMuted == true) } else { Circle().fill(ch.red).frame(width: 10, height: 10) }
                            }
                            .transition(.scale.combined(with: .opacity))
                        }
                    }
                    .frame(height: 22)
                }
                .frame(minWidth: 40, alignment: .trailing)
            }
            .padding(.horizontal, 16).padding(.vertical, compact ? 8 : 10)
            Rectangle().fill(ch.line).frame(height: 0.6)
        }
        .background(c.isPinned == true ? ch.red.opacity(0.045) : .clear)
        .contentShape(Rectangle())
        .onTapGesture(perform: onTap)
        .onLongPressGesture {
            UIImpactFeedbackGenerator(style: .medium).impactOccurred()
            onLongPress()
        }
        .chStagger(index)
    }
}

/// The last message under a chat's name: a draft first («Draft: …»), the sender in groups, ticks for mine, an icon
/// for a photo / voice / file…, nothing when Safe View is on or the message is sensitive.
struct LastMessagePreview: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var store = ChatStore.shared
    @ObservedObject private var shield = ChatShield.shared
    let c: ConversationDto
    var strong = false
    var ticks = false

    var body: some View {
        let tone: Color = strong ? ch.ink.opacity(0.78) : ch.mut
        HStack(spacing: 3) {
            if let draft = store.drafts[c.id] {
                Text(verbatim: L("ch_draft") + " ").font(.dorr(12.5, .bold)).foregroundStyle(ch.red)
                Text(verbatim: plainChatText(draft).replacingOccurrences(of: "\n", with: " ")).font(.dorr(12.5)).foregroundStyle(tone)
            } else if let last = c.lastMessage {
                if shield.safeView || last.isSensitive == true {
                    Text(L(last.isSensitive == true ? "ch_sensitive" : "ch_safe_view_hidden")).font(.dorr(12.5)).foregroundStyle(tone)
                } else {
                    if ticks && last.isMine == true && last.system == nil && last.isDeleted != true {
                        ChTicks(status: last.status, onBubble: false, size: 15)
                    } else if c.isGroup && !c.isChannel, let s = last.sender, last.system == nil {
                        Text(verbatim: (s.name ?? "") + ": ").font(.dorr(12.5, .semibold)).foregroundStyle(Ch.colorFor(s.key))
                    }
                    let (icon, label) = previewOf(last.type, deleted: last.isDeleted == true)
                    if let icon { Image(systemName: icon).font(.system(size: 13)).foregroundStyle(ch.soft) }
                    let text: String = last.isDeleted == true ? L("ch_deleted") : last.system != nil ? (last.body ?? "")
                        : !(last.body ?? "").isEmpty ? plainChatText(last.body) : label
                    Text(verbatim: text).font(.dorr(12.5)).italic(last.isDeleted == true).foregroundStyle(tone)
                }
            } else {
                Text(verbatim: c.isChannel ? L("ch_followers", c.group?.membersCount ?? 0) : c.isGroup ? L("ch_members", c.group?.membersCount ?? 0) : "")
                    .font(.dorr(12.5)).foregroundStyle(tone)
            }
        }
        .lineLimit(1)
    }
}

/// The icon and words for a message kind in a preview.
func previewOf(_ type: String, deleted: Bool) -> (String?, String) {
    if deleted { return (nil, "") }
    switch type {
    case "image": return ("photo.fill", L("ch_photo"))
    case "video": return ("video.fill", L("ch_video"))
    case "voice", "audio": return ("mic.fill", L("ch_voice"))
    case "document": return ("doc.text.fill", L("ch_document"))
    case "location": return ("mappin.circle.fill", L("ch_location"))
    case "contact": return ("person.fill", L("ch_contact_card"))
    case "wallet_transfer": return ("banknote.fill", L("ch_transfer_receipt"))
    case "wallet_qr": return ("qrcode", L("ch_wallet_qr_title"))
    case "call": return ("phone.fill", L("ch_call_voice"))
    case "poll": return ("chart.bar.fill", L("ch_poll"))
    case "interactive": return ("rectangle.and.hand.point.up.left.fill", L("ia_preview"))
    case "gif": return ("photo.on.rectangle", "GIF")
    case "sticker": return ("face.smiling.fill", L("ch_sticker"))
    case "money_request": return ("banknote.fill", L("ch_money_request"))
    case "bill_split": return ("banknote.fill", L("ch_split_title"))
    case "moment_card": return ("gift.fill", L("mo_card_preview"))
    case "event_card": return ("mappin.circle.fill", L("ev_event_preview"))
    case "match_card": return ("sportscourt.fill", L("sp_match_preview"))
    case "story_mention": return ("at", L("bk_story_mention_line"))
    default: return (nil, "")
    }
}

// MARK: - Speed dial

private struct SpeedDial: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var open = false

    var body: some View {
        VStack(alignment: .trailing, spacing: 12) {
            if open {
                let actions: [(String, String, ChRoute)] = [
                    ("qrcode.viewfinder", L("ch_scan_qr"), .newChat), ("person.3.fill", L("ch_new_group"), .newGroup()), ("square.and.pencil", L("ch_new_chat"), .newChat),
                ]
                ForEach(Array(actions.enumerated()), id: \.offset) { i, a in
                    HStack(spacing: 10) {
                        Text(verbatim: a.1).font(.dorr(13, .bold)).foregroundStyle(ch.ink).padding(.horizontal, 12).padding(.vertical, 7)
                            .background(ch.surface, in: RoundedRectangle(cornerRadius: 12, style: .continuous)).shadow(color: .black.opacity(0.12), radius: 5, y: 2)
                        Button {
                            open = false
                            host.push(a.2)
                        } label: {
                            Image(systemName: a.0).font(.system(size: 19)).foregroundStyle(ch.red).frame(width: 46, height: 46).background(ch.surface, in: Circle())
                                .shadow(color: ch.red.opacity(0.3), radius: 8, y: 3)
                        }
                        .buttonStyle(WaPressStyle())
                    }
                    .transition(.scale(scale: 0.3, anchor: .bottomTrailing).combined(with: .opacity).animation(.spring(response: 0.35, dampingFraction: 0.55).delay(Double(actions.count - i) * 0.04)))
                }
            }
            Button {
                UIImpactFeedbackGenerator(style: .light).impactOccurred()
                withAnimation(.spring(response: 0.4, dampingFraction: 0.5)) { open.toggle() }
            } label: {
                ZStack {
                    Image(systemName: "bubble.left.fill").font(.system(size: 25)).foregroundStyle(.white)
                    Image(systemName: "plus").font(.system(size: 11, weight: .black)).foregroundStyle(ch.red).offset(y: -1)
                }
                .rotationEffect(.degrees(open ? 135 : 0))
                .frame(width: 56, height: 56)
                .background(ch.headerBrush, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .shadow(color: ch.red.opacity(0.45), radius: 10, y: 5)
            }
            .buttonStyle(WaPressStyle())
        }
    }
}

// MARK: - Long-press actions and folders

/// Everything you can do with one chat, like WhatsApp — pin, read / unread, mute, archive, a folder, lock, delete.
struct ChatActionsSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let conversation: ConversationDto
    let onDismiss: () -> Void
    @State private var sub: String?

    private func settings(_ body: [String: Any?], leavesList: Bool = false) {
        onDismiss()
        if leavesList { host.remove(conversation.id) }
        Task {
            do {
                let updated = try await ChatApi.updateSettings(conversation.id, body).data
                if leavesList { await host.refreshList() } else if let updated { host.upsert(updated) }
            } catch {
                if let m = error.apiFailure.message { host.showToast(m) }
            }
        }
    }

    var body: some View {
        let c = conversation
        switch sub {
        case "folder":
            FolderPickerSheet(host: host, conversation: c) { sub = nil; onDismiss() }
        case "circle":
            CirclePickerSheet(host: host, conversation: c) { sub = nil; onDismiss() }
        case "mute":
            ChoiceSheet(title: L("ch_mute_notifications"), options: [
                (L("ch_mute_8h"), { settings(["mute": "8h"]) }), (L("ch_mute_1w"), { settings(["mute": "1w"]) }), (L("ch_mute_always"), { settings(["mute": "always"]) }),
            ], onDismiss: { sub = nil; onDismiss() })
        case "delete":
            ChoiceSheet(title: L("ch_delete_chat"), subtitle: L("ch_confirm_delete_chat"), options: [(L("ch_delete_chat"), {
                onDismiss()
                host.remove(c.id)
                Task { _ = try? await ChatApi.deleteConversation(c.id) }
            })], danger: true, onDismiss: { sub = nil; onDismiss() })
        default:
            let unread = (c.unreadCount ?? 0) > 0 || c.markedUnread == true
            ChSheetCard {
                HStack(spacing: 12) {
                    ChAvatar(url: c.avatar, name: c.title, key: c.peer?.key ?? c.id, size: 46, isGroup: c.isGroup)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: c.title ?? "").font(.dorr(16, .heavy)).foregroundStyle(ch.ink).lineLimit(1)
                        Text(L("ch_swipe_hint")).font(.dorr(11.5)).foregroundStyle(ch.soft).lineLimit(2)
                    }
                }
                .padding(.bottom, 12)
                row(0, "pin.fill", Color(hex: 0xF59E0B), L(c.isPinned == true ? "ch_unpin_chat" : "ch_pin_chat")) { settings(["pinned": !(c.isPinned ?? false)]) }
                row(1, unread ? "bubble.left.and.text.bubble.right.fill" : "bubble.left.and.exclamationmark.bubble.right.fill", Color(hex: 0x0EA5E9), L(unread ? "ch_mark_read" : "ch_mark_unread")) {
                    if unread {
                        onDismiss()
                        var read = c
                        read.unreadCount = 0
                        read.markedUnread = false
                        read.hasUnreadMention = false
                        host.upsert(read)
                        Task { _ = try? await ChatApi.markRead(c.id) }
                    } else {
                        settings(["marked_unread": true])
                    }
                }
                row(2, c.isMuted == true ? "bell.fill" : "bell.slash.fill", Color(hex: 0x8B5CF6), L(c.isMuted == true ? "ch_unmute" : "ch_mute")) {
                    if c.isMuted == true { settings(["mute": "off"]) } else { sub = "mute" }
                }
                // It leaves the list it's in (archived chats live under «Archived»).
                row(3, "archivebox.fill", Color(hex: 0x6B7280), L(c.isArchived == true ? "ch_unarchive" : "ch_archive")) { settings(["archived": !(c.isArchived ?? false)], leavesList: true) }
                row(4, "folder.fill", Color(hex: 0x10B981), L("ch_add_to_folder")) { sub = "folder" }
                row(4, "lock.circle.fill", Color(hex: 0xDB2777), c.circle.map { L("ch_circle_in", $0.name) } ?? L("ch_circle_add")) { sub = "circle" }
                row(5, c.isLocked == true ? "lock.open.fill" : "lock.fill", Color(hex: 0x334155), L(c.isLocked == true ? "ch_unlock_chat" : "ch_lock_chat")) {
                    settings(["locked": !(c.isLocked ?? false)], leavesList: true)
                }
                if !c.isGroup || c.isMember != true {
                    row(6, "trash.fill", ch.danger, L("ch_delete_chat"), danger: true) { sub = "delete" }
                }
            }
        }
    }

    private func row(_ i: Int, _ icon: String, _ tint: Color, _ label: String, danger: Bool = false, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 14) {
                Image(systemName: icon).font(.system(size: 18)).foregroundStyle(tint).frame(width: 38, height: 38).background(tint.opacity(0.14), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                Text(verbatim: label).font(.dorr(15, .bold)).foregroundStyle(danger ? ch.danger : ch.ink)
                Spacer()
            }
            .padding(.vertical, 10).padding(.horizontal, 4)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .chStagger(i)
    }
}

/// Tick the folders this chat belongs to — saved as you tap.
struct FolderPickerSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let conversation: ConversationDto
    let onDismiss: () -> Void
    @State private var newFolder = false

    var body: some View {
        ChSheetCard {
            if newFolder {
                TextInputSheetBody(title: L("ch_new_folder"), action: L("ch_save")) { name in
                    newFolder = false
                    Task {
                        do {
                            guard var folder = try await ChatApi.createFolder(["name": name]).data else { return }
                            folder.conversationIds = [conversation.id]
                            host.folders.append(folder)
                            if let withChat = try await ChatApi.folderConversations(folder.id, conversationIds: [conversation.id]).data,
                               let i = host.folders.firstIndex(where: { $0.id == folder.id }) { host.folders[i] = withChat }
                        } catch {
                            if let m = error.apiFailure.message { host.showToast(m) }
                        }
                    }
                }
            } else {
                Text(L("ch_add_to_folder")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                Text(verbatim: conversation.title ?? "").font(.dorr(13)).foregroundStyle(ch.mut).padding(.bottom, 12)
                ForEach(Array(host.folders.enumerated()), id: \.element.id) { i, folder in
                    let inside = folder.conversationIds?.contains(conversation.id) == true
                    Button { toggle(folder, inside) } label: {
                        HStack(spacing: 12) {
                            Image(systemName: "folder.fill").font(.system(size: 19)).foregroundStyle(ch.red)
                            Text(verbatim: folder.name).font(.dorr(15, .bold)).foregroundStyle(ch.ink)
                            Spacer()
                            Image(systemName: inside ? "checkmark.circle.fill" : "circle").font(.system(size: 22)).foregroundStyle(inside ? ch.red : ch.soft)
                        }
                        .padding(.vertical, 12).padding(.horizontal, 6)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    .chStagger(i)
                }
                Button { newFolder = true } label: {
                    HStack(spacing: 12) {
                        Image(systemName: "folder.badge.plus").font(.system(size: 19)).foregroundStyle(ch.red)
                        Text(L("ch_new_folder")).font(.dorr(15, .heavy)).foregroundStyle(ch.red)
                        Spacer()
                    }
                    .padding(.vertical, 12).padding(.horizontal, 6)
                }
                .buttonStyle(.plain)
            }
        }
        .task { await host.refreshFolders() }
    }

    /// The tick moves at once; a refusal puts it back and says why.
    private func toggle(_ folder: FolderDto, _ inside: Bool) {
        let ids = inside ? (folder.conversationIds ?? []).filter { $0 != conversation.id } : (folder.conversationIds ?? []) + [conversation.id]
        if let i = host.folders.firstIndex(where: { $0.id == folder.id }) { host.folders[i].conversationIds = ids }
        Task {
            do {
                if let u = try await ChatApi.folderConversations(folder.id, conversationIds: ids).data, let i = host.folders.firstIndex(where: { $0.id == u.id }) { host.folders[i] = u }
            } catch {
                if let i = host.folders.firstIndex(where: { $0.id == folder.id }) { host.folders[i] = folder }
                host.showToast(error.apiFailure.message ?? L("ch_error_network"))
            }
        }
    }
}

/// The body of `TextInputSheet` without its card (inside another sheet).
struct TextInputSheetBody: View {
    @ObservedObject private var ch = Ch.shared
    let title: String
    let action: String
    let onDone: (String) -> Void
    @State private var value = ""

    var body: some View {
        Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 14)
        ChField(placeholder: title, text: $value, icon: "pencil", limit: 50)
        ChPrimaryButton(text: action, enabled: !value.trimmingCharacters(in: .whitespaces).isEmpty) { onDone(value.trimmingCharacters(in: .whitespaces)) }.padding(.top, 16)
    }
}

/// Fill a folder: every chat I have (not requests), ticked when it's in the folder — search, tick, save once.
struct FolderChatsSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let folder: FolderDto
    let onDismiss: () -> Void
    @State private var all: [ConversationDto]?
    @State private var picked: [String] = []
    @State private var query = ""
    @State private var saving = false

    var body: some View {
        let shown = (all ?? []).filter { query.isEmpty || ($0.title ?? "").localizedCaseInsensitiveContains(query.trimmingCharacters(in: .whitespaces)) }
        ChSheetCard {
            Text(L("ch_folder_pick_title")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            Text(verbatim: folder.name).font(.dorr(13)).foregroundStyle(ch.mut).padding(.bottom, 10)
            ChField(placeholder: L("ch_search_hint"), text: $query, icon: "magnifyingglass")
            Group {
                if all == nil {
                    ProgressView().tint(ch.red).frame(maxWidth: .infinity, minHeight: 120)
                } else if shown.isEmpty {
                    Text(L("ch_no_results")).foregroundStyle(ch.soft).frame(maxWidth: .infinity, minHeight: 120)
                } else {
                    ScrollView {
                        LazyVStack(spacing: 0) {
                            ForEach(Array(shown.enumerated()), id: \.element.id) { i, c in
                                ChPickRow(index: i, avatar: c.avatar, title: c.title ?? "", subtitle: plainChatText(c.lastMessage?.body), key: c.peer?.key ?? c.id,
                                        isGroup: c.isGroup, checked: picked.contains(c.id)) {
                                    if let at = picked.firstIndex(of: c.id) { picked.remove(at: at) } else { picked.append(c.id) }
                                }
                            }
                        }
                    }
                    .frame(maxHeight: 420)
                }
            }
            .padding(.top, 8)
            ChPrimaryButton(text: L("ch_save") + (picked.isEmpty ? "" : "  (\(picked.count))"), icon: "checkmark", enabled: !saving && all != nil) { save() }.padding(.top, 12)
        }
        .task {
            picked = folder.conversationIds ?? []
            all = ((try? await ChatApi.conversations(perPage: 100))?.data ?? []).filter { $0.isRequest != true }
        }
    }

    private func save() {
        saving = true
        Task {
            do {
                if let updated = try await ChatApi.folderConversations(folder.id, conversationIds: picked).data, let i = host.folders.firstIndex(where: { $0.id == updated.id }) {
                    host.folders[i] = updated
                }
                host.showToast(L("ch_folder_saved", picked.count))
                if host.filter == "folder:\(folder.id)" { await host.refreshList() }
                onDismiss()
            } catch {
                if let m = error.apiFailure.message { host.showToast(m) }
            }
            saving = false
        }
    }
}

/// One tickable row: avatar, name, a line under it, and the tick.
struct ChPickRow: View {
    @ObservedObject private var ch = Ch.shared
    let index: Int
    let avatar: String?
    let title: String
    let subtitle: String?
    let key: String?
    let isGroup: Bool
    let checked: Bool
    let onToggle: () -> Void

    var body: some View {
        Button(action: onToggle) {
            HStack(spacing: 12) {
                ChAvatar(url: avatar, name: title, key: key, size: 42, isGroup: isGroup)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: title).font(.dorr(14.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                    if let subtitle, !subtitle.isEmpty { Text(verbatim: subtitle).font(.dorr(12)).foregroundStyle(ch.mut).lineLimit(1) }
                }
                Spacer(minLength: 0)
                Image(systemName: checked ? "checkmark.circle.fill" : "circle").font(.system(size: 22)).foregroundStyle(checked ? ch.red : ch.soft)
                    .animation(.spring(response: 0.3, dampingFraction: 0.4), value: checked)
            }
            .padding(.vertical, 8).padding(.horizontal, 6)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .chStagger(index % 14)
        .accessibilityAddTraits(checked ? .isSelected : [])
    }
}
