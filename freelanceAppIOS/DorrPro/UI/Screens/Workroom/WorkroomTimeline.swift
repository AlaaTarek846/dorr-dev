import SwiftUI

// freelanceApp `ui/screens/workroom/WorkroomTimeline.kt`.

/// The project's timeline: every step of the work, newest first (read as pages; a filter picks one kind of step).
@MainActor
final class TimelineState: ObservableObject {
    let project: String
    @Published var filter = "all"
    let feed = PagedRows<WorkEventDto>(perPage: WORK_PAGE, key: { timelineKey($0) })

    init(project: String) {
        self.project = project
        feed.reset { [weak self] page in
            guard let self else { return .offline }
            return await self.fetch(page)
        }
    }

    private func fetch(_ page: Int) async -> ApiResult<[WorkEventDto]> {
        let f = filter
        let r = await WorkroomApi.timeline(
            project, kind: (f != "all" && f != "mine") ? f : nil, mine: f == "mine" ? 1 : nil, perPage: WORK_PAGE, page: page
        )
        return r.rows { $0.events }
    }
}

/// What tells one step from another (the server's key; a step without one is told by its moment and its words).
private func timelineKey(_ e: WorkEventDto) -> String {
    if let key = e.key, !key.isEmpty { return key }
    return (e.at ?? "") + "|" + (e.label ?? "")
}

/// The icon and colour of a kind of step.
private func kindIcon(_ kind: String?) -> (icon: String, tint: Color) {
    switch kind ?? "" {
    case "task": return ("checklist", Tints.blue)
    case "file": return ("paperclip", Tints.teal)
    case "comment": return ("bubble.left", Tints.violet)
    case "decision": return ("hammer.fill", Tints.amber)
    case "agreement": return ("hands.sparkles.fill", Tints.green)
    case "milestone": return ("flag.fill", Tints.green)
    case "dispute": return ("exclamationmark.triangle.fill", Tints.rose)
    case "change": return ("arrow.left.arrow.right", Tints.amber)
    case "review": return ("star.fill", Tints.amber)
    case "rights": return ("wallet.pass.fill", Tints.teal)
    default: return ("clock.arrow.circlepath", Color(hex: 0x9CA3AF))
    }
}

/// The tab of the timeline: one line per step, under the heading of its day.
struct TimelineTab: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: TimelineState

    var body: some View {
        FeedList(
            feed: state.feed, keyOf: { timelineKey($0) },
            empty: WorkEmpty(icon: "clock.arrow.circlepath", title: L("wr_tl_empty_title"), text: L("wr_tl_empty_body")),
            header: { FilterChips(options: filters, selected: state.filter, onSelect: select) },
            row: { index, e in
                VStack(alignment: .leading, spacing: 8) {
                    if let title = dayHeading(at: index, e) {
                        Text(verbatim: title).font(.dorr(12, .heavy)).foregroundStyle(wa.settingsMut)
                            .padding(.top, index <= 0 ? 0 : 8)
                    }
                    EventRow(e: e)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
        )
    }

    private var filters: [FilterOption] {
        [
            FilterOption(key: "all", label: L("wr_f_all")),
            FilterOption(key: "mine", label: L("wr_f_mine")),
            FilterOption(key: "task", label: L("wr_k_task")),
            FilterOption(key: "file", label: L("wr_k_file")),
            FilterOption(key: "comment", label: L("wr_k_comment")),
            FilterOption(key: "decision", label: L("wr_k_decision")),
            FilterOption(key: "milestone", label: L("wr_k_milestone")),
        ]
    }

    private func select(_ key: String) {
        guard key != state.filter else { return }
        state.filter = key
        Task { await state.feed.reload() }
    }

    /// The day's heading over the first step of that day (nil under the others).
    private func dayHeading(at index: Int, _ e: WorkEventDto) -> String? {
        guard let day = dayKey(e.at) else { return nil }
        let rows = state.feed.rows
        if index > 0, index < rows.count, dayKey(rows[index - 1].at) == day { return nil }
        switch daysAgo(e.at) {
        case 0?: return L("wr_today")
        case 1?: return L("wr_yesterday")
        default: return workDayHeading(day)
        }
    }
}

/// One step: the icon of its kind, what happened, and who did it and when.
private struct EventRow: View {
    @Environment(\.wa) private var wa
    let e: WorkEventDto

    var body: some View {
        let style = kindIcon(e.kind)
        WorkroomCard {
            HStack(alignment: .top, spacing: 12) {
                Image(systemName: style.icon).font(.system(size: 16, weight: .semibold)).foregroundStyle(style.tint)
                    .frame(width: 34, height: 34)
                    .background(style.tint.opacity(0.14), in: Circle())
                VStack(alignment: .leading, spacing: 3) {
                    Text(verbatim: e.label ?? "").font(.dorr(13.5, .semibold)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                    let line = whoLine
                    if !line.isEmpty { Text(verbatim: line).font(.dorr(11.5)).foregroundStyle(wa.settingsMut) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
    }

    /// «who · time» — «me» when it was my side.
    private var whoLine: String {
        var parts: [String] = []
        let who = (e.by?.name ?? e.actor)?.trimmingCharacters(in: .whitespacesAndNewlines)
        if let who, !who.isEmpty { parts.append(e.mine == true ? L("wr_side_me") : who) }
        if let clock = workClock(e.at) { parts.append(clock) }
        return parts.joined(separator: " · ")
    }
}
