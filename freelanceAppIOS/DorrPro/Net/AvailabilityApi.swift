import Foundation

// freelanceApp `net/AvailabilityApi.kt`.

/// How the freelancer can be reached (FL-106), under `/api/freelance/v1/profile`: the pause, the guide price, the weekly hours, time off.
enum AvailabilityApi {
    private static let base = Api.freelance + "profile/"

    static func show() async -> ApiResult<AvailabilityDto> { await Api.call("GET", base + "availability") }

    static func update(_ body: AvailabilityRequest) async -> ApiResult<AvailabilityDto> { await Api.call("PUT", base + "availability", body: body.json) }

    static func addTimeOff(_ body: TimeOffRequest) async -> ApiResult<TimeOffDto> {
        var json: JsonBody = ["starts_on": body.startsOn, "ends_on": body.endsOn]
        if let reason = body.reason { json["reason"] = reason }
        return await Api.call("POST", base + "time-off", body: json)
    }

    static func deleteTimeOff(_ id: Int, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base + "time-off/\(id)", offline: offline, generic: generic)
    }

    /// The wallet's currency code (`ApiClient.wallet.balance().currencyCode` on Android) for the price labels — read here so
    /// the page does not wait for the wallet port.
    static func walletCurrency() async -> String? {
        (await Api.call("GET", Api.freelance + "wallet") as ApiResult<AvailabilityWalletDto>).value?.currencyCode
    }
}

/// The one field of `WalletBalanceDto` the availability page reads.
struct AvailabilityWalletDto: Decodable {
    var currencyCode: String?
}

struct AvailabilityDto: Decodable, Equatable {
    var acceptingRequests: Bool?
    var pausedUntil: String?
    var pauseReason: String?
    var canReceiveOffers: Bool?
    var onTimeOff: Bool?
    var rate: RateDto?
    var weeklyHours: [WeeklyHoursDto]?
    var timeOff: [TimeOffDto]?
}

struct RateDto: Decodable, Equatable {
    var type: String?
    var minMinor: Int64?
    var maxMinor: Int64?
}

/// One working day: `day` is 0 = Sunday … 6 = Saturday, `from` / `to` are `HH:MM`.
struct WeeklyHoursDto: Decodable, Equatable {
    var day: Int?
    var from: String?
    var to: String?
}

struct TimeOffDto: Decodable, Equatable {
    var id: Int?
    var startsOn: String?
    var endsOn: String?
    var reason: String?
}

/// Everything the screen saves in one go; a field left out keeps its value on the server.
struct AvailabilityRequest {
    var acceptingRequests: Bool
    var pausedUntil: String?
    var pauseReason: String?
    var rateType: String
    var rateMinMinor: Int64?
    var rateMaxMinor: Int64?
    var weeklyHours: [WeeklyHoursDto]

    /// As Gson writes it: a nil field is left out.
    var json: JsonBody {
        var body: JsonBody = [
            "accepting_requests": acceptingRequests,
            "rate_type": rateType,
            "weekly_hours": weeklyHours.map { ["day": $0.day ?? 0, "from": $0.from ?? "", "to": $0.to ?? ""] as [String: Any] },
        ]
        if let pausedUntil { body["paused_until"] = pausedUntil }
        if let pauseReason { body["pause_reason"] = pauseReason }
        if let rateMinMinor { body["rate_min_minor"] = rateMinMinor }
        if let rateMaxMinor { body["rate_max_minor"] = rateMaxMinor }
        return body
    }
}

struct TimeOffRequest {
    var startsOn: String
    var endsOn: String
    var reason: String?
}
