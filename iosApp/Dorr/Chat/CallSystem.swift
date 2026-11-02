import AVFoundation
import AVKit
import CallKit
import Combine
import LiveKit
import LiveKitWebRTC
import PushKit
import UIKit

// The iPhone's side of what Android does with its full-screen call notification and picture-in-picture
// (androidApp `chat/CallNotifications.kt`, `CallService.kt`, MainActivity's PiP):
//  - PushKit: a ringing call arrives as a VoIP push straight from the server (APNs), even with the app closed;
//  - CallKit: the phone's own call screen rings it — locked, or over another app — and its Answer / Decline /
//    mute buttons drive `CallController`, the same state machine as in the app;
//  - PiP: a video call keeps showing the other side in a small window when I leave the app.
// The VoIP token is registered with the server next to the OneSignal id (`ChatPush.register`).

@MainActor
final class CallSystem: NSObject {
    static let shared = CallSystem()

    private var registry: PKPushRegistry?
    private let provider: CXProvider
    private let controller = CXCallController()
    /// The server's call id ↔ the UUID CallKit knows it by.
    private var reported: [String: UUID] = [:]
    /// Answered from the system screen before the call had loaded: pick up as soon as it does.
    private var answerWhenLoaded: String?
    /// Reported and still loading — the end of an earlier call mustn't close these.
    private var loading: Set<String> = []
    private var answering: CXAnswerCallAction?
    private var phaseWatch: AnyCancellable?
    private var micWatch: AnyCancellable?
    /// This phone's PushKit token (hex), once Apple gives it.
    private(set) var voipToken: String?

    override init() {
        let config = CXProviderConfiguration()
        config.supportsVideo = true
        config.maximumCallGroups = 1
        config.maximumCallsPerCallGroup = 1
        config.supportedHandleTypes = [.generic]
        config.includesCallsInRecents = false
        config.iconTemplateImageData = UIImage(systemName: "phone.fill")?.pngData()
        provider = CXProvider(configuration: config)
        super.init()
        provider.setDelegate(self, queue: nil)
    }

    /// At launch (also when iOS starts the app in the background for a VoIP push).
    func start() {
        guard registry == nil else { return }
        let r = PKPushRegistry(queue: .main)
        r.delegate = self
        r.desiredPushTypes = [.voIP]
        registry = r
        phaseWatch = CallController.shared.$phase.removeDuplicates().sink { [weak self] phase in self?.follow(phase) }
        micWatch = CallController.shared.$micOn.removeDuplicates().sink { [weak self] on in self?.followMic(on) }
    }

    /// The system screen is ringing this call (the app's own ringtone stays quiet then).
    func isReported(_ callId: String?) -> Bool { callId.map { reported[$0] != nil } ?? false }

    /// «Answer» in the app while the system screen also rings: answer through CallKit, so both agree.
    func answer(_ callId: String) -> Bool {
        guard let uuid = reported[callId] else { return false }
        controller.request(CXTransaction(action: CXAnswerCallAction(call: uuid))) { error in
            if error != nil { Task { @MainActor in CallController.shared.acceptNow() } }
        }
        return true
    }

    // MARK: - the call → CallKit

    private func follow(_ phase: CallPhase?) {
        let c = CallController.shared
        switch phase {
        case .connecting, .active:
            // Answered from the system screen: CallKit waits for the room before it shows «connected».
            if phase == .active, let action = answering {
                action.fulfill()
                answering = nil
            }
        case .ended, nil:
            answering?.fail()
            answering = nil
            for (id, uuid) in reported where !loading.contains(id) {
                provider.reportCall(with: uuid, endedAt: Date(), reason: c.call?.id == id && c.connectedAt != nil ? .remoteEnded : .unanswered)
                reported[id] = nil
            }
            if answerWhenLoaded.map({ !loading.contains($0) }) ?? false { answerWhenLoaded = nil }
        default: break
        }
    }

    private func followMic(_ on: Bool) {
        guard let uuid = CallController.shared.call.flatMap({ reported[$0.id] }) else { return }
        controller.request(CXTransaction(action: CXSetMutedCallAction(call: uuid, muted: !on))) { _ in }
    }

    /// Show the system's ringing screen for a call from a VoIP push. iOS insists this happens for every VoIP push,
    /// right away — a call that turns out to be over is reported ended a moment later.
    private func reportIncoming(_ payload: [AnyHashable: Any], completion: @escaping () -> Void) {
        func s(_ k: String) -> String? { payload[k].map { "\($0)" }.flatMap { $0.isEmpty || $0 == "<null>" ? nil : $0 } }
        let callId = s("call_id") ?? UUID().uuidString
        let uuid = reported[callId] ?? UUID()
        let update = CXCallUpdate()
        update.remoteHandle = CXHandle(type: .generic, value: s("caller_name") ?? "DORR")
        update.localizedCallerName = s("caller_name")
        update.hasVideo = s("video") == "1" || s("call_type") == "video"
        update.supportsHolding = false
        update.supportsGrouping = false
        update.supportsUngrouping = false
        update.supportsDTMF = false
        provider.reportNewIncomingCall(with: uuid, update: update) { [weak self] error in
            Task { @MainActor in
                defer { completion() }
                guard let self else { return }
                // Busy, Do Not Disturb, blocked by the phone: nothing rings; the caller hears it ring out.
                if error != nil { return }
                let c = CallController.shared
                // Already in a call: the second one doesn't ring here.
                if let phase = c.phase, phase != .ended, c.call?.id != callId {
                    self.provider.reportCall(with: uuid, endedAt: Date(), reason: .declinedElsewhere)
                    return
                }
                self.reported[callId] = uuid
                // The real-time event may have rung the app's own tone first: the system screen rings now.
                Ringer.shared.stop()
                self.loading.insert(callId)
                c.loadIncoming(callId) { [weak self] ringing in
                    guard let self else { return }
                    self.loading.remove(callId)
                    if !ringing {
                        // Over before we got here (cancelled, answered on another phone).
                        self.reported[callId] = nil
                        self.provider.reportCall(with: uuid, endedAt: Date(), reason: .remoteEnded)
                    } else if self.answerWhenLoaded == callId {
                        self.answerWhenLoaded = nil
                        c.acceptNow()
                    }
                }
            }
        }
    }

    private func callId(for uuid: UUID) -> String? { reported.first { $0.value == uuid }?.key }
}

extension CallSystem: PKPushRegistryDelegate {
    nonisolated func pushRegistry(_ registry: PKPushRegistry, didUpdate pushCredentials: PKPushCredentials, for type: PKPushType) {
        let token = pushCredentials.token.map { String(format: "%02x", $0) }.joined()
        Task { @MainActor in
            self.voipToken = token
            ChatPush.shared.voipTokenChanged()
        }
    }

    nonisolated func pushRegistry(_ registry: PKPushRegistry, didInvalidatePushTokenFor type: PKPushType) {
        Task { @MainActor in self.voipToken = nil }
    }

    nonisolated func pushRegistry(_ registry: PKPushRegistry, didReceiveIncomingPushWith payload: PKPushPayload, for type: PKPushType, completion: @escaping () -> Void) {
        let data = payload.dictionaryPayload
        MainActor.assumeIsolated { self.reportIncoming(data, completion: completion) }
    }
}

extension CallSystem: CXProviderDelegate {
    nonisolated func providerDidReset(_ provider: CXProvider) {
        Task { @MainActor in self.reported = [:] }
    }

    nonisolated func provider(_ provider: CXProvider, perform action: CXAnswerCallAction) {
        Task { @MainActor in
            guard let id = self.callId(for: action.callUUID) else { return action.fail() }
            self.answering = action
            let c = CallController.shared
            if c.call?.id == id, c.phase == .incoming {
                c.acceptNow()
            } else {
                self.answerWhenLoaded = id
            }
        }
    }

    nonisolated func provider(_ provider: CXProvider, perform action: CXEndCallAction) {
        Task { @MainActor in
            let id = self.callId(for: action.callUUID)
            self.reported = self.reported.filter { $0.value != action.callUUID }
            let c = CallController.shared
            if let id, c.call?.id == id {
                if c.phase == .incoming { c.decline() } else { c.hangUp() }
            } else if let id {
                // Declined from the lock screen before the call loaded.
                Task { _ = try? await ChatApi.declineCall(id) }
            }
            action.fulfill()
        }
    }

    nonisolated func provider(_ provider: CXProvider, perform action: CXSetMutedCallAction) {
        Task { @MainActor in
            let c = CallController.shared
            if c.micOn == action.isMuted { c.toggleMic() }
            action.fulfill()
        }
    }

    /// CallKit owns the audio session for a call it shows: WebRTC's audio starts when it's handed over.
    nonisolated func provider(_ provider: CXProvider, didActivate audioSession: AVAudioSession) {
        LKRTCAudioSession.sharedInstance().audioSessionDidActivate(audioSession)
    }

    nonisolated func provider(_ provider: CXProvider, didDeactivate audioSession: AVAudioSession) {
        LKRTCAudioSession.sharedInstance().audioSessionDidDeactivate(audioSession)
    }
}

// MARK: - picture in picture

/// A video call keeps the other side in a small window over other apps when I leave DORR (Android: the activity's
/// PiP). iOS starts it by itself when the app goes to the background while a video call is on.
@MainActor
final class CallPip: NSObject, AVPictureInPictureControllerDelegate {
    static let shared = CallPip()

    private var pip: AVPictureInPictureController?
    private var content: AVPictureInPictureVideoCallViewController?
    private let videoView = VideoView()

    /// After each change of the call's videos: PiP is ready only while someone's video is on in an answered call.
    func update() {
        guard AVPictureInPictureController.isPictureInPictureSupported() else { return }
        let c = CallController.shared
        let remote = c.videos.first { !$0.isLocal }
        guard c.phase == .active, c.isVideo, let remote, let source = CallWindow.shared.sourceView else {
            stop()
            return
        }
        videoView.track = remote.track
        videoView.layoutMode = remote.screen ? .fit : .fill
        if pip == nil {
            let vc = AVPictureInPictureVideoCallViewController()
            vc.preferredContentSize = CGSize(width: 9, height: 16)
            videoView.frame = vc.view.bounds
            videoView.autoresizingMask = [.flexibleWidth, .flexibleHeight]
            vc.view.addSubview(videoView)
            content = vc
            let controller = AVPictureInPictureController(contentSource: .init(activeVideoCallSourceView: source, contentViewController: vc))
            controller.canStartPictureInPictureAutomaticallyFromInline = true
            controller.delegate = self
            pip = controller
        }
    }

    func stop() {
        pip?.stopPictureInPicture()
        pip = nil
        content = nil
        videoView.track = nil
        videoView.removeFromSuperview()
    }
}
