import Foundation

// freelanceApp `net/SkillTestsApi.kt`.

/// Skill tests and badges (FL-L08), under `/api/freelance/v1`: the tests of my skills and where I stand, a test's questions (texts only —
/// the right answer never comes down) and my answers, which the server marks.
enum SkillTestsApi {
    private static let base = Api.freelance

    static func overview() async -> ApiResult<SkillTestsDto> { await Api.call("GET", base + "skill-tests") }

    static func start(_ skillId: Int) async -> ApiResult<SkillAttemptDto> { await Api.call("POST", base + "skill-tests/\(skillId)/start") }

    static func submit(_ attemptId: Int, _ body: SkillSubmitRequest) async -> ApiResult<SkillResultDto> {
        await Api.call("POST", base + "skill-test-attempts/\(attemptId)/submit", body: ["answers": body.answers])
    }

    /// FS-602: a practical challenge for the next level (advanced / expert) of a skill. 409 `freelance_skill_challenge_open`, 422 `_wait` / `_level`.
    static func challenge(_ skillId: Int, _ body: SkillChallengeRequest) async -> ApiResult<SkillChallengeWrapDto> {
        await Api.call("POST", base + "skill-tests/\(skillId)/challenge", body: ["target_level": body.targetLevel, "link": body.link, "note": body.note])
    }

    static func withdrawChallenge(_ uuid: String) async -> ApiResult<SkillChallengeWrapDto> {
        await Api.call("POST", base + "skill-challenges/\(uuid)/withdraw")
    }
}

struct SkillTestsDto: Decodable {
    /// False where the admin has the feature off in my country.
    var enabled: Bool?
    var tests: [SkillTestDto]?
    /// Every badge I hold now (also the ones whose test is no longer offered).
    var badges: [SkillBadgeDto]?
}

struct SkillTestDto: Decodable {
    var skillId: Int?
    var name: String?
    var questions: Int?
    var minutes: Int?
    var passPercent: Int?
    /// FS-602: the mark that gives the intermediate level (a pass below it gives beginner).
    var intermediatePercent: Int?
    var badge: SkillBadgeDto?
    /// FS-602: the badge ends soon and the test may be sat again to renew it (`canStart` is then true).
    var renewable: Bool?
    /// FS-602: where I stand for the next level by a practical challenge.
    var challenge: SkillChallengeStateDto?
    var inProgress: Bool?
    /// When I may try again after a miss; nil when I may now.
    var retakeAt: String?
    var canStart: Bool?
}

struct SkillBadgeDto: Decodable {
    var skillId: Int?
    var name: String?
    /// FS-602: beginner · intermediate · advanced · expert
    var level: String?
    var scorePercent: Int?
    var earnedAt: String?
    var expiresAt: String?
}

struct SkillAttemptDto: Decodable {
    var attemptId: Int?
    var skillId: Int?
    var expiresAt: String?
    var secondsLeft: Int?
    var passPercent: Int?
    var questions: [SkillQuestionDto]?
}

struct SkillQuestionDto: Decodable {
    var id: Int?
    var prompt: String?
    var options: [String]?
}

/// `answers` maps a question id to the position of the option picked among those shown.
struct SkillSubmitRequest {
    let answers: [String: Int]
}

struct SkillResultDto: Decodable {
    var attemptId: Int?
    var skillId: Int?
    /// passed | failed | expired
    var status: String?
    var passed: Bool?
    var scorePercent: Int?
    var correctCount: Int?
    var total: Int?
    var passPercent: Int?
    var badge: SkillBadgeDto?
    var retakeAt: String?
}

/// FS-602: the practical challenge of one skill: the level it is for now, the brief, my open or last challenge and when I may send one.
struct SkillChallengeStateDto: Decodable {
    var enabled: Bool?
    /// advanced · expert · nil (none now)
    var nextLevel: String?
    var brief: String?
    var current: SkillChallengeDto?
    var retryAt: String?
    var canSend: Bool?
}

struct SkillChallengeDto: Decodable {
    var uuid: String?
    var targetLevel: String?
    var link: String?
    var note: String?
    /// submitted · accepted · rejected · withdrawn
    var status: String?
    var grantedLevel: String?
    var decisionNote: String?
    var sentAt: String?
    var decidedAt: String?
    var canWithdraw: Bool?
}

struct SkillChallengeWrapDto: Decodable {
    var challenge: SkillChallengeDto?
}

/// `note` is 20..2000 characters; `link` an http(s) address.
struct SkillChallengeRequest {
    let targetLevel: String
    let link: String
    let note: String
}
