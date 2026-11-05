import CoreLocation
import SwiftUI

// androidApp `moving/MovingPlacePicker.kt` — setting one end of the move, the rides' two ways: the search page (the same
// pill field, popular places, the server's suggestions) and, from it, the map with the centre pin. Location is asked
// for but not required: without it the map and the search still work. Also the day-and-time sheet (the rides' day
// strip and rolling hour and minutes).

private let riyadh = CLLocationCoordinate2D(latitude: 24.7136, longitude: 46.6753)

@MainActor
struct MovingPlacePicker: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var location = RideLocation.shared
    let title: String
    let start: RidePoint?
    let near: CLLocationCoordinate2D?
    let onClose: () -> Void
    let onPick: (RidePoint) -> Void
    @StateObject private var camera: DorrCamera
    @State private var onMap = false
    @State private var query = ""
    @State private var results: [RidePoint] = []
    @State private var popular: [RidePoint] = []
    @State private var searching = false
    @State private var me: CLLocationCoordinate2D?
    @State private var pinned: RidePoint?
    @FocusState private var focused: Bool

    init(title: String, start: RidePoint?, near: CLLocationCoordinate2D?, onClose: @escaping () -> Void, onPick: @escaping (RidePoint) -> Void) {
        self.title = title
        self.start = start
        self.near = near
        self.onClose = onClose
        self.onPick = onPick
        _camera = StateObject(wrappedValue: DorrCamera(start: start?.latLng ?? near ?? riyadh, zoom: 15))
        _me = State(initialValue: near)
        _pinned = State(initialValue: start)
    }

    private var typed: Bool { query.trimmingCharacters(in: .whitespaces).count >= 2 }

    var body: some View {
        ZStack {
            if onMap { mapPage.transition(.opacity) } else { searchPage.transition(.opacity) }
        }
        .animation(.easeInOut(duration: 0.25), value: onMap)
        .onAppear {
            if !location.allowed && !location.denied { location.ask() }
            focused = true
        }
        .task(id: location.allowed) {
            if location.allowed, let here = await location.current() {
                me = here
                if start == nil && near == nil { camera.move(to: here, zoom: 16) }
            }
            popular = await popularPlaces(near: me)
        }
        .task(id: query) {
            guard typed else { results = []; return }
            try? await Task.sleep(nanoseconds: 250_000_000)
            guard !Task.isCancelled else { return }
            searching = true
            results = await searchPlaces(query, near: me)
            searching = false
        }
        // The pin: when the map stops, the point under it is the place.
        .onChange(of: camera.isMoving) { moving in if !moving { pinFromMap() } }
        .onChange(of: onMap) { shown in if shown && !camera.isMoving { pinFromMap() } }
    }

    private func pinFromMap() {
        guard onMap else { return }
        let target = camera.target
        Task {
            let name = await addressOf(target) ?? coordinateText(target)
            pinned = RidePoint(lat: target.latitude, lng: target.longitude, address: name)
        }
    }

    private var mapPage: some View {
        ZStack {
            wa.settingsBackground.ignoresSafeArea()
            DorrMap(camera: camera, padding: EdgeInsets(top: 0, leading: 0, bottom: 260, trailing: 0)).ignoresSafeArea()
            PickupPin(lifted: camera.isMoving, color: AppColors.brandOrange, label: L("pk_pin_here"))
            VStack {
                HStack(alignment: .top) {
                    RideRoundButton(icon: "arrow.backward", label: L("common_back")) { onMap = false }
                    Spacer()
                    if let here = me { RideRoundButton(icon: "location.fill") { camera.animate(to: here, zoom: 16) } }
                }
                .padding(16)
                Spacer()
            }
            VStack {
                Spacer()
                PanelCard {
                    Text(L("rd_moving_pin")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: pinned?.address ?? "…").font(.dorr(16, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    AccentButton(text: L("rd_confirm_point"), enabled: pinned != nil) { if let pinned { onPick(pinned) } }
                        .padding(.top, 14)
                }
            }
            .ignoresSafeArea(edges: .bottom)
        }
    }

    private var searchPage: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 10) {
                RideRoundButton(icon: "arrow.backward", label: L("common_back"), action: onClose)
                Text(verbatim: title).font(.dorr(20, .heavy)).foregroundStyle(wa.settingsInk)
                Spacer()
            }
            .padding(12)
            HStack(spacing: 8) {
                Image(systemName: "magnifyingglass").foregroundStyle(wa.accent)
                TextField(L("rd_search_hint"), text: $query).font(.dorr(15)).foregroundStyle(wa.settingsInk).focused($focused).tint(wa.accent)
                if searching { ProgressView().tint(wa.accent).scaleEffect(0.8) }
            }
            .padding(.horizontal, 16)
            .frame(minHeight: 52)
            .background(wa.night ? wa.bg : Color(hex: 0xFBF7F8), in: Capsule())
            .overlay(Capsule().stroke(focused ? wa.accent : (wa.night ? wa.line : Color(hex: 0xF3D5DB)), lineWidth: focused ? 1.5 : 1))
            .padding(.horizontal, 16)
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 0) {
                    Button {
                        onMap = true
                        if let at = start?.latLng ?? me { camera.animate(to: at, zoom: 16) }
                    } label: {
                        HStack(spacing: 10) {
                            Image(systemName: "map").foregroundStyle(wa.accent)
                            Text(L("rd_pick_on_map")).font(.dorr(15, .semibold)).foregroundStyle(wa.accent)
                            Spacer()
                        }
                        .padding(.vertical, 12)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    if typed && !searching && results.isEmpty {
                        Text(L("rd_no_results")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.vertical, 8)
                    }
                    if !typed && !popular.isEmpty {
                        Text(L("rd_popular_near")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 10).padding(.bottom, 4)
                    }
                    ForEach(Array((typed ? results : popular).enumerated()), id: \.offset) { _, place in
                        Button { onPick(place) } label: {
                            HStack(spacing: 10) {
                                Image(systemName: typed ? "mappin" : "star.fill").font(.system(size: 15)).foregroundStyle(wa.accent)
                                    .frame(width: 38, height: 38).background(wa.accent.opacity(0.1), in: Circle())
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(verbatim: place.name ?? place.address).font(.dorr(15, .semibold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                                    if let name = place.name, name != place.address {
                                        Text(verbatim: place.address).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                                    }
                                }
                                Spacer(minLength: 0)
                            }
                            .padding(.vertical, 10)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(.horizontal, 16).padding(.vertical, 8)
            }
        }
        .background(wa.settingsBackground.ignoresSafeArea())
    }
}

// MARK: - The day and the time

private let minuteStep = 5

/// Picking the day and the time: the rides' day strip and rolling hour and minutes. The server checks it is still ahead.
struct MovingTimeSheet: View {
    @Environment(\.wa) private var wa
    let initial: Date?
    let title: String
    let onClose: () -> Void
    let onPick: (Date) -> Void
    @State private var picked = Date()
    @State private var first = Date()
    @State private var last = Date()

    private func clamp(_ t: Date) -> Date { min(max(t, first), last) }

    private var days: [Date] {
        let cal = Calendar.current
        var out: [Date] = []
        var d = cal.startOfDay(for: first)
        let end = cal.startOfDay(for: last)
        while d <= end {
            out.append(d)
            d = cal.date(byAdding: .day, value: 1, to: d) ?? end.addingTimeInterval(1)
        }
        return out
    }

    var body: some View {
        let cal = Calendar.current
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 10) {
                Image(systemName: "calendar").foregroundStyle(wa.accent)
                    .frame(width: 40, height: 40).background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                Text(verbatim: title).font(.dorr(19, .heavy)).foregroundStyle(wa.settingsInk)
            }
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(days, id: \.self) { day in
                        let on = cal.isDate(picked, inSameDayAs: day)
                        Button {
                            let time = cal.dateComponents([.hour, .minute], from: picked)
                            picked = clamp(cal.date(bySettingHour: time.hour ?? 0, minute: time.minute ?? 0, second: 0, of: day) ?? day)
                        } label: {
                            VStack(spacing: 0) {
                                Text(verbatim: cal.isDateInToday(day) ? L("rd_schedule_today") : (cal.isDateInTomorrow(day) ? L("rd_schedule_tomorrow") : mvFormatter("EEE").string(from: day)))
                                    .font(.dorr(11)).foregroundStyle(on ? .white.opacity(0.85) : wa.settingsMut).lineLimit(1)
                                Text(verbatim: mvFormatter("d").string(from: day)).font(.dorr(22, .heavy)).foregroundStyle(on ? .white : wa.settingsInk)
                                Text(verbatim: mvFormatter("MMM").string(from: day)).font(.dorr(11)).foregroundStyle(on ? .white.opacity(0.85) : wa.settingsMut)
                            }
                            .frame(width: 64).padding(.vertical, 10)
                            .background(on ? wa.accent : wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                            .scaleEffect(on ? 1.06 : 1)
                        }
                        .buttonStyle(.plain)
                        .animation(.spring(response: 0.3, dampingFraction: 0.55), value: on)
                    }
                }
                .padding(.vertical, 4)
            }
            .padding(.top, 16)
            Text(L("mv_time_label")).font(.dorr(12)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity).padding(.top, 20)
            HStack(spacing: 6) {
                WheelColumn(value: mvFormatter("HH").string(from: picked),
                            onUp: { picked = clamp(picked.addingTimeInterval(3600)) },
                            onDown: { picked = clamp(picked.addingTimeInterval(-3600)) })
                Text(verbatim: ":").font(.dorr(40, .heavy)).foregroundStyle(wa.settingsInk)
                WheelColumn(value: mvFormatter("mm").string(from: picked),
                            onUp: { picked = clamp(picked.addingTimeInterval(TimeInterval(minuteStep * 60))) },
                            onDown: { picked = clamp(picked.addingTimeInterval(-TimeInterval(minuteStep * 60))) })
            }
            .environment(\.layoutDirection, .leftToRight)
            .frame(maxWidth: .infinity)
            .padding(.vertical, 6)
            Text(verbatim: rideWhenText(picked)).font(.dorr(14, .bold)).foregroundStyle(wa.accent).frame(maxWidth: .infinity)
            AccentButton(text: L("mv_confirm_time")) { onPick(picked) }
                .padding(.top, 16)
        }
        .padding(.horizontal, 20).padding(.top, 20).padding(.bottom, 28)
        .frame(maxHeight: .infinity, alignment: .top)
        .background(wa.settingsCard.ignoresSafeArea())
        .onAppear {
            // Now and five minutes, rounded up to the next five; ninety days ahead at most.
            let cal = Calendar.current
            var t = Date().addingTimeInterval(TimeInterval(minuteStep * 60))
            t = cal.date(bySetting: .second, value: 0, of: t) ?? t
            let minute = cal.component(.minute, from: t)
            t = cal.date(byAdding: .minute, value: (minuteStep - minute % minuteStep) % minuteStep, to: t) ?? t
            first = t
            last = cal.date(byAdding: .day, value: 90, to: t) ?? t
            if let initial, initial >= first, initial <= last { picked = initial } else { picked = first }
        }
    }
}
