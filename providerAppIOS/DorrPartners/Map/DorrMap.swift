import CoreLocation
import GoogleMaps
import MapboxMaps
import MapKit
import SwiftUI
import UIKit

// providerApp `map/DorrMap.kt` — the captain app's map layer (driverAppIos `Map/DorrMap.swift`), ported with the same names.
// The map engines, one chosen by the admin (docs/rides_tasks.md O-53 / O-72): admin → Rides → Settings → map provider
// (Google or Mapbox) and «iPhone app map» (the same, or Apple Maps) — the same choice the rider and captain apps follow
// (`GET provider/v1/map-config`, the same payload as `driver/v1/map-config`).
// The engine chosen draws the whole app; the others are never started. The choice and the Mapbox token are
// saved on the phone (UserDefaults) and read when the app starts, so a map screen opens at once with the right
// engine; the server is asked once after each sign-in (RootView) and its answer saved for the next start. Nothing asks on a timer.
//
// Screens never touch an engine: they draw a `DorrMap` with pins, lines and zones, and move it through `DorrCamera`.

/// The admin's choice, kept on the phone.
@MainActor
final class MapEngine: ObservableObject {
    static let shared = MapEngine()

    enum Kind { case google, mapbox, apple }

    private static let prefs = "dorr_map_engine"

    /// `map-config.ios_provider`: google · mapbox · apple.
    @Published private(set) var provider = "google"
    private var token: String?
    private var style = "mapbox/streets-v12"
    private var googleReady = false

    /// The Google Maps key for this app (Local.xcconfig `DORR_GOOGLE_MAPS_KEY`, like Android's `dorr.mapsKey`).
    private static let googleKey: String = {
        let key = (Bundle.main.object(forInfoDictionaryKey: "DorrGoogleMapsKey") as? String ?? "").trimmingCharacters(in: .whitespaces)
        return key.hasPrefix("$(") ? "" : key
    }()

    /// The engine that draws now. Mapbox needs its token; Google needs this app's key — without it Apple Maps
    /// draws instead of a blank map (Android stays blank: it has no built-in engine).
    var kind: Kind {
        switch provider {
        case "mapbox" where !(token ?? "").isEmpty: return .mapbox
        case "apple": return .apple
        case "google" where !Self.googleKey.isEmpty: return .google
        default: return .apple
        }
    }

    /// At app start, before any screen: the last saved choice.
    func load() {
        let d = UserDefaults.standard
        token = d.string(forKey: "\(Self.prefs).token")
        style = d.string(forKey: "\(Self.prefs).style") ?? style
        provider = d.string(forKey: "\(Self.prefs).provider") ?? "google"
        prepare()
    }

    /// The server's current choice (once after sign-in); saved for the next start.
    /// providerApp `MapEngine.refresh(context) { ApiClient.appearance.mapConfig() }` — `GET provider/v1/map-config` (the same
    /// `MapConfigController` as the DORR and captain apps', so it also carries `ios_provider`).
    func refresh() async {
        let result = await AppearanceApi.mapConfig()
        guard let c = result.value else { return }
        token = c.mapboxToken
        style = c.mapboxStyle ?? style
        provider = c.iosProvider ?? c.provider ?? "google"
        let d = UserDefaults.standard
        d.set(provider, forKey: "\(Self.prefs).provider")
        d.set(token, forKey: "\(Self.prefs).token")
        d.set(style, forKey: "\(Self.prefs).style")
        prepare()
    }

    /// Only the engine chosen is set up.
    private func prepare() {
        switch kind {
        case .mapbox: if let token { MapboxOptions.accessToken = token }
        case .google:
            if !googleReady { googleReady = GMSServices.provideAPIKey(Self.googleKey) }
        case .apple: break
        }
    }

    func styleURI(night: Bool) -> String {
        night && style == "mapbox/streets-v12" ? "mapbox://styles/mapbox/dark-v11" : "mapbox://styles/\(style)"
    }
}

/// `GET provider/v1/map-config` as MapEngine reads it (providerApp `MapConfigDto` + the iPhone's own `ios_provider`).
struct MapEngineConfig: Decodable {
    var provider: String?
    var iosProvider: String?
    var mapboxToken: String?
    var mapboxStyle: String?
}

// MARK: - Camera

/// What a map's engine does when a screen moves it.
@MainActor
protocol CameraEngine: AnyObject {
    func move(to: CLLocationCoordinate2D, zoom: Double, animate: Bool)
    func fit(_ points: [CLLocationCoordinate2D], padding: CGFloat)
}

/// What screens ask of a map's camera — the same with every engine.
@MainActor
final class DorrCamera: ObservableObject {
    /// Where the map's centre is (after it stops).
    @Published var target: CLLocationCoordinate2D
    @Published var zoom: Double
    @Published var isMoving = false
    /// The last move was the person's finger (not the app).
    @Published var byGesture = false

    private weak var engine: CameraEngine?
    private var pending: ((CameraEngine) -> Void)?

    init(start: CLLocationCoordinate2D, zoom: Double) {
        target = start
        self.zoom = zoom
    }

    func move(to: CLLocationCoordinate2D, zoom: Double) { run { $0.move(to: to, zoom: zoom, animate: false) } }

    func animate(to: CLLocationCoordinate2D, zoom: Double) { run { $0.move(to: to, zoom: zoom, animate: true) } }

    /// Every point in view, with this much room around them (points).
    func fit(_ points: [CLLocationCoordinate2D], padding: CGFloat) {
        guard points.count >= 2 else { return }
        run { $0.fit(points, padding: padding) }
    }

    private func run(_ block: @escaping (CameraEngine) -> Void) {
        if let engine { block(engine) } else { pending = block }
    }

    func attach(_ e: CameraEngine) {
        engine = e
        if let p = pending { pending = nil; p(e) }
    }

    func detach(_ e: CameraEngine) {
        if engine === e { engine = nil }
    }

    /// Updated from an engine after it stops (published values changed outside a view update).
    func settle(_ at: CLLocationCoordinate2D, _ z: Double) {
        target = at
        zoom = z
        isMoving = false
    }
}

enum PinKind: String { case pickup, dropoff, stop, car }

/// A pin on the map. `id`: the same pin across updates (a car that moves) — it slides to its new place instead
/// of being drawn again.
struct MapPin: Equatable {
    var at: CLLocationCoordinate2D
    var kind: PinKind
    var rotation: Double = 0
    var id: String?

    static func == (a: MapPin, b: MapPin) -> Bool {
        a.at.latitude == b.at.latitude && a.at.longitude == b.at.longitude && a.kind == b.kind && a.rotation == b.rotation && a.id == b.id
    }
}

struct MapLine: Equatable {
    var points: [CLLocationCoordinate2D]
    var color: Color
    var width: CGFloat

    static func == (a: MapLine, b: MapLine) -> Bool {
        a.color == b.color && a.width == b.width && a.points.count == b.points.count
            && zip(a.points, b.points).allSatisfy { $0.latitude == $1.latitude && $0.longitude == $1.longitude }
    }
}

/// A round area on the ground (metres): a branch's range around its pin. `stroke` / `strokeWidth`: an edge line around it
/// (none by default).
struct MapZone: Equatable {
    var center: CLLocationCoordinate2D
    var radiusM: Double
    var color: Color
    var stroke: Color = .clear
    var strokeWidth: CGFloat = 0

    static func == (a: MapZone, b: MapZone) -> Bool {
        a.center.latitude == b.center.latitude && a.center.longitude == b.center.longitude && a.radiusM == b.radiusM && a.color == b.color
            && a.stroke == b.stroke && a.strokeWidth == b.strokeWidth
    }
}

/// Colours the screens use for their routes.
enum MapColors {
    static let route = Color(hex: 0x001B53)
    static let routeShadow = Color.black.opacity(0.18)
}

/// Everything a map draws, handed to the engine on each update.
struct MapScene: Equatable {
    var pins: [MapPin] = []
    var lines: [MapLine] = []
    var zones: [MapZone] = []
    var showMe = false
    var meAsCar = false
    var top: CGFloat = 0
    var bottom: CGFloat = 0
    var night = false
    /// providerApp `googleNightStyle = R.raw.map_night`: a Google style JSON in the bundle (`"map_night"`), used at night.
    var googleNightStyle: String?
    /// The map can't be turned or tilted (a place picker: north stays up).
    var fixedNorth = false
}

/// The map: pins, lines, zones, «me», kept inside `padding` (the sheets over it). Google, Mapbox or Apple Maps —
/// the admin's choice (`MapEngine`); the others aren't started at all.
///
/// `showMe`: my place on the map — a car turning with my heading when `meAsCar` (the captain, O-60), the usual
/// dot otherwise. `fixedNorth`: the map can't be turned or tilted.
struct DorrMap: View {
    @ObservedObject private var engine = MapEngine.shared
    @Environment(\.colorScheme) private var scheme
    @ObservedObject var camera: DorrCamera
    var pins: [MapPin] = []
    var lines: [MapLine] = []
    var zones: [MapZone] = []
    var showMe = false
    var meAsCar = false
    var padding = EdgeInsets()
    var night: Bool?
    /// Google only: the bundle's style JSON drawn at night (`R.raw.map_night` → `"map_night"`).
    var googleNightStyle: String?
    var fixedNorth = false
    var onLoaded: () -> Void = {}

    var body: some View {
        let scene = MapScene(pins: pins, lines: lines, zones: zones, showMe: showMe, meAsCar: meAsCar,
                             top: padding.top, bottom: padding.bottom, night: night ?? (scheme == .dark),
                             googleNightStyle: googleNightStyle, fixedNorth: fixedNorth)
        switch engine.kind {
        case .google: GoogleEngineMap(camera: camera, scene: scene, onLoaded: onLoaded).id("google")
        case .mapbox: MapboxEngineMap(camera: camera, scene: scene, style: engine.styleURI(night: scene.night), onLoaded: onLoaded).id("mapbox")
        case .apple: AppleEngineMap(camera: camera, scene: scene, onLoaded: onLoaded).id("apple")
        }
    }
}

// MARK: - My place (O-60)

/// My place and heading from the phone's own location updates, while a map shows me as a car.
@MainActor
final class MyPlace: NSObject, ObservableObject, CLLocationManagerDelegate {
    private let manager = CLLocationManager()
    var onChange: ((CLLocationCoordinate2D, Double) -> Void)?
    private var heading = 0.0

    func start() {
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyBest
        manager.startUpdatingLocation()
    }

    func stop() { manager.stopUpdatingLocation() }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        guard let l = locations.last else { return }
        Task { @MainActor in
            if l.course >= 0 { self.heading = l.course }
            self.onChange?(l.coordinate, self.heading)
        }
    }
}

// MARK: - Google

private struct GoogleEngineMap: UIViewRepresentable {
    let camera: DorrCamera
    let scene: MapScene
    let onLoaded: () -> Void

    func makeCoordinator() -> Coordinator { Coordinator(camera: camera, onLoaded: onLoaded) }

    func makeUIView(context: Context) -> GMSMapView {
        let options = GMSMapViewOptions()
        options.camera = GMSCameraPosition(target: camera.target, zoom: Float(camera.zoom))
        let map = GMSMapView(options: options)
        map.settings.compassButton = false
        map.settings.myLocationButton = false
        map.settings.indoorPicker = false
        map.delegate = context.coordinator
        context.coordinator.map = map
        camera.attach(context.coordinator)
        return map
    }

    func updateUIView(_ map: GMSMapView, context: Context) {
        context.coordinator.apply(scene)
    }

    static func dismantleUIView(_ map: GMSMapView, coordinator: Coordinator) {
        coordinator.place.stop()
        coordinator.camera.detach(coordinator)
    }

    @MainActor
    final class Coordinator: NSObject, GMSMapViewDelegate, CameraEngine {
        let camera: DorrCamera
        let onLoaded: () -> Void
        weak var map: GMSMapView?
        let place = MyPlace()
        private var last: MapScene?
        private var markers: [String: GMSMarker] = [:]
        private var loose: [GMSMarker] = []
        private var overlays: [GMSOverlay] = []
        private var me: GMSMarker?
        private var loaded = false

        init(camera: DorrCamera, onLoaded: @escaping () -> Void) {
            self.camera = camera
            self.onLoaded = onLoaded
        }

        func apply(_ s: MapScene) {
            guard let map, s != last else { return }
            let old = last
            last = s
            map.padding = UIEdgeInsets(top: s.top, left: 0, bottom: s.bottom, right: 0)
            map.overrideUserInterfaceStyle = s.night ? .dark : .light
            if old?.night != s.night || old?.googleNightStyle != s.googleNightStyle {
                // MapStyleOptions.loadRawResourceStyle: the night JSON only when night and a style was given.
                map.mapStyle = s.night ? s.googleNightStyle.flatMap { Bundle.main.url(forResource: $0, withExtension: "json") }
                    .flatMap { try? GMSMapStyle(contentsOfFileURL: $0) } : nil
            }
            map.isMyLocationEnabled = s.showMe && !s.meAsCar
            map.settings.rotateGestures = !s.fixedNorth
            map.settings.tiltGestures = !s.fixedNorth
            if old?.zones != s.zones || old?.lines != s.lines {
                overlays.forEach { $0.map = nil }
                overlays = s.zones.map { z in
                    let path = GMSMutablePath()
                    mapCircle(z.center, z.radiusM).forEach { path.add($0) }
                    let p = GMSPolygon(path: path)
                    p.fillColor = UIColor(z.color)
                    p.strokeColor = UIColor(z.stroke)
                    p.strokeWidth = z.strokeWidth
                    p.map = map
                    return p
                } + s.lines.map { l in
                    let path = GMSMutablePath()
                    l.points.forEach { path.add($0) }
                    let p = GMSPolyline(path: path)
                    p.strokeColor = UIColor(l.color)
                    p.strokeWidth = l.width
                    p.map = map
                    return p
                }
            }
            if old?.pins != s.pins { drawPins(s.pins, on: map) }
            if old?.showMe != s.showMe || old?.meAsCar != s.meAsCar {
                if s.showMe && s.meAsCar {
                    place.onChange = { [weak self] at, bearing in self?.moveMe(at, bearing) }
                    place.start()
                } else {
                    place.stop()
                    me?.map = nil
                    me = nil
                }
            }
        }

        private func drawPins(_ pins: [MapPin], on map: GMSMapView) {
            // Fixed pins: drawn again; pins with an id: moved in place (a moving car slides), the ones gone removed.
            loose.forEach { $0.map = nil }
            loose = []
            var keep = Set<String>()
            for pin in pins {
                if let id = pin.id {
                    keep.insert(id)
                    if let m = markers[id] {
                        CATransaction.begin()
                        CATransaction.setAnimationDuration(1)
                        m.position = pin.at
                        m.rotation = pin.rotation
                        CATransaction.commit()
                    } else {
                        markers[id] = marker(pin, on: map)
                    }
                } else {
                    loose.append(marker(pin, on: map))
                }
            }
            for (id, m) in markers where !keep.contains(id) {
                m.map = nil
                markers[id] = nil
            }
        }

        private func marker(_ pin: MapPin, on map: GMSMapView) -> GMSMarker {
            let m = GMSMarker(position: pin.at)
            m.icon = MapIcons.of(pin.kind)
            m.rotation = pin.rotation
            m.isFlat = pin.kind == .car
            m.groundAnchor = pin.kind == .car ? CGPoint(x: 0.5, y: 0.5) : CGPoint(x: 0.5, y: 1)
            m.map = map
            return m
        }

        private func moveMe(_ at: CLLocationCoordinate2D, _ bearing: Double) {
            guard let map else { return }
            let m = me ?? {
                let m = GMSMarker(position: at)
                m.icon = MapIcons.me
                m.isFlat = true
                m.groundAnchor = CGPoint(x: 0.5, y: 0.5)
                m.zIndex = 10
                m.map = map
                me = m
                return m
            }()
            m.position = at
            m.rotation = bearing
        }

        // CameraEngine
        func move(to: CLLocationCoordinate2D, zoom: Double, animate: Bool) {
            camera.byGesture = false
            let position = GMSCameraPosition(target: to, zoom: Float(zoom))
            if animate { map?.animate(to: position) } else { map?.camera = position }
        }

        func fit(_ points: [CLLocationCoordinate2D], padding: CGFloat) {
            camera.byGesture = false
            var bounds = GMSCoordinateBounds()
            points.forEach { bounds = bounds.includingCoordinate($0) }
            map?.animate(with: GMSCameraUpdate.fit(bounds, withPadding: padding))
        }

        // GMSMapViewDelegate
        nonisolated func mapView(_ mapView: GMSMapView, willMove gesture: Bool) {
            MainActor.assumeIsolated {
                camera.isMoving = true
                camera.byGesture = gesture
            }
        }

        nonisolated func mapView(_ mapView: GMSMapView, idleAt position: GMSCameraPosition) {
            MainActor.assumeIsolated { camera.settle(position.target, Double(position.zoom)) }
        }

        nonisolated func mapViewDidFinishTileRendering(_ mapView: GMSMapView) {
            MainActor.assumeIsolated {
                if !loaded { loaded = true; onLoaded() }
            }
        }
    }
}

// MARK: - Mapbox

private struct MapboxEngineMap: UIViewRepresentable {
    let camera: DorrCamera
    let scene: MapScene
    let style: String
    let onLoaded: () -> Void

    func makeCoordinator() -> Coordinator { Coordinator(camera: camera, onLoaded: onLoaded) }

    func makeUIView(context: Context) -> MapView {
        let start = CameraOptions(center: camera.target, padding: UIEdgeInsets(top: scene.top, left: 0, bottom: scene.bottom, right: 0), zoom: camera.zoom)
        let map = MapView(frame: .zero, mapInitOptions: MapInitOptions(cameraOptions: start, styleURI: StyleURI(rawValue: style)))
        map.ornaments.options.compass.visibility = .hidden
        map.ornaments.options.scaleBar.visibility = .hidden
        context.coordinator.attach(map, style: style)
        return map
    }

    func updateUIView(_ map: MapView, context: Context) {
        context.coordinator.apply(scene, style: style)
    }

    static func dismantleUIView(_ map: MapView, coordinator: Coordinator) {
        coordinator.camera.detach(coordinator)
        coordinator.cancelables.removeAll()
    }

    @MainActor
    final class Coordinator: NSObject, CameraEngine, GestureManagerDelegate {
        let camera: DorrCamera
        let onLoaded: () -> Void
        weak var map: MapView?
        var cancelables = Set<AnyCancelable>()
        private var zones: PolygonAnnotationManager?
        /// The zones' edges (a polygon's own outline is a hairline that fades with its fill): drawn as lines over them.
        private var zoneEdges: PolylineAnnotationManager?
        private var lines: PolylineAnnotationManager?
        private var points: PointAnnotationManager?
        private var last: MapScene?
        private var style = ""

        init(camera: DorrCamera, onLoaded: @escaping () -> Void) {
            self.camera = camera
            self.onLoaded = onLoaded
        }

        func attach(_ map: MapView, style: String) {
            self.map = map
            self.style = style
            zones = map.annotations.makePolygonAnnotationManager()
            zoneEdges = map.annotations.makePolylineAnnotationManager()
            lines = map.annotations.makePolylineAnnotationManager()
            points = map.annotations.makePointAnnotationManager()
            map.gestures.delegate = self
            map.mapboxMap.onMapLoaded.observeNext { [weak self] _ in self?.onLoaded() }.store(in: &cancelables)
            map.mapboxMap.onCameraChanged.observe { [weak self] _ in
                guard let self, !self.camera.isMoving else { return }
                self.camera.isMoving = true
            }.store(in: &cancelables)
            map.mapboxMap.onMapIdle.observe { [weak self, weak map] _ in
                guard let self, let state = map?.mapboxMap.cameraState else { return }
                self.camera.settle(state.center, state.zoom)
            }.store(in: &cancelables)
            camera.attach(self)
        }

        func apply(_ s: MapScene, style newStyle: String) {
            guard let map, s != last || newStyle != style else { return }
            let old = last
            last = s
            if newStyle != style {
                style = newStyle
                map.mapboxMap.styleURI = StyleURI(rawValue: newStyle)
            }
            // The sheets over the map: the centre stays in the visible part.
            if old?.top != s.top || old?.bottom != s.bottom {
                map.mapboxMap.setCamera(to: CameraOptions(padding: UIEdgeInsets(top: s.top, left: 0, bottom: s.bottom, right: 0)))
            }
            if old?.fixedNorth != s.fixedNorth {
                map.gestures.options.rotateEnabled = !s.fixedNorth
                map.gestures.options.pitchEnabled = !s.fixedNorth
            }
            if old?.showMe != s.showMe || old?.meAsCar != s.meAsCar {
                if s.showMe {
                    if s.meAsCar, let car = MapIcons.me {
                        map.location.options.puckType = .puck2D(Puck2DConfiguration(topImage: car))
                        map.location.options.puckBearing = .course
                        map.location.options.puckBearingEnabled = true
                    } else {
                        map.location.options.puckType = .puck2D(.makeDefault(showBearing: false))
                        map.location.options.puckBearingEnabled = false
                    }
                } else {
                    map.location.options.puckType = nil
                }
            }
            if old?.zones != s.zones {
                zones?.annotations = s.zones.map { z in
                    var a = PolygonAnnotation(polygon: Polygon([mapCircle(z.center, z.radiusM)]))
                    let ui = UIColor(z.color)
                    var alpha: CGFloat = 1
                    ui.getRed(nil, green: nil, blue: nil, alpha: &alpha)
                    a.fillColor = StyleColor(ui.withAlphaComponent(1))
                    a.fillOpacity = Double(alpha)
                    return a
                }
                zoneEdges?.annotations = s.zones.filter { $0.strokeWidth > 0 }.map { z in
                    var a = PolylineAnnotation(lineCoordinates: mapCircle(z.center, z.radiusM))
                    let ui = UIColor(z.stroke)
                    var alpha: CGFloat = 1
                    ui.getRed(nil, green: nil, blue: nil, alpha: &alpha)
                    a.lineColor = StyleColor(ui.withAlphaComponent(1))
                    a.lineOpacity = Double(alpha)
                    a.lineWidth = Double(z.strokeWidth)
                    return a
                }
            }
            if old?.lines != s.lines {
                lines?.annotations = s.lines.filter { $0.points.count >= 2 }.map { l in
                    var a = PolylineAnnotation(lineCoordinates: l.points)
                    a.lineColor = StyleColor(UIColor(l.color))
                    a.lineWidth = Double(l.width)
                    return a
                }
            }
            if old?.pins != s.pins {
                // Pins with an id (moving cars) keep their id, so they move instead of being drawn again.
                points?.annotations = s.pins.enumerated().map { i, pin in
                    var a = PointAnnotation(id: pin.id ?? "pin-\(i)-\(pin.kind.rawValue)", coordinate: pin.at)
                    if let image = MapIcons.of(pin.kind) { a.image = .init(image: image, name: "dorr-\(pin.kind.rawValue)") }
                    a.iconRotate = pin.rotation
                    a.iconAnchor = pin.kind == .car ? .center : .bottom
                    return a
                }
            }
        }

        // CameraEngine
        func move(to: CLLocationCoordinate2D, zoom: Double, animate: Bool) {
            camera.byGesture = false
            let options = CameraOptions(center: to, zoom: zoom)
            if animate { map?.camera.ease(to: options, duration: 0.6) } else { map?.mapboxMap.setCamera(to: options) }
        }

        func fit(_ coords: [CLLocationCoordinate2D], padding p: CGFloat) {
            camera.byGesture = false
            guard let map, let s = last else { return }
            let insets = UIEdgeInsets(top: s.top + p, left: p, bottom: s.bottom + p, right: p)
            if let options = try? map.mapboxMap.camera(for: coords, camera: CameraOptions(), coordinatesPadding: insets, maxZoom: nil, offset: nil) {
                map.camera.ease(to: options, duration: 0.7)
            }
        }

        // GestureManagerDelegate
        nonisolated func gestureManager(_ gestureManager: GestureManager, didBegin gestureType: GestureType) {
            MainActor.assumeIsolated { camera.byGesture = true }
        }

        nonisolated func gestureManager(_ gestureManager: GestureManager, didEnd gestureType: GestureType, willAnimate: Bool) {}

        nonisolated func gestureManager(_ gestureManager: GestureManager, didEndAnimatingFor gestureType: GestureType) {}
    }
}

// MARK: - Apple Maps (MapKit)

/// MapKit has no zoom level: the Google / Mapbox zoom (256-point tiles) is read back from the span shown.
private func appleZoom(span: Double, width: CGFloat) -> Double {
    log2(360 * Double(max(width, 1)) / (256 * max(span, 1e-9)))
}

private final class PinAnnotation: NSObject, MKAnnotation {
    @objc dynamic var coordinate: CLLocationCoordinate2D
    var kind: PinKind
    var rotation: Double
    var isMe = false

    init(_ pin: MapPin) {
        coordinate = pin.at
        kind = pin.kind
        rotation = pin.rotation
    }
}

private struct AppleEngineMap: UIViewRepresentable {
    let camera: DorrCamera
    let scene: MapScene
    let onLoaded: () -> Void

    func makeCoordinator() -> Coordinator { Coordinator(camera: camera, onLoaded: onLoaded) }

    func makeUIView(context: Context) -> MKMapView {
        let map = MKMapView()
        map.showsCompass = false
        map.showsScale = false
        map.pointOfInterestFilter = .includingAll
        map.delegate = context.coordinator
        context.coordinator.map = map
        context.coordinator.startAt = (camera.target, camera.zoom)
        camera.attach(context.coordinator)
        return map
    }

    func updateUIView(_ map: MKMapView, context: Context) {
        context.coordinator.apply(scene)
    }

    static func dismantleUIView(_ map: MKMapView, coordinator: Coordinator) {
        coordinator.place.stop()
        coordinator.camera.detach(coordinator)
    }

    @MainActor
    final class Coordinator: NSObject, MKMapViewDelegate, CameraEngine {
        let camera: DorrCamera
        let onLoaded: () -> Void
        weak var map: MKMapView?
        let place = MyPlace()
        var startAt: (CLLocationCoordinate2D, Double)?
        private var last: MapScene?
        private var byId: [String: PinAnnotation] = [:]
        private var loose: [PinAnnotation] = []
        private var me: PinAnnotation?
        private var zoneStyles: [ObjectIdentifier: (fill: UIColor, stroke: UIColor, width: CGFloat)] = [:]
        private var lineStyles: [ObjectIdentifier: (UIColor, CGFloat)] = [:]
        private var loaded = false

        init(camera: DorrCamera, onLoaded: @escaping () -> Void) {
            self.camera = camera
            self.onLoaded = onLoaded
        }

        func apply(_ s: MapScene) {
            guard let map, s != last else { return }
            let old = last
            last = s
            map.overrideUserInterfaceStyle = s.night ? .dark : .light
            map.showsUserLocation = s.showMe && !s.meAsCar
            map.isRotateEnabled = !s.fixedNorth
            map.isPitchEnabled = !s.fixedNorth
            // The sheets over the map changed (MapKit has no padding of its own): the same place back in the middle of the
            // visible part, as Google and Mapbox keep it.
            if let old, old.top != s.top || old.bottom != s.bottom, startAt == nil, !camera.isMoving, map.bounds.width > 0 {
                // After this view update (the move publishes the camera's state).
                DispatchQueue.main.async { [weak self] in
                    guard let self else { return }
                    self.move(to: self.camera.target, zoom: self.camera.zoom, animate: false)
                }
            }
            if let start = startAt, map.bounds.width > 0 {
                startAt = nil
                move(to: start.0, zoom: start.1, animate: false)
            } else if let start = startAt {
                // Not laid out yet: the first region waits for a width.
                DispatchQueue.main.async { [weak self] in
                    guard let self, self.startAt != nil, let s = self.last else { return }
                    self.last = nil
                    self.apply(s)
                }
                _ = start
            }
            if old?.zones != s.zones || old?.lines != s.lines {
                map.removeOverlays(map.overlays)
                zoneStyles = [:]
                lineStyles = [:]
                for z in s.zones {
                    var coords = mapCircle(z.center, z.radiusM)
                    let polygon = MKPolygon(coordinates: &coords, count: coords.count)
                    zoneStyles[ObjectIdentifier(polygon)] = (UIColor(z.color), UIColor(z.stroke), z.strokeWidth)
                    map.addOverlay(polygon)
                }
                for l in s.lines where l.points.count >= 2 {
                    var coords = l.points
                    let line = MKPolyline(coordinates: &coords, count: coords.count)
                    lineStyles[ObjectIdentifier(line)] = (UIColor(l.color), l.width)
                    map.addOverlay(line)
                }
            }
            if old?.pins != s.pins { drawPins(s.pins, on: map) }
            if old?.showMe != s.showMe || old?.meAsCar != s.meAsCar {
                if s.showMe && s.meAsCar {
                    place.onChange = { [weak self] at, bearing in self?.moveMe(at, bearing) }
                    place.start()
                } else {
                    place.stop()
                    if let me { map.removeAnnotation(me) }
                    me = nil
                }
            }
        }

        private func drawPins(_ pins: [MapPin], on map: MKMapView) {
            map.removeAnnotations(loose)
            loose = []
            var keep = Set<String>()
            for pin in pins {
                if let id = pin.id {
                    keep.insert(id)
                    if let a = byId[id] {
                        a.rotation = pin.rotation
                        // A moving car slides to its new place.
                        UIView.animate(withDuration: 1) { a.coordinate = pin.at }
                        map.view(for: a)?.transform = CGAffineTransform(rotationAngle: pin.rotation * .pi / 180)
                    } else {
                        let a = PinAnnotation(pin)
                        byId[id] = a
                        map.addAnnotation(a)
                    }
                } else {
                    let a = PinAnnotation(pin)
                    loose.append(a)
                    map.addAnnotation(a)
                }
            }
            for (id, a) in byId where !keep.contains(id) {
                map.removeAnnotation(a)
                byId[id] = nil
            }
        }

        private func moveMe(_ at: CLLocationCoordinate2D, _ bearing: Double) {
            guard let map else { return }
            if let me {
                me.coordinate = at
                me.rotation = bearing
                map.view(for: me)?.transform = CGAffineTransform(rotationAngle: bearing * .pi / 180)
            } else {
                let a = PinAnnotation(MapPin(at: at, kind: .car, rotation: bearing))
                a.isMe = true
                me = a
                map.addAnnotation(a)
            }
        }

        /// The point the sheets leave in the middle of the visible part (the padding's centre).
        private var visibleCenterOffset: CGFloat {
            guard let s = last else { return 0 }
            return (s.top - s.bottom) / 2
        }

        // CameraEngine
        func move(to: CLLocationCoordinate2D, zoom: Double, animate: Bool) {
            camera.byGesture = false
            guard let map, map.bounds.width > 0, map.bounds.height > 0 else { startAt = (to, zoom); return }
            // The target exactly in the middle of the part the sheets leave visible: a rect around it at this zoom (256-point
            // tiles, as Google / Mapbox), placed inside the sheets' insets.
            let s = last ?? MapScene()
            let width = Double(map.bounds.width)
            let visible = max(Double(map.bounds.height - s.top - s.bottom), 1)
            let across = MKMapSize.world.width * width / (256 * pow(2, zoom))
            let tall = across * visible / width
            let p = MKMapPoint(to)
            let rect = MKMapRect(x: p.x - across / 2, y: p.y - tall / 2, width: across, height: tall)
            map.setVisibleMapRect(rect, edgePadding: UIEdgeInsets(top: s.top, left: 0, bottom: s.bottom, right: 0), animated: animate)
        }

        func fit(_ points: [CLLocationCoordinate2D], padding p: CGFloat) {
            camera.byGesture = false
            guard let map else { return }
            let rect = points.reduce(MKMapRect.null) { r, c in r.union(MKMapRect(origin: MKMapPoint(c), size: MKMapSize(width: 0, height: 0))) }
            let s = last ?? MapScene()
            map.setVisibleMapRect(rect, edgePadding: UIEdgeInsets(top: s.top + p, left: p, bottom: s.bottom + p, right: p), animated: true)
        }

        // MKMapViewDelegate
        nonisolated func mapView(_ mapView: MKMapView, regionWillChangeAnimated animated: Bool) {
            MainActor.assumeIsolated {
                camera.isMoving = true
                // A finger on the map: one of its own gesture recognizers is running.
                let touching = mapView.subviews.first?.gestureRecognizers?.contains { $0.state == .began || $0.state == .changed } ?? false
                if touching { camera.byGesture = true }
            }
        }

        nonisolated func mapView(_ mapView: MKMapView, regionDidChangeAnimated animated: Bool) {
            MainActor.assumeIsolated {
                // The middle of the visible part: (top + height − bottom) / 2.
                let h = mapView.bounds.height
                let point = CGPoint(x: mapView.bounds.midX, y: h / 2 + visibleCenterOffset)
                let at = mapView.convert(point, toCoordinateFrom: mapView)
                camera.settle(at, appleZoom(span: mapView.region.span.longitudeDelta, width: mapView.bounds.width))
            }
        }

        nonisolated func mapViewDidFinishRenderingMap(_ mapView: MKMapView, fullyRendered: Bool) {
            MainActor.assumeIsolated {
                if !loaded { loaded = true; onLoaded() }
            }
        }

        nonisolated func mapView(_ mapView: MKMapView, rendererFor overlay: MKOverlay) -> MKOverlayRenderer {
            MainActor.assumeIsolated {
                if let polygon = overlay as? MKPolygon {
                    let r = MKPolygonRenderer(polygon: polygon)
                    let style = zoneStyles[ObjectIdentifier(polygon)]
                    r.fillColor = style?.fill
                    r.strokeColor = style?.stroke
                    r.lineWidth = style?.width ?? 0
                    return r
                }
                if let line = overlay as? MKPolyline {
                    let r = MKPolylineRenderer(polyline: line)
                    let style = lineStyles[ObjectIdentifier(line)]
                    r.strokeColor = style?.0 ?? UIColor(MapColors.route)
                    r.lineWidth = style?.1 ?? 5
                    r.lineCap = .round
                    r.lineJoin = .round
                    return r
                }
                return MKOverlayRenderer(overlay: overlay)
            }
        }

        nonisolated func mapView(_ mapView: MKMapView, viewFor annotation: MKAnnotation) -> MKAnnotationView? {
            MainActor.assumeIsolated {
                guard let pin = annotation as? PinAnnotation else { return nil }
                let reuse = pin.isMe ? "me" : pin.kind.rawValue
                let view = mapView.dequeueReusableAnnotationView(withIdentifier: reuse) ?? MKAnnotationView(annotation: pin, reuseIdentifier: reuse)
                view.annotation = pin
                view.image = pin.isMe ? MapIcons.me : MapIcons.of(pin.kind)
                view.zPriority = pin.isMe ? .max : .defaultUnselected
                // Drops point at their place with their tip; a car is centred on it and turns with its heading.
                if pin.kind == .car {
                    view.centerOffset = .zero
                    view.transform = CGAffineTransform(rotationAngle: pin.rotation * .pi / 180)
                } else {
                    view.transform = .identity
                    view.centerOffset = CGPoint(x: 0, y: -(view.image?.size.height ?? 40) / 2)
                }
                return view
            }
        }
    }
}

// MARK: - Shared

/// A circle of `radiusM` metres around `center`, as points (every engine draws it as a polygon).
private func mapCircle(_ center: CLLocationCoordinate2D, _ radiusM: Double, steps: Int = 72) -> [CLLocationCoordinate2D] {
    let lat = center.latitude * .pi / 180
    let dLat = radiusM / 111_320.0
    let dLng = radiusM / (111_320.0 * max(cos(lat), 0.01))
    return (0...steps).map { i in
        let a = 2 * Double.pi * Double(i) / Double(steps)
        return CLLocationCoordinate2D(latitude: center.latitude + dLat * sin(a), longitude: center.longitude + dLng * cos(a))
    }
}

/// The pin pictures, the same on every engine (androidApp `MapIcons`).
@MainActor
enum MapIcons {
    private static var cache: [String: UIImage] = [:]

    static func of(_ kind: PinKind) -> UIImage? {
        if let image = cache[kind.rawValue] { return image }
        let image: UIImage
        switch kind {
        case .car: image = car(body: UIColor(rgb: 0x1F2937), scale: 1)
        case .pickup: image = drop(UIColor(rgb: 0x001B53))
        case .dropoff: image = drop(UIColor(rgb: 0xF57C00))
        case .stop: image = drop(UIColor(rgb: 0xF9A825))
        }
        cache[kind.rawValue] = image
        return image
    }

    /// O-60: the captain's own car (brand blue), a little bigger than the others.
    static var me: UIImage? {
        if let image = cache["me"] { return image }
        let image = car(body: UIColor(rgb: 0x0B3D91), scale: 1.15)
        cache["me"] = image
        return image
    }

    /// A drop: a round head with a white dot, a point at the bottom (the place).
    private static func drop(_ color: UIColor) -> UIImage {
        let w: CGFloat = 30, h: CGFloat = 40
        return UIGraphicsImageRenderer(size: CGSize(width: w, height: h)).image { ctx in
            let c = ctx.cgContext
            let r = w / 2
            color.setFill()
            c.addEllipse(in: CGRect(x: 1, y: 1, width: 2 * (r - 1), height: 2 * (r - 1)))
            c.move(to: CGPoint(x: r - r * 0.55, y: r * 1.5))
            c.addLine(to: CGPoint(x: r, y: h - 1))
            c.addLine(to: CGPoint(x: r + r * 0.55, y: r * 1.5))
            c.closePath()
            c.fillPath()
            UIColor.white.setFill()
            c.fillEllipse(in: CGRect(x: r - r * 0.38, y: r - r * 0.38, width: r * 0.76, height: r * 0.76))
        }
    }

    /// A car seen from above, pointing up (the map turns it with the heading): a soft shadow, the body, a lighter
    /// roof, the windscreen and rear glass, mirrors and lights.
    private static func car(body: UIColor, scale u: CGFloat) -> UIImage {
        let w = 30 * u, h = 52 * u
        return UIGraphicsImageRenderer(size: CGSize(width: w, height: h)).image { ctx in
            let c = ctx.cgContext
            let cx = w / 2
            func round(_ x0: CGFloat, _ y0: CGFloat, _ x1: CGFloat, _ y1: CGFloat, _ r: CGFloat, _ color: UIColor) {
                color.setFill()
                UIBezierPath(roundedRect: CGRect(x: x0, y: y0, width: x1 - x0, height: y1 - y0), cornerRadius: r).fill()
            }
            round(4 * u, 5 * u, w - 3 * u, h - 2 * u, 11 * u, UIColor.black.withAlphaComponent(0.2))
            round(1 * u, 16 * u, 6 * u, 21 * u, 2 * u, body)
            round(w - 6 * u, 16 * u, w - 1 * u, 21 * u, 2 * u, body)
            round(4 * u, 2 * u, w - 4 * u, h - 4 * u, 10 * u, body)
            var red: CGFloat = 0, green: CGFloat = 0, blue: CGFloat = 0, alpha: CGFloat = 0
            body.getRed(&red, green: &green, blue: &blue, alpha: &alpha)
            round(7 * u, 19 * u, w - 7 * u, h - 17 * u, 5 * u, UIColor(red: min(red + 40 / 255, 1), green: min(green + 40 / 255, 1), blue: min(blue + 40 / 255, 1), alpha: 1))
            UIColor(rgb: 0xA9D2FF).setFill()
            c.move(to: CGPoint(x: 8 * u, y: 18 * u)); c.addLine(to: CGPoint(x: w - 8 * u, y: 18 * u))
            c.addLine(to: CGPoint(x: w - 10 * u, y: 11 * u)); c.addLine(to: CGPoint(x: 10 * u, y: 11 * u)); c.closePath(); c.fillPath()
            c.move(to: CGPoint(x: 9 * u, y: h - 16 * u)); c.addLine(to: CGPoint(x: w - 9 * u, y: h - 16 * u))
            c.addLine(to: CGPoint(x: w - 10 * u, y: h - 10 * u)); c.addLine(to: CGPoint(x: 10 * u, y: h - 10 * u)); c.closePath(); c.fillPath()
            UIColor(rgb: 0xFFF3B0).setFill()
            c.fillEllipse(in: CGRect(x: cx - 8 * u - 1.8 * u, y: 4.5 * u - 1.8 * u, width: 3.6 * u, height: 3.6 * u))
            c.fillEllipse(in: CGRect(x: cx + 8 * u - 1.8 * u, y: 4.5 * u - 1.8 * u, width: 3.6 * u, height: 3.6 * u))
            UIColor(rgb: 0xEF4444).setFill()
            c.fill(CGRect(x: cx - 10 * u, y: h - 6.5 * u, width: 4 * u, height: 1.5 * u))
            c.fill(CGRect(x: cx + 6 * u, y: h - 6.5 * u, width: 4 * u, height: 1.5 * u))
        }
    }
}

private extension UIColor {
    convenience init(rgb: UInt32) {
        self.init(red: CGFloat((rgb >> 16) & 0xFF) / 255, green: CGFloat((rgb >> 8) & 0xFF) / 255, blue: CGFloat(rgb & 0xFF) / 255, alpha: 1)
    }
}
