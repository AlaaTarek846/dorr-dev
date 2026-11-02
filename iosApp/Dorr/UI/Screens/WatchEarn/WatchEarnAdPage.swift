import AVKit
import SwiftUI

/// androidApp `ui/screens/watchearn/WatchEarnAdPage.kt` — one ad for a viewer who already saw it (opened from a push,
/// e.g. the answer to a report on their comment): `GET ads/{id}` — the image or video (no timer, no view session), the
/// title, the text and the link, then the reactions and the comments, live through the same channel as the comments
/// sheet ([WeComments]). Reacting and commenting work; they earn the interaction reward only while `can_interact` (the
/// server decides).
@MainActor
struct WatchEarnAdPage: View {
    @EnvironmentObject private var host: WeHost
    @Environment(\.wa) private var wa
    let adId: String
    @State private var page: WeAdPageDto?
    @State private var failed = false
    @State private var reload = 0
    @State private var interacted = false
    @State private var now = Date()
    @State private var reportOpen = false

    var body: some View {
        WaPage(title: L("we_ad_page_title"), onBack: { host.pop() }, scroll: false) {
            if let p = page {
                AdPageBody(
                    page: p,
                    windowOpen: windowOpen,
                    leftMs: leftMs,
                    onInteracted: { claimInteraction() },
                    onReportAd: { reportOpen = true }
                )
            } else if failed {
                WaEmpty(icon: "exclamationmark.triangle.fill", tone: .red, title: L("we_load_failed"), text: L("we_load_failed_hint")) {
                    WaButton(text: L("we_retry")) { reload += 1 }.padding(.horizontal, 40)
                }
            } else {
                VStack(spacing: 12) {
                    WaSkeleton(cornerRadius: 22).frame(height: 320)
                    WaSkeleton().frame(height: 22)
                    WaSkeleton().frame(height: 60)
                }
            }
        }
        .task(id: reload) { await load() }
        // The interaction window's countdown runs on the device clock — no server call.
        .task(id: deadline) { await tickWindow() }
        .weSheet(reportOpen, onDismiss: { reportOpen = false }) {
            WeReportAdSheet(adId: adId) { message in
                reportOpen = false
                host.showToast(message)
            }
        }
    }

    /// When interacting stops earning (only while the server says `can_interact`).
    private var deadline: Date? {
        guard let p = page, p.canInteract else { return nil }
        return weInstant(p.interactionDeadlineAt)
    }

    private var windowOpen: Bool {
        guard let until = deadline else { return false }
        return now < until && !interacted
    }

    private var leftMs: Int {
        guard let until = deadline else { return 0 }
        return Int(until.timeIntervalSince(now) * 1000)
    }

    private func load() async {
        do {
            if let fresh = try await WatchEarnApi.feedAd(adId).data {
                page = fresh
                failed = false
            } else if page == nil {
                failed = true
            }
        } catch {
            if page == nil { failed = true }
            host.showToast(error.weMessage(L("we_error_generic")), error: true)
        }
    }

    private func tickWindow() async {
        guard let until = deadline else { return }
        while !interacted && Date() < until {
            now = Date()
            try? await Task.sleep(nanoseconds: 1_000_000_000)
            if Task.isCancelled { return }
        }
        now = Date()
    }

    /// The first comment or reaction inside the window: the server pays it; the page says so once.
    private func claimInteraction() {
        guard let p = page, p.canInteract, !interacted, let until = weInstant(p.interactionDeadlineAt), Date() < until else { return }
        interacted = true
        if p.ad.earn.interactionMinor > 0 {
            host.showToast("+ " + weMoney(p.ad.earn.interactionMinor, p.ad.currency) + " · " + L("we_interaction_reward"))
        }
    }
}

/// The loaded page: one scroll (the ad, then its comments) and the composer under it.
@MainActor
private struct AdPageBody: View {
    @EnvironmentObject private var host: WeHost
    @Environment(\.wa) private var wa
    let page: WeAdPageDto
    let windowOpen: Bool
    let leftMs: Int
    let onInteracted: () -> Void
    let onReportAd: () -> Void
    @StateObject private var c: WeComments

    init(page: WeAdPageDto, windowOpen: Bool, leftMs: Int, onInteracted: @escaping () -> Void, onReportAd: @escaping () -> Void) {
        self.page = page
        self.windowOpen = windowOpen
        self.leftMs = leftMs
        self.onInteracted = onInteracted
        self.onReportAd = onReportAd
        _c = StateObject(wrappedValue: WeComments(adId: page.ad.id))
    }

    var body: some View {
        let ad = page.ad
        ScrollViewReader { proxy in
            VStack(spacing: 0) {
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) {
                        AdPageMedia(ad: ad)
                        AdPageText(ad: ad)
                        if windowOpen && ad.earn.interactionMinor > 0 {
                            AdPageRewardWindow(ad: ad, leftMs: leftMs).padding(.top, 12)
                        } else if !page.canInteract {
                            WaNote(text: L("we_ad_page_no_reward")).padding(.top, 12)
                        }
                        Text(L("we_comments")).font(.dorr(16, .heavy)).foregroundStyle(wa.ink)
                            .padding(.top, 18)
                            .id("comments")
                        WeReactionCounts(c: c).padding(.top, 10).padding(.bottom, 6)
                        WeCommentRows(c: c, onReportAd: onReportAd)
                    }
                    .padding(.bottom, 12)
                }
                .scrollDismissesKeyboard(.interactively)
                WeCommentComposer(c: c) { withAnimation { proxy.scrollTo("comments", anchor: .top) } }
                    .padding(.top, 8)
                    .padding(.bottom, 12)
            }
        }
        .onAppear { c.onInteracted = onInteracted }
        .weCommentsLive(c, host: host)
    }
}

/// The image (whole, on black) or the video, at a phone-friendly height.
private struct AdPageMedia: View {
    let ad: WeFeedAdDto

    var body: some View {
        ZStack {
            Color.black
            if ad.media.type == "video", let url = ApiClient.mediaUrl(ad.media.url) {
                AdPageVideo(url: url)
            } else {
                WeRemoteImage(url: ad.media.url, fit: true)
            }
        }
        .frame(maxWidth: .infinity)
        .frame(height: 380)
        .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
    }
}

/// The ad's video on its page: no timer and no session. The system player waits for a tap, plays once, and starts
/// muted (its own control turns the sound on) — Android draws the same play and 🔈 buttons itself.
private struct AdPageVideo: View {
    let url: URL
    @State private var player: AVPlayer?

    var body: some View {
        ZStack {
            if let player {
                VideoPlayer(player: player)
            } else {
                Color.black
            }
        }
        .onAppear {
            guard player == nil else { return }
            let p = AVPlayer(url: url)
            p.isMuted = true
            player = p
        }
        .onDisappear { player?.pause() }
    }
}

/// The advertiser, the title, the text and the link.
private struct AdPageText: View {
    @Environment(\.wa) private var wa
    @Environment(\.openURL) private var openURL
    let ad: WeFeedAdDto

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 8) {
                Text(verbatim: ad.advertiser?.name ?? "").font(.dorr(12.5, .bold)).foregroundStyle(wa.mut).lineLimit(1)
                Text(L("we_sponsored")).font(.dorr(11.5)).foregroundStyle(wa.soft)
            }
            Text(verbatim: ad.title).font(.dorr(18, .heavy)).foregroundStyle(wa.ink).padding(.top, 4)
            if let text = ad.body, !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                Text(verbatim: text).font(.dorr(14)).foregroundStyle(wa.ink).lineSpacing(5)
                    .fixedSize(horizontal: false, vertical: true)
                    .padding(.top, 6)
            }
            if let link = ad.linkUrl, !link.isEmpty, let url = URL(string: link) {
                WaButton(text: L("we_open_link"), style: .ghost, icon: "arrow.up.forward.square", iconAfter: true, compact: true) { openURL(url) }
                    .frame(width: 170)
                    .padding(.top, 12)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.top, 14)
    }
}

/// While the interaction reward is still open: what it pays and how long is left (device clock).
private struct AdPageRewardWindow: View {
    let ad: WeFeedAdDto
    let leftMs: Int

    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: "timer").font(.system(size: 13, weight: .semibold))
            Text(L("we_interaction_window", weMoney(ad.earn.interactionMinor, ad.currency))).font(.dorr(12, .bold))
            Text(verbatim: weCountdown(leftMs)).font(.dorr(12.5, .heavy)).weLtr()
        }
        .foregroundStyle(We.interaction)
        .padding(.horizontal, 12)
        .padding(.vertical, 7)
        .background(We.interaction.opacity(0.14), in: Capsule())
    }
}
