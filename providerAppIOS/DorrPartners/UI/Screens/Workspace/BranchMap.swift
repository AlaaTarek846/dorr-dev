// providerApp `ui/screens/BranchMap.kt`
import CoreLocation
import SwiftUI
import UIKit

// The branch's place on a real map (the onboarding's main branch and the branches page), in the look of the rider app's ride
// map: the map fills the screen, a pin stays in the middle (lifted while the map moves, landing with a pulse), "my location"
// brings the map back to the phone, and once the map rests the address under the pin is written in the address field by itself
// (CLGeocoder) — the person can still change it. No location yet: the rider app's location window asks for the permission and
// to switch location on, instead of a text explaining how.
// The map is `DorrMap` (Map/DorrMap.swift, the captain app's): Google, Mapbox or Apple Maps, the engine the admin chose; a light
// circle shows the branch's range around the pin.

/// The range a new branch starts with (the server gives a branch without one the same 100 km).
let defaultRadiusKm = 100.0

/// Quick choices under the range slider (km).
private let radiusChips = [10, 25, 50, 100, 200, 500]

/// Where the map waits before the phone's place is known: a wide view, never taken as the branch's place.
private let waitingView = CLLocationCoordinate2D(latitude: 24.0, longitude: 45.0)

/// The full-screen map to set a branch's place. `start` is the place it already has (nil = open on the phone's location).
/// `onPlace` gets the point under the pin each time the map rests after a move, `onAddress` the address found for it. `top`
/// goes under the title in the header card (the onboarding's step bar); `panel` is the card at the bottom (the address, the
/// range and the main button). `radiusKm`: the range chosen in the panel, drawn as a light circle around the pin.
@MainActor
struct BranchMapScreen<Top: View, Panel: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    let start: CLLocationCoordinate2D?
    let onBack: () -> Void
    let onPlace: (CLLocationCoordinate2D) -> Void
    let onAddress: (String) -> Void
    let radiusKm: Double?
    private let top: () -> Top
    private let panel: () -> Panel

    @StateObject private var camera: DorrCamera
    @ObservedObject private var location = PlaceLocation.shared
    /// The pin means a place only once the phone's position is known or the person moved the map.
    @State private var placed: Bool
    @State private var touched = false
    @State private var gate: Bool
    @State private var locating = false
    @State private var resolving = false
    @State private var label: String?
    @State private var error: String?
    @State private var sawMove = false
    /// The last place given to `onPlace`: a rest on the same point (the map settling after the panel's height changed, the
    /// engine's first idle) neither moves the branch nor writes over an address the person already has.
    @State private var lastPlace: CLLocationCoordinate2D?
    @State private var headerBottom: CGFloat = 0
    @State private var panelTop: CGFloat = 0
    @State private var panelContent: CGFloat = 0
    @State private var lookup: Task<Void, Never>?

    init(title: String, start: CLLocationCoordinate2D?, onBack: @escaping () -> Void,
         onPlace: @escaping (CLLocationCoordinate2D) -> Void, onAddress: @escaping (String) -> Void,
         radiusKm: Double? = nil,
         @ViewBuilder top: @escaping () -> Top, @ViewBuilder panel: @escaping () -> Panel) {
        self.title = title
        self.start = start
        self.onBack = onBack
        self.onPlace = onPlace
        self.onAddress = onAddress
        self.radiusKm = radiusKm
        self.top = top
        self.panel = panel
        _camera = StateObject(wrappedValue: DorrCamera(start: start ?? waitingView, zoom: start != nil ? 16 : 4))
        _placed = State(initialValue: start != nil)
        _gate = State(initialValue: start == nil && !PlaceLocation.shared.ready)
        _lastPlace = State(initialValue: start)
    }

    var body: some View {
        GeometryReader { geo in
            // The map runs under the status bar and the home indicator: its insets are in its own points.
            let safeTop = geo.safeAreaInsets.top
            let mapBottom = panelTop > headerBottom ? max(0, geo.size.height + geo.safeAreaInsets.bottom - panelTop) : 0
            ZStack(alignment: .top) {
                DorrMap(
                    camera: camera,
                    zones: rangeZones,
                    showMe: location.allowed,
                    padding: EdgeInsets(top: safeTop + headerBottom, leading: 0, bottom: mapBottom, trailing: 0),
                    night: wa.night,
                    googleNightStyle: "map_night",
                    fixedNorth: true
                )
                .ignoresSafeArea()

                // The pin sits in the middle of the map part left between the header and the panel.
                PlacePin(lifted: camera.isMoving, label: pinLabel)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .padding(.top, headerBottom)
                    .padding(.bottom, max(0, geo.size.height - panelTop))
                    .allowsHitTesting(false)

                VStack(spacing: 0) {
                    header
                        .background(GeometryReader { g in
                            Color.clear.preference(key: HeaderBottomKey.self, value: g.frame(in: .named("branchMap")).maxY)
                        })
                    if !touched {
                        HStack(spacing: 6) {
                            Image(systemName: "hand.tap.fill").font(.system(size: 13, weight: .semibold))
                            Text(L("onb_map_hint")).font(.dorr(12, .semibold))
                        }
                        .foregroundStyle(.white)
                        .padding(.horizontal, 14).padding(.vertical, 8)
                        .background(Color(hex: 0x111928), in: Capsule())
                        .shadow(color: .black.opacity(0.2), radius: 6, y: 2)
                        .padding(.top, 10)
                        .transition(.opacity.combined(with: .move(edge: .top)))
                    }
                    Spacer(minLength: 0)
                    HStack {
                        Spacer()
                        myLocationButton
                    }
                    .padding(.horizontal, 16)
                    .padding(.bottom, 12)
                    panelCard(maxHeight: geo.size.height * 0.62)
                        .background(GeometryReader { g in
                            Color.clear.preference(key: PanelTopKey.self, value: g.frame(in: .named("branchMap")).minY)
                        })
                }
                .animation(.easeInOut(duration: 0.25), value: touched)
            }
            .coordinateSpace(name: "branchMap")
            .onPreferenceChange(HeaderBottomKey.self) { headerBottom = $0 }
            .onPreferenceChange(PanelTopKey.self) { panelTop = $0 }
        }
        .background(wa.night ? wa.bg : Color.white)
        .overlay {
            if gate {
                LocationGate(
                    onReady: {
                        gate = false
                        goToMe()
                    },
                    // Without a place there is nothing to show: back to where the person came from.
                    onLeave: {
                        gate = false
                        if !placed { onBack() }
                    }
                )
                .transition(.opacity)
            }
        }
        .messageToast(error)
        .navigationBarHidden(true)
        .task {
            // Opened with no place yet and location already allowed and on: straight to where the phone is.
            if start == nil && !placed && !gate { goToMe() }
        }
        .onChange(of: camera.isMoving) { moving in
            if moving {
                sawMove = true
                if camera.byGesture { byFinger() }
            } else {
                rested()
            }
        }
        .onChange(of: camera.byGesture) { finger in
            if finger && camera.isMoving { byFinger() }
        }
    }

    /// The range around the pin while it rests (hidden while the map moves: the circle would lag behind the pin).
    private var rangeZones: [MapZone] {
        guard placed, let km = radiusKm, !camera.isMoving else { return [] }
        return [MapZone(center: camera.target, radiusM: km * 1000, color: wa.red.opacity(0.07), stroke: wa.red.opacity(0.55), strokeWidth: 1.5)]
    }

    /// The person moved the map: the pin is a place now, and the hint goes.
    private func byFinger() {
        placed = true
        touched = true
    }

    private var pinLabel: String? {
        if !placed { return nil }
        if resolving { return L("onb_map_finding_address") }
        return label ?? L("onb_map_pin_here")
    }

    /// The title, the back button and what the caller adds (the steps).
    private var header: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 8) {
                Text(verbatim: title).font(.dorr(19, .black)).foregroundStyle(wa.accent).lineLimit(1)
                    .frame(maxWidth: .infinity, alignment: .leading)
                Button(action: onBack) {
                    // ArrowBack mirrored: it points to the edge it sits on (as the app's headers).
                    Image(systemName: "arrow.forward").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.red)
                        .frame(width: 34, height: 34)
                        .background(wa.red.opacity(0.10), in: Circle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("common_back"))
            }
            top()
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 12)
        .settingsSurface(22)
        .padding(.horizontal, 14)
        .padding(.top, 10)
    }

    /// The rider app's round map button ("my location"), with a spinner while the phone looks for its place.
    private var myLocationButton: some View {
        Button(action: myLocation) {
            ZStack {
                if locating {
                    ProgressView().tint(wa.red)
                } else {
                    Image(systemName: "location.fill").font(.system(size: 18, weight: .semibold)).foregroundStyle(wa.red)
                }
            }
            .frame(width: 48, height: 48)
            .background(wa.settingsCard, in: Circle())
            .overlay { if wa.night { Circle().stroke(wa.line, lineWidth: 1) } }
            .shadow(color: wa.night ? .clear : .black.opacity(0.16), radius: 6, y: 2)
        }
        .buttonStyle(WaPressStyle())
        .disabled(locating)
        .accessibilityLabel(L("onb_map_my_location"))
    }

    /// The card at the bottom: it fits its content, and scrolls when that is taller than most of the screen.
    private func panelCard(maxHeight: CGFloat) -> some View {
        VStack(spacing: 0) {
            Capsule().fill(wa.settingsInk.opacity(0.15)).frame(width: 38, height: 4).padding(.top, 10).padding(.bottom, 12)
            ScrollView(showsIndicators: false) {
                VStack(alignment: .leading, spacing: 14) { panel() }
                    .padding(.horizontal, 18)
                    .padding(.bottom, 20)
                    .background(GeometryReader { g in Color.clear.preference(key: PanelContentKey.self, value: g.size.height) })
            }
            .scrollDismissesKeyboard(.interactively)
            .frame(height: min(max(panelContent, 1), maxHeight))
            .onPreferenceChange(PanelContentKey.self) { panelContent = $0 }
        }
        .frame(maxWidth: .infinity)
        .background(UnevenRoundedRectangleCompat(topRadius: 26).fill(wa.settingsCard).ignoresSafeArea(edges: .bottom))
        .overlay { if wa.night { UnevenRoundedRectangleCompat(topRadius: 26).stroke(wa.line, lineWidth: 1).ignoresSafeArea(edges: .bottom) } }
        .shadow(color: .black.opacity(wa.night ? 0 : 0.14), radius: 18, y: -2)
    }

    // MARK: - actions

    private func myLocation() {
        location.refresh()
        if location.ready { goToMe() } else { withAnimation { gate = true } }
    }

    private func goToMe() {
        guard !locating else { return }
        locating = true
        error = nil
        Task {
            var here = await CurrentPosition.shared.fetch(seconds: 12)
            if here == nil { here = CLLocationManager().location?.coordinate }
            locating = false
            guard let here else {
                error = L("onb_map_locate_failed")
                return
            }
            placed = true
            camera.animate(to: here, zoom: 16)
        }
    }

    /// The pin: once the map rests after a move, the point under it is the branch's place and its address is looked up.
    private func rested() {
        guard placed, sawMove else { return }
        let at = camera.target
        if let lastPlace, samePlace(lastPlace, at) { return }
        lastPlace = at
        onPlace(at)
        lookup?.cancel()
        resolving = true
        lookup = Task {
            let found = await branchAddressOf(at)
            if Task.isCancelled { return }
            resolving = false
            label = found
            if let found { onAddress(found) }
        }
    }
}

extension BranchMapScreen where Top == EmptyView {
    init(title: String, start: CLLocationCoordinate2D?, onBack: @escaping () -> Void,
         onPlace: @escaping (CLLocationCoordinate2D) -> Void, onAddress: @escaping (String) -> Void,
         radiusKm: Double? = nil,
         @ViewBuilder panel: @escaping () -> Panel) {
        self.init(title: title, start: start, onBack: onBack, onPlace: onPlace, onAddress: onAddress, radiusKm: radiusKm,
                  top: { EmptyView() }, panel: panel)
    }
}

/// Two points the same to about two metres.
private func samePlace(_ a: CLLocationCoordinate2D, _ b: CLLocationCoordinate2D) -> Bool {
    abs(a.latitude - b.latitude) < 2e-5 && abs(a.longitude - b.longitude) < 2e-5
}

private struct HeaderBottomKey: PreferenceKey {
    static var defaultValue: CGFloat = 0
    static func reduce(value: inout CGFloat, nextValue: () -> CGFloat) { value = max(value, nextValue()) }
}

private struct PanelTopKey: PreferenceKey {
    static var defaultValue: CGFloat = 0
    static func reduce(value: inout CGFloat, nextValue: () -> CGFloat) { value = max(value, nextValue()) }
}

private struct PanelContentKey: PreferenceKey {
    static var defaultValue: CGFloat = 0
    static func reduce(value: inout CGFloat, nextValue: () -> CGFloat) { value = max(value, nextValue()) }
}

/// The rider app's pin: a drop with a soft pulse on the ground while it rests, lifted while the map moves, and its bubble.
private struct PlacePin: View {
    @Environment(\.wa) private var wa
    let lifted: Bool
    let label: String?
    @State private var pulse = false

    var body: some View {
        ZStack {
            if !lifted {
                Circle().fill(wa.red.opacity(0.35)).frame(width: 46, height: 46)
                    .scaleEffect(x: pulse ? 1.4 : 0.4, y: (pulse ? 1.4 : 0.4) * 0.4)
                    .opacity(pulse ? 0 : 1)
                    .onAppear { withAnimation(.linear(duration: 1.6).repeatForever(autoreverses: false)) { pulse = true } }
                    .onDisappear { pulse = false }
            }
            Capsule().fill(Color.black.opacity(lifted ? 0.12 : 0.28)).frame(width: 12, height: 5)
            VStack(spacing: 0) {
                if !lifted, let label {
                    Text(verbatim: label).font(.dorr(12, .bold)).foregroundStyle(.white).lineLimit(1)
                        .padding(.horizontal, 12).padding(.vertical, 6)
                        .background(Color(hex: 0x111928), in: Capsule())
                        .shadow(radius: 6)
                        .frame(maxWidth: 260)
                        .padding(.bottom, 6)
                        .transition(.scale.combined(with: .opacity))
                }
                Circle().fill(wa.red).frame(width: 38, height: 38)
                    .overlay(Circle().fill(.white).frame(width: 14, height: 14))
                    .shadow(color: .black.opacity(0.25), radius: 8, y: 3)
                Rectangle().fill(wa.red).frame(width: 4, height: 18)
            }
            .offset(y: -30 + (lifted ? -26 : 0))
            .animation(.spring(response: 0.35, dampingFraction: 0.5), value: lifted)
        }
    }
}

// MARK: - the range

/// The range around the branch that requests reach it from: the value in big letters, a slider (finer on short distances),
/// quick choices, and one plain sentence saying what it means with the value chosen.
struct RadiusPicker: View {
    @Environment(\.wa) private var wa
    let km: Double
    let onChange: (Double) -> Void

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 20, style: .continuous)
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                Image(systemName: "scope").font(.system(size: 19, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 40, height: 40)
                    .background(wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 13, style: .continuous))
                VStack(alignment: .leading, spacing: 1) {
                    Text(L("onb_radius_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(L("onb_radius_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut).fixedSize(horizontal: false, vertical: true)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Text(L("onb_radius_value", formatKm(km))).font(.dorr(20, .black)).foregroundStyle(wa.red)
                    .id(km)
                    .transition(.asymmetric(insertion: .move(edge: .bottom).combined(with: .opacity), removal: .opacity))
            }
            .animation(.easeOut(duration: 0.2), value: km)
            Slider(value: Binding(get: { radiusToSlider(km) }, set: { onChange(sliderToRadius($0)) }), in: 0...1)
                .tint(wa.red)
            HStack(spacing: 6) {
                ForEach(radiusChips, id: \.self) { chip in
                    let on = km == Double(chip)
                    let chipShape = RoundedRectangle(cornerRadius: 12, style: .continuous)
                    Button { onChange(Double(chip)) } label: {
                        Text(verbatim: String(chip)).font(.dorr(13, .bold)).foregroundStyle(on ? .white : wa.settingsInk)
                            .frame(maxWidth: .infinity).padding(.vertical, 8)
                            .background(on ? wa.red : wa.settingsCard, in: chipShape)
                            .overlay(chipShape.stroke(on ? wa.red : (wa.night ? wa.line : Color(hex: 0xE5E7EB)), lineWidth: 1))
                            .animation(.easeInOut(duration: 0.18), value: on)
                    }
                    .buttonStyle(.plain)
                }
            }
            HStack(alignment: .top, spacing: 6) {
                Image(systemName: "info.circle.fill").font(.system(size: 14)).foregroundStyle(wa.red).padding(.top, 1)
                Text(L("onb_radius_explain", formatKm(km))).font(.dorr(12)).foregroundStyle(wa.settingsInk)
                    .lineSpacing(3).fixedSize(horizontal: false, vertical: true)
            }
        }
        .padding(14)
        .background(wa.red.opacity(wa.night ? 0.14 : 0.05), in: shape)
        .overlay(shape.stroke(wa.red.opacity(0.18), lineWidth: 1))
    }
}

/// Slider position (0...1) for a range: the square root, so the short distances get most of the track.
private func radiusToSlider(_ km: Double) -> Double { ((min(max(km, 1), 500) - 1) / 499).squareRoot() }

/// The range for a slider position: whole km, and steps of 5 km from 100 km up.
private func sliderToRadius(_ position: Double) -> Double {
    let raw = 1 + 499 * position * position
    let km = raw >= 100 ? (raw / 5).rounded() * 5 : raw.rounded()
    return min(max(km, 1), 500)
}

// MARK: - location

/// The location permission and switch for the location window (iosApp `RideLocation`): published so the window updates the
/// moment the person answers, and checked again when the app comes back to the front.
@MainActor
final class PlaceLocation: NSObject, ObservableObject, CLLocationManagerDelegate {
    static let shared = PlaceLocation()
    private let manager = CLLocationManager()
    @Published private(set) var status: CLAuthorizationStatus
    @Published private(set) var switchedOn: Bool

    override init() {
        status = CLLocationManager().authorizationStatus
        switchedOn = CLLocationManager.locationServicesEnabled()
        super.init()
        manager.delegate = self
    }

    var allowed: Bool { status == .authorizedWhenInUse || status == .authorizedAlways }
    var denied: Bool { status == .denied || status == .restricted }
    /// Allowed *and* switched on.
    var ready: Bool { allowed && switchedOn }

    func ask() { manager.requestWhenInUseAuthorization() }

    func refresh() {
        status = manager.authorizationStatus
        switchedOn = CLLocationManager.locationServicesEnabled()
    }

    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        let s = manager.authorizationStatus
        Task { @MainActor in
            self.status = s
            self.switchedOn = CLLocationManager.locationServicesEnabled()
        }
    }
}

/// The rider app's location window (iosApp `LocationGate`): it can't be dismissed by a tap outside, asks first for the
/// permission, then for location services to be on (iOS sends the person to Settings for that). It checks again every time
/// the app comes back to the front.
struct LocationGate: View {
    @Environment(\.wa) private var wa
    @Environment(\.scenePhase) private var phase
    @Environment(\.openURL) private var openURL
    @ObservedObject private var location = PlaceLocation.shared
    let onReady: () -> Void
    var onLeave: (() -> Void)?
    @State private var bob = false

    var body: some View {
        let allowed = location.allowed
        ZStack {
            Color.black.opacity(0.45).ignoresSafeArea()
            VStack(spacing: 0) {
                Image(systemName: "location.fill").font(.system(size: 44)).foregroundStyle(.white).offset(y: bob ? -10 : 0)
                    .frame(width: 96, height: 96)
                    .background(LinearGradient(colors: [wa.red, wa.red.opacity(0.72)], startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                    .onAppear { withAnimation(.easeInOut(duration: 0.9).repeatForever()) { bob = true } }
                Text(L(!allowed ? "lg_title_permission" : "lg_title_off")).font(.dorr(20, .black)).foregroundStyle(wa.settingsInk)
                    .multilineTextAlignment(.center).padding(.top, 18)
                Text(L(!allowed ? "lg_body_permission" : "lg_body_off")).font(.dorr(14)).foregroundStyle(wa.settingsMut)
                    .multilineTextAlignment(.center).lineSpacing(3).padding(.top, 8)
                Button {
                    if !allowed && !location.denied {
                        location.ask()
                    } else if let url = URL(string: UIApplication.openSettingsURLString) {
                        openURL(url)
                    }
                } label: {
                    Text(L(!allowed ? (location.denied ? "lg_open_settings" : "lg_allow") : "lg_turn_on")).font(.dorr(16, .bold)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity).padding(.vertical, 15)
                        .background(wa.red, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                }
                .buttonStyle(WaPressStyle())
                .padding(.top, 22)
                if let onLeave {
                    Button(action: onLeave) {
                        Text(L("lg_leave")).font(.dorr(14)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity).padding(.vertical, 10)
                    }
                    .buttonStyle(.plain)
                    .padding(.top, 10)
                }
            }
            .padding(24)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 28, style: .continuous))
            .padding(24)
        }
        .onAppear {
            location.refresh()
            if location.ready { onReady() }
        }
        .onChange(of: phase) { p in if p == .active { location.refresh() } }
        .onChange(of: location.ready) { ready in if ready { onReady() } }
    }
}

/// The address under a point, in the app's language: street, district and city. Nil when the phone can't tell.
func branchAddressOf(_ at: CLLocationCoordinate2D) async -> String? {
    let placemarks = try? await CLGeocoder().reverseGeocodeLocation(CLLocation(latitude: at.latitude, longitude: at.longitude),
                                                                    preferredLocale: AppLocale.locale)
    guard let p = placemarks?.first else { return nil }
    let street = [p.thoroughfare, p.subThoroughfare].compactMap { $0 }.joined(separator: " ")
    let parts = [street.isEmpty ? p.name : street, p.subLocality, p.locality ?? p.subAdministrativeArea]
        .compactMap { $0?.trimmingCharacters(in: .whitespaces) }
        .filter { !$0.isEmpty }
    var unique: [String] = []
    parts.forEach { if !unique.contains($0) { unique.append($0) } }
    let text = unique.joined(separator: AppLocale.isArabic ? "، " : ", ")
    let shown = String((text.isEmpty ? (p.name ?? "") : text).prefix(255))
    return shown.isEmpty ? nil : shown
}
