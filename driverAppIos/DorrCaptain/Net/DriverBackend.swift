import Foundation
import Security

/// driverApp `net/DriverBackend.kt` + `core/session/TokenStore.kt` — the driver app's single connection to the server: its
/// own session (`driver_session`, never the DORR app's, in the Keychain) and a signed-out signal the navigation listens to
/// when a token stops working.
@MainActor
final class DriverBackend: ObservableObject {
    static let shared = DriverBackend()

    private static let tokenAccount = "driver_session.token"
    private static let deviceKey = "driver_session.device_id"

    /// Ticks each time the server answers 401 to a signed-in call.
    @Published private(set) var signedOut = 0
    @Published private(set) var isSignedIn: Bool

    private init() {
        isSignedIn = !(Keychain.get(Self.tokenAccount) ?? "").isEmpty
    }

    /// The Bearer token (read by the HTTP layer on any thread).
    nonisolated static var token: String? { Keychain.get(tokenAccount) }

    /// Stable per install; sent as X-Device-Id.
    nonisolated static let deviceId: String = {
        if let existing = UserDefaults.standard.string(forKey: deviceKey) { return existing }
        let id = UUID().uuidString.lowercased()
        UserDefaults.standard.set(id, forKey: deviceKey)
        return id
    }()

    func signIn(_ token: String) {
        Keychain.set(token, for: Self.tokenAccount)
        isSignedIn = true
        Self.shareSignedIn(true)
    }

    func clear() {
        Keychain.set(nil, for: Self.tokenAccount)
        isSignedIn = false
        Self.shareSignedIn(false)
    }

    /// The notification extension reads it from the app group (Android's `DriverBackend.session.isSignedIn` in the extension).
    static func shareSignedIn(_ on: Bool) {
        UserDefaults(suiteName: "group.com.dorr.driver")?.set(on, forKey: "signed_in")
    }

    /// The server answered 401 to a call that carried the token: stop the live connection, forget the token, tell the
    /// navigation (it goes back to sign-in).
    func unauthorized() {
        DriverLive.shared.stop()
        clear()
        signedOut += 1
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
