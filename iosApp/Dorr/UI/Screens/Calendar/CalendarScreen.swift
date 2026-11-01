import SwiftUI
import UIKit

// androidApp `calendar/CalendarScreen.kt`.
// DORR Calendar & DORR Today: the home page's "my day" card and the calendar — Today (in my order), the week and
// the month — from only the sources I keep on. Times are shown where I am now; an appointment set elsewhere also
// shows its own time there.

// MARK: - Days, times and names in the app's language

func calZoneId() -> String { TimeZone.current.identifier }

/// The app's language, its own digits too (java.time with `DecimalStyle.of(locale)`).
func calLocale() -> Locale { AppLocale.locale }

var calCalendar: Calendar {
    var cal = Calendar(identifier: .gregorian)
    cal.locale = calLocale()
    cal.timeZone = .current
    return cal
}

/// "2026-10-28" ↔ that day (at noon, so time zone shifts never move it).
func calDay(_ iso: String?) -> Date? {
    guard let iso else { return nil }
    let parts = iso.prefix(10).split(separator: "-").compactMap { Int($0) }
    guard parts.count == 3 else { return nil }
    return calCalendar.date(from: DateComponents(year: parts[0], month: parts[1], day: parts[2], hour: 12))
}

func calKey(_ day: Date) -> String {
    let c = calCalendar.dateComponents([.year, .month, .day], from: day)
    return String(format: "%04d-%02d-%02d", c.year ?? 0, c.month ?? 0, c.day ?? 0)
}

func calToday() -> Date { calCalendar.date(bySettingHour: 12, minute: 0, second: 0, of: Date()) ?? Date() }

func calSameDay(_ a: Date, _ b: Date) -> Bool { calCalendar.isDate(a, inSameDayAs: b) }

func calAddDays(_ day: Date, _ n: Int) -> Date { calCalendar.date(byAdding: .day, value: n, to: day) ?? day }

private func formatter(_ pattern: String? = nil, date: DateFormatter.Style = .none, time: DateFormatter.Style = .none) -> DateFormatter {
    let f = DateFormatter()
    f.locale = calLocale()
    f.calendar = calCalendar
    f.timeZone = .current
    if let pattern { f.dateFormat = pattern } else { f.dateStyle = date; f.timeStyle = time }
    return f
}

func timeText(_ iso: String?) -> String {
    guard let date = parseInstant(iso) else { return "" }
    return formatter(time: .short).string(from: date)
}

func clockText(_ date: Date) -> String { formatter(time: .short).string(from: date) }

func dayTitle(_ day: Date) -> String { formatter(AppLocale.isArabic ? "EEEE، d MMMM" : "EEEE, d MMMM").string(from: day) }

func shortDay(_ day: Date) -> String { formatter("d MMM").string(from: day) }

func dayNumber(_ day: Date) -> String { formatter("d").string(from: day) }

func monthTitle(_ day: Date) -> String { formatter("MMMM yyyy").string(from: day) }

func mediumDate(_ day: Date) -> String { formatter(date: .medium).string(from: day) }

/// One letter for a weekday (the short name without "ال" when the language has no one-letter form).
func weekdayLetter(_ day: Date) -> String {
    let f = formatter()
    let index = calCalendar.component(.weekday, from: day) - 1
    let narrow = f.veryShortStandaloneWeekdaySymbols[index]
    if narrow.count == 1 { return narrow }
    var short = f.shortStandaloneWeekdaySymbols[index]
    if short.hasPrefix("ال") { short.removeFirst(2) }
    return String(short.trimmingCharacters(in: .whitespaces).prefix(1))
}

func weekdayShort(_ weekday: Int) -> String { formatter().shortStandaloneWeekdaySymbols[(weekday - 1) % 7] }

/// The comma between items of a list, in the app's language ("، " in Arabic).
func listSeparator() -> String { AppLocale.isArabic ? "، " : ", " }

// MARK: - Kinds

/// The colour of each kind of item — the same on Home, the lists, the grid's dots.
func typeColor(_ item: CalItemDto, _ wa: WaPalette) -> Color {
    switch item.type {
    case "event": return momentColor(item.color, wa.red)
    case "task": return Color(hex: 0x16A34A)
    case "reminder": return Color(hex: 0xD97706)
    case "moment", "personal": return momentColor(item.color, Color(hex: 0xDB2777))
    case "discover": return Color(hex: 0x7C3AED)
    case "sports": return Color(hex: 0x0E9F6E)
    default: return wa.mut
    }
}

func typeIcon(_ type: String) -> String {
    switch type {
    case "task": return "checkmark.circle.fill"
    case "reminder": return "alarm.fill"
    case "capsule": return "archivebox.fill"
    case "discover": return "mappin.and.ellipse"
    case "sports": return "soccerball"
    default: return "calendar"
    }
}

/// The kinds Today's list is grouped by, in their default order (reorderable / hideable in Settings).
let defaultSections = ["events", "tasks", "reminders", "moments"]

/// Which of those kinds an item is: appointments, Discover events and matches are "events"; own dates are "moments".
func sectionOf(_ item: CalItemDto) -> String {
    switch item.type {
    case "task": return "tasks"
    case "reminder": return "reminders"
    case "moment", "personal": return "moments"
    default: return "events"
    }
}

/// A kind shows only while one of its sources is on.
func sectionSourcesOn(_ section: String, _ sources: [String]?) -> Bool {
    guard let sources else { return true }
    let needs: [String]
    switch section {
    case "events": needs = ["events", "discover", "sports"]
    case "tasks": needs = ["tasks"]
    case "reminders": needs = ["reminders"]
    case "moments": needs = ["moments", "personal"]
    default: return true
    }
    return needs.contains { sources.contains($0) }
}

func sectionOrder(_ sections: CalSectionsDto?) -> [String] {
    let saved = (sections?.order ?? []).filter { defaultSections.contains($0) }
    return saved + defaultSections.filter { !saved.contains($0) }
}

/// Today's page, shared by the home card and the calendar (one load, kept fresh).
@MainActor
final class CalendarStore: ObservableObject {
    static let shared = CalendarStore()
    @Published var today: CalTodayDto?
    @Published private(set) var version = 0

    func refresh() async {
        if let fresh = try? await CalendarApi.today(timezone: calZoneId()).data { today = fresh }
    }

    /// Something changed (added, edited, ticked): every view reloads.
    func changed() async {
        version += 1
        await refresh()
    }
}

// MARK: - Home: my day

/// DORR Today on the home page — icon, two lines, accent pill. Shows "free" when nothing is on.
@MainActor
struct TodayCard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var store = CalendarStore.shared
    let onOpen: () -> Void

    var body: some View {
        let today = store.today
        let hidden = today?.sections?.hidden ?? []
        let count: Int = {
            guard let today, today.enabled else { return 0 }
            return (hidden.contains("events") ? 0 : today.events.count)
                + (hidden.contains("tasks") ? 0 : today.tasks.filter { !$0.done }.count)
                + (hidden.contains("reminders") ? 0 : today.reminders.count)
        }()
        let mut = wa.night ? wa.mut : AppColors.textSecondary
        let nextLine: String? = {
            guard let day = today, day.enabled else { return nil }
            if !hidden.contains("events"), let next = day.next {
                return [timeText(next.startsAt), next.title].filter { !$0.isEmpty }.joined(separator: "  ·  ")
            }
            if !hidden.contains("moments"), let m = day.moments.first { return "\(m.emoji ?? "✨")  \(m.title)" }
            return nil
        }()
        Button(action: onOpen) {
            HStack(spacing: 12) {
                Image(systemName: "calendar").font(.system(size: 19, weight: .semibold)).foregroundStyle(wa.accent)
                    .frame(width: 42, height: 42)
                    .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 15, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    if count == 0 {
                        Text(L("cal_home_free")).font(.dorr(12, .semibold)).foregroundStyle(mut)
                        Text(verbatim: dayTitle(calDay(today?.date) ?? calToday())).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    } else {
                        Text(L("cal_home_label")).font(.dorr(12, .semibold)).foregroundStyle(mut)
                        HStack(alignment: .lastTextBaseline, spacing: 4) {
                            Text(verbatim: localNumber(count)).font(.dorr(20, .heavy)).foregroundStyle(wa.settingsInk)
                            Text(L("cal_home_things")).font(.dorr(12, .bold)).foregroundStyle(mut)
                        }
                    }
                    // The next thing today, when there is one.
                    if let nextLine {
                        Text(verbatim: nextLine).font(.dorr(12, .semibold)).foregroundStyle(mut).lineLimit(1)
                    }
                }
                Spacer(minLength: 0)
                Text(L("cal_home_open")).font(.dorr(12, .heavy)).foregroundStyle(.white)
                    .padding(.horizontal, 14).padding(.vertical, 8)
                    .background(wa.red, in: Capsule())
                    .shadow(color: wa.red.opacity(0.25), radius: 8, y: 3)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 14)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(wa.night ? wa.line : .clear, lineWidth: 1))
            .shadow(color: wa.night ? .clear : wa.red.opacity(0.08), radius: 10, y: 4)
        }
        .buttonStyle(WaPressStyle(scale: 0.98))
        .padding(.vertical, 12)
        .task(id: network.reconnectTick) { await store.refresh() }
    }
}

private func localNumber(_ value: Int) -> String {
    let f = NumberFormatter()
    f.locale = calLocale()
    return f.string(from: NSNumber(value: value)) ?? String(value)
}

// MARK: - The screen

private enum CalPage: Equatable {
    case main, settings, search
    /// The appointment form: a new one on [day], or [existing] changed.
    case editor(CalItemDto?, Date)
}

enum CalTab { case today, week, month }

@MainActor
struct CalendarScreen: View {
    @Environment(\.wa) private var wa
    let onExit: () -> Void
    var onOpenMoments: () -> Void = {}
    @State private var page = CalPage.main
    // Kept here: coming back from settings or search lands on the same tab and day.
    @State private var tab = CalTab.today
    @State private var selected = calToday()
    @State private var filter = "all"
    @State private var forward = true

    var body: some View {
        ZStack {
            current
                .id(pageKey)
                .transition(.asymmetric(
                    insertion: .modifier(active: CalSlide(fraction: forward ? 1.0 / 6 : -1.0 / 6), identity: CalSlide(fraction: 0)),
                    removal: .modifier(active: CalSlide(fraction: forward ? -0.1 : 0.1), identity: CalSlide(fraction: 0))
                ))
        }
        .background(wa.bg.ignoresSafeArea())
    }

    private var pageKey: String {
        switch page {
        case .main: return "main"
        case .settings: return "settings"
        case .search: return "search"
        case .editor(let item, _): return "editor-\(item?.id ?? "new")"
        }
    }

    @ViewBuilder private var current: some View {
        switch page {
        case .main:
            CalendarMain(tab: $tab, selected: $selected, filter: $filter, onBack: onExit, onSettings: { go(.settings) }, onSearch: { go(.search) },
                         onOpenMoments: onOpenMoments, onAdd: { go(.editor(nil, $0)) }, onEdit: { go(.editor($0, calDay($0.date) ?? calToday())) })
        case .settings: CalendarSettings(onBack: { go(.main) })
        case .search: CalendarSearch(onBack: { go(.main) }, onOpenMoments: onOpenMoments, onEdit: { go(.editor($0, calDay($0.date) ?? calToday())) })
        case .editor(let item, let day): EventEditorScreen(existing: item, day: day, onBack: { go(.main) })
        }
    }

    private func go(_ next: CalPage) {
        forward = next != .main
        withAnimation(.easeOut(duration: 0.3)) { page = next }
    }
}

private struct CalSlide: ViewModifier {
    let fraction: CGFloat
    func body(content: Content) -> some View {
        GeometryReader { proxy in content.offset(x: proxy.size.width * fraction).opacity(fraction == 0 ? 1 : 0) }
    }
}

@MainActor
private struct CalendarMain: View {
    @Environment(\.wa) private var wa
    @Binding var tab: CalTab
    @Binding var selected: Date
    @Binding var filter: String
    let onBack: () -> Void
    let onSettings: () -> Void
    let onSearch: () -> Void
    let onOpenMoments: () -> Void
    let onAdd: (Date) -> Void
    let onEdit: (CalItemDto) -> Void
    @State private var open: CalItemDto?

    var body: some View {
        WaPage(title: L("cal_title"), onBack: onBack, showHeader: false, hasCta: true, actions: { EmptyView() }, cta: {
            WaButton(text: L("cal_add"), icon: "plus") { onAdd(tab == .today ? calToday() : selected) }
        }) {
            switch tab {
            case .today:
                DarkHeroPanel {
                    titleBar
                    DayHeader(day: calToday(), onDark: true).padding(.top, 10)
                }
                Spacer().frame(height: 12)
                TodayTab(filter: $filter, onOpen: open(_:))
            case .week:
                RangeTab(month: false, selected: $selected, filter: $filter, onOpen: open(_:)) { titleBar }
            case .month:
                RangeTab(month: true, selected: $selected, filter: $filter, onOpen: open(_:)) { titleBar }
            }
        }
        .sheet(item: $open) { item in
            ItemSheet(item: item, onDismiss: { open = nil }, onOpenMoments: onOpenMoments, onEdit: onEdit).dorrThemed()
        }
    }

    private func open(_ item: CalItemDto) {
        // A Discover event or a match opens its own page.
        if item.type == "discover" { EventsLink.shared.show(item.ref); return }
        if item.type == "sports" { SportsLink.shared.show(item.ref); return }
        open = item
    }

    private var titleBar: some View {
        VStack(spacing: 10) {
            HStack {
                Text(L("cal_title")).font(.dorr(22, .black)).foregroundStyle(.white).lineLimit(2)
                Spacer(minLength: 8)
                HStack(spacing: 8) {
                    DarkHeroButton(icon: "magnifyingglass", label: L("cal_search"), action: onSearch)
                    DarkHeroButton(icon: "gearshape.fill", label: L("cal_settings"), action: onSettings)
                    DarkHeroButton(icon: "arrow.forward", label: L("common_back"), action: onBack)
                }
            }
            CalTabs(tab: tab, onDark: true) { tab = $0 }
        }
    }
}

private struct CalTabs: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var appearance: AppearanceState
    let tab: CalTab
    var onDark = false
    let onPick: (CalTab) -> Void

    var body: some View {
        let night = wa.night
        let primary = appearance.color("primary", Color(hex: 0x001B53), dark: night)
        let primaryDark = appearance.color("primaryDark", night ? Color(hex: 0x101216) : Color(hex: 0x00113A), dark: night)
        let accent = appearance.color(night ? "primary" : "accent", Color(hex: 0xFA7552), dark: night)
        HStack(spacing: 0) {
            Image(systemName: "calendar").font(.system(size: 13, weight: .semibold)).foregroundStyle(onDark ? .white : wa.ink)
                .frame(width: 30, height: 30)
                .background(onDark ? Color.white.opacity(0.12) : wa.surface, in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            ForEach([(CalTab.today, "cal_today"), (.week, "cal_week"), (.month, "cal_month")], id: \.1) { t, label in
                let on = t == tab
                Button { onPick(t) } label: {
                    ZStack(alignment: .top) {
                        Capsule().fill(on ? (onDark ? primaryDark : primary) : Color.clear)
                        if on {
                            RoundedRectangle(cornerRadius: 1).fill(accent).frame(width: 12, height: 2).padding(.top, 3)
                        }
                        Text(L(label)).font(.dorr(11, on ? .bold : .medium))
                            .foregroundStyle(on ? .white : (onDark ? Color.white.opacity(0.55) : wa.mut)).lineLimit(1)
                            .frame(maxHeight: .infinity)
                    }
                    .contentShape(Capsule())
                }
                .buttonStyle(.plain)
                .padding(.leading, 3)
            }
        }
        .padding(3)
        .frame(height: 36)
        .background(onDark ? Color.white.opacity(0.10) : wa.field, in: Capsule())
    }
}

// MARK: - Today

@MainActor
private struct TodayTab: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var store = CalendarStore.shared
    @Binding var filter: String
    let onOpen: (CalItemDto) -> Void

    var body: some View {
        Group {
            if let loaded = store.today {
                if !loaded.enabled {
                    WaCard { WaEmpty(icon: "calendar", tone: .gray, title: L("cal_off_title"), text: L("cal_off_text")) }
                } else {
                    // Everything on today in one list, grouped in the order set in Settings; all-day first, then by time.
                    let order = sectionOrder(loaded.sections)
                    let hidden = loaded.sections?.hidden ?? []
                    var seen = Set<String>()
                    let items = (loaded.events + loaded.tasks + loaded.reminders + loaded.moments)
                        .filter { seen.insert($0.id).inserted }
                        .filter { !hidden.contains(sectionOf($0)) && sectionSourcesOn(sectionOf($0), loaded.sources) && matchesFilter($0, filter) }
                        .sorted { a, b in
                            let oa = order.firstIndex(of: sectionOf(a)) ?? 0, ob = order.firstIndex(of: sectionOf(b)) ?? 0
                            if oa != ob { return oa < ob }
                            let da = a.allDay || a.startsAt == nil, db = b.allDay || b.startsAt == nil
                            if da != db { return da }
                            return (a.startsAt ?? "") < (b.startsAt ?? "")
                        }
                    VStack(alignment: .leading, spacing: 0) {
                        FilterChips(filter: $filter, honourHidden: true)
                        Spacer().frame(height: 10)
                        if items.isEmpty {
                            DayEmpty(filtered: filter != "all")
                        } else {
                            ForEach(Array(items.enumerated()), id: \.element.id) { i, item in
                                ItemRow(item: item, onToggle: item.type == "task" ? { toggle(item) } : nil) { onOpen(item) }
                                    .padding(.bottom, 8)
                                    .waRise(i)
                            }
                        }
                    }
                }
            } else {
                VStack(spacing: 8) {
                    ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 14).frame(height: 50) }
                }
            }
        }
        .task(id: store.version) { await store.refresh() }
    }

    private func toggle(_ item: CalItemDto) {
        Task {
            _ = try? await AiTasksApi.updateTask(item.ref, ["done": .bool(!item.done)])
            await store.changed()
        }
    }
}

/// An empty page: a soft icon, a line and a hint, in the middle of the space left (optionally with an action).
struct CalEmptyState<Action: View>: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let hint: String
    @ViewBuilder var action: () -> Action

    var body: some View {
        VStack(spacing: 0) {
            Image(systemName: icon).font(.system(size: 38, weight: .semibold)).foregroundStyle(wa.red)
                .frame(width: 92, height: 92)
                .background(wa.red.opacity(0.12), in: Circle())
                .frame(width: 132, height: 132)
                .background(wa.red.opacity(0.06), in: Circle())
            Text(verbatim: title).font(.dorr(17, .heavy)).foregroundStyle(wa.ink).multilineTextAlignment(.center).padding(.top, 18)
            Text(verbatim: hint).font(.dorr(13)).lineSpacing(5).foregroundStyle(wa.mut).multilineTextAlignment(.center).padding(.top, 6)
            action().padding(.top, 16)
        }
        .padding(.horizontal, 24)
        .frame(maxWidth: .infinity, minHeight: 300)
    }
}

extension CalEmptyState where Action == EmptyView {
    init(icon: String, title: String, hint: String) {
        self.init(icon: icon, title: title, hint: hint, action: { EmptyView() })
    }
}

/// A day with nothing on it.
struct DayEmpty: View {
    let filtered: Bool
    var body: some View {
        CalEmptyState(icon: "calendar.badge.checkmark", title: L("cal_day_empty"), hint: L(filtered ? "cal_day_empty_filtered" : "cal_day_empty_sub"))
    }
}

/// The day's heading: "Tuesday, 15 October" — with a "Today" badge when it is today.
struct DayHeader: View {
    @Environment(\.wa) private var wa
    let day: Date
    var topPadding: CGFloat = 0
    var onDark = false

    var body: some View {
        HStack {
            Text(verbatim: dayTitle(day)).font(.dorr(onDark ? 15 : 20, .heavy)).foregroundStyle(onDark ? .white : wa.ink)
            Spacer(minLength: 8)
            if calSameDay(day, Date()) {
                Text(L("cal_today")).font(.dorr(12, .bold)).foregroundStyle(onDark ? .white : wa.red)
                    .padding(.horizontal, 12).padding(.vertical, 5)
                    .background(onDark ? Color.white.opacity(0.14) : wa.red.opacity(0.12), in: Capsule())
            }
        }
        .padding(.top, topPadding)
        .padding(.bottom, onDark ? 0 : 10)
    }
}

/// One item as a card: a colour bar on the edge, its time (the end under it), a thin divider, the title with one
/// line under it, and a tile with the icon of its kind. Direction follows the language.
struct ItemRow: View {
    @Environment(\.wa) private var wa
    let item: CalItemDto
    var onToggle: (() -> Void)?
    var showDate = false
    let onClick: () -> Void

    var body: some View {
        let color = typeColor(item, wa)
        let shape = RoundedRectangle(cornerRadius: 14, style: .continuous)
        let sub: [String] = [
            item.overdue ? L("cal_overdue") : nil,
            item.originTime.map { L("cal_origin_time", $0, zoneCity(item.timezone)) },
            item.location,
            item.turns.map { L("mo_turns", $0) },
            item.type == "task" ? L("cal_kind_task") : (item.type == "reminder" ? L("cal_kind_reminder") : nil),
        ].compactMap { $0 }
        Button(action: onClick) {
            HStack(spacing: 0) {
                color.frame(width: 3.5)
                HStack(spacing: 0) {
                    VStack(spacing: 0) {
                        // In search results there is no day heading above the card, so the card says its day.
                        if showDate, let day = calDay(item.date) {
                            Text(verbatim: shortDay(day)).font(.dorr(10, .heavy)).foregroundStyle(color).lineLimit(1)
                        }
                        if item.allDay || item.startsAt == nil {
                            Text(L("cal_all_day")).font(.dorr(11, .bold)).foregroundStyle(wa.mut).multilineTextAlignment(.center).lineLimit(2)
                        } else {
                            Text(verbatim: timeText(item.startsAt)).font(.dorr(12.5, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                            if let end = item.endsAt { Text(verbatim: timeText(end)).font(.dorr(10.5)).foregroundStyle(wa.soft).lineLimit(1) }
                        }
                    }
                    .frame(width: 54)
                    wa.line.frame(width: 1, height: 28).padding(.horizontal, 8)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: [item.emoji, item.title].compactMap { $0 }.joined(separator: " "))
                            .font(.dorr(13.5, .bold)).foregroundStyle(item.done ? wa.soft : wa.ink).lineLimit(1)
                        if !sub.isEmpty {
                            HStack(spacing: 3) {
                                if item.location != nil {
                                    Image(systemName: "mappin.and.ellipse").font(.system(size: 10)).foregroundStyle(wa.mut)
                                } else if item.originTime != nil {
                                    Image(systemName: "globe").font(.system(size: 10)).foregroundStyle(wa.mut)
                                }
                                Text(verbatim: sub.joined(separator: "  ·  ")).font(.dorr(11)).foregroundStyle(item.overdue ? wa.danger : wa.mut).lineLimit(1)
                            }
                        }
                    }
                    Spacer(minLength: 0)
                    if let onToggle {
                        Button(action: onToggle) {
                            Image(systemName: item.done ? "checkmark.circle.fill" : "circle").font(.system(size: 20))
                                .foregroundStyle(item.done ? Color(hex: 0x16A34A) : wa.soft)
                                .scaleEffect(item.done ? 1 : 0.9)
                                .animation(.spring(response: 0.3, dampingFraction: 0.4), value: item.done)
                        }
                        .buttonStyle(.plain)
                        .padding(.leading, 4).padding(.trailing, 6)
                    } else {
                        Spacer().frame(width: 8)
                    }
                    Image(systemName: typeIcon(item.type)).font(.system(size: 15, weight: .semibold)).foregroundStyle(color)
                        .frame(width: 34, height: 34)
                        .background(color.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                }
                .padding(.horizontal, 10)
                .padding(.vertical, 8)
                .frame(minHeight: 52)
            }
            .fixedSize(horizontal: false, vertical: true)
            .background(wa.surface)
            .clipShape(shape)
            .overlay(shape.stroke(wa.line, lineWidth: 1))
        }
        .buttonStyle(.plain)
    }
}

func zoneCity(_ zone: String?) -> String {
    (zone ?? "").split(separator: "/").last.map { String($0).replacingOccurrences(of: "_", with: " ") } ?? ""
}

// MARK: - Filters

private let filters: [(String, String)] = [
    ("all", "cal_f_all"), ("event", "cal_f_events"), ("task", "cal_f_tasks"), ("reminder", "cal_f_reminders"),
    ("occasion", "cal_f_occasions"), ("discover", "cal_f_discover"), ("sports", "cal_f_sports"),
]

/// Does an item belong under this filter chip?
func matchesFilter(_ item: CalItemDto, _ filter: String) -> Bool {
    switch filter {
    case "all": return true
    case "occasion": return item.type == "moment" || item.type == "personal"
    default: return item.type == filter
    }
}

/// The chips follow Settings: a kind whose source is off has no chip, the order is the one set for Today, and (on
/// Today) a hidden kind has none either. A chosen chip that goes away falls back to "All".
@MainActor
private struct FilterChips: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var store = CalendarStore.shared
    @Binding var filter: String
    var honourHidden = false

    var body: some View {
        let today = store.today
        let sources = today?.sources
        let hidden = honourHidden ? (today?.sections?.hidden ?? []) : []
        let order = sectionOrder(today?.sections)
        let sectionOfChip = ["event": "events", "discover": "events", "sports": "events", "task": "tasks", "reminder": "reminders", "occasion": "moments"]
        let needsOf = ["event": ["events"], "discover": ["discover"], "sports": ["sports"], "task": ["tasks"], "reminder": ["reminders"], "occasion": ["moments", "personal"]]
        let chips = filters.filter { key, _ in
            guard let section = sectionOfChip[key], let needs = needsOf[key] else { return true }
            return !hidden.contains(section) && (sources == nil || needs.contains { sources!.contains($0) })
        }
        .sorted { a, b in
            let ia = a.0 == "all" ? -1 : (order.firstIndex(of: sectionOfChip[a.0] ?? "") ?? 0)
            let ib = b.0 == "all" ? -1 : (order.firstIndex(of: sectionOfChip[b.0] ?? "") ?? 0)
            if ia != ib { return ia < ib }
            return (filters.firstIndex { $0.0 == a.0 } ?? 0) < (filters.firstIndex { $0.0 == b.0 } ?? 0)
        }
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 6) {
                ForEach(chips, id: \.0) { key, label in
                    let on = filter == key
                    Button { filter = key } label: {
                        Text(L(label)).font(.dorr(12.5, .bold)).foregroundStyle(on ? .white : wa.ink)
                            .padding(.horizontal, 12).padding(.vertical, 7)
                            .background(on ? wa.red : wa.surface, in: Capsule())
                            .overlay(Capsule().stroke(on ? .clear : wa.line, lineWidth: 1))
                    }
                    .buttonStyle(.plain)
                }
            }
        }
        .onChange(of: chips.map(\.0)) { keys in if filter != "all" && !keys.contains(filter) { filter = "all" } }
        .onAppear { if filter != "all" && !chips.contains(where: { $0.0 == filter }) { filter = "all" } }
    }
}

// MARK: - Week & month

@MainActor
private struct RangeTab<Hero: View>: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var store = CalendarStore.shared
    let month: Bool
    @Binding var selected: Date
    @Binding var filter: String
    let onOpen: (CalItemDto) -> Void
    @ViewBuilder let hero: () -> Hero
    @State private var anchor: Date?
    @State private var items: [CalItemDto]?
    @State private var enabled = true

    private var days: [Date] {
        let base = anchor ?? selected
        let cal = calCalendar
        let firstWeekday = cal.firstWeekday
        let start = month ? (cal.date(from: cal.dateComponents([.year, .month], from: base)).map { calAddDays($0, 0) } ?? base) : base
        var first = cal.date(bySettingHour: 12, minute: 0, second: 0, of: start) ?? start
        while cal.component(.weekday, from: first) != firstWeekday { first = calAddDays(first, -1) }
        if !month { return (0..<7).map { calAddDays(first, $0) } }
        let range = cal.range(of: .day, in: .month, for: base)?.count ?? 30
        let monthStart = cal.date(from: cal.dateComponents([.year, .month], from: base)).flatMap { cal.date(bySettingHour: 12, minute: 0, second: 0, of: $0) } ?? base
        var last = calAddDays(monthStart, range - 1)
        while cal.component(.weekday, from: calAddDays(last, 1)) != firstWeekday { last = calAddDays(last, 1) }
        let count = (cal.dateComponents([.day], from: first, to: last).day ?? 0) + 1
        return (0..<count).map { calAddDays(first, $0) }
    }

    var body: some View {
        let days = self.days
        let shown = (items ?? []).filter { matchesFilter($0, filter) }
        var byDay: [String: [CalItemDto]] = [:]
        for item in shown {
            guard let start = calDay(item.date) else { continue }
            let end = calDay(item.endDate) ?? start
            var d = start
            while d <= end {
                byDay[calKey(d), default: []].append(item)
                d = calAddDays(d, 1)
            }
        }
        let base = anchor ?? selected
        return VStack(alignment: .leading, spacing: 0) {
            DarkHeroPanel {
                hero()
                HStack {
                    NavArrow(icon: "chevron.backward", onDark: true) { shift(-1) }
                    Text(verbatim: month ? monthTitle(base) : shortDay(days.first!) + " – " + shortDay(days.last!))
                        .font(.dorr(15, .heavy)).foregroundStyle(.white).multilineTextAlignment(.center).frame(maxWidth: .infinity)
                    NavArrow(icon: "chevron.forward", onDark: true) { shift(1) }
                }
                .padding(.top, 8)
                Group {
                    if month {
                        MonthCard(days: days, selected: selected, anchorMonth: base, byDay: byDay) { selected = $0 }
                        DayHeader(day: selected, onDark: true).padding(.top, 8)
                    } else {
                        WeekStrip(days: days, selected: selected, byDay: byDay) { selected = $0 }
                    }
                }
                .padding(.top, 8)
            }
            Spacer().frame(height: 12)
            if !enabled {
                WaCard { WaEmpty(icon: "calendar", tone: .gray, title: L("cal_off_title"), text: L("cal_off_text")) }
            } else {
                FilterChips(filter: $filter)
                Spacer().frame(height: 10)
                if items == nil {
                    ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 14).frame(height: 50).padding(.bottom, 8) }
                } else if (byDay[calKey(selected)] ?? []).isEmpty {
                    DayEmpty(filtered: filter != "all")
                } else {
                    ForEach(Array((byDay[calKey(selected)] ?? []).enumerated()), id: \.element.id) { i, item in
                        ItemRow(item: item) { onOpen(item) }.padding(.bottom, 8).waRise(i)
                    }
                }
            }
        }
        .task(id: "\(calKey(days.first!))|\(calKey(days.last!))|\(store.version)") {
            items = nil
            do {
                let feed = try await CalendarApi.feed(from: calKey(days.first!), to: calKey(days.last!), timezone: calZoneId()).data
                enabled = feed?.enabled != false
                items = feed?.items ?? []
            } catch {
                if error is CancellationError { return }
                items = []
            }
        }
    }

    private func shift(_ step: Int) {
        let base = anchor ?? selected
        anchor = calCalendar.date(byAdding: month ? .month : .weekOfYear, value: step, to: base) ?? base
    }
}

private struct WeekStrip: View {
    @Environment(\.wa) private var wa
    let days: [Date]
    let selected: Date
    let byDay: [String: [CalItemDto]]
    let onSelect: (Date) -> Void

    var body: some View {
        VStack(spacing: 8) {
            HStack(spacing: 0) {
                ForEach(days, id: \.self) { d in
                    let on = calSameDay(d, selected)
                    let today = calSameDay(d, Date())
                    let dots = Array(Set((byDay[calKey(d)] ?? []).map { typeColor($0, wa) })).prefix(3)
                    Button { onSelect(d) } label: {
                        ZStack(alignment: .bottom) {
                            VStack(spacing: 2) {
                                Text(verbatim: weekdayLetter(d)).font(.dorr(9, .bold)).foregroundStyle(on ? Color.white.opacity(0.9) : Color.white.opacity(0.4))
                                Text(verbatim: dayNumber(d)).font(.dorr(on ? 15 : 14, on || today ? .heavy : .medium))
                                    .foregroundStyle(on ? .white : (today ? wa.red : .white))
                            }
                            .frame(width: 34)
                            .frame(maxHeight: .infinity)
                            .background { if on { Capsule().fill(wa.heroGradient) } }
                            if !on && !dots.isEmpty {
                                HStack(spacing: 2) { ForEach(Array(dots.enumerated()), id: \.offset) { _, c in Circle().fill(c).frame(width: 3, height: 3) } }
                                    .padding(.bottom, 2)
                            }
                        }
                        .frame(maxWidth: .infinity)
                        .frame(height: 48)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 6)
            .padding(.vertical, 8)
            .background(Color.white.opacity(0.08), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
            DayHeader(day: selected, onDark: true)
        }
    }
}

private struct MonthCard: View {
    @Environment(\.wa) private var wa
    let days: [Date]
    let selected: Date
    let anchorMonth: Date
    let byDay: [String: [CalItemDto]]
    let onSelect: (Date) -> Void

    var body: some View {
        let month = calCalendar.component(.month, from: anchorMonth)
        VStack(spacing: 0) {
            HStack(spacing: 0) {
                ForEach(days.prefix(7), id: \.self) { d in
                    Text(verbatim: weekdayLetter(d)).font(.dorr(10, .bold)).foregroundStyle(Color.white.opacity(0.4)).frame(maxWidth: .infinity)
                }
            }
            .padding(.bottom, 4)
            ForEach(0..<(days.count / 7), id: \.self) { w in
                HStack(spacing: 0) {
                    ForEach(days[(w * 7)..<(w * 7 + 7)], id: \.self) { d in
                        DayCell(day: d, selected: calSameDay(d, selected), today: calSameDay(d, Date()),
                                outside: calCalendar.component(.month, from: d) != month,
                                dots: Array(Array(Set((byDay[calKey(d)] ?? []).map { typeColor($0, wa) })).prefix(3))) { onSelect(d) }
                            .frame(maxWidth: .infinity)
                    }
                }
            }
        }
        .padding(.horizontal, 6)
        .padding(.vertical, 8)
        .background(Color.white.opacity(0.08), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
    }
}

private struct DayCell: View {
    @Environment(\.wa) private var wa
    let day: Date
    let selected: Bool
    let today: Bool
    let outside: Bool
    let dots: [Color]
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(spacing: 1) {
                Text(verbatim: dayNumber(day)).font(.dorr(13, today || selected ? .heavy : .medium))
                    .foregroundStyle(selected ? .white : (outside ? Color.white.opacity(0.28) : .white))
                    .frame(width: 28, height: 28)
                    .background { if selected { Circle().fill(wa.heroGradient) } }
                    .overlay { if today && !selected { Circle().stroke(Color.white.opacity(0.7), lineWidth: 1.5) } }
                HStack(spacing: 2) { ForEach(Array(dots.enumerated()), id: \.offset) { _, c in Circle().fill(c).frame(width: 3, height: 3) } }
                    .frame(height: 5)
            }
            .frame(height: 40)
            .frame(maxWidth: .infinity)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

private struct NavArrow: View {
    @Environment(\.wa) private var wa
    let icon: String
    var onDark = false
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 13, weight: .bold)).foregroundStyle(onDark ? .white : wa.red)
                .frame(width: 28, height: 28)
                .background(onDark ? Color.white.opacity(0.12) : wa.surface, in: Circle())
        }
        .buttonStyle(.plain)
    }
}
