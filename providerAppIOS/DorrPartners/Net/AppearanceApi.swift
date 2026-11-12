import Foundation

// providerApp `net/AppearanceApi.kt` and `ui/theme/Appearance.kt` (AppearanceStore) — the app's colours, dark mode and font
// under `/api/provider/v1/appearance` (ProviderAppearanceController): the same payload as the DORR app's
// `mobile/v1/appearance`, from the admin's settings, so the app looks exactly like the other DORR apps (§12). Read only —
// there is no per-person change on the server yet. Also the interface languages (ar / en bundled, every other active
// language downloaded), and the small saved copy of both.

struct AppearanceDto: Codable, Equatable {
    var usesDefaultColors: Bool?
    var customLightTokens: [String: String]?
    var customDarkTokens: [String: String]?
    var darkMode: String?
    var customizableTokenKeys: [String]?
    /// The font this account picked, resolved for the app.
    var mobileAppFontId: Int?
    var font: MobileAppFontDto?
    /// Every font on offer, so the picker can list them.
    var availableFonts: [MobileAppFontDto]?
    var resolved: AppearanceTokenSetDto?
    var `default`: AppearanceTokenSetDto?
}

struct AppearanceTokenSetDto: Codable, Equatable {
    var lightTokens: [String: String]?
    var darkTokens: [String: String]?
}

struct MobileAppFontDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?
    var slug: String?
    var status: Bool?
    var isDefault: Bool?
    var sortOrder: Int?
    var fontFiles: [MobileAppFontFileDto]?
}

struct MobileAppFontFileDto: Codable, Equatable {
    var id: Int?
    var fileName: String?
    var url: String?
    var weight: String?
}

struct AppLanguageDto: Codable, Equatable, Identifiable {
    var id: Int?
    var code: String
    var name: String?
    var direction: String?
    var flag: FlagDto?
    /// Version of the downloadable strings; nil for the bundled ar/en.
    var androidVersion: String?
}

enum AppearanceApi {
    static func show() async -> ApiResult<AppearanceDto> { await Api.call("GET", Api.provider + "appearance") }

    /// Which map to draw — Google, Mapbox (token + style) or, on the iPhone, Apple Maps: the admin's choice (Map/DorrMap.swift
    /// `MapEngine`), the same payload as the captain's `driver/v1/map-config`. Read once after sign-in.
    static func mapConfig() async -> ApiResult<MapEngineConfig> { await Api.call("GET", Api.provider + "map-config") }
}

enum TranslationsApi {
    /// ar/en (bundled) plus the active languages with published strings, each with its version. Pre-login.
    static func list() async -> ApiResult<[AppLanguageDto]> {
        await Api.call("GET", "general/v1/translations/languages", query: ["platform": "android"])
    }

    /// `{code, direction, version, strings}` as it came: the `strings` object is stored on disk as it is.
    static func androidStrings(_ code: String) async -> Data? { await Api.bytes("general/v1/translations/\(code)/android") }
}

/// The saved appearance (one small store): the signed-in account's own, and the platform tokens.
enum AppearanceStore {
    private static let userKey = "dorr_provider_appearance.appearance_user"
    private static let lightKey = "dorr_provider_appearance.platform_light_tokens"
    private static let darkKey = "dorr_provider_appearance.platform_dark_tokens"

    static func loadUser() -> AppearanceDto? {
        UserDefaults.standard.data(forKey: userKey).flatMap { try? JSONDecoder().decode(AppearanceDto.self, from: $0) }
    }

    static func saveUser(_ dto: AppearanceDto) { UserDefaults.standard.set(try? JSONEncoder().encode(dto), forKey: userKey) }

    static func clearUser() { UserDefaults.standard.removeObject(forKey: userKey) }

    static func loadPlatformLight() -> [String: String]? { UserDefaults.standard.dictionary(forKey: lightKey) as? [String: String] }

    static func loadPlatformDark() -> [String: String]? { UserDefaults.standard.dictionary(forKey: darkKey) as? [String: String] }

    static func savePlatform(light: [String: String]?, dark: [String: String]?) {
        if let light { UserDefaults.standard.set(light, forKey: lightKey) }
        if let dark { UserDefaults.standard.set(dark, forKey: darkKey) }
    }
}
