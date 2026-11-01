import SwiftUI

// androidApp `chat/ChatCommunityUi.kt` — communities (WhatsApp list 93–98): related groups under one roof, with an
// announcements group only its admins write in. Members see every group in one place and hop in; admins (up to 20)
// add / make / remove groups and people; the creator stays an admin until they delete it.

struct CommunitiesPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var list: [CommunityDto]?
    @State private var creating = false

    var body: some View {
        ChPage(title: L("cm_title"), onBack: { host.pop() }) {
            ScrollView {
                LazyVStack(spacing: 8) {
                    CommunityRow(icon: "plus", title: L("cm_new"), sub: L("cm_new_sub"), avatar: nil) { creating = true }
                    if let items = list {
                        if items.isEmpty {
                            ChEmptyState(icon: "person.3.fill", title: L("cm_title"), text: L("cm_empty")).frame(height: 340)
                        } else {
                            ForEach(Array(items.enumerated()), id: \.element.id) { i, c in
                                CommunityRow(icon: nil, title: c.name, sub: L("cm_counts", c.groupsCount ?? 0, c.membersCount ?? 0), avatar: c.avatar, key: c.id) {
                                    host.push(.community(c.id))
                                }
                                .chStagger(i)
                            }
                        }
                    } else {
                        ProgressView().tint(ch.red).frame(height: 160)
                    }
                }
                .padding(14)
            }
        }
        .task { list = (try? await ChatApi.communities().data) ?? [] }
        .dorrDialog(isPresented: $creating, bottom: true) {
            NewCommunitySheet(host: host, onDismiss: { creating = false }) { made in
                list = [made] + (list ?? [])
                host.push(.community(made.id))
            }
        }
    }
}

private struct CommunityRow: View {
    @ObservedObject private var ch = Ch.shared
    let icon: String?
    let title: String
    let sub: String?
    let avatar: String?
    var key: String?
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
                    if let sub { Text(verbatim: sub).font(.dorr(12.5)).foregroundStyle(ch.mut).lineLimit(2).multilineTextAlignment(.leading) }
                }
                Spacer(minLength: 0)
            }
            .padding(12)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

/// Groups I run that aren't in a community yet — they can be brought in.
@MainActor private func myFreeGroups(_ host: ChatHost) -> [ConversationDto] {
    host.conversations.filter { $0.isGroup && !$0.isChannel && $0.isAdmin && $0.isMember != false && $0.group?.community == nil }
}

private struct GroupPicker: View {
    @ObservedObject private var ch = Ch.shared
    let groups: [ConversationDto]
    @Binding var picked: [String]

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(L("cm_pick_groups")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).padding(.leading, 4).padding(.bottom, 6)
            if groups.isEmpty {
                Text(L("cm_no_free_groups")).font(.dorr(13)).foregroundStyle(ch.soft).padding(4)
            }
            ForEach(Array(groups.enumerated()), id: \.element.id) { i, c in
                let on = picked.contains(c.id)
                Button {
                    if on { picked.removeAll { $0 == c.id } } else { picked.append(c.id) }
                } label: {
                    HStack(spacing: 10) {
                        ChAvatar(url: c.avatar, name: c.title, key: c.id, size: 40, isGroup: true)
                        Text(verbatim: c.title ?? "").font(.dorr(14.5, .semibold)).foregroundStyle(ch.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                        Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 21)).foregroundStyle(on ? ch.red : ch.mut)
                    }
                    .padding(.vertical, 8).padding(.horizontal, 4).contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .chStagger(i)
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
                    GroupPicker(groups: myFreeGroups(host), picked: $picked).padding(.top, 6)
                    ChPrimaryButton(text: L("cm_create"), icon: "person.3.fill", enabled: !name.trimmingCharacters(in: .whitespaces).isEmpty && !busy, action: create)
                        .padding(.top, 8)
                }
            }
            .frame(maxHeight: 620)
        }
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

// MARK: - one community

struct CommunityPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let id: String
    @State private var community: CommunityDto?
    @State private var failed: String?
    @State private var sheet: String?
    @State private var groupMenu: CommunityGroupDto?

    var body: some View {
        ChPage(title: community?.name ?? L("cm_title"), onBack: { host.pop() }) {
            if let c = community {
                content(c)
            } else if let failed {
                ChEmptyState(icon: "person.3.fill", title: L("cm_title"), text: failed)
            } else {
                ProgressView().tint(ch.red)
            }
        }
        .task(id: id) { await load() }
        .dorrDialog(isPresented: Binding(get: { sheet != nil || groupMenu != nil }, set: { if !$0 { sheet = nil; groupMenu = nil } }), bottom: true) {
            if let c = community { sheetView(c) }
        }
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

    private func content(_ c: CommunityDto) -> some View {
        let groups = c.groups ?? []
        let inside = groups.filter { $0.isMember == true }
        let outside = groups.filter { $0.isMember != true }
        return ScrollView {
            LazyVStack(spacing: 8) {
                // Who it is.
                VStack(spacing: 0) {
                    ChAvatar(url: c.avatar, name: c.name, key: c.id, size: 84, isGroup: true)
                    Text(verbatim: c.name).font(.dorr(20, .heavy)).foregroundStyle(ch.ink).multilineTextAlignment(.center).padding(.top, 10)
                    Text(L("cm_counts", c.groupsCount ?? 0, c.membersCount ?? 0)).font(.dorr(13)).foregroundStyle(ch.mut)
                    if let d = c.description, !d.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(verbatim: d).font(.dorr(13.5)).foregroundStyle(ch.ink).multilineTextAlignment(.center).padding(.top, 8)
                    }
                }
                .frame(maxWidth: .infinity).padding(18)
                .background(ch.surface, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
                // Announcements: only admins write there (94).
                CommunityRow(icon: "megaphone.fill", title: L("cm_announcements"), sub: L("cm_announcements_sub"), avatar: nil) {
                    if let a = c.announcementId { host.push(.conversation(a)) }
                }
                if !inside.isEmpty { section(L("cm_your_groups")) }
                ForEach(inside) { g in GroupLine(g: g, admin: c.isAdmin == true, onLong: { groupMenu = g }) { host.push(.conversation(g.id)) } }
                if !outside.isEmpty { section(L("cm_other_groups")) }
                ForEach(outside) { g in
                    // Hop in (95): straight in, or ask when that group approves new members.
                    GroupLine(g: g, admin: c.isAdmin == true, onLong: { groupMenu = g }) {
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
                }
                // Admins (96): bring groups in, make one, the people, the name.
                InfoCard {
                    if c.isAdmin == true {
                        SettingRow(icon: "link.badge.plus", title: L("cm_add_groups")) { sheet = "add" }
                        SettingRow(icon: "person.2.badge.plus", title: L("cm_new_group")) { sheet = "newGroup" }
                        SettingRow(icon: "person.3.fill", title: L("cm_members"), value: L("cm_admins_of", c.adminsCount ?? 0, c.maxAdmins ?? 20)) { sheet = "members" }
                        SettingRow(icon: "pencil", title: L("cm_rename"), lined: false) { sheet = "rename" }
                    } else {
                        SettingRow(icon: "person.3.fill", title: L("cm_members"), value: String(c.membersCount ?? 0), lined: false) { sheet = "members" }
                    }
                }
                // The creator stays (98): they can only delete it; everyone else can leave.
                InfoCard {
                    if c.isCreator == true {
                        SettingRow(icon: "trash", title: L("cm_delete"), danger: true, lined: false) { sheet = "delete" }
                    } else {
                        SettingRow(icon: "rectangle.portrait.and.arrow.right", title: L("cm_leave"), danger: true, lined: false) { sheet = "leave" }
                    }
                }
            }
            .padding(14)
        }
    }

    private func section(_ text: String) -> some View {
        Text(verbatim: text).font(.dorr(12.5, .heavy)).foregroundStyle(ch.mut).frame(maxWidth: .infinity, alignment: .leading).padding(.leading, 6).padding(.top, 8)
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
                TextInputSheet(title: L("cm_new_group"), action: L("cm_create_group"), icon: "person.2.badge.plus", onDismiss: close) { name in
                    close()
                    act {
                        let fresh = try await ChatApi.addCommunityGroups(c.id, ["name": name]).data
                        await host.refreshList()
                        return fresh
                    }
                }
            case "rename":
                TextInputSheet(title: L("cm_rename"), initial: c.name, action: L("ch_save"), limit: 100, onDismiss: close) { name in
                    close()
                    act { try await ChatApi.updateCommunity(c.id, ["name": name]).data }
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
            case "delete":
                ChoiceSheet(title: L("cm_delete"), subtitle: L("cm_delete_sub"), options: [(L("cm_delete"), {
                    act {
                        _ = try await ChatApi.deleteCommunity(c.id)
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

private struct GroupLine: View {
    @ObservedObject private var ch = Ch.shared
    let g: CommunityGroupDto
    let admin: Bool
    let onLong: () -> Void
    let onClick: () -> Void

    var body: some View {
        HStack(spacing: 12) {
            ChAvatar(url: g.avatar, name: g.name, key: g.id, size: 46, isGroup: true)
            VStack(alignment: .leading, spacing: 1) {
                Text(verbatim: g.name).font(.dorr(15, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                Text(L("ch_members", g.membersCount ?? 0)).font(.dorr(12.5)).foregroundStyle(ch.mut)
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
        .padding(12)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .contentShape(Rectangle())
        .onTapGesture(perform: onClick)
        .onLongPressGesture { if admin { onLong() } }
    }
}

/// Bring in more groups I run.
private struct AddGroupsSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    let onAdd: ([String]) -> Void
    @State private var picked: [String] = []

    var body: some View {
        ChSheetCard {
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    Text(L("cm_add_groups")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                    GroupPicker(groups: myFreeGroups(host), picked: $picked)
                    ChPrimaryButton(text: L("cm_add_groups"), icon: "link.badge.plus", enabled: !picked.isEmpty) {
                        onAdd(picked)
                        onDismiss()
                    }
                }
            }
            .frame(maxHeight: 600)
        }
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

    var body: some View {
        if let m = menu {
            let makeAdmin = m.role != "admin"
            ChoiceSheet(title: m.profile?.name ?? "", subtitle: L("cm_remove_member_sub"), options: [
                (L("cm_remove_member"), { act { _ = try await ChatApi.removeCommunityMember(c.id, participant: m.participantId) } }),
                (L(makeAdmin ? "cm_make_admin" : "cm_unmake_admin"), { act { _ = try await ChatApi.communityRole(c.id, participant: m.participantId, role: makeAdmin ? "admin" : "member") } }),
            ], danger: true) { menu = nil }
        } else {
        ChSheetCard {
            Group {
                Text(L("cm_members")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                Text(L("cm_admins_of", c.adminsCount ?? 0, c.maxAdmins ?? 20)).font(.dorr(12.5)).foregroundStyle(ch.mut).padding(.bottom, 10)
                if let list = members {
                    ScrollView {
                        LazyVStack(spacing: 0) {
                            ForEach(Array(list.enumerated()), id: \.element.id) { i, m in
                                let canManage = c.isAdmin == true && m.role != "owner" && m.profile?.isMe != true
                                Button { menu = m } label: {
                                    HStack(spacing: 10) {
                                        ChAvatar(url: m.profile?.avatar, name: m.profile?.name, key: m.profile?.key, size: 40)
                                        Text(verbatim: m.profile?.isMe == true ? L("ch_you") : m.profile?.name ?? "").font(.dorr(14.5, .semibold)).foregroundStyle(ch.ink)
                                            .lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                                        if m.role == "owner" { roleTag(L("cm_creator")) } else if m.role == "admin" { roleTag(L("cm_admin")) }
                                    }
                                    .padding(.vertical, 8).padding(.horizontal, 4).contentShape(Rectangle())
                                }
                                .buttonStyle(.plain)
                                .disabled(!canManage)
                                .chStagger(i)
                            }
                        }
                    }
                    .frame(maxHeight: 480)
                } else {
                    ProgressView().tint(ch.red).frame(maxWidth: .infinity).frame(height: 120)
                }
            }
        }
        .task { await load() }
        }
    }

    private func load() async { members = (try? await ChatApi.communityMembers(c.id).data) ?? [] }

    private func act(_ block: @escaping () async throws -> Void) {
        Task {
            do {
                try await block()
                await load()
                onChanged()
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
        }
    }

    private func roleTag(_ text: String) -> some View {
        Text(verbatim: text).font(.dorr(11.5, .heavy)).foregroundStyle(ch.red).padding(.horizontal, 8).padding(.vertical, 3).background(ch.red.opacity(0.08), in: Capsule())
    }
}
