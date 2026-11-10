import SwiftUI

/// androidApp `OnboardingScreen.kt` — three swipeable pages: «‹ Skip» at the top, the picture large and sharp, then
/// (no card) the small label, the title, its highlighted line, the text, the «Next ‹» / «Start» button, the page dots
/// and «A safe and secure experience». The colours are the app's: the title and button in the primary colour (navy
/// by day), the highlight and the active dot in the accent (orange); by night the title is the light ink and the
/// button the night primary.
///
/// Every screen: the content is at most `maxWidth` wide, in the middle (an iPad looks like a phone, the button isn't
/// stretched); the picture takes about half the height (within 180–460pt), the skip row stays at the top and the
/// words / button at the bottom; when that doesn't fit (a small phone with a large font) the page scrolls instead of
/// cutting anything.
@MainActor
struct OnboardingScreen: View {
    @EnvironmentObject private var router: AppRouter
    @EnvironmentObject private var appearance: AppearanceState
    @Environment(\.wa) private var wa
    @State private var page = 0

    /// One page: its picture (an asset drawn from the SVGs in androidApp/design/onboarding/light), the small label
    /// over the title, the title, its highlighted line and the text.
    private struct Page {
        let image: String
        let eyebrow: String
        let title: String
        let highlight: String
        let body: String
    }

    private let pages: [Page] = [
        Page(image: "OnboardingChatAi", eyebrow: "onb_chat_eyebrow", title: "onb_chat_title", highlight: "onb_chat_highlight", body: "onb_chat_body"),
        Page(image: "OnboardingRidesServices", eyebrow: "onb_rides_eyebrow", title: "onb_rides_title", highlight: "onb_rides_highlight", body: "onb_rides_body"),
        Page(image: "OnboardingWallet", eyebrow: "onb_wallet_eyebrow", title: "onb_wallet_title", highlight: "onb_wallet_highlight", body: "onb_wallet_body"),
    ]

    /// The content's widest: a phone's width, kept on an iPad.
    private let maxWidth: CGFloat = 480

    var body: some View {
        let lastPage = page == pages.count - 1
        let muted = AuthColors.mut(wa)
        let titleInk = wa.night ? wa.ink : wa.red
        let accent = appearance.color("accent", Color(hex: 0xFA7552), dark: wa.night)
        ZStack {
            PinkBackdrop()
            GeometryReader { screen in
            let pictureHeight = min(max(screen.size.height * 0.46, 180), 460)
            ScrollView(.vertical, showsIndicators: false) {
            VStack(spacing: 0) {
                // «Skip» at the start (the right in Arabic), small: the word, then a chevron on its right pointing
                // right — «تخطي ›» / «Skip ›» in both languages (the pill's inside is laid out left-to-right).
                HStack {
                    // The sign-in's language button's look: see-through on the backdrop, a soft frame, the word and the
                    // chevron in the app's colour, bold.
                    Button(action: finish) {
                        HStack(spacing: 2) {
                            Text(L("onboarding_skip")).font(.dorr(13, .bold))
                            Image(systemName: "chevron.right").font(.system(size: 12, weight: .bold))
                        }
                        .environment(\.layoutDirection, .leftToRight)
                        .foregroundStyle(wa.night ? wa.ink : wa.red)
                        .padding(.leading, 12).padding(.trailing, 9)
                        .frame(height: 34)
                        .contentShape(Capsule())
                        .overlay(Capsule().stroke(AuthColors.line(wa), lineWidth: 1))
                    }
                    .buttonStyle(.plain)
                    Spacer()
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 10)

                // With the spacer under it: the picture sits in the middle of the room left on a tall screen (like
                // androidApp's SpaceBetween).
                Spacer(minLength: 0)

                // The picture, large and sharp.
                TabView(selection: $page) {
                    ForEach(pages.indices, id: \.self) { index in
                        GeometryReader { proxy in
                            Image(pages[index].image).resizable().interpolation(.high).scaledToFit()
                                .frame(width: proxy.size.width * 0.9)
                                .frame(maxWidth: .infinity, maxHeight: .infinity)
                        }
                        .padding(.horizontal, 20)
                        .tag(index)
                    }
                }
                .tabViewStyle(.page(indexDisplayMode: .never))
                .frame(height: pictureHeight)
                // Like androidApp (its pager is reversed under RTL): the next page comes in from the right in both
                // languages, so the dots below run left-to-right with it.
                .environment(\.layoutDirection, .leftToRight)
                .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32), value: page)

                // The skip row stays at the top and the words / button go to the bottom on a tall screen.
                Spacer(minLength: 12)

                // The words under it — they fade to the next page's with the swipe.
                let current = pages[page]
                VStack(spacing: 0) {
                    Text(L(current.eyebrow)).font(.dorr(13.5, .semibold)).foregroundStyle(muted)
                    Text(L(current.title)).font(.dorr(22, .heavy)).foregroundStyle(titleInk).padding(.top, 6)
                    Text(L(current.highlight)).font(.dorr(22, .heavy)).foregroundStyle(accent)
                    Text(L(current.body)).font(.dorr(14)).foregroundStyle(muted).lineSpacing(5)
                        .frame(maxWidth: 320)
                        .padding(.top, 10)
                }
                .multilineTextAlignment(.center)
                .padding(.horizontal, 26)
                .id(page)
                .transition(.opacity)
                .animation(.easeInOut(duration: 0.25), value: page)

                Button {
                    if lastPage { finish() } else { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32)) { page += 1 } }
                } label: {
                    // «التالي ›» / «Next ›»: the chevron on the word's right, pointing right — the way the next page
                    // comes in.
                    HStack(spacing: 4) {
                        Text(L(lastPage ? "onb_start" : "onboarding_next")).font(.dorr(16, .bold))
                        Image(systemName: "chevron.right").font(.system(size: 14, weight: .bold))
                    }
                    .environment(\.layoutDirection, .leftToRight)
                    .foregroundStyle(.white)
                    .frame(maxWidth: .infinity, minHeight: 52)
                    .background(wa.red, in: Capsule())
                    .shadow(color: wa.night ? .clear : wa.red.opacity(0.35), radius: 8, y: 4)
                }
                .buttonStyle(WaPressStyle(scale: 0.98))
                .padding(.horizontal, 20)
                .padding(.top, 20)

                // The page dots: the current one a short pill in the accent colour, the others small dots in the app's
                // faint colour (its frame colour by night) — left-to-right in both languages (the first page on the
                // left), moving the same way as the pages.
                HStack(spacing: 6) {
                    ForEach(pages.indices, id: \.self) { i in
                        Capsule().fill(i == page ? accent : (wa.night ? wa.line : AuthColors.soft(wa).opacity(0.45)))
                            .frame(width: i == page ? 22 : 7, height: 7)
                    }
                }
                .environment(\.layoutDirection, .leftToRight)
                .animation(.easeInOut(duration: 0.26), value: page)
                .padding(.top, 16)

                HStack(spacing: 5) {
                    // The shield in the success green (the admin's).
                    Image(systemName: "checkmark.shield").font(.system(size: 13, weight: .medium))
                        .foregroundStyle(AuthColors.success(wa))
                    Text(L("onb_safe")).font(.dorr(12)).foregroundStyle(muted)
                }
                .padding(.top, 14)
                .padding(.bottom, 14)
            }
            .frame(maxWidth: maxWidth)
            .frame(maxWidth: .infinity)
            .frame(minHeight: screen.size.height)
            }
            }
        }
    }

    private func finish() {
        OnboardingStore.markCompleted()
        router.go(routeAfterOnboarding(authenticated: AuthSession.shared.isAuthenticated))
    }
}
