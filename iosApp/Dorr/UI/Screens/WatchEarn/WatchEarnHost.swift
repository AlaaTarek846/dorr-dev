import SwiftUI

/// androidApp `ui/screens/watchearn/WatchEarnHost.kt` — the pages of the Watch & Earn section
/// (docs/modules/watch-earn/APP.md), the link that opens it from anywhere, its phone-only preferences, and the page
/// stack with its toast.
enum WeRoute {
    case home
    /// The player over the feed loaded on the landing page.
    case player(WeFeedDto)
    case rewards
    /// Create an ad, or edit one (`form.edit`). The form rides in the route so a trip to fund the ad wallet keeps it.
    case wizard(AdForm)
    case myAds(status: String? = nil)
    case stats(adId: String)
    /// The ad wallet; [fundMinor] opens the fund sheet with that amount (from the wizard or a budget top-up) and comes back.
    case adWallet(fundMinor: Int? = nil)
    /// One ad for a viewer who already saw it (from a push): the media, the text and the live comments.
    case adPage(adId: String)

    var isHome: Bool { if case .home = self { return true } else { return false } }
    var isPlayer: Bool { if case .player = self { return true } else { return false } }
}

/// Opens the section from anywhere (wallet card, chat list card, Home banner, Account row, a tapped push).
/// [open] drives the overlay in MainScreen; [pending] is a page to go to once the section shows.
@MainActor
final class WatchEarnLink: ObservableObject {
    static let shared = WatchEarnLink()

    @Published private(set) var open = false
    @Published var pending: WeRoute?
    /// Last thing the feed said about this country: nil = not known yet; false hides the entry cards.
    @Published var knownEnabled: Bool?

    func show(_ route: WeRoute? = nil) {
        pending = route
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42)) { open = true }
    }

    func close() {
        open = false
        pending = nil
    }

    /// A tapped Watch & Earn push: `watch_earn.ad.*` → that ad's stats; `watch_earn.rewards` → my rewards; a comment
    /// report's answer, by [role]: `reporter` (the advertiser) → the ad's stats, `author` (who wrote the comment) → the
    /// ad's page with its comments. Older pushes without a role fall back to the event's suffix.
    func openFromPush(_ event: String, _ adId: String?, role: String? = nil) {
        let reportRole = role ?? (event.hasSuffix("_reporter") ? "reporter" : event.hasSuffix("_author") ? "author" : nil)
        let route: WeRoute
        if event.hasPrefix("watch_earn.ad."), let adId {
            route = .stats(adId: adId)
        } else if event == "watch_earn.rewards" {
            route = .rewards
        } else if event.hasPrefix("watch_earn.comment_report_"), reportRole == "reporter", let adId {
            route = .stats(adId: adId)
        } else if event.hasPrefix("watch_earn.comment_report_"), reportRole == "author", let adId {
            route = .adPage(adId: adId)
        } else {
            route = .home
        }
        show(route)
    }
}

/// Phone-only preferences: the slim chat-list card can be hidden, and turned back on from the chat settings.
/// Android keeps it in the `dorr_watch_earn` preferences under `chat_card_hidden`.
@MainActor
final class WatchEarnPrefs: ObservableObject {
    static let shared = WatchEarnPrefs()
    private static let key = "dorr_watch_earn.chat_card_hidden"

    @Published private(set) var chatCardHidden = UserDefaults.standard.bool(forKey: key)

    func setChatCardHidden(_ hidden: Bool) {
        withAnimation(.easeOut(duration: 0.25)) { chatCardHidden = hidden }
        UserDefaults.standard.set(hidden, forKey: Self.key)
    }
}

struct WeToast: Equatable {
    let text: String
    let error: Bool
    let id = UUID()
}

/// The section's page stack and its toast — one per visit. Also the confirm dialog and the date picker, which show
/// over every page.
@MainActor
final class WeHost: ObservableObject {
    @Published private(set) var stack: [WeRoute] = [.home]
    /// Bumped on every move, so the page on top is a new view (and slides in).
    @Published private(set) var pageId = 0
    @Published private(set) var forward = true
    @Published private(set) var toast: WeToast?
    @Published var dialog: WeDialogSpec?
    @Published var datePicker: WeDatePickerSpec?
    var onExit: () -> Void
    private var toastTask: Task<Void, Never>?

    init(onExit: @escaping () -> Void) {
        self.onExit = onExit
    }

    var current: WeRoute { stack[stack.count - 1] }

    func push(_ route: WeRoute) {
        forward = true
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32)) {
            stack.append(route)
            pageId += 1
        }
    }

    /// Swaps the page on top (the wizard → my ads after sending, the player → my rewards).
    func replace(_ route: WeRoute) {
        forward = true
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32)) {
            stack[stack.count - 1] = route
            pageId += 1
        }
    }

    func pop() {
        if stack.count <= 1 {
            onExit()
            return
        }
        forward = false
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.28)) {
            _ = stack.removeLast()
            pageId += 1
        }
    }

    /// A page asked for from outside (a push): on top of the landing page.
    func open(_ route: WeRoute) {
        forward = true
        var next: [WeRoute] = [.home]
        if !route.isHome { next.append(route) }
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32)) {
            stack = next
            pageId += 1
        }
    }

    func showToast(_ text: String, error: Bool = false) {
        guard !text.trimmingCharacters(in: .whitespaces).isEmpty else { return }
        withAnimation(.easeOut(duration: 0.26)) { toast = WeToast(text: text, error: error) }
        toastTask?.cancel()
        toastTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: error ? 3_200_000_000 : 2_300_000_000)
            guard !Task.isCancelled else { return }
            withAnimation(.easeIn(duration: 0.22)) { self?.toast = nil }
        }
    }

    func confirm(_ spec: WeDialogSpec) {
        withAnimation(.easeOut(duration: 0.2)) { dialog = spec }
    }

    func pickDate(_ spec: WeDatePickerSpec) {
        withAnimation(.easeOut(duration: 0.2)) { datePicker = spec }
    }
}

/// The section: its pages slide in and out like the wallet's, a toast floats on top, back walks the stack.
@MainActor
struct WatchEarnHost: View {
    @ObservedObject private var link = WatchEarnLink.shared
    @StateObject private var host: WeHost
    let onExit: () -> Void

    init(onExit: @escaping () -> Void) {
        self.onExit = onExit
        _host = StateObject(wrappedValue: WeHost(onExit: onExit))
    }

    var body: some View {
        WeHostChrome(host: host)
            .onAppear {
                host.onExit = onExit
                openPending(link.pending)
            }
            // A page asked for by a push or an entry point (also while the section is already open).
            .onChange(of: link.pending != nil) { _ in openPending(link.pending) }
    }

    private func openPending(_ route: WeRoute?) {
        guard let route else { return }
        host.open(route)
        link.pending = nil
    }
}

private struct WeHostChrome: View {
    @ObservedObject var host: WeHost
    @Environment(\.wa) private var wa

    var body: some View {
        let dark = host.current.isPlayer
        ZStack(alignment: .top) {
            (dark ? Color.black : wa.bg).ignoresSafeArea()
            page(host.current)
                .id(host.pageId)
                .transition(.asymmetric(
                    insertion: .modifier(active: WeShift(fraction: host.forward ? 0.33 : -0.33, opacity: 0), identity: WeShift(fraction: 0, opacity: 1)),
                    removal: .modifier(active: WeShift(fraction: host.forward ? -0.25 : 0.25, opacity: 0), identity: WeShift(fraction: 0, opacity: 1))
                ))
            if let toast = host.toast {
                WeToastView(toast: toast)
                    .id(toast.id)
                    .transition(.move(edge: .top).combined(with: .opacity))
                    .zIndex(3)
            }
            if let dialog = host.dialog {
                ZStack {
                    Color.black.opacity(0.45).ignoresSafeArea().onTapGesture { host.dialog = nil }
                    WeConfirmDialog(spec: dialog) { withAnimation(.easeIn(duration: 0.15)) { host.dialog = nil } }
                }
                .transition(.opacity)
                .zIndex(4)
            }
            if let picker = host.datePicker {
                ZStack {
                    Color.black.opacity(0.45).ignoresSafeArea().onTapGesture { host.datePicker = nil }
                    WeDatePickerCard(spec: picker) { withAnimation(.easeIn(duration: 0.15)) { host.datePicker = nil } }
                }
                .transition(.opacity)
                .zIndex(4)
            }
        }
        // Nothing behind the section reacts to touches.
        .contentShape(Rectangle())
        .environmentObject(host)
    }

    @ViewBuilder
    private func page(_ route: WeRoute) -> some View {
        switch route {
        case .home: WatchEarnHome()
        case .player(let feed): WatchEarnPlayer(feed: feed)
        case .rewards: WatchEarnRewards()
        case .wizard(let form): AdWizard(form: form)
        case .myAds(let status): MyAds(initialStatus: status)
        case .stats(let adId): AdStats(adId: adId)
        case .adWallet(let fundMinor): AdWallet(fundMinor: fundMinor)
        case .adPage(let adId): WatchEarnAdPage(adId: adId)
        }
    }
}

/// Slides a page by a share of the screen (towards the reading direction's "next" edge) while fading.
/// SwiftUI mirrors `offset(x:)` in right-to-left layouts itself (like the wallet's PageShift).
struct WeShift: ViewModifier {
    let fraction: CGFloat
    let opacity: Double

    func body(content: Content) -> some View {
        content
            .offset(x: fraction * UIScreen.main.bounds.width)
            .opacity(opacity)
    }
}

private struct WeToastView: View {
    @Environment(\.wa) private var wa
    let toast: WeToast

    var body: some View {
        let color = toast.error ? wa.danger : We.withdrawable
        HStack(spacing: 10) {
            Image(systemName: toast.error ? "exclamationmark.circle" : "checkmark.circle.fill").font(.system(size: 18, weight: .semibold)).foregroundStyle(color)
            Text(verbatim: toast.text).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink).multilineTextAlignment(.leading)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 12)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .shadow(color: Color(hex: 0x111928).opacity(0.25), radius: 14, y: 4)
        .padding(.horizontal, 16)
        .padding(.vertical, 10)
    }
}
