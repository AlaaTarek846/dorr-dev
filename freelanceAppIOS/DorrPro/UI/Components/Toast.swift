import SwiftUI

/// Android's `Toast.makeText(…).show()` for the whole app: a dark pill near the bottom that fades away
/// (2 s for SHORT, 3.5 s for LONG). `AppToast.show("…")` from anywhere.
@MainActor
final class AppToast: ObservableObject {
    static let shared = AppToast()

    @Published private(set) var message: String?
    private var hideTask: Task<Void, Never>?

    static func show(_ text: String, long: Bool = false) { shared.show(text, long: long) }

    func show(_ text: String, long: Bool = false) {
        withAnimation(.easeOut(duration: 0.2)) { message = text }
        hideTask?.cancel()
        hideTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: long ? 3_500_000_000 : 2_000_000_000)
            guard !Task.isCancelled else { return }
            withAnimation(.easeIn(duration: 0.2)) { self?.message = nil }
        }
    }
}

/// Drawn once over the app (RootView).
struct AppToastHost: View {
    @ObservedObject private var toast = AppToast.shared

    var body: some View {
        VStack {
            Spacer()
            if let message = toast.message {
                Text(verbatim: message)
                    .font(.dorr(14, .medium))
                    .foregroundStyle(.white)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 16)
                    .padding(.vertical, 10)
                    .background(Color(hex: 0x323232).opacity(0.95), in: Capsule())
                    .padding(.horizontal, 32)
                    .padding(.bottom, 96)
                    .transition(.opacity)
            }
        }
        .allowsHitTesting(false)
    }
}
