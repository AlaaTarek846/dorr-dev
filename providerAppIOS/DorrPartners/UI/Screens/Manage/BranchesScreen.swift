// providerApp `ui/screens/BranchesScreen.kt`
import CoreLocation
import SwiftUI

/// The establishment's branches (C-03, `branches.manage`): each with its place, the range it serves and whether it takes requests.
/// Adding or editing one sets its place on the map (the same map as the registration); the main branch can't be switched off
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

/// Adding a branch (`branch` nil) or editing one, on the same map as the registration (`BranchMapScreen`): the pin on the branch's
/// place (a new branch opens on the phone's location, an edited one on its own place), and on the card under the map its name, the
/// address written from the map (editable), the range requests reach it from, and on / off.
@MainActor
private struct BranchForm: View {
    @Environment(\.wa) private var wa
    let branch: BranchDto?
    let onBack: () -> Void
    let onSaved: () -> Void

    @State private var name: String
    @State private var address: String
    @State private var radiusKm: Double
    @State private var lat: Double?
    @State private var lng: Double?
    @State private var active: Bool
    @State private var busy = false
    @State private var error: String?
    private let start: CLLocationCoordinate2D?

    init(branch: BranchDto?, onBack: @escaping () -> Void, onSaved: @escaping () -> Void) {
        self.branch = branch
        self.onBack = onBack
        self.onSaved = onSaved
        _name = State(initialValue: branch?.name ?? "")
        _address = State(initialValue: branch?.address ?? "")
        _radiusKm = State(initialValue: branch?.radiusKm ?? defaultRadiusKm)
        _lat = State(initialValue: branch?.lat)
        _lng = State(initialValue: branch?.lng)
        _active = State(initialValue: branch?.status ?? true)
        if let lat = branch?.lat, let lng = branch?.lng {
            start = CLLocationCoordinate2D(latitude: lat, longitude: lng)
        } else {
            start = nil
        }
    }

    private var ready: Bool { !name.trimmingCharacters(in: .whitespaces).isEmpty && lat != nil && lng != nil }

    var body: some View {
        BranchMapScreen(
            title: L(branch == nil ? "br_add" : "br_edit"),
            start: start,
            onBack: onBack,
            onPlace: { at in
                lat = at.latitude
                lng = at.longitude
            },
            onAddress: { address = $0 },
            radiusKm: radiusKm
        ) {
            Text(L("br_form_body")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
            DorrTextField(value: $name.profileTake(150), label: L("br_name"), icon: "storefront.fill")
            DorrTextField(value: $address.profileTake(255), label: L("onb_map_address_label"), placeholder: L("onb_map_address_placeholder"), icon: "mappin.and.ellipse")
            Text(L("onb_map_address_note")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2).fixedSize(horizontal: false, vertical: true)
            RadiusPicker(km: radiusKm) { radiusKm = $0 }
            if branch?.isMain != true {
                HStack {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("br_takes_requests")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                        Text(L("br_takes_requests_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    RedToggle(on: active) { active.toggle() }
                }
            } else {
                Text(L("br_main_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
            }
            SettingsPrimaryButton(text: L("common_save"), enabled: ready, loading: busy, action: save)
        }
        .messageToast(error)
    }

    private func save() {
        guard let latitude = lat, let longitude = lng else { return }
        busy = true
        error = nil
        let trimmed = address.trimmingCharacters(in: .whitespacesAndNewlines)
        let body = BranchBody(
            name: name.trimmingCharacters(in: .whitespacesAndNewlines), lat: latitude, lng: longitude, radiusKm: radiusKm,
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
