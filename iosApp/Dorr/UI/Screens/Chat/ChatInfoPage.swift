import PhotosUI
import SwiftUI

// androidApp `ui/screens/chat/ChatInfoPage.kt` + `ChatMembersPage.kt` (and from `ChatTools.kt`, `ChatBusinessUi.kt`,
// `ChatThemes.kt`, `ChatExtrasUi.kt`, `ChatBacklogUi.kt`, `ChatPartialUi.kt`, `ContactActionsSheet.kt` the sheets they
// open) — one chat's info: the hero with calls / add members, members first (groups), its community, the media / docs /
// links strip, a business's card, my settings (notifications, privacy & lock PIN, Safe View, contacts, disappearing,
// folder, export, money, theme), group / channel admin settings, and the danger zone. Searchable from the top bar.

private let slowModeSteps = [0, 10, 30, 60, 300, 900, 3600]

/// Unlocked this visit (each PIN chat on its own); cleared when the app goes to the background.
@MainActor
enum ChatPinLock {
    static var unlocked = Set<String>()
}

/// A wrong lock PIN: too many tries locks it for 5 minutes.
private func lockFailure(_ error: Error) -> PadResult {
    let f = error.apiFailure
    if f.httpStatus == 429 { return .locked(Date().addingTimeInterval(300)) }
    return .error(f.message ?? "")
}

/// Save the other person of a one-to-one chat to my Dorr contacts — they see my stories then. Returns the fresh chat.
func addPeerToContacts(_ c: ConversationDto) async throws -> ConversationDto? {
    guard let peer = c.peer, let phone = peer.phone, !phone.isEmpty else { return nil }
    _ = try await ChatApi.addContact(name: peer.accountName ?? peer.name ?? phone, phone: phone)
    return try await ChatApi.conversation(c.id).data
}

struct ChatInfoPage: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var ai = ChatAi.shared
    @ObservedObject private var shield = ChatShield.shared
    @ObservedObject var host: ChatHost
    let id: String
    @State private var c: ConversationDto?
    @State private var members: [MemberDto] = []
    @State private var gallery: [MessageDto] = []
    @State private var tab = "media"
    @State private var sheet: String?
    @State private var confirm: String?
    @State private var memberSheet: MemberDto?
    @State private var editing = false
    @State private var searching = false
    @State private var query = ""
    @State private var scrolled = false

    init(host: ChatHost, id: String) {
        self.host = host
        self.id = id
        _c = State(initialValue: host.find(id))
    }

    private var q: String { query.trimmingCharacters(in: .whitespaces) }
    private func hit(_ parts: String?...) -> Bool { q.isEmpty || parts.contains { ($0 ?? "").localizedCaseInsensitiveContains(q) } }
    private func hitRes(_ keys: String...) -> Bool { q.isEmpty || keys.contains { L($0).localizedCaseInsensitiveContains(q) } }

    var body: some View {
        let conversation = c
        ZStack(alignment: .top) {
            ScrollView {
                VStack(spacing: 0) {
                    GeometryReader { g in Color.clear.preference(key: InfoScrollKey.self, value: g.frame(in: .named("info")).minY) }.frame(height: 0)
                    hero(conversation)
                    if let conversation, conversation.isGroup { membersSection(conversation) }
                    // Its community (WhatsApp list 93).
                    if let community = conversation?.group?.community, hit(community.name) {
                        InfoCard {
                            SettingRow(icon: "person.3.sequence.fill", title: L(community.isAnnouncement == true ? "cm_announcements_of" : "cm_part_of", community.name), lined: false) {
                                host.push(.community(community.id))
                            }
                        }
                        .padding(.horizontal, 16).padding(.vertical, 6)
                    }
                    if q.isEmpty || !shownGallery.isEmpty || hitRes("ch_media_title", "ch_media", "ch_docs", "ch_links") { gallerySection }
                    // A business's card (backlog 111).
                    if let b = conversation?.business, [b.about, b.address, b.email, b.website].contains(where: { !($0 ?? "").isEmpty }) { BusinessCardInfo(b: b) }
                    if let conversation { settingsSections(conversation) }
                    if let conversation, conversation.isGroup, conversation.isAdmin { adminSection(conversation) }
                    if let conversation { dangerZone(conversation) }
                }
                .padding(.bottom, 40)
            }
            .coordinateSpace(name: "info")
            .onPreferenceChange(InfoScrollKey.self) { y in scrolled = y < -140 }
            .ignoresSafeArea(edges: .top)
            // Compact header that fades in once the hero scrolls away.
            Text(verbatim: conversation?.title ?? "").font(.dorr(16, .bold)).foregroundStyle(.white).lineLimit(1)
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.leading, 16).padding(.trailing, 96).padding(.vertical, 12)
                .background(ch.headerBrush.ignoresSafeArea(edges: .top))
                .opacity(scrolled && !searching ? 1 : 0)
                .animation(.easeInOut(duration: 0.2), value: scrolled)
            topBar
        }
        .background(ch.bg.ignoresSafeArea())
        .task { await reload() }
        .task(id: tab) {
            gallery = (try? await ChatApi.gallery(id, kind: tab == "docs" ? "documents" : tab).data?.messages) ?? []
        }
        .dorrDialog(isPresented: Binding(get: { sheet != nil }, set: { if !$0 { sheet = nil } }), bottom: true) { sheetView }
        .dorrDialog(isPresented: Binding(get: { confirm != nil }, set: { if !$0 { confirm = nil } })) { confirmView }
        .dorrDialog(isPresented: $editing, bottom: true) {
            if let current = c { GroupEditSheet(host: host, conversation: current, onDismiss: { editing = false }) { c = $0; host.upsert($0) } }
        }
        .dorrDialog(isPresented: Binding(get: { memberSheet != nil }, set: { if !$0 { memberSheet = nil } }), bottom: true) {
            if let m = memberSheet {
                MemberActionsSheet(host: host, groupId: id, member: m, myRole: c?.myRole, onMembers: { members = $0 }, onConversation: { c = $0 }) { memberSheet = nil }
            }
        }
    }

    private var shownMembers: [MemberDto] { q.isEmpty ? members : members.filter { hit($0.profile?.name) } }
    private var shownGallery: [MessageDto] { q.isEmpty ? gallery : gallery.filter { hit($0.body, $0.files.first?.name) } }

    private func reload() async {
        c = (try? await ChatApi.conversation(id).data) ?? c
        // A channel's followers are listed to its admins only.
        if c?.isGroup == true && (c?.isChannel != true || c?.isAdmin == true) {
            members = (try? await ChatApi.members(id).data) ?? []
        }
    }

    private func update(_ body: [String: Any?]) {
        Task {
            if let fresh = try? await ChatApi.updateSettings(id, body).data {
                c = fresh
                host.upsert(fresh)
            }
        }
    }

    private func group(_ body: [String: Any?]) {
        Task { if let fresh = try? await ChatApi.groupSettings(id, body).data { c = fresh; host.upsert(fresh) } }
    }

    // MARK: Top bar & hero

    private var topBar: some View {
        HStack(spacing: 8) {
            if searching {
                ChField(placeholder: L("ch_info_search"), text: $query, icon: "magnifyingglass", limit: 80)
                DarkHeroButton(icon: "xmark", label: L("ch_close_sheet")) { withAnimation { searching = false; query = "" } }
            } else {
                Spacer()
                DarkHeroButton(icon: "magnifyingglass", label: L("ch_info_search")) { withAnimation { searching = true } }
                DarkHeroButton(icon: "chevron.right", label: L("common_back")) { host.pop() }
            }
        }
        .padding(.horizontal, 16).padding(.vertical, 8)
    }

    private func hero(_ conversation: ConversationDto?) -> some View {
        let canEdit = conversation?.group != nil && conversation?.isMember != false && (conversation?.isAdmin == true || conversation?.group?.onlyAdminsEditInfo != true)
        let subtitle: String = {
            guard let conversation else { return "" }
            if conversation.isChannel { return [conversation.group?.handle.map { "@\($0)" }, L("ch_followers", conversation.group?.membersCount ?? 0)].compactMap { $0 }.joined(separator: "  ·  ") }
            if conversation.isGroup { return L("ch_members", conversation.group?.membersCount ?? 0) }
            if conversation.isSelf == true { return L("ch_note_to_self_sub") }
            return ltrNumber(conversation.peer?.phone)
        }()
        return VStack(spacing: 0) {
            ChAvatar(url: conversation?.avatar, name: conversation?.title, key: conversation?.peer?.key ?? id, size: 72, isGroup: conversation?.isGroup == true)
                .onTapGesture { if canEdit { editing = true } }
            HStack(spacing: 6) {
                Text(verbatim: conversation?.title ?? "").font(.dorr(18, .bold)).foregroundStyle(.white).multilineTextAlignment(.center).lineLimit(2)
                if conversation?.isChannel == true && conversation?.group?.isVerified == true {
                    VerifiedBadge(size: 18, tint: .white)
                }
                if canEdit {
                    Button { editing = true } label: {
                        Image(systemName: "pencil").font(.system(size: 13, weight: .bold)).foregroundStyle(.white)
                            .frame(width: 32, height: 32).background(Color.white.opacity(0.16), in: Circle())
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 16).padding(.top, 8)
            Text(verbatim: subtitle).font(.dorr(13)).foregroundStyle(.white.opacity(0.8)).padding(.top, 4)
            if let d = conversation?.group?.description, !d.isEmpty {
                Text(verbatim: d).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.9)).multilineTextAlignment(.center).lineLimit(2)
                    .padding(.horizontal, 24).padding(.vertical, 4)
            }
            if let conversation, conversation.canSend != false, !conversation.isChannel, conversation.isSelf != true {
                HStack(spacing: 8) {
                    // No call buttons where calls can't work (switched off, or a request not accepted yet).
                    if conversation.canCall != false && conversation.status == "accepted" {
                        quickAction("phone.fill", L("ch_call_voice"), 0) {
                            startCall(id, video: false, title: conversation.title ?? "", avatar: conversation.avatar, peerKey: conversation.peer?.key)
                            host.pop()
                        }
                        quickAction("video.fill", L("ch_call_video"), 1) {
                            startCall(id, video: true, title: conversation.title ?? "", avatar: conversation.avatar, peerKey: conversation.peer?.key)
                            host.pop()
                        }
                    }
                    if conversation.isGroup && (conversation.isAdmin || conversation.group?.onlyAdminsAddMembers != true) {
                        quickAction("person.badge.plus", L("ch_add_members"), 2) { host.push(.newGroup(addTo: id)) }
                    }
                }
                .padding(.top, 10)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.top, 104).padding(.bottom, 14)
        .background(ch.headerBrush)
        .clipShape(BottomRoundedRectangle(radius: 34))
    }

    private func quickAction(_ icon: String, _ label: String, _ i: Int, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(spacing: 4) {
                Image(systemName: icon).font(.system(size: 18))
                Text(verbatim: label).font(.dorr(11, .bold)).lineLimit(1)
            }
            .foregroundStyle(.white).frame(width: 84).padding(.vertical, 8)
            .background(Color.white.opacity(0.16), in: RoundedRectangle(cornerRadius: 16))
        }
        .buttonStyle(AiPressStyle())
        .chStagger(i)
    }

    // MARK: Members

    @ViewBuilder
    private func membersSection(_ conversation: ConversationDto) -> some View {
        let canAdd = conversation.isMember != false && (conversation.isAdmin || conversation.group?.onlyAdminsAddMembers == false)
        if conversation.isChannel && conversation.isAdmin && hit(L("ch_channel_invite_contacts")) {
            SettingRow(icon: "person.badge.plus", title: L("ch_channel_invite_contacts"), lined: false) { sheet = "inviteChannel" }
                .padding(.horizontal, 8).padding(.vertical, 4)
        }
        if !conversation.isChannel && canAdd && hit(L("ch_add_members")) {
            SettingRow(icon: "person.badge.plus", title: L("ch_add_members"), lined: false) { host.push(.newGroup(addTo: id)) }
                .padding(.horizontal, 8).padding(.vertical, 4)
        }
        if !shownMembers.isEmpty {
            HStack(spacing: 10) {
                Text(L(conversation.isChannel ? "ch_followers" : "ch_members", conversation.group?.membersCount ?? members.count)).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut)
                Rectangle().fill(ch.line).frame(height: 1)
            }
            .padding(.leading, 20).padding(.trailing, 16).padding(.top, 4).padding(.bottom, 6)
        }
        let preview = Array(shownMembers.prefix(6))
        ForEach(Array(preview.enumerated()), id: \.element.id) { i, m in
            GroupMemberRow(host: host, m: m, index: i, isAdmin: conversation.isAdmin, lined: i < preview.count - 1) { memberSheet = m }
        }
        if q.isEmpty && members.count > 6 {
            Button { host.push(.groupMembers(id)) } label: {
                Text(L("ch_see_all_members", conversation.group?.membersCount ?? members.count)).font(.dorr(14, .heavy)).foregroundStyle(ch.red)
                    .frame(maxWidth: .infinity).padding(.vertical, 12)
                    .background(ch.red.opacity(0.1), in: RoundedRectangle(cornerRadius: 18))
                    .overlay(RoundedRectangle(cornerRadius: 18).stroke(ch.red, lineWidth: 1.5))
            }
            .buttonStyle(.plain)
            .padding(.horizontal, 16).padding(.vertical, 8)
        }
    }

    // MARK: Gallery

    private var gallerySection: some View {
        VStack(spacing: 0) {
            HStack {
                Text(L("ch_media_title")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).lineLimit(1)
                Spacer()
                Button { host.push(.media(id, tab: tab)) } label: {
                    HStack(spacing: 4) {
                        Text(L("ch_media_all")).font(.dorr(12.5, .bold))
                        Image(systemName: "arrow.forward").font(.system(size: 11, weight: .bold))
                    }
                    .foregroundStyle(ch.red)
                }
                .buttonStyle(.plain)
            }
            .padding(.leading, 20).padding(.trailing, 16).padding(.top, 14).padding(.bottom, 6)
            VStack(spacing: 0) {
                HStack(spacing: 4) {
                    ForEach([("media", "ch_media", "photo.on.rectangle"), ("docs", "ch_docs", "doc.text.fill"), ("links", "ch_links", "link")], id: \.0) { key, label, icon in
                        let active = tab == key
                        Button { withAnimation(.easeInOut(duration: 0.2)) { tab = key } } label: {
                            HStack(spacing: 4) {
                                Image(systemName: icon).font(.system(size: 13))
                                Text(L(label)).font(.dorr(11.5, .bold)).lineLimit(1)
                            }
                            .foregroundStyle(active ? .white : ch.mut)
                            .frame(maxWidth: .infinity).padding(.vertical, 8).padding(.horizontal, 4)
                            .background(active ? ch.red : .clear, in: RoundedRectangle(cornerRadius: 14))
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(5)
                .background(ch.surface, in: RoundedRectangle(cornerRadius: 18))
                let items = shownGallery
                if items.isEmpty {
                    Text(L("ch_no_media")).font(.dorr(13)).foregroundStyle(ch.soft).padding(20)
                } else if tab == "media" {
                    let cells = Array(items.flatMap { m in m.files.map { (m, $0) } }.prefix(6))
                    LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 4), count: 3), spacing: 4) {
                        ForEach(Array(cells.enumerated()), id: \.offset) { _, cell in
                            ZStack {
                                ChatImage(url: ApiClient.mediaUrl(cell.0.type == "video" ? cell.1.thumbnail ?? cell.1.url : cell.1.url))
                                if cell.0.type == "video" { Image(systemName: "play.fill").font(.system(size: 22)).foregroundStyle(.white) }
                            }
                            .aspectRatio(1, contentMode: .fill)
                            .frame(minWidth: 0, maxWidth: .infinity)
                            .clipped()
                            .clipShape(RoundedRectangle(cornerRadius: 10))
                            .onTapGesture { host.push(.media(id, tab: tab)) }
                        }
                    }
                    .padding(8)
                } else {
                    VStack(spacing: 0) {
                        ForEach(items.prefix(5)) { m in
                            HStack(spacing: 10) {
                                Image(systemName: tab == "docs" ? "doc.text.fill" : "link").font(.system(size: 15)).foregroundStyle(ch.red)
                                    .frame(width: 32, height: 32).background(ch.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 9))
                                Text(verbatim: m.files.first?.name ?? plainChatText(m.body)).font(.dorr(13, .medium)).foregroundStyle(ch.ink).lineLimit(1)
                                    .frame(maxWidth: .infinity, alignment: .leading)
                            }
                            .padding(.horizontal, 6).padding(.vertical, 8)
                            .contentShape(Rectangle())
                            .onTapGesture { host.push(.media(id, tab: tab)) }
                        }
                    }
                    .padding(.horizontal, 8).padding(.vertical, 2)
                }
            }
            .padding(.horizontal, 16)
        }
    }

    // MARK: My settings

    @ViewBuilder
    private func settingsSections(_ conversation: ConversationDto) -> some View {
        if hitRes("ch_info_notify", "ch_mute_notifications") {
            InfoLabel(key: "ch_info_notify")
            VStack(spacing: 0) {
                SettingRow(icon: conversation.isMuted == true ? "bell.slash.fill" : "bell.fill", title: L("ch_mute_notifications"), value: muteChoiceLabel(conversation), lined: false) { sheet = "mute" }
            }
            .padding(.horizontal, 8)
        }
        if hitRes("ch_privacy_title", "ch_lock_chat", "cp_pin_title", "ch_hide_in_safe_view", "ch_in_contacts", "ch_add_to_contacts") {
            InfoLabel(key: "ch_privacy_title")
            VStack(spacing: 0) {
                ToggleRow(icon: "lock.fill", title: L("ch_lock_chat"), checked: conversation.isLocked == true) { update(["locked": $0]) }
                SettingRow(icon: "number.circle.fill", title: L("cp_pin_title"), value: L(conversation.hasLockPin == true ? "cp_on" : "ch_off")) { sheet = "pin" }
                ToggleRow(icon: "eye.slash.fill", title: L("ch_hide_in_safe_view"), checked: shield.safeHidden.contains(id), subtitle: L("ch_hide_in_safe_view_sub")) { _ in
                    shield.toggleSafeHidden(id)
                }
                if !conversation.isGroup, conversation.isSelf != true, let peer = conversation.peer {
                    if peer.isContact {
                        SettingRow(icon: "person.crop.circle.badge.checkmark", title: L("ch_in_contacts"), value: "✓", opens: false, lined: false) {}
                    } else {
                        SettingRow(icon: "person.badge.plus", title: L("ch_add_to_contacts")) {
                            Task {
                                do {
                                    if let fresh = try await addPeerToContacts(conversation) { c = fresh; host.upsert(fresh) }
                                    host.showToast(L("ch_contact_added"))
                                } catch { if let m = error.apiFailure.message { host.showToast(m) } }
                            }
                        }
                    }
                }
            }
            .padding(.horizontal, 8)
        }
        if hitRes("ch_set_messages", "ch_disappearing", "ch_add_to_folder", "bk_export", "cp_money") {
            InfoLabel(key: "ch_set_messages")
            VStack(spacing: 0) {
                SettingRow(icon: "timer", title: L("ch_disappearing"), value: disappearingLabel(conversation.disappearingSeconds)) { sheet = "disappearing" }
                SettingRow(icon: "folder.fill", title: L("ch_add_to_folder")) { sheet = "folder" }
                SettingRow(icon: "square.and.arrow.up", title: L("bk_export")) { sheet = "export" }
                if !conversation.isGroup && conversation.isSelf != true {
                    SettingRow(icon: "banknote.fill", title: L("cp_money"), lined: false) { host.push(.chatMoney(id)) }
                }
            }
            .padding(.horizontal, 8)
        }
        if hitRes("ch_set_look", "ch_chat_theme") {
            InfoLabel(key: "ch_set_look")
            SettingRow(icon: "paintpalette.fill", title: L("ch_chat_theme"), value: conversation.theme?.applied?.name ?? L("ch_theme_dorr"), lined: false) { host.push(.chatTheme(id)) }
                .padding(.horizontal, 8)
        }
    }

    // MARK: Admin

    @ViewBuilder
    private func adminSection(_ conversation: ConversationDto) -> some View {
        if hitRes("ch_settings", "ch_invite_link", "ch_channel_public", "ch_channel_handle", "ch_channel_category", "bk_channel_stats", "bk_channel_reactions", "ch_channel_verify", "ch_approve_joins", "ch_slow_mode", "ch_banned_words") {
            let g = conversation.group
            InfoLabel(key: "ch_settings")
            VStack(spacing: 0) {
                SettingRow(icon: "link", title: L("ch_invite_link")) { sheet = "invite" }
                if !conversation.isChannel { SettingRow(icon: "hammer.fill", title: L("ch_decisions")) { host.push(.decisions(conversationId: id, isAdmin: true)) } }
                if conversation.isChannel {
                    // A channel: public (in Discover) or link-only, and its @handle.
                    ToggleRow(icon: "gearshape.fill", title: L("ch_channel_public"), checked: g?.isPublic == true) { group(["is_public": $0]) }
                    SettingRow(icon: "at", title: L("ch_channel_handle"), value: g?.handle.map { "@\($0)" }) { sheet = "handle" }
                    SettingRow(icon: "square.grid.2x2.fill", title: L("ch_channel_category"), value: g?.category?.name ?? L("ch_channel_category_none")) { sheet = "category" }
                    SettingRow(icon: "chart.xyaxis.line", title: L("bk_channel_stats")) { host.push(.channelStats(id)) }
                    // Backlog 110: the reactions it allows.
                    let allowed = g?.allowedReactions
                    SettingRow(icon: "face.smiling", title: L("bk_channel_reactions"),
                               value: allowed == nil ? L("bk_reactions_any") : allowed!.isEmpty ? L("bk_reactions_none") : allowed!.joined()) { sheet = "reactions" }
                    // The owner buys the ✔ (like WhatsApp's Meta Verified).
                    if conversation.myRole == "owner" {
                        SettingRow(icon: "checkmark.seal.fill", title: L("ch_channel_verify"), value: L(g?.isVerified == true ? "ch_verified" : "ch_not_verified")) { sheet = "verify" }
                    }
                } else {
                    ToggleRow(icon: "gearshape.fill", title: L("ch_only_admins_send"), checked: g?.onlyAdminsSend == true) { group(["only_admins_send": $0]) }
                    ToggleRow(icon: "gearshape.fill", title: L("ch_only_admins_add"), checked: g?.onlyAdminsAddMembers == true) { group(["only_admins_add_members": $0]) }
                    // Moderation: members wait between messages; some words aren't allowed.
                    SettingRow(icon: "hourglass.bottomhalf.filled", title: L("ch_slow_mode"), value: slowModeLabel(g?.slowModeSeconds ?? 0)) { sheet = "slow" }
                    SettingRow(icon: "nosign", title: L("ch_banned_words"), value: (g?.bannedWords?.count ?? 0) > 0 ? "\(g!.bannedWords!.count)" : nil) { sheet = "banned" }
                }
                ToggleRow(icon: "gearshape.fill", title: L("ch_only_admins_edit"), checked: g?.onlyAdminsEditInfo == true) { group(["only_admins_edit_info": $0]) }
                ToggleRow(icon: "gearshape.fill", title: L("ch_approve_joins"), checked: g?.approveJoins == true) { group(["approve_joins": $0]) }
                // Who's waiting, with a badge.
                if g?.approveJoins == true || (g?.pendingJoinRequests ?? 0) > 0 {
                    SettingRow(icon: "hourglass.tophalf.filled", title: L("ch_join_requests"), value: (g?.pendingJoinRequests ?? 0) > 0 ? "\(g!.pendingJoinRequests!)" : nil, lined: false) { sheet = "joins" }
                }
            }
            .padding(.horizontal, 8)
        }
    }

    // MARK: Danger zone

    @ViewBuilder
    private func dangerZone(_ conversation: ConversationDto) -> some View {
        if hitRes("ch_clear_chat", "ch_unfollow", "ch_leave_group", "ch_delete_chat", "ch_report", "ch_delete_channel_all") {
            VStack(spacing: 0) {
                if !conversation.isGroup && conversation.peer != nil {
                    SettingRow(icon: "nosign", title: L(conversation.iBlocked == true ? "ch_unblock" : "ch_block"), danger: true, opens: false) { confirm = "block" }
                }
                // Nobody to report in my own notes.
                if conversation.isSelf != true { SettingRow(icon: "flag.fill", title: L("ch_report"), danger: true) { sheet = "report" } }
                SettingRow(icon: "paintbrush.pointed.fill", title: L("ch_clear_chat"), danger: true, opens: false) { confirm = "clear" }
                // The owner can delete the whole group / channel for everyone.
                if conversation.isGroup && conversation.myRole == "owner" && conversation.isMember != false {
                    SettingRow(icon: "trash.slash.fill", title: L(conversation.isChannel ? "ch_delete_channel_all" : "ch_delete_group_all"), danger: true, opens: false) { confirm = "deleteAll" }
                }
                if conversation.isGroup && conversation.isMember != false {
                    SettingRow(icon: "rectangle.portrait.and.arrow.right", title: L(conversation.isChannel ? "ch_unfollow" : "ch_leave_group"), danger: true, opens: false, lined: false) { confirm = "leave" }
                } else {
                    SettingRow(icon: "trash.fill", title: L("ch_delete_chat"), danger: true, opens: false, lined: false) { confirm = "delete" }
                }
            }
            .padding(.horizontal, 8).padding(.vertical, 8)
        }
    }

    // MARK: Sheets

    @ViewBuilder
    private var sheetView: some View {
        let close = { sheet = nil }
        switch sheet {
        case "mute":
            ChoiceSheet(title: L("ch_mute_notifications"), options: [
                (L("ch_mute_8h"), { update(["mute": "8h"]) }), (L("ch_mute_1w"), { update(["mute": "1w"]) }),
                (L("ch_mute_always"), { update(["mute": "always"]) }), (L("ch_unmute"), { update(["mute": "off"]) }),
            ], radios: true, selected: muteChoiceIndex(c) ?? 3, onDismiss: close)
        case "disappearing":
            let steps: [Int?] = [nil, 86400, 604_800, 7_776_000]
            ChoiceSheet(title: L("ch_disappearing"), options: steps.map { s in
                (disappearingLabel(s), { setDisappearing(s) })
            }, radios: true, selected: steps.firstIndex(of: c?.disappearingSeconds) ?? 0, onDismiss: close)
        case "slow":
            ChoiceSheet(title: L("ch_slow_mode"), subtitle: L("ch_slow_mode_sub"), options: slowModeSteps.map { s in
                ((s == (c?.group?.slowModeSeconds ?? 0) ? "✓  " : "") + slowModeLabel(s), { group(["slow_mode_seconds": s]) })
            }, onDismiss: close)
        case "folder":
            if let current = c { FolderPickerSheet(host: host, conversation: current, onDismiss: close) }
        case "export":
            ChoiceSheet(title: L("bk_export"), subtitle: L("bk_export_sub"), options: [
                (L("bk_export_text"), { export(media: false) }), (L("bk_export_media"), { export(media: true) }),
            ], radios: true, onDismiss: close)
        case "invite":
            if let current = c { InviteLinkSheet(host: host, conversation: current, onDismiss: close) }
        case "inviteChannel":
            if let current = c { ChannelInviteSheet(host: host, channel: current, onDismiss: close) }
        case "reactions":
            if let current = c { ChannelReactionsSheet(host: host, conversationId: id, current: current.group?.allowedReactions, onDismiss: close) { c = $0; host.upsert($0) } }
        case "verify":
            ChannelVerificationSheet(conversationId: id, onDismiss: close) { Task { await reload() } }
        case "banned":
            if let current = c { BannedWordsSheet(host: host, conversation: current, onDismiss: close) { c = $0; host.upsert($0) } }
        case "report":
            if let current = c { ChatReportSheet(host: host, conversation: current, onDismiss: close) { changed in if changed { Task { await reload() } } } }
        case "joins":
            JoinRequestsSheet(host: host, conversationId: id, onDismiss: close) { Task { await reload() } }
        case "pin":
            if let current = c {
                ChatPinSheet(host: host, conversationId: id, hasPin: current.hasLockPin == true, onDismiss: close) { has in
                    var fresh = current
                    fresh.hasLockPin = has
                    fresh.isLocked = current.isLocked == true || has
                    c = fresh
                    host.upsert(fresh)
                }
            }
        case "handle":
            TextInputSheet(title: L("ch_channel_handle"), initial: c?.group?.handle ?? "", action: L("ch_save"), icon: "at", limit: 32, onDismiss: close) { value in
                Task {
                    do { if let fresh = try await ChatApi.channelHandle(id, handle: value.isEmpty ? nil : value).data { c = fresh } } catch { if let m = error.apiFailure.message { host.showToast(m) } }
                }
            }
        case "category":
            CategoryChoice(current: c?.group?.category?.id, onDismiss: close) { catId in
                Task { if let fresh = try? await DiscoverApi.channelCategory(id, categoryId: catId).data { c = fresh; host.upsert(fresh) } }
            }
        default:
            EmptyView()
        }
    }

    @ViewBuilder
    private var confirmView: some View {
        let close = { confirm = nil }
        switch confirm {
        case "clear":
            ConfirmDialog(icon: "paintbrush.pointed.fill", title: L("ch_clear_chat"), message: L("ch_confirm_clear"), confirmLabel: L("ch_clear_chat"), onConfirm: {
                close()
                Task {
                    do { _ = try await ChatApi.clear(id); host.showToast(L("ch_clear_done")); host.pop(); host.pop() } catch { if let m = error.apiFailure.message { host.showToast(m, error: true) } }
                }
            }, onDismiss: close)
        case "deleteAll":
            let label = L(c?.isChannel == true ? "ch_delete_channel_all" : "ch_delete_group_all")
            ConfirmDialog(icon: "trash.slash.fill", title: label, message: L("ch_confirm_delete_all"), confirmLabel: label, onConfirm: {
                close()
                Task {
                    do { _ = try await ChatApi.deleteGroup(id); host.showToast(L("ch_delete_all_done")); host.remove(id); host.pop(); host.pop() } catch { if let m = error.apiFailure.message { host.showToast(m, error: true) } }
                }
            }, onDismiss: close)
        case "delete":
            ConfirmDialog(icon: "trash.fill", title: L("ch_delete_chat"), message: L("ch_confirm_delete_chat"), confirmLabel: L("ch_delete_chat"), onConfirm: {
                close()
                Task {
                    do { _ = try await ChatApi.deleteConversation(id); host.showToast(L("ch_delete_done")); host.remove(id); host.pop(); host.pop() } catch { if let m = error.apiFailure.message { host.showToast(m, error: true) } }
                }
            }, onDismiss: close)
        case "leave":
            let channel = c?.isChannel == true
            let label = L(channel ? "ch_unfollow" : "ch_leave_group")
            ConfirmDialog(icon: "rectangle.portrait.and.arrow.right", title: label, message: L(channel ? "ch_confirm_unfollow" : "ch_confirm_leave"), confirmLabel: label, onConfirm: {
                close()
                Task {
                    do { _ = try await ChatApi.leave(id); host.showToast(L(channel ? "ch_unfollow_done" : "ch_leave_done")); host.remove(id); host.pop(); host.pop() } catch { if let m = error.apiFailure.message { host.showToast(m, error: true) } }
                }
            }, onDismiss: close)
        case "block":
            let blocking = c?.iBlocked != true
            let action = L(blocking ? "ch_block" : "ch_unblock")
            ConfirmDialog(icon: "nosign", title: action, message: blocking ? L("ch_confirm_block", c?.title ?? "") : action, confirmLabel: action, onConfirm: {
                close()
                guard let peer = c?.peer else { return }
                Task {
                    do {
                        if blocking { _ = try await ChatApi.block(participantId: peer.id) } else { _ = try await ChatApi.unblock(participantId: peer.id) }
                        host.showToast(L(blocking ? "ch_block_done" : "ch_unblock_done"))
                        await reload()
                    } catch { if let m = error.apiFailure.message { host.showToast(m, error: true) } }
                }
            }, onDismiss: close)
        default:
            EmptyView()
        }
    }

    private func setDisappearing(_ seconds: Int?) {
        Task { if let fresh = try? await ChatApi.disappearing(id, seconds: seconds).data { c = fresh } }
    }

    /// Without media (a .txt) or with it (a .zip): downloaded, then handed to the share sheet.
    private func export(media: Bool) {
        host.showToast(L("bk_exporting"))
        Task {
            do {
                let data = try await ApiClient.raw("mobile/v1/chat/conversations/\(id)/export", query: ["media": media ? "1" : "0"], auth: true)
                let file = FileManager.default.temporaryDirectory.appendingPathComponent("DORR-chat." + (media ? "zip" : "txt"))
                try data.write(to: file, options: .atomic)
                presentShare(file)
            } catch {
                host.showToast(L("bk_export_failed"), error: true)
            }
        }
    }
}

private struct InfoScrollKey: PreferenceKey {
    static var defaultValue: CGFloat = 0
    static func reduce(value: inout CGFloat, nextValue: () -> CGFloat) { value = nextValue() }
}

private func muteChoiceIndex(_ c: ConversationDto?) -> Int? {
    guard c?.isMuted == true else { return nil }
    guard let until = parseRideTime(c?.mutedUntil) else { return 2 }
    let hours = until.timeIntervalSinceNow / 3600
    return hours > 24 * 20 ? 2 : hours > 10 ? 1 : 0
}

private func muteChoiceLabel(_ c: ConversationDto?) -> String? {
    switch muteChoiceIndex(c) {
    case 0: return L("ch_mute_8h")
    case 1: return L("ch_mute_1w")
    case 2: return L("ch_mute_always")
    default: return nil
    }
}

private func disappearingLabel(_ seconds: Int?) -> String {
    switch seconds {
    case 86400: return L("ch_24h")
    case 604_800: return L("ch_7d")
    case 7_776_000: return L("ch_90d")
    default: return L("ch_off")
    }
}

// MARK: - Rows

struct InfoCard<Content: View>: View {
    @ObservedObject private var ch = Ch.shared
    @ViewBuilder let content: () -> Content
    var body: some View {
        VStack(spacing: 0, content: content)
            .frame(maxWidth: .infinity)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 18))
            .shadow(color: .black.opacity(0.06), radius: 3, y: 1)
    }
}

private struct InfoLabel: View {
    @ObservedObject private var ch = Ch.shared
    let key: String
    var body: some View {
        Text(L(key)).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 20).padding(.top, 14).padding(.bottom, 6)
    }
}

struct SettingRow: View {
    @ObservedObject private var ch = Ch.shared
    let icon: String
    let title: String
    var value: String?
    var danger = false
    var opens = true
    var lined = true
    let onTap: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            Button(action: onTap) {
                HStack(spacing: 12) {
                    Image(systemName: icon).font(.system(size: 15)).foregroundStyle(danger ? ch.danger : ch.red)
                        .frame(width: 32, height: 32).background((danger ? ch.danger : ch.red).opacity(danger ? 0.1 : 0.08), in: RoundedRectangle(cornerRadius: 10))
                    HStack(spacing: 8) {
                        Text(verbatim: title).font(.dorr(14.5, .semibold)).foregroundStyle(danger ? ch.danger : ch.ink).lineLimit(1)
                        if let value {
                            Text(verbatim: value).font(.dorr(12.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                                .padding(.horizontal, 12).padding(.vertical, 5).background(ch.surface, in: Capsule())
                        }
                        Spacer(minLength: 0)
                    }
                    if opens { Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(ch.soft) }
                }
                .padding(.horizontal, 14).padding(.vertical, 11)
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            if lined { Rectangle().fill(ch.line).frame(height: 1).padding(.leading, 58).padding(.trailing, 16) }
        }
    }
}

struct ToggleRow: View {
    @ObservedObject private var ch = Ch.shared
    let icon: String
    let title: String
    let checked: Bool
    var subtitle: String?
    let onChange: (Bool) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 15)).foregroundStyle(ch.red)
                    .frame(width: 32, height: 32).background(ch.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 10))
                Text(verbatim: title).font(.dorr(14.5, .semibold)).foregroundStyle(ch.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                Toggle("", isOn: Binding(get: { checked }, set: onChange)).labelsHidden().tint(ch.red)
            }
            if let subtitle { Text(verbatim: subtitle).font(.dorr(12)).foregroundStyle(ch.mut).padding(.leading, 44).padding(.trailing, 4) }
            Rectangle().fill(ch.line).frame(height: 1).padding(.leading, 44).padding(.top, 6)
        }
        .padding(.horizontal, 14).padding(.vertical, 10)
    }
}

/// A business's card on its chat info: about, address (maps), email, website.
private struct BusinessCardInfo: View {
    @ObservedObject private var ch = Ch.shared
    let b: BusinessHoursDto

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(L("bk_business_card")).font(.dorr(13, .bold)).foregroundStyle(ch.mut).padding(.horizontal, 16).padding(.vertical, 6)
            if let about = b.about, !about.isEmpty { Text(verbatim: about).font(.dorr(14)).foregroundStyle(ch.ink).padding(.horizontal, 16).padding(.vertical, 4) }
            if let a = b.address, !a.isEmpty { SettingRow(icon: "mappin.circle.fill", title: a) { open("http://maps.apple.com/?q=" + (a.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? "")) } }
            if let e = b.email, !e.isEmpty { SettingRow(icon: "envelope.fill", title: e) { open("mailto:\(e)") } }
            if let w = b.website, !w.isEmpty { SettingRow(icon: "globe", title: w.replacingOccurrences(of: "https://", with: "").replacingOccurrences(of: "http://", with: ""), lined: false) { open(w) } }
        }
        .padding(.vertical, 6)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 22))
        .padding(.horizontal, 14).padding(.vertical, 6)
    }

    private func open(_ s: String) { if let url = URL(string: s) { UIApplication.shared.open(url) } }
}

private struct CategoryChoice: View {
    let current: Int?
    let onDismiss: () -> Void
    let onPick: (Int?) -> Void
    @State private var categories: [CategoryDto]?

    var body: some View {
        Group {
            if let list = categories {
                ChoiceSheet(title: L("ch_channel_category"), options: ([(nil, L("ch_channel_category_none"))] + list.map { (Optional($0.id), $0.name ?? "") }).map { item in
                    ((item.0 == current ? "✓  " : "") + item.1, { onPick(item.0) })
                }, onDismiss: onDismiss)
            } else {
                ChSheetCard { ProgressView().frame(maxWidth: .infinity).frame(height: 120) }
            }
        }
        .task { categories = (try? await DiscoverApi.categories().data) ?? [] }
    }
}

// MARK: - Members

struct GroupMemberRow: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let m: MemberDto
    let index: Int
    let isAdmin: Bool
    var carded = false
    var lined = true
    let onTap: () -> Void

    var body: some View {
        let name = m.profile?.isMe == true ? L("ch_you") : m.profile?.name ?? ""
        let canOpen = isAdmin && m.profile?.isMe != true && m.role != "owner"
        VStack(spacing: 0) {
            HStack(spacing: 12) {
                ChAvatar(url: m.profile?.avatar, name: m.profile?.name, key: m.profile?.key, size: carded ? 46 : 40,
                         online: m.profile.flatMap { host.presence[$0.key]?.online } ?? false)
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: name).font(.dorr(15, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                    if carded, let phone = m.profile?.phone, !phone.isEmpty { Text(verbatim: ltrNumber(phone)).font(.dorr(12.5)).foregroundStyle(ch.mut) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if m.role != "member" {
                    Text(L(m.role == "owner" ? "ch_owner" : "ch_admin")).font(.dorr(11.5, .heavy)).foregroundStyle(ch.red)
                        .padding(.horizontal, 8).padding(.vertical, 3).background(ch.red.opacity(0.1), in: RoundedRectangle(cornerRadius: 10))
                }
            }
            .padding(.horizontal, carded ? 12 : 16).padding(.vertical, carded ? 12 : 10)
            .background(carded ? ch.surface : .clear, in: RoundedRectangle(cornerRadius: 18))
            .contentShape(Rectangle())
            .onTapGesture { if canOpen { onTap() } }
            if lined && !carded { Rectangle().fill(ch.line).frame(height: 1).padding(.leading, 68).padding(.trailing, 16) }
        }
        .chStagger(index)
    }
}

struct GroupMembersPage: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let id: String
    @State private var conversation: ConversationDto?
    @State private var members: [MemberDto]?
    @State private var query = ""
    @State private var visible = 15
    @State private var memberSheet: MemberDto?

    var body: some View {
        let filtered = filteredMembers
        ChSettingsPage(title: L(conversation?.isChannel == true ? "ch_followers_list" : "ch_group_members"), onBack: { host.pop() }) {
            VStack(spacing: 0) {
                ChField(placeholder: L("ch_search_contacts"), text: $query, icon: "magnifyingglass").padding(.horizontal, 16).padding(.vertical, 8)
                if members == nil {
                    ProgressView().tint(ch.red).frame(maxHeight: .infinity)
                } else if filtered.isEmpty {
                    Text(L("ch_no_results")).font(.dorr(14)).foregroundStyle(ch.soft).padding(30)
                    Spacer()
                } else {
                    ScrollView {
                        LazyVStack(spacing: 6) {
                            ForEach(Array(filtered.prefix(visible).enumerated()), id: \.element.id) { i, m in
                                GroupMemberRow(host: host, m: m, index: i, isAdmin: conversation?.isAdmin == true, carded: true, lined: false) { memberSheet = m }
                                    .onAppear { if i >= visible - 3 && visible < filtered.count { visible = min(visible + 15, filtered.count) } }
                            }
                        }
                        .padding(.horizontal, 14).padding(.bottom, 24)
                    }
                }
            }
        }
        .task {
            conversation = host.find(id)
            conversation = (try? await ChatApi.conversation(id).data) ?? conversation
            members = (try? await ChatApi.members(id).data) ?? []
        }
        .onChange(of: query) { _ in visible = 15 }
        .dorrDialog(isPresented: Binding(get: { memberSheet != nil }, set: { if !$0 { memberSheet = nil } }), bottom: true) {
            if let m = memberSheet {
                MemberActionsSheet(host: host, groupId: id, member: m, myRole: conversation?.myRole, onMembers: { members = $0 }, onConversation: { conversation = $0 }) { memberSheet = nil }
            }
        }
    }

    private var filteredMembers: [MemberDto] {
        let q = query.trimmingCharacters(in: .whitespaces)
        let digits = q.filter(\.isNumber)
        return (members ?? []).filter { m in
            q.isEmpty || (m.profile?.name ?? "").localizedCaseInsensitiveContains(q) || (!digits.isEmpty && (m.profile?.phone ?? "").filter(\.isNumber).contains(digits))
        }
    }
}

struct MemberActionsSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let groupId: String
    let member: MemberDto
    let myRole: String?
    let onMembers: ([MemberDto]) -> Void
    let onConversation: (ConversationDto) -> Void
    let onDismiss: () -> Void
    @State private var confirmRemove = false

    var body: some View {
        let profile = member.profile
        let name = profile?.isMe == true ? L("ch_you") : profile?.name ?? ""
        let who = profile?.name ?? ""
        let isAdmin = member.role == "admin"
        if confirmRemove {
            ConfirmDialog(icon: "person.fill.xmark", title: L("ch_remove_member"), message: L("ch_confirm_remove_member", who), confirmLabel: L("ch_remove"), onConfirm: {
                run(L("ch_member_removed", who)) { if let list = try await ChatApi.removeMember(groupId, participant: member.participantId).data { onMembers(list) } }
            }, onDismiss: { confirmRemove = false })
        } else {
            ChSheetCard {
                VStack(spacing: 0) {
                    ChAvatar(url: profile?.avatar, name: name, key: profile?.key, size: 82, online: profile.flatMap { host.presence[$0.key]?.online } ?? false)
                    Text(verbatim: name).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).lineLimit(1).padding(.top, 10)
                    if let phone = profile?.phone, !phone.isEmpty { Text(verbatim: ltrNumber(phone)).font(.dorr(14, .semibold)).foregroundStyle(ch.mut) }
                    HStack {
                        quick("bubble.left.fill", L("ch_message")) { open(video: nil) }
                        quick("phone.fill", L("ch_call")) { open(video: false) }
                        quick("video.fill", L("ch_video")) { open(video: true) }
                    }
                    .padding(.top, 20)
                    Rectangle().fill(ch.line).frame(height: 1).padding(.top, 14)
                    if myRole == "owner" {
                        adminRow("crown.fill", L("ch_make_owner"), 0) {
                            run(L("ch_member_now_owner", who)) {
                                if let c = try await ChatApi.transferOwnership(groupId, participant: member.participantId).data { onConversation(c); host.upsert(c) }
                                if let list = try await ChatApi.members(groupId).data { onMembers(list) }
                            }
                        }
                    }
                    adminRow(isAdmin ? "person.badge.minus" : "person.badge.shield.checkmark.fill", L(isAdmin ? "ch_dismiss_admin" : "ch_make_admin"), 1) {
                        run(L(isAdmin ? "ch_member_admin_dismissed" : "ch_member_now_admin", who)) {
                            if let list = try await ChatApi.setRole(groupId, participant: member.participantId, role: isAdmin ? "member" : "admin").data { onMembers(list) }
                        }
                    }
                    adminRow("person.fill.xmark", L("ch_remove_member"), 2, danger: true) { confirmRemove = true }
                    Button(action: onDismiss) {
                        Text(L("ch_cancel")).font(.dorr(15, .bold)).foregroundStyle(ch.ink).frame(maxWidth: .infinity).padding(.vertical, 14)
                            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 16))
                    }
                    .buttonStyle(.plain).padding(.top, 14)
                }
            }
        }
    }

    private func quick(_ icon: String, _ label: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(spacing: 8) {
                Image(systemName: icon).font(.system(size: 21)).foregroundStyle(ch.red).frame(width: 56, height: 56).background(ch.red.opacity(0.12), in: Circle())
                    .shadow(color: .black.opacity(0.15), radius: 6, y: 3)
                Text(verbatim: label).font(.dorr(12, .bold)).foregroundStyle(ch.ink).lineLimit(1)
            }
            .frame(maxWidth: .infinity)
        }
        .buttonStyle(AiPressStyle())
    }

    private func adminRow(_ icon: String, _ label: String, _ i: Int, danger: Bool = false, _ action: @escaping () -> Void) -> some View {
        VStack(spacing: 0) {
            Button(action: action) {
                HStack(spacing: 12) {
                    Image(systemName: icon).font(.system(size: 16)).foregroundStyle(danger ? ch.danger : ch.red)
                        .frame(width: 34, height: 34).background((danger ? ch.danger : ch.red).opacity(0.1), in: RoundedRectangle(cornerRadius: 10))
                    Text(verbatim: label).font(.dorr(15, .bold)).foregroundStyle(danger ? ch.danger : ch.ink)
                    Spacer()
                }
                .padding(.horizontal, 4).padding(.vertical, 12).contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            Rectangle().fill(ch.line).frame(height: 1)
        }
        .chStagger(i)
    }

    private func open(video: Bool?) {
        guard let p = member.profile else { return }
        onDismiss()
        guard let video else {
            host.openChatWith(p, keepCurrent: true)
            return
        }
        Task {
            do {
                if let conv = try await ChatApi.openDirect(participantId: p.id, participantType: p.type).data {
                    startCall(conv.id, video: video, title: p.name ?? "", avatar: p.avatar, peerKey: p.key)
                }
            } catch {
                if let m = error.apiFailure.message { host.showToast(m) }
            }
        }
    }

    private func run(_ done: String, _ call: @escaping () async throws -> Void) {
        onDismiss()
        Task {
            do {
                try await call()
                host.showToast(done)
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
        }
    }
}

// MARK: - Group sheets

/// Group name, description and photo — for admins (or everyone, when the group allows it).
struct GroupEditSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let conversation: ConversationDto
    let onDismiss: () -> Void
    let onSaved: (ConversationDto) -> Void
    @State private var name = ""
    @State private var description = ""
    @State private var item: PhotosPickerItem?
    @State private var photo: UIImage?
    @State private var saving = false

    var body: some View {
        ChSheetCard {
            VStack(spacing: 0) {
                Text(L("ch_edit_group")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                PhotosPicker(selection: $item, matching: .images) {
                    ZStack {
                        Circle().fill(ch.headerBrush)
                        if let photo { Image(uiImage: photo).resizable().scaledToFill() } else if let a = conversation.group?.avatar { ChatImage(url: ApiClient.mediaUrl(a)) }
                        Color.black.opacity(0.25)
                        Image(systemName: "camera.fill").font(.system(size: 26)).foregroundStyle(.white)
                    }
                    .frame(width: 100, height: 100).clipShape(Circle())
                    .shadow(color: ch.red.opacity(0.35), radius: 12, y: 5)
                }
                .padding(.top, 16)
                ChField(placeholder: L("ch_group_name_hint"), text: $name, icon: "person.3.fill", limit: 100).padding(.top, 16)
                ChField(placeholder: L("ch_group_desc_hint"), text: $description, icon: "text.alignleft", lines: 1...5, limit: 2000).padding(.top, 10)
                ChPrimaryButton(text: L("ch_save"), enabled: !name.trimmingCharacters(in: .whitespaces).isEmpty && !saving) { save() }.padding(.top, 16)
            }
        }
        .onAppear {
            name = conversation.group?.name ?? ""
            description = conversation.group?.description ?? ""
        }
        .onChange(of: item) { i in Task { if let d = try? await i?.loadTransferable(type: Data.self) { photo = UIImage(data: d) } } }
    }

    private func save() {
        saving = true
        Task {
            do {
                let file = photo.flatMap { $0.jpegData(compressionQuality: 0.85) }.map { ChatFile(field: "avatar", filename: "avatar.jpg", mimeType: "image/jpeg", data: $0) }
                if let saved = try await ChatApi.updateGroup(conversation.id, fields: ["name": name.trimmingCharacters(in: .whitespaces), "description": description.trimmingCharacters(in: .whitespaces)], avatar: file).data { onSaved(saved) }
                onDismiss()
            } catch {
                if let m = error.apiFailure.message { host.showToast(m) }
            }
            saving = false
        }
    }
}

/// The words a member's message may not contain (admins only): add, remove, save.
private struct BannedWordsSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let conversation: ConversationDto
    let onDismiss: () -> Void
    let onSaved: (ConversationDto) -> Void
    @State private var words: [String] = []
    @State private var input = ""
    @State private var busy = false

    var body: some View {
        ChSheetCard {
            Text(L("ch_banned_words")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            Text(L("ch_banned_words_sub")).font(.dorr(13)).foregroundStyle(ch.mut)
            HStack(spacing: 8) {
                ChField(placeholder: L("ch_banned_words_hint"), text: $input, icon: "nosign", limit: 50)
                Button(action: add) {
                    Image(systemName: "plus").font(.system(size: 18, weight: .bold)).foregroundStyle(.white).frame(width: 40, height: 40).background(ch.red, in: Circle())
                }
                .buttonStyle(.plain)
            }
            .padding(.top, 12)
            if words.isEmpty {
                Text(L("ch_banned_words_empty")).font(.dorr(13)).foregroundStyle(ch.soft).padding(.top, 12)
            } else {
                ChFlow(spacing: 6) {
                    ForEach(words, id: \.self) { word in
                        HStack(spacing: 2) {
                            Text(verbatim: word).font(.dorr(13, .bold)).foregroundStyle(ch.ink)
                            Button { words.removeAll { $0 == word } } label: {
                                Image(systemName: "xmark").font(.system(size: 11, weight: .bold)).foregroundStyle(ch.mut).frame(width: 26, height: 26)
                            }
                            .buttonStyle(.plain)
                        }
                        .padding(.leading, 12).padding(.trailing, 4).padding(.vertical, 4)
                        .background(ch.danger.opacity(0.08), in: Capsule())
                    }
                }
                .padding(.top, 12)
            }
            ChPrimaryButton(text: L("ch_save"), enabled: !busy) {
                if !input.trimmingCharacters(in: .whitespaces).isEmpty { add() }
                busy = true
                Task {
                    do {
                        if let saved = try await ChatApi.groupSettings(conversation.id, ["banned_words": words]).data { onSaved(saved) }
                        onDismiss()
                    } catch { host.showToast(error.apiFailure.message ?? L("ch_error_network")) }
                    busy = false
                }
            }
            .padding(.top, 16)
        }
        .onAppear { words = conversation.group?.bannedWords ?? [] }
    }

    private func add() {
        let word = input.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: "\\s+", with: " ", options: .regularExpression)
        if !word.isEmpty && !words.contains(where: { $0.caseInsensitiveCompare(word) == .orderedSame }) { words.append(word) }
        input = ""
    }
}

/// Lays chips out in rows, wrapping like Compose's FlowRow.
struct ChFlow: Layout {
    var spacing: CGFloat = 6

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? .infinity
        var x: CGFloat = 0, y: CGFloat = 0, row: CGFloat = 0, widest: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > 0 && x + size.width > width { x = 0; y += row + spacing; row = 0 }
            x += size.width + spacing
            widest = max(widest, x - spacing)
            row = max(row, size.height)
        }
        return CGSize(width: min(widest, width), height: y + row)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x = bounds.minX, y = bounds.minY, row: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > bounds.minX && x + size.width > bounds.maxX { x = bounds.minX; y += row + spacing; row = 0 }
            s.place(at: CGPoint(x: x, y: y), proposal: ProposedViewSize(size))
            x += size.width + spacing
            row = max(row, size.height)
        }
    }
}

/// The group's invite link: copy or share it, or make a new one (the old stops working) that lasts an hour, a day,
/// a week, a month — or forever.
private struct InviteLinkSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let conversation: ConversationDto
    let onDismiss: () -> Void
    @State private var invite: InviteDto?
    @State private var choosing = false

    var body: some View {
        if choosing {
            ChoiceSheet(title: L("ch_invite_new"), subtitle: L("ch_invite_new_sub"), options: [
                (L("ch_invite_1h"), 1), (L("ch_invite_1d"), 24), (L("ch_invite_7d"), 168), (L("ch_invite_30d"), 720), (L("ch_invite_forever"), nil),
            ].map { label, hours in (label, { reset(hours) }) }, onDismiss: { choosing = false })
        } else {
            ChSheetCard {
                Text(L("ch_invite_link")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 12)
                HStack(spacing: 10) {
                    Image(systemName: "link").font(.system(size: 19)).foregroundStyle(ch.red)
                    VStack(alignment: .leading, spacing: 1) {
                        Text(verbatim: invite?.link ?? "…").font(.dorr(13.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                        Text(verbatim: invite?.expiresAt.map { L("ch_invite_expires", scheduleLabel($0)) } ?? L("ch_invite_never_expires")).font(.dorr(12)).foregroundStyle(ch.mut)
                    }
                    Spacer()
                }
                .padding(14)
                .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18))
                VStack(spacing: 0) {
                    row("doc.on.doc.fill", L("ch_copy_link")) {
                        guard let link = invite?.link else { return }
                        UIPasteboard.general.string = link
                        host.showToast(L("ch_link_copied"))
                    }
                    row("square.and.arrow.up", L("ch_share_link")) { if let link = invite?.link { chShare(link) } }
                    row("arrow.clockwise", L("ch_invite_new")) { choosing = true }
                }
                .padding(.top, 10)
            }
            .task { invite = try? await ChatApi.invite(conversation.id).data }
        }
    }

    private func row(_ icon: String, _ label: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 16)).foregroundStyle(ch.red).frame(width: 32, height: 32).background(ch.red.opacity(0.1), in: Circle())
                Text(verbatim: label).font(.dorr(15, .bold)).foregroundStyle(ch.ink)
                Spacer()
            }
            .padding(.vertical, 10).contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    private func reset(_ hours: Int?) {
        Task {
            do { invite = try await ChatApi.resetInvite(conversation.id, expiresInHours: hours).data ?? invite } catch { host.showToast(error.apiFailure.message ?? L("ch_error_network")) }
        }
    }
}

/// Report a chat: pick a reason, add details if you like, and optionally block the person / leave the group in the
/// same step. The last messages go with it as evidence — the sheet says so.
private struct ChatReportSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let conversation: ConversationDto
    let onDismiss: () -> Void
    let onReported: (Bool) -> Void
    @State private var types: [ReportTypeDto]?
    @State private var picked: Int?
    @State private var details = ""
    @State private var alsoBlock = true
    @State private var sending = false

    var body: some View {
        let canBlock = !conversation.isGroup && conversation.peer != nil && conversation.iBlocked != true
        let canLeave = conversation.isGroup && conversation.isMember != false
        ChSheetCard {
            HStack(spacing: 12) {
                Image(systemName: "flag.fill").font(.system(size: 18)).foregroundStyle(ch.danger).frame(width: 40, height: 40).background(ch.danger.opacity(0.12), in: Circle())
                VStack(alignment: .leading, spacing: 1) {
                    Text(L("ch_report_title", conversation.title ?? "")).font(.dorr(17, .heavy)).foregroundStyle(ch.ink).lineLimit(1)
                    Text(L("ch_report_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut)
                }
            }
            ScrollView {
                VStack(alignment: .leading, spacing: 8) {
                    if let list = types {
                        ForEach(Array(list.enumerated()), id: \.element.id) { i, type in
                            let on = picked == type.id
                            HStack {
                                Text(verbatim: type.name ?? "").font(.dorr(14.5, on ? .heavy : .semibold)).foregroundStyle(ch.ink)
                                Spacer()
                                Image(systemName: on ? "largecircle.fill.circle" : "circle").font(.system(size: 20)).foregroundStyle(on ? ch.red : ch.soft)
                            }
                            .padding(.horizontal, 14).padding(.vertical, 13)
                            .background(on ? ch.red.opacity(0.1) : ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 16))
                            .contentShape(Rectangle())
                            .onTapGesture { withAnimation(.easeOut(duration: 0.2)) { picked = type.id } }
                            .chStagger(i)
                        }
                    } else {
                        ProgressView().tint(ch.red).frame(maxWidth: .infinity).frame(height: 120)
                    }
                    if picked != nil {
                        ChField(placeholder: L("ch_report_details_hint"), text: $details, icon: "square.and.pencil", lines: 3...6, limit: 1000).padding(.top, 8)
                        if canBlock || canLeave {
                            HStack(spacing: 10) {
                                Image(systemName: alsoBlock ? "checkmark.square.fill" : "square").font(.system(size: 20)).foregroundStyle(alsoBlock ? ch.red : ch.soft)
                                Text(L(canBlock ? "ch_report_also_block" : "ch_report_also_leave")).font(.dorr(14, .semibold)).foregroundStyle(ch.ink)
                            }
                            .padding(.vertical, 8).padding(.horizontal, 4)
                            .onTapGesture { alsoBlock.toggle() }
                        }
                        Text(L("ch_report_evidence_note")).font(.dorr(12)).foregroundStyle(ch.mut)
                        ChPrimaryButton(text: L("ch_report"), icon: "flag.fill", enabled: !sending) { send(canBlock: canBlock, canLeave: canLeave) }.padding(.top, 8)
                    }
                }
                .padding(.top, 14)
            }
            .frame(maxHeight: 560)
        }
        .task { types = (try? await ChatApi.reportTypes().data) ?? [] }
    }

    private func send(canBlock: Bool, canLeave: Bool) {
        guard let reason = picked else { return }
        sending = true
        Task {
            do {
                let andAlso = alsoBlock && (canBlock || canLeave)
                _ = try await ChatApi.report(conversation.id, [
                    "report_type_id": reason,
                    "details": details.trimmingCharacters(in: .whitespaces).isEmpty ? nil : details.trimmingCharacters(in: .whitespaces),
                    "block": andAlso && canBlock,
                    "leave": andAlso && canLeave,
                ])
                host.showToast(L("ch_report_sent"))
                onReported(andAlso)
                onDismiss()
            } catch {
                if let m = error.apiFailure.message { host.showToast(m) }
            }
            sending = false
        }
    }
}

/// Admins: who's asking to join, with ✓ and ✕.
private struct JoinRequestsSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let conversationId: String
    let onDismiss: () -> Void
    let onChanged: () -> Void
    @State private var rows: [JoinRequestDto]?

    var body: some View {
        ChSheetCard {
            HStack(spacing: 12) {
                Image(systemName: "hourglass.tophalf.filled").font(.system(size: 18)).foregroundStyle(.white).frame(width: 40, height: 40).background(Color(hex: 0xF59E0B), in: Circle())
                Text(L("ch_join_requests")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            }
            .padding(.bottom, 10)
            if let list = rows {
                if list.isEmpty {
                    Text(L("ch_join_requests_empty")).font(.dorr(14)).foregroundStyle(ch.mut).frame(maxWidth: .infinity).padding(.vertical, 30)
                } else {
                    ScrollView {
                        VStack(spacing: 8) {
                            ForEach(Array(list.enumerated()), id: \.element.id) { i, r in
                                HStack(spacing: 10) {
                                    ChAvatar(url: r.profile?.avatar, name: r.profile?.name, key: r.profile?.key, size: 44)
                                    VStack(alignment: .leading, spacing: 1) {
                                        Text(verbatim: r.profile?.name ?? "").font(.dorr(14.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                                        Text(verbatim: listTime(r.requestedAt)).font(.dorr(12)).foregroundStyle(ch.mut)
                                    }
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    round("xmark", ch.danger.opacity(0.12), ch.danger) { decide(r, approve: false) }
                                    round("checkmark", ch.success, .white) { decide(r, approve: true) }
                                }
                                .padding(10)
                                .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18))
                                .transition(.move(edge: .leading).combined(with: .opacity))
                                .chStagger(i)
                            }
                        }
                    }
                    .frame(maxHeight: 460)
                }
            } else {
                ProgressView().tint(ch.red).frame(maxWidth: .infinity).frame(height: 120)
            }
        }
        .task { rows = (try? await ChatApi.joinRequests(conversationId).data) ?? [] }
    }

    private func round(_ icon: String, _ bg: Color, _ fg: Color, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 15, weight: .bold)).foregroundStyle(fg).frame(width: 38, height: 38).background(bg, in: Circle())
        }
        .buttonStyle(.plain)
    }

    private func decide(_ r: JoinRequestDto, approve: Bool) {
        withAnimation { rows = rows?.filter { $0.id != r.id } }
        Task {
            do {
                rows = approve ? try await ChatApi.approveJoin(conversationId, request: r.id).data : try await ChatApi.rejectJoin(conversationId, request: r.id).data
                onChanged()
            } catch {
                if let m = error.apiFailure.message { host.showToast(m) }
            }
        }
    }
}

/// Set (twice, to be sure), change or remove this chat's own PIN.
private struct ChatPinSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let conversationId: String
    let hasPin: Bool
    let onDismiss: () -> Void
    let onChanged: (Bool) -> Void
    @State private var step = ""
    @State private var current: String?
    @State private var first = ""
    @State private var removing = false

    var body: some View {
        ChSheetCard {
            if hasPin && step == "current" {
                HStack(spacing: 8) {
                    pill(L("cp_pin_change"), !removing) { removing = false }
                    pill(L("cp_pin_remove"), removing) { removing = true }
                }
            }
            WaPinPad(title: L(step == "current" ? "cp_pin_current" : step == "new" ? "cp_pin_new" : "cp_pin_confirm"), sub: L("cp_pin_sub")) { pin in await complete(pin) }
                .id(step)
                .frame(height: 500)
        }
        .onAppear { if step.isEmpty { step = hasPin ? "current" : "new" } }
    }

    private func pill(_ text: String, _ on: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(13.5, .bold)).foregroundStyle(on ? .white : ch.ink).frame(maxWidth: .infinity).padding(.vertical, 10)
                .background(on ? ch.red : ch.surfaceMuted, in: Capsule())
        }
        .buttonStyle(.plain)
    }

    private func complete(_ pin: String) async -> PadResult {
        switch step {
        case "current":
            do {
                if removing {
                    _ = try await ChatMoreApi.removeLockPin(conversationId, pin: pin)
                    host.showToast(L("cp_pin_removed"))
                    onChanged(false)
                    onDismiss()
                    return .ok
                }
                _ = try await ChatMoreApi.unlock(conversationId, pin: pin)
                current = pin
                step = "new"
                return .reset
            } catch { return lockFailure(error) }
        case "new":
            first = pin
            step = "confirm"
            return .reset
        default:
            guard pin == first else {
                step = "new"
                return .error(L("cp_pin_mismatch"))
            }
            do {
                _ = try await ChatMoreApi.setLockPin(conversationId, pin: pin, currentPin: current)
                ChatPinLock.unlocked.insert(conversationId)
                host.showToast(L("cp_pin_saved"))
                onChanged(true)
                onDismiss()
                return .ok
            } catch { return lockFailure(error) }
        }
    }
}

/// Instead of a PIN-locked chat until its own PIN is entered.
struct PinGate: View {
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    let title: String
    let onUnlocked: () -> Void

    var body: some View {
        WaPinPad(title: L("cp_pin_enter"), sub: title, icon: "lock.fill") { pin in
            do {
                _ = try await ChatMoreApi.unlock(conversationId, pin: pin)
                ChatPinLock.unlocked.insert(conversationId)
                onUnlocked()
                return .ok
            } catch {
                return lockFailure(error)
            }
        }
        .padding(.horizontal, 16).padding(.vertical, 24)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(ch.bg.ignoresSafeArea())
    }
}
