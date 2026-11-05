import SwiftUI

// freelanceApp `NotificationsScreen.kt` — the freelancer's notifications, as in the DORR app.

/// Notifications fetched per request (infinite scroll).
private let pageSize = 15

private struct NotificationItem: Identifiable, Equatable {
    let id: String
    let title: String
    let body: String
    let event: String?
    let minutesAgo: Int
    var unread: Bool
    /// The screen the notice leads to (see [NoticeRoute]); nil when it has none of its own.
    let route: Route?
    /// 0 today, 1 yesterday, 2 earlier this week, 3 older — on the phone's calendar.
    var day: Int = 0
}

private extension NotificationDto {
    func toItem() -> NotificationItem {
        let minutes = parseInstant(createdAtIso).map { Int(Date().timeIntervalSince($0) / 60) } ?? 0
        let route = NoticeRoute.resolve(event) { key in
            guard let value = data?[key]?.str, !value.trimmingCharacters(in: .whitespaces).isEmpty else { return nil }
            return value
        }
        let daysBack = parseInstant(createdAtIso).map { at -> Int in
            let cal = Calendar.current
            return cal.dateComponents([.day], from: cal.startOfDay(for: at), to: cal.startOfDay(for: Date())).day ?? 0
        } ?? 0
        let day = daysBack <= 0 ? 0 : daysBack == 1 ? 1 : daysBack < 7 ? 2 : 3
        return NotificationItem(id: id, title: title ?? "", body: message ?? "", event: event, minutesAgo: max(minutes, 0),
                                unread: readAt == nil, route: route, day: day)
    }
}

/// The icon and colour of a notice from its event: verification, the wallet, the wallet PIN, anything else.
private func typeStyle(_ event: String?) -> (String, Color) {
    let e = event ?? ""
    if e.hasPrefix("wallet.pin") { return ("lock.fill", Color(hex: 0xDC2626)) }
    if e.hasPrefix("wallet.") { return ("wallet.pass.fill", Color(hex: 0x16A34A)) }
    if e.hasPrefix("freelance.verification") { return ("checkmark.seal.fill", Color(hex: 0x2563EB)) }
    if e.hasPrefix("freelance.offer") { return ("doc.text.magnifyingglass", Color(hex: 0xD97706)) }
    if e.contains("consultation") { return ("video.fill", Color(hex: 0x8B5CF6)) }
    if e.contains("order") || e.contains("service") { return ("storefront.fill", Color(hex: 0x14B8A6)) }
    if e.contains("special_offer") { return ("tag.fill", Color(hex: 0xF43F5E)) }
    if e.contains("loyalty") { return ("heart.circle.fill", Color(hex: 0xF59E0B)) }
    if e.contains("dispute") { return ("hammer.fill", Color(hex: 0xDC2626)) }
    if e.contains("agreement") || e.contains("milestone") || e.contains("payment") { return ("hand.raised.fill", Color(hex: 0x3B82F6)) }
    if e.contains("invitation") { return ("envelope.badge.fill", Color(hex: 0x3B82F6)) }
    if e.hasPrefix("freelance.project") { return ("briefcase.fill", Color(hex: 0x16A34A)) }
    // FL-L12: the professional community.
    if e.hasPrefix("freelance.community") { return ("person.3.fill", Color(hex: 0x7C3AED)) }
    return ("bell.fill", Color(hex: 0x001B53))
}

private func relativeTime(_ minutesAgo: Int) -> String {
    if minutesAgo < 1 { return L("notif_time_now") }
    if minutesAgo < 60 { return L("notif_time_minutes_ago", minutesAgo) }
    if minutesAgo < 24 * 60 { return L("notif_time_hours_ago", minutesAgo / 60) }
    if minutesAgo < 2 * 24 * 60 { return L("notif_time_yesterday") }
    if minutesAgo < 7 * 24 * 60 { return L("notif_time_days_ago", minutesAgo / (24 * 60)) }
    return L("notif_time_weeks_ago", minutesAgo / (7 * 24 * 60))
}

/**
 * The freelancer's notifications, as in the DORR app: a card per notice, the first page on entry and the next one each time the list
 * is scrolled near its end (a spinner under the rows, "show more" to retry a failed page), "mark all read", and a details sheet.
 * Nothing runs on a timer: the list also reloads when the connection returns and once for every live notice.
 */
@MainActor
struct NotificationsScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpenRoute: (Route) -> Void

    @State private var notifications: [NotificationItem] = []
    @State private var loaded = false
    @State private var selected: NotificationItem?
    @State private var page = 0
    @State private var hasMore = true
    @State private var loadingMore = false
    @State private var pageFailed = false
    @State private var onlyUnread = false

    var body: some View {
        let hasUnread = notifications.contains { $0.unread }
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("notifications_title"), onBack: onBack)
                // All / unread, and «mark all read» while something is unread.
                let unreadCount = notifications.filter(\.unread).count
                HStack(spacing: 0) {
                    PillChip(text: L("notif_filter_all"), selected: !onlyUnread) { onlyUnread = false }
                    Spacer().frame(width: 8)
                    PillChip(text: L("notif_filter_unread") + (unreadCount > 0 ? "  \(unreadCount)" : ""), selected: onlyUnread,
                             dot: unreadCount > 0 && !onlyUnread) { onlyUnread = true }
                    Spacer(minLength: 0)
                    if hasUnread {
                        Button(action: markAllRead) {
                            HStack(spacing: 4) {
                                Image(systemName: "checkmark.circle").font(.system(size: 15, weight: .semibold))
                                Text(L("notifications_mark_all_read")).font(.dorr(13, .bold))
                            }
                            .foregroundStyle(wa.night ? wa.accent : wa.red)
                            .padding(.horizontal, 8).padding(.vertical, 8)
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(.horizontal, 16)
                let shown = onlyUnread ? notifications.filter(\.unread) : notifications
                if loaded && shown.isEmpty && !(onlyUnread && hasMore) {
                    EmptyNotifications(allRead: onlyUnread && !notifications.isEmpty).frame(maxHeight: .infinity)
                } else {
                    ScrollView {
                        LazyVStack(spacing: 10) {
                            ForEach(Array(shown.enumerated()), id: \.element.id) { index, item in
                                // A day's head above its first notice.
                                if index == 0 || shown[index - 1].day != item.day {
                                    Text(L(item.day == 0 ? "notif_day_today" : item.day == 1 ? "notif_day_yesterday" : item.day == 2 ? "notif_day_week" : "notif_day_older"))
                                        .font(.dorr(12, .heavy)).foregroundStyle(wa.settingsMut)
                                        .frame(maxWidth: .infinity, alignment: .leading)
                                        .padding(.top, index == 0 ? 0 : 8).padding(.leading, 4)
                                }
                                NotificationCard(item: item) { open(item) }
                                    // Near the end of what is loaded (the last few rows are on screen)?
                                    .onAppear { if index >= shown.count - 4 { nearEnd() } }
                            }
                            // The next page is loading (a spinner under the rows), or it failed (tap to try again).
                            if loadingMore || pageFailed {
                                Group {
                                    if loadingMore {
                                        ProgressView().tint(wa.accent).frame(width: 24, height: 24)
                                    } else {
                                        Button { Task { await loadPage(page == 0 ? 1 : page + 1) } } label: {
                                            Text(L("support_ticket_more")).font(.dorr(14, .bold)).foregroundStyle(wa.accent)
                                                .padding(.horizontal, 16).padding(.vertical, 8).contentShape(Rectangle())
                                        }
                                        .buttonStyle(.plain)
                                    }
                                }
                                .frame(maxWidth: .infinity)
                                .padding(.vertical, 8)
                            }
                        }
                        .padding(.horizontal, 16)
                        .padding(.top, 12)
                        .padding(.bottom, 24)
                    }
                }
            }
        }
        .navigationBarHidden(true)
        .task(id: network.reconnectTick) { await restart() }
        // A notice arrived while this page is open: start again from the first page.
        .onReceive(Realtime.shared.changed) { _ in Task { await restart() } }
        .sheet(item: $selected) { item in
            NotificationDetailSheet(item: item)
                .presentationDetents([.large])
                .presentationDragIndicator(.visible)
        }
    }

    private func restart() async {
        page = 0
        hasMore = true
        await loadPage(1)
    }

    private func nearEnd() {
        if loaded && hasMore && !loadingMore && !pageFailed { Task { await loadPage(page + 1) } }
    }

    private func loadPage(_ next: Int) async {
        // One request at a time: scrolling again while a page is on its way does nothing.
        if loadingMore { return }
        loadingMore = true
        pageFailed = false
        if let data = await NotificationsApi.list(perPage: pageSize, page: next).value {
            let rows = data.map { $0.toItem() }
            if next == 1 { notifications.removeAll() }
            // New rows go under the ones already there; a row that moved between pages is not shown twice.
            let known = Set(notifications.map(\.id))
            notifications.append(contentsOf: rows.filter { !known.contains($0.id) })
            page = next
            hasMore = rows.count >= pageSize
        } else {
            pageFailed = true
        }
        loadingMore = false
        loaded = true
    }

    private func markAllRead() {
        for i in notifications.indices { notifications[i].unread = false }
        Task {
            if let result = await NotificationsApi.markAllRead().value { Realtime.shared.setUnread(result.count ?? 0) }
        }
    }

    private func open(_ item: NotificationItem) {
        if let index = notifications.firstIndex(where: { $0.id == item.id }) { notifications[index].unread = false }
        // A notice goes to the screen of what it is about; one without a screen of its own shows its details.
        if let route = item.route {
            onOpenRoute(route)
        } else {
            var read = item
            read.unread = false
            selected = read
        }
        if item.unread {
            Task {
                if let result = await NotificationsApi.markRead(item.id).value { Realtime.shared.setUnread(result.count ?? 0) }
            }
        }
    }
}

private struct NotificationCard: View {
    @Environment(\.wa) private var wa
    let item: NotificationItem
    let onClick: () -> Void

    var body: some View {
        let (icon, color) = typeStyle(item.event)
        let fill: Color = wa.night ? (item.unread ? wa.darkWell : wa.darkCard) : (item.unread ? Color(hex: 0xFFF6F7) : .white)
        Button(action: onClick) {
            HStack(alignment: .top, spacing: 12) {
                // Unread is shown by the tinted card and the bold title; no dot above the icon.
                Image(systemName: icon).font(.system(size: 19, weight: .semibold)).foregroundStyle(color)
                    .frame(width: 42, height: 42)
                    .background(color.opacity(0.1), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                VStack(alignment: .leading, spacing: 4) {
                    HStack(alignment: .top, spacing: 8) {
                        Text(verbatim: item.title).font(.dorr(14, item.unread ? .bold : .semibold)).foregroundStyle(wa.settingsInk)
                            .lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                        Text(verbatim: relativeTime(item.minutesAgo)).font(.dorr(11.5, item.unread ? .bold : .regular))
                            .foregroundStyle(item.unread ? wa.red : wa.settingsMut)
                        if item.unread { Circle().fill(wa.red).frame(width: 8, height: 8).padding(.leading, -2).padding(.top, 4) }
                    }
                    Text(verbatim: item.body).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(2)
                        .multilineTextAlignment(.leading)
                }
            }
            .padding(14)
            .background(fill, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay { if wa.night { RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.line, lineWidth: 1) } }
            .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.07), radius: 6, y: 2)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

private struct NotificationDetailSheet: View {
    @Environment(\.wa) private var wa
    let item: NotificationItem

    var body: some View {
        let (icon, color) = typeStyle(item.event)
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                HStack(alignment: .top, spacing: 12) {
                    Image(systemName: icon).font(.system(size: 20, weight: .semibold)).foregroundStyle(color)
                        .frame(width: 44, height: 44)
                        .background(color.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    VStack(alignment: .leading, spacing: 4) {
                        Text(verbatim: item.title.trimmingCharacters(in: .whitespaces).isEmpty ? L("notification_default_title") : item.title)
                            .font(.dorr(16, .bold)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: relativeTime(item.minutesAgo)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                }
                Text(verbatim: item.body).font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(6).padding(.top, 18)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 20)
            .padding(.top, 24)
            .padding(.bottom, 24)
        }
        .background((wa.night ? wa.darkCard : Color.white).ignoresSafeArea())
    }
}

private struct EmptyNotifications: View {
    var allRead = false

    var body: some View {
        EmptyState(icon: "bell.fill", title: L(allRead ? "notif_all_read_title" : "notifications_empty_title"),
                   body: L(allRead ? "notif_all_read_body" : "notifications_empty_body"))
            .padding(.vertical, 40)
    }
}
