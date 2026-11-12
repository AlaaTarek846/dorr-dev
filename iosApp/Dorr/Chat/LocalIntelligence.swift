import Foundation

// androidApp `chat/LocalIntelligence.kt` — local private intelligence (spec 156): a notification's text is looked at on
// this phone only — nothing of it goes anywhere — to show what kind of message it is and to show less of it. Shared
// with the notification extension (DorrNotifications), which applies it to each chat push as it arrives.

enum LocalIntelligence {
    enum Kind {
        case code, money, appointment, question
        var emoji: String {
            switch self {
            case .code: return "🔑"
            case .money: return "💸"
            case .appointment: return "🗓️"
            case .question: return "❓"
            }
        }
        var label: String {
            switch self {
            case .code: return "lp_kind_code"
            case .money: return "lp_kind_money"
            case .appointment: return "lp_kind_appointment"
            case .question: return "lp_kind_question"
            }
        }
        var sensitive: Bool { self == .code || self == .money }
    }

    static let store: UserDefaults = {
        let bundle = Bundle.main.bundleIdentifier ?? "com.dorr.app"
        let app = bundle.hasSuffix(".notifications") ? String(bundle.dropLast(".notifications".count)) : bundle
        return UserDefaults(suiteName: "group.\(app)") ?? .standard
    }()

    static var enabled: Bool {
        get { store.bool(forKey: "li.on") }
        set { store.set(newValue, forKey: "li.on") }
    }
    static var hideSensitive: Bool {
        get { store.object(forKey: "li.hide_sensitive") as? Bool ?? true }
        set { store.set(newValue, forKey: "li.hide_sensitive") }
    }
    static var keywords: [String] {
        get { (store.stringArray(forKey: "li.keywords") ?? []).sorted() }
        set { store.set(Array(Set(newValue.map { $0.trimmingCharacters(in: .whitespaces) }.filter { $0.count >= 2 }).prefix(50)), forKey: "li.keywords") }
    }

    private static func has(_ pattern: String, _ text: String) -> Bool { text.range(of: pattern, options: [.regularExpression, .caseInsensitive]) != nil }

    /// What kind of message this is, judged here (first match wins: a code before money…).
    static func kindOf(_ text: String) -> Kind? {
        if has("(code|otp|pin|verification|verify|كود|رمز|الرمز|تحقق|التحقق|كلمة السر)", text) && has("(?<!\\d)\\d{4,8}(?!\\d)", text) { return .code }
        if has("(\\d[\\d.,]*\\s?(sar|egp|aed|usd|\\$|€|£|ريال|ر\\.س|جنيه|ج\\.م|درهم|دولار))|((sar|egp|aed|usd|ريال|جنيه|درهم)\\s?\\d)", text) { return .money }
        if has("(\\b\\d{1,2}[:.]\\d{2}\\b|tomorrow|tonight|today at|بكره|بكرة|النهارده الساعة|الساعة \\d|يوم (السبت|الأحد|الاثنين|الإثنين|التلات|الثلاثاء|الأربع|الأربعاء|الخميس|الجمعة)|موعد|ميعاد)", text) { return .appointment }
        if has("([?؟]\\s*$|^(هل|ممكن|امتى|إمتى|فين|ليه|إزاي|ازاي|مين|كام|what|when|where|why|how|who|can you|could you|do you)\\b)", text.trimmingCharacters(in: .whitespacesAndNewlines)) { return .question }
        return nil
    }

    static func matchesKeyword(_ text: String) -> String? { keywords.first { text.range(of: $0, options: .caseInsensitive) != nil } }

    // Read by the notification extension too (DorrNotifications), so everything lives in the app group.

    /// The app's language, for the extension's labels (it can't read the app's own settings).
    static var language: String {
        get { store.string(forKey: "app.locale") ?? "ar" }
        set { store.set(newValue, forKey: "app.locale") }
    }

    /// Private pushes folded into one «N new messages» (`ChatShield.showPrivate` on Android).
    static var privateCount: Int {
        get { store.integer(forKey: "shield.private_count") }
        set { store.set(newValue, forKey: "shield.private_count") }
    }
}
