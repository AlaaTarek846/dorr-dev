import CoreLocation
import SwiftUI
import UIKit

// androidApp `moving/MovingUi.kt` — the pieces every moving screen shares. Nothing here is a new design: each one is
// the rides screens' own look (the page header of "My rides", the cards of the cases page, the chips, the sheet), given
// one name so that the moving pages stay short.

/// "250.00 SAR" from minor units, with the request's currency code and decimals.
func mvMoney(_ minor: Int?, _ code: String?, _ decimals: Int) -> String {
    rideMoney(minor, RideCurrencyDto(symbol: code, decimalPlaces: decimals))
}

/// A server time (Laravel's microseconds included).
func mvTime(_ iso: String?) -> Date? { parseRideTime(iso) ?? parseInstant(iso) }

/// Dates in the app's language.
func mvFormatter(_ pattern: String) -> DateFormatter {
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current)
    f.dateFormat = pattern
    return f
}

/// A digit in any script the keyboard gives (Arabic-Indic too): its value.
private func digitValue(_ c: Character) -> Int? {
    guard c.isNumber, let v = c.wholeNumberValue, (0...9).contains(v) else { return nil }
    return v
}

/// What is typed in an amount field, kept to digits and one separator, with no more decimals than the currency has.
func limitAmount(_ typed: String, _ decimals: Int) -> String {
    var out = ""
    var separated = false
    var fraction = 0
    for c in typed {
        if digitValue(c) != nil {
            if !separated {
                out.append(c)
            } else if fraction < decimals {
                out.append(c)
                fraction += 1
            }
        } else if (c == "." || c == "," || c == "٫") && !separated && decimals > 0 {
            separated = true
            out.append(c)
        }
    }
    return String(out.prefix(14))
}

/// A typed amount in minor units; nil when it isn't a number. Any digits the keyboard gives are understood.
func parseMinor(_ typed: String, _ decimals: Int) -> Int? {
    let normal = String(typed.trimmingCharacters(in: .whitespaces).map { c -> Character in
        if let v = digitValue(c) { return Character(String(v)) }
        return c == "," || c == "٫" ? "." : c
    })
    guard !normal.isEmpty else { return nil }
    let number = NSDecimalNumber(string: normal, locale: Locale(identifier: "en_US_POSIX"))
    guard number != NSDecimalNumber.notANumber else { return nil }
    let down = NSDecimalNumberHandler(roundingMode: .down, scale: 0, raiseOnExactness: false, raiseOnOverflow: false,
                                      raiseOnUnderflow: false, raiseOnDivideByZero: false)
    let minor = number.multiplying(byPowerOf10: Int16(decimals)).rounding(accordingToBehavior: down)
    guard minor.compare(NSDecimalNumber(value: Int.max)) != .orderedDescending else { return nil }
    return minor.intValue
}

/// The neighbourhood under a point, in the app's language (providers see only this until their offer is accepted).
func areaOf(_ at: CLLocationCoordinate2D) async -> String? {
    let placemarks = try? await CLGeocoder().reverseGeocodeLocation(CLLocation(latitude: at.latitude, longitude: at.longitude),
                                                                    preferredLocale: Locale(identifier: AppLocale.current))
    guard let p = placemarks?.first else { return nil }
    let found = p.subLocality ?? p.locality ?? p.subAdministrativeArea
    return found?.trimmingCharacters(in: .whitespaces).isEmpty == false ? found : nil
}

/// A pull on a moving list: one more "reload now", as a reconnect (Android's `RefreshCoordinator`).
func mvPull() async {
    await MainActor.run { NetworkMonitor.shared.noticeArrived() }
}

// MARK: - Colours on a panel

private struct MvOnPanelKey: EnvironmentKey { static let defaultValue = false }

extension EnvironmentValues {
    /// Set when the moving cards are drawn on a page's white panel: the cards on it take the page's colour instead.
    var mvOnPanel: Bool {
        get { self[MvOnPanelKey.self] }
        set { self[MvOnPanelKey.self] = newValue }
    }
}

extension WaPalette {
    func mvCard(_ onPanel: Bool) -> Color { onPanel ? settingsBackground : settingsCard }
    /// The colour of a chip or a field inside a card.
    func mvInset(_ onPanel: Bool) -> Color { onPanel ? settingsCard : settingsBackground }
}

// MARK: - The page

/// A page: back and the title at the top (as on "My rides"), its content, and an optional action bar at the bottom.
struct MvPage<Content: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    let onBack: () -> Void
    var trailing: AnyView? = nil
    var bottom: AnyView? = nil
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 10) {
                RideRoundButton(icon: "arrow.backward", label: L("common_back"), action: onBack)
                Text(verbatim: title).font(.dorr(20, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                Spacer(minLength: 0)
                if let trailing { trailing }
            }
            .padding(12)
            content().frame(maxWidth: .infinity, maxHeight: .infinity)
            if let bottom {
                VStack(spacing: 0) { bottom }
                    .padding(16)
                    .frame(maxWidth: .infinity)
                    .background(wa.settingsCard.ignoresSafeArea(edges: .bottom))
                    .shadow(color: .black.opacity(wa.night ? 0 : 0.12), radius: 18, y: -2)
            }
        }
        .background(wa.settingsBackground.ignoresSafeArea())
    }
}

/// The scrolling column of a page's cards; a pull reloads the page once.
struct MvScroll<Content: View>: View {
    var pull = true
    @ViewBuilder let content: () -> Content

    var body: some View {
        let scroll = ScrollView {
            VStack(alignment: .leading, spacing: 12) { content() }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 16)
                .padding(.bottom, 16)
        }
        if pull {
            scroll.refreshable { await mvPull() }
        } else {
            scroll
        }
    }
}

struct MvCard<Content: View>: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 0) { content() }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(wa.mvCard(onPanel), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
    }
}

struct MvCardTitle: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        Text(verbatim: text).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
    }
}

/// A state in words (a colour only helps).
struct MvChip: View {
    let text: String
    let color: Color

    var body: some View {
        Text(verbatim: text).font(.dorr(11, .bold)).foregroundStyle(color).lineLimit(1)
            .padding(.horizontal, 10).padding(.vertical, 4)
            .background(color.opacity(0.12), in: Capsule())
    }
}

/// A choice among a few (the cases page's pills).
struct MvPill: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let label: String
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(verbatim: label).font(.dorr(13, .semibold)).foregroundStyle(selected ? .white : wa.settingsInk)
                .padding(.horizontal, 14).padding(.vertical, 8)
                .background(selected ? wa.accent : wa.mvInset(onPanel), in: Capsule())
        }
        .buttonStyle(.plain)
        .animation(.easeInOut(duration: 0.2), value: selected)
    }
}

/// Label on one side, value on the other.
struct MvLine: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String
    var bold = false
    var valueColor: Color?

    var body: some View {
        HStack(spacing: 10) {
            Text(verbatim: label).font(.dorr(13, bold ? .heavy : .regular)).foregroundStyle(bold ? wa.settingsInk : wa.settingsMut)
            Spacer(minLength: 0)
            Text(verbatim: value).font(.dorr(bold ? 15 : 13, bold ? .heavy : .semibold)).foregroundStyle(valueColor ?? wa.settingsInk)
        }
        .padding(.vertical, 4)
    }
}

/// A row that opens something (the "left something in the car" row).
struct MvAction: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let icon: String
    let title: String
    var sub: String?
    var tint: Color?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 10) {
                Image(systemName: icon).font(.system(size: 18)).foregroundStyle(tint ?? wa.accent).frame(width: 22)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: title).font(.dorr(13.5, .semibold)).foregroundStyle(wa.settingsInk)
                    if let sub { Text(verbatim: sub).font(.dorr(11.5)).foregroundStyle(wa.settingsMut) }
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut)
            }
            .padding(12)
            .background(wa.mvCard(onPanel), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(WaPressStyle())
    }
}

/// The quieter of two buttons (the rides cancel look).
struct MvOutlineButton: View {
    @Environment(\.wa) private var wa
    let text: String
    var color: Color?
    var enabled = true
    let action: () -> Void

    var body: some View {
        let c = color ?? wa.settingsInk
        Button(action: action) {
            Text(verbatim: text).font(.dorr(15, .bold)).foregroundStyle(c).lineLimit(1).minimumScaleFactor(0.8)
                .frame(maxWidth: .infinity, minHeight: 54)
                .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(c.opacity(0.35), lineWidth: 1.5))
                .contentShape(Rectangle())
        }
        .buttonStyle(WaPressStyle())
        .disabled(!enabled)
        .opacity(enabled ? 1 : 0.5)
    }
}

struct MvError: View {
    let text: String?

    var body: some View {
        if let text { Text(verbatim: text).font(.dorr(13)).foregroundStyle(AppColors.danger) }
    }
}

/// A note in a soft wash of its colour.
struct MvNote: View {
    @Environment(\.wa) private var wa
    let text: String
    var color: Color?

    var body: some View {
        Text(verbatim: text).font(.dorr(12.5)).foregroundStyle(wa.settingsInk)
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 12).padding(.vertical, 10)
            .background((color ?? wa.accent).opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

struct MvLoading: View {
    @Environment(\.wa) private var wa

    var body: some View {
        ProgressView().tint(wa.accent).frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

/// Nothing to show: an icon in its circle and a line (the "My rides" empty state).
struct MvEmpty: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String

    var body: some View {
        VStack(spacing: 10) {
            Image(systemName: icon).font(.system(size: 30)).foregroundStyle(wa.accent)
                .frame(width: 72, height: 72).background(wa.accent.opacity(0.1), in: Circle())
            Text(verbatim: text).font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
        }
        .padding(32)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

/// A row of stars; tappable when `onChange` is given.
struct MvStars: View {
    @Environment(\.wa) private var wa
    let value: Int
    var size: CGFloat = 36
    var onChange: ((Int) -> Void)?

    var body: some View {
        HStack(spacing: 0) {
            ForEach(1...5, id: \.self) { i in
                let on = i <= value
                Image(systemName: on ? "star.fill" : "star").font(.system(size: size * 0.8))
                    .foregroundStyle(on ? AppColors.warning : wa.settingsMut)
                    .frame(width: size, height: size)
                    .contentShape(Circle())
                    .onTapGesture { onChange?(i) }
                    .scaleEffect(on ? 1.05 : 1)
                    .animation(.spring(response: 0.3, dampingFraction: 0.5), value: on)
            }
        }
    }
}

/// Every moving sheet: the rides sheets' card, scrolling. Shown with `.sheet { … .dorrThemed() }`.
struct MvSheet<Content: View>: View {
    @Environment(\.wa) private var wa
    @ViewBuilder let content: () -> Content

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 10) { content() }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 18).padding(.top, 22).padding(.bottom, 18)
        }
        .background(wa.settingsCard.ignoresSafeArea())
    }
}

// MARK: - Device-clock countdowns

/// The seconds left until a server time, on the device clock (nothing asks the server).
func mvSecondsLeft(_ iso: String?, now: Date = Date()) -> Int? {
    guard let until = mvTime(iso) else { return nil }
    return Int(until.timeIntervalSince(now))
}

/// Redraws its content every second from the device clock (Android's `rememberSecondsLeft`).
struct MvTicking<Content: View>: View {
    @ViewBuilder let content: (Date) -> Content

    var body: some View {
        TimelineView(.periodic(from: .now, by: 1)) { context in content(context.date) }
    }
}

extension View {
    /// `onEnd` runs once when a countdown that was running reaches zero (the screen reloads once, the rides bidding
    /// way); a time already over when the page opens stays quiet, so it can't reload in a loop. One sleep, no timer.
    func mvCountdownEnd(_ iso: String?, onEnd: @escaping () async -> Void) -> some View {
        task(id: iso ?? "") {
            guard let until = mvTime(iso) else { return }
            let left = until.timeIntervalSinceNow
            guard left > 0 else { return }
            try? await Task.sleep(nanoseconds: UInt64((left + 0.5) * 1_000_000_000))
            guard !Task.isCancelled else { return }
            await onEnd()
        }
    }
}

/// "02:15:09", with the days before it when there are any.
func countdownText(_ seconds: Int) -> String {
    let s = max(seconds, 0)
    let clock = String(format: "%02ld:%02ld:%02ld", (s % 86400) / 3600, (s % 3600) / 60, s % 60)
    return s >= 86400 ? L("mv_days_and_clock", s / 86400, clock) : clock
}

// MARK: - Live, no polling

/// Live, with no timer (the owner's rule): one reload on open and on a reconnect or a pull, one on each `moving.changed`
/// of this request, and the truck moved from `moving.driver_location` itself. MovingHost keeps the private channel open.
struct MovingLive: ViewModifier {
    @ObservedObject private var network = NetworkMonitor.shared
    let uuid: String
    let onChanged: () async -> Void
    var onDriver: ((Double, Double, Double?) -> Void)?

    func body(content: Content) -> some View {
        content
            .task(id: "\(uuid)|\(network.reconnectTick)") { await onChanged() }
            .onReceive(ChatRealtime.shared.$connected.removeDuplicates().dropFirst().filter { $0 }) { _ in Task { await onChanged() } }
            .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { e in
                if e.name == "moving.changed" {
                    if e.str("uuid") == uuid { Task { await onChanged() } }
                } else if e.name == "moving.driver_location" {
                    if let lat = e.data["lat"]?.dbl, let lng = e.data["lng"]?.dbl { onDriver?(lat, lng, e.data["heading"]?.dbl) }
                }
            }
    }
}

extension View {
    func movingLive(_ uuid: String, onChanged: @escaping () async -> Void, onDriver: ((Double, Double, Double?) -> Void)? = nil) -> some View {
        modifier(MovingLive(uuid: uuid, onChanged: onChanged, onDriver: onDriver))
    }
}

// MARK: - Words

/// «وصلك N عرض» in correct Arabic: one, two, three to ten, and more take different words.
func offersCountText(_ count: Int) -> String {
    switch count {
    case 1: return L("mv_offers_count_one")
    case 2: return L("mv_offers_count_two")
    case 3...10: return L("mv_offers_count_few", count)
    default: return L("mv_offers_count_many", count)
    }
}

/// The status of a request in words, and its colour.
func movingStatusLook(_ status: String?, _ wa: WaPalette) -> (String, Color) {
    switch status {
    case "draft": return (L("mv_st_draft"), wa.settingsMut)
    case "open": return (L("mv_st_open"), AppColors.warning)
    case "confirmed": return (L("mv_st_confirmed"), AppColors.brandNavy)
    case "assigned": return (L("mv_st_assigned"), AppColors.brandNavy)
    case "driver_on_way": return (L("mv_st_driver_on_way"), AppColors.brandNavy)
    case "arrived": return (L("mv_st_arrived"), AppColors.brandNavy)
    case "loading": return (L("mv_st_loading"), AppColors.brandNavy)
    case "in_transit": return (L("mv_st_in_transit"), AppColors.brandNavy)
    case "unloading": return (L("mv_st_unloading"), AppColors.brandNavy)
    case "delivered": return (L("mv_st_delivered"), AppColors.success)
    case "completed": return (L("mv_st_completed"), AppColors.success)
    case "disputed": return (L("mv_st_disputed"), AppColors.warning)
    case "expired": return (L("mv_st_expired"), wa.settingsMut)
    case "withdrawn": return (L("mv_st_withdrawn"), wa.settingsMut)
    default: return (L("mv_st_cancelled"), AppColors.danger)
    }
}

func loadTypeLabel(_ type: String?) -> String {
    switch type {
    case "single_piece": return L("mv_load_single")
    case "commercial": return L("mv_load_commercial")
    default: return L("mv_load_home")
    }
}

/// The name of a key of `extras` or of an offer's `includes`.
func extraLabel(_ key: String) -> String {
    switch key {
    case "packing": return L("mv_extra_packing")
    case "disassembly": return L("mv_extra_disassembly")
    case "crane": return L("mv_extra_crane")
    case "technician": return L("mv_extra_technician")
    case "tax": return L("mv_extra_tax")
    default: return key
    }
}

/// The ticked parts of an offer's (or an invoice's) `includes` (an empty one may come as `[]`).
func includedKeys(_ includes: JSONAny?) -> [String] {
    guard case .object(let map)? = includes else { return [] }
    // A JSON object keeps no order here: the known parts first, in the wizard's order.
    let known = ["packing", "disassembly", "crane", "technician", "tax"]
    let keys = known.filter { map[$0] != nil } + map.keys.filter { !known.contains($0) }.sorted()
    return keys.filter { key in
        let v = map[key]?.str
        return v == "true" || v == "1"
    }
}
