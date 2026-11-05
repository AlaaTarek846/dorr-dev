import SwiftUI

// driverApp `ui/NotificationsScreen.kt` (+ `NotificationBell`, the bell of Home and Account).

/**
 * The captain's notifications — the same list the rider has (wallet, rides, support, Wasl…), in the
 * app's language. A new one arrives on the captain's real-time channel and appears at the top by
 * itself; opening one marks it read; the bell on Home carries the unread count.
 */
struct NotificationsScreen: View {
    let onBack: () -> Void
    let onOpen: (NotificationDto) -> Void

    @Environment(\.pal) private var pal
    @ObservedObject private var live = DriverLive.shared
    @State private var items: [NotificationDto]?
    @State private var error: String?

    init(onBack: @escaping () -> Void, onOpen: @escaping (NotificationDto) -> Void) {
        self.onBack = onBack
        self.onOpen = onOpen
    }

    private func load() async {
        switch await DriverApi.notifications() {
        case .ok(let list, _): items = list; error = nil
        case .failed(let e): error = e.displayMessage
        case .offline: error = nil
        }
        DriverLive.shared.refreshUnread()
    }

    var body: some View {
        ScreenScaffold(L("nt_title"), onBack: onBack, actions: {
            // D-303: clear every read notification.
            if (items ?? []).contains(where: { $0.readAt != nil }) {
                RoundIconButton("trash.slash.fill", L("nt_clear_read")) {
                    Task {
                        if case .ok = await DriverApi.clearReadNotifications() { items = items?.filter { $0.readAt == nil } }
                    }
                }
            }
            if live.unread > 0 {
                RoundIconButton("checkmark.circle.fill", L("nt_read_all")) {
                    Task {
                        if let r = await DriverApi.markAllRead().value { DriverLive.shared.setUnread(r.count ?? 0) }
                        items = items?.map { n in var n = n; n.readAt = n.readAt ?? "now"; return n }
                    }
                }
                .transition(.opacity)
            }
        }) {
            if let list = items {
                if list.isEmpty {
                    VStack(spacing: 0) {
                        HeroBadge("bell.fill", tint: pal.orange, size: 76)
                        VSpace(10)
                        Text(L("nt_empty")).font(.dorr(16)).foregroundStyle(pal.ink.opacity(0.6))
                    }
                    .padding(32)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else {
                    ScrollView {
                        LazyVStack(spacing: 10) {
                            VSpace(6)
                            ForEach(Array(list.enumerated()), id: \.element.id) { _, n in
                                HStack(spacing: 0) {
                                    NotificationRow(n: n) {
                                        if n.readAt == nil {
                                            Task {
                                                if let r = await DriverApi.markRead(id: n.id ?? "").value { DriverLive.shared.setUnread(r.count ?? 0) }
                                                items = items?.map { it in
                                                    guard it.id == n.id else { return it }
                                                    var it = it
                                                    it.readAt = "now"
                                                    return it
                                                }
                                            }
                                        }
                                        onOpen(n)
                                    }
                                    .frame(maxWidth: .infinity)
                                    // D-303: delete one.
                                    DorrIcon("xmark", size: 20, tint: pal.ink.opacity(0.45))
                                        .padding(.leading, 6)
                                        .contentShape(Rectangle())
                                        .onTapGesture {
                                            Task {
                                                if case .ok = await DriverApi.deleteNotification(id: n.id ?? "") { items = items?.filter { $0.id != n.id } }
                                            }
                                        }
                                        .accessibilityLabel(L("nt_delete"))
                                }
                            }
                            VSpace(16)
                        }
                        .padding(.horizontal, 16)
                    }
                }
            } else {
                ZStack {
                    if let error {
                        Text(error).font(.dorr(16)).foregroundStyle(pal.danger).multilineTextAlignment(.center).padding(24)
                    } else {
                        CircularProgress(color: pal.orange, size: 40, lineWidth: 4)
                    }
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .animation(.easeInOut(duration: 0.2), value: live.unread > 0)
        .task { await load() }
        // A notification row arrived (any event that isn't just "something changed"): the list refreshes.
        .onReceive(DriverLive.shared.events) { e in
            if e.name != DriverLive.driverChanged && e.name != "ride.changed" && e.name != "ride.message" && e.name != "ride.driver_location" {
                Task { await load() }
            }
        }
    }
}

private struct NotificationRow: View {
    @Environment(\.pal) private var pal
    let n: NotificationDto
    let onClick: () -> Void

    var body: some View {
        let unread = n.readAt == nil
        let (icon, tint) = iconFor(n.event)
        HStack(alignment: .top, spacing: 0) {
            TileIcon(icon, tint: tint, box: 42)
            HSpace(12)
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 0) {
                    Text(n.title ?? "").font(.dorr(15, unread ? .heavy : .semibold)).foregroundStyle(pal.ink)
                        .lineLimit(1).truncationMode(.tail)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    Text(ago(n.createdAtIso)).font(.dorr(11)).foregroundStyle(pal.ink.opacity(0.5))
                }
                Text(n.message ?? "").font(.dorr(13)).foregroundStyle(pal.ink.opacity(0.75))
                    .lineLimit(3).truncationMode(.tail)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if unread {
                Circle().fill(pal.orange).frame(width: 9, height: 9).padding(.leading, 8).padding(.top, 6)
            }
        }
        .padding(14)
        .background(unread ? pal.orange.opacity(0.08) : Color.clear)
        .surface(18, elevation: 4)
        .bounceClick(onClick: onClick)
    }

    private func iconFor(_ event: String?) -> (String, Color) {
        guard let event else { return ("bell.fill", pal.navy) }
        if event.hasPrefix("wallet") || event.contains("earnings") { return ("wallet.pass.fill", pal.success) }
        if event.contains("sos") || event.contains("safety") || event.contains("contact") { return ("shield.fill", Color(hex: 0xA32D2D)) }
        if event.hasPrefix("support") { return ("headphones", pal.navy) }
        if event.hasPrefix("ride") { return ("car.fill", pal.orange) }
        return ("bell.fill", pal.navy)
    }
}

/** "now", "5 min", "3 h", "2 d" — short, in the app's language. */
private func ago(_ iso: String?) -> String {
    guard let at = parseInstant(iso) else { return "" }
    let minutes = max(0, Int(Date().timeIntervalSince(at) / 60))
    if minutes < 1 { return L("nt_now") }
    if minutes < 60 { return L("nt_min", minutes) }
    if minutes < 1440 { return L("nt_hour", minutes / 60) }
    return L("nt_day", minutes / 1440)
}

/** The bell: a round button with the unread count in a badge (Home and Account). */
struct NotificationBell: View {
    @Environment(\.pal) private var pal
    @ObservedObject private var live = DriverLive.shared
    let onClick: () -> Void

    init(onClick: @escaping () -> Void) { self.onClick = onClick }

    var body: some View {
        let unread = live.unread
        ZStack(alignment: .topTrailing) {
            RoundIconButton("bell.fill", L("nt_title"), onClick: onClick)
            if unread > 0 {
                Text(unread > 99 ? "99+" : String(unread))
                    .font(.dorr(10, .bold)).foregroundStyle(.white)
                    .padding(.horizontal, 5).padding(.vertical, 1)
                    .background(Capsule().fill(pal.danger))
                    .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
        .animation(.tween(200), value: unread > 0)
    }
}
