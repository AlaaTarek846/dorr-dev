import AVFoundation
import AVKit
import SwiftUI

/// The chat's quick reactions, in the same order as Android's `WeQuickReactions`.
let weQuickReactions = ["❤️", "😂", "😮", "😢", "👏", "🔥"]

/// Auto mode: how long the end of an ad stays before the next one (device clock).
private let autoNextNs: UInt64 = 4_500_000_000

/// Errors that mean "this ad is not for me now": go on to the next one.
private let skipCodes: Set<String> = ["watch_earn_not_eligible", "watch_earn_already_watched", "watch_earn_ad_unavailable"]

private enum Phase { case starting, playing, completing, ended }

/// androidApp `ui/screens/watchearn/WatchEarnPlayer.kt` — the player, like statuses (D5/D6): one progress bar per ad,
/// the advertiser, ✕ and ⋮ (report); the title, text and link at the bottom. Each ad starts on the server when it shows;
/// an image counts its seconds on the device clock, a video plays to its end (muted until 🔈). Holding pauses, and so
/// does opening the comments. At the end the view is completed, the reward toast pops, and only then the interaction
/// bar slides up with the window's countdown. When the feed is done, a summary shows what this session earned.
@MainActor
struct WatchEarnPlayer: View {
    @EnvironmentObject private var host: WeHost
    let feed: WeFeedDto
    @State private var index = 0
    @State private var earnedMinor = 0
    @State private var watched = 0

    var body: some View {
        let ads = feed.ads
        if index >= ads.count {
            PlayerSummary(earnedMinor: earnedMinor, watched: watched, currency: ads.first?.currency,
                          onRewards: { host.replace(.rewards) }, onClose: { host.pop() })
                .transition(.opacity)
        } else {
            let current = index
            AdPage(
                ad: ads[current],
                feed: feed,
                index: current,
                onEarned: { earnedMinor += $0 },
                onWatched: { watched += 1 },
                // Only once per ad, whichever asks first (the end, a tap, a swipe, an error).
                onNext: { if index == current { withAnimation(.easeOut(duration: 0.25)) { index += 1 } } },
                onClose: { host.pop() }
            )
            .id(ads[current].id)
            .transition(.opacity)
        }
    }
}

@MainActor
private struct AdPage: View {
    @EnvironmentObject private var host: WeHost
    @Environment(\.openURL) private var openURL
    @Environment(\.wa) private var wa
    let ad: WeFeedAdDto
    let feed: WeFeedDto
    let index: Int
    let onEarned: (Int) -> Void
    let onWatched: () -> Void
    let onNext: () -> Void
    let onClose: () -> Void

    @State private var phase: Phase = .starting
    @State private var start: WeStartDto?
    @State private var startedAt = Date()
    @State private var offline = false
    @State private var retry = 0
    /// The bar: what has run so far, plus the running stretch since [segmentStart] (device clock).
    @State private var elapsedMs: Double = 0
    @State private var segmentStart: Date?
    @State private var holding = false
    @State private var pressStart: Date?
    @State private var commentsOpen = false
    @State private var reportOpen = false
    @State private var menu = false
    @State private var muted = true
    @State private var videoMs: Int?
    @State private var videoEnded = false
    @State private var complete: WeCompleteDto?
    @State private var interacted = false
    @State private var myReaction: String?
    @State private var toastFx: RewardFx?
    @State private var now = Date()

    private var paused: Bool { holding || commentsOpen || reportOpen || menu }
    private var isVideo: Bool { ad.media.type == "video" }
    private var totalMs: Int? {
        isVideo ? videoMs : max(start?.seconds ?? ad.media.seconds ?? feed.player.imageSeconds, 1) * 1000
    }
    private var deadline: Date? { weInstant(complete?.interactionDeadlineAt) }
    private var windowOpen: Bool {
        guard complete?.canInteract == true, let deadline else { return false }
        return now < deadline && !interacted
    }

    var body: some View {
        let ended = phase == .ended
        let manualClosed = ended && feed.player.mode == "manual"
        ZStack {
            Color.black.ignoresSafeArea()

            // ------------------------------------------------------------ the ad itself
            Group {
                if isVideo {
                    WeVideo(
                        url: ApiClient.mediaUrl(ad.media.url),
                        playing: phase == .playing && !paused,
                        muted: muted,
                        onReady: { videoMs = $0 },
                        onEnded: { videoEnded = true },
                        onError: { if phase == .playing || phase == .starting { onNext() } }
                    )
                } else {
                    WeImage(url: ad.media.url, zooming: phase == .playing && !paused, seconds: Double(totalMs ?? 8000) / 1000)
                }
            }
            .ignoresSafeArea()

            // Manual mode: the ad view closes at the end — a dim card says a tap goes on.
            if manualClosed {
                ZStack {
                    Color.black.opacity(0.78).ignoresSafeArea()
                    VStack(spacing: 0) {
                        Image(systemName: "forward.end.fill").font(.system(size: 38, weight: .semibold)).foregroundStyle(.white)
                        Text(L("we_ad_over")).font(.dorr(17, .heavy)).foregroundStyle(.white).padding(.top, 8)
                        Text(L("we_tap_for_next")).font(.dorr(13)).foregroundStyle(.white.opacity(0.75))
                    }
                }
                .transition(.opacity)
            }

            // ------------------------------------------------------------ hold to pause; a tap or a swipe after the end goes on
            Color.clear
                .contentShape(Rectangle())
                .ignoresSafeArea()
                .gesture(
                    DragGesture(minimumDistance: 0)
                        .onChanged { _ in
                            if pressStart == nil {
                                pressStart = Date()
                                holding = true
                            }
                        }
                        .onEnded { value in
                            let quick = Date().timeIntervalSince(pressStart ?? Date()) < 0.22
                            pressStart = nil
                            holding = false
                            let dx = abs(value.translation.width)
                            if phase == .ended && (dx > 120 || (quick && dx < 12)) { onNext() }
                        }
                )

            if phase == .starting && !offline {
                ProgressView().tint(.white).scaleEffect(1.3)
            }
            if offline {
                VStack(spacing: 0) {
                    Image(systemName: "wifi.slash").font(.system(size: 36, weight: .semibold)).foregroundStyle(.white)
                    Text(L("we_error_network")).font(.dorr(14)).foregroundStyle(.white).multilineTextAlignment(.center).padding(.top, 10)
                    WaButton(text: L("we_retry"), compact: true) { retry += 1 }.frame(width: 160).padding(.top, 14)
                }
                .padding(32)
            }

            VStack(spacing: 0) {
                // ------------------------------------------------------------ top: one bar per ad, the advertiser, ⋮ and ✕
                if !holding { topBar.transition(.opacity) }
                Spacer(minLength: 0)
                // ------------------------------------------------------------ bottom: title, text, link — then the interaction bar
                if !holding { bottomInfo(ended: ended).transition(.opacity) }
                // Only once the ad is over: the chat's quick reactions and «write a comment», sliding up.
                if ended {
                    InteractionBar(
                        windowOpen: windowOpen,
                        leftMs: Int(((deadline ?? now).timeIntervalSince(now)) * 1000),
                        interactionMinor: ad.earn.interactionMinor,
                        currency: ad.currency,
                        myReaction: myReaction,
                        comments: ad.counts.comments,
                        onReact: { react($0) },
                        onComment: { commentsOpen = true }
                    )
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                }
            }
            .animation(.easeOut(duration: 0.15), value: holding)
            .animation(.spring(response: 0.45, dampingFraction: 0.75), value: ended)
            .animation(.easeOut(duration: 0.35), value: manualClosed)

            // ------------------------------------------------------------ the reward toast
            if let fx = toastFx {
                RewardToast(fx: fx) { if toastFx?.id == fx.id { toastFx = nil } }.id(fx.id)
            }

            // ------------------------------------------------------------ ⋮ → report the ad
            if menu {
                Color.black.opacity(0.001).ignoresSafeArea().onTapGesture { menu = false }
                VStack {
                    HStack {
                        Spacer(minLength: 0)
                        Button {
                            menu = false
                            reportOpen = true
                        } label: {
                            HStack(spacing: 10) {
                                Image(systemName: "flag.fill").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.danger)
                                Text(L("we_report_ad")).font(.dorr(14, .bold)).foregroundStyle(wa.ink)
                            }
                            .padding(.horizontal, 16)
                            .padding(.vertical, 13)
                            .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                            .shadow(color: .black.opacity(0.25), radius: 12, y: 4)
                        }
                        .buttonStyle(.plain)
                    }
                    Spacer(minLength: 0)
                }
                .padding(.top, 74)
                .padding(.horizontal, 12)
                .transition(.opacity.combined(with: .scale(scale: 0.9, anchor: .topTrailing)))
            }
        }
        .animation(.easeOut(duration: 0.18), value: menu)
        .weSheet(commentsOpen, onDismiss: { commentsOpen = false }) {
            WatchEarnCommentsSheet(adId: ad.id, onInteracted: { claimInteraction() }, onReportAd: {
                commentsOpen = false
                reportOpen = true
            })
        }
        .weSheet(reportOpen, onDismiss: { reportOpen = false }) {
            WeReportAdSheet(adId: ad.id) { message in
                reportOpen = false
                host.showToast(message.trimmingCharacters(in: .whitespaces).isEmpty ? L("we_reported") : message)
                onNext()
            }
        }
        // 1. The ad starts on the server as it shows.
        .task(id: retry) { await startAd() }
        // 2. The bar: an image's seconds, or the video's own length — stopped while held or while a sheet is open.
        .task(id: "\(phase)|\(paused)|\(totalMs ?? -1)") { await run() }
        // A video ends when it really ends (it may have buffered).
        .onChange(of: videoEnded) { done in
            if done && phase == .playing {
                elapsedMs = Double(totalMs ?? 1)
                segmentStart = nil
                phase = .completing
            }
        }
        // 3. Completed: the server checks the time, charges and rewards.
        .task(id: phase) { if phase == .completing { await completeAd() } }
        // The interaction window's countdown, on the device clock only.
        .task(id: "\(complete?.interactionDeadlineAt ?? "")|\(interacted)") { await tickWindow() }
        // Auto mode: on to the next ad a few seconds after the end, unless held or the comments are open.
        .task(id: "\(phase)|\(paused)|auto") {
            guard phase == .ended, !paused, feed.player.mode == "auto" else { return }
            try? await Task.sleep(nanoseconds: autoNextNs)
            guard !Task.isCancelled else { return }
            onNext()
        }
    }

    // MARK: - the parts

    private var topBar: some View {
        VStack(alignment: .leading, spacing: 10) {
            TimelineView(.animation(minimumInterval: nil, paused: segmentStart == nil)) { context in
                let p = progress(at: context.date)
                HStack(spacing: 4) {
                    ForEach(0..<feed.ads.count, id: \.self) { i in
                        let fill: CGFloat = i < index ? 1 : (i == index ? p : 0)
                        GeometryReader { g in
                            ZStack(alignment: .leading) {
                                Color.white.opacity(0.3)
                                Color.white.frame(width: g.size.width * fill)
                            }
                        }
                        .frame(height: 3)
                        .clipShape(RoundedRectangle(cornerRadius: 2))
                    }
                }
            }
            HStack(spacing: 0) {
                AdvertiserAvatar(avatar: ad.advertiser?.avatar, name: ad.advertiser?.name)
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: ad.advertiser?.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(.white).lineLimit(1)
                    HStack(spacing: 8) {
                        Text(L("we_sponsored")).font(.dorr(11.5)).foregroundStyle(.white.opacity(0.75))
                        if ad.earn.viewMinor > 0 {
                            HStack(spacing: 3) {
                                Image(systemName: "trophy.fill").font(.system(size: 9, weight: .bold))
                                Text(verbatim: "+ " + weMoney(ad.earn.viewMinor, ad.currency)).font(.dorr(10.5, .heavy)).weLtr()
                            }
                            .foregroundStyle(.white)
                            .padding(.horizontal, 8)
                            .padding(.vertical, 2)
                            .background(We.rewardBrush, in: Capsule())
                        }
                    }
                }
                .padding(.leading, 10)
                Spacer(minLength: 0)
                if isVideo {
                    Button {
                        muted.toggle()
                        if !muted { try? AVAudioSession.sharedInstance().setCategory(.playback) }
                    } label: {
                        Image(systemName: muted ? "speaker.slash.fill" : "speaker.wave.2.fill").font(.system(size: 18, weight: .semibold))
                            .foregroundStyle(.white).frame(width: 40, height: 40).contentShape(Circle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L(muted ? "we_unmute" : "we_mute"))
                }
                Button { menu = true } label: {
                    Image(systemName: "ellipsis").rotationEffect(.degrees(90)).font(.system(size: 18, weight: .bold))
                        .foregroundStyle(.white).frame(width: 40, height: 40).contentShape(Circle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("we_more"))
                Button(action: onClose) {
                    Image(systemName: "xmark").font(.system(size: 18, weight: .bold))
                        .foregroundStyle(.white).frame(width: 40, height: 40).contentShape(Circle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("we_close"))
            }
        }
        .padding(.horizontal, 10)
        .padding(.top, 10)
        .padding(.bottom, 26)
        .background(LinearGradient(colors: [.black.opacity(0.6), .clear], startPoint: .top, endPoint: .bottom).ignoresSafeArea(edges: .top))
    }

    private func bottomInfo(ended: Bool) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: ad.title).font(.dorr(18, .heavy)).foregroundStyle(.white).lineLimit(2)
            if let body = ad.body, !body.trimmingCharacters(in: .whitespaces).isEmpty {
                Text(verbatim: body).font(.dorr(13.5)).foregroundStyle(.white.opacity(0.9)).lineSpacing(4).lineLimit(3).padding(.top, 4)
            }
            if let link = ad.linkUrl, !link.trimmingCharacters(in: .whitespaces).isEmpty {
                Button {
                    menu = false
                    if let url = URL(string: link) { openURL(url) }
                } label: {
                    HStack(spacing: 6) {
                        Text(L("we_open_link")).font(.dorr(13, .heavy))
                        Image(systemName: "arrow.up.right.square").font(.system(size: 13, weight: .bold))
                    }
                    .foregroundStyle(.black)
                    .padding(.horizontal, 14)
                    .padding(.vertical, 7)
                    .background(Color.white, in: Capsule())
                }
                .buttonStyle(WaPressStyle(scale: 0.95))
                .padding(.top, 10)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 16)
        .padding(.top, 30)
        .padding(.bottom, ended ? 6 : 18)
        .background(LinearGradient(colors: [.clear, .black.opacity(0.7)], startPoint: .top, endPoint: .bottom).ignoresSafeArea(edges: ended ? [] : .bottom))
    }

    // MARK: - the clock

    private func progress(at date: Date) -> CGFloat {
        guard let total = totalMs, total > 0 else { return 0 }
        let running = segmentStart.map { date.timeIntervalSince($0) * 1000 } ?? 0
        return CGFloat(min(max((elapsedMs + running) / Double(total), 0), 1))
    }

    /// Runs the bar for what is left; a pause (hold, a sheet) cancels it and keeps what has run.
    private func run() async {
        guard phase == .playing, !paused, let total = totalMs else { return }
        let remaining = max(Double(total) - elapsedMs, 1)
        let begin = Date()
        segmentStart = begin
        try? await Task.sleep(nanoseconds: UInt64(remaining * 1_000_000))
        elapsedMs = min(elapsedMs + Date().timeIntervalSince(begin) * 1000, Double(total))
        if segmentStart == begin { segmentStart = nil }
        guard !Task.isCancelled else { return }
        elapsedMs = Double(total)
        // An image ends when its time is up; a video when it really ends.
        if !isVideo { phase = .completing }
    }

    private func tickWindow() async {
        guard let until = deadline else { return }
        while !interacted && Date() < until {
            now = Date()
            try? await Task.sleep(nanoseconds: 1_000_000_000)
            if Task.isCancelled { return }
        }
        now = Date()
    }

    // MARK: - the server

    private func startAd() async {
        offline = false
        do {
            guard let s = try await WatchEarnApi.start(ad.id).data else { throw URLError(.badServerResponse) }
            start = s
            startedAt = Date()
            phase = .playing
        } catch {
            if Task.isCancelled { return }
            let failure = error.apiFailure
            if failure.httpStatus == nil {
                offline = true
            } else if let code = failure.errorCode, skipCodes.contains(code) {
                onNext()
            } else {
                host.showToast(failure.message ?? L("we_error_generic"), error: true)
                onNext()
            }
        }
    }

    /// Too fast → wait out the rest, then ask again.
    private func completeAd() async {
        guard let s = start else { return }
        var attempts = 0
        while true {
            do {
                let done = try await WatchEarnApi.complete(s.viewId).data
                complete = done
                now = Date()
                onWatched()
                if ad.earn.viewMinor > 0 {
                    onEarned(ad.earn.viewMinor)
                    toastFx = RewardFx(amount: "+ " + weMoney(ad.earn.viewMinor, ad.currency), caption: nil)
                }
                phase = .ended
                return
            } catch {
                if Task.isCancelled { return }
                let failure = error.apiFailure
                if failure.errorCode == "watch_earn_too_fast" && attempts < 2 {
                    attempts += 1
                    let left = startedAt.addingTimeInterval(Double(s.seconds ?? 0)).timeIntervalSinceNow * 1000
                    let wait = min(max(left, 400), 15_000) + 400
                    try? await Task.sleep(nanoseconds: UInt64(wait * 1_000_000))
                    if Task.isCancelled { return }
                } else if let code = failure.errorCode, skipCodes.contains(code) {
                    onNext()
                    return
                } else {
                    host.showToast(failure.message ?? L("we_error_generic"), error: true)
                    phase = .ended
                    return
                }
            }
        }
    }

    /// The first comment or reaction inside the window earns the interaction reward.
    private func claimInteraction() {
        guard let done = complete, let until = weInstant(done.interactionDeadlineAt) else { return }
        guard done.canInteract, !interacted, Date() < until else { return }
        interacted = true
        if ad.earn.interactionMinor > 0 {
            onEarned(ad.earn.interactionMinor)
            toastFx = RewardFx(amount: "+ " + weMoney(ad.earn.interactionMinor, ad.currency), caption: L("we_interaction_reward"))
        }
    }

    private func react(_ emoji: String) {
        Task {
            do {
                let before = myReaction
                let result: WeReactionsDto?
                if before == emoji {
                    result = try await WatchEarnApi.unreact(ad.id).data
                } else {
                    result = try await WatchEarnApi.react(ad.id, WeReactionRequest(emoji: emoji)).data
                }
                myReaction = result?.myReaction
                if before == nil && myReaction != nil { claimInteraction() }
            } catch {
                host.showToast(error.weMessage(L("we_error_generic")), error: true)
            }
        }
    }
}

// MARK: - media

/// The ad's picture: a blurred copy fills the edges of a photo that isn't phone-shaped, and a very slow zoom runs while
/// it counts (Ken Burns), like statuses.
private struct WeImage: View {
    let url: String?
    let zooming: Bool
    let seconds: Double
    @State private var image: UIImage?
    @State private var zoom: CGFloat = 1

    var body: some View {
        GeometryReader { g in
            ZStack {
                if let image {
                    Image(uiImage: image).resizable().scaledToFill()
                        .frame(width: g.size.width, height: g.size.height)
                        .blur(radius: 40)
                        .opacity(0.5)
                        .clipped()
                    Image(uiImage: image).resizable().scaledToFit()
                        .frame(width: g.size.width, height: g.size.height)
                        .scaleEffect(zoom)
                }
            }
            .frame(width: g.size.width, height: g.size.height)
        }
        .task(id: url) {
            guard let u = ApiClient.mediaUrl(url), let (data, _) = try? await URLSession.shared.data(from: u) else { return }
            image = UIImage(data: data)
        }
        .onAppear { applyZoom(zooming) }
        .onChange(of: zooming) { applyZoom($0) }
    }

    private func applyZoom(_ on: Bool) {
        withAnimation(.linear(duration: on ? max(seconds, 0.3) : 0.3)) { zoom = on ? 1.07 : 1 }
    }
}

/// The ad's video: plays to the end, never loops. Sound is on the track but starts muted (the 🔈 button turns it on).
/// Holding, a sheet or the end stop it.
private struct WeVideo: UIViewRepresentable {
    let url: URL?
    let playing: Bool
    let muted: Bool
    let onReady: (Int) -> Void
    let onEnded: () -> Void
    let onError: () -> Void

    final class PlayerView: UIView {
        override static var layerClass: AnyClass { AVPlayerLayer.self }
        var playerLayer: AVPlayerLayer { layer as! AVPlayerLayer }
        var ready = false
        var finished = false
        var endObserver: NSObjectProtocol?
        var statusObserver: NSKeyValueObservation?
    }

    /// Holds the newest closures and flags (Compose's `rememberUpdatedState`).
    final class Coordinator {
        var parent: WeVideo
        init(_ parent: WeVideo) { self.parent = parent }
    }

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    func makeUIView(context: Context) -> PlayerView {
        let v = PlayerView()
        v.backgroundColor = .black
        v.playerLayer.videoGravity = .resizeAspect
        let coordinator = context.coordinator
        guard let url else {
            DispatchQueue.main.async { coordinator.parent.onError() }
            return v
        }
        let item = AVPlayerItem(url: url)
        let player = AVPlayer(playerItem: item)
        player.actionAtItemEnd = .pause
        player.isMuted = muted
        v.playerLayer.player = player
        v.endObserver = NotificationCenter.default.addObserver(forName: .AVPlayerItemDidPlayToEndTime, object: item, queue: .main) { [weak v] _ in
            v?.finished = true
            coordinator.parent.onEnded()
        }
        v.statusObserver = item.observe(\.status, options: [.new]) { item, _ in
            if item.status == .failed { DispatchQueue.main.async { coordinator.parent.onError() } }
        }
        Task { @MainActor in
            let duration = (try? await item.asset.load(.duration)).map(CMTimeGetSeconds) ?? 0
            v.ready = true
            coordinator.parent.onReady(Int(max(duration.isFinite ? duration * 1000 : 15_000, 1000)))
            if coordinator.parent.playing { player.play() }
        }
        return v
    }

    func updateUIView(_ v: PlayerView, context: Context) {
        context.coordinator.parent = self
        guard let player = v.playerLayer.player else { return }
        player.isMuted = muted
        guard v.ready, !v.finished else { return }
        if playing, player.rate == 0 { player.play() } else if !playing, player.rate != 0 { player.pause() }
    }

    static func dismantleUIView(_ v: PlayerView, coordinator: Coordinator) {
        if let endObserver = v.endObserver { NotificationCenter.default.removeObserver(endObserver) }
        v.statusObserver?.invalidate()
        v.playerLayer.player?.pause()
        v.playerLayer.player = nil
    }
}

private struct AdvertiserAvatar: View {
    let avatar: String?
    let name: String?

    var body: some View {
        ZStack {
            Color.white.opacity(0.2)
            if ApiClient.mediaUrl(avatar) != nil {
                WeRemoteImage(url: avatar)
            } else if let initial = name?.trimmingCharacters(in: .whitespaces).first {
                Text(verbatim: String(initial)).font(.dorr(16, .heavy)).foregroundStyle(.white)
            } else {
                Image(systemName: "person.fill").font(.system(size: 18)).foregroundStyle(.white)
            }
        }
        .frame(width: 38, height: 38)
        .clipShape(Circle())
        .overlay(Circle().stroke(Color.white.opacity(0.6), lineWidth: 1.5))
    }
}

// MARK: - the interaction bar

private struct InteractionBar: View {
    let windowOpen: Bool
    let leftMs: Int
    let interactionMinor: Int
    let currency: WeCurrencyDto
    let myReaction: String?
    let comments: Int
    let onReact: (String) -> Void
    let onComment: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            if windowOpen && interactionMinor > 0 {
                HStack(spacing: 6) {
                    Image(systemName: "timer").font(.system(size: 12, weight: .bold))
                    Text(L("we_interaction_window", weMoney(interactionMinor, currency))).font(.dorr(11.5, .bold))
                    Text(verbatim: weCountdown(leftMs)).font(.dorr(12, .heavy)).monospacedDigit().weLtr()
                }
                .foregroundStyle(.white)
                .padding(.horizontal, 12)
                .padding(.vertical, 5)
                .background(We.interaction.opacity(0.85), in: Capsule())
                .padding(.bottom, 10)
            }
            HStack(spacing: 0) {
                ForEach(Array(weQuickReactions.enumerated()), id: \.offset) { i, emoji in
                    ReactionButton(emoji: emoji, index: i, mine: myReaction == emoji) { onReact(emoji) }
                        .frame(maxWidth: .infinity)
                }
            }
            .padding(.bottom, 10)
            Button(action: onComment) {
                HStack(spacing: 10) {
                    Image(systemName: "bubble.left").font(.system(size: 17, weight: .semibold))
                    Text(L("we_write_comment")).font(.dorr(14)).foregroundStyle(.white.opacity(0.85))
                    Spacer(minLength: 0)
                    if comments > 0 { Text(verbatim: "\(comments)").font(.dorr(12, .bold)).foregroundStyle(.white.opacity(0.7)) }
                }
                .foregroundStyle(.white)
                .padding(.horizontal, 16)
                .frame(height: 46)
                .background(Color.white.opacity(0.16), in: RoundedRectangle(cornerRadius: 23, style: .continuous))
                .contentShape(Rectangle())
            }
            .buttonStyle(WaPressStyle(scale: 0.98))
        }
        .padding(12)
        .background(Color.black.opacity(0.72).ignoresSafeArea(edges: .bottom))
    }
}

/// One quick reaction: pops in a little after the one before; bounces when tapped.
private struct ReactionButton: View {
    let emoji: String
    let index: Int
    let mine: Bool
    let action: () -> Void
    @State private var pop: CGFloat = 0
    @State private var bounce: CGFloat = 1

    var body: some View {
        Button {
            withAnimation(.spring(response: 0.18, dampingFraction: 0.3)) { bounce = 1.45 }
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.18) {
                withAnimation(.spring(response: 0.3, dampingFraction: 0.4)) { bounce = 1 }
            }
            action()
        } label: {
            Text(verbatim: emoji).font(.system(size: 27))
                .padding(6)
                .background(mine ? Color.white.opacity(0.25) : .clear, in: Circle())
        }
        .buttonStyle(.plain)
        .scaleEffect(pop * bounce)
        .task {
            try? await Task.sleep(nanoseconds: UInt64(140 + index * 55) * 1_000_000)
            withAnimation(.spring(response: 0.4, dampingFraction: 0.4)) { pop = 1 }
        }
    }
}

// MARK: - the reward toast

private struct RewardFx: Equatable {
    let amount: String
    let caption: String?
    let id = UUID()
}

/// «+ 1.50 SAR» pops up big, floats a little higher and fades — the interaction one carries its caption.
private struct RewardToast: View {
    let fx: RewardFx
    let onDone: () -> Void
    @State private var t: CGFloat = 0
    @State private var out: CGFloat = 0
    @State private var glow = 0.55

    var body: some View {
        VStack(spacing: 8) {
            HStack(spacing: 8) {
                Image(systemName: "trophy.fill").font(.system(size: 22, weight: .bold))
                Text(verbatim: fx.amount).font(.dorr(24, .black)).weLtr()
            }
            .foregroundStyle(.white)
            .padding(.horizontal, 20)
            .padding(.vertical, 10)
            .background(We.rewardBrush, in: Capsule())
            .overlay(Capsule().stroke(Color.white.opacity(glow), lineWidth: 2))
            if let caption = fx.caption {
                Text(verbatim: caption).font(.dorr(13, .heavy)).foregroundStyle(.white)
                    .padding(.horizontal, 12)
                    .padding(.vertical, 4)
                    .background(We.interaction, in: Capsule())
            }
        }
        .scaleEffect(0.4 + 0.6 * t)
        .opacity(Double(min(max(t, 0), 1) * (1 - out)))
        .offset(y: -out * 90)
        .allowsHitTesting(false)
        .task {
            withAnimation(.spring(response: 0.45, dampingFraction: 0.45)) { t = 1 }
            withAnimation(.easeInOut(duration: 0.6).repeatForever(autoreverses: true)) { glow = 1 }
            try? await Task.sleep(nanoseconds: 1_750_000_000)
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.45)) { out = 1 }
            try? await Task.sleep(nanoseconds: 450_000_000)
            onDone()
        }
    }
}

// MARK: - the end of the feed

private struct PlayerSummary: View {
    @Environment(\.wa) private var wa
    let earnedMinor: Int
    let watched: Int
    let currency: WeCurrencyDto?
    let onRewards: () -> Void
    let onClose: () -> Void
    @State private var dy: CGFloat = 0

    var body: some View {
        ZStack {
            LinearGradient(colors: [Color(hex: 0x111827), .black], startPoint: .top, endPoint: .bottom).ignoresSafeArea()
            VStack(spacing: 0) {
                Image(systemName: "trophy.fill").font(.system(size: 40, weight: .bold)).foregroundStyle(.white)
                    .frame(width: 86, height: 86)
                    .background(We.rewardBrush, in: Circle())
                    .offset(y: dy)
                Text(L("we_summary_title")).font(.dorr(20, .black)).foregroundStyle(wa.ink).padding(.top, 16)
                Text(L("we_summary_watched", watched)).font(.dorr(13)).foregroundStyle(wa.mut).padding(.top, 4)
                Text(L("we_summary_earned")).font(.dorr(12)).foregroundStyle(wa.mut).padding(.top, 14)
                WeCountUp(target: earnedMinor, format: { weMoney($0, currency) }, color: We.reward, size: 30, duration: 1.2)
                Text(L("we_summary_note")).font(.dorr(12)).foregroundStyle(wa.mut).multilineTextAlignment(.center).lineSpacing(5).padding(.top, 10)
                WaButton(text: L("we_my_rewards"), icon: "trophy.fill", action: onRewards).padding(.top, 18)
                WaButton(text: L("we_close"), style: .quiet, action: onClose).padding(.top, 8)
            }
            .padding(24)
            .frame(maxWidth: .infinity)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 28, style: .continuous))
            .padding(24)
        }
        .onAppear {
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 1.5).repeatForever(autoreverses: true)) { dy = -10 }
        }
    }
}
