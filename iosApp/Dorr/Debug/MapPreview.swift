#if DEBUG
import CoreLocation
import SwiftUI

/// `-DorrMainRoute map`: the map layer on its own — a trip with a stop, a car and a hot area — to check each
/// engine (`-DorrMapEngine google|mapbox|apple` with fixtures).
struct MapPreview: View {
    @StateObject private var camera = DorrCamera(start: CLLocationCoordinate2D(latitude: 24.7136, longitude: 46.6753), zoom: 12)
    @ObservedObject private var engine = MapEngine.shared
    let onBack: () -> Void

    private let pickup = CLLocationCoordinate2D(latitude: 24.7136, longitude: 46.6753)
    private let stop = CLLocationCoordinate2D(latitude: 24.7300, longitude: 46.6950)
    private let dropoff = CLLocationCoordinate2D(latitude: 24.7600, longitude: 46.7200)

    var body: some View {
        ZStack(alignment: .top) {
            DorrMap(camera: camera,
                    pins: [MapPin(at: pickup, kind: .pickup), MapPin(at: stop, kind: .stop), MapPin(at: dropoff, kind: .dropoff),
                           MapPin(at: CLLocationCoordinate2D(latitude: 24.7200, longitude: 46.6800), kind: .car, rotation: 40, id: "car-1")],
                    lines: [MapLine(points: [pickup, stop, dropoff], color: MapColors.route, width: 5)],
                    zones: [MapZone(center: CLLocationCoordinate2D(latitude: 24.70, longitude: 46.71), radiusM: 1200, color: Color.red.opacity(0.18))],
                    padding: EdgeInsets(top: 0, leading: 0, bottom: 260, trailing: 0))
                .ignoresSafeArea()
            HStack {
                Text(verbatim: "engine: \(String(describing: engine.kind))").font(.dorr(13, .bold)).padding(8).background(.white, in: Capsule())
                Spacer()
                Button("✕", action: onBack).padding(8).background(.white, in: Circle())
            }
            .padding(16)
            .padding(.top, 40)
        }
        .task {
            await MapEngine.shared.refresh()
            camera.fit([pickup, stop, dropoff], padding: 48)
        }
    }
}
#endif
