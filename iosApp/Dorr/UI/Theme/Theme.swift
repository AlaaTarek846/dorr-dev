import SwiftUI

/// androidApp `ui/theme/Color.kt` — fallback brand palette. Live colors come from the
/// appearance tokens ([AppearanceState.color]); never hard-code hex in a screen.
enum AppColors {
    static let brandNavy = Color(hex: 0x001B53)
    static let brandOrange = Color(hex: 0xFA7552)

    static let primary = brandNavy
    static let primaryLight = Color(hex: 0x1E3A7B)
    static let primaryDark = Color(hex: 0x00113A)
    static let secondary = brandOrange
    static let accent = brandOrange

    static let danger = Color(hex: 0xF05252)
    static let success = Color(hex: 0x059669)
    static let warning = Color(hex: 0xF59E0B)
    static let info = Color(hex: 0x06B6D4)

    static let otpPinkBorder = Color(hex: 0xFBD2C4)
    static let otpGlowSoft = Color(hex: 0xFCDACF)

    static let background = Color(hex: 0xF9FAFB)
    static let surface = Color(hex: 0xFFFFFF)
    static let textPrimary = Color(hex: 0x111928)
    static let textSecondary = Color(hex: 0x6B7280)
    static let textMuted = Color(hex: 0x9CA3AF)
    static let border = Color(hex: 0xE5E7EB)

    static let darkBackground = Color(hex: 0x111928)
    static let darkSurface = Color(hex: 0x1F2937)
    static let darkTextPrimary = Color(hex: 0xF9FAFB)
    static let darkTextSecondary = Color(hex: 0x9CA3AF)
    static let darkBorder = Color(hex: 0x4B5563)
}

extension Color {
    init(hex: UInt32) {
        self.init(
            red: Double((hex >> 16) & 0xFF) / 255,
            green: Double((hex >> 8) & 0xFF) / 255,
            blue: Double(hex & 0xFF) / 255
        )
    }

    /// androidApp `hexToColor` — "#RRGGBB" only, anything else is nil.
    init?(hexString raw: String?) {
        guard var hex = raw?.trimmingCharacters(in: .whitespaces) else { return nil }
        if hex.hasPrefix("#") { hex.removeFirst() }
        guard hex.count == 6, let value = UInt32(hex, radix: 16) else { return nil }
        self.init(hex: value)
    }
}

/// androidApp `ui/theme/Appearance.kt` — user appearance after login plus platform
/// defaults from `GET general/v1/mobile-appearance-defaults` (pre-login screens).
@MainActor
final class AppearanceState: ObservableObject {
    static let shared = AppearanceState()

    @Published private(set) var snapshot: AppearanceDto? = AppearanceStore.loadUser()
    @Published private(set) var platformLight: [String: String]? = AppearanceStore.loadPlatformLight()
    @Published private(set) var platformDark: [String: String]? = AppearanceStore.loadPlatformDark()
    /// Bumped when the app font (Cairo or a downloaded one) changes, so every screen redraws with it.
    @Published private(set) var fontRevision = 0

    /// androidApp `MainActivity` → `AppFontLoader.load(snapshot.font)`.
    func loadFont() async {
        let family = await AppFontLoader.load(snapshot?.font)
        guard family != DorrFont.family else { return }
        DorrFont.use(family)
        fontRevision += 1
    }

    func apply(_ dto: AppearanceDto) {
        snapshot = dto
        AppearanceStore.saveUser(dto)
    }

    func applyPlatform(light: [String: String]?, dark: [String: String]?) {
        if let light, !light.isEmpty { platformLight = light }
        if let dark, !dark.isEmpty { platformDark = dark }
        AppearanceStore.savePlatform(light: platformLight, dark: platformDark)
    }

    /// Clears logged-in overrides only; platform defaults stay for Login / OTP.
    func clear() {
        snapshot = nil
        AppearanceStore.clearUser()
    }

    /// `nil` follows the phone.
    var colorSchemeOverride: ColorScheme? {
        switch snapshot?.darkMode {
        case "dark": return .dark
        case "light": return .light
        default: return nil
        }
    }

    /// androidApp `appearanceColor(key, fallback)`. Pre-login screens pass `platformOnly`.
    func color(_ key: String, _ fallback: Color, dark: Bool, platformOnly: Bool = false) -> Color {
        let userMap = platformOnly ? nil : (dark ? snapshot?.resolved?.darkTokens : snapshot?.resolved?.lightTokens)
        let map = userMap ?? (dark ? platformDark : platformLight)
        return Color(hexString: map?[key]) ?? fallback
    }
}

/// The resolved palette a screen reads (`MaterialTheme.colorScheme` on Android).
struct DorrPalette {
    let primary: Color
    let secondary: Color
    let danger: Color
    let background: Color
    let surface: Color
    let textPrimary: Color
    let textSecondary: Color
    let border: Color

    @MainActor
    static func resolve(_ appearance: AppearanceState, dark: Bool, platformOnly: Bool = false) -> DorrPalette {
        func c(_ key: String, _ light: Color, _ night: Color) -> Color {
            appearance.color(key, dark ? night : light, dark: dark, platformOnly: platformOnly)
        }
        return DorrPalette(
            primary: c("primary", AppColors.primary, AppColors.primaryLight),
            secondary: c("secondary", AppColors.secondary, AppColors.secondary),
            danger: c("danger", AppColors.danger, AppColors.danger),
            background: c("background", AppColors.background, AppColors.darkBackground),
            surface: c("surface", AppColors.surface, AppColors.darkSurface),
            textPrimary: c("textPrimary", AppColors.textPrimary, AppColors.darkTextPrimary),
            textSecondary: c("textSecondary", AppColors.textSecondary, AppColors.darkTextSecondary),
            border: c("border", AppColors.border, AppColors.darkBorder)
        )
    }
}

private struct PaletteKey: EnvironmentKey {
    static let defaultValue = DorrPalette(
        primary: AppColors.primary, secondary: AppColors.secondary, danger: AppColors.danger,
        background: AppColors.background, surface: AppColors.surface, textPrimary: AppColors.textPrimary,
        textSecondary: AppColors.textSecondary, border: AppColors.border
    )
}

extension EnvironmentValues {
    var palette: DorrPalette {
        get { self[PaletteKey.self] }
        set { self[PaletteKey.self] = newValue }
    }
}

/// Puts the resolved palette in the environment. Splash, onboarding, login and OTP keep the
/// platform default colors (`LocalUsePlatformColors` on Android).
struct DorrThemed: ViewModifier {
    @EnvironmentObject private var appearance: AppearanceState
    @Environment(\.colorScheme) private var scheme
    var platformOnly = false

    func body(content: Content) -> some View {
        content.environment(\.palette, DorrPalette.resolve(appearance, dark: scheme == .dark, platformOnly: platformOnly))
    }
}

extension View {
    func dorrThemed(platformOnly: Bool = false) -> some View {
        modifier(DorrThemed(platformOnly: platformOnly))
    }
}
