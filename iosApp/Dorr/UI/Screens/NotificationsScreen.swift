import SwiftUI

// androidApp `NotificationsScreen.kt`.

private enum NotificationType { case jobUpdate, quote, invoice, maintenance, promo, general, wallet, walletPin }

/// Notifications fetched per request (infinite scroll).
private let pageSize = 15

private struct NotificationItem: Identifiable, Equatable {
    let id: String
    let type: NotificationType
    let title: String
    let body: String
    let minutesAgo: Int
    var unread: Bool
    /// A ride notification opens that ride (its receipt and rating when it ended — O-41).
    var rideUuid: String?
    /// A booked service's notification: its kind and booking.
    var service: (kind: String, uuid: String?)?
    /// A freelance notification opens the Freelance page it belongs to (a page of the Freelance host).
    var freelancePage: String?

    static func == (a: NotificationItem, b: NotificationItem) -> Bool { a.id == b.id && a.unread == b.unread }

    init(_ dto: NotificationDto) {
        // Wallet event first, then the server type.
        let event = dto.event ?? ""
        switch true {
        case event.hasPrefix("wallet.pin"): type = .walletPin
        case event.hasPrefix("wallet."): type = .wallet
        case dto.type == "job_update": type = .jobUpdate
        case dto.type == "quote": type = .quote
        case dto.type == "invoice": type = .invoice
        case dto.type == "maintenance": type = .maintenance
        case dto.type == "promo": type = .promo
        default: type = .general
        }
        id = dto.id
        title = dto.title
        body = dto.message
        minutesAgo = max(parseInstant(dto.createdAtIso).map { Int(Date().timeIntervalSince($0) / 60) } ?? 0, 0)
        unread = dto.readAt == nil
        func value(_ k: String) -> String? {
            guard let v = dto.data?[k]?.str, !v.isEmpty, v != "null" else { return nil }
            return v
        }
        rideUuid = value("ride_uuid")
        if let kind = event.split(separator: ".").first.map(String.init), ["bus", "marine", "hire", "lesson", "loyalty"].contains(kind) {
            service = (kind, ["booking_uuid", "hire_uuid", "enrollment_uuid"].lazy.compactMap(value).first)
        }
        if event.hasPrefix("freelance.") {
            freelancePage = FreelanceLink.pageFor(event: event, project: value("project"), offer: value("offer"), dispute: value("dispute"), consultation: value("consultation"), specialOffer: value("special_offer"))
        }
    }
}

private func typeStyle(_ type: NotificationType) -> (String, Color) {
    switch type {
    case .jobUpdate: return ("wrench.and.screwdriver.fill", AppColors.info)
    case .quote: return ("doc.text.fill", AppColors.warning)
    case .invoice: return ("doc.plaintext.fill", AppColors.secondary)
    case .maintenance: return ("calendar", AppColors.accent)
    case .promo: return ("tag.fill", AppColors.danger)
    case .general: return ("bell.fill", AppColors.primary)
    case .wallet: return ("wallet.pass.fill", Color(hex: 0x16A34A))
    case .walletPin: return ("lock.fill", AppColors.danger)
    }
}

private func relativeTime(_ minutesAgo: Int) -> String {
    switch minutesAgo {
    case ..<1: return L("notif_time_now")
    case ..<60: return L("notif_time_minutes_ago", minutesAgo)
    case ..<(24 * 60): return L("notif_time_hours_ago", minutesAgo / 60)
    case ..<(2 * 24 * 60): return L("notif_time_yesterday")
    case ..<(7 * 24 * 60): return L("notif_time_days_ago", minutesAgo / (24 * 60))
    default: return L("notif_time_weeks_ago", minutesAgo / (7 * 24 * 60))
    }
}

/// The real feed, a page at a time: the first page on entry (and again on reconnect), the next one when the
/// list is scrolled near its end, until the server says there is no more.
@MainActor
struct NotificationsScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void

    @State private var notifications: [NotificationItem] = []
    @State private var loaded = false
    @State private var selected: NotificationItem?
    @State private var page = 0
    @State private var hasMore = true
    @State private var loadingMore = false
    @State private var pageFailed = false

    var body: some View {
        let hasUnread = notifications.contains { $0.unread }
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("notifications_title"), onBack: onBack)
                if hasUnread {
                    Button(action: markAllRead) {
                        Text(L("notifications_mark_all_read")).font(.dorr(14, .bold)).foregroundStyle(wa.accent)
                            .padding(.horizontal, 12).padding(.vertical, 8)
                    }
                    .buttonStyle(.plain)
                    .frame(maxWidth: .infinity, alignment: .trailing)
                    .padding(.trailing, 8)
                }
                if loaded && notifications.isEmpty {
                    EmptyNotifications()
                } else {
                    ScrollView {
                        LazyVStack(spacing: 10) {
                            ForEach(Array(notifications.enumerated()), id: \.element.id) { index, item in
                                NotificationCard(item: item) { open(item) }
                                    // Near the end of what is loaded (the last few rows on screen): the next page.
                                    .onAppear {
                                        if index >= notifications.count - 4 && loaded && hasMore && !loadingMore && !pageFailed {
                                            Task { await loadPage(page + 1) }
                                        }
                                    }
                            }
                            // The next page is loading, or it failed (tap to try again).
                            if loadingMore || pageFailed {
                                Group {
                                    if loadingMore {
                                        ProgressView().tint(wa.accent)
                                    } else {
                                        Button { Task { await loadPage(page == 0 ? 1 : page + 1) } } label: {
                                            Text(L("support_ticket_more")).font(.dorr(14, .bold)).foregroundStyle(wa.accent)
                                                .padding(.horizontal, 16).padding(.vertical, 8)
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
                        .padding(.bottom, 100)
                    }
                }
            }
        }
        .task(id: network.reconnectTick) {
            page = 0
            hasMore = true
            await loadPage(1)
        }
        .sheet(item: $selected) { item in
            NotificationDetailSheet(item: item).dorrThemed().presentationDetents([.medium, .large])
        }
    }

    private func loadPage(_ next: Int) async {
        // One request at a time.
        guard !loadingMore else { return }
        loadingMore = true
        pageFailed = false
        do {
            let envelope = try await NotificationApi.list(perPage: pageSize, page: next)
            let rows = (envelope.data ?? []).map(NotificationItem.init)
            if next == 1 { notifications = [] }
            // A row that moved between pages is not shown twice.
            notifications += rows.filter { row in !notifications.contains { $0.id == row.id } }
            page = next
            hasMore = envelope.pagination?.hasMorePages == true
        } catch {
            pageFailed = true
        }
        loadingMore = false
        loaded = true
    }

    private func open(_ item: NotificationItem) {
        if let index = notifications.firstIndex(where: { $0.id == item.id }) { notifications[index].unread = false }
        var read = item
        read.unread = false
        selected = read
        if item.unread { Task { _ = try? await NotificationApi.markRead(item.id) } }
        // A ride or a booked service: straight to it.
        if let uuid = item.rideUuid {
            selected = nil
            ChatPush.shared.open(.ride(uuid))
        }
        if let service = item.service {
            selected = nil
            ChatPush.shared.open(.service(kind: service.kind, uuid: service.uuid))
        }
        // This list is its own screen over the main one: go back to it and it opens the screen the link names.
        if let page = item.freelancePage {
            selected = nil
            ChatPush.shared.open(.freelance(page))
            onBack()
        }
    }

    private func markAllRead() {
        for index in notifications.indices { notifications[index].unread = false }
        Task { _ = try? await NotificationApi.markAllRead() }
    }
}

private struct NotificationCard: View {
    @Environment(\.wa) private var wa
    let item: NotificationItem
    let action: () -> Void

    var body: some View {
        let (icon, color) = typeStyle(item.type)
        let shape = RoundedRectangle(cornerRadius: 16, style: .continuous)
        let fill: Color = wa.night ? (item.unread ? wa.darkWell : wa.darkCard) : (item.unread ? Color(hex: 0xFFF6F7) : .white)
        Button(action: action) {
            // Unread is shown by the tinted card and the bold title; no dot.
            HStack(alignment: .top, spacing: 12) {
                Image(systemName: icon).font(.system(size: 18, weight: .semibold)).foregroundStyle(color)
                    .frame(width: 42, height: 42)
                    .background(color.opacity(0.1), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                VStack(alignment: .leading, spacing: 4) {
                    HStack(alignment: .top, spacing: 8) {
                        Text(verbatim: item.title).font(.dorr(14, item.unread ? .bold : .semibold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        Spacer(minLength: 0)
                        Text(verbatim: relativeTime(item.minutesAgo)).font(.dorr(12)).foregroundStyle(wa.night ? wa.mut : AppColors.textMuted)
                    }
                    Text(verbatim: item.body).font(.dorr(12)).foregroundStyle(wa.night ? wa.mut : AppColors.textSecondary)
                        .lineLimit(2).multilineTextAlignment(.leading)
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(fill, in: shape)
            .overlay(shape.stroke(wa.night ? wa.line : .clear, lineWidth: 1))
            .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.07), radius: 6, y: 2)
        }
        .buttonStyle(.plain)
    }
}

/// Material's ModalBottomSheet with the whole notification.
private struct NotificationDetailSheet: View {
    @Environment(\.wa) private var wa
    let item: NotificationItem

    var body: some View {
        let (icon, color) = typeStyle(item.type)
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                HStack(alignment: .top, spacing: 12) {
                    Image(systemName: icon).font(.system(size: 20, weight: .semibold)).foregroundStyle(color)
                        .frame(width: 44, height: 44)
                        .background(color.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    VStack(alignment: .leading, spacing: 4) {
                        Text(verbatim: item.title.trimmingCharacters(in: .whitespaces).isEmpty ? L("notification_default_title") : item.title)
                            .font(.dorr(16, .bold)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: relativeTime(item.minutesAgo)).font(.dorr(12)).foregroundStyle(wa.night ? wa.mut : AppColors.textMuted)
                    }
                }
                Text(verbatim: item.body).font(.dorr(16)).foregroundStyle(wa.settingsInk).padding(.top, 18)
                Spacer().frame(height: 24)
            }
            .padding(.horizontal, 20)
            .padding(.top, 28)
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .background((wa.night ? wa.darkCard : Color.white).ignoresSafeArea())
    }
}

private struct EmptyNotifications: View {
    @Environment(\.wa) private var wa

    var body: some View {
        VStack(spacing: 0) {
            Image(systemName: "bell.fill").font(.system(size: 44)).foregroundStyle(wa.night ? wa.mut : AppColors.textMuted)
                .frame(width: 100, height: 100)
                .background(wa.night ? wa.darkWell : AppColors.primary.opacity(0.08), in: Circle())
            Text(L("notifications_empty_title")).font(.dorr(16, .semibold)).foregroundStyle(wa.settingsInk).padding(.top, 24)
            Text(L("notifications_empty_body")).font(.dorr(14)).foregroundStyle(wa.night ? wa.mut : AppColors.textSecondary)
                .multilineTextAlignment(.center).padding(.top, 8)
        }
        .padding(40)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}
