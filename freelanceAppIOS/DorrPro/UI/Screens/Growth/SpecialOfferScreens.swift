import SwiftUI

// freelanceApp `ui/screens/SpecialOfferScreens.kt`.
//
// FL-L09: special offers to clients I finished work with. The list (with how my offers did and today's room), one offer, and the
// four-step composer: the client → what I offer → the details with a live "you get" → a preview as the client will see it. The client's
// yes starts the work at once. Lists reload on open, on a live notice and when the connection returns — never on a timer.

private let SPO_TABS = ["open", "accepted", "past"]
private let SPO_GREEN = Color(hex: 0x16A34A)

private func spoKindIcon(_ kind: String) -> String {
    switch kind {
    case "service": return "storefront.fill"
    case "consultation": return "video.fill"
    default: return "briefcase.fill"
    }
}

private func spoKindLabel(_ kind: String) -> String {
    switch kind {
    case "service": return L("spo_kind_service")
    case "consultation": return L("spo_kind_consultation")
    default: return L("spo_kind_custom")
    }
}

private func spoStatusLabel(_ status: String) -> String {
    switch status {
    case "accepted": return L("spo_status_accepted")
    case "declined": return L("spo_status_declined")
    case "withdrawn": return L("spo_status_withdrawn")
    case "expired": return L("spo_status_expired")
    default: return L("spo_status_sent")
    }
}

private func spoTone(_ status: String, night: Bool) -> SvcTone {
    let mapped: String
    switch status {
    case "accepted": mapped = "active"
    case "declined": mapped = "declined"
    case "sent": mapped = "awaiting"
    default: mapped = "paused"
    }
    return serviceTone(mapped, night: night)
}

/// "Ends in 2 days" / "Ends in 5 h" by the phone's clock, or nil past the end.
private func spoLeft(_ iso: String?) -> String? {
    guard let left = timeLeft(iso), left > 0 else { return nil }
    let days = Int(left / 86400)
    let hours = Int(left / 3600)
    if days >= 1 { return L("spo_left_days", days) }
    if hours >= 1 { return L("spo_left_hours", hours) }
    return L("spo_left_minutes", max(Int(left / 60), 1))
}

/// The price as the client reads it: the offered price, or "−25%" on a consultation.
private func spoPrice(_ o: SpecialOfferDto) -> String {
    if o.kind == "consultation" { return L("spo_discount_off", (o.discountBp ?? 0) / 100) }
    if let p = o.priceMinor { return svcMoney(p, o.decimals ?? 2, o.currency) }
    return ""
}

// ======================================================================== the list

struct SpecialOffersScreen: View {
    let onBack: () -> Void
    let onCompose: () -> Void
    let onOpen: (String) -> Void
    var onSwitchStore: ((String) -> Void)? = nil

    @EnvironmentObject private var network: NetworkMonitor
    @State private var tab = "open"
    @State private var pagedTab: String?
    @State private var info: SpecialOfferComposeDto?
    @State private var closed = false
    @StateObject private var paged = PagedRows<SpecialOfferDto>(perPage: 15, key: { $0.uuid ?? "" })

    private func loadInfo() async {
        let r = await SpecialOffersApi.compose()
        switch r {
        case .ok(let d, _):
            info = d
            closed = false
        case .failed(let e):
            if e.errorCode == "freelance_feature_off" { closed = true }
        case .offline:
            break
        }
    }

    private func reload() async {
        // `remember(tab)`: another tab is another list, which starts from its skeleton.
        if pagedTab != tab {
            let t = tab
            pagedTab = t
            paged.reset { page in await SpecialOffersApi.list(tab: t, page: page, perPage: 15) }
        }
        await paged.reload()
        await loadInfo()
    }

    private struct LoadKey: Equatable { let tab: String; let tick: Int }

    private func tabLabel(_ t: String) -> String {
        switch t {
        case "accepted": return L("spo_tab_accepted")
        case "past": return L("spo_tab_past")
        default: return L("spo_tab_open")
        }
    }

    var body: some View {
        Page(title: L("spo_title"), onBack: onBack, onNearEnd: { Task { await paged.loadMore() } }) {
            if closed {
                EmptyState(icon: "nosign", title: L("spo_closed_title"), body: L("spo_closed_body")).padding(.top, 70)
            } else {
                if let onSwitchStore { StoreSwitcher(current: "special_offers", onOpen: onSwitchStore) }
                SpoHero(info: info, onCompose: onCompose)
                let counts = info?.counts ?? [:]
                FilterChips(
                    options: SPO_TABS.map { t in
                        FilterOption(key: t, label: tabLabel(t) + ((counts[t] ?? 0) > 0 ? "  \(counts[t] ?? 0)" : ""))
                    },
                    selected: tab, onSelect: { tab = $0 }
                )
                if !paged.loaded && paged.failure == nil {
                    SkeletonList()
                } else if paged.loaded && paged.rows.isEmpty && paged.failure == nil {
                    EmptyState(icon: "tag.fill", title: L(tab == "open" ? "spo_empty_open" : "spo_empty_other"), body: L("spo_empty_body"))
                        .padding(.top, 40)
                } else {
                    ForEach(Array(paged.rows.enumerated()), id: \.offset) { _, o in SpoCard(o: o) { onOpen(o.uuid ?? "") } }
                }
                PagedFooter(paged: paged) { Task { await paged.loadMore() } }
            }
        }
        .messageToast(paged.failure.map { $0.errorText(L("login_error_offline")) ?? L("login_error_generic") })
        .task(id: LoadKey(tab: tab, tick: network.reconnectTick)) { await reload() }
        .onReceive(Realtime.shared.changed) { Task { await paged.reload(); await loadInfo() } }
    }
}

/// The head: what this is for, how my offers did, today's room and the button to make one.
private struct SpoHero: View {
    let info: SpecialOfferComposeDto?
    let onCompose: () -> Void

    var body: some View {
        let st = info?.stats
        let left = info?.todayLeft ?? 0
        StoreHero(
            icon: "tag.fill", title: L("spo_hero_title"), body: L("spo_hero_body"),
            numbers: [
                HeroNumber(value: "\(st?.sent ?? 0)", label: L("spo_stat_sent")),
                HeroNumber(value: "\(st?.seen ?? 0)", label: L("spo_stat_seen")),
                HeroNumber(value: "\(st?.accepted ?? 0)", label: L("spo_stat_accepted")),
                HeroNumber(value: "\(st?.acceptRate ?? 0)%", label: L("spo_stat_rate")),
            ],
            action: L("spo_new"), actionIcon: "paperplane.fill", actionEnabled: info != nil && left > 0, onAction: onCompose,
            footnote: info == nil ? nil : left > 0 ? L("spo_today_left", left) : L("spo_today_none")
        )
    }
}

private struct SpoCard: View {
    @Environment(\.wa) private var wa
    let o: SpecialOfferDto
    let onClick: () -> Void

    var body: some View {
        let kind = o.kind ?? "custom"
        let status = o.status ?? "sent"
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(spacing: 10) {
                    AvatarImage(url: o.client?.avatarUrl, size: 38)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: o.client?.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1).truncationMode(.tail)
                        HStack(spacing: 4) {
                            Image(systemName: spoKindIcon(kind)).font(.system(size: 11, weight: .semibold)).foregroundStyle(wa.red).frame(width: 13, height: 13)
                            Text(verbatim: spoKindLabel(kind)).font(.dorr(11.5, .bold)).foregroundStyle(wa.red)
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    SvcPill(text: spoStatusLabel(status), tone: spoTone(status, night: wa.night))
                }
                Text(verbatim: o.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2).truncationMode(.tail)
                    .multilineTextAlignment(.leading)
                HStack(spacing: 8) {
                    Text(verbatim: spoPrice(o)).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                    if let list = o.listPriceMinor {
                        Text(verbatim: svcMoney(list, o.decimals ?? 2, o.currency)).font(.dorr(12)).foregroundStyle(wa.settingsMut).strikethrough()
                    }
                    Spacer(minLength: 0)
                    if o.viewedAt != nil {
                        Image(systemName: "eye.fill").font(.system(size: 13, weight: .semibold)).foregroundStyle(SPO_GREEN).frame(width: 16, height: 16)
                            .accessibilityLabel(L("spo_seen"))
                    }
                }
                .padding(.horizontal, 12)
                .padding(.vertical, 10)
                .background(wa.red.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                if status == "sent", let text = spoLeft(o.expiresAt) {
                    let warn = serviceTone("awaiting", night: wa.night)
                    HStack(spacing: 6) {
                        Image(systemName: "hourglass").font(.system(size: 12, weight: .semibold)).foregroundStyle(warn.ink).frame(width: 15, height: 15)
                        Text(verbatim: text).font(.dorr(12, .bold)).foregroundStyle(warn.ink)
                    }
                }
            }
            .padding(14)
            .frame(maxWidth: 520, alignment: .leading)
            .settingsSurface(20)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity)
    }
}

// ======================================================================== one offer

struct SpecialOfferScreen: View {
    let uuid: String
    let onBack: () -> Void
    let onOpenWork: (String) -> Void
    let onOpenConsultation: (String) -> Void
    let onOpenOrder: (String) -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var offer: SpecialOfferDto?
    @State private var error: String?
    @State private var busy = false
    @State private var asking = false

    private func load() async {
        let r = await SpecialOffersApi.show(uuid)
        if let o = r.value {
            offer = o
            error = nil
        } else {
            error = r.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    private func withdraw() {
        busy = true
        Task {
            let r = await SpecialOffersApi.withdraw(uuid)
            busy = false
            asking = false
            if let o = r.value {
                offer = o
                await AppToast.show(L("spo_withdrawn"))
            } else {
                error = r.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    var body: some View {
        Page(title: L("spo_detail_title"), onBack: onBack) {
            if let o = offer {
                content(o)
            } else if error == nil {
                SkeletonDetail()
            }
        }
        .messageToast(error)
        .task(id: network.reconnectTick) { await load() }
        .onReceive(Realtime.shared.changed) { Task { await load() } }
        .dorrDialog(isPresented: Binding(get: { asking }, set: { if !$0 { asking = false } })) {
            ConfirmDialog(icon: "arrow.uturn.backward", title: L("spo_withdraw_title"), message: L("spo_withdraw_body"), loading: busy,
                          confirmLabel: L("spo_withdraw"), onConfirm: { withdraw() }, onDismiss: { asking = false })
        }
    }

    @ViewBuilder
    private func content(_ o: SpecialOfferDto) -> some View {
        let decimals = o.decimals ?? 2
        SpoHead(o: o)
        if let m = o.message, !m.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            InfoCard(title: L("spo_your_message"), icon: "quote.opening") {
                Text(verbatim: m).font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(5)
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(wa.red.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
        }
        SpoWhat(o: o)
        if let m = o.money {
            InfoCard(title: L("svo_money"), icon: "banknote.fill") {
                MoneyLine(label: L("svo_price"), amount: svcMoney(m.priceMinor ?? 0, decimals, o.currency))
                MoneyLine(label: L("svo_commission"), amount: "− " + svcMoney(m.commissionMinor ?? 0, decimals, o.currency), muted: true)
                Rectangle().fill(wa.night ? wa.line : Color(hex: 0xEEF0F3)).frame(height: 1)
                MoneyLine(label: L("svo_net"), amount: svcMoney(m.netMinor ?? 0, decimals, o.currency), strong: true)
            }
        }
        SpoTimeline(o: o)
        let result = o.result
        let accepted = o.status == "accepted"
        if o.can?.withdraw == true {
            let ink = serviceTone("rejected", night: wa.night).ink
            Button { asking = true } label: {
                HStack(spacing: 8) {
                    Image(systemName: "arrow.uturn.backward").font(.system(size: 15, weight: .semibold)).frame(width: 18, height: 18)
                    Text(L("spo_withdraw")).font(.dorr(15, .heavy))
                }
                .foregroundStyle(ink)
                .frame(maxWidth: 520)
                .frame(height: 50)
                .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(ink.opacity(0.4), lineWidth: 1.5))
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .disabled(busy)
            .frame(maxWidth: .infinity)
        } else if accepted, let c = result?.consultation {
            SettingsPrimaryButton(text: L("spo_open_consultation")) { onOpenConsultation(c) }.frame(maxWidth: 520).frame(maxWidth: .infinity)
        } else if accepted, let s = result?.serviceOrder {
            SettingsPrimaryButton(text: L("spo_open_order")) { onOpenOrder(s) }.frame(maxWidth: 520).frame(maxWidth: .infinity)
        } else if accepted, let p = result?.project {
            SettingsPrimaryButton(text: L("svo_open_work")) { onOpenWork(p) }.frame(maxWidth: 520).frame(maxWidth: .infinity)
        }
    }
}

private struct SpoHead: View {
    @Environment(\.wa) private var wa
    let o: SpecialOfferDto

    var body: some View {
        let status = o.status ?? "sent"
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 8) {
                SvcPill(text: spoStatusLabel(status), tone: spoTone(status, night: wa.night), solid: true)
                SvcPill(text: spoKindLabel(o.kind ?? "custom"), tone: SvcTone(ink: wa.red, fill: wa.red.opacity(0.10)))
            }
            Text(verbatim: o.title ?? "").font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(5)
            HStack(spacing: 10) {
                AvatarImage(url: o.client?.avatarUrl, size: 36)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("svo_client")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: o.client?.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: longDate(o.sentAt)).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
            if status == "sent", let text = spoLeft(o.expiresAt) {
                let warn = serviceTone("awaiting", night: wa.night)
                HStack(spacing: 10) {
                    Image(systemName: "hourglass").font(.system(size: 17, weight: .semibold)).foregroundStyle(warn.ink).frame(width: 20, height: 20)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: text).font(.dorr(14, .heavy)).foregroundStyle(warn.ink)
                        Text(L("spo_ends_on", longDate(o.expiresAt))).font(.dorr(12)).foregroundStyle(warn.ink.opacity(0.85))
                    }
                }
                .padding(12)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(warn.fill, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
        }
        .padding(16)
        .frame(maxWidth: 520, alignment: .leading)
        .settingsSurface(22)
        .frame(maxWidth: .infinity)
    }
}

/// What is offered: the work, the service level, or the consultation's lengths before → after.
private struct SpoWhat: View {
    @Environment(\.wa) private var wa
    let o: SpecialOfferDto

    var body: some View {
        let kind = o.kind ?? "custom"
        let decimals = o.decimals ?? 2
        InfoCard(title: L("spo_what"), icon: spoKindIcon(kind)) {
            switch kind {
            case "service":
                let s = o.service
                Text(verbatim: s?.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                HStack(spacing: 8) {
                    SvcPill(text: levelLabel(s?.level ?? "basic"), tone: SvcTone(ink: wa.red, fill: wa.red.opacity(0.10)))
                    if let tier = s?.tierName, !tier.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                        Text(verbatim: tier).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    }
                }
                ForEach(Array((s?.features ?? []).enumerated()), id: \.offset) { _, f in
                    HStack(spacing: 8) {
                        Image(systemName: "checkmark.circle.fill").font(.system(size: 13, weight: .semibold)).foregroundStyle(SPO_GREEN).frame(width: 16, height: 16)
                        Text(verbatim: f).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                    }
                }
                PriceCompare(o: o)
            case "consultation":
                Text(verbatim: o.consultation?.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                ForEach(Array((o.consultation?.lengths ?? []).enumerated()), id: \.offset) { _, l in
                    HStack(spacing: 8) {
                        Image(systemName: "clock").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.red).frame(width: 16, height: 16)
                        Text(L("spo_minutes", l.minutes ?? 0)).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                        if let list = l.listPriceMinor {
                            Text(verbatim: svcMoney(list, decimals, o.currency)).font(.dorr(12)).foregroundStyle(wa.settingsMut).strikethrough()
                        }
                        Text(verbatim: svcMoney(l.priceMinor ?? 0, decimals, o.currency)).font(.dorr(13, .heavy)).foregroundStyle(wa.red)
                    }
                }
                SavingBadge(text: L("spo_discount_off", (o.discountBp ?? 0) / 100))
            default:
                if let d = o.description { Text(verbatim: d).font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(5) }
                if let name = o.specialty?.name { SvcPill(text: name, tone: SvcTone(ink: wa.red, fill: wa.red.opacity(0.10))) }
            }
            if kind != "consultation" {
                let r = o.revisions ?? 0
                HStack(spacing: 8) {
                    SpoFact(icon: "clock", text: daysLabel(o.deliveryDays ?? 0))
                    SpoFact(icon: "arrow.counterclockwise", text: r > 0 ? L("svc_revisions", r) : L("svc_no_revisions"))
                }
                .padding(.top, 2)
            }
        }
    }
}

private struct PriceCompare: View {
    @Environment(\.wa) private var wa
    let o: SpecialOfferDto

    var body: some View {
        HStack(spacing: 10) {
            Text(verbatim: spoPrice(o)).font(.dorr(18, .heavy)).foregroundStyle(wa.red)
            if let list = o.listPriceMinor {
                Text(verbatim: svcMoney(list, o.decimals ?? 2, o.currency)).font(.dorr(13)).foregroundStyle(wa.settingsMut).strikethrough()
            }
            if let saving = o.savingMinor, saving > 0 {
                SavingBadge(text: L("spo_saving", svcMoney(saving, o.decimals ?? 2, o.currency)))
            }
        }
    }
}

private struct SavingBadge: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: "tag.fill").font(.system(size: 11, weight: .semibold)).foregroundStyle(SPO_GREEN).frame(width: 13, height: 13)
            Text(verbatim: text).font(.dorr(11.5, .heavy)).foregroundStyle(SPO_GREEN)
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 4)
        .background(SPO_GREEN.opacity(wa.night ? 0.22 : 0.12), in: Capsule())
    }
}

private struct SpoFact: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String

    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.red).frame(width: 16, height: 16)
            Text(verbatim: text).font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 9)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.red.opacity(0.06), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
    }
}

/// Sent → seen → answered, each with its day when it happened.
private struct SpoTimeline: View {
    @Environment(\.wa) private var wa
    let o: SpecialOfferDto

    var body: some View {
        let status = o.status ?? "sent"
        let steps: [(icon: String, label: String, at: String?)] = [
            ("paperplane.fill", L("spo_step_sent"), o.sentAt),
            ("eye.fill", L("spo_step_seen"), o.viewedAt),
            (status == "accepted" ? "checkmark.circle" : "nosign",
             status == "sent" ? L("spo_step_answer") : spoStatusLabel(status),
             o.answeredAt ?? (status == "expired" ? o.expiresAt : nil)),
        ]
        InfoCard(title: L("spo_timeline"), icon: "calendar.badge.checkmark") {
            VStack(alignment: .leading, spacing: 0) {
                ForEach(Array(steps.enumerated()), id: \.offset) { i, step in
                    let done = step.at != nil
                    HStack(alignment: .top, spacing: 12) {
                        VStack(spacing: 0) {
                            Image(systemName: step.icon).font(.system(size: 12, weight: .semibold)).foregroundStyle(done ? Color.white : wa.red)
                                .frame(width: 28, height: 28)
                                .background(done ? wa.red : wa.red.opacity(0.12), in: Circle())
                            if i < steps.count - 1 { Rectangle().fill(wa.red.opacity(0.18)).frame(width: 2, height: 16) }
                        }
                        VStack(alignment: .leading, spacing: 0) {
                            Text(verbatim: step.label).font(.dorr(13, done ? .bold : .medium)).foregroundStyle(done ? wa.settingsInk : wa.settingsMut)
                            if let at = step.at { Text(verbatim: longDate(at)).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
                        }
                        .padding(.top, 4)
                    }
                }
            }
            if let reason = o.declineReason, !reason.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                Text(L("spo_reason", reason)).font(.dorr(12, .semibold)).foregroundStyle(serviceTone("declined", night: wa.night).ink)
            }
        }
    }
}

// ======================================================================== the composer

/// Four steps, one at a time, with a bar of where I am and the back button stepping back: (1) the client — my past clients with what we
/// did together, the ones I can't offer now greyed with why; (2) what I offer; (3) the details with "you get" worked out by the server as I
/// type; (4) the offer as the client will read it, then Send. A double tap sends one offer (`request_id`).
struct SpecialOfferComposeScreen: View {
    let onBack: () -> Void
    let onSent: (String) -> Void

    @Environment(\.wa) private var wa
    @State private var step = 1
    @State private var info: SpecialOfferComposeDto?
    @State private var clients: [PastClientDto]?
    @State private var search = ""
    @State private var clientKey: String?
    @State private var kind = "custom"
    // custom
    @State private var title = ""
    @State private var description = ""
    @State private var specialtyId: Int?
    @State private var price = ""
    @State private var days = 5
    @State private var revisions = 2
    // service / consultation
    @State private var serviceUuid: String?
    @State private var level = "standard"
    @State private var consultationUuid: String?
    @State private var discount = 15
    // both
    @State private var message = ""
    @State private var validDays = 0
    @State private var requestId = UUID().uuidString.lowercased()
    @State private var quote: SpecialOfferQuoteDto?
    @State private var busy = false
    @State private var error: String?

    private struct QuoteKey: Equatable { let price: Int?; let kind: String; let specialty: Int? }

    private var decimals: Int { info?.decimals ?? 2 }
    private var currency: String? { info?.currency }
    private var service: SpecialServiceDto? { info?.services?.first(where: { $0.uuid == serviceUuid }) }
    private var tier: SpecialLevelDto? { service?.levels?.first(where: { $0.level == level }) ?? service?.levels?.first }
    private var consultation: SpecialConsultationDto? { info?.consultations?.first(where: { $0.uuid == consultationUuid }) }
    private var priceMinor: Int? { inputToMinor(price, decimals) }

    private var detailsProblem: String? {
        switch kind {
        case "custom":
            if title.trimmingCharacters(in: .whitespacesAndNewlines).count < 5 { return L("spo_need_title") }
            if description.trimmingCharacters(in: .whitespacesAndNewlines).count < 30 { return L("spo_need_description") }
            guard let p = priceMinor else { return L("spo_need_price") }
            if let min = info?.minPriceMinor, p < min { return L("spo_min_price", svcMoney(min, decimals, currency)) }
            return nil
        case "service":
            guard service != nil, let t = tier else { return L("spo_need_service") }
            guard let p = priceMinor else { return L("spo_need_price") }
            if p >= (t.priceMinor ?? 0) { return L("spo_price_not_lower", svcMoney(t.priceMinor ?? 0, decimals, currency)) }
            return nil
        default:
            return consultation == nil ? L("spo_need_consultation") : nil
        }
    }

    private func back() { if step > 1 { step -= 1 } else { onBack() } }

    private func loadCompose() async {
        let r = await SpecialOffersApi.compose()
        if let d = r.value {
            info = d
            if validDays == 0 { validDays = d.rules?.defaultValidDays ?? 7 }
            if specialtyId == nil {
                let specialties = d.specialties ?? []
                specialtyId = specialties.first(where: { $0.isPrimary == true })?.id ?? specialties.first?.id
            }
        } else {
            error = r.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    private func loadClients() async {
        if !search.isEmpty {
            try? await Task.sleep(nanoseconds: 350_000_000)
            if Task.isCancelled { return }
        }
        let q = search.trimmingCharacters(in: .whitespacesAndNewlines)
        let r = await SpecialOffersApi.clients(q.isEmpty ? nil : q)
        if Task.isCancelled { return }
        if let list = r.value { clients = list } else { error = r.errorText(L("login_error_offline")) ?? L("login_error_generic") }
    }

    /// "You get" for the price typed (custom work and a service price), worked out by the server a moment after typing stops.
    private func loadQuote() async {
        quote = nil
        guard let p = priceMinor, kind != "consultation" else { return }
        try? await Task.sleep(nanoseconds: 400_000_000)
        if Task.isCancelled { return }
        if let q = await SpecialOffersApi.quote(p, specialtyId: kind == "custom" ? specialtyId : nil).value, !Task.isCancelled { quote = q }
    }

    private func send() {
        guard let key = clientKey else { return }
        busy = true
        error = nil
        let trimmedMessage = message.trimmingCharacters(in: .whitespacesAndNewlines)
        let body = SpecialOfferRequest(
            client: key, kind: kind,
            title: kind == "custom" ? title.trimmingCharacters(in: .whitespacesAndNewlines) : nil,
            description: kind == "custom" ? description.trimmingCharacters(in: .whitespacesAndNewlines) : nil,
            message: trimmedMessage.isEmpty ? nil : trimmedMessage,
            specialtyId: kind == "custom" ? specialtyId : nil,
            service: kind == "service" ? service?.uuid : nil,
            level: kind == "service" ? tier?.level : nil,
            consultation: kind == "consultation" ? consultation?.uuid : nil,
            priceMinor: kind == "consultation" ? nil : priceMinor,
            discountBp: kind == "consultation" ? discount * 100 : nil,
            deliveryDays: kind == "custom" ? days : nil,
            revisions: kind == "custom" ? revisions : nil,
            validDays: validDays, requestId: requestId
        )
        Task {
            let r = await SpecialOffersApi.send(body)
            busy = false
            if let o = r.value {
                await AppToast.show(L("spo_sent"))
                onSent(o.uuid ?? "")
            } else {
                error = r.errorText(L("login_error_offline")) ?? L("login_error_generic")
                // A refusal is final for this tap: the next try is a new offer.
                requestId = UUID().uuidString.lowercased()
            }
        }
    }

    var body: some View {
        Page(title: L("spo_compose_title"), onBack: { back() }) {
            StepsBar(step: step)
            if let d = info {
                VStack(spacing: 14) {
                    stepContent(d)
                }
                .id(step)
                .transition(.opacity)
            } else if error == nil {
                SkeletonDetail()
            }
        }
        .animation(.easeInOut(duration: 0.25), value: step)
        .messageToast(error)
        .task { await loadCompose() }
        .task(id: search) { await loadClients() }
        .task(id: QuoteKey(price: priceMinor, kind: kind, specialty: specialtyId)) { await loadQuote() }
    }

    @ViewBuilder
    private func stepContent(_ d: SpecialOfferComposeDto) -> some View {
        switch step {
        case 1:
            ClientStep(clients: clients, search: $search, picked: clientKey) { clientKey = $0; step = 2 }
        case 2:
            KindStep(d: d, kind: kind) { kind = $0; price = ""; step = 3 }
        case 3:
            detailsStep(d)
        default:
            reviewStep()
        }
    }

    @ViewBuilder
    private func detailsStep(_ d: SpecialOfferComposeDto) -> some View {
        let rules = d.rules ?? SpecialOfferRulesDto()
        switch kind {
        case "custom":
            StepCard(number: 1, title: L("spo_custom_title"), subtitle: L("spo_custom_sub")) {
                DorrTextField(value: Binding(get: { title }, set: { title = String($0.prefix(120)) }), label: L("spo_field_title"))
                DorrTextField(value: Binding(get: { description }, set: { description = String($0.prefix(5000)) }), label: L("spo_field_description"),
                              singleLine: false, minLines: 4)
                let specialties = d.specialties ?? []
                if specialties.count > 1 {
                    ChipRow(options: specialties.map { (value: "\($0.id ?? 0)", label: $0.name ?? "") },
                            selected: specialtyId.map { ["\($0)"] } ?? []) { specialtyId = Int($0) }
                }
                SpoPriceField(value: $price, currency: currency)
                HStack(spacing: 10) {
                    Stepper(label: L("spo_field_days"), value: days, range: 1...365) { days = $0 }.frame(maxWidth: .infinity)
                    Stepper(label: L("spo_field_revisions"), value: revisions, range: 0...20) { revisions = $0 }.frame(maxWidth: .infinity)
                }
            }
        case "service":
            StepCard(number: 1, title: L("spo_service_title"), subtitle: L("spo_service_sub")) {
                ChipRow(options: (d.services ?? []).map { (value: $0.uuid ?? "", label: $0.title ?? "") },
                        selected: serviceUuid.map { [$0] } ?? []) { serviceUuid = $0; price = "" }
                if let service {
                    Segmented(options: (service.levels ?? []).map { ($0.level ?? "basic", levelLabel($0.level ?? "basic")) }, selected: tier?.level ?? "") {
                        level = $0
                        price = ""
                    }
                    if let t = tier {
                        MoneyLine(label: L("spo_usual_price"), amount: svcMoney(t.priceMinor ?? 0, decimals, currency), muted: true)
                        QuickPercents { pct in price = minorToInput((t.priceMinor ?? 0) * (100 - pct) / 100, decimals) }
                    }
                    SpoPriceField(value: $price, currency: currency, label: L("spo_field_special_price"))
                }
            }
        default:
            let min = (rules.minDiscountBp ?? 500) / 100
            let max = (rules.maxDiscountBp ?? 9000) / 100
            StepCard(number: 1, title: L("spo_consult_title"), subtitle: L("spo_consult_sub")) {
                ChipRow(options: (d.consultations ?? []).map { (value: $0.uuid ?? "", label: $0.title ?? "") },
                        selected: consultationUuid.map { [$0] } ?? []) { consultationUuid = $0 }
                Stepper(label: L("spo_field_discount"), value: discount, range: min...Swift.max(min, max)) { discount = $0 }
                QuickPercents(values: [10, 15, 20, 25, 30].filter { $0 >= min && $0 <= max }) { discount = $0 }
                ForEach(Array((consultation?.lengths ?? []).enumerated()), id: \.offset) { _, l in
                    let listPrice = l.priceMinor ?? 0
                    let after = listPrice * (100 - discount) / 100
                    HStack(spacing: 8) {
                        Text(L("spo_minutes", l.minutes ?? 0)).font(.dorr(13)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                        Text(verbatim: svcMoney(listPrice, decimals, currency)).font(.dorr(12)).foregroundStyle(wa.settingsMut).strikethrough()
                        Text(verbatim: svcMoney(after, decimals, currency)).font(.dorr(13, .heavy)).foregroundStyle(wa.red)
                    }
                }
            }
        }
        if let q = quote {
            InfoCard(title: L("svo_money"), icon: "banknote.fill") {
                MoneyLine(label: L("svo_price"), amount: svcMoney(q.priceMinor ?? 0, decimals, currency))
                MoneyLine(label: L("svo_commission"), amount: "− " + svcMoney(q.commissionMinor ?? 0, decimals, currency), muted: true)
                MoneyLine(label: L("svo_net"), amount: svcMoney(q.netMinor ?? 0, decimals, currency), strong: true)
                Text(L("spo_client_pays", svcMoney(q.clientTotalMinor ?? 0, decimals, currency))).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
            }
        }
        StepCard(number: 2, title: L("spo_personal_title"), subtitle: L("spo_personal_sub")) {
            DorrTextField(value: Binding(get: { message }, set: { message = String($0.prefix(1000)) }), label: L("spo_field_message"),
                          singleLine: false, minLines: 3)
            Stepper(label: L("spo_field_valid"), value: validDays, range: 1...Swift.max(rules.maxValidDays ?? 14, 1)) { validDays = $0 }
        }
        if let problem = detailsProblem {
            Text(verbatim: problem).font(.dorr(12, .semibold)).foregroundStyle(serviceTone("awaiting", night: wa.night).ink)
        }
        SettingsPrimaryButton(text: L("spo_preview"), enabled: detailsProblem == nil) { step = 4 }
            .frame(maxWidth: 520)
            .frame(maxWidth: .infinity)
    }

    @ViewBuilder
    private func reviewStep() -> some View {
        let client = clients?.first(where: { $0.key == clientKey })
        let reviewTitle: String = {
            switch kind {
            case "service": return service?.title ?? ""
            case "consultation": return consultation?.title ?? ""
            default: return title.trimmingCharacters(in: .whitespacesAndNewlines)
            }
        }()
        let subtitle: String? = {
            switch kind {
            case "service": return [tier.map { levelLabel($0.level ?? "basic") }, tier?.name].compactMap { $0 }.joined(separator: " · ")
            case "custom": return daysLabel(days)
            default: return nil
            }
        }()
        let shownPrice = kind == "consultation" ? L("spo_discount_off", discount) : (priceMinor.map { svcMoney($0, decimals, currency) } ?? "")
        let listPrice = kind == "service" ? tier.map { svcMoney($0.priceMinor ?? 0, decimals, currency) } : nil
        ReviewCard(clientName: client?.name, clientAvatar: client?.avatarUrl, kind: kind, title: reviewTitle, subtitle: subtitle,
                   price: shownPrice, listPrice: listPrice, message: message.trimmingCharacters(in: .whitespacesAndNewlines), validDays: validDays)
        SettingsPrimaryButton(text: L("spo_send"), loading: busy) { send() }
            .frame(maxWidth: 520)
            .frame(maxWidth: .infinity)
        Text(L("spo_send_hint")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineSpacing(4)
    }
}

/// 1 Client · 2 What · 3 Details · 4 Review — a bar that fills as I go.
private struct StepsBar: View {
    @Environment(\.wa) private var wa
    let step: Int

    var body: some View {
        let labels = ["spo_step_client", "spo_step_kind", "spo_step_details", "spo_step_review"]
        VStack(spacing: 8) {
            GeometryReader { geo in
                ZStack(alignment: .leading) {
                    Capsule().fill(wa.red.opacity(0.12))
                    Capsule().fill(wa.red).frame(width: geo.size.width * CGFloat(step) / 4)
                        .animation(.easeInOut(duration: 0.3), value: step)
                }
            }
            .frame(height: 6)
            HStack(spacing: 0) {
                ForEach(Array(labels.enumerated()), id: \.offset) { i, key in
                    Text(L(key)).font(.dorr(11.5, i + 1 == step ? .heavy : .semibold))
                        .foregroundStyle(i + 1 <= step ? wa.red : wa.settingsMut)
                        .lineLimit(1)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
            }
        }
        .frame(maxWidth: 520)
        .frame(maxWidth: .infinity)
    }
}

private struct ClientStep: View {
    @Environment(\.wa) private var wa
    let clients: [PastClientDto]?
    @Binding var search: String
    let picked: String?
    let onPick: (String) -> Void

    var body: some View {
        DorrTextField(value: $search, placeholder: L("spo_search_client"), icon: "magnifyingglass")
            .frame(maxWidth: 520)
            .frame(maxWidth: .infinity)
        if let clients {
            if clients.isEmpty {
                EmptyState(icon: "person.crop.circle.badge.questionmark",
                           title: L(search.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? "spo_no_clients" : "spo_no_match"),
                           body: L("spo_no_clients_body"))
                    .padding(.top, 30)
            } else {
                ForEach(Array(clients.enumerated()), id: \.offset) { _, c in row(c) }
            }
        } else {
            SkeletonList()
        }
    }

    private func row(_ c: PastClientDto) -> some View {
        let on = c.key == picked
        let canSend = c.canSend ?? true
        return Button { onPick(c.key ?? "") } label: {
            HStack(spacing: 12) {
                AvatarImage(url: c.avatarUrl, size: 44)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: c.name ?? "").font(.dorr(14, .bold)).foregroundStyle(canSend ? wa.settingsInk : wa.settingsMut).lineLimit(1).truncationMode(.tail)
                    Text(L("spo_client_works", c.works ?? 0)).font(.dorr(11.5, .bold)).foregroundStyle(wa.red)
                    if let last = c.lastTitle {
                        Text(L("spo_client_last", last)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineLimit(1).truncationMode(.tail)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                switch c.blockedReason ?? "" {
                case "has_open": SvcPill(text: L("spo_client_has_open"), tone: serviceTone("awaiting", night: wa.night))
                case "cooldown": SvcPill(text: L("spo_client_cooldown", longDate(c.blockedUntil)), tone: serviceTone("paused", night: wa.night))
                default: EmptyView()
                }
            }
            .padding(12)
            .frame(maxWidth: 520, alignment: .leading)
            .settingsSurface(18)
            .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(on ? wa.red : .clear, lineWidth: 2))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!canSend)
        .frame(maxWidth: .infinity)
    }
}

private struct KindStep: View {
    @Environment(\.wa) private var wa
    let d: SpecialOfferComposeDto
    let kind: String
    let onPick: (String) -> Void

    var body: some View {
        let kinds: [(String, String, String)] = [
            ("custom", "spo_kind_custom", "spo_kind_custom_body"),
            ("service", "spo_kind_service", "spo_kind_service_body"),
            ("consultation", "spo_kind_consultation", "spo_kind_consultation_body"),
        ]
        ForEach(kinds, id: \.0) { k, title, body in
            let enabled = d.kinds?[k] == true
            let on = k == kind
            Button { onPick(k) } label: {
                HStack(spacing: 14) {
                    Image(systemName: spoKindIcon(k)).font(.system(size: 20, weight: .semibold)).foregroundStyle(enabled ? wa.red : wa.settingsMut)
                        .frame(width: 46, height: 46)
                        .background(wa.red.opacity(enabled ? 0.12 : 0.05), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    VStack(alignment: .leading, spacing: 3) {
                        Text(L(title)).font(.dorr(15, .heavy)).foregroundStyle(enabled ? wa.settingsInk : wa.settingsMut)
                        Text(L(enabled ? body : (k == "custom" ? "spo_kind_off" : "spo_kind_none"))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                            .lineSpacing(3).multilineTextAlignment(.leading)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(16)
                .frame(maxWidth: 520, alignment: .leading)
                .settingsSurface(20)
                .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(on && enabled ? wa.red : .clear, lineWidth: 2))
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .disabled(!enabled)
            .frame(maxWidth: .infinity)
        }
    }
}

/// `PriceField` of SpecialOfferScreens.kt: digits and one decimal mark, the currency at the end.
private struct SpoPriceField: View {
    @Environment(\.wa) private var wa
    @Binding var value: String
    let currency: String?
    var label: String = L("spo_field_price")

    var body: some View {
        DorrTextField(
            value: Binding(get: { value }, set: { v in
                value = String(v.filter { $0.isNumber || $0 == "." || $0 == "," || $0 == "٫" }.prefix(14))
            }),
            label: label, icon: "banknote.fill",
            trailing: currency.map { c in AnyView(Text(verbatim: c).font(.dorr(13, .bold)).foregroundStyle(wa.settingsMut).padding(.trailing, 12)) },
            keyboard: .decimalPad
        )
    }
}

private struct QuickPercents: View {
    @Environment(\.wa) private var wa
    var values: [Int] = [10, 15, 20, 25]
    let onPick: (Int) -> Void

    var body: some View {
        HStack(spacing: 8) {
            ForEach(values, id: \.self) { pct in
                Button { onPick(pct) } label: {
                    Text(verbatim: "−\(pct)%").font(.dorr(12, .heavy)).foregroundStyle(wa.red)
                        .padding(.horizontal, 12)
                        .padding(.vertical, 7)
                        .background(wa.red.opacity(0.10), in: Capsule())
                }
                .buttonStyle(.plain)
            }
        }
    }
}

/// The offer as the client will see it in the DORR app.
private struct ReviewCard: View {
    @Environment(\.wa) private var wa
    let clientName: String?
    let clientAvatar: String?
    let kind: String
    let title: String
    let subtitle: String?
    let price: String
    let listPrice: String?
    let message: String
    let validDays: Int

    var body: some View {
        InfoCard(title: L("spo_review_title"), icon: "eye.fill") {
            HStack(spacing: 10) {
                AvatarImage(url: clientAvatar, size: 34)
                Text(L("spo_review_to", clientName ?? "")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
            }
            VStack(alignment: .leading, spacing: 8) {
                HStack(spacing: 6) {
                    Image(systemName: "tag.fill").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.red).frame(width: 16, height: 16)
                    Text(L("spo_review_badge")).font(.dorr(11.5, .heavy)).foregroundStyle(wa.red)
                    Spacer(minLength: 0)
                    Text(verbatim: spoKindLabel(kind)).font(.dorr(11, .bold)).foregroundStyle(wa.settingsMut)
                }
                Text(verbatim: title).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
                if let subtitle, !subtitle.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                    Text(verbatim: subtitle).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                HStack(spacing: 8) {
                    Text(verbatim: price).font(.dorr(20, .heavy)).foregroundStyle(wa.red)
                    if let listPrice { Text(verbatim: listPrice).font(.dorr(13)).foregroundStyle(wa.settingsMut).strikethrough() }
                }
                if !message.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                    Text(verbatim: "“\(message)”").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                }
                Text(L("spo_review_valid", validDays)).font(.dorr(11.5, .bold)).foregroundStyle(serviceTone("awaiting", night: wa.night).ink)
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(LinearGradient(colors: [wa.red.opacity(0.12), wa.red.opacity(0.03)], startPoint: .topLeading, endPoint: .bottomTrailing),
                        in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
    }
}
