import SwiftUI

/// androidApp `OnboardingScreen.kt` — three swipeable pages (the same picture), a white card with the
/// dots, the title (its "wallet" word in the brand colour), the text and the next / start button.
@MainActor
struct OnboardingScreen: View {
    @EnvironmentObject private var router: AppRouter
    @Environment(\.wa) private var wa
    @State private var page = 0

    private let pages: [(title: String, body: String)] = [
        ("onboarding_services_title", "onboarding_services_body"),
        ("onboarding_wallet_title", "onboarding_wallet_body"),
        ("onboarding_account_title", "onboarding_account_body"),
    ]

    var body: some View {
        let lastPage = page == pages.count - 1
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                HStack {
                    Spacer()
                    Button(action: finish) {
                        HStack(spacing: 2) {
                            Text(L("onboarding_skip")).font(.dorr(14, .semibold))
                            Image(systemName: "arrow.forward").font(.system(size: 13, weight: .semibold))
                        }
                        .foregroundStyle(wa.night ? wa.mut : AppColors.textSecondary)
                        .padding(.horizontal, 14)
                        .padding(.vertical, 8)
                        .background(wa.night ? wa.darkCard : .white, in: Capsule())
                        .shadow(color: wa.night ? .clear : Color(hex: 0x111928).opacity(0.08), radius: 4, y: 2)
                    }
                    .buttonStyle(.plain)
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 10)

                TabView(selection: $page) {
                    ForEach(pages.indices, id: \.self) { index in
                        GeometryReader { proxy in
                            Image("Payment").resizable().scaledToFit()
                                .frame(width: (proxy.size.width) * 0.78)
                                .frame(maxWidth: .infinity, maxHeight: .infinity)
                        }
                        .padding(.horizontal, 28)
                        .padding(.vertical, 8)
                        .tag(index)
                    }
                }
                .tabViewStyle(.page(indexDisplayMode: .never))
                .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32), value: page)

                VStack(spacing: 0) {
                    DotIndicator(count: pages.count, activeIndex: page)
                    highlightedTitle(L(pages[page].title))
                        .font(.dorr(22, .heavy))
                        .multilineTextAlignment(.center)
                        .lineSpacing(4)
                        .padding(.top, 14)
                    Text(verbatim: "'\(L(pages[page].body))'")
                        .font(.dorr(14))
                        .foregroundStyle(wa.night ? wa.mut : AppColors.textSecondary)
                        .multilineTextAlignment(.center)
                        .lineSpacing(5)
                        .frame(maxWidth: 280)
                        .padding(.top, 8)
                    Button {
                        if lastPage { finish() } else { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32)) { page += 1 } }
                    } label: {
                        HStack(spacing: 6) {
                            Text(L(lastPage ? "onboarding_start" : "onboarding_next")).font(.dorr(16, .bold))
                            Image(systemName: "arrow.forward").font(.system(size: 15, weight: .semibold))
                        }
                        .foregroundStyle(.white)
                        .frame(maxWidth: .infinity, minHeight: 52)
                        .background(wa.red, in: Capsule())
                        .shadow(color: Color(hex: 0x001B53).opacity(0.4), radius: 8, y: 4)
                    }
                    .buttonStyle(WaPressStyle(scale: 0.98))
                    .padding(.top, 16)
                }
                .padding(.horizontal, 20)
                .padding(.vertical, 18)
                .frame(maxWidth: .infinity)
                .background(wa.night ? wa.darkCard : .white, in: RoundedRectangle(cornerRadius: 28, style: .continuous))
                .overlay { if wa.night { RoundedRectangle(cornerRadius: 28, style: .continuous).stroke(wa.line, lineWidth: 1) } }
                .shadow(color: wa.night ? .clear : Color(hex: 0x111928).opacity(0.22), radius: 18, y: 6)
                .padding(.horizontal, 18)
                .padding(.bottom, 20)
            }
        }
    }

    /// The title with its first "wallet" word (محفظتك / محفظة / wallet / Wallet) in the brand colour.
    private func highlightedTitle(_ text: String) -> Text {
        let ink = wa.night ? wa.ink : AppColors.textPrimary
        let match = ["محفظتك", "محفظة", "wallet", "Wallet"]
            .compactMap { word in text.range(of: word).map { ($0, word) } }
            .min { $0.0.lowerBound < $1.0.lowerBound }
        guard let (range, _) = match else { return Text(verbatim: text).foregroundColor(ink) }
        return Text(verbatim: String(text[..<range.lowerBound])).foregroundColor(ink)
            + Text(verbatim: String(text[range])).foregroundColor(wa.red)
            + Text(verbatim: String(text[range.upperBound...])).foregroundColor(ink)
    }

    private func finish() {
        OnboardingStore.markCompleted()
        router.go(routeAfterOnboarding(authenticated: AuthSession.shared.isAuthenticated))
    }
}
