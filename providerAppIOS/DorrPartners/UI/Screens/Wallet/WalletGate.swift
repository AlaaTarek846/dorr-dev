// providerApp `ui/screens/WalletGate.kt`
import SwiftUI

/// The wallet (FL-005), exactly as the DORR app's wallet gate (iosApp `WalletScreen.swift` → `WaGate`): no PIN yet → the recovery
/// method then the PIN (`WaPinSetupPage`), and the wallet opens straight after (the phone that made the PIN is trusted by the
/// server); with a PIN → the pad (or Face ID / Touch ID when it is on), "forgot your PIN?" (`WaForgotPinPage`), the reset PIN 0000
/// replaced first (`WaForcePinChangePage`), a phone the wallet has not seen proven by a code (`WaDeviceTrustPage`), a frozen
/// wallet's selfie + ID review (`WaFrozenPage`). Only then the wallet home (`WalletHomeHost`), with the PIN kept in `WalletSession`
/// for the money pages. Leaving the wallet drops the PIN from memory. Errors come as the wallet's toast at the top.
@MainActor
struct WalletRoute: View {
    let onBack: () -> Void
    @Environment(\.wa) private var wa
    @StateObject private var host = WalletHost()

    var body: some View {
        ZStack(alignment: .top) {
            wa.bg.ignoresSafeArea()
            WalletStages(onBack: onBack)
            WaToastHost(host: host)
        }
        .environment(\.walletHost, host)
    }
}

@MainActor
private struct WalletStages: View {
    let onBack: () -> Void
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var session = WalletSession.shared
    @State private var status: PinStatusDto?
    @State private var failed: String?
    @State private var attempt = 0
    @State private var forgot = false
    /// The PIN typed at the gate when it turned out to be the reset value 0000: it has to be replaced first.
    @State private var resetPin: String?
    /// The PIN that verified fine but came from a device this wallet has never opened from before — kept only long
    /// enough to resend it as X-Wallet-Pin while proving the phone (wallet policy bend 3).
    @State private var untrustedDevicePin: String?
    /// The PIN just created on this phone: the wallet opens with it (not asked for a third time).
    @State private var createdPin: String?
    @State private var autoPrompted = false

    var body: some View {
        content
            // One read when the wallet opens (and on a retry, and when the connection comes back) — nothing on a timer.
            .task(id: "\(attempt)-\(network.reconnectTick)") { await load() }
            // Leaving the wallet forgets the PIN.
            .onDisappear { WalletSession.shared.lock() }
    }

    @ViewBuilder private var content: some View {
        if failed == nil, let current = status, current.isFrozen == true {
            WaFrozenPage(status: current, onExit: onBack, onLifted: { attempt += 1 })
        } else if session.pin != nil {
            WalletHomeHost(onBack: onBack)
        } else if failed == nil, let current = status, current.hasPin != true {
            WaPinSetupPage(
                onExit: onBack,
                onDone: {
                    // Straight into the wallet, like the DORR app: the PIN was just typed twice, and this phone is trusted.
                    var opened = current
                    opened.hasPin = true
                    status = opened
                    if let createdPin { WalletSession.shared.unlock(createdPin) }
                },
                title: L("wa_gate_create_title"),
                showSaved: false,
                onCreated: { createdPin = $0 }
            )
        } else if let resetPin {
            WaForcePinChangePage(current: resetPin, onExit: onBack, onDone: { self.resetPin = nil }, onChanged: { fresh in
                // Face ID holds the old PIN: it is turned off, and turned on again with the new one.
                if WalletBiometric.isEnabled() { WalletBiometric.disable() }
                WalletSession.shared.unlock(fresh)
            })
        } else if let untrustedDevicePin {
            WaDeviceTrustPage(pin: untrustedDevicePin, onExit: onBack, onDone: {
                self.untrustedDevicePin = nil
                WalletSession.shared.unlock(untrustedDevicePin)
            })
        } else if forgot {
            WaForgotPinPage(status: status, onExit: { forgot = false }, onDone: { forgot = false; attempt += 1 })
        } else {
            WaPage(title: L("wa_my_wallet"), onBack: onBack, scroll: false) {
                if let failed {
                    WaEmpty(icon: "exclamationmark.triangle.fill", title: L("wa_load_failed"), text: failed) {
                        WaButton(text: L("wa_retry"), style: .ghost, icon: "arrow.clockwise") { attempt += 1 }.padding(.horizontal, 60)
                    }
                    .frame(maxHeight: .infinity)
                } else if let current = status {
                    pad(current)
                } else {
                    WaSkeleton(cornerRadius: 24).frame(height: 300).padding(24).frame(maxHeight: .infinity)
                }
            }
        }
    }

    private var biometric: Bool { WalletBiometric.isEnabled() && WalletBiometric.isAvailable() }

    private func pad(_ current: PinStatusDto) -> some View {
        let lockedUntil = parseInstant(current.lockedUntil)
        return VStack(spacing: 0) {
            WaPinPad(title: L("wa_gate_enter_title"), sub: L("wa_gate_enter_sub"), lockedUntil: lockedUntil) { pin in
                await completeWithPin(pin)
            }
            .frame(maxHeight: .infinity)
            .waRise(0)
            if biometric {
                WaButton(text: L("wa_biometric_unlock_button"), style: .ghost, icon: "faceid", action: unlockWithBiometric)
            }
            WaButton(text: L("wa_forgot_link"), style: .quiet) { forgot = true }.padding(.bottom, 6)
        }
        .padding(.horizontal, 8)
        .onAppear {
            // Opening the wallet shows the prompt at once (once per visit; a cancel leaves the PIN pad and the button).
            let lockedNow = (lockedUntil ?? .distantPast) > Date()
            if !autoPrompted && !lockedNow && biometric {
                autoPrompted = true
                unlockWithBiometric()
            }
        }
    }

    private func load() async {
        failed = nil
        let result = await WalletApi.pinStatus()
        switch result {
        case .ok(let data, _): status = data
        // Offline is covered by the app-wide screen and a reconnect reloads (skeleton meanwhile); a real server answer gets
        // the retry card.
        case .failed: if status == nil { failed = result.errorText(L("wa_error_network")) ?? L("wa_error_generic") }
        case .offline: break
        }
    }

    private func completeWithPin(_ pin: String) async -> PadResult {
        let result = await WalletApi.verifyPin(pin)
        guard let verified = result.value else {
            // Froze between loading this screen and typing the PIN: reload so the frozen branch takes over.
            if result.error?.errorCode == "wallet_pin_frozen" {
                attempt += 1
                return .reset
            }
            // A temporary lock: the countdown on the pad, never asking the server during the wait.
            return padFailure(result, L("wa_error_network"), L("wa_error_generic"))
        }
        if verified.mustChange == true {
            resetPin = pin
        } else if verified.deviceTrusted == false {
            untrustedDevicePin = pin
        } else {
            WalletSession.shared.unlock(pin)
        }
        return .ok
    }

    /// The stored PIN, opened by Face ID / Touch ID. A PIN that no longer matches the server's is dropped,
    /// rather than failing silently on every future attempt.
    private func unlockWithBiometric() {
        WalletBiometric.unlock { pin in
            guard let pin else { return }
            Task { @MainActor in
                if case .error = await completeWithPin(pin) { WalletBiometric.disable() }
            }
        }
    }
}

/// A failed call as the pad reports it: a temporary lock becomes the pad's countdown, anything else its message.
func padFailure<T>(_ result: ApiResult<T>, _ offline: String, _ generic: String) -> PadResult {
    if case .failed(let error) = result, error.errorCode == "wallet_pin_locked",
       let until = parseInstant((error.data as? [String: Any])?["locked_until"] as? String) {
        return .locked(until)
    }
    return .error(result.errorText(offline) ?? generic)
}
