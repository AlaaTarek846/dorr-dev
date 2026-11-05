import Foundation

// driverApp `net/DriverLessonsApi.kt` — same calls, paths and bodies; its DTOs below, as in the Kotlin file.

/// Driving lessons, the trainer's side (docs/rides_tasks.md O-26b).
enum DriverLessonsApi {
    private static let d = Api.driver

    static func packs() async -> ApiResult<[TrainerPackDto]> { await Api.call("GET", d + "lessons/packs") }

    /// `{title, description?, transmission, lessons, minutes_per_lesson, price_minor, own_car, area{lat,lng,address}}`
    static func publish(body: JsonBody) async -> ApiResult<TrainerPackDto> { await Api.call("POST", d + "lessons/packs", body: body) }

    /// O-62: edit a pack (with learners in it: the name, the description and the area only).
    static func update(uuid: String, body: JsonBody) async -> ApiResult<TrainerPackDto> { await Api.call("PUT", d + "lessons/packs/\(uuid)", body: body) }

    /// O-62: remove a pack (archived when anyone ever bought it).
    static func remove(uuid: String) async -> ApiResult<JSONAny> { await Api.json("DELETE", d + "lessons/packs/\(uuid)") }

    /// `{status: active | paused}`
    static func status(uuid: String, body: JsonBody) async -> ApiResult<TrainerPackDto> { await Api.call("POST", d + "lessons/packs/\(uuid)/status", body: body) }

    static func sessions() async -> ApiResult<[TrainerSessionDto]> { await Api.call("GET", d + "lessons/sessions") }

    /// `{approve}`
    static func answer(uuid: String, body: JsonBody) async -> ApiResult<TrainerSessionDto> {
        await Api.call("POST", d + "lessons/sessions/\(uuid)/answer", body: body)
    }

    static func done(uuid: String) async -> ApiResult<TrainerSessionDto> { await Api.call("POST", d + "lessons/sessions/\(uuid)/done") }
}

struct TrainerPackDto: Decodable, Equatable {
    var uuid: String?
    var status: String?
    var title: String?
    var transmission: String?
    var lessons: Int?
    var minutesPerLesson: Int?
    var priceMinor: Int?
    var ownCar: Bool?
    var area: PlaceDto?
    var currency: CurrencyDto?
    var learners: Int?
    var description: String?
}

struct TrainerLearnerDto: Decodable, Equatable {
    var name: String?
    var phone: String?
}

struct TrainerSessionPackDto: Decodable, Equatable {
    var title: String?
    var minutesPerLesson: Int?
    var transmission: String?
    var lessons: Int?
}

struct TrainerSessionDto: Decodable, Equatable {
    var uuid: String?
    var number: Int?
    var status: String?
    var startsAt: String?
    var pickup: PlaceDto?
    var learner: TrainerLearnerDto?
    var pack: TrainerSessionPackDto?
}

