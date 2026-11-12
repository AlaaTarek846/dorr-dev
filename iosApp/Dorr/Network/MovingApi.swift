import Foundation

// androidApp `network/MovingApi.kt` — furniture moving, the customer's side (Modules/Moving, docs/moving_business.md):
// one draft on the server, publishing it, the offers three at a time, accepting one on the shared payment screen,
// following the job, and what comes after (report, rating, changes, cancelling, invoice, tickets). Money is always in
// minor units; the server prices everything.
//
// DTOs: CodingKeys = @SerializedName, Kotlin defaults kept through `value(_:_:)` (the wallet's way), read with the
// plain decoder — an accepted offer answers with a `CheckoutDto`, which has its own snake_case keys.

// MARK: - Refusals

/// A refused moving call: the server's own words, its code, and what a publish still needs (`data.missing`, which
/// `apiFailure` has no room for).
struct MovingRefusal {
    let message: String?
    let code: String?
    let http: Int?
    var missing: [String] = []
}

extension Error {
    /// androidApp `Throwable.movingRefusal()`.
    var movingRefusal: MovingRefusal {
        let failure = apiFailure
        guard let apiError = self as? ApiError, case .http(_, let body) = apiError else {
            return MovingRefusal(message: nil, code: nil, http: nil)
        }
        let root = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any]
        let missing = ((root?["data"] as? [String: Any])?["missing"] as? [Any])?.compactMap { $0 as? String } ?? []
        return MovingRefusal(message: failure.message, code: failure.errorCode, http: failure.httpStatus, missing: missing)
    }
}

// MARK: - DTOs

struct MovingVehicleTypeDto: Decodable, Equatable {
    var id: Int
    var code: String?
    var name: String?
    var description: String?
    var image: String?

    enum CodingKeys: String, CodingKey { case id, code, name, description, image }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        code = c.value(.code, nil)
        name = c.value(.name, nil)
        description = c.value(.description, nil)
        image = c.value(.image, nil)
    }
}

struct MovingOptionsDto: Decodable, Equatable {
    /// False when the service is off in this country: the unavailable card shows, and no new request starts.
    var available: Bool
    var loadTypes: [String]
    var vehicleTypes: [MovingVehicleTypeDto]
    /// Largest photo or video the country allows, in megabytes; nil when it set none.
    var mediaMaxMb: Double?

    enum CodingKeys: String, CodingKey {
        case available
        case loadTypes = "load_types", vehicleTypes = "vehicle_types", mediaMaxMb = "media_max_mb"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        available = c.value(.available, true)
        loadTypes = c.value(.loadTypes, [])
        vehicleTypes = c.value(.vehicleTypes, [])
        mediaMaxMb = c.value(.mediaMaxMb, nil)
    }
}

/// `GET moving/route`: the road distance and time, and the line to draw (nil when `estimated`).
struct MovingRouteDto: Decodable, Equatable {
    var distanceM: Int?
    var durationS: Int?
    var polyline: String?
    var estimated: Bool

    enum CodingKeys: String, CodingKey {
        case polyline, estimated
        case distanceM = "distance_m", durationS = "duration_s"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        distanceM = c.value(.distanceM, nil)
        durationS = c.value(.durationS, nil)
        polyline = c.value(.polyline, nil)
        estimated = c.value(.estimated, true)
    }
}

struct MovingMediaDto: Decodable, Equatable, Identifiable {
    var id: Int
    var url: String?
    var collection: String?
    /// A voice note: its length and bars (0–100).
    var durationMs: Int64?
    var waveform: [Int]

    enum CodingKeys: String, CodingKey {
        case id, url, collection, waveform
        case durationMs = "duration_ms"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        url = c.value(.url, nil)
        collection = c.value(.collection, nil)
        durationMs = c.value(.durationMs, nil)
        waveform = c.value(.waveform, [])
    }
}

struct MovingExtrasDto: Decodable, Equatable {
    var packing: Bool
    var disassembly: Bool
    var crane: Bool
    var technician: Bool

    enum CodingKeys: String, CodingKey { case packing, disassembly, crane, technician }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        packing = c.value(.packing, false)
        disassembly = c.value(.disassembly, false)
        crane = c.value(.crane, false)
        technician = c.value(.technician, false)
    }
}

/// One end of the move.
struct MovingEndDto: Decodable, Equatable {
    var lat: Double?
    var lng: Double?
    var address: String?
    var area: String?
    var floor: Int?
    var elevator: Bool?
    var entrance: String?

    enum CodingKeys: String, CodingKey { case lat, lng, address, area, floor, elevator, entrance }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        lat = c.value(.lat, nil)
        lng = c.value(.lng, nil)
        address = c.value(.address, nil)
        area = c.value(.area, nil)
        floor = c.value(.floor, nil)
        elevator = c.value(.elevator, nil)
        entrance = c.value(.entrance, nil)
    }
}

struct MovingMoneyDto: Decodable, Equatable {
    var priceMinor: Int?
    var taxMinor: Int?
    var totalMinor: Int?
    var paymentStatus: String?

    enum CodingKeys: String, CodingKey {
        case priceMinor = "price_minor", taxMinor = "tax_minor", totalMinor = "total_minor", paymentStatus = "payment_status"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        priceMinor = c.value(.priceMinor, nil)
        taxMinor = c.value(.taxMinor, nil)
        totalMinor = c.value(.totalMinor, nil)
        paymentStatus = c.value(.paymentStatus, nil)
    }
}

struct MovingEstablishmentDto: Decodable, Equatable {
    var uuid: String?
    var name: String?
    var type: String?
    var logo: String?

    enum CodingKeys: String, CodingKey { case uuid, name, type, logo }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        uuid = c.value(.uuid, nil)
        name = c.value(.name, nil)
        type = c.value(.type, nil)
        logo = c.value(.logo, nil)
    }
}

struct MovingVehicleDto: Decodable, Equatable {
    var uuid: String?
    var plateLetters: String?
    var plateNumber: String?
    var make: String?
    var model: String?
    var color: String?

    enum CodingKeys: String, CodingKey {
        case uuid, make, model, color
        case plateLetters = "plate_letters", plateNumber = "plate_number"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        uuid = c.value(.uuid, nil)
        plateLetters = c.value(.plateLetters, nil)
        plateNumber = c.value(.plateNumber, nil)
        make = c.value(.make, nil)
        model = c.value(.model, nil)
        color = c.value(.color, nil)
    }
}

struct MovingDriverDto: Decodable, Equatable {
    var uuid: String?
    var name: String?
    var avatar: String?
    var accepted: Bool

    enum CodingKeys: String, CodingKey { case uuid, name, avatar, accepted }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        uuid = c.value(.uuid, nil)
        name = c.value(.name, nil)
        avatar = c.value(.avatar, nil)
        accepted = c.value(.accepted, false)
    }
}

struct MovingRequestDto: Decodable, Equatable, Identifiable {
    var uuid: String
    var status: String
    var loadType: String?
    var description: String?
    var piecesCount: Int?
    var dimensions: String?
    var vehicleType: MovingVehicleTypeDto?
    var vehicleTypeId: Int?
    /// Every vehicle type the customer accepts, the first choice first.
    var vehicleTypeIds: [Int]
    var vehicleTypes: [MovingVehicleTypeDto]
    var workersCount: Int?
    var extras: MovingExtrasDto?
    var pickup: MovingEndDto?
    var dropoff: MovingEndDto?
    var distanceKm: Double?
    var scheduledAt: String?
    var notes: String?
    var photos: [MovingMediaDto]
    var videos: [MovingMediaDto]
    /// The customer's voice note about the load, heard by the companies too.
    var voice: MovingMediaDto?
    var offersDeadlineAt: String?
    var publishedAt: String?
    var currencyCode: String?
    /// How many decimals the amounts of this request have (offers and invoices take them from here).
    var currencyDecimals: Int
    var money: MovingMoneyDto?
    var establishment: MovingEstablishmentDto?
    var vehicle: MovingVehicleDto?
    var driverAccepted: Bool
    var driver: MovingDriverDto?

    var id: String { uuid }

    enum CodingKeys: String, CodingKey {
        case uuid, status, description, dimensions, extras, pickup, dropoff, notes, photos, videos, voice, money, establishment, vehicle, driver
        case loadType = "load_type", piecesCount = "pieces_count", vehicleType = "vehicle_type", vehicleTypeId = "vehicle_type_id"
        case vehicleTypeIds = "vehicle_type_ids", vehicleTypes = "vehicle_types"
        case workersCount = "workers_count", distanceKm = "distance_km", scheduledAt = "scheduled_at"
        case offersDeadlineAt = "offers_deadline_at", publishedAt = "published_at", currencyCode = "currency_code"
        case currencyDecimals = "currency_decimals", driverAccepted = "driver_accepted"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        uuid = c.value(.uuid, "")
        status = c.value(.status, "draft")
        loadType = c.value(.loadType, nil)
        description = c.value(.description, nil)
        piecesCount = c.value(.piecesCount, nil)
        dimensions = c.value(.dimensions, nil)
        vehicleType = c.value(.vehicleType, nil)
        vehicleTypeId = c.value(.vehicleTypeId, nil)
        vehicleTypeIds = c.value(.vehicleTypeIds, [])
        vehicleTypes = c.value(.vehicleTypes, [])
        workersCount = c.value(.workersCount, nil)
        extras = c.value(.extras, nil)
        pickup = c.value(.pickup, nil)
        dropoff = c.value(.dropoff, nil)
        distanceKm = c.value(.distanceKm, nil)
        scheduledAt = c.value(.scheduledAt, nil)
        notes = c.value(.notes, nil)
        photos = c.value(.photos, [])
        videos = c.value(.videos, [])
        voice = c.value(.voice, nil)
        offersDeadlineAt = c.value(.offersDeadlineAt, nil)
        publishedAt = c.value(.publishedAt, nil)
        currencyCode = c.value(.currencyCode, nil)
        currencyDecimals = c.value(.currencyDecimals, 2)
        money = c.value(.money, nil)
        establishment = c.value(.establishment, nil)
        vehicle = c.value(.vehicle, nil)
        driverAccepted = c.value(.driverAccepted, false)
        driver = c.value(.driver, nil)
    }
}

struct MovingDriverLocationDto: Decodable, Equatable {
    var lat: Double?
    var lng: Double?
    var at: String?

    enum CodingKeys: String, CodingKey { case lat, lng, at }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        lat = c.value(.lat, nil)
        lng = c.value(.lng, nil)
        at = c.value(.at, nil)
    }
}

/// A numbered piece with its photos before loading and after delivery.
struct MovingInventoryItemDto: Decodable, Equatable, Identifiable {
    var uuid: String
    var number: Int?
    var label: String?
    var tripNo: Int?
    var conditionNote: String?
    var deliveredNote: String?
    var before: String?
    var after: String?

    var id: String { uuid }

    enum CodingKeys: String, CodingKey {
        case uuid, number, label, before, after
        case tripNo = "trip_no", conditionNote = "condition_note", deliveredNote = "delivered_note"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        uuid = c.value(.uuid, "")
        number = c.value(.number, nil)
        label = c.value(.label, nil)
        tripNo = c.value(.tripNo, nil)
        conditionNote = c.value(.conditionNote, nil)
        deliveredNote = c.value(.deliveredNote, nil)
        before = c.value(.before, nil)
        after = c.value(.after, nil)
    }
}

struct MovingCaseDto: Decodable, Equatable {
    var uuid: String
    /// report | exception
    var kind: String?
    var status: String?
    var decision: String?
    var awardMinor: Int?

    enum CodingKeys: String, CodingKey { case uuid, kind, status, decision, awardMinor = "award_minor" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        uuid = c.value(.uuid, "")
        kind = c.value(.kind, nil)
        status = c.value(.status, nil)
        decision = c.value(.decision, nil)
        awardMinor = c.value(.awardMinor, nil)
    }
}

/// `GET requests/{uuid}`: the hub of a request.
struct MovingShowDto: Decodable, Equatable {
    var request: MovingRequestDto
    var offersCount: Int
    /// The customer has rated this move: no rating form.
    var rated: Bool
    var missing: [String]
    var deliveryCode: String?
    var deliveryQr: String?
    var cancelFeeMinor: Int?
    var driverLocation: MovingDriverLocationDto?
    var objectionUntil: String?
    var inventory: [MovingInventoryItemDto]
    var cases: [MovingCaseDto]
    /// What the rating card asks the customer to score the establishment on (as rides ask about the captain).
    var ratingCriteria: [MovingCriterionDto]

    enum CodingKeys: String, CodingKey {
        case request, rated, missing, inventory, cases
        case ratingCriteria = "rating_criteria"
        case offersCount = "offers_count", deliveryCode = "delivery_code", deliveryQr = "delivery_qr"
        case cancelFeeMinor = "cancel_fee_minor", driverLocation = "driver_location", objectionUntil = "objection_until"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        // The request is the page itself: a broken one fails the read instead of showing an empty draft.
        request = try c.decode(MovingRequestDto.self, forKey: .request)
        offersCount = c.value(.offersCount, 0)
        rated = c.value(.rated, false)
        missing = c.value(.missing, [])
        deliveryCode = c.value(.deliveryCode, nil)
        deliveryQr = c.value(.deliveryQr, nil)
        cancelFeeMinor = c.value(.cancelFeeMinor, nil)
        driverLocation = c.value(.driverLocation, nil)
        objectionUntil = c.value(.objectionUntil, nil)
        inventory = c.value(.inventory, [])
        cases = c.value(.cases, [])
        ratingCriteria = c.value(.ratingCriteria, [])
    }
}

/// A rating criterion: its key and label, and — with an establishment — its average and how many scored it.
struct MovingCriterionDto: Decodable, Equatable {
    var key: String
    var label: String?
    var average: Double?
    var count: Int

    enum CodingKeys: String, CodingKey { case key, label, average, count }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        key = c.value(.key, "")
        label = c.value(.label, nil)
        average = c.value(.average, nil)
        count = c.value(.count, 0)
    }
}

/// One of the company's branches on an offer: the one that answered first, then the nearest to the pickup point.
struct MovingOfferBranchDto: Decodable, Equatable {
    var name: String?
    var address: String?
    var lat: Double?
    var lng: Double?
    var isMain: Bool
    var answering: Bool
    var distanceKm: Double?

    enum CodingKeys: String, CodingKey {
        case name, address, lat, lng, answering
        case isMain = "is_main", distanceKm = "distance_km"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        name = c.value(.name, nil)
        address = c.value(.address, nil)
        lat = c.value(.lat, nil)
        lng = c.value(.lng, nil)
        isMain = c.value(.isMain, false)
        answering = c.value(.answering, false)
        distanceKm = c.value(.distanceKm, nil)
    }
}

struct MovingOfferEstablishmentDto: Decodable, Equatable {
    var uuid: String?
    var name: String?
    var type: String?
    var logo: String?
    var average: Double?
    var count: Int
    var jobs: Int
    /// Its average on each criterion customers score (nil average = nobody scored it yet).
    var criteria: [MovingCriterionDto]

    enum CodingKeys: String, CodingKey { case uuid, name, type, logo, average, count, jobs, criteria }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        uuid = c.value(.uuid, nil)
        name = c.value(.name, nil)
        type = c.value(.type, nil)
        logo = c.value(.logo, nil)
        average = c.value(.average, nil)
        count = c.value(.count, 0)
        jobs = c.value(.jobs, 0)
        criteria = c.value(.criteria, [])
    }
}

struct MovingOfferVehicleDto: Decodable, Equatable {
    var type: MovingVehicleTypeDto?
    var photos: [String]

    enum CodingKeys: String, CodingKey { case type, photos }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        type = c.value(.type, nil)
        photos = c.value(.photos, [])
    }
}

struct MovingOfferDto: Decodable, Equatable, Identifiable {
    var uuid: String
    var requestUuid: String?
    var status: String?
    var priceMinor: Int
    var currencyCode: String?
    var workersCount: Int?
    var tripsCount: Int?
    /// A map of what the price holds: `{packing: true, crane: false, …}` (an empty one may come as `[]`).
    var includes: JSONAny?
    var technicianNote: String?
    var arrivalAt: String?
    var terms: String?
    var afterService: String?
    var validUntil: String?
    var establishment: MovingOfferEstablishmentDto?
    var branch: String?
    /// Where the company is: its branches with their address and distance from the pickup point.
    var branches: [MovingOfferBranchDto]
    var vehicle: MovingOfferVehicleDto?
    /// Why it sits where it does: cheapest, earliest, vehicle_fit.
    var reasons: [String]

    var id: String { uuid }

    enum CodingKeys: String, CodingKey {
        case uuid, status, includes, terms, establishment, branch, branches, vehicle, reasons
        case requestUuid = "request_uuid", priceMinor = "price_minor", currencyCode = "currency_code"
        case workersCount = "workers_count", tripsCount = "trips_count", technicianNote = "technician_note"
        case arrivalAt = "arrival_at", afterService = "after_service", validUntil = "valid_until"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        uuid = c.value(.uuid, "")
        requestUuid = c.value(.requestUuid, nil)
        status = c.value(.status, nil)
        priceMinor = c.value(.priceMinor, 0)
        currencyCode = c.value(.currencyCode, nil)
        workersCount = c.value(.workersCount, nil)
        tripsCount = c.value(.tripsCount, nil)
        includes = c.value(.includes, nil)
        technicianNote = c.value(.technicianNote, nil)
        arrivalAt = c.value(.arrivalAt, nil)
        terms = c.value(.terms, nil)
        afterService = c.value(.afterService, nil)
        validUntil = c.value(.validUntil, nil)
        establishment = c.value(.establishment, nil)
        branch = c.value(.branch, nil)
        branches = c.value(.branches, [])
        vehicle = c.value(.vehicle, nil)
        reasons = c.value(.reasons, [])
    }
}

struct MovingOffersPageDto: Decodable, Equatable {
    var total: Int
    var page: Int
    var hasMore: Bool
    var offers: [MovingOfferDto]

    enum CodingKeys: String, CodingKey { case total, page, offers, hasMore = "has_more" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        total = c.value(.total, 0)
        page = c.value(.page, 1)
        hasMore = c.value(.hasMore, false)
        offers = c.value(.offers, [])
    }
}

/// The fee table in force for this request; a nil means the country set none, so no fee.
struct MovingCancellationDto: Decodable, Equatable {
    var nearHours: Double?
    var earlyBp: Double?
    var nearBp: Double?
    var onWayBp: Double?
    var arrivedBp: Double?
    var reasons: [String]

    enum CodingKeys: String, CodingKey {
        case reasons
        case nearHours = "near_hours", earlyBp = "early_bp", nearBp = "near_bp", onWayBp = "on_way_bp", arrivedBp = "arrived_bp"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        nearHours = c.value(.nearHours, nil)
        earlyBp = c.value(.earlyBp, nil)
        nearBp = c.value(.nearBp, nil)
        onWayBp = c.value(.onWayBp, nil)
        arrivedBp = c.value(.arrivedBp, nil)
        reasons = c.value(.reasons, [])
    }
}

struct MovingChangeDto: Decodable, Equatable, Identifiable {
    var uuid: String
    /// change | waiting | reschedule
    var kind: String?
    /// provider | user
    var proposedBy: String?
    /// pending | awaiting_payment | approved | rejected
    var status: String?
    var priceDeltaMinor: Int
    var extraTotalMinor: Int?
    var workersCount: Int?
    var tripsCount: Int?
    var newScheduledAt: String?
    /// Free text; a waiting fee says `waiting:{minutes}`.
    var reason: String?
    var before: JSONAny?
    var after: JSONAny?
    var createdAt: String?

    var id: String { uuid }

    enum CodingKeys: String, CodingKey {
        case uuid, kind, status, reason, before, after
        case proposedBy = "proposed_by", priceDeltaMinor = "price_delta_minor", extraTotalMinor = "extra_total_minor"
        case workersCount = "workers_count", tripsCount = "trips_count", newScheduledAt = "new_scheduled_at", createdAt = "created_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        uuid = c.value(.uuid, "")
        kind = c.value(.kind, nil)
        proposedBy = c.value(.proposedBy, nil)
        status = c.value(.status, nil)
        priceDeltaMinor = c.value(.priceDeltaMinor, 0)
        extraTotalMinor = c.value(.extraTotalMinor, nil)
        workersCount = c.value(.workersCount, nil)
        tripsCount = c.value(.tripsCount, nil)
        newScheduledAt = c.value(.newScheduledAt, nil)
        reason = c.value(.reason, nil)
        before = c.value(.before, nil)
        after = c.value(.after, nil)
        createdAt = c.value(.createdAt, nil)
    }
}

struct MovingRespondDto: Decodable {
    var change: MovingChangeDto?
    var checkout: CheckoutDto?

    enum CodingKeys: String, CodingKey { case change, checkout }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        change = c.value(.change, nil)
        checkout = c.value(.checkout, nil)
    }
}

struct MovingInvoiceEstablishmentDto: Decodable, Equatable {
    var name: String?
    var taxNumber: String?

    enum CodingKeys: String, CodingKey { case name, taxNumber = "tax_number" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        name = c.value(.name, nil)
        taxNumber = c.value(.taxNumber, nil)
    }
}

struct MovingInvoiceLineDto: Decodable, Equatable {
    /// moving | waiting | change
    var key: String?
    var amountMinor: Int
    var reason: String?

    enum CodingKeys: String, CodingKey { case key, reason, amountMinor = "amount_minor" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        key = c.value(.key, nil)
        amountMinor = c.value(.amountMinor, 0)
        reason = c.value(.reason, nil)
    }
}

struct MovingInvoiceDto: Decodable, Equatable {
    var number: String?
    var issuedAt: String?
    var currencyCode: String?
    var establishment: MovingInvoiceEstablishmentDto?
    var includes: JSONAny?
    var workersCount: Int?
    var tripsCount: Int?
    var lines: [MovingInvoiceLineDto]
    var priceMinor: Int
    var taxMinor: Int
    var totalMinor: Int
    var refundedMinor: Int

    enum CodingKeys: String, CodingKey {
        case number, establishment, includes, lines
        case issuedAt = "issued_at", currencyCode = "currency_code", workersCount = "workers_count", tripsCount = "trips_count"
        case priceMinor = "price_minor", taxMinor = "tax_minor", totalMinor = "total_minor", refundedMinor = "refunded_minor"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        number = c.value(.number, nil)
        issuedAt = c.value(.issuedAt, nil)
        currencyCode = c.value(.currencyCode, nil)
        establishment = c.value(.establishment, nil)
        includes = c.value(.includes, nil)
        workersCount = c.value(.workersCount, nil)
        tripsCount = c.value(.tripsCount, nil)
        lines = c.value(.lines, [])
        priceMinor = c.value(.priceMinor, 0)
        taxMinor = c.value(.taxMinor, 0)
        totalMinor = c.value(.totalMinor, 0)
        refundedMinor = c.value(.refundedMinor, 0)
    }
}

struct MovingTicketMessageDto: Decodable, Equatable {
    /// user | provider | admin
    var author: String?
    var body: String?
    var at: String?

    enum CodingKeys: String, CodingKey { case author, body, at }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        author = c.value(.author, nil)
        body = c.value(.body, nil)
        at = c.value(.at, nil)
    }
}

struct MovingTicketDto: Decodable, Equatable, Identifiable {
    var uuid: String
    var requestUuid: String?
    var type: String?
    var subject: String?
    /// awaiting_company | company_replied | escalated | awaiting_customer | resolved | unresolved
    var status: String?
    var escalationReason: String?
    var closingReason: String?
    var createdAt: String?
    var messages: [MovingTicketMessageDto]

    var id: String { uuid }

    enum CodingKeys: String, CodingKey {
        case uuid, type, subject, status, messages
        case requestUuid = "request_uuid", escalationReason = "escalation_reason", closingReason = "closing_reason", createdAt = "created_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        uuid = c.value(.uuid, "")
        requestUuid = c.value(.requestUuid, nil)
        type = c.value(.type, nil)
        subject = c.value(.subject, nil)
        status = c.value(.status, nil)
        escalationReason = c.value(.escalationReason, nil)
        closingReason = c.value(.closingReason, nil)
        createdAt = c.value(.createdAt, nil)
        messages = c.value(.messages, [])
    }
}

// MARK: - Endpoints

enum MovingApi {
    private static let base = "mobile/v1/moving"

    static func options() async throws -> ApiEnvelope<MovingOptionsDto> {
        try await ApiClient.get("\(base)/options", auth: true)
    }

    /// The road between the two ends (the admin's map engine, cached on the server per pair). Asked once when both ends
    /// are set or one of them changes, never on a timer. `estimated`: no road was found, the numbers are a straight line.
    static func route(fromLat: Double, fromLng: Double, toLat: Double, toLng: Double) async throws -> ApiEnvelope<MovingRouteDto> {
        try await ApiClient.get("\(base)/route", query: [
            "from_lat": String(fromLat), "from_lng": String(fromLng),
            "to_lat": String(toLat), "to_lng": String(toLng),
        ], auth: true)
    }

    /// `status=active`: only what is not over yet. Paged (`pagination.has_more_pages`).
    static func requests(status: String?, page: Int) async throws -> ApiEnvelope<[MovingRequestDto]> {
        try await ApiClient.get("\(base)/requests", query: ["status": status, "page": String(page)], auth: true)
    }

    static func create(_ body: [String: JSONValue]) async throws -> ApiEnvelope<MovingRequestDto> {
        try await ApiClient.post("\(base)/requests", body: body, auth: true)
    }

    static func show(_ uuid: String) async throws -> ApiEnvelope<MovingShowDto> {
        try await ApiClient.get("\(base)/requests/\(uuid)", auth: true)
    }

    static func update(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<MovingRequestDto> {
        try await ApiClient.put("\(base)/requests/\(uuid)", body: body)
    }

    static func addMedia(_ uuid: String, file: ApiClient.FilePart) async throws -> ApiEnvelope<MovingMediaDto> {
        try await ApiClient.upload("\(base)/requests/\(uuid)/media", files: [file])
    }

    /// The customer's voice note about the load: `kind=voice`, its length and bars (a JSON list); it replaces the last one.
    static func addVoice(_ uuid: String, file: ApiClient.FilePart, durationMs: Int64, waveform: String) async throws -> ApiEnvelope<MovingMediaDto> {
        try await ApiClient.upload("\(base)/requests/\(uuid)/media", fields: ["kind": "voice", "duration_ms": String(durationMs), "waveform": waveform], files: [file])
    }

    /// Answers 204 with no body, so there is no envelope to read: the answer is not looked at (as on Android).
    static func removeMedia(_ uuid: String, media: Int) async {
        let _: ApiEnvelope<Ignored>? = try? await ApiClient.delete("\(base)/requests/\(uuid)/media/\(media)")
    }

    /// Refused with `moving_fields_missing` and `data.missing` (read it with `movingRefusal`).
    static func publish(_ uuid: String) async throws -> ApiEnvelope<MovingRequestDto> {
        try await ApiClient.post("\(base)/requests/\(uuid)/publish", body: [String: JSONValue](), auth: true)
    }

    static func withdraw(_ uuid: String) async throws -> ApiEnvelope<MovingRequestDto> {
        try await ApiClient.post("\(base)/requests/\(uuid)/withdraw", body: [String: JSONValue](), auth: true)
    }

    /// Expired or withdrawn: a new draft with the same details and photos (a time that has passed is cleared).
    /// Refused: `moving_cannot_republish`.
    static func republish(_ uuid: String) async throws -> ApiEnvelope<MovingRequestDto> {
        try await ApiClient.post("\(base)/requests/\(uuid)/republish", body: [String: JSONValue](), auth: true)
    }

    static func offers(_ uuid: String, page: Int) async throws -> ApiEnvelope<MovingOffersPageDto> {
        try await ApiClient.get("\(base)/requests/\(uuid)/offers", query: ["page": String(page)], auth: true)
    }

    /// A checkout for this offer; paying it on the shared payment screen holds the money and accepts the offer.
    static func accept(_ uuid: String, offer: String) async throws -> ApiEnvelope<CheckoutDto> {
        try await ApiClient.post("\(base)/requests/\(uuid)/offers/\(offer)/accept", body: [String: JSONValue](), auth: true)
    }

    static func cancellation(_ uuid: String) async throws -> ApiEnvelope<MovingCancellationDto> {
        try await ApiClient.get("\(base)/requests/\(uuid)/cancellation", auth: true)
    }

    /// `data` is the request, or `{case, kind}` when the reason opened an exception for Dorr.
    static func cancel(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.post("\(base)/requests/\(uuid)/cancel", body: body, auth: true)
    }

    static func confirm(_ uuid: String) async throws -> ApiEnvelope<MovingRequestDto> {
        try await ApiClient.post("\(base)/requests/\(uuid)/confirm", body: [String: JSONValue](), auth: true)
    }

    static func report(_ uuid: String, fields: [String: String], photos: [ApiClient.FilePart]) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.upload("\(base)/requests/\(uuid)/report", fields: fields, files: photos)
    }

    static func changes(_ uuid: String) async throws -> ApiEnvelope<[MovingChangeDto]> {
        try await ApiClient.get("\(base)/requests/\(uuid)/changes", auth: true)
    }

    /// A yes that costs more comes back with `checkout`: open the payment screen with it.
    static func respondChange(_ uuid: String, change: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<MovingRespondDto> {
        try await ApiClient.post("\(base)/requests/\(uuid)/changes/\(change)/respond", body: body, auth: true)
    }

    static func reschedule(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<MovingChangeDto> {
        try await ApiClient.post("\(base)/requests/\(uuid)/reschedule", body: body, auth: true)
    }

    static func rate(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.post("\(base)/requests/\(uuid)/rating", body: body, auth: true)
    }

    static func invoice(_ uuid: String) async throws -> ApiEnvelope<MovingInvoiceDto> {
        try await ApiClient.get("\(base)/requests/\(uuid)/invoice", auth: true)
    }

    static func tickets(_ uuid: String) async throws -> ApiEnvelope<[MovingTicketDto]> {
        try await ApiClient.get("\(base)/requests/\(uuid)/tickets", auth: true)
    }

    static func openTicket(_ uuid: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<MovingTicketDto> {
        try await ApiClient.post("\(base)/requests/\(uuid)/tickets", body: body, auth: true)
    }

    static func ticket(_ ticket: String) async throws -> ApiEnvelope<MovingTicketDto> {
        try await ApiClient.get("\(base)/tickets/\(ticket)", auth: true)
    }

    /// `action`: reply | resolve | escalate.
    static func actOnTicket(_ ticket: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<MovingTicketDto> {
        try await ApiClient.post("\(base)/tickets/\(ticket)/act", body: body, auth: true)
    }
}
