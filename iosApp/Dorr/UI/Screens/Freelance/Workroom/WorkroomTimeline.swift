import SwiftUI

// androidApp `freelance/workroom/WorkroomTimeline.kt` — the Workroom's timeline.

/// The kinds a step can be filtered by (`nil`: all).
private let timelineKinds: [String?] = [nil, "task", "file", "comment", "decision", "milestone", "change"]

private func kindIcon(_ kind: String) -> String {
    switch kind {
    case "task": return "checkmark.circle"
    case "file": return "paperclip"
    case "comment": return "text.bubble"
    case "decision": return "hammer"
    case "milestone": return "flag.fill"
    case "change": return "arrow.left.arrow.right"
    case "dispute": return "exclamationmark.triangle"
    case "review": return "star.fill"
    case "rights": return "lock.fill"
    case "agreement": return "person.2.fill"
    case "": return "clock.arrow.circlepath"
    default: return "circle.fill"
    }
}

private func kindTone(_ kind: String) -> Color {
    switch kind {
    case "task": return wrBlue
    case "file": return wrViolet
    case "comment": return Color(hex: 0x14B8A6)
    case "decision": return wrAmber
    case "milestone": return wrGreen
    case "change": return Color(hex: 0x6366F1)
    case "dispute": return wrRed
    case "review": return wrAmber
    default: return wrGray
    }
}

private func kindLabel(_ kind: String?) -> String {
    switch kind ?? "" {
    case "task": return L("wr_time_tasks")
    case "file": return L("wr_time_files")
    case "comment": return L("wr_time_notes")
    case "decision": return L("wr_time_decisions")
    case "milestone": return L("wr_time_parts")
    case "change": return L("wr_time_changes")
    default: return L("wr_all")
    }
}

/// The steps of one day.
private struct TimelineDay: Identifiable {
    let day: String
    var events: [WorkEventDto]
    var id: String { day }
}

/// The Workroom's timeline: what happened on the project, newest first and grouped by day — tasks, files, notes, decisions and the steps of
/// the agreement (parts, changes, disputes). The text of a step is ready (in my language) and never carries what either side wrote or any
/// amount. A step about a file opens it; one about a task or a decision opens that part of the Workroom. Read when it opens, on a live
/// event, on a pull and when the connection returns.
@MainActor
struct TimelineTab: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let project: String
    let freelancer: String
    @ObservedObject var live: WorkroomLive
    /// Whether this tab is the one on show: a hidden tab keeps what it holds but does not read again until it is back (Android drops the tab).
    let active: Bool
    let onOpenFile: (String) -> Void
    let onOpenTab: (Int) -> Void

    @State private var kind: String?
    @State private var mineOnly = false
    /// The filter the list was last started for (a new filter starts again from its skeleton; a live event only reads again).
    @State private var applied: String?
    @StateObject private var paged = PagedRows<WorkEventDto>(key: { $0.id })

    private var filterKey: String { "\(kind ?? "")|\(mineOnly)" }

    /// The steps grouped by the day (in the phone's zone), the order of the answer kept.
    private var days: [TimelineDay] {
        var out: [TimelineDay] = []
        for e in paged.rows {
            let day = wrLocalDay(e.at)
            if let i = out.firstIndex(where: { $0.day == day }) { out[i].events.append(e) } else { out.append(TimelineDay(day: day, events: [e])) }
        }
        return out
    }

    /// A step about a file opens it (a note, the file it is on); one about a task or a decision opens its tab.
    private func open(_ e: WorkEventDto) {
        let subject = e.subject
        let type = subject?.type == "comment" ? subject?.on?.type : subject?.type
        let uuid = subject?.type == "comment" ? subject?.on?.uuid : subject?.uuid
        switch type ?? "" {
        case "file": if let uuid { onOpenFile(uuid) }
        case "task": onOpenTab(0)
        case "decision": onOpenTab(2)
        default: break
        }
    }

    var body: some View {
        ScrollView {
            LazyVStack(spacing: 0) {
                WrChipRow {
                    ForEach(timelineKinds, id: \.self) { k in
                        FlChip(text: kindLabel(k), selected: kind == k, icon: kindIcon(k ?? "")) { kind = k }
                    }
                    FlChip(text: L("wr_task_mine"), selected: mineOnly, icon: "person.fill") { mineOnly.toggle() }
                }
                .padding(.bottom, 10)
                if !paged.loaded && paged.error == nil {
                    VStack(spacing: 10) { WrSkeleton(count: 4, height: 64) }
                } else if paged.rows.isEmpty, let failed = paged.error {
                    FlRetry(message: failed)
                } else if paged.rows.isEmpty {
                    WrEmpty(icon: "clock.arrow.circlepath", title: L("wr_time_none"), text: L("wr_time_none_body"))
                }
                ForEach(days) { group in
                    Text(verbatim: group.day).font(.dorr(12.5, .heavy)).foregroundStyle(wa.settingsMut)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(.top, 12).padding(.bottom, 8)
                    ForEach(Array(group.events.enumerated()), id: \.element.id) { i, e in
                        EventRow(e: e, freelancer: freelancer, last: i == group.events.count - 1) { open(e) }
                    }
                }
                PagedSpinner(paged: paged)
                PagedError(paged: paged)
            }
            .padding(.horizontal, 16).padding(.top, 4).padding(.bottom, 28)
        }
        // Pulling down reads the timeline again (the client's own gesture, not a timer).
        .refreshable { await paged.reload() }
        // When it opens, on a live event (`freelance.workroom`, any step) and when the connection returns — once each.
        .task(id: "\(filterKey)|\(network.reconnectTick)|\(live.timeline)|\(active)") {
            if !active { return }
            if applied != filterKey {
                applied = filterKey
                let k = kind
                let m: Int? = mineOnly ? 1 : nil
                paged.reset { page in
                    let reply = try await FreelanceWorkroomApi.timeline(project, kind: k, mine: m, perPage: workPage, page: page)
                    return workRows(reply, page: page, meta: { _ in }, rows: { $0.events ?? [] })
                }
            }
            await paged.reload()
        }
    }
}

/// One step on the rail: its kind's icon on a disc, the line down to the next step, the text, who did it and at what time.
private struct EventRow: View {
    @Environment(\.wa) private var wa
    let e: WorkEventDto
    let freelancer: String
    let last: Bool
    let onClick: () -> Void

    /// Who took the step: the person of a team by name (never more), else «You» or the freelancer.
    private var who: String {
        if let name = e.by?.name, !name.isEmpty { return name }
        if e.mine == true { return L("wr_you") }
        if e.actor == "freelancer" { return freelancer.isEmpty ? L("wr_freelancer") : freelancer }
        return ""
    }

    private var opens: Bool {
        guard let s = e.subject else { return false }
        return s.type == "file" || s.type == "task" || s.type == "decision" || (s.type == "comment" && s.on?.type == "file")
    }

    var body: some View {
        let kind = e.kind ?? ""
        let tone = kindTone(kind)
        let row = HStack(alignment: .top, spacing: 12) {
            VStack(spacing: 0) {
                Image(systemName: kindIcon(kind)).font(.system(size: 15, weight: .semibold)).foregroundStyle(tone)
                    .frame(width: 34, height: 34)
                    .background(tone.opacity(wa.night ? 0.25 : 0.14), in: Circle())
                if !last { Rectangle().fill(wa.settingsMut.opacity(0.22)).frame(width: 2).frame(maxHeight: .infinity) }
            }
            .frame(width: 34)
            VStack(alignment: .leading, spacing: 3) {
                Text(verbatim: e.label ?? "").font(.dorr(13.5, .semibold)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                Text(verbatim: [who, wrLocalTime(e.at)].filter { !$0.isEmpty }.joined(separator: "  ·  ")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.bottom, last ? 4 : 14)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        if opens {
            Button(action: onClick) { row.contentShape(Rectangle()) }.buttonStyle(.plain)
        } else {
            row
        }
    }
}
