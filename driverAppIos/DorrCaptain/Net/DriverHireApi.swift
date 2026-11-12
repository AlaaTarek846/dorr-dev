import Foundation

// driverApp `net/DriverHireApi.kt` — same calls, paths and bodies; its DTOs below, as in the Kotlin file.

/// Find a driver, the captain's side (docs/rides_tasks.md O-26c).
enum DriverHireApi {
    private static let d = Api.driver

    static func open() async -> ApiResult<[HireJobDto]> { await Api.call("GET", d + "hire/open") }

    static func mine() async -> ApiResult<[HireJobDto]> { await Api.call("GET", d + "hire/mine") }

    static func accept(uuid: String) async -> ApiResult<HireJobDto> { await Api.call("POST", d + "hire/\(uuid)/accept") }

    /// `{to: arrived | started | completed}`
    static func move(uuid: String, body: JsonBody) async -> ApiResult<HireJobDto> { await Api.call("POST", d + "hire/\(uuid)/move", body: body) }

    static func release(uuid: String) async -> ApiResult<HireJobDto> { await Api.call("POST", d + "hire/\(uuid)/release") }
}

struct HireRiderDto: Decodable, Equatable {
    var name: String?
    var phone: String?
    var isVerified: Bool?
}

struct HireJobDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
    var pickup: PlaceDto?
    var startsAt: String?
    var endsAt: String?
    var unit: String?
    var quantity: Int?
    var transmission: String?
    var car: String?
    var note: String?
    var amountMinor: Int?
    var extraMinor: Int?
    var currency: CurrencyDto?
    var rider: HireRiderDto?
}

