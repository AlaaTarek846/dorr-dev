import CoreText
import SwiftUI
import UIKit

// driverApp `ui/theme/DriverTheme.kt` — the palette (`Pal`, `DorrBrand`) from the platform's appearance tokens in light and
// dark, and Cairo type scaled by the captain's text size (D-307).

/// The palette. Read it from a view through `@Environment(\.pal) private var pal` (the root view puts the current one there).
struct Pal {
    let night: Bool
    private let tokens: [String: String]

    init(night: Bool, light: [String: String], dark: [String: String]) {
        self.night = night
        self.tokens = night ? dark : light
    }

    private func token(_ key: String, _ fallback: UInt32) -> Color { Color(hexString: tokens[key]) ?? Color(hex: fallback) }

    /// The page.
    var bg: Color { night ? token("background", 0x101216) : token("background", 0xFFFFFF) }
    /// Cards, sheets, bars.
    var card: Color { night ? token("surface", 0x1A1D24) : token("surface", 0xFFFFFF) }
    /// The soft tinted fill behind an icon or a chip.
    var well: Color { night ? Color(hex: 0x2C2226) : accent.opacity(0.14) }
    var line: Color { night ? token("border", 0x2C313A) : token("border", 0xE5E7EB) }
    var ink: Color { night ? token("textPrimary", 0xF4F5F7) : token("textPrimary", 0x111928) }
    /// Secondary text.
    var mut: Color { night ? token("textSecondary", 0x9AA1AC) : token("textSecondary", 0x6B7280) }
    /// Hints, placeholders, disabled.
    var faint: Color { night ? Color(hex: 0x8B93A0) : token("textMuted", 0x9CA3AF) }
    /// The brand colour: navy by day, orange at night.
    var accent: Color { night ? token("primary", 0xFA7552) : token("primary", 0x001B53) }
    /// The logo's orange, in both modes.
    var orange: Color { token("secondary", 0xFA7552) }
    var danger: Color { token("danger", 0xF05252) }
    var success: Color { Color(hex: 0x059669) }
    var warning: Color { Color(hex: 0xF59E0B) }
    var info: Color { Color(hex: 0x06B6D4) }
    /// Text field fill and hairline (the DORR app's pill fields).
    var fieldFill: Color { night ? bg : Color(hex: 0xFBF7F8) }
    var fieldLine: Color { night ? line : Color(hex: 0xF3D5DB) }

    /// `DorrBrand` — kept under its old name: `navy` is the brand colour of the moment, `orange` the logo's orange.
    var navy: Color { accent }
    var navyLight: Color { accent.opacity(0.85) }

    @MainActor
    static var current: Pal {
        let prefs = DriverPrefs.shared
        return Pal(night: prefs.night, light: prefs.platformLight, dark: prefs.platformDark)
    }
}

private struct PalKey: EnvironmentKey {
    static let defaultValue = Pal(night: false, light: [:], dark: [:])
}

extension EnvironmentValues {
    var pal: Pal {
        get { self[PalKey.self] }
        set { self[PalKey.self] = newValue }
    }
}

extension Color {
    init(hex: UInt32, alpha: Double = 1) {
        self.init(.sRGB, red: Double((hex >> 16) & 0xFF) / 255, green: Double((hex >> 8) & 0xFF) / 255, blue: Double(hex & 0xFF) / 255, opacity: alpha)
    }

    /// "#RRGGBB" only, anything else is nil.
    init?(hexString raw: String?) {
        guard var hex = raw?.trimmingCharacters(in: .whitespaces) else { return nil }
        if hex.hasPrefix("#") { hex.removeFirst() }
        guard hex.count == 6, let value = UInt32(hex, radix: 16) else { return nil }
        self.init(hex: value)
    }
}

/// Cairo is one variable font file (weights 200–1000); each weight is asked for through the `wght` axis, like Android's
/// `FontVariation.weight`. Sizes follow the captain's text size (`DriverPrefs.fontScale`) on top of the phone's.
enum DorrFont {
    private static let postScriptName = "Cairo-Regular"
    private static let weightAxis = 0x7767_6874 // 'wght'
    private static var cache: [String: UIFont] = [:]
    private static let lock = NSLock()

    static func clearCache() {
        lock.lock()
        cache = [:]
        lock.unlock()
    }

    static func uiFont(size: CGFloat, weight: Font.Weight) -> UIFont {
        let scale = UserDefaults.standard.double(forKey: "driver_look.font_scale")
        let scaled = size * (scale > 0 ? scale : 1)
        let value = axisValue(weight)
        let key = "\(scaled)-\(value)"
        lock.lock()
        defer { lock.unlock() }
        if let cached = cache[key] { return cached }
        let descriptor = UIFontDescriptor(fontAttributes: [
            .name: postScriptName,
            UIFontDescriptor.AttributeName(rawValue: kCTFontVariationAttribute as String): [weightAxis: value],
        ])
        let font = UIFont(descriptor: descriptor, size: scaled)
        let resolved = font.fontName.hasPrefix("Cairo") ? font : UIFont.systemFont(ofSize: scaled, weight: uiWeight(weight))
        cache[key] = resolved
        return resolved
    }

    private static func axisValue(_ weight: Font.Weight) -> CGFloat {
        switch weight {
        case .ultraLight: return 200
        case .thin: return 250
        case .light: return 300
        case .medium: return 500
        case .semibold: return 600
        case .bold: return 700
        case .heavy: return 800
        case .black: return 900
        default: return 400
        }
    }

    private static func uiWeight(_ weight: Font.Weight) -> UIFont.Weight {
        switch weight {
        case .medium: return .medium
        case .semibold: return .semibold
        case .bold: return .bold
        case .heavy: return .heavy
        case .black: return .black
        default: return .regular
        }
    }
}

extension Font {
    /// Cairo at a size in points (Android `sp`) and a weight — `FontWeight.ExtraBold` is `.heavy`, `Black` is `.black`.
    static func dorr(_ size: CGFloat, _ weight: Font.Weight = .regular) -> Font {
        Font(DorrFont.uiFont(size: size, weight: weight))
    }
}
