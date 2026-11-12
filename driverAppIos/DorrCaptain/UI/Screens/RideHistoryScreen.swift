import SwiftUI

// driverApp `ui/RideHistoryScreen.kt`.

/** O-78: my past rides — finished, cancelled or no-show — page by page, and each one's details. */
struct RideHistoryScreen: View {
    let onBack: () -> Void

    @Environment(\.pal) private var pal
    @State private var items: [DriverRideItemDto]?
    @State private var page = 1
    @State private var lastPage = 1
    @State private var loadingMore = false
    @State private var open: String?

    init(onBack: @escaping () -> Void) { self.onBack = onBack }

    private func load(_ p: Int) async {
        guard let data = await DriverApi.rideHistory(page: p).value else { return }
        items = p == 1 ? (data.items ?? []) : (items ?? []) + (data.items ?? [])
        page = p
        lastPage = data.meta?.lastPage ?? p
    }

    var body: some View {
        ScreenScaffold(L("rh_title"), onBack: onBack) {
            if let list = items {
                if list.isEmpty {
                    VStack(spacing: 0) {
                        HeroBadge("clock.arrow.circlepath", size: 76)
                        VSpace(10)
                        Text(L("rh_empty")).font(.dorr(16)).foregroundStyle(pal.mut)
                    }
                    .padding(32)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else {
                    ScrollView {
                        LazyVStack(spacing: 10) {
                            VSpace(6)
                            ForEach(Array(list.enumerated()), id: \.offset) { _, r in
                                HistoryRow(r: r) { open = r.uuid }
                            }
                            if page < lastPage {
                                Text(L(loadingMore ? "rh_loading" : "rh_more")).font(.dorr(16, .bold)).foregroundStyle(pal.accent)
                                    .multilineTextAlignment(.center)
                                    .padding(14)
                                    .frame(maxWidth: .infinity)
                                    .contentShape(RoundedRectangle(cornerRadius: 14))
                                    .onTapGesture {
                                        guard !loadingMore else { return }
                                        loadingMore = true
                                        Task {
                                            await load(page + 1)
                                            loadingMore = false
                                        }
                                    }
                            }
                            VSpace(16)
                        }
                        .padding(.horizontal, 16)
                    }
                }
            } else {
                CircularProgress(color: pal.accent, size: 40, lineWidth: 4)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .task {
            await load(1)
            if items == nil { items = [] }
        }
        .sheet(isPresented: Binding(get: { open != nil }, set: { if !$0 { open = nil } })) {
            if let uuid = open {
                RideDetails(uuid: uuid)
                    .environment(\.pal, pal)
                    .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
                    .background(pal.card.ignoresSafeArea())
                    .presentationDetents([.medium, .large])
                    .presentationDragIndicator(.visible)
            }
        }
    }
}

private struct HistoryRow: View {
    @Environment(\.pal) private var pal
    let r: DriverRideItemDto
    let onClick: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                Text(r.number ?? "—").font(.dorr(14, .black)).foregroundStyle(pal.ink)
                HSpace(8)
                StatusChip(status: r.status ?? "")
                Spacer(minLength: 0)
                Text(r.earningMinor.map { moneyText($0, r.currency) } ?? "—")
                    .font(.dorr(16, .bold))
                    .foregroundStyle(r.status == "completed" ? pal.success : pal.mut)
            }
            let when = rhWhenText(r.completedAt)
            Text([r.category, when.trimmingCharacters(in: .whitespaces).isEmpty ? nil : when].compactMap { $0 }.joined(separator: " · "))
                .font(.dorr(12)).foregroundStyle(pal.mut).padding(.top, 2)
            VSpace(8)
            PlaceLine(icon: "smallcircle.filled.circle", text: r.originAddress, tint: pal.success)
            PlaceLine(icon: "mappin.circle.fill", text: r.destinationAddress, tint: pal.danger)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface(18, elevation: 4)
        .contentShape(Rectangle())
        .onTapGesture(perform: onClick)
    }
}

private struct PlaceLine: View {
    @Environment(\.pal) private var pal
    let icon: String
    let text: String?
    let tint: Color

    var body: some View {
        HStack(spacing: 0) {
            DorrIcon(icon, size: 16, tint: tint)
            HSpace(6)
            Text(text ?? "—").font(.dorr(13)).foregroundStyle(pal.ink).lineLimit(1).truncationMode(.tail)
        }
        .padding(.vertical, 2)
    }
}

private struct StatusChip: View {
    @Environment(\.pal) private var pal
    let status: String

    var body: some View {
        let (label, tint): (String, Color) = {
            switch status {
            case "completed": return (L("rh_completed"), pal.success)
            case "no_show": return (L("rh_no_show"), pal.warning)
            default: return (L("rh_canceled"), pal.danger)
            }
        }()
        Text(label).font(.dorr(11, .bold)).foregroundStyle(tint)
            .padding(.horizontal, 8).padding(.vertical, 3)
            .background(Capsule().fill(tint.opacity(0.12)))
    }
}

/** One past ride: where, when, how far, what it cost, what I earned, the rider — and blocking them. */
private struct RideDetails: View {
    @Environment(\.pal) private var pal
    let uuid: String
    @State private var t: TripDto?

    var body: some View {
        Group {
            if let trip = t {
                ScrollView {
                    VStack(alignment: .leading, spacing: 12) {
                        HStack(spacing: 0) {
                            Text(trip.number ?? "—").font(.dorr(18, .black)).foregroundStyle(pal.ink)
                            HSpace(8)
                            StatusChip(status: trip.status ?? "")
                        }
                        HStack(spacing: 0) {
                            Text([trip.category?.name, trip.rider?.name].compactMap { $0 }.joined(separator: " · "))
                                .font(.dorr(13)).foregroundStyle(pal.mut)
                            RiderTick(verified: trip.rider?.isVerified == true, size: 13)
                        }
                        VStack(alignment: .leading, spacing: 0) {
                            PlaceLine(icon: "smallcircle.filled.circle", text: trip.origin?.address, tint: pal.success)
                            ForEach(Array((trip.stops ?? []).enumerated()), id: \.offset) { _, s in
                                PlaceLine(icon: "mappin.circle.fill", text: s.address, tint: pal.warning)
                            }
                            PlaceLine(icon: "mappin.circle.fill", text: trip.destination?.address, tint: pal.danger)
                        }
                        .padding(12)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .surface(16, elevation: 2)
                        HStack(spacing: 10) {
                            Fact(label: L("rh_distance"), value: trip.route?.distanceM.map { rhNumber(Double($0) / 1000, places: 1) + " km" } ?? "—")
                            Fact(label: L("rh_duration"), value: trip.route?.durationS.map { "\($0 / 60) min" } ?? "—")
                            Fact(label: L("rh_payment"), value: {
                                switch trip.paymentMethod {
                                case "cash": return L("offer_cash")
                                case nil: return "—"
                                default: return L("offer_paid")
                                }
                            }())
                        }
                        if let times = trip.times {
                            VStack(alignment: .leading, spacing: 4) {
                                if let at = times.arrivedAt { Line(label: L("rh_arrived_at"), value: rhWhenText(at)) }
                                if let at = times.startedAt { Line(label: L("rh_started_at"), value: rhWhenText(at)) }
                            }
                            .padding(12)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .surface(16, elevation: 2)
                        }
                        let lines = trip.lines ?? []
                        if !lines.isEmpty {
                            VStack(alignment: .leading, spacing: 4) {
                                ForEach(Array(lines.enumerated()), id: \.offset) { _, l in
                                    Line(label: l.label ?? l.code ?? "", value: money(l.amountMinor, trip.currency))
                                }
                            }
                            .padding(12)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .surface(16, elevation: 2)
                        }
                        VStack(alignment: .leading, spacing: 4) {
                            Line(label: L("rh_total"), value: money(trip.finalTotalMinor, trip.currency))
                            if let tip = trip.tipMinor, tip > 0 { Line(label: L("rh_tip"), value: money(tip, trip.currency)) }
                            HStack(spacing: 0) {
                                Text(L("rh_earned")).font(.dorr(16, .bold)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                                Text(money(trip.earningMinor, trip.currency)).font(.dorr(16, .black)).foregroundStyle(pal.success)
                            }
                        }
                        .padding(12)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(RoundedRectangle(cornerRadius: 16).fill(pal.success.opacity(0.08)))
                        // O-80: block this rider — they never get matched with me again.
                        if trip.status == "completed" || trip.status == "no_show" || trip.status == "canceled" { BlockRiderButton(t: trip) }
                    }
                    .padding(.horizontal, 20)
                    .padding(.top, 22)
                    .padding(.bottom, 28)
                }
            } else {
                CircularProgress(color: pal.accent, size: 40, lineWidth: 4)
                    .frame(maxWidth: .infinity)
                    .frame(height: 220)
                    .padding(.top, 22)
            }
        }
        .task(id: uuid) { t = await DriverApi.ride(uuid: uuid).value }
    }
}

private struct Fact: View {
    @Environment(\.pal) private var pal
    let label: String
    let value: String

    var body: some View {
        VStack(spacing: 0) {
            Text(value).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
            Text(label).font(.dorr(11)).foregroundStyle(pal.mut)
        }
        .padding(.vertical, 10)
        .frame(maxWidth: .infinity)
        .background(RoundedRectangle(cornerRadius: 14).fill(pal.accent.opacity(0.07)))
    }
}

private struct Line: View {
    @Environment(\.pal) private var pal
    let label: String
    let value: String

    var body: some View {
        HStack(spacing: 0) {
            Text(label).font(.dorr(13)).foregroundStyle(pal.mut).frame(maxWidth: .infinity, alignment: .leading)
            Text(value).font(.dorr(13, .semibold)).foregroundStyle(pal.ink)
        }
    }
}

/// `String.format(Locale.getDefault(), "%.Nf", x)`: the app's language's digits.
private func rhNumber(_ value: Double, places: Int) -> String {
    let f = NumberFormatter()
    f.locale = Locale(identifier: DriverPrefs.locale)
    f.numberStyle = .decimal
    f.usesGroupingSeparator = false
    f.minimumFractionDigits = places
    f.maximumFractionDigits = places
    f.roundingMode = .halfUp
    return f.string(from: NSNumber(value: value)) ?? String(format: "%.\(places)f", value)
}

/** The list sends the currency as its symbol only. */
private func moneyText(_ minor: Int, _ symbol: String?) -> String {
    "\(rhNumber(Double(minor) / 100, places: 2)) \(symbol ?? "")".trimmingCharacters(in: .whitespaces)
}

/// `WalletScreen.kt` `whenText`: the date (medium) and time (short) in the phone's zone, in the app's language.
private func rhWhenText(_ iso: String?) -> String {
    guard let at = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = Locale(identifier: DriverPrefs.locale)
    f.dateStyle = .medium
    f.timeStyle = .short
    return f.string(from: at)
}
