import Foundation

/// FL-L09 (part B), the loyalty program on the client's side (`mobile/v1/freelance/...`) — the twin of Android's
/// `network/FreelanceLoyaltyApi.kt`: every freelancer I finished work with who rewards returning clients (the discount I hold, the next step and
/// how far I am from it), and the same for one freelancer before I buy. The server applies the discount to a project offer, a ready-made service
/// and a consultation; these only show it.
enum FreelanceLoyaltyApi {
    private static let base = "mobile/v1/freelance"

    /// My discounts: the best one first.
    static func mine() async throws -> ApiEnvelope<[FlLoyaltyCardDto]> {
        try await ApiClient.snake("\(base)/loyalty")
    }

    /// One freelancer's program as it stands for me (`loyalty` is nil when they run none).
    static func of(_ uuid: String) async throws -> ApiEnvelope<FlLoyaltyOfDto> {
        try await ApiClient.snake("\(base)/freelancers/\(uuid)/loyalty")
    }
}

struct FlLoyaltyOfDto: Codable, Equatable {
    var loyalty: FlLoyaltyViewDto?
}

/// One step of a ladder: after `after` finished works the client takes `discountBp` (basis points) off the price.
struct FlLoyaltyTierDto: Codable, Equatable {
    var after: Int?
    var discountBp: Int?
    var reached: Bool?
    /// Works still to finish for the next step (only on `FlLoyaltyViewDto.next`).
    var remaining: Int?
}

/// The program as I read it before buying: the ladder, my works with this freelancer, the step I hold, the next and the progress (0–1).
struct FlLoyaltyViewDto: Codable, Equatable {
    var works: Int?
    var tiers: [FlLoyaltyTierDto]?
    var current: FlLoyaltyTierDto?
    var next: FlLoyaltyTierDto?
    var progress: Double?
}

struct FlLoyaltyFreelancerDto: Codable, Equatable {
    var uuid: String?
    var name: String?
    var headline: String?
    var avatarUrl: String?
}

/// A freelancer I worked with and the program they run, as it stands for me.
struct FlLoyaltyCardDto: Codable, Equatable, Identifiable {
    var freelancer: FlLoyaltyFreelancerDto?
    var works: Int?
    var tiers: [FlLoyaltyTierDto]?
    var current: FlLoyaltyTierDto?
    var next: FlLoyaltyTierDto?
    var progress: Double?

    var id: String { freelancer?.uuid ?? "" }

    var view: FlLoyaltyViewDto { FlLoyaltyViewDto(works: works, tiers: tiers, current: current, next: next, progress: progress) }
}
