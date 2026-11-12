import Foundation

// freelanceApp `net/CapacityApi.kt`.

/// My capacity (FL-L10), under `/api/freelance/v1`: how many projects I take at once — only running work counts — and the clients
/// waiting in line for my services when I am full.
enum CapacityApi {
    private static let base = Api.freelance

    static func show() async -> ApiResult<CapacityDto> { await Api.call("GET", base + "capacity") }

    static func update(_ body: CapacityRequest) async -> ApiResult<CapacityDto> { await Api.call("PUT", base + "capacity", body: body.json) }
}

/// `max` nil = no cap (Gson leaves the null out of the body).
struct CapacityRequest {
    let max: Int?

    var json: JsonBody {
        var body: JsonBody = [:]
        if let max { body["max"] = max }
        return body
    }
}

struct CapacityDto: Decodable {
    var max: Int?
    /// The highest cap I may choose.
    var maxChoice: Int?
    var running: Int?
    /// Places kept for the first in line.
    var holds: Int?
    var waiting: Int?
    var full: Bool?
    var line: [WaitlistEntryDto]?
}

struct WaitlistEntryDto: Decodable, Identifiable {
    var id: Int?
    /// waiting | offered
    var status: String?
    var position: Int?
    var holdUntil: String?
    var joinedAt: String?
    var service: WaitlistServiceDto?
    var client: WaitlistClientDto?
}

struct WaitlistServiceDto: Decodable {
    var uuid: String?
    var title: String?
}

struct WaitlistClientDto: Decodable {
    var name: String?
}
