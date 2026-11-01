import SwiftUI
import UIKit

// androidApp `profile/AppearanceScreen.kt`.

@MainActor
struct AppearanceScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var appearance: AppearanceState
    @EnvironmentObject private var session: AuthSession
    let onBack: () -> Void

    @State private var busy = false
    @State private var lightPrimary = ""
    @State private var lightBackground = ""
    @State private var lightText = ""
    @State private var darkPrimary = ""
    @State private var darkBackground = ""
    @State private var darkText = ""
    @State private var lightEdited = false
    @State private var darkEdited = false

    private var snap: AppearanceDto? { appearance.snapshot }
    private var mode: String { snap?.darkMode ?? "system" }
    private var usingDefaults: Bool { snap?.usesDefaultColors != false }

    var body: some View {
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
                            ModeChip(label: L("appearance_mode_system"), icon: "circle.lefthalf.filled", selected: mode == "system") { push(AppearanceUpdateRequest(darkMode: "system")) }
                            ModeChip(label: L("appearance_mode_light"), icon: "sun.max.fill", selected: mode == "light") { push(AppearanceUpdateRequest(darkMode: "light")) }
                            ModeChip(label: L("appearance_mode_dark"), icon: "moon.fill", selected: mode == "dark") { push(AppearanceUpdateRequest(darkMode: "dark")) }
                        }
                        .padding(.top, 8)
                        Text(L(usingDefaults ? "appearance_using_defaults" : "appearance_custom")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                            .padding(.top, 8)
                        Spacer().frame(height: 18)

                        if showLight && showDark && darkFirst {
                            darkBlock
                            Spacer().frame(height: 18)
                            lightBlock
                        } else if showLight && showDark {
                            lightBlock
                            Spacer().frame(height: 18)
                            darkBlock
                        } else if showLight {
                            lightBlock
                        } else if showDark {
                            darkBlock
                        }
                        if showLight && showDark {
                            Text(L("appearance_separate_modes")).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 8)
                        }

                        Button(action: save) {
                            ZStack {
                                if busy { ProgressView().tint(.white).scaleEffect(0.8) } else { Text(L("appearance_save")).font(.dorr(15, .bold)) }
                            }
                            .foregroundStyle(.white)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 14)
                            .background(wa.red, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                        .buttonStyle(.plain)
                        .allowsHitTesting(!busy)
                        .padding(.top, 16)

                        if !usingDefaults {
                            Button {
                                push(AppearanceUpdateRequest(usesDefaultColors: true)) { AppToast.show(L("appearance_saved")) }
                            } label: {
                                Text(L("appearance_reset")).font(.dorr(15, .bold)).foregroundStyle(wa.red)
                                    .frame(maxWidth: .infinity)
                                    .padding(.vertical, 14)
                                    .settingsSurface(14)
                            }
                            .buttonStyle(.plain)
                            .allowsHitTesting(!busy)
                            .padding(.top, 10)
                        }
                    }
                    .padding(.horizontal, 14)
                    .padding(.bottom, 24)
                }
            }
        }
        .onAppear(perform: reset)
        .onChange(of: snapshotKey) { _ in reset() }
    }

    @ViewBuilder private var lightBlock: some View {
        Text(L("appearance_light_colors")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 12)
        ColorField(label: L("appearance_primary"), value: lightPrimary) { lightPrimary = $0; lightEdited = true }
        ColorField(label: L("appearance_background"), value: lightBackground) { lightBackground = $0; lightEdited = true }.padding(.top, 12)
        ColorField(label: L("appearance_text"), value: lightText) { lightText = $0; lightEdited = true }.padding(.top, 12)
    }

    @ViewBuilder private var darkBlock: some View {
        Text(L("appearance_dark_colors")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 12)
        ColorField(label: L("appearance_primary"), value: darkPrimary) { darkPrimary = $0; darkEdited = true }
        ColorField(label: L("appearance_background"), value: darkBackground) { darkBackground = $0; darkEdited = true }.padding(.top, 12)
        ColorField(label: L("appearance_text"), value: darkText) { darkText = $0; darkEdited = true }.padding(.top, 12)
    }

    /// `remember(snap)` — the picked colours start again whenever the saved appearance changes.
    private var snapshotKey: String {
        guard let snap else { return "nil" }
        return [snap.usesDefaultColors.map { "\($0)" } ?? "-", snap.darkMode ?? "-",
                (snap.customLightTokens ?? [:]).sorted { $0.key < $1.key }.map { "\($0.key)=\($0.value)" }.joined(separator: ","),
                (snap.customDarkTokens ?? [:]).sorted { $0.key < $1.key }.map { "\($0.key)=\($0.value)" }.joined(separator: ",")]
            .joined(separator: "|")
    }

    private func reset() {
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
        push(AppearanceUpdateRequest(
            usesDefaultColors: lightEdited || darkEdited ? false : nil,
            customLightTokens: lightEdited ? palette(lightPrimary, lightBackground, lightText) : nil,
            customDarkTokens: darkEdited ? palette(darkPrimary, darkBackground, darkText) : nil
        )) { AppToast.show(L("appearance_saved")) }
    }

    private func push(_ body: AppearanceUpdateRequest, onDone: (() -> Void)? = nil) {
        guard let token = session.token, !token.isEmpty, !busy else { return }
        busy = true
        Task {
            do {
                if let dto = try await AppearanceApi.update(body).data, session.token == token {
                    appearance.apply(dto)
                    onDone?()
                }
            } catch {
                AppToast.show(error.apiFailure.message ?? L("appearance_failed"))
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
    let action: () -> Void

    var body: some View {
        let tint: Color = selected ? .white : wa.settingsInk
        Button(action: action) {
            HStack(spacing: 4) {
                Image(systemName: icon).font(.system(size: 13, weight: .semibold))
                Text(verbatim: label).font(.dorr(12, .bold)).lineLimit(1)
            }
            .foregroundStyle(tint)
            .frame(maxWidth: .infinity)
            .padding(.horizontal, 6)
            .padding(.vertical, 12)
            .background {
                if selected {
                    RoundedRectangle(cornerRadius: 12, style: .continuous).fill(wa.red)
                } else {
                    Color.clear.settingsSurface(12)
                }
            }
        }
        .buttonStyle(.plain)
    }
}

private struct ColorField: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String
    let onChange: (String) -> Void
    @State private var open = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 8)
            Button { open = true } label: {
                HStack {
                    Circle().fill(Color(hexString: value) ?? Color(hex: 0x001B53))
                        .frame(width: 22, height: 22)
                        .overlay(Circle().stroke(wa.night ? wa.line : Color(hex: 0xE5E7EB), lineWidth: 1))
                    Spacer(minLength: 0)
                }
                .padding(.horizontal, 14)
                .frame(maxWidth: .infinity, minHeight: 44, maxHeight: 44)
                .background(wa.night ? wa.bg : Color(hex: 0xFBF7F8), in: Capsule())
                .overlay(Capsule().stroke(wa.night ? wa.line : Color(hex: 0xF3D5DB), lineWidth: 1))
            }
            .buttonStyle(.plain)
        }
        .dorrDialog(isPresented: $open) {
            ColorPickerDialog(initial: value, onConfirm: { onChange($0); open = false }, onDismiss: { open = false })
        }
    }
}

private struct ColorPickerDialog: View {
    @Environment(\.wa) private var wa
    let onConfirm: (String) -> Void
    let onDismiss: () -> Void
    @State private var hue: Double
    @State private var sat: Double
    @State private var val: Double

    init(initial: String, onConfirm: @escaping (String) -> Void, onDismiss: @escaping () -> Void) {
        self.onConfirm = onConfirm
        self.onDismiss = onDismiss
        let start = hsv(fromHex: initial) ?? hsv(fromHex: "#001B53")!
        _hue = State(initialValue: start.h)
        _sat = State(initialValue: start.s)
        _val = State(initialValue: start.v)
    }

    var body: some View {
        let color = hsvColor(hue, sat, val)
        let rgb = hsvToRgb(hue, sat, val)
        ZStack {
            Color.black.opacity(0.001).ignoresSafeArea().onTapGesture(perform: onDismiss)
            VStack(spacing: 12) {
                SaturationPlane(hue: hue, saturation: sat, value: val) { sat = $0; val = $1 }
                HStack(spacing: 12) {
                    Circle().fill(color).frame(width: 36, height: 36).overlay(Circle().stroke(Color(hex: 0xE5E7EB), lineWidth: 1))
                    HueSlider(hue: hue) { hue = $0 }
                }
                HStack(spacing: 8) {
                    ForEach(0..<3, id: \.self) { index in
                        RgbBox(label: ["R", "G", "B"][index], channel: rgb[index]) { channel in
                            var next = hsvToRgb(hue, sat, val)
                            next[index] = channel
                            let picked = rgbToHsv(next)
                            hue = picked.h
                            sat = picked.s
                            val = picked.v
                        }
                    }
                }
                Button { onConfirm(hsvToHex(hue, sat, val)) } label: {
                    Text(L("common_save")).font(.dorr(15, .bold)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity, minHeight: 44)
                        .background(color, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                .buttonStyle(.plain)
            }
            .padding(12)
            .frame(maxWidth: 340)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .shadow(color: .black.opacity(0.2), radius: 16, y: 6)
            .padding(.horizontal, 24)
        }
        // The picker always reads left to right (saturation and hue grow to the right), as on Android.
        .environment(\.layoutDirection, .leftToRight)
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
            ZStack(alignment: .topLeading) {
                LinearGradient(colors: [.white, hsvColor(hue, 1, 1)], startPoint: .leading, endPoint: .trailing)
                LinearGradient(colors: [.clear, .black], startPoint: .top, endPoint: .bottom)
                let center = CGPoint(x: saturation * size.width, y: (1 - value) * size.height)
                Circle().stroke(Color.white, lineWidth: 2).frame(width: 22, height: 22).position(center)
                Circle().stroke(Color.black.opacity(0.35), lineWidth: 1).frame(width: 22, height: 22).position(center)
            }
            .contentShape(Rectangle())
            .gesture(DragGesture(minimumDistance: 0).onChanged { g in
                onChange(Double(min(max(g.location.x / size.width, 0), 1)), Double(min(max(1 - g.location.y / size.height, 0), 1)))
            })
        }
        .frame(height: 160)
        .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
    }
}

private struct HueSlider: View {
    let hue: Double
    let onChange: (Double) -> Void

    var body: some View {
        GeometryReader { proxy in
            let width = proxy.size.width
            let height = proxy.size.height
            ZStack(alignment: .topLeading) {
                LinearGradient(colors: [0, 60, 120, 180, 240, 300, 360].map { hsvColor(Double($0), 1, 1) }, startPoint: .leading, endPoint: .trailing)
                let center = CGPoint(x: min(max(hue / 360, 0), 1) * width, y: height / 2)
                Circle().fill(Color.white).frame(width: height, height: height).position(center)
                Circle().fill(hsvColor(hue, 1, 1)).frame(width: height - 6, height: height - 6).position(center)
            }
            .contentShape(Rectangle())
            .gesture(DragGesture(minimumDistance: 0).onChanged { g in onChange(Double(min(max(g.location.x / width, 0), 1)) * 360) })
        }
        .frame(height: 18)
        .clipShape(Capsule())
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
            TextField("", text: Binding(get: { text }, set: { raw in
                let digits = String(raw.filter(\.isNumber).prefix(3))
                text = digits
                if let n = Int(digits) { onChannel(min(max(n, 0), 255)) }
            }))
            .font(.dorr(16, .bold))
            .foregroundStyle(wa.settingsInk)
            .multilineTextAlignment(.center)
            .keyboardType(.numberPad)
            .tint(wa.red)
            .focused($focused)
            .frame(maxWidth: .infinity, minHeight: 40, maxHeight: 40)
            .overlay(RoundedRectangle(cornerRadius: 8, style: .continuous).stroke(wa.night ? wa.line : Color(hex: 0xE5E7EB), lineWidth: 1))
            Text(verbatim: label).font(.dorr(12)).foregroundStyle(wa.settingsMut)
        }
        .onAppear { text = String(channel) }
        .onChange(of: channel) { if !focused { text = String($0) } }
        .onChange(of: focused) { if !$0 { text = String(channel) } }
    }
}

// MARK: - Colour helpers

private func palette(_ primary: String, _ background: String, _ text: String) -> [String: String] {
    ["primary": primary, "background": background, "textPrimary": text]
}

private func pickedOrDefault(_ custom: [String: String]?, _ snap: AppearanceDto?, _ key: String, night: Bool, _ fallback: String) -> String {
    if let picked = custom?[key].flatMap(normalizeBrandHex) { return picked }
    let defaults = night ? snap?.default?.darkTokens?[key] : snap?.default?.lightTokens?[key]
    return normalizeBrandHex(defaults ?? fallback) ?? fallback
}

private func samePalette(_ light: [String: String]?, _ dark: [String: String]?) -> Bool {
    guard let light, !light.isEmpty, let dark, !dark.isEmpty else { return false }
    return ["primary", "background", "textPrimary"].allSatisfy { key in
        let left = light[key].flatMap(normalizeBrandHex)
        return left != nil && left == dark[key].flatMap(normalizeBrandHex)
    }
}

private func normalizeBrandHex(_ raw: String) -> String? {
    var hex = raw.trimmingCharacters(in: .whitespaces)
    if hex.hasPrefix("#") { hex.removeFirst() }
    guard hex.count == 6, hex.allSatisfy(\.isHexDigit) else { return nil }
    return "#" + hex.uppercased()
}

private func hsv(fromHex hex: String) -> (h: Double, s: Double, v: Double)? {
    guard let normalized = normalizeBrandHex(hex), let n = Int(normalized.dropFirst(), radix: 16) else { return nil }
    return rgbToHsv([(n >> 16) & 0xFF, (n >> 8) & 0xFF, n & 0xFF])
}

/// Android's `Color.colorToHSV`.
private func rgbToHsv(_ rgb: [Int]) -> (h: Double, s: Double, v: Double) {
    let r = Double(rgb[0]) / 255, g = Double(rgb[1]) / 255, b = Double(rgb[2]) / 255
    let maxC = max(r, g, b), minC = min(r, g, b), delta = maxC - minC
    var h = 0.0
    if delta > 0 {
        if maxC == r { h = 60 * ((g - b) / delta).truncatingRemainder(dividingBy: 6) }
        else if maxC == g { h = 60 * ((b - r) / delta + 2) }
        else { h = 60 * ((r - g) / delta + 4) }
    }
    if h < 0 { h += 360 }
    return (h, maxC == 0 ? 0 : delta / maxC, maxC)
}

/// Android's `Color.HSVToColor` (channels rounded the same way).
private func hsvToRgb(_ hue: Double, _ saturation: Double, _ value: Double) -> [Int] {
    let h = min(max(hue, 0), 360), s = min(max(saturation, 0), 1), v = min(max(value, 0), 1)
    let c = v * s
    let x = c * (1 - abs((h / 60).truncatingRemainder(dividingBy: 2) - 1))
    let m = v - c
    let (r, g, b): (Double, Double, Double)
    switch h {
    case ..<60: (r, g, b) = (c, x, 0)
    case ..<120: (r, g, b) = (x, c, 0)
    case ..<180: (r, g, b) = (0, c, x)
    case ..<240: (r, g, b) = (0, x, c)
    case ..<300: (r, g, b) = (x, 0, c)
    default: (r, g, b) = (c, 0, x)
    }
    return [r, g, b].map { min(max(Int((($0 + m) * 255).rounded()), 0), 255) }
}

private func hsvColor(_ hue: Double, _ saturation: Double, _ value: Double) -> Color {
    let rgb = hsvToRgb(hue, saturation, value)
    return Color(red: Double(rgb[0]) / 255, green: Double(rgb[1]) / 255, blue: Double(rgb[2]) / 255)
}

private func hsvToHex(_ hue: Double, _ saturation: Double, _ value: Double) -> String {
    let rgb = hsvToRgb(hue, saturation, value)
    return String(format: "#%02X%02X%02X", rgb[0], rgb[1], rgb[2])
}
