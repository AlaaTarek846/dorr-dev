import Foundation
import Network
import Security

// providerApp `net/Session.kt`, `net/AppLocale.kt`, `net/NetworkMonitor.kt`, `net/PagedRows.kt`
// and driverApp `core/session/TokenStore.kt`.

/// The provider's session: the `provider_api` token and this install's device id, in their own store (never the DORR app's,
/// the driver app's or the freelancer app's, even on the same phone). `signedIn` is what the screens follow — a 401 on a
/// request that carried the token ends the session, and the navigation goes back to sign-in by itself.
///
/// It also keeps **the establishment the app works in** (`establishment`, its uuid): one person may hold seats in several
/// establishments (docs/moving_business.md §14), and every request carries the chosen one as `X-Establishment` (Api). The
/// choice is kept on the phone so the app opens on the same establishment next time.
@MainActor
final class Session: ObservableObject {
    static let shared = Session()

    private let tokenAccount = "dorr_provider_session.token"
    private let establishmentKey = "dorr_provider_workspace.establishment_uuid"

    @Published private(set) var signedIn: Bool
    /// The person as the server last described them (`me`, or the sign-in's answer); in memory only.
    @Published var me: ProviderDto?
    /// The uuid of the establishment the app works in, sent as `X-Establishment`; nil before one is chosen.
    @Published private(set) var establishment: String?
    /// My seat in that establishment (`GET establishment` → membership): its permissions decide which parts show (§17).
    @Published private(set) var membership: MembershipDto?

    private(set) var token: String?

    private init() {
        token = Keychain.get(tokenAccount)
        signedIn = !(token ?? "").isEmpty
        establishment = UserDefaults.standard.string(forKey: establishmentKey)
    }

    func setMe(_ provider: ProviderDto?) { me = provider }

    /// Works in `uuid` from now on (kept on the phone); the seat is read again for it.
    func chooseEstablishment(_ uuid: String?) {
        if establishment != uuid { membership = nil }
        establishment = uuid
        if let uuid { UserDefaults.standard.set(uuid, forKey: establishmentKey) } else { UserDefaults.standard.removeObject(forKey: establishmentKey) }
    }

    func setMembership(_ membership: MembershipDto?) { self.membership = membership }

    /// Whether my seat holds `permission` (the owner holds them all; the server checks every one again).
    func can(_ permission: String) -> Bool { membership.can(permission) }

    func signIn(_ token: String) {
        self.token = token
        Keychain.set(token, for: tokenAccount)
        signedIn = true
    }

    func clear() {
        token = nil
        Keychain.set(nil, for: tokenAccount)
        me = nil
        membership = nil
        chooseEstablishment(nil)
        Workspace.shared.clear()
        signedIn = false
    }
}

/// A random id per app install, sent as `X-Device-Id`. Not a hardware id.
enum DeviceId {
    private static let key = "dorr_provider_session.device_id"

    static let current: String = {
        if let existing = UserDefaults.standard.string(forKey: key) { return existing }
        let id = UUID().uuidString.lowercased()
        UserDefaults.standard.set(id, forKey: key)
        return id
    }()
}

/// The app language sent as `X-Locale` (the server speaks ar / en; a downloaded language asks in English).
enum AppLocale {
    private static let key = "dorr_provider_locale"

    static var current: String {
        get { UserDefaults.standard.string(forKey: key) ?? "ar" }
        set { UserDefaults.standard.set(newValue, forKey: key) }
    }

    static var isArabic: Bool { current == "ar" }

    /// Locale for dates and numbers — the app language, not the phone's.
    static var locale: Locale { Locale(identifier: current) }
}

/// Drives the app-wide no-internet screen. The path monitor is the system's own push signal (no polling); API round-trips
/// confirm it. `reconnectTick` bumps each time the connection comes back, so a screen keyed on it reloads once.
@MainActor
final class NetworkMonitor: ObservableObject {
    static let shared = NetworkMonitor()

    @Published private(set) var isOnline = true {
        didSet { if isOnline && !oldValue { reconnectTick += 1 } }
    }

    @Published private(set) var reconnectTick = 0

    /// A notice arrived while the app is open: the screens on show reload once, as on a reconnect.
    func noticeArrived() { reconnectTick += 1 }

    private let monitor = NWPathMonitor()

    private init() {
        monitor.pathUpdateHandler = { path in
            Task { @MainActor in NetworkMonitor.shared.isOnline = path.status == .satisfied }
        }
        monitor.start(queue: DispatchQueue(label: "dorr.partners.network"))
    }

    func refresh() -> Bool {
        let online = monitor.currentPath.status == .satisfied
        isOnline = online
        return online
    }

    func reportReachable() { if !isOnline { isOnline = true } }

    func reportUnreachable() { isOnline = false }
}

/// A list that fills itself page by page while the person scrolls (nothing on a timer): `reload` reads the first page
/// and replaces what is shown, `loadMore` adds the next page under it. One request at a time; a row that moved between pages
/// is not shown twice. A page shorter than `perPage` is the last one.
@MainActor
final class PagedRows<T>: ObservableObject {
    @Published var rows: [T] = []
    /// The first page has been answered (rows or an error) — before that the screen shows its spinner.
    @Published private(set) var loaded = false
    @Published private(set) var loading = false
    @Published private(set) var hasMore = true
    /// The last page that failed, for the screen to turn into text; cleared by the next success.
    @Published private(set) var failure: ApiResult<[T]>?

    private let perPage: Int
    private let key: (T) -> AnyHashable
    private var fetch: (Int) async -> ApiResult<[T]>
    private var page = 0
    private var generation = 0

    init(perPage: Int = 15, key: @escaping (T) -> AnyHashable, fetch: @escaping (Int) async -> ApiResult<[T]> = { _ in .offline }) {
        self.perPage = perPage
        self.key = key
        self.fetch = fetch
    }

    /// Another list (a new filter): it starts again from its spinner.
    func reset(_ fetch: @escaping (Int) async -> ApiResult<[T]>) {
        generation += 1
        self.fetch = fetch
        rows = []
        loaded = false
        loading = false
        hasMore = true
        failure = nil
        page = 0
    }

    func reload() async {
        generation += 1
        loading = false
        await load(1)
    }

    func loadMore() async {
        if loaded && hasMore && failure == nil { await load(page + 1) }
    }

    /// After a failed page, the next scroll tries it again.
    func retry() { failure = nil }

    /// The row at `index` came on screen: the next page comes when the last few rows show.
    func appeared(_ index: Int) {
        if index >= rows.count - 3 { Task { await loadMore() } }
    }

    /// Changes one row where it is (an answer to an invitation) without reading anything again.
    func update(where match: (T) -> Bool, _ change: (T) -> T) {
        if let i = rows.firstIndex(where: match) { rows[i] = change(rows[i]) }
    }

    func remove(where match: (T) -> Bool) { rows.removeAll(where: match) }

    private func load(_ next: Int) async {
        guard !loading else { return }
        let gen = generation
        loading = true
        let result = await fetch(next)
        guard gen == generation else { return }
        switch result {
        case .ok(let data, _):
            failure = nil
            if next == 1 { rows = [] }
            let seen = Set(rows.map(key))
            rows += data.filter { !seen.contains(key($0)) }
            page = next
            hasMore = data.count >= perPage
        default:
            failure = result
        }
        loaded = true
        loading = false
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
