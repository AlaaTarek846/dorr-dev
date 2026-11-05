import AVFoundation
import LiveKit
import SwiftUI

// freelanceApp `session/SessionCallController.kt`.
//
// The only file of the app that imports LiveKit: everything else talks to `SessionCallController`, `SessionPhase`, `SessionVideo`
// and `SessionVideoSurface`, which carry no LiveKit type in their surface (scripts/typecheck.sh swaps this file for a stand-in).

/// Where the session is on this phone.
enum SessionPhase { case connecting, active, ended }

/// Someone's camera in the room.
struct SessionVideo: Identifiable {
    let key: String
    let isLocal: Bool
    fileprivate let track: VideoTrack

    var id: String { "\(key)|\(isLocal)|\(ObjectIdentifier(track as AnyObject).hashValue)" }
}

/// A LiveKit video track on screen (as in the DORR app's call screen); my own camera mirrored.
struct SessionVideoSurface: View {
    let video: SessionVideo

    var body: some View {
        SwiftUIVideoView(video.track, layoutMode: .fill, mirrorMode: video.isLocal ? .mirror : .off)
            .id(video.id)
    }
}

/// The consultation video session this phone is in (FL-L16) — app-wide, so it keeps going if I step to another screen. A copy, cut down
/// to two people and no ringing, of the DORR app's chat call controller (iosApp `Chat/CallController`): LiveKit carries the sound and
/// the video, the server is told when I am in (`connected`) and when I leave (`leave`). The end comes from `ends_at` on the phone's clock
/// (corrected by the ticket's `server_time`) — nothing asks the server on a timer; the server also closes the room itself at the end.
@MainActor
final class SessionCallController: NSObject, ObservableObject {
    static let shared = SessionCallController()

    @Published private(set) var phase: SessionPhase?
    /// The consultation whose room I am in (or getting into).
    @Published private(set) var consultationUuid: String?
    @Published private(set) var title = ""
    @Published private(set) var ticket: ConsultationJoinDto?
    /// When the room closes, on the server's clock (epoch ms).
    @Published private(set) var endsAtMs: Int?
    /// The session is being recorded (the ticket says so).
    @Published private(set) var recording = false
    @Published private(set) var otherName: String?
    /// The other side is in the room right now (from LiveKit's own participant list).
    @Published private(set) var otherInRoom = false
    @Published private(set) var micOn = true
    @Published private(set) var cameraOn = true
    @Published private(set) var flippedCamera = false
    @Published private(set) var speakerOn = true
    @Published private(set) var connectedAt: Date?
    /// Why it ended or could not start (shown once by the screen).
    @Published var error: String?
    /// The room's time ran out (auto-leave) — the screen says so.
    @Published private(set) var endedByTime = false
    @Published private(set) var videos: [SessionVideo] = []

    private var room: Room?
    private var endTask: Task<Void, Never>?

    /// At launch (FreelanceApp.kt's `attach`): the background side of the session (SessionCallService) starts listening.
    func attach() {
        SessionCallService.attach()
    }

    func ongoing() -> Bool { phase == .connecting || phase == .active }

    func isIn(_ uuid: String) -> Bool { ongoing() && consultationUuid == uuid }

    // MARK: - actions

    /// Ask for a ticket and go into the room. `offline` / `generic` are the texts for a failure without the server's own message.
    func start(_ uuid: String, title: String, offline: String, generic: String) {
        if ongoing() { return }
        consultationUuid = uuid
        self.title = title
        error = nil
        endedByTime = false
        micOn = true
        cameraOn = cameraAllowed()
        flippedCamera = false
        speakerOn = true
        updatePhase(.connecting)
        Task {
            let r = await ConsultationSessionApi.join(uuid)
            switch r {
            case let .ok(join, _): await connect(join)
            case let .failed(e):
                error = e.displayMessage.isEmpty ? generic : e.displayMessage
                end(tellServer: false)
            case .offline:
                error = offline
                end(tellServer: false)
            }
        }
    }

    /// I leave the room (Leave, or the time is over): the server is told; coming back while time is left is fine.
    func leave() {
        guard let phase, phase != .ended else { return }
        end(tellServer: true)
    }

    func toggleMic() {
        micOn.toggle()
        SessionCallService.update()
        Task {
            await applyMic()
            refreshVideos()
        }
    }

    func cameraAllowed() -> Bool { AVCaptureDevice.authorizationStatus(for: .video) == .authorized }

    func toggleCamera() {
        if !cameraOn && !cameraAllowed() { return }
        cameraOn.toggle()
        if !cameraOn { flippedCamera = false }
        Task {
            await applyCamera()
            refreshVideos()
        }
    }

    func flipCamera() {
        guard let track = room?.localParticipant.firstCameraVideoTrack as? LocalVideoTrack,
              let capturer = track.capturer as? CameraCapturer else { return }
        Task { if (try? await capturer.switchCameraPosition()) != nil { flippedCamera.toggle() } }
    }

    func toggleSpeaker() {
        speakerOn.toggle()
        applySpeaker()
    }

    /// The account signed out on this phone: the session ends with it.
    func signedOut() {
        if ongoing() { leave() }
    }

    /// Seconds left until the room closes, on the server's clock.
    func secondsLeft() -> Int {
        guard let at = endsAtMs else { return 0 }
        return max((at - ServerClock.nowMs) / 1000, 0)
    }

    // MARK: - LiveKit

    private func connect(_ join: ConsultationJoinDto) async {
        ticket = join
        ServerClock.observe(join.serverTime)
        endsAtMs = parseInstant(join.endsAt).map { Int($0.timeIntervalSince1970 * 1000) }
        recording = join.recording ?? false
        otherName = join.other?.name
        otherInRoom = join.other?.inRoom == true
        let r = room ?? Room(delegate: self)
        room = r
        do {
            try await r.connect(url: join.url ?? "", token: join.token ?? "")
        } catch {
            self.error = error.localizedDescription
            end(tellServer: false)
            return
        }
        // The session may have been left while LiveKit was connecting.
        guard room === r, ongoing() else { return }
        // The microphone and the camera each fail on their own (no permission, camera busy…) without taking the session down.
        await applyMic()
        if cameraOn { await applyCamera() }
        applySpeaker()
        connectedAt = Date()
        updatePhase(.active)
        refreshVideos()
        if let uuid = consultationUuid { Task { _ = await ConsultationSessionApi.connected(uuid) } }
        scheduleEnd()
    }

    /// Out of the room at `ends_at` (the phone's clock, corrected) — one timer for a time already known, no request on the way.
    private func scheduleEnd() {
        endTask?.cancel()
        guard let at = endsAtMs else { return }
        let wait = max(at - ServerClock.nowMs, 0)
        endTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: UInt64(wait) * 1_000_000)
            guard let self, !Task.isCancelled, self.ongoing() else { return }
            self.endedByTime = true
            self.end(tellServer: true)
        }
    }

    private func applyMic() async {
        guard let me = room?.localParticipant else { return }
        _ = try? await me.setMicrophone(enabled: micOn)
        micOn = me.isMicrophoneEnabled()
    }

    private func applyCamera() async {
        guard let me = room?.localParticipant else { return }
        if cameraOn && !cameraAllowed() {
            cameraOn = false
            return
        }
        do {
            try await me.setCamera(enabled: cameraOn, captureOptions: CameraCaptureOptions(position: .front))
        } catch {
            cameraOn = false
            _ = try? await me.setCamera(enabled: false)
        }
    }

    fileprivate func refreshVideos() {
        guard let r = room else { return }
        var list: [SessionVideo] = []
        for p in r.remoteParticipants.values {
            if let pub = p.firstCameraPublication, !pub.isMuted, let t = pub.track as? VideoTrack {
                list.append(SessionVideo(key: keyOf(p), isLocal: false, track: t))
            }
        }
        if cameraOn, let t = r.localParticipant.firstCameraVideoTrack {
            list.append(SessionVideo(key: keyOf(r.localParticipant), isLocal: true, track: t))
        }
        videos = list
        otherInRoom = !r.remoteParticipants.isEmpty
        if let name = r.remoteParticipants.values.first?.name, !name.trimmingCharacters(in: .whitespaces).isEmpty { otherName = name }
    }

    private func keyOf(_ p: Participant) -> String { p.identity?.stringValue ?? p.sid?.stringValue ?? "" }

    /// The loudspeaker, or else a headset / the earpiece — LiveKit's audio session follows this preference.
    private func applySpeaker() {
        AudioManager.shared.isSpeakerOutputPreferred = speakerOn
    }

    // MARK: - the end

    private func updatePhase(_ next: SessionPhase?) {
        phase = next
        if next == .connecting || next == .active { SessionCallService.update() } else { SessionCallService.stop() }
    }

    private func end(tellServer: Bool) {
        if tellServer, let uuid = consultationUuid { Task { _ = await ConsultationSessionApi.leave(uuid) } }
        updatePhase(.ended)
        endTask?.cancel()
        if let r = room { Task { await r.disconnect() } }
        room = nil
        videos = []
        connectedAt = nil
        otherInRoom = false
        flippedCamera = false
    }

    /// The screen closed after the end: nothing is left behind.
    func clear() {
        if ongoing() { return }
        phase = nil
        ticket = nil
        endsAtMs = nil
        recording = false
        error = nil
        endedByTime = false
    }
}

extension SessionCallController: RoomDelegate {
    private nonisolated func changed(_ room: Room) {
        Task { @MainActor in if self.room === room { self.refreshVideos() } }
    }

    nonisolated func room(_ room: Room, participantDidConnect participant: RemoteParticipant) { changed(room) }
    nonisolated func room(_ room: Room, participantDidDisconnect participant: RemoteParticipant) { changed(room) }
    nonisolated func room(_ room: Room, participant: Participant, trackPublication: TrackPublication, didUpdateIsMuted isMuted: Bool) { changed(room) }
    nonisolated func room(_ room: Room, participant: RemoteParticipant, didSubscribeTrack publication: RemoteTrackPublication) { changed(room) }
    nonisolated func room(_ room: Room, participant: RemoteParticipant, didUnsubscribeTrack publication: RemoteTrackPublication) { changed(room) }
    nonisolated func room(_ room: Room, participant: LocalParticipant, didPublishTrack publication: LocalTrackPublication) { changed(room) }

    /// The room is gone (the server closed it at the end, or the staff did, or the network is gone for good).
    nonisolated func room(_ room: Room, didDisconnectWithError error: LiveKitError?) {
        Task { @MainActor in
            if self.room === room, self.phase == .active { self.end(tellServer: true) }
        }
    }
}
