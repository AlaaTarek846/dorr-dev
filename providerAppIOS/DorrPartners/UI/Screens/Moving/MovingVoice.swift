// providerApp `ui/screens/MovingVoice.kt`
import AVFoundation
import SwiftUI

private func voiceTime(_ ms: Int) -> String {
    let s = max(ms / 1000, 0)
    return String(format: "%d:%02d", locale: Locale(identifier: "en_US_POSIX"), s / 60, s % 60)
}

/// The page's player of the customer's voice note: one AVPlayer, its position read from the player's own clock.
@MainActor
final class MvVoicePlayback: ObservableObject {
    @Published private(set) var playing = false
    @Published private(set) var started = false
    @Published private(set) var progress: Double = 0
    @Published private(set) var positionMs = 0
    private var player: AVPlayer?
    private var observer: Any?
    private var endObserver: NSObjectProtocol?

    func toggle(_ url: URL) {
        if let player {
            if playing { player.pause() } else { player.play() }
            playing.toggle()
            return
        }
        try? AVAudioSession.sharedInstance().setCategory(.playback, mode: .spokenAudio)
        try? AVAudioSession.sharedInstance().setActive(true)
        // The tunnel's header, or it answers with its warning page instead of the audio.
        let item = AVPlayerItem(asset: AVURLAsset(url: url, options: ["AVURLAssetHTTPHeaderFieldsKey": ["ngrok-skip-browser-warning": "1"]]))
        let p = AVPlayer(playerItem: item)
        observer = p.addPeriodicTimeObserver(forInterval: CMTime(value: 1, timescale: 10), queue: .main) { [weak self] t in
            Task { @MainActor in
                guard let self, let d = p.currentItem?.duration.seconds, d.isFinite, d > 0 else { return }
                self.progress = t.seconds / d
                self.positionMs = Int(t.seconds * 1000)
            }
        }
        endObserver = NotificationCenter.default.addObserver(forName: .AVPlayerItemDidPlayToEndTime, object: item, queue: .main) { [weak self] _ in
            Task { @MainActor in self?.stop() }
        }
        player = p
        started = true
        playing = true
        p.play()
    }

    func stop() {
        player?.pause()
        if let observer { player?.removeTimeObserver(observer) }
        if let endObserver { NotificationCenter.default.removeObserver(endObserver) }
        observer = nil
        endObserver = nil
        player = nil
        playing = false
        started = false
        progress = 0
        positionMs = 0
    }
}

/// The customer's voice note about the load (owner, 2026-11-12), heard on the request's page: play / pause, its bars filling as it
/// plays and the time. The player stops when the page closes.
struct MvCustomerVoice: View {
    @Environment(\.wa) private var wa
    let voice: MovingVoiceDto
    @StateObject private var playback = MvVoicePlayback()

    var body: some View {
        let duration = voice.durationMs ?? 0
        let bars = (voice.waveform?.isEmpty == false ? voice.waveform! : (0..<40).map { 20 + ($0 * 37 % 60) })
        HStack(spacing: 10) {
            Button {
                if let url = Api.mediaUrl(voice.url) { playback.toggle(url) }
            } label: {
                Image(systemName: playback.playing ? "pause.fill" : "play.fill").font(.system(size: 18)).foregroundStyle(.white)
                    .frame(width: 44, height: 44).background(wa.red, in: Circle())
            }
            .buttonStyle(.plain)
            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 4) {
                    Image(systemName: "waveform").font(.system(size: 11, weight: .semibold)).foregroundStyle(wa.red)
                    Text(L("mv_voice_customer")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk)
                }
                HStack(alignment: .center, spacing: 2) {
                    ForEach(Array(bars.enumerated()), id: \.offset) { i, v in
                        let on = (Double(i) + 0.5) / Double(max(bars.count, 1)) <= playback.progress
                        Capsule().fill(on ? wa.red : wa.red.opacity(0.28))
                            .frame(height: max(24 * CGFloat(min(max(v, 6), 100)) / 100, 3))
                            .frame(maxWidth: .infinity)
                    }
                }
                .frame(height: 28)
                .animation(.linear(duration: 0.12), value: playback.progress)
                Text(verbatim: playback.started ? voiceTime(playback.positionMs) + " / " + voiceTime(duration) : voiceTime(duration))
                    .font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(10)
        .background(wa.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .onDisappear { playback.stop() }
    }
}
