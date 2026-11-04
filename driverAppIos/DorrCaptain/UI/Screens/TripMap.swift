import CoreLocation
import SwiftUI
import UIKit

// driverApp `ui/TripMap.kt`.

/// The trip's map (O-34): the route drawn (the server's planned polyline, or a straight line when there is none), the
/// pickup, the stops and the destination, and the captain's own blue dot. Two buttons on it: back to the whole route, and
/// "navigate" in Google Maps to the next point — driving in the app or in Google Maps is the captain's choice.
/// Fills the screen (under the status bar too); `bottomPadding` is the sheet over it.
struct TripMap: View {
    @Environment(\.pal) private var pal
    let t: TripDto
    let bottomPadding: CGFloat
    @StateObject private var camera: DorrCamera

    init(t: TripDto, bottomPadding: CGFloat) {
        self.t = t
        self.bottomPadding = bottomPadding
        let start = t.origin.flatMap(coord) ?? CLLocationCoordinate2D(latitude: 24.7136, longitude: 46.6753)
        _camera = StateObject(wrappedValue: DorrCamera(start: start, zoom: 14))
    }

    private var pickup: CLLocationCoordinate2D? { t.origin.flatMap(coord) }
    private var drop: CLLocationCoordinate2D? { t.destination.flatMap(coord) }
    private var stops: [CLLocationCoordinate2D] {
        (t.stops ?? []).compactMap { s in s.lat.flatMap { lat in s.lng.map { CLLocationCoordinate2D(latitude: lat, longitude: $0) } } }
    }

    private var route: [CLLocationCoordinate2D] {
        if let p = t.route?.polyline, !p.trimmingCharacters(in: .whitespaces).isEmpty {
            let decoded = decodePolyline(p)
            if decoded.count >= 2 { return decoded }
        }
        return [pickup].compactMap { $0 } + stops + [drop].compactMap { $0 }
    }

    private func fitRoute() {
        camera.fit(route + [pickup, drop].compactMap { $0 }, padding: 48)
    }

    var body: some View {
        let route = self.route
        let located: Bool = {
            let s = CLLocationManager().authorizationStatus
            return s == .authorizedWhenInUse || s == .authorizedAlways
        }()
        var pins: [MapPin] = []
        if let pickup { pins.append(MapPin(at: pickup, kind: .pickup)) }
        pins += stops.map { MapPin(at: $0, kind: .stop) }
        if let drop { pins.append(MapPin(at: drop, kind: .dropoff)) }
        return ZStack(alignment: .topTrailing) {
            // O-53: the admin's engine (Google or Mapbox) — the route, the pins and the blue dot on either.
            DorrMap(
                camera: camera,
                pins: pins,
                lines: route.count >= 2 ? [MapLine(points: route, color: Color.black.opacity(0.18), width: 7), MapLine(points: route, color: pal.navy, width: 4.5)] : [],
                showMe: located,
                // O-60: the captain's own car on the trip's map too.
                meAsCar: true,
                padding: EdgeInsets(top: 150, leading: 0, bottom: bottomPadding, trailing: 0),
                night: pal.night,
                googleNightStyle: "map_night",
                onLoaded: { fitRoute() }
            )
            .ignoresSafeArea()
            VStack(spacing: 0) {
                MapButton(icon: "location.fill", tint: pal.navy) { fitRoute() }
                if let nav = t.navigation {
                    MapButton(icon: "location.north.line.fill", tint: pal.orange, label: L("trip_navigate")) { openGoogleNavigation(nav) }
                        .padding(.top, 10)
                }
            }
            .padding(.top, 150)
            .padding(.trailing, 14)
        }
        .task(id: "\(t.uuid ?? "")|\(route.count)") { fitRoute() }
    }

    /// O-56: with stops still ahead, the directions link carries them all (the turn-by-turn link takes one point only).
    @MainActor
    private func openGoogleNavigation(_ nav: NavigationDto) {
        let web = nav.googleMapsUrl.flatMap { URL(string: $0) }
        let app: URL? = (nav.waypoints ?? []).isEmpty
            ? URL(string: "comgooglemaps://?daddr=\(nav.lat ?? 0),\(nav.lng ?? 0)&directionsmode=driving")
            : web
        guard let first = app ?? web else { return }
        UIApplication.shared.open(first, options: [:]) { opened in
            if !opened, let web, web != first { UIApplication.shared.open(web) }
        }
    }
}

private func coord(_ p: PlaceDto) -> CLLocationCoordinate2D? {
    guard let lat = p.lat, let lng = p.lng else { return nil }
    return CLLocationCoordinate2D(latitude: lat, longitude: lng)
}

private struct MapButton: View {
    @Environment(\.pal) private var pal
    let icon: String
    let tint: Color
    var label: String? = nil
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            Image(systemName: icon).font(.system(size: 20, weight: .semibold)).foregroundStyle(tint)
                .frame(width: 50, height: 50)
                .background(Circle().fill(pal.card).shadow(color: .black.opacity(0.2), radius: 8, y: 3))
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label ?? "")
    }
}

/// Google's encoded polyline → points (the format the server stores, same as the Directions API).
func decodePolyline(_ encoded: String) -> [CLLocationCoordinate2D] {
    let chars = Array(encoded.utf8)
    var points: [CLLocationCoordinate2D] = []
    var index = 0
    var lat = 0
    var lng = 0
    while index < chars.count {
        var result = 0
        var shift = 0
        var b: Int
        repeat { b = Int(chars[index]) - 63; index += 1; result |= (b & 0x1f) << shift; shift += 5 } while b >= 0x20 && index < chars.count
        lat += (result & 1) != 0 ? ~(result >> 1) : result >> 1
        result = 0
        shift = 0
        guard index < chars.count else { break }
        repeat { b = Int(chars[index]) - 63; index += 1; result |= (b & 0x1f) << shift; shift += 5 } while b >= 0x20 && index < chars.count
        lng += (result & 1) != 0 ? ~(result >> 1) : result >> 1
        points.append(CLLocationCoordinate2D(latitude: Double(lat) / 1e5, longitude: Double(lng) / 1e5))
    }
    return points
}
