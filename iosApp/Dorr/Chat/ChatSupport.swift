import CoreLocation
import Foundation

// androidApp `chat/ChatFeatures.kt`, `chat/ChatShield.kt`, `chat/ChatFormatting.kt` — which chat features are on where
// I am (the admin's rules by country and city; the phone tells the server its approximate place at most every six
// hours and the server keeps only the city), the privacy that lives on this phone (Safe View, hide in the app
// switcher, blur media, chats kept out of the list), and WhatsApp-style text marks.

@MainActor
final class ChatFeatures: ObservableObject {
    static let shared = ChatFeatures()
    private static let locateEvery: TimeInterval = 6 * 60 * 60
    private let defaults = UserDefaults.standard

    @Published private(set) var features: [String: Bool] = [:]
    /// The city the rules were decided for (nil: the country's rules).
    @Published private(set) var city: String?

    /// Unknown counts as on: the server still refuses what's off.
    func allows(_ feature: String) -> Bool { (features[feature] ?? features[camel(feature)]) != false }

    func refresh() async {
        let located = locationAllowed && due ? await coarseLocation() : nil
        let dto: ChatFeaturesDto?
        if let located {
            dto = try? await ChatApi.locate(latitude: located.latitude, longitude: located.longitude).data
            if dto != nil { defaults.set(Date().timeIntervalSince1970, forKey: "chat_features.located_at") }
        } else {
            dto = try? await ChatApi.features().data
        }
        guard let dto else { return }
        features = dto.features ?? [:]
        city = dto.city?.name
    }

    /// Ask for the approximate location once (after our own explanation), never again.
    var shouldAsk: Bool { !locationAllowed && !defaults.bool(forKey: "chat_features.asked") }
    func markAsked() { defaults.set(true, forKey: "chat_features.asked") }
    /// A new answer to the permission: send the city right away.
    func forceLocate() { defaults.set(0, forKey: "chat_features.located_at") }

    private var due: Bool { Date().timeIntervalSince1970 - defaults.double(forKey: "chat_features.located_at") > Self.locateEvery }

    private var locationAllowed: Bool {
        let s = CLLocationManager().authorizationStatus
        return s == .authorizedWhenInUse || s == .authorizedAlways
    }

    private func coarseLocation() async -> CLLocationCoordinate2D? {
        if let last = CLLocationManager().location, Date().timeIntervalSince(last.timestamp) < Self.locateEvery { return last.coordinate }
        return await OneShotLocation().fetch()
    }
}

/// One reduced-accuracy fix (10 s at most).
private final class OneShotLocation: NSObject, CLLocationManagerDelegate {
    private let manager = CLLocationManager()
    private var continuation: CheckedContinuation<CLLocationCoordinate2D?, Never>?

    func fetch() async -> CLLocationCoordinate2D? {
        await withCheckedContinuation { c in
            continuation = c
            manager.delegate = self
            manager.desiredAccuracy = kCLLocationAccuracyReduced
            manager.requestLocation()
            DispatchQueue.main.asyncAfter(deadline: .now() + 10) { [weak self] in self?.finish(nil) }
        }
    }

    private func finish(_ c: CLLocationCoordinate2D?) {
        continuation?.resume(returning: c)
        continuation = nil
    }

    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) { finish(locations.last?.coordinate) }
    func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) { finish(nil) }
}

/// Privacy on this phone (spec 104–110), next to the server's privacy mode.
@MainActor
final class ChatShield: ObservableObject {
    static let shared = ChatShield()
    private let d = UserDefaults.standard

    @Published private(set) var safeView: Bool
    @Published private(set) var hideInRecents: Bool
    @Published private(set) var blurMedia: Bool
    /// Seconds before revealed content hides again; 0 = when I leave the chat.
    @Published private(set) var rehideSeconds: Int
    /// Chats kept out of the list while Safe View is on.
    @Published private(set) var safeHidden: Set<String>

    private init() {
        safeView = d.bool(forKey: "chat_shield.safe_view")
        hideInRecents = d.bool(forKey: "chat_shield.hide_in_recents")
        blurMedia = d.bool(forKey: "chat_shield.blur_media")
        rehideSeconds = d.integer(forKey: "chat_shield.rehide_seconds")
        safeHidden = Set(d.stringArray(forKey: "chat_shield.safe_hidden") ?? [])
    }

    func setSafeView(_ on: Bool) { safeView = on; d.set(on, forKey: "chat_shield.safe_view") }
    func setHideInRecents(_ on: Bool) { hideInRecents = on; d.set(on, forKey: "chat_shield.hide_in_recents") }
    func setBlurMedia(_ on: Bool) { blurMedia = on; d.set(on, forKey: "chat_shield.blur_media") }
    func setRehideSeconds(_ s: Int) { rehideSeconds = s; d.set(s, forKey: "chat_shield.rehide_seconds") }

    func toggleSafeHidden(_ id: String) {
        if safeHidden.contains(id) { safeHidden.remove(id) } else { safeHidden.insert(id) }
        d.set(Array(safeHidden), forKey: "chat_shield.safe_hidden")
    }

    /// When a timed privacy mode started (for the summary once it's over), or 0.
    var privacyStartedAt: TimeInterval {
        get { d.double(forKey: "chat_shield.privacy_started") }
        set { d.set(newValue, forKey: "chat_shield.privacy_started") }
    }
}

// MARK: - Text marks

/// *bold*, _italic_, ~strike~, `code`, ```mono block```; a mark only counts at a word edge and around non-blank text,
/// nothing is formatted inside code or a link, and marks nest. Same rules as the web bubble and the push text.
struct ChatPiece: Equatable {
    var text: String
    var bold = false
    var italic = false
    var strike = false
    var code = false
    var codeBlock = false
    /// A link (or a Dorr invite) — tappable, never formatted.
    var url: String?
}

private let linkPattern = try! NSRegularExpression(pattern: "(https?://\\S+|www\\.\\S+|dorr://chat/join/\\S+)")
private let blockPattern = try! NSRegularExpression(pattern: "```([\\s\\S]+?)```")
private let markPatterns: [Character: NSRegularExpression] = Dictionary(uniqueKeysWithValues: ["*", "_", "~", "`"].map { (m: Character) in
    let e = NSRegularExpression.escapedPattern(for: String(m))
    return (m, try! NSRegularExpression(pattern: "(?<=^|[\\s\\p{P}])\(e)(?=\\S)([^\(e)\\n]*?\\S)\(e)(?=$|[\\s\\p{P}])"))
})

/// The text cut into styled pieces, marks removed.
func parseChatText(_ body: String) -> [ChatPiece] {
    var out: [ChatPiece] = []
    let ns = body as NSString
    var last = 0
    for m in linkPattern.matches(in: body, range: NSRange(location: 0, length: ns.length)) {
        blocks(ns.substring(with: NSRange(location: last, length: m.range.location - last)), &out)
        let link = ns.substring(with: m.range)
        out.append(ChatPiece(text: link, url: link))
        last = m.range.location + m.range.length
    }
    blocks(ns.substring(from: last), &out)
    return out
}

/// The text as it reads, without marks (list preview, copy as plain text).
func plainChatText(_ body: String?) -> String { body.map { parseChatText($0).map(\.text).joined() } ?? "" }

private func blocks(_ text: String, _ out: inout [ChatPiece]) {
    let ns = text as NSString
    var last = 0
    for m in blockPattern.matches(in: text, range: NSRange(location: 0, length: ns.length)) {
        marks(ns.substring(with: NSRange(location: last, length: m.range.location - last)), ChatPiece(text: ""), Set(markPatterns.keys), &out)
        out.append(ChatPiece(text: ns.substring(with: m.range(at: 1)), codeBlock: true))
        last = m.range.location + m.range.length
    }
    marks(ns.substring(from: last), ChatPiece(text: ""), Set(markPatterns.keys), &out)
}

/// The earliest mark first, so an outer mark wins and its inside is formatted again (nesting).
private func marks(_ text: String, _ style: ChatPiece, _ allowed: Set<Character>, _ out: inout [ChatPiece]) {
    if text.isEmpty { return }
    let ns = text as NSString
    let found = allowed.compactMap { mark -> (Character, NSTextCheckingResult)? in
        markPatterns[mark]?.firstMatch(in: text, range: NSRange(location: 0, length: ns.length)).map { (mark, $0) }
    }.min { $0.1.range.location < $1.1.range.location }
    guard let (mark, match) = found else {
        var p = style
        p.text = text
        out.append(p)
        return
    }
    marks(ns.substring(to: match.range.location), style, allowed, &out)
    let inner = ns.substring(with: match.range(at: 1))
    if mark == "`" {
        var p = style
        p.text = inner
        p.code = true
        out.append(p)
    } else {
        var styled = style
        switch mark {
        case "*": styled.bold = true
        case "_": styled.italic = true
        default: styled.strike = true
        }
        marks(inner, styled, allowed.subtracting([mark]), &out)
    }
    marks(ns.substring(from: match.range.location + match.range.length), style, allowed, &out)
}

/// How a line is laid out: plain, a list item, or a quote.
enum LineKind { case plain, bullet, numbered, quote }

/// One line of a message: its kind, the marker shown («•», «3.»), and the text after it.
struct ChatLine: Equatable {
    let kind: LineKind
    let marker: String
    let text: String
}

/// The message line by line when it uses list or quote lines (nil otherwise, and for anything with a ``` block).
func chatLines(_ body: String) -> [ChatLine]? {
    if body.contains("```") { return nil }
    func match(_ pattern: String, _ line: String) -> [String]? {
        guard let r = try? NSRegularExpression(pattern: pattern), let m = r.firstMatch(in: line, range: NSRange(location: 0, length: (line as NSString).length)) else { return nil }
        return (1..<m.numberOfRanges).map { (line as NSString).substring(with: m.range(at: $0)) }
    }
    let lines = body.components(separatedBy: "\n").map { line -> ChatLine in
        if let g = match("^[-*] (.*)$", line) { return ChatLine(kind: .bullet, marker: "•", text: g[0]) }
        if let g = match("^(\\d{1,3})\\. (.*)$", line) { return ChatLine(kind: .numbered, marker: g[0] + ".", text: g[1]) }
        if let g = match("^> (.*)$", line) { return ChatLine(kind: .quote, marker: "", text: g[0]) }
        return ChatLine(kind: .plain, marker: "", text: line)
    }
    return lines.contains { $0.kind != .plain } ? lines : nil
}

/// The approximate-location permission for city rules, asked after our own explanation; the answer sends the city
/// right away.
final class FeatureLocationAsk: NSObject, CLLocationManagerDelegate {
    static let shared = FeatureLocationAsk()
    private let manager = CLLocationManager()

    func ask() {
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyReduced
        manager.requestWhenInUseAuthorization()
    }

    func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        guard manager.authorizationStatus != .notDetermined else { return }
        Task { @MainActor in
            ChatFeatures.shared.forceLocate()
            await ChatFeatures.shared.refresh()
        }
    }
}
