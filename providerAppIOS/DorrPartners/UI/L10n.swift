import Foundation

/// `stringResource(R.string.key, args…)` on iOS. Keys and text come from the Android XML through
/// `providerAppIOS/scripts/sync_strings.py`; the language is the in-app one ([AppLocale]), not the phone's.
/// A downloaded language (anything but ar/en) reads its own strings first and falls back to English,
/// like Android's `DynamicResources` over the default `values/`.
func L(_ key: String, _ args: CVarArg...) -> String {
    let format = L10n.text(key) ?? L10n.bundle.localizedString(forKey: key, value: nil, table: nil)
    return args.isEmpty ? format : String(format: format, locale: AppLocale.locale, arguments: args)
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

/// `pluralStringResource` — reads the `key##one` / `key##other` entries the sync script writes (or the
/// `{quantity: text}` map of a downloaded language).
func Lp(_ key: String, _ count: Int, _ args: CVarArg...) -> String {
    let quantity = count == 1 ? "one" : (AppLocale.isArabic && count == 2 ? "two" : (count == 0 ? "zero" : "other"))
    var format = L10n.plural(key, quantity)
    if format == nil {
        let bundle = L10n.bundle
        let found = bundle.localizedString(forKey: "\(key)##\(quantity)", value: "", table: nil)
        format = (found.isEmpty || found == "\(key)##\(quantity)") ? bundle.localizedString(forKey: "\(key)##other", value: nil, table: nil) : found
    }
    return String(format: format ?? key, locale: AppLocale.locale, arguments: args.isEmpty ? [count] : args)
}

enum L10n {
    /// ar / en from the app; a downloaded language falls back to English for anything it lacks.
    static var bundle: Bundle {
        let code = isBundledLanguage(AppLocale.current) ? AppLocale.current : "en"
        return Bundle.main.path(forResource: code, ofType: "lproj").flatMap(Bundle.init(path:)) ?? .main
    }

    /// The downloaded language's text for [key], with Android's `%1$s` / `%d` turned into iOS formats.
    static func text(_ key: String) -> String? {
        guard !isBundledLanguage(AppLocale.current),
              let value = DownloadedTranslations.load(AppLocale.current)?[key] as? String else { return nil }
        return iosFormat(value)
    }

    static func plural(_ key: String, _ quantity: String) -> String? {
        guard !isBundledLanguage(AppLocale.current),
              let map = DownloadedTranslations.load(AppLocale.current)?[key] as? [String: Any] else { return nil }
        return ((map[quantity] ?? map["other"]) as? String).map(iosFormat)
    }

    private static let formatPattern = try? NSRegularExpression(pattern: #"%(\d+\$)?([-#+ 0,(]*\d*(?:\.\d+)?)([sd])"#)

    /// The same conversion `sync_strings.py` applies to the bundled strings.
    static func iosFormat(_ text: String) -> String {
        guard let formatPattern, text.contains("%") else { return text }
        let range = NSRange(text.startIndex..., in: text)
        var result = text
        for match in formatPattern.matches(in: text, range: range).reversed() {
            guard let whole = Range(match.range, in: text), let kindRange = Range(match.range(at: 3), in: text) else { continue }
            let position = Range(match.range(at: 1), in: text).map { String(text[$0]) } ?? ""
            let flags = Range(match.range(at: 2), in: text).map { String(text[$0]) } ?? ""
            let replacement = "%\(position)\(flags)\(text[kindRange] == "s" ? "@" : "ld")"
            result.replaceSubrange(Range(match.range, in: result) ?? whole, with: replacement)
        }
        return result
    }
}
