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

/// MARK: - the panel

/// Which of the three is showing — WhatsApp's order: emoji · GIF · stickers.
private let tabEmoji = 0, tabGif = 1, tabStickers = 2

struct ExpressionPanel: View {
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    let onEmoji: (String) -> Void
    let onPick: (ExpressionPick) -> Void
    /// Docked under the message box where the keyboard was (androidApp `ExpressionDock`): the keyboard's height,
    /// and it stays open after sending a sticker or a GIF.
    var docked = false
    var height: CGFloat = 460
    /// ⌫ in the bar (emoji tab) — only where there is a message being typed.
    var onBackspace: (() -> Void)?
    /// The face button opens on the emoji, straight away.
    @State private var tab = tabEmoji
    @State private var searching = false
    @State private var library: StickerLibraryDto?
    @State private var making = false

    var body: some View {
        VStack(spacing: 0) {
            Capsule().fill(ch.mut.opacity(0.35)).frame(width: 34, height: 4).padding(.top, docked ? 7 : 10).padding(.bottom, 2)
            bar
            Group {
                switch tab {
                case tabEmoji: EmojiTab(onEmoji: onEmoji)
                case tabGif: GifTab(library: library, searching: searching) { picked($0) }
                default:
                    StickersTab(library: library, searching: searching, onStopSearch: { searching = false }, onPick: { picked($0) }, onMake: { making = true }) { mine in
                        var l = library ?? StickerLibraryDto()
                        l.mine = mine
                        library = l
                    }
                }
            }
            .transition(.opacity)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
        .frame(height: docked ? height : 460)
        .background(ch.surface.ignoresSafeArea(edges: .bottom))
        .clipShape(UnevenRoundedRectangleCompat(topRadius: docked ? 22 : 28))
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
                picked(mySticker(s))
            }
        }
    }

    /// Sent: the sheet closes; the docked panel stays open (as in WhatsApp).
    private func picked(_ pick: ExpressionPick) {
        onPick(pick)
        if !docked { onDismiss() }
    }

    /// One bar for all three tabs (as in WhatsApp): search · [emoji | GIF | stickers] · this tab's action.
    private var bar: some View {
        // Search is for the GIF / sticker library only (emoji have no names to search by).
        let canSearch = tab != tabEmoji && library?.libraryEnabled == true
        return HStack(spacing: 0) {
            ZStack {
                if canSearch {
                    BarIcon(icon: "magnifyingglass", label: L(tab == tabGif ? "ch_expr_search_gifs" : "ch_expr_search_stickers"), active: searching) {
                        withAnimation(.easeOut(duration: 0.2)) { searching.toggle() }
                    }
                }
            }
            .frame(width: 44, height: 44)
            tabsSwitch.frame(maxWidth: .infinity)
            ZStack {
                if tab == tabEmoji, let onBackspace {
                    BackspaceKey(action: onBackspace)
                } else if tab == tabStickers {
                    BarIcon(icon: "pencil", label: L("ch_sticker_make_short"), active: false) { making = true }
                }
            }
            .frame(width: 44, height: 44)
        }
        .padding(.horizontal, 6).padding(.vertical, 4)
    }

    /// The three tabs as one small split pill — icons only (VoiceOver reads the names).
    private var tabsSwitch: some View {
        let items = [(tabEmoji, "ch_expr_emoji"), (tabGif, "ch_expr_gif"), (tabStickers, "ch_expr_stickers")]
        return HStack(spacing: 0) {
            ForEach(Array(items.enumerated()), id: \.offset) { i, item in
                if i > 0 { Rectangle().fill(ch.line).frame(width: 1) }
                let on = item.0 == tab
                Button {
                    guard item.0 != tab else { return }
                    withAnimation(.easeOut(duration: 0.22)) { tab = item.0; searching = false }
                } label: {
                    Group {
                        switch item.0 {
                        case tabEmoji: Image(systemName: "face.smiling").font(.system(size: 18))
                        case tabGif: Text(verbatim: "GIF").font(.system(size: 12, weight: .black)).tracking(0.6)
                        default: Image(systemName: "note.text").font(.system(size: 17))
                        }
                    }
                    .foregroundStyle(on ? ch.red : ch.mut)
                    .frame(width: 64, height: 36)
                    .background(on ? ch.red.opacity(0.14) : .clear)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L(item.1))
            }
        }
        .frame(height: 36)
        .background(ch.surfaceMuted)
        .clipShape(Capsule())
        .overlay(Capsule().stroke(ch.line, lineWidth: 1))
    }
}

private struct BarIcon: View {
    @ObservedObject private var ch = Ch.shared
    let icon: String
    let label: String
    let active: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 19)).foregroundStyle(active ? ch.red : ch.ink)
                .frame(width: 40, height: 40)
                .background(active ? ch.red.opacity(0.14) : .clear, in: Circle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label)
    }
}

/// ⌫ — removes the last character (a whole emoji counts as one); held down, it keeps going.
private struct BackspaceKey: View {
    @ObservedObject private var ch = Ch.shared
    let action: () -> Void
    @State private var repeater: Task<Void, Never>?

    var body: some View {
        Image(systemName: "delete.left").font(.system(size: 19)).foregroundStyle(ch.ink)
            .frame(width: 40, height: 40).contentShape(Circle())
            .gesture(
                DragGesture(minimumDistance: 0)
                    .onChanged { _ in
                        guard repeater == nil else { return }
                        action()
                        repeater = Task { @MainActor in
                            try? await Task.sleep(nanoseconds: 450_000_000)
                            while !Task.isCancelled {
                                action()
                                try? await Task.sleep(nanoseconds: 70_000_000)
                            }
                        }
                    }
                    .onEnded { _ in
                        repeater?.cancel()
                        repeater = nil
                    }
            )
            .accessibilityLabel(L("ch_expr_backspace"))
            .accessibilityAddTraits(.isButton)
    }
}

/// The strip of sections along the bottom of a tab (as in WhatsApp).
private struct SectionIcon: View {
    @ObservedObject private var ch = Ch.shared
    let selected: Bool
    let icon: String
    let label: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 18)).foregroundStyle(selected ? ch.red : ch.mut)
                .frame(width: 38, height: 38)
                .background(selected ? ch.red.opacity(0.12) : .clear, in: Circle())
                .animation(.easeOut(duration: 0.2), value: selected)
        }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity)
        .accessibilityLabel(label)
    }
}

// MARK: - emoji

private struct EmojiSection {
    let icon: String
    let title: String
    let emojis: [String]
}

private let emojiSections: [EmojiSection] = [
    EmojiSection(icon: "face.smiling", title: "ch_emoji_smileys", emojis: "😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 😉 😍 🥰 😘 😗 😋 😛 😜 🤪 😝 🤗 🤭 🤫 🤔 😐 😏 😒 🙄 😬 😌 😔 😪 😴 😷 🤒 🤕 🥵 🥶 🥴 😵 🤯 🤠 🥳 😎 🤓 🧐 😕 😟 🙁 😮 😯 😲 😳 🥺 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 😤 😡 😠 🤬".components(separatedBy: " ")),
    EmojiSection(icon: "hand.raised", title: "ch_emoji_hands", emojis: "👍 👎 👌 🤌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐️ 🖖 👋 👏 🙌 👐 🤲 🤝 🙏 💪 🦾 ✍️ 💅 🤳 👀 👁️ 👄 🫶".components(separatedBy: " ")),
    EmojiSection(icon: "heart", title: "ch_emoji_symbols", emojis: "❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 💟 ☮️ ✨ ⭐ 🌟 💫 🔥 💯 ✅ ❌ ‼️ ❓ 💤".components(separatedBy: " ")),
    EmojiSection(icon: "pawprint", title: "ch_emoji_nature", emojis: "🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🙈 🙉 🙊 🐔 🐧 🐦 🦆 🦅 🦉 🐴 🦄 🐝 🦋 🐢 🐍 🐬 🐳 🐟 🌸 🌹 🌻 🌷 🌳 🌴 🌵 🍀 🌙 ☀️ ⛅ 🌈 ❄️ 🌊".components(separatedBy: " ")),
    EmojiSection(icon: "fork.knife", title: "ch_emoji_food", emojis: "🍏 🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🍒 🍑 🥭 🍍 🥥 🥑 🍅 🥕 🌽 🥔 🍞 🧀 🥚 🍳 🥞 🍗 🍖 🌭 🍔 🍟 🍕 🌮 🌯 🥙 🥗 🍝 🍜 🍣 🍤 🍩 🍪 🎂 🍰 🍫 🍬 🍭 ☕ 🍵 🧃 🥤 🍹".components(separatedBy: " ")),
    EmojiSection(icon: "soccerball", title: "ch_emoji_activity", emojis: "⚽ 🏀 🏈 ⚾ 🎾 🏐 🏓 🏸 🥊 🏆 🥇 🎮 🎲 🎯 🎳 🎤 🎧 🎸 🎹 🥁 🎬 🎨 🎁 🎉 🎊 🎈 🕌 🕋 🌙 🏠 🚗 🚕 🚌 ✈️ 🚀 ⛵ 🗺️ 📱 💻 ⌚ 📷 💡 📚 ✏️ 💰 💳 🛒".components(separatedBy: " ")),
]

/// My recent emoji, newest first, on this phone.
private enum EmojiRecents {
    private static let store = UserDefaults(suiteName: "chat_expressions") ?? .standard

    static func load() -> [String] {
        (store.string(forKey: "emoji") ?? "").split(separator: " ").map(String.init)
    }

    static func add(_ emoji: String) {
        let list = ([emoji] + load().filter { $0 != emoji }).prefix(32)
        store.set(list.joined(separator: " "), forKey: "emoji")
    }
}

/// Where each section's title is, in the scroll view (to mark the section in view).
private struct SectionTops: PreferenceKey {
    static var defaultValue: [Int: CGFloat] = [:]
    static func reduce(value: inout [Int: CGFloat], nextValue: () -> [Int: CGFloat]) { value.merge(nextValue()) { $1 } }
}

/// All the emoji in one scrolling grid, each section under its name — my recent ones first — and the sections' icons
/// along the bottom (as in WhatsApp): the one in view is marked, a tap jumps to it.
private struct EmojiTab: View {
    @ObservedObject private var ch = Ch.shared
    let onEmoji: (String) -> Void
    /// Read once: what I pick now shows up there next time, so the grid doesn't jump under my finger.
    @State private var sections: [EmojiSection] = {
        let recents = EmojiRecents.load()
        return (recents.isEmpty ? [] : [EmojiSection(icon: "clock", title: "ch_emoji_recent", emojis: recents)]) + emojiSections
    }()
    @State private var current = 0
    private let grid = Array(repeating: GridItem(.flexible(), spacing: 0), count: 8)

    var body: some View {
        ScrollViewReader { proxy in
            VStack(spacing: 0) {
                ScrollView {
                    LazyVGrid(columns: grid, spacing: 0) {
                        ForEach(Array(sections.enumerated()), id: \.offset) { si, s in
                            Section {
                                ForEach(Array(s.emojis.enumerated()), id: \.offset) { _, emoji in
                                    PopCell(action: { EmojiRecents.add(emoji); onEmoji(emoji) }) { Text(verbatim: emoji).font(.system(size: 26)) }
                                        .aspectRatio(1, contentMode: .fit)
                                }
                            } header: {
                                Text(L(s.title)).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut)
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    .padding(.horizontal, 6).padding(.vertical, 6)
                                    .id("title\(si)")
                                    .background(GeometryReader { g in
                                        Color.clear.preference(key: SectionTops.self, value: [si: g.frame(in: .named("emojiScroll")).minY])
                                    })
                            }
                        }
                    }
                    .padding(.horizontal, 8).padding(.vertical, 2)
                }
                .coordinateSpace(name: "emojiScroll")
                .onPreferenceChange(SectionTops.self) { tops in
                    // The last title at or above the top; none there yet → the one before the first title in view.
                    if let passed = tops.filter({ $0.value <= 20 }).keys.max() { current = passed }
                    else if let first = tops.keys.min() { current = max(first - 1, 0) }
                }
                Rectangle().fill(ch.line).frame(height: 1)
                HStack(spacing: 0) {
                    ForEach(Array(sections.enumerated()), id: \.offset) { i, s in
                        SectionIcon(selected: i == current, icon: s.icon, label: L(s.title)) { proxy.scrollTo("title\(i)", anchor: .top) }
                    }
                }
                .padding(.horizontal, 8).padding(.vertical, 4)
            }
        }
    }
}

// MARK: - stickers

/// Five to a row (as in WhatsApp), «Create sticker» first; the sources along the bottom: recent · mine · the
/// library · each of Dorr's packs. The bar's 🔍 searches the library.
private struct StickersTab: View {
    @EnvironmentObject private var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let library: StickerLibraryDto?
    let searching: Bool
    let onStopSearch: () -> Void
    let onPick: (ExpressionPick) -> Void
    let onMake: () -> Void
    let onMineChange: ([StickerDto]) -> Void
    /// -3 = my stickers, -2 = recent, -1 = the Giphy library, else a pack id.
    @State private var source = -2
    @State private var stored = Recents.load("sticker")
    @State private var removing: StickerDto?
    private let grid = Array(repeating: GridItem(.flexible(), spacing: 0), count: 5)

    /// A sticker of mine that I deleted is no longer offered in recents.
    private var recents: [RecentItem] {
        stored.filter { r in r.source != "mine" || library == nil || (library?.mine ?? []).contains { String($0.id) == r.id } }
    }

    /// Searching is always in the library.
    private var shown: Int { searching ? -1 : source }

    var body: some View {
        VStack(spacing: 0) {
            content.frame(maxWidth: .infinity, maxHeight: .infinity)
            // Sources, along the bottom (as in WhatsApp).
            Rectangle().fill(ch.line).frame(height: 1)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 4) {
                    SourceChip(selected: shown == -2, icon: "clock") { open(-2) }
                    SourceChip(selected: shown == -3, icon: "face.smiling") { open(-3) }
                    if library?.libraryEnabled == true { SourceChip(selected: shown == -1, icon: "flame") { open(-1) } }
                    ForEach(library?.packs ?? []) { pack in
                        SourceChip(selected: shown == pack.id, image: pack.cover) { open(pack.id) }
                    }
                }
                .padding(.horizontal, 10).padding(.vertical, 4)
            }
        }
        .onChange(of: library != nil) { loaded in
            // Nothing recent yet: open on Dorr's first pack — or on «My stickers», where one can be made.
            if loaded, recents.isEmpty { source = library?.packs?.first?.id ?? -3 }
        }
        .dorrDialog(isPresented: Binding(get: { removing != nil }, set: { if !$0 { removing = nil } })) {
            if let s = removing { removeDialog(s) }
        }
    }

    private func open(_ id: Int) {
        source = id
        onStopSearch()
    }

    @ViewBuilder
    private var content: some View {
        switch shown {
        case -2:
            ScrollView {
                LazyVGrid(columns: grid, spacing: 0) {
                    MakeStickerCell(action: onMake)
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
                        .aspectRatio(1, contentMode: .fit).padding(3)
                    }
                }
                .padding(.horizontal, 8).padding(.vertical, 4)
                // Nothing at all to offer yet (no packs from the admin, no GIF library): say why, not just «nothing».
                if recents.isEmpty {
                    EmptyHint(text: L(library != nil && (library?.packs ?? []).isEmpty && library?.libraryEnabled != true ? "ch_expr_no_stickers_yet" : "ch_expr_no_recent"))
                }
            }
        case -1:
            GiphyGrid(kind: "stickers", columns: 5, showSearch: searching) { g in
                Recents.add(RecentItem(kind: "sticker", source: "giphy", id: g.id, url: g.webp ?? g.url, width: g.width ?? 0, height: g.height ?? 0))
                onPick(ExpressionPick(type: "sticker", extra: ["giphy_id": g.id, "source": "giphy", "url": g.webp ?? g.url, "title": g.title]))
            }
        case -3:
            // My stickers: «Create» makes one from a photo; a long press removes one.
            ScrollView {
                LazyVGrid(columns: grid, spacing: 0) {
                    MakeStickerCell(action: onMake)
                    ForEach(library?.mine ?? []) { s in
                        PopCell(action: {
                            Recents.add(RecentItem(kind: "sticker", source: "mine", id: String(s.id), url: s.url ?? "", width: s.width ?? 0, height: s.height ?? 0))
                            onPick(mySticker(s))
                        }, onLongPress: { removing = s }) { StickerImage(url: s.url) }
                        .aspectRatio(1, contentMode: .fit).padding(3)
                    }
                }
                .padding(.horizontal, 8).padding(.vertical, 4)
                if library != nil, (library?.mine ?? []).isEmpty {
                    Text(L("ch_sticker_mine_empty")).font(.dorr(13)).foregroundStyle(ch.mut).frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 18)
                }
            }
        default:
            if let pack = library?.packs?.first(where: { $0.id == shown }) {
                ScrollView {
                    LazyVGrid(columns: grid, spacing: 0) {
                        ForEach(pack.stickers ?? []) { s in
                            PopCell(action: {
                                Recents.add(RecentItem(kind: "sticker", source: "pack", id: String(s.id), url: s.url ?? "", width: s.width ?? 0, height: s.height ?? 0))
                                onPick(ExpressionPick(type: "sticker", extra: ["sticker_id": s.id, "source": "pack", "url": s.url, "emoji": s.emoji]))
                            }) { StickerImage(url: s.url) }
                            .aspectRatio(1, contentMode: .fit).padding(3)
                        }
                    }
                    .padding(.horizontal, 8).padding(.vertical, 4)
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

/// «Create sticker» — the first cell, as in WhatsApp (in the app's red).
private struct MakeStickerCell: View {
    @ObservedObject private var ch = Ch.shared
    let action: () -> Void

    var body: some View {
        PopCell(action: action) {
            VStack(spacing: 3) {
                Image(systemName: "pencil").font(.system(size: 13, weight: .bold)).foregroundStyle(.white)
                    .frame(width: 28, height: 28).background(ch.red, in: Circle())
                Text(L("ch_sticker_make_short")).font(.dorr(11, .heavy)).foregroundStyle(ch.red).lineLimit(1)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(ch.red.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(ch.line, lineWidth: 1))
        }
        .aspectRatio(1, contentMode: .fit).padding(3)
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
            .frame(width: 40, height: 40)
            .background(selected ? ch.red.opacity(0.12) : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            .animation(.easeOut(duration: 0.2), value: selected)
        }
        .buttonStyle(.plain)
    }
}

// MARK: - GIFs

private struct GifTab: View {
    let library: StickerLibraryDto?
    let searching: Bool
    let onPick: (ExpressionPick) -> Void

    var body: some View {
        // The GIF library is off until the server has a Giphy key — say so instead of «no results».
        if let library, library.libraryEnabled != true {
            EmptyHint(text: L("ch_expr_gifs_off"))
        } else {
            GiphyGrid(kind: "gifs", columns: 3, moods: true, showSearch: searching) { g in
                Recents.add(RecentItem(kind: "gif", source: "giphy", id: g.id, url: g.webp ?? g.url, width: g.width ?? 0, height: g.height ?? 0))
                onPick(ExpressionPick(type: "gif", extra: [
                    "giphy_id": g.id, "source": "giphy", "url": g.url, "webp": g.webp, "width": g.width, "height": g.height, "title": g.title,
                ]))
            }
        }
    }
}

// Giphy results: a search field (+ mood chips for GIFs), then a staggered grid that loads more as it nears the end.
/// Trending when the search is empty.
private struct GiphyGrid: View {
    @ObservedObject private var ch = Ch.shared
    let kind: String
    let columns: Int
    var moods = false
    /// The field shows only while searching (the bar's 🔍).
    var showSearch = false
    let onPick: (GifDto) -> Void
    @State private var query = ""
    @State private var items: [GifDto] = []
    @State private var next: Int? = 0
    @State private var loading = false

    private static let moodList = [("😂", "ch_mood_haha"), ("❤️", "ch_mood_love"), ("👏", "ch_mood_congrats"), ("👋", "ch_mood_hi"),
                                   ("🎉", "ch_mood_party"), ("😢", "ch_mood_sad"), ("😴", "ch_mood_sleepy"), ("🤔", "ch_mood_thinking")]

    var body: some View {
        VStack(spacing: 0) {
            if showSearch {
                ChField(placeholder: L(kind == "gifs" ? "ch_expr_search_gifs" : "ch_expr_search_stickers"), text: $query, icon: "magnifyingglass", limit: 50, clearable: true, autofocus: true)
                    .padding(.horizontal, 14).padding(.vertical, 4)
            }
            // Quick moods (Dorr's own, not in WhatsApp) — one slim row.
            if moods {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 6) {
                        ForEach(Array(Self.moodList.enumerated()), id: \.offset) { i, m in
                            let word = L(m.1)
                            let on = query == word
                            Button { query = on ? "" : word } label: {
                                HStack(spacing: 4) {
                                    Text(verbatim: m.0).font(.system(size: 13))
                                    Text(verbatim: word).font(.dorr(12, .bold)).foregroundStyle(on ? .white : ch.ink)
                                }
                                .padding(.horizontal, 10).padding(.vertical, 5)
                                .background(on ? ch.red : ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                            }
                            .buttonStyle(.plain)
                            .chStagger(i)
                        }
                    }
                    .padding(.horizontal, 12).padding(.vertical, 3)
                }
            }
            if items.isEmpty && !loading {
                EmptyHint(text: L("ch_expr_nothing"))
                Spacer()
            } else {
                ScrollView {
                    HStack(alignment: .top, spacing: 4) {
                        ForEach(0..<columns, id: \.self) { col in
                            LazyVStack(spacing: 4) {
                                ForEach(column(col), id: \.element.id) { i, g in cell(g).onAppear { if i >= items.count - 6 { Task { await loadMore() } } } }
                            }
                        }
                    }
                    .padding(8)
                }
            }
            // «Powered by GIPHY» — required by Giphy's terms.
            Text(L("ch_expr_powered")).font(.dorr(10)).foregroundStyle(ch.soft).padding(.bottom, 4)
        }
        // Closing the search (the bar's 🔍) goes back to trending.
        .onChange(of: showSearch) { on in if !on, !query.isEmpty { query = "" } }
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
