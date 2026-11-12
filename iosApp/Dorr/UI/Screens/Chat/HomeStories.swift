import PhotosUI
import SwiftUI

// androidApp `chat/HomeStories.kt` — public stories on the home page, drawn with the chat's own stories pieces: a small
// ChatHost of its own (in public mode) runs the same story viewer and composer, so a story looks and behaves the same
// everywhere — posting from here makes it public.

@MainActor
final class HomeStories: ObservableObject {
    static let shared = HomeStories()
    private static let page = 15

    let host: ChatHost
    @Published var feed: PublicStoryFeedDto?
    @Published var adding = false
    @Published var loadingMore = false
    private var page = 1

    private init() {
        host = ChatHost(onExit: {}, openWalletQr: { _ in })
        host.publicMode = true
        host.onRefreshStories = { [weak self] in await self?.refresh() }
    }

    /// The first 15 people (and mine / Dorr's). Pages already scrolled in stay, after the fresh first 15.
    func refresh() async {
        guard let fresh = try? await ChatApi.publicStories(page: 1, perPage: Self.page).data else { return }
        let keys = Set((fresh.people ?? []).map { $0.owner?.key })
        let kept = Array((feed?.people ?? []).dropFirst(Self.page)).filter { !keys.contains($0.owner?.key) }
        var merged = fresh
        merged.people = (fresh.people ?? []) + kept
        merged.hasMore = kept.isEmpty ? fresh.hasMore : feed?.hasMore ?? fresh.hasMore
        feed = merged
        page = ((merged.people ?? []).count + Self.page - 1) / Self.page
    }

    /// The next 15, when the row is scrolled to its end.
    func loadMore() async {
        guard let current = feed, !loadingMore, current.hasMore == true else { return }
        loadingMore = true
        defer { loadingMore = false }
        guard let next = try? await ChatApi.publicStories(page: page + 1, perPage: Self.page).data else { return }
        let known = Set((current.people ?? []).map { $0.owner?.key })
        var merged = current
        merged.people = (current.people ?? []) + (next.people ?? []).filter { !known.contains($0.owner?.key) }
        merged.hasMore = next.hasMore
        feed = merged
        page += 1
    }

    /// Everything the circles open, in their order — so the viewer pages from one to the next.
    private var all: [StoryGroupDto] { [feed?.mine, feed?.dorr].compactMap { $0 } + (feed?.people ?? []) }

    func open(_ group: StoryGroupDto) {
        let list = all
        host.openStories(list, start: list.firstIndex(of: group) ?? 0)
    }

    /// «View all»: plays everything from the first circle on.
    func openAll() { host.openStories(all, start: 0) }
}

/// The full-screen part, over the main screen: the story viewer, the composer while writing one, the «add» sheet and
/// the chat's toast.
struct HomeStoriesLayer: View {
    @ObservedObject private var state = HomeStories.shared
    @ObservedObject private var host = HomeStories.shared.host

    var body: some View {
        ZStack {
            if case .storyComposer(let media) = host.current {
                StoryComposerPage(host: host, media: media).transition(.move(edge: .bottom))
            } else if case .storyPrivacy = host.current {
                StoryPrivacyPage(host: host)
            }
            StoryViewerOverlay(host: host)
            ChatToastView(host: host)
        }
        .animation(.spring(response: 0.4, dampingFraction: 0.85), value: host.current)
        .chatAppearance()
        .environmentObject(host)
        .dorrDialog(isPresented: $state.adding, bottom: true) {
            StoryAddSheet(host: host) { state.adding = false }.chatAppearance().environmentObject(host)
        }
    }
}

// MARK: - the circles

/// «Me»: my public story (tap to watch, long-press to add another), or a «+» to post one.
struct HomeMyCircle: View {
    @ObservedObject private var state = HomeStories.shared
    @ObservedObject private var host = HomeStories.shared.host
    @Environment(\.wa) private var wa
    @State private var plus: CGFloat = 0

    var body: some View {
        let mine = state.feed?.mine
        let count = mine?.stories.count ?? 0
        let quota = state.feed?.quota
        let full = quota.map { $0.used >= $0.free } ?? false
        let me = AuthSession.shared.user
        VStack(spacing: 4) {
            ZStack(alignment: .bottomTrailing) {
                ChStoryRing(count: count, seenCount: 0, size: 56, uploading: host.storyUploading) {
                    if let latest = mine?.stories.last { StoryThumb(story: latest, size: 48) } else { ChAvatar(url: me?.avatar, name: me?.name, key: myKey(), size: 48) }
                }
                if !full {
                    Button { add(full: full, quota: quota) } label: {
                        Image(systemName: "plus").font(.system(size: 11, weight: .black)).foregroundStyle(.white)
                            .frame(width: 20, height: 20).background(Ch.shared.headerBrush, in: Circle()).overlay(Circle().stroke(.white, lineWidth: 2))
                    }
                    .buttonStyle(.plain)
                    .scaleEffect(plus)
                }
            }
            .frame(width: 56, height: 56)
            CircleLabel(text: L("st_my_public"), bold: true)
        }
        .frame(width: 58)
        .contentShape(Rectangle())
        .onTapGesture { if let mine { state.open(mine) } else { add(full: full, quota: quota) } }
        .onLongPressGesture { add(full: full, quota: quota) }
        .task {
            try? await Task.sleep(nanoseconds: 250_000_000)
            withAnimation(.spring(response: 0.4, dampingFraction: 0.35)) { plus = 1 }
        }
    }

    private func add(full: Bool, quota: StoryQuotaDto?) {
        if full { host.showToast(L("st_public_full", quota?.free ?? 1)) } else { state.adding = true }
    }
}

/// Dorr's own stories — a fixed circle with the logo.
struct HomeDorrCircle: View {
    let group: StoryGroupDto

    var body: some View {
        Button { HomeStories.shared.open(group) } label: {
            VStack(spacing: 4) {
                ChStoryRing(count: group.stories.count, seenCount: group.stories.filter { $0.seen == true }.count, size: 56) { DorrAvatar(size: 48) }
                CircleLabel(text: L("st_dorr"), bold: group.allSeen != true)
            }
            .frame(width: 58)
        }
        .buttonStyle(.plain)
    }
}

/// Someone's public stories — their name and photo, never their number.
struct HomePersonCircle: View {
    let group: StoryGroupDto

    var body: some View {
        Button { HomeStories.shared.open(group) } label: {
            VStack(spacing: 4) {
                ChStoryRing(count: group.stories.count, seenCount: group.stories.filter { $0.seen == true }.count, size: 56) {
                    ChAvatar(url: group.owner?.avatar, name: group.owner?.name, key: group.owner?.key, size: 48)
                }
                CircleLabel(text: ltrTitle(group.owner?.name), bold: group.allSeen != true)
            }
            .frame(width: 58)
        }
        .buttonStyle(.plain)
    }
}

private struct CircleLabel: View {
    @Environment(\.wa) private var wa
    let text: String
    let bold: Bool

    var body: some View {
        Text(verbatim: text).font(.dorr(11, bold ? .bold : .regular)).foregroundStyle(wa.ink.opacity(bold ? 1 : 0.65))
            .lineLimit(1).multilineTextAlignment(.center)
    }
}
