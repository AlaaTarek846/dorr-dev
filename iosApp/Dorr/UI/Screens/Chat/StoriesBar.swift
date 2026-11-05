import PhotosUI
import SwiftUI

// androidApp `chat/StoriesBar.kt` — the stories row at the top of the chat list: «My status» first (tap to add or to
// watch mine), then everyone else (unseen first), the muted ones faded at the end; each avatar sits in a ring with
// one arc per story (unseen arcs in the turning brand gradient, seen ones grey). Text stories' looks (StoryLook) are
// shared with the composer and the viewer.

/// The «voice status» composer marker (androidApp `STORY_VOICE`).
let storyVoice = "voice"

/// Backgrounds and fonts for text stories — sent as names, so both apps paint the same.
enum StoryLook {
    static let backgrounds: [(String, [Color])] = [
        ("dorr", [Color(hex: 0x1E3A7B), Color(hex: 0x7A0410)]), ("sunset", [Color(hex: 0xFF8A4C), Color(hex: 0xDB2777)]),
        ("ocean", [Color(hex: 0x22D3EE), Color(hex: 0x1D4ED8)]), ("forest", [Color(hex: 0x34D399), Color(hex: 0x065F46)]),
        ("grape", [Color(hex: 0xA78BFA), Color(hex: 0x5B21B6)]), ("gold", [Color(hex: 0xFBBF24), Color(hex: 0xB45309)]),
        ("night", [Color(hex: 0x334155), Color(hex: 0x020617)]), ("rose", [Color(hex: 0xFDA4AF), Color(hex: 0xBE123C)]),
    ]
    static let fonts = ["bold", "serif", "mono", "script", "light"]

    static func brush(_ name: String?) -> LinearGradient {
        let colors = backgrounds.first { $0.0 == name }?.1 ?? backgrounds[0].1
        return LinearGradient(colors: colors, startPoint: .topLeading, endPoint: .bottomTrailing)
    }

    static func font(_ name: String?, size: CGFloat) -> Font {
        switch name {
        case "serif": return .system(size: size, weight: .semibold, design: .serif)
        case "mono": return .system(size: size, weight: .heavy, design: .monospaced)
        case "script": return .custom("SnellRoundhand-Bold", size: size)
        case "light": return .dorr(size, .regular)
        default: return .dorr(size, .heavy)
        }
    }

    /// Big text for a short status, smaller as it grows.
    static func textSize(_ length: Int) -> CGFloat { length < 20 ? 38 : length < 60 ? 30 : length < 140 ? 24 : 19 }
}

/// The ring: one arc per story; `uploading` spins one arc while my story is posted.
struct ChStoryRing<Content: View>: View {
    @ObservedObject private var ch = Ch.shared
    let count: Int
    let seenCount: Int
    let size: CGFloat
    var uploading = false
    @ViewBuilder let content: () -> Content

    var body: some View {
        TimelineView(.animation) { ctx in
            let t = ctx.date.timeIntervalSinceReferenceDate
            let angle = (t / (uploading ? 0.9 : 9)).truncatingRemainder(dividingBy: 1) * 360
            ZStack {
                Canvas { gc, sz in
                    let stroke: CGFloat = 2.6
                    let rect = CGRect(origin: .zero, size: sz).insetBy(dx: stroke / 2, dy: stroke / 2)
                    let center = CGPoint(x: sz.width / 2, y: sz.height / 2)
                    let brand = GraphicsContext.Shading.conicGradient(Gradient(colors: [ch.red, ch.redDeep, ch.red.scaled(0.45), ch.red]), center: center, angle: .degrees(angle))
                    func arc(_ start: Double, _ sweep: Double) -> Path {
                        var p = Path()
                        p.addArc(center: center, radius: rect.width / 2, startAngle: .degrees(start), endAngle: .degrees(start + sweep), clockwise: false)
                        return p
                    }
                    if uploading {
                        gc.stroke(arc(angle, 100), with: brand, style: StrokeStyle(lineWidth: stroke, lineCap: .round))
                        return
                    }
                    guard count > 0 else { return }
                    let gap: Double = count > 1 ? 7 : 0
                    let sweep = 360.0 / Double(count)
                    for i in 0..<count {
                        let start = -90 + Double(i) * sweep + gap / 2
                        if i < seenCount {
                            gc.stroke(arc(start, sweep - gap), with: .color(ch.soft), style: StrokeStyle(lineWidth: stroke * 0.8, lineCap: .round))
                        } else {
                            gc.stroke(arc(start, sweep - gap), with: brand, style: StrokeStyle(lineWidth: stroke, lineCap: .round))
                        }
                    }
                }
                content().padding(5)
            }
        }
        .frame(width: size, height: size)
        .environment(\.layoutDirection, .leftToRight)
    }
}

struct StoriesBar: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let onAdd: () -> Void

    var body: some View {
        let people = host.stories?.recent ?? []
        let muted = host.stories?.muted ?? []
        ScrollView(.horizontal, showsIndicators: false) {
            LazyHStack(spacing: 6) {
                myStatus
                ForEach(Array(people.enumerated()), id: \.offset) { i, g in
                    person(g, i + 1) { host.openStories(people, start: i) }
                        .onAppear { if i >= people.count - 2 { Task { await host.loadMoreStories() } } }
                }
                ForEach(Array(muted.enumerated()), id: \.offset) { i, g in
                    person(g, people.count + i + 1) { host.openStories(muted, start: i) }.opacity(0.55)
                }
            }
            .padding(.horizontal, 8).padding(.top, 12).padding(.bottom, 4)
        }
        .frame(height: 98)
    }

    private var myStatus: some View {
        let mine = host.stories?.mine
        let count = mine?.stories.count ?? 0
        let me = AuthSession.shared.user
        return VStack(spacing: 4) {
            ZStack(alignment: .bottomTrailing) {
                // My stories show like anyone's: an arc per story and my latest one inside the circle.
                ChStoryRing(count: count, seenCount: 0, size: 58, uploading: host.storyUploading) {
                    if let latest = mine?.stories.last { StoryThumb(story: latest, size: 50) } else { ChAvatar(url: me?.avatar, name: me?.name, key: myKey(), size: 50) }
                }
                if count > 1 {
                    Text(verbatim: "\(count)").font(.dorr(10, .heavy)).foregroundStyle(.white).padding(.horizontal, 6).padding(.vertical, 1)
                        .background(ch.red, in: Capsule()).frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topTrailing)
                }
                Button(action: onAdd) {
                    Image(systemName: "plus").font(.system(size: 11, weight: .black)).foregroundStyle(.white)
                        .frame(width: 20, height: 20).background(ch.headerBrush, in: Circle()).overlay(Circle().stroke(.white, lineWidth: 2))
                }
                .buttonStyle(.plain)
            }
            .frame(width: 58, height: 58)
            Text(verbatim: host.storyUploading ? L("st_uploading") : count > 0 ? (aiTimeAgo(mine?.stories.last?.createdAt).isEmpty ? L("st_my_status") : aiTimeAgo(mine?.stories.last?.createdAt)) : L("st_my_status"))
                .font(.dorr(10.5, .semibold)).foregroundStyle(ch.ink).lineLimit(1)
        }
        .frame(width: 64)
        .contentShape(Rectangle())
        .onTapGesture { if let mine, count > 0 { host.openStories([mine], start: 0) } else { onAdd() } }
        .onLongPressGesture(perform: onAdd)
    }

    private func person(_ g: StoryGroupDto, _ index: Int, _ action: @escaping () -> Void) -> some View {
        let seen = g.stories.filter { $0.seen == true }.count
        return Button(action: action) {
            VStack(spacing: 4) {
                ChStoryRing(count: g.stories.count, seenCount: seen, size: 58) { ChAvatar(url: g.owner?.avatar, name: g.owner?.name, key: g.owner?.key, size: 50) }
                Text(verbatim: ltrTitle(g.owner?.name)).font(.dorr(10.5, g.allSeen == true ? .regular : .bold)).foregroundStyle(g.allSeen == true ? ch.mut : ch.ink).lineLimit(1)
            }
            .frame(width: 64)
        }
        .buttonStyle(.plain)
        .chStagger(index)
    }
}

/// A story in miniature: the photo (or a play mark for a video), or a text status as its gradient with the first words.
struct StoryThumb: View {
    @ObservedObject private var ch = Ch.shared
    let story: StoryDto
    let size: CGFloat

    var body: some View {
        ZStack {
            StoryLook.brush(story.style?.background)
            if let media = story.media, media.mimeType?.hasPrefix("video/") == true {
                ch.headerBrush
                Image(systemName: "play.fill").font(.system(size: size * 0.32)).foregroundStyle(.white)
            } else if let media = story.media {
                AsyncImage(url: ApiClient.mediaUrl(media.url)) { phase in
                    if let image = phase.image { image.resizable().scaledToFill() }
                }
            } else {
                Text(verbatim: String((story.body ?? "").prefix(18))).font(.dorr(8, .bold)).foregroundStyle(.white).lineLimit(3).multilineTextAlignment(.center).padding(6)
            }
        }
        .frame(width: size, height: size)
        .clipShape(Circle())
    }
}

/// «Text», «Photo or video» or «Voice» — the ways to post: three tiles side by side, each with its own coloured
/// circle (text in the app's red); × closes it (androidApp `StoryAddSheet`).
struct StoryAddSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    @State private var items: [PhotosPickerItem] = []

    var body: some View {
        ChSheetCard {
            HStack {
                Text(L("st_add_status")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                Spacer()
                Button(action: onDismiss) {
                    Image(systemName: "xmark").font(.system(size: 14, weight: .bold)).foregroundStyle(ch.mut)
                        .frame(width: 34, height: 34).background(ch.surfaceMuted, in: Circle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("ch_close_sheet"))
            }
            .padding(.bottom, 14)
            HStack(spacing: 10) {
                tileButton("pencil", L("st_text"), ch.red, 0) { onDismiss(); host.push(.storyComposer()) }
                // Up to 10 at once, each its own status.
                PhotosPicker(selection: $items, maxSelectionCount: storyMediaMax, selectionBehavior: .ordered, matching: .any(of: [.images, .videos])) {
                    tile("camera.fill", L("st_photo_video"), Color(hex: 0x6D5BD0))
                }
                .buttonStyle(.plain).chStagger(1)
                tileButton("mic.fill", L("bk_voice_status"), Color(hex: 0x3E8A99), 2) { onDismiss(); host.push(.storyComposer(media: storyVoice)) }
            }
        }
        .onChange(of: items) { picked in
            guard !picked.isEmpty else { return }
            items = []
            Task {
                var urls: [URL] = []
                for p in picked { if let f = await aiCopyPicked(p) { urls.append(f.url) } }
                guard !urls.isEmpty else { return }
                onDismiss()
                host.push(.storyComposer(media: storyMediaRoute(urls)))
            }
        }
    }

    private func tileButton(_ icon: String, _ label: String, _ color: Color, _ i: Int, _ action: @escaping () -> Void) -> some View {
        Button(action: action) { tile(icon, label, color) }.buttonStyle(.plain).chStagger(i)
    }

    private func tile(_ icon: String, _ label: String, _ color: Color) -> some View {
        VStack(spacing: 10) {
            Image(systemName: icon).font(.system(size: 19)).foregroundStyle(.white)
                .frame(width: 46, height: 46).background(color, in: Circle())
            Text(verbatim: label).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink).lineLimit(1).minimumScaleFactor(0.8)
        }
        .frame(maxWidth: .infinity).padding(.vertical, 18).padding(.horizontal, 6)
        .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(ch.line, lineWidth: 1))
        .contentShape(Rectangle())
    }
}
