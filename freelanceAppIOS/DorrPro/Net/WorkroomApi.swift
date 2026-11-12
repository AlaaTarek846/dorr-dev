import Foundation

// freelanceApp `net/WorkroomApi.kt`.

/// FS-150e: a project's Workroom as the freelancer sees it — tasks, files (with versions and notes), the decisions log and the timeline.
/// Everything lives under `projects/{uuid}/workroom/…` and works while the agreement is `active` (write) or `completed` (read only). The
/// screens read once when they open, once on a live event (`freelance.workroom` on the project's channel) and once when the connection returns.
///
/// A list answers with its rows, a `summary` (the numbers for the chips), what I `can` do and `read_only`; the pages are 30 rows (a full page
/// means another one follows, as everywhere in the app). A field I want cleared on an update (a part, a day) goes as an empty string.
enum WorkroomApi {
    private static func base(_ project: String) -> String { Api.freelance + "projects/\(project)/workroom/" }

    private static func text(_ number: Int?) -> String? { number.map { String($0) } }

    // MARK: - tasks

    static func tasks(_ project: String, status: String? = nil, milestone: String? = nil, assignee: String? = nil, mine: Int? = nil,
                      overdue: Int? = nil, perPage: Int? = nil, page: Int? = nil) async -> ApiResult<WorkTasksDto> {
        await Api.call("GET", base(project) + "tasks", query: [
            "status": status, "milestone": milestone, "assignee": assignee, "mine": text(mine), "overdue": text(overdue),
            "per_page": text(perPage), "page": text(page),
        ])
    }

    static func createTask(_ project: String, _ body: JsonBody) async -> ApiResult<WorkTaskAnswerDto> {
        await Api.call("POST", base(project) + "tasks", body: body)
    }

    static func updateTask(_ project: String, _ task: String, _ body: JsonBody) async -> ApiResult<WorkTaskAnswerDto> {
        await Api.call("PUT", base(project) + "tasks/\(task)", body: body)
    }

    static func setTaskStatus(_ project: String, _ task: String, _ body: WorkStatusRequest) async -> ApiResult<WorkTaskAnswerDto> {
        await Api.call("POST", base(project) + "tasks/\(task)/status", body: body.json)
    }

    static func deleteTask(_ project: String, _ task: String) async -> ApiResult<WorkSummaryAnswerDto> {
        await Api.call("DELETE", base(project) + "tasks/\(task)")
    }

    // MARK: - files

    static func files(_ project: String, milestone: String? = nil, mine: Int? = nil, search: String? = nil, perPage: Int? = nil,
                      page: Int? = nil) async -> ApiResult<WorkFilesDto> {
        await Api.call("GET", base(project) + "files", query: [
            "milestone": milestone, "mine": text(mine), "search": search, "per_page": text(perPage), "page": text(page),
        ])
    }

    /// The file with all its versions (newest first).
    static func file(_ project: String, _ file: String) async -> ApiResult<WorkFileAnswerDto> {
        await Api.call("GET", base(project) + "files/\(file)")
    }

    /// Multipart: `file` and the text fields (`title`, `description`, `milestone`, `note`, `scope`).
    static func uploadFile(_ project: String, fields: [String: String], _ file: FilePart) async -> ApiResult<WorkFileAnswerDto> {
        await Api.upload(base(project) + "files", fields: fields, files: [file.asField("file")])
    }

    static func updateFile(_ project: String, _ file: String, _ body: JsonBody) async -> ApiResult<WorkFileAnswerDto> {
        await Api.call("PUT", base(project) + "files/\(file)", body: body)
    }

    /// A new version of a file (the owner only): `file` and an optional `note`.
    static func addVersion(_ project: String, _ file: String, fields: [String: String], _ part: FilePart) async -> ApiResult<WorkFileAnswerDto> {
        await Api.upload(base(project) + "files/\(file)/versions", fields: fields, files: [part.asField("file")])
    }

    static func deleteFile(_ project: String, _ file: String) async -> ApiResult<WorkSummaryAnswerDto> {
        await Api.call("DELETE", base(project) + "files/\(file)")
    }

    /// One version's bytes (private, signed in): shown in the system viewer once kept in the cache. Nil when it could not be read.
    static func download(_ project: String, _ file: String, _ number: Int) async -> Data? {
        await Api.bytes(base(project) + "files/\(file)/versions/\(number)/download")
    }

    // MARK: - notes on a file or a delivery

    static func comments(_ project: String, file: String? = nil, delivery: String? = nil, version: Int? = nil, perPage: Int? = nil,
                         page: Int? = nil) async -> ApiResult<WorkCommentsDto> {
        await Api.call("GET", base(project) + "comments", query: [
            "file": file, "delivery": delivery, "version": text(version), "per_page": text(perPage), "page": text(page),
        ])
    }

    static func addComment(_ project: String, _ body: WorkCommentRequest) async -> ApiResult<WorkCommentAnswerDto> {
        await Api.call("POST", base(project) + "comments", body: body.json)
    }

    static func editComment(_ project: String, _ comment: String, _ body: WorkCommentBodyRequest) async -> ApiResult<WorkCommentAnswerDto> {
        await Api.call("PUT", base(project) + "comments/\(comment)", body: body.json)
    }

    /// The text to show when it did not go through, or nil.
    static func deleteComment(_ project: String, _ comment: String, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base(project) + "comments/\(comment)", offline: offline, generic: generic)
    }

    // MARK: - decisions

    static func decisions(_ project: String, status: String? = nil, milestone: String? = nil, mine: Int? = nil, waiting: Int? = nil,
                          perPage: Int? = nil, page: Int? = nil) async -> ApiResult<WorkDecisionsDto> {
        await Api.call("GET", base(project) + "decisions", query: [
            "status": status, "milestone": milestone, "mine": text(mine), "waiting": text(waiting), "per_page": text(perPage), "page": text(page),
        ])
    }

    static func proposeDecision(_ project: String, _ body: JsonBody) async -> ApiResult<WorkDecisionAnswerDto> {
        await Api.call("POST", base(project) + "decisions", body: body)
    }

    static func updateDecision(_ project: String, _ decision: String, _ body: JsonBody) async -> ApiResult<WorkDecisionAnswerDto> {
        await Api.call("PUT", base(project) + "decisions/\(decision)", body: body)
    }

    static func agreeDecision(_ project: String, _ decision: String, _ body: WorkNoteRequest) async -> ApiResult<WorkDecisionAnswerDto> {
        await Api.call("POST", base(project) + "decisions/\(decision)/agree", body: body.json)
    }

    static func declineDecision(_ project: String, _ decision: String, _ body: WorkNoteRequest) async -> ApiResult<WorkDecisionAnswerDto> {
        await Api.call("POST", base(project) + "decisions/\(decision)/decline", body: body.json)
    }

    /// Takes back my own proposal while it waits.
    static func withdrawDecision(_ project: String, _ decision: String) async -> ApiResult<WorkSummaryAnswerDto> {
        await Api.call("DELETE", base(project) + "decisions/\(decision)")
    }

    // MARK: - timeline

    static func timeline(_ project: String, kind: String? = nil, mine: Int? = nil, perPage: Int? = nil, page: Int? = nil) async -> ApiResult<WorkTimelineDto> {
        await Api.call("GET", base(project) + "timeline", query: [
            "kind": kind, "mine": text(mine), "per_page": text(perPage), "page": text(page),
        ])
    }
}

/// A part of the agreement a task, a file or a decision is tied to.
struct WorkPartDto: Decodable, Equatable {
    var uuid: String?
    var title: String?
    var position: Int?
}

struct WorkPersonDto: Decodable, Equatable {
    var uuid: String?
    var name: String?
}

// MARK: - tasks

struct WorkTasksDto: Decodable {
    var tasks: [WorkTaskDto]?
    var summary: WorkTaskSummaryDto?
    var limit: WorkTaskLimitDto?
    var readOnly: Bool?
    var can: WorkTasksCanDto?
}

struct WorkTaskSummaryDto: Decodable, Equatable {
    var total: Int?
    var todo: Int?
    var doing: Int?
    var done: Int?
    var blocked: Int?
    var overdue: Int?

    /// Not finished: the tasks that still ask for something.
    var open: Int { (todo ?? 0) + (doing ?? 0) + (blocked ?? 0) }
}

struct WorkTaskLimitDto: Decodable, Equatable {
    var max: Int?
}

struct WorkTasksCanDto: Decodable, Equatable {
    var taskCreate: Bool?
}

/// `status` = todo | doing | blocked | done; `assigneeSide` = client | freelancer; `scope` = shared | team (a team's inside task: the client never sees it).
struct WorkTaskDto: Decodable, Equatable {
    var uuid: String?
    var title: String?
    var description: String?
    var status: String?
    var dueOn: String?
    var overdue: Bool?
    var milestone: WorkPartDto?
    var assigneeSide: String?
    var scope: String?
    /// The person of my team it is given to (FS-206b).
    var assignee: WorkPersonDto?
    var createdBySide: String?
    /// It is on my side.
    var mine: Bool?
    var completedAt: String?
    var createdAt: String?
    var updatedAt: String?
    var can: WorkTaskCanDto?
}

struct WorkTaskCanDto: Decodable, Equatable {
    var taskEdit: Bool?
    var taskDelete: Bool?
    var taskStatus: Bool?
}

struct WorkTaskAnswerDto: Decodable {
    var task: WorkTaskDto?
    var summary: WorkTaskSummaryDto?
}

struct WorkStatusRequest {
    let status: String

    var json: JsonBody { ["status": status] }
}

/// What a delete answers: the new numbers (a task, a file or a decision — each reads the part it needs).
struct WorkSummaryAnswerDto: Decodable {
    var summary: JSONAny?
}

// MARK: - files

struct WorkFilesDto: Decodable {
    var files: [WorkFileDto]?
    var summary: WorkFileSummaryDto?
    var limit: WorkFileLimitDto?
    var upload: WorkUploadRulesDto?
    var readOnly: Bool?
    var can: WorkFilesCanDto?
}

struct WorkFileSummaryDto: Decodable, Equatable {
    var files: Int?
    var versions: Int?
    var storageBytes: Int?
}

struct WorkFileLimitDto: Decodable, Equatable {
    var maxFiles: Int?
    var maxStorageMb: Int?
}

/// What may be uploaded: the biggest file in KB, the allowed types (extensions) and how many versions a file takes.
struct WorkUploadRulesDto: Decodable, Equatable {
    var maxKb: Int?
    var mimes: [String]?
    var maxVersions: Int?
}

struct WorkFilesCanDto: Decodable, Equatable {
    var fileUpload: Bool?
}

struct WorkFileDto: Decodable, Equatable {
    var uuid: String?
    var title: String?
    var description: String?
    var milestone: WorkPartDto?
    var versionsCount: Int?
    var commentsCount: Int?
    /// The newest version.
    var current: WorkVersionDto?
    /// Every version, newest first — only on the file's own page.
    var versions: [WorkVersionDto]?
    var createdBySide: String?
    var mine: Bool?
    var scope: String?
    var createdAt: String?
    var updatedAt: String?
    var can: WorkFileCanDto?
}

struct WorkVersionDto: Decodable, Equatable {
    var number: Int?
    var note: String?
    var name: String?
    var mimeType: String?
    var sizeBytes: Int?
    var isImage: Bool?
    var previewOnly: Bool?
    var bySide: String?
    var createdAt: String?
    /// Where the bytes are read (signed in), relative to the freelancer API.
    var path: String?
}

struct WorkFileCanDto: Decodable, Equatable {
    var fileEdit: Bool?
    var fileDelete: Bool?
    var versionAdd: Bool?
    var commentAdd: Bool?
}

struct WorkFileAnswerDto: Decodable {
    var file: WorkFileDto?
    var summary: WorkFileSummaryDto?
}

// MARK: - notes

struct WorkCommentsDto: Decodable {
    var comments: [WorkCommentDto]?
    var readOnly: Bool?
    var can: WorkCommentsCanDto?
}

struct WorkCommentsCanDto: Decodable, Equatable {
    var commentAdd: Bool?
}

struct WorkCommentDto: Decodable, Equatable {
    var uuid: String?
    var body: String?
    var subject: WorkCommentSubjectDto?
    /// The version of the file it is about.
    var version: Int?
    var authorSide: String?
    var mine: Bool?
    var scope: String?
    var editedAt: String?
    var createdAt: String?
    var can: WorkCommentCanDto?
}

struct WorkCommentSubjectDto: Decodable, Equatable {
    var type: String?
    var uuid: String?
}

struct WorkCommentCanDto: Decodable, Equatable {
    var commentEdit: Bool?
    var commentDelete: Bool?
}

struct WorkCommentAnswerDto: Decodable {
    var comment: WorkCommentDto?
}

struct WorkCommentRequest {
    var file: String?
    var delivery: String?
    var version: Int?
    let body: String

    var json: JsonBody {
        var out: JsonBody = ["body": body]
        if let file { out["file"] = file }
        if let delivery { out["delivery"] = delivery }
        if let version { out["version"] = version }
        return out
    }
}

struct WorkCommentBodyRequest {
    let body: String

    var json: JsonBody { ["body": body] }
}

// MARK: - decisions

struct WorkDecisionsDto: Decodable {
    var decisions: [WorkDecisionDto]?
    var summary: WorkDecisionSummaryDto?
    var readOnly: Bool?
    var can: WorkDecisionsCanDto?
}

struct WorkDecisionSummaryDto: Decodable, Equatable {
    var total: Int?
    var proposed: Int?
    var agreed: Int?
    var declined: Int?
    var superseded: Int?
    var waitingForMe: Int?
}

struct WorkDecisionsCanDto: Decodable, Equatable {
    var decisionCreate: Bool?
}

/// `status` = proposed | agreed | declined | superseded.
struct WorkDecisionDto: Decodable, Equatable {
    var uuid: String?
    var title: String?
    var body: String?
    var status: String?
    var milestone: WorkPartDto?
    var proposedBySide: String?
    var mine: Bool?
    var clientAgreed: Bool?
    var freelancerAgreed: Bool?
    var answerNote: String?
    var supersedes: WorkDecisionRefDto?
    var supersededBy: WorkDecisionRefDto?
    var answeredAt: String?
    var createdAt: String?
    var updatedAt: String?
    var can: WorkDecisionCanDto?
}

struct WorkDecisionRefDto: Decodable, Equatable {
    var uuid: String?
    var title: String?
}

struct WorkDecisionCanDto: Decodable, Equatable {
    var decisionEdit: Bool?
    var decisionWithdraw: Bool?
    var decisionAgree: Bool?
    var decisionDecline: Bool?
    var decisionSupersede: Bool?
}

struct WorkDecisionAnswerDto: Decodable {
    var decision: WorkDecisionDto?
    var summary: WorkDecisionSummaryDto?
}

struct WorkNoteRequest {
    var note: String?

    /// No `note` when there is none (the server leaves the answer without one).
    var json: JsonBody {
        var out: JsonBody = [:]
        if let note { out["note"] = note }
        return out
    }
}

// MARK: - timeline

struct WorkTimelineDto: Decodable {
    var events: [WorkEventDto]?
}

/// One step of the project: `label` is ready text in my language; `kind` = task | file | comment | decision | agreement | milestone | dispute | change | review | rights.
struct WorkEventDto: Decodable, Equatable {
    var key: String?
    var source: String?
    var kind: String?
    var action: String?
    var label: String?
    var actor: String?
    var mine: Bool?
    var by: WorkEventByDto?
    var subject: WorkEventSubjectDto?
    var at: String?
}

struct WorkEventByDto: Decodable, Equatable {
    var name: String?
    /// Always true when somebody of a team took the step (a flag, not a name).
    var team: Bool?
}

struct WorkEventSubjectDto: Decodable, Equatable {
    var type: String?
    var uuid: String?
    var title: String?
}
