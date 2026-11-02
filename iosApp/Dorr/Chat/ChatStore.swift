import Foundation

// androidApp `chat/ChatStore.kt` — the chat's on-phone memory: preferences (dark mode: system / light / dark, the
// OneSignal app id for the next cold start); an offline cache (the last chat list and the latest messages of each
// conversation) so the chat opens at once and reads without a connection — the server's answer always replaces it;
// drafts, back in the box when I return and shown in the list as «Draft: …». Wiped on logout.

@MainActor
final class ChatStore: ObservableObject {
    static let shared = ChatStore()

    private static let messagesKept = 80
    private let defaults = UserDefaults.standard
    private let dir: URL = {
        let d = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0].appendingPathComponent("chat-cache", isDirectory: true)
        try? FileManager.default.createDirectory(at: d, withIntermediateDirectories: true)
        return d
    }()
    private var pendingWrites: [String: Task<Void, Never>] = [:]

    /// The chat is on screen: the app-wide offline page steps aside for the chat's own banner.
    @Published var screenOpen = false
    /// Past the chat list (a conversation, its info, media…): the tab bar steps away.
    @Published var immersive = false
    /// "system" | "light" | "dark"
    @Published private(set) var themeMode: String
    /// conversation id → unsent text.
    @Published private(set) var drafts: [String: String]

    private init() {
        themeMode = UserDefaults.standard.string(forKey: "chat.theme") ?? "system"
        drafts = (UserDefaults.standard.dictionary(forKey: "chat.drafts") as? [String: String]) ?? [:]
    }

    // MARK: Drafts

    func draft(_ conversationId: String) -> String { drafts[conversationId] ?? "" }

    func saveDraft(_ conversationId: String, _ text: String) {
        let clean = text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? nil : text
        if clean == drafts[conversationId] { return }
        drafts[conversationId] = clean
        defaults.set(drafts, forKey: "chat.drafts")
    }

    func setTheme(_ mode: String) {
        themeMode = mode
        defaults.set(mode, forKey: "chat.theme")
    }

    var oneSignalAppId: String? {
        get { defaults.string(forKey: "chat.onesignal_app_id") }
        set { defaults.set(newValue, forKey: "chat.onesignal_app_id") }
    }

    // MARK: Cache

    func conversations() -> [ConversationDto]? { read("conversations.json") }
    func saveConversations(_ list: [ConversationDto]) { write("conversations.json", Array(list.prefix(100))) }

    var requestsCount: Int {
        get { defaults.integer(forKey: "chat.requests_count") }
        set { defaults.set(newValue, forKey: "chat.requests_count") }
    }

    func messages(_ conversationId: String) -> [MessageDto]? { read(file(conversationId)) }
    func saveMessages(_ conversationId: String, _ list: [MessageDto]) { write(file(conversationId), Array(list.suffix(Self.messagesKept))) }

    func clear() {
        pendingWrites.values.forEach { $0.cancel() }
        pendingWrites.removeAll()
        if let files = try? FileManager.default.contentsOfDirectory(at: dir, includingPropertiesForKeys: nil) {
            files.forEach { try? FileManager.default.removeItem(at: $0) }
        }
        defaults.removeObject(forKey: "chat.theme")
        defaults.removeObject(forKey: "chat.drafts")
        drafts = [:]
        themeMode = "system"
    }

    private func file(_ id: String) -> String { "m-" + id.filter { $0.isLetter || $0.isNumber || $0 == "-" } + ".json" }

    private func read<T: Decodable>(_ name: String) -> T? {
        guard let data = try? Data(contentsOf: dir.appendingPathComponent(name)) else { return nil }
        return try? JSONDecoder().decode(T.self, from: data)
    }

    /// Debounced: a burst of updates (typing, receipts) writes the file once.
    private func write<T: Encodable>(_ name: String, _ value: T) {
        pendingWrites[name]?.cancel()
        let url = dir.appendingPathComponent(name)
        guard let data = try? JSONEncoder().encode(value) else { return }
        pendingWrites[name] = Task {
            try? await Task.sleep(nanoseconds: 400_000_000)
            guard !Task.isCancelled else { return }
            try? data.write(to: url, options: .atomic)
        }
    }
}
