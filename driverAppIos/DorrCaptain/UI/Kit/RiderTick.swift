import SwiftUI

// driverApp `ui/RiderTick.kt`.

/// The blue tick of a verified Dorr account (the same one the DORR app shows).
let VerifiedBlue = Color(hex: 0x0EA5E9)

/// The rider's account verification tick, after their name (docs/verification_tasks.md AV-028) — nothing at all when
/// the rider isn't verified. The server says who is (`is_verified`); the app never works it out.
struct RiderTick: View {
    let verified: Bool
    var size: CGFloat = 16
    var tint: Color = VerifiedBlue

    init(verified: Bool, size: CGFloat = 16, tint: Color = VerifiedBlue) {
        self.verified = verified
        self.size = size
        self.tint = tint
    }

    var body: some View {
        if verified {
            DorrIcon("checkmark.seal.fill", size: size, tint: tint)
                .padding(.leading, 4)
                .accessibilityLabel(L("rider_verified"))
        }
    }
}
