import SwiftUI

// androidApp `rides/RidesSavedPlaces.kt` (R-5, R-174), `RidesAddons.kt` (R-233..R-237, R-NEW-06) and
// `RidesCircle.kt` (R-INN-07).

// MARK: - Saved places

extension RidesState {
    func loadPlaces() async {
        guard feature("saved_places") else { return }
        places = (try? await RidesApi.places().data) ?? places
    }

    /// Keep a place on the account: Home / Work replace the one there, a favourite is added.
    func savePlace(_ point: RidePoint, type: String) {
        Task {
            let existing = type == "home" ? places?.home?.id : (type == "work" ? places?.work?.id : nil)
            let title = type == "other" ? String((point.address.components(separatedBy: ",").first ?? point.address).prefix(60)) : nil
            let body = SaveAddressRequest(type: type, title: title, addressDetails: String(point.address.prefix(255)), latitude: point.lat, longitude: point.lng)
            do {
                if let existing { _ = try await AddressApi.update(existing, body) } else { _ = try await AddressApi.store(body) }
                await loadPlaces()
            } catch {
                self.error = error.apiFailure.message
            }
        }
    }

    fileprivate func goTo(_ place: RideSavedPlaceDto) {
        guard pickup != nil, let lat = place.lat, let lng = place.lng else { return }
        openDestination = false
        destination = RidePoint(lat: lat, lng: lng, address: place.address ?? place.title ?? "")
        stops = []
        loadQuotes()
    }

    fileprivate func setPlace(_ type: String) {
        savingPlace = type
        editing = "destination"
        step = .search
    }
}

private struct PlaceChip: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    var dashed = false
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 14)).foregroundStyle(wa.accent)
                Text(verbatim: label).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).lineLimit(1)
            }
            .padding(.horizontal, 14).padding(.vertical, 9)
            .background(dashed ? .clear : wa.settingsBackground, in: Capsule())
            .overlay { if dashed { Capsule().stroke(wa.accent.opacity(0.5), lineWidth: 1) } }
        }
        .buttonStyle(WaPressStyle())
    }
}

/// Home · Work · favourites as chips, then the last few destinations.
struct SavedPlacesRow: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState

    var body: some View {
        if let places = state.places {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    if let home = places.home {
                        PlaceChip(icon: "house.fill", label: L("rd_place_home")) { state.goTo(home) }
                    } else {
                        PlaceChip(icon: "plus", label: L("rd_place_set_home"), dashed: true) { state.setPlace("home") }
                    }
                    if let work = places.work {
                        PlaceChip(icon: "briefcase.fill", label: L("rd_place_work")) { state.goTo(work) }
                    } else {
                        PlaceChip(icon: "plus", label: L("rd_place_set_work"), dashed: true) { state.setPlace("work") }
                    }
                    ForEach(Array((places.favorites ?? []).enumerated()), id: \.offset) { _, fav in
                        PlaceChip(icon: "star.fill", label: fav.title ?? fav.address ?? "") { state.goTo(fav) }
                    }
                }
            }
            .padding(.top, 10)
            ForEach(Array((places.recent ?? []).prefix(3).enumerated()), id: \.offset) { _, recent in
                Button { state.goTo(recent) } label: {
                    HStack(spacing: 10) {
                        Image(systemName: "clock.arrow.circlepath").font(.system(size: 15)).foregroundStyle(wa.settingsMut)
                        Text(verbatim: recent.address ?? "").font(.dorr(14)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        Spacer(minLength: 0)
                    }
                    .padding(.vertical, 8)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
        }
    }
}

/// On the prices sheet: keep this destination as Home, Work or a favourite — unless it already is one.
struct SaveDestinationRow: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState

    var body: some View {
        if let destination = state.openDestination ? nil : state.destination, let places = state.places {
            let saved = ([places.home, places.work].compactMap { $0 } + (places.favorites ?? []))
                .contains { abs(($0.lat ?? 0) - destination.lat) < 0.001 && abs(($0.lng ?? 0) - destination.lng) < 0.001 }
            if !saved {
                HStack(spacing: 8) {
                    Image(systemName: "star").font(.system(size: 15)).foregroundStyle(wa.accent)
                    Text(L("rd_place_save")).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                    Spacer(minLength: 0)
                }
                .padding(.top, 6)
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        PlaceChip(icon: "house.fill", label: L("rd_place_home")) { state.savePlace(destination, type: "home") }
                        PlaceChip(icon: "briefcase.fill", label: L("rd_place_work")) { state.savePlace(destination, type: "work") }
                        PlaceChip(icon: "star.fill", label: L("rd_place_favorite")) { state.savePlace(destination, type: "other") }
                    }
                }
                .padding(.top, 4)
            }
        }
    }
}

// MARK: - Add-ons

/// What the rider adds to the trip — sent with the quote request (`options`).
struct TripOptions: Equatable {
    /// standard / wait_save / priority
    var speed = "standard"
    var childSeats = 0
    var pet = false
    var serviceAnimal = false
    var bagsSmall = 0
    var bagsLarge = 0

    var isEmpty: Bool { self == TripOptions() }

    var json: [String: JSONValue] {
        var o: [String: JSONValue] = [:]
        if speed == "wait_save" { o["wait_save"] = .bool(true) }
        if speed == "priority" { o["priority"] = .bool(true) }
        if childSeats > 0 { o["child_seats"] = .int(childSeats) }
        if pet { o["pet"] = .bool(true) }
        if serviceAnimal { o["service_animal"] = .bool(true) }
        if bagsSmall > 0 { o["bags_small"] = .int(bagsSmall) }
        if bagsLarge > 0 { o["bags_large"] = .int(bagsLarge) }
        return o
    }
}

func addonIcon(_ key: String) -> String {
    switch key {
    case "wait_save": return "hourglass.bottomhalf.filled"
    case "priority": return "speedometer"
    case "child_seats": return "figure.and.child.holdinghands"
    case "pet": return "pawprint.fill"
    case "service_animal": return "figure.roll"
    default: return "suitcase.fill"
    }
}

/// An add-on as a small chip (the booking row, the receipt).
struct AddonChip: View {
    @Environment(\.wa) private var wa
    let o: RideAddonDto

    var body: some View {
        HStack(spacing: 3) {
            Image(systemName: addonIcon(o.key)).font(.system(size: 11)).foregroundStyle(wa.accent)
            Text(verbatim: (o.label ?? "") + ((o.count ?? 0) > 1 ? " ×\(o.count!)" : "")).font(.dorr(11.5, .semibold)).foregroundStyle(wa.settingsInk)
        }
        .padding(.horizontal, 8).padding(.vertical, 3)
        .background(wa.accent.opacity(0.1), in: Capsule())
    }
}

/// One row on the booking sheet: what's added (or «add to your trip»), opening the sheet to change it.
struct AddonsRow: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let quote: RideQuoteDto
    @State private var open = false

    var body: some View {
        if let offer = quote.addonsOffer, !offer.isEmpty {
            Button { open = true } label: {
                HStack(spacing: 10) {
                    Image(systemName: "slider.horizontal.3").font(.system(size: 17)).foregroundStyle(wa.accent)
                        .frame(width: 38, height: 38).background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    VStack(alignment: .leading, spacing: 2) {
                        Text(L("ad_title")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                        if (quote.options ?? []).isEmpty {
                            Text(L("ad_none")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                        } else {
                            ScrollView(.horizontal, showsIndicators: false) {
                                HStack(spacing: 6) { ForEach(quote.options ?? [], id: \.key) { AddonChip(o: $0) } }
                            }
                        }
                        if let e = state.addonsError { Text(verbatim: e).font(.dorr(11)).foregroundStyle(AppColors.danger) }
                    }
                    Spacer(minLength: 0)
                    Text(L("rd_change")).font(.dorr(13, .bold)).foregroundStyle(wa.accent)
                    Image(systemName: "chevron.forward").font(.system(size: 12, weight: .bold)).foregroundStyle(wa.accent)
                }
                .padding(.horizontal, 12).padding(.vertical, 11)
                .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.accent.opacity(0.18), lineWidth: 1))
            }
            .buttonStyle(WaPressStyle())
            .padding(.top, 6)
            .sheet(isPresented: $open) {
                AddonsSheet(state: state, quote: quote, offer: offer) { open = false }.dorrThemed().presentationDetents([.large])
            }
        }
    }
}

private struct AddonsSheet: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let quote: RideQuoteDto
    let offer: RideAddonsOfferDto
    let onClose: () -> Void
    @State private var o = TripOptions()

    private func fee(_ minor: Int?, perUnit: Bool = false) -> String {
        guard let minor, minor > 0 else { return L("ad_free") }
        return "+" + rideMoney(minor, quote.currency) + (perUnit ? " " + L("ad_each") : "")
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                Text(L("ad_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                Text(L("ad_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.bottom, 12)
                // R-233, R-234: how soon — the usual, cheaper for a longer wait, or first.
                if offer.waitSave != nil || offer.priority != nil {
                    Text(L("ad_speed")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 6)
                    HStack(spacing: 8) {
                        speedTile("briefcase", L("ad_standard"), nil, "standard", wa.accent)
                        if let w = offer.waitSave {
                            speedTile("hourglass.bottomhalf.filled", L("ad_wait_save"), L("ad_wait_save_sub", (w.discountBp ?? 0) / 100, (w.extraWaitS ?? 0) / 60), "wait_save", AppColors.success)
                        }
                        if let p = offer.priority {
                            speedTile("speedometer", L("ad_priority"), "+" + rideMoney(p.feeMinor, quote.currency), "priority", AppColors.brandOrange)
                        }
                    }
                    .padding(.bottom, 14)
                }
                if let c = offer.childSeats {
                    stepper("figure.and.child.holdinghands", L("ad_child_seats"), fee(c.feeMinor, perUnit: true), value: o.childSeats, max: c.max ?? 2) { o.childSeats = $0 }
                }
                if let p = offer.pet {
                    toggle("pawprint.fill", L("ad_pet"), fee(p.feeMinor), on: o.pet) { o.pet = $0 }
                }
                if offer.serviceAnimal != nil {
                    toggle("figure.roll", L("ad_service_animal"), L("ad_service_animal_sub"), on: o.serviceAnimal) { o.serviceAnimal = $0 }
                }
                if let l = offer.luggage {
                    let used = o.bagsSmall + 2 * o.bagsLarge
                    let cap = l.capacity ?? 4
                    Text(L("ad_luggage")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 6)
                    Text(verbatim: L("ad_luggage_room", used, cap)).font(.dorr(12)).foregroundStyle(used > cap ? AppColors.danger : wa.settingsMut)
                    stepper("suitcase.fill", L("ad_bags_small"), nil, value: o.bagsSmall, max: 10) { o.bagsSmall = $0 }
                    stepper("suitcase.fill", L("ad_bags_large"), (l.largeFeeMinor ?? 0) > 0 ? fee(l.largeFeeMinor, perUnit: true) : nil, value: o.bagsLarge, max: 10) { o.bagsLarge = $0 }
                }
                AccentButton(text: L("ad_apply")) {
                    if o != state.addons {
                        state.addons = o
                        state.addonsError = nil
                        state.loadQuotes(reprice: true)
                    }
                    onClose()
                }
                .padding(.top, 14)
            }
            .padding(18)
        }
        .background(wa.settingsCard.ignoresSafeArea())
        .onAppear { o = state.addons }
    }

    private func speedTile(_ icon: String, _ title: String, _ sub: String?, _ key: String, _ tint: Color) -> some View {
        let on = o.speed == key
        return Button { o.speed = key } label: {
            VStack(spacing: 2) {
                Image(systemName: icon).font(.system(size: 19)).foregroundStyle(on ? tint : wa.settingsMut)
                Text(verbatim: title).font(.dorr(12.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1).minimumScaleFactor(0.8)
                if let sub { Text(verbatim: sub).font(.dorr(10.5, .semibold)).foregroundStyle(on ? tint : wa.settingsMut).lineLimit(1).minimumScaleFactor(0.8) }
            }
            .frame(maxWidth: .infinity).padding(.vertical, 10).padding(.horizontal, 6)
            .background(on ? tint.opacity(0.14) : wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(on ? tint : .clear, lineWidth: 1.5))
            .scaleEffect(on ? 1.04 : 1)
        }
        .buttonStyle(.plain)
        .animation(.spring(response: 0.3, dampingFraction: 0.5), value: on)
    }

    private func toggle(_ icon: String, _ title: String, _ sub: String?, on: Bool, change: @escaping (Bool) -> Void) -> some View {
        HStack(spacing: 10) {
            Image(systemName: icon).font(.system(size: 18)).foregroundStyle(wa.accent).frame(width: 24)
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: title).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
                if let sub { Text(verbatim: sub).font(.dorr(11.5)).foregroundStyle(wa.settingsMut) }
            }
            Spacer(minLength: 0)
            Toggle("", isOn: Binding(get: { on }, set: change)).labelsHidden().tint(wa.accent)
        }
        .padding(.vertical, 6)
    }

    private func stepper(_ icon: String, _ title: String, _ sub: String?, value: Int, max: Int, change: @escaping (Int) -> Void) -> some View {
        HStack(spacing: 10) {
            Image(systemName: icon).font(.system(size: 18)).foregroundStyle(wa.accent).frame(width: 24)
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: title).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
                if let sub { Text(verbatim: sub).font(.dorr(11.5)).foregroundStyle(wa.settingsMut) }
            }
            Spacer(minLength: 0)
            HStack(spacing: 0) {
                stepCircle("minus", enabled: value > 0) { change(value - 1) }
                Text(verbatim: "\(value)").font(.dorr(16, .black)).foregroundStyle(wa.settingsInk).frame(width: 32)
                stepCircle("plus", enabled: value < max) { change(value + 1) }
            }
        }
        .padding(.vertical, 6)
    }

    private func stepCircle(_ icon: String, enabled: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 14, weight: .bold)).foregroundStyle(enabled ? wa.accent : wa.settingsMut.opacity(0.4))
                .frame(width: 32, height: 32).background(enabled ? wa.accent.opacity(0.12) : wa.settingsBackground, in: Circle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

// MARK: - DORR Circle (R-INN-07)

extension RidesState {
    func loadCircle() async {
        guard feature("favorite_circle") else { return }
        circleDrivers = (try? await RidesApi.circle().data?.drivers) ?? circleDrivers
    }

    /// What the booking sends: `{driver_ids: [...]}` (empty = all favourites), or nil for the usual search.
    var circleBody: [String: JSONValue]? {
        guard useCircle, !circleDrivers.isEmpty else { return nil }
        return ["driver_ids": .array(circlePicked.map { .int($0) })]
    }
}

/// On the booking sheet: "Ask my favorite captains first", and which of them (none picked = all of them).
struct CircleRow: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState

    var body: some View {
        let has = !state.circleDrivers.isEmpty
        HStack(spacing: 10) {
            Image(systemName: "heart.fill").font(.system(size: 17)).foregroundStyle(wa.accent)
            VStack(alignment: .leading, spacing: 0) {
                Text(L("rd_circle_title")).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
                Text(L(has ? "rd_circle_hint" : "rd_circle_none")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            Spacer(minLength: 0)
            RedToggle(on: state.useCircle && has) { if has { state.useCircle.toggle() } }
        }
        .padding(.vertical, 6)
        if state.useCircle && has {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(state.circleDrivers, id: \.driverId) { driver in
                        let picked = state.circlePicked.contains(driver.driverId)
                        Button {
                            if picked { state.circlePicked.removeAll { $0 == driver.driverId } } else { state.circlePicked.append(driver.driverId) }
                        } label: {
                            HStack(spacing: 0) {
                                Text(verbatim: driver.name ?? "").font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                                if let r = driver.rating { Text(verbatim: "  ★ " + String(format: "%.1f", r)).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                            }
                            .padding(.horizontal, 14).padding(.vertical, 9)
                            .background(picked ? wa.accent.opacity(0.14) : wa.settingsBackground, in: Capsule())
                            .overlay { if picked { Capsule().stroke(wa.accent, lineWidth: 1) } }
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
            Text(verbatim: L(state.circlePicked.isEmpty ? "rd_circle_all" : "rd_circle_picked", state.circlePicked.count)).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                .padding(.top, 4).padding(.bottom, 2)
        }
    }
}

/// While searching: "Asking your favorite captains" — and the one question if none of them could take it.
struct CircleNotice: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let ride: RideDto
    @State private var keepWaiting = false
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        if let circle = ride.circle, circle.active == true {
            HStack(spacing: 6) {
                Image(systemName: "heart.fill").font(.system(size: 13)).foregroundStyle(wa.accent)
                Text(verbatim: L("rd_circle_asking", circle.count ?? 0)).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
            }
            .padding(.horizontal, 12).padding(.vertical, 6)
            .background(wa.accent.opacity(0.1), in: Capsule())
            .padding(.top, 6)
            if circle.asked == true && !keepWaiting {
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 8) {
                        Image(systemName: "person.3.fill").font(.system(size: 15)).foregroundStyle(AppColors.warning)
                        Text(L("rd_circle_ask")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                    }
                    Text(L("rd_circle_ask_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 4)
                    AccentButton(text: L("rd_circle_widen"), busy: busy) {
                        busy = true
                        error = nil
                        Task {
                            do {
                                if let fresh = try await RidesApi.widenCircle(ride.uuid, key: RidesApi.key()).data { state.ride = fresh }
                            } catch {
                                self.error = error.apiFailure.message
                            }
                            busy = false
                        }
                    }
                    .padding(.top, 10)
                    Button { keepWaiting = true } label: {
                        Text(L("rd_circle_keep")).font(.dorr(13, .bold)).foregroundStyle(wa.accent).frame(maxWidth: .infinity).padding(.vertical, 12)
                    }
                    .buttonStyle(.plain)
                    if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(AppColors.danger).padding(.top, 6) }
                }
                .padding(12)
                .background(AppColors.warning.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .padding(.top, 10)
            }
        }
    }
}
