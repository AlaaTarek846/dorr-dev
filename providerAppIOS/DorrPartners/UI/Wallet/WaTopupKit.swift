// providerApp `ui/wallet/WaTopupKit.kt`
import SwiftUI
import WebKit

/* The DORR app's top-up pieces (WalletTopup.kt), unchanged in look: the method cards, the quote strip, the OTP field and the
 * in-app layer for the test bank page. */

/// Payment gateway → icon + meaning.
func methodLook(_ gateway: String) -> (icon: String, tone: Tone) {
    switch gateway {
    case "myfatoorah": return ("creditcard.fill", .blue)
    case "arb": return ("building.columns.fill", .green)
    case "urpay": return ("phone.fill", .pink)
    case "sandbox": return ("testtube.2", .amber)
    default: return ("creditcard.fill", .gray)
    }
}

struct MethodCard: View {
    @Environment(\.wa) private var wa
    let method: PaymentMethodDto
    let selected: Bool
    let onClick: () -> Void

    var body: some View {
        let look = methodLook(method.gateway ?? "")
        let soon = method.comingSoon ?? false
        Button(action: onClick) {
            HStack(spacing: 10) {
                WaIconWell(icon: look.icon, tone: soon ? .gray : look.tone, size: 36, iconSize: 16)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: method.name ?? method.code ?? "").font(.dorr(13, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                    Text(L(soon ? "wa_method_soon_sub" : (method.gateway == "sandbox" ? "wa_method_sandbox_sub" : "wa_method_secure_sub")))
                        .font(.dorr(11)).foregroundStyle(wa.mut).lineLimit(1)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if soon {
                    Text(L("wa_soon_badge")).font(.dorr(10, .bold)).foregroundStyle(Color(hex: 0xB45309))
                        .padding(.horizontal, 8).padding(.vertical, 3)
                        .background(Color(hex: 0xFEF3C7), in: Capsule())
                } else {
                    ZStack {
                        Circle().fill(selected ? wa.red : .clear)
                        Circle().stroke(selected ? wa.red : (wa.night ? wa.line : Color(hex: 0xD1D5DB)), lineWidth: 1.5)
                        if selected { Image(systemName: "checkmark").font(.system(size: 10, weight: .heavy)).foregroundStyle(.white) }
                    }
                    .frame(width: 20, height: 20)
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .background(
                selected ? (wa.night ? wa.darkWell : wa.red.opacity(0.06)) : (soon ? (wa.night ? wa.surface : Color(hex: 0xF7F8FA)) : wa.surface),
                in: RoundedRectangle(cornerRadius: 16, style: .continuous)
            )
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(selected ? wa.red : wa.line, lineWidth: 1))
            .opacity(soon ? 0.72 : 1)
            .contentShape(Rectangle())
        }
        .buttonStyle(WaPressStyle(scale: 0.98))
        .padding(.bottom, 8)
    }
}

private struct QuoteStat: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String
    var valueColor: Color?
    var ltr = false

    var body: some View {
        VStack(spacing: 2) {
            Text(verbatim: label).font(.dorr(10, .medium)).foregroundStyle(wa.mut).lineLimit(1)
            Text(verbatim: ltr ? "\u{2066}\(value)\u{2069}" : value).font(.dorr(12, .semibold)).foregroundStyle(valueColor ?? wa.ink).lineLimit(1)
        }
        .frame(maxWidth: .infinity)
    }
}

struct QuoteCard: View {
    @Environment(\.wa) private var wa
    let amountMinor: Int
    let feeMinor: Int
    let bonusMinor: Int
    let currency: String
    let methodName: String?

    var body: some View {
        let free = feeMinor <= 0
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                QuoteStat(label: L("wa_quote_amount"), value: "\(money(amountMinor)) \(currency)", ltr: true)
                wa.line.opacity(0.7).frame(width: 1, height: 22)
                QuoteStat(label: L("wa_quote_fee_short"), value: free ? L("wa_quote_fee_free") : "− \(money(feeMinor)) \(currency)",
                          valueColor: free ? Color(hex: 0x059669) : nil)
                wa.line.opacity(0.7).frame(width: 1, height: 22)
                QuoteStat(label: L("wa_pay_method"), value: methodName ?? "—")
            }
            .padding(.horizontal, 8)
            .padding(.vertical, 10)
            .background(wa.night ? wa.darkWell : .white, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(wa.line, lineWidth: 1))
            if bonusMinor > 0 {
                HStack(spacing: 6) {
                    Image(systemName: "sparkles").font(.system(size: 10))
                    Text(verbatim: "\(L("wa_quote_bonus")) + \(money(bonusMinor)) \(currency)").font(.dorr(10))
                }
                .foregroundStyle(wa.mut)
                .padding(.top, 8)
            }
        }
        .padding(.bottom, 12)
    }
}

/// The SMS code of a gateway that confirms by code; the caller filters what is typed.
struct OtpField: View {
    @Environment(\.wa) private var wa
    @Binding var value: String

    var body: some View {
        ZStack {
            if value.isEmpty {
                Text(verbatim: "••••").font(.dorr(26)).kerning(10).foregroundStyle(wa.night ? wa.soft : Color(hex: 0xD1D5DB))
            }
            TextField("", text: $value)
                .font(.dorr(26, .heavy))
                .kerning(10)
                .foregroundStyle(wa.ink)
                .tint(wa.red)
                .multilineTextAlignment(.center)
                .keyboardType(.numberPad)
                .textContentType(.oneTimeCode)
        }
        .padding(14)
        .frame(maxWidth: .infinity)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(value.isEmpty ? wa.line : wa.red, lineWidth: 2))
        .padding(.horizontal, 30)
        .environment(\.layoutDirection, .leftToRight)
    }
}

/// The test bank (sandbox) inside the phone, like an in-app browser sliding up over the page; real gateways open in the browser.
struct GatewayLayer: View {
    @Environment(\.wa) private var wa
    let url: String?
    let onClose: () -> Void

    var body: some View {
        ZStack {
            if let url {
                // The layer takes every touch, so nothing reaches the page under it.
                VStack(spacing: 0) {
                    HStack(spacing: 6) {
                        Image(systemName: "lock.fill").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.ink)
                        Text(L("wa_secure_pay")).font(.dorr(16, .heavy)).foregroundStyle(wa.ink)
                        Spacer()
                        WaCircleButton(icon: "xmark", action: onClose)
                    }
                    .padding(.horizontal, 14)
                    .padding(.vertical, 12)
                    WaGatewayWebView(url: themedUrl(url), background: UIColor(wa.bg))
                }
                .background(wa.bg.ignoresSafeArea())
                .transition(.move(edge: .bottom).combined(with: .opacity))
                // While it is open the payment page gets the whole screen: the app's header and bottom bar step aside.
                .onAppear { ShellState.shared.immersive = true }
                .onDisappear { ShellState.shared.immersive = false }
            }
        }
        .animation(.easeOut(duration: 0.38), value: url)
    }

    /// The test bank page is ours, so it is drawn in the app's own colours: they travel in the query string (six hex digits
    /// each). A real gateway's URL is left untouched.
    private func themedUrl(_ url: String) -> String {
        guard url.contains("/sandbox/") else { return url }
        let theme = [("primary", wa.red), ("bg", wa.bg), ("surface", wa.surface), ("ink", wa.ink),
                     ("mut", wa.mut), ("soft", wa.soft), ("line", wa.line), ("field", wa.field)]
            .map { "\($0.0)=\($0.1.hex6)" }.joined(separator: "&")
        return url + (url.contains("?") ? "&" : "?") + theme + "&lang=" + AppLocale.current
    }
}

private struct WaGatewayWebView: UIViewRepresentable {
    let url: String
    let background: UIColor

    func makeUIView(context: Context) -> WKWebView {
        let view = WKWebView()
        // No white flash before the page paints.
        view.isOpaque = false
        view.backgroundColor = background
        view.scrollView.backgroundColor = background
        if let link = URL(string: url) { view.load(URLRequest(url: link)) }
        return view
    }

    func updateUIView(_ view: WKWebView, context: Context) {}
}
