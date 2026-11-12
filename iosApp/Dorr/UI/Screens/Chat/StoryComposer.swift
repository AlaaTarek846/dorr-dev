import AVFoundation
import PhotosUI
import SwiftUI
import UniformTypeIdentifiers

// androidApp `chat/StoryComposer.kt` (+ `VoiceStoryEditor` of `StoryVoice.kt` and ChatOrganizeUi's `PeoplePickerSheet`) —
// the status editor: text, photo / video (one or several) or voice, switched at the bottom without leaving. Every kind
// has the same top bar (✕, then round tools in a fixed order) and the same bottom (who sees it · replies · send).
// It uploads in the background: the page closes at once and «My status» spins until it's posted.

/// Maximum video length the app lets through before the server says no (the admin can raise it).
let videoLimitSeconds = 60

/// How many photos / videos can be picked at once — each becomes its own status (as in WhatsApp).
let storyMediaMax = 10

/// The status kinds, switched at the bottom of the editor.
enum StoryMode: CaseIterable {
    case text, media, voice

    var labelKey: String {
        switch self {
        case .text: return "st_text"
        case .media: return "st_photo_video"
        case .voice: return "bk_voice_status"
        }
    }
}

/// Several picked photos / videos travel in one route, one per line (`storyVoice` = the voice recorder).
func storyMediaRoute(_ urls: [URL]) -> String { urls.map(\.absoluteString).joined(separator: "\n") }

/// What an editor tells the page: it has something to lose, the kind to switch to, close.
struct StoryEditorHost {
    let onDirty: (Bool) -> Void
    let onSwitch: (StoryMode) -> Void
    let onClose: () -> Void
}

/// The status editor. Closing or switching away from something made asks first («Discard this status?»).
struct StoryComposerPage: View {
    @ObservedObject var host: ChatHost
    @State private var mode: StoryMode
    @State private var urls: [URL]
    /// A fresh editor for each kind and each new pick.
    @State private var generation = 0
    @State private var dirty = false
    @State private var confirm: (() -> Void)?
    @State private var picking = false
    @State private var picked: [PhotosPickerItem] = []

    init(host: ChatHost, media: String?) {
        _host = ObservedObject(wrappedValue: host)
        _mode = State(initialValue: media == nil ? .text : media == storyVoice ? .voice : .media)
        let list: [URL] = media == storyVoice ? [] : (media ?? "").split(separator: "\n").compactMap { URL(string: String($0)) }
        _urls = State(initialValue: list)
    }

    var body: some View {
        let editor = StoryEditorHost(onDirty: { dirty = $0 }, onSwitch: switchTo, onClose: { guarded { host.pop() } })
        ZStack {
            Color.black.ignoresSafeArea()
            Group {
                switch mode {
                case .text: TextStoryEditor(host: host, editor: editor)
                case .voice: VoiceStoryEditor(host: host, editor: editor)
                case .media:
                    if urls.isEmpty {
                        Color.clear.onAppear { mode = .text }
                    } else {
                        MediaStoryEditor(host: host, urls: urls, editor: editor)
                    }
                }
            }
            .id("\(mode)-\(generation)")
        }
        .environment(\.colorScheme, .dark)
        .photosPicker(isPresented: $picking, selection: $picked, maxSelectionCount: storyMediaMax, selectionBehavior: .ordered, matching: .any(of: [.images, .videos]))
        .onChange(of: picked) { items in
            guard !items.isEmpty else { return }
            picked = []
            Task {
                var files: [URL] = []
                for item in items { if let f = await aiCopyPicked(item) { files.append(f.url) } }
                guard !files.isEmpty else { return }
                urls = files
                dirty = false
                generation += 1
                mode = .media
            }
        }
        .dorrDialog(isPresented: Binding(get: { confirm != nil }, set: { if !$0 { confirm = nil } })) {
            ConfirmDialog(icon: "trash", title: L("st_discard_title"), message: L("st_discard_text"), confirmLabel: L("st_discard"),
                          onConfirm: discard, onDismiss: { confirm = nil })
                .environment(\.colorScheme, Ch.shared.dark ? .dark : .light)
        }
    }

    private func discard() {
        let action = confirm
        confirm = nil
        dirty = false
        // After the dialog's cover is gone: the photo picker can't open over it.
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) { action?() }
    }

    private func guarded(_ action: @escaping () -> Void) {
        if dirty { confirm = action } else { action() }
    }

    private func switchTo(_ target: StoryMode) {
        if target == .media {
            guarded { picking = true }
        } else if target != mode {
            guarded {
                dirty = false
                mode = target
            }
        }
    }
}

// MARK: - text

private enum TextPanel { case closed, colors, fonts }

private struct TextStoryEditor: View {
    @ObservedObject var host: ChatHost
    let editor: StoryEditorHost
    @StateObject private var ed = StoryLayerEditor()
    @Environment(\.layoutDirection) private var layoutDirection
    @State private var text = ""
    @State private var bg = 0
    @State private var font = 0
    @State private var align = "center"
    @State private var panel = TextPanel.closed
    @State private var allowReplies = true
    @State private var mentions: [Int] = []
    @State private var fontBounce = false
    @FocusState private var focused: Bool

    private var trimmed: String { text.trimmingCharacters(in: .whitespacesAndNewlines) }
    private var made: Bool { !trimmed.isEmpty || !ed.layers.isEmpty }

    var body: some View {
        let fontName = StoryLook.fonts[font]
        let size = StoryLook.textSize(text.count)
        ZStack {
            // The background crossfades to the one picked.
            StoryLook.brush(StoryLook.backgrounds[bg].0).ignoresSafeArea().animation(.easeInOut(duration: 0.45), value: bg)

            ZStack {
                if text.isEmpty {
                    Text(L("st_type_status")).font(StoryLook.font(fontName, size: size)).foregroundStyle(.white.opacity(0.6))
                        .multilineTextAlignment(storyTextAlign(align)).frame(maxWidth: .infinity, alignment: storyFrameAlign(align))
                }
                TextField("", text: Binding(get: { text }, set: { text = String($0.prefix(700)) }), axis: .vertical)
                    .font(StoryLook.font(fontName, size: size)).foregroundStyle(.white).tint(.white)
                    .multilineTextAlignment(storyTextAlign(align)).lineSpacing(size * 0.25)
                    .frame(maxWidth: .infinity)
                    .focused($focused)
            }
            .animation(.spring(response: 0.4), value: size)
            .padding(.horizontal, 28).padding(.top, 80).padding(.bottom, 200)

            // Stickers and drawing on top (backlog 79–80).
            StoryLayersEditable(ed: ed, deselectOnTap: false).ignoresSafeArea()

            VStack(spacing: 0) {
                if ed.drawing {
                    DrawingBar(ed: ed)
                } else {
                    StoryTopBar(onClose: editor.onClose) {
                        LayerToolButtons(ed: ed, withText: false)
                        MentionTool(mentions: $mentions)
                        StoryTool(label: "Aa", description: L("st_tool_font"), active: panel == .fonts) { panel = panel == .fonts ? .closed : .fonts }
                            .scaleEffect(fontBounce ? 1.25 : 1)
                            .animation(.spring(response: 0.25, dampingFraction: 0.35), value: fontBounce)
                        StoryTool(icon: alignIcon(align), description: L("st_tool_align")) {
                            align = align == "center" ? "start" : align == "start" ? "end" : "center"
                        }
                        StoryTool(icon: "paintpalette.fill", description: L("st_tool_colors"), active: panel == .colors) { panel = panel == .colors ? .closed : .colors }
                    }
                }
                Spacer(minLength: 0)
                if !ed.drawing {
                    VStack(spacing: 0) {
                        if panel == .colors {
                            BackgroundSwatches(selected: bg) { bg = $0 }.padding(.bottom, 12).transition(.move(edge: .bottom).combined(with: .opacity))
                        }
                        if panel == .fonts {
                            FontChips(selected: font) { font = $0; bounceFont() }.padding(.bottom, 12).transition(.move(edge: .bottom).combined(with: .opacity))
                        }
                        // Near the limit: how much is left.
                        if text.count > 600 {
                            Text(verbatim: "\(text.count)/700").font(.dorr(12, .bold)).foregroundStyle(.white.opacity(0.8)).padding(.bottom, 8)
                        }
                        StoryModeSwitch(mode: .text, onSwitch: editor.onSwitch)
                        Spacer().frame(height: 12)
                        StoryBottomBar(host: host, allowReplies: allowReplies, onToggleReplies: { allowReplies.toggle() }, sendEnabled: !trimmed.isEmpty, onSend: post)
                    }
                    .padding(.horizontal, 14).padding(.vertical, 12)
                    .animation(.easeOut(duration: 0.2), value: panel)
                }
            }
        }
        .onAppear {
            focused = true
            editor.onDirty(made)
        }
        .onChange(of: made) { editor.onDirty($0) }
        .onChange(of: ed.drawing) { if $0 { focused = false } }
        .modifier(LayerStickerPicker(ed: ed))
    }

    private func post() {
        var fields: [String: String] = [
            "type": "text", "body": trimmed, "style[background]": StoryLook.backgrounds[bg].0, "style[font]": StoryLook.fonts[font],
            "style[align]": align, "allow_replies": allowReplies ? "1" : "0",
        ]
        fields.merge(mentionFields(mentions)) { $1 }
        fields.merge(ed.fields()) { $1 }
        host.postStory(fields, file: nil)
    }

    private func bounceFont() {
        fontBounce = true
        Task {
            try? await Task.sleep(nanoseconds: 200_000_000)
            fontBounce = false
        }
    }

    /// The icon shows where the text lines up now (start is the right side in Arabic).
    private func alignIcon(_ align: String) -> String {
        let rtl = layoutDirection == .rightToLeft
        switch align {
        case "start": return rtl ? "text.alignright" : "text.alignleft"
        case "end": return rtl ? "text.alignleft" : "text.alignright"
        default: return "text.aligncenter"
        }
    }
}

/// `style.align` → how a text status lines up (the editor and the viewer).
func storyTextAlign(_ align: String?) -> TextAlignment {
    switch align {
    case "start": return .leading
    case "end": return .trailing
    default: return .center
    }
}

/// The same, for the frame the text sits in.
func storyFrameAlign(_ align: String?) -> Alignment {
    switch align {
    case "start": return .leading
    case "end": return .trailing
    default: return .center
    }
}

// MARK: - photo / video

/// One picked photo / video and what was made on it (its caption, its stickers and drawing).
@MainActor
private final class StoryMediaItem: ObservableObject {
    let url: URL
    let mime: String
    let ed: StoryLayerEditor
    @Published var caption = ""
    /// The picture's own shape: the layers sit in exactly that frame on every phone.
    @Published var aspect: Double?
    /// The photo, scaled down to what a screen shows.
    @Published var image: UIImage?
    /// Small: the photo, or a video's first frame — for the row of picked ones.
    @Published var thumb: UIImage?
    @Published var durationMs: Int64?

    var isVideo: Bool { mime.hasPrefix("video/") }
    var tooLong: Bool { isVideo && (durationMs ?? 0) > Int64(videoLimitSeconds) * 1000 + 500 }

    init(url: URL) {
        self.url = url
        mime = UTType(filenameExtension: url.pathExtension)?.preferredMIMEType ?? "image/jpeg"
        ed = StoryLayerEditor()
    }

    func load() async {
        if isVideo {
            let asset = AVURLAsset(url: url)
            if let d = try? await asset.load(.duration) { durationMs = Int64(CMTimeGetSeconds(d) * 1000) }
            // A video's width ÷ height as it plays (turned phones' videos swap the two).
            if let track = try? await asset.loadTracks(withMediaType: .video).first,
               let loaded = try? await track.load(.naturalSize, .preferredTransform) {
                let r = CGRect(origin: .zero, size: loaded.0).applying(loaded.1)
                if r.width != 0, r.height != 0 { aspect = Double(abs(r.width) / abs(r.height)) }
            }
            let generator = AVAssetImageGenerator(asset: asset)
            generator.appliesPreferredTrackTransform = true
            generator.maximumSize = CGSize(width: 240, height: 240)
            if let shot = try? await generator.image(at: .zero) { thumb = UIImage(cgImage: shot.image) }
        } else if let data = try? Data(contentsOf: url), let img = UIImage(data: data) {
            if img.size.width > 0, img.size.height > 0 { aspect = Double(img.size.width / img.size.height) }
            // Up to 10 full-size photos at once would be too much memory: scaled for the screen and the thumbnail.
            image = await img.byPreparingThumbnail(ofSize: fitting(img.size, 2048)) ?? img
            thumb = await img.byPreparingThumbnail(ofSize: fitting(img.size, 200))
        }
    }

    private func fitting(_ size: CGSize, _ longest: CGFloat) -> CGSize {
        let scale = min(1, longest / max(size.width, size.height, 1))
        return CGSize(width: size.width * scale, height: size.height * scale)
    }
}

/// The picked photos / videos, kept for the whole edit.
@MainActor
private final class StoryMediaBatch: ObservableObject {
    let items: [StoryMediaItem]
    private var loaded = false

    init(_ urls: [URL]) { items = urls.map { StoryMediaItem(url: $0) } }

    func load() async {
        guard !loaded else { return }
        loaded = true
        for item in items {
            await item.load()
            // A video's length decides whether send is allowed.
            objectWillChange.send()
        }
    }
}

private struct MediaStoryEditor: View {
    @ObservedObject var host: ChatHost
    let editor: StoryEditorHost
    @StateObject private var batch: StoryMediaBatch
    @State private var index = 0
    @State private var allowReplies = true
    @State private var mentions: [Int] = []

    init(host: ChatHost, urls: [URL], editor: StoryEditorHost) {
        _host = ObservedObject(wrappedValue: host)
        self.editor = editor
        _batch = StateObject(wrappedValue: StoryMediaBatch(urls))
    }

    var body: some View {
        ZStack {
            if !batch.items.isEmpty {
                let item = batch.items[min(max(index, 0), batch.items.count - 1)]
                // Each one keeps its own caption and drawing; the page itself is fresh for each.
                MediaItemPage(
                    host: host, editor: editor, item: item, ed: item.ed, items: batch.items, index: $index,
                    allowReplies: $allowReplies, mentions: $mentions, sendEnabled: !batch.items.contains { $0.tooLong }, onPost: post
                )
                .id(index)
            }
        }
        .task { await batch.load() }
        // A picked photo is already something to lose.
        .onAppear { editor.onDirty(true) }
    }

    /// Each one its own status, in order (the file is read when its turn comes).
    private func post() {
        var uploads: [(fields: [String: String], file: () -> ChatFile?)] = []
        for (i, it) in batch.items.enumerated() {
            var fields = ["type": it.isVideo ? "video" : "image", "allow_replies": allowReplies ? "1" : "0"]
            let body = it.caption.trimmingCharacters(in: .whitespacesAndNewlines)
            if !body.isEmpty { fields["body"] = body }
            if let d = it.durationMs { fields["duration_ms"] = String(d) }
            if let a = it.aspect { fields["style[aspect]"] = String(format: "%.4f", locale: Locale(identifier: "en_US_POSIX"), min(max(a, 0.2), 5)) }
            fields.merge(mentionFields(mentions)) { $1 }
            fields.merge(it.ed.fields()) { $1 }
            let url = it.url, mime = it.mime, name = it.isVideo ? "story_\(i).mp4" : "story_\(i).jpg"
            uploads.append((fields, { (try? Data(contentsOf: url)).map { ChatFile(field: "file", filename: name, mimeType: mime, data: $0) } }))
        }
        if !uploads.isEmpty { host.postStories(uploads) }
    }
}

/// One picked photo / video being edited: the picture with its layers, the tools, then (bottom up) caption + send,
/// who sees it and replies, the kinds, and the picked ones to switch between.
private struct MediaItemPage: View {
    @ObservedObject var host: ChatHost
    let editor: StoryEditorHost
    @ObservedObject var item: StoryMediaItem
    @ObservedObject var ed: StoryLayerEditor
    let items: [StoryMediaItem]
    @Binding var index: Int
    @Binding var allowReplies: Bool
    @Binding var mentions: [Int]
    let sendEnabled: Bool
    let onPost: () -> Void
    @State private var paused = false

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()
            ZStack {
                if item.isVideo {
                    LoopingVideoView(url: item.url, paused: paused)
                } else if let image = item.image {
                    Image(uiImage: image).resizable().scaledToFit()
                }
                if item.aspect != nil { StoryLayersEditable(ed: ed) }
            }
            .aspectRatio(item.aspect.map { CGFloat($0) }, contentMode: .fit)
            .ignoresSafeArea(.keyboard)
            // Soft shade top and bottom: the tools read on any picture.
            StoryScrims()

            VStack(alignment: .leading, spacing: 0) {
                if ed.drawing {
                    DrawingBar(ed: ed)
                } else {
                    StoryTopBar(onClose: editor.onClose) {
                        if item.aspect != nil { LayerToolButtons(ed: ed, withText: true) }
                        MentionTool(mentions: $mentions)
                    }
                    if item.isVideo { videoChip.padding(.horizontal, 14) }
                }
                Spacer(minLength: 0)
                if !ed.busy { bottom.frame(maxWidth: .infinity) }
            }
            LayerTextWriter(ed: ed)
        }
        .modifier(LayerStickerPicker(ed: ed))
    }

    /// A video: pause / play, and how long it is.
    private var videoChip: some View {
        Button { paused.toggle() } label: {
            HStack(spacing: 5) {
                Image(systemName: paused ? "play.fill" : "pause.fill").font(.system(size: 13, weight: .bold))
                Text(verbatim: durationText(item.durationMs ?? 0)).font(.dorr(12, .bold))
            }
            .foregroundStyle(.white)
            .padding(.horizontal, 10).padding(.vertical, 6)
            .background(Color.black.opacity(0.45), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
        .buttonStyle(.plain)
    }

    private var bottom: some View {
        VStack(spacing: 0) {
            if item.tooLong {
                Text(L("st_video_too_long", videoLimitSeconds)).font(.dorr(14, .bold)).foregroundStyle(.white)
                    .padding(.horizontal, 12).padding(.vertical, 6)
                    .background(Color(hex: 0xDC2626).opacity(0.8), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .padding(.bottom, 10)
                    .transition(.opacity)
            }
            // Several picked: each one, to switch between.
            if items.count > 1 { MediaThumbs(items: items, index: index) { index = $0 } }
            StoryModeSwitch(mode: .media, onSwitch: editor.onSwitch)
            Spacer().frame(height: 10)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    AudienceChip(host: host)
                    RepliesChip(host: host, allowReplies: allowReplies) { allowReplies.toggle() }
                }
            }
            Spacer().frame(height: 10)
            // The caption and send, in one line (as in WhatsApp).
            HStack(alignment: .bottom, spacing: 10) {
                TextField("", text: Binding(get: { item.caption }, set: { item.caption = String($0.prefix(700)) }),
                          prompt: Text(L("st_add_caption")).foregroundColor(.white.opacity(0.7)), axis: .vertical)
                    .lineLimit(1...4).font(.dorr(15)).foregroundStyle(.white).tint(.white)
                    .padding(.horizontal, 18).padding(.vertical, 15)
                    .frame(maxWidth: .infinity, minHeight: 52, alignment: .leading)
                    .background(Color.black.opacity(0.5), in: RoundedRectangle(cornerRadius: 26, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 26, style: .continuous).stroke(Color.white.opacity(0.2), lineWidth: 1))
                PostButton(enabled: sendEnabled, action: onPost)
            }
        }
        .padding(.horizontal, 14).padding(.vertical, 12)
    }
}

/// The picked photos / videos in a row: the one being edited is outlined.
private struct MediaThumbs: View {
    let items: [StoryMediaItem]
    let index: Int
    let onPick: (Int) -> Void

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(Array(items.enumerated()), id: \.offset) { i, it in
                    MediaThumb(item: it, on: i == index).onTapGesture { onPick(i) }
                }
            }
            .padding(.horizontal, 2)
            .frame(height: 60)
        }
        .padding(.bottom, 10)
    }
}

private struct MediaThumb: View {
    @ObservedObject var item: StoryMediaItem
    let on: Bool

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 12, style: .continuous)
        ZStack {
            if let thumb = item.thumb { Image(uiImage: thumb).resizable().scaledToFill() } else { Color(hex: 0x1F2937) }
            if item.isVideo { Image(systemName: "play.fill").font(.system(size: 16)).foregroundStyle(.white) }
        }
        .frame(width: on ? 56 : 50, height: on ? 56 : 50)
        .clipShape(shape)
        .overlay(shape.stroke(Color.white.opacity(on ? 1 : 0.35), lineWidth: on ? 2.5 : 1))
        .contentShape(Rectangle())
        .animation(.easeOut(duration: 0.2), value: on)
    }
}

/// The picked video, playing over and over while the status is made (or paused).
private struct LoopingVideoView: UIViewRepresentable {
    let url: URL?
    var paused = false

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
        if !paused { player.play() }
        return v
    }

    func updateUIView(_ v: PlayerView, context: Context) {
        guard let player = v.playerLayer.player else { return }
        if paused { player.pause() } else if player.rate == 0 { player.play() }
    }

    static func dismantleUIView(_ v: PlayerView, coordinator: ()) {
        v.playerLayer.player?.pause()
        v.looper = nil
        v.playerLayer.player = nil
    }
}

// MARK: - voice

/// Record a voice status on a colour of my choice (up to the story length): before — the mic and a hint; while
/// recording — the bars move with my voice, a big timer and a ring filling to the limit; after — ▶ to hear it first
/// (the bars fill as it plays) and 🗑 to record again.
private struct VoiceStoryEditor: View {
    @ObservedObject var host: ChatHost
    let editor: StoryEditorHost
    @StateObject private var recorder = ChatVoiceRecorder()
    /// Hearing it before posting: the chat's own player (nothing asks the server).
    @ObservedObject private var player = VoicePlayer.shared
    @State private var clip: ChatVoiceRecorder.Clip?
    @State private var bg = 0
    @State private var colors = false
    @State private var allowReplies = true
    @State private var mentions: [Int] = []
    @State private var pulse = false

    private let previewId = "story-voice-preview"
    private var limitMs: Int64 { Int64(videoLimitSeconds) * 1000 }
    private var made: Bool { clip != nil || recorder.recording }
    private var playing: Bool { player.isPlaying(previewId) }

    var body: some View {
        ZStack {
            StoryLook.brush(StoryLook.backgrounds[bg].0).ignoresSafeArea().animation(.easeInOut(duration: 0.45), value: bg)
            VStack(spacing: 0) {
                if recorder.recording {
                    VoiceBars(levels: recorder.levels).frame(height: 90)
                    Text(verbatim: durationText(recorder.elapsedMs) + " / " + durationText(limitMs)).font(.dorr(28, .heavy)).foregroundStyle(.white).padding(.top, 16)
                } else if let clip {
                    VoiceBars(levels: clip.waveform, progress: player.playingId == previewId ? player.progress : 1).frame(height: 90)
                    Text(verbatim: durationText(clip.durationMs)).font(.dorr(28, .heavy)).foregroundStyle(.white).padding(.top, 16)
                }
                Spacer().frame(height: 26)
                if clip != nil && !recorder.recording { playRow } else { micButton }
                Text(verbatim: hint).font(.dorr(15, .bold)).foregroundStyle(.white.opacity(0.9)).multilineTextAlignment(.center).padding(.top, 14)
            }
            .padding(.horizontal, 24).padding(.bottom, 120)

            VStack(spacing: 0) {
                StoryTopBar(onClose: editor.onClose) {
                    MentionTool(mentions: $mentions)
                    StoryTool(icon: "paintpalette.fill", description: L("st_tool_colors"), active: colors) { colors.toggle() }
                }
                Spacer(minLength: 0)
                VStack(spacing: 0) {
                    if colors {
                        BackgroundSwatches(selected: bg) { bg = $0 }.padding(.bottom, 12).transition(.move(edge: .bottom).combined(with: .opacity))
                    }
                    StoryModeSwitch(mode: .voice, onSwitch: editor.onSwitch)
                    Spacer().frame(height: 12)
                    StoryBottomBar(host: host, allowReplies: allowReplies, onToggleReplies: { allowReplies.toggle() },
                                   sendEnabled: clip != nil && !recorder.recording, onSend: post)
                }
                .padding(.horizontal, 14).padding(.vertical, 12)
                .animation(.easeOut(duration: 0.2), value: colors)
            }
        }
        // The longest a status may be.
        .onChange(of: recorder.elapsedMs) { if recorder.recording, $0 >= limitMs { stop() } }
        .onChange(of: recorder.recording) { pulse = $0 }
        .onAppear { editor.onDirty(made) }
        .onChange(of: made) { editor.onDirty($0) }
        .onDisappear {
            if recorder.recording { recorder.cancel() }
            releasePlayer()
        }
    }

    /// The mic, with a ring filling up to the limit while recording.
    private var micButton: some View {
        let filled = recorder.recording ? min(max(Double(recorder.elapsedMs) / Double(limitMs), 0), 1) : 0
        return ZStack {
            Circle().stroke(Color.white.opacity(0.25), lineWidth: 4)
            Circle().trim(from: 0, to: filled).stroke(Color.white, style: StrokeStyle(lineWidth: 4, lineCap: .round)).rotationEffect(.degrees(-90))
            Button(action: tapMic) {
                Image(systemName: recorder.recording ? "stop.fill" : "mic.fill").font(.system(size: 40)).foregroundStyle(Ch.shared.red)
                    .frame(width: 92, height: 92).background(Color.white, in: Circle())
            }
            .buttonStyle(.plain)
            .scaleEffect(recorder.recording && pulse ? 1.08 : 1)
            .animation(recorder.recording ? .easeInOut(duration: 0.6).repeatForever(autoreverses: true) : .default, value: pulse)
        }
        .frame(width: 112, height: 112)
    }

    /// 🗑 record again · ▶ hear it.
    private var playRow: some View {
        HStack(spacing: 22) {
            Button {
                releasePlayer()
                clip = nil
            } label: {
                Image(systemName: "trash.fill").font(.system(size: 22)).foregroundStyle(.white)
                    .frame(width: 56, height: 56).background(Color.black.opacity(0.3), in: Circle())
                    .overlay(Circle().stroke(Color.white.opacity(0.35), lineWidth: 1))
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("st_voice_rerecord"))
            Button(action: togglePlay) {
                Image(systemName: playing ? "pause.fill" : "play.fill").font(.system(size: 44)).foregroundStyle(Ch.shared.red)
                    .frame(width: 92, height: 92).background(Color.white, in: Circle())
            }
            .buttonStyle(.plain)
            Color.clear.frame(width: 56, height: 56)
        }
    }

    private var hint: String {
        if recorder.recording { return L("st_voice_tap_stop") }
        if clip != nil { return L("st_voice_preview") }
        return L("bk_voice_hold", videoLimitSeconds)
    }

    private func stop() { clip = recorder.finish() }

    private func releasePlayer() {
        if player.playingId == previewId { player.stop() }
    }

    private func togglePlay() {
        guard let c = clip else { return }
        player.toggle(previewId, c.url)
    }

    private func tapMic() {
        if recorder.recording { stop(); return }
        if recorder.permitted {
            releasePlayer()
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
        releasePlayer()
        var fields = ["type": "voice", "duration_ms": String(c.durationMs), "style[background]": StoryLook.backgrounds[bg].0, "allow_replies": allowReplies ? "1" : "0"]
        for (i, v) in c.waveform.enumerated() { fields["style[waveform][\(i)]"] = String(v) }
        fields.merge(mentionFields(mentions)) { $1 }
        host.postStory(fields, file: ChatFile(field: "file", filename: c.url.lastPathComponent, mimeType: "audio/mp4", data: data))
    }
}

// MARK: - shared: the editor's parts

/// A round tool over the status: the same size, glass look and order on every kind. `active` (its panel is open)
/// turns it white with the app's red; `badge` counts (people mentioned).
struct StoryTool: View {
    var icon: String?
    var label: String?
    let description: String
    var active = false
    var badge = 0
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            ZStack {
                if let icon { Image(systemName: icon).font(.system(size: 18, weight: .semibold)) }
                if let label { Text(verbatim: label).font(.dorr(16, .heavy)) }
            }
            .foregroundStyle(active ? Ch.shared.red : .white)
            .frame(width: 44, height: 44)
            .background(active ? Color.white : Color.black.opacity(0.28), in: Circle())
            .overlay(Circle().stroke(Color.white.opacity(active ? 1 : 0.3), lineWidth: 1))
        }
        .buttonStyle(.plain)
        .overlay(alignment: .topTrailing) {
            if badge > 0 {
                Text(verbatim: "\(badge)").font(.dorr(10, .bold)).foregroundStyle(.white)
                    .frame(width: 18, height: 18).background(Ch.shared.red, in: Circle())
                    .overlay(Circle().stroke(Color.white, lineWidth: 1.5))
            }
        }
        .accessibilityLabel(description)
        .animation(.easeOut(duration: 0.2), value: active)
    }
}

/// The top of every kind: ✕ at the start, then the tools — always in the same order.
struct StoryTopBar<Tools: View>: View {
    let onClose: () -> Void
    @ViewBuilder let tools: () -> Tools

    var body: some View {
        HStack(spacing: 0) {
            StoryTool(icon: "xmark", description: L("ch_close_sheet"), action: onClose)
            Spacer(minLength: 8)
            HStack(spacing: 8) { tools() }
        }
        .padding(.horizontal, 12).padding(.vertical, 10)
    }
}

/// The layer tools: 🗑 (the one selected) · stickers · text · pen.
struct LayerToolButtons: View {
    @ObservedObject var ed: StoryLayerEditor
    let withText: Bool

    var body: some View {
        HStack(spacing: 8) {
            if let id = ed.selected { StoryTool(icon: "trash", description: L("ch_delete")) { ed.remove(id) } }
            StoryTool(icon: "face.smiling", description: L("ch_expr_stickers")) {
                ed.selected = nil
                ed.picking = true
            }
            if withText {
                StoryTool(icon: "textformat", description: L("st_text")) {
                    ed.selected = nil
                    ed.writing = EditLayer(id: 0, dto: StoryLayerDto(k: "t", y: 0.4, c: "#FFFFFF", f: "bold"))
                }
            }
            StoryTool(icon: "paintbrush.pointed.fill", description: L("st_tool_draw")) {
                ed.selected = nil
                ed.drawing = true
            }
        }
    }
}

/// @ — pick saved contacts to mention (they're told in our chat); the count shows on it.
struct MentionTool: View {
    @Binding var mentions: [Int]
    @State private var picking = false

    var body: some View {
        StoryTool(icon: "at", description: L("bk_mention"), badge: mentions.count) { picking = true }
            .dorrDialog(isPresented: $picking, bottom: true) {
                PeoplePickerSheet(initial: Set(mentions), onDismiss: { picking = false }) { ids in
                    mentions = Array(ids.prefix(10))
                    picking = false
                }
                .environment(\.colorScheme, Ch.shared.dark ? .dark : .light)
            }
    }
}

/// Text · Photo or video · Voice — switch the kind without leaving.
struct StoryModeSwitch: View {
    let mode: StoryMode
    let onSwitch: (StoryMode) -> Void

    var body: some View {
        HStack(spacing: 0) {
            ForEach(StoryMode.allCases, id: \.self) { kind in
                let on = kind == mode
                Button { onSwitch(kind) } label: {
                    Text(L(kind.labelKey)).font(.dorr(13, .heavy)).lineLimit(1).foregroundStyle(on ? Ch.shared.red : .white)
                        .padding(.horizontal, 14).padding(.vertical, 7)
                        .background(on ? Color.white : Color.clear, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(4)
        .background(Color.black.opacity(0.35), in: RoundedRectangle(cornerRadius: 22, style: .continuous))
        .animation(.easeOut(duration: 0.2), value: mode)
    }
}

/// Who sees it · replies on/off · send. The chips scroll if they must — send is never pushed off.
struct StoryBottomBar: View {
    @ObservedObject var host: ChatHost
    let allowReplies: Bool
    let onToggleReplies: () -> Void
    let sendEnabled: Bool
    let onSend: () -> Void

    var body: some View {
        HStack(spacing: 10) {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    AudienceChip(host: host)
                    RepliesChip(host: host, allowReplies: allowReplies, onToggle: onToggleReplies)
                }
            }
            PostButton(enabled: sendEnabled, action: onSend)
        }
    }
}

/// From the home page: «Public» (a tap says what it means). From the chat: «Privacy» — who sees my statuses.
struct AudienceChip: View {
    @ObservedObject var host: ChatHost

    var body: some View {
        if host.publicMode {
            StoryChip(icon: "globe", text: L("st_public_short")) { host.showToast(L("st_public_hint")) }
        } else {
            StoryChip(icon: "shield.fill", text: L("st_privacy_short")) { host.push(.storyPrivacy) }
        }
    }
}

/// Replies: on shows ✓, off is dim with a crossed icon — and each tap says which it is now.
struct RepliesChip: View {
    @ObservedObject var host: ChatHost
    let allowReplies: Bool
    let onToggle: () -> Void

    var body: some View {
        if allowReplies {
            StoryChip(icon: "arrowshape.turn.up.left.fill", text: L("st_replies_short"), trailing: "checkmark") {
                onToggle()
                host.showToast(L("st_replies_off_toast"))
            }
        } else {
            StoryChip(icon: "nosign", text: L("st_replies_closed"), dim: true) {
                onToggle()
                host.showToast(L("st_replies_on_toast"))
            }
        }
    }
}

private struct StoryChip: View {
    let icon: String
    let text: String
    var dim = false
    var trailing: String?
    let action: () -> Void

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 20, style: .continuous)
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 13, weight: .semibold))
                Text(verbatim: text).font(.dorr(12.5, .bold)).lineLimit(1)
                if let trailing { Image(systemName: trailing).font(.system(size: 12, weight: .bold)).foregroundStyle(.white) }
            }
            .foregroundStyle(.white.opacity(dim ? 0.55 : 1))
            .padding(.horizontal, 12).padding(.vertical, 8)
            .background(Color.black.opacity(dim ? 0.5 : 0.35), in: shape)
            .overlay(shape.stroke(Color.white.opacity(dim ? 0.1 : 0.25), lineWidth: 1))
        }
        .buttonStyle(.plain)
    }
}

/// Send, in the app's red (as the chat's send button) — faded while there's nothing to post.
struct PostButton: View {
    let enabled: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: "paperplane.fill").font(.system(size: 22, weight: .semibold)).foregroundStyle(.white.opacity(enabled ? 1 : 0.6))
                .frame(width: 56, height: 56)
                .background(enabled ? Ch.shared.red : Color.white.opacity(0.22), in: Circle())
                .shadow(color: .black.opacity(enabled ? 0.35 : 0), radius: 12, y: 4)
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
        .scaleEffect(enabled ? 1 : 0.92)
        .animation(.spring(response: 0.35, dampingFraction: 0.5), value: enabled)
        .accessibilityLabel(L("ch_send"))
    }
}

/// The background colours to pick from (text and voice statuses): the one in use is ringed.
struct BackgroundSwatches: View {
    let selected: Int
    let onPick: (Int) -> Void

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 10) {
                ForEach(Array(StoryLook.backgrounds.enumerated()), id: \.offset) { i, look in
                    let on = i == selected
                    Circle().fill(LinearGradient(colors: look.1, startPoint: .topLeading, endPoint: .bottomTrailing))
                        .frame(width: on ? 38 : 32, height: on ? 38 : 32)
                        .overlay(Circle().stroke(Color.white.opacity(on ? 1 : 0.6), lineWidth: on ? 3 : 1.5))
                        .contentShape(Circle())
                        .onTapGesture { onPick(i) }
                }
            }
            .padding(.horizontal, 4)
            .frame(height: 44)
        }
    }
}

/// The fonts to pick from, each «Aa» written in itself.
private struct FontChips: View {
    let selected: Int
    let onPick: (Int) -> Void

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(Array(StoryLook.fonts.enumerated()), id: \.offset) { i, name in
                    let on = i == selected
                    let shape = RoundedRectangle(cornerRadius: 14, style: .continuous)
                    Text(verbatim: "Aa").font(StoryLook.font(name, size: 17)).foregroundStyle(on ? Ch.shared.red : .white)
                        .padding(.horizontal, 16).padding(.vertical, 8)
                        .background(on ? Color.white : Color.black.opacity(0.35), in: shape)
                        .overlay(shape.stroke(Color.white.opacity(0.3), lineWidth: 1))
                        .contentShape(shape)
                        .onTapGesture { onPick(i) }
                }
            }
            .padding(.horizontal, 4)
        }
    }
}

/// A soft shade at the top and bottom of a photo / video, so the tools read on any picture.
struct StoryScrims: View {
    var body: some View {
        VStack(spacing: 0) {
            LinearGradient(colors: [Color.black.opacity(0.5), Color.clear], startPoint: .top, endPoint: .bottom).frame(height: 150)
            Spacer(minLength: 0)
            LinearGradient(colors: [Color.clear, Color.black.opacity(0.65)], startPoint: .top, endPoint: .bottom).frame(height: 260)
        }
        .ignoresSafeArea()
        .allowsHitTesting(false)
    }
}

/// The people mentioned (backlog 84), as form fields.
private func mentionFields(_ ids: [Int]) -> [String: String] {
    Dictionary(uniqueKeysWithValues: ids.enumerated().map { ("mentions[\($0.offset)]", String($0.element)) })
}

extension ChatHost {
    /// Upload in the background: the page closes at once and «My status» spins until it's posted.
    func postStory(_ fields: [String: String], file: ChatFile?) {
        postStories([(fields, { file })])
    }

    /// Several statuses (photos / videos picked together), uploaded one after the other in that order; the first
    /// failure says why and stops the rest.
    func postStories(_ batch: [(fields: [String: String], file: () -> ChatFile?)]) {
        pop()
        storyUploading = true
        Task {
            var posted = 0
            for upload in batch {
                do {
                    var all = upload.fields
                    if publicMode { all["public"] = "1" }
                    _ = try await ChatApi.postStory(fields: all, file: upload.file())
                    posted += 1
                } catch {
                    // No HTTP answer at all (offline, tunnel down, timeout): say so instead of staying silent.
                    showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
                    break
                }
            }
            if posted > 0 { await refreshStories() }
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
