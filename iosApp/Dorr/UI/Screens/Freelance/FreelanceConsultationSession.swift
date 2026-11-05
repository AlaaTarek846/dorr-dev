import AVFoundation
import AVKit
import LiveKit
import SwiftUI

// androidApp `freelance/FreelanceConsultationSession.kt` — FL-L16, the consultation's video session on the client's side: the consent
// before booking, the session card on the consultation (the room's opening by the phone's clock, «enter», who is in, attendance, the
// recorded parts with download and watch, the freelancer-did-not-come complaint) and the room itself, full screen, on LiveKit like the
// chat's calls. Nothing asks the server on a timer: the end comes from `ends_at` on the phone's clock (corrected once by `server_time`),
// the other side's arrival from LiveKit, and the rest from the notifications (`freelance.consultation.session_*`, `recording_ready`)
// that reload the page once. If the app goes away the server ends the stay itself; coming back while time is left is a new join.

private let ssGreen = Color(hex: 0x16A34A)
private let ssAmber = Color(hex: 0xD97706)
private let ssRed = Color(hex: 0xDC2626)
private let ssSlate = Color(hex: 0x64748B)

private func ssHm(_ iso: String?) -> String {
    guard let d = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.dateFormat = "HH:mm"
    return f.string(from: d)
}

private func ssDay(_ iso: String?) -> String {
    guard let d = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.dateFormat = "EEEE d MMMM"
    return f.string(from: d)
}

/// "1:05:09" / "05:09" — a countdown or a length.
func ssClock(_ seconds: Int) -> String {
    let s = max(seconds, 0)
    return s >= 3600 ? String(format: "%d:%02d:%02d", s / 3600, (s % 3600) / 60, s % 60) : String(format: "%02d:%02d", s / 60, s % 60)
}

private func ssSize(_ bytes: Int?) -> String? {
    guard let b = bytes, b > 0 else { return nil }
    return b >= 1_048_576 ? String(format: "%.1f MB", Double(b) / 1_048_576) : "\(max(b / 1024, 1)) KB"
}

// MARK: - the consent (booking)

/// FL-L16: the required yes to the recording — booking (and a special offer's consultation) waits for it.
struct RecordingConsentRow: View {
    @Environment(\.wa) private var wa
    let checked: Bool
    let onChange: (Bool) -> Void

    var body: some View {
        Button { onChange(!checked) } label: {
            HStack(spacing: 10) {
                Image(systemName: checked ? "checkmark.square.fill" : "square").font(.system(size: 21)).foregroundStyle(checked ? ssGreen : wa.settingsMut)
                Text(L("css_consent")).font(.dorr(12.5, .semibold)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                    .multilineTextAlignment(.leading).frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(12)
            .background(checked ? ssGreen.opacity(0.10) : wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(checked ? ssGreen.opacity(0.4) : wa.accent.opacity(0.25), lineWidth: 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - the room (one at a time, app-wide)

enum ConsultSessionPhase { case joining, live, ended }

/// The one consultation session this phone can be in. It asks the server for a ticket, connects LiveKit, says it is in, and leaves on
/// «Leave», when LiveKit drops it (the server closes the room at the end) or when the time is over by the phone's corrected clock.
@MainActor
final class ConsultSession: NSObject, ObservableObject {
    static let shared = ConsultSession()

    @Published private(set) var uuid: String?
    @Published private(set) var phase: ConsultSessionPhase?
    @Published private(set) var join: ConsultJoinDto?
    /// The other side's name (from the consultation, then the ticket).
    @Published private(set) var otherName = ""
    @Published private(set) var otherInRoom = false
    @Published private(set) var micOn = true
    @Published private(set) var cameraOn = true
    @Published private(set) var speakerOn = true
    @Published private(set) var remote: CallVideo?
    @Published private(set) var local: CallVideo?
    @Published var error: String?
    /// Bumped each time the screen closes: the consultation page reads itself once more.
    @Published private(set) var closedCount = 0

    /// The server's clock minus the phone's, from the ticket's `server_time` (once per join).
    private var offset: TimeInterval = 0
    private(set) var room: Room?
    private var endTask: Task<Void, Never>?

    /// Now on the server's clock, by the phone's clock.
    func serverNow() -> Date { Date().addingTimeInterval(offset) }

    var endsAt: Date? { parseInstant(join?.endsAt) }

    /// Into the room: a ticket, LiveKit, then «I am in». `onError` gets the server's words when it says no.
    func start(_ consultation: String, other: String, onError: @escaping (String) -> Void) {
        if let phase, phase != .ended { return }
        uuid = consultation
        otherName = other
        otherInRoom = false
        micOn = true
        cameraOn = AVCaptureDevice.authorizationStatus(for: .video) == .authorized
        speakerOn = true
        error = nil
        join = nil
        remote = nil
        local = nil
        phase = .joining
        Task {
            let ticket: ConsultJoinDto
            do {
                guard let t = try await FreelanceConsultationsApi.joinSession(consultation).data else { throw NoCallJoin() }
                ticket = t
            } catch {
                // Not in yet (too early, over, closed…): nothing to leave — the card says why.
                phase = nil
                uuid = nil
                onError(error.apiFailure.message ?? L("fl_error_generic"))
                return
            }
            join = ticket
            if let n = ticket.other?.name, !n.isEmpty { otherName = n }
            otherInRoom = ticket.other?.inRoom ?? false
            offset = parseInstant(ticket.serverTime).map { $0.timeIntervalSinceNow } ?? 0
            await connect(ticket, onError: onError)
        }
    }

    private func connect(_ ticket: ConsultJoinDto, onError: @escaping (String) -> Void) async {
        let r = Room(delegate: self)
        room = r
        do {
            try await r.connect(url: ticket.url, token: ticket.token)
        } catch {
            self.error = error.localizedDescription
            close()
            onError(self.error ?? L("fl_error_generic"))
            return
        }
        if (try? await r.localParticipant.setMicrophone(enabled: micOn)) == nil { micOn = false }
        if cameraOn, (try? await r.localParticipant.setCamera(enabled: true, captureOptions: CameraCaptureOptions(position: .front))) == nil { cameraOn = false }
        AudioManager.shared.isSpeakerOutputPreferred = speakerOn
        phase = .live
        refresh()
        // The server starts the attendance (and the recording) only once we are really in.
        guard let id = uuid else { return }
        do {
            _ = try await FreelanceConsultationsApi.sessionConnected(id)
        } catch {
            let message = error.apiFailure.message ?? L("fl_error_generic")
            self.error = message
            leave()
            onError(message)
            return
        }
        // Out at the end, by the phone's clock corrected by the server's.
        endTask?.cancel()
        if let end = endsAt {
            let wait = max(end.timeIntervalSince(serverNow()), 0)
            endTask = Task { [weak self] in
                try? await Task.sleep(nanoseconds: UInt64(wait * 1_000_000_000))
                guard let self, !Task.isCancelled, self.phase == .live else { return }
                self.leave()
            }
        }
    }

    /// «Leave», the end of the time, or the room closed: the server hears it, LiveKit lets go, the page reads itself again.
    func leave() {
        if let id = uuid, let phase, phase != .ended { Task { _ = try? await FreelanceConsultationsApi.leaveSession(id) } }
        close()
    }

    private func close() {
        endTask?.cancel()
        if let r = room { Task { await r.disconnect() } }
        room = nil
        remote = nil
        local = nil
        phase = .ended
        closedCount += 1
        Task { [weak self] in
            try? await Task.sleep(nanoseconds: 1_200_000_000)
            guard let self, self.phase == .ended else { return }
            self.phase = nil
            self.uuid = nil
            self.join = nil
        }
    }

    func toggleMic() {
        micOn.toggle()
        Task { _ = try? await room?.localParticipant.setMicrophone(enabled: micOn); refresh() }
    }

    func toggleCamera() {
        if !cameraOn && AVCaptureDevice.authorizationStatus(for: .video) != .authorized { return }
        cameraOn.toggle()
        Task {
            if (try? await room?.localParticipant.setCamera(enabled: cameraOn, captureOptions: CameraCaptureOptions(position: .front))) == nil { cameraOn = false }
            refresh()
        }
    }

    func flipCamera() {
        guard let track = room?.localParticipant.firstCameraVideoTrack as? LocalVideoTrack, let capturer = track.capturer as? CameraCapturer else { return }
        Task { _ = try? await capturer.switchCameraPosition() }
    }

    /// The loudspeaker, else a headset / the earpiece — LiveKit's audio session follows it.
    func toggleSpeaker() {
        speakerOn.toggle()
        AudioManager.shared.isSpeakerOutputPreferred = speakerOn
    }

    fileprivate func refresh() {
        guard let r = room else { return }
        otherInRoom = !r.remoteParticipants.isEmpty
        remote = r.remoteParticipants.values.compactMap { p -> CallVideo? in
            guard let pub = p.firstCameraPublication, !pub.isMuted, let t = pub.track as? VideoTrack else { return nil }
            return CallVideo(key: p.identity?.stringValue ?? "", track: t, isLocal: false)
        }.first
        if cameraOn, let t = r.localParticipant.firstCameraVideoTrack {
            local = CallVideo(key: "me", track: t, isLocal: true)
        } else {
            local = nil
        }
    }
}

extension ConsultSession: RoomDelegate {
    private nonisolated func changed(_ room: Room) {
        Task { @MainActor in if self.room === room { self.refresh() } }
    }

    nonisolated func room(_ room: Room, participantDidConnect participant: RemoteParticipant) { changed(room) }
    nonisolated func room(_ room: Room, participantDidDisconnect participant: RemoteParticipant) { changed(room) }
    nonisolated func room(_ room: Room, participant: Participant, trackPublication: TrackPublication, didUpdateIsMuted isMuted: Bool) { changed(room) }
    nonisolated func room(_ room: Room, participant: RemoteParticipant, didSubscribeTrack publication: RemoteTrackPublication) { changed(room) }
    nonisolated func room(_ room: Room, participant: RemoteParticipant, didUnsubscribeTrack publication: RemoteTrackPublication) { changed(room) }
    nonisolated func room(_ room: Room, participant: LocalParticipant, didPublishTrack publication: LocalTrackPublication) { changed(room) }

    /// The server closed the room (time over, or the staff), or the network is gone for good.
    nonisolated func room(_ room: Room, didDisconnectWithError error: LiveKitError?) {
        Task { @MainActor in
            if self.room === room, self.phase == .live || self.phase == .joining { self.leave() }
        }
    }
}

// MARK: - the session card (on the consultation)

/// The session on the consultation page: when the room opens, «enter», who is in, who came when, the recordings, and «did not come».
@MainActor
struct ConsultSessionCard: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var session = ConsultSession.shared
    let uuid: String
    let c: ConsultDto
    let s: ConsultSessionDto
    let onChanged: (ConsultDto?) -> Void
    @State private var error: String?
    @State private var noShowAsk = false
    @State private var busy = false
    @State private var fetching: String?
    @State private var playing: PlayingPart?
    @State private var now = Date()

    private struct PlayingPart: Identifiable {
        let part: Int
        let url: URL
        var id: String { url.absoluteString }
    }

    private var other: String { c.freelancer?.name ?? "" }
    private var opensAt: Date? { parseInstant(s.opensAt) }
    private var endsAt: Date? { parseInstant(s.endsAt) }
    private var booked: Bool { c.status == "booked" }
    private var beforeOpen: Bool { booked && s.closed == nil && (opensAt.map { now < $0 } ?? false) }
    /// By the phone's clock the room is open: «enter» shows (the server has the last word, and its reason is shown as it comes).
    private var openByClock: Bool {
        guard booked, s.available == true, s.closed == nil, let o = opensAt, let e = endsAt else { return false }
        return now >= o && now < e
    }
    /// The next moment the card changes on its own (the room opens, or its time is over): one read then, nothing in between.
    private var next: Date? {
        if beforeOpen { return opensAt }
        if booked, s.roomOpen == true, let e = endsAt, now < e { return e }
        return nil
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 8) {
                Image(systemName: "video.bubble.left.fill").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.accent)
                Text(L("css_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                if s.recorded != false && s.recordingAvailable == true {
                    SsPill(text: L("css_recorded"), color: ssRed)
                } else if booked {
                    SsPill(text: L("css_not_recorded"), color: ssSlate)
                }
            }
            if let error { FlNotice(text: error, icon: "xmark", color: ssRed) }
            state
            presence
            attendance
            recordings
            if s.can?.noShow == true {
                FlAction(text: L("css_no_show"), icon: "exclamationmark.bubble.fill", tone: .warn) { noShowAsk = true }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(16)
        .settingsSurface(20)
        .task(id: next) {
            guard let next else { return }
            let wait = max(next.timeIntervalSinceNow, 0) + 1.5
            try? await Task.sleep(nanoseconds: UInt64(wait * 1_000_000_000))
            guard !Task.isCancelled else { return }
            onChanged(nil)
        }
        // The countdown on show ticks by the phone's clock (and each fresh read restarts from it).
        .task(id: s) { now = Date() }
        .task(id: beforeOpen) {
            while beforeOpen && !Task.isCancelled {
                now = Date()
                try? await Task.sleep(nanoseconds: 1_000_000_000)
            }
        }
        .dorrDialog(isPresented: $noShowAsk) {
            ConfirmDialog(icon: "exclamationmark.bubble.fill", title: L("css_no_show_title"), message: L("css_no_show_body"), loading: busy,
                          confirmLabel: L("css_no_show_confirm"), onConfirm: sendNoShow, onDismiss: { noShowAsk = false })
        }
        .fullScreenCover(item: $playing) { p in RecordingPlayer(part: p.part, url: p.url) { playing = nil } }
    }

    @ViewBuilder
    private var state: some View {
        if booked && s.available != true {
            FlNotice(text: L("css_unavailable"), icon: "info.circle.fill", color: ssAmber)
        } else if booked && s.closed?.by == "staff" && (endsAt.map { now < $0 } ?? true) {
            FlNotice(text: L("css_closed_staff"), icon: "lock.fill", color: ssAmber)
        } else if beforeOpen {
            HStack(spacing: 12) {
                Image(systemName: "hourglass").font(.system(size: 20)).foregroundStyle(wa.accent)
                Text(verbatim: L("css_opens_in", "\u{200E}" + ssClock(Int((opensAt ?? now).timeIntervalSince(now)))))
                    .font(.dorr(15, .heavy)).foregroundStyle(wa.accent).frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(14)
            .background(wa.accent.opacity(wa.night ? 0.2 : 0.08), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            Text(L("css_opens_rule")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
        } else if s.can?.join == true || openByClock {
            Text(L("css_join_hint")).font(.dorr(12, .bold)).foregroundStyle(ssGreen)
            FlAction(text: L("css_join"), icon: "video.fill", tone: .primary, loading: session.uuid == uuid && session.phase == .joining, action: enter)
        } else if booked, let e = endsAt, now >= e {
            FlNotice(text: L("css_over"), icon: "timer", color: ssSlate)
        }
    }

    /// Who is in the room now.
    @ViewBuilder
    private var presence: some View {
        if s.roomOpen == true {
            Text(L("css_in_room")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
            FlowLayout(spacing: 8, lineSpacing: 8) {
                if s.inRoom?.client == true { SsPill(text: L("css_you"), color: ssGreen) }
                if s.inRoom?.freelancer == true { SsPill(text: other.isEmpty ? L("css_freelancer") : other, color: ssGreen) }
                if s.inRoom?.client != true && s.inRoom?.freelancer != true { SsPill(text: L("css_nobody"), color: ssSlate) }
            }
        }
    }

    /// Who came when.
    @ViewBuilder
    private var attendance: some View {
        let list = s.attendance ?? []
        if !list.isEmpty {
            HStack(spacing: 6) {
                Image(systemName: "person.2.fill").font(.system(size: 13)).foregroundStyle(wa.settingsMut)
                Text(L("css_attendance")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
            }
            ForEach(Array(list.enumerated()), id: \.offset) { _, a in
                HStack {
                    Text(verbatim: a.side == "client" ? L("css_you") : (other.isEmpty ? L("css_freelancer") : other)).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    Spacer(minLength: 8)
                    Text(verbatim: a.leftAt == nil ? L("css_still_in", "\u{200E}" + ssHm(a.joinedAt)) : L("css_joined_left", "\u{200E}" + ssHm(a.joinedAt), "\u{200E}" + ssHm(a.leftAt)))
                        .font(.dorr(12, .semibold)).foregroundStyle(a.leftAt == nil ? ssGreen : wa.settingsMut)
                }
            }
        }
    }

    /// The recorded parts.
    @ViewBuilder
    private var recordings: some View {
        let parts = s.recordings ?? []
        if !parts.isEmpty || s.recordingsDeletedAt != nil {
            Text(L("css_recordings")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
            ForEach(parts) { r in
                RecordingRow(r: r, fetching: fetching,
                             onDownload: { withFreshLink(r, watch: false) { url in download(url, part: r.part ?? 1) } },
                             onWatch: { withFreshLink(r, watch: true) { url in playing = PlayingPart(part: r.part ?? 1, url: url) } })
            }
            if s.recordingsDeletedAt != nil {
                FlNotice(text: L("css_deleted_all"), icon: "calendar.badge.exclamationmark", color: ssSlate)
            } else if s.recordingsHeld == true {
                FlNotice(text: L("css_held"), icon: "lock.fill", color: ssAmber)
            } else if let at = s.recordingsDeleteAt {
                FlNotice(text: L("css_delete_at", ssDay(at)), icon: "info.circle.fill", color: wa.accent)
            }
        }
    }

    private func enter() {
        error = nil
        Task {
            guard await CallPermissions.ask(video: true) else {
                error = L("css_mic_needed")
                return
            }
            ConsultSession.shared.start(uuid, other: other) { message in
                error = message
                AppToast.show(message, long: true)
            }
        }
    }

    /// The links are good for 15 minutes: they are read fresh right before use.
    private func withFreshLink(_ r: ConsultRecordingDto, watch: Bool, use: @escaping (URL) -> Void) {
        fetching = r.uuid + (watch ? ":w" : ":d")
        Task {
            do {
                let fresh = try await FreelanceConsultationsApi.session(uuid).data?.recordings?.first { $0.uuid == r.uuid }
                // Signed on the path only (`signed:relative`): the dev host rewrite of other media applies.
                if let url = ApiClient.mediaUrl(watch ? fresh?.watchUrl : fresh?.downloadUrl) { use(url) } else { error = L("fl_error_generic") }
            } catch {
                self.error = error.apiFailure.message ?? L("fl_error_generic")
            }
            fetching = nil
        }
    }

    /// Saved through the system share sheet (Files, AirDrop…), like the other files the app hands out.
    private func download(_ url: URL, part: Int) {
        fetching = "download:\(part)"
        Task {
            do {
                var request = URLRequest(url: url)
                request.setValue("1", forHTTPHeaderField: "ngrok-skip-browser-warning")
                let (tmp, response) = try await URLSession.shared.download(for: request)
                guard (response as? HTTPURLResponse).map({ (200..<300).contains($0.statusCode) }) ?? false else { throw URLError(.badServerResponse) }
                let file = FileManager.default.temporaryDirectory.appendingPathComponent("consultation-\(c.uuid.prefix(8))-part-\(part).mp4")
                try? FileManager.default.removeItem(at: file)
                try FileManager.default.moveItem(at: tmp, to: file)
                presentShare(file)
            } catch {
                AppToast.show(L("css_download_failed"))
            }
            fetching = nil
        }
    }

    private func sendNoShow() {
        busy = true
        Task {
            do {
                let fresh = try await FreelanceConsultationsApi.noShow(uuid).data
                AppToast.show(L("css_no_show_done"))
                error = nil
                onChanged(fresh)
            } catch {
                self.error = error.apiFailure.message ?? L("fl_error_generic")
            }
            busy = false
            noShowAsk = false
        }
    }
}

private struct SsPill: View {
    @Environment(\.wa) private var wa
    let text: String
    let color: Color

    var body: some View {
        HStack(spacing: 6) {
            Circle().fill(color).frame(width: 6, height: 6)
            Text(verbatim: text).font(.dorr(11.5, .bold)).foregroundStyle(color).lineLimit(1)
        }
        .padding(.horizontal, 10).padding(.vertical, 4)
        .background(color.opacity(wa.night ? 0.22 : 0.12), in: Capsule())
    }
}

/// One recorded part: its length, size and state; once stored, «Download» and «Watch».
private struct RecordingRow: View {
    @Environment(\.wa) private var wa
    let r: ConsultRecordingDto
    let fetching: String?
    let onDownload: () -> Void
    let onWatch: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 8) {
                Image(systemName: "video.fill").font(.system(size: 14)).foregroundStyle(wa.accent)
                Text(L("css_part", r.part ?? 1)).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk)
                Text(verbatim: [r.durationSeconds.flatMap { $0 > 0 ? "\u{200E}" + ssClock($0) : nil }, ssSize(r.sizeBytes)].compactMap { $0 }.joined(separator: " · "))
                    .font(.dorr(12)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                switch r.status {
                case "stored": EmptyView()
                case "recording": SsPill(text: L("css_recording_now"), color: ssRed)
                case "deleted": SsPill(text: L("css_deleted"), color: ssSlate)
                default: SsPill(text: L("css_preparing"), color: ssAmber)
                }
            }
            if r.status == "stored" {
                HStack(spacing: 8) {
                    FlAction(text: L("css_watch"), icon: "play.fill", tone: .primary, loading: fetching == r.uuid + ":w", action: onWatch)
                    FlAction(text: L("css_download"), icon: "arrow.down.circle.fill", loading: fetching == r.uuid + ":d" || fetching == "download:\(r.part ?? 1)", action: onDownload)
                }
            }
        }
        .padding(12)
        .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// A recorded part played inside the app (the link supports Range, so it streams and seeks).
private struct RecordingPlayer: View {
    let part: Int
    let url: URL
    let onClose: () -> Void
    @State private var player: AVPlayer?

    var body: some View {
        ZStack(alignment: .top) {
            Color.black.ignoresSafeArea()
            if let player { VideoPlayer(player: player).ignoresSafeArea(edges: .bottom) }
            HStack {
                Text(L("css_part", part)).font(.dorr(15, .heavy)).foregroundStyle(.white)
                Spacer()
                Button(action: onClose) {
                    Image(systemName: "xmark").font(.system(size: 17, weight: .bold)).foregroundStyle(.white)
                        .frame(width: 40, height: 40).background(Color.white.opacity(0.16), in: Circle())
                }
                .accessibilityLabel(L("css_close"))
            }
            .padding(12)
        }
        .onAppear {
            let p = AVPlayer(url: url)
            player = p
            p.play()
        }
        .onDisappear { player?.pause() }
    }
}

// MARK: - the room, full screen

/// In the session: their video (or who we wait for), mine in a corner, the time left, the warning, REC, and the controls.
struct ConsultSessionScreen: View {
    @ObservedObject private var session = ConsultSession.shared
    @State private var leaving = false

    var body: some View {
        let phase = session.phase ?? .ended
        ZStack {
            LinearGradient(colors: [Color(hex: 0x0B1220), Color(hex: 0x10234F), Color(hex: 0x0B1220)], startPoint: .top, endPoint: .bottom).ignoresSafeArea()
            if let remote = session.remote {
                VideoSurface(video: remote).ignoresSafeArea()
            } else {
                VStack(spacing: 0) {
                    Text(verbatim: String(session.otherName.prefix(1)).isEmpty ? "?" : String(session.otherName.prefix(1)))
                        .font(.dorr(42, .black)).foregroundStyle(.white)
                        .frame(width: 104, height: 104).background(Color.white.opacity(0.14), in: Circle())
                    Text(verbatim: session.otherName).font(.dorr(22, .black)).foregroundStyle(.white).multilineTextAlignment(.center).padding(.top, 16)
                    Text(verbatim: waitingLine(phase)).font(.dorr(14)).foregroundStyle(.white.opacity(0.75)).multilineTextAlignment(.center).padding(.top, 6)
                }
                .padding(24)
            }
            // My camera in the corner.
            if let mine = session.local {
                VStack {
                    HStack {
                        Spacer()
                        VideoSurface(video: mine).frame(width: 104, height: 150)
                            .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
                    }
                    Spacer()
                }
                .padding(.top, 70).padding(.horizontal, 16)
            }
            VStack(spacing: 0) {
                TimelineView(.periodic(from: .now, by: 1)) { _ in top(phase) }
                Spacer()
                if phase != .ended { controls }
            }
        }
        .onAppear { UIApplication.shared.isIdleTimerDisabled = true }
        .onDisappear { UIApplication.shared.isIdleTimerDisabled = false }
        .dorrDialog(isPresented: $leaving) {
            ConfirmDialog(icon: "phone.down.fill", title: L("css_leave_title"), message: L("css_leave_body"), confirmLabel: L("css_leave"),
                          onConfirm: { leaving = false; session.leave() }, onDismiss: { leaving = false })
        }
    }

    private func waitingLine(_ phase: ConsultSessionPhase) -> String {
        switch phase {
        case .joining: return L("css_connecting")
        case .ended: return session.error ?? L("css_ended")
        case .live: return session.otherInRoom ? L("css_other_in", session.otherName) : L("css_waiting_for", session.otherName)
        }
    }

    /// The top: REC, the time left, and the warning near the end (by the phone's clock corrected by the server's).
    @ViewBuilder
    private func top(_ phase: ConsultSessionPhase) -> some View {
        let left = session.endsAt.map { Int($0.timeIntervalSince(session.serverNow())) }
        let warnAt = (session.join?.warnBeforeEndMinutes ?? 5) * 60
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 8) {
                if session.join?.recording == true {
                    HStack(spacing: 6) {
                        Circle().fill(Color(hex: 0xEF4444)).frame(width: 9, height: 9)
                        Text(L("css_recording_now")).font(.dorr(12, .bold)).foregroundStyle(.white)
                    }
                    .padding(.horizontal, 10).padding(.vertical, 6)
                    .background(Color.black.opacity(0.45), in: Capsule())
                }
                Spacer()
                if let left, phase == .live {
                    HStack(spacing: 6) {
                        Image(systemName: "timer").font(.system(size: 13)).foregroundStyle(left <= warnAt ? Color(hex: 0xFBBF24) : .white)
                        Text(verbatim: L("css_left_time", "\u{200E}" + ssClock(left))).font(.dorr(13, .heavy)).foregroundStyle(.white)
                    }
                    .padding(.horizontal, 12).padding(.vertical, 6)
                    .background(Color.black.opacity(0.45), in: Capsule())
                }
            }
            if let left, phase == .live, left > 0, left <= warnAt {
                HStack(spacing: 8) {
                    Image(systemName: "exclamationmark.triangle.fill").font(.system(size: 15)).foregroundStyle(.white)
                    Text(L("css_ending_warn", (left + 59) / 60)).font(.dorr(13, .bold)).foregroundStyle(.white)
                }
                .padding(.horizontal, 12).padding(.vertical, 9)
                .background(Color(hex: 0xD97706).opacity(0.92), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
        }
        .padding(.horizontal, 16).padding(.vertical, 12)
    }

    private var controls: some View {
        HStack {
            toggle(session.micOn ? "mic.fill" : "mic.slash.fill", active: !session.micOn) { session.toggleMic() }
            Spacer()
            toggle(session.cameraOn ? "video.fill" : "video.slash.fill", active: !session.cameraOn) { session.toggleCamera() }
            Spacer()
            toggle("arrow.triangle.2.circlepath.camera.fill", active: false, enabled: session.cameraOn) { session.flipCamera() }
            Spacer()
            toggle("speaker.wave.2.fill", active: session.speakerOn) { session.toggleSpeaker() }
            Spacer()
            Button { leaving = true } label: {
                Image(systemName: "phone.down.fill").font(.system(size: 23)).foregroundStyle(.white)
                    .frame(width: 58, height: 58).background(Color(hex: 0xE53935), in: Circle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("css_leave"))
        }
        .padding(.horizontal, 18).padding(.vertical, 12)
        .background(Color.white.opacity(0.12), in: RoundedRectangle(cornerRadius: 36, style: .continuous))
        .padding(16)
    }

    private func toggle(_ icon: String, active: Bool, enabled: Bool = true, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 20)).foregroundStyle(active ? .black : .white.opacity(enabled ? 1 : 0.4))
                .frame(width: 50, height: 50).background(active ? Color.white : Color.white.opacity(enabled ? 0.16 : 0.06), in: Circle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}
