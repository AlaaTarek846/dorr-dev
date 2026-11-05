// providerApp `ui/wallet/WaPin.kt` (androidApp's pad without the sheet; ported from iosApp)
import SwiftUI
import UIKit

/// What the pad does once four digits are in.
enum PadResult {
    /// All good, the caller moves on.
    case ok
    /// Shake, show the message, clear so typing can start again.
    case error(String)
    /// Clear silently (moving to the next step).
    case reset
    /// A temporary server-side lock (too many wrong PINs) — the pad itself disappears behind a live
    /// countdown and refuses all input until it ends, so a person can't keep sending doomed attempts
    /// (and, per docs/wallet-tasks.md §10.6, can't turn a temporary lock into a permanent freeze).
    case locked(Date)
}

// MARK: - Weighted column (Compose's Modifier.weight inside a Column)

struct WaLayoutWeight: LayoutValueKey {
    static let defaultValue: CGFloat = 0
}

extension View {
    /// Inside a [WaWeightedColumn]: take this share of the height left after the fixed-size children.
    func waLayoutWeight(_ weight: CGFloat) -> some View { layoutValue(key: WaLayoutWeight.self, value: weight) }
}

/// A column whose children are either their natural height (weight 0) or a share of what is left.
struct WaWeightedColumn: Layout {
    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? subviews.map { $0.sizeThatFits(.unspecified).width }.max() ?? 0
        let height = proposal.height ?? subviews.map { $0.sizeThatFits(ProposedViewSize(width: width, height: nil)).height }.reduce(0, +)
        return CGSize(width: width, height: height)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        let fixed = subviews.filter { $0[WaLayoutWeight.self] == 0 }
            .map { $0.sizeThatFits(ProposedViewSize(width: bounds.width, height: nil)).height }
            .reduce(0, +)
        let totalWeight = subviews.map { $0[WaLayoutWeight.self] }.reduce(0, +)
        let free = max(bounds.height - fixed, 0)
        var y = bounds.minY
        for subview in subviews {
            let weight = subview[WaLayoutWeight.self]
            let height = weight > 0 && totalWeight > 0
                ? free * weight / totalWeight
                : subview.sizeThatFits(ProposedViewSize(width: bounds.width, height: nil)).height
            subview.place(at: CGPoint(x: bounds.midX, y: y), anchor: .top, proposal: ProposedViewSize(width: bounds.width, height: height))
            y += height
        }
    }
}

/// A row whose children share the width by their [layoutWeight] (Compose's `Modifier.weight` in a Row),
/// with a fixed gap between them; the row is as tall as its tallest child.
struct WaWeightedRow: Layout {
    var spacing: CGFloat = 0

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? 0
        let widths = columnWidths(width, subviews)
        let height = zip(subviews, widths).map { $0.sizeThatFits(ProposedViewSize(width: $1, height: nil)).height }.max() ?? 0
        return CGSize(width: width, height: height)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x = bounds.minX
        for (subview, width) in zip(subviews, columnWidths(bounds.width, subviews)) {
            subview.place(at: CGPoint(x: x, y: bounds.minY), anchor: .topLeading, proposal: ProposedViewSize(width: width, height: bounds.height))
            x += width + spacing
        }
    }

    private func columnWidths(_ width: CGFloat, _ subviews: Subviews) -> [CGFloat] {
        let weights = subviews.map { max($0[WaLayoutWeight.self], 0.0001) }
        let total = weights.reduce(0, +)
        let free = max(width - spacing * CGFloat(max(subviews.count - 1, 0)), 0)
        return weights.map { free * $0 / total }
    }
}

// MARK: - The pad

/// The four-dot PIN pad used everywhere (wallet gate, PIN sheet, PIN settings) — same as the preview's
/// `pinPadMarkup` + `mountPinPad`: dots that pop in, a shake on a wrong PIN, a keypad that ignores taps
/// while the server is being asked.
@MainActor
struct WaPinPad: View {
    @Environment(\.wa) private var wa
    @Environment(\.walletHost) private var host
    let title: String
    let sub: String
    var icon = "lock.fill"
    var tone: Tone = .red
    /// A lock already known when the pad opens (from the PIN status) — start on the countdown.
    var lockedUntil: Date?
    let onComplete: @MainActor (String) async -> PadResult

    @State private var value = ""
    @State private var busy = false
    @State private var error = ""
    @State private var bad = false
    @State private var shake: CGFloat = 0
    @State private var lockedUntilDate: Date?
    @State private var remainingSeconds = 0
    @State private var started = false

    var body: some View {
        Group {
            if let until = lockedUntilDate {
                WaPinLockedCountdown(icon: icon, remainingSeconds: remainingSeconds)
                    .task(id: until) { await tick(until) }
            } else {
                pad
            }
        }
        .onAppear {
            guard !started else { return }
            started = true
            if let lockedUntil, lockedUntil > Date() { lockedUntilDate = lockedUntil }
        }
        // A new lock handed in (the PIN status reloaded) restarts the countdown.
        .onChange(of: lockedUntil) { until in
            lockedUntilDate = until.flatMap { $0 > Date() ? $0 : nil }
        }
    }

    private var pad: some View {
        WaWeightedColumn {
            Color.clear.waLayoutWeight(0.12)
            card
            Color.clear.waLayoutWeight(0.25)
            keypad.waLayoutWeight(3.2)
        }
        .opacity(busy ? 0.55 : 1)
    }

    private var card: some View {
        VStack(spacing: 0) {
            WaIconWell(icon: icon, tone: tone, size: 58, iconSize: 28)
            Text(title).font(.dorr(18, .heavy)).foregroundStyle(wa.ink).multilineTextAlignment(.center).padding(.top, 10)
            Text(error.isEmpty ? sub : error)
                .font(.dorr(13))
                .foregroundStyle(error.isEmpty ? wa.mut : Color(hex: 0xDC2626))
                .multilineTextAlignment(.center)
                .frame(minHeight: 20)
                .padding(.top, 4)
            HStack(spacing: 16) {
                ForEach(0..<4, id: \.self) { index in
                    let on = index < value.count
                    let fill: Color = on ? (bad ? wa.danger : wa.red) : .clear
                    Circle()
                        .fill(fill)
                        .overlay(Circle().stroke(on ? fill : (wa.night ? wa.line : Color(hex: 0xD1D5DB)), lineWidth: 2))
                        .frame(width: 16, height: 16)
                        .scaleEffect(on ? 1.18 : 1)
                        .animation(.spring(response: 0.3, dampingFraction: 0.45), value: on)
                }
            }
            .offset(x: shake * 6)
            .padding(.top, 16)
            .environment(\.layoutDirection, .leftToRight)
            Spacer().frame(height: 14)
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 18)
        .frame(maxWidth: .infinity)
        .background(wa.night ? wa.darkCard : wa.surface, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
        .overlay { if wa.night { RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(wa.line, lineWidth: 1) } }
        .shadow(color: wa.night ? .clear : wa.red.opacity(0.14), radius: 12, y: 4)
        .padding(.horizontal, 8)
    }

    /// Digits always read left-to-right, whatever the app language. The keypad stretches to fill the
    /// screen down to the bottom bar.
    private var keypad: some View {
        VStack(spacing: 10) {
            ForEach([["1", "2", "3"], ["4", "5", "6"], ["7", "8", "9"], ["", "0", "del"]], id: \.self) { row in
                HStack(spacing: 10) {
                    ForEach(row, id: \.self) { key in
                        if key.isEmpty {
                            Color.clear.frame(maxWidth: .infinity, maxHeight: .infinity)
                        } else {
                            PadKey(key: key) { press(key) }
                        }
                    }
                }
                .frame(maxHeight: .infinity)
            }
        }
        .frame(maxWidth: 320)
        .padding(.bottom, 6)
        .environment(\.layoutDirection, .leftToRight)
    }

    private func press(_ key: String) {
        guard !busy, lockedUntilDate == nil else { return }
        error = ""
        if key == "del" {
            if !value.isEmpty { value.removeLast() }
        } else if value.count < 4 {
            value += key
        }
        if value.count == 4 { complete(value) }
    }

    private func complete(_ pin: String) {
        Task {
            busy = true
            let result = await onComplete(pin)
            busy = false
            switch result {
            case .error(let message):
                if !message.trimmingCharacters(in: .whitespaces).isEmpty {
                    if let host { host.showError(message) } else { error = message }
                }
                value = ""
                bad = true
                UINotificationFeedbackGenerator().notificationOccurred(.error)
                for _ in 0..<3 {
                    withAnimation(.linear(duration: 0.07)) { shake = 1 }
                    try? await Task.sleep(nanoseconds: 70_000_000)
                    withAnimation(.linear(duration: 0.07)) { shake = -1 }
                    try? await Task.sleep(nanoseconds: 70_000_000)
                }
                withAnimation(.linear(duration: 0.06)) { shake = 0 }
                bad = false
            case .locked(let until):
                value = ""
                error = ""
                lockedUntilDate = until
            case .reset:
                value = ""
            case .ok:
                break
            }
        }
    }

    /// Ticks the countdown while locked; clears itself when time is up — never fires a request on its own.
    private func tick(_ until: Date) async {
        while !Task.isCancelled {
            let left = Int(until.timeIntervalSinceNow)
            if left <= 0 {
                remainingSeconds = 0
                lockedUntilDate = nil
                return
            }
            remainingSeconds = left
            try? await Task.sleep(nanoseconds: 1_000_000_000)
        }
    }
}

/// Replaces the pad entirely while a temporary lock is in effect — a live mm:ss countdown, no digits,
/// nothing to press. Goes away on its own once the countdown reaches zero; never talks to the network.
private struct WaPinLockedCountdown: View {
    @Environment(\.wa) private var wa
    let icon: String
    let remainingSeconds: Int

    var body: some View {
        WaWeightedColumn {
            Color.clear.waLayoutWeight(0.3)
            VStack(spacing: 0) {
                WaIconWell(icon: icon, tone: .amber, size: 58, iconSize: 28)
                Text(L("wa_pin_locked_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink).multilineTextAlignment(.center).padding(.top, 14)
                Text(L("wa_pin_locked_sub")).font(.dorr(13)).foregroundStyle(wa.mut).multilineTextAlignment(.center)
                    .padding(.horizontal, 24).padding(.top, 6)
                Text(String(format: "%02d:%02d", remainingSeconds / 60, remainingSeconds % 60))
                    .font(.dorr(40, .heavy))
                    .kerning(2)
                    .foregroundStyle(wa.red)
                    .environment(\.layoutDirection, .leftToRight)
                    .padding(.top, 18)
            }
            .frame(maxWidth: .infinity)
            Color.clear.waLayoutWeight(0.5)
        }
    }
}

private struct PadKey: View {
    @Environment(\.wa) private var wa
    let key: String
    let action: () -> Void

    var body: some View {
        // Every digit is a filled rounded box that stands out from the page; the delete key is only its icon.
        let isDelete = key == "del"
        // At night: lighter than the card it sits on, so the key stays visible on the page and in the PIN sheet.
        let keyDark = wa.darkCard.blendedWhite(0.08)
        Button(action: action) {
            ZStack {
                RoundedRectangle(cornerRadius: 18, style: .continuous)
                    .fill(isDelete ? Color.clear : (wa.night ? keyDark : wa.field))
                if isDelete {
                    Image(systemName: "delete.left").font(.system(size: 21, weight: .semibold)).foregroundStyle(wa.ink)
                } else {
                    Text(key).font(.dorr(23, .bold)).foregroundStyle(wa.ink)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .contentShape(Rectangle())
        }
        .buttonStyle(WaPressStyle(scale: 0.9))
        .accessibilityLabel(isDelete ? "⌫" : key)
    }
}

extension Color {
    /// `Color.White.copy(alpha).compositeOver(this)`.
    func blendedWhite(_ alpha: CGFloat) -> Color {
        var r: CGFloat = 0, g: CGFloat = 0, b: CGFloat = 0, a: CGFloat = 0
        UIColor(self).getRed(&r, green: &g, blue: &b, alpha: &a)
        return Color(red: Double(r + (1 - r) * alpha), green: Double(g + (1 - g) * alpha), blue: Double(b + (1 - b) * alpha))
    }
}
