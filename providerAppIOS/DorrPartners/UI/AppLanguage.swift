import SwiftUI

/// providerApp `ui/locale/AppLanguage.kt` — the app language: the source of truth for both the X-Locale header (through
/// `AppLocale.current`) and the app's own strings. ar/en ship in the app; every other active language is downloaded from
/// the server (the strings it has for the providers app; the rest stays English). The root view is rebuilt on a change,
/// so every `L(…)` and the layout direction follow it.
@MainActor
final class AppLanguage: ObservableObject {
    static let shared = AppLanguage()

    @Published private(set) var code: String
    /// Bumped when the current downloaded language was replaced by a newer version.
    @Published private(set) var stringsRevision = 0

    private init() {
        // The saved language, or en when it is a downloaded one whose file is gone.
        let saved = AppLocale.current
        if isBundledLanguage(saved) || DownloadedTranslations.load(saved) != nil {
            code = saved
        } else {
            AppLocale.current = "en"
            code = "en"
        }
    }

    var isArabic: Bool { code == "ar" }

    /// Layout direction of the current language — saved with a downloaded one, so it holds offline.
    var isRtl: Bool {
        switch code {
        case "ar": return true
        case "en": return false
        default:
            if let meta = DownloadedTranslations.meta(), meta.code == code { return meta.direction == "rtl" }
            return Locale.characterDirection(forLanguage: code) == .rightToLeft
        }
    }

    func set(_ next: String) {
        let lower = next.lowercased()
        AppLocale.current = lower
        code = lower
    }

    /// ar ⇄ en — the sign-in page's one-tap switch; the settings page offers the whole list.
    func toggle() { set(isArabic ? "en" : "ar") }

    /// Switches to `next`. A bundled language applies at once and drops any downloaded file; any other language is
    /// downloaded first and applied only once the download succeeded. False when the download failed.
    func choose(_ next: String) async -> Bool {
        let target = next.lowercased()
        if isBundledLanguage(target) {
            set(target)
            DownloadedTranslations.clear()
            return true
        }
        if target == code && DownloadedTranslations.load(target) != nil { return true }
        switch await DownloadedTranslations.download(target) {
        case .success:
            if target == code { stringsRevision += 1 } else { set(target) }
            return true
        default:
            return false
        }
    }

    /// Keeps a downloaded current language in step with the server list: a newer `android_version` is downloaded and
    /// applied, and a language that is no longer offered falls back to en. Asked once per launch.
    func syncWithServer() async {
        guard !isBundledLanguage(code), let languages = await TranslationsApi.list().value, !languages.isEmpty else { return }
        guard let entry = languages.first(where: { $0.code.lowercased() == code }) else {
            fallBackToEnglish()
            return
        }
        if let stored = DownloadedTranslations.meta(), stored.code == code, let version = entry.androidVersion, stored.version == version { return }
        switch await DownloadedTranslations.download(code) {
        case .success: stringsRevision += 1
        case .notAvailable: fallBackToEnglish()
        case .failed: break
        }
    }

    private func fallBackToEnglish() {
        set("en")
        DownloadedTranslations.clear()
    }
}
