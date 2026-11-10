import Foundation
import WidgetKit
import Network
import Security

/// androidApp `AuthSession.kt`. The token lives in the Keychain (iOS's counterpart of private
/// SharedPreferences that also survives app updates); the user JSON in UserDefaults.
@MainActor
final class AuthSession: ObservableObject {
    static let shared = AuthSession()

    private let userKey = "dorr_auth.user"
    private let tokenAccount = "dorr_auth.token"

    /// Bumped on every token/user write so screens can key their state on the active account.
    @Published private(set) var sessionVersion = 0

    @Published var token: String? {
        didSet {
            Keychain.set(token, for: tokenAccount)
            sessionVersion += 1
            // The home-screen widgets read the server as this account (or show «sign in»).
            SportsWidgetStore.token = token
            WidgetCenter.shared.reloadAllTimelines()
        }
    }

    @Published var user: UserDto? {
        didSet {
            UserDefaults.standard.set(user.flatMap { try? JSONEncoder().encode($0) }, forKey: userKey)
            sessionVersion += 1
        }
    }

    /// Invoked whenever an authenticated request comes back 401. The client clears the
    /// session before calling this, so callers only need to move the UI (back to Login).
    var onUnauthorized: (() -> Void)?

    var isAuthenticated: Bool { !(token ?? "").isEmpty }

    private init() {
        token = Keychain.get(tokenAccount)
        user = UserDefaults.standard.data(forKey: userKey).flatMap { try? JSONDecoder().decode(UserDto.self, from: $0) }
    }

    func clear() {
        // Another person may sign in on this phone: no live chat or pushes for the old account.
        ChatRealtime.shared.stop()
        token = nil
        user = nil
        AppearanceStore.clearUser()
        WalletCountry.shared.forget()
    }
}

/// androidApp `DeviceId.kt` — a random id per app install, sent as `X-Device-Id`. Not a hardware id.
enum DeviceId {
    private static let key = "dorr_device.device_id"

    static let current: String = {
        if let existing = UserDefaults.standard.string(forKey: key) { return existing }
        let id = UUID().uuidString.lowercased()
        UserDefaults.standard.set(id, forKey: key)
        return id
    }()
}

/// androidApp `AppLocale.kt` — the in-app language sent as `X-Locale` ("ar" / "en").
enum AppLocale {
    private static let key = "dorr_locale"

    /// The saved choice. The very first launch has none: the phone's language when the app ships it (Arabic or
    /// English), English for any other — saved at once, so it stays put until the user picks another in the app
    /// (androidApp `AppLanguagePreferences.get`).
    static var current: String {
        get {
            if let saved = UserDefaults.standard.string(forKey: key) { return saved }
            let phone = (Locale.preferredLanguages.first ?? "").lowercased()
            let first = phone.hasPrefix("ar") ? "ar" : "en"
            UserDefaults.standard.set(first, forKey: key)
            LocalIntelligence.language = first  // the extensions' copy, as the setter keeps it
            return first
        }
        set {
            UserDefaults.standard.set(newValue, forKey: key)
            LocalIntelligence.language = newValue
        }
    }

    static var isArabic: Bool { current == "ar" }

    /// Locale for dates and weekday names — the app language, not the phone's.
    /// Always Latin digits (0-9): an Arabic screen mixed ٣٠ with 1500.00 before.
    static var locale: Locale { Locale(identifier: current.hasPrefix("ar") ? "ar@numbers=latn" : current) }
}

/// androidApp `OnboardingStore.kt` — kept apart from AuthSession so logout keeps it.
enum OnboardingStore {
    private static let key = "dorr_onboarding.onboarding_completed"

    static var isCompleted: Bool { UserDefaults.standard.bool(forKey: key) }

    static func markCompleted() { UserDefaults.standard.set(true, forKey: key) }
}

/// androidApp `AppearanceStore.kt` — cached user appearance + platform defaults (pre-login theme).
enum AppearanceStore {
    private static let userKey = "dorr_appearance.appearance_user"
    private static let lightKey = "dorr_appearance.platform_light_tokens"
    private static let darkKey = "dorr_appearance.platform_dark_tokens"

    static func loadUser() -> AppearanceDto? {
        UserDefaults.standard.data(forKey: userKey).flatMap { try? JSONDecoder().decode(AppearanceDto.self, from: $0) }
    }

    static func saveUser(_ dto: AppearanceDto) {
        UserDefaults.standard.set(try? JSONEncoder().encode(dto), forKey: userKey)
    }

    static func clearUser() { UserDefaults.standard.removeObject(forKey: userKey) }

    static func loadPlatformLight() -> [String: String]? { UserDefaults.standard.dictionary(forKey: lightKey) as? [String: String] }

    static func loadPlatformDark() -> [String: String]? { UserDefaults.standard.dictionary(forKey: darkKey) as? [String: String] }

    static func savePlatform(light: [String: String]?, dark: [String: String]?) {
        if let light { UserDefaults.standard.set(light, forKey: lightKey) }
        if let dark { UserDefaults.standard.set(dark, forKey: darkKey) }
    }
}

/// androidApp `NetworkMonitor.kt` — drives the app-wide no-internet screen.
/// The path monitor is the system's own push signal (no polling); API round-trips confirm it.
@MainActor
final class NetworkMonitor: ObservableObject {
    static let shared = NetworkMonitor()

    @Published private(set) var isOffline = false {
        didSet { if oldValue && !isOffline { reconnectTick += 1 } }
    }

    /// androidApp `collectReconnectTick()`: bumps each time the connection comes back, so a screen keyed
    /// on it reloads once (no timer, no polling).
    @Published private(set) var reconnectTick = 0
    /// androidApp `RefreshCoordinator.noticeArrived()`: a notice arrived while the app is open (a freelance push, say) —
    /// the screens on show reload once, as on a reconnect.
    func noticeArrived() { reconnectTick += 1 }
    /// A metered or constrained line (cellular, Low Data Mode) — photos go lighter.
    @Published private(set) var isExpensive = false

    private let monitor = NWPathMonitor()

    private init() {
        monitor.pathUpdateHandler = { path in
            Task { @MainActor in
                NetworkMonitor.shared.isOffline = path.status != .satisfied
                NetworkMonitor.shared.isExpensive = path.isExpensive || path.isConstrained
            }
        }
        monitor.start(queue: DispatchQueue(label: "dorr.network"))
    }

    /// androidApp `NetworkMonitor.refresh()` — the system's current answer, for the offline screen's "Try again".
    func refresh() -> Bool {
        let online = monitor.currentPath.status == .satisfied
        isOffline = !online
        return online
    }

    func reportReachable() { if isOffline { isOffline = false } }

    func reportUnreachable() { isOffline = true }
}

/// androidApp `network/WalletCountry.kt` — which of my wallets the wallet screens work on (one wallet per
/// country, docs/wallet-plan.md §7).
///
/// By default it's the wallet of where I am (the server's country for my sign-in). Someone with a
/// Saudi wallet who is in Egypt can switch to it: every wallet request then carries `X-Country: SA`,
/// so the Saudi wallet shows its own number / QR, receives, and sends to Saudi numbers and wallets
/// only — a wallet never pays another country's wallets. Kept on the phone across visits.
@MainActor
final class WalletCountry: ObservableObject {
    static let shared = WalletCountry()

    private static let selectedKey = "dorr_wallet_country.selected"
    private static let hereKey = "dorr_wallet_country.here"

    /// The wallet I chose (ISO code), or nil = the one of where I am.
    @Published private(set) var selected: String? = UserDefaults.standard.string(forKey: selectedKey)

    /// The country of where I am (the wallet the server opens without a choice) — learnt on the first look.
    @Published private(set) var here: String? = UserDefaults.standard.string(forKey: hereKey)

    /// The server opened its default wallet (no choice made): that's where I am.
    func learnHere(_ code: String?) {
        guard let code, !code.isEmpty, code.caseInsensitiveCompare(here ?? "") != .orderedSame else { return }
        here = code.uppercased()
        UserDefaults.standard.set(here, forKey: Self.hereKey)
    }

    func select(_ code: String?) {
        let upper = code?.uppercased()
        let value = upper == here?.uppercased() ? nil : upper
        selected = value
        UserDefaults.standard.set(value, forKey: Self.selectedKey)
    }

    /// Signing out forgets the choice (the next account starts where it is).
    func forget() {
        selected = nil
        here = nil
        UserDefaults.standard.removeObject(forKey: Self.selectedKey)
        UserDefaults.standard.removeObject(forKey: Self.hereKey)
    }

    /// For the HTTP client (any thread): the header value for a wallet request, if I chose another wallet. Watch & Earn
    /// works on the same wallet (rewards land in it, the ad wallet is funded from it), so it follows too.
    nonisolated static func headerFor(path: String) -> String? {
        guard !isInternational, path.contains("/v1/wallet") || path.contains("/v1/watch-earn") else { return nil }
        return UserDefaults.standard.string(forKey: selectedKey)
    }

    /// The freelance international wallet (dollars, one for the whole world) is on screen: every wallet request goes to
    /// `mobile/v1/freelance/wallet/…` instead of the local one ([rewrite]). Set by the freelance wallet launcher while it is open.
    nonisolated(unsafe) private static var internationalFlag = false
    private static let internationalLock = NSLock()

    nonisolated static var isInternational: Bool {
        internationalLock.lock(); defer { internationalLock.unlock() }
        return internationalFlag
    }

    nonisolated static func setInternational(_ on: Bool) {
        internationalLock.lock(); internationalFlag = on; internationalLock.unlock()
    }

    /// The path a wallet request really takes (relative to the API base): the international wallet's while it is on screen.
    nonisolated static func rewrite(path: String) -> String {
        guard isInternational, path.hasPrefix("mobile/v1/wallet") else { return path }
        return "mobile/v1/freelance/wallet" + path.dropFirst("mobile/v1/wallet".count)
    }
}

/// Minimal generic-password Keychain access for the session token.
enum Keychain {
    static func get(_ account: String) -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: account,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne,
        ]
        var result: AnyObject?
        guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess, let data = result as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }

    static func set(_ value: String?, for account: String) {
        let query: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrAccount as String: account]
        SecItemDelete(query as CFDictionary)
        guard let value, let data = value.data(using: .utf8) else { return }
        var attributes = query
        attributes[kSecValueData as String] = data
        attributes[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlock
        SecItemAdd(attributes as CFDictionary, nil)
    }
}

/// androidApp `network/CountryCache.kt` — the countries dropdown the login screen fetched, kept on the phone
/// so later screens (changing the phone number) know the dial code and the number's rules.
enum CountryCache {
    private static let key = "dorr_countries.dropdown"
    private static var memory: [CountryDto]?

    static func save(_ countries: [CountryDto]) {
        memory = countries
        UserDefaults.standard.set(try? JSONEncoder().encode(countries), forKey: key)
    }

    static func load() -> [CountryDto] {
        if let memory { return memory }
        let restored = UserDefaults.standard.data(forKey: key).flatMap { try? JSONDecoder().decode([CountryDto].self, from: $0) } ?? []
        memory = restored
        return restored
    }

    /// Default → SA → first, mirroring the login screen's own picking order.
    static func pickDefault() -> CountryDto? {
        let listed = load()
        return listed.first(where: \.isDefault) ?? listed.first { $0.code.caseInsensitiveCompare("sa") == .orderedSame } ?? listed.first
    }

    /// The country whose dial code the full phone number starts with (the longest match).
    static func resolveForPhone(_ fullPhone: String?) -> CountryDto? {
        let digits = (fullPhone ?? "").filter(\.isNumber)
        guard !digits.isEmpty else { return nil }
        return load()
            .filter { let dial = $0.dialCode.filter(\.isNumber); return !dial.isEmpty && digits.hasPrefix(dial) }
            .max { $0.dialCode.filter(\.isNumber).count < $1.dialCode.filter(\.isNumber).count }
    }
}
