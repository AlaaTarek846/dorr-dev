import AVFoundation
import LiveKit
import SwiftUI

// androidApp `rides/RideCall.kt` — the in-app voice call between the rider and the captain of a ride (docs/rides_tasks.md
// O-09; X-13 decided: LiveKit, like the chat's calls), and with a bus captain (O-68). The server rings the other side by
// push and broadcasts each change on the ride; the call itself is a two-person LiveKit room. «Answered» is the other
// person arriving in the room and «over» is them leaving — LiveKit tells us, so nothing asks the server on a timer.
// The ring stops by the phone's own clock after `ringSeconds`.

enum RideCallPhase { case incoming, calling, active, ended }

/// Where to join: the server's call id and its LiveKit room token.
struct RideCallJoin {
    let callUuid: String
    let url: String
    let token: String

    init(_ dto: RideCallStartDto) {
        callUuid = dto.uuid
        url = dto.join.url
        token = dto.join.token
    }
}

@MainActor
final class RideCallSession: NSObject, ObservableObject {
    static let shared = RideCallSession()
    private static let ringSeconds: UInt64 = 45

    @Published private(set) var phase: RideCallPhase?
    @Published private(set) var peerName = ""
    @Published private(set) var muted = false
    @Published private(set) var speaker = false
    @Published private(set) var activeSince: Date?
    @Published var error: String?

    private(set) var rideUuid: String?
    private(set) var callUuid: String?
    /// O-68: how to answer a call that isn't a ride's (a bus ticket's) — nil: the ride's own.
    private(set) var acceptApi: ((String, String) async throws -> RideCallJoin)?

    private var room: Room?
    private var ringTask: Task<Void, Never>?
    private var endApi: ((String, String) async throws -> Void)?

    /// I call: the server makes the room and rings them; I wait in the room until they arrive.
    func call(ride: String, peer: String, start: @escaping () async throws -> RideCallJoin, end: @escaping (String, String) async throws -> Void) {
        if phase != nil { return }
        reset(ride: ride, peer: peer, end: end)
        phase = .calling
        Task {
            do {
                let join = try await start()
                callUuid = join.callUuid
                ringFor(.calling)
                await connect(join)
            } catch {
                self.error = error.apiFailure.message ?? error.localizedDescription
                finish()
            }
        }
    }

    /// They call me: ring until I answer, decline, or the ring runs out.
    func incoming(ride: String, call: String, peer: String, end: @escaping (String, String) async throws -> Void,
                  accept: ((String, String) async throws -> RideCallJoin)? = nil) {
        if phase != nil || callUuid == call { return }
        reset(ride: ride, peer: peer, end: end)
        acceptApi = accept
        callUuid = call
        phase = .incoming
        Ringer.shared.startIncoming()
        ringFor(.incoming)
    }

    func accept(_ accept: @escaping () async throws -> RideCallJoin) {
        guard phase == .incoming else { return }
        Ringer.shared.stop()
        Task {
            do {
                let join = try await accept()
                await connect(join)
                markActive()
            } catch {
                self.error = error.apiFailure.message ?? error.localizedDescription
                finish()
            }
        }
    }

    /// Decline, cancel my ring, or hang up — the server hears it and tells the other side.
    func hangUp() {
        if let ride = rideUuid, let call = callUuid, let end = endApi { Task { try? await end(ride, call) } }
        finish()
    }

    /// The server says the call is over (they hung up / declined, or the ride moved on).
    func endedElsewhere(_ call: String?) {
        if let phase, phase != .ended, call == nil || call == callUuid { finish() }
    }

    func toggleMute() {
        muted.toggle()
        Task { _ = try? await room?.localParticipant.setMicrophone(enabled: !muted) }
    }

    func toggleSpeaker() {
        speaker.toggle()
        applySpeaker()
    }

    private func reset(ride: String, peer: String, end: @escaping (String, String) async throws -> Void) {
        rideUuid = ride
        callUuid = nil
        peerName = peer
        muted = false
        speaker = false
        activeSince = nil
        error = nil
        endApi = end
        acceptApi = nil
    }

    private func ringFor(_ during: RideCallPhase) {
        ringTask?.cancel()
        ringTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: Self.ringSeconds * 1_000_000_000)
            guard let self, !Task.isCancelled, self.phase == during else { return }
            self.hangUp()
        }
    }

    private func connect(_ join: RideCallJoin) async {
        let r = room ?? Room(delegate: self)
        room = r
        do {
            try await r.connect(url: join.url, token: join.token)
            _ = try? await r.localParticipant.setMicrophone(enabled: !muted)
            applySpeaker()
            if !r.remoteParticipants.isEmpty { markActive() }
        } catch {
            self.error = error.localizedDescription
            hangUp()
        }
    }

    private func markActive() {
        guard phase != .active else { return }
        ringTask?.cancel()
        Ringer.shared.stop()
        phase = .active
        activeSince = Date()
    }

    /// The loudspeaker, or else a headset / the earpiece — LiveKit's audio session follows it.
    private func applySpeaker() { AudioManager.shared.isSpeakerOutputPreferred = speaker }

    private func finish() {
        ringTask?.cancel()
        Ringer.shared.stop()
        if let r = room { Task { await r.disconnect() } }
        room = nil
        phase = .ended
        Task { [weak self] in
            try? await Task.sleep(nanoseconds: 1_500_000_000)
            guard let self, self.phase == .ended else { return }
            self.phase = nil
            self.callUuid = nil
        }
    }
}

extension RideCallSession: RoomDelegate {
    /// They're in the room: the call is answered.
    nonisolated func room(_ room: Room, participantDidConnect participant: RemoteParticipant) {
        Task { @MainActor in if self.room === room { self.markActive() } }
    }

    /// They left: the call is over.
    nonisolated func room(_ room: Room, participantDidDisconnect participant: RemoteParticipant) {
        Task { @MainActor in if self.room === room, self.phase == .active { self.hangUp() } }
    }

    nonisolated func room(_ room: Room, didDisconnectWithError error: LiveKitError?) {
        Task { @MainActor in if self.room === room, let phase = self.phase, phase != .ended { self.finish() } }
    }
}

/// The call screen over everything: who, the state, the time; mute, speaker, hang up — or answer / decline.
struct RideCallOverlay: View {
    @ObservedObject private var session = RideCallSession.shared
    let onAccept: () -> Void
    @State private var pulse = false

    var body: some View {
        if let phase = session.phase {
            ZStack {
                LinearGradient(colors: [Color(hex: 0x001B53), Color(hex: 0x0B2E7A), Color(hex: 0x12224A)], startPoint: .top, endPoint: .bottom).ignoresSafeArea()
                VStack(spacing: 0) {
                    Spacer().frame(height: 48)
                    ZStack {
                        if phase == .incoming || phase == .calling {
                            TimelineView(.animation) { ctx in
                                let t = ctx.date.timeIntervalSinceReferenceDate.truncatingRemainder(dividingBy: 1.8) / 1.8
                                ZStack {
                                    ForEach(0..<3, id: \.self) { i in
                                        let f = (t + Double(i) / 3).truncatingRemainder(dividingBy: 1)
                                        Circle().stroke(Color.white.opacity((1 - f) * 0.35), lineWidth: 2)
                                            .frame(width: 180 * (0.45 + f * 0.55), height: 180 * (0.45 + f * 0.55))
                                    }
                                }
                            }
                        }
                        Text(verbatim: String(session.peerName.prefix(1)).isEmpty ? "?" : String(session.peerName.prefix(1)))
                            .font(.dorr(40, .black)).foregroundStyle(.white)
                            .frame(width: 96, height: 96).background(Color.white.opacity(0.16), in: Circle())
                    }
                    .frame(width: 180, height: 180)
                    Text(verbatim: session.peerName).font(.dorr(26, .black)).foregroundStyle(.white).padding(.top, 18)
                    status(phase).font(.dorr(15)).foregroundStyle(.white.opacity(0.8)).padding(.top, 6)
                    Text(L("rc_private_note")).font(.dorr(12)).foregroundStyle(.white.opacity(0.5)).padding(.top, 4)
                    Spacer()
                    switch phase {
                    case .incoming:
                        HStack {
                            Spacer()
                            button("phone.down.fill", L("rc_decline"), Color(hex: 0xE53935)) { session.hangUp() }
                            Spacer()
                            button("phone.fill", L("rc_answer"), Color(hex: 0x2E7D32)) {
                                Task { if await CallPermissions.ask(video: false) { onAccept() } }
                            }
                            Spacer()
                        }
                    case .ended:
                        EmptyView()
                    default:
                        HStack {
                            Spacer()
                            button(session.muted ? "mic.slash.fill" : "mic.fill", L("rc_mute"), session.muted ? .white : .white.opacity(0.18), dark: session.muted) { session.toggleMute() }
                            Spacer()
                            button("phone.down.fill", L("rc_hang_up"), Color(hex: 0xE53935)) { session.hangUp() }
                            Spacer()
                            button("speaker.wave.2.fill", L("rc_speaker"), session.speaker ? .white : .white.opacity(0.18), dark: session.speaker) { session.toggleSpeaker() }
                            Spacer()
                        }
                    }
                    Spacer().frame(height: 24)
                }
                .padding(24)
            }
            .transition(.opacity)
        }
    }

    @ViewBuilder
    private func status(_ phase: RideCallPhase) -> some View {
        switch phase {
        case .incoming: Text(L("rc_incoming"))
        case .calling: Text(L("rc_calling"))
        case .ended: Text(verbatim: session.error ?? L("rc_ended"))
        case .active:
            TimelineView(.periodic(from: .now, by: 1)) { ctx in
                let s = Int(ctx.date.timeIntervalSince(session.activeSince ?? ctx.date))
                Text(verbatim: String(format: "%d:%02d", s / 60, s % 60)).environment(\.layoutDirection, .leftToRight)
            }
        }
    }

    private func button(_ icon: String, _ label: String, _ bg: Color, dark: Bool = false, action: @escaping () -> Void) -> some View {
        VStack(spacing: 6) {
            Button(action: action) {
                Image(systemName: icon).font(.system(size: 28)).foregroundStyle(dark ? Color(hex: 0x001B53) : .white)
                    .frame(width: 68, height: 68).background(bg, in: Circle())
            }
            .buttonStyle(.plain)
            Text(verbatim: label).font(.dorr(12)).foregroundStyle(.white.opacity(0.85))
        }
    }
}

/// The server answered without a room to join.
struct NoCallJoin: Error {}

/// O-09: call the ride's captain inside the app.
@MainActor func startRideCall(_ ride: RideDto) {
    RideCallSession.shared.call(
        ride: ride.uuid, peer: ride.driver?.name ?? "",
        start: { guard let dto = try await RidesApi.startCall(ride.uuid).data else { throw NoCallJoin() }; return RideCallJoin(dto) },
        end: { r, c in _ = try await RidesApi.endCall(r, c) }
    )
}
