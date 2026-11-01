import SwiftUI

// androidApp `calendar/CalendarSheets.kt` + `CalendarEditor.kt`.

/// "30 min", "1 h", "1 day" — a reminder offset.
func reminderLabel(_ minutes: Int, allDay: Bool = false) -> String {
    switch minutes {
    case 0: return L(allDay ? "cal_rem_morning" : "cal_rem_at_time")
    case ..<60: return L("cal_rem_minutes", minutes)
    case ..<1440: return L("cal_rem_hours", minutes / 60)
    case ..<10080: return Lp("cal_rem_days", minutes / 1440, minutes / 1440)
    default: return L("cal_rem_week")
    }
}

let reminderChoices = [0, 5, 10, 15, 30, 60, 120, 180, 1440, 2880, 10080]
let allDayChoices = [0, 1440, 2880, 10080]
let eventColors: [String?] = [nil, "#2563EB", "#16A34A", "#D97706", "#DB2777", "#7C3AED", "#0891B2"]

private func kindLabel(_ type: String) -> String {
    switch type {
    case "task": return L("cal_kind_task")
    case "reminder": return L("cal_kind_reminder")
    case "moment": return L("cal_kind_moment")
    case "personal": return L("cal_kind_personal")
    case "capsule": return L("cal_kind_capsule")
    default: return L("cal_kind_event")
    }
}

// MARK: - One item

@MainActor
struct ItemSheet: View {
    @Environment(\.wa) private var wa
    let item: CalItemDto
    let onDismiss: () -> Void
    let onOpenMoments: () -> Void
    let onEdit: (CalItemDto) -> Void
    @State private var confirmDelete = false
    @State private var deleting = false

    var body: some View {
        let color = typeColor(item, wa)
        var lines: [(String, String)] = []
        lines.append(("clock", [calDay(item.date).map(dayTitle),
                                item.allDay || item.startsAt == nil ? L("cal_all_day") : timeText(item.startsAt) + (item.endsAt.map { " – " + timeText($0) } ?? "")]
            .compactMap { $0 }.joined(separator: "  ·  ")))
        if let origin = item.originTime { lines.append(("globe", L("cal_origin_time", origin, zoneCity(item.timezone)))) }
        if let location = item.location { lines.append(("mappin.and.ellipse", location)) }
        if let note = item.note { lines.append(("text.alignleft", note)) }
        if let r = item.reminders, !r.isEmpty { lines.append(("alarm", r.map { reminderLabel($0, allDay: item.allDay) }.joined(separator: listSeparator()))) }
        return ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    Group {
                        if let emoji = item.emoji { Text(verbatim: emoji).font(.system(size: 20)) } else {
                            Image(systemName: typeIcon(item.type)).font(.system(size: 17, weight: .semibold)).foregroundStyle(color)
                        }
                    }
                    .frame(width: 40, height: 40)
                    .background(color.opacity(0.14), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    VStack(alignment: .leading, spacing: 4) {
                        Text(verbatim: item.title).font(.dorr(16, .heavy)).foregroundStyle(wa.ink).lineLimit(2)
                        Text(verbatim: kindLabel(item.type)).font(.dorr(11, .bold)).foregroundStyle(color)
                            .padding(.horizontal, 8).padding(.vertical, 3)
                            .background(color.opacity(0.12), in: RoundedRectangle(cornerRadius: 8, style: .continuous))
                    }
                    Spacer(minLength: 0)
                }
                VStack(spacing: 0) {
                    ForEach(Array(lines.enumerated()), id: \.offset) { i, line in
                        HStack(spacing: 10) {
                            Image(systemName: line.0).font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.ink)
                                .frame(width: 32, height: 32)
                                .background(wa.field, in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                            Text(verbatim: line.1).font(.dorr(13.5, .semibold)).foregroundStyle(wa.ink).lineSpacing(2)
                            Spacer(minLength: 0)
                        }
                        .padding(.horizontal, 4)
                        .padding(.vertical, 10)
                        if i != lines.count - 1 { wa.line.frame(height: 1).padding(.leading, 46) }
                    }
                    if item.overdue {
                        Text(L("cal_overdue")).font(.dorr(12, .bold)).foregroundStyle(wa.danger)
                            .frame(maxWidth: .infinity, alignment: .leading).padding(8)
                    }
                }
                .padding(.horizontal, 8)
                .padding(.vertical, 2)
                .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.line, lineWidth: 1))
                .padding(.top, 12)

                VStack(spacing: 8) {
                    switch item.type {
                    case "event":
                        SheetAction(text: L("cal_edit"), icon: "pencil") { onDismiss(); onEdit(item) }
                        SheetAction(text: L("cal_delete"), icon: "trash.fill", tone: .quiet) { confirmDelete = true }
                    case "task":
                        SheetAction(text: L(item.done ? "cal_task_undo" : "cal_task_done"), icon: "checkmark.circle.fill") {
                            Task {
                                _ = try? await AiTasksApi.updateTask(item.ref, ["done": .bool(!item.done)])
                                await CalendarStore.shared.changed()
                                onDismiss()
                            }
                        }
                    case "moment", "personal":
                        SheetAction(text: L("cal_open_moments"), icon: "calendar") { onDismiss(); onOpenMoments() }
                    default:
                        EmptyView()
                    }
                    if let c = item.conversationId {
                        SheetAction(text: L("cal_open_chat"), icon: "bubble.left.and.bubble.right.fill", tone: .ghost) {
                            onDismiss()
                            ChatPush.shared.open(.conversation(c))
                        }
                        .padding(.top, 8)
                    }
                }
                .padding(.top, 12)
            }
            .padding(.horizontal, 18)
            .padding(.top, 24)
            .padding(.bottom, 20)
        }
        .background(wa.surface.ignoresSafeArea())
        .presentationDetents([.medium, .large])
        .presentationDragIndicator(.visible)
        .dorrDialog(isPresented: Binding(get: { confirmDelete }, set: { if !$0 && !deleting { confirmDelete = false } })) {
            ConfirmDialog(icon: "trash.fill", title: L("cal_delete"), message: L("cal_delete_confirm", item.title), loading: deleting,
                          confirmLabel: L("cal_delete_yes"), onConfirm: delete, onDismiss: { if !deleting { confirmDelete = false } })
        }
    }

    private func delete() {
        deleting = true
        Task {
            do {
                _ = try await CalendarApi.delete(item.ref)
                AppToast.show(L("cal_deleted"))
                await CalendarStore.shared.changed()
                onDismiss()
            } catch {
                AppToast.show(error.apiFailure.message ?? L("cal_delete_failed"), long: true)
                deleting = false
                confirmDelete = false
            }
        }
    }
}

private enum SheetActionTone { case primary, quiet, ghost }

private struct SheetAction: View {
    @Environment(\.wa) private var wa
    let text: String
    var icon: String?
    var tone: SheetActionTone = .primary
    let action: () -> Void

    var body: some View {
        let fg: Color = tone == .primary ? .white : (tone == .quiet ? wa.danger : wa.ink)
        let shape = RoundedRectangle(cornerRadius: 14, style: .continuous)
        Button(action: action) {
            HStack(spacing: 8) {
                if let icon { Image(systemName: icon).font(.system(size: 15, weight: .semibold)) }
                Text(verbatim: text).font(.dorr(13.5, .bold))
            }
            .foregroundStyle(fg)
            .frame(maxWidth: .infinity, minHeight: 44)
            .background {
                switch tone {
                case .primary: shape.fill(wa.heroGradient)
                case .quiet: shape.fill(wa.field)
                case .ghost: shape.stroke(wa.line, lineWidth: 1)
                }
            }
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Settings

private let sourceLabels: [(String, String, String, String)] = [
    ("events", "calendar", "cal_src_events", "cal_src_events_sub"),
    ("moments", "party.popper.fill", "cal_src_moments", "cal_src_moments_sub"),
    ("personal", "birthday.cake.fill", "cal_src_personal", "cal_src_personal_sub"),
    ("tasks", "checkmark.circle.fill", "cal_src_tasks", "cal_src_tasks_sub"),
    ("reminders", "alarm.fill", "cal_src_reminders", "cal_src_reminders_sub"),
    ("discover", "mappin.and.ellipse", "cal_src_discover", "cal_src_discover_sub"),
    ("sports", "soccerball", "cal_src_sports", "cal_src_sports_sub"),
]

private let sectionLabels = ["events": "cal_sec_events", "tasks": "cal_sec_tasks", "reminders": "cal_sec_reminders", "moments": "cal_sec_moments"]

private enum CalSub { case menu, sources, reminders, today }

/// Calendar settings like the wallet's: a short menu, each entry its own page — what shows in the calendar,
/// reminders (with quiet hours), and the order of the Today page.
@MainActor
struct CalendarSettings: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var store = CalendarStore.shared
    let onBack: () -> Void
    @State private var prefs: CalPrefsDto?
    @State private var error: String?
    @State private var sub = CalSub.menu
    @State private var latest = 0
    /// Saves go out one at a time, in the order they were made.
    @State private var queue: Task<Void, Never>?

    var body: some View {
        let title = L(sub == .menu ? "cal_settings" : (sub == .sources ? "cal_sources" : (sub == .reminders ? "cal_menu_reminders" : "cal_today_order")))
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: title) { if sub == .menu { onBack() } else { sub = .menu } }
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) {
                        if let p = prefs {
                            WaError(message: error)
                            content(p)
                        } else {
                            ForEach(0..<4, id: \.self) { _ in WaSkeleton(cornerRadius: 16).frame(height: 48).padding(.bottom, 8) }
                        }
                    }
                    .padding(.horizontal, 14)
                    .padding(.top, 6)
                    .padding(.bottom, 16)
                }
            }
        }
        .task { prefs = try? await CalendarApi.preferences().data }
    }

    @ViewBuilder private func content(_ p: CalPrefsDto) -> some View {
        switch sub {
        case .menu:
            CalSettingsRow(icon: "calendar", title: L("cal_sources"), subtitle: L("cal_menu_sources_sub")) { sub = .sources }
            CalSettingsRow(icon: "alarm.fill", title: L("cal_menu_reminders"), subtitle: L("cal_menu_reminders_sub")) { sub = .reminders }
            CalSettingsRow(icon: "clock.fill", title: L("cal_today_order"), subtitle: L("cal_menu_today_sub")) { sub = .today }
        case .sources:
            Text(L("cal_sources_sub")).font(.dorr(12)).foregroundStyle(wa.mut).padding(.bottom, 6)
            SettingsToggleCard {
                ForEach(Array(sourceLabels.enumerated()), id: \.offset) { index, source in
                    SettingsToggleRow(icon: source.1, title: L(source.2), help: L(source.3), on: p.sources[source.0] != false,
                                      divider: index != sourceLabels.count - 1) { on in
                        change({ $0.sources[source.0] = on }, ["sources": .object([source.0: .bool(on)])])
                    }
                }
            }
            .waRise(0)
        case .reminders:
            // Waiting for quiet hours comes first: it decides when every reminder below actually arrives.
            SettingsToggleCard {
                SettingsToggleRow(icon: "moon.zzz.fill", title: L("cal_respect_quiet"), help: L("cal_respect_quiet_sub"), on: p.respectQuiet, divider: false) { on in
                    change({ $0.respectQuiet = on }, ["respect_quiet": .bool(on)])
                }
            }
            .waRise(0)
            SettingsToggleCard {
                ReminderSection(title: L("cal_default_reminders"), help: L("cal_default_reminders_sub"))
                ReminderRows(icon: "alarm.fill", choices: reminderChoices, selected: p.defaultReminders, allDay: false) { list in
                    change({ $0.defaultReminders = list }, ["default_reminders": .array(list.map { .int($0) })])
                }
            }
            .padding(.top, 14)
            .waRise(1)
            SettingsToggleCard {
                ReminderSection(title: L("cal_all_day_reminders"), help: L("cal_all_day_reminders_sub"))
                ReminderRows(icon: "calendar", choices: allDayChoices, selected: p.allDayReminders, allDay: true) { list in
                    change({ $0.allDayReminders = list }, ["all_day_reminders": .array(list.map { .int($0) })])
                }
            }
            .padding(.top, 14)
            .waRise(2)
        case .today:
            let order = sectionOrder(p.todaySections)
            let hidden = p.todaySections?.hidden ?? []
            Text(L("cal_today_order_hint")).font(.dorr(12)).lineSpacing(4).foregroundStyle(wa.mut).padding(.bottom, 8)
            WaCard(padding: 6) {
                ForEach(Array(order.enumerated()), id: \.element) { i, key in
                    let off = hidden.contains(key)
                    HStack(spacing: 0) {
                        Text(verbatim: formatNumber(i + 1)).font(.dorr(13, .bold)).foregroundStyle(wa.soft).frame(width: 22, alignment: .leading)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L(sectionLabels[key] ?? key)).font(.dorr(14.5, .bold)).foregroundStyle(off ? wa.soft : wa.ink)
                            Text(verbatim: off ? L("cal_section_hidden") : sectionToday(key, store.today)).font(.dorr(11.5)).foregroundStyle(off ? wa.red : wa.mut)
                        }
                        Spacer(minLength: 0)
                        SmallIcon(icon: "arrow.up", enabled: i > 0) { saveSections(moved(order, i, i - 1), hidden) }
                        SmallIcon(icon: "arrow.down", enabled: i < order.count - 1) { saveSections(moved(order, i, i + 1), hidden) }
                        SmallIcon(icon: off ? "eye.slash" : "eye", enabled: true) {
                            saveSections(order, off ? hidden.filter { $0 != key } : hidden + [key])
                        }
                    }
                    .padding(.horizontal, 8)
                    .padding(.vertical, 6)
                }
            }
            .waRise(0)
        }
    }

    private func moved(_ order: [String], _ from: Int, _ to: Int) -> [String] {
        var copy = order
        copy.insert(copy.remove(at: from), at: to)
        return copy
    }

    private func saveSections(_ order: [String], _ hidden: [String]) {
        change({ $0.todaySections = CalSectionsDto(order: order, hidden: hidden) },
               ["today_sections": .object(["order": .array(order.map { .string($0) }), "hidden": .array(hidden.map { .string($0) })])])
    }

    /// Shows the change at once, then saves it; quick taps add up (two taps on ↑ move a section two places).
    private func change(_ update: (inout CalPrefsDto) -> Void, _ body: [String: JSONValue]) {
        guard var current = prefs else { return }
        update(&current)
        prefs = current
        error = nil
        latest += 1
        let ticket = latest
        let previous = queue
        queue = Task {
            await previous?.value
            do {
                let saved = try await CalendarApi.savePreferences(body).data
                if ticket == latest, let saved { prefs = saved }
            } catch {
                self.error = error.apiFailure.message
                // Back to what is really saved.
                if let real = try? await CalendarApi.preferences().data { prefs = real }
            }
            if ticket == latest { await CalendarStore.shared.changed() }
        }
    }
}

private func formatNumber(_ n: Int) -> String {
    let f = NumberFormatter()
    f.locale = calLocale()
    return f.string(from: NSNumber(value: n)) ?? String(n)
}

/// "3 today", "Nothing today", or "Not shown" — what a kind holds on today's page.
private func sectionToday(_ key: String, _ today: CalTodayDto?) -> String {
    if !sectionSourcesOn(key, today?.sources) { return L("cal_section_source_off") }
    let count: Int
    switch key {
    case "events": count = today?.events.count ?? 0
    case "tasks": count = today?.tasks.count ?? 0
    case "reminders": count = today?.reminders.count ?? 0
    case "moments": count = today?.moments.count ?? 0
    default: count = 0
    }
    return count == 0 ? L("cal_section_empty") : Lp("cal_section_count", count, count)
}

private struct SmallIcon: View {
    @Environment(\.wa) private var wa
    let icon: String
    let enabled: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 16, weight: .semibold)).foregroundStyle(enabled ? wa.red : wa.line)
                .frame(width: 34, height: 34)
                .contentShape(Circle())
        }
        .buttonStyle(.plain)
        .allowsHitTesting(enabled)
    }
}

/// A card of toggle rows — the notification settings' card.
struct SettingsToggleCard<Content: View>: View {
    @ViewBuilder let content: () -> Content
    var body: some View {
        VStack(spacing: 0, content: content).padding(.horizontal, 8).padding(.vertical, 4).frame(maxWidth: .infinity).settingsSurface(18, elevation: 8)
    }
}

private struct ReminderSection: View {
    @Environment(\.wa) private var wa
    let title: String
    let help: String
    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(verbatim: title).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
            Text(verbatim: help).font(.dorr(11)).lineSpacing(2).foregroundStyle(wa.settingsMut)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 4)
        .padding(.top, 12)
        .padding(.bottom, 10)
    }
}

/// An icon, a title with one line of help, and the settings toggle; a thin line under it unless it is the last.
struct SettingsToggleRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let help: String?
    let on: Bool
    let divider: Bool
    let onChange: (Bool) -> Void

    var body: some View {
        HStack(spacing: 10) {
            PinkIcon(icon: icon)
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: title).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                if let help { Text(verbatim: help).font(.dorr(11)).lineSpacing(2).foregroundStyle(wa.settingsMut) }
            }
            Spacer(minLength: 8)
            RedToggle(on: on) { onChange(!on) }
        }
        .padding(.horizontal, 4)
        .padding(.vertical, 12)
        if divider { (wa.night ? wa.line : Color(hex: 0xF6E4E8)).frame(height: 1) }
    }
}

/// One entry of the settings menu — same row as Account (icon well, 13.5 / 11 type, 14 card).
struct CalSettingsRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let subtitle: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 10) {
                Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 34, height: 34)
                    .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: title).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: subtitle).font(.dorr(11)).foregroundStyle(wa.settingsInk.opacity(0.65))
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.forward").font(.system(size: 12, weight: .bold))
                    .foregroundStyle(wa.night ? Color(hex: 0x8B93A0) : wa.red.opacity(0.55))
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.night ? wa.line : .clear, lineWidth: 1))
            .shadow(color: wa.night ? .clear : wa.red.opacity(0.07), radius: 6, y: 2)
        }
        .buttonStyle(.plain)
        .padding(.vertical, 4)
    }
}

/// The reminder choices as a list: icon well, name, a tick when chosen (up to five).
private struct ReminderRows: View {
    @Environment(\.wa) private var wa
    let icon: String
    let choices: [Int]
    let selected: [Int]
    let allDay: Bool
    let onChange: ([Int]) -> Void

    var body: some View {
        VStack(spacing: 0) {
            ForEach(Array(choices.enumerated()), id: \.element) { index, m in
                let on = selected.contains(m)
                Button {
                    onChange(on ? selected.filter { $0 != m } : Array((selected + [m]).prefix(5)))
                } label: {
                    HStack(spacing: 10) {
                        PinkIcon(icon: icon)
                        Text(verbatim: reminderLabel(m, allDay: allDay)).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                        Spacer(minLength: 0)
                        if on { Image(systemName: "checkmark.circle.fill").font(.system(size: 20)).foregroundStyle(wa.ink) }
                    }
                    .padding(.horizontal, 4)
                    .padding(.vertical, 10)
                    .background(on ? (wa.night ? wa.line : Color(hex: 0xEDEEF3)) : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                if index != choices.count - 1 { (wa.night ? wa.line : Color(hex: 0xF6E4E8)).frame(height: 1) }
            }
        }
        .padding(.bottom, 4)
    }
}

// MARK: - Search

@MainActor
struct CalendarSearch: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let onOpenMoments: () -> Void
    let onEdit: (CalItemDto) -> Void
    @State private var query = ""
    @State private var results: [CalItemDto]?
    @State private var open: CalItemDto?
    @State private var loading = false
    @State private var failed = false
    @State private var off = false
    @State private var attempt = 0

    var body: some View {
        WaPage(title: L("cal_search"), onBack: onBack) {
            HStack(spacing: 8) {
                DorrTextArea(value: Binding(get: { query }, set: { query = String($0.prefix(100)) }), placeholder: L("cal_search_hint"),
                             icon: "magnifyingglass", singleLine: true)
                    .overlay(alignment: .trailing) {
                        if !query.isEmpty {
                            Button { query = "" } label: {
                                Image(systemName: "xmark").font(.system(size: 14, weight: .bold)).foregroundStyle(wa.mut).frame(width: 30, height: 30)
                            }
                            .buttonStyle(.plain)
                            .padding(.trailing, 8)
                            .accessibilityLabel(L("cal_clear"))
                        }
                    }
            }
            .waRise(0)
            Text(L("cal_search_sub")).font(.dorr(11.5)).foregroundStyle(wa.soft).padding(.top, 6).padding(.bottom, 10)
            if off {
                CalEmptyState(icon: "calendar", title: L("cal_off_title"), hint: L("cal_off_text"))
            } else if failed {
                CalEmptyState(icon: "exclamationmark.triangle.fill", title: L("cal_search_failed"), hint: L("cal_search_failed_sub")) {
                    WaButton(text: L("wa_retry"), style: .ghost) { attempt += 1 }.padding(.horizontal, 40)
                }
            } else if let r = results {
                if r.isEmpty {
                    CalEmptyState(icon: "magnifyingglass", title: L("cal_search_none"), hint: L("cal_search_none_sub"))
                } else {
                    ForEach(Array(r.enumerated()), id: \.element.id) { i, item in
                        ItemRow(item: item, showDate: true) { if item.type != "capsule" { open = item } }.padding(.bottom, 8).waRise(i)
                    }
                }
            } else if loading {
                ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 14).frame(height: 50).padding(.bottom, 8) }
            } else {
                CalEmptyState(icon: "magnifyingglass", title: L("cal_search_idle"), hint: L("cal_search_idle_sub"))
            }
        }
        .task(id: "\(query)|\(attempt)") {
            let q = query.trimmingCharacters(in: .whitespaces)
            guard q.count >= 2 else { results = nil; loading = false; failed = false; return }
            try? await Task.sleep(nanoseconds: 350_000_000)
            guard !Task.isCancelled else { return }
            loading = true
            failed = false
            do {
                let answer = try await CalendarApi.search(q, timezone: calZoneId()).data
                off = answer?.enabled == false
                results = answer?.items ?? []
            } catch {
                if error is CancellationError { return }
                failed = true
                results = nil
            }
            loading = false
        }
        .sheet(item: $open) { item in
            ItemSheet(item: item, onDismiss: { open = nil }, onOpenMoments: onOpenMoments, onEdit: onEdit).dorrThemed()
        }
    }
}

// MARK: - Editor

private let noteMax = 1000

/// A new appointment, or a change to one — a whole screen: a live summary, then the details, when, the reminders
/// and the colour, with the save button pinned at the bottom. Going back with something typed asks first.
@MainActor
struct EventEditorScreen: View {
    @Environment(\.wa) private var wa
    let existing: CalItemDto?
    let day: Date
    let onBack: () -> Void

    @State private var title = ""
    @State private var allDay = false
    @State private var date = calToday()
    @State private var start = Date()
    @State private var end: Date?
    @State private var location = ""
    @State private var note = ""
    @State private var color: String?
    @State private var reminders: [Int] = []
    @State private var remindersTouched = false
    @State private var picking: String?
    @State private var busy = false
    @State private var tried = false
    @State private var confirmLeave = false
    @State private var initial: (date: Date, start: Date, end: Date?)?
    @State private var remindersOpen = false

    private var dirty: Bool {
        guard let existing, let initial else {
            return !title.trimmingCharacters(in: .whitespaces).isEmpty || !location.trimmingCharacters(in: .whitespaces).isEmpty
                || !note.trimmingCharacters(in: .whitespaces).isEmpty || remindersTouched || allDay
        }
        return title != existing.title || allDay != existing.allDay || !calSameDay(date, initial.date)
            || (!allDay && (minutes(start) != minutes(initial.start) || end.map(minutes) != initial.end.map(minutes)))
            || location != (existing.location ?? "") || note != (existing.note ?? "") || color != existing.color
            || reminders.sorted() != (existing.reminders ?? []).sorted()
    }

    var body: some View {
        let accent = color.map { momentColor($0, wa.red) } ?? wa.red
        let titleMissing = tried && title.trimmingCharacters(in: .whitespaces).isEmpty
        let endsNextDay = !allDay && end.map { minutes($0) < minutes(start) } == true
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L(existing == nil ? "cal_add" : "cal_edit"), onBack: leave)
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) {
                        VStack(alignment: .leading, spacing: 4) {
                            Text(verbatim: title.trimmingCharacters(in: .whitespaces).isEmpty ? L("cal_summary_untitled") : title)
                                .font(.dorr(16, .heavy)).foregroundStyle(.white).lineLimit(2)
                            SummaryLine(icon: "clock", text: [dayTitle(date), allDay ? L("cal_all_day") : clockText(start) + (end.map { " – " + clockText($0) } ?? "")]
                                .joined(separator: "  ·  "))
                            if !location.trimmingCharacters(in: .whitespaces).isEmpty {
                                SummaryLine(icon: "mappin.and.ellipse", text: location.trimmingCharacters(in: .whitespaces))
                            }
                        }
                        .padding(.horizontal, 14)
                        .padding(.vertical, 12)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(LinearGradient(colors: [accent, accent.opacity(0.78)], startPoint: .topLeading, endPoint: .bottomTrailing),
                                    in: RoundedRectangle(cornerRadius: 14, style: .continuous))

                        EditorLabel(text: L("cal_sec_details"))
                        EditorCard {
                            DorrTextArea(value: Binding(get: { title }, set: { title = String($0.prefix(160)) }), label: L("cal_lbl_title"),
                                         placeholder: L("cal_title_hint"), icon: "pencil", error: titleMissing, singleLine: true)
                            if titleMissing { Text(L("cal_err_title")).font(.dorr(12, .bold)).foregroundStyle(wa.danger).padding(.top, 4) }
                            DorrTextArea(value: Binding(get: { location }, set: { location = String($0.prefix(200)) }), label: L("cal_lbl_location"),
                                         placeholder: L("cal_location_hint"), icon: "mappin.and.ellipse", singleLine: true)
                                .padding(.top, 8)
                            DorrTextArea(value: Binding(get: { note }, set: { note = String($0.prefix(noteMax)) }), label: L("cal_lbl_note"),
                                         placeholder: L("cal_note_hint"), icon: "text.alignleft", minHeight: 70)
                                .padding(.top, 8)
                            Text(verbatim: L("cal_chars", note.count, noteMax)).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                                .frame(maxWidth: .infinity, alignment: .trailing).padding(.top, 4)
                        }

                        EditorLabel(text: L("cal_sec_when"))
                        EditorCard {
                            HStack {
                                Text(L("cal_all_day")).font(.dorr(14.5, .bold)).foregroundStyle(wa.ink)
                                Spacer()
                                RedToggle(on: allDay) {
                                    allDay.toggle()
                                    if !remindersTouched { reminders = [] }
                                }
                            }
                            .padding(.horizontal, 10).padding(.vertical, 6)
                            PickerField(label: L("cal_date"), value: mediumDate(date), icon: "calendar") { picking = "date" }.padding(.top, 8)
                            if !allDay {
                                PickerField(label: L("cal_starts"), value: clockText(start), icon: "clock") { picking = "start" }.padding(.top, 8)
                                PickerField(label: L("cal_ends"), value: end.map(clockText) ?? "", placeholder: L("cal_no_end"), icon: "clock",
                                            onClear: end == nil ? nil : { end = nil }) { picking = "end" }
                                    .padding(.top, 8)
                                if endsNextDay { Text(L("cal_end_next_day")).font(.dorr(11)).foregroundStyle(wa.settingsMut).padding(.top, 6) }
                            }
                        }

                        EditorLabel(text: L("cal_reminders"))
                        EditorCard {
                            let choices = allDay ? allDayChoices : reminderChoices
                            let shown = reminders.sorted().filter { choices.contains($0) }
                            let value = !shown.isEmpty ? shown.map { reminderLabel($0, allDay: allDay) }.joined(separator: listSeparator())
                                : L(remindersTouched ? "cal_rem_none" : "cal_rem_default_value")
                            PickerField(label: nil, value: value, icon: "alarm", chevron: true) { remindersOpen = true }
                                .popover(isPresented: $remindersOpen, arrowEdge: .top) {
                                    ScrollView {
                                        VStack(spacing: 0) {
                                            ForEach(choices, id: \.self) { m in
                                                let on = reminders.contains(m)
                                                Button {
                                                    remindersTouched = true
                                                    if on { reminders.removeAll { $0 == m } } else { reminders.append(m) }
                                                } label: {
                                                    HStack {
                                                        Text(verbatim: reminderLabel(m, allDay: allDay)).font(.dorr(15, on ? .bold : .medium))
                                                            .foregroundStyle(on ? wa.red : wa.settingsInk)
                                                        Spacer(minLength: 8)
                                                        if on { Image(systemName: "checkmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.red) }
                                                    }
                                                    .padding(.horizontal, 16).padding(.vertical, 12)
                                                    .contentShape(Rectangle())
                                                    .opacity(on || reminders.count < 5 ? 1 : 0.4)
                                                }
                                                .buttonStyle(.plain)
                                                .disabled(!on && reminders.count >= 5)
                                            }
                                        }
                                    }
                                    .frame(minWidth: 240, maxHeight: 340)
                                    .background(wa.night ? wa.darkCard : Color.white)
                                    .compactPopover()
                                }
                            if !remindersTouched {
                                Text(L("cal_reminders_default")).font(.dorr(11)).foregroundStyle(wa.settingsMut).padding(.top, 6)
                            }
                            Text(L("cal_color")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 10).padding(.bottom, 8)
                            HStack(spacing: 8) {
                                ForEach(Array(eventColors.enumerated()), id: \.offset) { _, c in
                                    let swatch = c.map { momentColor($0, wa.red) } ?? wa.red
                                    let selected = color == c
                                    Button { color = c } label: {
                                        ZStack {
                                            Circle().fill(swatch)
                                            Circle().stroke(selected ? wa.settingsInk.opacity(0.45) : .clear, lineWidth: 2)
                                            if selected { Image(systemName: "checkmark").font(.system(size: 12, weight: .bold)).foregroundStyle(.white) }
                                        }
                                        .frame(width: 28, height: 28)
                                    }
                                    .buttonStyle(.plain)
                                }
                            }
                            .padding(.bottom, 2)
                        }
                    }
                    .padding(.horizontal, 14)
                    .padding(.top, 6)
                    .padding(.bottom, 12)
                }
                .scrollDismissesKeyboard(.interactively)
                WaButton(text: L("cal_save"), icon: "checkmark.circle.fill", enabled: !busy, loading: busy, action: save)
                    .padding(.horizontal, 14)
                    .padding(.bottom, 16)
            }
        }
        .onAppear(perform: setUp)
        .dorrDialog(isPresented: Binding(get: { picking == "date" }, set: { if !$0 { picking = nil } })) {
            CalDatePickerDialog(initial: date, onDismiss: { picking = nil }) { date = $0; picking = nil }
        }
        .dorrDialog(isPresented: Binding(get: { picking == "start" || picking == "end" }, set: { if !$0 { picking = nil } })) {
            CalTimePickerDialog(initial: picking == "end" ? (end ?? start.addingTimeInterval(3600)) : start, onDismiss: { picking = nil }) { t in
                if picking == "end" { end = t } else { start = t }
                picking = nil
            }
        }
        .dorrDialog(isPresented: $confirmLeave) {
            ConfirmDialog(icon: "exclamationmark.triangle.fill", title: L("cal_discard_title"), message: L("cal_discard_text"),
                          confirmLabel: L("cal_discard_yes"), onConfirm: { confirmLeave = false; onBack() }, onDismiss: { confirmLeave = false })
        }
    }

    private func minutes(_ d: Date) -> Int {
        let c = calCalendar.dateComponents([.hour, .minute], from: d)
        return (c.hour ?? 0) * 60 + (c.minute ?? 0)
    }

    private func setUp() {
        guard initial == nil else { return }
        let start0 = parseInstant(existing?.startsAt)
        let d = start0.flatMap { calCalendar.date(bySettingHour: 12, minute: 0, second: 0, of: $0) } ?? calDay(existing?.date) ?? day
        // A new one starts at the next whole hour.
        let next = calCalendar.date(byAdding: .hour, value: 1, to: Date()).flatMap { calCalendar.date(bySetting: .minute, value: 0, of: $0) } ?? Date()
        let s = start0 ?? next
        let e = parseInstant(existing?.endsAt)
        title = existing?.title ?? ""
        allDay = existing?.allDay ?? false
        date = d
        start = s
        end = e
        location = existing?.location ?? ""
        note = existing?.note ?? ""
        color = existing?.color
        reminders = existing?.reminders ?? []
        remindersTouched = existing != nil
        initial = (d, s, e)
    }

    private func leave() {
        if dirty && !busy { confirmLeave = true } else { onBack() }
    }

    /// The day [date] at the clock time of [time], in this phone's zone, as an ISO instant with its offset.
    private func instant(_ day: Date, _ time: Date) -> String {
        let t = calCalendar.dateComponents([.hour, .minute], from: time)
        let at = calCalendar.date(bySettingHour: t.hour ?? 0, minute: t.minute ?? 0, second: 0, of: day) ?? day
        let f = ISO8601DateFormatter()
        f.timeZone = .current
        f.formatOptions = [.withInternetDateTime]
        return f.string(from: at)
    }

    private func save() {
        tried = true
        guard !title.trimmingCharacters(in: .whitespaces).isEmpty, !busy else { return }
        busy = true
        var body: [String: JSONValue] = [
            "title": .string(title.trimmingCharacters(in: .whitespacesAndNewlines)),
            "all_day": .bool(allDay),
            "timezone": .string(calZoneId()),
            "location": .string(location.trimmingCharacters(in: .whitespacesAndNewlines)),
            "note": .string(note.trimmingCharacters(in: .whitespacesAndNewlines)),
            "color": color.map { .string($0) } ?? .null,
        ]
        if allDay {
            body["date"] = .string(calKey(date))
        } else {
            body["starts_at"] = .string(instant(date, start))
            if let end {
                body["ends_at"] = .string(instant(minutes(end) < minutes(start) ? calAddDays(date, 1) : date, end))
            }
        }
        if remindersTouched { body["reminders"] = .array(reminders.sorted().map { .int($0) }) }
        Task {
            do {
                let saved = existing == nil ? try await CalendarApi.create(body).data : try await CalendarApi.update(existing!.ref, body).data
                AppToast.show(L(saved?.duplicate == true ? "cal_duplicate" : (existing == nil ? "cal_saved" : "cal_updated")))
                await CalendarStore.shared.changed()
                onBack()
            } catch {
                AppToast.show(error.apiFailure.message ?? L("cal_save_failed"), long: true)
                busy = false
            }
        }
    }
}

private struct EditorLabel: View {
    @Environment(\.wa) private var wa
    let text: String
    var body: some View {
        Text(verbatim: text).font(.dorr(13.5, .heavy)).foregroundStyle(wa.settingsInk).padding(.top, 14).padding(.bottom, 8).padding(.leading, 2)
    }
}

private struct EditorCard<Content: View>: View {
    @Environment(\.wa) private var wa
    @ViewBuilder let content: () -> Content
    var body: some View {
        VStack(alignment: .leading, spacing: 0, content: content)
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.night ? wa.line : .clear, lineWidth: 1))
            .shadow(color: wa.night ? .clear : wa.red.opacity(0.07), radius: 6, y: 2)
    }
}

private struct SummaryLine: View {
    let icon: String
    let text: String
    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 12)).foregroundStyle(.white.opacity(0.9))
            Text(verbatim: text).font(.dorr(12, .semibold)).foregroundStyle(.white.opacity(0.92)).lineLimit(2)
        }
        .padding(.top, 2)
    }
}

/// A read-only field (the same pill as the others) that opens a picker; an optional ✕ clears it.
private struct PickerField: View {
    @Environment(\.wa) private var wa
    let label: String?
    let value: String
    var placeholder: String?
    let icon: String
    var chevron = false
    var onClear: (() -> Void)?
    let action: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            if let label { Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 8) }
            HStack(spacing: 8) {
                Button(action: action) {
                    HStack(spacing: 8) {
                        Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red.opacity(0.8))
                        if value.isEmpty, let placeholder {
                            Text(verbatim: placeholder).font(.dorr(15)).foregroundStyle(wa.night ? wa.mut : Color(hex: 0xB4BAC4))
                        } else {
                            Text(verbatim: value).font(.dorr(15)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        }
                        Spacer(minLength: 0)
                        if chevron { Image(systemName: "chevron.down").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.settingsMut) }
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                if let onClear {
                    Button(action: onClear) {
                        Image(systemName: "xmark").font(.system(size: 14, weight: .bold)).foregroundStyle(wa.settingsMut).frame(width: 30, height: 30)
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 14)
            .frame(height: 46)
            .background(wa.night ? wa.bg : Color(hex: 0xFBF7F8), in: Capsule())
            .overlay(Capsule().stroke(wa.night ? wa.line : Color(hex: 0xF3D5DB), lineWidth: 1))
        }
    }
}

// MARK: - Pickers in the app's language

/// A day picker in the app's language: month and weekday names and the digits follow it. Tap a day, then OK.
struct CalDatePickerDialog: View {
    @Environment(\.wa) private var wa
    let initial: Date
    let onDismiss: () -> Void
    let onPick: (Date) -> Void
    @State private var month: Date?
    @State private var chosen: Date?

    var body: some View {
        let cal = calCalendar
        let m = month ?? initial
        let monthStart = cal.date(from: cal.dateComponents([.year, .month], from: m)).flatMap { cal.date(bySettingHour: 12, minute: 0, second: 0, of: $0) } ?? m
        let length = cal.range(of: .day, in: .month, for: monthStart)?.count ?? 30
        let offset = (cal.component(.weekday, from: monthStart) - cal.firstWeekday + 7) % 7
        let rows = (offset + length + 6) / 7
        let pick = chosen ?? initial
        VStack(spacing: 0) {
            HStack {
                MonthArrow(icon: "chevron.backward") { month = cal.date(byAdding: .month, value: -1, to: m) }
                Text(verbatim: monthTitle(monthStart)).font(.dorr(16, .heavy)).foregroundStyle(wa.ink).frame(maxWidth: .infinity)
                MonthArrow(icon: "chevron.forward") { month = cal.date(byAdding: .month, value: 1, to: m) }
            }
            HStack(spacing: 0) {
                ForEach(0..<7, id: \.self) { i in
                    Text(verbatim: weekdayShort((cal.firstWeekday - 1 + i) % 7 + 1)).font(.dorr(11.5, .bold)).foregroundStyle(wa.mut).frame(maxWidth: .infinity)
                }
            }
            .padding(.top, 8)
            .padding(.bottom, 4)
            ForEach(0..<rows, id: \.self) { row in
                HStack(spacing: 0) {
                    ForEach(0..<7, id: \.self) { col in
                        let n = row * 7 + col - offset
                        ZStack {
                            if n >= 0 && n < length {
                                let d = calAddDays(monthStart, n)
                                let selected = calSameDay(d, pick)
                                Button { chosen = d } label: {
                                    Text(verbatim: dayNumber(d)).font(.dorr(14, selected ? .heavy : .medium)).foregroundStyle(selected ? .white : wa.ink)
                                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                                        .background(selected ? wa.red : .clear, in: Circle())
                                        .overlay { if calSameDay(d, Date()) && !selected { Circle().stroke(wa.red, lineWidth: 1.5) } }
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .frame(maxWidth: .infinity)
                        .aspectRatio(1, contentMode: .fit)
                        .padding(2)
                    }
                }
            }
            PickerActions(onDismiss: onDismiss) { onPick(pick) }
        }
        .padding(16)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 28, style: .continuous))
        .padding(.horizontal, 24)
        .contentShape(Rectangle())
        .onTapGesture {}
    }
}

private struct PickerActions: View {
    let onDismiss: () -> Void
    let onOk: () -> Void
    var body: some View {
        HStack(spacing: 8) {
            WaButton(text: L("cal_cancel"), style: .ghost, compact: true, action: onDismiss)
            WaButton(text: L("cal_ok"), compact: true, action: onOk)
        }
        .padding(.top, 6)
    }
}

private struct MonthArrow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let action: () -> Void
    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 16, weight: .bold)).foregroundStyle(wa.red)
                .frame(width: 36, height: 36)
                .background(wa.field, in: Circle())
        }
        .buttonStyle(.plain)
    }
}

/// The time in the app's language: the hour and minute on top, the iOS wheels under them (Android shows its
/// analog dial here).
struct CalTimePickerDialog: View {
    @Environment(\.wa) private var wa
    let initial: Date
    let onDismiss: () -> Void
    let onPick: (Date) -> Void
    @State private var value: Date?

    var body: some View {
        let current = value ?? initial
        let c = calCalendar.dateComponents([.hour, .minute], from: current)
        VStack(spacing: 0) {
            HStack(spacing: 8) {
                TimeChip(text: twoDigits(c.hour ?? 0))
                Text(verbatim: ":").font(.dorr(26, .heavy)).foregroundStyle(wa.ink)
                TimeChip(text: twoDigits(c.minute ?? 0))
            }
            .environment(\.layoutDirection, .leftToRight)
            DatePicker("", selection: Binding(get: { current }, set: { value = $0 }), displayedComponents: .hourAndMinute)
                .datePickerStyle(.wheel)
                .labelsHidden()
                .environment(\.locale, calLocale())
                .tint(wa.red)
            PickerActions(onDismiss: onDismiss) { onPick(current) }
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 14)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 28, style: .continuous))
        .padding(.horizontal, 24)
        .contentShape(Rectangle())
        .onTapGesture {}
    }

    private func twoDigits(_ n: Int) -> String {
        let f = NumberFormatter()
        f.locale = calLocale()
        f.minimumIntegerDigits = 2
        return f.string(from: NSNumber(value: n)) ?? String(format: "%02d", n)
    }
}

private struct TimeChip: View {
    @Environment(\.wa) private var wa
    let text: String
    var body: some View {
        Text(verbatim: text).font(.dorr(28, .heavy)).foregroundStyle(wa.red)
            .frame(width: 88, height: 56)
            .background(wa.red.opacity(0.16), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.red.opacity(0.75), lineWidth: 1))
    }
}
