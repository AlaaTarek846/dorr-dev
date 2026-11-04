import SwiftUI

// driverApp `ui/ServicesSheet.kt`.

/// O-89 (owner): «Services» — what the captain's vehicle can work in where they are (set from the dashboard
/// per country and kind of vehicle), each with its switch. Opened from the home screen's services button.
///
/// The sheet's content: open it with `.sheet(isPresented:) { ServicesSheet(onClose:) }` (Android's `ModalBottomSheet`,
/// opened fully).
@MainActor
struct ServicesSheet: View {
    @Environment(\.pal) private var pal
    let onClose: () -> Void
    let onChanged: (WorkServicesDto) -> Void
    @State private var data: WorkServicesDto?
    @State private var failed = false
    @State private var error: String?

    init(onClose: @escaping () -> Void, onChanged: @escaping (WorkServicesDto) -> Void = { _ in }) {
        self.onClose = onClose
        self.onChanged = onChanged
    }

    var body: some View {
        ScrollView {
            VStack(spacing: 0) {
                ServicesHeader(kind: data?.kind)
                VSpace(14)
                if let d = data {
                    let services = d.services ?? []
                    if services.isEmpty {
                        Text(L("sv_none")).font(.dorr(16)).foregroundStyle(pal.mut).multilineTextAlignment(.center)
                            .frame(maxWidth: .infinity).padding(24)
                    } else {
                        ForEach(Array(services.enumerated()), id: \.offset) { i, s in
                            ServiceCard(s: s, index: i) { on in toggle(s, on) }
                        }
                        VSpace(6)
                        let active = services.filter { $0.on ?? true }.count
                        Text(L("sv_count", active, services.filter { !($0.comingSoon ?? false) }.count))
                            .font(.dorr(12)).foregroundStyle(pal.mut).multilineTextAlignment(.center)
                            .frame(maxWidth: .infinity)
                    }
                } else if !failed {
                    CircularProgress(color: pal.accent, size: 40, lineWidth: 4)
                        .frame(maxWidth: .infinity)
                        .frame(height: 180)
                } else {
                    Text(L("error_generic")).font(.dorr(16)).foregroundStyle(pal.mut).multilineTextAlignment(.center)
                        .frame(maxWidth: .infinity).padding(24)
                }
                if let error {
                    Text(error).font(.dorr(12.5)).foregroundStyle(pal.danger).multilineTextAlignment(.center)
                        .frame(maxWidth: .infinity)
                        .padding(.top, 8)
                }
            }
            .padding(.horizontal, 18)
            .padding(.top, 8)
            .padding(.bottom, 22)
            .sheetFitsContent(max: 640)
        }
        .background(pal.card.ignoresSafeArea())
        .presentationDragIndicator(.visible)
        .task {
            switch await DriverApi.workServices() {
            case let .ok(d, _): data = d
            default: failed = true
            }
        }
    }

    private func toggle(_ s: WorkServiceDto, _ on: Bool) {
        guard let before = data, let key = s.key else { return }
        var changed = before
        changed.services = (before.services ?? []).map { item in
            var copy = item
            if item.key == s.key { copy.on = on }
            return copy
        }
        data = changed
        error = nil
        Task {
            switch await DriverApi.chooseWorkService(key: key, body: ["on": on]) {
            case let .ok(d, _):
                data = d
                onChanged(d)
            case let .failed(e):
                data = before
                error = e.displayMessage
            case .offline:
                data = before
                error = L("error_offline")
            }
        }
    }
}

private struct ServicesHeader: View {
    @Environment(\.pal) private var pal
    let kind: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                DorrIcon("square.grid.2x2.fill", size: 24, tint: .white)
                    .frame(width: 44, height: 44)
                    .background(Circle().fill(Color.white.opacity(0.18)))
                HSpace(12)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("sv_title")).font(.dorr(20, .black)).foregroundStyle(.white)
                    Text(L("sv_hint")).font(.dorr(12.5)).foregroundStyle(Color.white.opacity(0.85))
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            if let kind {
                VSpace(12)
                HStack(spacing: 0) {
                    DorrIcon(kindIcon(kind), size: 16, tint: .white)
                    HSpace(6)
                    Text(L("sv_vehicle", kindLabel(kind))).font(.dorr(12.5, .bold)).foregroundStyle(.white)
                }
                .padding(.horizontal, 12).padding(.vertical, 6)
                .background(Capsule().fill(Color.white.opacity(0.18)))
            }
        }
        .padding(18)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(
            RoundedRectangle(cornerRadius: 24)
                .fill(LinearGradient(colors: [pal.accent, pal.accent.opacity(0.72)], startPoint: .topLeading, endPoint: .bottomTrailing))
        )
    }
}

/// One service: its drawing, name and line, and the switch — sliding in one after the other.
private struct ServiceCard: View {
    @Environment(\.pal) private var pal
    let s: WorkServiceDto
    let index: Int
    let onToggle: (Bool) -> Void
    @State private var shown = false

    var body: some View {
        let tint = Color(hexString: s.color ?? "#1E88E5") ?? pal.accent
        let on = s.on ?? true
        HStack(spacing: 0) {
            DorrIcon(serviceIcon(s.icon), size: 26, tint: .white)
                .frame(width: 48, height: 48)
                .background(RoundedRectangle(cornerRadius: 16).fill(LinearGradient(colors: [tint, tint.opacity(0.65)], startPoint: .topLeading, endPoint: .bottomTrailing)))
                .scaleEffect(on ? 1.12 : 1)
                .animation(.springBouncy, value: on)
            HSpace(12)
            VStack(alignment: .leading, spacing: 0) {
                Text(s.name ?? "").font(.dorr(15, .bold)).foregroundStyle(pal.ink)
                if let description = s.description, !description.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(description).font(.dorr(12)).foregroundStyle(pal.mut).lineSpacing(1)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            HSpace(8)
            if s.comingSoon ?? false {
                Text(L("sv_soon")).font(.dorr(11.5, .bold)).foregroundStyle(tint)
                    .padding(.horizontal, 10).padding(.vertical, 5)
                    .background(Capsule().fill(tint.opacity(0.12)))
            } else {
                DorrToggle(on: on, onChange: onToggle)
            }
        }
        .padding(14)
        .background(RoundedRectangle(cornerRadius: 20).fill(on ? tint.opacity(0.07) : pal.card))
        .overlay(RoundedRectangle(cornerRadius: 20).strokeBorder(on ? tint.opacity(0.55) : pal.line, lineWidth: 1.5))
        .animation(.tween(250), value: on)
        .padding(.vertical, 5)
        .opacity(shown ? 1 : 0)
        .offset(y: shown ? 0 : 26)
        .onAppear {
            guard !shown else { return }
            // delay(60 × index), then fadeIn(260) + slideInVertically(320) from a third of its height.
            withAnimation(.tween(320).delay(0.06 * Double(index))) { shown = true }
        }
    }
}

/// The dashboard's icon name for a service, as an SF Symbol.
func serviceIcon(_ key: String?) -> String {
    switch key {
    case "car": return "car.fill"
    case "timer": return "timer"
    case "calendar": return "calendar"
    case "group": return "person.3.fill"
    case "flight": return "airplane"
    case "bus": return "bus.fill"
    case "boat": return "ferry.fill"
    case "person": return "person.fill.viewfinder"
    case "school": return "graduationcap.fill"
    case "package": return "shippingbox.fill"
    case "restaurant": return "fork.knife"
    case "moto": return "scooter"
    case "star": return "star.fill"
    default: return "square.grid.2x2.fill"
    }
}

private func kindIcon(_ kind: String) -> String {
    switch kind {
    case "motorcycle": return "scooter"
    case "minibus": return "bus"
    case "bus": return "bus.fill"
    case "boat": return "ferry.fill"
    case "yacht": return "sailboat.fill"
    case "none": return "person"
    default: return "car.fill"
    }
}

private func kindLabel(_ kind: String) -> String {
    switch kind {
    case "motorcycle": return L("sv_kind_motorcycle")
    case "minibus": return L("sv_kind_minibus")
    case "bus": return L("sv_kind_bus")
    case "boat": return L("sv_kind_boat")
    case "yacht": return L("sv_kind_yacht")
    case "none": return L("sv_kind_none")
    default: return L("sv_kind_car")
    }
}
