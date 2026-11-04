import SwiftUI

// driverApp `wallet/WalletCheckout.kt` (the DORR app's, coupons always offered — the captain's checkout has no
// `coupons_allowed`; ported from iosApp `UI/Wallet/WalletCheckout.swift`).

// MARK: - Launching it

/// Something to pay for: a purpose the server knows, and what it's about (the server prices it).
struct CheckoutRequest: Equatable {
    let purpose: String
    let reference: [String: WalletJSONValue]
}

/// Survives leaving the page (to top up) and coming back: it lives in the route, not the page.
@MainActor
final class CheckoutState: ObservableObject {
    let request: CheckoutRequest
    @Published var checkout: CheckoutDto?
    @Published var loadError: String?
    /// "wallet" or a payment method id.
    @Published var choice: String?
    @Published var error: String?
    @Published var busy = false
    @Published var idempotencyKey = UUID().uuidString
    /// Kept in memory for this attempt only (the OTP step needs it again).
    var attemptPin: String?
    @Published var otp = ""
    @Published var browserUrl: String?
    var onPaid: () -> Void = {}

    init(request: CheckoutRequest) {
        self.request = request
    }
}

/// The one payment screen, opened from anywhere in the app (a portal's listing, a channel's verification,
/// a card ride…): `CheckoutLauncher.shared.open(request) { /* paid */ }`. It slides up over the current
/// screen (CheckoutOverlay, in MainScreen) and calls back once paid.
@MainActor
final class CheckoutLauncher: ObservableObject {
    static let shared = CheckoutLauncher()

    struct Pending: Identifiable {
        let id = UUID()
        let request: CheckoutRequest
        let onPaid: () -> Void
    }

    @Published private(set) var pending: Pending?

    func open(_ request: CheckoutRequest, onPaid: @escaping () -> Void = {}) {
        pending = Pending(request: request, onPaid: onPaid)
    }

    func close() { pending = nil }
}

/// Drawn once, over the main screen: the payment screen while one is open.
@MainActor
struct CheckoutOverlay: View {
    @ObservedObject private var launcher = CheckoutLauncher.shared

    var body: some View {
        ZStack {
            if let pending = launcher.pending {
                // A new request is a new visit (its own page stack, its own state).
                CheckoutScreen(request: pending.request, onExit: { launcher.close() }, onPaid: { pending.onPaid(); launcher.close() })
                    .id(pending.id)
                    .transition(.move(edge: .bottom).combined(with: .opacity))
            }
        }
        .animation(.easeOut(duration: 0.42), value: launcher.pending?.id)
    }
}

/// A wallet visit that starts on the payment page instead of the wallet's home (no PIN gate — the PIN is
/// asked when paying). Topping up from here pushes the top-up page on the same stack, so finishing the
/// top-up lands back on this payment page.
@MainActor
struct CheckoutScreen: View {
    @StateObject private var host: WalletHost
    @StateObject private var state: CheckoutState

    init(request: CheckoutRequest, onExit: @escaping () -> Void, onPaid: @escaping () -> Void) {
        let state = CheckoutState(request: request)
        state.onPaid = onPaid
        _state = StateObject(wrappedValue: state)
        _host = StateObject(wrappedValue: WalletHost(start: .checkout(state), onExit: onExit))
    }

    var body: some View {
        WalletChrome(host: host) {
            WalletPagesFor(host: host)
            WaSheetHost(host: host)
        }
    }
}

// MARK: - The page

@MainActor
struct WalletCheckout: View {
    @EnvironmentObject private var host: WalletHost
    @Environment(\.wa) private var wa
    @ObservedObject var state: CheckoutState

    var body: some View {
        content
            // First look, and again each time the page comes back (after a top-up).
            .task { await firstLook() }
            // Handed to a gateway: wait for the server to settle it.
            .task(id: "\(state.checkout?.payment?.uuid ?? "")-\(state.checkout?.status ?? "")") { await watch() }
    }

    @ViewBuilder
    private var content: some View {
        if let checkout = state.checkout {
            if checkout.status == "paid" {
                CheckoutPaid(checkout: checkout) { state.onPaid() }
            } else if let payment = checkout.payment, checkout.status == "pending", payment.status == "pending" {
                ZStack {
                    CheckoutWaiting(state: state, payment: payment, onConfirmOtp: { confirmOtp(checkout, payment) },
                                    onReopen: { payment.redirectUrl.map { reopen($0, checkout) } }, onCancel: { host.pop() })
                    GatewayLayer(url: state.browserUrl) { state.browserUrl = nil }
                }
            } else if checkout.status == "expired" || checkout.status == "failed" || ["failed", "expired"].contains(checkout.payment?.status ?? "") {
                CheckoutFailed(checkout: checkout, onRetry: retry, onBack: { host.pop() })
            } else {
                payPage(checkout)
            }
        } else {
            WaPage(title: L("wa_checkout_title"), onBack: { host.pop() }) {
                if let loadError = state.loadError {
                    WaError(message: loadError)
                } else {
                    WaSkeleton(cornerRadius: 22).frame(height: 150)
                    Spacer().frame(height: 14)
                    ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 20).frame(height: 60).padding(.bottom, 10) }
                }
            }
        }
    }

    private func payPage(_ checkout: CheckoutDto) -> some View {
        let method = checkout.paymentMethods.first { String($0.id) == state.choice }
        let payWithWallet = state.choice == "wallet"
        let payable = checkout.payableMinor ?? checkout.amountMinor
        let charge = payWithWallet ? payable : (method?.chargeMinor ?? payable)
        let canPay = (payWithWallet && checkout.wallet.enough) || (method.map { $0.available && !$0.comingSoon } ?? false)
        return WaPage(title: L("wa_checkout_title"), onBack: { host.pop() }, cta: {
            WaButton(text: L("wa_pay_amount", money(charge), checkout.currencyCode ?? ""), icon: "lock.fill",
                     enabled: canPay && !state.busy, loading: state.busy) { pay(checkout, method: method, payWithWallet: payWithWallet) }
            WaCtaHint(text: L("wa_secure_payments"), icon: "shield.fill")
        }) {
            OrderCard(checkout: checkout).waRise(0)
            CouponBox(checkout: checkout) { state.checkout = $0 }.padding(.top, 12).waRise(1)
            WaSectionTitle(title: L("wa_checkout_from_wallet")).waRise(1)
            WalletChoice(checkout: checkout, selected: payWithWallet, onSelect: { state.choice = "wallet" },
                         onTopUp: { host.push(.topupAmount(checkout.wallet.shortfallMinor)) })
                .waRise(2)
            if !checkout.paymentMethods.isEmpty {
                WaSectionTitle(title: L("wa_checkout_other_ways")).waRise(3)
                VStack(spacing: 0) {
                    ForEach(checkout.paymentMethods) { m in
                        MethodChoice(method: m, currency: checkout.currencyCode ?? "", selected: state.choice == String(m.id)) {
                            if m.comingSoon || !m.available { host.showToast(L("wa_method_coming_soon_toast")) } else { state.choice = String(m.id) }
                        }
                    }
                }
                .waRise(4)
                if let method, let chargeMinor = method.chargeMinor, chargeMinor > payable {
                    WaNote(text: L("wa_checkout_fee_note", money(chargeMinor - payable), checkout.currencyCode ?? ""))
                }
            }
            WaError(message: state.error)
        }
    }

    private func firstLook() async {
        await host.refreshBalance()
        do {
            let fresh: CheckoutDto?
            if let existing = state.checkout {
                fresh = try await WalletApi.checkout(uuid: existing.id).data
            } else {
                fresh = try await WalletApi.createCheckout(CheckoutCreateRequest(purpose: state.request.purpose, reference: state.request.reference)).data
            }
            guard let fresh else { return }
            state.checkout = fresh
            state.loadError = nil
            // Pick the wallet when it covers it, else the first method that can take it.
            if state.choice == nil || (state.choice == "wallet" && !fresh.wallet.enough) {
                state.choice = fresh.wallet.enough ? "wallet" : (fresh.paymentMethods.first { $0.available && !$0.comingSoon }.map { String($0.id) } ?? "wallet")
            }
        } catch {
            state.loadError = error.walletFailure.message ?? L("wa_error_network")
        }
    }

    /// Asks the server every 2 s while a gateway payment is pending (at most 150 times), as on Android.
    private func watch() async {
        guard let current = state.checkout, current.status == "pending", let payment = current.payment, payment.status == "pending" else { return }
        for _ in 0..<150 {
            try? await Task.sleep(nanoseconds: 2_000_000_000)
            guard !Task.isCancelled else { return }
            guard let fresh = try? await WalletApi.checkout(uuid: current.id).data else { continue }
            state.checkout = fresh
            if fresh.status != "pending" || fresh.payment?.status != "pending" {
                state.browserUrl = nil
                await host.refreshBalance()
                return
            }
        }
    }

    private func reopen(_ url: String, _ checkout: CheckoutDto) {
        let gateway = checkout.paymentMethods.first { String($0.id) == state.choice }?.gateway ?? ""
        openGateway(url, gateway: gateway) { state.browserUrl = $0 }
    }

    private func pay(_ checkout: CheckoutDto, method: CheckoutMethodDto?, payWithWallet: Bool) {
        state.error = nil
        host.requestPin(subtitle: L("wa_checkout_pin_sub"), onError: { state.error = $0 }) { pin in
            do {
                guard let paid = try await WalletApi.payCheckout(
                    pin: pin,
                    idempotencyKey: payWithWallet ? nil : state.idempotencyKey,
                    uuid: checkout.id,
                    payWithWallet ? CheckoutPayRequest(method: "wallet") : CheckoutPayRequest(method: "gateway", paymentMethodId: method?.id)
                ).data else { return .close(L("wa_error_generic")) }
                state.attemptPin = pin
                state.checkout = paid
                await host.refreshBalance()
                if paid.status == "pending", let url = paid.payment?.redirectUrl { reopen(url, paid) }
                return .done
            } catch {
                let failure = error.walletFailure
                if failure.httpStatus == nil { return .retry(L("wa_error_network")) }
                state.idempotencyKey = UUID().uuidString
                let message = failure.message ?? L("wa_error_generic")
                if let code = failure.errorCode, pinErrorCodes.contains(code) { return .retry(message) }
                return .close(message)
            }
        }
    }

    private func confirmOtp(_ checkout: CheckoutDto, _ payment: TopupPaymentDto) {
        guard let pin = state.attemptPin else { return }
        state.busy = true
        state.error = nil
        Task {
            do {
                _ = try await WalletApi.confirmTopup(pin: pin, uuid: payment.uuid, ConfirmOtpRequest(otp: state.otp))
                if let fresh = try? await WalletApi.checkout(uuid: checkout.id).data { state.checkout = fresh }
            } catch {
                state.error = error.walletFailure.message ?? L("wa_error_network")
            }
            state.busy = false
        }
    }

    /// A fresh payment page for the same thing.
    private func retry() {
        state.checkout = nil
        state.choice = nil
        state.error = nil
        state.idempotencyKey = UUID().uuidString
        Task {
            do {
                state.checkout = try await WalletApi.createCheckout(CheckoutCreateRequest(purpose: state.request.purpose, reference: state.request.reference)).data
            } catch {
                state.loadError = error.walletFailure.message ?? L("wa_error_network")
            }
        }
    }
}

// MARK: - Pieces

private struct OrderCard: View {
    @Environment(\.wa) private var wa
    let checkout: CheckoutDto

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 12) {
                Image(systemName: "doc.plaintext.fill").font(.system(size: 18, weight: .semibold)).foregroundStyle(.white)
                    .frame(width: 40, height: 40).background(Color.white.opacity(0.16), in: Circle())
                VStack(alignment: .leading, spacing: 0) {
                    Text(checkout.title).font(.dorr(16, .heavy)).foregroundStyle(.white).lineLimit(2)
                    if let subtitle = checkout.subtitle { Text(subtitle).font(.dorr(13, .semibold)).foregroundStyle(.white.opacity(0.78)) }
                }
                Spacer(minLength: 0)
            }
            Text(L("wa_checkout_total")).font(.dorr(12, .semibold)).foregroundStyle(.white.opacity(0.7)).padding(.top, 18)
            HStack(alignment: .lastTextBaseline, spacing: 6) {
                if checkout.discountMinor > 0 {
                    Text(money(checkout.amountMinor)).font(.dorr(16, .bold)).strikethrough().foregroundStyle(.white.opacity(0.6))
                }
                Text(money(checkout.payableMinor ?? checkout.amountMinor)).font(.dorr(34, .heavy)).kerning(-0.5).foregroundStyle(.white)
                Text(checkout.currencyCode ?? "").font(.dorr(15, .bold)).foregroundStyle(.white.opacity(0.8))
            }
            .environment(\.layoutDirection, .leftToRight)
        }
        .padding(20)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.heroGradient, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
    }
}

private struct ChoiceRow<Content: View>: View {
    @Environment(\.wa) private var wa
    let selected: Bool
    let action: () -> Void
    @ViewBuilder let content: () -> Content

    var body: some View {
        Button(action: action) {
            HStack(spacing: 12) {
                ZStack {
                    Circle().stroke(selected ? wa.red : wa.line, lineWidth: 2)
                    if selected { Circle().fill(wa.red).frame(width: 12, height: 12) }
                }
                .frame(width: 22, height: 22)
                HStack(spacing: 0, content: content)
            }
            .padding(14)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(selected ? wa.red : wa.line, lineWidth: selected ? 2 : 1))
            .animation(.easeOut(duration: 0.2), value: selected)
        }
        .buttonStyle(WaPressStyle(scale: 0.98))
    }
}

private struct WalletChoice: View {
    @Environment(\.wa) private var wa
    let checkout: CheckoutDto
    let selected: Bool
    let onSelect: () -> Void
    let onTopUp: () -> Void

    var body: some View {
        let currency = checkout.currencyCode ?? ""
        let enough = checkout.wallet.enough
        ChoiceRow(selected: selected, action: onSelect) {
            WaIconWell(icon: "wallet.pass.fill", tone: enough ? .green : .amber)
            VStack(alignment: .leading, spacing: 0) {
                Text(L("wa_checkout_wallet")).font(.dorr(15, .bold)).foregroundStyle(wa.ink)
                Text(L("wa_checkout_balance", money(checkout.wallet.availableMinor), currency)).font(.dorr(13, .semibold))
                    .foregroundStyle(enough ? wa.mut : wa.danger)
                if !enough {
                    Text(L("wa_checkout_short", money(checkout.wallet.shortfallMinor), currency)).font(.dorr(12)).foregroundStyle(wa.mut)
                }
            }
            .padding(.leading, 12)
            Spacer(minLength: 8)
            // Top up from here: the top-up page opens on this stack and comes back to this page.
            Button(action: onTopUp) {
                HStack(spacing: 4) {
                    Image(systemName: "plus").font(.system(size: 13, weight: .bold))
                    Text(L("wa_checkout_top_up")).font(.dorr(13, .bold))
                }
                .foregroundStyle(wa.red)
                .padding(.horizontal, 12)
                .padding(.vertical, 8)
                .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            .buttonStyle(WaPressStyle(scale: 0.94))
        }
    }
}

private struct MethodChoice: View {
    @Environment(\.wa) private var wa
    let method: CheckoutMethodDto
    let currency: String
    let selected: Bool
    let action: () -> Void

    var body: some View {
        let usable = method.available && !method.comingSoon
        let look = methodLook(method.gateway)
        ChoiceRow(selected: selected, action: action) {
            if let logo = method.logoUrl {
                AsyncImage(url: Api.mediaUrl(logo)) { phase in
                    if let image = phase.image { image.resizable().scaledToFill() } else { Image(systemName: "square.grid.2x2").foregroundStyle(.secondary) }
                }
                .frame(width: 44, height: 44).background(.white).clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
            } else {
                WaIconWell(icon: look.icon, tone: look.tone)
            }
            VStack(alignment: .leading, spacing: 0) {
                Text(method.name ?? method.code).font(.dorr(15, .bold)).foregroundStyle(usable ? wa.ink : wa.soft)
                Text(method.comingSoon ? L("wa_method_soon_sub") : (!method.available ? L("wa_checkout_method_cant") : L("wa_checkout_charged", money(method.chargeMinor ?? 0), currency)))
                    .font(.dorr(12)).foregroundStyle(wa.mut)
            }
            .padding(.leading, 12)
            Spacer(minLength: 0)
        }
        .padding(.bottom, 10)
    }
}

private struct CheckoutWaiting: View {
    @ObservedObject var state: CheckoutState
    @Environment(\.wa) private var wa
    let payment: TopupPaymentDto
    let onConfirmOtp: () -> Void
    let onReopen: () -> Void
    let onCancel: () -> Void

    var body: some View {
        WaPage(title: L("wa_checkout_title"), onBack: onCancel, scroll: false) {
            if payment.requiresOtp {
                WaStatusColumn {
                    WaPulse(icon: "phone.fill")
                    WaStatusTitle(text: L("wa_otp_title"))
                    WaStatusText(text: L("wa_otp_text"))
                    OtpField(value: $state.otp).padding(.top, 16)
                    WaError(message: state.error)
                    WaButton(text: L("wa_otp_confirm"), enabled: state.otp.count >= 4, loading: state.busy, action: onConfirmOtp).padding(.top, 12)
                    WaButton(text: L("wa_cancel"), style: .quiet, action: onCancel)
                }
            } else {
                WaStatusColumn {
                    WaPulse(icon: "creditcard.fill")
                    HStack(spacing: 0) {
                        Text(L("wa_waiting_title")).font(.dorr(22, .heavy)).foregroundStyle(wa.ink)
                        WaLoadingDots()
                    }
                    .padding(.top, 18)
                    .padding(.bottom, 6)
                    WaStatusText(text: L("wa_checkout_waiting_text"))
                    if payment.redirectUrl != nil {
                        WaButton(text: L("wa_open_payment_page"), style: .ghost, icon: "arrow.up.forward.square", action: onReopen).padding(.top, 12)
                    }
                    WaButton(text: L("wa_checkout_later"), style: .quiet, action: onCancel)
                }
            }
        }
    }
}

private struct CheckoutPaid: View {
    let checkout: CheckoutDto
    let onDone: () -> Void

    var body: some View {
        WaPage(title: L("wa_checkout_title"), onBack: onDone, scroll: false) {
            ZStack {
                WaConfetti()
                WaStatusColumn {
                    WaSeal()
                    WaStatusTitle(text: L("wa_checkout_paid_title"))
                    WaStatusText(text: checkout.title)
                    WaCard(padding: 14) {
                        WaKeyValue(label: L("wa_recap_paid"), value: "\(money(checkout.amountMinor)) \(checkout.currencyCode ?? "")", bold: true, ltr: true)
                        if let subtitle = checkout.subtitle {
                            WaDivider()
                            WaKeyValue(label: L("wa_checkout_package"), value: subtitle)
                        }
                        WaDivider()
                        WaKeyValue(label: L("wa_checkout_paid_with"), value: L(checkout.paidVia == "gateway" ? "wa_checkout_paid_card" : "wa_checkout_wallet"))
                    }
                    .padding(.top, 16)
                    .padding(.bottom, 6)
                    WaButton(text: L("wa_done"), icon: "checkmark.circle.fill", action: onDone).padding(.top, 12)
                }
            }
        }
    }
}

private struct CheckoutFailed: View {
    let checkout: CheckoutDto
    let onRetry: () -> Void
    let onBack: () -> Void

    var body: some View {
        WaPage(title: L("wa_checkout_title"), onBack: onBack, scroll: false) {
            WaStatusColumn {
                WaSeal(bad: true)
                WaStatusTitle(text: L(checkout.status == "expired" ? "wa_checkout_expired_title" : "wa_checkout_failed_title"))
                WaStatusText(text: L(checkout.status == "failed" ? "wa_checkout_failed_in_wallet" : "wa_topup_failed_text"))
                WaButton(text: L("wa_try_again"), icon: "arrow.clockwise", action: onRetry).padding(.top, 12)
                WaButton(text: L("wa_cancel"), style: .quiet, action: onBack)
            }
        }
    }
}

/// A coupon on this payment: type a code, or tap one of mine (a DORR Sports prize…).
@MainActor
private struct CouponBox: View {
    @Environment(\.wa) private var wa
    let checkout: CheckoutDto
    let onChanged: (CheckoutDto) -> Void
    @State private var code = ""
    @State private var mine: [WalletCouponDto] = []
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        Group {
            if let applied = checkout.coupon {
                HStack(spacing: 10) {
                    Text("🎟️").font(.system(size: 20))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(applied.code ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.ink)
                        Text(L("wa_coupon_saved", money(applied.discountMinor), checkout.currencyCode ?? "")).font(.dorr(12.5, .bold)).foregroundStyle(wa.green)
                    }
                    Spacer(minLength: 0)
                    Button {
                        busy = true
                        Task {
                            if let updated = try? await WalletApi.removeCoupon(uuid: checkout.id).data { onChanged(updated) }
                            busy = false
                        }
                    } label: {
                        Text(L("wa_coupon_remove")).font(.dorr(13, .bold)).foregroundStyle(wa.danger).padding(8)
                    }
                    .buttonStyle(.plain)
                    .disabled(busy)
                }
                .padding(.horizontal, 14)
                .padding(.vertical, 12)
                .background(wa.green.opacity(0.10), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            } else {
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 8) {
                        WaTextField(value: Binding(get: { code }, set: { code = String($0.prefix(40)).uppercased() }), placeholder: L("wa_coupon_hint"))
                        WaButton(text: L("wa_coupon_apply"), style: .ghost, enabled: code.count >= 4 && !busy, loading: busy) { apply(code) }
                            .frame(width: 110)
                    }
                    if !mine.isEmpty {
                        HStack(spacing: 6) {
                            ForEach(Array(mine.prefix(3).enumerated()), id: \.offset) { _, c in
                                Button { apply(c.code) } label: {
                                    Text("🎟️ " + (c.kind == "percent" ? "\(c.value)%" : "\(money(c.value)) \(c.currencyCode ?? "")"))
                                        .font(.dorr(12.5, .bold)).foregroundStyle(wa.ink)
                                        .padding(.horizontal, 12).padding(.vertical, 7)
                                        .background(wa.surface, in: Capsule())
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .padding(.top, 8)
                    }
                    if let error { Text(error).font(.dorr(12.5)).foregroundStyle(wa.danger).padding(.top, 6) }
                }
            }
        }
        .task { mine = (try? await WalletApi.coupons().data) ?? [] }
    }

    private func apply(_ value: String) {
        busy = true
        error = nil
        Task {
            do {
                if let updated = try await WalletApi.applyCoupon(uuid: checkout.id, CheckoutCouponRequest(code: value.trimmingCharacters(in: .whitespaces))).data {
                    onChanged(updated)
                }
                code = ""
            } catch {
                self.error = error.walletFailure.message
            }
            busy = false
        }
    }
}
