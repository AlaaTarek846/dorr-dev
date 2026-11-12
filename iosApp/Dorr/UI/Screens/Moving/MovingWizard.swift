import CoreLocation
import PhotosUI
import SwiftUI
import UniformTypeIdentifiers

// androidApp `moving/MovingWizard.kt` — a new request (REQ-081), one draft kept on the server so a lost connection loses
// nothing. The customer starts with what they know: from and to (on a map, with the road distance) · the load, what is
// moved and its photos · vehicle, workers and extras · the time and notes · review and publish. Nothing is required
// before the review: the server says what is missing (`data.missing`), and that is shown beside the step it belongs to.
//
// The server makes the draft with its load type (`POST requests` needs `load_type`), so the places typed before it stay
// on the phone; choosing the load type creates the draft (photos need it), and every "next" after that saves it
// (`PUT requests/{uuid}`).

private let stepPlaces = 0
private let stepLoad = 1
private let stepVehicle = 2
private let stepTime = 3
private let stepReview = 4
private let stepCount = 5

private let imageOrVideo: Set<String> = ["image/jpeg", "image/png", "image/webp", "video/mp4", "video/quicktime"]

/// Which step a field the server says is missing belongs to.
private func stepOfMissing(_ key: String) -> Int {
    switch key {
    case "pickup", "dropoff": return stepPlaces
    case "load_type", "description_or_photo": return stepLoad
    case "vehicle_type_id": return stepVehicle
    default: return stepTime
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

    /// Pickup and drop-off change places, with all their details.
    mutating func swapWith(_ other: inout MovingEnd) {
        let mine = self
        self = other
        other = mine
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
    /// The vehicle types the customer accepts, in the order picked: the first is their first choice.
    @Published var vehicleTypeIds: [Int] = []
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
    /// The voice note about the load (one; a new one replaces it).
    @Published var voice: MovingMediaDto?
    @Published var missing: [String] = []
    /// The save running now: the next one waits for it (Android's `Mutex`), so two quick taps never make two drafts.
    var saving: Task<Bool, Never>?

    func fill(_ r: MovingRequestDto) {
        uuid = r.uuid
        loadType = r.loadType
        description = r.description ?? ""
        pieces = r.piecesCount.map(String.init) ?? ""
        dimensions = r.dimensions ?? ""
        vehicleTypeIds = r.vehicleTypeIds.isEmpty ? [r.vehicleTypeId].compactMap { $0 } : r.vehicleTypeIds
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
        voice = r.voice
    }

    func body() -> [String: JSONValue] {
        var b: [String: JSONValue] = [:]
        if let loadType { b["load_type"] = .string(loadType) }
        b["description"] = .string(String(description.trimmingCharacters(in: .whitespacesAndNewlines).prefix(3000)))
        if let count = Int(pieces.trimmingCharacters(in: .whitespaces)) { b["pieces_count"] = .int(count) } else { b["pieces_count"] = .string("") }
        b["dimensions"] = .string(String(dimensions.trimmingCharacters(in: .whitespacesAndNewlines).prefix(255)))
        b["vehicle_type_ids"] = .array(vehicleTypeIds.map { .int($0) })
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
    @ObservedObject private var location = RideLocation.shared
    let initialUuid: String?
    @ObservedObject var nav: MovingNav
    let atReview: Bool
    @StateObject private var draft = MovingDraft()
    /// The road between the two ends: asked once per pair, kept here so the places step and the review share it.
    @StateObject private var route = MovingRouteState()
    @State private var step: Int
    @State private var forward = true
    @State private var loaded: Bool
    @State private var options: MovingOptionsDto?
    @State private var busy = false
    @State private var error: String?
    /// "pickup" | "dropoff" while its picker is open over the wizard.
    @State private var picker: String?
    @State private var timeOpen = false
    /// The pickup is the phone's own place (filled for a new request), labelled «موقعك».
    @State private var pickupIsHere = false

    init(initialUuid: String?, nav: MovingNav, atReview: Bool = false) {
        self.initialUuid = initialUuid
        _nav = ObservedObject(wrappedValue: nav)
        self.atReview = atReview
        _step = State(initialValue: atReview ? stepReview : stepPlaces)
        _loaded = State(initialValue: initialUuid == nil)
    }

    var body: some View {
        let missingSteps = Set(draft.missing.map(stepOfMissing))
        ZStack {
            MvPage(title: L("mv_new_title"), onBack: { if step > stepPlaces { go(step - 1) } else { nav.pop() } }, bottom: AnyView(bottomBar)) {
                if !loaded {
                    MvLoading()
                } else {
                    VStack(spacing: 0) {
                        MovingStepBar(step: step, missing: missingSteps) { target in if target <= step { go(target) } }
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
            // One end of the move: over the wizard. Picking it saves the draft (once there is one), so a lost connection keeps it too.
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
        .movingRoute(route, from: draft.pickup.point?.latLng, to: draft.dropoff.point?.latLng)
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
        // A new request starts from where the customer is: the pickup is the phone's place when location is allowed, so
        // most people only choose the drop-off. Asked once; without it they choose both.
        .task(id: loaded) {
            guard loaded, initialUuid == nil, draft.pickup.point == nil, location.status == .notDetermined else { return }
            location.ask()
        }
        .task(id: "\(loaded)|\(location.allowed)") { await fillHere() }
    }

    private var bottomBar: some View {
        VStack(spacing: 8) {
            MvError(text: error).frame(maxWidth: .infinity, alignment: .leading)
            if step == stepReview {
                AccentButton(text: L("mv_publish"), busy: busy, icon: "checkmark.circle.fill") { publish() }
            } else {
                AccentButton(text: L("mv_next"), enabled: step != stepLoad || draft.loadType != nil, busy: busy, icon: "arrow.forward") { next() }
            }
        }
    }

    @ViewBuilder
    private func stepView(_ current: Int) -> some View {
        switch current {
        case stepPlaces: PlacesStep(draft: draft, route: route, pickupIsHere: pickupIsHere, onPick: { picker = $0 }, onSwap: { swapEnds() })
        case stepLoad: LoadStep(draft: draft, options: options) { chooseType($0) }
        case stepVehicle: VehicleStep(draft: draft, options: options)
        case stepTime: TimeStep(draft: draft) { timeOpen = true }
        default: ReviewStep(draft: draft, options: options, route: route) { go($0) }
        }
    }

    private func go(_ to: Int) {
        forward = to > step
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.3)) { step = to }
    }

    /// The phone's place as the pickup of a new request, once location is allowed (never over a place already chosen).
    private func fillHere() async {
        guard loaded, location.allowed, initialUuid == nil, draft.pickup.point == nil else { return }
        guard let here = await location.current(), draft.pickup.point == nil else { return }
        let address = await addressOf(here) ?? coordinateText(here)
        guard draft.pickup.point == nil else { return }
        draft.pickup.point = RidePoint(lat: here.latitude, lng: here.longitude, address: address)
        draft.pickup.address = address
        pickupIsHere = true
        if let found = await areaOf(here), !found.isEmpty { draft.pickup.area = found }
    }

    private func picked(_ place: RidePoint, _ which: String) {
        if which == "pickup" {
            draft.pickup.point = place
            draft.pickup.address = place.address
            pickupIsHere = false
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

    private func swapEnds() {
        var pickup = draft.pickup
        var dropoff = draft.dropoff
        pickup.swapWith(&dropoff)
        withAnimation(.spring(response: 0.35, dampingFraction: 0.8)) {
            draft.pickup = pickup
            draft.dropoff = dropoff
        }
        pickupIsHere = false
        let hasPickup = draft.pickup.point != nil
        let hasDropoff = draft.dropoff.point != nil
        draft.missing.removeAll { ($0 == "pickup" && hasPickup) || ($0 == "dropoff" && hasDropoff) }
        Task { _ = await save() }
    }

    private func chooseType(_ type: String) {
        draft.loadType = type
        draft.clearMissing("load_type")
        // The first choice makes the draft on the server, so photos can be added right away.
        if draft.uuid == nil { Task { _ = await save() } }
    }

    /// Saves what is on the phone; false when it couldn't (and says why, the server's words first). One save at a time:
    /// the second of two quick taps waits for the first, finds the uuid and updates.
    private func save() async -> Bool {
        let previous = draft.saving
        let task = Task<Bool, Never> {
            _ = await previous?.value
            return await saveNow()
        }
        draft.saving = task
        return await task.value
    }

    private func saveNow() async -> Bool {
        // No load type yet: the server can't make the draft, so what is typed stays on the phone until it is chosen.
        if draft.uuid == nil && draft.loadType == nil { return true }
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
            if await save() {
                if let uuid = draft.uuid {
                    do {
                        _ = try await MovingApi.publish(uuid)
                        nav.replace(.request(uuid))
                    } catch {
                        let refusal = error.movingRefusal
                        draft.missing = refusal.missing
                        self.error = refusal.message ?? L("rd_error")
                    }
                } else {
                    // Never saved (no load type): the review shows what is missing.
                    draft.missing = ["load_type"]
                }
            }
            busy = false
        }
    }
}

// MARK: - The step bar

private func stepIcon(_ i: Int) -> String {
    switch i {
    case stepPlaces: return "mappin.and.ellipse"
    case stepLoad: return "shippingbox.fill"
    case stepVehicle: return "box.truck.fill"
    case stepTime: return "calendar"
    default: return "checklist"
    }
}

private func stepShortLabel(_ i: Int) -> String {
    switch i {
    case stepPlaces: return L("mv_step_short_places")
    case stepLoad: return L("mv_step_short_load")
    case stepVehicle: return L("mv_step_short_vehicle")
    case stepTime: return L("mv_step_short_time")
    default: return L("mv_step_short_review")
    }
}

/// The five steps as icons joined by a line, each with its short name: done ones filled with a tick, the current one
/// filled and a little bigger, the rest waiting. A step the server says is missing something turns red. A step already
/// reached can be tapped.
private struct MovingStepBar: View {
    let step: Int
    let missing: Set<Int>
    let onTap: (Int) -> Void

    var body: some View {
        HStack(alignment: .top, spacing: 0) {
            ForEach(0..<stepCount, id: \.self) { i in
                StepCell(index: i, step: step, bad: missing.contains(i))
                    .frame(maxWidth: .infinity)
                    .contentShape(Rectangle())
                    .onTapGesture { if i <= step { onTap(i) } }
            }
        }
        .padding(.horizontal, 8)
        .padding(.bottom, 12)
    }
}

/// One step of the bar: its circle on the joining line, and its short name.
private struct StepCell: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let index: Int
    let step: Int
    let bad: Bool

    private var done: Bool { index < step }
    private var now: Bool { index == step }

    private var fill: Color {
        if bad { return AppColors.danger }
        return done || now ? wa.accent : wa.mvInset(onPanel)
    }

    private var labelColor: Color {
        if bad { return AppColors.danger }
        return now ? wa.settingsInk : wa.settingsMut
    }

    private func line(_ shown: Bool, _ lit: Bool) -> Color {
        guard shown else { return Color.clear }
        return lit ? wa.accent : wa.settingsInk.opacity(0.10)
    }

    var body: some View {
        VStack(spacing: 4) {
            ZStack {
                // The line to the step before (start side) and to the one after (end side).
                HStack(spacing: 0) {
                    Rectangle().fill(line(index > 0, index <= step)).frame(height: 3)
                    Rectangle().fill(line(index < stepCount - 1, index < step)).frame(height: 3)
                }
                Image(systemName: done && !bad ? "checkmark" : stepIcon(index))
                    .font(.system(size: 14, weight: .bold))
                    .foregroundStyle(done || now || bad ? Color.white : wa.settingsMut)
                    .frame(width: 34, height: 34)
                    .background(fill, in: Circle())
                    .overlay(Circle().strokeBorder(now ? fill.opacity(0.25) : Color.clear, lineWidth: 3))
                    .scaleEffect(now ? 1.12 : 1)
                    .animation(.spring(response: 0.3, dampingFraction: 0.5), value: now)
                    .animation(.easeInOut(duration: 0.26), value: fill)
            }
            .frame(height: 40)
            Text(verbatim: stepShortLabel(index))
                .font(.dorr(10.5, now ? .bold : .medium))
                .foregroundStyle(labelColor)
                .lineLimit(1)
                .multilineTextAlignment(.center)
        }
        .padding(.vertical, 2)
    }
}

/// A step's heading and the line under it.
private struct StepTitle: View {
    @Environment(\.wa) private var wa
    let title: String
    var hint: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(verbatim: title).font(.dorr(22, .black)).foregroundStyle(wa.settingsInk)
            if let hint { Text(verbatim: hint).font(.dorr(13)).foregroundStyle(wa.settingsMut) }
        }
        .staggerIn(0)
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

// MARK: - 1. From and to

private struct PlacesStep: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    @ObservedObject var draft: MovingDraft
    @ObservedObject var route: MovingRouteState
    let pickupIsHere: Bool
    let onPick: (String) -> Void
    let onSwap: () -> Void

    var body: some View {
        let from = draft.pickup.point?.latLng
        let to = draft.dropoff.point?.latLng
        MvScroll(pull: false) {
            StepTitle(title: L("mv_places_title"), hint: L("mv_places_hint"))
            MvCard {
                MovingRouteMap(pickup: from, dropoff: to, route: route.route, onTap: { onPick(from == nil ? "pickup" : "dropoff") })
                    .frame(maxWidth: .infinity)
                    .frame(height: 200)
                MovingRouteChips(state: route, bothEnds: from != nil && to != nil,
                                 fallbackKm: from.flatMap { a in to.map { straightKm(a, $0) } }, top: 10)
                HStack(spacing: 8) {
                    VStack(alignment: .leading, spacing: 0) {
                        EndRow(title: L("mv_pickup"), end: draft.pickup, dot: AppColors.brandNavy,
                               badge: pickupIsHere ? L("mv_current_location") : nil) { onPick("pickup") }
                        MissingNote(draft: draft, key: "pickup")
                        Rectangle().fill(wa.settingsInk.opacity(0.12)).frame(width: 2, height: 12).padding(.leading, 16)
                        EndRow(title: L("mv_dropoff"), end: draft.dropoff, dot: AppColors.brandOrange, badge: nil) { onPick("dropoff") }
                        MissingNote(draft: draft, key: "dropoff")
                    }
                    if from != nil && to != nil {
                        Button(action: onSwap) {
                            Image(systemName: "arrow.up.arrow.down").font(.system(size: 18, weight: .semibold)).foregroundStyle(wa.accent)
                                .frame(width: 40, height: 40)
                                .background(wa.mvInset(onPanel), in: Circle())
                        }
                        .buttonStyle(WaPressStyle())
                        .accessibilityLabel(L("mv_swap"))
                        .transition(.scale.combined(with: .opacity))
                    }
                }
                .padding(.top, 12)
                .animation(.easeInOut(duration: 0.2), value: from != nil && to != nil)
            }
            .staggerIn(1)
            if draft.pickup.point != nil {
                EndDetailsCard(title: L("mv_pickup"), dot: AppColors.brandNavy, end: $draft.pickup).staggerIn(2)
            }
            if draft.dropoff.point != nil {
                EndDetailsCard(title: L("mv_dropoff"), dot: AppColors.brandOrange, end: $draft.dropoff).staggerIn(3)
            }
        }
    }
}

/// One end as a row that opens the place picker: its coloured dot, its name and the chosen address.
private struct EndRow: View {
    @Environment(\.wa) private var wa
    let title: String
    let end: MovingEnd
    let dot: Color
    let badge: String?
    let action: () -> Void

    var body: some View {
        let chosen = end.point
        Button(action: action) {
            HStack(spacing: 10) {
                Image(systemName: badge != nil ? "location.fill" : "mappin").font(.system(size: 16, weight: .semibold)).foregroundStyle(dot)
                    .frame(width: 34, height: 34).background(dot.opacity(0.14), in: Circle())
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 6) {
                        Text(verbatim: title).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                        if let badge {
                            Text(verbatim: badge).font(.dorr(10, .bold)).foregroundStyle(wa.accent)
                                .padding(.horizontal, 6).padding(.vertical, 1)
                                .background(wa.accent.opacity(0.12), in: Capsule())
                        }
                    }
                    Text(verbatim: end.shown ?? L("mv_pick_place")).font(.dorr(14, .semibold))
                        .foregroundStyle(chosen == nil ? wa.accent : wa.settingsInk).lineLimit(1)
                }
                Spacer(minLength: 0)
                Image(systemName: chosen == nil ? "plus" : "pencil").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.accent)
            }
            .padding(.vertical, 6)
            .contentShape(Rectangle())
        }
        .buttonStyle(WaPressStyle())
    }
}

/// An end's building: the floor on a stepper, the elevator as a chip, and the rest folded under «تفاصيل أكثر».
private struct EndDetailsCard: View {
    @Environment(\.wa) private var wa
    let title: String
    let dot: Color
    @Binding var end: MovingEnd
    @State private var more: Bool

    init(title: String, dot: Color, end: Binding<MovingEnd>) {
        self.title = title
        self.dot = dot
        _end = end
        _more = State(initialValue: !end.wrappedValue.entrance.trimmingCharacters(in: .whitespaces).isEmpty)
    }

    var body: some View {
        MvCard {
            HStack(spacing: 8) {
                Circle().fill(dot).frame(width: 10, height: 10)
                MvCardTitle(text: title)
                Text(verbatim: end.area).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                Spacer(minLength: 0)
            }
            FloorStepper(end: $end).padding(.top, 8)
            MvToggleChip(icon: "arrow.up.arrow.down.square.fill", label: L("mv_elevator"), on: end.elevator) { end.elevator.toggle() }
                .padding(.top, 6)
            Button {
                withAnimation(.easeInOut(duration: 0.22)) { more.toggle() }
            } label: {
                HStack {
                    Text(L("mv_more_details")).font(.dorr(13, .semibold)).foregroundStyle(wa.accent)
                    Spacer(minLength: 0)
                    Image(systemName: "chevron.down").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.accent)
                        .rotationEffect(.degrees(more ? 180 : 0))
                }
                .padding(.vertical, 8)
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .padding(.top, 6)
            if more {
                VStack(spacing: 8) {
                    DorrTextArea(value: limited({ end.area }, { end.area = $0 }, 120), label: L("mv_area"), placeholder: L("mv_area_hint"), singleLine: true)
                    DorrTextArea(value: limited({ end.address }, { end.address = $0 }, 255), label: L("mv_address"), singleLine: true)
                    DorrTextArea(value: limited({ end.entrance }, { end.entrance = $0 }, 255), label: L("mv_entrance"), placeholder: L("mv_entrance_hint"), singleLine: true)
                }
                .padding(.top, 4)
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
    }
}

/// The floor with − and +: "—" until touched (unknown), then the ground floor first; −5 to 200 as the server takes.
private struct FloorStepper: View {
    @Environment(\.wa) private var wa
    @Binding var end: MovingEnd

    var body: some View {
        let level = Int(end.floor.trimmingCharacters(in: .whitespaces))
        let shown: String = {
            guard let level else { return "—" }
            return level == 0 ? L("mv_floor_ground") : String(level)
        }()
        HStack(spacing: 0) {
            Image(systemName: "square.stack.3d.up.fill").font(.system(size: 19)).foregroundStyle(wa.accent).frame(width: 24)
            Text(L("mv_floor")).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk).padding(.leading, 10)
            Spacer(minLength: 0)
            MvStepCircle(icon: "minus", enabled: level.map { $0 > -5 } ?? true) { end.floor = String((level ?? 1) - 1) }
            ZStack {
                Text(verbatim: shown).font(.dorr(15, .black)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    .id(shown)
                    .transition(.asymmetric(insertion: .move(edge: .bottom).combined(with: .opacity), removal: .move(edge: .top).combined(with: .opacity)))
            }
            .frame(width: 64, height: 34)
            .clipped()
            .animation(.easeOut(duration: 0.16), value: shown)
            MvStepCircle(icon: "plus", enabled: level.map { $0 < 200 } ?? true) { end.floor = String((level ?? -1) + 1) }
        }
        .padding(.vertical, 4)
    }
}

private struct MvStepCircle: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let icon: String
    let enabled: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 15, weight: .bold)).foregroundStyle(enabled ? wa.accent : wa.settingsMut.opacity(0.4))
                .frame(width: 34, height: 34)
                .background(enabled ? wa.accent.opacity(0.12) : wa.mvInset(onPanel), in: Circle())
        }
        .buttonStyle(WaPressStyle())
        .disabled(!enabled)
    }
}

/// A yes/no as a chip: filled in the app colour with a tick when on.
private struct MvToggleChip: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let icon: String
    let label: String
    let on: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 8) {
                Image(systemName: icon).font(.system(size: 16)).foregroundStyle(on ? wa.accent : wa.settingsMut)
                Text(verbatim: label).font(.dorr(13, on ? .bold : .medium)).foregroundStyle(wa.settingsInk)
                if on {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 14)).foregroundStyle(wa.accent)
                        .transition(.scale.combined(with: .opacity))
                }
            }
            .padding(.horizontal, 14).padding(.vertical, 9)
            .background(on ? wa.accent.opacity(0.12) : wa.mvInset(onPanel), in: Capsule())
            .overlay(Capsule().stroke(on ? wa.accent : Color.clear, lineWidth: 1.5))
        }
        .buttonStyle(WaPressStyle())
        .animation(.easeInOut(duration: 0.2), value: on)
    }
}

// MARK: - 2. The load, what is moved, with photos

private struct LoadStep: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    @ObservedObject var draft: MovingDraft
    let options: MovingOptionsDto?
    let onType: (String) -> Void
    @State private var uploading = 0
    @State private var warning: String?
    @State private var pickerOpen = false
    @State private var items: [PhotosPickerItem] = []

    var body: some View {
        let maxMb = options?.mediaMaxMb
        MvScroll(pull: false) {
            StepTitle(title: L("mv_type_title"))
            MissingNote(draft: draft, key: "load_type")
            HStack(spacing: 10) {
                LoadTile(icon: "sofa.fill", title: L("mv_load_single"), hint: L("mv_load_single_hint"), on: draft.loadType == "single_piece") { onType("single_piece") }
                LoadTile(icon: "house.fill", title: L("mv_load_home"), hint: L("mv_load_home_hint"), on: draft.loadType == "home") { onType("home") }
                LoadTile(icon: "building.2.fill", title: L("mv_load_commercial"), hint: L("mv_load_commercial_hint"), on: draft.loadType == "commercial") { onType("commercial") }
            }
            .fixedSize(horizontal: false, vertical: true)
            .staggerIn(1)
            MvCard {
                MvCardTitle(text: L("mv_items_title"))
                DorrTextArea(value: limited({ draft.description }, { draft.description = $0; draft.clearMissing("description_or_photo") }, 3000),
                             label: L("mv_description"), placeholder: L("mv_description_hint"), minHeight: 96)
                    .padding(.top, 10)
                // Or say it: a voice note on the same page as the description.
                MvVoiceNote(draft: draft).padding(.top, 10)
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
            .staggerIn(2)
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
                    Button {
                        // Photos belong to the draft on the server, made when the load type is chosen.
                        if draft.uuid == nil { warning = L("mv_media_first") } else { pickerOpen = true }
                    } label: {
                        Image(systemName: "photo.badge.plus").font(.system(size: 26)).foregroundStyle(wa.accent)
                            .frame(width: 92, height: 92)
                            .background(wa.accent.opacity(0.08), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.accent.opacity(0.35), lineWidth: 1.5))
                    }
                    .buttonStyle(WaPressStyle())
                }
                .padding(.top, 10)
                if let warning { MvNote(text: warning, color: AppColors.warning).padding(.top, 8) }
            }
            .staggerIn(3)
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

/// A load type as a tile: its icon in a soft square, the name and a hint; the chosen one washed, bordered and popped.
private struct LoadTile: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let icon: String
    let title: String
    let hint: String
    let on: Bool
    let action: () -> Void

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 20, style: .continuous)
        Button(action: action) {
            ZStack(alignment: .topTrailing) {
                VStack(spacing: 0) {
                    Image(systemName: icon).font(.system(size: 24)).foregroundStyle(on ? Color.white : wa.accent)
                        .frame(width: 52, height: 52)
                        .background(on ? wa.accent : wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    Text(verbatim: title).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                        .multilineTextAlignment(.center).lineLimit(2).padding(.top, 8)
                    Text(verbatim: hint).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                        .multilineTextAlignment(.center).lineLimit(3).padding(.top, 2)
                    Spacer(minLength: 0)
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
                .padding(.horizontal, 8).padding(.vertical, 14)
                if on {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 17)).foregroundStyle(wa.accent)
                        .padding(6)
                        .transition(.scale.combined(with: .opacity))
                }
            }
            .background(on ? wa.accent.opacity(0.12) : wa.mvCard(onPanel), in: shape)
            .overlay(shape.stroke(on ? wa.accent : Color.clear, lineWidth: 1.5))
            .scaleEffect(on ? 1.04 : 1)
        }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .animation(.spring(response: 0.3, dampingFraction: 0.5), value: on)
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
        let types = options?.vehicleTypes ?? []
        MvScroll(pull: false) {
            StepTitle(title: L("mv_vehicle_title"), hint: L("mv_vehicle_hint"))
            MissingNote(draft: draft, key: "vehicle_type_id")
            if options != nil && types.isEmpty { MvNote(text: L("mv_no_vehicle_types")) }
            ForEach(Array(types.enumerated()), id: \.element.id) { index, type in
                VehicleCard(type: type, order: draft.vehicleTypeIds.firstIndex(of: type.id), picked: draft.vehicleTypeIds.count) {
                    // Several types may fit (a quarter or a half truck…): each tap adds or removes one, in the order picked.
                    if let i = draft.vehicleTypeIds.firstIndex(of: type.id) { draft.vehicleTypeIds.remove(at: i) } else { draft.vehicleTypeIds.append(type.id) }
                    draft.clearMissing("vehicle_type_id")
                }
                .staggerIn(index + 1)
            }
            MvCard {
                RideStepperLine(icon: "person.3.fill", title: L("mv_workers"), sub: L("mv_workers_hint"), value: draft.workers, range: 0...50) { draft.workers = $0 }
            }
            .staggerIn(types.count + 1)
            Text(L("mv_extras_title")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).staggerIn(types.count + 2)
            VStack(spacing: 10) {
                HStack(spacing: 10) {
                    ExtraTile(icon: "shippingbox.fill", title: L("mv_extra_packing"), hint: L("mv_extra_packing_hint"), on: draft.packing) { draft.packing.toggle() }
                    ExtraTile(icon: "wrench.and.screwdriver.fill", title: L("mv_extra_disassembly"), hint: L("mv_extra_disassembly_hint"), on: draft.disassembly) { draft.disassembly.toggle() }
                }
                .fixedSize(horizontal: false, vertical: true)
                HStack(spacing: 10) {
                    ExtraTile(icon: "hammer.fill", title: L("mv_extra_crane"), hint: L("mv_extra_crane_hint"), on: draft.crane) { draft.crane.toggle() }
                    ExtraTile(icon: "wrench.adjustable.fill", title: L("mv_extra_technician"), hint: L("mv_extra_technician_hint"), on: draft.technician) { draft.technician.toggle() }
                }
                .fixedSize(horizontal: false, vertical: true)
            }
            .staggerIn(types.count + 3)
        }
    }
}

/// A vehicle size as a card: its picture, name and description, and a round tick when chosen.
private struct VehicleCard: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let type: MovingVehicleTypeDto
    /// Its place in the customer's order (0 = first choice); nil when not picked.
    let order: Int?
    /// How many types are picked: with more than one, the box shows the number.
    let picked: Int
    let action: () -> Void

    private var on: Bool { order != nil }

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 20, style: .continuous)
        Button(action: action) {
            HStack(spacing: 12) {
                ZStack {
                    if let url = ApiClient.mediaUrl(type.image) {
                        AsyncImage(url: url) { $0.resizable().scaledToFit() } placeholder: { Color.clear }.padding(4)
                    } else {
                        Image(systemName: "box.truck.fill").font(.system(size: 30)).foregroundStyle(wa.accent)
                    }
                }
                .frame(width: 96, height: 68)
                .background(wa.accent.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: type.name ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                    if let d = type.description, !d.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(verbatim: d).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(3)
                    }
                    if order == 0 && picked > 1 {
                        Text(L("mv_first_choice")).font(.dorr(11, .bold)).foregroundStyle(wa.accent)
                    }
                }
                Spacer(minLength: 0)
                // Picked: its place in the customer's order (1 = first choice); not picked: an empty box.
                ZStack {
                    RoundedRectangle(cornerRadius: 8, style: .continuous).fill(on ? wa.accent : Color.clear)
                    RoundedRectangle(cornerRadius: 8, style: .continuous).stroke(on ? wa.accent : wa.settingsMut.opacity(0.4), lineWidth: 1.5)
                    if let order {
                        if picked > 1 {
                            Text(verbatim: "\(order + 1)").font(.dorr(13, .black)).foregroundStyle(.white)
                        } else {
                            Image(systemName: "checkmark").font(.system(size: 11, weight: .heavy)).foregroundStyle(.white)
                        }
                    }
                }
                .frame(width: 26, height: 26)
            }
            .padding(12)
            .background(on ? wa.accent.opacity(0.10) : wa.mvCard(onPanel), in: shape)
            .overlay(shape.stroke(on ? wa.accent : Color.clear, lineWidth: 1.5))
            .scaleEffect(on ? 1.02 : 1)
        }
        .buttonStyle(.plain)
        .animation(.spring(response: 0.3, dampingFraction: 0.5), value: on)
    }
}

/// An extra service as a selectable tile: icon, name, a short line; a tick in the corner when chosen.
private struct ExtraTile: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let icon: String
    let title: String
    let hint: String
    let on: Bool
    let action: () -> Void

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 18, style: .continuous)
        Button(action: action) {
            ZStack(alignment: .topTrailing) {
                VStack(alignment: .leading, spacing: 0) {
                    Image(systemName: icon).font(.system(size: 19)).foregroundStyle(on ? Color.white : wa.accent)
                        .frame(width: 40, height: 40)
                        .background(on ? wa.accent : wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    Text(verbatim: title).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 8)
                    Text(verbatim: hint).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                    Spacer(minLength: 0)
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
                .padding(12)
                if on {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 18)).foregroundStyle(wa.accent)
                        .padding(8)
                        .transition(.scale.combined(with: .opacity))
                }
            }
            .background(on ? wa.accent.opacity(0.12) : wa.mvCard(onPanel), in: shape)
            .overlay(shape.stroke(on ? wa.accent : Color.clear, lineWidth: 1.5))
        }
        .buttonStyle(WaPressStyle())
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .animation(.easeInOut(duration: 0.2), value: on)
    }
}

// MARK: - 4. The time and the notes

private struct TimeStep: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    @ObservedObject var draft: MovingDraft
    let onTime: () -> Void

    var body: some View {
        let at = draft.scheduledAt
        let tint = draft.missing.contains("scheduled_at") ? AppColors.danger : wa.accent
        let shape = RoundedRectangle(cornerRadius: 20, style: .continuous)
        MvScroll(pull: false) {
            StepTitle(title: L("mv_time_title"), hint: L("mv_time_hint"))
            Button(action: onTime) {
                HStack(spacing: 12) {
                    Image(systemName: "calendar").font(.system(size: 22)).foregroundStyle(tint)
                        .frame(width: 52, height: 52)
                        .background(tint.opacity(0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("mv_when")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        Text(verbatim: at.map { rideWhenText($0) } ?? L("mv_pick_time")).font(.dorr(17, .heavy))
                            .foregroundStyle(at == nil ? tint : wa.settingsInk)
                    }
                    Spacer(minLength: 0)
                    Image(systemName: at == nil ? "chevron.forward" : "pencil").font(.system(size: 16, weight: .semibold)).foregroundStyle(tint)
                }
                .padding(16)
                .background(wa.mvCard(onPanel), in: shape)
                .overlay(shape.stroke(at == nil ? tint.opacity(0.35) : Color.clear, lineWidth: 1.5))
                .contentShape(Rectangle())
            }
            .buttonStyle(WaPressStyle())
            .staggerIn(1)
            MissingNote(draft: draft, key: "scheduled_at")
            if let at, at <= Date() {
                Text(L("mv_time_passed")).font(.dorr(12.5, .semibold)).foregroundStyle(AppColors.danger)
            }
            MvCard {
                DorrTextArea(value: limited({ draft.notes }, { draft.notes = $0 }, 2000), label: L("mv_notes"), minHeight: 70)
            }
            .staggerIn(2)
        }
    }
}

// MARK: - 5. The review

private struct ReviewStep: View {
    @Environment(\.wa) private var wa
    @ObservedObject var draft: MovingDraft
    let options: MovingOptionsDto?
    @ObservedObject var route: MovingRouteState
    let onEdit: (Int) -> Void

    var body: some View {
        let from = draft.pickup.point?.latLng
        let to = draft.dropoff.point?.latLng
        MvScroll(pull: false) {
            StepTitle(title: L("mv_review_title"), hint: L("mv_review_hint"))
            ReviewCard(title: L("mv_step_places"), onEdit: { onEdit(stepPlaces) }) {
                MovingRouteMap(pickup: from, dropoff: to, route: route.route, onTap: { onEdit(stepPlaces) })
                    .frame(maxWidth: .infinity)
                    .frame(height: 160)
                MovingRouteChips(state: route, bothEnds: from != nil && to != nil,
                                 fallbackKm: from.flatMap { a in to.map { straightKm(a, $0) } }, top: 10, bottom: 4)
                ReviewEnd(title: L("mv_pickup"), end: draft.pickup, dot: AppColors.brandNavy)
                MissingNote(draft: draft, key: "pickup")
                ReviewEnd(title: L("mv_dropoff"), end: draft.dropoff, dot: AppColors.brandOrange)
                MissingNote(draft: draft, key: "dropoff")
            }
            .staggerIn(1)
            ReviewCard(title: L("mv_step_load"), onEdit: { onEdit(stepLoad) }) {
                Text(verbatim: draft.loadType.map { loadTypeLabel($0) } ?? "—").font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
                MissingNote(draft: draft, key: "load_type")
                let text = draft.description.trimmingCharacters(in: .whitespacesAndNewlines)
                if !text.isEmpty { Text(verbatim: text).font(.dorr(14)).foregroundStyle(wa.settingsInk).padding(.top, 4) }
                if !draft.pieces.isEmpty { MvLine(label: L("mv_pieces"), value: draft.pieces) }
                if !draft.dimensions.trimmingCharacters(in: .whitespaces).isEmpty { MvLine(label: L("mv_dimensions"), value: draft.dimensions) }
                MvLine(label: L("mv_media_title"), value: L("mv_media_counts", draft.photos.count, draft.videos.count))
                if let voice = draft.voice { MvLine(label: L("mv_voice_title"), value: voiceTime(voice.durationMs ?? 0)) }
                MissingNote(draft: draft, key: "description_or_photo")
            }
            .staggerIn(2)
            ReviewCard(title: L("mv_step_vehicle"), onEdit: { onEdit(stepVehicle) }) {
                let names = draft.vehicleTypeIds.compactMap { id in options?.vehicleTypes.first { $0.id == id }?.name }
                MvLine(label: L("mv_vehicle_title"), value: names.isEmpty ? "—" : names.joined(separator: " / "))
                MvLine(label: L("mv_workers"), value: String(draft.workers))
                let extras = [
                    draft.packing ? "packing" : nil,
                    draft.disassembly ? "disassembly" : nil,
                    draft.crane ? "crane" : nil,
                    draft.technician ? "technician" : nil,
                ].compactMap { $0 }
                if !extras.isEmpty {
                    FlowLayout(spacing: 6, lineSpacing: 6) {
                        ForEach(extras, id: \.self) { MvChip(text: extraLabel($0), color: wa.accent) }
                    }
                    .padding(.top, 4)
                }
                MissingNote(draft: draft, key: "vehicle_type_id")
            }
            .staggerIn(3)
            ReviewCard(title: L("mv_step_time"), onEdit: { onEdit(stepTime) }) {
                MvLine(label: L("mv_when"), value: draft.scheduledAt.map { rideWhenText($0) } ?? "—")
                MissingNote(draft: draft, key: "scheduled_at")
                let notes = draft.notes.trimmingCharacters(in: .whitespacesAndNewlines)
                if !notes.isEmpty { Text(verbatim: notes).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.top, 2) }
            }
            .staggerIn(4)
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
            VStack(alignment: .leading, spacing: 2) { content() }.padding(.top, 8)
        }
    }
}

private struct ReviewEnd: View {
    @Environment(\.wa) private var wa
    let title: String
    let end: MovingEnd
    let dot: Color

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            Circle().fill(dot).frame(width: 9, height: 9).padding(.top, 5)
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: title).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                Text(verbatim: end.shown ?? "—").font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
                let level = Int(end.floor.trimmingCharacters(in: .whitespaces))
                let details = [
                    end.area.trimmingCharacters(in: .whitespaces).isEmpty ? nil : end.area,
                    level.map { $0 == 0 ? L("mv_floor_ground") : L("mv_floor_value", String($0)) },
                    end.point == nil ? nil : L(end.elevator ? "mv_has_elevator" : "mv_no_elevator"),
                    end.entrance.trimmingCharacters(in: .whitespaces).isEmpty ? nil : end.entrance,
                ].compactMap { $0 }
                if !details.isEmpty { Text(verbatim: details.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            }
            Spacer(minLength: 0)
        }
        .padding(.vertical, 4)
    }
}
