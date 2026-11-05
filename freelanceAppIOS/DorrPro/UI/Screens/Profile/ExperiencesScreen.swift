import SwiftUI

// freelanceApp `ui/screens/ExperiencesScreen.kt`.

private let maxExperiences = 20

/** The freelancer's work history: the list (current jobs first), and a form to add, edit or delete one. Up to 20. */
struct ExperiencesScreen: View {
    let onBack: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor

    @State private var items: [ExperienceDto] = []
    @State private var loaded = false
    @State private var error: String?
    /// nil = the list; 0 = a new one; else the id being edited.
    @State private var formId: Int?

    private var editing: ExperienceDto? {
        guard let id = formId else { return nil }
        return id == 0 ? ExperienceDto(id: 0) : items.first { $0.id == id }
    }

    var body: some View {
        Group {
            if let editing {
                ExperienceForm(initial: editing, onBack: { formId = nil }, onChanged: changed)
                    .id(formId)
            } else {
                list
            }
        }
        .task(id: network.reconnectTick) {
            let result = await ListsApi.experiences()
            if let list = result.value {
                items = list
                loaded = true
                error = nil
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    private var list: some View {
        Page(title: L("experiences_title"), onBack: onBack) {
            if loaded {
                if items.count < maxExperiences { SettingsPrimaryButton(text: L("experiences_add")) { formId = 0 } }
                if items.isEmpty { Text(L("common_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut) }
                ForEach(items, id: \.id) { e in
                    Card {
                        VStack(alignment: .leading, spacing: 4) {
                            Text(verbatim: e.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                            if let company = e.company, !company.trimmingCharacters(in: .whitespaces).isEmpty {
                                Text(verbatim: company).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                            }
                            Text(verbatim: "\(e.startedOn ?? "") → " + (e.isCurrent == true ? L("experience_now") : (e.endedOn ?? "")))
                                .font(.dorr(12, .semibold)).foregroundStyle(wa.red)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .contentShape(Rectangle())
                        .onTapGesture { formId = e.id }
                    }
                }
            }
        }
        .messageToast(error)
    }

    private func changed(_ saved: ExperienceDto?, _ removedId: Int?) {
        if let removedId { items.removeAll { $0.id == removedId } }
        if let saved {
            if let at = items.firstIndex(where: { $0.id == saved.id }) { items[at] = saved } else { items.insert(saved, at: 0) }
        }
        formId = nil
    }
}

private struct ExperienceForm: View {
    let initial: ExperienceDto
    let onBack: () -> Void
    let onChanged: (ExperienceDto?, Int?) -> Void

    @Environment(\.wa) private var wa
    @State private var title: String
    @State private var company: String
    @State private var start: String
    @State private var end: String
    @State private var current: Bool
    @State private var description: String
    @State private var busy = false
    @State private var error: String?
    @State private var confirmDelete = false

    init(initial: ExperienceDto, onBack: @escaping () -> Void, onChanged: @escaping (ExperienceDto?, Int?) -> Void) {
        self.initial = initial
        self.onBack = onBack
        self.onChanged = onChanged
        _title = State(initialValue: initial.title ?? "")
        _company = State(initialValue: initial.company ?? "")
        _start = State(initialValue: initial.startedOn ?? "")
        _end = State(initialValue: initial.endedOn ?? "")
        _current = State(initialValue: initial.isCurrent ?? false)
        _description = State(initialValue: initial.description ?? "")
    }

    private var isNew: Bool { (initial.id ?? 0) == 0 }

    var body: some View {
        Page(title: L(isNew ? "experiences_add" : "common_edit"), onBack: onBack) {
            FormIntro(icon: "briefcase.fill", title: L("experience_intro_title"), text: L("experience_intro"))
            FormGroup {
                DorrTextField(value: $title, label: L("experience_title"), icon: "briefcase.fill")
                DorrTextField(value: $company, label: L("experience_company"), icon: "building.2.fill")
                DateField(value: $start, label: L("experience_start"))
                HStack(spacing: 10) {
                    RedToggle(on: current) { current.toggle() }
                    Text(L("experience_current")).font(.dorr(14)).foregroundStyle(wa.settingsInk)
                }
                if !current { DateField(value: $end, label: L("experience_end")) }
                DorrTextField(value: $description.profileTake(1000), label: L("experience_description"), icon: "text.alignleft", singleLine: false, minLines: 3)
            }
            SettingsPrimaryButton(text: L("profile_save"), enabled: title.trimmingCharacters(in: .whitespacesAndNewlines).count >= 2 && !start.isEmpty, loading: busy, action: save)
            if !isNew {
                ProfileTextLink(text: L("common_delete")) { confirmDelete = true }.padding(8)
            }
        }
        .messageToast(error)
        .dorrDialog(isPresented: $confirmDelete) {
            AlertDialog(
                onDismissRequest: { confirmDelete = false },
                text: { Text(L("common_delete_confirm")) },
                confirmButton: { DialogButton(onClick: { confirmDelete = false; delete() }) { Text(L("common_delete")) } },
                dismissButton: { DialogButton(onClick: { confirmDelete = false }) { Text(L("common_cancel")) } }
            )
        }
    }

    private func save() {
        busy = true
        error = nil
        var body: JsonBody = [
            "title": title.trimmingCharacters(in: .whitespacesAndNewlines),
            "company": company.trimmingCharacters(in: .whitespacesAndNewlines),
            "started_on": start,
            "is_current": current,
            "description": description.trimmingCharacters(in: .whitespacesAndNewlines),
        ]
        // A current job has no end; the others send theirs (or none).
        if !current && !end.isEmpty { body["ended_on"] = end }
        let sent = body
        Task {
            let result = isNew ? await ListsApi.addExperience(sent) : await ListsApi.updateExperience(initial.id ?? 0, sent)
            busy = false
            if let saved = result.value { onChanged(saved, nil) } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }

    private func delete() {
        busy = true
        Task {
            let failed = await ListsApi.deleteExperience(initial.id ?? 0, offline: L("login_error_offline"), generic: L("login_error_generic"))
            busy = false
            if let failed { error = failed } else { onChanged(nil, initial.id) }
        }
    }
}
