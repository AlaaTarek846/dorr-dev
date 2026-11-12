import CoreLocation
import SwiftUI

// androidApp `moving/MovingRoute.kt` — the road between the two ends of a move: asked once from `GET moving/route` when
// both ends are known or one of them changes (the server caches it per pair; nothing asks again on a timer), drawn on
// the admin's map engine with the pickup pin (navy) and the drop-off pin (orange), with the road distance and the drive
// time under it. Used by the wizard's places step, its review, and the request page.

/// Where a map with nothing on it yet opens.
let movingDefaultCenter = CLLocationCoordinate2D(latitude: 24.7136, longitude: 46.6753)

/// The pair of ends as the server caches it (4 decimals); nil without both ends.
func movingRouteKey(_ from: CLLocationCoordinate2D?, _ to: CLLocationCoordinate2D?) -> String? {
    guard let from, let to else { return nil }
    return String(format: "%.4f,%.4f,%.4f,%.4f", locale: Locale(identifier: "en_US_POSIX"),
                  from.latitude, from.longitude, to.latitude, to.longitude)
}

/// The route of the two ends on screen; `loading` while it is being asked for (Android's `MovingRouteState`).
@MainActor
final class MovingRouteState: ObservableObject {
    @Published var route: MovingRouteDto?
    @Published var loading = false
    /// The pair last asked for: the same pair is never asked twice (a page that shows again, a reload).
    private var asked: String?

    /// Asks for the road once per pair of ends; no ends, no call (Android's `rememberMovingRoute`).
    func load(from: CLLocationCoordinate2D?, to: CLLocationCoordinate2D?) async {
        let key = movingRouteKey(from, to)
        guard let key, let from, let to else {
            asked = nil
            route = nil
            loading = false
            return
        }
        guard key != asked else { return }
        asked = key
        route = nil
        loading = true
        let fresh = try? await MovingApi.route(fromLat: from.latitude, fromLng: from.longitude, toLat: to.latitude, toLng: to.longitude).data
        // Left for another pair (or the page closed) while it was asked: that answer isn't this pair's.
        guard asked == key else { return }
        if Task.isCancelled {
            asked = nil
            loading = false
            return
        }
        route = fresh
        loading = false
    }
}

extension View {
    /// Keeps `state` on the road between these two ends: one call when the pair changes, never on a timer.
    func movingRoute(_ state: MovingRouteState, from: CLLocationCoordinate2D?, to: CLLocationCoordinate2D?) -> some View {
        task(id: movingRouteKey(from, to) ?? "") { await state.load(from: from, to: to) }
    }
}

/// The straight line between two points, in km (the fallback when the road could not be asked for).
func straightKm(_ a: CLLocationCoordinate2D, _ b: CLLocationCoordinate2D) -> Double {
    let r = 6371.0
    let dLat = (b.latitude - a.latitude) * .pi / 180
    let dLng = (b.longitude - a.longitude) * .pi / 180
    let h = pow(sin(dLat / 2), 2) + cos(a.latitude * .pi / 180) * cos(b.latitude * .pi / 180) * pow(sin(dLng / 2), 2)
    return 2 * r * asin(min(sqrt(h), 1))
}

/// "18.4" for the `mv_km` string.
func movingKmText(_ km: Double) -> String { String(format: "%.1f", locale: Locale(identifier: "en_US_POSIX"), km) }

/// A straight line cut into dashes (the engines draw solid lines only): the "estimated" look.
private func dashes(_ a: CLLocationCoordinate2D, _ b: CLLocationCoordinate2D, count: Int = 22) -> [[CLLocationCoordinate2D]] {
    func at(_ t: Double) -> CLLocationCoordinate2D {
        CLLocationCoordinate2D(latitude: a.latitude + (b.latitude - a.latitude) * t, longitude: a.longitude + (b.longitude - a.longitude) * t)
    }
    let step = 1.0 / Double(count * 2 - 1)
    return (0..<count).map { i in [at(Double(i * 2) * step), at(Double(i * 2 + 1) * step)] }
}

/// The map card: both pins, the road (a dashed straight line while it is unknown or estimated), the camera fitted to
/// them. It takes no gestures (it sits in a scrolling page); `onTap` makes the whole card a button. Without an end, a
/// drawn sketch of a route stands in for it (iOS always has a map engine: Apple Maps at least).
struct MovingRouteMap: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let pickup: CLLocationCoordinate2D?
    let dropoff: CLLocationCoordinate2D?
    let route: MovingRouteDto?
    var onTap: (() -> Void)?
    @StateObject private var camera: DorrCamera
    @State private var ready = false

    init(pickup: CLLocationCoordinate2D?, dropoff: CLLocationCoordinate2D?, route: MovingRouteDto?, onTap: (() -> Void)? = nil) {
        self.pickup = pickup
        self.dropoff = dropoff
        self.route = route
        self.onTap = onTap
        let start: CLLocationCoordinate2D
        if let pickup, let dropoff {
            start = CLLocationCoordinate2D(latitude: (pickup.latitude + dropoff.latitude) / 2, longitude: (pickup.longitude + dropoff.longitude) / 2)
        } else {
            start = pickup ?? dropoff ?? movingDefaultCenter
        }
        _camera = StateObject(wrappedValue: DorrCamera(start: start, zoom: pickup != nil && dropoff != nil ? 11 : 15))
    }

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 16, style: .continuous)
        ZStack {
            if pickup == nil && dropoff == nil {
                MovingRouteSketch()
            } else {
                map
            }
            // Over the map: the page scrolls through it, and a tap is the card's.
            if let onTap {
                Color.clear.contentShape(Rectangle()).onTapGesture(perform: onTap)
            }
        }
        .background(wa.mvInset(onPanel))
        .clipShape(shape)
    }

    private var map: some View {
        let road = decodePolyline(route?.polyline)
        let lineColor = wa.night ? wa.accent : MapColors.route
        var lines: [MapLine] = []
        if road.count > 1 {
            lines = [MapLine(points: road, color: MapColors.routeShadow, width: 8), MapLine(points: road, color: lineColor, width: 5)]
        } else if let pickup, let dropoff {
            lines = dashes(pickup, dropoff).map { MapLine(points: $0, color: lineColor.opacity(0.85), width: 3) }
        }
        var pins: [MapPin] = []
        if let pickup { pins.append(MapPin(at: pickup, kind: .pickup)) }
        if let dropoff { pins.append(MapPin(at: dropoff, kind: .dropoff)) }
        let ends = "\(pickup?.latitude ?? 0),\(pickup?.longitude ?? 0),\(dropoff?.latitude ?? 0),\(dropoff?.longitude ?? 0)"
        let fitKey = "\(ready)|\(ends)|\(road.count)"
        return DorrMap(camera: camera, pins: pins, lines: lines, night: wa.night, onLoaded: { ready = true })
            .allowsHitTesting(false)
            // The camera once the map is drawn, and again when an end or the road changes.
            // (Before it is drawn the camera keeps the move and makes it when the engine attaches.)
            .task(id: fitKey) {
                let points = road.isEmpty ? [pickup, dropoff].compactMap { $0 } : road
                var single = points.count == 1
                if let pickup, let dropoff, straightKm(pickup, dropoff) < 0.05 { single = true }
                if points.isEmpty { return }
                if single {
                    camera.animate(to: points[0], zoom: 15)
                } else {
                    camera.fit(points, padding: 36)
                }
            }
    }
}

/// Before any end is set: a little drawn route, the dashes moving along it (the device's own animation).
private struct MovingRouteSketch: View {
    @Environment(\.wa) private var wa

    var body: some View {
        TimelineView(.animation) { context in
            let phase = (context.date.timeIntervalSinceReferenceDate / 1.6).truncatingRemainder(dividingBy: 1) * 52
            Canvas { g, size in
                let w = size.width
                let h = size.height
                let street = wa.settingsInk.opacity(0.05)
                for i in 1...4 {
                    var p = Path()
                    p.move(to: CGPoint(x: 0, y: h * CGFloat(i) / 5))
                    p.addLine(to: CGPoint(x: w, y: h * CGFloat(i) / 5 + h * 0.08))
                    g.stroke(p, with: .color(street), lineWidth: 7)
                }
                for i in 1...5 {
                    var p = Path()
                    p.move(to: CGPoint(x: w * CGFloat(i) / 6, y: 0))
                    p.addLine(to: CGPoint(x: w * CGFloat(i) / 6 - w * 0.05, y: h))
                    g.stroke(p, with: .color(street), lineWidth: 7)
                }
                let a = CGPoint(x: w * 0.18, y: h * 0.70)
                let b = CGPoint(x: w * 0.82, y: h * 0.30)
                var road = Path()
                road.move(to: a)
                road.addCurve(to: b, control1: CGPoint(x: w * 0.45, y: h * 0.98), control2: CGPoint(x: w * 0.55, y: h * 0.02))
                g.stroke(road, with: .color(wa.accent.opacity(0.6)),
                         style: StrokeStyle(lineWidth: 3, lineCap: .round, dash: [16, 10], dashPhase: -phase))
                for (at, color) in [(a, AppColors.brandNavy), (b, AppColors.brandOrange)] {
                    g.fill(Path(ellipseIn: CGRect(x: at.x - 11, y: at.y - 11, width: 22, height: 22)), with: .color(.white))
                    g.fill(Path(ellipseIn: CGRect(x: at.x - 9, y: at.y - 9, width: 18, height: 18)), with: .color(color))
                    g.fill(Path(ellipseIn: CGRect(x: at.x - 3.5, y: at.y - 3.5, width: 7, height: 7)), with: .color(.white))
                }
            }
        }
    }
}

/// Under the map: the road distance, the drive time, and «تقديرية» when the server could only estimate (or the road
/// could not be asked for and `fallbackKm`, the straight line, is shown). Nothing until both ends are set.
struct MovingRouteChips: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: MovingRouteState
    let bothEnds: Bool
    let fallbackKm: Double?
    /// Room above and below, only when something is shown (Android's modifier padding).
    var top: CGFloat = 0
    var bottom: CGFloat = 0

    var body: some View {
        if bothEnds {
            if state.loading {
                HStack(spacing: 8) {
                    ProgressView().tint(wa.accent).scaleEffect(0.7).frame(width: 14, height: 14)
                    Text(L("mv_route_loading")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut)
                }
                .padding(.vertical, 4)
                .padding(.top, top).padding(.bottom, bottom)
            } else if let km = state.route?.distanceM.map({ Double($0) / 1000 }) ?? fallbackKm {
                let route = state.route
                let estimated = route == nil || route?.estimated == true || route?.distanceM == nil
                FlowLayout(spacing: 8, lineSpacing: 8) {
                    RouteChip(icon: "ruler.fill", text: L("mv_km", movingKmText(km)), tint: wa.accent, strong: true)
                    if let s = route?.durationS, s > 0 {
                        RouteChip(icon: "clock.fill", text: minutesText(s, L("rd_minutes"), L("rd_hours")), tint: wa.accent)
                    }
                    if estimated { RouteChip(icon: "info.circle.fill", text: L("mv_route_estimated"), tint: AppColors.warning) }
                }
                .padding(.top, top).padding(.bottom, bottom)
            }
        }
    }
}

private struct RouteChip: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String
    let tint: Color
    var strong = false

    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(tint)
            Text(verbatim: text).font(.dorr(strong ? 14 : 12.5, strong ? .heavy : .semibold)).foregroundStyle(wa.settingsInk).lineLimit(1)
        }
        .padding(.horizontal, 12).padding(.vertical, 7)
        .background(tint.opacity(0.12), in: Capsule())
    }
}
