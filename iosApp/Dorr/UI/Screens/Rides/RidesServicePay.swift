import SwiftUI

// androidApp `rides/RidesServicePay.kt` — the payment of a booked service (bus, boat, driver, lessons — O-67) and the
// link from a tapped notification to that service's booking.

/// A tapped notification of a booked service: which one and its booking. The rides screen opens that service, and
/// the service opens that booking.
@MainActor
final class ServiceLink: ObservableObject {
    static let shared = ServiceLink()
    @Published var pending: (kind: String, uuid: String?)?

    /// The booking waiting for this service, once.
    func take(_ kind: String) -> String? {
        guard let p = pending, p.kind == kind else { return nil }
        pending = nil
        return p.uuid
    }
}

/// O-67: the payment screen's purposes for the booked services (server: ServiceBookingPurpose).
enum ServicePay {
    static let bus = "bus_booking"
    static let marine = "marine_booking"
    static let hire = "driver_hire"
    static let lessons = "lesson_pack"

    /// The app's one payment screen for this booking — coupon, wallet, «top up» and back to the same page.
    @MainActor
    static func open(_ purpose: String, _ uuid: String, onPaid: @escaping () -> Void) {
        CheckoutLauncher.shared.open(CheckoutRequest(purpose: purpose, reference: ["booking_uuid": .string(uuid)]), onPaid: onPaid)
    }
}

/// A booking held for its payment — the time left (the phone's clock) and «pay now». A fresh booking opens the
/// payment screen at once (`autoOpen`); paid → `onPaid` reloads the booking.
struct ServicePayCard: View {
    @Environment(\.wa) private var wa
    let purpose: String
    let uuid: String
    let expiresAt: String?
    let autoOpen: Bool
    let onPaid: () -> Void
    @State private var pulse = false

    var body: some View {
        let ends = parseRideTime(expiresAt)
        TimelineView(.periodic(from: .now, by: 1)) { context in
            let left = ends.map { max(Int($0.timeIntervalSince(context.date)), 0) }
            VStack(alignment: .leading, spacing: 12) {
                HStack(spacing: 12) {
                    Image(systemName: "banknote.fill").font(.system(size: 20)).foregroundStyle(AppColors.warning)
                        .frame(width: 44, height: 44).background(AppColors.warning.opacity(0.16), in: Circle())
                        .scaleEffect(pulse ? 1.12 : 1)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("svc_pay_waiting")).font(.dorr(16, .black)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: left.map { L("svc_pay_left", String(format: "%d:%02d", $0 / 60, $0 % 60)) } ?? L("svc_pay_hint"))
                            .font(.dorr(12.5)).foregroundStyle(wa.settingsMut)
                    }
                    Spacer(minLength: 0)
                }
                AccentButton(text: L("svc_pay_now"), enabled: left == nil || left! > 0) { ServicePay.open(purpose, uuid, onPaid: onPaid) }
            }
            .padding(16)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(AppColors.warning.opacity(0.5), lineWidth: 1.5))
        }
        .onAppear {
            withAnimation(.easeInOut(duration: 0.7).repeatForever()) { pulse = true }
        }
        .task(id: uuid) { if autoOpen { ServicePay.open(purpose, uuid, onPaid: onPaid) } }
    }
}
