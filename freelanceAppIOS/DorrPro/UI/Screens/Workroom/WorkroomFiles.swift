import SwiftUI
import UniformTypeIdentifiers

// freelanceApp `ui/screens/workroom/WorkroomFiles.kt`.

/// The files of the Workroom: the read (search included), the numbers and what the upload takes.
@MainActor
final class FilesState: ObservableObject {
    let project: String
    /// What is typed in the search box, and what the last read used.
    @Published var query = ""
    @Published var applied = ""
    @Published var summary = WorkFileSummaryDto()
    @Published var limit = WorkFileLimitDto()
    @Published var rules = WorkUploadRulesDto()
    @Published var readOnly = false
    @Published var canUpload = false
    let feed = PagedRows<WorkFileDto>(perPage: WORK_PAGE, key: { $0.uuid ?? "" })
    private let typing = WorkDebouncer()

    init(project: String) {
        self.project = project
        feed.reset { [weak self] page in
            guard let self else { return .offline }
            return await self.fetch(page)
        }
    }

    /// A word typed in the search box is sent once the typing pauses (this answers the person's typing, nothing runs by itself).
    func typed(_ text: String) {
        query = String(text.prefix(100))
        typing.fire(after: 450_000_000) { [weak self] in self?.apply() }
    }

    private func apply() {
        guard query != applied else { return }
        applied = query
        Task { await feed.reload() }
    }

    func stop() { typing.cancel() }

    private func fetch(_ page: Int) async -> ApiResult<[WorkFileDto]> {
        let word = applied.agreementTrimmed
        let r = await WorkroomApi.files(project, search: word.isEmpty ? nil : word, perPage: WORK_PAGE, page: page)
        if let data = r.value {
            summary = data.summary ?? WorkFileSummaryDto()
            limit = data.limit ?? WorkFileLimitDto()
            rules = data.upload ?? WorkUploadRulesDto()
            readOnly = data.readOnly ?? false
            canUpload = data.can?.fileUpload ?? false
        }
        return r.rows { $0.files }
    }
}

/// The tab of the files: the numbers, the search, the cards (a tap opens the file) and the button to upload.
struct FilesTab: View {
    @ObservedObject var state: FilesState
    let project: String
    let parts: [WorkPartDto]
    let team: OfferTeamDto?
    let onOpenFile: (String) -> Void
    let onChanged: () -> Void

    @State private var adding = false
    /// Kept here (not in the sheet) so the sheet stays open while a request runs.
    @State private var sheetBusy = false

    private var canAdd: Bool { state.canUpload && !state.readOnly }

    private var empty: WorkEmpty {
        if state.applied.agreementTrimmed.isEmpty {
            return WorkEmpty(icon: "folder", title: L("wr_files_empty_title"), text: L("wr_files_empty_body"))
        }
        return WorkEmpty(icon: "magnifyingglass", title: L("wr_files_nomatch_title"), text: L("wr_files_nomatch_body"))
    }

    var body: some View {
        FeedList(
            feed: state.feed, keyOf: { $0.uuid ?? "" }, empty: empty,
            fabLabel: canAdd ? L("wr_file_upload") : nil, onFab: addAction,
            header: { header },
            row: { _, f in
                FileCard(f: f) { onOpenFile(f.uuid ?? "") }
            }
        )
        .cmSheet(isPresented: $adding, fullHeight: true, locked: sheetBusy) { addSheet }
    }

    private var addAction: (() -> Void)? {
        guard canAdd else { return nil }
        return { adding = true }
    }

    @ViewBuilder
    private var header: some View {
        VStack(spacing: 12) {
            if state.readOnly { ReadOnlyBanner() }
            FileNumbers(summary: state.summary, limit: state.limit)
            DorrTextField(value: Binding(get: { state.query }, set: { state.typed($0) }), placeholder: L("wr_files_search"), icon: "magnifyingglass")
        }
    }

    @ViewBuilder
    private var addSheet: some View {
        UploadSheet(
            project: project, existing: nil, rules: state.rules, parts: parts, team: team, busy: $sheetBusy,
            onDone: {
                adding = false
                Task { await state.feed.reload() }
                onChanged()
            }
        )
    }
}

/// What the Workroom holds: files, versions and the storage used (with the limit when there is one).
private struct FileNumbers: View {
    @Environment(\.wa) private var wa
    let summary: WorkFileSummaryDto
    let limit: WorkFileLimitDto

    var body: some View {
        let files = String(summary.files ?? 0) + (limit.maxFiles.map { " / \($0)" } ?? "")
        let storage = fileSize(summary.storageBytes ?? 0) + (limit.maxStorageMb.map { " / \($0) MB" } ?? "")
        HStack(spacing: 8) {
            FileCell(label: L("wr_n_files"), value: files)
            FileCell(label: L("wr_n_versions"), value: String(summary.versions ?? 0))
            FileCell(label: L("wr_n_storage"), value: storage)
        }
        .padding(14)
        .frame(maxWidth: .infinity)
        .background(wa.settingsMut.opacity(0.07), in: RoundedRectangle(cornerRadius: Corners.card, style: .continuous))
    }
}

private struct FileCell: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String

    var body: some View {
        VStack(spacing: 1) {
            Text(verbatim: value).font(.dorr(15, .black)).foregroundStyle(wa.settingsInk).lineLimit(1).minimumScaleFactor(0.7)
            Text(verbatim: label).font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut).lineLimit(1)
        }
        .frame(maxWidth: .infinity)
    }
}

/// One file: a tile by its type, the title, the newest version, who added it and what hangs on it (versions, notes).
private struct FileCard: View {
    @Environment(\.wa) private var wa
    let f: WorkFileDto
    let onClick: () -> Void

    var body: some View {
        let cur = f.current
        let kind = fileIcon(cur?.mimeType ?? "", cur?.name ?? "")
        WorkroomCard(onClick: onClick) {
            HStack(spacing: 12) {
                Image(systemName: kind.icon).font(.system(size: 20, weight: .semibold)).foregroundStyle(kind.tint)
                    .frame(width: 46, height: 46)
                    .background(kind.tint.opacity(0.14), in: RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: f.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    if let cur {
                        Text(verbatim: line(cur)).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            if let text = f.description, !text.agreementTrimmed.isEmpty {
                Text(verbatim: text).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3).lineLimit(2)
            }
            footer(cur)
            if let part = f.milestone { PartTag(part: part) }
        }
    }

    private func line(_ v: WorkVersionDto) -> String {
        var items: [String] = []
        if let name = v.name, !name.agreementTrimmed.isEmpty { items.append(name) }
        items.append(fileSize(v.sizeBytes ?? 0))
        return items.joined(separator: " · ")
    }

    private func footer(_ cur: WorkVersionDto?) -> some View {
        let versions = f.versionsCount ?? 1
        let notes = f.commentsCount ?? 0
        return HStack(spacing: 6) {
            if f.scope == "team" { TeamOnlyPill() }
            if cur?.previewOnly == true { PreviewOnlyPill() }
            if versions > 1 { StatusPill(text: L("wr_version_n", cur?.number ?? versions), tint: Tints.blue, icon: "clock.arrow.circlepath") }
            if notes > 0 { StatusPill(text: String(notes), tint: wa.red, icon: "bubble.left") }
            Spacer(minLength: 0)
            if let when = workWhen(cur?.createdAt ?? f.updatedAt ?? f.createdAt) {
                Text(verbatim: when).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
        }
    }
}

/// Uploading a file (the first version), or a new version of `existing`: pick the file (checked for size and type before it is sent),
/// its title, a line about it, the part it belongs to, and for a team's project whether it is only for the team. Shown with `.cmSheet`,
/// which closes itself — `onDone` is where the page reads again. `busy` lives in the page so the sheet stays open while it uploads.
struct UploadSheet: View {
    @Environment(\.wa) private var wa
    let project: String
    let existing: WorkFileDto?
    let rules: WorkUploadRulesDto
    let parts: [WorkPartDto]
    let team: OfferTeamDto?
    @Binding var busy: Bool
    let onDone: () -> Void

    @State private var picked: PickedFile?
    @State private var title = ""
    @State private var description = ""
    @State private var note = ""
    @State private var part: String?
    @State private var teamOnly = false
    @State private var error: String?
    @State private var picking = false
    @State private var reading = false

    private var maxKb: Int { rules.maxKb ?? 0 }

    var body: some View {
        WorkSheet(title: L(existing == nil ? "wr_file_upload" : "wr_version_add")) {
            chooser
            if existing == nil {
                DorrTextField(value: agreementLimit($title, 160), label: L("wr_file_title"))
                DorrTextField(value: agreementLimit($description, 1000), label: L("wr_description"), singleLine: false, minLines: 2)
                if !parts.isEmpty {
                    WorkLabel(text: L("wr_part"))
                    PartPicker(parts: parts, selected: part) { part = $0 }
                }
            }
            DorrTextField(
                value: agreementLimit($note, 500), label: L("wr_version_note"), placeholder: L("wr_version_note_hint"), singleLine: false, minLines: 2
            )
            if team != nil && existing == nil {
                WorkSwitchRow(title: L("wr_team_only"), hint: L("wr_team_only_file_hint"), on: teamOnly) { teamOnly.toggle() }
            }
            WorkError(text: error)
            SettingsPrimaryButton(text: L("wr_send"), enabled: picked != nil && !reading, loading: busy, action: send)
        }
        .fileImporter(isPresented: $picking, allowedContentTypes: [.item]) { result in
            if case .success(let url) = result { pick(url) }
        }
    }

    /// The file first: a big tile that turns into the chosen file.
    private var chooser: some View {
        Button { picking = true } label: {
            HStack(spacing: 12) {
                Image(systemName: picked == nil ? "icloud.and.arrow.up" : "paperclip").font(.system(size: 22, weight: .semibold))
                    .foregroundStyle(wa.red).frame(width: 26, height: 26)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: picked?.name ?? L("wr_file_pick")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Text(verbatim: chooserLine).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if reading { ProgressView().tint(wa.red) }
            }
            .padding(16)
            .frame(maxWidth: .infinity)
            .background(wa.red.opacity(0.08), in: RoundedRectangle(cornerRadius: Corners.card, style: .continuous))
            .contentShape(RoundedRectangle(cornerRadius: Corners.card, style: .continuous))
        }
        .buttonStyle(.plain)
        .disabled(busy || reading)
    }

    private var chooserLine: String {
        if let picked { return fileSize(picked.bytes.count) }
        return maxKb > 0 ? L("wr_file_rules", maxKb / 1024) : ""
    }

    /// Reads the chosen file and checks it against the server's rules before anything is sent.
    private func pick(_ url: URL) {
        reading = true
        Task {
            let file = await Task.detached { readFile(url) }.value
            reading = false
            let ext = ((file?.name ?? "") as NSString).pathExtension.lowercased()
            let allowed = (rules.mimes ?? []).map { $0.lowercased() }
            guard let file else {
                picked = nil
                error = L("wr_file_unreadable")
                return
            }
            if maxKb > 0 && file.bytes.count > maxKb * 1024 {
                picked = nil
                error = L("wr_file_too_large", maxKb / 1024)
            } else if !allowed.isEmpty && !allowed.contains(ext) {
                picked = nil
                error = L("wr_file_wrong_type", allowed.joined(separator: ", "))
            } else {
                picked = file
                error = nil
                if existing == nil && title.agreementTrimmed.isEmpty {
                    title = String((file.name as NSString).deletingPathExtension.prefix(160))
                }
            }
        }
    }

    private func send() {
        guard let file = picked else { return }
        busy = true
        error = nil
        Task {
            let upload = FilePart(field: "file", filename: file.name, mimeType: file.type, data: file.bytes)
            let text = note.agreementTrimmed
            let r: ApiResult<WorkFileAnswerDto>
            if let uuid = existing?.uuid {
                var fields: [String: String] = [:]
                if !text.isEmpty { fields["note"] = text }
                r = await WorkroomApi.addVersion(project, uuid, fields: fields, upload)
            } else {
                var fields: [String: String] = [:]
                if !title.agreementTrimmed.isEmpty { fields["title"] = title.agreementTrimmed }
                if !description.agreementTrimmed.isEmpty { fields["description"] = description.agreementTrimmed }
                if let part { fields["milestone"] = part }
                if !text.isEmpty { fields["note"] = text }
                if teamOnly { fields["scope"] = "team" }
                r = await WorkroomApi.uploadFile(project, fields: fields, upload)
            }
            busy = false
            if case .ok = r { onDone() } else { error = r.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}
