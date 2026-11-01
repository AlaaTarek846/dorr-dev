import Foundation
import Security

// androidApp `widget/SportsWidgets.kt` (SportsWidgetStore + SportsWidgetWorker) — DORR Sports on the home screen: «My
// team» and «Live now». Shared by the app and the DorrWidgets extension through the app group: the account's token
// (in the group's keychain), the server, the app's language, the last data and the crests kept on the phone. The
// widgets read our server (`sports/widget`) every 15 minutes, and the app asks them to read again right after a sports
// push and whenever Sports opens.

struct SpWidgetTeam: Codable, Equatable {
    var id: Int
    var name: String?
    var code: String?
    var logo: String?
}

struct SpWidgetCompetition: Codable, Equatable {
    var name: String?
}

struct SpWidgetMatch: Codable, Equatable, Identifiable {
    var id: String
    var competition: SpWidgetCompetition?
    var home: SpWidgetTeam?
    var away: SpWidgetTeam?
    var status: String?
    var minute: Int?
    var minuteExtra: Int?
    var homeScore: Int?
    var awayScore: Int?
    var localDate: String?
    var localTime: String?
}

struct SpWidgetData: Codable, Equatable {
    var mine: [SpWidgetMatch]?
    var live: [SpWidgetMatch]?
    var updatedAt: String?
    var signedOut: Bool?
}

enum SportsWidgetStore {
    static let kind = (myTeam: "DorrMyTeamWidget", live: "DorrLiveWidget")

    /// The app's id, whether this runs in the app or in one of its extensions.
    private static var appId: String {
        let id = Bundle.main.bundleIdentifier ?? "com.dorr.app"
        for suffix in [".widgets", ".notifications"] where id.hasSuffix(suffix) { return String(id.dropLast(suffix.count)) }
        return id
    }
    static var group: String { "group.\(appId)" }
    static var defaults: UserDefaults { UserDefaults(suiteName: group) ?? .standard }

    // MARK: what the app hands over

    /// The account's token, in the app group's keychain (nil: signed out).
    static var token: String? {
        get {
            let query: [String: Any] = [
                kSecClass as String: kSecClassGenericPassword, kSecAttrAccount as String: "dorr_widget.token",
                kSecAttrAccessGroup as String: group, kSecReturnData as String: true, kSecMatchLimit as String: kSecMatchLimitOne,
            ]
            var result: AnyObject?
            guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess, let data = result as? Data else { return nil }
            return String(data: data, encoding: .utf8)
        }
        set {
            let query: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrAccount as String: "dorr_widget.token", kSecAttrAccessGroup as String: group]
            SecItemDelete(query as CFDictionary)
            guard let newValue, !newValue.isEmpty, let data = newValue.data(using: .utf8) else { return }
            var attributes = query
            attributes[kSecValueData as String] = data
            attributes[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlock
            SecItemAdd(attributes as CFDictionary, nil)
        }
    }

    /// `https://host` — where the app talks to.
    static var origin: String? {
        get { defaults.string(forKey: "widget.origin") }
        set { defaults.set(newValue, forKey: "widget.origin") }
    }

    /// The app's language (ar / en) — written by the app with every language change.
    static var language: String { defaults.string(forKey: "app.locale") ?? "ar" }

    // MARK: the last data and the crests

    static func save(_ data: SpWidgetData) {
        defaults.set(try? JSONEncoder().encode(data), forKey: "widget.data")
    }

    static func load() -> SpWidgetData? {
        defaults.data(forKey: "widget.data").flatMap { try? JSONDecoder().decode(SpWidgetData.self, from: $0) }
    }

    static var crestsDir: URL? {
        guard let root = FileManager.default.containerURL(forSecurityApplicationGroupIdentifier: group) else { return nil }
        let dir = root.appendingPathComponent("sports_widget_crests", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir
    }

    static func crest(_ teamId: Int) -> Data? {
        crestsDir.flatMap { try? Data(contentsOf: $0.appendingPathComponent("\(teamId).png")) }
    }

    // MARK: reading the server

    /// `sports/widget` now; signed out → `signedOut`. Crests once per team (20 at most).
    static func fetch() async -> SpWidgetData? {
        guard let token, !token.isEmpty, let origin else {
            let out = SpWidgetData(signedOut: true)
            save(out)
            return out
        }
        var parts = URLComponents(string: "\(origin)/api/mobile/v1/sports/widget")
        parts?.queryItems = [URLQueryItem(name: "timezone", value: TimeZone.current.identifier)]
        guard let url = parts?.url else { return nil }
        var request = URLRequest(url: url, timeoutInterval: 20)
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue(language, forHTTPHeaderField: "Accept-Language")
        guard let (body, response) = try? await URLSession.shared.data(for: request),
              (response as? HTTPURLResponse)?.statusCode == 200 else { return load() }
        struct Envelope: Decodable { var data: SpWidgetData? }
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        guard let data = (try? decoder.decode(Envelope.self, from: body))?.data else { return load() }
        save(data)
        await keepCrests(data, origin: origin)
        return data
    }

    private static func keepCrests(_ data: SpWidgetData, origin: String) async {
        guard let dir = crestsDir else { return }
        var seen = Set<Int>()
        let teams = ((data.mine ?? []) + (data.live ?? [])).flatMap { [$0.home, $0.away].compactMap { $0 } }.filter { seen.insert($0.id).inserted }.prefix(20)
        for t in teams {
            let file = dir.appendingPathComponent("\(t.id).png")
            guard !FileManager.default.fileExists(atPath: file.path), let logo = t.logo,
                  let url = URL(string: logo.hasPrefix("http") ? logo : origin + (logo.hasPrefix("/") ? "" : "/") + logo),
                  let (bytes, _) = try? await URLSession.shared.data(from: url) else { continue }
            try? bytes.write(to: file)
        }
    }
}
