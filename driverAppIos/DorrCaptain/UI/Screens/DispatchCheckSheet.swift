import SwiftUI

// driverApp `ui/DispatchCheckSheet.kt`.

/*
 * «Why don't requests reach me?» (docs/rides_tasks.md O-47): every condition the server checks before
 * sending a captain a request, each passed (✓) or not (✗) with what to do about it — read once when opened.
 */

/// The sheet's content: open it with `.sheet(isPresented:) { DispatchCheckSheet(onClose:) }` (Android's `ModalBottomSheet`,
/// opened fully — `skipPartiallyExpanded`).
@MainActor
struct DispatchCheckSheet: View {
    @Environment(\.pal) private var pal
    let onClose: () -> Void
    @State private var check: DispatchCheckDto?
    @State private var error: String?

    init(onClose: @escaping () -> Void) {
        self.onClose = onClose
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 0) {
                    TileIcon("dot.radiowaves.left.and.right", tint: pal.accent, box: 44)
                    HSpace(12)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("dc_title")).font(.dorr(18, .heavy)).foregroundStyle(pal.ink)
                        if let c = check {
                            let ready = c.ready ?? false
                            Text(L(ready ? "dc_ready" : "dc_not_ready")).font(.dorr(13, .bold)).foregroundStyle(ready ? pal.success : pal.danger)
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
                VSpace(14)
                Banner(error)
                if check == nil && error == nil {
                    CircularProgress(color: pal.accent, size: 40, lineWidth: 4)
                        .frame(maxWidth: .infinity)
                        .padding(30)
                }
                if let c = check {
                    ForEach(Array((c.checks ?? []).enumerated()), id: \.offset) { _, item in
                        row(item)
                    }
                    Text(L("dc_same_phone_tip")).font(.dorr(12)).foregroundStyle(pal.mut)
                        .padding(12)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(RoundedRectangle(cornerRadius: 14).fill(pal.warning.opacity(0.1)))
                        .padding(.top, 12)
                }
            }
            .padding(.horizontal, 18)
            .padding(.top, 8)
            .padding(.bottom, 20)
            .sheetFitsContent()
        }
        .background(pal.card.ignoresSafeArea())
        .presentationDragIndicator(.visible)
        .task {
            switch await DriverApi.dispatchCheck() {
            case let .ok(data, _): check = data
            case let .failed(e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
        }
    }

    private func row(_ item: DispatchCheckItemDto) -> some View {
        let ok = item.ok ?? false
        let key = item.key ?? ""
        return HStack(alignment: .top, spacing: 0) {
            DorrIcon(ok ? "checkmark" : "xmark", size: 16, tint: ok ? pal.success : pal.danger)
                .frame(width: 26, height: 26)
                .background(Circle().fill((ok ? pal.success : pal.danger).opacity(0.14)))
            HSpace(10)
            VStack(alignment: .leading, spacing: 0) {
                Text(checkTitle(key)).font(.dorr(14, .semibold)).foregroundStyle(pal.ink)
                if !ok, let fix = checkFix(key) {
                    Text(fix).font(.dorr(12)).foregroundStyle(pal.mut)
                }
                // The categories: which ones reach me, and why not the others.
                if key == "categories" {
                    ForEach(Array(item.categories.enumerated()), id: \.offset) { _, cat in
                        Text(categoryLine(cat))
                            .font(.dorr(12)).foregroundStyle(cat.ok == true ? pal.success : pal.mut)
                    }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(.vertical, 6)
        .frame(maxWidth: .infinity)
    }
}

/// "✓ Comfort" / "✗ XL — your vehicle's seats".
private func categoryLine(_ cat: DispatchCategoryDto) -> String {
    let mark: String = cat.ok == true ? "✓ " : "✗ "
    let name: String = cat.name ?? cat.slug ?? ""
    let why: String = cat.reason.map { " — " + categoryReason($0) } ?? ""
    return mark + name + why
}

private func checkTitle(_ key: String) -> String {
    switch key {
    case "country": return L("dc_country")
    case "service_on": return L("dc_service_on")
    case "account": return L("dc_account")
    case "online": return L("dc_online")
    case "gps": return L("dc_gps")
    case "location": return L("dc_location")
    case "location_fresh": return L("dc_location_fresh")
    case "categories": return L("dc_categories")
    case "free": return L("dc_free")
    case "offers_room": return L("dc_offers_room")
    case "balance_for_cash": return L("dc_balance")
    case "pickup_reach": return L("dc_reach")
    case "server_dispatch_tick": return L("dc_server_tick")
    case "server_realtime": return L("dc_server_realtime")
    case "server_push": return L("dc_server_push")
    default: return L("dc_other")
    }
}

private func checkFix(_ key: String) -> String? {
    switch key {
    case "account": return L("dc_account_fix")
    case "online": return L("dc_online_fix")
    case "gps", "location", "location_fresh": return L("dc_location_fix")
    case "categories": return L("dc_categories_fix")
    case "balance_for_cash": return L("dc_balance_fix")
    case "server_dispatch_tick", "server_realtime", "server_push": return L("dc_server_fix")
    default: return nil
    }
}

private func categoryReason(_ reason: String) -> String {
    switch reason {
    case "switched_off_by_you": return L("dc_cat_off")
    case "vehicle_body_type": return L("dc_cat_body")
    case "vehicle_too_old": return L("dc_cat_old")
    case "vehicle_seats": return L("dc_cat_seats")
    case "driver_gender": return L("dc_cat_gender")
    // O-89: the dashboard didn't open its service to this vehicle here / the captain switched it off.
    case "vehicle_kind": return L("dc_cat_kind")
    case "service_switched_off": return L("dc_cat_service_off")
    default: return reason
    }
}

// MARK: - A sheet as tall as what it shows

/// Material's `ModalBottomSheet(skipPartiallyExpanded = true)`: opened at its content's height (up to the whole screen).
/// Put it on the sheet's content inside its `ScrollView`.
struct SheetFitsContent: ViewModifier {
    /// Compose `heightIn(max = …)`.
    var max: CGFloat? = nil
    @State private var height: CGFloat = 0

    func body(content: Content) -> some View {
        content
            .background(GeometryReader { g in
                Color.clear
                    .onAppear { height = g.size.height }
                    .onChange(of: g.size.height) { height = $0 }
            })
            .presentationDetents(height > 0 ? [.height(Swift.min(height, max ?? .infinity) + 24)] : [.large])
    }
}

extension View {
    func sheetFitsContent(max: CGFloat? = nil) -> some View { modifier(SheetFitsContent(max: max)) }
}
