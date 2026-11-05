import SwiftUI

/// Reasons a viewer reports an ad with (AdReport::REASONS).
private let adReportReasons: [(String, String)] = [
    ("misleading", "we_reason_misleading"),
    ("offensive", "we_reason_offensive"),
    ("adult", "we_reason_adult"),
    ("violence", "we_reason_violence"),
    ("scam", "we_reason_scam"),
    ("other", "we_reason_other"),
]

/// Reasons the advertiser reports a comment with (CommentReport::REASONS).
private let commentReportReasons: [(String, String)] = [
    ("offensive", "we_reason_offensive"),
    ("spam", "we_reason_spam"),
    ("misleading", "we_reason_misleading"),
    ("harassment", "we_reason_harassment"),
    ("other", "we_reason_other"),
]

/// emoji → count from a real-time payload (`{}` or an empty PHP `[]`).
private func reactionsOf(_ value: JSONAny?) -> [String: Int]? {
    guard let value else { return nil }
    if case .array = value { return [:] }
    if case .object(let map) = value { return map.mapValues { $0.int ?? 0 } }
    return nil
}

/// A comment from a real-time payload (keys as the server sent them).
private func commentOf(_ value: JSONAny?) -> WeCommentDto? {
    guard let value, let data = try? JSONEncoder().encode(value) else { return nil }
    return try? JSONDecoder().decode(WeCommentDto.self, from: data)
}

/// androidApp `WeComments` — one ad's comments, live (WE-033), shared by the comments sheet and the ad's page: the
/// list (cursor pages), the reaction counts with my own, and the composer's text. `weCommentsLive` loads it once and,
/// while the view shows, listens to `private-watch-earn.ad.{id}`: a new comment comes in at the top, a deleted one
/// goes, the counts follow.
@MainActor
final class WeComments: ObservableObject {
    let adId: String
    @Published private(set) var comments: [WeCommentDto] = []
    @Published private(set) var nextCursor: String?
    @Published private(set) var loaded = false
    @Published private(set) var loadingMore = false
    @Published private(set) var reactions: [String: Int] = [:]
    @Published private(set) var myReaction: String?
    @Published var text = ""
    @Published private(set) var sending = false
    /// The section's host: toasts and the «delete?» question.
    weak var host: WeHost?
    /// The first comment or reaction (it earns the interaction reward while the window is open).
    var onInteracted: () -> Void = {}

    init(adId: String) {
        self.adId = adId
    }

    func load(host: WeHost) async {
        self.host = host
        do {
            if let page = try await WatchEarnApi.comments(adId).data {
                comments = page.comments
                nextCursor = page.nextCursor
                reactions = page.reactions
                myReaction = page.myReaction
            }
        } catch {
            host?.showToast(error.weMessage(L("we_error_generic")), error: true)
        }
        loaded = true
    }

    func more() async {
        guard let cursor = nextCursor, !loadingMore else { return }
        loadingMore = true
        if let page = try? await WatchEarnApi.comments(adId, cursor: cursor).data {
            let known = Set(comments.map(\.id))
            comments.append(contentsOf: page.comments.filter { !known.contains($0.id) })
            nextCursor = page.nextCursor
        }
        loadingMore = false
    }

    func onEvent(_ event: ChatEvent) {
        guard event.str("ad_id") == adId else { return }
        switch event.name {
        case "watch_earn.comment":
            if let c = commentOf(event.data["comment"]), !comments.contains(where: { $0.id == c.id }) { comments.insert(c, at: 0) }
        case "watch_earn.comment_deleted":
            if let id = event.data["comment_id"]?.int { comments.removeAll { $0.id == id } }
        case "watch_earn.reactions":
            if let fresh = reactionsOf(event.data["reactions"]) { reactions = fresh }
        default: break
        }
    }

    /// A tap sets my reaction; a tap on mine takes it back.
    func react(_ emoji: String) {
        Task {
            do {
                let before = myReaction
                let result: WeReactionsDto?
                if before == emoji {
                    result = try await WatchEarnApi.unreact(adId).data
                } else {
                    result = try await WatchEarnApi.react(adId, WeReactionRequest(emoji: emoji)).data
                }
                if let result {
                    reactions = result.reactions
                    myReaction = result.myReaction
                    if before == nil && result.myReaction != nil { onInteracted() }
                }
            } catch {
                host?.showToast(error.weMessage(L("we_error_generic")), error: true)
            }
        }
    }

    /// Sends the composer's text; [onSent] runs once it is on the list (to scroll to it).
    func send(onSent: @escaping () -> Void = {}) {
        let body = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !body.isEmpty, !sending else { return }
        sending = true
        Task {
            do {
                let c = try await WatchEarnApi.comment(adId, WeCommentRequest(body: body)).data
                text = ""
                if let c, !comments.contains(where: { $0.id == c.id }) { comments.insert(c, at: 0) }
                onInteracted()
                onSent()
            } catch {
                host?.showToast(error.weMessage(L("we_error_generic")), error: true)
            }
            sending = false
        }
    }

    func confirmDelete(_ c: WeCommentDto) {
        host?.confirm(WeDialogSpec(title: L("we_delete_comment_title"), text: L("we_delete_comment_text"), confirm: L("we_delete"), danger: true) { [weak self] in
            guard let self else { return }
            Task { @MainActor in
                do {
                    try await WatchEarnApi.deleteComment(self.adId, c.id)
                    self.comments.removeAll { $0.id == c.id }
                } catch {
                    self.host?.showToast(error.weMessage(L("we_error_generic")), error: true)
                }
            }
        })
    }
}

extension View {
    /// Loads [c] once and keeps it live while this view shows: no timer, only what the server pushes.
    @MainActor
    func weCommentsLive(_ c: WeComments, host: WeHost) -> some View {
        self
            .task { await c.load(host: host) }
            .onAppear {
                c.host = host
                ChatRealtime.shared.watchWatchEarnAd(c.adId)
            }
            .onDisappear { ChatRealtime.shared.unwatchWatchEarnAd(c.adId) }
            .onReceive(ChatRealtime.shared.events) { event in c.onEvent(event) }
    }
}

/// The reaction counts; mine is outlined. A tap sets it, a tap on mine takes it back.
struct WeReactionCounts: View {
    @Environment(\.wa) private var wa
    @ObservedObject var c: WeComments

    var body: some View {
        HStack(spacing: 6) {
            ForEach(weQuickReactions, id: \.self) { emoji in
                let mine = c.myReaction == emoji
                let count = c.reactions[emoji] ?? 0
                Button { c.react(emoji) } label: {
                    HStack(spacing: 3) {
                        Text(verbatim: emoji).font(.system(size: 17))
                        if count > 0 { Text(verbatim: "\(count)").font(.dorr(11, .bold)).foregroundStyle(mine ? wa.red : wa.mut) }
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 7)
                    .background(mine ? wa.red.opacity(0.14) : wa.field, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(mine ? wa.red.opacity(0.5) : .clear, lineWidth: 1))
                    .animation(.easeOut(duration: 0.2), value: count)
                }
                .buttonStyle(WaPressStyle(scale: 0.92))
            }
        }
    }
}

/// The comments: skeletons while loading, the empty state, the rows (the next page when the end shows — the person
/// scrolled, not a timer) and the next page's spinner. Long-press my comment to delete it; ⋮ on someone else's reports
/// the ad (when [onReportAd] is given).
struct WeCommentRows: View {
    @Environment(\.wa) private var wa
    @ObservedObject var c: WeComments
    var onReportAd: (() -> Void)?

    var body: some View {
        let me = AuthSession.shared.user?.id
        Group {
            if !c.loaded {
                VStack(spacing: 10) { ForEach(0..<3, id: \.self) { _ in WaSkeleton().frame(height: 48) } }
            } else if c.comments.isEmpty {
                VStack(spacing: 8) {
                    Image(systemName: "bubble.left").font(.system(size: 30)).foregroundStyle(wa.soft)
                    Text(L("we_no_comments")).font(.dorr(13)).foregroundStyle(wa.mut).multilineTextAlignment(.center)
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 30)
            } else {
                LazyVStack(spacing: 0) {
                    ForEach(Array(c.comments.enumerated()), id: \.element.id) { i, comment in
                        let mine = me != nil && comment.user?.id == me
                        WeCommentRow(
                            comment: comment,
                            onLongPress: mine ? { c.confirmDelete(comment) } : nil,
                            menuLabel: !mine && onReportAd != nil ? L("we_report_ad") : nil,
                            onMenu: !mine ? onReportAd : nil
                        )
                        .transition(.opacity.combined(with: .move(edge: .top)))
                        .onAppear { if i >= c.comments.count - 2 { Task { await c.more() } } }
                    }
                    if c.loadingMore {
                        ProgressView().tint(wa.red).padding(12)
                    }
                }
                .animation(.spring(response: 0.35, dampingFraction: 0.8), value: c.comments.map(\.id))
            }
        }
    }
}

/// «Write a comment» and its send button, which pops in once there is text.
struct WeCommentComposer: View {
    @Environment(\.wa) private var wa
    @ObservedObject var c: WeComments
    var onSent: () -> Void = {}

    var body: some View {
        HStack(spacing: 8) {
            WeTextField(value: $c.text, placeholder: L("we_write_comment"), minLines: 1, multiline: true, limit: 1000, capitalize: true)
            if !c.text.trimmingCharacters(in: .whitespaces).isEmpty {
                Button { c.send(onSent: onSent) } label: {
                    ZStack {
                        if c.sending { ProgressView().tint(.white) } else { Image(systemName: "paperplane.fill").font(.system(size: 17, weight: .semibold)).foregroundStyle(.white) }
                    }
                    .frame(width: 46, height: 46)
                    .background(wa.buttonGradient, in: Circle())
                }
                .buttonStyle(WaPressStyle(scale: 0.9))
                .accessibilityLabel(L("we_send"))
                .transition(.scale.combined(with: .opacity))
            }
        }
        .animation(.spring(response: 0.3, dampingFraction: 0.5), value: c.text.isEmpty)
    }
}

/// androidApp `WatchEarnCommentsSheet` — one ad's comments in a bottom sheet: the reaction counts, the live list and
/// the composer ([WeComments]). Long-press my comment to delete it; ⋮ on someone else's reports the ad.
@MainActor
struct WatchEarnCommentsSheet: View {
    @EnvironmentObject private var host: WeHost
    @Environment(\.wa) private var wa
    let adId: String
    var onInteracted: () -> Void = {}
    var onReportAd: (() -> Void)?
    @StateObject private var c: WeComments

    init(adId: String, onInteracted: @escaping () -> Void = {}, onReportAd: (() -> Void)? = nil) {
        self.adId = adId
        self.onInteracted = onInteracted
        self.onReportAd = onReportAd
        _c = StateObject(wrappedValue: WeComments(adId: adId))
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(L("we_comments")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
            WeReactionCounts(c: c).padding(.top, 10)

            ScrollViewReader { proxy in
                ScrollView {
                    VStack(spacing: 0) {
                        Color.clear.frame(height: 0).id("top")
                        WeCommentRows(c: c, onReportAd: onReportAd)
                    }
                }
                .onChange(of: c.comments.first?.id) { _ in withAnimation { proxy.scrollTo("top", anchor: .top) } }
            }
            .frame(minHeight: 160, maxHeight: 420)
            .fixedSize(horizontal: false, vertical: true)
            .padding(.top, 10)

            WeCommentComposer(c: c).padding(.top, 8)
        }
        .padding(.horizontal, 16)
        .padding(.bottom, 12)
        .onAppear { c.onInteracted = onInteracted }
        .weCommentsLive(c, host: host)
    }
}

/// One comment: avatar, name, when, the text — long-press (mine) or ⋮ (someone else's) when given.
struct WeCommentRow: View {
    @Environment(\.wa) private var wa
    let comment: WeCommentDto
    var onLongPress: (() -> Void)?
    var menuLabel: String?
    var onMenu: (() -> Void)?

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            ZStack {
                wa.red.opacity(0.14)
                if let avatar = comment.user?.avatar, ApiClient.mediaUrl(avatar) != nil {
                    WeRemoteImage(url: avatar)
                } else if let initial = comment.user?.name?.trimmingCharacters(in: .whitespaces).first {
                    Text(verbatim: String(initial)).font(.dorr(14, .heavy)).foregroundStyle(wa.red)
                } else {
                    Image(systemName: "person.fill").font(.system(size: 16)).foregroundStyle(wa.red)
                }
            }
            .frame(width: 36, height: 36)
            .clipShape(Circle())
            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 8) {
                    Text(verbatim: comment.user?.name ?? "").font(.dorr(13, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                    Text(verbatim: weDateTime(comment.createdAt)).font(.dorr(10.5)).foregroundStyle(wa.soft)
                }
                Text(verbatim: comment.body).font(.dorr(13.5)).foregroundStyle(wa.ink).lineSpacing(4).fixedSize(horizontal: false, vertical: true)
            }
            Spacer(minLength: 0)
            if let onMenu, let menuLabel {
                Menu {
                    Button(role: .destructive, action: onMenu) { Label(menuLabel, systemImage: "flag.fill") }
                } label: {
                    Image(systemName: "ellipsis").rotationEffect(.degrees(90))
                        .font(.system(size: 14, weight: .bold)).foregroundStyle(wa.mut)
                        .frame(width: 32, height: 32).contentShape(Circle())
                }
                .accessibilityLabel(L("we_more"))
            }
        }
        .padding(.vertical, 8)
        .padding(.horizontal, 2)
        .contentShape(Rectangle())
        .onLongPressGesture { onLongPress?() }
    }
}

// MARK: - report sheets

/// The viewer reports the ad (one report per viewer). [onReported] gets the server's message.
struct WeReportAdSheet: View {
    let adId: String
    let onReported: (String) -> Void

    var body: some View {
        WeReasonSheet(title: L("we_report_ad"), reasons: adReportReasons, submit: { reason, note in
            try await WatchEarnApi.reportAd(adId, WeReportRequest(reason: reason, note: note)).message ?? ""
        }, onDone: onReported)
    }
}

/// The advertiser reports a comment on their ad — they can't delete it (D21).
struct WeReportCommentSheet: View {
    let adId: String
    let commentId: Int
    let onReported: (String) -> Void

    var body: some View {
        WeReasonSheet(title: L("we_report_comment"), reasons: commentReportReasons, submit: { reason, note in
            try await WatchEarnApi.reportComment(adId, commentId, WeReportRequest(reason: reason, note: note)).message ?? ""
        }, onDone: onReported)
    }
}

@MainActor
private struct WeReasonSheet: View {
    @EnvironmentObject private var host: WeHost
    @Environment(\.wa) private var wa
    let title: String
    let reasons: [(String, String)]
    let submit: (String, String?) async throws -> String
    let onDone: (String) -> Void
    @State private var reason: String?
    @State private var note = ""
    @State private var busy = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                Text(L("we_report_hint")).font(.dorr(12.5)).foregroundStyle(wa.mut).padding(.top, 4).padding(.bottom, 12)
                ForEach(reasons, id: \.0) { item in
                    let key = item.0
                    let label = item.1
                    let picked = reason == key
                    Button { withAnimation(.spring(response: 0.25, dampingFraction: 0.6)) { reason = key } } label: {
                        HStack(spacing: 12) {
                            ZStack {
                                Circle().stroke(picked ? wa.red : wa.soft, lineWidth: 2).frame(width: 18, height: 18)
                                if picked { Circle().fill(wa.red).frame(width: 9, height: 9).transition(.scale) }
                            }
                            Text(L(label)).font(.dorr(14, .bold)).foregroundStyle(wa.ink)
                            Spacer(minLength: 0)
                        }
                        .padding(.horizontal, 14)
                        .padding(.vertical, 12)
                        .background(picked ? wa.red.opacity(0.1) : wa.field, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(picked ? wa.red.opacity(0.5) : .clear, lineWidth: 1))
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    .padding(.vertical, 4)
                }
                WeTextField(value: $note, placeholder: L("we_report_note"), minLines: 2, limit: 500, capitalize: true).padding(.top, 8)
                WaButton(text: L("we_send_report"), icon: "flag.fill", enabled: reason != nil, loading: busy) {
                    guard let r = reason else { return }
                    busy = true
                    Task {
                        do {
                            let trimmed = note.trimmingCharacters(in: .whitespacesAndNewlines)
                            let message = try await submit(r, trimmed.isEmpty ? nil : trimmed)
                            onDone(message)
                        } catch {
                            host.showToast(error.weMessage(L("we_error_generic")), error: true)
                        }
                        busy = false
                    }
                }
                .padding(.top, 14)
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 20)
        }
        .frame(maxHeight: 600)
        .fixedSize(horizontal: false, vertical: true)
    }
}
