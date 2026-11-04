import SwiftUI
import CoreLocation
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

/// A round area on the ground (metres): the captain's hot areas (O-58).
struct MapZone: Equatable {
    var center: CLLocationCoordinate2D
    var radiusM: Double
    var color: Color

    static func == (a: MapZone, b: MapZone) -> Bool {
        a.center.latitude == b.center.latitude && a.center.longitude == b.center.longitude && a.radiusM == b.radiusM && a.color == b.color
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
    /// driverApp `googleNightStyle = R.raw.map_night`: a Google style JSON in the bundle (`"map_night"`), used at night.
    var googleNightStyle: String?
}

/// The map: pins, lines, zones, «me», kept inside `padding` (the sheets over it). Google, Mapbox or Apple Maps —
/// the admin's choice (`MapEngine`); the others aren't started at all.
///
/// `showMe`: my place on the map — a car turning with my heading when `meAsCar` (the captain, O-60), the usual
/// dot otherwise.

struct DorrMap: View {
    @ObservedObject var camera: DorrCamera
    var pins: [MapPin] = []
    var lines: [MapLine] = []
    var zones: [MapZone] = []
    var showMe = false
    var meAsCar = false
    var padding = EdgeInsets()
    var night: Bool?
    var googleNightStyle: String?
    var onLoaded: () -> Void = {}
    var body: some View { Color.clear }
}

@MainActor final class MapEngine: ObservableObject {
    enum Kind { case google, mapbox }
    static let shared = MapEngine()
    @Published private(set) var provider = "google"
    var kind: Kind { .google }
    func load() {}
    func refresh() async {}
}
