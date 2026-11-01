import AVFoundation
import SwiftUI
import LiveKitWebRTC

// androidApp `voice/RealtimeVoiceClient.kt` and `aichat/AiVoiceScreen.kt` — the live voice call with DORR AI: a session
// minted by our server (`user/v1/ai-realtime/session`), then a direct WebRTC call (the WebRTC LiveKit ships, like Android) to the provider's own edge (a raw
// SDP offer posted with the short-lived client secret — never the real key), the live transcript and a speaking
// indicator from the data channel, mute, and the call's length reported back on hang-up for billing.

enum RealtimeVoiceState { case idle, connecting, connected, ended, failed }

/// One instance per call; not reused.
@MainActor
final class RealtimeVoiceClient: NSObject, ObservableObject {
    @Published private(set) var state = RealtimeVoiceState.idle
    @Published private(set) var muted = false
    @Published private(set) var assistantSpeaking = false
    @Published private(set) var transcript = ""
    @Published private(set) var errorMessage: String?

    private static let factory: LKRTCPeerConnectionFactory = {
        RTCInitializeSSL()
        return LKRTCPeerConnectionFactory(encoderFactory: LKRTCDefaultVideoEncoderFactory(), decoderFactory: LKRTCDefaultVideoDecoderFactory())
    }()

    private var peer: LKRTCPeerConnection?
    private var localAudio: LKRTCAudioTrack?
    private var dataChannel: LKRTCDataChannel?
    private var ended = false

    /// Opens the call with what `ai-realtime/session` returned; never talks to our own server.
    func connect(clientSecret: String, webrtcEndpoint: String) {
        guard state == .idle else { return }
        state = .connecting
        errorMessage = nil
        routeAudioForCall()

        // Non-trickle: the provider answers with a complete SDP (candidates bundled), so no ICE servers of our own.
        let config = LKRTCConfiguration()
        config.sdpSemantics = .unifiedPlan
        config.continualGatheringPolicy = .gatherOnce
        let constraints = LKRTCMediaConstraints(mandatoryConstraints: nil, optionalConstraints: nil)
        guard let pc = Self.factory.peerConnection(with: config, constraints: constraints, delegate: self) else {
            failWith("تعذر إنشاء اتصال WebRTC")
            return
        }
        peer = pc
        let source = Self.factory.audioSource(with: constraints)
        let track = Self.factory.audioTrack(with: source, trackId: "mic0")
        track.isEnabled = true
        localAudio = track
        pc.add(track, streamIds: ["dorr-ai-voice"])
        let dc = pc.dataChannel(forLabel: "oai-events", configuration: LKRTCDataChannelConfiguration())
        dc?.delegate = self
        dataChannel = dc

        pc.offer(for: constraints) { [weak self] offer, error in
            Task { @MainActor in
                guard let self else { return }
                guard let offer else { self.failWith(error?.localizedDescription ?? "فشل إنشاء عرض الاتصال"); return }
                pc.setLocalDescription(offer) { error in
                    Task { @MainActor in
                        if let error { self.failWith(error.localizedDescription); return }
                        await self.exchangeSdp(offer.sdp, clientSecret: clientSecret, endpoint: webrtcEndpoint, pc: pc)
                    }
                }
            }
        }
    }

    private func exchangeSdp(_ sdp: String, clientSecret: String, endpoint: String, pc: LKRTCPeerConnection) async {
        guard let url = URL(string: endpoint) else { failWith("تعذر الوصول لخادم OpenAI"); return }
        // Straight to the provider — none of our server's headers.
        var request = URLRequest(url: url, timeoutInterval: 20)
        request.httpMethod = "POST"
        request.setValue("Bearer \(clientSecret)", forHTTPHeaderField: "Authorization")
        request.setValue("application/sdp", forHTTPHeaderField: "Content-Type")
        request.httpBody = Data(sdp.utf8)
        do {
            let (data, response) = try await URLSession.shared.data(for: request)
            let code = (response as? HTTPURLResponse)?.statusCode ?? 0
            let answer = String(data: data, encoding: .utf8) ?? ""
            guard (200..<300).contains(code), !answer.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
                failWith("رفض OpenAI الاتصال (\(code))")
                return
            }
            pc.setRemoteDescription(LKRTCSessionDescription(type: .answer, sdp: answer)) { [weak self] error in
                // ICE / DTLS finish on their own; the connection-state callback flips to connected.
                if let error { Task { @MainActor in self?.failWith(error.localizedDescription) } }
            }
        } catch {
            failWith(error.localizedDescription)
        }
    }

    /// Only the few provider events this screen shows (the live transcript and «speaking»); others are ignored.
    fileprivate func handle(_ json: String) {
        guard let obj = (try? JSONSerialization.jsonObject(with: Data(json.utf8))) as? [String: Any] else { return }
        switch obj["type"] as? String {
        case "response.audio_transcript.delta", "response.output_audio_transcript.delta":
            transcript += obj["delta"] as? String ?? ""
            assistantSpeaking = true
        case "response.audio_transcript.done", "response.output_audio_transcript.done", "response.done", "response.audio.done", "response.output_audio.done":
            assistantSpeaking = false
        case "input_audio_buffer.speech_started":
            transcript = ""
        case "error":
            if let m = (obj["error"] as? [String: Any])?["message"] as? String, !m.isEmpty { errorMessage = m }
        default: break
        }
    }

    fileprivate func iceChanged(_ s: RTCIceConnectionState) {
        switch s {
        case .connected, .completed: if state == .connecting { state = .connected }
        case .failed: failWith("انقطع الاتصال بالمكالمة الصوتية")
        case .disconnected, .closed: if !ended && state == .connected { failWith("انقطع الاتصال بالمكالمة الصوتية") }
        default: break
        }
    }

    func toggleMute() {
        muted.toggle()
        localAudio?.isEnabled = !muted
    }

    private func failWith(_ message: String) {
        if ended { return }
        state = .failed
        errorMessage = message
    }

    private func routeAudioForCall() {
        let session = LKRTCAudioSession.sharedInstance()
        session.lockForConfiguration()
        try? session.setCategory(AVAudioSession.Category.playAndRecord, with: [.defaultToSpeaker, .allowBluetooth])
        try? session.setMode(AVAudioSession.Mode.voiceChat)
        try? session.setActive(true)
        session.unlockForConfiguration()
    }

    /// Ends the call and lets go of everything. Safe to call more than once.
    func disconnect() {
        if ended { return }
        ended = true
        state = .ended
        dataChannel?.close()
        dataChannel = nil
        localAudio?.isEnabled = false
        localAudio = nil
        peer?.close()
        peer = nil
        let session = LKRTCAudioSession.sharedInstance()
        session.lockForConfiguration()
        try? session.setActive(false)
        session.unlockForConfiguration()
    }
}

extension RealtimeVoiceClient: LKRTCPeerConnectionDelegate, LKRTCDataChannelDelegate {
    nonisolated func peerConnection(_ peerConnection: LKRTCPeerConnection, didChange stateChanged: RTCSignalingState) {}
    nonisolated func peerConnection(_ peerConnection: LKRTCPeerConnection, didAdd stream: LKRTCMediaStream) {}
    nonisolated func peerConnection(_ peerConnection: LKRTCPeerConnection, didRemove stream: LKRTCMediaStream) {}
    nonisolated func peerConnectionShouldNegotiate(_ peerConnection: LKRTCPeerConnection) {}
    nonisolated func peerConnection(_ peerConnection: LKRTCPeerConnection, didChange newState: RTCIceConnectionState) {
        Task { @MainActor in self.iceChanged(newState) }
    }
    nonisolated func peerConnection(_ peerConnection: LKRTCPeerConnection, didChange newState: RTCIceGatheringState) {}
    nonisolated func peerConnection(_ peerConnection: LKRTCPeerConnection, didGenerate candidate: LKRTCIceCandidate) {}
    nonisolated func peerConnection(_ peerConnection: LKRTCPeerConnection, didRemove candidates: [LKRTCIceCandidate]) {}
    nonisolated func peerConnection(_ peerConnection: LKRTCPeerConnection, didOpen dataChannel: LKRTCDataChannel) {}

    nonisolated func dataChannelDidChangeState(_ dataChannel: LKRTCDataChannel) {}
    nonisolated func dataChannel(_ dataChannel: LKRTCDataChannel, didReceiveMessageWith buffer: LKRTCDataBuffer) {
        guard let text = String(data: buffer.data, encoding: .utf8) else { return }
        Task { @MainActor in self.handle(text) }
    }
}

// MARK: - The call screen

struct AiVoiceScreen: View {
    @Environment(\.wa) private var wa
    let conversationId: Int?
    let onExit: () -> Void
    @StateObject private var holder = VoiceClientHolder()
    @State private var sessionId: Int?
    @State private var startingUp = true
    @State private var setupError: String?
    @State private var connectedAt: Date?
    @State private var permissionDenied = false
    @State private var endedByUser = false
    @State private var pulse = false

    private var client: RealtimeVoiceClient { holder.client }

    private func elapsed(_ now: Date = Date()) -> Int { connectedAt.map { Int(now.timeIntervalSince($0)) } ?? 0 }

    private func startSession() async {
        startingUp = true
        setupError = nil
        if client.state != .idle { holder.renew() }
        do {
            let envelope = try await AiChatApi.createRealtimeSession(conversationId: conversationId)
            if let data = envelope.data {
                sessionId = data.sessionId
                startingUp = false
                client.connect(clientSecret: data.clientSecret, webrtcEndpoint: data.webrtcEndpoint)
            } else {
                setupError = envelope.message
                startingUp = false
            }
        } catch {
            setupError = error.apiFailure.message
            startingUp = false
        }
    }

    /// Hang up, and report the call's length so it is counted against the plan.
    private func endCall() {
        if endedByUser { return }
        endedByUser = true
        client.disconnect()
        if let id = sessionId {
            let duration = elapsed()
            Task { _ = try? await AiChatApi.endRealtimeSession(id, durationSeconds: duration) }
        }
        onExit()
    }

    var body: some View {
        let c = wa.ai
        ObservedVoice(client: client) { client in
            VStack(spacing: 0) {
                HStack(spacing: 8) {
                    AiRoundIcon(icon: "chevron.left", tint: c.ink, size: 17, action: endCall)
                    Text(L("ai_voice_mode")).font(.dorr(15, .heavy)).foregroundStyle(c.ink)
                    Spacer()
                }
                .padding(.horizontal, 8).padding(.vertical, 8)
                VStack(spacing: 0) {
                    if permissionDenied {
                        Text(L("ai_voice_permission_denied")).font(.dorr(13)).foregroundStyle(c.mut).multilineTextAlignment(.center)
                    } else if let setupError {
                        failure(setupError)
                    } else if client.state == .failed {
                        failure(client.errorMessage ?? L("ai_voice_error_generic"))
                    } else {
                        ZStack {
                            Circle().fill(c.red.opacity(0.15)).frame(width: 160, height: 160).scaleEffect(pulse && client.state == .connected ? 1.08 : 1)
                            Circle().fill(c.red.opacity(0.85)).frame(width: 120, height: 120)
                            if startingUp || client.state == .connecting {
                                ProgressView().tint(.white).scaleEffect(1.3)
                            } else {
                                Image(systemName: client.muted ? "mic.slash.fill" : "mic.fill").font(.system(size: 38)).foregroundStyle(.white)
                            }
                        }
                        let status: String = startingUp || client.state == .connecting ? L("ai_voice_connecting")
                            : client.muted ? L("ai_voice_muted") : client.assistantSpeaking ? L("ai_voice_speaking") : L("ai_voice_listening")
                        Text(verbatim: status).font(.dorr(15, .semibold)).foregroundStyle(c.ink).padding(.top, 18)
                        if client.state == .connected {
                            // The call's length on the phone's clock.
                            TimelineView(.periodic(from: .now, by: 1)) { ctx in
                                let s = elapsed(ctx.date)
                                Text(verbatim: String(format: "%d:%02d", s / 60, s % 60)).font(.dorr(12)).foregroundStyle(c.mut)
                            }
                            .padding(.top, 4)
                        }
                        if !client.transcript.trimmingCharacters(in: .whitespaces).isEmpty {
                            Text(verbatim: client.transcript).font(.dorr(13)).foregroundStyle(c.mut).multilineTextAlignment(.center).frame(maxWidth: .infinity).padding(.top, 20)
                        }
                    }
                }
                .padding(24)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                if client.state == .connected || client.state == .connecting {
                    HStack {
                        Spacer()
                        Button { client.toggleMute() } label: {
                            Image(systemName: client.muted ? "mic.slash.fill" : "mic.fill").font(.system(size: 22)).foregroundStyle(c.ink)
                                .frame(width: 56, height: 56).background(c.surface, in: Circle())
                        }
                        .buttonStyle(.plain)
                        .disabled(client.state != .connected)
                        .accessibilityLabel(L(client.muted ? "ai_voice_unmute" : "ai_voice_mute"))
                        Spacer()
                        Button(action: endCall) {
                            Image(systemName: "phone.down.fill").font(.system(size: 26)).foregroundStyle(.white)
                                .frame(width: 64, height: 64).background(Color(hex: 0xDC2626), in: Circle())
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel(L("ai_voice_end_call"))
                        Spacer()
                    }
                    .padding(.horizontal, 32).padding(.vertical, 28)
                }
            }
            .onChange(of: client.state) { s in if s == .connected && connectedAt == nil { connectedAt = Date() } }
        }
        .background(c.bg.ignoresSafeArea())
        .onAppear { withAnimation(.easeInOut(duration: 0.9).repeatForever(autoreverses: true)) { pulse = true } }
        .task {
            AVAudioSession.sharedInstance().requestRecordPermission { granted in
                Task { @MainActor in
                    if granted { permissionDenied = false; await startSession() } else { permissionDenied = true; startingUp = false }
                }
            }
        }
        .onDisappear {
            // Left another way (the system back): still hang up and report.
            if !endedByUser {
                endedByUser = true
                client.disconnect()
                if let id = sessionId {
                    let duration = elapsed()
                    Task { _ = try? await AiChatApi.endRealtimeSession(id, durationSeconds: duration) }
                }
            }
        }
    }

    private func failure(_ text: String) -> some View {
        let c = wa.ai
        return VStack(spacing: 12) {
            Text(verbatim: text).font(.dorr(13)).foregroundStyle(c.mut).multilineTextAlignment(.center)
            Button { Task { await startSession() } } label: { Text(L("ai_voice_retry")).font(.dorr(14, .semibold)).foregroundStyle(c.red) }.buttonStyle(.plain)
        }
    }
}

/// Keeps the call's client, and a fresh one for «try again».
@MainActor
private final class VoiceClientHolder: ObservableObject {
    @Published private(set) var client = RealtimeVoiceClient()
    func renew() {
        client.disconnect()
        client = RealtimeVoiceClient()
    }
}

/// Re-renders with the client's own changes.
private struct ObservedVoice<Content: View>: View {
    @ObservedObject var client: RealtimeVoiceClient
    @ViewBuilder let content: (RealtimeVoiceClient) -> Content
    var body: some View { content(client) }
}
