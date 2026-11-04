import Foundation

// freelanceApp `net/ProjectsApi.kt`.

/// Browsing the clients' projects (FL-206 / FL-207), under `/api/freelance/v1/projects`: the published public projects of the
/// freelancer's own country, one project's page, and the ones saved. The client and the files are never part of the answer.
enum ProjectsApi {
    private static let base = Api.freelance

    /// `scope`: `mine` = my specialties (and their sub-specialties), `all`; left out, the server picks mine when I have any.
    static func list(page: Int = 1, perPage: Int = 15, q: String? = nil, specialtyId: Int? = nil, budgetMinMinor: Int? = nil,
                     budgetMaxMinor: Int? = nil, durationMaxDays: Int? = nil, favorites: Int? = nil, scope: String? = nil) async -> ApiResult<[ProjectDto]> {
        await Api.call("GET", base + "projects", query: [
            "page": String(page), "per_page": String(perPage), "q": q, "specialty_id": specialtyId.map(String.init),
            "budget_min_minor": budgetMinMinor.map(String.init), "budget_max_minor": budgetMaxMinor.map(String.init),
            "duration_max_days": durationMaxDays.map(String.init), "favorites": favorites.map(String.init), "scope": scope,
        ])
    }

    static func show(_ uuid: String) async -> ApiResult<ProjectDto> { await Api.call("GET", base + "projects/\(uuid)") }

    static func favorite(_ uuid: String) async -> ApiResult<ProjectDto> { await Api.call("POST", base + "projects/\(uuid)/favorite") }

    static func unfavorite(_ uuid: String) async -> ApiResult<ProjectDto> { await Api.call("DELETE", base + "projects/\(uuid)/favorite") }
}

struct ProjectDto: Decodable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var title: String?
    var specialty: NamedDto?
    var skills: [NamedDto]?
    var budget: ProjectBudgetDto?
    var durationDays: Int?
    var publishedAt: String?
    /// published, or awarded once the client chose an offer (FL-309). Default `published`.
    var status: String?
    var isFavorite: Bool?
    /// The list shows a short text …
    var summary: String?
    /// … the page shows all of it.
    var description: String?
    /// FL-307: the client's questions, answered with the offer. Only the project page has them.
    var questions: [String]?
    var attachmentsCount: Int?
    var offers: ProjectOffersDto?
    /// I already sent an offer on it (FL-301).
    var offered: Bool?
    /// FL-205: my invitation to this (private) project, if any.
    var invitation: InvitationRefDto?
}

/// Whether offers can still be made on this project (FL-204). `accepting` defaults to true.
struct ProjectOffersDto: Decodable {
    var accepting: Bool?
    var deadlineAt: String?
    var closeReason: String?
}

struct NamedDto: Decodable, Identifiable {
    var id: Int?
    var name: String?
}

struct ProjectBudgetDto: Decodable {
    /// `fixed` (the default) or `range`.
    var type: String?
    var minMinor: Int?
    var maxMinor: Int?
    @CurrencyLabel var currency: String?
}
