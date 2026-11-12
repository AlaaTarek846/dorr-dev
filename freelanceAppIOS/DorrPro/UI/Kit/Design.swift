import SwiftUI

// freelanceApp `ui/kit/Design.kt` — the redesign's shared pieces (one look for the tabs): three corner sizes, the section head,
// the parts switch of a hub tab, the hub tab's frame, a number tile and a shortcut tile. Colours come from the appearance
// tokens through the Kit helpers (`\.wa`).

/// The three corners the app uses: small chips and tiles, cards, and the big hero cards (radii; draw them with
/// `RoundedRectangle(cornerRadius: Corners.card, style: .continuous)`).
enum Corners {
    static let small: CGFloat = 12
    static let card: CGFloat = 18
    static let hero: CGFloat = 24
}

/// A section's head: its title on one side and an optional «See all» on the other.
struct SectionHeader: View {
    @Environment(\.wa) private var wa
    let title: String
    var action: String?
    var onAction: (() -> Void)?

    var body: some View {
        HStack(spacing: 0) {
            Text(verbatim: title).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                .frame(maxWidth: .infinity, alignment: .leading)
            if let action, let onAction {
                Button(action: onAction) {
                    HStack(spacing: 0) {
                        Text(verbatim: action).font(.dorr(12, .bold))
                        Image(systemName: "chevron.forward").font(.system(size: 11, weight: .bold)).frame(width: 16, height: 16)
                    }
                    .foregroundStyle(wa.red)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .contentShape(Capsule())
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.top, 6)
    }
}

/// One part of a hub tab: its key, its label, an icon and what waits in it.
struct Segment: Identifiable, Equatable {
    var id: String { key }
    let key: String
    let label: String
    var icon: String?
    var count = 0
}

/// The parts switch under a hub tab's title: a soft track with the chosen part on a raised pill; scrolls sideways when the
/// parts are many.
struct SegmentTabs: View {
    @Environment(\.wa) private var wa
    let segments: [Segment]
    let selected: String
    let onSelect: (String) -> Void

    var body: some View {
        let night = wa.night
        let accent = wa.red
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 4) {
                ForEach(segments) { s in
                    let on = s.key == selected
                    let ink = on ? (night ? Color.white : accent) : wa.settingsMut
                    Button { onSelect(s.key) } label: {
                        HStack(spacing: 0) {
                            if let icon = s.icon {
                                Image(systemName: icon).font(.system(size: 13, weight: .semibold)).frame(width: 16, height: 16)
                                Spacer().frame(width: 6)
                            }
                            Text(verbatim: s.label).font(.dorr(13, on ? .heavy : .semibold)).lineLimit(1).fixedSize()
                            if s.count > 0 {
                                Spacer().frame(width: 6)
                                CountBadge(count: s.count, background: accent, ink: .white)
                            }
                        }
                        .foregroundStyle(ink)
                        .padding(.horizontal, 14)
                        .padding(.vertical, 9)
                        .frame(minHeight: 40)
                        .background(on ? (night ? wa.darkAccent : Color.white) : Color.clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                        .shadow(color: on && !night ? accent.opacity(0.18) : .clear, radius: 4, y: 1)
                        .contentShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    .accessibilityAddTraits(on ? [.isSelected, .isButton] : .isButton)
                    .animation(.easeInOut(duration: 0.2), value: on)
                }
            }
            .padding(4)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(night ? wa.darkCard : accent.opacity(0.07), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
    }
}

/// A hub tab (Opportunities, My work): its title and line, the parts switch, then the open part filling the rest.
struct HubTabFrame<Content: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    let subtitle: String?
    let segments: [Segment]
    let selected: String
    let onSelect: (String) -> Void
    var onBack: (() -> Void)?
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(spacing: 0) {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 0) {
                    Text(verbatim: title).font(.dorr(22, .black)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                    if let onBack { DesignRoundBack(onClick: onBack) }
                }
                if let subtitle {
                    Text(verbatim: subtitle).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 2)
                }
                if segments.count > 1 {
                    SegmentTabs(segments: segments, selected: selected, onSelect: onSelect).padding(.top, 12)
                }
            }
            .padding(.horizontal, 16)
            .padding(.top, 14)
            .padding(.bottom, 10)
            content().frame(maxWidth: .infinity, maxHeight: .infinity)
        }
    }
}

/// The round back button of a hub tab's head (`RoundIcon(ArrowBack, mirrored = true)` of CommunityScreens.kt, same look).
private struct DesignRoundBack: View {
    @Environment(\.wa) private var wa
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            // ArrowBack mirrored: it points to the edge it sits on.
            Image(systemName: "arrow.forward").font(.system(size: 14, weight: .bold)).foregroundStyle(wa.red)
                .frame(width: 36, height: 36)
                .background(wa.settingsCard, in: Circle())
                .overlay { if wa.night { Circle().stroke(wa.line, lineWidth: 1) } }
                .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.08), radius: 6, y: 2)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(L("common_back"))
    }
}

extension View {
    /// `Modifier.appCard(shape, elevation)` — a card of the app: a soft shadow by day, a hairline at night.
    func appCard(_ radius: CGFloat = Corners.card, elevation: CGFloat = 6) -> some View {
        settingsSurface(radius, elevation: elevation)
    }
}

/// A number tile: an icon on its tint, the value large and what it counts under it. Fills the width it is given (Compose
/// `Modifier.weight(1f)` in a row).
struct StatTile: View {
    @Environment(\.wa) private var wa
    let icon: String
    let value: String
    let label: String
    let tint: Color
    var onClick: (() -> Void)?

    var body: some View {
        let tile = VStack(alignment: .leading, spacing: 10) {
            Image(systemName: icon).font(.system(size: 16, weight: .semibold)).foregroundStyle(tint)
                .frame(width: 36, height: 36)
                .background(tint.opacity(wa.night ? 0.22 : 0.12), in: RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: value).font(.dorr(19, .black)).foregroundStyle(wa.settingsInk).lineLimit(1)
                Text(verbatim: label).font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut).lineLimit(1)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .appCard(Corners.card, elevation: 4)
        if let onClick {
            Button(action: onClick) { tile.contentShape(Rectangle()) }.buttonStyle(.plain)
        } else {
            tile
        }
    }
}

/// A shortcut tile of a grid: an icon on a soft square and a short label under it; `count` adds a badge. Fills the width it
/// is given.
struct ShortcutTile: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    /// nil = the app colour (`settingsAccent()`).
    var tint: Color?
    var count = 0
    let onClick: () -> Void

    var body: some View {
        let tint = self.tint ?? wa.red
        Button(action: onClick) {
            VStack(spacing: 0) {
                Image(systemName: icon).font(.system(size: 18, weight: .semibold)).foregroundStyle(tint)
                    .frame(width: 42, height: 42)
                    .background(tint.opacity(wa.night ? 0.22 : 0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .overlay(alignment: .topTrailing) {
                        if count > 0 { CountBadge(count: count, background: wa.red, ink: .white, edge: wa.settingsCard).offset(x: 6, y: -6) }
                    }
                Text(verbatim: label).font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2).multilineTextAlignment(.center)
                    .padding(.top, 8)
            }
            .padding(.vertical, 14)
            .padding(.horizontal, 6)
            .frame(maxWidth: .infinity, minHeight: 84)
            .appCard(Corners.card, elevation: 4)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// A row of the «needs your answer» strip: a coloured dot icon, the line and a chevron.
struct AttentionChip: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String
    let tint: Color
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(spacing: 8) {
                Image(systemName: icon).font(.system(size: 11, weight: .bold)).foregroundStyle(.white)
                    .frame(width: 24, height: 24)
                    .background(tint, in: Circle())
                Text(verbatim: text).font(.dorr(12.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1).fixedSize()
            }
            .padding(.leading, 6)
            .padding(.trailing, 12)
            .padding(.vertical, 6)
            .background(tint.opacity(wa.night ? 0.20 : 0.10), in: Capsule())
            .overlay(Capsule().stroke(tint.opacity(0.25), lineWidth: 1))
            .contentShape(Capsule())
        }
        .buttonStyle(.plain)
    }
}

/// The tints the tiles use besides the app colour (the same in both modes; tiles soften them with alpha).
enum Tints {
    static let blue = Color(hex: 0x3B82F6)
    static let green = Color(hex: 0x10B981)
    static let amber = Color(hex: 0xF59E0B)
    static let violet = Color(hex: 0x8B5CF6)
    static let teal = Color(hex: 0x14B8A6)
    static let rose = Color(hex: 0xF43F5E)
}

/// One choice of a filter row: its key, label, an optional icon, and a green dot when something there waits for me.
struct FilterOption: Identifiable, Equatable {
    var id: String { key }
    let key: String
    let label: String
    var icon: String?
    var dot = false
}

/// The second-level filter of a list (under a hub tab's parts): pills that scroll sideways, the chosen one filled with the
/// app colour and the others outlined — one look for every list of «My work» and «Opportunities».
struct FilterChips: View {
    let options: [FilterOption]
    let selected: String
    let onSelect: (String) -> Void

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(options) { o in
                    PillChip(text: o.label, selected: o.key == selected, icon: o.icon, dot: o.dot) { onSelect(o.key) }
                }
            }
            .padding(.vertical, 1)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

/// A pill: filled with the app colour when chosen, a soft outline otherwise.
struct PillChip: View {
    @Environment(\.wa) private var wa
    let text: String
    let selected: Bool
    var icon: String?
    var dot = false
    let onClick: () -> Void

    var body: some View {
        let accent = wa.red
        let ink = selected ? Color.white : wa.settingsInk
        Button(action: onClick) {
            HStack(spacing: 0) {
                if let icon {
                    Image(systemName: icon).font(.system(size: 12, weight: .semibold)).foregroundStyle(selected ? ink : accent)
                        .frame(width: 15, height: 15)
                    Spacer().frame(width: 6)
                }
                Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(ink).lineLimit(1).fixedSize()
                if dot {
                    Circle().fill(selected ? Color.white : Tints.green).frame(width: 7, height: 7).padding(.leading, 6)
                }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 8)
            .frame(minHeight: 40)
            .background(selected ? accent : wa.settingsCard, in: Capsule())
            .overlay(Capsule().stroke(selected ? accent : wa.settingsMut.opacity(0.22), lineWidth: 1))
            .contentShape(Capsule())
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(selected ? [.isSelected, .isButton] : .isButton)
    }
}

/// A small fact: an icon and a short text (money in the ink colour, the rest muted).
struct FactLine: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String
    var strong = false

    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: icon).font(.system(size: 12, weight: .semibold)).foregroundStyle(strong ? wa.red : wa.settingsMut)
                .frame(width: 15, height: 15)
            Text(verbatim: text).font(.dorr(12.5, strong ? .heavy : .semibold)).foregroundStyle(strong ? wa.settingsInk : wa.settingsMut).lineLimit(1)
        }
    }
}

/// A status pill in its tone.
struct StatusPill: View {
    let text: String
    let tint: Color
    var icon: String?

    var body: some View {
        HStack(spacing: 4) {
            if let icon { Image(systemName: icon).font(.system(size: 10, weight: .bold)).frame(width: 12, height: 12) }
            Text(verbatim: text).font(.dorr(11, .bold)).lineLimit(1)
        }
        .foregroundStyle(tint)
        .padding(.horizontal, 10)
        .padding(.vertical, 4)
        .background(tint.opacity(0.12), in: Capsule())
    }
}

/// One number in a store page's head.
struct HeroNumber: Identifiable {
    var id: String { label }
    let value: String
    let label: String
    var weight: CGFloat = 1
}

/// The head of a store page (my services, consultations, special offers, loyalty) in the home slider's look: the app colour
/// deepening across, soft rings, an icon on a light tile with the title and a line (or `status`), the numbers on light
/// tiles, a white main button and a small line under it.
struct StoreHero: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let body_: String?
    let numbers: [HeroNumber]
    var status: AnyView?
    var action: String?
    var actionIcon: String?
    var actionEnabled = true
    var onAction: (() -> Void)?
    var footnote: String?

    init(icon: String, title: String, body: String?, numbers: [HeroNumber], status: AnyView? = nil, action: String? = nil,
         actionIcon: String? = nil, actionEnabled: Bool = true, onAction: (() -> Void)? = nil, footnote: String? = nil) {
        self.icon = icon
        self.title = title
        self.body_ = body
        self.numbers = numbers
        self.status = status
        self.action = action
        self.actionIcon = actionIcon
        self.actionEnabled = actionEnabled
        self.onAction = onAction
        self.footnote = footnote
    }

    var body: some View {
        let accent = wa.red
        let deep = accent.scaled(0.62)
        VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 20, weight: .semibold)).foregroundStyle(.white)
                    .frame(width: 46, height: 46)
                    .background(.white.opacity(0.18), in: RoundedRectangle(cornerRadius: 15, style: .continuous))
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: title).font(.dorr(18, .black)).foregroundStyle(.white).lineLimit(1)
                    if let status {
                        status
                    } else if let body_ {
                        Text(verbatim: body_).font(.dorr(12)).foregroundStyle(.white.opacity(0.88)).lineSpacing(3)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            if !numbers.isEmpty {
                HeroWeightedRow(spacing: 8, weights: numbers.map(\.weight)) {
                    ForEach(numbers) { n in
                        VStack(spacing: 2) {
                            Text(verbatim: n.value).font(.dorr(18, .black)).foregroundStyle(.white).lineLimit(1)
                            Text(verbatim: n.label).font(.dorr(10.5, .semibold)).foregroundStyle(.white.opacity(0.85)).lineLimit(1)
                        }
                        .padding(.vertical, 10)
                        .padding(.horizontal, 6)
                        .frame(maxWidth: .infinity)
                        .background(.white.opacity(0.14), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                }
            }
            if let action, let onAction {
                Button(action: onAction) {
                    HStack(spacing: 8) {
                        if let actionIcon { Image(systemName: actionIcon).font(.system(size: 15, weight: .semibold)).frame(width: 18, height: 18) }
                        Text(verbatim: action).font(.dorr(15, .heavy))
                    }
                    .foregroundStyle(accent)
                    .frame(maxWidth: .infinity, minHeight: 48, maxHeight: 48)
                    .background(.white.opacity(actionEnabled ? 1 : 0.6), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .contentShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
                }
                .buttonStyle(.plain)
                .disabled(!actionEnabled)
            }
            if let footnote {
                Text(verbatim: footnote).font(.dorr(11.5, .semibold)).foregroundStyle(.white.opacity(0.9))
            }
        }
        .padding(18)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background {
            ZStack {
                LinearGradient(colors: [accent, deep], startPoint: .topLeading, endPoint: .bottomTrailing)
                Circle().fill(.white.opacity(0.07)).frame(width: 140, height: 140)
                    .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topTrailing).offset(x: 20, y: -20)
                Circle().fill(.white.opacity(0.06)).frame(width: 110, height: 110)
                    .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottomLeading)
            }
        }
        .clipShape(RoundedRectangle(cornerRadius: Corners.hero, style: .continuous))
    }
}

/// A row whose children share the width by their weights (Compose `Modifier.weight(n.weight)`).
private struct HeroWeightedRow: Layout {
    let spacing: CGFloat
    let weights: [CGFloat]

    private func widths(_ total: CGFloat, count: Int) -> [CGFloat] {
        let ws = (0..<count).map { $0 < weights.count ? max(weights[$0], 0.0001) : 1 }
        let sum = ws.reduce(0, +)
        let free = max(total - spacing * CGFloat(max(count - 1, 0)), 0)
        return ws.map { free * $0 / sum }
    }

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let total = proposal.width ?? 300
        let ws = widths(total, count: subviews.count)
        let height = zip(subviews, ws).map { $0.sizeThatFits(ProposedViewSize(width: $1, height: nil)).height }.max() ?? 0
        return CGSize(width: total, height: height)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        let ws = widths(bounds.width, count: subviews.count)
        var x = bounds.minX
        for (sub, w) in zip(subviews, ws) {
            sub.place(at: CGPoint(x: x, y: bounds.minY), proposal: ProposedViewSize(width: w, height: bounds.height))
            x += w + spacing
        }
    }
}

/// The store's pages, with the country feature each needs.
let STORE_PAGES: [(String, String)] = [("services", "services"), ("consultations", "consultations"), ("special_offers", "special_offers"), ("loyalty", "loyalty")]

/// The switch at the top of a store page: one pill per page of my store (those on in my country), the open one filled; a tap
/// swaps the page in place.
struct StoreSwitcher: View {
    @ObservedObject private var hub = HomeHub.shared
    let current: String
    let onOpen: (String) -> Void

    var body: some View {
        let home = hub.home
        let pages = STORE_PAGES.filter { _, feature in home.map { $0.on(feature) } != false }.map(\.0)
        if pages.count >= 2 {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(pages, id: \.self) { key in
                        PillChip(text: L(Self.label(key)), selected: key == current, icon: Self.icon(key)) {
                            if key != current { onOpen(key) }
                        }
                    }
                }
                .padding(.vertical, 1)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }

    private static func label(_ key: String) -> String {
        switch key {
        case "services": return "svc_home_card"
        case "consultations": return "cns_home_card"
        case "special_offers": return "spo_home_card"
        default: return "loy_home_card"
        }
    }

    private static func icon(_ key: String) -> String {
        switch key {
        case "services": return "storefront.fill"
        case "consultations": return "video.fill"
        case "special_offers": return "tag.fill"
        default: return "heart.circle.fill"
        }
    }
}
