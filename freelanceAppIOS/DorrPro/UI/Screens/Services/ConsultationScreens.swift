import SwiftUI

// freelanceApp `ui/screens/ConsultationScreens.kt`.
//
// FL-L07, paid consultations on the freelancer's side, listed like ready-made services: the hub (my consultations with their state, my
// numbers, the bookings in three lists), the editor of one consultation (title, lengths with a price, weekly hours in my phone's time
// zone, sent to the staff for review), and one booking (when, who, what about, the money, answering or cancelling). The session itself is
// a video call (FL-L16), so there is no room. Everything is read when a page opens, when the connection returns and when a notice arrives —
// never on a timer; the countdowns read the phone's clock.

private let DAYS = ["day_sun", "day_mon", "day_tue", "day_wed", "day_thu", "day_fri", "day_sat"]

private func isHHMM(_ s: String) -> Bool { s.range(of: #"^([01]\d|2[0-3]):[0-5]\d$"#, options: .regularExpression) != nil }

private func consFormat(_ iso: String?, _ pattern: String, latin: Bool = false) -> String {
    guard let date = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = latin ? Locale(identifier: "en_US_POSIX") : svcDateLocale
    f.timeZone = .current
    f.dateFormat = pattern
    return f.string(from: date)
}

private func hm(_ iso: String?) -> String { consFormat(iso, "HH:mm", latin: true) }

/// "Mon 9 Nov" in the app language, digits Latin.
private func dayLine(_ iso: String?) -> String {
    guard let date = parseInstant(iso) else { return "" }
    let day = Calendar.current.component(.day, from: date)
    return "\(consFormat(iso, "EEEE")) \(day) \(consFormat(iso, "MMMM"))"
}

private func phaseLabel(_ phase: String) -> String {
    switch phase {
    case "pending": return L("cns_phase_pending")
    case "declined": return L("cns_phase_declined")
    case "upcoming": return L("cns_phase_upcoming")
    case "live": return L("cns_phase_live")
    case "ended": return L("cns_phase_ended")
    case "completed": return L("cns_phase_completed")
    case "cancelled": return L("cns_phase_cancelled")
    case "disputed": return L("cns_phase_disputed")
    case "refunded": return L("cns_phase_refunded")
    default: return phase
    }
}

private func phaseTone(_ phase: String, _ wa: WaPalette) -> SvcTone {
    let c: Color
    switch phase {
    case "upcoming": c = wa.red
    case "live", "completed": c = Color(hex: 0x16A34A)
    case "ended", "disputed", "pending": c = Color(hex: 0xD97706)
    case "refunded": c = Color(hex: 0xDC2626)
    default: c = Color(hex: 0x64748B)
    }
    return SvcTone(ink: c, fill: c.opacity(wa.night ? 0.22 : 0.12))
}

/// "in 3 h", "in 2 days", "now" — from the phone's clock.
private func untilText(_ iso: String?) -> String? {
    guard let at = parseInstant(iso) else { return nil }
    let left = at.timeIntervalSinceNow
    if left < 0 { return nil }
    let days = Int(left / 86_400), hours = Int(left / 3600)
    if days >= 1 { return L("cns_in_days", days) }
    if hours >= 1 { return L("cns_in_hours", hours) }
    return L("cns_in_minutes", max(Int(left / 60), 1))
}

// MARK: - the hub

private let HUB_TABS = ["offers", "upcoming", "past", "cancelled"]

private func tabLabel(_ tab: String) -> String {
    switch tab {
    case "offers": return L("cns_tab_offers")
    case "past": return L("cns_tab_past")
    case "cancelled": return L("cns_tab_cancelled")
    default: return L("cns_tab_upcoming")
    }
}

struct ConsultationsScreen: View {
    let onBack: () -> Void
    let onOpenEditor: (String?) -> Void
    let onOpenConsultation: (String) -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var tab = "offers"
    @State private var pagedTab: String?
    @State private var info: ConsultationInfoDto?
    @State private var offers: [ConsultationOfferDto] = []
    @State private var offersLoaded = false
    @State private var error: String?
    @State private var busyUuid: String?
    @State private var deleting: ConsultationOfferDto?
    @StateObject private var paged = PagedRows<ConsultationDto>(perPage: 15, key: { $0.uuid ?? "" })

    private var offline: String { L("login_error_offline") }
    private var generic: String { L("login_error_generic") }

    private func loadInfo() async { if let i = await ConsultationsApi.info().value { info = i } }

    private func loadOffers() async {
        let r = await ConsultationsApi.offers()
        if let rows = r.value {
            offers = rows
            offersLoaded = true
            error = nil
        } else {
            error = r.errorText(offline) ?? generic
        }
    }

    private func reloadPaged() async {
        guard tab != "offers" else { return }
        // `remember(tab)`: another tab is another list, which starts from its skeleton.
        if pagedTab != tab {
            let t = tab
            pagedTab = t
            paged.reset { page in await ConsultationsApi.list(tab: t, page: page, perPage: 15) }
        }
        await paged.reload()
    }

    private func act(_ o: ConsultationOfferDto, _ kind: String) {
        guard let id = o.uuid else { return }
        busyUuid = id
        Task {
            let r: ApiResult<ConsultationOfferDto>
            switch kind {
            case "submit": r = await ConsultationsApi.submit(id)
            case "pause": r = await ConsultationsApi.pause(id)
            default: r = await ConsultationsApi.resume(id)
            }
            busyUuid = nil
            if r.value != nil {
                await AppToast.show(kind == "submit" ? L("cns_offer_sent") : L("cns_offer_updated"))
                await loadOffers()
                await loadInfo()
            } else {
                error = r.errorText(offline) ?? generic
            }
        }
    }

    private func delete(_ o: ConsultationOfferDto) {
        deleting = nil
        guard let id = o.uuid else { return }
        busyUuid = id
        Task {
            let r = await ConsultationsApi.delete(id)
            busyUuid = nil
            if r.value != nil {
                await loadOffers()
                await loadInfo()
            } else {
                error = r.errorText(offline) ?? generic
            }
        }
    }

    private struct LoadKey: Equatable { let tab: String; let tick: Int }

    var body: some View {
        Page(title: L("cns_title"), onBack: onBack, onNearEnd: { if tab != "offers" { Task { await paged.loadMore() } } }) {
            if let s = info {
                HubHero(s: s, openNow: offers.filter { $0.live ?? false }.count, onNew: { onOpenEditor(nil) })
                if (s.stats?.pending ?? 0) > 0 {
                    Notice(icon: "bell.badge.fill", text: L("cns_pending_banner", s.stats?.pending ?? 0), tone: phaseTone("pending", wa))
                }
                if !(s.featureOn ?? true) {
                    Notice(icon: "info.circle.fill", text: L("cns_feature_off"), tone: serviceTone("pending_review", night: wa.night))
                }
            }
            Segmented(options: HUB_TABS.map { ($0, tabLabel($0)) }, selected: tab,
                      marks: (info?.stats?.pending ?? 0) > 0 ? ["upcoming"] : []) { tab = $0 }
            if tab == "offers" {
                if !offersLoaded && error == nil {
                    SkeletonList()
                } else if offersLoaded && offers.isEmpty {
                    StartCard { onOpenEditor(nil) }
                } else {
                    ForEach(offers, id: \.id) { o in
                        OfferCard(o: o, busy: busyUuid != nil && busyUuid == o.uuid, onEdit: { onOpenEditor(o.uuid) },
                                  onAct: { act(o, $0) }, onDelete: { deleting = o })
                    }
                }
                if offersLoaded && !offers.isEmpty && info?.review == true {
                    Text(L("cns_review_note")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                        .frame(maxWidth: 520, alignment: .leading)
                        .frame(maxWidth: .infinity)
                }
            } else {
                if !paged.loaded && paged.failure == nil {
                    SkeletonList()
                } else if paged.loaded && paged.rows.isEmpty && paged.failure == nil {
                    EmptyState(icon: "calendar.badge.checkmark",
                               title: L(tab == "upcoming" ? "cns_empty_upcoming" : tab == "past" ? "cns_empty_past" : "cns_empty_cancelled"),
                               body: L("cns_empty_body"))
                        .padding(.top, 40)
                } else {
                    ForEach(paged.rows, id: \.id) { c in BookingCard(c: c) { if let id = c.uuid { onOpenConsultation(id) } } }
                }
                PagedFooter(paged: paged) { Task { await paged.loadMore() } }
            }
        }
        .messageToast(tab == "offers" ? error : paged.failure.map { $0.errorText(offline) ?? generic })
        .task(id: network.reconnectTick) {
            await loadInfo()
            await loadOffers()
        }
        .task(id: LoadKey(tab: tab, tick: network.reconnectTick)) { await reloadPaged() }
        .onReceive(Realtime.shared.changed) {
            Task {
                await loadInfo()
                await loadOffers()
                await reloadPaged()
            }
        }
        .alert(L("cns_delete_title"), isPresented: Binding(get: { deleting != nil }, set: { if !$0 { deleting = nil } }), presenting: deleting) { o in
            Button(L("common_cancel"), role: .cancel) { deleting = nil }
            Button(L("common_delete"), role: .destructive) { delete(o) }
        } message: { o in
            Text(L("cns_delete_body", o.title ?? ""))
        }
    }
}

/// The top of the hub: how many I have open, my numbers, and a new consultation.
private struct HubHero: View {
    @Environment(\.wa) private var wa
    let s: ConsultationInfoDto
    let openNow: Int
    let onNew: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 12) {
                Image(systemName: "video.fill").font(.system(size: 19)).foregroundStyle(.white)
                    .frame(width: 44, height: 44)
                    .background(Color.white.opacity(0.18), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("cns_hero_title")).font(.dorr(17, .heavy)).foregroundStyle(.white).lineLimit(1)
                    HStack(spacing: 6) {
                        Circle().fill(openNow > 0 ? Color(hex: 0x86EFAC) : Color.white.opacity(0.5)).frame(width: 8, height: 8)
                        Text(openNow > 0 ? L("cns_open_n", openNow) : L("cns_open_none")).font(.dorr(12, .semibold)).foregroundStyle(.white.opacity(0.92))
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            HStack(spacing: 8) {
                HeroStat(value: "\(s.stats?.upcoming ?? 0)", label: L("cns_stat_upcoming"))
                HeroStat(value: "\(s.stats?.completed ?? 0)", label: L("cns_stat_done"))
                HeroStat(value: svcMoney(s.stats?.earnedMinor ?? 0, s.decimals ?? 2, nil), label: L("cns_stat_earned", s.currency ?? ""))
                    .layoutPriority(1)
            }
            Button(action: onNew) {
                HStack(spacing: 8) {
                    Image(systemName: "plus").font(.system(size: 15, weight: .bold))
                    Text(L("cns_new_offer")).font(.dorr(14, .heavy))
                }
                .foregroundStyle(wa.red)
                .frame(maxWidth: .infinity, minHeight: 46)
                .background(Color.white, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            .buttonStyle(.plain)
        }
        .padding(18)
        .frame(maxWidth: 520, alignment: .leading)
        .background(LinearGradient(colors: [wa.red, wa.red.opacity(0.78)], startPoint: .topLeading, endPoint: .bottomTrailing),
                    in: RoundedRectangle(cornerRadius: 24, style: .continuous))
        .frame(maxWidth: .infinity)
    }
}

private struct HeroStat: View {
    let value: String
    let label: String

    var body: some View {
        VStack(spacing: 0) {
            Text(verbatim: value).font(.dorr(17, .heavy)).foregroundStyle(.white).lineLimit(1).minimumScaleFactor(0.7)
            Text(verbatim: label).font(.dorr(11)).foregroundStyle(.white.opacity(0.85)).lineLimit(1)
        }
        .padding(.vertical, 10)
        .padding(.horizontal, 8)
        .frame(maxWidth: .infinity)
        .background(Color.white.opacity(0.14), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// No consultation listed yet: how it works in three lines.
private struct StartCard: View {
    @Environment(\.wa) private var wa
    let onNew: () -> Void

    var body: some View {
        InfoCard(title: L("cns_start_title"), icon: "calendar.badge.checkmark") {
            ForEach(Array(["cns_start_1", "cns_start_2", "cns_start_3"].enumerated()), id: \.offset) { i, key in
                HStack(alignment: .top, spacing: 10) {
                    Text(verbatim: "\(i + 1)").font(.dorr(11, .heavy)).foregroundStyle(wa.red)
                        .frame(width: 22, height: 22)
                        .background(wa.red.opacity(0.12), in: Circle())
                    Text(L(key)).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
                }
            }
            SettingsPrimaryButton(text: L("cns_start_cta"), action: onNew)
        }
    }
}

/// One consultation I list: its title, state, lengths from a price, the bookings on it and what I can do next.
private struct OfferCard: View {
    @Environment(\.wa) private var wa
    let o: ConsultationOfferDto
    let busy: Bool
    let onEdit: () -> Void
    let onAct: (String) -> Void
    let onDelete: () -> Void

    var body: some View {
        let status = o.status ?? "draft"
        let tone = serviceTone(status, night: wa.night)
        let decimals = o.decimals ?? 2
        let lengths = (o.options ?? []).map { L("cns_len_price", $0.minutes ?? 0, svcMoney($0.priceMinor ?? 0, decimals, o.currency)) }.joined(separator: " · ")
        let b = o.bookings
        let title = (o.title ?? "").trimmingCharacters(in: .whitespaces)
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .top, spacing: 8) {
                Text(verbatim: title.isEmpty ? L("cns_untitled") : (o.title ?? "")).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                    .frame(maxWidth: .infinity, alignment: .leading)
                SvcPill(text: serviceStatusLabel(status), tone: tone)
            }
            Text(verbatim: lengths.trimmingCharacters(in: .whitespaces).isEmpty ? L("cns_no_lengths") : lengths)
                .font(.dorr(12.5, .bold)).foregroundStyle(wa.red).lineSpacing(3)
            if (b?.pending ?? 0) + (b?.upcoming ?? 0) + (b?.completed ?? 0) > 0 {
                Text(L("cns_offer_counts", b?.pending ?? 0, b?.upcoming ?? 0, b?.completed ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            if let reason = o.statusReason, !reason.trimmingCharacters(in: .whitespaces).isEmpty, status == "rejected" || status == "suspended" {
                Notice(icon: "info.circle.fill", text: reason, tone: serviceTone(status == "rejected" ? "rejected" : "suspended", night: wa.night))
            }
            if status == "pending_review" {
                Text(L("cns_offer_waiting")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            }
            HStack(spacing: 8) {
                if o.can?.edit ?? true { OfferAction(icon: "pencil", text: L("cns_edit"), enabled: !busy, action: onEdit) }
                if o.can?.submit ?? false { OfferAction(icon: "paperplane.fill", text: L("cns_send_review"), enabled: !busy, strong: true) { onAct("submit") }.layoutPriority(1) }
                if o.can?.pause ?? false { OfferAction(icon: "pause.fill", text: L("cns_pause"), enabled: !busy) { onAct("pause") } }
                if o.can?.resume ?? false { OfferAction(icon: "play.fill", text: L("cns_resume"), enabled: !busy, strong: true) { onAct("resume") } }
                if o.can?.delete ?? true {
                    Button(action: onDelete) {
                        Image(systemName: "trash.fill").font(.system(size: 17)).foregroundStyle(serviceTone("rejected", night: wa.night).ink)
                            .frame(width: 42, height: 42)
                            .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    .disabled(busy)
                    .accessibilityLabel(L("common_delete"))
                }
            }
        }
        .padding(14)
        .frame(maxWidth: 520, alignment: .leading)
        .settingsSurface(20)
        .frame(maxWidth: .infinity)
    }
}

private struct OfferAction: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String
    let enabled: Bool
    var strong = false
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 13, weight: .semibold))
                Text(verbatim: text).font(.dorr(12.5, .heavy)).lineLimit(1)
            }
            .foregroundStyle(strong ? Color.white : wa.red)
            .padding(.horizontal, 8)
            .frame(maxWidth: .infinity, minHeight: 42)
            .background(strong ? wa.red : wa.red.opacity(0.10), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

/// One booking in a list: the day on a coloured tile, the time, the client and the topic, what I get and the state.
private struct BookingCard: View {
    @Environment(\.wa) private var wa
    let c: ConsultationDto
    let onClick: () -> Void

    var body: some View {
        let phase = c.phase ?? ""
        let tone = phaseTone(phase, wa)
        let day = parseInstant(c.startsAt).map { "\(Calendar.current.component(.day, from: $0))" } ?? ""
        Button(action: onClick) {
            HStack(alignment: .top, spacing: 12) {
                VStack(spacing: 0) {
                    Text(verbatim: consFormat(c.startsAt, "EEE")).font(.dorr(11, .bold)).foregroundStyle(tone.ink).lineLimit(1)
                    Text(verbatim: day).font(.dorr(22, .heavy)).foregroundStyle(tone.ink)
                    Text(verbatim: consFormat(c.startsAt, "MMM")).font(.dorr(11, .bold)).foregroundStyle(tone.ink).lineLimit(1)
                }
                .padding(.vertical, 10)
                .frame(width: 58)
                .background(tone.fill, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                VStack(alignment: .leading, spacing: 5) {
                    HStack(spacing: 0) {
                        Text(verbatim: "\u{200E}\(hm(c.startsAt)) – \(hm(c.endsAt))").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        SvcPill(text: phaseLabel(phase), tone: tone)
                    }
                    Text(L("cns_with", c.client?.name ?? "", c.minutes ?? 30)).font(.dorr(12, .bold)).foregroundStyle(wa.red).lineLimit(1)
                    if let topic = c.topic {
                        Text(verbatim: topic).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2).lineLimit(2)
                    }
                    HStack(spacing: 6) {
                        Text(L("svo_you_get", svcMoney(c.money?.netMinor ?? 0, c.decimals ?? 2, c.currency))).font(.dorr(12, .heavy)).foregroundStyle(wa.settingsInk)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        if phase == "upcoming" || phase == "pending", let until = untilText(c.startsAt) {
                            Image(systemName: "timer").font(.system(size: 12)).foregroundStyle(wa.settingsMut)
                            Text(verbatim: until).font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut)
                        }
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(12)
            .frame(maxWidth: 520, alignment: .leading)
            .settingsSurface(20)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity)
    }
}

private struct Notice: View {
    let icon: String
    let text: String
    let tone: SvcTone

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: icon).font(.system(size: 16)).foregroundStyle(tone.ink).frame(width: 18, height: 18)
            Text(verbatim: text).font(.dorr(13, .semibold)).foregroundStyle(tone.ink).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(12)
        .frame(maxWidth: 520, alignment: .leading)
        .background(tone.fill, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .frame(maxWidth: .infinity)
    }
}

// MARK: - the editor

/// One range of a day in the editor.
private struct HourRange: Identifiable {
    let id = UUID()
    var from: String
    var to: String
}

/// The clock being picked: which range, its start or its end.
private struct Picking {
    let day: Int
    let id: UUID
    let isFrom: Bool
    let current: String
}

struct ConsultationEditorScreen: View {
    let uuid: String?
    let onBack: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    // The times are told in this phone's zone and kept with the listing; each client sees them in their own.
    private let zone = TimeZone.current.identifier

    @State private var info: ConsultationInfoDto?
    @State private var offer: ConsultationOfferDto?
    @State private var ready = false
    @State private var title = ""
    @State private var about = ""
    /// minutes → the typed price ("" = not sold)
    @State private var prices: [Int: String] = [:]
    @State private var chosen: [Int] = []
    @State private var week: [[HourRange]] = Array(repeating: [], count: 7)
    @State private var busy = false
    @State private var error: String?
    @State private var picking: Picking?
    @State private var pickedTime = Date()

    private var offline: String { L("login_error_offline") }
    private var generic: String { L("login_error_generic") }

    private func fill(_ o: ConsultationOfferDto, _ decimals: Int) {
        offer = o
        title = o.title ?? ""
        about = o.about ?? ""
        chosen = []
        prices = [:]
        for x in o.options ?? [] {
            guard let m = x.minutes else { continue }
            chosen.append(m)
            prices[m] = minorToInput(x.priceMinor ?? 0, decimals)
        }
        var w: [[HourRange]] = Array(repeating: [], count: 7)
        for h in o.weekly ?? [] { if let d = h.day, (0...6).contains(d) { w[d].append(HourRange(from: h.from ?? "", to: h.to ?? "")) } }
        week = w
    }

    private func start() async {
        if ready { return }
        let i = await ConsultationsApi.info()
        guard let data = i.value else { error = i.errorText(offline) ?? generic; return }
        info = data
        if let uuid {
            let o = await ConsultationsApi.offer(uuid)
            guard let offerData = o.value else { error = o.errorText(offline) ?? generic; return }
            fill(offerData, data.decimals ?? 2)
        } else if week.allSatisfy({ $0.isEmpty }), let suggested = data.suggestedWeekly, !suggested.isEmpty {
            for h in suggested { if let d = h.day, (0...6).contains(d) { week[d].append(HourRange(from: h.from ?? "", to: h.to ?? "")) } }
        }
        ready = true
    }

    /// Saves it; with `send` the listing also goes to the staff (or live where they don't look first).
    private func save(_ send: Bool) {
        guard let s = info else { return }
        let decimals = s.decimals ?? 2
        error = nil
        let options = chosen.sorted().map { m in (m, inputToMinor(prices[m] ?? "", decimals)) }
        if title.trimmingCharacters(in: .whitespacesAndNewlines).count < 3 { error = L("cns_err_no_title"); return }
        if options.isEmpty { error = L("cns_err_no_length"); return }
        if options.contains(where: { $0.1 == nil || $0.1! < 100 }) { error = L("cns_err_price"); return }
        let hours = week.enumerated().flatMap { day, list in list.map { ConsultationHoursDto(day: day, from: $0.from, to: $0.to) } }
        if hours.isEmpty { error = L("cns_err_no_hours"); return }
        let bad = week.contains { list in
            let sorted = list.sorted { $0.from < $1.from }
            return sorted.contains { !isHHMM($0.from) || !isHHMM($0.to) || $0.to <= $0.from }
                || zip(sorted, sorted.dropFirst()).contains { a, b in b.from < a.to }
        }
        if bad { error = L("cns_err_hours"); return }
        busy = true
        let aboutText = about.trimmingCharacters(in: .whitespacesAndNewlines)
        let body = ConsultationOfferRequest(
            title: title.trimmingCharacters(in: .whitespacesAndNewlines), about: aboutText.isEmpty ? nil : aboutText,
            options: options.map { ConsultationOptionRequest(minutes: $0.0, priceMinor: $0.1!) }, weekly: hours, timezone: zone
        )
        Task {
            let current = offer
            let first: ApiResult<ConsultationOfferDto>
            if let id = current?.uuid { first = await ConsultationsApi.update(id, body) } else { first = await ConsultationsApi.create(body) }
            var r = first
            if send, let saved = first.value, saved.can?.submit == true, let id = saved.uuid {
                r = await ConsultationsApi.submit(id)
            }
            busy = false
            if let o = r.value {
                fill(o, decimals)
                await AppToast.show(send ? L("cns_offer_sent") : L("cns_saved"))
                if send { onBack() }
            } else {
                error = r.errorText(offline) ?? generic
            }
        }
    }

    var body: some View {
        Page(title: uuid == nil ? L("cns_new_title") : L("cns_edit_title"), onBack: onBack) {
            if let s = info, ready {
                editor(s)
            } else if error == nil {
                SkeletonDetail()
            }
        }
        .messageToast(error)
        .task(id: network.reconnectTick) { await start() }
        .dorrDialog(isPresented: Binding(get: { picking != nil }, set: { if !$0 { picking = nil } })) {
            if let p = picking {
                DialogCard {
                    VStack(spacing: 12) {
                        Text(L(p.isFrom ? "cns_pick_from" : "cns_pick_to")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        DatePicker("", selection: $pickedTime, displayedComponents: .hourAndMinute)
                            .datePickerStyle(.wheel)
                            .labelsHidden()
                            .environment(\.locale, Locale(identifier: "en_GB"))
                            .tint(wa.red)
                        HStack {
                            Spacer()
                            Button(L("common_cancel")) { picking = nil }.foregroundStyle(wa.settingsMut)
                            Button(L("common_ok")) {
                                let c = Calendar.current.dateComponents([.hour, .minute], from: pickedTime)
                                let v = String(format: "%02d:%02d", c.hour ?? 0, c.minute ?? 0)
                                if let i = week[p.day].firstIndex(where: { $0.id == p.id }) {
                                    if p.isFrom { week[p.day][i].from = v } else { week[p.day][i].to = v }
                                }
                                picking = nil
                            }
                            .foregroundStyle(wa.red)
                            .padding(.leading, 12)
                        }
                        .font(.dorr(15, .bold))
                    }
                    .padding(16)
                }
            }
        }
    }

    private func pick(_ day: Int, _ r: HourRange, _ isFrom: Bool) {
        let current = isFrom ? r.from : r.to
        let parts = current.split(separator: ":")
        let hour = parts.first.flatMap { Int($0) } ?? 9
        let minute = parts.count > 1 ? Int(parts[1]) ?? 0 : 0
        pickedTime = Calendar.current.date(bySettingHour: hour, minute: minute, second: 0, of: Date()) ?? Date()
        picking = Picking(day: day, id: r.id, isFrom: isFrom, current: current)
    }

    @ViewBuilder
    private func editor(_ s: ConsultationInfoDto) -> some View {
        let decimals = s.decimals ?? 2
        let maxOptions = s.maxOptions ?? 4
        let review = s.review ?? true
        if let o = offer {
            let status = o.status ?? "draft"
            HStack(spacing: 8) { SvcPill(text: serviceStatusLabel(status), tone: serviceTone(status, night: wa.night)); Spacer(minLength: 0) }
            if status == "rejected" || status == "suspended", let reason = o.statusReason, !reason.trimmingCharacters(in: .whitespaces).isEmpty {
                Notice(icon: "info.circle.fill", text: reason, tone: serviceTone(status, night: wa.night))
            }
            if review && (status == "published" || status == "paused") {
                Notice(icon: "info.circle.fill", text: L("cns_edit_review_note"), tone: serviceTone("pending_review", night: wa.night))
            }
        }

        StepCard(number: 1, title: L("cns_step_about"), subtitle: L("cns_step_about_sub")) {
            DorrTextField(value: Binding(get: { title }, set: { title = String($0.prefix(120)) }), label: L("cns_field_title"), placeholder: L("cns_field_title_hint"))
            DorrTextField(value: Binding(get: { about }, set: { about = String($0.prefix(1000)) }), label: L("cns_field_about"), placeholder: L("cns_field_about_hint"),
                          singleLine: false, minLines: 3)
        }

        StepCard(number: 2, title: L("cns_step_lengths"), subtitle: L("cns_step_lengths_sub", maxOptions)) {
            ForEach(s.lengths ?? [15, 30, 45, 60, 90], id: \.self) { m in
                let on = chosen.contains(m)
                let saved = offer?.options?.first { $0.minutes == m }
                let net = saved.flatMap { o in minorToInput(o.priceMinor ?? 0, decimals) == (prices[m] ?? "") ? svcMoney(o.netMinor ?? 0, decimals, s.currency) : nil }
                LengthRow(minutes: m, on: on, price: Binding(get: { prices[m] ?? "" }, set: { prices[m] = String($0.prefix(10)) }),
                          currency: s.currency, net: net, canAdd: chosen.count < maxOptions,
                          onToggle: {
                              if on { chosen.removeAll { $0 == m } } else if chosen.count < maxOptions { chosen.append(m) }
                          })
            }
        }

        StepCard(number: 3, title: L("cns_step_hours"), subtitle: L("cns_step_hours_sub")) {
            ForEach(0..<7, id: \.self) { day in
                DayHours(day: day, ranges: $week[day], onPick: { r, isFrom in pick(day, r, isFrom) })
            }
            HStack(spacing: 6) {
                Image(systemName: "globe").font(.system(size: 12)).foregroundStyle(wa.settingsMut)
                Text(L("cns_zone", zone)).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
        }

        InfoCard(title: L("cns_rules_title"), icon: "hammer.fill") {
            let r = s.rules
            let lines = [
                L("cns_rule_notice", r?.minNoticeHours ?? 2, r?.maxDaysAhead ?? 30),
                L("cns_rule_paid_first", r?.responseHours ?? 24),
                L("cns_rule_cancel", r?.freeCancelHours ?? 24),
                L("cns_rule_after", r?.complaintHours ?? 24),
            ]
            ForEach(Array(lines.enumerated()), id: \.offset) { _, line in
                HStack(alignment: .top, spacing: 8) {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 14)).foregroundStyle(Color(hex: 0x16A34A)).padding(.top, 1)
                    Text(verbatim: line).font(.dorr(12.5)).foregroundStyle(wa.settingsInk).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
                }
            }
        }

        let sendable = offer == nil || offer?.can?.submit == true
        VStack(spacing: 14) {
            if sendable {
                SettingsPrimaryButton(text: review ? L("cns_save_send") : L("cns_save_publish"), loading: busy) { save(true) }
                Button { save(false) } label: {
                    Text(L("cns_save_draft")).font(.dorr(15, .heavy)).foregroundStyle(wa.red)
                        .frame(maxWidth: .infinity, minHeight: 50)
                        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(wa.night ? wa.line : wa.red.opacity(0.3), lineWidth: 1.5))
                        .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .disabled(busy)
            } else {
                SettingsPrimaryButton(text: L("cns_save"), loading: busy) { save(false) }
            }
        }
        .frame(maxWidth: 520)
        .frame(maxWidth: .infinity)
    }
}

/// A length sold or not, its price, and what I keep of it (once saved).
private struct LengthRow: View {
    @Environment(\.wa) private var wa
    let minutes: Int
    let on: Bool
    @Binding var price: String
    let currency: String?
    let net: String?
    let canAdd: Bool
    let onToggle: () -> Void

    var body: some View {
        let line = on ? wa.red : (wa.night ? wa.line : Color(hex: 0xE5E7EB))
        VStack(alignment: .leading, spacing: 10) {
            Button(action: onToggle) {
                HStack(spacing: 10) {
                    ZStack {
                        RoundedRectangle(cornerRadius: 7, style: .continuous).fill(on ? wa.red : .clear)
                        RoundedRectangle(cornerRadius: 7, style: .continuous).stroke(on ? wa.red : wa.settingsMut.opacity(0.5), lineWidth: 1.5)
                        if on { Image(systemName: "checkmark").font(.system(size: 11, weight: .bold)).foregroundStyle(.white) }
                    }
                    .frame(width: 22, height: 22)
                    Image(systemName: "timer").font(.system(size: 15)).foregroundStyle(on ? wa.red : wa.settingsMut)
                    Text(L("cns_minutes", minutes)).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                    if !on && !canAdd { Text(L("cns_max_reached")).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .disabled(!(on || canAdd))
            if on {
                DorrTextField(value: $price, label: L("cns_price", currency ?? ""), icon: "banknote", keyboard: .decimalPad)
                if let net { Text(L("cns_you_keep", net)).font(.dorr(12, .bold)).foregroundStyle(Color(hex: 0x16A34A)) }
            }
        }
        .padding(12)
        .background(on ? wa.red.opacity(0.05) : .clear, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(line, lineWidth: on ? 1.5 : 1))
    }
}

/// A weekday: on with one or more ranges (each opens a clock), or off.
private struct DayHours: View {
    @Environment(\.wa) private var wa
    let day: Int
    @Binding var ranges: [HourRange]
    let onPick: (HourRange, Bool) -> Void

    var body: some View {
        let on = !ranges.isEmpty
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 10) {
                Text(L(DAYS[day])).font(.dorr(14, .bold)).foregroundStyle(on ? wa.settingsInk : wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                if !on { Text(L("cns_day_off")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                RedToggle(on: on) { if on { ranges.removeAll() } else { ranges.append(HourRange(from: "09:00", to: "12:00")) } }
            }
            ForEach(ranges) { r in
                HStack(spacing: 8) {
                    TimeChip(value: r.from) { onPick(r, true) }
                    Text(verbatim: "—").font(.dorr(14)).foregroundStyle(wa.settingsMut)
                    TimeChip(value: r.to, bad: r.to <= r.from) { onPick(r, false) }
                    Button { ranges.removeAll { $0.id == r.id } } label: {
                        Image(systemName: "xmark").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.settingsMut)
                            .frame(width: 36, height: 36)
                            .contentShape(Circle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("common_delete"))
                }
            }
            if on {
                Button {
                    let start = ranges.max { $0.to < $1.to }?.to ?? "09:00"
                    let parts = start.split(separator: ":", maxSplits: 1).map(String.init)
                    let h = (Int(parts.first ?? "") ?? 9) + 1
                    let minute = parts.count > 1 ? parts[1] : start
                    ranges.append(HourRange(from: start, to: String(format: "%02d:%@", min(h, 23), minute)))
                } label: {
                    HStack(spacing: 4) {
                        Image(systemName: "plus").font(.system(size: 13, weight: .bold))
                        Text(L("cns_add_range")).font(.dorr(12, .bold))
                    }
                    .foregroundStyle(wa.red)
                    .padding(.vertical, 4)
                    .padding(.horizontal, 2)
                }
                .buttonStyle(.plain)
            }
            Rectangle().fill(wa.night ? wa.line : Color(hex: 0xF1F2F4)).frame(height: 1)
        }
    }
}

private struct TimeChip: View {
    @Environment(\.wa) private var wa
    let value: String
    var bad = false
    let action: () -> Void

    var body: some View {
        let line = bad ? Color(hex: 0xDC2626) : (wa.night ? wa.line : Color(hex: 0xE5E7EB))
        Button(action: action) {
            HStack(spacing: 8) {
                Image(systemName: "clock").font(.system(size: 14)).foregroundStyle(wa.red)
                Text(verbatim: "\u{200E}\(value)").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity)
            }
            .padding(.horizontal, 12)
            .frame(maxWidth: .infinity, minHeight: 42)
            .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(line, lineWidth: 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - one booking

struct ConsultationScreen: View {
    let uuid: String
    let onBack: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var c: ConsultationDto?
    @State private var error: String?
    @State private var cancelling = false
    @State private var declining = false
    @State private var busy = false
    @State private var reason = ""

    private var offline: String { L("login_error_offline") }
    private var generic: String { L("login_error_generic") }

    private func load() async {
        let r = await ConsultationsApi.show(uuid)
        if let x = r.value {
            c = x
            error = nil
        } else {
            error = r.errorText(offline) ?? generic
        }
    }

    private func accept() {
        busy = true
        Task {
            let r = await ConsultationsApi.accept(uuid)
            busy = false
            if let x = r.value {
                c = x
                await AppToast.show(L("cns_accept_done"))
            } else {
                error = r.errorText(offline) ?? generic
            }
        }
    }

    private func confirmAnswer() {
        busy = true
        Task {
            let trimmed = reason.trimmingCharacters(in: .whitespacesAndNewlines)
            let body = ConsultationCancelRequest(reason: trimmed.isEmpty ? nil : trimmed)
            let wasDeclining = declining
            let r = wasDeclining ? await ConsultationsApi.decline(uuid, body) : await ConsultationsApi.cancel(uuid, body)
            busy = false
            cancelling = false
            declining = false
            if let x = r.value {
                c = x
                await AppToast.show(wasDeclining ? L("cns_declined_done") : L("cns_cancelled_done"))
            } else {
                error = r.errorText(offline) ?? generic
            }
        }
    }

    var body: some View {
        Page(title: L("cns_detail_title"), onBack: onBack) {
            if let x = c {
                content(x)
            } else if error == nil {
                SkeletonDetail()
            }
        }
        .messageToast(error)
        .task(id: network.reconnectTick) { await load() }
        .onReceive(Realtime.shared.changed) { Task { await load() } }
        .dorrDialog(isPresented: Binding(get: { cancelling || declining }, set: { if !$0 && !busy { cancelling = false; declining = false } })) {
            DialogCard {
                VStack(alignment: .leading, spacing: 10) {
                    Text(L(declining ? "cns_decline_title" : "cns_cancel_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(L(declining ? "cns_decline_body" : "cns_cancel_body")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                    DorrTextField(value: Binding(get: { reason }, set: { reason = String($0.prefix(500)) }), label: L("cns_cancel_reason"),
                                  singleLine: false, minLines: 2)
                    HStack {
                        Spacer()
                        Button(L("common_cancel")) { cancelling = false; declining = false }.foregroundStyle(wa.settingsMut).disabled(busy)
                        Button(action: confirmAnswer) {
                            Text(L(declining ? "cns_decline_confirm" : "cns_cancel_confirm")).foregroundStyle(serviceTone("rejected", night: wa.night).ink)
                        }
                        .disabled(busy)
                        .padding(.leading, 12)
                    }
                    .font(.dorr(15, .bold))
                    .padding(.top, 4)
                }
                .padding(18)
            }
        }
    }

    @ViewBuilder
    private func content(_ x: ConsultationDto) -> some View {
        let decimals = x.decimals ?? 2
        DetailHead(c: x)
        if let topic = x.topic, !topic.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            InfoCard(title: L("cns_topic"), icon: "quote.opening") {
                Text(verbatim: topic).font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(wa.red.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
        }
        InfoCard(title: L("svo_money"), icon: "banknote.fill") {
            MoneyLine(label: L("svo_price"), amount: svcMoney(x.money?.priceMinor ?? 0, decimals, x.currency))
            MoneyLine(label: L("svo_commission"), amount: "− " + svcMoney(x.money?.commissionMinor ?? 0, decimals, x.currency), muted: true)
            Rectangle().fill(wa.night ? wa.line : Color(hex: 0xEEF0F3)).frame(height: 1)
            MoneyLine(label: L("svo_net"), amount: svcMoney(x.money?.netMinor ?? 0, decimals, x.currency), strong: true)
        }
        StateNotes(c: x)
        ClientRating(c: x)
        if x.can?.accept ?? false {
            SettingsPrimaryButton(text: L("cns_accept"), enabled: !busy, action: accept).frame(maxWidth: 520).frame(maxWidth: .infinity)
        }
        let canDecline = x.can?.decline ?? false
        if (x.can?.cancel ?? false) || canDecline {
            Button { if canDecline { declining = true } else { cancelling = true } } label: {
                Text(L(canDecline ? "cns_decline" : "cns_cancel")).font(.dorr(15, .heavy)).foregroundStyle(wa.red)
                    .frame(maxWidth: .infinity, minHeight: 50)
                    .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(wa.night ? wa.line : wa.red.opacity(0.3), lineWidth: 1.5))
                    .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .disabled(busy)
            .frame(maxWidth: 520)
            .frame(maxWidth: .infinity)
        }
    }
}

private struct DetailHead: View {
    @Environment(\.wa) private var wa
    let c: ConsultationDto

    var body: some View {
        let phase = c.phase ?? ""
        let tone = phaseTone(phase, wa)
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                SvcPill(text: phaseLabel(phase), tone: tone, solid: true)
                Spacer()
                Text(L("cns_minutes", c.minutes ?? 30)).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
            }
            Text(verbatim: c.title ?? "").font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(4)
            HStack(spacing: 12) {
                Image(systemName: "calendar").font(.system(size: 22)).foregroundStyle(tone.ink).frame(width: 26, height: 26)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: dayLine(c.startsAt)).font(.dorr(14, .heavy)).foregroundStyle(tone.ink)
                    Text(verbatim: "\u{200E}\(hm(c.startsAt)) – \(hm(c.endsAt))").font(.dorr(13, .bold)).foregroundStyle(tone.ink.opacity(0.9))
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if phase == "upcoming" || phase == "pending", let until = untilText(c.startsAt) {
                    VStack(spacing: 0) {
                        Image(systemName: "hourglass").font(.system(size: 14)).foregroundStyle(tone.ink)
                        Text(verbatim: until).font(.dorr(11, .heavy)).foregroundStyle(tone.ink)
                    }
                }
            }
            .padding(14)
            .background(tone.fill, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            HStack(spacing: 10) {
                SvcInitial(name: c.client?.name, size: 34, fontSize: 14)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("svo_client")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: c.client?.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
        .padding(16)
        .frame(maxWidth: 520, alignment: .leading)
        .settingsSurface(22)
        .frame(maxWidth: .infinity)
    }
}

/// Once it is over: the client's stars and words, or until when they may still rate it.
private struct ClientRating: View {
    @Environment(\.wa) private var wa
    let c: ConsultationDto

    var body: some View {
        let given = c.review?.rating
        if given != nil || c.review?.rateUntil != nil {
            InfoCard(title: L("cns_rating_title"), icon: "star.fill") {
                if let given {
                    let stars = given.stars ?? 0
                    HStack(spacing: 8) {
                        Stars(value: Int(stars.rounded()), size: 22)
                        Text(verbatim: "\(stars)").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                    }
                    if let comment = given.comment, !comment.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(verbatim: "“\(comment)”").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                    }
                    Text(L("cns_rating_public")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                } else {
                    let until = c.review?.rateUntil
                    Text(L("cns_rating_none", "\(dayLine(until)) \(hm(until))")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                }
            }
        }
    }
}

/// What happens next, or what happened, in one or two plain lines.
private struct StateNotes: View {
    @Environment(\.wa) private var wa
    let c: ConsultationDto

    var body: some View {
        let warn = serviceTone("pending_review", night: wa.night)
        let ok = serviceTone("published", night: wa.night)
        let info = SvcTone(ink: wa.red, fill: wa.red.opacity(0.08))
        let cancel = c.cancel
        switch c.phase ?? "" {
        case "pending":
            Notice(icon: "hourglass", text: L("cns_note_pending", "\(dayLine(c.respondBy)) \(hm(c.respondBy))"), tone: warn)
        case "declined":
            let text = cancel?.by == "freelancer" ? L("cns_note_declined_me")
                : cancel?.reason == "taken" ? L("cns_note_declined_taken") : L("cns_note_declined_expired")
            let quote = cancel?.by == "freelancer" ? cancel?.reason.map { "\n“\($0)”" } ?? "" : ""
            Notice(icon: "calendar.badge.minus", text: text + quote, tone: serviceTone("paused", night: wa.night))
        case "upcoming":
            Notice(icon: "info.circle.fill", text: L("cns_note_upcoming"), tone: info)
        case "live":
            Notice(icon: "video.fill", text: L("cns_note_live"), tone: ok)
        case "ended":
            Notice(icon: "hourglass", text: L("cns_note_ended", "\(dayLine(c.rules?.releaseAt)) \(hm(c.rules?.releaseAt))"), tone: warn)
        case "completed":
            Notice(icon: "checkmark.circle.fill", text: L("cns_note_completed"), tone: ok)
        case "disputed":
            Notice(icon: "hammer.fill", text: L("cns_note_disputed"), tone: warn)
            if let complaint = c.complaint?.text {
                InfoCard(title: L("cns_complaint"), icon: "quote.opening") {
                    Text(verbatim: complaint).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                }
            }
        case "refunded":
            Notice(icon: "info.circle.fill", text: L("cns_note_refunded") + (c.resolution?.note.map { "\n\($0)" } ?? ""), tone: serviceTone("rejected", night: wa.night))
        case "cancelled":
            let text = cancel?.by == "freelancer" ? L("cns_note_cancelled_me")
                : cancel?.late == true ? L("cns_note_cancelled_late") : L("cns_note_cancelled_client")
            Notice(icon: "calendar.badge.minus", text: text + (cancel?.reason.map { "\n“\($0)”" } ?? ""), tone: serviceTone("paused", night: wa.night))
        default:
            EmptyView()
        }
    }
}
