import SwiftUI
import UIKit

// freelanceApp `ui/screens/CommunityKit.kt`.

/*
 * FL-L12: the pieces the community screens share — the post card (LinkedIn-like), reactions and their picker, the plan badge, the image
 * grid and its full-screen viewer, the report sheet, relative times, and [CommunityActions] (optimistic react / save / follow / delete that
 * every screen showing the same post follows through [CommunityHub.changes]).
 */

/// Where the community screens lead.
struct CommunityNav {
    let openPost: (String) -> Void
    let openMember: (String) -> Void
    let editPost: (String) -> Void
    let compose: (String) -> Void
    let openPeople: (String, String) -> Void
    let openVerification: () -> Void
    let openPortfolio: () -> Void
}

// MARK: - sheets and covers

/// Android's `LocaleAwareBottomSheet` / `LocaleAwareDialog`: a sheet or full-screen cover carrying the app's palette, language and
/// shared objects (it is presented outside the page's tree).
private struct CmPresenter<Sheet: View>: ViewModifier {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    @Environment(\.locale) private var locale
    @EnvironmentObject private var appearance: AppearanceState
    @EnvironmentObject private var network: NetworkMonitor
    @Binding var isPresented: Bool
    let cover: Bool
    let clear: Bool
    let locked: Bool
    let detents: Set<PresentationDetent>
    @ViewBuilder let sheet: () -> Sheet

    func body(content: Content) -> some View {
        if cover {
            content.fullScreenCover(isPresented: $isPresented) {
                carried(sheet().background(clear ? AnyView(CmClearBackground()) : AnyView(EmptyView())))
            }
            .transaction { if clear { $0.disablesAnimations = true } }
        } else {
            content.sheet(isPresented: $isPresented) {
                carried(sheet())
                    .interactiveDismissDisabled(locked)
                    .presentationDetents(detents)
                    .presentationDragIndicator(.visible)
            }
        }
    }

    private func carried<V: View>(_ view: V) -> some View {
        view
            .environment(\.wa, wa)
            .environment(\.layoutDirection, direction)
            .environment(\.locale, locale)
            .environmentObject(appearance)
            .environmentObject(network)
    }
}

/// A full-screen cover's own background made clear (iOS 16.0 has no presentationBackground).
private struct CmClearBackground: UIViewRepresentable {
    func makeUIView(context: Context) -> UIView {
        let view = UIView()
        DispatchQueue.main.async { view.superview?.superview?.backgroundColor = .clear }
        return view
    }

    func updateUIView(_ view: UIView, context: Context) {}
}

extension View {
    /// A bottom sheet of the community (`fullHeight` = the large size only); `locked` keeps it open while a request runs.
    func cmSheet<C: View>(isPresented: Binding<Bool>, fullHeight: Bool = false, locked: Bool = false, @ViewBuilder content: @escaping () -> C) -> some View {
        modifier(CmPresenter(isPresented: isPresented, cover: false, clear: false, locked: locked,
                             detents: fullHeight ? [.large] : [.medium, .large], sheet: content))
    }

    /// A full-screen layer of the community (the image viewer; `clear` for the reaction picker over the page).
    func cmCover<C: View>(isPresented: Binding<Bool>, clear: Bool = false, @ViewBuilder content: @escaping () -> C) -> some View {
        modifier(CmPresenter(isPresented: isPresented, cover: true, clear: clear, locked: false, detents: [.large], sheet: content))
    }
}

/// A `Binding<Bool>` that is true while `value` is set, and clears it when the sheet closes.
func cmPresence<T>(_ value: Binding<T?>) -> Binding<Bool> {
    Binding(get: { value.wrappedValue != nil }, set: { if !$0 { value.wrappedValue = nil } })
}

/// `Color(accent.red * r, accent.green * g, accent.blue * b)` of the gradients.
func cmScaled(_ color: Color, _ r: CGFloat, _ g: CGFloat, _ b: CGFloat) -> Color {
    var red: CGFloat = 0, green: CGFloat = 0, blue: CGFloat = 0, alpha: CGFloat = 0
    UIColor(color).getRed(&red, green: &green, blue: &blue, alpha: &alpha)
    return Color(red: Double(red * r), green: Double(green * g), blue: Double(blue * b))
}

// MARK: - reactions

struct ReactionStyle {
    let key: String
    let icon: String
    let color: Color
    let label: String
}

let REACTIONS: [ReactionStyle] = [
    ReactionStyle(key: "like", icon: "hand.thumbsup.fill", color: Color(hex: 0x2563EB), label: "cm_react_like"),
    ReactionStyle(key: "celebrate", icon: "party.popper.fill", color: Color(hex: 0x16A34A), label: "cm_react_celebrate"),
    ReactionStyle(key: "insightful", icon: "lightbulb.fill", color: Color(hex: 0xD97706), label: "cm_react_insightful"),
    ReactionStyle(key: "support", icon: "heart.fill", color: Color(hex: 0xDB2777), label: "cm_react_support"),
]

func reactionStyle(_ key: String?) -> ReactionStyle? { REACTIONS.first { $0.key == key } }

/// The reaction's name: the server's (in my language) when the home has been read, else the app's own.
@MainActor
func reactionLabel(_ style: ReactionStyle) -> String {
    if let label = CommunityHub.shared.home?.reactions?.first(where: { $0.key == style.key })?.label,
       !label.trimmingCharacters(in: .whitespaces).isEmpty { return label }
    return L(style.label)
}

/// Up to three reaction icons overlapping, as LinkedIn shows them over a post.
@MainActor
struct ReactionStack: View {
    @Environment(\.wa) private var wa
    let top: [String]
    var size: CGFloat = 18

    var body: some View {
        HStack(spacing: -5) {
            ForEach(Array(top.compactMap(reactionStyle).prefix(3).enumerated()), id: \.offset) { _, s in
                Image(systemName: s.icon).font(.system(size: size * 0.5, weight: .bold)).foregroundStyle(.white)
                    .frame(width: size - 3, height: size - 3)
                    .background(s.color, in: Circle())
                    .padding(1.5)
                    .background(wa.settingsCard, in: Circle())
            }
        }
    }
}

/**
 * The react button: a tap likes (or takes my reaction back), a long press opens the picker of the four reactions. Shows my reaction in
 * its colour.
 */
@MainActor
struct ReactButton: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var hub = CommunityHub.shared
    let mine: String?
    var compact = false
    let onReact: (String?) -> Void
    @State private var picking = false
    @State private var frame: CGRect = .zero

    var body: some View {
        let style = reactionStyle(mine)
        let color = style?.color ?? wa.settingsMut
        HStack(spacing: 5) {
            Image(systemName: style?.icon ?? "hand.thumbsup").font(.system(size: compact ? 13 : 16, weight: .semibold)).foregroundStyle(color)
            Text(verbatim: style.map(reactionLabel) ?? L("cm_react_like")).font(.dorr(compact ? 12 : 13, .bold)).foregroundStyle(color).lineLimit(1)
        }
        .padding(.horizontal, compact ? 4 : 8)
        .padding(.vertical, compact ? 4 : 9)
        .contentShape(RoundedRectangle(cornerRadius: 10))
        .background(GeometryReader { p in Color.clear.onAppear { frame = p.frame(in: .global) }.onChange(of: p.frame(in: .global)) { frame = $0 } })
        .onTapGesture { onReact(mine == nil ? "like" : nil) }
        .onLongPressGesture(minimumDuration: 0.4) {
            UIImpactFeedbackGenerator(style: .medium).impactOccurred()
            picking = true
        }
        .cmCover(isPresented: $picking, clear: true) {
            ReactionPicker(mine: mine, anchor: frame, onPick: { key in
                picking = false
                onReact(key == mine ? nil : key)
            }, onDismiss: { picking = false })
        }
    }
}

/// The four reactions in a pill over the button (Android's Popup 74dp above it); a tap anywhere else closes it.
@MainActor
private struct ReactionPicker: View {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    let mine: String?
    let anchor: CGRect
    let onPick: (String) -> Void
    let onDismiss: () -> Void
    @State private var grow: CGFloat = 0.6
    @State private var width: CGFloat = 0

    var body: some View {
        GeometryReader { screen in
            let full = screen.size.width
            let start = direction == .rightToLeft ? anchor.maxX - width : anchor.minX
            let x = min(max(start, 8), max(full - width - 8, 8))
            ZStack(alignment: .topLeading) {
                Color.black.opacity(0.001).onTapGesture(perform: onDismiss)
                pill
                    .background(GeometryReader { p in Color.clear.onAppear { width = p.size.width } })
                    .scaleEffect(grow)
                    .offset(x: x, y: max(anchor.minY - 74, 8))
            }
            .environment(\.layoutDirection, .leftToRight)
        }
        .ignoresSafeArea()
        .onAppear { withAnimation(.spring(response: 0.35, dampingFraction: 0.55)) { grow = 1 } }
    }

    private var pill: some View {
        HStack(spacing: 6) {
            ForEach(REACTIONS, id: \.key) { s in
                Button { onPick(s.key) } label: {
                    VStack(spacing: 0) {
                        Image(systemName: s.icon).font(.system(size: 18, weight: .bold)).foregroundStyle(.white)
                            .frame(width: s.key == mine ? 44 : 40, height: s.key == mine ? 44 : 40)
                            .background(s.color, in: Circle())
                        Text(verbatim: reactionLabel(s)).font(.dorr(10, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    }
                    .padding(.horizontal, 4)
                    .padding(.vertical, 2)
                    .contentShape(RoundedRectangle(cornerRadius: 14))
                }
                .buttonStyle(.plain)
                .accessibilityLabel(reactionLabel(s))
            }
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 6)
        .background(wa.settingsCard, in: Capsule())
        .overlay { if wa.night { Capsule().stroke(wa.line, lineWidth: 1) } }
        .shadow(color: Color(hex: 0x001B53).opacity(0.2), radius: 14, y: 4)
        .environment(\.layoutDirection, direction)
        .fixedSize()
    }
}

// MARK: - badges and pills

/// The plan badge next to a name: gold for Pro, indigo for Plus, the app colour for any other plan; nothing without a badge.
@MainActor
struct PlanBadge: View {
    @Environment(\.wa) private var wa
    let badge: CommunityBadgeDto?
    var large = false

    var body: some View {
        if let name = badge?.name, !name.trimmingCharacters(in: .whitespaces).isEmpty {
            let colors: [Color] = {
                switch badge?.plan?.lowercased() {
                case "pro": return [Color(hex: 0xF59E0B), Color(hex: 0xB45309)]
                case "plus": return [Color(hex: 0x6366F1), Color(hex: 0x4338CA)]
                default: return [wa.red, wa.red.opacity(0.75)]
                }
            }()
            HStack(spacing: 3) {
                Image(systemName: "rosette").font(.system(size: large ? 11 : 8, weight: .bold)).foregroundStyle(.white)
                Text(verbatim: name).font(.dorr(large ? 11 : 9, .heavy)).foregroundStyle(.white).lineLimit(1)
            }
            .padding(.horizontal, large ? 9 : 6)
            .padding(.vertical, large ? 3 : 1.5)
            .background(LinearGradient(colors: colors, startPoint: .leading, endPoint: .trailing), in: Capsule())
        }
    }
}

/// A name that gives way to its badge: the name is cut, the badge always shows.
@MainActor
struct NameWithBadge: View {
    @Environment(\.wa) private var wa
    let name: String?
    let badge: CommunityBadgeDto?
    var size: CGFloat = 14

    var body: some View {
        HStack(spacing: 5) {
            Text(verbatim: name.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? L("cm_member"))
                .font(.dorr(size, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1).truncationMode(.tail)
                .layoutPriority(0)
            if badge?.name != nil { PlanBadge(badge: badge).layoutPriority(1) }
        }
    }
}

let QUESTION_TONE = Color(hex: 0x2563EB)
let WORK_TONE = Color(hex: 0x16A34A)

/// The kind of a post, styled: a question in blue, a shared work in green; a plain post has none.
@MainActor
struct KindPill: View {
    let kind: String?
    let label: String?

    var body: some View {
        let style: (String, Color, String)? = {
            switch kind {
            case "question": return ("bubble.left.and.bubble.right.fill", QUESTION_TONE, "cm_kind_question")
            case "work": return ("briefcase.fill", WORK_TONE, "cm_kind_work")
            default: return nil
            }
        }()
        if let style {
            SoftPill(text: label.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? L(style.2), tone: style.1, icon: style.0)
        }
    }
}

@MainActor
struct SoftPill: View {
    @Environment(\.wa) private var wa
    let text: String
    let tone: Color
    var icon: String?
    var solid = false

    var body: some View {
        HStack(spacing: 4) {
            if let icon { Image(systemName: icon).font(.system(size: 10, weight: .bold)).foregroundStyle(solid ? .white : tone) }
            Text(verbatim: text).font(.dorr(11, .bold)).foregroundStyle(solid ? .white : tone).lineLimit(1).truncationMode(.tail)
        }
        .padding(.horizontal, 9)
        .padding(.vertical, 3.5)
        .background(solid ? tone : tone.opacity(wa.night ? 0.22 : 0.11), in: Capsule())
    }
}

// MARK: - time

/// Java's date formats print ASCII digits; the community keeps them Latin too.
private var cmDateLocale: Locale { Locale(identifier: AppLocale.current == "ar" ? "ar@numbers=latn" : AppLocale.current) }

private func cmDateText(_ date: Date) -> String {
    let f = DateFormatter()
    f.calendar = Calendar(identifier: .gregorian)
    f.locale = cmDateLocale
    f.dateFormat = "d MMM yyyy"
    return f.string(from: date)
}

/// "now", "5m", "3h", "2d", "1w", then the date — by the phone's clock.
func cmRelativeTime(_ iso: String?) -> String {
    guard let then = parseInstant(iso) else { return "" }
    let minutes = max(Int(Date().timeIntervalSince(then) / 60), 0)
    if minutes < 1 { return L("cm_time_now") }
    if minutes < 60 { return L("cm_time_minutes", minutes) }
    if minutes < 24 * 60 { return L("cm_time_hours", minutes / 60) }
    if minutes < 7 * 24 * 60 { return L("cm_time_days", minutes / (24 * 60)) }
    if minutes < 5 * 7 * 24 * 60 { return L("cm_time_weeks", minutes / (7 * 24 * 60)) }
    return cmDateText(then)
}

func cmDate(_ iso: String?) -> String { parseInstant(iso).map(cmDateText) ?? "" }

// MARK: - body

/// A post's text, cut after a few lines with «see more» (a tap opens it in place).
@MainActor
struct ExpandableBody: View {
    @Environment(\.wa) private var wa
    let text: String
    let key: String
    var startExpanded = false
    var collapsedLines = 5
    var size: CGFloat = 14
    @State private var expanded: Bool?
    @State private var cutHeight: CGFloat = 0
    @State private var fullHeight: CGFloat = 0

    var body: some View {
        let open = expanded ?? startExpanded
        VStack(alignment: .leading, spacing: 0) {
            styled(Text(verbatim: text))
                .lineLimit(open ? nil : collapsedLines)
                .truncationMode(.tail)
                .frame(maxWidth: .infinity, alignment: .leading)
                .fixedSize(horizontal: false, vertical: true)
                .background(GeometryReader { p in
                    Color.clear.onAppear { cutHeight = p.size.height }.onChange(of: p.size.height) { cutHeight = $0 }
                })
                .background(alignment: .topLeading) {
                    // The whole text, unseen, to know whether the shown one is cut (Compose's hasVisualOverflow).
                    styled(Text(verbatim: text))
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .fixedSize(horizontal: false, vertical: true)
                        .hidden()
                        .background(GeometryReader { p in
                            Color.clear.onAppear { fullHeight = p.size.height }.onChange(of: p.size.height) { fullHeight = $0 }
                        })
                }
            if !open && fullHeight > cutHeight + 1 {
                Button { withAnimation(.easeOut(duration: 0.2)) { expanded = true } } label: {
                    Text(L("cm_see_more")).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsMut).padding(.vertical, 4)
                }
                .buttonStyle(.plain)
            }
        }
        .id(key)
    }

    private func styled(_ t: Text) -> some View {
        t.font(.dorr(size)).foregroundStyle(wa.settingsInk).lineSpacing(size * 0.35)
    }
}

// MARK: - images

/// The images read, kept in memory while the app runs under the image's own id (the link is signed and changes on every read).
private enum CommunityPhotoCache {
    static let shared = NSCache<NSString, UIImage>()
}

/// A network image cached under [cacheKey] (Coil's memory / disk cache keys), cropped to fill or fitted.
@MainActor
struct CommunityKeyedImage: View {
    let url: String?
    let cacheKey: String
    var fit = false
    @State private var image: UIImage?

    var body: some View {
        ZStack {
            if let image {
                if fit { Image(uiImage: image).resizable().scaledToFit() } else { Image(uiImage: image).resizable().scaledToFill() }
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .clipped()
        .task(id: cacheKey) {
            if let cached = CommunityPhotoCache.shared.object(forKey: cacheKey as NSString) {
                image = cached
                return
            }
            guard let link = Api.mediaUrl(url), let (data, _) = try? await URLSession.shared.data(from: link), let read = UIImage(data: data) else { return }
            CommunityPhotoCache.shared.setObject(read, forKey: cacheKey as NSString)
            withAnimation(.easeOut(duration: 0.2)) { image = read }
        }
    }
}

/// A post image. The link is signed and changes on every read, so the cache keys are the image's own id.
@MainActor
struct CommunityPhoto: View {
    let image: CommunityImageDto
    var fit = false

    var body: some View {
        CommunityKeyedImage(url: image.url, cacheKey: "community-image-\(image.id ?? 0)", fit: fit)
    }
}

/// One to four images laid out like LinkedIn: one wide, two side by side, one big and two stacked, or a 2×2 grid.
@MainActor
struct ImageGrid: View {
    @Environment(\.wa) private var wa
    let images: [CommunityImageDto]
    let onOpen: (Int) -> Void

    var body: some View {
        if !images.isEmpty {
            let gap: CGFloat = 3
            Group {
                switch images.count {
                case 1:
                    Color.clear.aspectRatio(4.0 / 3.0, contentMode: .fit).overlay(cell(0))
                case 2:
                    Color.clear.aspectRatio(2.0 / 1.1, contentMode: .fit).overlay(HStack(spacing: gap) { cell(0); cell(1) })
                case 3:
                    Color.clear.aspectRatio(4.0 / 3.0, contentMode: .fit).overlay(
                        GeometryReader { p in
                            HStack(spacing: gap) {
                                cell(0).frame(width: (p.size.width - gap) * 0.6)
                                VStack(spacing: gap) { cell(1); cell(2) }
                            }
                        }
                    )
                default:
                    Color.clear.aspectRatio(1, contentMode: .fit).overlay(
                        VStack(spacing: gap) {
                            HStack(spacing: gap) { cell(0); cell(1) }
                            HStack(spacing: gap) { cell(2); cell(3) }
                        }
                    )
                }
            }
            .frame(maxWidth: .infinity)
            .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
        }
    }

    private func cell(_ i: Int) -> some View {
        CommunityPhoto(image: images[i])
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(wa.night ? wa.darkWell : wa.red.opacity(0.06))
            .clipped()
            .contentShape(Rectangle())
            .onTapGesture { onOpen(i) }
    }
}

/// The images full-screen, in the app: swipe between them, pinch or double-tap to zoom, drag a zoomed image.
@MainActor
struct ImageViewer: View {
    let images: [CommunityImageDto]
    let start: Int
    let onDismiss: () -> Void
    @State private var page = 0

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()
            TabView(selection: $page) {
                ForEach(images.indices, id: \.self) { i in
                    ZoomableImage(image: images[i]).tag(i)
                }
            }
            .tabViewStyle(.page(indexDisplayMode: .never))
            .ignoresSafeArea()
            VStack(spacing: 0) {
                HStack {
                    Button(action: onDismiss) {
                        Image(systemName: "xmark").font(.system(size: 17, weight: .bold)).foregroundStyle(.white)
                            .frame(width: 40, height: 40)
                            .background(Color.white.opacity(0.16), in: Circle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("common_close"))
                    Spacer()
                    if images.count > 1 {
                        Text(verbatim: "\u{200E}\(page + 1) / \(images.count)").font(.dorr(13, .bold)).foregroundStyle(.white)
                            .padding(.horizontal, 12).padding(.vertical, 6)
                            .background(Color.white.opacity(0.16), in: Capsule())
                    }
                }
                .padding(.horizontal, 14)
                .padding(.vertical, 10)
                Spacer()
                if images.count > 1 {
                    HStack(spacing: 6) {
                        ForEach(images.indices, id: \.self) { i in
                            Circle().fill(Color.white.opacity(i == page ? 1 : 0.45)).frame(width: i == page ? 8 : 6, height: i == page ? 8 : 6)
                        }
                    }
                    .padding(.bottom, 18)
                }
            }
        }
        .onAppear { page = min(max(start, 0), max(images.count - 1, 0)) }
    }
}

@MainActor
private struct ZoomableImage: View {
    let image: CommunityImageDto
    @State private var scale: CGFloat = 1
    @State private var lastScale: CGFloat = 1
    @State private var offset: CGSize = .zero
    @State private var lastOffset: CGSize = .zero

    var body: some View {
        GeometryReader { box in
            let size = box.size
            CommunityPhoto(image: image, fit: true)
                .frame(width: size.width, height: size.height)
                .scaleEffect(scale)
                .offset(offset)
                .contentShape(Rectangle())
                .onTapGesture(count: 2) { tap in
                    withAnimation(.easeOut(duration: 0.2)) {
                        if scale > 1 {
                            scale = 1
                            offset = .zero
                        } else {
                            scale = 2.5
                            let center = CGPoint(x: size.width / 2, y: size.height / 2)
                            offset = clamp(CGSize(width: (center.x - tap.x) * 1.5, height: (center.y - tap.y) * 1.5), 2.5, size)
                        }
                    }
                    lastScale = scale
                    lastOffset = offset
                }
                // Pinch zooms; a drag only moves a zoomed image, else it is left to the pager (swipe to the next image).
                .gesture(
                    MagnificationGesture()
                        .onChanged { value in
                            scale = min(max(lastScale * value, 1), 5)
                            offset = scale <= 1 ? .zero : clamp(offset, scale, size)
                        }
                        .onEnded { _ in
                            lastScale = scale
                            lastOffset = offset
                        }
                )
                .simultaneousGesture(
                    DragGesture()
                        .onChanged { value in
                            offset = clamp(CGSize(width: lastOffset.width + value.translation.width, height: lastOffset.height + value.translation.height), scale, size)
                        }
                        .onEnded { _ in lastOffset = offset },
                    including: scale > 1 ? .all : .none
                )
        }
    }

    private func clamp(_ o: CGSize, _ s: CGFloat, _ box: CGSize) -> CGSize {
        let maxX = box.width * (s - 1) / 2
        let maxY = box.height * (s - 1) / 2
        return CGSize(width: min(max(o.width, -maxX), maxX), height: min(max(o.height, -maxY), maxY))
    }
}

/// The finished work a `work` post shares, as a small card.
@MainActor
struct WorkCard: View {
    @Environment(\.wa) private var wa
    let work: CommunityWorkDto

    var body: some View {
        HStack(spacing: 12) {
            ZStack {
                WORK_TONE.opacity(0.12)
                if let url = work.imageUrl {
                    CommunityKeyedImage(url: url, cacheKey: "community-work-\(work.uuid ?? "")")
                } else {
                    Image(systemName: "briefcase.fill").font(.system(size: 22)).foregroundStyle(WORK_TONE)
                }
            }
            .frame(width: 64, height: 64)
            .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
            VStack(alignment: .leading, spacing: 3) {
                Text(L("cm_work_from_portfolio")).font(.dorr(11, .heavy)).foregroundStyle(WORK_TONE)
                Text(verbatim: work.title ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(10)
        .frame(maxWidth: .infinity)
        .background(wa.night ? wa.darkWell : WORK_TONE.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(WORK_TONE.opacity(0.22), lineWidth: 1))
    }
}

// MARK: - actions

/**
 * What a tap on a post does, optimistically: the change shows at once on every screen (through [CommunityHub.changes]), the server's answer
 * then settles it, and a refusal puts it back with the server's message. Runs in its own task so leaving the screen does not cut it off.
 */
@MainActor
enum CommunityActions {
    private static func toast(_ text: String?) {
        if let text, !text.trimmingCharacters(in: .whitespaces).isEmpty { AppToast.show(text) }
    }

    private static var offline: String { L("login_error_offline") }
    private static var generic: String { L("login_error_generic") }

    /// Why I can't write now, or nil when I can (the home not read yet lets the server decide).
    static func cannotWrite() -> String? {
        guard let home = CommunityHub.shared.home else { return nil }
        if home.canWrite == true { return nil }
        return home.muted.map { L("cm_muted_until", cmDate($0.until)) } ?? L("cm_need_verified")
    }

    static func react(_ post: CommunityPostDto, _ kind: String?) {
        if let why = cannotWrite() { toast(why); return }
        let (n, top) = optimisticReaction(count: post.reactions ?? 0, top: post.topReactions ?? [], before: post.myReaction, after: kind)
        var shown = post
        shown.reactions = n
        shown.topReactions = top
        shown.myReaction = kind
        CommunityHub.shared.emit(.postUpdated(shown))
        Task {
            let r = await CommunityApi.react("posts", post.uuid ?? "", CommunityReactionRequest(kind: kind))
            if case .ok(let data, _) = r {
                var settled = post
                settled.reactions = data.reactions ?? 0
                settled.topReactions = data.top ?? []
                settled.myReaction = data.myReaction
                CommunityHub.shared.emit(.postUpdated(settled))
            } else {
                CommunityHub.shared.emit(.postUpdated(post))
                toast(r.errorText(offline) ?? generic)
            }
        }
    }

    static func save(_ post: CommunityPostDto) {
        let on = !(post.saved ?? false)
        var shown = post
        shown.saved = on
        CommunityHub.shared.emit(.postUpdated(shown))
        Task {
            let uuid = post.uuid ?? ""
            let r = on ? await CommunityApi.save(uuid) : await CommunityApi.unsave(uuid)
            if case .ok(_, let message) = r {
                toast(message)
            } else {
                CommunityHub.shared.emit(.postUpdated(post))
                toast(r.errorText(offline) ?? generic)
            }
        }
    }

    /// Follows / unfollows; [onCard] gets the member's new card (their counts) from the server.
    static func follow(_ member: String, _ on: Bool, onCard: @escaping (CommunityMemberDto) -> Void = { _ in }, onFailed: @escaping () -> Void = {}) {
        CommunityHub.shared.emit(.followChanged(member: member, following: on))
        Task {
            let r = on ? await CommunityApi.follow(member) : await CommunityApi.unfollow(member)
            if case .ok(let card, _) = r {
                onCard(card)
                await CommunityHub.shared.refresh()
            } else {
                CommunityHub.shared.emit(.followChanged(member: member, following: !on))
                onFailed()
                toast(r.errorText(offline) ?? generic)
            }
        }
    }

    static func delete(_ post: CommunityPostDto, onFailed: @escaping () -> Void, onDone: @escaping () -> Void) {
        Task {
            let failed = await CommunityApi.delete(post.uuid ?? "", offline: offline, generic: generic)
            if failed == nil {
                onDone()
                CommunityHub.shared.emit(.postDeleted(post.uuid ?? ""))
                toast(L("cm_deleted"))
                await CommunityHub.shared.refresh()
            } else {
                onFailed()
                toast(failed)
            }
        }
    }
}

// MARK: - the post card

/**
 * A post, LinkedIn-like: the author (photo, name, badge, headline, time, «edited», a quick «follow»), the kind and the specialty, the text
 * with «see more», the images, the shared work, what people reacted and how many commented, then React · Comment · Save. The menu holds
 * edit / delete for my own post and report for the others'.
 */
@MainActor
struct PostCard: View {
    @Environment(\.wa) private var wa
    let post: CommunityPostDto
    let nav: CommunityNav
    var expanded = false
    var onComment: (() -> Void)?
    var onDeleted: () -> Void = {}

    @State private var viewing: Int?
    @State private var confirmDelete = false
    @State private var deleting = false
    @State private var reporting = false

    private var a: CommunityAuthorDto { post.author ?? CommunityAuthorDto() }
    private var comment: () -> Void { onComment ?? { nav.openPost(post.uuid ?? "") } }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            authorRow.padding(.horizontal, 14)
            pills
            if let h = post.hidden {
                HiddenNote(underReview: h.underReview ?? false, reason: h.reason).padding(.horizontal, 14).padding(.top, 10)
            }
            ExpandableBody(text: post.body ?? "", key: "post-\(post.uuid ?? "")", startExpanded: expanded, size: expanded ? 15 : 14)
                .padding(.horizontal, 14).padding(.top, 10)
            if let images = post.images, !images.isEmpty {
                ImageGrid(images: images) { viewing = $0 }.padding(.horizontal, 10).padding(.top, 10)
            }
            if let w = post.work { WorkCard(work: w).padding(.horizontal, 14).padding(.top, 10) }
            counts
            Rectangle().fill(wa.night ? wa.line : Color(hex: 0x001B53).opacity(0.08)).frame(height: 1)
                .padding(.horizontal, 14).padding(.top, 10)
            HStack(spacing: 0) {
                ReactButton(mine: post.myReaction) { CommunityActions.react(post, $0) }.frame(maxWidth: .infinity)
                PostActionButton(icon: "bubble.left", label: L("cm_comment"), tint: wa.settingsMut, action: comment)
                let saved = post.saved ?? false
                PostActionButton(icon: saved ? "bookmark.fill" : "bookmark", label: L(saved ? "cm_saved" : "cm_save"),
                                 tint: saved ? wa.red : wa.settingsMut) { CommunityActions.save(post) }
            }
            .padding(.horizontal, 6)
            .padding(.vertical, 2)
        }
        .padding(.top, 14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20, elevation: 4)
        .contentShape(Rectangle())
        .onTapGesture { if !expanded { nav.openPost(post.uuid ?? "") } }
        .cmCover(isPresented: cmPresence($viewing)) {
            ImageViewer(images: post.images ?? [], start: viewing ?? 0) { viewing = nil }
        }
        .cmSheet(isPresented: $reporting, fullHeight: true) {
            ReportSheet(subjectType: "post", subject: post.uuid ?? "") { reporting = false }
        }
        .dorrDialog(isPresented: $confirmDelete) {
            ConfirmDialog(icon: "trash.fill", title: L("cm_delete_post_title"), message: L("cm_delete_post_body"), loading: deleting,
                          confirmLabel: L("common_delete"), onConfirm: {
                              deleting = true
                              CommunityActions.delete(post, onFailed: { deleting = false }) {
                                  deleting = false
                                  confirmDelete = false
                                  onDeleted()
                              }
                          }, onDismiss: { confirmDelete = false })
        }
    }

    private func openAuthor() { if let uuid = a.uuid { nav.openMember(uuid) } }

    private var authorRow: some View {
        HStack(alignment: .top, spacing: 0) {
            AvatarImage(url: a.avatarUrl, size: 46).onTapGesture(perform: openAuthor)
            VStack(alignment: .leading, spacing: 0) {
                NameWithBadge(name: a.name, badge: a.badge)
                if let headline = a.headline, !headline.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: headline).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
                let time = cmRelativeTime(post.createdAt)
                Text(verbatim: post.edited == true ? "\(time) · \(L("cm_edited"))" : time).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineLimit(1)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.leading, 10)
            .contentShape(Rectangle())
            .onTapGesture(perform: openAuthor)
            if a.isMe != true, a.isFollowing == false, let uuid = a.uuid {
                Button { CommunityActions.follow(uuid, true) } label: {
                    HStack(spacing: 2) {
                        Image(systemName: "plus").font(.system(size: 13, weight: .bold))
                        Text(L("cm_follow")).font(.dorr(13, .heavy))
                    }
                    .foregroundStyle(wa.red)
                    .padding(.horizontal, 8).padding(.vertical, 6)
                    .contentShape(Capsule())
                }
                .buttonStyle(.plain)
            }
            Menu {
                let can = post.can ?? CommunityPostCan()
                if can.edit == true { MenuLine(icon: "pencil", label: L("cm_edit_post")) { nav.editPost(post.uuid ?? "") } }
                if can.delete == true { MenuLine(icon: "trash", label: L("cm_delete_post"), danger: true) { confirmDelete = true } }
                if can.report == true { MenuLine(icon: "flag.fill", label: L("cm_report_post")) { reporting = true } }
                if can.edit != true && can.delete != true && can.report != true {
                    MenuLine(icon: "person.3.fill", label: L("cm_view_profile"), action: openAuthor)
                }
            } label: {
                Image(systemName: "ellipsis").font(.system(size: 17, weight: .bold)).foregroundStyle(wa.settingsMut)
                    .frame(width: 34, height: 34).contentShape(Circle())
            }
            .accessibilityLabel(L("cm_more"))
        }
    }

    @ViewBuilder
    private var pills: some View {
        let kind = post.kind ?? "post"
        let solved = kind == "question" && post.acceptedComment != nil
        if kind != "post" || post.specialty != nil || solved {
            HStack(spacing: 6) {
                KindPill(kind: kind, label: post.kindLabel)
                if solved { SoftPill(text: L("cm_solved"), tone: WORK_TONE, icon: "checkmark.circle.fill") }
                if let s = post.specialty { SoftPill(text: s.name ?? "", tone: wa.settingsMut) }
            }
            .padding(.horizontal, 14)
            .padding(.top, 10)
        }
    }

    @ViewBuilder
    private var counts: some View {
        let reactions = post.reactions ?? 0
        let comments = post.comments ?? 0
        if reactions > 0 || comments > 0 {
            HStack(spacing: 0) {
                if reactions > 0 {
                    ReactionStack(top: post.topReactions ?? [])
                    Text(verbatim: "\(reactions)").font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut).padding(.leading, 4)
                }
                Spacer(minLength: 0)
                if comments > 0 {
                    Button(action: comment) {
                        Text(L("cm_comments_count", comments)).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut).padding(2)
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 14)
            .padding(.top, 10)
        }
    }
}

@MainActor
struct HiddenNote: View {
    let underReview: Bool
    let reason: String?

    var body: some View {
        let tone = Color(hex: 0xD97706)
        HStack(spacing: 8) {
            Image(systemName: "eye.slash.fill").font(.system(size: 14)).foregroundStyle(tone)
            Text(underReview ? L("cm_hidden_review") : L("cm_hidden_staff", reason ?? "")).font(.dorr(12, .semibold)).foregroundStyle(tone).lineSpacing(3)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 8)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(tone.opacity(0.1), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
    }
}

/// Android's private `ActionButton` of the post card (renamed: the agreement kit has an `ActionButton`).
@MainActor
private struct PostActionButton: View {
    let icon: String
    let label: String
    let tint: Color
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 5) {
                Image(systemName: icon).font(.system(size: 16, weight: .semibold))
                Text(verbatim: label).font(.dorr(13, .bold)).lineLimit(1)
            }
            .foregroundStyle(tint)
            .frame(maxWidth: .infinity)
            .padding(.vertical, 9)
            .contentShape(RoundedRectangle(cornerRadius: 10))
        }
        .buttonStyle(.plain)
    }
}

/// A line of a post's or a comment's menu (`DropdownMenuItem`), red for the dangerous one.
@MainActor
struct MenuLine: View {
    let icon: String
    let label: String
    var danger = false
    let action: () -> Void

    var body: some View {
        Button(role: danger ? .destructive : nil, action: action) { Label(label, systemImage: icon) }
    }
}

/// A post card while the feed loads: the shape of a post, softly pulsing.
@MainActor
struct PostSkeleton: View {
    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                SkeletonBlock(height: 46, radius: 23, width: 46)
                VStack(alignment: .leading, spacing: 6) {
                    SkeletonBlock(height: 12, fraction: 0.5)
                    SkeletonBlock(height: 10, fraction: 0.75)
                    SkeletonBlock(height: 9, fraction: 0.25)
                }
            }
            SkeletonBlock(height: 11)
            SkeletonBlock(height: 11, fraction: 0.92)
            SkeletonBlock(height: 11, fraction: 0.6)
            SkeletonBlock(height: 150, radius: 14)
            HStack(spacing: 12) { ForEach(0..<3, id: \.self) { _ in SkeletonBlock(height: 18) } }
        }
        .padding(14)
        .frame(maxWidth: .infinity)
        .settingsSurface(20, elevation: 4)
    }
}

// MARK: - report

/**
 * Report a post or a comment: one of the server's reasons and, if wanted, a few words. The author is not told who reported. A report
 * already sent, or one on my own words, comes back as the server's message, shown in the sheet. Present it with
 * `.cmSheet(isPresented:fullHeight: true, locked: …)`.
 */
@MainActor
struct ReportSheet: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var hub = CommunityHub.shared
    let subjectType: String
    let subject: String
    let onDismiss: () -> Void

    @State private var reason: String?
    @State private var details = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        let danger = Color(hex: 0xDC2626)
        let reasons: [CommunityKeyLabel] = {
            let list = hub.home?.reportReasons ?? []
            return list.isEmpty ? ["spam", "contact", "abuse", "off_topic", "misleading", "other"].map { CommunityKeyLabel(key: $0, label: $0) } : list
        }()
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                HStack(spacing: 12) {
                    Image(systemName: "flag.fill").font(.system(size: 20)).foregroundStyle(danger)
                        .frame(width: 48, height: 48)
                        .background(danger.opacity(0.1), in: RoundedRectangle(cornerRadius: 15, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L(subjectType == "post" ? "cm_report_post_title" : "cm_report_comment_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                        Text(L("cm_report_hint")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                    }
                    Spacer(minLength: 0)
                }
                Text(L("cm_report_why")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                ForEach(reasons, id: \.key) { r in reasonRow(r) }
                DorrTextField(value: Binding(get: { details }, set: { details = String($0.prefix(1000)) }),
                              placeholder: L("cm_report_details"), singleLine: false, minLines: 3)
                if let error {
                    Text(verbatim: error).font(.dorr(13, .semibold)).foregroundStyle(danger)
                        .padding(10).frame(maxWidth: .infinity, alignment: .leading)
                        .background(danger.opacity(0.08), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                SettingsPrimaryButton(text: L("cm_report_send"), enabled: reason != nil, loading: busy, action: send)
            }
            .padding(.horizontal, 20)
            .padding(.top, 24)
            .padding(.bottom, 24)
        }
        .scrollDismissesKeyboard(.interactively)
        .background(wa.settingsCard.ignoresSafeArea())
        .interactiveDismissDisabled(busy)
    }

    private func reasonRow(_ r: CommunityKeyLabel) -> some View {
        let on = reason == r.key
        return Button {
            reason = r.key
            error = nil
        } label: {
            HStack(spacing: 0) {
                Text(verbatim: r.label ?? "").font(.dorr(14, on ? .heavy : .semibold)).foregroundStyle(wa.settingsInk)
                    .frame(maxWidth: .infinity, alignment: .leading)
                ZStack {
                    Circle().stroke(on ? wa.red : wa.settingsMut.opacity(0.5), lineWidth: 2).frame(width: 20, height: 20)
                    if on { Circle().fill(wa.red).frame(width: 10, height: 10) }
                }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 12)
            .background(on ? wa.red.opacity(0.1) : .clear, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(on ? wa.red : (wa.night ? wa.line : Color(hex: 0xE5E7EB)), lineWidth: 1))
            .contentShape(RoundedRectangle(cornerRadius: 14))
        }
        .buttonStyle(.plain)
    }

    private func send() {
        busy = true
        error = nil
        let trimmed = details.trimmingCharacters(in: .whitespacesAndNewlines)
        Task {
            let r = await CommunityApi.report(CommunityReportRequest(subjectType: subjectType, subject: subject, reason: reason ?? "",
                                                                     details: trimmed.isEmpty ? nil : trimmed))
            busy = false
            if case .ok(_, let message) = r {
                AppToast.show(message ?? L("cm_report_done"))
                onDismiss()
            } else {
                error = r.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}

// MARK: - entry points

/**
 * The community's door on the home: a wide card on the app colour with my photo and «start a post», shown only while the feature is on
 * in my country ([CommunityHub.available]).
 */
@MainActor
struct CommunityHomeCard: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var hub = CommunityHub.shared
    @ObservedObject private var session = Session.shared
    let onOpen: () -> Void
    let onCompose: () -> Void

    var body: some View {
        if hub.available == true {
            let accent = wa.red
            VStack(alignment: .leading, spacing: 12) {
                HStack(spacing: 12) {
                    Image(systemName: "person.3.fill").font(.system(size: 18)).foregroundStyle(.white)
                        .frame(width: 44, height: 44)
                        .background(Color.white.opacity(0.18), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("cm_home_title")).font(.dorr(16, .heavy)).foregroundStyle(.white)
                        Text(L("cm_home_body")).font(.dorr(12)).foregroundStyle(.white.opacity(0.88)).lineSpacing(3)
                    }
                    Spacer(minLength: 0)
                }
                Button(action: onCompose) {
                    HStack(spacing: 10) {
                        AvatarImage(url: hub.home?.me?.avatarUrl ?? session.me?.avatarUrl, size: 32)
                        Text(L("cm_start_post")).font(.dorr(13, .semibold)).foregroundStyle(Color(hex: 0x6B7280))
                            .frame(maxWidth: .infinity, alignment: .leading)
                        Image(systemName: "photo").font(.system(size: 17)).foregroundStyle(accent).padding(.trailing, 10)
                    }
                    .padding(5)
                    .background(Color.white, in: Capsule())
                    .contentShape(Capsule())
                }
                .buttonStyle(.plain)
                if let m = hub.home?.me {
                    HStack(spacing: 14) {
                        Text(L("cm_stat_posts_n", m.posts ?? 0))
                        Text(L("cm_stat_followers_n", m.followers ?? 0))
                        Text(L("cm_stat_following_n", m.following ?? 0))
                    }
                    .font(.dorr(11.5, .bold))
                    .foregroundStyle(.white.opacity(0.92))
                }
            }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(LinearGradient(colors: [accent, cmScaled(accent, 0.72, 0.72, 0.78)], startPoint: .topLeading, endPoint: .bottomTrailing),
                        in: RoundedRectangle(cornerRadius: 22, style: .continuous))
            .contentShape(RoundedRectangle(cornerRadius: 22))
            .onTapGesture(perform: onOpen)
        }
    }
}

/// A loading line for a button row (follow, send…).
@MainActor
struct SmallSpinner: View {
    @Environment(\.wa) private var wa
    var color: Color?
    var size: CGFloat = 18

    var body: some View {
        ProgressView().tint(color ?? wa.red).scaleEffect(size / 20).frame(width: size, height: size)
    }
}
