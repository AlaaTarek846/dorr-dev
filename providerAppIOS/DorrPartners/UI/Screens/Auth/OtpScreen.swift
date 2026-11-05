import SwiftUI

private let otpLength = 4

/// providerApp `OtpScreen.kt` — the code sent by SMS. The resend countdown starts from the server's cooldown and runs on
/// the phone's clock — no request is made to ask "can I resend yet". A correct code signs in and keeps the token in `Session`.
@MainActor
struct OtpScreen: View {
    @Environment(\.wa) private var wa
    let dialCode: String
    let phoneNumber: String
    let cooldownSeconds: Int
    let onBack: () -> Void
    let onVerified: () -> Void

    @State private var code = ""
    @FocusState private var focused: Bool
    @State private var isVerifying = false
    @State private var hasError = false
    @State private var errorMessage: String?
    @State private var isSuccess = false
    @State private var shake: CGFloat = 0
    @State private var cooldown = 60
    @State private var countdown = 60
    @State private var timerKey = 0
    @State private var pop = -1

    var body: some View {
        let isComplete = code.count == otpLength
        ZStack {
            PinkBackdrop()
            ScrollView {
                VStack(spacing: 0) {
                    HStack {
                        Button(action: onBack) {
                            Image(systemName: "arrow.backward").font(.system(size: 19, weight: .semibold))
                                .foregroundStyle(wa.night ? wa.darkAccent : Color(hex: 0x111928))
                                .frame(width: 36, height: 36)
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel(L("common_back"))
                        Spacer()
                        LanguageSwitch()
                    }
                    VStack(spacing: 0) {
                        Text(L("otp_title")).font(.dorr(26, .heavy)).foregroundStyle(wa.red).multilineTextAlignment(.center)
                        Text(L("otp_subtitle", [dialCode, phoneNumber].filter { !$0.isEmpty }.joined(separator: " ")))
                            .font(.dorr(14)).foregroundStyle(wa.night ? wa.mut : Color(hex: 0x6B7280)).multilineTextAlignment(.center)
                            .lineSpacing(6).frame(maxWidth: 280).padding(.top, 8)
                        digits.padding(.top, 24)
                        SettingsPrimaryButton(text: L("otp_verify"), enabled: isComplete, loading: isVerifying, action: verify).padding(.top, 24)
                        Group {
                            if countdown <= 0 {
                                Button(action: resend) {
                                    Text(L("otp_resend")).font(.dorr(14, .semibold)).foregroundStyle(wa.accent)
                                }
                                .buttonStyle(.plain)
                            } else {
                                Text(L("otp_resend_in", countdown)).font(.dorr(12)).foregroundStyle(wa.night ? wa.mut : Color(hex: 0x9CA3AF))
                            }
                        }
                        .padding(.top, 14)
                        InlineErrorBanner(message: hasError ? errorMessage : nil)
                        #if DEBUG
                        // A test build accepts a fixed code (config/auth_flow.php); a release build never shows this.
                        Text(L("otp_dev_hint")).font(.dorr(12)).foregroundStyle(Color(hex: 0xF59E0B)).multilineTextAlignment(.center).padding(.top, 16)
                        #endif
                    }
                    .frame(maxWidth: 440)
                    .padding(.top, 32)
                }
                .padding(.horizontal, 24)
                .padding(.top, 10)
                .padding(.bottom, 28)
            }
        }
        .navigationBarHidden(true)
        .onAppear {
            cooldown = cooldownSeconds
            focused = true
        }
        .task(id: timerKey) {
            // The device clock, one tick a second, from the server's cooldown (never a request).
            countdown = timerKey == 0 ? cooldownSeconds : cooldown
            while countdown > 0 {
                try? await Task.sleep(nanoseconds: 1_000_000_000)
                if Task.isCancelled { return }
                countdown -= 1
            }
        }
    }

    /// The four boxes over one hidden field (the iOS keyboard handles typing, pasting and the SMS autofill in one place).
    /// The digits are always left-to-right, even in Arabic.
    private var digits: some View {
        let override: Color? = isSuccess ? AppColors.success : (hasError ? AppColors.danger : nil)
        return ZStack {
            TextField("", text: Binding(get: { code }, set: { typed in
                let clean = String(typed.filter { $0.isASCII && $0.isNumber }.prefix(otpLength))
                if clean.count > code.count {
                    hasError = false
                    pop = clean.count - 1
                    withAnimation(.easeOut(duration: 0.1)) { pop = clean.count - 1 }
                    Task {
                        try? await Task.sleep(nanoseconds: 120_000_000)
                        withAnimation(.easeOut(duration: 0.1)) { pop = -1 }
                    }
                }
                code = clean
                if clean.count == otpLength { verify() }
            }))
            .keyboardType(.numberPad)
            .textContentType(.oneTimeCode)
            .focused($focused)
            .opacity(0.02)
            .frame(width: 1, height: 1)
            HStack(spacing: 8) {
                ForEach(0..<otpLength, id: \.self) { i in
                    let chars = Array(code)
                    let isFocusedBox = focused && (i == min(code.count, otpLength - 1))
                    let border = override ?? (isFocusedBox ? wa.red : (wa.night ? wa.line : Color(hex: 0xFBD2C4)))
                    Text(verbatim: i < chars.count ? String(chars[i]) : "")
                        .font(.dorr(22, .semibold))
                        .foregroundStyle(wa.night ? wa.ink : Color(hex: 0x111928))
                        .frame(width: 44, height: 52)
                        .background(wa.night ? wa.darkCard : .white, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(border, lineWidth: 1.5))
                        .scaleEffect(pop == i ? 1.15 : 1)
                }
            }
            .frame(maxWidth: .infinity)
            .offset(x: shake)
            .contentShape(Rectangle())
            .onTapGesture { focused = true }
        }
        .environment(\.layoutDirection, .leftToRight)
    }

    private func triggerShake() async {
        for target: CGFloat in [-10, 10, -10, 0] {
            withAnimation(.linear(duration: 0.1)) { shake = target }
            try? await Task.sleep(nanoseconds: 100_000_000)
        }
    }

    private func verify() {
        guard code.count == otpLength, !isVerifying else { return }
        isVerifying = true
        hasError = false
        errorMessage = nil
        Task {
            let result = await AuthApi.verifyOtp(dialCode: dialCode, phone: phoneNumber, code: code)
            isVerifying = false
            switch result {
            case .ok(let data, _):
                guard let token = data.token, !token.isEmpty else {
                    hasError = true
                    errorMessage = L("otp_error_generic")
                    return
                }
                Session.shared.me = data.provider
                Session.shared.signIn(token)
                isSuccess = true
                try? await Task.sleep(nanoseconds: 300_000_000)
                onVerified()
            default:
                hasError = true
                errorMessage = result.errorText(L("login_error_offline")) ?? L("otp_error_generic")
                await triggerShake()
            }
        }
    }

    private func resend() {
        code = ""
        hasError = false
        errorMessage = nil
        isSuccess = false
        focused = true
        Task {
            let result = await AuthApi.resendOtp(dialCode: dialCode, phone: phoneNumber)
            if case .ok(let data, _) = result {
                cooldown = data.resendCooldownSeconds ?? cooldown
                timerKey += 1
            } else {
                hasError = true
                errorMessage = result.errorText(L("login_error_offline")) ?? L("otp_error_generic")
            }
        }
    }
}
