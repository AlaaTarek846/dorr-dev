import SwiftUI

// androidApp `rides/RidesTripDetails.kt` (TripDetailsRow D-60 / D-61 / O-15, BookForOtherRow R-13),
// `RidesWithMe.kt` (O-17, O-30..O-33) and `RidesPickupPoints.kt` (R-16..R-18).

private let teal = Color(hex: 0x00897B)

/// How many ride (moving to a roomier category when it doesn't seat them) and a short note for the captain.
struct TripDetailsRow: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    @State private var noteOpen = false

    var body: some View {
        if let quote = state.selected {
            let seats = max(quote.category?.capacity ?? 4, 1)
            // O-15: more riders than this category seats — the category that seats them (the Family one first).
            let roomier = state.quotes.filter { ($0.category?.capacity ?? 4) > state.passengers }
                .sorted { a, b in
                    let fa = a.category?.slug == "family", fb = b.category?.slug == "family"
                    return fa != fb ? fa : (a.category?.capacity ?? 4) < (b.category?.capacity ?? 4)
                }
                .first
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    icon("person.2.fill")
                    Text(L("rd_passengers")).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
                    Spacer(minLength: 0)
                    HStack(spacing: 0) {
                        step("minus", enabled: state.passengers > 1) { state.passengers -= 1 }
                        Text(verbatim: "\(state.passengers)").font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk).frame(width: 34)
                            .id(state.passengers).transition(.scale.combined(with: .opacity))
                        step("plus", enabled: state.passengers < seats || roomier != nil) {
                            if state.passengers < seats {
                                state.passengers += 1
                            } else if let roomier {
                                state.selected = roomier
                                state.riderPrice = roomier.bid?.suggestedMinor
                                state.passengers += 1
                                state.categorySwitchedTo = roomier.category?.name ?? roomier.category?.slug
                            }
                        }
                    }
                    .animation(.easeInOut(duration: 0.2), value: state.passengers)
                }
                .padding(.vertical, 6)
                Button { noteOpen.toggle() } label: {
                    HStack(spacing: 10) {
                        icon("square.and.pencil")
                        Text(verbatim: state.pickupNote.isEmpty ? L("rd_pickup_note_title") : state.pickupNote).font(.dorr(14, .semibold))
                            .foregroundStyle(state.pickupNote.isEmpty ? wa.settingsInk : wa.accent).lineLimit(1)
                        Spacer(minLength: 0)
                    }
                    .padding(.vertical, 6)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                if noteOpen {
                    DorrTextArea(value: Binding(get: { state.pickupNote }, set: { state.pickupNote = String($0.replacingOccurrences(of: "\n", with: " ").prefix(120)) }),
                                 placeholder: L("rd_pickup_note_hint"), icon: "square.and.pencil", singleLine: true)
                    HStack {
                        Text(L("rd_pickup_note_privacy")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                        Spacer()
                        Text(verbatim: "\(state.pickupNote.count)/120").font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    }
                    .padding(.vertical, 4)
                }
            }
            .animation(.easeInOut(duration: 0.2), value: noteOpen)
            .onAppear {
                noteOpen = !state.pickupNote.isEmpty
                if state.passengers > seats { state.passengers = seats }
            }
            .dorrDialog(isPresented: Binding(get: { state.categorySwitchedTo != nil }, set: { if !$0 { state.categorySwitchedTo = nil } })) {
                FlAlert(title: L("rd_family_title"), content: {
                    Text(verbatim: L("rd_family_body", state.passengers, state.categorySwitchedTo ?? "")).font(.dorr(14)).foregroundStyle(wa.settingsInk)
                }, confirm: L("rd_done"), onConfirm: { state.categorySwitchedTo = nil }, dismiss: nil, onDismiss: { state.categorySwitchedTo = nil })
            }
        }
    }

    private func icon(_ name: String) -> some View {
        Image(systemName: name).font(.system(size: 14)).foregroundStyle(wa.accent).frame(width: 30, height: 30).background(wa.accent.opacity(0.1), in: Circle())
    }

    private func step(_ icon: String, enabled: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 14, weight: .bold)).foregroundStyle(.white)
                .frame(width: 32, height: 32).background(enabled ? wa.accent : wa.settingsMut.opacity(0.3), in: Circle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

/// R-13: the ride is for someone else — their name and number, and the rider's own consent tick (never pre-ticked).
struct BookForOtherRow: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 10) {
                Image(systemName: "person.badge.plus").font(.system(size: 14)).foregroundStyle(wa.accent)
                    .frame(width: 30, height: 30).background(wa.accent.opacity(0.1), in: Circle())
                Text(L("rd_for_other_title")).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
                Spacer(minLength: 0)
                RedToggle(on: state.forOther) { state.forOther.toggle() }
            }
            .padding(.vertical, 6)
            if state.forOther {
                DorrTextArea(value: Binding(get: { state.beneficiaryName }, set: { state.beneficiaryName = String($0.prefix(60)) }), placeholder: L("rd_for_other_name"), singleLine: true)
                DorrTextArea(value: Binding(get: { state.beneficiaryPhone }, set: { v in state.beneficiaryPhone = String(v.filter { $0.isNumber || $0 == "+" || $0 == " " }.prefix(18)) }),
                             placeholder: L("rd_for_other_phone"), singleLine: true)
                    .keyboardType(.phonePad)
                HStack(alignment: .top, spacing: 8) {
                    Image(systemName: "info.circle.fill").font(.system(size: 14)).foregroundStyle(AppColors.warning)
                    Text(L("rd_for_other_responsibility")).font(.dorr(12)).foregroundStyle(wa.settingsInk)
                    Spacer(minLength: 0)
                }
                .padding(10)
                .background(AppColors.warning.opacity(0.1), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                HStack(spacing: 6) {
                    Image(systemName: "lock.fill").font(.system(size: 11)).foregroundStyle(wa.settingsMut)
                    Text(L("rd_for_other_privacy")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                }
                Button { state.beneficiaryConsent.toggle() } label: {
                    HStack(spacing: 10) {
                        Image(systemName: state.beneficiaryConsent ? "checkmark.square.fill" : "square").font(.system(size: 20)).foregroundStyle(wa.accent)
                        Text(L("rd_for_other_consent")).font(.dorr(13)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.leading)
                        Spacer(minLength: 0)
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
        }
        .animation(.easeInOut(duration: 0.2), value: state.forOther)
    }
}

// MARK: - With Me (O-30..O-33) and by the hour (O-17)

/// «أنا معك» on the booking sheet: riders going the same way share the car and the fare; the rider chooses who may
/// share with them — anyone, men only, or women only.
struct ShareWithRow: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 6) {
                Image(systemName: "person.3.fill").font(.system(size: 15)).foregroundStyle(teal)
                Text(L("wm_share_title")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
            }
            Text(L("wm_share_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 2).padding(.bottom, 8)
            HStack(spacing: 8) {
                option("person.3.fill", L("wm_share_any"), "any")
                option("figure.stand", L("wm_share_male"), "male")
                option("figure.stand.dress", L("wm_share_female"), "female")
            }
        }
        .padding(12)
        .background(teal.opacity(0.07), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .padding(.vertical, 6)
    }

    private func option(_ icon: String, _ label: String, _ key: String) -> some View {
        let on = state.shareWith == key
        return Button { state.shareWith = key } label: {
            VStack(spacing: 2) {
                Image(systemName: icon).font(.system(size: 17)).foregroundStyle(on ? .white : teal)
                Text(verbatim: label).font(.dorr(12, on ? .bold : .regular)).foregroundStyle(on ? .white : wa.settingsInk)
            }
            .frame(maxWidth: .infinity).padding(.vertical, 8)
            .background(on ? teal : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(WaPressStyle())
        .animation(.easeInOut(duration: 0.2), value: on)
    }
}

/// «by the distance | by the hour» under the categories, and the hours (− / +). Switching re-prices that category
/// (hours × its hour price); back to the distance brings every category back.
struct HourlyRow: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let quote: RideQuoteDto

    private func price(_ hours: Int?) {
        state.hourlyHours = hours
        state.hourlySlug = hours == nil ? nil : quote.category?.slug
        state.loadQuotes(reprice: true)
    }

    var body: some View {
        if let offer = quote.hourlyOffer {
            let hourly = state.hourlyHours != nil
            let minH = offer.minHours ?? 1, maxH = offer.maxHours ?? 8
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 0) {
                    ForEach([(false, "hr_by_distance"), (true, "hr_by_hour")], id: \.0) { byHour, label in
                        let on = hourly == byHour
                        Button { price(byHour ? minH : nil) } label: {
                            Text(L(label)).font(.dorr(13, .bold)).foregroundStyle(on ? .white : wa.settingsInk)
                                .frame(maxWidth: .infinity).padding(.vertical, 9)
                                .background(on ? wa.accent : .clear, in: Capsule())
                        }
                        .buttonStyle(.plain)
                        .disabled(state.busy || on)
                    }
                }
                .padding(3)
                .background(wa.settingsCard, in: Capsule())
                if hourly {
                    let hours = state.hourlyHours ?? minH
                    HStack {
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("hr_hours")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                            Text(verbatim: L("hr_hour_price", rideMoney(offer.hourPriceMinor, quote.currency))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        }
                        Spacer()
                        HStack(spacing: 0) {
                            hourStep("−", enabled: hours > minH && !state.busy) { price(hours - 1) }
                            Text(verbatim: "\(hours)").font(.dorr(18, .black)).foregroundStyle(wa.settingsInk).frame(width: 40)
                            hourStep("+", enabled: hours < maxH && !state.busy) { price(hours + 1) }
                        }
                    }
                    .padding(.top, 10)
                    if (offer.overtimePerMinMinor ?? 0) > 0 {
                        Text(verbatim: L("hr_overtime", rideMoney(offer.overtimePerMinMinor, quote.currency))).font(.dorr(11)).foregroundStyle(wa.settingsMut).padding(.top, 6)
                    }
                }
            }
            .padding(10)
            .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .padding(.top, 6)
        }
    }

    private func hourStep(_ text: String, enabled: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(18, .black)).foregroundStyle(enabled ? wa.accent : wa.settingsMut)
                .frame(width: 34, height: 34).background(enabled ? wa.accent.opacity(0.14) : wa.settingsCard, in: Circle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

/// On the ride: «N hours booked — until HH:MM».
struct HourlyRideBanner: View {
    @Environment(\.wa) private var wa
    let hourly: RideHourlyDto
    let currency: RideCurrencyDto?

    var body: some View {
        let until = parseRideTime(hourly.endsAt).map(rideClock)
        HStack(spacing: 10) {
            Text(verbatim: "⏱").font(.system(size: 18)).frame(width: 36, height: 36).background(wa.accent, in: Circle())
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: until.map { L("hr_ride_until", hourly.hours ?? 0, $0) } ?? L("hr_ride_hours", hourly.hours ?? 0)).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                if (hourly.overtimePerMinMinor ?? 0) > 0 {
                    Text(verbatim: L("hr_overtime", rideMoney(hourly.overtimePerMinMinor, currency))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
            }
            Spacer(minLength: 0)
        }
        .padding(12)
        .background(wa.accent.opacity(0.1), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        .padding(.top, 10)
    }
}

/// On the ride: «a shared ride — N in the car — you saved X».
struct SharedRideBanner: View {
    @Environment(\.wa) private var wa
    let pool: RidePoolDto
    let currency: RideCurrencyDto?

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: "person.3.fill").font(.system(size: 15)).foregroundStyle(.white).frame(width: 36, height: 36).background(teal, in: Circle())
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: L("wm_shared_ride", pool.riders ?? 1)).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                if (pool.discountMinor ?? 0) > 0 {
                    Text(verbatim: L("wm_saved", rideMoney(pool.discountMinor, currency))).font(.dorr(12, .semibold)).foregroundStyle(teal)
                }
            }
            Spacer(minLength: 0)
        }
        .padding(12)
        .background(teal.opacity(0.1), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        .padding(.top, 10)
    }
}

// MARK: - Approved pickup / drop-off points (R-16..R-18)

private func pointKey(_ p: RidePoint) -> String { String(format: "%.5f,%.5f", p.lat, p.lng) }

extension RidesState {
    /// Before pricing: is the pickup (or the drop-off) in a place with approved points, not decided yet? Opens the
    /// chooser and returns true; the chooser prices the trip again once the rider has decided.
    func askForPoint(_ pickup: RidePoint, _ destination: RidePoint?) async -> Bool {
        if pointKey(pickup) != decidedPickup {
            pickupPointId = nil
            if let around = try? await RidesApi.pickupPoints(lat: pickup.lat, lng: pickup.lng, purpose: "pickup").data, !(around.points ?? []).isEmpty {
                pointChoices = around
                pointsChooser = "pickup"
                return true
            }
            decidedPickup = pointKey(pickup)
        }
        if let destination, pointKey(destination) != decidedDropoff {
            dropoffPointId = nil
            if let around = try? await RidesApi.pickupPoints(lat: destination.lat, lng: destination.lng, purpose: "dropoff").data, !(around.points ?? []).isEmpty {
                pointChoices = around
                pointsChooser = "dropoff"
                return true
            }
            decidedDropoff = pointKey(destination)
        }
        return false
    }

    /// The rider chose a point (or, where allowed, kept their own spot): the trip is priced from it.
    fileprivate func decide(_ purpose: String, _ point: RidePickupPointDto?) {
        if purpose == "pickup" {
            pickupPointId = point?.id
            if let point, let lat = point.lat, let lng = point.lng { pickup = RidePoint(lat: lat, lng: lng, address: point.name ?? pickup?.address ?? "") }
            decidedPickup = pickup.map(pointKey)
        } else {
            dropoffPointId = point?.id
            if let point, let lat = point.lat, let lng = point.lng { destination = RidePoint(lat: lat, lng: lng, address: point.name ?? destination?.address ?? "") }
            decidedDropoff = destination.map(pointKey)
        }
        pointsChooser = nil
        pointChoices = nil
        loadQuotes()
    }
}

private func pointIcon(_ kind: String?) -> String {
    switch kind {
    case "gate": return "door.left.hand.open"
    case "terminal": return "airplane"
    case "entrance": return "arrow.right.to.line"
    case "parking": return "parkingsign"
    default: return "mappin.and.ellipse"
    }
}

/// "Choose your pickup point" — the zone's approved gates, terminals, entrances and parkings, nearest first.
struct PickupPointsPanel: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState

    var body: some View {
        if let purpose = state.pointsChooser, let choices = state.pointChoices {
            PanelCard {
                ScrollView(showsIndicators: false) {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L(purpose == "pickup" ? "rd_points_title_pickup" : "rd_points_title_dropoff")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                        if let zone = choices.zone?.name { Text(verbatim: zone).font(.dorr(13)).foregroundStyle(wa.settingsMut) }
                        Text(L(choices.required == true ? "rd_points_required" : "rd_points_optional")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                            .padding(.top, 4).padding(.bottom, 10)
                        ForEach((choices.points ?? []).sorted { ($0.distanceM ?? .max) < ($1.distanceM ?? .max) }, id: \.id) { point in
                            Button { state.decide(purpose, point) } label: {
                                HStack(spacing: 12) {
                                    Image(systemName: pointIcon(point.kind)).font(.system(size: 17)).foregroundStyle(wa.accent)
                                        .frame(width: 38, height: 38).background(wa.accent.opacity(0.12), in: Circle())
                                    VStack(alignment: .leading, spacing: 1) {
                                        Text(verbatim: point.name ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                                        Text(verbatim: point.kindLabel ?? "").font(.dorr(12, .semibold)).foregroundStyle(wa.accent)
                                        if let i = point.instructions { Text(verbatim: i).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                                    }
                                    Spacer(minLength: 0)
                                    if let d = point.distanceM { Text(verbatim: L("rd_point_away", d)).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                                }
                                .padding(12)
                                .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                            }
                            .buttonStyle(WaPressStyle())
                            .padding(.bottom, 8)
                        }
                        if choices.required != true {
                            Button { state.decide(purpose, nil) } label: {
                                Text(L("rd_points_keep")).font(.dorr(15, .bold)).foregroundStyle(wa.accent).padding(.vertical, 12)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
                .frame(maxHeight: 520)
                .fixedSize(horizontal: false, vertical: true)
            }
        }
    }
}

/// On the ride: the pickup point (and the drop-off one) with how to find it.
struct PickupPointCard: View {
    @Environment(\.wa) private var wa
    let ride: RideDto

    var body: some View {
        if let p = ride.pickupPoint { line(L("rd_pickup_point"), p) }
        if let p = ride.dropoffPoint { line(L("rd_dropoff_point"), p) }
    }

    private func line(_ title: String, _ point: RidePickupPointDto) -> some View {
        HStack(spacing: 10) {
            Image(systemName: pointIcon(point.kind)).font(.system(size: 19)).foregroundStyle(wa.accent)
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: title).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                Text(verbatim: "\(point.name ?? "") · \(point.kindLabel ?? "")").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                if let i = point.instructions { Text(verbatim: i).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            }
            Spacer(minLength: 0)
        }
        .padding(12)
        .background(wa.accent.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        .padding(.top, 8)
    }
}
