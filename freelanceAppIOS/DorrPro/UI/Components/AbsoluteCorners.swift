import SwiftUI

// freelanceApp `ui/components/AbsoluteCorners.kt`

/// A rounded shape whose corners are named by the screen side (left/right), not by the reading side. Chat bubbles sit on a
/// fixed side (mine on the right, the other person on the left, in Arabic too), so the sharp "tail" corner must follow the
/// screen side as well. (A SwiftUI `Shape` path is drawn in screen coordinates and is not mirrored in right-to-left, so the
/// corners stay where they are named.)
struct AbsoluteCorners: Shape {
    let topLeft: CGFloat
    let topRight: CGFloat
    let bottomRight: CGFloat
    let bottomLeft: CGFloat

    func path(in rect: CGRect) -> Path {
        let limit = min(rect.width, rect.height) / 2
        let tl = min(topLeft, limit), tr = min(topRight, limit), br = min(bottomRight, limit), bl = min(bottomLeft, limit)
        var p = Path()
        p.move(to: CGPoint(x: rect.minX + tl, y: rect.minY))
        p.addLine(to: CGPoint(x: rect.maxX - tr, y: rect.minY))
        p.addArc(tangent1End: CGPoint(x: rect.maxX, y: rect.minY), tangent2End: CGPoint(x: rect.maxX, y: rect.minY + tr), radius: tr)
        p.addLine(to: CGPoint(x: rect.maxX, y: rect.maxY - br))
        p.addArc(tangent1End: CGPoint(x: rect.maxX, y: rect.maxY), tangent2End: CGPoint(x: rect.maxX - br, y: rect.maxY), radius: br)
        p.addLine(to: CGPoint(x: rect.minX + bl, y: rect.maxY))
        p.addArc(tangent1End: CGPoint(x: rect.minX, y: rect.maxY), tangent2End: CGPoint(x: rect.minX, y: rect.maxY - bl), radius: bl)
        p.addLine(to: CGPoint(x: rect.minX, y: rect.minY + tl))
        p.addArc(tangent1End: CGPoint(x: rect.minX, y: rect.minY), tangent2End: CGPoint(x: rect.minX + tl, y: rect.minY), radius: tl)
        p.closeSubpath()
        return p
    }
}

/// `absoluteCorners(topLeft, topRight, bottomRight, bottomLeft)` — see [AbsoluteCorners].
func absoluteCorners(topLeft: CGFloat, topRight: CGFloat, bottomRight: CGFloat, bottomLeft: CGFloat) -> AbsoluteCorners {
    AbsoluteCorners(topLeft: topLeft, topRight: topRight, bottomRight: bottomRight, bottomLeft: bottomLeft)
}
