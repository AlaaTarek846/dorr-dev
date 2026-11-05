// providerApp `ui/screens/VehicleScreens.kt`
import SwiftUI

/// The establishment's vehicles (C-03, `fleet.vehicles`): each with its review status. No offer goes out without a vehicle that is
/// approved with its papers and its photos (owner's rule, docs/moving_business.md §6). Adding one opens its page to upload them.
@MainActor
struct VehiclesScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpenVehicle: (String) -> Void

    @State private var vehicles: [VehicleDto]?
    @State private var error: String?
    @State private var adding = false

    var body: some View {
        ZStack {
            if adding {
                VehicleForm(vehicle: nil, onBack: { adding = false }, onSaved: { uuid in
                    adding = false
                    Task { await reload() }
                    onOpenVehicle(uuid)
                })
            } else {
                list
            }
        }
        .task(id: network.reconnectTick) { await reload() }
        // A vehicle reviewed by Dorr arrives as a notice: one reload.
        .onReceive(Realtime.shared.changed) { _ in Task { await reload() } }
    }

    private var list: some View {
        Page(title: L("vh_title"), onBack: onBack) {
            Text(L("vh_intro")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4).frame(maxWidth: .infinity, alignment: .leading)
            if let shown = vehicles {
                if shown.isEmpty {
                    EmptyState(icon: "box.truck.fill", title: L("vh_empty_title"), body: L("vh_empty_body")).padding(.top, 30)
                } else {
                    ForEach(shown) { vehicle in
                        RowCard(
                            icon: "box.truck.fill",
                            title: vehicle.vehicleType?.name ?? L("vh_vehicle"),
                            subtitle: vehicleLine(vehicle),
                            pill: AnyView(StatusPill(text: vehicleStatusLabel(vehicle.status), color: vehicleStatusColor(vehicle.status))),
                            onClick: { onOpenVehicle(vehicle.uuid) }
                        )
                    }
                }
            } else if error == nil {
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
            }
            SettingsPrimaryButton(text: L("vh_add")) { adding = true }
        }
        .messageToast(error)
    }

    private func vehicleLine(_ vehicle: VehicleDto) -> String {
        let name = [vehicle.make, vehicle.model].compactMap { $0 }.joined(separator: " ")
        return ["\u{200E}" + vehicle.plate(), notBlank(name)].compactMap { $0 }.joined(separator: " · ")
    }

    private func reload() async {
        let result = await ManageApi.vehicles()
        if let data = result.value {
            vehicles = data
            error = nil
        } else {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }
}

/// One vehicle: its data, its review, and what it still needs — **the photos first** (the one showing the plate matters most), then
/// the papers — each uploaded on its own. Editing an approved vehicle's type or plate sends it back to review.
@MainActor
struct VehicleScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let uuid: String
    let onBack: () -> Void

    @State private var data: VehiclePageDto?
    @State private var error: String?
    @State private var formTypeId: Int?
    @State private var editing = false
    @State private var deactivating = false
    @State private var busy = false

    var body: some View {
        ZStack {
            if let id = formTypeId, let type = data?.required?.first(where: { $0.id == id }) {
                DocumentForm(type: type, subject: "vehicle", subjectUuid: uuid, onBack: { formTypeId = nil }, onUploaded: {
                    Task {
                        await reload()
                        formTypeId = nil
                    }
                })
            } else if editing, let edited = data?.vehicle {
                VehicleForm(vehicle: edited, onBack: { editing = false }, onSaved: { _ in
                    Task {
                        await reload()
                        editing = false
                    }
                })
            } else {
                content
            }
        }
        .task(id: network.reconnectTick) { await reload() }
        .onReceive(Realtime.shared.changed) { _ in Task { await reload() } }
    }

    private var content: some View {
        let vehicle = data?.vehicle
        return Page(title: vehicle?.vehicleType?.name ?? L("vh_vehicle"), onBack: onBack) {
            if let page = data, let vehicle {
                Card {
                    HStack {
                        Text(verbatim: "\u{200E}" + vehicle.plate()).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                        StatusPill(text: vehicleStatusLabel(vehicle.status), color: vehicleStatusColor(vehicle.status))
                    }
                    InfoLine(L("vh_make"), vehicle.make)
                    InfoLine(L("vh_model"), vehicle.model)
                    InfoLine(L("vh_color"), vehicle.color)
                    InfoLine(L("vh_year"), vehicle.year.map { String($0) })
                    if vehicle.status == "rejected", let reason = notBlank(vehicle.statusReason) {
                        Text(L("pd_reason", reason)).font(.dorr(13)).foregroundStyle(Red)
                    }
                    if vehicle.status == "pending" { Text(L("vh_pending_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3) }
                }
                needs(page, editable: vehicle.status != "inactive")
                if vehicle.status != "inactive" {
                    SettingsPrimaryButton(text: L("vh_edit")) { editing = true }
                    Button { deactivating = true } label: {
                        HStack(spacing: 6) {
                            Image(systemName: "nosign").font(.system(size: 15, weight: .semibold)).foregroundStyle(Red)
                            Text(L("vh_deactivate")).font(.dorr(14, .bold)).foregroundStyle(Red)
                        }
                    }
                    .buttonStyle(.plain)
                    .disabled(busy)
                }
            } else if error == nil {
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
            }
        }
        .messageToast(error)
        .dorrDialog(isPresented: $deactivating) {
            ConfirmDialog(icon: "nosign", title: L("vh_deactivate"), message: L("vh_deactivate_message"), loading: busy,
                          confirmLabel: L("vh_deactivate_confirm"), onConfirm: deactivate, onDismiss: { if !busy { deactivating = false } })
        }
    }

    // The photos first: the vehicle with its plate showing, then its corners; then the papers.
    @ViewBuilder private func needs(_ page: VehiclePageDto, editable: Bool) -> some View {
        let required = page.required ?? []
        let photos = required.filter { $0.kind == "photo" }
        let papers = required.filter { $0.kind != "photo" }
        let missing = page.missing ?? []
        if !photos.isEmpty {
            FieldLabel(text: L("vh_photos")).frame(maxWidth: .infinity, alignment: .leading)
            HStack(alignment: .top, spacing: 8) {
                Image(systemName: "camera.fill").font(.system(size: 16, weight: .semibold)).foregroundStyle(Amber).frame(width: 20)
                Text(L("vh_plate_photo_first")).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(12)
            .background(Amber.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            ForEach(photos) { type in requiredRow(type, page, missing, editable) }
        }
        if !papers.isEmpty {
            FieldLabel(text: L("vh_papers")).frame(maxWidth: .infinity, alignment: .leading)
            ForEach(papers) { type in requiredRow(type, page, missing, editable) }
        }
        if !missing.isEmpty {
            Text(L("pd_missing_count", missing.count)).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
        }
    }

    private func requiredRow(_ type: DocumentTypeDto, _ page: VehiclePageDto, _ missing: [String], _ editable: Bool) -> some View {
        RequiredRow(type: type, doc: page.documents?.first { $0.documentTypeId == type.id }, missing: missing.contains(type.code ?? ""),
                    editable: editable, busy: busy) { formTypeId = type.id }
    }

    private func reload() async {
        let result = await ManageApi.vehicle(uuid)
        if let page = result.value {
            data = page
            error = nil
        } else {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    private func deactivate() {
        busy = true
        Task {
            let result = await ManageApi.deactivateVehicle(uuid)
            busy = false
            deactivating = false
            if let page = result.value { data = page } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}

/// Adding a vehicle (`vehicle` nil) or editing one: its type (the admin's types with their images), the plate (letters + number),
/// make, model, colour, year and branch. `onSaved` gets the vehicle's uuid.
@MainActor
private struct VehicleForm: View {
    @Environment(\.wa) private var wa
    let vehicle: VehicleDto?
    let onBack: () -> Void
    let onSaved: (String) -> Void

    @State private var types: [VehicleTypeDto]?
    @State private var branches: [BranchDto] = []
    @State private var typeId: Int?
    @State private var letters: String
    @State private var number: String
    @State private var make: String
    @State private var model: String
    @State private var color: String
    @State private var year: String
    @State private var branchId: Int?
    @State private var busy = false
    @State private var error: String?

    init(vehicle: VehicleDto?, onBack: @escaping () -> Void, onSaved: @escaping (String) -> Void) {
        self.vehicle = vehicle
        self.onBack = onBack
        self.onSaved = onSaved
        _typeId = State(initialValue: vehicle?.vehicleTypeId)
        _letters = State(initialValue: vehicle?.plateLetters ?? "")
        _number = State(initialValue: vehicle?.plateNumber ?? "")
        _make = State(initialValue: vehicle?.make ?? "")
        _model = State(initialValue: vehicle?.model ?? "")
        _color = State(initialValue: vehicle?.color ?? "")
        _year = State(initialValue: vehicle?.year.map { String($0) } ?? "")
        _branchId = State(initialValue: vehicle?.branchId)
    }

    private var yearValue: Int? { Int(year) }
    private var yearOk: Bool { year.trimmingCharacters(in: .whitespaces).isEmpty || (yearValue.map { (1950...2100).contains($0) } ?? false) }
    private var ready: Bool { typeId != nil && !number.trimmingCharacters(in: .whitespaces).isEmpty && yearOk }

    var body: some View {
        let title = L(vehicle == nil ? "vh_add" : "vh_edit")
        Page(title: title, onBack: onBack) {
            FormIntro(icon: "box.truck.fill", title: title, text: L("vh_form_body"))
            if vehicle?.status == "approved" {
                HStack(alignment: .top, spacing: 8) {
                    Image(systemName: "exclamationmark.triangle.fill").font(.system(size: 16, weight: .semibold)).foregroundStyle(Amber).frame(width: 20)
                    Text(L("vh_edit_review_warning")).font(.dorr(12)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(12)
                .background(Amber.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }

            FieldLabel(text: L("vh_type")).frame(maxWidth: .infinity, alignment: .leading)
            if let list = types {
                if list.isEmpty {
                    Text(L("vh_no_types")).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                } else {
                    LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 10, alignment: .top), count: 3), spacing: 10) {
                        ForEach(list) { type in
                            TypeTile(type: type, selected: type.id == typeId) { typeId = type.id }
                        }
                    }
                }
            } else {
                ProgressView().tint(wa.red).frame(width: 24, height: 24)
            }

            FormGroup {
                HStack(alignment: .top, spacing: 10) {
                    DorrTextField(value: $letters.profileTake(32), label: L("vh_plate_letters"), placeholder: L("onb_optional"))
                    DorrTextField(value: $number.profileTake(20), label: L("vh_plate_number"), icon: "person.text.rectangle", keyboard: .asciiCapable)
                }
                DorrTextField(value: $make.profileTake(60), label: L("vh_make"), icon: "car.fill")
                DorrTextField(value: $model.profileTake(60), label: L("vh_model"), icon: "square.grid.2x2.fill")
                HStack(alignment: .top, spacing: 10) {
                    DorrTextField(value: $color.profileTake(40), label: L("vh_color"), icon: "paintpalette.fill")
                    DorrTextField(value: $year.profileDigits(4), label: L("vh_year"), icon: "calendar", keyboard: .numberPad, error: !yearOk)
                }
            }
            if branches.count > 1 {
                FieldLabel(text: L("vh_branch")).frame(maxWidth: .infinity, alignment: .leading)
                ChipRow(
                    options: branches.map { (value: String($0.id ?? 0), label: $0.name ?? "") },
                    selected: branchId.map { [String($0)] } ?? [],
                    onToggle: { value in
                        let id = Int(value)
                        branchId = id == branchId ? nil : id
                    }
                )
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            SettingsPrimaryButton(text: L(vehicle == nil ? "vh_add_next" : "common_save"), enabled: ready, loading: busy, action: save)
        }
        .messageToast(error)
        .task {
            if let data = await WorkspaceApi.options(countryId: Session.shared.me?.countryId).value { types = data.vehicleTypes ?? [] }
            if let data = await ManageApi.branches().value { branches = data }
        }
    }

    private func save() {
        guard let type = typeId else { return }
        busy = true
        error = nil
        let body = VehicleBody(
            vehicleTypeId: type, branchId: branchId, plateLetters: trimmedOrNil(letters), plateNumber: number.trimmingCharacters(in: .whitespacesAndNewlines),
            make: trimmedOrNil(make), model: trimmedOrNil(model), color: trimmedOrNil(color), year: yearValue
        )
        Task {
            let result: ApiResult<VehiclePageDto>
            if let vehicle { result = await ManageApi.updateVehicle(vehicle.uuid, body) } else { result = await ManageApi.addVehicle(body) }
            busy = false
            if let page = result.value {
                onSaved(page.vehicle?.uuid ?? vehicle?.uuid ?? "")
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}

/// `it.trim().ifEmpty { null }`.
func trimmedOrNil(_ text: String) -> String? {
    let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
    return trimmed.isEmpty ? nil : trimmed
}

/// One vehicle type with its image from the admin, a tick when chosen (the onboarding's service tile).
private struct TypeTile: View {
    @Environment(\.wa) private var wa
    let type: VehicleTypeDto
    let selected: Bool
    let onClick: () -> Void

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 18, style: .continuous)
        Button(action: onClick) {
            VStack(spacing: 8) {
                GeometryReader { proxy in
                    let side = proxy.size.width * 0.72
                    ZStack {
                        if type.image != nil {
                            MediaThumb(url: type.image, fit: true)
                        } else {
                            Image(systemName: "box.truck.fill").font(.system(size: 24, weight: .semibold)).foregroundStyle(wa.red)
                        }
                    }
                    .frame(width: side, height: side)
                    .frame(maxWidth: .infinity)
                }
                .aspectRatio(1 / 0.72, contentMode: .fit)
                Text(verbatim: type.name ?? "").font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk)
                    .multilineTextAlignment(.center).lineLimit(2)
            }
            .padding(.horizontal, 8)
            .padding(.vertical, 12)
            .frame(maxWidth: .infinity)
            .background(selected ? wa.red.opacity(wa.night ? 0.22 : 0.10) : wa.settingsCard, in: shape)
            .overlay(shape.stroke(selected ? wa.red : (wa.night ? wa.line : Color(hex: 0xE5E7EB)), lineWidth: selected ? 2 : 1))
            .overlay(alignment: .topTrailing) {
                if selected {
                    Image(systemName: "checkmark").font(.system(size: 10, weight: .heavy)).foregroundStyle(.white)
                        .frame(width: 20, height: 20)
                        .background(wa.red, in: Circle())
                        .padding(6)
                }
            }
            .contentShape(shape)
        }
        .buttonStyle(.plain)
    }
}
