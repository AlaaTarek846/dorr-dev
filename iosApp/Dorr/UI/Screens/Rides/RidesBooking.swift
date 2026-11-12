import CoreLocation
import SwiftUI

// androidApp `rides/RidesBooking.kt` + `RidesPickupUi.kt` (RouteCard, PickupPin): the first card, the place
// search, picking a point on the map, and the prices with the booking button.

extension RidesState {
    /// Ask the server to price the trip for every category open here (`reprice`: the same trip, e.g. a promo code).
    func loadQuotes(reprice: Bool = false) {
        guard let pickup else { return }
        step = .quotes
        // A promo code re-prices the list in place; a new trip starts from an empty one.
        if !reprice {
            quotes = []
            selected = nil
        }
        error = nil
        busy = true
        Task {
            // R-16..R-18: in an airport or other sensitive place, the rider picks an approved point first.
            if !reprice && feature("pickup_points"), await askForPoint(pickup, openDestination ? nil : destination) {
                busy = false
                return
            }
            var origin = pickup.json
            if let id = pickupPointId { origin["point_id"] = .int(id) }
            var body: [String: JSONValue] = ["origin": .object(origin)]
            if let d = destination, !openDestination {
                var dest = d.json
                if let id = dropoffPointId { dest["point_id"] = .int(id) }
                body["destination"] = .object(dest)
            }
            if !stops.isEmpty && !openDestination { body["stops"] = .array(stops.map { .object($0.json) }) }
            if let code = couponCode, !openDestination { body["coupon_code"] = .string(code) }
            // R-233..R-237, R-NEW-06: the add-ons chosen.
            if !addons.isEmpty { body["options"] = .object(addons.json) }
            // O-17: by the hour — only that category, priced hours × its hour price.
            if let h = hourlyHours {
                body["hours"] = .int(h)
                if let slug = hourlySlug { body["category"] = .string(slug) }
            }
            let keep = selected?.category?.slug
            do {
                let list = try await RidesApi.quotes(body).data ?? []
                quotes = list
                addonsApplied = addons
                // The same category stays chosen after a promo code re-prices the list.
                selected = list.first { $0.category?.slug == keep }
                    ?? preferCategory.flatMap { want in list.first { $0.category?.slug == want } }
                    ?? list.first
                riderPrice = selected?.bid?.suggestedMinor
                if selected?.category?.cashAllowed == false && payment == "cash" { payment = "wallet" }
            } catch {
                let failure = error.apiFailure
                if couponCode != nil && ((failure.errorCode ?? "").hasPrefix("rides_coupon_") || failure.errorCode == "rides_feature_disabled") {
                    // A refused code never costs the prices: say why under the field and price without it.
                    couponError = failure.message
                    couponCode = nil
                    busy = false
                    loadQuotes(reprice: true)
                    return
                }
                // An add-on nothing here can take: say why on the row, go back to the last good choice.
                if (failure.errorCode ?? "").hasPrefix("rides_addons_") && !addons.isEmpty {
                    addonsError = failure.message
                    addons = addonsApplied
                    busy = false
                    loadQuotes(reprice: true)
                    return
                }
                self.error = failure.message ?? L("rd_error")
            }
            busy = false
        }
    }

    /// A card ride's payment, on the app's one payment screen (purpose `ride`).
    func openRidePayment() {
        guard let uuid = ride?.uuid else { return }
        CheckoutLauncher.shared.open(CheckoutRequest(purpose: "ride", reference: ["ride_uuid": .string(uuid)])) { [weak self] in
            Task { if let fresh = try? await RidesApi.ride(uuid).data { self?.ride = fresh } }
        }
    }
}

// MARK: - The first card

/// O-51: from → to as one card: a route line on the side, the pickup's address on top (or «finding you…»
/// while it's worked out), and «Where to?» under it as the big target.
struct RouteCard: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    @State private var shimmer = false

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(L("pk_title")).font(.dorr(22, .black)).foregroundStyle(wa.settingsInk)
            HStack(alignment: .top, spacing: 12) {
                // The route line.
                VStack(spacing: 0) {
                    Circle().fill(AppColors.success.opacity(0.22)).frame(width: 14, height: 14)
                        .overlay(Circle().fill(AppColors.success).frame(width: 8, height: 8))
                    DashedLine().stroke(Color.gray.opacity(0.45), style: StrokeStyle(lineWidth: 2, dash: [4, 4])).frame(width: 2, height: 42)
                    RoundedRectangle(cornerRadius: 3).fill(AppColors.brandOrange).frame(width: 12, height: 12)
                }
                .padding(.top, 20)
                VStack(spacing: 0) {
                    // Pickup.
                    Button { state.editing = "pickup"; state.step = .search } label: {
                        HStack {
                            VStack(alignment: .leading, spacing: 0) {
                                Text(L("pk_pickup")).font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut)
                                if state.pickupLoading || state.pickup == nil {
                                    Text(L("pk_finding")).font(.dorr(15, .semibold)).foregroundStyle(wa.settingsMut.opacity(shimmer ? 0.9 : 0.35))
                                        .onAppear { withAnimation(.easeInOut(duration: 0.7).repeatForever()) { shimmer = true } }
                                } else {
                                    Text(verbatim: state.pickup?.address ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                                }
                            }
                            Spacer(minLength: 0)
                            Image(systemName: "pencil").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.accent)
                                .frame(width: 30, height: 30).background(wa.accent.opacity(0.1), in: Circle())
                        }
                        .padding(.vertical, 8).padding(.horizontal, 4)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(WaPressStyle())
                    Rectangle().fill(wa.settingsInk.opacity(0.07)).frame(height: 1)
                    // Where to?
                    Button { state.editing = "destination"; state.step = .search } label: {
                        HStack {
                            Text(L("rd_where_to")).font(.dorr(19, .black)).foregroundStyle(wa.settingsInk)
                            Spacer(minLength: 0)
                            Image(systemName: "magnifyingglass").font(.system(size: 17, weight: .bold)).foregroundStyle(.white)
                                .frame(width: 38, height: 38).background(wa.accent, in: Circle())
                        }
                        .padding(.vertical, 12).padding(.horizontal, 4)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(WaPressStyle())
                }
                .padding(.trailing, 10)
            }
            .padding(.leading, 16)
            .padding(.vertical, 6)
            .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
            .shadow(color: wa.accent.opacity(0.18), radius: 10, y: 3)
        }
    }
}

private struct DashedLine: Shape {
    func path(in rect: CGRect) -> Path {
        var p = Path()
        p.move(to: CGPoint(x: rect.midX, y: 4))
        p.addLine(to: CGPoint(x: rect.midX, y: rect.height - 4))
        return p
    }
}

/// O-51: the pin in the middle of the map — a drop with a soft pulse on the ground while it rests, lifted while
/// the map moves, and a «Pickup here» bubble once it lands.
struct PickupPin: View {
    let lifted: Bool
    let color: Color
    let label: String?
    @State private var pulse = false

    var body: some View {
        ZStack {
            if !lifted {
                Circle().fill(color.opacity(0.35)).frame(width: 46, height: 46)
                    .scaleEffect(x: pulse ? 1.4 : 0.4, y: (pulse ? 1.4 : 0.4) * 0.4)
                    .opacity(pulse ? 0 : 1)
                    .onAppear { withAnimation(.linear(duration: 1.6).repeatForever(autoreverses: false)) { pulse = true } }
                    .onDisappear { pulse = false }
            }
            Capsule().fill(Color.black.opacity(lifted ? 0.12 : 0.28)).frame(width: 12, height: 5)
            VStack(spacing: 0) {
                if !lifted, let label {
                    Text(verbatim: label).font(.dorr(12, .bold)).foregroundStyle(.white)
                        .padding(.horizontal, 12).padding(.vertical, 6)
                        .background(Color(hex: 0x111928), in: Capsule())
                        .shadow(radius: 6)
                        .padding(.bottom, 6)
                        .transition(.scale.combined(with: .opacity))
                }
                Circle().fill(color).frame(width: 38, height: 38)
                    .overlay(Circle().fill(.white).frame(width: 14, height: 14))
                    .shadow(color: .black.opacity(0.25), radius: 8, y: 3)
                UnevenRoundedRectangleCompat(topRadius: 0).fill(color).frame(width: 4, height: 18)
            }
            .offset(y: -30 + (lifted ? -26 : 0))
            .animation(.spring(response: 0.35, dampingFraction: 0.5), value: lifted)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .padding(.bottom, 260)
        .allowsHitTesting(false)
    }
}

/// The first card: where am I picked up, and "Where to?".
struct WhereToPanel: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let hasLocation: Bool
    let onAskLocation: () -> Void

    var body: some View {
        PanelCard {
            if !hasLocation {
                Button(action: onAskLocation) {
                    HStack(spacing: 8) {
                        Image(systemName: "location.fill").foregroundStyle(AppColors.brandOrange)
                        Text(L("rd_location_needed")).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                        Spacer(minLength: 0)
                        Text(L("rd_allow")).font(.dorr(14, .bold)).foregroundStyle(AppColors.brandOrange)
                    }
                    .padding(12)
                    .background(AppColors.brandOrange.opacity(0.1), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                .buttonStyle(WaPressStyle())
                .padding(.bottom, 10)
            }
            RouteCard(state: state)
            // Block 06: «دُرر يفهم مقصدك» — the trip in my own words (on a DORR AI plan).
            SmartDestinationEntry(state: state) { state.smartOpen = true }
            if state.feature("saved_places") { SavedPlacesRow(state: state) }
            if state.feature("scheduled") { WhenChips(state: state).padding(.top, 10) }
            if state.feature("open_destination") {
                Button {
                    state.openDestination = true
                    state.destination = nil
                    state.stops = []
                    state.loadQuotes()
                } label: {
                    HStack(spacing: 10) {
                        Image(systemName: "speedometer").font(.system(size: 17)).foregroundStyle(wa.accent)
                            .frame(width: 36, height: 36).background(wa.accent.opacity(0.1), in: Circle())
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("rd_no_destination")).font(.dorr(15, .semibold)).foregroundStyle(wa.settingsInk)
                            Text(L("rd_no_destination_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        }
                        Spacer(minLength: 0)
                    }
                    .padding(.vertical, 8)
                    .contentShape(Rectangle())
                }
                .buttonStyle(WaPressStyle())
                .padding(.top, 10)
            }
            if let error = state.error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger).padding(.top, 8) }
        }
    }
}

// MARK: - The place search

/// Typing a place: pickup, destination and stops, with the server's suggestions.
struct PlaceSearchPage: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    @ObservedObject var camera: DorrCamera
    @State private var query = ""
    @State private var results: [RidePoint] = []
    @State private var popular: [RidePoint] = []
    @State private var searching = false
    @FocusState private var focused: Bool
    private let maxStops = 3

    private var typed: Bool { query.trimmingCharacters(in: .whitespaces).count >= 2 }

    private func picked(_ point: RidePoint) {
        // R-5: the search is choosing Home or Work to keep, not a trip.
        if let type = state.savingPlace {
            state.savePlace(point, type: type)
            state.savingPlace = nil
            state.step = .map
            return
        }
        state.set(point)
        if state.destination != nil && state.pickup != nil { state.loadQuotes() } else { state.step = .map }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 10) {
                RideRoundButton(icon: "arrow.backward", label: L("common_back")) {
                    state.savingPlace = nil
                    state.step = state.quotes.isEmpty ? .map : .quotes
                }
                Text(L(state.savingPlace == "home" ? "rd_place_pick_home" : (state.savingPlace == "work" ? "rd_place_pick_work" : "rd_where_to")))
                    .font(.dorr(20, .heavy)).foregroundStyle(wa.settingsInk)
                Spacer()
            }
            .padding(12)
            if state.savingPlace == nil { fields }
            if state.savingPlace == nil && state.editing == "destination" {
                SmartDestinationEntry(state: state) { state.smartOpen = true }.padding(.horizontal, 16)
            }
            searchField
            resultList
        }
        .background(wa.settingsBackground.ignoresSafeArea())
        .task { popular = await popularPlaces(near: state.me ?? state.pickup?.latLng) }
        .task(id: query) {
            guard typed else { results = []; return }
            try? await Task.sleep(nanoseconds: 250_000_000)
            guard !Task.isCancelled else { return }
            searching = true
            results = await searchPlaces(query, near: state.me ?? state.pickup?.latLng)
            searching = false
        }
        .onChange(of: state.editing) { _ in query = ""; focused = true }
        .onAppear { focused = true }
    }

    private var fields: some View {
            VStack(spacing: 0) {
                FieldRow(dot: AppColors.brandNavy, label: L("rd_pickup"), value: state.pickup?.address, active: state.editing == "pickup") { state.editing = "pickup" }
                ForEach(state.stops.indices, id: \.self) { i in
                    FieldRow(dot: AppColors.warning, label: L("rd_stop", i + 1), value: state.stops[i].address, active: state.editing == "stop:\(i)",
                             onRemove: { state.stops.remove(at: i) }) { state.editing = "stop:\(i)" }
                }
                FieldRow(dot: AppColors.brandOrange, label: L("rd_destination"), value: state.destination?.address, active: state.editing == "destination") { state.editing = "destination" }
                if state.feature("multi_stop") && state.stops.count < maxStops && state.destination != nil {
                    Button { state.editing = "stop:\(state.stops.count)" } label: {
                        HStack(spacing: 4) {
                            Image(systemName: "plus").font(.system(size: 14, weight: .bold))
                            Text(L("rd_add_stop")).font(.dorr(13, .semibold))
                            Spacer()
                        }
                        .foregroundStyle(wa.accent)
                        .padding(.vertical, 6)
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(12)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .padding(.horizontal, 16)
    }

    private var searchField: some View {
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
            .padding(.top, 10)
    }

    private var resultList: some View {
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 0) {
                    Button {
                        state.step = .pickOnMap
                        let start: RidePoint? = state.editing == "pickup" ? state.pickup : (state.editing == "destination" ? state.destination : nil)
                        if let at = (start ?? state.pickup)?.latLng { camera.animate(to: at, zoom: 16) }
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
                    // O-57: nothing typed yet — the popular places around.
                    if !typed && !popular.isEmpty {
                        Text(L("rd_popular_near")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 10).padding(.bottom, 4)
                    }
                    ForEach(Array((typed ? results : popular).enumerated()), id: \.offset) { _, place in
                        Button { picked(place) } label: {
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
}

private struct FieldRow: View {
    @Environment(\.wa) private var wa
    let dot: Color
    let label: String
    let value: String?
    let active: Bool
    var onRemove: (() -> Void)?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 10) {
                Circle().fill(dot).frame(width: 10, height: 10)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: label).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: value ?? "—").font(.dorr(15, active ? .bold : .regular)).foregroundStyle(wa.settingsInk).lineLimit(1)
                }
                Spacer(minLength: 0)
                if let onRemove {
                    Button(action: onRemove) { Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut) }.buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 8).padding(.vertical, 8)
            .background(active ? wa.accent.opacity(0.08) : .clear, in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(WaPressStyle())
        .animation(.easeInOut(duration: 0.2), value: active)
    }
}

/// Picking a point by moving the map: the pin's point is set, then confirmed.
struct ConfirmPointPanel: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState

    private var current: RidePoint? {
        if state.editing == "pickup" { return state.pickup }
        if state.editing == "destination" { return state.destination }
        if let i = Int(state.editing.dropFirst(5)), state.stops.indices.contains(i) { return state.stops[i] }
        return nil
    }

    var body: some View {
        PanelCard {
            Text(L("rd_moving_pin")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            Text(verbatim: current?.address ?? "…").font(.dorr(16, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2)
            AccentButton(text: L("rd_confirm_point"), enabled: current != nil) {
                if state.pickup != nil && (state.destination != nil || state.openDestination) { state.loadQuotes() } else { state.step = .search }
            }
            .padding(.top, 14)
        }
    }
}

// MARK: - The prices

/// The prices: one card per category, how to pay, and the button.
struct QuotesPanel: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    @State private var suggestCard = false

    private func book() {
        guard let quote = state.selected else { return }
        if state.scheduledAt != nil && quote.scheduling == nil { return }
        state.busy = true
        state.error = nil
        suggestCard = false
        Task {
            var body: [String: JSONValue] = [
                "quote_uuid": .string(quote.uuid), "fingerprint": .string(quote.fingerprint), "payment_method": .string(state.payment),
                "passengers": .int(state.passengers),
            ]
            if state.payment == "cash" && state.useWallet && (quote.walletAvailableMinor ?? 0) > 0 { body["use_wallet"] = .bool(true) }
            if quote.category?.slug == "with_me" { body["share_with"] = .string(state.shareWith) }
            if let bid = quote.bid { body["rider_price_minor"] = .int(state.riderPrice ?? bid.suggestedMinor ?? 0) }
            if let at = state.scheduledAt {
                let f = ISO8601DateFormatter()
                f.timeZone = .current
                body["scheduled_at"] = .string(f.string(from: at))
            }
            let note = state.pickupNote.trimmingCharacters(in: .whitespacesAndNewlines)
            if !note.isEmpty { body["pickup_note"] = .string(note) }
            // A quiet ride for this trip (the captain sees it on the request).
            if let quiet = state.quietRide { body["quiet"] = .bool(quiet) }
            // R-INN-07: the favourite captains first.
            if let circle = state.circleBody { body["circle"] = .object(circle) }
            if state.forOther {
                body["beneficiary"] = .object(["name": .string(state.beneficiaryName.trimmingCharacters(in: .whitespaces)),
                                               "phone": .string(state.beneficiaryPhone.trimmingCharacters(in: .whitespaces)),
                                               "consent": .bool(state.beneficiaryConsent)])
            }
            do {
                let ride = try await RidesApi.book(body, key: RidesApi.key()).data
                state.ride = ride
                // Card: the app's one payment screen; once paid the server holds it and searches.
                if ride?.status == "awaiting_payment" { state.openRidePayment() }
            } catch {
                let f = error.apiFailure
                if f.errorCode == "rides_insufficient_balance" && state.feature("card") { suggestCard = true }
                if f.errorCode == "rides_quote_expired" || f.errorCode == "rides_quote_changed" { state.loadQuotes() }
                state.error = f.message ?? L("rd_error")
            }
            state.busy = false
        }
    }

    var body: some View {
        if state.pointsChooser != nil {
            PickupPointsPanel(state: state)
        } else {
            quotesCard
        }
    }

    private var quotesCard: some View {
        PanelCard {
            ScrollView(showsIndicators: false) {
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("rd_choose_ride")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).padding(.bottom, 10)
                    if state.feature("scheduled") && state.quotes.contains(where: { $0.scheduling != nil }) {
                        WhenChips(state: state).padding(.bottom, 8)
                    }
                    if state.busy && state.quotes.isEmpty {
                        ProgressView().tint(wa.accent).frame(maxWidth: .infinity, minHeight: 90)
                    }
                    ForEach(Array(state.quotes.enumerated()), id: \.element.uuid) { index, quote in
                        QuoteCard(quote: quote, selected: quote.uuid == state.selected?.uuid, suggested: quote.category?.slug == state.suggestion?.category) {
                            state.selected = quote
                            state.riderPrice = quote.bid?.suggestedMinor
                        }
                        .staggerIn(index)
                    }
                    if let why = state.suggestion?.why { Text(verbatim: "✨ " + why).font(.dorr(11)).foregroundStyle(wa.settingsMut).padding(.top, 4) }
                    if let quote = state.selected, let bid = quote.bid {
                        BidPriceEditor(price: state.riderPrice ?? bid.suggestedMinor ?? 0, floor: bid.floorMinor ?? 0, suggested: bid.suggestedMinor ?? 0,
                                       step: bid.stepMinor ?? 100, currency: quote.currency) { state.riderPrice = $0 }
                        // AI-05: why the suggestion moved from the rate card.
                        if let why = bid.smart?.why { Text(verbatim: why).font(.dorr(12, .semibold)).foregroundStyle(AppColors.brandOrange).padding(.top, 4) }
                    }
                    // O-17: motorcycles (and any category the admin opens by the hour): by the distance or by the hour.
                    if let quote = state.selected, quote.hourlyOffer != nil { HourlyRow(state: state, quote: quote) }
                    if let quote = state.selected { selectedPart(quote) } else if let error = state.error {
                        Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger)
                    }
                }
            }
            .frame(maxHeight: 560)
            .fixedSize(horizontal: false, vertical: true)
        }
        // AI-09: the category I usually take for a trip like this (on a DORR AI plan).
        .task(id: state.quotes.map(\.uuid).joined()) { await state.loadSuggestion() }
    }

    @ViewBuilder
    private func selectedPart(_ quote: RideQuoteDto) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            // O-30..O-33: With Me — who may share the car with me, and what sharing means.
            if quote.category?.slug == "with_me" { ShareWithRow(state: state) }
            // O-37, O-38a, O-38b: one row — how I pay and the coupon — opening a sheet to change either.
            PaymentRow(state: state, quote: quote)
            // R-233..R-237, R-NEW-06: Wait & Save / priority, child seats, a pet, a service animal, bags.
            AddonsRow(state: state, quote: quote)
            // R-5, R-174: keep this destination as Home, Work or a favourite.
            if state.feature("saved_places") { SaveDestinationRow(state: state) }
            // D-60, D-61: how many ride and a note for the captain.
            TripDetailsRow(state: state)
            // R-13: the ride is for someone else.
            // «راحتي»: how I like the trip — requests, not guarantees.
            ComfortRow(state: state)
            if state.feature("book_for_other") { BookForOtherRow(state: state) }
            // R-INN-07: ask my favourite captains first.
            if state.feature("favorite_circle") { CircleRow(state: state) }
            // Wireflow م11 / م12: every line of the price, and the rules it comes with.
            PriceDetails(quote: quote, method: state.payment)
            PolicySummary(quote: quote, method: state.payment)
            // R-179: the terms in force for this booking.
            TermsNotice(quote: quote)
            if let error = state.error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger).padding(.top, 8) }
            if suggestCard {
                Button { state.payment = "card"; book() } label: {
                    Text(L("rd_use_card")).font(.dorr(14, .bold)).foregroundStyle(wa.accent)
                }
                .buttonStyle(.plain)
                .padding(.top, 6)
            }
            if let at = state.scheduledAt {
                Text(verbatim: quote.scheduling.map { L("rd_scheduled_free_until", rideWhenText(at.addingTimeInterval(-TimeInterval($0.freeCancelBeforeS ?? 3600)))) } ?? L("rd_schedule_not_for_bid"))
                    .font(.dorr(12, .semibold)).foregroundStyle(quote.scheduling == nil ? AppColors.warning : AppColors.success).padding(.top, 8)
            }
            AccentButton(text: state.scheduledAt.map { L("rd_confirm_scheduled", quote.category?.name ?? "", rideWhenText($0)) } ?? L("rd_confirm", quote.category?.name ?? ""), enabled: (state.scheduledAt == nil || quote.scheduling != nil) && state.beneficiaryReady(),
                         busy: state.busy) { book() }
                .padding(.top, 12)
        }
        .padding(.top, 8)
    }
}

private struct QuoteCard: View {
    @Environment(\.wa) private var wa
    let quote: RideQuoteDto
    let selected: Bool
    var suggested = false
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 12) {
                Image(systemName: "car.fill").font(.system(size: 22)).foregroundStyle(wa.accent)
                    .scaleEffect(selected ? 1.1 : 1)
                    .frame(width: 48, height: 48)
                    .background(wa.accent.opacity(selected ? 0.16 : 0.1), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: quote.category?.name ?? quote.category?.slug ?? "").font(.dorr(16, .bold)).foregroundStyle(wa.settingsInk)
                    if suggested { SuggestedTag() }
                    let sub = [quote.route?.durationS.map { minutesText($0, L("rd_minutes"), L("rd_hours")) },
                               quote.route?.distanceM.map { L("rd_km", String(format: "%.1f", Double($0) / 1000)) },
                               quote.category?.capacity.map { L("rd_seats", $0) }].compactMap { $0 }.joined(separator: " · ")
                    if !sub.isEmpty { Text(verbatim: sub).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    if let tagline = quote.category?.tagline { Text(verbatim: tagline).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                }
                Spacer(minLength: 0)
                VStack(alignment: .trailing, spacing: 1) { price }
            }
            .padding(12)
            .background(selected ? wa.accent.opacity(0.06) : .clear, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(selected ? wa.accent : wa.settingsInk.opacity(0.08), lineWidth: selected ? 2 : 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(WaPressStyle())
        .padding(.vertical, 4)
        .animation(.spring(response: 0.3, dampingFraction: 0.6), value: selected)
    }

    @ViewBuilder
    private var price: some View {
        let currency = quote.currency
        if let bid = quote.bid {
            Text(verbatim: rideMoney(bid.suggestedMinor, currency)).font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk)
            Text(L("rd_bid_name_price")).font(.dorr(11, .bold)).foregroundStyle(AppColors.brandOrange)
        } else if let total = quote.totalMinor {
            StruckPrice(quote: quote)
            Text(verbatim: rideMoney(total, currency)).font(.dorr(17, .heavy)).foregroundStyle(quote.coupon?.applied == true ? AppColors.success : wa.settingsInk)
            Text(L("rd_fixed_price")).font(.dorr(11)).foregroundStyle(AppColors.success)
        } else if let e = quote.estimate {
            Text(verbatim: "\(rideMoney(e.minMinor, currency))–\(rideMoney(e.maxMinor, currency))").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
            Text(L("rd_estimate")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
        } else {
            Text(L("rd_rate_card")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
        }
    }
}
