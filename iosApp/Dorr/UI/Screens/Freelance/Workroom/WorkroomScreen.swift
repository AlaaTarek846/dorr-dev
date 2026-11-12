import SwiftUI

// androidApp `freelance/workroom/WorkroomScreen.kt` — the Workroom of a running (or finished) work, and the card that opens it.

/// The Workroom's four parts, in the order of its tabs; a notice names one of them in its page key («workroom:<project>:files»).
private let workroomTabs = ["tasks", "files", "decisions", "timeline"]

func workroomTabIndex(_ name: String?) -> Int { workroomTabs.firstIndex(of: name ?? "") ?? 0 }

/// The Workroom of a running (or finished) work: the tasks both sides keep, the files with their versions and notes, the decisions they settle
/// and the timeline of it all — four tabs of one page, with the numbers that ask something of me (open tasks, decisions waiting for my answer)
/// on the tabs. It is shared with the freelancer (what I write, they see) and is read only once the agreement is completed. Live while it is
/// open (the project's channel), read again on a pull and when the connection returns — nothing on a timer.
@MainActor
struct WorkroomPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let project: String
    let startTab: String
    let onBack: () -> Void
    let onOpenFile: (String) -> Void

    @StateObject private var live = WorkroomLive()
    @StateObject private var people = WorkroomPeople()
    @State private var tab: Int
    /// The tabs opened so far: each is read when it is first on show and then kept (its filter and scroll stay while another one is on show).
    @State private var visited: Set<Int>
    // What the tabs say: tasks still open, decisions waiting for me; and whether the Workroom is there at all.
    @State private var openTasks = 0
    @State private var waiting = 0
    @State private var unavailable: String?

    init(project: String, startTab: String, onBack: @escaping () -> Void, onOpenFile: @escaping (String) -> Void) {
        self.project = project
        self.startTab = startTab
        self.onBack = onBack
        self.onOpenFile = onOpenFile
        let first = workroomTabIndex(startTab)
        _tab = State(initialValue: first)
        _visited = State(initialValue: [first])
    }

    private func select(_ index: Int) {
        tab = index
        visited.insert(index)
    }

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: L("wr_title"), onBack: onBack,
                         subtitle: people.freelancer.isEmpty ? L("wr_subtitle") : L("wr_subtitle_with", people.freelancer))
            if let problem = unavailable {
                ScrollView { WrEmpty(icon: "lock.fill", title: L("wr_unavailable"), text: problem) }
            } else {
                FlPartTabs(tabs: [
                    FlTab(icon: "checkmark.circle", label: L("wr_tab_tasks"), badge: openTasks),
                    FlTab(icon: "paperclip", label: L("wr_tab_files")),
                    FlTab(icon: "hammer", label: L("wr_tab_decisions"), badge: waiting),
                    FlTab(icon: "clock.arrow.circlepath", label: L("wr_tab_timeline")),
                ], selected: tab, compact: true) { select($0) }
                ZStack {
                    ForEach(0..<workroomTabs.count, id: \.self) { i in
                        if visited.contains(i) {
                            tabView(i)
                                .opacity(tab == i ? 1 : 0)
                                .allowsHitTesting(tab == i)
                                .accessibilityHidden(tab != i)
                        }
                    }
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .workroomLive(project, live)
        // The agreement's parts and the freelancer's name (for the forms): when it opens and when the connection returns.
        .task(id: network.reconnectTick) { await people.read(project) }
        // The two numbers on the tabs, read small (one row each) while another tab is on show; the tabs themselves refresh them as they read.
        .task(id: "\(network.reconnectTick)|\(live.tasks)|\(live.decisions)") {
            do {
                let tasks = try await FreelanceWorkroomApi.tasks(project, perPage: 1).data
                openTasks = tasks?.summary?.open ?? 0
                unavailable = nil
                waiting = try await FreelanceWorkroomApi.decisions(project, perPage: 1).data?.summary?.waitingForMe ?? 0
            } catch {
                if Task.isCancelled { return }
                // The Workroom is off in this country, or the work has none (not running): say so. Anything else leaves the tabs to try for themselves.
                let failure = error.apiFailure
                if failure.httpStatus == 403 || failure.httpStatus == 404 { unavailable = failure.message ?? L("fl_error_generic") }
            }
        }
    }

    @ViewBuilder
    private func tabView(_ index: Int) -> some View {
        switch index {
        case 0:
            TasksTab(project: project, parts: people.parts, freelancer: people.freelancer, live: live, active: tab == 0) { openTasks = $0.open }
        case 1:
            FilesTab(project: project, parts: people.parts, freelancer: people.freelancer, live: live, active: tab == 1, onOpenFile: onOpenFile)
        case 2:
            DecisionsTab(project: project, parts: people.parts, freelancer: people.freelancer, live: live, active: tab == 2) { waiting = $0.waitingForMe ?? 0 }
        default:
            TimelineTab(project: project, freelancer: people.freelancer, live: live, active: tab == 3, onOpenFile: onOpenFile) { select($0) }
        }
    }
}

/// The card on a running or finished agreement that opens its Workroom: what is open there (tasks done of all, decisions waiting for my
/// answer). It shows nothing while the Workroom is switched off in the country (or the work has none); read once when the page opens, when a
/// notice arrives and when the connection returns.
@MainActor
struct WorkroomEntryCard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let project: String
    let onOpen: () -> Void
    // nil: not answered yet; false: not available (off in the country); true: shown (the numbers may be missing if the read failed).
    @State private var shown: Bool?
    @State private var done = 0
    @State private var total = 0
    @State private var waiting = 0

    private var line: String {
        let parts = [total > 0 ? L("wr_entry_tasks", done, total) : nil, waiting > 0 ? L("wr_entry_waiting", waiting) : nil].compactMap { $0 }
        return parts.isEmpty ? L("wr_entry_idle") : parts.joined(separator: "  ·  ")
    }

    private func read() async {
        do {
            let tasks = try await FreelanceWorkroomApi.tasks(project, perPage: 1).data
            done = tasks?.summary?.done ?? 0
            total = tasks?.summary?.total ?? 0
            shown = true
            waiting = try await FreelanceWorkroomApi.decisions(project, perPage: 1).data?.summary?.waitingForMe ?? 0
        } catch {
            if Task.isCancelled { return }
            let status = error.apiFailure.httpStatus
            shown = status != 403 && status != 404
        }
    }

    var body: some View {
        ZStack {
            // The anchor that reads, always there: the card itself shows nothing until the Workroom answers (or when it is off).
            Color.clear.frame(width: 1, height: 1).accessibilityHidden(true)
                .task(id: "\(project)|\(network.reconnectTick)") { await read() }
            if shown == true {
                Button(action: onOpen) {
                    HStack(spacing: 12) {
                        Image(systemName: "bubble.left.and.bubble.right.fill").font(.system(size: 20, weight: .semibold)).foregroundStyle(wrViolet)
                            .frame(width: 44, height: 44)
                            .background(wrViolet.opacity(0.14), in: RoundedRectangle(cornerRadius: FlCorners.icon, style: .continuous))
                        VStack(alignment: .leading, spacing: 2) {
                            Text(verbatim: L("wr_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                            Text(verbatim: line).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2).lineLimit(2)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        FlCountBadge(count: waiting)
                        Image(systemName: "chevron.forward").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.settingsMut)
                    }
                    .padding(14)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .settingsSurface(FlCorners.card)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .accessibilityElement(children: .combine)
            }
        }
    }
}
