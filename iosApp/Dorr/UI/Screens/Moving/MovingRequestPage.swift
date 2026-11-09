import CoreLocation
import SwiftUI
import UIKit

// androidApp `moving/MovingRequestPage.kt` — the request page, the hub of everything after publishing: it switches by
// status. Waiting for offers: how many came, and taking it back. The job: the timeline, the driver and the plate, the
// delivery code and, once the driver moves, the rides map with the truck. After delivery: the objection time on the
// device clock, «received intact», the report and the rating. Changes, a new time, cancelling, the invoice and the
// tickets are one tap away from here.

/// The order the customer follows; anything else is a side state.
private let timeline = ["confirmed", "assigned", "driver_on_way", "arrived", "loading", "in_transit", "unloading", "delivered", "completed"]
private let mapStatuses: Set<String> = ["driver_on_way", "arrived", "loading", "in_transit", "unloading"]
private let afterAccept: Set<String> = ["confirmed", "assigned", "driver_on_way", "arrived", "loading", "in_transit", "unloading", "delivered", "completed", "disputed", "cancelled"]
private let canCancel: Set<String> = ["confirmed", "assigned", "driver_on_way", "arrived"]
private let exceptionOnly: Set<String> = ["loading", "in_transit", "unloading"]
private let canRate: Set<String> = ["delivered", "completed", "disputed"]
private let hasInvoice: Set<String> = ["confirmed", "assigned", "driver_on_way", "arrived", "loading", "in_transit", "unloading", "delivered", "completed", "disputed", "cancelled"]

private let riyadh = CLLocationCoordinate2D(latitude: 24.7136, longitude: 46.6753)

private func latLng(_ end: MovingEndDto?) -> CLLocationCoordinate2D? {
    guard let lat = end?.lat, let lng = end?.lng else { return nil }
    return CLLocationCoordinate2D(latitude: lat, longitude: lng)
}

@MainActor
struct MovingRequestPage: View {
    @Environment(\.wa) private var wa
    let uuid: String
    @ObservedObject var nav: MovingNav
    @StateObject private var camera = DorrCamera(start: riyadh, zoom: 13)
    /// The road between the two ends: asked once per pair (a reload of the page doesn't ask again).
    @StateObject private var route = MovingRouteState()
    @State private var show: MovingShowDto?
    @State private var changes: [MovingChangeDto] = []
    @State private var loadError: String?
    @State private var truck: CLLocationCoordinate2D?
    @State private var heading: Double = 0
    @State private var cancelOpen = false
    @State private var withdrawOpen = false

    private func reload() async {
        do {
            guard let fresh = try await MovingApi.show(uuid).data else { return }
            show = fresh
            loadError = nil
            if let lat = fresh.driverLocation?.lat, let lng = fresh.driverLocation?.lng { truck = CLLocationCoordinate2D(latitude: lat, longitude: lng) }
            if afterAccept.contains(fresh.request.status) {
                if let list = try? await MovingApi.changes(uuid).data { changes = list }
            }
        } catch {
            if show == nil { loadError = error.apiFailure.message ?? L("rd_error") }
        }
    }

    var body: some View {
        content
            .movingLive(uuid, onChanged: { await reload() }, onDriver: { lat, lng, h in
                truck = CLLocationCoordinate2D(latitude: lat, longitude: lng)
                if let h { heading = h }
            })
            .movingRoute(route, from: latLng(show?.request.pickup), to: latLng(show?.request.dropoff))
            // A draft continues in the steps.
            .onChange(of: show?.request.status) { status in if status == "draft" { nav.replace(.wizard(uuid: uuid, atReview: false)) } }
            .sheet(isPresented: $cancelOpen) {
                if let current = show {
                    MovingCancelSheet(show: current, onClose: { cancelOpen = false }) { message in
                        cancelOpen = false
                        if let message, !message.isEmpty { AppToast.show(message, long: true) }
                        Task { await reload() }
                    }
                    .dorrThemed().presentationDetents([.large])
                }
            }
            .sheet(isPresented: $withdrawOpen) {
                MovingWithdrawSheet(uuid: uuid, title: L("mv_withdraw_title"), body: L("mv_withdraw_body"), action: L("mv_withdraw"),
                                    onClose: { withdrawOpen = false },
                                    onDone: { message in
                                        withdrawOpen = false
                                        if let message, !message.isEmpty { AppToast.show(message, long: true) }
                                        Task { await reload() }
                                    })
                    .dorrThemed().presentationDetents([.medium, .large])
            }
    }

    @ViewBuilder
    private var content: some View {
        if let current = show {
            if mapStatuses.contains(current.request.status) {
                ZStack {
                    wa.settingsBackground.ignoresSafeArea()
                    JobMap(request: current.request, truck: truck, heading: heading, camera: camera, polyline: route.route?.polyline)
                    VStack {
                        HStack {
                            RideRoundButton(icon: "arrow.backward", label: L("common_back")) { nav.pop() }
                            Spacer()
                        }
                        .padding(16)
                        Spacer()
                    }
                    VStack {
                        Spacer()
                        PanelCard {
                            ScrollView(showsIndicators: false) {
                                VStack(alignment: .leading, spacing: 12) { sections(current) }
                                    .frame(maxWidth: .infinity, alignment: .leading)
                            }
                            .frame(maxHeight: UIScreen.main.bounds.height * 0.6)
                            .fixedSize(horizontal: false, vertical: true)
                            .environment(\.mvOnPanel, true)
                        }
                    }
                    .ignoresSafeArea(edges: .bottom)
                }
            } else {
                MvPage(title: L("mv_request_title"), onBack: { nav.pop() }) {
                    MvScroll { sections(current) }
                }
            }
        } else {
            MvPage(title: L("mv_request_title"), onBack: { nav.pop() }) {
                if let loadError { MvEmpty(icon: "exclamationmark.triangle.fill", text: loadError) } else { MvLoading() }
            }
        }
    }

    /// The cards of the page, in the order the customer needs them for this status.
    @ViewBuilder
    private func sections(_ show: MovingShowDto) -> some View {
        let r = show.request
        let status = r.status
        let pending = changes.first { $0.status == "pending" && $0.proposedBy == "provider" }
        let unpaid = changes.first { $0.status == "awaiting_payment" }
        Group {
            StatusHeader(r: r)
            // On the live map the road is drawn already: its distance and time stay one glance away.
            if mapStatuses.contains(status) {
                MovingRouteChips(state: route, bothEnds: latLng(r.pickup) != nil && latLng(r.dropoff) != nil, fallbackKm: r.distanceKm)
            }
            switch status {
            case "open": OpenCard(show: show, nav: nav) { await reload() }
            case "expired": MvNote(text: L("mv_expired_note"), color: AppColors.warning)
            case "withdrawn": MvNote(text: L("mv_withdrawn_note"))
            case "cancelled": MvNote(text: L("mv_cancelled_note"), color: AppColors.danger)
            default: EmptyView()
            }
            if timeline.contains(status) || status == "disputed" { TimelineCard(status: status) }
            if pending != nil || unpaid != nil {
                Button { nav.push(.changes(r.uuid)) } label: {
                    HStack(spacing: 8) {
                        Image(systemName: "exclamationmark.triangle.fill").font(.system(size: 17)).foregroundStyle(AppColors.warning)
                        Text(L(unpaid != nil ? "mv_change_pay_banner" : "mv_change_banner")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                        Spacer(minLength: 0)
                    }
                    .padding(12)
                    .background(AppColors.warning.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                .buttonStyle(WaPressStyle())
            }
            if r.driver != nil || r.vehicle != nil { DriverCard(r: r) }
            if let code = show.deliveryCode { DeliveryCodeCard(code: code, qr: show.deliveryQr) }
        }
        Group {
            if status == "delivered" { DeliveredCard(show: show, nav: nav) { await reload() } }
            CasesCard(show: show)
            if canRate.contains(status) && !show.rated { RatingCard(r: r) { await reload() } }
            if r.money != nil { MoneyCard(r: r) }
            if !mapStatuses.contains(status) { RequestSummary(r: r, route: route) }
            Actions(show: show, nav: nav, onCancel: { cancelOpen = true }, onWithdraw: { withdrawOpen = true })
        }
    }
}

/// The rides map: the two ends joined by the road (a straight line until it is known), and the truck sliding as its
/// place arrives.
private struct JobMap: View {
    @Environment(\.wa) private var wa
    let request: MovingRequestDto
    let truck: CLLocationCoordinate2D?
    let heading: Double
    @ObservedObject var camera: DorrCamera
    let polyline: String?

    var body: some View { map }

    private var map: some View {
        let pickup = latLng(request.pickup)
        let dropoff = latLng(request.dropoff)
        var pins: [MapPin] = []
        if let pickup { pins.append(MapPin(at: pickup, kind: .pickup)) }
        if let dropoff { pins.append(MapPin(at: dropoff, kind: .dropoff)) }
        if let truck { pins.append(MapPin(at: truck, kind: .car, rotation: heading, id: "moving-truck")) }
        let road = decodePolyline(polyline)
        let route = road.count > 1 ? road : [pickup, dropoff].compactMap { $0 }
        return DorrMap(camera: camera, pins: pins, lines: route.count > 1 ? [MapLine(points: route, color: MapColors.route, width: 5)] : [],
                       padding: EdgeInsets(top: 0, leading: 0, bottom: 300, trailing: 0), night: wa.night)
            .ignoresSafeArea()
            .task(id: "\(route.count)|\(truck != nil)") {
                camera.fit(route + [truck].compactMap { $0 }, padding: 48)
            }
    }
}

private struct StatusHeader: View {
    @Environment(\.wa) private var wa
    let r: MovingRequestDto

    var body: some View {
        let (label, color) = movingStatusLook(r.status, wa)
        HStack(spacing: 8) {
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: loadTypeLabel(r.loadType)).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                if let t = mvTime(r.scheduledAt) { Text(verbatim: rideWhenText(t)).font(.dorr(13)).foregroundStyle(wa.settingsMut) }
            }
            Spacer(minLength: 0)
            MvChip(text: label, color: color)
        }
    }
}

/// Waiting for offers: how many came, how long is left, and taking the request back.
private struct OpenCard: View {
    @Environment(\.wa) private var wa
    let show: MovingShowDto
    @ObservedObject var nav: MovingNav
    let onReload: () async -> Void

    var body: some View {
        let r = show.request
        MvCard {
            Text(verbatim: show.offersCount > 0 ? offersCountText(show.offersCount) : L("mv_offers_waiting"))
                .font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
            MvTicking { now in
                if let left = mvSecondsLeft(r.offersDeadlineAt, now: now), left > 0 {
                    Text(verbatim: L("mv_offers_left", countdownText(left))).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.top, 2)
                }
            }
            if show.offersCount > 0 {
                AccentButton(text: L("mv_see_offers"), icon: "tag.fill") { nav.push(.offers(r.uuid, decimals: r.currencyDecimals)) }
                    .padding(.top, 12)
            }
        }
        .mvCountdownEnd(r.offersDeadlineAt, onEnd: onReload)
    }
}

/// The status as steps: done with a tick, the current one pulsing, the rest waiting.
private struct TimelineCard: View {
    @Environment(\.wa) private var wa
    let status: String
    @State private var pulse = false

    var body: some View {
        let at = status == "disputed" ? (timeline.firstIndex(of: "delivered") ?? 0) : (timeline.firstIndex(of: status) ?? -1)
        MvCard {
            MvCardTitle(text: L("mv_timeline")).padding(.bottom, 10)
            ForEach(Array(timeline.enumerated()), id: \.offset) { i, step in
                let done = i < at
                let now = i == at
                HStack(alignment: .top, spacing: 10) {
                    VStack(spacing: 0) {
                        ZStack {
                            Circle().fill(done ? AppColors.success : (now ? wa.accent : wa.settingsInk.opacity(0.10)))
                            if done { Image(systemName: "checkmark").font(.system(size: 10, weight: .bold)).foregroundStyle(.white) }
                            if now { Circle().fill(.white).frame(width: 7, height: 7) }
                        }
                        .frame(width: 20, height: 20)
                        .opacity(now ? (pulse ? 1 : 0.55) : 1)
                        if i < timeline.count - 1 {
                            Rectangle().fill(done ? AppColors.success : wa.settingsInk.opacity(0.10)).frame(width: 2, height: 16)
                        }
                    }
                    Text(verbatim: movingStatusLook(step, wa).0).font(.dorr(13.5, now ? .heavy : .medium))
                        .foregroundStyle(done || now ? wa.settingsInk : wa.settingsMut)
                }
            }
        }
        .onAppear { withAnimation(.easeInOut(duration: 0.8).repeatForever()) { pulse = true } }
    }
}

/// Who is coming and in what: the driver's name and photo, the vehicle and the drawn plate (the rides captain card).
private struct DriverCard: View {
    @Environment(\.wa) private var wa
    let r: MovingRequestDto

    var body: some View {
        let driver = r.driver
        let vehicle = r.vehicle
        MvCard {
            HStack(spacing: 12) {
                ZStack {
                    Circle().fill(wa.accent.opacity(0.12))
                    if let url = ApiClient.mediaUrl(driver?.avatar) {
                        AsyncImage(url: url) { $0.resizable().scaledToFill() } placeholder: { Color.clear }.clipShape(Circle())
                    } else {
                        Text(verbatim: String((driver?.name ?? "").prefix(1))).font(.dorr(20, .bold)).foregroundStyle(wa.accent)
                    }
                }
                .frame(width: 54, height: 54)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: driver?.name ?? L("mv_driver_soon")).font(.dorr(16, .bold)).foregroundStyle(wa.settingsInk)
                    if let name = r.establishment?.name { Text(verbatim: name).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                    if let v = vehicle {
                        Text(verbatim: [v.make, v.model, v.color].compactMap { $0 }.joined(separator: " ")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                }
                Spacer(minLength: 0)
                if let v = vehicle { Plate(letters: v.plateLetters, numbers: v.plateNumber, colorHex: nil) }
            }
        }
    }
}

/// The code at the door: big, one digit a box, with its QR. Only the customer sees it.
private struct DeliveryCodeCard: View {
    @Environment(\.wa) private var wa
    let code: String
    let qr: String?

    var body: some View {
        MvCard {
            MvCardTitle(text: L("mv_code_title"))
            Text(L("mv_code_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 2)
            HStack(spacing: 8) {
                ForEach(Array(code.enumerated()), id: \.offset) { _, digit in
                    Text(verbatim: String(digit)).font(.dorr(32, .black)).foregroundStyle(wa.settingsInk)
                        .frame(width: 52, height: 64)
                        .background(wa.accent.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
            }
            .environment(\.layoutDirection, .leftToRight)
            .frame(maxWidth: .infinity)
            .padding(.top, 12)
            if let qr, let matrix = QrMatrix(text: qr) {
                QrCanvas(matrix: matrix)
                    .frame(width: 164, height: 164)
                    .padding(8)
                    .background(Color.white, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .frame(maxWidth: .infinity)
                    .padding(.top, 12)
            }
        }
    }
}

/// After delivery: how long is left to object, «received intact», and the report.
private struct DeliveredCard: View {
    @Environment(\.wa) private var wa
    let show: MovingShowDto
    @ObservedObject var nav: MovingNav
    let onReload: () async -> Void
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        let r = show.request
        let openReport = show.cases.contains { $0.kind == "report" && $0.status == "open" }
        MvCard {
            MvCardTitle(text: L("mv_delivered_title"))
            Text(L("mv_delivered_hint")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).padding(.top, 2)
            MvTicking { now in
                let left = mvSecondsLeft(show.objectionUntil, now: now)
                VStack(alignment: .leading, spacing: 0) {
                    if let left {
                        if left > 0 {
                            Text(verbatim: L("mv_objection_left", countdownText(left))).font(.dorr(14, .bold)).foregroundStyle(wa.accent).padding(.top, 8)
                        } else {
                            Text(L("mv_objection_over")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.top, 8)
                        }
                    }
                    AccentButton(text: L("mv_received_intact"), busy: busy, color: AppColors.success, icon: "checkmark.circle.fill") { confirm(r.uuid) }
                        .padding(.top, 12)
                    if !openReport && (left == nil || (left ?? 0) > 0) {
                        MvOutlineButton(text: L("mv_report_open"), color: AppColors.danger) {
                            nav.push(.report(r.uuid, currency: r.currencyCode, decimals: r.currencyDecimals))
                        }
                        .padding(.top, 8)
                    }
                }
            }
            if let error { MvError(text: error).padding(.top, 8) }
        }
        .mvCountdownEnd(show.objectionUntil, onEnd: onReload)
    }

    private func confirm(_ uuid: String) {
        busy = true
        error = nil
        Task {
            do {
                let envelope = try await MovingApi.confirm(uuid)
                if let message = envelope.message, !message.isEmpty { AppToast.show(message, long: true) }
                await onReload()
            } catch {
                self.error = error.apiFailure.message ?? L("rd_error")
            }
            busy = false
        }
    }
}

/// A report or an exception in progress, and what was decided.
private struct CasesCard: View {
    @Environment(\.wa) private var wa
    let show: MovingShowDto

    var body: some View {
        if !show.cases.isEmpty {
            MvCard {
                ForEach(Array(show.cases.enumerated()), id: \.offset) { i, c in
                    VStack(alignment: .leading, spacing: 0) {
                        HStack {
                            Text(L(c.kind == "exception" ? "mv_case_exception" : "mv_case_report")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                            Spacer(minLength: 0)
                            let isOpen = c.status == "open"
                            MvChip(text: L(isOpen ? "mv_case_open" : "mv_case_decided"), color: isOpen ? AppColors.warning : AppColors.success)
                        }
                        if c.status != "open", let decision = c.decision {
                            Text(verbatim: caseDecision(decision)).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).padding(.top, 4)
                        }
                        if c.kind == "report", (c.awardMinor ?? 0) > 0 {
                            Text(verbatim: L("mv_case_award", mvMoney(c.awardMinor, show.request.currencyCode, show.request.currencyDecimals)))
                                .font(.dorr(13, .semibold)).foregroundStyle(AppColors.success).padding(.top, 4)
                        }
                    }
                    .padding(.top, i > 0 ? 10 : 0)
                }
            }
        }
    }

    private func caseDecision(_ decision: String) -> String {
        switch decision {
        case "accepted": return L("mv_decision_accepted")
        case "partial": return L("mv_decision_partial")
        case "rejected": return L("mv_decision_rejected")
        default: return decision
        }
    }
}

/// Stars for the establishment and the driver. Once rated (`rated` in the show) the page no longer draws this card.
private struct RatingCard: View {
    @Environment(\.wa) private var wa
    let r: MovingRequestDto
    let onReload: () async -> Void
    @State private var establishment = 0
    @State private var driver = 0
    @State private var comment = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        MvCard {
            MvCardTitle(text: L("mv_rate_title")).padding(.bottom, 8)
            Text(verbatim: r.establishment?.name ?? L("mv_rate_establishment")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
            MvStars(value: establishment) { establishment = $0 }
            if let d = r.driver {
                Text(verbatim: d.name ?? L("mv_rate_driver")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.top, 6)
                MvStars(value: driver) { driver = $0 }
            }
            if establishment > 0 {
                DorrTextArea(value: Binding(get: { comment }, set: { comment = String($0.prefix(1000)) }), placeholder: L("rd_rate_comment"), minHeight: 70)
                    .padding(.top, 8)
                AccentButton(text: L("rd_rate_send"), busy: busy) { send() }.padding(.top, 10)
            }
            if let error { MvError(text: error).padding(.top, 6) }
        }
        .animation(.easeInOut(duration: 0.25), value: establishment > 0)
    }

    private func send() {
        busy = true
        error = nil
        Task {
            var body: [String: JSONValue] = ["establishment_stars": .int(establishment)]
            if driver > 0 { body["driver_stars"] = .int(driver) }
            let text = comment.trimmingCharacters(in: .whitespacesAndNewlines)
            if !text.isEmpty { body["comment"] = .string(text) }
            do {
                let envelope = try await MovingApi.rate(r.uuid, body)
                if let message = envelope.message, !message.isEmpty { AppToast.show(message) }
                await onReload()
            } catch {
                let failure = error.apiFailure
                if failure.errorCode == "moving_already_rated" { await onReload() } else { self.error = failure.message ?? L("rd_error") }
            }
            busy = false
        }
    }
}

private struct MoneyCard: View {
    @Environment(\.wa) private var wa
    let r: MovingRequestDto

    var body: some View {
        if let money = r.money {
            let code = r.currencyCode
            let places = r.currencyDecimals
            MvCard {
                if let p = money.priceMinor { MvLine(label: L("mv_price"), value: mvMoney(p, code, places)) }
                if let t = money.taxMinor, t > 0 { MvLine(label: L("mv_tax"), value: mvMoney(t, code, places)) }
                if let total = money.totalMinor { MvLine(label: L("mv_total"), value: mvMoney(total, code, places), bold: true) }
                if money.paymentStatus == "held" { Text(L("mv_money_held")).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 4) }
            }
        }
    }
}

/// What was asked for.
private struct RequestSummary: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let r: MovingRequestDto
    @ObservedObject var route: MovingRouteState

    var body: some View {
        let from = latLng(r.pickup)
        let to = latLng(r.dropoff)
        MvCard {
            MvCardTitle(text: L("mv_summary")).padding(.bottom, 10)
            if from != nil || to != nil {
                MovingRouteMap(pickup: from, dropoff: to, route: route.route)
                    .frame(maxWidth: .infinity)
                    .frame(height: 170)
                MovingRouteChips(state: route, bothEnds: from != nil && to != nil, fallbackKm: r.distanceKm, top: 10, bottom: 8)
            }
            if let d = r.description, !d.trimmingCharacters(in: .whitespaces).isEmpty {
                Text(verbatim: d).font(.dorr(14)).foregroundStyle(wa.settingsInk).padding(.bottom, 6)
            }
            if !r.photos.isEmpty {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(r.photos) { p in
                            RemoteImage(url: p.url, placeholder: "photo").frame(width: 84, height: 84)
                                .background(wa.mvInset(onPanel))
                                .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                    }
                }
                .padding(.bottom, 8)
            }
            SummaryEnd(dot: AppColors.brandNavy, end: r.pickup)
            SummaryEnd(dot: AppColors.brandOrange, end: r.dropoff)
            Group {
                if let n = r.piecesCount { MvLine(label: L("mv_pieces"), value: String(n)) }
                if let d = r.dimensions, !d.trimmingCharacters(in: .whitespaces).isEmpty { MvLine(label: L("mv_dimensions"), value: d) }
                if let name = r.vehicleType?.name { MvLine(label: L("mv_vehicle_title"), value: name) }
                if let w = r.workersCount { MvLine(label: L("mv_workers"), value: String(w)) }
            }
            let e = r.extras
            let chosen = [
                e?.packing == true ? "packing" : nil,
                e?.disassembly == true ? "disassembly" : nil,
                e?.crane == true ? "crane" : nil,
                e?.technician == true ? "technician" : nil,
            ].compactMap { $0 }
            if !chosen.isEmpty {
                FlowLayout(spacing: 6, lineSpacing: 6) {
                    ForEach(chosen, id: \.self) { MvChip(text: extraLabel($0), color: wa.accent) }
                }
                .padding(.top, 4)
            }
        }
    }
}

private struct SummaryEnd: View {
    @Environment(\.wa) private var wa
    let dot: Color
    let end: MovingEndDto?

    var body: some View {
        TripLine(dot: dot, address: end?.address ?? end?.area)
        let details = [
            end?.area.flatMap { $0 != end?.address ? $0 : nil },
            end?.floor.map { L("mv_floor_value", String($0)) },
            end.map { L($0.elevator == true ? "mv_has_elevator" : "mv_no_elevator") },
            end?.entrance.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 },
        ].compactMap { $0 }
        if !details.isEmpty {
            Text(verbatim: details.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                .padding(.leading, 19).padding(.bottom, 4)
        }
    }
}

/// One tap away: changes, the inventory, the invoice, the tickets, and leaving.
private struct Actions: View {
    @ObservedObject var nav: MovingNav
    let show: MovingShowDto
    let onCancel: () -> Void
    let onWithdraw: () -> Void

    init(show: MovingShowDto, nav: MovingNav, onCancel: @escaping () -> Void, onWithdraw: @escaping () -> Void) {
        self.show = show
        _nav = ObservedObject(wrappedValue: nav)
        self.onCancel = onCancel
        self.onWithdraw = onWithdraw
    }

    var body: some View {
        let r = show.request
        let status = r.status
        VStack(spacing: 8) {
            if afterAccept.contains(status) && status != "cancelled" {
                MvAction(icon: "arrow.triangle.2.circlepath", title: L("mv_changes_title")) { nav.push(.changes(r.uuid)) }
            }
            if !show.inventory.isEmpty {
                MvAction(icon: "shippingbox.fill", title: L("mv_inventory_title"), sub: L("mv_inventory_count", show.inventory.count)) {
                    nav.push(.inventory(r.uuid, items: show.inventory))
                }
            }
            if hasInvoice.contains(status) {
                MvAction(icon: "doc.plaintext.fill", title: L("mv_invoice_title")) { nav.push(.invoice(r.uuid, decimals: r.currencyDecimals)) }
            }
            MvAction(icon: "headphones", title: L("mv_tickets_title")) { nav.push(.tickets(r.uuid)) }
            if status == "expired" || status == "withdrawn" { RepublishButton(uuid: r.uuid, nav: nav) }
            if status == "open" { MvOutlineButton(text: L("mv_withdraw"), color: AppColors.danger, action: onWithdraw) }
            if canCancel.contains(status) { MvOutlineButton(text: L("mv_cancel"), color: AppColors.danger, action: onCancel) }
            if exceptionOnly.contains(status) { MvOutlineButton(text: L("mv_exception"), color: AppColors.danger, action: onCancel) }
        }
    }
}

/// Cancelling: the fee shown first as a badge, the reasons as cards, the three that blame the other side said to go to
/// Dorr as an exception. Once loading has begun only those are offered (the server refuses the rest).
private struct MovingCancelSheet: View {
    @Environment(\.wa) private var wa
    let show: MovingShowDto
    let onClose: () -> Void
    let onDone: (String?) -> Void
    @State private var reason: String?
    @State private var details = ""
    @State private var busy = false
    @State private var error: String?

    private let blaming = ["driver_late", "extra_money_asked", "vehicle_mismatch"]

    var body: some View {
        let r = show.request
        let onlyException = exceptionOnly.contains(r.status)
        let reasons = onlyException ? blaming : ["changed_plans", "found_another", "other"] + blaming
        let isException = reason.map { blaming.contains($0) } ?? false
        MvSheet {
            VStack(spacing: 8) {
                Image(systemName: "exclamationmark.triangle.fill").font(.system(size: 30)).foregroundStyle(AppColors.danger)
                    .frame(width: 64, height: 64).background(AppColors.danger.opacity(0.1), in: Circle())
                Text(L(onlyException ? "mv_exception" : "mv_cancel_title")).font(.dorr(20, .black)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
                if !onlyException {
                    let fee = show.cancelFeeMinor ?? 0
                    let free = fee <= 0
                    let tint = free ? AppColors.success : AppColors.danger
                    Text(verbatim: free ? L("mv_cancel_free") : L("mv_cancel_fee", mvMoney(fee, r.currencyCode, r.currencyDecimals)))
                        .font(.dorr(13, .bold)).foregroundStyle(tint)
                        .padding(.horizontal, 14).padding(.vertical, 6)
                        .background(tint.opacity(0.1), in: Capsule())
                }
            }
            .frame(maxWidth: .infinity)
            Text(L("mv_cancel_reason")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
            ForEach(reasons, id: \.self) { key in
                let on = reason == key
                Button { reason = key } label: {
                    HStack(spacing: 6) {
                        if on { Image(systemName: "checkmark.circle.fill").font(.system(size: 15)).foregroundStyle(wa.accent) }
                        VStack(alignment: .leading, spacing: 0) {
                            Text(verbatim: cancelReasonLabel(key)).font(.dorr(13, on ? .bold : .medium)).foregroundStyle(wa.settingsInk)
                            if blaming.contains(key) { Text(L("mv_cancel_goes_to_dorr")).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
                        }
                        Spacer(minLength: 0)
                    }
                    .padding(.horizontal, 12).padding(.vertical, 10)
                    .background(on ? wa.accent.opacity(0.12) : wa.settingsBackground, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(on ? wa.accent : .clear, lineWidth: 1.5))
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .animation(.easeInOut(duration: 0.2), value: on)
            }
            if isException { MvNote(text: L("mv_cancel_exception_note"), color: AppColors.warning) }
            DorrTextArea(value: Binding(get: { details }, set: { details = String($0.prefix(1000)) }), placeholder: L("mv_cancel_details"), minHeight: 70)
            MvError(text: error)
            AccentButton(text: L("mv_keep_request"), action: onClose)
            MvOutlineButton(text: L(isException || onlyException ? "mv_send_exception" : "mv_cancel_confirm"), color: AppColors.danger,
                            enabled: reason != nil && !busy) { send(r.uuid) }
        }
    }

    private func send(_ uuid: String) {
        guard let chosen = reason else { return }
        busy = true
        error = nil
        Task {
            var body: [String: JSONValue] = ["reason": .string(chosen)]
            let text = details.trimmingCharacters(in: .whitespacesAndNewlines)
            if !text.isEmpty { body["details"] = .string(text) }
            do {
                let envelope = try await MovingApi.cancel(uuid, body)
                onDone(envelope.message)
            } catch {
                self.error = error.apiFailure.message ?? L("rd_error")
            }
            busy = false
        }
    }

    private func cancelReasonLabel(_ key: String) -> String {
        switch key {
        case "changed_plans": return L("mv_reason_changed_plans")
        case "found_another": return L("mv_reason_found_another")
        case "driver_late": return L("mv_reason_driver_late")
        case "extra_money_asked": return L("mv_reason_extra_money")
        case "vehicle_mismatch": return L("mv_reason_vehicle_mismatch")
        default: return L("mv_reason_other")
        }
    }
}

/// An expired or withdrawn request as a new draft with the same details: the steps open on its review.
private struct RepublishButton: View {
    let uuid: String
    @ObservedObject var nav: MovingNav
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            AccentButton(text: L("mv_republish"), busy: busy) {
                busy = true
                error = nil
                Task {
                    do {
                        if let draft = try await MovingApi.republish(uuid).data { nav.replace(.wizard(uuid: draft.uuid, atReview: true)) }
                    } catch {
                        self.error = error.apiFailure.message ?? L("rd_error")
                    }
                    busy = false
                }
            }
            MvError(text: error)
        }
    }
}

/// Taking a request or a draft back before an offer is chosen: free, and any offers are closed.
struct MovingWithdrawSheet: View {
    @Environment(\.wa) private var wa
    let uuid: String
    let title: String
    /// Android's `body` (a View's `body` is the page itself here).
    let text: String
    let action: String
    let onClose: () -> Void
    let onDone: (String?) -> Void
    @State private var busy = false
    @State private var error: String?

    init(uuid: String, title: String, body: String, action: String, onClose: @escaping () -> Void, onDone: @escaping (String?) -> Void) {
        self.uuid = uuid
        self.title = title
        self.text = body
        self.action = action
        self.onClose = onClose
        self.onDone = onDone
    }

    var body: some View {
        MvSheet {
            Text(verbatim: title).font(.dorr(20, .black)).foregroundStyle(wa.settingsInk)
            Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsMut)
            MvError(text: error)
            AccentButton(text: L("mv_keep_request"), action: onClose)
            MvOutlineButton(text: action, color: AppColors.danger, enabled: !busy) { withdraw() }
        }
    }

    private func withdraw() {
        busy = true
        error = nil
        Task {
            do {
                let envelope = try await MovingApi.withdraw(uuid)
                onDone(envelope.message)
            } catch {
                self.error = error.apiFailure.message ?? L("rd_error")
            }
            busy = false
        }
    }
}
