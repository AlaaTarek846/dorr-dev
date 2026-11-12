import AVFoundation
import SwiftUI

// androidApp `chat/StoryViewer.kt` (+ the viewing half of `StoryVoice.kt` and HomeStories' `DorrAvatar` /
// `DorrLinkButton`) — the full-screen stories viewer over the chat: people turn like the faces of a cube, a tap moves
// to the next / previous story, a hold pauses (and hides the bars), pulling down closes. Mine show who watched; other
// people's take a reply, a quick reaction (it floats up the screen), or can be muted; one that mentions me can be
// added to my status. Photos zoom very slowly, videos play to their length, voice statuses fill their bars as they play.

private let photoSeconds = 5.5
private let quickStoryReactions = ["❤️", "😂", "😮", "😢", "👏", "🔥"]

/// The full-screen stories viewer, over the chat.
struct StoryViewerOverlay: View {
    @ObservedObject var host: ChatHost

    var body: some View {
        ZStack {
            if let session = host.storyViewer {
                StoryViewer(host: host, groups: session.groups, start: session.start) {
                    withAnimation(.easeOut(duration: 0.2)) { host.storyViewer = nil }
                    Task { await host.refreshStories() }
                }
                .transition(.asymmetric(insertion: .scale(scale: 0.86).combined(with: .opacity), removal: .scale(scale: 0.9).combined(with: .opacity)))
            }
        }
        .animation(.spring(response: 0.35, dampingFraction: 0.85), value: host.storyViewer != nil)
    }
}

private struct StoryViewer: View {
    @ObservedObject var host: ChatHost
    let groups: [StoryGroupDto]
    let onClose: () -> Void
    @Environment(\.layoutDirection) private var direction
    @State private var page: Int
    /// The finger: sideways turns the cube, down pulls the story away.
    @State private var dragX: CGFloat = 0
    @State private var dragY: CGFloat = 0
    @State private var axis: Axis?
    @State private var turning = false

    init(host: ChatHost, groups: [StoryGroupDto], start: Int, onClose: @escaping () -> Void) {
        self.host = host
        self.groups = groups
        self.onClose = onClose
        _page = State(initialValue: start)
    }

    var body: some View {
        let rtl = direction == .rightToLeft
        let dismiss = min(max(dragY / 1400 * 3, 0), 1)
        GeometryReader { geo in
            let w = max(geo.size.width, 1)
            ZStack {
                ForEach(max(page - 1, 0)...min(page + 1, groups.count - 1), id: \.self) { p in
                    // Where this person sits, in screen widths: 0 on screen, ±1 beside it.
                    let pos = (rtl ? -1 : 1) * CGFloat(p - page) + dragX / w
                    StoryGroupPage(
                        host: host, group: groups[p],
                        active: p == page && !turning && dragX == 0 && dragY < 1,
                        onPrevGroup: { go(p - 1) },
                        onNextGroup: { if p < groups.count - 1 { go(p + 1) } else { onClose() } },
                        onClose: onClose,
                        onDrag: { drag($0, w: w, rtl: rtl) },
                        onDragEnd: { end($0, w: w, rtl: rtl) },
                        insets: windowInsets
                    )
                    .environment(\.layoutDirection, direction)
                    .frame(width: geo.size.width, height: geo.size.height)
                    .rotation3DEffect(.degrees(Double(min(max(pos, -1), 1)) * 90), axis: (0, 1, 0), anchor: pos < 0 ? .trailing : .leading, perspective: 0.5)
                    .offset(x: pos * w)
                    .opacity(abs(pos) >= 1 ? 0 : 1)
                    .zIndex(p == page ? 1 : 0)
                }
            }
            .environment(\.layoutDirection, .leftToRight)
            .offset(y: dragY)
            .scaleEffect(1 - dismiss * 0.35)
            .clipShape(RoundedRectangle(cornerRadius: dismiss * 60, style: .continuous))
        }
        // Edge to edge: the story fills the whole screen; its bars keep clear of the clock and the home indicator.
        .ignoresSafeArea(.container)
        .background(Color.black.opacity(1 - dismiss).ignoresSafeArea())
        .screenshotShield(groups[safe: page]?.blockScreenshots == true)
    }

    private func go(_ p: Int) {
        guard groups.indices.contains(p) else { return }
        turning = true
        withAnimation(.easeInOut(duration: 0.38)) { page = p }
        Task {
            try? await Task.sleep(nanoseconds: 400_000_000)
            turning = false
        }
    }

    private func drag(_ t: CGSize, w: CGFloat, rtl: Bool) {
        if axis == nil, max(abs(t.width), abs(t.height)) > 12 { axis = abs(t.width) > abs(t.height) ? .horizontal : .vertical }
        switch axis {
        case .horizontal:
            // No one before the first / after the last: it only gives a little.
            let toNext = (rtl ? t.width > 0 : t.width < 0)
            let edge = toNext ? page == groups.count - 1 : page == 0
            dragX = edge ? t.width * 0.25 : t.width
        case .vertical: dragY = max(t.height, 0)
        default: break
        }
    }

    private func end(_ t: CGSize, w: CGFloat, rtl: Bool) {
        defer { axis = nil }
        switch axis {
        case .horizontal:
            let toNext = rtl ? t.width > 0 : t.width < 0
            let target = abs(t.width) > w * 0.25 ? (toNext ? page + 1 : page - 1) : page
            if groups.indices.contains(target), target != page {
                // Carry on from where the finger left it.
                let p = target
                turning = true
                withAnimation(.easeOut(duration: 0.3)) {
                    page = p
                    dragX = 0
                }
                Task {
                    try? await Task.sleep(nanoseconds: 320_000_000)
                    turning = false
                }
            } else {
                withAnimation(.spring(response: 0.35, dampingFraction: 0.8)) { dragX = 0 }
            }
        case .vertical:
            if dragY > 260 / 3 { onClose() } else { withAnimation(.spring(response: 0.4, dampingFraction: 0.65)) { dragY = 0 } }
        default: break
        }
    }
}

/// The clock and home-indicator areas of the window (the viewer itself runs under them).
@MainActor private var windowInsets: EdgeInsets {
    let window = UIApplication.shared.connectedScenes.compactMap { ($0 as? UIWindowScene)?.keyWindow }.first
    let i = window?.safeAreaInsets ?? .zero
    return EdgeInsets(top: i.top, leading: i.left, bottom: i.bottom, trailing: i.right)
}

// MARK: - one person's stories

private struct StoryGroupPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let group: StoryGroupDto
    let active: Bool
    let onPrevGroup: () -> Void
    let onNextGroup: () -> Void
    let onClose: () -> Void
    let onDrag: (CGSize) -> Void
    let onDragEnd: (CGSize) -> Void
    /// The clock and the home indicator: the story runs under them, its bars stay clear.
    let insets: EdgeInsets
    @Environment(\.layoutDirection) private var direction

    @State private var stories: [StoryDto]
    @State private var index: Int
    @State private var paused = false
    @State private var uiHidden = false
    @State private var typing = false
    @State private var videoMs: Int64?
    @State private var viewersFor: StoryDto?
    @State private var menu = false
    @State private var bursts: [Burst] = []
    // The bar: what was filled before the current run, and since when it has been running.
    @State private var progressBase: Double = 0
    @State private var runningSince: Date?
    @State private var runTotal: Double = photoSeconds
    @State private var progressFor: String?
    @State private var pressStart: Date?
    @State private var hideTask: Task<Void, Never>?

    init(host: ChatHost, group: StoryGroupDto, active: Bool, onPrevGroup: @escaping () -> Void, onNextGroup: @escaping () -> Void,
         onClose: @escaping () -> Void, onDrag: @escaping (CGSize) -> Void, onDragEnd: @escaping (CGSize) -> Void, insets: EdgeInsets) {
        self.insets = insets
        self.host = host
        self.group = group
        self.active = active
        self.onPrevGroup = onPrevGroup
        self.onNextGroup = onNextGroup
        self.onClose = onClose
        self.onDrag = onDrag
        self.onDragEnd = onDragEnd
        _stories = State(initialValue: group.stories)
        // Open on the first unseen story (like WhatsApp), or the first one.
        _index = State(initialValue: group.stories.firstIndex { $0.seen != true } ?? 0)
    }

    private var holding: Bool { paused || typing || viewersFor != nil || menu }

    var body: some View {
        if let story = stories[safe: index] {
            ZStack {
                StoryContent(story: story, playing: active && !holding) { videoMs = $0 }

                // Taps (next / previous), a hold (pause), and the finger turning / pulling the viewer.
                GeometryReader { geo in
                    Color.clear.contentShape(Rectangle()).gesture(
                        DragGesture(minimumDistance: 0)
                            .onChanged { v in
                                if pressStart == nil {
                                    pressStart = Date()
                                    paused = true
                                    // Only a real hold hides the bars — a quick tap mustn't make them blink.
                                    hideTask = Task {
                                        try? await Task.sleep(nanoseconds: 250_000_000)
                                        if !Task.isCancelled { withAnimation(.easeOut(duration: 0.15)) { uiHidden = true } }
                                    }
                                }
                                if abs(v.translation.width) > 8 || abs(v.translation.height) > 8 { onDrag(v.translation) }
                            }
                            .onEnded { v in
                                hideTask?.cancel()
                                withAnimation { uiHidden = false }
                                paused = false
                                let quick = Date().timeIntervalSince(pressStart ?? Date()) < 0.22
                                pressStart = nil
                                let moved = abs(v.translation.width) > 8 || abs(v.translation.height) > 8
                                if moved { onDragEnd(v.translation); return }
                                if quick {
                                    // The «back» side is the reading start: left in English, right in Arabic.
                                    let leftSide = v.location.x < geo.size.width * 0.33
                                    if leftSide != (direction == .rightToLeft) { prev() } else { next() }
                                }
                            }
                    )
                }

                VStack(spacing: 0) {
                    if !uiHidden { topBar(story).transition(.opacity) }
                    Spacer()
                }

                ForEach(bursts) { b in BurstEmoji(burst: b) { bursts.removeAll { $0.id == b.id } } }

                VStack(spacing: 0) {
                    Spacer()
                    bottom(story).padding(.bottom, typing ? 0 : insets.bottom)
                }
            }
            .task(id: "\(story.id)|\(active)") { await markSeen(story) }
            // The bar drives the timing: photo / text for a few seconds, video for its length, voice for its own.
            .task(id: "\(story.id)|\(active)|\(holding)|\(videoMs ?? -1)") { await run(story) }
            .onChange(of: story.id) { _ in videoMs = nil }
            .dorrDialog(isPresented: Binding(get: { viewersFor != nil }, set: { if !$0 { viewersFor = nil } }), bottom: true) {
                if let s = viewersFor { ViewersSheet(story: s) { viewersFor = nil } }
            }
            .confirmationDialog("", isPresented: $menu, titleVisibility: .hidden) { menuItems(story) }
        }
    }

    private func next() { if index < stories.count - 1 { index += 1 } else { onNextGroup() } }
    private func prev() { if index > 0 { index -= 1 } else { onPrevGroup() } }

    private func progress(at now: Date) -> Double {
        guard let since = runningSince else { return progressBase }
        return min(progressBase + now.timeIntervalSince(since) / max(runTotal, 0.1), 1)
    }

    /// Mark seen as soon as it shows.
    private func markSeen(_ story: StoryDto) async {
        guard active, story.isMine != true, story.seen != true else { return }
        if story.isDorr == true { _ = try? await ChatApi.viewDorrStory(story.id) } else { _ = try? await ChatApi.viewStory(story.id) }
        if let i = stories.firstIndex(where: { $0.id == story.id }) { stories[i].seen = true }
    }

    private func run(_ story: StoryDto) async {
        // A new story starts its bar from zero; a pause keeps what was filled.
        if progressFor != story.id {
            progressFor = story.id
            progressBase = 0
            runningSince = nil
        } else {
            progressBase = progress(at: Date())
            runningSince = nil
        }
        guard active, !holding else { return }
        let total: Double
        switch story.type {
        case "video":
            guard let videoMs else { return } // wait for the video to start
            total = Double(videoMs) / 1000
        case "voice": total = Double(max(story.durationMs ?? 15_000, 1000)) / 1000
        default: total = photoSeconds
        }
        runTotal = total
        runningSince = Date()
        let remaining = max((1 - progressBase) * total, 0.001)
        try? await Task.sleep(nanoseconds: UInt64(remaining * 1_000_000_000))
        guard !Task.isCancelled else { return }
        progressBase = 1
        runningSince = nil
        next()
    }

    // MARK: top: bars + owner

    private func topBar(_ story: StoryDto) -> some View {
        let owner = group.owner
        return VStack(spacing: 10) {
            TimelineView(.animation(paused: runningSince == nil)) { ctx in
                let current = progress(at: ctx.date)
                HStack(spacing: 4) {
                    ForEach(Array(stories.indices), id: \.self) { i in
                        let fill = i < index ? 1 : i == index ? current : 0
                        GeometryReader { g in
                            ZStack(alignment: .leading) {
                                Capsule().fill(Color.white.opacity(0.3))
                                Capsule().fill(Color.white).frame(width: g.size.width * fill)
                            }
                        }
                        .frame(height: 3)
                    }
                }
            }
            HStack(spacing: 10) {
                if story.isDorr == true { DorrAvatar(size: 38) } else { ChAvatar(url: owner?.avatar, name: owner?.name, key: owner?.key, size: 38) }
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: story.isMine == true ? L("st_my_status") : ltrTitle(owner?.name)).font(.dorr(15, .heavy)).foregroundStyle(.white)
                    Text(verbatim: aiTimeAgo(story.createdAt)).font(.dorr(12)).foregroundStyle(.white.opacity(0.75))
                }
                Spacer()
                if story.isDorr != true {
                    Button { menu = true } label: {
                        Image(systemName: "ellipsis").rotationEffect(.degrees(90)).font(.system(size: 18, weight: .bold)).foregroundStyle(.white).frame(width: 40, height: 40)
                    }
                    .buttonStyle(.plain)
                }
                Button(action: onClose) {
                    Image(systemName: "xmark").font(.system(size: 18, weight: .bold)).foregroundStyle(.white).frame(width: 40, height: 40)
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.horizontal, 10).padding(.top, 10 + insets.top).padding(.bottom, 26)
        .background(LinearGradient(colors: [.black.opacity(0.55), .clear], startPoint: .top, endPoint: .bottom).ignoresSafeArea(edges: .top))
    }

    @ViewBuilder
    private func menuItems(_ story: StoryDto) -> some View {
        if story.isMine == true {
            Button(L("st_delete"), role: .destructive) {
                Task {
                    _ = try? await ChatApi.deleteStory(story.id)
                    host.showToast(L("st_deleted"))
                    stories.removeAll { $0.id == story.id }
                    if stories.isEmpty { onClose() } else if index > stories.count - 1 { index = stories.count - 1 }
                }
            }
        } else if let owner = group.owner {
            Button(L("st_mute", ltrTitle(owner.name))) {
                Task {
                    if let feed = try? await ChatApi.muteStories(["participant_id": owner.id, "muted": true]).data { host.stories = feed }
                    onNextGroup()
                }
            }
        }
    }

    // MARK: bottom: caption, reply / viewers

    @ViewBuilder
    private func bottom(_ story: StoryDto) -> some View {
        VStack(spacing: 0) {
            // It mentions me: I may add it to my status (backlog 85).
            if story.mentionedMe == true, story.isMine != true, !uiHidden, !typing { MentionedMeBar(host: host, story: story) }
            if story.type != "text", let body = story.body, !body.trimmingCharacters(in: .whitespaces).isEmpty, !uiHidden {
                Text(verbatim: body).font(.dorr(15)).foregroundStyle(.white).multilineTextAlignment(.center)
                    .frame(maxWidth: .infinity).padding(.horizontal, 20).padding(.vertical, 12).background(Color.black.opacity(0.35))
            }
            if !uiHidden || typing {
                if story.isMine == true {
                    Button { viewersFor = story } label: {
                        VStack(spacing: 2) {
                            Image(systemName: "chevron.up").font(.system(size: 16, weight: .bold))
                            HStack(spacing: 6) {
                                Image(systemName: "eye.fill").font(.system(size: 15))
                                Text(verbatim: "\(story.views ?? 0)").font(.dorr(15, .heavy))
                            }
                        }
                        .foregroundStyle(.white).frame(maxWidth: .infinity).padding(.top, 8).padding(.bottom, 18).contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                } else if story.isDorr == true {
                    // Dorr's own story: its «Open» button, if it has a link.
                    if let url = story.linkUrl { DorrLinkButton(label: story.linkLabel, url: url) }
                } else if story.allowReplies != false {
                    StoryReplyBar(
                        onFocus: { typing = $0 },
                        onReact: { emoji in
                            bursts += (0..<7).map { _ in Burst(emoji: emoji) }
                            Task { _ = try? await ChatApi.reactStory(story.id, emoji: emoji) }
                        },
                        onSend: { text in
                            Task {
                                do {
                                    _ = try await ChatApi.replyStory(story.id, body: text)
                                    host.showToast(L("st_replied"))
                                } catch {
                                    host.showToast(error.apiFailure.message ?? L("st_replied"))
                                }
                            }
                        }
                    )
                } else {
                    Text(L("st_replies_off")).font(.dorr(12)).foregroundStyle(.white.opacity(0.6)).frame(maxWidth: .infinity).padding(18)
                }
            }
        }
        .animation(.easeOut(duration: 0.2), value: uiHidden)
    }
}

// MARK: - content

private struct StoryContent: View {
    let story: StoryDto
    let playing: Bool
    let onVideoReady: (Int64) -> Void

    var body: some View {
        switch story.type {
        case "text":
            ZStack {
                StoryLook.brush(story.style?.background).ignoresSafeArea()
                let body = story.body ?? ""
                let size = StoryLook.textSize(body.count)
                // Lined up as it was written (start / centre / end).
                Text(verbatim: body).font(StoryLook.font(story.style?.font, size: size)).foregroundStyle(.white)
                    .multilineTextAlignment(storyTextAlign(story.style?.align)).lineSpacing(size * 0.25)
                    .frame(maxWidth: .infinity, alignment: storyFrameAlign(story.style?.align)).padding(28)
                StoryLayersView(layers: story.style?.layers)
            }
        case "image":
            PhotoStory(story: story, playing: playing)
        case "voice":
            VoiceStoryContent(story: story, playing: playing)
        default:
            ZStack {
                Color.black.ignoresSafeArea()
                if let aspect = story.style?.aspect, aspect > 0 {
                    ZStack {
                        StoryVideoView(url: ApiClient.mediaUrl(story.media?.url), playing: playing, onReady: onVideoReady)
                        StoryLayersView(layers: story.style?.layers)
                    }
                    .aspectRatio(aspect, contentMode: .fit)
                } else {
                    StoryVideoView(url: ApiClient.mediaUrl(story.media?.url), playing: playing, onReady: onVideoReady)
                }
            }
        }
    }
}

/// A blurred, zoomed copy fills the edges of photos that aren't phone-shaped; the photo zooms very slowly (Ken Burns).
private struct PhotoStory: View {
    let story: StoryDto
    let playing: Bool
    @State private var image: UIImage?

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()
            if let image {
                Image(uiImage: image).resizable().scaledToFill()
                    .frame(maxWidth: .infinity, maxHeight: .infinity).clipped()
                    .blur(radius: ChatLite.shared.active ? 0 : 40).opacity(0.55).ignoresSafeArea()
                ZStack {
                    Image(uiImage: image).resizable().scaledToFit()
                    if let aspect = story.style?.aspect, aspect > 0 {
                        StoryLayersView(layers: story.style?.layers).aspectRatio(aspect, contentMode: .fit)
                    }
                }
                .scaleEffect(playing ? 1.08 : 1)
                .animation(playing ? .linear(duration: photoSeconds) : .easeOut(duration: 0.3), value: playing)
            } else {
                ProgressView().tint(.white)
            }
        }
        .task(id: story.id) {
            guard let url = ApiClient.mediaUrl(story.media?.url), let (data, _) = try? await URLSession.shared.data(from: url) else { return }
            image = UIImage(data: data)
        }
    }
}

/// A video story: plays when shown, pauses on hold; tells its length once it knows it.
private struct StoryVideoView: UIViewRepresentable {
    let url: URL?
    let playing: Bool
    let onReady: (Int64) -> Void

    final class PlayerView: UIView {
        override static var layerClass: AnyClass { AVPlayerLayer.self }
        var playerLayer: AVPlayerLayer { layer as! AVPlayerLayer }
        var ready = false
    }

    func makeUIView(context: Context) -> PlayerView {
        let v = PlayerView()
        v.playerLayer.videoGravity = .resizeAspect
        guard let url else { return v }
        let player = AVPlayer(url: url)
        player.actionAtItemEnd = .pause
        v.playerLayer.player = player
        Task { @MainActor in
            let duration = (try? await player.currentItem?.asset.load(.duration)).map(CMTimeGetSeconds) ?? 0
            v.ready = true
            onReady(Int64(max(duration.isFinite ? duration * 1000 : 15_000, 1000)))
            if playing { player.play() }
        }
        return v
    }

    func updateUIView(_ v: PlayerView, context: Context) {
        guard v.ready, let player = v.playerLayer.player else { return }
        if playing, player.rate == 0 { player.play() } else if !playing, player.rate != 0 { player.pause() }
    }

    static func dismantleUIView(_ v: PlayerView, coordinator: ()) {
        v.playerLayer.player?.pause()
        v.playerLayer.player = nil
    }
}

/// A voice status: its colour, its bars filling as it plays, the sound itself.
struct VoiceStoryContent: View {
    let story: StoryDto
    let playing: Bool
    @StateObject private var player = StoryVoicePlayer()

    var body: some View {
        ZStack {
            StoryLook.brush(story.style?.background).ignoresSafeArea()
            VStack(spacing: 24) {
                Image(systemName: "mic.fill").font(.system(size: 40)).foregroundStyle(.white)
                    .frame(width: 84, height: 84).background(Color.white.opacity(0.2), in: Circle())
                let bars = (story.style?.waveform ?? []).isEmpty ? (0..<36).map { 20 + ($0 * 37) % 60 } : story.style?.waveform ?? []
                VoiceBars(levels: bars, progress: player.progress).frame(height: 80).padding(.horizontal, 30)
            }
        }
        .onAppear { player.load(ApiClient.mediaUrl(story.media?.url)) }
        .onDisappear { player.stop() }
        .onChange(of: playing) { player.set(playing: $0) }
        .onChange(of: player.ready) { if $0 { player.set(playing: playing) } }
    }
}

@MainActor
private final class StoryVoicePlayer: ObservableObject {
    @Published var progress: Double = 0
    @Published var ready = false
    private var player: AVPlayer?
    private var observer: Any?

    func load(_ url: URL?) {
        guard player == nil, let url else { return }
        try? AVAudioSession.sharedInstance().setCategory(.playback)
        try? AVAudioSession.sharedInstance().setActive(true)
        let p = AVPlayer(url: url)
        player = p
        observer = p.addPeriodicTimeObserver(forInterval: CMTime(value: 80, timescale: 1000), queue: .main) { [weak self, weak p] t in
            guard let item = p?.currentItem else { return }
            let total = CMTimeGetSeconds(item.duration)
            let progress = total.isFinite && total > 0 ? CMTimeGetSeconds(t) / total : 0
            MainActor.assumeIsolated { self?.progress = progress }
        }
        Task {
            _ = try? await p.currentItem?.asset.load(.duration)
            ready = true
        }
    }

    func set(playing: Bool) { if playing { player?.play() } else { player?.pause() } }

    func stop() {
        player?.pause()
        if let observer { player?.removeTimeObserver(observer) }
        observer = nil
        player = nil
        ready = false
    }
}

/// White bars (0–100), the played part solid.
struct VoiceBars: View {
    let levels: [Int]
    var progress: Double = 1

    var body: some View {
        Canvas { gc, size in
            let bars: [Int] = levels.isEmpty ? Array(repeating: 6, count: 24) : Array(levels.suffix(48))
            let gap: CGFloat = 3
            let n = CGFloat(bars.count)
            let w: CGFloat = max((size.width - gap * (n - 1)) / n, 2)
            for (i, v) in bars.enumerated() {
                let level: CGFloat = 0.12 + 0.88 * CGFloat(v) / 100
                let h: CGFloat = max(size.height * level, 4)
                let filled: Bool = (Double(i) + 0.5) / Double(bars.count) <= progress
                let x: CGFloat = CGFloat(i) * (w + gap)
                let rect = CGRect(x: x, y: (size.height - h) / 2, width: w, height: h)
                gc.fill(Path(roundedRect: rect, cornerRadius: w / 2), with: .color(.white.opacity(filled ? 1 : 0.45)))
            }
        }
        .environment(\.layoutDirection, .leftToRight)
    }
}

// MARK: - reply

private struct StoryReplyBar: View {
    @ObservedObject private var ch = Ch.shared
    let onFocus: (Bool) -> Void
    let onReact: (String) -> Void
    let onSend: (String) -> Void
    @State private var text = ""
    @FocusState private var focused: Bool
    @State private var shown = false
    @State private var bounced: String?

    var body: some View {
        VStack(spacing: 10) {
            // Quick reactions: each one pops in, a tap sends a burst up the screen.
            HStack {
                ForEach(Array(quickStoryReactions.enumerated()), id: \.offset) { i, emoji in
                    Button {
                        bounced = emoji
                        onReact(emoji)
                        Task {
                            try? await Task.sleep(nanoseconds: 180_000_000)
                            if bounced == emoji { bounced = nil }
                        }
                    } label: {
                        Text(verbatim: emoji).font(.system(size: 28)).padding(6)
                    }
                    .buttonStyle(.plain)
                    .scaleEffect((shown ? 1 : 0) * (bounced == emoji ? 1.45 : 1))
                    .animation(.spring(response: 0.35, dampingFraction: 0.4).delay(shown && bounced == nil ? 0.12 + Double(i) * 0.05 : 0), value: shown)
                    .animation(.spring(response: 0.25, dampingFraction: 0.3), value: bounced)
                    .frame(maxWidth: .infinity)
                }
            }
            HStack(spacing: 8) {
                TextField("", text: $text, prompt: Text(L("st_reply_hint")).foregroundColor(.white.opacity(0.7)), axis: .vertical)
                    .lineLimit(1...4).font(.dorr(15)).foregroundStyle(.white).tint(.white)
                    .focused($focused)
                    .padding(.horizontal, 18).padding(.vertical, 13)
                    .frame(minHeight: 48)
                    .background(Color.white.opacity(0.16), in: RoundedRectangle(cornerRadius: 24, style: .continuous))
                if !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                    Button {
                        onSend(text.trimmingCharacters(in: .whitespacesAndNewlines))
                        text = ""
                        focused = false
                    } label: {
                        Image(systemName: "paperplane.fill").font(.system(size: 20)).foregroundStyle(.white)
                            .frame(width: 48, height: 48).background(ch.headerBrush, in: Circle())
                    }
                    .buttonStyle(.plain)
                    .transition(.scale.combined(with: .opacity))
                }
            }
            .animation(.spring(response: 0.35, dampingFraction: 0.45), value: text.isEmpty)
        }
        .padding(12)
        .background(LinearGradient(colors: [.clear, .black.opacity(0.6)], startPoint: .top, endPoint: .bottom).ignoresSafeArea(edges: .bottom))
        // The story waits while I type; sending or closing the keyboard lets it go on.
        .onChange(of: focused) { onFocus($0) }
        .onAppear { shown = true }
    }
}

// MARK: - reaction bursts

private struct Burst: Identifiable {
    let id = UUID()
    let emoji: String
    let drift = Double.random(in: -1...1)
    let delay = Double.random(in: 0...0.26)
    let size = CGFloat(26 + Int.random(in: 0..<18))
}

/// One emoji floating up from the reply bar, drifting sideways, spinning a little, fading out.
private struct BurstEmoji: View {
    let burst: Burst
    let onDone: () -> Void
    @State private var start: Date?

    var body: some View {
        TimelineView(.animation) { ctx in
            let p = start.map { min(max(ctx.date.timeIntervalSince($0) / 1.5, 0), 1) } ?? 0
            let eased = 1 - pow(1 - p, 2.2)
            Text(verbatim: burst.emoji).font(.system(size: burst.size))
                .scaleEffect(eased < 0.15 ? eased / 0.15 : 1)
                .rotationEffect(.degrees(burst.drift * 30 * eased))
                .offset(x: burst.drift * eased * 120 + sin(eased * 8) * 6, y: -eased * 520)
                .opacity(start == nil ? 0 : eased < 0.7 ? 1 : (1 - eased) / 0.3)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottom)
        .padding(.bottom, 120)
        .allowsHitTesting(false)
        .task {
            try? await Task.sleep(nanoseconds: UInt64(burst.delay * 1_000_000_000))
            start = Date()
            try? await Task.sleep(nanoseconds: 1_500_000_000)
            onDone()
        }
    }
}

// MARK: - viewers

private struct ViewersSheet: View {
    @ObservedObject private var ch = Ch.shared
    let story: StoryDto
    let onDismiss: () -> Void
    @State private var viewers: [StoryViewerDto]?

    var body: some View {
        ChSheetCard {
            Text(L("st_viewers", viewers?.count ?? story.views ?? 0)).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 12)
            if let list = viewers {
                if list.isEmpty {
                    Text(L("st_no_views")).font(.dorr(14)).foregroundStyle(ch.soft).padding(.vertical, 24)
                } else {
                    ScrollView {
                        LazyVStack(spacing: 0) {
                            ForEach(Array(list.enumerated()), id: \.offset) { i, v in
                                HStack(spacing: 12) {
                                    ChAvatar(url: v.viewer?.avatar, name: v.viewer?.name, key: v.viewer?.key, size: 44)
                                    VStack(alignment: .leading, spacing: 0) {
                                        Text(verbatim: v.viewer?.name ?? "").font(.dorr(15, .bold)).foregroundStyle(ch.ink)
                                        Text(verbatim: aiTimeAgo(v.viewedAt)).font(.dorr(12)).foregroundStyle(ch.mut)
                                    }
                                    Spacer()
                                    if let r = v.reaction { Text(verbatim: r).font(.system(size: 24)) }
                                }
                                .padding(.vertical, 8)
                                .chStagger(i)
                            }
                        }
                    }
                    .frame(maxHeight: 420)
                }
            } else {
                ChSkeletonRows()
            }
        }
        .task { viewers = (try? await ChatApi.storyViewers(story.id).data) ?? [] }
    }
}

// MARK: - mentions, Dorr's stories

/// «Mentioned you» on a status — and adding it to mine (it won't say whose it was).
struct MentionedMeBar: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let story: StoryDto
    @State private var done = false

    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: "at").font(.system(size: 14, weight: .bold))
            Text(L("bk_mentioned_you")).font(.dorr(13, .bold))
            if !done {
                Button {
                    Task {
                        do {
                            _ = try await ChatApi.reshareStory(story.id)
                            done = true
                            host.showToast(L("bk_reshared"))
                            await host.refreshStories()
                        } catch {
                            if let msg = error.apiFailure.message { host.showToast(msg, error: true) }
                        }
                    }
                } label: {
                    Text(L("bk_reshare")).font(.dorr(12.5, .heavy)).foregroundStyle(ch.red)
                        .padding(.horizontal, 10).padding(.vertical, 5).background(Color.white, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                .buttonStyle(.plain)
                .padding(.leading, 4)
            }
        }
        .foregroundStyle(.white)
        .padding(.horizontal, 12).padding(.vertical, 8)
        .background(Color.black.opacity(0.45), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .padding(.horizontal, 14).padding(.vertical, 6)
    }
}

/// Dorr's logo in a circle — the avatar of Dorr's own stories.
struct DorrAvatar: View {
    let size: CGFloat

    var body: some View {
        DorrLogo(width: size * 0.72, onDark: false)
            .frame(width: size, height: size)
            .background(Color.white, in: Circle())
            .clipShape(Circle())
    }
}

/// The «Open» button on one of Dorr's stories.
struct DorrLinkButton: View {
    @ObservedObject private var ch = Ch.shared
    @Environment(\.openURL) private var openURL
    let label: String?
    let url: String

    var body: some View {
        Button {
            if let u = URL(string: url) { openURL(u) }
        } label: {
            HStack(spacing: 8) {
                Text(verbatim: label.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? L("st_open_link")).font(.dorr(15, .heavy))
                Image(systemName: "arrow.up.right.square").font(.system(size: 17, weight: .semibold))
            }
            .foregroundStyle(ch.red)
            .padding(.horizontal, 22).padding(.vertical, 12)
            .background(Color.white, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
        }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity)
        .padding(.top, 8).padding(.bottom, 22)
    }
}

// MARK: - screenshots off

extension View {
    /// Android's FLAG_SECURE: while on, screenshots and screen recordings show nothing of this view. iOS has no switch
    /// for it — a secure text field's canvas is the one layer the system leaves out of captures, so the content goes there.
    @ViewBuilder
    func screenshotShield(_ on: Bool) -> some View {
        if on { ShieldReader(content: AnyView(self)) } else { self }
    }
}

/// The content moves into a UIKit host: it takes the direction, language and colours with it.
private struct ShieldReader: View {
    @Environment(\.layoutDirection) private var direction
    @Environment(\.locale) private var locale
    @Environment(\.colorScheme) private var scheme
    let content: AnyView

    var body: some View {
        ScreenshotShield { content.environment(\.layoutDirection, direction).environment(\.locale, locale).environment(\.colorScheme, scheme) }
            .ignoresSafeArea()
    }
}

private struct ScreenshotShield<Content: View>: UIViewRepresentable {
    @ViewBuilder let content: () -> Content

    /// Never takes the keyboard: it's only there for its protected canvas.
    final class ShieldField: UITextField {
        override var canBecomeFirstResponder: Bool { false }
    }

    final class Holder: UIView {
        let field = ShieldField()
        var host: UIHostingController<Content>?
    }

    func makeUIView(context: Context) -> Holder {
        let holder = Holder()
        holder.field.isSecureTextEntry = true
        holder.field.isUserInteractionEnabled = false
        holder.addSubview(holder.field)
        holder.field.frame = holder.bounds
        holder.field.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        let host = UIHostingController(rootView: content())
        host.view.backgroundColor = .clear
        holder.host = host
        // The field's own canvas is the protected layer; without it (a future iOS), show the content plainly.
        let canvas = holder.field.subviews.first { type(of: $0).description().contains("CanvasView") } ?? holder
        canvas.isUserInteractionEnabled = true
        holder.field.isUserInteractionEnabled = canvas !== holder
        canvas.addSubview(host.view)
        host.view.frame = holder.bounds
        host.view.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        return holder
    }

    func updateUIView(_ holder: Holder, context: Context) {
        holder.host?.rootView = content()
    }
}
