// providerApp `ui/screens/WalletGate.kt`
import SwiftUI

/// The wallet (FL-005), in the DORR app's wallet look (its `Wa` kit, the same colours): first a way to get the PIN back and the
/// PIN itself (the four-dot pad), then — each time the wallet is opened — the pad (or Face ID / Touch ID when it is on), and on
/// a phone the wallet has not seen before, a code sent to the number on file. Only then the wallet home (`WalletHomeHost`).
/// Leaving the wallet drops the PIN from memory. Errors come as the wallet's toast at the top.
@MainActor
struct WalletRoute: View {
    let onBack: () -> Void
    @Environment(\.wa) private var wa
    @StateObject private var host = WalletHost()

    var body: some View {
        ZStack(alignment: .top) {
            wa.bg.ignoresSafeArea()
            WalletStages(onBack: onBack, host: host)
            WaToastHost(host: host)
        }
        .environment(\.walletHost, host)
    }
}

@MainActor
private struct WalletStages: View {
    let onBack: () -> Void
    let host: WalletHost
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var session = WalletSession.shared
    @State private var status: PinStatusDto?
    @State private var trusted = false
    @State private var forgot = false
    /// A reset PIN (`must_change`) has to be replaced before the wallet opens.
    @State private var mustChange = false

    var body: some View {
        content
            // One read when the wallet opens (and when the connection comes back).
            .task(id: network.reconnectTick) {
                let result = await WalletApi.pinStatus()
                if let data = result.value { status = data }
                else if status == nil { host.showError(result.errorText(L("wa_error_network")) ?? L("wa_error_generic")) }
            }
            // Leaving the wallet forgets the PIN.
            .onDisappear { WalletSession.shared.lock() }
    }

    @ViewBuilder private var content: some View {
        if let current = status {
            if current.isFrozen == true {
                WaPage(title: L("wa_my_wallet"), onBack: onBack, scroll: false) {
                    WaStatusColumn {
                        WaSeal(bad: true)
                        WaStatusTitle(text: L("wa_frozen_title"))
                        WaStatusText(text: L("wl_frozen"))
                    }
                }
            } else if current.hasPin != true {
                WalletSetup(status: current, onBack: onBack, onUnlocked: { createdPin, verify in
                    mustChange = verify.mustChange ?? false
                    trusted = verify.deviceTrusted ?? true
                    WalletSession.shared.unlock(createdPin)
                }, onStatus: { status = $0 })
            } else if forgot {
                WalletRecover(status: current, onBack: { forgot = false }) {
                    status = $0
                    forgot = false
                }
            } else if let pin = session.pin {
                if mustChange {
                    WalletChangePin(currentPin: pin, forced: true, onBack: onBack) { mustChange = false }
                } else if !trusted {
                    WalletDeviceTrust(pin: pin, onBack: onBack) { trusted = true }
                } else {
                    WalletHomeHost(onBack: onBack)
                }
            } else {
                WalletGateScreen(status: current, onBack: onBack, onForgot: { forgot = true }) { unlockedPin, verify in
                    mustChange = verify.mustChange ?? false
                    trusted = verify.deviceTrusted ?? true
                    WalletSession.shared.unlock(unlockedPin)
                }
            }
        } else {
            WaPage(title: L("wa_my_wallet"), onBack: onBack) {
                WaSkeleton(cornerRadius: 24).frame(height: 172)
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

/// The PIN typed twice on the pad: `first`, then the same again; a mismatch starts over.
@MainActor
struct WalletPinTwice: View {
    let firstTitle: String
    let firstSub: String
    let onBoth: @MainActor (String) async -> PadResult
    @State private var step = 0
    @State private var first = ""

    var body: some View {
        Group {
            if step == 0 {
                WaPinPad(title: firstTitle, sub: firstSub) { entered in
                    first = entered
                    step = 1
                    return .reset
                }
            } else {
                WaPinPad(title: L("wa_pin_confirm_title"), sub: L("wa_pin_confirm_sub")) { entered in
                    if entered != first {
                        step = 0
                        first = ""
                        return .error(L("wa_pin_mismatch"))
                    }
                    return await onBoth(entered)
                }
            }
        }
        .id(step)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

// MARK: - setting up: recovery first, then the PIN (as in the DORR app)

private let setupMethods = ["password", "birth_date", "email"]

private func methodIcon(_ method: String) -> String {
    switch method {
    case "password": return "key.fill"
    case "birth_date": return "birthday.cake.fill"
    default: return "envelope.fill"
    }
}

private func methodTone(_ method: String) -> Tone {
    switch method {
    case "password": return .red
    case "birth_date": return .pink
    default: return .green
    }
}

private func methodTitle(_ method: String) -> String {
    switch method {
    case "password": return "wa_rec_m_password"
    case "birth_date": return "wa_rec_m_birth_date"
    default: return "wa_rec_m_email"
    }
}

private func methodDesc(_ method: String) -> String {
    switch method {
    case "password": return "wa_rec_m_password_d"
    case "birth_date": return "wa_rec_m_birth_date_d"
    default: return "wa_rec_m_email_d"
    }
}

private func emailShape(_ text: String) -> Bool { text.range(of: #"^[^@\s]+@[^@\s]+\.[^@\s]+$"#, options: .regularExpression) != nil }

@MainActor
private struct WlStepHeader: View {
    @Environment(\.wa) private var wa
    let icon: String
    let tone: Tone
    let title: String
    let sub: String

    var body: some View {
        VStack(spacing: 0) {
            WaIconWell(icon: icon, tone: tone, size: 58, iconSize: 28)
            Text(title).font(.dorr(18, .heavy)).foregroundStyle(wa.ink).multilineTextAlignment(.center).padding(.top, 10)
            if !sub.isEmpty {
                Text(sub).font(.dorr(13)).foregroundStyle(wa.mut).lineSpacing(5).multilineTextAlignment(.center).padding(.top, 4)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.top, 8)
        .padding(.bottom, 18)
    }
}

@MainActor
private struct WlMethodRow: View {
    @Environment(\.wa) private var wa
    let method: String
    let index: Int
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            WaCard(padding: 14) {
                HStack(spacing: 12) {
                    WaIconWell(icon: methodIcon(method), tone: methodTone(method))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L(methodTitle(method))).font(.dorr(15, .heavy)).foregroundStyle(wa.ink)
                        Text(L(methodDesc(method))).font(.dorr(12.5)).foregroundStyle(wa.mut).lineSpacing(3)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Image(systemName: "chevron.forward").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.soft)
                }
            }
            .multilineTextAlignment(.leading)
        }
        .buttonStyle(WaPressStyle(scale: 0.98))
        .waRise(index)
    }
}

/// First-time PIN, step by step like the DORR app: choose a way to get the PIN back → its details (an e-mail is confirmed with a
/// 4-digit code) → the PIN twice → "saved". The wallet opens from "saved" with the PIN just created (not asked for again).
@MainActor
struct WalletSetup: View {
    @Environment(\.wa) private var wa
    let status: PinStatusDto
    let onBack: () -> Void
    let onUnlocked: (String, PinVerifyDto) -> Void
    let onStatus: (PinStatusDto) -> Void
    /// Set when the provider already has a PIN and only switches the recovery method (proven with that PIN): it ends after the method.
    var existingPin: String?
    var onChanged: () -> Void = {}

    @State private var step: String
    @State private var method: String
    @State private var password = ""
    @State private var passwordAgain = ""
    @State private var day = ""
    @State private var month = ""
    @State private var year = ""
    @State private var email: String
    @State private var code = ""
    @State private var error: String?
    @State private var notice: String?
    @State private var busy = false
    /// Seconds until a new code may be asked for: counted on the phone's clock, nothing is asked of the server meanwhile.
    @State private var countdown = 0
    /// The PIN is created and "saved" is showing: the wallet opens (and the status updates) only from its button.
    @State private var created: (String, PinVerifyDto)?
    @State private var createdStatus: PinStatusDto?

    init(status: PinStatusDto, onBack: @escaping () -> Void, onUnlocked: @escaping (String, PinVerifyDto) -> Void,
         onStatus: @escaping (PinStatusDto) -> Void, existingPin: String? = nil, onChanged: @escaping () -> Void = {}) {
        self.status = status
        self.onBack = onBack
        self.onUnlocked = onUnlocked
        self.onStatus = onStatus
        self.existingPin = existingPin
        self.onChanged = onChanged
        let recovery = status.recovery
        let start: String
        if existingPin != nil { start = "method" }
        else if recovery?.ready == true { start = "pin" }
        else if recovery?.method == "email" { start = "code" }
        else { start = "method" }
        _step = State(initialValue: start)
        _method = State(initialValue: recovery?.method.flatMap { setupMethods.contains($0) ? $0 : nil } ?? "password")
        _email = State(initialValue: recovery?.email ?? "")
    }

    private var changing: Bool { existingPin != nil }
    private var pageTitle: String { L(changing ? "wa_rec_change_title" : "wa_pin_page_title") }

    var body: some View {
        page
            .task(id: countdown) {
                guard countdown > 0 else { return }
                try? await Task.sleep(nanoseconds: 1_000_000_000)
                if !Task.isCancelled { countdown -= 1 }
            }
    }

    @ViewBuilder private var page: some View {
        switch step {
        case "method":
            WaPage(title: pageTitle, onBack: back) {
                WlStepHeader(icon: "checkmark.shield.fill", tone: .red, title: L("wa_rec_choose_title"), sub: L("wa_rec_choose_sub"))
                ForEach(Array(setupMethods.enumerated()), id: \.offset) { index, item in
                    WlMethodRow(method: item, index: index) {
                        method = item
                        error = nil
                        step = "detail"
                    }
                    Spacer().frame(height: 10)
                }
            }
        case "detail":
            WaPage(title: pageTitle, onBack: back, cta: {
                WaError(message: error)
                WaButton(text: L(method == "email" ? "pd_send_code" : "wa_continue"), loading: busy) { submitDetail() }
            }) {
                WlStepHeader(icon: methodIcon(method), tone: methodTone(method), title: L(methodTitle(method)), sub: "")
                detailFields
            }
        case "code":
            WaPage(title: pageTitle, onBack: back, cta: {
                WaError(message: error)
                WaButton(text: L("profile_otp_confirm"), enabled: code.count == 4, loading: busy) { confirmCode() }
            }) {
                WaCard(padding: 18) {
                    Text(L("wa_rec_code_sub", email.trimmingCharacters(in: .whitespaces)))
                        .font(.dorr(13)).foregroundStyle(wa.mut).lineSpacing(6).multilineTextAlignment(.center).frame(maxWidth: .infinity)
                    Spacer().frame(height: 18)
                    WaOtpBoxes(value: Binding(get: { code }, set: { code = String(asciiDigits($0).prefix(4)); error = nil }), error: error != nil)
                    Spacer().frame(height: 16)
                    if countdown > 0 {
                        Text(L("profile_otp_timer", countdown)).font(.dorr(13)).foregroundStyle(wa.mut).frame(maxWidth: .infinity)
                    } else {
                        Button { resendCode() } label: {
                            Text(L("profile_otp_resend")).font(.dorr(13.5, .heavy)).foregroundStyle(wa.red)
                                .frame(maxWidth: .infinity).padding(.vertical, 4)
                        }
                        .buttonStyle(.plain)
                    }
                    if let notice {
                        Text(notice).font(.dorr(12.5)).foregroundStyle(wa.green).multilineTextAlignment(.center).frame(maxWidth: .infinity).padding(.top, 8)
                    }
                }
            }
        case "pin":
            WaPage(title: L("wa_pin_create_title_page"), onBack: back, scroll: false) {
                WalletPinTwice(firstTitle: L("wa_gate_create_title"), firstSub: L("wa_gate_create_sub")) { pin in
                    let result = await WalletApi.createPin(CreatePinRequest(pin: pin, pinConfirmation: pin))
                    if let data = result.value {
                        // Typed twice just now: the wallet opens with it from "saved" instead of asking for it a third time.
                        if let verified = await WalletApi.verifyPin(pin).value { created = (pin, verified) }
                        createdStatus = data
                        step = "saved"
                        return .ok
                    }
                    return padFailure(result, L("wa_error_network"), L("wa_error_generic"))
                }
            }
        default:
            WaPage(title: pageTitle, onBack: {}, scroll: false) {
                WaStatusColumn {
                    WaSeal()
                    WaStatusTitle(text: L(changing ? "wa_rec_changed_title" : "wa_pin_saved_title"))
                    WaStatusText(text: L(changing ? "wa_rec_changed_text" : "wa_pin_saved_text"))
                    Spacer().frame(height: 12)
                    WaButton(text: L("wa_done")) {
                        if changing { onChanged() } else {
                            if let created { onUnlocked(created.0, created.1) }
                            if let createdStatus { onStatus(createdStatus) }
                        }
                    }
                    .padding(.horizontal, 20)
                }
            }
        }
    }

    @ViewBuilder private var detailFields: some View {
        switch method {
        case "password":
            WaCard(padding: 16) {
                DorrTextField(value: Binding(get: { password }, set: { password = $0; error = nil }), label: L("wa_rec_password"), icon: "lock.fill", password: true)
                Spacer().frame(height: 12)
                DorrTextField(value: Binding(get: { passwordAgain }, set: { passwordAgain = $0; error = nil }), label: L("wa_rec_password_confirm"),
                              icon: "lock.rotation", password: true)
                Text(L("wa_rec_password_hint")).font(.dorr(12)).foregroundStyle(wa.mut).lineSpacing(4).padding(.top, 12).padding(.horizontal, 2)
            }
        case "birth_date":
            WaBirthPicker(day: Binding(get: { day }, set: { day = $0; error = nil }),
                          month: Binding(get: { month }, set: { month = $0; error = nil }),
                          year: Binding(get: { year }, set: { year = $0; error = nil }), error: error != nil)
            Spacer().frame(height: 12)
            WaNote(text: L("wa_rec_birth_hint"))
        default:
            WaCard(padding: 16) {
                Text(L("wa_rec_email")).font(.dorr(14, .heavy)).foregroundStyle(wa.ink).padding(.bottom, 10)
                WaPillField(value: Binding(get: { email }, set: { email = $0; error = nil }), placeholder: "name@example.com", icon: "envelope.fill",
                            keyboard: .emailAddress, error: error != nil)
                Text(L("wa_rec_email_hint")).font(.dorr(12)).foregroundStyle(wa.mut).lineSpacing(4).padding(.top, 10).padding(.horizontal, 2)
            }
        }
    }

    private func back() {
        error = nil
        notice = nil
        switch step {
        case "method": onBack()
        case "detail", "pin": step = "method"
        case "code": step = "detail"
        default: break
        }
    }

    private func submitDetail() {
        switch method {
        case "password":
            error = password.count < 6 ? L("wa_rec_password_short") : (password != passwordAgain ? L("wa_rec_password_mismatch") : nil)
        case "birth_date":
            error = birthDateOrNull(day, month, year) == nil ? L("wa_rec_birth_invalid") : nil
        default:
            error = emailShape(email.trimmingCharacters(in: .whitespaces)) ? nil : L("wa_rec_email_invalid")
        }
        if error != nil { return }
        busy = true
        Task { @MainActor in
            let body: RecoverySetupRequest
            switch method {
            case "password": body = RecoverySetupRequest(method: "password", password: password, passwordConfirmation: passwordAgain)
            case "birth_date": body = RecoverySetupRequest(method: "birth_date", birthDate: birthDateOrNull(day, month, year))
            default: body = RecoverySetupRequest(method: "email", email: email.trimmingCharacters(in: .whitespaces))
            }
            let saved = await WalletApi.setRecovery(body, pin: existingPin)
            if saved.value == nil {
                error = saved.errorText(L("wa_error_network")) ?? L("wa_error_generic")
            } else if method == "email" {
                let sent = await WalletApi.done({ await WalletApi.sendRecoveryEmailCode(pending: changing ? true : nil) },
                                                offline: L("wa_error_network"), generic: L("wa_error_generic"))
                if let sent { error = sent } else {
                    countdown = 60
                    step = "code"
                }
            } else {
                step = changing ? "saved" : "pin"
            }
            busy = false
        }
    }

    private func confirmCode() {
        guard code.count == 4 else {
            error = L("wa_rec_code_invalid")
            return
        }
        busy = true
        Task { @MainActor in
            let result = await WalletApi.confirmRecoveryEmail(CodeBody(code: code.trimmingCharacters(in: .whitespaces)))
            if result.value != nil {
                error = nil
                step = changing ? "saved" : "pin"
            } else {
                error = result.errorText(L("wa_error_network")) ?? L("wa_error_generic")
            }
            busy = false
        }
    }

    private func resendCode() {
        Task { @MainActor in
            let sent = await WalletApi.done({ await WalletApi.sendRecoveryEmailCode(pending: changing ? true : nil) },
                                            offline: L("wa_error_network"), generic: L("wa_error_generic"))
            if let sent { error = sent } else {
                error = nil
                notice = L("wa_rec_code_sent")
                countdown = 60
            }
        }
    }
}

// MARK: - the gate: the pad, or Face ID / Touch ID

/// Android `WalletGate` (renamed: Swift has one namespace with this file's name).
@MainActor
private struct WalletGateScreen: View {
    @Environment(\.wa) private var wa
    @Environment(\.walletHost) private var host
    let status: PinStatusDto
    let onBack: () -> Void
    let onForgot: () -> Void
    let onUnlocked: (String, PinVerifyDto) -> Void
    @State private var biometric = WalletBiometric.isEnabled() && WalletBiometric.isAvailable()
    @State private var prompted = false

    private var lockedUntil: Date? { parseInstant(status.lockedUntil) }

    var body: some View {
        WaPage(title: L("wa_my_wallet"), onBack: onBack, scroll: false) {
            WaPinPad(title: L("wa_gate_enter_title"), sub: L("wa_gate_enter_sub"), lockedUntil: lockedUntil) { await verify($0) }
                .frame(maxHeight: .infinity)
            HStack {
                Spacer()
                Button(action: onForgot) {
                    Text(L("wa_forgot_link")).font(.dorr(13.5, .bold)).foregroundStyle(wa.red).padding(8)
                }
                .buttonStyle(.plain)
                if biometric {
                    Spacer()
                    Button { unlockWithBiometric() } label: {
                        HStack(spacing: 6) {
                            Image(systemName: "faceid").font(.system(size: 18, weight: .semibold))
                            Text(L("wa_biometric_unlock_button")).font(.dorr(13.5, .bold))
                        }
                        .foregroundStyle(wa.red)
                        .padding(8)
                    }
                    .buttonStyle(.plain)
                }
                Spacer()
            }
            .padding(.vertical, 8)
        }
        // With Face ID / Touch ID on, the prompt opens by itself once.
        .onAppear {
            guard !prompted else { return }
            prompted = true
            if biometric && lockedUntil == nil { unlockWithBiometric() }
        }
    }

    private func verify(_ pin: String) async -> PadResult {
        let result = await WalletApi.verifyPin(pin)
        if let data = result.value {
            onUnlocked(pin, data)
            return .ok
        }
        return padFailure(result, L("wa_error_network"), L("wa_error_generic"))
    }

    private func unlockWithBiometric() {
        WalletBiometric.unlock { stored in
            guard let stored else { return }
            Task { @MainActor in
                if case .error = await verify(stored) { host?.showError(L("wa_biometric_unlock_failed")) }
            }
        }
    }
}

// MARK: - forgot the PIN

@MainActor
struct WalletRecover: View {
    @Environment(\.wa) private var wa
    @Environment(\.walletHost) private var host
    let status: PinStatusDto
    let onBack: () -> Void
    let onDone: (PinStatusDto) -> Void
    @State private var password = ""
    @State private var birth = ""
    @State private var code = ""
    @State private var codeSent = false
    /// The secret first, then the new PIN on the pad, then done.
    @State private var stage = "secret"
    @State private var busy = false
    @State private var finished: PinStatusDto?

    private var method: String { status.recovery?.method ?? "" }
    private var supported: Bool { ["password", "birth_date", "email"].contains(method) }
    private var ready: Bool {
        switch method {
        case "password": return !password.isEmpty
        case "birth_date": return !birth.isEmpty
        case "email": return codeSent && code.count >= 4
        default: return false
        }
    }

    var body: some View {
        if let done = finished {
            WaPage(title: L("wa_forgot_title"), onBack: { onDone(done) }, scroll: false) {
                WaStatusColumn {
                    WaSeal()
                    WaStatusTitle(text: L("wa_forgot_done_title"))
                    WaStatusText(text: L("wa_forgot_done_text"))
                    Spacer().frame(height: 14)
                    WaButton(text: L("wa_done")) { onDone(done) }
                }
            }
        } else if stage == "pin" {
            WaPage(title: L("wa_forgot_title"), onBack: { stage = "secret" }, scroll: false) {
                WalletPinTwice(firstTitle: L("wa_forgot_new_pin_title"), firstSub: L("wa_pin_digits_sub")) { pin in
                    let body = RecoverPinRequest(
                        password: method == "password" ? password : nil,
                        birthDate: method == "birth_date" ? birth : nil,
                        code: method == "email" ? code : nil,
                        pin: pin, pinConfirmation: pin
                    )
                    let result = await WalletApi.recoverPin(body)
                    if let data = result.value {
                        finished = data
                        return .ok
                    }
                    // A wrong secret: back to it, with the server's message.
                    stage = "secret"
                    host?.showError(result.errorText(L("wa_error_network")) ?? L("wa_error_generic"))
                    return .reset
                }
            }
        } else if supported {
            WaPage(title: L("wa_forgot_title"), onBack: onBack, cta: {
                if method == "email" && !codeSent {
                    WaButton(text: L("wl_send_code"), loading: busy) { sendCode() }
                } else {
                    WaButton(text: L("wa_continue"), enabled: ready) { stage = "pin" }
                }
            }) { secretCard }
        } else {
            WaPage(title: L("wa_forgot_title"), onBack: onBack) { secretCard }
        }
    }

    private var secretCard: some View {
        WaCard(padding: 16) {
            VStack(alignment: .leading, spacing: 12) {
                switch method {
                case "password":
                    Text(L("wa_forgot_password_sub")).font(.dorr(13)).foregroundStyle(wa.mut)
                    DorrTextField(value: $password, label: L("wa_rec_password"), password: true)
                case "birth_date":
                    Text(L("wa_forgot_birth_sub")).font(.dorr(13)).foregroundStyle(wa.mut)
                    DateField(value: $birth, label: L("wa_rec_birth_label"))
                case "email":
                    Text(L("wa_forgot_email_sub", status.recovery?.email ?? "")).font(.dorr(13)).foregroundStyle(wa.mut)
                    if codeSent {
                        OtpField(value: Binding(get: { code }, set: { code = String(asciiDigits($0).prefix(8)) }))
                    }
                case "":
                    Text(L("wa_forgot_no_method")).font(.dorr(14)).foregroundStyle(wa.ink)
                default:
                    Text(L("wl_recover_photo")).font(.dorr(14)).foregroundStyle(wa.ink)
                }
            }
        }
    }

    private func sendCode() {
        busy = true
        Task { @MainActor in
            let failed = await WalletApi.done({ await WalletApi.sendRecoveryEmailCode() }, offline: L("wa_error_network"), generic: L("wa_error_generic"))
            busy = false
            if let failed { host?.showError(failed) } else {
                codeSent = true
                host?.showToast(L("wa_rec_code_sent"))
            }
        }
    }
}

// MARK: - a phone the wallet has not seen

@MainActor
private struct WalletDeviceTrust: View {
    @Environment(\.walletHost) private var host
    let pin: String
    let onBack: () -> Void
    let onTrusted: () -> Void
    @State private var code = ""
    @State private var busy = false
    @State private var sent = false

    var body: some View {
        WaPage(title: L("wa_device_trust_title"), onBack: onBack, scroll: false) {
            WaStatusColumn {
                WaPulse(icon: "iphone")
                WaStatusTitle(text: L("wa_device_trust_title"))
                WaStatusText(text: L("wa_device_trust_sub"))
                Spacer().frame(height: 16)
                OtpField(value: Binding(get: { code }, set: { code = String(asciiDigits($0).prefix(8)) }))
                Spacer().frame(height: 12)
                WaButton(text: L("wl_confirm_code"), enabled: code.count >= 4, loading: busy) {
                    busy = true
                    Task { @MainActor in
                        // The confirm answers without data on success, so the `done` reading.
                        let failed = await WalletApi.done({ await WalletApi.confirmDevice(pin, CodeBody(code: code.trimmingCharacters(in: .whitespaces))) },
                                                          offline: L("wa_error_network"), generic: L("wa_error_generic"))
                        busy = false
                        if let failed { host?.showError(failed) } else { onTrusted() }
                    }
                }
            }
        }
        // One code is sent when this page opens.
        .task {
            guard !sent else { return }
            sent = true
            if let failed = await WalletApi.done({ await WalletApi.sendDeviceCode(pin) }, offline: L("wa_error_network"), generic: L("wa_error_generic")) {
                host?.showError(failed)
            }
        }
    }
}

// MARK: - changing the PIN (also forced after a reset)

/// The current PIN, then the new one twice, on the pad. After a reset (`must_change`) the current one is already known.
@MainActor
struct WalletChangePin: View {
    @Environment(\.wa) private var wa
    @Environment(\.walletHost) private var host
    let currentPin: String
    let forced: Bool
    let onBack: () -> Void
    let onDone: () -> Void
    @State private var current: String

    init(currentPin: String, forced: Bool, onBack: @escaping () -> Void, onDone: @escaping () -> Void) {
        self.currentPin = currentPin
        self.forced = forced
        self.onBack = onBack
        self.onDone = onDone
        _current = State(initialValue: forced ? currentPin : "")
    }

    var body: some View {
        WaPage(title: L("wa_pin_page_title"), onBack: { if !forced { onBack() } }, scroll: false) {
            if forced {
                Text(L("wl_must_change")).font(.dorr(13)).foregroundStyle(wa.mut).frame(maxWidth: .infinity, alignment: .leading).padding(.bottom, 4)
            }
            if current.isEmpty {
                WaPinPad(title: L("wa_pin_current_title"), sub: L("wa_pin_current_sub"), icon: "lock.fill") { entered in
                    current = entered
                    return .reset
                }
                .frame(maxHeight: .infinity)
            } else {
                WalletPinTwice(firstTitle: L("wa_pin_new_title"), firstSub: L("wa_pin_digits_sub")) { pin in
                    let result = await WalletApi.changePin(ChangePinRequest(currentPin: current, pin: pin, pinConfirmation: pin))
                    if result.value != nil {
                        // Face ID holds the old PIN: it is turned off, and turned on again with the new one.
                        if WalletBiometric.isEnabled() { WalletBiometric.disable() }
                        WalletSession.shared.unlock(pin)
                        host?.showToast(L("wa_pin_saved_title"))
                        onDone()
                        return .ok
                    }
                    // A wrong current PIN: ask for it again.
                    if !forced { current = "" }
                    host?.showError(result.errorText(L("wa_error_network")) ?? L("wa_error_generic"))
                    return .reset
                }
            }
        }
    }
}
