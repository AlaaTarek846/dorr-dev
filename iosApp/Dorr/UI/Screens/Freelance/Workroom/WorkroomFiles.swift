import SwiftUI
import UniformTypeIdentifiers

// androidApp `freelance/workroom/WorkroomFiles.kt` — the Workroom's File Hub and the sheet that uploads a file (or a new version of one).

/// The Workroom's File Hub: every file of the project, each with its versions and notes. A card per file (what it is, its newest version, who
/// added it, how many notes), a search, «Mine», and «Upload» for a new one. A file opens on its own page. Read once when it opens, on a live
/// event, on a pull and when the connection returns; the search waits for a pause in typing.
@MainActor
struct FilesTab: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let project: String
    let parts: [WorkPartDto]
    let freelancer: String
    @ObservedObject var live: WorkroomLive
    /// Whether this tab is the one on show: a hidden tab keeps what it holds but does not read again until it is back (Android drops the tab).
    let active: Bool
    let onOpenFile: (String) -> Void

    @State private var typed = ""
    @State private var search = ""
    @State private var mineOnly = false
    @State private var version = 0
    @State private var uploading = false
    @State private var uploadBusy = false
    @State private var notice: String?
    /// The first page's whole answer: the numbers, the upload rules, what I `can` do, whether the work is over.
    @State private var answer: WorkFilesDto?
    /// The filter the list was last started for (a new filter starts again from its skeleton; a live event only reads again).
    @State private var applied: String?
    @StateObject private var paged = PagedRows<WorkFileDto>(key: { $0.uuid })

    private var filterKey: String { "\(search)|\(mineOnly)" }

    var body: some View {
        let canUpload = answer != nil && answer?.can?.fileUpload == true && answer?.readOnly != true
        let filtered = !search.isEmpty || mineOnly
        ScrollView {
            LazyVStack(spacing: 10) {
                if let summary = answer?.summary { FilesSummary(summary: summary, limit: answer?.limit) }
                if canUpload { FlAction(text: L("wr_file_upload"), icon: "icloud.and.arrow.up", tone: .primary) { uploading = true } }
                if let notice { FlNotice(text: notice, icon: "checkmark.circle", color: wrGreen) }
                if answer?.readOnly == true { FlNotice(text: L("wr_read_only"), icon: "lock.fill", color: wrAmber) }
                if answer != nil && ((answer?.summary?.files ?? 0) > 0 || filtered) {
                    VStack(alignment: .leading, spacing: 8) {
                        searchField
                        WrChipRow { FlChip(text: L("wr_file_mine"), selected: mineOnly, icon: "person.fill") { mineOnly.toggle() } }
                    }
                }
                if !paged.loaded && paged.error == nil {
                    WrSkeleton(count: 3, height: 92)
                } else if paged.rows.isEmpty, let failed = paged.error {
                    FlRetry(message: failed)
                } else if paged.rows.isEmpty {
                    WrEmpty(icon: "folder", title: L(filtered ? "wr_file_none_filtered" : "wr_file_none"),
                            text: L(filtered ? "wr_file_none_filtered_body" : "wr_file_none_body"))
                }
                ForEach(Array(paged.rows.enumerated()), id: \.element.uuid) { i, file in
                    FileCard(file: file, freelancer: freelancer) { onOpenFile(file.uuid) }
                        .onAppear { paged.appeared(i) }
                }
                PagedSpinner(paged: paged)
                PagedError(paged: paged)
            }
            .padding(.horizontal, 16).padding(.top, 4).padding(.bottom, 28)
        }
        // Pulling down reads the list again (the client's own gesture, not a timer).
        .refreshable { await paged.reload() }
        // The search is asked 400 ms after the last key — a pause in typing, not a timer.
        .task(id: typed) {
            guard wrTrim(typed) != search else { return }
            try? await Task.sleep(nanoseconds: 400_000_000)
            if Task.isCancelled { return }
            search = wrTrim(typed)
        }
        // When it opens, on a live event (`freelance.workroom`), after a change of mine and when the connection returns — once each.
        .task(id: "\(filterKey)|\(network.reconnectTick)|\(live.files)|\(version)|\(active)") {
            if !active { return }
            if applied != filterKey {
                applied = filterKey
                let q: String? = search.isEmpty ? nil : search
                let m: Int? = mineOnly ? 1 : nil
                paged.reset { page in
                    let reply = try await FreelanceWorkroomApi.files(project, milestone: nil, mine: m, search: q, perPage: workPage, page: page)
                    return workRows(reply, page: page, meta: { answer = $0 }, rows: { $0.files ?? [] })
                }
            }
            await paged.reload()
        }
        .dorrDialog(isPresented: Binding(get: { uploading }, set: { if !$0 && !uploadBusy { uploading = false } }), bottom: true) {
            WorkUploadSheet(project: project, file: nil, parts: parts, rules: answer?.upload ?? WorkUploadRulesDto(), busy: $uploadBusy,
                            onDismiss: { uploading = false },
                            onDone: { uploading = false; notice = L("wr_file_uploaded"); version += 1 })
        }
    }

    /// The search of the files, with a cross to clear it.
    private var searchField: some View {
        DorrTextArea(value: Binding(get: { typed }, set: { typed = String($0.prefix(80)) }), placeholder: L("wr_file_search"), icon: "magnifyingglass", singleLine: true)
            .overlay(alignment: .trailing) {
                if !typed.isEmpty {
                    Button { typed = ""; search = "" } label: {
                        Image(systemName: "xmark.circle.fill").font(.system(size: 18)).foregroundStyle(wa.settingsMut).padding(.horizontal, 12).padding(.vertical, 10)
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("fl_remove"))
                }
            }
    }
}

private struct FilesSummary: View {
    @Environment(\.wa) private var wa
    let summary: WorkFileSummaryDto
    let limit: WorkFileLimitDto?

    var body: some View {
        HStack(spacing: 8) {
            FlStat(icon: "paperclip", label: L("wr_file_count"), value: "\(summary.files ?? 0)")
            FlStat(icon: "clock.arrow.circlepath", label: L("wr_file_versions"), value: "\(summary.versions ?? 0)")
            FlStat(icon: "externaldrive", label: L("wr_file_storage"),
                   value: wrSize(summary.storageBytes ?? 0) + (limit?.maxStorageMb.map { " / \($0) MB" } ?? ""))
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(FlCorners.card)
    }
}

private struct FileCard: View {
    @Environment(\.wa) private var wa
    let file: WorkFileDto
    let freelancer: String
    let onClick: () -> Void

    var body: some View {
        let current = file.current
        let versions = file.versionsCount ?? 1
        Button(action: onClick) {
            HStack(alignment: .top, spacing: 12) {
                Image(systemName: fileIcon(current?.mimeType ?? "", current?.name ?? "")).font(.system(size: 21, weight: .semibold)).foregroundStyle(wa.accent)
                    .frame(width: 46, height: 46)
                    .background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: FlCorners.icon, style: .continuous))
                VStack(alignment: .leading, spacing: 6) {
                    Text(verbatim: file.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2).lineSpacing(3)
                    if let current {
                        Text(verbatim: (current.name ?? "") + "  ·  " + wrSize(current.sizeBytes ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                    }
                    FlowLayout(spacing: 6) {
                        WrTag(text: versions > 1 ? L("wr_file_v_many", versions) : L("wr_file_v_one"), icon: "clock.arrow.circlepath", color: wrViolet)
                        if let part = file.milestone { WrTag(text: wrPartName(part), icon: "flag.fill", color: wa.accent) }
                        if (file.commentsCount ?? 0) > 0 { WrTag(text: "\(file.commentsCount ?? 0)", icon: "text.bubble", color: wrBlue) }
                        if current?.previewOnly == true { WrTag(text: L("wr_file_preview_tag"), icon: "lock.fill", color: wrAmber) }
                    }
                    Text(verbatim: L("wr_added_by", sideName(file.createdBySide, freelancer), wrDay(file.createdAt))).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
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

/// «Up to 20.0 MB · JPG, PNG, PDF…» — what the Workroom takes (a line to pick the file from the phone when it did not say).
private func wrRulesLine(_ rules: WorkUploadRulesDto) -> String {
    let limit = rules.maxKb ?? 0
    let size = limit > 0 ? L("wr_file_rule_size", wrSize(limit * 1024)) : ""
    let kinds = rules.mimes ?? []
    let list = kinds.prefix(8).map { $0.uppercased() }.joined(separator: ", ") + (kinds.count > 8 ? "…" : "")
    let line = [size, list].filter { !$0.isEmpty }.joined(separator: "  ·  ")
    return line.isEmpty ? L("wr_file_choose_hint") : line
}

/// Adds a file (a title, what it is, the part it belongs to, a note) or — when `file` is given — a new version of it (a note). The file is
/// picked from the phone; what the server accepts (size, kinds) is checked first when the Workroom told it. The server's own words show when it
/// refuses. `busy` is the parent's, so it does not close the sheet while a file is on its way.
@MainActor
struct WorkUploadSheet: View {
    @Environment(\.wa) private var wa
    let project: String
    let file: WorkFileDto?
    let parts: [WorkPartDto]
    let rules: WorkUploadRulesDto
    @Binding var busy: Bool
    let onDismiss: () -> Void
    let onDone: () -> Void

    @State private var picking = false
    @State private var picked: ApiClient.FilePart?
    @State private var title = ""
    @State private var description = ""
    @State private var part: String?
    @State private var note = ""
    @State private var error: String?

    /// A picked file is read now (it is sent with the request); one over what the Workroom takes is refused here, before it is read.
    private func pick(_ url: URL) {
        let scoped = url.startAccessingSecurityScopedResource()
        defer { if scoped { url.stopAccessingSecurityScopedResource() } }
        let name = url.lastPathComponent
        let ext = url.pathExtension.lowercased()
        let limit = (rules.maxKb ?? 0) * 1024
        let size = (try? url.resourceValues(forKeys: [.fileSizeKey]).fileSize) ?? 0
        if limit > 0 && size > limit { error = L("wr_file_too_big", wrSize(limit)); return }
        let kinds = (rules.mimes ?? []).map { $0.lowercased() }
        if !kinds.isEmpty && !kinds.contains(ext) { error = L("wr_file_type"); return }
        guard let data = try? Data(contentsOf: url) else { error = L("fl_dl_open_failed"); return }
        if limit > 0 && data.count > limit { error = L("wr_file_too_big", wrSize(limit)); return }
        error = nil
        let mime = UTType(filenameExtension: url.pathExtension)?.preferredMIMEType ?? "application/octet-stream"
        picked = ApiClient.FilePart(field: "file", filename: name, mimeType: mime, data: data)
        if file == nil && wrTrim(title).isEmpty { title = String((name as NSString).deletingPathExtension.prefix(160)) }
    }

    private func send() {
        guard let upload = picked else { return }
        busy = true
        error = nil
        Task {
            do {
                var fields: [String: String] = [:]
                if !wrTrim(note).isEmpty { fields["note"] = wrTrim(note) }
                if let file {
                    _ = try await FreelanceWorkroomApi.addVersion(project, file: file.uuid, fields: fields, upload: upload)
                } else {
                    if !wrTrim(title).isEmpty { fields["title"] = wrTrim(title) }
                    if !wrTrim(description).isEmpty { fields["description"] = wrTrim(description) }
                    if let part { fields["milestone"] = part }
                    _ = try await FreelanceWorkroomApi.uploadFile(project, fields: fields, file: upload)
                }
                onDone()
            } catch {
                self.error = flError(error)
            }
            busy = false
        }
    }

    var body: some View {
        FlSheet(icon: file == nil ? "icloud.and.arrow.up" : "arrow.up.doc", title: L(file == nil ? "wr_file_new" : "wr_file_new_version"),
                text: file == nil ? L("wr_file_new_body") : L("wr_file_new_version_body", file?.title ?? ""),
                footer: AnyView(VStack(spacing: 10) {
                    if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: wrRed) }
                    FlAction(text: L("wr_file_send"), icon: "icloud.and.arrow.up", tone: .primary,
                             enabled: picked != nil && (file != nil || !wrTrim(title).isEmpty), loading: busy) { send() }
                    FlAction(text: L("common_cancel"), enabled: !busy, action: onDismiss)
                })) {
            // The file itself: nothing chosen yet, or its name and size (tap to choose another).
            Button { picking = true } label: {
                HStack(spacing: 12) {
                    Image(systemName: picked == nil ? "paperclip" : fileIcon("", picked?.filename ?? "")).font(.system(size: 22, weight: .semibold)).foregroundStyle(wa.accent)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: picked?.filename ?? L("wr_file_choose")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2)
                        Text(verbatim: picked.map { L("wr_file_tap_change") + ($0.data.count > 0 ? "  ·  " + wrSize($0.data.count) : "") } ?? wrRulesLine(rules))
                            .font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    if picked != nil { Image(systemName: "checkmark").font(.system(size: 17, weight: .bold)).foregroundStyle(wrGreen) }
                }
                .padding(14)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(wa.accent.opacity(0.08), in: RoundedRectangle(cornerRadius: FlCorners.tile, style: .continuous))
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .disabled(busy)
            if file == nil {
                DorrTextArea(value: Binding(get: { title }, set: { title = String($0.prefix(160)) }), label: L("wr_file_title"), icon: "paperclip", singleLine: true)
                DorrTextArea(value: Binding(get: { description }, set: { description = String($0.prefix(1000)) }), label: L("wr_file_description"), minHeight: 70)
                if !parts.isEmpty {
                    VStack(alignment: .leading, spacing: 8) {
                        Text(verbatim: L("wr_part")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                        PartPicker(parts: parts, selected: part) { part = $0 }
                    }
                }
            }
            DorrTextArea(value: Binding(get: { note }, set: { note = String($0.prefix(500)) }), label: L("wr_file_note"), placeholder: L("wr_file_note_hint"), minHeight: 70)
        }
        .fileImporter(isPresented: $picking, allowedContentTypes: [.item]) { result in
            if case .success(let url) = result { pick(url) }
        }
    }
}
