import SwiftUI

/// freelanceApp `StartScreen.kt` — the welcome page: the app's identity, the language switch and "Get started". It is
/// shown once, the first time the app opens after install (see `Welcome`); every later launch goes straight to sign-in
/// or the home.
struct StartScreen: View {
    @Environment(\.wa) private var wa
    let onStart: () -> Void

    var body: some View {
        ZStack {
            PinkBackdrop()
            ScrollView {
                VStack(spacing: 0) {
                    HStack {
                        Spacer()
                        LanguageSwitch(label: L("start_language"))
                    }
                    DorrLogo(width: 132, onDark: wa.night).padding(.top, 48)
                    VStack(spacing: 0) {
                        PinkIcon(icon: "briefcase", box: 48)
                        Text(L("start_title")).font(.dorr(20, .heavy)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center).padding(.top, 14)
                        Text(L("start_body")).font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).lineSpacing(6).padding(.top, 8)
                        SettingsPrimaryButton(text: L("start_cta"), action: onStart).padding(.top, 20)
                    }
                    .padding(20)
                    .frame(maxWidth: 440)
                    .settingsSurface(24)
                    .padding(.top, 28)
                }
                .padding(.horizontal, 24)
                .padding(.vertical, 16)
            }
        }
    }
}
