import SwiftUI
import UIKit

// driverApp `ui/SafetySheet.kt` — the captain's safety on a ride (D-141, D-142, D-156): share the trip with someone they
// trust, and SOS — hold the red button to alert DORR's safety team with where they are; the emergency number is one tap
// away. The rider is never told.

private let SosRed = Color(hex: 0xA32D2D)

/// Gets (or makes) the trip's link and opens the phone's share sheet; an error message, or nil.
@MainActor
func shareTrip(_ uuid: String) async -> String? {
    switch await DriverApi.shareRide(uuid: uuid) {
    case let .ok(share, _):
        presentTripShare(L("trip_share_text", share.url ?? ""))
        return nil
    case let .failed(e):
        return e.displayMessage
    case .offline:
        return L("error_offline")
    }
}

/// Android's `Intent.createChooser(ACTION_SEND text/plain)`: the system share sheet over whatever is on screen.
@MainActor
func presentTripShare(_ text: String) {
    guard let root = UIApplication.shared.connectedScenes.compactMap({ $0 as? UIWindowScene })
        .flatMap(\.windows).first(where: \.isKeyWindow)?.rootViewController else { return }
    var top = root
    while let next = top.presentedViewController { top = next }
    let sheet = UIActivityViewController(activityItems: [text], applicationActivities: nil)
    sheet.popoverPresentationController?.sourceView = top.view
    sheet.popoverPresentationController?.sourceRect = CGRect(x: top.view.bounds.midX, y: top.view.bounds.midY, width: 0, height: 0)
    top.present(sheet, animated: true)
}

/// `Intent.ACTION_DIAL` with `tel:` — the phone's dialer with the number filled in.
@MainActor
func tripDial(_ number: String) {
    let digits = number.filter { $0.isNumber || $0 == "+" || $0 == "*" || $0 == "#" }
    if let url = URL(string: "tel:\(digits)") { UIApplication.shared.open(url) }
}

/// The SOS sheet's content — present it with `.sheet` (`.presentationDetents([.large])`, Android's `skipPartiallyExpanded`).
struct SosSheet: View {
    @Environment(\.pal) private var pal
    let t: TripDto
    let onClose: () -> Void
    var onReport: () -> Void = {}

    @State private var sent = false
    @State private var sending = false
    @State private var error: String?
    @State private var number: String
    @State private var sharing = false
    @State private var info: String?
    @State private var pressed = false

    init(t: TripDto, onClose: @escaping () -> Void, onReport: @escaping () -> Void = {}) {
        self.t = t
        self.onClose = onClose
        self.onReport = onReport
        _number = State(initialValue: t.safety?.emergencyNumber ?? "911")
    }

    private func alert() {
        if sent || sending { return }
        sending = true
        error = nil
        UIImpactFeedbackGenerator(style: .heavy).impactOccurred()
        Task {
            let here = await lastPosition()
            var body: JsonBody = [:]
            if let here { body["lat"] = here.0; body["lng"] = here.1 }
            switch await DriverApi.sos(uuid: t.uuid ?? "", body: body) {
            case let .ok(r, _): sent = true; if let n = r.emergencyNumber { number = n }
            case let .failed(e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            sending = false
        }
    }

    var body: some View {
        ScrollView {
            VStack(spacing: 0) {
                HStack(spacing: 8) {
                    DorrIcon("shield.fill", size: 24, tint: SosRed)
                    Text(L("trip_sos_title")).font(.dorr(20, .heavy)).foregroundStyle(pal.ink)
                }
                Spacer().frame(height: 18)
                ZStack {
                    if !sent {
                        // Two rings spreading out of the button, 1.6 s each, half a turn apart.
                        TimelineView(.animation) { ctx in
                            let ring = ctx.date.timeIntervalSinceReferenceDate.truncatingRemainder(dividingBy: 1.6) / 1.6
                            ZStack {
                                ForEach([0.0, 0.5], id: \.self) { offset in
                                    let p = (ring + offset).truncatingRemainder(dividingBy: 1)
                                    Circle().fill(SosRed.opacity((1 - p) * 0.22))
                                        .frame(width: 200, height: 200)
                                        .scaleEffect(0.62 + p * 0.38)
                                }
                            }
                        }
                    }
                    ZStack {
                        Circle().fill(sent ? pal.success : SosRed)
                        if sent {
                            Image(systemName: "checkmark").font(.system(size: 50, weight: .bold)).foregroundStyle(.white)
                                .transition(.scale.combined(with: .opacity))
                        } else if sending {
                            CircularProgress(color: .white, size: 42, lineWidth: 3)
                        } else {
                            Text(verbatim: "SOS").font(.dorr(36, .black)).foregroundStyle(.white)
                        }
                    }
                    .frame(width: 136, height: 136)
                    .scaleEffect(pressed ? 0.92 : 1)
                    .animation(.interpolatingSpring(mass: 1, stiffness: 1500, damping: 2 * 0.5 * 1500.0.squareRoot()), value: pressed)
                    .animation(.interpolatingSpring(mass: 1, stiffness: 1500, damping: 2 * 0.5 * 1500.0.squareRoot()), value: sent)
                    .contentShape(Circle())
                    .onLongPressGesture(minimumDuration: 0.5, perform: { if !sent { alert() } }, onPressingChanged: { p in pressed = sent ? false : p })
                }
                .frame(width: 200, height: 200)
                Text(L(sent ? "trip_sos_sent" : "trip_sos_hold"))
                    .font(.dorr(14, sent ? .bold : .regular))
                    .foregroundStyle(sent ? pal.success : pal.ink.opacity(0.65))
                    .multilineTextAlignment(.center)
                if let error {
                    Text(verbatim: error).font(.dorr(13)).foregroundStyle(pal.danger).multilineTextAlignment(.center).padding(.top, 6)
                }
                Spacer().frame(height: 18)
                Button { tripDial(number) } label: {
                    HStack(spacing: 8) {
                        Image(systemName: "phone.fill").foregroundStyle(.white)
                        Text(L("trip_sos_call", number)).font(.dorr(17, .bold)).foregroundStyle(.white)
                    }
                    .frame(maxWidth: .infinity).frame(height: 58)
                    .background(SosRed, in: RoundedRectangle(cornerRadius: 18))
                }
                .buttonStyle(.plain)
                Spacer().frame(height: 10)
                Button {
                    sharing = true
                    Task { error = await shareTrip(t.uuid ?? ""); sharing = false }
                } label: {
                    HStack(spacing: 8) {
                        Image(systemName: "square.and.arrow.up").foregroundStyle(pal.ink)
                        Text(L("trip_share")).font(.dorr(16, .semibold)).foregroundStyle(pal.ink)
                    }
                    .frame(maxWidth: .infinity).frame(height: 52)
                    .overlay(RoundedRectangle(cornerRadius: 18).strokeBorder(pal.ink.opacity(0.15), lineWidth: 1))
                    .contentShape(RoundedRectangle(cornerRadius: 18))
                }
                .buttonStyle(.plain)
                .disabled(sharing)
                Spacer().frame(height: 10)
                // Straight to my trusted contacts (D-142, D-143), and a report that isn't an emergency.
                HStack(spacing: 10) {
                    Button {
                        Task {
                            if let e = await shareTripWithContacts(t.uuid ?? "") { error = e } else { info = L("sc_sent") }
                        }
                    } label: {
                        Text(L("sc_send_contacts")).font(.dorr(13, .semibold)).foregroundStyle(pal.ink).multilineTextAlignment(.center)
                            .frame(maxWidth: .infinity).frame(height: 50)
                            .background(pal.navy.opacity(0.08), in: RoundedRectangle(cornerRadius: 18))
                    }
                    .buttonStyle(.plain)
                    Button(action: onReport) {
                        Text(L("rp_title")).font(.dorr(13, .semibold)).foregroundStyle(SosRed)
                            .frame(maxWidth: .infinity).frame(height: 50)
                            .background(SosRed.opacity(0.08), in: RoundedRectangle(cornerRadius: 18))
                    }
                    .buttonStyle(.plain)
                }
                if let info {
                    Text(verbatim: info).font(.dorr(13)).foregroundStyle(pal.success).multilineTextAlignment(.center)
                        .frame(maxWidth: .infinity).padding(.top, 8)
                }
            }
            .padding(.horizontal, 22)
            .padding(.top, 22)
            .padding(.bottom, 28)
        }
        .background(pal.card.ignoresSafeArea())
    }
}
