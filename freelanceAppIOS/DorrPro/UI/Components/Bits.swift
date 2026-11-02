import SwiftUI

// freelanceApp `ui/components/Avatar.kt`, `Chips.kt`, `Skeleton.kt`.

/// The server keeps one file name per account, so a new photo has the same URL: this stamp makes the image refetch.
@MainActor
final class AvatarVersion: ObservableObject {
    static let shared = AvatarVersion()
    @Published private(set) var value: Int64 = 0

    func bump() { value = Int64(Date().timeIntervalSince1970 * 1000) }
}

/// The profile photo in a circle, or a person icon when there is none (or it can't be reached).
struct AvatarImage: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var version = AvatarVersion.shared
    let url: String?
    let size: CGFloat
    /// On the coloured header the empty avatar is white on a lighter tint.
    var onBrand = false

    var body: some View {
        let accent = onBrand ? Color.white : wa.red
        let shown: URL? = Api.mediaUrl(url).flatMap { base in
            if version.value == 0 { return base }
            let s = base.absoluteString
            return URL(string: s + (s.contains("?") ? "&" : "?") + "v=\(version.value)")
        }
        ZStack {
            Circle().fill(accent.opacity(onBrand ? 0.22 : 0.14))
            AsyncImage(url: shown) { phase in
                if let image = phase.image {
                    image.resizable().scaledToFill()
                } else {
                    Image(systemName: "person.fill").font(.system(size: size / 2.6)).foregroundStyle(accent)
                }
            }
        }
        .frame(width: size, height: size)
        .clipShape(Circle())
    }
}

/// A wrapping row of pills to pick from (one or several): `selected` holds the chosen values.
struct ChipRow: View {
    @Environment(\.wa) private var wa
    let options: [(value: String, label: String)]
    let selected: Set<String>
    let onToggle: (String) -> Void

    var body: some View {
        FlowLayout(spacing: 8) {
            ForEach(options, id: \.value) { option in
                let on = selected.contains(option.value)
                Text(verbatim: option.label)
                    .font(.dorr(13, .semibold))
                    .foregroundStyle(on ? .white : wa.settingsInk)
                    .padding(.horizontal, 14)
                    .padding(.vertical, 8)
                    .background(on ? wa.red : .clear, in: Capsule())
                    .overlay(Capsule().stroke(on ? wa.red : (wa.night ? wa.line : Color(hex: 0xE5E7EB)), lineWidth: 1))
                    .contentShape(Capsule())
                    .onTapGesture { onToggle(option.value) }
            }
        }
    }
}

/// Compose's `FlowRow`: children left to right (start to end), wrapping onto new lines.
struct FlowLayout: Layout {
    var spacing: CGFloat = 8
    var lineSpacing: CGFloat?

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? .infinity
        var x: CGFloat = 0, y: CGFloat = 0, line: CGFloat = 0, widest: CGFloat = 0
        for view in subviews {
            let size = view.sizeThatFits(.unspecified)
            if x > 0 && x + size.width > width {
                y += line + (lineSpacing ?? spacing)
                x = 0
                line = 0
            }
            x += size.width + spacing
            widest = max(widest, x - spacing)
            line = max(line, size.height)
        }
        return CGSize(width: proposal.width ?? widest, height: y + line)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x = bounds.minX, y = bounds.minY, line: CGFloat = 0
        for view in subviews {
            let size = view.sizeThatFits(.unspecified)
            if x > bounds.minX && x + size.width > bounds.maxX {
                y += line + (lineSpacing ?? spacing)
                x = bounds.minX
                line = 0
            }
            view.place(at: CGPoint(x: x, y: y), proposal: ProposedViewSize(size))
            x += size.width + spacing
            line = max(line, size.height)
        }
    }
}

/// A grey block that pulses softly: the placeholder of something still being read (instead of a spinner).
struct SkeletonBlock: View {
    @Environment(\.wa) private var wa
    var height: CGFloat = 12
    var radius: CGFloat = 8
    /// A fraction of the available width (`fillMaxWidth(0.7f)`), nil for a fixed `width`.
    var fraction: CGFloat? = 1
    var width: CGFloat?
    @State private var pulse = false

    var body: some View {
        GeometryReader { proxy in
            RoundedRectangle(cornerRadius: radius, style: .continuous)
                .fill(wa.settingsMut.opacity(0.18))
                .frame(width: width ?? proxy.size.width * (fraction ?? 1))
        }
        .frame(width: width, height: height)
        .opacity(pulse ? 1 : 0.45)
        .onAppear { withAnimation(.easeInOut(duration: 0.8).repeatForever(autoreverses: true)) { pulse = true } }
    }
}

/// One list row while loading: a tile and three lines on a card.
struct SkeletonCard: View {
    var body: some View {
        HStack(spacing: 12) {
            SkeletonBlock(height: 44, radius: 14, width: 44)
            VStack(alignment: .leading, spacing: 8) {
                SkeletonBlock(height: 14, fraction: 0.7)
                SkeletonBlock(height: 11, fraction: 0.45)
                SkeletonBlock(height: 11, fraction: 0.9)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity)
        .settingsSurface(18)
    }
}

/// A list page while its first page is read.
struct SkeletonList: View {
    var count = 4

    var body: some View {
        VStack(spacing: 10) { ForEach(0..<count, id: \.self) { _ in SkeletonCard() } }.frame(maxWidth: .infinity)
    }
}

/// A detail page while it is read: the headline card, number tiles and two text cards.
struct SkeletonDetail: View {
    var body: some View {
        VStack(spacing: 12) {
            VStack(spacing: 14) {
                HStack(spacing: 12) {
                    SkeletonBlock(height: 44, radius: 14, width: 44)
                    VStack(alignment: .leading, spacing: 8) {
                        SkeletonBlock(height: 16, fraction: 0.8)
                        SkeletonBlock(height: 11, fraction: 0.4)
                    }
                }
                HStack(spacing: 8) { ForEach(0..<3, id: \.self) { _ in SkeletonBlock(height: 52, radius: 14) } }
            }
            .padding(16)
            .settingsSurface(20)
            ForEach(0..<2, id: \.self) { _ in
                VStack(alignment: .leading, spacing: 10) {
                    SkeletonBlock(height: 14, fraction: 0.35)
                    SkeletonBlock(height: 11)
                    SkeletonBlock(height: 11, fraction: 0.92)
                    SkeletonBlock(height: 11, fraction: 0.6)
                }
                .padding(16)
                .settingsSurface(18)
            }
        }
        .frame(maxWidth: .infinity)
    }
}

/// A block of text lines while an article or a list of questions is read.
struct SkeletonLines: View {
    var count = 6

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            SkeletonBlock(height: 16, fraction: 0.5)
            ForEach(0..<count, id: \.self) { i in SkeletonBlock(height: 11, fraction: i == count - 1 ? 0.6 : 1) }
        }
        .padding(.vertical, 4)
        .frame(maxWidth: .infinity)
    }
}
