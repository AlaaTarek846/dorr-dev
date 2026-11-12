import SwiftUI

// driverApp `ui/HireScreens.kt`.
//
// Find a driver, the captain's side (docs/rides_tasks.md O-26c): requests near me to drive a rider's own car
// (where, when, how long, automatic or manual, the pay), the first to accept takes it; my jobs: call the
// rider, arrived → started → ended (the time left counts down on the phone's clock), or step back before
// starting. New requests arrive as notifications (`hire.new`) — the page reloads once on them; nothing polls.

private let Mint = Color(hex: 0x00897B)

@MainActor
struct HireJobsScreen: View {
    @Environment(\.pal) private var pal
    let onBack: () -> Void

    @State private var tab = "open"
    @State private var open: [HireJobDto]?
    @State private var mine: [HireJobDto]?
    @State private var error: String?
    @State private var busy: String?
    @State private var tick = 0
    // The time left on a job going, from the phone's clock.
    @State private var nowMs = Date()

    private var started: Bool { (mine ?? []).contains { $0.status == "started" } }

    var body: some View {
        VStack(spacing: 0) {
            ScreenHeader(L("hj_title"), onBack: onBack)
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    HStack(spacing: 8) {
                        Chip(L("hj_open_tab", open?.count ?? 0), selected: tab == "open", tint: Mint) { tab = "open" }
                        Chip(L("hj_mine_tab"), selected: tab == "mine", tint: Mint) { tab = "mine" }
                    }
                    Banner(error)
                    if let list = tab == "open" ? open : mine {
                        if list.isEmpty {
                            VStack(spacing: 0) {
                                TileIcon("person.crop.circle.badge.magnifyingglass", tint: Mint, box: 56)
                                Text(L(tab == "open" ? "hj_no_open" : "hj_no_mine")).font(.dorr(14)).foregroundStyle(pal.mut).padding(.top, 10)
                            }
                            .padding(28)
                            .frame(maxWidth: .infinity)
                        } else {
                            ForEach(Array(list.enumerated()), id: \.offset) { i, j in jobCard(j).staggerIn(i) }
                        }
                    }
                }
                .padding(16)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
        .background(pal.bg.ignoresSafeArea())
        .task(id: tick) {
            switch await DriverHireApi.open() {
            case .ok(let v, _): open = v
            case .failed(let e): error = e.displayMessage
            default: break
            }
            if let v = await DriverHireApi.mine().value { mine = v }
        }
        .onReceive(DriverLive.shared.events.receive(on: DispatchQueue.main)) { e in if e.name.hasPrefix("hire.") { tick += 1 } }
        .task(id: started) {
            while started && !Task.isCancelled {
                try? await Task.sleep(nanoseconds: 1_000_000_000)
                nowMs = Date()
            }
        }
    }

    @ViewBuilder
    private func jobCard(_ j: HireJobDto) -> some View {
        let uuid = j.uuid ?? ""
        let working = busy == uuid
        let unitText: String = L((j.unit ?? "hour") == "day" ? "hj_days" : "hj_hours")
        let gearText: String = L(j.transmission == "manual" ? "hj_manual" : "hj_automatic")
        let terms: String = "\(j.quantity ?? 1) " + unitText + " · " + gearText
        let total: Int = (j.amountMinor ?? 0) + (j.extraMinor ?? 0)
        let when: String = busDay(j.startsAt) + " · " + busTime(j.startsAt)
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                Text(money(total, j.currency)).font(.dorr(20, .black)).foregroundStyle(Mint)
                    .frame(maxWidth: .infinity, alignment: .leading)
                Text(terms)
                    .font(.dorr(12, .bold)).foregroundStyle(pal.ink)
                    .padding(.horizontal, 10).padding(.vertical, 4)
                    .background(Mint.opacity(0.12), in: Capsule())
            }
            Text(j.pickup?.address ?? "").font(.dorr(14, .semibold)).foregroundStyle(pal.ink).lineLimit(2).truncationMode(.tail).padding(.top, 6)
            Text(when).font(.dorr(12)).foregroundStyle(pal.mut)
            if let car = j.car { Text("🚗 \(car)").font(.dorr(12)).foregroundStyle(pal.mut) }
            if let note = j.note { Text("📝 \(note)").font(.dorr(12)).foregroundStyle(pal.mut) }
            if j.status == "started", let end = parseInstant(j.endsAt) {
                let left = Int(floor(end.timeIntervalSince(nowMs)))
                let s = abs(left)
                Text((left < 0 ? "+" : "") + String(format: "%d:%02d:%02d", s / 3600, s % 3600 / 60, s % 60))
                    .font(.dorr(26, .black)).foregroundStyle(left < 0 ? pal.warning : pal.success).padding(.top, 6)
            }
            if let phone = j.rider?.phone {
                HStack(spacing: 8) {
                    Chip("📞 " + (j.rider?.name ?? L("hj_rider")), tint: pal.success) { dialPhone(phone) }
                    RiderTick(verified: j.rider?.isVerified ?? false, size: 16)
                    Chip("🧭 " + L("hj_navigate"), tint: Mint) { openNavigation(lat: j.pickup?.lat ?? 0, lng: j.pickup?.lng ?? 0) }
                }
                .padding(.top, 8)
            }
            VSpace(8)
            switch j.status {
            case "open":
                PrimaryButton(L("hj_accept"), busy: working, color: Mint) { act(uuid, { await DriverHireApi.accept(uuid: uuid) }) { tab = "mine" } }
            case "accepted":
                HStack(spacing: 8) {
                    PrimaryButton(L("hj_arrived"), busy: working, color: Mint) { move(uuid, "arrived") }.frame(maxWidth: .infinity)
                    OutlineButton(L("hj_release"), color: pal.danger, fillMaxWidth: true) { act(uuid, { await DriverHireApi.release(uuid: uuid) }) }.frame(maxWidth: .infinity)
                }
            case "arrived":
                HStack(spacing: 8) {
                    PrimaryButton(L("hj_start"), busy: working, color: pal.success) { move(uuid, "started") }.frame(maxWidth: .infinity)
                    OutlineButton(L("hj_release"), color: pal.danger, fillMaxWidth: true) { act(uuid, { await DriverHireApi.release(uuid: uuid) }) }.frame(maxWidth: .infinity)
                }
            case "started":
                PrimaryButton(L("hj_finish"), busy: working) { move(uuid, "completed") }
            default:
                Text(L(j.status == "completed" ? "hj_done" : "hj_closed")).font(.dorr(12, .bold)).foregroundStyle(pal.mut)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface()
    }

    private func act(_ uuid: String, _ call: @escaping () async -> ApiResult<HireJobDto>, then: @escaping () -> Void = {}) {
        busy = uuid
        error = nil
        Task {
            switch await call() {
            case .ok: then(); tick += 1
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = nil
        }
    }

    private func move(_ uuid: String, _ to: String) { act(uuid, { await DriverHireApi.move(uuid: uuid, body: ["to": to]) }) }
}
