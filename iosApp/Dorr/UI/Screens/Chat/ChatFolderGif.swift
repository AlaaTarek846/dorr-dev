import AVFoundation
import AVKit
import SwiftUI

// androidApp `chat/FolderZip.kt`, `ChatFolderGifUi.kt` (GifMakerDialog) and VideoTools' GIF clip — a folder sent in a
// chat (spec 10): the folder I pick is zipped on the phone, sub-folders kept, and goes as one document with its name and
// how many files were in it; and a video sent as a GIF (spec 11): drag the two ends to the part I want (at most 6 s),
// watch it loop, send — muted and small.

/// The largest folder that goes (zipped).
let folderMaxMB = 100

enum FolderZip {
    enum Result {
        case ok(ChatLocalFile, name: String, count: Int)
        case empty, tooBig, failed
    }

    private static let maxFiles = 5_000

    /// The picked folder (security-scoped from the files picker), zipped by the system (`.forUploading`).
    static func zip(_ folder: URL, maxBytes: Int64) async -> Result {
        await Task.detached(priority: .userInitiated) {
            let scoped = folder.startAccessingSecurityScopedResource()
            defer { if scoped { folder.stopAccessingSecurityScopedResource() } }
            // Every file under it: how many, how big.
            var count = 0
            var total: Int64 = 0
            if let walk = FileManager.default.enumerator(at: folder, includingPropertiesForKeys: [.isRegularFileKey, .fileSizeKey]) {
                for case let url as URL in walk {
                    guard let v = try? url.resourceValues(forKeys: [.isRegularFileKey, .fileSizeKey]), v.isRegularFile == true else { continue }
                    count += 1
                    total += Int64(v.fileSize ?? 0)
                    if count >= maxFiles { break }
                }
            }
            if count == 0 { return .empty }
            if total > maxBytes { return .tooBig }
            let name = folder.lastPathComponent.isEmpty ? "folder" : folder.lastPathComponent
            var made: URL?
            var coordError: NSError?
            NSFileCoordinator().coordinate(readingItemAt: folder, options: .forUploading, error: &coordError) { zipped in
                let out = FileManager.default.temporaryDirectory.appendingPathComponent("\(UUID().uuidString)-folder.zip")
                if (try? FileManager.default.copyItem(at: zipped, to: out)) != nil { made = out }
            }
            guard coordError == nil, let out = made else { return .failed }
            // Zipping can grow already-compressed photos a little: check what's really sent.
            let size = (try? out.resourceValues(forKeys: [.fileSizeKey]).fileSize).map(Int64.init) ?? 0
            if size > maxBytes {
                try? FileManager.default.removeItem(at: out)
                return .tooBig
            }
            return .ok(ChatLocalFile(url: out, mime: "application/zip", name: "\(name).zip"), name: name, count: count)
        }.value
    }
}

extension VideoTools {
    /// A GIF lasts at most this long.
    static let gifMaxMs: Int64 = 6_000

    /// The part from `startMs` to `endMs`, muted, scaled down to about 480 on the short side, as MP4.
    static func gifClip(_ input: URL, startMs: Int64, endMs: Int64) async -> ChatLocalFile? {
        let asset = AVURLAsset(url: input)
        let end = min(endMs, startMs + gifMaxMs)
        guard end > startMs, let video = try? await asset.loadTracks(withMediaType: .video).first else { return nil }
        let range = CMTimeRange(start: CMTime(value: startMs, timescale: 1000), end: CMTime(value: end, timescale: 1000))
        // Only the picture: the sound stays behind.
        let composition = AVMutableComposition()
        guard let track = composition.addMutableTrack(withMediaType: .video, preferredTrackID: kCMPersistentTrackID_Invalid),
              (try? track.insertTimeRange(range, of: video, at: .zero)) != nil else { return nil }
        if let transform = try? await video.load(.preferredTransform) { track.preferredTransform = transform }
        guard let export = AVAssetExportSession(asset: composition, presetName: AVAssetExportPreset960x540) else { return nil }
        let out = FileManager.default.temporaryDirectory.appendingPathComponent("\(UUID().uuidString)-gif.mp4")
        export.outputURL = out
        export.outputFileType = .mp4
        export.shouldOptimizeForNetworkUse = true
        await export.export()
        guard export.status == .completed else { return nil }
        return ChatLocalFile(url: out, mime: "video/mp4", name: "gif.mp4")
    }
}

/// A short muted video on a loop between `startMs` and `endMs`, filling its box.
private struct ClipLoop: UIViewRepresentable {
    let url: URL
    let startMs: Int64
    let endMs: Int64

    final class PlayerView: UIView {
        override static var layerClass: AnyClass { AVPlayerLayer.self }
        var playerLayer: AVPlayerLayer { layer as! AVPlayerLayer }
        var observer: Any?
        var window_: (Int64, Int64) = (0, 0)
    }

    func makeUIView(context: Context) -> PlayerView {
        let v = PlayerView()
        let player = AVPlayer(url: url)
        player.isMuted = true
        v.playerLayer.player = player
        v.playerLayer.videoGravity = .resizeAspect
        v.window_ = (startMs, endMs)
        // Back to the window's start whenever it passes the end.
        v.observer = player.addPeriodicTimeObserver(forInterval: CMTime(value: 40, timescale: 1000), queue: .main) { [weak v] t in
            guard let v else { return }
            if Int64(CMTimeGetSeconds(t) * 1000) >= v.window_.1 { player.seek(to: CMTime(value: v.window_.0, timescale: 1000), toleranceBefore: .zero, toleranceAfter: .zero) }
        }
        player.seek(to: CMTime(value: startMs, timescale: 1000))
        player.play()
        return v
    }

    func updateUIView(_ v: PlayerView, context: Context) {
        let moved = v.window_.0 != startMs
        v.window_ = (startMs, endMs)
        // The window moved: show its start now.
        if moved { v.playerLayer.player?.seek(to: CMTime(value: startMs, timescale: 1000), toleranceBefore: .zero, toleranceAfter: .zero) }
    }

    static func dismantleUIView(_ v: PlayerView, coordinator: ()) {
        if let o = v.observer { v.playerLayer.player?.removeTimeObserver(o) }
        v.playerLayer.player?.pause()
        v.playerLayer.player = nil
    }
}

/// Turn a picked video into a GIF: drag the two ends to the part I want (at most 6 s), watch it loop, send.
struct GifMakerView: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let source: ChatLocalFile
    let onDismiss: () -> Void
    let onSend: (_ clip: ChatLocalFile, _ lengthMs: Int64, _ poster: URL?) -> Void
    @State private var duration: Double = 0
    @State private var ratio: CGFloat = 1
    @State private var start: Double = 0
    @State private var end: Double = 0
    @State private var making = false

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 12) {
                Button(action: onDismiss) {
                    Image(systemName: "xmark").font(.system(size: 16, weight: .bold)).foregroundStyle(.white).frame(width: 42, height: 42).background(Color.white.opacity(0.12), in: Circle())
                }
                .buttonStyle(.plain)
                .disabled(making)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("fg_gif_title")).font(.dorr(17, .heavy)).foregroundStyle(.white)
                    Text(L("fg_gif_hint")).font(.dorr(12)).foregroundStyle(.white.opacity(0.6))
                }
                Spacer()
            }
            .padding(14)
            ZStack {
                if duration > 0 {
                    ZStack(alignment: .topLeading) {
                        ClipLoop(url: source.url, startMs: Int64(start), endMs: Int64(end))
                        GifTag().padding(10)
                        if making {
                            VStack(spacing: 10) {
                                ProgressView().tint(.white)
                                Text(L("fg_gif_making")).font(.dorr(13)).foregroundStyle(.white)
                            }
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                        }
                    }
                    .aspectRatio(ratio, contentMode: .fit)
                    .background(Color.black)
                    .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
                } else {
                    ProgressView().tint(.white)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .padding(20)
            VStack(alignment: .leading, spacing: 6) {
                HStack(spacing: 8) {
                    Image(systemName: "play.square.stack").font(.system(size: 18)).foregroundStyle(.white)
                    Text(L("fg_gif_length", String(format: "%.1f", locale: Locale(identifier: "en_US_POSIX"), (end - start) / 1000))).font(.dorr(14, .bold)).foregroundStyle(.white)
                }
                if duration > 0 { RangeBar(duration: duration, start: $start, end: $end, enabled: !making) }
            }
            .padding(.horizontal, 20)
            ChPrimaryButton(text: L("fg_gif_send"), icon: "play.square.stack", enabled: !making && duration > 0, action: make).padding(20)
        }
        .background(Color(hex: 0x0E1116).ignoresSafeArea())
        .environment(\.layoutDirection, .leftToRight)
        .task {
            let asset = AVURLAsset(url: source.url)
            let seconds = (try? await asset.load(.duration)).map(CMTimeGetSeconds) ?? 0
            if let track = try? await asset.loadTracks(withMediaType: .video).first, let loaded = try? await track.load(.naturalSize, .preferredTransform) {
                let r = CGRect(origin: .zero, size: loaded.0).applying(loaded.1)
                if r.height != 0 { ratio = min(max(abs(r.width) / abs(r.height), 0.5), 2) }
            }
            guard seconds.isFinite, seconds > 0 else {
                host.showToast(L("fg_gif_failed"), error: true)
                onDismiss()
                return
            }
            duration = seconds * 1000
            end = min(duration, Double(VideoTools.gifMaxMs))
        }
    }

    private func make() {
        making = true
        Task {
            let clip = await VideoTools.gifClip(source.url, startMs: Int64(start), endMs: Int64(end))
            let poster = await clip.asyncMap { await VideoTools.poster($0.url) } ?? nil
            making = false
            if let clip { onSend(clip, Int64(end - start), poster) } else { host.showToast(L("fg_gif_failed"), error: true) }
        }
    }
}

private extension Optional {
    func asyncMap<T>(_ transform: (Wrapped) async -> T) async -> T? {
        guard let value = self else { return nil }
        return await transform(value)
    }
}

/// Two handles on one track; the window stays between half a second and 6 s — dragging one end past that pulls the other.
private struct RangeBar: View {
    @ObservedObject private var ch = Ch.shared
    let duration: Double
    @Binding var start: Double
    @Binding var end: Double
    let enabled: Bool

    var body: some View {
        GeometryReader { g in
            let w = max(g.size.width - 24, 1)
            let x0 = CGFloat(start / duration) * w
            let x1 = CGFloat(end / duration) * w
            ZStack(alignment: .leading) {
                Capsule().fill(Color.white.opacity(0.25)).frame(height: 4).padding(.horizontal, 12)
                Capsule().fill(ch.red).frame(width: max(x1 - x0, 0), height: 4).offset(x: 12 + x0)
                handle.offset(x: x0).gesture(DragGesture(coordinateSpace: .named("gifBar")).onChanged { v in move(start: Double((v.location.x - 12) / w) * duration) })
                handle.offset(x: x1).gesture(DragGesture(coordinateSpace: .named("gifBar")).onChanged { v in move(end: Double((v.location.x - 12) / w) * duration) })
            }
            .coordinateSpace(name: "gifBar")
            .frame(height: 30)
            .disabled(!enabled)
        }
        .frame(height: 30)
    }

    private var handle: some View {
        Circle().fill(Color.white).frame(width: 24, height: 24).shadow(color: .black.opacity(0.3), radius: 3, y: 1)
    }

    private func move(start newStart: Double? = nil, end newEnd: Double? = nil) {
        let maxWindow = Double(VideoTools.gifMaxMs)
        let minWindow = min(500, duration)
        var a = min(max(newStart ?? start, 0), duration)
        var b = min(max(newEnd ?? end, 0), duration)
        if b - a > maxWindow { if newStart != nil { b = a + maxWindow } else { a = b - maxWindow } }
        guard b - a >= minWindow else { return }
        start = max(a, 0)
        end = min(b, duration)
    }
}
