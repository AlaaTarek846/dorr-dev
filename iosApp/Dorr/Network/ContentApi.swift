import Foundation

/// androidApp `network/LanguageApi.kt` + `LanguageDto`.
struct LanguageDto: Codable, Equatable, Identifiable {
    var id: Int
    var code: String
    var name: String
    var direction: String
    var flag: FlagDto?
    /// Version of the downloadable Android strings; nil for the bundled ar/en.
    var androidVersion: String?

    enum CodingKeys: String, CodingKey {
        case id, code, name, direction, flag
        case androidVersion = "android_version"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        code = c.value(.code, "")
        name = c.value(.name, "")
        direction = c.value(.direction, "ltr")
        flag = c.value(.flag, nil)
        androidVersion = c.value(.androidVersion, nil)
    }
}

enum LanguageApi {
    /// GET /api/general/v1/translations/languages?platform=android — ar/en (bundled) plus active languages
    /// with published strings, each with its `android_version`. The iOS app reads the same Android strings
    /// (the keys are shared), so it asks for the Android platform too. Pre-login.
    static func list() async throws -> ApiEnvelope<[LanguageDto]> {
        try await ApiClient.get("general/v1/translations/languages", query: ["platform": "android"])
    }

    /// GET /api/general/v1/translations/{code}/android — `{code, direction, version, strings}`, raw.
    static func androidStrings(_ code: String) async throws -> Data {
        try await ApiClient.raw("general/v1/translations/\(code)/android")
    }
}

/// androidApp `network/ContentApi.kt`.
struct FaqDto: Codable, Equatable, Identifiable {
    var id: Int
    var question: String
    var answer: String
    var status: Bool
    var sortOrder: Int?

    enum CodingKeys: String, CodingKey {
        case id, question, answer, status
        case sortOrder = "sort_order"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        question = c.value(.question, "")
        answer = c.value(.answer, "")
        status = c.value(.status, true)
        sortOrder = c.value(.sortOrder, nil)
    }
}

struct LegalPageDto: Codable, Equatable {
    var id: Int?
    var type: String?
    var content: String?
    var status: Bool?
}

enum ContentApi {
    /// GET /api/mobile/v1/faqs — active general FAQs for the mobile app.
    static func faqs() async throws -> ApiEnvelope<[FaqDto]> {
        try await ApiClient.get("mobile/v1/faqs", auth: true)
    }

    /// GET /api/mobile/v1/legal-pages?type= — active general legal page (privacy/term); with a service and
    /// `fallback` the page in force for that service (its own, else the general one — R-179).
    static func legalPage(type: String = "privacy", serviceId: Int? = nil, fallback: Int? = nil) async throws -> ApiEnvelope<LegalPageDto> {
        try await ApiClient.get("mobile/v1/legal-pages", query: [
            "type": type, "service_id": serviceId.map(String.init), "fallback": fallback.map(String.init),
        ], auth: true)
    }
}

/// androidApp `ui/locale/DownloadedTranslations.kt` — interface strings of a language that is not bundled
/// (anything but ar/en), downloaded from GET general/v1/translations/{code}/android. Only the current
/// language is kept on disk: `Application Support/translations/{code}.json` holds the flat `strings` map
/// (`key -> text`, or `key -> {quantity -> text}` for plurals); its code/version/direction live in
/// UserDefaults so a cold start without internet still has them.
enum DownloadedTranslations {
    private static let codeKey = "dorr_app_prefs.translations_code"
    private static let versionKey = "dorr_app_prefs.translations_version"
    private static let directionKey = "dorr_app_prefs.translations_direction"

    struct Meta {
        let code: String
        let version: String
        let direction: String
    }

    enum Result { case success, notAvailable, failed }

    private static var memory: (code: String, strings: [String: Any])?
    private static let lock = NSLock()

    static func meta() -> Meta? {
        let defaults = UserDefaults.standard
        guard let code = defaults.string(forKey: codeKey) else { return nil }
        return Meta(code: code, version: defaults.string(forKey: versionKey) ?? "", direction: defaults.string(forKey: directionKey) ?? "ltr")
    }

    /// The stored strings of [code], read from disk once and then kept in memory; nil when absent.
    static func load(_ code: String) -> [String: Any]? {
        lock.lock()
        defer { lock.unlock() }
        if let memory, memory.code == code { return memory.strings }
        guard meta()?.code == code,
              let data = try? Data(contentsOf: fileFor(code)),
              let strings = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else { return nil }
        memory = (code, strings)
        return strings
    }

    /// Downloads [code] and writes it atomically, so a failed or interrupted download never replaces the
    /// working file. Only after success are the metadata updated and any other language file deleted.
    static func download(_ code: String) async -> Result {
        let body: Data
        do {
            body = try await LanguageApi.androidStrings(code)
        } catch {
            if case ApiError.http(let status, _) = error, status == 404 { return .notAvailable }
            return .failed
        }
        guard let root = try? JSONSerialization.jsonObject(with: body) as? [String: Any],
              let data = root["data"] as? [String: Any],
              let strings = data["strings"] as? [String: Any],
              let json = try? JSONSerialization.data(withJSONObject: strings) else { return .failed }
        let version = data["version"] as? String ?? (data["version"] as? NSNumber)?.stringValue ?? ""
        let direction = data["direction"] as? String ?? "ltr"

        let directory = directoryURL()
        try? FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        guard (try? json.write(to: fileFor(code), options: .atomic)) != nil else { return .failed }
        for file in (try? FileManager.default.contentsOfDirectory(at: directory, includingPropertiesForKeys: nil)) ?? []
        where file.lastPathComponent != "\(code).json" {
            try? FileManager.default.removeItem(at: file)
        }

        let defaults = UserDefaults.standard
        defaults.set(code, forKey: codeKey)
        defaults.set(version, forKey: versionKey)
        defaults.set(direction, forKey: directionKey)
        lock.lock()
        memory = (code, strings)
        lock.unlock()
        return .success
    }

    /// Back to a bundled language (ar/en): nothing downloaded is kept.
    static func clear() {
        lock.lock()
        memory = nil
        lock.unlock()
        try? FileManager.default.removeItem(at: directoryURL())
        let defaults = UserDefaults.standard
        defaults.removeObject(forKey: codeKey)
        defaults.removeObject(forKey: versionKey)
        defaults.removeObject(forKey: directionKey)
    }

    private static func directoryURL() -> URL {
        FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0].appendingPathComponent("translations")
    }

    private static func fileFor(_ code: String) -> URL { directoryURL().appendingPathComponent("\(code).json") }
}

/// ar/en ship in the app (ar.lproj, en.lproj); every other language is downloaded.
func isBundledLanguage(_ code: String) -> Bool { ["ar", "en"].contains(code.lowercased()) }

/// androidApp `network/NotificationApi.kt` (the parts in use so far).
struct UnreadCountDto: Codable { var count: Int }

enum NotificationApi {
    /// GET /api/mobile/v1/notifications/unread-count
    static func unreadCount() async throws -> ApiEnvelope<UnreadCountDto> {
        try await ApiClient.get("mobile/v1/notifications/unread-count", auth: true)
    }
}

/// The chat list as far as the home page needs it (the rest of `ChatApi.kt` comes with the chat section).
enum ChatListApi {
    /// GET /api/mobile/v1/chat/conversations?filter=unread&per_page=50 — how many chats have unread messages.
    static func unreadConversations() async throws -> Int {
        let envelope: ApiEnvelope<[Ignored]> = try await ApiClient.get("mobile/v1/chat/conversations", query: ["filter": "unread", "per_page": "50"], auth: true)
        return envelope.data?.count ?? 0
    }
}
