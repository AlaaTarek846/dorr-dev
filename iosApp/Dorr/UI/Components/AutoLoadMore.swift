import SwiftUI

/// androidApp `ui/components/AutoLoadMore.kt` — the app's paging rule (owner 2026-11-11): lists come 15 at a time and
/// the next 15 load by themselves, no «show more» button. Put it under the last row of a list in a normal ScrollView:
/// once it comes within about a screen-fifth of the bottom edge it asks for the next page, one request at a time, with
/// a small spinner while that page is on its way. A short page that leaves it on screen keeps asking until the screen
/// is full or `hasMore` is false.
struct AutoLoadMore: View {
    let hasMore: Bool
    let loading: Bool
    let tint: Color
    let onLoadMore: () -> Void
    @State private var near = false

    var body: some View {
        if hasMore {
            ZStack { if loading { ProgressView().tint(tint) } }
                .frame(maxWidth: .infinity, minHeight: 28)
                .padding(.top, 10)
                // The global frame changes on every scroll, so «near the end» follows the finger.
                .background(GeometryReader { g in
                    Color.clear.preference(key: AutoLoadMoreEdge.self, value: g.frame(in: .global).minY)
                })
                .onPreferenceChange(AutoLoadMoreEdge.self) { y in
                    let screen = UIScreen.main.bounds.height
                    near = y < screen + screen / 5
                }
                .onChange(of: near) { _ in ask() }
                .onChange(of: loading) { _ in ask() }
        }
    }

    private func ask() {
        if near && hasMore && !loading { onLoadMore() }
    }
}

private struct AutoLoadMoreEdge: PreferenceKey {
    static var defaultValue: CGFloat = .infinity
    static func reduce(value: inout CGFloat, nextValue: () -> CGFloat) { value = nextValue() }
}
