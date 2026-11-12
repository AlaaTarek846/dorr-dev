import SwiftUI

// freelanceApp `ui/screens/workroom/WorkroomFileScreen.kt` — one file of the Workroom: its details, every version (newest first, each opens in
// the system viewer with my own sign-in) and the notes on it. It reads once when it opens, once on a live event for this file
// (`freelance.workroom` on the project's channel — a new version, a note, an edit), once on a notice and once when the connection returns;
// a burst of those is one read, and nothing runs on a timer.

/// What the file page holds: the file, its notes, what I may do and what a sheet needs.
@MainActor
final class FileModel: ObservableObject {
    let project: String
    let fileUuid: String
    @Published var file: WorkFileDto?
    @Published var failure: String?
    @Published var readOnly = false
    @Published var canNote = false
    @Published var rules = WorkUploadRulesDto()
    @Published var parts: [WorkPartDto] = []
    /// The version being fetched to open (its number), 0 when none.
    @Published var opening = 0
    @Published var shown: ProfileShownFile?
    let notes = PagedRows<WorkCommentDto>(perPage: WORK_PAGE, key: { $0.uuid ?? "" })
    private let burst = WorkDebouncer()
    /// FS-209: a team file (and the notes on it) travels on the team's channel: the team of the project, the one listened to, and whether the
    /// page is on show (nothing is listened to while it is not).
    private var team: String?
    private var listeningTeam: String?
    private var onScreen = false

    init(project: String, fileUuid: String) {
        self.project = project
        self.fileUuid = fileUuid
        notes.reset { [weak self] page in
            guard let self else { return .offline }
            return await self.fetchNotes(page)
        }
    }

    private func fetchNotes(_ page: Int) async -> ApiResult<[WorkCommentDto]> {
        let r = await WorkroomApi.comments(project, file: fileUuid, perPage: WORK_PAGE, page: page)
        if let data = r.value {
            canNote = data.can?.commentAdd ?? false
            readOnly = data.readOnly ?? false
        }
        return r.rows { $0.comments }
    }

    /// The file and then its notes.
    func load() async {
        let r = await WorkroomApi.file(project, fileUuid)
        if let data = r.value {
            if let f = data.file {
                file = f
                failure = nil
            } else if file == nil {
                failure = L("login_error_generic")
            }
        } else if file == nil {
            failure = r.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
        await notes.reload()
    }

    /// The parts and the upload rules a sheet needs (read once; the page works without them).
    func loadContext() async {
        if let agreement = await AgreementsApi.show(project).value {
            parts = (agreement.milestones ?? []).asParts()
            team = agreement.team?.uuid
            syncTeamChannel()
        }
        if let rows = await WorkroomApi.files(project, perPage: 1).value, let upload = rows.upload { rules = upload }
    }

    /// The page is on show: the team's channel is listened to as soon as the agreement says the project is a team's.
    func appear() {
        onScreen = true
        syncTeamChannel()
    }

    /// Listens to the team's channel while the page is on show and the project is a team's; lets go of it otherwise.
    private func syncTeamChannel() {
        let wanted = onScreen ? team : nil
        guard wanted != listeningTeam else { return }
        if let old = listeningTeam { Realtime.shared.stopListeningTeamWorkroom(old) }
        if let new = wanted { Realtime.shared.listenTeamWorkroom(new) }
        listeningTeam = wanted
    }

    func retry() {
        failure = nil
        Task { await load() }
    }

    /// Something changed (a live event, a notice, the connection back): wait for the burst to settle, then read once. The read runs apart
    /// from the waiting, so a newer event never cancels a read that is already on its way.
    func changed() {
        burst.fire { [weak self] in
            Task { await self?.load() }
        }
    }

    func stop() {
        burst.cancel()
        onScreen = false
        syncTeamChannel()
    }

    func open(_ v: WorkVersionDto) {
        guard opening == 0, let number = v.number else { return }
        opening = number
        Task {
            let viewer = await openVersion(project, fileUuid, number, v.name ?? "", v.mimeType ?? "")
            opening = 0
            if let viewer { shown = viewer } else { AppToast.show(L("wr_file_open_failed")) }
        }
    }

    /// A note was added or removed: the list is read again and the count on the file follows.
    func notesChanged(by delta: Int) {
        file?.commentsCount = Swift.max((file?.commentsCount ?? 0) + delta, 0)
        Task { await notes.reload() }
    }
}

struct WorkroomFileScreen: View {
    let project: String
    let fileUuid: String
    let onBack: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @StateObject private var model: FileModel

    @State private var input = ""
    @State private var sending = false
    @State private var editingFile = false
    @State private var addingVersion = false
    @State private var deleting = false
    @State private var editingNote: WorkCommentDto?
    @State private var deletingNote: WorkCommentDto?
    @State private var busy = false
    /// Kept here (not in a sheet) so the sheet stays open while a request runs.
    @State private var sheetBusy = false
    @State private var listening = false

    init(project: String, fileUuid: String, onBack: @escaping () -> Void) {
        self.project = project
        self.fileUuid = fileUuid
        self.onBack = onBack
        _model = StateObject(wrappedValue: FileModel(project: project, fileUuid: fileUuid))
    }

    private var canSend: Bool { !input.agreementTrimmed.isEmpty && !sending }

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: model.file?.title ?? L("wr_file"), onBack: onBack)
                content.frame(maxWidth: .infinity, maxHeight: .infinity)
                if model.file != nil && model.canNote && !model.readOnly { composer }
            }
        }
        .navigationBarHidden(true)
        // The page is a thread with a composer: it takes the whole screen (no tab bar over the keyboard) and listens to the project's channel.
        .onAppear {
            ShellState.shared.immersive = true
            model.appear()
            if !listening {
                listening = true
                Realtime.shared.listenWorkroom(project)
            }
        }
        .onDisappear {
            ShellState.shared.immersive = false
            model.stop()
            if listening {
                listening = false
                Realtime.shared.stopListeningWorkroom(project)
            }
        }
        .task(id: "\(project)|\(fileUuid)") {
            await model.load()
            await model.loadContext()
        }
        .onChange(of: network.reconnectTick) { _ in model.changed() }
        .onReceive(Realtime.shared.changed) { _ in model.changed() }
        // What the project's channel says about this file (or its notes) makes one new read; nothing carries the text.
        .onReceive(Realtime.shared.workroomEvents) { e in
            // An event of the team's channel names its project; one of another project's Workroom is not ours.
            if let other = e.data.text("project"), other != project { return }
            let kind = e.data.text("kind")
            let uuid = e.data.text("uuid")
            if (kind == "file" || kind == "comment") && (uuid == fileUuid || uuid == nil) { model.changed() }
        }
        .sheet(item: $model.shown) { ProfileFileViewer(url: $0.url).ignoresSafeArea() }
        .cmSheet(isPresented: $editingFile, fullHeight: true, locked: sheetBusy) { editFileSheet }
        .cmSheet(isPresented: $addingVersion, fullHeight: true, locked: sheetBusy) { addVersionSheet }
        .cmSheet(isPresented: cmPresence($editingNote), locked: sheetBusy) { editNoteSheet }
        .dorrDialog(isPresented: $deleting) { deleteFileDialog }
        .dorrDialog(isPresented: cmPresence($deletingNote)) { deleteNoteDialog }
    }

    // MARK: - content

    @ViewBuilder
    private var content: some View {
        if let f = model.file {
            thread(f)
        } else if let failure = model.failure {
            failureView(failure)
        } else {
            ScrollView { SkeletonList(count: 3).padding(16) }
        }
    }

    private func thread(_ f: WorkFileDto) -> some View {
        let versions = f.versions ?? []
        return ScrollView {
            LazyVStack(spacing: 10) {
                FileHead(
                    f: f, readOnly: model.readOnly, opening: model.opening != 0,
                    onOpen: { if let cur = f.current { model.open(cur) } },
                    onNewVersion: { addingVersion = true }, onEdit: { editingFile = true }, onDelete: { deleting = true }
                )
                SectionHeader(title: L("wr_versions", Swift.max(versions.count, f.versionsCount ?? 0)))
                ForEach(versions, id: \.number) { v in
                    VersionRow(v: v, opening: v.number != nil && model.opening == v.number) { model.open(v) }
                }
                SectionHeader(title: L("wr_notes"))
                NotesList(notes: model.notes, onEdit: { editingNote = $0 }, onDelete: { deletingNote = $0 })
            }
            .padding(.horizontal, 16)
            .padding(.top, 2)
            .padding(.bottom, 20)
        }
        .refreshable { await model.load() }
        .scrollDismissesKeyboard(.interactively)
    }

    private func failureView(_ failure: String) -> some View {
        VStack(spacing: 12) {
            Image(systemName: "exclamationmark.circle").font(.system(size: 40, weight: .regular)).foregroundStyle(wa.settingsMut)
            Text(verbatim: failure).font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
            Button { model.retry() } label: {
                Text(L("common_retry")).font(.dorr(14, .bold)).foregroundStyle(wa.red).padding(16).contentShape(Rectangle())
            }
            .buttonStyle(.plain)
        }
        .padding(32)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    private var composer: some View {
        HStack(spacing: 8) {
            DorrTextField(value: agreementLimit($input, 2000), placeholder: L("wr_note_hint"), singleLine: false)
                .frame(maxWidth: .infinity)
            Button(action: send) {
                Image(systemName: "paperplane.fill").font(.system(size: 17, weight: .semibold)).foregroundStyle(.white)
                    .flipsForRightToLeftLayoutDirection(true)
                    .frame(width: 44, height: 44)
                    .background(wa.red.opacity(canSend ? 1 : 0.4), in: Circle())
            }
            .buttonStyle(.plain)
            .disabled(!canSend)
            .accessibilityLabel(L("wr_note_send"))
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
    }

    // MARK: - actions

    private func send() {
        let text = input.agreementTrimmed
        guard !text.isEmpty, !sending else { return }
        sending = true
        Task {
            let r = await WorkroomApi.addComment(project, WorkCommentRequest(file: fileUuid, version: model.file?.current?.number, body: text))
            sending = false
            if case .ok = r {
                input = ""
                model.notesChanged(by: 1)
            } else {
                AppToast.show(r.errorText(L("login_error_offline")) ?? L("login_error_generic"))
            }
        }
    }

    private func deleteFile() {
        busy = true
        Task {
            let r = await WorkroomApi.deleteFile(project, fileUuid)
            busy = false
            deleting = false
            if case .ok = r { onBack() } else { AppToast.show(r.errorText(L("login_error_offline")) ?? L("login_error_generic")) }
        }
    }

    private func deleteNote(_ c: WorkCommentDto) {
        guard let uuid = c.uuid else { return }
        busy = true
        Task {
            let problem = await WorkroomApi.deleteComment(project, uuid, offline: L("login_error_offline"), generic: L("login_error_generic"))
            busy = false
            deletingNote = nil
            if let problem { AppToast.show(problem) } else { model.notesChanged(by: -1) }
        }
    }

    // MARK: - sheets and dialogs

    @ViewBuilder
    private var editFileSheet: some View {
        if let f = model.file {
            EditFileSheet(project: project, f: f, parts: model.parts, busy: $sheetBusy, onSaved: {
                editingFile = false
                Task { await model.load() }
            })
        }
    }

    @ViewBuilder
    private var addVersionSheet: some View {
        if let f = model.file {
            UploadSheet(project: project, existing: f, rules: model.rules, parts: [], team: nil, busy: $sheetBusy, onDone: {
                addingVersion = false
                Task { await model.load() }
            })
        }
    }

    @ViewBuilder
    private var editNoteSheet: some View {
        if let c = editingNote {
            EditNoteSheet(project: project, c: c, busy: $sheetBusy, onSaved: {
                editingNote = nil
                Task { await model.notes.reload() }
            })
        }
    }

    @ViewBuilder
    private var deleteFileDialog: some View {
        ConfirmDialog(
            icon: "trash", title: L("wr_file_delete"), message: L("wr_file_delete_ask"), loading: busy, confirmLabel: L("wr_delete"),
            onConfirm: deleteFile, onDismiss: { if !busy { deleting = false } }
        )
    }

    @ViewBuilder
    private var deleteNoteDialog: some View {
        if let c = deletingNote {
            ConfirmDialog(
                icon: "trash", title: L("wr_note_delete"), message: L("wr_note_delete_ask"), loading: busy, confirmLabel: L("wr_delete"),
                onConfirm: { deleteNote(c) }, onDismiss: { if !busy { deletingNote = nil } }
            )
        }
    }
}

/// The file's own card: a tile by its type, the title, the line about it, where it belongs, and what I may do with it.
private struct FileHead: View {
    @Environment(\.wa) private var wa
    let f: WorkFileDto
    let readOnly: Bool
    let opening: Bool
    let onOpen: () -> Void
    let onNewVersion: () -> Void
    let onEdit: () -> Void
    let onDelete: () -> Void

    var body: some View {
        let cur = f.current
        let kind = fileIcon(cur?.mimeType ?? "", cur?.name ?? "")
        WorkroomCard {
            HStack(spacing: 12) {
                Image(systemName: kind.icon).font(.system(size: 26, weight: .semibold)).foregroundStyle(kind.tint)
                    .frame(width: 54, height: 54)
                    .background(kind.tint.opacity(0.14), in: RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: f.title ?? "").font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(3)
                    if let cur {
                        Text(verbatim: line(cur)).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(2)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            if let text = f.description, !text.agreementTrimmed.isEmpty {
                Text(verbatim: text).font(.dorr(13.5)).foregroundStyle(wa.settingsInk).lineSpacing(4)
            }
            pills(cur)
            if let part = f.milestone { PartTag(part: part) }
            if cur != nil { SettingsPrimaryButton(text: L("wr_file_open"), loading: opening, action: onOpen) }
            if !readOnly { actions }
        }
    }

    private func line(_ v: WorkVersionDto) -> String {
        var items: [String] = []
        if let name = v.name, !name.agreementTrimmed.isEmpty { items.append(name) }
        items.append(fileSize(v.sizeBytes ?? 0))
        return items.joined(separator: " · ")
    }

    private func pills(_ cur: WorkVersionDto?) -> some View {
        HStack(spacing: 6) {
            if f.scope == "team" { TeamOnlyPill() }
            if cur?.previewOnly == true { PreviewOnlyPill() }
            StatusPill(text: sideName(f.createdBySide), tint: (f.createdBySide ?? MY_SIDE) == MY_SIDE ? Tints.teal : Tints.violet)
            Spacer(minLength: 0)
        }
    }

    @ViewBuilder
    private var actions: some View {
        let can = f.can
        if can?.versionAdd == true || can?.fileEdit == true || can?.fileDelete == true {
            HStack(spacing: 8) {
                if can?.versionAdd == true { WorkAction(icon: "icloud.and.arrow.up", label: L("wr_version_add"), tint: wa.red, onClick: onNewVersion) }
                if can?.fileEdit == true { WorkAction(icon: "pencil", label: L("wr_edit"), tint: wa.red, onClick: onEdit) }
                if can?.fileDelete == true { WorkAction(icon: "trash", label: L("wr_delete"), tint: WorkRed, onClick: onDelete) }
            }
        }
    }
}

/// One version: its number, who added it and when, its note, and a way to open it.
private struct VersionRow: View {
    @Environment(\.wa) private var wa
    let v: WorkVersionDto
    let opening: Bool
    let onOpen: () -> Void

    private var name: String {
        if let n = v.name, !n.agreementTrimmed.isEmpty { return n }
        return L("wr_file")
    }

    private var line: String {
        var items = [sideName(v.bySide), fileSize(v.sizeBytes ?? 0)]
        if let at = workDateTime(v.createdAt) { items.append(at) }
        return items.joined(separator: " · ")
    }

    var body: some View {
        WorkroomCard(onClick: onOpen) {
            HStack(spacing: 10) {
                Text(verbatim: "v\(v.number ?? 0)").font(.dorr(12, .black)).foregroundStyle(wa.red)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 4)
                    .background(wa.red.opacity(0.12), in: Capsule())
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: name).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Text(verbatim: line).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if opening {
                    ProgressView().tint(wa.red).frame(width: 20, height: 20)
                } else {
                    Image(systemName: "arrow.up.right.square").font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.red)
                        .frame(width: 20, height: 20)
                        .accessibilityLabel(L("wr_file_open"))
                }
            }
            if let note = v.note, !note.agreementTrimmed.isEmpty {
                Text(verbatim: note).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(4)
            }
            if v.previewOnly == true { PreviewOnlyPill() }
        }
    }
}

/// The notes on the file (a page at a time).
private struct NotesList: View {
    @Environment(\.wa) private var wa
    @ObservedObject var notes: PagedRows<WorkCommentDto>
    let onEdit: (WorkCommentDto) -> Void
    let onDelete: (WorkCommentDto) -> Void

    var body: some View {
        if notes.loaded && notes.rows.isEmpty {
            Text(L("wr_notes_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.vertical, 6)
        }
        ForEach(Array(notes.rows.enumerated()), id: \.element.uuid) { pair in
            NoteCard(c: pair.element, onEdit: { onEdit(pair.element) }, onDelete: { onDelete(pair.element) })
                .onAppear { notes.appeared(pair.offset) }
        }
        PagedFooter(paged: notes) { Task { await notes.loadMore() } }
    }
}

/// One note on the file: who wrote it (me or the client), when, the version it is about, and edit / delete for mine.
private struct NoteCard: View {
    @Environment(\.wa) private var wa
    let c: WorkCommentDto
    let onEdit: () -> Void
    let onDelete: () -> Void

    private var whenText: String? {
        guard let when = workWhen(c.createdAt) else { return nil }
        return c.editedAt != nil ? when + " · " + L("wr_edited") : when
    }

    var body: some View {
        let mine = c.mine == true
        let accent: Color? = mine ? wa.red : nil
        WorkroomCard(accent: accent) {
            HStack(spacing: 8) {
                Text(verbatim: sideName(c.authorSide)).font(.dorr(13, .heavy)).foregroundStyle(mine ? wa.red : wa.settingsInk)
                if let v = c.version { StatusPill(text: "v\(v)", tint: Tints.blue) }
                if c.scope == "team" { TeamOnlyPill() }
                Spacer(minLength: 0)
                if let whenText { Text(verbatim: whenText).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
            }
            Text(verbatim: c.body ?? "").font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(5)
            if c.can?.commentEdit == true || c.can?.commentDelete == true {
                HStack(spacing: 16) {
                    if c.can?.commentEdit == true {
                        Button(action: onEdit) { Text(L("wr_edit")).font(.dorr(12.5, .bold)).foregroundStyle(wa.red).contentShape(Rectangle()) }
                            .buttonStyle(.plain)
                    }
                    if c.can?.commentDelete == true {
                        Button(action: onDelete) { Text(L("wr_delete")).font(.dorr(12.5, .bold)).foregroundStyle(WorkRed).contentShape(Rectangle()) }
                            .buttonStyle(.plain)
                    }
                    Spacer(minLength: 0)
                }
            }
        }
    }
}

/// Change the title, the line about the file and the part it belongs to.
private struct EditFileSheet: View {
    let project: String
    let f: WorkFileDto
    let parts: [WorkPartDto]
    @Binding var busy: Bool
    let onSaved: () -> Void

    @State private var title: String
    @State private var text: String
    @State private var part: String?
    @State private var error: String?

    init(project: String, f: WorkFileDto, parts: [WorkPartDto], busy: Binding<Bool>, onSaved: @escaping () -> Void) {
        self.project = project
        self.f = f
        self.parts = parts
        _busy = busy
        self.onSaved = onSaved
        _title = State(initialValue: f.title ?? "")
        _text = State(initialValue: f.description ?? "")
        _part = State(initialValue: f.milestone?.uuid)
    }

    var body: some View {
        WorkSheet(title: L("wr_file_edit")) {
            DorrTextField(value: agreementLimit($title, 160), label: L("wr_file_title"))
            DorrTextField(value: agreementLimit($text, 1000), label: L("wr_description"), singleLine: false, minLines: 2)
            if !parts.isEmpty {
                WorkLabel(text: L("wr_part"))
                PartPicker(parts: parts, selected: part) { part = $0 }
            }
            WorkError(text: error)
            SettingsPrimaryButton(text: L("wr_save"), enabled: !title.agreementTrimmed.isEmpty, loading: busy, action: save)
        }
    }

    private func save() {
        guard let uuid = f.uuid else { return }
        busy = true
        error = nil
        Task {
            // An update sends everything; an empty text clears the part.
            let body: JsonBody = ["title": title.agreementTrimmed, "description": text.agreementTrimmed, "milestone": part ?? ""]
            let r = await WorkroomApi.updateFile(project, uuid, body)
            busy = false
            if case .ok = r { onSaved() } else { error = r.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}

/// Change the words of one of my notes.
private struct EditNoteSheet: View {
    let project: String
    let c: WorkCommentDto
    @Binding var busy: Bool
    let onSaved: () -> Void

    @State private var text: String
    @State private var error: String?

    init(project: String, c: WorkCommentDto, busy: Binding<Bool>, onSaved: @escaping () -> Void) {
        self.project = project
        self.c = c
        _busy = busy
        self.onSaved = onSaved
        _text = State(initialValue: c.body ?? "")
    }

    var body: some View {
        WorkSheet(title: L("wr_note_edit")) {
            DorrTextField(value: agreementLimit($text, 2000), singleLine: false, minLines: 3)
            WorkError(text: error)
            SettingsPrimaryButton(text: L("wr_save"), enabled: !text.agreementTrimmed.isEmpty, loading: busy, action: save)
        }
    }

    private func save() {
        guard let uuid = c.uuid else { return }
        busy = true
        error = nil
        Task {
            let r = await WorkroomApi.editComment(project, uuid, WorkCommentBodyRequest(body: text.agreementTrimmed))
            busy = false
            if case .ok = r { onSaved() } else { error = r.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}
