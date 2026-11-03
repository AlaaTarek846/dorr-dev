import Foundation

/// The client's freelance hub in one answer (the client redesign, batch B — `GET mobile/v1/freelance/home`) — the twin of Android's
/// `network/FreelanceHomeApi.kt`: the promo banners for the client, the few numbers that matter, what waits for me, the next
/// consultation, my running projects and a row of ready-made services. Read on open, on a pull, when a freelance notice arrives and
/// when the connection returns — never on a timer.
enum FreelanceHomeApi {
    static func home() async throws -> ApiEnvelope<FlHomeDto> {
        try await ApiClient.snake("mobile/v1/freelance/home")
    }
}

struct FlHomeDto: Codable, Equatable {
    var service: Bool?
    var features: [String: Bool]?
    var currency: String?
    var decimals: Int?
    var promos: [FlPromoDto]?
    var stats: FlHomeStatsDto?
    var attention: FlHomeAttentionDto?
    var nextConsultation: FlHomeConsultationDto?
    var projects: [FreelanceProjectDto]?
    var services: [MarketServiceCardDto]?
    /// Nothing posted or ordered yet: the hub shows how it works instead of empty numbers.
    var isNew: Bool?

    /// A feature of the country is on.
    func on(_ feature: String) -> Bool { features?[feature] ?? false }
}

/// A banner the staff wrote: `targetType` none · screen (a page of the hub, see `FreelancePromo::CLIENT_SCREENS`) · url (https).
struct FlPromoDto: Codable, Equatable, Identifiable {
    var uuid: String
    var id: String { uuid }
    var title: String?
    var body: String?
    var cta: String?
    var imagePath: String?
    var targetType: String?
    var target: String?
}

struct FlHomeStatsDto: Codable, Equatable {
    var runningProjects: Int?
    var newOffers: Int?
    var runningOrders: Int?
    var upcomingConsultations: Int?
    var fundedMonthMinor: Int?
}

/// How many of a kind wait, and the project the newest one is on.
struct FlWaitingDto: Codable, Equatable {
    var count: Int?
    var projectUuid: String?
}

struct FlHomeAttentionDto: Codable, Equatable {
    var newOffers: FlWaitingDto?
    var toReview: FlWaitingDto?
    var toFund: FlWaitingDto?
    var toFix: FlWaitingDto?
    var specialOffers: Int?
}

struct FlHomeConsultationDto: Codable, Equatable {
    var uuid: String
    var status: String?
    var title: String?
    var minutes: Int?
    var freelancerName: String?
    var startsAt: String?
    var endsAt: String?
}
