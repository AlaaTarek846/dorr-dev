import SwiftUI

// androidApp `freelance/FreelanceTalent.kt` — FS-103, the client's talent lists: lists of freelancers (a name, an optional specialty),
// a note and a private reminder on each one, and «Invite to a project» for a whole list. Only the client sees them — the freelancer is
// never told he was saved. Each screen reads once when it opens (and when the connection comes back) and again after every action;
// nothing is asked on a timer. (A form that Android opens over another sheet takes the place of the sheet here: one dialog at a time.)

private struct TalentAvatar: View {
    @Environment(\.wa) private var wa
    let name: String?
    let url: String?
    var size: CGFloat = 46

    var body: some View {
        ZStack {
            Circle().fill(wa.accent.opacity(0.12))
            if let url, !url.isEmpty, let u = URL(string: url) {
                AsyncImage(url: u) { $0.resizable().scaledToFill() } placeholder: { Color.clear }
            } else {
                Text(verbatim: String((name ?? "").prefix(1))).font(.dorr(size / 2.4, .heavy)).foregroundStyle(wa.accent)
            }
        }
        .frame(width: size, height: size)
        .clipShape(Circle())
    }
}

// MARK: - My lists

@MainActor
struct TalentListsPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpen: (String) -> Void
    @State private var filter: Int?
    @State private var data: TalentListsDto?
    // The specialties my lists are kept under (seen on a read without a filter) — the chips.
    @State private var known: [TalentSpecialtyDto] = []
    @State private var error: String?
    @State private var version = 0
    @State private var creating = false
    @State private var editing: TalentListDto?
    @State private var deleting: TalentListDto?
    @State private var busy = false
    @State private var failure: String?

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: L("fs_tp_title"), onBack: onBack,
                         trailing: AnyView(FlHeaderAction(text: L("fs_tp_new"), icon: "plus") { creating = true }))
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 10) {
                    FlHint(text: L("fs_tp_private"))
                    if !known.isEmpty || filter != nil {
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 8) {
                                FlChip(text: L("fl_filter_all"), selected: filter == nil, icon: "square.grid.2x2") { filter = nil }
                                ForEach(known, id: \.id) { s in
                                    FlChip(text: s.name ?? "—", selected: filter == s.id, icon: "square.grid.2x2.fill") { filter = s.id }
                                }
                            }
                        }
                    }
                    if let failure { FlNotice(text: failure, icon: "exclamationmark.circle", color: flRed) }
                    listBody
                }
                .padding(.horizontal, 16).padding(.vertical, 10)
            }
            .refreshable { network.noticeArrived() }
        }
        .task(id: "\(network.reconnectTick)|\(version)|\(filter ?? -1)") { await load() }
        .dorrDialog(isPresented: $creating, bottom: true) {
            TalentFormSheet(title: L("fs_tp_new"), initialName: "", initialSpecialty: nil, onDismiss: { creating = false }) { name, specialty in
                _ = try await FreelanceTalentApi.create(name: name, specialtyId: specialty)
                version += 1
            }
        }
        .dorrDialog(isPresented: Binding(get: { editing != nil }, set: { if !$0 { editing = nil } }), bottom: true) {
            if let l = editing {
                TalentFormSheet(title: L("fs_tp_rename"), initialName: l.name ?? "", initialSpecialty: l.specialty, onDismiss: { editing = nil }) { name, specialty in
                    _ = try await FreelanceTalentApi.update(l.uuid, name: name, specialtyId: specialty)
                    version += 1
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { deleting != nil }, set: { if !$0 && !busy { deleting = nil } })) {
            if let l = deleting {
                ConfirmDialog(icon: "trash", title: L("fs_tp_delete"), message: L("fs_tp_delete_confirm", l.name ?? ""), loading: busy,
                              confirmLabel: L("fs_tp_delete"), onConfirm: { remove(l) }, onDismiss: { deleting = nil })
            }
        }
    }

    @ViewBuilder
    private var listBody: some View {
        if let d = data {
            let lists = d.lists ?? []
            if lists.isEmpty {
                WaEmpty(icon: "person.3.fill", tone: .pink, title: L("fs_tp_title"), text: L("fs_tp_empty"))
            } else {
                ForEach(lists) { l in
                    HStack(spacing: 4) {
                        Button { onOpen(l.uuid) } label: {
                            HStack(spacing: 12) {
                                Image(systemName: "person.3.fill").font(.system(size: 18, weight: .semibold)).foregroundStyle(wa.accent)
                                    .frame(width: 44, height: 44)
                                    .background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(verbatim: l.name ?? "—").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                                    Text(verbatim: [l.specialty?.name, L("fs_tp_count", l.count ?? 0)].compactMap { $0 }.joined(separator: "  ·  "))
                                        .font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                                }
                                .frame(maxWidth: .infinity, alignment: .leading)
                            }
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        Button { editing = l } label: {
                            Image(systemName: "pencil").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 36, height: 36)
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel(L("fs_tp_rename"))
                        Button { deleting = l } label: {
                            Image(systemName: "trash").font(.system(size: 15, weight: .semibold)).foregroundStyle(flRed).frame(width: 36, height: 36)
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel(L("fs_tp_delete"))
                    }
                    .padding(14)
                    .settingsSurface(FlCorners.card)
                }
                Text(verbatim: L("fs_tp_max", d.maxLists ?? 20)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).padding(.horizontal, 4)
            }
        } else if let error {
            FlRetry(message: error)
        } else {
            ForEach(0..<4, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 76) }
        }
    }

    private func load() async {
        do {
            let d = try await FreelanceTalentApi.lists(specialtyId: filter).data ?? TalentListsDto()
            data = d
            error = nil
            if filter == nil {
                var seen = Set<Int>()
                known = (d.lists ?? []).compactMap { $0.specialty }.filter { seen.insert($0.id).inserted }
            }
        } catch {
            if Task.isCancelled { return }
            self.error = flError(error)
        }
    }

    private func remove(_ l: TalentListDto) {
        busy = true
        Task {
            do {
                _ = try await FreelanceTalentApi.delete(l.uuid)
                failure = nil
                version += 1
            } catch {
                failure = flError(error)
            }
            busy = false
            deleting = nil
        }
    }
}

/// The form of a list: a name and a specialty (or none). `submit` throws what the server refused; the sheet shows it and stays open.
@MainActor
struct TalentFormSheet: View {
    @Environment(\.wa) private var wa
    let title: String
    let onDismiss: () -> Void
    let submit: (String, Int?) async throws -> Void
    @State private var name: String
    @State private var specialty: TalentSpecialtyDto?
    @State private var picking = false
    @State private var busy = false
    @State private var error: String?

    init(title: String, initialName: String, initialSpecialty: TalentSpecialtyDto?, onDismiss: @escaping () -> Void, submit: @escaping (String, Int?) async throws -> Void) {
        self.title = title
        self.onDismiss = onDismiss
        self.submit = submit
        _name = State(initialValue: initialName)
        _specialty = State(initialValue: initialSpecialty)
    }

    var body: some View {
        if picking {
            TalentSpecialtySheet(onBack: { picking = false }, onPick: { specialty = $0; picking = false })
        } else {
            FlSheet(icon: "person.3.fill", title: title, text: L("fs_tp_form_hint")) {
                DorrTextArea(value: Binding(get: { name }, set: { name = String($0.prefix(80)) }), label: L("fs_tp_name"), icon: "pencil", singleLine: true)
                FlHeading(icon: "square.grid.2x2.fill", text: L("fs_tp_specialty"), hint: L("fs_tp_specialty_hint"))
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        FlChip(text: L("fs_tp_any"), selected: specialty == nil) { specialty = nil }
                        FlChip(text: specialty?.name ?? L("fl_pick_specialty"), selected: specialty != nil, icon: "square.grid.2x2.fill") { picking = true }
                    }
                }
                if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed) }
                FlAction(text: L("common_save"), icon: "checkmark", tone: .primary,
                         enabled: !name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty, loading: busy) { save() }
            }
        }
    }

    private func save() {
        busy = true
        error = nil
        Task {
            do {
                try await submit(name.trimmingCharacters(in: .whitespacesAndNewlines), specialty?.id)
                onDismiss()
            } catch {
                self.error = flError(error)
            }
            busy = false
        }
    }
}

/// The specialty tree, read once: a parent is a heading, its children are picked (a parent without children is picked itself).
@MainActor
private struct TalentSpecialtySheet: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let onPick: (TalentSpecialtyDto) -> Void
    @State private var tree: [FreelanceSpecialtyNodeDto]?

    var body: some View {
        FlSheet(icon: "square.grid.2x2.fill", title: L("fl_pick_specialty"), text: "") {
            if let nodes = tree {
                ForEach(nodes, id: \.id) { parent in
                    let children = parent.children ?? []
                    if children.isEmpty {
                        row(parent.name ?? "—", bold: true) { onPick(TalentSpecialtyDto(id: parent.id, name: parent.name)) }
                    } else {
                        Text(verbatim: parent.name ?? "—").font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut).padding(.top, 6)
                        ForEach(children, id: \.id) { child in
                            row(child.name ?? "—", bold: false) { onPick(TalentSpecialtyDto(id: child.id, name: child.name)) }
                        }
                    }
                }
            } else {
                ForEach(0..<5, id: \.self) { _ in WaSkeleton(cornerRadius: 12).frame(height: 40) }
            }
            FlAction(text: L("common_cancel"), action: onBack)
        }
        .task { tree = (try? await FreelanceApi.specialties().data) ?? [] }
    }

    private func row(_ text: String, bold: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(14, bold ? .bold : .regular)).foregroundStyle(wa.settingsInk)
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.vertical, 12).padding(.horizontal, 12)
                .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - One list

@MainActor
struct TalentListPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let uuid: String
    let onBack: () -> Void
    let onGone: () -> Void
    @State private var list: TalentListDetailDto?
    @State private var error: String?
    @State private var failure: String?
    @State private var busy = false
    @State private var renaming = false
    @State private var deleting = false
    @State private var noteFor: TalentMemberDto?
    @State private var remindFor: TalentMemberDto?
    @State private var removeFor: TalentMemberDto?
    @State private var inviting = false
    @State private var result: TalentInviteResultDto?

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: list?.name ?? L("fs_tp_title"), onBack: onBack)
            if let l = list {
                ScrollView { content(l).padding(.horizontal, 16) }.refreshable { network.noticeArrived() }
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: network.reconnectTick) {
            do {
                list = try await FreelanceTalentApi.show(uuid).data
                error = nil
            } catch {
                if Task.isCancelled { return }
                self.error = flError(error)
            }
        }
        .dorrDialog(isPresented: $renaming, bottom: true) {
            if let l = list {
                TalentFormSheet(title: L("fs_tp_rename"), initialName: l.name ?? "", initialSpecialty: l.specialty, onDismiss: { renaming = false }) { name, specialty in
                    list = try await FreelanceTalentApi.update(uuid, name: name, specialtyId: specialty).data ?? list
                }
            }
        }
        .dorrDialog(isPresented: $deleting) {
            if let l = list {
                ConfirmDialog(icon: "trash", title: L("fs_tp_delete"), message: L("fs_tp_delete_confirm", l.name ?? ""), loading: busy,
                              confirmLabel: L("fs_tp_delete"), onConfirm: { deleteList() }, onDismiss: { deleting = false })
            }
        }
        .dorrDialog(isPresented: Binding(get: { removeFor != nil }, set: { if !$0 && !busy { removeFor = nil } })) {
            if let m = removeFor {
                ConfirmDialog(icon: "xmark", title: L("fs_tp_remove_member"), message: L("fs_tp_remove_confirm", m.name ?? "—"), loading: busy,
                              confirmLabel: L("fl_remove"), onConfirm: { removeMember(m) }, onDismiss: { removeFor = nil })
            }
        }
        .dorrDialog(isPresented: Binding(get: { noteFor != nil }, set: { if !$0 { noteFor = nil } }), bottom: true) {
            if let m = noteFor {
                TalentNoteSheet(member: m, onDismiss: { noteFor = nil }) { text in
                    list = try await FreelanceTalentApi.updateMember(uuid, freelancer: m.uuid, body: ["note": .string(text)]).data ?? list
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { remindFor != nil }, set: { if !$0 { remindFor = nil } }), bottom: true) {
            if let m = remindFor {
                TalentRemindSheet(member: m, options: list?.remindOptions ?? [30, 60, 90, 180], onDismiss: { remindFor = nil }) { days in
                    list = try await FreelanceTalentApi.updateMember(uuid, freelancer: m.uuid, body: ["remind_in_days": days.map(JSONValue.int) ?? .null]).data ?? list
                }
            }
        }
        .dorrDialog(isPresented: $inviting, bottom: true) {
            if let l = list { TalentInviteSheet(list: l, onDismiss: { inviting = false }, onDone: { result = $0 }) }
        }
    }

    @ViewBuilder
    private func content(_ l: TalentListDetailDto) -> some View {
        let members = l.members ?? []
        VStack(alignment: .leading, spacing: 12) {
            Text(verbatim: [l.specialty?.name, L("fs_tp_count", l.count ?? 0)].compactMap { $0 }.joined(separator: "  ·  "))
                .font(.dorr(13)).foregroundStyle(wa.settingsMut)
            FlHint(text: L("fs_tp_private"))
            if let failure { FlNotice(text: failure, icon: "exclamationmark.circle", color: flRed) }
            if let r = result { TalentInviteResult(result: r, members: members) }
            if members.isEmpty {
                WaEmpty(icon: "person.badge.plus", tone: .pink, title: L("fs_tp_members"), text: L("fs_tp_members_empty"))
            }
            ForEach(members) { m in memberCard(m) }
            FlAction(text: L("fs_tp_invite"), icon: "paperplane.fill", tone: .primary, enabled: !members.isEmpty) { result = nil; inviting = true }
            FlAction(text: L("fs_tp_rename"), icon: "pencil") { renaming = true }
            FlAction(text: L("fs_tp_delete"), icon: "trash", tone: .danger) { deleting = true }
            Spacer(minLength: 20)
        }
    }

    private func memberCard(_ m: TalentMemberDto) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                TalentAvatar(name: m.name, url: m.avatarUrl)
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 4) {
                        Text(verbatim: m.name ?? "—").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        if m.verified == true {
                            Image(systemName: "checkmark.seal.fill").font(.system(size: 14)).foregroundStyle(flGreen).accessibilityLabel(L("fl_verified"))
                        }
                    }
                    if let h = m.headline, !h.isEmpty { Text(verbatim: h).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                    Text(verbatim: L("fl_completeness", m.completeness ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Button { removeFor = m } label: {
                    Image(systemName: "xmark").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 36, height: 36)
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("fs_tp_remove_member"))
            }
            if m.workedTogether == true {
                HStack(spacing: 4) {
                    Image(systemName: "hands.clap.fill").font(.system(size: 11))
                    Text(L("fs_tp_worked_together")).font(.dorr(11, .bold))
                }
                .foregroundStyle(flGreen)
                .padding(.horizontal, 10).padding(.vertical, 3)
                .background(flGreen.opacity(0.12), in: Capsule())
            }
            // The note (mine alone) and the reminder (mine alone): a tap changes either.
            let note = (m.note ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
            Button { noteFor = m } label: {
                HStack(alignment: .top, spacing: 8) {
                    Image(systemName: "note.text").font(.system(size: 14)).foregroundStyle(wa.accent).padding(.top, 2)
                    Text(verbatim: note.isEmpty ? L("fs_tp_note_add") : note).font(.dorr(13)).foregroundStyle(note.isEmpty ? wa.settingsMut : wa.settingsInk)
                        .lineSpacing(4).multilineTextAlignment(.leading).frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(.horizontal, 12).padding(.vertical, 10)
                .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            Button { remindFor = m } label: {
                HStack(spacing: 8) {
                    Image(systemName: m.remindAt != nil ? "bell.badge.fill" : "bell").font(.system(size: 14)).foregroundStyle(wa.accent)
                    Text(verbatim: m.remindAt.map { L("fs_tp_remind_on", "\u{200E}" + String($0.prefix(10))) } ?? L("fs_tp_remind_off"))
                        .font(.dorr(13)).foregroundStyle(m.remindAt == nil ? wa.settingsMut : wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(.horizontal, 12).padding(.vertical, 10)
                .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(FlCorners.card)
    }

    private func removeMember(_ m: TalentMemberDto) {
        busy = true
        failure = nil
        Task {
            do {
                list = try await FreelanceTalentApi.removeMember(uuid, freelancer: m.uuid).data ?? list
                removeFor = nil
            } catch {
                failure = flError(error)
            }
            busy = false
        }
    }

    private func deleteList() {
        busy = true
        Task {
            do {
                _ = try await FreelanceTalentApi.delete(uuid)
                deleting = false
                onGone()
            } catch {
                failure = flError(error)
                deleting = false
            }
            busy = false
        }
    }
}

@MainActor
private struct TalentNoteSheet: View {
    let member: TalentMemberDto
    let onDismiss: () -> Void
    let submit: (String) async throws -> Void
    @State private var text: String
    @State private var busy = false
    @State private var error: String?

    init(member: TalentMemberDto, onDismiss: @escaping () -> Void, submit: @escaping (String) async throws -> Void) {
        self.member = member
        self.onDismiss = onDismiss
        self.submit = submit
        _text = State(initialValue: member.note ?? "")
    }

    var body: some View {
        FlSheet(icon: "note.text", title: L("fs_tp_note_title", member.name ?? "—"), text: L("fs_tp_note_hint")) {
            DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(200)) }), label: L("fs_tp_note"), icon: "text.alignleft", minHeight: 90)
            if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed) }
            FlAction(text: L("common_save"), icon: "checkmark", tone: .primary, loading: busy) {
                busy = true
                error = nil
                Task {
                    do {
                        try await submit(text.trimmingCharacters(in: .whitespacesAndNewlines))
                        onDismiss()
                    } catch {
                        self.error = flError(error)
                    }
                    busy = false
                }
            }
        }
    }
}

/// Off · 30 · 60 · 90 · 180 days. The reminder is for me alone; the freelancer is never told.
@MainActor
private struct TalentRemindSheet: View {
    @Environment(\.wa) private var wa
    let member: TalentMemberDto
    let options: [Int]
    let onDismiss: () -> Void
    let submit: (Int?) async throws -> Void
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        FlSheet(icon: "bell.badge.fill", title: L("fs_tp_remind_title", member.name ?? "—"), text: L("fs_tp_remind_hint")) {
            if let at = member.remindAt {
                FlNotice(text: L("fs_tp_remind_on", "\u{200E}" + String(at.prefix(10))), icon: "bell.badge.fill", color: wa.accent)
            }
            // Wraps (every choice is in view at once - a sideways scroll hid the longer ones).
            LazyVGrid(columns: [GridItem(.adaptive(minimum: 120), spacing: 8, alignment: .leading)], alignment: .leading, spacing: 8) {
                FlChip(text: L("fs_tp_remind_none"), selected: member.remindAt == nil, icon: "bell.slash.fill") { if !busy { pick(nil) } }
                ForEach(options, id: \.self) { d in
                    FlChip(text: L("fs_tp_remind_days", d), selected: false, icon: "clock") { if !busy { pick(d) } }
                }
            }
            if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed) }
        }
    }

    private func pick(_ days: Int?) {
        busy = true
        error = nil
        Task {
            do {
                try await submit(days)
                onDismiss()
            } catch {
                self.error = flError(error)
            }
            busy = false
        }
    }
}

/// «Invite to a project»: one of my private published projects, and who (everyone unless some are unticked).
@MainActor
private struct TalentInviteSheet: View {
    @Environment(\.wa) private var wa
    let list: TalentListDetailDto
    let onDismiss: () -> Void
    let onDone: (TalentInviteResultDto) -> Void
    @State private var projects: [FreelanceProjectDto]?
    @State private var loadError: String?
    @State private var project: String?
    @State private var picked: Set<String>
    @State private var busy = false
    @State private var error: String?

    init(list: TalentListDetailDto, onDismiss: @escaping () -> Void, onDone: @escaping (TalentInviteResultDto) -> Void) {
        self.list = list
        self.onDismiss = onDismiss
        self.onDone = onDone
        _picked = State(initialValue: Set((list.members ?? []).map(\.uuid)))
    }

    var body: some View {
        let members = list.members ?? []
        FlSheet(icon: "paperplane.fill", title: L("fs_tp_invite"), text: L("fs_tp_invite_hint"), footer: AnyView(footer(members))) {
            FlHeading(icon: "briefcase.fill", text: L("fs_tp_pick_project"))
            if let ps = projects {
                if ps.isEmpty {
                    FlNotice(text: L("fs_tp_no_projects"), icon: "info.circle", color: wa.accent)
                } else {
                    ForEach(ps) { p in
                        let on = project == p.uuid
                        Button { project = p.uuid } label: {
                            HStack(spacing: 10) {
                                Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 18)).foregroundStyle(on ? wa.accent : wa.settingsMut)
                                Text(verbatim: p.title ?? "—").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2).multilineTextAlignment(.leading)
                                    .frame(maxWidth: .infinity, alignment: .leading)
                            }
                            .padding(.horizontal, 12).padding(.vertical, 12)
                            .background(wa.accent.opacity(on ? 0.14 : 0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                }
            } else if let loadError {
                FlNotice(text: loadError, icon: "exclamationmark.circle", color: flRed)
            } else {
                ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 14).frame(height: 52) }
            }
            FlHeading(icon: "person.3.fill", text: L("fs_tp_pick_who"), hint: L("fs_tp_pick_who_hint"))
            ForEach(members) { m in
                let on = picked.contains(m.uuid)
                Button {
                    if on { picked.remove(m.uuid) } else { picked.insert(m.uuid) }
                } label: {
                    HStack(spacing: 10) {
                        Image(systemName: on ? "checkmark.square.fill" : "square").font(.system(size: 19)).foregroundStyle(on ? wa.accent : wa.settingsMut)
                        TalentAvatar(name: m.name, url: m.avatarUrl, size: 34)
                        Text(verbatim: m.name ?? "—").font(.dorr(14)).foregroundStyle(wa.settingsInk).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .padding(.horizontal, 4).padding(.vertical, 6)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
        }
        .task { await loadProjects() }
    }

    @ViewBuilder
    private func footer(_ members: [TalentMemberDto]) -> some View {
        if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed) }
        FlAction(text: L("fs_tp_invite_go", picked.count), icon: "paperplane.fill", tone: .primary,
                 enabled: project != nil && !picked.isEmpty, loading: busy) { send(members) }
    }

    private func loadProjects() async {
        do {
            let all = try await FreelanceApi.projects(status: "published", page: 1, perPage: 50, scope: "active").data ?? []
            projects = all.filter { $0.visibility == "private" && $0.status == "published" }
        } catch {
            if Task.isCancelled { return }
            loadError = flError(error)
        }
    }

    private func send(_ members: [TalentMemberDto]) {
        guard let target = project else { return }
        busy = true
        error = nil
        Task {
            do {
                // Everyone: leave the list out, as the server reads it.
                let who: [String]? = picked.count == members.count ? nil : members.map(\.uuid).filter { picked.contains($0) }
                let r = try await FreelanceTalentApi.invite(list.uuid, project: target, freelancers: who).data ?? TalentInviteResultDto()
                onDone(r)
                onDismiss()
            } catch {
                self.error = flError(error)
            }
            busy = false
        }
    }
}

/// What an invite did: how many were invited, and who was left out and why.
private struct TalentInviteResult: View {
    @Environment(\.wa) private var wa
    let result: TalentInviteResultDto
    let members: [TalentMemberDto]

    var body: some View {
        let skipped = result.skipped ?? []
        VStack(alignment: .leading, spacing: 6) {
            // Green only when somebody really was invited - a "0 invited" in green read like a success.
            if (result.invited ?? 0) > 0 { FlNotice(text: L("fs_tp_invited_n", result.invited ?? 0), icon: "checkmark.circle.fill", color: flGreen) }
            if !skipped.isEmpty {
                FlNotice(text: L("fs_tp_skipped_n", skipped.count), icon: "info.circle", color: flAmber)
                ForEach(Array(skipped.enumerated()), id: \.offset) { _, s in
                    let name = members.first { $0.uuid == s.uuid }?.name ?? "—"
                    Text(verbatim: "\(name) — \(reason(s.reason))").font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.horizontal, 6)
                }
            }
        }
    }

    private func reason(_ code: String?) -> String {
        switch code {
        case "already_offered": return L("fs_tp_skip_already_offered")
        case "already_invited": return L("fs_tp_skip_already_invited")
        case "declined": return L("fs_tp_skip_declined")
        default: return L("fs_tp_skip_not_found")
        }
    }
}

// MARK: - «Save to a list»

/// A small icon that opens «Save to a list» for the freelancer (a row of the freelancers I keep).
struct SaveToListButton: View {
    @Environment(\.wa) private var wa
    let freelancer: String
    @State private var open = false

    var body: some View {
        Button { open = true } label: {
            Image(systemName: "bookmark").font(.system(size: 18)).foregroundStyle(wa.accent).frame(width: 34, height: 34)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(L("fs_tp_save"))
        .dorrDialog(isPresented: $open, bottom: true) { SaveToListSheet(freelancer: freelancer) { open = false } }
    }
}

/// The same, as a pill with its words (a freelancer's profile).
struct SaveToListPill: View {
    @Environment(\.wa) private var wa
    let freelancer: String
    @State private var open = false

    var body: some View {
        Button { open = true } label: {
            HStack(spacing: 4) {
                Image(systemName: "bookmark").font(.system(size: 15))
                Text(L("fs_tp_save")).font(.dorr(12, .bold))
            }
            .foregroundStyle(wa.accent)
            .padding(.horizontal, 10).padding(.vertical, 4)
        }
        .buttonStyle(.plain)
        .dorrDialog(isPresented: $open, bottom: true) { SaveToListSheet(freelancer: freelancer) { open = false } }
    }
}

/// My lists as ticks (the ones that hold this freelancer are ticked): a tap adds or removes him; «New list» makes one and saves him in it.
@MainActor
private struct SaveToListSheet: View {
    @Environment(\.wa) private var wa
    let freelancer: String
    let onDismiss: () -> Void
    @State private var lists: [TalentListDto]?
    @State private var holding: Set<String> = []
    @State private var error: String?
    @State private var failure: String?
    @State private var busyList: String?
    @State private var creating = false
    @State private var version = 0

    var body: some View {
        Group {
            if creating {
                TalentFormSheet(title: L("fs_tp_new"), initialName: "", initialSpecialty: nil, onDismiss: { creating = false }) { name, specialty in
                    let made = try await FreelanceTalentApi.create(name: name, specialtyId: specialty).data
                    // The list exists even if saving him in it fails: the sheet then reads the lists again.
                    if let made { _ = try? await FreelanceTalentApi.addMember(made.uuid, freelancer: freelancer) }
                    version += 1
                }
            } else {
                FlSheet(icon: "bookmark.fill", title: L("fs_tp_save_title"), text: L("fs_tp_private")) {
                    if let failure { FlNotice(text: failure, icon: "exclamationmark.circle", color: flRed) }
                    if let ls = lists {
                        if ls.isEmpty { FlHint(text: L("fs_tp_save_none")) }
                        ForEach(ls) { l in row(l) }
                        FlAction(text: L("fs_tp_new"), icon: "plus") { creating = true }
                    } else if let error {
                        FlNotice(text: error, icon: "exclamationmark.circle", color: flRed)
                    } else {
                        ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 14).frame(height: 52) }
                    }
                }
            }
        }
        .task(id: version) { await load() }
    }

    private func row(_ l: TalentListDto) -> some View {
        let on = holding.contains(l.uuid)
        return Button { toggle(l, on: on) } label: {
            HStack(spacing: 10) {
                Image(systemName: on ? "checkmark.square.fill" : "square").font(.system(size: 19)).foregroundStyle(on ? wa.accent : wa.settingsMut)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: l.name ?? "—").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Text(verbatim: [l.specialty?.name, L("fs_tp_count", l.count ?? 0)].compactMap { $0 }.joined(separator: "  ·  "))
                        .font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if busyList == l.uuid { ProgressView().tint(wa.accent).scaleEffect(0.8) }
            }
            .padding(.horizontal, 12).padding(.vertical, 12)
            .background(wa.accent.opacity(on ? 0.14 : 0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(busyList != nil)
    }

    private func load() async {
        do {
            let all = try await FreelanceTalentApi.lists().data?.lists ?? []
            let mine = try await FreelanceTalentApi.containing(freelancer).data?.lists ?? []
            lists = all
            holding = Set(mine)
            error = nil
        } catch {
            if Task.isCancelled { return }
            self.error = flError(error)
        }
    }

    private func toggle(_ l: TalentListDto, on: Bool) {
        busyList = l.uuid
        failure = nil
        Task {
            do {
                if on { _ = try await FreelanceTalentApi.removeMember(l.uuid, freelancer: freelancer) } else { _ = try await FreelanceTalentApi.addMember(l.uuid, freelancer: freelancer) }
                if on { holding.remove(l.uuid) } else { holding.insert(l.uuid) }
                // The counts move too: read the lists again.
                if let fresh = try? await FreelanceTalentApi.lists().data?.lists { lists = fresh }
            } catch {
                failure = flError(error)
            }
            busyList = nil
        }
    }
}
