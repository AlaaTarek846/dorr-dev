import AVFoundation
import SwiftUI

// freelanceApp `ui/screens/ConsultationSessionScreen.kt`.

/// FL-L16, the consultation's video session (adapted from the DORR app's call screen): asks for the microphone and camera, takes a
/// ticket and goes into the LiveKit room; the client's camera fills the screen and mine floats in a corner; mute, camera, switch camera,
/// speaker and leave; the time left to `ends_at` (the phone's clock, corrected by the server's), a warning `warn_before_end_minutes`
/// before the end and out at the end. The screen stays on while it is open.
@MainActor
struct ConsultationSessionScreen: View {
    let uuid: String
    let onBack: () -> Void

    @ObservedObject private var call = SessionCallController.shared
    @State private var denied: String?
    @State private var confirmLeave = false

    private func close() {
        call.clear()
        onBack()
    }

    /// Into the room once (coming back to a session already running only shows it).
    private func enter() async {
        if call.isIn(uuid) { return }
        if call.ongoing() { call.leave() }
        call.clear()
        let mic = await withCheckedContinuation { c in AVAudioSession.sharedInstance().requestRecordPermission { c.resume(returning: $0) } }
        if AVCaptureDevice.authorizationStatus(for: .video) == .notDetermined { _ = await AVCaptureDevice.requestAccess(for: .video) }
        if mic {
            call.start(uuid, title: "", offline: L("login_error_offline"), generic: L("login_error_generic"))
        } else {
            denied = L("ses_mic_needed")
        }
    }

    var body: some View {
        let phase = call.phase
        ZStack {
            Color(hex: 0x0B1220).ignoresSafeArea()
            if let remote = call.videos.first(where: { !$0.isLocal }) {
                SessionVideoSurface(video: remote).ignoresSafeArea()
            } else {
                SesWaiting(phase: phase)
            }
            if let local = call.videos.first(where: { $0.isLocal }) {
                VStack {
                    HStack {
                        Spacer()
                        SessionVideoSurface(video: local)
                            .frame(width: 110, height: 160)
                            .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
                            .shadow(color: .black.opacity(0.4), radius: 16)
                    }
                    Spacer()
                }
                .padding(.top, 92).padding(.horizontal, 16)
            }

            VStack(spacing: 0) {
                SesTopBar()
                Spacer()
                if phase == .active || phase == .connecting {
                    SesControls { confirmLeave = true }
                }
            }

            if phase == .ended || denied != nil {
                let text = denied ?? call.error ?? L(call.endedByTime ? "ses_ended_time" : "ses_ended")
                SesEnded(text: text, onClose: close)
                    // A plain leave closes by itself; a reason to read stays until it is closed.
                    .task {
                        guard denied == nil, call.error == nil, !call.endedByTime else { return }
                        try? await Task.sleep(nanoseconds: 1_200_000_000)
                        guard !Task.isCancelled else { return }
                        close()
                    }
            }
        }
        .navigationBarHidden(true)
        .task(id: uuid) { await enter() }
        // The screen stays on for the whole session.
        .onAppear { UIApplication.shared.isIdleTimerDisabled = true }
        .onDisappear { UIApplication.shared.isIdleTimerDisabled = false }
        .dorrDialog(isPresented: $confirmLeave) {
            ConfirmDialog(icon: "phone.down.fill", title: L("ses_leave_title"), message: L("ses_leave_body"), confirmLabel: L("ses_leave"),
                          onConfirm: { confirmLeave = false; call.leave() }, onDismiss: { confirmLeave = false }, dismissLabel: L("ses_keep_in"))
        }
    }
}

/// The title, the recording dot, the time left and — near the end — the warning.
@MainActor
private struct SesTopBar: View {
    @ObservedObject private var call = SessionCallController.shared

    var body: some View {
        let active = call.phase == .active
        // The clock only (once a second) — the end itself is SessionCallController's own timer.
        TimelineView(.periodic(from: .now, by: 1)) { _ in
            let left = call.secondsLeft()
            let warnMinutes = call.ticket?.warnBeforeEndMinutes ?? 5
            VStack(spacing: 10) {
                HStack(spacing: 8) {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: call.otherName.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? L("ses_title"))
                            .font(.dorr(18, .heavy)).foregroundStyle(.white).lineLimit(1)
                        Text(L(active ? "ses_ongoing" : "ses_connecting")).font(.dorr(12)).foregroundStyle(.white.opacity(0.75))
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    if call.recording && active {
                        HStack(spacing: 4) {
                            Image(systemName: "circle.fill").font(.system(size: 9)).foregroundStyle(.white)
                            Text(L("ses_rec_badge")).font(.dorr(11, .heavy)).foregroundStyle(.white)
                        }
                        .padding(.horizontal, 10).padding(.vertical, 5)
                        .background(Color(hex: 0xDC2626), in: Capsule())
                    }
                    if active && call.endsAtMs != nil {
                        HStack(spacing: 4) {
                            Image(systemName: "timer").font(.system(size: 13)).foregroundStyle(.white)
                            Text(verbatim: "\u{200E}" + sesClock(left)).font(.dorr(12, .bold)).foregroundStyle(.white)
                        }
                        .padding(.horizontal, 10).padding(.vertical, 5)
                        .background(Color.white.opacity(0.16), in: Capsule())
                    }
                }
                if active && left > 0 && left <= warnMinutes * 60 {
                    HStack(spacing: 8) {
                        Image(systemName: "exclamationmark.triangle.fill").font(.system(size: 16)).foregroundStyle(.white)
                        Text(L("ses_warn", (left + 59) / 60)).font(.dorr(13, .bold)).foregroundStyle(.white)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .padding(12)
                    .background(Color(hex: 0xD97706), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
            }
            .padding(16)
        }
    }
}

/// No video from the other side yet: who we are waiting for (or that we are connecting).
@MainActor
private struct SesWaiting: View {
    @ObservedObject private var call = SessionCallController.shared
    let phase: SessionPhase?

    var body: some View {
        VStack(spacing: 0) {
            Image(systemName: "person.fill").font(.system(size: 44)).foregroundStyle(.white.opacity(0.8))
                .frame(width: 96, height: 96).background(Color.white.opacity(0.1), in: Circle())
                .padding(.bottom, 16)
            if phase == .connecting {
                ProgressView().tint(.white).scaleEffect(1.1).padding(.bottom, 10)
                Text(L("ses_connecting")).font(.dorr(15, .bold)).foregroundStyle(.white)
            } else if phase == .active && !call.otherInRoom {
                let name = call.otherName.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? L("ses_the_client")
                Text(L("ses_waiting_other", name)).font(.dorr(15, .bold)).foregroundStyle(.white).multilineTextAlignment(.center)
            } else if phase == .active {
                Text(verbatim: call.otherName ?? "").font(.dorr(17, .heavy)).foregroundStyle(.white).multilineTextAlignment(.center)
            }
        }
        .padding(32)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

@MainActor
private struct SesControls: View {
    @ObservedObject private var call = SessionCallController.shared
    let onLeave: () -> Void

    var body: some View {
        let active = call.phase == .active
        HStack {
            Spacer(minLength: 0)
            round(call.micOn ? "mic.fill" : "mic.slash.fill", L(call.micOn ? "ses_mute" : "ses_unmute"), on: call.micOn, enabled: active) { call.toggleMic() }
            Spacer(minLength: 0)
            round(call.cameraOn ? "video.fill" : "video.slash.fill", L("ses_camera"), on: call.cameraOn, enabled: active && call.cameraAllowed()) { call.toggleCamera() }
            Spacer(minLength: 0)
            round("arrow.triangle.2.circlepath.camera.fill", L("ses_switch_camera"), on: true, enabled: active && call.cameraOn) { call.flipCamera() }
            Spacer(minLength: 0)
            round(call.speakerOn ? "speaker.wave.2.fill" : "ear.fill", L("ses_speaker"), on: call.speakerOn, enabled: active) { call.toggleSpeaker() }
            Spacer(minLength: 0)
            Button(action: onLeave) {
                Image(systemName: "phone.down.fill").font(.system(size: 22)).foregroundStyle(.white)
                    .frame(width: 56, height: 56).background(Color(hex: 0xDC2626), in: Circle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("ses_leave"))
            Spacer(minLength: 0)
        }
        .padding(.vertical, 12)
        .background(Color.black.opacity(0.45), in: RoundedRectangle(cornerRadius: 28, style: .continuous))
        .padding(.horizontal, 16).padding(.vertical, 20)
    }

    private func round(_ icon: String, _ label: String, on: Bool, enabled: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 21)).foregroundStyle(on ? Color.white : Color(hex: 0x0B1220))
                .frame(width: 50, height: 50).background(on ? Color.white.opacity(0.18) : Color.white, in: Circle())
        }
        .buttonStyle(.plain)
        .opacity(enabled ? 1 : 0.4)
        .disabled(!enabled)
        .accessibilityLabel(label)
    }
}

@MainActor
private struct SesEnded: View {
    let text: String
    let onClose: () -> Void

    var body: some View {
        VStack(spacing: 16) {
            Text(verbatim: text).font(.dorr(16, .bold)).foregroundStyle(.white).multilineTextAlignment(.center).lineSpacing(7)
            Button(action: onClose) {
                Text(L("ses_close")).font(.dorr(15, .heavy)).foregroundStyle(Color(hex: 0x0B1220))
                    .padding(.horizontal, 28).padding(.vertical, 12)
                    .background(Color.white, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            }
            .buttonStyle(.plain)
        }
        .padding(32)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color.black.opacity(0.7).ignoresSafeArea())
    }
}
