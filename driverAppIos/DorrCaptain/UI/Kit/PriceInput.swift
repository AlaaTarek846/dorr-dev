import SwiftUI

// driverApp `ui/PriceInput.kt`.

/// A price the captain can type straight in — or move with − / + by a real step (the server's first
/// step, 5 by default), not one unit per tap (O-43). `onChange` gets minor units; the caller decides
/// what is allowed (the send button stays off outside `minMinor`…`maxMinor`).
struct PriceInput: View {
    @Environment(\.pal) private var pal
    let priceMinor: Int
    let currency: CurrencyDto?
    let stepMinor: Int
    let minMinor: Int
    let maxMinor: Int
    let onChange: (Int) -> Void

    @State private var text: String
    @FocusState private var editing: Bool

    init(priceMinor: Int, currency: CurrencyDto?, stepMinor: Int, minMinor: Int, maxMinor: Int, onChange: @escaping (Int) -> Void) {
        self.priceMinor = priceMinor
        self.currency = currency
        self.stepMinor = stepMinor
        self.minMinor = minMinor
        self.maxMinor = maxMinor
        self.onChange = onChange
        _text = State(initialValue: Self.shown(priceMinor, places: currency?.decimals ?? 2))
    }

    private var places: Int { currency?.decimals ?? 2 }

    private static func unit(_ places: Int) -> Decimal {
        var u = Decimal(1)
        for _ in 0..<Swift.max(places, 0) { u *= 10 }
        return u
    }

    private static func shown(_ minor: Int, places: Int) -> String {
        let major = Decimal(minor) / unit(places)
        var rounded = Decimal()
        var copy = major
        NSDecimalRound(&rounded, &copy, 0, .plain)
        if rounded == major { return NSDecimalNumber(decimal: major).stringValue }
        return String(format: "%.\(places)f", locale: Locale(identifier: "en_US_POSIX"), NSDecimalNumber(decimal: major).doubleValue)
    }

    private func clamp(_ v: Int) -> Int { Swift.min(Swift.max(v, minMinor), maxMinor) }

    var body: some View {
        let outOfRange = priceMinor < minMinor || priceMinor > maxMinor
        let shape = RoundedRectangle(cornerRadius: 16)
        HStack(spacing: 0) {
            StepButton(icon: "minus", enabled: priceMinor - stepMinor >= minMinor) {
                editing = false
                onChange(clamp(priceMinor - stepMinor))
            }
            HSpace(10)
            HStack(spacing: 0) {
                TextField("", text: Binding(get: { text }, set: { raw in
                    let clean = String(raw.replacingOccurrences(of: ",", with: ".").filter { $0.isASCII && ($0.isNumber || $0 == ".") }.prefix(9))
                    if clean.filter({ $0 == "." }).count > 1 || (places == 0 && clean.contains(".")) { return }
                    text = clean
                    if let value = Decimal(string: clean, locale: Locale(identifier: "en_US_POSIX")), !clean.isEmpty {
                        var scaled = value * Self.unit(places)
                        var whole = Decimal()
                        NSDecimalRound(&whole, &scaled, 0, .down)
                        onChange(NSDecimalNumber(decimal: whole).intValue)
                    }
                }))
                .font(.dorr(24, .heavy))
                .multilineTextAlignment(.center)
                .foregroundStyle(pal.ink)
                .tint(pal.orange)
                .keyboardType(places > 0 ? .decimalPad : .numberPad)
                .submitLabel(.done)
                .onSubmit { editing = false }
                .focused($editing)
                .frame(width: 120)
                if let symbol = currency?.symbol, !symbol.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(symbol).font(.dorr(14, .semibold)).foregroundStyle(pal.ink.opacity(0.6))
                }
            }
            .padding(.horizontal, 12)
            .frame(maxWidth: .infinity)
            .frame(height: 56)
            .background(shape.fill(pal.ink.opacity(0.05)))
            .overlay(shape.strokeBorder(outOfRange ? pal.danger : (editing ? pal.orange : pal.ink.opacity(0.12)), lineWidth: editing ? 2 : 1))
            HSpace(10)
            StepButton(icon: "plus", enabled: priceMinor + stepMinor <= maxMinor) {
                editing = false
                onChange(clamp(priceMinor + stepMinor))
            }
        }
        .frame(maxWidth: .infinity)
        // The buttons and the chips move the number; while typing, the field keeps what was typed.
        .onChange(of: priceMinor) { if !editing { text = Self.shown($0, places: places) } }
        .onChange(of: editing) { if !$0 { text = Self.shown(priceMinor, places: places) } }
    }
}

private struct StepButton: View {
    @Environment(\.pal) private var pal
    let icon: String
    let enabled: Bool
    let onClick: () -> Void

    var body: some View {
        DorrIcon(icon, size: 24, tint: .white)
            .frame(width: 48, height: 48)
            .background(Circle().fill(enabled ? pal.navy : Color.gray.opacity(0.3)))
            .contentShape(Circle())
            .onTapGesture { if enabled { onClick() } }
    }
}
