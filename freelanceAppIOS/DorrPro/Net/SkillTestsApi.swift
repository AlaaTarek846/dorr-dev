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
    var badge: SkillBadgeDto?
    var inProgress: Bool?
    /// When I may try again after a miss; nil when I may now.
    var retakeAt: String?
    var canStart: Bool?
}

struct SkillBadgeDto: Decodable {
    var skillId: Int?
    var name: String?
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
