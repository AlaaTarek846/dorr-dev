import SwiftUI

// androidApp `chat/ChatCommunityUi.kt` — communities (WhatsApp list 93–98): related groups under one roof, with an
// announcements group only its admins write in. Members see every group in one place and hop in; admins (up to 20)
// add / make / remove groups and people; the creator stays an admin until they delete it.

struct CommunitiesPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var list: [CommunityDto]?
    @State private var query = ""
    @State private var page = 1
    @State private var hasMore = false
    @State private var loadingMore = false
    @State private var creating = false

    private func loadPage(reset: Bool) async {
        if !reset && (loadingMore || !hasMore) { return }
        if !reset { loadingMore = true }
        let next = reset ? 1 : page + 1
        let q = query.trimmingCharacters(in: .whitespaces)
        let envelope = try? await ChatApi.communities(page: next, perPage: 15, q: q.isEmpty ? nil : q)
        let chunk = envelope?.data ?? []
        if reset {
            list = chunk
        } else {
            let known = Set((list ?? []).map(\.id))
            list = (list ?? []) + chunk.filter { !known.contains($0.id) }
        }
        page = next
        hasMore = envelope?.pagination?.hasMorePages == true
        if !reset { loadingMore = false }
    }

    var body: some View {
        ChSettingsPage(title: L("cm_title"), onBack: { host.pop() }) {
            ZStack(alignment: .bottom) {
                VStack(spacing: 0) {
                    ChField(placeholder: L("cm_search"), text: $query, icon: "magnifyingglass", limit: 80, clearable: true)
                        .padding(.horizontal, 14).padding(.top, 8).padding(.bottom, 4)
                    if let items = list {
                        ScrollView {
                            LazyVStack(spacing: 8) {
                                if items.isEmpty {
                                    ChEmptyState(icon: "person.3.fill", title: L("cm_title"), text: L("cm_empty"), animated: true).frame(height: 340)
                                } else {
                                    ForEach(Array(items.enumerated()), id: \.element.id) { i, c in
                                        CommunityRow(icon: nil, title: c.name, avatar: c.avatar, key: c.id, groups: c.groupsCount ?? 0, members: c.membersCount ?? 0) {
                                            host.push(.community(c.id))
                                        }
                                        .chStagger(i)
                                        .onAppear { if i >= items.count - 3 { Task { await loadPage(reset: false) } } }
                                    }
                                }
                                if loadingMore { ProgressView().tint(ch.red).padding(12) }
                            }
                            .padding(.horizontal, 14).padding(.top, 8).padding(.bottom, 110)
                        }
                    } else {
                        ProgressView().tint(ch.red).frame(maxWidth: .infinity, maxHeight: .infinity)
                    }
                }
                CommunityCreateBar { creating = true }
                    .padding(.horizontal, 16).padding(.top, 26).padding(.bottom, 16)
                    .background(LinearGradient(colors: [.clear, ch.bg.opacity(0.96)], startPoint: .top, endPoint: UnitPoint(x: 0.5, y: 0.45)))
            }
        }
        .task(id: query) {
            if !query.trimmingCharacters(in: .whitespaces).isEmpty {
                try? await Task.sleep(nanoseconds: 300_000_000)
                guard !Task.isCancelled else { return }
            }
            await loadPage(reset: true)
        }
        .dorrDialog(isPresented: $creating, bottom: true) {
            NewCommunitySheet(host: host, onDismiss: { creating = false }) { made in
                list = [made] + (list ?? []).filter { $0.id != made.id }
                host.push(.community(made.id))
            }
        }
    }
}

private struct CommunityCreateBar: View {
    @ObservedObject private var ch = Ch.shared
    let onCreate: () -> Void

    var body: some View {
        Button(action: onCreate) {
            ZStack {
                Text(L("cm_new")).font(.dorr(15, .heavy)).foregroundStyle(.white).frame(maxWidth: .infinity)
                Image(systemName: "person.3.fill").font(.system(size: 14)).foregroundStyle(.white)
                    .frame(width: 34, height: 34).background(Color.white.opacity(0.16), in: Circle())
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(10)
            .background(ch.headerBrush, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .shadow(color: ch.red.opacity(0.45), radius: 10, y: 4)
        }
        .buttonStyle(.plain)
    }
}

private struct CommunityRow: View {
    @ObservedObject private var ch = Ch.shared
    let icon: String?
    let title: String
    var sub: String?
    let avatar: String?
    var key: String?
    var groups: Int?
    var members: Int?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 12) {
                if let icon {
                    Image(systemName: icon).font(.system(size: 21, weight: .semibold)).foregroundStyle(ch.red)
                        .frame(width: 48, height: 48).background(ch.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                } else {
                    ChAvatar(url: avatar, name: title, key: key, size: 48, isGroup: true)
                }
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: title).font(.dorr(15, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                    if let groups, let members {
                        CommunityStats(groups: groups, members: members)
                    } else if let sub {
                        Text(verbatim: sub).font(.dorr(12.5)).foregroundStyle(ch.mut).lineLimit(2).multilineTextAlignment(.leading)
                    }
                }
                Spacer(minLength: 0)
            }
            .padding(12)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

private struct CommunityStats: View {
    @ObservedObject private var ch = Ch.shared
    let groups: Int
    let members: Int
    var ink: Color?
    var mut: Color?
    var accent: Color?

    var body: some View {
        HStack(spacing: 0) {
            CommunityStat(icon: "person.3.fill", count: groups, label: L("cm_group"), ink: ink ?? ch.ink, mut: mut ?? ch.mut, accent: accent ?? ch.red)
            Text(verbatim: "  ·  ").font(.dorr(12)).foregroundStyle(mut ?? ch.mut)
            CommunityStat(icon: "person.fill", count: members, label: L("cm_member"), ink: ink ?? ch.ink, mut: mut ?? ch.mut, accent: accent ?? ch.red)
        }
        .padding(.top, 4)
    }
}

private struct CommunityStat: View {
    let icon: String
    let count: Int
    let label: String
    let ink: Color
    let mut: Color
    let accent: Color

    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: icon).font(.system(size: 11)).foregroundStyle(accent)
            Text(verbatim: "\(count)").font(.dorr(13, .heavy)).foregroundStyle(ink)
            Text(verbatim: label).font(.dorr(12, .bold)).foregroundStyle(mut)
        }
    }
}

/// Groups I run that aren't in a community yet — they can be brought in.
private func isFreeGroup(_ c: ConversationDto) -> Bool { c.isGroup && !c.isChannel && c.isAdmin && c.isMember != false && c.group?.community == nil }

@MainActor private func myFreeGroups(_ host: ChatHost) -> [ConversationDto] { host.conversations.filter(isFreeGroup) }

/// Every group I run, read page by page from the server (the list on hand may not hold them all).
private func loadFreeGroups() async throws -> [ConversationDto] {
    var out: [ConversationDto] = []
    var page = 1
    while page <= 10 {
        let res = try await ChatApi.conversations(filter: "groups", page: page, perPage: 50)
        out += (res.data ?? []).filter(isFreeGroup)
        if res.pagination?.hasMorePages != true { break }
        page += 1
    }
    var seen = Set<String>()
    return out.filter { seen.insert($0.id).inserted }
}

private struct GroupPicker: View {
    @ObservedObject private var ch = Ch.shared
    let groups: [ConversationDto]?
    @Binding var picked: [String]
    var showTitle = true
    @State private var query = ""

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            if let groups {
                let q = query.trimmingCharacters(in: .whitespaces)
                let shown = groups.filter { q.isEmpty || ($0.title ?? "").localizedCaseInsensitiveContains(q) }
                if showTitle { Text(L("cm_pick_groups")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).padding(.leading, 4).padding(.bottom, 8) }
                if !groups.isEmpty {
                    ChField(placeholder: L("ch_info_search"), text: $query, icon: "magnifyingglass", clearable: true).padding(.bottom, 10)
                }
                if shown.isEmpty {
                    VStack(spacing: 10) {
                        Image(systemName: "person.3.fill").font(.system(size: 22)).foregroundStyle(ch.red).frame(width: 56, height: 56).background(ch.red.opacity(0.08), in: Circle())
                        Text(L("cm_no_free_groups")).font(.dorr(13)).foregroundStyle(ch.mut).multilineTextAlignment(.center).padding(.horizontal, 12)
                    }
                    .frame(maxWidth: .infinity).padding(.vertical, 18)
                } else {
                    VStack(spacing: 8) {
                        ForEach(Array(shown.enumerated()), id: \.element.id) { i, c in
                            let on = picked.contains(c.id)
                            Button {
                                if on { picked.removeAll { $0 == c.id } } else { picked.append(c.id) }
                            } label: {
                                HStack(spacing: 12) {
                                    ChAvatar(url: c.avatar, name: c.title, key: c.id, size: 46, isGroup: true)
                                    VStack(alignment: .leading, spacing: 0) {
                                        Text(verbatim: c.title ?? "").font(.dorr(15, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                                        CommunityStat(icon: "person.fill", count: c.group?.membersCount ?? 0, label: L("cm_member"), ink: ch.ink, mut: ch.mut, accent: ch.red)
                                    }
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 21)).foregroundStyle(on ? ch.red : ch.mut)
                                }
                                .padding(12)
                                .background(on ? ch.red.opacity(0.08) : ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                                .shadow(color: .black.opacity(0.06), radius: 3, y: 1)
                                .contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                            .chStagger(i)
                        }
                    }
                }
            } else {
                ProgressView().tint(ch.red).frame(maxWidth: .infinity).frame(height: 120)
            }
        }
    }
}

/// Name it, say what it's for, and pick the groups I run that start in it.
private struct NewCommunitySheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    let onCreated: (CommunityDto) -> Void
    @State private var name = ""
    @State private var description = ""
    @State private var picked: [String] = []
    @State private var busy = false
    @State private var mine: [ConversationDto]?

    var body: some View {
        ChSheetCard {
            ScrollView {
                VStack(alignment: .leading, spacing: 8) {
                    HStack(spacing: 12) {
                        Image(systemName: "person.3.fill").font(.system(size: 18)).foregroundStyle(Color(hex: 0x14B8A6))
                            .frame(width: 44, height: 44).background(Color(hex: 0x14B8A6).opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("cm_new")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                            Text(L("cm_new_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut)
                        }
                    }
                    .padding(.bottom, 6)
                    ChField(placeholder: L("cm_name_hint"), text: $name, icon: "person.3.fill", limit: 100)
                    ChField(placeholder: L("cm_description_hint"), text: $description, icon: "text.alignleft", lines: 1...4, limit: 2000)
                    GroupPicker(groups: mine, picked: $picked).padding(.top, 6)
                    ChPrimaryButton(text: L("cm_create"), icon: "person.3.fill", enabled: !name.trimmingCharacters(in: .whitespaces).isEmpty && !busy, action: create)
                        .padding(.top, 8)
                }
            }
            .frame(maxHeight: 660)
        }
        .task { mine = (try? await loadFreeGroups()) ?? myFreeGroups(host) }
    }

    private func create() {
        busy = true
        Task {
            do {
                var body: [String: Any?] = ["name": name.trimmingCharacters(in: .whitespaces), "groups": picked]
                let d = description.trimmingCharacters(in: .whitespacesAndNewlines)
                if !d.isEmpty { body["description"] = d }
                if let made = try await ChatApi.createCommunity(body).data {
                    await host.refreshList()
                    onCreated(made)
                    onDismiss()
                }
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
            busy = false
        }
    }
}

/// A new group inside this community: name, and optionally what it's for.
private struct NewCommunityGroupSheet: View {
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    let onCreate: (String, String) -> Void
    @State private var name = ""
    @State private var description = ""

    var body: some View {
        ChSheetCard {
            HStack(spacing: 12) {
                Image(systemName: "person.2.badge.plus").font(.system(size: 18)).foregroundStyle(ch.red)
                    .frame(width: 44, height: 44).background(ch.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                Text(L("cm_new_group")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            }
            .padding(.bottom, 14)
            ChField(placeholder: L("ch_group_name_hint"), text: $name, icon: "person.2.badge.plus", limit: 100)
            ChField(placeholder: L("cm_description_hint"), text: $description, icon: "text.alignleft", lines: 1...4, limit: 2000).padding(.top, 8)
            ChPrimaryButton(text: L("cm_create_group"), icon: "person.2.badge.plus", enabled: !name.trimmingCharacters(in: .whitespaces).isEmpty) {
                onCreate(name.trimmingCharacters(in: .whitespaces), description.trimmingCharacters(in: .whitespacesAndNewlines))
                onDismiss()
            }
            .padding(.top, 16)
        }
    }
}

/// Name and what it's for — the same two fields as making one.
private struct EditCommunitySheet: View {
    @ObservedObject private var ch = Ch.shared
    let c: CommunityDto
    let onDismiss: () -> Void
    let onSave: (String, String) -> Void
    @State private var name = ""
    @State private var description = ""
    @State private var started = false

    var body: some View {
        ChSheetCard {
            Text(L("cm_rename")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 14)
            ChField(placeholder: L("cm_name_hint"), text: $name, icon: "pencil", limit: 100)
            ChField(placeholder: L("cm_description_hint"), text: $description, icon: "text.alignleft", lines: 1...4, limit: 2000).padding(.top, 8)
            ChPrimaryButton(text: L("ch_save"), enabled: !name.trimmingCharacters(in: .whitespaces).isEmpty) {
                onSave(name.trimmingCharacters(in: .whitespaces), description.trimmingCharacters(in: .whitespacesAndNewlines))
                onDismiss()
            }
            .padding(.top, 16)
        }
        .onAppear {
            guard !started else { return }
            started = true
            name = c.name
            description = c.description ?? ""
        }
    }
}

// MARK: - one community

struct CommunityPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let id: String
    @State private var community: CommunityDto?
    @State private var failed: String?
    @State private var sheet: String?
    @State private var groupMenu: CommunityGroupDto?
    @State private var searching = false
    @State private var infoQuery = ""
    @State private var scrolled = false

    var body: some View {
        ZStack(alignment: .top) {
            ch.bg.ignoresSafeArea()
            if let c = community {
                content(c)
            } else if let failed {
                ChEmptyState(icon: "person.3.fill", title: L("cm_title"), text: failed)
            } else {
                ProgressView().tint(ch.red).frame(maxWidth: .infinity, maxHeight: .infinity)
            }
            // The name in a bar once the head scrolls away.
            HStack {
                Text(verbatim: community?.name ?? "").font(.dorr(16, .bold)).foregroundStyle(.white).lineLimit(1)
                Spacer(minLength: 96)
            }
            .padding(.horizontal, 16).padding(.vertical, 12)
            .frame(maxWidth: .infinity)
            .background(ch.headerBrush.ignoresSafeArea(edges: .top))
            .opacity(scrolled && !searching ? 1 : 0)
            .animation(.easeOut(duration: 0.2), value: scrolled)
            topBar
        }
        .task(id: id) { await load() }
        .dorrDialog(isPresented: Binding(get: { (sheet != nil && sheet != "delete") || groupMenu != nil }, set: { if !$0 { sheet = nil; groupMenu = nil } }), bottom: true) {
            if let c = community { sheetView(c) }
        }
        .dorrDialog(isPresented: Binding(get: { sheet == "delete" }, set: { if !$0 { sheet = nil } })) {
            if let c = community {
                ConfirmDialog(icon: "trash.fill", title: L("cm_delete"), message: L("cm_delete_sub"), confirmLabel: L("ch_delete"), onConfirm: {
                    sheet = nil
                    Task {
                        do {
                            _ = try await ChatApi.deleteCommunity(c.id)
                            host.showToast(L("cm_delete_done"))
                            await host.refreshList()
                            host.pop()
                        } catch {
                            if let message = error.apiFailure.message { host.showToast(message, error: true) }
                        }
                    }
                }, onDismiss: { sheet = nil })
            }
        }
    }

    @ViewBuilder
    private var topBar: some View {
        HStack(spacing: 8) {
            if searching {
                ChField(placeholder: L("ch_info_search"), text: $infoQuery, icon: "magnifyingglass", limit: 80, clearable: true)
                DarkHeroButton(icon: "xmark", label: L("ch_close_sheet")) { searching = false; infoQuery = "" }
            } else {
                Spacer()
                DarkHeroButton(icon: "magnifyingglass", label: L("ch_info_search")) { searching = true }
                DarkHeroButton(icon: "chevron.right", label: L("common_back")) { host.pop() }
            }
        }
        .padding(.horizontal, 16).padding(.vertical, 8)
    }

    private func load() async {
        do { community = try await ChatApi.community(id).data } catch { failed = error.apiFailure.message ?? L("ch_error_network") }
    }

    /// Runs a change and shows the community it hands back.
    private func act(_ block: @escaping () async throws -> CommunityDto?) {
        Task {
            do { if let fresh = try await block() { community = fresh } } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
        }
    }

    private var q: String { infoQuery.trimmingCharacters(in: .whitespaces) }
    private func hit(_ parts: String?...) -> Bool { q.isEmpty || parts.contains { ($0 ?? "").localizedCaseInsensitiveContains(q) } }
    private func hitKeys(_ keys: String...) -> Bool { q.isEmpty || keys.contains { L($0).localizedCaseInsensitiveContains(q) } }

    private func join(_ c: CommunityDto, _ g: CommunityGroupDto) {
        guard g.requestPending != true else { return }
        act {
            let res = try await ChatApi.joinCommunityGroup(c.id, group: g.id).data
            if res?.status == "joined" {
                await host.refreshList()
                host.push(.conversation(g.id))
            } else {
                host.showToast(L("ch_join_request_sent"))
            }
            return try await ChatApi.community(c.id).data
        }
    }

    private func content(_ c: CommunityDto) -> some View {
        let groups = c.groups ?? []
        let membersLabel = L("ch_members", 0).replacingOccurrences(of: "\\d+", with: "", options: .regularExpression)
        let inside = groups.filter { $0.isMember == true }.filter { hit($0.name, membersLabel) }
        let outside = groups.filter { $0.isMember != true }.filter { g in hit(g.name, membersLabel, L(g.approveJoins == true ? "cm_ask_join" : "cm_join")) }
        let limit = q.isEmpty ? 6 : Int.max
        let insideShown = Array(inside.prefix(limit))
        let outsideShown = Array(outside.prefix(limit))
        return ScrollView {
            LazyVStack(spacing: 0) {
                head(c)
                    .background(GeometryReader { proxy in
                        Color.clear.preference(key: CommunityScrollKey.self, value: proxy.frame(in: .named("community")).minY)
                    })
                // Announcements: only admins write there (94).
                if hitKeys("cm_announcements", "cm_announcements_sub") {
                    CommunitySettingRow(icon: "megaphone.fill", title: L("cm_announcements"), subtitle: L("cm_announcements_sub")) {
                        if let a = c.announcementId { host.push(.conversation(a)) }
                    }
                    .padding(.horizontal, 8).padding(.vertical, 4)
                }
                if !inside.isEmpty { section(L("cm_your_groups")) }
                ForEach(Array(insideShown.enumerated()), id: \.element.id) { i, g in
                    GroupLine(g: g, admin: c.isAdmin == true, index: i, lined: i < insideShown.count - 1, onLong: { groupMenu = g }) { host.push(.conversation(g.id)) }
                }
                if q.isEmpty && inside.count > 6 { SeeAllGroups(total: inside.count) { host.push(.communityGroups(c.id, yours: true)) } }
                if !outside.isEmpty { section(L("cm_other_groups")) }
                ForEach(Array(outsideShown.enumerated()), id: \.element.id) { i, g in
                    // Hop in (95): straight in, or ask when that group approves new members.
                    GroupLine(g: g, admin: c.isAdmin == true, index: i, lined: i < outsideShown.count - 1, onLong: { groupMenu = g }) { join(c, g) }
                }
                if q.isEmpty && outside.count > 6 { SeeAllGroups(total: outside.count) { host.push(.communityGroups(c.id, yours: false)) } }
                // Admins (96): bring groups in, make one, the people, the name.
                if c.isAdmin == true {
                    let adminsLine = L("cm_admins_of", c.adminsCount ?? 0, c.maxAdmins ?? 20)
                    if hitKeys("cm_add_groups", "cm_new_group", "cm_members", "cm_rename") || hit(adminsLine) {
                        VStack(spacing: 0) {
                            if hitKeys("cm_add_groups") { SettingRow(icon: "link.badge.plus", title: L("cm_add_groups")) { sheet = "add" } }
                            if hitKeys("cm_new_group") { SettingRow(icon: "person.2.badge.plus", title: L("cm_new_group")) { sheet = "newGroup" } }
                            if hitKeys("cm_members") || hit(adminsLine) { SettingRow(icon: "person.3.fill", title: L("cm_members"), value: adminsLine) { sheet = "members" } }
                            if hitKeys("cm_rename") { SettingRow(icon: "pencil", title: L("cm_rename"), lined: false) { sheet = "rename" } }
                        }
                        .padding(.horizontal, 8).padding(.vertical, 4)
                    }
                } else if hitKeys("cm_members") {
                    SettingRow(icon: "person.3.fill", title: L("cm_members"), value: String(c.membersCount ?? 0), lined: false) { sheet = "members" }
                        .padding(.horizontal, 8).padding(.vertical, 4)
                }
                // The creator stays (98): they can only delete it; everyone else can leave.
                if c.isCreator == true, hitKeys("cm_delete") {
                    SettingRow(icon: "trash", title: L("cm_delete"), danger: true, opens: false, lined: false) { sheet = "delete" }.padding(8)
                } else if c.isCreator != true, hitKeys("cm_leave") {
                    SettingRow(icon: "rectangle.portrait.and.arrow.right", title: L("cm_leave"), danger: true, opens: false, lined: false) { sheet = "leave" }.padding(8)
                }
            }
            .padding(.bottom, 40)
        }
        .coordinateSpace(name: "community")
        .onPreferenceChange(CommunityScrollKey.self) { y in scrolled = y < -140 }
        .ignoresSafeArea(edges: .top)
    }

    private func head(_ c: CommunityDto) -> some View {
        VStack(spacing: 0) {
            ChAvatar(url: c.avatar, name: c.name, key: c.id, size: 72, isGroup: true)
            HStack(spacing: 8) {
                Text(verbatim: c.name).font(.dorr(18, .bold)).foregroundStyle(.white).multilineTextAlignment(.center).lineLimit(2)
                if c.isAdmin == true {
                    Button { sheet = "rename" } label: {
                        Image(systemName: "pencil").font(.system(size: 13, weight: .bold)).foregroundStyle(.white)
                            .frame(width: 32, height: 32).background(Color.white.opacity(0.16), in: Circle())
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 16).padding(.top, 8)
            CommunityStats(groups: c.groupsCount ?? 0, members: c.membersCount ?? 0, ink: .white, mut: .white.opacity(0.8), accent: .white)
            if let d = c.description, !d.trimmingCharacters(in: .whitespaces).isEmpty {
                Text(verbatim: d).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.9)).multilineTextAlignment(.center).lineLimit(2)
                    .padding(.horizontal, 24).padding(.vertical, 4)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.top, 48 + (UIApplication.shared.connectedScenes.compactMap { ($0 as? UIWindowScene)?.keyWindow?.safeAreaInsets.top }.first ?? 0))
        .padding(.bottom, 24)
        .background(ch.headerBrush, in: BottomRoundedRectangle(radius: 34))
    }

    private func section(_ text: String) -> some View {
        Text(verbatim: text).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 20).padding(.top, 14).padding(.bottom, 6)
    }

    @ViewBuilder
    private func sheetView(_ c: CommunityDto) -> some View {
        let close = { sheet = nil; groupMenu = nil }
        if let g = groupMenu, c.isAdmin == true {
            ChoiceSheet(title: g.name, subtitle: L("cm_remove_group_sub"), options: [(L("cm_remove_group"), { act { try await ChatApi.removeCommunityGroup(c.id, group: g.id).data } })], danger: true, onDismiss: close)
        } else {
            switch sheet {
            case "add":
                AddGroupsSheet(host: host, onDismiss: close) { ids in
                    act {
                        let fresh = try await ChatApi.addCommunityGroups(c.id, ["groups": ids]).data
                        await host.refreshList()
                        return fresh
                    }
                }
            case "newGroup":
                NewCommunityGroupSheet(onDismiss: close) { name, description in
                    act {
                        var body: [String: Any?] = ["name": name]
                        if !description.isEmpty { body["description"] = description }
                        let fresh = try await ChatApi.addCommunityGroups(c.id, body).data
                        await host.refreshList()
                        return fresh
                    }
                }
            case "rename":
                EditCommunitySheet(c: c, onDismiss: close) { name, description in
                    act { try await ChatApi.updateCommunity(c.id, ["name": name, "description": description]).data }
                }
            case "members":
                CommunityMembersSheet(host: host, c: c) { Task { await load() } }
            case "leave":
                ChoiceSheet(title: L("cm_leave"), subtitle: L("cm_leave_sub"), options: [(L("cm_leave"), {
                    act {
                        _ = try await ChatApi.leaveCommunity(c.id)
                        await host.refreshList()
                        host.pop()
                        return nil
                    }
                })], danger: true, onDismiss: close)
            default:
                EmptyView()
            }
        }
    }
}

private struct CommunityScrollKey: PreferenceKey {
    static var defaultValue: CGFloat = 0
    static func reduce(value: inout CGFloat, nextValue: () -> CGFloat) { value = nextValue() }
}

private struct CommunitySettingRow: View {
    @ObservedObject private var ch = Ch.shared
    let icon: String
    let title: String
    let subtitle: String
    let action: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            Button(action: action) {
                HStack(spacing: 12) {
                    Image(systemName: icon).font(.system(size: 15)).foregroundStyle(ch.red)
                        .frame(width: 32, height: 32).background(ch.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: title).font(.dorr(14.5, .semibold)).foregroundStyle(ch.ink).lineLimit(1)
                        Text(verbatim: subtitle).font(.dorr(12)).foregroundStyle(ch.mut).lineLimit(2).multilineTextAlignment(.leading)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(ch.soft)
                }
                .padding(.horizontal, 14).padding(.vertical, 11)
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            Rectangle().fill(ch.line).frame(height: 1).padding(.leading, 58).padding(.trailing, 16)
        }
    }
}

private struct GroupLine: View {
    @ObservedObject private var ch = Ch.shared
    let g: CommunityGroupDto
    let admin: Bool
    var index = 0
    var lined = true
    let onLong: () -> Void
    let onClick: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 12) {
                ChAvatar(url: g.avatar, name: g.name, key: g.id, size: 40, isGroup: true)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: g.name).font(.dorr(15, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                    CommunityStat(icon: "person.fill", count: g.membersCount ?? 0, label: L("cm_member"), ink: ch.ink, mut: ch.mut, accent: ch.red)
                }
                Spacer(minLength: 0)
                if g.isMember == true {
                    EmptyView()
                } else if g.requestPending == true {
                    Image(systemName: "hourglass").font(.system(size: 18)).foregroundStyle(Color(hex: 0xD97706))
                } else {
                    Text(L(g.approveJoins == true ? "cm_ask_join" : "cm_join")).font(.dorr(13, .bold)).foregroundStyle(.white)
                        .padding(.horizontal, 12).padding(.vertical, 6).background(ch.red, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
            }
            .padding(.horizontal, 16).padding(.vertical, 10)
            .contentShape(Rectangle())
            .onTapGesture(perform: onClick)
            .onLongPressGesture { if admin { onLong() } }
            if lined { Rectangle().fill(ch.line).frame(height: 1).padding(.leading, 68).padding(.trailing, 16) }
        }
        .chStagger(index)
    }
}

private struct SeeAllGroups: View {
    @ObservedObject private var ch = Ch.shared
    let total: Int
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(L("ch_see_all_members", total)).font(.dorr(14, .heavy)).foregroundStyle(ch.red)
                .frame(maxWidth: .infinity).padding(.vertical, 12)
                .background(ch.red.opacity(0.1), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(ch.red, lineWidth: 1.5))
        }
        .buttonStyle(.plain)
        .padding(.horizontal, 16).padding(.vertical, 8)
    }
}

/// Every group of one side of a community (mine, or the others), searched and shown fifteen more at a time.
struct CommunityGroupsPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let id: String
    let yours: Bool
    @State private var community: CommunityDto?
    @State private var query = ""
    @State private var visibleCount = 15
    @State private var groupMenu: CommunityGroupDto?

    private func act(_ block: @escaping () async throws -> CommunityDto?) {
        Task {
            do { if let fresh = try await block() { community = fresh } } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
        }
    }

    var body: some View {
        let q = query.trimmingCharacters(in: .whitespaces)
        let source = (community?.groups ?? []).filter { yours ? $0.isMember == true : $0.isMember != true }
        let groups = q.isEmpty ? source : source.filter { $0.name.localizedCaseInsensitiveContains(q) }
        let paged = Array(groups.prefix(visibleCount))
        ChSettingsPage(title: L(yours ? "cm_your_groups" : "cm_other_groups"), onBack: { host.pop() }) {
            VStack(spacing: 0) {
                ChField(placeholder: L("ch_info_search"), text: $query, icon: "magnifyingglass", clearable: true).padding(.horizontal, 16).padding(.vertical, 8)
                if let c = community {
                    if groups.isEmpty {
                        Text(L("ch_no_results")).font(.dorr(14)).foregroundStyle(ch.soft).multilineTextAlignment(.center).frame(maxWidth: .infinity).padding(30)
                        Spacer(minLength: 0)
                    } else {
                        ScrollView {
                            LazyVStack(spacing: 0) {
                                ForEach(Array(paged.enumerated()), id: \.element.id) { i, g in
                                    GroupLine(g: g, admin: c.isAdmin == true, index: i, lined: i < paged.count - 1, onLong: { groupMenu = g }) {
                                        if g.isMember == true { host.push(.conversation(g.id)); return }
                                        guard g.requestPending != true else { return }
                                        act {
                                            let res = try await ChatApi.joinCommunityGroup(c.id, group: g.id).data
                                            if res?.status == "joined" {
                                                await host.refreshList()
                                                host.push(.conversation(g.id))
                                            } else {
                                                host.showToast(L("ch_join_request_sent"))
                                            }
                                            return try await ChatApi.community(c.id).data
                                        }
                                    }
                                    .onAppear { if i >= paged.count - 3 && visibleCount < groups.count { visibleCount = min(visibleCount + 15, groups.count) } }
                                }
                            }
                            .padding(.bottom, 24)
                        }
                    }
                } else {
                    ProgressView().tint(ch.red).frame(maxWidth: .infinity, maxHeight: .infinity)
                }
            }
        }
        .task(id: id) { if let fresh = try? await ChatApi.community(id).data { community = fresh } }
        .onChange(of: query) { _ in visibleCount = 15 }
        .dorrDialog(isPresented: Binding(get: { groupMenu != nil && community?.isAdmin == true }, set: { if !$0 { groupMenu = nil } }), bottom: true) {
            if let g = groupMenu, let c = community {
                ChoiceSheet(title: g.name, subtitle: L("cm_remove_group_sub"), options: [(L("cm_remove_group"), { act { try await ChatApi.removeCommunityGroup(c.id, group: g.id).data } })],
                            danger: true) { groupMenu = nil }
            }
        }
    }
}

/// Bring in more groups I run.
private struct AddGroupsSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    let onAdd: ([String]) -> Void
    @State private var picked: [String] = []
    @State private var mine: [ConversationDto]?

    var body: some View {
        ChSheetCard {
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    HStack(spacing: 12) {
                        Image(systemName: "link.badge.plus").font(.system(size: 18)).foregroundStyle(ch.red)
                            .frame(width: 44, height: 44).background(ch.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("cm_add_groups")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                            Text(L("cm_pick_groups")).font(.dorr(12.5)).foregroundStyle(ch.mut)
                        }
                    }
                    .padding(.bottom, 2)
                    GroupPicker(groups: mine, picked: $picked, showTitle: false)
                    ChPrimaryButton(text: L("cm_add_groups"), icon: "link.badge.plus", enabled: !picked.isEmpty) {
                        onAdd(picked)
                        onDismiss()
                    }
                }
            }
            .frame(maxHeight: 660)
        }
        .task { mine = (try? await loadFreeGroups()) ?? myFreeGroups(host) }
    }
}

/// Everyone in it; admins make / unmake admins and remove people from the community and all its groups.
private struct CommunityMembersSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let c: CommunityDto
    let onChanged: () -> Void
    @State private var members: [MemberDto]?
    @State private var menu: MemberDto?
    @State private var confirmRemove: MemberDto?
    @State private var query = ""

    var body: some View {
        if let m = confirmRemove {
            let name = m.profile?.name ?? ""
            ConfirmDialog(icon: "person.fill.xmark", title: L("cm_remove_member"), message: L("cm_confirm_remove_member", name), confirmLabel: L("ch_delete"), onConfirm: {
                confirmRemove = nil
                menu = nil
                act(done: L("cm_member_removed", name)) { _ = try await ChatApi.removeCommunityMember(c.id, participant: m.participantId) }
            }, onDismiss: { confirmRemove = nil })
        } else if let m = menu {
            CommunityMemberActionsSheet(member: m, onDismiss: { menu = nil }, onRole: {
                let makeAdmin = m.role != "admin"
                let name = m.profile?.name ?? ""
                menu = nil
                act(done: L(makeAdmin ? "cm_member_now_admin" : "cm_member_admin_dismissed", name)) {
                    _ = try await ChatApi.communityRole(c.id, participant: m.participantId, role: makeAdmin ? "admin" : "member")
                }
            }, onRemove: { confirmRemove = m })
        } else {
            ChSheetCard {
                HStack(spacing: 12) {
                    Image(systemName: "person.3.fill").font(.system(size: 17)).foregroundStyle(ch.red)
                        .frame(width: 44, height: 44).background(ch.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("cm_members")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                        Text(L("cm_admins_of", c.adminsCount ?? 0, c.maxAdmins ?? 20)).font(.dorr(12.5)).foregroundStyle(ch.mut)
                    }
                }
                ChField(placeholder: L("ch_search_contacts"), text: $query, icon: "magnifyingglass", clearable: true).padding(.vertical, 12)
                if let list = members {
                    let q = query.trimmingCharacters(in: .whitespaces)
                    let filtered = list.filter { q.isEmpty || ($0.profile?.name ?? "").localizedCaseInsensitiveContains(q) }
                    if filtered.isEmpty {
                        Text(L("ch_no_results")).font(.dorr(14)).foregroundStyle(ch.soft).frame(maxWidth: .infinity).padding(24)
                    } else {
                        ScrollView {
                            LazyVStack(spacing: 8) {
                                ForEach(Array(filtered.enumerated()), id: \.element.id) { i, m in
                                    let canManage = c.isAdmin == true && m.role != "owner" && m.profile?.isMe != true
                                    CommunityMemberCard(host: host, m: m, canManage: canManage) { menu = m }.chStagger(i)
                                }
                            }
                            .padding(2)
                        }
                        .frame(maxHeight: 480)
                    }
                } else {
                    ProgressView().tint(ch.red).frame(maxWidth: .infinity).frame(height: 120)
                }
            }
            .task { if members == nil { await load() } }
        }
    }

    private func load() async { members = (try? await ChatApi.communityMembers(c.id).data) ?? [] }

    private func act(done: String? = nil, _ block: @escaping () async throws -> Void) {
        Task {
            do {
                try await block()
                await load()
                onChanged()
                if let done { host.showToast(done) }
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
        }
    }
}

private struct CommunityMemberCard: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let m: MemberDto
    let canManage: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 12) {
                ChAvatar(url: m.profile?.avatar, name: m.profile?.name, key: m.profile?.key, size: 46, online: host.presence[m.profile?.key ?? ""]?.online == true)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: m.profile?.isMe == true ? L("ch_you") : m.profile?.name ?? "").font(.dorr(15, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                    if let phone = m.profile?.phone, !phone.isEmpty { Text(verbatim: ltrNumber(phone)).font(.dorr(12.5)).foregroundStyle(ch.mut) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if m.role == "owner" { RoleTag(text: L("cm_creator")) } else if m.role == "admin" { RoleTag(text: L("cm_admin")) }
            }
            .padding(12)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .shadow(color: .black.opacity(0.06), radius: 3, y: 1)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!canManage)
    }
}

private struct RoleTag: View {
    @ObservedObject private var ch = Ch.shared
    let text: String

    var body: some View {
        Text(verbatim: text).font(.dorr(11.5, .heavy)).foregroundStyle(ch.red).padding(.horizontal, 8).padding(.vertical, 3)
            .background(ch.red.opacity(0.1), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
    }
}

/// One member: who (with the number), make / unmake admin, remove (asked first).
private struct CommunityMemberActionsSheet: View {
    @ObservedObject private var ch = Ch.shared
    let member: MemberDto
    let onDismiss: () -> Void
    let onRole: () -> Void
    let onRemove: () -> Void

    var body: some View {
        let name = member.profile?.name ?? ""
        let makeAdmin = member.role != "admin"
        ChSheetCard {
            VStack(spacing: 0) {
                ChAvatar(url: member.profile?.avatar, name: name, key: member.profile?.key, size: 72)
                Text(verbatim: name).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).lineLimit(1).padding(.top, 10)
                if let phone = member.profile?.phone, !phone.isEmpty { Text(verbatim: ltrNumber(phone)).font(.dorr(13, .semibold)).foregroundStyle(ch.mut) }
                Rectangle().fill(ch.line).frame(height: 1).padding(.top, 16)
                row(makeAdmin ? "person.badge.shield.checkmark.fill" : "person.badge.minus", L(makeAdmin ? "cm_make_admin" : "cm_unmake_admin"), action: onRole)
                Rectangle().fill(ch.line).frame(height: 1)
                row("person.fill.xmark", L("cm_remove_member"), danger: true, action: onRemove)
                Rectangle().fill(ch.line).frame(height: 1)
                Button(action: onDismiss) {
                    Text(L("common_cancel")).font(.dorr(15, .bold)).foregroundStyle(ch.ink).frame(maxWidth: .infinity).padding(.vertical, 14)
                        .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                }
                .buttonStyle(.plain)
                .padding(.top, 14)
            }
            .frame(maxWidth: .infinity)
        }
    }

    private func row(_ icon: String, _ label: String, danger: Bool = false, action: @escaping () -> Void) -> some View {
        let tint = danger ? ch.danger : ch.red
        return Button(action: action) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 15)).foregroundStyle(tint)
                    .frame(width: 34, height: 34).background(tint.opacity(0.1), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                Text(verbatim: label).font(.dorr(15, .bold)).foregroundStyle(danger ? ch.danger : ch.ink).frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(.horizontal, 4).padding(.vertical, 12)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

