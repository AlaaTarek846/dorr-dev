import SwiftUI

// driverApp `ui/SplashScreen.kt`.

/// The first thing on the screen, the same as the DORR app's: the logo rises and settles over the
/// glowing page while the platform's colours are read, then on to the sign-in or the shift.
@MainActor
struct SplashScreen: View {
    @Environment(\.pal) private var pal
    let onFinished: () -> Void
    @State private var start = Date()

    init(onFinished: @escaping () -> Void) {
        self.onFinished = onFinished
    }

    var body: some View {
        ZStack {
            PinkBackdrop()
            TimelineView(.animation) { context in
                let elapsed = context.date.timeIntervalSince(start)
                // fade: tween(900); scale: 0.85 → 1 with the overshoot easing, tween(900); rise: tween(1100, FastOutSlowIn).
                let fade = min(elapsed / 0.9, 1)
                let scale = 0.85 + 0.15 * splashOvershoot(min(elapsed / 0.9, 1))
                let rise = splashFastOutSlowIn(min(elapsed / 1.1, 1))
                WaWeightedColumn {
                    Color.clear.layoutWeight(3)
                    VStack(spacing: 0) {
                        DorrLogo(width: 156)
                            .offset(y: (1 - rise) * 140)
                            .opacity(rise)
                        Text(L("app_name")).font(.dorr(28, .heavy)).foregroundStyle(pal.accent).padding(.top, 24)
                        ZStack {
                            Circle().stroke(pal.accent.opacity(0.18), lineWidth: 2).frame(width: 26, height: 26)
                            CircularProgress(color: pal.accent, size: 28, lineWidth: 2)
                        }
                        .frame(width: 28, height: 28)
                        .padding(.top, 12)
                    }
                    .frame(maxWidth: .infinity)
                    Color.clear.layoutWeight(3)
                    Text(L("app_tagline")).font(.dorr(12)).foregroundStyle(pal.mut)
                        .frame(maxWidth: .infinity)
                        .padding(.bottom, 24)
                }
                .padding(24)
                .opacity(fade)
                .scaleEffect(scale)
            }
        }
        .task {
            start = Date()
            // The platform's colours, alongside (a slow server never holds the splash).
            Task {
                if let dto = await DriverApi.appearanceDefaults().value {
                    DriverPrefs.shared.applyPlatform(light: dto.lightTokens, dark: dto.darkTokens)
                }
            }
            try? await Task.sleep(nanoseconds: 1_800_000_000)
            onFinished()
        }
    }
}

/// Slightly overshoots 1 on the way in, then settles (the logo "lands").
private func splashOvershoot(_ x: Double) -> Double {
    let c1 = 1.70158
    let c3 = c1 + 1
    let t = x - 1
    return 1 + c3 * t * t * t + c1 * t * t
}

/// Compose's FastOutSlowInEasing (cubic-bezier 0.4, 0, 0.2, 1) at `t`.
private func splashFastOutSlowIn(_ t: Double) -> Double {
    var low = 0.0, high = 1.0, s = t
    for _ in 0..<20 {
        s = (low + high) / 2
        let x = 3 * (1 - s) * (1 - s) * s * 0.4 + 3 * (1 - s) * s * s * 0.2 + s * s * s
        if x < t { low = s } else { high = s }
    }
    return 3 * (1 - s) * s * s * 1 + s * s * s
}
