import SwiftUI

/// androidApp `WalletHost.kt` — what the wallet's own screen stack can show (the preview's `push(buildX)` pages).
enum WaRoute {
    case home
    case topup
    case transfer
    case transferConfirm(TransferRecipientDto)
    case myQr
    case scanner
    case history
    case pinSettings
    /// The payment screen — its state rides in the route so a top-up and back keeps it.
    case checkout(CheckoutState)
    /// Top up a given amount (what's missing on the payment screen).
    case topupAmount(Int)
}

/// The PIN prompt for a protected action, shown as the pad inside a bottom sheet.
struct PinSheet {
    let subtitle: String
    let onSubmit: (String) async -> PinOutcome
    let onClose: (String) -> Void
    /// A temporary PIN lock already running when the sheet opens — shows the countdown.
    let lockedUntil: Date?
}

/// Bottom sheets that float over any wallet page.
enum WaSheet {
    case explain
    case tx(WalletTransactionDto)
    case pin(PinSheet)
}

/// What the PIN pad should do after the protected action ran.
enum PinOutcome {
    /// Action succeeded — close the sheet.
    case done
    /// PIN problem (wrong/locked) — stay open and show the message so the user can retry.
    case retry(String)
    /// Any other failure — close the sheet; the caller shows the message on its own page.
    case close(String)
}

/// PIN-related failures the pad keeps open for; everything else is the caller's to show.
let pinErrorCodes: Set<String> = ["wallet_pin_invalid", "wallet_pin_locked", "wallet_pin_required", "wallet_pin_not_set"]

/// State shared by every wallet page: the balance, the page stack, the open sheet and the toast.
/// One instance lives for one visit to the wallet (leaving it re-locks it), like the preview's
/// module-level `state`/`stack`.
@MainActor
final class WalletHost: ObservableObject {
    @Published var balance: WalletBalanceDto?
    @Published var hideBalance = false
    @Published var sheet: WaSheet?
    @Published var toast: String?
    /// True when the toast on screen reports a problem (validation, a refused request) rather than a success.
    @Published var toastError = false
    @Published private(set) var stack: [WaRoute]
    /// True when the last move was a push, so pages slide in from the trailing side and back the other way.
    @Published private(set) var forward = true

    /// The total each hero card last counted up to (per wallet key), so the next count starts from there.
    var shownTotals: [String: Int] = [:]
    /// Last balance seen per wallet (a country code, or "INT" for the dollar wallet): a card I swipe to shows its numbers at once.
    @Published var balances: [String: WalletBalanceDto] = [:]
    /// My country wallets, the one of where I am first — learnt from the balance of any of them (the dollar wallet doesn't list them).
    @Published var localCodes: [String] = []
    /// Whether a separate international (dollar) wallet exists for me — not when my local currency is the freelance one.
    @Published var intlWallet = true
    /// A wallet chosen in «My wallets» (a country code, or "INT"): the wallet home switches to it and clears this —
    /// the dollar wallet's on/off lives in WalletHome's own state, which the sheet can't reach.
    @Published var pickKey: String?
    var onExit: () -> Void

    private var toastTask: Task<Void, Never>?

    init(start: WaRoute = .home, onExit: @escaping () -> Void) {
        stack = [start]
        self.onExit = onExit
    }

    var current: WaRoute { stack[stack.count - 1] }

    func push(_ route: WaRoute) {
        forward = true
        withAnimation(.easeOut(duration: 0.34)) { stack.append(route) }
    }

    func pop() {
        if sheet != nil {
            closeSheet()
            return
        }
        if stack.count <= 1 {
            onExit()
            return
        }
        forward = false
        withAnimation(.easeOut(duration: 0.3)) { _ = stack.removeLast() }
    }

    func showToast(_ message: String, error: Bool = false) {
        withAnimation(.easeOut(duration: 0.3)) {
            toast = message
            toastError = error
        }
        toastTask?.cancel()
        toastTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: error ? 3_200_000_000 : 2_200_000_000)
            guard !Task.isCancelled else { return }
            withAnimation(.easeIn(duration: 0.25)) { self?.toast = nil }
        }
    }

    /// A validation or request problem, shown as a notification at the top instead of text inside the page.
    func showError(_ message: String) { showToast(message, error: true) }

    func openSheet(_ value: WaSheet) {
        withAnimation(.easeOut(duration: 0.38)) { sheet = value }
    }

    func closeSheet() {
        withAnimation(.easeIn(duration: 0.28)) { sheet = nil }
    }

    /// Balance + wallet number for the request's country; the wallet is created on first look.
    @discardableResult
    func refreshBalance() async -> Bool {
        guard let fresh = try? await WalletApi.balance().data else { return false }
        let code = fresh.countryCode?.uppercased() ?? ""
        let intl = code == "INT"
        // No wallet chosen: the server opened the one of where I am.
        if !intl, WalletCountry.shared.selected == nil { WalletCountry.shared.learnHere(fresh.countryCode) }
        if !code.isEmpty { balances[code] = fresh }
        if !intl {
            intlWallet = fresh.internationalWallet
            let here = WalletCountry.shared.here?.uppercased()
            let all = ([code] + (fresh.otherWallets ?? []).compactMap { $0.countryCode?.uppercased() }).filter { !$0.isEmpty }
            var seen = Set<String>()
            localCodes = all.filter { seen.insert($0).inserted }.sorted { ($0 == here ? 0 : 1) < ($1 == here ? 0 : 1) }
        }
        // A slow answer for the wallet I just swiped away from must not replace the one on screen.
        // (No separate dollar wallet: the dollar flows read my local one, whichever side is asked for.)
        if intl == WalletCountry.isInternational || !fresh.internationalWallet { balance = fresh }
        return true
    }

    /// Opens the PIN sheet for a protected action. Asks the server first whether a PIN exists so a
    /// first-time user is sent to create one instead of getting a "wrong PIN" error.
    func requestPin(
        subtitle: String,
        onError: @escaping (String) -> Void,
        onSubmit: @escaping (String) async -> PinOutcome,
        onClose: ((String) -> Void)? = nil
    ) {
        Task {
            let status: PinStatusDto?
            do {
                status = try await WalletApi.pinStatus().data
            } catch {
                onError(error.apiFailure.message ?? "")
                return
            }
            // No PIN yet (the gate normally guarantees one): the PIN page walks through recovery method + PIN.
            if status?.hasPin == false {
                push(.pinSettings)
                return
            }
            openSheet(.pin(PinSheet(subtitle: subtitle, onSubmit: onSubmit, onClose: onClose ?? onError, lockedUntil: parseInstant(status?.lockedUntil))))
        }
    }
}

/// Something another part of the app wants the wallet to do once it's unlocked — today: a wallet QR
/// tapped in a chat ("Send money"), which goes straight to the transfer confirmation, exactly as if it
/// had been scanned. Consumed once.
enum WalletDeepLink {
    private static var pendingQr: String?

    static func openQr(_ payload: String) { pendingQr = payload }

    static func consumeQr() -> String? {
        defer { pendingQr = nil }
        return pendingQr
    }
}
