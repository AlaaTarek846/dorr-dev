import Foundation

// freelanceApp `net/AuthApi.kt` and `net/GeneralApi.kt` — the sign-in (FL-003: phone + OTP, no password) under
// `/api/freelance/v1/auth`, and the shared public endpoints every DORR app reads (no sign-in).

struct OtpSentDto: Decodable {
    var maskedPhone: String?
    var isNewFreelancer: Bool?
    var resendCooldownSeconds: Int?
}

struct AuthResultDto: Decodable {
    var freelancer: FreelancerDto?
    var token: String?
}

/// The freelancer as their own app sees them (`FreelancerResource`).
struct FreelancerDto: Codable, Equatable {
    var uuid: String?
    var name: String?
    var phone: String?
    var headline: String?
    var bio: String?
    var yearsOfExperience: Int?
    var contactLanguages: [String]?
    var city: String?
    var email: String?
    var emailVerified: Bool?
    var gender: String?
    var dateOfBirth: String?
    var countryId: Int?
    var locale: String?
    var residency: String?
    var status: String?
    var statusReason: String?
    var avatarUrl: String?
    var completeness: CompletenessDto?
    var availability: MyAvailabilityDto?
    var terms: TermsDto?
}

/// Whether new work can be given to the freelancer now (a pause or time off says no) — FL-106.
struct MyAvailabilityDto: Codable, Equatable {
    var canReceiveOffers: Bool?
    var acceptingRequests: Bool?
    var onTimeOff: Bool?
}

struct CompletenessDto: Codable, Equatable {
    var percent: Int?
    var missing: [String]?
}

struct TermsDto: Codable, Equatable {
    var accepted: Bool?
    var currentVersion: String?
}

enum AuthApi {
    private static let base = Api.freelance + "auth/"

    /// Sends the code; the freelancer is created on the first request (`is_new_freelancer`).
    static func requestOtp(dialCode: String, phone: String) async -> ApiResult<OtpSentDto> {
        await Api.call("POST", base + "otp", body: ["dial_code": dialCode, "phone": phone])
    }

    static func resendOtp(dialCode: String, phone: String) async -> ApiResult<OtpSentDto> {
        await Api.call("POST", base + "resend", body: ["dial_code": dialCode, "phone": phone])
    }

    /// Verifies the code and returns the Bearer token with the freelancer.
    static func verifyOtp(dialCode: String, phone: String, code: String) async -> ApiResult<AuthResultDto> {
        await Api.call("POST", base + "verify", body: [
            "dial_code": dialCode, "phone": phone, "code": code,
            // This install, so the server lists the phone under the freelancer's devices.
            "device_id": DeviceId.current, "device_name": Api.deviceName, "platform": "ios",
        ])
    }

    /// Me — asked once when the app opens signed in.
    static func me() async -> ApiResult<FreelancerDto> { await Api.call("GET", base + "me") }

    static func logout(playerId: String?) async -> ApiResult<Ignored> {
        await Api.call("POST", base + "logout", body: ["player_id": playerId])
    }
}

struct LanguageDto: Codable, Equatable, Hashable {
    var code: String?
    var name: String?
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

    /// The active languages (code + name) — the contact languages the freelancer picks from.
    static func languages() async -> ApiResult<[LanguageDto]> { await Api.call("GET", "general/v1/languages/dropdown") }
}
