import CoreLocation
import SwiftUI

// driverApp `ui/Common.kt` — small helpers every screen shares: money, the "can't work now" reason, one location fix,
// clocks and times, the card and the big button.

/// "25.50 ر.س" from minor units (the app's language's digits, as `String.format(Locale.getDefault(), …)`).
func money(_ minor: Int?, _ currency: CurrencyDto?) -> String {
    guard let minor else { return "—" }
    let places = currency?.decimals ?? 2
    var divisor = 1.0
    for _ in 0..<Swift.max(places, 0) { divisor *= 10 }
    let f = NumberFormatter()
    f.locale = Locale(identifier: DriverPrefs.locale)
    f.numberStyle = .decimal
    f.usesGroupingSeparator = false
    f.minimumFractionDigits = places
    f.maximumFractionDigits = places
    f.roundingMode = .halfUp
    let number = f.string(from: NSNumber(value: Double(minor) / divisor)) ?? String(format: "%.\(places)f", Double(minor) / divisor)
    return "\(number) \(currency?.symbol ?? "")".trimmingCharacters(in: .whitespaces)
}

/// The reason code the server gives for "can't work now", in words (D-9).
func problemText(_ code: String?) -> String {
    switch code {
    case "account_pending_documents": return L("problem_account_pending_documents")
    case "account_under_review": return L("problem_account_under_review")
    case "account_suspended": return L("problem_account_suspended")
    case "account_restricted": return L("problem_account_restricted")
    case "account_blocked": return L("problem_account_blocked")
    case "no_active_vehicle": return L("problem_no_active_vehicle")
    case "documents_missing": return L("problem_documents_missing")
    case "documents_expired": return L("problem_documents_expired")
    case "regulator_pending": return L("problem_regulator_pending")
    case "regulator_invalid": return L("problem_regulator_invalid")
    case "regulator_rejected": return L("problem_regulator_rejected")
    case "regulator_expired": return L("problem_regulator_expired")
    case "regulator_vehicle_changed": return L("problem_regulator_vehicle_changed")
    case "driving_limit": return L("problem_driving_limit")
    case "maintenance": return L("problem_maintenance")
    case "service_off_here": return L("problem_service_off_here")
    case "emergency_review": return L("problem_emergency_review")
    case "terms_pending": return L("problem_terms_pending")
    default: return L("problem_generic")
    }
}

/// One fresh fix, high accuracy (`getCurrentLocation(PRIORITY_HIGH_ACCURACY)`) → (lat, lng); nil without the
/// permission or when it fails.
@MainActor
func lastPosition() async -> (Double, Double)? {
    let status = CLLocationManager().authorizationStatus
    guard status == .authorizedWhenInUse || status == .authorizedAlways else { return nil }
    guard let location = await OneFix().get() else { return nil }
    return (location.coordinate.latitude, location.coordinate.longitude)
}

/// Asks CoreLocation for one location and keeps itself alive until it answers.
@MainActor
private final class OneFix: NSObject, CLLocationManagerDelegate {
    private let manager = CLLocationManager()
    private var continuation: CheckedContinuation<CLLocation?, Never>?
    private var keepAlive: OneFix?

    func get() async -> CLLocation? {
        await withCheckedContinuation { cont in
            continuation = cont
            keepAlive = self
            manager.delegate = self
            manager.desiredAccuracy = kCLLocationAccuracyBest
            manager.requestLocation()
        }
    }

    private func finish(_ location: CLLocation?) {
        continuation?.resume(returning: location)
        continuation = nil
        manager.delegate = nil
        keepAlive = nil
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        let last = locations.last
        Task { @MainActor in self.finish(last) }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        Task { @MainActor in self.finish(nil) }
    }
}

/// "4:05" from seconds.
func clock(_ seconds: Int) -> String {
    "\(seconds / 60):\(String(format: "%02d", seconds % 60))"
}

/// A server time ("2026-10-14T09:30:00+00:00") → an instant; nil if missing or unreadable.
func instantOf(_ iso: String?) -> Date? { parseInstant(iso) }

/// When the waiting at the pickup counts from: the booked time if the captain came early.
func waitingFrom(_ t: TripDto) -> Date? { instantOf(t.times?.waitingFrom ?? t.times?.arrivedAt) }

/// "9:30 AM" in the phone's language and zone.
func timeOfDay(_ at: Date) -> String {
    let f = DateFormatter()
    f.locale = Locale(identifier: DriverPrefs.locale + "@numbers=latn")
    f.timeZone = .current
    f.dateStyle = .none
    f.timeStyle = .short
    return f.string(from: at)
}

/// The card of every screen: the DORR app's surface (soft shadow by day, hairline at night), 16 inside.
struct Card<Content: View>: View {
    @ViewBuilder let content: () -> Content

    init(@ViewBuilder content: @escaping () -> Content) { self.content = content }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) { content() }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .surface(20)
    }
}

/// `PrimaryButton` under its older name. `color` nil = `DorrBrand.navy` (the brand colour).
struct BigButton: View {
    let text: String
    var color: Color? = nil
    var enabled = true
    var busy = false
    let onClick: () -> Void

    init(_ text: String, color: Color? = nil, enabled: Bool = true, busy: Bool = false, onClick: @escaping () -> Void) {
        self.text = text
        self.color = color
        self.enabled = enabled
        self.busy = busy
        self.onClick = onClick
    }

    var body: some View {
        PrimaryButton(text, enabled: enabled, busy: busy, color: color, onClick: onClick)
    }
}
