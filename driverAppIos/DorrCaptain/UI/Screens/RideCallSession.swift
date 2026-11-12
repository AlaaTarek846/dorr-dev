import AVFoundation
import SwiftUI

// driverApp `ui/RideCall.kt` (its SDK-free half; the LiveKit room is in `RideCall.swift`, like DriverLive / DriverLivePusher)
// — the in-app voice call between the rider and the captain of a ride (docs/rides_tasks.md O-09; X-13 decided: LiveKit,
// like the chat's calls). The server rings the other side by push and broadcasts each change on the ride; the call itself
// is a two-person LiveKit room. «Answered» is the other person arriving in the room and «over» is them leaving — LiveKit
// tells us, so nothing asks the server on a timer. The ring stops by the phone's own clock after `ringSeconds`.

enum RideCallPhase { case incoming, calling, active, ended }

/// Where to join: the server's call id and its LiveKit room token.
struct RideCallJoin {
    let callUuid: String
    let url: String
    let token: String
}

/// The server's refusal (or "offline") as the call's error.
struct RideCallError: Error {
    let message: String
}

@MainActor
final class RideCallSession: NSObject, ObservableObject {
    static let shared = RideCallSession()
    private static let ringSeconds: UInt64 = 45

    @Published private(set) var phase: RideCallPhase?
    @Published private(set) var peerName = ""
    /// The other side is a verified Dorr account: the tick after their name on the call screen (AV-028).
    @Published private(set) var peerVerified = false
    @Published private(set) var muted = false
    @Published private(set) var speaker = false
    @Published private(set) var activeSince: Date?
    @Published var error: String?

    private(set) var rideUuid: String?
    private(set) var callUuid: String?

    /// The LiveKit room (`RideCallLiveKit`), kept untyped so this file builds without the SDK.
    var room: AnyObject?
    private var ringTask: Task<Void, Never>?
    private var endApi: ((String, String) async -> Void)?
    private var ringer: AVAudioPlayer?

    /// I call: the server makes the room and rings them; I wait in the room until they arrive.
    func call(ride: String, peer: String, start: @escaping () async throws -> RideCallJoin,
              end: @escaping (String, String) async -> Void, peerVerified: Bool = false) {
        if phase != nil { return }
        reset(ride: ride, peer: peer, end: end, verified: peerVerified)
        phase = .calling
        Task {
            do {
                let join = try await start()
                callUuid = join.callUuid
                ringFor(.calling)
                await connect(join)
            } catch {
                self.error = (error as? RideCallError)?.message ?? error.localizedDescription
                finish()
            }
        }
    }

    /// They call me: ring until I answer, decline, or the ring runs out.
    func incoming(ride: String, call: String, peer: String, end: @escaping (String, String) async -> Void, peerVerified: Bool = false) {
        if phase != nil || callUuid == call { return }
        reset(ride: ride, peer: peer, end: end, verified: peerVerified)
        callUuid = call
        phase = .incoming
        startRinging()
        ringFor(.incoming)
    }

    func accept(_ accept: @escaping () async throws -> RideCallJoin) {
        guard phase == .incoming else { return }
        stopRinging()
        Task {
            do {
                let join = try await accept()
                await connect(join)
                markActive()
            } catch {
                self.error = (error as? RideCallError)?.message ?? error.localizedDescription
                finish()
            }
        }
    }

    /// Decline, cancel my ring, or hang up — the server hears it and tells the other side.
    func hangUp() {
        if let ride = rideUuid, let call = callUuid, let end = endApi { Task { await end(ride, call) } }
        finish()
    }

    /// The server says the call is over (they hung up / declined, or the ride moved on).
    func endedElsewhere(_ call: String?) {
        if let phase, phase != .ended, call == nil || call == callUuid { finish() }
    }

    func toggleMute() {
        muted.toggle()
        let on = !muted
        Task { await RideCallLiveKit.setMicrophone(self, enabled: on) }
    }

    func toggleSpeaker() {
        speaker.toggle()
        applySpeaker()
    }

    private func reset(ride: String, peer: String, end: @escaping (String, String) async -> Void, verified: Bool) {
        rideUuid = ride
        callUuid = nil
        peerName = peer
        peerVerified = verified
        muted = false
        speaker = false
        activeSince = nil
        error = nil
        endApi = end
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
        do {
            let someoneThere = try await RideCallLiveKit.connect(self, url: join.url, token: join.token, microphone: !muted)
            applySpeaker()
            if someoneThere { markActive() }
        } catch {
            self.error = error.localizedDescription
            hangUp()
        }
    }

    // MARK: the room's events (called by `RideCallLiveKit`, for the room in `room` only)

    /// They're in the room: the call is answered.
    func peerJoined() { markActive() }

    /// They left: the call is over.
    func peerLeft() { if phase == .active { hangUp() } }

    /// The room closed.
    func roomClosed() { if let phase, phase != .ended { finish() } }

    private func markActive() {
        guard phase != .active else { return }
        ringTask?.cancel()
        stopRinging()
        phase = .active
        activeSince = Date()
    }

    /// The loudspeaker, or else a headset / the earpiece.
    private func applySpeaker() { RideCallLiveKit.applySpeaker(speaker) }

    /// Android plays the phone's ringtone (`RingtoneManager.TYPE_RINGTONE`); iOS lets no app use it, so a ring tone of
    /// our own, looping, following the silent switch like the phone's.
    private func startRinging() {
        stopRinging()
        try? AVAudioSession.sharedInstance().setCategory(.soloAmbient)
        try? AVAudioSession.sharedInstance().setActive(true)
        ringer = try? AVAudioPlayer(data: Self.ringtone)
        ringer?.numberOfLoops = -1
        ringer?.volume = 0.8
        ringer?.play()
    }

    private func stopRinging() {
        ringer?.stop()
        ringer = nil
    }

    private func finish() {
        ringTask?.cancel()
        stopRinging()
        RideCallLiveKit.disconnect(self)
        room = nil
        phase = .ended
        Task { [weak self] in
            try? await Task.sleep(nanoseconds: 1_500_000_000)
            guard let self, self.phase == .ended else { return }
            self.phase = nil
            self.callUuid = nil
        }
    }

    private static let rate = 22_050

    /// A bright two-note chime, three times, then a pause (the same tone the DORR app rings with).
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

/// Android's `RECORD_AUDIO` check + request: true when the microphone may be used (asked once, when needed).
@MainActor
func askMicrophone() async -> Bool {
    switch AVAudioSession.sharedInstance().recordPermission {
    case .granted: return true
    case .denied: return false
    default: return await withCheckedContinuation { c in AVAudioSession.sharedInstance().requestRecordPermission { c.resume(returning: $0) } }
    }
}

/// The call screen over everything: who, the state, the time; mute, speaker, hang up — or answer / decline.
struct RideCallOverlay: View {
    @ObservedObject private var session = RideCallSession.shared
    let onAccept: () -> Void

    init(onAccept: @escaping () -> Void) { self.onAccept = onAccept }

    var body: some View {
        if let phase = session.phase {
            ZStack {
                LinearGradient(colors: [Color(hex: 0x001B53), Color(hex: 0x0B2E7A), Color(hex: 0x12224A)], startPoint: .top, endPoint: .bottom)
                    .ignoresSafeArea()
                    .contentShape(Rectangle())
                    .onTapGesture {}
                VStack(spacing: 0) {
                    Spacer().frame(height: 48)
                    ZStack {
                        if phase == .incoming || phase == .calling {
                            // Three rings spreading out, 1.8 s a turn.
                            TimelineView(.animation) { ctx in
                                let p = ctx.date.timeIntervalSinceReferenceDate.truncatingRemainder(dividingBy: 1.8) / 1.8
                                ZStack {
                                    ForEach(0..<3, id: \.self) { i in
                                        let f = (p + Double(i) / 3).truncatingRemainder(dividingBy: 1)
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
                    Spacer().frame(height: 18)
                    HStack(spacing: 0) {
                        Text(verbatim: session.peerName).font(.dorr(26, .black)).foregroundStyle(.white).lineLimit(1)
                        RiderTick(verified: session.peerVerified, size: 24, tint: .white)
                    }
                    Spacer().frame(height: 6)
                    status(phase).font(.dorr(15)).foregroundStyle(.white.opacity(0.8))
                    Text(L("rc_private_note")).font(.dorr(12)).foregroundStyle(.white.opacity(0.5)).padding(.top, 4)
                    Spacer()
                    switch phase {
                    case .incoming:
                        HStack {
                            Spacer()
                            callButton("phone.down.fill", L("rc_decline"), Color(hex: 0xE53935)) { session.hangUp() }
                            Spacer()
                            callButton("phone.fill", L("rc_answer"), Color(hex: 0x2E7D32)) {
                                Task { if await askMicrophone() { onAccept() } }
                            }
                            Spacer()
                        }
                    case .ended:
                        EmptyView()
                    default:
                        HStack {
                            Spacer()
                            callButton(session.muted ? "mic.slash.fill" : "mic.fill", L("rc_mute"), session.muted ? .white : .white.opacity(0.18), dark: session.muted) { session.toggleMute() }
                            Spacer()
                            callButton("phone.down.fill", L("rc_hang_up"), Color(hex: 0xE53935)) { session.hangUp() }
                            Spacer()
                            callButton("speaker.wave.2.fill", L("rc_speaker"), session.speaker ? .white : .white.opacity(0.18), dark: session.speaker) { session.toggleSpeaker() }
                            Spacer()
                        }
                    }
                    Spacer().frame(height: 24)
                }
                .padding(24)
            }
        }
    }

    @ViewBuilder
    private func status(_ phase: RideCallPhase) -> some View {
        switch phase {
        case .incoming: Text(L("rc_incoming"))
        case .calling: Text(L("rc_calling"))
        case .ended: Text(verbatim: session.error ?? L("rc_ended"))
        case .active:
            // The call's time by the phone's clock, each second.
            TimelineView(.periodic(from: .now, by: 1)) { ctx in
                if let since = session.activeSince {
                    let s = Swift.max(0, Int(ctx.date.timeIntervalSince(since)))
                    Text(verbatim: String(format: "%d:%02d", s / 60, s % 60)).environment(\.layoutDirection, .leftToRight)
                } else {
                    Text(verbatim: "")
                }
            }
        }
    }

    private func callButton(_ icon: String, _ label: String, _ bg: Color, dark: Bool = false, action: @escaping () -> Void) -> some View {
        VStack(spacing: 6) {
            Button(action: action) {
                Image(systemName: icon).font(.system(size: 28)).foregroundStyle(dark ? Color(hex: 0x001B53) : .white)
                    .frame(width: 68, height: 68).background(bg, in: Circle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel(label)
            Text(verbatim: label).font(.dorr(12)).foregroundStyle(.white.opacity(0.85))
        }
    }
}

/// O-09: call the ride's rider inside the app (the microphone asked first, as Android's `RECORD_AUDIO`).
@MainActor
func startRideCall(_ trip: TripDto) {
    let uuid = trip.uuid ?? ""
    RideCallSession.shared.call(
        ride: uuid, peer: trip.rider?.name ?? "",
        start: { try callJoin(await DriverApi.startCall(uuid: uuid)) },
        end: { r, c in _ = await DriverApi.endCall(uuid: r, call: c) },
        peerVerified: trip.rider?.isVerified == true
    )
}

/// The server's answer as a room to join — or its message as the error.
func callJoin(_ r: ApiResult<TripCallStartDto>) throws -> RideCallJoin {
    switch r {
    case let .ok(dto, _): return RideCallJoin(callUuid: dto.uuid ?? "", url: dto.join?.url ?? "", token: dto.join?.token ?? "")
    case let .failed(e): throw RideCallError(message: e.displayMessage)
    case .offline: throw RideCallError(message: "offline")
    }
}
