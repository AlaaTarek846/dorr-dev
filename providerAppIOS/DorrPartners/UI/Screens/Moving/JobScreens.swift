// providerApp `ui/screens/JobScreens.kt`
import SwiftUI

/// The establishment's jobs (C-04, `jobs.track`): the running ones, or those still waiting for a driver, soonest first. Read on open,
/// when the connection returns and on every notice — never on a timer.
@MainActor
struct JobsScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpenJob: (String) -> Void

    @State private var filter: String
    @StateObject private var paged = PagedRows<MovingRequestDto>(perPage: 20, key: { $0.uuid })

    init(startUnassigned: Bool, onBack: @escaping () -> Void, onOpenJob: @escaping (String) -> Void) {
        self.onBack = onBack
        self.onOpenJob = onOpenJob
        _filter = State(initialValue: startUnassigned ? "confirmed" : "")
    }

    var body: some View {
        Page(title: L("mv_jobs_title"), onBack: onBack, onNearEnd: { Task { await paged.loadMore() } }) {
            ChipRow(
                options: [(value: "", label: L("mv_jobs_running")), (value: "confirmed", label: L("mv_jobs_unassigned"))],
                selected: [filter],
                onToggle: { filter = $0 }
            )
            .frame(maxWidth: .infinity, alignment: .leading)
            if !paged.loaded {
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
            } else if paged.rows.isEmpty {
                EmptyState(icon: "briefcase.fill", title: L("mv_jobs_empty_title"), body: L("mv_jobs_empty_body")).padding(.top, 30)
            } else {
                ForEach(paged.rows) { job in
                    JobCard(job: job, onClick: { onOpenJob(job.uuid) }, footer: { EmptyView() })
                }
            }
            PagedFooter(paged: paged) { Task { await paged.loadMore() } }
        }
        .messageToast(paged.failure?.errorText(L("login_error_offline")))
        .task(id: "\(network.reconnectTick)|\(filter)") {
            let status = filter.isEmpty ? nil : filter
            paged.reset { page in await MovingApi.jobs(status: status, page: page) }
            await paged.reload()
        }
        .onReceive(Realtime.shared.changed) { _ in Task { await paged.reload() } }
    }
}

/// One job in a list: the route, when, its status and who drives it.
private struct JobCard<Footer: View>: View {
    @Environment(\.wa) private var wa
    let job: MovingRequestDto
    let onClick: () -> Void
    @ViewBuilder let footer: () -> Footer

    var body: some View {
        Card {
            Button(action: onClick) {
                VStack(alignment: .leading, spacing: 6) {
                    HStack {
                        Text(verbatim: routeText(job)).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                        StatusPill(text: moveStatusLabel(job.status), color: moveStatusColor(job.status))
                    }
                    Text(verbatim: dateTimeText(job.scheduledAt)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    if !who.isEmpty {
                        Text(verbatim: who.joined(separator: " · ")).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            footer()
        }
    }

    private var who: [String] {
        var out: [String] = []
        if let name = job.driver?.name {
            out.append(L("mv_driver_line", name))
        } else if job.status == "confirmed" {
            out.append(L("mv_no_driver"))
        }
        if let vehicle = job.vehicle { out.append("\u{200E}" + plateText(vehicle)) }
        return out
    }
}

/// `listOfNotNull(plateLetters, plateNumber).joinToString(" ")`.
private func plateText(_ vehicle: JobVehicleDto) -> String {
    [vehicle.plateLetters, vehicle.plateNumber].compactMap { $0 }.joined(separator: " ")
}

/// «مهامي»: the jobs handed to me as a driver, with accepting or declining a new one; the moving day itself runs on the job's page
/// (`DriverDayPanel`). Read on open, when the connection returns and on every notice.
@MainActor
struct MyJobsScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpenJob: (String) -> Void

    @State private var jobs: [MovingRequestDto]?
    @State private var error: String?
    @State private var busy = false
    @State private var declining: MovingRequestDto?

    var body: some View {
        Page(title: L("mv_my_jobs_title"), onBack: onBack) {
            if let list = jobs {
                if list.isEmpty {
                    EmptyState(icon: "box.truck.fill", title: L("mv_my_jobs_empty_title"), body: L("mv_my_jobs_empty_body")).padding(.top, 30)
                } else {
                    ForEach(list) { job in
                        JobCard(job: job, onClick: { onOpenJob(job.uuid) }, footer: {
                            // A new job waits for my answer.
                            if job.status == "assigned" && job.driverAccepted != true { answerButtons(job) }
                        })
                    }
                }
            } else if error == nil {
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
            }
        }
        .messageToast(error)
        .task(id: network.reconnectTick) { await reload() }
        .onReceive(Realtime.shared.changed) { _ in Task { await reload() } }
        .dorrDialog(isPresented: Binding(get: { declining != nil }, set: { if !$0 && !busy { declining = nil } })) {
            if let job = declining {
                ConfirmDialog(icon: "xmark.circle.fill", title: L("mv_decline_job"), message: L("mv_decline_job_message"), loading: busy,
                              confirmLabel: L("mv_decline_job"), onConfirm: { decline(job) }, onDismiss: { if !busy { declining = nil } })
            }
        }
    }

    private func answerButtons(_ job: MovingRequestDto) -> some View {
        let shape = RoundedRectangle(cornerRadius: 14, style: .continuous)
        return HStack(spacing: 10) {
            Button { accept(job) } label: {
                Text(L("mv_accept_job")).font(.dorr(14, .bold)).foregroundStyle(.white)
                    .frame(maxWidth: .infinity, minHeight: 44)
                    .background(wa.red.opacity(busy ? 0.6 : 1), in: shape)
            }
            .buttonStyle(.plain)
            .disabled(busy)
            Button { declining = job } label: {
                Text(L("mv_decline_job")).font(.dorr(14, .bold)).foregroundStyle(wa.red)
                    .frame(maxWidth: .infinity, minHeight: 44)
                    .background(wa.red.opacity(0.10), in: shape)
            }
            .buttonStyle(.plain)
            .disabled(busy)
        }
    }

    private func reload() async {
        let result = await MovingApi.myJobs()
        if let data = result.value {
            jobs = data
            error = nil
        } else {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    private func accept(_ job: MovingRequestDto) {
        busy = true
        error = nil
        Task {
            let result = await MovingApi.acceptJob(job.uuid)
            busy = false
            if result.value != nil { await reload() } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }

    private func decline(_ job: MovingRequestDto) {
        busy = true
        Task {
            let failed = await MovingApi.declineJob(job.uuid, offline: L("login_error_offline"), generic: L("login_error_generic"))
            busy = false
            declining = nil
            if let failed { error = failed } else { await reload() }
        }
    }
}

/// A job's page: for its driver the moving day on top (`DriverDayPanel`); for everyone the request with everything now that it is ours,
/// its driver and vehicle, the truck's last position from the live channel and its changes (price differences, waiting fees, new
/// times). By permission: assign a driver and a new time (`jobs.assign`), a price difference (`jobs.change_price`, or the job's own
/// driver), the answer to the customer's new time (`jobs.assign`), cancelling (`jobs.cancel`). The prices and the invoice are for
/// whoever follows the jobs (`jobs.track`): a driver alone doesn't see them (§5) — the server sends them `money: null` and refuses them
/// the invoice. The move's own channel reloads the page once on each change; nothing is asked on a timer.
@MainActor
struct JobScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var session = Session.shared
    @ObservedObject private var realtime = Realtime.shared
    let uuid: String
    let onBack: () -> Void

    @State private var move: MovingRequestDto?
    @State private var changes: [ChangeOrderDto] = []
    @State private var error: String?
    @State private var info: String?
    @State private var lastPosition: String?
    /// "" = the page; assign, change, reschedule, cancel, invoice, inventory, deliver = a page of its own.
    @State private var page = ""
    @State private var busy = false

    private var isDriver: Bool {
        guard let seat = session.membership?.uuid, let driver = move?.driver?.uuid else { return false }
        return seat == driver
    }

    var body: some View {
        ZStack {
            if !page.isEmpty, let current = move {
                subPage(current)
            } else {
                main
            }
        }
        .task(id: network.reconnectTick) { await reload() }
        .onReceive(Realtime.shared.changed) { _ in Task { await reload() } }
        .task(id: "\(uuid)-\(realtime.connected)") {
            for await event in realtime.watchMove(uuid) {
                if event.name == "moving.changed" {
                    await reload()
                } else if event.name == "moving.driver_location" {
                    position(event)
                }
            }
        }
    }

    @ViewBuilder private func subPage(_ current: MovingRequestDto) -> some View {
        let back = { page = "" }
        let done: (String) -> Void = { message in
            info = message
            page = ""
            Task { await reload() }
        }
        switch page {
        case "assign": AssignPage(move: current, onBack: back, onDone: done)
        case "change": ChangePage(move: current, onBack: back, onDone: done)
        case "reschedule": ReschedulePage(move: current, onBack: back, onDone: done)
        case "cancel": CancelPage(move: current, onBack: back, onDone: done)
        case "inventory": InventoryPage(move: current, isDriver: isDriver, onBack: back)
        case "deliver": DeliverPage(move: current, onBack: back, onDone: done)
        default: InvoicePage(move: current, onBack: back)
        }
    }

    private var main: some View {
        Page(title: L("mv_job_title"), onBack: onBack) {
            if let current = move {
                if let info {
                    Text(verbatim: info).font(.dorr(13, .semibold)).foregroundStyle(Green).frame(maxWidth: .infinity, alignment: .leading)
                }
                // The driver's moving day comes first: it is why they opened the page.
                if isDriver {
                    DriverDayPanel(
                        move: current,
                        onReload: { await reload() },
                        onOpenInventory: { page = "inventory" },
                        onOpenDeliver: { page = "deliver" },
                        onDeclined: onBack
                    )
                }
                // Price differences, waiting fees and new times: the open ones first.
                ForEach(changes) { change in
                    ChangeCard(change: change, currency: current.currencyCode, canAnswer: session.membership.can("jobs.assign"), busy: busy) { approve in
                        respond(change, approve)
                    }
                }
                RequestDetails(move: current)
                execution(current)
                actions(current)
            } else if error == nil {
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
            }
        }
        .messageToast(error)
    }

    /// Who drives it and with which vehicle, and the truck's last position while it moves.
    private func execution(_ current: MovingRequestDto) -> some View {
        Card {
            Text(L("mv_execution")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
            if let driver = current.driver {
                let accepted = current.driverAccepted == true
                HStack(spacing: 8) {
                    AvatarImage(url: driver.avatar, size: 34)
                    Text(verbatim: driver.name ?? "").font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                    StatusPill(text: L(accepted ? "mv_driver_accepted" : "mv_driver_waiting"), color: accepted ? Green : Amber)
                }
            } else {
                Text(L("mv_no_driver")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
            }
            if let vehicle = current.vehicle {
                InfoLine(L("mv_offer_vehicle"), "\u{200E}" + plateText(vehicle) + (vehicle.make.map { " · " + $0 } ?? ""))
            }
            InfoLine(L("mv_last_position"), lastPosition)
        }
    }

    @ViewBuilder private func actions(_ current: MovingRequestDto) -> some View {
        let seat = session.membership
        let seesMoney = seat.can("jobs.track")
        let status = current.status ?? ""
        let beforeMoving = status == "confirmed" || status == "assigned"
        let running = ["confirmed", "assigned", "driver_on_way", "arrived", "loading", "in_transit", "unloading"].contains(status)
        if seesMoney, let money = current.money {
            let currency = current.currencyCode
            Card {
                InfoLine(L("mv_price"), money.priceMinor.map { formatMinor($0, currency) })
                InfoLine(L("mv_tax"), money.taxMinor.flatMap { $0 > 0 ? formatMinor($0, currency) : nil })
                InfoLine(L("mv_total"), money.totalMinor.map { formatMinor($0, currency) })
            }
        }
        // The pieces for whoever follows the job; the driver has them in their panel while they work on them.
        if seesMoney && !isDriver && ["loading", "in_transit", "unloading", "delivered", "completed", "disputed"].contains(status) {
            RowCard(icon: "shippingbox.fill", title: L("day_inventory"), subtitle: nil, onClick: { page = "inventory" })
        }
        if seat.can("jobs.assign") && beforeMoving {
            RowCard(icon: "person.crop.circle.badge.checkmark", title: L("mv_assign"), subtitle: L("mv_assign_sub"), onClick: { page = "assign" })
        }
        if (seat.can("jobs.change_price") || isDriver) && running {
            RowCard(icon: "banknote.fill", title: L("mv_change"), subtitle: L("mv_change_sub"), onClick: { page = "change" })
        }
        if seat.can("jobs.assign") && beforeMoving {
            RowCard(icon: "clock.fill", title: L("mv_reschedule"), subtitle: L("mv_reschedule_sub"), onClick: { page = "reschedule" })
        }
        if seesMoney && current.money?.priceMinor != nil {
            RowCard(icon: "doc.text.fill", title: L("mv_invoice"), subtitle: nil, onClick: { page = "invoice" })
        }
        if seat.can("jobs.cancel") && running {
            RowCard(icon: "xmark.circle.fill", title: L("mv_cancel"), subtitle: L("mv_cancel_sub"), onClick: { page = "cancel" })
        }
    }

    private func position(_ event: LiveEvent) {
        guard let lat = event.data?["lat"]?.str.flatMap({ Double($0) }), let lng = event.data?["lng"]?.str.flatMap({ Double($0) }) else { return }
        let at = dateTimeText(event.data?["at"]?.str)
        lastPosition = coordinatesText(lat, lng) + (at.isEmpty ? "" : " · " + at)
    }

    private func reload() async {
        let result = await MovingApi.request(uuid)
        if let data = result.value {
            move = data
            error = nil
        } else {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
        if let data = await MovingApi.changes(uuid).value { changes = data }
    }

    private func respond(_ change: ChangeOrderDto, _ approve: Bool) {
        busy = true
        error = nil
        Task {
            let result = await MovingApi.respond(change.uuid, approve: approve)
            busy = false
            if result.value != nil {
                info = L("mv_change_answered")
                await reload()
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}

/// One change of the job: what it is, its amount or new time, why, where it stands; the customer's new time can be answered here.
private struct ChangeCard: View {
    @Environment(\.wa) private var wa
    let change: ChangeOrderDto
    let currency: String?
    let canAnswer: Bool
    let busy: Bool
    let onAnswer: (Bool) -> Void

    private var fromCustomer: Bool { change.proposedBy == "user" }
    private var open: Bool { change.status == "pending" }

    private var state: (text: String, color: Color) {
        if open && fromCustomer { return (L("mv_change_waiting_you"), Amber) }
        if open { return (L("mv_change_waiting_customer"), Amber) }
        switch change.status {
        case "awaiting_payment": return (L("mv_change_waiting_payment"), Amber)
        case "approved": return (L("mv_change_approved"), Green)
        case "rejected": return (L("mv_change_rejected"), Red)
        default: return (change.status ?? "", Slate)
        }
    }

    private var titleKey: String {
        if change.kind == "waiting" { return "mv_change_kind_waiting" }
        if change.kind == "reschedule" { return fromCustomer ? "mv_new_time_title" : "mv_reschedule" }
        return "mv_change"
    }

    /// A waiting fee's reason is `waiting:{minutes}`; any other is the words of whoever asked.
    private var minutes: Int? {
        guard change.kind == "waiting", let reason = change.reason else { return nil }
        return Int(reason.replacingOccurrences(of: "waiting:", with: ""))
    }

    var body: some View {
        Card {
            HStack(spacing: 8) {
                Image(systemName: change.kind == "reschedule" ? "calendar.badge.clock" : "banknote.fill").font(.system(size: 18, weight: .semibold))
                    .foregroundStyle(open ? Amber : wa.settingsMut).frame(width: 22)
                Text(L(titleKey)).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                StatusPill(text: state.text, color: state.color)
            }
            InfoLine(L("mv_change_amount_line"), change.priceDeltaMinor.map { formatMinor($0, currency) })
            InfoLine(L("mv_change_new_time"), dateTimeText(change.newScheduledAt))
            InfoLine(L("mv_workers"), change.workersCount.map { String($0) })
            InfoLine(L("mv_trips"), change.tripsCount.map { String($0) })
            if let minutes {
                Text(L("mv_change_waited", minutes)).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            } else if change.kind != "waiting", let reason = notBlank(change.reason) {
                Text(L("pd_reason", reason)).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            }
            if open && fromCustomer && canAnswer {
                Text(L("mv_new_time_body")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                HStack(spacing: 20) {
                    Button { onAnswer(true) } label: { Text(L("mv_approve")).font(.dorr(14, .bold)).foregroundStyle(Green) }
                        .buttonStyle(.plain).disabled(busy)
                    Button { onAnswer(false) } label: { Text(L("mv_refuse")).font(.dorr(14, .bold)).foregroundStyle(Red) }
                        .buttonStyle(.plain).disabled(busy)
                }
            }
        }
    }
}

/// Handing the job to a driver: the drivers of the job's branch (`moving/drivers?branch_id=`), each with how many jobs they hold; one
/// who can't be chosen says why (their licence…). Optionally another approved vehicle: replacing the offer's vehicle needs a reason.
@MainActor
private struct AssignPage: View {
    @Environment(\.wa) private var wa
    let move: MovingRequestDto
    let onBack: () -> Void
    let onDone: (String) -> Void

    @State private var drivers: [DriverOptionDto]?
    @State private var vehicles: [VehicleDto] = []
    @State private var driverUuid: String?
    @State private var vehicleUuid: String?
    @State private var reason = ""
    @State private var busy = false
    @State private var error: String?

    init(move: MovingRequestDto, onBack: @escaping () -> Void, onDone: @escaping (String) -> Void) {
        self.move = move
        self.onBack = onBack
        self.onDone = onDone
        _driverUuid = State(initialValue: move.driver?.uuid)
        _vehicleUuid = State(initialValue: move.vehicle?.uuid)
    }

    private var currentVehicle: String? { move.vehicle?.uuid }
    private var replacing: Bool { vehicleUuid != nil && currentVehicle != nil && vehicleUuid != currentVehicle }
    private var ready: Bool { driverUuid != nil && (!replacing || !reason.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty) }

    var body: some View {
        Page(title: L("mv_assign"), onBack: onBack) {
            FormIntro(icon: "person.crop.circle.badge.checkmark", title: L("mv_assign"), text: L("mv_assign_body"))
            FieldLabel(text: L("mv_driver")).frame(maxWidth: .infinity, alignment: .leading)
            if let list = drivers {
                if list.isEmpty {
                    Text(L("mv_no_drivers")).font(.dorr(13)).foregroundStyle(Red).frame(maxWidth: .infinity, alignment: .leading)
                } else {
                    Card {
                        ForEach(list) { driver in
                            PickRow(
                                selected: driverUuid == driver.uuid,
                                title: notBlank(driver.name) ?? ("\u{200E}" + (driver.phone ?? "")),
                                subtitle: driver.problem.map { driverProblemLabel($0) } ?? L("mv_driver_busy_jobs", driver.busyJobs ?? 0),
                                enabled: driver.problem == nil
                            ) { driverUuid = driver.uuid }
                        }
                    }
                }
            } else if error == nil {
                ProgressView().tint(wa.red).frame(width: 24, height: 24)
            }
            if !vehicles.isEmpty {
                FieldLabel(text: L("mv_offer_vehicle")).frame(maxWidth: .infinity, alignment: .leading)
                Card {
                    ForEach(vehicles) { vehicle in
                        PickRow(
                            selected: vehicleUuid == vehicle.uuid,
                            title: (vehicle.vehicleType?.name ?? L("vh_vehicle")) + " · \u{200E}" + vehicle.plate(),
                            subtitle: vehicle.uuid == currentVehicle ? L("mv_offer_vehicle_current") : nil
                        ) { vehicleUuid = vehicle.uuid }
                    }
                }
            }
            if replacing {
                DorrTextField(value: $reason.profileTake(500), label: L("mv_replace_reason"), icon: "text.alignleft", singleLine: false, minLines: 2)
                Text(L("mv_replace_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
            }
            SettingsPrimaryButton(text: L("mv_assign"), enabled: ready, loading: busy, action: assign)
        }
        .messageToast(error)
        .task {
            let result = await MovingApi.drivers(branchId: move.branchId)
            if let data = result.value { drivers = data } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
            if let list = await ManageApi.vehicles().value { vehicles = list.filter { $0.status == "approved" } }
        }
    }

    private func assign() {
        guard let driver = driverUuid else { return }
        busy = true
        error = nil
        let body = AssignBody(driverUuid: driver, vehicleUuid: vehicleUuid == currentVehicle ? nil : vehicleUuid, reason: trimmedOrNil(reason))
        Task {
            let result = await MovingApi.assign(move.uuid, body)
            busy = false
            if result.value != nil { onDone(L("mv_assigned_ok")) } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }

    /// Why a driver can't be given a job (WorkGate::driverProblem).
    private func driverProblemLabel(_ problem: String) -> String {
        switch problem {
        case "driver_licence": return L("mv_problem_licence")
        case "driver_inactive": return L("mv_problem_inactive")
        case "not_a_driver": return L("mv_problem_not_driver")
        default: return problem
        }
    }
}

/// A choice among a few (a radio mark, a title and a line under it); one that can't be chosen is greyed, its line in red saying why.
struct PickRow: View {
    @Environment(\.wa) private var wa
    let selected: Bool
    let title: String
    let subtitle: String?
    var enabled = true
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(spacing: 8) {
                Image(systemName: selected ? "largecircle.fill.circle" : "circle").font(.system(size: 18))
                    .foregroundStyle(enabled ? wa.red : wa.settingsMut.opacity(0.4)).frame(width: 22, height: 22)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: title).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk.opacity(enabled ? 1 : 0.5))
                    if let subtitle {
                        Text(verbatim: subtitle).font(.dorr(11)).foregroundStyle(enabled ? wa.settingsMut : Red)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(.vertical, 4)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

/// A price difference for more (or less) load, trips or workers; the customer approves it and pays the difference (B-13).
@MainActor
private struct ChangePage: View {
    let move: MovingRequestDto
    let onBack: () -> Void
    let onDone: (String) -> Void

    @State private var increase = true
    @State private var amount = ""
    @State private var workers = ""
    @State private var trips = ""
    @State private var reason = ""
    @State private var busy = false
    @State private var error: String?

    private var minor: Int? { parseMinor(amount) }
    private var ready: Bool { minor != nil && !reason.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }

    var body: some View {
        Page(title: L("mv_change"), onBack: onBack) {
            FormIntro(icon: "banknote.fill", title: L("mv_change"), text: L("mv_change_body"))
            ChipRow(
                options: [(value: "up", label: L("mv_change_up")), (value: "down", label: L("mv_change_down"))],
                selected: [increase ? "up" : "down"],
                onToggle: { increase = $0 == "up" }
            )
            .frame(maxWidth: .infinity, alignment: .leading)
            FormGroup {
                DorrTextField(value: $amount.profileMap(decimalInput(12)), label: L("mv_change_amount", move.currencyCode ?? ""), icon: "banknote.fill",
                              keyboard: .decimalPad, error: !amount.trimmingCharacters(in: .whitespaces).isEmpty && minor == nil)
                HStack(alignment: .top, spacing: 10) {
                    DorrTextField(value: $workers.profileDigits(2), label: L("mv_workers"), placeholder: L("onb_optional"), icon: "person.3.fill", keyboard: .numberPad)
                    DorrTextField(value: $trips.profileDigits(2), label: L("mv_trips"), placeholder: L("onb_optional"), icon: "repeat", keyboard: .numberPad)
                }
                DorrTextField(value: $reason.profileTake(500), label: L("mv_reason"), icon: "text.alignleft", singleLine: false, minLines: 2)
            }
            SettingsPrimaryButton(text: L("mv_send_to_customer"), enabled: ready, loading: busy, action: send)
        }
        .messageToast(error)
    }

    private func send() {
        guard let value = minor else { return }
        busy = true
        error = nil
        let body = ChangeBody(priceDeltaMinor: increase ? value : -value, workersCount: Int(workers), tripsCount: Int(trips),
                              reason: reason.trimmingCharacters(in: .whitespacesAndNewlines))
        Task {
            let result = await MovingApi.proposeChange(move.uuid, body)
            busy = false
            if result.value != nil { onDone(L("mv_change_sent")) } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}

/// A new time for the job, with the reason; the customer approves or refuses it.
@MainActor
private struct ReschedulePage: View {
    let move: MovingRequestDto
    let onBack: () -> Void
    let onDone: (String) -> Void

    @State private var date = ""
    @State private var time = ""
    @State private var reason = ""
    @State private var busy = false
    @State private var error: String?

    private var iso: String? { isoAt(date, time) }
    private var ready: Bool { iso != nil && !reason.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }

    var body: some View {
        Page(title: L("mv_reschedule"), onBack: onBack) {
            FormIntro(icon: "clock.fill", title: L("mv_reschedule"), text: L("mv_reschedule_body"))
            Card { InfoLine(L("mv_when_now"), dateTimeText(move.scheduledAt)) }
            HStack(alignment: .top, spacing: 10) {
                DateField(value: $date, label: L("mv_date"))
                TimeField(value: $time, label: L("mv_time"))
            }
            DorrTextField(value: $reason.profileTake(500), label: L("mv_reason"), icon: "text.alignleft", singleLine: false, minLines: 2)
            SettingsPrimaryButton(text: L("mv_send_to_customer"), enabled: ready, loading: busy, action: send)
        }
        .messageToast(error)
    }

    private func send() {
        guard let at = iso else { return }
        busy = true
        error = nil
        let body = RescheduleBody(scheduledAt: at, reason: reason.trimmingCharacters(in: .whitespacesAndNewlines))
        Task {
            let result = await MovingApi.reschedule(move.uuid, body)
            busy = false
            if result.value != nil { onDone(L("mv_change_sent")) } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}

/// The provider's cancel reasons (config `moving.cancel_reasons.provider`); the last three open an exception Dorr decides.
private let cancelReasons = ["vehicle_breakdown", "driver_unavailable", "other", "load_differs", "customer_absent", "unsafe_place"]
private let exceptionReasons: Set<String> = ["load_differs", "customer_absent", "unsafe_place"]

private func cancelReasonLabel(_ reason: String) -> String {
    switch reason {
    case "vehicle_breakdown": return L("mv_cr_vehicle_breakdown")
    case "driver_unavailable": return L("mv_cr_driver_unavailable")
    case "other": return L("mv_cr_other")
    case "load_differs": return L("mv_cr_load_differs")
    case "customer_absent": return L("mv_cr_customer_absent")
    case "unsafe_place": return L("mv_cr_unsafe_place")
    default: return reason
    }
}

/// Cancelling the job with a reason: a reason on the establishment's side ends it and the customer gets the money back; a reason on the
/// customer's side (the load differs, the customer is absent, the place is unsafe) does not cancel — it opens an exception for Dorr to
/// decide. Said before the tap.
@MainActor
private struct CancelPage: View {
    @Environment(\.wa) private var wa
    let move: MovingRequestDto
    let onBack: () -> Void
    let onDone: (String) -> Void

    @State private var reason: String?
    @State private var details = ""
    @State private var confirming = false
    @State private var busy = false
    @State private var error: String?

    private var exception: Bool { reason.map { exceptionReasons.contains($0) } ?? false }

    var body: some View {
        Page(title: L("mv_cancel"), onBack: onBack) {
            FormIntro(icon: "xmark.circle.fill", title: L("mv_cancel"), text: L("mv_cancel_body"))
            Card {
                ForEach(cancelReasons, id: \.self) { key in
                    PickRow(selected: reason == key, title: cancelReasonLabel(key),
                            subtitle: exceptionReasons.contains(key) ? L("mv_cr_exception_hint") : nil) { reason = key }
                }
            }
            if reason != nil {
                let tone = exception ? Amber : Red
                HStack(alignment: .top, spacing: 8) {
                    Image(systemName: "exclamationmark.triangle.fill").font(.system(size: 16, weight: .semibold)).foregroundStyle(tone).frame(width: 20)
                    Text(L(exception ? "mv_cancel_exception_note" : "mv_cancel_refund_note")).font(.dorr(12)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(12)
                .background(tone.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            DorrTextField(value: $details.profileTake(1000), label: L("mv_details"), placeholder: L("onb_optional"), icon: "text.alignleft", singleLine: false, minLines: 3)
            SettingsPrimaryButton(text: L(exception ? "mv_open_exception" : "mv_cancel"), enabled: reason != nil, loading: busy) { confirming = true }
        }
        .messageToast(error)
        .dorrDialog(isPresented: $confirming) {
            ConfirmDialog(icon: "xmark.circle.fill", title: L(exception ? "mv_open_exception" : "mv_cancel"),
                          message: L(exception ? "mv_cancel_exception_note" : "mv_cancel_refund_note"), loading: busy,
                          confirmLabel: L("common_yes"), onConfirm: send, onDismiss: { if !busy { confirming = false } })
        }
    }

    private func send() {
        guard let chosen = reason else { return }
        busy = true
        error = nil
        let body = CancelBody(reason: chosen, details: trimmedOrNil(details))
        Task {
            // The answer's data is empty or a small object: `done` reads only whether it went through.
            let failed = await MovingApi.cancelDone(move.uuid, body, offline: L("login_error_offline"), generic: L("login_error_generic"))
            busy = false
            confirming = false
            if let failed { error = failed } else { onDone(L(exceptionReasons.contains(chosen) ? "mv_exception_ok" : "mv_cancelled_ok")) }
        }
    }
}

/// The job's invoice (B-06): the agreed price, approved differences, tax, total, refunds, Dorr's commission and what the establishment gets.
@MainActor
private struct InvoicePage: View {
    @Environment(\.wa) private var wa
    let move: MovingRequestDto
    let onBack: () -> Void

    @State private var invoice: InvoiceDto?
    @State private var error: String?

    var body: some View {
        Page(title: L("mv_invoice"), onBack: onBack) {
            if let inv = invoice {
                let c = inv.currencyCode
                Card {
                    InfoLine(L("mv_invoice_number"), inv.number)
                    InfoLine(L("mv_invoice_date"), dateTimeText(inv.issuedAt))
                }
                Card {
                    ForEach(Array((inv.lines ?? []).enumerated()), id: \.offset) { _, line in
                        InfoLine(lineLabel(line), formatMinor(line.amountMinor ?? 0, c))
                    }
                    InfoLine(L("mv_price"), formatMinor(inv.priceMinor ?? 0, c))
                    if (inv.taxMinor ?? 0) > 0 { InfoLine(L("mv_tax"), formatMinor(inv.taxMinor ?? 0, c)) }
                    InfoLine(L("mv_total"), formatMinor(inv.totalMinor ?? 0, c))
                    if (inv.refundedMinor ?? 0) > 0 { InfoLine(L("mv_refunded"), formatMinor(inv.refundedMinor ?? 0, c)) }
                    if let commission = inv.commissionMinor { InfoLine(L("mv_commission"), formatMinor(commission, c)) }
                    if let net = inv.providerNetMinor { InfoLine(L("mv_net"), formatMinor(net, c)) }
                }
            } else if error == nil {
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
            }
        }
        .messageToast(error)
        .task(id: move.uuid) {
            let result = await MovingApi.invoice(move.uuid)
            if let data = result.value { invoice = data } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }

    private func lineLabel(_ line: InvoiceLineDto) -> String {
        let name: String
        switch line.key {
        case "moving": name = L("mv_line_moving")
        case "waiting": name = L("mv_line_waiting")
        default: name = L("mv_line_change")
        }
        return name + (notBlank(line.reason).map { " (" + $0 + ")" } ?? "")
    }
}
