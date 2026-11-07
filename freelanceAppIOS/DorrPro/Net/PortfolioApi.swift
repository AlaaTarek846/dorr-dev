import Foundation

// freelanceApp `net/PortfolioApi.kt`.

/// The freelancer's portfolio (FL-105), under `/api/freelance/v1/profile/portfolio`.
enum PortfolioApi {
    private static let base = Api.freelance + "profile/portfolio"

    static func list() async -> ApiResult<[PortfolioItemDto]> { await Api.call("GET", base) }

    static func create(_ body: JsonBody) async -> ApiResult<PortfolioItemDto> { await Api.call("POST", base, body: body) }

    static func update(_ id: Int, _ body: JsonBody) async -> ApiResult<PortfolioItemDto> { await Api.call("PUT", base + "/\(id)", body: body) }

    static func delete(_ id: Int, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base + "/\(id)", offline: offline, generic: generic)
    }

    /// The new order: every work of mine once.
    static func order(_ body: PortfolioOrderRequest) async -> ApiResult<[PortfolioItemDto]> {
        await Api.call("PUT", base + "/order", body: ["ids": body.ids])
    }

    /// One image, as the part `image`.
    static func addImage(_ id: Int, _ image: FilePart) async -> ApiResult<PortfolioItemDto> {
        await Api.upload(base + "/\(id)/images", files: [image.asField("image")])
    }

    static func deleteImage(_ id: Int, _ media: Int) async -> ApiResult<PortfolioItemDto> { await Api.call("DELETE", base + "/\(id)/images/\(media)") }
}

/// FS-209c: a team's works (FS-202a), under `/api/freelance/v1/teams/{team}/portfolio` — the same fields as mine (without the agreement link).
/// Everybody in the team reads; the Owners write.
enum TeamPortfolioApi {
    private static func base(_ team: String) -> String { Api.freelance + "teams/\(team)/portfolio" }

    static func list(_ team: String) async -> ApiResult<[PortfolioItemDto]> { await Api.call("GET", base(team)) }

    static func create(_ team: String, _ body: JsonBody) async -> ApiResult<PortfolioItemDto> { await Api.call("POST", base(team), body: body) }

    static func update(_ team: String, _ id: Int, _ body: JsonBody) async -> ApiResult<PortfolioItemDto> {
        await Api.call("PUT", base(team) + "/\(id)", body: body)
    }

    static func delete(_ team: String, _ id: Int, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base(team) + "/\(id)", offline: offline, generic: generic)
    }

    static func order(_ team: String, _ body: PortfolioOrderRequest) async -> ApiResult<[PortfolioItemDto]> {
        await Api.call("PUT", base(team) + "/order", body: ["ids": body.ids])
    }

    /// One image, as the part `image`.
    static func addImage(_ team: String, _ id: Int, _ image: FilePart) async -> ApiResult<PortfolioItemDto> {
        await Api.upload(base(team) + "/\(id)/images", files: [image.asField("image")])
    }

    static func deleteImage(_ team: String, _ id: Int, _ media: Int) async -> ApiResult<PortfolioItemDto> {
        await Api.call("DELETE", base(team) + "/\(id)/images/\(media)")
    }
}

struct PortfolioOrderRequest {
    var ids: [Int]
}

struct PortfolioItemDto: Decodable, Equatable {
    var id: Int?
    var uuid: String?
    var title: String?
    var description: String?
    var completedOn: String?
    var specialty: PortfolioRefDto?
    var skills: [PortfolioRefDto]?
    var images: [PortfolioImageDto]?
    var externalUrl: String?
    var video: PortfolioVideoDto?
    /// private · clients_only · public
    var visibility: String?
    var rightsConfirmed: Bool?
    var hidden: Bool?
    var hiddenReason: String?
    /// FL-L13: the finished work it comes from (uuid of the agreement), and whether that client took their leave back.
    var agreement: String?
    var withdrawnByClient: Bool?
}

struct PortfolioRefDto: Decodable, Equatable {
    var id: Int?
    var name: String?
}

struct PortfolioImageDto: Decodable, Equatable {
    var id: Int?
    var name: String?
    var size: Int64?
    /// My own image is read signed in from this path (no public link).
    var path: String?
}

struct PortfolioVideoDto: Decodable, Equatable {
    var provider: String?
    var embedUrl: String?
}
