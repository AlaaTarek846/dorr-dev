import SwiftUI
import UIKit

/// driverApp `ui/theme/DriverPrefs.kt` + `DriverLanguage.kt` — what the captain chose about how the app looks: dark /
/// light / follow the phone, the language (Arabic, English, or follow the phone), the text size — plus the platform's
/// colours from `GET general/v1/mobile-appearance-defaults`, kept so the first frame after a restart is already in the
/// right colours. Every property is published, so a change repaints the whole app without a restart (the root view is
/// rebuilt on a language change, like Android's `recreate()`).
@MainActor
final class DriverPrefs: ObservableObject {
    static let shared = DriverPrefs()

    static let modeSystem = "system"
    static let modeLight = "light"
    static let modeDark = "dark"

    static let langSystem = ""
    static let langAr = "ar"
    static let langEn = "en"

    private let store = UserDefaults.standard
    private static let prefix = "driver_look."

    @Published private(set) var darkMode: String
    @Published private(set) var language: String
    /// D-307: the text size (1 = normal).
    @Published private(set) var fontScale: CGFloat
    @Published private(set) var platformLight: [String: String]
    @Published private(set) var platformDark: [String: String]
    /// Whether the phone itself is dark now (kept by the root view, for "follow the phone").
    @Published var systemDark = UITraitCollection.current.userInterfaceStyle == .dark

    private init() {
        darkMode = store.string(forKey: Self.prefix + "dark_mode") ?? Self.modeSystem
        language = store.string(forKey: Self.prefix + "language") ?? Self.langSystem
        let scale = store.double(forKey: Self.prefix + "font_scale")
        fontScale = scale > 0 ? scale : 1
        platformLight = (store.dictionary(forKey: Self.prefix + "platform_light") as? [String: String]) ?? [:]
        platformDark = (store.dictionary(forKey: Self.prefix + "platform_dark") as? [String: String]) ?? [:]
    }

    /// Dark now? The chosen mode, or the phone's own setting while the choice is "system".
    var night: Bool {
        switch darkMode {
        case Self.modeDark: return true
        case Self.modeLight: return false
        default: return systemDark
        }
    }

    /// The colour scheme forced on the app (nil = the phone's).
    var colorScheme: ColorScheme? {
        switch darkMode {
        case Self.modeDark: return .dark
        case Self.modeLight: return .light
        default: return nil
        }
    }

    func chooseDarkMode(_ mode: String) {
        darkMode = mode
        store.set(mode, forKey: Self.prefix + "dark_mode")
    }

    /// Stores the choice; the root view is rebuilt so every string and the layout direction follow.
    func chooseLanguage(_ code: String) {
        guard language != code else { return }
        language = code
        store.set(code, forKey: Self.prefix + "language")
    }

    func chooseFontScale(_ scale: CGFloat) {
        fontScale = scale
        store.set(Double(scale), forKey: Self.prefix + "font_scale")
        DorrFont.clearCache()
    }

    func applyPlatform(light: [String: String]?, dark: [String: String]?) {
        if let light, !light.isEmpty { platformLight = light }
        if let dark, !dark.isEmpty { platformDark = dark }
        store.set(platformLight, forKey: Self.prefix + "platform_light")
        store.set(platformDark, forKey: Self.prefix + "platform_dark")
    }

    /// The language in use: the captain's choice, else the phone's when it is Arabic or English, else Arabic
    /// (CoreHttp's `X-Locale`: `Locale.getDefault().language.takeIf { ar / en } ?: "ar"`).
    nonisolated static var locale: String {
        let chosen = UserDefaults.standard.string(forKey: prefix + "language") ?? ""
        if !chosen.isEmpty { return chosen }
        let phone = Locale.preferredLanguages.first.map { String($0.prefix(2)) } ?? "ar"
        return phone == "ar" || phone == "en" ? phone : "ar"
    }

    /// The layout direction of the language in use.
    var isRtl: Bool { Self.locale == "ar" }
}

extension DriverPrefs {
    /// `stringResource` reads the bundle of the language in use.
    nonisolated static var bundle: Bundle {
        Bundle.main.path(forResource: locale, ofType: "lproj").flatMap(Bundle.init(path:)) ?? .main
    }
}

/// `stringResource(R.string.key, args…)`. Keys and text come from driverApp's XML through `scripts/sync_strings.py`.
func L(_ key: String, _ args: CVarArg...) -> String {
    let format = DriverPrefs.bundle.localizedString(forKey: key, value: nil, table: nil)
    return args.isEmpty ? format : String(format: format, locale: Locale(identifier: DriverPrefs.locale), arguments: args)
}

/// `stringArrayResource(R.array.key)` — the `key##0`, `key##1`… entries the sync script writes.
func La(_ key: String) -> [String] {
    var out: [String] = []
    while out.count < 100 {
        let k = "\(key)##\(out.count)"
        let value = L(k)
        if value == k || value.isEmpty { break }
        out.append(value)
    }
    return out
}

/// `pluralStringResource` — the `key##one` / `key##other` entries the sync script writes.
func Lp(_ key: String, _ count: Int, _ args: CVarArg...) -> String {
    let arabic = DriverPrefs.locale == "ar"
    let quantity = count == 1 ? "one" : (arabic && count == 2 ? "two" : (count == 0 ? "zero" : (arabic && (3...10).contains(count % 100) ? "few" : (arabic && count % 100 >= 11 ? "many" : "other"))))
    let bundle = DriverPrefs.bundle
    let found = bundle.localizedString(forKey: "\(key)##\(quantity)", value: "", table: nil)
    let format = (found.isEmpty || found == "\(key)##\(quantity)") ? bundle.localizedString(forKey: "\(key)##other", value: nil, table: nil) : found
    return String(format: format, locale: Locale(identifier: DriverPrefs.locale), arguments: args.isEmpty ? [count] : args)
}
