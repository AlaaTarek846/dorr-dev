import Foundation

// One namespace per Android `network/*Api.kt` interface, same paths and names.

struct PhoneChangedDto: Codable { var phone: String? }

/// androidApp `MobileAuthApi.kt`.
enum MobileAuthApi {
    /// POST /api/mobile/v1/auth/otp — combined login/register: sends a phone OTP.
    static func requestOtp(_ body: OtpRequest) async throws -> ApiEnvelope<OtpSentDto> {
        try await ApiClient.post("mobile/v1/auth/otp", body: body)
    }

    /// POST /api/mobile/v1/auth/otp/restore — sends the OTP for a soft-deleted account.
    static func requestRestoreOtp(_ body: OtpRequest) async throws -> ApiEnvelope<OtpSentDto> {
        try await ApiClient.post("mobile/v1/auth/otp/restore", body: body)
    }

    /// POST /api/mobile/v1/auth/resend — resend the phone OTP.
    static func resendOtp(_ body: OtpRequest) async throws -> ApiEnvelope<OtpSentDto> {
        try await ApiClient.post("mobile/v1/auth/resend", body: body)
    }

    /// POST /api/mobile/v1/auth/verify — verify the OTP and get a Bearer token.
    static func verifyOtp(_ body: VerifyOtpRequest) async throws -> ApiEnvelope<AuthResultDto> {
        try await ApiClient.post("mobile/v1/auth/verify", body: body)
    }

    /// A code goes to the *new* number. Behind the wallet PIN once one exists — a first attempt without
    /// [pin] on such an account fails with `wallet_pin_required`, so the caller asks for the PIN and retries.
    static func startPhoneChange(_ body: OtpRequest, pin: String? = nil) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.post("mobile/v1/phone/change", body: body, auth: true, headers: pin.map { ["X-Wallet-Pin": $0] } ?? [:])
    }

    static func confirmPhoneChange(_ body: ConfirmCodeBody) async throws -> ApiEnvelope<PhoneChangedDto> {
        try await ApiClient.post("mobile/v1/phone/change/confirm", body: body, auth: true)
    }

    /// GET /api/mobile/v1/auth/me — authenticated profile.
    static func me() async throws -> ApiEnvelope<UserDto> {
        try await ApiClient.get("mobile/v1/auth/me", auth: true)
    }

    /// POST /api/mobile/v1/auth/logout — revoke the current bearer token (and forget this phone's push id).
    /// The token is passed in because the local session is already cleared when this runs.
    static func logout(token: String, _ body: LogoutRequest = LogoutRequest()) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.post("mobile/v1/auth/logout", body: body, headers: ["Authorization": "Bearer \(token)"])
    }
}

/// androidApp `CountryApi.kt`.
enum CountryApi {
    /// GET /api/general/v1/countries/dropdown — full active-country list, pre-login.
    static func list() async throws -> ApiEnvelope<[CountryDto]> {
        try await ApiClient.get("general/v1/countries/dropdown")
    }

    /// GET /api/general/v1/countries/detect — the caller's country by IP, in the dropdown shape.
    static func detect() async throws -> ApiEnvelope<CountryDto> {
        try await ApiClient.get("general/v1/countries/detect")
    }
}

/// androidApp `BrandingApi.kt`.
enum BrandingApi {
    static func get() async throws -> ApiEnvelope<BrandingDto> {
        try await ApiClient.get("general/v1/platform-settings/branding")
    }
}

/// androidApp `ServiceApi.kt`.
enum ServiceApi {
    /// GET /api/general/v1/services — active categories for the given audience (default `user`).
    /// Pass home=1 for the home dashboard subset only.
    static func list(audience: String = "user", home: Int? = nil) async throws -> ApiEnvelope<[ServiceDto]> {
        try await ApiClient.get("general/v1/services", query: ["audience": audience, "home": home.map(String.init)])
    }
}

/// androidApp `MobileAppearanceDefaultsApi.kt` + `AppearanceApi.kt`.
enum AppearanceApi {
    /// GET /api/general/v1/mobile-appearance-defaults — platform colors, pre-login.
    static func platformDefaults() async throws -> ApiEnvelope<AppearanceTokenSetDto> {
        try await ApiClient.get("general/v1/mobile-appearance-defaults")
    }

    static func show() async throws -> ApiEnvelope<AppearanceDto> {
        try await ApiClient.get("mobile/v1/appearance", auth: true)
    }

    static func update(_ body: AppearanceUpdateRequest) async throws -> ApiEnvelope<AppearanceDto> {
        try await ApiClient.put("mobile/v1/appearance", body: body)
    }
}

struct AppearanceDto: Codable {
    var usesDefaultColors: Bool?
    var customLightTokens: [String: String]?
    var customDarkTokens: [String: String]?
    var darkMode: String?
    var mobileAppFontId: Int?
    /// The font this account picked, resolved for the app.
    var font: MobileAppFontDto?
    /// Every font on offer, so the picker can list them.
    var availableFonts: [MobileAppFontDto]?
    var resolved: AppearanceTokenSetDto?
    var `default`: AppearanceTokenSetDto?

    enum CodingKeys: String, CodingKey {
        case resolved, `default`, font
        case availableFonts = "available_fonts"
        case usesDefaultColors = "uses_default_colors"
        case customLightTokens = "custom_light_tokens"
        case customDarkTokens = "custom_dark_tokens"
        case darkMode = "dark_mode"
        case mobileAppFontId = "mobile_app_font_id"
    }
}

/// androidApp `MobileAppFontDto.kt`.
struct MobileAppFontDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?
    var slug: String?
    var status: Bool
    var isDefault: Bool
    var sortOrder: Int
    var fontFiles: [MobileAppFontFileDto]?

    enum CodingKeys: String, CodingKey {
        case id, name, slug, status
        case isDefault = "is_default", sortOrder = "sort_order", fontFiles = "font_files"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        name = c.value(.name, nil)
        slug = c.value(.slug, nil)
        status = c.value(.status, true)
        isDefault = c.value(.isDefault, false)
        sortOrder = c.value(.sortOrder, 0)
        fontFiles = c.value(.fontFiles, nil)
    }
}

struct MobileAppFontFileDto: Codable, Equatable {
    var id: Int?
    var fileName: String?
    var url: String?
    var weight: String?

    enum CodingKeys: String, CodingKey {
        case id, url, weight
        case fileName = "file_name"
    }
}

struct AppearanceTokenSetDto: Codable {
    var lightTokens: [String: String]?
    var darkTokens: [String: String]?

    enum CodingKeys: String, CodingKey {
        case lightTokens = "light_tokens"
        case darkTokens = "dark_tokens"
    }
}

struct AppearanceUpdateRequest: Codable {
    var usesDefaultColors: Bool?
    var customLightTokens: [String: String]?
    var customDarkTokens: [String: String]?
    var darkMode: String?
    var mobileAppFontId: Int?

    enum CodingKeys: String, CodingKey {
        case usesDefaultColors = "uses_default_colors"
        case customLightTokens = "custom_light_tokens"
        case customDarkTokens = "custom_dark_tokens"
        case darkMode = "dark_mode"
        case mobileAppFontId = "mobile_app_font_id"
    }
}
