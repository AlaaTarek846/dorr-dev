// freelanceApp `ui/screens/ProjectsScreens.kt`
import SwiftUI

private let PAGE_SIZE = 15

/// What the filter sheet holds. Money is typed in the currency's main unit and sent in minor units.
private struct ProjectFilter: Equatable {
    var specialty: SpecialtyNodeDto?
    var budgetMin = ""
    var budgetMax = ""
    var durationMax = ""

    var active: Int {
        [specialty != nil, !budgetMin.isBlankText, !budgetMax.isBlankText, !durationMax.isBlankText].filter { $0 }.count
    }
}

private extension String {
    var isBlankText: Bool { trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
}

private func minor(_ text: String) -> Int? { text.isBlankText ? nil : parseMinor(text) }

/// "5 days ago" in the notification wording (the notices' time strings are reused).
func publishedAgo(_ iso: String?) -> String? {
    guard let date = parseInstant(iso) else { return nil }
    let minutes = max(Int(Date().timeIntervalSince(date) / 60), 0)
    switch minutes {
    case ..<1: return L("notif_time_now")
    case ..<60: return L("notif_time_minutes_ago", minutes)
    case ..<(24 * 60): return L("notif_time_hours_ago", minutes / 60)
    case ..<(2 * 24 * 60): return L("notif_time_yesterday")
    case ..<(7 * 24 * 60): return L("notif_time_days_ago", minutes / (24 * 60))
    default: return L("notif_time_weeks_ago", minutes / (7 * 24 * 60))
    }
}

func budgetText(_ b: ProjectBudgetDto?) -> String? {
    guard let low = b?.minMinor else { return nil }
    let range: String
    if b?.type == "range", let high = b?.maxMinor { range = "\(formatMinor(low, nil)) – \(formatMinor(high, nil))" } else { range = formatMinor(low, nil) }
    return "\u{200E}\(range) \(b?.currency ?? "")"
}

/// "N days left" while offers are open, "closed for offers" once they are not.
private func offersLine(_ p: ProjectDto) -> (text: String, open: Bool)? {
    if p.offers?.closeReason == "chosen" { return (L("projects_offer_chosen"), false) }
    if p.offers?.accepting == false { return (L("projects_offers_closed"), false) }
    guard let deadline = parseInstant(p.offers?.deadlineAt) else { return nil }
    // Duration.toDays(): whole days, truncated toward zero.
    let left = Int(deadline.timeIntervalSinceNow / 86_400)
    return (L(left <= 0 ? "projects_offers_last_day" : "projects_offers_left", left), true)
}

private let green = Color(hex: 0x16A34A)
private let amber = Color(hex: 0xD97706)
private let starColor = Color(hex: 0xF59E0B)

// MARK: - the list (a tab)

/// Projects (FL-206): the published projects of my country, newest first — a search box, a filter sheet (specialty, budget, duration),
/// the saved ones, 15 at a time with infinite scroll. Read when the page opens and when the connection returns; nothing on a timer.
@MainActor
struct ProjectsTab: View {
    let onBack: () -> Void
    let onOpenProject: (String) -> Void
    var embedded = false

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor

    @State private var query = ""
    @State private var savedOnly = false
    // My specialties by default when I have any (read once); "all" shows the rest too.
    @State private var hasSpecialties: Bool?
    @State private var reach = "mine"
    @State private var filter = ProjectFilter()
    @State private var sheet = false

    @State private var rows: [ProjectDto] = []
    @State private var page = 0
    @State private var hasMore = true
    @State private var loading = false
    @State private var loaded = false
    @State private var failed: String?
    // A first page (new search, filter, reconnect) replaces whatever is still on its way; a later page waits for the one before it.
    @State private var generation = 0
    @State private var refreshing = false

    private var reloadKey: String {
        "\(query)|\(filter.specialty?.id ?? -1)|\(filter.budgetMin)|\(filter.budgetMax)|\(filter.durationMax)|\(savedOnly)|\(reach)|\(String(describing: hasSpecialties))|\(network.reconnectTick)"
    }

    var body: some View {
        VStack(spacing: 0) {
            if !embedded { SubHeader(title: L("projects_title"), onBack: onBack) }
            VStack(spacing: 0) {
                // The search box with the filter button inside it (its number shows how many filters are on).
                DorrTextField(value: Binding(get: { query }, set: { query = String($0.prefix(100)) }), placeholder: L("projects_search"), icon: "magnifyingglass",
                              trailing: AnyView(searchTrailing))
                    .frame(maxWidth: .infinity)
                // Where to look: my specialties, all of them, or the ones I saved; then each filter that is on, removable on its own.
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        if hasSpecialties == true {
                            ProjectChip(text: L("projects_mine"), selected: !savedOnly && reach == "mine", icon: "sparkles") { savedOnly = false; reach = "mine" }
                            ProjectChip(text: L("projects_all_specialties"), selected: !savedOnly && reach == "all", icon: "square.grid.2x2.fill") { savedOnly = false; reach = "all" }
                        } else {
                            ProjectChip(text: L("projects_all"), selected: !savedOnly, icon: "square.grid.2x2.fill") { savedOnly = false }
                        }
                        ProjectChip(text: L("projects_saved"), selected: savedOnly, icon: "star.fill") { savedOnly = true }
                        if let sp = filter.specialty { FilterOnChip(text: sp.name ?? "") { filter.specialty = nil } }
                        if !filter.budgetMin.isBlankText || !filter.budgetMax.isBlankText {
                            let range = [filter.budgetMin, filter.budgetMax].filter { !$0.isBlankText }.joined(separator: " – ")
                            FilterOnChip(text: L("projects_chip_budget", range)) { filter.budgetMin = ""; filter.budgetMax = "" }
                        }
                        if let d = Int(filter.durationMax) { FilterOnChip(text: L("projects_chip_duration", d)) { filter.durationMax = "" } }
                    }
                }
                .padding(.top, 12)
            }
            .padding(.horizontal, 16)
            ScrollView {
                LazyVStack(spacing: 12) {
                    if loaded && rows.isEmpty && failed == nil { emptyView }
                    ForEach(Array(rows.enumerated()), id: \.element.id) { index, p in
                        ProjectCard(p: p, onOpen: { onOpenProject(p.uuid ?? "") }, onToggleSaved: { toggleSaved(p) })
                            .onAppear { nearEnd(index) }
                    }
                    if !loaded && failed == nil {
                        SkeletonList()
                    } else if loading && !refreshing {
                        ProgressView().tint(wa.red).frame(maxWidth: .infinity).padding(12)
                    }
                    if let failed {
                        Button { Task { await loadPage(page == 0 ? 1 : page + 1) } } label: {
                            Text(verbatim: failed + "  ·  " + L("support_ticket_more")).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                                .frame(maxWidth: .infinity, alignment: .leading).padding(12)
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(.horizontal, 16)
                .padding(.top, 12)
                .padding(.bottom, 16)
            }
            .scrollDismissesKeyboard(.interactively)
            // Pulling down asks once for the first page again (the freelancer's own gesture, not a timer).
            .refreshable {
                refreshing = true
                await loadPage(1)
                refreshing = false
            }
        }
        .task(id: network.reconnectTick) {
            if let mine = await ListsApi.mySpecialties().value { hasSpecialties = !mine.isEmpty }
        }
        // A new search / filter / reconnect starts again from the first page (a search waits 400 ms after the last key).
        .task(id: reloadKey) {
            // The first load waits until it is known whether I have specialties.
            guard hasSpecialties != nil else { return }
            if !query.isBlankText {
                try? await Task.sleep(nanoseconds: 400_000_000)
                if Task.isCancelled { return }
            }
            page = 0
            hasMore = true
            await loadPage(1)
        }
        .sheet(isPresented: $sheet) {
            FilterSheet(initial: filter, onApply: { filter = $0; sheet = false })
        }
    }

    /// Inside the search box: × to empty it (while it has text) and the filter button, filled while a filter is on.
    private var searchTrailing: some View {
        let on = filter.active > 0
        return HStack(spacing: 0) {
            if !query.isEmpty {
                Button { query = "" } label: {
                    Image(systemName: "xmark").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut)
                        .frame(width: 30, height: 30).contentShape(Circle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("common_close"))
            }
            Button { sheet = true } label: {
                Image(systemName: "slider.horizontal.3").font(.system(size: 15, weight: .semibold)).foregroundStyle(on ? .white : wa.red)
                    .frame(width: 34, height: 34)
                    .background(on ? wa.red : wa.red.opacity(0.10), in: RoundedRectangle(cornerRadius: 11, style: .continuous))
            }
            .buttonStyle(.plain)
            .padding(.leading, 2)
            .accessibilityLabel(L("projects_filter"))
        }
    }

    private var emptyView: some View {
        VStack(spacing: 0) {
            Image(systemName: savedOnly ? "star" : "magnifyingglass").font(.system(size: 36)).foregroundStyle(wa.settingsMut)
                .frame(width: 88, height: 88)
                .background(wa.night ? wa.darkWell : wa.red.opacity(0.08), in: Circle())
            Spacer().frame(height: 16)
            Text(L(savedOnly ? "projects_no_saved" : "projects_none")).font(.dorr(16, .semibold)).foregroundStyle(wa.settingsInk)
            Text(L(savedOnly ? "projects_no_saved_text" : "projects_none_text")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                .multilineTextAlignment(.center).padding(.top, 6)
            if !savedOnly && hasSpecialties == true && reach == "mine" {
                Button { reach = "all" } label: {
                    Text(L("projects_show_all")).font(.dorr(14, .bold)).foregroundStyle(wa.red).padding(10)
                }
                .buttonStyle(.plain)
                .padding(.top, 10)
            }
            if filter.active > 0 {
                Button { filter = ProjectFilter() } label: {
                    Text(L("projects_clear_filter", filter.active)).font(.dorr(14, .bold)).foregroundStyle(wa.red).padding(10)
                }
                .buttonStyle(.plain)
                .padding(.top, 4)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.top, 60)
    }

    private func nearEnd(_ index: Int) {
        if loaded && index >= rows.count - 3 && hasMore && !loading && failed == nil { Task { await loadPage(page + 1) } }
    }

    private func loadPage(_ next: Int) async {
        if next == 1 { generation += 1 } else if loading { return }
        let mine = generation
        loading = true
        failed = nil
        let q = query.trimmingCharacters(in: .whitespacesAndNewlines)
        let result = await ProjectsApi.list(
            page: next, perPage: PAGE_SIZE, q: q.isEmpty ? nil : q, specialtyId: filter.specialty?.id,
            budgetMinMinor: minor(filter.budgetMin), budgetMaxMinor: minor(filter.budgetMax),
            durationMaxDays: Int(filter.durationMax), favorites: savedOnly ? 1 : nil,
            scope: hasSpecialties == true ? reach : "all"
        )
        guard mine == generation else { return }
        if let data = result.value {
            if next == 1 { rows = [] }
            let seen = Set(rows.map(\.uuid))
            rows += data.filter { !seen.contains($0.uuid) }
            page = next
            hasMore = data.count >= PAGE_SIZE
        } else {
            failed = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
        loaded = true
        loading = false
    }

    private func toggleSaved(_ p: ProjectDto) {
        let was = p.isFavorite == true
        if let index = rows.firstIndex(where: { $0.uuid == p.uuid }) { rows[index].isFavorite = !was }
        Task {
            let uuid = p.uuid ?? ""
            let result = was ? await ProjectsApi.unfavorite(uuid) : await ProjectsApi.favorite(uuid)
            if result.value != nil {
                if savedOnly && was { rows.removeAll { $0.uuid == p.uuid } }
            } else {
                // The server did not take it: put the star back.
                if let at = rows.firstIndex(where: { $0.uuid == p.uuid }) { rows[at] = p }
                failed = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}

/// A filter pill with an icon: brand-filled when chosen (the chips of the projects, offers, invitations and work lists).
struct ProjectChip: View {
    @Environment(\.wa) private var wa
    let text: String
    let selected: Bool
    var icon: String?
    let action: () -> Void

    var body: some View {
        let ink: Color = selected ? .white : wa.settingsInk
        Button(action: action) {
            HStack(spacing: 6) {
                if let icon { Image(systemName: icon).font(.system(size: 12, weight: .semibold)).foregroundStyle(selected ? ink : wa.red) }
                Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(ink).lineLimit(1).fixedSize()
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 8)
            .background(selected ? wa.red : wa.settingsCard, in: Capsule())
            .overlay(Capsule().stroke(selected ? wa.red : wa.settingsMut.opacity(0.22), lineWidth: 1))
        }
        .buttonStyle(.plain)
    }
}

/// A filter that is on, with its × to take it off.
private struct FilterOnChip: View {
    @Environment(\.wa) private var wa
    let text: String
    let onRemove: () -> Void

    var body: some View {
        Button(action: onRemove) {
            HStack(spacing: 4) {
                Text(verbatim: text).font(.dorr(12.5, .bold)).foregroundStyle(wa.red).lineLimit(1).fixedSize()
                Image(systemName: "xmark").font(.system(size: 11, weight: .bold)).foregroundStyle(wa.red)
                    .frame(width: 15, height: 15)
                    .accessibilityLabel(L("projects_remove_filter"))
            }
            .padding(.leading, 12).padding(.trailing, 8).padding(.vertical, 8)
            .background(wa.red.opacity(0.12), in: Capsule())
        }
        .buttonStyle(.plain)
    }
}

/// A small fact of a project: an icon and a short text (budget, days, files).
private struct ProjectFact: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String
    var strong = false

    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: icon).font(.system(size: 12, weight: .semibold)).foregroundStyle(strong ? wa.red : wa.settingsMut).frame(width: 15, height: 15)
            Text(verbatim: text).font(.dorr(12.5, strong ? .heavy : .semibold)).foregroundStyle(strong ? wa.settingsInk : wa.settingsMut).lineLimit(1)
        }
    }
}

/// A small tinted pill (a budget, a state, a skill).
private struct ProjectPill: View {
    let text: String
    let color: Color
    var fill: Color?
    var size: CGFloat = 11.5
    var weight: Font.Weight = .bold
    var radius: CGFloat = 999
    var h: CGFloat = 10
    var v: CGFloat = 3

    var body: some View {
        Text(verbatim: text).font(.dorr(size, weight)).foregroundStyle(color)
            .padding(.horizontal, h).padding(.vertical, v)
            .background(fill ?? color.opacity(0.12), in: RoundedRectangle(cornerRadius: radius, style: .continuous))
    }
}

/// One project in the list: its specialty and age on top, the title and a short text, the budget / days / files, up to three skills, then
/// a footer with how open it is for offers, my offer or invitation, and the star.
private struct ProjectCard: View {
    @Environment(\.wa) private var wa
    let p: ProjectDto
    let onOpen: () -> Void
    let onToggleSaved: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            VStack(alignment: .leading, spacing: 8) {
                HStack(spacing: 0) {
                    if let name = p.specialty?.name {
                        Text(verbatim: name).font(.dorr(11, .bold)).foregroundStyle(wa.red).lineLimit(1)
                            .padding(.horizontal, 10).padding(.vertical, 3)
                            .background(wa.red.opacity(0.10), in: Capsule())
                    }
                    Spacer(minLength: 0)
                    // The client's country: the list mixes every open country.
                    CountryTag(country: p.country).padding(.trailing, 8)
                    if let ago = publishedAgo(p.publishedAt) { Text(verbatim: ago).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                }
                Text(verbatim: p.title ?? "").font(.dorr(15.5, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2).lineSpacing(3)
                if let summary = p.summary, !summary.isBlankText {
                    Text(verbatim: summary).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(3).lineLimit(2)
                }
                FlowLayout(spacing: 14) {
                    if let budget = budgetText(p.budget) { ProjectFact(icon: "banknote.fill", text: budget, strong: true) }
                    if let days = p.durationDays { ProjectFact(icon: "clock", text: L("projects_days", days)) }
                    if let files = p.attachmentsCount, files > 0 { ProjectFact(icon: "paperclip", text: "\(files)") }
                }
                let skills = p.skills ?? []
                if !skills.isEmpty {
                    FlowLayout(spacing: 6) {
                        ForEach(Array(skills.prefix(3).enumerated()), id: \.offset) { _, s in
                            ProjectPill(text: s.name ?? "", color: wa.settingsInk, fill: wa.settingsMut.opacity(0.12), size: 11, weight: .semibold, radius: 8, h: 8)
                        }
                        if skills.count > 3 {
                            Text(verbatim: "+\(skills.count - 3)").font(.dorr(11, .bold)).foregroundStyle(wa.settingsMut).padding(.horizontal, 4).padding(.vertical, 3)
                        }
                    }
                }
            }
            .padding(.horizontal, 16).padding(.top, 14).padding(.bottom, 12)
            // The footer: how open it is, my offer / invitation, and the star.
            HStack(spacing: 8) {
                if let line = offersLine(p) {
                    let tint = line.open ? green : amber
                    Circle().fill(tint).frame(width: 7, height: 7)
                    Text(verbatim: line.text).font(.dorr(11.5, .bold)).foregroundStyle(tint).lineLimit(1)
                }
                Spacer(minLength: 0)
                if p.offered == true {
                    ProjectPill(text: L("offer_sent_badge"), color: wa.red, size: 11)
                } else if p.invitation != nil {
                    ProjectPill(text: L("projects_invited_badge"), color: Color(hex: 0x3B82F6), size: 11)
                }
                Button(action: onToggleSaved) {
                    Image(systemName: p.isFavorite == true ? "star.fill" : "star").font(.system(size: 19))
                        .foregroundStyle(p.isFavorite == true ? starColor : wa.settingsMut)
                        .frame(width: 34, height: 34)
                        .contentShape(Circle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("projects_save"))
            }
            .padding(.leading, 16).padding(.trailing, 8).padding(.vertical, 6)
            .frame(maxWidth: .infinity)
            .background(wa.settingsMut.opacity(0.06))
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
        .settingsSurface(20)
        .contentShape(Rectangle())
        .onTapGesture(perform: onOpen)
    }
}

// MARK: - the filter sheet

private struct FilterSheet: View {
    @Environment(\.wa) private var wa
    let initial: ProjectFilter
    let onApply: (ProjectFilter) -> Void
    @State private var draft = ProjectFilter()
    @State private var tree: [SpecialtyNodeDto]?
    @State private var started = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                FormIntro(icon: "line.3.horizontal.decrease", title: L("projects_filter"), text: L("projects_filter_intro"))
                sectionTitle("square.grid.2x2.fill", L("projects_specialty"))
                if let nodes = tree {
                    ScrollView {
                        VStack(spacing: 2) {
                            SpecialtyOption(text: L("projects_any"), selected: draft.specialty == nil) { draft.specialty = nil }
                            ForEach(Array(nodes.enumerated()), id: \.offset) { _, parent in
                                SpecialtyOption(text: parent.name ?? "", selected: draft.specialty?.id == parent.id, bold: true) { draft.specialty = parent }
                                ForEach(Array((parent.children ?? []).enumerated()), id: \.offset) { _, c in
                                    SpecialtyOption(text: c.name ?? "", selected: draft.specialty?.id == c.id, indent: true) { draft.specialty = c }
                                }
                            }
                        }
                        .padding(6)
                    }
                    .frame(maxHeight: 260)
                    .fixedSize(horizontal: false, vertical: true)
                    .background(wa.red.opacity(0.05), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                } else {
                    SkeletonBlock(height: 120, radius: 16)
                }
                sectionTitle("banknote.fill", L("projects_budget"))
                HStack(spacing: 10) {
                    DorrTextField(value: $draft.budgetMin, label: L("projects_from"), placeholder: "0.00", keyboard: .decimalPad).frame(maxWidth: .infinity)
                    DorrTextField(value: $draft.budgetMax, label: L("projects_to"), placeholder: "0.00", keyboard: .decimalPad).frame(maxWidth: .infinity)
                }
                DorrTextField(
                    value: Binding(get: { draft.durationMax }, set: { draft.durationMax = String($0.filter(\.isWholeNumberDigit).prefix(3)) }),
                    label: L("projects_duration_max"), icon: "clock.fill", keyboard: .numberPad
                )
                ActionButton(L("projects_apply"), "checkmark", .primary) { onApply(draft) }
                ActionButton(L("projects_reset"), "arrow.clockwise", .soft) { onApply(ProjectFilter()) }
            }
            .padding(.horizontal, 20)
            .padding(.top, 24)
            .padding(.bottom, 24)
        }
        .background((wa.night ? wa.darkCard : Color.white).ignoresSafeArea())
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
        .onAppear {
            if !started { started = true; draft = initial }
        }
        .task { if let nodes = await ListsApi.specialtyTree().value { tree = nodes } }
    }

    private func sectionTitle(_ icon: String, _ text: String) -> some View {
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red)
            Text(verbatim: text).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
        }
    }
}

private struct SpecialtyOption: View {
    @Environment(\.wa) private var wa
    let text: String
    let selected: Bool
    var bold = false
    var indent = false
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 0) {
                Text(verbatim: text).font(.dorr(14, bold || selected ? .bold : .regular)).foregroundStyle(selected ? wa.red : wa.settingsInk)
                    .frame(maxWidth: .infinity, alignment: .leading)
                if selected { Image(systemName: "checkmark").font(.system(size: 14, weight: .bold)).foregroundStyle(wa.red) }
            }
            .padding(.leading, indent ? 22 : 10)
            .padding(.trailing, 10)
            .padding(.vertical, 9)
            .background(selected ? wa.red.opacity(0.12) : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - one project

/// A section of the project page: a card with a small icon title over its content.
private struct DetailSection<Content: View>: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 8) {
                Image(systemName: icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 28, height: 28)
                    .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 9, style: .continuous))
                Text(verbatim: title).font(.dorr(14.5, .heavy)).foregroundStyle(wa.settingsInk)
            }
            content()
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20)
    }
}

/// One of the three facts under the title: a value and what it is.
private struct DetailFact: View {
    @Environment(\.wa) private var wa
    let icon: String
    let value: String
    let label: String
    let tint: Color

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Image(systemName: icon).font(.system(size: 14, weight: .semibold)).foregroundStyle(tint).frame(height: 17)
            Text(verbatim: value).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
            Text(verbatim: label).font(.dorr(10.5, .semibold)).foregroundStyle(wa.settingsMut).lineLimit(1)
        }
        .padding(10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(tint.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// One project's page (FL-207): a head card (specialty, how open it is, the title, when it was posted, the star) with the budget, the
/// days and the offers deadline; then what the client wrote (a long text folds), the skills, the client's questions and the files; an
/// invitation waiting for my answer; and a bar fixed at the bottom with the main step — send an offer, or see mine (and its agreement).
@MainActor
struct ProjectDetailScreen: View {
    let uuid: String
    let onBack: () -> Void
    let onOffer: () -> Void
    let onOpenOffer: () -> Void
    let onAgreement: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var myOffer: OfferDto?
    @State private var offerChecked = false
    @State private var project: ProjectDto?
    @State private var error: String?
    @State private var expanded = false
    // FL-205: an invitation to a private project waits for my yes or no.
    @State private var invitation: InvitationRefDto?

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("projects_project"), onBack: onBack)
                ScrollView {
                    VStack(spacing: 12) {
                        if let p = project {
                            content(p)
                        } else if error == nil {
                            SkeletonDetail()
                        }
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.horizontal, 16)
                    .padding(.bottom, 20)
                }
                bottomBar
            }
        }
        .navigationBarHidden(true)
        .messageToast(error)
        .task(id: "\(uuid)|\(network.reconnectTick)") {
            let result = await ProjectsApi.show(uuid)
            if let data = result.value {
                project = data
                invitation = data.invitation
                error = nil
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
        // My offer on it (FL-301).
        .task(id: "\(uuid)|\(project != nil)|\(project?.offered == true)") {
            guard project != nil else { return }
            await loadMine()
            offerChecked = true
        }
        // A notice (the client saw, shortlisted, decided) reads it once more — nothing on a timer.
        .onReceive(Realtime.shared.changed) { _ in
            if project != nil { Task { await loadMine() } }
        }
    }

    private func loadMine() async {
        if let data = await OffersApi.mine(uuid).value { myOffer = data.offer }
    }

    /// The bar fixed at the bottom: the main step for this project.
    @ViewBuilder
    private var bottomBar: some View {
        if let p = project, myOffer != nil || (offerChecked && p.offers?.accepting != false) {
            VStack(spacing: 8) {
                if let sent = myOffer {
                    if let ag = sent.agreement {
                        let status = ag.status ?? "pending_approval"
                        OfferLinkRow(icon: "doc.text.fill", title: L("ag_title"), trailing: agreementStatusText(status),
                                     tint: status == "pending_approval" ? amber : wa.red, onClick: onAgreement)
                    }
                    SettingsPrimaryButton(text: L("offer_view_mine"), action: onOpenOffer)
                } else {
                    SettingsPrimaryButton(text: L("offer_send_one"), action: onOffer)
                }
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 12)
            .frame(maxWidth: .infinity)
            .background(wa.settingsCard.ignoresSafeArea(edges: .bottom))
            .overlay(alignment: .top) { Rectangle().fill(Color.black.opacity(0.06)).frame(height: 1) }
        }
    }

    @ViewBuilder
    private func content(_ p: ProjectDto) -> some View {
        let offersInfo = offersLine(p)
        let open = offersInfo?.open == true
        let stateTint = open ? green : amber
        let blue = Color(hex: 0x3B82F6)
        // The head card.
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 0) {
                if let name = p.specialty?.name {
                    Text(verbatim: name).font(.dorr(11.5, .bold)).foregroundStyle(wa.red).lineLimit(1)
                        .padding(.horizontal, 10).padding(.vertical, 4)
                        .background(wa.red.opacity(0.10), in: Capsule())
                }
                Spacer(minLength: 0)
                Button { toggleFavorite(p) } label: {
                    Image(systemName: p.isFavorite == true ? "star.fill" : "star").font(.system(size: 19))
                        .foregroundStyle(p.isFavorite == true ? starColor : wa.settingsMut)
                        .frame(width: 36, height: 36)
                        .background(wa.settingsMut.opacity(0.08), in: Circle())
                        .contentShape(Circle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("projects_save"))
            }
            Text(verbatim: p.title ?? "").font(.dorr(19, .black)).foregroundStyle(wa.settingsInk).lineSpacing(5)
            HStack(spacing: 8) {
                if let ago = publishedAgo(p.publishedAt) { Text(L("projects_posted", ago)).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                if let offersInfo {
                    HStack(spacing: 6) {
                        Circle().fill(stateTint).frame(width: 6, height: 6)
                        Text(verbatim: offersInfo.text).font(.dorr(11.5, .bold)).foregroundStyle(stateTint).lineLimit(1)
                    }
                    .padding(.horizontal, 10).padding(.vertical, 4)
                    .background(stateTint.opacity(0.12), in: Capsule())
                }
            }
            HStack(alignment: .top, spacing: 8) {
                DetailFact(icon: "banknote.fill", value: budgetText(p.budget) ?? "—", label: L("projects_budget"), tint: Color(hex: 0x10B981))
                DetailFact(icon: "clock", value: p.durationDays.map { L("projects_days", $0) } ?? "—", label: L("projects_duration"), tint: blue)
                DetailFact(icon: "paperclip", value: "\(p.attachmentsCount ?? 0)", label: L("projects_files_label"), tint: Color(hex: 0x8B5CF6))
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(22)

        // FL-205: an invitation to a private project waits for my yes or no.
        if let inv = invitation {
            VStack(alignment: .leading, spacing: 10) {
                HStack(spacing: 8) {
                    Image(systemName: "envelope.badge.fill").font(.system(size: 17)).foregroundStyle(blue).frame(width: 20, height: 20)
                    Text(L("inv_banner", invitationStatusLabel(inv.status ?? "sent"))).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                }
                if (inv.status ?? "sent") == "sent" {
                    InvitationAnswer(uuid: inv.uuid ?? "", onAnswered: { s in invitation?.status = s }, onError: { error = $0 })
                }
            }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(blue.opacity(0.08), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(blue.opacity(0.25), lineWidth: 1))
        }

        DetailSection(icon: "doc.text.fill", title: L("projects_description")) {
            let text = p.description ?? ""
            let long = text.count > 420
            Text(verbatim: text).font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(7)
                .lineLimit(long && !expanded ? 7 : nil)
                .frame(maxWidth: .infinity, alignment: .leading)
            if long {
                Button { expanded.toggle() } label: {
                    Text(L(expanded ? "projects_read_less" : "projects_read_more")).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                        .padding(.vertical, 4).padding(.horizontal, 2)
                }
                .buttonStyle(.plain)
            }
        }
        let skills = p.skills ?? []
        if !skills.isEmpty {
            DetailSection(icon: "sparkles", title: L("projects_skills")) {
                FlowLayout(spacing: 6) {
                    ForEach(Array(skills.enumerated()), id: \.offset) { _, s in
                        ProjectPill(text: s.name ?? "", color: wa.red, fill: wa.red.opacity(0.10), size: 12, weight: .semibold, h: 12, v: 5)
                    }
                }
            }
        }
        let questions = p.questions ?? []
        if !questions.isEmpty {
            DetailSection(icon: "questionmark.circle", title: L("offer_questions")) {
                Text(L("projects_questions_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                ForEach(Array(questions.enumerated()), id: \.offset) { i, q in
                    HStack(alignment: .top, spacing: 10) {
                        Text(verbatim: "\(i + 1)").font(.dorr(11, .heavy)).foregroundStyle(wa.red)
                            .frame(width: 22, height: 22)
                            .background(wa.red.opacity(0.12), in: Circle())
                        Text(verbatim: q).font(.dorr(13.5)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                }
            }
        }
        if let count = p.attachmentsCount, count > 0 {
            DetailSection(icon: "paperclip", title: L("projects_files_label")) {
                Text(L("projects_files_note", count)).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            }
        }
    }

    private func toggleFavorite(_ p: ProjectDto) {
        let before = p
        var flipped = p
        flipped.isFavorite = !(p.isFavorite == true)
        project = flipped
        Task {
            let result = before.isFavorite == true ? await ProjectsApi.unfavorite(uuid) : await ProjectsApi.favorite(uuid)
            if let data = result.value { project = data } else {
                project = before
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}
