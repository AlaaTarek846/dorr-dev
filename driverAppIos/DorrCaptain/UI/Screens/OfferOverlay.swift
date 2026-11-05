import AudioToolbox
import SwiftUI
import UIKit

// driverApp `ui/OfferOverlay.kt`.

/// A request, over everything while the app is open: what you earn in big, the pickup distance, the
/// trip, and a ring that empties with the time left. Rings and vibrates; times out by itself.
@MainActor
struct OfferOverlay: View {
    @ObservedObject private var duty = Duty.shared
    let onAccepted: () -> Void

    init(onAccepted: @escaping () -> Void) {
        self.onAccepted = onAccepted
    }

    var body: some View {
        let visible = duty.offer != nil && !duty.minimized
        ZStack {
            if visible, let offer = duty.offer {
                OfferCard(offer: offer, onAccepted: onAccepted)
                    // AnimatedVisibility(enter = slideInVertically { it } + fadeIn(), exit = fadeOut())
                    .transition(.asymmetric(insertion: .move(edge: .bottom).combined(with: .opacity), removal: .opacity))
            }
        }
        .animation(.tween(300), value: visible)
    }
}

/// O-36: the requests inbox — an icon with how many are open; tapping it brings the request cards back.
/// Shown while the card is put aside (or anywhere a screen wants it).
@MainActor
struct OffersInboxButton: View {
    @Environment(\.pal) private var pal
    @ObservedObject private var duty = Duty.shared
    @State private var pulse = false

    init() {}

    var body: some View {
        if !duty.offers.isEmpty && duty.minimized {
            ZStack(alignment: .topTrailing) {
                DorrIcon("tray.fill", size: 28, tint: .white)
                    .frame(width: 58, height: 58)
                    .background(Circle().fill(pal.orange))
                    .bounceClick { duty.minimize(false) }
                    .accessibilityLabel(L("offer_inbox"))
                    .frame(width: 64, height: 64)
                Text("\(duty.offers.count)").font(.dorr(12, .bold)).foregroundStyle(.white)
                    .frame(width: 24, height: 24)
                    .background(Circle().fill(pal.danger))
            }
            .frame(width: 64, height: 64)
            .scaleEffect(pulse ? 1.08 : 1)
            .onAppear { withAnimation(.tween(700).repeatForever(autoreverses: true)) { pulse = true } }
        }
    }
}

/// O-36: the other open requests, as chips over the card — tap one to see it.
@MainActor
private struct OfferSwitcher: View {
    @Environment(\.pal) private var pal
    @ObservedObject private var duty = Duty.shared
    let current: OfferDto

    var body: some View {
        if duty.offers.count >= 2 {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    Text(L("offer_count", duty.offers.count)).font(.dorr(13, .bold)).foregroundStyle(pal.ink)
                    ForEach(Array(duty.offers.enumerated()), id: \.offset) { _, o in
                        let on = o.uuid == current.uuid
                        Text("\(money(o.earningMinor, o.currency)) · \(km(o.pickupDistanceM)) km")
                            .font(.dorr(12, .bold)).foregroundStyle(on ? Color.white : pal.navy)
                            .padding(.horizontal, 12).padding(.vertical, 7)
                            .background(Capsule().fill(on ? pal.navy : pal.navy.opacity(0.08)))
                            .bounceClick { if let uuid = o.uuid { duty.focus(uuid) } }
                    }
                }
            }
            .frame(maxWidth: .infinity)
            .padding(.bottom, 10)
        }
    }
}

/// `String.format("%.1f", metres / 1000.0)`.
private func km(_ metres: Int?) -> String {
    String(format: "%.1f", locale: Locale(identifier: DriverPrefs.locale), Double(metres ?? 0) / 1000.0)
}

@MainActor
private struct OfferCard: View {
    @Environment(\.pal) private var pal
    let offer: OfferDto
    let onAccepted: () -> Void

    /// Follows the server on every refresh (a bidding request's time moves with each offer).
    @State private var left: Int
    @State private var busy = false
    @State private var error: String?
    @State private var contentHeight: CGFloat = 0

    init(offer: OfferDto, onAccepted: @escaping () -> Void) {
        self.offer = offer
        self.onAccepted = onAccepted
        _left = State(initialValue: max(offer.secondsLeft ?? 0, 1))
    }

    private var timerKey: String { "\(offer.uuid ?? "")|\(offer.secondsLeft ?? 0)" }

    var body: some View {
        let total = max(offer.ttlS ?? 15, 1)
        let progress = CGFloat(left) / CGFloat(total)
        ZStack(alignment: .bottom) {
            Color.black.opacity(0.55).ignoresSafeArea()
                .contentShape(Rectangle())
                .onTapGesture {}
            VStack(spacing: 0) {
                Spacer(minLength: 0)
                // Scrolls when it is taller than the screen (a bidding request with the keyboard up), so the
                // buttons at its foot — "send my offer" — are always reachable (O-42).
                ScrollView {
                    card(progress)
                        .padding(22)
                        .background(GeometryReader { g in
                            Color.clear
                                .onAppear { contentHeight = g.size.height }
                                .onChange(of: g.size.height) { contentHeight = $0 }
                        })
                }
                .frame(maxHeight: contentHeight > 0 ? contentHeight : nil)
                .background(TopRoundedShape(radius: 30).fill(pal.card).ignoresSafeArea(edges: .bottom))
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        // Sound and vibration while it waits (the notification already rang when the app was closed).
        .task(id: offer.uuid) { await ring() }
        .task(id: timerKey) {
            left = max(offer.secondsLeft ?? 0, 1)
            while left > 0 {
                try? await Task.sleep(nanoseconds: 1_000_000_000)
                if Task.isCancelled { return }
                left -= 1
            }
            Duty.shared.clearOffer(offer.uuid)
        }
    }

    @ViewBuilder
    private func card(_ progress: CGFloat) -> some View {
        VStack(spacing: 0) {
            // The grabber of a sheet, as in the DORR app's panels.
            Capsule().fill(pal.ink.opacity(0.15)).frame(width: 38, height: 4)
            VSpace(10)
            // O-36: more than one request open — switch between them, or put them aside behind the inbox icon.
            HStack(spacing: 0) {
                OfferSwitcher(current: offer).frame(maxWidth: .infinity, alignment: .leading)
                Text(L("offer_later")).font(.dorr(13, .semibold)).foregroundStyle(pal.navy)
                    .padding(.horizontal, 10).padding(.vertical, 6)
                    .bounceClick { Duty.shared.minimize(true) }
            }
            VSpace(4)
            ZStack {
                // Drawn like Android's Canvas: a 10 px ring, 6 px in from the edge, never mirrored.
                Circle().stroke(Color(white: 0.8).opacity(0.3), lineWidth: 3.4).padding(2)
                Circle()
                    .trim(from: 0, to: progress)
                    .stroke(left <= 5 ? pal.danger : pal.orange, style: StrokeStyle(lineWidth: 3.4, lineCap: .round))
                    .rotationEffect(.degrees(-90))
                    .padding(2)
                    .animation(.tween(900), value: progress)
                VStack(spacing: 0) {
                    Text(money(offer.earningMinor, offer.currency)).font(.dorr(20, .heavy)).foregroundStyle(pal.ink)
                    Text(verbatim: "\(left)s").font(.dorr(13)).foregroundStyle(pal.ink.opacity(0.6))
                }
            }
            .frame(width: 120, height: 120)
            .environment(\.layoutDirection, .leftToRight)
            VSpace(8)
            Text(L("offer_title")).font(.dorr(18, .bold)).foregroundStyle(pal.ink)
            // Block 06: a next trip — it starts once the ride I'm driving ends; the net is right above.
            NextTripBadge(offer.nextTrip)
            // O-28: the ride's category, and how the earning is made — before accepting.
            if let name = offer.category?.name {
                Text(name).font(.dorr(12, .bold)).foregroundStyle(.white)
                    .padding(.horizontal, 12).padding(.vertical, 4)
                    .background(Capsule().fill(pal.navy))
                    .padding(.top, 6)
            }
            if let m = offer.money {
                VStack(spacing: 0) {
                    MoneyLine(label: L("offer_fare"), value: money(m.fareMinor ?? 0, offer.currency))
                    if (m.serviceFeeMinor ?? 0) > 0 {
                        MoneyLine(label: L("offer_service_fee"), value: "−" + money(m.serviceFeeMinor ?? 0, offer.currency))
                    }
                    if (m.taxMinor ?? 0) > 0 && (m.taxOnDriver ?? true) {
                        MoneyLine(label: L("offer_tax"), value: "−" + money(m.taxMinor ?? 0, offer.currency))
                    }
                    MoneyLine(label: L("offer_net"), value: money(m.netMinor ?? 0, offer.currency), strong: true)
                }
                .padding(.horizontal, 14).padding(.vertical, 8)
                .frame(maxWidth: .infinity)
                .background(RoundedRectangle(cornerRadius: 14).fill(pal.ink.opacity(0.05)))
                .padding(.top, 10)
            }
            HStack(spacing: 8) {
                OfferChip(text: L("offer_pickup_km", km(offer.pickupDistanceM)))
                OfferChip(text: L("offer_away", String(((offer.pickupEtaS ?? 0) + 59) / 60)))
                OfferChip(text: offer.paymentMethod == "cash" ? L("offer_cash") : L("offer_paid"))
                OfferChip(text: offer.riderRating.map { String(format: "%.1f ★", locale: Locale(identifier: DriverPrefs.locale), $0) } ?? L("offer_new_rider"))
            }
            .padding(.top, 6)
            VSpace(14)
            OfferPoint(color: pal.navy, label: L("offer_pickup"), address: offer.origin?.address)
            ForEach(Array((offer.stops ?? []).enumerated()), id: \.offset) { _, stop in
                OfferPoint(color: pal.warning, label: "•", address: stop.address)
            }
            OfferPoint(color: pal.orange, label: L("offer_dropoff"), address: offer.destination?.address ?? L("offer_open_destination"), pin: true)
            // D-60, D-61: how many ride and the pickup note, before accepting.
            RiderDetails(passengers: offer.passengers, note: offer.pickupNote, beneficiary: offer.beneficiary?.name,
                         pickupPoint: offer.pickupPoint, dropoffPoint: offer.dropoffPoint, fromCircle: offer.fromCircle ?? false,
                         options: offer.options ?? [])
            // «راحتي»: how the rider likes the trip — before accepting.
            ComfortChips(offer.comfort)
            if let error {
                Text(error).font(.dorr(13)).foregroundStyle(pal.danger)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.top, 8)
            }
            VSpace(16)
            // Bidding: the rider's price, "accept for", or my own offer.
            if offer.bid != nil {
                BidSection(offer: offer, onAccepted: onAccepted, onDecline: { decline() })
            } else {
                WaWeightedRow(spacing: 12) {
                    Text(L("offer_decline")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                        .frame(maxWidth: .infinity)
                        .frame(height: 58)
                        .background(RoundedRectangle(cornerRadius: 18).fill(pal.ink.opacity(0.08)))
                        .bounceClick(enabled: !busy) { decline() }
                        .layoutWeight(1)
                    BigButton(L("offer_accept"), color: pal.success, busy: busy) { accept() }
                        .layoutWeight(2)
                }
            }
        }
        .frame(maxWidth: .infinity)
    }

    private func decline() {
        guard let uuid = offer.uuid else { return }
        Task { _ = await DriverApi.decline(uuid: uuid, body: [:]) }
        Duty.shared.clearOffer(uuid)
    }

    private func accept() {
        guard let uuid = offer.uuid else { return }
        busy = true
        Task {
            switch await DriverApi.accept(key: UUID().uuidString.lowercased(), uuid: uuid) {
            case .ok:
                Duty.shared.clearOffer(uuid)
                Duty.shared.setOnTrip(true)
                onAccepted()
            case let .failed(e):
                error = e.displayMessage
                try? await Task.sleep(nanoseconds: 1_500_000_000)
                Duty.shared.clearOffer(uuid)
            case .offline:
                error = L("error_offline")
            }
            busy = false
        }
    }

    /// The ring and the buzz while the card waits; stopped when it goes (the task is cancelled).
    private func ring() async {
        // Block 06: a next trip comes while the captain is driving — no ring, one short buzz, so it never distracts.
        let quiet = offer.nextTrip != nil
        // The notification of the same request goes (the card is the UI now).
        DriverDutyService.cancelRing()
        // Android plays the phone's ringtone; iOS gives apps no ringtone — the system alert sound instead.
        if AlertPrefs.sound() && !quiet { AudioServicesPlayAlertSound(SystemSoundID(1005)) }
        guard AlertPrefs.vibration() else { return }
        if quiet {
            UIImpactFeedbackGenerator(style: .medium).impactOccurred()
            return
        }
        // Waveform 0, 500, 400, 500, 400, 500: three buzzes 900 ms apart.
        for i in 0..<3 {
            if Task.isCancelled { return }
            AudioServicesPlaySystemSound(kSystemSoundID_Vibrate)
            if i < 2 { try? await Task.sleep(nanoseconds: 900_000_000) }
        }
    }
}

private struct MoneyLine: View {
    @Environment(\.pal) private var pal
    let label: String
    let value: String
    var strong = false

    var body: some View {
        HStack(spacing: 0) {
            Text(label).font(.dorr(13, strong ? .bold : .regular)).foregroundStyle(pal.ink.opacity(strong ? 1 : 0.65))
                .frame(maxWidth: .infinity, alignment: .leading)
            Text(value).font(.dorr(strong ? 15 : 13, strong ? .heavy : .semibold)).foregroundStyle(strong ? pal.success : pal.ink)
        }
        .padding(.vertical, 2)
    }
}

private struct OfferChip: View {
    @Environment(\.pal) private var pal
    let text: String

    var body: some View {
        Text(text).font(.dorr(12)).foregroundStyle(pal.ink).lineLimit(1)
            .padding(.horizontal, 10).padding(.vertical, 5)
            .background(Capsule().fill(pal.ink.opacity(0.07)))
    }
}

private struct OfferPoint: View {
    @Environment(\.pal) private var pal
    let color: Color
    let label: String
    let address: String?
    /// The drop-off: a pin instead of a dot.
    var pin = false

    var body: some View {
        HStack(alignment: .top, spacing: 0) {
            DorrIcon(pin ? "mappin" : "circle.fill", size: pin ? 18 : 12, tint: color)
                .padding(.top, 2)
                .frame(width: pin ? 20 : 14)
            HSpace(10)
            VStack(alignment: .leading, spacing: 0) {
                Text(label).font(.dorr(11)).foregroundStyle(pal.ink.opacity(0.55))
                Text(address ?? "—").font(.dorr(16, .semibold)).foregroundStyle(pal.ink).lineLimit(2)
            }
            Spacer(minLength: 0)
        }
        .padding(.vertical, 4)
        .frame(maxWidth: .infinity)
    }
}

/// A rectangle with only its top corners rounded (Compose `RoundedCornerShape(topStart, topEnd)`).
