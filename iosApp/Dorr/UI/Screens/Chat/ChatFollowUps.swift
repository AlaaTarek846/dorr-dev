import SwiftUI

// androidApp `chat/ChatToolsUi.kt` (FollowUpsPage) and ChatBacklogUi's `ChannelStatsPage` — the messages I set aside
// (read later · needs a reply · reminders), each shown as in its chat with «Done»; and a channel's statistics.

struct FollowUpsPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var tab = 0
    @State private var readLater: [MessageDto]?
    @State private var replies: [MessageDto]?
    @State private var reminders: [ReminderDto]?

    var body: some View {
        ChPage(title: L("ch_follow_ups_title"), onBack: { host.pop() }) {
            VStack(spacing: 0) {
                HStack(spacing: 0) {
                    ForEach(Array([("ch_read_later_title", readLater?.count), ("ch_needs_reply_title", replies?.count), ("ch_reminders_title", reminders?.count)].enumerated()), id: \.offset) { i, t in
                        let on = tab == i
                        Button { tab = i } label: {
                            Text(verbatim: L(t.0) + ((t.1 ?? 0) > 0 ? " (\(t.1 ?? 0))" : "")).font(.dorr(12.5, .heavy)).foregroundStyle(on ? ch.red : ch.mut).lineLimit(1)
                                .frame(maxWidth: .infinity).padding(.vertical, 9)
                                .background(on ? ch.surface : .clear, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(4)
                .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                .padding(14)
                switch tab {
                case 0:
                    list(readLater, icon: "bookmark.fill", empty: "ch_read_later_empty", label: { _ in nil }) { m in
                        readLater = readLater?.filter { $0.id != m.id }
                        Task { _ = try? await ChatApi.readLater(m.id, false) }
                    }
                case 1:
                    list(replies, icon: "flag.fill", empty: "ch_needs_reply_empty", label: { _ in nil }) { m in
                        replies = replies?.filter { $0.id != m.id }
                        Task { _ = try? await ChatApi.followUp(m.id, false) }
                    }
                default:
                    list(reminders?.map(\.message), icon: "alarm.fill", empty: "ch_reminders_empty", label: { m in
                        reminders?.first { $0.message.id == m.id }.map { r in "⏰ " + scheduleLabel(r.remindAt) + (r.note.map { " · \($0)" } ?? "") }
                    }) { m in
                        reminders = reminders?.filter { $0.message.id != m.id }
                        Task { _ = try? await ChatApi.clearReminder(m.id) }
                    }
                }
            }
        }
        .task {
            readLater = (try? await ChatApi.readLaterList().data) ?? []
            replies = (try? await ChatApi.followUpList().data) ?? []
            reminders = (try? await ChatApi.reminders().data) ?? []
        }
    }

    @ViewBuilder
    private func list(_ items: [MessageDto]?, icon: String, empty: String, label: @escaping (MessageDto) -> String?, onDone: @escaping (MessageDto) -> Void) -> some View {
        if let items {
            if items.isEmpty {
                ChEmptyState(icon: icon, title: "", text: L(empty))
            } else {
                ScrollView {
                    LazyVStack(spacing: 0) {
                        ForEach(Array(items.enumerated()), id: \.element.id) { i, m in
                            VStack(spacing: 0) {
                                HStack(spacing: 8) {
                                    ChAvatar(url: m.sender?.avatar, name: m.sender?.name, key: m.sender?.key, size: 26)
                                    VStack(alignment: .leading, spacing: 0) {
                                        Text(verbatim: m.sender?.isMe == true ? L("ch_you") : m.sender?.name ?? "").font(.dorr(13, .bold)).foregroundStyle(ch.ink)
                                        if let l = label(m) { Text(verbatim: l).font(.dorr(11.5, .bold)).foregroundStyle(ch.red) }
                                    }
                                    Spacer()
                                    Button { withAnimation { onDone(m) } } label: {
                                        Text(L("ch_read_later_done_short")).font(.dorr(12.5, .heavy)).foregroundStyle(ch.red)
                                            .padding(.horizontal, 10).padding(.vertical, 5).background(ch.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 12))
                                    }
                                    .buttonStyle(.plain)
                                }
                                .padding(.horizontal, 18)
                                MessageRow(m: UiMessage(dto: m), firstInRun: true, lastInRun: true, isGroup: false, actions: BubbleActions(
                                    onReply: { _ in }, onLongPress: { _ in }, onJumpTo: { _ in }, onRetry: { _ in }, onOpenMedia: { _, _ in },
                                    onPayQr: { host.openWalletQr($0) }, onMessageContact: { _ in }
                                ))
                                .allowsHitTesting(false)
                            }
                            .padding(.vertical, 6)
                            .contentShape(Rectangle())
                            .onTapGesture { host.showInChat(m.conversationId, m.id) }
                            .chStagger(i)
                        }
                    }
                    .padding(.bottom, 12)
                }
            }
        } else {
            WaSkeleton().frame(height: 200).padding(16)
            Spacer()
        }
    }
}

/// A channel's followers, joins and leaves, posts, views and reach over 7 / 30 / 90 days, and its top posts.
struct ChannelStatsPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    @State private var days = 30
    @State private var stats: ChannelStatsDto?

    var body: some View {
        ChPage(title: L("bk_channel_stats"), onBack: { host.pop() }) {
            ScrollView {
                VStack(alignment: .leading, spacing: 10) {
                    HStack(spacing: 6) {
                        ForEach([7, 30, 90], id: \.self) { d in
                            Button { days = d } label: {
                                Text(L("bk_days", d)).font(.dorr(13, .bold)).foregroundStyle(days == d ? .white : ch.ink)
                                    .padding(.horizontal, 12).padding(.vertical, 8).background(days == d ? ch.red : ch.surfaceMuted, in: Capsule())
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    if let s = stats {
                        HStack(spacing: 10) {
                            tile(L("bk_channel_followers"), "\(s.followers ?? 0)")
                            tile(L("bk_channel_joined"), "+\(s.joined ?? 0)")
                            tile(L("bk_channel_left"), "−\(s.left ?? 0)")
                        }
                        .padding(.top, 4)
                        HStack(spacing: 10) {
                            tile(L("bk_channel_posts"), "\(s.posts ?? 0)")
                            tile(L("bk_channel_views"), "\(s.views ?? 0)")
                            tile(L("bk_channel_reach"), "\(s.reachPercent ?? 0)%")
                        }
                        tile(L("bk_channel_avg"), "\(s.averageViews ?? 0)")
                        if let top = s.topPosts, !top.isEmpty {
                            Text(L("bk_channel_top")).font(.dorr(13, .bold)).foregroundStyle(ch.mut).padding(.top, 8)
                            ForEach(Array(top.enumerated()), id: \.offset) { _, post in
                                Button {
                                    guard let id = post.id else { return }
                                    host.focusRequest = (conversationId, id)
                                    host.pop()
                                } label: {
                                    HStack(spacing: 10) {
                                        Text(verbatim: post.preview ?? "").font(.dorr(13.5)).foregroundStyle(ch.ink).lineLimit(2).multilineTextAlignment(.leading)
                                            .frame(maxWidth: .infinity, alignment: .leading)
                                        Text(verbatim: "👁 \(post.views ?? 0)").font(.dorr(12.5, .bold)).foregroundStyle(ch.mut)
                                    }
                                    .padding(12)
                                    .background(ch.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    } else {
                        ProgressView().tint(ch.red).frame(maxWidth: .infinity).padding(40)
                    }
                }
                .padding(16)
            }
        }
        .task(id: days) {
            stats = nil
            stats = try? await BacklogApi.channelStats(conversationId, days: days).data
        }
    }

    private func tile(_ label: String, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: value).font(.dorr(20, .heavy)).foregroundStyle(ch.ink)
            Text(verbatim: label).font(.dorr(12)).foregroundStyle(ch.mut).lineLimit(1)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(12)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}
