import SwiftUI
import UIKit

// driverApp `ui/ServiceStates.kt`.

/// D-329, D-330, D-335, D-336, D-344: what is out of the ordinary where the captain works, on the home sheet.
/// Loaded on open / reconnect and on a real-time change — never on a timer.
struct ServiceStateBanners: View {
    let s: ServiceStatusDto?

    init(_ s: ServiceStatusDto?) { self.s = s }

    var body: some View {
        if let s {
            VStack(spacing: 8) {
                if s.maintenance ?? false { Banner(s.maintenanceMessage ?? L("ss_maintenance"), kind: .warning) }
                if !(s.serviceOn ?? true) { Banner(L("ss_service_off"), kind: .warning) }
                if let r = s.emergencyReview {
                    Banner(L("ss_emergency_review", r.rideNumber ?? "", r.until.map(formatClock) ?? ""), kind: .error)
                }
                let paused = s.pausedCategories ?? []
                if !paused.isEmpty {
                    Banner(L("ss_categories_paused", paused.map { $0.name ?? "" }.joined(separator: "، ")), kind: .info)
                }
                if s.safeMode ?? false { Banner(L("ss_safe_mode"), kind: .info) }
            }
            .frame(maxWidth: .infinity)
        }
    }
}

/// D-330, D-338..D-340: the wallet's side — the safe mode, and how the last withdrawal stands.
struct WithdrawalStateBanners: View {
    let s: ServiceStatusDto?
    let money: (Int) -> String

    init(_ s: ServiceStatusDto?, money: @escaping (Int) -> String) {
        self.s = s
        self.money = money
    }

    var body: some View {
        if let s {
            VStack(spacing: 8) {
                if s.safeMode ?? false { Banner(L("ss_safe_mode"), kind: .warning) }
                if let w = s.lastWithdrawal { WithdrawalLine(w: w, amount: money(w.amountMinor ?? 0)) }
            }
            .frame(maxWidth: .infinity)
        }
    }
}

private struct WithdrawalLine: View {
    let w: LastWithdrawalDto
    let amount: String

    var body: some View {
        switch w.state ?? "pending" {
        case "pending": Banner(L("ss_wd_pending", amount), kind: .info)
        case "unknown": Banner(L("ss_wd_unknown", amount), kind: .warning)
        case "failed": Banner(L("ss_wd_failed", amount, w.reason ?? "—"), kind: .error)
        default: EmptyView()
        }
    }
}

/// "2026-10-21T18:00:00+03:00" → "21/10 18:00" in the phone's time zone.
private func formatClock(_ iso: String) -> String {
    guard let at = instantOf(iso) else { return "" }
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.timeZone = .current
    f.dateFormat = "dd/MM HH:mm"
    return f.string(from: at)
}

/// D-341: to a point by navigation — Google Maps, else the phone's maps app, else the browser; with none of those,
/// the coordinates go to the clipboard so the captain can paste them anywhere.
@MainActor
func openNavigation(lat: Double, lng: Double, webUrl: String? = nil) {
    let tries = [
        URL(string: "comgooglemaps://?daddr=\(lat),\(lng)&directionsmode=driving"),
        URL(string: "maps://?daddr=\(lat),\(lng)&dirflg=d"),
        URL(string: webUrl ?? "https://www.google.com/maps/dir/?api=1&destination=\(lat),\(lng)"),
    ].compactMap { $0 }

    func attempt(_ index: Int) {
        guard index < tries.count else {
            UIPasteboard.general.string = "\(lat),\(lng)"
            AppToast.show(L("ss_nav_failed"), long: true)
            return
        }
        UIApplication.shared.open(tries[index], options: [:]) { opened in
            if !opened { attempt(index + 1) }
        }
    }
    attempt(0)
}
