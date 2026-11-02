import AVFoundation
import UIKit
import Foundation

// androidApp `chat/ChatSender.kt`, `chat/ChatOutbox.kt`, `chat/VideoTools.kt` (compress) — sending one message the
// same way from the open chat and from the outbox: JSON without files, multipart with them (videos shrunk first),
// the app-made uuid making it idempotent. Messages that haven't reached the server are kept on the phone (like
// WhatsApp's clock): something sent offline survives closing the app and goes as soon as there is a connection
// (Android: a WorkManager job; here when the app opens and each time the connection comes back), always with the
// same uuid so nothing arrives twice. The open chat sends its own; the outbox leaves those alone.

/// A file picked on this phone, ready to upload (and shown before the upload finishes).
struct ChatLocalFile: Codable, Equatable, Hashable {
    let url: URL
    let mime: String
    let name: String
}

/// What the composer is sending.
struct Outgoing: Codable, Equatable {
    var type: String
    var body: String?
    var files: [ChatLocalFile] = []
    /// Fields next to type / body (meta, poll options, amount…), as JSON values.
    var extra: [String: JSONAny] = [:]
    /// Video only: its poster frame.
    var thumbnail: URL?
}

extension JSONAny {
    /// A Swift value as JSONAny (for a message's extras).
    static func from(_ v: Any?) -> JSONAny {
        switch v {
        case nil: return .null
        case let x as JSONAny: return x
        case let x as String: return .string(x)
        case let x as Bool: return .bool(x)
        case let x as Int: return .number(Double(x))
        case let x as Int64: return .number(Double(x))
        case let x as Double: return .number(x)
        case let x as [Any?]: return .array(x.map { from($0) })
        case let x as [String]: return .array(x.map { .string($0) })
        case let x as [Int]: return .array(x.map { .number(Double($0)) })
        case let x as [String: Any?]: return .object(x.mapValues { from($0) })
        default: return .string("\(v!)")
        }
    }

    /// As a request value (whole numbers stay integers: 5000, not 5000.0).
    var request: JSONValue {
        switch self {
        case .string(let s): return .string(s)
        case .number(let n): return n == n.rounded() && abs(n) < 9e15 ? .int(Int(n)) : .double(n)
        case .bool(let b): return .bool(b)
        case .array(let a): return .array(a.map(\.request))
        case .object(let o): return .object(o.mapValues(\.request))
        case .null: return .null
        }
    }

    /// As a multipart text field (Laravel's boolean rule takes 1 / 0).
    var field: String? {
        switch self {
        case .string(let s): return s
        case .number(let n): return n == n.rounded() ? String(Int(n)) : String(n)
        case .bool(let b): return b ? "1" : "0"
        default: return nil
        }
    }
}

/// One message I sent that the server doesn't have yet.
struct OutboxItem: Codable, Equatable {
    let uuid: String
    let conversationId: String
    var out: Outgoing
    var replyTo: String?
    /// The server said no (validation, blocked…): kept as «failed» until I retry or delete it.
    var rejected = false
    /// The bubble as drawn while it waits.
    let message: MessageDto
}

/// The video was too big for the line it would go over (androidApp `VideoTooBigException`).
struct VideoTooBigError: Error {}

enum ChatSender {
    static func send(conversationId: String, uuid: String, out: Outgoing, replyId: String?, onProgress: ((Double) -> Void)? = nil) async throws -> ApiEnvelope<MessageDto> {
        if out.files.isEmpty {
            var body: [String: JSONValue] = ["type": .string(out.type), "uuid": .string(uuid)]
            if let b = out.body { body["body"] = .string(b) }
            if let replyId { body["reply_to"] = .string(replyId) }
            for (k, v) in out.extra where v != .null { body[k] = v.request }
            let sent: ApiEnvelope<MessageDto> = try await ApiClient.snake("mobile/v1/chat/conversations/\(conversationId)/messages", method: "POST", body: body)
            ChatUsage.messageSent()
            return sent
        }
        var fields: [String: String] = ["type": out.type, "uuid": uuid]
        if let b = out.body { fields["body"] = b }
        if let replyId { fields["reply_to"] = replyId }
        for (k, v) in out.extra {
            if case .array(let items) = v {
                for (i, item) in items.enumerated() { if let f = item.field { fields["\(k)[\(i)]"] = f } }
            } else if let f = v.field {
                fields[k] = f
            }
        }
        // Videos are shrunk first (the ring spins meanwhile), then uploaded.
        var files: [ApiClient.FilePart] = []
        if let t = out.thumbnail, let data = try? Data(contentsOf: t) { files.append(ApiClient.FilePart(field: "thumbnail", filename: "poster.jpg", mimeType: "image/jpeg", data: data)) }
        for f in out.files {
            let file = out.type == "video" ? await VideoTools.compress(f) : f
            files.append(ApiClient.FilePart(field: "files[]", filename: file.name, mimeType: file.mime, data: try Data(contentsOf: file.url)))
        }
        let data = try await ApiClient.uploadRaw("mobile/v1/chat/conversations/\(conversationId)/messages", fields: fields, files: files, progress: onProgress)
        ChatUsage.messageSent()
        return try ApiClient.snakeDecoder.decode(ApiEnvelope<MessageDto>.self, from: data)
    }
}

enum VideoTools {
    /// A smaller copy for sending (AVFoundation's medium preset, MP4); the original when that fails.
    static func compress(_ file: ChatLocalFile) async -> ChatLocalFile {
        let asset = AVURLAsset(url: file.url)
        // Data saver / «saver» quality / a slow mobile line: light (Android's `slow`); «high»: as it is.
        let quality = await MainActor.run { ChatDataSaver.shared.active ? "saver" : ChatPrefs.shared.uploadQuality }
        if quality == "high" { return file }
        let preset = quality == "saver" ? AVAssetExportPresetLowQuality : AVAssetExportPresetMediumQuality
        guard let export = AVAssetExportSession(asset: asset, presetName: preset) else { return file }
        let out = FileManager.default.temporaryDirectory.appendingPathComponent("chat-\(UUID().uuidString).mp4")
        export.outputURL = out
        export.outputFileType = .mp4
        export.shouldOptimizeForNetworkUse = true
        await export.export()
        guard export.status == .completed, let size = try? out.resourceValues(forKeys: [.fileSizeKey]).fileSize, size > 0,
              size < ((try? file.url.resourceValues(forKeys: [.fileSizeKey]).fileSize) ?? .max) else { return file }
        return ChatLocalFile(url: out, mime: "video/mp4", name: (file.name as NSString).deletingPathExtension + ".mp4")
    }

    /// The first frame as a JPEG (the poster shown before the server's copy arrives).
    static func poster(_ url: URL) async -> URL? {
        let gen = AVAssetImageGenerator(asset: AVURLAsset(url: url))
        gen.appliesPreferredTrackTransform = true
        gen.maximumSize = CGSize(width: 640, height: 640)
        guard let cg = try? gen.copyCGImage(at: .zero, actualTime: nil), let data = UIImage(cgImage: cg).jpegData(compressionQuality: 0.8) else { return nil }
        let out = FileManager.default.temporaryDirectory.appendingPathComponent("poster-\(UUID().uuidString).jpg")
        return (try? data.write(to: out)) != nil ? out : nil
    }
}

@MainActor
final class ChatOutbox {
    static let shared = ChatOutbox()

    private let dir: URL = {
        let d = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0].appendingPathComponent("chat-outbox", isDirectory: true)
        try? FileManager.default.createDirectory(at: d, withIntermediateDirectories: true)
        return d
    }()
    private var items: [OutboxItem] = []
    /// Conversations open on screen right now (their own page is sending).
    private var open = Set<String>()
    private var flushing = false

    private init() {
        items = (try? JSONDecoder().decode([OutboxItem].self, from: Data(contentsOf: dir.appendingPathComponent("outbox.json")))) ?? []
    }

    /// Keep a new message until the server has it; its files move into the outbox folder.
    func add(uuid: String, conversationId: String, out: Outgoing, replyId: String?, message: MessageDto) -> Outgoing {
        let home = dir.appendingPathComponent(uuid, isDirectory: true)
        try? FileManager.default.createDirectory(at: home, withIntermediateDirectories: true)
        func keep(_ url: URL) -> URL {
            let target = home.appendingPathComponent(url.lastPathComponent)
            if url == target { return url }
            try? FileManager.default.removeItem(at: target)
            return (try? FileManager.default.copyItem(at: url, to: target)) != nil ? target : url
        }
        var stored = out
        stored.files = out.files.map { ChatLocalFile(url: keep($0.url), mime: $0.mime, name: $0.name) }
        stored.thumbnail = out.thumbnail.map(keep)
        items.removeAll { $0.uuid == uuid }
        items.append(OutboxItem(uuid: uuid, conversationId: conversationId, out: stored, replyTo: replyId, message: message))
        save()
        return stored
    }

    /// The server has it: forget it and its files.
    func sent(_ uuid: String) {
        if items.contains(where: { $0.uuid == uuid }) {
            items.removeAll { $0.uuid == uuid }
            save()
        }
        try? FileManager.default.removeItem(at: dir.appendingPathComponent(uuid, isDirectory: true))
    }

    func rejected(_ uuid: String) { update(uuid) { $0.rejected = true } }
    func retrying(_ uuid: String) { update(uuid) { $0.rejected = false } }
    func pending(for conversationId: String) -> [OutboxItem] { items.filter { $0.conversationId == conversationId } }

    func opened(_ conversationId: String) { open.insert(conversationId) }

    /// The chat closed: whatever it didn't get to send goes in the background.
    func closed(_ conversationId: String) {
        open.remove(conversationId)
        Task { await flush() }
    }

    /// Sign-out: another person may use this phone.
    func clear() {
        items.removeAll()
        save()
        (try? FileManager.default.contentsOfDirectory(at: dir, includingPropertiesForKeys: nil))?.filter(\.hasDirectoryPath).forEach { try? FileManager.default.removeItem(at: $0) }
    }

    /// Send everything waiting (except chats open on screen — they send their own).
    func flush() async {
        guard !flushing, AuthSession.shared.token != nil else { return }
        flushing = true
        defer { flushing = false }
        for item in items.filter({ !$0.rejected && !open.contains($0.conversationId) }) {
            do {
                let delivered = try await ChatSender.send(conversationId: item.conversationId, uuid: item.uuid, out: item.out, replyId: item.replyTo).data
                sent(item.uuid)
                // Opening that chat offline later shows it as sent.
                if let delivered {
                    let cached = ChatStore.shared.messages(item.conversationId) ?? []
                    ChatStore.shared.saveMessages(item.conversationId, cached.filter { $0.id != delivered.id } + [delivered])
                }
            } catch {
                // The server answered «no»: final, it waits in the chat as failed. No answer at all: try later.
                if error.apiFailure.httpStatus != nil {
                    if error.apiFailure.httpStatus == 401 { return }
                    rejected(item.uuid)
                } else {
                    return
                }
            }
        }
    }

    private func update(_ uuid: String, _ change: (inout OutboxItem) -> Void) {
        guard let i = items.firstIndex(where: { $0.uuid == uuid }) else { return }
        change(&items[i])
        save()
    }

    private func save() {
        if let data = try? JSONEncoder().encode(items) { try? data.write(to: dir.appendingPathComponent("outbox.json"), options: .atomic) }
    }
}
