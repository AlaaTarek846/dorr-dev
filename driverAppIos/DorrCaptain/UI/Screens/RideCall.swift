import LiveKit
import SwiftUI

// driverApp `ui/RideCall.kt` — its LiveKit half: the two-person room of the in-app call with the rider (Android io.livekit
// 2.5.0 → LiveKit Swift 2.5.0). Everything else (the session, the call screen, startRideCall) is in `RideCallSession.swift`,
// which builds without the SDK; it reaches the room only through `RideCallLiveKit`.

@MainActor
enum RideCallLiveKit {
    /// Joins the room (made once per call, kept in `session.room`) with the microphone on or off; true when the other person
    /// is already in it (the call is answered).
    static func connect(_ session: RideCallSession, url: String, token: String, microphone: Bool) async throws -> Bool {
        let room = (session.room as? Room) ?? Room(delegate: session)
        session.room = room
        try await room.connect(url: url, token: token)
        _ = try? await room.localParticipant.setMicrophone(enabled: microphone)
        return !room.remoteParticipants.isEmpty
    }

    static func setMicrophone(_ session: RideCallSession, enabled: Bool) async {
        _ = try? await (session.room as? Room)?.localParticipant.setMicrophone(enabled: enabled)
    }

    /// The loudspeaker, or else a headset / the earpiece — LiveKit's audio session follows it (Android's AudioSwitchHandler).
    static func applySpeaker(_ on: Bool) {
        AudioManager.shared.isSpeakerOutputPreferred = on
    }

    static func disconnect(_ session: RideCallSession) {
        guard let room = session.room as? Room else { return }
        Task { await room.disconnect() }
    }
}

extension RideCallSession: RoomDelegate {
    /// They're in the room: the call is answered.
    nonisolated func room(_ room: Room, participantDidConnect participant: RemoteParticipant) {
        Task { @MainActor in if self.room === room { self.peerJoined() } }
    }

    /// They left: the call is over.
    nonisolated func room(_ room: Room, participantDidDisconnect participant: RemoteParticipant) {
        Task { @MainActor in if self.room === room { self.peerLeft() } }
    }

    nonisolated func room(_ room: Room, didDisconnectWithError error: LiveKitError?) {
        Task { @MainActor in if self.room === room { self.roomClosed() } }
    }
}
