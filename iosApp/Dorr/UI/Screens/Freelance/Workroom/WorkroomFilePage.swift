import SwiftUI
import UIKit

// androidApp `freelance/workroom/WorkroomFilePage.kt` — one file of the Workroom: its versions and the notes on it.

/// One file of the Workroom: what it is, every version of it (newest first; a picture shows in place, anything else opens in Quick Look), and
/// the notes both sides wrote on it. Its owner adds versions, changes its details or removes it; both sides write notes while the work is
/// running. Read once when it opens, on a live event (a new version, a note), on a pull and when the connection returns.
@MainActor
struct WorkroomFilePage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let project: String
    let fileUuid: String
    let onBack: () -> Void

    @StateObject private var live = WorkroomLive()
    @StateObject private var people = WorkroomPeople()
    @StateObject private var comments = PagedRows<WorkCommentDto>(key: { $0.uuid })
    @State private var file: WorkFileDto?
    @State private var failure: String?
    @State private var version = 0
    @State private var busy = false
    @State private var error: String?
    @State private var notice: String?
    @State private var newVersion = false
    @State private var versionBusy = false
    @State private var editing = false
    @State private var deleting = false
    @State private var viewing: UIImage?
    @State private var opening: Int?
    // The notes: all of them, or those on one version; oldest first.
    @State private var versionFilter: Int?
    @State private var commentsMeta: WorkCommentsDto?
    @State private var applied: String?
    @State private var draft = ""
    @State private var sending = false
    @State private var editingNote: WorkCommentDto?
    @State private var deletingNote: WorkCommentDto?
    @State private var scrollToEnd = false

    private func readFile() async {
        do {
            file = try await FreelanceWorkroomApi.file(project, file: fileUuid).data?.file
            failure = nil
        } catch {
            if Task.isCancelled { return }
            // The server's words, and whether the file is gone (its owner removed it).
            let refused = error.apiFailure
            if refused.httpStatus == 404 { file = nil }
            failure = refused.message ?? L("fl_error_generic")
        }
    }

    private func act(_ block: @escaping () async throws -> Void) {
        busy = true
        error = nil
        notice = nil
        Task {
            do { try await block() } catch { self.error = flError(error) }
            busy = false
        }
    }

    private func sendNote() {
        guard let f = file else { return }
        let text = wrTrim(draft)
        if text.isEmpty || sending { return }
        sending = true
        error = nil
        Task {
            do {
                _ = try await FreelanceWorkroomApi.addComment(project, request: WorkCommentRequest(file: f.uuid, version: versionFilter ?? f.current?.number, body: text))
                draft = ""
                scrollToEnd = true
                version += 1
            } catch {
                self.error = flError(error)
            }
            sending = false
        }
    }

    private func openVersion(_ f: WorkFileDto, _ v: WorkVersionDto) {
        guard let path = v.path else { return }
        opening = v.number
        Task {
            if !(await flOpenFile(path: path, name: "\(f.uuid.prefix(8))_v\(v.number ?? 0)_\(v.name ?? "file")")) { error = L("fl_dl_open_failed") }
            opening = nil
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: file?.title ?? L("wr_file_page"), onBack: onBack)
            if let f = file {
                list(f)
                composer(f)
            } else {
                FlLoading(error: failure)
            }
        }
        .workroomLive(project, live)
        // The agreement's parts (for the forms) once when it opens and when the connection returns.
        .task(id: network.reconnectTick) { await people.read(project) }
        // The file with its versions: when it opens, on a live event (also a note: its count changes), on a pull and when the connection returns.
        .task(id: "\(network.reconnectTick)|\(live.files)|\(version)") { await readFile() }
        // The notes: the same, and again for another version.
        .task(id: "\(versionFilter.map(String.init) ?? "")|\(network.reconnectTick)|\(live.comments)|\(version)") {
            let key = versionFilter.map(String.init) ?? ""
            if applied != key {
                applied = key
                let v = versionFilter
                comments.reset { page in
                    let reply = try await FreelanceWorkroomApi.comments(project, file: fileUuid, version: v, perPage: workPage, page: page)
                    return workRows(reply, page: page, meta: { commentsMeta = $0 }, rows: { $0.comments ?? [] })
                }
            }
            await comments.reload()
        }
        .dorrDialog(isPresented: Binding(get: { newVersion }, set: { if !$0 && !versionBusy { newVersion = false } }), bottom: true) {
            if let f = file {
                WorkUploadSheet(project: project, file: f, parts: people.parts, rules: WorkUploadRulesDto(), busy: $versionBusy,
                                onDismiss: { newVersion = false },
                                onDone: { newVersion = false; notice = L("wr_file_version_added"); version += 1 })
            }
        }
        .dorrDialog(isPresented: Binding(get: { editing }, set: { if !$0 && !busy { editing = false } }), bottom: true) {
            if let f = file {
                FileEditSheet(file: f, parts: people.parts, busy: busy, error: error, onDismiss: { if !busy { editing = false } }) { fields in
                    act {
                        _ = try await FreelanceWorkroomApi.updateFile(project, file: f.uuid, body: fields)
                        editing = false
                        version += 1
                    }
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { deleting }, set: { if !$0 && !busy { deleting = false } })) {
            if let f = file {
                ConfirmDialog(icon: "trash.fill", title: L("wr_file_delete_title"), message: L("wr_file_delete_body", f.title ?? ""),
                              loading: busy, confirmLabel: L("wr_delete"), confirmTint: wrRed,
                              onConfirm: { act { _ = try await FreelanceWorkroomApi.deleteFile(project, file: f.uuid); deleting = false; onBack() } },
                              onDismiss: { if !busy { deleting = false } })
            }
        }
        .dorrDialog(isPresented: Binding(get: { editingNote != nil }, set: { if !$0 && !busy { editingNote = nil } }), bottom: true) {
            if let note = editingNote {
                NoteEditSheet(note: note, busy: busy, error: error, onDismiss: { if !busy { editingNote = nil } }) { text in
                    act {
                        _ = try await FreelanceWorkroomApi.editComment(project, comment: note.uuid, body: text)
                        editingNote = nil
                        version += 1
                    }
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { deletingNote != nil }, set: { if !$0 && !busy { deletingNote = nil } })) {
            if let note = deletingNote {
                ConfirmDialog(icon: "trash.fill", title: L("wr_note_delete_title"), message: L("wr_note_delete_body"),
                              loading: busy, confirmLabel: L("wr_delete"), confirmTint: wrRed,
                              onConfirm: { act { _ = try await FreelanceWorkroomApi.deleteComment(project, comment: note.uuid); deletingNote = nil; version += 1 } },
                              onDismiss: { if !busy { deletingNote = nil } })
            }
        }
        .fullScreenCover(isPresented: Binding(get: { viewing != nil }, set: { if !$0 { viewing = nil } })) {
            if let image = viewing { RightsImageViewer(image: image) { viewing = nil } }
        }
    }

    /// The file's head, its versions and the notes on it.
    private func list(_ f: WorkFileDto) -> some View {
        let versions = f.versions ?? []
        return ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(spacing: 10) {
                    FileHead(file: f, freelancer: people.freelancer)
                    if let notice { FlNotice(text: notice, icon: "checkmark.circle", color: wrGreen) }
                    if f.can?.versionAdd == true { FlAction(text: L("wr_file_new_version"), icon: "arrow.up.doc", tone: .primary) { newVersion = true } }
                    if f.can?.fileEdit == true || f.can?.fileDelete == true {
                        HStack(spacing: 10) {
                            if f.can?.fileEdit == true { FlAction(text: L("wr_edit"), icon: "pencil") { editing = true } }
                            if f.can?.fileDelete == true { FlAction(text: L("wr_delete"), icon: "trash", tone: .danger) { deleting = true } }
                        }
                    }
                    FlSectionHeader(title: L("wr_file_versions_title", max(versions.count, f.versionsCount ?? 0))).padding(.top, 6)
                    ForEach(Array(versions.enumerated()), id: \.offset) { _, v in
                        VersionCard(file: f, v: v, latest: v.number == f.current?.number, freelancer: people.freelancer, opening: opening != nil && opening == v.number,
                                    onView: { viewing = $0 }, onOpen: { openVersion(f, v) })
                    }
                    FlSectionHeader(title: L("wr_notes_title")).padding(.top, 6)
                    if (f.versionsCount ?? 0) > 1 {
                        WrChipRow {
                            FlChip(text: L("wr_all"), selected: versionFilter == nil) { versionFilter = nil }
                            ForEach(Array(versions.enumerated()), id: \.offset) { _, v in
                                if let n = v.number { FlChip(text: "v\(n)", selected: versionFilter == n) { versionFilter = versionFilter == n ? nil : n } }
                            }
                        }
                    }
                    if comments.loaded && comments.rows.isEmpty {
                        WrEmpty(icon: "text.bubble", title: L("wr_notes_none"), text: L("wr_notes_none_body"))
                    }
                    ForEach(Array(comments.rows.enumerated()), id: \.element.uuid) { i, c in
                        NoteCard(c: c, freelancer: people.freelancer, onEdit: { editingNote = c }, onDelete: { deletingNote = c })
                            .onAppear { comments.appeared(i) }
                    }
                    PagedSpinner(paged: comments)
                    PagedError(paged: comments)
                    Color.clear.frame(height: 1).id("end")
                }
                .padding(.horizontal, 16).padding(.top, 4).padding(.bottom, 20)
            }
            // Pulling down reads the file and its notes again (the client's own gesture, not a timer).
            .refreshable {
                await readFile()
                await comments.reload()
            }
            // After a note of mine, down to it (once every page of the notes is in).
            .onChange(of: comments.rows.count) { _ in
                if scrollToEnd && !comments.loading && !comments.hasMore {
                    withAnimation { proxy.scrollTo("end", anchor: .bottom) }
                    scrollToEnd = false
                }
            }
        }
    }

    /// Writing a note: while the work runs (the server says so in `can`); the work being over says so instead.
    @ViewBuilder
    private func composer(_ f: WorkFileDto) -> some View {
        let canNote = commentsMeta?.can?.commentAdd ?? f.can?.commentAdd ?? false
        VStack(spacing: 8) {
            if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: wrRed) }
            if canNote {
                HStack(alignment: .bottom, spacing: 8) {
                    DorrTextArea(value: Binding(get: { draft }, set: { draft = String($0.prefix(2000)) }),
                                 placeholder: versionFilter.map { L("wr_note_hint_v", $0) } ?? L("wr_note_hint"), minHeight: 46)
                    let blocked = wrTrim(draft).isEmpty || sending
                    Button(action: sendNote) {
                        ZStack {
                            if sending {
                                ProgressView().tint(.white)
                            } else {
                                Image(systemName: "paperplane.fill").font(.system(size: 17, weight: .semibold)).foregroundStyle(.white)
                                    .flipsForRightToLeftLayoutDirection(true)
                            }
                        }
                        .frame(width: 44, height: 44)
                        .background(wa.accent.opacity(blocked ? 0.4 : 1), in: Circle())
                    }
                    .buttonStyle(.plain)
                    .disabled(blocked)
                    .accessibilityLabel(L("wr_note_send"))
                }
            } else if commentsMeta?.readOnly == true {
                FlNotice(text: L("wr_read_only"), icon: "lock.fill", color: wrAmber)
            }
        }
        .padding(.horizontal, canNote ? 12 : 16).padding(.vertical, 8)
    }
}

/// The file's own card: its icon, its name, what it is, the part it belongs to and who added it.
private struct FileHead: View {
    @Environment(\.wa) private var wa
    let file: WorkFileDto
    let freelancer: String

    var body: some View {
        let current = file.current
        let versions = file.versionsCount ?? 1
        HStack(alignment: .top, spacing: 14) {
            Image(systemName: fileIcon(current?.mimeType ?? "", current?.name ?? "")).font(.system(size: 25, weight: .semibold)).foregroundStyle(wa.accent)
                .frame(width: 54, height: 54)
                .background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: FlCorners.icon, style: .continuous))
            VStack(alignment: .leading, spacing: 7) {
                Text(verbatim: file.title ?? "").font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                if let text = file.description, !wrTrim(text).isEmpty {
                    Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                }
                FlowLayout(spacing: 6) {
                    if let part = file.milestone { WrTag(text: wrPartName(part), icon: "flag.fill", color: wa.accent) }
                    WrTag(text: versions > 1 ? L("wr_file_v_many", versions) : L("wr_file_v_one"), icon: "clock.arrow.circlepath", color: wrViolet)
                }
                Text(verbatim: L("wr_added_by", sideName(file.createdBySide, freelancer), wrDay(file.createdAt))).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(FlCorners.card)
    }
}

/// One version: its number, its file, who sent it and when, the note that came with it, and «Open» (or the lock when it is only a preview).
private struct VersionCard: View {
    @Environment(\.wa) private var wa
    let file: WorkFileDto
    let v: WorkVersionDto
    let latest: Bool
    let freelancer: String
    let opening: Bool
    let onView: (UIImage) -> Void
    let onOpen: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 10) {
                Text(verbatim: "v\(v.number ?? 0)").font(.dorr(12, .heavy)).foregroundStyle(.white)
                    .padding(.horizontal, 10).padding(.vertical, 4)
                    .background(latest ? wa.accent : wrGray, in: Capsule())
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: v.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    Text(verbatim: wrSize(v.sizeBytes ?? 0) + "  ·  " + sideName(v.bySide, freelancer) + "  ·  " + wrLocalDateTime(v.createdAt))
                        .font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if latest { FlDotPill(text: L("wr_file_latest"), color: wrGreen) }
            }
            if let note = v.note, !wrTrim(note).isEmpty {
                Text(verbatim: note).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(4)
            }
            // The newest picture shows in place (tap: in the app's own viewer).
            if latest, v.isImage == true, let path = v.path {
                VersionImage(id: "\(file.uuid)|\(v.number ?? 0)", path: path, name: v.name ?? "", onView: onView)
            }
            if v.previewOnly == true {
                Text(verbatim: "🔒 " + L("rights_preview_locked")).font(.dorr(11.5, .semibold)).foregroundStyle(wrAmber).lineSpacing(3)
            } else if v.path != nil {
                FlAction(text: L("wr_file_open"), icon: "arrow.down.circle", enabled: !opening, loading: opening, action: onOpen)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(FlCorners.tile)
    }
}

/// A picture of a version, read with my sign-in (never a public link) and shown at a size that fits a phone card.
@MainActor
private struct VersionImage: View {
    @Environment(\.wa) private var wa
    let id: String
    let path: String
    let name: String
    let onView: (UIImage) -> Void
    @State private var image: UIImage?

    var body: some View {
        Group {
            if let image {
                Image(uiImage: image).resizable().scaledToFit()
                    .frame(maxWidth: .infinity, maxHeight: 320)
                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .onTapGesture { onView(image) }
                    .accessibilityLabel(name)
                    .accessibilityAddTraits(.isButton)
            } else {
                Text(verbatim: "🖼 \(name)").font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
        }
        .task(id: id) {
            guard let data = try? await FreelanceApi.file(path) else { return }
            // Decoded at a size that fits a phone card; a huge picture never fills the memory.
            image = Self.downsample(data, max: 1200)
        }
    }

    private static func downsample(_ data: Data, max: CGFloat) -> UIImage? {
        guard let source = CGImageSourceCreateWithData(data as CFData, nil) else { return nil }
        let options: [CFString: Any] = [kCGImageSourceCreateThumbnailFromImageAlways: true, kCGImageSourceCreateThumbnailWithTransform: true,
                                        kCGImageSourceThumbnailMaxPixelSize: max]
        return CGImageSourceCreateThumbnailAtIndex(source, 0, options as CFDictionary).map { UIImage(cgImage: $0) }
    }
}

/// A note on the file: who wrote it, on which version, when (and «edited»); the writer may change or remove it.
private struct NoteCard: View {
    @Environment(\.wa) private var wa
    let c: WorkCommentDto
    let freelancer: String
    let onEdit: () -> Void
    let onDelete: () -> Void

    var body: some View {
        let name = sideName(c.authorSide, freelancer)
        let card = HStack(alignment: .top, spacing: 10) {
            FlAvatar(name: name, url: nil, size: 34)
            VStack(alignment: .leading, spacing: 5) {
                HStack(spacing: 8) {
                    Text(verbatim: name).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    if let v = c.version { WrTag(text: "v\(v)", color: wrViolet) }
                    Text(verbatim: wrLocalDateTime(c.createdAt) + (c.editedAt != nil ? "  ·  " + L("wr_note_edited") : "")).font(.dorr(11))
                        .foregroundStyle(wa.settingsMut).lineLimit(1)
                }
                Text(verbatim: c.body ?? "").font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(5)
                if c.can?.commentEdit == true || c.can?.commentDelete == true {
                    HStack(spacing: 14) {
                        if c.can?.commentEdit == true { WrLink(text: L("wr_edit"), icon: "pencil", color: wa.accent, action: onEdit) }
                        if c.can?.commentDelete == true { WrLink(text: L("wr_delete"), icon: "trash", color: wrRed, action: onDelete) }
                    }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        if c.mine == true {
            card.background(wa.accent.opacity(0.08), in: RoundedRectangle(cornerRadius: FlCorners.tile, style: .continuous))
        } else {
            card.settingsSurface(FlCorners.tile)
        }
    }
}

private struct NoteEditSheet: View {
    let note: WorkCommentDto
    let busy: Bool
    let error: String?
    let onDismiss: () -> Void
    let onSave: (String) -> Void
    @State private var text: String

    init(note: WorkCommentDto, busy: Bool, error: String?, onDismiss: @escaping () -> Void, onSave: @escaping (String) -> Void) {
        self.note = note
        self.busy = busy
        self.error = error
        self.onDismiss = onDismiss
        self.onSave = onSave
        _text = State(initialValue: note.body ?? "")
    }

    var body: some View {
        FlSheet(icon: "pencil", title: L("wr_note_edit"), text: "",
                footer: AnyView(VStack(spacing: 10) {
                    if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: wrRed) }
                    FlAction(text: L("common_save"), icon: "checkmark", tone: .primary,
                             enabled: !wrTrim(text).isEmpty && wrTrim(text) != (note.body ?? ""), loading: busy) { onSave(wrTrim(text)) }
                    FlAction(text: L("common_cancel"), enabled: !busy, action: onDismiss)
                })) {
            DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(2000)) }), minHeight: 120)
        }
    }
}

/// The file's details: its name, what it is and the part it belongs to (its versions stay as they are).
private struct FileEditSheet: View {
    @Environment(\.wa) private var wa
    let file: WorkFileDto
    let parts: [WorkPartDto]
    let busy: Bool
    let error: String?
    let onDismiss: () -> Void
    let onSave: ([String: JSONValue]) -> Void
    @State private var title: String
    @State private var description: String
    @State private var part: String?

    init(file: WorkFileDto, parts: [WorkPartDto], busy: Bool, error: String?, onDismiss: @escaping () -> Void, onSave: @escaping ([String: JSONValue]) -> Void) {
        self.file = file
        self.parts = parts
        self.busy = busy
        self.error = error
        self.onDismiss = onDismiss
        self.onSave = onSave
        _title = State(initialValue: file.title ?? "")
        _description = State(initialValue: file.description ?? "")
        _part = State(initialValue: file.milestone?.uuid)
    }

    var body: some View {
        FlSheet(icon: "pencil", title: L("wr_file_edit"), text: L("wr_file_edit_body"),
                footer: AnyView(VStack(spacing: 10) {
                    if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: wrRed) }
                    FlAction(text: L("common_save"), icon: "checkmark", tone: .primary, enabled: !wrTrim(title).isEmpty, loading: busy) {
                        let fields: [String: JSONValue] = [
                            "title": .string(wrTrim(title)),
                            "description": .string(wrTrim(description)),
                            "milestone": .string(part ?? ""),
                        ]
                        onSave(fields)
                    }
                    FlAction(text: L("common_cancel"), enabled: !busy, action: onDismiss)
                })) {
            DorrTextArea(value: Binding(get: { title }, set: { title = String($0.prefix(160)) }), label: L("wr_file_title"), singleLine: true)
            DorrTextArea(value: Binding(get: { description }, set: { description = String($0.prefix(1000)) }), label: L("wr_file_description"), minHeight: 90)
            if !parts.isEmpty {
                VStack(alignment: .leading, spacing: 8) {
                    Text(verbatim: L("wr_part")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    PartPicker(parts: parts, selected: part) { part = $0 }
                }
            }
        }
    }
}
