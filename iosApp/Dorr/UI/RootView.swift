import SwiftUI

struct RootView: View {
    @EnvironmentObject private var router: AppRouter
    @EnvironmentObject private var network: NetworkMonitor

    var body: some View {
        ZStack {
            switch router.route {
            case .splash:
                SplashScreen().dorrThemed(platformOnly: true).transition(.opacity)
            case .onboarding:
                OnboardingScreen().dorrThemed(platformOnly: true).transition(.dorrSlide)
            case .login:
                LoginScreen().dorrThemed(platformOnly: true).transition(.dorrSlide)
            case .otp:
                OtpScreen().dorrThemed(platformOnly: true).transition(.dorrSlide)
            case .main:
                MainScreen().dorrThemed().transition(.dorrSlide)
            }
            if network.isOffline {
                NoInternetScreen().dorrThemed().transition(.opacity).zIndex(1)
            }
            // Android-style toasts, over everything.
            AppToastHost().zIndex(2)
        }
        .animation(.easeInOut(duration: 0.25), value: network.isOffline)
    }
}

extension AnyTransition {
    /// The NavHost slide + fade on Android (start edge in, follows RTL).
    static var dorrSlide: AnyTransition {
        .asymmetric(
            insertion: .move(edge: .trailing).combined(with: .opacity),
            removal: .move(edge: .leading).combined(with: .opacity)
        )
    }
}
