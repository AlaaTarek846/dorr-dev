import SwiftUI

// driverApp `ui/OtpScreen.kt`.

private let codeLength = 4

/// The code step, in the DORR app's design: the shield, the boxes that pop and shake, the resend link.
@MainActor
struct OtpScreen: View {
    @Environment(\.pal) private var pal
    let dialCode: String
    let phone: String
    let maskedPhone: String
    let onBack: () -> Void
    let onSignedIn: () -> Void

    @State private var code = ""
    @State private var busy = false
    @State private var error: String?
    @State private var cooldown: Int

    init(dialCode: String, phone: String, maskedPhone: String, initialCooldown: Int, onBack: @escaping () -> Void,
         onSignedIn: @escaping () -> Void) {
        self.dialCode = dialCode
        self.phone = phone
        self.maskedPhone = maskedPhone
        self.onBack = onBack
        self.onSignedIn = onSignedIn
        _cooldown = State(initialValue: initialCooldown)
    }

    var body: some View {
        ZStack {
            PinkBackdrop()
            ScrollView {
                VStack(spacing: 0) {
                    HStack {
                        RoundIconButton("arrow.backward", L("back"), onClick: onBack)
                        Spacer(minLength: 0)
                    }
                    .frame(maxWidth: 440)
                    VSpace(8)

                    VStack(spacing: 0) {
                        OtpShield(isError: error != nil).frame(width: 118, height: 118)
                        VSpace(6)
                        Text(L("otp_title")).font(.dorr(26, .heavy)).foregroundStyle(pal.accent).multilineTextAlignment(.center)
                        VSpace(8)
                        Text(L("otp_subtitle", maskedPhone))
                            .font(.dorr(14)).foregroundStyle(pal.mut).lineSpacing(8)
                            .multilineTextAlignment(.center)
                            .frame(maxWidth: 280)
                        VSpace(24)

                        OtpBoxes(code: code, length: codeLength, error: error != nil, onChange: { value in
                            code = value
                            error = nil
                            if code.count == codeLength && !busy { verify() }
                        })

                        Banner(error).padding(.top, 14)

                        VSpace(24)
                        PrimaryButton(L("otp_verify"), enabled: code.count == codeLength, busy: busy, onClick: { verify() })

                        VSpace(18)
                        HStack {
                            Text(L("otp_change_number"))
                                .font(.dorr(14, .semibold)).foregroundStyle(pal.accent)
                                .padding(.vertical, 6)
                                .bounceClick(onClick: onBack)
                            Spacer(minLength: 0)
                            if cooldown > 0 {
                                Text(L("otp_resend_in", cooldown)).font(.dorr(12)).foregroundStyle(pal.faint)
                            } else {
                                Text(L("otp_resend"))
                                    .font(.dorr(14, .semibold)).foregroundStyle(pal.accent)
                                    .padding(.vertical, 6)
                                    .bounceClick { resend() }
                            }
                        }
                        .frame(maxWidth: .infinity)
                    }
                    .frame(maxWidth: 440)
                }
                .frame(maxWidth: .infinity)
                .padding(.horizontal, 24)
                .padding(.top, 10)
                .padding(.bottom, 28)
            }
            .scrollDismissesKeyboard(.interactively)
        }
        // The resend countdown runs on the phone's own clock — nothing is asked of the server meanwhile.
        .task(id: cooldown) {
            guard cooldown > 0 else { return }
            try? await Task.sleep(nanoseconds: 1_000_000_000)
            if Task.isCancelled { return }
            cooldown -= 1
        }
    }

    private func verify() {
        busy = true
        let typed = code
        Task {
            switch await DriverApi.verify(body: VerifyBody(dialCode: dialCode, phone: phone, code: typed)) {
            case let .ok(signed, _):
                if let token = signed.token, !token.isEmpty {
                    DriverBackend.shared.signIn(token)
                    onSignedIn()
                } else {
                    error = L("error_generic")
                    code = ""
                }
            case let .failed(e):
                error = e.displayMessage
                code = ""
            case .offline:
                error = L("error_offline")
            }
            busy = false
        }
    }

    private func resend() {
        Task {
            switch await DriverApi.resendOtp(body: PhoneBody(dialCode: dialCode, phone: phone)) {
            case let .ok(sent, _):
                cooldown = sent.resendCooldownSeconds ?? 60
                error = nil
            case let .failed(e):
                error = e.displayMessage
            case .offline:
                error = L("error_offline")
            }
        }
    }
}

/// The shield with a lock — turns red with a wrong code, and lands with a little spring when it first appears.
private struct OtpShield: View {
    @Environment(\.pal) private var pal
    let isError: Bool
    @State private var landed = false

    var body: some View {
        let badge = isError ? pal.danger : pal.accent
        ZStack {
            Circle().stroke(badge.opacity(0.28), lineWidth: 2).padding(1)
            Canvas { gc, size in
                let s = size.width / 64
                func p(_ x: CGFloat, _ y: CGFloat) -> CGPoint { CGPoint(x: x * s, y: y * s) }
                var shield = Path()
                shield.move(to: p(32, 6))
                shield.addLine(to: p(54, 14.2))
                shield.addLine(to: p(54, 32.8))
                shield.addCurve(to: p(32, 60.4), control1: p(54, 46), control2: p(45.4, 55.6))
                shield.addCurve(to: p(10, 32.8), control1: p(18.6, 55.6), control2: p(10, 46))
                shield.addLine(to: p(10, 14.2))
                shield.closeSubpath()
                gc.fill(shield, with: .color(badge))
                gc.fill(Path(roundedRect: CGRect(x: 23 * s, y: 31 * s, width: 18 * s, height: 13 * s), cornerRadius: 2.5 * s), with: .color(.white))
                var shackle = Path()
                shackle.move(to: p(27, 31.5))
                shackle.addLine(to: p(27, 27.3))
                shackle.addCurve(to: p(32, 22.3), control1: p(27, 24.5), control2: p(29.2, 22.3))
                shackle.addCurve(to: p(37, 27.3), control1: p(34.8, 22.3), control2: p(37, 24.5))
                shackle.addLine(to: p(37, 31.5))
                gc.stroke(shackle, with: .color(.white), style: StrokeStyle(lineWidth: 2.6 * s, lineCap: .round))
            }
            .frame(width: 92, height: 92)
        }
        // A drawing, never mirrored.
        .environment(\.layoutDirection, .leftToRight)
        .animation(.tween(600), value: isError)
        .scaleEffect(landed ? 1 : 0.7)
        .onAppear {
            // spring(DampingRatioMediumBouncy, StiffnessLow)
            withAnimation(.interpolatingSpring(mass: 1, stiffness: 200, damping: 14.1)) { landed = true }
        }
    }
}
