import SwiftUI

// freelanceApp `ui/screens/SkillsScreen.kt`.

private let maxSkills = 30

/**
 * The freelancer's skills: their own list with a level each, and a search over the catalog to add more. A skill that is not in
 * the catalog can be suggested — it joins the list as "under review" until the admin decides. The catalog is asked when the
 * page opens and when the search text settles (400 ms after the last key), never on a timer; the save is one request with the
 * whole list.
 */
struct SkillsScreen: View {
    let onBack: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor

    @State private var mine: [MySkillDto] = []
    @State private var results: [CatalogSkillDto] = []
    @State private var query = ""
    @State private var loaded = false
    @State private var error: String?
    @State private var info: String?
    @State private var saving = false
    @State private var suggesting = false

    private var levels: [(value: String, label: String)] {
        [
            (value: "beginner", label: L("skills_level_beginner")),
            (value: "intermediate", label: L("skills_level_intermediate")),
            (value: "advanced", label: L("skills_level_advanced")),
            (value: "expert", label: L("skills_level_expert")),
        ]
    }

    var body: some View {
        Page(title: L("skills_title"), onBack: onBack) {
            if loaded {
                Card {
                    Text(verbatim: L("skills_mine") + " (\(mine.count)/\(maxSkills))").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                    if mine.isEmpty { Text(L("common_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut) }
                    ForEach(Array(mine.enumerated()), id: \.offset) { index, skill in
                        VStack(alignment: .leading, spacing: 8) {
                            HStack(spacing: 8) {
                                Text(verbatim: skill.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                if skill.pending == true { Text(L("skills_pending")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                                Button { mine.remove(at: index) } label: {
                                    Image(systemName: "xmark").font(.system(size: 15, weight: .bold)).foregroundStyle(wa.settingsMut).frame(width: 20, height: 20)
                                }
                                .buttonStyle(.plain)
                                .accessibilityLabel(L("common_remove"))
                            }
                            ChipRow(
                                options: levels,
                                selected: Set([skill.level].compactMap { $0 }),
                                onToggle: { level in
                                    guard index < mine.count else { return }
                                    mine[index].level = skill.level == level ? nil : level
                                }
                            )
                        }
                    }
                }

                Card {
                    DorrTextField(value: $query.profileTake(80), placeholder: L("skills_search"), icon: "magnifyingglass")
                    let clean = query.trimmingCharacters(in: .whitespacesAndNewlines)
                    let exact = results.contains { ($0.name ?? "").lowercased() == clean.lowercased() }
                    ForEach(results.filter { r in !mine.contains { $0.id == r.id } }, id: \.id) { skill in
                        Button { add(skill) } label: {
                            HStack(spacing: 8) {
                                Image(systemName: "plus").font(.system(size: 16, weight: .bold)).foregroundStyle(wa.red).frame(width: 20, height: 20)
                                Text(verbatim: skill.name ?? "").font(.dorr(14)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                                if skill.pending == true { Text(L("skills_pending")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                            }
                            .padding(.vertical, 4)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                    if clean.count >= 2 && !exact {
                        SettingsPrimaryButton(text: L("skills_suggest", clean), loading: suggesting, action: suggest)
                    }
                }
                SettingsPrimaryButton(text: L("profile_save"), loading: saving, action: save)
            }
            if let info { Text(verbatim: info).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
        }
        .messageToast(error)
        .task(id: network.reconnectTick) {
            let result = await ListsApi.mySkills()
            if let list = result.value {
                mine = list
                loaded = true
                error = nil
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
        // The search: the first page when the page opens, then whenever the text settles.
        .task(id: "\(query)|\(network.reconnectTick)") {
            if !query.isEmpty {
                try? await Task.sleep(nanoseconds: 400_000_000)
                if Task.isCancelled { return }
            }
            let clean = query.trimmingCharacters(in: .whitespacesAndNewlines)
            let result = await ListsApi.skillCatalog(clean.isEmpty ? nil : clean)
            if Task.isCancelled { return }
            if let list = result.value { results = list }
        }
    }

    private func add(_ skill: CatalogSkillDto) {
        info = nil
        if mine.contains(where: { $0.id == skill.id }) { return }
        if mine.count >= maxSkills {
            error = L("skills_limit", maxSkills)
            return
        }
        error = nil
        mine.append(MySkillDto(id: skill.id, name: skill.name, level: nil, pending: skill.pending ?? false))
    }

    private func suggest() {
        suggesting = true
        error = nil
        info = nil
        Task {
            let result = await ListsApi.suggestSkill(SuggestSkillRequest(name: query.trimmingCharacters(in: .whitespacesAndNewlines), locale: AppLocale.current))
            suggesting = false
            if let skill = result.value {
                add(skill)
                if skill.created == true { info = L("skills_suggested") }
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    private func save() {
        saving = true
        error = nil
        Task {
            let result = await ListsApi.setSkills(SetSkillsRequest(skills: mine.map { SkillRow(skillId: $0.id ?? 0, level: $0.level) }))
            saving = false
            if result.value != nil { onBack() } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}
