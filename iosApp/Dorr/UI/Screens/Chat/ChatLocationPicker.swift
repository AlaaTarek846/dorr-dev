import CoreLocation
import MapKit
import SwiftUI

// androidApp `chat/ChatLocationPicker.kt` — «Send location», as in WhatsApp: the real map on top (the admin's
// engine — `DorrMap`) with a pin in its middle — move the map and «Send this location» sends where the pin is;
// then «Share live location» (15 min / 1 h / 8 h), «Send your current location» with how accurate the fix is, and
// the places nearby (around the pin, `GET rides/places/nearby`). 🔍 searches places by name (`places/suggest`).
// The phone's own location updates (no server) keep the fix fresh while the page is open.

/// What the location page sends: a point, with the place's name and address when a place was picked.
struct LocationPick {
    let lat: Double
    let lng: Double
    var name: String?
    var address: String?
}

/// The phone's position while the page is open.
@MainActor
final class LocationTracker: NSObject, ObservableObject, CLLocationManagerDelegate {
    private let manager = CLLocationManager()
    @Published var fix: CLLocation?

    override init() {
        super.init()
        fix = manager.location
    }

    func start() {
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyBest
        manager.startUpdatingLocation()
    }

    func stop() { manager.stopUpdatingLocation() }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        Task { @MainActor in if let l = locations.last { fix = l } }
    }
}

struct LocationPickerPage: View {
    @ObservedObject private var ch = Ch.shared
    @Environment(\.wa) private var wa
    let onDismiss: () -> Void
    let onSend: (LocationPick) -> Void
    let onLive: (Int) -> Void
    @StateObject private var camera: DorrCamera
    @StateObject private var tracker = LocationTracker()
    @State private var centred = false
    @State private var nearby: [PlaceHitDto]?
    @State private var nearbyKey: String?
    @State private var searching = false
    @State private var query = ""
    @State private var results: [PlaceHitDto]?
    @State private var liveChoice = false
    @State private var refreshTick = 0

    init(onDismiss: @escaping () -> Void, onSend: @escaping (LocationPick) -> Void, onLive: @escaping (Int) -> Void) {
        self.onDismiss = onDismiss
        self.onSend = onSend
        self.onLive = onLive
        let start = CLLocationManager().location?.coordinate
        _camera = StateObject(wrappedValue: DorrCamera(start: start ?? CLLocationCoordinate2D(latitude: 30.0444, longitude: 31.2357), zoom: start != nil ? 17 : 12))
    }

    var body: some View {
        // The app's inner-screen look (as «Starred messages»): the soft backdrop, the title with back, then cards.
        GeometryReader { geo in
            VStack(spacing: 0) {
                header
                if searching {
                    ChField(placeholder: L("ch_loc_search_hint"), text: $query, icon: "magnifyingglass", limit: 80, clearable: true, autofocus: true)
                        .padding(.horizontal, 14).padding(.bottom, 10)
                }
                map.frame(height: geo.size.height * (searching ? 0.2 : 0.38))
                    .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
                    .padding(.horizontal, 14)
                list
                    .clipShape(UnevenRoundedRectangleCompat(topRadius: 22))
                    .padding(.horizontal, 14).padding(.top, 12)
            }
        }
        .background(PinkBackdrop().ignoresSafeArea())
        .onAppear { tracker.start() }
        .onDisappear { tracker.stop() }
        // The first good fix: the map goes there (unless the pin was already moved by hand).
        .onReceive(tracker.$fix) { f in
            guard let f, !centred else { return }
            centred = true
            if !camera.byGesture { camera.animate(to: f.coordinate, zoom: 17) }
        }
        // The places around where the pin stops (~100 m steps, so a nudge doesn't ask again).
        .task(id: nearbyTrigger) {
            guard nearbyTrigger != "wait", nearbyTrigger != nearbyKey else { return }
            try? await Task.sleep(nanoseconds: 300_000_000)
            guard !Task.isCancelled else { return }
            nearbyKey = nearbyTrigger
            nearby = nil
            let t = camera.target
            nearby = (try? await RidesApi.placeNearby(lat: t.latitude, lng: t.longitude).data) ?? []
        }
        // Search as I type (from two letters), nearest to the pin first.
        .task(id: "\(searching)|\(query)") {
            let q = query.trimmingCharacters(in: .whitespaces)
            guard searching, q.count >= 2 else { results = nil; return }
            try? await Task.sleep(nanoseconds: 350_000_000)
            guard !Task.isCancelled else { return }
            let t = camera.target
            results = (try? await RidesApi.placeSuggest(q: q, lat: t.latitude, lng: t.longitude).data) ?? []
        }
        .confirmationDialog(L("ch_live_title"), isPresented: $liveChoice, titleVisibility: .visible) {
            Button(L("ch_live_15m")) { onLive(900) }
            Button(L("ch_live_1h")) { onLive(3600) }
            Button(L("ch_live_8h")) { onLive(28800) }
        } message: {
            Text(L("ch_live_sub"))
        }
    }

    private var nearbyTrigger: String {
        guard centred, !camera.isMoving else { return "wait" }
        return String(format: "%.3f,%.3f|%d", camera.target.latitude, camera.target.longitude, refreshTick)
    }

    // MARK: the header: title · 🔍 · ⟳ · back (the app's `SubHeader`)

    private var header: some View {
        SubHeader(title: L("ch_loc_page_title"), onBack: {
            if searching { withAnimation { searching = false; query = "" } } else { onDismiss() }
        }, trailing: searching ? nil : AnyView(HStack(spacing: 8) {
            headerCircle("magnifyingglass", L("ch_loc_search_hint")) { withAnimation { searching = true } }
            headerCircle("arrow.clockwise", L("ch_loc_refresh")) {
                refreshTick += 1
                if let f = tracker.fix { camera.animate(to: f.coordinate, zoom: 17) }
            }
        }))
    }

    /// A round button beside the header's back (the same look: the settings card colour, the accent icon).
    private func headerCircle(_ icon: String, _ label: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 13, weight: .bold)).foregroundStyle(wa.red)
                .frame(width: 34, height: 34)
                .background(wa.settingsCard, in: Circle())
                .overlay { if wa.night { Circle().stroke(wa.line, lineWidth: 1) } }
                .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.08), radius: 6, y: 2)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label)
    }

    // MARK: the map, with the pin in the middle

    private var map: some View {
        ZStack(alignment: .topTrailing) {
            DorrMap(camera: camera, showMe: true)
            // The pin lifts while the map moves under it, and lands where it stops.
            ZStack {
                Ellipse().fill(Color.black.opacity(0.3)).frame(width: 8, height: 4)
                Image(systemName: "mappin").font(.system(size: 38, weight: .bold)).foregroundStyle(ch.red)
                    .offset(y: -22 - (camera.isMoving ? 12 : 0))
                    .animation(.spring(response: 0.3, dampingFraction: 0.6), value: camera.isMoving)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .allowsHitTesting(false)
            // Back to me.
            Button {
                if let f = tracker.fix { camera.animate(to: f.coordinate, zoom: 17) }
            } label: {
                Image(systemName: "location.fill").font(.system(size: 18)).foregroundStyle(ch.ink)
                    .frame(width: 44, height: 44).background(ch.surface, in: Circle()).shadow(color: .black.opacity(0.2), radius: 4, y: 2)
            }
            .buttonStyle(.plain).padding(12)
            .accessibilityLabel(L("ch_loc_my_place"))
        }
        .clipped()
    }

    // MARK: live · the pin · my place · nearby (or the search's results)

    private var list: some View {
        let me = tracker.fix
        let t = camera.target
        let pinMoved = me.map { $0.distance(from: CLLocation(latitude: t.latitude, longitude: t.longitude)) > 25 } ?? false
        return ScrollView {
            LazyVStack(alignment: .leading, spacing: 0) {
                if searching, let shown = results {
                    section(L("ch_loc_results"))
                    if shown.isEmpty { empty }
                    ForEach(Array(shown.enumerated()), id: \.offset) { _, p in placeRow(p) }
                } else {
                    pickRow("location.north.fill", L("ch_live_title"), nil) { liveChoice = true }
                    Rectangle().fill(ch.line).frame(height: 1).padding(.leading, 72)
                    if pinMoved {
                        pickRow("mappin", L("ch_loc_send_pin"), String(format: "%.5f, %.5f", t.latitude, t.longitude)) {
                            onSend(LocationPick(lat: t.latitude, lng: t.longitude))
                        }
                    }
                    section(L("ch_loc_nearby"))
                    pickRow("location.fill", L("ch_loc_send_current"), currentSub(me), enabled: me != nil) {
                        if let me { onSend(LocationPick(lat: me.coordinate.latitude, lng: me.coordinate.longitude)) }
                    }
                    if let places = nearby {
                        if places.isEmpty { empty }
                        ForEach(Array(places.enumerated()), id: \.offset) { _, p in placeRow(p) }
                    } else {
                        ProgressView().tint(ch.red).frame(maxWidth: .infinity).padding(20)
                    }
                }
            }
            .padding(.bottom, 12)
        }
        .background(ch.surface)
    }

    private func currentSub(_ me: CLLocation?) -> String? {
        guard let me else { return L("ch_loc_locating") }
        guard me.horizontalAccuracy >= 0 else { return nil }
        return String(format: L("ch_loc_accuracy"), max(Int(me.horizontalAccuracy), 1))
    }

    /// A main line (live · this pin · my place): a round icon in the app's red, a title and a line under it.
    private func pickRow(_ icon: String, _ title: String, _ sub: String?, enabled: Bool = true, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 14) {
                Image(systemName: icon).font(.system(size: 18)).foregroundStyle(.white)
                    .frame(width: 44, height: 44).background(ch.red, in: Circle())
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: title).font(.dorr(15.5, .bold)).foregroundStyle(ch.ink.opacity(enabled ? 1 : 0.5)).lineLimit(1)
                    if let sub { Text(verbatim: sub).font(.dorr(12.5)).foregroundStyle(ch.mut).lineLimit(1) }
                }
                Spacer(minLength: 0)
            }
            .padding(.horizontal, 16).padding(.vertical, 11)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }

    /// A nearby place / a search result: its name and address.
    private func placeRow(_ p: PlaceHitDto) -> some View {
        Button {
            guard let lat = p.lat, let lng = p.lng else { return }
            onSend(LocationPick(lat: lat, lng: lng, name: p.name, address: p.address))
        } label: {
            HStack(spacing: 14) {
                Image(systemName: "mappin.and.ellipse").font(.system(size: 18)).foregroundStyle(ch.mut)
                    .frame(width: 44, height: 44).background(ch.surfaceMuted, in: Circle())
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: p.name ?? "").font(.dorr(15, .semibold)).foregroundStyle(ch.ink).lineLimit(1)
                    if let a = p.address, !a.isEmpty, a != p.name {
                        Text(verbatim: a).font(.dorr(12.5)).foregroundStyle(ch.mut).lineLimit(1)
                    }
                }
                Spacer(minLength: 0)
            }
            .padding(.horizontal, 16).padding(.vertical, 10)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    private func section(_ text: String) -> some View {
        Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(ch.mut).padding(.horizontal, 16).padding(.top, 14).padding(.bottom, 4)
    }

    private var empty: some View {
        Text(L("ch_loc_nothing")).font(.dorr(13)).foregroundStyle(ch.mut).padding(.horizontal, 16).padding(.vertical, 16)
    }
}

// MARK: - the map in a bubble

/// A still picture of the map around a place, for a chat bubble (androidApp `DorrMapPreview`): Apple's map snapshot
/// (no key, nothing to pay), kept in memory. The drawn map shows until it's ready, and if it can't be made.
struct MapSnapshotView<Placeholder: View>: View {
    let center: CLLocationCoordinate2D
    @ViewBuilder let placeholder: () -> Placeholder
    @Environment(\.colorScheme) private var scheme
    @State private var image: UIImage?

    var body: some View {
        GeometryReader { geo in
            ZStack {
                if let image { Image(uiImage: image).resizable().scaledToFill() } else { placeholder() }
            }
            .frame(width: geo.size.width, height: geo.size.height)
            .clipped()
            .task(id: String(format: "%.4f,%.4f|%.0fx%.0f|%d", center.latitude, center.longitude, geo.size.width, geo.size.height, scheme == .dark ? 1 : 0)) {
                image = await MapSnapshots.image(center, size: geo.size, dark: scheme == .dark)
            }
        }
    }
}

@MainActor
enum MapSnapshots {
    private static let cache = NSCache<NSString, UIImage>()

    static func image(_ c: CLLocationCoordinate2D, size: CGSize, dark: Bool) async -> UIImage? {
        guard size.width > 0, size.height > 0 else { return nil }
        let key = String(format: "%.4f,%.4f|%.0fx%.0f|%d", c.latitude, c.longitude, size.width, size.height, dark ? 1 : 0) as NSString
        if let hit = cache.object(forKey: key) { return hit }
        let options = MKMapSnapshotter.Options()
        options.region = MKCoordinateRegion(center: c, latitudinalMeters: 600, longitudinalMeters: 600)
        options.size = size
        options.traitCollection = UITraitCollection(userInterfaceStyle: dark ? .dark : .light)
        guard let shot = try? await MKMapSnapshotter(options: options).start() else { return nil }
        cache.setObject(shot.image, forKey: key)
        return shot.image
    }
}
