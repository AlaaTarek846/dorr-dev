import SwiftUI
import UIKit

private let otpLength = 4

/// androidApp `OtpScreen.kt` — 4 boxes, auto-verify on the last digit, a 60 s resend countdown, a shake on a
/// wrong code, and a note when the code restores a deleted account.
@MainActor
struct OtpScreen: View {
    @EnvironmentObject private var router: AppRouter
    @Environment(\.wa) private var wa

    @State private var digits = Array(repeating: "", count: otpLength)
    @State private var isVerifying = false
    @State private var hasError = false
    @State private var errorMessage: String?
    @State private var isSuccess = false
    @State private var shake: CGFloat = 0
    /// The countdown runs on the device clock (no server calls), restarted by each resend.
    @State private var resendAvailableAt = Date().addingTimeInterval(60)
    @FocusState private var focusedBox: Int?

    private var code: String { digits.joined() }

    var body: some View {
        let borderOverride: Color? = isSuccess ? AppColors.success : (hasError ? AppColors.danger : nil)
        ZStack {
            PinkBackdrop()
            ScrollView {
                VStack(spacing: 0) {
                    HStack {
                        Button {
                            router.restoreMode = false
                            router.go(.login)
                        } label: {
                            // ArrowBack, auto-mirrored: ← in English, → in Arabic.
                            Image(systemName: "arrow.backward").font(.system(size: 19, weight: .semibold))
                                .foregroundStyle(wa.night ? wa.darkAccent : Color(hex: 0x111928))
                                .frame(width: 36, height: 36)
                                .contentShape(Circle())
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel(L("common_back"))
                        Spacer()
                        LanguagePicker()
                    }
                    .frame(maxWidth: 440)

                    if router.restoreMode {
                        HStack(spacing: 10) {
                            Image(systemName: "checkmark.circle.fill").font(.system(size: 18)).foregroundStyle(AppColors.success)
                            VStack(alignment: .leading, spacing: 2) {
                                Text(L("account_restore_title")).font(.dorr(13.5, .bold)).foregroundStyle(wa.night ? .white : Color(hex: 0x14532D))
                                Text(L("account_restore_message")).font(.dorr(12)).foregroundStyle(wa.night ? Color(hex: 0xD1D5DB) : Color(hex: 0x166534))
                            }
                            Spacer(minLength: 0)
                        }
                        .padding(.horizontal, 14)
                        .padding(.vertical, 12)
                        .background(AppColors.success.opacity(wa.night ? 0.16 : 0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(AppColors.success.opacity(0.45), lineWidth: 1))
                        .frame(maxWidth: 440)
                        .padding(.top, 8)
                        .transition(.opacity)
                    }

                    VStack(spacing: 0) {
                        OtpShield(isError: hasError).frame(width: 118, height: 118)
                        Text(L("otp_title")).font(.dorr(26, .heavy)).foregroundStyle(wa.red).multilineTextAlignment(.center).padding(.top, 6)
                        let formattedPhone = [router.pendingDialCode, router.pendingPhone].filter { !$0.isEmpty }.joined(separator: " ")
                        Text(L("otp_subtitle", formattedPhone)).font(.dorr(14)).foregroundStyle(wa.night ? wa.mut : Color(hex: 0x6B7280))
                            .multilineTextAlignment(.center).lineSpacing(5).frame(maxWidth: 280).padding(.top, 8)

                        // OTP digits: always left to right, centred.
                        HStack(spacing: 8) {
                            ForEach(0..<otpLength, id: \.self) { index in
                                DigitBox(
                                    value: digits[index],
                                    focused: focusedBox == index,
                                    borderOverride: borderOverride,
                                    highlightFill: isSuccess || hasError,
                                    focus: $focusedBox,
                                    index: index,
                                    onChange: { typed(index, $0) },
                                    onBackspaceOnEmpty: {
                                        guard index > 0 else { return }
                                        digits[index - 1] = ""
                                        focusedBox = index - 1
                                    }
                                )
                            }
                        }
                        .frame(maxWidth: .infinity)
                        .offset(x: shake)
                        .environment(\.layoutDirection, .leftToRight)
                        .padding(.top, 22)

                        Button(action: verify) {
                            ZStack {
                                if isVerifying { ProgressView().tint(.white) } else { Text(L("otp_verify")).font(.dorr(15, .bold)) }
                            }
                            .foregroundStyle(.white)
                            .frame(maxWidth: .infinity, minHeight: 48)
                            .background(wa.red, in: Capsule())
                            .shadow(color: Color(hex: 0x001B53).opacity(0.22), radius: 8, y: 4)
                        }
                        .buttonStyle(.plain)
                        // Android keeps it fully coloured while disabled (disabledContainerColor = accent); verify() itself
                        // ignores taps until four digits are in.
                        .allowsHitTesting(!isVerifying && code.count == otpLength)
                        .padding(.top, 22)

                        TimelineView(.periodic(from: .now, by: 1)) { context in
                            let left = Int(ceil(resendAvailableAt.timeIntervalSince(context.date)))
                            if left > 0 {
                                Text(L("otp_resend_in", left)).font(.dorr(12)).foregroundStyle(wa.night ? wa.mut : Color(hex: 0x9CA3AF))
                            } else {
                                Button(action: resend) {
                                    Text(L("otp_resend")).font(.dorr(14, .semibold)).foregroundStyle(wa.accent)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .padding(.top, 14)

                        if hasError, let errorMessage {
                            HStack(spacing: 10) {
                                Image(systemName: "exclamationmark.circle").font(.system(size: 18, weight: .semibold)).foregroundStyle(wa.red)
                                Text(errorMessage).font(.dorr(12, .medium)).foregroundStyle(wa.night ? wa.darkAccent : Color(hex: 0x001B53))
                                Spacer(minLength: 0)
                            }
                            .padding(.horizontal, 14)
                            .padding(.vertical, 12)
                            .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                            .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(wa.night ? wa.darkAccent : Color(hex: 0xFBC8B7), lineWidth: 1))
                            .padding(.top, 16)
                            .transition(.opacity)
                        }

                        Text(L("otp_dev_hint")).font(.dorr(12)).foregroundStyle(Color(hex: 0xF59E0B)).multilineTextAlignment(.center).padding(.top, 16)
                    }
                    .frame(maxWidth: 440)
                    .padding(.top, 8)
                }
                .padding(.horizontal, 24)
                .padding(.top, 10)
                .padding(.bottom, 28)
                .frame(maxWidth: .infinity)
                .animation(.easeOut(duration: 0.3), value: router.restoreMode)
                .animation(.easeOut(duration: 0.2), value: hasError)
            }
            .scrollDismissesKeyboard(.interactively)
        }
        .onAppear { focusedBox = 0 }
    }

    private func typed(_ index: Int, _ digit: String) {
        digits[index] = digit
        guard !digit.isEmpty else { return }
        hasError = false
        if index < otpLength - 1 {
            focusedBox = index + 1
        } else {
            verify()
        }
    }

    private func verify() {
        guard code.count == otpLength, !isVerifying else { return }
        isVerifying = true
        hasError = false
        errorMessage = nil
        Task {
            do {
                let data = try await MobileAuthApi.verifyOtp(VerifyOtpRequest(dialCode: router.pendingDialCode, phone: router.pendingPhone, code: code)).data
                isVerifying = false
                AuthSession.shared.token = data?.token
                AuthSession.shared.user = data?.user
                isSuccess = true
                try? await Task.sleep(nanoseconds: 300_000_000)
                router.verified()
            } catch {
                isVerifying = false
                hasError = true
                errorMessage = error.serverMessage ?? L("otp_error_generic")
                await triggerShake()
            }
        }
    }

    private func resend() {
        digits = Array(repeating: "", count: otpLength)
        hasError = false
        errorMessage = nil
        isSuccess = false
        resendAvailableAt = Date().addingTimeInterval(60)
        focusedBox = 0
        Task {
            do {
                _ = try await MobileAuthApi.resendOtp(OtpRequest(dialCode: router.pendingDialCode, phone: router.pendingPhone))
            } catch {
                hasError = true
                errorMessage = error.serverMessage ?? L("otp_error_generic")
            }
        }
    }

    /// Android: −10 → 10 → −10 → 0 px, 100 ms each (pixels, so divided by the screen scale here).
    private func triggerShake() async {
        for x: CGFloat in [-10, 10, -10, 0] {
            withAnimation(.easeInOut(duration: 0.1)) { shake = x / UIScreen.main.scale }
            try? await Task.sleep(nanoseconds: 100_000_000)
        }
    }
}

/// One code box: 44×52, rounded 14, a single centred digit that pops when typed. A zero-width marker
/// lets an empty box notice Backspace (iOS sends nothing for deleting an empty field), like Android.
private struct DigitBox: View {
    @Environment(\.wa) private var wa
    let value: String
    let focused: Bool
    let borderOverride: Color?
    let highlightFill: Bool
    var focus: FocusState<Int?>.Binding
    let index: Int
    let onChange: (String) -> Void
    let onBackspaceOnEmpty: () -> Void
    @State private var pop: CGFloat = 1

    private static let marker = "\u{200B}"

    var body: some View {
        let border = borderOverride ?? (focused ? wa.accent : (wa.night ? wa.line : Color(hex: 0xFBD2C4)))
        let fill: Color = highlightFill && borderOverride != nil ? borderOverride!.opacity(0.05) : (wa.night ? wa.darkCard : .white)
        ZStack {
            RoundedRectangle(cornerRadius: 14, style: .continuous).fill(fill)
            RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(border, lineWidth: 1.5)
            Text(verbatim: value).font(.dorr(22, .semibold)).foregroundStyle(wa.night ? wa.ink : Color(hex: 0x111928))
            TextField("", text: Binding(
                get: { Self.marker + value },
                set: { typed in
                    guard typed.hasPrefix(Self.marker) else {
                        // Marker deleted → Backspace: clear this digit, or step back from an empty box.
                        if value.isEmpty { onBackspaceOnEmpty() } else { onChange("") }
                        return
                    }
                    let digit = String(asciiDigits(typed).suffix(1))
                    onChange(digit)
                    if !digit.isEmpty {
                        withAnimation(.linear(duration: 0.1)) { pop = 1.15 }
                        withAnimation(.linear(duration: 0.1).delay(0.1)) { pop = 1 }
                    }
                }
            ))
            .keyboardType(.numberPad)
            .textContentType(.oneTimeCode)
            .focused(focus, equals: index)
            .foregroundStyle(.clear)
            .tint(wa.red)
            .multilineTextAlignment(.center)
        }
        .frame(width: 44, height: 52)
        .scaleEffect(pop)
    }
}

/// The decorative shield with a white lock (Android draws the same path on a 64-unit grid).
struct OtpShield: View {
    @Environment(\.wa) private var wa
    var isError = false

    var body: some View {
        let badge = isError ? AppColors.danger : wa.red
        ZStack {
            Circle().stroke(badge.opacity(0.28), lineWidth: 2)
            Canvas { gc, size in
                let s = size.width / 64
                var shield = Path()
                shield.move(to: CGPoint(x: 32 * s, y: 6 * s))
                shield.addLine(to: CGPoint(x: 54 * s, y: 14.2 * s))
                shield.addLine(to: CGPoint(x: 54 * s, y: 32.8 * s))
                shield.addCurve(to: CGPoint(x: 32 * s, y: 60.4 * s), control1: CGPoint(x: 54 * s, y: 46 * s), control2: CGPoint(x: 45.4 * s, y: 55.6 * s))
                shield.addCurve(to: CGPoint(x: 10 * s, y: 32.8 * s), control1: CGPoint(x: 18.6 * s, y: 55.6 * s), control2: CGPoint(x: 10 * s, y: 46 * s))
                shield.addLine(to: CGPoint(x: 10 * s, y: 14.2 * s))
                shield.closeSubpath()
                gc.fill(shield, with: .color(badge))
                gc.fill(Path(roundedRect: CGRect(x: 23 * s, y: 31 * s, width: 18 * s, height: 13 * s), cornerRadius: 2.5 * s), with: .color(.white))
                var shackle = Path()
                shackle.move(to: CGPoint(x: 27 * s, y: 31.5 * s))
                shackle.addLine(to: CGPoint(x: 27 * s, y: 27.3 * s))
                shackle.addCurve(to: CGPoint(x: 32 * s, y: 22.3 * s), control1: CGPoint(x: 27 * s, y: 24.5 * s), control2: CGPoint(x: 29.2 * s, y: 22.3 * s))
                shackle.addCurve(to: CGPoint(x: 37 * s, y: 27.3 * s), control1: CGPoint(x: 34.8 * s, y: 22.3 * s), control2: CGPoint(x: 37 * s, y: 24.5 * s))
                shackle.addLine(to: CGPoint(x: 37 * s, y: 31.5 * s))
                gc.stroke(shackle, with: .color(.white), style: StrokeStyle(lineWidth: 2.6 * s, lineCap: .round))
            }
            .frame(width: 92, height: 92)
        }
        .animation(.easeInOut(duration: 0.6), value: isError)
    }
}
