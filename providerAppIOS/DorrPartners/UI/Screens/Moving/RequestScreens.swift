// providerApp `ui/screens/RequestScreens.kt`
import SwiftUI

/// The requests that reached my branches (C-04, `requests.view`), newest first, page by page: the route between the two
/// neighbourhoods (the server never sends the exact address before the job is ours), the time, distance and when offers close, the
/// vehicle types the customer accepts, and «عرضي» with its price when the establishment already answered. Read on open, when the
/// connection returns and on every notice (a new request is one) — never on a timer.
@MainActor
struct RequestsScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpenRequest: (String) -> Void
    @StateObject private var paged = PagedRows<MovingRequestDto>(
        perPage: 20, key: { $0.uuid }, fetch: { page in await MovingApi.requests(page: page) }
    )

    var body: some View {
        Page(title: L("mv_requests_title"), onBack: onBack, onNearEnd: { Task { await paged.loadMore() } }) {
            if !paged.loaded {
                SkeletonList(count: 3)
            } else if paged.rows.isEmpty {
                EmptyState(icon: "shippingbox.fill", title: L("mv_requests_empty_title"), body: L("mv_requests_empty_body")).padding(.top, 30)
            } else {
                ForEach(paged.rows) { move in RequestCard(move: move) { onOpenRequest(move.uuid) } }
            }
            PagedFooter(paged: paged) { Task { await paged.loadMore() } }
        }
        .messageToast(paged.failure?.errorText(L("login_error_offline")))
        .task(id: network.reconnectTick) { await paged.reload() }
        .onReceive(Realtime.shared.changed) { _ in Task { await paged.reload() } }
    }
}

/// One request in a list: the route card, then what is moved, the vehicle types asked, the extras — and my offer's price.
struct RequestCard: View {
    @Environment(\.wa) private var wa
    let move: MovingRequestDto
    var statusPill = false
    let onClick: () -> Void

    var body: some View {
        MvRouteCard(move: move, compact: true, onClick: onClick, badge: {
            VStack(alignment: .trailing, spacing: 4) {
                if statusPill { StatusPill(text: moveStatusLabel(move.status), color: moveStatusColor(move.status)) }
                if let mine = move.myOffer {
                    StatusPill(text: L("mv_my_offer") + " · " + offerStatusLabel(mine.status), color: offerStatusColor(mine.status))
                    if let price = mine.priceMinor, price > 0 {
                        Text(verbatim: formatMinor(price, move.currencyCode)).font(.dorr(13, .black)).foregroundStyle(wa.settingsInk)
                    }
                }
            }
        }, footer: {
            Rectangle().fill(wa.settingsMut.opacity(0.15)).frame(height: 1)
            FlowLayout(spacing: 6) {
                if let load = notBlank(loadTypeLabel(move.loadType)) { MvChip(icon: "shippingbox.fill", text: load, color: Slate) }
                ForEach(move.askedTypes()) { type in if let name = type.name { MvChip(icon: "box.truck.fill", text: name) } }
                if let workers = move.workersCount, workers > 0 { MvChip(icon: "person.3.fill", text: L("mv_workers_n", workers), color: Slate) }
                if let photos = move.photos?.count, photos > 0 { MvChip(icon: "photo.on.rectangle", text: L("mv_photos_n", photos), color: Slate) }
            }
            ExtrasRow(move: move)
        })
    }
}

/// The details of a request (and of a job, which is the same request once ours) shown on its page.
struct RequestDetails: View {
    @Environment(\.wa) private var wa
    let move: MovingRequestDto

    var body: some View {
        MvRouteCard(move: move, badge: { StatusPill(text: moveStatusLabel(move.status), color: moveStatusColor(move.status)) })

        let types = move.askedTypes()
        if !types.isEmpty {
            MvSection(icon: "box.truck.fill", title: L("mv_sec_vehicles_asked"), hint: L(types.count > 1 ? "mv_sec_vehicles_asked_many" : "mv_sec_vehicles_asked_one"))
            MvAskedTypes(types: types).frame(maxWidth: .infinity, alignment: .leading)
        }

        MvSection(icon: "shippingbox.fill", title: L("mv_sec_load"))
        MvFactGrid(facts: facts)
        if let description = notBlank(move.description) {
            Text(verbatim: description).font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(5)
                .padding(14).frame(maxWidth: .infinity, alignment: .leading).settingsSurface(18)
        }
        if let voice = move.voice, voice.url != nil { MvCustomerVoice(voice: voice) }
        MvPhotos(urls: (move.photos ?? []).compactMap { $0.url })
        if move.extras?.packing == true || move.extras?.disassembly == true || move.extras?.crane == true || move.extras?.technician == true {
            Text(L("mv_sec_extras")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
            ExtrasRow(move: move).frame(maxWidth: .infinity, alignment: .leading)
        }

        MvSection(icon: "mappin.and.ellipse", title: L("mv_sec_places"))
        MvEndsCard(move: move)
        if let notes = notBlank(move.notes) {
            HStack(alignment: .top, spacing: 8) {
                Image(systemName: "text.alignleft").font(.system(size: 15)).foregroundStyle(Amber)
                Text(L("mv_notes_line", notes)).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(12)
            .background(Amber.opacity(0.10), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
    }

    private var facts: [(icon: String, label: String, value: String)] {
        var out: [(icon: String, label: String, value: String)] = []
        if let load = notBlank(loadTypeLabel(move.loadType)) { out.append(("square.grid.2x2.fill", L("mv_load"), load)) }
        if let pieces = notBlank(move.piecesCount?.text) { out.append(("shippingbox.fill", L("mv_pieces"), pieces)) }
        if let workers = move.workersCount, workers > 0 { out.append(("person.3.fill", L("mv_workers"), String(workers))) }
        if let dimensions = notBlank(move.dimensions) { out.append(("ruler", L("mv_dimensions"), dimensions)) }
        return out
    }
}

/// A request's page: its details; then my offer (changed or taken back while the customer hasn't chosen) or «قدّم عرضك» for a seat
/// with `offers.send`. The move's own live channel reloads it once on each change while the page is open (no timer).
@MainActor
struct RequestScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var realtime = Realtime.shared
    @ObservedObject private var session = Session.shared
    let uuid: String
    let canOffer: Bool
    let onBack: () -> Void

    @State private var move: MovingRequestDto?
    @State private var error: String?
    @State private var offering = false
    @State private var editing = false
    @State private var withdrawing = false
    @State private var busy = false
    @State private var sentMessage: String?

    private var mine: MovingOfferDto? {
        guard let offer = move?.myOffer, ["sent", "awaiting_approval", "accepted"].contains(offer.status ?? "") else { return nil }
        return offer
    }

    var body: some View {
        ZStack {
            if offering || editing, let current = move {
                OfferForm(move: current, offer: editing ? mine : nil, onBack: { offering = false; editing = false }, onDone: { message in
                    withAnimation { sentMessage = message }
                    offering = false
                    editing = false
                    Task { await reload() }
                })
            } else {
                Page(title: L("mv_request_title"), onBack: onBack) {
                    if let current = move {
                        MvDoneBanner(text: sentMessage)
                        if let mine {
                            MvMyOfferCard(offer: mine, canManage: session.membership.can("offers.manage"), busy: busy,
                                          onEdit: { editing = true }, onWithdraw: { withdrawing = true })
                        }
                        RequestDetails(move: current)
                        if mine == nil && canOffer && current.status == "open" {
                            SettingsPrimaryButton(text: L("mv_make_offer")) { sentMessage = nil; offering = true }.padding(.top, 4)
                            Text(L("mv_make_offer_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2).frame(maxWidth: .infinity, alignment: .leading)
                        }
                    } else if error == nil {
                        SkeletonList(count: 3)
                    }
                }
                .messageToast(error)
                .dorrDialog(isPresented: Binding(get: { withdrawing }, set: { if !$0 && !busy { withdrawing = false } })) {
                    if let mine {
                        ConfirmDialog(icon: "arrow.uturn.backward", title: L("mv_withdraw_title"), message: L("mv_withdraw_message"), loading: busy,
                                      confirmLabel: L("mv_withdraw"), onConfirm: { withdraw(mine) }, onDismiss: { if !busy { withdrawing = false } })
                    }
                }
            }
        }
        .task(id: network.reconnectTick) { await reload() }
        .task(id: "\(uuid)-\(realtime.connected)") {
            for await event in realtime.watchMove(uuid) where event.name == "moving.changed" { await reload() }
        }
    }

    private func reload() async {
        let result = await MovingApi.request(uuid)
        if let data = result.value {
            move = data
            error = nil
        } else {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    private func withdraw(_ offer: MovingOfferDto) {
        busy = true
        Task {
            let failed = await MovingApi.withdrawOffer(offer.uuid, offline: L("login_error_offline"), generic: L("login_error_generic"))
            busy = false
            withdrawing = false
            if let failed {
                error = failed
            } else {
                withAnimation { sentMessage = L("mv_offer_withdrawn_ok") }
                await reload()
            }
        }
    }
}

private let offerIncludeKeys = ["packing", "disassembly", "crane", "technician"]

/// Sending an offer on `move` (`offer` nil) or changing one, in clear blocks: the vehicle (my vehicles, the ones of a type the
/// customer asked for first; the others say why they can't), the price with what the customer pays and what reaches the
/// establishment, the team (workers and trips with − and +), what it includes (what the customer asked for is ticked already), the
/// arrival (the asked time, or another) and how long it holds (until offers close, or another time), and — folded — terms and the
/// promise after the move. With `move` nil (changing from «العروض»), the request is read first. The server's refusal is shown as it
/// is; an offer above the sender's limit goes to the approver first. `onDone` gets the sentence to show.
@MainActor
struct OfferForm: View {
    @Environment(\.wa) private var wa
    let move: MovingRequestDto?
    let offer: MovingOfferDto?
    let onBack: () -> Void
    let onDone: (String) -> Void

    @State private var request: MovingRequestDto?
    @State private var vehicleUuid: String?
    @State private var price: String
    @State private var workers: Int
    @State private var trips: Int
    @State private var includes: [String]
    @State private var technicianNote: String
    @State private var arrivalAsAsked = true
    @State private var arrivalDate: String
    @State private var arrivalTime: String
    @State private var validToDeadline = true
    @State private var validDate: String
    @State private var validTime: String
    @State private var terms: String
    @State private var afterService: String
    @State private var moreOpen: Bool
    @State private var busy = false
    @State private var error: String?

    init(move: MovingRequestDto?, offer: MovingOfferDto?, onBack: @escaping () -> Void, onDone: @escaping (String) -> Void) {
        self.move = move
        self.offer = offer
        self.onBack = onBack
        self.onDone = onDone
        _request = State(initialValue: move)
        _price = State(initialValue: offer?.priceMinor.flatMap { $0 > 0 ? formatMinor($0, nil).replacingOccurrences(of: "\u{200E}", with: "") : nil } ?? "")
        _workers = State(initialValue: offer?.workersCount ?? move?.workersCount.flatMap { $0 > 0 ? $0 : nil } ?? 2)
        _trips = State(initialValue: offer?.tripsCount ?? 1)
        var ticked: [String] = []
        if let offer {
            ticked = Array(offer.included())
        } else if let extras = move?.extras {
            if extras.packing == true { ticked.append("packing") }
            if extras.disassembly == true { ticked.append("disassembly") }
            if extras.crane == true { ticked.append("crane") }
            if extras.technician == true { ticked.append("technician") }
        }
        _includes = State(initialValue: ticked)
        _technicianNote = State(initialValue: offer?.technicianNote ?? "")
        let asked = localDateAndTime(move?.scheduledAt)
        _arrivalDate = State(initialValue: asked?.date ?? "")
        _arrivalTime = State(initialValue: asked?.time ?? "")
        let deadline = localDateAndTime(move?.offersDeadlineAt ?? move?.scheduledAt)
        _validDate = State(initialValue: deadline?.date ?? "")
        _validTime = State(initialValue: deadline?.time ?? "")
        _terms = State(initialValue: offer?.terms ?? "")
        _afterService = State(initialValue: offer?.afterService ?? "")
        _moreOpen = State(initialValue: notBlank(offer?.terms) != nil || notBlank(offer?.afterService) != nil)
    }

    private var creating: Bool { offer == nil }
    private var currency: String? { offer?.currencyCode ?? request?.currencyCode }
    private var priceMinor: Int? { parseMinor(price) }
    private var deadline: String? { move?.offersDeadlineAt ?? move?.scheduledAt }
    private var arrivalIso: String? { arrivalAsAsked ? move?.scheduledAt : isoAt(arrivalDate, arrivalTime) }
    private var validIso: String? { validToDeadline ? deadline : isoAt(validDate, validTime) }
    private var ready: Bool { priceMinor != nil && (!creating || (vehicleUuid != nil && arrivalIso != nil && validIso != nil)) }

    var body: some View {
        Page(title: L(creating ? "mv_make_offer" : "mv_edit_offer"), onBack: onBack) {
            if let req = request {
                MvRouteCard(move: req, compact: true, footer: { MvAskedTypes(types: req.askedTypes()) })
                vehicleSection(req)
                priceSection(req)
                teamSection(req)
                includesSection(req)
                timesSection
                moreSection
                SettingsPrimaryButton(text: L(creating ? "mv_send_offer" : "common_save"), enabled: ready, loading: busy, action: send)
                if creating {
                    Text(L("mv_offer_form_body")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2).frame(maxWidth: .infinity, alignment: .leading)
                }
            } else {
                SkeletonList(count: 3)
            }
        }
        .messageToast(error)
        .task {
            if request == nil, let uuid = offer?.requestUuid, let data = await MovingApi.request(uuid).value { request = data }
            // A new offer starts on the first vehicle that can answer (the server lists those first).
            if creating && vehicleUuid == nil { vehicleUuid = request?.myVehicles?.first(where: { $0.usable })?.uuid }
        }
    }

    // 1 · The vehicle.
    @ViewBuilder private func vehicleSection(_ req: MovingRequestDto) -> some View {
        MvSection(icon: "box.truck.fill", title: L("mv_sec_offer_vehicle"), hint: L(creating ? "mv_sec_offer_vehicle_hint" : "mv_vehicle_keep"))
        let vehicles = req.myVehicles ?? []
        if vehicles.isEmpty {
            MvWarning(text: L("mv_no_vehicles"))
        } else if !vehicles.contains(where: { $0.usable }) {
            MvWarning(text: L("mv_no_fitting_vehicle", req.askedTypes().compactMap { $0.name }.joined(separator: " / ")))
        }
        ForEach(vehicles) { vehicle in
            MvVehicleOption(vehicle: vehicle, selected: vehicleUuid == vehicle.uuid) {
                vehicleUuid = (!creating && vehicleUuid == vehicle.uuid) ? nil : vehicle.uuid
            }
        }
    }

    // 2 · The price, and what it means for both sides.
    @ViewBuilder private func priceSection(_ req: MovingRequestDto) -> some View {
        MvSection(icon: "banknote.fill", title: L("mv_sec_price"), hint: L("mv_sec_price_hint"))
        DorrTextField(value: $price.profileMap(decimalInput(12)), label: L("mv_offer_price", currency ?? ""), icon: "banknote.fill",
                      keyboard: .decimalPad, error: !price.trimmingCharacters(in: .whitespaces).isEmpty && priceMinor == nil, minHeight: 56)
        if let pricing = req.pricing, let value = priceMinor {
            MvPriceBreakdown(b: pricing.breakdown(value), currency: currency).transition(.opacity.combined(with: .move(edge: .top)))
        }
    }

    // 3 · The team.
    @ViewBuilder private func teamSection(_ req: MovingRequestDto) -> some View {
        MvSection(icon: "person.3.fill", title: L("mv_sec_team"))
        MvStepper(icon: "person.3.fill", label: L("mv_workers"), value: $workers, range: 0...50,
                  hint: req.workersCount.flatMap { $0 > 0 ? L("mv_customer_asked_n", $0) : nil })
        MvStepper(icon: "repeat", label: L("mv_trips"), value: $trips, range: 1...20, hint: L("mv_trips_hint"))
    }

    // 4 · What it includes.
    @ViewBuilder private func includesSection(_ req: MovingRequestDto) -> some View {
        MvSection(icon: "checklist", title: L("mv_sec_includes"), hint: L("mv_sec_includes_hint"))
        LazyVGrid(columns: [GridItem(.flexible(), spacing: 8), GridItem(.flexible(), spacing: 8)], spacing: 8) {
            ForEach(offerIncludeKeys, id: \.self) { key in
                MvIncludeTile(key: key, on: includes.contains(key), asked: askedFor(key, req)) {
                    if let i = includes.firstIndex(of: key) { includes.remove(at: i) } else { includes.append(key) }
                }
            }
        }
        if includes.contains("technician") {
            DorrTextField(value: $technicianNote.profileTake(255), label: L("mv_technician_note"), placeholder: L("onb_optional"))
        }
    }

    private func askedFor(_ key: String, _ req: MovingRequestDto) -> Bool {
        switch key {
        case "packing": return req.extras?.packing == true
        case "disassembly": return req.extras?.disassembly == true
        case "crane": return req.extras?.crane == true
        default: return req.extras?.technician == true
        }
    }

    // 5 · When the truck arrives, and 6 · how long the offer holds (new offers only: the server keeps them).
    @ViewBuilder private var timesSection: some View {
        if creating {
            MvSection(icon: "clock.fill", title: L("mv_sec_arrival"))
            MvChoice(first: L("mv_arrival_as_asked", dateTimeText(move?.scheduledAt)), second: L("mv_other_time"), firstOn: $arrivalAsAsked)
            if !arrivalAsAsked {
                HStack(alignment: .top, spacing: 10) {
                    DateField(value: $arrivalDate, label: L("mv_date"))
                    TimeField(value: $arrivalTime, label: L("mv_time"))
                }
            }
            MvSection(icon: "timer", title: L("mv_sec_validity"), hint: L("mv_valid_until_hint"))
            MvChoice(first: L("mv_valid_to_deadline", dateTimeText(deadline)), second: L("mv_other_time"), firstOn: $validToDeadline)
            if !validToDeadline {
                HStack(alignment: .top, spacing: 10) {
                    DateField(value: $validDate, label: L("mv_date"))
                    TimeField(value: $validTime, label: L("mv_time"))
                }
            }
        } else {
            VStack(alignment: .leading, spacing: 6) {
                InfoLine(L("mv_arrival"), dateTimeText(offer?.arrivalAt))
                InfoLine(L("mv_valid_until"), dateTimeText(offer?.validUntil))
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .settingsSurface(18)
        }
    }

    // 7 · Terms and the promise after the move, folded.
    @ViewBuilder private var moreSection: some View {
        Button { withAnimation(.easeOut(duration: 0.2)) { moreOpen.toggle() } } label: {
            HStack(spacing: 8) {
                Image(systemName: "text.alignleft").font(.system(size: 16)).foregroundStyle(wa.red)
                Text(L("mv_more_details")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                Image(systemName: moreOpen ? "chevron.up" : "chevron.down").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut)
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 12)
            .background(wa.mvWell, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        if moreOpen {
            DorrTextField(value: $terms.profileTake(3000), label: L("mv_terms"), placeholder: L("mv_terms_hint"), icon: "text.alignleft", singleLine: false, minLines: 3)
            DorrTextField(value: $afterService.profileTake(2000), label: L("mv_after_service"), placeholder: L("mv_after_service_hint"), icon: "box.truck.fill", singleLine: false, minLines: 2)
        }
    }

    private func send() {
        busy = true
        error = nil
        // `jsonBody`: an emptied note is sent as null, so it is cleared; a missing key would keep the old one.
        var body: JsonBody = ["price_minor": priceMinor, "trips_count": trips, "workers_count": workers]
        if let vehicleUuid { body["vehicle_uuid"] = vehicleUuid }
        // The tax is always added on top by the server: it isn't something an offer includes or not.
        var ticked: [String: Bool] = [:]
        offerIncludeKeys.forEach { ticked[$0] = includes.contains($0) }
        body["includes"] = ticked
        body.updateValue(includes.contains("technician") ? trimmedOrNil(technicianNote) : nil, forKey: "technician_note")
        if creating {
            body["arrival_at"] = arrivalIso
            body["valid_until"] = validIso
        }
        body.updateValue(trimmedOrNil(terms), forKey: "terms")
        body.updateValue(trimmedOrNil(afterService), forKey: "after_service")
        let sent = body
        Task {
            let result: ApiResult<MovingOfferDto>
            if let offer { result = await MovingApi.updateOffer(offer.uuid, sent) } else { result = await MovingApi.sendOffer(request?.uuid ?? "", sent) }
            busy = false
            if let answer = result.value {
                if offer != nil {
                    onDone(L("mv_offer_updated"))
                } else {
                    onDone(L(answer.status == "awaiting_approval" ? "mv_offer_to_approver" : "mv_offer_sent_ok"))
                }
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}
