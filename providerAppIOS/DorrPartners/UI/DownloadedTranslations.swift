import Foundation

/// providerApp `ui/locale/DownloadedTranslations.kt` — interface strings of a language that is not bundled
/// (anything but ar/en), downloaded from GET general/v1/translations/{code}/android. Only the current
/// language is kept on disk: `Application Support/translations/{code}.json` holds the flat `strings` map
/// (`key -> text`, or `key -> {quantity -> text}` for plurals); its code/version/direction live in
/// UserDefaults so a cold start without internet still has them.
enum DownloadedTranslations {
    private static let codeKey = "dorr_provider_prefs.translations_code"
    private static let versionKey = "dorr_provider_prefs.translations_version"
    private static let directionKey = "dorr_provider_prefs.translations_direction"

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
        switch await Api.raw("general/v1/translations/\(code)/android") {
        case .success(let data): body = data
        case .failure(let f): return f.status == 404 ? .notAvailable : .failed
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

