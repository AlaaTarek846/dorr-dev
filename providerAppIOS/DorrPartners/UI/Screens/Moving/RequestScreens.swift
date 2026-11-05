// providerApp `ui/screens/RequestScreens.kt`
import SwiftUI

/// The requests that reached my branches (C-04, `requests.view`), newest first, page by page: the neighbourhoods only (the server
/// never sends the exact address before the job is ours), distance, time, load, workers, extras, vehicle type, photos, and «عرضي» when
/// the establishment already answered. Read on open, when the connection returns and on every notice (a new request is one) — never
/// on a timer.
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
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
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

/// One request in a list: the route, when, what, and the pills.
struct RequestCard: View {
    @Environment(\.wa) private var wa
    let move: MovingRequestDto
    var statusPill = false
    let onClick: () -> Void

    var body: some View {
        Card {
            Button(action: onClick) {
                VStack(alignment: .leading, spacing: 6) {
                    HStack {
                        Text(verbatim: routeText(move)).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                        if let offer = move.myOffer { StatusPill(text: L("mv_my_offer"), color: offerStatusColor(offer.status)) }
                        if statusPill { StatusPill(text: moveStatusLabel(move.status), color: moveStatusColor(move.status)) }
                    }
                    Text(verbatim: dateTimeText(move.scheduledAt)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    if !facts.isEmpty {
                        Text(verbatim: facts.joined(separator: " · ")).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk)
                    }
                    ExtrasRow(move: move)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
        }
    }

    private var facts: [String] {
        var out: [String] = []
        if let km = move.distanceKm { out.append(L("mv_distance", formatKm(km))) }
        if let load = notBlank(loadTypeLabel(move.loadType)) { out.append(load) }
        if let workers = move.workersCount, workers > 0 { out.append(L("mv_workers_n", workers)) }
        if let vehicle = move.vehicleType?.name { out.append(vehicle) }
        if let photos = move.photos?.count, photos > 0 { out.append(L("mv_photos_n", photos)) }
        return out
    }
}

/// The details of a request (and of a job, which is the same request once ours) shown on its page.
struct RequestDetails: View {
    @Environment(\.wa) private var wa
    let move: MovingRequestDto

    var body: some View {
        Card {
            HStack {
                Text(verbatim: routeText(move)).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                StatusPill(text: moveStatusLabel(move.status), color: moveStatusColor(move.status))
            }
            InfoLine(L("mv_when"), dateTimeText(move.scheduledAt))
            InfoLine(L("mv_distance_label"), move.distanceKm.map { L("mv_distance", formatKm($0)) })
            InfoLine(L("mv_load"), loadTypeLabel(move.loadType))
            InfoLine(L("mv_pieces"), move.piecesCount?.text)
            InfoLine(L("mv_dimensions"), move.dimensions)
            InfoLine(L("mv_workers"), move.workersCount.map { String($0) })
            InfoLine(L("mv_vehicle_type"), move.vehicleType?.name)
            InfoLine(L("mv_deadline"), dateTimeText(move.offersDeadlineAt))
            ExtrasRow(move: move)
            if let description = notBlank(move.description) {
                Text(verbatim: description).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(4)
            }
            if let notes = notBlank(move.notes) {
                Text(L("mv_notes_line", notes)).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            }
        }
        EndCard(title: L("mv_pickup"), end: move.pickup)
        EndCard(title: L("mv_dropoff"), end: move.dropoff)
        if let photos = move.photos, !photos.isEmpty {
            FieldLabel(text: L("mv_photos")).frame(maxWidth: .infinity, alignment: .leading)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(photos) { photo in
                        MediaThumb(url: photo.url)
                            .frame(width: 110, height: 110)
                            .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                }
            }
        }
    }
}

/// One end of the move: the neighbourhood, floor and lift; the exact address only once the job is ours (the server decides).
private struct EndCard: View {
    @Environment(\.wa) private var wa
    let title: String
    let end: MovingEndDto?

    var body: some View {
        if let end {
            Card {
                Text(verbatim: title).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                InfoLine(L("mv_area"), end.area)
                InfoLine(L("mv_floor"), end.floor?.text)
                InfoLine(L("mv_elevator"), end.elevator.map { L($0 ? "common_yes" : "mv_no") })
                InfoLine(L("mv_address"), end.address)
                InfoLine(L("mv_entrance"), end.entrance)
                if end.address == nil { Text(L("mv_address_hidden")).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineSpacing(2) }
            }
        }
    }
}

/// A request's page: its details, and «إرسال عرض» for a seat with `offers.send`. The move's own live channel reloads it once on each
/// change while the page is open (no timer).
@MainActor
struct RequestScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var realtime = Realtime.shared
    let uuid: String
    let canOffer: Bool
    let onBack: () -> Void

    @State private var move: MovingRequestDto?
    @State private var error: String?
    @State private var offering = false
    @State private var sentMessage: String?

    var body: some View {
        ZStack {
            if offering, let current = move {
                OfferForm(move: current, offer: nil, onBack: { offering = false }, onDone: { message in
                    sentMessage = message
                    offering = false
                })
            } else {
                Page(title: L("mv_request_title"), onBack: onBack) {
                    if let current = move {
                        RequestDetails(move: current)
                        if let sentMessage {
                            Text(verbatim: sentMessage).font(.dorr(13, .semibold)).foregroundStyle(Green).frame(maxWidth: .infinity, alignment: .leading)
                        }
                        if canOffer && current.status == "open" { SettingsPrimaryButton(text: L("mv_send_offer")) { offering = true } }
                    } else if error == nil {
                        ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
                    }
                }
                .messageToast(error)
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
}

private let offerIncludeKeys = ["packing", "disassembly", "crane", "technician", "tax"]

/// Sending an offer on `move` (`offer` nil) or changing one: the vehicle (approved ones only — the others say why), the price, workers
/// and trips, what it includes, arrival and how long it holds (new offers only: the server keeps them), terms and the promise after
/// the move. The server's refusal is shown as it is (e.g. «لا يمكن إرسال عرض: …»). An offer above the sender's limit goes to the
/// approver first. `onDone` gets the sentence to show.
@MainActor
struct OfferForm: View {
    @Environment(\.wa) private var wa
    let move: MovingRequestDto?
    let offer: MovingOfferDto?
    let onBack: () -> Void
    let onDone: (String) -> Void

    @State private var vehicles: [VehicleDto]?
    @State private var vehicleUuid: String?
    @State private var price: String
    @State private var workers: String
    @State private var trips: String
    @State private var includes: [String]
    @State private var technicianNote: String
    @State private var arrivalDate: String
    @State private var arrivalTime: String
    @State private var validDate = ""
    @State private var validTime = ""
    @State private var terms: String
    @State private var afterService: String
    @State private var busy = false
    @State private var error: String?

    init(move: MovingRequestDto?, offer: MovingOfferDto?, onBack: @escaping () -> Void, onDone: @escaping (String) -> Void) {
        self.move = move
        self.offer = offer
        self.onBack = onBack
        self.onDone = onDone
        _price = State(initialValue: offer?.priceMinor.map { formatMinor($0, nil).replacingOccurrences(of: "\u{200E}", with: "") } ?? "")
        _workers = State(initialValue: (offer?.workersCount ?? move?.workersCount).map { String($0) } ?? "")
        _trips = State(initialValue: String(offer?.tripsCount ?? 1))
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
        let scheduled = localDateAndTime(move?.scheduledAt)
        _arrivalDate = State(initialValue: scheduled?.date ?? "")
        _arrivalTime = State(initialValue: scheduled?.time ?? "")
        _terms = State(initialValue: offer?.terms ?? "")
        _afterService = State(initialValue: offer?.afterService ?? "")
    }

    private var creating: Bool { offer == nil }
    private var currency: String? { offer?.currencyCode ?? move?.currencyCode }
    private var priceMinor: Int? { parseMinor(price) }
    private var workersValue: Int? { Int(workers) }
    private var tripsValue: Int? { Int(trips) }
    private var arrivalIso: String? { isoAt(arrivalDate, arrivalTime) }
    private var validIso: String? { isoAt(validDate, validTime) }
    private var ready: Bool {
        guard priceMinor != nil else { return false }
        if !workers.trimmingCharacters(in: .whitespaces).isEmpty {
            guard let value = workersValue, (0...50).contains(value) else { return false }
        }
        guard let tripCount = tripsValue, (1...50).contains(tripCount) else { return false }
        return !creating || (vehicleUuid != nil && arrivalIso != nil && validIso != nil)
    }

    var body: some View {
        let title = L(creating ? "mv_send_offer" : "mv_edit_offer")
        Page(title: title, onBack: onBack) {
            FormIntro(icon: "doc.text.fill", title: title, text: L("mv_offer_form_body"))
            vehicleSection
            FormGroup {
                DorrTextField(value: $price.profileMap(decimalInput(12)), label: L("mv_offer_price", currency ?? ""), icon: "banknote.fill",
                              keyboard: .decimalPad, error: !price.trimmingCharacters(in: .whitespaces).isEmpty && priceMinor == nil)
                HStack(alignment: .top, spacing: 10) {
                    DorrTextField(value: $workers.profileDigits(2), label: L("mv_workers"), icon: "person.3.fill", keyboard: .numberPad)
                    DorrTextField(value: $trips.profileDigits(2), label: L("mv_trips"), icon: "repeat", keyboard: .numberPad)
                }
            }
            includesSection
            timesSection
            FormGroup {
                DorrTextField(value: $terms.profileTake(3000), label: L("mv_terms"), placeholder: L("onb_optional"), icon: "text.alignleft", singleLine: false, minLines: 3)
                DorrTextField(value: $afterService.profileTake(2000), label: L("mv_after_service"), placeholder: L("mv_after_service_hint"), icon: "box.truck.fill", singleLine: false, minLines: 2)
            }
            SettingsPrimaryButton(text: L(creating ? "mv_send_offer" : "common_save"), enabled: ready, loading: busy, action: send)
        }
        .messageToast(error)
        .task {
            guard let list = await ManageApi.vehicles().value else { return }
            vehicles = list
            if vehicleUuid == nil && creating {
                // The approved vehicle of the requested type first, else any approved one.
                vehicleUuid = (list.first(where: { $0.status == "approved" && $0.vehicleTypeId == move?.vehicleTypeId })
                    ?? list.first(where: { $0.status == "approved" }))?.uuid
            }
        }
    }

    // The vehicle: only an approved one can carry an offer; the others say why not.
    @ViewBuilder private var vehicleSection: some View {
        FieldLabel(text: L("mv_offer_vehicle")).frame(maxWidth: .infinity, alignment: .leading)
        if let list = vehicles {
            if list.isEmpty {
                Text(L("mv_no_vehicles")).font(.dorr(13)).foregroundStyle(Red).frame(maxWidth: .infinity, alignment: .leading)
            } else {
                Card {
                    ForEach(list) { vehicle in vehicleRow(vehicle) }
                    if !creating { Text(L("mv_vehicle_keep")).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
                }
            }
        } else {
            ProgressView().tint(wa.red).frame(width: 24, height: 24)
        }
    }

    private func vehicleRow(_ vehicle: VehicleDto) -> some View {
        let usable = vehicle.status == "approved"
        let wanted = move?.vehicleTypeId
        return Button { vehicleUuid = vehicle.uuid } label: {
            HStack(spacing: 8) {
                Image(systemName: vehicleUuid == vehicle.uuid ? "largecircle.fill.circle" : "circle").font(.system(size: 18))
                    .foregroundStyle(usable ? wa.red : wa.settingsMut.opacity(0.4)).frame(width: 22, height: 22)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: (vehicle.vehicleType?.name ?? L("vh_vehicle")) + " · \u{200E}" + vehicle.plate())
                        .font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk.opacity(usable ? 1 : 0.5))
                    if !usable {
                        Text(L("mv_vehicle_unusable", vehicleStatusLabel(vehicle.status))).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    } else if let wanted, vehicle.vehicleTypeId != wanted {
                        Text(L("mv_vehicle_other_type")).font(.dorr(11)).foregroundStyle(Amber)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(.vertical, 4)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!usable)
    }

    @ViewBuilder private var includesSection: some View {
        FieldLabel(text: L("mv_offer_includes")).frame(maxWidth: .infinity, alignment: .leading)
        Card {
            ForEach(offerIncludeKeys, id: \.self) { key in
                let on = includes.contains(key)
                Button {
                    if let i = includes.firstIndex(of: key) { includes.remove(at: i) } else { includes.append(key) }
                } label: {
                    HStack(spacing: 8) {
                        Image(systemName: on ? "checkmark.square.fill" : "square").font(.system(size: 18)).foregroundStyle(on ? wa.red : wa.settingsMut).frame(width: 22, height: 22)
                        Text(verbatim: includeLabel(key)).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .padding(.vertical, 2)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
            if includes.contains("technician") {
                DorrTextField(value: $technicianNote.profileTake(255), label: L("mv_technician_note"), placeholder: L("onb_optional"))
            }
        }
    }

    @ViewBuilder private var timesSection: some View {
        if creating {
            FieldLabel(text: L("mv_arrival")).frame(maxWidth: .infinity, alignment: .leading)
            HStack(alignment: .top, spacing: 10) {
                DateField(value: $arrivalDate, label: L("mv_date"))
                TimeField(value: $arrivalTime, label: L("mv_time"))
            }
            FieldLabel(text: L("mv_valid_until")).frame(maxWidth: .infinity, alignment: .leading)
            HStack(alignment: .top, spacing: 10) {
                DateField(value: $validDate, label: L("mv_date"))
                TimeField(value: $validTime, label: L("mv_time"))
            }
            Text(L("mv_valid_until_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineSpacing(2).frame(maxWidth: .infinity, alignment: .leading)
        } else {
            Card {
                InfoLine(L("mv_arrival"), dateTimeText(offer?.arrivalAt))
                InfoLine(L("mv_valid_until"), dateTimeText(offer?.validUntil))
            }
        }
    }

    private func send() {
        busy = true
        error = nil
        // `jsonBody`: an emptied note is sent as null, so it is cleared; a missing key would keep the old one.
        var body: JsonBody = ["price_minor": priceMinor, "trips_count": tripsValue]
        if let vehicleUuid { body["vehicle_uuid"] = vehicleUuid }
        if let workersValue { body["workers_count"] = workersValue }
        var ticked: [String: Bool] = [:]
        offerIncludeKeys.forEach { ticked[$0] = includes.contains($0) }
        body["includes"] = ticked
        body.updateValue(trimmedOrNil(technicianNote), forKey: "technician_note")
        if creating {
            body["arrival_at"] = arrivalIso
            body["valid_until"] = validIso
        }
        body.updateValue(trimmedOrNil(terms), forKey: "terms")
        body.updateValue(trimmedOrNil(afterService), forKey: "after_service")
        let sent = body
        Task {
            let result: ApiResult<MovingOfferDto>
            if let offer { result = await MovingApi.updateOffer(offer.uuid, sent) } else { result = await MovingApi.sendOffer(move?.uuid ?? "", sent) }
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
