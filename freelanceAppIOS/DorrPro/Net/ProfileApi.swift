import Foundation

// freelanceApp `net/ProfileApi.kt`, `net/AccountApi.kt`.

/// The freelancer's own profile (FL-103). Every answer is the updated freelancer.
enum ProfileApi {
    private static let base = Api.freelance

    /// The profile fields (`name` is always required); a blank text clears its field.
    static func update(_ body: JsonBody) async -> ApiResult<FreelancerDto> { await Api.call("PUT", base + "profile", body: body) }

    /// A code goes to that address.
    static func sendEmailCode(_ email: String) async -> ApiResult<Ignored> { await Api.call("POST", base + "profile/email/send-code", body: ["email": email]) }

    static func confirmEmail(_ code: String) async -> ApiResult<FreelancerDto> { await Api.call("POST", base + "profile/email/confirm", body: ["code": code]) }

    static func avatar(_ file: FilePart) async -> ApiResult<FreelancerDto> { await Api.upload(base + "profile/avatar", files: [file]) }

    static func acceptTerms(_ version: String) async -> ApiResult<FreelancerDto> { await Api.call("POST", base + "terms/accept", body: ["version": version]) }
}

struct SanctionsDto: Decodable {
    var items: [SanctionDto]?
}

struct SanctionDto: Decodable, Identifiable {
    var id: String { uuid }
    var uuid: String
    var type: String?
    var state: String?
    var reason: String?
    var blocks: [String]?
    var endsAt: String?
    var canAppeal: Bool?
    var appealUntil: String?
    var appeal: SanctionAppealDto?
}

struct SanctionAppealDto: Decodable {
    var status: String?
    var note: String?
    var decisionNote: String?
}

/// The freelancer's own account (FL-003): deleting it; FL-708 warnings and restrictions.
enum AccountApi {
    private static let base = Api.freelance

    /// Deletes the account (and its works and files); the caller must confirm.
    static func delete() async -> ApiResult<Ignored> { await Api.call("DELETE", base + "account", body: ["confirm": true]) }

    static func sanctions() async -> ApiResult<SanctionsDto> { await Api.call("GET", base + "sanctions") }

    static func appealSanction(_ uuid: String, note: String) async -> ApiResult<SanctionsDto> {
        await Api.call("POST", base + "sanctions/\(uuid)/appeal", body: ["note": note])
    }
}
