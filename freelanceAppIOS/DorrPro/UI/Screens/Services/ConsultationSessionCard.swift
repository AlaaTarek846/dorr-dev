import AVKit
import SwiftUI

// freelanceApp `ui/screens/ConsultationSessionCard.kt`.
//
// FL-L16, the consultation's video session on the booking's page: before the room opens a countdown (the phone's clock, corrected once
// by the server's), then a big "join" while it is open, who is in the room, that it is recorded, the attendance, and after it the
// recorded parts — each one downloaded (saved through the share sheet) or watched in the app — with the day they are deleted. Read when
// the page opens and when a notice arrives; nothing asks the server on a timer (the countdown only reads the clock).

private func sesHm(_ at: Date?) -> String {
    guard let at else { return "" }
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.timeZone = .current
    f.dateFormat = "HH:mm"
    return f.string(from: at)
}

private func sesDay(_ at: Date?) -> String {
    guard let at else { return "" }
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.timeZone = .current
    f.dateFormat = "EEEE d MMMM"
    return f.string(from: at)
}

private func sesMs(_ d: Date) -> Int { Int(d.timeIntervalSince1970 * 1000) }

/// "1:02:05" / "12:05" — digits Latin.
func sesClock(_ totalSeconds: Int) -> String {
    let s = max(totalSeconds, 0)
    let h = s / 3600, m = (s % 3600) / 60, sec = s % 60
    return h > 0 ? String(format: "%d:%02d:%02d", h, m, sec) : String(format: "%02d:%02d", m, sec)
}

private let sesRed = Color(hex: 0xDC2626)

/// The session card. `onJoin` opens the session screen. Shown once the booking is taken (or when it left recordings behind).
@MainActor
struct ConsultationSessionCard: View {
    let c: ConsultationDto
    let onJoin: () -> Void

    @Environment(\.wa) private var wa
    @ObservedObject private var call = SessionCallController.shared
    // Only the clock ticks (once a second, while a countdown or an open room is on screen) — never a request.
    @State private var nowMs = ServerClock.nowMs

    var body: some View {
        if let s = c.session {
            let taken = ["booked", "completed", "disputed", "refunded"].contains(c.status ?? "")
            if taken || !(s.recordings ?? []).isEmpty || !(s.attendance ?? []).isEmpty {
                card(s)
            }
        }
    }

    @ViewBuilder
    private func card(_ s: ConsultationSessionDto) -> some View {
        let opensAt = parseInstant(s.opensAt)
        let startsAt = parseInstant(s.startsAt ?? c.startsAt)
        let endsAt = parseInstant(s.endsAt ?? c.endsAt)
        let live = c.status == "booked" && s.closed == nil && endsAt != nil && nowMs < sesMs(endsAt!)
        let beforeOpen = live && opensAt != nil && nowMs < sesMs(opensAt!)
        // The server said "join" when the page was read; the room also opens on its own at `opens_at` by the clock.
        let canJoin = (s.available ?? false) && live && !beforeOpen && ((s.can?.join ?? false) || (opensAt.map { nowMs >= sesMs($0) } ?? false))
        let inThisSession = call.isIn(c.uuid ?? "")

        InfoCard(title: L("ses_card_title"), icon: "video.bubble.left.fill") {
            if s.recorded ?? true {
                HStack(spacing: 8) {
                    // Recording only counts when the room can really record (same rule as the client app).
                    if s.recordingAvailable ?? false {
                        SvcPill(text: L("ses_recorded"), tone: SvcTone(ink: sesRed, fill: sesRed.opacity(wa.night ? 0.22 : 0.1)))
                    } else if live {
                        SvcPill(text: L("ses_not_recorded"), tone: serviceTone("paused", night: wa.night))
                    }
                    if s.recordingsHeld ?? false { SvcPill(text: L("ses_held"), tone: serviceTone("pending_review", night: wa.night)) }
                }
            }

            if beforeOpen, let opensAt {
                SesCountdown(opensAt: opensAt, startsAt: startsAt, nowMs: nowMs)
            } else if inThisSession || canJoin {
                SesJoinButton(text: L(inThisSession ? "ses_back_to" : "ses_join"), action: onJoin)
                SesNote(icon: "info.circle.fill", text: L("ses_room_open_note", sesHm(endsAt)), tone: SvcTone(ink: wa.red, fill: wa.red.opacity(0.08)))
            } else if live && !(s.available ?? false) {
                SesNote(icon: "info.circle.fill", text: L("ses_unavailable"), tone: serviceTone("pending_review", night: wa.night))
            } else if s.closed?.by == "staff" {
                SesNote(icon: "lock.fill", text: L("ses_closed_staff"), tone: serviceTone("paused", night: wa.night))
            } else if let endsAt, nowMs >= sesMs(endsAt) {
                SesNote(icon: "hourglass", text: L("ses_over"), tone: serviceTone("paused", night: wa.night))
            }

            if live {
                Text(L(s.recordingAvailable ?? false ? "ses_recorded_note" : "ses_recording_off"))
                    .font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                SesInRoom(s: s)
            }

            if !(s.attendance ?? []).isEmpty { SesAttendance(s: s) }
            SesRecordings(uuid: c.uuid ?? "", s: s)
        }
        .task(id: live) {
            while live && !Task.isCancelled {
                nowMs = ServerClock.nowMs
                try? await Task.sleep(nanoseconds: 1_000_000_000)
            }
        }
    }
}

private struct SesCountdown: View {
    @Environment(\.wa) private var wa
    let opensAt: Date
    let startsAt: Date?
    let nowMs: Int

    var body: some View {
        let left = (sesMs(opensAt) - nowMs) / 1000
        let days = left / 86_400
        let before = startsAt.map { (sesMs($0) - sesMs(opensAt)) / 60_000 } ?? 5
        VStack(spacing: 4) {
            Text(L("ses_opens_in")).font(.dorr(13, .bold)).foregroundStyle(.white.opacity(0.9))
            Text(verbatim: "\u{200E}" + (days > 0 ? "\(days)d " : "") + sesClock(left % 86_400))
                .font(.dorr(28, .heavy)).foregroundStyle(.white)
            Text(L("ses_opens_at", "\(sesDay(opensAt)) \(sesHm(opensAt))", before))
                .font(.dorr(12)).foregroundStyle(.white.opacity(0.9)).multilineTextAlignment(.center).lineSpacing(4)
        }
        .padding(16)
        .frame(maxWidth: .infinity)
        .background(LinearGradient(colors: [wa.red, wa.red.opacity(0.78)], startPoint: .topLeading, endPoint: .bottomTrailing),
                    in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}

private struct SesJoinButton: View {
    let text: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 10) {
                Image(systemName: "video.bubble.left.fill").font(.system(size: 20))
                Text(verbatim: text).font(.dorr(17, .heavy))
            }
            .foregroundStyle(.white)
            .frame(maxWidth: .infinity, minHeight: 58)
            .padding(.horizontal, 16)
            .background(Color(hex: 0x16A34A), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// Who is in the room right now: the client and me.
private struct SesInRoom: View {
    @Environment(\.wa) private var wa
    let s: ConsultationSessionDto

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: "person.2.fill").font(.system(size: 15)).foregroundStyle(wa.red)
            Text(L("ses_in_room_title")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
        }
        HStack(spacing: 8) {
            presence(L("ses_side_client"), s.inRoom?.client ?? false)
            presence(L("ses_side_me"), s.inRoom?.freelancer ?? false)
        }
    }

    private func presence(_ who: String, _ inside: Bool) -> some View {
        SvcPill(text: "\(who) · " + L(inside ? "ses_in_room_yes" : "ses_in_room_no"),
                tone: serviceTone(inside ? "published" : "paused", night: wa.night))
    }
}

private struct SesAttendance: View {
    @Environment(\.wa) private var wa
    let s: ConsultationSessionDto

    var body: some View {
        SesLine()
        Text(L("ses_attendance")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
        ForEach(Array((s.attendance ?? []).enumerated()), id: \.offset) { _, a in
            let me = a.side == "freelancer"
            let left = parseInstant(a.leftAt)
            HStack(spacing: 8) {
                Circle().fill(me ? wa.red : Color(hex: 0x0EA5E9)).frame(width: 8, height: 8)
                Text(L(me ? "ses_side_me" : "ses_side_client")).font(.dorr(12.5, .semibold)).foregroundStyle(wa.settingsInk)
                    .frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: "\u{200E}\(sesHm(parseInstant(a.joinedAt))) → " + (left != nil ? sesHm(left) : L("ses_still_in")))
                    .font(.dorr(12.5)).foregroundStyle(wa.settingsMut)
            }
        }
    }
}

@MainActor
private struct SesRecordings: View {
    @Environment(\.wa) private var wa
    let uuid: String
    let s: ConsultationSessionDto
    @State private var watching: URL?
    @State private var busy: String?

    var body: some View {
        let parts = s.recordings ?? []
        if !parts.isEmpty || s.recordingsDeletedAt != nil {
            SesLine()
            HStack(spacing: 8) {
                Image(systemName: "film.stack.fill").font(.system(size: 15)).foregroundStyle(wa.red)
                Text(L("ses_recordings")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
            }
            ForEach(parts) { part in
                SesRecordingRow(r: part, busy: busy != nil && busy == part.uuid,
                                onDownload: { fresh(part) { p in download(p) } },
                                onWatch: {
                                    fresh(part) { p in
                                        if let url = Api.mediaUrl(p.watchUrl) { watching = url } else { AppToast.show(L("ses_link_failed")) }
                                    }
                                })
            }
            if let deleted = s.recordingsDeletedAt {
                SesNote(icon: "trash.fill", text: L("ses_deleted_all", sesDay(parseInstant(deleted))), tone: serviceTone("paused", night: wa.night))
            } else if s.recordingsHeld ?? false {
                SesNote(icon: "lock.fill", text: L("ses_held_note"), tone: serviceTone("pending_review", night: wa.night))
            } else if let at = s.recordingsDeleteAt {
                SesNote(icon: "trash.fill", text: L("ses_delete_at", sesDay(parseInstant(at))), tone: serviceTone("pending_review", night: wa.night))
            }
            Color.clear.frame(height: 0)
                .fullScreenCover(isPresented: Binding(get: { watching != nil }, set: { if !$0 { watching = nil } })) {
                    if let watching { SesRecordingPlayer(url: watching) { self.watching = nil } }
                }
        }
    }

    /// The links are good for a few minutes only: a fresh one is read when it is used (one request, on a tap).
    private func fresh(_ part: SessionRecordingDto, use: @escaping (SessionRecordingDto) -> Void) {
        busy = part.uuid
        Task {
            let r = await ConsultationSessionApi.session(uuid)
            busy = nil
            let now = r.value?.recordings?.first { $0.uuid == part.uuid }
            if let now, now.status == "stored" { use(now) } else { AppToast.show(L("ses_link_failed")) }
        }
    }

    /// Android hands the part to the system's download manager; here it is fetched (the link is signed for this freelancer and a few
    /// minutes, so no sign-in header is needed) and saved through the share sheet (Files, Photos…).
    private func download(_ p: SessionRecordingDto) {
        guard let url = Api.mediaUrl(p.downloadUrl) else {
            AppToast.show(L("ses_link_failed"))
            return
        }
        let name = "consultation-\(uuid.prefix(8))-part-\(p.part ?? 1).mp4"
        AppToast.show(L("ses_download_started"))
        Task {
            do {
                var request = URLRequest(url: url)
                request.setValue("1", forHTTPHeaderField: "ngrok-skip-browser-warning")
                let (tmp, response) = try await URLSession.shared.download(for: request)
                guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode) else { throw URLError(.badServerResponse) }
                let file = FileManager.default.temporaryDirectory.appendingPathComponent(name)
                try? FileManager.default.removeItem(at: file)
                try FileManager.default.moveItem(at: tmp, to: file)
                sesShare(file)
            } catch {
                AppToast.show(L("ses_link_failed"))
            }
        }
    }
}

/// The share sheet over whatever is on top.
@MainActor private func sesShare(_ file: URL) {
    guard let scene = UIApplication.shared.connectedScenes.compactMap({ $0 as? UIWindowScene }).first,
          var top = scene.windows.first(where: \.isKeyWindow)?.rootViewController else { return }
    while let next = top.presentedViewController { top = next }
    let sheet = UIActivityViewController(activityItems: [file], applicationActivities: nil)
    sheet.popoverPresentationController?.sourceView = top.view
    top.present(sheet, animated: true)
}

private struct SesRecordingRow: View {
    @Environment(\.wa) private var wa
    let r: SessionRecordingDto
    let busy: Bool
    let onDownload: () -> Void
    let onWatch: () -> Void

    var body: some View {
        let (label, tone): (String, SvcTone) = {
            switch r.status {
            case "stored": return (L("ses_rec_ready"), serviceTone("published", night: wa.night))
            case "recording": return (L("ses_rec_recording"), SvcTone(ink: sesRed, fill: sesRed.opacity(0.1)))
            case "deleted": return (L("ses_rec_deleted"), serviceTone("paused", night: wa.night))
            default: return (L("ses_rec_processing"), serviceTone("pending_review", night: wa.night))
            }
        }()
        let facts = [
            r.durationSeconds.map { "\u{200E}" + sesClock($0) },
            r.sizeBytes.flatMap { $0 > 0 ? L("ses_size_mb", String(format: "%.1f", Double($0) / 1_048_576)) : nil },
        ].compactMap { $0 }
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 8) {
                Image(systemName: r.status == "recording" ? "record.circle.fill" : "video.bubble.left.fill")
                    .font(.system(size: 15)).foregroundStyle(tone.ink)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("ses_part", r.part ?? 1)).font(.dorr(13.5, .heavy)).foregroundStyle(wa.settingsInk)
                    if !facts.isEmpty { Text(verbatim: facts.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                SvcPill(text: label, tone: tone)
            }
            if r.status == "stored" {
                HStack(spacing: 8) {
                    SesSmallAction(text: L("ses_watch"), icon: "play.fill", enabled: !busy, action: onWatch)
                    SesSmallAction(text: L("ses_download"), icon: "arrow.down.to.line", enabled: !busy, action: onDownload)
                    if busy { ProgressView().tint(wa.red).scaleEffect(0.8) }
                }
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.settingsMut.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

private struct SesSmallAction: View {
    @Environment(\.wa) private var wa
    let text: String
    let icon: String
    let enabled: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 15))
                Text(verbatim: text).font(.dorr(13, .bold))
            }
            .foregroundStyle(wa.red)
            .frame(maxWidth: .infinity, minHeight: 40)
            .padding(.horizontal, 10)
            .background(wa.red.opacity(enabled ? 0.12 : 0.05), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

private struct SesNote: View {
    let icon: String
    let text: String
    let tone: SvcTone

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: icon).font(.system(size: 15)).foregroundStyle(tone.ink).frame(width: 18, height: 18)
            Text(verbatim: text).font(.dorr(12.5, .semibold)).foregroundStyle(tone.ink).lineSpacing(4)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(tone.fill, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

private struct SesLine: View {
    @Environment(\.wa) private var wa

    var body: some View {
        Rectangle().fill(wa.night ? wa.line : Color(hex: 0xEEF0F3)).frame(height: 1)
    }
}

/// Under "accept" on a request: accepting is agreeing to the recording.
struct SessionConsentLine: View {
    @Environment(\.wa) private var wa

    var body: some View {
        Text(L("ses_accept_consent")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(5)
            .frame(maxWidth: 520, alignment: .leading)
            .frame(maxWidth: .infinity)
    }
}

/// Watching a part in the app: the phone's own player streaming the signed link (the server answers range requests).
private struct SesRecordingPlayer: View {
    let url: URL
    let onClose: () -> Void
    let item: AVPlayerItem
    @State private var player: AVPlayer?
    @State private var loading = true

    init(url: URL, onClose: @escaping () -> Void) {
        self.url = url
        self.onClose = onClose
        item = AVPlayerItem(asset: AVURLAsset(url: url, options: ["AVURLAssetHTTPHeaderFieldsKey": ["ngrok-skip-browser-warning": "1"]]))
    }

    var body: some View {
        ZStack(alignment: .topLeading) {
            Color.black.ignoresSafeArea()
            if let player {
                VideoPlayer(player: player).padding(.top, 56)
            }
            if loading { ProgressView().tint(.white).frame(maxWidth: .infinity, maxHeight: .infinity) }
            Button(action: onClose) {
                Image(systemName: "xmark").font(.system(size: 18, weight: .bold)).foregroundStyle(.white)
                    .frame(width: 40, height: 40).background(Color.white.opacity(0.18), in: Circle())
            }
            .accessibilityLabel(L("ses_close"))
            .padding(12)
        }
        .onAppear {
            let p = AVPlayer(playerItem: item)
            player = p
            p.play()
        }
        // Ready (or failed): the spinner goes — the item's own state (KVO), nothing on a timer.
        .onReceive(item.publisher(for: \.status)) { status in if status != .unknown { loading = false } }
        .onDisappear { player?.pause() }
    }
}
