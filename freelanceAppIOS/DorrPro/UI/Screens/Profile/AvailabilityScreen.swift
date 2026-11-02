import SwiftUI

// freelanceApp `ui/screens/AvailabilityScreen.kt`.

private let dayNames = ["day_sun", "day_mon", "day_tue", "day_wed", "day_thu", "day_fri", "day_sat"]

/// One weekday in the editor: switched on or off, and its hours (kept as the typed `HH:MM` text).
private struct DayState {
    let day: Int
    var on: Bool
    var from: String
    var to: String
}

private func timeShape(_ text: String) -> Bool { text.range(of: #"^([01]\d|2[0-3]):[0-5]\d$"#, options: .regularExpression) != nil }

/// `formatMinor(minor, null)` of WalletKit.kt without its left-to-right mark (it would stop the field from parsing again).
private func availabilityFormatMinor(_ minor: Int64) -> String {
    let sign = minor < 0 ? "-" : ""
    let abs = Swift.abs(minor)
    // Same as Android: a left-to-right mark in front (WalletKit.kt `formatMinor`), which `parseMinor` then refuses.
    return "\u{200E}" + String(format: "%@%lld.%02lld", sign, abs / 100, abs % 100)
}

/// `parseMinor` of WalletKit.kt: "12.5" or "12,5" → 1250 minor units; nil when it is not a positive amount with at most two decimals.
private func availabilityParseMinor(_ text: String) -> Int64? {
    let clean = text.trimmingCharacters(in: .whitespacesAndNewlines).replacingOccurrences(of: ",", with: ".")
    guard clean.range(of: #"^\d{1,9}(\.\d{1,2})?$"#, options: .regularExpression) != nil else { return nil }
    let parts = clean.split(separator: ".", omittingEmptySubsequences: false)
    let whole = Int64(parts[0]) ?? 0
    let cents = parts.count > 1 ? Int64(parts[1].padding(toLength: 2, withPad: "0", startingAt: 0)) ?? 0 : 0
    let value = whole * 100 + cents
    return value > 0 ? value : nil
}

/**
 * Availability and guide price (FL-106): whether new requests are taken (with an optional day it lifts by itself), a guide price
 * (hourly or fixed, from – to), the weekly hours, and time off. Read once when the page opens; the form saves with one button, time
 * off is added and removed at once.
 */
struct AvailabilityScreen: View {
    let onBack: () -> Void

    @Environment(\.wa) private var wa

    @State private var loaded = false
    @State private var accepting = true
    @State private var pausedUntil = ""
    @State private var pauseReason = ""
    @State private var rateType = "none"
    @State private var rateMin = ""
    @State private var rateMax = ""
    @State private var currency = ""
    @State private var days: [DayState] = dayNames.indices.map { DayState(day: $0, on: false, from: "09:00", to: "17:00") }
    @State private var timeOff: [TimeOffDto] = []
    @State private var newStart = ""
    @State private var newEnd = ""
    @State private var newReason = ""
    @State private var busy = false
    @State private var notice: String?
    @State private var error: String?

    var body: some View {
        Page(title: L("availability_title"), onBack: onBack) {
            // The pause: no new offers while it is on.
            Card {
                HStack(spacing: 10) {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("availability_accepting")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                        Text(L("availability_accepting_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    RedToggle(on: accepting) { accepting.toggle() }
                }
                if !accepting {
                    DateField(value: $pausedUntil, label: L("availability_back_on"))
                    DorrTextField(value: $pauseReason.profileTake(200), label: L("availability_pause_reason"), icon: "text.alignleft")
                }
            }

            // The guide price.
            Card {
                FieldLabel(text: L("availability_rate"))
                ChipRow(
                    options: [(value: "none", label: L("availability_rate_none")), (value: "hourly", label: L("availability_rate_hourly")), (value: "fixed", label: L("availability_rate_fixed"))],
                    selected: [rateType],
                    onToggle: { rateType = $0 }
                )
                if rateType != "none" {
                    HStack(alignment: .top, spacing: 10) {
                        DorrTextField(value: $rateMin, label: L("availability_rate_from", currency), icon: "banknote", keyboard: .decimalPad)
                            .frame(maxWidth: .infinity)
                        DorrTextField(value: $rateMax, label: L("availability_rate_to", currency), icon: "banknote", keyboard: .decimalPad)
                            .frame(maxWidth: .infinity)
                    }
                }
            }

            // The weekly hours.
            Card {
                FieldLabel(text: L("availability_hours"))
                ForEach(days.indices, id: \.self) { i in
                    HStack(spacing: 8) {
                        RedToggle(on: days[i].on) { days[i].on.toggle() }
                        Text(L(dayNames[days[i].day])).font(.dorr(13)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                        if days[i].on {
                            DorrTextField(value: $days[i].from.profileTake(5), keyboard: .numbersAndPunctuation, error: !timeShape(days[i].from))
                                .frame(maxWidth: .infinity)
                            DorrTextField(value: $days[i].to.profileTake(5), keyboard: .numbersAndPunctuation, error: !timeShape(days[i].to) || days[i].to <= days[i].from)
                                .frame(maxWidth: .infinity)
                        }
                    }
                }
            }

            SettingsPrimaryButton(text: L("profile_save"), enabled: loaded, loading: busy, action: save)

            // Time off: added and removed at once.
            Card {
                FieldLabel(text: L("availability_time_off"))
                if timeOff.isEmpty { Text(L("availability_time_off_none")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                ForEach(timeOff, id: \.id) { t in
                    HStack(spacing: 10) {
                        VStack(alignment: .leading, spacing: 0) {
                            Text(verbatim: "\u{200E}\(t.startsOn ?? "")  →  \(t.endsOn ?? "")").font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                            if let reason = t.reason { Text(verbatim: reason).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        ProfileTextLink(text: L("common_delete"), size: 13) { removeTimeOff(t) }.padding(6)
                    }
                }
                DateField(value: $newStart, label: L("availability_from"))
                DateField(value: $newEnd, label: L("availability_to"))
                DorrTextField(value: $newReason.profileTake(200), label: L("availability_pause_reason"))
                SettingsPrimaryButton(text: L("availability_add_time_off"), enabled: !newStart.isEmpty && !newEnd.isEmpty && newEnd >= newStart, action: addTimeOff)
            }
        }
        .messageToast(notice)
        .messageToast(error)
        .task {
            if let code = await AvailabilityApi.walletCurrency() { currency = code }
            let result = await AvailabilityApi.show()
            if let a = result.value {
                accepting = a.acceptingRequests ?? true
                pausedUntil = a.pausedUntil ?? ""
                pauseReason = a.pauseReason ?? ""
                rateType = a.rate?.type ?? "none"
                rateMin = a.rate?.minMinor.map(availabilityFormatMinor) ?? ""
                rateMax = a.rate?.maxMinor.map(availabilityFormatMinor) ?? ""
                for i in days.indices {
                    let h = (a.weeklyHours ?? []).first { $0.day == days[i].day }
                    days[i].on = h != nil
                    if let h {
                        days[i].from = h.from ?? ""
                        days[i].to = h.to ?? ""
                    }
                }
                timeOff = a.timeOff ?? []
                loaded = true
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    private func save() {
        error = nil
        notice = nil
        let min = availabilityParseMinor(rateMin)
        let blankMax = rateMax.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        let max = blankMax ? nil : availabilityParseMinor(rateMax)
        if rateType != "none" {
            let bad: Bool
            if let min {
                bad = min <= 0 || (!blankMax && (max == nil || max! < min))
            } else {
                bad = true
            }
            if bad {
                error = L("availability_rate_invalid")
                return
            }
        }
        let on = days.filter(\.on)
        if on.contains(where: { !timeShape($0.from) || !timeShape($0.to) || $0.to <= $0.from }) {
            error = L("availability_hours_invalid")
            return
        }
        busy = true
        let trimmedReason = pauseReason.trimmingCharacters(in: .whitespacesAndNewlines)
        let request = AvailabilityRequest(
            acceptingRequests: accepting,
            pausedUntil: accepting || pausedUntil.trimmingCharacters(in: .whitespaces).isEmpty ? nil : pausedUntil,
            pauseReason: accepting || trimmedReason.isEmpty ? nil : trimmedReason,
            rateType: rateType,
            rateMinMinor: rateType == "none" ? nil : min,
            rateMaxMinor: rateType == "none" ? nil : max,
            weeklyHours: on.map { WeeklyHoursDto(day: $0.day, from: $0.from, to: $0.to) }
        )
        Task {
            let result = await AvailabilityApi.update(request)
            if result.value != nil { notice = L("profile_saved") } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
            busy = false
        }
    }

    private func addTimeOff() {
        error = nil
        let reason = newReason.trimmingCharacters(in: .whitespacesAndNewlines)
        let request = TimeOffRequest(startsOn: newStart, endsOn: newEnd, reason: reason.isEmpty ? nil : reason)
        Task {
            let result = await AvailabilityApi.addTimeOff(request)
            if let added = result.value {
                timeOff.append(added)
                timeOff.sort { ($0.startsOn ?? "") < ($1.startsOn ?? "") }
                newStart = ""
                newEnd = ""
                newReason = ""
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    private func removeTimeOff(_ period: TimeOffDto) {
        Task {
            let failed = await AvailabilityApi.deleteTimeOff(period.id ?? 0, offline: L("login_error_offline"), generic: L("login_error_generic"))
            if let failed { error = failed } else { timeOff.removeAll { $0 == period } }
        }
    }
}
