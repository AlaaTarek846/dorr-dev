import Foundation

// androidApp `network/FreelancerTeamViewApi.kt` — FS-210: a freelancers' team as the client reads it (docs/modules/freelance/API.md «عرض باسم
// الفريق» and «ملف الفريق»). Not the client's own project team (FS-104, `FreelanceTeam.swift`). The client reads the first name, the role and
// what each person takes on, and a person is on a team's profile only when they agreed to it (D10) — the server sends nothing else, and the
// app asks for nothing else.

/// The team an offer or an agreement is made in the name of (`team` is nil on a freelancer's own). `members` are the people the offer names
/// (the agreement keeps them as they were when the work started).
struct FreelancerTeamViewDto: Codable, Equatable {
    var uuid: String?
    var name: String?
    var profile: FreelancerTeamBadgeDto?
    var members: [FreelancerTeamStaffDto]?
}

/// What an offer shows next to the team's name: logo, badge, rating and finished projects.
struct FreelancerTeamBadgeDto: Codable, Equatable {
    var logoUrl: String?
    var verified: Bool?
    var acceptingProjects: Bool?
    /// The average once there are three ratings, else nil (the app says «new»).
    var rating: Double?
    var reviewsCount: Int?
    var completedProjects: Int?
}

/// A person on the offer: first name, `pm` or `executor`, and what they take on.
struct FreelancerTeamStaffDto: Codable, Equatable {
    var name: String?
    var role: String?
    var responsibility: String?
}

/// `GET mobile/v1/freelance/freelancer-teams/{uuid}` answers `{team}`.
struct FreelancerTeamEnvelopeDto: Codable, Equatable {
    var team: FreelancerTeamProfileDto?
}

/// The team's profile for a client.
struct FreelancerTeamProfileDto: Codable, Equatable {
    var uuid: String?
    var name: String?
    var bio: String?
    var logoUrl: String?
    var verified: Bool?
    var acceptingProjects: Bool?
    var runningProjects: Int?
    var since: String?
    /// All the people on the team; `members` holds only the ones who agreed to be shown.
    var membersCount: Int?
    var members: [FreelancerTeamPersonDto]?
    var specialties: [FreelanceNamedDto]?
    var skills: [FreelanceNamedDto]?
    var languages: [String]?
    var stats: FreelancerTeamStatsDto?
    var reviews: [FreelanceReviewLineDto]?
    var portfolio: [FreelancePortfolioDto]?
    /// FS-213: I keep this team (the heart on its page).
    var isFavorite: Bool?
}

/// A person who agreed to be shown: first name, `owner` · `pm` · `member`, job title (FS-213), headline and photo.
struct FreelancerTeamPersonDto: Codable, Equatable {
    var name: String?
    var role: String?
    var jobTitle: String?
    var headline: String?
    var avatarUrl: String?
}

/// The team's own figures (never its people's): rating from three ratings, projects finished, on time and the share finished.
struct FreelancerTeamStatsDto: Codable, Equatable {
    var rating: Double?
    var reviewsCount: Int?
    var completedProjects: Int?
    var completionRate: Int?
    var onTimeRate: Int?
}

extension FreelanceApi {
    /// FS-210: a freelancers' team's profile (public, or private once it sent me an offer): answers `{team}`; 404 when it is not mine to read.
    static func freelancerTeam(_ uuid: String) async throws -> ApiEnvelope<FreelancerTeamEnvelopeDto> {
        try await ApiClient.snake("mobile/v1/freelance/freelancer-teams/\(uuid)")
    }
}
