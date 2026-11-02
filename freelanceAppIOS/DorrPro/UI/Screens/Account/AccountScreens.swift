import SwiftUI

// freelanceApp `AccountScreens.kt` — the Account tab: the person, the wallet card, three stats and the menu; under it, one
// page at a time (my profile, settings and its pages, rating the app, inviting friends, support), sliding like the DORR app.

/// The calls of the cards on this tab (Android `ApiClient.wallet.balance()`, `portfolio.list()`, `lists.mySkills()`), only
/// what the cards read.
private struct AccountBalanceDto: Decodable {
    var currencyCode: String?
    var currencySymbol: String?
    var totalMinor: Int?
}

private enum AccountCardsApi {
    static func balance() async -> ApiResult<AccountBalanceDto> { await Api.call("GET", Api.freelance + "wallet") }

    static func portfolioCount() async -> ApiResult<[JSONAny]> { await Api.call("GET", Api.freelance + "profile/portfolio") }

    static func mySkillsCount() async -> ApiResult<[JSONAny]> { await Api.call("GET", Api.freelance + "profile/skills") }
}

/// `statusLabel` of HomeScreen.kt — the account status in words.
private func accountStatusLabel(_ status: String?) -> String {
    switch status {
    case "registered": return L("status_registered")
    case "profile_ready": return L("status_profile_ready")
    case "verification_submitted": return L("status_verification_submitted")
    case "verified": return L("status_verified")
    case "verification_rejected": return L("status_verification_rejected")
    case "changes_requested": return L("status_changes_requested")
    case "suspended": return L("status_suspended")
    case "blocked": return L("status_blocked")
    default: return status ?? ""
    }
}

/// The person at the top of a menu tab: the photo, the name (with the verified tick), the phone and the account status.
private struct MeHeader: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var session = Session.shared
    var withBadges = false

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
                HStack(spacing: 6) {
                    let name = (me?.name ?? "").trimmingCharacters(in: .whitespaces)
                    Text(verbatim: name.isEmpty ? L("account_default_user") : name)
                        .font(.dorr(16, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    if withBadges && me?.status == "verified" {
                        Image(systemName: "checkmark").font(.system(size: 8, weight: .heavy)).foregroundStyle(.white)
                            .frame(width: 15, height: 15)
                            .background(wa.red, in: Circle())
                            .accessibilityLabel(L("account_verified"))
                    }
                }
                // A phone number keeps its left-to-right order inside Arabic text.
                Text(verbatim: "\u{200E}" + (me?.phone ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                if withBadges {
                    HStack(spacing: 5) {
                        Image(systemName: "star.fill").font(.system(size: 10))
                        Text(verbatim: accountStatusLabel(me?.status)).font(.dorr(11, .semibold))
                    }
                    .foregroundStyle(wa.accent)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 2)
                    .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: Capsule())
                    .padding(.top, 4)
                } else {
                    Text(verbatim: accountStatusLabel(me?.status)).font(.dorr(12, .semibold)).foregroundStyle(wa.red)
                }
            }
            Spacer(minLength: 0)
        }
        .padding(.top, 4)
        .padding(.bottom, withBadges ? 0 : 12)
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

/// The balance in a card with a "top up" pill: one request when the page opens and when the connection returns.
private struct AccountWalletCard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onOpenWallet: () -> Void
    @State private var balanceText = "0.00"
    @State private var currencyText = ""

    var body: some View {
        HStack(spacing: 0) {
            VStack(alignment: .leading, spacing: 4) {
                HStack(spacing: 5) {
                    Image(systemName: "wallet.pass.fill").font(.system(size: 13)).foregroundStyle(wa.accent)
                    Text(L("home_wallet_balance")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
                }
                HStack(spacing: 6) {
                    Image(systemName: "dollarsign.circle").font(.system(size: 15)).foregroundStyle(wa.accent)
                    Text(verbatim: balanceText).font(.dorr(22, .heavy)).foregroundStyle(wa.settingsInk)
                    if !currencyText.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(verbatim: currencyText).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                    }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            Button(action: onOpenWallet) {
                Text(L("home_wallet_topup")).font(.dorr(12, .bold)).foregroundStyle(.white)
                    .padding(.horizontal, 12).padding(.vertical, 8)
                    .background(wa.red, in: Capsule())
                    .shadow(color: wa.red.opacity(0.18), radius: 6, y: 2)
            }
            .buttonStyle(.plain)
        }
        .padding(12)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay { if wa.night { RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.line, lineWidth: 1) } }
        .shadow(color: wa.night ? .clear : wa.red.opacity(0.07), radius: 6, y: 2)
        .contentShape(Rectangle())
        .onTapGesture(perform: onOpenWallet)
        .task(id: network.reconnectTick) {
            if let it = await AccountCardsApi.balance().value {
                balanceText = formatMinorGrouped(it.totalMinor ?? 0, nil)
                currencyText = it.currencySymbol ?? it.currencyCode ?? ""
            }
        }
    }
}

/// Three small tiles of what the freelancer has built: the profile completeness, the works and the skills.
private struct AccountStats: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var session = Session.shared
    @State private var works: Int?
    @State private var skills: Int?

    var body: some View {
        let dark = wa.night
        let blue = dark ? Color(hex: 0xA8C7FF) : Color(hex: 0x2563EB)
        let blueBg = dark ? Color(hex: 0x1C2838) : Color(hex: 0xEFF6FF)
        HStack(spacing: 6) {
            AccountStat(icon: "checkmark.circle.fill", iconTint: blue, iconBg: blueBg,
                        value: "\(session.me?.completeness?.percent ?? 0)%", label: L("stat_completeness"))
            AccountStat(icon: "briefcase.fill", iconTint: dark ? Color(hex: 0xF6C56B) : Color(hex: 0xD97706),
                        iconBg: dark ? Color(hex: 0x2C2618) : Color(hex: 0xFEF3C7), value: works.map(String.init) ?? "-", label: L("stat_works"))
            AccountStat(icon: "bolt.fill", iconTint: blue, iconBg: blueBg, value: skills.map(String.init) ?? "-", label: L("stat_skills"))
        }
        .task(id: network.reconnectTick) {
            if let list = await AccountCardsApi.portfolioCount().value { works = list.count }
            if let list = await AccountCardsApi.mySkillsCount().value { skills = list.count }
        }
    }
}

private struct AccountStat: View {
    @Environment(\.wa) private var wa
    let icon: String
    let iconTint: Color
    let iconBg: Color
    let value: String
    let label: String

    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(iconTint)
                .frame(width: 34, height: 34)
                .background(iconBg, in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: value).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                Text(verbatim: label).font(.dorr(9, .medium)).foregroundStyle(wa.settingsMut).lineLimit(2)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 6)
        .frame(maxWidth: .infinity)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(wa.night ? wa.line : Color(hex: 0xE5E7EB), lineWidth: 1))
        .shadow(color: wa.night ? .clear : Color(hex: 0x111928).opacity(0.04), radius: 4, y: 1)
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

/// My profile, a page under the Account tab: the personal data and the lists that make the profile, under a title with a back circle.
private struct ProfileHubPage: View {
    let actions: MainActions
    let onBack: () -> Void

    var body: some View {
        AccountPageFrame(title: L("tab_profile"), circleIcon: "arrow.forward", circleDescription: L("common_back"), onCircle: onBack) {
            ForEach([
                MenuEntry(icon: "person.fill", label: L("account_personal_data"), subtitle: L("account_personal_data_sub"), action: actions.openProfile),
                MenuEntry(icon: "square.grid.2x2.fill", label: L("home_open_specialties"), subtitle: L("hub_specialties_sub"), action: actions.openSpecialties),
                MenuEntry(icon: "bolt.fill", label: L("home_open_skills"), subtitle: L("hub_skills_sub"), action: actions.openSkills),
                MenuEntry(icon: "wrench.and.screwdriver.fill", label: L("home_open_experiences"), subtitle: L("hub_experiences_sub"), action: actions.openExperiences),
                MenuEntry(icon: "graduationcap.fill", label: L("home_open_certificates"), subtitle: L("hub_certificates_sub"), action: actions.openCertificates),
                MenuEntry(icon: "briefcase.fill", label: L("portfolio_title"), subtitle: L("hub_portfolio_sub"), action: actions.openPortfolio),
                MenuEntry(icon: "calendar.badge.checkmark", label: L("availability_title"), subtitle: L("availability_sub"), action: actions.openAvailability),
                MenuEntry(icon: "person.text.rectangle.fill", label: L("home_open_verification"), subtitle: L("hub_verification_sub"), action: actions.openVerification),
            ]) { MenuRow(entry: $0) }
        }
    }
}

/// A page of menu rows under a title with a back circle (the support menu) — the same look as the settings page.
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

private let accountRate = 1
private let accountSupport = 2
private let accountQuickChat = 3
private let accountTicket = 4
private let accountInvite = 5
private let accountProfile = 6

/// Which page of the Account tab is drawn: `account` is an `account*` page (0 = none) and `settings` is `settingsPage` (-1 = settings closed).
private struct AccountShown: Hashable {
    let account: Int
    let settings: Int

    /// How deep under the Account list the page is; going to a deeper one slides forward, to a shallower one back.
    func depth() -> Int {
        if account == accountQuickChat || account == accountTicket { return 2 }
        if account != 0 { return 1 }
        if settings == -1 { return 0 }
        if settings == 0 { return 1 }
        if (5...6).contains(settings) { return 3 }
        return 2
    }
}

/// My account: the settings (language, appearance, font, contact, about), rating the app, support, signing out and deleting the account — the DORR app's pages.
@MainActor
struct AccountScreen: View {
    let actions: MainActions

    @ObservedObject private var shell = ShellState.shared
    @State private var showLogout = false
    @State private var showDelete = false
    @State private var showAbout = false
    @State private var busy = false
    @State private var error: String?

    @State private var settingsOpen = false
    // 0 = the settings list, 1 = colours and mode, 2 = the font (each a page of its own, as in the DORR app),
    // 3 = notifications, 4 = policies & terms (5 privacy, 6 terms under it), 7 = security (devices and the log).
    @State private var settingsPage = 0
    @State private var showLanguage = false
    // The pages under the main list (0 = none): rate the app, the support menu, the quick chat and the tickets.
    @State private var accountPage = 0
    @State private var showContact = false
    @State private var showFaq = false
    /// The direction of the last page change: forward comes in from the trailing side, back from the leading one.
    @State private var forward = true

    private var shown: AccountShown { AccountShown(account: accountPage, settings: settingsOpen ? settingsPage : -1) }

    var body: some View {
        let sign: CGFloat = forward ? 1 : -1
        let width = UIScreen.main.bounds.width
        ZStack {
            page(shown)
                .id(shown)
                .transition(.asymmetric(
                    insertion: .offset(x: sign * width / 5).combined(with: .opacity),
                    removal: .offset(x: -sign * width / 5).combined(with: .opacity)
                ))
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
        .clipped()
        .messageToast(error)
        .onReceive(shell.$openProfileHub) { open in
            if open {
                go(account: accountProfile)
                shell.openProfileHub = false
            }
        }
        .dorrDialog(isPresented: $showLanguage) { LanguageDialog(onDismiss: { showLanguage = false }) }
        .dorrDialog(isPresented: $showContact, bottom: true) { ContactUsSheet(onDismiss: { showContact = false }) }
        .dorrDialog(isPresented: $showFaq, bottom: true) { FaqSheet(onDismiss: { showFaq = false }) }
        .dorrDialog(isPresented: $showLogout) {
            ConfirmDialog(icon: "rectangle.portrait.and.arrow.right", title: L("account_logout"), message: L("logout_confirm_message"),
                          loading: busy, confirmLabel: L("common_yes"), onConfirm: signOut, onDismiss: { if !busy { showLogout = false } })
        }
        .dorrDialog(isPresented: $showDelete) {
            ConfirmDialog(icon: "trash.fill", title: L("account_delete"), message: L("delete_confirm_message"),
                          loading: busy, confirmLabel: L("common_delete"), onConfirm: deleteAccount, onDismiss: { if !busy { showDelete = false } })
        }
        .dorrDialog(isPresented: $showAbout) { AboutDialog(onDismiss: { showAbout = false }) }
    }

    /// One page at a time, sliding like the DORR app's profile pages.
    @ViewBuilder private func page(_ shown: AccountShown) -> some View {
        if shown.account == accountRate {
            RateAppScreen(onBack: { go(account: 0) })
        } else if shown.account == accountProfile {
            ProfileHubPage(actions: actions, onBack: { go(account: 0) })
        } else if shown.account == accountInvite {
            InviteFriendsScreen(onBack: { go(account: 0) })
        } else if shown.account == accountSupport {
            AccountListPage(title: L("support_title"), onBack: { go(account: 0) }, entries: [
                MenuEntry(icon: "headphones", label: L("support_quick_chat_title"), subtitle: L("support_quick_chat_sub"), action: { go(account: accountQuickChat) }),
                MenuEntry(icon: "ticket.fill", label: L("support_ticket_title"), subtitle: L("support_ticket_sub"), action: { go(account: accountTicket) }),
                MenuEntry(icon: "questionmark.circle.fill", label: L("account_faqs"), subtitle: L("account_faqs_sub"), action: { showFaq = true }),
            ])
        } else if shown.account == accountQuickChat {
            // The guided help that ends in "solved" or a ticket (the ticket screen hosts both).
            SupportTicketScreen(onBack: { go(account: accountSupport) }, startQuickChat: true)
        } else if shown.account == accountTicket {
            SupportTicketScreen(onBack: { go(account: accountSupport) })
        } else if shown.settings == 1 {
            AppearanceScreen(onBack: { go(settingsPage: 0) })
        } else if shown.settings == 2 {
            AppearanceFontScreen(onBack: { go(settingsPage: 0) })
        } else if shown.settings == 3 {
            NotificationSettingsScreen(onBack: { go(settingsPage: 0) })
        } else if shown.settings == 7 {
            SecurityScreen(onBack: { go(settingsPage: 0) })
        } else if shown.settings == 4 {
            AccountListPage(title: L("account_legal"), onBack: { go(settingsPage: 0) }, entries: [
                MenuEntry(icon: "shield.fill", label: L("account_privacy"), subtitle: L("account_privacy_sub"), action: { go(settingsPage: 5) }),
                MenuEntry(icon: "doc.text.fill", label: L("account_terms"), subtitle: L("account_terms_sub"), action: { go(settingsPage: 6) }),
            ])
        } else if shown.settings == 5 {
            PrivacyPolicyScreen(onBack: { go(settingsPage: 4) })
        } else if shown.settings == 6 {
            TermsConditionsScreen(onBack: { go(settingsPage: 4) })
        } else if shown.settings == 0 {
            // The settings page, as in the DORR app: its own title with a back circle and the rows below.
            AccountPageFrame(title: L("account_settings"), circleIcon: "arrow.forward", circleDescription: L("common_back"),
                             onCircle: { go(settingsOpen: false) }) {
                ForEach(Array([
                    MenuEntry(icon: "bell.fill", label: L("account_notifications"), subtitle: L("account_notifications_sub"), action: { go(settingsPage: 3) }),
                    MenuEntry(icon: "lock.shield.fill", label: L("security_title"), subtitle: L("security_sub"), action: { go(settingsPage: 7) }),
                    MenuEntry(icon: "globe", label: L("account_language"), subtitle: L("account_language_sub"), action: { showLanguage = true }),
                    MenuEntry(icon: "paintpalette.fill", label: L("appearance_title"), subtitle: L("appearance_sub"), action: { go(settingsPage: 1) }),
                    MenuEntry(icon: "textformat", label: L("appearance_font"), subtitle: L("appearance_font_sub"), action: { go(settingsPage: 2) }),
                    MenuEntry(icon: "phone.fill", label: L("account_contact_us"), subtitle: L("account_contact_us_sub"), action: { showContact = true }),
                    MenuEntry(icon: "info.circle.fill", label: L("account_about"), subtitle: L("account_about_sub"), action: { showAbout = true }),
                    MenuEntry(icon: "checkmark.shield.fill", label: L("account_legal"), subtitle: L("account_legal_sub"), action: { go(settingsPage: 4) }),
                    MenuEntry(icon: "trash.fill", label: L("account_delete"), subtitle: L("account_delete_sub"), danger: true, action: { showDelete = true }),
                ].enumerated()), id: \.offset) { index, entry in AnimatedRow(index: index) { MenuRow(entry: entry) } }
            }
        } else {
            AccountPageFrame(title: L("tab_account"), circleIcon: "gearshape.fill", circleDescription: L("account_settings"),
                             onCircle: { go(settingsOpen: true) }) {
                MeHeader(withBadges: true)
                AccountWalletCard(onOpenWallet: actions.openWallet).padding(.top, 12)
                AccountStats().padding(.top, 10)
                Spacer().frame(height: 12)
                ForEach(Array([
                    MenuEntry(icon: "person.fill", label: L("tab_profile"), subtitle: L("account_profile_sub"), action: { go(account: accountProfile) }),
                    MenuEntry(icon: "rosette", label: L("plans_title"), subtitle: L("plans_sub"), action: actions.openPlans),
                    MenuEntry(icon: "gearshape.fill", label: L("account_settings"), subtitle: L("account_settings_sub"), action: { go(settingsOpen: true) }),
                    MenuEntry(icon: "star.fill", label: L("rate_title"), subtitle: L("rate_sub"), action: { go(account: accountRate) }),
                    MenuEntry(icon: "person.badge.plus", label: L("invite_title"), subtitle: L("invite_sub"), action: { go(account: accountInvite) }),
                    MenuEntry(icon: "headphones", label: L("support_title"), subtitle: L("support_sub"), action: { go(account: accountSupport) }),
                    MenuEntry(icon: "rectangle.portrait.and.arrow.right", label: L("account_logout"), subtitle: L("account_logout_sub"), danger: true,
                              action: { showLogout = true }),
                ].enumerated()), id: \.offset) { index, entry in AnimatedRow(index: index) { MenuRow(entry: entry) } }
            }
        }
    }

    /// Moves to another page of the tab: the slide direction is set first (forward = deeper), then the page changes.
    private func go(account: Int? = nil, settingsOpen open: Bool? = nil, settingsPage page: Int? = nil) {
        let nextAccount = account ?? accountPage
        let nextOpen = open ?? settingsOpen
        let nextPage = page ?? settingsPage
        let target = AccountShown(account: nextAccount, settings: nextOpen ? nextPage : -1)
        forward = target.depth() >= shown.depth()
        DispatchQueue.main.async {
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) {
                accountPage = nextAccount
                settingsOpen = nextOpen
                settingsPage = nextPage
            }
        }
    }

    private func signOut() {
        busy = true
        Task {
            // The token is revoked on the server; the phone forgets it whatever the answer.
            _ = await AuthApi.logout(playerId: Realtime.shared.pushId())
            Session.shared.clear()
        }
    }

    private func deleteAccount() {
        busy = true
        error = nil
        Task {
            let result = await AccountApi.delete()
            busy = false
            showDelete = false
            if case .ok = result {
                Session.shared.clear()
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
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
