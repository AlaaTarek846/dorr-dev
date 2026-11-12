import SwiftUI

// androidApp `freelance/workroom/WorkroomTasks.kt` — the Workroom's tasks: what each side has to do on the project.

private let taskStatuses = ["todo", "doing", "blocked", "done"]

private func taskStatusIcon(_ status: String) -> String {
    switch status {
    case "doing": return "arrow.triangle.2.circlepath"
    case "blocked": return "nosign"
    case "done": return "checkmark.circle.fill"
    default: return "circle"
    }
}

/// The Workroom's tasks: what each side has to do on the project. A summary on top (how far it is), filters, and a card for each task with
/// its part, its day and who it is for; whoever the task is on (or wrote it) changes its status with one tap. Read once when it opens, on a
/// live event, on a pull and when the connection returns.
@MainActor
struct TasksTab: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let project: String
    let parts: [WorkPartDto]
    let freelancer: String
    @ObservedObject var live: WorkroomLive
    /// Whether this tab is the one on show: a hidden tab keeps what it holds but does not read again until it is back (Android drops the tab).
    let active: Bool
    let onSummary: (WorkTaskSummaryDto) -> Void

    @State private var status: String?
    @State private var mineOnly = false
    @State private var overdueOnly = false
    @State private var version = 0
    @State private var busy = false
    @State private var error: String?
    @State private var editing: WorkTaskDto?
    @State private var creating = false
    @State private var statusFor: WorkTaskDto?
    @State private var deleteFor: WorkTaskDto?
    /// The first page's whole answer: the numbers for the chips, what I `can` do, whether the work is over.
    @State private var answer: WorkTasksDto?
    /// The filter the list was last started for (a new filter starts again from its skeleton; a live event only reads again).
    @State private var applied: String?
    @StateObject private var paged = PagedRows<WorkTaskDto>(key: { $0.uuid })

    private var filterKey: String { "\(status ?? "")|\(mineOnly)|\(overdueOnly)" }

    private func act(_ block: @escaping () async throws -> Void) {
        busy = true
        error = nil
        Task {
            do { try await block() } catch { self.error = flError(error) }
            busy = false
        }
    }

    private func changeStatus(_ task: WorkTaskDto, _ next: String) {
        act {
            let result = try await FreelanceWorkroomApi.setTaskStatus(project, task: task.uuid, status: next).data
            if let updated = result?.task { paged.update(where: { $0.uuid == task.uuid }) { _ in updated } }
            if let summary = result?.summary { answer?.summary = summary }
            statusFor = nil
            version += 1
        }
    }

    private func statusCount(_ s: String, _ summary: WorkTaskSummaryDto) -> Int {
        switch s {
        case "todo": return summary.todo ?? 0
        case "doing": return summary.doing ?? 0
        case "blocked": return summary.blocked ?? 0
        default: return summary.done ?? 0
        }
    }

    var body: some View {
        let summary = answer?.summary
        let canCreate = answer != nil && answer?.can?.taskCreate == true && answer?.readOnly != true
        let filtered = status != nil || mineOnly || overdueOnly
        ScrollView {
            LazyVStack(spacing: 10) {
                if let summary { TasksSummary(s: summary) }
                if canCreate { FlAction(text: L("wr_task_add"), icon: "plus", tone: .primary) { creating = true } }
                if let summary, (summary.total ?? 0) > 0 {
                    WrChipRow {
                        FlChip(text: L("wr_all"), selected: !filtered, icon: "checkmark.circle") { status = nil; mineOnly = false; overdueOnly = false }
                        ForEach(taskStatuses, id: \.self) { s in
                            FlChip(text: taskLabel(s) + "  \(statusCount(s, summary))", selected: status == s, icon: taskStatusIcon(s)) { status = status == s ? nil : s }
                        }
                        FlChip(text: L("wr_task_mine"), selected: mineOnly, icon: "person.fill") { mineOnly.toggle() }
                        if (summary.overdue ?? 0) > 0 {
                            FlChip(text: L("wr_task_overdue") + "  \(summary.overdue ?? 0)", selected: overdueOnly, icon: "clock") { overdueOnly.toggle() }
                        }
                    }
                }
                if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: wrRed) }
                if answer?.readOnly == true { FlNotice(text: L("wr_read_only"), icon: "lock.fill", color: wrAmber) }
                if !paged.loaded && paged.error == nil {
                    WrSkeleton(count: 3, height: 96)
                } else if paged.rows.isEmpty, let failed = paged.error {
                    FlRetry(message: failed)
                } else if paged.rows.isEmpty {
                    WrEmpty(icon: "checkmark.circle", title: L(filtered ? "wr_task_none_filtered" : "wr_task_none"),
                            text: L(filtered ? "wr_task_none_filtered_body" : "wr_task_none_body"))
                }
                ForEach(Array(paged.rows.enumerated()), id: \.element.uuid) { i, task in
                    TaskCard(task: task, freelancer: freelancer, busy: busy,
                             onToggle: { changeStatus(task, task.status == "done" ? "todo" : "done") },
                             onStatus: { statusFor = task },
                             onEdit: { editing = task },
                             onDelete: { deleteFor = task })
                        .onAppear { paged.appeared(i) }
                }
                PagedSpinner(paged: paged)
                PagedError(paged: paged)
            }
            .padding(.horizontal, 16).padding(.top, 4).padding(.bottom, 28)
        }
        // Pulling down reads the list again (the client's own gesture, not a timer).
        .refreshable { await paged.reload() }
        // When it opens, on a live event (`freelance.workroom`), after a change of mine and when the connection returns — once each.
        .task(id: "\(filterKey)|\(network.reconnectTick)|\(live.tasks)|\(version)|\(active)") {
            if !active { return }
            if applied != filterKey {
                applied = filterKey
                let s = status
                let m: Int? = mineOnly ? 1 : nil
                let o: Int? = overdueOnly ? 1 : nil
                paged.reset { page in
                    let reply = try await FreelanceWorkroomApi.tasks(project, status: s, mine: m, overdue: o, perPage: workPage, page: page)
                    return workRows(reply, page: page, meta: { answer = $0 }, rows: { $0.tasks ?? [] })
                }
            }
            await paged.reload()
        }
        .onChange(of: answer) { now in if let summary = now?.summary { onSummary(summary) } }
        .dorrDialog(isPresented: Binding(get: { creating }, set: { if !$0 && !busy { creating = false } }), bottom: true) {
            TaskSheet(initial: nil, parts: parts, freelancer: freelancer, busy: busy, error: error, onDismiss: { if !busy { creating = false } }) { fields in
                act {
                    _ = try await FreelanceWorkroomApi.createTask(project, body: fields)
                    creating = false
                    version += 1
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { editing != nil }, set: { if !$0 && !busy { editing = nil } }), bottom: true) {
            if let task = editing {
                TaskSheet(initial: task, parts: parts, freelancer: freelancer, busy: busy, error: error, onDismiss: { if !busy { editing = nil } }) { fields in
                    act {
                        _ = try await FreelanceWorkroomApi.updateTask(project, task: task.uuid, body: fields)
                        editing = nil
                        version += 1
                    }
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { statusFor != nil }, set: { if !$0 && !busy { statusFor = nil } }), bottom: true) {
            if let task = statusFor { statusSheet(task) }
        }
        .dorrDialog(isPresented: Binding(get: { deleteFor != nil }, set: { if !$0 && !busy { deleteFor = nil } })) {
            if let task = deleteFor {
                ConfirmDialog(icon: "trash.fill", title: L("wr_task_delete_title"), message: L("wr_task_delete_body", task.title ?? ""),
                              loading: busy, confirmLabel: L("wr_delete"), confirmTint: wrRed,
                              onConfirm: { act { _ = try await FreelanceWorkroomApi.deleteTask(project, task: task.uuid); deleteFor = nil; version += 1 } },
                              onDismiss: { if !busy { deleteFor = nil } })
            }
        }
    }

    /// «Where is it?»: the four states, the current one filled.
    private func statusSheet(_ task: WorkTaskDto) -> some View {
        let current = task.status ?? "todo"
        return FlSheet(icon: "arrow.triangle.2.circlepath", title: L("wr_task_status_title"), text: task.title ?? "") {
            if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: wrRed) }
            ForEach(taskStatuses, id: \.self) { s in
                FlAction(text: taskLabel(s), icon: s == current ? "checkmark" : taskStatusIcon(s), tone: s == current ? .primary : .soft, enabled: !busy) {
                    if s == current { statusFor = nil } else { changeStatus(task, s) }
                }
            }
        }
    }
}

/// How far the work is: done of all, a bar, and the numbers that ask for something.
private struct TasksSummary: View {
    @Environment(\.wa) private var wa
    let s: WorkTaskSummaryDto

    var body: some View {
        let total = s.total ?? 0
        let done = s.done ?? 0
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: L("wr_task_progress", done, total)).font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: L("wr_task_progress_line")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                Spacer(minLength: 8)
                Text(verbatim: total == 0 ? "0%" : "\(done * 100 / total)%").font(.dorr(20, .heavy)).foregroundStyle(wa.accent)
            }
            GeometryReader { proxy in
                ZStack(alignment: .leading) {
                    Capsule().fill(wa.accent.opacity(0.12))
                    if total > 0 && done > 0 { Capsule().fill(wrGreen).frame(width: proxy.size.width * CGFloat(done) / CGFloat(total)) }
                }
            }
            .frame(height: 8)
            HStack(spacing: 8) {
                FlStat(icon: "hourglass", label: L("wr_task_open"), value: "\(s.open)")
                FlStat(icon: "clock", label: L("wr_task_overdue"), value: "\(s.overdue ?? 0)")
                FlStat(icon: "nosign", label: L("wr_task_blocked"), value: "\(s.blocked ?? 0)")
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(FlCorners.card)
    }
}

private struct TaskCard: View {
    @Environment(\.wa) private var wa
    let task: WorkTaskDto
    let freelancer: String
    let busy: Bool
    let onToggle: () -> Void
    let onStatus: () -> Void
    let onEdit: () -> Void
    let onDelete: () -> Void

    var body: some View {
        let status = task.status ?? "todo"
        let tone = taskTone(status)
        let done = status == "done"
        let canStatus = task.can?.taskStatus == true
        let pill = FlDotPill(text: taskLabel(status), color: tone)
        let toClient = task.assigneeSide == "client"
        HStack(alignment: .top, spacing: 12) {
            // One tap finishes a task (or opens it again) — for whoever may change its status.
            Button(action: onToggle) {
                ZStack {
                    Circle().fill(done ? wrGreen : Color.clear)
                    Circle().stroke(done ? wrGreen : tone.opacity(0.55), lineWidth: 2)
                    if done { Image(systemName: "checkmark").font(.system(size: 13, weight: .heavy)).foregroundStyle(.white) }
                }
                .frame(width: 28, height: 28)
                .contentShape(Circle())
            }
            .buttonStyle(.plain)
            .disabled(!canStatus || busy)
            .accessibilityLabel(taskLabel(status))
            .accessibilityAddTraits(done ? [.isSelected] : [])
            VStack(alignment: .leading, spacing: 7) {
                HStack(alignment: .top, spacing: 8) {
                    Text(verbatim: task.title ?? "").font(.dorr(15, .heavy)).strikethrough(done).foregroundStyle(done ? wa.settingsMut : wa.settingsInk)
                        .lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
                    if canStatus { Button(action: onStatus) { pill }.buttonStyle(.plain) } else { pill }
                }
                if let text = task.description, !wrTrim(text).isEmpty {
                    Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(3).lineLimit(3)
                }
                FlowLayout(spacing: 6) {
                    if let part = task.milestone { WrTag(text: wrPartName(part), icon: "flag.fill", color: wa.accent) }
                    if let due = task.dueOn, !due.isEmpty {
                        WrTag(text: L("wr_due", wrDay(due)), icon: "calendar", color: task.overdue == true ? wrRed : wrGray)
                    }
                    WrTag(text: toClient ? L("wr_for_you") : L("wr_for_them", sideName("freelancer", freelancer)), icon: "person.fill",
                          color: toClient ? wrViolet : wrGray)
                }
                Text(verbatim: L("wr_added_by", sideName(task.createdBySide, freelancer), wrDay(task.createdAt))).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                if task.can?.taskEdit == true || task.can?.taskDelete == true {
                    HStack(spacing: 14) {
                        if task.can?.taskEdit == true { WrLink(text: L("wr_edit"), icon: "pencil", color: wa.accent, action: onEdit) }
                        if task.can?.taskDelete == true { WrLink(text: L("wr_delete"), icon: "trash", color: wrRed, action: onDelete) }
                    }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(FlCorners.card)
    }
}

/// The form of a task: a new one, or the one being changed. Saves one map of fields; a part or a day cleared goes as empty text.
@MainActor
private struct TaskSheet: View {
    @Environment(\.wa) private var wa
    let initial: WorkTaskDto?
    let parts: [WorkPartDto]
    let freelancer: String
    let busy: Bool
    let error: String?
    let onDismiss: () -> Void
    let onSave: ([String: JSONValue]) -> Void
    @State private var title: String
    @State private var description: String
    @State private var part: String?
    @State private var due: String
    @State private var side: String

    init(initial: WorkTaskDto?, parts: [WorkPartDto], freelancer: String, busy: Bool, error: String?,
         onDismiss: @escaping () -> Void, onSave: @escaping ([String: JSONValue]) -> Void) {
        self.initial = initial
        self.parts = parts
        self.freelancer = freelancer
        self.busy = busy
        self.error = error
        self.onDismiss = onDismiss
        self.onSave = onSave
        _title = State(initialValue: initial?.title ?? "")
        _description = State(initialValue: initial?.description ?? "")
        _part = State(initialValue: initial?.milestone?.uuid)
        _due = State(initialValue: String((initial?.dueOn ?? "").prefix(10)))
        _side = State(initialValue: initial?.assigneeSide ?? "freelancer")
    }

    var body: some View {
        FlSheet(icon: "checklist", title: L(initial == nil ? "wr_task_new" : "wr_task_edit"), text: L("wr_task_form_body"),
                footer: AnyView(VStack(spacing: 10) {
                    if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: wrRed) }
                    FlAction(text: L("common_save"), icon: "checkmark", tone: .primary, enabled: wrTrim(title).count >= 3, loading: busy) {
                        let fields: [String: JSONValue] = [
                            "title": .string(wrTrim(title)),
                            "description": .string(wrTrim(description)),
                            "milestone": .string(part ?? ""),
                            "due_on": .string(due),
                            "assignee_side": .string(side),
                        ]
                        onSave(fields)
                    }
                    FlAction(text: L("common_cancel"), enabled: !busy, action: onDismiss)
                })) {
            DorrTextArea(value: Binding(get: { title }, set: { title = String($0.prefix(160)) }), label: L("wr_task_title"), icon: "checklist", singleLine: true)
            DorrTextArea(value: Binding(get: { description }, set: { description = String($0.prefix(2000)) }), label: L("wr_task_description"), minHeight: 90)
            VStack(alignment: .leading, spacing: 8) {
                Text(verbatim: L("wr_for")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                HStack(spacing: 8) {
                    FlChip(text: L("wr_you"), selected: side == "client", icon: "person.fill", fit: true) { side = "client" }
                    FlChip(text: freelancer.isEmpty ? L("wr_freelancer") : freelancer, selected: side == "freelancer", icon: "person.fill", fit: true) { side = "freelancer" }
                }
            }
            // A new day starts from today; a day already past may stay on a task that has it.
            WrDateField(label: L("wr_task_due"), value: due, onPick: { due = $0 }, earliest: (initial?.dueOn ?? "").isEmpty ? Calendar.current.startOfDay(for: Date()) : nil)
            if !parts.isEmpty {
                VStack(alignment: .leading, spacing: 8) {
                    Text(verbatim: L("wr_part")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    PartPicker(parts: parts, selected: part) { part = $0 }
                }
            }
        }
    }
}
