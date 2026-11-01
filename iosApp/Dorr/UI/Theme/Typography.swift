import CoreText
import SwiftUI
import UIKit

/// androidApp `ui/theme/Type.kt` — Cairo is one variable font file (weights 200–1000); each weight is
/// asked for through the `wght` axis, like Android's `FontVariation.weight`, not a static file per weight.
enum DorrFont {
    /// PostScript name of the bundled `Resources/Fonts/Cairo.ttf` (listed in Info.plist `UIAppFonts`).
    private static let postScriptName = "Cairo-Regular"
    private static let weightAxis = 0x7767_6874 // 'wght'

    fileprivate static var cache: [String: UIFont] = [:]

    static func uiFont(size: CGFloat, weight: Font.Weight) -> UIFont {
        let value = axisValue(weight)
        let key = "\(size)-\(value)"
        if let cached = cache[key] { return cached }
        if let remote = remoteFont(size: size, weight: weight) {
            cache[key] = remote
            return remote
        }
        let descriptor = UIFontDescriptor(fontAttributes: [
            .name: postScriptName,
            UIFontDescriptor.AttributeName(rawValue: kCTFontVariationAttribute as String): [weightAxis: value],
        ])
        let font = UIFont(descriptor: descriptor, size: size)
        // Not bundled (or renamed): the system font at the same weight, never a crash.
        let resolved = font.fontName.hasPrefix("Cairo") ? font : UIFont.systemFont(ofSize: size, weight: uiWeight(weight))
        cache[key] = resolved
        return resolved
    }

    fileprivate static func axisValue(_ weight: Font.Weight) -> CGFloat {
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
    /// The app font (Cairo) at a size in points (Android `sp`) and a weight — `FontWeight.ExtraBold` is `.heavy`.
    static func dorr(_ size: CGFloat, _ weight: Font.Weight = .regular) -> Font {
        Font(DorrFont.uiFont(size: size, weight: weight))
    }
}

extension DorrFont {
    /// A downloaded font, weight → PostScript name (nil = the bundled Cairo).
    private(set) static var family: [Int: String]?

    static func use(_ family: [Int: String]?) {
        self.family = family
        cache = [:]
    }

    /// The downloaded file nearest the asked weight, at that size.
    static func remoteFont(size: CGFloat, weight: Font.Weight) -> UIFont? {
        guard let family, !family.isEmpty else { return nil }
        let wanted = Int(axisValue(weight))
        guard let nearest = family.keys.min(by: { abs($0 - wanted) < abs($1 - wanted) }), let name = family[nearest] else { return nil }
        return UIFont(name: name, size: size)
    }
}

/// androidApp `ui/theme/AppFontLoader.kt` — the font the account picked: its files downloaded once into the
/// app's storage and registered with the system; Cairo (bundled) when none, or when every download fails.
enum AppFontLoader {
    private static let bundledCairoSlug = "cairo"

    static func load(_ font: MobileAppFontDto?) async -> [Int: String]? {
        guard let font, font.slug != bundledCairoSlug else { return nil }
        let files = (font.fontFiles ?? []).filter { !($0.url ?? "").isEmpty }
        guard !files.isEmpty else { return nil }
        let dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0].appendingPathComponent("fonts", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        var family: [Int: String] = [:]
        for entry in files {
            let weight = min(max(Int(entry.weight ?? "") ?? 400, 1), 1000)
            let safeName = (entry.fileName ?? "font.ttf").replacingOccurrences(of: "[^a-zA-Z0-9._-]", with: "_", options: .regularExpression)
            let dest = dir.appendingPathComponent("\(font.id)_\(weight)_\(safeName)")
            let size = (try? FileManager.default.attributesOfItem(atPath: dest.path)[.size] as? Int) ?? 0
            if size == 0 {
                guard let url = ApiClient.mediaUrl(entry.url),
                      let (data, response) = try? await URLSession.shared.data(from: url),
                      (response as? HTTPURLResponse).map({ (200..<300).contains($0.statusCode) }) ?? false,
                      (try? data.write(to: dest)) != nil else { continue }
            }
            guard let name = register(dest) else { continue }
            family[weight] = name
        }
        return family.isEmpty ? nil : family
    }

    /// Registers the file for this process and returns its PostScript name.
    private static func register(_ url: URL) -> String? {
        guard let provider = CGDataProvider(url: url as CFURL), let cgFont = CGFont(provider),
              let name = cgFont.postScriptName as String? else { return nil }
        if UIFont(name: name, size: 12) == nil {
            CTFontManagerRegisterFontsForURL(url as CFURL, .process, nil)
        }
        return UIFont(name: name, size: 12) == nil ? nil : name
    }
}
