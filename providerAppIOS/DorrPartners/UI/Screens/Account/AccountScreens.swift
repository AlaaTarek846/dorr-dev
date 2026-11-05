import SwiftUI

// providerApp `AccountScreens.kt` — the Account tab: who I am, the establishments I work in (switching between them), the
// invitations, creating my own establishment, leaving one I am a member of, the papers (owner), the settings (language,
// policies, about) and signing out; under it, one page at a time, sliding like the DORR app.

/// The person at the top of the Account tab: the photo, the name and the phone.
private struct MeHeader: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var session = Session.shared

    var body: some View {
        let me = session.me
        HStack(spacing: 10) {
            AvatarImage(url: me?.avatarUrl, size: 56)
                .frame(width: 56, height: 56)
                .background(wa.settingsCard, in: Circle())
                .clipShape(Circle())
                .overlay { if wa.night { Circle().stroke(wa.line, lineWidth: 1) } }
                .shadow(color: wa.night ? .clear : wa.red.opacity(0.1), radius: 6, y: 2)
            VStack(alignment: .leading, spacing: 0) {
                let name = (me?.name ?? "").trimmingCharacters(in: .whitespaces)
                Text(verbatim: name.isEmpty ? L("account_default_user") : name)
                    .font(.dorr(16, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                // A phone number keeps its left-to-right order inside Arabic text.
                Text(verbatim: "\u{200E}" + (me?.phone ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
            }
            Spacer(minLength: 0)
        }
        .padding(.top, 4)
        .padding(.bottom, 12)
    }
}

/// The round header button (settings / back): a card-coloured circle with a small icon.
private struct AccountCircle: View {
    @Environment(\.wa) private var wa
    let icon: String
    let description: String
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            Image(systemName: icon).font(.system(size: 13, weight: .bold)).foregroundStyle(wa.accent)
                .frame(width: 34, height: 34)
                .background(wa.settingsCard, in: Circle())
                .overlay { if wa.night { Circle().stroke(wa.line, lineWidth: 1) } }
                .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.08), radius: 6, y: 2)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(description)
    }
}

/// A row that slides in from the side, one after the other.
private struct AnimatedRow<Content: View>: View {
    let index: Int
    @ViewBuilder let content: () -> Content
    @State private var visible = false

    var body: some View {
        content()
            .opacity(visible ? 1 : 0)
            .offset(x: visible ? 0 : -UIScreen.main.bounds.width / 3)
            .onAppear {
                withAnimation(.easeInOut(duration: 0.35).delay(Double(index) * 0.04)) { visible = true }
            }
    }
}

/// One establishment I hold a seat in, in the look of a menu row: its logo, its name, my place in it (owner / member,
/// exclusive), and a tick on the one the app works in now. A tap works in it from now on.
private struct EstablishmentRow: View {
    @Environment(\.wa) private var wa
    let seat: MembershipDto
    let current: Bool
    let onClick: () -> Void

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 14, style: .continuous)
        let role = L(seat.isOwner == true ? "acc_role_owner" : "acc_role_member")
        let line = seat.isExclusive == true ? role + " · " + L("ws_exclusive") : role
        Button(action: onClick) {
            HStack(spacing: 10) {
                AvatarImage(url: seat.establishment?.logo, size: 34)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: seat.establishment?.name ?? "").font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Text(verbatim: line).font(.dorr(11)).foregroundStyle(wa.settingsInk.opacity(0.65))
                }
                Spacer(minLength: 0)
                if current {
                    Image(systemName: "checkmark").font(.system(size: 10, weight: .heavy)).foregroundStyle(.white)
                        .frame(width: 20, height: 20)
                        .background(wa.red, in: Circle())
                        .accessibilityLabel(L("acc_current"))
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .background(wa.settingsCard, in: shape)
            .overlay(shape.stroke(current ? wa.red : (wa.night ? wa.line : .clear), lineWidth: current ? 1.5 : 1))
            .shadow(color: wa.night ? .clear : wa.red.opacity(0.07), radius: 6, y: 2)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .padding(.vertical, 4)
    }
}

/// The frame of a menu page under the Account tab: its title with a round button at the end, then the rows.
private struct AccountPageFrame<Content: View>: View {
    let title: String
    let circleIcon: String
    let circleDescription: String
    let onCircle: () -> Void
    @ViewBuilder let content: () -> Content

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                HStack {
                    SettingsScreenTitle(title: title).frame(maxWidth: .infinity, alignment: .leading)
                    AccountCircle(icon: circleIcon, description: circleDescription, onClick: onCircle)
                }
                .padding(.top, 14)
                .padding(.bottom, 12)
                content()
            }
            .padding(.horizontal, 14)
            .padding(.bottom, 16)
        }
    }
}

/// A page of menu rows under a title with a back circle — the same look as the settings page.
private struct AccountListPage: View {
    let title: String
    let onBack: () -> Void
    let entries: [MenuEntry]

    var body: some View {
        AccountPageFrame(title: title, circleIcon: "arrow.forward", circleDescription: L("common_back"), onCircle: onBack) {
            ForEach(Array(entries.enumerated()), id: \.offset) { index, entry in AnimatedRow(index: index) { MenuRow(entry: entry) } }
        }
    }
}

private let pageList = 0
private let pageSettings = 1
private let pageLegal = 2
private let pagePrivacy = 3
private let pageTerms = 4

/// How deep a page sits under the Account list; going deeper slides forward, shallower slides back.
private func depthOf(_ page: Int) -> Int {
    switch page {
    case pageList: return 0
    case pageSettings: return 1
    case pageLegal: return 2
    default: return 3
    }
}

/// My account: who I am, **the establishments I work in** (switching between them, §14 — the choice becomes the
/// `X-Establishment` of every request), the invitations, creating my own establishment, leaving one I am a member of, the
/// papers (owner), the settings (language, policies, about) and signing out — the DORR app's pages.
@MainActor
struct AccountScreen: View {
    let actions: MainActions

    @ObservedObject private var session = Session.shared
    @ObservedObject private var workspace = Workspace.shared
    @ObservedObject private var shell = ShellState.shared

    @State private var page = pageList
    @State private var showLogout = false
    @State private var showLeave = false
    @State private var showLanguage = false
    @State private var showAbout = false
    @State private var busy = false
    @State private var error: String?
    /// The direction of the last page change: forward comes in from the trailing side, back from the leading one.
    @State private var forward = true

    var body: some View {
        let sign: CGFloat = forward ? 1 : -1
        let width = UIScreen.main.bounds.width
        ZStack {
            content(page)
                .id(page)
                .transition(.asymmetric(
                    insertion: .offset(x: sign * width / 5).combined(with: .opacity),
                    removal: .offset(x: -sign * width / 5).combined(with: .opacity)
                ))
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
        .clipped()
        .messageToast(error)
        .dorrDialog(isPresented: $showLanguage) { LanguageDialog(onDismiss: { showLanguage = false }) }
        .dorrDialog(isPresented: $showLogout) {
            ConfirmDialog(icon: "rectangle.portrait.and.arrow.right", title: L("account_logout"), message: L("logout_confirm_message"),
                          loading: busy, confirmLabel: L("common_yes"), onConfirm: signOut, onDismiss: { if !busy { showLogout = false } })
        }
        .dorrDialog(isPresented: $showLeave) {
            ConfirmDialog(icon: "rectangle.portrait.and.arrow.forward", title: L("acc_leave"),
                          message: L("acc_leave_message", session.membership?.establishment?.name ?? ""),
                          loading: busy, confirmLabel: L("acc_leave_confirm"), onConfirm: leave, onDismiss: { if !busy { showLeave = false } })
        }
        .dorrDialog(isPresented: $showAbout) { AboutDialog(onDismiss: { showAbout = false }) }
    }

    /// One page at a time, sliding like the DORR app's profile pages.
    @ViewBuilder private func content(_ shown: Int) -> some View {
        switch shown {
        case pagePrivacy:
            PrivacyPolicyScreen(onBack: { go(pageLegal) })
        case pageTerms:
            TermsConditionsScreen(onBack: { go(pageLegal) })
        case pageLegal:
            AccountListPage(title: L("account_legal"), onBack: { go(pageSettings) }, entries: [
                MenuEntry(icon: "shield.fill", label: L("account_privacy"), subtitle: L("account_privacy_sub"), action: { go(pagePrivacy) }),
                MenuEntry(icon: "doc.text.fill", label: L("account_terms"), subtitle: L("account_terms_sub"), action: { go(pageTerms) }),
            ])
        case pageSettings:
            AccountListPage(title: L("account_settings"), onBack: { go(pageList) }, entries: [
                MenuEntry(icon: "globe", label: L("account_language"), subtitle: L("account_language_sub"), action: { showLanguage = true }),
                MenuEntry(icon: "checkmark.shield.fill", label: L("account_legal"), subtitle: L("account_legal_sub"), action: { go(pageLegal) }),
                MenuEntry(icon: "info.circle.fill", label: L("account_about"), subtitle: L("account_about_sub"), action: { showAbout = true }),
            ])
        default:
            AccountPageFrame(title: L("tab_account"), circleIcon: "gearshape.fill", circleDescription: L("account_settings"),
                             onCircle: { go(pageSettings) }) {
                MeHeader()

                // The establishments I work in (§14): the one in use carries the tick; a tap switches to another.
                let seats = workspace.state?.memberships ?? []
                if !seats.isEmpty {
                    SectionLabel(text: L(seats.count > 1 ? "acc_establishments_switch" : "acc_establishment"))
                        .padding(.top, 4)
                        .padding(.bottom, 2)
                    ForEach(Array(seats.enumerated()), id: \.offset) { index, membership in
                        AnimatedRow(index: index) {
                            EstablishmentRow(seat: membership, current: membership.establishment?.uuid == session.establishment) {
                                choose(membership.establishment?.uuid)
                            }
                        }
                    }
                    Spacer().frame(height: 10)
                }

                ForEach(Array(menu.enumerated()), id: \.offset) { index, entry in
                    AnimatedRow(index: index + seats.count) { MenuRow(entry: entry) }
                }
            }
        }
    }

    private var menu: [MenuEntry] {
        let invitations = workspace.state?.invitations ?? []
        let seat = session.membership
        var entries: [MenuEntry] = []
        if !invitations.isEmpty {
            entries.append(MenuEntry(icon: "envelope.badge.fill", label: L("ws_invitations_title"), subtitle: L("acc_invitations_sub"), action: actions.openInvitations))
        }
        if seat?.isOwner == true {
            entries.append(MenuEntry(icon: "doc.text.fill", label: L("pd_title"), subtitle: L("acc_papers_sub"), action: actions.openDocuments))
        }
        // Running the establishment (C-03), each by its permission (§17); the server checks it again.
        if seat.can("branches.manage") {
            entries.append(MenuEntry(icon: "storefront.fill", label: L("br_title"), subtitle: L("acc_branches_sub"), action: actions.openBranches))
        }
        if seat.can("members.manage") {
            entries.append(MenuEntry(icon: "person.3.fill", label: L("tm_title"), subtitle: L("acc_team_sub"), action: actions.openTeam))
        }
        if seat.can("fleet.vehicles") {
            entries.append(MenuEntry(icon: "box.truck.fill", label: L("vh_title"), subtitle: L("acc_vehicles_sub"), action: actions.openVehicles))
        }
        // My own driving licence, when I work as a driver here.
        if let mine = seat, seat.can("driving.work") {
            entries.append(MenuEntry(icon: "person.text.rectangle.fill", label: L("drv_docs_title"), subtitle: L("acc_my_licence_sub"), action: { actions.openDriverDocs(mine.uuid) }))
        }
        if workspace.state?.ownsEstablishment == false {
            entries.append(MenuEntry(icon: "storefront.fill", label: L("ws_create_own_title"), subtitle: L("acc_create_own_sub"), action: actions.openOnboarding))
        }
        entries.append(MenuEntry(icon: "gearshape.fill", label: L("account_settings"), subtitle: L("account_settings_sub"), action: { go(pageSettings) }))
        // Leaving is for a member; my own establishment is never "left" (§14: the driver unlinks any time).
        if let seat, seat.isOwner != true {
            entries.append(MenuEntry(icon: "rectangle.portrait.and.arrow.forward", label: L("acc_leave"), subtitle: L("acc_leave_sub"), danger: true,
                                     action: { showLeave = true }))
        }
        entries.append(MenuEntry(icon: "rectangle.portrait.and.arrow.right", label: L("account_logout"), subtitle: L("account_logout_sub"), danger: true,
                                 action: { showLogout = true }))
        return entries
    }

    /// Moves to another page of the tab: the slide direction is set first (forward = deeper), then the page changes.
    private func go(_ next: Int) {
        forward = depthOf(next) >= depthOf(page)
        DispatchQueue.main.async {
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { page = next }
        }
    }

    private func choose(_ uuid: String?) {
        guard let uuid, uuid != session.establishment else { return }
        Session.shared.chooseEstablishment(uuid)
        shell.tab = tabHome
    }

    private func signOut() {
        busy = true
        Task {
            // The token is revoked on the server; the phone forgets it whatever the answer.
            _ = await AuthApi.logout(playerId: Realtime.shared.pushId())
            Session.shared.clear()
        }
    }

    /// Leaves the establishment the app works in now (not my own): the gate then picks where I work next.
    private func leave() {
        guard let membership = session.membership else { return }
        busy = true
        error = nil
        Task {
            let failed = await WorkspaceApi.leave(membership.uuid, offline: L("login_error_offline"), generic: L("login_error_generic"))
            busy = false
            showLeave = false
            if let failed {
                error = failed
                return
            }
            Session.shared.chooseEstablishment(nil)
            await Workspace.shared.reload()
            actions.openWorkspace()
        }
    }
}

/// The small muted title over the establishments list.
private struct SectionLabel: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        Text(verbatim: text).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
    }
}

/// "About the app": the same card as the user app - a pink backdrop, the app name with its icon, the version, a line about the app and a close button.
private struct AboutDialog: View {
    @Environment(\.wa) private var wa
    let onDismiss: () -> Void

    var body: some View {
        let version = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? ""
        DialogCard {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    PinkIcon(icon: "info.circle.fill")
                    Text(L("app_name")).font(.dorr(18, .heavy)).foregroundStyle(wa.accent)
                }
                .padding(.bottom, 12)
                Text(L("about_version", version)).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(6)
                Spacer().frame(height: 22)
                Text(L("about_description")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(6).padding(.bottom, 14)
                Button(action: onDismiss) {
                    Text(L("common_close")).font(.dorr(15, .bold)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity, minHeight: 44)
                        .background(wa.red, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        .shadow(color: Color(hex: 0x001B53).opacity(0.22), radius: 8, y: 3)
                }
                .buttonStyle(.plain)
            }
            .padding(.horizontal, 16)
            .padding(.top, 18)
            .padding(.bottom, 16)
        }
    }
}
