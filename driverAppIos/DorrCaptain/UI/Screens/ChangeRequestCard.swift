import SwiftUI
import UIKit

// driverApp `ui/ChangeRequestCard.kt`.

/// Wireflow 6ب: "⚡ Change of destination — 30 seconds to answer". The old and the new destination, the extra distance and
/// what the captain earns more; "✅ Agree +X" or "❌ Decline, keep going". No answer in time = declined by itself (the
/// server's clock decides). Laid over the whole trip screen.
@MainActor
struct ChangeRequestCard: View {
    @Environment(\.pal) private var pal
    let t: TripDto
    let onAnswered: () -> Void

    @State private var left = 30
    @State private var busy = false
    @State private var error: String?
    @State private var shown = false

    init(t: TripDto, onAnswered: @escaping () -> Void) {
        self.t = t
        self.onAnswered = onAnswered
    }

    var body: some View {
        if let change = t.changeRequest {
            content(change)
        }
    }

    private func answer(_ change: ChangeRequestDto, accept: Bool) {
        busy = true
        error = nil
        Task {
            let uuid = t.uuid ?? ""
            let change = change.uuid ?? ""
            let r = accept ? await DriverApi.acceptChange(uuid: uuid, change: change) : await DriverApi.rejectChange(uuid: uuid, change: change)
            switch r {
            case .ok: onAnswered()
            case let .failed(e): error = e.displayMessage; onAnswered()
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    @ViewBuilder
    private func content(_ change: ChangeRequestDto) -> some View {
        let adding = change.type == "add_stop"
        let distance = change.distanceDeltaM ?? 0
        let earning = change.earningDeltaMinor ?? 0
        ZStack(alignment: .bottom) {
            Color.black.opacity(0.55).ignoresSafeArea().contentShape(Rectangle()).onTapGesture {}
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 0) {
                    DorrIcon("bolt.fill", size: 26, tint: pal.orange)
                    Spacer().frame(width: 6)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L(adding ? "chg_title_stop" : "chg_title")).font(.dorr(20, .heavy)).foregroundStyle(pal.ink)
                        Text(L("chg_seconds", 30)).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    ZStack {
                        Circle()
                            .trim(from: 0, to: CGFloat(left) / 30)
                            .stroke(left > 10 ? pal.orange : pal.danger, style: StrokeStyle(lineWidth: 4))
                            .rotationEffect(.degrees(-90))
                            .frame(width: 52, height: 52)
                            .animation(.tween(900), value: left)
                        Text(verbatim: "\(left)").font(.dorr(20, .heavy)).foregroundStyle(pal.ink)
                    }
                    .frame(width: 56, height: 56)
                }
                Spacer().frame(height: 10)
                HStack(spacing: 0) {
                    Text(L("chg_rider_wants", t.rider?.name ?? "")).font(.dorr(16, .semibold)).foregroundStyle(pal.ink)
                    RiderTick(verified: t.rider?.isVerified == true, size: 14)
                }
                Spacer().frame(height: 10)
                VStack(alignment: .leading, spacing: 6) {
                    if !adding { ChangeLine(label: L("chg_old"), value: t.destination?.address ?? "—") }
                    ChangeLine(label: L(adding ? "chg_new_stop" : "chg_new"), value: change.place?.address ?? "—", bold: true)
                    ChangeLine(label: L("chg_extra_distance"), value: (distance >= 0 ? "+" : "−") + L("sch_trip_km", oneDecimal(Double(abs(distance)) / 1000.0)))
                    ChangeLine(label: L("chg_extra_earning"), value: (earning >= 0 ? "+" : "−") + money(abs(earning), t.currency), bold: true, color: pal.success)
                }
                .padding(14)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(pal.navy.opacity(0.05), in: RoundedRectangle(cornerRadius: 16))
                if let error {
                    Text(verbatim: error).font(.dorr(13)).foregroundStyle(pal.danger).padding(.top, 8)
                }
                Spacer().frame(height: 14)
                BigButton(L("chg_accept", money(Swift.max(0, earning), t.currency)), color: pal.success, enabled: left > 0, busy: busy) {
                    answer(change, accept: true)
                }
                Spacer().frame(height: 10)
                Button { answer(change, accept: false) } label: {
                    Text(L("chg_reject", String((t.destination?.address ?? "").prefix(28))))
                        .font(.dorr(16, .bold)).foregroundStyle(pal.danger).multilineTextAlignment(.center)
                        .frame(maxWidth: .infinity).frame(height: 54)
                        .overlay(RoundedRectangle(cornerRadius: 18).strokeBorder(pal.danger.opacity(0.4), lineWidth: 1))
                        .contentShape(RoundedRectangle(cornerRadius: 18))
                }
                .buttonStyle(.plain)
                .disabled(busy)
            }
            .padding(20)
            .background(TopRoundedShape(radius: 28).fill(pal.card).ignoresSafeArea(edges: .bottom))
            .scaleEffect(shown ? 1 : 0.85)
        }
        .task(id: change.uuid) {
            left = change.secondsLeft ?? 30
            UIImpactFeedbackGenerator(style: .heavy).impactOccurred()
            // The phone's own clock counts the seconds down (no asking the server).
            while left > 0 {
                try? await Task.sleep(nanoseconds: 1_000_000_000)
                if Task.isCancelled { return }
                left -= 1
            }
        }
        .onAppear {
            withAnimation(.interpolatingSpring(mass: 1, stiffness: 1500, damping: 2 * 0.5 * 1500.0.squareRoot())) { shown = true }
        }
    }
}

/// A shape rounded at its top corners only (the bottom sheet's).
struct TopRoundedShape: Shape {
    let radius: CGFloat

    func path(in rect: CGRect) -> Path {
        Path(UIBezierPath(roundedRect: rect, byRoundingCorners: [.topLeft, .topRight], cornerRadii: CGSize(width: radius, height: radius)).cgPath)
    }
}

private struct ChangeLine: View {
    @Environment(\.pal) private var pal
    let label: String
    let value: String
    var bold = false
    var color: Color? = nil

    var body: some View {
        HStack(spacing: 0) {
            Text(verbatim: label).font(.dorr(13)).foregroundStyle(pal.ink.opacity(0.6)).frame(maxWidth: .infinity, alignment: .leading)
            Text(verbatim: value).font(.dorr(14, bold ? .heavy : .semibold)).foregroundStyle(color ?? pal.ink).lineLimit(1)
        }
    }
}
