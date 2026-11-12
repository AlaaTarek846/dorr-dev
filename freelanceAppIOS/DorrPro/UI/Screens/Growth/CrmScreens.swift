import SwiftUI

// freelanceApp `ui/screens/CrmScreens.kt`.
//
// FS-703: «My clients», the freelancer's private CRM. Two pages: the list (a head with the numbers, the follow-ups due, a search, five tabs with
// their counts, a card per client with the star) and one client (the numbers, the label, notes, follow-up reminders, the work we did together and
// what is still waiting). What a plan opens is the server's (`level`: 0 none, 1 clients + star + notes, 2 + labels and reminders): a plan with none
// sees what the page is and the plan that opens it, level 1 sees the rest locked with the plan's name. Read when a page opens, when the
// connection returns and when a notice arrives (a follow-up falling due is a push that opens the client) — never on a timer.

private let CRM_TABS = ["all", "active", "past", "leads", "starred"]
private let CrmGreen = Color(hex: 0x16A34A)
private let CrmRed = Color(hex: 0xDC2626)
private let CrmAmber = Color(hex: 0xD97706)

private func crmTone(_ color: Color) -> SvcTone { SvcTone(ink: color, fill: color.opacity(0.12)) }

/// The small tick under a tap that changes something (Compose `HapticFeedbackType.TextHandleMove`).
private func crmTick() { UISelectionFeedbackGenerator().selectionChanged() }

private extension String {
    var crmTrimmed: String { trimmingCharacters(in: .whitespacesAndNewlines) }
}

// MARK: - the list

/// The list read (the first page's frame with the numbers, then the clients page by page) and what it was asked for.
@MainActor
final class CrmListState: ObservableObject {
    @Published private(set) var tab = "all"
    @Published private(set) var query = ""
    /// The frame of the last answer: my level, the plan to upgrade to, the counts and the follow-ups due.
    @Published var frame: CrmOverviewDto?
    let feed = PagedRows<CrmClientDto>(perPage: 20, key: { $0.uuid ?? "" })

    init() { attach() }

    /// Another tab or search is another list: it starts again from its skeleton.
    private func attach() {
        feed.reset { [weak self] page in
            guard let self else { return .offline }
            return await self.fetch(page)
        }
    }

    func setTab(_ next: String) {
        guard next != tab else { return }
        tab = next
        attach()
    }

    func setQuery(_ next: String) {
        guard next != query else { return }
        query = next
        attach()
    }

    private func fetch(_ page: Int) async -> ApiResult<[CrmClientDto]> {
        let tabNow = tab
        let queryNow = query
        let r = await CrmApi.clients(tab: tabNow, search: queryNow.isEmpty ? nil : queryNow, page: page, perPage: 20)
        if let data = r.value, tabNow == tab, queryNow == query { frame = data }
        return r.rows { $0.clients }
    }
}

/// The list: the numbers on top, the follow-ups due (level 2, overdue in red), a name search (read 400 ms after the last key), the tabs with
/// their counts and a card per client. Pages fill in while scrolling. A star is changed on the spot and put back if the server refuses.
struct CrmScreen: View {
    let onBack: () -> Void
    let onOpenClient: (String) -> Void
    let onOpenPlans: () -> Void

    @EnvironmentObject private var network: NetworkMonitor
    @StateObject private var state = CrmListState()

    private struct LoadKey: Equatable { let tab: String; let query: String; let tick: Int }

    var body: some View {
        Page(title: L("crm_title"), onBack: onBack, onNearEnd: { Task { await state.feed.loadMore() } }) {
            CrmListContent(state: state, feed: state.feed, onOpenClient: onOpenClient, onOpenPlans: onOpenPlans)
        }
        .task(id: LoadKey(tab: state.tab, query: state.query, tick: network.reconnectTick)) { await state.feed.reload() }
        .onReceive(Realtime.shared.changed) { Task { await state.feed.reload() } }
    }
}

private struct CrmListContent: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: CrmListState
    @ObservedObject var feed: PagedRows<CrmClientDto>
    let onOpenClient: (String) -> Void
    let onOpenPlans: () -> Void

    @State private var typed = ""
    @State private var toast: String?

    private var offlineText: String { L("login_error_offline") }
    private var genericError: String { L("login_error_generic") }

    var body: some View {
        VStack(spacing: 14) {
            if let f = state.frame {
                if f.locked ?? false {
                    CrmLocked(upgrade: f.upgrade, onOpenPlans: onOpenPlans)
                } else {
                    loaded(f)
                }
            } else if let failure = feed.failure {
                CrmFailure(text: failure.errorText(offlineText) ?? genericError) {
                    feed.retry()
                    Task { await feed.reload() }
                }
            } else {
                SkeletonList()
            }
        }
        .frame(maxWidth: .infinity)
        .messageToast(toast ?? (state.frame != nil ? feed.failure.map { $0.errorText(offlineText) ?? genericError } : nil))
        .task(id: typed) { await debounce() }
    }

    /// The search is read once the typing pauses (400 ms after the last key), not on every letter; a new key cancels the wait.
    private func debounce() async {
        let wanted = typed.crmTrimmed
        guard wanted != state.query else { return }
        do { try await Task.sleep(nanoseconds: 400_000_000) } catch { return }
        state.setQuery(wanted)
    }

    @ViewBuilder
    private func loaded(_ f: CrmOverviewDto) -> some View {
        let counts = f.counts ?? CrmCountsDto()
        let level = f.level ?? 0
        StoreHero(
            icon: "person.crop.rectangle.stack.fill",
            title: L("crm_title"),
            body: L("crm_hero_body"),
            numbers: [
                HeroNumber(value: String(counts.all ?? 0), label: L("crm_hero_total")),
                HeroNumber(value: String(counts.active ?? 0), label: L("crm_hero_active")),
                HeroNumber(value: String(counts.past ?? 0), label: L("crm_hero_past")),
                HeroNumber(value: String(counts.leads ?? 0), label: L("crm_hero_leads")),
            ]
        )
        if level >= 2, let due = f.due, !due.isEmpty { CrmDueCard(due: due, onOpenClient: onOpenClient) }

        DorrTextField(
            value: agreementLimit($typed, 100), placeholder: L("crm_search_hint"), icon: "magnifyingglass",
            trailing: typed.isEmpty ? nil : AnyView(
                Button { typed = "" } label: {
                    Image(systemName: "xmark.circle.fill").font(.system(size: 17)).foregroundStyle(wa.settingsMut).frame(width: 30, height: 30)
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("crm_search_clear"))
            )
        )
        .frame(maxWidth: 520)
        FilterChips(
            options: CRM_TABS.map { FilterOption(key: $0, label: L("crm_tab_count", crmTabName($0), crmTabCount($0, counts))) },
            selected: state.tab,
            onSelect: { key in
                if key != state.tab {
                    crmTick()
                    state.setTab(key)
                }
            }
        )

        if !feed.loaded && feed.failure == nil {
            SkeletonList()
        } else if feed.loaded && feed.rows.isEmpty && feed.failure == nil {
            CrmEmpty(tab: state.tab, searching: !state.query.isEmpty)
        } else {
            ForEach(feed.rows) { c in
                CrmClientCard(c: c, level: level, decimals: f.decimals ?? 2, currency: f.currency,
                              onClick: { if let id = c.uuid { onOpenClient(id) } }, onStar: { toggleStar(c) })
            }
        }
        PagedFooter(paged: feed) { Task { await feed.loadMore() } }

        // The basic level sees what the next plan adds.
        if level < 2, let next = f.upgrade {
            InfoCard(title: L("crm_more_title"), icon: "bell.badge.fill") {
                Text(L("crm_more_body", next.name ?? next.slug ?? "")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                SettingsPrimaryButton(text: L("crm_unlock", next.name ?? next.slug ?? ""), action: onOpenPlans)
            }
        }
    }

    /// The star changes on the spot and goes back if the server refuses; a plan that changed under the list is read again.
    private func toggleStar(_ c: CrmClientDto) {
        guard let uuid = c.uuid else { return }
        let next = !(c.starred ?? false)
        crmTick()
        feed.update(where: { $0.uuid == uuid }) { row in
            var copy = row
            copy.starred = next
            return copy
        }
        toast = nil
        Task {
            let r = await CrmApi.update(uuid, CrmBody.star(next))
            if r.value != nil {
                if var f = state.frame {
                    var counts = f.counts ?? CrmCountsDto()
                    counts.starred = max((counts.starred ?? 0) + (next ? 1 : -1), 0)
                    f.counts = counts
                    state.frame = f
                }
                if state.tab == "starred" && !next { feed.remove(where: { $0.uuid == uuid }) }
            } else {
                feed.update(where: { $0.uuid == uuid }) { row in
                    var copy = row
                    copy.starred = !next
                    return copy
                }
                toast = r.errorText(offlineText) ?? genericError
                if r.error?.crmLocked() != nil { await feed.reload() }
            }
        }
    }
}

private func crmTabName(_ tab: String) -> String {
    switch tab {
    case "active": return L("crm_tab_active")
    case "past": return L("crm_tab_past")
    case "leads": return L("crm_tab_leads")
    case "starred": return L("crm_tab_starred")
    default: return L("crm_tab_all")
    }
}

private func crmTabCount(_ tab: String, _ counts: CrmCountsDto) -> Int {
    switch tab {
    case "active": return counts.active ?? 0
    case "past": return counts.past ?? 0
    case "leads": return counts.leads ?? 0
    case "starred": return counts.starred ?? 0
    default: return counts.all ?? 0
    }
}

private struct CrmEmpty: View {
    let tab: String
    let searching: Bool

    private var content: (icon: String, title: String, text: String) {
        if searching { return ("magnifyingglass", L("crm_empty_search_title"), L("crm_empty_search_body")) }
        switch tab {
        case "active": return ("briefcase.fill", L("crm_empty_active_title"), L("crm_empty_active_body"))
        case "past": return ("clock.arrow.circlepath", L("crm_empty_past_title"), L("crm_empty_past_body"))
        case "leads": return ("hourglass", L("crm_empty_leads_title"), L("crm_empty_leads_body"))
        case "starred": return ("star", L("crm_empty_starred_title"), L("crm_empty_starred_body"))
        default: return ("person.crop.rectangle.stack.fill", L("crm_empty_all_title"), L("crm_empty_all_body"))
        }
    }

    var body: some View {
        let c = content
        EmptyState(icon: c.icon, title: c.title, body: c.text).padding(.top, 24)
    }
}

/// A failed first read: the reason and a way to try again (offline included).
private struct CrmFailure: View {
    let text: String
    let onRetry: () -> Void

    var body: some View {
        VStack(spacing: 16) {
            EmptyState(icon: "icloud.slash", title: L("crm_title"), body: text).padding(.top, 50)
            SettingsPrimaryButton(text: L("common_retry"), action: onRetry)
        }
        .frame(maxWidth: 520)
    }
}

/// A plan without the CRM: what it is, and the plan that opens it (or that plans are not sold here).
private struct CrmLocked: View {
    @Environment(\.wa) private var wa
    let upgrade: CrmUpgradeDto?
    let onOpenPlans: () -> Void

    var body: some View {
        InfoCard {
            VStack(spacing: 10) {
                Image(systemName: "lock.fill").font(.system(size: 26)).foregroundStyle(wa.red)
                    .frame(width: 64, height: 64).background(wa.red.opacity(0.12), in: Circle())
                Text(L("crm_locked_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
                Text(L("crm_locked_body")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4).multilineTextAlignment(.center)
            }
            .frame(maxWidth: .infinity)
            VStack(alignment: .leading, spacing: 8) {
                ForEach(["crm_locked_b1", "crm_locked_b2", "crm_locked_b3", "crm_locked_b4"], id: \.self) { CrmPerk(text: L($0)) }
            }
            if let upgrade {
                SettingsPrimaryButton(text: L("crm_unlock", upgrade.name ?? upgrade.slug ?? ""), action: onOpenPlans)
            } else {
                Text(L("crm_no_plans")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                    .multilineTextAlignment(.center).frame(maxWidth: .infinity)
            }
        }
    }
}

private struct CrmPerk: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: "checkmark.circle.fill").font(.system(size: 16)).foregroundStyle(wa.red).frame(width: 18, height: 18)
            Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsInk)
        }
    }
}

/// A tappable row when it has somewhere to go; the plain row when it has not.
private struct CrmTap<Label: View>: View {
    let action: (() -> Void)?
    @ViewBuilder let label: () -> Label

    var body: some View {
        if let action {
            Button(action: action) { label().contentShape(Rectangle()) }.buttonStyle(.plain)
        } else {
            label()
        }
    }
}

/// The follow-ups that wait (the soonest first): the overdue ones in red. Four show; the rest open on a tap.
private struct CrmDueCard: View {
    @Environment(\.wa) private var wa
    let due: [CrmReminderDto]
    let onOpenClient: (String) -> Void
    @State private var all = false

    var body: some View {
        let shown = all ? due : Array(due.prefix(4))
        InfoCard(title: L("crm_due_title"), icon: "bell.badge.fill") {
            Text(L("crm_due_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            ForEach(shown) { r in CrmDueRow(r: r, onOpenClient: onOpenClient) }
            if due.count > 4 {
                Button {
                    crmTick()
                    all.toggle()
                } label: {
                    Text(all ? L("crm_due_less") : L("crm_due_more", due.count - 4)).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                        .padding(.vertical, 6).padding(.horizontal, 4)
                }
                .buttonStyle(.plain)
            }
        }
    }
}

private struct CrmDueRow: View {
    @Environment(\.wa) private var wa
    let r: CrmReminderDto
    let onOpenClient: (String) -> Void

    private var open: (() -> Void)? {
        guard let id = r.client?.uuid else { return nil }
        return { onOpenClient(id) }
    }

    var body: some View {
        let overdue = crmOverdue(r)
        let tone = overdue ? CrmRed : wa.red
        // The name and the moment each in their own bidi isolate (FSI / LRI … PDI): a Latin name next to a date in an Arabic line otherwise
        // splits the date's digits around the name.
        let clientName = r.client?.name?.crmTrimmed ?? ""
        let moment = crmDateTime(r.dueAt)
        let sub = [clientName.isEmpty ? "" : "\u{2068}\(clientName)\u{2069}", moment.isEmpty ? "" : "\u{2066}\(moment)\u{2069}"]
            .filter { !$0.isEmpty }.joined(separator: " · ")
        CrmTap(action: open) {
            HStack(spacing: 10) {
                Image(systemName: overdue ? "calendar.badge.exclamationmark" : "calendar").font(.system(size: 16, weight: .semibold)).foregroundStyle(tone)
                    .frame(width: 36, height: 36).background(tone.opacity(0.12), in: Circle())
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: r.title ?? "").font(.dorr(13.5, .semibold)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    Text(verbatim: sub).font(.dorr(11.5)).foregroundStyle(overdue ? CrmRed : wa.settingsMut).lineLimit(1)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if overdue { SvcPill(text: L("crm_overdue"), tone: crmTone(CrmRed)) }
            }
            .padding(.vertical, 4)
        }
    }
}

/// One client: the name and stage (and the label, from level 2), the star, the numbers, what waits and when we last did something.
private struct CrmClientCard: View {
    @Environment(\.wa) private var wa
    let c: CrmClientDto
    let level: Int
    let decimals: Int
    let currency: String?
    let onClick: () -> Void
    let onStar: () -> Void

    var body: some View {
        let name = c.name ?? ""
        let earned = c.earnedMinor ?? 0
        let notes = c.notesCount ?? 0
        let reminders = c.remindersCount ?? 0
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                Button(action: onClick) {
                    HStack(spacing: 10) {
                        CrmAvatar(name: name)
                        VStack(alignment: .leading, spacing: 5) {
                            Text(verbatim: name).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                            HStack(spacing: 6) {
                                SvcPill(text: crmStageName(c.stage), tone: crmStageTone(c.stage, mut: wa.settingsMut))
                                if level >= 2, let label = c.label, !label.crmTrimmed.isEmpty {
                                    SvcPill(text: crmLabelName(label), tone: crmLabelTone(label, mut: wa.settingsMut))
                                }
                            }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("crm_open_client", name))
                CrmStarButton(starred: c.starred ?? false, action: onStar)
            }
            VStack(alignment: .leading, spacing: 6) {
                HStack(spacing: 14) {
                    CrmFact(icon: "briefcase.fill", text: Lp("crm_n_projects", c.projects ?? 0))
                    if earned > 0 {
                        CrmFact(icon: "banknote.fill", text: L("crm_fact_earned", svcMoney(earned, decimals, currency)), strong: true, ltr: true)
                    }
                }
                if c.rating != nil || notes > 0 || reminders > 0 {
                    HStack(spacing: 14) {
                        if let rating = c.rating {
                            CrmFact(icon: "star.fill", text: String(format: "%.1f", locale: Locale(identifier: "en_US_POSIX"), rating), tint: Tints.amber)
                        }
                        if notes > 0 { CrmFact(icon: "note.text", text: Lp("crm_n_notes", notes)) }
                        if reminders > 0 { CrmFact(icon: "bell.badge.fill", text: Lp("crm_n_reminders", reminders)) }
                    }
                }
                if let due = c.nextDueAt, !due.crmTrimmed.isEmpty {
                    let late = (parseInstant(due) ?? .distantFuture) < Date()
                    CrmFact(icon: late ? "calendar.badge.exclamationmark" : "calendar", text: L("crm_next_due", crmDateTime(due)),
                            tint: late ? CrmRed : nil, strong: late)
                }
                if (c.waitingOffer ?? false) || (c.invitedYou ?? false) {
                    HStack(spacing: 6) {
                        if c.waitingOffer ?? false { SvcPill(text: L("crm_waiting_offer"), tone: crmTone(CrmAmber)) }
                        if c.invitedYou ?? false { SvcPill(text: L("crm_invited_you"), tone: crmTone(Tints.blue)) }
                    }
                }
                if let last = c.lastActivityAt, !last.crmTrimmed.isEmpty {
                    Text(L("crm_last_activity", crmDate(last))).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                }
            }
            .padding(.trailing, 8)
            .frame(maxWidth: .infinity, alignment: .leading)
            .contentShape(Rectangle())
            .onTapGesture(perform: onClick)
        }
        .padding(.leading, 14).padding(.top, 12).padding(.trailing, 6).padding(.bottom, 14)
        .frame(maxWidth: 520, alignment: .leading)
        .appCard(Corners.card)
        .frame(maxWidth: .infinity)
    }
}

/// A small fact: an icon and a short text; money is kept left-to-right in either language.
private struct CrmFact: View {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    let icon: String
    let text: String
    var tint: Color?
    var strong = false
    var ltr = false

    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: icon).font(.system(size: 12, weight: .semibold)).foregroundStyle(tint ?? (strong ? wa.red : wa.settingsMut))
                .frame(width: 15, height: 15)
            Text(verbatim: text).font(.dorr(12.5, strong ? .heavy : .semibold)).foregroundStyle(tint ?? (strong ? wa.settingsInk : wa.settingsMut))
                .lineLimit(1)
                .environment(\.layoutDirection, ltr ? .leftToRight : direction)
        }
    }
}

/// The star: 44 pt to tap, named for VoiceOver by what a tap does.
private struct CrmStarButton: View {
    @Environment(\.wa) private var wa
    let starred: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: starred ? "star.fill" : "star").font(.system(size: 21, weight: .semibold))
                .foregroundStyle(starred ? Tints.amber : wa.settingsMut)
                .frame(width: 44, height: 44)
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(L(starred ? "crm_star_on" : "crm_star_off"))
    }
}

/// An icon-only button 44 pt wide, named for VoiceOver.
private struct CrmIconButton: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    var size: CGFloat = 20
    var tint: Color?
    var disabled = false
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: size, weight: .semibold)).foregroundStyle(tint ?? wa.settingsMut)
                .frame(width: 44, height: 44)
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(disabled)
        .opacity(disabled ? 0.5 : 1)
        .accessibilityLabel(label)
    }
}

/// The client's first letter on a soft disc — a client has no picture here.
private struct CrmAvatar: View {
    @Environment(\.wa) private var wa
    let name: String?
    var size: CGFloat = 40

    var body: some View {
        let first = (name ?? "").crmTrimmed.first.map { String($0).uppercased() } ?? "?"
        Text(verbatim: first).font(.dorr(size * 0.4, .heavy)).foregroundStyle(wa.red)
            .frame(width: size, height: size)
            .background(wa.red.opacity(0.16), in: Circle())
            .accessibilityHidden(true)
    }
}

private func crmStageName(_ stage: String?) -> String {
    switch stage {
    case "active": return L("crm_stage_active")
    case "past": return L("crm_stage_past")
    default: return L("crm_stage_lead")
    }
}

private func crmStageTone(_ stage: String?, mut: Color) -> SvcTone {
    switch stage {
    case "active": return crmTone(CrmGreen)
    case "past": return crmTone(mut)
    default: return crmTone(CrmAmber)
    }
}

private func crmLabelName(_ label: String) -> String {
    switch label {
    case "vip": return L("crm_label_vip")
    case "regular": return L("crm_label_regular")
    case "lead": return L("crm_label_lead")
    case "paused": return L("crm_label_paused")
    default: return L("crm_label_none")
    }
}

private func crmLabelTone(_ label: String, mut: Color) -> SvcTone {
    switch label {
    case "vip": return crmTone(CrmAmber)
    case "regular": return crmTone(CrmGreen)
    case "lead": return crmTone(Tints.blue)
    default: return crmTone(mut)
    }
}

// MARK: - dates

/// «12 Dec 2026» in the app's language (Latin digits), in the phone's time zone.
private func crmDate(_ iso: String?) -> String {
    guard let at = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.dateStyle = .medium
    f.timeStyle = .none
    return f.string(from: at)
}

/// The date and the hour (`12 Dec 2026 · 09:30`).
private func crmDateTime(_ iso: String?) -> String {
    guard let at = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.dateFormat = "HH:mm"
    return crmDate(iso) + " · " + f.string(from: at)
}

/// Waiting and past its time: the server says so when it answers, and the phone's clock keeps saying so while the page stays open.
private func crmOverdue(_ r: CrmReminderDto) -> Bool {
    guard r.doneAt == nil else { return false }
    if r.overdue ?? false { return true }
    return (parseInstant(r.dueAt) ?? .distantFuture) < Date()
}

// MARK: - one client

private struct CrmNoteSheetState {
    let note: CrmNoteDto?
}

/// One client: the numbers, the star, the label (level 2; locked with the plan's name at level 1), what is still waiting, the follow-ups
/// (level 2), the private notes and the work we did together (a project opens its agreement). Every change answers with the whole client, so the
/// page repaints from it; a refusal's own message shows (in the sheet while one is open).
struct CrmClientScreen: View {
    let uuid: String
    let onBack: () -> Void
    let onOpenAgreement: (String) -> Void
    let onOpenProject: (String) -> Void
    let onOpenPlans: () -> Void

    @EnvironmentObject private var network: NetworkMonitor
    @State private var page: CrmClientPageDto?
    @State private var locked: CrmLockedDto?
    @State private var failure: String?
    @State private var toast: String?
    @State private var busy = false
    @State private var noteSheet: CrmNoteSheetState?
    @State private var reminderSheet = false
    // What the two sheets hold lives on the page, so each save button can stand under its sheet's fields (Android `FormSheet(footer = …)`).
    @State private var noteDraft = ""
    @State private var reminderDraft = CrmReminderDraft()
    @State private var sheetError: String?
    @State private var deleteNote: CrmNoteDto?
    @State private var deleteReminder: CrmReminderDto?

    private var offlineText: String { L("login_error_offline") }
    private var genericError: String { L("login_error_generic") }

    private func load() async {
        let r = await CrmApi.client(uuid)
        if let data = r.value {
            page = data
            locked = nil
            failure = nil
        } else if let refused = r.error?.crmLocked() {
            locked = refused
            page = nil
        } else if page == nil {
            failure = r.errorText(offlineText) ?? genericError
        }
    }

    /// Runs one change; nil when it went through (the page is the answer), else the text to show.
    private func change(_ call: @escaping () async -> ApiResult<CrmClientPageDto>) async -> String? {
        busy = true
        let r = await call()
        busy = false
        switch r {
        case .ok(let data, _):
            page = data
            return nil
        case .failed(let error):
            // The plan changed under the page (a downgrade): read again, so the page shows what it opens now.
            if error.crmLocked() != nil { await load() }
            let text = error.displayMessage
            return text.crmTrimmed.isEmpty ? genericError : text
        case .offline:
            return offlineText
        }
    }

    /// `done` gets the refusal's text (or nil); without it the text goes to the toast.
    private func send(_ call: @escaping () async -> ApiResult<CrmClientPageDto>, done: ((String?) -> Void)? = nil) {
        guard !busy else { return }
        toast = nil
        Task {
            let error = await change(call)
            if let done { done(error) } else { toast = error }
        }
    }

    var body: some View {
        Page(title: L("crm_client_title"), onBack: onBack) {
            if let p = page, let c = p.client {
                clientBody(p, c)
            } else if let refused = locked {
                CrmLocked(upgrade: refused.upgrade, onOpenPlans: onOpenPlans)
            } else if let failure {
                CrmFailure(text: failure) {
                    self.failure = nil
                    Task { await load() }
                }
            } else {
                SkeletonDetail()
            }
        }
        .messageToast(toast)
        .task(id: network.reconnectTick) { await load() }
        .onReceive(Realtime.shared.changed) { Task { await load() } }
        .formSheet(isPresented: agreementPresence($noteSheet), icon: "square.and.pencil",
                   title: L(noteSheet?.note == nil ? "crm_note_sheet_new" : "crm_note_sheet_edit"), text: L("crm_note_sheet_body"), locked: busy) {
            CrmNoteFields(text: $noteDraft, error: sheetError)
        } footer: {
            ActionButton(L("crm_note_save"), "square.and.arrow.down", .primary, enabled: !noteDraft.crmTrimmed.isEmpty && !busy, loading: busy) {
                saveNote(noteDraft.crmTrimmed)
            }
        }
        .formSheet(isPresented: $reminderSheet, icon: "bell.badge.fill", title: L("crm_rem_sheet_title"), text: L("crm_rem_sheet_body"), locked: busy) {
            CrmReminderFields(draft: $reminderDraft)
        } footer: {
            if reminderDraft.pastError || sheetError != nil {
                Text(verbatim: reminderDraft.pastError ? L("crm_rem_past") : (sheetError ?? "")).font(.dorr(12)).foregroundStyle(CrmRed).lineSpacing(3)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            ActionButton(L("crm_rem_save"), "square.and.arrow.down", .primary,
                         enabled: !reminderDraft.title.crmTrimmed.isEmpty && !reminderDraft.date.isEmpty && !busy, loading: busy) {
                submitReminder()
            }
        }
        .dorrDialog(isPresented: agreementPresence($deleteNote)) {
            ConfirmDialog(icon: "trash", title: L("crm_note_delete"), message: L("crm_note_delete_confirm"), loading: busy,
                          confirmLabel: L("common_delete"), onConfirm: { removeNote() }, onDismiss: { deleteNote = nil })
        }
        .dorrDialog(isPresented: agreementPresence($deleteReminder)) {
            ConfirmDialog(icon: "trash", title: L("crm_rem_delete"), message: L("crm_rem_delete_confirm"), loading: busy,
                          confirmLabel: L("common_delete"), onConfirm: { removeReminder() }, onDismiss: { deleteReminder = nil })
        }
    }

    @ViewBuilder
    private func clientBody(_ p: CrmClientPageDto, _ c: CrmClientDto) -> some View {
        CrmClientHead(c: c, p: p) {
            crmTick()
            send({ await CrmApi.update(uuid, CrmBody.star(!(c.starred ?? false))) })
        }
        CrmLabelCard(c: c, p: p, busy: busy) { label in send({ await CrmApi.update(uuid, CrmBody.label(label)) }) }
        if let leads = c.leads, !leads.isEmpty { CrmLeadsCard(leads: leads, onOpenProject: onOpenProject) }
        if (p.level ?? 0) >= 2 {
            CrmRemindersCard(
                reminders: c.reminders ?? [], busy: busy,
                onAdd: {
                    sheetError = nil
                    reminderDraft = CrmReminderDraft()
                    reminderSheet = true
                },
                onToggle: { r in
                    send({ await CrmApi.finishReminder(uuid, r.uuid ?? "", CrmReminderDoneRequest(done: r.doneAt == nil)) })
                },
                onDelete: { deleteReminder = $0 }
            )
        } else {
            CrmRemindersLocked(upgrade: p.upgrade, onOpenPlans: onOpenPlans)
        }
        CrmNotesCard(
            notes: c.notes ?? [], max: p.limits?.notesMax ?? 50, busy: busy,
            onAdd: {
                sheetError = nil
                noteDraft = ""
                noteSheet = CrmNoteSheetState(note: nil)
            },
            onEdit: { note in
                sheetError = nil
                noteDraft = note.body ?? ""
                noteSheet = CrmNoteSheetState(note: note)
            },
            onDelete: { deleteNote = $0 }
        )
        CrmHistoryCard(history: c.history ?? [], p: p, onOpenAgreement: onOpenAgreement)
    }

    private func saveNote(_ text: String) {
        let existing = noteSheet?.note?.uuid
        send({
            if let existing { return await CrmApi.editNote(uuid, existing, CrmNoteRequest(body: text)) }
            return await CrmApi.addNote(uuid, CrmNoteRequest(body: text))
        }, done: { error in
            if let error {
                sheetError = error
            } else {
                noteSheet = nil
                AppToast.show(L("crm_saved_ok"))
            }
        })
    }

    /// The time must be in the future; otherwise the sheet says so and nothing is sent.
    private func submitReminder() {
        guard let moment = crmMoment(day: reminderDraft.date, time: reminderDraft.time) else { return }
        if moment > Date() {
            saveReminder(reminderDraft.title.crmTrimmed, crmIso(moment))
        } else {
            reminderDraft.pastError = true
        }
    }

    private func saveReminder(_ title: String, _ dueAt: String) {
        send({ await CrmApi.addReminder(uuid, CrmReminderRequest(title: title, dueAt: dueAt)) }, done: { error in
            if let error {
                sheetError = error
            } else {
                reminderSheet = false
                AppToast.show(L("crm_saved_ok"))
            }
        })
    }

    private func removeNote() {
        guard let note = deleteNote?.uuid else { return }
        send({ await CrmApi.deleteNote(uuid, note) }, done: { error in
            deleteNote = nil
            toast = error
        })
    }

    private func removeReminder() {
        guard let reminder = deleteReminder?.uuid else { return }
        send({ await CrmApi.deleteReminder(uuid, reminder) }, done: { error in
            deleteReminder = nil
            toast = error
        })
    }
}

/// The top of the page: who, the stage and label, the star, the three numbers, what waits, and that only I see all this.
private struct CrmClientHead: View {
    @Environment(\.wa) private var wa
    let c: CrmClientDto
    let p: CrmClientPageDto
    let onStar: () -> Void

    var body: some View {
        let level = p.level ?? 0
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 12) {
                CrmAvatar(name: c.name, size: 52)
                VStack(alignment: .leading, spacing: 6) {
                    Text(verbatim: c.name ?? "").font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(3).lineLimit(2)
                    HStack(spacing: 6) {
                        SvcPill(text: crmStageName(c.stage), tone: crmStageTone(c.stage, mut: wa.settingsMut))
                        if level >= 2, let label = c.label, !label.crmTrimmed.isEmpty {
                            SvcPill(text: crmLabelName(label), tone: crmLabelTone(label, mut: wa.settingsMut))
                        }
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                CrmStarButton(starred: c.starred ?? false, action: onStar)
            }
            CrmWeightedRow(spacing: 8, weights: [1, 1.8, 1]) {
                CrmStat(value: String(c.projects ?? 0), label: L("crm_stat_projects"))
                CrmStat(value: svcMoney(c.earnedMinor ?? 0, p.decimals ?? 2, p.currency), label: L("crm_stat_earned"))
                CrmStat(value: c.rating.map { String(format: "%.1f", locale: Locale(identifier: "en_US_POSIX"), $0) } ?? "—", label: L("crm_stat_rating"))
            }
            .padding(.trailing, 8)
            if (c.waitingOffer ?? false) || (c.invitedYou ?? false) {
                HStack(spacing: 6) {
                    if c.waitingOffer ?? false { SvcPill(text: L("crm_waiting_offer"), tone: crmTone(CrmAmber)) }
                    if c.invitedYou ?? false { SvcPill(text: L("crm_invited_you"), tone: crmTone(Tints.blue)) }
                }
            }
            HStack(spacing: 6) {
                Image(systemName: "lock.fill").font(.system(size: 11, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 14, height: 14)
                Text(L("crm_private_note")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            }
            .padding(.trailing, 8)
        }
        .padding(.leading, 16).padding(.top, 14).padding(.trailing, 8).padding(.bottom, 16)
        .frame(maxWidth: 520, alignment: .leading)
        .settingsSurface(22)
        .frame(maxWidth: .infinity)
    }
}

/// A row that gives each child a share of its width by weight (Compose `Modifier.weight(w)` in a `Row`).
private struct CrmWeightedRow: Layout {
    let spacing: CGFloat
    let weights: [CGFloat]

    private func widths(_ total: CGFloat, count: Int) -> [CGFloat] {
        let ws = (0..<count).map { $0 < weights.count ? max(weights[$0], 0.0001) : 1 }
        let sum = ws.reduce(0, +)
        let free = max(total - spacing * CGFloat(max(count - 1, 0)), 0)
        return ws.map { free * $0 / sum }
    }

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let total = proposal.width ?? 300
        let ws = widths(total, count: subviews.count)
        let height = zip(subviews, ws).map { $0.sizeThatFits(ProposedViewSize(width: $1, height: nil)).height }.max() ?? 0
        return CGSize(width: total, height: height)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        let ws = widths(bounds.width, count: subviews.count)
        var x = bounds.minX
        for (sub, w) in zip(subviews, ws) {
            sub.place(at: CGPoint(x: x, y: bounds.minY), proposal: ProposedViewSize(width: w, height: bounds.height))
            x += w + spacing
        }
    }
}

private struct CrmStat: View {
    @Environment(\.wa) private var wa
    let value: String
    let label: String

    var body: some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(verbatim: value).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1).minimumScaleFactor(0.7)
                .environment(\.layoutDirection, .leftToRight)
            Text(verbatim: label).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineSpacing(1).lineLimit(2)
        }
        .padding(10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.red.opacity(0.07), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// The label: chosen from the plan's labels at level 2; at level 1 a locked line that names the plan, with no action.
private struct CrmLabelCard: View {
    @Environment(\.wa) private var wa
    let c: CrmClientDto
    let p: CrmClientPageDto
    let busy: Bool
    let onPick: (String?) -> Void

    // The chip fills at the tap and not when the server has answered: on a slow line a tap that waits for the answer looks like a tap that did nothing.
    @State private var pending: String?

    var body: some View {
        let shown = pending ?? (c.label ?? "")
        InfoCard(title: L("crm_label_title"), icon: "tag.fill") {
            if (p.level ?? 0) >= 2 {
                Text(L("crm_label_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                FilterChips(
                    options: [FilterOption(key: "", label: L("crm_label_none"))] + (p.labels ?? []).map { FilterOption(key: $0, label: crmLabelName($0)) },
                    selected: shown,
                    onSelect: { key in
                        if !busy && key != shown {
                            crmTick()
                            pending = key
                            onPick(key.isEmpty ? nil : key)
                        }
                    }
                )
            } else {
                let plan = p.upgrade.flatMap { $0.name ?? $0.slug }
                HStack(spacing: 8) {
                    Image(systemName: "lock.fill").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 18, height: 18)
                    Text(plan != nil ? L("crm_label_locked", plan ?? "") : L("crm_label_locked_plain")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                }
            }
        }
        // The answer (or the failure) came: the page's own value is shown again.
        .onChange(of: busy) { isBusy in if !isBusy { pending = nil } }
        .onChange(of: c.label) { _ in pending = nil }
    }
}

/// Offers of mine still waiting on him and his invitations to me: a tap opens the project.
private struct CrmLeadsCard: View {
    @Environment(\.wa) private var wa
    let leads: [CrmLeadDto]
    let onOpenProject: (String) -> Void

    var body: some View {
        InfoCard(title: L("crm_leads_title"), icon: "hourglass") {
            Text(L("crm_leads_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            ForEach(leads) { l in CrmLeadRow(l: l, onOpenProject: onOpenProject) }
        }
    }
}

private struct CrmLeadRow: View {
    @Environment(\.wa) private var wa
    let l: CrmLeadDto
    let onOpenProject: (String) -> Void

    private var open: (() -> Void)? {
        guard let id = l.project else { return nil }
        return { onOpenProject(id) }
    }

    private var statusText: String {
        switch l.status {
        case "viewed": return L("crm_lead_viewed")
        case "shortlisted": return L("crm_lead_shortlisted")
        case "pending": return L("crm_lead_pending")
        default: return L("crm_lead_submitted")
        }
    }

    var body: some View {
        let offer = l.kind == "offer"
        let sub = [L(offer ? "crm_lead_offer" : "crm_lead_invitation"), crmDate(l.at)].filter { !$0.crmTrimmed.isEmpty }.joined(separator: " · ")
        CrmTap(action: open) {
            HStack(spacing: 10) {
                Image(systemName: offer ? "paperplane.fill" : "envelope.fill").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 36, height: 36).background(wa.red.opacity(0.12), in: Circle())
                VStack(alignment: .leading, spacing: 2) {
                    let title = (l.title ?? "").crmTrimmed
                    Text(verbatim: title.isEmpty ? L("crm_untitled") : title).font(.dorr(13.5, .semibold)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    Text(verbatim: sub).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                SvcPill(text: statusText, tone: crmTone(CrmAmber))
            }
            .padding(.vertical, 4)
        }
    }
}

/// Level 2: the follow-ups (the open ones soonest first, then the last few done) with done / reopen and delete on each.
private struct CrmRemindersCard: View {
    @Environment(\.wa) private var wa
    let reminders: [CrmReminderDto]
    let busy: Bool
    let onAdd: () -> Void
    let onToggle: (CrmReminderDto) -> Void
    let onDelete: (CrmReminderDto) -> Void

    var body: some View {
        InfoCard(title: L("crm_rem_title"), icon: "bell.badge.fill") {
            Text(L("crm_rem_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            if reminders.isEmpty {
                Text(L("crm_rem_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            }
            ForEach(reminders) { r in
                CrmReminderRow(r: r, busy: busy, onToggle: { onToggle(r) }, onDelete: { onDelete(r) })
            }
            ActionButton(L("crm_rem_add"), "bell.badge.fill", .soft, enabled: !busy, action: onAdd)
        }
    }
}

private struct CrmReminderRow: View {
    @Environment(\.wa) private var wa
    let r: CrmReminderDto
    let busy: Bool
    let onToggle: () -> Void
    let onDelete: () -> Void

    var body: some View {
        let done = r.doneAt != nil
        let overdue = crmOverdue(r)
        HStack(spacing: 2) {
            CrmIconButton(icon: done ? "checkmark.circle.fill" : "circle", label: L(done ? "crm_rem_reopen" : "crm_rem_mark_done"), size: 22,
                          tint: done ? CrmGreen : (overdue ? CrmRed : wa.settingsMut), disabled: busy, action: onToggle)
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: r.title ?? "").font(.dorr(13.5, .semibold)).foregroundStyle(done ? wa.settingsMut : wa.settingsInk)
                    .strikethrough(done).lineLimit(3)
                Text(verbatim: done ? L("crm_rem_done_on", crmDate(r.doneAt)) : crmDateTime(r.dueAt))
                    .font(.dorr(11.5, overdue ? .bold : .regular)).foregroundStyle(overdue ? CrmRed : wa.settingsMut)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if overdue { SvcPill(text: L("crm_overdue"), tone: crmTone(CrmRed)) }
            CrmIconButton(icon: "trash", label: L("crm_rem_delete"), size: 19, disabled: busy, action: onDelete)
        }
    }
}

/// Level 1: a teaser for the reminders, naming the plan that opens them.
private struct CrmRemindersLocked: View {
    @Environment(\.wa) private var wa
    let upgrade: CrmUpgradeDto?
    let onOpenPlans: () -> Void

    var body: some View {
        let plan = upgrade.flatMap { $0.name ?? $0.slug }
        InfoCard(title: L("crm_rem_locked_title"), icon: "bell.badge.fill") {
            HStack(spacing: 8) {
                Image(systemName: "lock.fill").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 18, height: 18)
                Text(plan != nil ? L("crm_rem_locked_body", plan ?? "") : L("crm_rem_locked_plain")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            }
            if let plan { SettingsPrimaryButton(text: L("crm_unlock", plan), action: onOpenPlans) }
        }
    }
}

/// The private notes, the newest first, with the count against the plan's limit; the add button stops at the limit.
private struct CrmNotesCard: View {
    @Environment(\.wa) private var wa
    let notes: [CrmNoteDto]
    let max: Int
    let busy: Bool
    let onAdd: () -> Void
    let onEdit: (CrmNoteDto) -> Void
    let onDelete: (CrmNoteDto) -> Void

    var body: some View {
        let full = notes.count >= max
        InfoCard(title: L("crm_notes_title"), icon: "note.text") {
            HStack {
                Text(L("crm_notes_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                Text(L("crm_notes_count", notes.count, max)).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut).environment(\.layoutDirection, .leftToRight)
            }
            if notes.isEmpty {
                Text(L("crm_notes_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            }
            ForEach(notes) { n in
                CrmNoteRow(n: n, busy: busy, onEdit: { onEdit(n) }, onDelete: { onDelete(n) })
            }
            ActionButton(L("crm_note_add"), "plus", .soft, enabled: !busy && !full, action: onAdd)
            if full {
                Text(L("crm_notes_full_hint", max)).font(.dorr(12)).foregroundStyle(CrmAmber).lineSpacing(3)
            }
        }
    }
}

private struct CrmNoteRow: View {
    @Environment(\.wa) private var wa
    let n: CrmNoteDto
    let busy: Bool
    let onEdit: () -> Void
    let onDelete: () -> Void

    var body: some View {
        let edited = n.updatedAt != nil && n.updatedAt != n.createdAt
        VStack(alignment: .leading, spacing: 2) {
            Text(verbatim: n.body ?? "").font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(5)
                .padding(.trailing, 8).frame(maxWidth: .infinity, alignment: .leading)
            HStack(spacing: 0) {
                Text(verbatim: edited ? L("crm_note_edited", crmDate(n.updatedAt)) : crmDate(n.createdAt)).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    .frame(maxWidth: .infinity, alignment: .leading)
                CrmIconButton(icon: "pencil", label: L("crm_note_edit"), disabled: busy, action: onEdit)
                CrmIconButton(icon: "trash", label: L("crm_note_delete"), disabled: busy, action: onDelete)
            }
        }
        .padding(.leading, 12).padding(.top, 12).padding(.trailing, 4).padding(.bottom, 2)
        .background(wa.red.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// The work we did together (the newest first): each opens its agreement.
private struct CrmHistoryCard: View {
    @Environment(\.wa) private var wa
    let history: [CrmHistoryDto]
    let p: CrmClientPageDto
    let onOpenAgreement: (String) -> Void

    var body: some View {
        InfoCard(title: L("crm_hist_title"), icon: "clock.arrow.circlepath") {
            Text(L("crm_hist_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            if history.isEmpty {
                Text(L("crm_hist_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            } else {
                ForEach(Array(history.enumerated()), id: \.offset) { i, h in
                    if i > 0 { Rectangle().fill(wa.night ? wa.line : Color(hex: 0xEEF0F3)).frame(height: 1) }
                    CrmHistoryRow(h: h, p: p, onOpenAgreement: onOpenAgreement)
                }
            }
        }
    }
}

private struct CrmHistoryRow: View {
    @Environment(\.wa) private var wa
    let h: CrmHistoryDto
    let p: CrmClientPageDto
    let onOpenAgreement: (String) -> Void

    private var open: (() -> Void)? {
        guard let id = h.project else { return nil }
        return { onOpenAgreement(id) }
    }

    /// «Started 12 Dec 2026 · Finished 3 Jan 2027 · Ready-made service».
    private var datesLine: String {
        var parts: [String] = []
        let started = crmDate(h.startedAt)
        if !started.crmTrimmed.isEmpty { parts.append(L("crm_hist_started", started)) }
        let finished = crmDate(h.completedAt)
        if !finished.crmTrimmed.isEmpty { parts.append(L("crm_hist_finished", finished)) }
        if h.service ?? false { parts.append(L("crm_hist_service")) }
        return parts.joined(separator: " · ")
    }

    var body: some View {
        let done = h.status == "completed"
        let decimals = p.decimals ?? 2
        let title = (h.title ?? "").crmTrimmed
        let dates = datesLine
        CrmTap(action: open) {
            VStack(alignment: .leading, spacing: 6) {
                HStack(alignment: .top, spacing: 8) {
                    Text(verbatim: title.isEmpty ? L("crm_untitled") : title).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    SvcPill(text: L(done ? "crm_hist_completed" : "crm_hist_active"), tone: done ? crmTone(CrmGreen) : crmTone(wa.red))
                }
                HStack(spacing: 14) {
                    CrmFact(icon: "banknote.fill", text: L("crm_hist_price", svcMoney(h.priceMinor ?? 0, decimals, p.currency)), ltr: true)
                    if (h.earnedMinor ?? 0) > 0 {
                        CrmFact(icon: "checkmark.circle.fill", text: L("crm_hist_earned", svcMoney(h.earnedMinor ?? 0, decimals, p.currency)), strong: true, ltr: true)
                    }
                }
                if !dates.isEmpty {
                    Text(verbatim: dates).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.vertical, 4)
        }
    }
}

// MARK: - sheets

/// A note's fields: up to 1000 characters with a counter; a refusal's text (the notes are full) stays in the sheet. The save button is the
/// sheet's footer (see `CrmClientScreen`).
private struct CrmNoteFields: View {
    @Environment(\.wa) private var wa
    @Binding var text: String
    let error: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            DorrTextField(value: agreementLimit($text, 1000), label: L("crm_note_field"), placeholder: L("crm_note_hint"), singleLine: false, minLines: 5)
            HStack(alignment: .top, spacing: 8) {
                Text(verbatim: error ?? "").font(.dorr(12)).foregroundStyle(CrmRed).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: "\(text.count) / 1000").font(.dorr(12)).foregroundStyle(wa.settingsMut).environment(\.layoutDirection, .leftToRight)
            }
        }
    }
}

private let crmGregorian = Calendar(identifier: .gregorian)

/// `YYYY-MM-DD` of the day `days` from today.
private func crmDay(_ days: Int) -> String {
    DateField.format(crmGregorian.date(byAdding: .day, value: days, to: Date()) ?? Date())
}

/// The day (`YYYY-MM-DD`) and the hour (`HH:mm`) as one moment in the phone's time zone; nil when either is not valid.
private func crmMoment(day: String, time: String) -> Date? {
    guard let date = DateField.parse(day) else { return nil }
    let parts = time.split(separator: ":").compactMap { Int($0) }
    guard parts.count == 2, (0..<24).contains(parts[0]), (0..<60).contains(parts[1]) else { return nil }
    return crmGregorian.date(bySettingHour: parts[0], minute: parts[1], second: 0, of: date)
}

/// What the server takes: `2026-12-12T09:00:00+02:00`.
private func crmIso(_ moment: Date) -> String {
    let f = ISO8601DateFormatter()
    f.formatOptions = [.withInternetDateTime]
    f.timeZone = .current
    return f.string(from: moment)
}

/// What a new follow-up holds while its sheet is open: kept by the page, so the save button can stand under the fields.
private struct CrmReminderDraft {
    var title = ""
    var date = crmDay(1)
    var time = "09:00"
    var pastError = false
}

/// A follow-up's fields: what to follow up, and when — the date and the hour (quick picks for tomorrow, a week and a month at 9:00). The time
/// must be in the future; the server's own limits (a year ahead, the open reminders' cap) come back as its message, in the sheet's footer, which
/// also holds the save button (see `CrmClientScreen`).
private struct CrmReminderFields: View {
    @Environment(\.wa) private var wa
    @Binding var draft: CrmReminderDraft

    @State private var picking = false
    @State private var picked = Date()

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            DorrTextField(value: agreementLimit($draft.title, 120), label: L("crm_rem_field_title"), placeholder: L("crm_rem_field_title_ph"), singleLine: false, minLines: 2)
            Text(L("crm_rem_quick")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(Array([("crm_quick_tomorrow", 1), ("crm_quick_week", 7), ("crm_quick_month", 30)].enumerated()), id: \.offset) { _, quick in
                        PillChip(text: L(quick.0), selected: draft.date == crmDay(quick.1) && draft.time == "09:00") {
                            crmTick()
                            draft.date = crmDay(quick.1)
                            draft.time = "09:00"
                            draft.pastError = false
                        }
                    }
                }
                .padding(.vertical, 1)
            }
            DateField(value: Binding(get: { draft.date }, set: { draft.date = $0; draft.pastError = false }), label: L("crm_rem_field_date"))
            DorrTextField(value: .constant(draft.time), label: L("crm_rem_field_time"), icon: "clock", onClick: {
                picked = crmMoment(day: crmDay(0), time: draft.time) ?? Date()
                picking = true
            })
        }
        .dorrDialog(isPresented: $picking) {
            DialogCard {
                VStack(spacing: 12) {
                    Text(L("crm_rem_pick_time")).font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk)
                    DatePicker("", selection: $picked, displayedComponents: .hourAndMinute)
                        .datePickerStyle(.wheel)
                        .labelsHidden()
                        .tint(wa.red)
                        // 24-hour wheels with Latin digits in either language.
                        .environment(\.locale, Locale(identifier: "en_GB"))
                    HStack {
                        Button(L("common_cancel")) { picking = false }.foregroundStyle(wa.settingsMut)
                        Spacer()
                        Button(L("common_ok")) {
                            let parts = crmGregorian.dateComponents([.hour, .minute], from: picked)
                            draft.time = String(format: "%02ld:%02ld", parts.hour ?? 9, parts.minute ?? 0)
                            draft.pastError = false
                            picking = false
                        }
                        .foregroundStyle(wa.red)
                    }
                    .font(.dorr(15, .bold))
                }
                .padding(14)
            }
            .frame(maxWidth: 360)
        }
    }
}
