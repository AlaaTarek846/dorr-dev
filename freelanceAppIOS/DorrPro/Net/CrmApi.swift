import Foundation

// freelanceApp `net/CrmApi.kt`.

/// My clients (FS-703): the freelancer's private CRM, under `/api/freelance/v1/crm`. What it opens is the plan's (`benefits.crm`: 0 none, 1 clients,
/// the star and notes, 2 + labels and follow-up reminders). A plan with none answers the list with 200 and `CrmOverviewDto.locked` (so the screen can
/// offer the upgrade instead of an error); a change the plan does not open is a 403 `freelance_crm_locked` (see `ApiError.crmLocked`). Every answer to
/// a change is the client page as `show` gives it, so the screen repaints from one object.
enum CrmApi {
    private static let base = Api.freelance + "crm/"

    /// `tab` is `all`, `active`, `past`, `leads` or `starred`; `search` matches the client's name.
    static func clients(tab: String? = nil, search: String? = nil, page: Int? = nil, perPage: Int? = nil) async -> ApiResult<CrmOverviewDto> {
        await Api.call("GET", base + "clients", query: ["tab": tab, "search": search, "page": page.map(String.init), "per_page": perPage.map(String.init)])
    }

    static func client(_ uuid: String) async -> ApiResult<CrmClientPageDto> {
        await Api.call("GET", base + "clients/\(uuid)")
    }

    /// The star (CRM 1) and the label (CRM 2). Built by `CrmBody`: a cleared label is a `null` the JSON must carry.
    static func update(_ uuid: String, _ body: JsonBody) async -> ApiResult<CrmClientPageDto> {
        await Api.call("PUT", base + "clients/\(uuid)", body: body)
    }

    static func addNote(_ uuid: String, _ body: CrmNoteRequest) async -> ApiResult<CrmClientPageDto> {
        await Api.call("POST", base + "clients/\(uuid)/notes", body: body.json)
    }

    static func editNote(_ uuid: String, _ note: String, _ body: CrmNoteRequest) async -> ApiResult<CrmClientPageDto> {
        await Api.call("PUT", base + "clients/\(uuid)/notes/\(note)", body: body.json)
    }

    static func deleteNote(_ uuid: String, _ note: String) async -> ApiResult<CrmClientPageDto> {
        await Api.call("DELETE", base + "clients/\(uuid)/notes/\(note)")
    }

    static func addReminder(_ uuid: String, _ body: CrmReminderRequest) async -> ApiResult<CrmClientPageDto> {
        await Api.call("POST", base + "clients/\(uuid)/reminders", body: body.json)
    }

    static func finishReminder(_ uuid: String, _ reminder: String, _ body: CrmReminderDoneRequest) async -> ApiResult<CrmClientPageDto> {
        await Api.call("PUT", base + "clients/\(uuid)/reminders/\(reminder)", body: body.json)
    }

    static func deleteReminder(_ uuid: String, _ reminder: String) async -> ApiResult<CrmClientPageDto> {
        await Api.call("DELETE", base + "clients/\(uuid)/reminders/\(reminder)")
    }

    /// Every follow-up still waiting, soonest first (the list's own `due` carries the same).
    static func reminders() async -> ApiResult<CrmRemindersDto> {
        await Api.call("GET", base + "reminders")
    }
}

struct CrmNoteRequest {
    let body: String

    var json: JsonBody { ["body": body] }
}

struct CrmReminderRequest {
    let title: String
    let dueAt: String

    var json: JsonBody { ["title": title, "due_at": dueAt] }
}

struct CrmReminderDoneRequest {
    let done: Bool

    var json: JsonBody { ["done": done] }
}

/// The body of `PUT crm/clients/{uuid}`: a present-and-null `label` clears it, an absent one leaves it.
enum CrmBody {
    static func star(_ starred: Bool) -> JsonBody { ["starred": starred] }

    /// `label` is `vip`, `regular`, `lead`, `paused`, or nil to clear it.
    static func label(_ label: String?) -> JsonBody { ["label": label] }
}

/// The frame every answer shares, as the list reads it. `locked` = my plan has no CRM (then there is no list, only `upgrade`).
struct CrmOverviewDto: Decodable {
    /// 0 = none (locked), 1 = clients, star and notes, 2 = + labels and follow-ups.
    var level: Int?
    var locked: Bool?
    /// False when plans are not sold in my country, so there is nothing to upgrade to.
    var subscriptionsEnabled: Bool?
    /// The lowest plan that opens more than mine; nil at the top or where plans are not sold.
    var upgrade: CrmUpgradeDto?
    /// The labels my plan allows (level 2 only).
    var labels: [String]?
    var limits: CrmLimitsDto?
    @CurrencyLabel var currency: String?
    var decimals: Int?
    var tab: String?
    var counts: CrmCountsDto?
    var clients: [CrmClientDto]?
    var meta: CrmMetaDto?
    /// The follow-ups still waiting, soonest first (level 2).
    var due: [CrmReminderDto]?
}

struct CrmUpgradeDto: Decodable {
    var slug: String?
    var name: String?
}

struct CrmLimitsDto: Decodable {
    var notesMax: Int?
    var remindersMax: Int?
}

struct CrmCountsDto: Decodable {
    var all: Int?
    var active: Int?
    var past: Int?
    var leads: Int?
    var starred: Int?
}

struct CrmMetaDto: Decodable {
    var total: Int?
    var page: Int?
    var perPage: Int?
    var lastPage: Int?
}

/// `GET crm/clients/{uuid}` and every change: the frame and the client.
struct CrmClientPageDto: Decodable {
    var level: Int?
    var locked: Bool?
    var subscriptionsEnabled: Bool?
    var upgrade: CrmUpgradeDto?
    var labels: [String]?
    var limits: CrmLimitsDto?
    @CurrencyLabel var currency: String?
    var decimals: Int?
    var client: CrmClientDto?
}

/// A client of mine. The list gives the card (the first fields); the page also fills `notes`, `reminders` (level 2), `history` (the newest first)
/// and `leads` (what is still waiting).
struct CrmClientDto: Decodable, Identifiable {
    var uuid: String?
    var name: String?
    /// `active` (a running project), `past` (only finished ones) or `lead` (an offer or an invitation, no work yet).
    var stage: String?
    /// `vip`, `regular`, `lead`, `paused` (level 2), or nil.
    var label: String?
    var starred: Bool?
    var projects: Int?
    var active: Int?
    var completed: Int?
    var earnedMinor: Int?
    /// What this client gave me, from the published reviews; nil before any.
    var rating: Double?
    var notesCount: Int?
    /// The soonest open follow-up.
    var nextDueAt: String?
    var remindersCount: Int?
    /// An offer of mine is still waiting on him.
    var waitingOffer: Bool?
    /// He invited me to a project and I have not answered.
    var invitedYou: Bool?
    var lastActivityAt: String?
    var notes: [CrmNoteDto]?
    var reminders: [CrmReminderDto]?
    var history: [CrmHistoryDto]?
    var leads: [CrmLeadDto]?

    var id: String { uuid ?? "" }
}

struct CrmNoteDto: Decodable, Identifiable {
    var uuid: String?
    var body: String?
    var createdAt: String?
    var updatedAt: String?

    var id: String { uuid ?? "" }
}

/// A follow-up. On the list's `due` it also names the client (`client`).
struct CrmReminderDto: Decodable, Identifiable {
    var uuid: String?
    var title: String?
    var dueAt: String?
    var doneAt: String?
    var overdue: Bool?
    var client: CrmClientRefDto?

    var id: String { uuid ?? "" }
}

struct CrmClientRefDto: Decodable {
    var uuid: String?
    var name: String?
}

struct CrmRemindersDto: Decodable {
    var reminders: [CrmReminderDto]?
}

/// A project I did with the client: `project` is its uuid (the agreement page opens by it); `service` = it came from a ready-made service.
struct CrmHistoryDto: Decodable, Identifiable {
    var project: String?
    var title: String?
    /// `active` or `completed`.
    var status: String?
    var priceMinor: Int?
    var earnedMinor: Int?
    var service: Bool?
    var startedAt: String?
    var completedAt: String?

    var id: String { (project ?? "") + (startedAt ?? "") + (status ?? "") }
}

/// Something still waiting: `kind` `offer` (mine, on his project) or `invitation` (his, to me); `status` `submitted`, `viewed`, `shortlisted` or `pending`.
struct CrmLeadDto: Decodable, Identifiable {
    var kind: String?
    /// The project's uuid.
    var project: String?
    var title: String?
    var status: String?
    var at: String?

    var id: String { (kind ?? "") + (project ?? "") + (at ?? "") }
}

/// The 403 `freelance_crm_locked` data: the level I have, the level the change needs and the plan that opens it.
struct CrmLockedDto {
    var level: Int?
    var needed: Int?
    var upgrade: CrmUpgradeDto?
}

extension ApiError {
    /// The upgrade detail of a `freelance_crm_locked` refusal, or nil when this error is something else.
    func crmLocked() -> CrmLockedDto? {
        guard errorCode == "freelance_crm_locked" else { return nil }
        guard let d = data as? [String: Any] else { return CrmLockedDto() }
        let upgrade = (d["upgrade"] as? [String: Any]).map { CrmUpgradeDto(slug: $0["slug"] as? String, name: $0["name"] as? String) }
        return CrmLockedDto(level: d["level"] as? Int, needed: d["needed"] as? Int, upgrade: upgrade)
    }
}
