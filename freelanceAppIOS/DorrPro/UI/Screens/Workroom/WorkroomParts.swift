import SwiftUI
import UniformTypeIdentifiers

// freelanceApp `ui/screens/workroom/WorkroomParts.kt` — the small pieces every Workroom page shares: the paging size, how a moment and a size
// are written, who is who, the colour and icon of a status, the cards and pills, the sheet frame and the way a file is opened.

/// The rows of a Workroom page (a full page means another one follows).
let WORK_PAGE = 30

let WorkRed = Color(hex: 0xDC2626)

/// My side in this app is the freelancer's; the other is the client.
let MY_SIDE = "freelancer"

extension ApiResult {
    /// A paged call's answer reduced to its rows (the screen keeps the rest — the numbers, what I can do — as a side effect of the fetch).
    func rows<Row>(_ extract: (T) -> [Row]?) -> ApiResult<[Row]> {
        switch self {
        case .ok(let data, let message): return .ok(extract(data) ?? [], message: message)
        case .failed(let error): return .failed(error)
        case .offline: return .offline
        }
    }
}

extension Array where Element == MilestoneDto {
    /// The parts of an agreement a Workroom item can be tied to (a cancelled part is not offered).
    func asParts() -> [WorkPartDto] {
        filter { $0.status != "cancelled" }.map { WorkPartDto(uuid: $0.uuid, title: $0.title, position: $0.position) }
    }
}

extension JSONAny {
    /// A text field of a live event's data.
    func text(_ key: String) -> String? { self[key]?.str }
}

// MARK: - time and size

private var workFormatters: [String: DateFormatter] = [:]

/// One formatter per pattern and language, kept (a list writes a moment per row). `latin`: ASCII digits whatever the language (a clock).
private func workFormatter(_ pattern: String, latin: Bool = false) -> DateFormatter {
    let key = pattern + "|" + (latin ? "latin" : AppLocale.current)
    if let cached = workFormatters[key] { return cached }
    let f = DateFormatter()
    f.locale = latin ? Locale(identifier: "en_US_POSIX") : AppLocale.locale
    f.timeZone = .current
    f.dateFormat = pattern
    workFormatters[key] = f
    return f
}

private func workDate(_ date: Date) -> String {
    let sameYear = Calendar.current.component(.year, from: date) == Calendar.current.component(.year, from: Date())
    return workFormatter(sameYear ? "d MMM" : "d MMM yyyy").string(from: date)
}

/// A moment as a day: today as the time, this year as «12 Nov», older with the year.
func workWhen(_ iso: String?) -> String? {
    guard let at = parseInstant(iso) else { return nil }
    if Calendar.current.isDateInToday(at) { return workFormatter("HH:mm", latin: true).string(from: at) }
    return workDate(at)
}

func workDateTime(_ iso: String?) -> String? {
    guard let at = parseInstant(iso) else { return nil }
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.timeZone = .current
    f.dateStyle = .medium
    f.timeStyle = .short
    return f.string(from: at)
}

/// A day without a time (`2026-11-20`): «20 Nov» in this year.
func workDay(_ day: String?) -> String? {
    guard let day, let date = DateField.parse(day) else { return nil }
    return workDate(date)
}

/// A date (the start of a day) in words, for a day's heading.
func workDayHeading(_ date: Date) -> String { workDate(date) }

func workClock(_ iso: String?) -> String? {
    guard let at = parseInstant(iso) else { return nil }
    return workFormatter("HH:mm", latin: true).string(from: at)
}

func fileSize(_ bytes: Int) -> String { ByteCountFormatter.string(fromByteCount: Int64(bytes), countStyle: .file) }

// MARK: - who

func sideName(_ side: String?) -> String { L((side ?? MY_SIDE) == MY_SIDE ? "wr_side_me" : "wr_side_client") }

// MARK: - statuses

private let workGrey = Color(hex: 0x9CA3AF)

let TASK_STATUSES = ["todo", "doing", "blocked", "done"]

func taskTint(_ status: String) -> Color {
    switch status {
    case "doing": return Tints.blue
    case "blocked": return Tints.rose
    case "done": return Tints.green
    default: return workGrey
    }
}

func taskIcon(_ status: String) -> String {
    switch status {
    case "doing": return "play.circle.fill"
    case "blocked": return "nosign"
    case "done": return "checkmark.circle.fill"
    default: return "circle"
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

func decisionTint(_ status: String) -> Color {
    switch status {
    case "agreed": return Tints.green
    case "declined": return Tints.rose
    case "superseded": return workGrey
    default: return Tints.amber
    }
}

func decisionIcon(_ status: String) -> String {
    switch status {
    case "agreed": return "checkmark.circle.fill"
    case "declined": return "xmark.circle.fill"
    case "superseded": return "arrow.left.arrow.right"
    default: return "hourglass"
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

/// The icon and colour of a kind of file (by its type).
func fileIcon(_ mime: String, _ name: String) -> (icon: String, tint: Color) {
    let lower = name.lowercased()
    if mime.hasPrefix("image/") { return ("photo", Tints.violet) }
    if mime == "application/pdf" || lower.hasSuffix(".pdf") { return ("doc.richtext", Tints.rose) }
    if mime.contains("zip") || mime.contains("compressed") || lower.hasSuffix(".zip") || lower.hasSuffix(".rar") { return ("doc.zipper", Tints.amber) }
    let office = ["word", "text", "sheet", "presentation", "excel", "powerpoint"]
    if office.contains(where: { mime.contains($0) }) { return ("doc.text", Tints.blue) }
    return ("doc", Tints.teal)
}

// MARK: - small parts

/// A tonal card of the Workroom: tap it to open, content in a padded column.
struct WorkroomCard<Content: View>: View {
    var accent: Color?
    var onClick: (() -> Void)?
    @ViewBuilder let content: () -> Content

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: Corners.card, style: .continuous)
        let tint: Color = accent?.opacity(0.06) ?? Color.clear
        let card = VStack(alignment: .leading, spacing: 10) { content() }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(tint, in: shape)
            .appCard(Corners.card)
            .contentShape(shape)
        // A tap gesture (not a Button): a card holds buttons of its own (a task's status, a decision's answers) that must keep working.
        if let onClick {
            card.onTapGesture(perform: onClick).accessibilityAddTraits(.isButton)
        } else {
            card
        }
    }
}

/// The tag of the part an item is tied to.
struct PartTag: View {
    @Environment(\.wa) private var wa
    let part: WorkPartDto

    var body: some View {
        Text(verbatim: L("wr_part_tag", part.position ?? 0, part.title ?? ""))
            .font(.dorr(11.5, .semibold)).foregroundStyle(wa.settingsMut).lineLimit(1)
            .padding(.horizontal, 9)
            .padding(.vertical, 3)
            .background(wa.settingsMut.opacity(0.12), in: Capsule())
    }
}

/// An inside item of a team (the client never sees it).
struct TeamOnlyPill: View {
    var body: some View { StatusPill(text: L("wr_team_only"), tint: Tints.violet, icon: "lock.fill") }
}

/// The server gave this version of the client's view as a small preview only (the work is not approved yet).
struct PreviewOnlyPill: View {
    var body: some View { StatusPill(text: L("wr_preview_only"), tint: Tints.amber, icon: "eye.fill") }
}

/// The Workroom is over (the agreement is completed): everything is read only.
struct ReadOnlyBanner: View {
    @Environment(\.wa) private var wa

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: "lock.fill").font(.system(size: 14, weight: .semibold)).foregroundStyle(Tints.amber).frame(width: 18, height: 18)
            Text(L("wr_read_only")).font(.dorr(12.5)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Tints.amber.opacity(0.12), in: RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
    }
}

/// A small muted caption over a field of a sheet.
struct WorkLabel: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        Text(verbatim: text).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
    }
}

/// Picks the part an item is tied to: «No part» or one of the agreement's parts.
struct PartPicker: View {
    let parts: [WorkPartDto]
    let selected: String?
    let onSelect: (String?) -> Void

    var body: some View {
        if !parts.isEmpty {
            let none: [(value: String, label: String)] = [(value: "", label: L("wr_no_part"))]
            let each: [(value: String, label: String)] = parts.map { (value: $0.uuid ?? "", label: L("wr_part_tag", $0.position ?? 0, $0.title ?? "")) }
            ChipRow(options: none + each, selected: [selected ?? ""], onToggle: { onSelect($0.isEmpty ? nil : $0) })
        }
    }
}

/// The frame of a bottom sheet of the Workroom (shown with `.cmSheet`): a title and a scrolling column for the content — the keyboard
/// never covers the fields.
struct WorkSheet<Content: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    @ViewBuilder let content: () -> Content

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                content()
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 18)
            .padding(.top, 24)
            .padding(.bottom, 24)
        }
        .scrollDismissesKeyboard(.interactively)
        .background(wa.settingsCard.ignoresSafeArea())
    }
}

/// An error line of a sheet.
struct WorkError: View {
    let text: String?

    var body: some View {
        if let text {
            Text(verbatim: text).font(.dorr(12.5)).foregroundStyle(WorkRed).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
        }
    }
}

/// A line of a sheet: a small muted label over its value.
struct WorkFact: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(verbatim: label).font(.dorr(11.5, .semibold)).foregroundStyle(wa.settingsMut)
            Text(verbatim: value).font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(4)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

/// A round number badge (a count on a card).
struct CountDot: View {
    @Environment(\.wa) private var wa
    let count: Int
    var tint: Color?

    var body: some View {
        if count > 0 {
            Text(verbatim: count > 99 ? "99+" : String(count)).font(.dorr(10.5, .black)).foregroundStyle(.white)
                .padding(.horizontal, 7)
                .padding(.vertical, 2)
                .background(tint ?? wa.red, in: Capsule())
                .environment(\.layoutDirection, .leftToRight)
        }
    }
}

/// A form switch row: a title and its line on one side, the switch on the other.
struct WorkSwitchRow: View {
    @Environment(\.wa) private var wa
    let title: String
    let hint: String
    let on: Bool
    let action: () -> Void

    var body: some View {
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: title).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                Text(verbatim: hint).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            RedToggle(on: on, action: action)
        }
    }
}

/// A tinted action tile of a card (a file's, a decision's): an icon over a short label.
struct WorkAction: View {
    let icon: String
    let label: String
    let tint: Color
    var onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            VStack(spacing: 3) {
                Image(systemName: icon).font(.system(size: 16, weight: .semibold)).frame(width: 20, height: 20)
                Text(verbatim: label).font(.dorr(11.5, .bold)).lineLimit(1)
            }
            .foregroundStyle(tint)
            .frame(maxWidth: .infinity)
            .padding(.vertical, 10)
            .background(tint.opacity(0.10), in: RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
            .contentShape(RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - files on the phone

/// Writes `data` to `caches/workroom/name` for the viewer; nil when it can't.
private func workCacheFile(_ data: Data, name: String) -> ProfileShownFile? {
    guard let caches = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first else { return nil }
    let dir = caches.appendingPathComponent("workroom", isDirectory: true)
    try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
    let url = dir.appendingPathComponent(name)
    do { try data.write(to: url, options: .atomic) } catch { return nil }
    return ProfileShownFile(url: url)
}

/// Reads one version of a file with my own sign-in (never a public link), keeps it in the cache and returns it for the system viewer
/// (`.sheet(item:) { ProfileFileViewer(url: $0.url) }`). Nil when it could not be read or kept.
func openVersion(_ project: String, _ file: String, _ number: Int, _ name: String, _ mime: String) async -> ProfileShownFile? {
    guard let data = await WorkroomApi.download(project, file, number), !data.isEmpty else { return nil }
    var safe = String(name.filter { $0.isLetter || $0.isNumber || "._- ".contains($0) }.suffix(80))
    if safe.trimmingCharacters(in: .whitespaces).isEmpty { safe = "file" }
    // Quick Look picks the viewer by the extension: a name without one takes it from the type (else from the bytes).
    if (safe as NSString).pathExtension.isEmpty {
        let known = mime.isEmpty ? nil : UTType(mimeType: mime)?.preferredFilenameExtension
        safe += "." + (known ?? profileSniffExtension(data))
    }
    return workCacheFile(data, name: "\(file.prefix(8))_v\(number)_\(safe)")
}
