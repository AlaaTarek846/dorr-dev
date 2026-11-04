import AVFoundation
import PhotosUI
import SwiftUI
import UniformTypeIdentifiers

// androidApp `aichat/AiConversationPage.kt` — the DORR AI conversation: the thread and the composer, talking to
// /api/user/v1/ai-chat/conversations/{id}/messages. The message just sent shows at once (with its picked image or
// recorded note), a «thinking» bubble narrates the wait by the phone's clock, code renders in its own card, voice
// notes play with a waveform, sources fold under a reply, and an image opens full screen (zoom, save).

/// A file picked or recorded for the next message.
struct AiLocalFile: Equatable {
    let url: URL
    let mime: String
    let name: String
}

struct AiConversationPage: View {
    @Environment(\.wa) private var wa
    let conversationId: Int
    let onExit: () -> Void
    let onOpenHistory: () -> Void
    let onNewChat: () -> Void
    let onOpenVoice: () -> Void
    let onOpenSubscription: () -> Void
    let onOpenSettings: () -> Void
    /// A search result opens the conversation at its message, once.
    var focusMessageId: Int?
    /// A home starter: the composer already holds its opening words (and, for «analyse a file», the attach sheet).
    var initialInput: String?
    var openAttachments = false

    @State private var title: String?
    @State private var messages: [AiMessageDto] = []
    @State private var loading = true
    @State private var loadError: String?
    @State private var usage: AiUsageDto?
    @State private var input = ""
    @State private var pendingAttachment: AiLocalFile?
    @State private var sending = false
    @State private var sendStarted = Date()
    @State private var sendError: String?
    @State private var idempotencyKey = UUID().uuidString
    @State private var attachSheet = false
    @State private var pickingPhoto = false
    @State private var photoItem: PhotosPickerItem?
    @State private var pickingDocument = false
    @State private var viewer: AiAttachmentDto?
    @State private var pendingUserMessage: AiMessageDto?
    @State private var typingIsImageLike = false
    @State private var pendingFocus: Int?
    @StateObject private var recorder = AiVoiceRecorder()

    private var blocked: Bool { usage?.allowed == false }

    private func load() async {
        loading = true
        loadError = nil
        do {
            let conv = try await AiChatApi.conversation(conversationId).data
            title = conv?.title
            messages = (conv?.messages ?? []).sorted { $0.id < $1.id }
        } catch {
            loadError = error.apiFailure.message
        }
        if let u = try? await AiChatApi.usage().data { usage = u }
        loading = false
    }

    /// Shared by «send» and the mic's send-on-stop — the same request, failure handling and idempotency key.
    private func sendPayload(_ typed: String, _ attachment: AiLocalFile?, restoreOnFailure: Bool) {
        if typed.isEmpty && attachment == nil { return }
        if sending || blocked { return }
        // The server needs a message even on an attachment-only send — a short honest caption; a voice note stays
        // empty (the server puts its transcript there).
        let isVoice = attachment?.mime.hasPrefix("audio/") == true
        let text = isVoice ? typed : (typed.isEmpty ? L("ai_attachment_caption") : typed)
        let imageWords = ["صورة", "صوره", "ارسم", "image", "picture", "photo", "draw"]
        typingIsImageLike = attachment?.mime.hasPrefix("image/") == true || imageWords.contains { typed.localizedCaseInsensitiveContains($0) }
        sending = true
        sendStarted = Date()
        sendError = nil
        pendingUserMessage = AiMessageDto(
            id: Int.min, role: "user", content: text,
            attachments: attachment.map { [AiAttachmentDto(id: -1, fileName: $0.name, mimeType: $0.mime, isImage: $0.mime.hasPrefix("image/"), localUrl: $0.url)] }
        )
        let key = idempotencyKey
        Task {
            do {
                let part = try attachment.map { ApiClient.FilePart(field: "attachment", filename: $0.name, mimeType: $0.mime, data: try Data(contentsOf: $0.url)) }
                let envelope = try await AiChatApi.sendMessage(conversationId, key: key, fields: ["message": text], attachment: part)
                if let data = envelope.data {
                    var merged = messages
                    for m in [data.userMessage, data.assistantMessage].compactMap({ $0 }) where !merged.contains(where: { $0.id == m.id }) { merged.append(m) }
                    messages = merged.sorted { $0.id < $1.id }
                    if let t = data.conversation?.title { title = t }
                    if let u = data.usage { usage = u }
                    idempotencyKey = UUID().uuidString
                } else {
                    sendError = envelope.message
                    if restoreOnFailure { input = typed; pendingAttachment = attachment }
                }
            } catch {
                sendError = error.apiFailure.message ?? L("ai_send_failed")
                if restoreOnFailure { input = typed; pendingAttachment = attachment }
            }
            pendingUserMessage = nil
            sending = false
        }
    }

    private func send() {
        let typed = input.trimmingCharacters(in: .whitespacesAndNewlines)
        let attachment = pendingAttachment
        input = ""
        pendingAttachment = nil
        sendPayload(typed, attachment, restoreOnFailure: true)
    }

    var body: some View {
        let c = wa.ai
        VStack(spacing: 0) {
            topBar
            banner
            // Context carried over from earlier chats (spec 130): shown, readable and deletable.
            AiCapsuleBanner(conversationId: conversationId)
            thread.frame(maxHeight: .infinity)
            if let sendError {
                Text(verbatim: sendError).font(.dorr(12)).foregroundStyle(Color(hex: 0xB91C1C)).frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.horizontal, 16).padding(.vertical, 4)
            }
            if let file = pendingAttachment {
                HStack(spacing: 6) {
                    Image(systemName: file.mime.hasPrefix("image/") ? "photo.fill" : "doc.text.fill").font(.system(size: 14)).foregroundStyle(c.red)
                    Text(verbatim: file.name).font(.dorr(12)).foregroundStyle(c.ink).lineLimit(1).frame(maxWidth: 160, alignment: .leading)
                    Button { pendingAttachment = nil } label: { Image(systemName: "xmark").font(.system(size: 11, weight: .bold)).foregroundStyle(c.mut) }.buttonStyle(.plain)
                }
                .padding(.horizontal, 10).padding(.vertical, 6)
                .background(c.surface, in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 14).padding(.top, 6)
            }
            if recorder.recording { recordingBar } else { composer }
        }
        .background(c.bg.ignoresSafeArea())
        .task(id: conversationId) {
            pendingFocus = focusMessageId
            if let initialInput, input.isEmpty { input = initialInput }
            if openAttachments { attachSheet = true }
            await load()
        }
        .dorrDialog(isPresented: $attachSheet) {
            AiAttachSheet(
                onPickImage: { attachSheet = false; Task { try? await Task.sleep(nanoseconds: 350_000_000); pickingPhoto = true } },
                onPickDocument: { attachSheet = false; Task { try? await Task.sleep(nanoseconds: 350_000_000); pickingDocument = true } }
            )
        }
        .photosPicker(isPresented: $pickingPhoto, selection: $photoItem, matching: .any(of: [.images, .videos]))
        .onChange(of: photoItem) { item in
            guard let item else { return }
            photoItem = nil
            Task { if let f = await aiCopyPicked(item) { pendingAttachment = f } }
        }
        // Matches the server's accepted kinds exactly (no legacy .xls).
        .fileImporter(isPresented: $pickingDocument, allowedContentTypes: aiDocumentTypes) { result in
            if case .success(let url) = result, let f = aiCopyDocument(url) { pendingAttachment = f }
        }
        .fullScreenCover(item: Binding(get: { viewer.map { AiViewerItem(attachment: $0) } }, set: { viewer = $0?.attachment })) { item in
            AiImageViewer(attachment: item.attachment) { viewer = nil }
        }
    }

    // MARK: Top bar & banners

    private var topBar: some View {
        let c = wa.ai
        return HStack(spacing: 0) {
            AiRoundIcon(icon: "chevron.left", tint: c.ink, size: 17, action: onExit)
            Image(systemName: "sparkles").font(.system(size: 15)).foregroundStyle(.white).frame(width: 34, height: 34).background(c.red, in: Circle())
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: title?.isEmpty == false ? title! : L("ai_title")).font(.dorr(15, .heavy)).foregroundStyle(c.ink).lineLimit(1)
                Text(L("ai_subtitle")).font(.dorr(11)).foregroundStyle(c.mut)
            }
            .padding(.leading, 10)
            Spacer(minLength: 0)
            AiRoundIcon(icon: "sparkles", tint: c.mut, size: 17, action: onNewChat).accessibilityLabel(L("ai_new_chat"))
            AiRoundIcon(icon: "phone.fill", tint: c.mut, size: 16, action: onOpenVoice).accessibilityLabel(L("ai_voice_mode"))
            AiRoundIcon(icon: "clock.arrow.circlepath", tint: c.mut, size: 17, action: onOpenHistory).accessibilityLabel(L("ai_history_title"))
            AiRoundIcon(icon: "gearshape.fill", tint: c.mut, size: 17, action: onOpenSettings).accessibilityLabel(L("ai_settings_title"))
        }
        .padding(.horizontal, 8).padding(.vertical, 8)
    }

    @ViewBuilder private var banner: some View {
        let c = wa.ai
        if blocked {
            let offers = ["trial_ended", "no_plan", "subscription_suspended"].contains(usage?.reason ?? "")
            HStack(spacing: 8) {
                Image(systemName: "lock.fill").font(.system(size: 13))
                Text(verbatim: aiUsageReasonText(usage?.reason)).font(.dorr(12.5)).frame(maxWidth: .infinity, alignment: .leading)
                if offers {
                    Button(action: onOpenSubscription) { Text(L("ai_subscription_subscribe")).font(.dorr(12.5, .bold)) }.buttonStyle(.plain)
                }
            }
            .foregroundStyle(Color(hex: 0xB91C1C))
            .padding(.horizontal, 16).padding(.vertical, 10)
            .background(Color(hex: 0xFEE2E2))
        } else if usage?.planIsTrial == true, (usage?.remainingSeconds ?? 0) > 0 {
            Text(verbatim: L("ai_trial_remaining", Int((usage?.remainingSeconds ?? 0) / 60))).font(.dorr(11.5, .semibold)).foregroundStyle(c.red)
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 16).padding(.vertical, 7)
                .background(c.red.opacity(0.08))
        }
    }

    // MARK: Thread

    @ViewBuilder private var thread: some View {
        let c = wa.ai
        if loading && messages.isEmpty {
            ProgressView().tint(c.red).frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if let loadError, messages.isEmpty {
            Text(verbatim: loadError).font(.dorr(13)).foregroundStyle(c.mut).frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if messages.isEmpty && !sending && pendingUserMessage == nil {
            VStack(spacing: 10) {
                Image(systemName: "sparkles").font(.system(size: 38)).foregroundStyle(c.red.opacity(0.5))
                Text(L("ai_empty_conversation")).font(.dorr(13)).foregroundStyle(c.mut)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        } else {
            ScrollViewReader { reader in
                ScrollView {
                    LazyVStack(spacing: 10) {
                        ForEach(messages) { m in
                            AiMessageBubble(message: m, onImageTap: { viewer = $0 }).id(m.id)
                        }
                        if let pending = pendingUserMessage { AiMessageBubble(message: pending, onImageTap: { _ in }).id("pending") }
                        if sending { AiTypingBubble(started: sendStarted, isImageLike: typingIsImageLike).id("typing") }
                        Color.clear.frame(height: 1).id("end")
                    }
                    .padding(.horizontal, 12).padding(.vertical, 10)
                }
                .scrollDismissesKeyboard(.interactively)
                .onAppear { reader.scrollTo("end", anchor: .bottom) }
                .onChange(of: messages.count) { _ in
                    if let id = pendingFocus, messages.contains(where: { $0.id == id }), !sending {
                        reader.scrollTo(id, anchor: .center)
                        pendingFocus = nil
                    } else {
                        withAnimation { reader.scrollTo("end", anchor: .bottom) }
                    }
                }
                .onChange(of: sending) { _ in withAnimation { reader.scrollTo("end", anchor: .bottom) } }
            }
        }
    }

    // MARK: Composer

    private var composer: some View {
        let c = wa.ai
        let hasContent = !input.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || pendingAttachment != nil
        let canSend = !sending && !blocked && hasContent
        return HStack(alignment: .bottom, spacing: 8) {
            Button { attachSheet = true } label: {
                Image(systemName: "paperclip").font(.system(size: 19)).foregroundStyle(c.mut).frame(width: 42, height: 42)
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("ai_attach"))
            TextField(L("ai_composer_hint"), text: $input, axis: .vertical)
                .lineLimit(1...6).font(.dorr(14)).foregroundStyle(c.ink).tint(c.red)
                .padding(.horizontal, 16).padding(.vertical, 11)
                .frame(minHeight: 42)
                .background(c.surface, in: RoundedRectangle(cornerRadius: 21, style: .continuous))
            Button {
                if hasContent { send() } else { recorder.start() }
            } label: {
                ZStack {
                    if sending {
                        ProgressView().tint(.white).scaleEffect(0.8)
                    } else {
                        Image(systemName: hasContent ? "paperplane.fill" : "mic.fill").font(.system(size: 17)).foregroundStyle(.white)
                            .flipsForRightToLeftLayoutDirection(hasContent)
                    }
                }
                .frame(width: 42, height: 42)
                .background((hasContent ? canSend : !blocked) ? c.red : c.mut.opacity(0.25), in: Circle())
            }
            .buttonStyle(.plain)
            .disabled(hasContent ? !canSend : (sending || blocked))
            .accessibilityLabel(L(hasContent ? "ai_send" : "ai_record_voice"))
        }
        .padding(10)
    }

    private var recordingBar: some View {
        let c = wa.ai
        return HStack(spacing: 8) {
            Button { recorder.cancel() } label: {
                Image(systemName: "xmark").font(.system(size: 17, weight: .semibold)).foregroundStyle(c.mut).frame(width: 42, height: 42)
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("ai_cancel_recording"))
            TimelineView(.periodic(from: .now, by: 1)) { ctx in
                let s = Int(ctx.date.timeIntervalSince(recorder.startedAt ?? ctx.date))
                HStack(spacing: 8) {
                    Circle().fill(Color(hex: 0xDC2626)).frame(width: 9, height: 9)
                    Text(verbatim: String(format: "%d:%02d", s / 60, s % 60)).font(.dorr(14)).foregroundStyle(c.ink).environment(\.layoutDirection, .leftToRight)
                    Text(L("ai_recording_hint")).font(.dorr(12)).foregroundStyle(c.mut).lineLimit(1)
                    Spacer(minLength: 0)
                }
                .padding(.horizontal, 16).frame(minHeight: 42)
                .background(c.surface, in: RoundedRectangle(cornerRadius: 21, style: .continuous))
            }
            Button {
                if let clip = recorder.finish() { sendPayload("", AiLocalFile(url: clip, mime: "audio/mp4", name: clip.lastPathComponent), restoreOnFailure: false) }
            } label: {
                Image(systemName: "paperplane.fill").font(.system(size: 16)).foregroundStyle(.white).flipsForRightToLeftLayoutDirection(true)
                    .frame(width: 42, height: 42).background(c.red, in: Circle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("ai_stop_recording"))
        }
        .padding(10)
    }
}

private struct AiViewerItem: Identifiable {
    let attachment: AiAttachmentDto
    var id: String { attachment.url ?? "\(attachment.id)" }
}

func aiUsageReasonText(_ reason: String?) -> String {
    switch reason {
    case "trial_ended": return L("ai_usage_trial_ended")
    case "subscription_suspended": return L("ai_subscription_suspended_banner")
    case "cooldown": return L("ai_usage_cooldown")
    case "limit_reached": return L("ai_usage_limit_reached")
    case "blocked": return L("ai_usage_blocked")
    case "no_plan": return L("ai_usage_no_plan")
    default: return L("ai_usage_unavailable")
    }
}

let aiDocumentTypes: [UTType] = [
    .pdf, .plainText, .commaSeparatedText,
    UTType("com.microsoft.word.doc"), UTType("org.openxmlformats.wordprocessingml.document"),
    UTType("org.openxmlformats.spreadsheetml.sheet"),
].compactMap { $0 }

/// A picked photo or video, copied into the cache with its real type.
func aiCopyPicked(_ item: PhotosPickerItem) async -> AiLocalFile? {
    guard let data = try? await item.loadTransferable(type: Data.self) else { return nil }
    let type = item.supportedContentTypes.first
    let isVideo = type?.conforms(to: .movie) == true
    let ext = type?.preferredFilenameExtension ?? (isVideo ? "mp4" : "jpg")
    let mime = type?.preferredMIMEType ?? (isVideo ? "video/mp4" : "image/jpeg")
    let url = FileManager.default.temporaryDirectory.appendingPathComponent("\(isVideo ? "video" : "photo")-\(UUID().uuidString.prefix(8)).\(ext)")
    guard (try? data.write(to: url)) != nil else { return nil }
    return AiLocalFile(url: url, mime: mime, name: url.lastPathComponent)
}

/// A picked document, copied out of its security scope.
func aiCopyDocument(_ url: URL) -> AiLocalFile? {
    let scoped = url.startAccessingSecurityScopedResource()
    defer { if scoped { url.stopAccessingSecurityScopedResource() } }
    let dest = FileManager.default.temporaryDirectory.appendingPathComponent(url.lastPathComponent)
    try? FileManager.default.removeItem(at: dest)
    guard (try? FileManager.default.copyItem(at: url, to: dest)) != nil else { return nil }
    let mime = UTType(filenameExtension: url.pathExtension)?.preferredMIMEType ?? "application/octet-stream"
    return AiLocalFile(url: dest, mime: mime, name: url.lastPathComponent)
}

// MARK: - Voice notes

/// Records a voice note as AAC in an .m4a (`audio/mp4`, like Android's recorder).
@MainActor
final class AiVoiceRecorder: NSObject, ObservableObject {
    @Published private(set) var recording = false
    @Published private(set) var startedAt: Date?
    private var recorder: AVAudioRecorder?

    func start() {
        AVAudioSession.sharedInstance().requestRecordPermission { granted in
            guard granted else { return }
            Task { @MainActor in self.begin() }
        }
    }

    private func begin() {
        let session = AVAudioSession.sharedInstance()
        try? session.setCategory(.playAndRecord, mode: .default, options: [.defaultToSpeaker])
        try? session.setActive(true)
        let url = FileManager.default.temporaryDirectory.appendingPathComponent("voice-\(UUID().uuidString.prefix(8)).m4a")
        let settings: [String: Any] = [AVFormatIDKey: kAudioFormatMPEG4AAC, AVSampleRateKey: 44_100, AVNumberOfChannelsKey: 1, AVEncoderAudioQualityKey: AVAudioQuality.high.rawValue]
        guard let r = try? AVAudioRecorder(url: url, settings: settings), r.record() else { return }
        recorder = r
        startedAt = Date()
        recording = true
    }

    /// Stops and returns the clip (nil when nothing usable was recorded).
    func finish() -> URL? {
        guard let r = recorder else { return nil }
        let long = r.currentTime >= 0.5
        r.stop()
        recorder = nil
        recording = false
        startedAt = nil
        if !long { try? FileManager.default.removeItem(at: r.url); return nil }
        return r.url
    }

    func cancel() {
        recorder?.stop()
        recorder?.deleteRecording()
        recorder = nil
        recording = false
        startedAt = nil
    }
}

/// Plays one voice note at a time (Android's `VoicePlayer`): the current one, its progress and position.
@MainActor
final class AiVoicePlayer: ObservableObject {
    static let shared = AiVoicePlayer()
    @Published private(set) var playingId: String?
    @Published private(set) var playing = false
    @Published private(set) var progress: Double = 0
    @Published private(set) var positionMs: Int = 0
    private var player: AVPlayer?
    private var observer: Any?
    private var endObserver: NSObjectProtocol?

    func toggle(_ url: URL) {
        let id = url.absoluteString
        if playingId == id, let player {
            if playing { player.pause() } else { player.play() }
            playing.toggle()
            return
        }
        stop()
        try? AVAudioSession.sharedInstance().setCategory(.playback)
        let item = AVPlayerItem(asset: AVURLAsset(url: url, options: ["AVURLAssetHTTPHeaderFieldsKey": ["ngrok-skip-browser-warning": "1"]]))
        let p = AVPlayer(playerItem: item)
        observer = p.addPeriodicTimeObserver(forInterval: CMTime(value: 1, timescale: 10), queue: .main) { t in
            Task { @MainActor in
                guard AiVoicePlayer.shared.playingId == id, let d = p.currentItem?.duration.seconds, d.isFinite, d > 0 else { return }
                AiVoicePlayer.shared.progress = t.seconds / d
                AiVoicePlayer.shared.positionMs = Int(t.seconds * 1000)
            }
        }
        endObserver = NotificationCenter.default.addObserver(forName: .AVPlayerItemDidPlayToEndTime, object: item, queue: .main) { _ in
            Task { @MainActor in if AiVoicePlayer.shared.playingId == id { AiVoicePlayer.shared.stop() } }
        }
        player = p
        playingId = id
        playing = true
        p.play()
    }

    func seek(_ url: URL, _ fraction: Double) {
        guard playingId == url.absoluteString, let player, let d = player.currentItem?.duration.seconds, d.isFinite else { return }
        player.seek(to: CMTime(seconds: d * fraction, preferredTimescale: 600))
    }

    func stop() {
        player?.pause()
        if let observer { player?.removeTimeObserver(observer) }
        if let endObserver { NotificationCenter.default.removeObserver(endObserver) }
        observer = nil
        endObserver = nil
        player = nil
        playingId = nil
        playing = false
        progress = 0
        positionMs = 0
    }
}

private func aiDurationText(_ ms: Int) -> String {
    let s = ms / 1000
    return String(format: "%d:%02d", s / 60, s % 60)
}

// MARK: - A message

/// A reply mixes prose with ```fenced``` code: prose with **bold** / *italic* / `code`, code in its own card.
private struct AiContentSegment: Hashable {
    let isCode: Bool
    let language: String?
    let text: String
}

private func splitAiContent(_ content: String) -> [AiContentSegment] {
    guard let regex = try? NSRegularExpression(pattern: "```([a-zA-Z0-9_+#.-]*)\\n?([\\s\\S]*?)```") else { return [AiContentSegment(isCode: false, language: nil, text: content)] }
    let ns = content as NSString
    var out: [AiContentSegment] = []
    var last = 0
    for m in regex.matches(in: content, range: NSRange(location: 0, length: ns.length)) {
        if m.range.location > last { out.append(AiContentSegment(isCode: false, language: nil, text: ns.substring(with: NSRange(location: last, length: m.range.location - last)))) }
        let lang = ns.substring(with: m.range(at: 1)).trimmingCharacters(in: .whitespaces)
        let code = ns.substring(with: m.range(at: 2)).trimmingCharacters(in: CharacterSet(charactersIn: "\n"))
        out.append(AiContentSegment(isCode: true, language: lang.isEmpty ? nil : lang, text: code))
        last = m.range.location + m.range.length
    }
    if last < ns.length { out.append(AiContentSegment(isCode: false, language: nil, text: ns.substring(from: last))) }
    return out
}

/// The small inline-markdown subset the server's documents use: bold, italic, inline code; links as "label (url)".
func aiInlineMarkdown(_ text: String) -> AttributedString {
    var linked = text
    if let link = try? NSRegularExpression(pattern: "\\[([^\\]]+)]\\(([^)\\s]+)\\)") {
        linked = link.stringByReplacingMatches(in: linked, range: NSRange(location: 0, length: (linked as NSString).length), withTemplate: "$1 ($2)")
    }
    guard let regex = try? NSRegularExpression(pattern: "(\\*\\*.+?\\*\\*|__.+?__|`.+?`|\\*[^*\\n]+?\\*|_[^_\\n]+?_)", options: [.dotMatchesLineSeparators]) else { return AttributedString(linked) }
    let ns = linked as NSString
    var out = AttributedString()
    var last = 0
    for m in regex.matches(in: linked, range: NSRange(location: 0, length: ns.length)) {
        if m.range.location > last { out += AttributedString(ns.substring(with: NSRange(location: last, length: m.range.location - last))) }
        let token = ns.substring(with: m.range)
        var piece: AttributedString
        if token.hasPrefix("**") || token.hasPrefix("__") {
            piece = AttributedString(String(token.dropFirst(2).dropLast(2)))
            piece.font = .dorr(14, .bold)
        } else if token.hasPrefix("`") {
            piece = AttributedString(String(token.dropFirst().dropLast()))
            piece.font = .system(size: 13, design: .monospaced)
        } else {
            piece = AttributedString(String(token.dropFirst().dropLast()))
            piece.font = .dorr(14).italic()
        }
        out += piece
        last = m.range.location + m.range.length
    }
    if last < ns.length { out += AttributedString(ns.substring(from: last)) }
    return out
}

private struct AiMessageContent: View {
    let content: String
    let color: Color

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            ForEach(Array(splitAiContent(content).enumerated()), id: \.offset) { _, s in
                if s.isCode {
                    if !s.text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { AiCodeBlock(code: s.text, language: s.language) }
                } else if !s.text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                    Text(aiInlineMarkdown(s.text.trimmingCharacters(in: CharacterSet(charactersIn: "\n")))).font(.dorr(14)).foregroundStyle(color).lineSpacing(4)
                        .textSelection(.enabled)
                }
            }
        }
    }
}

/// Code on a fixed dark «editor» card: the language, a copy button, and a body that scrolls sideways.
private struct AiCodeBlock: View {
    let code: String
    let language: String?
    @State private var copied = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack {
                Text(verbatim: language?.lowercased() ?? "code").font(.system(size: 11.5, weight: .semibold, design: .monospaced)).foregroundStyle(Color(hex: 0x9CA3AF))
                Spacer()
                Button {
                    UIPasteboard.general.string = code
                    AppToast.shared.show(L("ai_code_copied"))
                    copied = true
                    Task { try? await Task.sleep(nanoseconds: 1_600_000_000); copied = false }
                } label: {
                    HStack(spacing: 4) {
                        Image(systemName: copied ? "checkmark" : "doc.on.doc").font(.system(size: 12))
                        Text(L(copied ? "ai_copied_done" : "ai_copy")).font(.dorr(11, .medium))
                    }
                    .foregroundStyle(copied ? Color(hex: 0x34D399) : Color(hex: 0x9CA3AF))
                    .padding(.horizontal, 7).padding(.vertical, 3)
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("ai_copy_code"))
            }
            .padding(.horizontal, 12).padding(.vertical, 7)
            .background(Color(hex: 0x14141F))
            ScrollView(.horizontal, showsIndicators: false) {
                Text(verbatim: code).font(.system(size: 12.5, design: .monospaced)).foregroundStyle(Color(hex: 0xE5E7EB)).lineSpacing(4)
                    .fixedSize(horizontal: true, vertical: false)
                    .padding(.horizontal, 12).padding(.vertical, 10)
            }
        }
        .environment(\.layoutDirection, .leftToRight)
        .background(Color(hex: 0x1E1E2E))
        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
    }
}

struct AiMessageBubble: View {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    let message: AiMessageDto
    let onImageTap: (AiAttachmentDto) -> Void
    @State private var transcriptOpen = false

    var body: some View {
        let c = wa.ai
        let isUser = message.isUser
        let isError = message.isError == true
        let textColor: Color = isError ? Color(hex: 0xB91C1C) : isUser ? .white : c.ink
        let attachments = message.attachments ?? []
        let hasAudio = attachments.contains { $0.mimeType?.hasPrefix("audio/") == true }
        HStack {
            if isUser { Spacer(minLength: 40) }
            VStack(alignment: .leading, spacing: 6) {
                ForEach(Array(attachments.enumerated()), id: \.offset) { _, a in attachment(a, isUser: isUser) }
                if hasAudio && !message.content.trimmingCharacters(in: .whitespaces).isEmpty {
                    Button { transcriptOpen.toggle() } label: {
                        HStack(spacing: 2) {
                            Image(systemName: transcriptOpen ? "chevron.up" : "chevron.down").font(.system(size: 11, weight: .semibold))
                            Text(L(transcriptOpen ? "ai_voice_transcript_hide" : "ai_voice_transcript_show")).font(.dorr(12))
                        }
                        .foregroundStyle(isUser ? .white.opacity(0.85) : c.mut)
                    }
                    .buttonStyle(.plain)
                    if transcriptOpen { AiMessageContent(content: message.content, color: textColor) }
                } else if !message.content.trimmingCharacters(in: .whitespaces).isEmpty {
                    AiMessageContent(content: message.content, color: textColor)
                }
                if let file = message.generatedFile, let url = file.url {
                    Button { if let u = ApiClient.mediaUrl(url) { UIApplication.shared.open(u) } } label: {
                        fileChip(icon: "arrow.down.circle.fill", name: file.name ?? "", isUser: isUser)
                    }
                    .buttonStyle(.plain)
                }
                if let warnings = message.verificationWarnings, !warnings.isEmpty {
                    Text(verbatim: warnings.joined(separator: " • ")).font(.dorr(10.5)).foregroundStyle(isUser ? .white.opacity(0.85) : c.mut)
                }
                // Sources for this reply, folded — never on my own message.
                if !isUser, let citations = message.citations, !citations.isEmpty { AiCitations(citations: citations) }
            }
            .padding(.horizontal, 14).padding(.vertical, 10)
            .environment(\.layoutDirection, direction)
            .frame(maxWidth: 300, alignment: .leading)
            .background(isError ? Color(hex: 0xFEE2E2) : isUser ? c.red : c.surface,
                        in: UnevenBubble(mine: isUser))
            // Long press copies the text (the person-to-person chat's convention).
            .contextMenu {
                if !message.content.isEmpty {
                    Button {
                        UIPasteboard.general.string = message.content
                        AppToast.shared.show(L("ai_message_copied"))
                    } label: { Label(L("ai_copy"), systemImage: "doc.on.doc") }
                }
            }
            if !isUser { Spacer(minLength: 40) }
        }
        .environment(\.layoutDirection, .leftToRight)
    }

    @ViewBuilder private func attachment(_ a: AiAttachmentDto, isUser: Bool) -> some View {
        let source = a.url.flatMap { ApiClient.mediaUrl($0) } ?? a.localUrl
        if a.isImage == true, let source {
            Button { onImageTap(a) } label: {
                AsyncImage(url: source) { phase in
                    if let image = phase.image { image.resizable().scaledToFill() } else { Color.black.opacity(0.06) }
                }
                .frame(width: 220, height: 160)
                .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            .buttonStyle(.plain)
            .disabled(a.url == nil)
        } else if a.mimeType?.hasPrefix("audio/") == true, let source {
            AiVoiceNote(url: source, seed: a.id, mine: isUser)
        } else if source != nil {
            Button { if let u = a.url.flatMap({ ApiClient.mediaUrl($0) }) { UIApplication.shared.open(u) } } label: {
                fileChip(icon: "doc.text.fill", name: a.fileName ?? "", isUser: isUser)
            }
            .buttonStyle(.plain)
        }
    }

    private func fileChip(icon: String, name: String, isUser: Bool) -> some View {
        let c = wa.ai
        return HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 14)).foregroundStyle(isUser ? .white : c.red)
            Text(verbatim: name).font(.dorr(12)).foregroundStyle(isUser ? .white : c.ink).lineLimit(1)
        }
        .padding(.horizontal, 10).padding(.vertical, 8)
        .background(Color.black.opacity(0.06), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
    }
}

/// 18 all round, the corner by the sender 4.
private struct UnevenBubble: Shape {
    let mine: Bool

    func path(in rect: CGRect) -> Path {
        let big: CGFloat = 18, small: CGFloat = 4
        let bl = mine ? big : small, br = mine ? small : big
        var p = Path()
        p.move(to: CGPoint(x: rect.minX + big, y: rect.minY))
        p.addLine(to: CGPoint(x: rect.maxX - big, y: rect.minY))
        p.addQuadCurve(to: CGPoint(x: rect.maxX, y: rect.minY + big), control: CGPoint(x: rect.maxX, y: rect.minY))
        p.addLine(to: CGPoint(x: rect.maxX, y: rect.maxY - br))
        p.addQuadCurve(to: CGPoint(x: rect.maxX - br, y: rect.maxY), control: CGPoint(x: rect.maxX, y: rect.maxY))
        p.addLine(to: CGPoint(x: rect.minX + bl, y: rect.maxY))
        p.addQuadCurve(to: CGPoint(x: rect.minX, y: rect.maxY - bl), control: CGPoint(x: rect.minX, y: rect.maxY))
        p.addLine(to: CGPoint(x: rect.minX, y: rect.minY + big))
        p.addQuadCurve(to: CGPoint(x: rect.minX + big, y: rect.minY), control: CGPoint(x: rect.minX, y: rect.minY))
        p.closeSubpath()
        return p
    }
}

/// A voice note: play / pause, a waveform seeded by the note (no real samples exist), tap to seek, the length.
private struct AiVoiceNote: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var player = AiVoicePlayer.shared
    let url: URL
    let seed: Int
    let mine: Bool
    @State private var durationMs: Int?

    var body: some View {
        let c = wa.ai
        let current = player.playingId == url.absoluteString
        let playing = current && player.playing
        let progress = current ? player.progress : 0
        let fg: Color = mine ? .white : c.red
        let track: Color = mine ? .white.opacity(0.35) : c.red.opacity(0.22)
        let wave = (0..<32).map { i in 18 + ((i * 37 + seed * 11) % 64 + 64) % 64 }
        HStack(spacing: 10) {
            Button { player.toggle(url) } label: {
                Image(systemName: playing ? "pause.fill" : "play.fill").font(.system(size: 16)).foregroundStyle(mine ? c.red : .white)
                    .frame(width: 38, height: 38).background(mine ? Color.white : c.red, in: Circle())
                    .scaleEffect(playing ? 1.08 : 1)
                    .animation(.spring(response: 0.3, dampingFraction: 0.4), value: playing)
            }
            .buttonStyle(.plain)
            VStack(alignment: .leading, spacing: 4) {
                GeometryReader { g in
                    let gap: CGFloat = 2
                    let w = (g.size.width - gap * 31) / 32
                    HStack(alignment: .center, spacing: gap) {
                        ForEach(0..<32, id: \.self) { i in
                            Capsule().fill((Double(i) + 0.5) / 32 <= progress ? fg : track)
                                .frame(width: w, height: max(3, g.size.height * (0.2 + 0.8 * Double(wave[i]) / 100)))
                        }
                    }
                    .frame(height: g.size.height)
                    .contentShape(Rectangle())
                    .onTapGesture { p in player.seek(url, min(max(p.x / g.size.width, 0), 1)) }
                }
                .frame(width: 150, height: 26)
                Text(verbatim: current ? aiDurationText(player.positionMs) : durationMs.map(aiDurationText) ?? "--:--").font(.dorr(11))
                    .foregroundStyle(mine ? .white.opacity(0.85) : c.mut)
            }
        }
        .environment(\.layoutDirection, .leftToRight)
        .padding(.horizontal, 10).padding(.vertical, 8)
        .background(mine ? Color.white.opacity(0.16) : Color.black.opacity(0.06), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .task(id: url) {
            // The length isn't stored anywhere — read off the file itself.
            let asset = AVURLAsset(url: url, options: ["AVURLAssetHTTPHeaderFieldsKey": ["ngrok-skip-browser-warning": "1"]])
            if let d = try? await asset.load(.duration), d.seconds.isFinite { durationMs = Int(d.seconds * 1000) }
        }
    }
}

/// A reply's sources, folded: the file and whichever place in it the server found.
private struct AiCitations: View {
    @Environment(\.wa) private var wa
    let citations: [AiFileCitationDto]
    @State private var open = false

    var body: some View {
        let c = wa.ai
        VStack(alignment: .leading, spacing: 2) {
            Button { open.toggle() } label: {
                HStack(spacing: 2) {
                    Image(systemName: open ? "chevron.up" : "chevron.down").font(.system(size: 10, weight: .semibold))
                    Text(verbatim: L("ai_sources_count", citations.count)).font(.dorr(11))
                }
                .foregroundStyle(c.mut)
            }
            .buttonStyle(.plain)
            if open {
                ForEach(citations) { ci in
                    HStack(spacing: 4) {
                        Image(systemName: "doc.text").font(.system(size: 10))
                        Text(verbatim: (ci.fileName ?? "") + (citationLabel(ci).map { " — \($0)" } ?? "")).font(.dorr(11)).lineLimit(1)
                    }
                    .foregroundStyle(c.mut)
                }
            }
        }
        .padding(.top, 2)
    }

    private func citationLabel(_ ci: AiFileCitationDto) -> String? {
        guard let loc = ci.location else { return nil }
        if let page = loc.page { return "p.\(page)" }
        if let sheet = loc.sheet, loc.rowStart != nil || loc.rowEnd != nil { return "\(sheet) (\(loc.rowStart.map(String.init) ?? "null")-\(loc.rowEnd.map(String.init) ?? "null"))" }
        if let sheet = loc.sheet { return sheet }
        if let slide = loc.slide { return "slide \(slide)" }
        if let t = loc.timestampStart { let s = Int(t); return String(format: "%d:%02d", s / 60, s % 60) }
        return loc.section
    }
}

/// «Thinking…», then more as the wait grows (the phone's clock; the send is one call with no progress).
private struct AiTypingBubble: View {
    @Environment(\.wa) private var wa
    let started: Date
    let isImageLike: Bool

    var body: some View {
        let c = wa.ai
        HStack {
            TimelineView(.periodic(from: started, by: 1)) { ctx in
                let ms = ctx.date.timeIntervalSince(started) * 1000
                let key: String = ms < 3000 ? "ai_typing" : ms < 8000 ? "ai_typing_step_2" : ms < 20000 ? (isImageLike ? "ai_typing_step_image" : "ai_typing_step_3")
                    : (isImageLike ? "ai_typing_step_image" : "ai_typing_step_4")
                HStack(spacing: 8) {
                    ProgressView().tint(c.red).scaleEffect(0.7).frame(width: 14, height: 14)
                    Text(L(key)).font(.dorr(12.5)).foregroundStyle(c.mut)
                }
                .padding(.horizontal, 16).padding(.vertical, 12)
                .background(c.surface, in: UnevenBubble(mine: false))
            }
            Spacer(minLength: 40)
        }
    }
}

private struct AiAttachSheet: View {
    @Environment(\.wa) private var wa
    let onPickImage: () -> Void
    let onPickDocument: () -> Void

    var body: some View {
        let c = wa.ai
        VStack(spacing: 0) {
            row("photo.fill", L("ai_attach_photo"), onPickImage)
            row("doc.text.fill", L("ai_attach_document"), onPickDocument)
        }
        .padding(18)
        .background(c.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .padding(.horizontal, 32)
        .contentShape(Rectangle())
        .onTapGesture {}
    }

    private func row(_ icon: String, _ label: String, _ action: @escaping () -> Void) -> some View {
        let c = wa.ai
        return Button(action: action) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 18)).foregroundStyle(c.red)
                Text(verbatim: label).font(.dorr(14, .semibold)).foregroundStyle(c.ink)
                Spacer()
            }
            .padding(.vertical, 12)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// Full-screen image: pinch to zoom, drag down to close, save to Photos.
private struct AiImageViewer: View {
    let attachment: AiAttachmentDto
    let onClose: () -> Void
    @State private var scale: CGFloat = 1
    @State private var lastScale: CGFloat = 1
    @State private var drag: CGFloat = 0

    var body: some View {
        let url = attachment.url.flatMap { ApiClient.mediaUrl($0) }
        ZStack(alignment: .top) {
            Color.black.opacity(Double(max(0.2, 1 - abs(drag) / 900))).ignoresSafeArea()
            AsyncImage(url: url) { phase in
                if let image = phase.image { image.resizable().scaledToFit() } else { ProgressView().tint(.white) }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .scaleEffect(scale * (1 - abs(drag) / 3000))
            .offset(y: drag)
            .gesture(MagnificationGesture()
                .onChanged { scale = min(max(lastScale * $0, 1), 5) }
                .onEnded { _ in lastScale = scale })
            .simultaneousGesture(DragGesture()
                .onChanged { v in if scale <= 1 { drag = v.translation.height } }
                .onEnded { _ in
                    if abs(drag) > 260 { onClose() } else { withAnimation(.spring(response: 0.35, dampingFraction: 0.6)) { drag = 0 } }
                })
            HStack {
                circle("xmark", L("ai_image_viewer_close"), onClose)
                Spacer()
                if let url { circle("arrow.down.circle.fill", L("ai_image_viewer_download")) { save(url) } }
            }
            .padding(16)
        }
        .background(ClearCoverBackground())
    }

    private func circle(_ icon: String, _ label: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 18, weight: .semibold)).foregroundStyle(.white)
                .frame(width: 42, height: 42).background(Color.white.opacity(0.15), in: Circle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label)
    }

    @MainActor private func save(_ url: URL) {
        AppToast.shared.show(L("ai_image_download_started"))
        Task {
            var request = URLRequest(url: url)
            request.setValue("1", forHTTPHeaderField: "ngrok-skip-browser-warning")
            if let (data, _) = try? await URLSession.shared.data(for: request), let image = UIImage(data: data) {
                UIImageWriteToSavedPhotosAlbum(image, nil, nil, nil)
            } else {
                AppToast.shared.show(L("ai_image_download_failed"))
            }
        }
    }
}

/// A full-screen cover with nothing behind it but its own scrim.
private struct ClearCoverBackground: UIViewRepresentable {
    func makeUIView(context: Context) -> UIView {
        let view = UIView()
        DispatchQueue.main.async { view.superview?.superview?.backgroundColor = .clear }
        return view
    }

    func updateUIView(_ view: UIView, context: Context) {}
}
