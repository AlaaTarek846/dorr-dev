import SwiftUI

// androidApp `rides/RidesPricing.kt`, `RidesPaySheet.kt`, `RidesBidding.kt` — the money around a ride: the promo
// code (R-45), "Price details" (R-47, R-48), "Cancellation & refund" (R-52, R-53), the terms (R-179), how to pay
// (O-37, O-38), the tip after the ride (R-144), naming my price and the drivers' offers (§9).

/// A line's icon, the same on the booking sheet and the receipt.
private func lineIcon(_ code: String) -> String {
    switch code {
    case "fare", "rate_card": return "car.fill"
    case "waiting", "stops_wait": return "hourglass.bottomhalf.filled"
    case "customer_fee": return "doc.text"
    case "discount": return "tag.fill"
    case "tax": return "doc.plaintext"
    case "payment_method_fee": return "creditcard.fill"
    case "fx_fee": return "globe"
    case "previous_balance": return "clock.arrow.circlepath"
    case "tip": return "heart.circle.fill"
    default: return "doc.plaintext"
    }
}

/// One price line: icon, label, amount — a discount in green with its minus sign.
struct PriceLineRow: View {
    @Environment(\.wa) private var wa
    let line: RideLineDto
    let currency: RideCurrencyDto?

    var body: some View {
        let amount = line.amountMinor ?? 0
        let discount = amount < 0
        let tint = discount ? AppColors.success : (line.code == "tip" ? AppColors.brandOrange : wa.settingsMut)
        HStack(spacing: 8) {
            Image(systemName: lineIcon(line.code)).font(.system(size: 13)).foregroundStyle(tint).frame(width: 16)
            Text(verbatim: line.label ?? line.code).font(.dorr(13)).foregroundStyle(discount ? AppColors.success : wa.settingsMut)
            Spacer(minLength: 4)
            Text(verbatim: (discount ? "− " : "") + rideMoney(abs(amount), currency)).font(.dorr(13, discount ? .bold : .regular))
                .foregroundStyle(discount ? AppColors.success : wa.settingsInk)
        }
        .padding(.vertical, 3)
    }
}

/// A row that opens / closes what's under it, with a turning chevron.
private struct Expander: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let trailing: String?
    let open: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 10) {
                Image(systemName: icon).font(.system(size: 14)).foregroundStyle(wa.accent)
                    .frame(width: 30, height: 30).background(wa.accent.opacity(0.1), in: Circle())
                Text(verbatim: title).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
                Spacer(minLength: 4)
                if let trailing { Text(verbatim: trailing).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk) }
                Image(systemName: "chevron.down").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut)
                    .rotationEffect(.degrees(open ? 180 : 0))
            }
            .padding(.vertical, 8)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .animation(.easeInOut(duration: 0.25), value: open)
    }
}

extension RideQuoteDto {
    /// What the chosen way of paying adds (R-48): 0 for the wallet.
    func paymentFee(_ method: String) -> Int { paymentFees?[method] ?? 0 }

    /// The price the rider is asked for with this category (fixed, the estimate's top, or their own offer).
    func askedMinor(_ riderPrice: Int?) -> Int {
        if let bid { return riderPrice ?? bid.suggestedMinor ?? 0 }
        return totalMinor ?? estimate?.maxMinor ?? holdMinor ?? 0
    }
}

/// R-45: the promo code field — one input with "Apply"; applied = a green ticket with ✕.
struct PromoCodeRow: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    @State private var code = ""

    var body: some View {
        if let quote = state.selected, state.feature("coupons"), quote.bid == nil, quote.openDestination != true {
            if let applied = quote.coupon, let appliedCode = applied.code {
                // The ticket: the code and what it took off — or why it didn't apply to this category.
                let ok = applied.applied == true
                let tint = ok ? AppColors.success : AppColors.warning
                HStack(spacing: 10) {
                    Image(systemName: ok ? "checkmark.circle.fill" : "tag.fill").font(.system(size: 18)).foregroundStyle(tint)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: appliedCode).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: ok ? L("rd_promo_saved", rideMoney(applied.discountMinor, quote.currency)) : (applied.reason ?? ""))
                            .font(.dorr(12, .semibold)).foregroundStyle(tint)
                    }
                    Spacer(minLength: 0)
                    Button {
                        state.couponCode = nil
                        state.couponError = nil
                        code = ""
                        state.loadQuotes(reprice: true)
                    } label: {
                        Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut).frame(width: 28, height: 28)
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("rd_promo_remove"))
                }
                .padding(.horizontal, 12).padding(.vertical, 10)
                .background(tint.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(tint.opacity(0.5), lineWidth: 1))
                .padding(.vertical, 4)
                .transition(.scale(scale: 0.92).combined(with: .opacity))
            } else {
                let bad = state.couponError != nil
                VStack(alignment: .leading, spacing: 4) {
                    HStack(spacing: 10) {
                        Image(systemName: "tag.fill").font(.system(size: 17)).foregroundStyle(bad ? AppColors.danger : wa.accent)
                        TextField(L("rd_promo_hint"), text: Binding(get: { code }, set: {
                            code = String($0.uppercased().filter { !$0.isWhitespace }.prefix(40))
                            state.couponError = nil
                        }))
                        .font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).tint(wa.accent)
                        .textInputAutocapitalization(.characters).autocorrectionDisabled()
                        Button {
                            state.couponCode = code
                            state.loadQuotes(reprice: true)
                        } label: {
                            Text(L("rd_promo_apply")).font(.dorr(14, .bold)).foregroundStyle(.white)
                                .padding(.horizontal, 18).padding(.vertical, 10)
                                .background(code.isEmpty ? wa.accent.opacity(0.35) : wa.accent, in: Capsule())
                        }
                        .buttonStyle(.plain)
                        .disabled(code.isEmpty || state.busy)
                    }
                    .padding(.leading, 14).padding(.trailing, 5).padding(.vertical, 5)
                    .background(wa.settingsBackground, in: Capsule())
                    .overlay(Capsule().stroke(bad ? AppColors.danger : wa.accent.opacity(0.3), lineWidth: bad ? 1.5 : 1))
                    if let e = state.couponError { Text(verbatim: e).font(.dorr(12)).foregroundStyle(AppColors.danger).padding(.leading, 4) }
                }
                .onAppear { code = state.couponCode ?? "" }
            }
        }
    }
}

/// "Price details" (Wireflow م11): every line for the chosen category and way of paying.
struct PriceDetails: View {
    @Environment(\.wa) private var wa
    let quote: RideQuoteDto
    let method: String
    @State private var open = false

    var body: some View {
        let lines0 = quote.lines ?? []
        if quote.bid == nil, lines0.contains(where: { $0.code != "rate_card" }) {
            let fee = quote.paymentFee(method)
            let lines = lines0.filter { $0.code != "total" } + (fee > 0 ? [RideLineDto(code: "payment_method_fee", label: L("rd_payment_fee_line"), amountMinor: fee)] : [])
            let total = quote.totalMinor.map { $0 + fee }
            let shown = total.map { rideMoney($0, quote.currency) }
                ?? quote.estimate.map { "\(rideMoney(($0.minMinor ?? 0) + fee, quote.currency))–\(rideMoney(($0.maxMinor ?? 0) + fee, quote.currency))" }
            VStack(alignment: .leading, spacing: 0) {
                Expander(icon: "doc.text", title: L("rd_price_details"), trailing: shown, open: open) { open.toggle() }
                if open {
                    VStack(alignment: .leading, spacing: 0) {
                        ForEach(Array(lines.enumerated()), id: \.offset) { _, l in PriceLineRow(line: l, currency: quote.currency) }
                        if let shown {
                            Rectangle().fill(wa.settingsInk.opacity(0.08)).frame(height: 1).padding(.vertical, 6)
                            HStack {
                                Text(L(total != nil ? "rd_total" : "rd_estimate")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                                Spacer()
                                Text(verbatim: shown).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                            }
                        }
                        Text(L("rd_no_new_fees")).font(.dorr(11)).foregroundStyle(wa.settingsMut).padding(.top, 6)
                    }
                    .padding(12)
                    .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .transition(.opacity.combined(with: .move(edge: .top)))
                }
            }
            .animation(.easeInOut(duration: 0.25), value: open)
        }
    }
}

/// "Cancellation & refund" (Wireflow م12, R-52, R-53): the rules this very price comes with.
struct PolicySummary: View {
    @Environment(\.wa) private var wa
    let quote: RideQuoteDto
    let method: String
    @State private var open = false

    var body: some View {
        if let summary = quote.policySummary {
            VStack(alignment: .leading, spacing: 0) {
                Expander(icon: "checkmark.shield", title: L("rd_policy_title"), trailing: nil, open: open) { open.toggle() }
                if open {
                    VStack(alignment: .leading, spacing: 8) {
                        ForEach(Array((summary.items ?? []).enumerated()), id: \.offset) { _, item in
                            HStack(alignment: .top, spacing: 8) {
                                Image(systemName: item.code == "no_show" ? "hourglass.bottomhalf.filled" : "xmark").font(.system(size: 12, weight: .semibold))
                                    .foregroundStyle(wa.accent).padding(.top, 3)
                                Text(verbatim: item.text ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                            }
                        }
                        if let refund = summary.refund?[method] {
                            HStack(alignment: .top, spacing: 8) {
                                Image(systemName: "arrow.uturn.backward").font(.system(size: 12, weight: .semibold)).foregroundStyle(AppColors.success).padding(.top, 3)
                                Text(verbatim: refund).font(.dorr(12)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                                Spacer(minLength: 0)
                            }
                            .padding(10)
                            .background(AppColors.success.opacity(0.08), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                        }
                    }
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .transition(.opacity.combined(with: .move(edge: .top)))
                }
            }
            .animation(.easeInOut(duration: 0.25), value: open)
        }
    }
}

/// R-179: before confirming, the terms in force for booking a ride — each opening in a sheet. Reading them never
/// blocks the booking.
struct TermsNotice: View {
    @Environment(\.wa) private var wa
    let quote: RideQuoteDto
    @State private var open: RideTermDto?

    var body: some View {
        if let terms = quote.terms, !terms.isEmpty {
            HStack(alignment: .top, spacing: 6) {
                Image(systemName: "building.columns").font(.system(size: 12)).foregroundStyle(wa.settingsMut).padding(.top, 2)
                VStack(alignment: .leading, spacing: 2) {
                    Text(L("rd_terms_notice")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    FlowLayout(spacing: 12) {
                        ForEach(Array(terms.enumerated()), id: \.offset) { _, term in
                            Button { open = term } label: {
                                Text(verbatim: term.title ?? "").font(.dorr(12, .bold)).underline().foregroundStyle(wa.accent).padding(.vertical, 2)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
            }
            .padding(.top, 8)
            .sheet(isPresented: Binding(get: { open != nil }, set: { if !$0 { open = nil } })) {
                if let term = open { TermsSheet(term: term).dorrThemed().presentationDetents([.medium, .large]) }
            }
        }
    }
}

private struct TermsSheet: View {
    @Environment(\.wa) private var wa
    let term: RideTermDto
    @State private var html: String?
    @State private var failed = false

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 10) {
                Image(systemName: term.type == "privacy" ? "checkmark.shield" : "building.columns").font(.system(size: 17)).foregroundStyle(wa.accent)
                    .frame(width: 38, height: 38).background(wa.accent.opacity(0.1), in: Circle())
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: term.title ?? "").font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: [L(term.scope == "service" ? "rd_terms_scope_service" : "rd_terms_scope_general"), updated.map { L("rd_terms_updated", $0) }]
                        .compactMap { $0 }.joined(separator: " · "))
                        .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
            }
            if failed {
                Text(L("content_error")).font(.dorr(13)).foregroundStyle(AppColors.danger)
            } else if let html {
                ScrollView { HtmlContent(html: html, textColor: wa.settingsInk, accentColor: wa.accent) }
            } else {
                ProgressView().tint(wa.accent).frame(maxWidth: .infinity, minHeight: 160)
            }
            Spacer(minLength: 0)
        }
        .padding(20)
        .background(wa.settingsCard.ignoresSafeArea())
        .task {
            do { html = try await ContentApi.legalPage(type: term.type ?? "term", serviceId: term.serviceId, fallback: 1).data?.content ?? "" } catch { failed = true }
        }
    }

    private var updated: String? {
        guard let at = term.updatedAt else { return nil }
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        guard let date = f.date(from: at) ?? ISO8601DateFormatter().date(from: at) else { return nil }
        let out = DateFormatter()
        out.locale = Locale(identifier: AppLocale.current)
        out.dateStyle = .medium
        return out.string(from: date)
    }
}

/// The old price struck through above the new one, when a promo code took something off.
struct StruckPrice: View {
    @Environment(\.wa) private var wa
    let quote: RideQuoteDto

    var body: some View {
        if let off = quote.coupon?.applied == true ? quote.coupon?.discountMinor : nil, let total = quote.totalMinor {
            Text(verbatim: rideMoney(total + off, quote.currency)).font(.dorr(11)).strikethrough().foregroundStyle(wa.settingsMut)
        }
    }
}

// MARK: - How to pay (O-37, O-38)

private func methodIcon(_ method: String) -> String {
    switch method {
    case "wallet": return "wallet.pass.fill"
    case "card": return "creditcard.fill"
    default: return "banknote.fill"
    }
}

func rideMethodName(_ method: String) -> String {
    switch method {
    case "wallet": return L("rd_pay_wallet")
    case "card": return L("rd_pay_card")
    default: return L("rd_pay_cash")
    }
}

/// One row on the booking sheet — how I pay (cash by default, the wallet's balance with it when there is one)
/// and the coupon — that opens the payment sheet to change either, any time.
struct PaymentRow: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let quote: RideQuoteDto
    @State private var open = false

    var body: some View {
        let asked = quote.askedMinor(state.riderPrice)
        let walletPart = state.payment == "cash" && state.useWallet ? min(quote.walletAvailableMinor ?? 0, asked) : 0
        Button { open = true } label: {
            HStack(spacing: 10) {
                Image(systemName: methodIcon(state.payment)).font(.system(size: 17)).foregroundStyle(wa.accent)
                    .frame(width: 38, height: 38).background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("rd_pay_with")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: walletPart > 0 && walletPart >= asked ? L("rd_pay_wallet_covers", rideMoney(walletPart, quote.currency))
                        : walletPart > 0 ? L("rd_pay_wallet_plus_cash", rideMoney(walletPart, quote.currency), rideMoney(asked - walletPart, quote.currency))
                        : rideMethodName(state.payment))
                        .font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                    if let c = quote.coupon, c.applied == true {
                        Text(verbatim: L("rd_promo_saved", rideMoney(c.discountMinor, quote.currency))).font(.dorr(11, .semibold)).foregroundStyle(AppColors.success)
                    }
                }
                Spacer(minLength: 0)
                Text(L("rd_change")).font(.dorr(13, .bold)).foregroundStyle(wa.accent)
                Image(systemName: "chevron.forward").font(.system(size: 12, weight: .bold)).foregroundStyle(wa.accent)
            }
            .padding(.horizontal, 12).padding(.vertical, 11)
            .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.accent.opacity(0.18), lineWidth: 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(WaPressStyle())
        .sheet(isPresented: $open) {
            PaymentSheet(state: state, quote: quote) { open = false }.dorrThemed().presentationDetents([.medium, .large])
        }
    }
}

private struct PaymentSheet: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let quote: RideQuoteDto
    let onClose: () -> Void

    private func fee(_ m: String) -> String? {
        let f = quote.paymentFee(m)
        return f > 0 ? "+" + rideMoney(f, quote.currency) : nil
    }

    var body: some View {
        let asked = quote.askedMinor(state.riderPrice)
        let wallet = quote.walletAvailableMinor ?? 0
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                Text(L("rd_pay_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).padding(.bottom, 12)
                // Cash first — the default. With money in the wallet, it goes first and the rest is cash.
                if state.feature("cash") && quote.category?.cashAllowed != false {
                    MethodOption(icon: methodIcon("cash"), title: rideMethodName("cash"), subtitle: nil, fee: fee("cash"), selected: state.payment == "cash") { state.payment = "cash" }
                    if state.payment == "cash" && wallet > 0 && state.feature("wallet") {
                        let part = min(wallet, asked)
                        HStack(spacing: 8) {
                            Image(systemName: "wallet.pass.fill").foregroundStyle(AppColors.success)
                            VStack(alignment: .leading, spacing: 0) {
                                Text(verbatim: L("rd_use_wallet_too", rideMoney(wallet, quote.currency))).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                                if state.useWallet {
                                    Text(verbatim: part >= asked ? L("rd_pay_wallet_covers", rideMoney(part, quote.currency))
                                        : L("rd_pay_wallet_plus_cash", rideMoney(part, quote.currency), rideMoney(asked - part, quote.currency)))
                                        .font(.dorr(12)).foregroundStyle(AppColors.success)
                                }
                            }
                            Spacer(minLength: 0)
                            Toggle("", isOn: $state.useWallet).labelsHidden().tint(AppColors.success)
                        }
                        .padding(.horizontal, 12).padding(.vertical, 8)
                        .background(AppColors.success.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        .padding(.leading, 12).padding(.top, 2).padding(.bottom, 6)
                    }
                }
                // The wallet alone: shown when it holds money.
                if state.feature("wallet") && wallet > 0 {
                    MethodOption(icon: methodIcon("wallet"), title: rideMethodName("wallet"), subtitle: L("rd_wallet_free", rideMoney(wallet, quote.currency)),
                                 fee: fee("wallet"), selected: state.payment == "wallet") { state.payment = "wallet" }
                }
                if state.feature("card") {
                    MethodOption(icon: methodIcon("card"), title: rideMethodName("card"), subtitle: nil, fee: fee("card"), selected: state.payment == "card") { state.payment = "card" }
                }
                // The coupon, in the same sheet — applied whenever, the prices follow.
                HStack(spacing: 6) {
                    Image(systemName: "tag.fill").font(.system(size: 14)).foregroundStyle(wa.accent)
                    Text(L("rd_promo_title")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                }
                .padding(.top, 14).padding(.bottom, 8)
                PromoCodeRow(state: state)
                AccentButton(text: L("rd_done"), action: onClose).padding(.top, 14)
            }
            .padding(18)
        }
        .background(wa.settingsCard.ignoresSafeArea())
    }
}

private struct MethodOption: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let subtitle: String?
    let fee: String?
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 10) {
                Image(systemName: icon).font(.system(size: 17)).foregroundStyle(wa.accent)
                    .frame(width: 38, height: 38).background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: title).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                    if let subtitle { Text(verbatim: subtitle).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    if let fee { Text(verbatim: fee).font(.dorr(11, .bold)).foregroundStyle(AppColors.warning) }
                }
                Spacer(minLength: 0)
                Image(systemName: selected ? "checkmark.circle.fill" : "circle").font(.system(size: 20)).foregroundStyle(selected ? wa.accent : wa.settingsMut)
            }
            .padding(12)
            .background(selected ? wa.accent.opacity(0.06) : .clear, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(selected ? wa.accent : wa.settingsInk.opacity(0.1), lineWidth: selected ? 2 : 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(WaPressStyle())
        .padding(.vertical, 4)
    }
}

// MARK: - The tip (R-144)

/// "Tip (optional): 5 — 10 — 20" under the rating — or another amount. Paid from the wallet, all of it the
/// captain's; once given it turns into a thank-you.
struct TipSection: View {
    @Environment(\.wa) private var wa
    let ride: RideDto
    let onTipped: (RideDto) -> Void
    @State private var chosen: Int?
    @State private var other = false
    @State private var typed = ""
    @State private var busy = false
    @State private var error: String?
    @State private var key = RidesApi.key()
    @State private var pop: CGFloat = 0.3

    var body: some View {
        if let tip = ride.tip {
            let unit = Int(pow(10, Double(ride.currency?.decimals ?? 2)))
            let amount = other ? Double(typed).map { Int($0 * Double(unit)) } : chosen
            VStack(alignment: .leading, spacing: 0) {
                if let given = tip.amountMinor {
                    // Thank-you: a heart that pops, and the amount.
                    HStack(spacing: 10) {
                        Image(systemName: "heart.fill").font(.system(size: 26)).foregroundStyle(AppColors.brandOrange).scaleEffect(pop)
                            .onAppear { withAnimation(.spring(response: 0.5, dampingFraction: 0.35)) { pop = 1 } }
                        VStack(alignment: .leading, spacing: 0) {
                            Text(verbatim: L("rd_tip_given", rideMoney(given, ride.currency))).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                            Text(L("rd_tip_all_driver")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        }
                    }
                } else if tip.allowed == true {
                    HStack(spacing: 8) {
                        Image(systemName: "dollarsign.circle.fill").font(.system(size: 18)).foregroundStyle(AppColors.brandOrange)
                        Text(verbatim: L("rd_tip_title", ride.driver?.name ?? "")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                    }
                    Text(L("rd_tip_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 2).padding(.bottom, 8)
                    HStack(spacing: 8) {
                        ForEach(tip.presetsMinor ?? [], id: \.self) { preset in
                            TipChip(label: rideMoney(preset, ride.currency), selected: !other && chosen == preset) {
                                other = false
                                chosen = chosen == preset ? nil : preset
                                error = nil
                            }
                        }
                        TipChip(label: L("rd_tip_other"), selected: other) { other.toggle(); chosen = nil; error = nil }
                    }
                    if other {
                        DorrTextArea(value: Binding(get: { typed }, set: { typed = String($0.filter { $0.isNumber || $0 == "." }.prefix(8)); error = nil }),
                                     placeholder: L("rd_tip_amount_hint", rideMoney(tip.maxMinor, ride.currency)), icon: "dollarsign.circle", singleLine: true)
                            .keyboardType(.decimalPad)
                            .padding(.top, 8)
                    }
                    if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(AppColors.danger).padding(.top, 6) }
                    if let value = amount, value > 0 {
                        AccentButton(text: L("rd_tip_send", rideMoney(value, ride.currency)), busy: busy, color: AppColors.brandOrange) {
                            busy = true
                            Task {
                                do {
                                    if let fresh = try await RidesApi.tip(ride.uuid, ["amount_minor": .int(value)], key: key).data { onTipped(fresh) }
                                } catch {
                                    self.error = error.apiFailure.message
                                }
                                busy = false
                            }
                        }
                        .padding(.top, 10)
                    }
                }
            }
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(AppColors.brandOrange.opacity(0.07), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .padding(.vertical, 8)
            .animation(.easeInOut(duration: 0.25), value: other)
        }
    }
}

private struct TipChip: View {
    @Environment(\.wa) private var wa
    let label: String
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(verbatim: label).font(.dorr(13, selected ? .bold : .medium)).foregroundStyle(wa.settingsInk).lineLimit(1).minimumScaleFactor(0.8)
                .frame(maxWidth: .infinity).padding(.vertical, 10)
                .background(selected ? AppColors.brandOrange.opacity(0.12) : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(selected ? AppColors.brandOrange : wa.settingsInk.opacity(0.1), lineWidth: selected ? 2 : 1))
                .scaleEffect(selected ? 1.06 : 1)
        }
        .buttonStyle(.plain)
        .animation(.spring(response: 0.3, dampingFraction: 0.5), value: selected)
    }
}

// MARK: - Bidding (§9)

/// "Name your price" on the booking sheet (§9.1): typed straight in, or − / + by the server's step. − stops at
/// the floor; a typed price under the floor shows in red.
struct BidPriceEditor: View {
    @Environment(\.wa) private var wa
    let price: Int
    let floor: Int
    let suggested: Int
    let step: Int
    let currency: RideCurrencyDto?
    let onChange: (Int) -> Void
    @State private var text = ""
    @FocusState private var editing: Bool

    private var places: Int { currency?.decimals ?? 2 }
    private var unit: Double { pow(10, Double(places)) }

    private func shown(_ minor: Int) -> String {
        let major = Double(minor) / unit
        return major == major.rounded() ? String(Int(major)) : String(format: "%.\(places)f", major)
    }

    var body: some View {
        let below = price < floor
        VStack(spacing: 4) {
            Text(L("rd_bid_your_price")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            HStack(spacing: 12) {
                stepButton("minus", enabled: price - step >= floor) { editing = false; onChange(max(price - step, floor)) }
                HStack(spacing: 4) {
                    TextField("", text: Binding(get: { text }, set: { raw in
                        let clean = String(raw.replacingOccurrences(of: ",", with: ".").filter { $0.isNumber || $0 == "." }.prefix(9))
                        if clean.filter({ $0 == "." }).count > 1 || (places == 0 && clean.contains(".")) { return }
                        text = clean
                        if let v = Double(clean) { onChange(Int((v * unit).rounded())) }
                    }))
                    .font(.dorr(28, .heavy)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
                    .keyboardType(places > 0 ? .decimalPad : .numberPad)
                    .focused($editing)
                    .frame(width: 110)
                    if let symbol = currency?.symbol, !symbol.isEmpty { Text(verbatim: symbol).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsMut) }
                }
                .padding(.horizontal, 10).padding(.vertical, 4)
                .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous)
                    .stroke(below ? AppColors.danger : (editing ? wa.accent : wa.accent.opacity(0.25)), lineWidth: editing ? 2 : 1))
                stepButton("plus", enabled: true) { editing = false; onChange(max(price + step, floor)) }
            }
            Text(verbatim: L("rd_bid_hint", rideMoney(suggested, currency), rideMoney(floor, currency))).font(.dorr(12))
                .foregroundStyle(below ? AppColors.danger : (price < suggested ? AppColors.warning : wa.settingsMut)).multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity)
        .padding(14)
        .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .padding(.top, 8)
        .onAppear { text = shown(price) }
        .onChange(of: price) { p in if !editing { text = shown(p) } }
        .onChange(of: editing) { e in if !e { text = shown(price) } }
    }

    private func stepButton(_ icon: String, enabled: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 17, weight: .bold)).foregroundStyle(.white)
                .frame(width: 44, height: 44).background(enabled ? wa.accent : wa.settingsMut.opacity(0.3), in: Circle())
        }
        .buttonStyle(WaPressStyle())
        .disabled(!enabled)
    }
}

/// The bidding screen after booking (§9.2): my price (and raising it), and every driver's offer as a card —
/// cheapest first, its timer as a draining bar — with take / decline. An offer whose timer ends is reloaded once at
/// its own deadline (the clock, not a timer asking); live offers arrive on the ride's channel.
struct BiddingPanel: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let onCancel: () -> Void
    @State private var board = RideBidBoardDto()
    @State private var busy: String?
    @State private var error: String?

    private func reload() async {
        guard let uuid = state.ride?.uuid, let b = try? await RidesApi.bids(uuid).data else { return }
        board = b
    }

    var body: some View {
        if let ride = state.ride {
            let myPrice = board.riderPriceMinor ?? ride.bidding?.riderPriceMinor ?? 0
            let bids = board.bids ?? []
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 6) {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L(bids.isEmpty ? "rd_bid_waiting" : "rd_bid_offers")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: L("rd_bid_my_price", rideMoney(myPrice, ride.currency))).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                    }
                    Spacer(minLength: 0)
                    ForEach([500, 1000], id: \.self) { by in
                        Button { raise(ride, myPrice, by) } label: {
                            Text(verbatim: "+" + rideMoney(by, RideCurrencyDto(code: ride.currency?.code, symbol: "", decimalPlaces: ride.currency?.decimalPlaces)))
                                .font(.dorr(13, .bold)).foregroundStyle(wa.accent)
                                .padding(.horizontal, 10).padding(.vertical, 6)
                                .overlay(Capsule().stroke(wa.accent, lineWidth: 1))
                        }
                        .buttonStyle(.plain)
                        .disabled(busy != nil)
                    }
                }
                if bids.isEmpty {
                    ProgressView().progressViewStyle(.linear).tint(wa.accent).padding(.top, 10)
                    Text(L("rd_bid_raise_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 8)
                }
                ScrollView {
                    VStack(spacing: 8) {
                        ForEach(bids) { bid in
                            BidCard(bid: bid, ride: ride, busy: busy == bid.uuid, onTake: { take(ride, bid) }, onDecline: { decline(ride, bid) })
                                .transition(.opacity.combined(with: .move(edge: .top)))
                        }
                    }
                }
                .frame(maxHeight: 330)
                .fixedSize(horizontal: false, vertical: true)
                .padding(.top, 8)
                if (board.queued ?? 0) > 0 { Text(verbatim: L("rd_bid_queued", board.queued ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 4) }
                if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger).padding(.top, 6) }
                Button(action: onCancel) { Text(L("rd_cancel")).font(.dorr(15, .bold)).foregroundStyle(AppColors.danger).padding(8) }
                    .buttonStyle(.plain)
                    .padding(.top, 8)
            }
            .task(id: ride.uuid) { await reload() }
            // A new offer, one withdrawn, the ride taken: the ride's channel says so — one reload.
            .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { e in if e.name == "ride.changed" { Task { await reload() } } }
            // An offer whose timer ends is reloaded once at its own deadline — by the clock.
            .task(id: bids.map { "\($0.uuid):\($0.secondsLeft ?? 0)" }.joined()) {
                guard let next = bids.compactMap(\.secondsLeft).filter({ $0 > 0 }).min() else { return }
                try? await Task.sleep(nanoseconds: UInt64(next + 1) * 1_000_000_000)
                if !Task.isCancelled { await reload() }
            }
            .animation(.easeInOut(duration: 0.25), value: bids.map(\.uuid))
        }
    }

    private func raise(_ ride: RideDto, _ myPrice: Int, _ by: Int) {
        busy = "raise"
        error = nil
        Task {
            do {
                if let fresh = try await RidesApi.raise(ride.uuid, priceMinor: myPrice + by).data { state.ride = fresh }
                board.riderPriceMinor = myPrice + by
            } catch {
                self.error = error.apiFailure.message
            }
            busy = nil
        }
    }

    private func take(_ ride: RideDto, _ bid: RideBidDto) {
        busy = bid.uuid
        error = nil
        Task {
            do {
                if let fresh = try await RidesApi.acceptBid(ride.uuid, bid.uuid, key: RidesApi.key()).data { state.ride = fresh }
            } catch {
                self.error = error.apiFailure.message
            }
            busy = nil
        }
    }

    private func decline(_ ride: RideDto, _ bid: RideBidDto) {
        board.bids = (board.bids ?? []).filter { $0.uuid != bid.uuid }
        Task { _ = try? await RidesApi.declineBid(ride.uuid, bid.uuid) }
    }
}

private struct BidCard: View {
    @Environment(\.wa) private var wa
    let bid: RideBidDto
    let ride: RideDto
    let busy: Bool
    let onTake: () -> Void
    let onDecline: () -> Void
    @State private var left = 0

    var body: some View {
        let ttl = max(bid.ttlS ?? 20, 1)
        let cheapest = bid.cheapest == true
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 10) {
                Text(verbatim: String((bid.driver?.name ?? "").prefix(1))).font(.dorr(16, .bold)).foregroundStyle(wa.accent)
                    .frame(width: 44, height: 44).background(wa.accent.opacity(0.12), in: Circle())
                VStack(alignment: .leading, spacing: 1) {
                    HStack(spacing: 4) {
                        Text(verbatim: bid.driver?.name ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                        Image(systemName: "star.fill").font(.system(size: 11)).foregroundStyle(AppColors.warning)
                        Text(verbatim: bid.driver?.rating.map { String(format: "%.1f", $0) } ?? L("rd_new_rating")).font(.dorr(12)).foregroundStyle(wa.settingsInk)
                    }
                    let car = bid.driver?.vehicle.map { "\($0.make ?? "") \($0.model ?? "")".trimmingCharacters(in: .whitespaces) }
                    Text(verbatim: [car, "\(((bid.etaS ?? 0) + 59) / 60) \(L("rd_minutes"))"].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " · "))
                        .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    HStack(spacing: 4) {
                        if cheapest { badge(L("rd_bid_cheapest"), AppColors.success) }
                        if bid.nearest == true { badge(L("rd_bid_nearest"), AppColors.info) }
                    }
                    .padding(.top, 2)
                }
                Spacer(minLength: 0)
                Text(verbatim: rideMoney(bid.priceMinor, ride.currency)).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
            }
            GeometryReader { proxy in
                ZStack(alignment: .leading) {
                    Capsule().fill(wa.accent.opacity(0.12))
                    Capsule().fill(left <= 5 ? AppColors.danger : wa.accent)
                        .frame(width: proxy.size.width * CGFloat(min(max(Double(left) / Double(ttl), 0), 1)))
                        .animation(.linear(duration: 0.9), value: left)
                }
            }
            .frame(height: 4)
            .padding(.vertical, 8)
            HStack(spacing: 8) {
                Button(action: onDecline) {
                    Text(L("rd_bid_decline")).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
                        .frame(maxWidth: .infinity, minHeight: 42)
                        .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(wa.settingsInk.opacity(0.15), lineWidth: 1))
                }
                .buttonStyle(.plain)
                Button(action: onTake) {
                    ZStack {
                        if busy { ProgressView().tint(.white) } else {
                            Text(verbatim: L("rd_bid_take", rideMoney(bid.priceMinor, ride.currency))).font(.dorr(14, .bold)).foregroundStyle(.white)
                        }
                    }
                    .frame(maxWidth: .infinity, minHeight: 42)
                    .background(AppColors.success, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                .buttonStyle(.plain)
                .disabled(busy || left <= 0)
                .layoutPriority(1)
            }
        }
        .padding(12)
        .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(cheapest ? AppColors.success : wa.settingsInk.opacity(0.08), lineWidth: cheapest ? 2 : 1))
        // The offer's own countdown, on the phone's clock.
        .task(id: "\(bid.uuid):\(bid.secondsLeft ?? 0)") {
            left = bid.secondsLeft ?? 0
            while left > 0 {
                try? await Task.sleep(nanoseconds: 1_000_000_000)
                if Task.isCancelled { return }
                left -= 1
            }
        }
    }

    private func badge(_ text: String, _ color: Color) -> some View {
        Text(verbatim: text).font(.dorr(10, .bold)).foregroundStyle(color)
            .padding(.horizontal, 6).padding(.vertical, 2)
            .background(color.opacity(0.12), in: Capsule())
    }
}
