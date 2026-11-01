import UIKit
import SwiftUI

// androidApp `moments/MomentsScreen.kt` + `MomentEffects.kt` + `components/DarkHero.kt`.
// DORR Moments: the home page's banner, the Moments page (on now, coming, my own dates) and its preferences.
// Occasions come from the server — nothing here knows any occasion by name. The greeting card composer, group cards
// and capsules (sent inside chats) are in `MomentCards.swift` and `MomentsTogether.swift`.

/// The Moments Center, shared by the home banner and the page (one load, kept fresh).
@MainActor
final class MomentsStore: ObservableObject {
    static let shared = MomentsStore()
    @Published var center: MomentsCenterDto?

    func refresh() async {
        if let fresh = try? await MomentsApi.center().data { center = fresh }
    }
}

private func dateLabel(_ iso: String?) -> String {
    guard let iso, !iso.trimmingCharacters(in: .whitespaces).isEmpty else { return "" }
    let raw = String(iso.trimmingCharacters(in: .whitespaces).prefix(10)).replacingOccurrences(of: "/", with: "-")
    let parts = raw.split(separator: "-").compactMap { Int($0.prefix(4)) }
    guard parts.count == 3, let date = Calendar(identifier: .gregorian).date(from: DateComponents(year: parts[0], month: parts[1], day: parts[2])) else {
        return iso
    }
    let f = DateFormatter()
    // java.time keeps Western digits in Arabic too.
    f.locale = Locale(identifier: AppLocale.current + "@numbers=latn")
    f.calendar = Calendar(identifier: .gregorian)
    f.dateStyle = .medium
    return f.string(from: date)
}

private func whenLabel(_ daysLeft: Int, _ isToday: Bool) -> String {
    if isToday || daysLeft == 0 { return L("mo_today") }
    if daysLeft == 1 { return L("mo_tomorrow") }
    return L("mo_in_days", daysLeft)
}

// MARK: - The look of an occasion

/// "#1E3A8A" → Color (a bad value falls back).
func momentColor(_ hex: String?, _ fallback: Color) -> Color {
    Color(hexString: hex) ?? fallback
}

func momentGradient(_ primary: String?, _ secondary: String?) -> LinearGradient {
    LinearGradient(colors: [momentColor(primary, Color(hex: 0x001B53)), momentColor(secondary, Color(hex: 0xFA7552))],
                   startPoint: .topLeading, endPoint: .bottomTrailing)
}

/// Particles over a card or banner. `effects`: full · light · off (the person's choice) — "off" draws nothing.
struct MomentEffect: View {
    let animation: String?
    let emoji: String?
    let primary: String?
    let secondary: String?
    let effects: String

    private enum Motion { case fall, rise, twinkle }

    var body: some View {
        if effects != "off" {
            let count = effects == "light" ? 6 : 14
            let glyph: String? = {
                switch animation {
                case "hearts": return "❤️"
                case "petals": return "🌸"
                case "snow": return "❄️"
                case "balloons": return "🎈"
                case "lanterns": return "🏮"
                case "stars": return "⭐"
                case "sparkles", "fireworks": return "✨"
                case "flags": return emoji ?? "🎉"
                case "confetti": return nil
                default: return "✨"
                }
            }()
            let motion: Motion = {
                switch animation {
                case "balloons", "lanterns": return .rise
                case "stars", "sparkles", "fireworks": return .twinkle
                default: return .fall
                }
            }()
            let colors = [momentColor(primary, Color(hex: 0x001B53)), momentColor(secondary, Color(hex: 0xFA7552)), Color.white, Color(hex: 0xFBBF24)]
            GeometryReader { proxy in
                let w = proxy.size.width, h = proxy.size.height
                TimelineView(.animation) { context in
                    let now = context.date.timeIntervalSinceReferenceDate
                    ZStack(alignment: .topLeading) {
                        ForEach(0..<count, id: \.self) { i in
                            let duration = Double(3200 + (i * 397) % 2600) / 1000
                            let offset = Double((i * 330) % Int(duration * 1000)) / 1000
                            let p = ((now + offset).truncatingRemainder(dividingBy: duration)) / duration
                            let x = w * CGFloat((i * 37) % 100) / 100
                            let baseY = h * CGFloat((i * 53) % 100) / 100
                            let y: CGFloat = motion == .fall ? -24 + (h + 48) * p : (motion == .rise ? h + 24 - (h + 48) * p : baseY)
                            let sway = CGFloat(sin(p * 2 * .pi + Double(i))) * 10
                            let alpha = motion == .twinkle ? sin(p * .pi) : 0.85
                            let scale = motion == .twinkle ? 0.6 + 0.7 * sin(p * .pi) : 1
                            let rotation = motion == .fall ? p * 360 * (i % 2 == 0 ? 1 : -1) : 0
                            Group {
                                if let glyph {
                                    Text(verbatim: glyph).font(.system(size: CGFloat(12 + (i % 4) * 3)))
                                } else {
                                    RoundedRectangle(cornerRadius: 2).fill(colors[i % colors.count]).frame(width: 6, height: 11)
                                }
                            }
                            .scaleEffect(scale)
                            .rotationEffect(.degrees(rotation))
                            .opacity(alpha)
                            .offset(x: x + sway, y: y)
                        }
                    }
                    .frame(width: w, height: h, alignment: .topLeading)
                }
            }
            .allowsHitTesting(false)
            .environment(\.layoutDirection, .leftToRight)
        }
    }
}

// MARK: - Dark hero (androidApp components/DarkHero.kt)

/// The page's top panel in the brand gradient, bleeding to the screen edges, rounded at the bottom.
struct DarkHeroPanel<Content: View>: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var appearance: AppearanceState
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 0, content: content)
            .padding(.horizontal, 16)
            .padding(.top, 8)
            .padding(.bottom, 12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(LinearGradient(colors: colors, startPoint: .top, endPoint: .bottom))
            .clipShape(BottomRoundedRectangle(radius: 36))
            .padding(.horizontal, -16)
    }

    private var colors: [Color] {
        if wa.night {
            let accent = appearance.color("primary", Color(hex: 0xFA7552), dark: true)
            return [accent, accent.scaled(0.78), accent.scaled(0.45)]
        }
        let primary = appearance.color("primary", Color(hex: 0x001B53), dark: false)
        let light = appearance.color("primaryLight", Color(hex: 0x1E3A7B), dark: false)
        let dark = appearance.color("primaryDark", Color(hex: 0x00113A), dark: false)
        let accent = appearance.color("accent", Color(hex: 0xFA7552), dark: false)
        var lr: CGFloat = 0, lg: CGFloat = 0, lb: CGFloat = 0, la: CGFloat = 0
        var ar: CGFloat = 0, ag: CGFloat = 0, ab: CGFloat = 0, aa: CGFloat = 0
        UIColor(light).getRed(&lr, green: &lg, blue: &lb, alpha: &la)
        UIColor(accent).getRed(&ar, green: &ag, blue: &ab, alpha: &aa)
        let top = Color(red: Double(min(lr * 0.78 + ar * 0.22, 1)), green: Double(min(lg * 0.78 + ag * 0.22, 1)), blue: Double(min(lb * 0.78 + ab * 0.22, 1)))
        return [top, primary, dark]
    }
}

struct DarkHeroButton: View {
    let icon: String
    let label: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 14, weight: .bold)).foregroundStyle(.white)
                .frame(width: 34, height: 34)
                .background(Color.white.opacity(0.12), in: Circle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label)
    }
}

// MARK: - Home banner

/// On the home page: the occasion that's on (its colours, emoji and animation) — or the next one soon, as a
/// slim strip. Nothing when Moments are off.
@MainActor
struct MomentsBanner: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var store = MomentsStore.shared
    let onOpen: () -> Void

    var body: some View {
        // A VStack, not a Group: an empty Group has no view to run the load task on.
        VStack(spacing: 0) {
            if let center = store.center, center.enabled {
                if let active = center.active.first(where: \.isToday) ?? center.active.first {
                    Button(action: onOpen) {
                        ZStack {
                            momentGradient(active.primaryColor, active.secondaryColor)
                            if let image = active.cardImage, let url = URL(string: image) {
                                AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFill() }
                            }
                            MomentEffect(animation: active.animation, emoji: active.emoji, primary: active.primaryColor, secondary: active.secondaryColor, effects: center.effects)
                            HStack(spacing: 14) {
                                Text(verbatim: active.emoji ?? "✨").font(.system(size: 44))
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(verbatim: active.isToday ? (active.name ?? "") : L("mo_soon_named", active.name ?? "", whenLabel(active.daysLeft, false)))
                                        .font(.dorr(19, .heavy)).foregroundStyle(.white).lineLimit(2).multilineTextAlignment(.leading)
                                    if let greeting = active.greeting {
                                        Text(verbatim: greeting).font(.dorr(13)).foregroundStyle(.white.opacity(0.9)).lineLimit(2).multilineTextAlignment(.leading)
                                    }
                                }
                                Spacer(minLength: 0)
                            }
                            .padding(18)
                        }
                        .frame(height: 132)
                        .clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous))
                    }
                    .buttonStyle(WaPressStyle(scale: 0.98))
                } else if let strip = stripText(center) {
                    // Nothing on now: the next one within two weeks, as a strip.
                    Button(action: onOpen) {
                        HStack(spacing: 10) {
                            Text(verbatim: strip.emoji).font(.system(size: 20))
                                .frame(width: 40, height: 40)
                                .background(momentGradient(strip.primary, strip.secondary), in: Circle())
                            Text(verbatim: strip.text).font(.dorr(14, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                            Spacer(minLength: 0)
                            Image(systemName: "calendar").font(.system(size: 17)).foregroundStyle(wa.red)
                        }
                        .padding(12)
                        .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(wa.line, lineWidth: 1))
                    }
                    .buttonStyle(WaPressStyle(scale: 0.98))
                }
            }
        }
        .task { await store.refresh() }
    }

    private func stripText(_ center: MomentsCenterDto) -> (emoji: String, primary: String?, secondary: String?, text: String)? {
        let next = center.upcoming.first { $0.daysLeft <= 14 }
        let personal = center.personal.first { $0.daysLeft <= 7 }
        if let next {
            return (next.emoji ?? personal?.look?.emoji ?? "✨", next.primaryColor, next.secondaryColor,
                    L("mo_soon_named", next.name ?? "", whenLabel(next.daysLeft, next.isToday)))
        }
        if let personal {
            return (personal.look?.emoji ?? "✨", personal.look?.primaryColor, personal.look?.secondaryColor,
                    L("mo_soon_named", personal.title, whenLabel(personal.daysLeft, false)))
        }
        return nil
    }
}

// MARK: - The page

private enum MomentsPage: Equatable { case center, settings, collabs, collab(String), capsules, capsule(String) }

@MainActor
struct MomentsScreen: View {
    @Environment(\.wa) private var wa
    let onExit: () -> Void
    @State private var page = MomentsPage.center

    var body: some View {
        ZStack {
            switch page {
            case .center:
                MomentsCenter(onBack: onExit, onSettings: { go(.settings) }, onCollabs: { go(.collabs) }, onCapsules: { go(.capsules) })
                    .transition(.asymmetric(insertion: .modifier(active: MoSlide(fraction: -1.0 / 6), identity: MoSlide(fraction: 0)),
                                            removal: .modifier(active: MoSlide(fraction: -0.1), identity: MoSlide(fraction: 0))))
            case .settings:
                MomentsSettings(onBack: { go(.center) }).transition(forward)
            case .collabs:
                CollabCardsPage(onBack: { go(.center) }, onOpen: { go(.collab($0)) }).transition(forward)
            case .collab(let id):
                CollabCardPage(id: id, onBack: { go(.collabs) }).transition(forward)
            case .capsules:
                CapsulesPage(onBack: { go(.center) }, onOpen: { go(.capsule($0)) }).transition(forward)
            case .capsule(let id):
                CapsulePage(id: id, onBack: { go(.capsules) }).transition(forward)
            }
        }
        .background(wa.bg.ignoresSafeArea())
    }

    private var forward: AnyTransition {
        .asymmetric(insertion: .modifier(active: MoSlide(fraction: 1.0 / 6), identity: MoSlide(fraction: 0)),
                    removal: .modifier(active: MoSlide(fraction: 0.1), identity: MoSlide(fraction: 0)))
    }

    private func go(_ next: MomentsPage) {
        withAnimation(.easeOut(duration: 0.32)) { page = next }
    }
}

private struct MoSlide: ViewModifier {
    let fraction: CGFloat
    func body(content: Content) -> some View {
        GeometryReader { proxy in content.offset(x: proxy.size.width * fraction).opacity(fraction == 0 ? 1 : 0) }
    }
}

@MainActor
private struct MomentsCenter: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var store = MomentsStore.shared
    let onBack: () -> Void
    let onSettings: () -> Void
    let onCollabs: () -> Void
    let onCapsules: () -> Void
    @State private var editing: PersonalMomentDto?
    @State private var adding = false
    @State private var card: CardOpen?

    private struct CardOpen: Identifiable {
        let id = UUID()
        let preset: CardPreset
        let to: ProfileDto?
    }

    var body: some View {
        WaPage(title: L("mo_title"), onBack: onBack, showHeader: false, hasCta: true, actions: { EmptyView() }, cta: {
            WaButton(text: L("mo_add_personal"), icon: "birthday.cake.fill") { adding = true }
        }) {
            DarkHeroPanel {
                HStack {
                    Text(L("mo_title")).font(.dorr(22, .black)).foregroundStyle(.white).lineLimit(1)
                    Spacer(minLength: 8)
                    HStack(spacing: 8) {
                        DarkHeroButton(icon: "gearshape.fill", label: L("mo_settings"), action: onSettings)
                        DarkHeroButton(icon: "arrow.forward", label: L("common_back"), action: onBack)
                    }
                }
            }
            Spacer().frame(height: 12)
            if let center = store.center {
                if !center.enabled {
                    WaCard {
                        WaEmpty(icon: "calendar", tone: .gray, title: L("mo_off_title"), text: L("mo_off_text")) {
                            WaButton(text: L("mo_settings"), style: .ghost, icon: "slider.horizontal.3", action: onSettings)
                        }
                    }
                } else {
                    content(center)
                }
            } else {
                WaSkeleton(cornerRadius: 22).frame(height: 160).padding(.bottom, 12)
                ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 16).frame(height: 62).padding(.bottom, 10) }
            }
        }
        .task { await store.refresh() }
        .sheet(isPresented: $adding) { PersonalSheet(existing: nil) { adding = false }.dorrThemed() }
        .sheet(item: $editing) { p in PersonalSheet(existing: p) { editing = nil }.dorrThemed() }
        .sheet(item: $card) { c in
            MomentCardSheet(conversationId: nil, peerName: c.to?.name, canGift: true, preset: c.preset, toUser: c.to, onDismiss: { card = nil }).chatAppearance()
        }
    }

    @ViewBuilder private func content(_ center: MomentsCenterDto) -> some View {
        ForEach(Array(center.active.enumerated()), id: \.element.id) { i, m in
            ActiveMoment(m: m, effects: center.effects).padding(.bottom, 12).waRise(i)
        }
        // Card / group card / capsules: sent inside chats.
        HStack(spacing: 0) {
            QuickTile(emoji: "💌", label: L("mo_card_title")) { card = CardOpen(preset: CardPreset(), to: nil) }
            MoVLine()
            QuickTile(emoji: "✍️", label: L("mo_collab_title"), action: onCollabs)
            MoVLine()
            QuickTile(emoji: "📦", label: L("mo_capsules"), action: onCapsules)
        }
        .fixedSize(horizontal: false, vertical: true)
        .moHairline()
        .waRise(1)

        if !center.personal.isEmpty {
            MoCenterLabel(text: L("mo_mine")).waRise(2)
            MoListCard {
                ForEach(Array(center.personal.enumerated()), id: \.element.id) { i, p in
                    PersonalRow(p: p, onCard: { card = CardOpen(preset: CardPreset(personalKind: p.kind), to: p.contact) }) { editing = p }
                    if i != center.personal.count - 1 { MoListDivider() }
                }
            }
            .waRise(3)
        }

        let countryName = (center.country?.name ?? "").trimmingCharacters(in: .whitespaces)
        MoCenterLabel(text: countryName.isEmpty ? L("mo_upcoming_plain") : L("mo_upcoming", countryName)).waRise(4)
        let coming = center.upcoming.filter { !$0.isVisible }
        if coming.isEmpty {
            Text(L("mo_none_soon")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.vertical, 8).padding(.horizontal, 2)
        } else {
            MoListCard {
                ForEach(Array(coming.enumerated()), id: \.element.id) { i, m in
                    UpcomingRow(m: m)
                    if i != coming.count - 1 { MoListDivider() }
                }
            }
            .waRise(5)
        }
    }
}

private extension View {
    func moHairline(radius: CGFloat = 16) -> some View { modifier(MoHairline(radius: radius)) }
}

private struct MoHairline: ViewModifier {
    @Environment(\.wa) private var wa
    let radius: CGFloat
    func body(content: Content) -> some View {
        let shape = RoundedRectangle(cornerRadius: radius, style: .continuous)
        content
            .background(wa.night ? wa.darkCard : Color.white, in: shape)
            .clipShape(shape)
            .overlay(shape.stroke(wa.night ? wa.line : Color(hex: 0xE6E8EC), lineWidth: 1))
    }
}

private struct MoVLine: View {
    @Environment(\.wa) private var wa
    var body: some View { (wa.night ? wa.line : Color(hex: 0xE6E8EC)).frame(width: 1) }
}

private struct QuickTile: View {
    @Environment(\.wa) private var wa
    let emoji: String
    let label: String
    let action: () -> Void

    var body: some View {
        Button(action: action) { tile }.buttonStyle(.plain)
    }

    private var tile: some View {
        VStack(spacing: 6) {
            Text(verbatim: emoji).font(.system(size: 18))
                .frame(width: 36, height: 36)
                .background(wa.night ? wa.darkWell : wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            Text(verbatim: label).font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center).lineLimit(2)
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 12)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .contentShape(Rectangle())
    }
}

private struct MoCenterLabel: View {
    @Environment(\.wa) private var wa
    let text: String
    var body: some View {
        Text(verbatim: text).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk)
            .padding(.top, 14).padding(.bottom, 8).padding(.leading, 2)
    }
}

private struct MoListCard<Content: View>: View {
    @ViewBuilder let content: () -> Content
    var body: some View {
        VStack(spacing: 0, content: content).padding(.horizontal, 6).padding(.vertical, 2).frame(maxWidth: .infinity).moHairline()
    }
}

private struct MoListDivider: View {
    @Environment(\.wa) private var wa
    var body: some View { (wa.night ? wa.line : Color(hex: 0xF0F1F4)).frame(height: 1).padding(.horizontal, 8) }
}

private struct MoWhenChip: View {
    @Environment(\.wa) private var wa
    let daysLeft: Int
    let isToday: Bool
    var body: some View {
        let soon = isToday || daysLeft <= 1
        Text(verbatim: whenLabel(daysLeft, isToday)).font(.dorr(11, .bold)).foregroundStyle(soon ? .white : wa.red).lineLimit(1)
            .padding(.horizontal, 8).padding(.vertical, 4)
            .background(soon ? wa.red : wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 8, style: .continuous))
    }
}

private struct ActiveMoment: View {
    let m: MomentDto
    let effects: String

    var body: some View {
        let line = m.isToday ? (m.greeting ?? "") : whenLabel(m.daysLeft, false) + " · " + dateLabel(m.start)
        ZStack {
            momentGradient(m.primaryColor, m.secondaryColor)
            if let image = m.cardImage, let url = URL(string: image) {
                AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFill() }
            }
            MomentEffect(animation: m.animation, emoji: m.emoji, primary: m.primaryColor, secondary: m.secondaryColor, effects: effects)
            LinearGradient(stops: [.init(color: .black.opacity(0.12), location: 0), .init(color: .clear, location: 0.4), .init(color: .black.opacity(0.55), location: 1)],
                           startPoint: .top, endPoint: .bottom)
            VStack(spacing: 0) {
                Text(verbatim: m.emoji ?? "✨").font(.system(size: 36)).shadow(color: .black.opacity(0.55), radius: 5, y: 1)
                Text(verbatim: m.name ?? "").font(.dorr(20, .heavy)).foregroundStyle(.white).multilineTextAlignment(.center).lineLimit(2)
                    .shadow(color: .black.opacity(0.55), radius: 5, y: 1).padding(.top, 6)
                if !line.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: line).font(.dorr(13, .semibold)).foregroundStyle(.white.opacity(0.95)).multilineTextAlignment(.center).lineLimit(2)
                        .shadow(color: .black.opacity(0.55), radius: 5, y: 1).padding(.top, 4)
                }
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 14)
        }
        .frame(height: 172)
        .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
    }
}

private struct UpcomingRow: View {
    @Environment(\.wa) private var wa
    let m: MomentDto
    var body: some View {
        HStack(spacing: 10) {
            Text(verbatim: m.emoji ?? "✨").font(.system(size: 16))
                .frame(width: 34, height: 34)
                .background(momentGradient(m.primaryColor, m.secondaryColor), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: m.name ?? "").font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                Text(verbatim: dateLabel(m.start)).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineLimit(1)
            }
            Spacer(minLength: 8)
            MoWhenChip(daysLeft: m.daysLeft, isToday: m.isToday)
        }
        .padding(.horizontal, 4)
        .padding(.vertical, 10)
    }
}

private struct PersonalRow: View {
    @Environment(\.wa) private var wa
    let p: PersonalMomentDto
    let onCard: () -> Void
    let onClick: () -> Void

    var body: some View {
        let sub = [dateLabel(p.next), p.turns.map { L("mo_turns", $0) }, p.contact?.name].compactMap { $0 }.joined(separator: "  ·  ")
        Button(action: onClick) {
            HStack(spacing: 10) {
                Text(verbatim: p.look?.emoji ?? "✨").font(.system(size: 16))
                    .frame(width: 34, height: 34)
                    .background(momentGradient(p.look?.primaryColor, p.look?.secondaryColor), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: p.title).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Text(verbatim: sub).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
                Spacer(minLength: 8)
                if p.contact != nil {
                    Button(action: onCard) {
                        Text(verbatim: "💌").font(.system(size: 14)).frame(width: 32, height: 32).background(wa.red.opacity(0.12), in: Circle())
                    }
                    .buttonStyle(.plain)
                }
                MoWhenChip(daysLeft: p.daysLeft, isToday: false)
            }
            .padding(.horizontal, 4)
            .padding(.vertical, 10)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - My own dates

private let personalKinds: [(String, String)] = [("birthday", "🎂"), ("anniversary", "💍"), ("graduation", "🎓"), ("wedding", "💒"), ("baby", "👶"), ("other", "✨")]

func personalKindLabel(_ kind: String) -> String {
    switch kind {
    case "birthday": return L("mo_kind_birthday")
    case "anniversary": return L("mo_kind_anniversary")
    case "graduation": return L("mo_kind_graduation")
    case "wedding": return L("mo_kind_wedding")
    case "baby": return L("mo_kind_baby")
    default: return L("mo_kind_other")
    }
}

private func monthName(_ m: Int) -> String {
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.calendar = Calendar(identifier: .gregorian)
    return f.standaloneMonthSymbols[max(min(m, 12), 1) - 1]
}

@MainActor
private struct PersonalSheet: View {
    @Environment(\.wa) private var wa
    let existing: PersonalMomentDto?
    let onDismiss: () -> Void

    @State private var kind = "birthday"
    @State private var title = ""
    @State private var day = ""
    @State private var month = Calendar.current.component(.month, from: Date())
    @State private var year = ""
    @State private var remind = 1
    @State private var contactId: Int?
    @State private var contactName: String?
    @State private var contacts: [ContactDto] = []
    @State private var busy = false
    @State private var error: String?
    @State private var started = false

    private let remindChoices: [(Int, String)] = [(0, "mo_remind_day"), (1, "mo_remind_1"), (2, "mo_remind_2"), (7, "mo_remind_7")]

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                Text(L(existing == nil ? "mo_add_personal" : "mo_edit_personal")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(personalKinds, id: \.0) { k, emoji in
                            let on = kind == k
                            Button { kind = k } label: {
                                Text(verbatim: "\(emoji)  " + personalKindLabel(k)).font(.dorr(13, .bold)).foregroundStyle(on ? .white : wa.ink)
                                    .padding(.horizontal, 12).padding(.vertical, 8)
                                    .background(on ? wa.red : wa.field, in: Capsule())
                                    .animation(.easeInOut(duration: 0.2), value: on)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
                .padding(.top, 12)
                DorrTextArea(value: Binding(get: { title }, set: { title = String($0.prefix(120)) }), label: L("mo_lbl_title"),
                             placeholder: L("mo_title_hint"), icon: "pencil", singleLine: true)
                    .padding(.top, 12)
                HStack(alignment: .bottom, spacing: 8) {
                    DorrTextArea(value: Binding(get: { day }, set: { day = String($0.filter(\.isNumber).prefix(2)) }), label: L("mo_day"),
                                 icon: "calendar", singleLine: true)
                        .keyboardType(.numberPad)
                        .layoutPriority(1)
                    DorrTextArea(value: Binding(get: { year }, set: { year = String($0.filter(\.isNumber).prefix(4)) }), label: L("mo_year"),
                                 placeholder: L("mo_year_optional"), singleLine: true)
                        .keyboardType(.numberPad)
                        .layoutPriority(1.4)
                }
                .padding(.top, 10)
                MoSheetSelect(label: L("mo_month"), value: monthName(month), icon: "calendar.badge.clock",
                              options: (1...12).map { ($0, monthName($0)) }, selected: month) { month = $0 }
                    .padding(.top, 10)
                if !contacts.isEmpty {
                    let none = L("mo_person_none")
                    MoSheetSelect(label: L("mo_person"), value: contactName ?? none, icon: "person.fill",
                                  options: [(-1, none)] + contacts.compactMap { c in c.profile.map { ($0.id, c.name) } },
                                  selected: contactId ?? -1) { id in
                        if id == -1 {
                            contactId = nil
                            contactName = nil
                        } else if let c = contacts.first(where: { $0.profile?.id == id }) {
                            contactId = id
                            contactName = c.name
                            if title.trimmingCharacters(in: .whitespaces).isEmpty { title = c.name }
                        }
                    }
                    .padding(.top, 10)
                }
                MoSheetSelect(label: L("mo_remind"), value: L((remindChoices.first { $0.0 == remind } ?? remindChoices[1]).1), icon: "alarm.fill",
                              options: remindChoices.map { ($0.0, L($0.1)) }, selected: remind) { remind = $0 }
                    .padding(.top, 10)
                WaError(message: error)
                WaButton(text: L("mo_save"), icon: "checkmark.circle.fill",
                         enabled: !title.trimmingCharacters(in: .whitespaces).isEmpty && (1...31).contains(Int(day) ?? 0) && !busy,
                         loading: busy, action: save)
                    .padding(.top, 16)
                if let existing {
                    WaButton(text: L("mo_delete"), style: .quiet, icon: "trash.fill") {
                        Task {
                            _ = try? await MomentsApi.deletePersonal(existing.id)
                            await MomentsStore.shared.refresh()
                            onDismiss()
                        }
                    }
                    .padding(.top, 6)
                }
            }
            .padding(.horizontal, 20)
            .padding(.top, 24)
            .padding(.bottom, 28)
        }
        .scrollDismissesKeyboard(.interactively)
        .background(wa.surface.ignoresSafeArea())
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
        .task {
            guard !started else { return }
            started = true
            if let e = existing {
                kind = e.kind
                title = e.title
                day = String(e.day)
                month = e.month
                year = e.year.map(String.init) ?? ""
                remind = e.remindDaysBefore
                contactId = e.contact?.id
                contactName = e.contact?.name
            }
            contacts = ((try? await ChatContactsApi.contacts(registered: 1).data) ?? []).filter { $0.profile != nil }
        }
    }

    private func save() {
        busy = true
        error = nil
        let body: [String: JSONValue] = [
            "kind": .string(kind),
            "title": .string(title.trimmingCharacters(in: .whitespacesAndNewlines)),
            "month": .int(month),
            "day": .int(Int(day) ?? 1),
            "remind_days_before": .int(remind),
            "year": Int(year).map { .int($0) } ?? .null,
            "contact_id": contactId.map { .int($0) } ?? .null,
        ]
        Task {
            do {
                if let existing { _ = try await MomentsApi.updatePersonal(existing.id, body) } else { _ = try await MomentsApi.addPersonal(body) }
                await MomentsStore.shared.refresh()
                onDismiss()
            } catch {
                self.error = error.apiFailure.message
                busy = false
            }
        }
    }
}

/// androidApp `MoSheetSelect` — a read-only field (label, icon, chevron) that opens a dropdown of choices,
/// the chosen one ticked.
struct MoSheetSelect<ID: Hashable>: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String
    let icon: String
    let options: [(ID, String)]
    let selected: ID
    let onPick: (ID) -> Void
    @State private var open = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 8)
            Button { open = true } label: {
                HStack(spacing: 8) {
                    Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red.opacity(0.8))
                    Text(verbatim: value).font(.dorr(15)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Spacer(minLength: 0)
                    Image(systemName: "chevron.down").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.settingsMut)
                }
                .padding(.horizontal, 14)
                .frame(height: 46)
                .background(wa.night ? wa.bg : Color(hex: 0xFBF7F8), in: Capsule())
                .overlay(Capsule().stroke(wa.night ? wa.line : Color(hex: 0xF3D5DB), lineWidth: 1))
            }
            .buttonStyle(.plain)
            .popover(isPresented: $open, arrowEdge: .top) {
                ScrollView {
                    VStack(spacing: 0) {
                        ForEach(options, id: \.0) { id, text in
                            let on = id == selected
                            Button {
                                open = false
                                onPick(id)
                            } label: {
                                HStack {
                                    Text(verbatim: text).font(.dorr(15, on ? .bold : .medium)).foregroundStyle(on ? wa.red : wa.settingsInk)
                                    Spacer(minLength: 8)
                                    if on { Image(systemName: "checkmark.circle.fill").font(.system(size: 14)).foregroundStyle(wa.red) }
                                }
                                .padding(.horizontal, 16)
                                .padding(.vertical, 12)
                                .contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
                .frame(minWidth: 240, maxHeight: 280)
                .background(wa.night ? wa.darkCard : Color.white)
                .compactPopover()
            }
        }
    }
}

// MARK: - Preferences

@MainActor
private struct MomentsSettings: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var session: AuthSession
    @ObservedObject private var store = MomentsStore.shared
    let onBack: () -> Void
    @State private var catalog: [MomentCatalogItemDto]?
    @State private var countries: [CountryDto] = []
    @State private var error: String?
    @State private var on: Set<Int> = []
    @State private var onEffects = false
    @State private var onCountry = false

    private let effectChoices: [(String, String, String)] = [
        ("full", "mo_effects_full", "sparkles"), ("light", "mo_effects_light", "sun.max.fill"), ("off", "mo_effects_off", "eye.slash.fill"),
    ]

    var body: some View {
        let center = store.center
        let effects = center?.effects ?? "full"
        let effectsLabel = L((effectChoices.first { $0.0 == effects } ?? effectChoices[0]).1)
        let homeCode = session.user?.country?.code
        let countryChoices = countries.sorted { a, b in
            let ah = a.code.caseInsensitiveCompare(homeCode ?? "") == .orderedSame
            let bh = b.code.caseInsensitiveCompare(homeCode ?? "") == .orderedSame
            return ah != bh ? ah : a.name < b.name
        }
        let countryName = center?.country?.name ?? countryChoices.first { $0.id == center?.country?.id }?.name
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L(onEffects ? "mo_effects" : (onCountry ? "mo_country" : "mo_settings"))) {
                    if onEffects || onCountry {
                        onEffects = false
                        onCountry = false
                    } else {
                        onBack()
                    }
                }
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) {
                        if onEffects {
                            MoSettingsCard {
                                ForEach(Array(effectChoices.enumerated()), id: \.offset) { index, choice in
                                    Button { save(["effects": .string(choice.0)]) } label: {
                                        HStack(spacing: 10) {
                                            PinkIcon(icon: choice.2)
                                            Text(L(choice.1)).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                                            Spacer(minLength: 0)
                                            if effects == choice.0 {
                                                Image(systemName: "checkmark.circle.fill").font(.system(size: 18)).foregroundStyle(wa.red)
                                            }
                                        }
                                        .padding(.horizontal, 4).padding(.vertical, 12)
                                        .contentShape(Rectangle())
                                    }
                                    .buttonStyle(.plain)
                                    if index != effectChoices.count - 1 { MoSettingsDivider() }
                                }
                            }
                        } else if onCountry {
                            MoCountryDropdown(countries: countryChoices, selectedId: center?.country?.id, selectedName: countryName) { c in
                                if c.id != center?.country?.id { save(["country_id": .int(c.id)], reloadCatalog: true, catalogCountry: c.id) }
                            }
                            Text(L("mo_which")).font(.dorr(13.5, .heavy)).foregroundStyle(wa.settingsInk).padding(.top, 16).padding(.bottom, 8).padding(.leading, 2)
                            Text(L("mo_which_sub")).font(.dorr(11)).lineSpacing(2).foregroundStyle(wa.settingsMut).padding(.bottom, 8).padding(.leading, 2)
                            WaError(message: error)
                            MoOccasionList(list: catalog, on: on) { m, want in
                                if want { on.insert(m.id) } else { on.remove(m.id) }
                                save([want ? "on" : "off": .array([.int(m.id)])])
                            }
                        } else {
                            MoSettingsCard {
                                MoToggleRow(icon: "party.popper.fill", title: L("mo_enabled"), help: L("mo_enabled_sub"), on: center?.enabled != false, divider: true) {
                                    save(["enabled": .bool($0)])
                                }
                                MoSettingsNavRow(icon: "sparkles", title: L("mo_effects"), value: effectsLabel, divider: true) { onEffects = true }
                                MoSettingsNavRow(icon: "globe", title: L("mo_country"), value: countryName, divider: false) { onCountry = true }
                            }
                        }
                    }
                    .padding(.horizontal, 14)
                    .padding(.top, 6)
                    .padding(.bottom, 16)
                }
            }
        }
        .task {
            await loadCatalog(store.center?.country?.id)
            countries = (try? await CountryApi.list().data) ?? []
        }
    }

    private func loadCatalog(_ countryId: Int?) async {
        catalog = nil
        let list = (try? await MomentsApi.catalog(countryId: countryId).data) ?? []
        catalog = list
        on = Set(list.filter(\.on).map(\.id))
    }

    private func save(_ body: [String: JSONValue], reloadCatalog: Bool = false, catalogCountry: Int? = nil) {
        Task {
            do {
                let fresh = try await MomentsApi.preferences(body).data
                if let fresh { store.center = fresh }
                if reloadCatalog { await loadCatalog(catalogCountry ?? fresh?.country?.id) }
            } catch {
                self.error = error.apiFailure.message
            }
        }
    }
}

private struct MoSettingsCard<Content: View>: View {
    @ViewBuilder let content: () -> Content
    var body: some View {
        VStack(spacing: 0, content: content).padding(.horizontal, 8).padding(.vertical, 4).frame(maxWidth: .infinity).settingsSurface(18, elevation: 8)
    }
}

private struct MoSettingsDivider: View {
    @Environment(\.wa) private var wa
    var body: some View { (wa.night ? wa.line : Color(hex: 0xF6E4E8)).frame(height: 1) }
}

private struct MoToggleRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let help: String
    let on: Bool
    let divider: Bool
    let onChange: (Bool) -> Void

    var body: some View {
        HStack(spacing: 10) {
            PinkIcon(icon: icon)
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: title).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                Text(verbatim: help).font(.dorr(11)).lineSpacing(2).foregroundStyle(wa.settingsMut)
            }
            Spacer(minLength: 8)
            RedToggle(on: on) { onChange(!on) }
        }
        .padding(.horizontal, 4)
        .padding(.vertical, 12)
        if divider { MoSettingsDivider() }
    }
}

private struct MoSettingsNavRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let value: String?
    let divider: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 10) {
                PinkIcon(icon: icon)
                HStack(spacing: 6) {
                    Text(verbatim: title).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    if let value, !value.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(verbatim: "(\(value))").font(.dorr(13.5, .semibold)).foregroundStyle(wa.settingsMut).lineLimit(1)
                    }
                    Spacer(minLength: 0)
                }
                Image(systemName: "chevron.forward").font(.system(size: 12, weight: .bold))
                    .foregroundStyle(wa.night ? Color(hex: 0x8B93A0) : wa.red.opacity(0.55))
            }
            .padding(.horizontal, 4)
            .padding(.vertical, 12)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        if divider { MoSettingsDivider() }
    }
}

private struct MoCountryDropdown: View {
    @Environment(\.wa) private var wa
    let countries: [CountryDto]
    let selectedId: Int?
    let selectedName: String?
    let onPick: (CountryDto) -> Void
    @State private var open = false
    @State private var query = ""

    var body: some View {
        let shown = countries.filter { query.isEmpty || $0.name.localizedCaseInsensitiveContains(query) || $0.code.localizedCaseInsensitiveContains(query) }
        Button {
            query = ""
            open = true
        } label: {
            HStack(spacing: 10) {
                PinkIcon(icon: "globe")
                Text(verbatim: selectedName ?? L("mo_country")).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                Spacer(minLength: 0)
                Image(systemName: "chevron.down").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.settingsMut)
            }
            .padding(14)
            .settingsSurface(18, elevation: 8)
        }
        .buttonStyle(.plain)
        .allowsHitTesting(!countries.isEmpty)
        .popover(isPresented: $open, arrowEdge: .top) {
            VStack(spacing: 0) {
                HStack(spacing: 8) {
                    Image(systemName: "magnifyingglass").font(.system(size: 15)).foregroundStyle(wa.settingsMut)
                    ZStack(alignment: .leading) {
                        if query.isEmpty { Text(L("mo_search_country")).font(.dorr(14)).foregroundStyle(wa.settingsMut) }
                        TextField("", text: $query).font(.dorr(14)).foregroundStyle(wa.settingsInk).tint(wa.red)
                    }
                }
                .padding(.horizontal, 10)
                .padding(.vertical, 8)
                .background(wa.night ? wa.darkWell : Color(hex: 0xF3F4F6), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                .padding(8)
                ScrollView {
                    LazyVStack(spacing: 0) {
                        ForEach(shown) { c in
                            let on = c.id == selectedId
                            Button {
                                open = false
                                onPick(c)
                            } label: {
                                HStack {
                                    Text(verbatim: c.name.isEmpty ? c.code : c.name).font(.dorr(14, on ? .bold : .regular))
                                        .foregroundStyle(on ? wa.red : wa.settingsInk).lineLimit(1)
                                    Spacer(minLength: 8)
                                    if on { Image(systemName: "checkmark.circle.fill").font(.system(size: 16)).foregroundStyle(wa.red) }
                                }
                                .padding(.horizontal, 16)
                                .padding(.vertical, 12)
                                .contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
            }
            .frame(minWidth: 260, maxHeight: 340)
            .background(wa.night ? wa.darkCard : Color.white)
            .compactPopover()
        }
    }
}

private struct MoOccasionList: View {
    @Environment(\.wa) private var wa
    let list: [MomentCatalogItemDto]?
    let on: Set<Int>
    let onToggle: (MomentCatalogItemDto, Bool) -> Void

    var body: some View {
        if let list {
            if !list.isEmpty {
                MoSettingsCard {
                    ForEach(Array(list.enumerated()), id: \.element.id) { index, m in
                        let isOn = on.contains(m.id)
                        HStack(spacing: 10) {
                            Text(verbatim: m.emoji ?? "✨").font(.system(size: 16))
                                .frame(width: 34, height: 34)
                                .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                            VStack(alignment: .leading, spacing: 2) {
                                Text(verbatim: m.name ?? "").font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                                if let next = m.next { Text(verbatim: dateLabel(next)).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
                            }
                            Spacer(minLength: 8)
                            RedToggle(on: isOn) { onToggle(m, !isOn) }
                        }
                        .padding(.horizontal, 4)
                        .padding(.vertical, 10)
                        if index != list.count - 1 { MoSettingsDivider() }
                    }
                }
            }
        } else {
            ForEach(0..<4, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 48).padding(.bottom, 8) }
        }
    }
}
