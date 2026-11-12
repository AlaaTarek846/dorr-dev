import Foundation

// androidApp `network/CalendarApi.kt` — DORR Calendar & DORR Today. Times come as UTC instants; days are cut
// in [timezone].

/// One thing on the calendar: `type` = event (my appointment) · task · reminder (on a message) · moment (an
/// occasion) · personal (one of my own dates) · capsule (search only) · discover · sports. `date` is the day it
/// falls on where I am; `origin_time` is its time where it was set, when that's elsewhere.
struct CalItemDto: Codable, Equatable, Identifiable {
    var id: String
    var type: String
    var ref: String
    var title: String
    var startsAt: String?
    var endsAt: String?
    var allDay: Bool
    var date: String?
    var endDate: String?
    var timezone: String?
    var originTime: String?
    var location: String?
    var note: String?
    var color: String?
    var secondaryColor: String?
    var emoji: String?
    var source: String?
    var reminders: [Int]?
    var done: Bool
    var overdue: Bool
    var messageId: String?
    var conversationId: String?
    var kind: String?
    /// An appointment's kind: appointment · meeting · match · event · trip · study · health · work.
    var category: String?
    var turns: Int?
    var count: Int?
    var duplicate: Bool

    enum CodingKeys: String, CodingKey {
        case id, type, ref, title, date, timezone, location, note, color, emoji, source, reminders, done, overdue, kind, category, turns, count, duplicate
        case startsAt = "starts_at", endsAt = "ends_at", allDay = "all_day", endDate = "end_date", originTime = "origin_time"
        case secondaryColor = "secondary_color", messageId = "message_id", conversationId = "conversation_id"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = Self.string(c, .id) ?? ""
        type = c.value(.type, "event")
        ref = Self.string(c, .ref) ?? ""
        title = c.value(.title, "")
        startsAt = c.value(.startsAt, nil)
        endsAt = c.value(.endsAt, nil)
        allDay = c.value(.allDay, false)
        date = c.value(.date, nil)
        endDate = c.value(.endDate, nil)
        timezone = c.value(.timezone, nil)
        originTime = c.value(.originTime, nil)
        location = c.value(.location, nil)
        note = c.value(.note, nil)
        color = c.value(.color, nil)
        secondaryColor = c.value(.secondaryColor, nil)
        emoji = c.value(.emoji, nil)
        source = c.value(.source, nil)
        reminders = c.value(.reminders, nil)
        done = c.value(.done, false)
        overdue = c.value(.overdue, false)
        messageId = Self.string(c, .messageId)
        conversationId = Self.string(c, .conversationId)
        kind = c.value(.kind, nil)
        category = c.value(.category, nil)
        turns = c.value(.turns, nil)
        count = c.value(.count, nil)
        duplicate = c.value(.duplicate, false)
    }

    /// Gson reads a number into a String field too.
    private static func string(_ c: KeyedDecodingContainer<CodingKeys>, _ key: CodingKeys) -> String? {
        if let s = try? c.decode(String.self, forKey: key) { return s }
        if let n = try? c.decode(Int.self, forKey: key) { return String(n) }
        return nil
    }
}

struct CalFeedDto: Codable {
    var enabled: Bool
    var items: [CalItemDto]

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        enabled = c.value(.enabled, true)
        items = c.value(.items, [])
    }
}

struct CalSectionsDto: Codable, Equatable {
    var order: [String]
    var hidden: [String]

    init(order: [String], hidden: [String]) {
        self.order = order
        self.hidden = hidden
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        order = c.value(.order, [])
        hidden = c.value(.hidden, [])
    }
}

struct CalTodayDto: Codable, Equatable {
    var enabled: Bool
    var date: String?
    var timezone: String?
    var sections: CalSectionsDto?
    /// The sources I keep on (nil from an older server: all of them).
    var sources: [String]?
    var next: CalItemDto?
    var events: [CalItemDto]
    var tasks: [CalItemDto]
    var reminders: [CalItemDto]
    var moments: [CalItemDto]

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        enabled = c.value(.enabled, true)
        date = c.value(.date, nil)
        timezone = c.value(.timezone, nil)
        sections = c.value(.sections, nil)
        sources = c.value(.sources, nil)
        next = c.value(.next, nil)
        events = c.value(.events, [])
        tasks = c.value(.tasks, [])
        reminders = c.value(.reminders, [])
        moments = c.value(.moments, [])
    }
}

struct CalPrefsDto: Codable, Equatable {
    var sources: [String: Bool]
    var defaultReminders: [Int]
    var allDayReminders: [Int]
    var reminderChoices: [Int]
    var respectQuiet: Bool
    var personalised: Bool
    var todaySections: CalSectionsDto?

    enum CodingKeys: String, CodingKey {
        case sources, personalised
        case defaultReminders = "default_reminders", allDayReminders = "all_day_reminders", reminderChoices = "reminder_choices"
        case respectQuiet = "respect_quiet", todaySections = "today_sections"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        sources = c.value(.sources, [:])
        defaultReminders = c.value(.defaultReminders, [])
        allDayReminders = c.value(.allDayReminders, [])
        reminderChoices = c.value(.reminderChoices, [])
        respectQuiet = c.value(.respectQuiet, true)
        personalised = c.value(.personalised, true)
        todaySections = c.value(.todaySections, nil)
    }
}

/// `enabled` is false when the admin switched the calendar off.
struct CalSearchDto: Codable {
    var enabled: Bool
    var items: [CalItemDto]

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        enabled = c.value(.enabled, true)
        items = c.value(.items, [])
    }
}

enum CalendarApi {
    static func feed(from: String, to: String, timezone: String) async throws -> ApiEnvelope<CalFeedDto> {
        try await ApiClient.get("mobile/v1/chat/calendar", query: ["from": from, "to": to, "timezone": timezone], auth: true)
    }

    static func today(timezone: String) async throws -> ApiEnvelope<CalTodayDto> {
        try await ApiClient.get("mobile/v1/chat/calendar/today", query: ["timezone": timezone], auth: true)
    }

    static func search(_ q: String, timezone: String) async throws -> ApiEnvelope<CalSearchDto> {
        try await ApiClient.get("mobile/v1/chat/calendar/search", query: ["q": q, "timezone": timezone], auth: true)
    }

    /// `{title, starts_at | (all_day + date), ends_at?, timezone, location?, note?, color?, reminders?[], message_id?}`
    static func create(_ body: [String: JSONValue]) async throws -> ApiEnvelope<CalItemDto> {
        try await ApiClient.post("mobile/v1/chat/calendar/items", body: body, auth: true)
    }

    static func update(_ id: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<CalItemDto> {
        try await ApiClient.patch("mobile/v1/chat/calendar/items/\(id)", body: body)
    }

    static func delete(_ id: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.delete("mobile/v1/chat/calendar/items/\(id)")
    }

    static func preferences() async throws -> ApiEnvelope<CalPrefsDto> {
        try await ApiClient.get("mobile/v1/chat/calendar/preferences", auth: true)
    }

    static func savePreferences(_ body: [String: JSONValue]) async throws -> ApiEnvelope<CalPrefsDto> {
        try await ApiClient.put("mobile/v1/chat/calendar/preferences", body: body)
    }
}

/// androidApp `AiToolsApi.updateTask` — `{text?, due_at?, done?}` (the rest of the AI tools join with the AI section).
enum AiTasksApi {
    static func updateTask(_ id: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.patch("mobile/v1/chat/tasks/\(id)", body: body)
    }
}
