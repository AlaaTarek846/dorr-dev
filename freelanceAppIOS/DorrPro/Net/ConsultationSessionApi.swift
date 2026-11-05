import Foundation

// freelanceApp `net/ConsultationSessionApi.kt` (its `ServerClock` lives in CurrencyLabel.swift).

/// FL-L16, the consultation's video session under `/api/freelance/v1/consultations/{uuid}/session…`: the room's state (also inside the
/// consultation as `session`), a ticket into the LiveKit room, and saying the app is in it / left it. No polling: the end is known from
/// `ends_at` on the phone's clock (corrected by the server's), and the notices say when the other side came, the end is near, or the
/// recording is ready.
enum ConsultationSessionApi {
    private static let base = Api.freelance

    static func session(_ uuid: String) async -> ApiResult<ConsultationSessionDto> {
        await Api.call("GET", base + "consultations/\(uuid)/session")
    }

    /// A ticket into the room (LiveKit's address and a token good until the end).
    static func join(_ uuid: String) async -> ApiResult<ConsultationJoinDto> {
        await Api.call("POST", base + "consultations/\(uuid)/session/join")
    }

    /// The app is in the room now: my stay starts (and the recording, if it is not running).
    static func connected(_ uuid: String) async -> ApiResult<ConsultationSessionDto> {
        await Api.call("POST", base + "consultations/\(uuid)/session/connected")
    }

    /// The app left the room; coming back while time is left is fine.
    static func leave(_ uuid: String) async -> ApiResult<ConsultationSessionDto> {
        await Api.call("POST", base + "consultations/\(uuid)/session/leave")
    }
}

/// What the consultation screen shows about the session (ConsultationSessions::presentFor).
struct ConsultationSessionDto: Decodable, Equatable {
    /// LiveKit is set up on the server.
    var available: Bool?
    var roomOpen: Bool?
    var opensAt: String?
    var startsAt: String?
    var endsAt: String?
    /// `by`: time · staff
    var closed: SessionClosedDto?
    var recorded: Bool?
    /// The recording can really be made (the bucket is set up); until then sessions run unrecorded.
    var recordingAvailable: Bool?
    var consent: SessionConsentDto?
    var inRoom: SessionInRoomDto?
    var attendance: [SessionStayDto]?
    var recordings: [SessionRecordingDto]?
    var recordingsDeleteAt: String?
    /// Kept until the staff decide on a complaint.
    var recordingsHeld: Bool?
    var recordingsDeletedAt: String?
    var can: SessionCanDto?
}

struct SessionClosedDto: Decodable, Equatable {
    var by: String?
    var at: String?
}

struct SessionConsentDto: Decodable, Equatable {
    var client: String?
    var freelancer: String?
}

struct SessionInRoomDto: Decodable, Equatable {
    var client: Bool?
    var freelancer: Bool?
}

/// One stay in the room: `side` client · freelancer.
struct SessionStayDto: Decodable, Equatable {
    var side: String?
    var joinedAt: String?
    var leftAt: String?
}

/// One recorded part. `status`: recording · processing · moving · stored · deleted. The links are signed for a few minutes.
struct SessionRecordingDto: Decodable, Equatable, Identifiable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var part: Int?
    var status: String?
    var durationSeconds: Int?
    var sizeBytes: Int?
    var startedAt: String?
    var stoppedAt: String?
    var downloadUrl: String?
    var watchUrl: String?
}

struct SessionCanDto: Decodable, Equatable {
    var join: Bool?
}

/// The ticket into the room.
struct ConsultationJoinDto: Decodable, Equatable {
    var url: String?
    var token: String?
    var room: String?
    var identity: String?
    var startsAt: String?
    var endsAt: String?
    var serverTime: String?
    var warnBeforeEndMinutes: Int?
    /// The session is being recorded.
    var recording: Bool?
    var other: SessionOtherDto?
}

struct SessionOtherDto: Decodable, Equatable {
    var side: String?
    var name: String?
    var inRoom: Bool?
}
