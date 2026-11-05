import Combine
import SwiftUI
import UIKit

// freelanceApp `ui/components/AppShell.kt`, `BottomBar.kt`.

/// One tab of the bottom bar: its `key` (see [Tabs]), and a count of what waits there (`badge`, 0 = none).
struct BottomTab {
    let key: String
    let label: String
    let icon: String
    let activeIcon: String
    var badge = 0
}

/// The tabs shown now: the community's only while it is on in my country (Android `shellTabs()`; reads [HomeHub] and
/// [CommunityHub], so a view showing them observes both).
@MainActor
var shellTabs: [BottomTab] {
    let home = HomeHub.shared.home
    let attention = home?.attention
    let withCommunity = home?.on("community") == true || CommunityHub.shared.available == true
    var tabs = [
        BottomTab(key: Tabs.HOME, label: L("tab_home"), icon: "house", activeIcon: "house.fill"),
        BottomTab(key: Tabs.OPPORTUNITIES, label: L("tab_opportunities"), icon: "safari", activeIcon: "safari.fill",
                  badge: attention?.invitationsPending ?? 0),
        BottomTab(key: Tabs.WORK, label: L("tab_work"), icon: "briefcase", activeIcon: "briefcase.fill",
                  badge: (attention?.ordersAwaiting ?? 0) + (attention?.consultationsPending ?? 0) + (attention?.revisions ?? 0)),
    ]
    if withCommunity {
        tabs.append(BottomTab(key: Tabs.COMMUNITY, label: L("tab_community"), icon: "bubble.left.and.bubble.right", activeIcon: "bubble.left.and.bubble.right.fill"))
    }
    tabs.append(BottomTab(key: Tabs.ME, label: L("tab_me"), icon: "person", activeIcon: "person.fill"))
    return tabs
}

/// Whether the keyboard is up: the bottom bar steps aside so the fields and the save button keep their room.
@MainActor
final class KeyboardState: ObservableObject {
    static let shared = KeyboardState()
    @Published private(set) var visible = false
    private var bag = Set<AnyCancellable>()

    private init() {
        NotificationCenter.default.publisher(for: UIResponder.keyboardWillShowNotification)
            .sink { [weak self] _ in self?.visible = true }.store(in: &bag)
        NotificationCenter.default.publisher(for: UIResponder.keyboardWillHideNotification)
            .sink { [weak self] _ in self?.visible = false }.store(in: &bag)
    }
}

/// The header of the tabs: the photo (a tap opens «Me»), a greeting for the time of day and the name with the verified tick,
/// then the wallet and the bell with the number of unread notices.
struct AppHeader: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var session = Session.shared
    @ObservedObject private var realtime = Realtime.shared
    let onOpenWallet: () -> Void
    let onOpenNotifications: () -> Void
    var onOpenMe: () -> Void = {}

    var body: some View {
        let accent = wa.red
        let hour = Calendar.current.component(.hour, from: Date())
        HStack(spacing: 0) {
            Button(action: onOpenMe) {
                AvatarImage(url: session.me?.avatarUrl, size: 40, onBrand: true)
                    .padding(2)
                    .overlay(Circle().stroke(.white.opacity(0.55), lineWidth: 2))
                    .contentShape(Circle())
            }
            .buttonStyle(.plain)
            VStack(alignment: .leading, spacing: 0) {
                Text(L(hour < 12 ? "home_greet_morning" : "home_greet_evening")).font(.dorr(11, .semibold)).foregroundStyle(.white.opacity(0.86)).lineLimit(1)
                HStack(spacing: 5) {
                    Text(verbatim: (session.me?.name).flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? L("account_default_user"))
                        .font(.dorr(16, .heavy)).foregroundStyle(.white).lineLimit(1)
                    if session.me?.status == "verified" {
                        Image(systemName: "checkmark").font(.system(size: 7, weight: .black)).foregroundStyle(accent)
                            .frame(width: 15, height: 15)
                            .background(.white, in: Circle())
                            .accessibilityLabel(L("account_verified"))
                    }
                }
            }
            .padding(.leading, 10)
            .padding(.trailing, 8)
            Spacer(minLength: 0)
            HeaderButton(icon: "wallet.pass.fill", description: L("wl_title"), action: onOpenWallet)
            HeaderButton(icon: "bell.fill", description: L("notifications_title"), count: realtime.unread, action: onOpenNotifications).padding(.leading, 8)
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
        .background(
            LinearGradient(colors: [accent, accent.scaled(0.74)], startPoint: .topLeading, endPoint: .bottomTrailing)
                .clipShape(BottomRoundedShape(radius: 24))
                .ignoresSafeArea(edges: .top)
        )
    }
}

/// A round button on the header's colour, with a small count in its corner when there is one.
private struct HeaderButton: View {
    @Environment(\.wa) private var wa
    let icon: String
    let description: String
    var count = 0
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 16, weight: .semibold)).foregroundStyle(.white)
                .frame(width: 38, height: 38)
                .background(.white.opacity(0.18), in: Circle())
                .frame(width: 40, height: 40)
                .overlay(alignment: .topTrailing) {
                    if count > 0 { CountBadge(count: count, background: .white, ink: wa.red, edge: wa.red) }
                }
                .contentShape(Circle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(description)
    }
}

/// A small round count (9+ above nine).
struct CountBadge: View {
    let count: Int
    let background: Color
    let ink: Color
    var edge: Color?

    var body: some View {
        let size: CGFloat = count > 9 ? 20 : 17
        Text(verbatim: count > 9 ? "9+" : String(count))
            .font(.dorr(9, .heavy)).foregroundStyle(ink).lineLimit(1).fixedSize()
            .frame(width: size, height: size)
            .background(background, in: Circle())
            .overlay { if let edge { Circle().stroke(edge, lineWidth: 1.5) } }
            .environment(\.layoutDirection, .leftToRight)
    }
}

/// A rectangle with only its two bottom corners rounded.
struct BottomRoundedShape: Shape {
    let radius: CGFloat

    func path(in rect: CGRect) -> Path {
        Path(UIBezierPath(roundedRect: rect, byRoundingCorners: [.bottomLeft, .bottomRight], cornerRadii: CGSize(width: radius, height: radius)).cgPath)
    }
}

/// The flat bottom bar: a hairline on top; the open tab gets a soft pill of the app colour behind its icon and a bold label, the
/// others are grey. A tab with something waiting carries a small count.
struct BottomBar: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var appearance: AppearanceState
    let tabs: [BottomTab]
    let current: String
    let onSelect: (String) -> Void

    var body: some View {
        let barColor = wa.night ? wa.bg : appearance.color("background", .white, dark: false)
        let accent = wa.red
        HStack(spacing: 0) {
            ForEach(tabs, id: \.key) { tab in
                let selected = tab.key == current
                let tint = selected ? accent : Color(hex: 0x8E9BAE)
                Button {
                    if tab.key != current { UISelectionFeedbackGenerator().selectionChanged() }
                    onSelect(tab.key)
                } label: {
                    VStack(spacing: 3) {
                        ZStack {
                            Capsule().fill(accent.opacity(wa.night ? 0.22 : 0.12)).frame(width: selected ? 52 : 0, height: 30)
                            Image(systemName: selected ? tab.activeIcon : tab.icon).font(.system(size: 19, weight: selected ? .semibold : .regular))
                                .frame(width: 23, height: 23)
                                .overlay(alignment: .topTrailing) {
                                    if tab.badge > 0 {
                                        CountBadge(count: tab.badge, background: accent, ink: .white, edge: barColor).offset(x: 12, y: -4)
                                    }
                                }
                        }
                        .frame(height: 30)
                        // A very large phone font would push the labels onto two lines: the bar keeps them at most a step up.
                        Text(verbatim: tab.label).font(.dorr(11, selected ? .heavy : .medium)).lineLimit(1).fixedSize()
                            .dynamicTypeSize(...DynamicTypeSize.xLarge)
                    }
                    .foregroundStyle(tint)
                    .animation(.easeInOut(duration: 0.24), value: selected)
                    .frame(maxWidth: .infinity, minHeight: 60)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(tab.label)
                .accessibilityAddTraits(selected ? [.isSelected, .isButton] : .isButton)
            }
        }
        .padding(.horizontal, 4)
        .frame(height: 66)
        .background(
            barColor
                .overlay(alignment: .top) { Rectangle().fill(wa.night ? .white.opacity(0.10) : .black.opacity(0.07)).frame(height: 1) }
                .shadow(color: .black.opacity(0.10), radius: 8, y: -2)
                .ignoresSafeArea(edges: .bottom)
        )
    }
}
