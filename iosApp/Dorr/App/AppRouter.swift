import SwiftUI

/// androidApp `navigation/DorrNavGraph.kt` — splash → onboarding → login → otp → main.
enum Route: Equatable {
    case splash, onboarding, login, otp, main
}

/// Where Splash goes once its animation finishes.
func routeAfterSplash(onboardingCompleted: Bool, authenticated: Bool) -> Route {
    if !onboardingCompleted { return .onboarding }
    return authenticated ? .main : .login
}

/// Where onboarding goes after Skip or the last step.
func routeAfterOnboarding(authenticated: Bool) -> Route {
    authenticated ? .main : .login
}

@MainActor
final class AppRouter: ObservableObject {
    @Published var route: Route = .splash
    @Published var locale: String = AppRouter.initialLanguage()
    /// Bumped when the current downloaded language was replaced by a newer version (screens re-read `L`).
    @Published var stringsRevision = 0

    // Held here (not passed around) so the phone number reaches the OTP screen as typed.
    @Published var pendingDialCode = ""
    @Published var pendingPhone = ""
    /// Set when the OTP request found a soft-deleted account and restored it.
    @Published var restoreMode = false
    @Published var sessionExpiredNotice = false
    /// Set right after account deletion, so Login can say signing in with the same phone restores it.
    @Published var accountDeletedNotice = false
    @Published var mainTab = 0
    /// The wallet drawn over the tabs (Android's `walletOpen` / `lastWalletOpen`).
    @Published var walletOpen = false
    /// The Account tab's open page (kept here so a language or font switch, which rebuilds the screens, stays on it).
    @Published var profileSub = ProfileSub.none
    /// True while a support conversation (or the quick chat) is on screen: the bottom bar steps aside.
    @Published var supportChatOpen = false

    init() {
        #if DEBUG
        DebugFlags.apply(router: self)
        #endif
        // A 401 on any authenticated request clears AuthSession and lands here.
        AuthSession.shared.onUnauthorized = { [weak self] in
            guard let self else { return }
            AppearanceState.shared.clear()
            self.sessionExpiredNotice = true
            self.accountDeletedNotice = false
            self.restoreMode = false
            if self.route != .login { self.go(.login) }
        }
    }

    func go(_ route: Route) {
        withAnimation(.easeInOut(duration: 0.34)) { self.route = route }
    }

    func setLocale(_ code: String) {
        AppLocale.current = code
        locale = code
    }

    /// androidApp `AppLanguageState.isRtl`: ar / en known; a downloaded language carries its own direction.
    var isRtl: Bool {
        switch locale {
        case "ar": return true
        case "en": return false
        default:
            if let meta = DownloadedTranslations.meta(), meta.code == locale { return meta.direction == "rtl" }
            return Locale.characterDirection(forLanguage: locale) == .rightToLeft
        }
    }

    /// Switches to [next]. A bundled language applies at once and drops any downloaded file; any other
    /// language is downloaded first and applied only once the download succeeded. False when it failed.
    func chooseLanguage(_ next: String) async -> Bool {
        let target = next.lowercased()
        if isBundledLanguage(target) {
            setLocale(target)
            DownloadedTranslations.clear()
            return true
        }
        if target == locale && DownloadedTranslations.load(target) != nil { return true }
        switch await DownloadedTranslations.download(target) {
        case .success:
            if target == locale { stringsRevision += 1 } else { setLocale(target) }
            return true
        default:
            return false
        }
    }

    /// Keeps a downloaded current language in step with the server list: a newer `android_version` is
    /// downloaded and applied, and a language no longer offered falls back to English. Once per launch.
    func syncLanguage() async {
        guard !isBundledLanguage(locale), let languages = try? await LanguageApi.list().data, !languages.isEmpty else { return }
        guard let entry = languages.first(where: { $0.code.caseInsensitiveCompare(locale) == .orderedSame }) else {
            fallBackToEnglish()
            return
        }
        if let stored = DownloadedTranslations.meta(), stored.code == locale, let version = entry.androidVersion, stored.version == version { return }
        switch await DownloadedTranslations.download(locale) {
        case .success: stringsRevision += 1
        case .notAvailable: fallBackToEnglish()
        case .failed: break
        }
    }

    private func fallBackToEnglish() {
        setLocale("en")
        DownloadedTranslations.clear()
    }

    /// The saved language, or en when it is a downloaded one whose file is gone.
    private static func initialLanguage() -> String {
        let saved = AppLocale.current
        if isBundledLanguage(saved) || DownloadedTranslations.load(saved) != nil { return saved }
        AppLocale.current = "en"
        return "en"
    }

    func otpRequested(dialCode: String, phone: String, restore: Bool) {
        sessionExpiredNotice = false
        accountDeletedNotice = false
        pendingDialCode = dialCode
        pendingPhone = phone
        restoreMode = restore
        go(.otp)
    }

    func verified() {
        restoreMode = false
        mainTab = 0
        walletOpen = false
        profileSub = .none
        go(.main)
    }

    func logout() {
        if let token = AuthSession.shared.token, !token.isEmpty {
            // With this phone's push id, so the server stops pushing to it.
            let playerId = ChatPush.shared.currentId()
            Task { _ = try? await MobileAuthApi.logout(token: token, LogoutRequest(playerId: playerId)) }
        }
        // Whatever call or voice room there is ends with the account.
        CallController.shared.signedOut()
        VoiceRoomController.shared.leave()
        ChatRealtime.shared.stop()
        ChatPush.shared.signedOut()
        ChatStore.shared.clear()
        ChatOutbox.shared.clear()
        LiveLocationSharing.shared.stopAll()
        ChatLock.lock()
        AppearanceState.shared.clear()
        AuthSession.shared.clear()
        mainTab = 0
        walletOpen = false
        profileSub = .none
        sessionExpiredNotice = false
        accountDeletedNotice = false
        restoreMode = false
        go(.login)
    }

    /// The account was soft-deleted and every token revoked: mirror logout without the request.
    func accountDeleted() {
        AppearanceState.shared.clear()
        AuthSession.shared.clear()
        mainTab = 0
        walletOpen = false
        profileSub = .none
        sessionExpiredNotice = false
        restoreMode = false
        accountDeletedNotice = true
        go(.login)
    }
}
