import Foundation

// freelanceApp `net/VerificationApi.kt`.

/// Verifying the account by hand (FL-107 / FL-108 / FL-110), under `/api/freelance/v1/verification`.
enum VerificationApi {
    private static let base = Api.freelance + "verification"

    static func overview() async -> ApiResult<VerificationOverviewDto> { await Api.call("GET", base) }

    /// The text fields in `fields`, the files as `front` and `back`; a new upload of a type replaces its draft.
    static func upload(_ fields: [String: String], front: FilePart, back: FilePart?) async -> ApiResult<VerificationDocDto> {
        await Api.upload(base + "/documents", fields: fields, files: [front.asField("front")] + (back.map { [$0.asField("back")] } ?? []))
    }

    /// A draft only.
    static func deleteDocument(_ id: Int, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base + "/documents/\(id)", offline: offline, generic: generic)
    }

    /// Sends the uploaded documents for review.
    static func submit() async -> ApiResult<VerificationOverviewDto> { await Api.call("POST", base + "/submit") }

    /// My own file (front or back): private, signed in.
    static func file(_ id: Int, _ side: String) async -> Data? { await Api.bytes(base + "/documents/\(id)/\(side)") }
}

struct VerificationOverviewDto: Decodable, Equatable {
    var status: String?
    var canApply: Bool?
    var step: String?
    var needsResidency: Bool?
    var required: [RequiredDocDto]?
    var others: [VerificationDocDto]?
    var canSubmit: Bool?
    var submitBlockers: [String]?
    var lastRequest: LastRequestDto?
}

struct RequiredDocDto: Decodable, Equatable {
    var type: DocTypeDto?
    var document: VerificationDocDto?
    var satisfied: Bool?
}

struct DocTypeDto: Decodable, Equatable {
    var id: Int?
    var code: String?
    var name: String?
    var instructions: String?
    var hasNumber: Bool?
    var hasExpiry: Bool?
    var needsBackSide: Bool?
}

struct VerificationDocDto: Decodable, Equatable {
    var id: Int?
    var type: DocTypeRefDto?
    var number: String?
    var expiresOn: String?
    var isExpired: Bool?
    /// draft · pending · approved · rejected · expired · replaced
    var status: String?
    var rejectionReason: String?
    var files: [String: DocFileDto?]?

    /// Whether the side (`front` / `back`) has a file.
    func hasFile(_ side: String) -> Bool { (files?[side] ?? nil) != nil }
}

struct DocTypeRefDto: Decodable, Equatable {
    var id: Int?
    var code: String?
    var name: String?
}

struct DocFileDto: Decodable, Equatable {
    var name: String?
    var mime: String?
    var size: Int64?
}

struct LastRequestDto: Decodable, Equatable {
    var uuid: String?
    /// submitted · approved · rejected · changes_requested (as the server names them)
    var status: String?
    var reason: String?
    var submittedAt: String?
    var decidedAt: String?
}
