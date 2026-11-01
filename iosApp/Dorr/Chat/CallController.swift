import AVFoundation
import AudioToolbox
import Combine
import LiveKit
import UIKit
import UserNotifications

// androidApp `chat/CallController.kt` + `CallNotifications.kt` (Ringer) — the one call this phone can be in (app-wide:
// a call rings over any screen). It drives the server's ringing state machine (start / accept / decline / leave) and
// the LiveKit room that carries the audio and video. The UI (CallOverlay) only reads this object's state.
// Android keeps the call alive in the background with a foreground service; iOS does it with the `audio` / `voip`
// background modes.

/// The server answered without a call session.
private struct NoSession: Error {}

/// Where a call is.
enum CallPhase { case incoming, outgoing, connecting, active, ended }

/// Someone's video in the call (their camera track), keyed by their participant key ("user:7").
struct CallVideo: Identifiable {
    let key: String
    let track: VideoTrack
    let isLocal: Bool
    var screen = false

    var id: String { "\(key)|\(isLocal)|\(screen)" }
}

/// Someone in the call as the group grid shows them: their camera when it's on, else their photo.
struct CallMember: Identifiable {
    let key: String
    let name: String?
    let avatar: String?
    let video: CallVideo?
    let micOn: Bool
    let speaking: Bool
    let isLocal: Bool

    var id: String { key }
}

@MainActor
final class CallController: NSObject, ObservableObject {
    static let shared = CallController()

    /// A little past the server's ring time (chat.call_ring_timeout_seconds = 45).
    private static let ringTimeout: TimeInterval = 50
    private static let connectTimeout: TimeInterval = 25
    private static let aloneTimeout: UInt64 = 20_000_000_000
    private static let pollNs: UInt64 = 5_000_000_000

    @Published private(set) var phase: CallPhase?

    /// This call broadcast live (spec 93): its token, status and viewers — nil when it isn't.
    @Published var live: LiveDto?

    // Recording (spec 92): nothing is recorded until everyone in the call said yes.

    /// Asking everyone (`pending`) or `recording` right now; nil when neither.
    @Published private(set) var recording: CallRecordingStateDto?
    /// The call may be recorded (the server has the storage and the admin left it on).
    @Published private(set) var recordingAvailable = false
    /// When this phone first saw it recording — the REC timer.
    @Published private(set) var recordingSince: Date?
    /// Joining waits for my yes: the call records (or asks to), and the server lets nobody in without agreeing.
    @Published private(set) var joinConsent: CallRecordingStateDto?
    /// What became of the recording ("recording", "declined", "expired", "processing"…), shown once.
    @Published var recordingNote: String?
    /// A server message about recording (e.g. it isn't available), shown once.
    @Published var recordingError: String?

    /// Joins again with my yes — set while `joinConsent` waits.
    private var joinAgain: (() -> Void)?
    private var lastRecording: String?
    /// This call rang in (rather than me calling).
    private var ringingIn = false

    @Published private(set) var call: CallDto?
    /// Who the call is with (the other person, or the group).
    @Published private(set) var title = ""
    @Published private(set) var avatar: String?
    @Published private(set) var peerKey: String?
    @Published private(set) var isVideo = false
    @Published private(set) var micOn = true
    @Published private(set) var cameraOn = false
    @Published private(set) var speakerOn = false
    /// The call screen shrunk to a small window I can drag (⌄) while I use the app.
    @Published var minimized = false
    /// My screen is going out to the call (WhatsApp list 71) — on iOS the app's own screen.
    @Published private(set) var sharingScreen = false
    /// Joined from a call link (WhatsApp list 72): no ringing, no call row — just the room.
    @Published private(set) var linkToken: String?
    @Published private(set) var connectedAt: Date?
    @Published var error: String?
    /// A short message over the call (Android's toast), shown once.
    @Published var notice: String?

    @Published private(set) var videos: [CallVideo] = []
    /// Everyone in the room, me last — what a group call shows as a grid.
    @Published private(set) var members: [CallMember] = []

    private(set) var room: Room?
    private var watchTask: Task<Void, Never>?
    private var aloneTask: Task<Void, Never>?
    private var endTask: Task<Void, Never>?
    private var events: AnyCancellable?

    func attach() {
        guard events == nil else { return }
        events = ChatRealtime.shared.events.receive(on: DispatchQueue.main).sink { [weak self] e in self?.onEvent(e) }
    }

    /// Every phase change goes through here, so the sounds follow the call: the ringtone while it rings in the open
    /// app, the ring-back tone while I'm calling, silence otherwise — and the ringing notification goes away as soon as
    /// the call is answered, declined or over.
    private func updatePhase(_ next: CallPhase?) {
        let changed = phase != next
        phase = next
        if next == .incoming { ringingIn = true } else if next == .outgoing { ringingIn = false }
        if changed { watch(next) }
        // The usage stats count a call from when it goes through.
        if changed && next == .active { ChatUsage.callStarted(incoming: ringingIn) }
        if changed && next == .ended { ChatUsage.callEnded() }
        // A new call opens full screen; a finished one leaves nothing minimised behind.
        if next == nil || next == .incoming || (changed && next == .outgoing) { minimized = false }
        // The screen stays on while a call is going.
        UIApplication.shared.isIdleTimerDisabled = next != nil && next != .ended
        switch next {
        // The phone's own call screen (CallKit) rings a call that came by VoIP push — no second ringtone.
        case .incoming: if ChatRealtime.shared.inForeground && !CallSystem.shared.isReported(call?.id) { Ringer.shared.startIncoming() }
        case .outgoing: Ringer.shared.startRingback()
        default:
            Ringer.shared.stop()
            if let id = call?.id { CallNotifications.cancel(id) }
        }
        CallWindow.shared.update()
        CallPip.shared.update()
    }

    // MARK: - actions

    func start(conversationId: String, video: Bool, title: String, avatar: String?, peerKey: String?) {
        if let phase, phase != .ended { return }
        self.title = title
        self.avatar = avatar
        self.peerKey = peerKey
        isVideo = video
        cameraOn = video
        speakerOn = video
        micOn = true
        error = nil
        updatePhase(.outgoing)
        Task { await startSession(conversationId, video: video, recordingConsent: false) }
    }

    /// Into the room behind a call link (WhatsApp list 72): nobody rings, I'm in as soon as it connects, and it stays
    /// open while I wait for others.
    func joinLink(token: String, join: JoinDto, video: Bool, title: String, avatar: String?) {
        if let phase, phase != .ended { return }
        call = nil
        linkToken = token
        self.title = title
        self.avatar = avatar
        peerKey = nil
        isVideo = video
        cameraOn = video
        speakerOn = true
        micOn = true
        error = nil
        updatePhase(.connecting)
        Task { await connect(join) }
    }

    private func startSession(_ conversationId: String, video: Bool, recordingConsent: Bool) async {
        do {
            guard let session = try await ChatApi.startCall(conversationId, type: video ? "video" : "audio", recordingConsent: recordingConsent).data else {
                throw NoSession()
            }
            take(session.call)
            // A group call already running: we joined it straight away.
            if session.call.status == "ongoing" { updatePhase(.connecting) }
            if let join = session.join { await connect(join) }
        } catch {
            let failure = error.apiFailure
            // That group call records: hopping in takes my yes.
            if askToJoin(failure, again: { [weak self] in Task { await self?.startSession(conversationId, video: video, recordingConsent: true) } }) { return }
            self.error = failure.message
            end()
        }
    }

    /// The call as the server sent it, with its recording state.
    private func take(_ dto: CallDto) {
        call = dto
        recordingAvailable = dto.recordingAvailable == true
        showRecording(dto.recording)
    }

    /// The call is over on the other side (caller hung up / ring timed out) — close the ringing page.
    func onRemoteEnded(_ callId: String) {
        if call?.id == callId, let phase, phase != .ended { end() }
    }

    /// Opened from a call notification (the app was closed, so the real-time "ringing" was missed): if the call still
    /// rings, show the incoming screen for it — and, for «Answer», pick up straight away when the mic is allowed.
    /// `done` hears whether it still rings (the system call screen closes when it doesn't).
    func loadIncoming(_ callId: String, autoAnswer: Bool = false, done: ((Bool) -> Void)? = nil) {
        if let phase, phase != .ended {
            done?(call?.id == callId && phase == .incoming)
            return
        }
        Task {
            guard let dto = try? await ChatApi.call(callId).data, dto.status == "ringing", dto.isOutgoing != true else {
                done?(false)
                return
            }
            if let phase, phase != .ended {
                done?(call?.id == callId && phase == .incoming)
                return
            }
            take(dto)
            isVideo = dto.type == "video"
            cameraOn = isVideo
            speakerOn = isVideo
            micOn = true
            title = dto.conversationType == "group" ? (dto.groupName ?? "") : (dto.initiator?.name ?? "")
            avatar = dto.initiator?.avatar
            peerKey = dto.initiator?.key
            updatePhase(.incoming)
            done?(true)
            if autoAnswer, AVAudioSession.sharedInstance().recordPermission == .granted { accept() }
        }
    }

    /// «Answer» — through the system call screen when it's ringing this call too, so both agree.
    func accept() {
        if let id = call?.id, CallSystem.shared.answer(id) { return }
        acceptNow()
    }

    /// Pick up now (CallKit's Answer lands here).
    func acceptNow() { accept(recordingConsent: false) }

    private func accept(recordingConsent: Bool) {
        guard let current = call else { return }
        updatePhase(.connecting)
        Task {
            do {
                guard let session = try await ChatApi.acceptCall(current.id, recordingConsent: recordingConsent ? true : nil).data else { throw NoSession() }
                take(session.call)
                if let join = session.join { await connect(join) }
            } catch {
                let failure = error.apiFailure
                // The call records (or asks to): answering takes my yes.
                if askToJoin(failure, again: { [weak self] in self?.accept(recordingConsent: true) }) { return }
                self.error = failure.message
                end()
            }
        }
    }

    /// The server let me in only with a yes to the recording: ask, and hold the call (no ringing, no connect timeout)
    /// until I answer `answerJoinConsent`.
    private func askToJoin(_ failure: ApiFailure, again: @escaping () -> Void) -> Bool {
        guard failure.errorCode == "chat_recording_consent_required" else { return false }
        updatePhase(.connecting)
        watchTask?.cancel()
        joinAgain = again
        joinConsent = recording ?? CallRecordingStateDto(status: "recording")
        return true
    }

    /// Join with my yes to the recording — or don't join (an incoming call is declined).
    func answerJoinConsent(_ agree: Bool) {
        let again = joinAgain
        joinConsent = nil
        joinAgain = nil
        if agree, let again {
            watch(.connecting)
            again()
        } else if ringingIn {
            decline()
        } else {
            end()
        }
    }

    // MARK: - recording (spec 92)

    /// Ask everyone in the call to agree to recording it — my asking is my yes.
    func requestRecording() { recordingAction { try await ChatApi.requestRecording($0) } }

    /// My answer when someone asked to record.
    func answerRecording(_ accept: Bool) { recordingAction { try await ChatApi.answerRecording($0, agree: accept) } }

    /// Stop the recording, or take the request back (anyone in the call can).
    func stopRecording() { recordingAction { try await ChatApi.stopRecording($0) } }

    /// I'm one of the people the request waits for.
    func mustAnswerRecording() -> Bool {
        guard let r = recording, r.status == "pending" else { return false }
        return (r.waitingFor ?? []).contains(myKey())
    }

    /// Who someone in the call is ("user:7" → their name), for "Ahmed wants to record".
    func nameOf(_ key: String?) -> String? {
        guard let key else { return nil }
        return members.first { $0.key == key }?.name ?? (key == peerKey ? title : nil)
    }

    private func recordingAction(_ request: @escaping (String) async throws -> ApiEnvelope<CallRecordingStateDto>) {
        guard let id = call?.id else { return }
        Task {
            do {
                showRecording(try await request(id).data)
            } catch {
                recordingError = error.apiFailure.message ?? L("cr_failed")
            }
        }
    }

    /// The recording's new state, from an answer or the real-time event — each change noted once.
    private func showRecording(_ state: CallRecordingStateDto?) {
        let open = ["pending", "recording"].contains(state?.status ?? "") ? state : nil
        if open?.status == "recording" && recording?.status != "recording" {
            let started = open?.startedAt.flatMap(parseRideTime)
            recordingSince = min(started ?? Date(), Date())
        }
        if open?.status != "recording" { recordingSince = nil }
        recording = open
        guard let state else { return }
        let now = "\(state.id ?? "")|\(state.status ?? "")"
        // Only what happens while I'm in the call (not what it was when it rang), and not my own "no".
        let inCall = phase == .active || phase == .connecting
        let myNo = state.status == "declined" && (state.declined ?? []).contains(myKey())
        if lastRecording != now && inCall && state.status != "pending" && !myNo { recordingNote = state.status }
        lastRecording = now
    }

    func decline() {
        guard let current = call else { return }
        Task { _ = try? await ChatApi.declineCall(current.id) }
        end()
    }

    func hangUp() {
        if let current = call { Task { _ = try? await ChatApi.leaveCall(current.id) } }
        end()
    }

    func toggleMic() {
        micOn.toggle()
        Task {
            await applyMic()
            refreshVideos()
        }
    }

    /// Whether this phone may use the camera — the overlay asks for it before turning it on.
    func cameraAllowed() -> Bool { AVCaptureDevice.authorizationStatus(for: .video) == .authorized }

    func toggleCamera() {
        if !cameraOn && !cameraAllowed() { return }
        cameraOn.toggle()
        Task {
            await applyCamera()
            refreshVideos()
        }
    }

    func flipCamera() {
        guard let track = room?.localParticipant.firstCameraVideoTrack as? LocalVideoTrack,
              let capturer = track.capturer as? CameraCapturer else { return }
        Task { _ = try? await capturer.switchCameraPosition() }
    }

    func toggleSpeaker() {
        speakerOn.toggle()
        applySpeaker()
    }

    /// Share my screen — the whole phone, like Android: the `DorrBroadcast` extension (LiveKit's broadcast uploader)
    /// captures it once I pick it in the system's sheet, and LiveKit publishes it when the broadcast starts. Without
    /// the extension (no app group: the simulator, an unsigned build) only DORR's own screen is shared.
    func startScreenShare() {
        guard let me = room?.localParticipant else { return }
        watchBroadcast()
        Task {
            do {
                // nil: the system's broadcast sheet is up; the track comes when the broadcast starts.
                let published = try await me.setScreenShare(enabled: true)
                sharingScreen = published != nil || BroadcastManager.shared.isBroadcasting
            } catch {
                sharingScreen = false
                notice = L("ch_call_share_failed")
            }
            refreshVideos()
        }
    }

    func stopScreenShare() {
        guard let me = room?.localParticipant else { return }
        if BroadcastManager.shared.isBroadcasting { BroadcastManager.shared.requestStop() }
        Task {
            _ = try? await me.setScreenShare(enabled: false)
            sharingScreen = false
            refreshVideos()
        }
    }

    private var broadcast: AnyCancellable?

    /// The whole-screen broadcast starts / stops from the system (its sheet, the red status bar): follow it.
    private func watchBroadcast() {
        guard broadcast == nil else { return }
        broadcast = BroadcastManager.shared.isBroadcastingPublisher.receive(on: DispatchQueue.main).sink { [weak self] on in
            guard let self, self.room != nil else { return }
            self.sharingScreen = on
            Task { @MainActor in
                try? await Task.sleep(nanoseconds: 500_000_000)
                self.refreshVideos()
            }
        }
    }

    /// The account signed out on this phone: whatever call there is ends with it.
    func signedOut() {
        switch phase {
        case .incoming: decline()
        case .outgoing, .connecting, .active: hangUp()
        default: break
        }
    }

    // MARK: - LiveKit

    private func connect(_ join: JoinDto) async {
        let r = room ?? Room(delegate: self, roomOptions: roomOptions())
        room = r
        do {
            try await r.connect(url: join.url, token: join.token)
        } catch {
            self.error = error.localizedDescription
            hangUp()
            return
        }
        // The microphone and the camera each fail on their own (no permission, camera busy…) without taking the whole
        // call down — the buttons then show what really happened.
        await applyMic()
        if isVideo && cameraOn { await applyCamera() }
        applySpeaker()
        // The caller is in the room already but still "Calling…" until someone answers.
        if phase != .outgoing {
            updatePhase(.active)
            connectedAt = Date()
        }
        refreshVideos()
    }

    /// Data saver / lite mode (spec 82, 87): my camera at 360p, 15 fps, ~450 kbps.
    private func roomOptions() -> RoomOptions {
        guard lightCalls else { return RoomOptions() }
        return RoomOptions(defaultCameraCaptureOptions: CameraCaptureOptions(dimensions: .h360_169, fps: 15),
                           defaultVideoPublishOptions: VideoPublishOptions(encoding: VideoEncoding(maxBitrate: 450_000, maxFps: 15)))
    }

    /// Mute / unmute for real, and check it took.
    private func applyMic() async {
        guard let me = room?.localParticipant else { return }
        _ = try? await me.setMicrophone(enabled: micOn)
        micOn = me.isMicrophoneEnabled()
    }

    /// Blur / a picture behind me / nothing (spec 94) — the camera's frames go through the processor while one is on.
    func setBackground(_ mode: CallEffects.Mode, image: UIImage? = nil, preset: Int? = nil) {
        CallEffects.shared.set(mode, image: image, preset: preset)
        (room?.localParticipant.firstCameraVideoTrack as? LocalVideoTrack)?.processor = CallEffects.shared.processor
    }

    private func applyCamera() async {
        guard let me = room?.localParticipant else { return }
        if cameraOn && !cameraAllowed() {
            cameraOn = false
            return
        }
        do {
            try await me.setCamera(enabled: cameraOn, captureOptions: CameraCaptureOptions(position: .front))
            (me.firstCameraVideoTrack as? LocalVideoTrack)?.processor = CallEffects.shared.processor
        } catch {
            cameraOn = false
            _ = try? await me.setCamera(enabled: false)
        }
    }

    fileprivate func refreshVideos() {
        guard let r = room else { return }
        var list: [CallVideo] = []
        for p in r.remoteParticipants.values {
            // A shared screen comes first: that's what they want everyone to see.
            if let pub = p.firstScreenSharePublication, !pub.isMuted, let screen = pub.track as? VideoTrack {
                list.append(CallVideo(key: keyOf(p), track: screen, isLocal: false, screen: true))
            } else if let pub = p.firstCameraPublication, !pub.isMuted, let t = pub.track as? VideoTrack {
                list.append(CallVideo(key: keyOf(p), track: t, isLocal: false))
            }
        }
        let me = r.localParticipant
        if cameraOn, let t = me.firstCameraVideoTrack {
            list.append(CallVideo(key: keyOf(me), track: t, isLocal: true))
        }
        videos = list
        CallPip.shared.update()

        let people = r.remoteParticipants.values.map { p -> CallMember in
            let key = keyOf(p)
            return CallMember(key: key, name: p.name, avatar: avatarOf(p), video: list.first { !$0.isLocal && $0.key == key },
                              micOn: p.isMicrophoneEnabled(), speaking: p.isSpeaking, isLocal: false)
        }
        .sorted { ($0.name ?? "") < ($1.name ?? "") }
        members = people + [CallMember(key: keyOf(me), name: me.name, avatar: avatarOf(me), video: list.first { $0.isLocal },
                                       micOn: micOn, speaking: me.isSpeaking, isLocal: true)]
    }

    /// The token carries `{"avatar": …}` as participant metadata (CallService::join).
    private func avatarOf(_ p: Participant) -> String? {
        guard let data = p.metadata?.data(using: .utf8),
              let json = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] else { return nil }
        return json["avatar"] as? String
    }

    private func keyOf(_ p: Participant) -> String { p.identity?.stringValue ?? p.sid?.stringValue ?? "" }

    /// The loudspeaker, or else a headset when one is plugged in / paired, or else the earpiece — LiveKit's audio
    /// session follows this preference.
    private func applySpeaker() {
        AudioManager.shared.isSpeakerOutputPreferred = speakerOn
    }

    // MARK: - never stuck

    /// A call can't hang in a ringing or connecting state: the server is asked every few seconds (a missed real-time
    /// event — screen off, network blip — would leave the page up forever), and each phase has its own limit.
    private func watch(_ next: CallPhase?) {
        watchTask?.cancel()
        guard next == .incoming || next == .outgoing || next == .connecting else { return }
        let limit = next == .connecting ? Self.connectTimeout : Self.ringTimeout
        watchTask = Task { [weak self] in
            let started = Date()
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: Self.pollNs)
                guard let self, !Task.isCancelled, self.phase == next else { return }
                var status: String?
                if let id = self.call?.id { status = try? await ChatApi.call(id).data?.status }
                guard !Task.isCancelled, self.phase == next else { return }
                if let status, status != "ringing", status != "ongoing" {
                    self.end()
                    return
                }
                // Answered while we missed the event: the caller is in the room already.
                if status == "ongoing", next == .outgoing, self.room != nil {
                    self.updatePhase(.active)
                    if self.connectedAt == nil { self.connectedAt = Date() }
                    return
                }
                if Date().timeIntervalSince(started) >= limit {
                    switch next {
                    case .incoming: self.end() // it rang out; the caller's side closes it
                    case .connecting:
                        self.error = L("ch_call_failed")
                        self.hangUp()
                    default: self.hangUp() // nobody answered
                    }
                    return
                }
            }
        }
    }

    /// A one-to-one call whose other side vanished from the room (app killed, phone off) without hanging up: after a
    /// grace period for them to come back, the call is over.
    fileprivate func watchAlone() {
        aloneTask?.cancel()
        guard let r = room, phase == .active, linkToken == nil, call?.conversationType != "group", r.remoteParticipants.isEmpty else { return }
        aloneTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: Self.aloneTimeout)
            guard let self, !Task.isCancelled else { return }
            if self.phase == .active, self.room === r, r.remoteParticipants.isEmpty { self.hangUp() }
        }
    }

    private func end() {
        updatePhase(.ended)
        watchTask?.cancel()
        aloneTask?.cancel()
        if let r = room { Task { await r.disconnect() } }
        room = nil
        if BroadcastManager.shared.isBroadcasting { BroadcastManager.shared.requestStop() }
        sharingScreen = false
        linkToken = nil
        live = nil
        recording = nil
        recordingAvailable = false
        recordingSince = nil
        joinConsent = nil
        joinAgain = nil
        lastRecording = nil
        videos = []
        members = []
        connectedAt = nil
        endTask?.cancel()
        endTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: 1_400_000_000) // show "call ended" for a moment
            guard let self, self.phase == .ended else { return }
            self.updatePhase(nil)
            self.call = nil
        }
    }

    // MARK: - real-time

    private func onEvent(_ event: ChatEvent) {
        let data = event.data
        switch event.name {
        case "chat.call.live":
            guard event.str("call_id") == call?.id, let l = data["live"] else { return }
            var next = live ?? LiveDto()
            next.id = l.str("id") ?? ""
            next.token = l.str("token") ?? ""
            next.status = l.str("status") ?? "live"
            next.viewers = l.int("viewers") ?? 0
            next.audience = l.str("audience") ?? "members"
            live = next
        // Asked for, agreed, started, stopped, saved (spec 92).
        case "chat.call.recording":
            guard event.str("call_id") == call?.id else { return }
            showRecording(data["recording"]?.decode(CallRecordingStateDto.self))
        case "chat.call.ringing":
            if let phase, phase != .ended { return } // busy: the caller hears it ring out
            guard let dto = data.decode(CallDto.self) else { return }
            take(dto)
            isVideo = dto.type == "video"
            cameraOn = isVideo
            speakerOn = isVideo
            micOn = true
            let caller = data["caller"]
            title = dto.conversationType == "group" ? (dto.groupName ?? "") : (caller?.str("name") ?? dto.initiator?.name ?? "")
            avatar = caller?.str("avatar")
            peerKey = caller?.str("key")
            updatePhase(.incoming)
        case "chat.call.accepted":
            if call?.id == event.str("call_id"), phase == .outgoing || phase == .connecting {
                updatePhase(.active)
                if connectedAt == nil { connectedAt = Date() }
            }
        case "chat.call.ended", "chat.call.declined":
            guard call?.id == event.str("call_id") else { return }
            if event.name == "chat.call.ended" || event.str("status") == "declined" { end() }
        default: break
        }
    }

    /// Seconds since the call went through — the timer.
    func elapsedSeconds(_ now: Date = Date()) -> Int64 { connectedAt.map { Int64(now.timeIntervalSince($0)) } ?? 0 }
}

extension CallController: RoomDelegate {
    nonisolated func room(_ room: Room, participantDidConnect participant: RemoteParticipant) { changed(alone: true) }
    nonisolated func room(_ room: Room, participantDidDisconnect participant: RemoteParticipant) { changed(alone: true) }
    nonisolated func room(_ room: Room, didUpdateSpeakingParticipants participants: [Participant]) { changed() }
    nonisolated func room(_ room: Room, participant: Participant, trackPublication: TrackPublication, didUpdateIsMuted isMuted: Bool) { changed() }
    nonisolated func room(_ room: Room, participant: RemoteParticipant, didUnsubscribeTrack publication: RemoteTrackPublication) { changed() }
    nonisolated func room(_ room: Room, participant: LocalParticipant, didPublishTrack publication: LocalTrackPublication) { changed() }

    nonisolated func room(_ room: Room, participant: RemoteParticipant, didSubscribeTrack publication: RemoteTrackPublication) {
        changed()
        // Data saver / lite mode: others' video at its lightest layer.
        Task { @MainActor in
            if lightCalls, publication.kind == .video { try? await publication.set(videoQuality: .low) }
        }
    }

    /// The room dropped us (network gone for good, kicked, room closed): the call is over in every phase, not only once
    /// it was answered.
    nonisolated func room(_ room: Room, didDisconnectWithError error: LiveKitError?) {
        Task { @MainActor in
            guard self.room === room, let phase = self.phase, phase != .ended else { return }
            self.hangUp()
        }
    }

    private nonisolated func changed(alone: Bool = false) {
        Task { @MainActor in
            self.refreshVideos()
            if alone { self.watchAlone() }
        }
    }
}

// MARK: - permissions

enum CallPermissions {
    /// The microphone (and the camera, for video) — asked when needed. False when the microphone is refused.
    @MainActor static func ask(video: Bool) async -> Bool {
        let mic = await withCheckedContinuation { c in AVAudioSession.sharedInstance().requestRecordPermission { c.resume(returning: $0) } }
        if video, AVCaptureDevice.authorizationStatus(for: .video) == .notDetermined {
            _ = await AVCaptureDevice.requestAccess(for: .video)
        }
        return mic
    }

    @MainActor static func askCamera() async -> Bool {
        if AVCaptureDevice.authorizationStatus(for: .video) == .authorized { return true }
        return await AVCaptureDevice.requestAccess(for: .video)
    }
}

/// Starts a call after making sure the microphone (and the camera, for video) may be used (androidApp `rememberCallStarter`).
@MainActor func startCall(_ conversationId: String, video: Bool, title: String, avatar: String?, peerKey: String?) {
    Task {
        guard await CallPermissions.ask(video: video) else { return }
        CallController.shared.start(conversationId: conversationId, video: video, title: title, avatar: avatar, peerKey: peerKey)
    }
}

// MARK: - the ringing notification

/// A ringing call's notification (OneSignal shows it while the app is closed): taken away once the call is answered,
/// declined or over.
enum CallNotifications {
    static func cancel(_ callId: String) {
        let center = UNUserNotificationCenter.current()
        center.getDeliveredNotifications { list in
            let ids = list.filter { n in
                let info = n.request.content.userInfo
                let custom = (info["custom"] as? [String: Any])?["a"] as? [String: Any]
                return "\(custom?["call_id"] ?? info["call_id"] ?? "")" == callId
            }
            .map(\.request.identifier)
            if !ids.isEmpty { center.removeDeliveredNotifications(withIdentifiers: ids) }
        }
    }
}

// MARK: - sounds

/// Sounds while the app is open: a ringtone + vibration for an incoming call, and the "ring back" tone for the caller
/// while the other side's phone rings. iOS lets no app play the phone's own ringtone, so both are made here.
@MainActor
final class Ringer {
    static let shared = Ringer()

    private var player: AVAudioPlayer?
    private var vibration: Task<Void, Never>?

    func startIncoming() {
        stop()
        // Solo ambient follows the ring / silent switch, like the phone's own calls.
        try? AVAudioSession.sharedInstance().setCategory(.soloAmbient)
        try? AVAudioSession.sharedInstance().setActive(true)
        play(Self.ringtone)
        vibration = Task {
            while !Task.isCancelled {
                AudioServicesPlaySystemSound(kSystemSoundID_Vibrate)
                try? await Task.sleep(nanoseconds: 1_400_000_000)
            }
        }
    }

    /// Classic ring-back cadence: 1 s tone, 3 s silence.
    func startRingback() {
        stop()
        play(Self.ringback)
    }

    func stop() {
        player?.stop()
        player = nil
        vibration?.cancel()
        vibration = nil
    }

    private func play(_ data: Data) {
        player = try? AVAudioPlayer(data: data)
        player?.numberOfLoops = -1
        player?.volume = 0.8
        player?.play()
    }

    private static let rate = 22_050

    /// 425 Hz for a second, then three seconds of nothing.
    private static let ringback: Data = wav(tones: [(425, 1.0), (0, 3.0)])

    /// A bright two-note chime, three times, then a pause.
    private static let ringtone: Data = wav(tones: [(880, 0.16), (1175, 0.16), (880, 0.16), (1175, 0.16), (0, 0.18),
                                                    (880, 0.16), (1175, 0.16), (880, 0.16), (1175, 0.16), (0, 1.6)])

    /// 16-bit mono PCM in a WAV file, each tone faded in and out so it never clicks.
    private static func wav(tones: [(Double, Double)]) -> Data {
        var samples: [Int16] = []
        for (freq, seconds) in tones {
            let n = Int(Double(rate) * seconds)
            let fade = min(n / 6, rate / 100)
            for i in 0..<n {
                guard freq > 0 else { samples.append(0); continue }
                let env = min(1, Double(min(i, n - i)) / Double(max(fade, 1)))
                let v = sin(2 * .pi * freq * Double(i) / Double(rate)) * 0.55 * env
                samples.append(Int16(v * Double(Int16.max)))
            }
        }
        var d = Data()
        func put<T>(_ v: T) { withUnsafeBytes(of: v) { d.append(contentsOf: $0) } }
        let bytes = samples.count * 2
        d.append(contentsOf: Array("RIFF".utf8)); put(UInt32(36 + bytes).littleEndian)
        d.append(contentsOf: Array("WAVEfmt ".utf8)); put(UInt32(16).littleEndian); put(UInt16(1).littleEndian); put(UInt16(1).littleEndian)
        put(UInt32(rate).littleEndian); put(UInt32(rate * 2).littleEndian); put(UInt16(2).littleEndian); put(UInt16(16).littleEndian)
        d.append(contentsOf: Array("data".utf8)); put(UInt32(bytes).littleEndian)
        samples.forEach { put($0.littleEndian) }
        return d
    }
}

#if DEBUG
extension CallController {
    /// -DorrCall incoming|outgoing (with fixtures): the call screen as it rings, for screenshots — no network.
    func debugShow(_ which: String) {
        call = CallDto(id: "debug", conversationId: "c1", conversationType: "direct", type: "audio", status: "ringing")
        title = "سارة العتيبي"
        peerKey = "user:2"
        recordingAvailable = true
        phase = which == "incoming" ? .incoming : .outgoing
        CallWindow.shared.update()
    }
}
#endif
