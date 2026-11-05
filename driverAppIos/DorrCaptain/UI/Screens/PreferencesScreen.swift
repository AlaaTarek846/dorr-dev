import SwiftUI

// driverApp `ui/PreferencesScreen.kt`.

/** Pickup ranges offered as choices, in km (only those the country's search can reach). */
private let RANGES_KM = [2, 3, 5, 10, 20]

/** How far Auto-Accept will go for a pickup, in km. */
private let AUTO_PICKUP_KM = [1, 2, 3, 5]

/**
 * The captain's driving preferences (D-32..D-37): the categories they want to be asked for, how far
 * they go for a pickup, and Auto-Accept — requests that fit their own rules are accepted for them.
 * Every choice is saved as it is made; the server answers with the whole state and says what isn't
 * available where they are (Auto-Accept, its price and payment rules).
 */
struct PreferencesScreen: View {
    let onBack: () -> Void

    @Environment(\.pal) private var pal
    @State private var prefs: PreferencesDto?
    @State private var error: String?
    @State private var saved = false

    init(onBack: @escaping () -> Void) { self.onBack = onBack }

    private func save(_ body: JsonBody) {
        error = nil
        saved = false
        Task {
            switch await DriverApi.savePreferences(body: body) {
            case .ok(let p, _): prefs = p; saved = true
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
        }
    }

    private func saveAuto(_ build: [String: Any]) { save(["auto_accept": build]) }

    var body: some View {
        ScreenScaffold(L("pf_title"), onBack: onBack) {
            if let p = prefs { content(p) }
        }
        .task { if let p = await DriverApi.preferences().value { prefs = p } }
    }

    @ViewBuilder
    private func content(_ p: PreferencesDto) -> some View {
        let maxKm = (p.pickupRadiusMaxM ?? 50000) / 1000
        let auto = p.autoAccept ?? AutoAcceptDto()
        let autoEnabled = auto.enabled ?? false
        let categories = p.categories ?? []

        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                if let error {
                    Text(error).font(.dorr(13)).foregroundStyle(pal.danger)
                } else if saved {
                    Text(L("pf_saved")).font(.dorr(13, .semibold)).foregroundStyle(pal.success)
                }

                // D-32: the categories.
                Card {
                    Text(L("pf_categories")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                    Text(L("pf_categories_hint")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                    VSpace(10)
                    PrefFlow {
                        // O-27: only the categories this captain and car may take — the general ones and the special ones they
                        // qualify for (For Her for women, VIP for a luxury car, Accessible for an equipped one…).
                        ForEach(Array(categories.filter { $0.eligible ?? false }.enumerated()), id: \.offset) { _, c in
                            let slug = c.slug ?? ""
                            let selected = c.selected ?? false
                            PrefPill(label: c.name ?? slug, selected: selected) {
                                let now = categories.filter { $0.selected ?? false }.map { $0.slug ?? "" }
                                let next = selected ? now.filter { $0 != slug } : now + [slug]
                                if next.isEmpty { error = L("pf_keep_one") }
                                else { save(["categories": next]) }
                            }
                        }
                    }
                }

                // D-33: how far for a pickup.
                Card {
                    Text(L("pf_range")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                    Text(L("pf_range_hint")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                    VSpace(10)
                    let current = p.pickupRadiusM
                    let extra: [Int] = current.map { $0 / 1000 }.flatMap { km in km > 0 && !RANGES_KM.contains(km) ? [km] : nil } ?? []
                    let options = Array(Set(RANGES_KM.filter { $0 <= maxKm } + extra)).sorted()
                    PrefFlow {
                        PrefPill(label: L("pf_no_limit"), selected: current == nil) {
                            save(["pickup_radius_m": NSNull()])
                        }
                        ForEach(options, id: \.self) { km in
                            PrefPill(label: L("pf_km", km), selected: current != nil && current! / 1000 == km) {
                                save(["pickup_radius_m": km * 1000])
                            }
                        }
                    }
                }

                // R-235, R-236, D-390: what my car has / takes — requests with a pet or child seats come only when it fits.
                let eq = p.equipment ?? EquipmentDto()
                if (eq.petsOffered ?? false) || (eq.childSeatsOffered ?? false) {
                    Card {
                        Text(L("pf_equipment")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                        Text(L("pf_equipment_hint")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                        if eq.petsOffered ?? false {
                            VSpace(10)
                            HStack(spacing: 0) {
                                Text(L("pf_pets")).font(.dorr(16, .semibold)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                                DorrToggle(on: eq.petFriendly ?? false) { on in save(["pet_friendly": on]) }
                            }
                        }
                        if eq.childSeatsOffered ?? false {
                            VSpace(10)
                            Text(L("pf_child_seats")).font(.dorr(13, .semibold)).foregroundStyle(pal.ink)
                            VSpace(6)
                            PrefFlow {
                                ForEach(0...3, id: \.self) { n in
                                    PrefPill(label: n == 0 ? L("pf_none") : "\(n)", selected: (eq.childSeats ?? 0) == n) { save(["child_seats": n]) }
                                }
                            }
                        }
                    }
                }

                // Block 06: a next trip near where my ride ends, offered quietly before it ends — only where the country has it.
                if p.nextTrips?.available == true {
                    Card {
                        HStack(spacing: 0) {
                            VStack(alignment: .leading, spacing: 0) {
                                Text(L("pf_next_trips")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                                Text(L("pf_next_trips_hint")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                            }
                            .frame(maxWidth: .infinity, alignment: .leading)
                            DorrToggle(on: p.nextTrips?.enabled ?? true) { on in save(["next_trips": on]) }
                        }
                    }
                }

                // D-34..D-37: Auto-Accept — only where the country has it on.
                if auto.available ?? false {
                    Card {
                        HStack(spacing: 0) {
                            VStack(alignment: .leading, spacing: 0) {
                                Text(L("pf_auto")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                                Text(L("pf_auto_hint")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                            }
                            .frame(maxWidth: .infinity, alignment: .leading)
                            DorrToggle(on: autoEnabled) { on in saveAuto(["enabled": on]) }
                        }
                        if autoEnabled {
                            VSpace(12)
                            Text(L("pf_auto_pickup")).font(.dorr(13, .semibold)).foregroundStyle(pal.ink)
                            VSpace(6)
                            PrefFlow {
                                PrefPill(label: L("pf_any"), selected: auto.maxPickupM == nil) { saveAuto(["max_pickup_m": NSNull()]) }
                                ForEach(AUTO_PICKUP_KM, id: \.self) { km in
                                    PrefPill(label: L("pf_km", km), selected: auto.maxPickupM == km * 1000) { saveAuto(["max_pickup_m": km * 1000]) }
                                }
                            }
                        }
                    }

                    if autoEnabled && (auto.priceFilter?.allowed ?? false) { PriceRules(p: p) }
                    let payment = auto.paymentFilter ?? PaymentFilterDto()
                    if autoEnabled && (payment.allowed ?? false) {
                        Card {
                            Text(L("pf_payment")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                            Text(L("pf_payment_hint")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                            VSpace(10)
                            PrefFlow {
                                ForEach(payment.options ?? [], id: \.self) { method in
                                    let methods = payment.methods ?? []
                                    let on = methods.contains(method)
                                    PrefPill(label: paymentLabel(method), selected: on) {
                                        let next = on ? methods.filter { $0 != method } : methods + [method]
                                        saveAuto(["payment_methods": next])
                                    }
                                }
                            }
                        }
                    }
                }
            }
            .padding(16)
        }
    }
}

/** D-36: the lowest earning Auto-Accept takes — per ride and per km — only where the country allows it. */
private struct PriceRules: View {
    @Environment(\.pal) private var pal
    let p: PreferencesDto
    @State private var fare = ""
    @State private var perKm = ""
    @State private var message: String?

    private var places: Int { p.currency?.decimals ?? 2 }
    private var price: PriceFilterDto { p.autoAccept?.priceFilter ?? PriceFilterDto() }

    var body: some View {
        let symbol = p.currency?.symbol ?? ""
        Card {
            Text(L("pf_price")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
            VSpace(8)
            DorrField(value: fare, onValueChange: { fare = $0; message = nil }, label: L("pf_min_fare", symbol), singleLine: true,
                      keyboardOptions: KeyboardOptions(keyboardType: .decimalPad))
            VSpace(8)
            DorrField(value: perKm, onValueChange: { perKm = $0; message = nil }, label: L("pf_min_km", symbol), singleLine: true,
                      keyboardOptions: KeyboardOptions(keyboardType: .decimalPad))
            VSpace(10)
            BigButton(L("pf_save")) {
                let body: JsonBody = ["auto_accept": [
                    "min_fare_minor": toMinor(fare, places).map { $0 as Any } ?? NSNull(),
                    "min_per_km_minor": toMinor(perKm, places).map { $0 as Any } ?? NSNull(),
                ] as [String: Any]]
                Task {
                    switch await DriverApi.savePreferences(body: body) {
                    case .ok: message = L("pf_saved")
                    case .failed(let e): message = e.displayMessage
                    case .offline: message = L("error_offline")
                    }
                }
            }
            if let message { Text(message).font(.dorr(12)).foregroundStyle(pal.ink).padding(.top, 6) }
        }
        // `remember(price.minFareMinor)` / `remember(price.minPerKmMinor)`: the field follows what the server keeps.
        .task(id: price.minFareMinor) { fare = fromMinor(price.minFareMinor, places) }
        .task(id: price.minPerKmMinor) { perKm = fromMinor(price.minPerKmMinor, places) }
    }
}

private struct PrefPill: View {
    @Environment(\.pal) private var pal
    let label: String
    let selected: Bool
    var enabled = true
    let onClick: () -> Void

    init(label: String, selected: Bool, enabled: Bool = true, onClick: @escaping () -> Void) {
        self.label = label
        self.selected = selected
        self.enabled = enabled
        self.onClick = onClick
    }

    var body: some View {
        Text(label)
            .font(.dorr(13, .semibold))
            .foregroundStyle(selected ? Color.white : pal.navy.opacity(enabled ? 1 : 0.4))
            .padding(.horizontal, 14)
            .padding(.vertical, 8)
            .background(Capsule().fill(selected ? pal.navy : pal.navy.opacity(0.07)))
            .animation(.tween(200), value: selected)
            .contentShape(Capsule())
            .onTapGesture { if enabled { onClick() } }
    }
}

/// `FlowRow(horizontalArrangement = spacedBy(8.dp), verticalArrangement = spacedBy(8.dp))`.
private struct PrefFlow: Layout {
    var spacing: CGFloat = 8

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? .infinity
        var x: CGFloat = 0, y: CGFloat = 0, row: CGFloat = 0, widest: CGFloat = 0
        for v in subviews {
            let s = v.sizeThatFits(.unspecified)
            if x > 0 && x + s.width > width { y += row + spacing; x = 0; row = 0 }
            x += s.width + spacing
            row = max(row, s.height)
            widest = max(widest, x - spacing)
        }
        return CGSize(width: proposal.width ?? widest, height: y + row)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x: CGFloat = 0, y: CGFloat = 0, row: CGFloat = 0
        for v in subviews {
            let s = v.sizeThatFits(.unspecified)
            if x > 0 && x + s.width > bounds.width { y += row + spacing; x = 0; row = 0 }
            v.place(at: CGPoint(x: bounds.minX + x, y: bounds.minY + y), proposal: ProposedViewSize(s))
            x += s.width + spacing
            row = max(row, s.height)
        }
    }
}

private func paymentLabel(_ method: String) -> String {
    switch method {
    case "cash": return L("pf_pay_cash")
    case "wallet": return L("pf_pay_wallet")
    default: return L("pf_pay_card")
    }
}

/** "12.5" → minor units (1250 with two places); nil for nothing typed or not a number. */
private func toMinor(_ text: String, _ places: Int) -> Int? {
    // An iPhone's number pad may type Arabic-Indic digits and «٫»; Android's sends Latin ones.
    let latin = text.map { ch -> String in
        if let d = "٠١٢٣٤٥٦٧٨٩".firstIndex(of: ch) { return String("٠١٢٣٤٥٦٧٨٩".distance(from: "٠١٢٣٤٥٦٧٨٩".startIndex, to: d)) }
        if let d = "۰۱۲۳۴۵۶۷۸۹".firstIndex(of: ch) { return String("۰۱۲۳۴۵۶۷۸۹".distance(from: "۰۱۲۳۴۵۶۷۸۹".startIndex, to: d)) }
        return ch == "٫" ? "." : String(ch)
    }.joined()
    let t = latin.trimmingCharacters(in: .whitespacesAndNewlines)
    guard !t.isEmpty, let d = Decimal(string: t, locale: Locale(identifier: "en_US_POSIX")),
          t.range(of: #"^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$"#, options: .regularExpression) != nil
    else { return nil }
    var shifted = d * pow(Decimal(10), places)
    var whole = Decimal()
    // `BigDecimal.toLong()` drops the fraction (towards zero).
    NSDecimalRound(&whole, &shifted, 0, shifted < 0 ? .up : .down)
    return NSDecimalNumber(decimal: whole).intValue
}

private func fromMinor(_ minor: Int?, _ places: Int) -> String {
    guard let minor else { return "" }
    let value = Decimal(minor) / pow(Decimal(10), places)
    // `stripTrailingZeros().toPlainString()`.
    return NSDecimalNumber(decimal: value).stringValue
}
