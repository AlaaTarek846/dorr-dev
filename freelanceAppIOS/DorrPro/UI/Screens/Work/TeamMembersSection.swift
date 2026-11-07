import SwiftUI

// freelanceApp `ui/screens/TeamMembersSection.kt`.

/// The abilities an Owner may switch for a PM or a member, in the order the sheet lists them (the server names them in `switches`).
private let teamSwitchOrder = ["view_price", "client_chat", "internal_files", "invite_members"]

/// The «Members» part of a team's page: everybody in the team with their role (Owners first, as the server sends them). An Owner taps a row to
/// manage that person; somebody who may invite gets a shortcut to the invitations.
struct TeamMembersSection: View {
    @Environment(\.wa) private var wa
    let people: TeamMembersDto
    let myUuid: String?
    let isOwner: Bool
    let canInvite: Bool
    let onManage: (TeamMemberDto) -> Void
    let onInvite: () -> Void

    var body: some View {
        let members = people.members ?? []
        VStack(spacing: 14) {
            VStack(spacing: 0) {
                ForEach(Array(members.enumerated()), id: \.offset) { index, m in
                    if index > 0 {
                        Rectangle().fill(wa.settingsMut.opacity(0.12)).frame(height: 1).padding(.leading, 72)
                    }
                    MemberRow(m: m, isMe: m.freelancer?.uuid != nil && m.freelancer?.uuid == myUuid, canManage: isOwner, onClick: { onManage(m) })
                }
            }
            .padding(.vertical, 6)
            .frame(maxWidth: .infinity)
            .appCard(Corners.card)
            if canInvite { SettingsPrimaryButton(text: L("team_invite_title"), action: onInvite) }
        }
    }
}

private struct MemberRow: View {
    @Environment(\.wa) private var wa
    let m: TeamMemberDto
    let isMe: Bool
    let canManage: Bool
    let onClick: () -> Void

    var body: some View {
        if canManage {
            Button(action: onClick) { row }.buttonStyle(.plain)
        } else {
            row
        }
    }

    private var row: some View {
        let role = m.role ?? "member"
        return HStack(spacing: 12) {
            InitialsBadge(name: m.freelancer?.name, size: 44, tint: roleTint(role))
            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: 6) {
                    Text(verbatim: m.freelancer?.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    if isMe { Text(L("team_you")).font(.dorr(11, .bold)).foregroundStyle(wa.red) }
                }
                if let since = teamDate(m.joinedAt) {
                    Text(verbatim: L("team_member_since", since)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            RolePill(role: role)
            if canManage {
                Image(systemName: "chevron.forward").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut)
                    .frame(width: 18, height: 18)
                    .accessibilityLabel(L("team_manage"))
            }
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 10)
        .contentShape(Rectangle())
    }
}

/// An Owner's sheet for one person: the role (promoting somebody to Owner is how ownership is passed on), the four abilities an Owner switches
/// for a PM or a member, and taking the person out of the team. Each change goes to the server at once; `member` is the latest copy of the
/// person. Shown with `.cmSheet` (Android `LocaleAwareBottomSheet`), which closes itself — so there is no `onDismiss` here.
struct ManageMemberSheet: View {
    @Environment(\.wa) private var wa
    let member: TeamMemberDto
    let switches: [String]
    let isMe: Bool
    let busy: Bool
    let onRole: (String) -> Void
    let onPermission: (String, Bool) -> Void
    let onRemove: () -> Void

    var body: some View {
        let keys = teamSwitchOrder.filter { switches.contains($0) }
        let role = member.role ?? "member"
        let red = Color(hex: 0xDC2626)
        // FS-212: an Owner who signed agreements of the team that have not ended, or has offers in its name still open, stays an Owner until they
        // end or are taken back - the server refuses the rest.
        let held = role == "owner" && (member.signedAgreements ?? 0) + (member.openOffers ?? 0) > 0
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                HStack(spacing: 12) {
                    InitialsBadge(name: member.freelancer?.name, size: 52, tint: roleTint(role))
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: member.freelancer?.name ?? "").font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        Text(verbatim: roleLabel(role)).font(.dorr(13, .bold)).foregroundStyle(roleTint(role))
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }

                Text(L("team_role_label")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                FilterChips(
                    options: ["owner", "pm", "member"].map { FilterOption(key: $0, label: roleLabel($0)) },
                    selected: role,
                    onSelect: { if !busy && $0 != role && !(held && $0 != "owner") { onRole($0) } }
                )
                Text(verbatim: roleHint(role)).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                if held {
                    Text(verbatim: L("team_signer_hold", (member.freelancer?.name ?? "").components(separatedBy: " ").first ?? "")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                }

                if role == "owner" {
                    Text(L("team_perm_owner_all")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                } else if !keys.isEmpty {
                    Text(L("team_perm_title")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    VStack(spacing: 0) {
                        ForEach(keys, id: \.self) { key in
                            let on = member.can(key)
                            TeamSwitchRow(title: permTitle(key), subtitle: permSub(key), on: on, enabled: !busy) { onPermission(key, !on) }
                        }
                    }
                    .padding(.horizontal, 14)
                    .padding(.vertical, 6)
                    .overlay(RoundedRectangle(cornerRadius: Corners.card, style: .continuous).stroke(wa.settingsMut.opacity(0.16), lineWidth: 1))
                }

                if !isMe {
                    Button(action: onRemove) {
                        Text(L("team_remove")).font(.dorr(15, .heavy)).foregroundStyle(red.opacity(held ? 0.4 : 1))
                            .frame(maxWidth: .infinity, minHeight: 50)
                            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(red.opacity(held ? 0.2 : 0.45), lineWidth: 1.5))
                            .contentShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    .allowsHitTesting(!busy && !held)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 18)
            .padding(.top, 24)
            .padding(.bottom, 24)
        }
        .background(wa.settingsCard.ignoresSafeArea())
    }

    private func permTitle(_ key: String) -> String {
        switch key {
        case "view_price": return L("team_perm_view_price")
        case "client_chat": return L("team_perm_client_chat")
        case "internal_files": return L("team_perm_internal_files")
        default: return L("team_perm_invite_members")
        }
    }

    private func permSub(_ key: String) -> String {
        switch key {
        case "view_price": return L("team_perm_view_price_sub")
        case "client_chat": return L("team_perm_client_chat_sub")
        case "internal_files": return L("team_perm_internal_files_sub")
        default: return L("team_perm_invite_members_sub")
        }
    }
}
