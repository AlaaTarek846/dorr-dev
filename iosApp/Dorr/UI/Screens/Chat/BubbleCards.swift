import CoreLocation
import SwiftUI

// The cards a bubble can hold, from androidApp `ChatExtrasUi.kt` (link preview, poll, view once, live location),
// `ChatMoneyUi.kt` (money request, bill split), `ChatExpressionsUi.kt` (sticker, GIF), `ChatShieldUi.kt` (sensitive),
// `ChatAiUi.kt` (ChatAi, the AI note, «show text»), `ChatOrganizeUi.kt` (thread chip), `ChatInteractiveUi.kt` (reply
// buttons / list) and `StoryVoice.kt` (story mention).

/// Which AI tools the server offers right now (an AI provider is set up and the admin left them on). Loaded when the
/// chat opens; the buttons only show when their tool is available.
@MainActor
final class ChatAi: ObservableObject {
    static let shared = ChatAi()
    @Published private(set) var caps: AiCapabilitiesDto?

    func load() async {
        if let c = try? await ChatApi.aiCapabilities().data { caps = c }
    }

    var translate: Bool { caps?.translate == true }
    var transcribe: Bool { caps?.transcribe == true }
    var summarize: Bool { caps?.summarize == true }
    var smartReplies: Bool { caps?.smartReplies == true }
    var ask: Bool { caps?.ask == true }
    var proofread: Bool { caps?.proofread == true }
    var commitments: Bool { caps?.commitments == true }
    var assistant: Bool { caps?.assistant == true }
    var understand: Bool { caps?.understand == true }
    var simplify: Bool { caps?.simplify == true }
    var tasks: Bool { caps?.tasks == true }
    var notes: Bool { caps?.notes == true }
    var dates: Bool { caps?.dates == true }
    var important: Bool { caps?.important == true }
    var relatedFiles: Bool { caps?.relatedFiles == true }
    var today: Bool { caps?.today == true }
    var imageGenerate: Bool { caps?.imageGenerate == true }
    var imageEdit: Bool { caps?.imageEdit == true }
    var searchMeaning: Bool { caps?.searchMeaning == true }
    var memory: Bool { caps?.memory == true }
    var capsule: Bool { caps?.capsule == true }
    var aiImages: Bool { caps?.aiImages == true }
}

/// The page behind a link: its picture on top, then site · title · a line of text. Tap → browser.
struct LinkPreviewCard: View {
    @ObservedObject private var ch = Ch.shared
    let preview: LinkPreviewDto
    let mine: Bool
    @State private var pop: CGFloat = 0

    /// As in WhatsApp: the picture, the title, a line of text, then the site's address with a link icon. Each line reads
    /// the way its words do (English left to right).
    var body: some View {
        let ink = mine ? ch.outText : ch.inText
        let host = URL(string: preview.url)?.host.map { $0.hasPrefix("www.") ? String($0.dropFirst(4)) : $0 } ?? (preview.siteName ?? "")
        let rtl = startsArabic(preview.title ?? preview.description ?? "")
        VStack(alignment: .leading, spacing: 0) {
            if let image = preview.image {
                ChatImage(url: ApiClient.mediaUrl(image)).frame(maxWidth: .infinity).frame(height: 150).clipped()
            }
            VStack(alignment: .leading, spacing: 2) {
                if let title = preview.title { Text(verbatim: title).font(.dorr(14, .bold)).foregroundStyle(ink).lineLimit(2) }
                if let d = preview.description { Text(verbatim: d).font(.dorr(12.5)).foregroundStyle(ink.opacity(0.75)).lineLimit(2) }
                if !host.isEmpty {
                    HStack(spacing: 5) {
                        Image(systemName: "link").font(.system(size: 13))
                        Text(verbatim: host).font(.dorr(12.5)).lineLimit(1)
                    }
                    .foregroundStyle(ink.opacity(0.8))
                    .padding(.top, 2)
                    .environment(\.layoutDirection, .leftToRight)
                }
            }
            .multilineTextAlignment(.leading)
            .padding(.horizontal, 10).padding(.vertical, 8)
            .frame(maxWidth: .infinity, alignment: .leading)
            .environment(\.layoutDirection, rtl ? .rightToLeft : .leftToRight)
        }
        // As wide as the bubble (no strip beside it).
        .frame(maxWidth: .infinity)
        .background(mine ? Color.black.opacity(0.14) : ch.surfaceMuted)
        .clipShape(RoundedRectangle(cornerRadius: 11))
        .opacity(pop).scaleEffect(x: 1, y: 0.85 + 0.15 * pop, anchor: .top)
        .onTapGesture { if let url = URL(string: preview.url) { UIApplication.shared.open(url) } }
        .padding(.horizontal, 3).padding(.top, 3)
        .task(id: preview.url) { withAnimation(.spring(response: 0.4, dampingFraction: 0.7)) { pop = 1 } }
    }

    private func startsArabic(_ text: String) -> Bool {
        for s in text.unicodeScalars where CharacterSet.letters.contains(s) {
            switch s.value {
            case 0x0590...0x08FF, 0xFB1D...0xFDFF, 0xFE70...0xFEFF: return true
            default: return false
            }
        }
        return false
    }
}

/// The message is only a link (nothing else written): under its card, the address isn't written out again.
func isOnlyLink(_ body: String?) -> Bool {
    let b = (body ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
    let lower = b.lowercased()
    return !b.isEmpty && !b.contains(where: \.isWhitespace) && (lower.hasPrefix("http://") || lower.hasPrefix("https://") || lower.hasPrefix("www."))
}

/// A poll: the question, then each option as a row whose bar fills with its share of the votes (springing as votes
/// arrive live), a tick for mine, and «View votes» at the bottom.
struct PollCard<Footer: View>: View {
    @ObservedObject private var ch = Ch.shared
    let m: UiMessage
    let mine: Bool
    let onVote: (Int) -> Void
    let onShowVotes: () -> Void
    @ViewBuilder let footer: () -> Footer

    var body: some View {
        if let poll = m.dto.poll {
            let ink = mine ? ch.outText : ch.inText
            let accent = mine ? ch.outText : ch.red
            let options = poll.options ?? []
            let total = max(options.reduce(0) { $0 + ($1.votes ?? 0) }, 1)
            let top = options.map { $0.votes ?? 0 }.max() ?? 0
            let multiple = poll.multiple == true
            let voters = poll.voters ?? 0
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 5) {
                    Image(systemName: "chart.bar.fill").font(.system(size: 13)).foregroundStyle(accent)
                    Text(L(multiple ? "ch_poll_pick_many" : "ch_poll_pick_one")).font(.dorr(11, .semibold)).foregroundStyle(ink.opacity(0.65))
                }
                Text(verbatim: poll.question ?? "").font(.dorr(14.5, .heavy)).foregroundStyle(ink).padding(.top, 2).padding(.bottom, 4)
                ForEach(Array(options.enumerated()), id: \.element.id) { i, option in
                    let chosen = (poll.myVotes ?? []).contains(option.id)
                    let votes = option.votes ?? 0
                    VStack(alignment: .leading, spacing: 3) {
                        HStack(spacing: 6) {
                            Image(systemName: chosen ? (multiple ? "checkmark.square.fill" : "checkmark.circle.fill") : (multiple ? "square" : "circle"))
                                .font(.system(size: 16)).foregroundStyle(chosen ? accent : ink.opacity(0.45))
                                .frame(width: 18).scaleEffect(chosen ? 1 : 0.92)
                                .animation(.spring(response: 0.3, dampingFraction: 0.45), value: chosen)
                            Text(verbatim: option.text).font(.dorr(13, chosen ? .heavy : .semibold)).foregroundStyle(ink).frame(maxWidth: .infinity, alignment: .leading)
                            Text(verbatim: "\(votes)").font(.dorr(11.5, .bold)).foregroundStyle(ink.opacity(0.75))
                                .contentTransition(.numericText()).animation(.default, value: votes)
                        }
                        GeometryReader { geo in
                            ZStack(alignment: .leading) {
                                Capsule().fill(ink.opacity(0.12))
                                Capsule().fill(votes == top && top > 0 ? accent : accent.opacity(0.55))
                                    .frame(width: geo.size.width * CGFloat(votes) / CGFloat(total))
                                    .animation(.spring(response: 0.5, dampingFraction: 0.75), value: votes)
                            }
                        }
                        .frame(height: 4).padding(.leading, 24)
                    }
                    .padding(.vertical, 4)
                    .contentShape(Rectangle())
                    .onTapGesture { if m.local == nil { onVote(option.id) } }
                    .chStagger(i)
                }
                HStack {
                    Button(action: onShowVotes) {
                        HStack(spacing: 4) {
                            Image(systemName: "chart.bar.xaxis").font(.system(size: 13))
                            Text(voters == 0 ? L("ch_poll_no_votes") : L("ch_poll_view_votes", voters)).font(.dorr(12.5, .heavy))
                        }
                        .foregroundStyle(accent).padding(.horizontal, 6).padding(.vertical, 4)
                    }
                    .buttonStyle(.plain).disabled(voters == 0)
                    Spacer()
                    footer()
                }
                .padding(.top, 3)
            }
            .frame(width: 216)
            .padding(.horizontal, 10).padding(.vertical, 7)
        }
    }
}

/// A view-once photo / video / voice note: never a preview, just a round «1» badge and what it is. Theirs: tap to
/// open (once). Opened: greyed «Opened». Mine: «Photo» → «Opened» when seen.
struct ViewOnceCard<Footer: View>: View {
    @ObservedObject private var ch = Ch.shared
    let m: UiMessage
    let mine: Bool
    let onOpen: () -> Void
    @ViewBuilder let footer: () -> Footer
    @State private var glow = false

    var body: some View {
        let dto = m.dto
        let opened = dto.viewOnceOpened == true
        let ink = mine ? ch.outText : ch.inText
        let canOpen = !mine && !opened && m.local == nil
        let (icon, label) = dto.type == "video" ? ("video.fill", "ch_view_once_video") : dto.type == "voice" ? ("mic.fill", "ch_view_once_voice") : ("photo.fill", "ch_view_once_photo")
        HStack(spacing: 10) {
            ZStack {
                if opened {
                    Circle().stroke(ink.opacity(0.35), lineWidth: 2)
                    Image(systemName: "eye.fill").font(.system(size: 17)).foregroundStyle(ink.opacity(0.5))
                } else {
                    Circle().fill(mine ? ch.outText.opacity(0.2) : ch.red)
                    Text(verbatim: "1").font(.dorr(17, .heavy)).foregroundStyle(.white)
                }
            }
            .frame(width: 42, height: 42)
            .scaleEffect(canOpen ? (glow ? 1.08 : 0.85) : 1)
            VStack(alignment: .leading, spacing: 1) {
                HStack(spacing: 4) {
                    Image(systemName: icon).font(.system(size: 13)).foregroundStyle(ink.opacity(opened ? 0.5 : 0.9))
                    Text(L(opened ? "ch_view_once_opened" : label)).font(.dorr(14, .heavy)).foregroundStyle(ink.opacity(opened ? 0.55 : 1))
                }
                if canOpen { Text(L("ch_view_once_tap")).font(.dorr(11.5)).foregroundStyle(ink.opacity(0.6)) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            footer()
        }
        .frame(width: 206)
        .padding(.horizontal, 12).padding(.vertical, 11)
        .contentShape(Rectangle())
        .onTapGesture { if canOpen { onOpen() } }
        .onAppear { if canOpen { withAnimation(.easeInOut(duration: 1.1).repeatForever(autoreverses: true)) { glow = true } } }
    }
}

/// A live location: the drawn map with a pin that glides to each new position, a pulsing ring while it's live, «Live
/// until 3:45 PM» (a countdown in the last hour), and — mine — «Stop sharing».
struct LiveLocationCard<Footer: View>: View {
    @ObservedObject private var ch = Ch.shared
    let meta: JSONAny?
    let live: LiveLocationDto
    let mine: Bool
    let onStop: () -> Void
    /// Whose location it is (their photo is the pin).
    var sender: ProfileDto?
    /// «View live location» in the app; the phone's maps app without one.
    var onOpen: (() -> Void)?
    @ViewBuilder let footer: () -> Footer
    @State private var nudge: CGFloat = 0

    var body: some View {
        let lat = meta?.at("latitude")?.dbl ?? 0
        let lng = meta?.at("longitude")?.dbl ?? 0
        let ink = mine ? ch.outText : ch.inText
        // The label is worked out from the device clock (no server calls): redrawn every 15 s.
        TimelineView(.periodic(from: .now, by: 15)) { ctx in
            let until = parseRideTime(live.liveUntil)
            let active = live.active == true && (until.map { $0 > ctx.date } ?? false)
            // The app's red while live (as the rest of the chat), grey once it has ended.
            let activeColor = active ? ch.red : ch.mut
            VStack(alignment: .leading, spacing: 0) {
                ZStack {
                    // The real map around the last position (~10 m steps: a new picture only after a real move).
                    MapSnapshotView(center: CLLocationCoordinate2D(latitude: (lat * 10_000).rounded() / 10_000, longitude: (lng * 10_000).rounded() / 10_000)) { DrawnMap() }
                    // Their photo is the pin (the app's red ring, a red pulse while live), as in WhatsApp.
                    LiveAvatarPin(sender: sender, active: active, size: 44)
                        .offset(y: nudge)
                    Text(L(active ? "ch_live_badge" : "ch_live_ended"))
                        .font(.dorr(11, .heavy)).foregroundStyle(.white)
                        .padding(.leading, 17).padding(.trailing, 8).padding(.vertical, 3)
                        .overlay(alignment: .leading) { Circle().fill(activeColor).frame(width: 7, height: 7).padding(.leading, 6) }
                        .background(Color.black.opacity(0.45), in: RoundedRectangle(cornerRadius: 10))
                        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading).padding(8)
                    footer().frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottomTrailing).padding(8)
                }
                .frame(height: 150)
                .clipShape(RoundedRectangle(cornerRadius: 11))
                .onTapGesture { if let onOpen { onOpen() } else { openMaps(lat, lng) } }
                HStack(spacing: 6) {
                    Image(systemName: "location.fill").font(.system(size: 14)).foregroundStyle(active && !mine ? activeColor : ink.opacity(active ? 1 : 0.6))
                    Text(verbatim: active ? liveLabel(until!, ctx.date) : L("ch_live_ended_long")).font(.dorr(13, .bold)).foregroundStyle(ink).lineLimit(1)
                }
                .padding(.horizontal, 9).padding(.vertical, 7)
                if mine && active {
                    Button(action: onStop) {
                        HStack(spacing: 6) {
                            Image(systemName: "stop.circle.fill").font(.system(size: 16))
                            Text(L("ch_live_stop")).font(.dorr(13.5, .heavy))
                        }
                        .foregroundStyle(ink).frame(maxWidth: .infinity).padding(.vertical, 9)
                        .background(ch.outText.opacity(0.16), in: RoundedRectangle(cornerRadius: 12))
                    }
                    .buttonStyle(.plain).padding(.horizontal, 6).padding(.bottom, 6)
                }
            }
        }
        .frame(width: 260)
        .padding(3)
        // Each new position nudges the pin a little: it looks like it's walking.
        .onChange(of: "\(lat),\(lng)") { _ in
            nudge = -8
            withAnimation(.spring(response: 0.4, dampingFraction: 0.4)) { nudge = 0 }
        }
    }

    private func liveLabel(_ until: Date, _ now: Date) -> String {
        let minutes = max(Int(until.timeIntervalSince(now) / 60), 1)
        if minutes < 60 { return L("ch_live_minutes_left", minutes) }
        let f = DateFormatter()
        f.dateFormat = "h:mm a"
        return L("ch_live_until", f.string(from: until))
    }
}

// MARK: - Money

private let moneyGreen = [Color(hex: 0x10B981), Color(hex: 0x047857)]

extension PaymentDto {
    func money(_ minor: Int64) -> String { formatMinor(Int(minor), currencySymbol ?? currency) }
}

/// «Send me 50 · Dinner 🍕»: a green card with the amount counting up when it appears, a status chip, and — for the
/// other person — «Pay» (PIN) and «Decline».
struct MoneyRequestCard<Footer: View>: View {
    let m: UiMessage
    let onPay: () -> Void
    let onDecline: () -> Void
    let onCancel: () -> Void
    @ViewBuilder let footer: () -> Footer
    @State private var shown: Double = 0

    var body: some View {
        if let p = m.dto.payment {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 8) {
                    Image(systemName: "doc.text.fill").font(.system(size: 16)).foregroundStyle(.white)
                        .frame(width: 34, height: 34).background(Color.white.opacity(0.2), in: Circle())
                    Text(L(p.isRequester == true ? "ch_money_you_requested" : "ch_money_requested_from_you"))
                        .font(.dorr(12.5, .bold)).foregroundStyle(.white.opacity(0.9)).frame(maxWidth: .infinity, alignment: .leading)
                    MoneyStatusChip(status: p.status)
                }
                CountingMoney(p: p, minor: p.amountMinor ?? 0, progress: shown)
                    .padding(.top, 10)
                if let body = m.dto.body, !body.isEmpty { Text(verbatim: body).font(.dorr(14)).foregroundStyle(.white.opacity(0.92)).padding(.top, 2) }
                if p.canPay == true {
                    HStack(spacing: 8) {
                        MoneyButton(text: L("ch_money_pay"), icon: "lock.fill", filled: true, action: onPay)
                        MoneyButton(text: L("ch_money_decline"), icon: "xmark", filled: false, action: onDecline).frame(maxWidth: 100)
                    }
                    .padding(.top, 12)
                    .transition(.opacity.combined(with: .move(edge: .top)))
                }
                if p.canCancel == true {
                    Button(action: onCancel) {
                        Text(L("ch_money_cancel")).font(.dorr(13, .bold)).foregroundStyle(.white.opacity(0.85)).padding(.horizontal, 6).padding(.vertical, 4)
                    }
                    .buttonStyle(.plain).padding(.top, 10)
                }
                HStack { Spacer(); footer() }.padding(.top, 6)
            }
            .padding(14)
            .frame(width: 254)
            .background(LinearGradient(colors: moneyGreen, startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 18))
            .padding(4)
            .task(id: p.amountMinor) { withAnimation(.easeInOut(duration: 0.7)) { shown = 1 } }
        }
    }
}

/// The amount, counting up from zero the first time it shows.
private struct CountingMoney: View, Animatable {
    let p: PaymentDto
    let minor: Int64
    var progress: Double
    var animatableData: Double {
        get { progress }
        set { progress = newValue }
    }

    var body: some View {
        Text(verbatim: p.money(Int64(Double(minor) * progress))).font(.dorr(30, .heavy)).foregroundStyle(.white).kerning(-0.5)
            .environment(\.layoutDirection, .leftToRight)
    }
}

private struct MoneyStatusChip: View {
    let status: String
    @State private var pop: CGFloat = 1

    var body: some View {
        let (label, color): (String, Color) = switch status {
        case "paid", "settled": ("ch_money_status_paid", .white)
        case "declined": ("ch_money_status_declined", Color(hex: 0xFECACA))
        case "cancelled": ("ch_money_status_cancelled", .white.opacity(0.7))
        default: ("ch_money_status_pending", .white.opacity(0.9))
        }
        HStack(spacing: 3) {
            if status == "paid" { Image(systemName: "checkmark").font(.system(size: 11, weight: .bold)) }
            Text(L(label)).font(.dorr(11, .heavy))
        }
        .foregroundStyle(color)
        .padding(.horizontal, 8).padding(.vertical, 3)
        .background(Color.black.opacity(0.18), in: RoundedRectangle(cornerRadius: 10))
        .scaleEffect(pop)
        .onChange(of: status) { s in
            guard s == "paid" else { return }
            pop = 0.4
            withAnimation(.spring(response: 0.35, dampingFraction: 0.35)) { pop = 1 }
        }
    }
}

private struct MoneyButton: View {
    let text: String
    let icon: String
    let filled: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 5) {
                Image(systemName: icon).font(.system(size: 14, weight: .bold))
                Text(verbatim: text).font(.dorr(13.5, .heavy))
            }
            .foregroundStyle(filled ? moneyGreen[1] : .white)
            .frame(maxWidth: .infinity).padding(.vertical, 10)
            .background(filled ? Color.white : .clear, in: RoundedRectangle(cornerRadius: 14))
            .overlay(RoundedRectangle(cornerRadius: 14).stroke(filled ? .clear : Color.white.opacity(0.7), lineWidth: 1.5))
        }
        .buttonStyle(AiPressStyle())
    }
}

/// A shared bill: the total, a ring that fills as shares are paid, each person with their share and a tick / clock /
/// ✕, and «Pay my share» for me when mine is pending.
struct BillSplitCard<Footer: View>: View {
    @ObservedObject private var ch = Ch.shared
    let m: UiMessage
    let mine: Bool
    let onPay: () -> Void
    let onDecline: () -> Void
    let onCancel: () -> Void
    @ViewBuilder let footer: () -> Footer

    var body: some View {
        if let p = m.dto.payment {
            let ink = mine ? ch.outText : ch.inText
            let total = p.totalMinor ?? 0
            let progress = total > 0 ? Double(p.paidMinor ?? 0) / Double(total) : 0
            let settled = p.status == "settled"
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    ZStack {
                        Circle().stroke(ink.opacity(0.14), lineWidth: 6)
                        Circle().trim(from: 0, to: progress).stroke(settled ? ch.success : Color(hex: 0x10B981), style: StrokeStyle(lineWidth: 6, lineCap: .round))
                            .rotationEffect(.degrees(-90)).animation(.spring(response: 0.8, dampingFraction: 0.8), value: progress)
                        Image(systemName: settled ? "checkmark.circle.fill" : "arrow.triangle.branch").font(.system(size: 22))
                            .foregroundStyle(settled ? ch.success : ink)
                            .transition(.scale).id(settled)
                    }
                    .frame(width: 52, height: 52).padding(3)
                    .environment(\.layoutDirection, .leftToRight)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: m.dto.body.flatMap { $0.isEmpty ? nil : $0 } ?? L("ch_split_title")).font(.dorr(15, .heavy)).foregroundStyle(ink).lineLimit(2)
                        Text(verbatim: p.money(total)).font(.dorr(19, .heavy)).foregroundStyle(ink).environment(\.layoutDirection, .leftToRight)
                        Text(L("ch_split_collected", p.money(p.paidMinor ?? 0))).font(.dorr(11.5)).foregroundStyle(ink.opacity(0.7))
                    }
                }
                ForEach(Array((p.shares ?? []).enumerated()), id: \.offset) { i, share in
                    HStack(spacing: 8) {
                        ChAvatar(url: share.profile?.avatar, name: share.profile?.name, key: share.profile?.key, size: 28)
                        Text(verbatim: share.profile?.isMe == true ? L("ch_you") : share.profile?.name ?? "")
                            .font(.dorr(13, .semibold)).foregroundStyle(ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                        Text(verbatim: p.money(share.amountMinor)).font(.dorr(12.5, .bold)).foregroundStyle(ink.opacity(0.85)).environment(\.layoutDirection, .leftToRight)
                        ShareIcon(status: share.status, ink: ink)
                    }
                    .padding(.vertical, 4)
                    .chStagger(i)
                }
                .padding(.top, 8)
                if p.canPay == true {
                    HStack(spacing: 8) {
                        Button(action: onPay) {
                            HStack(spacing: 6) {
                                Image(systemName: "lock.fill").font(.system(size: 14))
                                Text(L("ch_split_pay_mine", p.money(p.myShare?.amountMinor ?? 0))).font(.dorr(13.5, .heavy))
                            }
                            .foregroundStyle(.white).frame(maxWidth: .infinity).padding(.vertical, 11)
                            .background(LinearGradient(colors: moneyGreen, startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 14))
                        }
                        .buttonStyle(AiPressStyle())
                        Button(action: onDecline) {
                            Image(systemName: "xmark").font(.system(size: 16, weight: .bold)).foregroundStyle(ink)
                                .frame(width: 42, height: 42).background(ink.opacity(0.1), in: RoundedRectangle(cornerRadius: 14))
                        }
                        .buttonStyle(.plain)
                    }
                    .padding(.top, 10)
                }
                if p.canCancel == true {
                    Button(action: onCancel) {
                        Text(L("ch_split_close")).font(.dorr(12.5, .bold)).foregroundStyle(ink.opacity(0.75)).padding(4)
                    }
                    .buttonStyle(.plain).padding(.top, 8)
                }
                HStack { Spacer(); footer() }.padding(.top, 4)
            }
            .frame(width: 254)
            .padding(.horizontal, 12).padding(.vertical, 10)
        }
    }
}

private struct ShareIcon: View {
    @ObservedObject private var ch = Ch.shared
    let status: String
    let ink: Color
    @State private var pulse = false

    var body: some View {
        Group {
            switch status {
            case "paid": Image(systemName: "checkmark.circle.fill").foregroundStyle(ch.success)
            case "owner": Image(systemName: "star.fill").foregroundStyle(Color(hex: 0xF59E0B))
            case "declined": Image(systemName: "nosign").foregroundStyle(ch.danger)
            default:
                Image(systemName: "hourglass").foregroundStyle(ink.opacity(pulse ? 0.8 : 0.4))
                    .onAppear { withAnimation(.linear(duration: 1.4).repeatForever(autoreverses: true)) { pulse = true } }
            }
        }
        .font(.system(size: 16))
        .transition(.scale)
        .id(status)
    }
}

// MARK: - Expressions

/// A sticker: no bubble, a little bounce when it arrives, the time in a pill underneath.
struct StickerBubble<Footer: View>: View {
    let m: UiMessage
    let mine: Bool
    @ViewBuilder let footer: () -> Footer
    @State private var pop: CGFloat = 1

    var body: some View {
        VStack(alignment: mine ? .trailing : .leading, spacing: 2) {
            ChatImage(url: ApiClient.mediaUrl(m.dto.meta?.str("url")), fit: true)
                .frame(width: 150, height: 150).scaleEffect(pop)
            footer()
        }
        .onAppear {
            guard m.fresh else { return }
            pop = 0.4
            withAnimation(.spring(response: 0.4, dampingFraction: 0.4)) { pop = 1 }
        }
    }
}

/// A GIF: rounded, moving, with a small «GIF» badge and the time in the corner.
struct GifBubble<Footer: View>: View {
    @ObservedObject private var ch = Ch.shared
    let m: UiMessage
    @ViewBuilder let footer: () -> Footer

    var body: some View {
        let meta = m.dto.meta
        let w = max(meta?.int("width") ?? 1, 1)
        let h = max(meta?.int("height") ?? 1, 1)
        let ratio = min(max(CGFloat(w) / CGFloat(h), 0.6), 2.2)
        ZStack {
            // Data saver / lite mode: a still frame (spec 82, 87).
            AnimatedImageView(url: ApiClient.mediaUrl(meta?.str("webp") ?? meta?.str("url")), animate: autoplayClips)
            GifTag().frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading).padding(8)
            footer().frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottomTrailing).padding(8)
        }
        .frame(width: 234, height: 234 / ratio)
        .background(ch.surfaceMuted)
        .clipShape(RoundedRectangle(cornerRadius: 11))
        .padding(3)
    }
}

/// An animated GIF / WebP from the web (UIImageView plays it), cropped to fill (or fitted: stickers).
struct AnimatedImageView: UIViewRepresentable {
    let url: URL?
    var animate = true
    var mode: UIView.ContentMode = .scaleAspectFill

    func makeUIView(context: Context) -> UIImageView {
        let v = UIImageView()
        v.contentMode = mode
        v.clipsToBounds = true
        v.setContentCompressionResistancePriority(.defaultLow, for: .horizontal)
        v.setContentCompressionResistancePriority(.defaultLow, for: .vertical)
        return v
    }

    func updateUIView(_ v: UIImageView, context: Context) {
        guard let url, v.accessibilityIdentifier != url.absoluteString else { return }
        v.accessibilityIdentifier = url.absoluteString
        Task {
            guard let (data, _) = try? await URLSession.shared.data(from: url) else { return }
            let image = animatedImage(data)
            await MainActor.run { if v.accessibilityIdentifier == url.absoluteString { v.image = image } }
        }
    }

    private func animatedImage(_ data: Data) -> UIImage? {
        guard let source = CGImageSourceCreateWithData(data as CFData, nil) else { return UIImage(data: data) }
        let count = CGImageSourceGetCount(source)
        guard count > 1, animate else { return UIImage(data: data) }
        var frames: [UIImage] = []
        var duration: Double = 0
        for i in 0..<count {
            guard let cg = CGImageSourceCreateImageAtIndex(source, i, nil) else { continue }
            frames.append(UIImage(cgImage: cg))
            let props = CGImageSourceCopyPropertiesAtIndex(source, i, nil) as? [CFString: Any]
            let gif = props?[kCGImagePropertyGIFDictionary] as? [CFString: Any]
            let webp = props?[kCGImagePropertyWebPDictionary] as? [CFString: Any]
            let delay = (gif?[kCGImagePropertyGIFUnclampedDelayTime] ?? gif?[kCGImagePropertyGIFDelayTime] ?? webp?[kCGImagePropertyWebPUnclampedDelayTime] ?? webp?[kCGImagePropertyWebPDelayTime]) as? Double
            duration += max(delay ?? 0.1, 0.02)
        }
        return UIImage.animatedImage(with: frames, duration: duration)
    }
}

// MARK: - Shield, AI, threads

/// A sensitive message before it's unlocked: no content, just «tap to view» (spec 107–108).
struct SensitiveCard<Footer: View>: View {
    @ObservedObject private var ch = Ch.shared
    let mine: Bool
    let onReveal: () -> Void
    @ViewBuilder let footer: () -> Footer

    var body: some View {
        let ink = mine ? ch.outText : ch.inText
        VStack(alignment: .leading, spacing: 4) {
            HStack(spacing: 10) {
                Image(systemName: "lock.fill").font(.system(size: 16)).foregroundStyle(ink)
                    .frame(width: 34, height: 34).background(ink.opacity(0.12), in: Circle())
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("ch_sensitive")).font(.dorr(14, .heavy)).foregroundStyle(ink)
                    Text(L("ch_sensitive_tap")).font(.dorr(12)).foregroundStyle(ink.opacity(0.7))
                }
            }
            HStack { Spacer(); footer() }
        }
        .frame(width: 206)
        .padding(.horizontal, 12).padding(.vertical, 10)
        .contentShape(Rectangle())
        .onTapGesture(perform: onReveal)
    }
}

/// The translation / transcript under a bubble, with a way to hide it.
struct AiNotePanel: View {
    @ObservedObject private var ch = Ch.shared
    let note: AiNote
    let mine: Bool
    let onHide: () -> Void
    @State private var shimmer = false

    var body: some View {
        if note.kind == "transcript" { voiceText } else { panel }
    }

    private var panel: some View {
        let ink = mine ? ch.outText : ch.ink
        return VStack(alignment: .leading, spacing: 4) {
            HStack(spacing: 5) {
                Image(systemName: "character.bubble").font(.system(size: 12))
                Text(L("ch_ai_translation")).font(.dorr(11, .bold))
                    .frame(maxWidth: .infinity, alignment: .leading)
                Button(action: onHide) { Image(systemName: "xmark").font(.system(size: 11, weight: .bold)).frame(width: 22, height: 22) }.buttonStyle(.plain)
            }
            .foregroundStyle(ink.opacity(0.7))
            words(ink, size: 14)
        }
        .padding(.horizontal, 10).padding(.vertical, 8)
        .frame(minWidth: 200, alignment: .leading)
        .background(ink.opacity(0.07), in: RoundedRectangle(cornerRadius: 14))
        .padding(.horizontal, 8).padding(.bottom, 6)
    }

    /// A voice message's text: the «show text» strip with its title at the start and × at the end, then the words.
    private var voiceText: some View {
        let accent = mine ? ch.outText : ch.red
        let ink = mine ? ch.outText : ch.ink
        return VStack(alignment: .leading, spacing: 0) {
            Rectangle().fill((mine ? ch.outText : ch.inText).opacity(0.18)).frame(height: 1)
            HStack(spacing: 5) {
                Image(systemName: "waveform").font(.system(size: 13, weight: .semibold))
                Text(L("ch_ai_transcript")).font(.dorr(12.5, .heavy))
                    .frame(maxWidth: .infinity, alignment: .leading)
                Button(action: onHide) {
                    Image(systemName: "xmark").font(.system(size: 12, weight: .bold)).frame(width: 28, height: 28).contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
            .foregroundStyle(accent)
            .padding(.leading, 12).padding(.trailing, 6).padding(.vertical, 3)
            .frame(maxWidth: .infinity)
            .background(accent.opacity(0.16))
            words(ink, size: 13.5)
                .padding(.horizontal, 12).padding(.top, 7).padding(.bottom, 8)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func words(_ ink: Color, size: CGFloat) -> some View {
        Group {
            if note.loading {
                VStack(alignment: .leading, spacing: 5) {
                    ForEach([1.0, 0.85, 0.55], id: \.self) { w in
                        GeometryReader { g in Capsule().fill(ink.opacity((shimmer ? 0.6 : 0.25) * 0.35)).frame(width: g.size.width * w) }.frame(height: 9)
                    }
                }
                .padding(.top, 2)
                .onAppear { withAnimation(.easeInOut(duration: 0.7).repeatForever(autoreverses: true)) { shimmer = true } }
            } else if let failed = note.failed {
                // In the bubble's own text colour: red didn't show on my orange bubble.
                Text(verbatim: failed).font(.dorr(12.5)).italic().foregroundStyle(ink.opacity(0.9))
            } else {
                Text(verbatim: note.text ?? "").font(.dorr(size)).foregroundStyle(ink).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
            }
        }
        .transition(.opacity)
        .animation(.easeInOut(duration: 0.2), value: note)
    }
}

/// Under a voice message: «Show text», a strip across the bubble under a thin line (like «show replies»).
struct TranscribeChip: View {
    @ObservedObject private var ch = Ch.shared
    let mine: Bool
    let onTap: () -> Void

    var body: some View {
        let accent = mine ? ch.outText : ch.red
        VStack(spacing: 0) {
            Rectangle().fill((mine ? ch.outText : ch.inText).opacity(0.18)).frame(height: 1)
            Button(action: onTap) {
                HStack(spacing: 5) {
                    Image(systemName: "waveform").font(.system(size: 13, weight: .semibold))
                    Text(L("ch_ai_show_text")).font(.dorr(12.5, .heavy))
                }
                .foregroundStyle(accent)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 8)
                .background(accent.opacity(0.16))
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
        }
    }
}

/// «💬 3 replies» under a message that has a thread.
struct ThreadChip: View {
    @ObservedObject private var ch = Ch.shared
    let count: Int
    let onTap: () -> Void

    var body: some View {
        Button(action: onTap) {
            HStack(spacing: 5) {
                Image(systemName: "bubble.left.and.bubble.right.fill").font(.system(size: 12))
                Text(L("ch_thread_replies", count)).font(.dorr(12, .bold))
            }
            .foregroundStyle(ch.red).padding(.horizontal, 10).padding(.vertical, 4)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 14))
            .overlay(RoundedRectangle(cornerRadius: 14).stroke(ch.red.opacity(0.25), lineWidth: 1))
        }
        .buttonStyle(.plain)
        .padding(.top, 2).padding(.horizontal, 8)
    }
}

// MARK: - Interactive

/// The bubble: the text, a quiet footer, then the buttons (or the list's button).
struct InteractiveCard<Footer: View>: View {
    @ObservedObject private var ch = Ch.shared
    let m: UiMessage
    let mine: Bool
    let onChoose: (Int) -> Void
    let onShowAnswers: () -> Void
    @ViewBuilder let footer: () -> Footer
    @State private var listOpen = false

    var body: some View {
        if let ui = m.dto.interactive {
            let ink = mine ? ch.outText : ch.inText
            let accent = mine ? ch.outText : ch.red
            // My own message can't be answered by me; one still being sent can't be tapped yet.
            let canTap = !mine && m.local == nil
            let answers = ui.answers ?? 0
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: m.dto.body ?? "").font(.dorr(14)).foregroundStyle(ink).padding(.horizontal, 10)
                if let f = ui.footer { Text(verbatim: f).font(.dorr(11.5)).foregroundStyle(ink.opacity(0.6)).padding(.horizontal, 10).padding(.vertical, 2) }
                HStack { Spacer(); footer() }.padding(.horizontal, 10)
                if ui.kind == "list" {
                    Rectangle().fill(ink.opacity(0.12)).frame(height: 1).padding(.top, 6)
                    ChoiceButton(icon: "list.bullet", title: ui.button ?? L("ia_choose"), accent: accent, chosen: false) { listOpen = true }
                } else {
                    ForEach(ui.options ?? []) { o in
                        Rectangle().fill(ink.opacity(0.12)).frame(height: 1).padding(.top, 2)
                        ChoiceButton(icon: "arrowshape.turn.up.left.fill", title: o.title, accent: accent, chosen: o.id == ui.myChoice, enabled: canTap) { onChoose(o.id) }
                    }
                }
                if mine {
                    Rectangle().fill(ink.opacity(0.18)).frame(height: 1).padding(.top, 4)
                    Button(action: onShowAnswers) {
                        HStack(spacing: 5) {
                            Image(systemName: "chart.bar.xaxis").font(.system(size: 13))
                            Text(L("ia_answers", answers)).font(.dorr(12.5, .heavy))
                        }
                        .foregroundStyle(accent)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 8)
                        .background(accent.opacity(0.16))
                    }
                    .buttonStyle(.plain).disabled(answers == 0)
                } else {
                    Spacer().frame(height: 3)
                }
            }
            .frame(width: 236)
            .padding(.top, 7)
            .sheet(isPresented: $listOpen) {
                InteractiveListSheet(title: m.dto.body ?? "", ui: ui, canTap: canTap) { listOpen = false; onChoose($0) }
                    .presentationDetents([.medium, .large])
            }
        }
    }
}

private struct ChoiceButton: View {
    let icon: String
    let title: String
    let accent: Color
    let chosen: Bool
    var enabled = true
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 5) {
                Image(systemName: chosen ? "checkmark.circle.fill" : icon).font(.system(size: 14)).scaleEffect(chosen ? 1.15 : 1)
                Text(verbatim: title).font(.dorr(13.5, chosen ? .heavy : .bold)).lineLimit(1)
            }
            .foregroundStyle(accent).frame(maxWidth: .infinity).padding(.horizontal, 10).padding(.vertical, 7)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain).disabled(!enabled)
        .animation(.spring(response: 0.3, dampingFraction: 0.45), value: chosen)
    }
}

/// A list message opened: one choice, each with its line, then «Send».
private struct InteractiveListSheet: View {
    @ObservedObject private var ch = Ch.shared
    let title: String
    let ui: InteractiveDto
    let canTap: Bool
    let onSend: (Int) -> Void
    @State private var picked: Int?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 12) {
                    ChIconBadge(icon: "list.bullet", color: ch.red, size: 42)
                    Text(verbatim: ui.button ?? title).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).lineLimit(2)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(.bottom, 12)
                ForEach(Array((ui.options ?? []).enumerated()), id: \.element.id) { i, o in
                    let on = picked == o.id
                    HStack(spacing: 10) {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(verbatim: o.title).font(.dorr(15, on ? .heavy : .bold)).foregroundStyle(ch.ink)
                            if let d = o.description { Text(verbatim: d).font(.dorr(12.5)).foregroundStyle(ch.mut) }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        Image(systemName: on ? "largecircle.fill.circle" : "circle").font(.system(size: 20)).foregroundStyle(on ? ch.red : ch.soft)
                    }
                    .padding(.vertical, 10).padding(.horizontal, 12)
                    .frame(maxWidth: .infinity)
                    .background(on ? ch.red.opacity(0.16) : ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .contentShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .onTapGesture { if canTap { picked = o.id } }
                    .chStagger(i)
                    .padding(.bottom, 8)
                }
                if canTap {
                    ChPrimaryButton(text: L("ch_send"), enabled: picked != nil) { if let picked { onSend(picked) } }.padding(.top, 14)
                }
            }
            .padding(.horizontal, 20).padding(.top, 24).padding(.bottom, 26)
        }
        .background(ch.surface)
        .onAppear { picked = ui.myChoice }
    }
}

// MARK: - Stories & snapshots

/// A `story_mention` message: the status, and (for the one mentioned) adding it to theirs.
struct StoryMentionLine<Footer: View>: View {
    @ObservedObject private var ch = Ch.shared
    @EnvironmentObject private var host: ChatHost
    let meta: JSONAny?
    let mine: Bool
    @ViewBuilder let footer: () -> Footer
    @State private var done = false

    var body: some View {
        let storyId = meta?.str("story_id")
        let live = parseRideTime(meta?.str("expires_at")).map { $0 > Date() } ?? true
        VStack(alignment: .leading, spacing: 4) {
            Text(verbatim: "📣 " + L(mine ? "bk_story_mention_mine" : "bk_story_mention_line")).font(.dorr(14, .bold)).foregroundStyle(mine ? ch.outText : ch.ink)
            if !mine, let storyId, live, !done {
                Button {
                    Task {
                        do {
                            _ = try await ChatApi.reshareStory(storyId)
                            done = true
                            host.showToast(L("bk_reshared"))
                            await host.refreshStories()
                        } catch {
                            if let msg = error.apiFailure.message { host.showToast(msg, error: true) }
                        }
                    }
                } label: {
                    Text(L("bk_reshare")).font(.dorr(13, .heavy)).foregroundStyle(.white)
                        .padding(.horizontal, 14).padding(.vertical, 8).background(ch.red, in: RoundedRectangle(cornerRadius: 14))
                }
                .buttonStyle(.plain).padding(.top, 4)
            }
            HStack { Spacer(); footer() }
        }
        .padding(.horizontal, 12).padding(.vertical, 8)
    }
}
