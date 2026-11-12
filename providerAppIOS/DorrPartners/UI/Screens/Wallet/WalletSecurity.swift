// providerApp `ui/screens/WalletSecurity.kt`
import SwiftUI

/// The pages behind the wallet's settings: the menu and what it opens.
private enum SecuritySub { case menu, changePin, recovery, biometric }

/// The wallet's security settings, as in the DORR app: a menu of three pages — the wallet PIN (change it, with "forgot your PIN?"
/// on its first step), the recovery method, and unlocking with Face ID / Touch ID. A sub page's back returns to the menu.
/// The wallet status is read once when this opens (and again after a recovery).
@MainActor
struct WalletSecurity: View {
    let onBack: () -> Void
    @State private var sub = SecuritySub.menu
    @State private var forgot = false
    @State private var status: PinStatusDto?
    /// Read once when this opens, and again after the recovery method changed.
    @State private var refresh = 0

    var body: some View {
        content.task(id: refresh) {
            if let data = await WalletApi.pinStatus().value { status = data }
        }
    }

    @ViewBuilder private var content: some View {
        if forgot {
            // The DORR app's "forgot your PIN?" page. A recovered PIN is a new one: the wallet locks and asks for it again
            // (the stale one is dropped).
            WaForgotPinPage(status: status, onExit: { forgot = false }, onDone: {
                forgot = false
                WalletSession.shared.lock()
            })
        } else {
            switch sub {
            case .changePin: WlChangePinPage(onBack: { sub = .menu }, onForgot: { forgot = true })
            // The DORR app's page: the current PIN, then the same method wizard as at setup (ends after the method).
            case .recovery: WaChangeRecoveryPage(onExit: { sub = .menu }, onDone: { sub = .menu; refresh += 1 })
            case .biometric: WlBiometricPage(onBack: { sub = .menu })
            case .menu: WlSecurityMenu(onBack: onBack, onOpen: { sub = $0 })
            }
        }
    }
}

// MARK: - the menu

@MainActor
private struct WlSecurityMenu: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let onOpen: (SecuritySub) -> Void
    @State private var biometricAvailable = WalletBiometric.isAvailable()

    var body: some View {
        WaPage(title: L("wa_security_title"), onBack: onBack) {
            // A short reassurance strip: why this page exists.
            HStack(spacing: 14) {
                WaIconWell(icon: "checkmark.shield.fill", tone: .red, size: 52, iconSize: 26)
                Text(L("wa_security_intro")).font(.dorr(12, .medium)).foregroundStyle(wa.ink).lineSpacing(4)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(16)
            .background(wa.red.opacity(0.10), in: RoundedRectangle(cornerRadius: 22, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(wa.red.opacity(0.25), lineWidth: 1))
            .padding(.top, 4)
            .padding(.bottom, 6)
            .waRise(0)

            Spacer().frame(height: 10)
            WlSecurityRow(icon: "lock.fill", title: "wa_pin_menu_pin_title", subtitle: "wa_pin_menu_pin_sub", index: 1, state: nil) { onOpen(.changePin) }
            WlSecurityRow(icon: "key.fill", title: "wa_rec_change_link", subtitle: "wa_pin_menu_recovery_sub", index: 2, state: nil) { onOpen(.recovery) }
            WlSecurityRow(icon: "faceid", title: "wa_biometric_menu_title", subtitle: "wa_biometric_menu_sub", index: 3,
                          state: biometricAvailable ? nil : L("wa_not_available")) { onOpen(.biometric) }
        }
    }
}

/// One entry of the menu: brand-tinted icon, title + one line of help, optional state, chevron.
@MainActor
private struct WlSecurityRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let subtitle: String
    let index: Int
    let state: String?
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(spacing: 14) {
                WaIconWell(icon: icon, tone: .red, size: 48, iconSize: 24)
                VStack(alignment: .leading, spacing: 2) {
                    Text(L(title)).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink)
                    Text(L(subtitle)).font(.dorr(11)).foregroundStyle(wa.mut).lineSpacing(2)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .multilineTextAlignment(.leading)
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

// MARK: - 1. the wallet PIN

/// Change the PIN: current → new → confirm, all on the pad, ending on a "saved" seal. A wrong current PIN sends the person back to
/// the first step. "Forgot your PIN?" on the first step opens the recovery flow.
@MainActor
private struct WlChangePinPage: View {
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
                    Spacer().frame(height: 12)
                    WaButton(text: L("wa_done"), action: onBack).padding(.horizontal, 20)
                }
            } else {
                VStack(spacing: 0) {
                    let texts: (String, String) = step == "current" ? (L("wa_pin_current_title"), L("wa_pin_current_sub"))
                        : step == "new" ? (L("wa_pin_new_title"), L("wa_pin_digits_sub")) : (L("wa_pin_confirm_title"), L("wa_pin_confirm_sub"))
                    WaPinPad(title: texts.0, sub: texts.1, icon: "checkmark.shield.fill") { pin in await complete(pin) }
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

    private func complete(_ pin: String) async -> PadResult {
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
            let result = await WalletApi.changePin(ChangePinRequest(currentPin: current, pin: fresh, pinConfirmation: pin))
            if result.value != nil {
                // Face ID holds the old PIN: it is turned off, and turned on again with the new one.
                if WalletBiometric.isEnabled() { WalletBiometric.disable() }
                WalletSession.shared.unlock(fresh)
                saved = true
                return .ok
            }
            // A wrong current PIN sends the person back to the start; anything else retries.
            step = "current"
            return padFailure(result, L("wa_error_network"), L("wa_error_generic"))
        }
    }
}

// MARK: - 2. the recovery method
// `WaChangeRecoveryPage` (UI/Wallet/WaPinFlows.swift), the DORR app's own page.

// MARK: - 3. Face ID / Touch ID

/// Turning Face ID / Touch ID unlock on or off. Turning it on needs the PIN once (the pad), then the system prompt; turning it off
/// needs nothing — it is only a local convenience, never a way to pay by itself.
@MainActor
private struct WlBiometricPage: View {
    @Environment(\.wa) private var wa
    @Environment(\.walletHost) private var host
    let onBack: () -> Void
    @State private var available = WalletBiometric.isAvailable()
    @State private var enabled = WalletBiometric.isEnabled()
    @State private var verifying = false

    var body: some View {
        if verifying {
            WaPage(title: L("wa_biometric_menu_title"), onBack: { verifying = false }, scroll: false) {
                WaPinPad(title: L("wa_pin_enter_title"), sub: L("wa_biometric_toggle"), icon: "faceid") { pin in
                    let result = await WalletApi.verifyPin(pin)
                    if result.value == nil { return padFailure(result, L("wa_error_network"), L("wa_error_generic")) }
                    verifying = false
                    WalletBiometric.enable(pin: pin) { ok in
                        enabled = ok
                        if !ok { host?.showError(L("wa_biometric_setup_failed")) }
                    }
                    return .ok
                }
                .frame(maxHeight: .infinity)
                .waRise(0)
            }
        } else {
            WaPage(title: L("wa_biometric_menu_title"), onBack: onBack) {
                VStack(spacing: 0) {
                    WaIconWell(icon: "faceid", tone: .red, size: 96, iconSize: 48).waRise(0)
                    Text(L("wa_biometric_menu_title")).font(.dorr(20, .heavy)).foregroundStyle(wa.ink).multilineTextAlignment(.center)
                        .padding(.top, 18).waRise(1)
                    Text(L("wa_biometric_toggle_hint")).font(.dorr(13)).foregroundStyle(wa.mut).lineSpacing(8).multilineTextAlignment(.center)
                        .padding(.top, 10).padding(.horizontal, 8).waRise(2)
                }
                .frame(maxWidth: .infinity)
                .padding(.top, 8)
                Spacer().frame(height: 24)
                if available {
                    WaCard(padding: 16) {
                        HStack(spacing: 12) {
                            WaIconWell(icon: "faceid", tone: .red, size: 44, iconSize: 22)
                            Text(L("wa_biometric_toggle")).font(.dorr(14, .heavy)).foregroundStyle(wa.ink).frame(maxWidth: .infinity, alignment: .leading)
                            RedToggle(on: enabled) {
                                if !enabled {
                                    verifying = true
                                } else {
                                    WalletBiometric.disable()
                                    enabled = false
                                }
                            }
                        }
                    }
                    .waRise(3)
                } else {
                    WaNote(text: L("wa_biometric_unavailable"), icon: "info.circle.fill").waRise(3)
                }
            }
        }
    }
}
