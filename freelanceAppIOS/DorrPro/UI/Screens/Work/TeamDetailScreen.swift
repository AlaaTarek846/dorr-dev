import PhotosUI
import SwiftUI

// freelanceApp `ui/screens/TeamDetailScreen.kt`.

/// One team's page (FS-209) for somebody in it: the team's head (logo, name, badge, numbers), then three parts — its profile (what clients see
/// and the switches for it), its people (an Owner manages them here) and its invitations (for the people who may invite). A change goes to the
/// server at once and the page is read again. Nothing runs on a timer: it reads when shown, when a notice arrives and when the connection returns.
/// FS-209b: two more parts — the team's projects (a card opens the project, [onOpenProject]) and the workload of its people.
struct TeamScreen: View {
    let uuid: String
    let onBack: () -> Void
    var onOpenProject: (String) -> Void = { _ in }
    /// FS-209c: a thread of the team's inside chat, the team's portfolio, and the team's verification.
    var onOpenThread: (String) -> Void = { _ in }
    var onOpenPortfolio: () -> Void = {}
    var onOpenVerification: () -> Void = {}
    /// FS-211: the team's plan (an Owner's page).
    var onOpenPlan: () -> Void = {}

    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var session = Session.shared

    @State private var team: TeamDto?
    @State private var people: TeamMembersDto?
    @State private var invites: TeamInvitationsDto?
    @State private var chat: TeamThreadsDto?
    @State private var chatFailure: String?
    @State private var failure: String?
    @State private var notice: String?
    @State private var busy = false
    @State private var uploading = false
    @State private var logoVersion: Int64 = 0
    @State private var segment = "profile"
    @State private var editing = false
    @State private var managing: String?
    // FS-213: a person writing his own job title.
    @State private var titling: String?
    @State private var askOwner: String?
    @State private var askRemove: String?
    @State private var askLeave = false
    @State private var galleryItem: PhotosPickerItem?

    private var isOwner: Bool { team?.me?.role == "owner" }
    private var canInvite: Bool { teamAbility(people?.me?.abilities, "invite_members") }
    private var shownSegment: String { segment == "invites" && !canInvite ? "profile" : segment }
    private var managed: TeamMemberDto? {
        guard let id = managing else { return nil }
        return people?.members?.first { $0.freelancer?.uuid == id }
    }
    private var titled: TeamMemberDto? {
        guard let id = titling else { return nil }
        return people?.members?.first { $0.freelancer?.uuid == id }
    }

    /// The text of what went wrong with a call (nil = it went through).
    private func problem<T>(_ result: ApiResult<T>) -> String? {
        if case .ok = result { return nil }
        return result.errorText(L("login_error_offline")) ?? L("login_error_generic")
    }

    private func load() async {
        let profile = await TeamsApi.profile(uuid)
        if let t = profile.value?.team {
            team = t
            failure = nil
        } else if profile.error?.httpStatus == 404 && team != nil {
            // I am not in this team any more (removed, or it was deleted): there is nothing to show.
            onBack()
            return
        } else if team == nil {
            failure = problem(profile)
        }
        let members = await TeamsApi.members(uuid)
        if let m = members.value {
            people = m
            if teamAbility(m.me?.abilities, "invite_members") {
                if let open = await TeamsApi.invitations(uuid).value { invites = open }
            } else {
                invites = nil
            }
        }
        let threads = await TeamsApi.chatThreads(uuid)
        if let t = threads.value {
            chat = t
            chatFailure = nil
        } else if chat == nil {
            chatFailure = problem(threads)
        }
    }

    /// Runs a change: `block` returns the text of what went wrong (nil = it went through); then the page is read again.
    private func change(done: String? = nil, _ block: @escaping () async -> String?) {
        notice = nil
        busy = true
        Task {
            let failed = await block()
            if let failed { notice = failed } else if let done { notice = done }
            await load()
            busy = false
        }
    }

    private func saveProfile(_ request: TeamProfileRequest, done: String?) {
        change(done: done) {
            let result = await TeamsApi.update(uuid, request)
            return problem(result)
        }
    }

    private func setRole(_ member: String, _ role: String) {
        change(done: L("common_saved")) {
            let result = await TeamsApi.setRole(uuid, member, TeamRoleRequest(role: role))
            return problem(result)
        }
    }

    /// FS-213: what the person does in the team (an empty title clears it).
    private func setTitle(_ member: String, _ title: String) {
        change(done: L("common_saved")) {
            let result = await TeamsApi.setTitle(uuid, member, TeamTitleRequest(jobTitle: title.isEmpty ? nil : title))
            return problem(result)
        }
    }

    private func uploadLogo(_ data: Data) {
        uploading = true
        notice = nil
        Task {
            let part = await Task.detached { logoPart(data) }.value
            if let part {
                let result = await TeamsApi.logo(uuid, part)
                if let logo = result.value {
                    team?.logoUrl = logo.logoUrl
                    logoVersion = Int64(Date().timeIntervalSince1970 * 1000)
                    notice = L("common_saved")
                } else {
                    notice = problem(result)
                }
            } else {
                notice = L("login_error_generic")
            }
            uploading = false
        }
    }

    private func leave() {
        notice = nil
        busy = true
        Task {
            let failed = await TeamsApi.leave(uuid, offline: L("login_error_offline"), generic: L("login_error_generic"))
            busy = false
            askLeave = false
            if let failed {
                notice = failed
            } else {
                Task { await HomeHub.shared.refresh() }
                AppToast.show(L("team_left_toast"))
                onBack()
            }
        }
    }

    var body: some View {
        Page(title: L("team_title"), onBack: onBack) { pageContent }
            .messageToast(notice)
            .task(id: network.reconnectTick) { await load() }
            .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
            .onChange(of: galleryItem) { item in
                guard let item else { return }
                galleryItem = nil
                Task {
                    guard let data = try? await item.loadTransferable(type: Data.self) else {
                        notice = L("login_error_generic")
                        return
                    }
                    uploadLogo(data)
                }
            }
            .cmSheet(isPresented: $editing, locked: busy) { editSheet }
            .cmSheet(isPresented: managingPresented, locked: busy) { manageSheet }
            .cmSheet(isPresented: titlingPresented, locked: busy) { titleSheet }
            .dorrDialog(isPresented: $askLeave) {
                ConfirmDialog(
                    icon: "rectangle.portrait.and.arrow.right", title: L("team_leave_title"), message: L("team_leave_body"),
                    loading: busy, confirmLabel: L("team_leave"), onConfirm: leave, onDismiss: { if !busy { askLeave = false } }
                )
            }
    }

    private var managingPresented: Binding<Bool> {
        Binding(get: { isOwner && managed != nil }, set: { if !$0 { managing = nil } })
    }

    private var titlingPresented: Binding<Bool> {
        Binding(get: { titled != nil }, set: { if !$0 { titling = nil } })
    }

    private var segments: [Segment] {
        var items = [
            Segment(key: "profile", label: L("team_tab_profile"), icon: "info.circle.fill"),
            Segment(key: "members", label: L("team_tab_members"), icon: "person.3.fill", count: people?.members?.count ?? 0),
            Segment(key: "projects", label: L("tw_tab_projects"), icon: "hammer.fill"),
            Segment(key: "workload", label: L("tw_tab_workload"), icon: "speedometer"),
            Segment(
                key: "chat", label: L("tc_tab_chat"), icon: "bubble.left.and.bubble.right.fill",
                count: (chat?.threads ?? []).reduce(0) { $0 + ($1.unread ?? 0) }
            ),
        ]
        if canInvite {
            items.append(Segment(key: "invites", label: L("team_tab_invites"), icon: "person.badge.plus", count: invites?.invitations?.count ?? 0))
        }
        return items
    }

    @ViewBuilder
    private var pageContent: some View {
        if let t = team {
            TeamHeader(t: t, canEdit: isOwner, uploading: uploading, version: logoVersion, picked: $galleryItem)
            SegmentTabs(segments: segments, selected: shownSegment, onSelect: { segment = $0 })
            switch shownSegment {
            case "members": membersPart
            case "projects": TeamProjectsList(teamUuid: uuid, role: t.me?.role, onOpenProject: onOpenProject)
            case "workload": TeamWorkloadSection(teamUuid: uuid, role: t.me?.role)
            case "chat":
                TeamChatSection(
                    teamUuid: uuid, data: chat, failure: chatFailure, onOpenThread: onOpenThread,
                    onReload: { await load() }
                )
            case "invites": invitesPart(t)
            default: profilePart(t)
            }
        } else if let failure {
            VStack(spacing: 14) {
                EmptyState(icon: "person.3.fill", title: L("common_loading_failed"), body: failure)
                SettingsPrimaryButton(text: L("common_retry")) { Task { await load() } }
            }
        } else {
            SkeletonList(count: 2)
        }
    }

    @ViewBuilder
    private var membersPart: some View {
        if let p = people {
            TeamMembersSection(
                people: p, myUuid: session.me?.uuid, isOwner: isOwner, canInvite: canInvite,
                onManage: { managing = $0.freelancer?.uuid }, onInvite: { segment = "invites" },
                onEditTitle: { titling = $0.freelancer?.uuid }
            )
        }
    }

    private func invitesPart(_ t: TeamDto) -> some View {
        TeamInvitesSection(
            teamName: t.name ?? "", data: invites, isOwner: isOwner, busy: busy,
            onInvite: { phone, role in
                change(done: L(phone == nil ? "team_invite_code_ready" : "team_invite_sent_toast")) {
                    let result = await TeamsApi.invite(uuid, TeamInviteRequest(phone: phone, role: role))
                    return problem(result)
                }
            },
            onCancel: { i in
                change(done: L("team_invite_cancelled_toast")) {
                    await TeamsApi.cancelInvitation(uuid, i.uuid ?? "", offline: L("login_error_offline"), generic: L("login_error_generic"))
                }
            },
            onCopied: { AppToast.show(L("team_invite_copied")) }
        )
    }

    private func profilePart(_ t: TeamDto) -> some View {
        let owners = people?.members?.filter { $0.role == "owner" }.count ?? 0
        return TeamProfileSection(
            t: t, lastOwner: isOwner && owners <= 1, signs: (people?.me?.signedAgreements ?? 0) + (people?.me?.openOffers ?? 0), busy: busy,
            isOwner: isOwner, onOpenPortfolio: onOpenPortfolio, onOpenVerification: onOpenVerification, onOpenPlan: onOpenPlan,
            onEdit: { editing = true },
            onToggleVisibility: {
                var next = t
                next.visibility = t.visibility == "public" ? "private" : "public"
                team = next
                saveProfile(TeamProfileRequest(visibility: next.visibility), done: nil)
            },
            onToggleAccepting: {
                var next = t
                next.acceptingProjects = t.acceptingProjects != true
                team = next
                saveProfile(TeamProfileRequest(acceptingProjects: next.acceptingProjects), done: nil)
            },
            onToggleShowMe: {
                let show = t.me?.showInProfile != true
                var next = t
                var me = t.me ?? TeamMeDto()
                me.showInProfile = show
                next.me = me
                team = next
                change {
                    let result = await TeamsApi.consent(uuid, TeamConsentRequest(showInProfile: show))
                    return problem(result)
                }
            },
            onLeave: { askLeave = true }
        )
    }

    @ViewBuilder
    private var editSheet: some View {
        if let t = team {
            EditTeamSheet(
                team: t, busy: busy, uploading: uploading, picked: $galleryItem,
                onSave: { name, bio in
                    editing = false
                    saveProfile(TeamProfileRequest(name: name, bio: bio), done: L("common_saved"))
                },
                onRemoveLogo: {
                    change(done: L("common_saved")) {
                        let result = await TeamsApi.removeLogo(uuid)
                        return problem(result)
                    }
                    team?.logoUrl = nil
                }
            )
        }
    }

    /// FS-213: a person (not an Owner) writing his own job title; saving closes the sheet.
    @ViewBuilder
    private var titleSheet: some View {
        if let m = titled {
            JobTitleSheet(member: m, busy: busy, onSave: { title in
                setTitle(m.freelancer?.uuid ?? "", title)
                titling = nil
            })
        }
    }

    /// The Owner's sheet for one person. The two questions it can ask (make an Owner, take out) are presented from the sheet itself: a cover can't
    /// open over the page while the sheet is up.
    @ViewBuilder
    private var manageSheet: some View {
        if let m = managed, let p = people {
            let target = m.freelancer?.uuid ?? ""
            ManageMemberSheet(
                member: m, switches: p.switches ?? [], isMe: target != "" && target == session.me?.uuid, busy: busy,
                onRole: { role in
                    if role == "owner" { askOwner = target } else { setRole(target, role) }
                },
                onPermission: { key, on in
                    change {
                        let result = await TeamsApi.setPermissions(uuid, target, TeamPermissionsRequest(permissions: [key: on]))
                        return problem(result)
                    }
                },
                onRemove: { askRemove = target },
                onTitle: { title in setTitle(target, title) }
            )
            .dorrDialog(isPresented: cmPresence($askOwner)) {
                ConfirmDialog(
                    icon: "checkmark.shield.fill", title: L("team_role_owner_title", m.freelancer?.name ?? ""), message: L("team_role_owner_body"),
                    loading: busy, confirmLabel: L("team_role_owner_confirm"),
                    onConfirm: {
                        askOwner = nil
                        setRole(target, "owner")
                    },
                    onDismiss: { askOwner = nil }
                )
            }
            .dorrDialog(isPresented: cmPresence($askRemove)) {
                ConfirmDialog(
                    icon: "person.crop.circle.badge.minus", title: L("team_remove_title", m.freelancer?.name ?? ""), message: L("team_remove_body"),
                    loading: busy, confirmLabel: L("team_remove"),
                    onConfirm: {
                        askRemove = nil
                        managing = nil
                        change(done: L("team_removed_toast")) {
                            await TeamsApi.remove(uuid, target, offline: L("login_error_offline"), generic: L("login_error_generic"))
                        }
                    },
                    onDismiss: { askRemove = nil }
                )
            }
        }
    }
}

/// The head of a team's page: its logo (an Owner changes it from the camera), name and badge, who I am in it, and four numbers.
private struct TeamHeader: View {
    @Environment(\.wa) private var wa
    let t: TeamDto
    let canEdit: Bool
    let uploading: Bool
    let version: Int64
    @Binding var picked: PhotosPickerItem?

    var body: some View {
        let accent = wa.red
        let deep = accent.scaled(0.62)
        VStack(alignment: .leading, spacing: 16) {
            HStack(spacing: 14) {
                ZStack(alignment: .bottomTrailing) {
                    TeamLogo(url: t.logoUrl, size: 72, onBrand: true, version: version)
                    if canEdit {
                        PhotosPicker(selection: $picked, matching: .images) {
                            ZStack {
                                if uploading {
                                    ProgressView().tint(accent).scaleEffect(0.6)
                                } else {
                                    Image(systemName: "camera.fill").font(.system(size: 13, weight: .semibold)).foregroundStyle(accent)
                                }
                            }
                            .frame(width: 28, height: 28)
                            .background(Color.white, in: Circle())
                        }
                        .buttonStyle(.plain)
                        .disabled(uploading)
                        .accessibilityLabel(L("team_logo_change"))
                    }
                }
                VStack(alignment: .leading, spacing: 8) {
                    HStack(spacing: 6) {
                        Text(verbatim: t.name ?? "").font(.dorr(20, .black)).foregroundStyle(.white).lineLimit(2)
                        if t.verified == true {
                            Image(systemName: "checkmark.seal.fill").font(.system(size: 18)).foregroundStyle(.white)
                                .accessibilityLabel(L("team_verified"))
                        }
                    }
                    FlowLayout(spacing: 6, lineSpacing: 6) {
                        BrandPill(text: roleLabel(t.me?.role ?? "member"))
                        BrandPill(text: L(t.visibility == "public" ? "team_public" : "team_private"))
                        if t.status == "suspended" {
                            BrandPill(text: L("team_status_suspended"))
                        } else if t.status == "archived" {
                            BrandPill(text: L("team_status_archived"))
                        }
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            HStack(spacing: 8) {
                HeaderNumber(value: String(t.membersCount ?? 0), label: L("team_stat_members"))
                HeaderNumber(value: String(t.runningProjects ?? 0), label: L("team_stat_running"))
                HeaderNumber(value: String(t.stats?.completedProjects ?? 0), label: L("team_stat_done"))
                HeaderNumber(value: t.stats?.rating.map { String(format: "%.1f", locale: AppLocale.locale, $0) } ?? "—", label: L("team_stat_rating"))
            }
        }
        .padding(18)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background {
            ZStack {
                LinearGradient(colors: [accent, deep], startPoint: .topLeading, endPoint: .bottomTrailing)
                Circle().fill(.white.opacity(0.07)).frame(width: 180, height: 180)
                    .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topTrailing)
                Circle().fill(.white.opacity(0.06)).frame(width: 110, height: 110)
                    .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottomLeading)
            }
        }
        .clipShape(RoundedRectangle(cornerRadius: Corners.hero, style: .continuous))
    }
}

private struct BrandPill: View {
    let text: String

    var body: some View {
        Text(verbatim: text).font(.dorr(11, .bold)).foregroundStyle(.white).lineLimit(1)
            .padding(.horizontal, 10)
            .padding(.vertical, 4)
            .background(.white.opacity(0.20), in: Capsule())
    }
}

private struct HeaderNumber: View {
    let value: String
    let label: String

    var body: some View {
        VStack(spacing: 2) {
            Text(verbatim: value).font(.dorr(18, .black)).foregroundStyle(.white).lineLimit(1)
            Text(verbatim: label).font(.dorr(10.5, .semibold)).foregroundStyle(.white.opacity(0.85)).lineLimit(1)
        }
        .padding(.vertical, 10)
        .padding(.horizontal, 4)
        .frame(maxWidth: .infinity)
        .background(.white.opacity(0.14), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// The «Profile» part: the team's description, the switches (an Owner changes the two about the team; every person decides alone whether
/// they are shown), what the team covers (the union of its people's work), the team's works and its verification (FS-209c: two cards that lead
/// to their own pages), and leaving.
private struct TeamProfileSection: View {
    @Environment(\.wa) private var wa
    let t: TeamDto
    let lastOwner: Bool
    /// FS-212: agreements of the team I signed that have not ended plus my open offers in its name; while there are any I stay (they are mine).
    let signs: Int
    let busy: Bool
    let isOwner: Bool
    let onOpenPortfolio: () -> Void
    let onOpenVerification: () -> Void
    let onOpenPlan: () -> Void
    let onEdit: () -> Void
    let onToggleVisibility: () -> Void
    let onToggleAccepting: () -> Void
    let onToggleShowMe: () -> Void
    let onLeave: () -> Void

    /// The text of the verification state and its colour (the state before the first request has its own words).
    private func verificationStyle(_ status: String) -> (String, Color) {
        switch status {
        case "verified": return ("team_ver_verified", Tints.blue)
        case "rejected": return ("team_ver_rejected", Tints.rose)
        case "submitted": return ("team_ver_submitted", Tints.amber)
        default: return ("tv_status_none", Tints.amber)
        }
    }

    var body: some View {
        let canEdit = t.me?.canEdit == true
        let hasBio = !(t.bio ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        let red = Color(hex: 0xDC2626)
        let specialties = (t.specialties ?? []).compactMap(\.name)
        let skills = (t.skills ?? []).compactMap(\.name)
        let languages = t.languages ?? []
        VStack(spacing: 14) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(spacing: 0) {
                    Text(L("team_about")).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                    if canEdit {
                        Button(action: onEdit) {
                            HStack(spacing: 4) {
                                Image(systemName: "pencil").font(.system(size: 13, weight: .semibold)).frame(width: 15, height: 15)
                                Text(L("team_edit")).font(.dorr(13, .bold))
                            }
                            .foregroundStyle(wa.red)
                            .padding(.horizontal, 10)
                            .padding(.vertical, 6)
                            .contentShape(Capsule())
                        }
                        .buttonStyle(.plain)
                    }
                }
                Text(verbatim: hasBio ? (t.bio ?? "") : L(canEdit ? "team_no_bio" : "team_no_bio_member"))
                    .font(.dorr(13.5)).foregroundStyle(hasBio ? wa.settingsInk : wa.settingsMut).lineSpacing(4)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .appCard(Corners.card)

            VStack(alignment: .leading, spacing: 4) {
                Text(L("team_settings")).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).padding(.bottom, 4)
                TeamSwitchRow(
                    title: L("team_set_public"),
                    subtitle: L(t.visibility == "public" ? "team_set_public_on" : "team_set_public_off"),
                    on: t.visibility == "public", enabled: canEdit && !busy, onToggle: onToggleVisibility
                )
                TeamSwitchRow(
                    title: L("team_set_accepting"), subtitle: L("team_set_accepting_sub"),
                    on: t.acceptingProjects == true, enabled: canEdit && !busy, onToggle: onToggleAccepting
                )
                Rectangle().fill(wa.settingsMut.opacity(0.12)).frame(height: 1).padding(.vertical, 4)
                TeamSwitchRow(
                    title: L("team_set_show_me"), subtitle: L("team_set_show_me_sub"),
                    on: t.me?.showInProfile == true, enabled: !busy, onToggle: onToggleShowMe
                )
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .appCard(Corners.card)

            if !specialties.isEmpty || !skills.isEmpty || !languages.isEmpty {
                VStack(alignment: .leading, spacing: 12) {
                    Text(L("team_expertise")).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
                    ChipGroup(title: L("team_specialties"), names: specialties)
                    ChipGroup(title: L("team_skills"), names: skills)
                    ChipGroup(title: L("team_languages"), names: languages)
                }
                .padding(16)
                .frame(maxWidth: .infinity, alignment: .leading)
                .appCard(Corners.card)
            }

            // FS-209c: the team's work (everybody in the team reads it) and its verification (the Owners send the papers).
            TeamNavRow(
                icon: "photo.on.rectangle.angled", tint: wa.red, title: L("tpf_row_title"),
                subtitle: L(isOwner ? "tpf_row_owner" : "tpf_row_member"), onTap: onOpenPortfolio
            )
            let verification = t.verificationStatus ?? "none"
            if isOwner || verification != "none" {
                let style = verificationStyle(verification)
                TeamNavRow(
                    icon: "checkmark.seal.fill", tint: style.1, title: L("team_verification_title"), subtitle: L(style.0),
                    onTap: isOwner ? onOpenVerification : nil
                )
            }

            // FS-211: the team's plan - what it opens for the whole team. The Owners subscribe (an Owner pays from their own wallet), so only they see it.
            if isOwner {
                TeamNavRow(icon: "crown.fill", tint: Tints.blue, title: L("tpl_row_title"), subtitle: L("tpl_row_sub"), onTap: onOpenPlan)
            }

            let held = lastOwner || signs > 0
            VStack(spacing: 8) {
                Button(action: onLeave) {
                    Text(L("team_leave")).font(.dorr(15, .heavy)).foregroundStyle(red.opacity(held ? 0.4 : 1))
                        .frame(maxWidth: .infinity, minHeight: 50)
                        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(red.opacity(held ? 0.2 : 0.45), lineWidth: 1.5))
                        .contentShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
                }
                .buttonStyle(.plain)
                .allowsHitTesting(!held && !busy)
                if lastOwner {
                    Text(L("team_leave_last_owner")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                        .frame(maxWidth: .infinity, alignment: .leading)
                } else if signs > 0 {
                    Text(L("team_leave_signer_active")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
            }
        }
    }
}

/// A small heading and the names under it as soft pills (nothing when there are none).
private struct ChipGroup: View {
    @Environment(\.wa) private var wa
    let title: String
    let names: [String]

    var body: some View {
        if !names.isEmpty {
            VStack(alignment: .leading, spacing: 6) {
                Text(verbatim: title).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
                FlowLayout(spacing: 6, lineSpacing: 6) {
                    ForEach(Array(names.enumerated()), id: \.offset) { _, n in
                        Text(verbatim: n).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk)
                            .padding(.horizontal, 10)
                            .padding(.vertical, 5)
                            .background(wa.red.opacity(0.10), in: Capsule())
                    }
                }
            }
        }
    }
}

/// A card that leads to another page of the team (its works, its verification): an icon, a title, one line under it, and an arrow
/// (no arrow and no tap when `onTap` is nil).
private struct TeamNavRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let tint: Color
    let title: String
    let subtitle: String
    let onTap: (() -> Void)?

    var body: some View {
        let row = HStack(spacing: 12) {
            Image(systemName: icon).font(.system(size: 18, weight: .semibold)).foregroundStyle(tint)
                .frame(width: 40, height: 40)
                .background(tint.opacity(0.14), in: RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: title).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                Text(verbatim: subtitle).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(2)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if onTap != nil {
                Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 20, height: 20)
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .appCard(Corners.card)
        if let onTap {
            Button(action: onTap) { row.contentShape(RoundedRectangle(cornerRadius: Corners.card, style: .continuous)) }.buttonStyle(.plain)
        } else {
            row
        }
    }
}

/// An Owner's sheet to edit the team: its name and description, and its logo.
private struct EditTeamSheet: View {
    @Environment(\.wa) private var wa
    let team: TeamDto
    let busy: Bool
    let uploading: Bool
    @Binding var picked: PhotosPickerItem?
    let onSave: (String, String) -> Void
    let onRemoveLogo: () -> Void
    @State private var name: String
    @State private var bio: String

    init(team: TeamDto, busy: Bool, uploading: Bool, picked: Binding<PhotosPickerItem?>,
         onSave: @escaping (String, String) -> Void, onRemoveLogo: @escaping () -> Void) {
        self.team = team
        self.busy = busy
        self.uploading = uploading
        _picked = picked
        self.onSave = onSave
        self.onRemoveLogo = onRemoveLogo
        _name = State(initialValue: team.name ?? "")
        _bio = State(initialValue: team.bio ?? "")
    }

    var body: some View {
        let hasLogo = team.logoUrl != nil
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                Text(L("team_edit_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                HStack(spacing: 14) {
                    TeamLogo(url: team.logoUrl, size: 64)
                    VStack(alignment: .leading, spacing: 8) {
                        PhotosPicker(selection: $picked, matching: .images) {
                            SheetLink(text: L(hasLogo ? "team_logo_change" : "team_logo_add"), enabled: !uploading && !busy, danger: false)
                        }
                        .buttonStyle(.plain)
                        .disabled(uploading || busy)
                        if hasLogo {
                            Button(action: onRemoveLogo) {
                                SheetLink(text: L("team_logo_remove"), enabled: !uploading && !busy, danger: true)
                            }
                            .buttonStyle(.plain)
                            .disabled(uploading || busy)
                        }
                    }
                    Spacer(minLength: 0)
                }
                DorrTextField(value: agreementLimit($name, 80), label: L("team_name_label"), enabled: !busy)
                DorrTextField(
                    value: agreementLimit($bio, 2000), label: L("team_bio_label"), placeholder: L("team_bio_hint"),
                    singleLine: false, minLines: 4, enabled: !busy
                )
                SettingsPrimaryButton(text: L("common_save"), enabled: name.agreementTrimmed.count >= 2, loading: busy) {
                    onSave(name.agreementTrimmed, bio.agreementTrimmed)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 18)
            .padding(.top, 24)
            .padding(.bottom, 24)
        }
        .scrollDismissesKeyboard(.interactively)
        .background(wa.settingsCard.ignoresSafeArea())
    }
}

private struct SheetLink: View {
    @Environment(\.wa) private var wa
    let text: String
    let enabled: Bool
    let danger: Bool

    var body: some View {
        let tint = danger ? Color(hex: 0xDC2626) : wa.red
        Text(verbatim: text).font(.dorr(13.5, .heavy)).foregroundStyle(tint.opacity(enabled ? 1 : 0.5))
            .padding(.vertical, 4)
            .contentShape(Rectangle())
    }
}
