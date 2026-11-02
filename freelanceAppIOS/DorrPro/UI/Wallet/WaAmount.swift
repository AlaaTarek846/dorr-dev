// freelanceApp `ui/wallet/WaAmount.kt` (the same design as androidApp's; adapted from iosApp's WalletTransfer.swift)
import SwiftUI

/// The big centred amount entry used by top-up and transfer (`wa-amount`): label, a huge number field with
/// the currency next to it, optional quick-pick chips, and a brand glow while focused.
struct WaAmountEntry<Below: View>: View {
    @Environment(\.wa) private var wa
    let label: String
    @Binding var amountText: String
    let currency: String
    var quick: [Int] = []
    var invalid = false
    var autoFocus = false
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
        .task {
            guard autoFocus else { return }
            try? await Task.sleep(nanoseconds: 350_000_000)
            focused = true
        }
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
    init(label: String, amountText: Binding<String>, currency: String, quick: [Int] = [], invalid: Bool = false, autoFocus: Bool = false, hero: Bool = false,
         subtitle: String? = nil, footerLabel: String? = nil, footerValue: String? = nil) {
        self.init(label: label, amountText: amountText, currency: currency, quick: quick, invalid: invalid, autoFocus: autoFocus, hero: hero,
                  subtitle: subtitle, footerLabel: footerLabel, footerValue: footerValue, below: { EmptyView() })
    }
}

