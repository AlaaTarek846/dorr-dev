// providerApp `ui/screens/OnboardingScreen.kt`
import CoreLocation
import SwiftUI

/// After the sign-in, with no establishment yet (C-02, docs/moving_business.md §12): three short steps in the kit's look —
///  1. individual or company, and the name (a company adds its commercial register, and its tax number if it has one);
///  2. the service(s) to register in, as a grid with each service's image in the admin's order (`workspace/options`);
///  3. the main branch's place: the phone's current location, an address line and the area served (km).
/// Then `POST establishments`, and the establishment's papers page opens. A map to place the branch by hand comes later.
@MainActor
struct OnboardingScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onExit: () -> Void
    let onCreated: () -> Void

    @State private var step = 1
    /// The direction of the last step change: forward comes in from the trailing side, back from the leading one.
    @State private var forward = true
    @State private var type = "individual"
    @State private var name = ""
    @State private var register = ""
    @State private var tax = ""
    @State private var picked: [Int] = []
    @State private var lat: Double?
    @State private var lng: Double?
    @State private var address = ""
    @State private var radius = ""

    @State private var services: [ServiceOptionDto]?
    @State private var locating = false
    @State private var busy = false
    @State private var error: String?

    private var radiusValue: Double? { Double(radius.replacingOccurrences(of: ",", with: ".")) }
    private var radiusOk: Bool {
        radius.trimmingCharacters(in: .whitespaces).isEmpty || (radiusValue.map { (0.5...500.0).contains($0) } ?? false)
    }
    private var step1Ok: Bool { !name.trimmingCharacters(in: .whitespaces).isEmpty && (type == "individual" || !register.trimmingCharacters(in: .whitespaces).isEmpty) }
    private var step2Ok: Bool { !picked.isEmpty }
    private var step3Ok: Bool { lat != nil && lng != nil && radiusOk }

    var body: some View {
        let sign: CGFloat = forward ? 1 : -1
        let width = UIScreen.main.bounds.width
        Page(title: L("onb_title"), onBack: { if step > 1 { go(step - 1) } else { onExit() } }) {
            StepBar(step: step)
            VStack(alignment: .leading, spacing: 14) {
                switch step {
                case 1: stepOne
                case 2: stepTwo
                default: stepThree
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .id(step)
            .transition(.asymmetric(
                insertion: .offset(x: sign * width / 6).combined(with: .opacity),
                removal: .offset(x: -sign * width / 6).combined(with: .opacity)
            ))
        }
        .messageToast(error)
        // The person's country decides the services and later the papers; read once (and again when the connection returns).
        .task(id: network.reconnectTick) { await loadOptions() }
    }

    // MARK: - steps

    @ViewBuilder private var stepOne: some View {
        FormIntro(icon: "storefront.fill", title: L("onb_step1_title"), text: L("onb_step1_body"))
        HStack(spacing: 10) {
            TypeCard(icon: "person.fill", title: L("est_type_individual"), hint: L("onb_individual_hint"), selected: type == "individual") { type = "individual" }
            TypeCard(icon: "building.2.fill", title: L("est_type_company"), hint: L("onb_company_hint"), selected: type == "company") { type = "company" }
        }
        FormGroup {
            DorrTextField(value: $name.profileTake(150), label: L(type == "company" ? "onb_company_name" : "onb_individual_name"), icon: "storefront.fill")
            if type == "company" {
                DorrTextField(value: $register.profileTake(64), label: L("onb_register"), icon: "person.text.rectangle", keyboard: .asciiCapable)
                DorrTextField(value: $tax.profileTake(64), label: L("onb_tax"), placeholder: L("onb_optional"), icon: "doc.plaintext", keyboard: .asciiCapable)
            }
        }
        SettingsPrimaryButton(text: L("onb_next"), enabled: step1Ok) { go(2) }
    }

    @ViewBuilder private var stepTwo: some View {
        FormIntro(icon: "square.grid.2x2.fill", title: L("onb_step2_title"), text: L("onb_step2_body"))
        if let list = services {
            if list.isEmpty {
                Text(L("onb_no_services")).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
            } else {
                // The server's order is the admin's order (§12): kept as it comes, three to a row.
                LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 10, alignment: .top), count: 3), spacing: 10) {
                    ForEach(list) { service in
                        ServiceTile(service: service, selected: picked.contains(service.id)) {
                            if let i = picked.firstIndex(of: service.id) { picked.remove(at: i) } else { picked.append(service.id) }
                        }
                    }
                }
            }
        } else {
            ProgressView().tint(wa.red).frame(width: 28, height: 28).frame(maxWidth: .infinity)
        }
        SettingsPrimaryButton(text: L("onb_next"), enabled: step2Ok) { go(3) }
    }

    @ViewBuilder private var stepThree: some View {
        FormIntro(icon: "mappin.and.ellipse", title: L("onb_step3_title"), text: L("onb_step3_body"))
        Card {
            HStack(spacing: 10) {
                Image(systemName: "location.fill").font(.system(size: 18, weight: .semibold)).foregroundStyle(wa.red).frame(width: 22)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L(lat != nil ? "onb_location_set" : "onb_location_none")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                    if let lat, let lng {
                        // Coordinates keep their left-to-right order inside Arabic text.
                        Text(verbatim: "\u{200E}" + String(format: "%.5f, %.5f", locale: Locale(identifier: "en_US_POSIX"), lat, lng))
                            .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                }
                Spacer(minLength: 0)
                if locating { ProgressView().tint(wa.red).frame(width: 20, height: 20) }
            }
            ProfileTextLink(text: L(lat != nil ? "onb_location_again" : "onb_location_use"), enabled: !locating && !busy, action: useMyLocation)
            Text(L("onb_map_later")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
        }
        FormGroup {
            DorrTextField(value: $address.profileTake(255), label: L("onb_address"), placeholder: L("onb_optional"), icon: "mappin.and.ellipse")
            DorrTextField(value: $radius.profileMap { String($0.filter { $0.isASCII && ($0.isNumber || $0 == "." || $0 == ",") }.prefix(5)) },
                          label: L("onb_radius"), placeholder: L("onb_radius_hint"), icon: "safari", keyboard: .decimalPad, error: !radiusOk)
            if !radiusOk { Text(L("onb_radius_invalid")).font(.dorr(12)).foregroundStyle(Color(hex: 0xDC2626)) }
        }
        SettingsPrimaryButton(text: L("onb_create"), enabled: step3Ok, loading: busy, action: submit)
    }

    // MARK: - actions

    /// Moves to another step: the slide direction is set first (forward = a later step), then the step changes.
    private func go(_ next: Int) {
        forward = next >= step
        DispatchQueue.main.async {
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32)) { step = next }
        }
    }

    private func loadOptions() async {
        var me = Session.shared.me
        if me == nil, let read = await AuthApi.me().value {
            Session.shared.setMe(read)
            me = read
        }
        let result = await WorkspaceApi.options(countryId: me?.countryId)
        switch result {
        case .ok(let data, _): services = data.services ?? []
        default: if services == nil { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }

    /// The phone's position now, once — asking for the permission first when it was never asked (Android's
    /// `RequestMultiplePermissions` then `currentPosition`).
    private func useMyLocation() {
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

    private func submit() {
        guard let countryId = Session.shared.me?.countryId, let latitude = lat, let longitude = lng else {
            error = L("login_error_generic")
            return
        }
        busy = true
        error = nil
        let trimmedRegister = register.trimmingCharacters(in: .whitespacesAndNewlines)
        let trimmedTax = tax.trimmingCharacters(in: .whitespacesAndNewlines)
        let trimmedAddress = address.trimmingCharacters(in: .whitespacesAndNewlines)
        let request = CreateEstablishmentRequest(
            type: type,
            name: name.trimmingCharacters(in: .whitespacesAndNewlines),
            countryId: countryId,
            commercialRegisterNo: type == "company" && !trimmedRegister.isEmpty ? trimmedRegister : nil,
            taxNumber: type == "company" && !trimmedTax.isEmpty ? trimmedTax : nil,
            serviceIds: picked,
            branch: BranchRequest(name: nil, lat: latitude, lng: longitude, radiusKm: radiusValue, address: trimmedAddress.isEmpty ? nil : trimmedAddress)
        )
        Task {
            let result = await WorkspaceApi.create(request)
            busy = false
            if let seat = result.value {
                Session.shared.chooseEstablishment(seat.establishment?.uuid)
                await Workspace.shared.reload()
                onCreated()
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}

/// Three segments on top: the steps done and the one open in the app colour.
private struct StepBar: View {
    @Environment(\.wa) private var wa
    let step: Int

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 6) {
                ForEach(1...3, id: \.self) { index in
                    Capsule()
                        .fill(index <= step ? wa.red : (wa.night ? wa.line : wa.red.opacity(0.15)))
                        .frame(height: 5)
                        .frame(maxWidth: .infinity)
                        .animation(.easeInOut(duration: 0.3), value: step)
                }
            }
            Text(L("onb_step_of", step, 3)).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

private struct TypeCard: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let hint: String
    let selected: Bool
    let onClick: () -> Void

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 18, style: .continuous)
        Button(action: onClick) {
            VStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 20, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 44, height: 44)
                    .background(wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                Text(verbatim: title).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                Text(verbatim: hint).font(.dorr(11)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).lineSpacing(2)
            }
            .padding(14)
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
            .background(selected ? wa.red.opacity(wa.night ? 0.22 : 0.10) : wa.settingsCard, in: shape)
            .overlay(shape.stroke(selected ? wa.red : (wa.night ? wa.line : Color(hex: 0xE5E7EB)), lineWidth: selected ? 2 : 1))
            .contentShape(shape)
        }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity)
    }
}

/// One service: its image from the admin (a soft tile when there is none), its name, and a tick once chosen.
private struct ServiceTile: View {
    @Environment(\.wa) private var wa
    let service: ServiceOptionDto
    let selected: Bool
    let onClick: () -> Void

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 18, style: .continuous)
        Button(action: onClick) {
            VStack(spacing: 8) {
                GeometryReader { proxy in
                    let side = proxy.size.width * 0.72
                    ZStack {
                        if let image = Api.mediaUrl(service.image) {
                            AsyncImage(url: image) { phase in
                                if let loaded = phase.image { loaded.resizable().scaledToFit() } else { Color.clear }
                            }
                        } else {
                            Image(systemName: "square.grid.2x2.fill").font(.system(size: 22, weight: .semibold)).foregroundStyle(wa.red)
                                .frame(width: side, height: side)
                                .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                    }
                    .frame(width: side, height: side)
                    .frame(maxWidth: .infinity)
                }
                .aspectRatio(1 / 0.72, contentMode: .fit)
                Text(verbatim: service.name ?? "").font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk)
                    .multilineTextAlignment(.center).lineLimit(2).lineSpacing(1)
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

/// providerApp `hasLocationPermission`: whether the app may read the position now.
func hasLocationPermission() -> Bool {
    let status = CLLocationManager().authorizationStatus
    return status == .authorizedWhenInUse || status == .authorizedAlways
}

/// providerApp `hasLocationPermission` + `currentPosition`: the phone's position now, once (best accuracy, 15 s at most),
/// asking for the when-in-use permission first when it was never asked. Nil when it is refused or can't tell.
@MainActor
final class CurrentPosition: NSObject, CLLocationManagerDelegate {
    static let shared = CurrentPosition()

    private let manager = CLLocationManager()
    private var continuation: CheckedContinuation<CLLocationCoordinate2D?, Never>?
    private var waitingForPermission = false
    /// Bumped by every request, so a late timeout never ends a newer one.
    private var generation = 0
    private var waitSeconds: UInt64 = 15
    private var permissionAnswer: CheckedContinuation<Bool, Never>?

    /// Android's `RequestMultiplePermissions`: the system question when it was never asked; whether the app may read the position now.
    func askPermission() async -> Bool {
        manager.delegate = self
        guard manager.authorizationStatus == .notDetermined, permissionAnswer == nil else { return hasLocationPermission() }
        return await withCheckedContinuation { c in
            permissionAnswer = c
            manager.requestWhenInUseAuthorization()
        }
    }

    /// `seconds`: how long to wait for a fix once it is asked for.
    func fetch(seconds: UInt64 = 15) async -> CLLocationCoordinate2D? {
        // One request at a time: a second tap while locating gets nothing new.
        if continuation != nil { return nil }
        waitSeconds = seconds
        return await withCheckedContinuation { c in
            continuation = c
            generation += 1
            manager.delegate = self
            manager.desiredAccuracy = kCLLocationAccuracyBest
            switch manager.authorizationStatus {
            case .notDetermined:
                // The person answers the system question first; the wait starts once the position is asked for.
                waitingForPermission = true
                manager.requestWhenInUseAuthorization()
            case .authorizedWhenInUse, .authorizedAlways:
                locate()
            default:
                finish(nil)
            }
        }
    }

    private func locate() {
        manager.requestLocation()
        let current = generation
        let wait = waitSeconds
        Task { @MainActor [weak self] in
            try? await Task.sleep(nanoseconds: wait * 1_000_000_000)
            if let self, self.generation == current { self.finish(nil) }
        }
    }

    private func finish(_ coordinate: CLLocationCoordinate2D?) {
        waitingForPermission = false
        continuation?.resume(returning: coordinate)
        continuation = nil
    }

    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        let status = manager.authorizationStatus
        Task { @MainActor in
            if status != .notDetermined, let answer = self.permissionAnswer {
                self.permissionAnswer = nil
                answer.resume(returning: status == .authorizedWhenInUse || status == .authorizedAlways)
            }
            guard self.waitingForPermission, status != .notDetermined else { return }
            self.waitingForPermission = false
            if status == .authorizedWhenInUse || status == .authorizedAlways { self.locate() } else { self.finish(nil) }
        }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        let coordinate = locations.last?.coordinate
        Task { @MainActor in self.finish(coordinate) }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        Task { @MainActor in self.finish(nil) }
    }
}
