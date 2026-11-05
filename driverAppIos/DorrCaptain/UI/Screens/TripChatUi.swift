import SwiftUI

// driverApp `ui/TripChatUi.kt` — the ride's chat with the rider, in the look of the chat between people
// (docs/rides_tasks.md O-61): the same header gradient, wallpaper, bubbles (mine in the app colour's gradient, theirs as
// soft cards, the small «tail» corner), times under them, the ready-made replies as chips and the pill composer with its
// round send button. Full screen, like a conversation.

/// The chat's roles in the captain app's colours (the DORR app's chat look, O-61).
private struct Ch {
    let pal: Pal
    var red: Color { pal.navy }
    var ink: Color { pal.ink }
    var mut: Color { pal.mut }
    var soft: Color { pal.faint }
    var surface: Color { pal.card }
    var surfaceMuted: Color { pal.well }
    var fieldFill: Color { pal.bg }
    var fieldLine: Color { pal.line }
    var inBubble: Color { pal.card }
    var inText: Color { pal.ink }
    let outText = Color.white
    var outBubble: LinearGradient { LinearGradient(colors: [pal.navy, lerp(pal.navy, .black, 0.25)], startPoint: .topLeading, endPoint: .bottomTrailing) }
    var headerBrush: LinearGradient { LinearGradient(colors: [pal.navy, lerp(pal.navy, .black, 0.45)], startPoint: .topLeading, endPoint: .bottomTrailing) }
    var wallpaper: LinearGradient { LinearGradient(colors: [lerp(pal.bg, pal.navy, 0.05), pal.bg], startPoint: .top, endPoint: .bottom) }
    static let bubbleRadius: CGFloat = 20
    static let tailRadius: CGFloat = 6
}

/// Compose's `lerp(a, b, f)` between two colours.
private func lerp(_ a: Color, _ b: Color, _ f: CGFloat) -> Color {
    var (r1, g1, b1, a1): (CGFloat, CGFloat, CGFloat, CGFloat) = (0, 0, 0, 0)
    var (r2, g2, b2, a2): (CGFloat, CGFloat, CGFloat, CGFloat) = (0, 0, 0, 0)
    UIColor(a).getRed(&r1, green: &g1, blue: &b1, alpha: &a1)
    UIColor(b).getRed(&r2, green: &g2, blue: &b2, alpha: &a2)
    return Color(red: r1 + (r2 - r1) * f, green: g1 + (g2 - g1) * f, blue: b1 + (b2 - b1) * f, opacity: a1 + (a2 - a1) * f)
}

/// The chat screen's content — Android shows it in a full-screen `Dialog`; present it with `.fullScreenCover`.
struct TripChatScreen: View {
    @Environment(\.pal) private var pal
    let title: String
    /// The person on the other side is a verified Dorr account: the tick after their name.
    var titleVerified = false
    let subtitle: String
    let messages: [MessageDto]
    let quick: [QuickDto]
    let open: Bool
    let onSend: (_ body: String?, _ quickKey: String?) -> Void
    let onCall: (() -> Void)?
    let onClose: () -> Void

    @State private var text = ""

    init(title: String, titleVerified: Bool = false, subtitle: String, messages: [MessageDto], quick: [QuickDto], open: Bool,
         onSend: @escaping (_ body: String?, _ quickKey: String?) -> Void, onCall: (() -> Void)?, onClose: @escaping () -> Void) {
        self.title = title
        self.titleVerified = titleVerified
        self.subtitle = subtitle
        self.messages = messages
        self.quick = quick
        self.open = open
        self.onSend = onSend
        self.onCall = onCall
        self.onClose = onClose
    }

    var body: some View {
        let ch = Ch(pal: pal)
        let blank = text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        VStack(spacing: 0) {
            // The header: the app colour deepening, like a conversation's.
            HStack(spacing: 0) {
                Button(action: onClose) {
                    Image(systemName: "arrow.backward").font(.system(size: 18, weight: .semibold)).foregroundStyle(.white)
                        .frame(width: 40, height: 40).contentShape(Circle())
                }
                .buttonStyle(.plain)
                Text(verbatim: String(title.prefix(1)).isEmpty ? "?" : String(title.prefix(1)))
                    .font(.dorr(18, .black)).foregroundStyle(.white)
                    .frame(width: 42, height: 42).background(Color.white.opacity(0.2), in: Circle())
                Spacer().frame(width: 10)
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 0) {
                        Text(verbatim: title).font(.dorr(16, .bold)).foregroundStyle(.white).lineLimit(1)
                        RiderTick(verified: titleVerified, size: 15, tint: .white)
                    }
                    Text(verbatim: subtitle).font(.dorr(12)).foregroundStyle(.white.opacity(0.8)).lineLimit(1)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if let onCall {
                    Button(action: onCall) {
                        Image(systemName: "phone.fill").foregroundStyle(.white)
                            .frame(width: 42, height: 42).background(Color.white.opacity(0.18), in: Circle())
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(10)
            .background(ch.headerBrush.ignoresSafeArea(edges: .top))

            ScrollViewReader { proxy in
                ScrollView {
                    LazyVStack(spacing: 6) {
                        if messages.isEmpty {
                            Text(L("trip_chat_empty")).font(.dorr(13)).foregroundStyle(ch.mut).multilineTextAlignment(.center)
                                .frame(maxWidth: .infinity).padding(.top, 40)
                        }
                        ForEach(Array(messages.enumerated()), id: \.offset) { i, m in
                            Bubble(m: m, ch: ch).id(i)
                        }
                    }
                    .padding(12)
                }
                .frame(maxHeight: .infinity)
                .onChange(of: messages.count) { n in
                    if n > 0 { withAnimation { proxy.scrollTo(n - 1, anchor: .bottom) } }
                }
                .onAppear { if !messages.isEmpty { proxy.scrollTo(messages.count - 1, anchor: .bottom) } }
            }

            if !open {
                Text(L("trip_chat_closed")).font(.dorr(13)).foregroundStyle(ch.mut).multilineTextAlignment(.center)
                    .frame(maxWidth: .infinity).padding(16)
                    .background(ch.surface.ignoresSafeArea(edges: .bottom))
            } else {
                VStack(spacing: 0) {
                    // The ready-made replies, one tap each.
                    if !quick.isEmpty {
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 6) {
                                ForEach(Array(quick.enumerated()), id: \.offset) { _, q in
                                    Button { onSend(nil, q.key) } label: {
                                        Text(verbatim: q.text ?? "").font(.dorr(13, .semibold)).foregroundStyle(ch.red)
                                            .padding(.horizontal, 14).padding(.vertical, 8)
                                            .background(ch.red.opacity(0.08), in: Capsule())
                                            .overlay(Capsule().strokeBorder(ch.red.opacity(0.2), lineWidth: 1))
                                    }
                                    .buttonStyle(.plain)
                                }
                            }
                            .padding(.horizontal, 12).padding(.vertical, 8)
                        }
                    }
                    // The composer: a pill field and the round send button.
                    HStack(alignment: .bottom, spacing: 8) {
                        ZStack(alignment: .leading) {
                            if text.isEmpty {
                                Text(L("trip_chat_hint")).font(.dorr(15)).foregroundStyle(ch.soft)
                            }
                            TextField("", text: Binding(get: { text }, set: { text = String($0.prefix(1000)) }), axis: .vertical)
                                .font(.dorr(15)).foregroundStyle(ch.ink).tint(ch.red)
                                .lineLimit(1...5)
                        }
                        .padding(.horizontal, 16).padding(.vertical, 12)
                        .frame(maxWidth: .infinity, minHeight: 46, alignment: .leading)
                        .background(ch.fieldFill, in: RoundedRectangle(cornerRadius: 24))
                        .overlay(RoundedRectangle(cornerRadius: 24).strokeBorder(ch.fieldLine, lineWidth: 1))
                        Button {
                            onSend(text.trimmingCharacters(in: .whitespacesAndNewlines), nil)
                            text = ""
                        } label: {
                            Image(systemName: "paperplane.fill")
                                .foregroundStyle(blank ? ch.soft : .white)
                                .flipsForRightToLeftLayoutDirection(true)
                                .frame(width: 46, height: 46)
                                .background(blank ? ch.surfaceMuted : ch.red, in: Circle())
                                .shadow(color: .black.opacity(blank ? 0 : 0.2), radius: blank ? 0 : 6, y: blank ? 0 : 3)
                        }
                        .buttonStyle(.plain)
                        .disabled(blank)
                    }
                    .padding(.horizontal, 10)
                }
                .padding(.bottom, 8)
                .background(ch.surface.ignoresSafeArea(edges: .bottom))
            }
        }
        .background(ch.wallpaper.ignoresSafeArea())
    }
}

private struct Bubble: View {
    let m: MessageDto
    let ch: Ch

    var body: some View {
        let mine = m.mine ?? (m.from == "driver")
        let big = Ch.bubbleRadius
        let tail = Ch.tailRadius
        // RoundedCornerShape(topStart, topEnd, bottomStart, bottomEnd) — the tail at the bottom on my side.
        let shape = BubbleShape(topStart: big, topEnd: big, bottomStart: mine ? big : tail, bottomEnd: mine ? tail : big)
        HStack(spacing: 0) {
            if mine { Spacer(minLength: 0) }
            VStack(alignment: .trailing, spacing: 0) {
                Text(verbatim: m.body ?? "").font(.dorr(15)).lineSpacing(3).foregroundStyle(mine ? ch.outText : ch.inText)
                Text(verbatim: timeOf(m.createdAt)).font(.dorr(10)).foregroundStyle((mine ? ch.outText : ch.mut).opacity(0.75)).padding(.top, 2)
            }
            .padding(.horizontal, 12).padding(.vertical, 8)
            .background {
                if mine { shape.fill(ch.outBubble) } else { shape.fill(ch.inBubble).shadow(color: .black.opacity(0.12), radius: 1, y: 0.5) }
            }
            .frame(maxWidth: 300, alignment: mine ? .trailing : .leading)
            if !mine { Spacer(minLength: 0) }
        }
        .frame(maxWidth: .infinity)
    }
}

/// A rounded rectangle with its own radius at each corner, in the reading direction (Compose's start / end).
private struct BubbleShape: Shape {
    let topStart: CGFloat
    let topEnd: CGFloat
    let bottomStart: CGFloat
    let bottomEnd: CGFloat

    func path(in rect: CGRect) -> Path {
        // SwiftUI mirrors the drawing in right-to-left layouts, so start = left here.
        let tl = topStart, tr = topEnd, bl = bottomStart, br = bottomEnd
        var p = Path()
        p.move(to: CGPoint(x: rect.minX + tl, y: rect.minY))
        p.addLine(to: CGPoint(x: rect.maxX - tr, y: rect.minY))
        p.addArc(center: CGPoint(x: rect.maxX - tr, y: rect.minY + tr), radius: tr, startAngle: .degrees(-90), endAngle: .degrees(0), clockwise: false)
        p.addLine(to: CGPoint(x: rect.maxX, y: rect.maxY - br))
        p.addArc(center: CGPoint(x: rect.maxX - br, y: rect.maxY - br), radius: br, startAngle: .degrees(0), endAngle: .degrees(90), clockwise: false)
        p.addLine(to: CGPoint(x: rect.minX + bl, y: rect.maxY))
        p.addArc(center: CGPoint(x: rect.minX + bl, y: rect.maxY - bl), radius: bl, startAngle: .degrees(90), endAngle: .degrees(180), clockwise: false)
        p.addLine(to: CGPoint(x: rect.minX, y: rect.minY + tl))
        p.addArc(center: CGPoint(x: rect.minX + tl, y: rect.minY + tl), radius: tl, startAngle: .degrees(180), endAngle: .degrees(270), clockwise: false)
        p.closeSubpath()
        return p
    }
}

/// "9:30 PM" in the phone's zone (`ofPattern("h:mm a")`).
private func timeOf(_ iso: String?) -> String {
    guard let at = instantOf(iso) else { return "" }
    let f = DateFormatter()
    f.locale = Locale(identifier: DriverPrefs.locale)
    f.timeZone = .current
    f.dateFormat = "h:mm a"
    return f.string(from: at)
}
