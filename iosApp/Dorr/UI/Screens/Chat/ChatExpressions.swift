import PhotosUI
import SwiftUI
import Vision

// androidApp `chat/ChatExpressionsUi.kt` (the panel) + `chat/ChatStickerMaker.kt` — Emoji · Stickers · GIF in one sheet.
// Stickers: my recents, my own stickers (made from a photo), Dorr's packs and the big animated library (Giphy) with
// search; GIFs: trending, quick moods, search, endless scroll. Everything shrinks under the finger; a tap picks it.

/// What was picked, ready to send: the message type and the fields (the server builds the rest).
struct ExpressionPick {
    let type: String
    /// Only the fields that have a value (Android drops the nulls).
    let extra: [String: Any]

    init(type: String, extra: [String: Any?]) {
        self.type = type
        self.extra = extra.compactMapValues { $0 }
    }
}

private struct RecentItem: Codable, Equatable {
    let kind: String
    let source: String
    let id: String
    let url: String
    var width = 0
    var height = 0
}

/// The last 24 stickers / GIFs used, on this phone.
private enum Recents {
    private static let store = UserDefaults(suiteName: "chat_expressions") ?? .standard

    static func load(_ kind: String) -> [RecentItem] {
        guard let data = store.data(forKey: kind) else { return [] }
        return (try? JSONDecoder().decode([RecentItem].self, from: data)) ?? []
    }

    static func add(_ item: RecentItem) {
        let list = ([item] + load(item.kind).filter { !($0.id == item.id && $0.source == item.source) }).prefix(24)
        if let data = try? JSONEncoder().encode(Array(list)) { store.set(data, forKey: item.kind) }
    }
}

/// Sending one of «My stickers»: only its id goes up — the server knows the file.
private func mySticker(_ s: StickerDto) -> ExpressionPick {
    ExpressionPick(type: "sticker", extra: ["my_sticker_id": s.id, "source": "mine", "url": s.url, "emoji": s.emoji])
}

// MARK: - the panel

struct ExpressionPanel: View {
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    let onEmoji: (String) -> Void
    let onPick: (ExpressionPick) -> Void
    @State private var tab = 1
    @State private var library: StickerLibraryDto?
    @State private var making = false

    var body: some View {
        VStack(spacing: 0) {
            Capsule().fill(ch.line).frame(width: 40, height: 4).padding(.top, 10).padding(.bottom, 8)
            tabs
            Group {
                switch tab {
                case 0: EmojiTab(onEmoji: onEmoji)
                case 1:
                    StickersTab(library: library, onPick: { onPick($0); onDismiss() }, onMake: { making = true }) { mine in
                        var l = library ?? StickerLibraryDto()
                        l.mine = mine
                        library = l
                    }
                default: GifTab(library: library) { onPick($0); onDismiss() }
                }
            }
            .transition(.opacity)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
        .frame(height: 460)
        .background(ch.surface.ignoresSafeArea(edges: .bottom))
        .clipShape(UnevenRoundedRectangleCompat(topRadius: 28))
        .contentShape(Rectangle())
        .onTapGesture {}
        .task { library = (try? await ChatApi.stickers().data) ?? StickerLibraryDto() }
        // A new sticker from my photo: kept in «My stickers» and sent straight away, like WhatsApp.
        .fullScreenCover(isPresented: $making) {
            StickerMakerView(onDismiss: { making = false }) { s in
                making = false
                var l = library ?? StickerLibraryDto()
                l.mine = [s] + (l.mine ?? [])
                library = l
                onPick(mySticker(s))
                onDismiss()
            }
        }
    }

    private var tabs: some View {
        let items = [("face.smiling", L("ch_expr_emoji")), ("note.text", L("ch_expr_stickers")), ("play.square", L("ch_expr_gif"))]
        return HStack(spacing: 0) {
            ForEach(Array(items.enumerated()), id: \.offset) { i, item in
                let on = i == tab
                Button {
                    withAnimation(.easeOut(duration: 0.22)) { tab = i }
                } label: {
                    HStack(spacing: 5) {
                        Image(systemName: item.0).font(.system(size: 17))
                        Text(verbatim: item.1).font(.dorr(13, .heavy))
                    }
                    .foregroundStyle(on ? ch.red : ch.mut)
                    .frame(maxWidth: .infinity).padding(.vertical, 9)
                    .background(on ? ch.surface : .clear, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(4)
        .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .padding(.horizontal, 16).padding(.vertical, 6)
    }
}

// MARK: - emoji

private let emojiGroups: [(String, [String])] = [
    ("😀", "😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 😉 😍 🥰 😘 😗 😋 😛 😜 🤪 😝 🤗 🤭 🤫 🤔 😐 😏 😒 🙄 😬 😌 😔 😪 😴 😷 🤒 🤕 🥵 🥶 🥴 😵 🤯 🤠 🥳 😎 🤓 🧐 😕 😟 🙁 😮 😯 😲 😳 🥺 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 😤 😡 😠 🤬".components(separatedBy: " ")),
    ("👍", "👍 👎 👌 🤌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐️ 🖖 👋 👏 🙌 👐 🤲 🤝 🙏 💪 🦾 ✍️ 💅 🤳 👀 👁️ 👄 🫶".components(separatedBy: " ")),
    ("❤️", "❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 💟 ☮️ ✨ ⭐ 🌟 💫 🔥 💯 ✅ ❌ ‼️ ❓ 💤".components(separatedBy: " ")),
    ("🐶", "🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🙈 🙉 🙊 🐔 🐧 🐦 🦆 🦅 🦉 🐴 🦄 🐝 🦋 🐢 🐍 🐬 🐳 🐟 🌸 🌹 🌻 🌷 🌳 🌴 🌵 🍀 🌙 ☀️ ⛅ 🌈 ❄️ 🌊".components(separatedBy: " ")),
    ("🍕", "🍏 🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🍒 🍑 🥭 🍍 🥥 🥑 🍅 🥕 🌽 🥔 🍞 🧀 🥚 🍳 🥞 🍗 🍖 🌭 🍔 🍟 🍕 🌮 🌯 🥙 🥗 🍝 🍜 🍣 🍤 🍩 🍪 🎂 🍰 🍫 🍬 🍭 ☕ 🍵 🧃 🥤 🍹".components(separatedBy: " ")),
    ("⚽", "⚽ 🏀 🏈 ⚾ 🎾 🏐 🏓 🏸 🥊 🏆 🥇 🎮 🎲 🎯 🎳 🎤 🎧 🎸 🎹 🥁 🎬 🎨 🎁 🎉 🎊 🎈 🕌 🕋 🌙 🏠 🚗 🚕 🚌 ✈️ 🚀 ⛵ 🗺️ 📱 💻 ⌚ 📷 💡 📚 ✏️ 💰 💳 🛒".components(separatedBy: " ")),
]

private struct EmojiTab: View {
    @ObservedObject private var ch = Ch.shared
    let onEmoji: (String) -> Void
    @State private var group = 0

    var body: some View {
        VStack(spacing: 0) {
            HStack {
                ForEach(Array(emojiGroups.enumerated()), id: \.offset) { i, g in
                    Button { group = i } label: {
                        Text(verbatim: g.0).font(.system(size: i == group ? 22 : 18))
                            .frame(width: 42, height: 42)
                            .background(i == group ? ch.red.opacity(0.1) : .clear, in: Circle())
                    }
                    .buttonStyle(.plain)
                    .frame(maxWidth: .infinity)
                    .animation(.spring(response: 0.3, dampingFraction: 0.5), value: group)
                }
            }
            .padding(.horizontal, 14).padding(.vertical, 4)
            ScrollView {
                LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 0), count: 8), spacing: 0) {
                    ForEach(emojiGroups[group].1, id: \.self) { emoji in
                        PopCell(action: { onEmoji(emoji) }) { Text(verbatim: emoji).font(.system(size: 26)) }.aspectRatio(1, contentMode: .fit)
                    }
                }
                .padding(.horizontal, 10).padding(.vertical, 6)
            }
        }
    }
}

// MARK: - stickers

private struct StickersTab: View {
    @EnvironmentObject private var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let library: StickerLibraryDto?
    let onPick: (ExpressionPick) -> Void
    let onMake: () -> Void
    let onMineChange: ([StickerDto]) -> Void
    /// -3 = my stickers, -2 = recent, -1 = the Giphy library, else a pack id.
    @State private var source = -2
    @State private var stored = Recents.load("sticker")
    @State private var removing: StickerDto?
    private let grid = Array(repeating: GridItem(.flexible(), spacing: 0), count: 4)

    /// A sticker of mine that I deleted is no longer offered in recents.
    private var recents: [RecentItem] {
        stored.filter { r in r.source != "mine" || library == nil || (library?.mine ?? []).contains { String($0.id) == r.id } }
    }

    var body: some View {
        VStack(spacing: 0) {
            // Sources: recent · mine · library · each pack's cover.
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    SourceChip(selected: source == -2, icon: "clock.arrow.circlepath") { source = -2 }
                    SourceChip(selected: source == -3, icon: "face.smiling") { source = -3 }
                    if library?.libraryEnabled == true { SourceChip(selected: source == -1, icon: "magnifyingglass") { source = -1 } }
                    ForEach(library?.packs ?? []) { pack in
                        SourceChip(selected: source == pack.id, image: pack.cover) { source = pack.id }
                    }
                }
                .padding(.horizontal, 12).padding(.vertical, 4)
            }
            content.frame(maxWidth: .infinity, maxHeight: .infinity)
        }
        .onChange(of: library != nil) { loaded in
            // Nothing recent yet: open on Dorr's first pack — or on «My stickers», where one can be made.
            if loaded, recents.isEmpty { source = library?.packs?.first?.id ?? -3 }
        }
        .dorrDialog(isPresented: Binding(get: { removing != nil }, set: { if !$0 { removing = nil } })) {
            if let s = removing { removeDialog(s) }
        }
    }

    @ViewBuilder
    private var content: some View {
        switch source {
        case -2:
            // Nothing at all to offer yet (no packs from the admin, no GIF library): say why, not just «nothing».
            if recents.isEmpty {
                EmptyHint(text: L(library != nil && (library?.packs ?? []).isEmpty && library?.libraryEnabled != true ? "ch_expr_no_stickers_yet" : "ch_expr_no_recent"))
            } else {
                ScrollView {
                    LazyVGrid(columns: grid, spacing: 0) {
                        ForEach(recents, id: \.id) { r in
                            PopCell(action: {
                                Recents.add(r)
                                let extra: [String: Any?]
                                switch r.source {
                                case "pack": extra = ["sticker_id": Int(r.id) ?? 0, "source": "pack", "url": r.url]
                                case "mine": extra = ["my_sticker_id": Int(r.id) ?? 0, "source": "mine", "url": r.url]
                                default: extra = ["giphy_id": r.id, "source": "giphy", "url": r.url]
                                }
                                onPick(ExpressionPick(type: "sticker", extra: extra))
                            }) { StickerImage(url: r.url) }
                            .aspectRatio(1, contentMode: .fit).padding(4)
                        }
                    }
                    .padding(10)
                }
            }
        case -1:
            GiphyGrid(kind: "stickers", columns: 4) { g in
                Recents.add(RecentItem(kind: "sticker", source: "giphy", id: g.id, url: g.webp ?? g.url, width: g.width ?? 0, height: g.height ?? 0))
                onPick(ExpressionPick(type: "sticker", extra: ["giphy_id": g.id, "source": "giphy", "url": g.webp ?? g.url, "title": g.title]))
            }
        case -3:
            // My stickers: «+» makes one from a photo; a long press removes one.
            ScrollView {
                LazyVGrid(columns: grid, spacing: 0) {
                    PopCell(action: onMake) {
                        VStack(spacing: 2) {
                            Image(systemName: "plus").font(.system(size: 22, weight: .bold))
                            Text(L("ch_sticker_make_short")).font(.dorr(11, .heavy))
                        }
                        .foregroundStyle(ch.red)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                        .background(ch.red.opacity(0.1), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    }
                    .aspectRatio(1, contentMode: .fit).padding(4)
                    ForEach(library?.mine ?? []) { s in
                        PopCell(action: {
                            Recents.add(RecentItem(kind: "sticker", source: "mine", id: String(s.id), url: s.url ?? "", width: s.width ?? 0, height: s.height ?? 0))
                            onPick(mySticker(s))
                        }, onLongPress: { removing = s }) { StickerImage(url: s.url) }
                        .aspectRatio(1, contentMode: .fit).padding(4)
                    }
                }
                .padding(10)
                if library != nil, (library?.mine ?? []).isEmpty {
                    Text(L("ch_sticker_mine_empty")).font(.dorr(13)).foregroundStyle(ch.mut).frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 18)
                }
            }
        default:
            if let pack = library?.packs?.first(where: { $0.id == source }) {
                ScrollView {
                    LazyVGrid(columns: grid, spacing: 0) {
                        ForEach(pack.stickers ?? []) { s in
                            PopCell(action: {
                                Recents.add(RecentItem(kind: "sticker", source: "pack", id: String(s.id), url: s.url ?? "", width: s.width ?? 0, height: s.height ?? 0))
                                onPick(ExpressionPick(type: "sticker", extra: ["sticker_id": s.id, "source": "pack", "url": s.url, "emoji": s.emoji]))
                            }) { StickerImage(url: s.url) }
                            .aspectRatio(1, contentMode: .fit).padding(4)
                        }
                    }
                    .padding(10)
                }
            } else {
                ProgressView().tint(ch.red)
            }
        }
    }

    private func removeDialog(_ s: StickerDto) -> some View {
        VStack(spacing: 12) {
            StickerImage(url: s.url).frame(width: 84, height: 84)
            Text(L("ch_sticker_remove_title")).font(.dorr(17, .heavy)).foregroundStyle(ch.ink)
            Text(L("ch_sticker_remove_body")).font(.dorr(14)).foregroundStyle(ch.mut).multilineTextAlignment(.center)
            HStack {
                Button(L("ch_sticker_keep")) { removing = nil }.font(.dorr(15, .bold)).foregroundStyle(ch.mut).frame(maxWidth: .infinity)
                Button(L("ch_sticker_remove")) {
                    removing = nil
                    // Gone from the grid at once; it comes back if the server says no.
                    let before = library?.mine ?? []
                    onMineChange(before.filter { $0.id != s.id })
                    Task {
                        do { _ = try await ChatApi.deleteMySticker(s.id) } catch {
                            onMineChange(before)
                            if let msg = error.apiFailure.message { host.showToast(msg, error: true) }
                        }
                    }
                }
                .font(.dorr(15, .bold)).foregroundStyle(ch.red).frame(maxWidth: .infinity)
            }
            .padding(.top, 6)
        }
        .padding(22)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 26, style: .continuous))
        .padding(.horizontal, 30)
    }
}

private struct SourceChip: View {
    @ObservedObject private var ch = Ch.shared
    let selected: Bool
    var icon: String?
    var image: String?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Group {
                if let image { StickerImage(url: image) } else if let icon {
                    Image(systemName: icon).font(.system(size: 19)).foregroundStyle(selected ? ch.red : ch.mut)
                }
            }
            .padding(6)
            .frame(width: 44, height: 44)
            .background(selected ? ch.red.opacity(0.12) : .clear, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .animation(.easeOut(duration: 0.2), value: selected)
        }
        .buttonStyle(.plain)
    }
}

// MARK: - GIFs

private struct GifTab: View {
    let library: StickerLibraryDto?
    let onPick: (ExpressionPick) -> Void

    var body: some View {
        // The GIF library is off until the server has a Giphy key — say so instead of «no results».
        if let library, library.libraryEnabled != true {
            EmptyHint(text: L("ch_expr_gifs_off"))
        } else {
            GiphyGrid(kind: "gifs", columns: 2, moods: true) { g in
                Recents.add(RecentItem(kind: "gif", source: "giphy", id: g.id, url: g.webp ?? g.url, width: g.width ?? 0, height: g.height ?? 0))
                onPick(ExpressionPick(type: "gif", extra: [
                    "giphy_id": g.id, "source": "giphy", "url": g.url, "webp": g.webp, "width": g.width, "height": g.height, "title": g.title,
                ]))
            }
        }
    }
}

/// Giphy results: a search field (+ mood chips for GIFs), then a staggered grid that loads more as it nears the end.
/// Trending when the search is empty.
private struct GiphyGrid: View {
    @ObservedObject private var ch = Ch.shared
    let kind: String
    let columns: Int
    var moods = false
    let onPick: (GifDto) -> Void
    @State private var query = ""
    @State private var items: [GifDto] = []
    @State private var next: Int? = 0
    @State private var loading = false

    private static let moodList = [("😂", "ch_mood_haha"), ("❤️", "ch_mood_love"), ("👏", "ch_mood_congrats"), ("👋", "ch_mood_hi"),
                                   ("🎉", "ch_mood_party"), ("😢", "ch_mood_sad"), ("😴", "ch_mood_sleepy"), ("🤔", "ch_mood_thinking")]

    var body: some View {
        VStack(spacing: 0) {
            ChField(placeholder: L(kind == "gifs" ? "ch_expr_search_gifs" : "ch_expr_search_stickers"), text: $query, icon: "magnifyingglass", limit: 50)
                .padding(.horizontal, 14).padding(.vertical, 4)
            if moods {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 6) {
                        ForEach(Array(Self.moodList.enumerated()), id: \.offset) { i, m in
                            let word = L(m.1)
                            let on = query == word
                            Button { query = on ? "" : word } label: {
                                HStack(spacing: 4) {
                                    Text(verbatim: m.0).font(.system(size: 14))
                                    Text(verbatim: word).font(.dorr(12.5, .bold)).foregroundStyle(on ? .white : ch.ink)
                                }
                                .padding(.horizontal, 12).padding(.vertical, 7)
                                .background(on ? ch.red : ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                            }
                            .buttonStyle(.plain)
                            .chStagger(i)
                        }
                    }
                    .padding(.horizontal, 14).padding(.vertical, 4)
                }
            }
            if items.isEmpty && !loading {
                EmptyHint(text: L("ch_expr_nothing"))
                Spacer()
            } else {
                ScrollView {
                    HStack(alignment: .top, spacing: 6) {
                        ForEach(0..<columns, id: \.self) { col in
                            LazyVStack(spacing: 6) {
                                ForEach(column(col), id: \.element.id) { i, g in cell(g).onAppear { if i >= items.count - 6 { Task { await loadMore() } } } }
                            }
                        }
                    }
                    .padding(10)
                }
            }
            // «Powered by GIPHY» — required by Giphy's terms.
            Text(L("ch_expr_powered")).font(.dorr(10)).foregroundStyle(ch.soft).padding(.bottom, 4)
        }
        .task(id: query) {
            if !query.isEmpty { try? await Task.sleep(nanoseconds: 350_000_000) }
            guard !Task.isCancelled else { return }
            items = []
            next = 0
            await loadMore()
        }
    }

    /// Masonry: item i sits in column i % columns, so the order still reads row by row.
    private func column(_ col: Int) -> [(offset: Int, element: GifDto)] {
        items.enumerated().filter { $0.offset % columns == col }.map { ($0.offset, $0.element) }
    }

    private func cell(_ g: GifDto) -> some View {
        let w = Double(g.width ?? 0), h = Double(g.height ?? 0)
        let ratio = kind == "stickers" ? 1 : (w > 0 && h > 0 ? min(max(w / h, 0.5), 2.5) : 1)
        return PopCell(action: { onPick(g) }) {
            AnimatedImageView(url: ApiClient.mediaUrl(g.preview ?? g.url), mode: kind == "stickers" ? .scaleAspectFit : .scaleAspectFill)
                .background(kind == "stickers" ? Color.clear : ch.surfaceMuted)
        }
        .aspectRatio(ratio, contentMode: .fit)
    }

    private func loadMore() async {
        guard let offset = next, !loading else { return }
        loading = true
        let page = try? await ChatApi.gifs(kind: kind, q: query.trimmingCharacters(in: .whitespaces).isEmpty ? nil : query.trimmingCharacters(in: .whitespaces), offset: offset).data
        let known = Set(items.map(\.id))
        items += (page?.items ?? []).filter { !known.contains($0.id) }
        next = page?.nextOffset
        loading = false
    }
}

// MARK: - bits

/// A cell that shrinks under the finger and springs back (the press feels alive).
private struct PopCell<Content: View>: View {
    let action: () -> Void
    var onLongPress: (() -> Void)?
    @ViewBuilder let content: () -> Content

    var body: some View {
        Button(action: action) {
            content().frame(maxWidth: .infinity, maxHeight: .infinity).clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous)).contentShape(Rectangle())
        }
        .buttonStyle(WaPressStyle(scale: 0.82))
        .simultaneousGesture(LongPressGesture().onEnded { _ in onLongPress?() }, including: onLongPress == nil ? .subviews : .all)
    }
}

private struct EmptyHint: View {
    @ObservedObject private var ch = Ch.shared
    let text: String

    var body: some View {
        Text(verbatim: text).font(.dorr(13.5)).foregroundStyle(ch.mut).multilineTextAlignment(.center).frame(maxWidth: .infinity).padding(40)
    }
}

/// A sticker (still or animated), fitted.
struct StickerImage: View {
    let url: String?

    var body: some View {
        AnimatedImageView(url: ApiClient.mediaUrl(url), animate: autoplayClips, mode: .scaleAspectFit)
    }
}

// MARK: - sticker maker

/// How the photo becomes a sticker.
private enum StickerShape { case cutout, circle, rounded }

/// A sticker is 512×512 with a transparent background (the WhatsApp size).
private let stickerPx: CGFloat = 512
/// The server takes stickers up to 1 MB.
private let stickerMaxBytes = 950_000

/// Make a sticker from my own photo, like WhatsApp: pick a photo, the subject is cut out of its background on the phone
/// (Vision, iOS 17+) with a white sticker outline — or a circle / rounded square — then pinch and drag it into place and
/// save. It lands in «My stickers» and is sent right away.
struct StickerMakerView: View {
    @EnvironmentObject private var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    let onCreated: (StickerDto) -> Void
    @State private var item: PhotosPickerItem?
    @State private var picking = true
    @State private var photo: UIImage?
    @State private var cutout: UIImage?
    @State private var cutting = false
    @State private var cutFailed = false
    @State private var shape = StickerShape.cutout
    @State private var scale: CGFloat = 1
    @State private var baseScale: CGFloat = 1
    @State private var offset: CGSize = .zero
    @State private var baseOffset: CGSize = .zero
    @State private var frame: CGFloat = 1
    @State private var saving = false

    private var source: UIImage? { shape == .cutout ? cutout : photo }

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 12) {
                Button(action: onDismiss) {
                    Image(systemName: "xmark").font(.system(size: 17, weight: .bold)).foregroundStyle(.white)
                        .frame(width: 42, height: 42).background(Color.white.opacity(0.12), in: Circle())
                }
                .buttonStyle(.plain)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("ch_sticker_make")).font(.dorr(17, .heavy)).foregroundStyle(.white)
                    Text(L("ch_sticker_make_hint")).font(.dorr(12)).foregroundStyle(.white.opacity(0.6))
                }
                Spacer()
            }
            .padding(14)

            GeometryReader { geo in
                let side = min(geo.size.width, geo.size.height)
                ZStack {
                    Checkerboard()
                    if let source {
                        Image(uiImage: source).resizable().scaledToFit()
                            .scaleEffect(scale).offset(offset)
                            .frame(width: side, height: side)
                            .clipShape(frameShape)
                    }
                    if cutting || photo == nil {
                        VStack(spacing: 10) {
                            ProgressView().tint(.white)
                            if cutting { Text(L("ch_sticker_cutting")).font(.dorr(13)).foregroundStyle(.white) }
                        }
                    }
                }
                .frame(width: side, height: side)
                .clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous))
                .contentShape(Rectangle())
                .gesture(SimultaneousGesture(
                    MagnificationGesture().onChanged { scale = min(max(baseScale * $0, 0.4), 5) }.onEnded { _ in baseScale = scale },
                    DragGesture().onChanged { offset = CGSize(width: baseOffset.width + $0.translation.width, height: baseOffset.height + $0.translation.height) }
                        .onEnded { _ in baseOffset = offset }
                ))
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .onAppear { frame = side }
                .onChange(of: side) { frame = $0 }
            }
            .padding(20)

            HStack(spacing: 10) {
                shapeChip("wand.and.stars", L("ch_sticker_cutout"), .cutout, enabled: cutout != nil)
                shapeChip("circle", L("ch_sticker_circle"), .circle, enabled: photo != nil)
                shapeChip("square", L("ch_sticker_rounded"), .rounded, enabled: photo != nil)
            }
            .padding(.horizontal, 20)
            if cutFailed {
                Text(L("ch_sticker_no_subject")).font(.dorr(12)).foregroundStyle(.white.opacity(0.6)).multilineTextAlignment(.center).padding(.top, 8)
            }
            HStack(spacing: 10) {
                Button { picking = true } label: {
                    Text(L("ch_sticker_other_photo")).font(.dorr(14, .bold)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity).padding(.vertical, 15)
                        .background(Color.white.opacity(0.12), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                }
                .buttonStyle(.plain)
                Button(action: save) {
                    HStack(spacing: 8) {
                        if saving { ProgressView().tint(.white) } else { Image(systemName: "checkmark").font(.system(size: 17, weight: .bold)) }
                        Text(L("ch_sticker_save_send")).font(.dorr(14, .heavy))
                    }
                    .foregroundStyle(.white)
                    .frame(maxWidth: .infinity).padding(.vertical, 15)
                    .background(ch.red.opacity(source != nil && !saving ? 1 : 0.4), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                }
                .buttonStyle(.plain)
                .disabled(source == nil || saving || cutting)
            }
            .padding(.horizontal, 20).padding(.vertical, 22)
        }
        .background(Color(hex: 0x0E1116).ignoresSafeArea())
        .photosPicker(isPresented: $picking, selection: $item, matching: .images)
        .onChange(of: picking) { open in if !open, item == nil, photo == nil { onDismiss() } }
        .onChange(of: item) { picked in
            guard let picked else { return }
            item = nil
            Task { await load(picked) }
        }
    }

    private var frameShape: AnyShape {
        switch shape {
        case .circle: return AnyShape(Circle())
        case .rounded: return AnyShape(RoundedRectangle(cornerRadius: frame * 0.18, style: .continuous))
        case .cutout: return AnyShape(Rectangle())
        }
    }

    private func shapeChip(_ icon: String, _ label: String, _ value: StickerShape, enabled: Bool) -> some View {
        let on = shape == value
        return Button { shape = value } label: {
            VStack(spacing: 4) {
                Image(systemName: icon).font(.system(size: 20))
                Text(verbatim: label).font(.dorr(12, .bold))
            }
            .foregroundStyle(.white)
            .frame(maxWidth: .infinity).padding(.vertical, 10)
            .background(on ? ch.red : Color.white.opacity(0.1), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(Color.white.opacity(on ? 0 : 0.12)))
            .opacity(enabled ? 1 : 0.4)
            .animation(.easeOut(duration: 0.2), value: on)
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }

    private func load(_ picked: PhotosPickerItem) async {
        guard let data = try? await picked.loadTransferable(type: Data.self), let raw = UIImage(data: data) else { return }
        let image = upright(raw)
        photo = image
        cutout = nil
        cutFailed = false
        scale = 1; baseScale = 1
        offset = .zero; baseOffset = .zero
        shape = .cutout
        cutting = true
        cutout = await Task.detached { cutOut(image).map(withOutline) }.value
        cutting = false
        if cutout == nil {
            cutFailed = true
            shape = .circle
            host.showToast(L("ch_sticker_no_subject"))
        }
    }

    private func save() {
        guard let art = source else { return }
        saving = true
        let png = render(art, shape: shape, scale: scale, offset: offset, frame: frame)
        Task {
            do {
                if let s = try await ChatApi.uploadMySticker(ChatFile(field: "image", filename: "sticker.png", mimeType: "image/png", data: png)).data { onCreated(s) }
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
            saving = false
        }
    }
}

/// Grey squares behind the sticker: what you see through is transparent.
private struct Checkerboard: View {
    var body: some View {
        Canvas { gc, size in
            let cell: CGFloat = 16
            gc.fill(Path(CGRect(origin: .zero, size: size)), with: .color(Color(hex: 0x2A2F38)))
            var y: CGFloat = 0
            var row = 0
            while y < size.height {
                var x: CGFloat = row % 2 == 0 ? 0 : cell
                while x < size.width {
                    gc.fill(Path(CGRect(x: x, y: y, width: cell, height: cell)), with: .color(Color(hex: 0x353B46)))
                    x += cell * 2
                }
                y += cell
                row += 1
            }
        }
    }
}

/// The picked photo, right way up and no bigger than the cut-out needs (the sticker is 512px; 1280 leaves room to zoom).
private func upright(_ image: UIImage) -> UIImage {
    let longest = max(image.size.width, image.size.height)
    let fit = min(1, 1280 / max(longest, 1))
    let size = CGSize(width: image.size.width * fit, height: image.size.height * fit)
    let format = UIGraphicsImageRendererFormat()
    format.scale = 1
    return UIGraphicsImageRenderer(size: size, format: format).image { _ in image.draw(in: CGRect(origin: .zero, size: size)) }
}

/// The photo's main subject on a transparent background (Vision, on the phone), cropped to it; nil if none found
/// (or before iOS 17, which has no subject lifting).
private func cutOut(_ photo: UIImage) -> UIImage? {
    guard #available(iOS 17.0, *), let cg = photo.cgImage else { return nil }
    let request = VNGenerateForegroundInstanceMaskRequest()
    let handler = VNImageRequestHandler(cgImage: cg)
    guard (try? handler.perform([request])) != nil, let result = request.results?.first, !result.allInstances.isEmpty,
          let buffer = try? result.generateMaskedImage(ofInstances: result.allInstances, from: handler, croppedToInstancesExtent: true)
    else { return nil }
    let ci = CIImage(cvPixelBuffer: buffer)
    guard let out = CIContext().createCGImage(ci, from: ci.extent) else { return nil }
    return UIImage(cgImage: out)
}

/// The white outline of a sticker: the shape's silhouette drawn in white around it, then the shape on top.
private func withOutline(_ cut: UIImage) -> UIImage {
    let stroke = max(max(cut.size.width, cut.size.height) * 0.025, 4)
    let pad = stroke + 2
    let size = CGSize(width: cut.size.width + pad * 2, height: cut.size.height + pad * 2)
    let format = UIGraphicsImageRendererFormat()
    format.scale = 1
    let white = cut.withTintColor(.white, renderingMode: .alwaysOriginal)
    return UIGraphicsImageRenderer(size: size, format: format).image { _ in
        for i in 0..<24 {
            let a = 2 * Double.pi * Double(i) / 24
            white.draw(at: CGPoint(x: pad + cos(a) * stroke, y: pad + sin(a) * stroke))
        }
        cut.draw(at: CGPoint(x: pad, y: pad))
    }
}

/// The final 512×512 transparent sticker, exactly as framed on screen: fitted into the square, moved / zoomed by the
/// same amounts, clipped to a circle or rounded square if chosen. PNG keeps the transparency; a busy photo is scaled
/// down until it fits the server's 1 MB.
private func render(_ art: UIImage, shape: StickerShape, scale: CGFloat, offset: CGSize, frame: CGFloat) -> Data {
    func draw(_ px: CGFloat) -> Data {
        let format = UIGraphicsImageRendererFormat()
        format.scale = 1
        format.opaque = false
        let ratio = px / max(frame, 1)
        let image = UIGraphicsImageRenderer(size: CGSize(width: px, height: px), format: format).image { ctx in
            let square = CGRect(x: 0, y: 0, width: px, height: px)
            switch shape {
            case .circle: UIBezierPath(ovalIn: square).addClip()
            case .rounded: UIBezierPath(roundedRect: square, cornerRadius: px * 0.18).addClip()
            case .cutout: ctx.cgContext.clip(to: square)
            }
            let fit = min(px / art.size.width, px / art.size.height)
            let w = art.size.width * fit * scale
            let h = art.size.height * fit * scale
            art.draw(in: CGRect(x: (px - w) / 2 + offset.width * ratio, y: (px - h) / 2 + offset.height * ratio, width: w, height: h))
        }
        return image.pngData() ?? Data()
    }
    var px = stickerPx
    var data = draw(px)
    while data.count > stickerMaxBytes, px > 256 {
        px -= 64
        data = draw(px)
    }
    return data
}
