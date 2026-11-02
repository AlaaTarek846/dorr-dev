import SwiftUI

// androidApp `ui/screens/chat/ChatMediaPage.kt` (+ `ChatPartialUi.kt` MediaFilterRow) — everything shared in a chat,
// like WhatsApp's «Media, links and docs»: photos & videos in a grid by month, files, links with their card, voice
// notes and audio. Pages in as you scroll. A tap opens the thing itself; a long press (or the bubble icon) shows it in
// the chat. Photos can start from a day; files filter by kind, big ones only, or by size.

private struct MediaTab: Hashable {
    let key: String
    let kind: String
    let label: String
    let icon: String
}

private let mediaTabs = [
    MediaTab(key: "media", kind: "media", label: "ch_media", icon: "photo.on.rectangle"),
    MediaTab(key: "docs", kind: "documents", label: "ch_docs", icon: "doc.text.fill"),
    MediaTab(key: "links", kind: "links", label: "ch_links", icon: "link"),
    MediaTab(key: "audio", kind: "audio", label: "ch_audio", icon: "waveform"),
]

/// A tab's pages so far.
private struct MediaFeed {
    var items: [MessageDto] = []
    var hasMore = true
    var loading = false
    var loaded = false
}

struct ChatMediaPage: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let id: String
    let startTab: String
    @State private var tab = mediaTabs[0]
    @State private var feeds: [String: MediaFeed] = [:]
    @State private var viewer: (MessageDto, Int)?
    @State private var date: Date?
    @State private var picking = false
    @State private var fileKind: String?
    @State private var bigOnly = false
    @State private var bySize = false

    var body: some View {
        ZStack {
            ChSettingsPage(title: L("ch_media_title"), onBack: { host.pop() }) {
                VStack(spacing: 0) {
                    tabsRow
                    if tab.key == "media" || tab.key == "docs" { filterRow }
                    content(tab).frame(maxHeight: .infinity).id(tab.key).transition(.opacity)
                }
                .animation(.easeInOut(duration: 0.2), value: tab)
            }
            if let (m, i) = viewer {
                MediaViewer(message: m, start: i) { withAnimation { viewer = nil } }.transition(.opacity)
            }
        }
        .onAppear { if let t = mediaTabs.first(where: { $0.key == startTab }) { tab = t } }
        .task(id: tab) { if feeds[tab.key]?.loaded != true { await loadMore(tab) } }
        .sheet(isPresented: $picking) {
            VStack {
                DatePicker("", selection: Binding(get: { date ?? Date() }, set: { date = $0 }), in: ...Date(), displayedComponents: .date)
                    .datePickerStyle(.graphical).tint(ch.red).labelsHidden()
                ChPrimaryButton(text: L("ch_ok")) {
                    if date == nil { date = Date() }
                    picking = false
                    refilter()
                }
            }
            .padding(20)
            .presentationDetents([.medium])
        }
    }

    private func feed(_ t: MediaTab) -> MediaFeed { feeds[t.key] ?? MediaFeed() }

    private func loadMore(_ t: MediaTab) async {
        var f = feed(t)
        guard !f.loading, f.hasMore else { return }
        f.loading = true
        feeds[t.key] = f
        let files = t.key == "docs"
        let day = date.map { d -> String in
            let fmt = DateFormatter()
            fmt.dateFormat = "yyyy-MM-dd"
            fmt.locale = Locale(identifier: "en_US_POSIX")
            return fmt.string(from: d)
        }
        let page = try? await ChatApi.gallery(id, kind: t.kind, before: f.items.last?.id, date: t.key == "media" ? day : nil,
                                              fileKind: files ? fileKind : nil, minSize: files && bigOnly ? 10 * 1024 * 1024 : nil, sort: files && bySize ? "size" : nil).data
        f = feed(t)
        if let page {
            let fresh = (page.messages ?? []).filter { m in !f.items.contains { $0.id == m.id } }
            f.items += fresh
            f.hasMore = page.hasMore == true
        } else {
            f.hasMore = false
        }
        f.loaded = true
        f.loading = false
        feeds[t.key] = f
    }

    private func refilter() {
        feeds[tab.key] = nil
        Task { await loadMore(tab) }
    }

    private func showInChat(_ m: MessageDto) { host.showInChat(id, m.id) }

    // MARK: Tabs & filters

    private var tabsRow: some View {
        HStack(spacing: 4) {
            ForEach(mediaTabs, id: \.self) { t in
                let active = t == tab
                let count = feeds[t.key].flatMap { $0.loaded ? $0.items.count : nil } ?? 0
                Button { tab = t } label: {
                    VStack(spacing: 2) {
                        Image(systemName: t.icon).font(.system(size: 16))
                        Text(verbatim: L(t.label) + (count > 0 ? " · \(count)" : "")).font(.dorr(11.5, .bold)).lineLimit(1)
                    }
                    .foregroundStyle(active ? .white : ch.mut)
                    .frame(maxWidth: .infinity).padding(.vertical, 8)
                    .background(active ? ch.red : .clear, in: RoundedRectangle(cornerRadius: 14))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(5)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 18))
        .padding(.horizontal, 14).padding(.vertical, 10)
        .animation(.spring(response: 0.3, dampingFraction: 0.8), value: tab)
    }

    private var filterRow: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 6) {
                if tab.key == "media" {
                    chip(date.map { L("cp_until", scheduleDay($0)) } ?? L("cp_pick_date"), date != nil, icon: "calendar") {
                        if date != nil { date = nil; refilter() } else { picking = true }
                    }
                } else {
                    ForEach([("pdf", "PDF"), ("word", "Word"), ("excel", "Excel"), ("slides", "Slides"), ("archive", "ZIP")], id: \.0) { k, l in
                        chip(l, fileKind == k) { fileKind = fileKind == k ? nil : k; refilter() }
                    }
                    chip(L("cp_other"), fileKind == "other") { fileKind = fileKind == "other" ? nil : "other"; refilter() }
                    chip(L("cp_big_files"), bigOnly) { bigOnly.toggle(); refilter() }
                    chip(L("cp_by_size"), bySize) { bySize.toggle(); refilter() }
                }
            }
            .padding(.horizontal, 12).padding(.vertical, 6)
        }
    }

    private func scheduleDay(_ d: Date) -> String {
        let f = DateFormatter()
        f.locale = AppLocale.locale
        f.dateStyle = .medium
        return f.string(from: d)
    }

    private func chip(_ label: String, _ on: Bool, icon: String? = nil, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 5) {
                if let icon { Image(systemName: icon).font(.system(size: 13)).foregroundStyle(on ? .white : ch.red) }
                Text(verbatim: label).font(.dorr(12.5, .bold)).foregroundStyle(on ? .white : ch.ink)
                if on && icon != nil { Image(systemName: "xmark").font(.system(size: 11, weight: .bold)).foregroundStyle(.white) }
            }
            .padding(.horizontal, 12).padding(.vertical, 7)
            .background(on ? ch.red : ch.surface, in: Capsule())
            .overlay(Capsule().stroke(on ? .clear : ch.line, lineWidth: 1))
        }
        .buttonStyle(.plain)
    }

    // MARK: Content

    @ViewBuilder
    private func content(_ t: MediaTab) -> some View {
        let f = feed(t)
        if !f.loaded {
            ProgressView().tint(ch.red).frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if f.items.isEmpty {
            ChEmptyState(icon: t.icon, title: L(t.label), text: L("ch_no_media"), animated: true)
        } else if t.key == "media" {
            grid(f)
        } else {
            list(t, f)
        }
    }

    /// Photos & videos, three a row, under a header per month.
    private func grid(_ f: MediaFeed) -> some View {
        var months: [(String, [(MessageDto, Int, AttachmentDto)])] = []
        for m in f.items {
            let label = monthLabel(m.createdAt)
            let tiles = m.files.enumerated().map { (m, $0.offset, $0.element) }
            if months.last?.0 == label { months[months.count - 1].1 += tiles } else { months.append((label, tiles)) }
        }
        return ScrollView {
            LazyVStack(alignment: .leading, spacing: 4) {
                ForEach(Array(months.enumerated()), id: \.offset) { _, month in
                    Text(verbatim: month.0).font(.dorr(13, .heavy)).foregroundStyle(ch.mut).padding(.leading, 6).padding(.top, 12).padding(.bottom, 4)
                    LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 4), count: 3), spacing: 4) {
                        ForEach(Array(month.1.enumerated()), id: \.offset) { index, tile in
                            gridTile(tile.0, tile.2, index)
                        }
                    }
                }
                if f.loading { ProgressView().tint(ch.red).frame(maxWidth: .infinity).padding(16) }
                Color.clear.frame(height: 1).onAppear { Task { await loadMore(tab) } }
            }
            .padding(.horizontal, 10).padding(.bottom, 30)
        }
    }

    private func gridTile(_ m: MessageDto, _ a: AttachmentDto, _ index: Int) -> some View {
        let video = m.type == "video" || a.mimeType?.hasPrefix("video/") == true
        return ZStack(alignment: .bottomLeading) {
            ch.surfaceMuted
            ChatImage(url: ApiClient.mediaUrl(video ? a.thumbnail ?? a.url : a.url))
            if video {
                LinearGradient(colors: [.clear, .black.opacity(0.55)], startPoint: .top, endPoint: .bottom)
                HStack(spacing: 2) {
                    Image(systemName: "play.fill").font(.system(size: 12))
                    if let d = a.durationMs { Text(verbatim: durationText(d)).font(.dorr(11, .bold)) }
                }
                .foregroundStyle(.white).padding(6)
            }
        }
        .aspectRatio(1, contentMode: .fill)
        .frame(minWidth: 0, maxWidth: .infinity)
        .clipped()
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .contentShape(Rectangle())
        .onTapGesture {
            if video, let url = ApiClient.mediaUrl(a.url) {
                presentVideo(url)
            } else {
                // The viewer pages through this message's photos.
                let photos = m.files.filter { $0.mimeType?.hasPrefix("image/") ?? true }
                withAnimation { viewer = (m, max(photos.firstIndex(of: a) ?? 0, 0)) }
            }
        }
        .onLongPressGesture {
            UIImpactFeedbackGenerator(style: .medium).impactOccurred()
            showInChat(m)
        }
        .chStagger(index % 12)
    }

    /// Files, links and voice notes: one row each, newest first.
    private func list(_ t: MediaTab, _ f: MediaFeed) -> some View {
        ScrollView {
            LazyVStack(spacing: 8) {
                ForEach(Array(f.items.enumerated()), id: \.element.id) { i, m in
                    listRow(t, m, i)
                        .onAppear { if i >= f.items.count - 4 { Task { await loadMore(t) } } }
                }
                if f.loading { ProgressView().tint(ch.red).padding(16) }
            }
            .padding(.horizontal, 14).padding(.bottom, 30)
        }
    }

    private func listRow(_ t: MediaTab, _ m: MessageDto, _ i: Int) -> some View {
        let file = m.files.first
        let link: String? = t.key == "links" ? m.linkPreview?.url ?? firstLink(m.body ?? "") : nil
        let image = t.key == "links" ? m.linkPreview?.image : nil
        let title: String = t.key == "docs" ? file?.name ?? L("ch_docs") : t.key == "links" ? (m.linkPreview?.title.flatMap { $0.isEmpty ? nil : $0 } ?? link ?? "") : m.sender?.name ?? L("ch_you")
        let details: [String] = t.key == "docs" ? [file?.size.map { fileSizeText($0) }, listTime(m.createdAt)].compactMap { $0 }
            : t.key == "links" ? [link.flatMap { URL(string: $0.hasPrefix("http") ? $0 : "https://\($0)")?.host?.replacingOccurrences(of: "www.", with: "") }, listTime(m.createdAt)].compactMap { $0 }
            : [file?.durationMs.map { durationText($0) }, listTime(m.createdAt)].compactMap { $0 }
        return HStack(spacing: 12) {
            // The link's picture when it has one, else a tinted icon.
            ZStack {
                ch.tint
                if let image {
                    ChatImage(url: ApiClient.mediaUrl(image))
                } else if t.key == "docs" {
                    let ext = String(((file?.name ?? "") as NSString).pathExtension.prefix(4)).uppercased()
                    Text(verbatim: ext.isEmpty ? "DOC" : ext).font(.dorr(12, .black)).foregroundStyle(ch.red)
                } else {
                    Image(systemName: t.key == "audio" ? "mic.fill" : "link").font(.system(size: 20)).foregroundStyle(ch.red)
                }
            }
            .frame(width: 48, height: 48).clipShape(RoundedRectangle(cornerRadius: 14))
            VStack(alignment: .leading, spacing: 1) {
                Text(verbatim: title).font(.dorr(14.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                Text(verbatim: details.joined(separator: "  ·  ")).font(.dorr(12.5)).foregroundStyle(ch.mut).lineLimit(1)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            // Straight to where it was sent.
            Button { showInChat(m) } label: {
                Image(systemName: "bubble.left.and.bubble.right.fill").font(.system(size: 16)).foregroundStyle(ch.soft).frame(width: 38, height: 38)
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("ch_show_in_chat"))
        }
        .padding(10)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 18))
        .contentShape(Rectangle())
        .onTapGesture {
            switch t.key {
            case "docs":
                if let file {
                    if ChatViewers.isPdf(file.name, file.mimeType) { ChatViewers.shared.pdf = PdfTarget(url: file.url, name: file.name ?? "") }
                    else if let url = ApiClient.mediaUrl(file.url) { UIApplication.shared.open(url) }
                }
            case "links": if let link, let url = URL(string: link.hasPrefix("http") ? link : "https://\(link)") { UIApplication.shared.open(url) }
            // Voice notes play in the chat itself.
            default: showInChat(m)
            }
        }
        .onLongPressGesture {
            UIImpactFeedbackGenerator(style: .medium).impactOccurred()
            showInChat(m)
        }
        .chStagger(i % 12)
    }

    private func firstLink(_ text: String) -> String? {
        text.range(of: #"(https?://\S+|www\.\S+)"#, options: .regularExpression).map { String(text[$0]) }
    }

    /// «September 2026», in the app's language.
    private func monthLabel(_ iso: String?) -> String {
        guard let d = parseRideTime(iso) else { return "" }
        let f = DateFormatter()
        f.locale = AppLocale.locale
        f.dateFormat = "MMMM yyyy"
        return f.string(from: d)
    }
}
