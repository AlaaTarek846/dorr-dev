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
        // The boxes' frames: the success green on the right code, the danger red on a wrong one (the admin's).
        let borderOverride: Color? = isSuccess ? AuthColors.success(wa) : (hasError ? AuthColors.danger(wa) : nil)
        let mut = AuthColors.mut(wa)
        ZStack {
            PinkBackdrop()
            ScrollView {
                VStack(spacing: 0) {
                    HStack {
                        Button {
                            router.restoreMode = false
                            router.go(.login)
                        } label: {
                            // The language button's look — a see-through circle, 34, with a soft frame — and a chevron in
                            // the app's colour (› in Arabic, ‹ in English: chevron.backward mirrors).
                            Image(systemName: "chevron.backward").font(.system(size: 15, weight: .bold))
                                .foregroundStyle(wa.night ? wa.ink : wa.red)
                                .frame(width: 34, height: 34)
                                .overlay(Circle().stroke(AuthColors.line(wa), lineWidth: 1))
                                .contentShape(Circle())
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel(L("common_back"))
                        Spacer()
                        LanguagePicker()
                    }
                    .frame(maxWidth: 440)

                    VStack(spacing: 0) {
                        OtpShield(isError: hasError, restore: router.restoreMode).frame(width: 88, height: 88)
                        // A deleted account: «Restore your account» / «Your account was deleted. Enter the 4-digit code…».
                        Text(L(router.restoreMode ? "account_restore_title" : "otp_title")).font(.dorr(22, .heavy)).foregroundStyle(wa.red).multilineTextAlignment(.center).padding(.top, 18)
                        // «Enter the 4-digit code we sent to», then the number on its own line, grouped like the sign-in.
                        Text(L(router.restoreMode ? "otp_restore_subtitle" : "otp_subtitle", String(otpLength))).font(.dorr(14)).foregroundStyle(mut)
                            .multilineTextAlignment(.center).lineSpacing(5).frame(maxWidth: 320).padding(.top, 4)
                        let formattedPhone = [router.pendingDialCode, groupPhone(router.pendingPhone, phoneGroups(router.pendingPhone.count))]
                            .filter { !$0.isEmpty }.joined(separator: " ")
                        Text(verbatim: formattedPhone).font(.dorr(16, .bold)).foregroundStyle(wa.night ? wa.ink : wa.red)
                            .environment(\.layoutDirection, .leftToRight)
                            .padding(.top, 2)

                        // OTP digits: always left to right, centred.
                        HStack(spacing: 12) {
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
                        .padding(.top, 20)

                        // What went wrong, right under the boxes: a red line with its icon (the danger red), until the
                        // next digit.
                        if let errorMessage {
                            let bad = AuthColors.onStatus(AuthColors.danger(wa), night: wa.night)
                            HStack(spacing: 6) {
                                Image(systemName: "exclamationmark.circle").font(.system(size: 14, weight: .semibold))
                                Text(errorMessage).font(.dorr(12.5, .medium)).multilineTextAlignment(.center)
                            }
                            .foregroundStyle(bad)
                            .padding(.top, 10)
                            .transition(.opacity)
                        }

                        // Until real SMS is wired: the code to use, small, under the boxes.
                        Text(L("otp_dev_hint")).font(.dorr(12, .bold)).foregroundStyle(mut).multilineTextAlignment(.center)
                            .padding(.top, 10)

                        // «Verify»: filled in the app's colour once the code is complete; before that the sign-in's soft
                        // fill with grey words; while checking, the app's colour with a spinner.
                        let ready = code.count == otpLength
                        Button(action: verify) {
                            ZStack {
                                if isVerifying { ProgressView().tint(.white) } else { Text(L("otp_verify")).font(.dorr(16, .bold)) }
                            }
                            .foregroundStyle(ready || isVerifying ? .white : AuthColors.soft(wa))
                            .frame(maxWidth: .infinity, minHeight: 52)
                            .background(ready || isVerifying ? wa.red : loginSoftFill(wa, 0.6), in: Capsule())
                            .shadow(color: ready && !isVerifying ? wa.red.opacity(0.4) : .clear, radius: 8, y: 4)
                        }
                        .buttonStyle(.plain)
                        .allowsHitTesting(!isVerifying && ready)
                        .padding(.top, 20)

                        // «Didn't get the code? You can resend in 0:17» (the time bold, in the app's colour, on the
                        // device clock), then «Didn't get the code? Resend» — «Resend» bold in the app's accent.
                        TimelineView(.periodic(from: .now, by: 1)) { context in
                            let left = Int(ceil(resendAvailableAt.timeIntervalSince(context.date)))
                            HStack(spacing: 6) {
                                Text(L("otp_resend_question")).font(.dorr(13.5)).foregroundStyle(mut)
                                if left > 0 {
                                    Text(L("otp_resend_in")).font(.dorr(13.5)).foregroundStyle(mut)
                                    Text(verbatim: String(format: "%d:%02d", left / 60, left % 60)).font(.dorr(13.5, .bold))
                                        .foregroundStyle(wa.night ? wa.ink : wa.red)
                                } else {
                                    Button(action: resend) {
                                        Text(L("otp_resend")).font(.dorr(13.5, .bold))
                                            .foregroundStyle(wa.night ? wa.ink : wa.red)
                                            .padding(.horizontal, 4).padding(.vertical, 2)
                                    }
                                    .buttonStyle(.plain)
                                }
                            }
                        }
                        .padding(.top, 14)


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
            // A server message is in the language it was asked in: switching language drops it.
            .onChange(of: router.locale) { _ in
                errorMessage = nil
                hasError = false
            }
        }
        .onAppear { focusedBox = 0 }
    }

    private func typed(_ index: Int, _ digit: String) {
        digits[index] = digit
        guard !digit.isEmpty else { return }
        hasError = false
        errorMessage = nil
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
                // Then the boxes empty and the first one takes the cursor, ready for the code again (the message stays
                // until the next digit is typed).
                try? await Task.sleep(nanoseconds: 700_000_000)
                digits = Array(repeating: "", count: otpLength)
                hasError = false
                focusedBox = 0
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

/// One code box: 56×60, rounded 16, the soft frame (the app's colour on the focused one), a single centred digit that pops when typed. A zero-width marker
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
        let border = borderOverride ?? (focused ? wa.accent : AuthColors.line(wa))
        let fill: Color = highlightFill && borderOverride != nil ? borderOverride!.opacity(0.05) : AuthColors.card(wa)
        ZStack {
            RoundedRectangle(cornerRadius: 16, style: .continuous).fill(fill)
            RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(border, lineWidth: 1.5)
            Text(verbatim: value).font(.dorr(22, .semibold)).foregroundStyle(AuthColors.ink(wa))
            // «_» blinking under the focused empty box (the design's cursor) — the system cursor is hidden below.
            if focused && value.isEmpty {
                TimelineView(.periodic(from: .now, by: 0.5)) { context in
                    let on = Int(context.date.timeIntervalSinceReferenceDate * 2) % 2 == 0
                    Capsule().fill(wa.red).frame(width: 20, height: 2.5).opacity(on ? 1 : 0)
                }
                .frame(maxHeight: .infinity, alignment: .bottom)
                .padding(.bottom, 16)
            }
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
            .tint(.clear)
            .multilineTextAlignment(.center)
        }
        .frame(width: 56, height: 60)
        .scaleEffect(pop)
    }
}

/// androidApp `OtpShield` — the shield with a white lock (the same path on a 64-unit grid), on a rounded square of the
/// same colour, faint (no ring): the app's main colour, or the danger red (shield and square together) on a wrong code.
struct OtpShield: View {
    @Environment(\.wa) private var wa
    var isError = false
    /// A deleted account being restored: a white restore icon (a clock with an arrow) inside the shield, in place of
    /// the lock.
    var restore = false

    var body: some View {
        // A wrong code turns the shield the danger red (the admin's).
        let badge = isError ? AuthColors.danger(wa) : wa.red
        ZStack {
            RoundedRectangle(cornerRadius: 26, style: .continuous).fill(badge.opacity(wa.night ? 0.16 : 0.10))
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
                // A deleted account: the restore icon goes in the shield instead of the lock (drawn over the canvas).
                guard !restore else { return }
                gc.fill(Path(roundedRect: CGRect(x: 23 * s, y: 31 * s, width: 18 * s, height: 13 * s), cornerRadius: 2.5 * s), with: .color(.white))
                var shackle = Path()
                shackle.move(to: CGPoint(x: 27 * s, y: 31.5 * s))
                shackle.addLine(to: CGPoint(x: 27 * s, y: 27.3 * s))
                shackle.addCurve(to: CGPoint(x: 32 * s, y: 22.3 * s), control1: CGPoint(x: 27 * s, y: 24.5 * s), control2: CGPoint(x: 29.2 * s, y: 22.3 * s))
                shackle.addCurve(to: CGPoint(x: 37 * s, y: 27.3 * s), control1: CGPoint(x: 34.8 * s, y: 22.3 * s), control2: CGPoint(x: 37 * s, y: 24.5 * s))
                shackle.addLine(to: CGPoint(x: 37 * s, y: 31.5 * s))
                gc.stroke(shackle, with: .color(.white), style: StrokeStyle(lineWidth: 2.6 * s, lineCap: .round))
            }
            .frame(width: 76, height: 76)
            if restore {
                Image(systemName: "clock.arrow.circlepath").font(.system(size: 21, weight: .semibold)).foregroundStyle(.white)
                    .offset(y: -1)
            }
        }
        .frame(width: 88, height: 88)
        .animation(.easeInOut(duration: 0.6), value: isError)
    }
}
