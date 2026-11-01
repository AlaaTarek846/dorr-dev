import LiveKit
import SwiftUI

// androidApp `CallsPage` (ChatExtraPages.kt), `ChatCallLinks.kt`, the recordings list (`ChatCallRecordingUi.kt`),
// `ChatVoiceUi.kt` and the viewer's side of `ChatLiveUi.kt` — the calls log with call links, a call link someone sent
// me, the recordings I agreed to, the standing voice rooms (the bar in a group, the room, the mini bar) and watching a
// call broadcast live.
// Android's «calls over other apps / full-screen on the lock screen» card is Android only: iOS has no such switches.

// MARK: - the calls page

struct CallsPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var list: [CallDto]?

    var body: some View {
        ChPage(title: L("ch_calls_title"), onBack: { host.pop() }) {
            if let items = list {
                ScrollView {
                    LazyVStack(spacing: 6) {
                        // Call links (WhatsApp list 72): make one, and the ones I have.
                        CallLinksCard(host: host)
                        if items.isEmpty {
                            ChEmptyState(icon: "phone.fill", title: L("ch_calls_title"), text: L("ch_no_calls"), animated: true).frame(height: 360)
                        }
                        ForEach(Array(items.enumerated()), id: \.element.id) { i, call in row(call).chStagger(i) }
                    }
                    .padding(14)
                }
            } else {
                ProgressView().tint(ch.red).frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .task { list = (try? await ChatApi.calls().data) ?? [] }
    }

    private func row(_ call: CallDto) -> some View {
        let group = call.conversationType == "group"
        let other = group ? nil : call.participants?.first { $0.profile?.isMe != true }?.profile
        let title = call.groupName ?? other?.name ?? ""
        let missed = ["missed", "cancelled", "declined"].contains(call.status)
        let outgoing = call.isOutgoing == true
        let video = call.type == "video"
        return HStack(spacing: 12) {
            ChAvatar(url: other?.avatar, name: title, key: other?.key ?? call.conversationId, size: 48, isGroup: group)
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: title).font(.dorr(15, .bold)).foregroundStyle(missed && !outgoing ? ch.danger : ch.ink).lineLimit(1)
                HStack(spacing: 4) {
                    Image(systemName: missed && !outgoing ? "phone.arrow.down.left" : outgoing ? "phone.arrow.up.right" : "phone.arrow.down.left")
                        .font(.system(size: 12)).foregroundStyle(missed ? ch.danger : ch.success)
                    Text(verbatim: listTime(call.createdAt) + " " + clockTime(call.createdAt) + (call.durationSeconds.map { "  ·  " + durationText(Int64($0) * 1000) } ?? ""))
                        .font(.dorr(12.5)).foregroundStyle(ch.mut).lineLimit(1)
                    // Didn't ring: an unknown caller, silenced by my setting.
                    if call.silenced == true { Text(verbatim: "🔕 " + L("bk_silenced")).font(.dorr(12, .bold)).foregroundStyle(ch.mut).padding(.leading, 2) }
                }
            }
            Spacer(minLength: 0)
            // Recordings of this call I agreed to (spec 92).
            if (call.recordingsCount ?? 0) > 0 {
                circleButton("record.circle.fill", size: 15) { host.recordingsCall = call.id }
            }
            circleButton(video ? "video.fill" : "phone.fill", size: 18) {
                startCall(call.conversationId, video: video, title: title, avatar: other?.avatar, peerKey: other?.key)
            }
        }
        .padding(12)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }

    private func circleButton(_ icon: String, size: CGFloat, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: size)).foregroundStyle(ch.red).frame(width: 42, height: 42).background(ch.red.opacity(0.08), in: Circle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - call links (WhatsApp list 72)

/// On the calls page: «Create call link», then my links that still work.
private struct CallLinksCard: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var links: [CallLinkDto] = []
    @State private var picking = false
    @State private var showing: CallLinkDto?

    var body: some View {
        VStack(spacing: 0) {
            CallLinkRow(icon: "link.badge.plus", title: L("cl_create"), sub: L("cl_create_sub")) { picking = true }
            ForEach(links) { l in
                CallLinkRow(icon: l.type == "video" ? "video.fill" : "phone.fill", title: L(l.type == "video" ? "cl_title_video" : "cl_title_voice"), sub: l.link) { showing = l }
            }
        }
        .padding(.vertical, 4)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .task { links = (try? await ChatApi.callLinks().data) ?? [] }
        .dorrDialog(isPresented: $picking, bottom: true) {
            ChoiceSheet(title: L("cl_pick_type"), subtitle: L("cl_create_sub"),
                        options: [(L("cl_voice"), { create("audio") }), (L("cl_video"), { create("video") })],
                        onDismiss: { picking = false })
        }
        .dorrDialog(isPresented: Binding(get: { showing != nil }, set: { if !$0 { showing = nil } }), bottom: true) {
            if let l = showing {
                CallLinkShareSheet(host: host, link: l, onCancelled: { links.removeAll { $0.token == l.token } }) { showing = nil }
            }
        }
    }

    private func create(_ type: String) {
        Task {
            do {
                guard let made = try await ChatApi.createCallLink(type: type).data else { return }
                links.insert(made, at: 0)
                try? await Task.sleep(nanoseconds: 350_000_000)
                showing = made
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
        }
    }
}

struct CallLinkRow: View {
    @ObservedObject private var ch = Ch.shared
    let icon: String
    let title: String
    let sub: String?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 19)).foregroundStyle(ch.red).frame(width: 44, height: 44).background(ch.red.opacity(0.08), in: Circle())
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: title).font(.dorr(15, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                    if let sub { Text(verbatim: sub).font(.dorr(12.5)).foregroundStyle(ch.mut).lineLimit(1) }
                }
                Spacer(minLength: 0)
            }
            .padding(.horizontal, 14).padding(.vertical, 10).contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// My link: copy, share, join now, cancel it.
struct CallLinkShareSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let link: CallLinkDto
    let onCancelled: () -> Void
    let onDismiss: () -> Void

    var body: some View {
        let video = link.type == "video"
        ChSheetCard {
            Text(L(video ? "cl_title_video" : "cl_title_voice")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            HStack(spacing: 10) {
                Image(systemName: "link").font(.system(size: 19)).foregroundStyle(ch.red)
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: link.link).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                    if let until = link.expiresAt { Text(verbatim: L("cl_works_until", scheduleLabel(until))).font(.dorr(12)).foregroundStyle(ch.mut) }
                }
                Spacer(minLength: 0)
            }
            .padding(14)
            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .padding(.top, 12).padding(.bottom, 10)
            CallLinkRow(icon: "doc.on.doc", title: L("ch_copy_link"), sub: nil) {
                UIPasteboard.general.string = link.link
                host.showToast(L("ch_link_copied"))
            }
            CallLinkRow(icon: "square.and.arrow.up", title: L("ch_share_link"), sub: nil) { chShare(L("cl_share_text", link.link)) }
            CallLinkRow(icon: video ? "video.fill" : "phone.fill", title: L("cl_join"), sub: nil) { joinCallLink(host, link, onDone: onDismiss) }
            CallLinkRow(icon: "link.badge.minus", title: L("cl_cancel"), sub: nil) {
                Task {
                    do {
                        _ = try await ChatApi.revokeCallLink(link.token)
                        onCancelled()
                        host.showToast(L("cl_cancelled"))
                        onDismiss()
                    } catch {
                        host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
                    }
                }
            }
        }
    }
}

/// A call link someone sent me (or mine, opened from a notification): who made it, then «Join».
struct CallLinkSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let token: String
    let onDismiss: () -> Void
    @State private var link: CallLinkDto?
    @State private var failed: String?
    @State private var pop = false

    var body: some View {
        ChSheetCard {
            VStack(spacing: 0) {
                if let failed {
                    Image(systemName: "face.dashed").font(.system(size: 50)).foregroundStyle(ch.mut)
                    Text(verbatim: failed.isEmpty ? L("ch_error_network") : failed).font(.dorr(15, .bold)).foregroundStyle(ch.ink).multilineTextAlignment(.center).padding(.top, 12)
                } else if let l = link {
                    ChAvatar(url: l.owner?.avatar, name: l.owner?.name, key: l.owner?.key, size: 92).scaleEffect(pop ? 1 : 0.6)
                        .onAppear { withAnimation(.spring(response: 0.45, dampingFraction: 0.45)) { pop = true } }
                    Text(L(l.type == "video" ? "cl_title_video" : "cl_title_voice")).font(.dorr(20, .heavy)).foregroundStyle(ch.ink).multilineTextAlignment(.center).padding(.top, 12)
                    if let name = l.owner?.name { Text(verbatim: L("cl_from", name)).font(.dorr(14)).foregroundStyle(ch.mut).padding(.top, 4) }
                    ChPrimaryButton(text: L("cl_join"), icon: l.type == "video" ? "video.fill" : "phone.fill") { joinCallLink(host, l, onDone: onDismiss) }.padding(.top, 20)
                } else {
                    ProgressView().tint(ch.red).frame(height: 180)
                }
            }
            .frame(maxWidth: .infinity)
            .padding(.bottom, 6)
        }
        .task {
            do { link = try await ChatApi.callLink(token).data } catch { failed = error.apiFailure.message ?? "" }
        }
    }
}

/// Join a call link: the microphone (and the camera for video) first, then into the room. Busy in another call: say
/// so instead.
@MainActor func joinCallLink(_ host: ChatHost, _ l: CallLinkDto, onDone: @escaping () -> Void) {
    if let phase = CallController.shared.phase, phase != .ended {
        host.showToast(L("cl_in_call"))
        return
    }
    let video = l.type == "video"
    Task {
        guard await CallPermissions.ask(video: video) else { return }
        do {
            guard let res = try await ChatApi.joinCallLink(l.token).data else { return }
            let owner = res.link.owner
            // My own link: the call is named after the link; someone else's: after them.
            let title = res.link.isMine == true ? L(video ? "cl_title_video" : "cl_title_voice") : (owner?.name ?? "")
            CallController.shared.joinLink(token: l.token, join: res.join, video: video, title: title, avatar: owner?.avatar)
            onDone()
        } catch {
            host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
        }
    }
}

// MARK: - recordings (spec 92)

/// The recordings of one call I agreed to: a video opens in the player, a voice call plays right here. Links from the
/// server work for a few minutes, so they're fetched when this opens.
struct CallRecordingsSheet: View {
    @ObservedObject private var ch = Ch.shared
    let callId: String
    let onDismiss: () -> Void
    @State private var list: [CallRecordingDto]?
    @State private var failed = false

    var body: some View {
        ChSheetCard {
            Text(L("cr_list_title")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            Text(L("cr_list_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut)
            Group {
                if let items = list {
                    if items.isEmpty {
                        Text(L(failed ? "cr_list_failed" : "cr_list_empty")).font(.dorr(14)).foregroundStyle(ch.mut).padding(.vertical, 18)
                    } else {
                        VStack(spacing: 8) {
                            ForEach(Array(items.enumerated()), id: \.element.id) { i, r in
                                RecordingRow(number: i + 1, recording: r) { url in
                                    onDismiss()
                                    presentVideo(url)
                                }
                            }
                        }
                    }
                } else {
                    ProgressView().tint(ch.red).frame(maxWidth: .infinity).frame(height: 90)
                }
            }
            .padding(.top, 14)
        }
        .task {
            do { list = try await ChatApi.callRecordings(callId).data ?? [] } catch {
                failed = true
                list = []
            }
        }
        .onDisappear { if VoicePlayer.shared.playingId?.hasPrefix("rec:") == true { VoicePlayer.shared.stop() } }
    }
}

private struct RecordingRow: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var player = VoicePlayer.shared
    let number: Int
    let recording: CallRecordingDto
    let onVideo: (URL) -> Void

    var body: some View {
        let playerId = "rec:\(recording.id)"
        let ready = recording.status == "ready" && recording.url != nil
        HStack(spacing: 12) {
            Image(systemName: recording.type == "video" ? "video.fill" : "mic.fill").font(.system(size: 18)).foregroundStyle(ch.red)
                .frame(width: 42, height: 42).background(ch.red.opacity(0.1), in: Circle())
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: L("cr_item", number)).font(.dorr(15, .bold)).foregroundStyle(ch.ink)
                Text(verbatim: line(ready)).font(.dorr(12.5)).foregroundStyle(ch.mut)
                if player.playingId == playerId {
                    ProgressView(value: player.progress).tint(ch.red).padding(.top, 4)
                }
            }
            Spacer(minLength: 0)
            if ready {
                Button {
                    guard let url = recording.url.flatMap(URL.init(string:)) else { return }
                    if recording.type == "video" { onVideo(url) } else { player.toggle(playerId, url) }
                } label: {
                    Image(systemName: player.isPlaying(playerId) ? "pause.fill" : "play.fill").font(.system(size: 18)).foregroundStyle(.white)
                        .frame(width: 42, height: 42).background(ch.red, in: Circle())
                }
                .buttonStyle(.plain)
            }
        }
        .padding(12)
        .background(ch.bg, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }

    private func line(_ ready: Bool) -> String {
        let duration = recording.durationSeconds.map { durationText(Int64($0) * 1000) }
        guard ready else { return L("cr_saving") }
        if let until = recording.expiresAt.flatMap(parseRideTime) {
            let day = until.formatted(.dateTime.day().month(.abbreviated).locale(Locale(identifier: AppLocale.current)))
            return (duration.map { $0 + "  ·  " } ?? "") + L("cr_until", day)
        }
        return duration ?? ""
    }
}

// MARK: - voice rooms (spec 91)

private let voiceTeal = Color(hex: 0x0D9488)
private let voiceDeep = Color(hex: 0x0F172A)
private let voiceMint = Color(hex: 0x5EEAD4)

/// In a group: who's in its voice room, and a way in.
struct VoiceRoomBar: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var voice = VoiceRoomController.shared
    let conversationId: String
    let title: String?
    @State private var count = 0

    var body: some View {
        VStack {
            if count > 0 && !voice.isIn(conversationId) {
                Button { host.push(.voiceRoom(conversationId: conversationId, title: title)) } label: {
                    HStack(spacing: 10) {
                        LiveBars(color: .white)
                        Text(verbatim: L("vr_bar", count)).font(.dorr(13.5, .bold)).foregroundStyle(.white).frame(maxWidth: .infinity, alignment: .leading)
                        Text(L("vr_join")).font(.dorr(12.5, .heavy)).foregroundStyle(voiceTeal).padding(.horizontal, 12).padding(.vertical, 5).background(.white, in: Capsule())
                    }
                    .padding(.horizontal, 14).padding(.vertical, 10)
                    .background(LinearGradient(colors: [voiceTeal, Color(hex: 0x0E7490)], startPoint: .leading, endPoint: .trailing), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                }
                .buttonStyle(.plain)
                .padding(.horizontal, 12).padding(.vertical, 6)
                .transition(.move(edge: .top).combined(with: .opacity))
            }
        }
        .animation(.spring(response: 0.4, dampingFraction: 0.85), value: count > 0 && !voice.isIn(conversationId))
        .task(id: conversationId) { count = (try? await VoiceApi.room(conversationId).data?.count) ?? 0 }
        .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { e in
            if e.name == "chat.voice.updated", e.str("conversation_id") == conversationId { count = e.data.int("count") ?? count }
        }
    }
}

/// On top of the chat while I'm in a room and not on its page.
struct VoiceMiniBar: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var voice = VoiceRoomController.shared

    var body: some View {
        if let room = voice.room, !onItsPage(room.conversationId) {
            HStack(spacing: 8) {
                LiveBars(color: voiceMint)
                VStack(alignment: .leading, spacing: 1) {
                    Text(L("vr_title")).font(.dorr(12.5, .heavy)).foregroundStyle(.white)
                    Text(verbatim: [voice.chatTitle, L("vr_people", room.count ?? 0)].compactMap { $0 }.joined(separator: " · "))
                        .font(.dorr(11)).foregroundStyle(.white.opacity(0.7)).lineLimit(1)
                }
                Spacer(minLength: 0)
                if room.me?.role != "listener" {
                    VoiceRoundButton(icon: voice.micOn ? "mic.fill" : "mic.slash.fill", bg: voice.micOn ? voiceTeal : .white.opacity(0.16), size: 34) { voice.toggleMic() }
                }
                VoiceRoundButton(icon: "phone.down.fill", bg: Color(hex: 0xDC2626), size: 34) { voice.leave() }
            }
            .padding(.horizontal, 12).padding(.vertical, 8)
            .background(voiceDeep, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .contentShape(Rectangle())
            .onTapGesture { host.push(.voiceRoom(conversationId: room.conversationId, title: voice.chatTitle)) }
            .padding(.horizontal, 10).padding(.vertical, 4)
            .transition(.move(edge: .top).combined(with: .opacity))
        }
    }

    private func onItsPage(_ id: String) -> Bool {
        if case .voiceRoom(let cid, _) = host.current { return cid == id }
        return false
    }
}

private struct LiveBars: View {
    let color: Color

    var body: some View {
        TimelineView(.animation) { t in
            let time = t.date.timeIntervalSinceReferenceDate
            HStack(spacing: 2) {
                ForEach(0..<3, id: \.self) { i in
                    let phase = (time / 0.52 + Double(i) * 0.35).truncatingRemainder(dividingBy: 2)
                    let h = 0.3 + 0.7 * (phase < 1 ? phase : 2 - phase)
                    Capsule().fill(color).frame(width: 3, height: 16 * h)
                }
            }
            .frame(height: 16)
        }
    }
}

private struct VoiceRoundButton: View {
    let icon: String
    let bg: Color
    let size: CGFloat
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: size * 0.42)).foregroundStyle(.white).frame(width: size, height: size).background(bg, in: Circle())
        }
        .buttonStyle(.plain)
    }
}

/// The room: who's in (talking ones glow), hands, my microphone, the hosts' controls.
struct VoiceRoomPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var voice = VoiceRoomController.shared
    let conversationId: String
    let title: String?
    @State private var preview: VoiceRoomDto?
    @State private var menuFor: VoiceMemberDto?

    var body: some View {
        let inRoom = voice.isIn(conversationId)
        let room = inRoom ? voice.room : preview
        ZStack(alignment: .bottom) {
            LinearGradient(colors: [voiceDeep, Color(hex: 0x134E4A)], startPoint: .top, endPoint: .bottom).ignoresSafeArea()
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    header(room: room, inRoom: inRoom)
                    members(room: room, inRoom: inRoom)
                    Spacer().frame(height: 120)
                }
                .padding(.horizontal, 18).padding(.vertical, 14)
            }
            controls(room: room, inRoom: inRoom)
        }
        .task(id: conversationId) { preview = try? await VoiceApi.room(conversationId).data }
        .dorrDialog(isPresented: Binding(get: { menuFor != nil }, set: { if !$0 { menuFor = nil } }), bottom: true) {
            if let m = menuFor {
                ChoiceSheet(title: m.name ?? "", options: [
                    m.role == "listener" ? (L("vr_let_speak"), { voice.setRole(m.participantId, "speaker") }) : nil,
                    m.role == "speaker" ? (L("vr_make_listener"), { voice.setRole(m.participantId, "listener") }) : nil,
                    (L("vr_remove"), { voice.remove(m.participantId) }),
                ].compactMap { $0 }, onDismiss: { menuFor = nil })
            }
        }
    }

    private func header(room: VoiceRoomDto?, inRoom: Bool) -> some View {
        HStack(spacing: 6) {
            Button { host.pop() } label: {
                Image(systemName: "chevron.left").font(.system(size: 20, weight: .semibold)).foregroundStyle(.white)
                    .flipsForRightToLeftLayoutDirection(true).frame(width: 40, height: 40)
            }
            .buttonStyle(.plain)
            VStack(alignment: .leading, spacing: 1) {
                Text(L("vr_title")).font(.dorr(19, .heavy)).foregroundStyle(.white)
                Text(verbatim: [title, room.map { L("vr_people", $0.count ?? 0) }].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.7))
            }
            Spacer(minLength: 0)
            if room?.me?.canManage == true {
                let approved = room?.speakPolicy == "approved"
                Button { voice.setPolicy(approved ? "everyone" : "approved") } label: {
                    Text(L(approved ? "vr_policy_approved" : "vr_policy_everyone")).font(.dorr(11.5, .bold)).foregroundStyle(.white)
                        .padding(.horizontal, 10).padding(.vertical, 6).background(Color.white.opacity(0.14), in: Capsule())
                }
                .buttonStyle(.plain)
                .disabled(!inRoom)
            }
        }
        .padding(.bottom, 18)
    }

    @ViewBuilder
    private func members(room: VoiceRoomDto?, inRoom: Bool) -> some View {
        let all = room?.members ?? []
        if all.isEmpty {
            VStack(spacing: 10) {
                Image(systemName: "waveform").font(.system(size: 50)).foregroundStyle(voiceMint)
                Text(L("vr_empty")).font(.dorr(14)).foregroundStyle(.white.opacity(0.85)).multilineTextAlignment(.center)
            }
            .frame(maxWidth: .infinity).padding(.vertical, 40)
        }
        ForEach([("vr_speakers", all.filter { $0.role != "listener" }), ("vr_listeners", all.filter { $0.role == "listener" })], id: \.0) { label, list in
            if !list.isEmpty {
                Text(L(label)).font(.dorr(12, .bold)).foregroundStyle(.white.opacity(0.6)).padding(.vertical, 10)
                LazyVGrid(columns: Array(repeating: GridItem(.flexible()), count: 4), spacing: 14) {
                    ForEach(list) { m in
                        VoiceMemberTile(member: m, talking: voice.speaking.contains(m.key))
                            .onTapGesture { if room?.me?.canManage == true && inRoom && m.key != myKey() { menuFor = m } }
                    }
                }
            }
        }
    }

    private func controls(room: VoiceRoomDto?, inRoom: Bool) -> some View {
        HStack(spacing: 12) {
            if !inRoom {
                Button {
                    Task {
                        guard await CallPermissions.ask(video: false) else { return }
                        if !(await voice.join(conversationId, title: title)), let e = voice.error { host.showToast(e, error: true) }
                    }
                } label: {
                    Text(L(voice.connecting ? "vr_connecting" : "vr_join_room")).font(.dorr(15, .heavy)).foregroundStyle(voiceDeep)
                        .frame(maxWidth: .infinity).padding(.vertical, 14).background(voiceMint, in: Capsule())
                }
                .buttonStyle(.plain)
                .disabled(voice.connecting)
            } else {
                if room?.me?.role == "listener" {
                    let raised = room?.members?.first { $0.key == myKey() }?.handRaised == true
                    Button { voice.raiseHand(!raised) } label: {
                        Text(verbatim: "✋ " + L(raised ? "vr_hand_down" : "vr_raise_hand")).font(.dorr(14, .bold)).foregroundStyle(.white)
                            .padding(.horizontal, 18).padding(.vertical, 12).background(raised ? Color(hex: 0xF59E0B) : .white.opacity(0.16), in: Capsule())
                    }
                    .buttonStyle(.plain)
                } else {
                    VoiceRoundButton(icon: voice.micOn ? "mic.fill" : "mic.slash.fill", bg: voice.micOn ? voiceTeal : .white.opacity(0.16), size: 58) { voice.toggleMic() }
                }
                // The loudspeaker, or the earpiece / headset.
                VoiceRoundButton(icon: voice.speakerOn ? "speaker.wave.2.fill" : "phone.fill", bg: voice.speakerOn ? voiceTeal : .white.opacity(0.16), size: 58) { voice.toggleSpeaker() }
                VoiceRoundButton(icon: "phone.down.fill", bg: Color(hex: 0xDC2626), size: 58) {
                    voice.leave()
                    host.pop()
                }
            }
        }
        .frame(maxWidth: .infinity)
        .padding(14)
        .background(Color.white.opacity(0.08), in: RoundedRectangle(cornerRadius: 28, style: .continuous))
        .padding(16)
    }
}

private struct VoiceMemberTile: View {
    let member: VoiceMemberDto
    let talking: Bool
    @State private var ring = false

    var body: some View {
        VStack(spacing: 6) {
            ZStack {
                if talking {
                    Circle().stroke(voiceMint, lineWidth: 3).frame(width: 70, height: 70).scaleEffect(ring ? 1.12 : 1)
                        .onAppear { withAnimation(.easeInOut(duration: 0.6).repeatForever(autoreverses: true)) { ring = true } }
                        .onDisappear { ring = false }
                }
                ChAvatar(url: member.avatar, name: member.name, key: member.key, size: 60)
                if member.handRaised == true { Text(verbatim: "✋").font(.system(size: 18)).offset(x: 24, y: -24) }
                if member.role == "host" { Text(verbatim: "👑").font(.system(size: 14)).offset(x: -24, y: -24) }
            }
            .frame(width: 72, height: 72)
            Text(verbatim: member.key == myKey() ? L("lf_you") : (member.name ?? "")).font(.dorr(12, .bold)).foregroundStyle(.white).lineLimit(1).multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity)
        .contentShape(Rectangle())
    }
}

// MARK: - a call broadcast live (spec 93), the viewer's side

/// The card in a chat: «🔴 Live · title · host — Watch».
struct LiveCardBubble<Footer: View>: View {
    @EnvironmentObject private var host: ChatHost
    let dto: MessageDto
    @ViewBuilder let footer: () -> Footer

    var body: some View {
        let live = dto.meta?.at("live")
        // A live that ended has no token any more: the card stays, it just doesn't open.
        let token = live?.str("token")
        Button { if let token { host.push(.liveViewer(token)) } } label: {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 5) {
                    LivePulseDot()
                    Text(verbatim: "LIVE").font(.dorr(11, .black)).foregroundStyle(.white)
                }
                .padding(.horizontal, 8).padding(.vertical, 3).background(Color.white.opacity(0.2), in: Capsule())
                Text(verbatim: live?.str("title") ?? L("lv_live")).font(.dorr(16, .heavy)).foregroundStyle(.white).padding(.top, 8)
                if let name = live?.str("host") { Text(verbatim: name).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.8)) }
                Text(verbatim: "▶  " + L("lv_watch")).font(.dorr(13.5, .heavy)).foregroundStyle(liveRed)
                    .frame(maxWidth: .infinity).padding(.vertical, 9).background(.white, in: Capsule()).padding(.top, 10)
                footer()
            }
            .padding(14)
            .frame(width: 250, alignment: .leading)
            .background(LinearGradient(colors: [Color(hex: 0x881337), liveRed], startPoint: .topLeading, endPoint: .bottomTrailing),
                        in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(.plain)
        .disabled(token == nil)
    }
}

/// Watching: the call's cameras and voices, nothing to publish (LiveKit's own rule for a viewer's token).
@MainActor
private final class LiveViewerModel: NSObject, ObservableObject, RoomDelegate {
    @Published var live: LiveDto?
    @Published var videos: [VideoTrack] = []
    @Published var failed: String?
    private var room: Room?
    private var pinger: Task<Void, Never>?

    func start(_ token: String) async {
        let watch: LiveWatchDto
        do {
            guard let w = try await VoiceApi.watch(token).data else { return }
            watch = w
        } catch {
            failed = error.apiFailure.message ?? L("ch_error_network")
            return
        }
        live = watch.live
        guard let join = watch.join else { return }
        let r = Room(delegate: self)
        room = r
        do { try await r.connect(url: join.url, token: join.token) } catch { failed = error.localizedDescription }
        refresh()
        // Still watching: the server counts me (every 30 s, like Android).
        pinger = Task { [weak self] in
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: 30_000_000_000)
                guard !Task.isCancelled else { return }
                if let fresh = try? await VoiceApi.pingLive(token).data { self?.live = fresh }
            }
        }
    }

    func stop() {
        pinger?.cancel()
        if let r = room { Task { await r.disconnect() } }
        room = nil
    }

    private func refresh() {
        videos = room?.remoteParticipants.values.flatMap { p in p.videoTracks.compactMap { $0.track as? VideoTrack } } ?? []
    }

    nonisolated func room(_ room: Room, participant: RemoteParticipant, didSubscribeTrack publication: RemoteTrackPublication) { Task { @MainActor in self.refresh() } }
    nonisolated func room(_ room: Room, participant: RemoteParticipant, didUnsubscribeTrack publication: RemoteTrackPublication) { Task { @MainActor in self.refresh() } }
    nonisolated func room(_ room: Room, participantDidDisconnect participant: RemoteParticipant) { Task { @MainActor in self.refresh() } }
    nonisolated func room(_ room: Room, didDisconnectWithError error: LiveKitError?) { Task { @MainActor in self.live?.status = "ended" } }
}

struct LiveViewerPage: View {
    @ObservedObject var host: ChatHost
    let token: String
    @StateObject private var model = LiveViewerModel()

    var body: some View {
        ZStack(alignment: .top) {
            Color.black.ignoresSafeArea()
            if !model.videos.isEmpty {
                VStack(spacing: 0) {
                    ForEach(Array(model.videos.prefix(4).enumerated()), id: \.offset) { _, track in
                        SwiftUIVideoView(track, layoutMode: .fill).frame(maxWidth: .infinity, maxHeight: .infinity).clipped()
                    }
                }
                .ignoresSafeArea()
            } else {
                VStack(spacing: 10) {
                    Text(verbatim: model.live?.status == "ended" ? "⏹" : "🎙️").font(.system(size: 46))
                    Text(verbatim: model.failed ?? L(model.live?.status == "ended" ? "lv_ended" : "lv_listening")).font(.dorr(15, .bold)).foregroundStyle(.white).multilineTextAlignment(.center)
                }
                .padding(30)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
            // The title and who watches, on top.
            HStack(spacing: 6) {
                Button { host.pop() } label: {
                    Image(systemName: "chevron.left").font(.system(size: 20, weight: .semibold)).foregroundStyle(.white)
                        .flipsForRightToLeftLayoutDirection(true).frame(width: 40, height: 40)
                }
                .buttonStyle(.plain)
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: model.live?.title ?? L("lv_live")).font(.dorr(16, .heavy)).foregroundStyle(.white).lineLimit(1)
                    if let name = model.live?.host?.name { Text(verbatim: name).font(.dorr(12)).foregroundStyle(.white.opacity(0.7)) }
                }
                Spacer(minLength: 0)
                if let l = model.live, l.status == "live" { LiveBadge(live: l) {} }
            }
            .padding(12)
        }
        .task(id: token) { await model.start(token) }
        .onDisappear { model.stop() }
    }
}
