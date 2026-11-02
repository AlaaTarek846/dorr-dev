import SwiftUI

// freelanceApp `ui/screens/SpecialtiesScreen.kt`.

private let maxSpecialties = 5

/**
 * The freelancer's specialties: the active tree to pick from (up to 5), one starred as the main one. A specialty the admin
 * stopped after it was picked stays in the list, marked, until it is unchecked. Loaded once when the page opens (and when
 * the connection comes back); the save is one request.
 */
struct SpecialtiesScreen: View {
    let onBack: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor

    @State private var tree: [SpecialtyNodeDto] = []
    @State private var stopped: [MySpecialtyDto] = []
    @State private var selected: [Int] = []
    @State private var primary: Int?
    @State private var loaded = false
    @State private var error: String?
    @State private var saving = false

    var body: some View {
        Page(title: L("specialties_title"), onBack: onBack) {
            Text(L("specialties_hint", maxSpecialties)).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                .frame(maxWidth: .infinity, alignment: .leading)
            if loaded {
                ForEach(tree, id: \.id) { root in
                    Card {
                        Text(verbatim: root.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        ForEach([root] + (root.children ?? []), id: \.id) { node in
                            let id = node.id ?? 0
                            SpecialtyRow(label: node.name ?? "", checked: selected.contains(id), isPrimary: id == primary,
                                         indented: node.id != root.id, onToggle: { toggle(id) }, onStar: { primary = id })
                        }
                    }
                }
                if !stopped.isEmpty {
                    Card {
                        ForEach(stopped, id: \.id) { s in
                            let id = s.id ?? 0
                            SpecialtyRow(label: (s.name ?? "") + " — " + L("specialties_inactive"), checked: selected.contains(id), isPrimary: id == primary,
                                         indented: false, onToggle: { toggle(id) }, onStar: { primary = id })
                        }
                    }
                }
                SettingsPrimaryButton(text: L("profile_save"), enabled: !selected.isEmpty && primary != nil, loading: saving, action: save)
            }
        }
        .messageToast(error)
        .task(id: network.reconnectTick) { await load() }
    }

    private func load() async {
        let catalog = await ListsApi.specialtyTree()
        let mine = await ListsApi.mySpecialties()
        if let nodes = catalog.value, let picked = mine.value {
            tree = nodes
            let activeIds = Set(nodes.flatMap { [$0.id] + ($0.children ?? []).map(\.id) }.compactMap { $0 })
            stopped = picked.filter { !activeIds.contains($0.id ?? 0) }
            selected = picked.compactMap(\.id)
            primary = picked.first { $0.isPrimary == true }?.id ?? picked.first?.id
            loaded = true
            error = nil
        } else {
            error = catalog.errorText(L("login_error_offline")) ?? mine.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    private func toggle(_ id: Int) {
        error = nil
        if selected.contains(id) {
            selected.removeAll { $0 == id }
            if primary == id { primary = selected.first }
        } else if selected.count >= maxSpecialties {
            error = L("specialties_limit", maxSpecialties)
        } else {
            selected.append(id)
            if primary == nil { primary = id }
        }
    }

    private func save() {
        guard let main = primary else { return }
        saving = true
        error = nil
        Task {
            let result = await ListsApi.setSpecialties(SetSpecialtiesRequest(specialtyIds: selected, primaryId: main))
            saving = false
            if result.value != nil { onBack() } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}

private struct SpecialtyRow: View {
    @Environment(\.wa) private var wa
    let label: String
    let checked: Bool
    let isPrimary: Bool
    let indented: Bool
    let onToggle: () -> Void
    let onStar: () -> Void

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: checked ? "checkmark.square.fill" : "square").font(.system(size: 20))
                .foregroundStyle(checked ? wa.red : wa.settingsMut)
                .frame(width: 22, height: 22)
            Text(verbatim: label).font(.dorr(14)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
            if checked {
                Button(action: onStar) {
                    HStack(spacing: 4) {
                        Image(systemName: isPrimary ? "star.fill" : "star").font(.system(size: 17)).foregroundStyle(wa.red)
                        if isPrimary { Text(L("specialties_primary")).font(.dorr(12, .bold)).foregroundStyle(wa.red) }
                    }
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.leading, indented ? 16 : 0)
        .padding(.vertical, 4)
        .contentShape(Rectangle())
        .onTapGesture(perform: onToggle)
    }
}
