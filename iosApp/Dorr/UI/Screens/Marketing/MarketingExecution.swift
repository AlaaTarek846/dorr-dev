import SwiftUI
import UIKit

/// androidApp `ui/screens/marketing/MarketingExecution.kt` — phase B: the daily calendar (U06), one post / ad / task
/// with its A/B variants, claims review and steps (U07), and the ad studio. DORR never posts: the person posts and says so.

// MARK: - shared labels

let mkChannels = ["instagram", "tiktok", "snapchat", "x", "facebook", "youtube", "google", "linkedin", "whatsapp", "email", "sms", "website", "offline", "other"]
let mkFormats = ["image", "video", "story", "reel", "carousel", "text"]

func mkChannelLabel(_ channel: String) -> String {
    mkChannels.contains(channel) ? L("mktg_ch_\(channel)") : L("mktg_ch_other")
}

func mkFormatLabel(_ format: String?) -> String {
    guard let format, ["video", "story", "reel", "carousel", "text"].contains(format) else { return L("mktg_fmt_image") }
    return L("mktg_fmt_\(format)")
}

func mkContentKindLabel(_ kind: String) -> String {
    switch kind {
    case "ad": return L("mktg_kind_ad")
    case "task": return L("mktg_kind_task")
    default: return L("mktg_kind_post")
    }
}

func mkClaimLabel(_ code: String) -> String {
    switch code {
    case "guaranteed_result", "absolute_claim", "number_needs_proof", "price_claim", "too_long_for_channel", "headline_too_long":
        return L("mktg_claim_\(code)")
    default:
        return L("mktg_claim_empty_text")
    }
}

/// Text that is there (not empty, not only spaces), or nil.
func mkNonBlank(_ text: String?) -> String? {
    guard let text, !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return nil }
    return text
}

/// The local moment of a server ISO time.
func mkLocal(_ iso: String?) -> Date? { parseInstant(iso) }

/// A local date in the app's language (its month names and digits).
func mkFormat(_ date: Date, _ pattern: String) -> String {
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.calendar = calCalendar
    f.timeZone = .current
    f.dateFormat = pattern
    return f.string(from: date)
}

func mkTime(_ iso: String?) -> String { mkLocal(iso).map { mkFormat($0, "HH:mm") } ?? "" }

func mkDateTime(_ iso: String?) -> String {
    guard let local = mkLocal(iso) else { return "" }
    return mkFormat(local, "EEE d MMM") + " · " + mkFormat(local, "HH:mm")
}

/// A server ISO time (with my offset) from a local moment.
func mkIso(_ date: Date) -> String {
    let f = ISO8601DateFormatter()
    f.timeZone = .current
    f.formatOptions = [.withInternetDateTime]
    return f.string(from: date)
}

/// [day] with the hour and minute of [time].
func mkAt(_ day: Date, _ time: Date) -> Date {
    let cal = calCalendar
    let t = cal.dateComponents([.hour, .minute], from: time)
    return cal.date(bySettingHour: t.hour ?? 0, minute: t.minute ?? 0, second: 0, of: day) ?? day
}

/// The first day of [day]'s week (the app language's first weekday).
func mkWeekStart(_ day: Date) -> Date {
    let cal = calCalendar
    let offset = (cal.component(.weekday, from: day) - cal.firstWeekday + 7) % 7
    return calAddDays(day, -offset)
}

/// 12 → "12", 12.5 → "12.5".
func mkNum(_ value: Double) -> String {
    value == value.rounded() && abs(value) < 1e15 ? String(Int(value)) : String(value)
}

private let contentStatus: [String: (String, UInt32)] = [
    "DRAFT": ("mktg_cs_DRAFT", 0x6B7280),
    "POLICY_REVIEW": ("mktg_cs_POLICY_REVIEW", 0xD97706),
    "APPROVED": ("mktg_cs_APPROVED", 0x16A34A),
    "SCHEDULED": ("mktg_cs_SCHEDULED", 0x2563EB),
    "PUBLISHED": ("mktg_cs_PUBLISHED", 0x16A34A),
    "FAILED": ("mktg_cs_FAILED", 0xDC2626),
    "REJECTED": ("mktg_cs_REJECTED", 0xDC2626),
    "OPEN": ("mktg_cs_OPEN", 0x6B7280),
    "DONE": ("mktg_cs_DONE", 0x16A34A),
]

struct MkContentStatus: View {
    let status: String

    var body: some View {
        let entry = contentStatus[status] ?? ("mktg_cs_DRAFT", 0x6B7280)
        MkBadge(text: L(entry.0), color: Color(hex: entry.1))
    }
}

struct MkChannelTag: View {
    let channel: String
    var body: some View { MkSmallTag(text: mkChannelLabel(channel), tint: mkBlue) }
}

/// A read-only field (the same rounded field as [WeTextField]) that opens a picker — Android's `DorrTextField(onClick)`.
struct MkPickField: View {
    @Environment(\.wa) private var wa
    var label: String?
    let value: String
    let icon: String
    let action: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            if let label { Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.ink).padding(.bottom, 8) }
            Button(action: action) {
                HStack(spacing: 8) {
                    Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red.opacity(0.8))
                    Text(verbatim: value).font(.dorr(15)).foregroundStyle(wa.ink).lineLimit(1)
                    Spacer(minLength: 0)
                    Image(systemName: "chevron.down").font(.system(size: 12, weight: .semibold)).foregroundStyle(wa.soft)
                }
                .padding(.horizontal, 14)
                .frame(minHeight: 48)
                .background(wa.night ? wa.bg : wa.field, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.line, lineWidth: 1))
                .contentShape(Rectangle())
            }
            .buttonStyle(WaPressStyle(scale: 0.98))
        }
    }
}

extension View {
    /// A dialog (a date or time picker) over this page, drawn in the page itself like [weSheet]: a dim scrim that
    /// closes it, the card in the middle.
    func mkDialog<C: View>(_ shown: Bool, onDismiss: @escaping () -> Void, @ViewBuilder content: @escaping () -> C) -> some View {
        overlay {
            ZStack {
                if shown {
                    Color.black.opacity(0.45).ignoresSafeArea().onTapGesture(perform: onDismiss).transition(.opacity)
                    content().transition(.opacity.combined(with: .scale(scale: 0.94)))
                }
            }
            .animation(.easeOut(duration: 0.2), value: shown)
        }
    }
}

// MARK: - calendar (U06)

/// The daily calendar: a week strip, the chosen day's posts, ads and tasks, and the AI that fills the next days from
/// the approved plan. Reloads once per `marketing.job.changed` of this project and on reconnect — never on a timer.
struct MarketingCalendar: View {
    @EnvironmentObject private var host: MkHost
    @ObservedObject private var net = NetworkMonitor.shared
    @Environment(\.wa) private var wa
    let projectId: String

    @State private var day = calToday()
    @State private var items: [MkContentDto]?
    @State private var unplanned = 0
    @State private var project: MkProjectDto?
    @State private var jobs: [MkJobDto] = []
    @State private var reload = 0
    @State private var generating = false
    @State private var adding = false

    var body: some View {
        let weekStart = mkWeekStart(day)
        MkPage(title: L("mktg_calendar")) {
            MkWeekStrip(weekStart: weekStart, selected: day, items: items ?? [], onPick: { day = $0 }, onWeek: { delta in day = calAddDays(day, delta * 7) })
            controls
            dayList
        }
        .task(id: "\(projectId)|\(calKey(weekStart))|\(reload)|\(net.reconnectTick)|\(host.projectChanges(projectId))") { await load(weekStart) }
        .weSheet(generating, onDismiss: { generating = false }) {
            MkGenerateCalendarSheet(projectId: projectId) {
                generating = false
                reload += 1
            }
        }
        .weSheet(adding, onDismiss: { adding = false }) {
            MkAddItemSheet(projectId: projectId, day: day) { created in
                adding = false
                reload += 1
                if !created.isTask { host.push(.content(projectId: projectId, itemId: created.id)) }
            }
        }
    }

    private var canEdit: Bool { project?.abilities?.edit == true && project?.status == "active" }

    @ViewBuilder
    private var controls: some View {
        let running = jobs.first { $0.type == "calendar" && !$0.finished }
        let last = jobs.first { $0.type == "calendar" }
        if let running {
            WaCard(padding: 14) {
                Text(verbatim: L("mktg_calendar_writing")).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink)
                MkBar(fraction: Double(running.progressPct ?? 0) / 100, color: mkBlue).padding(.top, 8)
            }
            .padding(.top, 12)
        } else if let last, last.status == "FAILED" {
            WaNote(text: L("mktg_job_failed", last.errorCode ?? "")).padding(.top, 12)
        }
        if canEdit {
            HStack(spacing: 8) {
                WaButton(text: L("mktg_calendar_generate"), icon: "sparkles", enabled: running == nil, compact: true) { generating = true }
                WaButton(text: L("mktg_add"), style: .ghost, icon: "plus", compact: true) { adding = true }
                    .fixedSize()
            }
            .padding(.top, 12)
        }
    }

    @ViewBuilder
    private var dayList: some View {
        let dayItems = (items ?? []).filter { item in mkLocal(item.plannedAt).map { calSameDay($0, day) } ?? false }
        WaSectionTitle(title: dayTitle(day))
        if items == nil {
            VStack(spacing: 10) { ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 20).frame(height: 76) } }
        } else if dayItems.isEmpty {
            WaEmpty(icon: "calendar.badge.checkmark", tone: .gray, title: L("mktg_calendar_empty"),
                    text: L(canEdit ? "mktg_calendar_empty_sub" : "mktg_calendar_empty_view"))
        } else {
            VStack(spacing: 10) {
                ForEach(Array(dayItems.enumerated()), id: \.element.id) { i, item in
                    MkContentRow(item: item, canTick: canEdit, onTick: { done in Task { await tick(item, done) } },
                                 action: { host.push(.content(projectId: projectId, itemId: item.id)) })
                        .waRise(i)
                }
            }
        }
        if unplanned > 0 {
            Text(verbatim: L("mktg_unplanned", unplanned)).font(.dorr(12.5)).foregroundStyle(wa.mut).padding(.horizontal, 4).padding(.top, 12)
        }
    }

    private func load(_ weekStart: Date) async {
        do {
            if project == nil { project = try await MarketingApi.project(projectId).data }
            let cal = try await MarketingApi.calendar(projectId, from: calKey(weekStart), to: calKey(calAddDays(weekStart, 6))).data
            items = cal?.items ?? []
            unplanned = cal?.unplanned ?? 0
            jobs = try await MarketingApi.jobs(projectId).data ?? []
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
    }

    private func tick(_ item: MkContentDto, _ done: Bool) async {
        do {
            _ = try await MarketingApi.taskDone(item.id, MkDoneRequest(done: done))
            reload += 1
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
    }
}

private struct MkWeekStrip: View {
    @Environment(\.wa) private var wa
    let weekStart: Date
    let selected: Date
    let items: [MkContentDto]
    let onPick: (Date) -> Void
    let onWeek: (Int) -> Void

    var body: some View {
        let busy = Set(items.compactMap { item in mkLocal(item.plannedAt).map { calKey($0) } })
        let end = calAddDays(weekStart, 6)
        WaCard(padding: 12) {
            HStack(spacing: 4) {
                // SF "chevron.backward / forward" mirror themselves in right-to-left layouts.
                arrow("chevron.backward", L("mktg_prev_week")) { onWeek(-1) }
                Text(verbatim: mkFormat(weekStart, "d MMM") + " – " + mkFormat(end, "d MMM yyyy"))
                    .font(.dorr(14, .heavy)).foregroundStyle(wa.ink).lineLimit(1).minimumScaleFactor(0.8)
                    .frame(maxWidth: .infinity)
                arrow("chevron.forward", L("mktg_next_week")) { onWeek(1) }
            }
            HStack(spacing: 4) {
                ForEach(0..<7, id: \.self) { offset in
                    let date = calAddDays(weekStart, offset)
                    dayCell(date, busy: busy.contains(calKey(date)))
                }
            }
            .padding(.top, 10)
        }
        .waRise(0)
    }

    private func arrow(_ icon: String, _ label: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 15, weight: .bold)).foregroundStyle(wa.ink)
                .frame(width: 36, height: 36)
                .contentShape(Circle())
        }
        .buttonStyle(WaPressStyle(scale: 0.9))
        .accessibilityLabel(label)
    }

    private func dayCell(_ date: Date, busy: Bool) -> some View {
        let isSel = calSameDay(date, selected)
        let today = calSameDay(date, Date())
        return Button { onPick(date) } label: {
            VStack(spacing: 2) {
                Text(verbatim: mkFormat(date, "EEE")).font(.dorr(10.5)).foregroundStyle(isSel ? Color.white : wa.mut).lineLimit(1).minimumScaleFactor(0.7)
                Text(verbatim: mkFormat(date, "d")).font(.dorr(15, .heavy)).foregroundStyle(isSel ? Color.white : wa.ink)
                Circle().fill(busy ? (isSel ? Color.white : wa.red) : Color.clear).frame(width: 5, height: 5).padding(.top, 1)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 8)
            .background(isSel ? wa.red : Color.clear, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay {
                if today && !isSel { RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.red.opacity(0.4), lineWidth: 1) }
            }
            .contentShape(Rectangle())
            .animation(.easeOut(duration: 0.2), value: isSel)
        }
        .buttonStyle(.plain)
    }
}

/// One item of the calendar or a list: kind icon, time, channel, title, status; tasks get a tick box.
struct MkContentRow: View {
    @Environment(\.wa) private var wa
    let item: MkContentDto
    var canTick = false
    var onTick: (Bool) -> Void = { _ in }
    let action: () -> Void

    var body: some View {
        let done = item.status == "DONE"
        WaCard(padding: 14) {
            HStack(spacing: 12) {
                if item.isTask {
                    Button { onTick(!done) } label: {
                        Image(systemName: done ? "checkmark.circle.fill" : "circle")
                            .font(.system(size: 24, weight: .semibold))
                            .foregroundStyle(done ? wa.green : wa.soft)
                            .frame(width: 30, height: 30)
                    }
                    .buttonStyle(WaPressStyle(scale: 0.85))
                    .disabled(!canTick)
                    .accessibilityLabel(L(done ? "mktg_task_reopen" : "mktg_task_done"))
                } else {
                    WaIconWell(icon: item.kind == "ad" ? "megaphone.fill" : "photo.fill", tone: item.kind == "ad" ? .pink : .blue, size: 40, iconSize: 20)
                }
                VStack(alignment: .leading, spacing: 4) {
                    Text(verbatim: item.title).font(.dorr(14, .heavy)).strikethrough(done)
                        .foregroundStyle(done ? wa.mut : wa.ink).lineLimit(2).multilineTextAlignment(.leading)
                    HStack(spacing: 6) {
                        let time = mkTime(item.plannedAt)
                        if !time.isEmpty { Text(verbatim: time).font(.dorr(12, .bold)).foregroundStyle(wa.mut) }
                        MkChannelTag(channel: item.channel)
                        if item.hasBlockingIssues {
                            Image(systemName: "exclamationmark.triangle.fill").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.danger)
                                .accessibilityLabel(L("mktg_has_blocking"))
                        }
                    }
                }
                Spacer(minLength: 6)
                if !item.isTask { MkContentStatus(status: item.status) }
            }
        }
        .contentShape(Rectangle())
        .onTapGesture(perform: action)
    }
}

private struct MkGenerateCalendarSheet: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let projectId: String
    let onStarted: () -> Void

    @State private var days = 7
    @State private var channels: [String] = []
    @State private var replace = true
    @State private var busy = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(verbatim: L("mktg_calendar_generate")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                Text(verbatim: L("mktg_calendar_generate_sub")).font(.dorr(13)).foregroundStyle(wa.mut).lineSpacing(4).fixedSize(horizontal: false, vertical: true)
                Text(verbatim: L("mktg_days")).font(.dorr(12.5, .bold)).foregroundStyle(wa.mut)
                HStack(spacing: 8) {
                    ForEach([7, 14, 30], id: \.self) { d in
                        WeChip(text: L("mktg_n_days", d), selected: days == d) { days = d }
                    }
                }
                Text(verbatim: L("mktg_channels_optional")).font(.dorr(12.5, .bold)).foregroundStyle(wa.mut)
                WeFlow(spacing: 8) {
                    ForEach(mkChannels.filter { $0 != "offline" && $0 != "other" }, id: \.self) { ch in
                        WeChip(text: mkChannelLabel(ch), selected: channels.contains(ch)) { toggle(ch) }
                    }
                }
                HStack(spacing: 10) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: L("mktg_replace_drafts")).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink)
                        Text(verbatim: L("mktg_replace_drafts_sub")).font(.dorr(12)).foregroundStyle(wa.mut).fixedSize(horizontal: false, vertical: true)
                    }
                    Spacer(minLength: 6)
                    Toggle("", isOn: $replace).labelsHidden().tint(wa.red)
                }
                WaButton(text: L("mktg_calendar_generate"), icon: "sparkles", loading: busy) { Task { await start() } }
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 20)
        }
        .frame(maxHeight: UIScreen.main.bounds.height * 0.8)
        .fixedSize(horizontal: false, vertical: true)
    }

    private func toggle(_ ch: String) {
        if let i = channels.firstIndex(of: ch) {
            channels.remove(at: i)
        } else if channels.count < 8 {
            channels.append(ch)
        }
    }

    private func start() async {
        busy = true
        do {
            _ = try await MarketingApi.generateCalendar(projectId, MkCalendarRequest(
                days: days, channels: channels.isEmpty ? nil : channels, replaceDrafts: replace, timezone: TimeZone.current.identifier
            ))
            host.showToast(L("mktg_calendar_started"))
            onStarted()
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}

/// A post, an ad or a task written by hand for the chosen day.
private struct MkAddItemSheet: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let projectId: String
    let day: Date
    let onCreated: (MkContentDto) -> Void

    @State private var kind = "post"
    @State private var channel = "instagram"
    @State private var title = ""
    @State private var text = ""
    @State private var time: Date
    @State private var pickTime = false
    @State private var busy = false

    init(projectId: String, day: Date, onCreated: @escaping (MkContentDto) -> Void) {
        self.projectId = projectId
        self.day = day
        self.onCreated = onCreated
        _time = State(initialValue: calCalendar.date(bySettingHour: 19, minute: 0, second: 0, of: day) ?? day)
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(verbatim: L("mktg_add_item")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                HStack(spacing: 8) {
                    ForEach(["post", "ad", "task"], id: \.self) { k in
                        WeChip(text: mkContentKindLabel(k), selected: kind == k) {
                            kind = k
                            if k == "task" { channel = "offline" } else if channel == "offline" { channel = "instagram" }
                        }
                    }
                }
                WeFlow(spacing: 8) {
                    ForEach(mkChannels.filter { kind == "task" || $0 != "offline" }, id: \.self) { ch in
                        WeChip(text: mkChannelLabel(ch), selected: channel == ch) { channel = ch }
                    }
                }
                WeTextField(value: $title, label: L("mktg_item_title"), limit: 160, capitalize: true)
                if kind != "task" {
                    WeTextField(value: $text, label: L("mktg_body"), minLines: 3, limit: 2200, capitalize: true)
                }
                MkPickField(label: L("mktg_when"), value: mkFormat(day, "EEE d MMM") + " · " + mkFormat(time, "HH:mm"), icon: "clock") { pickTime = true }
                WaButton(text: L("mktg_save"), enabled: title.trimmingCharacters(in: .whitespacesAndNewlines).count >= 2 && (kind == "task" || mkNonBlank(text) != nil),
                         loading: busy) { Task { await save() } }
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 20)
        }
        .frame(maxHeight: UIScreen.main.bounds.height * 0.8)
        .fixedSize(horizontal: false, vertical: true)
        .dorrDialog(isPresented: $pickTime) {
            CalTimePickerDialog(initial: time, onDismiss: { pickTime = false }) { picked in
                time = picked
                pickTime = false
            }
        }
    }

    private func save() async {
        busy = true
        var payload: [String: JSONValue] = [
            "kind": .string(kind),
            "channel": .string(channel),
            "title": .string(title.trimmingCharacters(in: .whitespacesAndNewlines)),
            "planned_at": .string(mkIso(mkAt(day, time))),
        ]
        if kind != "task" {
            payload["variants"] = .array([.object(["body": .string(text.trimmingCharacters(in: .whitespacesAndNewlines))])])
        }
        do {
            if let created = try await MarketingApi.createContent(projectId, payload).data { onCreated(created) }
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}

// MARK: - one item (U07 review)

/// One post, ad or task: the A/B variants, the claims review, and the steps its state allows — send for review,
/// approve this exact wording (owner or manager), return with a note, schedule, then "I posted it". DORR never posts.
struct MarketingContentPage: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let projectId: String
    let itemId: String

    @State private var item: MkContentDto?
    @State private var project: MkProjectDto?
    @State private var variant: String?
    @State private var busy: String?
    @State private var editing = false
    @State private var returning = false
    @State private var posting: Bool?
    /// The schedule flow: 1 = the day, 2 = the time.
    @State private var scheduleStep = 0
    @State private var scheduleDate = Date()
    @State private var reload = 0

    var body: some View {
        MkPage(title: item?.title ?? mkContentKindLabel("post")) {
            if let c = item {
                content(c)
            } else {
                VStack(spacing: 12) { ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 96) } }
            }
        }
        .task(id: "\(itemId)|\(reload)") { await load() }
        .weSheet(editing, onDismiss: { editing = false }) {
            if let c = item {
                MkEditContentSheet(item: c) { updated in
                    editing = false
                    item = updated
                    host.showToast(L("mktg_saved"))
                }
            }
        }
        .weSheet(returning, onDismiss: { returning = false }) {
            MkNoteSheet(title: L("mktg_return")) { note in
                returning = false
                guard let c = item else { return }
                act("return") { try await MarketingApi.rejectContent(c.id, MkNoteRequest(note: note.isEmpty ? nil : note)).data }
            }
        }
        .weSheet(posting != nil, onDismiss: { posting = nil }) {
            if let published = posting {
                MkPostedSheet(published: published) { url, note in
                    posting = nil
                    guard let c = item else { return }
                    act("outcome", ok: L("mktg_saved")) {
                        try await MarketingApi.contentOutcome(c.id, MkOutcomeRequest(published: published, url: url, note: note)).data
                    }
                }
            }
        }
        .mkDialog(scheduleStep > 0, onDismiss: { scheduleStep = 0 }) { scheduleDialog }
    }

    @ViewBuilder
    private func content(_ c: MkContentDto) -> some View {
        header(c).waRise(0)
        if c.status == "REJECTED", let note = mkNonBlank(c.reviewNote) {
            WaNote(text: L("mktg_returned_note", note)).padding(.top, 10)
        }
        if c.status == "PUBLISHED", let url = mkNonBlank(c.publishedUrl) {
            WaCard(padding: 14) {
                HStack(spacing: 8) {
                    Image(systemName: "link").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.green)
                    Text(verbatim: "\u{2066}\(url)\u{2069}").font(.dorr(12.5)).foregroundStyle(wa.ink).lineLimit(1).truncationMode(.middle)
                }
            }
            .padding(.top, 10)
        }
        if !c.isTask && !c.variants.isEmpty {
            variants(c)
        }
        if !c.isTask {
            WaSectionTitle(title: L("mktg_claims"))
            MkClaimsBlock(claims: c.claims)
        }
        steps(c).padding(.top, 18)
    }

    private func header(_ c: MkContentDto) -> some View {
        let meta = [mkContentKindLabel(c.kind), mkChannelLabel(c.channel), c.format.map { mkFormatLabel($0) }].compactMap { $0 }.joined(separator: " · ")
        return WaCard(padding: 16) {
            HStack(spacing: 12) {
                WaIconWell(icon: c.isTask ? "checklist" : (c.kind == "ad" ? "megaphone.fill" : "photo.fill"), tone: c.kind == "ad" ? .pink : .blue, size: 44, iconSize: 22)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: c.title).font(.dorr(15.5, .heavy)).foregroundStyle(wa.ink).fixedSize(horizontal: false, vertical: true)
                    Text(verbatim: meta).font(.dorr(12.5)).foregroundStyle(wa.mut)
                }
                Spacer(minLength: 6)
                MkContentStatus(status: c.status)
            }
            if let planned = mkNonBlank(c.plannedAt) {
                HStack(spacing: 6) {
                    Image(systemName: "clock").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.mut)
                    Text(verbatim: mkDateTime(planned)).font(.dorr(13, .bold)).foregroundStyle(wa.ink)
                }
                .padding(.top, 10)
            }
            if let objective = mkNonBlank(c.objective) {
                Text(verbatim: objective).font(.dorr(13)).foregroundStyle(wa.mut).lineSpacing(4).fixedSize(horizontal: false, vertical: true).padding(.top, 8)
            }
            if let cost = c.costEstimateMinor {
                Text(verbatim: L("mktg_cost_estimate", mkMoney(cost, project?.currency ?? ""))).font(.dorr(12.5)).foregroundStyle(wa.mut).padding(.top, 6)
            }
        }
    }

    @ViewBuilder
    private func variants(_ c: MkContentDto) -> some View {
        let shown = c.variants.first { $0.key == (variant ?? c.selected) } ?? c.variants[0]
        WaSectionTitle(title: L("mktg_variants")) {
            Button {
                UIPasteboard.general.string = mkVariantText(shown)
                host.showToast(L("mktg_copied"))
            } label: {
                Image(systemName: "doc.on.doc").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 34, height: 34)
                    .contentShape(Circle())
            }
            .buttonStyle(WaPressStyle(scale: 0.9))
            .accessibilityLabel(L("mktg_copy"))
        }
        if c.variants.count > 1 {
            HStack(spacing: 8) {
                ForEach(c.variants, id: \.key) { v in
                    WeChip(text: L("mktg_variant", v.key) + (v.key == c.selected ? " ✓" : ""), selected: v.key == shown.key) { variant = v.key }
                }
            }
            .padding(.bottom, 10)
        }
        MkVariantCard(variant: shown, claims: c.claims.filter { $0.variant == shown.key })
        if project?.abilities?.edit == true && c.variants.count > 1 && shown.key != c.selected && c.status != "PUBLISHED" {
            WaButton(text: L("mktg_use_variant", shown.key), style: .ghost, loading: busy == "select", compact: true) {
                act("select", ok: L("mktg_saved")) {
                    try await MarketingApi.updateContent(c.id, ["lock_version": .int(c.lockVersion), "selected": .string(shown.key)]).data
                }
            }
            .padding(.top, 8)
        }
    }

    @ViewBuilder
    private func steps(_ c: MkContentDto) -> some View {
        let abilities = project?.abilities
        let canEdit = abilities?.edit == true
        VStack(alignment: .leading, spacing: 10) {
            if c.isTask {
                if canEdit {
                    let done = c.status == "DONE"
                    WaButton(text: L(done ? "mktg_task_reopen" : "mktg_task_done"), style: done ? .ghost : .primary, icon: "checkmark.circle", loading: busy == "done") {
                        act("done") { try await MarketingApi.taskDone(c.id, MkDoneRequest(done: !done)).data }
                    }
                }
            } else if ["DRAFT", "REJECTED"].contains(c.status) && canEdit {
                WaButton(text: L("mktg_send_review"), icon: "paperplane.fill", enabled: !c.hasBlockingIssues, loading: busy == "submit") {
                    act("submit", ok: L("mktg_sent_review")) { try await MarketingApi.submitContent(c.id).data }
                }
                if c.hasBlockingIssues {
                    Text(verbatim: L("mktg_fix_blocking")).font(.dorr(12.5)).foregroundStyle(wa.danger)
                }
            } else if c.status == "POLICY_REVIEW" && abilities?.approve == true {
                WaButton(text: L("mktg_approve_content"), icon: "checkmark.seal", loading: busy == "approve") {
                    act("approve", ok: L("mktg_approved_toast")) { try await MarketingApi.approveContent(c.id, MkLockRequest(lockVersion: c.lockVersion)).data }
                }
                WaButton(text: L("mktg_return"), style: .ghost, icon: "arrow.uturn.backward") { returning = true }
            } else if c.status == "POLICY_REVIEW" {
                WaNote(text: L("mktg_waiting_review"))
            } else if ["APPROVED", "SCHEDULED"].contains(c.status) && canEdit {
                WaButton(text: L("mktg_mark_posted"), icon: "checkmark.circle.fill") { posting = true }
                HStack(spacing: 10) {
                    WaButton(text: L(c.status == "SCHEDULED" ? "mktg_reschedule" : "mktg_schedule"), style: .ghost, icon: "clock", loading: busy == "schedule", compact: true) {
                        startSchedule(c)
                    }
                    WaButton(text: L("mktg_not_posted"), style: .ghost, compact: true) { posting = false }
                }
                Text(verbatim: L("mktg_post_yourself")).font(.dorr(12)).foregroundStyle(wa.mut).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
            }
            if canEdit && c.status != "PUBLISHED" {
                HStack(spacing: 10) {
                    WaButton(text: L("mktg_edit"), style: .quiet, icon: "pencil", compact: true) { editing = true }
                    WaButton(text: L("mktg_delete"), style: .quiet, icon: "trash", compact: true) { confirmDelete(c) }
                }
                if ["APPROVED", "SCHEDULED", "POLICY_REVIEW"].contains(c.status) {
                    Text(verbatim: L("mktg_edit_resets")).font(.dorr(11.5)).foregroundStyle(wa.soft).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
                }
            }
        }
    }

    /// Date, then time; the time must be in the future (the server refuses a past one).
    @ViewBuilder
    private var scheduleDialog: some View {
        if scheduleStep == 1 {
            CalDatePickerDialog(initial: scheduleDate, onDismiss: { scheduleStep = 0 }) { picked in
                let today = calToday()
                let day = calKey(picked) < calKey(today) ? today : picked
                scheduleDate = mkAt(day, scheduleDate)
                scheduleStep = 2
            }
        } else if scheduleStep == 2 {
            CalTimePickerDialog(initial: scheduleDate, onDismiss: { scheduleStep = 0 }) { time in
                let chosen = mkAt(scheduleDate, time)
                let safe = chosen > Date().addingTimeInterval(60) ? chosen : Date().addingTimeInterval(300)
                scheduleStep = 0
                guard let c = item else { return }
                act("schedule", ok: L("mktg_saved")) { try await MarketingApi.scheduleContent(c.id, MkScheduleRequest(plannedAt: mkIso(safe))).data }
            }
        }
    }

    private func startSchedule(_ c: MkContentDto) {
        let now = Date()
        if let planned = mkLocal(c.plannedAt), planned > now {
            scheduleDate = planned
        } else {
            let cal = calCalendar
            let next = cal.dateComponents([.year, .month, .day, .hour], from: now.addingTimeInterval(3600))
            scheduleDate = cal.date(from: next) ?? now.addingTimeInterval(3600)
        }
        scheduleStep = 1
    }

    private func confirmDelete(_ c: MkContentDto) {
        host.confirm(WeDialogSpec(title: L("mktg_delete"), text: c.title, confirm: L("mktg_delete"), danger: true) {
            Task {
                do {
                    _ = try await MarketingApi.deleteContent(c.id)
                    host.showToast(L("mktg_deleted"))
                    host.changed(projectId)
                    host.pop()
                } catch {
                    host.fail(error, L("mktg_error_generic"))
                }
            }
        })
    }

    private func load() async {
        do {
            if project == nil { project = try await MarketingApi.project(projectId).data }
            item = try await MarketingApi.content(itemId).data
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
    }

    private func act(_ key: String, ok: String? = nil, _ call: @escaping () async throws -> MkContentDto?) {
        Task {
            busy = key
            do {
                if let updated = try await call() { item = updated }
                if let ok { host.showToast(ok) }
            } catch {
                host.fail(error, L("mktg_error_generic"))
                reload += 1
            }
            busy = nil
        }
    }
}

private func mkVariantText(_ v: MkVariantDto) -> String {
    let tags = v.hashtags.joined(separator: " ")
    return [v.headline, v.body, v.cta, tags].compactMap { mkNonBlank($0) }.joined(separator: "\n\n")
}

private struct MkVariantCard: View {
    @Environment(\.wa) private var wa
    let variant: MkVariantDto
    let claims: [MkClaimDto]

    var body: some View {
        WaCard(padding: 16) {
            if let headline = mkNonBlank(variant.headline) {
                Text(verbatim: headline).font(.dorr(16, .heavy)).foregroundStyle(wa.ink).lineSpacing(4).fixedSize(horizontal: false, vertical: true)
                    .padding(.bottom, 8)
            }
            if let text = mkNonBlank(variant.body) {
                Text(verbatim: text).font(.dorr(14)).foregroundStyle(wa.ink).lineSpacing(6).fixedSize(horizontal: false, vertical: true)
            }
            if let cta = mkNonBlank(variant.cta) {
                Text(verbatim: cta).font(.dorr(13, .heavy)).foregroundStyle(.white)
                    .padding(.horizontal, 16).padding(.vertical, 8)
                    .background(wa.red, in: Capsule())
                    .padding(.top, 12)
            }
            if !variant.hashtags.isEmpty {
                WeFlow(spacing: 6) {
                    ForEach(Array(variant.hashtags.enumerated()), id: \.offset) { _, tag in MkSmallTag(text: tag, tint: mkBlue) }
                }
                .padding(.top, 10)
            }
            if let brief = mkNonBlank(variant.visualBrief) {
                HStack(alignment: .top, spacing: 8) {
                    Image(systemName: "photo").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.mut)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: L("mktg_visual_brief")).font(.dorr(11.5, .bold)).foregroundStyle(wa.mut)
                        Text(verbatim: brief).font(.dorr(13)).foregroundStyle(wa.ink).lineSpacing(4).fixedSize(horizontal: false, vertical: true)
                    }
                    Spacer(minLength: 0)
                }
                .padding(12)
                .background(wa.field, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .padding(.top, 12)
            }
            if claims.contains(where: { $0.severity == "blocking" }) {
                Text(verbatim: L("mktg_variant_blocked")).font(.dorr(12, .bold)).foregroundStyle(wa.danger).padding(.top, 10)
            }
        }
    }
}

struct MkClaimsBlock: View {
    @Environment(\.wa) private var wa
    let claims: [MkClaimDto]

    var body: some View {
        if claims.isEmpty {
            WaCard(padding: 14) {
                HStack(spacing: 8) {
                    Image(systemName: "checkmark.seal.fill").font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.green)
                    Text(verbatim: L("mktg_claims_clear")).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink)
                }
            }
        } else {
            WaCard(padding: 14) {
                VStack(alignment: .leading, spacing: 10) {
                    ForEach(Array(claims.enumerated()), id: \.offset) { _, claim in row(claim) }
                }
            }
        }
    }

    private func row(_ claim: MkClaimDto) -> some View {
        let blocking = claim.severity == "blocking"
        let place = [claim.variant.map { L("mktg_variant", $0) }, claim.detail.map { "«\($0)»" }].compactMap { $0 }.joined(separator: " · ")
        return HStack(alignment: .top, spacing: 8) {
            Image(systemName: "exclamationmark.triangle.fill").font(.system(size: 14, weight: .semibold))
                .foregroundStyle(blocking ? wa.danger : mkAmber).padding(.top, 2)
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: mkClaimLabel(claim.code)).font(.dorr(13, .bold)).foregroundStyle(wa.ink).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
                if !place.isEmpty { Text(verbatim: place).font(.dorr(12)).foregroundStyle(wa.mut).fixedSize(horizontal: false, vertical: true) }
            }
            Spacer(minLength: 6)
            MkSmallTag(text: L(blocking ? "mktg_blocking" : "mktg_warning"), tint: blocking ? wa.danger : mkAmber)
        }
    }
}

private struct MkEditContentSheet: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let item: MkContentDto
    let onSaved: (MkContentDto) -> Void

    @State private var title = ""
    @State private var objective = ""
    /// [headline, body, cta] per variant.
    @State private var drafts: [[String]] = []
    @State private var tab = 0
    @State private var busy = false
    @State private var filled = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(verbatim: L("mktg_edit")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                WeTextField(value: $title, label: L("mktg_item_title"), limit: 160, capitalize: true)
                WeTextField(value: $objective, label: L("mktg_objective"), limit: 300, capitalize: true)
                if tab < drafts.count {
                    if drafts.count > 1 {
                        HStack(spacing: 8) {
                            ForEach(Array(item.variants.enumerated()), id: \.offset) { i, v in
                                WeChip(text: L("mktg_variant", v.key), selected: tab == i) { tab = i }
                            }
                        }
                    }
                    WeTextField(value: $drafts[tab][0], label: L("mktg_headline"), limit: 120, capitalize: true)
                    WeTextField(value: $drafts[tab][1], label: L("mktg_body"), minLines: 4, limit: 2200, capitalize: true)
                    WeTextField(value: $drafts[tab][2], label: L("mktg_cta"), limit: 60, capitalize: true)
                }
                if !item.isTask && ["APPROVED", "SCHEDULED", "POLICY_REVIEW"].contains(item.status) {
                    WaNote(text: L("mktg_edit_resets"))
                }
                WaButton(text: L("mktg_save"), enabled: title.trimmingCharacters(in: .whitespacesAndNewlines).count >= 2, loading: busy) {
                    Task { await save() }
                }
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 20)
        }
        .frame(maxHeight: UIScreen.main.bounds.height * 0.8)
        .fixedSize(horizontal: false, vertical: true)
        .onAppear {
            guard !filled else { return }
            filled = true
            title = item.title
            objective = item.objective ?? ""
            drafts = item.variants.map { [$0.headline ?? "", $0.body ?? "", $0.cta ?? ""] }
            tab = max(item.variants.firstIndex { $0.key == item.selected } ?? 0, 0)
        }
    }

    private func save() async {
        busy = true
        var payload: [String: JSONValue] = [
            "lock_version": .int(item.lockVersion),
            "title": .string(title.trimmingCharacters(in: .whitespacesAndNewlines)),
        ]
        if let goal = mkNonBlank(objective) { payload["objective"] = .string(goal.trimmingCharacters(in: .whitespacesAndNewlines)) }
        if !item.isTask && !drafts.isEmpty {
            var list: [JSONValue] = []
            for (i, v) in item.variants.enumerated() where i < drafts.count {
                var one: [String: JSONValue] = [
                    "headline": .string(drafts[i][0].trimmingCharacters(in: .whitespacesAndNewlines)),
                    "body": .string(drafts[i][1].trimmingCharacters(in: .whitespacesAndNewlines)),
                    "cta": .string(drafts[i][2].trimmingCharacters(in: .whitespacesAndNewlines)),
                    "hashtags": .array(v.hashtags.map { JSONValue.string($0) }),
                ]
                if let brief = v.visualBrief { one["visual_brief"] = .string(brief) }
                list.append(.object(one))
            }
            payload["variants"] = .array(list)
            if let selected = item.selected { payload["selected"] = .string(selected) }
        }
        do {
            if let updated = try await MarketingApi.updateContent(item.id, payload).data { onSaved(updated) }
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}

struct MkNoteSheet: View {
    @Environment(\.wa) private var wa
    let title: String
    let onSend: (String) -> Void
    @State private var note = ""

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
            WeTextField(value: $note, label: L("mktg_note_optional"), minLines: 3, limit: 500, capitalize: true)
            WaButton(text: title) { onSend(note.trimmingCharacters(in: .whitespacesAndNewlines)) }
        }
        .padding(.horizontal, 20)
        .padding(.bottom, 20)
    }
}

private struct MkPostedSheet: View {
    @Environment(\.wa) private var wa
    let published: Bool
    let onSave: (String?, String?) -> Void
    @State private var url = ""
    @State private var note = ""

    var body: some View {
        let link = url.trimmingCharacters(in: .whitespacesAndNewlines)
        let validUrl = link.isEmpty || link.hasPrefix("http")
        VStack(alignment: .leading, spacing: 12) {
            Text(verbatim: L(published ? "mktg_mark_posted" : "mktg_not_posted")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
            if published {
                WeTextField(value: Binding(get: { url }, set: { url = String($0.trimmingCharacters(in: .whitespaces).prefix(500)) }),
                            label: L("mktg_post_link"), placeholder: "https://", icon: "link", keyboard: .URL, error: !validUrl, ltr: true)
            }
            WeTextField(value: $note, label: L("mktg_note_optional"), minLines: 2, limit: 500, capitalize: true)
            WaButton(text: L("mktg_save"), enabled: validUrl) {
                onSave(link.isEmpty ? nil : link, mkNonBlank(note)?.trimmingCharacters(in: .whitespacesAndNewlines))
            }
        }
        .padding(.horizontal, 20)
        .padding(.bottom, 20)
    }
}

// MARK: - ad studio (U07)

/// The ad studio: a brief in, A/B variants out (the AI writes, the person reviews). The new draft opens when ready.
struct MarketingStudio: View {
    @EnvironmentObject private var host: MkHost
    @ObservedObject private var net = NetworkMonitor.shared
    @Environment(\.wa) private var wa
    let projectId: String

    @State private var channel = "instagram"
    @State private var format = "image"
    @State private var kind = "ad"
    @State private var variants = 2
    @State private var objective = ""
    @State private var brief = ""
    @State private var busy = false
    @State private var waiting: String?
    @State private var jobs: [MkJobDto] = []
    @State private var drafts: [MkContentDto]?
    @State private var reload = 0

    var body: some View {
        let running = jobs.first { $0.type == "content" && !$0.finished }
        MkPage(title: L("mktg_studio"), cta: {
            WaButton(text: L("mktg_studio_write"), icon: "sparkles",
                     enabled: brief.trimmingCharacters(in: .whitespacesAndNewlines).count >= 5 && running == nil,
                     loading: busy || waiting != nil) { Task { await write() } }
        }, content: {
            WaNote(text: L("mktg_studio_note"))
            form.padding(.top, 12)
            if running != nil || waiting != nil {
                WaCard(padding: 14) {
                    Text(verbatim: L("mktg_studio_writing")).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink)
                    MkBar(fraction: Double(running?.progressPct ?? 10) / 100, color: mkBlue).padding(.top, 8)
                }
                .padding(.top, 12)
            }
            draftList
        })
        .task(id: "\(projectId)|\(reload)|\(net.reconnectTick)|\(host.projectChanges(projectId))") { await load() }
    }

    private var form: some View {
        WaCard(padding: 16) {
            VStack(alignment: .leading, spacing: 12) {
                Text(verbatim: L("mktg_channel")).font(.dorr(12.5, .bold)).foregroundStyle(wa.mut)
                WeFlow(spacing: 8) {
                    ForEach(mkChannels.filter { !["offline", "other", "website"].contains($0) }, id: \.self) { ch in
                        WeChip(text: mkChannelLabel(ch), selected: channel == ch) { channel = ch }
                    }
                }
                Text(verbatim: L("mktg_format")).font(.dorr(12.5, .bold)).foregroundStyle(wa.mut)
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(mkFormats, id: \.self) { f in
                            WeChip(text: mkFormatLabel(f), selected: format == f) { format = f }
                        }
                    }
                }
                HStack(spacing: 8) {
                    ForEach(["ad", "post"], id: \.self) { k in
                        WeChip(text: mkContentKindLabel(k), selected: kind == k) { kind = k }
                    }
                    Spacer(minLength: 4)
                    ForEach([2, 3], id: \.self) { n in
                        WeChip(text: L("mktg_n_variants", n), selected: variants == n) { variants = n }
                    }
                }
                WeTextField(value: $objective, label: L("mktg_objective"), placeholder: L("mktg_objective_hint"), limit: 300, capitalize: true)
                WeTextField(value: $brief, label: L("mktg_brief"), placeholder: L("mktg_brief_hint"), minLines: 4, limit: 1000, capitalize: true)
                Text(verbatim: "\u{2066}\(brief.count)/1000\u{2069}").font(.dorr(11)).foregroundStyle(wa.soft).frame(maxWidth: .infinity, alignment: .trailing)
            }
        }
    }

    @ViewBuilder
    private var draftList: some View {
        WaSectionTitle(title: L("mktg_drafts"))
        if let list = drafts {
            if list.isEmpty {
                Text(verbatim: L("mktg_no_drafts")).font(.dorr(13)).foregroundStyle(wa.mut).padding(.horizontal, 4)
            } else {
                VStack(spacing: 10) {
                    ForEach(list) { item in
                        MkContentRow(item: item, action: { host.push(.content(projectId: projectId, itemId: item.id)) })
                    }
                }
            }
        } else {
            WaSkeleton(cornerRadius: 20).frame(height: 76)
        }
    }

    private func load() async {
        do {
            jobs = try await MarketingApi.jobs(projectId).data ?? []
            drafts = (try await MarketingApi.contentList(projectId, status: "DRAFT,POLICY_REVIEW,REJECTED").data ?? []).filter { !$0.isTask }
            if let id = waiting, let mine = jobs.first(where: { $0.id == id }), mine.finished {
                waiting = nil
                if mine.status == "COMPLETED", let created = mine.created?.first {
                    host.push(.content(projectId: projectId, itemId: created))
                } else {
                    host.showToast(L("mktg_error_generic"), error: true)
                }
            }
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
    }

    private func write() async {
        busy = true
        do {
            let goal = objective.trimmingCharacters(in: .whitespacesAndNewlines)
            let started = try await MarketingApi.generateContent(projectId, MkStudioRequest(
                channel: channel, brief: brief.trimmingCharacters(in: .whitespacesAndNewlines), kind: kind, format: format,
                objective: goal.isEmpty ? nil : goal, variants: variants
            )).data
            waiting = started?.job?.id
            host.showToast(L("mktg_studio_started"))
            reload += 1
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}
