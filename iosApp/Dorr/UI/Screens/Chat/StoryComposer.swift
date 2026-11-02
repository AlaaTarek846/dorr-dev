import AVFoundation
import SwiftUI
import UniformTypeIdentifiers

// androidApp `chat/StoryComposer.kt` (+ the recording half of `StoryVoice.kt` and ChatOrganizeUi's `PeoplePickerSheet`) —
// posting a status: text on a gradient with a font and stickers / drawing on top; a picked photo or video with a
// caption and layers in its own frame; or a voice status on a colour. Each can mention people and turn replies off.
// It uploads in the background: the page closes at once and «My status» spins until it's posted.

/// Maximum video length the app lets through before the server says no (the admin can raise it).
let videoLimitSeconds = 60

struct StoryComposerPage: View {
    @ObservedObject var host: ChatHost
    let media: String?

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()
            switch media {
            case nil: TextStoryEditor(host: host)
            case storyVoice?: VoiceStoryEditor(host: host)
            case let uri?: MediaStoryEditor(host: host, url: URL(string: uri))
            }
        }
        .environment(\.colorScheme, .dark)
    }
}

// MARK: - text

private struct TextStoryEditor: View {
    @ObservedObject var host: ChatHost
    @StateObject private var ed = StoryLayerEditor()
    @State private var text = ""
    @State private var bg = 0
    @State private var font = 0
    @State private var allowReplies = true
    @State private var mentions: [Int] = []
    @State private var fontBounce = false
    @FocusState private var focused: Bool

    var body: some View {
        let fontName = StoryLook.fonts[font]
        let size = StoryLook.textSize(text.count)
        ZStack {
            // The background crossfades to the next gradient.
            StoryLook.brush(StoryLook.backgrounds[bg].0).ignoresSafeArea().animation(.easeInOut(duration: 0.45), value: bg)

            ZStack {
                if text.isEmpty {
                    Text(L("st_type_status")).font(StoryLook.font(fontName, size: size)).foregroundStyle(.white.opacity(0.6)).multilineTextAlignment(.center)
                }
                TextField("", text: Binding(get: { text }, set: { text = String($0.prefix(700)) }), axis: .vertical)
                    .font(StoryLook.font(fontName, size: size)).foregroundStyle(.white).tint(.white)
                    .multilineTextAlignment(.center).lineSpacing(size * 0.25)
                    .focused($focused)
            }
            .animation(.spring(response: 0.4), value: size)
            .padding(.horizontal, 28).padding(.vertical, 90)

            // Stickers and drawing on top (backlog 79–80).
            StoryLayersEditable(ed: ed, deselectOnTap: false).ignoresSafeArea()

            VStack {
                if ed.drawing {
                    DrawingBar(ed: ed)
                } else {
                    HStack(spacing: 8) {
                        StoryToolButton(icon: "xmark") { host.pop() }
                        Spacer()
                        LayerTools(ed: ed, withText: false)
                        MentionPill(mentions: $mentions)
                        StoryToolButton(label: "Aa") {
                            font = (font + 1) % StoryLook.fonts.count
                            fontBounce = true
                            Task {
                                try? await Task.sleep(nanoseconds: 200_000_000)
                                fontBounce = false
                            }
                        }
                        .scaleEffect(fontBounce ? 1.3 : 1)
                        .animation(.spring(response: 0.25, dampingFraction: 0.3), value: fontBounce)
                        StoryToolButton(icon: "paintpalette.fill") { bg = (bg + 1) % StoryLook.backgrounds.count }
                    }
                    .padding(12)
                }
                Spacer()
                // Bottom: replies switch + privacy + post.
                if !ed.drawing {
                    HStack {
                        PostOptions(host: host, allowReplies: allowReplies) { allowReplies.toggle() }
                        Spacer()
                        PostButton(enabled: !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty) {
                            var fields = ["type": "text", "body": text.trimmingCharacters(in: .whitespacesAndNewlines), "style[background]": StoryLook.backgrounds[bg].0,
                                          "style[font]": fontName, "allow_replies": allowReplies ? "1" : "0"]
                            fields.merge(mentionFields(mentions)) { $1 }
                            fields.merge(ed.fields()) { $1 }
                            host.postStory(fields, file: nil)
                        }
                    }
                    .padding(14)
                }
            }
        }
        .onAppear { focused = true }
        .onChange(of: ed.drawing) { if $0 { focused = false } }
        .modifier(LayerStickerPicker(ed: ed))
    }
}

// MARK: - photo / video

private struct MediaStoryEditor: View {
    @ObservedObject var host: ChatHost
    let url: URL?
    @StateObject private var ed = StoryLayerEditor()
    @State private var caption = ""
    @State private var allowReplies = true
    @State private var mentions: [Int] = []
    @State private var image: UIImage?
    @State private var durationMs: Int64?
    /// The picture's own shape: the layers sit in exactly that frame on every phone.
    @State private var aspect: Double?

    private var mime: String { url.flatMap { UTType(filenameExtension: $0.pathExtension)?.preferredMIMEType } ?? "image/jpeg" }
    private var isVideo: Bool { mime.hasPrefix("video/") }
    private var tooLong: Bool { isVideo && (durationMs ?? 0) > Int64(videoLimitSeconds) * 1000 + 500 }

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()
            ZStack {
                if isVideo {
                    LoopingVideoView(url: url)
                } else if let image {
                    Image(uiImage: image).resizable().scaledToFit()
                }
                if aspect != nil { StoryLayersEditable(ed: ed) }
            }
            .aspectRatio(aspect.map { CGFloat($0) }, contentMode: .fit)

            VStack {
                if ed.drawing {
                    DrawingBar(ed: ed)
                } else {
                    HStack(spacing: 8) {
                        StoryToolButton(icon: "xmark") { host.pop() }
                        Spacer()
                        if aspect != nil { LayerTools(ed: ed) }
                        MentionPill(mentions: $mentions)
                    }
                    .padding(12)
                }
                Spacer()
                if !ed.busy {
                    VStack(alignment: .leading, spacing: 12) {
                        if tooLong {
                            Text(L("st_video_too_long", videoLimitSeconds)).font(.dorr(14, .bold)).foregroundStyle(.white)
                                .padding(.horizontal, 12).padding(.vertical, 6)
                                .background(Color(hex: 0xDC2626).opacity(0.8), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                                .transition(.opacity)
                        }
                        TextField("", text: Binding(get: { caption }, set: { caption = String($0.prefix(700)) }),
                                  prompt: Text(L("st_add_caption")).foregroundColor(.white.opacity(0.7)), axis: .vertical)
                            .lineLimit(1...4).font(.dorr(15)).foregroundStyle(.white).tint(.white)
                            .padding(.horizontal, 18).padding(.vertical, 13).frame(minHeight: 48)
                            .background(Color.black.opacity(0.45), in: RoundedRectangle(cornerRadius: 24, style: .continuous))
                        HStack {
                            PostOptions(host: host, allowReplies: allowReplies) { allowReplies.toggle() }
                            Spacer()
                            PostButton(enabled: !tooLong, action: post)
                        }
                    }
                    .padding(14)
                }
            }
            LayerTextWriter(ed: ed)
        }
        .modifier(LayerStickerPicker(ed: ed))
        .task { await load() }
    }

    private func load() async {
        guard let url else { return }
        if isVideo {
            let asset = AVURLAsset(url: url)
            if let d = try? await asset.load(.duration) { durationMs = Int64(CMTimeGetSeconds(d) * 1000) }
            // A video's width ÷ height as it plays (turned phones' videos swap the two).
            if let track = try? await asset.loadTracks(withMediaType: .video).first,
               let loaded = try? await track.load(.naturalSize, .preferredTransform) {
                let r = CGRect(origin: .zero, size: loaded.0).applying(loaded.1)
                if r.width != 0, r.height != 0 { aspect = Double(abs(r.width) / abs(r.height)) }
            }
        } else if let data = try? Data(contentsOf: url), let img = UIImage(data: data) {
            image = img
            if img.size.width > 0, img.size.height > 0 { aspect = Double(img.size.width / img.size.height) }
        }
    }

    private func post() {
        guard let url, let data = try? Data(contentsOf: url) else { return }
        var fields = ["type": isVideo ? "video" : "image", "allow_replies": allowReplies ? "1" : "0"]
        let body = caption.trimmingCharacters(in: .whitespacesAndNewlines)
        if !body.isEmpty { fields["body"] = body }
        if let durationMs { fields["duration_ms"] = String(durationMs) }
        if let aspect { fields["style[aspect]"] = String(format: "%.4f", locale: Locale(identifier: "en_US_POSIX"), min(max(aspect, 0.2), 5)) }
        fields.merge(mentionFields(mentions)) { $1 }
        fields.merge(ed.fields()) { $1 }
        host.postStory(fields, file: ChatFile(field: "file", filename: isVideo ? "story.mp4" : "story.jpg", mimeType: mime, data: data))
    }
}

/// The picked video, playing over and over while the status is made.
private struct LoopingVideoView: UIViewRepresentable {
    let url: URL?

    final class PlayerView: UIView {
        override static var layerClass: AnyClass { AVPlayerLayer.self }
        var playerLayer: AVPlayerLayer { layer as! AVPlayerLayer }
        var looper: AVPlayerLooper?
    }

    func makeUIView(context: Context) -> PlayerView {
        let v = PlayerView()
        v.playerLayer.videoGravity = .resizeAspect
        guard let url else { return v }
        let player = AVQueuePlayer()
        v.looper = AVPlayerLooper(player: player, templateItem: AVPlayerItem(url: url))
        v.playerLayer.player = player
        player.play()
        return v
    }

    func updateUIView(_ v: PlayerView, context: Context) {}

    static func dismantleUIView(_ v: PlayerView, coordinator: ()) {
        v.playerLayer.player?.pause()
        v.looper = nil
        v.playerLayer.player = nil
    }
}

// MARK: - voice

/// Record a voice status on a colour of my choice (up to the story length).
private struct VoiceStoryEditor: View {
    @ObservedObject var host: ChatHost
    @StateObject private var recorder = ChatVoiceRecorder()
    @State private var clip: ChatVoiceRecorder.Clip?
    @State private var bg = 0
    @State private var allowReplies = true
    @State private var mentions: [Int] = []
    @State private var pulse = false

    var body: some View {
        ZStack {
            StoryLook.brush(StoryLook.backgrounds[bg].0).ignoresSafeArea().animation(.easeInOut(duration: 0.45), value: bg)
            VStack(spacing: 0) {
                VoiceBars(levels: recorder.recording ? recorder.levels : clip?.waveform ?? []).frame(height: 90)
                Button(action: tap) {
                    Image(systemName: recorder.recording ? "stop.fill" : "mic.fill").font(.system(size: 40)).foregroundStyle(Ch.shared.red)
                        .frame(width: 92, height: 92).background(Color.white, in: Circle())
                }
                .buttonStyle(.plain)
                .scaleEffect(recorder.recording && pulse ? 1.12 : 1)
                .animation(recorder.recording ? .easeInOut(duration: 0.6).repeatForever(autoreverses: true) : .default, value: pulse)
                .padding(.top, 22)
                Text(verbatim: status).font(.dorr(15, .bold)).foregroundStyle(.white).multilineTextAlignment(.center).padding(.top, 14)
            }
            .padding(24)

            VStack {
                HStack(spacing: 10) {
                    StoryToolButton(icon: "xmark") { host.pop() }
                    Spacer()
                    MentionPill(mentions: $mentions)
                    StoryToolButton(icon: "paintpalette.fill") { bg = (bg + 1) % StoryLook.backgrounds.count }
                }
                .padding(12)
                Spacer()
                HStack {
                    PostOptions(host: host, allowReplies: allowReplies) { allowReplies.toggle() }
                    Spacer()
                    PostButton(enabled: clip != nil && !recorder.recording, action: post)
                }
                .padding(14)
            }
        }
        // The longest a status may be.
        .onChange(of: recorder.elapsedMs) { if recorder.recording, $0 >= Int64(videoLimitSeconds) * 1000 { stop() } }
        .onChange(of: recorder.recording) { pulse = $0 }
        .onDisappear { if recorder.recording { recorder.cancel() } }
    }

    private var status: String {
        if recorder.recording { return L("bk_voice_recording") + "  " + durationText(recorder.elapsedMs) }
        if let clip { return durationText(clip.durationMs) }
        return L("bk_voice_hold", videoLimitSeconds)
    }

    private func stop() { clip = recorder.finish() }

    private func tap() {
        if recorder.recording { stop(); return }
        if recorder.permitted {
            clip = nil
            _ = recorder.start()
        } else {
            AVAudioSession.sharedInstance().requestRecordPermission { granted in
                if granted { Task { @MainActor in clip = nil; _ = recorder.start() } }
            }
        }
    }

    private func post() {
        guard let c = clip, let data = try? Data(contentsOf: c.url) else { return }
        var fields = ["type": "voice", "duration_ms": String(c.durationMs), "style[background]": StoryLook.backgrounds[bg].0, "allow_replies": allowReplies ? "1" : "0"]
        for (i, v) in c.waveform.enumerated() { fields["style[waveform][\(i)]"] = String(v) }
        fields.merge(mentionFields(mentions)) { $1 }
        host.postStory(fields, file: ChatFile(field: "file", filename: c.url.lastPathComponent, mimeType: "audio/mp4", data: data))
    }
}

// MARK: - shared

private struct StoryToolButton: View {
    var icon: String?
    var label: String?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            ZStack {
                if let icon { Image(systemName: icon).font(.system(size: 19, weight: .semibold)) }
                if let label { Text(verbatim: label).font(.dorr(17, .heavy)) }
            }
            .foregroundStyle(.white)
            .frame(width: 46, height: 46)
            .background(Color.black.opacity(0.25), in: Circle())
            .overlay(Circle().stroke(Color.white.opacity(0.35), lineWidth: 1))
        }
        .buttonStyle(.plain)
    }
}

/// The people mentioned (backlog 84), as form fields.
private func mentionFields(_ ids: [Int]) -> [String: String] {
    Dictionary(uniqueKeysWithValues: ids.enumerated().map { ("mentions[\($0.offset)]", String($0.element)) })
}

/// «@ Mention»: pick saved contacts; they're told in our chat.
struct MentionPill: View {
    @Binding var mentions: [Int]
    @State private var picking = false

    var body: some View {
        Button { picking = true } label: {
            HStack(spacing: 6) {
                Image(systemName: "at").font(.system(size: 14, weight: .bold))
                Text(verbatim: mentions.isEmpty ? L("bk_mention") : L("bk_mentioned_n", mentions.count)).font(.dorr(12, .bold))
            }
            .foregroundStyle(.white)
            .padding(.horizontal, 12).padding(.vertical, 8)
            .background(Color.black.opacity(0.35), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        }
        .buttonStyle(.plain)
        .dorrDialog(isPresented: $picking, bottom: true) {
            PeoplePickerSheet(initial: Set(mentions), onDismiss: { picking = false }) { ids in
                mentions = Array(ids.prefix(10))
                picking = false
            }
            .environment(\.colorScheme, Ch.shared.dark ? .dark : .light)
        }
    }
}

struct PostOptions: View {
    @ObservedObject var host: ChatHost
    let allowReplies: Bool
    let onToggleReplies: () -> Void

    var body: some View {
        HStack(spacing: 8) {
            // From the home page it's public — everyone sees it; from the chat, my usual audience.
            if host.publicMode {
                pill("globe", L("st_public_everyone")) {}
            } else {
                pill("shield.fill", L("st_privacy")) { host.push(.storyPrivacy) }
            }
            pill("arrowshape.turn.up.left.fill", L(allowReplies ? "st_allow_replies" : "st_replies_off"), active: allowReplies, action: onToggleReplies)
        }
    }

    private func pill(_ icon: String, _ text: String, active: Bool = true, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(.white.opacity(active ? 1 : 0.5))
                Text(verbatim: text).font(.dorr(12, .bold)).foregroundStyle(.white.opacity(active ? 1 : 0.6))
            }
            .padding(.horizontal, 12).padding(.vertical, 8)
            .background(Color.black.opacity(active ? 0.35 : 0.6), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

struct PostButton: View {
    let enabled: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: "paperplane.fill").font(.system(size: 23)).foregroundStyle(enabled ? Ch.shared.red : .white)
                .frame(width: 58, height: 58)
                .background(enabled ? Color.white : Color.white.opacity(0.35), in: Circle())
                .shadow(color: .black.opacity(enabled ? 0.35 : 0), radius: 12, y: 4)
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
        .scaleEffect(enabled ? 1 : 0.92)
        .animation(.spring(response: 0.35, dampingFraction: 0.45), value: enabled)
    }
}

extension ChatHost {
    /// Upload in the background: the page closes at once and «My status» spins until it's posted.
    func postStory(_ fields: [String: String], file: ChatFile?) {
        pop()
        storyUploading = true
        Task {
            do {
                var all = fields
                if publicMode { all["public"] = "1" }
                _ = try await ChatApi.postStory(fields: all, file: file)
                await refreshStories()
            } catch {
                // No HTTP answer at all (offline, tunnel down, timeout): say so instead of staying silent.
                showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
            storyUploading = false
        }
    }
}

// MARK: - people

/// Pick people from my contacts on Dorr (search, tick, pages of 15).
struct PeoplePickerSheet: View {
    @ObservedObject private var ch = Ch.shared
    let initial: Set<Int>
    var title = "ch_broadcast_pick"
    var allowEmpty = false
    let onDismiss: () -> Void
    let onDone: ([Int]) -> Void
    @State private var query = ""
    @State private var contacts: [ContactDto]?
    @State private var page = 1
    @State private var hasMore = false
    @State private var loadingMore = false
    @State private var picked: [Int] = []
    @State private var started = false

    var body: some View {
        ChSheetCard {
            Text(L(title)).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 8)
            ChField(placeholder: L("ch_search_contacts"), text: $query, icon: "magnifyingglass", limit: 80).padding(.bottom, 8)
            if let items = contacts {
                if items.isEmpty {
                    Text(L("ch_no_contacts")).font(.dorr(14)).foregroundStyle(ch.mut).frame(maxWidth: .infinity).frame(height: 160)
                } else {
                    ScrollView {
                        LazyVStack(spacing: 0) {
                            ForEach(Array(items.enumerated()), id: \.element.id) { i, c in
                                row(c).onAppear { if i >= items.count - 3 { Task { await loadPage(reset: false) } } }
                            }
                            if loadingMore { ProgressView().tint(ch.red).padding(10) }
                        }
                    }
                    .frame(height: 380)
                }
            } else {
                ProgressView().tint(ch.red).frame(maxWidth: .infinity).frame(height: 380)
            }
            ChPrimaryButton(text: L("ch_broadcast_done", picked.count), enabled: allowEmpty || !picked.isEmpty) { onDone(picked) }
                .padding(.top, 12)
        }
        .onAppear {
            if !started { started = true; picked = Array(initial) }
        }
        .task(id: query) {
            try? await Task.sleep(nanoseconds: 280_000_000)
            guard !Task.isCancelled else { return }
            await loadPage(reset: true)
        }
    }

    private func row(_ c: ContactDto) -> some View {
        let id = c.profile?.id ?? 0
        let on = picked.contains(id)
        return Button {
            if on { picked.removeAll { $0 == id } } else { picked.append(id) }
        } label: {
            HStack(spacing: 12) {
                ChAvatar(url: c.profile?.avatar, name: c.name, key: c.profile?.key, size: 44)
                Text(verbatim: c.name).font(.dorr(15, .bold)).foregroundStyle(ch.ink).frame(maxWidth: .infinity, alignment: .leading)
                Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 24)).foregroundStyle(on ? ch.red : ch.soft)
                    .animation(.spring(response: 0.3, dampingFraction: 0.4), value: on)
            }
            .padding(.vertical, 8).padding(.horizontal, 6)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    private func loadPage(reset: Bool) async {
        if !reset && (loadingMore || !hasMore) { return }
        if !reset { loadingMore = true }
        let next = reset ? 1 : page + 1
        let q = query.trimmingCharacters(in: .whitespaces)
        let envelope = try? await ChatApi.contacts(registered: 1, q: q.isEmpty ? nil : q, page: next, perPage: 15)
        let chunk = (envelope?.data ?? []).filter { $0.profile != nil }
        if reset {
            contacts = chunk
        } else {
            let known = Set((contacts ?? []).map(\.id))
            contacts = (contacts ?? []) + chunk.filter { !known.contains($0.id) }
        }
        page = next
        hasMore = envelope?.pagination?.hasMorePages == true
        if !reset { loadingMore = false }
    }
}
