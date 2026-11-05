import SwiftUI

// driverApp `ui/NotAtPickupDialog.kt`.

/// O-76: «Arrived» pressed while still away from the pickup — a clear dialog in the middle of the screen
/// (not a line at the bottom): how far, within how much it works, and the way there.
///
/// Like the Kotlin `if (shown) NotAtPickupDialog(…)`: put it in the screen while it should show (`if shown { NotAtPickupDialog(…) }`);
/// it presents itself over everything (Android's `Dialog`) and a tap outside calls `onClose`.
@MainActor
struct NotAtPickupDialog: View {
    let distanceM: Int?
    let radiusM: Int?
    let message: String
    let pickupLat: Double?
    let pickupLng: Double?
    let onClose: () -> Void

    init(distanceM: Int?, radiusM: Int?, message: String, pickupLat: Double?, pickupLng: Double?, onClose: @escaping () -> Void) {
        self.distanceM = distanceM
        self.radiusM = radiusM
        self.message = message
        self.pickupLat = pickupLat
        self.pickupLng = pickupLng
        self.onClose = onClose
    }

    var body: some View {
        Color.clear
            .frame(width: 0, height: 0)
            .dorrDialog(isPresented: Binding(get: { true }, set: { if !$0 { onClose() } })) {
                NotAtPickupCard(distanceM: distanceM, radiusM: radiusM, message: message, pickupLat: pickupLat, pickupLng: pickupLng, onClose: onClose)
            }
    }
}

private struct NotAtPickupCard: View {
    @Environment(\.pal) private var pal
    let distanceM: Int?
    let radiusM: Int?
    let message: String
    let pickupLat: Double?
    let pickupLng: Double?
    let onClose: () -> Void
    @State private var ring = false

    var body: some View {
        VStack(spacing: 12) {
            ZStack {
                Circle().fill(pal.warning.opacity(0.18)).frame(width: 84, height: 84).scaleEffect(ring ? 1.25 : 1)
                DorrIcon("mappin.and.ellipse", size: 32, tint: .white)
                    .frame(width: 60, height: 60)
                    .background(Circle().fill(pal.warning))
            }
            .onAppear { withAnimation(.tween(900).repeatForever(autoreverses: true)) { ring = true } }
            Text(L("np_title")).font(.dorr(20, .black)).foregroundStyle(pal.ink).multilineTextAlignment(.center)
            if let distanceM, let radiusM {
                HStack(spacing: 10) {
                    NpStat(label: L("np_you_are"), value: L("np_metres", distanceM), tint: pal.warning)
                    NpStat(label: L("np_works_within"), value: L("np_metres", radiusM), tint: pal.success)
                }
                // How much of the way is left, as a bar.
                let done = min(max(CGFloat(radiusM) / CGFloat(max(distanceM, 1)), 0.05), 1)
                GeometryReader { g in
                    ZStack(alignment: .leading) {
                        Capsule().fill(pal.line)
                        Capsule().fill(pal.success).frame(width: g.size.width * done)
                    }
                }
                .frame(height: 8)
            } else {
                Text(message).font(.dorr(14)).foregroundStyle(pal.mut).multilineTextAlignment(.center)
            }
            VSpace(2)
            if let pickupLat, let pickupLng {
                BigButton(L("np_navigate"), color: pal.accent) {
                    openNavigation(lat: pickupLat, lng: pickupLng)
                    onClose()
                }
            }
            Text(L("np_ok")).font(.dorr(16, .bold)).foregroundStyle(pal.accent)
                .padding(.horizontal, 18).padding(.vertical, 8)
                .bounceClick(onClick: onClose)
        }
        .padding(22)
        .frame(maxWidth: .infinity)
        .background(RoundedRectangle(cornerRadius: 28).fill(pal.card))
        // Android's Dialog keeps the platform's margins at the sides.
        .padding(.horizontal, 24)
    }
}

private struct NpStat: View {
    @Environment(\.pal) private var pal
    let label: String
    let value: String
    let tint: Color

    var body: some View {
        VStack(spacing: 0) {
            Text(value).font(.dorr(18, .black)).foregroundStyle(tint)
            Text(label).font(.dorr(11)).foregroundStyle(pal.mut)
        }
        .padding(.vertical, 10)
        .frame(maxWidth: .infinity)
        .background(RoundedRectangle(cornerRadius: 16).fill(tint.opacity(0.1)))
    }
}
