import SwiftUI

// androidApp `freelance/workroom/WorkroomSupport.kt` — what the Workroom screens share: the live counters of a project's channel, the
// agreement's parts and people for the forms, the paging of an answer that is an object, dates and sizes, the colours and words of a
// status, and the small pieces (a tag, an empty page, a row of chips, the part picker, the day field).

/// How many rows a page of the Workroom asks for (Android `WORK_PAGE`).
let workPage = 30

// MARK: - Live

/// "Something changed" counters of one project's Workroom, bumped when its private channel says so (`freelance.workroom`: a task, a file, a
/// note or a decision). A screen reads its list again when its counter moves — one request per event, nothing on a timer.
@MainActor
final class WorkroomLive: ObservableObject {
    @Published var tasks = 0
    @Published var files = 0
    @Published var comments = 0
    @Published var decisions = 0
    /// Any step at all: the timeline reads again.
    @Published var timeline = 0

    /// An event of the channel (`ChatRealtime` adds the `project` it is about); the ones of another project or another kind are ignored.
    func take(_ event: ChatEvent, project: String) {
        guard event.name == ChatRealtime.workroomEvent, event.str("project") == project else { return }
        switch event.str("kind") {
        case "task": tasks += 1
        case "file": files += 1
        // A note changes the count on its file's card as well as the file's own page.
        case "comment": comments += 1; files += 1
        case "decision": decisions += 1
        default: break
        }
        timeline += 1
    }
}

/// Listens to the project's Workroom channel while the screen is on, and counts what arrives into `live`.
@MainActor
private struct WorkroomLiveListener: ViewModifier {
    let project: String
    let live: WorkroomLive

    func body(content: Content) -> some View {
        content
            .onAppear { ChatRealtime.shared.watchWorkroom(project) }
            .onDisappear { ChatRealtime.shared.unwatchWorkroom(project) }
            .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { event in live.take(event, project: project) }
    }
}

extension View {
    /// Android `rememberWorkroomLive`: the screen listens to the project's Workroom channel while it is on.
    func workroomLive(_ project: String, _ live: WorkroomLive) -> some View {
        modifier(WorkroomLiveListener(project: project, live: live))
    }
}

// MARK: - The agreement's parts and people

/// What the forms need from the agreement: its parts (to tie a task, a file or a decision to one) and the freelancer's name.
@MainActor
final class WorkroomPeople: ObservableObject {
    @Published var parts: [WorkPartDto] = []
    @Published var freelancer = ""

    /// Reads the agreement (when the screen opens and when the connection returns); a failed read just leaves the lists empty.
    func read(_ project: String) async {
        do {
            if let a = try await FreelanceApi.agreement(project).data {
                parts = (a.milestones ?? []).filter { $0.state != "cancelled" }.map { WorkPartDto(uuid: $0.uuid, title: $0.title, position: $0.position) }
                freelancer = a.freelancer?.name ?? ""
            }
        } catch {
        }
    }
}

// MARK: - Paging

/// A page-by-page list for an answer that is an object (the rows, the numbers for the chips, what I `can` do): the rows go to the pager and
/// the first page's whole answer to `meta`. The envelope is rebuilt around the rows so the app's own `PagedRows` does the paging.
func workRows<R: Decodable, T: Decodable>(_ answer: ApiEnvelope<R>, page: Int, meta: (R?) -> Void, rows: (R) -> [T]) -> ApiEnvelope<[T]> {
    if page == 1 { meta(answer.data) }
    return ApiEnvelope<[T]>(success: answer.success, status: answer.status, code: answer.code, message: answer.message,
                            data: answer.data.map(rows), pagination: answer.pagination)
}

// MARK: - Dates and sizes

private func wrFormatter(_ pattern: String) -> DateFormatter {
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.calendar = Calendar(identifier: .gregorian)
    f.timeZone = TimeZone.autoupdatingCurrent
    f.dateFormat = pattern
    return f
}

private let wrDayFormat = wrFormatter("dd/MM/yyyy")
private let wrClockFormat = wrFormatter("HH:mm")
private let wrServerDay = wrFormatter("yyyy-MM-dd")

/// A server day ("2026-11-20", or a full time) as "20/11/2026"; empty when it cannot be read.
func wrDay(_ date: String?) -> String {
    guard let date, date.count >= 10 else { return "" }
    let p = date.prefix(10).split(separator: "-")
    guard p.count == 3, p[0].count == 4, p[1].count == 2, p[2].count == 2, Int(p[0]) != nil, Int(p[1]) != nil, Int(p[2]) != nil else { return "" }
    return "\(p[2])/\(p[1])/\(p[0])"
}

/// The day a server time falls on, in the phone's zone ("27/10/2026"), to group a timeline by.
func wrLocalDay(_ iso: String?) -> String { parseInstant(iso).map { wrDayFormat.string(from: $0) } ?? "" }

func wrLocalTime(_ iso: String?) -> String { parseInstant(iso).map { wrClockFormat.string(from: $0) } ?? "" }

func wrLocalDateTime(_ iso: String?) -> String {
    parseInstant(iso).map { wrDayFormat.string(from: $0) + " " + wrClockFormat.string(from: $0) } ?? ""
}

/// "1.4 MB" · "230 KB".
func wrSize(_ bytes: Int) -> String {
    if bytes >= 1_048_576 { return String(format: "%.1f MB", Double(bytes) / 1_048_576.0) }
    if bytes >= 1024 { return "\(bytes / 1024) KB" }
    return "\(bytes) B"
}

/// The icon of a file by its kind (an SF Symbol).
func fileIcon(_ mime: String, _ name: String) -> String {
    let ext = (name as NSString).pathExtension.lowercased()
    if mime.hasPrefix("image/") { return "photo" }
    if mime == "application/pdf" || ext == "pdf" { return "doc.richtext" }
    if mime.hasPrefix("video/") { return "film" }
    if mime.hasPrefix("audio/") { return "music.note" }
    if ["zip", "rar", "7z", "gz", "tar"].contains(ext) { return "archivebox" }
    if ["doc", "docx", "txt", "rtf", "odt", "xls", "xlsx", "ppt", "pptx", "csv"].contains(ext) { return "doc.text" }
    return "doc"
}

/// "2. Design" — a part by its number and name.
func wrPartName(_ p: WorkPartDto) -> String { (p.position.map { "\($0). " } ?? "") + (p.title ?? "") }

// MARK: - Tones and words

let wrGray = Color(hex: 0x6B7280)
let wrBlue = Color(hex: 0x0EA5E9)
let wrGreen = Color(hex: 0x16A34A)
let wrAmber = Color(hex: 0xD97706)
let wrRed = Color(hex: 0xDC2626)
let wrViolet = Color(hex: 0x7C3AED)

func taskTone(_ status: String) -> Color {
    switch status {
    case "doing": return wrBlue
    case "blocked": return wrRed
    case "done": return wrGreen
    default: return wrGray
    }
}

func taskLabel(_ status: String) -> String {
    switch status {
    case "doing": return L("wr_task_doing")
    case "blocked": return L("wr_task_blocked")
    case "done": return L("wr_task_done")
    default: return L("wr_task_todo")
    }
}

func decisionTone(_ status: String) -> Color {
    switch status {
    case "agreed": return wrGreen
    case "declined": return wrRed
    case "superseded": return wrGray
    default: return wrAmber
    }
}

func decisionLabel(_ status: String) -> String {
    switch status {
    case "agreed": return L("wr_dec_agreed")
    case "declined": return L("wr_dec_declined")
    case "superseded": return L("wr_dec_superseded")
    default: return L("wr_dec_proposed")
    }
}

/// Who a side is, said to the client: «You» for the client's own side, the freelancer's name for the other.
func sideName(_ side: String?, _ freelancer: String) -> String {
    side == "client" ? L("wr_you") : (freelancer.isEmpty ? L("wr_freelancer") : freelancer)
}

// MARK: - Small pieces

/// A small tag: an icon and a few words on a soft tint (a part, a day, who it is for).
struct WrTag: View {
    @Environment(\.wa) private var wa
    let text: String
    var icon: String?
    var color: Color?

    var body: some View {
        let tint = color ?? wa.settingsMut
        HStack(spacing: 5) {
            if let icon { Image(systemName: icon).font(.system(size: 11, weight: .semibold)) }
            Text(verbatim: text).font(.dorr(11.5, .semibold)).lineLimit(1)
        }
        .foregroundStyle(tint)
        .padding(.horizontal, 9).padding(.vertical, 4)
        .background(tint.opacity(wa.night ? 0.20 : 0.10), in: Capsule())
        .frame(maxWidth: 280, alignment: .leading)
    }
}

/// A small action under a card: an icon and a word in its own colour.
struct WrLink: View {
    let text: String
    let icon: String
    let color: Color
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 5) {
                Image(systemName: icon).font(.system(size: 13, weight: .semibold))
                Text(verbatim: text).font(.dorr(12.5, .bold))
            }
            .foregroundStyle(color)
            .padding(.horizontal, 4).padding(.vertical, 6)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// What a list says when it has no rows: an icon on a soft disc, a title and a line.
struct WrEmpty: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let text: String

    var body: some View {
        VStack(spacing: 10) {
            Image(systemName: icon).font(.system(size: 28, weight: .semibold)).foregroundStyle(wa.accent)
                .frame(width: 64, height: 64).background(wa.accent.opacity(0.10), in: Circle())
                .accessibilityHidden(true)
            Text(verbatim: title).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
            Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).lineSpacing(4)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 36).padding(.horizontal, 24)
    }
}

/// Grey blocks that stand for the cards of a list while its first page is read.
struct WrSkeleton: View {
    var count = 3
    var height: CGFloat = 96

    var body: some View {
        ForEach(0..<count, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: height) }
    }
}

/// A field as typed, without the spaces and lines around it.
func wrTrim(_ text: String) -> String { text.trimmingCharacters(in: .whitespacesAndNewlines) }

/// A row of chips that scrolls sideways (the filters of a list).
struct WrChipRow<Content: View>: View {
    @ViewBuilder let content: () -> Content

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) { content() }
        }
    }
}

/// The parts of the agreement to tie something to: «No part» first, then each part by its number.
struct PartPicker: View {
    let parts: [WorkPartDto]
    let selected: String?
    let onPick: (String?) -> Void

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                FlChip(text: L("wr_no_part"), selected: selected == nil) { onPick(nil) }
                ForEach(parts, id: \.uuid) { p in
                    FlChip(text: wrPartName(p), selected: selected == p.uuid) { onPick(p.uuid) }
                }
            }
        }
    }
}

// MARK: - A day to pick

/// A field that opens the calendar; `value` is "yyyy-MM-dd" (empty: none). Clearing is the cross in the field. `earliest` is the first day a
/// new value may take (a day already past may stay on a task that has it).
struct WrDateField: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String
    let onPick: (String) -> Void
    var earliest: Date? = Calendar.current.startOfDay(for: Date())
    @State private var open = false
    @State private var draft = Date()

    private var current: Date? { value.count >= 10 ? wrServerDay.date(from: String(value.prefix(10))) : nil }

    private var range: ClosedRange<Date> {
        let upper = Calendar.current.date(byAdding: .year, value: 3, to: Date()) ?? Date().addingTimeInterval(94_608_000)
        guard var lower = earliest else { return Date.distantPast...upper }
        if let day = current, day < lower { lower = day }
        return lower...max(upper, lower)
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 8)
            HStack(spacing: 8) {
                Button {
                    draft = current ?? max(earliest ?? Date(), Calendar.current.startOfDay(for: Date()))
                    open.toggle()
                } label: {
                    HStack(spacing: 8) {
                        Image(systemName: "calendar").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.accent.opacity(0.8))
                        Text(verbatim: value.isEmpty ? L("wr_no_day") : wrDay(value)).font(.dorr(15))
                            .foregroundStyle(value.isEmpty ? wa.settingsMut : wa.settingsInk)
                        Spacer(minLength: 0)
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                if !value.isEmpty {
                    Button { onPick(""); open = false } label: {
                        Image(systemName: "xmark.circle.fill").font(.system(size: 18)).foregroundStyle(wa.settingsMut).padding(4)
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("fl_remove"))
                }
            }
            .padding(.horizontal, 14)
            .frame(maxWidth: .infinity, minHeight: 46, alignment: .leading)
            .background(wa.night ? wa.bg : Color(hex: 0xFBF7F8), in: Capsule())
            .overlay(Capsule().stroke(wa.night ? wa.line : Color(hex: 0xF3D5DB), lineWidth: 1))
            if open {
                VStack(spacing: 6) {
                    DatePicker("", selection: $draft, in: range, displayedComponents: .date)
                        .datePickerStyle(.graphical)
                        .labelsHidden()
                        .tint(wa.accent)
                        .environment(\.locale, AppLocale.locale)
                    HStack(spacing: 10) {
                        FlAction(text: L("common_cancel")) { open = false }
                        FlAction(text: L("wr_ok"), tone: .primary) {
                            onPick(wrServerDay.string(from: draft))
                            open = false
                        }
                    }
                }
                .padding(.top, 8)
            }
        }
    }
}
