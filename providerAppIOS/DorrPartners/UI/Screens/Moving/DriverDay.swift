// providerApp `ui/screens/DriverDay.kt`
import AudioToolbox
import AVFoundation
import PhotosUI
import SwiftUI
import UIKit

// The driver's moving day (C-05), on the job's page when the job is theirs: where it stands, ONE main action for the next step, the way
// to the place, the numbered pieces and the delivery. Every refusal of the server is shown word for word and stays on the page.

/// The next step of a status: nil for `step` is the delivery, which has a page of its own.
private struct NextStep {
    let step: String?
    let label: String
    let hint: String
}

private func nextStepOf(_ status: String?) -> NextStep? {
    switch status {
    case "assigned": return NextStep(step: "set_out", label: "day_set_out", hint: "day_hint_assigned")
    case "driver_on_way": return NextStep(step: "arrived", label: "day_arrived", hint: "day_hint_on_way")
    case "arrived": return NextStep(step: "loading", label: "day_loading", hint: "day_hint_arrived")
    case "loading": return NextStep(step: "departed", label: "day_departed", hint: "day_hint_loading")
    case "in_transit": return NextStep(step: "unloading", label: "day_unloading", hint: "day_hint_in_transit")
    case "unloading": return NextStep(step: nil, label: "day_deliver", hint: "day_hint_unloading")
    default: return nil
    }
}

/// The phone's position now, at most ten seconds' wait; nil without the permission or a fix.
@MainActor
private func positionOrNull() async -> (lat: Double, lng: Double)? {
    guard hasLocationPermission(), let here = await CurrentPosition.shared.fetch(seconds: 10) else { return nil }
    return (here.latitude, here.longitude)
}

/// Turn-by-turn in Google Maps, else Apple Maps, else the browser (driverApp's order, with the phone's own map second).
@MainActor
private func openNavigation(_ end: MovingEndDto) {
    let raw: String
    if let lat = end.lat, let lng = end.lng { raw = "\(lat),\(lng)" } else { raw = end.address ?? "" }
    guard !raw.isEmpty, let target = raw.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) else { return }
    let candidates = [
        "comgooglemaps://?daddr=\(target)&directionsmode=driving",
        "maps://?daddr=\(target)&dirflg=d",
        "https://www.google.com/maps/dir/?api=1&destination=\(target)",
    ]
    Task {
        for link in candidates {
            if let url = URL(string: link), await UIApplication.shared.open(url) { return }
        }
    }
}

/// The top of the job's page for its driver: a big status, the one action that comes next, the way to the place and the pieces. A job
/// not accepted yet shows accept / decline first. The location tracking follows the job's status from here (it starts on set_out).
@MainActor
struct DriverDayPanel: View {
    @Environment(\.wa) private var wa
    let move: MovingRequestDto
    let onReload: () async -> Void
    let onOpenInventory: () -> Void
    let onOpenDeliver: () -> Void
    let onDeclined: () -> Void

    @State private var busy = false
    @State private var error: String?
    @State private var declining = false
    @State private var askNote = false
    @State private var note = ""
    @State private var permissionTick = 0

    private var status: String? { move.status }
    private var waitingForAnswer: Bool { status == "assigned" && move.driverAccepted != true }
    // The way there: to the pickup until the truck is loading, then to the drop-off.
    private var toDropoff: Bool { status == "loading" || status == "in_transit" || status == "unloading" }

    private var hintKey: String? {
        if waitingForAnswer { return "day_hint_answer" }
        if let next = nextStepOf(status) { return next.hint }
        if status == "delivered" || status == "completed" { return "day_hint_done" }
        return nil
    }

    var body: some View {
        let next = nextStepOf(status)
        let end = toDropoff ? move.dropoff : move.pickup
        VStack(spacing: 14) {
            Card {
                Text(verbatim: moveStatusLabel(status)).font(.dorr(24, .heavy)).foregroundStyle(moveStatusColor(status))
                Text(verbatim: routeText(move)).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                if let hintKey { Text(L(hintKey)).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4) }
                mainAction(next)
                InlineErrorBanner(message: error)
            }

            // Running without the location: the customer can't follow the truck. Said plainly, with the way to fix it.
            if MoveTracking.tracked.contains(status ?? "") && !hasLocationPermission() {
                Button(action: fixPermission) {
                    HStack(alignment: .top, spacing: 8) {
                        Image(systemName: "exclamationmark.triangle.fill").font(.system(size: 16, weight: .semibold)).foregroundStyle(Amber).frame(width: 20)
                        Text(L("day_location_off")).font(.dorr(12)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .padding(12)
                    .background(Amber.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }

            if next != nil && !waitingForAnswer, let end, end.lat != nil || notBlank(end.address) != nil {
                RowCard(icon: "location.north.line.fill", title: L(toDropoff ? "day_nav_dropoff" : "day_nav_pickup"), subtitle: end.address ?? end.area,
                        onClick: { openNavigation(end) })
            }
            // The pieces: while they are numbered, and again at the delivery.
            if status == "arrived" || status == "loading" || status == "unloading" {
                RowCard(icon: "shippingbox.fill", title: L("day_inventory"), subtitle: L(status == "unloading" ? "day_inventory_unloading_sub" : "day_inventory_sub"),
                        onClick: onOpenInventory)
            }
        }
        // On for the running states, off for the rest; asked again when the permission changes.
        .task(id: "\(move.uuid)-\(status ?? "")-\(permissionTick)") { MoveTracking.follow(move.uuid, status: status) }
        // Back from the phone's settings: the permission may have changed.
        .onReceive(NotificationCenter.default.publisher(for: UIApplication.didBecomeActiveNotification)) { _ in permissionTick += 1 }
        .dorrDialog(isPresented: $declining) {
            ConfirmDialog(icon: "box.truck.fill", title: L("mv_decline_job"), message: L("mv_decline_job_message"), loading: busy,
                          confirmLabel: L("mv_decline_job"), onConfirm: decline, onDismiss: { if !busy { declining = false } })
        }
    }

    @ViewBuilder private func mainAction(_ next: NextStep?) -> some View {
        if waitingForAnswer {
            SettingsPrimaryButton(text: L("mv_accept_job"), loading: busy, action: accept)
            Button { declining = true } label: {
                Text(L("mv_decline_job")).font(.dorr(14, .bold)).foregroundStyle(Red)
            }
            .buttonStyle(.plain)
            .disabled(busy)
        } else if askNote {
            // No location at the door: the note is the other proof.
            Text(L("day_no_location")).font(.dorr(13, .semibold)).foregroundStyle(Amber).lineSpacing(4)
            DorrTextField(value: $note.profileTake(500), label: L("day_note"), icon: "text.alignleft", singleLine: false, minLines: 2)
            SettingsPrimaryButton(text: L("day_arrived"), enabled: !note.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty, loading: busy) {
                send("arrived", withNote: note.trimmingCharacters(in: .whitespacesAndNewlines))
            }
            ProfileTextLink(text: L("day_try_location"), enabled: !busy, action: arrive)
        } else if let next {
            SettingsPrimaryButton(text: L(next.label), loading: busy) { tap(next) }
        }
    }

    private func tap(_ next: NextStep) {
        guard let step = next.step else {
            onOpenDeliver()
            return
        }
        if (step == "set_out" || step == "arrived") && !hasLocationPermission() {
            // The system's question first; the step goes on whatever the answer (the page then says what is missing).
            Task {
                _ = await CurrentPosition.shared.askPermission()
                permissionTick += 1
                go(step)
            }
        } else {
            go(step)
        }
    }

    private func go(_ step: String) {
        if step == "arrived" { arrive() } else { send(step) }
    }

    /// iOS asks only once: after a "no" the way back is the phone's settings.
    private func fixPermission() {
        Task {
            if !(await CurrentPosition.shared.askPermission()), let settings = URL(string: UIApplication.openSettingsURLString) {
                await UIApplication.shared.open(settings)
            }
            permissionTick += 1
        }
    }

    private func send(_ step: String, lat: Double? = nil, lng: Double? = nil, withNote: String? = nil) {
        busy = true
        error = nil
        Task {
            let result = await MovingApi.step(move.uuid, StepBody(step: step, lat: lat, lng: lng, note: withNote))
            if result.value != nil {
                askNote = false
                note = ""
                await onReload()
            } else {
                // Word for word: «اقبل المهمة أولًا», «سجّل قطع الجرد بصورها قبل التحرك»…
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
            busy = false
        }
    }

    /// At the door the position is the proof; without one, a short note is (a person looks at it).
    private func arrive() {
        busy = true
        error = nil
        Task {
            if let here = await positionOrNull() {
                send("arrived", lat: here.lat, lng: here.lng)
            } else {
                busy = false
                askNote = true
            }
        }
    }

    private func accept() {
        busy = true
        error = nil
        Task {
            let result = await MovingApi.acceptJob(move.uuid)
            if result.value != nil { await onReload() } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
            busy = false
        }
    }

    private func decline() {
        busy = true
        error = nil
        Task {
            let failed = await MovingApi.declineJob(move.uuid, offline: L("login_error_offline"), generic: L("login_error_generic"))
            busy = false
            declining = false
            if let failed { error = failed } else { onDeclined() }
        }
    }
}

// MARK: - a photo: the camera first

/// The chosen photo (if any) with the two ways to take it, the camera first — the papers' upload row. The photo is scaled and kept as
/// `name`; `onError` says why it couldn't be read.
private struct PhotoRow: View {
    @Environment(\.wa) private var wa
    let title: String
    let name: String
    @Binding var file: FilePart?
    let onError: (String) -> Void

    @State private var galleryItem: PhotosPickerItem?
    @State private var cameraOpen = false

    var body: some View {
        FieldLabel(text: title)
        HStack(spacing: 20) {
            if let file, let image = UIImage(data: file.data) {
                Image(uiImage: image).resizable().scaledToFill()
                    .frame(width: 64, height: 64)
                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            ProfileTextLink(text: L("pd_camera")) { cameraOpen = true }
            PhotosPicker(selection: $galleryItem, matching: .images) {
                Text(L("pd_gallery")).font(.dorr(14, .bold)).foregroundStyle(wa.red)
            }
            .buttonStyle(.plain)
        }
        .onChange(of: galleryItem) { picked in
            guard let picked else { return }
            galleryItem = nil
            Task {
                guard let data = try? await picked.loadTransferable(type: Data.self) else {
                    onError(L("day_photo_type"))
                    return
                }
                take(data)
            }
        }
        .fullScreenCover(isPresented: $cameraOpen) {
            ProfileCameraPicker { data in
                cameraOpen = false
                if let data { take(data) }
            }
            .ignoresSafeArea()
        }
    }

    private func take(_ data: Data) {
        Task {
            // Any picture the phone can read becomes a scaled JPEG (a library photo may be HEIC).
            let prepared = await Task.detached { prepareDocFile(data, mimeType: "image/jpeg", name: name) }.value
            if let prepared { file = prepared } else { onError(L("day_photo_type")) }
        }
    }
}

// MARK: - the pieces

/// The job's numbered pieces (REQ-029, REQ-031). While arrived or loading the driver adds them, each with its photo; while unloading,
/// each gets its after-photo or a reservation. Anyone else who opens the job sees them read-only.
@MainActor
struct InventoryPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let move: MovingRequestDto
    let isDriver: Bool
    let onBack: () -> Void

    @State private var items: [InventoryItemDto]?
    @State private var error: String?
    @State private var deliveringUuid: String?

    private var canAdd: Bool { isDriver && (move.status == "arrived" || move.status == "loading") }
    private var canDeliver: Bool { isDriver && move.status == "unloading" }

    var body: some View {
        ZStack {
            if let delivering = items?.first(where: { $0.uuid == deliveringUuid }) {
                DeliverItemPage(jobUuid: move.uuid, item: delivering, onBack: { deliveringUuid = nil }, onDone: {
                    Task {
                        await reload()
                        deliveringUuid = nil
                    }
                })
            } else {
                Page(title: L("day_inventory"), onBack: onBack) {
                    FormIntro(icon: "shippingbox.fill", title: L("day_inventory"),
                              text: L(canDeliver ? "day_inventory_unloading_body" : (canAdd ? "day_inventory_body" : "day_inventory_read_body")))
                    if canAdd { AddItemCard(jobUuid: move.uuid, onAdded: { Task { await reload() } }) }
                    if let list = items {
                        if list.isEmpty {
                            EmptyState(icon: "shippingbox.fill", title: L("day_inventory_empty_title"), body: L("day_inventory_empty_body")).padding(.top, 12)
                        } else {
                            ForEach(list) { item in
                                ItemCard(item: item, canDeliver: canDeliver) { deliveringUuid = item.uuid }
                            }
                        }
                    } else if error == nil {
                        ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 24)
                    }
                    InlineErrorBanner(message: error)
                }
            }
        }
        .task(id: network.reconnectTick) { await reload() }
    }

    private func reload() async {
        let result = await MovingApi.inventory(move.uuid)
        if let data = result.value {
            items = data
            error = nil
        } else {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }
}

private struct AddItemCard: View {
    @Environment(\.wa) private var wa
    let jobUuid: String
    let onAdded: () -> Void

    @State private var label = ""
    @State private var trip = ""
    @State private var note = ""
    @State private var photo: FilePart?
    @State private var busy = false
    @State private var error: String?

    private var tripValue: Int? { Int(trip) }
    private var tripOk: Bool { trip.isEmpty || (tripValue.map { (1...50).contains($0) } ?? false) }

    var body: some View {
        Card {
            Text(L("day_add_piece")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
            DorrTextField(value: $label.profileTake(150), label: L("day_piece_label"), placeholder: L("day_piece_label_hint"), icon: "shippingbox.fill")
            PhotoRow(title: L("day_piece_photo"), name: "piece_before", file: $photo, onError: { error = $0 })
            DorrTextField(value: $trip.profileDigits(2), label: L("day_piece_trip"), placeholder: L("onb_optional"), icon: "repeat", keyboard: .numberPad, error: !tripOk)
            DorrTextField(value: $note.profileTake(500), label: L("day_piece_note"), placeholder: L("day_piece_note_hint"), icon: "text.alignleft", singleLine: false, minLines: 2)
            SettingsPrimaryButton(text: L("day_add_piece"), enabled: !label.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty && photo != nil && tripOk, loading: busy, action: add)
            InlineErrorBanner(message: error)
        }
    }

    private func add() {
        guard let file = photo else { return }
        busy = true
        error = nil
        var fields = ["label": label.trimmingCharacters(in: .whitespacesAndNewlines)]
        if let tripValue { fields["trip_no"] = String(tripValue) }
        if let text = trimmedOrNil(note) { fields["note"] = text }
        let sent = fields
        Task {
            let result = await MovingApi.addItem(jobUuid, fields: sent, photo: file)
            busy = false
            if result.value != nil {
                label = ""
                note = ""
                photo = nil
                onAdded()
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}

/// One piece: its number, photo before (and after), what it is and what was noted about it.
private struct ItemCard: View {
    @Environment(\.wa) private var wa
    let item: InventoryItemDto
    let canDeliver: Bool
    let onDeliver: () -> Void

    var body: some View {
        Card {
            HStack(alignment: .top, spacing: 10) {
                Thumb(url: item.before)
                VStack(alignment: .leading, spacing: 2) {
                    Text(L("day_piece_line", item.number ?? 0, item.label ?? "")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                    if let trip = item.tripNo, trip > 1 { Text(L("day_piece_trip_line", trip)).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    if let condition = notBlank(item.conditionNote) { Text(verbatim: condition).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2) }
                    if let reservation = notBlank(item.deliveredNote) {
                        Text(L("day_piece_reservation_line", reservation)).font(.dorr(12)).foregroundStyle(Amber).lineSpacing(2)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if item.after != nil { Thumb(url: item.after) }
            }
            if canDeliver {
                ProfileTextLink(text: L(item.after != nil || notBlank(item.deliveredNote) != nil ? "day_piece_update" : "day_piece_deliver"), action: onDeliver)
            }
        }
    }
}

private struct Thumb: View {
    @Environment(\.wa) private var wa
    let url: String?

    var body: some View {
        Group {
            if url != nil {
                MediaThumb(url: url)
            } else {
                Image(systemName: "shippingbox.fill").font(.system(size: 20, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .background(wa.red.opacity(0.10))
            }
        }
        .frame(width: 64, height: 64)
        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
    }
}

/// A piece at the delivery: its after-photo, a reservation, or both (at least one).
private struct DeliverItemPage: View {
    @Environment(\.wa) private var wa
    let jobUuid: String
    let item: InventoryItemDto
    let onBack: () -> Void
    let onDone: () -> Void

    @State private var note: String
    @State private var photo: FilePart?
    @State private var busy = false
    @State private var error: String?

    init(jobUuid: String, item: InventoryItemDto, onBack: @escaping () -> Void, onDone: @escaping () -> Void) {
        self.jobUuid = jobUuid
        self.item = item
        self.onBack = onBack
        self.onDone = onDone
        _note = State(initialValue: item.deliveredNote ?? "")
    }

    var body: some View {
        Page(title: L("day_piece_line", item.number ?? 0, item.label ?? ""), onBack: onBack) {
            Card {
                HStack(spacing: 10) {
                    Thumb(url: item.before)
                    Text(verbatim: notBlank(item.conditionNote) ?? L("day_piece_before")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
            }
            Card {
                PhotoRow(title: L("day_piece_after_photo"), name: "piece_after", file: $photo, onError: { error = $0 })
                DorrTextField(value: $note.profileTake(500), label: L("day_piece_reservation"), placeholder: L("onb_optional"), icon: "text.alignleft", singleLine: false, minLines: 2)
            }
            // The server needs something to record, and an empty form has nothing to send.
            SettingsPrimaryButton(text: L("common_save"), enabled: photo != nil || !note.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty, loading: busy, action: save)
            InlineErrorBanner(message: error)
        }
    }

    private func save() {
        busy = true
        error = nil
        var fields: [String: String] = [:]
        if let text = trimmedOrNil(note) { fields["note"] = text }
        let sent = fields
        let file = photo
        Task {
            let result = await MovingApi.deliverItem(jobUuid, item: item.uuid, fields: sent, photo: file)
            busy = false
            if result.value != nil { onDone() } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}

// MARK: - the delivery

private let codeLength = 4

/// The customer's QR `dorr://moving/{uuid}/{code}` → the code; nil when it is not this job's.
private func codeFromQr(_ contents: String, jobUuid: String) -> String? {
    guard let uri = URLComponents(string: contents), uri.scheme == "dorr", uri.host == "moving" else { return nil }
    let parts = uri.path.split(separator: "/").map(String.init)
    guard parts.count == 2, parts[0] == jobUuid, !parts[1].isEmpty, parts[1].allSatisfy({ $0.isASCII && $0.isNumber }) else { return nil }
    return parts[1]
}

/// Handing over the load: the customer's 4-digit code (typed in the OTP boxes or read from their QR) with who received it; or, without
/// the code, why (refused / absent) and a required note — recorded as not confirmed by the customer, and said so before the tap.
@MainActor
struct DeliverPage: View {
    @Environment(\.wa) private var wa
    let move: MovingRequestDto
    let onBack: () -> Void
    let onDone: (String) -> Void

    @State private var withCode = true
    @State private var code = ""
    @FocusState private var codeFocused: Bool
    @State private var recipient = ""
    @State private var reason: String?
    @State private var note = ""
    @State private var scanning = false
    @State private var confirming = false
    @State private var busy = false
    @State private var error: String?

    private var ready: Bool {
        withCode ? code.count == codeLength : (reason != nil && !note.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
    }

    var body: some View {
        Page(title: L("day_deliver"), onBack: onBack) {
            FormIntro(icon: "checkmark.seal.fill", title: L("day_deliver"), text: L("day_deliver_body"))
            ChipRow(
                options: [(value: "code", label: L("day_with_code")), (value: "without", label: L("day_without_code"))],
                selected: [withCode ? "code" : "without"],
                onToggle: {
                    withCode = $0 == "code"
                    error = nil
                }
            )
            .frame(maxWidth: .infinity, alignment: .leading)
            if withCode {
                Card {
                    FieldLabel(text: L("day_code"))
                    codeBoxes
                    Button { scanning = true } label: {
                        HStack(spacing: 6) {
                            Image(systemName: "qrcode.viewfinder").font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.red)
                            Text(L("day_scan")).font(.dorr(14, .bold)).foregroundStyle(wa.red)
                        }
                    }
                    .buttonStyle(.plain)
                }
            } else {
                Card {
                    FieldLabel(text: L("day_without_reason"))
                    PickRow(selected: reason == "refused", title: L("day_reason_refused"), subtitle: nil) { reason = "refused" }
                    PickRow(selected: reason == "absent", title: L("day_reason_absent"), subtitle: nil) { reason = "absent" }
                    DorrTextField(value: $note.profileTake(1000), label: L("day_what_happened"), icon: "text.alignleft", singleLine: false, minLines: 3)
                }
                HStack(alignment: .top, spacing: 8) {
                    Image(systemName: "exclamationmark.triangle.fill").font(.system(size: 16, weight: .semibold)).foregroundStyle(Amber).frame(width: 20)
                    Text(L("day_without_warning")).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(12)
                .background(Amber.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            DorrTextField(value: $recipient.profileTake(120), label: L("day_recipient"), placeholder: L("onb_optional"), icon: "person.fill")
            SettingsPrimaryButton(text: L("day_deliver"), enabled: ready, loading: busy) {
                if withCode { send() } else { confirming = true }
            }
            InlineErrorBanner(message: error)
        }
        .fullScreenCover(isPresented: $scanning) {
            QrScanPage(prompt: L("day_scan_prompt"), onClose: { scanning = false }, onCode: { contents in
                scanning = false
                scanned(contents)
            })
        }
        .dorrDialog(isPresented: $confirming) {
            ConfirmDialog(icon: "exclamationmark.triangle.fill", title: L("day_without_code"), message: L("day_without_warning"), loading: busy,
                          confirmLabel: L("common_yes"), onConfirm: send, onDismiss: { if !busy { confirming = false } })
        }
    }

    /// The four boxes over one hidden field, as on the code page. The digits are always left-to-right, even in Arabic.
    private var codeBoxes: some View {
        ZStack {
            TextField("", text: Binding(get: { code }, set: { typed in
                code = String(typed.filter { $0.isASCII && $0.isNumber }.prefix(codeLength))
            }))
            .keyboardType(.numberPad)
            .focused($codeFocused)
            .opacity(0.02)
            .frame(width: 1, height: 1)
            HStack(spacing: 8) {
                ForEach(0..<codeLength, id: \.self) { i in
                    let chars = Array(code)
                    let isFocusedBox = codeFocused && (i == min(code.count, codeLength - 1))
                    Text(verbatim: i < chars.count ? String(chars[i]) : "")
                        .font(.dorr(22, .semibold))
                        .foregroundStyle(wa.night ? wa.ink : Color(hex: 0x111928))
                        .frame(width: 44, height: 52)
                        .background(wa.night ? wa.darkCard : .white, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(isFocusedBox ? wa.red : (wa.night ? wa.line : Color(hex: 0xFBD2C4)), lineWidth: 1.5))
                }
            }
            .frame(maxWidth: .infinity)
            .contentShape(Rectangle())
            .onTapGesture { codeFocused = true }
        }
        .environment(\.layoutDirection, .leftToRight)
    }

    private func scanned(_ contents: String) {
        if let read = codeFromQr(contents, jobUuid: move.uuid), read.count == codeLength {
            error = nil
            code = read
        } else {
            error = L("day_qr_wrong")
        }
    }

    private func send() {
        busy = true
        error = nil
        let sentCode = withCode
        Task {
            let here = await positionOrNull()
            let person = trimmedOrNil(recipient)
            let body = sentCode
                ? DeliverBody(code: code, recipientName: person, lat: here?.lat, lng: here?.lng)
                : DeliverBody(recipientName: person, withoutCode: reason, note: note.trimmingCharacters(in: .whitespacesAndNewlines), lat: here?.lat, lng: here?.lng)
            let result = await MovingApi.deliver(move.uuid, body)
            busy = false
            confirming = false
            if result.value != nil {
                // The job left the running states: no more positions.
                MoveTracking.stop()
                onDone(L(sentCode ? "day_delivered_ok" : "day_delivered_unconfirmed"))
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}

// MARK: - the QR scanner

/// The camera over the whole screen with the prompt under it and a way out (Android: zxing's capture screen), for `dorr://moving/{uuid}/{code}`.
private struct QrScanPage: View {
    let prompt: String
    let onClose: () -> Void
    let onCode: (String) -> Void

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()
            QrCameraView(onCode: onCode).ignoresSafeArea()
            VStack {
                HStack {
                    Spacer()
                    Button(action: onClose) {
                        Image(systemName: "xmark").font(.system(size: 14, weight: .bold)).foregroundStyle(.white)
                            .frame(width: 34, height: 34)
                            .background(.black.opacity(0.55), in: Circle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("common_close"))
                }
                .padding(16)
                Spacer()
                Text(verbatim: prompt).font(.dorr(14, .semibold)).foregroundStyle(.white).multilineTextAlignment(.center)
                    .padding(.horizontal, 16)
                    .padding(.vertical, 10)
                    .background(.black.opacity(0.55), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .padding(24)
            }
        }
    }
}

/// `AVCaptureMetadataOutput` reading QR codes; the camera permission is asked when it opens. `onCode` gets the first code read, once.
private struct QrCameraView: UIViewControllerRepresentable {
    let onCode: (String) -> Void

    func makeCoordinator() -> Coordinator { Coordinator(onCode: onCode) }

    func makeUIViewController(context: Context) -> QrCameraController {
        let controller = QrCameraController()
        controller.metadataDelegate = context.coordinator
        return controller
    }

    func updateUIViewController(_ controller: QrCameraController, context: Context) {}

    final class Coordinator: NSObject, AVCaptureMetadataOutputObjectsDelegate {
        private let onCode: (String) -> Void
        private var read = false

        init(onCode: @escaping (String) -> Void) { self.onCode = onCode }

        func metadataOutput(_ output: AVCaptureMetadataOutput, didOutput metadataObjects: [AVMetadataObject], from connection: AVCaptureConnection) {
            guard !read, let code = metadataObjects.compactMap({ $0 as? AVMetadataMachineReadableCodeObject }).first?.stringValue else { return }
            read = true
            AudioServicesPlaySystemSound(1057)
            onCode(code)
        }
    }
}

private final class QrCameraController: UIViewController {
    weak var metadataDelegate: AVCaptureMetadataOutputObjectsDelegate?
    private let session = AVCaptureSession()
    private var preview: AVCaptureVideoPreviewLayer?

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = .black
        AVCaptureDevice.requestAccess(for: .video) { [weak self] granted in
            guard granted else { return }
            Task { @MainActor in self?.configure() }
        }
    }

    private func configure() {
        guard let device = AVCaptureDevice.default(for: .video), let input = try? AVCaptureDeviceInput(device: device), session.canAddInput(input) else { return }
        session.addInput(input)
        let output = AVCaptureMetadataOutput()
        guard session.canAddOutput(output) else { return }
        session.addOutput(output)
        output.setMetadataObjectsDelegate(metadataDelegate, queue: .main)
        output.metadataObjectTypes = [.qr]
        let layer = AVCaptureVideoPreviewLayer(session: session)
        layer.videoGravity = .resizeAspectFill
        layer.frame = view.bounds
        view.layer.addSublayer(layer)
        preview = layer
        let running = session
        DispatchQueue.global(qos: .userInitiated).async { running.startRunning() }
    }

    override func viewDidLayoutSubviews() {
        super.viewDidLayoutSubviews()
        preview?.frame = view.bounds
    }

    override func viewWillDisappear(_ animated: Bool) {
        super.viewWillDisappear(animated)
        let running = session
        DispatchQueue.global(qos: .userInitiated).async { running.stopRunning() }
    }
}
