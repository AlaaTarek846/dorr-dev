import Combine
import Foundation
import Network

// androidApp `chat/ChatPerformance.kt` + `chat/ChatUsage.kt` — data saver (spec 82), lite mode for modest phones
// (spec 87) and the usage stats kept on this phone (spec 83).

/// Data saver: on mobile data only, or always. While it's on — others' photos don't download by themselves (a tiny
/// preview and a download button), clips sent as GIFs don't play by themselves, photos and videos I send go lighter,
/// calls send and receive less video (360p, 15 fps).
@MainActor
final class ChatDataSaver: ObservableObject {
    static let shared = ChatDataSaver()

    /// off · mobile (on mobile data only) · always
    @Published private(set) var mode: String
    /// On mobile data (an expensive connection) right now.
    @Published private(set) var metered = false
    /// Photos I chose to download while saving data (until the app closes; then they're on the phone).
    @Published var loaded: Set<String> = []
    /// The same as `metered`, readable off the main thread (the request counter).
    nonisolated(unsafe) static var meteredNow = false

    var active: Bool { mode == "always" || (mode == "mobile" && metered) }

    private let monitor = NWPathMonitor()

    private init() {
        mode = UserDefaults.standard.string(forKey: "chat_data.mode") ?? "off"
        monitor.pathUpdateHandler = { path in
            let expensive = path.isExpensive || path.usesInterfaceType(.cellular)
            ChatDataSaver.meteredNow = expensive
            Task { @MainActor in ChatDataSaver.shared.metered = expensive }
        }
        monitor.start(queue: DispatchQueue(label: "chat.datasaver"))
    }

    func setMode(_ value: String) {
        mode = value
        UserDefaults.standard.set(value, forKey: "chat_data.mode")
    }
}

/// Lite mode: by itself on a phone with little memory, or on / off by choice. While it's on — no bubble animations,
/// no blur (a plain veil instead), still GIFs and stickers, no clips playing by themselves, no camera backgrounds in
/// calls, and lighter call video.
@MainActor
final class ChatLite: ObservableObject {
    static let shared = ChatLite()

    /// auto · on · off
    @Published private(set) var mode: String
    /// The phone's memory, in GB (shown in the settings).
    let ramGb = Double(ProcessInfo.processInfo.physicalMemory) / 1_073_741_824
    /// A phone with little memory (3 GB or less — a "3 GB" phone reports a little under it).
    var weakPhone: Bool { ProcessInfo.processInfo.physicalMemory < 3_300_000_000 }

    var active: Bool { mode == "on" || (mode == "auto" && weakPhone) }

    private init() {
        mode = UserDefaults.standard.string(forKey: "chat_lite.mode") ?? "auto"
    }

    func setMode(_ value: String) {
        mode = value
        UserDefaults.standard.set(value, forKey: "chat_lite.mode")
    }
}

/// Calls on a light budget: data saver or lite mode.
@MainActor var lightCalls: Bool { ChatDataSaver.shared.active || ChatLite.shared.active }

/// Clips that play by themselves (GIFs): not while saving data or in lite mode.
@MainActor var autoplayClips: Bool { !ChatDataSaver.shared.active && !ChatLite.shared.active }

/// Usage stats, kept on this phone like WhatsApp's "Network usage": messages sent and received, the data chats used —
/// messages and media, up and down, on mobile data and on Wi-Fi — and calls (how many, how long, how much data).
/// Counted since a date; reset by hand. Chat requests are counted as they come back (`ApiClient`); a call's data is
/// the phone's traffic while it lasts (iOS has no per-app counter — Android uses the app's own TrafficStats).
enum ChatUsage {
    static let since = "since"
    static let sent = "sent"
    static let received = "received"
    static let callsOut = "calls_out"
    static let callsIn = "calls_in"
    static let callSeconds = "call_seconds"

    private static let store = UserDefaults(suiteName: "chat_usage") ?? .standard
    private static let lock = NSLock()

    /// Data counters: `msg_up_m`, `media_down_w`… (kind _ direction _ m = mobile / w = Wi-Fi).
    static func dataKey(_ kind: String, up: Bool, mobile: Bool) -> String { "\(kind)_\(up ? "up" : "down")_\(mobile ? "m" : "w")" }
    static func callDataKey(mobile: Bool) -> String { "call_\(mobile ? "m" : "w")" }

    static func attach() {
        if store.object(forKey: since) == nil { store.set(Int64(Date().timeIntervalSince1970 * 1000), forKey: since) }
    }

    static func snapshot() -> [String: Int64] {
        attach()
        var out: [String: Int64] = [:]
        for (k, v) in store.dictionaryRepresentation() {
            if let n = v as? NSNumber, !(v is Bool) { out[k] = n.int64Value }
        }
        return out
    }

    static func reset() {
        lock.lock()
        for k in store.dictionaryRepresentation().keys { store.removeObject(forKey: k) }
        store.set(Int64(Date().timeIntervalSince1970 * 1000), forKey: since)
        store.set(true, forKey: "cleared")
        lock.unlock()
    }

    /// Sample numbers so the usage page isn't empty the first time. Skip after I reset.
    static func seedDemoIfEmpty() {
        if store.bool(forKey: "cleared") { return }
        let snap = snapshot()
        if (snap[sent] ?? 0) + (snap[received] ?? 0) + (snap[callsOut] ?? 0) > 0 { return }
        let values: [String: Int64] = [
            since: Int64(Date().timeIntervalSince1970 * 1000) - 5 * 24 * 60 * 60 * 1000,
            sent: 186, received: 540,
            dataKey("msg", up: true, mobile: true): 1_150_000, dataKey("msg", up: false, mobile: true): 4_820_000,
            dataKey("msg", up: true, mobile: false): 3_400_000, dataKey("msg", up: false, mobile: false): 12_600_000,
            dataKey("media", up: true, mobile: true): 18_500_000, dataKey("media", up: false, mobile: true): 96_000_000,
            dataKey("media", up: true, mobile: false): 42_000_000, dataKey("media", up: false, mobile: false): 210_000_000,
            callsOut: 9, callsIn: 6, callSeconds: 4_860,
            callDataKey(mobile: true): 28_000_000, callDataKey(mobile: false): 74_000_000,
        ]
        for (k, v) in values { store.set(v, forKey: k) }
    }

    static func add(_ key: String, _ amount: Int64) {
        guard amount > 0 else { return }
        lock.lock()
        store.set(((store.object(forKey: key) as? NSNumber)?.int64Value ?? 0) + amount, forKey: key)
        lock.unlock()
    }

    static func messageSent() { add(sent, 1) }
    static func messageReceived() { add(received, 1) }

    /// One chat request: what went up and what came down.
    static func countRequest(up: Int64, down: Int64, upload: Bool, media: Bool) {
        let mobile = isMetered()
        add(dataKey(upload ? "media" : "msg", up: true, mobile: mobile), up)
        add(dataKey(media ? "media" : "msg", up: false, mobile: mobile), down)
    }

    private static func isMetered() -> Bool { ChatDataSaver.meteredNow }

    // MARK: - calls

    private static var callStart: Date?
    private static var callBytes: Int64 = 0
    private static var callMobile = false

    /// A call got through (both sides talking).
    @MainActor static func callStarted(incoming: Bool) {
        guard callStart == nil else { return }
        callStart = Date()
        callBytes = deviceBytes()
        callMobile = ChatDataSaver.shared.metered
        add(incoming ? callsIn : callsOut, 1)
    }

    @MainActor static func callEnded() {
        guard let start = callStart else { return }
        add(callSeconds, Int64(Date().timeIntervalSince(start)))
        let now = deviceBytes()
        if callBytes >= 0, now >= callBytes { add(callDataKey(mobile: callMobile), now - callBytes) }
        callStart = nil
    }

    /// Bytes in and out over Wi-Fi and mobile data since the phone started (its network interfaces' counters).
    private static func deviceBytes() -> Int64 {
        var total: Int64 = 0
        var list: UnsafeMutablePointer<ifaddrs>?
        guard getifaddrs(&list) == 0, let first = list else { return -1 }
        defer { freeifaddrs(list) }
        var cursor: UnsafeMutablePointer<ifaddrs>? = first
        while let p = cursor {
            let name = String(cString: p.pointee.ifa_name)
            if p.pointee.ifa_addr?.pointee.sa_family == UInt8(AF_LINK), name.hasPrefix("en") || name.hasPrefix("pdp_ip"),
               let data = p.pointee.ifa_data?.assumingMemoryBound(to: if_data.self) {
                total += Int64(data.pointee.ifi_ibytes) + Int64(data.pointee.ifi_obytes)
            }
            cursor = p.pointee.ifa_next
        }
        return total
    }
}

/// "12.4 MB" — the usage pages' sizes.
func usageBytes(_ bytes: Int64) -> String {
    switch bytes {
    case 1_073_741_824...: return String(format: "%.2f GB", Double(bytes) / 1_073_741_824)
    case 1_048_576...: return String(format: "%.1f MB", Double(bytes) / 1_048_576)
    case 1024...: return "\(bytes / 1024) KB"
    default: return "\(bytes) B"
    }
}
