import AVFoundation
import ContactsUI
import CoreLocation
import PhotosUI
import SwiftUI
import UniformTypeIdentifiers

// androidApp `ui/screens/chat/Composer.kt` (+ `chat/VoiceRecorder.kt`, and from `ChatBusinessUi.kt` / `ChatExtrasUi.kt` /
// `ChatMoneyUi.kt` / `ChatAiUi.kt` the schedule, location, poll, money-request and suggested-replies sheets) — the
// bottom of a conversation: the draft kept as I type, @mentions in groups, the reply / edit bar, ✨ suggested replies,
// the field with + (attachments) and the mic that turns into send. Hold the mic to record: slide towards the start to
// cancel, up to lock hands-free. Long-press send: without sound, scheduled, sensitive, urgent.

/// Records a voice note (AAC in .m4a) and samples the microphone level ~12 times a second: the live waveform while I
/// talk, and the `waveform` bars (0–100) the note carries to the other side.
@MainActor
final class ChatVoiceRecorder: ObservableObject {
    struct Clip {
        let url: URL
        let durationMs: Int64
        let waveform: [Int]
    }

    @Published private(set) var recording = false
    @Published private(set) var elapsedMs: Int64 = 0
    /// The latest levels (0–100), newest last — for the live bars.
    @Published private(set) var levels: [Int] = []
    private var recorder: AVAudioRecorder?
    private var startedAt = Date()
    private var samples: [Int] = []
    private var sampler: Task<Void, Never>?

    var permitted: Bool { AVAudioSession.sharedInstance().recordPermission == .granted }

    func askPermission() { AVAudioSession.sharedInstance().requestRecordPermission { _ in } }

    func start() -> Bool {
        if recording { return true }
        let session = AVAudioSession.sharedInstance()
        try? session.setCategory(.playAndRecord, mode: .default, options: [.defaultToSpeaker])
        try? session.setActive(true)
        let dir = FileManager.default.temporaryDirectory.appendingPathComponent("chat-voice", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        let url = dir.appendingPathComponent("\(UUID().uuidString).m4a")
        let settings: [String: Any] = [AVFormatIDKey: kAudioFormatMPEG4AAC, AVSampleRateKey: 44_100, AVNumberOfChannelsKey: 1, AVEncoderBitRateKey: 64_000]
        guard let r = try? AVAudioRecorder(url: url, settings: settings) else { return false }
        r.isMeteringEnabled = true
        guard r.record() else { return false }
        recorder = r
        startedAt = Date()
        samples = []
        levels = []
        recording = true
        VoicePlayer.shared.stop()
        sampler = Task {
            while !Task.isCancelled, let r = recorder {
                r.updateMeters()
                // dB → 0…100 on a square-root curve: quiet speech still shows.
                let linear = pow(10, Double(r.averagePower(forChannel: 0)) / 20)
                let level = min(max(Int(sqrt(linear) * 100), 4), 100)
                samples.append(level)
                levels.append(level)
                if levels.count > 40 { levels.removeFirst() }
                elapsedMs = Int64(Date().timeIntervalSince(startedAt) * 1000)
                try? await Task.sleep(nanoseconds: 80_000_000)
            }
        }
        return true
    }

    /// Stop and hand back the clip (nil when it was too short to be a message).
    func finish() -> Clip? {
        guard let r = recorder else { return nil }
        let duration = stop()
        if duration < 700 {
            try? FileManager.default.removeItem(at: r.url)
            return nil
        }
        return Clip(url: r.url, durationMs: duration, waveform: compress(samples, 64))
    }

    func cancel() {
        guard let r = recorder else { return }
        _ = stop()
        try? FileManager.default.removeItem(at: r.url)
    }

    private func stop() -> Int64 {
        sampler?.cancel()
        let duration = Int64(Date().timeIntervalSince(startedAt) * 1000)
        recorder?.stop()
        recorder = nil
        recording = false
        elapsedMs = 0
        return duration
    }

    /// Squeeze the samples into `bars` peaks.
    private func compress(_ values: [Int], _ bars: Int) -> [Int] {
        if values.isEmpty { return Array(repeating: 10, count: bars) }
        if values.count <= bars { return values }
        let step = Double(values.count) / Double(bars)
        return (0..<bars).map { i in
            let from = Int(Double(i) * step)
            let to = max(min(Int(Double(i + 1) * step), values.count), from + 1)
            return values[from..<to].max() ?? 4
        }
    }
}

struct ChatComposer: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var ai = ChatAi.shared
    @ObservedObject var host: ChatHost
    @ObservedObject var state: ConversationState
    @StateObject private var recorder = ChatVoiceRecorder()

    @State private var text = ""
    @State private var loaded = false
    @State private var mentioned: [Int: String] = [:]
    @State private var sendMenu = false
    @State private var sheet: String?
    @State private var locked = false
    @State private var dragX: CGFloat = 0
    @State private var dragY: CGFloat = 0
    @State private var holding = false
    @State private var now = Date()
    @FocusState private var focused: Bool
    @Environment(\.layoutDirection) private var direction

    private let cancelAt: CGFloat = 110
    private let lockAt: CGFloat = 80

    var body: some View {
        VStack(spacing: 0) {
            ScheduledPill(state: state) { sheet = "scheduled" }
            SmartRepliesRow(state: state) { reply in
                text = reply
                state.clearSmartReplies()
                UISelectionFeedbackGenerator().selectionChanged()
            }
            // ✓ fix spelling (on a tap, spec 36).
            ProofreadBar(host: host, text: text, enabled: !recorder.recording) { text = $0 }
            // «/» quick replies.
            QuickReplySuggestions(host: host, query: quickQuery) { reply in
                text = reply.body
                UISelectionFeedbackGenerator().selectionChanged()
            }
            mentionSuggestions
            if let target = state.editing ?? state.replyTo {
                ContextBar(target: target, isEdit: state.editing != nil) {
                    if state.editing != nil { text = "" }
                    state.replyTo = nil
                    state.editing = nil
                }
                .transition(.move(edge: .bottom).combined(with: .opacity))
            }
            HStack(alignment: .bottom, spacing: 6) {
                ZStack {
                    if recorder.recording {
                        RecordingBar(recorder: recorder, dragX: dragX, locked: locked) {
                            recorder.cancel()
                            locked = false
                            state.sendTyping(stop: true)
                        }
                        .transition(.move(edge: .trailing).combined(with: .opacity))
                    } else {
                        field.transition(.move(edge: .leading).combined(with: .opacity))
                    }
                }
                .animation(.easeInOut(duration: 0.2), value: recorder.recording)
                sendButton
            }
        }
        .padding(.horizontal, 10).padding(.vertical, 5)
        .animation(.spring(response: 0.35, dampingFraction: 0.85), value: state.replyTo?.id)
        .animation(.spring(response: 0.35, dampingFraction: 0.85), value: state.editing?.id)
        .onAppear {
            // What I typed last time and didn't send comes back.
            if !loaded { text = ChatStore.shared.draft(state.id); loaded = true }
        }
        .onDisappear {
            if state.editing == nil { ChatStore.shared.saveDraft(state.id, text) }
            recorder.cancel()
        }
        // The draft: saved a moment after typing stops.
        .task(id: text) {
            guard loaded, state.editing == nil else { return }
            try? await Task.sleep(nanoseconds: 500_000_000)
            if !Task.isCancelled { ChatStore.shared.saveDraft(state.id, text) }
        }
        // Editing puts the old text in the field.
        .onChange(of: state.editing?.id) { _ in if let e = state.editing { text = e.body ?? ""; focused = true } }
        .onChange(of: state.replyTo?.id) { id in if id != nil { focused = true } }
        // A reply picked elsewhere lands here to edit and send.
        .onChange(of: state.composerInsert) { insert in
            if let insert { text = insert; state.composerInsert = nil }
        }
        .onChange(of: mentionQuery != nil) { on in if on { Task { await state.loadMembers() } } }
        // Slow mode: the seconds left tick down on the send button.
        .task(id: state.slowUntil) {
            while let until = state.slowUntil, until > Date() {
                now = Date()
                try? await Task.sleep(nanoseconds: 250_000_000)
            }
            now = Date()
        }
        .dorrDialog(isPresented: Binding(get: { sheet != nil }, set: { if !$0 { sheet = nil } }), bottom: true) { sheetView }
    }

    // MARK: Field

    private var field: some View {
        HStack(alignment: .bottom, spacing: 0) {
            Button { sheet = "attach" } label: {
                Image(systemName: "plus").font(.system(size: 20, weight: .semibold)).foregroundStyle(ch.red)
                    .rotationEffect(.degrees(sheet == "attach" ? 45 : 0))
                    .animation(.spring(response: 0.3, dampingFraction: 0.45), value: sheet)
                    .frame(width: 34, height: 34)
            }
            .buttonStyle(.plain).padding(.bottom, 3)
            ZStack(alignment: .leading) {
                if text.isEmpty {
                    // Slow mode on: say so where I type.
                    Text(state.slowModeSeconds > 0 ? L("ch_slow_mode_hint", slowModeLabel(state.slowModeSeconds)) : L("ch_message_hint"))
                        .font(.dorr(14.5)).foregroundStyle(ch.soft).lineLimit(1)
                }
                TextField("", text: $text, axis: .vertical)
                    .lineLimit(1...6)
                    .font(.dorr(14.5)).foregroundStyle(ch.ink).tint(ch.red)
                    .focused($focused)
                    .onChange(of: text) { t in
                        if t.trimmingCharacters(in: .whitespaces).isEmpty { state.sendTyping(stop: true) } else { state.sendTyping() }
                    }
            }
            .padding(.vertical, 11).padding(.horizontal, 4)
            // ✨ Suggest replies — only on an empty field, only when the AI is available.
            if text.isEmpty && ai.smartReplies && state.editing == nil {
                Button { state.loadSmartReplies() } label: {
                    Image(systemName: "sparkles").font(.system(size: 17)).foregroundStyle(state.loadingReplies ? ch.red : ch.mut).frame(width: 34, height: 34)
                }
                .buttonStyle(.plain).padding(.bottom, 3)
            }
            // Emoji · stickers · GIFs — the face winks when the panel opens.
            Button { sheet = "expressions" } label: {
                let wink = sheet == "expressions"
                Image(systemName: "face.smiling").font(.system(size: 19)).foregroundStyle(wink ? ch.red : ch.mut)
                    .rotationEffect(.degrees(wink ? 18 : 0)).scaleEffect(wink ? 1.15 : 1)
                    .animation(.spring(response: 0.3, dampingFraction: 0.4), value: wink)
                    .frame(width: 34, height: 34)
            }
            .buttonStyle(.plain).padding(.bottom, 3)
        }
        .padding(.horizontal, 4)
        .frame(minHeight: 44)
        // Slightly see-through: the wallpaper shows around and under the message box.
        .background(ch.surface.opacity(0.9), in: RoundedRectangle(cornerRadius: 22))
        .shadow(color: .black.opacity(0.1), radius: 3, y: 1)
    }

    /// «/…» typed alone: the shortcut so far (nil otherwise, or while editing).
    private var quickQuery: String? {
        guard state.editing == nil, let r = text.range(of: #"^/([^\s/]{0,32})$"#, options: .regularExpression) else { return nil }
        return String(text[r].dropFirst())
    }

    // MARK: @mentions

    /// The word being typed right now, when it starts with «@» (nil otherwise).
    private var mentionQuery: String? {
        guard state.conversation?.isGroup == true,
              let r = text.range(of: #"(?:^|\s)@([^\s@]{0,30})$"#, options: .regularExpression) else { return nil }
        return String(text[r]).trimmingCharacters(in: .whitespaces).dropFirst().description
    }

    @ViewBuilder
    private var mentionSuggestions: some View {
        let q = mentionQuery
        let list = q == nil ? [] : Array(state.members.filter { m in
            m.profile?.isMe != true && (q!.isEmpty || (m.profile?.name ?? "").localizedCaseInsensitiveContains(q!))
        }.prefix(6))
        if !list.isEmpty {
            VStack(spacing: 0) {
                ForEach(Array(list.enumerated()), id: \.element.id) { i, member in
                    let name = member.profile?.name ?? ""
                    HStack(spacing: 10) {
                        ChAvatar(url: member.profile?.avatar, name: name, key: member.profile?.key, size: 34)
                        Text(verbatim: name).font(.dorr(14.5, .bold)).foregroundStyle(ch.ink).frame(maxWidth: .infinity, alignment: .leading)
                        if member.role != "member" { Text(L(member.role == "owner" ? "ch_owner" : "ch_admin")).font(.dorr(11, .bold)).foregroundStyle(ch.red) }
                    }
                    .padding(.horizontal, 14).padding(.vertical, 8)
                    .contentShape(Rectangle())
                    .onTapGesture {
                        // Replace «@partial» with «@Full Name ».
                        if let r = text.range(of: #"@([^\s@]{0,30})$"#, options: .regularExpression) { text.replaceSubrange(r, with: "@\(name) ") }
                        mentioned[member.participantId] = name
                        UISelectionFeedbackGenerator().selectionChanged()
                    }
                    .chStagger(i)
                }
            }
            .padding(.vertical, 6)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 20))
            .shadow(color: .black.opacity(0.12), radius: 8, y: 3)
            .padding(.bottom, 6)
            .transition(.move(edge: .bottom).combined(with: .opacity))
        }
    }

    // MARK: Mic ⇄ send

    private var slowLeft: Int {
        guard let until = state.slowUntil, until > now else { return 0 }
        return Int(ceil(until.timeIntervalSince(now)))
    }

    private var sendButton: some View {
        let showSend = !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || locked
        let grow: CGFloat = recorder.recording && !locked ? 1.45 : 1
        return ZStack(alignment: .bottom) {
            // The lock that rises above the mic while recording.
            if recorder.recording && !locked {
                let lift = min(max(-dragY / lockAt, 0), 1)
                VStack(spacing: 2) {
                    Image(systemName: "lock.fill").font(.system(size: 17)).foregroundStyle(lift > 0.9 ? ch.red : ch.mut)
                    Image(systemName: "chevron.up").font(.system(size: 13, weight: .bold)).foregroundStyle(ch.soft)
                }
                .padding(.horizontal, 8).padding(.vertical, 10)
                .background(ch.surface, in: RoundedRectangle(cornerRadius: 20))
                .shadow(color: .black.opacity(0.12), radius: 5, y: 2)
                .offset(y: -62 - lift * 24)
                .transition(.scale.combined(with: .opacity))
                RecordHalo(color: ch.red).frame(width: 46, height: 46).scaleEffect(grow)
            }
            ZStack {
                Circle().fill(ch.headerBrush)
                Group {
                    if showSend && slowLeft > 0 && state.editing == nil {
                        Text(verbatim: "\(slowLeft)").font(.dorr(16, .heavy))
                    } else if showSend {
                        Image(systemName: state.editing != nil ? "pencil" : "paperplane.fill").font(.system(size: 18))
                            .flipsForRightToLeftLayoutDirection(true)
                    } else {
                        Image(systemName: "mic.fill").font(.system(size: 19))
                    }
                }
                .foregroundStyle(.white)
                .transition(.scale(scale: 0.3).combined(with: .opacity))
                .id(showSend)
            }
            .frame(width: 46, height: 46)
            .scaleEffect(grow)
            .shadow(color: ch.red.opacity(0.4), radius: 8, y: 3)
            .animation(.spring(response: 0.3, dampingFraction: 0.5), value: grow)
            .animation(.spring(response: 0.3, dampingFraction: 0.45), value: showSend)
            .contentShape(Circle())
            .gesture(showSend ? nil : recordGesture)
            .onTapGesture { if showSend { if locked { locked = false; finishVoice() } else { send() } } }
            .onLongPressGesture(minimumDuration: 0.45) {
                // A long press on a written message offers «send without sound» and the rest.
                guard showSend, !locked, state.editing == nil else { return }
                UIImpactFeedbackGenerator(style: .medium).impactOccurred()
                sendMenu = true
            }
            .modifier(SendMenuPresenter(shown: $sendMenu, menu: sendMenuView, options: sendMenuOptions))
        }
    }

    /// Hold to record: slide towards the start to cancel, up to lock hands-free.
    private var recordGesture: some Gesture {
        DragGesture(minimumDistance: 0)
            .onChanged { v in
                if !holding {
                    holding = true
                    guard recorder.permitted else {
                        recorder.askPermission()
                        host.showToast(L("ch_permission_mic"))
                        return
                    }
                    guard recorder.start() else { return }
                    UIImpactFeedbackGenerator(style: .medium).impactOccurred()
                    state.sendTyping(state: "recording")
                    return
                }
                guard recorder.recording, !locked else { return }
                dragX = v.translation.width * (direction == .rightToLeft ? 1 : -1)
                dragY = v.translation.height
                if dragX > cancelAt {
                    UINotificationFeedbackGenerator().notificationOccurred(.warning)
                    recorder.cancel()
                    state.sendTyping(stop: true)
                    dragX = 0
                    dragY = 0
                } else if dragY < -lockAt {
                    locked = true
                    UISelectionFeedbackGenerator().selectionChanged()
                    dragX = 0
                    dragY = 0
                }
            }
            .onEnded { _ in
                holding = false
                dragX = 0
                dragY = 0
                // Locked: keeps recording; the send button finishes it.
                if recorder.recording && !locked { finishVoice() }
            }
    }

    /// The same choices as a system dialog (before iOS 16.4 a popover can't stay small on a phone).
    private var sendMenuOptions: [(String, () -> Void)] {
        var out: [(String, () -> Void)] = [
            (L("ch_send_silent"), { send(silent: true) }),
            (L("ch_schedule_message"), { sheet = "schedule" }),
            (L("ch_send_sensitive"), { send(sensitive: true) }),
        ]
        if let c = state.conversation, !c.isGroup, c.isSelf != true { out.append((L("ch_send_urgent"), { send(urgent: true) })) }
        return out
    }

    private var sendMenuView: some View {
        VStack(alignment: .leading, spacing: 0) {
            menuRow("bell.slash.fill", L("ch_send_silent")) { send(silent: true) }
            menuRow("clock.fill", L("ch_schedule_message")) { sheet = "schedule" }
            menuRow("lock.fill", L("ch_send_sensitive")) { send(sensitive: true) }
            if let c = state.conversation, !c.isGroup, c.isSelf != true {
                menuRow("exclamationmark", L("ch_send_urgent")) { send(urgent: true) }
            }
        }
        .padding(.vertical, 4)
        .background(ch.surface)
    }

    private func menuRow(_ icon: String, _ label: String, _ action: @escaping () -> Void) -> some View {
        Button {
            sendMenu = false
            action()
        } label: {
            HStack(spacing: 10) {
                Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(ch.red)
                    .frame(width: 32, height: 32).background(ch.red.opacity(0.12), in: Circle())
                Text(verbatim: label).font(.dorr(14.5, .bold)).foregroundStyle(ch.ink)
            }
            .padding(.horizontal, 16).padding(.vertical, 10)
        }
        .buttonStyle(.plain)
    }

    // MARK: Sending

    private func send(silent: Bool = false, urgent: Bool = false, sensitive: Bool = false) {
        let body = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !body.isEmpty else { return }
        if slowLeft > 0 && state.editing == nil {
            host.showToast(L("ch_slow_mode_wait", slowLeft))
            return
        }
        UISelectionFeedbackGenerator().selectionChanged()
        if let editing = state.editing {
            state.edit(editing, body: body)
        } else {
            var extra: [String: JSONAny] = [:]
            let mentions = mentioned.filter { body.contains("@\($0.value)") }.map(\.key)
            if !mentions.isEmpty { extra["mentions"] = .from(mentions) }
            // Delivered without a notification sound or vibration.
            if silent { extra["silent"] = .bool(true) }
            // Gets through their mute, when they allow urgent messages from me.
            if urgent { extra["urgent"] = .bool(true) }
            // Hidden until they unlock it, and never in a notification.
            if sensitive { extra["sensitive"] = .bool(true) }
            state.send(Outgoing(type: "text", body: body, extra: extra))
        }
        text = ""
        mentioned = [:]
        ChatStore.shared.saveDraft(state.id, "")
    }

    private func finishVoice() {
        guard let clip = recorder.finish() else { return }
        UISelectionFeedbackGenerator().selectionChanged()
        state.sendTyping(stop: true)
        state.send(Outgoing(type: "voice", files: [ChatLocalFile(url: clip.url, mime: "audio/mp4", name: clip.url.lastPathComponent)],
                            extra: ["duration_ms": .from(clip.durationMs), "waveform": .from(clip.waveform)]))
    }

    // MARK: Sheets

    @ViewBuilder
    private var sheetView: some View {
        switch sheet {
        case "attach":
            AttachSheet(host: host, state: state) { sheet = nil }
        case "schedule":
            ScheduleSheet(onDismiss: { sheet = nil }) { at in
                let body = text.trimmingCharacters(in: .whitespacesAndNewlines)
                Task {
                    if !body.isEmpty, await state.schedule(body, sendAt: at) {
                        host.showToast(L("ch_scheduled_toast", scheduleLabel(at)))
                        text = ""
                        ChatStore.shared.saveDraft(state.id, "")
                    }
                }
            }
        case "scheduled":
            ScheduledListSheet(host: host, state: state) { sheet = nil }
        case "expressions":
            ExpressionPanel(onDismiss: { sheet = nil }, onEmoji: { text += $0 }) { pick in
                state.send(Outgoing(type: pick.type, extra: pick.extra.mapValues { JSONAny.from($0) }))
            }
        default:
            EmptyView()
        }
    }
}

/// Long-press on send: a small popover above the button (iOS 16.4+), else the same choices in a dialog.
private struct SendMenuPresenter<Menu: View>: ViewModifier {
    @Binding var shown: Bool
    let menu: Menu
    let options: [(String, () -> Void)]

    func body(content: Content) -> some View {
        if #available(iOS 16.4, *) {
            content.popover(isPresented: $shown) { menu.presentationCompactAdaptation(.popover) }
        } else {
            content.confirmationDialog("", isPresented: $shown) {
                ForEach(Array(options.enumerated()), id: \.offset) { _, o in Button(o.0, action: o.1) }
            }
        }
    }
}

func slowModeLabel(_ seconds: Int) -> String {
    if seconds <= 0 { return L("ch_off") }
    if seconds < 60 { return L("ch_seconds_n", seconds) }
    if seconds < 3600 { return L("ch_minutes_n", seconds / 60) }
    return L("ch_hours_n", seconds / 3600)
}

private struct RecordHalo: View {
    let color: Color
    @State private var big = false
    var body: some View {
        Circle().fill(color.opacity(0.18)).scaleEffect(big ? 1.35 : 1)
            .onAppear { withAnimation(.easeInOut(duration: 0.7).repeatForever(autoreverses: true)) { big = true } }
    }
}

/// Above the field: what I'm replying to, or the message I'm editing.
private struct ContextBar: View {
    @ObservedObject private var ch = Ch.shared
    let target: MessageDto
    let isEdit: Bool
    let onClose: () -> Void

    var body: some View {
        let accent = isEdit ? ch.red : Ch.colorFor(target.sender?.key)
        let (_, label) = previewOf(target.type, deleted: false)
        HStack(spacing: 0) {
            Rectangle().fill(accent).frame(width: 5)
            VStack(alignment: .leading, spacing: 1) {
                Text(verbatim: isEdit ? L("ch_editing") : target.sender?.key == myKey() ? L("ch_you") : target.sender?.name ?? "")
                    .font(.dorr(13, .heavy)).foregroundStyle(accent)
                Text(verbatim: target.body.flatMap { $0.isEmpty ? nil : plainChatText($0) } ?? label).font(.dorr(13)).foregroundStyle(ch.mut).lineLimit(1)
            }
            .padding(.horizontal, 12).padding(.vertical, 8)
            .frame(maxWidth: .infinity, alignment: .leading)
            Button(action: onClose) { Image(systemName: "xmark").font(.system(size: 15, weight: .semibold)).foregroundStyle(ch.soft).padding(10) }.buttonStyle(.plain)
        }
        .fixedSize(horizontal: false, vertical: true)
        .background(ch.surface)
        .clipShape(RoundedRectangle(cornerRadius: 18))
        .shadow(color: .black.opacity(0.1), radius: 3, y: 1)
        .padding(.bottom, 6)
    }
}

/// While holding the mic: blinking dot + timer, a live waveform, and «‹ slide to cancel» drifting with the finger.
/// Locked: a bin to discard.
private struct RecordingBar: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var recorder: ChatVoiceRecorder
    let dragX: CGFloat
    let locked: Bool
    let onCancel: () -> Void
    @State private var blink = false

    var body: some View {
        HStack(spacing: 8) {
            if locked {
                Button(action: onCancel) { Image(systemName: "trash.fill").font(.system(size: 20)).foregroundStyle(ch.mut) }.buttonStyle(.plain)
            }
            Circle().fill(ch.red).frame(width: 10, height: 10).opacity(blink ? 0.2 : 1)
            Text(verbatim: durationText(recorder.elapsedMs)).font(.dorr(14, .bold)).foregroundStyle(ch.ink).monospacedDigit()
            Canvas { gc, size in
                let bars = 40
                let gap: CGFloat = 2
                let w = (size.width - gap * CGFloat(bars - 1)) / CGFloat(bars)
                let levels = recorder.levels
                for i in 0..<bars {
                    let v = levels[safe: levels.count - bars + i] ?? 4
                    let h = max(size.height * CGFloat(v) / 100, 3)
                    let rect = CGRect(x: CGFloat(i) * (w + gap), y: (size.height - h) / 2, width: w, height: h)
                    gc.fill(Path(roundedRect: rect, cornerRadius: w / 2), with: .color(ch.red.opacity(0.35 + 0.65 * Double(i) / Double(bars))))
                }
            }
            .frame(height: 26)
            .environment(\.layoutDirection, .leftToRight)
            if locked {
                Text(L("ch_recording_locked")).font(.dorr(12.5, .bold)).foregroundStyle(ch.red).lineLimit(1)
            } else {
                Text(verbatim: "‹ " + L("ch_slide_cancel")).font(.dorr(12.5, .semibold)).foregroundStyle(ch.mut.opacity(blink ? 0.5 : 1)).lineLimit(1)
                    .offset(x: -dragX * 0.6 * (1))
            }
        }
        .padding(.horizontal, 12).frame(height: 44)
        .background(ch.surface.opacity(0.9), in: RoundedRectangle(cornerRadius: 22))
        .shadow(color: .black.opacity(0.1), radius: 3, y: 1)
        .onAppear { withAnimation(.easeInOut(duration: 0.6).repeatForever(autoreverses: true)) { blink = true } }
    }
}

/// Above the composer after tapping ✨: three replies the AI suggests. A tap puts one in the field.
private struct SmartRepliesRow: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var state: ConversationState
    let onPick: (String) -> Void

    var body: some View {
        if state.loadingReplies || !state.smartReplies.isEmpty {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    Button { state.clearSmartReplies() } label: {
                        Image(systemName: "xmark").font(.system(size: 13, weight: .semibold)).foregroundStyle(ch.mut).frame(width: 34, height: 34).background(ch.surface, in: Circle())
                    }
                    .buttonStyle(.plain)
                    if state.loadingReplies {
                        ForEach(0..<3, id: \.self) { i in chip("…").opacity(0.5).chStagger(i) }
                    } else {
                        ForEach(Array(state.smartReplies.enumerated()), id: \.offset) { i, reply in
                            chip(reply).onTapGesture { onPick(reply) }.chStagger(i)
                        }
                    }
                }
            }
            .padding(.bottom, 6)
            .transition(.move(edge: .bottom).combined(with: .opacity))
        }
    }

    private func chip(_ text: String) -> some View {
        Text(verbatim: text).font(.dorr(13.5, .semibold)).foregroundStyle(ch.ink).lineLimit(1)
            .padding(.horizontal, 14).padding(.vertical, 8)
            .background(ch.surface, in: Capsule())
            .overlay(Capsule().stroke(ch.red.opacity(0.25), lineWidth: 1))
    }
}

/// Above the composer: «2 scheduled messages» — opens the list.
private struct ScheduledPill: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var state: ConversationState
    let onTap: () -> Void

    var body: some View {
        let count = state.scheduled.count
        if count > 0 {
            let failed = state.scheduled.contains { $0.status == "failed" }
            HStack(spacing: 6) {
                Image(systemName: failed ? "exclamationmark.circle" : "clock.fill").font(.system(size: 15)).foregroundStyle(failed ? ch.danger : ch.red)
                Text(verbatim: Lp("ch_scheduled_count", count)).font(.dorr(13, .bold)).foregroundStyle(ch.ink)
            }
            .padding(.horizontal, 14).padding(.vertical, 7)
            .background(ch.surface, in: Capsule())
            .shadow(color: .black.opacity(0.1), radius: 3, y: 1)
            .onTapGesture(perform: onTap)
            .padding(.bottom, 6)
            .transition(.move(edge: .bottom).combined(with: .opacity))
        }
    }
}

/// My scheduled messages here: change the time or text, send now, or delete.
private struct ScheduledListSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    @ObservedObject var state: ConversationState
    let onDismiss: () -> Void
    @State private var rescheduling: ScheduledMessageDto?
    @State private var editing: ScheduledMessageDto?
    @State private var editText = ""

    var body: some View {
        if let item = rescheduling {
            ScheduleSheet(onDismiss: { rescheduling = nil }) { at in act { _ = try await ChatApi.updateScheduled(item.id, ["send_at": isoWithOffset(at)]) } }
        } else if let item = editing {
            ChSheetCard {
                Text(L("ch_edit")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 12)
                ChField(placeholder: L("ch_message_hint"), text: $editText, icon: "pencil", lines: 1...6, limit: 4000)
                ChPrimaryButton(text: L("ch_save"), enabled: !editText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty) {
                    let body = editText.trimmingCharacters(in: .whitespacesAndNewlines)
                    editing = nil
                    act { _ = try await ChatApi.updateScheduled(item.id, ["body": body]) }
                }
                .padding(.top, 14)
            }
        } else {
            ChSheetCard {
                Text(L("ch_scheduled_title")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 12)
                ScrollView {
                    VStack(spacing: 12) {
                        ForEach(Array(state.scheduled.enumerated()), id: \.element.id) { i, item in
                            let failed = item.status == "failed"
                            VStack(alignment: .leading, spacing: 6) {
                                HStack(spacing: 6) {
                                    Image(systemName: failed ? "exclamationmark.circle" : "clock.fill").font(.system(size: 14)).foregroundStyle(failed ? ch.danger : ch.red)
                                    Text(verbatim: failed ? L("ch_scheduled_failed") : scheduleLabel(item.sendAt)).font(.dorr(12.5, .bold)).foregroundStyle(failed ? ch.danger : ch.mut)
                                }
                                Text(verbatim: item.body).font(.dorr(14.5)).foregroundStyle(ch.ink).lineLimit(4)
                                HStack(spacing: 6) {
                                    mini("paperplane.fill", L("ch_send_now")) { act { _ = try await ChatApi.sendScheduledNow(item.id) } }
                                    mini("clock", L("ch_reschedule")) { rescheduling = item }
                                    mini("pencil", L("ch_edit")) { editText = item.body; editing = item }
                                    mini("trash.fill", L("ch_delete"), danger: true) { act { _ = try await ChatApi.deleteScheduled(item.id) } }
                                }
                                .padding(.top, 4)
                            }
                            .padding(14)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18))
                            .chStagger(i)
                        }
                    }
                }
                .frame(maxHeight: 480)
            }
        }
    }

    private func mini(_ icon: String, _ label: String, danger: Bool = false, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 4) {
                Image(systemName: icon).font(.system(size: 11, weight: .semibold))
                Text(verbatim: label).font(.dorr(11.5, .bold)).lineLimit(1)
            }
            .foregroundStyle(danger ? ch.danger : ch.red)
            .padding(.horizontal, 8).padding(.vertical, 6)
            .background((danger ? ch.danger : ch.red).opacity(0.1), in: Capsule())
        }
        .buttonStyle(.plain)
    }

    private func act(_ action: @escaping () async throws -> Void) {
        rescheduling = nil
        Task {
            do { try await action() } catch { host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true) }
            await state.refreshScheduled()
            if state.scheduled.isEmpty { onDismiss() }
        }
    }
}

/// «When should it go?» — quick picks (in an hour, tonight, tomorrow morning) or any day and time.
struct ScheduleSheet: View {
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    let onPick: (Date) -> Void
    @State private var custom = false
    @State private var picked = Date().addingTimeInterval(3600)

    var body: some View {
        let now = Date()
        let cal = Calendar.current
        let inHour = cal.date(bySetting: .second, value: 0, of: now.addingTimeInterval(3600)) ?? now.addingTimeInterval(3600)
        let tonight = cal.date(bySettingHour: 21, minute: 0, second: 0, of: now).flatMap { $0 > now.addingTimeInterval(300) ? $0 : nil }
        let tomorrow = cal.date(bySettingHour: 9, minute: 0, second: 0, of: now.addingTimeInterval(86400)) ?? now
        let options: [(String, Date)] = [(L("ch_schedule_in_hour"), inHour)] + (tonight.map { [(L("ch_schedule_tonight"), $0)] } ?? []) + [(L("ch_schedule_tomorrow"), tomorrow)]
        ChSheetCard {
            Text(L("ch_schedule_title")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            Text(L("ch_schedule_sub")).font(.dorr(13)).foregroundStyle(ch.mut).padding(.top, 4)
            if custom {
                DatePicker("", selection: $picked, in: now.addingTimeInterval(60)..., displayedComponents: [.date, .hourAndMinute])
                    .datePickerStyle(.graphical).tint(ch.red).labelsHidden()
                ChPrimaryButton(text: scheduleLabel(picked), icon: "clock.fill") { onPick(picked); onDismiss() }.padding(.top, 8)
            } else {
                VStack(spacing: 0) {
                    ForEach(Array(options.enumerated()), id: \.offset) { i, o in
                        row("clock.fill", o.0, scheduleLabel(o.1)) { onPick(o.1); onDismiss() }
                        if i < options.count { Rectangle().fill(ch.line).frame(height: 0.6).padding(.leading, 44) }
                    }
                    row("calendar", L("ch_schedule_pick"), nil) { withAnimation { custom = true } }
                }
                .padding(.horizontal, 8).padding(.vertical, 4)
                .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18))
                .padding(.top, 12)
            }
        }
    }

    private func row(_ icon: String, _ title: String, _ sub: String?, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 16)).foregroundStyle(ch.red).frame(width: 32, height: 32).background(ch.red.opacity(0.1), in: Circle())
                Text(verbatim: title).font(.dorr(15, .bold)).foregroundStyle(ch.ink)
                Spacer()
                if let sub { Text(verbatim: sub).font(.dorr(12.5)).foregroundStyle(ch.mut) }
            }
            .padding(.vertical, 12).padding(.horizontal, 6).contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Attachments

private struct AttachItem: Identifiable {
    let id: String
    let icon: String
    let label: String
    let colors: [Color]
    let action: () -> Void
}

/// The + sheet: gallery, file, location, poll, view once, contact, money request, wallet QR — each a round
/// gradient button popping in one after another.
private struct AttachSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    @ObservedObject var state: ConversationState
    let onDismiss: () -> Void

    @State private var sub: String?
    /// The live length of the location being sent, for «Try again».
    @State private var pendingLive: Int?
    @State private var galleryItems: [PhotosPickerItem] = []
    @State private var viewOnceItem: [PhotosPickerItem] = []
    @State private var galleryOpen = false
    @State private var folderOpen = false
    @State private var gifOpen = false
    @State private var gifItem: [PhotosPickerItem] = []
    @State private var viewOnceOpen = false
    @State private var documentOpen = false

    var body: some View {
        let c = state.conversation
        let isGroup = c?.isGroup == true
        let isChannel = c?.isChannel == true
        Group {
            switch sub {
            case "location":
                LocationShareSheet(onDismiss: onDismiss, onCurrent: { shareLocation(live: nil) }, onLive: { shareLocation(live: $0) })
            case "poll":
                PollComposerSheet(onDismiss: onDismiss) { question, options, multiple in
                    state.send(Outgoing(type: "poll", body: question, extra: ["poll_options": .from(options), "poll_multiple": .bool(multiple)]))
                }
            case "request":
                RequestMoneySheet(peerName: c?.title ?? "", onDismiss: onDismiss) { amount, note in
                    state.send(Outgoing(type: "money_request", body: note, extra: ["amount_minor": .from(amount)]))
                }
            case "split":
                SplitBillSheet(members: state.members, onDismiss: onDismiss) { total, note, equal, shares in
                    var extra: [String: JSONAny] = ["amount_minor": .from(total), "split_mode": .string(equal ? "equal" : "custom")]
                    if equal {
                        extra["split_participants"] = .from(Array(shares.keys))
                    } else {
                        extra["split_shares"] = .array(shares.map { .object(["participant_id": .from($0.key), "amount_minor": .from($0.value)]) })
                    }
                    state.send(Outgoing(type: "bill_split", body: note, extra: extra))
                }
                // The members were only loaded for «@» suggestions: load them for the split.
                .task { await state.loadMembers() }
            case "sendMoney":
                SendMoneySheet(host: host, conversationId: state.id, peerName: c?.title ?? "", onDismiss: onDismiss) { sent in
                    state.replaceMessage(sent.id, UiMessage(dto: sent))
                }
            case "card":
                MomentCardSheet(conversationId: state.id, peerName: c?.title, canGift: !isGroup && c?.isSelf != true && c != nil, onDismiss: onDismiss) { sent in
                    if let sent { state.replaceMessage(sent.id, UiMessage(dto: sent)) } else { Task { await state.refreshScheduled() } }
                }
            case "interactive":
                InteractiveComposerSheet(onDismiss: onDismiss) { body, extra in state.send(Outgoing(type: "interactive", body: body, extra: extra)) }
            case "aiImage":
                ChatAiImageSheet(state: state, source: nil, onDismiss: onDismiss)
            case "transfer":
                TransferPicker { tx in
                    onDismiss()
                    state.send(Outgoing(type: "wallet_transfer", extra: ["wallet_transaction_id": .string(tx.uuid)]))
                }
            case "location_denied", "location_off":
                // Location off for the whole phone, or not allowed for Dorr: the settings, then «Try again» once back.
                let off = sub == "location_off"
                ChoiceSheet(title: L(off ? "ch_location_off_title" : "ch_location_denied_title"), subtitle: L(off ? "ch_location_off_sub" : "ch_location_denied_sub"), options: [
                    (L(off ? "ch_location_open_settings" : "ch_open_app_settings"), { if let url = URL(string: UIApplication.openSettingsURLString) { UIApplication.shared.open(url) } }),
                    (L("ch_location_try_again"), { shareLocation(live: pendingLive) }),
                ], onDismiss: onDismiss)
            default:
                ChSheetCard {
                    let items = attachItems(isGroup: isGroup, isChannel: isChannel)
                    VStack(spacing: 20) {
                        ForEach(0..<((items.count + 2) / 3), id: \.self) { row in
                            HStack(spacing: 0) {
                                ForEach(0..<3, id: \.self) { col in
                                    let i = row * 3 + col
                                    if i < items.count { AttachButton(item: items[i], index: i).frame(maxWidth: .infinity) } else { Spacer().frame(maxWidth: .infinity) }
                                }
                            }
                        }
                    }
                    .padding(.vertical, 10)
                }
            }
        }
        .photosPicker(isPresented: $galleryOpen, selection: $galleryItems, maxSelectionCount: 10, matching: .any(of: [.images, .videos]))
        .photosPicker(isPresented: $viewOnceOpen, selection: $viewOnceItem, maxSelectionCount: 1, matching: .any(of: [.images, .videos]))
        // A GIF from a video (spec 11): the trim screen opens with it.
        .photosPicker(isPresented: $gifOpen, selection: $gifItem, maxSelectionCount: 1, matching: .videos)
        .onChange(of: gifItem) { items in
            guard let item = items.first else { return }
            gifItem = []
            onDismiss()
            Task {
                guard let data = try? await item.loadTransferable(type: Data.self) else { return }
                let url = FileManager.default.temporaryDirectory.appendingPathComponent("gif-src-\(UUID().uuidString).mov")
                guard (try? data.write(to: url)) != nil else { return }
                state.gifSource = ChatLocalFile(url: url, mime: "video/quicktime", name: url.lastPathComponent)
            }
        }
        // A folder (spec 10): zipped on the phone, sent as one document with its name and file count.
        .background(EmptyView().fileImporter(isPresented: $folderOpen, allowedContentTypes: [.folder]) { result in
            onDismiss()
            guard case .success(let url) = result else { return }
            host.showToast(L("fg_zipping"))
            Task {
                switch await FolderZip.zip(url, maxBytes: Int64(folderMaxMB) * 1024 * 1024) {
                case .ok(let file, let name, let count):
                    state.send(Outgoing(type: "document", files: [file], extra: ["folder_name": .string(name), "folder_count": .from(count)]))
                case .empty: host.showToast(L("fg_empty"))
                case .tooBig: host.showToast(L("fg_too_big", folderMaxMB))
                case .failed: host.showToast(L("fg_failed"))
                }
            }
        })
        .fileImporter(isPresented: $documentOpen, allowedContentTypes: [.item]) { result in
            onDismiss()
            guard case .success(let url) = result, let file = copyPicked(url) else { return }
            let type = typeForMime(file.mime)
            state.send(Outgoing(type: type == "image" || type == "video" ? "document" : type, files: [file]))
        }
        .onChange(of: galleryItems) { items in
            guard !items.isEmpty else { return }
            galleryItems = []
            onDismiss()
            Task { await sendMedia(items, viewOnce: false) }
        }
        .onChange(of: viewOnceItem) { items in
            guard !items.isEmpty else { return }
            viewOnceItem = []
            onDismiss()
            Task { await sendMedia(items, viewOnce: true) }
        }
    }

    private func attachItems(isGroup: Bool, isChannel: Bool) -> [AttachItem] {
        var items: [AttachItem] = [
            AttachItem(id: "gallery", icon: "photo.fill", label: L("ch_attach_gallery"), colors: [Color(hex: 0xA78BFA), Color(hex: 0x7C3AED)]) { galleryOpen = true },
            AttachItem(id: "document", icon: "doc.text.fill", label: L("ch_attach_document"), colors: [Color(hex: 0x60A5FA), Color(hex: 0x2563EB)]) { documentOpen = true },
            AttachItem(id: "folder", icon: "folder.fill", label: L("fg_attach_folder"), colors: [Color(hex: 0x93C5FD), Color(hex: 0x1D4ED8)]) { folderOpen = true },
            AttachItem(id: "gif", icon: "play.square.stack.fill", label: L("fg_attach_gif"), colors: [Color(hex: 0xC084FC), Color(hex: 0x9333EA)]) { gifOpen = true },
            AttachItem(id: "location", icon: "mappin.and.ellipse", label: L("ch_attach_location"), colors: [Color(hex: 0x34D399), Color(hex: 0x059669)]) { withAnimation { sub = "location" } },
        ]
        if ChatAi.shared.imageGenerate {
            items.append(AttachItem(id: "aiImage", icon: "sparkles", label: L("ch_ai_image_title"), colors: [Color(hex: 0x818CF8), Color(hex: 0x4F46E5)]) { withAnimation { sub = "aiImage" } })
        }
        items += [
            AttachItem(id: "poll", icon: "chart.bar.fill", label: L("ch_attach_poll"), colors: [Color(hex: 0xFCD34D), Color(hex: 0xF59E0B)]) { withAnimation { sub = "poll" } },
            AttachItem(id: "interactive", icon: "rectangle.and.hand.point.up.left.fill", label: L("ia_attach"), colors: [Color(hex: 0x7DD3FC), Color(hex: 0x0284C7)]) { withAnimation { sub = "interactive" } },
            AttachItem(id: "once", icon: "1.circle.fill", label: L("ch_attach_view_once"), colors: [Color(hex: 0xF472B6), Color(hex: 0xDB2777)]) { viewOnceOpen = true },
            AttachItem(id: "contact", icon: "person.fill", label: L("ch_attach_contact"), colors: [Color(hex: 0x22D3EE), Color(hex: 0x0891B2)]) { pickContact() },
        ]
        // Money requests / splits are between people — not in a channel.
        if !isChannel {
            items.append(AttachItem(id: "request", icon: isGroup ? "arrow.triangle.branch" : "doc.plaintext.fill", label: L(isGroup ? "ch_attach_split" : "ch_attach_request"),
                                    colors: [Color(hex: 0x34D399), Color(hex: 0x047857)]) { withAnimation { sub = isGroup ? "split" : "request" } })
        }
        let canSendMoney = !isGroup && state.conversation?.isSelf != true && state.conversation != nil
        if canSendMoney {
            items.append(AttachItem(id: "sendMoney", icon: "banknote.fill", label: L("ch_attach_send_money"), colors: [Color(hex: 0x10B981), Color(hex: 0x047857)]) { withAnimation { sub = "sendMoney" } })
        }
        if !isChannel {
            items.append(AttachItem(id: "card", icon: "gift.fill", label: L("mo_attach_card"), colors: [Color(hex: 0xF472B6), Color(hex: 0x8B5CF6)]) { withAnimation { sub = "card" } })
        }
        items.append(AttachItem(id: "transfer", icon: "doc.text.fill", label: L("ch_attach_transfer"), colors: [ch.red, ch.redDeep]) { withAnimation { sub = "transfer" } })
        items.append(AttachItem(id: "qr", icon: "qrcode", label: L("ch_attach_wallet_qr"), colors: [Color(hex: 0xFBBF24), Color(hex: 0xD97706)]) {
            onDismiss()
            state.send(Outgoing(type: "wallet_qr"))
        })
        return items
    }

    /// Photos travel together as one album (shrunk to my upload quality); each video is its own message.
    private func sendMedia(_ items: [PhotosPickerItem], viewOnce: Bool) async {
        var images: [ChatLocalFile] = []
        for item in items {
            let isVideo = item.supportedContentTypes.contains { $0.conforms(to: .movie) }
            guard let data = try? await item.loadTransferable(type: Data.self) else { continue }
            if isVideo {
                let ext = item.supportedContentTypes.first { $0.conforms(to: .movie) }?.preferredFilenameExtension ?? "mov"
                let url = FileManager.default.temporaryDirectory.appendingPathComponent("chat-\(UUID().uuidString).\(ext)")
                guard (try? data.write(to: url)) != nil else { continue }
                let file = ChatLocalFile(url: url, mime: ext == "mp4" ? "video/mp4" : "video/quicktime", name: url.lastPathComponent)
                // Poster + length now, so the bubble shows a picture and a duration at once.
                let poster = await VideoTools.poster(url)
                let seconds = (try? await AVURLAsset(url: url).load(.duration).seconds) ?? 0
                var extra: [String: JSONAny] = seconds > 0 ? ["duration_ms": .from(Int(seconds * 1000))] : [:]
                if viewOnce { extra["view_once"] = .from(1) }
                state.send(Outgoing(type: "video", files: [file], extra: extra, thumbnail: poster))
            } else if let file = shrinkPhoto(data) {
                images.append(file)
            }
        }
        if !images.isEmpty { state.send(Outgoing(type: "image", files: images, extra: viewOnce ? ["view_once": .from(1)] : [:])) }
    }

    private func shrinkPhoto(_ data: Data) -> ChatLocalFile? {
        guard let image = UIImage(data: data) else { return nil }
        let budget = ChatPrefs.shared.photoBudget()
        let side = max(image.size.width, image.size.height)
        let scale = side > budget.side ? budget.side / side : 1
        let size = CGSize(width: image.size.width * scale, height: image.size.height * scale)
        let resized = UIGraphicsImageRenderer(size: size).image { _ in image.draw(in: CGRect(origin: .zero, size: size)) }
        guard let jpeg = resized.jpegData(compressionQuality: budget.quality) else { return nil }
        let url = FileManager.default.temporaryDirectory.appendingPathComponent("chat-\(UUID().uuidString).jpg")
        guard (try? jpeg.write(to: url)) != nil else { return nil }
        return ChatLocalFile(url: url, mime: "image/jpeg", name: url.lastPathComponent)
    }

    private func copyPicked(_ url: URL) -> ChatLocalFile? {
        let scoped = url.startAccessingSecurityScopedResource()
        defer { if scoped { url.stopAccessingSecurityScopedResource() } }
        let target = FileManager.default.temporaryDirectory.appendingPathComponent("\(UUID().uuidString.prefix(8))-\(url.lastPathComponent)")
        guard (try? FileManager.default.copyItem(at: url, to: target)) != nil else { return nil }
        let mime = UTType(filenameExtension: url.pathExtension)?.preferredMIMEType ?? "application/octet-stream"
        return ChatLocalFile(url: target, mime: mime, name: url.lastPathComponent)
    }

    /// Location: «now» or live (15 min / 1 h / 8 h), then the permission.
    private func shareLocation(live: Int?) {
        pendingLive = live
        // Location turned off for the whole phone.
        guard CLLocationManager.locationServicesEnabled() else {
            withAnimation { sub = "location_off" }
            return
        }
        onDismiss()
        Task {
            host.showToast(L("ch_sending_location"))
            switch await ChatLocationOnce.fetch() {
            case .denied:
                sub = "location_denied"
                host.showToast(L("ch_location_denied_title"), error: true)
            case .failed:
                host.showToast(L("ch_location_failed"), error: true)
            case .found(let loc):
                var extra: [String: JSONAny] = ["latitude": .number(loc.coordinate.latitude), "longitude": .number(loc.coordinate.longitude)]
                if let live { extra["live_seconds"] = .from(live) }
                state.send(Outgoing(type: "location", extra: extra))
            }
        }
    }

    /// The phone's own contact picker (no permission needed: it hands over just the picked contact).
    private func pickContact() {
        onDismiss()
        ContactPick.shared.present { name, phones in
            state.send(Outgoing(type: "contact", extra: ["contact_name": .string(name), "contact_phones": .from(phones)]))
        }
    }
}

private struct AttachButton: View {
    @ObservedObject private var ch = Ch.shared
    let item: AttachItem
    let index: Int
    @State private var pop: CGFloat = 0

    var body: some View {
        Button(action: item.action) {
            VStack(spacing: 8) {
                Image(systemName: item.icon).font(.system(size: 24)).foregroundStyle(.white)
                    .frame(width: 62, height: 62)
                    .background(LinearGradient(colors: item.colors, startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                    .shadow(color: item.colors.last!.opacity(0.45), radius: 8, y: 4)
                Text(verbatim: item.label).font(.dorr(12.5, .semibold)).foregroundStyle(ch.ink).lineLimit(1)
            }
        }
        .buttonStyle(AiPressStyle())
        .scaleEffect(pop).opacity(pop)
        .task {
            try? await Task.sleep(nanoseconds: UInt64(40 + index * 45) * 1_000_000)
            withAnimation(.spring(response: 0.4, dampingFraction: 0.45)) { pop = 1 }
        }
    }
}

/// Where the phone is, once: a fix from the last 2 minutes right away, else the next one (12 s at most).
@MainActor
final class ChatLocationOnce: NSObject, CLLocationManagerDelegate {
    enum Result { case found(CLLocation), denied, failed }
    private let manager = CLLocationManager()
    private var continuation: CheckedContinuation<Result, Never>?

    static func fetch() async -> Result { await ChatLocationOnce().run() }

    private func run() async -> Result {
        if let last = manager.location, Date().timeIntervalSince(last.timestamp) < 120 { return .found(last) }
        return await withCheckedContinuation { c in
            continuation = c
            manager.delegate = self
            manager.desiredAccuracy = kCLLocationAccuracyBest
            switch manager.authorizationStatus {
            case .notDetermined: manager.requestWhenInUseAuthorization()
            case .denied, .restricted: finish(.denied)
            default: manager.requestLocation()
            }
            Task {
                try? await Task.sleep(nanoseconds: 12_000_000_000)
                finish(manager.location.map { .found($0) } ?? .failed)
            }
        }
    }

    private func finish(_ r: Result) {
        continuation?.resume(returning: r)
        continuation = nil
    }

    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        Task { @MainActor in
            switch manager.authorizationStatus {
            case .denied, .restricted: finish(.denied)
            case .authorizedAlways, .authorizedWhenInUse: manager.requestLocation()
            default: break
            }
        }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        Task { @MainActor in if let l = locations.last { finish(.found(l)) } }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        Task { @MainActor in finish(manager.location.map { .found($0) } ?? .failed) }
    }
}

/// Presents the system contact picker over whatever is on screen.
@MainActor
final class ContactPick: NSObject, CNContactPickerDelegate {
    static let shared = ContactPick()
    private var onPick: ((String, [String]) -> Void)?

    func present(_ onPick: @escaping (String, [String]) -> Void) {
        self.onPick = onPick
        let picker = CNContactPickerViewController()
        picker.delegate = self
        picker.displayedPropertyKeys = [CNContactPhoneNumbersKey]
        // After the attach sheet has gone.
        Task {
            try? await Task.sleep(nanoseconds: 400_000_000)
            var top = UIApplication.shared.connectedScenes.compactMap { ($0 as? UIWindowScene)?.keyWindow }.first?.rootViewController
            while let presented = top?.presentedViewController { top = presented }
            top?.present(picker, animated: true)
        }
    }

    nonisolated func contactPicker(_ picker: CNContactPickerViewController, didSelect contact: CNContact) {
        let name = CNContactFormatter.string(from: contact, style: .fullName) ?? ""
        let phones = Array(Set(contact.phoneNumbers.map { $0.value.stringValue })).prefix(10)
        Task { @MainActor in
            let shown = name.isEmpty ? (phones.first ?? "") : name
            if !shown.isEmpty { onPick?(shown, Array(phones)) }
            onPick = nil
        }
    }
}

/// Send my location now, or share it live for 15 min / 1 h / 8 h.
private struct LocationShareSheet: View {
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    let onCurrent: () -> Void
    let onLive: (Int) -> Void

    var body: some View {
        ChSheetCard {
            Text(L("ch_attach_location")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 14)
            row("location.fill", L("ch_location_current"), L("ch_location_current_sub"), Color(hex: 0x2563EB), 0, onCurrent)
            Text(L("ch_live_title")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).padding(.leading, 4).padding(.top, 14).padding(.bottom, 6)
            ForEach(Array([(900, "ch_live_15m"), (3600, "ch_live_1h"), (28800, "ch_live_8h")].enumerated()), id: \.offset) { i, o in
                row("location.north.fill", L(o.1), L("ch_live_sub"), ch.success, i + 1) { onLive(o.0) }
            }
        }
    }

    private func row(_ icon: String, _ title: String, _ sub: String, _ color: Color, _ i: Int, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 18)).foregroundStyle(.white).frame(width: 42, height: 42).background(color, in: Circle())
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: title).font(.dorr(15, .bold)).foregroundStyle(ch.ink)
                    Text(verbatim: sub).font(.dorr(12)).foregroundStyle(ch.mut)
                }
                Spacer()
            }
            .padding(10)
            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 16))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .padding(.vertical, 3)
        .chStagger(i)
    }
}

/// Ask a question with 2–12 options; «Allow several answers» is a switch.
private struct PollComposerSheet: View {
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    let onSend: (String, [String], Bool) -> Void
    @State private var question = ""
    @State private var options = ["", ""]
    @State private var multiple = false

    var body: some View {
        var seen = Set<String>()
        let filled = options.map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty && seen.insert($0).inserted }
        let ready = !question.trimmingCharacters(in: .whitespaces).isEmpty && filled.count >= 2
        ChSheetCard {
            HStack(spacing: 12) {
                Image(systemName: "chart.bar.fill").font(.system(size: 18)).foregroundStyle(.white).frame(width: 40, height: 40).background(Color(hex: 0xF59E0B), in: Circle())
                Text(L("ch_poll_new")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            }
            ScrollView {
                VStack(alignment: .leading, spacing: 8) {
                    ChField(placeholder: L("ch_poll_question_hint"), text: $question, icon: "questionmark.circle", lines: 1...3, limit: 300)
                    Text(L("ch_poll_options")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).padding(.leading, 4).padding(.top, 4)
                    ForEach(options.indices, id: \.self) { i in
                        HStack(spacing: 6) {
                            ChField(placeholder: L("ch_poll_option_hint", i + 1), text: Binding(get: { options[safe: i] ?? "" }, set: { if i < options.count { options[i] = $0 } }), icon: "circle", limit: 100)
                            if options.count > 2 {
                                Button { options.remove(at: i) } label: { Image(systemName: "xmark").font(.system(size: 15, weight: .semibold)).foregroundStyle(ch.mut).frame(width: 34, height: 34) }
                                    .buttonStyle(.plain).transition(.scale)
                            }
                        }
                        .chStagger(i)
                    }
                    if options.count < 12 {
                        Button { withAnimation { options.append("") } } label: {
                            HStack(spacing: 6) {
                                Image(systemName: "plus").font(.system(size: 17, weight: .bold))
                                Text(L("ch_poll_add_option")).font(.dorr(14, .heavy))
                            }
                            .foregroundStyle(ch.red).padding(.horizontal, 8).padding(.vertical, 10)
                        }
                        .buttonStyle(.plain)
                    }
                    Toggle(isOn: $multiple) { Text(L("ch_poll_allow_many")).font(.dorr(14, .semibold)).foregroundStyle(ch.ink) }
                        .tint(ch.red).padding(.horizontal, 4)
                }
                .padding(.top, 14)
            }
            .frame(maxHeight: 460)
            ChPrimaryButton(text: L("ch_send"), icon: "paperplane.fill", enabled: ready) {
                onSend(question.trimmingCharacters(in: .whitespaces), filled, multiple)
                onDismiss()
            }
            .padding(.top, 12)
        }
    }
}

/// Ask the other person for an amount, with an optional note («Dinner 🍕»).
private struct RequestMoneySheet: View {
    @ObservedObject private var ch = Ch.shared
    let peerName: String
    let onDismiss: () -> Void
    let onSend: (Int, String?) -> Void
    @State private var amount = ""
    @State private var note = ""

    var body: some View {
        let minor = parseAmountToMinor(amount)
        ChSheetCard {
            VStack(spacing: 0) {
                Image(systemName: "doc.plaintext.fill").font(.system(size: 26)).foregroundStyle(.white)
                    .frame(width: 58, height: 58)
                    .background(LinearGradient(colors: [Color(hex: 0x10B981), Color(hex: 0x047857)], startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                Text(L("ch_money_request_from", peerName)).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).multilineTextAlignment(.center).padding(.top, 10)
                TextField("0.00", text: $amount).keyboardType(.decimalPad).font(.dorr(34, .heavy)).foregroundStyle(ch.ink)
                    .multilineTextAlignment(.center).tint(Color(hex: 0x10B981))
                    .environment(\.layoutDirection, .leftToRight)
                    .padding(.vertical, 10)
                    .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18))
                    .padding(.top, 16)
                ChField(placeholder: L("ch_money_note_hint"), text: $note, icon: "text.bubble", limit: 200).padding(.top, 12)
                ChPrimaryButton(text: L("ch_money_send_request"), icon: "paperplane.fill", enabled: (minor ?? 0) > 0) {
                    if let minor { onSend(minor, note.trimmingCharacters(in: .whitespaces).isEmpty ? nil : note.trimmingCharacters(in: .whitespaces)) }
                    onDismiss()
                }
                .padding(.top, 18)
            }
        }
    }
}
