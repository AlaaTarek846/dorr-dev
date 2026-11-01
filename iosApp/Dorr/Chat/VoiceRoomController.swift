import Combine
import Foundation
import LiveKit

// androidApp `chat/VoiceRoomController.kt` — the standing voice room I'm in (spec 91), one at a time, on LiveKit like
// calls. It stays on while I move around the chat (the mini bar), sends a heartbeat every 30 s (the server sweeps the
// silent), follows `chat.voice.updated` (a host let me speak, put me out…) and the room's own speaking events (who's
// talking, glowing). The `audio` background mode keeps it going when I leave the app (Android: VoiceRoomService).

@MainActor
final class VoiceRoomController: NSObject, ObservableObject {
    static let shared = VoiceRoomController()

    private var livekit: Room?
    private var pingTask: Task<Void, Never>?
    private var events: AnyCancellable?

    /// The room I'm in (nil: none).
    @Published private(set) var room: VoiceRoomDto?
    @Published private(set) var chatTitle: String?
    @Published private(set) var connecting = false
    @Published private(set) var micOn = false
    /// The loudspeaker (off: the earpiece, or a headset when one is on).
    @Published private(set) var speakerOn = false
    /// Who is talking right now (participant keys).
    @Published private(set) var speaking: Set<String> = []
    @Published var error: String?

    func attach() {
        guard events == nil else { return }
        events = ChatRealtime.shared.events.receive(on: DispatchQueue.main).sink { [weak self] e in
            if e.name == "chat.voice.updated" { self?.onUpdated(e) }
        }
    }

    func isIn(_ conversationId: String) -> Bool { room?.conversationId == conversationId }

    func join(_ conversationId: String, title: String?) async -> Bool {
        attach()
        if CallController.shared.phase != nil {
            error = L("vr_in_call")
            return false
        }
        if isIn(conversationId) { return true }
        leave()
        connecting = true
        let joined: VoiceJoinDto
        do {
            guard let j = try await VoiceApi.join(conversationId).data else {
                connecting = false
                return false
            }
            joined = j
        } catch {
            self.error = error.apiFailure.message ?? error.localizedDescription
            connecting = false
            return false
        }
        room = joined.room
        chatTitle = title
        let r = Room(delegate: self)
        livekit = r
        do {
            try await r.connect(url: joined.join.url, token: joined.join.token)
            // A speaker comes in muted — they unmute themselves.
            micOn = false
            applySpeaker()
            pingTask = Task { [weak self] in
                while !Task.isCancelled {
                    try? await Task.sleep(nanoseconds: 30_000_000_000)
                    guard !Task.isCancelled, let id = self?.room?.id else { return }
                    _ = try? await VoiceApi.ping(id)
                }
            }
            connecting = false
            return true
        } catch {
            self.error = error.localizedDescription
            leave()
            return false
        }
    }

    func toggleMic() {
        guard let r = livekit, room?.me?.role != "listener" else { return }
        Task {
            _ = try? await r.localParticipant.setMicrophone(enabled: !micOn)
            micOn = r.localParticipant.isMicrophoneEnabled()
        }
    }

    func toggleSpeaker() {
        speakerOn.toggle()
        applySpeaker()
    }

    /// LiveKit routes the room's sound itself, like calls: the loudspeaker, or a headset / the earpiece.
    private func applySpeaker() { AudioManager.shared.isSpeakerOutputPreferred = speakerOn }

    func raiseHand(_ raised: Bool) {
        guard let id = room?.id else { return }
        Task { if let fresh = try? await VoiceApi.hand(id, raised: raised).data { room = fresh } }
    }

    func setRole(_ participantId: Int, _ role: String) {
        guard let id = room?.id else { return }
        Task { if let fresh = try? await VoiceApi.role(id, participant: participantId, role: role).data { room = fresh } }
    }

    func remove(_ participantId: Int) {
        guard let id = room?.id else { return }
        Task { if let fresh = try? await VoiceApi.remove(id, participant: participantId).data { room = fresh } }
    }

    func setPolicy(_ policy: String) {
        guard let id = room?.id else { return }
        Task { if let fresh = try? await VoiceApi.update(id, speakPolicy: policy).data { room = fresh } }
    }

    func leave() {
        let id = room?.id
        clear()
        if let id { Task { _ = try? await VoiceApi.leave(id) } }
    }

    private func clear() {
        pingTask?.cancel()
        if let r = livekit { Task { await r.disconnect() } }
        livekit = nil
        room = nil
        micOn = false
        speaking = []
        connecting = false
    }

    /// Someone came, left, raised a hand, or a host changed my role: read the room again.
    private func onUpdated(_ e: ChatEvent) {
        guard let current = room, e.str("room_id") == current.id else { return }
        Task {
            guard let fresh = try? await VoiceApi.room(current.conversationId).data else { return }
            // Put out by a host.
            if fresh.me?.inRoom != true {
                clear()
                return
            }
            // Made a listener: the microphone goes off (LiveKit took the right away already).
            if fresh.me?.role == "listener" && micOn {
                _ = try? await livekit?.localParticipant.setMicrophone(enabled: false)
                micOn = false
            }
            room = fresh
        }
    }
}

extension VoiceRoomController: RoomDelegate {
    nonisolated func room(_ room: Room, didUpdateSpeakingParticipants participants: [Participant]) {
        let keys = Set(participants.compactMap { $0.identity?.stringValue })
        Task { @MainActor in if self.livekit === room { self.speaking = keys } }
    }

    nonisolated func room(_ room: Room, didDisconnectWithError error: LiveKitError?) {
        Task { @MainActor in if self.livekit === room, self.room != nil { self.clear() } }
    }
}
