import SwiftUI

// androidApp `freelance/FreelanceDesign.kt` — the look the redesigned freelance pages share (the client's side, see
// docs/modules/freelance/CLIENT-APP-REDESIGN-PLAN.md): the head of a page, the head of a section, the tiles of the hub and a
// small count. The app's own colour (`wa.accent`) and its light / dark surfaces; nothing new in colour.

enum FlCorners {
    static let card: CGFloat = 20
    static let tile: CGFloat = 16
    static let icon: CGFloat = 14
}

/// The head of every freelance page: the title (and a quiet line under it when there is one), an action of the page, and the
/// way back — the same button as the app's other pages.
struct FlPageHeader: View {
    @Environment(\.wa) private var wa
    let title: String
    let onBack: () -> Void
    var subtitle: String?
    var trailing: AnyView?

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            SubHeader(title: title, onBack: onBack, trailing: trailing)
            if let subtitle, !subtitle.isEmpty {
                Text(verbatim: subtitle).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                    .padding(.horizontal, 16).padding(.bottom, 6).offset(y: -6)
            }
        }
    }
}

/// A small filled action in a page's head (e.g. «New project»).
struct FlHeaderAction: View {
    @Environment(\.wa) private var wa
    let text: String
    let icon: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 5) {
                Image(systemName: icon).font(.system(size: 13, weight: .bold))
                Text(verbatim: text).font(.dorr(12.5, .heavy)).lineLimit(1)
            }
            .foregroundStyle(.white)
            .padding(.horizontal, 12).padding(.vertical, 7)
            .frame(minHeight: 34)
            .background(wa.accent, in: Capsule())
        }
        .buttonStyle(.plain)
    }
}

/// The head of a section on a page: its name, and «see all» (or another action) on the other side when there is one.
struct FlSectionHeader: View {
    @Environment(\.wa) private var wa
    let title: String
    var action: String?
    var onAction: () -> Void = {}

    var body: some View {
        HStack {
            Text(verbatim: title).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
            Spacer(minLength: 8)
            if let action {
                Button(action: onAction) {
                    Text(verbatim: action).font(.dorr(13, .bold)).foregroundStyle(wa.accent).padding(.horizontal, 8).padding(.vertical, 4)
                }
                .buttonStyle(.plain)
            }
        }
        .accessibilityElement(children: .combine)
    }
}

/// A count on a tile or a button (what waits there); nothing below 1.
struct FlCountBadge: View {
    let count: Int

    var body: some View {
        if count > 0 {
            Text(verbatim: count > 99 ? "99+" : "\(count)").font(.dorr(10.5, .heavy)).foregroundStyle(.white).lineLimit(1)
                .padding(.horizontal, 6).padding(.vertical, 1)
                .background(flRed, in: Capsule())
        }
    }
}

/// A big tile of the hub: an icon on its own colour, the name of the section and what is in it.
struct FlHubTile: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let line: String
    let tint: Color
    var badge = 0
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(alignment: .top) {
                    Image(systemName: icon).font(.system(size: 19, weight: .semibold)).foregroundStyle(tint)
                        .frame(width: 44, height: 44)
                        .background(tint.opacity(0.14), in: RoundedRectangle(cornerRadius: FlCorners.icon, style: .continuous))
                    Spacer(minLength: 0)
                    FlCountBadge(count: badge)
                }
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: title).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Text(verbatim: line).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(2).fixedSize(horizontal: false, vertical: true)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(14)
            .settingsSurface(FlCorners.card)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .combine)
    }
}

/// A small tile of a tool: an icon over its name, a count on the corner when something waits.
struct FlToolTile: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let tint: Color
    var badge = 0
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(spacing: 8) {
                Image(systemName: icon).font(.system(size: 17, weight: .semibold)).foregroundStyle(tint)
                    .frame(width: 40, height: 40)
                    .background(tint.opacity(0.14), in: RoundedRectangle(cornerRadius: FlCorners.icon, style: .continuous))
                    .overlay(alignment: .topTrailing) { FlCountBadge(count: badge).offset(x: 8, y: -6) }
                Text(verbatim: title).font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2).multilineTextAlignment(.center)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 14).padding(.horizontal, 6)
            .settingsSurface(FlCorners.tile, elevation: 4)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .combine)
    }
}

/// A number with its name in a tile (the hub's numbers): an icon on its colour, the figure big, the name under it; opens its page.
struct FlStatTile: View {
    @Environment(\.wa) private var wa
    let icon: String
    let value: String
    let label: String
    let tint: Color
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(alignment: .leading, spacing: 6) {
                Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(tint)
                    .frame(width: 32, height: 32).background(tint.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                Text(verbatim: value).font(.dorr(19, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1).minimumScaleFactor(0.7)
                Text(verbatim: label).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineLimit(2)
            }
            .frame(width: 118, alignment: .leading)
            .padding(12)
            .settingsSurface(FlCorners.tile, elevation: 4)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .combine)
    }
}

/// The parts of a list (its statuses): side by side as tabs on a page of its own, as a row of chips inside a hub page (one row of
/// tabs is enough).
struct FlPartTabs: View {
    let tabs: [FlTab]
    let selected: Int
    var compact = false
    let onSelect: (Int) -> Void

    var body: some View {
        if compact {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(tabs.indices, id: \.self) { i in
                        let t = tabs[i]
                        FlChip(text: t.label + (t.badge > 0 ? "  \(t.badge)" : ""), selected: i == selected, icon: t.icon) { onSelect(i) }
                    }
                }
                .padding(.horizontal, 14).padding(.vertical, 8)
            }
        } else {
            FlTabs(tabs: tabs, selected: selected, onSelect: onSelect).padding(.horizontal, 16).padding(.vertical, 4)
        }
    }
}

/// androidApp `FlCountryTag` — where a service or a consultation comes from: a small flag and the country's name. Freelance work is
/// open across countries (owner, 2026-11-04), so the market mixes countries and this says which. Nothing when the server did not say.
struct FlCountryTag: View {
    @Environment(\.wa) private var wa
    let country: FlCountryRefDto?

    var body: some View {
        if let c = country {
            HStack(spacing: 5) {
                let iso = (c.code ?? "").trimmingCharacters(in: .whitespaces).lowercased()
                if iso.count == 2 {
                    AsyncImage(url: URL(string: "https://flagcdn.com/w40/\(iso).png")) { phase in
                        if let image = phase.image { image.resizable().scaledToFill() } else { Color.gray.opacity(0.12) }
                    }
                    .frame(width: 18, height: 13)
                    .clipShape(RoundedRectangle(cornerRadius: 2))
                }
                Text(verbatim: c.name ?? c.code ?? "").font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut).lineLimit(1)
            }
        }
    }
}

/// A status with a dot before it, on its colour (filled when `solid`): orders, consultations, subscriptions.
struct FlDotPill: View {
    @Environment(\.wa) private var wa
    let text: String
    let color: Color
    var solid = false

    var body: some View {
        HStack(spacing: 6) {
            Circle().fill(solid ? Color.white : color).frame(width: 6, height: 6)
            Text(verbatim: text).font(.dorr(11.5, .bold)).foregroundStyle(solid ? .white : color).lineLimit(1)
        }
        .padding(.horizontal, 10).padding(.vertical, 4)
        .background(solid ? color : color.opacity(wa.night ? 0.22 : 0.12), in: Capsule())
    }
}

/// The tints the hub gives its sections (each section keeps its colour wherever it shows).
enum FlTints {
    static let projects = Color(hex: 0x3B82F6)
    static let services = Color(hex: 0x14B8A6)
    static let consultations = Color(hex: 0x8B5CF6)
    static let orders = Color(hex: 0xF59E0B)
    static let special = Color(hex: 0xF43F5E)
    static let loyalty = Color(hex: 0xD97706)
    static let favorites = Color(hex: 0xEC4899)
    static let mine = Color(hex: 0x0EA5E9)
}
