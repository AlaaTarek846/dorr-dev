import CoreLocation
import PhotosUI
import SwiftUI
import UniformTypeIdentifiers

// androidApp `moving/MovingWizard.kt` — a new request (REQ-081), one draft kept on the server so a lost connection loses
// nothing: the first "next" creates it (`POST requests`) and every step after it saves it (`PUT requests/{uuid}`). Steps:
// load type · description, pieces and media · vehicle, workers and extras · pickup, drop-off and time · review and
// publish. Nothing is required before the review: the server says what is missing (`data.missing`), and that is shown
// beside the step it belongs to.

private let stepType = 0
private let stepItems = 1
private let stepVehicle = 2
private let stepPlace = 3
private let stepReview = 4
private let stepCount = 5

private let imageOrVideo: Set<String> = ["image/jpeg", "image/png", "image/webp", "video/mp4", "video/quicktime"]

/// Which step a field the server says is missing belongs to.
private func stepOfMissing(_ key: String) -> Int {
    switch key {
    case "load_type": return stepType
    case "description_or_photo": return stepItems
    case "vehicle_type_id": return stepVehicle
    default: return stepPlace
    }
}

/// Digits only (any script typed, kept as plain 0-9 so the number reads).
private func plainDigits(_ text: String) -> String {
    String(text.compactMap { c -> Character? in
        guard c.isNumber, let v = c.wholeNumberValue, (0...9).contains(v) else { return nil }
        return Character(String(v))
    })
}

private func megabytes(_ mb: Double) -> String {
    mb.truncatingRemainder(dividingBy: 1) == 0 ? String(Int(mb)) : String(format: "%.1f", mb)
}

/// A text binding cut to `limit` characters (and passed through `filter`).
private func limited(_ get: @escaping () -> String, _ set: @escaping (String) -> Void, _ limit: Int, filter: ((String) -> String)? = nil) -> Binding<String> {
    Binding(get: get, set: { set(String((filter?($0) ?? $0).prefix(limit))) })
}

/// One end of the move being filled in.
struct MovingEnd: Equatable {
    var point: RidePoint?
    var address = ""
    var area = ""
    var floor = ""
    var elevator = false
    var entrance = ""

    mutating func fill(_ dto: MovingEndDto?) {
        guard let d = dto else { return }
        if let lat = d.lat, let lng = d.lng { point = RidePoint(lat: lat, lng: lng, address: d.address ?? "") }
        address = d.address ?? ""
        area = d.area ?? ""
        floor = d.floor.map(String.init) ?? ""
        elevator = d.elevator == true
        entrance = d.entrance ?? ""
    }

    /// An empty text clears the field on the server (Laravel reads it as null).
    func into(_ body: inout [String: JSONValue], _ end: String) {
        if let p = point {
            body["\(end)_lat"] = .double(p.lat)
            body["\(end)_lng"] = .double(p.lng)
        }
        body["\(end)_address"] = .string(String(address.trimmingCharacters(in: .whitespacesAndNewlines).prefix(255)))
        body["\(end)_area"] = .string(String(area.trimmingCharacters(in: .whitespacesAndNewlines).prefix(120)))
        if let level = Int(floor.trimmingCharacters(in: .whitespaces)) { body["\(end)_floor"] = .int(level) } else { body["\(end)_floor"] = .string("") }
        body["\(end)_elevator"] = .bool(elevator)
        body["\(end)_entrance"] = .string(String(entrance.trimmingCharacters(in: .whitespacesAndNewlines).prefix(255)))
    }

    /// What the review and the cards show for the place.
    var shown: String? { point.map { address.trimmingCharacters(in: .whitespaces).isEmpty ? $0.address : address } }
}

/// The draft on this phone, until each step saves it.
@MainActor
final class MovingDraft: ObservableObject {
    @Published var uuid: String?
    @Published var loadType: String?
    @Published var description = ""
    @Published var pieces = ""
    @Published var dimensions = ""
    @Published var vehicleTypeId: Int?
    @Published var workers = 0
    @Published var packing = false
    @Published var disassembly = false
    @Published var crane = false
    @Published var technician = false
    @Published var pickup = MovingEnd()
    @Published var dropoff = MovingEnd()
    @Published var scheduledAt: Date?
    @Published var notes = ""
    @Published var photos: [MovingMediaDto] = []
    @Published var videos: [MovingMediaDto] = []
    @Published var missing: [String] = []

    func fill(_ r: MovingRequestDto) {
        uuid = r.uuid
        loadType = r.loadType
        description = r.description ?? ""
        pieces = r.piecesCount.map(String.init) ?? ""
        dimensions = r.dimensions ?? ""
        vehicleTypeId = r.vehicleTypeId
        workers = r.workersCount ?? 0
        packing = r.extras?.packing == true
        disassembly = r.extras?.disassembly == true
        crane = r.extras?.crane == true
        technician = r.extras?.technician == true
        pickup.fill(r.pickup)
        dropoff.fill(r.dropoff)
        scheduledAt = mvTime(r.scheduledAt)
        notes = r.notes ?? ""
        photos = r.photos
        videos = r.videos
    }

    func body() -> [String: JSONValue] {
        var b: [String: JSONValue] = [:]
        if let loadType { b["load_type"] = .string(loadType) }
        b["description"] = .string(String(description.trimmingCharacters(in: .whitespacesAndNewlines).prefix(3000)))
        if let count = Int(pieces.trimmingCharacters(in: .whitespaces)) { b["pieces_count"] = .int(count) } else { b["pieces_count"] = .string("") }
        b["dimensions"] = .string(String(dimensions.trimmingCharacters(in: .whitespacesAndNewlines).prefix(255)))
        if let vehicleTypeId { b["vehicle_type_id"] = .int(vehicleTypeId) }
        b["workers_count"] = .int(workers)
        b["packing"] = .bool(packing)
        b["disassembly"] = .bool(disassembly)
        b["crane"] = .bool(crane)
        b["technician"] = .bool(technician)
        b["notes"] = .string(String(notes.trimmingCharacters(in: .whitespacesAndNewlines).prefix(2000)))
        pickup.into(&b, "pickup")
        dropoff.into(&b, "dropoff")
        // A time that has passed since is not sent (the server refuses a past one); the review asks for a new one.
        if let at = scheduledAt, at > Date() {
            let f = ISO8601DateFormatter()
            f.timeZone = .current
            b["scheduled_at"] = .string(f.string(from: at))
        }
        return b
    }

    func clearMissing(_ key: String) { missing.removeAll { $0 == key } }
}

@MainActor
struct MovingWizard: View {
    @Environment(\.wa) private var wa
    let initialUuid: String?
    @ObservedObject var nav: MovingNav
    let atReview: Bool
    @StateObject private var draft = MovingDraft()
    @State private var step: Int
    @State private var forward = true
    @State private var loaded: Bool
    @State private var options: MovingOptionsDto?
    @State private var busy = false
    @State private var error: String?
    /// "pickup" | "dropoff" while its picker is open over the wizard.
    @State private var picker: String?
    @State private var timeOpen = false

    init(initialUuid: String?, nav: MovingNav, atReview: Bool = false) {
        self.initialUuid = initialUuid
        _nav = ObservedObject(wrappedValue: nav)
        self.atReview = atReview
        _step = State(initialValue: atReview ? stepReview : stepType)
        _loaded = State(initialValue: initialUuid == nil)
    }

    var body: some View {
        let missingSteps = Set(draft.missing.map(stepOfMissing))
        ZStack {
            MvPage(title: L("mv_new_title"), onBack: { if step > stepType { go(step - 1) } else { nav.pop() } }, bottom: AnyView(bottomBar)) {
                if !loaded {
                    MvLoading()
                } else {
                    VStack(spacing: 0) {
                        StepBar(step: step, missing: missingSteps) { target in if target <= step { go(target) } }
                        ZStack {
                            stepView(step)
                                .id(step)
                                .transition(.asymmetric(insertion: .move(edge: forward ? .trailing : .leading).combined(with: .opacity), removal: .opacity))
                        }
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                        .clipped()
                    }
                }
            }
            // One end of the move: over the wizard. Picking it saves the draft, so a lost connection keeps it too.
            if let which = picker {
                MovingPlacePicker(
                    title: L(which == "pickup" ? "mv_pickup" : "mv_dropoff"),
                    start: which == "pickup" ? draft.pickup.point : draft.dropoff.point,
                    near: (draft.pickup.point ?? draft.dropoff.point)?.latLng,
                    onClose: { picker = nil },
                    onPick: { place in picked(place, which) }
                )
                .transition(.move(edge: .bottom).combined(with: .opacity))
                .zIndex(2)
            }
        }
        .animation(.easeInOut(duration: 0.3), value: picker)
        .sheet(isPresented: $timeOpen) {
            MovingTimeSheet(initial: draft.scheduledAt, title: L("mv_when"), onClose: { timeOpen = false }) { at in
                draft.scheduledAt = at
                draft.clearMissing("scheduled_at")
                timeOpen = false
                Task { _ = await save() }
            }
            .dorrThemed().presentationDetents([.large])
        }
        .task {
            options = try? await MovingApi.options().data
            if let initialUuid {
                do {
                    if let show = try await MovingApi.show(initialUuid).data {
                        draft.fill(show.request)
                        // Published again: said at once what it still needs (a time that has passed was cleared).
                        draft.missing = atReview ? show.missing : []
                    }
                } catch {
                    self.error = error.apiFailure.message ?? L("rd_error")
                }
                loaded = true
            }
        }
    }

    private var bottomBar: some View {
        VStack(spacing: 8) {
            MvError(text: error).frame(maxWidth: .infinity, alignment: .leading)
            if step == stepReview {
                AccentButton(text: L("mv_publish"), busy: busy, icon: "checkmark.circle.fill") { publish() }
            } else {
                AccentButton(text: L("mv_next"), enabled: step != stepType || draft.loadType != nil, busy: busy) { next() }
            }
        }
    }

    @ViewBuilder
    private func stepView(_ current: Int) -> some View {
        switch current {
        case stepType: TypeStep(draft: draft)
        case stepItems: ItemsStep(draft: draft, options: options)
        case stepVehicle: VehicleStep(draft: draft, options: options)
        case stepPlace: PlaceStep(draft: draft, onPick: { picker = $0 }, onTime: { timeOpen = true })
        default: ReviewStep(draft: draft, options: options) { go($0) }
        }
    }

    private func go(_ to: Int) {
        forward = to > step
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.3)) { step = to }
    }

    private func picked(_ place: RidePoint, _ which: String) {
        if which == "pickup" {
            draft.pickup.point = place
            draft.pickup.address = place.address
        } else {
            draft.dropoff.point = place
            draft.dropoff.address = place.address
        }
        picker = nil
        Task {
            if let found = await areaOf(place.latLng), !found.isEmpty {
                if which == "pickup" { draft.pickup.area = found } else { draft.dropoff.area = found }
            }
            draft.clearMissing(which)
            _ = await save()
        }
    }

    /// Saves what is on the phone; false when it couldn't (and says why, the server's words first).
    private func save() async -> Bool {
        let body = draft.body()
        do {
            let fresh: MovingRequestDto?
            if let uuid = draft.uuid {
                fresh = try await MovingApi.update(uuid, body).data
            } else {
                fresh = try await MovingApi.create(body).data
            }
            if let fresh { draft.uuid = fresh.uuid }
            error = nil
            return true
        } catch {
            let refusal = error.movingRefusal
            if refusal.code == "moving_service_off" {
                nav.serviceOff = true
                nav.pop()
            } else {
                self.error = refusal.message ?? L("mv_save_failed")
            }
            return false
        }
    }

    private func next() {
        guard !busy else { return }
        busy = true
        Task {
            if await save(), step < stepReview { go(step + 1) }
            busy = false
        }
    }

    private func publish() {
        guard !busy else { return }
        busy = true
        error = nil
        Task {
            if await save(), let uuid = draft.uuid {
                do {
                    _ = try await MovingApi.publish(uuid)
                    nav.replace(.request(uuid))
                } catch {
                    let refusal = error.movingRefusal
                    draft.missing = refusal.missing
                    self.error = refusal.message ?? L("rd_error")
                }
            }
            busy = false
        }
    }
}

/// The five steps as a bar; a step the server says is missing something turns red.
private struct StepBar: View {
    @Environment(\.wa) private var wa
    let step: Int
    let missing: Set<Int>
    let onTap: (Int) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 6) {
                ForEach(0..<stepCount, id: \.self) { i in
                    let color = missing.contains(i) ? AppColors.danger : (i <= step ? wa.accent : wa.settingsInk.opacity(0.12))
                    Capsule().fill(color).frame(height: 5).frame(maxWidth: .infinity)
                        .contentShape(Rectangle().inset(by: -8))
                        .onTapGesture { onTap(i) }
                        .animation(.easeInOut(duration: 0.24), value: color)
                }
            }
            Text(verbatim: L("mv_step_of", step + 1, stepCount, stepName(step))).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
        }
        .padding(.horizontal, 16)
        .padding(.bottom, 10)
    }

    private func stepName(_ s: Int) -> String {
        switch s {
        case stepType: return L("mv_step_type")
        case stepItems: return L("mv_step_items")
        case stepVehicle: return L("mv_step_vehicle")
        case stepPlace: return L("mv_step_place")
        default: return L("mv_step_review")
        }
    }
}

/// What the server says this step still needs.
private struct MissingNote: View {
    @ObservedObject var draft: MovingDraft
    let key: String

    var body: some View {
        if draft.missing.contains(key) {
            Text(verbatim: missingText(key)).font(.dorr(12.5, .semibold)).foregroundStyle(AppColors.danger)
        }
    }

    private func missingText(_ key: String) -> String {
        switch key {
        case "load_type": return L("mv_missing_load_type")
        case "description_or_photo": return L("mv_missing_description")
        case "pickup": return L("mv_missing_pickup")
        case "dropoff": return L("mv_missing_dropoff")
        case "scheduled_at": return L("mv_missing_time")
        default: return L("mv_missing_vehicle")
        }
    }
}

// MARK: - 1. The load

private struct TypeStep: View {
    @Environment(\.wa) private var wa
    @ObservedObject var draft: MovingDraft

    var body: some View {
        MvScroll(pull: false) {
            Text(L("mv_type_title")).font(.dorr(22, .black)).foregroundStyle(wa.settingsInk)
            MissingNote(draft: draft, key: "load_type")
            TypeTile(icon: "sofa.fill", title: L("mv_load_single"), hint: L("mv_load_single_hint"), on: draft.loadType == "single_piece") { pick("single_piece") }
            TypeTile(icon: "house.fill", title: L("mv_load_home"), hint: L("mv_load_home_hint"), on: draft.loadType == "home") { pick("home") }
            TypeTile(icon: "building.2.fill", title: L("mv_load_commercial"), hint: L("mv_load_commercial_hint"), on: draft.loadType == "commercial") { pick("commercial") }
        }
    }

    private func pick(_ type: String) {
        draft.loadType = type
        draft.clearMissing("load_type")
    }
}

/// A choice as a card: a soft wash and a border when it is the chosen one, with a small pop (the rides speed tiles).
private struct TypeTile: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let icon: String
    let title: String
    let hint: String
    let on: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 24)).foregroundStyle(wa.accent)
                    .frame(width: 52, height: 52).background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: title).font(.dorr(16, .bold)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: hint).font(.dorr(12.5)).foregroundStyle(wa.settingsMut)
                }
                Spacer(minLength: 0)
                if on { Image(systemName: "checkmark.circle.fill").font(.system(size: 20)).foregroundStyle(wa.accent).transition(.scale.combined(with: .opacity)) }
            }
            .padding(16)
            .background(on ? wa.accent.opacity(0.12) : wa.mvCard(onPanel), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(on ? wa.accent : .clear, lineWidth: 1.5))
            .scaleEffect(on ? 1.02 : 1)
        }
        .buttonStyle(.plain)
        .animation(.spring(response: 0.3, dampingFraction: 0.5), value: on)
    }
}

// MARK: - 2. What is moved, with photos

private struct ItemsStep: View {
    @Environment(\.wa) private var wa
    @ObservedObject var draft: MovingDraft
    let options: MovingOptionsDto?
    @State private var uploading = 0
    @State private var warning: String?
    @State private var pickerOpen = false
    @State private var items: [PhotosPickerItem] = []

    var body: some View {
        let maxMb = options?.mediaMaxMb
        MvScroll(pull: false) {
            MvCard {
                MvCardTitle(text: L("mv_items_title"))
                DorrTextArea(value: limited({ draft.description }, { draft.description = $0; draft.clearMissing("description_or_photo") }, 3000),
                             label: L("mv_description"), placeholder: L("mv_description_hint"), minHeight: 96)
                    .padding(.top, 10)
                MissingNote(draft: draft, key: "description_or_photo")
                HStack(alignment: .top, spacing: 10) {
                    DorrTextArea(value: limited({ draft.pieces }, { draft.pieces = $0 }, 5, filter: plainDigits), label: L("mv_pieces"), singleLine: true)
                        .keyboardType(.numberPad)
                        .frame(maxWidth: .infinity)
                        .layoutPriority(0.4)
                    DorrTextArea(value: limited({ draft.dimensions }, { draft.dimensions = $0 }, 255), label: L("mv_dimensions"),
                                 placeholder: L("mv_dimensions_hint"), singleLine: true)
                        .frame(maxWidth: .infinity)
                        .layoutPriority(0.6)
                }
                .padding(.top, 10)
            }
            MvCard {
                MvCardTitle(text: L("mv_media_title"))
                Text(verbatim: maxMb.map { L("mv_media_limit", megabytes($0)) } ?? L("mv_media_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                FlowLayout(spacing: 8, lineSpacing: 8) {
                    ForEach(draft.photos) { media in
                        ZStack(alignment: .topTrailing) {
                            RemoteImage(url: media.url, placeholder: "photo").frame(width: 92, height: 92).clipped()
                            MediaRemove { remove(media) }
                        }
                        .frame(width: 92, height: 92)
                        .background(wa.settingsBackground)
                        .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    ForEach(draft.videos) { media in
                        ZStack(alignment: .topTrailing) {
                            Image(systemName: "play.circle.fill").font(.system(size: 34)).foregroundStyle(wa.accent).frame(width: 92, height: 92)
                            MediaRemove { remove(media) }
                        }
                        .frame(width: 92, height: 92)
                        .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    ForEach(0..<uploading, id: \.self) { _ in
                        ProgressView().tint(wa.accent).frame(width: 92, height: 92)
                            .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    Button { pickerOpen = true } label: {
                        Image(systemName: "photo.badge.plus").font(.system(size: 26)).foregroundStyle(wa.accent)
                            .frame(width: 92, height: 92)
                            .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.settingsInk.opacity(0.12), lineWidth: 1.5))
                    }
                    .buttonStyle(WaPressStyle())
                }
                .padding(.top, 10)
                if let warning { MvNote(text: warning, color: AppColors.warning).padding(.top, 8) }
            }
        }
        .photosPicker(isPresented: $pickerOpen, selection: $items, maxSelectionCount: 10, matching: .any(of: [.images, .videos]))
        .onChange(of: items) { picked in
            guard !picked.isEmpty else { return }
            items = []
            upload(picked)
        }
    }

    private func upload(_ picked: [PhotosPickerItem]) {
        guard let uuid = draft.uuid else { return }
        warning = nil
        let maxMb = options?.mediaMaxMb
        for item in picked {
            let types = item.supportedContentTypes
            let video = types.contains { $0.conforms(to: .movie) }
            let image = types.contains { $0.conforms(to: .image) }
            guard video || image else { warning = L("mv_media_type"); continue }
            uploading += 1
            Task {
                defer { uploading -= 1 }
                guard var data = try? await item.loadTransferable(type: Data.self) else { warning = L("rd_error"); return }
                var mime: String
                var ext: String
                if video {
                    let type = types.first { $0.conforms(to: .movie) }
                    mime = type?.preferredMIMEType?.lowercased() ?? "video/quicktime"
                    ext = type?.preferredFilenameExtension ?? "mov"
                } else {
                    let type = types.first { $0.conforms(to: .image) }
                    mime = type?.preferredMIMEType?.lowercased() ?? "image/jpeg"
                    ext = type?.preferredFilenameExtension ?? "jpg"
                    // The iPhone's HEIC isn't taken by the server: it goes up as JPEG (Android's picker hands JPEGs already).
                    if !imageOrVideo.contains(mime) {
                        guard let picture = UIImage(data: data), let jpeg = picture.jpegData(compressionQuality: 0.9) else { warning = L("mv_media_type"); return }
                        data = jpeg
                        mime = "image/jpeg"
                        ext = "jpg"
                    }
                }
                guard imageOrVideo.contains(mime) else { warning = L("mv_media_type"); return }
                // Said before anything is sent: a file over the country's limit is never uploaded.
                if let maxMb, Double(data.count) > maxMb * 1024 * 1024 { warning = L("mv_media_too_big", megabytes(maxMb)); return }
                let part = ApiClient.FilePart(field: "file", filename: "moving_\(Int(Date().timeIntervalSince1970 * 1000)).\(ext)", mimeType: mime, data: data)
                do {
                    if let media = try await MovingApi.addMedia(uuid, file: part).data {
                        if media.collection == "videos" { draft.videos.append(media) } else { draft.photos.append(media) }
                        draft.clearMissing("description_or_photo")
                    }
                } catch {
                    warning = error.apiFailure.message ?? L("rd_error")
                }
            }
        }
    }

    private func remove(_ media: MovingMediaDto) {
        guard let uuid = draft.uuid else { return }
        draft.photos.removeAll { $0.id == media.id }
        draft.videos.removeAll { $0.id == media.id }
        Task { await MovingApi.removeMedia(uuid, media: media.id) }
    }
}

private struct MediaRemove: View {
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: "xmark").font(.system(size: 11, weight: .bold)).foregroundStyle(.white)
                .frame(width: 26, height: 26).background(Color.black.opacity(0.55), in: Circle())
        }
        .buttonStyle(.plain)
        .padding(4)
    }
}

// MARK: - 3. The vehicle and the services

private struct VehicleStep: View {
    @Environment(\.wa) private var wa
    @ObservedObject var draft: MovingDraft
    let options: MovingOptionsDto?

    var body: some View {
        MvScroll(pull: false) {
            MvCard {
                MvCardTitle(text: L("mv_vehicle_title"))
                MissingNote(draft: draft, key: "vehicle_type_id")
                let types = options?.vehicleTypes ?? []
                if options != nil && types.isEmpty {
                    Text(L("mv_no_vehicle_types")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.top, 10)
                }
                VStack(spacing: 8) {
                    ForEach(types, id: \.id) { type in vehicleRow(type) }
                }
                .padding(.top, 10)
            }
            MvCard {
                RideStepperLine(icon: "person.3.fill", title: L("mv_workers"), sub: L("mv_workers_hint"), value: draft.workers, range: 0...50) { draft.workers = $0 }
            }
            MvCard {
                MvCardTitle(text: L("mv_extras_title")).padding(.bottom, 4)
                RideToggleLine(icon: "shippingbox.fill", title: L("mv_extra_packing"), sub: nil, on: draft.packing) { draft.packing = $0 }
                RideToggleLine(icon: "wrench.and.screwdriver.fill", title: L("mv_extra_disassembly"), sub: nil, on: draft.disassembly) { draft.disassembly = $0 }
                RideToggleLine(icon: "box.truck.fill", title: L("mv_extra_crane"), sub: nil, on: draft.crane) { draft.crane = $0 }
                RideToggleLine(icon: "wrench.and.screwdriver.fill", title: L("mv_extra_technician"), sub: nil, on: draft.technician) { draft.technician = $0 }
            }
        }
    }

    private func vehicleRow(_ type: MovingVehicleTypeDto) -> some View {
        let on = draft.vehicleTypeId == type.id
        return Button {
            draft.vehicleTypeId = type.id
            draft.clearMissing("vehicle_type_id")
        } label: {
            HStack(spacing: 12) {
                ZStack {
                    if let url = ApiClient.mediaUrl(type.image) {
                        AsyncImage(url: url) { $0.resizable().scaledToFit() } placeholder: { Color.clear }.padding(4)
                    } else {
                        Image(systemName: "box.truck.fill").font(.system(size: 26)).foregroundStyle(wa.accent)
                    }
                }
                .frame(width: 64, height: 64)
                .background(wa.accent.opacity(0.08), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: type.name ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                    if let d = type.description, !d.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(verbatim: d).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                }
                Spacer(minLength: 0)
                if on { Image(systemName: "checkmark.circle.fill").font(.system(size: 20)).foregroundStyle(wa.accent) }
            }
            .padding(10)
            .background(on ? wa.accent.opacity(0.10) : wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(on ? wa.accent : .clear, lineWidth: 1.5))
        }
        .buttonStyle(.plain)
        .animation(.easeInOut(duration: 0.2), value: on)
    }
}

// MARK: - 4. The place and the time

private struct PlaceStep: View {
    @Environment(\.wa) private var wa
    @ObservedObject var draft: MovingDraft
    let onPick: (String) -> Void
    let onTime: () -> Void

    var body: some View {
        MvScroll(pull: false) {
            EndCard(draft: draft, key: "pickup", end: $draft.pickup, title: L("mv_pickup"), dot: AppColors.brandNavy, onPick: onPick)
            EndCard(draft: draft, key: "dropoff", end: $draft.dropoff, title: L("mv_dropoff"), dot: AppColors.brandOrange, onPick: onPick)
            MvCard {
                Button(action: onTime) {
                    HStack(spacing: 10) {
                        Image(systemName: "calendar").font(.system(size: 15)).foregroundStyle(wa.accent)
                            .frame(width: 34, height: 34).background(wa.accent.opacity(0.12), in: Circle())
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("mv_when")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                            Text(verbatim: draft.scheduledAt.map { rideWhenText($0) } ?? L("mv_pick_time")).font(.dorr(14, .semibold))
                                .foregroundStyle(draft.scheduledAt == nil ? wa.settingsMut : wa.settingsInk)
                        }
                        Spacer(minLength: 0)
                        Image(systemName: "pencil").font(.system(size: 15)).foregroundStyle(wa.accent)
                    }
                    .padding(.vertical, 6)
                    .contentShape(Rectangle())
                }
                .buttonStyle(WaPressStyle())
                MissingNote(draft: draft, key: "scheduled_at")
                if let at = draft.scheduledAt, at <= Date() {
                    Text(L("mv_time_passed")).font(.dorr(12.5, .semibold)).foregroundStyle(AppColors.danger)
                }
            }
            MvCard {
                DorrTextArea(value: limited({ draft.notes }, { draft.notes = $0 }, 2000), label: L("mv_notes"), minHeight: 70)
            }
        }
    }
}

private struct EndCard: View {
    @Environment(\.wa) private var wa
    @ObservedObject var draft: MovingDraft
    let key: String
    @Binding var end: MovingEnd
    let title: String
    let dot: Color
    let onPick: (String) -> Void

    var body: some View {
        MvCard {
            Button { onPick(key) } label: {
                HStack(spacing: 10) {
                    Image(systemName: "mappin.circle.fill").font(.system(size: 16)).foregroundStyle(dot)
                        .frame(width: 34, height: 34).background(dot.opacity(0.12), in: Circle())
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: title).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                        Text(verbatim: end.shown ?? L("mv_pick_place")).font(.dorr(14, .semibold))
                            .foregroundStyle(end.point == nil ? wa.settingsMut : wa.settingsInk).lineLimit(2)
                    }
                    Spacer(minLength: 0)
                    Image(systemName: "pencil").font(.system(size: 15)).foregroundStyle(wa.accent)
                }
                .padding(.vertical, 6)
                .contentShape(Rectangle())
            }
            .buttonStyle(WaPressStyle())
            MissingNote(draft: draft, key: key)
            if end.point != nil {
                VStack(spacing: 8) {
                    DorrTextArea(value: limited({ end.area }, { end.area = $0 }, 120), label: L("mv_area"), placeholder: L("mv_area_hint"), singleLine: true)
                    DorrTextArea(value: limited({ end.address }, { end.address = $0 }, 255), label: L("mv_address"), singleLine: true)
                    DorrTextArea(value: limited({ end.entrance }, { end.entrance = $0 }, 255), label: L("mv_entrance"), placeholder: L("mv_entrance_hint"), singleLine: true)
                    DorrTextArea(value: limited({ end.floor }, { end.floor = $0 }, 4, filter: floorChars), label: L("mv_floor"), singleLine: true)
                        .keyboardType(.numbersAndPunctuation)
                    RideToggleLine(icon: "arrow.up.arrow.down.square.fill", title: L("mv_elevator"), sub: nil, on: end.elevator) { end.elevator = $0 }
                }
                .padding(.top, 8)
            }
        }
    }

    /// A floor: digits, and a minus in front for a basement.
    private func floorChars(_ typed: String) -> String {
        let minus = typed.trimmingCharacters(in: .whitespaces).hasPrefix("-")
        return (minus ? "-" : "") + plainDigits(typed)
    }
}

// MARK: - 5. The review

private struct ReviewStep: View {
    @Environment(\.wa) private var wa
    @ObservedObject var draft: MovingDraft
    let options: MovingOptionsDto?
    let onEdit: (Int) -> Void

    var body: some View {
        MvScroll(pull: false) {
            Text(L("mv_review_title")).font(.dorr(22, .black)).foregroundStyle(wa.settingsInk)
            Text(L("mv_review_hint")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
            ReviewCard(title: L("mv_step_type"), onEdit: { onEdit(stepType) }) {
                Text(verbatim: draft.loadType.map { loadTypeLabel($0) } ?? "—").font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
                MissingNote(draft: draft, key: "load_type")
            }
            ReviewCard(title: L("mv_step_items"), onEdit: { onEdit(stepItems) }) {
                let text = draft.description.trimmingCharacters(in: .whitespacesAndNewlines)
                if !text.isEmpty { Text(verbatim: text).font(.dorr(14)).foregroundStyle(wa.settingsInk) }
                if !draft.pieces.isEmpty { MvLine(label: L("mv_pieces"), value: draft.pieces) }
                if !draft.dimensions.trimmingCharacters(in: .whitespaces).isEmpty { MvLine(label: L("mv_dimensions"), value: draft.dimensions) }
                MvLine(label: L("mv_media_title"), value: L("mv_media_counts", draft.photos.count, draft.videos.count))
                MissingNote(draft: draft, key: "description_or_photo")
            }
            ReviewCard(title: L("mv_step_vehicle"), onEdit: { onEdit(stepVehicle) }) {
                let type = options?.vehicleTypes.first { $0.id == draft.vehicleTypeId }
                MvLine(label: L("mv_vehicle_title"), value: type?.name ?? "—")
                MvLine(label: L("mv_workers"), value: String(draft.workers))
                let extras = [
                    draft.packing ? L("mv_extra_packing") : nil,
                    draft.disassembly ? L("mv_extra_disassembly") : nil,
                    draft.crane ? L("mv_extra_crane") : nil,
                    draft.technician ? L("mv_extra_technician") : nil,
                ].compactMap { $0 }
                if !extras.isEmpty { Text(verbatim: extras.joined(separator: " · ")).font(.dorr(13)).foregroundStyle(wa.settingsInk) }
                MissingNote(draft: draft, key: "vehicle_type_id")
            }
            ReviewCard(title: L("mv_step_place"), onEdit: { onEdit(stepPlace) }) {
                ReviewEnd(title: L("mv_pickup"), end: draft.pickup)
                MissingNote(draft: draft, key: "pickup")
                ReviewEnd(title: L("mv_dropoff"), end: draft.dropoff)
                MissingNote(draft: draft, key: "dropoff")
                MvLine(label: L("mv_when"), value: draft.scheduledAt.map { rideWhenText($0) } ?? "—")
                MissingNote(draft: draft, key: "scheduled_at")
            }
        }
    }
}

private struct ReviewCard<Content: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    let onEdit: () -> Void
    @ViewBuilder let content: () -> Content

    var body: some View {
        MvCard {
            HStack {
                MvCardTitle(text: title)
                Spacer(minLength: 0)
                Button(action: onEdit) {
                    Text(L("mv_edit")).font(.dorr(13, .bold)).foregroundStyle(wa.accent).padding(.horizontal, 8).padding(.vertical, 4)
                }
                .buttonStyle(.plain)
            }
            VStack(alignment: .leading, spacing: 2) { content() }.padding(.top, 6)
        }
    }
}

private struct ReviewEnd: View {
    @Environment(\.wa) private var wa
    let title: String
    let end: MovingEnd

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: title).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            Text(verbatim: end.shown ?? "—").font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
            let details = [
                end.area.trimmingCharacters(in: .whitespaces).isEmpty ? nil : end.area,
                end.floor.isEmpty ? nil : L("mv_floor_value", end.floor),
                end.point == nil ? nil : L(end.elevator ? "mv_has_elevator" : "mv_no_elevator"),
            ].compactMap { $0 }
            if !details.isEmpty { Text(verbatim: details.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
        }
        .padding(.vertical, 3)
    }
}
