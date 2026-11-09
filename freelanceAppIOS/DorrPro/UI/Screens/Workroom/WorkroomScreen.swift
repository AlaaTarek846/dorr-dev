import SwiftUI

// freelanceApp `ui/screens/workroom/WorkroomScreen.kt`.

/// The keys of the Workroom's tabs (also what a notice's route carries: `tasks` · `files` · `decisions` · `timeline`).
private let WORKROOM_TABS = ["tasks", "files", "decisions", "timeline"]

/// What the Workroom holds: the four lists, the agreement (for its parts and its team) and the one place that reads them again.
///
/// Everything reads once when it opens, once when the connection returns and once when a notice arrives. While it is open it also listens to
/// the project's private channel (`freelance.workroom`: kind, action, uuid, by — never the text): each change by the other side (or by my
/// team) reads only the lists it belongs to, once, after the burst settles. Nothing runs on a timer.
@MainActor
final class WorkroomModel: ObservableObject {
    let project: String
    let tasks: TasksState
    let files: FilesState
    let decisions: DecisionsState
    let timeline: TimelineState
    @Published var agreement: AgreementDto?
    @Published var selected: String
    /// The timeline is read when its tab is open; a change meanwhile only marks it old.
    private var timelineStale = true
    /// What changed since the last read: `task` · `file` · `comment` · `decision`, or `all` (a notice, the connection back).
    private var pending = Set<String>()
    private let burst = WorkDebouncer()
    /// FS-209: the team whose channel this Workroom listens to (a team's project — the inside tasks, files and notes travel only there), and
    /// whether the screen is on show (nothing is listened to while it is not).
    private var listeningTeam: String?
    private var onScreen = false

    init(project: String, tab: String) {
        self.project = project
        tasks = TasksState(project: project)
        files = FilesState(project: project)
        decisions = DecisionsState(project: project)
        timeline = TimelineState(project: project)
        selected = WORKROOM_TABS.contains(tab) ? tab : "tasks"
    }

    private func loadAgreement() async {
        if let a = await AgreementsApi.show(project).value {
            agreement = a
            syncTeamChannel()
        }
    }

    /// The screen is on show: the team's channel is listened to as soon as the agreement says the project is a team's.
    func appear() {
        onScreen = true
        syncTeamChannel()
    }

    /// Listens to the team's channel while the screen is on show and the project is a team's; lets go of it otherwise (or when the team changes).
    private func syncTeamChannel() {
        let wanted = onScreen ? agreement?.team?.uuid : nil
        guard wanted != listeningTeam else { return }
        if let old = listeningTeam { Realtime.shared.stopListeningTeamWorkroom(old) }
        if let new = wanted { Realtime.shared.listenTeamWorkroom(new) }
        listeningTeam = wanted
    }

    /// The first read (and the one that catches up when the screen comes back): the agreement and the three lists together.
    func loadAll() async {
        let jobs = [
            Task { await self.loadAgreement() },
            Task { await self.tasks.feed.reload() },
            Task { await self.files.feed.reload() },
            Task { await self.decisions.feed.reload() },
        ]
        for job in jobs { await job.value }
        timelineMayChange()
    }

    /// Something changed (`all`, or the kind a live event names): wait for the burst to settle, then read what changed — once.
    func touch(_ kind: String) {
        pending.insert(kind)
        burst.fire { [weak self] in self?.flush() }
    }

    private func flush() {
        let kinds = pending
        pending.removeAll()
        guard !kinds.isEmpty else { return }
        let all = kinds.contains("all")
        // The reads run apart from the waiting, so a newer event never cancels one that is already on its way.
        var jobs: [Task<Void, Never>] = []
        if all || kinds.contains("task") { jobs.append(Task { await self.tasks.feed.reload() }) }
        if all || kinds.contains("file") || kinds.contains("comment") { jobs.append(Task { await self.files.feed.reload() }) }
        if all || kinds.contains("decision") { jobs.append(Task { await self.decisions.feed.reload() }) }
        if all { jobs.append(Task { await self.loadAgreement() }) }
        let running = jobs
        Task {
            for job in running { await job.value }
            self.timelineMayChange()
        }
    }

    /// Something in the project changed: the timeline is read again when it is on show (now, or when its tab is opened).
    func timelineMayChange() {
        timelineStale = true
        readTimelineIfShown()
    }

    func select(_ key: String) {
        selected = key
        readTimelineIfShown()
    }

    private func readTimelineIfShown() {
        guard selected == "timeline", timelineStale else { return }
        timelineStale = false
        Task { await self.timeline.feed.reload() }
    }

    func stop() {
        burst.cancel()
        pending.removeAll()
        files.stop()
        onScreen = false
        syncTeamChannel()
    }
}

/// FS-150e: a project's Workroom for the freelancer — the tasks, the files (with versions and notes), the decisions both sides agreed and the
/// timeline of the whole project, in one place under the agreement. It opens on the tab a notice points at.
struct WorkroomScreen: View {
    let project: String
    let tab: String
    let onBack: () -> Void
    let onOpenFile: (String) -> Void

    @EnvironmentObject private var network: NetworkMonitor
    @StateObject private var model: WorkroomModel
    @State private var listening = false

    init(project: String, tab: String, onBack: @escaping () -> Void, onOpenFile: @escaping (String) -> Void) {
        self.project = project
        self.tab = tab
        self.onBack = onBack
        self.onOpenFile = onOpenFile
        _model = StateObject(wrappedValue: WorkroomModel(project: project, tab: tab))
    }

    private var parts: [WorkPartDto] { (model.agreement?.milestones ?? []).asParts() }

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("wr_title"), onBack: onBack)
                WorkroomTabs(tasks: model.tasks, decisions: model.decisions, selected: model.selected, onSelect: { model.select($0) })
                tabContent.frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .navigationBarHidden(true)
        // It listens to the project's channel while it is open.
        .onAppear {
            model.appear()
            if !listening {
                listening = true
                Realtime.shared.listenWorkroom(project)
            }
        }
        .onDisappear {
            model.stop()
            if listening {
                listening = false
                Realtime.shared.stopListeningWorkroom(project)
            }
        }
        .task(id: project) { await model.loadAll() }
        .onChange(of: network.reconnectTick) { _ in model.touch("all") }
        .onReceive(Realtime.shared.changed) { _ in model.touch("all") }
        .onReceive(Realtime.shared.workroomEvents) { e in
            // An event of the team's channel names its project; one of another project's Workroom is not ours.
            if let other = e.data.text("project"), other != project { return }
            model.touch(e.data.text("kind") ?? "all")
        }
    }

    @ViewBuilder
    private var tabContent: some View {
        switch model.selected {
        case "files":
            FilesTab(
                state: model.files, project: project, parts: parts, team: model.agreement?.team,
                onOpenFile: onOpenFile, onChanged: { model.timelineMayChange() }
            )
        case "decisions":
            DecisionsTab(state: model.decisions, project: project, parts: parts, onChanged: { model.timelineMayChange() })
        case "timeline":
            TimelineTab(state: model.timeline)
        default:
            TasksTab(
                state: model.tasks, project: project, parts: parts, team: model.agreement?.team,
                onChanged: { model.timelineMayChange() }
            )
        }
    }
}

/// The four tabs, with what is open in the tasks and what waits for my answer in the decisions.
private struct WorkroomTabs: View {
    @ObservedObject var tasks: TasksState
    @ObservedObject var decisions: DecisionsState
    let selected: String
    let onSelect: (String) -> Void

    var body: some View {
        SegmentTabs(
            segments: [
                Segment(key: "tasks", label: L("wr_tab_tasks"), icon: "checklist", count: tasks.summary.open),
                Segment(key: "files", label: L("wr_tab_files"), icon: "paperclip"),
                Segment(key: "decisions", label: L("wr_tab_decisions"), icon: "hammer.fill", count: decisions.summary.waitingForMe ?? 0),
                Segment(key: "timeline", label: L("wr_tab_timeline"), icon: "clock.arrow.circlepath"),
            ],
            selected: selected, onSelect: onSelect
        )
        .padding(.horizontal, 16)
        .padding(.bottom, 8)
    }
}

/// The card on the agreement page that opens the Workroom (its title, what is inside, and — once the project is over — that it is read only).
struct WorkroomEntryCard: View {
    @Environment(\.wa) private var wa
    let readOnly: Bool
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(spacing: 12) {
                Image(systemName: "checklist").font(.system(size: 20, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 44, height: 44)
                    .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
                VStack(alignment: .leading, spacing: 2) {
                    Text(L("wr_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(L(readOnly ? "wr_entry_body_done" : "wr_entry_body")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        .lineSpacing(3).multilineTextAlignment(.leading)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Image(systemName: "chevron.forward").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut)
            }
            .padding(14)
            .frame(maxWidth: .infinity)
            .appCard(Corners.card)
            .contentShape(RoundedRectangle(cornerRadius: Corners.card, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}
