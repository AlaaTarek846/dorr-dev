import Foundation

// freelanceApp `net/AgreementsApi.kt`.

/// A request body as Gson writes it: a field left nil is not sent at all.
func agreementBody(_ fields: [String: Any?]) -> JsonBody { fields.filter { $0.value != nil } }

/// The agreement on my chosen offer (FL-401), under `/api/freelance/v1/projects/{uuid}/agreement`.
enum AgreementsApi {
    private static let base = Api.freelance

    static func show(_ uuid: String) async -> ApiResult<AgreementDto> { await Api.call("GET", base + "projects/\(uuid)/agreement") }

    /// My work: the projects I was chosen for, `group` = active, completed or closed.
    static func myProjects(page: Int = 1, perPage: Int = 15, group: String? = nil) async -> ApiResult<[MyProjectDto]> {
        await Api.call("GET", base + "my-projects", query: ["page": String(page), "per_page": String(perPage), "group": group])
    }

    /// FL-605: progress reports - the client's asking and what I sent.
    static func reports(_ uuid: String) async -> ApiResult<ReportsDto> { await Api.call("GET", base + "projects/\(uuid)/reports") }

    static func sendReport(_ uuid: String, _ body: ReportRequest) async -> ApiResult<ReportsDto> {
        await Api.call("POST", base + "projects/\(uuid)/reports", body: body.json)
    }

    static func approve(_ uuid: String) async -> ApiResult<AgreementDto> { await Api.call("POST", base + "projects/\(uuid)/agreement/approve") }

    /// FL-403: action = start · deliver {note}.
    static func milestone(_ uuid: String, _ milestone: String, _ action: String, _ body: MilestoneActionRequest) async -> ApiResult<AgreementDto> {
        await Api.call("POST", base + "projects/\(uuid)/milestones/\(milestone)/\(action)", body: body.json)
    }

    /// FL-601: a delivery is a new version of the part - `note` and up to 10 `files[]`.
    static func deliver(_ uuid: String, _ milestone: String, fields: [String: String], files: [FilePart]) async -> ApiResult<AgreementDto> {
        await Api.upload(base + "projects/\(uuid)/milestones/\(milestone)/deliver", fields: fields, files: files)
    }

    /// The versions delivered for a part, newest first.
    static func deliveries(_ uuid: String, _ milestone: String) async -> ApiResult<[DeliveryDto]> {
        await Api.call("GET", base + "projects/\(uuid)/milestones/\(milestone)/deliveries")
    }

    /// A delivered file: private, only the two people of the agreement, signed in. `path` is the full API path.
    static func file(_ path: String) async -> Data? { await Api.bytes(path) }

    /// FL-701: rate the client after the project.
    static func review(_ uuid: String, _ body: ReviewRequest) async -> ApiResult<ReviewDto> {
        await Api.call("POST", base + "projects/\(uuid)/review", body: body.json)
    }

    /// FL-706: object to the client's rating of me (once). FL-702: the satisfaction survey after the project.
    static func objectToReview(_ uuid: String, _ body: ObjectionRequest) async -> ApiResult<ReviewDto> {
        await Api.call("POST", base + "projects/\(uuid)/review/objection", body: body.json)
    }

    static func survey(_ uuid: String) async -> ApiResult<SurveyDto> { await Api.call("GET", base + "projects/\(uuid)/survey") }

    static func answerSurvey(_ uuid: String, _ body: SurveyRequest) async -> ApiResult<SurveyDto> {
        await Api.call("POST", base + "projects/\(uuid)/survey", body: body.json)
    }

    /// FL-511: my receipts of the project money, newest first; one with its text to share.
    static func receipts(project: String? = nil, perPage: Int = 50) async -> ApiResult<[ReceiptDto]> {
        await Api.call("GET", base + "receipts", query: ["project": project, "per_page": String(perPage)])
    }

    static func receipt(_ uuid: String) async -> ApiResult<ReceiptDto> { await Api.call("GET", base + "receipts/\(uuid)") }

    /// FL-406: the project's record of approvals.
    static func trail(_ uuid: String) async -> ApiResult<[TrailStepDto]> { await Api.call("GET", base + "projects/\(uuid)/agreement/trail") }

    /// FL-703: my numbers and the latest ratings clients gave me.
    static func reviews() async -> ApiResult<MyReviewsDto> { await Api.call("GET", base + "reviews") }

    /// FL-404 / FL-405: ask for a change to the agreement, or answer one (accept · reject · withdraw). FS-101: always multipart -
    /// the fields of `ChangeRequest.fields` and up to 3 `files[]`.
    static func requestChange(_ uuid: String, fields: [String: String], files: [FilePart]) async -> ApiResult<[ChangeDto]> {
        await Api.upload(base + "projects/\(uuid)/agreement/changes", fields: fields, files: files)
    }

    static func answerChange(_ uuid: String, _ change: String, _ action: String) async -> ApiResult<[ChangeDto]> {
        await Api.call("POST", base + "projects/\(uuid)/agreement/changes/\(change)/\(action)")
    }

    /// FL-509: my earnings from projects.
    static func earnings() async -> ApiResult<EarningsDto> { await Api.call("GET", base + "earnings") }

    /// FL-704: a dispute on a part.
    static func openDispute(_ uuid: String, _ milestone: String, _ body: DisputeOpenRequest) async -> ApiResult<DisputeDto> {
        await Api.call("POST", base + "projects/\(uuid)/milestones/\(milestone)/dispute", body: body.json)
    }

    static func dispute(_ uuid: String, _ dispute: String) async -> ApiResult<DisputeDto> {
        await Api.call("GET", base + "projects/\(uuid)/disputes/\(dispute)")
    }

    static func disputeEvidence(_ uuid: String, _ dispute: String, _ body: TextRequest) async -> ApiResult<DisputeDto> {
        await Api.call("POST", base + "projects/\(uuid)/disputes/\(dispute)/evidence", body: body.json)
    }

    static func appealDispute(_ uuid: String, _ dispute: String, _ body: TextRequest) async -> ApiResult<DisputeDto> {
        await Api.call("POST", base + "projects/\(uuid)/disputes/\(dispute)/appeal", body: body.json)
    }

    static func decline(_ uuid: String, _ body: DeclineRequest) async -> ApiResult<AgreementDto> {
        await Api.call("POST", base + "projects/\(uuid)/agreement/decline", body: body.json)
    }
}

struct AgreementDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
    var scope: String?
    var includes: String?
    var excludes: String?
    @CurrencyLabel var currency: String?
    var durationDays: Int?
    var money: AgreementMoneyDto?
    var terms: AgreementTermsDto?
    var expiresAt: String?
    var milestones: [MilestoneDto]?
    /// FL-404 / FL-405: the changes asked, newest first.
    var changes: [ChangeDto]?
    /// FL-701: rating each other once it is completed.
    var review: ReviewDto?
    var can: AgreementCanDto?
    /// FL-605: how often the client asked for a progress report, and whether the next one is late.
    var reports: ReportSummaryDto?
    /// FL-L13: the ownership terms the agreement keeps (nil: none, or the feature is off).
    var rights: AgreementRightsDto?
    /// FS-106: how the running project is doing (nil: it is not running). Informational only.
    var health: ProjectHealthDto?
}

/// FS-106: `state` = healthy · at_risk · delayed · blocked; `nextStep` is the first thing I can do, else what I wait for.
struct ProjectHealthDto: Decodable, Equatable {
    var state: String?
    var reasons: [HealthReasonDto]?
    var nextStep: HealthNextStepDto?
}

/// `code` = dispute_open · part_unfunded · part_overdue · part_due_soon · part_not_started · review_waiting · report_late · change_waiting; `mine`: I am the one to act.
struct HealthReasonDto: Decodable, Equatable {
    var code: String?
    var level: String?
    var mine: Bool?
    var data: HealthReasonDataDto?
}

struct HealthReasonDataDto: Decodable, Equatable {
    var part: String?
    var days: Int?
    var dueAt: String?
}

struct HealthNextStepDto: Decodable, Equatable {
    var code: String?
    var mine: Bool?
}

struct ReportSummaryDto: Decodable, Equatable {
    var everyDays: Int?
    var nextDueAt: String?
    var lastAt: String?
    var late: Bool?
}

struct ReportDto: Decodable, Equatable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var note: String?
    var percent: Int?
    var createdAt: String?
}

struct ReportsDto: Decodable, Equatable {
    var summary: ReportSummaryDto?
    var reports: [ReportDto]?
}

struct ReportRequest {
    let note: String
    var percent: Int?
    var json: JsonBody { agreementBody(["note": note, "percent": percent]) }
}

/// One part of the agreement (FL-402) and what it leaves me.
struct MilestoneDto: Decodable, Equatable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var position: Int?
    var title: String?
    var amountMinor: Int?
    var netMinor: Int?
    var status: String?
    var revisionReason: String?
    /// FL-503: what reached my wallet.
    var paidMinor: Int?
    var autoApproveAt: String?
    var cancelRequestedBy: String?
    var revisionsLeft: Int?
    var dispute: DisputeRefDto?
    /// FL-601 / FL-604: how many versions were delivered, and what the client checks before approving.
    var deliveriesCount: Int?
    var checklist: [ChecklistItemDto]?
    var can: MilestoneCanDto?
}

struct ChecklistItemDto: Decodable, Equatable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var text: String?
    var checked: Bool?
}

struct DeliveryDto: Decodable, Equatable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var number: Int?
    var note: String?
    var deliveredAt: String?
    var revisionReason: String?
    var files: [DeliveryFileDto]?
    /// FL-L13: my word and the outside material I listed.
    var rights: DeliveryRightsDto?
}

struct DeliveryFileDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var name: String?
    var mime: String?
    var size: Int?
    var isImage: Bool?
    /// Under freelance/v1/.
    var path: String?
}

struct MilestoneCanDto: Decodable, Equatable {
    var start: Bool?
    var deliver: Bool?
    var withdraw: Bool?
    var cancelAccept: Bool?
    var cancelReject: Bool?
    var dispute: Bool?
}

struct MilestoneActionRequest {
    var note: String?
    var reasonCode: String?
    var json: JsonBody { agreementBody(["note": note, "reason_code": reasonCode]) }
}

struct AgreementMoneyDto: Decodable, Equatable {
    var priceMinor: Int?
    var commissionMinor: Int?
    var netMinor: Int?
}

struct AgreementTermsDto: Decodable, Equatable {
    var reviewDays: Int?
    var freeRevisions: Int?
}

struct AgreementCanDto: Decodable, Equatable {
    var approve: Bool?
    var decline: Bool?
    var report: Bool?
}

struct DeclineRequest {
    var reason: String?
    var json: JsonBody { agreementBody(["reason": reason]) }
}

/// What my offer shows of the agreement.
struct OfferAgreementDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
}

struct DisputeRefDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
}

struct DisputeOpenRequest {
    let reason: String
    let details: String
    var json: JsonBody { ["reason": reason, "details": details] }
}

/// `body` for evidence, `reason` for an appeal.
struct TextRequest {
    var body: String?
    var reason: String?
    var json: JsonBody { agreementBody(["body": body, "reason": reason]) }
}

struct DisputeDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
    var reasonLabel: String?
    var details: String?
    var mine: Bool?
    var milestone: DisputePartDto?
    @CurrencyLabel var currency: String?
    var decision: DisputeDecisionDto?
    var evidence: [DisputeEvidenceDto]?
    var can: DisputeCanDto?
}

struct DisputePartDto: Decodable, Equatable {
    var title: String?
    var amountMinor: Int?
}

struct DisputeDecisionDto: Decodable, Equatable {
    var type: String?
    var freelancerBp: Int?
    var note: String?
    var executesAt: String?
}

struct DisputeEvidenceDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var mine: Bool?
    var body: String?
    var at: String?
}

struct DisputeCanDto: Decodable, Equatable {
    var evidence: Bool?
    var appeal: Bool?
}

struct EarningsDto: Decodable, Equatable {
    @CurrencyLabel var currency: String?
    var availableMinor: Int?
    var pendingMinor: Int?
    var nextReleaseAt: String?
    var expectedMinor: Int?
    var earnedMinor: Int?
    var recent: [EarningDto]?
}

struct EarningDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var kind: String?
    var amountMinor: Int?
    @CurrencyLabel var currency: String?
    var status: String?
    var project: EarningProjectDto?
    var milestone: String?
    var releaseAt: String?
    var releasedAt: String?
}

struct EarningProjectDto: Decodable, Equatable {
    var uuid: String?
    var title: String?
}

struct ChangeDto: Decodable, Equatable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var type: String?
    var status: String?
    var mine: Bool?
    var milestone: DisputePartDto?
    var payload: JSONAny?
    var reason: String?
    var can: ChangeCanDto?
    /// FS-101: the deadline to answer, and once accepted the addendum number and the agreement version it reached.
    var respondBy: String?
    var at: String?
    var addendumNo: Int?
    var appliedVersion: Int?
    /// `{price_minor:[before,after], duration_days:[…], milestones:[…]}` - only what moved; `[]` or nil when nothing did. Read with `effectOf`.
    var effect: JSONAny?
    /// FS-101: `path` is under `freelance/v1/projects/{uuid}/`.
    var files: [DeliveryFileDto]?

    /// FS-101: one moved number of an accepted change as (before, after), or nil when it did not move (the decoder may have
    /// turned `price_minor` into `priceMinor`).
    func effectOf(_ key: String, _ camel: String? = nil) -> (Int, Int)? {
        guard case .array(let pair)? = effect?[key] ?? camel.flatMap({ effect?[$0] }), pair.count >= 2,
              let before = pair[0].int, let after = pair[1].int else { return nil }
        return (before, after)
    }
}

struct ChangeCanDto: Decodable, Equatable {
    var accept: Bool?
    var reject: Bool?
    var withdraw: Bool?
}

struct ChangeRequest {
    let type: String
    var milestone: String?
    var days: Int?
    var amountMinor: Int?
    var title: String?
    var scope: String?
    var includes: String?
    var excludes: String?
    var reason: String?
    /// The multipart text fields (a nil field is not sent, as the JSON body did).
    var fields: [String: String] {
        let all: [String: String?] = [
            "type": type, "milestone": milestone, "days": days.map { String($0) }, "amount_minor": amountMinor.map { String($0) }, "title": title,
            "scope": scope, "includes": includes, "excludes": excludes, "reason": reason,
        ]
        return all.compactMapValues { $0 }
    }
}

struct ReviewDto: Decodable, Equatable {
    var criteria: [String]?
    var canRate: Bool?
    var rateUntil: String?
    var mine: RatingDto?
    var theirs: RatingDto?
    var theirsWaiting: Bool?
    /// FL-706: I may object once to their rating of me; what became of it.
    var canObject: Bool?
    var objectUntil: String?
    var objection: ObjectionDto?
    var objectionReasons: [String]?
}

struct RatingDto: Decodable, Equatable {
    var stars: Double?
    var comment: String?
    var scores: [String: Int]?
    var commentHidden: Bool?
    var removed: Bool?
}

struct ObjectionDto: Decodable, Equatable {
    var reason: String?
    var note: String?
    var status: String?
    var decisionNote: String?
}

struct ObjectionRequest {
    let reason: String
    let note: String
    var json: JsonBody { ["reason": reason, "note": note] }
}

/// FL-702
struct SurveyDto: Decodable, Equatable {
    var canAnswer: Bool?
    var answerUntil: String?
    var reasons: [String]?
    var notSatisfiedMax: Int?
    var mine: SurveyMineDto?
}

struct SurveyMineDto: Decodable, Equatable {
    var score: Int?
    var reasons: [String]?
    var comment: String?
}

struct SurveyRequest {
    let score: Int
    let reasons: [String]
    var comment: String?
    var json: JsonBody { agreementBody(["score": score, "reasons": reasons, "comment": comment]) }
}

struct ReviewRequest {
    let scores: [String: Int]
    var comment: String?
    var json: JsonBody { agreementBody(["scores": scores, "comment": comment]) }
}

struct MyReviewsDto: Decodable, Equatable {
    var rating: Double?
    var reviewsCount: Int?
    var completedProjects: Int?
    var completionRate: Int?
    var onTimeRate: Int?
    var latest: [ReviewLineDto]?
}

/// `kind` = project · consultation; `project` is its title either way.
struct ReviewLineDto: Decodable, Equatable {
    var stars: Double?
    var comment: String?
    var project: String?
    var at: String?
    var kind: String?
}

/// FL-511: a receipt of one money movement of a part. `text`/`reason_text` come with the single receipt.
struct ReceiptDto: Decodable, Equatable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var number: String?
    var kind: String?
    var title: String?
    var reasonText: String?
    var project: RefDto?
    var milestone: RefDto?
    @CurrencyLabel var currency: String?
    var amountMinor: Int?
    var feeMinor: Int?
    var totalMinor: Int?
    var issuedAt: String?
    var text: String?
}

struct RefDto: Decodable, Equatable {
    var uuid: String?
    var title: String?
}

/// FL-406: one step of the record of approvals.
struct TrailStepDto: Decodable, Equatable, Identifiable {
    var id: Int?
    var action: String?
    var label: String?
    var actor: String?
    var mine: Bool?
    var at: String?
}

struct MyProjectRef: Decodable, Equatable {
    var uuid: String?
    var title: String?
}

struct MyProjectDto: Decodable, Equatable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var status: String?
    var project: MyProjectRef?
    var priceMinor: Int?
    @CurrencyLabel var currency: String?
    var durationDays: Int?
    var milestonesCount: Int?
    var milestonesDone: Int?
    var hasDispute: Bool?
}
