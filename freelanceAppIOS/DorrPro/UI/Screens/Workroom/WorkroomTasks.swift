import SwiftUI

// freelanceApp `ui/screens/workroom/WorkroomTasks.kt`.

/// The tasks of the Workroom: the read, the numbers for the chips and what I may do.
@MainActor
final class TasksState: ObservableObject {
    let project: String
    @Published var filter = "all"
    @Published var summary = WorkTaskSummaryDto()
    @Published var readOnly = false
    @Published var canCreate = false
    @Published var max: Int?
    let feed = PagedRows<WorkTaskDto>(perPage: WORK_PAGE, key: { $0.uuid ?? "" })

    init(project: String) {
        self.project = project
        feed.reset { [weak self] page in
            guard let self else { return .offline }
            return await self.fetch(page)
        }
    }

    private func fetch(_ page: Int) async -> ApiResult<[WorkTaskDto]> {
        let f = filter
        let r = await WorkroomApi.tasks(
            project, status: TASK_STATUSES.contains(f) ? f : nil, assignee: f == "client" ? "client" : nil,
            mine: f == "mine" ? 1 : nil, overdue: f == "overdue" ? 1 : nil, perPage: WORK_PAGE, page: page
        )
        if let data = r.value {
            summary = data.summary ?? WorkTaskSummaryDto()
            readOnly = data.readOnly ?? false
            canCreate = data.can?.taskCreate ?? false
            max = data.limit?.max
        }
        return r.rows { $0.tasks }
    }
}

/// The tab of the tasks: the numbers, the filter, the cards and the button to add.
struct TasksTab: View {
    @ObservedObject var state: TasksState
    let project: String
    let parts: [WorkPartDto]
    let team: OfferTeamDto?
    let onChanged: () -> Void

    @State private var editing: WorkTaskDto?
    @State private var adding = false
    /// Kept here (not in the sheet) so the sheet stays open while a request runs.
    @State private var sheetBusy = false

    private var canAdd: Bool { state.canCreate && !state.readOnly }

    var body: some View {
        FeedList(
            feed: state.feed, keyOf: { $0.uuid ?? "" },
            empty: WorkEmpty(icon: "checklist", title: L("wr_tasks_empty_title"), text: L("wr_tasks_empty_body")),
            fabLabel: canAdd ? L("wr_task_add") : nil, onFab: addAction,
            header: { header },
            row: { _, t in
                TaskCard(t: t, onStatus: { setStatus(t, $0) }, onOpen: { editing = t })
            }
        )
        .cmSheet(isPresented: $adding, fullHeight: true, locked: sheetBusy) { addSheet }
        .cmSheet(isPresented: cmPresence($editing), fullHeight: true, locked: sheetBusy) { editSheet }
    }

    private var addAction: (() -> Void)? {
        guard canAdd else { return nil }
        return { adding = true }
    }

    @ViewBuilder
    private var header: some View {
        VStack(spacing: 12) {
            if state.readOnly { ReadOnlyBanner() }
            TaskNumbers(s: state.summary)
            FilterChips(options: filters, selected: state.filter, onSelect: select)
        }
    }

    private var filters: [FilterOption] {
        [
            FilterOption(key: "all", label: L("wr_f_all")),
            FilterOption(key: "mine", label: L("wr_f_mine")),
            FilterOption(key: "client", label: L("wr_f_client")),
            FilterOption(key: "overdue", label: L("wr_f_overdue"), dot: (state.summary.overdue ?? 0) > 0),
            FilterOption(key: "todo", label: L("wr_task_todo")),
            FilterOption(key: "doing", label: L("wr_task_doing")),
            FilterOption(key: "blocked", label: L("wr_task_blocked")),
            FilterOption(key: "done", label: L("wr_task_done")),
        ]
    }

    private func select(_ key: String) {
        guard key != state.filter else { return }
        state.filter = key
        Task { await state.feed.reload() }
    }

    private func reload() {
        Task { await state.feed.reload() }
        onChanged()
    }

    private func setStatus(_ t: WorkTaskDto, _ status: String) {
        guard (t.status ?? "todo") != status, let uuid = t.uuid else { return }
        Task {
            let r = await WorkroomApi.setTaskStatus(project, uuid, WorkStatusRequest(status: status))
            if let data = r.value {
                if let s = data.summary { state.summary = s }
                if let task = data.task { state.feed.update(where: { $0.uuid == uuid }) { _ in task } }
                onChanged()
            } else {
                AppToast.show(r.errorText(L("login_error_offline")) ?? L("login_error_generic"))
            }
        }
    }

    @ViewBuilder
    private var addSheet: some View {
        TaskSheet(
            project: project, task: nil, parts: parts, team: team, limit: state.max, count: state.summary.total ?? 0, readOnly: false,
            busy: $sheetBusy, onSaved: { adding = false; reload() }
        )
    }

    @ViewBuilder
    private var editSheet: some View {
        if let t = editing {
            TaskSheet(
                project: project, task: t, parts: parts, team: team, limit: state.max, count: state.summary.total ?? 0,
                readOnly: state.readOnly || t.can?.taskEdit != true, busy: $sheetBusy, onSaved: { editing = nil; reload() }
            )
        }
    }
}

/// Four numbers over the list: what is open, in progress, done and late.
private struct TaskNumbers: View {
    @Environment(\.wa) private var wa
    let s: WorkTaskSummaryDto

    var body: some View {
        let total = Swift.max(s.total ?? 0, 1)
        let share = Swift.min(Swift.max(Double(s.done ?? 0) / Double(total), 0), 1)
        let late = s.overdue ?? 0
        VStack(spacing: 10) {
            HStack(spacing: 0) {
                NumberCell(value: s.open, label: L("wr_n_open"), tint: wa.settingsInk)
                NumberCell(value: s.doing ?? 0, label: L("wr_task_doing"), tint: Tints.blue)
                NumberCell(value: s.done ?? 0, label: L("wr_task_done"), tint: Tints.green)
                NumberCell(value: late, label: L("wr_f_overdue"), tint: late > 0 ? WorkRed : wa.settingsMut)
            }
            GeometryReader { proxy in
                ZStack(alignment: .leading) {
                    Capsule().fill(wa.settingsMut.opacity(0.16))
                    Capsule().fill(Tints.green).frame(width: proxy.size.width * share)
                }
            }
            .frame(height: 6)
        }
        .padding(14)
        .frame(maxWidth: .infinity)
        .background(wa.settingsMut.opacity(0.07), in: RoundedRectangle(cornerRadius: Corners.card, style: .continuous))
    }
}

private struct NumberCell: View {
    @Environment(\.wa) private var wa
    let value: Int
    let label: String
    let tint: Color

    var body: some View {
        VStack(spacing: 1) {
            Text(verbatim: String(value)).font(.dorr(20, .black)).foregroundStyle(tint)
            Text(verbatim: label).font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut).lineLimit(1)
        }
        .frame(maxWidth: .infinity)
    }
}

/// One task: its status (tap to change), the title, where it belongs, when it is due and who does it.
private struct TaskCard: View {
    @Environment(\.wa) private var wa
    let t: WorkTaskDto
    let onStatus: (String) -> Void
    let onOpen: () -> Void

    var body: some View {
        let status = t.status ?? "todo"
        let done = status == "done"
        let late = t.overdue == true && !done
        WorkroomCard(accent: late ? WorkRed : nil, onClick: onOpen) {
            HStack(alignment: .top, spacing: 12) {
                statusMark(status)
                VStack(alignment: .leading, spacing: 4) {
                    Text(verbatim: t.title ?? "").font(.dorr(15, .heavy)).strikethrough(done)
                        .foregroundStyle(done ? wa.settingsMut : wa.settingsInk).lineLimit(2)
                    if let text = t.description, !text.agreementTrimmed.isEmpty {
                        Text(verbatim: text).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3).lineLimit(2)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            pills(done: done, late: late)
            if let part = t.milestone { PartTag(part: part) }
        }
    }

    /// The round status icon; a tap opens the choice of the new status (when I may change it).
    @ViewBuilder
    private func statusMark(_ status: String) -> some View {
        let tint = taskTint(status)
        let icon = Image(systemName: taskIcon(status)).font(.system(size: 20, weight: .semibold)).foregroundStyle(tint)
            .frame(width: 36, height: 36)
            .background(tint.opacity(0.14), in: Circle())
        if t.can?.taskStatus == true {
            Menu {
                Picker("", selection: Binding(get: { status }, set: { onStatus($0) })) {
                    ForEach(TASK_STATUSES, id: \.self) { st in
                        Label(taskLabel(st), systemImage: taskIcon(st)).tag(st)
                    }
                }
            } label: {
                icon
            }
            .accessibilityLabel(taskLabel(status))
        } else {
            icon.accessibilityLabel(taskLabel(status))
        }
    }

    private func pills(done: Bool, late: Bool) -> some View {
        let side = t.assigneeSide ?? MY_SIDE
        return HStack(spacing: 6) {
            if t.scope == "team" { TeamOnlyPill() }
            if let due = t.dueOn {
                let day = workDay(due) ?? due
                StatusPill(text: late ? L("wr_due_late", day) : L("wr_due", day), tint: late ? WorkRed : wa.settingsMut, icon: "calendar")
            }
            StatusPill(text: t.assignee?.name ?? sideName(side), tint: side == MY_SIDE ? Tints.teal : Tints.violet, icon: "person.fill")
            Spacer(minLength: 0)
        }
    }
}

/// Adding a task, or the task itself: edit it (when I may) or just read it. Shown with `.cmSheet`, which closes itself — `onSaved` is where
/// the tab reads again. `busy` lives in the tab so the sheet stays open while a request runs.
private struct TaskSheet: View {
    @Environment(\.wa) private var wa
    let project: String
    let task: WorkTaskDto?
    let parts: [WorkPartDto]
    let team: OfferTeamDto?
    let limit: Int?
    let count: Int
    let readOnly: Bool
    @Binding var busy: Bool
    let onSaved: () -> Void

    @State private var title: String
    @State private var description: String
    @State private var part: String?
    @State private var due: String
    @State private var side: String
    @State private var person: String?
    @State private var teamOnly: Bool
    @State private var error: String?
    @State private var confirmDelete = false

    init(project: String, task: WorkTaskDto?, parts: [WorkPartDto], team: OfferTeamDto?, limit: Int?, count: Int, readOnly: Bool,
         busy: Binding<Bool>, onSaved: @escaping () -> Void) {
        self.project = project
        self.task = task
        self.parts = parts
        self.team = team
        self.limit = limit
        self.count = count
        self.readOnly = readOnly
        _busy = busy
        self.onSaved = onSaved
        _title = State(initialValue: task?.title ?? "")
        _description = State(initialValue: task?.description ?? "")
        _part = State(initialValue: task?.milestone?.uuid)
        _due = State(initialValue: task?.dueOn ?? "")
        _side = State(initialValue: task?.assigneeSide ?? MY_SIDE)
        _person = State(initialValue: task?.assignee?.uuid)
        _teamOnly = State(initialValue: task?.scope == "team")
    }

    private var staff: [OfferTeamMemberDto] { (team?.members ?? []).filter { !($0.freelancer ?? "").isEmpty } }

    private var atLimit: Bool {
        guard task == nil, let limit else { return false }
        return count >= limit
    }

    private var sheetTitle: String {
        if task == nil { return L("wr_task_new") }
        return L(readOnly ? "wr_task_view" : "wr_task_edit")
    }

    var body: some View {
        WorkSheet(title: sheetTitle) {
            if readOnly, let task {
                readView(task)
            } else {
                form
            }
        }
        .dorrDialog(isPresented: $confirmDelete) {
            ConfirmDialog(
                icon: "trash", title: L("wr_task_delete"), message: L("wr_task_delete_ask"), loading: busy, confirmLabel: L("wr_delete"),
                onConfirm: delete, onDismiss: { if !busy { confirmDelete = false } }
            )
        }
    }

    @ViewBuilder
    private func readView(_ t: WorkTaskDto) -> some View {
        Text(verbatim: t.title ?? "").font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk)
        if let text = t.description, !text.agreementTrimmed.isEmpty {
            Text(verbatim: text).font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(5)
        }
        WorkFact(label: L("wr_status"), value: taskLabel(t.status ?? "todo"))
        if let p = t.milestone { WorkFact(label: L("wr_part"), value: L("wr_part_tag", p.position ?? 0, p.title ?? "")) }
        if let d = t.dueOn { WorkFact(label: L("wr_due_label"), value: workDay(d) ?? d) }
        WorkFact(label: L("wr_assignee"), value: t.assignee?.name ?? sideName(t.assigneeSide))
        if t.scope == "team" { TeamOnlyPill() }
    }

    @ViewBuilder
    private var form: some View {
        if atLimit { Text(L("wr_tasks_limit", limit ?? 0)).font(.dorr(12.5)).foregroundStyle(WorkRed).lineSpacing(3) }
        DorrTextField(value: agreementLimit($title, 160), label: L("wr_task_title"), placeholder: L("wr_task_title_hint"))
        DorrTextField(value: agreementLimit($description, 2000), label: L("wr_description"), singleLine: false, minLines: 3)
        if !parts.isEmpty {
            WorkLabel(text: L("wr_part"))
            PartPicker(parts: parts, selected: part) { part = $0 }
        }
        DateField(value: $due, label: L("wr_due_label"))
        if !teamOnly {
            WorkLabel(text: L("wr_assignee"))
            let sides: [(value: String, label: String)] = [(value: MY_SIDE, label: L("wr_side_me")), (value: "client", label: L("wr_side_client"))]
            ChipRow(options: sides, selected: [side], onToggle: { picked in
                side = picked
                if picked != MY_SIDE { person = nil }
            })
        }
        if side == MY_SIDE && !staff.isEmpty { personPicker }
        // FS-207: inside tasks of a team (the client never sees them) are chosen when a task is made.
        if team != nil && task == nil {
            WorkSwitchRow(title: L("wr_team_only"), hint: L("wr_team_only_hint"), on: teamOnly) {
                teamOnly.toggle()
                if teamOnly { side = MY_SIDE }
            }
        }
        WorkError(text: error)
        SettingsPrimaryButton(text: L("wr_save"), enabled: !title.agreementTrimmed.isEmpty && !atLimit, loading: busy, action: save)
        if let task, task.can?.taskDelete == true { deleteRow }
    }

    @ViewBuilder
    private var personPicker: some View {
        WorkLabel(text: L("wr_person"))
        let none: [(value: String, label: String)] = [(value: "", label: L("wr_nobody"))]
        let each: [(value: String, label: String)] = staff.map { (value: $0.freelancer ?? "", label: $0.name ?? "") }
        ChipRow(options: none + each, selected: [person ?? ""], onToggle: { person = $0.isEmpty ? nil : $0 })
    }

    private var deleteRow: some View {
        Button { confirmDelete = true } label: {
            HStack(spacing: 6) {
                Image(systemName: "trash").font(.system(size: 14, weight: .semibold))
                Text(L("wr_task_delete")).font(.dorr(14, .bold))
            }
            .foregroundStyle(WorkRed)
            .frame(maxWidth: .infinity)
            .padding(12)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(busy)
    }

    private func save() {
        let name = title.agreementTrimmed
        guard !name.isEmpty else { return }
        busy = true
        error = nil
        Task {
            let r: ApiResult<WorkTaskAnswerDto>
            if let uuid = task?.uuid {
                // An update sends everything; an empty text clears a part, a day or a person.
                let body: JsonBody = [
                    "title": name, "description": description.agreementTrimmed, "milestone": part ?? "", "due_on": due,
                    "assignee_side": side, "assignee": side == MY_SIDE ? (person ?? "") : "",
                ]
                r = await WorkroomApi.updateTask(project, uuid, body)
            } else {
                var body: JsonBody = ["title": name]
                if !description.agreementTrimmed.isEmpty { body["description"] = description.agreementTrimmed }
                if let part { body["milestone"] = part }
                if !due.isEmpty { body["due_on"] = due }
                body["assignee_side"] = teamOnly ? MY_SIDE : side
                if side == MY_SIDE, let person { body["assignee"] = person }
                if teamOnly { body["scope"] = "team" }
                r = await WorkroomApi.createTask(project, body)
            }
            busy = false
            if case .ok = r { onSaved() } else { error = r.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }

    private func delete() {
        guard let uuid = task?.uuid else { return }
        busy = true
        Task {
            let r = await WorkroomApi.deleteTask(project, uuid)
            busy = false
            confirmDelete = false
            if case .ok = r { onSaved() } else { error = r.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}
