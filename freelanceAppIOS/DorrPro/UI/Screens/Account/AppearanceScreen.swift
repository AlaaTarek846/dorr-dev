import SwiftUI

// freelanceApp `AppearanceScreen.kt` — colours and mode: dark mode (system / light / dark), the light and dark palettes
// (primary, background, text — each from a colour picker), saved on the account (`PUT appearance`), and back to the defaults.

@MainActor
struct AppearanceScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var appearance: AppearanceState
    let onBack: () -> Void

    @State private var busy = false
    @State private var lightPrimary = "#E50914"
    @State private var lightBackground = "#FFFFFF"
    @State private var lightText = "#111928"
    @State private var darkPrimary = "#FF4D57"
    @State private var darkBackground = "#101216"
    @State private var darkText = "#F4F5F7"
    @State private var lightEdited = false
    @State private var darkEdited = false

    private var snap: AppearanceDto? { appearance.snapshot }

    var body: some View {
        let mode = snap?.darkMode ?? "system"
        let usingDefaults = snap?.usesDefaultColors != false
        let showLight = mode == "light" || mode == "system"
        let showDark = mode == "dark" || mode == "system"
        let darkFirst = mode == "system" && wa.night
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("appearance_title"), onBack: onBack)
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("appearance_mode")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                        HStack(spacing: 8) {
                            ModeChip(label: L("appearance_mode_system"), icon: "circle.lefthalf.filled", selected: mode == "system") { push(["dark_mode": "system"]) }
                            ModeChip(label: L("appearance_mode_light"), icon: "sun.max.fill", selected: mode == "light") { push(["dark_mode": "light"]) }
                            ModeChip(label: L("appearance_mode_dark"), icon: "moon.fill", selected: mode == "dark") { push(["dark_mode": "dark"]) }
                        }
                        .padding(.top, 8)
                        Text(usingDefaults ? L("appearance_using_defaults") : L("appearance_custom"))
                            .font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.top, 8)
                        Spacer().frame(height: 18)
                        if showLight && showDark && darkFirst {
                            darkColorsBlock
                            Spacer().frame(height: 18)
                            lightColorsBlock
                        } else if showLight && showDark {
                            lightColorsBlock
                            Spacer().frame(height: 18)
                            darkColorsBlock
                        } else if showLight {
                            lightColorsBlock
                        } else if showDark {
                            darkColorsBlock
                        }
                        if showLight && showDark {
                            Text(L("appearance_separate_modes")).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 8)
                        }
                        Button(action: save) {
                            ZStack {
                                if busy {
                                    ProgressView().tint(.white).frame(width: 18, height: 18)
                                } else {
                                    Text(L("appearance_save")).font(.dorr(14, .bold)).foregroundStyle(.white)
                                }
                            }
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 14)
                            .background(wa.red, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                        .buttonStyle(.plain)
                        .disabled(busy)
                        .padding(.top, 16)
                        if !usingDefaults {
                            Button {
                                push(["uses_default_colors": true]) { AppToast.show(L("appearance_saved")) }
                            } label: {
                                Text(L("appearance_reset")).font(.dorr(14, .bold)).foregroundStyle(wa.red)
                                    .frame(maxWidth: .infinity)
                                    .padding(.vertical, 14)
                                    .settingsSurface(14)
                            }
                            .buttonStyle(.plain)
                            .disabled(busy)
                            .padding(.top, 10)
                        }
                    }
                    .padding(.horizontal, 14)
                    .padding(.bottom, 24)
                }
            }
        }
        .navigationBarHidden(true)
        .onAppear(perform: reset)
        // `remember(snap)`: a new snapshot puts the picked colours back to what it holds.
        .onChange(of: appearance.snapshot) { _ in reset() }
    }

    private var lightColorsBlock: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(L("appearance_light_colors")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
            ColorField(label: L("appearance_primary"), value: lightPrimary) { lightPrimary = $0; lightEdited = true }
            ColorField(label: L("appearance_background"), value: lightBackground) { lightBackground = $0; lightEdited = true }
            ColorField(label: L("appearance_text"), value: lightText) { lightText = $0; lightEdited = true }
        }
    }

    private var darkColorsBlock: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(L("appearance_dark_colors")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
            ColorField(label: L("appearance_primary"), value: darkPrimary) { darkPrimary = $0; darkEdited = true }
            ColorField(label: L("appearance_background"), value: darkBackground) { darkBackground = $0; darkEdited = true }
            ColorField(label: L("appearance_text"), value: darkText) { darkText = $0; darkEdited = true }
        }
    }

    private func reset() {
        let snap = snap
        let lightStored = snap?.usesDefaultColors == false ? snap?.customLightTokens : nil
        let darkStored = snap?.usesDefaultColors == false ? snap?.customDarkTokens : nil
        let darkChosen = !(darkStored ?? [:]).isEmpty && !samePalette(lightStored, darkStored)
        lightPrimary = pickedOrDefault(lightStored, snap, "primary", night: false, "#E50914")
        lightBackground = pickedOrDefault(lightStored, snap, "background", night: false, "#FFFFFF")
        lightText = pickedOrDefault(lightStored, snap, "textPrimary", night: false, "#111928")
        darkPrimary = pickedOrDefault(darkChosen ? darkStored : nil, snap, "primary", night: true, "#FF4D57")
        darkBackground = pickedOrDefault(darkChosen ? darkStored : nil, snap, "background", night: true, "#101216")
        darkText = pickedOrDefault(darkChosen ? darkStored : nil, snap, "textPrimary", night: true, "#F4F5F7")
        lightEdited = !(lightStored ?? [:]).isEmpty
        darkEdited = darkChosen
    }

    private func save() {
        // Gson leaves the null fields out of the body: only what is set is sent.
        var body: JsonBody = [:]
        if lightEdited || darkEdited { body["uses_default_colors"] = false }
        if lightEdited { body["custom_light_tokens"] = palette(lightPrimary, lightBackground, lightText) }
        if darkEdited { body["custom_dark_tokens"] = palette(darkPrimary, darkBackground, darkText) }
        push(body) { AppToast.show(L("appearance_saved")) }
    }

    private func push(_ body: JsonBody, onDone: (() -> Void)? = nil) {
        if busy { return }
        busy = true
        Task {
            let result = await AppearanceApi.update(body)
            if case .ok(let dto, _) = result {
                appearance.apply(dto)
                onDone?()
            } else {
                AppToast.show(result.errorText(L("login_error_offline")) ?? L("appearance_failed"))
            }
            busy = false
        }
    }
}

private struct ModeChip: View {
    @Environment(\.wa) private var wa
    let label: String
    let icon: String
    let selected: Bool
    let onClick: () -> Void

    var body: some View {
        let tint = selected ? Color.white : wa.settingsInk
        Button(action: onClick) {
            HStack(spacing: 4) {
                Image(systemName: icon).font(.system(size: 14, weight: .semibold))
                Text(verbatim: label).font(.dorr(12, .bold)).lineLimit(1)
            }
            .foregroundStyle(tint)
            .frame(maxWidth: .infinity)
            .padding(.horizontal, 6)
            .padding(.vertical, 12)
            .modifier(ChipSurface(selected: selected))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

private struct ChipSurface: ViewModifier {
    @Environment(\.wa) private var wa
    let selected: Bool

    func body(content: Content) -> some View {
        if selected {
            content.background(wa.red, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        } else {
            content.settingsSurface(12)
        }
    }
}

private struct ColorField: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String
    let onValueChange: (String) -> Void
    @State private var open = false

    var body: some View {
        let swatch = hexToColor(value) ?? Color(hex: 0x001B53)
        VStack(alignment: .leading, spacing: 8) {
            Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
            Button { open = true } label: {
                HStack {
                    Circle().fill(swatch).frame(width: 22, height: 22)
                        .overlay(Circle().stroke(wa.night ? wa.line : Color(hex: 0xE5E7EB), lineWidth: 1))
                    Spacer()
                }
                .padding(.horizontal, 14)
                .frame(height: 44)
                .background(wa.night ? wa.bg : Color(hex: 0xFBF7F8), in: Capsule())
                .overlay(Capsule().stroke(wa.night ? wa.line : Color(hex: 0xF3D5DB), lineWidth: 1))
                .contentShape(Capsule())
            }
            .buttonStyle(.plain)
        }
        .dorrDialog(isPresented: $open) {
            ColorPickerDialog(initial: value, onConfirm: { picked in
                onValueChange(picked)
                open = false
            })
        }
    }
}

private struct ColorPickerDialog: View {
    @Environment(\.wa) private var wa
    let onConfirm: (String) -> Void
    @State private var hue: Double
    @State private var sat: Double
    @State private var value: Double

    init(initial: String, onConfirm: @escaping (String) -> Void) {
        self.onConfirm = onConfirm
        let start = rgbToHsv(hexToRgb(initial) ?? (0x00, 0x1B, 0x53))
        _hue = State(initialValue: start.h)
        _sat = State(initialValue: start.s)
        _value = State(initialValue: start.v)
    }

    var body: some View {
        let rgb = hsvToRgb(hue, sat, value)
        let color = rgbColor(rgb)
        VStack(spacing: 12) {
            SaturationPlane(hue: hue, saturation: sat, value: value) { s, v in
                sat = s
                value = v
            }
            HStack(spacing: 12) {
                Circle().fill(color).frame(width: 36, height: 36).overlay(Circle().stroke(Color(hex: 0xE5E7EB), lineWidth: 1))
                HueSlider(hue: hue) { hue = $0 }
            }
            HStack(spacing: 8) {
                RgbBox(label: "R", channel: rgb.0) { setChannel(0, $0) }
                RgbBox(label: "G", channel: rgb.1) { setChannel(1, $0) }
                RgbBox(label: "B", channel: rgb.2) { setChannel(2, $0) }
            }
            Button { onConfirm(hsvToHex(hue, sat, value)) } label: {
                Text(L("common_save")).font(.dorr(14, .bold)).foregroundStyle(.white)
                    .frame(maxWidth: .infinity, minHeight: 44)
                    .background(color, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            .buttonStyle(.plain)
        }
        .padding(12)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .shadow(color: .black.opacity(0.2), radius: 16, y: 6)
        .frame(maxWidth: 340)
        .padding(.horizontal, 24)
        .contentShape(Rectangle())
        .onTapGesture {}
    }

    private func setChannel(_ index: Int, _ channel: Int) {
        var next = hsvToRgb(hue, sat, value)
        switch index {
        case 0: next.0 = channel
        case 1: next.1 = channel
        default: next.2 = channel
        }
        let picked = rgbToHsv(next)
        hue = picked.h
        sat = picked.s
        value = picked.v
    }
}

private struct SaturationPlane: View {
    let hue: Double
    let saturation: Double
    let value: Double
    let onChange: (Double, Double) -> Void

    var body: some View {
        GeometryReader { proxy in
            let size = proxy.size
            let pure = rgbColor(hsvToRgb(hue, 1, 1))
            ZStack(alignment: .topLeading) {
                LinearGradient(colors: [.white, pure], startPoint: .leading, endPoint: .trailing)
                LinearGradient(colors: [.clear, .black], startPoint: .top, endPoint: .bottom)
                ZStack {
                    Circle().stroke(Color.white, lineWidth: 2)
                    Circle().stroke(Color.black.opacity(0.35), lineWidth: 1)
                }
                .frame(width: 22, height: 22)
                .position(x: saturation * size.width, y: (1 - value) * size.height)
            }
            .contentShape(Rectangle())
            .gesture(DragGesture(minimumDistance: 0).onChanged { drag in
                let s = min(max(drag.location.x / max(size.width, 1), 0), 1)
                let v = min(max(1 - drag.location.y / max(size.height, 1), 0), 1)
                onChange(s, v)
            })
        }
        .frame(height: 160)
        .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
        // Drawn in absolute positions (a Canvas on Android), never mirrored.
        .environment(\.layoutDirection, .leftToRight)
    }
}

private struct HueSlider: View {
    let hue: Double
    let onChange: (Double) -> Void

    var body: some View {
        GeometryReader { proxy in
            let size = proxy.size
            ZStack(alignment: .topLeading) {
                LinearGradient(colors: [0, 60, 120, 180, 240, 300, 360].map { rgbColor(hsvToRgb(Double($0), 1, 1)) },
                               startPoint: .leading, endPoint: .trailing)
                ZStack {
                    Circle().fill(Color.white)
                    Circle().fill(rgbColor(hsvToRgb(hue, 1, 1))).padding(3)
                }
                .frame(width: size.height, height: size.height)
                .position(x: CGFloat(min(max(hue / 360, 0), 1)) * size.width, y: size.height / 2)
            }
            .contentShape(Rectangle())
            .gesture(DragGesture(minimumDistance: 0).onChanged { drag in
                onChange(Double(min(max(drag.location.x / max(size.width, 1), 0), 1)) * 360)
            })
        }
        .frame(height: 18)
        .clipShape(Capsule())
        .environment(\.layoutDirection, .leftToRight)
    }
}

private struct RgbBox: View {
    @Environment(\.wa) private var wa
    let label: String
    let channel: Int
    let onChannel: (Int) -> Void
    @State private var text = ""
    @FocusState private var focused: Bool

    var body: some View {
        VStack(spacing: 0) {
            TextField("", text: $text)
                .keyboardType(.numberPad)
                .multilineTextAlignment(.center)
                .font(.dorr(16, .bold))
                .foregroundStyle(wa.settingsInk)
                .tint(wa.red)
                .focused($focused)
                .frame(height: 40)
                .overlay(RoundedRectangle(cornerRadius: 8, style: .continuous).stroke(wa.night ? wa.line : Color(hex: 0xE5E7EB), lineWidth: 1))
                .onChange(of: text) { raw in
                    let digits = String(raw.filter(\.isNumber).prefix(3))
                    if digits != raw { text = digits; return }
                    if focused, let number = Int(digits) { onChannel(min(max(number, 0), 255)) }
                }
            Text(verbatim: label).font(.dorr(12)).foregroundStyle(wa.settingsMut)
        }
        .frame(maxWidth: .infinity)
        .onAppear { text = String(channel) }
        .onChange(of: channel) { new in if !focused { text = String(new) } }
        .onChange(of: focused) { now in if !now { text = String(channel) } }
    }
}

// MARK: - Colours

private func palette(_ primary: String, _ background: String, _ text: String) -> [String: String] {
    ["primary": primary, "background": background, "textPrimary": text]
}

private func pickedOrDefault(_ custom: [String: String]?, _ snap: AppearanceDto?, _ key: String, night: Bool, _ fallback: String) -> String {
    if let picked = custom?[key].flatMap(normalizeBrandHex) { return picked }
    let defaults = night ? snap?.default?.darkTokens?[key] : snap?.default?.lightTokens?[key]
    return normalizeBrandHex(defaults ?? fallback) ?? fallback
}

private func samePalette(_ light: [String: String]?, _ dark: [String: String]?) -> Bool {
    guard let light, let dark, !light.isEmpty, !dark.isEmpty else { return false }
    return ["primary", "background", "textPrimary"].allSatisfy { key in
        let left = light[key].flatMap(normalizeBrandHex)
        let right = dark[key].flatMap(normalizeBrandHex)
        return left != nil && left == right
    }
}

private func normalizeBrandHex(_ raw: String) -> String? {
    var hex = raw.trimmingCharacters(in: .whitespaces)
    if hex.hasPrefix("#") { hex.removeFirst() }
    guard hex.count == 6, hex.allSatisfy(\.isHexDigit) else { return nil }
    return "#" + hex.uppercased()
}

private func hexToRgb(_ hex: String) -> (Int, Int, Int)? {
    guard let clean = normalizeBrandHex(hex), let value = Int(clean.dropFirst(), radix: 16) else { return nil }
    return ((value >> 16) & 0xFF, (value >> 8) & 0xFF, value & 0xFF)
}

private func hexToColor(_ hex: String) -> Color? { hexToRgb(hex).map(rgbColor) }

private func rgbColor(_ rgb: (Int, Int, Int)) -> Color {
    Color(red: Double(rgb.0) / 255, green: Double(rgb.1) / 255, blue: Double(rgb.2) / 255)
}

/// Android `Color.HSVToColor`: hue 0–360, saturation and value 0–1 → 0–255 channels.
private func hsvToRgb(_ hue: Double, _ saturation: Double, _ value: Double) -> (Int, Int, Int) {
    let h = min(max(hue, 0), 360)
    let s = min(max(saturation, 0), 1)
    let v = min(max(value, 0), 1)
    let c = v * s
    let hp = (h.truncatingRemainder(dividingBy: 360)) / 60
    let x = c * (1 - abs(hp.truncatingRemainder(dividingBy: 2) - 1))
    let (r1, g1, b1): (Double, Double, Double)
    switch hp {
    case 0..<1: (r1, g1, b1) = (c, x, 0)
    case 1..<2: (r1, g1, b1) = (x, c, 0)
    case 2..<3: (r1, g1, b1) = (0, c, x)
    case 3..<4: (r1, g1, b1) = (0, x, c)
    case 4..<5: (r1, g1, b1) = (x, 0, c)
    default: (r1, g1, b1) = (c, 0, x)
    }
    let m = v - c
    func channel(_ x: Double) -> Int { min(max(Int(((x + m) * 255).rounded()), 0), 255) }
    return (channel(r1), channel(g1), channel(b1))
}

/// Android `Color.colorToHSV`.
private func rgbToHsv(_ rgb: (Int, Int, Int)) -> (h: Double, s: Double, v: Double) {
    let r = Double(rgb.0) / 255, g = Double(rgb.1) / 255, b = Double(rgb.2) / 255
    let maxC = max(r, g, b), minC = min(r, g, b)
    let delta = maxC - minC
    var h: Double = 0
    if delta > 0 {
        if maxC == r { h = 60 * ((g - b) / delta).truncatingRemainder(dividingBy: 6) }
        else if maxC == g { h = 60 * ((b - r) / delta + 2) }
        else { h = 60 * ((r - g) / delta + 4) }
    }
    if h < 0 { h += 360 }
    return (h, maxC == 0 ? 0 : delta / maxC, maxC)
}

private func hsvToHex(_ hue: Double, _ saturation: Double, _ value: Double) -> String {
    let rgb = hsvToRgb(hue, saturation, value)
    return String(format: "#%02X%02X%02X", rgb.0, rgb.1, rgb.2)
}
