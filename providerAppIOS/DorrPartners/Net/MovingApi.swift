import Foundation

// providerApp `net/MovingApi.kt` — furniture moving, the establishment's side (C-04, Modules/Moving/routes/api.php →
// `provider/v1/moving/*`): the home numbers, the requests that reached my branches (neighbourhoods only), offers, jobs and handing
// them to drivers, changes and new times, cancelling, the invoice and the tickets; and a driver's own jobs («مهامي»).
// `X-Establishment` picks the establishment.

enum MovingApi {
    private static let base = Api.provider + "moving/"

    /// A number the seat's permissions don't cover is null: its card is hidden.
    static func dashboard() async -> ApiResult<MovingDashboardDto> { await Api.call("GET", base + "dashboard") }

    static func requests(page: Int = 1) async -> ApiResult<[MovingRequestDto]> {
        await Api.call("GET", base + "requests", query: ["page": String(page)])
    }

    static func request(_ uuid: String) async -> ApiResult<MovingRequestDto> { await Api.call("GET", base + "requests/\(uuid)") }

    /// Refused with `moving_cannot_offer` (data.reason), `moving_offer_exists`, `moving_vehicle_busy`…; the server's text is shown as it is.
    static func sendOffer(_ uuid: String, _ body: JsonBody) async -> ApiResult<MovingOfferDto> {
        await Api.call("POST", base + "requests/\(uuid)/offers", body: body)
    }

    static func offers(status: String? = nil, page: Int = 1) async -> ApiResult<[MovingOfferDto]> {
        await Api.call("GET", base + "offers", query: ["status": status, "page": String(page)])
    }

    static func updateOffer(_ uuid: String, _ body: JsonBody) async -> ApiResult<MovingOfferDto> {
        await Api.call("PUT", base + "offers/\(uuid)", body: body)
    }

    /// Answers with an empty `data` (`apiDone`).
    static func withdrawOffer(_ uuid: String, offline: String, generic: String) async -> String? {
        await Api.done("POST", base + "offers/\(uuid)/withdraw", offline: offline, generic: generic)
    }

    static func approveOffer(_ uuid: String) async -> ApiResult<MovingOfferDto> { await Api.call("POST", base + "offers/\(uuid)/approve") }

    /// The running jobs (or one `status`), soonest first.
    static func jobs(status: String? = nil, page: Int = 1) async -> ApiResult<[MovingRequestDto]> {
        await Api.call("GET", base + "jobs", query: ["status": status, "page": String(page)])
    }

    /// Who a job can be given to, each with why they can't (`problem`) and how many jobs they hold (needs jobs.assign).
    static func drivers(branchId: Int? = nil) async -> ApiResult<[DriverOptionDto]> {
        await Api.call("GET", base + "drivers", query: ["branch_id": branchId.map(String.init)])
    }

    /// A job's changes, the pending ones first: price differences, waiting fees and new times.
    static func changes(_ uuid: String) async -> ApiResult<[ChangeOrderDto]> { await Api.call("GET", base + "jobs/\(uuid)/changes") }

    static func assign(_ uuid: String, _ body: AssignBody) async -> ApiResult<MovingRequestDto> {
        await Api.call("POST", base + "jobs/\(uuid)/assign", body: body.json)
    }

    static func myJobs() async -> ApiResult<[MovingRequestDto]> { await Api.call("GET", base + "my-jobs") }

    static func acceptJob(_ uuid: String) async -> ApiResult<MovingRequestDto> { await Api.call("POST", base + "jobs/\(uuid)/accept") }

    /// Answers with an empty `data` (`apiDone`).
    static func declineJob(_ uuid: String, offline: String, generic: String) async -> String? {
        await Api.done("POST", base + "jobs/\(uuid)/decline", offline: offline, generic: generic)
    }

    /// The driver's next step on moving day: set_out, arrived, loading, departed, unloading (the delivery is `deliver`).
    static func step(_ uuid: String, _ body: StepBody) async -> ApiResult<MovingRequestDto> {
        await Api.call("POST", base + "jobs/\(uuid)/step", body: body.json)
    }

    /// The truck's positions, oldest first (`points`, at most 100). Called by `MoveTrackingService` with the job's own establishment.
    /// The caller reads what became of it: a batch that failed stays to be sent again.
    static func locations(establishment: String?, _ uuid: String, points: [[String: Any]]) async -> Api.Sent {
        await Api.send("POST", base + "jobs/\(uuid)/locations", body: ["points": points],
                       headers: establishment.map { [Api.establishmentHeader: $0] } ?? [:])
    }

    static func inventory(_ uuid: String) async -> ApiResult<[InventoryItemDto]> { await Api.call("GET", base + "jobs/\(uuid)/inventory") }

    /// A numbered piece with its photo: `label`, `trip_no?`, `note?`, and `photo`. Only while arrived or loading.
    static func addItem(_ uuid: String, fields: [String: String], photo: FilePart) async -> ApiResult<InventoryItemDto> {
        await Api.upload(base + "jobs/\(uuid)/inventory", fields: fields, files: [photo.asField("photo")])
    }

    /// A piece at delivery, while unloading: its after-photo and / or a reservation. Needs at least one of them.
    static func deliverItem(_ uuid: String, item: String, fields: [String: String], photo: FilePart?) async -> ApiResult<InventoryItemDto> {
        await Api.upload(base + "jobs/\(uuid)/inventory/\(item)/delivered", fields: fields, files: photo.map { [$0.asField("photo")] } ?? [])
    }

    /// Delivery with the customer's code, or without it: `without_code` (refused / absent) and the `note`.
    static func deliver(_ uuid: String, _ body: DeliverBody) async -> ApiResult<MovingRequestDto> {
        await Api.call("POST", base + "jobs/\(uuid)/deliver", body: body.json)
    }

    /// Ends the job (a refund to the customer), or for load_differs / customer_absent / unsafe_place opens a case Dorr decides.
    /// Read through `apiDone`: the app needs only whether it went through, nil when it did, else the text to show.
    static func cancelDone(_ uuid: String, _ body: CancelBody, offline: String, generic: String) async -> String? {
        await Api.done("POST", base + "jobs/\(uuid)/cancel", body: body.json, offline: offline, generic: generic)
    }

    static func proposeChange(_ uuid: String, _ body: ChangeBody) async -> ApiResult<ChangeDto> {
        await Api.call("POST", base + "jobs/\(uuid)/changes", body: body.json)
    }

    static func reschedule(_ uuid: String, _ body: RescheduleBody) async -> ApiResult<ChangeDto> {
        await Api.call("POST", base + "jobs/\(uuid)/reschedule", body: body.json)
    }

    /// The answer to the customer's new time.
    static func respond(_ uuid: String, approve: Bool) async -> ApiResult<ChangeDto> {
        await Api.call("POST", base + "changes/\(uuid)/respond", body: ["approve": approve])
    }

    static func invoice(_ uuid: String) async -> ApiResult<InvoiceDto> { await Api.call("GET", base + "jobs/\(uuid)/invoice") }

    static func tickets(page: Int = 1) async -> ApiResult<[TicketDto]> {
        await Api.call("GET", base + "tickets", query: ["page": String(page)])
    }

    static func ticket(_ uuid: String) async -> ApiResult<TicketDto> { await Api.call("GET", base + "tickets/\(uuid)") }

    /// The establishment's answer; it does not close the ticket (the customer or Dorr does).
    static func reply(_ uuid: String, body: String) async -> ApiResult<TicketDto> {
        await Api.call("POST", base + "tickets/\(uuid)/reply", body: ["body": body])
    }
}

struct MovingDashboardDto: Decodable, Equatable {
    var newRequests: Int?
    var offersAwaitingApproval: Int?
    var jobsRunning: Int?
    var jobsUnassigned: Int?
    var jobsToday: Int?
    var myJobs: Int?
    var openTickets: Int?
}

/// `MovingRequestResource`. Until the establishment's own offer is accepted the server masks it: the neighbourhoods (`area`) only, no
/// exact address, no coordinates, no phone. In the feed it carries `my_offer` when the establishment answered it. A seat that may not
/// follow jobs (a driver alone) gets `money: null` and a 403 on the invoice.
struct MovingRequestDto: Decodable, Equatable, Identifiable {
    var uuid: String
    var status: String?
    /// single_piece / home / commercial
    var loadType: String?
    var description: String?
    /// A number on the wire, text in the Android DTO.
    var piecesCount: FlexString?
    var dimensions: String?
    var vehicleType: VehicleTypeDto?
    var vehicleTypeId: Int?
    var workersCount: Int?
    var extras: MovingExtrasDto?
    var pickup: MovingEndDto?
    var dropoff: MovingEndDto?
    var distanceKm: Double?
    var scheduledAt: String?
    var notes: String?
    var photos: [MediaDto]?
    var videos: [MediaDto]?
    var offersDeadlineAt: String?
    var publishedAt: String?
    var currencyCode: String?
    /// The job's branch: who may be given it (`moving/drivers?branch_id=`).
    var branchId: Int?
    var money: MovingMoneyDto?
    var vehicle: JobVehicleDto?
    var driverAccepted: Bool?
    var driver: JobDriverDto?
    var myOffer: MyOfferDto?

    var id: String { uuid }
}

struct MovingExtrasDto: Decodable, Equatable {
    var packing: Bool?
    var disassembly: Bool?
    var crane: Bool?
    var technician: Bool?
}

struct MovingEndDto: Decodable, Equatable {
    var lat: Double?
    var lng: Double?
    var address: String?
    var area: String?
    /// A number on the wire, text in the Android DTO.
    var floor: FlexString?
    var elevator: Bool?
    var entrance: String?
}

struct MediaDto: Decodable, Equatable, Identifiable {
    var id: Int
    var url: String?
}

struct MovingMoneyDto: Decodable, Equatable {
    var priceMinor: Int?
    var taxMinor: Int?
    var totalMinor: Int?
    var paymentStatus: String?
}

struct JobVehicleDto: Decodable, Equatable {
    var uuid: String?
    var plateLetters: String?
    var plateNumber: String?
    var make: String?
    var model: String?
    var color: String?
}

struct JobDriverDto: Decodable, Equatable {
    var uuid: String?
    var name: String?
    var avatar: String?
    var accepted: Bool?
}

struct MyOfferDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
    var priceMinor: Int?
}

/// `MovingOfferResource`: status is awaiting_approval / sent / withdrawn / accepted / declined / expired.
struct MovingOfferDto: Decodable, Equatable, Identifiable {
    var uuid: String
    var requestUuid: String?
    var status: String?
    var priceMinor: Int?
    var currencyCode: String?
    var workersCount: Int?
    var tripsCount: Int?
    /// packing / disassembly / crane / technician / tax → true; an object, or `[]` when nothing was ticked.
    var includes: JSONAny?
    var technicianNote: String?
    var arrivalAt: String?
    var terms: String?
    var afterService: String?
    var validUntil: String?
    var branch: String?
    var vehicle: OfferVehicleDto?

    var id: String { uuid }

    /// The ticked parts of `includes`.
    func included() -> Set<String> {
        guard case .object(let map)? = includes else { return [] }
        return Set(map.filter { $0.value.bool == true }.keys)
    }
}

struct OfferVehicleDto: Decodable, Equatable {
    var type: VehicleTypeDto?
    var photos: [String]?
}

// The bodies below are plain `@Body` classes on Android: a value not given stays out of the request.

struct AssignBody {
    var driverUuid: String
    var vehicleUuid: String?
    var reason: String?

    var json: JsonBody { withoutNulls(["driver_uuid": driverUuid, "vehicle_uuid": vehicleUuid, "reason": reason]) }
}

struct CancelBody {
    var reason: String
    var details: String?

    var json: JsonBody { withoutNulls(["reason": reason, "details": details]) }
}

struct ChangeBody {
    var priceDeltaMinor: Int
    var workersCount: Int?
    var tripsCount: Int?
    var reason: String

    var json: JsonBody {
        withoutNulls(["price_delta_minor": priceDeltaMinor, "workers_count": workersCount, "trips_count": tripsCount, "reason": reason])
    }
}

struct RescheduleBody {
    var scheduledAt: String
    var reason: String

    var json: JsonBody { ["scheduled_at": scheduledAt, "reason": reason] }
}

struct ChangeDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
}

/// One change of a job. kind: change / waiting / reschedule. status: pending / awaiting_payment / approved / rejected.
struct ChangeOrderDto: Decodable, Equatable, Identifiable {
    var uuid: String
    var kind: String?
    /// `user` (the customer) or `provider` (the establishment).
    var proposedBy: String?
    var status: String?
    var priceDeltaMinor: Int?
    var workersCount: Int?
    var tripsCount: Int?
    var newScheduledAt: String?
    /// Free text; for a waiting fee `waiting:{minutes}`.
    var reason: String?
    var createdAt: String?

    var id: String { uuid }
}

/// `GET moving/drivers`. `problem`: driver_licence / driver_inactive / not_a_driver, or null when they can be chosen.
struct DriverOptionDto: Decodable, Equatable, Identifiable {
    var uuid: String
    var name: String?
    var phone: String?
    var avatar: String?
    var problem: String?
    var busyJobs: Int?

    var id: String { uuid }
}

struct StepBody {
    var step: String
    var lat: Double?
    var lng: Double?
    /// The other proof when the location is unavailable at the door.
    var note: String?

    var json: JsonBody { withoutNulls(["step": step, "lat": lat, "lng": lng, "note": note]) }
}

/// `MovingInventoryResource`: a numbered piece, with its photo before loading and after delivery.
struct InventoryItemDto: Decodable, Equatable, Identifiable {
    var uuid: String
    var number: Int?
    var label: String?
    var tripNo: Int?
    var conditionNote: String?
    var deliveredNote: String?
    var before: String?
    var after: String?

    var id: String { uuid }
}

struct DeliverBody {
    var code: String?
    var recipientName: String?
    /// refused / absent, when delivered without the code.
    var withoutCode: String?
    var note: String?
    var lat: Double?
    var lng: Double?

    var json: JsonBody {
        withoutNulls(["code": code, "recipient_name": recipientName, "without_code": withoutCode, "note": note, "lat": lat, "lng": lng])
    }
}

struct InvoiceDto: Decodable, Equatable {
    var number: String?
    var issuedAt: String?
    var currencyCode: String?
    var lines: [InvoiceLineDto]?
    var priceMinor: Int?
    var taxMinor: Int?
    var totalMinor: Int?
    var refundedMinor: Int?
    var commissionMinor: Int?
    var providerNetMinor: Int?
}

/// `moving` (the agreed price), `change` or `waiting` (an approved difference, with its reason).
struct InvoiceLineDto: Decodable, Equatable {
    var key: String?
    var amountMinor: Int?
    var reason: String?
}

/// `MovingTicketResource`: status new / awaiting_company / company_replied / escalated / resolved / unresolved.
struct TicketDto: Decodable, Equatable, Identifiable {
    var uuid: String
    var requestUuid: String?
    var type: String?
    var subject: String?
    var status: String?
    var escalationReason: String?
    var closingReason: String?
    var createdAt: String?
    var messages: [TicketMessageDto]?

    var id: String { uuid }
}

struct TicketMessageDto: Decodable, Equatable {
    /// user / provider / admin
    var author: String?
    var body: String?
    var at: String?
}
