import SwiftUI

// freelanceApp `ui/screens/TeamInvitesSection.kt`.

/// The «Invitations» part of a team's page, for the people who may invite: a card to invite by phone number or to make a code (no phone
/// needed), and the invitations still open with their code to copy or share. Only an Owner invites a PM; anybody else invites members.
struct TeamInvitesSection: View {
    @Environment(\.wa) private var wa
    let teamName: String
    let data: TeamInvitationsDto?
    let isOwner: Bool
    let busy: Bool
    /// `phone` nil = an invitation by code.
    let onInvite: (String?, String) -> Void
    let onCancel: (TeamInvitationDto) -> Void
    let onCopied: () -> Void

    @State private var role = "member"
    @State private var phone = ""

    var body: some View {
        let chosen = isOwner ? role : "member"
        let clean = phone.trimmingCharacters(in: .whitespacesAndNewlines)
        let open = data?.invitations ?? []
        VStack(spacing: 14) {
            VStack(alignment: .leading, spacing: 12) {
                Text(L("team_invite_title")).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
                if let days = data?.days {
                    Text(verbatim: L("team_invite_sub", days)).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                }
                if isOwner {
                    Text(L("team_invite_role")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    FilterChips(
                        options: ["pm", "member"].map { FilterOption(key: $0, label: roleLabel($0)) },
                        selected: chosen,
                        onSelect: { role = $0 }
                    )
                    Text(verbatim: roleHint(chosen)).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                }
                DorrTextField(
                    value: agreementLimit($phone, 30), label: L("team_invite_phone"), placeholder: L("team_invite_phone_hint"),
                    icon: "phone.fill", keyboard: .phonePad, enabled: !busy
                )
                SettingsPrimaryButton(text: L("team_invite_send"), enabled: clean.count >= 6, loading: busy) {
                    onInvite(clean, chosen)
                    phone = ""
                }
                Button { onInvite(nil, chosen) } label: {
                    HStack(spacing: 8) {
                        Image(systemName: "key.fill").font(.system(size: 15, weight: .semibold)).frame(width: 18, height: 18)
                        Text(L("team_invite_code_btn")).font(.dorr(14.5, .heavy))
                    }
                    .foregroundStyle(wa.red)
                    .frame(maxWidth: .infinity, minHeight: 50)
                    .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.red.opacity(0.45), lineWidth: 1.5))
                    .contentShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
                }
                .buttonStyle(.plain)
                .allowsHitTesting(!busy)
            }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .appCard(Corners.card)

            SectionHeader(title: L("team_invite_open"))
            if open.isEmpty {
                Text(L("team_invite_none")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                    .frame(maxWidth: .infinity, alignment: .leading).padding(.vertical, 8)
            } else {
                ForEach(open) { i in
                    OpenInvitation(teamName: teamName, i: i, busy: busy, onCancel: { onCancel(i) }, onCopied: onCopied)
                }
            }
        }
    }
}

/// An invitation still open: to a person (their name) or by code (the code, to copy or share), its role and when it ends, and a way to
/// withdraw it.
private struct OpenInvitation: View {
    @Environment(\.wa) private var wa
    let teamName: String
    let i: TeamInvitationDto
    let busy: Bool
    let onCancel: () -> Void
    let onCopied: () -> Void

    var body: some View {
        let code = i.token
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 12) {
                Image(systemName: i.byCode ? "key.fill" : "phone.fill").font(.system(size: 17, weight: .semibold)).foregroundStyle(Tints.blue)
                    .frame(width: 40, height: 40)
                    .background(Tints.blue.opacity(0.12), in: RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
                VStack(alignment: .leading, spacing: 4) {
                    Text(verbatim: i.byCode ? L("team_invite_by_code") : (i.invited?.name ?? ""))
                        .font(.dorr(14.5, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    HStack(spacing: 6) {
                        RolePill(role: i.role ?? "member")
                        if i.status == "expired" { StatusPill(text: L("team_inv_s_expired"), tint: Tints.rose) }
                    }
                    if let until = teamDate(i.expiresAt) {
                        Text(verbatim: L("team_invite_expires", until)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Button(action: onCancel) {
                    Text(L("team_invite_cancel")).font(.dorr(13, .bold)).foregroundStyle(Color(hex: 0xDC2626))
                        .padding(.horizontal, 10).padding(.vertical, 8)
                        .contentShape(Capsule())
                }
                .buttonStyle(.plain)
                .allowsHitTesting(!busy)
            }
            if let code {
                Text(verbatim: code)
                    .font(.system(size: 12.5, weight: .semibold, design: .monospaced)).foregroundStyle(wa.settingsInk)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.horizontal, 12).padding(.vertical, 10)
                    .background(wa.settingsMut.opacity(0.10), in: RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
                HStack(spacing: 8) {
                    CodeAction(icon: "doc.on.doc", label: L("team_invite_copy")) {
                        teamCopyText(code)
                        onCopied()
                    }
                    CodeAction(icon: "square.and.arrow.up", label: L("team_invite_share")) {
                        teamShareText(L("team_invite_share_text", teamName, code))
                    }
                }
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .appCard(Corners.card)
    }
}

private struct CodeAction: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 14, weight: .semibold)).frame(width: 17, height: 17)
                Text(verbatim: label).font(.dorr(13.5, .heavy))
            }
            .foregroundStyle(wa.red)
            .frame(maxWidth: .infinity, minHeight: 42)
            .background(wa.red.opacity(0.10), in: RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
            .contentShape(RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}
