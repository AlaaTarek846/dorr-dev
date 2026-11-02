import SwiftUI
import UIKit

/// The Account tab's pages (androidApp `ProfileScreen.kt` `ProfileSub`).
enum ProfileSub: Equatable {
    case none, personalData, settings, notifications, security, legal, privacy, terms, addresses
    case invite, rate, support, ticket, quickChat, appearance, font

    /// Where the back step leads (the same as each page's own back arrow).
    var parent: ProfileSub {
        switch self {
        case .notifications, .appearance, .font, .legal, .security: return .settings
        case .ticket, .quickChat: return .support
        case .privacy, .terms: return .legal
        default: return .none
        }
    }
}

/// androidApp `ProfileScreen.kt` — the Account tab and every page it opens, sliding a fifth of the width.
@MainActor
struct ProfileScreen: View {
    @EnvironmentObject private var router: AppRouter
    let onOpenWallet: () -> Void
    private var sub: ProfileSub { router.profileSub }
    @State private var forward = true
    /// A tapped support notification: open that ticket.
    @State private var openTicketId: Int?
    @State private var isDeleting = false
    @State private var deleteError: String?

    var body: some View {
        ZStack {
            page(sub)
                .id(sub)
                .transition(.asymmetric(
                    insertion: .modifier(active: SlideFifth(fraction: forward ? 1 : -1), identity: SlideFifth(fraction: 0)),
                    removal: .modifier(active: SlideFifth(fraction: forward ? -1 : 1), identity: SlideFifth(fraction: 0))
                ))
        }
        .alert(deleteError ?? "", isPresented: Binding(get: { deleteError != nil }, set: { if !$0 { deleteError = nil } })) {
            Button("OK", role: .cancel) {}
        }
    }

    private func go(_ next: ProfileSub) {
        // Back to the root (or up a level) slides the other way, like Android's AnimatedContent.
        forward = next != .none && next != sub.parent
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { router.profileSub = next }
    }

    @ViewBuilder
    private func page(_ current: ProfileSub) -> some View {
        switch current {
        case .personalData: PersonalDataScreen(onBack: { go(.none) })
        case .notifications: NotificationSettingsScreen(onBack: { go(.settings) })
        case .security: SecurityScreen(onBack: { go(.settings) })
        case .privacy: LegalContentScreen(kind: .privacy) { go(.legal) }
        case .terms: LegalContentScreen(kind: .terms) { go(.legal) }
        case .legal:
            AccountListScreen(title: L("account_legal"), onBack: { go(.settings) }, items: [
                MenuEntry(icon: "shield.fill", label: L("account_privacy"), subtitle: L("account_privacy_sub")) { go(.privacy) },
                MenuEntry(icon: "doc.text.fill", label: L("account_terms"), subtitle: L("account_terms_sub")) { go(.terms) },
            ])
        case .addresses: AddressesScreen(onBack: { go(.none) })
        case .settings:
            SettingsMenuScreen(
                onBack: { go(.none) },
                onOpenNotifications: { go(.notifications) },
                onOpenSecurity: { go(.security) },
                onOpenLegal: { go(.legal) },
                onOpenAppearance: { go(.appearance) },
                onOpenFont: { go(.font) },
                onDeleteAccount: deleteAccount,
                isDeleting: isDeleting
            )
        case .invite: InviteFriendsScreen(onBack: { go(.none) })
        case .rate: RateAppScreen(onBack: { go(.none) })
        case .support: SupportMenuScreen(onBack: { go(.none) }, onOpenTicket: { go(.ticket) }, onOpenQuickChat: { go(.quickChat) })
        // The quick chat: guided help that ends in "solved" or a ticket (the ticket screen hosts both).
        case .quickChat: SupportTicketScreen(onBack: { go(.support) }, startQuickChat: true)
        case .ticket: SupportTicketScreen(onBack: { go(.support) }, openTicketId: openTicketId, onTicketOpened: { openTicketId = nil })
        case .appearance: AppearanceScreen(onBack: { go(.settings) })
        case .font: AppearanceFontScreen(onBack: { go(.settings) })
        case .none:
            ProfileMenuScreen(
                onOpenPersonalData: { go(.personalData) },
                onOpenSettings: { go(.settings) },
                onOpenAddresses: { go(.addresses) },
                onOpenInvite: { go(.invite) },
                onOpenRate: { go(.rate) },
                onOpenSupport: { go(.support) },
                onOpenWallet: onOpenWallet,
                onLogout: { router.logout() }
            )
        }
    }

    /// DELETE profile/account: soft-deleted with every token revoked, so the session is cleared without the
    /// logout request (it would 401 into "session expired"). Signing in with the same phone restores it.
    private func deleteAccount() {
        guard !isDeleting else { return }
        isDeleting = true
        Task {
            do {
                _ = try await ProfileApi.deleteAccount()
                router.accountDeleted()
            } catch {
                deleteError = error.serverMessage ?? L("account_delete_failed")
            }
            isDeleting = false
        }
    }
}

/// Slides a page a fifth of the width (mirrored in Arabic by SwiftUI) while fading.
private struct SlideFifth: ViewModifier {
    let fraction: CGFloat

    func body(content: Content) -> some View {
        content.offset(x: fraction * UIScreen.main.bounds.width / 5).opacity(fraction == 0 ? 1 : 0)
    }
}

// MARK: - The backdrop

/// `accountBackdrop` — the settings glow once appearance is loaded (or at night); before that, the pink
/// login-style wash.
struct AccountBackdrop: View {
    @EnvironmentObject private var appearance: AppearanceState
    @Environment(\.wa) private var wa

    var body: some View {
        if wa.night || appearance.snapshot != nil {
            PinkBackdrop()
        } else {
            GeometryReader { proxy in
                let w = proxy.size.width
                ZStack {
                    Color.white
                    RadialGradient(colors: [Color(hex: 0xF8BCA9), AppColors.otpGlowSoft, .clear],
                                   center: UnitPoint(x: -0.08, y: -0.12), startRadius: 0, endRadius: w * 1.3)
                    RadialGradient(colors: [AppColors.otpPinkBorder, .clear], center: UnitPoint(x: 0.5, y: -0.18), startRadius: 0, endRadius: w * 1.1)
                    RadialGradient(colors: [Color(hex: 0xF9C4B4), .clear], center: UnitPoint(x: 1.12, y: -0.08), startRadius: 0, endRadius: w * 0.9)
                }
                .environment(\.layoutDirection, .leftToRight)
            }
            .ignoresSafeArea()
            .allowsHitTesting(false)
        }
    }
}

// MARK: - Menu rows

struct MenuEntry: Identifiable {
    var id: String { label }
    let icon: String
    let label: String
    var subtitle: String?
    var danger = false
    let action: () -> Void
}

/// One Account row: brand well, bold label (brand when dangerous), soft subtitle, chevron to the end edge.
struct MenuRow: View {
    @Environment(\.wa) private var wa
    let entry: MenuEntry

    var body: some View {
        let ink = wa.night ? wa.ink : AppColors.textPrimary
        Button(action: entry.action) {
            HStack(spacing: 10) {
                Image(systemName: entry.icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 34, height: 34)
                    .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: entry.label).font(.dorr(13.5, .bold)).foregroundStyle(entry.danger ? wa.red : ink)
                    if let subtitle = entry.subtitle {
                        Text(verbatim: subtitle).font(.dorr(11)).foregroundStyle(entry.danger ? Color(hex: 0xF8BCA9) : ink.opacity(0.65))
                    }
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.forward").font(.system(size: 12, weight: .bold))
                    .foregroundStyle(wa.night ? Color(hex: 0x8B93A0) : wa.red.opacity(0.55))
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay { if wa.night { RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.line, lineWidth: 1) } }
            .shadow(color: wa.night ? .clear : wa.red.opacity(0.07), radius: 6, y: 2)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .padding(.vertical, 4)
    }
}

/// Rows come in one after another: fade + slide from the start edge, 40 ms apart.
struct StaggeredIn: ViewModifier {
    let index: Int
    @State private var shown = false

    func body(content: Content) -> some View {
        content
            .opacity(shown ? 1 : 0)
            .offset(x: shown ? 0 : -UIScreen.main.bounds.width / 3)
            .onAppear { withAnimation(.easeOut(duration: 0.35).delay(Double(index) * 0.04)) { shown = true } }
    }
}

/// A settings list page: the sub-header, then the rows (`AccountListScreen`).
struct AccountListScreen: View {
    let title: String
    let onBack: () -> Void
    let items: [MenuEntry]

    var body: some View {
        ZStack {
            AccountBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: title, onBack: onBack)
                ScrollView {
                    VStack(spacing: 0) {
                        ForEach(Array(items.enumerated()), id: \.element.id) { index, entry in
                            MenuRow(entry: entry).modifier(StaggeredIn(index: index))
                        }
                    }
                    .padding(.horizontal, 14)
                    .padding(.bottom, 16)
                }
            }
        }
    }
}

// MARK: - The Account page

@MainActor
private struct ProfileMenuScreen: View {
    @EnvironmentObject private var session: AuthSession
    @Environment(\.wa) private var wa
    let onOpenPersonalData: () -> Void
    let onOpenSettings: () -> Void
    let onOpenAddresses: () -> Void
    let onOpenInvite: () -> Void
    let onOpenRate: () -> Void
    let onOpenSupport: () -> Void
    let onOpenWallet: () -> Void
    let onLogout: () -> Void
    @State private var showLogoutConfirm = false

    var body: some View {
        let items = [
            // Watch & Earn (D17): above the personal data.
            MenuEntry(icon: "play.circle.fill", label: L("we_title"), subtitle: L("we_menu_sub")) { WatchEarnLink.shared.show() },
            MenuEntry(icon: "person.fill", label: L("account_personal_data"), subtitle: L("account_personal_data_sub"), action: onOpenPersonalData),
            MenuEntry(icon: "gearshape.fill", label: L("account_settings"), subtitle: L("account_settings_sub"), action: onOpenSettings),
            MenuEntry(icon: "mappin.circle.fill", label: L("account_places"), subtitle: L("account_places_sub"), action: onOpenAddresses),
            MenuEntry(icon: "square.and.arrow.up.fill", label: L("invite_title"), subtitle: L("invite_sub"), action: onOpenInvite),
            MenuEntry(icon: "star.fill", label: L("rate_title"), subtitle: L("rate_sub"), action: onOpenRate),
            MenuEntry(icon: "headphones", label: L("support_title"), subtitle: L("support_sub"), action: onOpenSupport),
            MenuEntry(icon: "rectangle.portrait.and.arrow.right", label: L("account_logout"), subtitle: L("account_logout_sub"), danger: true) { showLogoutConfirm = true },
        ]
        ZStack {
            AccountBackdrop()
            ScrollView {
                VStack(spacing: 0) {
                    HStack {
                        SettingsScreenTitle(title: L("account_title"))
                        Spacer()
                        Button(action: onOpenSettings) {
                            Image(systemName: "gearshape.fill").font(.system(size: 14)).foregroundStyle(wa.accent)
                                .frame(width: 34, height: 34)
                                .background(wa.settingsCard, in: Circle())
                                .overlay { if wa.night { Circle().stroke(wa.line, lineWidth: 1) } }
                                .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.08), radius: 6, y: 2)
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel(L("account_settings"))
                    }
                    .padding(.top, 14)
                    .padding(.bottom, 12)

                    identityRow.padding(.bottom, 12)
                    ProfileWalletCard(onOpenWallet: onOpenWallet).padding(.bottom, 10)
                    stats.padding(.bottom, 12)
                    ForEach(Array(items.enumerated()), id: \.element.id) { index, entry in
                        MenuRow(entry: entry).modifier(StaggeredIn(index: index))
                    }
                }
                .padding(.horizontal, 14)
                .padding(.bottom, 100)
            }
        }
        .dorrDialog(isPresented: $showLogoutConfirm) {
            ConfirmDialog(icon: "rectangle.portrait.and.arrow.right", title: L("account_logout"), message: L("logout_confirm_message"),
                          confirmLabel: L("common_yes"), onConfirm: { showLogoutConfirm = false; onLogout() }, onDismiss: { showLogoutConfirm = false })
        }
    }

    private var identityRow: some View {
        let user = session.user
        return HStack(spacing: 10) {
            AccountAvatar(user: user, size: 56)
                .frame(width: 56, height: 56)
                .background(wa.settingsCard, in: Circle())
                .clipShape(Circle())
                .overlay { if wa.night { Circle().stroke(wa.line, lineWidth: 1) } }
                .shadow(color: wa.night ? .clear : wa.red.opacity(0.1), radius: 6, y: 2)
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 6) {
                    Text(verbatim: user?.name.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? L("account_default_user"))
                        .font(.dorr(16, .bold)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary).lineLimit(1)
                    Image(systemName: "checkmark").font(.system(size: 8, weight: .heavy)).foregroundStyle(.white)
                        .frame(width: 15, height: 15).background(wa.red, in: Circle())
                        .accessibilityLabel(L("account_verified"))
                }
                if let phone = user?.phone {
                    Text(verbatim: "\u{2066}\(phone)\u{2069}").font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
                HStack(spacing: 5) {
                    Image(systemName: "star.fill").font(.system(size: 10))
                    Text(L("account_premium")).font(.dorr(11, .semibold))
                }
                .foregroundStyle(wa.accent)
                .padding(.horizontal, 8)
                .padding(.vertical, 2)
                .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: Capsule())
                .padding(.top, 4)
            }
            Spacer(minLength: 0)
        }
    }

    /// Android shows fixed numbers here for now (12 orders, 4.9 rating, 3 active).
    private var stats: some View {
        HStack(spacing: 6) {
            AccountStat(icon: "car.fill", tint: wa.night ? Color(hex: 0xA8C7FF) : Color(hex: 0x2563EB), well: wa.night ? Color(hex: 0x1C2838) : Color(hex: 0xEFF6FF),
                        value: "12", label: L("account_stat_orders"))
            AccountStat(icon: "star.fill", tint: wa.night ? Color(hex: 0xF6C56B) : Color(hex: 0xD97706), well: wa.night ? Color(hex: 0x2C2618) : Color(hex: 0xFEF3C7),
                        value: "4.9", label: L("account_stat_rating"))
            AccountStat(icon: "calendar", tint: wa.night ? Color(hex: 0xA8C7FF) : Color(hex: 0x2563EB), well: wa.night ? Color(hex: 0x1C2838) : Color(hex: 0xEFF6FF),
                        value: "3", label: L("account_stat_active"))
        }
    }
}

/// The avatar (cache-busted with `updated_at`, the server keeps one file name per account) or a person icon.
struct AccountAvatar: View {
    @Environment(\.wa) private var wa
    let user: UserDto?
    let size: CGFloat

    var body: some View {
        let url = ApiClient.mediaUrl(user?.avatar).flatMap { URL(string: "\($0.absoluteString)?v=\(user?.updatedAt ?? "0")") }
        AsyncImage(url: url) { phase in
            if let image = phase.image {
                image.resizable().scaledToFill()
            } else {
                Image(systemName: "person.fill").font(.system(size: size / 2.6)).foregroundStyle(wa.accent)
            }
        }
    }
}

private struct AccountStat: View {
    @Environment(\.wa) private var wa
    let icon: String
    let tint: Color
    let well: Color
    let value: String
    let label: String

    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(tint)
                .frame(width: 34, height: 34).background(well, in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: value).font(.dorr(15, .heavy)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary).lineLimit(1)
                Text(verbatim: label).font(.dorr(9, .medium)).foregroundStyle(wa.settingsMut).lineLimit(2)
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 6)
        .frame(maxWidth: .infinity)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(wa.night ? wa.line : Color(hex: 0xF3F4F6), lineWidth: 1))
        .shadow(color: wa.night ? .clear : Color(hex: 0x111928).opacity(0.04), radius: 4, y: 1)
    }
}

/// androidApp `ProfileWalletCard` — the wallet balance with a top-up pill.
@MainActor
struct ProfileWalletCard: View {
    @EnvironmentObject private var network: NetworkMonitor
    @Environment(\.wa) private var wa
    let onOpenWallet: () -> Void
    @State private var balanceText = "0.00"
    @State private var currencyText = ""

    var body: some View {
        Button(action: onOpenWallet) {
            HStack(spacing: 0) {
                VStack(alignment: .leading, spacing: 4) {
                    HStack(spacing: 5) {
                        Image(systemName: "wallet.pass.fill").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.accent)
                        Text(L("home_wallet_balance")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
                    }
                    HStack(spacing: 6) {
                        Image(systemName: "banknote").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.accent)
                        Text(verbatim: balanceText).font(.dorr(22, .heavy)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary)
                        if !currencyText.isEmpty { Text(verbatim: currencyText).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut) }
                    }
                }
                Spacer(minLength: 8)
                Text(L("home_wallet_topup")).font(.dorr(12, .bold)).foregroundStyle(.white)
                    .padding(.horizontal, 12).padding(.vertical, 8)
                    .background(wa.red, in: Capsule())
                    .shadow(color: wa.red.opacity(0.18), radius: 6, y: 2)
            }
            .padding(12)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay { if wa.night { RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.line, lineWidth: 1) } }
            .shadow(color: wa.night ? .clear : wa.red.opacity(0.07), radius: 6, y: 2)
        }
        .buttonStyle(.plain)
        .task(id: network.reconnectTick) {
            guard let dto = try? await WalletApi.balance().data else { return }
            balanceText = formatMinor(dto.totalMinor, nil)
            currencyText = dto.currencySymbol ?? dto.currencyCode ?? ""
        }
    }
}

// MARK: - Settings & support menus

@MainActor
private struct SettingsMenuScreen: View {
    let onBack: () -> Void
    let onOpenNotifications: () -> Void
    let onOpenSecurity: () -> Void
    let onOpenLegal: () -> Void
    let onOpenAppearance: () -> Void
    let onOpenFont: () -> Void
    let onDeleteAccount: () -> Void
    let isDeleting: Bool
    @State private var showContactSheet = false
    @State private var showAboutDialog = false
    @State private var showLanguageDialog = false
    @State private var showDeleteConfirm = false

    var body: some View {
        AccountListScreen(title: L("account_settings"), onBack: onBack, items: [
            MenuEntry(icon: "bell.fill", label: L("account_notifications"), subtitle: L("account_notifications_sub"), action: onOpenNotifications),
            MenuEntry(icon: "lock.shield.fill", label: L("security_title"), subtitle: L("security_sub"), action: onOpenSecurity),
            MenuEntry(icon: "globe", label: L("account_language"), subtitle: L("account_language_sub")) { showLanguageDialog = true },
            MenuEntry(icon: "paintpalette.fill", label: L("appearance_title"), subtitle: L("appearance_sub"), action: onOpenAppearance),
            MenuEntry(icon: "textformat", label: L("appearance_font"), subtitle: L("appearance_font_sub"), action: onOpenFont),
            MenuEntry(icon: "phone.fill", label: L("account_contact_us"), subtitle: L("account_contact_us_sub")) { showContactSheet = true },
            MenuEntry(icon: "info.circle.fill", label: L("account_about"), subtitle: L("account_about_sub")) { showAboutDialog = true },
            MenuEntry(icon: "doc.badge.gearshape.fill", label: L("account_legal"), subtitle: L("account_legal_sub"), action: onOpenLegal),
            MenuEntry(icon: "trash.fill", label: L("account_delete"), subtitle: L("account_delete_sub"), danger: true) { showDeleteConfirm = true },
        ])
        .dorrDialog(isPresented: $showContactSheet, bottom: true) { ContactUsSheet { showContactSheet = false } }
        .dorrDialog(isPresented: $showAboutDialog) { AboutDialog { showAboutDialog = false } }
        .dorrDialog(isPresented: $showLanguageDialog) { LanguageDialog { showLanguageDialog = false } }
        .dorrDialog(isPresented: $showDeleteConfirm) {
            ConfirmDialog(icon: "trash.fill", title: L("account_delete"), message: L("delete_confirm_message"), loading: isDeleting,
                          onConfirm: onDeleteAccount, onDismiss: { if !isDeleting { showDeleteConfirm = false } })
        }
    }
}

@MainActor
private struct SupportMenuScreen: View {
    let onBack: () -> Void
    let onOpenTicket: () -> Void
    let onOpenQuickChat: () -> Void
    @State private var showFaqSheet = false

    var body: some View {
        AccountListScreen(title: L("support_title"), onBack: onBack, items: [
            MenuEntry(icon: "headphones", label: L("support_quick_chat_title"), subtitle: L("support_quick_chat_sub"), action: onOpenQuickChat),
            MenuEntry(icon: "ticket.fill", label: L("support_ticket_title"), subtitle: L("support_ticket_sub"), action: onOpenTicket),
            MenuEntry(icon: "questionmark.circle.fill", label: L("account_faqs"), subtitle: L("account_faqs_sub")) { showFaqSheet = true },
        ])
        .dorrDialog(isPresented: $showFaqSheet, bottom: true) { FaqSheet { showFaqSheet = false } }
    }
}

// MARK: - Dialogs

extension View {
    /// Android's `Dialog`: over everything (the tab bar too), a 45 % black scrim that closes it when tapped.
    /// `bottom`: a panel rising from the bottom edge (Contact us, FAQ) instead of a centred card.
    func dorrDialog<Content: View>(isPresented: Binding<Bool>, bottom: Bool = false, @ViewBuilder content: @escaping () -> Content) -> some View {
        fullScreenCover(isPresented: isPresented) {
            DialogScrim(onDismiss: { isPresented.wrappedValue = false }, bottom: bottom, content: content)
        }
        .transaction { $0.disablesAnimations = true }
    }
}

private struct DialogScrim<Content: View>: View {
    @EnvironmentObject private var appearance: AppearanceState
    let onDismiss: () -> Void
    var bottom = false
    @ViewBuilder let content: () -> Content
    @State private var shown = false

    var body: some View {
        ZStack {
            Color.black.opacity(shown ? 0.45 : 0).ignoresSafeArea().onTapGesture(perform: onDismiss)
            if bottom {
                content().offset(y: shown ? 0 : 700)
            } else {
                content()
                    .opacity(shown ? 1 : 0)
                    .scaleEffect(shown ? 1 : 0.94)
            }
        }
        .background(ClearPresentationBackground())
        .onAppear { withAnimation(bottom ? .timingCurve(0.4, 0, 0.2, 1, duration: 0.3) : .easeOut(duration: 0.2)) { shown = true } }
    }
}

/// A full-screen cover's own background made clear (iOS 16.0 has no presentationBackground).
private struct ClearPresentationBackground: UIViewRepresentable {
    func makeUIView(context: Context) -> UIView {
        let view = UIView()
        DispatchQueue.main.async { view.superview?.superview?.backgroundColor = .clear }
        return view
    }

    func updateUIView(_ view: UIView, context: Context) {}
}

/// The dialog card: max 300 wide, rounded 20, the pink backdrop behind its content.
struct DialogCard<Content: View>: View {
    @ViewBuilder let content: () -> Content

    var body: some View {
        content()
            .background(PinkBackdropCard())
            .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
            .shadow(color: Color(hex: 0x001B53).opacity(0.14), radius: 16, y: 6)
            .frame(maxWidth: 300)
            .padding(.horizontal, 24)
            .contentShape(Rectangle())
            .onTapGesture {}
    }
}

/// [PinkBackdrop] sized to its container (a dialog), not to the screen.
private struct PinkBackdropCard: View {
    @EnvironmentObject private var appearance: AppearanceState
    @Environment(\.wa) private var wa

    var body: some View {
        GeometryReader { proxy in
            let w = proxy.size.width
            let glow = wa.night ? wa.darkAccent : wa.red
            ZStack {
                wa.night ? wa.bg : appearance.color("background", .white, dark: false)
                RadialGradient(colors: [glow.opacity(0.35), glow.opacity(0.10), .clear], center: UnitPoint(x: 0.5, y: -0.08), startRadius: 0, endRadius: w * 0.85)
                RadialGradient(colors: [glow.opacity(0.20), .clear], center: UnitPoint(x: 1.05, y: 0.02), startRadius: 0, endRadius: w * 0.55)
            }
        }
    }
}

/// androidApp `ConfirmDialog` — icon tile, title, message, then confirm (brand) and cancel (outlined).
struct ConfirmDialog: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let message: String
    var loading = false
    var confirmLabel = L("addr_yes")
    let onConfirm: () -> Void
    let onDismiss: () -> Void

    var body: some View {
        DialogCard {
            VStack(spacing: 0) {
                Image(systemName: icon).font(.system(size: 22, weight: .semibold)).foregroundStyle(wa.accent)
                    .frame(width: 56, height: 56)
                    .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    .overlay { if wa.night { RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(wa.line, lineWidth: 1) } }
                    .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.1), radius: 8, y: 3)
                    .padding(.bottom, 12)
                Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(wa.accent).multilineTextAlignment(.center)
                Text(verbatim: message).font(.dorr(13, .bold)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).lineSpacing(4).padding(.top, 4)
                HStack(spacing: 8) {
                    Button(action: onConfirm) {
                        HStack(spacing: 8) {
                            if loading {
                                ProgressView().tint(.white).scaleEffect(0.8)
                                Text(L("account_delete_in_progress")).font(.dorr(14, .bold))
                            } else {
                                Text(verbatim: confirmLabel).font(.dorr(15, .bold))
                            }
                        }
                        .foregroundStyle(.white)
                        .frame(maxWidth: .infinity, minHeight: 46)
                        .background(wa.red.opacity(loading ? 0.6 : 1), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        .shadow(color: Color(hex: 0x001B53).opacity(0.22), radius: 8, y: 3)
                    }
                    .buttonStyle(.plain)
                    .allowsHitTesting(!loading)
                    Button(action: onDismiss) {
                        Text(L("common_cancel")).font(.dorr(15, .bold)).foregroundStyle(wa.accent.opacity(loading ? 0.5 : 1))
                            .frame(maxWidth: .infinity, minHeight: 46)
                            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.night ? wa.line : AppColors.otpPinkBorder, lineWidth: 1.5))
                    }
                    .buttonStyle(.plain)
                    .allowsHitTesting(!loading)
                }
                .padding(.top, 16)
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 18)
        }
    }
}

/// The big brand "Close" button of the about / language dialogs.
private struct DialogCloseButton: View {
    @Environment(\.wa) private var wa
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(L("common_close")).font(.dorr(15, .bold)).foregroundStyle(.white)
                .frame(maxWidth: .infinity, minHeight: 44)
                .background(wa.red, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .shadow(color: Color(hex: 0x001B53).opacity(0.22), radius: 8, y: 3)
        }
        .buttonStyle(.plain)
    }
}

private struct AboutDialog: View {
    @Environment(\.wa) private var wa
    let onDismiss: () -> Void

    var body: some View {
        DialogCard {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    PinkIcon(icon: "info.circle.fill")
                    Text(L("app_name")).font(.dorr(18, .heavy)).foregroundStyle(wa.accent)
                }
                .padding(.bottom, 12)
                Text(L("about_version")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(6)
                Text(L("about_description")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(6).padding(.top, 22).padding(.bottom, 14)
                DialogCloseButton(action: onDismiss)
            }
            .padding(.horizontal, 16)
            .padding(.top, 18)
            .padding(.bottom, 16)
        }
    }
}

/// androidApp `LanguageDialog` — the server's languages (ar / en when it can't be reached), a spinner on the one
/// being downloaded; a download that fails keeps the current language and says so.
@MainActor
private struct LanguageDialog: View {
    @EnvironmentObject private var router: AppRouter
    @EnvironmentObject private var network: NetworkMonitor
    @Environment(\.wa) private var wa
    let onDismiss: () -> Void
    @State private var languages: [LanguageDto] = []
    @State private var loading = true
    /// Code of the language whose strings are being downloaded; the other choices wait for it.
    @State private var downloading: String?
    @State private var failed = false

    var body: some View {
        DialogCard {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    PinkIcon(icon: "globe")
                    Text(L("language_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.accent)
                }
                .padding(.bottom, 12)
                if loading && languages.isEmpty {
                    Text(L("language_loading")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.bottom, 14)
                } else {
                    VStack(spacing: 8) {
                        ForEach(shown, id: \.code) { language in
                            let code = language.code.lowercased()
                            LanguageOption(name: language.name, flag: flagCode(language), selected: code == router.locale.lowercased(),
                                           loading: downloading == code) {
                                choose(code)
                            }
                        }
                    }
                    .padding(.bottom, 14)
                }
                if failed {
                    Text(L("language_download_failed")).font(.dorr(12)).foregroundStyle(wa.danger).padding(.bottom, 10)
                }
                DialogCloseButton(action: onDismiss)
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 18)
        }
        .task(id: network.reconnectTick) {
            languages = (try? await LanguageApi.list().data) ?? []
            loading = false
            await router.syncLanguage()
        }
    }

    private var shown: [LanguageDto] {
        if !languages.isEmpty { return languages }
        return [
            LanguageDto.bundled(code: "ar", name: L("language_arabic"), direction: "rtl"),
            LanguageDto.bundled(code: "en", name: L("language_english"), direction: "ltr"),
        ]
    }

    private func choose(_ code: String) {
        guard downloading == nil, code != router.locale.lowercased() else { return }
        failed = false
        downloading = code
        Task {
            let switched = await router.chooseLanguage(code)
            downloading = nil
            if !switched { failed = true }
        }
    }

    private func flagCode(_ language: LanguageDto) -> String {
        let fromFlag = (language.flag?.code ?? "").trimmingCharacters(in: .whitespaces).lowercased()
        if fromFlag.count == 2 { return fromFlag }
        switch language.code.lowercased() {
        case "ar": return "sa"
        case "en": return "us"
        default: return String(language.code.lowercased().prefix(2))
        }
    }
}

extension LanguageDto {
    /// ar / en shown when the server list can't be had.
    static func bundled(code: String, name: String, direction: String) -> LanguageDto {
        let json = "{\"id\":0,\"code\":\"\(code)\",\"name\":\"\(name)\",\"direction\":\"\(direction)\"}"
        return (try? JSONDecoder().decode(LanguageDto.self, from: Data(json.utf8)))!
    }
}

private struct LanguageOption: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var appearance: AppearanceState
    let name: String
    let flag: String
    let selected: Bool
    let loading: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 10) {
                AsyncImage(url: URL(string: "https://flagcdn.com/w40/\(flag).png")) { phase in
                    if let image = phase.image { image.resizable().scaledToFill() } else { Color.gray.opacity(0.12) }
                }
                .frame(width: 22, height: 16)
                .clipShape(RoundedRectangle(cornerRadius: 3))
                .frame(width: 34, height: 34)
                .background(wa.night ? wa.bg : appearance.color("background", .white, dark: false), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                Text(verbatim: name).font(.dorr(14, .bold)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary)
                Spacer(minLength: 0)
                if loading {
                    ProgressView().tint(wa.red).scaleEffect(0.8).frame(width: 18, height: 18)
                } else {
                    Circle().stroke(selected ? wa.red : (wa.night ? Color(hex: 0x8B93A0) : Color(hex: 0xF8BCA9)), lineWidth: 2)
                        .frame(width: 18, height: 18)
                        .overlay { if selected { Circle().fill(wa.red).frame(width: 10, height: 10) } }
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .background(selected ? (wa.night ? wa.darkWell : wa.red.opacity(0.14)) : (wa.night ? wa.darkCard : Color(hex: 0xFBF7F8)),
                        in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(selected ? wa.red : (wa.night ? wa.line : .clear), lineWidth: 1.5))
        }
        .buttonStyle(.plain)
    }
}
