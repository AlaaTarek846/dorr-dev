// freelanceApp `ui/wallet/WaHost.kt`
import SwiftUI

/// The little that the DORR app's `WalletHost` gives the wallet pages here: the toast at the top (an error in red, a note in
/// dark), shown for a few seconds. The pages, the PIN and the money logic live in the freelancer app's own wallet screens.
@MainActor
final class WalletHost: ObservableObject {
    @Published private(set) var toast: String?
    @Published private(set) var toastError = false
    private var job: Task<Void, Never>?

    func showToast(_ message: String, error: Bool = false) {
        if message.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { return }
        withAnimation(.easeOut(duration: 0.3)) {
            toast = message
            toastError = error
        }
        job?.cancel()
        job = Task { [weak self] in
            try? await Task.sleep(nanoseconds: 3_200_000_000)
            guard !Task.isCancelled else { return }
            withAnimation(.easeIn(duration: 0.25)) { self?.toast = nil }
        }
    }

    func showError(_ message: String) { showToast(message, error: true) }
}

/// The toast at the top, over everything (the PIN pad included), so it never hides the keypad or the buttons.
struct WaToastHost: View {
    @Environment(\.wa) private var wa
    @ObservedObject var host: WalletHost

    var body: some View {
        VStack {
            if let message = host.toast {
                HStack(spacing: 10) {
                    Image(systemName: host.toastError ? "exclamationmark.circle" : "checkmark.circle.fill")
                        .font(.system(size: 16, weight: .semibold))
                    Text(message).font(.dorr(13.5, .semibold)).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
                }
                .foregroundStyle(.white)
                .padding(.horizontal, 18)
                .padding(.vertical, 12)
                .background(host.toastError ? wa.danger : Color(hex: 0x111928), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .shadow(color: Color(hex: 0x111928).opacity(0.27), radius: 12, y: 4)
                .transition(.move(edge: .top).combined(with: .opacity))
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 16)
        .padding(.top, 10)
        .frame(maxWidth: .infinity)
        .allowsHitTesting(false)
    }
}

/// Filter pill (`wa-chip`): white with a hairline border, the brand colour and filled when selected.
struct WaChip: View {
    @Environment(\.wa) private var wa
    let text: String
    var icon: String?
    let selected: Bool
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(spacing: 6) {
                if let icon { Image(systemName: icon).font(.system(size: 13, weight: .semibold)) }
                Text(text).font(.dorr(13, .bold))
            }
            .foregroundStyle(selected ? Color.white : wa.ink)
            .padding(.horizontal, 14)
            .padding(.vertical, 7)
            .background(selected ? wa.red : wa.surface, in: Capsule())
            .overlay(Capsule().stroke(selected ? wa.red : wa.line, lineWidth: 1.5))
        }
        .buttonStyle(WaPressStyle(scale: 0.92))
    }
}

/// "اليوم" / "أمس" / a long date.
func dayText(_ iso: String?) -> String {
    switch daysAgo(iso) {
    case 0: return L("wa_today")
    case 1: return L("wa_yesterday")
    default: return longDate(iso)
    }
}
