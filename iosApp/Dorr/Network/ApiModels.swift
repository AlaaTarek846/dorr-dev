import Foundation

// Mirrors androidApp `network/ApiModels.kt` — same field names, CodingKeys = @SerializedName.

struct ApiEnvelope<T: Decodable>: Decodable {
    let success: Bool
    let status: String?
    let code: Int?
    let message: String?
    let data: T?
    /// Present only on paginated lists.
    let pagination: PaginationDto?
}

/// For responses whose `data` the app does not read (`ApiEnvelope<Any?>` on Android).
struct Ignored: Decodable {
    init(from decoder: Decoder) throws {}
}

struct PaginationDto: Codable {
    let currentPage: Int
    let hasMorePages: Bool
    /// How many there are in all (some lists say).
    let total: Int?

    enum CodingKeys: String, CodingKey {
        case currentPage = "current_page"
        case hasMorePages = "has_more_pages"
        case total
    }

    /// Read through either decoder (the snake-case one camel-cases the keys first).
    private enum CamelKeys: String, CodingKey { case currentPage, hasMorePages }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        let camel = try decoder.container(keyedBy: CamelKeys.self)
        currentPage = (try? c.decode(Int.self, forKey: .currentPage)) ?? (try? camel.decode(Int.self, forKey: .currentPage)) ?? 1
        hasMorePages = (try? c.decode(Bool.self, forKey: .hasMorePages)) ?? (try? camel.decode(Bool.self, forKey: .hasMorePages)) ?? false
        total = try? c.decode(Int.self, forKey: .total)
    }
}

struct CountryDto: Codable, Identifiable, Hashable {
    let id: Int
    let code: String
    let name: String
    let dialCode: String
    let phoneLength: Int?
    let phoneStartsWith: String?
    let isDefault: Bool
    let flag: FlagDto?

    enum CodingKeys: String, CodingKey {
        case id, code, name, flag
        case dialCode = "dial_code"
        case phoneLength = "phone_length"
        case phoneStartsWith = "phone_starts_with"
        case isDefault = "is_default"
    }
}

struct FlagDto: Codable, Hashable {
    let id: Int
    let code: String
}

struct BrandingDto: Codable {
    let appName: String?
    let logo: String?
    let logoDark: String?

    enum CodingKeys: String, CodingKey {
        case logo
        case appName = "app_name"
        case logoDark = "logo_dark"
    }
}

struct OtpRequest: Codable {
    let dialCode: String
    let phone: String

    enum CodingKeys: String, CodingKey {
        case phone
        case dialCode = "dial_code"
    }
}

struct OtpSentDto: Codable {
    let maskedPhone: String?
    let isNewUser: Bool?
    /// "active" (normal login), "deleted" (soft-deleted, offer restore), "restore" (restore OTP sent).
    let accountState: String?
    let resendCooldownSeconds: Int?

    enum CodingKeys: String, CodingKey {
        case maskedPhone = "masked_phone"
        case isNewUser = "is_new_user"
        case accountState = "account_state"
        case resendCooldownSeconds = "resend_cooldown_seconds"
    }
}

struct VerifyOtpRequest: Codable {
    let dialCode: String
    let phone: String
    let code: String

    enum CodingKeys: String, CodingKey {
        case phone, code
        case dialCode = "dial_code"
    }
}

struct AuthResultDto: Codable {
    let user: UserDto?
    let token: String?
    let tokenType: String?
    /// True when this verify restored a previously deleted account (deleted_at → null).
    let isRestored: Bool?

    enum CodingKeys: String, CodingKey {
        case user, token
        case tokenType = "token_type"
        case isRestored = "is_restored"
    }
}

struct UserDto: Codable, Hashable {
    let id: Int
    let name: String?
    let email: String?
    let phone: String?
    let phoneVerifiedAt: String?
    /// `male` / `female`, from Modules\User UserResource. Null when never set.
    let gender: String?
    /// Absolute media URL of the avatar, null when none.
    let avatar: String?
    let emailVerifiedAt: String?
    /// Bumps on every profile write; used to bust the image cache for the avatar.
    let updatedAt: String?
    /// Country data from auth/me — the primary source for country code, flag, and phone validation.
    let country: UserCountryDto?

    enum CodingKeys: String, CodingKey {
        case id, name, email, phone, gender, avatar, country
        case phoneVerifiedAt = "phone_verified_at"
        case emailVerifiedAt = "email_verified_at"
        case updatedAt = "updated_at"
    }
}

struct UserCountryDto: Codable, Hashable {
    let code: String?
    let dialCode: String?
    let phoneStartsWith: String?
    let phoneLength: Int?
    let flag: UserFlagDto?

    enum CodingKeys: String, CodingKey {
        case code, flag
        case dialCode = "dial_code"
        case phoneStartsWith = "phone_starts_with"
        case phoneLength = "phone_length"
    }
}

struct UserFlagDto: Codable, Hashable {
    let code: String?
}

struct ServiceDto: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
    let moduleName: String?
    let image: String?
    let description: String?
    let sortOrder: Int?
    let requiresProvider: Bool?
    let hasChildren: Bool?
    var children: [ServiceChildDto]?

    enum CodingKeys: String, CodingKey {
        case id, name, image, description, children
        case moduleName = "module_name"
        case sortOrder = "sort_order"
        case requiresProvider = "requires_provider"
        case hasChildren = "has_children"
    }
}

struct ServiceChildDto: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
    let moduleName: String?
    let image: String?
    let description: String?
    let sortOrder: Int?

    enum CodingKeys: String, CodingKey {
        case id, name, image, description
        case moduleName = "module_name"
        case sortOrder = "sort_order"
    }
}

struct LogoutRequest: Codable {
    var playerId: String?

    enum CodingKeys: String, CodingKey {
        case playerId = "player_id"
    }
}
