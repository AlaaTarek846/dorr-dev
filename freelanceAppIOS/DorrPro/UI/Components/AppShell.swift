import Combine
import SwiftUI
import UIKit

// freelanceApp `ui/components/AppShell.kt`, `BottomBar.kt`.

/// One tab of the bottom bar.
struct BottomTab {
    let label: String
    let icon: String
    let activeIcon: String
}

@MainActor
var shellTabs: [BottomTab] {
    [
        BottomTab(label: L("tab_home"), icon: "house", activeIcon: "house.fill"),
        BottomTab(label: L("tab_projects"), icon: "briefcase", activeIcon: "briefcase.fill"),
        BottomTab(label: L("offer_my_offers"), icon: "tag", activeIcon: "tag.fill"),
        BottomTab(label: L("tab_work"), icon: "hammer", activeIcon: "hammer.fill"),
        BottomTab(label: L("tab_account"), icon: "gearshape", activeIcon: "gearshape.fill"),
    ]
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

/// The DORR-app home header: the photo and name on the app colour, then the invitations, the wallet and the bell (with the
/// unread dot).
struct AppHeader: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var session = Session.shared
    @ObservedObject private var realtime = Realtime.shared
    let onOpenWallet: () -> Void
    let onOpenNotifications: () -> Void
    var onOpenInvitations: () -> Void = {}

    var body: some View {
        let accent = wa.red
        HStack(spacing: 0) {
            AvatarImage(url: session.me?.avatarUrl, size: 40, onBrand: true)
            VStack(alignment: .leading, spacing: 0) {
                Text(L("home_welcome")).font(.dorr(11, .semibold)).foregroundStyle(.white.opacity(0.86)).lineLimit(1)
                Text(verbatim: (session.me?.name).flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? L("account_default_user"))
                    .font(.dorr(15, .heavy)).foregroundStyle(.white).lineLimit(1)
            }
            .padding(.leading, 10)
            .padding(.trailing, 8)
            Spacer(minLength: 0)
            // The client invitations to private projects, next to the wallet.
            circle("envelope.badge", label: L("inv_title"), action: onOpenInvitations)
            circle("wallet.pass.fill", label: L("wl_title"), action: onOpenWallet).padding(.leading, 8)
            circle("bell.fill", label: L("notifications_title"), action: onOpenNotifications)
                .overlay(alignment: .topTrailing) {
                    if realtime.unread > 0 {
                        Circle().fill(.white).frame(width: 9, height: 9).overlay(Circle().stroke(accent, lineWidth: 1.5)).padding(5)
                    }
                }
                .padding(.leading, 8)
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 10)
        .background(
            LinearGradient(colors: [accent, accent.scaled(0.78)], startPoint: .topLeading, endPoint: .bottomTrailing)
                .clipShape(BottomRoundedShape(radius: 22))
                .ignoresSafeArea(edges: .top)
        )
    }

    private func circle(_ icon: String, label: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(.white)
                .frame(width: 34, height: 34)
                .background(.white.opacity(0.18), in: Circle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label)
    }
}

/// A rectangle with only its two bottom corners rounded.
struct BottomRoundedShape: Shape {
    let radius: CGFloat

    func path(in rect: CGRect) -> Path {
        Path(UIBezierPath(roundedRect: rect, byRoundingCorners: [.bottomLeft, .bottomRight], cornerRadii: CGSize(width: radius, height: radius)).cgPath)
    }
}

/// The DORR app flat bottom bar: a hairline on top, the app colour for the tab that is open, grey for the others.
struct BottomBar: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var appearance: AppearanceState
    let tabs: [BottomTab]
    let current: Int
    let onSelect: (Int) -> Void

    var body: some View {
        let barColor = wa.night ? wa.bg : appearance.color("background", .white, dark: false)
        HStack(spacing: 0) {
            ForEach(Array(tabs.enumerated()), id: \.offset) { index, tab in
                let selected = index == current
                let tint = selected ? wa.red : Color(hex: 0x8E9BAE)
                Button { onSelect(index) } label: {
                    VStack(spacing: 4) {
                        Image(systemName: selected ? tab.activeIcon : tab.icon).font(.system(size: 19, weight: selected ? .semibold : .regular))
                            .frame(height: 23)
                        Text(verbatim: tab.label).font(.dorr(11, selected ? .bold : .medium)).lineLimit(1)
                    }
                    .foregroundStyle(tint)
                    .frame(maxWidth: .infinity, minHeight: 58)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(tab.label)
            }
        }
        .padding(.horizontal, 4)
        .frame(height: 64)
        .background(
            barColor
                .overlay(alignment: .top) { Rectangle().fill(wa.night ? .white.opacity(0.10) : .black.opacity(0.07)).frame(height: 1) }
                .shadow(color: .black.opacity(0.10), radius: 8, y: -2)
                .ignoresSafeArea(edges: .bottom)
        )
    }
}
