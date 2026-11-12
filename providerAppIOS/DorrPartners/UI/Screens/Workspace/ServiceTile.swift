// providerApp `ui/screens/ServiceTile.kt`
import SwiftUI

/// One service as a tile — the same tile in the registration (to pick the services) and on the home (the services the
/// establishment works in): the admin's image on a soft disc, the name in full under it (two lines at most), and, once picked,
/// the app colour around it with a tick that pops in. `onClick` nil = a tile to look at (the home); `dimmed` = a service
/// switched off for this establishment.
struct ServiceTile: View {
    @Environment(\.wa) private var wa
    let name: String?
    let image: String?
    let selected: Bool
    var dimmed = false
    var onClick: (() -> Void)?

    var body: some View {
        if let onClick {
            Button(action: onClick) { tile }.buttonStyle(.plain)
        } else {
            tile
        }
    }

    private var tile: some View {
        let shape = RoundedRectangle(cornerRadius: 20, style: .continuous)
        return VStack(spacing: 10) {
            // The image on a soft disc in the app colour, so a light picture still stands out on the white card.
            ZStack {
                Circle().fill(LinearGradient(
                    colors: [wa.red.opacity(wa.night ? 0.20 : 0.10), wa.red.opacity(wa.night ? 0.08 : 0.03)],
                    startPoint: .topLeading, endPoint: .bottomTrailing
                ))
                if let url = Api.mediaUrl(image) {
                    AsyncImage(url: url) { phase in
                        if let loaded = phase.image { loaded.resizable().scaledToFit() } else { Color.clear }
                    }
                    .frame(width: 50, height: 50)
                } else {
                    Image(systemName: "square.grid.2x2.fill").font(.system(size: 24, weight: .semibold)).foregroundStyle(wa.red)
                }
            }
            .frame(width: 68, height: 68)
            // The name always shows in full width under the image; two lines kept for every tile so the rows line up.
            Text(verbatim: name ?? "\n").font(.dorr(13, .heavy)).foregroundStyle(selected ? wa.red : wa.settingsInk)
                .multilineTextAlignment(.center).lineLimit(2)
                .frame(maxWidth: .infinity, minHeight: 36, alignment: .center)
                .fixedSize(horizontal: false, vertical: true)
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 14)
        .frame(maxWidth: .infinity)
        .background(selected ? wa.red.opacity(wa.night ? 0.22 : 0.08) : wa.settingsCard, in: shape)
        .overlay(shape.stroke(selected ? wa.red : (wa.night ? wa.line : Color(hex: 0xE9EBEF)), lineWidth: selected ? 2 : 1))
        .overlay(alignment: .topTrailing) {
            if selected {
                Image(systemName: "checkmark").font(.system(size: 11, weight: .heavy)).foregroundStyle(.white)
                    .frame(width: 22, height: 22)
                    .background(wa.red, in: Circle())
                    .padding(7)
                    .transition(.scale.combined(with: .opacity))
            }
        }
        .contentShape(shape)
        .scaleEffect(selected ? 1.03 : 1)
        .opacity(dimmed ? 0.5 : 1)
        .animation(.spring(response: 0.35, dampingFraction: 0.55), value: selected)
    }
}

/// Tiles three to a row, in the order given (the admin's order), each sliding up into place a moment after the one before it.
struct ServiceTileGrid<Item, Tile: View>: View {
    let items: [Item]
    @ViewBuilder let tile: (Item) -> Tile

    var body: some View {
        LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 10, alignment: .top), count: 3), spacing: 10) {
            ForEach(Array(items.enumerated()), id: \.offset) { index, item in
                tile(item).modifier(TileAppear(order: index))
            }
        }
    }
}

/// The staggered entrance of a tile: faded and a little lower, then in place.
private struct TileAppear: ViewModifier {
    let order: Int
    @State private var shown = false

    func body(content: Content) -> some View {
        content
            .opacity(shown ? 1 : 0)
            .offset(y: shown ? 0 : 14)
            .onAppear {
                withAnimation(.easeOut(duration: 0.32).delay(0.04 * Double(min(order, 12)))) { shown = true }
            }
    }
}
