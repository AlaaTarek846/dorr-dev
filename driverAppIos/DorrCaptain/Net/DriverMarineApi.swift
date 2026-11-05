import Foundation

// driverApp `net/DriverMarineApi.kt` — same calls, paths and bodies; its DTOs below, as in the Kotlin file.

/// The captain's boat (docs/rides_tasks.md O-25): sea trips and yacht hires, bookings, boarding, more time.
enum DriverMarineApi {
    private static let d = Api.driver

    static func boat() async -> ApiResult<MarineBoatInfoDto> { await Api.call("GET", d + "marine/boat") }

    static func listings() async -> ApiResult<[DriverMarineListingDto]> { await Api.call("GET", d + "marine/listings") }

    /// `{kind: trip|rental, title, description?, marina{lat,lng,address}, route_note?, amenities[], capacity?, depart_at?, duration_min?, seat_price_minor?, private_price_minor?, hour_price_minor?, min_hours?}`
    static func publish(body: JsonBody) async -> ApiResult<DriverMarineListingDto> { await Api.call("POST", d + "marine/listings", body: body) }

    static func listing(uuid: String) async -> ApiResult<DriverMarineListingDto> { await Api.call("GET", d + "marine/listings/\(uuid)") }

    /// `{to: paused|active|departed|completed|canceled}`
    static func move(uuid: String, body: JsonBody) async -> ApiResult<DriverMarineListingDto> {
        await Api.call("POST", d + "marine/listings/\(uuid)/move", body: body)
    }

    /// `{qr}`
    static func board(body: JsonBody) async -> ApiResult<MarineGuestBookingDto> { await Api.call("POST", d + "marine/board", body: body) }

    static func finish(uuid: String) async -> ApiResult<MarineGuestBookingDto> { await Api.call("POST", d + "marine/bookings/\(uuid)/finish") }

    /// `{approve: true|false}`
    static func `extension`(uuid: String, body: JsonBody) async -> ApiResult<MarineGuestBookingDto> {
        await Api.call("POST", d + "marine/bookings/\(uuid)/extension", body: body)
    }
}

struct MarineBoatInfoDto: Decodable, Equatable {
    var uuid: String?
    var type: String?
    var name: String?
    var seats: Int?
    var amenities: [String]?
}

struct DriverMarinePricesDto: Decodable, Equatable {
    var seatMinor: Int?
    var privateMinor: Int?
    var hourMinor: Int?
    var minHours: Int?
}

struct MarineGuestDto: Decodable, Equatable {
    var name: String?
    var phone: String?
}

struct MarineExtDto: Decodable, Equatable {
    var hours: Int?
    var amountMinor: Int?
    var status: String?
}

struct MarineGuestBookingDto: Decodable, Equatable {
    var uuid: String?
    var mode: String?
    var status: String?
    var guests: Int?
    var startsAt: String?
    var endsAt: String?
    var amountMinor: Int?
    var extensionMinor: Int?
    var `extension`: MarineExtDto?
    var guest: MarineGuestDto?
}

struct DriverMarineListingDto: Decodable, Equatable {
    var uuid: String?
    var kind: String?
    var status: String?
    var title: String?
    var description: String?
    var marina: PlaceDto?
    var routeNote: String?
    var amenities: [String]?
    var capacity: Int?
    var seatsLeft: Int?
    var departAt: String?
    var durationMin: Int?
    var prices: DriverMarinePricesDto?
    var currency: CurrencyDto?
    var bookings: [MarineGuestBookingDto]?
}

