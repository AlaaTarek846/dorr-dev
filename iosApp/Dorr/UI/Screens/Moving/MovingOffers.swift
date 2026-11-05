import SwiftUI

// androidApp `moving/MovingOffers.kt` — the offers (REQ-096, owner's call): three at a time with «عرض المزيد», the number
// that came above them, each with the reasons for its place said in words. Accepting shows the price, the tax, the total
// and the cancellation fees first (REQ-043, REQ-115), then opens the app's one payment screen.

@MainActor
struct MovingOffersPage: View {
    @Environment(\.wa) private var wa
    let uuid: String
    let decimals: Int
    @ObservedObject var nav: MovingNav
    @State private var total = 0
    @State private var pages = 1
    @State private var hasMore = false
    @State private var offers: [MovingOfferDto]?
    @State private var deadline: String?
    @State private var status: String?
    @State private var error: String?
    @State private var loadingMore = false
    @State private var accepting: MovingOfferDto?

    /// The pages already on screen, read again: a new or withdrawn offer changes the order.
    private func reload() async {
        var collected: [MovingOfferDto] = []
        var last: MovingOffersPageDto?
        do {
            for p in 1...max(pages, 1) {
                guard let page = try await MovingApi.offers(uuid, page: p).data else { break }
                collected += page.offers
                last = page
            }
        } catch {
            self.error = error.apiFailure.message ?? L("rd_error")
        }
        if let last {
            total = last.total
            hasMore = last.hasMore
            offers = collected
            error = nil
        }
        if offers == nil { offers = [] }
        if let show = try? await MovingApi.show(uuid).data {
            deadline = show.request.offersDeadlineAt
            status = show.request.status
        }
    }

    var body: some View {
        MvPage(title: L("mv_offers_title"), onBack: { nav.pop() }) {
            if let list = offers {
                if list.isEmpty {
                    OffersWaiting(error: error, deadline: deadline)
                } else {
                    MvScroll {
                        Text(verbatim: offersCountText(total)).font(.dorr(22, .black)).foregroundStyle(wa.settingsInk)
                        Text(L("mv_offers_order_hint")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut)
                        MvTicking { now in
                            if let left = mvSecondsLeft(deadline, now: now), left > 0 {
                                Text(verbatim: L("mv_offers_left", countdownText(left))).font(.dorr(13, .semibold)).foregroundStyle(wa.accent)
                            }
                        }
                        ForEach(Array(list.enumerated()), id: \.element.uuid) { i, offer in
                            OfferCard(offer: offer, decimals: decimals,
                                      onOpen: { nav.push(.offerDetails(uuid, offer: offer, decimals: decimals)) },
                                      onAccept: { accepting = offer })
                                .staggerIn(i)
                        }
                        if hasMore {
                            AccentButton(text: L("mv_see_more"), busy: loadingMore) {
                                loadingMore = true
                                pages += 1
                            }
                        }
                        MvError(text: error)
                    }
                }
            } else {
                MvLoading()
            }
        }
        .movingLive(uuid, onChanged: { await reload() })
        // The request moved on (expired, an offer taken on another phone): the hub says what happened.
        .onChange(of: status) { s in if let s, s != "open" { nav.backToRequest(uuid) } }
        .task(id: pages) {
            guard pages > 1 else { return }
            await reload()
            loadingMore = false
        }
        .sheet(item: $accepting) { offer in
            AcceptOfferSheet(uuid: uuid, offer: offer, decimals: decimals, onClose: { accepting = nil }) { nav.backToRequest(uuid) }
                .dorrThemed().presentationDetents([.large])
        }
    }
}

/// No offer yet: the rides' calm radar, and how long the offers can still come.
private struct OffersWaiting: View {
    @Environment(\.wa) private var wa
    let error: String?
    let deadline: String?
    @State private var wave = false

    var body: some View {
        MvScroll {
            VStack(spacing: 4) {
                ZStack {
                    ForEach(0..<3, id: \.self) { i in
                        Circle().fill(wa.accent.opacity(0.25))
                            .scaleEffect(wave ? 1 : 0.3)
                            .opacity(wave ? 0 : 1)
                            .animation(.linear(duration: 1.8).repeatForever(autoreverses: false).delay(Double(i) * 0.6), value: wave)
                    }
                    Image(systemName: "box.truck.fill").font(.system(size: 18)).foregroundStyle(.white).frame(width: 46, height: 46).background(wa.accent, in: Circle())
                }
                .frame(width: 110, height: 110)
                .onAppear { wave = true }
                Text(L("mv_offers_waiting")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                MvTicking { now in
                    if let left = mvSecondsLeft(deadline, now: now), left > 0 {
                        Text(verbatim: L("mv_offers_left", countdownText(left))).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                    }
                }
                if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger).padding(.top, 8) }
            }
            .frame(maxWidth: .infinity)
            .padding(.top, 40)
        }
    }
}

private struct OfferCard: View {
    @Environment(\.wa) private var wa
    let offer: MovingOfferDto
    let decimals: Int
    let onOpen: () -> Void
    let onAccept: () -> Void

    var body: some View {
        let cheapest = offer.reasons.contains("cheapest")
        let shape = RoundedRectangle(cornerRadius: 20, style: .continuous)
        VStack(alignment: .leading, spacing: 8) {
            OfferHead(offer: offer, decimals: decimals)
            if !offer.reasons.isEmpty {
                FlowLayout(spacing: 6, lineSpacing: 6) { ForEach(offer.reasons, id: \.self) { OfferReasonChip(reason: $0) } }
            }
            OfferVehicle(offer: offer)
            OfferFacts(offer: offer)
            OfferValidity(offer: offer)
            HStack(spacing: 8) {
                MvOutlineButton(text: L("mv_offer_details"), action: onOpen)
                AccentButton(text: L("mv_accept"), action: onAccept)
            }
        }
        .padding(14)
        .background(wa.settingsCard, in: shape)
        .overlay(shape.stroke(cheapest ? AppColors.success : wa.settingsInk.opacity(0.08), lineWidth: cheapest ? 2 : 1))
    }
}

/// The price, and who offers it with their stars and finished moves.
private struct OfferHead: View {
    @Environment(\.wa) private var wa
    let offer: MovingOfferDto
    let decimals: Int

    var body: some View {
        let e = offer.establishment
        HStack(spacing: 10) {
            ZStack {
                Circle().fill(wa.accent.opacity(0.12))
                if let url = ApiClient.mediaUrl(e?.logo) {
                    AsyncImage(url: url) { $0.resizable().scaledToFill() } placeholder: { Color.clear }.clipShape(Circle())
                } else {
                    Text(verbatim: String((e?.name ?? "").prefix(1))).font(.dorr(15, .bold)).foregroundStyle(wa.accent)
                }
            }
            .frame(width: 46, height: 46)
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: e?.name ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                if let branch = offer.branch, !branch.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: branch).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                HStack(spacing: 2) {
                    Image(systemName: "star.fill").font(.system(size: 11)).foregroundStyle(AppColors.warning)
                    Text(verbatim: e?.average.map { String(format: "%.1f", $0) } ?? L("rd_new_rating")).font(.dorr(12)).foregroundStyle(wa.settingsInk)
                    if (e?.count ?? 0) > 0 { Text(verbatim: " (\(e?.count ?? 0))").font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    Text(verbatim: " · " + L("mv_finished_moves", e?.jobs ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
            }
            Spacer(minLength: 0)
            Text(verbatim: mvMoney(offer.priceMinor, offer.currencyCode, decimals)).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
        }
    }
}

/// Why it sits here, in words: the order is explained, never a choice made for the customer.
private struct OfferReasonChip: View {
    @Environment(\.wa) private var wa
    let reason: String

    var body: some View {
        switch reason {
        case "cheapest": MvChip(text: L("mv_reason_cheapest"), color: AppColors.success)
        case "earliest": MvChip(text: L("mv_reason_earliest"), color: AppColors.info)
        case "vehicle_fit": MvChip(text: L("mv_reason_vehicle_fit"), color: wa.accent)
        default: MvChip(text: reason, color: wa.settingsMut)
        }
    }
}

private struct OfferVehicle: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let offer: MovingOfferDto

    var body: some View {
        if let vehicle = offer.vehicle {
            if let name = vehicle.type?.name { MvLine(label: L("mv_vehicle_title"), value: name) }
            if !vehicle.photos.isEmpty {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(Array(vehicle.photos.enumerated()), id: \.offset) { _, url in
                            RemoteImage(url: url, placeholder: "photo").frame(width: 120, height: 84)
                                .background(wa.mvInset(onPanel))
                                .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                    }
                }
            }
        }
    }
}

private struct OfferFacts: View {
    @Environment(\.wa) private var wa
    let offer: MovingOfferDto

    var body: some View {
        if let w = offer.workersCount { MvLine(label: L("mv_workers"), value: String(w)) }
        if let t = offer.tripsCount { MvLine(label: L("mv_trips"), value: String(t)) }
        if let at = mvTime(offer.arrivalAt) { MvLine(label: L("mv_arrival"), value: rideWhenText(at)) }
        let included = includedKeys(offer.includes)
        if !included.isEmpty {
            Text(L("mv_included")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            FlowLayout(spacing: 6, lineSpacing: 6) { ForEach(included, id: \.self) { MvChip(text: extraLabel($0), color: wa.accent) } }
        }
    }
}

/// How long the offer holds, on the device clock.
private struct OfferValidity: View {
    @Environment(\.wa) private var wa
    let offer: MovingOfferDto

    var body: some View {
        MvTicking { now in
            if let left = mvSecondsLeft(offer.validUntil, now: now) {
                let over = left <= 0
                Text(verbatim: over ? L("mv_offer_expired") : L("mv_valid_for", countdownText(left)))
                    .font(.dorr(12.5, .semibold))
                    .foregroundStyle(over ? AppColors.danger : (left < 600 ? AppColors.warning : wa.settingsMut))
            }
        }
    }
}

/// The offer in full: everything on the card, its terms and what is promised after the move.
@MainActor
struct MovingOfferDetailsPage: View {
    @Environment(\.wa) private var wa
    let uuid: String
    let offer: MovingOfferDto
    let decimals: Int
    @ObservedObject var nav: MovingNav
    @State private var accepting = false

    var body: some View {
        MvPage(title: L("mv_offer_details"), onBack: { nav.pop() },
               bottom: AnyView(AccentButton(text: L("mv_accept_price", mvMoney(offer.priceMinor, offer.currencyCode, decimals))) { accepting = true })) {
            MvScroll {
                MvCard {
                    OfferHead(offer: offer, decimals: decimals)
                    if !offer.reasons.isEmpty {
                        FlowLayout(spacing: 6, lineSpacing: 6) { ForEach(offer.reasons, id: \.self) { OfferReasonChip(reason: $0) } }
                            .padding(.top, 8)
                    }
                }
                .padding(.top, 2)
                MvCard {
                    VStack(alignment: .leading, spacing: 8) {
                        OfferVehicle(offer: offer)
                        OfferFacts(offer: offer)
                        OfferValidity(offer: offer)
                    }
                }
                if let note = offer.technicianNote, !note.trimmingCharacters(in: .whitespaces).isEmpty { textCard(L("mv_extra_technician"), note) }
                if let terms = offer.terms, !terms.trimmingCharacters(in: .whitespaces).isEmpty { textCard(L("mv_terms"), terms) }
                if let after = offer.afterService, !after.trimmingCharacters(in: .whitespaces).isEmpty { textCard(L("mv_after_service"), after) }
            }
        }
        .sheet(isPresented: $accepting) {
            AcceptOfferSheet(uuid: uuid, offer: offer, decimals: decimals, onClose: { accepting = false }) { nav.backToRequest(uuid) }
                .dorrThemed().presentationDetents([.large])
        }
    }

    private func textCard(_ title: String, _ text: String) -> some View {
        MvCard {
            MvCardTitle(text: title)
            Text(verbatim: text).font(.dorr(14)).foregroundStyle(wa.settingsInk).padding(.top, 4)
        }
    }
}

/// Before paying: the price, the tax and the total (the checkout's amount is the total), with the cancellation fees
/// (REQ-043, REQ-115); then the app's one payment screen, as the rides card payment opens it. Paying holds the money;
/// it reaches the establishment only after delivery.
private struct AcceptOfferSheet: View {
    @Environment(\.wa) private var wa
    let uuid: String
    let offer: MovingOfferDto
    let decimals: Int
    let onClose: () -> Void
    let onPaid: () -> Void
    @State private var checkout: CheckoutDto?
    @State private var terms: MovingCancellationDto?
    @State private var loading = true
    @State private var error: String?

    var body: some View {
        let code = offer.currencyCode
        MvSheet {
            Text(L("mv_accept_title")).font(.dorr(20, .black)).foregroundStyle(wa.settingsInk)
            Text(verbatim: offer.establishment?.name ?? "").font(.dorr(13)).foregroundStyle(wa.settingsMut)
            if loading {
                ProgressView().tint(wa.accent).frame(maxWidth: .infinity, minHeight: 120)
            } else {
                if let c = checkout {
                    let total = c.amountMinor
                    VStack(alignment: .leading, spacing: 0) {
                        MvLine(label: L("mv_price"), value: mvMoney(offer.priceMinor, code, decimals))
                        MvLine(label: L("mv_tax"), value: mvMoney(max(total - offer.priceMinor, 0), code, decimals))
                        MvLine(label: L("mv_total"), value: mvMoney(total, code, decimals), bold: true)
                    }
                    .padding(12)
                    .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    Text(L("mv_accept_held")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut)
                }
                if let terms { CancellationTable(t: terms) }
                MvError(text: error)
                if let c = checkout {
                    AccentButton(text: L("mv_go_pay")) {
                        let paid = onPaid
                        CheckoutLauncher.shared.open(CheckoutRequest(purpose: c.purpose,
                                                                     reference: ["request_uuid": .string(uuid), "offer_uuid": .string(offer.uuid)],
                                                                     checkoutId: c.id)) { paid() }
                        onClose()
                    }
                }
            }
        }
        .task(id: offer.uuid) {
            do {
                checkout = try await MovingApi.accept(uuid, offer: offer.uuid).data
            } catch {
                self.error = error.apiFailure.message ?? L("rd_error")
            }
            terms = try? await MovingApi.cancellation(uuid).data
            loading = false
        }
    }
}

/// The fee table in force for this request; what the country left empty costs nothing.
private struct CancellationTable: View {
    @Environment(\.wa) private var wa
    let t: MovingCancellationDto

    var body: some View {
        let hours = t.nearHours.map { $0.truncatingRemainder(dividingBy: 1) == 0 ? String(Int($0)) : String($0) }
        VStack(alignment: .leading, spacing: 0) {
            MvCardTitle(text: L("mv_fees_title")).padding(.bottom, 4)
            MvLine(label: hours.map { L("mv_fee_early_hours", $0) } ?? L("mv_fee_early"), value: feeText(t.earlyBp))
            MvLine(label: hours.map { L("mv_fee_near_hours", $0) } ?? L("mv_fee_near"), value: feeText(t.nearBp))
            MvLine(label: L("mv_fee_on_way"), value: feeText(t.onWayBp))
            MvLine(label: L("mv_fee_arrived"), value: feeText(t.arrivedBp))
            Text(L("mv_fee_after_loading")).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 4)
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
    }

    private func feeText(_ bp: Double?) -> String {
        let percent = (bp ?? 0) / 100
        guard percent > 0 else { return L("mv_fee_none") }
        return L("mv_fee_percent", percent.truncatingRemainder(dividingBy: 1) == 0 ? String(Int(percent)) : String(format: "%.2f", percent))
    }
}
