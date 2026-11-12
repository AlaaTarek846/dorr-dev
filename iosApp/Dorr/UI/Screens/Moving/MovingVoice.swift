// androidApp `ui/screens/moving/MovingVoice.kt`
import AVFoundation
import SwiftUI

// The customer's voice note about the load (owner, 2026-11-12), on the page where the description is written: tell the pieces and
// what matters instead of typing. One note per request (a new one replaces it); the companies hear it with the request. Recorded with
// the chat's recorder (AAC .m4a, the live level for the bars), played with the chat's one-at-a-time player.

private let maxVoiceMs: Int64 = 120_000

func voiceTime(_ ms: Int64) -> String {
    let s = max(ms / 1000, 0)
    return String(format: "%d:%02d", locale: Locale(identifier: "en_US_POSIX"), s / 60, s % 60)
}

/// The voice card of the description page: record (pulsing rings and live bars), then listen, re-record or delete.
struct MvVoiceNote: View {
    @Environment(\.wa) private var wa
    @ObservedObject var draft: MovingDraft
    @StateObject private var recorder = ChatVoiceRecorder()
    @State private var uploading = false
    @State private var warning: String?

    private var state: String {
        if recorder.recording { return "recording" }
        if uploading { return "uploading" }
        return draft.voice != nil ? "done" : "idle"
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Group {
                switch state {
                case "recording": VoiceRecording(recorder: recorder, onStop: upload, onCancel: { recorder.cancel() })
                case "uploading": uploadingRow
                case "done":
                    if let media = draft.voice { MvVoicePlayer(media: media, onRedo: begin, onDelete: { remove(media) }) }
                default: VoiceIdle(onRecord: begin)
                }
            }
            .transition(.opacity.combined(with: .scale(scale: 0.96)))
            .animation(.easeOut(duration: 0.22), value: state)
            if let warning { MvNote(text: warning, color: AppColors.warning) }
        }
        .onDisappear { if recorder.recording { recorder.cancel() } }
        // A note is at most two minutes: it stops and is kept at the end.
        .onChange(of: recorder.elapsedMs) { ms in if recorder.recording && ms >= maxVoiceMs { upload() } }
    }

    private var uploadingRow: some View {
        HStack(spacing: 10) {
            ProgressView().tint(wa.accent)
            Text(L("mv_voice_saving")).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
            Spacer(minLength: 0)
        }
        .padding(16)
        .background(wa.accent.opacity(0.07), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }

    private func begin() {
        warning = nil
        guard draft.uuid != nil else { warning = L("mv_media_first"); return }
        switch AVAudioSession.sharedInstance().recordPermission {
        case .granted: start()
        case .undetermined:
            AVAudioSession.sharedInstance().requestRecordPermission { ok in
                Task { @MainActor in if ok { start() } else { warning = L("mv_voice_mic_denied") } }
            }
        default: warning = L("mv_voice_mic_denied")
        }
    }

    private func start() {
        VoicePlayer.shared.stop()
        if !recorder.start() { warning = L("rd_error") }
    }

    private func upload() {
        guard let uuid = draft.uuid, let clip = recorder.finish() else { return }
        uploading = true
        Task {
            defer { try? FileManager.default.removeItem(at: clip.url) }
            guard let data = try? Data(contentsOf: clip.url) else { uploading = false; warning = L("rd_error"); return }
            let part = ApiClient.FilePart(field: "file", filename: clip.url.lastPathComponent, mimeType: "audio/mp4", data: data)
            do {
                let waveform = "[" + clip.waveform.map(String.init).joined(separator: ",") + "]"
                if let media = try await MovingApi.addVoice(uuid, file: part, durationMs: clip.durationMs, waveform: waveform).data {
                    draft.voice = media
                    draft.clearMissing("description_or_photo")
                }
            } catch {
                warning = error.apiFailure.message ?? L("rd_error")
            }
            uploading = false
        }
    }

    private func remove(_ media: MovingMediaDto) {
        guard let uuid = draft.uuid else { return }
        if VoicePlayer.shared.playingId == "mv-voice-\(media.id)" { VoicePlayer.shared.stop() }
        draft.voice = nil
        Task { await MovingApi.removeMedia(uuid, media: media.id) }
    }
}

/// Before recording: the mic on a disc that breathes softly, and what to say.
private struct VoiceIdle: View {
    @Environment(\.wa) private var wa
    let onRecord: () -> Void
    @State private var breathe = false

    var body: some View {
        Button(action: onRecord) {
            HStack(spacing: 12) {
                ZStack {
                    Circle().fill(wa.accent.opacity(0.18)).frame(width: 52, height: 52).scaleEffect(breathe ? 1.12 : 1)
                    Circle().fill(wa.accent).frame(width: 44, height: 44)
                    Image(systemName: "mic.fill").font(.system(size: 19, weight: .semibold)).foregroundStyle(.white)
                }
                VStack(alignment: .leading, spacing: 2) {
                    Text(L("mv_voice_title")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                    Text(L("mv_voice_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(14)
            .background(LinearGradient(colors: [wa.accent.opacity(0.10), wa.accent.opacity(0.03)], startPoint: .leading, endPoint: .trailing),
                        in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .onAppear { withAnimation(.easeInOut(duration: 1.3).repeatForever(autoreverses: true)) { breathe = true } }
    }
}

/// While recording: rings pulsing out of the stop button, the bars following the voice, the time and the bin.
private struct VoiceRecording: View {
    @Environment(\.wa) private var wa
    @ObservedObject var recorder: ChatVoiceRecorder
    let onStop: () -> Void
    let onCancel: () -> Void
    @State private var pulse = false
    @State private var blink = false

    var body: some View {
        let red = AppColors.danger
        VStack(spacing: 10) {
            HStack(spacing: 6) {
                Circle().fill(red.opacity(blink ? 0.25 : 1)).frame(width: 9, height: 9)
                Text(L("mv_voice_recording")).font(.dorr(13, .bold)).foregroundStyle(red).frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: voiceTime(recorder.elapsedMs) + " / " + voiceTime(maxVoiceMs)).font(.dorr(13, .black)).foregroundStyle(wa.settingsInk)
            }
            LiveBars(levels: recorder.levels, color: red)
            HStack {
                Button(action: onCancel) {
                    Image(systemName: "trash.fill").font(.system(size: 16)).foregroundStyle(wa.settingsMut)
                        .frame(width: 42, height: 42).background(wa.settingsMut.opacity(0.12), in: Circle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("mv_voice_cancel"))
                Spacer()
                ZStack {
                    // Two rings leaving the button one after the other.
                    Circle().fill(red.opacity(pulse ? 0 : 0.28)).frame(width: 56, height: 56).scaleEffect(pulse ? 1.45 : 1)
                    Circle().fill(red.opacity(pulse ? 0.14 : 0.28)).frame(width: 56, height: 56).scaleEffect(pulse ? 1.22 : 1)
                    Button(action: onStop) {
                        Image(systemName: "stop.fill").font(.system(size: 22)).foregroundStyle(.white)
                            .frame(width: 56, height: 56).background(red, in: Circle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("mv_voice_stop"))
                }
                .frame(width: 78, height: 78)
                Spacer()
                Color.clear.frame(width: 42, height: 42)
            }
            Text(L("mv_voice_stop_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
        }
        .padding(14)
        .background(red.opacity(0.07), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .onAppear {
            withAnimation(.linear(duration: 1.4).repeatForever(autoreverses: false)) { pulse = true }
            withAnimation(.easeInOut(duration: 0.6).repeatForever(autoreverses: true)) { blink = true }
        }
    }
}

/// The live level, newest at the end, each bar springing to its height.
private struct LiveBars: View {
    let levels: [Int]
    let color: Color

    var body: some View {
        let shown = Array((Array(repeating: 4, count: max(40 - levels.count, 0)) + levels).suffix(40))
        HStack(spacing: 2) {
            ForEach(Array(shown.enumerated()), id: \.offset) { _, level in
                let h = CGFloat(level) / 100
                Capsule().fill(color.opacity(0.35 + 0.65 * h)).frame(height: 4 + 34 * h).frame(maxWidth: .infinity)
            }
        }
        .frame(height: 38)
        .animation(.spring(response: 0.25, dampingFraction: 0.6), value: levels)
    }
}

/// A saved voice note: play / pause, its bars filling as it plays, the time; `onRedo` and `onDelete` when it can still be changed
/// (the draft), nothing more where it is only heard.
struct MvVoicePlayer: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var player = VoicePlayer.shared
    let media: MovingMediaDto
    var onRedo: (() -> Void)?
    var onDelete: (() -> Void)?

    private var id: String { "mv-voice-\(media.id)" }

    var body: some View {
        let mine = player.playingId == id
        let duration = media.durationMs ?? 0
        HStack(spacing: 10) {
            Button {
                if let url = ApiClient.mediaUrl(media.url) { player.toggle(id, url) }
            } label: {
                Image(systemName: player.isPlaying(id) ? "pause.fill" : "play.fill").font(.system(size: 18)).foregroundStyle(.white)
                    .frame(width: 44, height: 44).background(wa.accent, in: Circle())
            }
            .buttonStyle(.plain)
            VStack(alignment: .leading, spacing: 2) {
                Waveform(bars: media.waveform.isEmpty ? (0..<40).map { 20 + ($0 * 37 % 60) } : media.waveform,
                         progress: mine ? player.progress : 0, color: wa.accent)
                    .frame(height: 30)
                Text(verbatim: mine ? voiceTime(player.positionMs) + " / " + voiceTime(duration) : voiceTime(duration))
                    .font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if let onRedo {
                Button(action: onRedo) {
                    Image(systemName: "arrow.counterclockwise").font(.system(size: 16, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 36, height: 36)
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("mv_voice_redo"))
            }
            if let onDelete {
                Button(action: onDelete) {
                    Image(systemName: "trash.fill").font(.system(size: 16)).foregroundStyle(AppColors.danger).frame(width: 36, height: 36)
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("mv_voice_delete"))
            }
        }
        .padding(10)
        .background(wa.accent.opacity(0.08), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}

/// The note's bars, the played part in the app colour (from the reading side in Arabic too: the HStack follows the language).
private struct Waveform: View {
    let bars: [Int]
    let progress: Double
    let color: Color

    var body: some View {
        HStack(alignment: .center, spacing: 2) {
            ForEach(Array(bars.enumerated()), id: \.offset) { i, v in
                let on = (Double(i) + 0.5) / Double(max(bars.count, 1)) <= progress
                Capsule().fill(on ? color : color.opacity(0.28))
                    .frame(height: max(30 * CGFloat(min(max(v, 6), 100)) / 100, 3))
                    .frame(maxWidth: .infinity)
            }
        }
        .animation(.linear(duration: 0.12), value: progress)
    }
}
