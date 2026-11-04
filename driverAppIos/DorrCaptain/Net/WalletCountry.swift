import Foundation

// driverApp `wallet/net/WalletCountry.kt` (the DORR app's, without Watch & Earn).

/// Which of my wallets the wallet screens work on (one wallet per country, docs/wallet-plan.md §7).
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

    /// `load(context)`: the saved choice is read when this object is first used (UserDefaults, above).
    func load() {}

    /// The server opened its default wallet (no choice made): that's where I am.
    func learnHere(_ code: String?) {
        guard let code, !code.trimmingCharacters(in: .whitespaces).isEmpty, code.caseInsensitiveCompare(here ?? "") != .orderedSame else { return }
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

    /// For the HTTP client (any thread): the header value for a wallet request, if I chose another wallet.
    nonisolated static func headerFor(path: String) -> String? {
        guard path.contains("/v1/wallet") else { return nil }
        return UserDefaults.standard.string(forKey: selectedKey)
    }
}
