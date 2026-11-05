import SwiftUI
import WebKit

// driverApp `wallet/WalletTopup.kt` (the DORR app's, without `WalletTopupLauncher.kt`, which the captain app doesn't have;
// ported from iosApp `UI/Wallet/WalletTopup.swift`).

private let quickAmounts = [50, 100, 200, 500]

/// Opens a payment page: the fake sandbox bank inside the app, a real gateway in the phone's browser.
@MainActor
func openGateway(_ url: String, gateway: String, inApp: (String) -> Void) {
    if gateway == "sandbox" {
        inApp(url)
    } else if let link = URL(string: url) {
        UIApplication.shared.open(link)
    }
}

/// androidApp `WalletTopup.kt` — top up: amount, payment method (gateways without credentials are listed as
/// "coming soon"), live quote, PIN, hand-off to the gateway, then a waiting / OTP / paid / failed result page.
/// The fake sandbox bank runs in an in-app browser layer; real gateways open in the phone's browser.
@MainActor
struct WalletTopup: View {
    @EnvironmentObject private var host: WalletHost
    @ObservedObject private var network = WalletReconnect.shared
    @Environment(\.wa) private var wa

    @State private var methods: [PaymentMethodDto]?
    @State private var methodId: Int?
    @State private var amountText: String
    @State private var quote: TopupQuoteDto?
    @State private var quoteError: String?
    @State private var screenError: String?
    /// Same key on a retry of the *same* request, so a dropped connection can't double-charge.
    @State private var idempotencyKey = UUID().uuidString
    /// Kept in memory only for this attempt (the OTP step needs it again); dropped when it settles.
    @State private var attemptPin: String?
    @State private var payment: TopupPaymentDto?
    @State private var gateway = ""
    @State private var browserUrl: String?
    @State private var otp = ""
    @State private var otpBusy = false

    init(initialAmount: String = "") {
        _amountText = State(initialValue: initialAmount)
    }

    var body: some View {
        let amountMinor = parseAmountToMinor(amountText)
        Group {
            if let current = payment {
                ZStack {
                    TopupStage(
                        payment: current, gateway: gateway, currency: host.balance?.currencyCode ?? "",
                        otp: $otp, otpBusy: otpBusy, error: screenError,
                        onConfirmOtp: { confirmOtp(current) },
                        onReopen: { current.redirectUrl.map { openGateway($0, gateway: gateway) { browserUrl = $0 } } },
                        onDone: { attemptPin = nil; host.pop() },
                        onStatement: { attemptPin = nil; host.pop(); host.push(.history) },
                        onRetry: { attemptPin = nil; payment = nil; otp = ""; screenError = nil; browserUrl = nil; idempotencyKey = UUID().uuidString },
                        onCancel: { attemptPin = nil; payment = nil; otp = ""; screenError = nil; browserUrl = nil },
                        onBack: { host.pop() }
                    )
                    GatewayLayer(url: browserUrl) { browserUrl = nil }
                }
            } else {
                form(amountMinor)
            }
        }
        .task(id: network.reconnectTick) { await loadMethods() }
        // Live quote, debounced so typing "100" doesn't fire three requests.
        .task(id: "\(amountMinor ?? -1)-\(methodId ?? -1)") { await loadQuote(amountMinor) }
        // After the hand-off, wait for the server to confirm.
        .task(id: payment?.uuid) { await watchPayment() }
    }

    private func form(_ amountMinor: Int?) -> some View {
        let currency = host.balance?.currencyCode ?? ""
        let paying = methods?.first { $0.id == methodId }
        let added = quote?.totalCreditedMinor ?? amountMinor ?? 0
        let expected = (host.balance?.totalMinor ?? 0) + added
        return WaPage(title: L("wa_topup_title"), onBack: { host.pop() }, ctaReserve: 220, ctaDock: true, cta: {
            QuoteCard(
                amountMinor: quote?.paidAmountMinor ?? amountMinor ?? 0,
                feeMinor: quote?.feeMinor ?? 0,
                bonusMinor: quote?.bonusMinor ?? 0,
                currency: currency,
                methodName: paying?.name ?? paying?.code
            )
            WaButton(text: L("wa_continue_pay"), icon: "chevron.left", enabled: quote != nil, iconAfter: true, spread: true) {
                startPayment(amountMinor, paying: paying)
            }
            WaCtaHint(text: L("wa_secure_payments"), icon: "shield.fill")
        }) {
            WaAmountEntry(
                label: L("wa_topup_how_much"), amountText: $amountText, currency: currency, quick: quickAmounts,
                invalid: !amountText.trimmingCharacters(in: .whitespaces).isEmpty && amountMinor == nil, hero: true,
                subtitle: L("wa_topup_how_much_sub"), footerLabel: L("wa_topup_expected"),
                footerValue: host.hideBalance ? "****" : "\(money(expected)) \(currency)"
            )
            .waRise(0)

            HStack(alignment: .center) {
                VStack(alignment: .leading, spacing: 1) {
                    Text(L("wa_pay_method")).font(.dorr(13, .bold)).foregroundStyle(wa.ink)
                    Text(L("wa_pay_method_sub")).font(.dorr(11)).foregroundStyle(wa.mut)
                }
                Spacer()
                if let count = methods?.count {
                    Text(L("wa_pay_methods_n", count)).font(.dorr(11)).foregroundStyle(wa.mut)
                }
            }
            .padding(.top, 18)
            .padding(.bottom, 8)
            .waRise(1)

            VStack(spacing: 0) {
                if let methods {
                    if methods.isEmpty {
                        WaCard { WaEmpty(icon: "creditcard.fill", title: L("wa_no_methods"), text: L("wa_no_methods_text")) }
                    } else {
                        ForEach(methods) { method in
                            MethodCard(method: method, selected: method.id == methodId) {
                                if method.comingSoon { host.showToast(L("wa_method_coming_soon_toast")) } else { methodId = method.id }
                            }
                        }
                    }
                } else {
                    ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 16).frame(height: 48).padding(.bottom, 8) }
                }
            }
            .waRise(2)

            WaError(message: quoteError)
            WaError(message: screenError)
        }
    }

    private func loadMethods() async {
        if host.balance == nil { await host.refreshBalance() }
        // No error card on failure: offline is covered by the app-wide screen and a reconnect reloads, so
        // methods stay nil (skeleton) until an attempt succeeds.
        guard let list = try? await WalletApi.paymentMethods().data else { return }
        methods = list
        methodId = list.first { !$0.comingSoon }?.id
    }

    private func loadQuote(_ amountMinor: Int?) async {
        quote = nil
        quoteError = nil
        guard let amountMinor, let id = methodId else { return }
        try? await Task.sleep(nanoseconds: 400_000_000)
        guard !Task.isCancelled else { return }
        do {
            quote = try await WalletApi.quote(TopupRequest(paymentMethodId: id, amountMinor: amountMinor)).data
        } catch {
            guard !Task.isCancelled else { return }
            quoteError = error.walletFailure.message ?? L("wa_error_network")
        }
    }

    /// Asks the server for the payment's state every 2 s while it is pending (at most 100 times), exactly as
    /// the Android screen does — the gateway confirms to the server, not to the phone.
    private func watchPayment() async {
        guard let uuid = payment?.uuid else { return }
        for _ in 0..<100 {
            guard payment?.status == "pending" else { return }
            try? await Task.sleep(nanoseconds: 2_000_000_000)
            guard !Task.isCancelled else { return }
            if let latest = try? await WalletApi.topup(uuid: uuid).data {
                if latest.status == "paid" && payment?.status != "paid" {
                    await host.refreshBalance()
                    browserUrl = nil
                }
                payment = latest
            }
        }
    }

    private func startPayment(_ amountMinor: Int?, paying: PaymentMethodDto?) {
        screenError = nil
        host.requestPin(subtitle: L("wa_pin_topup_sub"), onError: { screenError = $0 }) { pin in
            guard let id = methodId, let minor = amountMinor else { return .close(L("wa_error_generic")) }
            do {
                let started = try await WalletApi.startTopup(pin: pin, idempotencyKey: idempotencyKey, TopupRequest(paymentMethodId: id, amountMinor: minor)).data
                idempotencyKey = UUID().uuidString
                guard let started else { return .close(L("wa_error_generic")) }
                attemptPin = pin
                gateway = paying?.gateway ?? ""
                payment = started
                if let url = started.redirectUrl { openGateway(url, gateway: gateway) { browserUrl = $0 } }
                return .done
            } catch {
                let failure = error.walletFailure
                // No HTTP answer: keep the key so a retry is safe.
                if failure.httpStatus == nil { return .retry(L("wa_error_network")) }
                idempotencyKey = UUID().uuidString
                let message = failure.message ?? L("wa_error_generic")
                if let code = failure.errorCode, pinErrorCodes.contains(code) { return .retry(message) }
                return .close(message)
            }
        }
    }

    private func confirmOtp(_ current: TopupPaymentDto) {
        guard let pin = attemptPin else { return }
        otpBusy = true
        screenError = nil
        Task {
            defer { otpBusy = false }
            do {
                if let updated = try await WalletApi.confirmTopup(pin: pin, uuid: current.uuid, ConfirmOtpRequest(otp: otp)).data {
                    payment = updated
                    if updated.status == "paid" { await host.refreshBalance() }
                }
            } catch {
                screenError = error.walletFailure.message ?? L("wa_error_network")
            }
        }
    }
}

private struct MethodCard: View {
    @Environment(\.wa) private var wa
    let method: PaymentMethodDto
    let selected: Bool
    let action: () -> Void

    var body: some View {
        let look = methodLook(method.gateway)
        let soon = method.comingSoon
        Button(action: action) {
            HStack(spacing: 10) {
                WaIconWell(icon: look.icon, tone: soon ? .gray : look.tone, size: 36, iconSize: 16)
                VStack(alignment: .leading, spacing: 2) {
                    Text(method.name ?? method.code).font(.dorr(13, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                    Text(L(soon ? "wa_method_soon_sub" : (method.gateway == "sandbox" ? "wa_method_sandbox_sub" : "wa_method_secure_sub")))
                        .font(.dorr(11)).foregroundStyle(wa.mut).lineLimit(1)
                }
                Spacer(minLength: 0)
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
            Text(label).font(.dorr(10, .medium)).foregroundStyle(wa.mut).lineLimit(1)
            Text(ltr ? "\u{2066}\(value)\u{2069}" : value).font(.dorr(12, .semibold)).foregroundStyle(valueColor ?? wa.ink).lineLimit(1)
        }
        .frame(maxWidth: .infinity)
    }
}

private struct QuoteCard: View {
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

// MARK: - Result pages

struct TopupStage: View {
    @EnvironmentObject private var host: WalletHost
    @Environment(\.wa) private var wa
    let payment: TopupPaymentDto
    let gateway: String
    let currency: String
    @Binding var otp: String
    let otpBusy: Bool
    let error: String?
    let onConfirmOtp: () -> Void
    let onReopen: () -> Void
    let onDone: () -> Void
    let onStatement: () -> Void
    let onRetry: () -> Void
    let onCancel: () -> Void
    let onBack: () -> Void

    var body: some View {
        WaPage(title: L("wa_topup_title"), onBack: onBack, scroll: false) {
            if payment.status == "paid" {
                ZStack {
                    WaConfetti()
                    let credited = payment.amountMinor + payment.bonusMinor - payment.feeMinor
                    WaStatusColumn {
                        WaSeal()
                        WaStatusTitle(text: L("wa_topup_done_title"))
                        HStack(alignment: .lastTextBaseline, spacing: 6) {
                            Text(verbatim: "+ \(money(credited))").font(.dorr(34, .heavy)).kerning(-0.5).foregroundStyle(wa.ink)
                            Text(currency).font(.dorr(15, .bold)).foregroundStyle(wa.mut)
                        }
                        .environment(\.layoutDirection, .leftToRight)
                        WaCard(padding: 14) {
                            WaKeyValue(label: L("wa_recap_paid"), value: "\(money(payment.amountMinor)) \(currency)", ltr: true)
                            if payment.feeMinor > 0 {
                                WaDivider()
                                WaKeyValue(label: L("wa_quote_fee"), value: "− \(money(payment.feeMinor)) \(currency)", ltr: true)
                            }
                            if payment.bonusMinor > 0 {
                                WaDivider()
                                WaKeyValue(label: L("wa_recap_bonus"), value: "+ \(money(payment.bonusMinor)) \(currency)", ltr: true)
                            }
                            WaDivider()
                            WaKeyValue(label: L("wa_recap_balance_now"), value: host.balance.map { "\(money($0.totalMinor)) \(currency)" } ?? "…", ltr: true)
                        }
                        .padding(.top, 16)
                        .padding(.bottom, 6)
                        WaButton(text: L("wa_done"), action: onDone).padding(.top, 12)
                        WaButton(text: L("wa_view_statement"), style: .quiet, action: onStatement)
                    }
                }
            } else if payment.status == "pending" && payment.requiresOtp {
                WaStatusColumn {
                    WaPulse(icon: "phone.fill")
                    WaStatusTitle(text: L("wa_otp_title"))
                    WaStatusText(text: L("wa_otp_text"))
                    OtpField(value: $otp).padding(.top, 16)
                    WaError(message: error)
                    WaButton(text: L("wa_otp_confirm"), enabled: otp.count >= 4, loading: otpBusy, action: onConfirmOtp).padding(.top, 12)
                    WaButton(text: L("wa_cancel"), style: .quiet, action: onCancel)
                }
            } else if payment.status == "pending" {
                WaStatusColumn {
                    WaPulse(icon: gateway == "sandbox" ? "testtube.2" : "creditcard.fill")
                    HStack(spacing: 0) {
                        Text(L("wa_waiting_title")).font(.dorr(22, .heavy)).foregroundStyle(wa.ink)
                        WaLoadingDots()
                    }
                    .padding(.top, 18)
                    .padding(.bottom, 6)
                    WaStatusText(text: L("wa_waiting_text"))
                    if payment.redirectUrl != nil {
                        WaButton(text: L("wa_open_payment_page"), style: .ghost, icon: "arrow.up.forward.square", action: onReopen).padding(.top, 12)
                    }
                    WaButton(text: L("wa_cancel"), style: .quiet, action: onCancel)
                }
            } else {
                WaStatusColumn {
                    WaSeal(bad: true)
                    WaStatusTitle(text: L("wa_topup_failed_title"))
                    WaStatusText(text: L("wa_topup_failed_text"))
                    WaButton(text: L("wa_try_again"), icon: "arrow.clockwise", action: onRetry).padding(.top, 12)
                    WaButton(text: L("wa_back_to_wallet"), style: .quiet, action: onBack)
                }
            }
        }
    }
}

/// The gateway's SMS code (URPay): one wide field, digits spaced out.
struct OtpField: View {
    @Environment(\.wa) private var wa
    @Binding var value: String

    var body: some View {
        ZStack {
            if value.isEmpty {
                Text("••••••").font(.dorr(26)).kerning(10).foregroundStyle(wa.night ? wa.soft : Color(hex: 0xD1D5DB))
            }
            TextField("", text: $value)
                .font(.dorr(26, .heavy))
                .kerning(10)
                .foregroundStyle(wa.ink)
                .tint(wa.red)
                .multilineTextAlignment(.center)
                .keyboardType(.numberPad)
                .textContentType(.oneTimeCode)
                .onChange(of: value) { raw in
                    let digits = String(asciiDigits(raw).prefix(8))
                    if digits != raw { value = digits }
                }
        }
        .padding(14)
        .frame(maxWidth: .infinity)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(value.isEmpty ? wa.line : wa.red, lineWidth: 2))
        .padding(.horizontal, 30)
        .environment(\.layoutDirection, .leftToRight)
    }
}

/// The fake bank (sandbox) inside the phone, like an in-app browser sliding up over the page.
struct GatewayLayer: View {
    @Environment(\.wa) private var wa
    let url: String?
    let onClose: () -> Void

    var body: some View {
        ZStack {
            if let url {
                VStack(spacing: 0) {
                    HStack(spacing: 6) {
                        Image(systemName: "lock.fill").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.ink)
                        Text(L("wa_secure_pay")).font(.dorr(16, .heavy)).foregroundStyle(wa.ink)
                        Spacer()
                        WaCircleButton(icon: "xmark", action: onClose)
                    }
                    .padding(.horizontal, 14)
                    .padding(.vertical, 12)
                    GatewayWebView(url: themedUrl(url), background: UIColor(wa.bg))
                }
                .background(wa.bg.ignoresSafeArea())
                .transition(.move(edge: .bottom).combined(with: .opacity))
            }
        }
        .animation(.easeOut(duration: 0.38), value: url)
    }

    /// The test bank page is ours, so it is drawn in the app's own colours: they travel in the query string
    /// (six hex digits each). A real gateway's URL is left untouched — it must not get extra parameters.
    private func themedUrl(_ url: String) -> String {
        guard url.contains("/sandbox/") else { return url }
        let theme = [("primary", wa.red), ("bg", wa.bg), ("surface", wa.surface), ("ink", wa.ink),
                     ("mut", wa.mut), ("soft", wa.soft), ("line", wa.line), ("field", wa.field)]
            .map { "\($0.0)=\($0.1.hex6)" }.joined(separator: "&")
        // The page's language is the app's, so it matches the screens around it.
        return url + (url.contains("?") ? "&" : "?") + theme + "&lang=" + WalletLocale.current
    }
}

private struct GatewayWebView: UIViewRepresentable {
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
