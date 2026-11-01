import SwiftUI

// androidApp `rides/RidesSchedule.kt` — booking ahead (R-12, R-105..108) and "My rides" (R-163..165): the time
// picker (a day strip, then the hour and minutes that roll like a wheel), the "your ride is booked" card with its
// calendar badge, the free-cancel limit and the captain who reserved it, and the list of current / upcoming / past
// rides. The policy limits come from the quote; the server checks them again.

private let minuteStep = 5

/// Dates in the app's language (Android's `Locale.getDefault()`).
private func rideFormatter(_ pattern: String) -> DateFormatter {
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current)
    f.dateFormat = pattern
    return f
}

func rideClock(_ t: Date) -> String {
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current)
    f.timeStyle = .short
    f.dateStyle = .none
    return f.string(from: t)
}

/// "Today · 9:30 AM", "Tomorrow · …", or "Thursday 16 Oct · …".
func rideWhenText(_ t: Date) -> String {
    let cal = Calendar.current
    let day: String
    if cal.isDateInToday(t) {
        day = L("rd_schedule_today")
    } else if cal.isDateInTomorrow(t) {
        day = L("rd_schedule_tomorrow")
    } else {
        day = rideFormatter("EEEE d MMM").string(from: t)
    }
    return "\(day) · \(rideClock(t))"
}

/// The earliest time the policy allows, rounded up to the next 5 minutes.
private func earliest(_ minAheadS: Int) -> Date {
    let cal = Calendar.current
    var t = Date().addingTimeInterval(TimeInterval(minAheadS))
    t = cal.date(bySetting: .second, value: 0, of: t) ?? t
    let minute = cal.component(.minute, from: t)
    let extra = (minuteStep - minute % minuteStep) % minuteStep
    return cal.date(byAdding: .minute, value: extra, to: t) ?? t
}

/// "Now" | "📅 Book ahead" — on the first card and above the prices.
struct WhenChips: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState

    var body: some View {
        HStack(spacing: 8) {
            chip("car.fill", L("rd_now"), state.scheduledAt == nil) { state.scheduledAt = nil }
            chip("calendar", state.scheduledAt.map(rideWhenText) ?? L("rd_schedule"), state.scheduledAt != nil) { state.scheduleOpen = true }
            Spacer(minLength: 0)
        }
    }

    private func chip(_ icon: String, _ label: String, _ selected: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 13))
                Text(verbatim: label).font(.dorr(13, selected ? .bold : .semibold)).lineLimit(1)
            }
            .foregroundStyle(selected ? .white : wa.settingsInk)
            .padding(.horizontal, 14).padding(.vertical, 9)
            .background(selected ? wa.accent : wa.settingsBackground, in: Capsule())
        }
        .buttonStyle(.plain)
        .animation(.easeInOut(duration: 0.22), value: selected)
    }
}

/// Picking the day and the time; the limits are the category's (min / max ahead, free cancel).
struct SchedulePickerSheet: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let onClose: () -> Void
    @State private var picked = Date()
    @State private var direction = 1

    private var rules: RideSchedulingDto { state.selected?.scheduling ?? state.quotes.lazy.compactMap(\.scheduling).first ?? RideSchedulingDto() }
    private var first: Date { earliest(rules.minAheadS ?? 3600) }
    private var last: Date { Date().addingTimeInterval(TimeInterval(rules.maxAheadS ?? 604_800)) }

    private func clamp(_ t: Date) -> Date { min(max(t, first), last) }

    private var days: [Date] {
        let cal = Calendar.current
        var out: [Date] = []
        var d = cal.startOfDay(for: first)
        let end = cal.startOfDay(for: last)
        while d <= end {
            out.append(d)
            d = cal.date(byAdding: .day, value: 1, to: d) ?? end.addingTimeInterval(1)
        }
        return out
    }

    var body: some View {
        let cal = Calendar.current
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 10) {
                Image(systemName: "calendar").foregroundStyle(wa.accent)
                    .frame(width: 40, height: 40).background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                Text(L("rd_schedule_title")).font(.dorr(19, .heavy)).foregroundStyle(wa.settingsInk)
            }
            // The days, as a strip of cards.
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(days, id: \.self) { day in
                        let on = cal.isDate(picked, inSameDayAs: day)
                        Button {
                            let time = cal.dateComponents([.hour, .minute], from: picked)
                            picked = clamp(cal.date(bySettingHour: time.hour ?? 0, minute: time.minute ?? 0, second: 0, of: day) ?? day)
                        } label: {
                            VStack(spacing: 0) {
                                Text(verbatim: cal.isDateInToday(day) ? L("rd_schedule_today") : (cal.isDateInTomorrow(day) ? L("rd_schedule_tomorrow") : rideFormatter("EEE").string(from: day)))
                                    .font(.dorr(11)).foregroundStyle(on ? .white.opacity(0.85) : wa.settingsMut).lineLimit(1)
                                Text(verbatim: rideFormatter("d").string(from: day)).font(.dorr(22, .heavy)).foregroundStyle(on ? .white : wa.settingsInk)
                                Text(verbatim: rideFormatter("MMM").string(from: day)).font(.dorr(11)).foregroundStyle(on ? .white.opacity(0.85) : wa.settingsMut)
                            }
                            .frame(width: 64).padding(.vertical, 10)
                            .background(on ? wa.accent : wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                            .scaleEffect(on ? 1.06 : 1)
                        }
                        .buttonStyle(.plain)
                        .animation(.spring(response: 0.3, dampingFraction: 0.55), value: on)
                    }
                }
                .padding(.vertical, 4)
            }
            .padding(.top, 16)
            // The time: hour and minutes roll up and down.
            Text(L("rd_schedule_time")).font(.dorr(12)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity).padding(.top, 20)
            HStack(spacing: 6) {
                wheel(rideFormatter("HH").string(from: picked),
                      up: { direction = 1; picked = clamp(picked.addingTimeInterval(3600)) },
                      down: { direction = -1; picked = clamp(picked.addingTimeInterval(-3600)) })
                Text(verbatim: ":").font(.dorr(40, .heavy)).foregroundStyle(wa.settingsInk)
                wheel(rideFormatter("mm").string(from: picked),
                      up: { direction = 1; picked = clamp(picked.addingTimeInterval(TimeInterval(minuteStep * 60))) },
                      down: { direction = -1; picked = clamp(picked.addingTimeInterval(-TimeInterval(minuteStep * 60))) })
            }
            .environment(\.layoutDirection, .leftToRight)
            .frame(maxWidth: .infinity)
            .padding(.vertical, 6)
            Text(verbatim: "\(rideClock(picked)) · \(L("rd_schedule_local"))").font(.dorr(12)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity)
            if abs(picked.timeIntervalSince(first)) < 1 {
                Text(verbatim: L("rd_schedule_earliest", rideWhenText(first))).font(.dorr(12)).foregroundStyle(AppColors.warning).frame(maxWidth: .infinity).padding(.top, 4)
            }
            // The cancellation rule, before confirming (R-105).
            HStack(spacing: 8) {
                Image(systemName: "info.circle.fill").foregroundStyle(AppColors.success)
                Text(verbatim: L("rd_schedule_policy", (rules.freeCancelBeforeS ?? 3600) / 60)).font(.dorr(12)).foregroundStyle(wa.settingsInk)
                Spacer(minLength: 0)
            }
            .padding(12)
            .background(AppColors.success.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .padding(.top, 16)
            AccentButton(text: L("rd_schedule_confirm")) {
                state.scheduledAt = picked
                onClose()
            }
            .padding(.top, 16)
        }
        .padding(.horizontal, 20).padding(.top, 20).padding(.bottom, 28)
        .frame(maxHeight: .infinity, alignment: .top)
        .background(wa.settingsCard.ignoresSafeArea())
        .onAppear {
            if let at = state.scheduledAt, at >= first, at <= last { picked = at } else { picked = first }
        }
    }

    /// One wheel: ▲ / the number (rolls in the direction moved) / ▼.
    private func wheel(_ value: String, up: @escaping () -> Void, down: @escaping () -> Void) -> some View {
        VStack(spacing: 0) {
            Button(action: up) { Image(systemName: "chevron.up").font(.system(size: 24, weight: .semibold)).foregroundStyle(wa.accent).frame(width: 40, height: 40) }
                .buttonStyle(.plain)
            ZStack {
                Text(verbatim: value).font(.dorr(36, .heavy)).foregroundStyle(wa.settingsInk)
                    .id(value)
                    .transition(.asymmetric(insertion: .move(edge: direction > 0 ? .bottom : .top).combined(with: .opacity),
                                            removal: .move(edge: direction > 0 ? .top : .bottom).combined(with: .opacity)))
            }
            .frame(width: 84, height: 64)
            .clipped()
            .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .animation(.easeOut(duration: 0.2), value: value)
            Button(action: down) { Image(systemName: "chevron.down").font(.system(size: 24, weight: .semibold)).foregroundStyle(wa.accent).frame(width: 40, height: 40) }
                .buttonStyle(.plain)
        }
    }
}

/// The booked ride, until its time comes: when, where, the captain who took it, cancelling.
struct ScheduledPanel: View {
    @Environment(\.wa) private var wa
    let ride: RideDto
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var pop: CGFloat = 0.6

    var body: some View {
        if let at = parseRideTime(ride.scheduled?.at) {
            let freeUntil = parseRideTime(ride.scheduled?.freeCancelUntil)
            let startsSearch = parseRideTime(ride.scheduled?.startsSearchAt)
            VStack(spacing: 0) {
                VStack(spacing: 0) {
                    Text(verbatim: rideFormatter("MMM").string(from: at)).font(.dorr(12, .bold)).foregroundStyle(.white).frame(maxWidth: .infinity, minHeight: 24).background(wa.accent)
                    Text(verbatim: rideFormatter("d").string(from: at)).font(.dorr(30, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: rideFormatter("EEE").string(from: at)).font(.dorr(10)).foregroundStyle(wa.settingsMut)
                }
                .frame(width: 74, height: 82, alignment: .top)
                .background(wa.settingsCard)
                .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(wa.settingsInk.opacity(0.08), lineWidth: 1))
                .scaleEffect(pop)
                .onAppear { withAnimation(.spring(response: 0.5, dampingFraction: 0.5)) { pop = 1 } }
                HStack(spacing: 6) {
                    Image(systemName: "calendar.badge.checkmark").foregroundStyle(AppColors.success)
                    Text(L("rd_scheduled_title")).font(.dorr(19, .heavy)).foregroundStyle(wa.settingsInk)
                }
                .padding(.top, 10)
                Text(verbatim: rideWhenText(at)).font(.dorr(16, .bold)).foregroundStyle(wa.accent)
            }
            .frame(maxWidth: .infinity)
            VStack(alignment: .leading, spacing: 0) {
                TripLine(dot: AppColors.brandNavy, address: ride.origin?.address)
                ForEach(Array((ride.stops ?? []).enumerated()), id: \.offset) { _, s in TripLine(dot: AppColors.warning, address: s.address) }
                if let d = ride.destination { TripLine(dot: AppColors.brandOrange, address: d.address) }
                if let price = ride.agreedPriceMinor ?? ride.estimate?.maxMinor {
                    HStack {
                        Text(verbatim: ride.category?.name ?? "").font(.dorr(13)).foregroundStyle(wa.settingsMut)
                        Spacer()
                        Text(verbatim: rideMoney(price, ride.currency)).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                    }
                    .padding(.top, 6)
                }
            }
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .padding(.top, 12)
            // The captain who reserved it, or when the search starts.
            if let reserved = ride.reservedDriver {
                HStack(spacing: 10) {
                    ZStack {
                        Circle().fill(wa.accent.opacity(0.12))
                        if let url = reserved.avatarUrl, let u = URL(string: url) {
                            AsyncImage(url: u) { $0.resizable().scaledToFill() } placeholder: { Color.clear }.clipShape(Circle())
                        } else {
                            Text(verbatim: String((reserved.name ?? "").prefix(1))).font(.dorr(15, .bold)).foregroundStyle(wa.accent)
                        }
                    }
                    .frame(width: 42, height: 42)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: L("rd_scheduled_reserved", reserved.name ?? "")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                        HStack(spacing: 2) {
                            Image(systemName: "star.fill").font(.system(size: 11)).foregroundStyle(AppColors.warning)
                            Text(verbatim: reserved.rating.map { String(format: "%.1f", $0) } ?? L("rd_new_rating")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        }
                    }
                    Spacer(minLength: 0)
                }
                .padding(10)
                .background(AppColors.success.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .padding(.top, 10)
            } else {
                let lead = startsSearch.map { Int(at.timeIntervalSince($0) / 60) } ?? 30
                InfoLine(icon: "clock", tint: wa.accent, text: L("rd_scheduled_searching_at", lead)).padding(.top, 10)
            }
            if let freeUntil, freeUntil > Date() {
                InfoLine(icon: "checkmark.circle.fill", tint: AppColors.success, text: L("rd_scheduled_free_until", rideWhenText(freeUntil)))
            } else {
                InfoLine(icon: "exclamationmark.triangle", tint: AppColors.warning, text: L("rd_scheduled_free_over"))
            }
            Text(L("rd_scheduled_waiting_note")).font(.dorr(11)).foregroundStyle(wa.settingsMut).padding(.top, 4)
            HStack(spacing: 10) {
                AccentButton(text: L("rd_done"), action: onDone)
                Button(action: onCancel) {
                    Text(L("rd_scheduled_cancel")).font(.dorr(15, .bold)).foregroundStyle(AppColors.danger)
                        .frame(maxWidth: .infinity, minHeight: 54)
                        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(AppColors.danger.opacity(0.4), lineWidth: 1))
                }
                .buttonStyle(WaPressStyle())
            }
            .padding(.top, 14)
        }
    }
}

struct TripLine: View {
    @Environment(\.wa) private var wa
    let dot: Color
    let address: String?

    var body: some View {
        HStack(spacing: 10) {
            Circle().fill(dot).frame(width: 9, height: 9)
            Text(verbatim: address ?? "—").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineLimit(1)
        }
        .padding(.vertical, 3)
    }
}

private struct InfoLine: View {
    @Environment(\.wa) private var wa
    let icon: String
    let tint: Color
    let text: String

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: icon).font(.system(size: 15)).foregroundStyle(tint)
            Text(verbatim: text).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
            Spacer(minLength: 0)
        }
        .padding(.vertical, 4)
    }
}

/// My rides: current, upcoming (booked ahead) and past — tap one to open it.
struct MyRidesPage: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let onClose: () -> Void
    @State private var tab = "scheduled"
    @State private var items: [RideHistoryItemDto]?

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 10) {
                RideRoundButton(icon: "arrow.backward", label: L("common_back"), action: onClose)
                Text(L("rd_my_rides")).font(.dorr(20, .heavy)).foregroundStyle(wa.settingsInk)
                Spacer()
                // R-133..R-140: my complaints and disputes.
                Button { state.caseRideUuid = nil; state.caseStartNew = false; state.casesOpen = true } label: {
                    Text(L("rd_cases_mine")).font(.dorr(13, .bold)).foregroundStyle(wa.accent)
                        .padding(.horizontal, 14).padding(.vertical, 8).background(wa.accent.opacity(0.1), in: Capsule())
                }
                .buttonStyle(.plain)
            }
            .padding(12)
            // The tabs, a pill that slides to the chosen one.
            HStack(spacing: 0) {
                ForEach([("current", "rd_tab_current"), ("scheduled", "rd_tab_scheduled"), ("past", "rd_tab_past")], id: \.0) { key, label in
                    let on = key == tab
                    Button { tab = key } label: {
                        Text(L(label)).font(.dorr(14, on ? .bold : .semibold)).foregroundStyle(on ? .white : wa.settingsInk)
                            .frame(maxWidth: .infinity).padding(.vertical, 10)
                            .background(on ? wa.accent : .clear, in: Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(4)
            .background(wa.settingsCard, in: Capsule())
            .padding(.horizontal, 16)
            .animation(.easeInOut(duration: 0.24), value: tab)
            Group {
                if let list = items {
                    if list.isEmpty {
                        VStack(spacing: 10) {
                            Image(systemName: tab == "scheduled" ? "calendar" : "car.fill").font(.system(size: 30)).foregroundStyle(wa.accent)
                                .frame(width: 72, height: 72).background(wa.accent.opacity(0.1), in: Circle())
                            Text(L("rd_my_rides_empty")).font(.dorr(15)).foregroundStyle(wa.settingsMut)
                        }
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                    } else {
                        ScrollView {
                            LazyVStack(spacing: 10) {
                                ForEach(list) { item in
                                    let reportable = tab == "past" && ["completed", "canceled", "no_show"].contains(item.status)
                                    HistoryCard(item: item, onReport: reportable ? { state.caseRideUuid = item.uuid; state.caseStartNew = true; state.casesOpen = true } : nil) {
                                        Task {
                                            if let ride = try? await RidesApi.ride(item.uuid).data {
                                                state.ride = ride
                                                state.fromHistory = true
                                                onClose()
                                            }
                                        }
                                    }
                                }
                            }
                            .padding(.horizontal, 16).padding(.vertical, 10)
                        }
                    }
                } else {
                    ProgressView().tint(wa.accent).frame(maxWidth: .infinity, maxHeight: .infinity)
                }
            }
            .transition(.opacity)
        }
        .background(wa.settingsBackground.ignoresSafeArea())
        .onAppear { if state.ride != nil { tab = "current" } }
        .task(id: tab) {
            items = nil
            items = (try? await RidesApi.history(tab: tab).data?.items) ?? []
        }
    }
}

private struct HistoryCard: View {
    @Environment(\.wa) private var wa
    let item: RideHistoryItemDto
    let onReport: (() -> Void)?
    let action: () -> Void

    private var look: (String, Color) {
        switch item.status {
        case "scheduled": return (L("rd_status_scheduled"), wa.accent)
        case "searching", "bidding", "awaiting_payment": return (L("rd_status_searching"), AppColors.warning)
        case "accepted": return (L("rd_status_accepted"), AppColors.brandNavy)
        case "arrived": return (L("rd_status_arrived"), AppColors.brandNavy)
        case "in_progress": return (L("rd_status_in_progress"), AppColors.brandNavy)
        case "completed": return (L("rd_status_completed"), AppColors.success)
        case "no_show": return (L("rd_status_no_show"), AppColors.danger)
        case "expired": return (L("rd_status_expired"), wa.settingsMut)
        default: return (L("rd_status_canceled"), AppColors.danger)
        }
    }

    var body: some View {
        let (label, color) = look
        Button(action: action) {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    Image(systemName: item.status == "scheduled" ? "calendar" : "car.fill").font(.system(size: 18)).foregroundStyle(color)
                        .frame(width: 40, height: 40).background(color.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: item.category ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                        if let t = parseRideTime(item.scheduledAt ?? item.requestedAt) { Text(verbatim: rideWhenText(t)).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    }
                    Spacer(minLength: 0)
                    Text(verbatim: label).font(.dorr(11, .bold)).foregroundStyle(color).padding(.horizontal, 10).padding(.vertical, 4).background(color.opacity(0.12), in: Capsule())
                }
                TripLine(dot: AppColors.brandNavy, address: item.originAddress).padding(.top, 8)
                if let d = item.destinationAddress { TripLine(dot: AppColors.brandOrange, address: d) }
                if item.totalMinor != nil || item.driverName != nil {
                    HStack {
                        Text(verbatim: item.driverName ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        Spacer()
                        if let total = item.totalMinor {
                            Text(verbatim: rideMoney(total, RideCurrencyDto(symbol: item.currency))).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                        }
                    }
                    .padding(.top, 6)
                }
                if let onReport {
                    Button(action: onReport) { Text(L("rd_cases_report")).font(.dorr(12, .semibold)).foregroundStyle(wa.accent).padding(.vertical, 4) }
                        .buttonStyle(.plain)
                        .padding(.top, 6)
                }
            }
            .padding(14)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(WaPressStyle())
    }
}
