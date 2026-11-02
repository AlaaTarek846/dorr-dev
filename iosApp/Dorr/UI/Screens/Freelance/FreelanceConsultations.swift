import SwiftUI

// androidApp `freelance/FreelanceConsultations.kt` — FL-L07, paid consultations on the client's side, listed like the ready-made
// services: the list of consultations on offer, booking one (a length, a day, a time, what it is about — then a last look at the money
// before it is held), my consultations in three lists, and one consultation (when, cancelling with the refund said plainly, saying it
// went well, or reporting a problem). The session itself is a video call (FL-L16), so there is no room. Times come from the server as
// UTC instants and are shown on the phone's own clock. Each page reads once when it opens and when the connection returns; countdowns
// read the phone's clock.

private let csGreen = Color(hex: 0x16A34A)
private let csAmber = Color(hex: 0xD97706)
private let csRed = Color(hex: 0xDC2626)
private let csSlate = Color(hex: 0x64748B)

private func csFormat(_ iso: String?, _ pattern: String) -> String {
    guard let d = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = pattern == "HH:mm" ? Locale(identifier: "en_US_POSIX") : AppLocale.locale
    f.dateFormat = pattern
    return f.string(from: d)
}

private func hm(_ iso: String?) -> String { csFormat(iso, "HH:mm") }

private func longDay(_ iso: String?) -> String {
    guard parseInstant(iso) != nil else { return "" }
    return "\(csFormat(iso, "EEEE")) \(csFormat(iso, "d")) \(csFormat(iso, "MMMM"))"
}

private func until(_ iso: String?) -> String? {
    guard let at = parseInstant(iso) else { return nil }
    let left = at.timeIntervalSinceNow
    if left < 0 { return nil }
    if left >= 86_400 { return L("cs_in_days", Int(left / 86_400)) }
    if left >= 3600 { return L("cs_in_hours", Int(left / 3600)) }
    return L("cs_in_minutes", max(Int(left / 60), 1))
}

@MainActor private func csPhase(_ p: String?) -> (String, Color) {
    switch p {
    case "pending": return (L("cs_phase_pending"), csAmber)
    case "declined": return (L("cs_phase_declined"), csSlate)
    case "upcoming": return (L("cs_phase_upcoming"), Ch.shared.red)
    case "live": return (L("cs_phase_live"), csGreen)
    case "ended": return (L("cs_phase_ended"), csAmber)
    case "completed": return (L("cs_phase_completed"), csGreen)
    case "disputed": return (L("cs_phase_disputed"), csAmber)
    case "refunded": return (L("cs_phase_refunded"), csRed)
    default: return (L("cs_phase_cancelled"), csSlate)
    }
}

private func starWord(_ n: Int) -> String {
    switch n {
    case 1: return L("cs_star_1")
    case 2: return L("cs_star_2")
    case 3: return L("cs_star_3")
    case 4: return L("cs_star_4")
    default: return L("cs_star_5")
    }
}

private struct CsPill: View {
    @Environment(\.wa) private var wa
    let text: String
    let color: Color
    var solid = false

    var body: some View {
        HStack(spacing: 6) {
            Circle().fill(solid ? Color.white : color).frame(width: 6, height: 6)
            Text(verbatim: text).font(.dorr(11.5, .bold)).foregroundStyle(solid ? .white : color).lineLimit(1)
        }
        .padding(.horizontal, 10).padding(.vertical, 4)
        .background(solid ? color : color.opacity(wa.night ? 0.22 : 0.12), in: Capsule())
    }
}

private struct CsCard<Content: View>: View {
    @Environment(\.wa) private var wa
    var title: String?
    var icon: String?
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            if let title {
                HStack(spacing: 8) {
                    if let icon { Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.accent) }
                    Text(verbatim: title).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                }
            }
            content()
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(16)
        .settingsSurface(20)
    }
}

private struct CsLine: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String
    var strong = false

    var body: some View {
        HStack {
            Text(verbatim: label).font(.dorr(strong ? 15 : 13, strong ? .heavy : .medium)).foregroundStyle(strong ? wa.settingsInk : wa.settingsMut)
            Spacer(minLength: 8)
            Text(verbatim: value).font(.dorr(strong ? 17 : 13, strong ? .heavy : .semibold)).foregroundStyle(strong ? wa.accent : wa.settingsInk)
        }
    }
}

/// A numbered step heading on the booking page.
private struct StepHead: View {
    @Environment(\.wa) private var wa
    let n: Int
    let title: String
    let done: Bool

    var body: some View {
        HStack(spacing: 10) {
            ZStack {
                Circle().fill(done ? csGreen : wa.accent)
                if done {
                    Image(systemName: "checkmark").font(.system(size: 11, weight: .bold)).foregroundStyle(.white)
                } else {
                    Text(verbatim: "\(n)").font(.dorr(12, .heavy)).foregroundStyle(.white)
                }
            }
            .frame(width: 26, height: 26)
            Text(verbatim: title).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
        }
    }
}

// MARK: - the door on a freelancer's card

/// The consultations a freelancer lists now, one row each («Brand advice · from 210 SAR»); nothing when they list none.
struct ConsultTeaser: View {
    @Environment(\.wa) private var wa
    let items: [ConsultTeaserDto]
    let onBook: (String) -> Void

    var body: some View {
        VStack(spacing: 8) {
            ForEach(items, id: \.uuid) { t in
                Button { onBook(t.uuid) } label: {
                    HStack(spacing: 12) {
                        Image(systemName: "video.bubble.left.fill").font(.system(size: 18)).foregroundStyle(.white)
                            .frame(width: 42, height: 42).background(wa.accent, in: RoundedRectangle(cornerRadius: 13, style: .continuous))
                        VStack(alignment: .leading, spacing: 0) {
                            Text(verbatim: (t.title?.isEmpty == false ? t.title : nil) ?? L("cs_teaser_title")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                            Text(verbatim: L("cs_teaser_line", mkMoney(t.fromMinor, 2, t.currency), (t.minutes ?? []).map(String.init).joined(separator: " · ")))
                                .font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        Image(systemName: "chevron.forward").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.accent)
                    }
                    .padding(14)
                    .background(LinearGradient(colors: [wa.accent.opacity(0.12), wa.accent.opacity(0.04)], startPoint: .topLeading, endPoint: .bottomTrailing),
                                in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(wa.accent.opacity(0.25), lineWidth: 1))
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
        }
    }
}

// MARK: - the list of consultations on offer

/// The consultations clients can book, a list of their own next to the ready-made services: a search and one card each.
@MainActor
struct ConsultationsMarketPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpen: (String) -> Void
    let onMine: () -> Void
    @State private var typed = ""
    @State private var search = ""
    @State private var closed = false
    @StateObject private var paged = PagedRows<ConsultCardDto>(key: { $0.uuid })

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: L("cs_market_title"), onBack: onBack)
            ScrollView {
                LazyVStack(spacing: 12) {
                    HStack(spacing: 6) {
                        DorrTextArea(value: Binding(get: { typed }, set: { typed = String($0.prefix(80)) }), placeholder: L("cs_market_search"), icon: "magnifyingglass", singleLine: true)
                        if !typed.isEmpty { RemoveButton { typed = "" } }
                    }
                    Button(action: onMine) {
                        HStack(spacing: 10) {
                            Image(systemName: "calendar.badge.checkmark").font(.system(size: 15)).foregroundStyle(wa.accent)
                            Text(L("cs_mine_title")).font(.dorr(13, .heavy)).foregroundStyle(wa.accent).frame(maxWidth: .infinity, alignment: .leading)
                            Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.accent)
                        }
                        .padding(12)
                        .background(wa.accent.opacity(0.08), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    if closed {
                        WaEmpty(icon: "video.bubble.left.fill", tone: .blue, title: L("cs_market_title"), text: L("cs_market_closed"))
                    } else if !paged.loaded {
                        ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 150) }
                    } else if paged.rows.isEmpty && paged.error == nil {
                        WaEmpty(icon: "video.bubble.left.fill", tone: .blue, title: L("cs_market_empty_title"), text: L("cs_market_empty_body"))
                    } else {
                        ForEach(Array(paged.rows.enumerated()), id: \.element.uuid) { i, c in
                            ConsultCard(c: c) { onOpen(c.uuid) }.onAppear { paged.appeared(i) }
                        }
                    }
                    PagedSpinner(paged: paged)
                    if !closed { PagedError(paged: paged) }
                }
                .padding(.horizontal, 16).padding(.vertical, 6)
            }
            .scrollDismissesKeyboard(.interactively)
        }
        // The search goes out once typing pauses.
        .task(id: typed) {
            try? await Task.sleep(nanoseconds: 400_000_000)
            guard !Task.isCancelled else { return }
            search = typed.trimmingCharacters(in: .whitespaces)
        }
        .task(id: search) {
            let q = search
            paged.reset { page in
                do {
                    return try await FreelanceConsultationsApi.market(search: q.isEmpty ? nil : q, page: page)
                } catch {
                    if error.apiFailure.httpStatus == 403 { await MainActor.run { closed = true } }
                    throw error
                }
            }
        }
        .task(id: "\(search)|\(network.reconnectTick)") { await paged.reload() }
    }
}

private struct ConsultCard: View {
    @Environment(\.wa) private var wa
    let c: ConsultCardDto
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(spacing: 12) {
                    MkAvatar(url: c.freelancer?.avatarUrl, name: c.freelancer?.name, size: 44)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: c.freelancer?.name ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        if let h = c.freelancer?.headline, !h.isEmpty { Text(verbatim: h).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    if let r = c.freelancer?.rating {
                        Image(systemName: "star.fill").font(.system(size: 12)).foregroundStyle(Color(hex: 0xF59E0B))
                        Text(verbatim: String(format: "%.1f", r)).font(.dorr(12, .heavy)).foregroundStyle(wa.settingsInk)
                    }
                }
                Text(verbatim: c.title ?? "").font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(4).multilineTextAlignment(.leading)
                if let about = c.about, !about.isEmpty { Text(verbatim: about).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(3).lineLimit(2).multilineTextAlignment(.leading) }
                HStack(spacing: 8) {
                    Image(systemName: "timer").font(.system(size: 14)).foregroundStyle(wa.accent)
                    Text(verbatim: (c.minutes ?? []).map { L("cs_minutes", $0) }.joined(separator: " · ")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    Text(verbatim: L("cs_from_price", mkMoney(c.fromMinor, c.decimals, c.currency))).font(.dorr(13, .heavy)).foregroundStyle(wa.accent)
                }
                if c.nextSlot != nil {
                    // The first free time, on this phone's own clock.
                    HStack(spacing: 6) {
                        Image(systemName: "calendar").font(.system(size: 12)).foregroundStyle(csGreen)
                        Text(verbatim: L("cs_next_free", longDay(c.nextSlot), hm(c.nextSlot))).font(.dorr(11.5, .bold)).foregroundStyle(csGreen)
                    }
                }
            }
            .padding(16)
            .settingsSurface(22)
        }
        .buttonStyle(.plain)
    }
}

// MARK: - booking

@MainActor
struct BookConsultationPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let offerUuid: String
    let onBack: () -> Void
    let onBooked: (String) -> Void
    @State private var offer: ConsultOfferDto?
    @State private var error: String?
    @State private var minutes = 0
    @State private var slots: [String]?
    @State private var day: String?
    @State private var slot: String?
    @State private var topic = ""
    @State private var confirming = false
    @State private var busy = false
    @State private var bookError: String?
    // One id for this booking: a second tap (or a retry after a weak network) is the same booking.
    @State private var requestId = UUID().uuidString

    /// The free times grouped by the day they fall on, on this phone's clock ("yyyy-MM-dd" → instants).
    private var byDay: [(String, [String])] {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        var groups: [String: [String]] = [:]
        for s in slots ?? [] {
            guard let d = parseInstant(s) else { continue }
            groups[f.string(from: d), default: []].append(s)
        }
        return groups.keys.sorted().map { ($0, groups[$0] ?? []) }
    }

    private var option: ConsultOptionDto? { offer?.options?.first { $0.minutes == minutes } }
    private var ready: Bool { option != nil && slot != nil && topic.trimmingCharacters(in: .whitespacesAndNewlines).count >= 10 }

    private func loadSlots() async {
        guard minutes != 0 else { return }
        slots = nil
        do {
            slots = try await FreelanceConsultationsApi.slots(offerUuid, minutes: minutes).data?.slots ?? []
        } catch {
            slots = []
            self.error = flError(error)
        }
        let keys = byDay.map(\.0)
        if day == nil || !keys.contains(day!) { day = keys.first }
        if let s = slot, !(slots ?? []).contains(s) { slot = nil }
    }

    private func book() {
        guard let s = slot else { return }
        busy = true
        bookError = nil
        let t = topic.trimmingCharacters(in: .whitespacesAndNewlines)
        Task {
            do {
                let c = try await FreelanceConsultationsApi.book(offer: offerUuid, minutes: minutes, startsAt: s, topic: t, requestId: requestId).data
                confirming = false
                AppToast.show(L("cs_booked"))
                if let c { onBooked(c.uuid) }
            } catch {
                let f = error.apiFailure
                bookError = f.message ?? L("fl_error_generic")
                // Someone took this time a moment ago: the free times are read again.
                if f.errorCode == "freelance_consultation_slot_taken" {
                    slot = nil
                    await loadSlots()
                }
            }
            busy = false
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: L("cs_book_title"), onBack: onBack)
            if let o = offer {
                ScrollView { content(o).padding(.horizontal, 16).padding(.vertical, 6) }.scrollDismissesKeyboard(.interactively)
                bottomBar(o)
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: network.reconnectTick) {
            do {
                let o = try await FreelanceConsultationsApi.offer(offerUuid).data
                offer = o
                if minutes == 0 { minutes = o?.options?.first?.minutes ?? 0 }
                error = nil
            } catch {
                self.error = flError(error)
            }
        }
        .task(id: "\(minutes)|\(network.reconnectTick)") { await loadSlots() }
        .dorrDialog(isPresented: Binding(get: { confirming }, set: { if !$0 && !busy { confirming = false } }), bottom: true) {
            if let o = offer, let op = option, let s = slot { confirmSheet(o, op, s) }
        }
    }

    @ViewBuilder
    private func content(_ o: ConsultOfferDto) -> some View {
        let f = o.freelancer
        let rules = o.rules ?? ConsultRulesDto()
        VStack(alignment: .leading, spacing: 14) {
            // Who and what.
            VStack(alignment: .leading, spacing: 12) {
                HStack(spacing: 12) {
                    MkAvatar(url: f?.avatarUrl, name: f?.name, size: 56)
                    VStack(alignment: .leading, spacing: 3) {
                        Text(verbatim: f?.name ?? "").font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        if let h = f?.headline, !h.isEmpty { Text(verbatim: h).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                        HStack(spacing: 6) {
                            if let r = f?.rating {
                                Image(systemName: "star.fill").font(.system(size: 12)).foregroundStyle(Color(hex: 0xF59E0B))
                                Text(verbatim: String(format: "%.1f", r)).font(.dorr(12, .heavy)).foregroundStyle(wa.settingsInk)
                            }
                            if (f?.consultationsDone ?? 0) > 0 { Text(L("cs_done_n", f?.consultationsDone ?? 0)).font(.dorr(11, .bold)).foregroundStyle(csGreen) }
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
                Text(verbatim: (o.title?.isEmpty == false ? o.title : nil) ?? L("cs_default_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                if let about = o.about, !about.isEmpty { Text(verbatim: about).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(16)
            .settingsSurface(22)

            // What clients said, from projects and consultations.
            if let reviews = f?.reviews, !reviews.isEmpty {
                CsCard(title: L("cs_reviews_title", f?.reviewsCount ?? 0), icon: "star.fill") {
                    ForEach(Array(reviews.enumerated()), id: \.offset) { _, r in
                        VStack(alignment: .leading, spacing: 3) {
                            ReviewStars(stars: r.stars ?? 0)
                            if let c = r.comment, !c.isEmpty { Text(verbatim: c).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(3) }
                            Text(verbatim: [r.kind == "consultation" ? L("cs_review_kind") : nil, r.project].compactMap { $0 }.joined(separator: " · "))
                                .font(.dorr(11)).foregroundStyle(wa.settingsMut).lineLimit(1)
                        }
                    }
                }
            }

            // 1. The length.
            CsCard {
                StepHead(n: 1, title: L("cs_step_length"), done: option != nil)
                HStack(spacing: 8) {
                    ForEach(o.options ?? [], id: \.minutes) { op in
                        let on = op.minutes == minutes
                        Button {
                            if !on { minutes = op.minutes; slot = nil }
                        } label: {
                            VStack(spacing: 2) {
                                Image(systemName: "timer").font(.system(size: 15)).foregroundStyle(on ? .white : wa.accent)
                                Text(L("cs_minutes", op.minutes)).font(.dorr(13, .heavy)).foregroundStyle(on ? .white : wa.settingsInk).lineLimit(1)
                                Text(verbatim: mkMoney(op.totalMinor, o.decimals, o.currency)).font(.dorr(11, .bold)).foregroundStyle(on ? .white.opacity(0.9) : wa.settingsMut).lineLimit(1)
                            }
                            .frame(maxWidth: .infinity).padding(.vertical, 12).padding(.horizontal, 6)
                            .background(on ? wa.accent : wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        }
                        .buttonStyle(.plain)
                    }
                }
            }

            // 2. The day and the time.
            CsCard {
                StepHead(n: 2, title: L("cs_step_time"), done: slot != nil)
                if slots == nil {
                    WaSkeleton(cornerRadius: 18).frame(height: 70)
                } else if byDay.isEmpty {
                    FlNotice(text: L("cs_no_slots", rules.maxDaysAhead ?? 30), icon: "calendar.badge.exclamationmark", color: csAmber)
                } else {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 8) {
                            ForEach(byDay, id: \.0) { key, times in
                                DayChip(iso: times.first ?? "", count: times.count, on: key == day) { day = key }
                            }
                        }
                    }
                    let times = byDay.first { $0.0 == day }?.1 ?? []
                    FlowLayout(spacing: 8, lineSpacing: 8) {
                        ForEach(times, id: \.self) { s in
                            let on = s == slot
                            Button { slot = s } label: {
                                Text(verbatim: "\u{200E}" + hm(s)).font(.dorr(14, .heavy)).foregroundStyle(on ? .white : wa.settingsInk)
                                    .frame(width: 76).padding(.vertical, 10)
                                    .background(on ? wa.accent : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                                    .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(on ? wa.accent : wa.settingsMut.opacity(0.3), lineWidth: 1))
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    Text(L("cs_local_time")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                }
            }

            // 3. What about.
            CsCard {
                StepHead(n: 3, title: L("cs_step_topic"), done: topic.trimmingCharacters(in: .whitespacesAndNewlines).count >= 10)
                DorrTextArea(value: Binding(get: { topic }, set: { topic = String($0.prefix(1000)) }), placeholder: L("cs_topic_hint"), minHeight: 110)
                Text(L("cs_topic_rule")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }

            // The rules, said before paying.
            CsCard(title: L("cs_rules_title"), icon: "hammer.fill") {
                ForEach([("lock.fill", L("cs_rule_held")), ("hourglass", L("cs_rule_accept", rules.responseHours ?? 24)),
                         ("calendar.badge.exclamationmark", L("cs_rule_cancel", rules.freeCancelHours ?? 24)),
                         ("exclamationmark.bubble.fill", L("cs_rule_problem", rules.complaintHours ?? 24))], id: \.1) { icon, line in
                    HStack(alignment: .top, spacing: 10) {
                        Image(systemName: icon).font(.system(size: 14)).foregroundStyle(wa.accent).frame(width: 18)
                        Text(verbatim: line).font(.dorr(12.5)).foregroundStyle(wa.settingsInk).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
                    }
                }
            }
        }
    }

    /// The bar at the bottom: the total and the button.
    private func bottomBar(_ o: ConsultOfferDto) -> some View {
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 0) {
                Text(L("cs_total")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                Text(verbatim: option.map { mkMoney($0.totalMinor, o.decimals, o.currency) } ?? "—").font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                if let s = slot { Text(verbatim: "\(longDay(s)) · \u{200E}\(hm(s))").font(.dorr(11, .bold)).foregroundStyle(wa.accent).lineLimit(1) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            SettingsPrimaryButton(text: L("cs_book_cta"), enabled: ready) { bookError = nil; confirming = true }.frame(width: 170)
        }
        .padding(.horizontal, 16).padding(.vertical, 12)
        .background(wa.night ? wa.darkCard : .white, in: UnevenRoundedRectangleCompat(topRadius: 22))
    }

    private func confirmSheet(_ o: ConsultOfferDto, _ op: ConsultOptionDto, _ s: String) -> some View {
        FlSheet(icon: "calendar.badge.checkmark", title: L("cs_confirm_title"), text: L("cs_confirm_body"), footer: AnyView(VStack(spacing: 10) {
            if let bookError { FlNotice(text: bookError, icon: "xmark", color: csRed) }
            SettingsPrimaryButton(text: L("cs_confirm_cta"), loading: busy, action: book)
        })) {
            VStack(alignment: .leading, spacing: 9) {
                HStack(spacing: 10) {
                    MkAvatar(url: o.freelancer?.avatarUrl, name: o.freelancer?.name, size: 34)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: o.freelancer?.name ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: "\(longDay(s)) · \u{200E}\(hm(s))").font(.dorr(12, .bold)).foregroundStyle(wa.accent)
                    }
                }
                Rectangle().fill(wa.settingsMut.opacity(0.15)).frame(height: 1)
                CsLine(label: L("cs_minutes", op.minutes), value: mkMoney(op.priceMinor, o.decimals, o.currency))
                if (op.clientFeeMinor ?? 0) > 0 { CsLine(label: L("mk_fee"), value: mkMoney(op.clientFeeMinor, o.decimals, o.currency)) }
                CsLine(label: L("mk_total"), value: mkMoney(op.totalMinor, o.decimals, o.currency), strong: true)
            }
            .padding(14)
            .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            FlNotice(text: L("cs_confirm_safe", o.rules?.responseHours ?? 24), icon: "lock.fill", color: csGreen)
        }
    }
}

private struct DayChip: View {
    @Environment(\.wa) private var wa
    let iso: String
    let count: Int
    let on: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(spacing: 1) {
                Text(verbatim: csFormat(iso, "EEE")).font(.dorr(11, .bold)).foregroundStyle(on ? .white.opacity(0.9) : wa.settingsMut).lineLimit(1)
                Text(verbatim: csFormat(iso, "d")).font(.dorr(20, .heavy)).foregroundStyle(on ? .white : wa.settingsInk)
                Text(verbatim: csFormat(iso, "MMM")).font(.dorr(11, .bold)).foregroundStyle(on ? .white.opacity(0.9) : wa.settingsMut).lineLimit(1)
                Text(L("cs_times_n", count)).font(.dorr(10, .bold)).foregroundStyle(on ? .white : csGreen).lineLimit(1)
            }
            .frame(width: 66).padding(.vertical, 10)
            .background(on ? wa.accent : wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - my consultations

private let consultTabs = ["upcoming", "past", "cancelled"]

@MainActor
struct MyConsultationsPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpen: (String) -> Void
    @State private var tab = 0
    @StateObject private var paged = PagedRows<ConsultDto>(key: { $0.uuid })

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: L("cs_mine_title"), onBack: onBack)
            FlTabs(tabs: [
                FlTab(icon: "calendar.badge.checkmark", label: L("cs_tab_upcoming")),
                FlTab(icon: "checkmark.circle.fill", label: L("cs_tab_past")),
                FlTab(icon: "calendar.badge.exclamationmark", label: L("cs_tab_cancelled")),
            ], selected: tab) { tab = $0 }
            .padding(.horizontal, 16).padding(.vertical, 4)
            ScrollView {
                LazyVStack(spacing: 10) {
                    if !paged.loaded {
                        ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 110) }
                    } else if paged.rows.isEmpty && paged.error == nil {
                        WaEmpty(icon: "video.bubble.left.fill", tone: .blue, title: L("cs_empty_title"), text: L("cs_empty_body"))
                    } else {
                        ForEach(Array(paged.rows.enumerated()), id: \.element.uuid) { i, c in
                            ConsultTile(c: c) { onOpen(c.uuid) }.onAppear { paged.appeared(i) }
                        }
                    }
                    PagedSpinner(paged: paged)
                    PagedError(paged: paged)
                }
                .padding(.horizontal, 16).padding(.vertical, 10)
            }
        }
        .task(id: tab) {
            let t = consultTabs[tab]
            paged.reset { page in try await FreelanceConsultationsApi.list(tab: t, page: page) }
        }
        .task(id: "\(tab)|\(network.reconnectTick)") { await paged.reload() }
    }
}

private struct ConsultTile: View {
    @Environment(\.wa) private var wa
    let c: ConsultDto
    let onClick: () -> Void

    var body: some View {
        let (label, color) = csPhase(c.phase)
        Button(action: onClick) {
            HStack(alignment: .top, spacing: 12) {
                VStack(spacing: 0) {
                    Text(verbatim: csFormat(c.startsAt, "EEE")).font(.dorr(11, .bold)).foregroundStyle(color).lineLimit(1)
                    Text(verbatim: csFormat(c.startsAt, "d")).font(.dorr(22, .heavy)).foregroundStyle(color)
                    Text(verbatim: csFormat(c.startsAt, "MMM")).font(.dorr(11, .bold)).foregroundStyle(color).lineLimit(1)
                }
                .frame(width: 58).padding(.vertical, 10)
                .background(color.opacity(wa.night ? 0.22 : 0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                VStack(alignment: .leading, spacing: 5) {
                    HStack {
                        Text(verbatim: "\u{200E}\(hm(c.startsAt)) – \(hm(c.endsAt))").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                        Spacer(minLength: 4)
                        CsPill(text: label, color: color)
                    }
                    HStack(spacing: 6) {
                        MkAvatar(url: c.freelancer?.avatarUrl, name: c.freelancer?.name, size: 20)
                        Text(L("cs_with", c.freelancer?.name ?? "", c.minutes ?? 30)).font(.dorr(12, .bold)).foregroundStyle(wa.accent).lineLimit(1)
                    }
                    if let t = c.topic { Text(verbatim: t).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                    HStack(spacing: 6) {
                        Text(verbatim: mkMoney(c.money?.totalMinor, c.decimals, c.currency)).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        if c.phase == "upcoming", let u = until(c.startsAt) {
                            Image(systemName: "timer").font(.system(size: 12)).foregroundStyle(wa.settingsMut)
                            Text(verbatim: u).font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut)
                        }
                        if c.can?.confirm == true {
                            CsPill(text: L("cs_needs_you"), color: csAmber)
                        } else if c.review?.canRate == true {
                            CsPill(text: L("cs_rate_needs"), color: csAmber)
                        }
                    }
                }
            }
            .padding(12)
            .settingsSurface(20)
        }
        .buttonStyle(.plain)
    }
}

// MARK: - one consultation

@MainActor
struct ConsultationPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let uuid: String
    let onBack: () -> Void
    @State private var c: ConsultDto?
    @State private var error: String?
    @State private var busy = false
    @State private var cancelling = false
    @State private var confirming = false
    @State private var complaining = false
    @State private var complaint = ""
    @State private var rating = false
    @State private var stars = 0
    @State private var opinion = ""

    /// Runs one step; `then` gets the new state (after it went well it opens the rating, when it can be rated).
    private func act(_ done: String, then: ((ConsultDto) -> Void)? = nil, _ call: @escaping () async throws -> ConsultDto?) {
        busy = true
        Task {
            var fresh: ConsultDto?
            do {
                fresh = try await call()
                if let fresh { c = fresh }
                error = nil
                AppToast.show(L(done))
            } catch {
                self.error = error.apiFailure.message ?? L("fl_error_generic")
            }
            busy = false
            cancelling = false
            confirming = false
            complaining = false
            rating = false
            if let fresh, let then {
                try? await Task.sleep(nanoseconds: 350_000_000)
                then(fresh)
            }
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: L("cs_detail_title"), onBack: onBack)
            if let x = c {
                ScrollView { content(x).padding(.horizontal, 16).padding(.vertical, 6) }
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: network.reconnectTick) {
            do {
                c = try await FreelanceConsultationsApi.show(uuid).data
                error = nil
            } catch {
                self.error = flError(error)
            }
        }
        .dorrDialog(isPresented: Binding(get: { cancelling || confirming }, set: { if !$0 && !busy { cancelling = false; confirming = false } })) {
            if let x = c, cancelling {
                ConfirmDialog(icon: "calendar.badge.exclamationmark", title: L("cs_cancel_title"),
                              message: x.can?.cancelFree == true ? L("cs_cancel_free", mkMoney(x.money?.totalMinor, x.decimals, x.currency)) : L("cs_cancel_late", x.rules?.freeCancelHours ?? 24),
                              loading: busy, confirmLabel: L("cs_cancel_confirm"),
                              onConfirm: { act("cs_cancelled_done") { try await FreelanceConsultationsApi.cancel(uuid).data } },
                              onDismiss: { cancelling = false })
            } else if confirming {
                ConfirmDialog(icon: "hand.thumbsup.fill", title: L("cs_confirm_ok_title"), message: L("cs_confirm_ok_body"), loading: busy, confirmLabel: L("cs_confirm_ok"),
                              onConfirm: { act("cs_confirm_ok_done", then: { if $0.review?.canRate == true { rating = true } }) { try await FreelanceConsultationsApi.confirm(uuid).data } },
                              onDismiss: { confirming = false })
            }
        }
        .dorrDialog(isPresented: Binding(get: { complaining || rating }, set: { if !$0 && !busy { complaining = false; rating = false } }), bottom: true) {
            if complaining {
                FlSheet(icon: "exclamationmark.bubble.fill", title: L("cs_complain_title"), text: L("cs_complain_body"), footer: AnyView(
                    SettingsPrimaryButton(text: L("cs_complain_send"), enabled: complaint.trimmingCharacters(in: .whitespacesAndNewlines).count >= 10, loading: busy) {
                        let t = complaint.trimmingCharacters(in: .whitespacesAndNewlines)
                        act("cs_complain_done") { try await FreelanceConsultationsApi.complain(uuid, text: t).data }
                    })) {
                    DorrTextArea(value: Binding(get: { complaint }, set: { complaint = String($0.prefix(2000)) }), placeholder: L("cs_complain_hint"), minHeight: 110)
                    FlNotice(text: L("cs_complain_safe"), icon: "lock.fill", color: csGreen)
                }
            } else if rating, let x = c {
                FlSheet(icon: "star.fill", title: L("cs_rate_title"), text: L("cs_rate_body", x.freelancer?.name ?? ""), footer: AnyView(
                    SettingsPrimaryButton(text: L("cs_rate_send"), enabled: (1...5).contains(stars), loading: busy) {
                        let o = opinion.trimmingCharacters(in: .whitespacesAndNewlines)
                        act("cs_rate_done") { try await FreelanceConsultationsApi.review(uuid, stars: stars, comment: o.isEmpty ? nil : o).data }
                    })) {
                    VStack(spacing: 6) {
                        FlStars(value: stars, size: 40) { stars = $0 }
                        if stars > 0 { Text(verbatim: starWord(stars)).font(.dorr(14, .heavy)).foregroundStyle(wa.accent) }
                    }
                    .frame(maxWidth: .infinity)
                    DorrTextArea(value: Binding(get: { opinion }, set: { opinion = String($0.prefix(1000)) }), placeholder: L("cs_rate_hint"), minHeight: 90)
                }
            }
        }
    }

    @ViewBuilder
    private func content(_ x: ConsultDto) -> some View {
        VStack(alignment: .leading, spacing: 14) {
            ConsultHead(c: x)
            if let error { FlNotice(text: error, icon: "xmark", color: csRed) }
            ConsultNotes(c: x)
            if x.can?.confirm == true || x.can?.complain == true {
                HStack(spacing: 10) {
                    if x.can?.confirm == true { FlAction(text: L("cs_confirm_ok"), icon: "hand.thumbsup.fill", tone: .primary) { confirming = true } }
                    if x.can?.complain == true { FlAction(text: L("cs_complain"), icon: "exclamationmark.bubble.fill") { complaining = true } }
                }
            }
            ReviewBox(r: x.review ?? ConsultReviewDto()) { rating = true }
            if let topic = x.topic, !topic.isEmpty {
                CsCard(title: L("cs_topic"), icon: "quote.opening") {
                    Text(verbatim: topic).font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(5)
                        .frame(maxWidth: .infinity, alignment: .leading).padding(12)
                        .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
            }
            CsCard(title: L("cs_money"), icon: "banknote") {
                CsLine(label: L("cs_minutes", x.minutes ?? 30), value: mkMoney(x.money?.priceMinor, x.decimals, x.currency))
                if (x.money?.clientFeeMinor ?? 0) > 0 { CsLine(label: L("mk_fee"), value: mkMoney(x.money?.clientFeeMinor, x.decimals, x.currency)) }
                CsLine(label: L("mk_total"), value: mkMoney(x.money?.totalMinor, x.decimals, x.currency), strong: true)
            }
            if x.can?.cancel == true {
                Button { cancelling = true } label: {
                    Text(L("cs_cancel")).font(.dorr(14, .heavy)).foregroundStyle(csRed).frame(maxWidth: .infinity).padding(14).contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .disabled(busy)
            }
        }
    }
}

/// Once it is over: ask for the client's stars while they may still give them, or show the ones given.
private struct ReviewBox: View {
    @Environment(\.wa) private var wa
    let r: ConsultReviewDto
    let onRate: () -> Void

    var body: some View {
        if r.canRate == true || r.rating != nil {
            CsCard(title: L(r.rating == nil ? "cs_rate_title" : "cs_rate_mine"), icon: "star.fill") {
                if let given = r.rating {
                    let n = Int((given.stars ?? 0).rounded())
                    HStack(spacing: 8) {
                        FlStars(value: n, size: 22)
                        Text(verbatim: starWord(n)).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    }
                    if let c = given.comment, !c.isEmpty { Text(verbatim: "“\(c)”").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(4) }
                    Text(L("cs_rate_public")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                } else {
                    Text(verbatim: L("cs_rate_until", "\(longDay(r.rateUntil)) \u{200E}\(hm(r.rateUntil))")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                    FlAction(text: L("cs_rate_cta"), icon: "star.fill", tone: .primary, action: onRate)
                }
            }
        }
    }
}

private struct ConsultHead: View {
    @Environment(\.wa) private var wa
    let c: ConsultDto

    var body: some View {
        let (label, color) = csPhase(c.phase)
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                CsPill(text: label, color: color, solid: true)
                Spacer()
                Text(L("cs_minutes", c.minutes ?? 30)).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
            }
            Text(verbatim: c.title ?? "").font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(4)
            HStack(spacing: 12) {
                Image(systemName: "calendar").font(.system(size: 22)).foregroundStyle(color)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: longDay(c.startsAt)).font(.dorr(14, .heavy)).foregroundStyle(color)
                    Text(verbatim: "\u{200E}\(hm(c.startsAt)) – \(hm(c.endsAt))").font(.dorr(13, .bold)).foregroundStyle(color)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if c.phase == "upcoming" || c.phase == "pending", let u = until(c.startsAt) {
                    VStack(spacing: 2) {
                        Image(systemName: "hourglass").font(.system(size: 14)).foregroundStyle(color)
                        Text(verbatim: u).font(.dorr(11, .heavy)).foregroundStyle(color)
                    }
                }
            }
            .padding(14)
            .background(color.opacity(wa.night ? 0.22 : 0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            HStack(spacing: 10) {
                MkAvatar(url: c.freelancer?.avatarUrl, name: c.freelancer?.name, size: 38)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: c.freelancer?.name ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                    if let h = c.freelancer?.headline, !h.isEmpty { Text(verbatim: h).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                }
            }
        }
        .padding(16)
        .settingsSurface(22)
    }
}

/// What happens next, or what happened, plainly.
private struct ConsultNotes: View {
    @Environment(\.wa) private var wa
    let c: ConsultDto

    var body: some View {
        let reason = c.cancel?.reason.map { "\n“\($0)”" } ?? ""
        switch c.phase {
        case "pending":
            FlNotice(text: L("cs_note_pending", c.freelancer?.name ?? "", "\(longDay(c.respondBy)) \u{200E}\(hm(c.respondBy))"), icon: "hourglass", color: csAmber)
        case "declined":
            let key = c.cancel?.by == "freelancer" ? "cs_note_declined_freelancer" : (c.cancel?.reason == "taken" ? "cs_note_declined_taken" : "cs_note_declined_expired")
            FlNotice(text: L(key) + (c.cancel?.by == "freelancer" ? reason : ""), icon: "calendar.badge.exclamationmark", color: csSlate)
        case "upcoming":
            let until = c.rules?.freeCancelUntil
            FlNotice(text: c.can?.cancelFree == true ? L("cs_note_upcoming_free", "\(longDay(until)) \u{200E}\(hm(until))") : L("cs_note_upcoming_late"),
                     icon: "info.circle.fill", color: wa.accent)
        case "live":
            FlNotice(text: L("cs_note_live"), icon: "video.bubble.left.fill", color: csGreen)
        case "ended":
            let at = c.rules?.releaseAt
            FlNotice(text: L("cs_note_ended", "\(longDay(at)) \u{200E}\(hm(at))"), icon: "clock", color: csAmber)
        case "completed":
            FlNotice(text: L("cs_note_completed"), icon: "checkmark.circle.fill", color: csGreen)
        case "disputed":
            FlNotice(text: L("cs_note_disputed"), icon: "hammer.fill", color: csAmber)
        case "refunded":
            FlNotice(text: L("cs_note_refunded") + (c.resolution?.note.map { "\n\($0)" } ?? ""), icon: "checkmark.circle.fill", color: csGreen)
        default:
            let key = c.cancel?.by == "freelancer" ? "cs_note_cancelled_freelancer" : (c.cancel?.late == true ? "cs_note_cancelled_late" : "cs_note_cancelled_me")
            FlNotice(text: L(key) + reason, icon: "calendar.badge.exclamationmark", color: csSlate)
        }
    }
}
