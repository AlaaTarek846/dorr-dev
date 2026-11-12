import Foundation

// driverApp `net/DriverBusApi.kt` — same calls, paths and bodies; its DTOs below, as in the Kotlin file.

/// The captain's buses (docs/rides_tasks.md O-20, O-21, O-24).
enum DriverBusApi {
    private static let d = Api.driver

    /// My bus and a QR label for each of its seats.
    static func vehicle() async -> ApiResult<BusVehicleDto> { await Api.call("GET", d + "bus/vehicle") }

    static func trips() async -> ApiResult<[DriverBusTripDto]> { await Api.call("GET", d + "bus/trips") }

    /// `{origin, destination, depart_at, return_at?, price_outbound_minor, price_return_minor?, price_round_minor?, seats_total?, note?}`
    static func publish(body: JsonBody) async -> ApiResult<DriverBusTripDto> { await Api.call("POST", d + "bus/trips", body: body) }

    static func trip(uuid: String) async -> ApiResult<DriverBusTripDto> { await Api.call("GET", d + "bus/trips/\(uuid)") }

    static func update(uuid: String, body: JsonBody) async -> ApiResult<DriverBusTripDto> { await Api.call("PUT", d + "bus/trips/\(uuid)", body: body) }

    static func cancel(uuid: String) async -> ApiResult<DriverBusTripDto> { await Api.call("POST", d + "bus/trips/\(uuid)/cancel") }

    /// `{to: departed | returning | completed}`
    static func move(uuid: String, body: JsonBody) async -> ApiResult<DriverBusTripDto> { await Api.call("POST", d + "bus/trips/\(uuid)/move", body: body) }

    /// `{qr}` or `{leg, seat}`
    static func board(uuid: String, body: JsonBody) async -> ApiResult<BusBoardedDto> { await Api.call("POST", d + "bus/trips/\(uuid)/board", body: body) }

    /// O-68: the chat with one passenger (their ticket) and in-app calls.
    static func messages(booking: String) async -> ApiResult<ChatDto> { await Api.call("GET", d + "bus/bookings/\(booking)/messages") }

    static func send(booking: String, body: JsonBody) async -> ApiResult<MessageDto> { await Api.call("POST", d + "bus/bookings/\(booking)/messages", body: body) }

    static func startCall(booking: String) async -> ApiResult<TripCallStartDto> { await Api.call("POST", d + "bus/bookings/\(booking)/calls") }

    static func acceptCall(booking: String, call: String) async -> ApiResult<TripCallStartDto> {
        await Api.call("POST", d + "bus/bookings/\(booking)/calls/\(call)/accept")
    }

    static func endCall(booking: String, call: String) async -> ApiResult<TripCallDto> {
        await Api.call("POST", d + "bus/bookings/\(booking)/calls/\(call)/end")
    }
}

struct BusSeatLabelDto: Decodable, Equatable {
    var seat: Int?
    var qr: String?
}

struct BusVehicleDto: Decodable, Equatable {
    var uuid: String?
    var name: String?
    var plate: String?
    var seats: Int?
    var seatLabels: [BusSeatLabelDto]?
}

struct BusTakenLegsDto: Decodable, Equatable {
    var outbound: [Int]?
    var returnLeg: [Int]?

    enum CodingKeys: String, CodingKey {
        case outbound
        case returnLeg = "return"
    }
}

struct BusFreeLegsDto: Decodable, Equatable {
    var outbound: Int?
    var returnLeg: Int?

    enum CodingKeys: String, CodingKey {
        case outbound
        case returnLeg = "return"
    }
}

struct BusPassengerSeatDto: Decodable, Equatable {
    var leg: String?
    var seat: Int?
    var boarded: Bool?
}

struct BusPassengerDto: Decodable, Equatable {
    var bookingUuid: String?
    var name: String?
    var isVerified: Bool?
    var phone: String?
    var tripType: String?
    var seats: [BusPassengerSeatDto]?
    var amountMinor: Int?
    /// O-68: messages from them not read yet, and a call going on.
    var unread: Int?
    var call: TripCallDto?
}

struct BusTripPricesDto: Decodable, Equatable {
    var outboundMinor: Int?
    var returnMinor: Int?
    var roundMinor: Int?
}

struct DriverBusTripDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
    var origin: PlaceDto?
    var destination: PlaceDto?
    var departAt: String?
    var returnAt: String?
    /// O-68: from when «start» / «start the way back» open (a little before their time).
    var canDepartAt: String?
    var canReturnAt: String?
    var prices: BusTripPricesDto?
    var currency: CurrencyDto?
    var seatsTotal: Int?
    var seatsPerRow: Int?
    var free: BusFreeLegsDto?
    var taken: BusTakenLegsDto?
    var note: String?
    var passengers: [BusPassengerDto]?
}

struct BusBoardedDto: Decodable, Equatable {
    var leg: String?
    var seat: Int?
    var name: String?
    var passengers: [BusPassengerDto]?
}

