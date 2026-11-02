import LiveKit
import PhotosUI
import SwiftUI
import UIKit

// androidApp `ui/screens/chat/CallOverlay.kt`, `ChatCallRecordingUi.kt` (the layer over the call), `ChatLiveUi.kt`
// (going live from the call) and `ChatCallEffectsUi.kt` — the call screen, over everything: a call can ring on any
// screen of the app. It lives in its own window above the app's (Android draws it over every screen), shrinks to a
// small window I can drag while I use the app, and is drawn only while there is a call.

// MARK: - the window

/// The window the call is drawn in. Minimised, only the small call window takes touches; the rest go to the app.
@MainActor
final class CallWindow {
    static let shared = CallWindow()

    private var window: PassthroughWindow?
    private var rtl = true
    private var locale = "ar"
    /// The small window's frame (minimised), in window points — the only place that takes touches then.
    var interactiveRect: CGRect = .zero
    /// Where picture-in-picture grows from (the call's own view).
    var sourceView: UIView? { window?.isHidden == false ? window?.rootViewController?.view : nil }

    /// The app's language, read where the app sets it.
    func install(rtl: Bool, locale: String) {
        let changed = self.rtl != rtl || self.locale != locale
        self.rtl = rtl
        self.locale = locale
        if changed, let window { window.rootViewController = host() }
        update()
    }

    func update() {
        let c = CallController.shared
        let showing = c.phase != nil
        if showing && window == nil {
            guard let scene = UIApplication.shared.connectedScenes.compactMap({ $0 as? UIWindowScene }).first(where: { $0.activationState == .foregroundActive })
                ?? UIApplication.shared.connectedScenes.compactMap({ $0 as? UIWindowScene }).first else { return }
            let w = PassthroughWindow(windowScene: scene)
            w.windowLevel = UIWindow.Level(rawValue: UIWindow.Level.normal.rawValue + 20)
            w.backgroundColor = .clear
            w.rootViewController = host()
            window = w
        }
        guard let window else { return }
        window.isHidden = !showing
        // Full screen: the call takes the keyboard (the live title); minimised or gone: the app has it back.
        if showing && !c.minimized {
            window.makeKey()
        } else {
            UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }.flatMap(\.windows).first { $0 !== window && !$0.isHidden && $0.windowLevel == .normal }?.makeKey()
        }
    }

    private func host() -> UIViewController {
        let root = CallOverlayRoot()
            .environmentObject(AppearanceState.shared)
            .environmentObject(NetworkMonitor.shared)
            .environment(\.layoutDirection, rtl ? .rightToLeft : .leftToRight)
            .environment(\.locale, Locale(identifier: locale))
        let vc = UIHostingController(rootView: AnyView(root))
        vc.view.backgroundColor = .clear
        return vc
    }

    fileprivate final class PassthroughWindow: UIWindow {
        override func hitTest(_ point: CGPoint, with event: UIEvent?) -> UIView? {
            // Minimised: only the small window is the call's; everywhere else is the app.
            if CallController.shared.minimized && !CallWindow.shared.interactiveRect.contains(point) { return nil }
            return super.hitTest(point, with: event)
        }
    }
}

/// Puts the call's window in step with the app's language (androidApp draws `CallOverlay()` in MainScreen).
struct CallWindowInstaller: View {
    @Environment(\.layoutDirection) private var direction
    @Environment(\.locale) private var locale

    var body: some View {
        Color.clear.frame(width: 0, height: 0)
            .task(id: "\(direction == .rightToLeft)-\(locale.identifier)") {
                CallController.shared.attach()
                VoiceRoomController.shared.attach()
                CallWindow.shared.install(rtl: direction == .rightToLeft, locale: locale.identifier)
                #if DEBUG
                if let which = UserDefaults.standard.string(forKey: "DorrCall") {
                    try? await Task.sleep(nanoseconds: 1_500_000_000)
                    CallController.shared.debugShow(which)
                }
                #endif
            }
    }
}

private struct CallOverlayRoot: View {
    @ObservedObject private var c = CallController.shared

    var body: some View {
        ZStack {
            if c.phase != nil && !c.minimized {
                CallScreen().transition(.move(edge: .bottom).combined(with: .opacity))
            }
            // Minimised: a small window over the app that I can drag; a tap brings the call back.
            if let phase = c.phase, phase != .ended, c.minimized { CallMiniBubble() }
            CallNotice()
        }
        .animation(.spring(response: 0.45, dampingFraction: 0.85), value: c.minimized)
        .animation(.spring(response: 0.45, dampingFraction: 0.85), value: c.phase == nil)
        .onChange(of: c.minimized) { _ in CallWindow.shared.update() }
    }
}

/// A short message over the call (Android's toast): recording notes and errors, said once.
private struct CallNotice: View {
    @ObservedObject private var c = CallController.shared
    @State private var text: String?

    var body: some View {
        VStack {
            Spacer()
            if let text {
                Text(verbatim: text).font(.dorr(13.5, .semibold)).foregroundStyle(.white).multilineTextAlignment(.center)
                    .padding(.horizontal, 16).padding(.vertical, 10)
                    .background(Color.black.opacity(0.75), in: Capsule())
                    .padding(.bottom, 150).padding(.horizontal, 24)
                    .transition(.opacity.combined(with: .move(edge: .bottom)))
            }
        }
        .allowsHitTesting(false)
        .animation(.easeInOut(duration: 0.25), value: text)
        .onChange(of: c.recordingNote) { note in
            let key: String?
            switch note {
            case "recording": key = "cr_note_started"
            case "declined": key = "cr_note_declined"
            case "expired": key = "cr_note_expired"
            case "cancelled": key = "cr_note_cancelled"
            case "failed": key = "cr_note_failed"
            case "processing", "ready": key = "cr_note_saved"
            default: key = nil
            }
            if let key { show(L(key), seconds: 3.5) }
            if note != nil { c.recordingNote = nil }
        }
        .onChange(of: c.recordingError) { e in
            if let e { show(e, seconds: 2) }
            c.recordingError = nil
        }
        .onChange(of: c.notice) { n in
            if let n { show(n, seconds: 2) }
            c.notice = nil
        }
    }

    private func show(_ message: String, seconds: Double) {
        text = message
        Task {
            try? await Task.sleep(nanoseconds: UInt64(seconds * 1_000_000_000))
            if text == message { text = nil }
        }
    }
}

// MARK: - minimised

/// The call, minimised: its video (or photo, name and timer), anywhere on screen.
private struct CallMiniBubble: View {
    @ObservedObject private var c = CallController.shared
    @State private var offset: CGSize?
    @GestureState private var drag = CGSize.zero

    var body: some View {
        GeometryReader { geo in
            let remote = c.videos.first { !$0.isLocal }
            let size = remote != nil ? CGSize(width: 112, height: 168) : CGSize(width: 190, height: 62)
            let area = geo.frame(in: .global).insetBy(dx: 12, dy: 12)
            let maxX = max(area.width - size.width, 0)
            let maxY = max(area.height - size.height, 0)
            let base = offset ?? CGSize(width: maxX, height: min(64, maxY))
            let x = min(max(base.width + drag.width, 0), maxX)
            let y = min(max(base.height + drag.height, 0), maxY)
            bubble(remote: remote)
                .frame(width: size.width, height: size.height)
                .background(Color(hex: 0x001B53))
                .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
                .shadow(color: .black.opacity(0.35), radius: 18, y: 6)
                .onTapGesture { c.minimized = false }
                .gesture(DragGesture()
                    .updating($drag) { v, s, _ in s = v.translation }
                    .onEnded { v in
                        let endX = min(max(base.width + v.translation.width, 0), maxX)
                        let endY = min(max(base.height + v.translation.height, 0), maxY)
                        // Let go: it snaps to the nearest side.
                        withAnimation(.spring(response: 0.4, dampingFraction: 0.7)) { offset = CGSize(width: endX < maxX / 2 ? 0 : maxX, height: endY) }
                    })
                .position(x: area.minX + x + size.width / 2, y: area.minY + y + size.height / 2)
                .onAppear { CallWindow.shared.interactiveRect = CGRect(x: area.minX + x, y: area.minY + y, width: size.width, height: size.height) }
                .onChange(of: CGRect(x: area.minX + x, y: area.minY + y, width: size.width, height: size.height)) { CallWindow.shared.interactiveRect = $0 }
        }
        .ignoresSafeArea()
        .onDisappear { CallWindow.shared.interactiveRect = .zero }
    }

    @ViewBuilder
    private func bubble(remote: CallVideo?) -> some View {
        TimelineView(.periodic(from: .now, by: 1)) { t in
            if let remote {
                ZStack(alignment: .bottom) {
                    VideoSurface(video: remote)
                    Text(verbatim: durationText(c.elapsedSeconds(t.date) * 1000)).font(.dorr(11, .bold)).foregroundStyle(.white)
                        .padding(.horizontal, 6).padding(.vertical, 2).background(Color.black.opacity(0.45), in: RoundedRectangle(cornerRadius: 8)).padding(6)
                }
            } else {
                HStack(spacing: 8) {
                    ChAvatar(url: c.avatar, name: c.title, key: c.peerKey, size: 40)
                    VStack(alignment: .leading, spacing: 1) {
                        Text(verbatim: c.title).font(.dorr(13.5, .bold)).foregroundStyle(.white).lineLimit(1)
                        Text(verbatim: c.phase == .active ? durationText(c.elapsedSeconds(t.date) * 1000) : L("ch_call_calling")).font(.dorr(12)).foregroundStyle(.white.opacity(0.75))
                    }
                    Spacer(minLength: 0)
                    Button { c.hangUp() } label: {
                        Image(systemName: "phone.down.fill").font(.system(size: 15)).foregroundStyle(.white).frame(width: 34, height: 34).background(Ch.shared.danger, in: Circle())
                    }
                    .buttonStyle(.plain)
                }
                .padding(.horizontal, 10)
            }
        }
    }
}

// MARK: - the call screen

private struct CallScreen: View {
    @ObservedObject private var c = CallController.shared
    @State private var controlsVisible = true
    @State private var hideTask: Task<Void, Never>?
    @State private var liveShare = false

    var body: some View {
        let phase = c.phase ?? .ended
        let remote = c.videos.first { !$0.isLocal }
        let local = c.videos.first { $0.isLocal }
        // A group call (or anyone who joined a 1:1 later) shows everyone as a grid instead of one face.
        let grouped = phase == .active && (c.call?.conversationType == "group" || c.members.filter { !$0.isLocal }.count > 1)
        ZStack {
            Color.black.ignoresSafeArea()
            stage(phase: phase, remote: remote, local: local, grouped: grouped)
            if !grouped && (remote == nil || phase != .active || controlsVisible) {
                who(phase: phase, remote: remote).transition(.opacity)
            }
            topBar(phase: phase)
            // My camera (drag it anywhere, it snaps to a side).
            if let local, remote != nil, phase == .active, !grouped { LocalPreview(video: local) }
            // Live (spec 93): LIVE · viewers.
            if let l = c.live, l.status == "live", phase == .active {
                VStack { LiveBadge(live: l) { liveShare = true }.padding(.top, 8); Spacer() }
            }
            VStack {
                Spacer()
                if phase == .incoming {
                    IncomingControls(onDecline: { c.decline() }, onAccept: accept)
                } else if controlsVisible || remote == nil || grouped {
                    ActiveControls().transition(.move(edge: .bottom).combined(with: .opacity))
                }
            }
            .padding(.bottom, 36)
            // Recording (spec 92): REC for as long as it records, and the questions that come before anything is
            // recorded — on top of everything.
            CallRecordingLayer(phase: phase)
        }
        .contentShape(Rectangle())
        .onTapGesture { withAnimation(.easeInOut(duration: 0.25)) { controlsVisible.toggle() } }
        .animation(.easeInOut(duration: 0.25), value: controlsVisible)
        // In a video call, controls hide themselves; a tap brings them back.
        .onChange(of: "\(controlsVisible)-\(phase)-\(remote != nil)-\(grouped)") { _ in scheduleHide(phase: phase, remote: remote != nil, grouped: grouped) }
        .dorrDialog(isPresented: $liveShare, bottom: true) {
            if let l = c.live { LiveShareSheet(live: l) { liveShare = false } }
        }
    }

    private func scheduleHide(phase: CallPhase, remote: Bool, grouped: Bool) {
        hideTask?.cancel()
        guard controlsVisible, phase == .active, remote, !grouped else { return }
        hideTask = Task {
            try? await Task.sleep(nanoseconds: 4_000_000_000)
            if !Task.isCancelled { withAnimation { controlsVisible = false } }
        }
    }

    private func accept() {
        Task {
            if await CallPermissions.ask(video: c.isVideo) { c.accept() } else { c.decline() }
        }
    }

    @ViewBuilder
    private func stage(phase: CallPhase, remote: CallVideo?, local: CallVideo?, grouped: Bool) -> some View {
        if grouped {
            // Group: everyone in a grid.
            AnimatedBackdrop()
            GroupGrid().padding(.top, 96).padding(.bottom, 124)
            TimelineView(.periodic(from: .now, by: 1)) { t in
                VStack(spacing: 2) {
                    Text(verbatim: c.title).font(.dorr(19, .heavy)).foregroundStyle(.white).lineLimit(1)
                    Text(verbatim: L("ch_call_people", c.members.count) + "  ·  " + durationText(c.elapsedSeconds(t.date) * 1000))
                        .font(.dorr(13, .semibold)).foregroundStyle(.white.opacity(0.75))
                }
                .frame(maxHeight: .infinity, alignment: .top).padding(.top, 34)
            }
        } else if let remote, phase == .active {
            VideoSurface(video: remote).ignoresSafeArea()
        } else if let local, phase != .ended {
            // Calling, connecting, or their camera is off: my own camera fills the screen (like WhatsApp), so it's
            // plain the camera did open.
            VideoSurface(video: local).ignoresSafeArea()
        } else {
            AnimatedBackdrop()
        }
    }

    private func who(phase: CallPhase, remote: CallVideo?) -> some View {
        VStack(spacing: 0) {
            if remote == nil || phase != .active {
                Sonar(ringing: phase == .incoming || phase == .outgoing) { ChAvatar(url: c.avatar, name: c.title, key: c.peerKey, size: 124) }
                    .padding(.bottom, 26)
            }
            Text(verbatim: c.title).font(.dorr(27, .heavy)).foregroundStyle(.white).multilineTextAlignment(.center)
            TimelineView(.periodic(from: .now, by: 1)) { t in
                Text(verbatim: stateLine(phase, t.date)).font(.dorr(16, .semibold)).foregroundStyle(.white.opacity(0.8))
                    .id(phase).transition(.opacity)
            }
            .padding(.top, 6)
            Spacer()
        }
        .padding(.top, 70)
        .frame(maxWidth: .infinity)
        .animation(.easeInOut, value: phase)
    }

    private func stateLine(_ p: CallPhase, _ now: Date) -> String {
        switch p {
        case .incoming: return L(c.isVideo ? "ch_call_incoming_video" : "ch_call_incoming_voice")
        case .outgoing: return L("ch_call_calling")
        case .connecting: return L("ch_call_connecting")
        case .active: return durationText(c.elapsedSeconds(now) * 1000)
        case .ended: return c.error ?? L("ch_call_ended")
        }
    }

    @ViewBuilder
    private func topBar(phase: CallPhase) -> some View {
        VStack {
            HStack {
                // Minimise: the call goes on, small, while I use the app — it's ended with the red button, never by
                // accident. A ringing call stays on screen until answered or declined.
                if phase == .outgoing || phase == .connecting || phase == .active {
                    Button { c.minimized = true } label: {
                        Image(systemName: "chevron.down").font(.system(size: 18, weight: .bold)).foregroundStyle(.white)
                            .frame(width: 42, height: 42).background(Color.white.opacity(0.16), in: Circle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("ch_call_minimize"))
                }
                Spacer()
            }
            .padding(12)
            // My screen is going out: say so, with «Stop».
            if c.sharingScreen && phase == .active {
                HStack(spacing: 8) {
                    Image(systemName: "rectangle.on.rectangle").font(.system(size: 15))
                    Text(L("ch_call_sharing")).font(.dorr(13, .bold))
                    Button { c.stopScreenShare() } label: {
                        Text(L("ch_call_stop_sharing")).font(.dorr(13, .heavy)).foregroundStyle(Ch.shared.danger)
                            .padding(.horizontal, 10).padding(.vertical, 4).background(.white, in: RoundedRectangle(cornerRadius: 14))
                    }
                    .buttonStyle(.plain)
                }
                .foregroundStyle(.white)
                .padding(.leading, 14).padding(.trailing, 6).padding(.vertical, 6)
                .background(Ch.shared.danger, in: RoundedRectangle(cornerRadius: 20))
            }
            Spacer()
        }
    }
}

/// Everyone in the call: 2 people stacked, up to 6 in two columns filling the screen, more than that scroll.
private struct GroupGrid: View {
    @ObservedObject private var c = CallController.shared

    var body: some View {
        GeometryReader { geo in
            let people = c.members
            let columns = people.count <= 2 ? 1 : 2
            let rows = max((people.count + columns - 1) / columns, 1)
            let gap: CGFloat = 10
            let tile = people.count <= 6 ? (geo.size.height - gap * CGFloat(rows - 1)) / CGFloat(rows) : 220
            ScrollView {
                LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: gap), count: columns), spacing: gap) {
                    ForEach(people) { m in CallMemberTile(member: m).frame(height: tile) }
                }
                .animation(.spring(response: 0.5, dampingFraction: 0.8), value: people.map(\.key))
            }
            .scrollDisabled(people.count <= 6)
        }
        .padding(.horizontal, 10)
    }
}

/// A tile: the camera when it's on, else the person's photo on a soft gradient; whoever is talking gets a glowing
/// ring, and a muted mic shows a small badge.
private struct CallMemberTile: View {
    let member: CallMember

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 22, style: .continuous)
        ZStack(alignment: .bottomLeading) {
            LinearGradient(colors: [Color(hex: 0x2A0A0D), Color(hex: 0x14080A)], startPoint: .topLeading, endPoint: .bottomTrailing)
            if let v = member.video {
                VideoSurface(video: v)
            } else {
                Sonar(ringing: member.speaking) { ChAvatar(url: member.avatar, name: member.name, key: member.key, size: 84) }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
            HStack(spacing: 4) {
                if !member.micOn { Image(systemName: "mic.slash.fill").font(.system(size: 12)).foregroundStyle(Color(hex: 0xF87171)) }
                Text(verbatim: member.isLocal ? L("ch_you") : (member.name ?? "")).font(.dorr(12.5, .bold)).foregroundStyle(.white).lineLimit(1)
            }
            .padding(.horizontal, 10).padding(.vertical, 5)
            .background(Color.black.opacity(0.45), in: RoundedRectangle(cornerRadius: 12))
            .padding(10)
        }
        .clipShape(shape)
        .overlay(shape.stroke(Color(hex: 0x22C55E), lineWidth: member.speaking ? 3 : 0))
        .shadow(color: member.speaking ? Color(hex: 0x22C55E).opacity(0.6) : .black.opacity(0.3), radius: member.speaking ? 18 : 6)
        .animation(.spring(response: 0.35, dampingFraction: 0.5), value: member.speaking)
    }
}

/// Slowly drifting brand glows behind the avatar.
private struct AnimatedBackdrop: View {
    @State private var a = false
    @State private var b = true

    var body: some View {
        GeometryReader { geo in
            let w = geo.size.width, h = geo.size.height
            ZStack {
                LinearGradient(colors: [Color(hex: 0x3B0206), Color(hex: 0x120203), .black], startPoint: .top, endPoint: .bottom)
                RadialGradient(colors: [Color(hex: 0x001B53).opacity(0.4), .clear], center: .center, startRadius: 0, endRadius: w * 0.8)
                    .frame(width: w * 1.6, height: w * 1.6)
                    .position(x: w * (a ? 0.7 : 0.2), y: h * 0.25)
                RadialGradient(colors: [Color(hex: 0xFF8A4C).opacity(0.27), .clear], center: .center, startRadius: 0, endRadius: w * 0.7)
                    .frame(width: w * 1.4, height: w * 1.4)
                    .position(x: w * (b ? 0.8 : 0.4), y: h * 0.75)
            }
        }
        .ignoresSafeArea()
        .onAppear {
            withAnimation(.easeInOut(duration: 7).repeatForever(autoreverses: true)) { a = true }
            withAnimation(.easeInOut(duration: 9).repeatForever(autoreverses: true)) { b = false }
        }
    }
}

/// Three rings expanding out of the avatar while it rings.
private struct Sonar<Content: View>: View {
    let ringing: Bool
    @ViewBuilder let content: () -> Content

    var body: some View {
        ZStack {
            if ringing {
                TimelineView(.animation) { t in
                    let time = t.date.timeIntervalSinceReferenceDate
                    ZStack {
                        ForEach(0..<3, id: \.self) { i in
                            let p = (time / 2.4 + Double(i) / 3).truncatingRemainder(dividingBy: 1)
                            Circle().fill(Ch.shared.red.opacity(0.55)).frame(width: 124, height: 124)
                                .scaleEffect(1 + p * 0.95).opacity((1 - p) * 0.55)
                        }
                    }
                }
            }
            content()
        }
        .frame(width: 240, height: 240)
    }
}

private struct IncomingControls: View {
    let onDecline: () -> Void
    let onAccept: () -> Void
    @State private var bob = false

    var body: some View {
        HStack(alignment: .bottom) {
            RoundAction(icon: "phone.down.fill", label: L("ch_call_decline"), color: Ch.shared.danger, size: 72, action: onDecline)
            Spacer()
            VStack(spacing: 0) {
                Image(systemName: "chevron.up").font(.system(size: 22, weight: .bold)).foregroundStyle(.white.opacity(0.6)).offset(y: bob ? -8 : 0)
                RoundAction(icon: "phone.fill", label: L("ch_call_accept"), color: Color(hex: 0x22C55E), size: 72, action: onAccept).offset(y: bob ? -6 : 0)
            }
        }
        .padding(.horizontal, 50)
        .onAppear { withAnimation(.easeInOut(duration: 0.9).repeatForever(autoreverses: true)) { bob = true } }
    }
}

private struct ActiveControls: View {
    @ObservedObject private var c = CallController.shared
    @ObservedObject private var effects = CallEffects.shared
    @ObservedObject private var features = ChatFeatures.shared
    @State private var backgrounds = false
    @State private var goLive = false
    @State private var liveShare = false

    var body: some View {
        let isLive = c.live?.status == "live"
        // Many buttons on a narrow phone: the row scrolls instead of squeezing them.
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 16) {
                ToggleAction(icon: c.micOn ? "mic.fill" : "mic.slash.fill", active: !c.micOn) { c.toggleMic() }
                ToggleAction(icon: "speaker.wave.2.fill", active: c.speakerOn) { c.toggleSpeaker() }
                ToggleAction(icon: c.cameraOn ? "video.fill" : "video.slash.fill", active: c.cameraOn) {
                    if !c.cameraOn && !c.cameraAllowed() {
                        Task { if await CallPermissions.askCamera() { c.toggleCamera() } else { c.notice = L("ch_call_camera_denied") } }
                    } else {
                        c.toggleCamera()
                    }
                }
                if c.cameraOn { ToggleAction(icon: "arrow.triangle.2.circlepath.camera.fill", active: false) { c.flipCamera() } }
                // Blur or a picture behind me (spec 94), made on the phone. Heavy: not in lite mode (spec 87).
                if c.cameraOn && !ChatLite.shared.active { ToggleAction(icon: "person.crop.rectangle.fill", active: effects.mode != .none) { backgrounds = true } }
                ToggleAction(icon: "rectangle.on.rectangle", active: c.sharingScreen) {
                    if c.sharingScreen { c.stopScreenShare() } else { c.startScreenShare() }
                }
                // Go live (spec 93) — or, once live, share / end it.
                if features.allows("live") || isLive {
                    ToggleAction(icon: "dot.radiowaves.left.and.right", active: isLive) { if isLive { liveShare = true } else { goLive = true } }
                }
                RoundAction(icon: "phone.down.fill", label: nil, color: Ch.shared.danger, size: 60) { c.hangUp() }
            }
            .padding(.horizontal, 18).padding(.vertical, 14)
        }
        .fixedSize(horizontal: true, vertical: false)
        .frame(maxWidth: UIScreen.main.bounds.width - 24)
        .background(Color.white.opacity(0.12), in: RoundedRectangle(cornerRadius: 36, style: .continuous))
        .padding(.horizontal, 12)
        .dorrDialog(isPresented: $backgrounds, bottom: true) { CallBackgroundSheet { backgrounds = false } }
        .dorrDialog(isPresented: $goLive, bottom: true) { StartLiveSheet { goLive = false } }
        .dorrDialog(isPresented: $liveShare, bottom: true) {
            if let l = c.live { LiveShareSheet(live: l) { liveShare = false } }
        }
    }
}

private struct RoundAction: View {
    let icon: String
    let label: String?
    let color: Color
    let size: CGFloat
    let action: () -> Void

    var body: some View {
        VStack(spacing: 8) {
            Button(action: action) {
                Image(systemName: icon).font(.system(size: size * 0.4)).foregroundStyle(.white)
                    .frame(width: size, height: size).background(color, in: Circle())
                    .shadow(color: color.opacity(0.6), radius: 14)
            }
            .buttonStyle(AiPressStyle())
            if let label { Text(verbatim: label).font(.dorr(13, .semibold)).foregroundStyle(.white) }
        }
    }
}

private struct ToggleAction: View {
    let icon: String
    let active: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 21)).foregroundStyle(active ? .black : .white)
                .frame(width: 52, height: 52).background(active ? Color.white : Color.white.opacity(0.16), in: Circle())
        }
        .buttonStyle(.plain)
        .animation(.easeInOut(duration: 0.2), value: active)
    }
}

/// A LiveKit video track on screen; my own camera mirrored, a shared screen shown whole, never cropped.
struct VideoSurface: View {
    let video: CallVideo

    var body: some View {
        SwiftUIVideoView(video.track, layoutMode: video.screen ? .fit : .fill, mirrorMode: video.isLocal ? .mirror : .off)
            .id(video.id)
    }
}

/// My camera in a small corner window: drag it anywhere, it snaps to the nearest side.
private struct LocalPreview: View {
    let video: CallVideo
    @State private var offset: CGSize?
    @GestureState private var drag = CGSize.zero

    var body: some View {
        GeometryReader { geo in
            let w: CGFloat = 110, h: CGFloat = 160
            let maxX = geo.size.width - w
            let maxY = max(geo.size.height - h - 120, 0)
            let base = offset ?? CGSize(width: maxX, height: 40)
            VideoSurface(video: video)
                .frame(width: w, height: h)
                .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
                .shadow(color: .black.opacity(0.4), radius: 16)
                .position(x: base.width + drag.width + w / 2, y: base.height + drag.height + h / 2)
                .gesture(DragGesture()
                    .updating($drag) { v, s, _ in s = v.translation }
                    .onEnded { v in
                        let x = base.width + v.translation.width
                        let y = min(max(base.height + v.translation.height, 0), maxY)
                        withAnimation(.spring(response: 0.4, dampingFraction: 0.7)) { offset = CGSize(width: x + w / 2 < geo.size.width / 2 ? 0 : maxX, height: y) }
                    })
        }
        .padding(16)
    }
}

// MARK: - recording (spec 92)

private let recRed = Color(hex: 0xEF4444)
private let amber = Color(hex: 0xF59E0B)

/// Over the call screen: REC · the request and how many agreed · or a «Record» chip in the top corner (never hidden
/// with the controls), and the questions in front of everything when needed.
private struct CallRecordingLayer: View {
    @ObservedObject private var c = CallController.shared
    let phase: CallPhase
    @State private var confirm: String?

    var body: some View {
        let recording = c.recording
        // Shown while it rings too (it tells the one answering), but only someone in the call acts on it.
        let inCall = phase == .active
        ZStack {
            VStack {
                HStack {
                    Spacer().frame(width: phase == .incoming ? 0 : 54)
                    if recording?.status == "recording" {
                        RecBadge(enabled: inCall) { confirm = "stop" }
                    } else if let recording, recording.status == "pending" {
                        AskingPill(recording: recording, enabled: inCall) { c.stopRecording() }
                    } else if inCall && c.recordingAvailable {
                        RecordChip { confirm = "ask" }
                    }
                    Spacer()
                }
                .padding(.leading, 12).padding(.top, 16)
                Spacer()
            }
            if confirm == "ask" {
                PromptCard(title: L("cr_ask_title"), body: L("cr_ask_body"), no: L("cr_cancel"), yes: L("cr_ask_yes"),
                           onNo: { confirm = nil }, onYes: { confirm = nil; c.requestRecording() })
            } else if confirm == "stop" {
                PromptCard(title: L("cr_stop_title"), body: L("cr_stop_body"), no: L("cr_keep"), yes: L("cr_stop_yes"),
                           onNo: { confirm = nil }, onYes: { confirm = nil; c.stopRecording() })
            }
            // Someone asked: nothing is recorded until I (and everyone else) answer.
            if let recording, c.mustAnswerRecording() {
                let name = c.nameOf(recording.requestedBy)
                ConsentPrompt(recording: recording, body: name.map { L("cr_consent_body", $0) } ?? L("cr_consent_body_someone"))
            }
            // Joining a call that records takes my yes first.
            if let asking = c.joinConsent {
                PromptCard(title: L(asking.status == "pending" ? "cr_join_title_asking" : "cr_join_title"), body: L("cr_join_body"),
                           no: L("cr_join_no"), yes: L("cr_join_yes"),
                           onNo: { c.answerJoinConsent(false) }, onYes: { c.answerJoinConsent(true) })
            }
        }
        // «Stop recording?» is moot once it stopped.
        .onChange(of: recording?.status) { s in if confirm == "stop" && s != "recording" { confirm = nil } }
    }
}

/// The question with its countdown («12 s left» until the request is dropped).
private struct ConsentPrompt: View {
    @ObservedObject private var c = CallController.shared
    let recording: CallRecordingStateDto
    let body_: String

    init(recording: CallRecordingStateDto, body: String) {
        self.recording = recording
        body_ = body
    }

    var body: some View {
        TimelineView(.periodic(from: .now, by: 1)) { t in
            let left = recording.consentUntil.flatMap(parseRideTime).map { max(Int($0.timeIntervalSince(t.date)), 0) }
            PromptCard(title: L("cr_consent_title"), body: body_, no: L("cr_consent_no"), yes: L("cr_consent_yes"),
                       onNo: { c.answerRecording(false) }, onYes: { c.answerRecording(true) },
                       extra: left.map { L("cr_seconds_left", $0) })
        }
    }
}

/// ⏺ REC 01:23 — tapping it (in the call) asks whether to stop.
private struct RecBadge: View {
    @ObservedObject private var c = CallController.shared
    let enabled: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            TimelineView(.periodic(from: .now, by: 1)) { t in
                HStack(spacing: 6) {
                    PulseDot(color: recRed)
                    Text(L("cr_rec")).font(.dorr(12, .black)).foregroundStyle(.white)
                    if let since = c.recordingSince {
                        Text(verbatim: durationText(max(Int64(t.date.timeIntervalSince(since) * 1000), 0))).font(.dorr(12, .bold)).foregroundStyle(.white.opacity(0.85))
                    }
                }
            }
            .padding(.horizontal, 12).padding(.vertical, 6)
            .background(Color.black.opacity(0.55), in: Capsule())
            .overlay(Capsule().stroke(recRed.opacity(0.85), lineWidth: 1))
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

/// Waiting for everyone's yes: «1 of 2 agreed», and × (in the call) takes the request back.
private struct AskingPill: View {
    let recording: CallRecordingStateDto
    let enabled: Bool
    let onCancel: () -> Void

    var body: some View {
        let agreed = recording.agreed?.count ?? 0
        let total = agreed + (recording.waitingFor?.count ?? 0)
        Button(action: onCancel) {
            HStack(spacing: 6) {
                PulseDot(color: amber)
                Text(verbatim: L("cr_asking", agreed, total)).font(.dorr(12, .bold)).foregroundStyle(.white)
                if enabled { Image(systemName: "xmark").font(.system(size: 12, weight: .bold)).foregroundStyle(.white.opacity(0.8)) }
            }
            .padding(.leading, 12).padding(.trailing, enabled ? 8 : 12).padding(.vertical, 6)
            .background(Color.black.opacity(0.55), in: Capsule())
            .overlay(Capsule().stroke(amber.opacity(0.8), lineWidth: 1))
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

private struct RecordChip: View {
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: "record.circle.fill").font(.system(size: 13)).foregroundStyle(recRed)
                Text(L("cr_record")).font(.dorr(12.5, .bold)).foregroundStyle(.white)
            }
            .padding(.horizontal, 12).padding(.vertical, 7)
            .background(Color.white.opacity(0.14), in: Capsule())
        }
        .buttonStyle(.plain)
    }
}

private struct PulseDot: View {
    let color: Color
    @State private var dim = false

    var body: some View {
        Circle().fill(color).frame(width: 9, height: 9).opacity(dim ? 0.25 : 1)
            .onAppear { withAnimation(.easeInOut(duration: 0.7).repeatForever(autoreverses: true)) { dim = true } }
    }
}

/// A question in front of the call: a scrim, a card, two answers.
private struct PromptCard: View {
    let title: String
    let body_: String
    let no: String
    let yes: String
    let onNo: () -> Void
    let onYes: () -> Void
    var extra: String?

    init(title: String, body: String, no: String, yes: String, onNo: @escaping () -> Void, onYes: @escaping () -> Void, extra: String? = nil) {
        self.title = title
        body_ = body
        self.no = no
        self.yes = yes
        self.onNo = onNo
        self.onYes = onYes
        self.extra = extra
    }

    var body: some View {
        ZStack {
            Color.black.opacity(0.62).ignoresSafeArea().onTapGesture {}
            VStack(spacing: 0) {
                Image(systemName: "record.circle.fill").font(.system(size: 24)).foregroundStyle(recRed)
                    .frame(width: 56, height: 56).background(recRed.opacity(0.16), in: Circle())
                Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(.white).multilineTextAlignment(.center).padding(.top, 14)
                Text(verbatim: body_).font(.dorr(14)).foregroundStyle(.white.opacity(0.78)).multilineTextAlignment(.center).padding(.top, 8)
                if let extra { Text(verbatim: extra).font(.dorr(12.5, .semibold)).foregroundStyle(amber).padding(.top, 8) }
                HStack(spacing: 10) {
                    Button(action: onNo) {
                        Text(verbatim: no).font(.dorr(15, .bold)).foregroundStyle(.white).frame(maxWidth: .infinity).frame(height: 48)
                            .background(Color.white.opacity(0.1), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    Button(action: onYes) {
                        Text(verbatim: yes).font(.dorr(15, .heavy)).foregroundStyle(.white).frame(maxWidth: .infinity).frame(height: 48)
                            .background(recRed, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                }
                .buttonStyle(.plain)
                .padding(.top, 20)
            }
            .padding(22)
            .frame(maxWidth: 420)
            .background(Color(hex: 0x1C1C22), in: RoundedRectangle(cornerRadius: 26, style: .continuous))
            .padding(24)
        }
        .transition(.opacity)
    }
}

// MARK: - camera backgrounds (spec 94)

/// None, blur, a ready-made one or my own photo — all on the phone.
private struct CallBackgroundSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var effects = CallEffects.shared
    let onDismiss: () -> Void
    @State private var photo: PhotosPickerItem?
    private let presets = CallEffects.presets.indices.map { ($0, CallEffects.presetImage($0)) }

    var body: some View {
        ChSheetCard {
            Text(L("ce_title")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            Text(L("ce_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 10) {
                    tile(L("ce_none"), on: effects.mode == .none, action: { pick(.none) }) {
                        Image(systemName: "nosign").font(.system(size: 26)).foregroundStyle(ch.mut)
                    }
                    tile(L("ce_blur"), on: effects.mode == .blur, action: { pick(.blur) }) {
                        Image(systemName: "aqi.medium").font(.system(size: 28)).foregroundStyle(ch.red)
                    }
                    ForEach(presets, id: \.0) { i, image in
                        tile(nil, on: effects.mode == .image && effects.picked == i, action: { pick(.image, image: image, preset: i) }) {
                            Image(uiImage: image).resizable().scaledToFill()
                        }
                    }
                    PhotosPicker(selection: $photo, matching: .images) {
                        tileBody(L("ce_photo"), on: effects.mode == .image && effects.picked == nil) {
                            Image(systemName: "photo.badge.plus").font(.system(size: 26)).foregroundStyle(ch.red)
                        }
                    }
                }
                .padding(.vertical, 2)
            }
            .padding(.top, 14)
            Text(L("ce_note")).font(.dorr(11.5)).foregroundStyle(ch.soft).padding(.top, 12)
        }
        .onChange(of: photo) { item in
            guard let item else { return }
            Task {
                if let data = try? await item.loadTransferable(type: Data.self), let image = UIImage(data: data) { pick(.image, image: image) }
            }
        }
    }

    private func pick(_ mode: CallEffects.Mode, image: UIImage? = nil, preset: Int? = nil) {
        CallController.shared.setBackground(mode, image: image, preset: preset)
        onDismiss()
    }

    private func tile<C: View>(_ label: String?, on: Bool, action: @escaping () -> Void, @ViewBuilder content: () -> C) -> some View {
        Button(action: action) { tileBody(label, on: on, content: content) }.buttonStyle(.plain)
    }

    private func tileBody<C: View>(_ label: String?, on: Bool, @ViewBuilder content: () -> C) -> some View {
        VStack(spacing: 4) {
            content()
                .frame(width: 70, height: 96)
                .background(ch.surfaceMuted)
                .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(on ? ch.red : ch.line, lineWidth: on ? 3 : 1))
            if let label { Text(verbatim: label).font(.dorr(11.5, .bold)).foregroundStyle(on ? ch.red : ch.ink) }
        }
    }
}

// MARK: - going live (spec 93)

let liveRed = Color(hex: 0xE11D48)

struct LivePulseDot: View {
    @State private var dim = false

    var body: some View {
        Circle().fill(.white).frame(width: 8, height: 8).opacity(dim ? 0.35 : 1)
            .onAppear { withAnimation(.easeInOut(duration: 0.7).repeatForever(autoreverses: true)) { dim = true } }
    }
}

/// In the call: go live — a title and who may watch.
private struct StartLiveSheet: View {
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    @State private var title = ""
    @State private var audience = "members"
    @State private var busy = false
    @State private var failure: String?

    var body: some View {
        ChSheetCard {
            Text(verbatim: "🔴 " + L("lv_go_live")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            Text(L("lv_go_live_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut)
            ChField(placeholder: L("lv_title_hint"), text: $title, limit: 120).padding(.top, 12)
            ForEach([("members", "lv_audience_members"), ("link", "lv_audience_link")], id: \.0) { k, label in
                Button { audience = k } label: {
                    HStack(spacing: 10) {
                        Image(systemName: audience == k ? "checkmark.circle.fill" : "circle").font(.system(size: 21)).foregroundStyle(audience == k ? ch.red : ch.soft)
                        Text(L(label)).font(.dorr(14.5, .bold)).foregroundStyle(ch.ink)
                        Spacer()
                    }
                    .padding(.vertical, 10).padding(.horizontal, 4).contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
            .padding(.top, 10)
            if let failure { Text(verbatim: failure).font(.dorr(12.5)).foregroundStyle(ch.danger).padding(.top, 4) }
            ChPrimaryButton(text: L("lv_start"), enabled: !busy) { start() }.padding(.top, 12)
        }
    }

    private func start() {
        guard let callId = CallController.shared.call?.id else { return }
        busy = true
        Task {
            do {
                let t = title.trimmingCharacters(in: .whitespacesAndNewlines)
                CallController.shared.live = try await VoiceApi.startLive(callId, title: t.isEmpty ? nil : t, audience: audience).data
                onDismiss()
            } catch {
                failure = error.apiFailure.message ?? L("ch_error_network")
            }
            busy = false
        }
    }
}

/// On top of the call while it's live: LIVE · viewers. Tap to share or end it.
struct LiveBadge: View {
    let live: LiveDto
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 0) {
                LivePulseDot()
                Text(verbatim: "LIVE").font(.dorr(12, .black)).padding(.leading, 6)
                Image(systemName: "eye.fill").font(.system(size: 12)).padding(.leading, 8)
                Text(verbatim: "\(live.viewers)").font(.dorr(12, .bold)).padding(.leading, 3)
            }
            .foregroundStyle(.white)
            .padding(.horizontal, 12).padding(.vertical, 6)
            .background(liveRed, in: Capsule())
        }
        .buttonStyle(.plain)
    }
}

/// Share the live's card into chats, copy its link, or end it.
private struct LiveShareSheet: View {
    @ObservedObject private var ch = Ch.shared
    let live: LiveDto
    let onDismiss: () -> Void
    @State private var chats: [ConversationDto]?
    @State private var picked: Set<String> = []
    @State private var note: String?

    var body: some View {
        ChSheetCard {
            Text(L("lv_share")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            Text(L(live.audience == "members" ? "lv_share_members" : "lv_share_link")).font(.dorr(12.5)).foregroundStyle(ch.mut)
            ScrollView {
                LazyVStack(spacing: 0) {
                    ForEach(chats ?? [], id: \.id) { c in
                        let on = picked.contains(c.id)
                        Button {
                            if on { picked.remove(c.id) } else { picked.insert(c.id) }
                        } label: {
                            HStack(spacing: 10) {
                                ChAvatar(url: c.avatar, name: c.title, key: c.peer?.key ?? c.id, size: 38, isGroup: c.isGroup)
                                Text(verbatim: c.title ?? "").font(.dorr(14, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                                Spacer()
                                Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 21)).foregroundStyle(on ? ch.red : ch.soft)
                            }
                            .padding(.vertical, 8).contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
            .frame(height: 300)
            .padding(.top, 10)
            if let note { Text(verbatim: note).font(.dorr(12.5)).foregroundStyle(ch.mut) }
            HStack(spacing: 8) {
                ChPrimaryButton(text: L("lv_send"), enabled: !picked.isEmpty) {
                    Task {
                        do {
                            _ = try await VoiceApi.shareLive(live.token, conversationIds: Array(picked))
                            CallController.shared.notice = L("lv_shared")
                            onDismiss()
                        } catch {
                            note = error.apiFailure.message ?? L("ch_error_network")
                        }
                    }
                }
                if live.audience == "link" {
                    Button {
                        UIPasteboard.general.string = live.link ?? "dorr://live/\(live.token)"
                        CallController.shared.notice = L("ch_copied")
                    } label: {
                        Text(L("lv_copy_link")).font(.dorr(13.5, .bold)).foregroundStyle(ch.red)
                            .padding(.horizontal, 16).padding(.vertical, 14).background(ch.red.opacity(0.1), in: Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.top, 10)
            Button {
                Task {
                    if (try? await VoiceApi.endLive(live.id).data) != nil { CallController.shared.live = nil }
                    onDismiss()
                }
            } label: {
                Text(L("lv_end")).font(.dorr(13.5, .bold)).foregroundStyle(ch.danger).frame(maxWidth: .infinity).padding(12)
            }
            .buttonStyle(.plain)
            .padding(.top, 10)
        }
        .task { chats = (try? await ChatApi.conversations(perPage: 40).data) ?? [] }
    }
}
