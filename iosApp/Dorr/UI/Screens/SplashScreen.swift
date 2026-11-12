import SwiftUI

/// androidApp `SplashScreen.kt` — starts exactly where the launch screen leaves off (the plain background, white or the
/// app's dark one, with the Dorr mark in a 160pt circle: `LaunchLogo` / `LaunchBackground` in the asset catalog), then
/// the app's backdrop fades in, the mark lifts a little and the name and tagline appear under it. It moves on once the
/// platform colours are here — at least 0.8 s (the motion), at most 1.5 s (never waiting on a slow network). No spinner.
@MainActor
struct SplashScreen: View {
    @EnvironmentObject private var router: AppRouter
    @EnvironmentObject private var appearance: AppearanceState
    @Environment(\.wa) private var wa
    @State private var start = Date()
    /// When the coloured part (backdrop, name) starts to show — later on the very first launch (see `.task`).
    @State private var revealAt: Date?

    private static let minSeconds = 0.8
    private static let maxSeconds = 1.5

    var body: some View {
        TimelineView(.animation) { context in
            let elapsed = context.date.timeIntervalSince(start)
            let lift = fastOutSlowIn(min(max((elapsed - 0.12) / 0.65, 0), 1))
            let reveal = revealAt.map { min(max(context.date.timeIntervalSince($0) / 0.5, 0), 1) } ?? 0
            ZStack {
                // The launch screen's colour: the first frame matches it exactly.
                (wa.night ? Color(hex: 0x111928) : Color.white).ignoresSafeArea()
                PinkBackdrop().opacity(reveal)
                // The mark in its circle (the launch screen's picture, `LaunchLogo`): light mark on #FDF1EC by day,
                // the dark one (white R) on #1F2937 by night.
                ZStack {
                    Circle().fill(wa.night ? Color(hex: 0x1F2937) : Color(hex: 0xFDF1EC))
                    Image("DorrLogoMark").resizable().scaledToFit().frame(width: 96)
                        .environment(\.colorScheme, wa.night ? .dark : .light)
                }
                .frame(width: 160, height: 160)
                .offset(y: -44 * lift)
                // The name under the circle once it has lifted.
                Text(L("app_name")).font(.dorr(26, .heavy)).foregroundStyle(wa.accent)
                    .opacity(reveal)
                    .offset(y: 66 + (1 - reveal) * 12)
                VStack {
                    Spacer()
                    Text(L("app_tagline")).font(.dorr(12))
                        .foregroundStyle(wa.night ? wa.mut : appearance.color("authTextMuted", AppColors.textSecondary, dark: false))
                        .padding(.bottom, 24)
                        .opacity(reveal)
                }
            }
        }
        .task {
            start = Date()
            // The very first launch has no colours saved yet: the coloured part (backdrop, name) waits for the
            // server's, so the first colours seen are the real ones. Every later launch has them saved and shows them
            // at once.
            let firstTime = (AppearanceStore.loadPlatformLight() ?? [:]).isEmpty
            if !firstTime { revealAt = Date().addingTimeInterval(0.2) }
            // The colours load alongside; a slow or unreachable backend must not hold the splash.
            let loader = Task {
                if let dto = try? await AppearanceApi.platformDefaults().data {
                    appearance.applyPlatform(light: dto.lightTokens, dark: dto.darkTokens)
                }
            }
            await withTaskGroup(of: Void.self) { group in
                group.addTask { await loader.value }
                group.addTask { try? await Task.sleep(nanoseconds: UInt64(Self.maxSeconds * 1_000_000_000)) }
                await group.next()
                group.cancelAll()
            }
            if firstTime {
                revealAt = Date()
                try? await Task.sleep(nanoseconds: 450_000_000)
            }
            let left = Self.minSeconds - Date().timeIntervalSince(start)
            if left > 0 { try? await Task.sleep(nanoseconds: UInt64(left * 1_000_000_000)) }
            router.go(routeAfterSplash(onboardingCompleted: OnboardingStore.isCompleted, authenticated: AuthSession.shared.isAuthenticated))
        }
    }
}

/// Compose's FastOutSlowInEasing (cubic-bezier 0.4, 0, 0.2, 1) at [t].
func fastOutSlowIn(_ t: Double) -> Double {
    var low = 0.0, high = 1.0, s = t
    for _ in 0..<20 {
        s = (low + high) / 2
        let x = 3 * (1 - s) * (1 - s) * s * 0.4 + 3 * (1 - s) * s * s * 0.2 + s * s * s
        if x < t { low = s } else { high = s }
    }
    return 3 * (1 - s) * s * s * 1 + s * s * s
}
