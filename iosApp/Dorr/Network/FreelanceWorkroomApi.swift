import Foundation

/// FS-150e: a project's Workroom as the client sees it — the twin of Android's `network/FreelanceWorkroomApi.kt`: tasks, files (with
/// versions and notes), the decisions log and the timeline. Everything lives under `mobile/v1/freelance/projects/{uuid}/workroom/…` and
/// works while the agreement is `active` (write) or `completed` (read only). The screens read once when they open, once on a live event
/// (`freelance.workroom` on the project's channel) and once when the connection returns — nothing on a timer.
///
/// A list answers with its rows, a `summary` (the numbers for the chips), what I `can` do and `read_only`; a page is 30 rows and
/// `pagination.has_more_pages` says another follows. A field I want cleared on an update (a part, a day) goes as an empty string.
/// The bytes of a file version are read with `FreelanceApi.file` (the version's `path`, under `mobile/v1/freelance/`).
/// DTOs are plain optional structs read with `ApiClient.snake`.
enum FreelanceWorkroomApi {
    private static func root(_ project: String) -> String { "mobile/v1/freelance/projects/\(project)/workroom" }

    private static func text(_ value: Int?) -> String? { value.map { String($0) } }

    // MARK: - tasks

    static func tasks(_ project: String, status: String? = nil, milestone: String? = nil, assignee: String? = nil, mine: Int? = nil, overdue: Int? = nil,
                      perPage: Int? = nil, page: Int? = nil) async throws -> ApiEnvelope<WorkTasksDto> {
        try await ApiClient.snake("\(root(project))/tasks", query: [
            "status": status, "milestone": milestone, "assignee": assignee, "mine": text(mine), "overdue": text(overdue),
            "per_page": text(perPage), "page": text(page),
        ])
    }

    static func createTask(_ project: String, body: [String: JSONValue]) async throws -> ApiEnvelope<WorkTaskAnswerDto> {
        try await ApiClient.snake("\(root(project))/tasks", method: "POST", body: body)
    }

    static func updateTask(_ project: String, task: String, body: [String: JSONValue]) async throws -> ApiEnvelope<WorkTaskAnswerDto> {
        try await ApiClient.snake("\(root(project))/tasks/\(task)", method: "PUT", body: body)
    }

    static func setTaskStatus(_ project: String, task: String, status: String) async throws -> ApiEnvelope<WorkTaskAnswerDto> {
        try await ApiClient.snake("\(root(project))/tasks/\(task)/status", method: "POST", body: ["status": .string(status)])
    }

    static func deleteTask(_ project: String, task: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(root(project))/tasks/\(task)", method: "DELETE")
    }

    // MARK: - files

    static func files(_ project: String, milestone: String? = nil, mine: Int? = nil, search: String? = nil, perPage: Int? = nil,
                      page: Int? = nil) async throws -> ApiEnvelope<WorkFilesDto> {
        try await ApiClient.snake("\(root(project))/files", query: [
            "milestone": milestone, "mine": text(mine), "search": search, "per_page": text(perPage), "page": text(page),
        ])
    }

    /// The file with all its versions (newest first).
    static func file(_ project: String, file: String) async throws -> ApiEnvelope<WorkFileAnswerDto> {
        try await ApiClient.snake("\(root(project))/files/\(file)")
    }

    /// Multipart: `file` and the text fields (`title`, `description`, `milestone`, `note`).
    static func uploadFile(_ project: String, fields: [String: String], file: ApiClient.FilePart) async throws -> ApiEnvelope<WorkFileAnswerDto> {
        let data = try await ApiClient.uploadRaw("\(root(project))/files", fields: fields, files: [file])
        return try ApiClient.snakeDecoder.decode(ApiEnvelope<WorkFileAnswerDto>.self, from: data)
    }

    static func updateFile(_ project: String, file: String, body: [String: JSONValue]) async throws -> ApiEnvelope<WorkFileAnswerDto> {
        try await ApiClient.snake("\(root(project))/files/\(file)", method: "PUT", body: body)
    }

    /// A new version of a file (its owner only): `file` and an optional `note`.
    static func addVersion(_ project: String, file: String, fields: [String: String], upload: ApiClient.FilePart) async throws -> ApiEnvelope<WorkFileAnswerDto> {
        let data = try await ApiClient.uploadRaw("\(root(project))/files/\(file)/versions", fields: fields, files: [upload])
        return try ApiClient.snakeDecoder.decode(ApiEnvelope<WorkFileAnswerDto>.self, from: data)
    }

    static func deleteFile(_ project: String, file: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(root(project))/files/\(file)", method: "DELETE")
    }

    // MARK: - notes on a file or a delivery

    static func comments(_ project: String, file: String? = nil, delivery: String? = nil, version: Int? = nil, perPage: Int? = nil,
                         page: Int? = nil) async throws -> ApiEnvelope<WorkCommentsDto> {
        try await ApiClient.snake("\(root(project))/comments", query: [
            "file": file, "delivery": delivery, "version": text(version), "per_page": text(perPage), "page": text(page),
        ])
    }

    static func addComment(_ project: String, request: WorkCommentRequest) async throws -> ApiEnvelope<WorkCommentAnswerDto> {
        var body: [String: JSONValue] = ["body": .string(request.body)]
        if let file = request.file { body["file"] = .string(file) }
        if let delivery = request.delivery { body["delivery"] = .string(delivery) }
        if let version = request.version { body["version"] = .int(version) }
        return try await ApiClient.snake("\(root(project))/comments", method: "POST", body: body)
    }

    static func editComment(_ project: String, comment: String, body: String) async throws -> ApiEnvelope<WorkCommentAnswerDto> {
        try await ApiClient.snake("\(root(project))/comments/\(comment)", method: "PUT", body: ["body": .string(body)])
    }

    static func deleteComment(_ project: String, comment: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(root(project))/comments/\(comment)", method: "DELETE")
    }

    // MARK: - decisions

    static func decisions(_ project: String, status: String? = nil, milestone: String? = nil, mine: Int? = nil, waiting: Int? = nil,
                          perPage: Int? = nil, page: Int? = nil) async throws -> ApiEnvelope<WorkDecisionsDto> {
        try await ApiClient.snake("\(root(project))/decisions", query: [
            "status": status, "milestone": milestone, "mine": text(mine), "waiting": text(waiting), "per_page": text(perPage), "page": text(page),
        ])
    }

    static func proposeDecision(_ project: String, body: [String: JSONValue]) async throws -> ApiEnvelope<WorkDecisionAnswerDto> {
        try await ApiClient.snake("\(root(project))/decisions", method: "POST", body: body)
    }

    static func updateDecision(_ project: String, decision: String, body: [String: JSONValue]) async throws -> ApiEnvelope<WorkDecisionAnswerDto> {
        try await ApiClient.snake("\(root(project))/decisions/\(decision)", method: "PUT", body: body)
    }

    static func agreeDecision(_ project: String, decision: String, request: WorkNoteRequest) async throws -> ApiEnvelope<WorkDecisionAnswerDto> {
        try await ApiClient.snake("\(root(project))/decisions/\(decision)/agree", method: "POST", body: noteBody(request))
    }

    static func declineDecision(_ project: String, decision: String, request: WorkNoteRequest) async throws -> ApiEnvelope<WorkDecisionAnswerDto> {
        try await ApiClient.snake("\(root(project))/decisions/\(decision)/decline", method: "POST", body: noteBody(request))
    }

    /// Takes back my own proposal while it waits.
    static func withdrawDecision(_ project: String, decision: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(root(project))/decisions/\(decision)", method: "DELETE")
    }

    private static func noteBody(_ request: WorkNoteRequest) -> [String: JSONValue] {
        var body: [String: JSONValue] = [:]
        if let note = request.note { body["note"] = .string(note) }
        return body
    }

    // MARK: - timeline

    static func timeline(_ project: String, kind: String? = nil, mine: Int? = nil, perPage: Int? = nil, page: Int? = nil) async throws -> ApiEnvelope<WorkTimelineDto> {
        try await ApiClient.snake("\(root(project))/timeline", query: ["kind": kind, "mine": text(mine), "per_page": text(perPage), "page": text(page)])
    }
}

// MARK: - DTOs

/// A part of the agreement a task, a file or a decision is tied to.
struct WorkPartDto: Codable, Equatable {
    var uuid: String
    var title: String?
    var position: Int?
}

// ---- tasks ----

struct WorkTasksDto: Codable, Equatable {
    var tasks: [WorkTaskDto]?
    var summary: WorkTaskSummaryDto?
    var limit: WorkTaskLimitDto?
    var readOnly: Bool?
    var can: WorkTasksCanDto?
}

struct WorkTaskSummaryDto: Codable, Equatable {
    var total: Int?
    var todo: Int?
    var doing: Int?
    var done: Int?
    var blocked: Int?
    var overdue: Int?

    /// Not finished: the tasks that still ask for something.
    var open: Int { (todo ?? 0) + (doing ?? 0) + (blocked ?? 0) }
}

struct WorkTaskLimitDto: Codable, Equatable {
    var max: Int?
}

struct WorkTasksCanDto: Codable, Equatable {
    var taskCreate: Bool?
}

/// `status` = todo | doing | blocked | done; `assigneeSide` = client | freelancer. `mine`: it is on my side (given to me).
struct WorkTaskDto: Codable, Equatable, Identifiable {
    var uuid: String
    var title: String?
    var description: String?
    var status: String?
    var dueOn: String?
    var overdue: Bool?
    var milestone: WorkPartDto?
    var assigneeSide: String?
    var createdBySide: String?
    var mine: Bool?
    var completedAt: String?
    var createdAt: String?
    var updatedAt: String?
    var can: WorkTaskCanDto?

    var id: String { uuid }
}

struct WorkTaskCanDto: Codable, Equatable {
    var taskEdit: Bool?
    var taskDelete: Bool?
    var taskStatus: Bool?
}

struct WorkTaskAnswerDto: Codable, Equatable {
    var task: WorkTaskDto?
    var summary: WorkTaskSummaryDto?
}

// ---- files ----

struct WorkFilesDto: Codable, Equatable {
    var files: [WorkFileDto]?
    var summary: WorkFileSummaryDto?
    var limit: WorkFileLimitDto?
    var upload: WorkUploadRulesDto?
    var readOnly: Bool?
    var can: WorkFilesCanDto?
}

struct WorkFileSummaryDto: Codable, Equatable {
    var files: Int?
    var versions: Int?
    var storageBytes: Int?
}

struct WorkFileLimitDto: Codable, Equatable {
    var maxFiles: Int?
    var maxStorageMb: Int?
}

/// What may be uploaded: the biggest file in KB, the allowed types (extensions) and how many versions a file takes.
struct WorkUploadRulesDto: Codable, Equatable {
    var maxKb: Int?
    var mimes: [String]?
    var maxVersions: Int?
}

struct WorkFilesCanDto: Codable, Equatable {
    var fileUpload: Bool?
}

struct WorkFileDto: Codable, Equatable, Identifiable {
    var uuid: String
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
    var createdAt: String?
    var updatedAt: String?
    var can: WorkFileCanDto?

    var id: String { uuid }
}

struct WorkVersionDto: Codable, Equatable {
    var number: Int?
    var note: String?
    var name: String?
    var mimeType: String?
    var sizeBytes: Int?
    var isImage: Bool?
    /// FL-L13: only a small picture of it may be seen for now (never saved or shared).
    var previewOnly: Bool?
    var bySide: String?
    var createdAt: String?
    /// Where the bytes are read (signed in), under `mobile/v1/freelance/`.
    var path: String?
}

struct WorkFileCanDto: Codable, Equatable {
    var fileEdit: Bool?
    var fileDelete: Bool?
    var versionAdd: Bool?
    var commentAdd: Bool?
}

struct WorkFileAnswerDto: Codable, Equatable {
    var file: WorkFileDto?
    var summary: WorkFileSummaryDto?
}

// ---- notes ----

struct WorkCommentsDto: Codable, Equatable {
    var comments: [WorkCommentDto]?
    var readOnly: Bool?
    var can: WorkCommentsCanDto?
}

struct WorkCommentsCanDto: Codable, Equatable {
    var commentAdd: Bool?
}

struct WorkCommentDto: Codable, Equatable, Identifiable {
    var uuid: String
    var body: String?
    var subject: WorkCommentSubjectDto?
    /// The version of the file it is about.
    var version: Int?
    var authorSide: String?
    var mine: Bool?
    var editedAt: String?
    var createdAt: String?
    var can: WorkCommentCanDto?

    var id: String { uuid }
}

struct WorkCommentSubjectDto: Codable, Equatable {
    var type: String?
    var uuid: String?
}

struct WorkCommentCanDto: Codable, Equatable {
    var commentEdit: Bool?
    var commentDelete: Bool?
}

struct WorkCommentAnswerDto: Codable, Equatable {
    var comment: WorkCommentDto?
}

struct WorkCommentRequest {
    var file: String?
    var delivery: String?
    var version: Int?
    var body: String
}

// ---- decisions ----

struct WorkDecisionsDto: Codable, Equatable {
    var decisions: [WorkDecisionDto]?
    var summary: WorkDecisionSummaryDto?
    var readOnly: Bool?
    var can: WorkDecisionsCanDto?
}

struct WorkDecisionSummaryDto: Codable, Equatable {
    var total: Int?
    var proposed: Int?
    var agreed: Int?
    var declined: Int?
    var superseded: Int?
    var waitingForMe: Int?
}

struct WorkDecisionsCanDto: Codable, Equatable {
    var decisionCreate: Bool?
}

/// `status` = proposed | agreed | declined | superseded. The one who proposes is agreed by that act; the other side answers.
struct WorkDecisionDto: Codable, Equatable, Identifiable {
    var uuid: String
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

    var id: String { uuid }
}

struct WorkDecisionRefDto: Codable, Equatable {
    var uuid: String?
    var title: String?
}

struct WorkDecisionCanDto: Codable, Equatable {
    var decisionEdit: Bool?
    var decisionWithdraw: Bool?
    var decisionAgree: Bool?
    var decisionDecline: Bool?
    var decisionSupersede: Bool?
}

struct WorkDecisionAnswerDto: Codable, Equatable {
    var decision: WorkDecisionDto?
    var summary: WorkDecisionSummaryDto?
}

struct WorkNoteRequest {
    var note: String?
}

// ---- timeline ----

struct WorkTimelineDto: Codable, Equatable {
    var events: [WorkEventDto]?
}

/// One step of the project: `label` is ready text in my language; `kind` = task | file | comment | decision | agreement | milestone |
/// dispute | change | review | rights.
struct WorkEventDto: Codable, Equatable, Identifiable {
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

    /// The step's key; a step the server gave none for is named by what it holds.
    var id: String {
        if let key, !key.isEmpty { return key }
        return [kind, action, at, subject?.uuid].compactMap { $0 }.joined(separator: "|")
    }
}

/// Who took the step when it was a person of a team (`team` is true then): the name only, never more.
struct WorkEventByDto: Codable, Equatable {
    var name: String?
    var team: Bool?
}

/// What the step is about; a note names the file or delivery it is on in `on`.
struct WorkEventSubjectDto: Codable, Equatable {
    var type: String?
    var uuid: String?
    var title: String?
    var on: WorkEventTargetDto?
}

/// The file or delivery a note is on (Android reuses `WorkEventSubjectDto`; a struct cannot hold itself, so the inner one is its own type).
struct WorkEventTargetDto: Codable, Equatable {
    var type: String?
    var uuid: String?
    var title: String?
}
