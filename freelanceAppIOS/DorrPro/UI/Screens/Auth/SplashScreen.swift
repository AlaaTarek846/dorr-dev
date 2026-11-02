import SwiftUI

/// freelanceApp `SplashScreen.kt` — the DORR app's splash (fade + scale-in of the mark), with the Pro name under it.
/// While it plays, the platform colours are fetched once (one request on open, never repeated) and kept for the next launch.
@MainActor
struct SplashScreen: View {
    @EnvironmentObject private var appearance: AppearanceState
    @Environment(\.wa) private var wa
    let onFinished: () -> Void
    @State private var start = Date()

    var body: some View {
        let muted = wa.night ? wa.mut : appearance.color("authTextMuted", AppColors.textSecondary, dark: false)
        ZStack {
            PinkBackdrop()
            TimelineView(.animation) { context in
                let elapsed = context.date.timeIntervalSince(start)
                let alpha = min(elapsed / 0.9, 1)
                let scale = 0.85 + 0.15 * overshoot(min(elapsed / 0.9, 1))
                let rise = fastOutSlowIn(min(elapsed / 1.1, 1))
                VStack(spacing: 0) {
                    Spacer()
                    DorrLogo(width: 156, onDark: wa.night)
                        .offset(y: (1 - rise) * 140)
                        .opacity(rise)
                    Text(L("app_name")).font(.dorr(28, .heavy)).foregroundStyle(wa.accent).padding(.top, 24)
                    Text(L("app_subtitle")).font(.dorr(14, .semibold)).foregroundStyle(muted)
                    ProgressView().tint(wa.accent).scaleEffect(0.9).frame(width: 28, height: 28).padding(.top, 12)
                    Spacer()
                    Text(L("app_tagline")).font(.dorr(12)).foregroundStyle(muted).padding(.bottom, 24)
                }
                .frame(maxWidth: .infinity)
                .padding(24)
                .opacity(alpha)
                .scaleEffect(scale)
            }
        }
        .task {
            start = Date()
            Task {
                if let dto = await GeneralApi.appearanceDefaults().value {
                    appearance.applyPlatform(light: dto.lightTokens, dark: dto.darkTokens)
                }
            }
            try? await Task.sleep(nanoseconds: 1_800_000_000)
            onFinished()
        }
    }

    /// The splash's own easing: back-out with c1 = 1.70158.
    private func overshoot(_ x: Double) -> Double {
        let c1 = 1.70158, c3 = c1 + 1, t = x - 1
        return 1 + c3 * t * t * t + c1 * t * t
    }
}

/// Compose's FastOutSlowInEasing (cubic-bezier 0.4, 0, 0.2, 1) at `t`.
func fastOutSlowIn(_ t: Double) -> Double {
    var low = 0.0, high = 1.0, s = t
    for _ in 0..<20 {
        s = (low + high) / 2
        let x = 3 * (1 - s) * (1 - s) * s * 0.4 + 3 * (1 - s) * s * s * 0.2 + s * s * s
        if x < t { low = s } else { high = s }
    }
    return 3 * (1 - s) * s * s * 1 + s * s * s
}
