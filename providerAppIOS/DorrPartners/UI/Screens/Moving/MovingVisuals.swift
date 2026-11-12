// providerApp `ui/screens/MovingVisuals.kt`
import SwiftUI

// The look of the moving pages (requests, a request, the offer form, my offers) — built only from the kit (settingsSurface cards,
// the app colour, the same type sizes and soft tiles), in the style of the customer's moving pages: the route with its two dots,
// chips for the facts, tiles to tick, a stepper for numbers.

extension WaPalette {
    /// The soft background of a chip or a tile that isn't picked.
    var mvWell: Color { night ? darkWell : Color(hex: 0xF3F4F6) }
    var mvEdge: Color { night ? line : Color(hex: 0xEDEEF1) }
}

/// A titled block of a page: the icon on a soft tile, the title, and an optional line under it.
struct MvSection: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    var hint: String?

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.red)
                .frame(width: 28, height: 28)
                .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 9, style: .continuous))
            VStack(alignment: .leading, spacing: 1) {
                Text(verbatim: title).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                if let hint = notBlank(hint) { Text(verbatim: hint).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(.top, 6)
    }
}

/// A small fact as a chip: an icon and a short text in `color`.
struct MvChip: View {
    let icon: String?
    let text: String
    var color: Color?
    @Environment(\.wa) private var wa

    var body: some View {
        let tint = color ?? wa.red
        HStack(spacing: 4) {
            if let icon { Image(systemName: icon).font(.system(size: 11, weight: .semibold)) }
            Text(verbatim: text).font(.dorr(12, .bold)).lineLimit(1)
        }
        .foregroundStyle(tint)
        .padding(.horizontal, 10)
        .padding(.vertical, 5)
        .background(tint.opacity(0.10), in: Capsule())
    }
}

/// The route as the customer's pages draw it: a green dot for the pickup, a dashed line, the pin of the drop-off — each with its
/// neighbourhood (the exact address only once the job is ours). Under it the time, distance and when offers close.
struct MvRouteCard<Badge: View, Footer: View>: View {
    @Environment(\.wa) private var wa
    let move: MovingRequestDto
    var compact = false
    var onClick: (() -> Void)?
    @ViewBuilder var badge: () -> Badge
    @ViewBuilder var footer: () -> Footer

    var body: some View {
        if let onClick {
            Button(action: onClick) { card }.buttonStyle(.plain)
        } else {
            card
        }
    }

    private var card: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(alignment: .top, spacing: 10) {
                VStack(spacing: 0) {
                    Circle().fill(Green.opacity(0.2)).frame(width: 12, height: 12).overlay(Circle().fill(Green).frame(width: 6, height: 6))
                    Rectangle().fill(Color.clear).frame(width: 2, height: compact ? 26 : 34)
                        .overlay(
                            Path { p in p.move(to: CGPoint(x: 1, y: 0)); p.addLine(to: CGPoint(x: 1, y: compact ? 26 : 34)) }
                                .stroke(Color.gray.opacity(0.45), style: StrokeStyle(lineWidth: 2, dash: [3, 3]))
                        )
                    Image(systemName: "mappin.circle.fill").font(.system(size: 15)).foregroundStyle(wa.red)
                }
                .padding(.top, 4)
                VStack(alignment: .leading, spacing: compact ? 10 : 16) {
                    endLine(L("mv_from"), move.pickup?.area)
                    endLine(L("mv_to"), move.dropoff?.area)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                badge()
            }
            FlowLayout(spacing: 6) {
                if let when = notBlank(dateTimeText(move.scheduledAt)) { MvChip(icon: "calendar", text: when) }
                if let km = move.distanceKm { MvChip(icon: "ruler", text: L("mv_distance", formatKm(km)), color: Blue) }
                if move.status == "open", let closes = notBlank(timeOf(move.offersDeadlineAt)) {
                    MvChip(icon: "timer", text: L("mv_offers_close_at", closes), color: Amber)
                }
            }
            footer()
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(22)
        .contentShape(Rectangle())
    }

    private func endLine(_ label: String, _ area: String?) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: label).font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut)
            Text(verbatim: notBlank(area) ?? L("mv_area_unknown")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
        }
    }
}

extension MvRouteCard where Badge == EmptyView, Footer == EmptyView {
    init(move: MovingRequestDto, compact: Bool = false, onClick: (() -> Void)? = nil) {
        self.init(move: move, compact: compact, onClick: onClick, badge: { EmptyView() }, footer: { EmptyView() })
    }
}

extension MvRouteCard where Footer == EmptyView {
    init(move: MovingRequestDto, compact: Bool = false, onClick: (() -> Void)? = nil, @ViewBuilder badge: @escaping () -> Badge) {
        self.init(move: move, compact: compact, onClick: onClick, badge: badge, footer: { EmptyView() })
    }
}

extension MvRouteCard where Badge == EmptyView {
    init(move: MovingRequestDto, compact: Bool = false, onClick: (() -> Void)? = nil, @ViewBuilder footer: @escaping () -> Footer) {
        self.init(move: move, compact: compact, onClick: onClick, badge: { EmptyView() }, footer: footer)
    }
}

/// A fact as a tile: the icon on a soft square, the label small and the value bold.
struct MvFactTile: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let value: String

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red)
                .frame(width: 34, height: 34)
                .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: label).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineLimit(1)
                Text(verbatim: value).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(10)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.mvEdge, lineWidth: 1))
    }
}

/// Tiles two to a row.
struct MvFactGrid: View {
    let facts: [(icon: String, label: String, value: String)]

    var body: some View {
        if !facts.isEmpty {
            LazyVGrid(columns: [GridItem(.flexible(), spacing: 8), GridItem(.flexible(), spacing: 8)], spacing: 8) {
                ForEach(Array(facts.enumerated()), id: \.offset) { _, fact in MvFactTile(icon: fact.icon, label: fact.label, value: fact.value) }
            }
        }
    }
}

/// The admin's picture of a vehicle type, or the truck symbol.
struct MvVehicleImage: View {
    @Environment(\.wa) private var wa
    let image: String?
    let size: CGFloat

    var body: some View {
        if image != nil {
            MediaThumb(url: image, fit: true).frame(width: size, height: size)
        } else {
            Image(systemName: "box.truck.fill").font(.system(size: size * 0.55)).foregroundStyle(wa.red).frame(width: size, height: size)
        }
    }
}

/// The vehicle types the customer accepts, the first one marked as their first choice.
struct MvAskedTypes: View {
    @Environment(\.wa) private var wa
    let types: [VehicleTypeDto]

    var body: some View {
        if !types.isEmpty {
            FlowLayout(spacing: 8) {
                ForEach(Array(types.enumerated()), id: \.offset) { index, type in
                    HStack(spacing: 6) {
                        MvVehicleImage(image: type.image, size: 26)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(verbatim: type.name ?? "").font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk)
                            if index == 0 && types.count > 1 { Text(L("mv_first_choice")).font(.dorr(10, .bold)).foregroundStyle(wa.red) }
                        }
                    }
                    .padding(.horizontal, 10)
                    .padding(.vertical, 7)
                    .background(index == 0 ? wa.red.opacity(0.10) : wa.mvWell, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(index == 0 ? wa.red.opacity(0.5) : .clear, lineWidth: 1))
                }
            }
        }
    }
}

/// The two ends of the move, each with its floor and lift as chips (the address only once the job is ours).
struct MvEndsCard: View {
    @Environment(\.wa) private var wa
    let move: MovingRequestDto

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            endBlock(L("mv_pickup"), move.pickup, Green)
            Rectangle().fill(wa.mvEdge).frame(height: 1)
            endBlock(L("mv_dropoff"), move.dropoff, wa.red)
            if move.pickup?.address == nil { Text(L("mv_address_hidden")).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineSpacing(2) }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20)
    }

    @ViewBuilder private func endBlock(_ title: String, _ end: MovingEndDto?, _ dot: Color) -> some View {
        if let end {
            VStack(alignment: .leading, spacing: 6) {
                HStack(spacing: 8) {
                    Circle().fill(dot).frame(width: 10, height: 10)
                    Text(verbatim: title).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
                }
                Text(verbatim: notBlank(end.area) ?? L("mv_area_unknown")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                if let address = notBlank(end.address) { Text(verbatim: address).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(3) }
                FlowLayout(spacing: 6) {
                    if let floor = end.floor.flatMap({ Int($0.text) }) {
                        MvChip(icon: "square.3.layers.3d", text: floor == 0 ? L("mv_floor_ground") : L("mv_floor_n", floor), color: Slate)
                    }
                    if let lift = end.elevator {
                        MvChip(icon: "arrow.up.arrow.down.square", text: L(lift ? "mv_elevator_yes" : "mv_elevator_no"), color: lift ? Green : Amber)
                    }
                    if let entrance = notBlank(end.entrance) { MvChip(icon: nil, text: entrance, color: Slate) }
                }
            }
        }
    }
}

/// The photos the customer added, side by side.
struct MvPhotos: View {
    let urls: [String]

    var body: some View {
        if !urls.isEmpty {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(urls, id: \.self) { url in
                        MediaThumb(url: url).frame(width: 104, height: 104).clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                }
            }
        }
    }
}

/// The plate as written on it, in a framed box.
struct MvPlate: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        if notBlank(text) != nil {
            Text(verbatim: "\u{200E}" + text).font(.system(size: 12, weight: .black, design: .monospaced)).foregroundStyle(wa.settingsInk).lineLimit(1)
                .padding(.horizontal, 7)
                .padding(.vertical, 2)
                .overlay(RoundedRectangle(cornerRadius: 6, style: .continuous).stroke(wa.settingsInk.opacity(0.6), lineWidth: 1.5))
        }
    }
}

/// One of my vehicles to carry the offer: its type picture, name, plate and make; one that fits the request says so, one that
/// can't be offered says why (and can't be picked).
struct MvVehicleOption: View {
    @Environment(\.wa) private var wa
    let vehicle: MyVehicleDto
    let selected: Bool
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(spacing: 10) {
                MvVehicleImage(image: vehicle.vehicleType?.image, size: 40)
                    .frame(width: 52, height: 52)
                    .background(wa.red.opacity(vehicle.usable ? 0.10 : 0.04), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 4) {
                    HStack(spacing: 8) {
                        Text(verbatim: vehicle.vehicleType?.name ?? L("vh_vehicle")).font(.dorr(14, .heavy))
                            .foregroundStyle(wa.settingsInk.opacity(vehicle.usable ? 1 : 0.55)).lineLimit(1)
                        MvPlate(text: vehicle.plate())
                    }
                    let facts = [vehicle.make, vehicle.model, vehicle.year.map { String($0) }, vehicle.color].compactMap { notBlank($0) }
                    if !facts.isEmpty { Text(verbatim: facts.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                    if !vehicle.usable {
                        Text(verbatim: vehicle.problemText ?? vehicleStatusLabel(vehicle.status)).font(.dorr(11))
                            .foregroundStyle(vehicle.problem == "vehicle_type_not_asked" ? Slate : Amber).lineSpacing(2)
                    } else if vehicle.fits != false {
                        Text(L("mv_vehicle_fits")).font(.dorr(11, .bold)).foregroundStyle(Green)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                ZStack {
                    Circle().stroke(selected ? wa.red : wa.settingsMut.opacity(vehicle.usable ? 0.5 : 0.2), lineWidth: 2)
                    if selected {
                        Circle().fill(wa.red)
                        Image(systemName: "checkmark").font(.system(size: 11, weight: .bold)).foregroundStyle(.white).transition(.scale.combined(with: .opacity))
                    }
                }
                .frame(width: 24, height: 24)
            }
            .padding(12)
            .background(selected ? wa.red.opacity(wa.night ? 0.18 : 0.06) : wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(selected ? wa.red : wa.mvEdge, lineWidth: selected ? 2 : 1))
            .contentShape(Rectangle())
            .animation(.easeOut(duration: 0.2), value: selected)
        }
        .buttonStyle(.plain)
        .disabled(!vehicle.usable)
    }
}

/// A number with − and + (workers, trips), the number sliding as it changes.
struct MvStepper: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    @Binding var value: Int
    let range: ClosedRange<Int>
    var hint: String?

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: icon).font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.red).frame(width: 22)
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: label).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                if let hint = notBlank(hint) { Text(verbatim: hint).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineSpacing(2) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            circle("minus", value > range.lowerBound) { value -= 1 }
            Text(verbatim: "\(value)").font(.dorr(17, .black)).foregroundStyle(wa.settingsInk).frame(width: 44)
                .contentTransition(.numericText())
                .animation(.easeOut(duration: 0.2), value: value)
            circle("plus", value < range.upperBound) { value += 1 }
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 10)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.mvEdge, lineWidth: 1))
    }

    private func circle(_ icon: String, _ enabled: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 14, weight: .bold)).foregroundStyle(enabled ? wa.red : wa.settingsMut.opacity(0.4))
                .frame(width: 34, height: 34)
                .background(enabled ? wa.red.opacity(0.12) : wa.mvWell, in: Circle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

/// The symbol of an `includes` / extras key.
func includeIcon(_ key: String) -> String {
    switch key {
    case "packing": return "shippingbox.fill"
    case "disassembly": return "wrench.and.screwdriver.fill"
    case "crane": return "arrow.up.to.line.compact"
    case "technician": return "person.badge.key.fill"
    default: return "checkmark"
    }
}

/// What the offer includes, as a tile to tick: the icon, the name, and «طلبه العميل» when the customer asked for it.
struct MvIncludeTile: View {
    @Environment(\.wa) private var wa
    let key: String
    let on: Bool
    let asked: Bool
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 6) {
                HStack {
                    Image(systemName: includeIcon(key)).font(.system(size: 16, weight: .semibold)).foregroundStyle(on ? wa.red : wa.settingsMut)
                    Spacer(minLength: 0)
                    ZStack {
                        RoundedRectangle(cornerRadius: 5, style: .continuous).fill(on ? wa.red : .clear)
                        RoundedRectangle(cornerRadius: 5, style: .continuous).stroke(on ? wa.red : wa.settingsMut.opacity(0.5), lineWidth: 1.5)
                        if on { Image(systemName: "checkmark").font(.system(size: 10, weight: .bold)).foregroundStyle(.white) }
                    }
                    .frame(width: 18, height: 18)
                }
                Text(verbatim: includeLabel(key)).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk)
                Text(L(asked ? "mv_customer_asked" : "mv_customer_not_asked")).font(.dorr(10, .semibold)).foregroundStyle(asked ? wa.red : wa.settingsMut)
            }
            .padding(10)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(on ? wa.red.opacity(0.10) : wa.settingsCard, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(on ? wa.red : wa.mvEdge, lineWidth: on ? 1.5 : 1))
            .contentShape(Rectangle())
            .animation(.easeOut(duration: 0.2), value: on)
        }
        .buttonStyle(.plain)
    }
}

/// What the price means, as it is typed: what the customer pays (with the tax) and what reaches the establishment.
struct MvPriceBreakdown: View {
    @Environment(\.wa) private var wa
    let b: OfferBreakdown
    let currency: String?

    var body: some View {
        VStack(spacing: 6) {
            line(L("mv_bd_price"), formatMinor(b.price, currency))
            if b.tax > 0 { line(L("mv_bd_tax"), "+ " + formatMinor(b.tax, currency)) }
            line(L("mv_bd_customer_pays"), formatMinor(b.total, currency), strong: true)
            Rectangle().fill(wa.settingsMut.opacity(0.2)).frame(height: 1)
            if b.commission > 0 { line(L("mv_bd_commission"), "− " + formatMinor(b.commission, currency)) }
            line(L("mv_bd_you_get"), formatMinor(b.net, currency), strong: true, color: Green)
        }
        .padding(12)
        .background(wa.mvWell, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
    }

    private func line(_ label: String, _ value: String, strong: Bool = false, color: Color? = nil) -> some View {
        HStack {
            Text(verbatim: label).font(.dorr(13, strong ? .bold : .regular)).foregroundStyle(strong ? wa.settingsInk : wa.settingsMut)
                .frame(maxWidth: .infinity, alignment: .leading)
            Text(verbatim: value).font(.dorr(strong ? 15 : 13, strong ? .black : .semibold)).foregroundStyle(color ?? wa.settingsInk)
        }
    }
}

/// The establishment's own offer on a request: the price big, its status, vehicle, arrival and how long it holds, what it includes;
/// changing or taking it back while the customer hasn't chosen.
struct MvMyOfferCard: View {
    @Environment(\.wa) private var wa
    let offer: MovingOfferDto
    let canManage: Bool
    let busy: Bool
    let onEdit: () -> Void
    let onWithdraw: () -> Void

    var body: some View {
        let color = offerStatusColor(offer.status)
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 6) {
                Image(systemName: "checkmark.circle.fill").font(.system(size: 17)).foregroundStyle(color)
                Text(L("mv_your_offer")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                StatusPill(text: offerStatusLabel(offer.status), color: color)
            }
            Text(verbatim: formatMinor(offer.priceMinor ?? 0, offer.currencyCode)).font(.dorr(26, .black)).foregroundStyle(wa.settingsInk)
            FlowLayout(spacing: 6) {
                if let type = offer.vehicle?.type?.name { MvChip(icon: "box.truck.fill", text: type) }
                if let workers = offer.workersCount, workers > 0 { MvChip(icon: nil, text: L("mv_workers_n", workers), color: Slate) }
                if let trips = offer.tripsCount, trips > 1 { MvChip(icon: nil, text: L("mv_trips_n", trips), color: Slate) }
                ForEach(offer.included().filter { $0 != "tax" }.sorted(), id: \.self) { key in MvChip(icon: includeIcon(key), text: includeLabel(key), color: Slate) }
            }
            InfoLine(L("mv_arrival"), dateTimeText(offer.arrivalAt))
            InfoLine(L("mv_valid_until"), dateTimeText(offer.validUntil))
            if offer.status == "awaiting_approval" { Text(L("mv_awaiting_approval_hint")).font(.dorr(12)).foregroundStyle(Amber).lineSpacing(3) }
            if canManage && ["sent", "awaiting_approval"].contains(offer.status ?? "") {
                HStack(spacing: 10) {
                    MvOutlineButton(text: L("mv_edit_offer"), color: wa.red, busy: busy, action: onEdit)
                    MvOutlineButton(text: L("mv_withdraw"), color: Red, busy: busy, action: onWithdraw)
                }
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(LinearGradient(colors: [color.opacity(0.12), wa.settingsCard], startPoint: .top, endPoint: .bottom),
                    in: RoundedRectangle(cornerRadius: 22, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(color.opacity(0.35), lineWidth: 1))
    }
}

struct MvOutlineButton: View {
    let text: String
    let color: Color
    let busy: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(14, .bold)).foregroundStyle(color)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 11)
                .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(color.opacity(0.6), lineWidth: 1.5))
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(busy)
    }
}

/// A green line that pops in: «أُرسل عرضك إلى العميل.» and the like.
struct MvDoneBanner: View {
    let text: String?

    var body: some View {
        if let text {
            HStack(spacing: 8) {
                Image(systemName: "checkmark.circle.fill").font(.system(size: 19)).foregroundStyle(Green)
                Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(Green).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(12)
            .background(Green.opacity(0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .transition(.scale(scale: 0.9).combined(with: .opacity))
        }
    }
}

/// Two choices side by side (the asked time / another time…), the picked one filled.
struct MvChoice: View {
    @Environment(\.wa) private var wa
    let first: String
    let second: String
    @Binding var firstOn: Bool

    var body: some View {
        HStack(spacing: 8) {
            option(true, first).layoutPriority(1.6)
            option(false, second).layoutPriority(1)
        }
    }

    private func option(_ value: Bool, _ label: String) -> some View {
        let on = firstOn == value
        return Button { withAnimation(.easeOut(duration: 0.2)) { firstOn = value } } label: {
            HStack(spacing: 6) {
                Circle().fill(on ? wa.red : .clear).frame(width: 10, height: 10)
                    .overlay(Circle().stroke(on ? wa.red : wa.settingsMut, lineWidth: 1.5))
                Text(verbatim: label).font(.dorr(12, .bold)).foregroundStyle(on ? wa.red : wa.settingsInk).lineLimit(2)
            }
            .padding(.horizontal, 10)
            .padding(.vertical, 11)
            .frame(maxWidth: .infinity)
            .background(on ? wa.red.opacity(0.10) : wa.settingsCard, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(on ? wa.red : wa.settingsMut.opacity(0.25), lineWidth: on ? 1.5 : 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// A warning line on a soft amber tile.
struct MvWarning: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        HStack(alignment: .top, spacing: 8) {
            Image(systemName: "box.truck.fill").font(.system(size: 15)).foregroundStyle(Amber)
            Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(12)
        .background(Amber.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}
