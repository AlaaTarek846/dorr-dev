// providerApp `ui/screens/VehicleScreens.kt`
import SwiftUI
import UIKit

/// `DocumentType::PLATE_PHOTO`: the photo of the vehicle with its plate showing, required for every vehicle.
private let plateType = "vehicle_plate_photo"

/// The establishment's vehicles (C-03, `fleet.vehicles`): each with its plate, its type's image and its review status. No offer goes
/// out without a vehicle that is approved with its papers and its photos (owner's rule, docs/moving_business.md §6). Adding one opens
/// its page to upload them.
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
        PaperScaffold(title: L("vh_title"), onBack: onBack, bottom: AnyView(SettingsPrimaryButton(text: L("vh_add")) { adding = true })) {
            PaperNote(text: L("vh_intro"), color: Blue, icon: "info.circle.fill").paperStagger(0)
            if let shown = vehicles {
                if shown.isEmpty {
                    EmptyState(icon: "box.truck.fill", title: L("vh_empty_title"), body: L("vh_empty_body")).padding(.top, 30)
                } else {
                    ForEach(Array(shown.enumerated()), id: \.element.id) { index, vehicle in
                        VehicleRow(vehicle: vehicle) { onOpenVehicle(vehicle.uuid) }.paperStagger(index + 1)
                    }
                }
            } else if error == nil {
                SkeletonList(count: 3)
            }
        }
        .messageToast(error)
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

/// One vehicle in the list: its type's image, name and details, its status, and its plate as it looks.
private struct VehicleRow: View {
    @Environment(\.wa) private var wa
    let vehicle: VehicleDto
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            VStack(spacing: 12) {
                HStack(spacing: 12) {
                    TypeImage(image: vehicle.vehicleType?.image, size: 48)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: vehicle.vehicleType?.name ?? L("vh_vehicle")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        if let details = vehicleDetails(vehicle) {
                            Text(verbatim: details).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    StatusPill(text: vehicleStatusLabel(vehicle.status), color: vehicleStatusColor(vehicle.status))
                }
                VehiclePlate(letters: vehicle.plateLetters ?? "", numbers: vehicle.plateNumber ?? "", small: true)
            }
            .padding(14)
            .frame(maxWidth: 440)
            .settingsSurface(20)
            .contentShape(Rectangle())
        }
        .buttonStyle(PaperPressStyle())
    }
}

/// "Isuzu NPR · 2022 · White", or nil when none of them is set.
private func vehicleDetails(_ vehicle: VehicleDto) -> String? {
    let name = [vehicle.make, vehicle.model].compactMap { notBlank($0) }.joined(separator: " ")
    let parts = [notBlank(name), vehicle.year.map { String($0) }, notBlank(vehicle.color)].compactMap { $0 }
    return parts.isEmpty ? nil : parts.joined(separator: " · ")
}

/// One vehicle: its plate and review, then what it still needs — **the photos first** (a grid of slots, the plate photo leading; tap one
/// to shoot or pick and it uploads at once with its progress), then the papers — each with its state. Editing an approved vehicle's
/// type or plate sends it back to review.
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
                }, existing: data?.documents?.first { $0.documentTypeId == type.id })
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
        return PaperScaffold(title: vehicle?.vehicleType?.name ?? L("vh_vehicle"), onBack: onBack) {
            if let page = data, let vehicle {
                let editable = vehicle.status != "inactive"
                VehicleHero(vehicle: vehicle).paperStagger(0)
                VehicleNeeds(page: page, uuid: uuid, editable: editable, firstIndex: 1,
                             onOpenForm: { formTypeId = $0 }, onReload: { await reload() }, onError: { error = $0 })
                if editable {
                    HStack(spacing: 10) {
                        ActionPill(icon: "pencil", label: L("vh_edit"), color: wa.red) { editing = true }
                        ActionPill(icon: "nosign", label: L("vh_deactivate"), color: Red, enabled: !busy) { deactivating = true }
                    }
                    .frame(maxWidth: 440)
                    .paperStagger(8)
                }
            } else if error == nil {
                SkeletonList(count: 3)
            }
        }
        .messageToast(error)
        .dorrDialog(isPresented: $deactivating) {
            ConfirmDialog(icon: "nosign", title: L("vh_deactivate"), message: L("vh_deactivate_message"), loading: busy,
                          confirmLabel: L("vh_deactivate_confirm"), onConfirm: deactivate, onDismiss: { if !busy { deactivating = false } })
        }
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

/// The onboarding's vehicle step (moving needs at least one vehicle): the vehicle's data, then its required photos and papers. `onDone`
/// is called only once the vehicle is saved **and** nothing is left in its `missing` list (every required photo and paper uploaded).
/// There is no way to skip it: the first step has no back button, while the photos step goes back to the vehicle's data and an open
/// upload goes back to the photos. Opened again after the app was closed, it carries on with the vehicle already added.
@MainActor
struct FirstVehicleScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onDone: () -> Void

    @State private var vehicleUuid: String?
    /// Whether the establishment's vehicles were read once (a vehicle added before the app was closed).
    @State private var looked = false
    @State private var page: VehiclePageDto?
    @State private var editing = false
    @State private var formTypeId: Int?
    @State private var error: String?

    var body: some View {
        ZStack {
            if vehicleUuid == nil {
                if !looked {
                    PaperScaffold(title: L("pp_vh_first_title"), onBack: nil) {
                        if error == nil { SkeletonList(count: 3) }
                    }
                    .messageToast(error)
                } else {
                    // Step 1: the vehicle's data.
                    VehicleForm(vehicle: nil, onBack: nil, onSaved: { saved in
                        vehicleUuid = saved
                        Task { await reload() }
                    }, first: true)
                }
            } else if editing, let saved = page?.vehicle {
                VehicleForm(vehicle: saved, onBack: { editing = false }, onSaved: { _ in
                    Task {
                        await reload()
                        editing = false
                    }
                }, first: true)
            } else if let id = formTypeId, let type = page?.required?.first(where: { $0.id == id }), let uuid = vehicleUuid {
                DocumentForm(type: type, subject: "vehicle", subjectUuid: uuid, onBack: { formTypeId = nil }, onUploaded: {
                    Task {
                        await reload()
                        formTypeId = nil
                    }
                }, existing: page?.documents?.first { $0.documentTypeId == type.id })
            } else {
                needs
            }
        }
        .task(id: network.reconnectTick) { await reload() }
        .onReceive(Realtime.shared.changed) { _ in Task { await reload() } }
    }

    /// Step 2: its photos and papers. Back = to the vehicle's data.
    private var needs: some View {
        let complete = page != nil && (page?.missing ?? []).isEmpty
        return PaperScaffold(title: L("pp_vh_first_title"), onBack: { editing = true }, bottom: AnyView(doneBar(complete: complete))) {
            FirstVehicleSteps(current: 1).paperStagger(0)
            if let current = page, let vehicle = current.vehicle, let uuid = vehicleUuid {
                VehicleHero(vehicle: vehicle, onEdit: { editing = true }).paperStagger(1)
                if complete {
                    PaperNote(text: L("pp_vh_all_sent"), color: Green, icon: "checkmark.seal.fill")
                        .transition(.opacity.combined(with: .move(edge: .top)))
                }
                VehicleNeeds(page: current, uuid: uuid, editable: true, firstIndex: 2,
                             onOpenForm: { formTypeId = $0 }, onReload: { await reload() }, onError: { error = $0 })
            } else if error == nil {
                SkeletonList(count: 3)
            }
        }
        .animation(.easeOut(duration: 0.3), value: complete)
        .messageToast(error)
    }

    private func doneBar(complete: Bool) -> some View {
        VStack(spacing: 10) {
            if page != nil && !complete {
                Text(L("pp_vh_done_need")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            SettingsPrimaryButton(text: L("pp_vh_done"), enabled: complete, action: onDone)
        }
    }

    private func reload() async {
        if vehicleUuid == nil && !looked {
            let list = await ManageApi.vehicles()
            guard let vehicles = list.value else {
                error = list.errorText(L("login_error_offline")) ?? L("login_error_generic")
                return
            }
            looked = true
            vehicleUuid = vehicles.first { $0.status != "inactive" }?.uuid
            error = nil
        }
        guard let uuid = vehicleUuid else { return }
        let result = await ManageApi.vehicle(uuid)
        if let data = result.value {
            page = data
            error = nil
        } else {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }
}

/// The two steps of the first vehicle, the current one filled in the app colour (a tick on the one done).
private struct FirstVehicleSteps: View {
    @Environment(\.wa) private var wa
    let current: Int

    var body: some View {
        let track = wa.night ? wa.darkWell : Color(hex: 0xEDEEF1)
        HStack(spacing: 10) {
            ForEach(Array(["pp_vh_step_details", "pp_vh_step_photos"].enumerated()), id: \.offset) { index, key in
                let on = index <= current
                VStack(alignment: .leading, spacing: 8) {
                    Capsule().fill(on ? wa.red : track).frame(height: 5)
                    HStack(spacing: 6) {
                        ZStack {
                            Circle().fill(on ? wa.red : track)
                            if index < current {
                                Image(systemName: "checkmark").font(.system(size: 10, weight: .heavy)).foregroundStyle(.white)
                            } else {
                                Text(verbatim: "\(index + 1)").font(.dorr(11, .bold)).foregroundStyle(on ? .white : wa.settingsMut)
                            }
                        }
                        .frame(width: 22, height: 22)
                        Text(L(key)).font(.dorr(12, .bold)).foregroundStyle(index == current ? wa.settingsInk : wa.settingsMut)
                    }
                }
                .frame(maxWidth: .infinity)
                .animation(.easeOut(duration: 0.4), value: current)
            }
        }
        .frame(maxWidth: 440)
    }
}

/// The vehicle on top of its page: its type's image and name, its details, its status, its plate, and the review's word.
private struct VehicleHero: View {
    @Environment(\.wa) private var wa
    let vehicle: VehicleDto
    var onEdit: (() -> Void)?

    var body: some View {
        VStack(spacing: 12) {
            HStack(spacing: 12) {
                TypeImage(image: vehicle.vehicleType?.image, size: 54)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: vehicle.vehicleType?.name ?? L("vh_vehicle")).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    if let details = vehicleDetails(vehicle) {
                        Text(verbatim: details).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(2)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                StatusPill(text: vehicleStatusLabel(vehicle.status), color: vehicleStatusColor(vehicle.status))
            }
            VehiclePlate(letters: vehicle.plateLetters ?? "", numbers: vehicle.plateNumber ?? "")
            if vehicle.status == "rejected", let reason = notBlank(vehicle.statusReason) {
                PaperNote(text: L("pd_reason", reason), color: Red, icon: "exclamationmark.circle.fill")
            }
            if vehicle.status == "pending" {
                PaperNote(text: L("vh_pending_hint"), color: Blue, icon: "hourglass")
            }
            if let onEdit {
                Button(action: onEdit) {
                    HStack(spacing: 4) {
                        Image(systemName: "pencil").font(.system(size: 13, weight: .bold))
                        Text(L("vh_edit")).font(.dorr(13, .bold))
                    }
                    .foregroundStyle(wa.red)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                }
                .buttonStyle(PaperPressStyle())
                .frame(maxWidth: .infinity, alignment: .trailing)
            }
        }
        .padding(16)
        .frame(maxWidth: 440)
        .settingsSurface(20)
    }
}

/// What a vehicle needs, shared by its page and the first-vehicle step: how many are ready, the photos as a grid of slots (the plate
/// photo first; a plain photo uploads straight from the camera or the photos with its progress in the slot, one with a number, a date or
/// a back side opens the full form), then the papers as cards.
@MainActor
private struct VehicleNeeds: View {
    @Environment(\.wa) private var wa
    let page: VehiclePageDto
    let uuid: String
    let editable: Bool
    let firstIndex: Int
    let onOpenForm: (Int) -> Void
    let onReload: () async -> Void
    let onError: (String) -> Void

    @State private var uploadingId: Int?
    @State private var uploadPreview: FilePart?
    @State private var progress: Double = 0
    @State private var sheetTypeId: Int?
    @State private var pick: PaperPickRequest?

    var body: some View {
        let required = page.required ?? []
        let documents = page.documents ?? []
        let missing = page.missing ?? []
        // The plate photo leads, the rest keep the admin's order.
        let allPhotos = required.filter { $0.kind == "photo" }
        let photos = allPhotos.filter { $0.code == plateType } + allPhotos.filter { $0.code != plateType }
        let papers = required.filter { $0.kind != "photo" }
        let rows = stride(from: 0, to: photos.count, by: 2).map { Array(photos[$0..<min($0 + 2, photos.count)]) }
        // One container (not a Group, whose modifiers would go to every child): the picker and the dialog are attached once.
        VStack(spacing: 14) {
            if !required.isEmpty {
                PaperProgressCard(required: required, documents: documents, missing: missing).paperStagger(firstIndex)
            }
            if !photos.isEmpty {
                PaperSectionTitle(text: L("vh_photos")).paperStagger(firstIndex + 1)
                PaperNote(text: L("vh_plate_photo_first"), color: Amber, icon: "camera.fill").paperStagger(firstIndex + 1)
                ForEach(Array(rows.enumerated()), id: \.offset) { row, pair in
                    HStack(alignment: .top, spacing: 12) {
                        ForEach(pair) { type in
                            let mine = uploadingId == type.id
                            PhotoSlot(type: type, doc: documents.first { $0.documentTypeId == type.id }, missing: missing.contains(type.code ?? ""),
                                      uploading: mine, progress: progress, local: mine ? uploadPreview : nil, editable: editable && uploadingId == nil) {
                                if type.hasNumber == true || type.hasExpiry == true || type.needsBackSide == true {
                                    onOpenForm(type.id)
                                } else {
                                    sheetTypeId = type.id
                                }
                            }
                            .frame(maxWidth: .infinity)
                        }
                        if pair.count == 1 { Color.clear.frame(maxWidth: .infinity, maxHeight: 1) }
                    }
                    .frame(maxWidth: 440)
                    .paperStagger(firstIndex + 2 + row)
                }
            }
            if !papers.isEmpty {
                PaperSectionTitle(text: L("vh_papers")).paperStagger(firstIndex + 4)
                ForEach(Array(papers.enumerated()), id: \.element.id) { index, type in
                    PaperCard(type: type, doc: documents.first { $0.documentTypeId == type.id }, missing: missing.contains(type.code ?? ""),
                              editable: editable, busy: uploadingId != nil) { onOpenForm(type.id) }
                        .paperStagger(firstIndex + 5 + index)
                }
            }
        }
        .paperPicker($pick, allowPdf: false, onPicked: { key, file in
            if let type = required.first(where: { String($0.id) == key }) { send(type, file) }
        }, onError: onError)
        .confirmationDialog(required.first { $0.id == sheetTypeId }?.name ?? "",
                            isPresented: Binding(get: { sheetTypeId != nil }, set: { if !$0 { sheetTypeId = nil } }), titleVisibility: .visible) {
            let key = String(sheetTypeId ?? 0)
            Button(L("pd_camera")) { pick = PaperPickRequest(key: key, source: .camera, delayed: true) }
            Button(L("pp_source_gallery")) { pick = PaperPickRequest(key: key, source: .gallery, delayed: true) }
            Button(L("common_cancel"), role: .cancel) {}
        }
    }

    private func send(_ type: DocumentTypeDto, _ file: FilePart) {
        guard uploadingId == nil else { return }
        uploadingId = type.id
        uploadPreview = file
        progress = 0
        Task {
            let result = await uploadPaper(subject: "vehicle", subjectUuid: uuid, type: type, number: nil, expires: nil, front: file, back: nil) { value in
                withAnimation(.linear(duration: 0.2)) { progress = value }
            }
            if result.value != nil {
                progress = 1
                await onReload()
            } else {
                onError(result.errorText(L("login_error_offline")) ?? L("login_error_generic"))
            }
            uploadingId = nil
            uploadPreview = nil
        }
    }
}

/// One required photo as a slot: empty (dashed, "required"), the photo sent with its state badge, or the photo being sent with its
/// progress over it. Tapping it shoots or picks a new one.
private struct PhotoSlot: View {
    @Environment(\.wa) private var wa
    let type: DocumentTypeDto
    let doc: ProviderDocumentDto?
    let missing: Bool
    let uploading: Bool
    let progress: Double
    let local: FilePart?
    let editable: Bool
    let onClick: () -> Void

    var body: some View {
        let state = paperState(doc, missing: missing)
        let ringed = state == .rejected || state == .expired
        let shape = RoundedRectangle(cornerRadius: 18, style: .continuous)
        VStack(alignment: .leading, spacing: 6) {
            Button(action: onClick) {
                ZStack(alignment: .topLeading) {
                    (wa.night ? wa.darkWell : wa.red.opacity(0.05))
                    picture
                    if uploading {
                        ZStack {
                            Color.black.opacity(0.45)
                            Circle().stroke(Color.white.opacity(0.25), lineWidth: 4).frame(width: 50, height: 50)
                            Circle().trim(from: 0, to: progress).stroke(Color.white, style: StrokeStyle(lineWidth: 4, lineCap: .round))
                                .rotationEffect(.degrees(-90)).frame(width: 50, height: 50)
                            Text(verbatim: "\(Int(progress * 100))%").font(.dorr(12, .bold)).foregroundStyle(.white)
                                .environment(\.layoutDirection, .leftToRight)
                        }
                    } else if state == .missing {
                        StatusPill(text: L("pp_required"), color: Amber).padding(8)
                    } else {
                        PaperStateBadge(state: state, solid: true).padding(8)
                    }
                }
                .aspectRatio(1.1, contentMode: .fit)
                .clipShape(shape)
                .overlay(shape.stroke(ringed ? Red : .clear, lineWidth: 1.5))
                .contentShape(shape)
            }
            .buttonStyle(PaperPressStyle())
            .disabled(!editable)
            Text(verbatim: type.name ?? "").font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2)
        }
        .animation(.easeOut(duration: 0.3), value: uploading)
        .animation(.easeOut(duration: 0.3), value: doc?.front)
    }

    @ViewBuilder private var picture: some View {
        if let local, let image = UIImage(data: local.data) {
            Color.clear.overlay(Image(uiImage: image).resizable().scaledToFill()).clipped().transition(.opacity)
        } else if let front = notBlank(doc?.front) {
            Color.clear.overlay(MediaThumb(url: front)).clipped().transition(.opacity)
        } else {
            ZStack {
                DashedOutline(color: wa.red.opacity(0.45), radius: 18)
                VStack(spacing: 6) {
                    Image(systemName: "camera.badge.ellipsis").font(.system(size: 19, weight: .semibold)).foregroundStyle(wa.red)
                        .frame(width: 46, height: 46)
                        .background(wa.red.opacity(0.12), in: Circle())
                    Text(L("pp_tap_add")).font(.dorr(12, .bold)).foregroundStyle(wa.red)
                }
            }
            .transition(.opacity)
        }
    }
}

private struct ActionPill: View {
    let icon: String
    let label: String
    let color: Color
    var enabled = true
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 15, weight: .semibold))
                Text(verbatim: label).font(.dorr(14, .bold)).lineLimit(1)
            }
            .foregroundStyle(color)
            .frame(maxWidth: .infinity)
            .padding(.vertical, 13)
            .background(color.opacity(0.10), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
        .buttonStyle(PaperPressStyle())
        .disabled(!enabled)
    }
}

/// A type's image from the admin on a soft tile (a truck when it has none).
private struct TypeImage: View {
    @Environment(\.wa) private var wa
    let image: String?
    let size: CGFloat

    var body: some View {
        ZStack {
            if image != nil {
                MediaThumb(url: image, fit: true).padding(5)
            } else {
                Image(systemName: "box.truck.fill").font(.system(size: size * 0.4, weight: .semibold)).foregroundStyle(wa.red)
            }
        }
        .frame(width: size, height: size)
        .background(wa.red.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// Adding a vehicle (`vehicle` nil) or editing one, in numbered cards: 1. its type (the admin's types with their images), 2. the plate —
/// letters **and** numbers, both required, with the plate drawn live as it is typed (the captain app's plate), 3. make, model, colour and
/// year (optional), 4. the branch. Checked before sending; the server's own field errors show under their fields. `onSaved` gets the
/// vehicle's uuid. `onBack` nil = no back button (the first vehicle); `first` shows the first vehicle's steps and words.
@MainActor
private struct VehicleForm: View {
    @Environment(\.wa) private var wa
    let vehicle: VehicleDto?
    let onBack: (() -> Void)?
    let onSaved: (String) -> Void
    let first: Bool

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
    @State private var tried = false
    @State private var busy = false
    @State private var error: String?
    /// The server's field errors (`plate_letters` → its first sentence), shown under their fields until the field changes.
    @State private var server: [String: [String]] = [:]

    init(vehicle: VehicleDto?, onBack: (() -> Void)?, onSaved: @escaping (String) -> Void, first: Bool = false) {
        self.vehicle = vehicle
        self.onBack = onBack
        self.onSaved = onSaved
        self.first = first
        _typeId = State(initialValue: vehicle?.vehicleTypeId)
        _letters = State(initialValue: vehicle?.plateLetters ?? "")
        _number = State(initialValue: vehicle?.plateNumber ?? "")
        _make = State(initialValue: vehicle?.make ?? "")
        _model = State(initialValue: vehicle?.model ?? "")
        _color = State(initialValue: vehicle?.color ?? "")
        _year = State(initialValue: vehicle?.year.map { String($0) } ?? "")
        _branchId = State(initialValue: vehicle?.branchId)
    }

    private var maxYear: Int { Calendar.current.component(.year, from: Date()) + 1 }
    private var yearValue: Int? { Int(year) }
    private var yearBad: Bool { !year.isEmpty && !(yearValue.map { (1950...maxYear).contains($0) } ?? false) }
    private var lettersMissing: Bool { letters.trimmingCharacters(in: .whitespaces).isEmpty }
    private var numberMissing: Bool { number.trimmingCharacters(in: .whitespaces).isEmpty }
    private var ready: Bool { typeId != nil && !lettersMissing && !numberMissing && !yearBad }

    private func problem(_ bad: Bool, _ text: String, _ key: String) -> String? {
        server[key]?.first ?? (tried && bad ? text : nil)
    }

    var body: some View {
        let title = L(first ? "pp_vh_first_title" : (vehicle == nil ? "vh_add" : "vh_edit"))
        PaperScaffold(title: title, onBack: onBack, bottom: AnyView(bottomBar)) {
            if first {
                FirstVehicleSteps(current: 0).paperStagger(0)
                FormIntro(icon: "box.truck.fill", title: L("pp_vh_first_heading"), text: L("pp_vh_first_body")).paperStagger(1)
            } else {
                FormIntro(icon: "box.truck.fill", title: title, text: L("vh_form_body")).paperStagger(0)
            }
            if vehicle?.status == "approved" {
                PaperNote(text: L("vh_edit_review_warning"), color: Amber, icon: "exclamationmark.triangle.fill").paperStagger(1)
            }

            // 1. The type.
            FormStep(number: 1, title: L("vh_type")) {
                if let list = types {
                    if list.isEmpty {
                        Text(L("vh_no_types")).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                    } else {
                        LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 10, alignment: .top), count: 3), spacing: 10) {
                            ForEach(list) { type in
                                TypeTile(type: type, selected: type.id == typeId) {
                                    typeId = type.id
                                    server["vehicle_type_id"] = nil
                                }
                            }
                        }
                    }
                } else {
                    ProgressView().tint(wa.red).frame(width: 24, height: 24).frame(maxWidth: .infinity)
                }
                FieldError(text: problem(typeId == nil, L("pp_err_type"), "vehicle_type_id"))
            }
            .paperStagger(2)

            // 2. The plate, drawn as it is typed.
            FormStep(number: 2, title: L("pp_vh_section_plate")) {
                VehiclePlate(letters: letters, numbers: number).frame(maxWidth: .infinity)
                Text(L("pp_vh_plate_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
                    .frame(maxWidth: .infinity)
                HStack(alignment: .top, spacing: 10) {
                    let lettersProblem = problem(lettersMissing, L("pp_err_letters"), "plate_letters")
                    let numberProblem = problem(numberMissing, L("pp_err_number"), "plate_number")
                    VStack(alignment: .leading, spacing: 4) {
                        DorrTextField(value: $letters.profileMap { value in
                            server["plate_letters"] = nil
                            return String(value.filter { $0.isLetter || $0 == " " }.uppercased().prefix(8))
                        }, label: L("vh_plate_letters"), placeholder: "ABC", icon: "textformat", error: lettersProblem != nil)
                        FieldError(text: lettersProblem)
                    }
                    VStack(alignment: .leading, spacing: 4) {
                        DorrTextField(value: $number.profileMap { value in
                            server["plate_number"] = nil
                            return String(latinDigits(value).filter { $0.isASCII && $0.isNumber }.prefix(8))
                        }, label: L("vh_plate_number"), placeholder: "1234", icon: "number", keyboard: .numberPad, error: numberProblem != nil)
                        FieldError(text: numberProblem)
                    }
                }
            }
            .paperStagger(3)

            // 3. The details (optional).
            FormStep(number: 3, title: L("pp_vh_section_details")) {
                DorrTextField(value: $make.profileTake(60), label: L("vh_make"), placeholder: L("onb_optional"), icon: "car.fill")
                DorrTextField(value: $model.profileTake(60), label: L("vh_model"), placeholder: L("onb_optional"), icon: "square.grid.2x2.fill")
                HStack(alignment: .top, spacing: 10) {
                    DorrTextField(value: $color.profileTake(40), label: L("vh_color"), placeholder: L("onb_optional"), icon: "paintpalette.fill")
                    DorrTextField(value: $year.profileMap { value in
                        server["year"] = nil
                        return String(latinDigits(value).filter { $0.isASCII && $0.isNumber }.prefix(4))
                    }, label: L("vh_year"), placeholder: L("onb_optional"), icon: "calendar", keyboard: .numberPad,
                                  error: problem(yearBad, L("pp_err_year", maxYear), "year") != nil)
                }
                FieldError(text: problem(yearBad, L("pp_err_year", maxYear), "year"))
            }
            .paperStagger(4)

            // 4. The branch, when there is more than one.
            if branches.count > 1 {
                FormStep(number: 4, title: L("vh_branch")) {
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
                .paperStagger(5)
            }
        }
        .messageToast(error)
        .task {
            if let data = await WorkspaceApi.options(countryId: Session.shared.me?.countryId).value { types = data.vehicleTypes ?? [] }
            if let data = await ManageApi.branches().value { branches = data }
        }
    }

    private var bottomBar: some View {
        VStack(spacing: 10) {
            if tried && !ready {
                PaperNote(text: L("pp_complete_first"), color: Amber, icon: "exclamationmark.triangle.fill")
                    .transition(.opacity.combined(with: .move(edge: .bottom)))
            }
            SettingsPrimaryButton(text: L(vehicle == nil ? "pp_vh_save_next" : "common_save"), loading: busy, action: save)
        }
        .animation(.easeOut(duration: 0.25), value: tried && !ready)
    }

    private func save() {
        tried = true
        guard ready, !busy, let type = typeId else { return }
        busy = true
        error = nil
        server = [:]
        let body = VehicleBody(
            vehicleTypeId: type, branchId: branchId, plateLetters: letters.trimmingCharacters(in: .whitespacesAndNewlines),
            plateNumber: number.trimmingCharacters(in: .whitespacesAndNewlines),
            make: trimmedOrNil(make), model: trimmedOrNil(model), color: trimmedOrNil(color), year: yearValue
        )
        Task {
            let result: ApiResult<VehiclePageDto>
            if let vehicle { result = await ManageApi.updateVehicle(vehicle.uuid, body) } else { result = await ManageApi.addVehicle(body) }
            busy = false
            if let page = result.value {
                onSaved(page.vehicle?.uuid ?? vehicle?.uuid ?? "")
            } else {
                server = result.error?.fieldErrors ?? [:]
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

/// Arabic-Indic and Persian digits as Latin ones (the server reads Latin digits).
private func latinDigits(_ text: String) -> String {
    String(text.map { c -> Character in
        guard let scalar = c.unicodeScalars.first, c.unicodeScalars.count == 1 else { return c }
        switch scalar.value {
        case 0x0660...0x0669: return Character(String(scalar.value - 0x0660))
        case 0x06F0...0x06F9: return Character(String(scalar.value - 0x06F0))
        default: return c
        }
    })
}

/// A numbered card of the vehicle form: the step's number on an app-colour disc, its title, then its fields.
private struct FormStep<Content: View>: View {
    @Environment(\.wa) private var wa
    let number: Int
    let title: String
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 10) {
                Text(verbatim: "\(number)").font(.dorr(13, .heavy)).foregroundStyle(.white)
                    .frame(width: 26, height: 26)
                    .background(wa.red, in: Circle())
                Text(verbatim: title).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
            }
            content()
        }
        .padding(16)
        .frame(maxWidth: 440, alignment: .leading)
        .settingsSurface(20)
    }
}

/// The line under a field that says what is wrong, sliding in and out.
private struct FieldError: View {
    let text: String?

    var body: some View {
        Group {
            if let text {
                Text(verbatim: text).font(.dorr(12, .semibold)).foregroundStyle(Red).padding(.horizontal, 6)
                    .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
        .animation(.easeOut(duration: 0.2), value: text)
    }
}

/// A number plate (the captain app's `PlatePreview`): the numbers and the letters on white, as on the vehicle — grey placeholders until
/// typed.
struct VehiclePlate: View {
    let letters: String
    let numbers: String
    var small = false

    var body: some View {
        let ink = Color(hex: 0x111928)
        let shape = RoundedRectangle(cornerRadius: small ? 8 : 12)
        HStack(spacing: 0) {
            PlateHalf(value: numbers, placeholder: "1234", small: small).frame(maxWidth: .infinity)
            Rectangle().fill(ink).frame(width: 2)
            PlateHalf(value: letters, placeholder: "ABC", small: small).frame(maxWidth: .infinity)
        }
        .padding(4)
        .frame(maxWidth: small ? 190 : 290)
        .frame(height: small ? 44 : 66)
        .background(shape.fill(Color.white))
        .clipShape(shape)
        .overlay(shape.strokeBorder(ink, lineWidth: 2))
        .environment(\.layoutDirection, .leftToRight)
    }
}

private struct PlateHalf: View {
    let value: String
    let placeholder: String
    let small: Bool

    var body: some View {
        let trimmed = value.trimmingCharacters(in: .whitespaces)
        let shown = (trimmed.isEmpty ? placeholder : trimmed).map { String($0) }.joined(separator: " ")
        Text(verbatim: shown)
            .font(.dorr(small ? 15 : 22, .heavy))
            .kerning(1)
            .foregroundStyle(trimmed.isEmpty ? Color(hex: 0xB8BEC8) : Color(hex: 0x111928))
            .lineLimit(1)
            .minimumScaleFactor(0.6)
            .frame(maxHeight: .infinity)
    }
}

/// One vehicle type with its image from the admin, a tick and a small spring when chosen (the onboarding's service tile).
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
                        .transition(.scale.combined(with: .opacity))
                }
            }
            .scaleEffect(selected ? 1.04 : 1)
            .animation(.spring(response: 0.35, dampingFraction: 0.6), value: selected)
            .contentShape(shape)
        }
        .buttonStyle(PaperPressStyle())
    }
}
