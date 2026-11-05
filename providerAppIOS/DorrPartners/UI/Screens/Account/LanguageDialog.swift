import SwiftUI

// providerApp `LanguageDialog.kt` — switches the app language. `AppLanguage.set` persists the choice and the root view is
// rebuilt in it, so every `L(…)` and the RTL/LTR layout follow at once. Shown inside `.dorrDialog` (the scrim is there).
@MainActor
struct LanguageDialog: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var appLanguage = AppLanguage.shared
    let onDismiss: () -> Void

    @State private var languages: [AppLanguageDto] = []
    @State private var loading = true
    /// Code of the language whose strings are being downloaded; the other choices wait for it.
    @State private var downloading: String?

    var body: some View {
        DialogCard {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    PinkIcon(icon: "globe")
                    Text(L("language_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.accent)
                }
                .padding(.bottom, 12)
                if loading && languages.isEmpty {
                    Text(L("language_loading")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.bottom, 14)
                } else {
                    let shown = languages.isEmpty ? [
                        AppLanguageDto(id: 0, code: "ar", name: L("language_arabic"), direction: "rtl"),
                        AppLanguageDto(id: 0, code: "en", name: L("language_english"), direction: "ltr"),
                    ] : languages
                    VStack(spacing: 8) {
                        ForEach(shown, id: \.code) { language in
                            let code = language.code.lowercased()
                            LanguageOption(name: language.name ?? language.code, flag: languageFlagCode(language),
                                           selected: code == appLanguage.code.lowercased(), loading: downloading == code) {
                                choose(code)
                            }
                        }
                    }
                    .padding(.bottom, 14)
                }
                Button(action: onDismiss) {
                    Text(L("common_close")).font(.dorr(15, .bold)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity, minHeight: 44)
                        .background(wa.red, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        .shadow(color: Color(hex: 0x001B53).opacity(0.22), radius: 8, y: 3)
                }
                .buttonStyle(.plain)
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 18)
        }
        .task(id: network.reconnectTick) {
            languages = await TranslationsApi.list().value ?? []
            loading = false
            // Android `appLanguage.syncWith(languages)`: a newer version of the current downloaded language is fetched.
            await appLanguage.syncWithServer()
        }
    }

    private func choose(_ code: String) {
        guard downloading == nil, code != appLanguage.code.lowercased() else { return }
        Task {
            downloading = code
            let switched = await appLanguage.choose(code)
            downloading = nil
            if !switched { AppToast.show(L("language_download_failed")) }
        }
    }
}

private struct LanguageOption: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var appearance: AppearanceState
    let name: String
    let flag: String
    let selected: Bool
    var loading = false
    let onClick: () -> Void

    var body: some View {
        let dark = wa.night
        let fill: Color = dark && selected ? wa.darkWell : dark ? wa.darkCard : selected ? wa.red.opacity(0.14) : Color(hex: 0xFBF7F8)
        Button(action: onClick) {
            HStack(spacing: 10) {
                AsyncImage(url: URL(string: "https://flagcdn.com/w40/\(flag).png")) { phase in
                    if let image = phase.image { image.resizable().scaledToFill() } else { Color.clear }
                }
                .frame(width: 22, height: 16)
                .clipShape(RoundedRectangle(cornerRadius: 3))
                .frame(width: 34, height: 34)
                .background(dark ? wa.bg : appearance.color("background", .white, dark: false),
                            in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                Text(verbatim: name).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                if loading {
                    ProgressView().tint(wa.red).frame(width: 18, height: 18)
                } else {
                    Circle()
                        .stroke(selected ? wa.red : dark ? Color(hex: 0x8B93A0) : Color(hex: 0xF8BCA9), lineWidth: 2)
                        .frame(width: 18, height: 18)
                        .overlay { if selected { Circle().fill(wa.red).frame(width: 10, height: 10) } }
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .background(fill, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous)
                .stroke(selected ? wa.red : dark ? wa.line : .clear, lineWidth: 1.5))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

private func languageFlagCode(_ language: AppLanguageDto) -> String {
    let fromFlag = (language.flag?.code ?? "").trimmingCharacters(in: .whitespaces).lowercased()
    if fromFlag.count == 2 { return fromFlag }
    switch language.code.lowercased() {
    case "ar": return "sa"
    case "en": return "us"
    default: return String(language.code.lowercased().prefix(2))
    }
}
