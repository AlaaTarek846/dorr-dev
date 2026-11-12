import SwiftUI

// androidApp `aichat/AiServiceCards.kt` — DORR's own service cards under a DORR AI answer (Modules/AI
// AiServiceActions). Today: «احجز مشوارك مع دُرر», the trip DORR AI understood, ready to book in DORR Rides.

/// The DORR service cards under an answer.
struct AiServiceActionCards: View {
    let actions: [AiServiceActionDto]

    var body: some View {
        ForEach(Array(actions.enumerated()), id: \.offset) { _, action in
            if action.type == "ride_booking" { RideBookingCard(action: action) }
        }
    }
}

/// «احجز مشوارك مع دُرر»: the place DORR AI found on the map, and what else it understood (category, time,
/// riders, payment, a note) — the rider can pick another of the places found, then one tap opens rides with it
/// all filled in and the place on the map to confirm. Nothing is booked from the chat.
private struct RideBookingCard: View {
    @Environment(\.wa) private var wa
    let action: AiServiceActionDto
    @State private var chosen: RideAiPlaceDto?
    @State private var started = false
    @State private var bob = false
    @State private var shine = false

    private let navy = AppColors.brandNavy
    private let orange = AppColors.brandOrange

    var body: some View {
        let c = wa.ai
        VStack(alignment: .leading, spacing: 0) {
            header
            VStack(alignment: .leading, spacing: 0) {
                // Where to.
                HStack(spacing: 10) {
                    Image(systemName: "mappin.circle.fill").font(.system(size: 19)).foregroundStyle(orange)
                        .frame(width: 34, height: 34)
                        .background(orange.opacity(0.14), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    VStack(alignment: .leading, spacing: 1) {
                        Text(L("ai_ride_card_to")).font(.dorr(11)).foregroundStyle(c.mut)
                        Text(verbatim: chosen.map { $0.name ?? $0.address ?? "" } ?? (action.subtitle ?? ""))
                            .font(.dorr(14, .bold)).foregroundStyle(c.ink).lineLimit(2)
                        if let address = chosen?.address, !address.isEmpty, address != chosen?.name {
                            Text(verbatim: address).font(.dorr(11.5)).foregroundStyle(c.mut).lineLimit(1)
                        }
                    }
                    Spacer(minLength: 0)
                }

                // The other places found: one tap to switch.
                let others = (action.places ?? []).filter { $0 != chosen }
                if !others.isEmpty {
                    Text(L("ai_ride_card_or")).font(.dorr(11)).foregroundStyle(c.mut).padding(.top, 8).padding(.bottom, 4)
                    FlowLayout(spacing: 6) {
                        ForEach(Array(others.enumerated()), id: \.offset) { _, place in
                            Button { withAnimation(.easeInOut(duration: 0.2)) { chosen = place } } label: {
                                Text(verbatim: place.name ?? place.address ?? "").font(.dorr(11.5)).foregroundStyle(c.ink).lineLimit(1)
                                    .padding(.horizontal, 10).padding(.vertical, 5)
                                    .overlay(Capsule().stroke(c.line, lineWidth: 1))
                            }
                            .buttonStyle(AiPressStyle())
                        }
                    }
                }

                // What else was understood.
                let chips = infoChips
                if !chips.isEmpty {
                    FlowLayout(spacing: 6) {
                        ForEach(Array(chips.enumerated()), id: \.offset) { _, chip in
                            HStack(spacing: 4) {
                                Image(systemName: chip.icon).font(.system(size: 11)).foregroundStyle(navy.opacity(0.8))
                                Text(verbatim: chip.label).font(.dorr(11.5, .semibold)).foregroundStyle(c.ink).lineLimit(1)
                            }
                            .padding(.horizontal, 10).padding(.vertical, 5)
                            .overlay(Capsule().stroke(c.line, lineWidth: 1))
                        }
                    }
                    .padding(.top, 10)
                }
                if let clarify = action.clarify {
                    Text(verbatim: clarify).font(.dorr(12.5, .semibold)).foregroundStyle(orange).padding(.top, 8)
                }

                Button {
                    var booking = action
                    booking.destination = chosen
                    RidesLink.shared.book(booking)
                } label: {
                    HStack(spacing: 8) {
                        Text(L(chosen != nil ? "ai_ride_card_book" : "ai_ride_card_pick")).font(.dorr(14.5, .heavy))
                        Image(systemName: "arrow.forward").font(.system(size: 15, weight: .bold)).flipsForRightToLeftLayoutDirection(true)
                    }
                    .foregroundStyle(.white)
                    .frame(maxWidth: .infinity).frame(height: 46)
                    .background(LinearGradient(colors: [orange, Color(hex: 0xFF8E6E)], startPoint: .leading, endPoint: .trailing),
                                in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                .buttonStyle(AiPressStyle())
                .padding(.top, 12)
                Text(L("ai_ride_card_hint")).font(.dorr(10.5)).foregroundStyle(c.mut).padding(.top, 6)
            }
            .padding(14)
        }
        .frame(maxWidth: 340, alignment: .leading)
        .background(c.surface)
        .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(c.line, lineWidth: 1))
        .padding(.top, 10)
        .onAppear {
            if !started {
                started = true
                chosen = action.destination
            }
            withAnimation(.easeInOut(duration: 0.9).repeatForever(autoreverses: true)) { bob = true }
            withAnimation(.linear(duration: 2.6).repeatForever(autoreverses: false)) { shine = true }
        }
    }

    /// DORR's colours, a car that breathes, the title.
    private var header: some View {
        HStack(spacing: 10) {
            Image(systemName: "car.fill").font(.system(size: 18)).foregroundStyle(.white)
                .scaleEffect(bob ? 1.06 : 0.94)
                .frame(width: 38, height: 38)
                .background(Color.white.opacity(0.16), in: Circle())
            VStack(alignment: .leading, spacing: 1) {
                Text(verbatim: action.title ?? L("ai_ride_card_title")).font(.dorr(15, .heavy)).foregroundStyle(.white)
                Text(L("ai_ride_card_sub")).font(.dorr(11.5)).foregroundStyle(.white.opacity(0.85))
            }
            Spacer(minLength: 0)
            Image(systemName: "sparkles").font(.system(size: 14)).foregroundStyle(.white.opacity(0.9))
        }
        .padding(.horizontal, 14).padding(.vertical, 12)
        .frame(maxWidth: .infinity)
        .background(
            LinearGradient(colors: [navy, navy.opacity(0.92), orange],
                           startPoint: UnitPoint(x: shine ? 0.2 : -0.5, y: 0.5), endPoint: UnitPoint(x: shine ? 1.4 : 1, y: 0.5))
        )
    }

    private struct Chip { let icon: String; let label: String }

    private var infoChips: [Chip] {
        guard let u = action.understood else { return [] }
        var chips: [Chip] = []
        if let name = u.categoryName { chips.append(Chip(icon: "car.fill", label: name)) }
        if let at = parseRideTime(u.when) {
            let f = DateFormatter()
            f.locale = Locale(identifier: AppLocale.current)
            f.dateFormat = "EEE d MMM · h:mm a"
            chips.append(Chip(icon: "clock.fill", label: f.string(from: at)))
        }
        if let p = u.passengers { chips.append(Chip(icon: "person.2.fill", label: "\(p)")) }
        if let pay = u.payment { chips.append(Chip(icon: "banknote.fill", label: paymentLabel(pay))) }
        if let note = u.note { chips.append(Chip(icon: "note.text", label: note)) }
        return chips
    }

    private func paymentLabel(_ value: String) -> String {
        switch value {
        case "cash": return L("ai_ride_pay_cash")
        case "wallet": return L("ai_ride_pay_wallet")
        case "card": return L("ai_ride_pay_card")
        default: return value
        }
    }
}
