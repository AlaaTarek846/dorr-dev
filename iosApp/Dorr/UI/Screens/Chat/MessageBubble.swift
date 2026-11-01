import AVFoundation
import SwiftUI
import UIKit

// androidApp `ui/screens/chat/MessageBubble.kt` — one row of the conversation: the bubble (its tail on the first of a
// run), the sender's name and avatar in groups, swipe to reply, long-press for actions, and every kind of content
// (text with links / mentions / formatting, photos & videos, voice notes, files, location, contact, wallet cards,
// calls, polls, money…), the footer (time · edited · star · ticks) and the reactions hanging underneath.

/// Callbacks a bubble can trigger.
struct BubbleActions {
    var onReply: (MessageDto) -> Void
    var onLongPress: (UiMessage) -> Void
    var onJumpTo: (String) -> Void
    var onRetry: (UiMessage) -> Void
    var onOpenMedia: (MessageDto, Int) -> Void
    var onPayQr: (String) -> Void
    var onMessageContact: (String) -> Void
    var onVote: (UiMessage, Int) -> Void = { _, _ in }
    var onShowVotes: (MessageDto) -> Void = { _ in }
    /// A reply button / list choice was tapped (WhatsApp list 123–125).
    var onChoose: (UiMessage, Int) -> Void = { _, _ in }
    var onOpenViewOnce: (UiMessage) -> Void = { _ in }
    var onStopLive: (UiMessage) -> Void = { _ in }
    /// A Dorr group invite link (dorr://chat/join/…) was tapped.
    var onJoinGroup: (String) -> Void = { _ in }
    /// Money request / bill split: pay (PIN), decline, or — the requester — call it off.
    var onPay: (UiMessage) -> Void = { _ in }
    var onDeclineRequest: (UiMessage) -> Void = { _ in }
    var onCancelRequest: (UiMessage) -> Void = { _ in }
    /// AI: turn a voice message into text / hide a translation or transcript.
    var onTranscribe: (UiMessage) -> Void = { _ in }
    var onHideAi: (UiMessage) -> Void = { _ in }
    /// Threads (spec 122): the "N replies" chip under a message.
    var onOpenThread: (MessageDto) -> Void = { _ in }
    /// Sensitive messages / blurred media: shown yet? And unlock / uncover one.
    var isRevealed: (String) -> Bool = { _ in true }
    var onReveal: (UiMessage) -> Void = { _ in }
    /// A surprise greeting card whose time came: fetch it again, opened.
    var onOpenSealed: (UiMessage) -> Void = { _ in }
}

extension MessageDto {
    var deleted: Bool { isDeleted == true }

    /// A clip sent as a GIF (spec 11).
    var isGifVideo: Bool {
        guard type == "video", let flag = meta?.at("gif") else { return false }
        return flag.bool == true
    }

    /// A folder sent as one zip (spec 10): its name and how many files were in it.
    var folderInfo: (String, Int?)? {
        guard type == "document", let meta else { return nil }
        if let f = meta.at("folder"), let name = f.str("name") { return (name, f.int("count")) }
        guard let name = meta.str("folder_name") else { return nil }
        return (name, meta.int("folder_count"))
    }
}

/// The phone number shown left-to-right inside Arabic text.
func ltrNumber(_ text: String?) -> String { (text?.isEmpty == false) ? "\u{2066}\(text!)\u{2069}" : "" }

/// 950 · 1.2K · 3.4M
func compactCount(_ n: Int) -> String {
    if n >= 1_000_000 { return String(format: "%.1fM", Double(n) / 1_000_000).replacingOccurrences(of: ".0M", with: "M") }
    if n >= 1_000 { return String(format: "%.1fK", Double(n) / 1_000).replacingOccurrences(of: ".0K", with: "K") }
    return String(n)
}

/// One row of the conversation. Consecutive messages from the same person inside a few minutes form a run: only the
/// first shows the name (groups) and the tail, only the last the avatar.
struct MessageRow: View {
    @ObservedObject private var ch = Ch.shared
    @Environment(\.layoutDirection) private var direction
    let m: UiMessage
    let firstInRun: Bool
    let lastInRun: Bool
    let isGroup: Bool
    let actions: BubbleActions
    var highlighted = false

    @State private var drag: CGFloat = 0
    @State private var armed = false
    private let threshold: CGFloat = 64

    var body: some View {
        let dto = m.dto
        if dto.type == "system" || (dto.system != nil && dto.sender == nil) {
            HStack {
                Spacer()
                ChChip(text: dto.system?.text.flatMap { $0.isEmpty ? nil : $0 } ?? "•")
                Spacer()
            }
            .padding(.vertical, 6)
            .chPopIn(m.fresh)
        } else {
            row(dto)
        }
    }

    private func row(_ dto: MessageDto) -> some View {
        let mine = m.isMine
        let p = min(max(drag / threshold, 0), 1)
        return ZStack(alignment: .leading) {
            // The reply arrow that grows out from under the bubble.
            Image(systemName: "arrowshape.turn.up.left.fill").font(.system(size: 15, weight: .bold))
                .foregroundStyle(armed ? .white : ch.red)
                .frame(width: 34, height: 34)
                .background(armed ? ch.red : .white, in: Circle())
                .scaleEffect(0.4 + 0.6 * p).opacity(p)
                .padding(.leading, 12)
            HStack(alignment: .bottom, spacing: 0) {
                if mine { Spacer(minLength: 40) }
                if !mine && isGroup {
                    if lastInRun { ChAvatar(url: dto.sender?.avatar, name: dto.sender?.name, key: dto.sender?.key, size: 30) }
                    else { Color.clear.frame(width: 30, height: 1) }
                    Spacer().frame(width: 6)
                }
                Bubble(m: m, mine: mine, firstInRun: firstInRun, isGroup: isGroup, actions: actions)
                if !mine { Spacer(minLength: 40) }
            }
            .padding(.horizontal, 10)
            .offset(x: drag * (direction == .rightToLeft ? -1 : 1))
        }
        .frame(maxWidth: .infinity)
        .padding(.top, firstInRun ? 6 : 1.5).padding(.bottom, 1.5)
        .background(ch.red.opacity(highlighted ? 0.10 : 0).animation(.easeInOut(duration: 0.6)))
        .contentShape(Rectangle())
        .simultaneousGesture(
            DragGesture(minimumDistance: 18)
                .onChanged { v in
                    guard !dto.deleted, m.local == nil, abs(v.translation.width) > abs(v.translation.height) else { return }
                    // Swiping "into" the chat (towards the reading start) replies — mirrored in Arabic.
                    let delta = v.translation.width * (direction == .rightToLeft ? -1 : 1)
                    drag = min(max(delta, 0), threshold * 1.5)
                    if !armed && drag >= threshold {
                        armed = true
                        UIImpactFeedbackGenerator(style: .medium).impactOccurred()
                    } else if armed && drag < threshold { armed = false }
                }
                .onEnded { _ in
                    if armed { actions.onReply(dto) }
                    armed = false
                    withAnimation(.spring(response: 0.35, dampingFraction: 0.55)) { drag = 0 }
                }
        )
    }
}

/// The rounded bubble whose top corner on the sender's side is sharp on the first of a run (the tail).
private struct BubbleShape: Shape {
    var topLeft: CGFloat, topRight: CGFloat, bottomRight: CGFloat, bottomLeft: CGFloat

    func path(in r: CGRect) -> Path {
        var p = Path()
        p.move(to: CGPoint(x: r.minX + topLeft, y: r.minY))
        p.addLine(to: CGPoint(x: r.maxX - topRight, y: r.minY))
        p.addArc(center: CGPoint(x: r.maxX - topRight, y: r.minY + topRight), radius: topRight, startAngle: .degrees(-90), endAngle: .degrees(0), clockwise: false)
        p.addLine(to: CGPoint(x: r.maxX, y: r.maxY - bottomRight))
        p.addArc(center: CGPoint(x: r.maxX - bottomRight, y: r.maxY - bottomRight), radius: bottomRight, startAngle: .degrees(0), endAngle: .degrees(90), clockwise: false)
        p.addLine(to: CGPoint(x: r.minX + bottomLeft, y: r.maxY))
        p.addArc(center: CGPoint(x: r.minX + bottomLeft, y: r.maxY - bottomLeft), radius: bottomLeft, startAngle: .degrees(90), endAngle: .degrees(180), clockwise: false)
        p.addLine(to: CGPoint(x: r.minX, y: r.minY + topLeft))
        p.addArc(center: CGPoint(x: r.minX + topLeft, y: r.minY + topLeft), radius: topLeft, startAngle: .degrees(180), endAngle: .degrees(270), clockwise: false)
        p.closeSubpath()
        return p
    }
}

/// Message kinds whose card holds a list: they keep their own width instead of the bubble's intrinsic one.
private let cardsWithLists: Set<String> = ["moment_card", "match_card", "live_card", "event_card", "interactive", "poll", "bill_split", "commitment", "handoff", "agreement"]

private struct Bubble: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var ai = ChatAi.shared
    @Environment(\.layoutDirection) private var direction
    let m: UiMessage
    let mine: Bool
    let firstInRun: Bool
    let isGroup: Bool
    let actions: BubbleActions

    var body: some View {
        let dto = m.dto
        // Cards that bring their own background (wallet cards, the green money request). A sticker floats on the
        // wallpaper: no bubble, no shadow.
        let bare = dto.type == "sticker" && !dto.deleted
        let isCard = (["wallet_transfer", "wallet_qr", "money_request", "moment_card"].contains(dto.type) || bare) && !dto.deleted
        let media = ["image", "video"].contains(dto.type) && !dto.deleted
        let shape = bubbleShape
        VStack(alignment: mine ? .trailing : .leading, spacing: 0) {
            VStack(alignment: .leading, spacing: 0) {
                if isGroup && !mine && firstInRun, let sender = dto.sender {
                    Text(verbatim: sender.name ?? "").font(.dorr(13, .heavy)).foregroundStyle(Ch.colorFor(sender.key)).lineLimit(1)
                        .padding(.horizontal, 12).padding(.top, 8)
                }
                if dto.isForwarded == true && !dto.deleted { ForwardedLabel(many: dto.forwardedManyTimes == true, mine: mine) }
                if dto.isUrgent == true && !dto.deleted { UrgentLabel() }
                // A business's welcome / away message, sent by itself.
                if !dto.deleted, let auto = dto.meta?.at("auto_reply"), auto.obj == nil, auto.arr == nil { AutoReplyLabel(mine: mine) }
                if let reply = dto.replyTo, !dto.deleted {
                    ReplyQuote(reply: reply, mine: mine) { if let id = reply.id { actions.onJumpTo(id) } }
                }
                BubbleContent(m: m, mine: mine, actions: actions)
                // AI, only for me and only when I asked: the translation / transcript, or the «show text» button
                // under a voice message.
                if let note = m.ai {
                    AiNotePanel(note: note, mine: mine) { actions.onHideAi(m) }
                } else if ["voice", "audio"].contains(dto.type) && dto.viewOnce != true && !dto.deleted && m.local == nil && ai.transcribe {
                    TranscribeChip(mine: mine) { actions.onTranscribe(m) }
                }
            }
            .fixedSize(horizontal: !cardsWithLists.contains(dto.type), vertical: false)
            .padding(media || isCard ? 3 : 0)
            .background {
                if mine && !isCard { shape.fill(ch.outBubble) } else if !isCard { shape.fill(ch.inBubble) }
            }
            .clipShape(shape)
            .shadow(color: bare ? .clear : mine ? ch.red.opacity(0.22) : .black.opacity(0.08), radius: bare ? 0 : mine ? 5 : 2, y: bare ? 0 : 2)
            .frame(maxWidth: 300, alignment: mine ? .trailing : .leading)
            .contentShape(shape)
            .onTapGesture { if m.local == "failed" { actions.onRetry(m) } }
            .onLongPressGesture(minimumDuration: 0.4) {
                guard !dto.deleted, m.local == nil else { return }
                UIImpactFeedbackGenerator(style: .medium).impactOccurred()
                actions.onLongPress(m)
            }
            .chPopIn(m.fresh, fromEnd: mine)
            Reactions(dto: dto, mine: mine)
            if let t = dto.thread, t.count > 0 { ThreadChip(count: t.count) { actions.onOpenThread(dto) } }
            if m.local == "failed" {
                Button { actions.onRetry(m) } label: {
                    HStack(spacing: 4) {
                        Image(systemName: "exclamationmark.circle").font(.system(size: 13))
                        Text(L("ch_failed")).font(.dorr(11.5, .semibold))
                    }
                    .foregroundStyle(ch.danger)
                }
                .buttonStyle(.plain)
                .padding(.top, 3).padding(.trailing, 4)
                .transition(.scale.combined(with: .opacity))
            }
        }
    }

    /// The tail: the top corner on the sender's side of the first bubble in a run is sharp.
    private var bubbleShape: BubbleShape {
        let big = Ch.bubbleRadius, small = Ch.tailRadius
        let sharpEnd = mine && firstInRun, sharpStart = !mine && firstInRun
        let rtl = direction == .rightToLeft
        // The shape draws in physical left / right: «start» is the right side in Arabic.
        let left = rtl ? sharpEnd : sharpStart
        let right = rtl ? sharpStart : sharpEnd
        return BubbleShape(topLeft: left ? small : big, topRight: right ? small : big, bottomRight: big, bottomLeft: big)
    }
}

// MARK: - Labels

private struct UrgentLabel: View {
    @ObservedObject private var ch = Ch.shared
    var body: some View {
        Text(verbatim: "🚨 " + L("ch_urgent")).font(.dorr(11, .heavy)).foregroundStyle(.white)
            .padding(.horizontal, 7).padding(.vertical, 2)
            .background(ch.danger, in: RoundedRectangle(cornerRadius: 8))
            .padding(.horizontal, 12).padding(.top, 7)
    }
}

private struct AutoReplyLabel: View {
    @ObservedObject private var ch = Ch.shared
    let mine: Bool
    var body: some View {
        let tint = mine ? ch.outText.opacity(0.75) : ch.soft
        HStack(spacing: 4) {
            Image(systemName: "bolt.fill").font(.system(size: 12))
            Text(L("ch_auto_reply")).font(.dorr(11.5)).italic()
        }
        .foregroundStyle(tint)
        .padding(.horizontal, 12).padding(.top, 7)
    }
}

private struct ForwardedLabel: View {
    @ObservedObject private var ch = Ch.shared
    let many: Bool
    let mine: Bool
    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: many ? "arrowshape.turn.up.right.2.fill" : "arrowshape.turn.up.right.fill").font(.system(size: 11))
                .scaleEffect(x: 1, y: 1)
            Text(L(many ? "ch_forwarded_many" : "ch_forwarded")).font(.dorr(11.5)).italic()
        }
        .foregroundStyle(mine ? ch.outText.opacity(0.75) : ch.soft)
        .padding(.horizontal, 12).padding(.top, 7)
    }
}

private struct ReplyQuote: View {
    @ObservedObject private var ch = Ch.shared
    let reply: ReplyPreviewDto
    let mine: Bool
    let onTap: () -> Void

    var body: some View {
        let accent = mine ? ch.outText : Ch.colorFor(reply.sender?.key)
        let (icon, label) = previewOf(reply.type ?? "text", deleted: reply.isDeleted == true)
        HStack(spacing: 0) {
            Rectangle().fill(accent).frame(width: 4)
            VStack(alignment: .leading, spacing: 1) {
                Text(verbatim: reply.sender?.isMe == true ? L("ch_you") : reply.sender?.name ?? "")
                    .font(.dorr(12.5, .heavy)).foregroundStyle(accent).lineLimit(1)
                HStack(spacing: 3) {
                    if let icon { Image(systemName: icon).font(.system(size: 12)).foregroundStyle(mine ? ch.outText.opacity(0.8) : ch.soft) }
                    Text(verbatim: reply.isDeleted == true ? L("ch_deleted") : (reply.body.flatMap { $0.isEmpty ? nil : plainChatText($0) } ?? label))
                        .font(.dorr(12.5)).foregroundStyle(mine ? ch.outText.opacity(0.85) : ch.mut).lineLimit(2)
                }
            }
            .padding(.horizontal, 9).padding(.vertical, 6)
            .frame(maxWidth: .infinity, alignment: .leading)
            if let thumb = reply.thumbnail {
                ChatImage(url: ApiClient.mediaUrl(thumb)).frame(width: 48, height: 48).clipped()
            }
        }
        .fixedSize(horizontal: false, vertical: true)
        .background(mine ? ch.outText.opacity(0.16) : ch.surfaceMuted)
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .contentShape(Rectangle())
        .onTapGesture(perform: onTap)
        .padding(.horizontal, 6).padding(.top, 6)
    }
}

/// A remote picture filling its frame (cropped), the muted surface while it loads.
struct ChatImage: View {
    @ObservedObject private var ch = Ch.shared
    let url: URL?
    var local: URL?
    var fit = false

    var body: some View {
        if let local, let image = UIImage(contentsOfFile: local.path) {
            if fit { Image(uiImage: image).resizable().scaledToFit() } else { Image(uiImage: image).resizable().scaledToFill() }
        } else {
            AsyncImage(url: url) { phase in
                if let image = phase.image {
                    if fit { image.resizable().scaledToFit() } else { image.resizable().scaledToFill() }
                } else {
                    ch.surfaceMuted
                }
            }
        }
    }
}

// MARK: - Contents

private struct BubbleContent: View {
    @ObservedObject private var ch = Ch.shared
    let m: UiMessage
    let mine: Bool
    let actions: BubbleActions

    var body: some View {
        let dto = m.dto
        let text = mine ? ch.outText : ch.inText
        if dto.deleted {
            HStack(spacing: 6) {
                Image(systemName: "nosign").font(.system(size: 13)).foregroundStyle(text.opacity(0.6))
                Text(L("ch_deleted")).font(.dorr(14)).italic().foregroundStyle(text.opacity(0.7))
                Spacer().frame(width: 2)
                BubbleFooter(m: m, mine: mine, overlay: false)
            }
            .padding(.horizontal, 12).padding(.vertical, 9)
        } else if dto.viewOnce == true {
            ViewOnceCard(m: m, mine: mine, onOpen: { actions.onOpenViewOnce(m) }) { BubbleFooter(m: m, mine: mine, overlay: false) }
        } else if dto.isSensitive == true && !mine && !actions.isRevealed(m.id) {
            // Someone's sensitive message: nothing until I unlock it (Face ID / passcode).
            SensitiveCard(mine: mine, onReveal: { actions.onReveal(m) }) { BubbleFooter(m: m, mine: mine, overlay: false) }
        } else {
            content(dto)
        }
    }

    @ViewBuilder
    private func content(_ dto: MessageDto) -> some View {
        switch dto.type {
        case "image", "video": MediaGrid(m: m, mine: mine, actions: actions)
        case "voice", "audio": VoiceNote(m: m, mine: mine)
        case "document": DocumentCard(m: m, mine: mine)
        case "sticker": StickerBubble(m: m, mine: mine) { BubbleFooter(m: m, mine: mine, overlay: true) }
        case "gif": GifBubble(m: m) { BubbleFooter(m: m, mine: mine, overlay: true) }
        case "money_request":
            MoneyRequestCard(m: m, onPay: { actions.onPay(m) }, onDecline: { actions.onDeclineRequest(m) }, onCancel: { actions.onCancelRequest(m) }) {
                BubbleFooter(m: m, mine: mine, overlay: true)
            }
        case "bill_split":
            BillSplitCard(m: m, mine: mine, onPay: { actions.onPay(m) }, onDecline: { actions.onDeclineRequest(m) }, onCancel: { actions.onCancelRequest(m) }) {
                BubbleFooter(m: m, mine: mine, overlay: false)
            }
        case "poll":
            PollCard(m: m, mine: mine, onVote: { actions.onVote(m, $0) }, onShowVotes: { actions.onShowVotes(dto) }) { BubbleFooter(m: m, mine: mine, overlay: false) }
        case "interactive":
            InteractiveCard(m: m, mine: mine, onChoose: { actions.onChoose(m, $0) }, onShowAnswers: { actions.onShowVotes(dto) }) { BubbleFooter(m: m, mine: mine, overlay: false) }
        case "live_card":
            LiveCardBubble(dto: dto) { BubbleFooter(m: m, mine: mine, overlay: true) }
        case "commitment", "handoff", "agreement":
            LifeCardBubble(dto: dto, onJump: actions.onJumpTo) { BubbleFooter(m: m, mine: mine, overlay: false) }
        // A greeting card (DORR Moments): the occasion's look, words, voices, photos, a gift.
        case "moment_card":
            MomentCardBubble(dto: dto, mine: mine, onOpenSealed: { actions.onOpenSealed(m) }, onOpenPhoto: { actions.onOpenMedia(dto, $0) }) {
                BubbleFooter(m: m, mine: mine, overlay: true)
            }
        // An event from DORR Discover: a snapshot card that opens the live event.
        case "event_card":
            EventCardBubble(dto: dto, mine: mine) { BubbleFooter(m: m, mine: mine, overlay: false) }
        // A match from DORR Sports: live score from Pusher, opens the match.
        case "match_card":
            MatchCardBubble(dto: dto) { BubbleFooter(m: m, mine: mine, overlay: false) }
        case "location":
            if let live = dto.liveLocation {
                LiveLocationCard(meta: dto.meta, live: live, mine: mine, onStop: { actions.onStopLive(m) }) { BubbleFooter(m: m, mine: mine, overlay: true) }
            } else {
                LocationCard(meta: dto.meta, m: m, mine: mine)
            }
        case "contact": ContactCard(meta: dto.meta, m: m, mine: mine, actions: actions)
        case "wallet_transfer": TransferReceiptCard(meta: dto.meta, m: m)
        case "wallet_qr": WalletQrCard(meta: dto.meta, m: m, mine: mine, actions: actions)
        case "call": CallLine(meta: dto.meta, m: m, mine: mine)
        case "story_reply":
            VStack(alignment: .leading, spacing: 0) {
                StoryQuote(meta: dto.meta, mine: mine)
                TextBody(m: m, mine: mine, actions: actions)
            }
        // A status that mentions the other one (backlog 84).
        case "story_mention":
            VStack(alignment: .leading, spacing: 0) {
                StoryQuote(meta: dto.meta, mine: mine)
                StoryMentionLine(meta: dto.meta, mine: mine) { BubbleFooter(m: m, mine: mine, overlay: false) }
            }
        default:
            VStack(alignment: .leading, spacing: 0) {
                // The link's card above the text, like WhatsApp (arrives a moment after sending).
                if let preview = dto.linkPreview { LinkPreviewCard(preview: preview, mine: mine) }
                TextBody(m: m, mine: mine, actions: actions)
            }
        }
    }
}

/// The story a reply answers: «Status» + a small copy of it (its gradient and text, or its photo).
private struct StoryQuote: View {
    @ObservedObject private var ch = Ch.shared
    let meta: JSONAny?
    let mine: Bool

    var body: some View {
        let text = meta?.str("text")
        let thumb = meta?.str("thumbnail")
        let (icon, label) = previewOf(meta?.str("story_type") ?? "text", deleted: false)
        HStack(spacing: 0) {
            Rectangle().fill(mine ? .white : ch.red).frame(width: 4)
            VStack(alignment: .leading, spacing: 1) {
                Text(L("st_status_label")).font(.dorr(12.5, .heavy)).foregroundStyle(mine ? ch.outText : ch.red)
                HStack(spacing: 3) {
                    if let icon { Image(systemName: icon).font(.system(size: 12)).foregroundStyle(mine ? ch.outText.opacity(0.8) : ch.soft) }
                    Text(verbatim: text.flatMap { $0.isEmpty ? nil : $0 } ?? label).font(.dorr(12.5)).foregroundStyle(mine ? ch.outText.opacity(0.85) : ch.mut).lineLimit(2)
                }
            }
            .padding(.horizontal, 9).padding(.vertical, 7)
            .frame(maxWidth: .infinity, alignment: .leading)
            ZStack {
                StoryLook.brush(meta?.at("style")?.str("background"))
                if let thumb {
                    ChatImage(url: ApiClient.mediaUrl(thumb))
                } else if let text {
                    Text(verbatim: String(text.prefix(12))).font(.dorr(7, .bold)).foregroundStyle(.white).lineLimit(3).padding(3)
                }
            }
            .frame(width: 44, height: 62).clipped()
        }
        .background(mine ? ch.outText.opacity(0.16) : ch.surfaceMuted)
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .padding(.horizontal, 6).padding(.top, 6)
    }
}

/// Text with the time tucked into the last line (WhatsApp-style) and links underlined.
struct TextBody: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var prefs = ChatPrefs.shared
    @EnvironmentObject private var host: ChatHost
    let m: UiMessage
    let mine: Bool
    var actions: BubbleActions?

    var body: some View {
        let dto = m.dto
        let body = dto.body ?? ""
        let onlyEmoji = !body.isEmpty && body.count <= 8 && body.unicodeScalars.allSatisfy { !CharacterSet.alphanumerics.contains($0) && !CharacterSet.whitespacesAndNewlines.contains($0) }
        let size: CGFloat = onlyEmoji ? 38 : 15.5 * prefs.fontScale
        ZStack(alignment: .bottomTrailing) {
            Text(attributed(body))
                .font(.dorr(size))
                .foregroundStyle(mine ? ch.outText : ch.inText)
                .lineSpacing(onlyEmoji ? 0 : 3 * prefs.fontScale)
                .fixedSize(horizontal: false, vertical: true)
                .padding(.trailing, 62).padding(.bottom, 2)
                .environment(\.openURL, OpenURLAction { open($0) })
            BubbleFooter(m: m, mine: mine, overlay: false)
        }
        .padding(.horizontal, 12).padding(.vertical, 7)
    }

    /// *bold* _italic_ ~strike~ `code` ```block``` — marks removed, links never formatted. Lists and quotes line by
    /// line; «@Sara» of a real mention bold in the brand colour (white on my own bubble).
    private func attributed(_ body: String) -> AttributedString {
        let linkColor = mine ? ch.outText : Color(hex: 0x2563EB)
        let mentionColor = mine ? ch.outText : ch.red
        let codeBg = mine ? Color.white.opacity(0.2) : ch.ink.opacity(0.08)
        func pieces(_ text: String) -> AttributedString {
            var out = AttributedString()
            for piece in parseChatText(text) {
                var a = AttributedString(piece.code || piece.codeBlock ? (piece.codeBlock ? piece.text.trimmingCharacters(in: .newlines) : piece.text) : piece.text)
                if let url = piece.url {
                    let target = url.hasPrefix("http") || url.hasPrefix("dorr://") ? url : "https://\(url)"
                    a.link = URL(string: target)
                    a.foregroundColor = linkColor
                    a.underlineStyle = .single
                    a.font = .dorr(15.5 * prefs.fontScale, .semibold)
                } else if piece.code || piece.codeBlock {
                    a.font = .system(size: 14, design: .monospaced)
                    a.backgroundColor = codeBg
                } else {
                    var weight: Font.Weight = .regular
                    if piece.bold { weight = .bold }
                    var f = Font.dorr(15.5 * prefs.fontScale, weight)
                    if piece.italic { f = f.italic() }
                    a.font = f
                    if piece.strike { a.strikethroughStyle = .single }
                }
                out += a
            }
            return out
        }
        var out = AttributedString()
        if let lines = chatLines(body) {
            for (i, line) in lines.enumerated() {
                switch line.kind {
                case .plain: out += pieces(line.text)
                case .quote:
                    var bar = AttributedString("▍ ")
                    bar.foregroundColor = mentionColor.opacity(0.55)
                    out += bar
                    var q = pieces(line.text)
                    q.font = .dorr(15.5 * prefs.fontScale).italic()
                    out += q
                default:
                    var marker = AttributedString(line.marker + "  ")
                    marker.font = .dorr(15.5 * prefs.fontScale, .bold)
                    out += marker
                    out += pieces(line.text)
                }
                if i < lines.count - 1 { out += AttributedString("\n") }
            }
        } else {
            out = pieces(body)
        }
        let names = (m.dto.mentions ?? []).compactMap(\.name).filter { !$0.isEmpty }
        for name in names {
            var search = out.startIndex
            while let r = out[search...].range(of: "@\(name)") {
                out[r].foregroundColor = mentionColor
                out[r].font = .dorr(15.5 * prefs.fontScale, .heavy)
                search = r.upperBound
            }
        }
        return out
    }

    private func open(_ url: URL) -> OpenURLAction.Result {
        let link = url.absoluteString.trimmingCharacters(in: CharacterSet(charactersIn: ".,)"))
        // A call link: its sheet, right here.
        if let token = callLinkTokenOf(link) {
            host.callLinkToken = token
            return .handled
        }
        // A Dorr group invite opens the join sheet right here, not a browser.
        if link.hasPrefix("dorr://chat/join/") || link.range(of: "^https?://[^/]+/j/[A-Za-z0-9]{8,64}$", options: .regularExpression) != nil {
            if let token = inviteTokenOf(link) { actions?.onJoinGroup(token) }
            return .handled
        }
        // A «click to chat» link (backlog 130): that person's chat, the message ready.
        if link.hasPrefix("dorr://chat/") {
            host.openChatLink(link)
            return .handled
        }
        return .systemAction
    }
}

/// Time · edited · star · ticks — at the bottom corner of every bubble.
struct BubbleFooter: View {
    @ObservedObject private var ch = Ch.shared
    let m: UiMessage
    let mine: Bool
    let overlay: Bool

    var body: some View {
        let dto = m.dto
        let color: Color = overlay ? .white : mine ? ch.outText.opacity(0.8) : ch.soft
        HStack(spacing: 3) {
            if dto.expiresAt != nil { Image(systemName: "timer").font(.system(size: 10)) }
            if dto.isStarred == true { Image(systemName: "star.fill").font(.system(size: 10)) }
            if dto.reminderAt != nil { Image(systemName: "alarm.fill").font(.system(size: 10)) }
            if dto.isFollowUp == true { Image(systemName: "flag.fill").font(.system(size: 10)) }
            // Channel posts: 👁 1.2K — how many followers saw it.
            if let views = dto.views {
                Image(systemName: "eye.fill").font(.system(size: 10))
                Text(verbatim: compactCount(views)).font(.dorr(10.5, .semibold))
            }
            if dto.isEdited == true { Text(L("ch_edited")).font(.dorr(10.5)).italic() }
            Text(verbatim: clockTime(dto.createdAt)).font(.dorr(10.5))
            if mine { ChTicks(status: m.status, onBubble: true, size: 15) }
        }
        .foregroundStyle(color)
        .lineLimit(1)
        .fixedSize()
        .padding(.horizontal, overlay ? 7 : 0).padding(.vertical, overlay ? 2 : 0)
        .background(overlay ? Color.black.opacity(0.38) : .clear, in: RoundedRectangle(cornerRadius: 10))
    }
}

// MARK: - Media

private struct MediaGrid: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var shield = ChatShield.shared
    @ObservedObject private var saver = ChatDataSaver.shared
    let m: UiMessage
    let mine: Bool
    let actions: BubbleActions

    var body: some View {
        let dto = m.dto
        let remote = dto.files
        let local = m.localFiles
        let count = remote.isEmpty ? local.count : remote.count
        let isVideo = dto.type == "video"
        let gif = dto.isGifVideo
        // «Blur photos and videos»: covered until I tap (others' media only).
        let covered = !mine && shield.blurMedia && !actions.isRevealed(m.id) && !remote.isEmpty
        // Data saver (spec 82): others' photos not on the phone yet wait for a tap.
        let holding = !isVideo && !mine && saver.active && !saver.loaded.contains(m.id) && !remote.isEmpty
        let cells = Array(0..<min(count, 4))
        let per = count == 1 ? 1 : 2
        let rows: [[Int]] = stride(from: 0, to: cells.count, by: per).map { Array(cells[$0..<min($0 + per, cells.count)]) }
        VStack(alignment: .leading, spacing: 0) {
            ZStack(alignment: .bottomTrailing) {
                VStack(spacing: 2) {
                    ForEach(rows, id: \.self) { row in
                        HStack(spacing: 2) {
                            ForEach(row, id: \.self) { i in cell(i, remote: remote, local: local, count: count, isVideo: isVideo, gif: gif, covered: covered, holding: holding) }
                        }
                    }
                }
                if (dto.body ?? "").isEmpty { BubbleFooter(m: m, mine: mine, overlay: true).padding(8) }
            }
            .frame(width: 260)
            .clipShape(RoundedRectangle(cornerRadius: 17))
            if !(dto.body ?? "").isEmpty { TextBody(m: m, mine: mine).frame(width: 260, alignment: .leading) }
        }
    }

    private func cell(_ i: Int, remote: [AttachmentDto], local: [ChatLocalFile], count: Int, isVideo: Bool, gif: Bool, covered: Bool, holding: Bool) -> some View {
        let ratio = count == 1 ? aspectOf(remote.first) : 1
        let width: CGFloat = count == 1 ? 260 : 129
        // Photos: the photo. Videos: their poster (the server's copy, or the one made on this phone).
        let url: URL? = isVideo ? remote[safe: i]?.thumbnail.flatMap { ApiClient.mediaUrl($0) } : remote[safe: i].flatMap { ApiClient.mediaUrl($0.url) }
        let localUrl: URL? = isVideo ? m.localThumb : local[safe: i]?.url
        let held = holding && !onPhone(url)
        return ZStack {
            ch.surfaceMuted
            if held {
                HeldPhoto(file: remote[safe: i])
            } else {
                ChatImage(url: url, local: url == nil ? localUrl : nil).blur(radius: covered ? 28 : 0)
            }
            if covered {
                Color.black.opacity(0.25)
                Image(systemName: "eye.slash.fill").font(.system(size: 26)).foregroundStyle(.white)
            }
            if isVideo && m.local != "pending" && !covered { PlayDisc() }
            if isVideo && gif { GifTag().frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottomLeading).padding(8) }
            // Length in the corner, like every messenger.
            if isVideo && !gif, let length = m.dto.meta?.int("duration_ms"), length > 0 {
                Text(verbatim: durationText(Int64(length))).font(.dorr(11, .bold)).foregroundStyle(.white)
                    .padding(.horizontal, 6).padding(.vertical, 2).background(Color.black.opacity(0.45), in: RoundedRectangle(cornerRadius: 8))
                    .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottomLeading).padding(8)
            }
            if i == 3 && count > 4 {
                Color.black.opacity(0.5)
                Text(verbatim: "+\(count - 4)").font(.dorr(26, .heavy)).foregroundStyle(.white)
            }
            if m.local == "pending" { UploadRing(progress: m.progress) }
        }
        .frame(width: width, height: width / ratio)
        .clipped()
        .contentShape(Rectangle())
        .onTapGesture {
            guard !remote.isEmpty else { return }
            if held { saver.loaded.insert(m.id) } else if covered { actions.onReveal(m) } else { actions.onOpenMedia(m.dto, i) }
        }
    }

    /// Downloaded before (it's in the phone's cache): shown as usual even while saving data.
    private func onPhone(_ url: URL?) -> Bool {
        guard let url else { return true }
        return URLCache.shared.cachedResponse(for: URLRequest(url: url)) != nil
    }

    private func aspectOf(_ a: AttachmentDto?) -> CGFloat {
        guard let w = a?.width, let h = a?.height, w > 0, h > 0 else { return 1.1 }
        return min(max(CGFloat(w) / CGFloat(h), 0.62), 1.8)
    }
}

private struct PlayDisc: View {
    var body: some View {
        Image(systemName: "play.fill").font(.system(size: 24)).foregroundStyle(.white)
            .frame(width: 52, height: 52).background(Color.black.opacity(0.45), in: Circle())
    }
}

/// The small «GIF» tag on a looping clip.
struct GifTag: View {
    var body: some View {
        Text(verbatim: "GIF").font(.dorr(11, .heavy)).foregroundStyle(.white)
            .padding(.horizontal, 6).padding(.vertical, 2).background(Color.black.opacity(0.5), in: RoundedRectangle(cornerRadius: 8))
    }
}

/// A spinning arc while a file uploads (real progress when it's known).
struct UploadRing: View {
    var progress: Double?
    var size: CGFloat = 46
    @State private var spin = false

    var body: some View {
        ZStack {
            Circle().fill(Color.black.opacity(0.45))
            Circle().stroke(Color.white.opacity(0.25), lineWidth: 3).padding(size * 0.13)
            if let progress {
                Circle().trim(from: 0, to: progress).stroke(.white, style: StrokeStyle(lineWidth: 3, lineCap: .round))
                    .rotationEffect(.degrees(-90)).padding(size * 0.13).animation(.easeOut(duration: 0.25), value: progress)
                Text(verbatim: "\(Int(progress * 100))").font(.dorr(10, .heavy)).foregroundStyle(.white)
            } else {
                Circle().trim(from: 0, to: 0.28).stroke(.white, style: StrokeStyle(lineWidth: 3, lineCap: .round))
                    .rotationEffect(.degrees(spin ? 360 : 0)).padding(size * 0.13)
                    .onAppear { withAnimation(.linear(duration: 0.9).repeatForever(autoreverses: false)) { spin = true } }
            }
        }
        .frame(width: size, height: size)
        .environment(\.layoutDirection, .leftToRight)
    }
}

// MARK: - Voice

/// Plays one voice note at a time (androidApp `chat/VoicePlayer.kt`): the current one, its progress and position, and
/// the 1× / 1.5× / 2× speed.
@MainActor
final class VoicePlayer: ObservableObject {
    static let shared = VoicePlayer()
    @Published private(set) var playingId: String?
    @Published private(set) var paused = false
    @Published private(set) var progress: Double = 0
    @Published private(set) var positionMs: Int64 = 0
    @Published private(set) var speed: Float = 1
    private var player: AVPlayer?
    private var observer: Any?
    private var endObserver: NSObjectProtocol?

    func isPlaying(_ id: String) -> Bool { playingId == id && !paused }

    func toggle(_ id: String, _ url: URL) {
        if playingId == id, let player {
            if paused { player.playImmediately(atRate: speed) } else { player.pause() }
            paused.toggle()
            return
        }
        stop()
        AiVoicePlayer.shared.stop()
        try? AVAudioSession.sharedInstance().setCategory(.playback, mode: .spokenAudio)
        try? AVAudioSession.sharedInstance().setActive(true)
        // The tunnel's header, or it answers with its warning page instead of the audio.
        let item = AVPlayerItem(asset: AVURLAsset(url: url, options: ["AVURLAssetHTTPHeaderFieldsKey": ["ngrok-skip-browser-warning": "1"]]))
        item.audioTimePitchAlgorithm = .timeDomain
        let p = AVPlayer(playerItem: item)
        observer = p.addPeriodicTimeObserver(forInterval: CMTime(value: 1, timescale: 20), queue: .main) { t in
            Task { @MainActor in
                guard VoicePlayer.shared.playingId == id, let d = p.currentItem?.duration.seconds, d.isFinite, d > 0 else { return }
                VoicePlayer.shared.progress = t.seconds / d
                VoicePlayer.shared.positionMs = Int64(t.seconds * 1000)
            }
        }
        endObserver = NotificationCenter.default.addObserver(forName: .AVPlayerItemDidPlayToEndTime, object: item, queue: .main) { _ in
            Task { @MainActor in if VoicePlayer.shared.playingId == id { VoicePlayer.shared.stop() } }
        }
        player = p
        playingId = id
        paused = false
        progress = 0
        p.playImmediately(atRate: speed)
    }

    func seek(_ id: String, _ fraction: Double) {
        guard playingId == id, let player, let d = player.currentItem?.duration.seconds, d.isFinite else { return }
        player.seek(to: CMTime(seconds: d * fraction, preferredTimescale: 600))
    }

    /// 1× → 1.5× → 2×
    func cycleSpeed() {
        speed = speed == 1 ? 1.5 : speed == 1.5 ? 2 : 1
        if let player, !paused { player.rate = speed }
    }

    func stop() {
        player?.pause()
        if let observer { player?.removeTimeObserver(observer) }
        if let endObserver { NotificationCenter.default.removeObserver(endObserver) }
        observer = nil
        endObserver = nil
        player = nil
        playingId = nil
        paused = false
        progress = 0
        positionMs = 0
    }
}

/// Voice note: round play button, the waveform that fills in the brand colour as it plays (tap on it to seek), the
/// duration, and a 1× / 1.5× / 2× speed pill while playing.
private struct VoiceNote: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var player = VoicePlayer.shared
    let m: UiMessage
    let mine: Bool

    var body: some View {
        HStack(spacing: 8) {
            playButton
            VStack(alignment: .leading, spacing: 2) {
                wave
                info
            }
        }
        .frame(width: 232)
        .padding(.leading, 8).padding(.trailing, 10).padding(.top, 8).padding(.bottom, 6)
    }

    private var playButton: some View {
        let dto = m.dto
        let url = dto.files.first.flatMap { ApiClient.mediaUrl($0.url) }
        let playing = player.isPlaying(dto.id)
        return ZStack {
            Circle().fill(mine ? Color.white : ch.red)
            if m.local == "pending" {
                UploadRing(progress: m.progress, size: 42)
            } else {
                Image(systemName: playing ? "pause.fill" : "play.fill").font(.system(size: 20)).foregroundStyle(mine ? ch.red : Color.white)
            }
        }
        .frame(width: 42, height: 42)
        .scaleEffect(playing ? 1.08 : 1)
        .animation(.spring(response: 0.3, dampingFraction: 0.4), value: playing)
        .onTapGesture { if let url, m.local == nil { player.toggle(dto.id, url) } }
    }

    private var wave: some View {
        let id = m.dto.id
        let waveform = waveformOf(m.dto)
        let progress: Double = player.playingId == id ? player.progress : 0
        let fg: Color = mine ? ch.outText : ch.red
        let track: Color = mine ? ch.outText.opacity(0.38) : ch.red.opacity(0.22)
        return GeometryReader { geo in
            WaveBars(bars: waveform, progress: progress, fg: fg, track: track)
                .contentShape(Rectangle())
                .gesture(DragGesture(minimumDistance: 0).onEnded { v in player.seek(id, min(max(v.location.x / geo.size.width, 0), 1)) })
        }
        .frame(height: 30)
        .environment(\.layoutDirection, .leftToRight)
    }

    private var info: some View {
        let current = player.playingId == m.dto.id
        let shown: Int64 = current ? player.positionMs : durationOf(m.dto)
        let speed = player.speed == 1 ? "1" : player.speed == 2 ? "2" : "1.5"
        return HStack(spacing: 6) {
            Text(verbatim: durationText(shown)).font(.dorr(11)).foregroundStyle(mine ? ch.outText.opacity(0.85) : ch.mut)
            if current {
                Text(verbatim: speed + "×")
                    .font(.dorr(10.5, .heavy)).foregroundStyle(mine ? ch.red : Color.white)
                    .padding(.horizontal, 6).padding(.vertical, 1)
                    .background(mine ? Color.white : ch.red, in: RoundedRectangle(cornerRadius: 8))
                    .onTapGesture { player.cycleSpeed() }
            }
            Spacer(minLength: 4)
            BubbleFooter(m: m, mine: mine, overlay: false)
        }
    }
}

/// The waveform: bars filled in the brand colour up to where it's played.
private struct WaveBars: View {
    let bars: [Int]
    let progress: Double
    let fg: Color
    let track: Color

    var body: some View {
        Canvas { gc, size in draw(gc, size) }
    }

    private func draw(_ gc: GraphicsContext, _ size: CGSize) {
        let n = bars.count
        guard n > 0 else { return }
        let gap: CGFloat = 2
        let w: CGFloat = (size.width - gap * CGFloat(n - 1)) / CGFloat(n)
        for (i, v) in bars.enumerated() {
            let scaled: CGFloat = 0.18 + 0.82 * CGFloat(v) / 100
            let h: CGFloat = max(size.height * scaled, 3)
            let filled: Bool = (Double(i) + 0.5) / Double(n) <= progress
            let rect = CGRect(x: CGFloat(i) * (w + gap), y: (size.height - h) / 2, width: w, height: h)
            gc.fill(Path(roundedRect: rect, cornerRadius: w / 2), with: .color(filled ? fg : track))
        }
    }
}

private func waveformOf(_ dto: MessageDto) -> [Int] {
    let bars: [Int] = dto.meta?.at("waveform")?.arr?.compactMap { $0.int } ?? []
    if !bars.isEmpty { return bars }
    return (0..<36).map { (i: Int) -> Int in 20 + ((i * 37) % 60) }
}

private func durationOf(_ dto: MessageDto) -> Int64 {
    let meta = Int64(dto.meta?.int("duration_ms") ?? 0)
    return meta > 0 ? meta : (dto.files.first?.durationMs ?? 0)
}

// MARK: - Files, location, contact

private struct DocumentCard: View {
    @ObservedObject private var ch = Ch.shared
    let m: UiMessage
    let mine: Bool

    var body: some View {
        let file = m.dto.files.first
        // A folder sent as one zip (spec 10): its own name, and how many files were in it.
        let folder = m.dto.folderInfo
        let name = folder?.0 ?? file?.name ?? m.localFiles.first?.name ?? ""
        let ext = folder != nil ? "ZIP" : String((name as NSString).pathExtension.uppercased().prefix(4))
        let pdf = folder == nil && file != nil && ChatViewers.isPdf(name, file?.mimeType)
        VStack(alignment: .trailing, spacing: 0) {
            // A small PDF shows its first page.
            if pdf, let file, m.local == nil {
                PdfFirstPage(url: file.url, size: file.size).padding(.bottom, 6).onTapGesture { open(file, name: name, pdf: true) }
            }
            HStack(spacing: 10) {
                ZStack {
                    RoundedRectangle(cornerRadius: 10).fill(mine ? .white : ch.red)
                    if m.local == "pending" { UploadRing(progress: m.progress, size: 42) }
                    else {
                        VStack(spacing: 0) {
                            Image(systemName: folder != nil ? "folder.fill" : "doc.text.fill").font(.system(size: 17))
                            if !ext.isEmpty { Text(verbatim: ext).font(.dorr(8, .heavy)) }
                        }
                        .foregroundStyle(mine ? ch.red : .white)
                    }
                }
                .frame(width: 42, height: 42)
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: name).font(.dorr(13.5, .bold)).foregroundStyle(mine ? ch.outText : ch.ink).lineLimit(2)
                    let size = fileSizeText(file?.size)
                    let count = folder?.1.map { L("fg_files", $0) }
                    Text(verbatim: [count, size.isEmpty ? nil : size].compactMap { $0 }.joined(separator: " · "))
                        .font(.dorr(11)).foregroundStyle(mine ? ch.outText.opacity(0.75) : ch.mut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(10)
            .background(mine ? ch.outText.opacity(0.16) : ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 14))
            .contentShape(Rectangle())
            .onTapGesture { if let file { open(file, name: name, pdf: pdf) } }
            if !(m.dto.body ?? "").isEmpty {
                TextBody(m: m, mine: mine).frame(maxWidth: .infinity, alignment: .leading)
            } else {
                BubbleFooter(m: m, mine: mine, overlay: false).padding(.top, 4).padding(.trailing, 4)
            }
        }
        .frame(width: 260)
        .padding(6)
    }

    /// A PDF opens inside the chat; other files in the system's viewer.
    private func open(_ file: AttachmentDto, name: String, pdf: Bool) {
        if pdf { ChatViewers.shared.pdf = PdfTarget(url: file.url, name: name) } else if let url = ApiClient.mediaUrl(file.url) { UIApplication.shared.open(url) }
    }
}

/// The drawn map tile shared by the location cards (no map key needed).
struct DrawnMap: View {
    var body: some View {
        Canvas { gc, size in
            gc.fill(Path(CGRect(origin: .zero, size: size)), with: .color(Color(hex: 0xE8F0E3)))
            for i in 0...6 {
                gc.fill(Path(CGRect(x: CGFloat(i) * size.width / 5 - 20, y: CGFloat(i % 3) * size.height / 3 + 12, width: size.width / 7, height: size.height / 4.5)), with: .color(Color(hex: 0xD5E4CF)))
            }
            func line(_ a: CGPoint, _ b: CGPoint, _ color: Color, _ width: CGFloat) {
                var p = Path()
                p.move(to: a)
                p.addLine(to: b)
                gc.stroke(p, with: .color(color), lineWidth: width)
            }
            line(CGPoint(x: 0, y: size.height * 0.62), CGPoint(x: size.width, y: size.height * 0.38), .white, 14)
            line(CGPoint(x: size.width * 0.3, y: 0), CGPoint(x: size.width * 0.44, y: size.height), .white, 9)
            line(CGPoint(x: size.width * 0.75, y: 0), CGPoint(x: size.width, y: size.height * 0.3), Color(hex: 0xBFDBFE), 18)
        }
        .environment(\.layoutDirection, .leftToRight)
    }
}

func openMaps(_ lat: Double, _ lng: Double) {
    if let url = URL(string: "http://maps.apple.com/?ll=\(lat),\(lng)&q=\(lat),\(lng)") { UIApplication.shared.open(url) }
}

/// A stylised map tile with the pin bouncing in.
private struct LocationCard: View {
    @ObservedObject private var ch = Ch.shared
    let meta: JSONAny?
    let m: UiMessage
    let mine: Bool
    @State private var pin: CGFloat = 0

    var body: some View {
        let lat = meta?.at("latitude")?.dbl ?? 0
        let lng = meta?.at("longitude")?.dbl ?? 0
        VStack(alignment: .leading, spacing: 0) {
            ZStack(alignment: .bottomTrailing) {
                DrawnMap()
                Image(systemName: "mappin.circle.fill").font(.system(size: 40)).foregroundStyle(ch.red)
                    .offset(y: -(1 - pin) * 60 - 14).opacity(pin)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                BubbleFooter(m: m, mine: mine, overlay: true).padding(8)
            }
            .frame(height: 140)
            .clipShape(RoundedRectangle(cornerRadius: 17))
            .onTapGesture { openMaps(lat, lng) }
            HStack(spacing: 6) {
                Image(systemName: "map.fill").font(.system(size: 14)).foregroundStyle(mine ? ch.outText : ch.red)
                Text(verbatim: meta?.str("name") ?? L("ch_open_maps")).font(.dorr(13, .bold)).foregroundStyle(mine ? ch.outText : ch.ink).lineLimit(1)
            }
            .padding(.horizontal, 9).padding(.vertical, 7)
        }
        .frame(width: 260)
        .padding(3)
        .task { withAnimation(.spring(response: 0.5, dampingFraction: 0.35)) { pin = 1 } }
    }
}

private struct ContactCard: View {
    @ObservedObject private var ch = Ch.shared
    let meta: JSONAny?
    let m: UiMessage
    let mine: Bool
    let actions: BubbleActions
    @State private var sheet = false

    var body: some View {
        let name = meta?.str("name") ?? ""
        let phones = meta?.at("phones")?.arr?.compactMap(\.str) ?? []
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 10) {
                ChAvatar(url: nil, name: name, key: phones.first ?? name, size: 44)
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: name).font(.dorr(14.5, .heavy)).foregroundStyle(mine ? ch.outText : ch.ink).lineLimit(1)
                    if let first = phones.first {
                        Text(verbatim: ltrNumber(first) + (phones.count > 1 ? "  +\(phones.count - 1)" : "")).font(.dorr(12)).foregroundStyle(mine ? ch.outText.opacity(0.8) : ch.mut)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            Rectangle().fill(mine ? ch.outText.opacity(0.25) : ch.line).frame(height: 1).padding(.top, 8)
            HStack {
                Text(L("ch_message_contact")).font(.dorr(13.5, .heavy)).foregroundStyle(mine ? ch.outText : ch.red)
                    .padding(.vertical, 4).frame(maxWidth: .infinity, alignment: .leading)
                BubbleFooter(m: m, mine: mine, overlay: false)
            }
            .padding(.top, 6)
        }
        .frame(width: 250)
        .padding(8)
        .contentShape(Rectangle())
        // The whole card opens: call · message or invite · save · copy.
        .onTapGesture { if !phones.isEmpty { sheet = true } }
        .dorrDialog(isPresented: $sheet, bottom: true) {
            ContactActionsSheet(name: name, phones: phones, onMessage: { actions.onMessageContact($0) }) { sheet = false }
        }
    }
}

// MARK: - Wallet & calls

/// The occasion's emoji and title, for the gift card in the chat.
let giftCards: [(key: String, emoji: String, title: String)] = [
    ("general", "🎁", "ch_gift_general"), ("birthday", "🎂", "ch_gift_birthday"), ("eid", "🌙", "ch_gift_eid"),
    ("ramadan", "🏮", "ch_gift_ramadan"), ("wedding", "💍", "ch_gift_wedding"), ("newborn", "👶", "ch_gift_newborn"),
    ("graduation", "🎓", "ch_gift_graduation"), ("congrats", "🎉", "ch_gift_congrats"), ("thanks", "💐", "ch_gift_thanks"),
]

func giftOf(_ key: String?) -> (String, String)? {
    giftCards.first { $0.key == key }.map { ($0.emoji, L($0.title)) }
}

/// The transfer receipt: a small «success» card like the wallet's own success screen — amount big, a check that
/// pops, the masked recipient and the reference. Built by the server from the sender's real transfer.
private struct TransferReceiptCard: View {
    @ObservedObject private var ch = Ch.shared
    let meta: JSONAny?
    let m: UiMessage
    @State private var check: CGFloat = 0

    var body: some View {
        let amount = meta?.int("amount_minor") ?? 0
        let currency = meta?.str("currency_symbol") ?? meta?.str("currency")
        let reversed = meta?.at("is_reversed")?.bool == true
        VStack(alignment: .leading, spacing: 0) {
            // A money gift: the same receipt, drawn as a card for the occasion (it unwraps as it appears).
            if let gift = giftOf(meta?.str("gift")) {
                VStack(spacing: 2) {
                    Text(verbatim: gift.0).font(.system(size: 46)).scaleEffect(0.6 + 0.4 * check)
                    Text(verbatim: gift.1).font(.dorr(17, .heavy)).foregroundStyle(.white)
                }
                .frame(maxWidth: .infinity).padding(.bottom, 10)
            }
            HStack(spacing: 10) {
                Image(systemName: reversed ? "exclamationmark.circle.fill" : "checkmark.circle.fill").font(.system(size: 22)).foregroundStyle(ch.red)
                    .frame(width: 34, height: 34).background(ch.surface, in: Circle()).scaleEffect(check)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("ch_transfer_receipt")).font(.dorr(11.5, .bold)).foregroundStyle(.white.opacity(0.85))
                    Text(L(reversed ? "ch_transfer_reversed" : "ch_transfer_done")).font(.dorr(14, .heavy)).foregroundStyle(.white)
                }
            }
            Text(verbatim: formatMinor(amount, currency)).font(.dorr(30, .heavy)).foregroundStyle(.white).kerning(-1)
                .environment(\.layoutDirection, .leftToRight).padding(.top, 14)
            if let to = meta?.str("recipient_name") { Text(L("ch_transfer_to", to)).font(.dorr(13, .semibold)).foregroundStyle(.white.opacity(0.9)).padding(.top, 6) }
            if let number = meta?.str("recipient_wallet_number") { Text(verbatim: number).font(.dorr(12)).foregroundStyle(.white.opacity(0.7)) }
            Rectangle().fill(.white.opacity(0.25)).frame(height: 1).padding(.top, 10)
            HStack {
                Text(verbatim: "#" + String((meta?.str("transaction_id") ?? "").prefix(8)).uppercased()).font(.dorr(11)).foregroundStyle(.white.opacity(0.7))
                Spacer()
                BubbleFooter(m: m, mine: true, overlay: false)
            }
            .padding(.top, 8)
        }
        .padding(16)
        .frame(width: 262)
        .background(ch.headerBrush, in: RoundedRectangle(cornerRadius: 20))
        .task { withAnimation(.spring(response: 0.5, dampingFraction: 0.35)) { check = 1 } }
    }
}

/// Someone's wallet QR + «Send money» — opens the wallet's confirmation.
private struct WalletQrCard: View {
    @ObservedObject private var ch = Ch.shared
    let meta: JSONAny?
    let m: UiMessage
    let mine: Bool
    let actions: BubbleActions

    var body: some View {
        let payload = meta?.str("qr_payload") ?? ""
        VStack(spacing: 0) {
            HStack(spacing: 8) {
                Image(systemName: "qrcode").font(.system(size: 18)).foregroundStyle(.white)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("ch_wallet_qr_title")).font(.dorr(14, .heavy)).foregroundStyle(.white)
                    Text(verbatim: meta?.str("owner_name") ?? "").font(.dorr(12)).foregroundStyle(.white.opacity(0.8))
                }
                Spacer()
            }
            .padding(12)
            .background(ch.headerBrush)
            if let matrix = QrMatrix(text: payload) { QrCanvas(matrix: matrix).frame(width: 150, height: 150).padding(.top, 14) }
            Text(verbatim: meta?.str("wallet_number") ?? "").font(.dorr(14, .heavy)).foregroundStyle(ch.ink).padding(.top, 6)
            Text(L("ch_wallet_qr_sub")).font(.dorr(11.5)).foregroundStyle(ch.mut)
            if !mine && !payload.isEmpty {
                ChPrimaryButton(text: L("ch_wallet_qr_pay"), icon: "qrcode") { actions.onPayQr(payload) }.padding(12)
            }
            HStack {
                Spacer()
                BubbleFooter(m: m, mine: false, overlay: false)
            }
            .padding(.trailing, 12).padding(.bottom, 8).padding(.top, mine ? 10 : 0)
        }
        .frame(width: 250)
        .background(ch.surface)
        .clipShape(RoundedRectangle(cornerRadius: 20))
        .shadow(color: .black.opacity(0.1), radius: 4, y: 2)
    }
}

private struct CallLine: View {
    @EnvironmentObject private var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let meta: JSONAny?
    let m: UiMessage
    let mine: Bool

    var body: some View {
        let status = meta?.str("status")
        let video = meta?.str("call_type") == "video"
        let missed = ["missed", "cancelled", "declined"].contains(status ?? "")
        let duration = meta?.int("duration_seconds")
        let title = status == "missed" ? "ch_call_missed" : status == "declined" ? "ch_call_declined" : status == "cancelled" ? "ch_call_cancelled" : video ? "ch_call_video" : "ch_call_voice"
        HStack(spacing: 10) {
            Image(systemName: missed ? "phone.arrow.down.left.fill" : video ? "video.fill" : "phone.fill").font(.system(size: 17))
                .foregroundStyle(mine ? ch.outText : missed ? ch.danger : ch.success)
                .frame(width: 38, height: 38)
                .background(mine ? ch.outText.opacity(0.2) : missed ? Color(hex: 0xFEE2E2) : Color(hex: 0xDCFCE7), in: Circle())
            VStack(alignment: .leading, spacing: 2) {
                Text(L(title)).font(.dorr(14, .heavy)).foregroundStyle(mine ? ch.outText : ch.ink)
                HStack(spacing: 0) {
                    if let duration, duration > 0 { Text(verbatim: durationText(Int64(duration) * 1000) + "  ").font(.dorr(12)).foregroundStyle(mine ? ch.outText.opacity(0.8) : ch.mut) }
                    BubbleFooter(m: m, mine: mine, overlay: false)
                }
                // Recorded with everyone's yes (spec 92): tap for the recordings I agreed to.
                if meta?.at("recorded")?.bool == true, let callId = meta?.str("call_id") {
                    Button { host.recordingsCall = callId } label: {
                        HStack(spacing: 5) {
                            Image(systemName: "circle.fill").font(.system(size: 9)).foregroundStyle(mine ? ch.outText : Color(hex: 0xEF4444))
                            Text(L("cr_recorded")).font(.dorr(12, .bold)).foregroundStyle(mine ? ch.outText : ch.red)
                        }
                        .padding(.vertical, 2)
                    }
                    .buttonStyle(.plain)
                    .padding(.top, 4)
                }
            }
        }
        .padding(.horizontal, 12).padding(.vertical, 10)
    }
}

// MARK: - Reactions & typing

/// Reaction chips hanging under the bubble; they spring in when the first one lands.
private struct Reactions: View {
    @ObservedObject private var ch = Ch.shared
    let dto: MessageDto
    let mine: Bool

    var body: some View {
        let summary = dto.reactions?.summary ?? []
        if !summary.isEmpty {
            HStack(spacing: 2) {
                ForEach(summary.prefix(3), id: \.self) { Text(verbatim: $0.emoji).font(.system(size: 14)) }
                if (dto.reactions?.total ?? 0) > 1 {
                    Text(verbatim: "\(dto.reactions?.total ?? 0)").font(.dorr(11.5, .bold)).foregroundStyle(ch.mut).padding(.leading, 2)
                }
            }
            .padding(.horizontal, 7).padding(.vertical, 2)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 14))
            .overlay(RoundedRectangle(cornerRadius: 14).stroke(dto.reactions?.mine != nil ? ch.red.opacity(0.35) : .white, lineWidth: 1.5))
            .shadow(color: .black.opacity(0.12), radius: 3, y: 1)
            .padding(.horizontal, 10)
            .offset(y: -6)
            .transition(.scale.combined(with: .opacity))
            .animation(.spring(response: 0.35, dampingFraction: 0.4), value: summary)
        }
    }
}

/// «Ahmed is typing…» bubble at the bottom of the conversation.
struct TypingBubble: View {
    @ObservedObject private var ch = Ch.shared
    let label: String?

    var body: some View {
        HStack {
            HStack(spacing: 8) {
                TypingDots(color: ch.red.opacity(0.8), dot: 7)
                if let label { Text(verbatim: label).font(.dorr(12)).foregroundStyle(ch.mut) }
            }
            .padding(.horizontal, 16).padding(.vertical, 13)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 20))
            .shadow(color: .black.opacity(0.08), radius: 2, y: 1)
            Spacer()
        }
        .padding(.horizontal, 12).padding(.vertical, 4)
        .chPopIn(true)
    }
}


/// androidApp `ChatPerformanceUi.kt` HeldPhoto — a photo data saver held back (spec 82): the server's tiny preview,
/// softened, with the photo's size on a download button.
struct HeldPhoto: View {
    @ObservedObject private var ch = Ch.shared
    let file: AttachmentDto?

    var body: some View {
        ZStack {
            ch.surfaceMuted
            if let b64 = file?.preview, let data = Data(base64Encoded: b64, options: .ignoreUnknownCharacters), let image = UIImage(data: data) {
                Image(uiImage: image).resizable().scaledToFill().blur(radius: ChatLite.shared.active ? 0 : 10)
            }
            HStack(spacing: 6) {
                Image(systemName: "icloud.and.arrow.down.fill").font(.system(size: 15))
                if let size = file?.size { Text(verbatim: usageBytes(size)).font(.dorr(12, .bold)) }
            }
            .foregroundStyle(.white)
            .padding(.horizontal, 12).padding(.vertical, 7)
            .background(Color.black.opacity(0.55), in: Capsule())
        }
        .clipped()
    }
}
