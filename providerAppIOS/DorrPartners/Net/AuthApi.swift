import Foundation

// providerApp `net/AuthApi.kt` and `net/GeneralApi.kt` — the provider's sign-in (docs/moving_tasks.md T1: phone + OTP, no
// password) under `/api/provider/v1/auth` (ProviderPhoneAuthController), and the shared public endpoints every DORR app reads
// (no sign-in).

struct OtpSentDto: Decodable {
    var maskedPhone: String?
    var isNewProvider: Bool?
    var resendCooldownSeconds: Int?
}

struct AuthResultDto: Decodable {
    var provider: ProviderDto?
    var token: String?
}

/// The person as the app sees them (`ProviderResource`).
struct ProviderDto: Decodable, Equatable {
    var id: Int?
    var name: String?
    var email: String?
    var phone: String?
    var countryId: Int?
    var country: ProviderCountryDto?
    /// `avatar` on the wire (Android: `@SerializedName("avatar") avatarUrl`).
    var avatar: String?
    var status: String?
    var statusLabel: String?

    var avatarUrl: String? { avatar }
}

struct ProviderCountryDto: Decodable, Equatable {
    var id: Int?
    var code: String?
    var dialCode: String?
}

enum AuthApi {
    private static let base = Api.provider + "auth/"

    /// Sends the code; the person is created on the first request (`is_new_provider`).
    static func requestOtp(dialCode: String, phone: String) async -> ApiResult<OtpSentDto> {
        await Api.call("POST", base + "otp", body: ["dial_code": dialCode, "phone": phone])
    }

    static func resendOtp(dialCode: String, phone: String) async -> ApiResult<OtpSentDto> {
        await Api.call("POST", base + "resend", body: ["dial_code": dialCode, "phone": phone])
    }

    /// Verifies the code and returns the Bearer token with the person.
    static func verifyOtp(dialCode: String, phone: String, code: String) async -> ApiResult<AuthResultDto> {
        await Api.call("POST", base + "verify", body: [
            "dial_code": dialCode, "phone": phone, "code": code,
            // This install, so the server can list the phone under the person's devices.
            "device_id": DeviceId.current, "device_name": Api.deviceName, "platform": "ios",
        ])
    }

    /// Me (ProviderAuthController::me) — asked once when the app opens signed in.
    static func me() async -> ApiResult<ProviderDto> { await Api.call("GET", Api.provider + "me") }

    static func logout(playerId: String?) async -> ApiResult<Ignored> {
        await Api.call("POST", base + "logout", body: ["player_id": playerId])
    }
}

struct PlatformAppearanceDto: Decodable {
    var lightTokens: [String: String]?
    var darkTokens: [String: String]?
}

struct FlagDto: Codable, Equatable, Hashable {
    var id: Int?
    var code: String?
}

struct CountryDto: Codable, Equatable, Hashable, Identifiable {
    var id: Int
    var code: String
    var name: String
    var dialCode: String
    var phoneLength: Int?
    var phoneStartsWith: String?
    var isDefault: Bool?
    var flag: FlagDto?

    /// "+966" whether or not the stored dial code carries the plus.
    var formattedDial: String {
        let digits = dialCode.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: "+", with: "")
        return digits.isEmpty ? "" : "+\(digits)"
    }
}

enum GeneralApi {
    /// The platform colours the admin set for the apps (light and dark), read by the splash.
    static func appearanceDefaults() async -> ApiResult<PlatformAppearanceDto> { await Api.call("GET", "general/v1/mobile-appearance-defaults") }

    /// The active countries — the sign-in's country picker.
    static func countries() async -> ApiResult<[CountryDto]> { await Api.call("GET", "general/v1/countries/dropdown") }

    /// The caller's country by IP, in the dropdown shape (the default country when it can't tell).
    static func detectCountry() async -> ApiResult<CountryDto> { await Api.call("GET", "general/v1/countries/detect") }

    /// The DORR legal pages (`privacy` / `term`), public: the sign-in page links them. The providers app reads the platform's
    /// general pages (`mobile/v1/legal-pages`) until providers get pages of their own.
    static func legalPage(_ type: String) async -> ApiResult<LegalPageDto> {
        await Api.call("GET", "mobile/v1/legal-pages", query: ["type": type])
    }
}

struct LegalPageDto: Decodable {
    var id: Int?
    var type: String?
    var content: String?
    var status: Bool?
}
