import SwiftUI

// driverApp `ui/AccountScreen.kt`.

/**
 * The fourth tab: who I am (name, rating, account status), everything that is about how I drive,
 * how the app looks and speaks (dark / light, Arabic / English), and signing out.
 */
struct AccountScreen: View {
    let onOpenSignup: () -> Void
    let onOpenPreferences: () -> Void
    let onOpenScheduled: () -> Void
    let onOpenAirport: () -> Void
    let onOpenCases: () -> Void
    let onOpenSafety: () -> Void
    let onOpenNotifications: () -> Void
    let onSignedOut: () -> Void
    let onOpenBus: () -> Void
    let onOpenMarine: () -> Void
    let onOpenHire: () -> Void
    let onOpenLessons: () -> Void
    let onOpenWithMe: () -> Void
    let onOpenRank: () -> Void
    let onOpenSupport: () -> Void
    let onOpenSettings: () -> Void
    let onOpenHistory: () -> Void
    let onOpenDriverAi: () -> Void

    @Environment(\.pal) private var pal
    @ObservedObject private var prefs = DriverPrefs.shared
    @ObservedObject private var live = DriverLive.shared
    @State private var driver: DriverDto?
    @State private var earnings: EarningsDto?
    @State private var presence: PresenceDto?
    @State private var confirmLogout = false
    // O-85: only what the captain's vehicle can do (nil until known = nothing service-specific shown).
    @State private var services: Set<String>?

    init(
        onOpenSignup: @escaping () -> Void,
        onOpenPreferences: @escaping () -> Void,
        onOpenScheduled: @escaping () -> Void,
        onOpenAirport: @escaping () -> Void,
        onOpenCases: @escaping () -> Void,
        onOpenSafety: @escaping () -> Void,
        onOpenNotifications: @escaping () -> Void,
        onSignedOut: @escaping () -> Void,
        onOpenBus: @escaping () -> Void = {},
        onOpenMarine: @escaping () -> Void = {},
        onOpenHire: @escaping () -> Void = {},
        onOpenLessons: @escaping () -> Void = {},
        onOpenWithMe: @escaping () -> Void = {},
        onOpenRank: @escaping () -> Void = {},
        onOpenSupport: @escaping () -> Void = {},
        onOpenSettings: @escaping () -> Void = {},
        onOpenHistory: @escaping () -> Void = {},
        onOpenDriverAi: @escaping () -> Void = {}
    ) {
        self.onOpenSignup = onOpenSignup
        self.onOpenPreferences = onOpenPreferences
        self.onOpenScheduled = onOpenScheduled
        self.onOpenAirport = onOpenAirport
        self.onOpenCases = onOpenCases
        self.onOpenSafety = onOpenSafety
        self.onOpenNotifications = onOpenNotifications
        self.onSignedOut = onSignedOut
        self.onOpenBus = onOpenBus
        self.onOpenMarine = onOpenMarine
        self.onOpenHire = onOpenHire
        self.onOpenLessons = onOpenLessons
        self.onOpenWithMe = onOpenWithMe
        self.onOpenRank = onOpenRank
        self.onOpenSupport = onOpenSupport
        self.onOpenSettings = onOpenSettings
        self.onOpenHistory = onOpenHistory
        self.onOpenDriverAi = onOpenDriverAi
    }

    private func offers(_ service: String) -> Bool { services?.contains(service) == true }

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                ScreenHeader(L("acct_title"), onBack: nil) { NotificationBell(onClick: onOpenNotifications) }
                ScrollView {
                    VStack(spacing: 14) {
                        ProfileCard(driver: driver, earnings: earnings).staggerIn(0)

                        // What stops the captain from working is the first thing to fix, so it sits on top.
                        if let p = presence, !(p.canGoOnline ?? false), let reason = p.reason, reason != "driving_limit" {
                            VStack(spacing: 0) {
                                ListRow(icon: "person.text.rectangle.fill", title: L("home_cannot_work"),
                                        subtitle: driver?.statusReason ?? problemText(reason), tint: pal.warning, onClick: onOpenSignup)
                            }
                            .frame(maxWidth: .infinity)
                            .surface()
                            .staggerIn(1)
                        }

                        SectionLabel(L("acct_driving")).frame(maxWidth: .infinity, alignment: .leading)
                        VStack(spacing: 0) {
                            ListRow(icon: "person.text.rectangle.fill", title: L("acct_signup"), subtitle: L("acct_signup_hint"), onClick: onOpenSignup)
                            RowDivider()
                            // O-78: my past rides and their details.
                            ListRow(icon: "clock.arrow.circlepath", title: L("rh_title"), subtitle: L("rh_hint"), onClick: onOpenHistory)
                            RowDivider()
                            // D-304..D-318: settings and the account.
                            ListRow(icon: "gearshape.fill", title: L("st_title"), subtitle: L("st_home_hint"), onClick: onOpenSettings)
                            RowDivider()
                            // D-285..D-297: support, the help centre and lost items.
                            ListRow(icon: "headphones", title: L("sp_title"), subtitle: L("sp_home_hint"), tint: pal.accent, onClick: onOpenSupport)
                            RowDivider()
                            // Block 06: DORR Driver AI — where the work is, clashes with my bookings, my daily goal.
                            ListRow(icon: "sparkles", title: L("dai_title"), subtitle: L("dai_home_hint"), tint: Color(hex: 0x6D5BFF), onClick: onOpenDriverAi)
                            RowDivider()
                            // D-239..D-259: my rank and performance.
                            ListRow(icon: "medal.fill", title: L("rk_title"), subtitle: L("rk_home_hint"), tint: pal.warning, onClick: onOpenRank)
                            RowDivider()
                            // O-85: each service only for the vehicle that does it (a car: rides, With Me, airport, hire,
                            // lessons; a motorcycle: rides; a bus: bus trips; a boat: the sea; no vehicle: hire and lessons).
                            if offers("rides") || offers("hourly") {
                                ListRow(icon: "slider.horizontal.3", title: L("pf_title"), subtitle: L("pf_home_hint"), onClick: onOpenPreferences)
                                RowDivider()
                            }
                            if offers("scheduled") {
                                ListRow(icon: "calendar", title: L("sch_title"), subtitle: L("sch_home_hint"), tint: pal.orange, onClick: onOpenScheduled)
                                RowDivider()
                            }
                            // O-30: «أنا معك» — riders on my way.
                            // «With Me» is a category of passenger rides.
                            if offers("rides") {
                                ListRow(icon: "person.3.fill", title: L("wm_title"), subtitle: L("wm_home_hint"), tint: pal.success, onClick: onOpenWithMe)
                                RowDivider()
                            }
                            // O-20, O-21: the captain's bus trips (a bus or minibus registered).
                            if offers("bus") {
                                ListRow(icon: "bus.fill", title: L("bus_my_trips"), subtitle: L("bus_home_hint"), onClick: onOpenBus)
                                RowDivider()
                            }
                            // O-26c: find a driver — drive a rider's own car, by the hour or the day.
                            if offers("hire") {
                                ListRow(icon: "person.crop.circle.badge.questionmark", title: L("hj_title"), subtitle: L("hj_home_hint"),
                                        tint: Color(hex: 0x00897B), onClick: onOpenHire)
                                RowDivider()
                            }
                            // O-26b: driving lessons — I teach: lesson packs and the lessons asked for.
                            if offers("lessons") {
                                ListRow(icon: "graduationcap.fill", title: L("tr_title"), subtitle: L("tr_home_hint"), tint: Color(hex: 0x7B1FA2), onClick: onOpenLessons)
                                RowDivider()
                            }
                            // O-25: the captain's boat — sea trips and yacht hire (a boat or a yacht registered).
                            if offers("marine") {
                                ListRow(icon: "ferry.fill", title: L("mr_title"), subtitle: L("mr_home_hint"), tint: Color(hex: 0x0288D1), onClick: onOpenMarine)
                                RowDivider()
                            }
                            if offers("airport") {
                                ListRow(icon: "airplane", title: L("air_title"), subtitle: L("air_home_hint"), onClick: onOpenAirport)
                                RowDivider()
                            }
                            ListRow(icon: "hammer.fill", title: L("cs_title"), subtitle: L("cs_home_hint"), tint: pal.warning, onClick: onOpenCases)
                            RowDivider()
                            ListRow(icon: "shield.fill", title: L("sc_title"), subtitle: L("sc_home_hint"), tint: pal.danger, onClick: onOpenSafety)
                        }
                        .frame(maxWidth: .infinity)
                        .surface()
                        .staggerIn(2)

                        SectionLabel(L("acct_app")).frame(maxWidth: .infinity, alignment: .leading)
                        VStack(alignment: .leading, spacing: 10) {
                            Text(L("acct_appearance")).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                            Segmented(
                                options: [
                                    (DriverPrefs.modeSystem, L("acct_mode_system")),
                                    (DriverPrefs.modeLight, L("acct_mode_light")),
                                    (DriverPrefs.modeDark, L("acct_mode_dark")),
                                ],
                                selected: prefs.darkMode,
                                onSelect: { prefs.chooseDarkMode($0) }
                            )
                            VSpace(4)
                            Text(L("acct_language")).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                            Segmented(
                                options: [
                                    (DriverPrefs.langSystem, L("acct_lang_system")),
                                    (DriverPrefs.langAr, L("acct_lang_ar")),
                                    (DriverPrefs.langEn, L("acct_lang_en")),
                                ],
                                selected: prefs.language,
                                onSelect: { chooseLanguage($0) }
                            )
                        }
                        .padding(14)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .surface()
                        .staggerIn(3)
                        VStack(spacing: 0) {
                            ListRow(icon: "bell.fill", title: L("nt_title"), subtitle: L("acct_notifications_hint"),
                                    badge: live.unread > 0 ? (live.unread > 99 ? "99+" : String(live.unread)) : nil, onClick: onOpenNotifications)
                        }
                        .frame(maxWidth: .infinity)
                        .surface()
                        .staggerIn(4)

                        OutlineButton(L("home_logout"), color: pal.danger, fillMaxWidth: true) { confirmLogout = true }
                            .staggerIn(5)
                        Text(L("acct_version", Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? ""))
                            .font(.dorr(11)).foregroundStyle(pal.faint)
                            .padding(.bottom, 16)
                    }
                    .padding(.horizontal, 16)
                }
            }
        }
        .task {
            if let d = await DriverApi.me().value { driver = d }
            if let r = await DriverApi.workServices().value {
                services = Set((r.services ?? []).filter { $0.on ?? true }.map { $0.key ?? "" })
            }
            if let e = await DriverApi.earnings().value { earnings = e }
            if let p = await DriverApi.presence().value { presence = p }
        }
        .dorrDialog(isPresented: $confirmLogout) {
            AlertDialogCard(icon: { DorrIcon("rectangle.portrait.and.arrow.right", size: 24, tint: pal.danger) },
                            title: { Text(L("acct_logout_title")).font(.dorr(24, .bold)).foregroundStyle(pal.ink) },
                            text: { Text(L("acct_logout_message")).foregroundStyle(pal.mut) },
                            buttons: {
                                AlertTextButton(L("cancel"), color: pal.mut, weight: .semibold) { confirmLogout = false }
                                AlertTextButton(L("home_logout"), color: pal.danger, weight: .bold) {
                                    confirmLogout = false
                                    Task {
                                        Duty.shared.stop()
                                        _ = await DriverApi.goOffline()
                                        await DriverPush.shared.signedOut()
                                        _ = await DriverApi.logout()
                                        DriverLive.shared.stop()
                                        DriverBackend.shared.clear()
                                        onSignedOut()
                                    }
                                }
                            })
        }
    }
}

private struct ProfileCard: View {
    @Environment(\.pal) private var pal
    let driver: DriverDto?
    let earnings: EarningsDto?

    var body: some View {
        let stats = earnings?.profile
        VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 14) {
                ZStack {
                    Circle().fill(pal.well)
                    if let url = Api.mediaUrl(driver?.avatarUrl) {
                        AsyncImage(url: url) { image in image.resizable().scaledToFill() } placeholder: { Color.clear }
                    } else {
                        Text(String((driver?.name ?? driver?.phone ?? "?").trimmingCharacters(in: .whitespaces).prefix(1)).uppercased())
                            .font(.dorr(26, .heavy)).foregroundStyle(pal.accent)
                    }
                }
                .frame(width: 64, height: 64)
                .clipShape(Circle())
                VStack(alignment: .leading, spacing: 0) {
                    Text(driver?.name ?? driver?.phone ?? "…").font(.dorr(18, .heavy)).foregroundStyle(pal.ink).lineLimit(1)
                    // The number reads left to right in Arabic too.
                    if driver?.name != nil {
                        Text(driver?.phone ?? "").font(.dorr(13)).foregroundStyle(pal.mut).padding(.top, 2)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            HStack(spacing: 8) {
                if let status = statusOf(driver?.status) {
                    Chip(L(status.0), selected: false, tint: status.1)
                }
                if let rating = stats?.rating {
                    Chip("★ " + acctNumber(rating, places: 1), tint: pal.warning)
                }
                if (stats?.tripsCount ?? 0) > 0 {
                    Chip(L("acct_trips_total") + " " + String(stats?.tripsCount ?? 0), tint: pal.accent)
                }
            }
        }
        .padding(18)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface(24, elevation: 8)
    }

    /** The account state as a label and a colour (the words stay the ones the sign-up screen already uses). */
    private func statusOf(_ status: String?) -> (String, Color)? {
        switch status {
        case "active": return ("status_active", pal.success)
        case "pending_documents": return ("status_pending_documents", pal.warning)
        case "under_review": return ("status_under_review", pal.warning)
        case "suspended": return ("status_suspended", pal.danger)
        case "restricted": return ("status_restricted", pal.danger)
        case "blocked": return ("status_blocked", pal.danger)
        default: return nil
        }
    }
}

/// `String.format("%.Nf", x)` in the app's language (its digits), as `Locale.getDefault()` formats on Android.
private func acctNumber(_ value: Double, places: Int) -> String {
    let f = NumberFormatter()
    f.locale = Locale(identifier: DriverPrefs.locale)
    f.numberStyle = .decimal
    f.usesGroupingSeparator = false
    f.minimumFractionDigits = places
    f.maximumFractionDigits = places
    f.roundingMode = .halfUp
    return f.string(from: NSNumber(value: value)) ?? String(format: "%.\(places)f", value)
}
