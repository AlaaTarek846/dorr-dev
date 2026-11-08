import SwiftUI

// freelanceApp `ui/screens/TeamScreens.kt`.

/// My teams (FS-209): the invitations waiting for me, the teams I am in (each a card that opens the team's page), a way to make a team and
/// one to join with an invitation code. Read when shown, when a notice arrives and when the connection comes back — nothing on a timer.
struct TeamsScreen: View {
    let onBack: () -> Void
    let onOpenTeam: (String) -> Void

    @EnvironmentObject private var network: NetworkMonitor
    @State private var teams: [TeamSummaryDto]?
    @State private var invites: [TeamInvitationDto] = []
    @State private var failure: String?
    @State private var notice: String?
    @State private var answering: String?
    @State private var creating = false
    @State private var showCreate = false
    @State private var createError: String?
    @State private var showJoin = false

    private func load() async {
        let mine = await TeamsApi.mine()
        let waiting = await TeamsApi.myInvitations()
        if let list = mine.value {
            teams = list.teams ?? []
            failure = nil
        } else if teams == nil {
            failure = mine.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
        if let open = waiting.value { invites = open.invitations ?? [] }
    }

    private func answer(_ invite: TeamInvitationDto, accept: Bool) {
        notice = nil
        answering = invite.uuid
        Task {
            let result = accept ? await TeamsApi.accept(invite.uuid ?? "") : await TeamsApi.decline(invite.uuid ?? "")
            if let data = result.value {
                notice = L(accept ? "team_accepted_toast" : "team_declined_toast")
                await load()
                Task { await HomeHub.shared.refresh() }
                if accept, let uuid = data.invitation?.team?.uuid ?? invite.team?.uuid { onOpenTeam(uuid) }
            } else {
                notice = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
            answering = nil
        }
    }

    private func create(_ name: String) {
        notice = nil
        createError = nil
        creating = true
        Task {
            let result = await TeamsApi.create(TeamCreateRequest(name: name))
            if let data = result.value {
                showCreate = false
                notice = L("team_created_toast")
                await load()
                Task { await HomeHub.shared.refresh() }
                if let uuid = data.team?.uuid { onOpenTeam(uuid) }
            } else {
                // Inside the dialog: the page's toast sits under it, so the owned-teams limit message was never seen.
                createError = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
            creating = false
        }
    }

    var body: some View {
        Page(title: L("team_title"), onBack: onBack) {
            StoreHero(
                icon: "person.3.fill",
                title: L("team_hero_title"),
                body: L("team_hero_body"),
                numbers: [
                    HeroNumber(value: String(teams?.count ?? 0), label: L("team_stat_teams")),
                    HeroNumber(value: String(invites.count), label: L("team_stat_invites")),
                ],
                action: L("team_create"),
                actionIcon: "plus",
                onAction: { createError = nil; showCreate = true }
            )
            MenuRow(entry: MenuEntry(icon: "key.fill", label: L("team_join_code"), subtitle: L("team_join_code_sub"), action: { showJoin = true }))

            if !invites.isEmpty {
                SectionHeader(title: L("team_section_invites"))
                ForEach(invites) { i in
                    TeamInviteCard(i: i, busy: answering == i.uuid, onAnswer: { accept in answer(i, accept: accept) })
                }
            }

            if let list = teams {
                if list.isEmpty {
                    if invites.isEmpty {
                        EmptyState(icon: "person.3.fill", title: L("team_empty_title"), body: L("team_empty_body")).padding(.top, 24)
                    }
                } else {
                    SectionHeader(title: L("team_section_mine"))
                    ForEach(list) { t in TeamCard(t: t, onClick: { onOpenTeam(t.uuid ?? "") }) }
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
        .messageToast(notice)
        .task(id: network.reconnectTick) { await load() }
        .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
        .dorrDialog(isPresented: $showCreate) {
            CreateTeamDialog(busy: creating, error: createError, onCreate: create, onDismiss: { showCreate = false })
        }
        .dorrDialog(isPresented: $showJoin) {
            JoinCodeDialog(
                onDismiss: { showJoin = false },
                onJoined: { uuid in
                    showJoin = false
                    notice = L("team_accepted_toast")
                    Task { await load() }
                    Task { await HomeHub.shared.refresh() }
                    if let uuid { onOpenTeam(uuid) }
                }
            )
        }
    }
}

/// One team of mine: its logo, name and badge, my role, its size, and a note when it is not working now.
private struct TeamCard: View {
    @Environment(\.wa) private var wa
    let t: TeamSummaryDto
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(spacing: 12) {
                TeamLogo(url: t.logoUrl, size: 56)
                VStack(alignment: .leading, spacing: 6) {
                    HStack(spacing: 4) {
                        Text(verbatim: t.name ?? "").font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        if t.verified == true {
                            Image(systemName: "checkmark.seal.fill").font(.system(size: 16)).foregroundStyle(Tints.blue)
                                .accessibilityLabel(L("team_verified"))
                        }
                    }
                    HStack(spacing: 6) {
                        RolePill(role: t.role ?? "member")
                        if t.status == "suspended" {
                            StatusPill(text: L("team_status_suspended"), tint: Tints.rose)
                        } else if t.status == "archived" {
                            StatusPill(text: L("team_status_archived"), tint: Tints.amber)
                        }
                    }
                    FactLine(icon: "person.3.fill", text: L("team_members_line", t.membersCount ?? 0))
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Image(systemName: "chevron.forward").font(.system(size: 14, weight: .bold)).foregroundStyle(wa.settingsMut)
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .appCard(Corners.card)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// An invitation into a team that waits for my answer: who invited me, the role it offers and what that role is, then yes or no.
private struct TeamInviteCard: View {
    @Environment(\.wa) private var wa
    let i: TeamInvitationDto
    let busy: Bool
    let onAnswer: (Bool) -> Void

    var body: some View {
        let role = i.role ?? "member"
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 12) {
                TeamLogo(url: nil, size: 48)
                VStack(alignment: .leading, spacing: 3) {
                    Text(verbatim: i.team?.name ?? "").font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    if let by = i.invitedBy?.name, !by.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(verbatim: L("team_invited_by", by)).font(.dorr(12.5)).foregroundStyle(wa.settingsMut)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                RolePill(role: role)
            }
            Text(verbatim: roleHint(role)).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            if let until = teamDate(i.expiresAt) {
                Text(verbatim: L("team_invite_expires", until)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
            }
            TeamAnswerRow(busy: busy, onAnswer: onAnswer)
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .appCard(Corners.card)
    }
}

/// Decline (outlined) and join side by side, 1 : 1.4.
private struct TeamAnswerRow: View {
    let busy: Bool
    let onAnswer: (Bool) -> Void

    var body: some View {
        let red = Color(hex: 0xDC2626)
        GeometryReader { proxy in
            let free = max(proxy.size.width - 10, 0)
            HStack(spacing: 10) {
                Button { onAnswer(false) } label: {
                    Text(L("team_decline")).font(.dorr(15, .heavy)).foregroundStyle(red)
                        .frame(width: free / 2.4, height: 54)
                        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(red.opacity(0.45), lineWidth: 1.5))
                        .contentShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
                }
                .buttonStyle(.plain)
                .disabled(busy)
                SettingsPrimaryButton(text: L("team_accept"), loading: busy) { onAnswer(true) }
                    .frame(width: free * 1.4 / 2.4)
            }
        }
        .frame(height: 54)
        .frame(maxWidth: .infinity)
    }
}

/// Asks for the team's name; the server makes me its Owner.
private struct CreateTeamDialog: View {
    let busy: Bool
    let error: String?
    let onCreate: (String) -> Void
    let onDismiss: () -> Void
    @State private var name = ""

    var body: some View {
        let clean = name.trimmingCharacters(in: .whitespacesAndNewlines)
        AlertDialog(
            onDismissRequest: { if !busy { onDismiss() } },
            icon: "person.3.fill",
            title: { Text(L("team_create_title")) },
            text: {
                VStack(spacing: 14) {
                    Text(L("team_create_body")).frame(maxWidth: .infinity)
                    DorrTextField(value: agreementLimit($name, 80), label: L("team_name_label"), placeholder: L("team_name_hint"), enabled: !busy)
                    if let error { Text(verbatim: error).font(.dorr(12.5, .semibold)).foregroundStyle(Color(hex: 0xDC2626)).frame(maxWidth: .infinity) }
                }
            },
            confirmButton: {
                DialogButton(onClick: { onCreate(clean) }, enabled: clean.count >= 2 && !busy) { Text(L("team_create_confirm")) }
            },
            dismissButton: { DialogButton(onClick: onDismiss, enabled: !busy) { Text(L("common_cancel")) } }
        )
    }
}

/// Joining with the code a team gave me: paste it, see which team and role it is for, then join. A link is accepted too (its last part is the
/// code). Only the first person to use a code is let in.
private struct JoinCodeDialog: View {
    @Environment(\.wa) private var wa
    let onDismiss: () -> Void
    let onJoined: (String?) -> Void
    @State private var code = ""
    @State private var found: TeamInvitationDto?
    @State private var busy = false
    @State private var error: String?

    /// What was pasted, reduced to the code: a link keeps its last part, without a query.
    private var token: String {
        let trimmed = code.trimmingCharacters(in: .whitespacesAndNewlines)
        let withoutQuery = trimmed.split(separator: "?", maxSplits: 1, omittingEmptySubsequences: false).first.map(String.init) ?? trimmed
        let last = withoutQuery.split(separator: "/", omittingEmptySubsequences: false).last.map(String.init) ?? withoutQuery
        return last.trimmingCharacters(in: .whitespacesAndNewlines)
    }

    private func step() {
        busy = true
        error = nil
        let current = found
        let wanted = token
        Task {
            if let current {
                let result = await TeamsApi.acceptCode(wanted)
                if let data = result.value {
                    onJoined(data.invitation?.team?.uuid ?? current.team?.uuid)
                } else {
                    error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
                }
            } else {
                let result = await TeamsApi.preview(wanted)
                if let data = result.value {
                    found = data.invitation
                } else {
                    error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
                }
            }
            busy = false
        }
    }

    var body: some View {
        let typed = Binding<String>(get: { code }, set: { code = $0; found = nil; error = nil })
        AlertDialog(
            onDismissRequest: { if !busy { onDismiss() } },
            icon: "key.fill",
            title: { Text(L("team_join_title")) },
            text: {
                VStack(spacing: 14) {
                    Text(L("team_join_body")).frame(maxWidth: .infinity)
                    DorrTextField(value: typed, label: L("team_code_label"), enabled: !busy)
                    if let f = found {
                        let role = f.role ?? "member"
                        HStack(spacing: 10) {
                            TeamLogo(url: nil, size: 40)
                            Text(verbatim: L("team_join_found", f.team?.name ?? "", roleLabel(role)))
                                .font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                                .multilineTextAlignment(.leading)
                                .frame(maxWidth: .infinity, alignment: .leading)
                        }
                        .padding(12)
                        .overlay(RoundedRectangle(cornerRadius: Corners.small, style: .continuous).stroke(roleTint(role).opacity(0.35), lineWidth: 1))
                    }
                    if let error {
                        Text(verbatim: error).font(.dorr(12.5, .semibold)).foregroundStyle(Color(hex: 0xDC2626)).frame(maxWidth: .infinity)
                    }
                }
            },
            confirmButton: {
                DialogButton(onClick: step, enabled: token.count >= 6 && !busy) {
                    Text(L(found == nil ? "team_join_check" : "team_join_confirm"))
                }
            },
            dismissButton: { DialogButton(onClick: onDismiss, enabled: !busy) { Text(L("common_cancel")) } }
        )
    }
}
