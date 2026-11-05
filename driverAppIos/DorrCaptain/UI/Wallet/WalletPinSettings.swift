import LocalAuthentication
import SwiftUI

// driverApp `wallet/WalletPinSettings.kt` (the DORR app's, its switch from compat `RedToggle`; ported from iosApp
// `UI/Wallet/WalletPinSettings.swift`).

/// The three pages behind the wallet PIN entry, plus the menu that lists them (androidApp `WalletPinSettings.kt`).
private enum PinSub { case menu, changePin, recovery, biometric }

/// Security settings of the wallet. Opening "PIN" from the wallet shows a menu of three pages, like the app's
/// own settings: the wallet PIN (change it), the recovery method, and unlocking the wallet with Face ID /
/// Touch ID. The very first PIN (no PIN yet) and a frozen wallet still take over the whole screen, because
/// nothing else makes sense before they are sorted out.
@MainActor
struct WalletPinSettings: View {
    @EnvironmentObject private var host: WalletHost
    @ObservedObject private var network = WalletReconnect.shared

    @State private var status: PinStatusDto?
    @State private var forgot = false
    @State private var sub = PinSub.menu
    @State private var failed: String?
    @State private var attempt = 0

    var body: some View {
        content
            .task(id: "\(attempt)-\(network.reconnectTick)") { await load() }
    }

    @ViewBuilder
    private var content: some View {
        if let loaded = status, loaded.isFrozen, failed == nil {
            WaFrozenPage(status: loaded, onExit: { host.pop() }, onLifted: { attempt += 1 })
        } else if let loaded = status, !loaded.hasPin, failed == nil {
            // First PIN: choose how it can be recovered, then create it.
            WaPinSetupPage(onExit: { host.pop() }, onDone: { host.pop() })
        } else if forgot {
            WaForgotPinPage(status: status, onExit: { forgot = false }, onDone: { forgot = false; attempt += 1 })
        } else if sub == .recovery {
            WaChangeRecoveryPage(onExit: { sub = .menu }, onDone: { sub = .menu; attempt += 1 })
        } else if let failed {
            WaPage(title: L("wa_security_title"), onBack: { host.pop() }, scroll: false) {
                WaEmpty(icon: "exclamationmark.triangle.fill", title: L("wa_load_failed"), text: failed) {
                    WaButton(text: L("wa_retry"), style: .ghost, icon: "arrow.clockwise") { attempt += 1 }.padding(.horizontal, 60)
                }
                .frame(maxHeight: .infinity)
            }
        } else if status == nil {
            WaPage(title: L("wa_security_title"), onBack: { host.pop() }, scroll: false) {
                WaSkeleton(cornerRadius: 24).frame(height: 300).padding(24).frame(maxHeight: .infinity)
            }
        } else if sub == .changePin {
            WaChangePinPage(onBack: { sub = .menu }, onForgot: { forgot = true })
        } else if sub == .biometric {
            WaBiometricPage(onBack: { sub = .menu })
        } else {
            WaSecurityMenu(onBack: { host.pop() }, onOpen: { sub = $0 })
        }
    }

    private func load() async {
        failed = nil
        status = nil
        do {
            let loaded = try await WalletApi.pinStatus().data
            status = loaded
            if loaded == nil { failed = L("wa_error_network") }
        } catch {
            let failure = error.walletFailure
            // Offline is covered by the app-wide screen and a reconnect reloads (skeleton meanwhile);
            // a real server answer gets the retry card.
            if failure.httpStatus != nil { failed = failure.message ?? L("wa_error_network") }
        }
    }
}

// MARK: - The menu

private struct WaSecurityMenu: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let onOpen: (PinSub) -> Void

    var body: some View {
        WaPage(title: L("wa_security_title"), onBack: onBack) {
            // A short reassurance strip: why this page exists.
            HStack(spacing: 14) {
                WaIconWell(icon: "shield.fill", size: 52, iconSize: 26)
                Text(L("wa_security_intro")).font(.dorr(12, .medium)).foregroundStyle(wa.ink).lineSpacing(3)
                Spacer(minLength: 0)
            }
            .padding(16)
            .background(wa.red.opacity(0.10), in: RoundedRectangle(cornerRadius: 22, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(wa.red.opacity(0.25), lineWidth: 1))
            .padding(.top, 4)
            .padding(.bottom, 6)
            .waRise(0)

            Spacer().frame(height: 10)
            WaSecurityRow(icon: "lock.fill", title: L("wa_pin_menu_pin_title"), subtitle: L("wa_pin_menu_pin_sub"), index: 1, state: nil) { onOpen(.changePin) }
            WaSecurityRow(icon: "key.fill", title: L("wa_rec_change_link"), subtitle: L("wa_pin_menu_recovery_sub"), index: 2, state: nil) { onOpen(.recovery) }
            // Only "not available" is said (it explains why the row does nothing); on and off are the switch's to show.
            WaSecurityRow(icon: biometricIcon, title: L("wa_biometric_menu_title"), subtitle: L("wa_biometric_menu_sub"), index: 3,
                          state: WaBiometric.isAvailable ? nil : L("wa_not_available")) { onOpen(.biometric) }
        }
    }
}

/// Face ID or Touch ID, as the phone has it.
var biometricIcon: String {
    let context = LAContextBox.context
    return context.biometryType == .faceID ? "faceid" : "touchid"
}

private enum LAContextBox {
    static var context: LAContext {
        let context = LAContext()
        var error: NSError?
        _ = context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &error)
        return context
    }
}

/// One entry of the security menu: brand-tinted icon, title + one line of help, optional state, chevron.
private struct WaSecurityRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let subtitle: String
    let index: Int
    let state: String?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 14) {
                WaIconWell(icon: icon, size: 48, iconSize: 24)
                VStack(alignment: .leading, spacing: 2) {
                    Text(title).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink)
                    Text(subtitle).font(.dorr(11)).foregroundStyle(wa.mut).lineSpacing(2).multilineTextAlignment(.leading)
                }
                Spacer(minLength: 0)
                if let state {
                    Text(state).font(.dorr(11, .bold)).foregroundStyle(wa.red)
                        .padding(.horizontal, 10).padding(.vertical, 4)
                        .background(wa.red.opacity(0.12), in: Capsule())
                }
                Image(systemName: "chevron.forward").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.soft)
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 16)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(wa.red.opacity(0.3), lineWidth: 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(WaPressStyle(scale: 0.97))
        .padding(.vertical, 6)
        .waRise(index)
    }
}

// MARK: - 1. The wallet PIN

/// Change the PIN: current → new → confirm. A wrong current PIN sends the person back to the first step.
/// Ends on a "saved" seal. "Forgot your PIN?" on the first step opens the recovery flow.
@MainActor
private struct WaChangePinPage: View {
    let onBack: () -> Void
    let onForgot: () -> Void
    @State private var step = "current"
    @State private var current = ""
    @State private var fresh = ""
    @State private var saved = false

    var body: some View {
        WaPage(title: L("wa_pin_menu_pin_title"), onBack: onBack, scroll: false) {
            if saved {
                WaStatusColumn {
                    WaSeal()
                    WaStatusTitle(text: L("wa_pin_saved_title"))
                    WaStatusText(text: L("wa_pin_saved_text"))
                    WaButton(text: L("wa_done"), action: onBack).padding(.horizontal, 20).padding(.top, 12)
                }
            } else {
                VStack(spacing: 0) {
                    let texts: (String, String) = {
                        switch step {
                        case "current": return (L("wa_pin_current_title"), L("wa_pin_current_sub"))
                        case "new": return (L("wa_pin_new_title"), L("wa_pin_digits_sub"))
                        default: return (L("wa_pin_confirm_title"), L("wa_pin_confirm_sub"))
                        }
                    }()
                    WaPinPad(title: texts.0, sub: texts.1, icon: "shield.fill") { pin in await submit(pin) }
                        .frame(maxHeight: .infinity)
                        .waRise(0)
                    if step == "current" {
                        WaButton(text: L("wa_forgot_link"), style: .quiet, action: onForgot)
                    }
                }
                .padding(.horizontal, 8)
            }
        }
    }

    private func submit(_ pin: String) async -> PadResult {
        switch step {
        case "current":
            current = pin
            step = "new"
            return .reset
        case "new":
            fresh = pin
            step = "confirm"
            return .reset
        default:
            if pin != fresh {
                step = "new"
                return .error(L("wa_pin_mismatch"))
            }
            do {
                _ = try await WalletApi.changePin(ChangePinRequest(currentPin: current, pin: fresh, pinConfirmation: pin))
            } catch {
                // A wrong current PIN sends the person back to the start; anything else retries.
                step = "current"
                return padFailure(error, networkError: L("wa_error_network"))
            }
            saved = true
            return .ok
        }
    }
}

// MARK: - 3. Face ID / Touch ID

/// Turning the biometric unlock on or off. Turning it on needs the PIN once (the PIN pad), then the system
/// prompt; turning it off needs nothing — it is only a local convenience, never a way to pay by itself.
@MainActor
private struct WaBiometricPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var host: WalletHost
    let onBack: () -> Void
    @State private var enabled = WaBiometric.isEnabled
    @State private var verifying = false

    var body: some View {
        WaPage(title: L("wa_biometric_menu_title"), onBack: { if verifying { verifying = false } else { onBack() } }, scroll: !verifying) {
            if verifying {
                WaPinPad(title: L("wa_pin_enter_title"), sub: L("wa_biometric_toggle"), icon: biometricIcon) { pin in
                    do {
                        _ = try await WalletApi.verifyPin(pin)
                    } catch {
                        return padFailure(error, networkError: L("wa_error_network"))
                    }
                    verifying = false
                    WaBiometric.enable(pin: pin) { ok in
                        enabled = ok
                        if !ok { host.showToast(L("wa_biometric_setup_failed")) }
                    }
                    return .ok
                }
                .frame(maxHeight: .infinity)
                .waRise(0)
            } else {
                VStack(spacing: 0) {
                    WaIconWell(icon: biometricIcon, size: 96, iconSize: 48).waRise(0)
                    Text(L("wa_biometric_menu_title")).font(.dorr(20, .heavy)).foregroundStyle(wa.ink).multilineTextAlignment(.center)
                        .padding(.top, 18).waRise(1)
                    Text(L("wa_biometric_toggle_hint")).font(.dorr(13)).foregroundStyle(wa.mut).multilineTextAlignment(.center).lineSpacing(6)
                        .padding(.top, 10).padding(.horizontal, 8).waRise(2)
                }
                .frame(maxWidth: .infinity)
                .padding(.top, 8)
                Spacer().frame(height: 24)
                if WaBiometric.isAvailable {
                    WaCard(padding: 16) {
                        HStack(spacing: 12) {
                            WaIconWell(icon: biometricIcon, size: 44, iconSize: 22)
                            Text(L("wa_biometric_toggle")).font(.dorr(14, .heavy)).foregroundStyle(wa.ink)
                            Spacer(minLength: 0)
                            // The same toggle as in Account → Settings → Notifications.
                            RedToggle(on: enabled) {
                                if !enabled {
                                    verifying = true
                                } else {
                                    WaBiometric.disable()
                                    enabled = false
                                }
                            }
                        }
                    }
                    .waRise(3)
                } else {
                    WaNote(text: L("wa_biometric_unavailable")).waRise(3)
                }
            }
        }
    }
}

/// driverApp compat `RedToggle` (the DORR app's `profile/RedToggle`) — 42×24 track in the brand colour, white knob.
struct RedToggle: View {
    @Environment(\.wa) private var wa
    let on: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            ZStack(alignment: on ? .trailing : .leading) {
                RoundedRectangle(cornerRadius: 12, style: .continuous)
                    .fill(on ? wa.red : (wa.night ? Color(hex: 0x3A3F48) : Color(hex: 0xE5E7EB)))
                    .frame(width: 42, height: 24)
                Circle().fill(.white).frame(width: 20, height: 20).shadow(color: .black.opacity(0.2), radius: 1, y: 0.5).padding(2)
            }
            .animation(.easeOut(duration: 0.18), value: on)
            .environment(\.layoutDirection, .leftToRight)
        }
        .buttonStyle(.plain)
    }
}
