import SwiftUI

// driverApp `ui/TripDetailViews.kt` — what the captain needs around the ride (rides_tasks batch 3): how many ride and the
// rider's pickup note (D-60, D-61), the time / distance left (D-129), and the waiting clock that warns before the free
// minutes end (D-122).

/// D-60 / D-61: "3 riders" and the note, on the request card and on the ride; R-13: "Pick up Mona" when the ride was booked
/// for someone else (first name only — the app chat still reaches the rider who booked).
struct RiderDetails: View {
    @Environment(\.pal) private var pal
    let passengers: Int?
    let note: String?
    var beneficiary: String? = nil
    var pickupPoint: PickupPointDto? = nil
    var dropoffPoint: PickupPointDto? = nil
    var fromCircle = false
    var options: [AddonDto] = []

    init(passengers: Int?, note: String?, beneficiary: String? = nil, pickupPoint: PickupPointDto? = nil,
         dropoffPoint: PickupPointDto? = nil, fromCircle: Bool = false, options: [AddonDto] = []) {
        self.passengers = passengers
        self.note = note
        self.beneficiary = beneficiary
        self.pickupPoint = pickupPoint
        self.dropoffPoint = dropoffPoint
        self.fromCircle = fromCircle
        self.options = options
    }

    private func blank(_ s: String?) -> Bool { (s ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }

    var body: some View {
        if (passengers ?? 1) <= 1 && blank(note) && blank(beneficiary) && pickupPoint == nil && dropoffPoint == nil && !fromCircle && options.isEmpty {
            EmptyView()
        } else {
            VStack(alignment: .leading, spacing: 6) {
                // D-59, D-390: what the rider added — before accepting, so nothing is a surprise at the door.
                if !options.isEmpty {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 6) {
                            ForEach(Array(options.enumerated()), id: \.offset) { _, o in
                                let tint = o.key == "priority" ? pal.orange : (o.key == "service_animal" ? pal.success : pal.navy)
                                HStack(spacing: 5) {
                                    DorrIcon(addonIcon(o.key ?? ""), size: 16, tint: tint)
                                    Text(verbatim: (o.label ?? "") + ((o.count ?? 0) > 1 ? " ×\(o.count ?? 0)" : ""))
                                        .font(.dorr(12.5, .semibold)).foregroundStyle(tint)
                                }
                                .padding(.horizontal, 10).padding(.vertical, 5)
                                .background(tint.opacity(0.12), in: Capsule())
                            }
                        }
                    }
                }
                // D-INN-07 DORR Circle: the rider picked this captain from their favourites.
                if fromCircle {
                    HStack(spacing: 6) {
                        DorrIcon("heart.fill", size: 16, tint: pal.orange)
                        Text(L("trip_from_circle")).font(.dorr(13, .semibold)).foregroundStyle(pal.orange)
                    }
                    .padding(.horizontal, 12).padding(.vertical, 5)
                    .background(pal.orange.opacity(0.12), in: Capsule())
                }
                if let pickupPoint { PointRow(title: L("trip_pickup_point"), point: pickupPoint) }
                if let dropoffPoint { PointRow(title: L("trip_dropoff_point"), point: dropoffPoint) }
                if let name = beneficiary, !blank(name) {
                    HStack(spacing: 8) {
                        DorrIcon("mappin.and.ellipse", size: 18, tint: pal.navy)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("trip_beneficiary", name)).font(.dorr(14, .bold)).foregroundStyle(pal.navy)
                            Text(L("trip_beneficiary_hint")).font(.dorr(11)).foregroundStyle(pal.ink.opacity(0.6))
                        }
                        Spacer(minLength: 0)
                    }
                    .padding(10)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(pal.navy.opacity(0.08), in: RoundedRectangle(cornerRadius: 12))
                }
                if let n = passengers, n > 1 {
                    HStack(spacing: 6) {
                        DorrIcon("person.2.fill", size: 16, tint: pal.navy)
                        Text(L("trip_passengers", n)).font(.dorr(13, .semibold)).foregroundStyle(pal.navy)
                    }
                    .padding(.horizontal, 12).padding(.vertical, 5)
                    .background(pal.navy.opacity(0.08), in: Capsule())
                }
                if let note, !blank(note) {
                    HStack(alignment: .top, spacing: 8) {
                        DorrIcon("square.and.pencil", size: 18, tint: pal.orange)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("trip_pickup_note")).font(.dorr(11)).foregroundStyle(pal.ink.opacity(0.6))
                            Text(verbatim: "“\(note)”").font(.dorr(14, .medium)).italic().foregroundStyle(pal.ink)
                        }
                        Spacer(minLength: 0)
                    }
                    .padding(10)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(pal.orange.opacity(0.08), in: RoundedRectangle(cornerRadius: 12))
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.top, 10)
        }
    }
}

/// D-111: the approved point to meet at (a gate, a terminal…) with how to find it — where the navigation goes.
private struct PointRow: View {
    @Environment(\.pal) private var pal
    let title: String
    let point: PickupPointDto

    var body: some View {
        HStack(spacing: 8) {
            DorrIcon("mappin.and.ellipse", size: 20, tint: pal.navy)
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: title).font(.dorr(11)).foregroundStyle(pal.ink.opacity(0.6))
                Text(verbatim: "\(point.name ?? "") · \(point.kindLabel ?? "")").font(.dorr(14, .bold)).foregroundStyle(pal.navy)
                if let i = point.instructions, !i.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                    Text(verbatim: i).font(.dorr(12)).italic().foregroundStyle(pal.ink)
                }
            }
            Spacer(minLength: 0)
        }
        .padding(10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(pal.navy.opacity(0.08), in: RoundedRectangle(cornerRadius: 12))
    }
}

/// `String.format("%.1f", x)` in the app's language.
func oneDecimal(_ x: Double) -> String {
    String(format: "%.1f", locale: Locale(identifier: DriverPrefs.locale), x)
}

/// D-129: "6 min · 2.4 km to the pickup", a breathing dot while the numbers update.
struct ProgressChip: View {
    @Environment(\.pal) private var pal
    let progress: ProgressDto?

    init(progress: ProgressDto?) { self.progress = progress }

    var body: some View {
        if let progress {
            let target: String = {
                switch progress.to ?? "pickup" {
                case "pickup": return L("trip_to_pickup_short")
                case "stop": return L("trip_to_stop_short")
                default: return L("trip_to_destination_short")
                }
            }()
            let minutes = Swift.max(((progress.etaS ?? 0) + 59) / 60, 1)
            let text = L("trip_progress", minutes, oneDecimal(Double(progress.distanceM ?? 0) / 1000.0), target)
            HStack(spacing: 0) {
                // The breathing dot: 0.35 → 1, 900 ms each way.
                TimelineView(.animation) { ctx in
                    let phase = ctx.date.timeIntervalSinceReferenceDate.truncatingRemainder(dividingBy: 1.8) / 0.9
                    let f = phase <= 1 ? phase : 2 - phase
                    Circle().fill(pal.success).frame(width: 9, height: 9).opacity(0.35 + 0.65 * f)
                }
                .frame(width: 9, height: 9)
                Spacer().frame(width: 10)
                DorrIcon("car.fill", size: 20, tint: pal.navy)
                Spacer().frame(width: 8)
                Text(verbatim: text).font(.dorr(15, .bold)).foregroundStyle(pal.navy)
                    .id(text)
                    .transition(.opacity)
                Spacer(minLength: 0)
            }
            .animation(.tween(300), value: text)
            .padding(.horizontal, 14).padding(.vertical, 12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(pal.navy.opacity(0.07), in: RoundedRectangle(cornerRadius: 16))
        }
    }
}

/// D-122: the free waiting on the status band — white, then an amber pulsing pill near the end, then paid.
struct WaitClock: View {
    @Environment(\.pal) private var pal
    let left: Int
    let warnBeforeS: Int

    init(left: Int, warnBeforeS: Int) {
        self.left = left
        self.warnBeforeS = warnBeforeS
    }

    var body: some View {
        let warning = left >= 1 && left <= warnBeforeS
        let pill: Color = left <= 0 ? pal.danger : (warning ? pal.orange : Color.white.opacity(0.18))
        TimelineView(.animation(paused: !warning)) { ctx in
            // 1 → 1.07, 500 ms each way, while the warning is on.
            let phase = ctx.date.timeIntervalSinceReferenceDate.truncatingRemainder(dividingBy: 1.0) / 0.5
            let f = phase <= 1 ? phase : 2 - phase
            HStack(spacing: 6) {
                DorrIcon("hourglass.bottomhalf.filled", size: 16, tint: .white)
                Text(verbatim: left <= 0 ? L("trip_waiting_paid") : (warning ? L("trip_waiting_ending", clock(left)) : L("trip_waiting_free", clock(left))))
                    .font(.dorr(16, .bold)).foregroundStyle(.white)
            }
            .padding(.horizontal, 12).padding(.vertical, 6)
            .background(pill, in: Capsule())
            .animation(.tween(400), value: pill)
            .scaleEffect(warning ? 1 + 0.07 * f : 1)
        }
        .padding(.top, 8)
    }
}

/// The add-on's icon (Material → SF Symbols).
func addonIcon(_ key: String) -> String {
    switch key {
    case "wait_save": return "hourglass.bottomhalf.filled"
    case "priority": return "speedometer"
    case "child_seats": return "figure.2.and.child.holdinghands"
    case "pet": return "pawprint.fill"
    case "service_animal": return "figure.roll"
    default: return "suitcase.rolling.fill"
    }
}
