import Foundation

// freelanceApp `net/ReputationApi.kt`.

/// My reputation (FS-604), under `/api/freelance/v1`: the six figures clients see, where each comes from, the projects behind delivery and
/// completion (mine only - clients never see them), and my requests to correct the data of one project.
enum ReputationApi {
    private static let base = Api.freelance

    static func show() async -> ApiResult<ReputationDto> { await Api.call("GET", base + "reputation") }

    /// 409 `freelance_reputation_open` (one open request per project and figure), 422 `freelance_reputation_limit` / `_not_yours`.
    static func ask(_ body: ReputationCorrectionRequest) async -> ApiResult<ReputationCorrectionsDto> {
        await Api.call("POST", base + "reputation/corrections", body: [
            "agreement": body.agreement, "indicator": body.indicator, "reason": body.reason, "note": body.note,
        ])
    }
}

struct ReputationDto: Decodable {
    var indicators: [ReputationIndicatorDto]?
    /// The mean of the known figures, nil while fewer than three are known.
    var overall: Int?
    var corrections: [ReputationCorrectionDto]?
    /// client_delay · client_ended · scope_changed · wrong_data · other
    var reasons: [String]?
    /// How many requests may wait for an answer at once.
    var openMax: Int?
}

struct ReputationIndicatorDto: Decodable {
    /// quality · delivery · communication · completion · repeat_clients · technical
    var key: String?
    /// 0..100, nil while there is not enough data.
    var value: Int?
    /// excellent · good · fair · low · new
    var level: String?
    var source: ReputationSourceDto?
    /// Only for delivery and completion.
    var projects: [ReputationProjectDto]?
}

struct ReputationSourceDto: Decodable {
    /// ratings · parts · projects · clients · certifications
    var kind: String?
    var count: Int?
    var hits: Int?
    var excluded: Int?
    var best: [ReputationBestDto]?
    var verifiedWorks: Int?
}

struct ReputationBestDto: Decodable {
    var name: String?
    var level: String?
}

struct ReputationProjectDto: Decodable, Identifiable {
    var id: String { agreement ?? "" }
    /// The agreement's uuid (what a correction names).
    var agreement: String?
    var title: String?
    var parts: Int?
    var late: Int?
    /// on_time · late (delivery) · completed · ended_by_me (completion)
    var outcome: String?
    /// Staff took it out of the figure after a correction.
    var excluded: Bool?
}

struct ReputationCorrectionDto: Decodable {
    var uuid: String?
    /// delivery · completion
    var indicator: String?
    var reason: String?
    var note: String?
    /// open · accepted · rejected
    var status: String?
    var decisionNote: String?
    var sentAt: String?
    var decidedAt: String?
    var project: ReputationCorrectionProjectDto?
}

struct ReputationCorrectionProjectDto: Decodable {
    var agreement: String?
    var uuid: String?
    var title: String?
}

struct ReputationCorrectionsDto: Decodable {
    var corrections: [ReputationCorrectionDto]?
}

/// `note` is 10..1000 characters.
struct ReputationCorrectionRequest {
    let agreement: String
    let indicator: String
    let reason: String
    let note: String
}
