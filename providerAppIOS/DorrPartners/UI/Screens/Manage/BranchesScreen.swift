// providerApp `ui/screens/BranchesScreen.kt`
import SwiftUI

/// The establishment's branches (C-03, `branches.manage`): each with its place, the range it serves and whether it takes requests.
/// Adding or editing one sets its place from the phone's current location (a map comes later); the main branch can't be switched off
/// or deleted. Read once on open and when the connection returns.
@MainActor
struct BranchesScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void

    @State private var branches: [BranchDto]?
    @State private var error: String?
    /// nil = the list; "new" = a new branch; else the uuid being edited.
    @State private var editing: String?
    @State private var deleting: BranchDto?
    @State private var busy = false

    var body: some View {
        ZStack {
            if let editing {
                BranchForm(branch: branches?.first { $0.uuid == editing }, onBack: { self.editing = nil }, onSaved: {
                    Task {
                        await reload()
                        self.editing = nil
                    }
                })
            } else {
                list
            }
        }
        .task(id: network.reconnectTick) { await reload() }
    }

    private var list: some View {
        Page(title: L("br_title"), onBack: onBack) {
            if let shown = branches {
                if shown.isEmpty {
                    EmptyState(icon: "storefront.fill", title: L("br_empty_title"), body: L("br_empty_body")).padding(.top, 30)
                } else {
                    ForEach(Array(shown.enumerated()), id: \.offset) { _, branch in row(branch) }
                }
            } else if error == nil {
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
            }
            SettingsPrimaryButton(text: L("br_add")) { editing = "new" }
        }
        .messageToast(error)
        .dorrDialog(isPresented: Binding(get: { deleting != nil }, set: { if !$0 && !busy { deleting = nil } })) {
            if let branch = deleting {
                ConfirmDialog(icon: "trash.fill", title: L("br_delete_title"), message: L("br_delete_message", branch.name ?? ""),
                              loading: busy, confirmLabel: L("common_delete"),
                              onConfirm: { delete(branch) }, onDismiss: { if !busy { deleting = nil } })
            }
        }
    }

    private func row(_ branch: BranchDto) -> some View {
        let active = branch.status != false
        return Card {
            HStack {
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: branch.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                    if let address = notBlank(branch.address) {
                        Text(verbatim: address).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                    if let radius = branch.radiusKm {
                        Text(L("br_radius_line", formatKm(radius))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if branch.isMain == true {
                    StatusPill(text: L("br_main"), color: wa.red)
                } else {
                    StatusPill(text: L(active ? "br_active" : "br_inactive"), color: active ? Green : Slate)
                }
            }
            HStack(spacing: 20) {
                ProfileTextLink(text: L("common_edit"), enabled: !busy) { editing = branch.uuid }
                if branch.isMain != true {
                    Button { deleting = branch } label: {
                        Text(L("common_delete")).font(.dorr(14, .bold)).foregroundStyle(Red)
                    }
                    .buttonStyle(.plain)
                    .disabled(busy)
                }
            }
        }
    }

    private func reload() async {
        let result = await ManageApi.branches()
        if let data = result.value {
            branches = data
            error = nil
        } else {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    private func delete(_ branch: BranchDto) {
        busy = true
        Task {
            let failed = await ManageApi.deleteBranch(branch.uuid ?? "", offline: L("login_error_offline"), generic: L("login_error_generic"))
            busy = false
            deleting = nil
            if let failed { error = failed } else { await reload() }
        }
    }
}

/// "12.5" without a trailing ".0".
func formatKm(_ km: Double) -> String {
    km.truncatingRemainder(dividingBy: 1) == 0 ? String(Int(km)) : String(km)
}

/// Adding a branch (`branch` nil) or editing one: name, place (current location, or the one it has), address, range, on / off.
@MainActor
private struct BranchForm: View {
    @Environment(\.wa) private var wa
    let branch: BranchDto?
    let onBack: () -> Void
    let onSaved: () -> Void

    @State private var name: String
    @State private var address: String
    @State private var radius: String
    @State private var lat: Double?
    @State private var lng: Double?
    @State private var active: Bool
    @State private var locating = false
    @State private var busy = false
    @State private var error: String?

    init(branch: BranchDto?, onBack: @escaping () -> Void, onSaved: @escaping () -> Void) {
        self.branch = branch
        self.onBack = onBack
        self.onSaved = onSaved
        _name = State(initialValue: branch?.name ?? "")
        _address = State(initialValue: branch?.address ?? "")
        _radius = State(initialValue: branch?.radiusKm.map(formatKm) ?? "")
        _lat = State(initialValue: branch?.lat)
        _lng = State(initialValue: branch?.lng)
        _active = State(initialValue: branch?.status ?? true)
    }

    private var radiusValue: Double? { Double(radius.replacingOccurrences(of: ",", with: ".")) }
    private var radiusOk: Bool {
        radius.trimmingCharacters(in: .whitespaces).isEmpty || (radiusValue.map { (0.5...500.0).contains($0) } ?? false)
    }
    private var ready: Bool { !name.trimmingCharacters(in: .whitespaces).isEmpty && lat != nil && lng != nil && radiusOk }

    var body: some View {
        let title = L(branch == nil ? "br_add" : "br_edit")
        Page(title: title, onBack: onBack) {
            FormIntro(icon: "storefront.fill", title: title, text: L("br_form_body"))
            FormGroup {
                DorrTextField(value: $name.profileTake(150), label: L("br_name"), icon: "storefront.fill")
            }
            Card {
                HStack(spacing: 10) {
                    Image(systemName: "location.fill").font(.system(size: 18, weight: .semibold)).foregroundStyle(wa.red).frame(width: 22)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L(lat != nil ? "onb_location_set" : "onb_location_none")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                        if let lat, let lng {
                            Text(verbatim: coordinatesText(lat, lng)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        }
                    }
                    Spacer(minLength: 0)
                    if locating { ProgressView().tint(wa.red).frame(width: 20, height: 20) }
                }
                ProfileTextLink(text: L(lat != nil ? "onb_location_again" : "onb_location_use"), enabled: !locating && !busy, action: locate)
                Text(L("onb_map_later")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            }
            FormGroup {
                DorrTextField(value: $address.profileTake(255), label: L("onb_address"), placeholder: L("onb_optional"), icon: "mappin.and.ellipse")
                DorrTextField(value: $radius.profileMap(decimalInput(5)), label: L("onb_radius"), placeholder: L("onb_radius_hint"),
                              icon: "safari", keyboard: .decimalPad, error: !radiusOk)
                if !radiusOk { Text(L("onb_radius_invalid")).font(.dorr(12)).foregroundStyle(Red) }
            }
            if branch?.isMain != true {
                Card {
                    HStack {
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("br_takes_requests")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                            Text(L("br_takes_requests_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        RedToggle(on: active) { active.toggle() }
                    }
                }
            } else {
                Text(L("br_main_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
            }
            SettingsPrimaryButton(text: L("common_save"), enabled: ready, loading: busy, action: save)
        }
        .messageToast(error)
    }

    private func locate() {
        locating = true
        error = nil
        Task {
            let here = await CurrentPosition.shared.fetch()
            locating = false
            if let here {
                lat = here.latitude
                lng = here.longitude
            } else {
                error = L("onb_location_failed")
            }
        }
    }

    private func save() {
        guard let latitude = lat, let longitude = lng else { return }
        busy = true
        error = nil
        let trimmed = address.trimmingCharacters(in: .whitespacesAndNewlines)
        let body = BranchBody(
            name: name.trimmingCharacters(in: .whitespacesAndNewlines), lat: latitude, lng: longitude, radiusKm: radiusValue,
            address: trimmed.isEmpty ? nil : trimmed,
            // The main branch always takes requests.
            status: branch?.isMain == true ? true : active
        )
        Task {
            let failure: String?
            if let branch {
                let result = await ManageApi.updateBranch(branch.uuid ?? "", body)
                failure = result.value != nil ? nil : result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            } else {
                let result = await ManageApi.addBranch(body)
                failure = result.value != nil ? nil : result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
            busy = false
            if let failure { error = failure } else { onSaved() }
        }
    }
}

/// "‎24.71360, 46.67530": coordinates keep their left-to-right order inside Arabic text.
func coordinatesText(_ lat: Double, _ lng: Double) -> String {
    "\u{200E}" + String(format: "%.5f, %.5f", locale: Locale(identifier: "en_US_POSIX"), lat, lng)
}

/// What an amount field accepts (`it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(count)`).
func decimalInput(_ count: Int) -> (String) -> String {
    { String($0.filter { $0.isASCII && ($0.isNumber || $0 == "." || $0 == ",") }.prefix(count)) }
}
