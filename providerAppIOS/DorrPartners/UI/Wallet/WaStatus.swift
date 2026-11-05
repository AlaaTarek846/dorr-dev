// providerApp `ui/wallet/WaStatus.kt` (identical to androidApp's; ported from iosApp)
import SwiftUI

/// androidApp `WaStatus.kt` — the round "done / failed" seal: a disc that pops in, a check (or cross)
/// that draws itself, and a soft ring that keeps pulsing outwards — `wa-seal` in the preview.
struct WaSeal: View {
    @Environment(\.wa) private var wa
    var bad = false
    @State private var start = Date()

    var body: some View {
        let color = bad ? wa.danger : wa.green
        TimelineView(.animation) { context in
            let elapsed = context.date.timeIntervalSince(start)
            let ring = CGFloat(elapsed.truncatingRemainder(dividingBy: 1.6) / 1.6)
            let disc = springIn(CGFloat(elapsed / 0.45))
            let mark = min(max(CGFloat((elapsed - 0.45) / 0.45), 0), 1)
            Canvas { gc, size in
                let unit = size.width / 104
                let center = CGPoint(x: size.width / 2, y: size.height / 2)
                // Pulsing ring.
                let ringRadius = 46 * unit * (0.7 + ring * 0.55)
                gc.stroke(Path(ellipseIn: CGRect(x: center.x - ringRadius, y: center.y - ringRadius, width: ringRadius * 2, height: ringRadius * 2)),
                          with: .color(color.opacity(0.4 * (1 - ring))), lineWidth: 3 * unit)
                // Disc.
                let discRadius = 46 * unit * (0.4 + 0.6 * disc)
                gc.fill(Path(ellipseIn: CGRect(x: center.x - discRadius, y: center.y - discRadius, width: discRadius * 2, height: discRadius * 2)),
                        with: .color(color.opacity(min(max(disc, 0), 1))))
                // Mark, drawn progressively along its strokes.
                let style = StrokeStyle(lineWidth: 6 * unit, lineCap: .round, lineJoin: .round)
                if !bad {
                    gc.stroke(polyline([CGPoint(x: 34, y: 54), CGPoint(x: 47, y: 67), CGPoint(x: 71, y: 41)], unit: unit, progress: mark), with: .color(.white), style: style)
                } else {
                    gc.stroke(polyline([CGPoint(x: 38, y: 38), CGPoint(x: 66, y: 66)], unit: unit, progress: mark), with: .color(.white), style: style)
                    gc.stroke(polyline([CGPoint(x: 66, y: 38), CGPoint(x: 38, y: 66)], unit: unit, progress: mark), with: .color(.white), style: style)
                }
            }
            .offset(x: bad ? shake(elapsed - 0.9) * 6 : 0)
        }
        .frame(width: 104, height: 104)
        .onAppear { start = Date() }
    }

    /// The disc's pop-in: an under-damped spring (damping 0.55) that overshoots a little and settles at 1.
    private func springIn(_ t: CGFloat) -> CGFloat {
        guard t > 0 else { return 0 }
        guard t < 2.2 else { return 1 }
        return 1 - exp(-3.2 * t) * cos(5.4 * t)
    }

    /// Three quick side-to-side shakes (70 ms each way), then back to rest — only on a failure.
    private func shake(_ t: TimeInterval) -> CGFloat {
        guard t > 0, t < 0.84 else { return 0 }
        return CGFloat(sin(t / 0.28 * 2 * .pi))
    }

    /// The first [progress] of the line through [points] (in the 104-unit design grid).
    private func polyline(_ points: [CGPoint], unit: CGFloat, progress: CGFloat) -> Path {
        let scaled = points.map { CGPoint(x: $0.x * unit, y: $0.y * unit) }
        let lengths = zip(scaled, scaled.dropFirst()).map { hypot($1.x - $0.x, $1.y - $0.y) }
        var remaining = lengths.reduce(0, +) * progress
        var path = Path()
        guard remaining > 0 else { return path }
        path.move(to: scaled[0])
        for index in lengths.indices {
            if remaining <= 0 { break }
            let take = min(remaining, lengths[index])
            let p = scaled[index], q = scaled[index + 1]
            let t = take / lengths[index]
            path.addLine(to: CGPoint(x: p.x + (q.x - p.x) * t, y: p.y + (q.y - p.y) * t))
            remaining -= take
        }
        return path
    }
}

/// Confetti burst for a successful payment (`wa-confetti`): 26 pieces fall, spin and fade.
struct WaConfetti: View {
    @Environment(\.wa) private var wa
    @State private var start = Date()
    @State private var pieces: [Piece] = []

    struct Piece {
        let dx: CGFloat
        let spin: Double
        let delay: CGFloat
        let color: Color
    }

    var body: some View {
        TimelineView(.animation) { context in
            let progress = min(CGFloat(context.date.timeIntervalSince(start) / 2.05), 1)
            Canvas { gc, size in
                let startY: CGFloat = 90
                for piece in pieces {
                    let local = min(max((progress - piece.delay * 0.8) / 0.8, 0), 1)
                    if local <= 0 || local >= 1 { continue }
                    let e = ease(local)
                    let x = size.width / 2 + piece.dx * e
                    let y = startY + 420 * e
                    var layer = gc
                    layer.translateBy(x: x, y: y)
                    layer.rotate(by: .degrees(piece.spin * Double(e)))
                    layer.fill(Path(CGRect(x: -4, y: -7, width: 8, height: 14)), with: .color(piece.color.opacity(1 - local)))
                }
            }
        }
        .allowsHitTesting(false)
        .onAppear {
            start = Date()
            let colors: [Color] = [wa.red, Color(hex: 0xF59E0B), Color(hex: 0x16A34A), Color(hex: 0x2563EB), Color(hex: 0xDB2777), .white]
            pieces = (0..<26).map { _ in
                Piece(dx: (CGFloat.random(in: 0...1) - 0.5) * 300, spin: Double.random(in: -360...360),
                      delay: CGFloat.random(in: 0...0.35), color: colors.randomElement() ?? .white)
            }
        }
    }

    /// CubicBezierEasing(0.2, 0.7, 0.4, 1) solved for y at x = t.
    private func ease(_ t: CGFloat) -> CGFloat {
        let (x1, y1, x2, y2): (CGFloat, CGFloat, CGFloat, CGFloat) = (0.2, 0.7, 0.4, 1)
        func bezier(_ s: CGFloat, _ a: CGFloat, _ b: CGFloat) -> CGFloat {
            3 * (1 - s) * (1 - s) * s * a + 3 * (1 - s) * s * s * b + s * s * s
        }
        var low: CGFloat = 0, high: CGFloat = 1, s = t
        for _ in 0..<20 {
            s = (low + high) / 2
            if bezier(s, x1, x2) < t { low = s } else { high = s }
        }
        return bezier(s, y1, y2)
    }
}

/// The pulsing "waiting…" badge: three rings ping outwards from a brand core with an icon.
struct WaPulse: View {
    @Environment(\.wa) private var wa
    let icon: String

    var body: some View {
        ZStack {
            TimelineView(.animation) { context in
                let time = context.date.timeIntervalSinceReferenceDate
                Canvas { gc, size in
                    for index in 0..<3 {
                        let t = CGFloat((time + Double(index) * 0.7).truncatingRemainder(dividingBy: 2.2) / 2.2)
                        let radius = min(size.width, size.height) / 2 * (0.75 + 0.75 * t)
                        let center = CGPoint(x: size.width / 2, y: size.height / 2)
                        gc.stroke(Path(ellipseIn: CGRect(x: center.x - radius, y: center.y - radius, width: radius * 2, height: radius * 2)),
                                  with: .color(wa.red.opacity(0.5 * (1 - t))), lineWidth: 2)
                    }
                }
            }
            Image(systemName: icon)
                .font(.system(size: 30, weight: .semibold))
                .foregroundStyle(.white)
                .frame(width: 78, height: 78)
                .background(wa.buttonGradient, in: Circle())
                .shadow(color: wa.red.opacity(0.5), radius: 14, y: 6)
        }
        .frame(width: 104, height: 104)
    }
}

/// Centered column used by every result screen (done, failed, waiting, OTP). Centred when it fits,
/// scrollable when it doesn't (small screens, large fonts).
struct WaStatusColumn<Content: View>: View {
    @ViewBuilder let content: () -> Content

    var body: some View {
        GeometryReader { proxy in
            ScrollView {
                VStack(spacing: 0, content: content)
                    .padding(.horizontal, 24)
                    .padding(.top, 20)
                    .padding(.bottom, 28)
                    .frame(maxWidth: .infinity, minHeight: proxy.size.height)
            }
        }
    }
}

struct WaStatusTitle: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        Text(text).font(.dorr(22, .heavy)).foregroundStyle(wa.ink).multilineTextAlignment(.center)
            .padding(.top, 18).padding(.bottom, 6)
    }
}

struct WaStatusText: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        Text(text).font(.dorr(14)).foregroundStyle(wa.mut).multilineTextAlignment(.center).lineSpacing(6)
            .padding(.horizontal, 24)
    }
}

/// Three little dots that bounce after a title ("…").
struct WaLoadingDots: View {
    @Environment(\.wa) private var wa

    var body: some View {
        TimelineView(.animation) { context in
            let time = context.date.timeIntervalSinceReferenceDate
            HStack(spacing: 4) {
                ForEach(0..<3, id: \.self) { index in
                    let t = CGFloat((time + Double(3 - index) * 0.15).truncatingRemainder(dividingBy: 1.2) / 1.2)
                    let lift: CGFloat = t < 0.3 ? t / 0.3 : (t < 0.6 ? 1 - (t - 0.3) / 0.3 : 0)
                    Circle().fill(wa.ink).frame(width: 5, height: 5)
                        .offset(y: -4 * lift)
                        .opacity(0.4 + 0.6 * lift)
                }
            }
            .padding(.leading, 4)
        }
    }
}
