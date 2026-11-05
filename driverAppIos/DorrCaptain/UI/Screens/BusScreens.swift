import AudioToolbox
import AVFoundation
import CoreImage
import MapKit
import SwiftUI

// driverApp `ui/BusScreens.kt`.
//
// The captain's buses (docs/rides_tasks.md O-20, O-21, O-24): his bus and a QR label for each seat; his
// trips — from, to, leaving and coming back, a price going, coming back and both; and on a trip, the seat
// map, who booked (call or message them), boarding by the ticket's QR or the list, leaving and ending.
// New bookings arrive as notifications (`bus.booked`) — the open trip reloads on them; nothing polls.

struct BusTripsScreen: View {
    @Environment(\.pal) private var pal
    let onBack: () -> Void
    let onOpen: (String) -> Void

    @State private var bus: BusVehicleDto?
    @State private var trips: [DriverBusTripDto]?
    @State private var error: String?
    @State private var formOpen = false
    @State private var labelsOpen = false
    @State private var tick = 0

    var body: some View {
        ZStack {
            VStack(spacing: 0) {
                ScreenHeader(L("bus_my_trips"), onBack: onBack)
                ScrollView {
                    VStack(alignment: .leading, spacing: 12) {
                        Banner(error)
                        if let b = bus {
                            HStack(spacing: 0) {
                                TileIcon("bus.fill", tint: pal.accent, box: 46)
                                HSpace(12)
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(b.name ?? "—").font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                                    Text([b.plate, L("bus_seats_n", b.seats ?? 0)].compactMap { $0 }.joined(separator: " · "))
                                        .font(.dorr(12)).foregroundStyle(pal.mut)
                                }
                                .frame(maxWidth: .infinity, alignment: .leading)
                                Chip(L("bus_seat_labels"), onClick: { labelsOpen = true })
                            }
                            .padding(14)
                            .frame(maxWidth: .infinity)
                            .surface()
                            .staggerIn(0)
                            PrimaryButton(L("bus_new_trip"), icon: "plus") { formOpen = true }
                        }
                        if let list = trips {
                            if list.isEmpty {
                                Text(L("bus_no_trips")).font(.dorr(14)).foregroundStyle(pal.mut).padding(20)
                            } else {
                                ForEach(Array(list.enumerated()), id: \.offset) { i, t in tripRow(t).staggerIn(i + 1) }
                            }
                        }
                    }
                    .padding(16)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
            .background(pal.bg.ignoresSafeArea())

            if formOpen, let b = bus {
                BusTripForm(bus: b, onClose: { formOpen = false }, onDone: { formOpen = false; tick += 1 })
            }
            if labelsOpen, let b = bus {
                SeatLabels(bus: b, onClose: { labelsOpen = false })
            }
        }
        .task(id: tick) {
            switch await DriverBusApi.vehicle() {
            case .ok(let v, _): bus = v
            case .failed(let e): error = e.displayMessage
            default: break
            }
            if let v = await DriverBusApi.trips().value { trips = v }
        }
        .onReceive(DriverLive.shared.events.receive(on: DispatchQueue.main)) { e in if e.name.hasPrefix("bus.") { tick += 1 } }
    }

    private func tripRow(_ t: DriverBusTripDto) -> some View {
        let total = t.seatsTotal ?? 0
        let back = t.returnAt.map { " · " + L("bus_back_at", busDay($0) + " " + busTime($0)) } ?? ""
        return VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                Text(busTime(t.departAt)).font(.dorr(18, .black)).foregroundStyle(pal.ink)
                HSpace(8)
                Text(busDay(t.departAt)).font(.dorr(12)).foregroundStyle(pal.mut).frame(maxWidth: .infinity, alignment: .leading)
                BusStatusChip(status: t.status ?? "scheduled")
            }
            Text((t.origin?.address ?? "") + "  ←  " + (t.destination?.address ?? ""))
                .font(.dorr(13)).foregroundStyle(pal.ink).lineLimit(2).truncationMode(.tail).padding(.top, 6)
            Text(L("bus_booked_of", total - (t.free?.outbound ?? 0), total) + back)
                .font(.dorr(12)).foregroundStyle(pal.mut).padding(.top, 4)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface()
        .contentShape(Rectangle())
        .onTapGesture { if let uuid = t.uuid { onOpen(uuid) } }
    }
}

/// `StatusChip`.
private struct BusStatusChip: View {
    @Environment(\.pal) private var pal
    let status: String

    var body: some View {
        let (label, color): (String, Color) = {
            switch status {
            case "departed": return (L("bus_st_departed"), pal.success)
            case "returning": return (L("bus_st_returning"), pal.orange)
            case "completed": return (L("bus_st_completed"), pal.mut)
            case "canceled": return (L("bus_st_canceled"), pal.danger)
            default: return (L("bus_st_scheduled"), pal.accent)
            }
        }()
        Text(label).font(.dorr(11, .bold)).foregroundStyle(color)
            .padding(.horizontal, 10).padding(.vertical, 4)
            .background(color.opacity(0.12), in: Capsule())
    }
}

// MARK: - publish a trip

/// A time of day picked on the phone (`java.time.LocalTime`, hours and minutes).
struct PickedTime: Equatable {
    let hour: Int
    let minute: Int

    /// `LocalTime.toString()`: «14:05».
    func toString() -> String { String(format: "%02d:%02d", hour, minute) }

    static func now() -> PickedTime {
        let c = Calendar.current.dateComponents([.hour, .minute], from: Date())
        return PickedTime(hour: c.hour ?? 0, minute: c.minute ?? 0)
    }
}

/// `ZonedDateTime.of(day, time, ZoneId.systemDefault()).toOffsetDateTime().toString()` — «2026-11-03T14:05+02:00».
func busAt(_ day: LocalDate?, _ time: PickedTime?) -> String? {
    guard let day, let time else { return nil }
    var c = DateComponents()
    c.year = day.year; c.month = day.month; c.day = day.day; c.hour = time.hour; c.minute = time.minute
    var cal = Calendar(identifier: .gregorian)
    cal.timeZone = .current
    guard let date = cal.date(from: c) else { return nil }
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.calendar = cal
    f.timeZone = .current
    f.dateFormat = "yyyy-MM-dd'T'HH:mmXXX"
    return f.string(from: date)
}

/// `text.replace(',', '.').toBigDecimalOrNull()?.movePointRight(2)?.toLong()` — the amount in minor units, or nil.
func busMinor(_ text: String) -> Int? {
    let t = text.replacingOccurrences(of: ",", with: ".")
    guard t.range(of: #"^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$"#, options: .regularExpression) != nil,
          let d = Decimal(string: t, locale: Locale(identifier: "en_US_POSIX")) else { return nil }
    var shifted = d * 100
    var whole = Decimal()
    NSDecimalRound(&whole, &shifted, 0, d < 0 ? .up : .down)
    return NSDecimalNumber(decimal: whole).intValue
}

private struct BusTripForm: View {
    @Environment(\.pal) private var pal
    let bus: BusVehicleDto
    let onClose: () -> Void
    let onDone: () -> Void

    @State private var origin: PlaceDto?
    @State private var destination: PlaceDto?
    @State private var picking: String?
    @State private var departDay: LocalDate?
    @State private var departTime: PickedTime?
    @State private var withReturn = false
    @State private var returnDay: LocalDate?
    @State private var returnTime: PickedTime?
    @State private var priceOut = ""
    @State private var priceBack = ""
    @State private var priceRound = ""
    @State private var seats: String
    @State private var note = ""
    @State private var dateFor: String?
    @State private var timeFor: String?
    @State private var busy = false
    @State private var error: String?

    init(bus: BusVehicleDto, onClose: @escaping () -> Void, onDone: @escaping () -> Void) {
        self.bus = bus
        self.onClose = onClose
        self.onDone = onDone
        _seats = State(initialValue: String(bus.seats ?? 0))
    }

    var body: some View {
        ZStack {
            VStack(spacing: 0) {
                ScreenHeader(L("bus_new_trip"), onBack: onClose)
                ScrollView {
                    VStack(alignment: .leading, spacing: 12) {
                        VStack(spacing: 0) {
                            ListRow(icon: "mappin.circle.fill", title: origin?.address ?? L("bus_pick_from"), subtitle: L("bus_from"), tint: pal.success, onClick: { picking = "from" })
                            ListRow(icon: "mappin.circle.fill", title: destination?.address ?? L("bus_pick_to"), subtitle: L("bus_to"), tint: pal.orange, onClick: { picking = "to" })
                        }
                        .frame(maxWidth: .infinity)
                        .surface()
                        Text(L("bus_departure")).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                        HStack(spacing: 8) {
                            OutlineButton(departDay?.toString() ?? L("bus_day"), fillMaxWidth: true) { dateFor = "depart" }.frame(maxWidth: .infinity)
                            OutlineButton(departTime?.toString() ?? L("bus_time"), fillMaxWidth: true) { timeFor = "depart" }.frame(maxWidth: .infinity)
                        }
                        HStack(spacing: 0) {
                            Text(L("bus_with_return")).font(.dorr(14, .bold)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                            DorrToggle(on: withReturn) { withReturn = $0 }
                        }
                        .padding(.vertical, 6)
                        .contentShape(RoundedRectangle(cornerRadius: 14))
                        .onTapGesture { withReturn.toggle() }
                        if withReturn {
                            HStack(spacing: 8) {
                                OutlineButton(returnDay?.toString() ?? L("bus_day"), fillMaxWidth: true) { dateFor = "return" }.frame(maxWidth: .infinity)
                                OutlineButton(returnTime?.toString() ?? L("bus_time"), fillMaxWidth: true) { timeFor = "return" }.frame(maxWidth: .infinity)
                            }
                        }
                        Text(L("bus_prices")).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                        DorrField(value: priceOut, onValueChange: { priceOut = $0 }, label: L("bus_price_out"), singleLine: true,
                                  keyboardOptions: KeyboardOptions(keyboardType: .decimalPad))
                        if withReturn {
                            DorrField(value: priceBack, onValueChange: { priceBack = $0 }, label: L("bus_price_back"), singleLine: true,
                                      keyboardOptions: KeyboardOptions(keyboardType: .decimalPad))
                            DorrField(value: priceRound, onValueChange: { priceRound = $0 }, label: L("bus_price_round"), singleLine: true,
                                      keyboardOptions: KeyboardOptions(keyboardType: .decimalPad))
                        }
                        DorrField(value: seats, onValueChange: { seats = String($0.filter(\.isNumber).prefix(3)) }, label: L("bus_seats_for_sale"),
                                  singleLine: true, keyboardOptions: KeyboardOptions(keyboardType: .numberPad))
                        DorrField(value: note, onValueChange: { note = String($0.prefix(255)) }, label: L("bus_note"), minLines: 2)
                        Banner(error)
                        PrimaryButton(L("bus_publish"),
                                      enabled: origin != nil && destination != nil && departDay != nil && departTime != nil && busMinor(priceOut) != nil,
                                      busy: busy) { publish() }
                    }
                    .padding(16)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
            .background(pal.bg.ignoresSafeArea())

            if let which = picking {
                PlacePicker(onClose: { picking = nil }) { p in
                    if which == "from" { origin = p } else { destination = p }
                    picking = nil
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { dateFor != nil }, set: { if !$0 { dateFor = nil } })) {
            DorrDatePickerDialog(initial: nil, min: LocalDate.now(), max: LocalDate.now().plusYears(1), onDismiss: { dateFor = nil }) { d in
                if dateFor == "depart" { departDay = d } else { returnDay = d }
                dateFor = nil
            }
        }
        .background(
            Color.clear.dorrDialog(isPresented: Binding(get: { timeFor != nil }, set: { if !$0 { timeFor = nil } })) {
                TimePickDialog(onDismiss: { timeFor = nil }) { t in
                    if timeFor == "depart" { departTime = t } else { returnTime = t }
                    timeFor = nil
                }
            }
        )
    }

    private func publish() {
        guard let origin, let destination else { return }
        busy = true
        error = nil
        Task {
            var body: JsonBody = [
                "origin": ["lat": origin.lat, "lng": origin.lng, "address": origin.address] as JsonBody,
                "destination": ["lat": destination.lat, "lng": destination.lng, "address": destination.address] as JsonBody,
                "depart_at": busAt(departDay, departTime),
                "price_outbound_minor": busMinor(priceOut),
            ]
            if withReturn, let r = busAt(returnDay, returnTime) { body["return_at"] = r }
            if withReturn {
                if let m = busMinor(priceBack) { body["price_return_minor"] = m }
                if let m = busMinor(priceRound) { body["price_round_minor"] = m }
            }
            if let n = Int(seats) { body["seats_total"] = n }
            if !note.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { body["note"] = note.trimmingCharacters(in: .whitespacesAndNewlines) }
            switch await DriverBusApi.publish(body: body) {
            case .ok: onDone()
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }
}

/// Android's `TimePickerDialog` (12-hour clock, starting at now): the time wheel in a dialog card.
struct TimePickDialog: View {
    @Environment(\.pal) private var pal
    let onDismiss: () -> Void
    let onPick: (PickedTime) -> Void
    @State private var date = Date()

    var body: some View {
        VStack(spacing: 0) {
            DatePicker("", selection: $date, displayedComponents: .hourAndMinute)
                .datePickerStyle(.wheel)
                .labelsHidden()
                .environment(\.locale, Locale(identifier: DriverPrefs.locale + "@numbers=latn"))
                .frame(maxWidth: .infinity)
            HStack(spacing: 10) {
                OutlineButton(L("cancel"), color: pal.mut, fillMaxWidth: true, onClick: onDismiss)
                PrimaryButton(L("date_ok")) {
                    let c = Calendar.current.dateComponents([.hour, .minute], from: date)
                    onPick(PickedTime(hour: c.hour ?? 0, minute: c.minute ?? 0))
                }
                .frame(maxWidth: .infinity)
            }
            .padding(.top, 10)
        }
        .padding(16)
        .frame(maxWidth: .infinity)
        .surface(28, elevation: 12)
        .padding(.horizontal, 24)
    }
}

/// Typing a place: the phone's geocoder answers (no server call).
struct PlacePicker: View {
    @Environment(\.pal) private var pal
    let onClose: () -> Void
    let onPick: (PlaceDto) -> Void
    @State private var query = ""
    @State private var results: [PlaceDto] = []

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            ScreenHeader(L("bus_search_place"), onBack: onClose)
            DorrField(value: query, onValueChange: { query = $0 }, placeholder: L("bus_search_hint"), singleLine: true)
            VSpace(8)
            ScrollView {
                VStack(spacing: 0) {
                    ForEach(Array(results.enumerated()), id: \.offset) { _, p in
                        ListRow(icon: "mappin.circle.fill", title: p.address ?? "", onClick: { onPick(p) })
                    }
                }
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
        .background(pal.bg.ignoresSafeArea())
        .task(id: query) {
            try? await Task.sleep(nanoseconds: 300_000_000)
            if Task.isCancelled { return }
            let q = query
            if q.trimmingCharacters(in: .whitespaces).count < 3 { results = []; return }
            let request = MKLocalSearch.Request()
            request.naturalLanguageQuery = q
            let found = (try? await MKLocalSearch(request: request).start())?.mapItems ?? []
            if Task.isCancelled { return }
            results = found.prefix(8).map { item in
                let pm = item.placemark
                let line = [pm.name, pm.thoroughfare, pm.locality, pm.country].compactMap { $0 }.reduce(into: [String]()) { acc, s in
                    if !acc.contains(s) { acc.append(s) }
                }.joined(separator: ", ")
                return PlaceDto(lat: pm.coordinate.latitude, lng: pm.coordinate.longitude, address: line.isEmpty ? q : line)
            }
        }
    }
}

// MARK: - one trip: BusTripLive.swift (O-68, O-69)

/// O-20: a QR label for each seat — its number, to stick on the seat.
private struct SeatLabels: View {
    @Environment(\.pal) private var pal
    let bus: BusVehicleDto
    let onClose: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            ScreenHeader(L("bus_seat_labels"), onBack: onClose)
            ScrollView {
                LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 10), count: 3), spacing: 10) {
                    ForEach(Array((bus.seatLabels ?? []).enumerated()), id: \.offset) { _, label in
                        VStack(spacing: 0) {
                            if let img = busQr(label.qr ?? "") {
                                Image(uiImage: img).interpolation(.none).resizable().aspectRatio(1, contentMode: .fit).frame(maxWidth: .infinity)
                            }
                            HStack(spacing: 0) {
                                Image(systemName: "qrcode").font(.system(size: 12)).foregroundStyle(pal.accent).frame(width: 14, height: 14)
                                Text(L("bus_seat_no", label.seat ?? 0)).font(.dorr(14, .black)).foregroundStyle(pal.ink)
                            }
                        }
                        .padding(10)
                        .surface()
                    }
                }
                .padding(12)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
        .background(pal.bg.ignoresSafeArea())
    }
}

/// `qr(text, 360)`: black on white, a one-module margin.
private func busQr(_ text: String, size: CGFloat = 360) -> UIImage? {
    guard let filter = CIFilter(name: "CIQRCodeGenerator") else { return nil }
    filter.setValue(Data(text.utf8), forKey: "inputMessage")
    filter.setValue("L", forKey: "inputCorrectionLevel")
    guard let output = filter.outputImage, let cg = CIContext().createCGImage(output, from: output.extent) else { return nil }
    let width = cg.width, height = cg.height
    var pixels = [UInt8](repeating: 0, count: width * height)
    guard let ctx = CGContext(data: &pixels, width: width, height: height, bitsPerComponent: 8, bytesPerRow: width,
                              space: CGColorSpaceCreateDeviceGray(), bitmapInfo: CGImageAlphaInfo.none.rawValue) else { return nil }
    ctx.draw(cg, in: CGRect(x: 0, y: 0, width: width, height: height))
    // CoreImage adds its own white border: keep the modules, then a margin of one.
    let dark = pixels.map { $0 < 128 }
    let rows = (0..<height).filter { y in (0..<width).contains { dark[y * width + $0] } }
    let cols = (0..<width).filter { x in (0..<height).contains { dark[$0 * width + x] } }
    guard let top = rows.first, let bottom = rows.last, let left = cols.first, let right = cols.last else { return nil }
    let modules = max(bottom - top, right - left) + 1
    let total = modules + 2
    let cell = size / CGFloat(total)
    let format = UIGraphicsImageRendererFormat.default()
    format.scale = 1
    return UIGraphicsImageRenderer(size: CGSize(width: size, height: size), format: format).image { c in
        UIColor.white.setFill()
        c.fill(CGRect(x: 0, y: 0, width: size, height: size))
        UIColor.black.setFill()
        for y in 0..<modules {
            for x in 0..<modules where top + y < height && left + x < width && dark[(top + y) * width + left + x] {
                c.fill(CGRect(x: CGFloat(x + 1) * cell, y: CGFloat(y + 1) * cell, width: cell, height: cell))
            }
        }
    }
}

private func zonedBus(_ iso: String?) -> Date? { parseInstant(iso) }

private func busFormat(_ iso: String?, _ pattern: String) -> String? {
    guard let date = zonedBus(iso) else { return nil }
    let f = DateFormatter()
    f.locale = Locale(identifier: DriverPrefs.locale + "@numbers=latn")
    f.timeZone = .current
    f.dateFormat = pattern
    return f.string(from: date)
}

func busTime(_ iso: String?) -> String { busFormat(iso, "h:mm a") ?? "—" }

func busDay(_ iso: String?) -> String { busFormat(iso, "EEE d MMM") ?? "" }

// MARK: - scanning a ticket (zxing's `ScanContract` on Android)

/// The camera full screen, reading one QR: `onResult(nil)` when closed. Beeps on a read (`setBeepEnabled(true)`).
struct TicketScanScreen: View {
    /// The line over the camera (Android's `setPrompt`).
    let prompt: String
    let onResult: (String?) -> Void
    @State private var denied = false

    var body: some View {
        ZStack(alignment: .top) {
            Color.black.ignoresSafeArea()
            if denied {
                Text(prompt).font(.dorr(15, .bold)).foregroundStyle(.white).padding(.top, 120)
            } else {
                TicketCameraView(onCode: { code in
                    AudioServicesPlaySystemSound(1057)
                    onResult(code)
                }, onDenied: { denied = true }).ignoresSafeArea()
            }
            HStack {
                Text(prompt).font(.dorr(14, .bold)).foregroundStyle(.white)
                Spacer()
                Button { onResult(nil) } label: {
                    Image(systemName: "xmark").font(.system(size: 16, weight: .bold)).foregroundStyle(.white)
                        .frame(width: 38, height: 38).background(Color.white.opacity(0.18), in: Circle())
                }
            }
            .padding(.horizontal, 18)
            .padding(.top, 12)
        }
    }
}

private struct TicketCameraView: UIViewControllerRepresentable {
    let onCode: (String) -> Void
    let onDenied: () -> Void

    func makeUIViewController(context: Context) -> ScannerController {
        let controller = ScannerController()
        controller.onCode = onCode
        controller.onDenied = onDenied
        return controller
    }

    func updateUIViewController(_ controller: ScannerController, context: Context) {}

    final class ScannerController: UIViewController, AVCaptureMetadataOutputObjectsDelegate {
        var onCode: ((String) -> Void)?
        var onDenied: (() -> Void)?
        private let session = AVCaptureSession()
        private var delivered = false

        override func viewDidLoad() {
            super.viewDidLoad()
            view.backgroundColor = .black
            AVCaptureDevice.requestAccess(for: .video) { granted in
                DispatchQueue.main.async { granted ? self.start() : self.onDenied?() }
            }
        }

        private func start() {
            guard let device = AVCaptureDevice.default(for: .video), let input = try? AVCaptureDeviceInput(device: device),
                  session.canAddInput(input) else {
                onDenied?()
                return
            }
            session.addInput(input)
            let output = AVCaptureMetadataOutput()
            guard session.canAddOutput(output) else { return }
            session.addOutput(output)
            output.setMetadataObjectsDelegate(self, queue: .main)
            output.metadataObjectTypes = [.qr]
            let preview = AVCaptureVideoPreviewLayer(session: session)
            preview.videoGravity = .resizeAspectFill
            preview.frame = view.bounds
            view.layer.addSublayer(preview)
            let session = self.session
            DispatchQueue.global(qos: .userInitiated).async { session.startRunning() }
        }

        override func viewDidLayoutSubviews() {
            super.viewDidLayoutSubviews()
            view.layer.sublayers?.forEach { $0.frame = view.bounds }
        }

        override func viewWillDisappear(_ animated: Bool) {
            super.viewWillDisappear(animated)
            if session.isRunning { session.stopRunning() }
        }

        func metadataOutput(_ output: AVCaptureMetadataOutput, didOutput objects: [AVMetadataObject], from connection: AVCaptureConnection) {
            guard !delivered, let code = (objects.first as? AVMetadataMachineReadableCodeObject)?.stringValue else { return }
            delivered = true
            session.stopRunning()
            onCode?(code)
        }
    }
}
