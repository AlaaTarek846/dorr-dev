// providerApp `ui/screens/WorkspaceScreens.kt`
import SwiftUI

/// After the sign-in (and on every cold start): reads `workspace` once and opens the establishment I work in, the invitations
/// waiting for me, or the onboarding that creates my own establishment. A failed read shows a retry (nothing is asked again by
/// itself except when the connection comes back).
@MainActor
struct WorkspaceGateScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onHome: () -> Void
    let onInvitations: () -> Void
    let onOnboarding: () -> Void

    @State private var error: String?
    @State private var attempt = 0

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                DorrLogo(width: 120, onDark: wa.night)
                Spacer().frame(height: 24)
                if let error {
                    Text(verbatim: error).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
                    Spacer().frame(height: 16)
                    SettingsPrimaryButton(text: L("common_retry")) { attempt += 1 }
                } else {
                    ProgressView().tint(wa.red).scaleEffect(1.1).frame(width: 28, height: 28)
                    Spacer().frame(height: 12)
                    Text(L("ws_loading")).font(.dorr(13)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
                }
            }
            .padding(24)
            .frame(maxWidth: 440)
        }
        .task(id: "\(network.reconnectTick)-\(attempt)") {
            error = nil
            let result = await Workspace.shared.reload()
            switch result {
            case .ok(let data, _):
                if Workspace.shared.pick(data) != nil {
                    onHome()
                } else if !(data.invitations ?? []).isEmpty {
                    onInvitations()
                } else {
                    onOnboarding()
                }
            // A 401 already cleared the session (the navigation goes back to sign-in by itself).
            default:
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}

/// The invitations waiting for my answer (§14): which establishment, and — before I accept — whether the seat is exclusive
/// («حصري»: while I am in that establishment my own establishment cannot send offers). Accepting opens that establishment; with
/// no seat anywhere I can still create my own.
@MainActor
struct InvitationsScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var workspace = Workspace.shared
    let onBack: () -> Void
    let onJoined: () -> Void
    let onCreateOwn: () -> Void
    let onNothingLeft: () -> Void

    @State private var error: String?
    @State private var busy = false
    @State private var accepting: MembershipDto?
    @State private var declining: MembershipDto?

    var body: some View {
        let invitations = workspace.state?.invitations
        Page(title: L("ws_invitations_title"), onBack: onBack) {
            if let invitations {
                if invitations.isEmpty {
                    EmptyState(icon: "envelope.badge", title: L("ws_invitations_empty_title"), body: L("ws_invitations_empty_body"))
                        .padding(.top, 40)
                } else {
                    Text(L("ws_invitations_intro")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    ForEach(invitations, id: \.uuid) { invitation in
                        InvitationCard(invitation: invitation, busy: busy, onAccept: { accepting = invitation }, onDecline: { declining = invitation })
                    }
                }
            } else {
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
            }
            // Someone with no establishment of their own can still open one (C-02), instead of or besides joining.
            if workspace.state?.ownsEstablishment == false {
                Card {
                    HStack(spacing: 10) {
                        Image(systemName: "building.2.fill").font(.system(size: 20, weight: .semibold)).foregroundStyle(wa.red).frame(width: 24)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("ws_create_own_title")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                            Text(L("ws_create_own_body")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                        }
                        Spacer(minLength: 0)
                    }
                    ProfileTextLink(text: L("ws_create_own_cta"), enabled: !busy, action: onCreateOwn)
                }
            }
        }
        .messageToast(error)
        .task(id: network.reconnectTick) { await workspace.reload() }
        .dorrDialog(isPresented: Binding(get: { accepting != nil }, set: { if !$0 && !busy { accepting = nil } })) {
            if let invitation = accepting {
                let exclusive = invitation.isExclusive == true
                ConfirmDialog(icon: exclusive ? "lock.fill" : "person.badge.plus",
                              title: L("ws_accept_title", invitation.establishment?.name ?? ""),
                              message: L(exclusive ? "ws_accept_exclusive_message" : "ws_accept_message"),
                              loading: busy, confirmLabel: L("ws_accept"),
                              onConfirm: { accept(invitation) }, onDismiss: { if !busy { accepting = nil } })
            }
        }
        .dorrDialog(isPresented: Binding(get: { declining != nil }, set: { if !$0 && !busy { declining = nil } })) {
            if let invitation = declining {
                ConfirmDialog(icon: "envelope.badge", title: L("ws_decline_title"),
                              message: L("ws_decline_message", invitation.establishment?.name ?? ""),
                              loading: busy, confirmLabel: L("ws_decline"),
                              onConfirm: { decline(invitation) }, onDismiss: { if !busy { declining = nil } })
            }
        }
    }

    private func accept(_ invitation: MembershipDto) {
        busy = true
        error = nil
        Task {
            let result = await WorkspaceApi.accept(invitation.uuid)
            busy = false
            accepting = nil
            if let seat = result.value {
                Session.shared.chooseEstablishment(seat.establishment?.uuid ?? invitation.establishment?.uuid)
                await workspace.reload()
                onJoined()
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    private func decline(_ invitation: MembershipDto) {
        busy = true
        error = nil
        Task {
            let failed = await WorkspaceApi.decline(invitation.uuid, offline: L("login_error_offline"), generic: L("login_error_generic"))
            busy = false
            declining = nil
            if let failed {
                error = failed
                return
            }
            if let now = await workspace.reload().value, (now.invitations ?? []).isEmpty, (now.memberships ?? []).isEmpty { onNothingLeft() }
        }
    }
}

private struct InvitationCard: View {
    @Environment(\.wa) private var wa
    let invitation: MembershipDto
    let busy: Bool
    let onAccept: () -> Void
    let onDecline: () -> Void

    var body: some View {
        let exclusive = invitation.isExclusive == true
        let tone = exclusive ? Color(hex: 0xD97706) : Color(hex: 0x16A34A)
        Card {
            HStack(spacing: 10) {
                AvatarImage(url: invitation.establishment?.logo, size: 44)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: invitation.establishment?.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Text(verbatim: establishmentTypeLabel(invitation.establishment?.type)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                Spacer(minLength: 0)
            }
            // The exclusivity, said plainly before the answer (owner's rule, §14 point 5).
            HStack(alignment: .top, spacing: 8) {
                Image(systemName: exclusive ? "lock.fill" : "lock.open.fill").font(.system(size: 16, weight: .semibold)).foregroundStyle(tone).frame(width: 20)
                VStack(alignment: .leading, spacing: 2) {
                    Text(L(exclusive ? "ws_exclusive" : "ws_not_exclusive")).font(.dorr(13, .heavy)).foregroundStyle(tone)
                    Text(L(exclusive ? "ws_exclusive_body" : "ws_not_exclusive_body")).font(.dorr(12)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                }
                Spacer(minLength: 0)
            }
            .padding(12)
            .background(wa.night ? wa.darkWell : tone.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(tone.opacity(0.35), lineWidth: 1))
            HStack(spacing: 10) {
                Button(action: onAccept) {
                    Text(L("ws_accept")).font(.dorr(14, .bold)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity, minHeight: 44)
                        .background(wa.red.opacity(busy ? 0.6 : 1), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                .buttonStyle(.plain)
                .disabled(busy)
                Button(action: onDecline) {
                    Text(L("ws_decline")).font(.dorr(14, .bold)).foregroundStyle(wa.red)
                        .frame(maxWidth: .infinity, minHeight: 44)
                        .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.night ? wa.line : wa.red.opacity(0.25), lineWidth: 1.5))
                        .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .disabled(busy)
            }
        }
    }
}

/// «فرد» / «شركة».
func establishmentTypeLabel(_ type: String?) -> String {
    switch type {
    case "company": return L("est_type_company")
    case "individual": return L("est_type_individual")
    default: return type ?? ""
    }
}

/// The establishment's review status in the app language (EstablishmentStatus).
func establishmentStatusLabel(_ status: String?) -> String {
    switch status {
    case "draft": return L("est_status_draft")
    case "under_review": return L("est_status_under_review")
    case "approved": return L("est_status_approved")
    case "rejected": return L("est_status_rejected")
    case "suspended": return L("est_status_suspended")
    default: return status ?? ""
    }
}

/// The colour that goes with a status: green approved, amber waiting, red refused or stopped.
func establishmentStatusColor(_ status: String?) -> Color {
    switch status {
    case "approved": return Color(hex: 0x16A34A)
    case "under_review": return Color(hex: 0xD97706)
    case "rejected", "suspended": return Color(hex: 0xDC2626)
    default: return Color(hex: 0x64748B)
    }
}
