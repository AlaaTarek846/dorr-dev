import Foundation

// freelanceApp `net/HomeApi.kt`.

/// The home in one answer (`GET freelance/v1/home`): promos, my numbers, what waits for my answer, the next consultation and my running work.
enum HomeApi {
    static func show() async -> ApiResult<HomeDto> { await Api.call("GET", Api.freelance + "home") }
}

struct HomeDto: Decodable {
    var serverTime: String?
    /// The country's switches: a tab or a card whose feature is off stays hidden.
    var features: [String: Bool]?
    var promos: [PromoDto]?
    var stats: HomeStatsDto?
    var attention: HomeAttentionDto?
    var nextConsultation: HomeConsultationDto?
    var work: [HomeWorkDto]?

    func on(_ feature: String) -> Bool { features?[feature] == true }
}

/// A banner the staff put on the home: `targetType` `none` / `screen` (one of the app's pages, `target` its key) / `url` (an https page).
struct PromoDto: Decodable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var title: String?
    var body: String?
    var cta: String?
    var imagePath: String?
    /// Default `none`.
    var targetType: String?
    var target: String?
}

struct HomeStatsDto: Decodable {
    @CurrencyLabel var currency: String? = nil
    /// Default 2.
    var decimals: Int?
    var earnedMonthMinor: Int?
    /// Earned but still held (released after the client's review time).
    var pendingMinor: Int?
    var liveOffers: Int?
    var activeProjects: Int?
    /// Nil until enough reviews are in.
    var rating: Double?
    var reviewsCount: Int?
    var completedProjects: Int?
}

struct HomeAttentionDto: Decodable {
    var ordersAwaiting: Int?
    var consultationsPending: Int?
    var invitationsPending: Int?
    /// Parts the client sent back for changes.
    var revisions: Int?

    /// What waits on the «My work» tab.
    var work: Int { (ordersAwaiting ?? 0) + (consultationsPending ?? 0) + (revisions ?? 0) }
}

struct HomeConsultationDto: Decodable {
    var uuid: String?
    /// `pending` (waiting for my answer) or `booked`.
    var status: String?
    var title: String?
    var minutes: Int?
    var clientName: String?
    var startsAt: String?
    var endsAt: String?
}

struct HomeWorkDto: Decodable {
    var projectUuid: String?
    var title: String?
    var clientName: String?
    var milestone: HomeMilestoneDto?
}

struct HomeMilestoneDto: Decodable {
    var title: String?
    var status: String?
    var dueAt: String?
}

/// The last home answer, shared by the home, the bottom bar's badges and the tabs that hide with a feature. Read on open, when the
/// connection returns and when a notice arrives — never on a timer.
@MainActor
final class HomeHub: ObservableObject {
    static let shared = HomeHub()

    @Published private(set) var home: HomeDto?

    @discardableResult
    func refresh() async -> ApiResult<HomeDto> {
        let result = await HomeApi.show()
        if let data = result.value { home = data }
        return result
    }

    func clear() {
        home = nil
    }
}
