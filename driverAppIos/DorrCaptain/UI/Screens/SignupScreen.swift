import SwiftUI

// driverApp `ui/SignupScreen.kt` — signing up as a captain, step by step (details → vehicle → documents → review and send).

private let STEP_DETAILS = 0
private let STEP_VEHICLE = 1
private let STEP_DOCUMENTS = 2
private let STEP_REVIEW = 3

/// Signing up as a captain, one step at a time: details → vehicle → the documents the server says this driver needs
/// (their country, citizen or resident) → review and send — then Wasl where there is one. A bar on top shows where you
/// are and what is done; it opens on the first step still to do; each step checks its fields before saving.
struct SignupScreen: View {
    @Environment(\.pal) private var pal
    @Environment(\.layoutDirection) private var layoutDirection
    let onBack: () -> Void

    @State private var state: OnboardingDto? = nil
    @State private var options = OnboardingOptionsDto()
    @State private var regulator: RegulatorDto? = nil
    @State private var loadError: String? = nil
    @State private var step = -1
    /// The way the last move went (forward slides in from the reading end).
    @State private var forward = true

    init(onBack: @escaping () -> Void) { self.onBack = onBack }

    private func load() async {
        loadError = nil
        switch await DriverApi.onboarding() {
        case .ok(let data, _): state = data
        case .failed(let e): loadError = e.displayMessage
        case .offline: loadError = L("error_offline")
        }
        if case .ok(let data, _) = await DriverApi.regulator() { regulator = data }
    }

    /// Moves to a step, sliding the way it goes.
    private func go(_ to: Int) {
        forward = to > step
        DispatchQueue.main.async { withAnimation { step = to } }
    }

    private func goBack() {
        if step > 0 { go(step - 1) } else { onBack() }
    }

    var body: some View {
        ScreenScaffold(L("ob_title"), onBack: { goBack() }) {
            if let s = state, step >= 0 {
                StepBar(current: step, s: s) { go($0) }
                let rtl = layoutDirection == .rightToLeft
                let sign: CGFloat = (forward ? 1 : -1) * (rtl ? -1 : 1)
                let third = UIScreen.main.bounds.width / 3
                ZStack {
                    stepView(step, s)
                        .id(step)
                        .transition(.asymmetric(
                            insertion: .offset(x: third * sign).combined(with: .opacity).animation(.tween(320)),
                            removal: .offset(x: -third * sign).combined(with: .opacity).animation(.tween(260))
                        ))
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .clipped()
            } else {
                ZStack {
                    if let loadError {
                        VStack(spacing: 12) {
                            Banner(loadError)
                            OutlineButton(L("retry")) { Task { await load() } }
                        }
                    } else {
                        CircularProgress(color: pal.accent, size: 40, lineWidth: 4)
                    }
                }
                .padding(24)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .task {
            await load()
            if case .ok(let data, _) = await DriverApi.onboardingOptions() { options = data }
        }
        // O-66: reviewed from the admin (a document, the vehicle, the account) — the page follows at once.
        .onReceive(DriverLive.shared.events) { e in
            if e.name.hasPrefix("driver.") { Task { await load() } }
        }
        // Opens on the first step still to do (once; after that the driver moves).
        .onChange(of: state != nil) { _ in
            if let s = state, step < 0 { step = firstOpenStep(s) }
        }
        // `BackHandler { goBack() }`: iOS has no back key — the header's back button goes through `goBack()`.
    }

    @ViewBuilder
    private func stepView(_ current: Int, _ s: OnboardingDto) -> some View {
        switch current {
        case STEP_DETAILS:
            DetailsStep(s: s, options: options) { state = $0; go(STEP_VEHICLE) }
        case STEP_VEHICLE:
            VehicleStep(s: s, options: options, onSaved: { state = $0; go(STEP_DOCUMENTS) }, onNext: { go(STEP_DOCUMENTS) })
        case STEP_DOCUMENTS:
            DocumentsStep(s: s, onUpdated: { state = $0 }, onNeedVehicle: { go(STEP_VEHICLE) }, onNext: { go(STEP_REVIEW) })
        default:
            ReviewStep(
                s: s, options: options, regulator: regulator,
                onRegulator: { regulator = $0 }, onState: { state = $0 }, onEdit: { go($0) }, onClose: onBack
            )
        }
    }
}

private func firstOpenStep(_ s: OnboardingDto) -> Int {
    if !(s.steps?.profile ?? false) { return STEP_DETAILS }
    if !(s.steps?.vehicle ?? false) { return STEP_VEHICLE }
    if !(s.steps?.documents ?? false) { return STEP_DOCUMENTS }
    return STEP_REVIEW
}

// MARK: - the bar of steps

private struct StepBar: View {
    @Environment(\.pal) private var pal
    let current: Int
    let s: OnboardingDto
    let onStep: (Int) -> Void

    var body: some View {
        let labels = ["ob_details", "ob_vehicle", "ob_documents", "ob_review"]
        let icons = ["person.fill", "car.fill", "doc.text.fill", "checkmark.circle"]
        let done = [s.steps?.profile ?? false, s.steps?.vehicle ?? false, s.steps?.documents ?? false, s.driver?.status != "pending_documents"]
        HStack(alignment: .top, spacing: 0) {
            ForEach(0..<labels.count, id: \.self) { i in
                if i > 0 {
                    // The line between two steps fills once the one before is done.
                    StepLine(fill: done[i - 1])
                }
                StepDot(icon: icons[i], label: L(labels[i]), active: i == current, done: done[i]) { onStep(i) }
            }
        }
        .padding(.horizontal, 12)
        .padding(.top, 2)
        .padding(.bottom, 10)
    }
}

private struct StepLine: View {
    @Environment(\.pal) private var pal
    let fill: Bool

    var body: some View {
        Capsule().fill(pal.line)
            .overlay(alignment: .leading) {
                GeometryReader { g in
                    Rectangle().fill(pal.success).frame(width: g.size.width * (fill ? 1 : 0))
                }
            }
            .clipShape(Capsule())
            .frame(height: 3)
            .frame(maxWidth: .infinity)
            .padding(.top, 19)
            .animation(.tween(450), value: fill)
    }
}

private struct StepDot: View {
    @Environment(\.pal) private var pal
    let icon: String
    let label: String
    let active: Bool
    let done: Bool
    let onClick: () -> Void

    var body: some View {
        let fill: Color = active ? pal.accent : (done ? pal.success : pal.card)
        VStack(spacing: 0) {
            ZStack {
                Circle().fill(fill)
                Circle().strokeBorder(active || done ? Color.clear : pal.line, lineWidth: 1)
                DorrIcon(done && !active ? "checkmark" : icon, size: 20, tint: active || done ? .white : pal.mut)
            }
            .frame(width: 40, height: 40)
            .scaleEffect(active ? 1.1 : 1)
            .animation(.tween(250), value: fill)
            .animation(.spring(response: 0.35, dampingFraction: 0.5), value: active)
            Text(label)
                .font(.dorr(11, active ? .heavy : .medium))
                .foregroundStyle(active ? pal.accent : pal.mut)
                .lineLimit(1)
                .truncationMode(.tail)
                .multilineTextAlignment(.center)
                .padding(.top, 5)
        }
        .frame(width: 66)
        .bounceClick(onClick: onClick)
    }
}

// MARK: - the frame of one step

/// A step: its fields scrolling, the action pinned under them (above the keyboard).
private struct StepFrame<Footer: View, Content: View>: View {
    @Environment(\.pal) private var pal
    let title: String
    let hint: String
    let footer: Footer
    let content: Content

    init(_ title: String, _ hint: String, @ViewBuilder footer: () -> Footer, @ViewBuilder content: () -> Content) {
        self.title = title
        self.hint = hint
        self.footer = footer()
        self.content = content()
    }

    var body: some View {
        VStack(spacing: 0) {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(title).font(.dorr(22, .heavy)).foregroundStyle(pal.ink)
                        Text(hint).font(.dorr(13)).foregroundStyle(pal.mut).lineSpacing(4).padding(.top, 2)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                    .staggerIn(0)
                    content
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 18)
                .padding(.bottom, 16)
            }
            .scrollDismissesKeyboard(.interactively)
            VStack(spacing: 8) { footer }
                .padding(.horizontal, 18)
                .padding(.vertical, 12)
                .frame(maxWidth: .infinity)
                .background(pal.card.ignoresSafeArea(edges: .bottom))
        }
    }
}

/// What is wrong with a field, under it — sliding in when there is something to say.
private struct FieldHint: View {
    @Environment(\.pal) private var pal
    let text: String?
    /// The last words stay while it slides out.
    @State private var last: String = ""

    init(_ text: String?) { self.text = text }

    var body: some View {
        VStack(spacing: 0) {
            if text != nil {
                HStack(spacing: 4) {
                    DorrIcon("exclamationmark.triangle", size: 14, tint: pal.danger)
                    Text(text ?? last).font(.dorr(12, .medium)).foregroundStyle(pal.danger)
                }
                .padding(.leading, 14)
                .padding(.top, 4)
                .transition(.asymmetric(
                    insertion: .opacity.animation(.tween(160)).combined(with: .move(edge: .top)),
                    removal: .opacity.animation(.tween(120)).combined(with: .move(edge: .top))
                ))
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .clipped()
        .animation(.tween(text == nil ? 160 : 200), value: text)
        .onChange(of: text) { if let t = $0 { last = t } }
    }
}

/// The field's own check once the driver tried to save, else what the server said about it.
private func problem(_ tried: Bool, _ local: String?, _ server: [String: [String]], _ key: String) -> String? {
    if tried, let local { return L(local) }
    return server[key]?.first
}

private struct Caption: View {
    @Environment(\.pal) private var pal
    let text: String
    init(_ text: String) { self.text = text }
    var body: some View {
        Text(text).font(.dorr(13, .bold)).foregroundStyle(pal.ink).padding(.bottom, 8)
    }
}

/// `Patterns.EMAIL_ADDRESS`, as Android checks it.
private func isEmail(_ text: String) -> Bool {
    let pattern = "[a-zA-Z0-9\\+\\.\\_\\%\\-\\+]{1,256}\\@[a-zA-Z0-9][a-zA-Z0-9\\-]{0,64}(\\.[a-zA-Z0-9][a-zA-Z0-9\\-]{0,25})+"
    return NSPredicate(format: "SELF MATCHES %@", pattern).evaluate(with: text)
}

/// `Char.isDigit` (any Unicode decimal digit).
private func isDigit(_ c: Character) -> Bool {
    c.unicodeScalars.allSatisfy { CharacterSet.decimalDigits.contains($0) }
}

private func isLetterOrDigit(_ c: Character) -> Bool { c.isLetter || isDigit(c) }

// MARK: - 1. details

private struct DetailsStep: View {
    @Environment(\.pal) private var pal
    let s: OnboardingDto
    let options: OnboardingOptionsDto
    let onSaved: (OnboardingDto) -> Void

    @State private var name: String
    @State private var email: String
    @State private var gender: String?
    @State private var idType: String
    @State private var nationalId: String
    @State private var dob: String?
    @State private var nationality: Int?
    @State private var city: Int?
    @State private var tried = false
    @State private var busy = false
    @State private var error: String? = nil
    @State private var server: [String: [String]] = [:]

    init(s: OnboardingDto, options: OnboardingOptionsDto, onSaved: @escaping (OnboardingDto) -> Void) {
        self.s = s
        self.options = options
        self.onSaved = onSaved
        let p = s.profile ?? ProfileDto()
        _name = State(initialValue: p.name ?? "")
        _email = State(initialValue: p.email ?? "")
        _gender = State(initialValue: p.gender)
        _idType = State(initialValue: p.idType ?? "national_id")
        _nationalId = State(initialValue: p.nationalId ?? "")
        _dob = State(initialValue: p.dateOfBirth)
        _nationality = State(initialValue: p.nationalityCountryId)
        _city = State(initialValue: p.cityId)
    }

    private var locked: Bool { s.profile?.locked ?? false }

    // The server's own rules: a name, an ID number (max 20), a date of birth over 18, a valid email if given.
    private var latestBirth: LocalDate { LocalDate.now().plusYears(-18).minusDays(1) }
    private var nameLocal: String? { name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? "ob_err_required" : nil }
    private var emailLocal: String? {
        !email.trimmingCharacters(in: .whitespaces).isEmpty && !isEmail(email.trimmingCharacters(in: .whitespacesAndNewlines)) ? "ob_err_email" : nil
    }
    private var idLocal: String? { !locked && nationalId.trimmingCharacters(in: .whitespaces).isEmpty ? "ob_err_required" : nil }
    private var dobLocal: String? {
        if locked { return nil }
        guard let dob, !dob.trimmingCharacters(in: .whitespaces).isEmpty else { return "ob_err_required" }
        if let d = LocalDate.parse(String(dob.prefix(10))), d.isAfter(latestBirth) { return "ob_err_adult" }
        return nil
    }
    // O-74: For Her goes to women captains — the gender must be chosen.
    private var genderLocal: String? { !locked && (gender ?? "").trimmingCharacters(in: .whitespaces).isEmpty ? "ob_err_required" : nil }
    private var valid: Bool { [nameLocal, emailLocal, idLocal, dobLocal, genderLocal].allSatisfy { $0 == nil } }

    private func save() {
        tried = true
        if !valid || busy { return }
        busy = true
        error = nil
        var body: JsonBody = ["name": name.trimmingCharacters(in: .whitespacesAndNewlines)]
        if !email.trimmingCharacters(in: .whitespaces).isEmpty { body["email"] = email.trimmingCharacters(in: .whitespacesAndNewlines) }
        if let city { body["city_id"] = city }
        if !locked {
            body["id_type"] = idType
            body["national_id"] = nationalId.trimmingCharacters(in: .whitespacesAndNewlines)
            if let dob { body["date_of_birth"] = dob }
            if let gender { body["gender"] = gender }
            if let nationality { body["nationality_country_id"] = nationality }
        }
        let sent = body
        Task {
            switch await DriverApi.updateProfile(body: sent) {
            case .ok(let data, _): onSaved(data)
            case .failed(let e): server = e.fieldErrors; error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    var body: some View {
        StepFrame(L("ob_details"), L("ob_details_hint"), footer: {
            Banner(error)
            PrimaryButton(L("ob_next"), busy: busy, icon: "arrow.forward") { save() }
        }) {
            if locked { Banner(L("ob_locked"), kind: .info) }

            VStack(alignment: .leading, spacing: 0) {
                DorrTextField(
                    value: name, onValueChange: { name = String($0.prefix(150)); server["name"] = nil }, label: L("ob_name"),
                    placeholder: L("ob_name_hint"), icon: "person.fill",
                    keyboardOptions: KeyboardOptions(capitalization: .words, imeAction: .next),
                    error: problem(tried, nameLocal, server, "name") != nil
                )
                FieldHint(problem(tried, nameLocal, server, "name"))
            }
            .staggerIn(1)

            VStack(alignment: .leading, spacing: 0) {
                Caption(L("ob_gender"))
                Segmented(
                    options: ((options.genders ?? []).isEmpty ? ["male", "female"] : options.genders ?? []).map { ($0, genderLabel($0)) },
                    selected: gender ?? "", onSelect: { if !locked { gender = $0 } }
                )
                FieldHint(problem(tried, genderLocal, server, "gender"))
            }
            .opacity(locked ? 0.6 : 1)
            .staggerIn(2)

            VStack(alignment: .leading, spacing: 0) {
                Caption(L("ob_id_type"))
                Segmented(
                    options: ((options.idTypes ?? []).isEmpty ? ["national_id", "iqama", "passport"] : options.idTypes ?? []).map { ($0, idLabel($0)) },
                    selected: idType, onSelect: { if !locked { idType = $0; server["national_id"] = nil } }
                )
            }
            .opacity(locked ? 0.6 : 1)
            .staggerIn(3)

            VStack(alignment: .leading, spacing: 0) {
                let passport = idType == "passport"
                DorrTextField(
                    value: nationalId,
                    onValueChange: { input in
                        // A national ID / iqama is digits; a passport also has letters.
                        let kept = passport ? String(input.filter(isLetterOrDigit)).uppercased() : String(input.filter(isDigit))
                        nationalId = String(kept.prefix(20))
                        server["national_id"] = nil
                    },
                    label: L("ob_national_id"), placeholder: L("ob_national_id_hint"), icon: "person.text.rectangle",
                    keyboardOptions: KeyboardOptions(keyboardType: passport ? .asciiCapable : .numberPad, imeAction: .next),
                    error: problem(tried, idLocal, server, "national_id") != nil, enabled: !locked
                )
                FieldHint(problem(tried, idLocal, server, "national_id"))
            }
            .staggerIn(4)

            VStack(alignment: .leading, spacing: 0) {
                DateField(
                    label: L("ob_dob"), value: dob, onPick: { dob = $0; server["date_of_birth"] = nil },
                    min: LocalDate.now().plusYears(-90), max: latestBirth,
                    error: problem(tried, dobLocal, server, "date_of_birth") != nil, enabled: !locked
                )
                FieldHint(problem(tried, dobLocal, server, "date_of_birth"))
            }
            .staggerIn(5)

            SelectField(
                label: L("ob_nationality"), icon: "globe",
                choices: (options.nationalities ?? []).map { Choice(id: String($0.id ?? 0), label: $0.name ?? $0.code ?? "#\($0.id ?? 0)", flag: $0.code) },
                selectedId: nationality.map { String($0) }, onPick: { nationality = Int($0.id) }, enabled: !locked
            )
            .staggerIn(6)

            if !(options.cities ?? []).isEmpty {
                SelectField(
                    label: L("ob_city"), icon: "building.2",
                    choices: (options.cities ?? []).map { Choice(id: String($0.id ?? 0), label: $0.name ?? "#\($0.id ?? 0)") },
                    selectedId: city.map { String($0) }, onPick: { city = Int($0.id) }
                )
                .staggerIn(7)
            }

            VStack(alignment: .leading, spacing: 0) {
                DorrTextField(
                    value: email, onValueChange: { email = String($0.trimmingCharacters(in: .whitespacesAndNewlines).prefix(191)); server["email"] = nil },
                    label: L("ob_email"), placeholder: "name@example.com", icon: "envelope",
                    keyboardOptions: KeyboardOptions(keyboardType: .emailAddress, imeAction: .done, contentType: .emailAddress),
                    error: problem(tried, emailLocal, server, "email") != nil
                )
                FieldHint(problem(tried, emailLocal, server, "email"))
            }
            .staggerIn(8)
        }
    }
}

// MARK: - 2. vehicle

private struct VehicleStep: View {
    @Environment(\.pal) private var pal
    let s: OnboardingDto
    let options: OnboardingOptionsDto
    let onSaved: (OnboardingDto) -> Void
    let onNext: () -> Void

    @State private var make: Int?
    @State private var model: Int?
    @State private var color: Int?
    @State private var year: Int?
    @State private var letters: String
    @State private var numbers: String
    @State private var sequence: String
    // O-64: what kind of vehicle first (the makes follow it) — or none at all.
    @State private var kind: String
    @State private var tried = false
    @State private var busy = false
    @State private var error: String? = nil
    @State private var server: [String: [String]] = [:]

    init(s: OnboardingDto, options: OnboardingOptionsDto, onSaved: @escaping (OnboardingDto) -> Void, onNext: @escaping () -> Void) {
        self.s = s
        self.options = options
        self.onSaved = onSaved
        self.onNext = onNext
        let v = s.vehicle
        _make = State(initialValue: v?.makeId)
        _model = State(initialValue: v?.modelId)
        _color = State(initialValue: v?.colorId)
        _year = State(initialValue: v?.year)
        _letters = State(initialValue: v?.plateLetters ?? "")
        _numbers = State(initialValue: v?.plateNumber ?? "")
        _sequence = State(initialValue: v?.sequenceNumber ?? "")
        _kind = State(initialValue: (s.noVehicle ?? false) && v == nil ? "none" : vehicleKind(v?.bodyType))
    }

    private var v: OnboardingVehicleDto? { s.vehicle }
    private var makes: [MakeOptionDto] { (options.makes ?? []).filter { m in (m.models ?? []).contains { vehicleKind($0.bodyType) == kind } } }
    private var models: [ModelOptionDto] {
        ((options.makes ?? []).first { $0.id == make }?.models ?? []).filter { vehicleKind($0.bodyType) == kind }
    }
    private var chosenModel: ModelOptionDto? { models.first { $0.id == model } }
    private var water: Bool { ["boat", "yacht"].contains(kind) }

    private static let missing = "ob_err_required"
    private var makeLocal: String? { make == nil ? Self.missing : nil }
    private var modelLocal: String? { model == nil ? Self.missing : nil }
    private var colorLocal: String? { color == nil ? Self.missing : nil }
    private var yearLocal: String? { year == nil ? Self.missing : nil }
    private var lettersLocal: String? { letters.trimmingCharacters(in: .whitespaces).isEmpty ? Self.missing : nil }
    private var numbersLocal: String? { numbers.trimmingCharacters(in: .whitespaces).isEmpty ? Self.missing : nil }
    // O-64: the sequence number is required (a boat or a yacht has none).
    private var sequenceLocal: String? { !water && sequence.trimmingCharacters(in: .whitespaces).isEmpty ? Self.missing : nil }
    private var valid: Bool {
        [makeLocal, modelLocal, colorLocal, yearLocal, lettersLocal, numbersLocal, sequenceLocal].allSatisfy { $0 == nil }
    }

    // O-64: no vehicle — the step is done without one.
    private func noVehicle() {
        if busy { return }
        busy = true
        error = nil
        Task {
            switch await DriverApi.noVehicle(body: ["on": true]) {
            case .ok(let data, _): onSaved(data)
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    private func save() {
        tried = true
        if !valid || busy { return }
        busy = true
        error = nil
        var body: JsonBody = [
            "make_id": make,
            "model_id": model,
            "color_id": color,
            "year": year,
            // The model says what it is and how many it seats.
            "body_type": chosenModel?.bodyType ?? v?.bodyType ?? "sedan",
            "seats": chosenModel?.seats ?? v?.seats ?? 4,
            "plate_letters": letters.trimmingCharacters(in: .whitespacesAndNewlines),
            "plate_number": numbers.trimmingCharacters(in: .whitespacesAndNewlines),
        ]
        if !sequence.trimmingCharacters(in: .whitespaces).isEmpty { body["sequence_number"] = sequence.trimmingCharacters(in: .whitespacesAndNewlines) }
        let vehicle = v
        let wasNone = s.noVehicle ?? false
        let sent = body
        Task {
            if wasNone { _ = await DriverApi.noVehicle(body: ["on": false]) }
            let r = vehicle == nil ? await DriverApi.addVehicle(body: sent) : await DriverApi.updateVehicle(id: vehicle?.id ?? 0, body: sent)
            switch r {
            case .ok(let data, _): onSaved(data)
            case .failed(let e): server = e.fieldErrors; error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    var body: some View {
        // An approved vehicle can't be edited here (the server refuses it): show it, and move on.
        if let v, v.status == "approved" {
            StepFrame(L("ob_vehicle"), L("ob_vehicle_hint"), footer: {
                PrimaryButton(L("ob_continue"), icon: "arrow.forward", onClick: onNext)
            }) {
                Banner(L("ob_vehicle_locked"), kind: .success)
                VehicleSummary(make: v.make, model: v.model, year: v.year, color: v.color, colorHex: v.colorHex,
                               letters: v.plateLetters, numbers: v.plateNumber)
                    .staggerIn(1)
            }
        } else {
            form
        }
    }

    private var form: some View {
        StepFrame(L("ob_vehicle"), L("ob_vehicle_hint"), footer: {
            Banner(error)
            PrimaryButton(L("ob_next"), busy: busy, icon: "arrow.forward") { if kind == "none" { noVehicle() } else { save() } }
        }) {
            if v?.status == "rejected" { Banner(L("ob_vehicle_rejected"), kind: .warning) }

            // O-64: the kind of vehicle — a car, a motorcycle, a bus, a boat, a yacht — or none.
            VStack(alignment: .leading, spacing: 0) {
                Caption(L("ob_vehicle_kind"))
                KindFlow(spacing: 8) {
                    ForEach([("car", "vk_car"), ("motorcycle", "vk_motorcycle"), ("bus", "vk_bus"), ("boat", "vk_boat"),
                             ("yacht", "vk_yacht"), ("none", "vk_none")], id: \.0) { k, label in
                        Chip(L(label), selected: kind == k) { if kind != k { kind = k; make = nil; model = nil } }
                    }
                }
            }
            .staggerIn(0)

            if kind == "none" {
                Banner(L("ob_no_vehicle_hint"), kind: .info)
            } else {
                vehicleFields
            }
        }
    }

    @ViewBuilder
    private var vehicleFields: some View {
        VStack(alignment: .leading, spacing: 0) {
            SelectField(
                label: L("ob_make"), icon: "car.fill",
                choices: makes.map { Choice(id: String($0.id ?? 0), label: $0.name ?? "#\($0.id ?? 0)") },
                selectedId: make.map { String($0) },
                onPick: { picked in
                    if Int(picked.id) != make { make = Int(picked.id); model = nil }
                    server["make_id"] = nil
                },
                searchable: true,
                error: problem(tried, makeLocal, server, "make_id") != nil
            )
            FieldHint(problem(tried, makeLocal, server, "make_id"))
        }
        .staggerIn(1)

        VStack(alignment: .leading, spacing: 0) {
            SelectField(
                label: L("ob_model"), icon: "car.2.fill",
                choices: models.map { m in
                    let sub = [m.bodyType.map { bodyLabel($0) }, m.seats.map { L("ob_seats_count", $0) }].compactMap { $0 }.joined(separator: " · ")
                    return Choice(id: String(m.id ?? 0), label: m.name ?? "#\(m.id ?? 0)", sub: sub.isEmpty ? nil : sub)
                },
                selectedId: model.map { String($0) },
                onPick: { model = Int($0.id); server["model_id"] = nil },
                placeholder: make == nil ? L("ob_make_first") : nil,
                searchable: models.count > 8, enabled: make != nil,
                error: problem(tried, modelLocal, server, "model_id") != nil
            )
            FieldHint(problem(tried, modelLocal, server, "model_id"))
            VStack(spacing: 0) {
                if let chosen = chosenModel {
                    HStack(spacing: 8) {
                        if let body = chosen.bodyType { Chip(bodyLabel(body)) }
                        if let seats = chosen.seats {
                            DorrIcon("chair.fill", size: 16, tint: pal.mut)
                            Text(L("ob_seats_count", seats)).font(.dorr(12, .bold)).foregroundStyle(pal.mut)
                        }
                    }
                    .padding(.top, 8)
                    .transition(.opacity.combined(with: .move(edge: .top)))
                }
            }
            .clipped()
            .animation(.default, value: chosenModel?.id)
        }
        .staggerIn(2)

        VStack(alignment: .leading, spacing: 0) {
            Caption(L("ob_color"))
            ColorPicker(colors: options.colors ?? [], selected: color, error: problem(tried, colorLocal, server, "color_id") != nil) {
                color = $0
                server["color_id"] = nil
            }
            FieldHint(problem(tried, colorLocal, server, "color_id"))
        }
        .staggerIn(3)

        VStack(alignment: .leading, spacing: 0) {
            let thisYear = LocalDate.now().year
            SelectField(
                label: L("ob_year"), icon: "calendar",
                choices: stride(from: thisYear + 1, through: 1980, by: -1).map { Choice(id: String($0), label: String($0)) },
                selectedId: year.map { String($0) },
                onPick: { year = Int($0.id); server["year"] = nil },
                searchable: false,
                error: problem(tried, yearLocal, server, "year") != nil
            )
            FieldHint(problem(tried, yearLocal, server, "year"))
        }
        .staggerIn(4)

        VStack(alignment: .leading, spacing: 10) {
            Text(L("ob_plate")).font(.dorr(13, .bold)).foregroundStyle(pal.ink)
            // The plate as it will look, while it is typed.
            PlatePreview(letters: letters, numbers: numbers)
                .frame(maxWidth: .infinity)
            HStack(alignment: .top, spacing: 10) {
                VStack(alignment: .leading, spacing: 0) {
                    DorrTextField(
                        value: letters,
                        onValueChange: { letters = String(String($0.filter { $0.isLetter || $0 == " " }).uppercased().prefix(8)); server["plate_letters"] = nil },
                        label: L("ob_plate_letters"), placeholder: "ABC", icon: "textformat",
                        keyboardOptions: KeyboardOptions(capitalization: .characters, imeAction: .next),
                        error: problem(tried, lettersLocal, server, "plate_letters") != nil
                    )
                    FieldHint(problem(tried, lettersLocal, server, "plate_letters"))
                }
                .frame(maxWidth: .infinity)
                VStack(alignment: .leading, spacing: 0) {
                    DorrTextField(
                        value: numbers,
                        onValueChange: { numbers = String(String($0.filter(isDigit)).prefix(8)); server["plate_number"] = nil },
                        label: L("ob_plate_number"), placeholder: "1234", icon: "number",
                        keyboardOptions: KeyboardOptions(keyboardType: .numberPad, imeAction: .next),
                        error: problem(tried, numbersLocal, server, "plate_number") != nil
                    )
                    FieldHint(problem(tried, numbersLocal, server, "plate_number"))
                }
                .frame(maxWidth: .infinity)
            }
        }
        .staggerIn(5)

        // O-64: the plate type isn't asked any more (a private car = 1 for Wasl); the sequence number is required.
        if !water {
            VStack(alignment: .leading, spacing: 0) {
                DorrTextField(
                    value: sequence,
                    onValueChange: { sequence = String(String($0.filter(isDigit)).prefix(16)); server["sequence_number"] = nil },
                    label: L("ob_sequence"), icon: "number",
                    keyboardOptions: KeyboardOptions(keyboardType: .numberPad, imeAction: .done),
                    error: problem(tried, sequenceLocal, server, "sequence_number") != nil
                )
                FieldHint(problem(tried, sequenceLocal, server, "sequence_number"))
            }
            .staggerIn(6)
        }
    }
}

/// Compose's `FlowRow`: the chips in rows, wrapping to the next row when the width is full.
private struct KindFlow: Layout {
    var spacing: CGFloat

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? .infinity
        var x: CGFloat = 0, y: CGFloat = 0, row: CGFloat = 0, widest: CGFloat = 0
        for v in subviews {
            let size = v.sizeThatFits(.unspecified)
            if x > 0 && x + size.width > width { x = 0; y += row + spacing; row = 0 }
            x += size.width + spacing
            row = max(row, size.height)
            widest = max(widest, x - spacing)
        }
        return CGSize(width: proposal.width ?? widest, height: y + row)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x: CGFloat = 0, y: CGFloat = 0, row: CGFloat = 0
        for v in subviews {
            let size = v.sizeThatFits(.unspecified)
            if x > 0 && x + size.width > bounds.width { x = 0; y += row + spacing; row = 0 }
            v.place(at: CGPoint(x: bounds.minX + x, y: bounds.minY + y), proposal: ProposedViewSize(size))
            x += size.width + spacing
            row = max(row, size.height)
        }
    }
}

/// The colours as swatches to tap — the chosen one ringed and ticked.
private struct ColorPicker: View {
    @Environment(\.pal) private var pal
    let colors: [ColorOptionDto]
    let selected: Int?
    let error: Bool
    let onPick: (Int) -> Void

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 18)
        ScrollView(.horizontal, showsIndicators: false) {
            LazyHStack(spacing: 4) {
                ForEach(colors, id: \.id) { c in
                    let on = c.id != nil && c.id == selected
                    VStack(spacing: 0) {
                        ZStack {
                            Circle().strokeBorder(on ? pal.accent : Color.clear, lineWidth: 2)
                            Swatch(c.hex, 36)
                            if on {
                                DorrIcon("checkmark", size: 18, tint: hexLuminance(c.hex) > 0.6 ? Color(hex: 0xFF111928) : .white)
                            }
                        }
                        .frame(width: 44, height: 44)
                        .scaleEffect(on ? 1.08 : 1)
                        .animation(.tween(200), value: on)
                        .animation(.spring(response: 0.35, dampingFraction: 0.5), value: on)
                        Text(c.name ?? "")
                            .font(.dorr(11, on ? .bold : .medium))
                            .foregroundStyle(on ? pal.accent : pal.mut)
                            .lineLimit(1)
                            .truncationMode(.tail)
                            .padding(.top, 4)
                    }
                    .frame(width: 62)
                    .bounceClick { if let id = c.id { onPick(id) } }
                }
            }
            .padding(.horizontal, 8)
            .padding(.vertical, 10)
        }
        .frame(maxWidth: .infinity)
        .background(shape.fill(pal.fieldFill))
        .clipShape(shape)
        .overlay(shape.strokeBorder(error ? pal.danger : pal.fieldLine, lineWidth: error ? 1.5 : 1))
    }
}

/// Compose's `Color.luminance()` of a "#RRGGBB" (grey when it can't be read).
private func hexLuminance(_ hex: String?) -> Double {
    var text = (hex ?? "").trimmingCharacters(in: .whitespaces)
    if text.hasPrefix("#") { text.removeFirst() }
    let value: UInt64 = text.count == 6 ? (UInt64(text, radix: 16) ?? 0x888888) : 0x888888
    func linear(_ c: UInt64) -> Double {
        let v = Double(c) / 255
        return v <= 0.04045 ? v / 12.92 : pow((v + 0.055) / 1.055, 2.4)
    }
    return 0.2126 * linear((value >> 16) & 0xFF) + 0.7152 * linear((value >> 8) & 0xFF) + 0.0722 * linear(value & 0xFF)
}

/// A number plate: the numbers and the letters on white, as on the car — placeholder digits until they are typed.
private struct PlatePreview: View {
    let letters: String
    let numbers: String
    var small = false

    var body: some View {
        let ink = Color(hex: 0xFF111928)
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
        Text(shown)
            .font(.dorr(small ? 15 : 22, .heavy))
            .kerning(1)
            .foregroundStyle(value.trimmingCharacters(in: .whitespaces).isEmpty ? Color(hex: 0xFFB8BEC8) : Color(hex: 0xFF111928))
            .lineLimit(1)
            .frame(maxHeight: .infinity)
    }
}

private struct VehicleSummary: View {
    @Environment(\.pal) private var pal
    let make: String?
    let model: String?
    let year: Int?
    let color: String?
    let colorHex: String?
    let letters: String?
    let numbers: String?

    var body: some View {
        VStack(spacing: 12) {
            HStack(spacing: 12) {
                TileIcon("car.fill", tint: pal.accent, box: 44)
                VStack(alignment: .leading, spacing: 0) {
                    let title = [make, model].compactMap { $0 }.joined(separator: " ")
                    Text(title.trimmingCharacters(in: .whitespaces).isEmpty ? L("ob_vehicle") : title)
                        .font(.dorr(15, .bold)).foregroundStyle(pal.ink)
                    HStack(spacing: 6) {
                        if let colorHex { Swatch(colorHex, 12) }
                        Text([color, year.map { String($0) }].compactMap { $0 }.joined(separator: " · "))
                            .font(.dorr(12)).foregroundStyle(pal.mut)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            PlatePreview(letters: letters ?? "", numbers: numbers ?? "", small: true)
                .frame(maxWidth: .infinity)
        }
        .padding(16)
        .frame(maxWidth: .infinity)
        .surface(20)
    }
}

// MARK: - 3. documents

private struct DocumentsStep: View {
    @Environment(\.pal) private var pal
    let s: OnboardingDto
    let onUpdated: (OnboardingDto) -> Void
    let onNeedVehicle: () -> Void
    let onNext: () -> Void
    @State private var open: Int? = nil

    var body: some View {
        let docs = s.requiredDocuments ?? []
        let sent = docs.filter { $0.document != nil && $0.document?.status != "rejected" }.count
        let shown: Double = docs.isEmpty ? 1 : Double(sent) / Double(docs.count)
        let needsVehicle = s.vehicle == nil && docs.contains { ($0.type?.appliesTo ?? "driver") == "vehicle" }

        StepFrame(L("ob_documents"), L("ob_documents_hint"), footer: {
            PrimaryButton(L("ob_continue"), icon: "arrow.forward", onClick: onNext)
        }) {
            if !docs.isEmpty {
                VStack(spacing: 6) {
                    HStack {
                        Text(L("ob_docs_progress", sent, docs.count)).font(.dorr(13, .bold)).foregroundStyle(pal.ink)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        if sent == docs.count { DorrIcon("checkmark.seal.fill", size: 18, tint: pal.success) }
                    }
                    ProgressLine(progress: shown, color: sent == docs.count ? pal.success : pal.accent, duration: 500)
                }
                .staggerIn(1)
            }
            if needsVehicle { Banner(L("ob_docs_need_vehicle"), kind: .warning) }
            if docs.isEmpty {
                VStack(spacing: 0) {
                    HeroBadge("checkmark.circle", tint: pal.success)
                    Text(L("ob_docs_none")).font(.dorr(14)).foregroundStyle(pal.mut).multilineTextAlignment(.center).padding(.top, 12)
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 32)
            }
            ForEach(Array(docs.enumerated()), id: \.offset) { i, doc in
                DocumentCard(doc: doc) {
                    if doc.type?.appliesTo == "vehicle" && s.vehicle == nil { onNeedVehicle() } else { open = doc.type?.id }
                }
                .staggerIn(i + 2)
            }
        }
        .sheet(isPresented: Binding(get: { open != nil }, set: { if !$0 { open = nil } })) {
            if let id = open, let doc = docs.first(where: { $0.type?.id == id }) {
                DocumentSheet(doc: doc, vehicleId: s.vehicle?.id, onDismiss: { open = nil }) { updated in
                    onUpdated(updated)
                    open = nil
                }
                .environment(\.pal, pal)
            }
        }
    }
}

/// Material's `LinearProgressIndicator`: a rounded 6-high track (`Pal.well`) and its fill, moving smoothly.
struct ProgressLine: View {
    @Environment(\.pal) private var pal
    let progress: Double
    let color: Color
    var duration = 250

    var body: some View {
        Capsule().fill(pal.well)
            .overlay(alignment: .leading) {
                GeometryReader { g in
                    Capsule().fill(color).frame(width: g.size.width * min(max(progress, 0), 1))
                }
            }
            .clipShape(Capsule())
            .frame(height: 6)
            .frame(maxWidth: .infinity)
            .animation(.tween(duration), value: progress)
    }
}

// MARK: - 4. review and send

private struct ReviewStep: View {
    @Environment(\.pal) private var pal
    let s: OnboardingDto
    let options: OnboardingOptionsDto
    let regulator: RegulatorDto?
    let onRegulator: (RegulatorDto) -> Void
    let onState: (OnboardingDto) -> Void
    let onEdit: (Int) -> Void
    let onClose: () -> Void
    @State private var busy = false
    @State private var error: String? = nil

    private var status: String? { s.driver?.status }

    private func act<T>(_ call: @escaping () async -> ApiResult<T>, _ done: @escaping (T) -> Void) {
        if busy { return }
        busy = true
        error = nil
        Task {
            switch await call() {
            case .ok(let data, _): done(data)
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    var body: some View {
        StepFrame(L("ob_review"), L("ob_review_hint"), footer: {
            Banner(error)
            if status == "pending_documents" {
                PrimaryButton(L("ob_submit"), enabled: s.canSubmit ?? false, busy: busy, icon: "person.crop.circle.badge.checkmark") {
                    act({ await DriverApi.submitOnboarding() }) { onState($0) }
                }
            } else {
                PrimaryButton(L("ob_done"), onClick: onClose)
            }
        }) {
            if status == "under_review" {
                WaitingHero()
            } else if status == "active" {
                VStack(spacing: 0) {
                    HeroBadge("checkmark.seal.fill", tint: pal.success)
                    Text(L("ob_status_approved")).font(.dorr(17, .heavy)).foregroundStyle(pal.success).padding(.top, 10)
                }
                .frame(maxWidth: .infinity)
                .staggerIn(1)
            }
            if let wp = s.workProblem { Banner(problemText(wp), kind: .warning) }

            if status == "pending_documents" && !(s.missing ?? []).isEmpty {
                let names = uniqued((s.missing ?? []).map { missingLabel(s, $0) })
                Banner(L("ob_missing", names.joined(separator: L("list_sep"))), kind: .warning)
            }

            let p = s.profile ?? ProfileDto()
            SummaryCard(icon: "person.fill", title: L("ob_details"), done: s.steps?.profile ?? false, onEdit: { onEdit(STEP_DETAILS) }) {
                SummaryLine(label: L("ob_name"), value: p.name)
                SummaryLine(label: p.idType.map { idLabel($0) } ?? L("ob_national_id"), value: p.nationalId)
                SummaryLine(label: L("ob_dob"), value: prettyDate(p.dateOfBirth).isEmpty ? nil : prettyDate(p.dateOfBirth))
                SummaryLine(label: L("ob_nationality"),
                            value: (options.nationalities ?? []).first { $0.id != nil && $0.id == p.nationalityCountryId }?.name)
            }
            .staggerIn(2)

            SummaryCard(icon: "car.fill", title: L("ob_vehicle"), done: s.steps?.vehicle ?? false, onEdit: { onEdit(STEP_VEHICLE) }) {
                if let v = s.vehicle {
                    SummaryLine(label: L("ob_model"), value: [v.make, v.model, v.year.map { String($0) }].compactMap { $0 }.joined(separator: " "))
                    SummaryLine(label: L("ob_status"), value: docStatus(v.status))
                    PlatePreview(letters: v.plateLetters ?? "", numbers: v.plateNumber ?? "", small: true)
                        .padding(.top, 4)
                } else {
                    Text(L("ob_status_missing")).font(.dorr(13, .bold)).foregroundStyle(pal.warning)
                }
            }
            .staggerIn(3)

            SummaryCard(icon: "doc.text.fill", title: L("ob_documents"), done: s.steps?.documents ?? false, onEdit: { onEdit(STEP_DOCUMENTS) }) {
                ForEach(Array((s.requiredDocuments ?? []).enumerated()), id: \.offset) { _, doc in
                    HStack {
                        Text(doc.type?.name ?? doc.type?.code ?? "").font(.dorr(13)).foregroundStyle(pal.ink)
                            .lineLimit(1).truncationMode(.tail)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        Text(docStatus(doc.document?.status)).font(.dorr(12, .bold)).foregroundStyle(statusColor(pal, doc.document?.status))
                    }
                }
            }
            .staggerIn(4)

            // Wasl, only where there is a regulator.
            if let reg = regulator, reg.required ?? false {
                let valid = reg.driverStatus == "valid" && reg.vehicleStatus == "valid"
                SummaryCard(icon: "checkmark.seal.fill", title: L("ob_wasl"), done: valid, onEdit: nil) {
                    Text({
                        if let until = reg.blockedUntil {
                            let pretty = prettyDate(until)
                            return L("ob_wasl_blocked", pretty.trimmingCharacters(in: .whitespaces).isEmpty ? until : pretty)
                        }
                        return valid ? L("ob_wasl_valid") : L("ob_wasl_pending")
                    }())
                    .font(.dorr(13, .bold))
                    .foregroundStyle(valid ? pal.success : pal.ink)
                    ForEach(Array((reg.reasons ?? []).enumerated()), id: \.offset) { _, r in
                        Text("• \(r.message ?? r.code ?? "")").font(.dorr(12)).foregroundStyle(pal.danger)
                    }
                    HStack(spacing: 8) {
                        SmallButton(icon: "arrow.triangle.2.circlepath", label: L("ob_wasl_check")) {
                            act({ await DriverApi.checkRegulator() }) { onRegulator($0) }
                        }
                        .frame(maxWidth: .infinity)
                        if (reg.canRegister ?? true) && reg.driverStatus != "valid" {
                            SmallButton(icon: "person.crop.circle.badge.checkmark", label: L("ob_wasl_register")) {
                                act({ await DriverApi.registerRegulator() }) { onRegulator($0) }
                            }
                            .frame(maxWidth: .infinity)
                        }
                    }
                    .padding(.top, 4)
                }
                .staggerIn(5)
            }
        }
    }
}

/// `List.distinct()` — the first of each, in order.
private func uniqued(_ list: [String]) -> [String] {
    var seen = Set<String>()
    return list.filter { seen.insert($0).inserted }
}

/// Sent and waiting: an hourglass that breathes, and the words.
private struct WaitingHero: View {
    @Environment(\.pal) private var pal
    @State private var breathe = false

    var body: some View {
        VStack(spacing: 0) {
            HeroBadge("hourglass", tint: pal.info, size: 84, scale: breathe ? 1.06 : 0.94)
                .onAppear { withAnimation(.tween(1100).repeatForever(autoreverses: true)) { breathe = true } }
            Text(L("ob_submitted")).font(.dorr(17, .heavy)).foregroundStyle(pal.ink).multilineTextAlignment(.center).padding(.top, 12)
            Text(L("ob_waiting_hint")).font(.dorr(13)).foregroundStyle(pal.mut).multilineTextAlignment(.center).lineSpacing(4).padding(.top, 4)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 8)
    }
}

private struct SummaryCard<Content: View>: View {
    @Environment(\.pal) private var pal
    let icon: String
    let title: String
    let done: Bool
    let onEdit: (() -> Void)?
    let content: Content

    init(icon: String, title: String, done: Bool, onEdit: (() -> Void)?, @ViewBuilder content: () -> Content) {
        self.icon = icon
        self.title = title
        self.done = done
        self.onEdit = onEdit
        self.content = content()
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 10) {
                TileIcon(icon, tint: done ? pal.success : pal.warning, box: 38)
                Text(title).font(.dorr(15, .heavy)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                if let onEdit {
                    HStack(spacing: 4) {
                        DorrIcon("pencil", size: 15, tint: pal.accent)
                        Text(L("ob_edit")).font(.dorr(12, .bold)).foregroundStyle(pal.accent)
                    }
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .clipShape(RoundedRectangle(cornerRadius: 10))
                    .bounceClick(onClick: onEdit)
                }
            }
            .padding(.bottom, 4)
            content
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface(20)
    }
}

private struct SummaryLine: View {
    @Environment(\.pal) private var pal
    let label: String
    let value: String?

    var body: some View {
        HStack(alignment: .top) {
            Text(label).font(.dorr(13)).foregroundStyle(pal.mut).frame(maxWidth: .infinity, alignment: .leading)
            Text((value?.trimmingCharacters(in: .whitespaces).isEmpty ?? true) ? "—" : value ?? "—")
                .font(.dorr(13, .semibold)).foregroundStyle(pal.ink)
        }
    }
}

private struct SmallButton: View {
    @Environment(\.pal) private var pal
    let icon: String
    let label: String
    let onClick: () -> Void

    var body: some View {
        HStack(spacing: 0) {
            DorrIcon(icon, size: 16, tint: pal.accent)
            Text(" \(label)").font(.dorr(13, .bold)).foregroundStyle(pal.accent)
        }
        .padding(.vertical, 10)
        .frame(maxWidth: .infinity)
        .background(RoundedRectangle(cornerRadius: 14).fill(pal.well))
        .bounceClick(onClick: onClick)
    }
}

// MARK: - words

/// A "missing" code from the server ("national_id", "vehicle", "document:licence") in words.
private func missingLabel(_ s: OnboardingDto, _ code: String) -> String {
    switch code {
    case "name": return L("ob_name")
    case "national_id": return L("ob_national_id")
    case "date_of_birth": return L("ob_dob")
    case "gender": return L("ob_gender")
    case "vehicle": return L("ob_vehicle")
    default:
        if code.hasPrefix("document:") {
            let c = String(code.dropFirst("document:".count))
            return (s.requiredDocuments ?? []).first { $0.type?.code == c }?.type?.name ?? c
        }
        return code
    }
}

private func genderLabel(_ value: String) -> String {
    switch value {
    case "male": return L("ob_gender_male")
    case "female": return L("ob_gender_female")
    default: return value
    }
}

/// O-64: the vehicle kinds the sign-up offers, from a body type.
private func vehicleKind(_ body: String?) -> String {
    switch body {
    case "motorcycle": return "motorcycle"
    case "bus", "minibus": return "bus"
    case "boat": return "boat"
    case "yacht": return "yacht"
    default: return "car"
    }
}

private func bodyLabel(_ value: String) -> String {
    switch value {
    case "sedan": return L("body_sedan")
    case "suv": return L("body_suv")
    case "van": return L("body_van")
    case "minibus": return L("body_minibus")
    case "bus": return L("body_bus")
    case "motorcycle": return L("body_motorcycle")
    case "boat": return L("body_boat")
    case "yacht": return L("body_yacht")
    default: return value
    }
}

private func idLabel(_ type: String) -> String {
    switch type {
    case "iqama": return L("ob_id_iqama")
    case "passport": return L("ob_id_passport")
    default: return L("ob_id_national_id")
    }
}

private func docStatus(_ status: String?) -> String {
    switch status {
    case "approved": return L("ob_status_approved")
    case "rejected": return L("ob_status_rejected")
    case nil: return L("ob_status_missing")
    default: return L("ob_status_pending")
    }
}

private func statusColor(_ pal: Pal, _ status: String?) -> Color {
    switch status {
    case "approved": return pal.success
    case "rejected": return pal.danger
    case nil: return pal.warning
    default: return pal.info
    }
}
