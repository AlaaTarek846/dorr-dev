import SwiftUI

// MARK: - Amount entry (androidApp WaAmount.kt)

/// The big centred amount entry used by top-up and transfer (`wa-amount`): label, a huge number field with
/// the currency next to it, optional quick-pick chips, and a brand glow while focused.
struct WaAmountEntry<Below: View>: View {
    @Environment(\.wa) private var wa
    let label: String
    @Binding var amountText: String
    let currency: String
    var quick: [Int] = []
    var invalid = false
    var hero = false
    var subtitle: String?
    var footerLabel: String?
    var footerValue: String?
    @ViewBuilder var below: () -> Below
    @FocusState private var focused: Bool

    var body: some View {
        let chosen = parseAmountToMinor(amountText)
        let radius: CGFloat = hero ? 28 : 22
        VStack(spacing: 0) {
            if hero {
                HStack(spacing: 0) {
                    Image(systemName: "wallet.pass.fill").font(.system(size: 12, weight: .semibold)).foregroundStyle(.white)
                        .frame(width: 28, height: 28).background(Color.white.opacity(0.16), in: Circle())
                    VStack(alignment: .leading, spacing: 1) {
                        Text(label).font(.dorr(13, .bold)).foregroundStyle(.white)
                        if let subtitle, !subtitle.isEmpty { Text(subtitle).font(.dorr(11)).foregroundStyle(.white.opacity(0.68)) }
                    }
                    .padding(.horizontal, 8)
                    Spacer(minLength: 0)
                }
                HStack(spacing: 10) {
                    if !currency.isEmpty { Text(currency).font(.dorr(16, .bold)).foregroundStyle(.white.opacity(0.72)) }
                    amountField(size: 28, kerning: -0.5, ink: .white, placeholder: .white.opacity(0.35), cursor: .white)
                        .frame(minWidth: 72, maxWidth: 180)
                        .fixedSize(horizontal: true, vertical: false)
                }
                .frame(maxWidth: .infinity)
                .environment(\.layoutDirection, .leftToRight)
                .padding(.top, 10)
                .padding(.bottom, 8)
                Color.white.opacity(0.18).frame(height: 1)
            } else {
                Text(label).font(.dorr(13, .bold)).foregroundStyle(wa.mut)
                HStack(alignment: .lastTextBaseline, spacing: 8) {
                    amountField(size: 48, kerning: -1, ink: wa.ink, placeholder: wa.night ? wa.soft : Color(hex: 0xD1D5DB), cursor: wa.red)
                        .frame(minWidth: 96, maxWidth: 190)
                        .fixedSize(horizontal: true, vertical: false)
                    if !currency.isEmpty { Text(currency).font(.dorr(16, .heavy)).foregroundStyle(wa.mut) }
                }
                .environment(\.layoutDirection, .leftToRight)
                .padding(.top, 6)
                .padding(.bottom, 4)
            }
            if !quick.isEmpty {
                HStack(spacing: 8) {
                    ForEach(quick, id: \.self) { value in
                        if hero {
                            let on = chosen == value * 100
                            Button { amountText = String(value) } label: {
                                Text(verbatim: "+\(value)").font(.dorr(12, .bold)).foregroundStyle(.white)
                                    .frame(maxWidth: .infinity).padding(.vertical, 9)
                                    .background(Color.white.opacity(on ? 0.28 : 0.12), in: Capsule())
                                    .environment(\.layoutDirection, .leftToRight)
                            }
                            .buttonStyle(.plain)
                        } else {
                            WaChip(text: String(value), selected: chosen == value * 100) { amountText = String(value) }
                        }
                    }
                }
                .padding(.top, hero ? 14 : 10)
            }
            if hero, let footerLabel, !footerLabel.isEmpty {
                HStack {
                    Text(footerLabel).font(.dorr(11)).foregroundStyle(.white.opacity(0.72))
                    Spacer(minLength: 8)
                    Text(verbatim: "\u{2066}\(footerValue ?? "")\u{2069}").font(.dorr(12.5, .bold)).foregroundStyle(.white)
                }
                .padding(.horizontal, 12)
                .padding(.vertical, 10)
                .background(Color.white.opacity(0.14), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .shadow(color: .black.opacity(0.2), radius: 8, y: 3)
                .padding(.top, 12)
            }
            if invalid && !amountText.trimmingCharacters(in: .whitespaces).isEmpty { WaError(message: L("wa_amount_invalid")) }
            below()
        }
        .padding(.horizontal, 16)
        .padding(.top, hero ? 16 : 22)
        .padding(.bottom, 16)
        .frame(maxWidth: .infinity)
        .background {
            if hero {
                RoundedRectangle(cornerRadius: radius, style: .continuous).fill(wa.heroGradient)
                    .shadow(color: wa.red.opacity(0.36), radius: 16, y: 6)
            } else {
                RoundedRectangle(cornerRadius: radius, style: .continuous).fill(wa.surface)
                    .shadow(color: focused ? wa.red.opacity(0.25) : Color(hex: 0x111928).opacity(0.15), radius: focused ? 16 : 10, y: 4)
            }
        }
        .overlay {
            if !hero {
                RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(focused ? wa.red.opacity(0.35) : .clear, lineWidth: 2)
                    .animation(.easeOut(duration: 0.2), value: focused)
            }
        }
        .contentShape(Rectangle())
        .onTapGesture { focused = true }
    }

    private func amountField(size: CGFloat, kerning: CGFloat, ink: Color, placeholder: Color, cursor: Color) -> some View {
        ZStack {
            if amountText.isEmpty {
                Text("0.00").font(.dorr(size, .heavy)).kerning(kerning).foregroundStyle(placeholder)
            }
            TextField("", text: $amountText)
                .font(.dorr(size, .heavy))
                .kerning(kerning)
                .foregroundStyle(ink)
                .tint(cursor)
                .multilineTextAlignment(.center)
                .keyboardType(.decimalPad)
                .focused($focused)
                .onChange(of: amountText) { raw in
                    let cleaned = String(raw.replacingOccurrences(of: ",", with: ".").replacingOccurrences(of: "٫", with: ".")
                        .filter { ($0.isASCII && $0.isNumber) || $0 == "." }.prefix(12))
                    if cleaned != raw { amountText = cleaned }
                }
        }
    }
}

extension WaAmountEntry where Below == EmptyView {
    init(label: String, amountText: Binding<String>, currency: String, quick: [Int] = [], invalid: Bool = false, hero: Bool = false,
         subtitle: String? = nil, footerLabel: String? = nil, footerValue: String? = nil) {
        self.init(label: label, amountText: amountText, currency: currency, quick: quick, invalid: invalid, hero: hero,
                  subtitle: subtitle, footerLabel: footerLabel, footerValue: footerValue, below: { EmptyView() })
    }
}

// MARK: - Step 1: who? (androidApp WalletTransfer.kt)

private enum TransferMode { case phone, wallet }

enum FieldState { case empty, typing, ok, bad }

/// Step 1 of a transfer: find the recipient by phone or wallet number (or scan their QR). Nothing moves
/// here — the next page shows who it is, masked, before any amount is chosen.
@MainActor
struct WalletTransfer: View {
    @EnvironmentObject private var host: WalletHost
    @EnvironmentObject private var session: AuthSession
    @Environment(\.wa) private var wa

    @State private var mode = TransferMode.phone
    @State private var phoneRaw = ""
    @State private var walletRaw = ""
    @State private var busy = false
    @State private var error: String?
    @State private var keyboardShown = false

    var body: some View {
        let balance = host.balance
        // The wallet's own country (not my phone number's): where I am, or another wallet of mine I chose.
        let uc = session.user?.country
        let code = balance?.countryCode ?? uc?.code
        let country = countryName(code)
        let dialCode: String = {
            let raw = (balance?.countryCode != nil ? balance?.dialCode : uc?.dialCode) ?? ""
            let digits = raw.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: "+", with: "")
            return digits.isEmpty ? "+966" : "+\(digits)"
        }()
        let flagCode = (balance?.countryCode ?? uc?.flag?.code ?? uc?.code)?.lowercased() ?? "sa"
        let phoneLength = (balance?.countryCode != nil ? balance?.phoneLength : uc?.phoneLength) ?? 9
        let startsRaw = (balance?.countryCode != nil ? balance?.phoneStartsWith : uc?.phoneStartsWith) ?? ""
        let phoneStartsWith = startsRaw.trimmingCharacters(in: .whitespaces).isEmpty ? "5" : startsRaw

        let isPhoneValid = phoneRaw.count == phoneLength && (phoneStartsWith.isEmpty || phoneRaw.hasPrefix(phoneStartsWith))
        let phoneError: String? = {
            if phoneRaw.isEmpty { return nil }
            if phoneRaw.count != phoneLength { return L("login_error_phone_invalid_length", phoneLength) }
            if !phoneStartsWith.isEmpty && !phoneRaw.hasPrefix(phoneStartsWith) { return L("login_error_phone_invalid_start", phoneStartsWith) }
            return nil
        }()
        let phonePlaceholder = phoneStartsWith + String(repeating: "*", count: max(phoneLength - phoneStartsWith.count, 0))
        let phoneOk: FieldState = phoneRaw.isEmpty ? .empty : (phoneError != nil ? .bad : (isPhoneValid ? .ok : .typing))

        let walletDigits = String(asciiDigits(walletRaw).prefix(11))
        let walletOk: FieldState = walletDigits.isEmpty ? .empty : (walletDigits.count < 11 ? .typing : (isValidWalletNumber(walletDigits) ? .ok : .bad))
        let ready = mode == .phone ? isPhoneValid : walletOk == .ok

        let phoneHint: String = {
            var text = L("wa_phone_hint_country", country)
            let parts = [L("wa_phone_digits", phoneLength), phoneStartsWith.isEmpty ? nil : L("wa_phone_starts", phoneStartsWith)].compactMap { $0 }
            if !parts.isEmpty { text += ": " + parts.joined(separator: " ") }
            return text
        }()

        return WaPage(title: L("wa_transfer_title"), onBack: { host.pop() }, cta: {
            WaButton(text: L("wa_next"), enabled: ready, loading: busy) { lookup(walletDigits: walletDigits) }
            WaCtaHint(text: L("wa_transfer_review_hint"), icon: "shield.fill")
        }) {
            if !keyboardShown {
                HStack(alignment: .top, spacing: 10) {
                    Image(systemName: "info.circle.fill").font(.system(size: 16)).foregroundStyle(Color(hex: 0x3B82F6)).padding(.top, 2)
                    VStack(alignment: .leading, spacing: 4) {
                        Text(L("wa_transfer_banner_title", country)).font(.dorr(13, .bold)).foregroundStyle(wa.ink)
                        Text(L("wa_transfer_banner_text", balance?.currencyCode ?? "", country)).font(.dorr(12)).foregroundStyle(wa.mut).lineSpacing(3)
                    }
                    Spacer(minLength: 0)
                }
                .padding(.horizontal, 14)
                .padding(.vertical, 12)
                .background(wa.night ? wa.darkWell : Color(hex: 0xF0F6FF), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(wa.night ? wa.line : Color(hex: 0xC9DCF5), lineWidth: 1))
                .waRise(0)

                if let balance {
                    WalletPickerCard(balance: balance).padding(.top, 10).padding(.bottom, 10).waRise(0)
                } else {
                    Spacer().frame(height: 10)
                }

                ScanCta { host.push(.scanner) }.waRise(1)

                HStack(spacing: 10) {
                    wa.line.frame(height: 1)
                    Text(L("wa_or_manual")).font(.dorr(12, .bold)).foregroundStyle(wa.soft).fixedSize()
                    wa.line.frame(height: 1)
                }
                .padding(.vertical, 12)
                .waRise(1)
            }

            Segmented(options: [(L("wa_by_phone"), "phone.fill"), (L("wa_by_wallet"), "wallet.pass.fill")], selected: mode == .phone ? 0 : 1) { index in
                mode = index == 0 ? .phone : .wallet
                error = nil
            }
            .waRise(1)

            Spacer().frame(height: 12)
            if mode == .phone {
                InputCard(
                    label: L("wa_recipient_phone"), prefix: dialCode, prefixIcon: "phone.fill",
                    value: Binding(get: { phoneRaw }, set: { phoneRaw = String(asciiDigits($0).prefix(phoneLength)) }),
                    placeholder: phonePlaceholder, state: phoneOk, hint: phoneHint, keyboard: .phonePad,
                    flagCode: flagCode, errorMessage: phoneError
                )
                .waRise(2)
            } else {
                InputCard(
                    label: L("wa_wallet_number"), prefix: nil, prefixIcon: "wallet.pass.fill",
                    value: Binding(get: { groupWalletNumber(walletDigits) }, set: { walletRaw = String(asciiDigits($0).prefix(11)) }),
                    placeholder: "000 0000 0000", state: walletOk,
                    hint: walletOk == .bad ? L("wa_wallet_invalid") : L("wa_wallet_hint", country), keyboard: .numberPad
                )
                .waRise(2)
            }
            WaError(message: error)
        }
        .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillShowNotification)) { _ in withAnimation { keyboardShown = true } }
        .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillHideNotification)) { _ in withAnimation { keyboardShown = false } }
    }

    private func lookup(walletDigits: String) {
        error = nil
        busy = true
        Task {
            defer { busy = false }
            do {
                let request = mode == .phone ? TransferLookupRequest(mode: "phone", phone: phoneRaw) : TransferLookupRequest(mode: "wallet", walletNumber: walletDigits)
                if let who = try await WalletApi.transferLookup(request).data {
                    host.push(.transferConfirm(who))
                } else {
                    error = L("wa_error_network")
                }
            } catch {
                let failure = error.apiFailure
                self.error = failure.httpStatus == nil ? L("wa_error_network") : failure.message ?? L("wa_error_network")
            }
        }
    }
}

/// The brand "scan a QR code" card on top of the transfer form.
private struct ScanCta: View {
    @Environment(\.wa) private var wa
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 12) {
                Image(systemName: "qrcode.viewfinder").font(.system(size: 20, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 44, height: 44)
                    .background(Color.white.opacity(0.95), in: RoundedRectangle(cornerRadius: 15, style: .continuous))
                Text(L("wa_scan_cta_title")).font(.dorr(15.5, .heavy)).foregroundStyle(.white)
                Spacer(minLength: 0)
                Image(systemName: "chevron.forward").font(.system(size: 14, weight: .bold)).foregroundStyle(.white.opacity(0.9))
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 14)
            .background(wa.buttonGradient, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
            .shadow(color: wa.red.opacity(0.28), radius: 14, y: 6)
        }
        .buttonStyle(WaPressStyle(scale: 0.98))
    }
}

/// Two-way switch with a pill that slides between the options (`wa-seg`).
private struct Segmented: View {
    @Environment(\.wa) private var wa
    let options: [(String, String)]
    let selected: Int
    let onSelect: (Int) -> Void

    var body: some View {
        GeometryReader { proxy in
            let tab = proxy.size.width / CGFloat(options.count)
            ZStack(alignment: .leading) {
                RoundedRectangle(cornerRadius: 14, style: .continuous).fill(wa.red)
                    .frame(width: tab, height: 40)
                    .offset(x: CGFloat(selected) * tab)
                    .animation(.easeOut(duration: 0.28), value: selected)
                HStack(spacing: 0) {
                    ForEach(Array(options.enumerated()), id: \.offset) { index, option in
                        Button { onSelect(index) } label: {
                            HStack(spacing: 6) {
                                Image(systemName: option.1).font(.system(size: 14, weight: .semibold))
                                Text(option.0).font(.dorr(14, .heavy))
                            }
                            .foregroundStyle(index == selected ? .white : wa.mut)
                            .frame(maxWidth: .infinity, minHeight: 44)
                            .contentShape(Rectangle())
                            .animation(.easeOut(duration: 0.2), value: selected)
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
        .frame(height: 44)
        .padding(4)
        .background(wa.night ? wa.darkWell : Color(hex: 0xECEEF2), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}

/// A labelled input with a state icon (✓ / ✕) and a hint that turns red when wrong.
@MainActor
private struct InputCard: View {
    @Environment(\.wa) private var wa
    @Environment(\.walletHost) private var host
    let label: String
    let prefix: String?
    let prefixIcon: String
    @Binding var value: String
    let placeholder: String
    let state: FieldState
    let hint: String
    let keyboard: UIKeyboardType
    var flagCode: String?
    var errorMessage: String?
    @FocusState private var focused: Bool

    var body: some View {
        let isError = errorMessage != nil || state == .bad
        let border: Color = state == .ok ? wa.green : (isError ? wa.danger : (focused ? wa.red.opacity(0.45) : wa.line))
        // No card around the field: just its label, the bordered number box and the hint.
        VStack(alignment: .leading, spacing: 0) {
            Text(label).font(.dorr(12.5, .bold)).foregroundStyle(wa.mut).padding(.bottom, 8).padding(.leading, 2)
            HStack(spacing: 8) {
                Image(systemName: prefixIcon).font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.soft)
                if let flagCode, !flagCode.isEmpty {
                    AsyncImage(url: URL(string: "https://flagcdn.com/w40/\(flagCode.lowercased()).png")) { phase in
                        if let image = phase.image { image.resizable().scaledToFill() } else { Color.gray.opacity(0.15) }
                    }
                    .frame(width: 20, height: 15)
                    .clipShape(RoundedRectangle(cornerRadius: 2))
                }
                if let prefix, !prefix.isEmpty {
                    Text(prefix).font(.dorr(15, .heavy)).foregroundStyle(wa.ink)
                    wa.line.frame(width: 1, height: 25)
                }
                ZStack(alignment: .leading) {
                    if value.isEmpty {
                        Text(placeholder).font(.dorr(15, .medium)).kerning(0.5).foregroundStyle(wa.night ? wa.soft : Color(hex: 0xD1D5DB))
                    }
                    TextField("", text: $value)
                        .font(.dorr(15, .bold))
                        .kerning(0.5)
                        .foregroundStyle(wa.ink)
                        .tint(wa.red)
                        .keyboardType(keyboard)
                        .focused($focused)
                }
                if state == .ok {
                    Image(systemName: "checkmark").font(.system(size: 11, weight: .heavy)).foregroundStyle(.white)
                        .frame(width: 22, height: 22).background(wa.green, in: Circle())
                } else if isError {
                    Image(systemName: "xmark").font(.system(size: 11, weight: .heavy)).foregroundStyle(.white)
                        .frame(width: 22, height: 22).background(wa.danger, in: Circle())
                }
            }
            .padding(.horizontal, 14)
            .frame(height: 50)
            .background(focused ? wa.surface : wa.field, in: RoundedRectangle(cornerRadius: 15, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 15, style: .continuous).stroke(border, lineWidth: 1.5))
            .animation(.easeOut(duration: 0.2), value: border)
            .environment(\.layoutDirection, .leftToRight)
            Text(hint).font(.dorr(12)).foregroundStyle(isError ? wa.danger : wa.mut).lineSpacing(4).padding(.top, 8).padding(.leading, 2)
        }
        // The reason a field was refused arrives as a notification, not as a box under the field.
        .onChange(of: errorMessage) { message in
            if let message, !message.isEmpty { host?.showError(message) }
        }
    }
}

// MARK: - Step 2: confirm (androidApp WalletTransferConfirm.kt)

/// Step 2: *who is this?* — the recipient's name (first letter of each word, the rest hidden) and the number
/// that was typed, shown BEFORE any amount is chosen or any money moves — then the amount and the PIN. The
/// transfer only accepts the token the server issued at step 1, so what is on screen is provably who gets paid.
@MainActor
struct WalletTransferConfirm: View {
    @EnvironmentObject private var host: WalletHost
    @Environment(\.wa) private var wa
    let who: TransferRecipientDto

    @State private var amountText = ""
    @State private var error: String?
    @State private var idempotencyKey = UUID().uuidString
    @State private var sent: WalletTransactionDto?

    var body: some View {
        let balance = host.balance
        let currency = balance?.currencyCode ?? ""
        let amountMinor = parseAmountToMinor(amountText)
        // DORR's optional cut (a preview only — the rate actually applied is decided at send time).
        let feePercent = Double(who.feePercent) ?? 0
        let feeMinor = amountMinor.map { Int((Double($0) * feePercent / 100).rounded()) } ?? 0
        let senderPaysFee = who.feePayer == "sender"

        if let done = sent {
            WaPage(title: L("wa_transfer_confirm_title"), onBack: { host.pop() }, scroll: false) {
                ZStack {
                    WaConfetti()
                    WaStatusColumn {
                        WaSeal()
                        WaStatusTitle(text: L("wa_transfer_done_title"))
                        HStack(alignment: .lastTextBaseline, spacing: 6) {
                            Text(money(done.amountMinor)).font(.dorr(34, .heavy)).kerning(-0.5).foregroundStyle(wa.ink)
                            Text(currency).font(.dorr(15, .bold)).foregroundStyle(wa.mut)
                        }
                        .environment(\.layoutDirection, .leftToRight)
                        Text(L("wa_transfer_done_to", who.nameMasked)).font(.dorr(14)).foregroundStyle(wa.mut).padding(.top, 6).padding(.bottom, 16)
                        WaButton(text: L("wa_done")) { host.pop(); host.pop() }.padding(.horizontal, 20)
                    }
                }
            }
        } else {
            WaPage(title: L("wa_transfer_confirm_title"), onBack: { host.pop() }, cta: {
                // Matches the "total charged to you" line above when the sender pays the fee.
                WaButton(
                    text: amountMinor.map { L("wa_send_amount", money(senderPaysFee ? $0 + feeMinor : $0), currency) } ?? L("wa_send"),
                    icon: "arrow.up.right",
                    enabled: amountMinor != nil
                ) { send(amountMinor) }
                WaCtaHint(text: L("wa_protected_by_pin"), icon: "shield.fill")
            }) {
                RecipientCard(who: who).waRise(0)
                if who.numberRecentlyChanged {
                    WaNote(text: L("wa_transfer_number_changed_warning"), icon: "exclamationmark.triangle.fill").padding(.top, 12).waRise(0)
                }
                WaAmountEntry(
                    label: L("wa_transfer_how_much"), amountText: $amountText, currency: currency,
                    invalid: !amountText.trimmingCharacters(in: .whitespaces).isEmpty && amountMinor == nil
                ) {
                    if let balance {
                        HStack(spacing: 6) {
                            Image(systemName: "wallet.pass.fill").font(.system(size: 13)).foregroundStyle(wa.mut)
                            Text(L("wa_available")).font(.dorr(12.5)).foregroundStyle(wa.mut)
                            Text(verbatim: "\u{2066}\(money(balance.totalMinor - balance.heldMinor)) \(currency)\u{2069}").font(.dorr(12.5, .heavy)).foregroundStyle(wa.ink)
                        }
                        .padding(.top, 10)
                    }
                }
                .padding(.top, 12)
                .waRise(1)
                if let amountMinor, feeMinor > 0 {
                    WaCard(padding: 14) {
                        WaKeyValue(label: L("wa_transfer_amount_label"), value: "\(money(amountMinor)) \(currency)", ltr: true)
                        WaKeyValue(label: L("wa_transfer_fee_label", fmtPercent(feePercent)), value: "\(money(feeMinor)) \(currency)", ltr: true)
                        WaDivider()
                        WaKeyValue(
                            label: L(senderPaysFee ? "wa_transfer_total_label" : "wa_transfer_receives_label"),
                            value: "\(money(senderPaysFee ? amountMinor + feeMinor : amountMinor - feeMinor)) \(currency)",
                            bold: true, ltr: true
                        )
                    }
                    .padding(.top, 12)
                    .waRise(2)
                }
                WaNote(text: L("wa_spend_only_transfer_note")).padding(.top, 12).waRise(2)
                WaError(message: error)
            }
        }
    }

    private func send(_ amountMinor: Int?) {
        error = nil
        host.requestPin(subtitle: L("wa_pin_transfer_sub"), onError: { error = $0 }) { pin in
            guard let minor = amountMinor else { return .close(L("wa_error_generic")) }
            do {
                let result = try await WalletApi.transfer(pin: pin, idempotencyKey: idempotencyKey, TransferRequest(recipientToken: who.recipientToken, amountMinor: minor)).data
                idempotencyKey = UUID().uuidString
                guard let result else { return .close(L("wa_error_generic")) }
                sent = result
                await host.refreshBalance()
                return .done
            } catch {
                let failure = error.apiFailure
                // No HTTP answer: keep the key so a retry can't send twice.
                if failure.httpStatus == nil { return .retry(L("wa_error_network")) }
                idempotencyKey = UUID().uuidString
                let message = failure.message ?? L("wa_error_generic")
                if let code = failure.errorCode, pinErrorCodes.contains(code) { return .retry(message) }
                // The 10-minute confirmation ran out: go back and look the person up again.
                if failure.errorCode == "transfer_recipient_expired" {
                    host.closeSheet()
                    host.pop()
                    host.showToast(L("wa_transfer_expired"))
                    return .done
                }
                return .close(message)
            }
        }
    }
}

/// The "is this who you mean?" card: verified badge, avatar, masked name, number, country chips.
private struct RecipientCard: View {
    @Environment(\.wa) private var wa
    let who: TransferRecipientDto

    var body: some View {
        let initial = who.nameMasked.trimmingCharacters(in: .whitespaces).first.map(String.init) ?? ""
        let line = who.phone ?? groupWalletNumber(who.walletNumber ?? "")
        let lineIcon = who.phone != nil ? "phone.fill" : "wallet.pass.fill"
        ZStack(alignment: .top) {
            Group {
                if wa.night {
                    LinearGradient(colors: [wa.red.opacity(0.2), .clear], startPoint: .top, endPoint: .bottom)
                } else {
                    LinearGradient(colors: [wa.red.opacity(0.16), wa.red.opacity(0.04)], startPoint: .topLeading, endPoint: .bottomTrailing)
                }
            }
            .frame(height: 84)
            VStack(spacing: 0) {
                HStack(spacing: 6) {
                    Image(systemName: "shield.fill").font(.system(size: 13))
                    Text(L("wa_verify_badge")).font(.dorr(11.5, .heavy))
                }
                .foregroundStyle(wa.red)
                .padding(.horizontal, 12)
                .padding(.vertical, 5)
                .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: Capsule())
                ZStack {
                    Circle().stroke(wa.red.opacity(0.2), lineWidth: 3).frame(width: 84, height: 84)
                    Text(initial).font(.dorr(30, .heavy)).foregroundStyle(.white)
                        .frame(width: 72, height: 72)
                        .background(wa.buttonGradient, in: Circle())
                        .shadow(color: wa.red.opacity(0.35), radius: 10, y: 4)
                }
                .padding(.top, 14)
                Text(who.nameMasked).font(.dorr(22, .heavy)).foregroundStyle(wa.ink).multilineTextAlignment(.center).padding(.top, 12)
                HStack(spacing: 8) {
                    Text(line).font(.dorr(15, .heavy)).kerning(0.5).foregroundStyle(wa.ink)
                    Image(systemName: lineIcon).font(.system(size: 14)).foregroundStyle(wa.mut)
                }
                .padding(.horizontal, 14)
                .padding(.vertical, 8)
                .background(wa.night ? wa.darkWell : Color(hex: 0xF3F4F6), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .environment(\.layoutDirection, .leftToRight)
                .padding(.top, 10)
                HStack(spacing: 8) {
                    WaGlassChip(text: countryName(who.countryCode), icon: "globe", dark: true)
                    WaGlassChip(text: who.currencyCode ?? "", icon: "wallet.pass.fill", dark: true)
                }
                .padding(.top, 12)
            }
            .padding(.horizontal, 18)
            .padding(.top, 22)
            .padding(.bottom, 20)
            .frame(maxWidth: .infinity)
        }
        .background(wa.surface)
        .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
        .overlay { if wa.night { RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(wa.line, lineWidth: 1) } }
        .shadow(color: wa.night ? .clear : Color(hex: 0x111928).opacity(0.15), radius: 10, y: 4)
    }
}
