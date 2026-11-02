import SwiftUI

// androidApp `rides/RidesCases.kt` — my complaints and disputes (R-133..R-140): a complaint about the captain, the car,
// the price, the payment, safety or privacy; or an objection to a waiting, cancellation, no-show fee or the fare —
// each a case of its own, apart from general support. The page shows what the system recorded for the ride (R-137),
// the team's decision with its reason and any refund (R-139), and one request for another look where the country
// allows it (R-140). The team's replies reach the rider as notifications; nothing here asks the server on a timer.

struct CasesPage: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let onClose: () -> Void
    @State private var options: RideCaseOptionsDto?
    @State private var cases: [RideCaseDto]?
    @State private var openUuid: String?
    @State private var creating = false
    @State private var error: String?

    private func back() {
        if openUuid != nil {
            openUuid = nil
        } else if creating {
            creating = false
            state.caseStartNew = false
            state.caseRideUuid = nil
        } else {
            onClose()
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 10) {
                RideRoundButton(icon: "arrow.backward", label: L("common_back")) { back() }
                Text(L("rd_cases_title")).font(.dorr(20, .heavy)).foregroundStyle(wa.settingsInk)
                Spacer()
                if openUuid == nil && !creating && options?.available == true { RideRoundButton(icon: "plus") { creating = true } }
            }
            .padding(12)
            if let uuid = openUuid {
                CaseDetail(uuid: uuid)
            } else if creating {
                NewCase(state: state, options: options) { uuid in
                    creating = false
                    state.caseStartNew = false
                    state.caseRideUuid = nil
                    openUuid = uuid
                }
            } else if cases == nil && error == nil {
                ProgressView().tint(wa.accent).frame(maxWidth: .infinity, maxHeight: .infinity)
            } else if options?.available == false {
                hint(L("rd_cases_unavailable"))
            } else if (cases ?? []).isEmpty {
                hint(error ?? L("rd_cases_empty"))
            } else {
                ScrollView {
                    VStack(spacing: 10) { ForEach(Array((cases ?? []).enumerated()), id: \.offset) { _, c in CaseRow(c: c) { openUuid = c.uuid } } }.padding(16)
                }
            }
        }
        .background(wa.settingsBackground.ignoresSafeArea())
        .onAppear { creating = state.caseStartNew }
        // Back from a case (or the form) shows the list as the server has it now.
        .task(id: "\(openUuid ?? "")|\(creating)") {
            if openUuid == nil && !creating {
                if let o = try? await RidesApi.caseOptions().data { options = o }
                do { cases = try await RidesApi.cases().data ?? [] } catch { self.error = error.apiFailure.message ?? L("rd_cases_failed") }
            } else if options == nil {
                options = try? await RidesApi.caseOptions().data
            }
        }
    }

    private func hint(_ text: String) -> some View {
        VStack(spacing: 10) {
            Image(systemName: "headphones").font(.system(size: 30)).foregroundStyle(wa.accent).frame(width: 72, height: 72).background(wa.accent.opacity(0.1), in: Circle())
            Text(verbatim: text).font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
        }
        .padding(32)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

private func caseColor(_ status: String?, _ wa: WaPalette) -> Color {
    switch status {
    case "confirmed", "partially_confirmed": return AppColors.success
    case "rejected": return AppColors.danger
    case "needs_info": return AppColors.warning
    default: return wa.accent
    }
}

private func caseTitle(_ c: RideCaseDto) -> String {
    let kind = c.kindLabel ?? ""
    if let cat = c.categoryLabel, !cat.isEmpty, cat != kind { return "\(kind) · \(cat)" }
    return kind
}

private struct CaseCard<Content: View>: View {
    @Environment(\.wa) private var wa
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 0) { content() }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
    }
}

private struct CaseRow: View {
    @Environment(\.wa) private var wa
    let c: RideCaseDto
    let action: () -> Void

    var body: some View {
        let color = caseColor(c.status, wa)
        Button(action: action) {
            VStack(alignment: .leading, spacing: 0) {
                HStack {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: caseTitle(c)).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: [c.number, c.ride?.number].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                    Spacer(minLength: 0)
                    // The state in words (a colour only helps).
                    Text(verbatim: c.statusLabel ?? "").font(.dorr(11, .bold)).foregroundStyle(color).padding(.horizontal, 10).padding(.vertical, 4).background(color.opacity(0.12), in: Capsule())
                }
                if let m = c.disputedMinor { Text(verbatim: rideMoney(m, c.currency)).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).padding(.top, 6) }
            }
            .padding(14)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(WaPressStyle())
    }
}

private struct CasePill: View {
    @Environment(\.wa) private var wa
    let label: String
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(verbatim: label).font(.dorr(13, .semibold)).foregroundStyle(selected ? .white : wa.settingsInk)
                .padding(.horizontal, 14).padding(.vertical, 8).background(selected ? wa.accent : wa.settingsBackground, in: Capsule())
        }
        .buttonStyle(.plain)
    }
}

/// Open a complaint or a dispute — about the ride it came from, or (a complaint) about nothing in particular.
private struct NewCase: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let options: RideCaseOptionsDto?
    let onCreated: (String) -> Void
    @State private var rides: [RideHistoryItemDto]?
    @State private var kind: String?
    @State private var category: String?
    @State private var rideUuid: String?
    @State private var summary = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        ScrollView {
            VStack(spacing: 12) {
                CaseCard {
                    Text(L("rd_cases_what")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                    FlowLayout(spacing: 8) {
                        ForEach(options?.kinds ?? [], id: \.code) { k in CasePill(label: k.label ?? k.code, selected: kind == k.code) { kind = k.code; category = nil } }
                    }
                    .padding(.top, 8)
                    Text(L(kind == "dispute" ? "rd_cases_which_charge" : "rd_cases_about")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.top, 10)
                    FlowLayout(spacing: 8) {
                        ForEach((kind == "dispute" ? options?.disputeReasons : options?.complaintCategories) ?? [], id: \.code) { o in
                            CasePill(label: o.label ?? o.code, selected: category == o.code) { category = o.code }
                        }
                    }
                    .padding(.top, 6)
                }
                CaseCard {
                    Text(verbatim: L("rd_cases_which_ride") + (kind == "complaint" ? " " + L("rd_cases_optional") : "")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                        .padding(.bottom, 8)
                    if let list = rides {
                        if list.isEmpty {
                            Text(L("rd_cases_no_rides")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                        } else {
                            ForEach(list.prefix(15)) { r in
                                let on = rideUuid == r.uuid
                                Button { rideUuid = on && kind == "complaint" ? nil : r.uuid } label: {
                                    HStack {
                                        VStack(alignment: .leading, spacing: 0) {
                                            Text(verbatim: [r.number, r.category].compactMap { $0 }.joined(separator: " · ")).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                                            Text(verbatim: [r.originAddress, r.destinationAddress].compactMap { $0 }.joined(separator: " → ")).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineLimit(1)
                                        }
                                        Spacer(minLength: 0)
                                        if let t = r.totalMinor { Text(verbatim: rideMoney(t, RideCurrencyDto(symbol: r.currency))).font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk) }
                                    }
                                    .padding(10)
                                    .background(on ? wa.accent.opacity(0.12) : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                                }
                                .buttonStyle(.plain)
                                .padding(.vertical, 3)
                            }
                        }
                    } else {
                        ProgressView().tint(wa.accent)
                    }
                }
                CaseCard {
                    DorrTextArea(value: $summary, label: L("rd_cases_summary"), minHeight: 90)
                    if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger).padding(.top, 6) }
                }
                AccentButton(text: L("rd_cases_send"),
                             enabled: kind != nil && category != nil && summary.trimmingCharacters(in: .whitespaces).count >= 3 && (kind != "dispute" || rideUuid != nil),
                             busy: busy) { send() }
            }
            .padding(16)
        }
        .onAppear {
            rideUuid = state.caseRideUuid
            kind = state.caseRideUuid != nil ? "dispute" : options?.kinds?.first?.code
        }
        .onChange(of: options) { o in if kind == nil { kind = o?.kinds?.first?.code } }
        .task { rides = (try? await RidesApi.history(tab: "past").data?.items) ?? [] }
    }

    private func send() {
        busy = true
        error = nil
        Task {
            var body: [String: JSONValue] = ["kind": .string(kind ?? ""), "category": .string(category ?? ""),
                                             "summary": .string(summary.trimmingCharacters(in: .whitespacesAndNewlines))]
            if let rideUuid { body["ride_uuid"] = .string(rideUuid) }
            do {
                if let c = try await RidesApi.openCase(body).data, let uuid = c.uuid { onCreated(uuid) }
            } catch {
                self.error = error.apiFailure.message
            }
            busy = false
        }
    }
}

private struct CaseDetail: View {
    @Environment(\.wa) private var wa
    let uuid: String
    @State private var c: RideCaseDto?
    @State private var text = ""
    @State private var appealing = false
    @State private var note = ""
    @State private var busy = false
    @State private var error: String?

    private func act(_ call: @escaping () async throws -> RideCaseDto?) {
        busy = true
        error = nil
        Task {
            do {
                if let updated = try await call() {
                    c = updated
                    text = ""
                    note = ""
                    appealing = false
                }
            } catch {
                self.error = error.apiFailure.message
            }
            busy = false
        }
    }

    var body: some View {
        Group {
            if let d = c { content(d) } else { ProgressView().tint(wa.accent).frame(maxWidth: .infinity, maxHeight: .infinity) }
        }
        .task(id: uuid) { if let fresh = try? await RidesApi.caseOf(uuid).data { c = fresh } }
    }

    @ViewBuilder
    private func content(_ d: RideCaseDto) -> some View {
        let color = caseColor(d.status, wa)
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                CaseCard {
                    HStack {
                        VStack(alignment: .leading, spacing: 0) {
                            Text(verbatim: caseTitle(d)).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                            Text(verbatim: [d.number, d.ride?.number].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        }
                        Spacer(minLength: 0)
                        Text(verbatim: d.statusLabel ?? "").font(.dorr(12, .bold)).foregroundStyle(color).padding(.horizontal, 12).padding(.vertical, 4).background(color.opacity(0.12), in: Capsule())
                    }
                    if let m = d.disputedMinor { Text(verbatim: rideMoney(m, d.currency)).font(.dorr(22, .heavy)).foregroundStyle(wa.settingsInk).padding(.top, 8) }
                    if let s = d.summary { Text(verbatim: s).font(.dorr(13)).foregroundStyle(wa.settingsInk).padding(.top, 6) }
                }
                if let dec = d.decision {
                    CaseCard {
                        Text(L("rd_cases_decision")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: d.statusLabel ?? "").font(.dorr(14, .semibold)).foregroundStyle(color).padding(.top, 4)
                        if let r = dec.reason { Text(verbatim: r).font(.dorr(14)).foregroundStyle(wa.settingsInk).padding(.top, 6) }
                        // R-139: the money effect, plainly.
                        if let refund = dec.refundMinor, refund > 0 {
                            Text(verbatim: L(dec.refundApplied == true ? "rd_cases_refund_paid" : "rd_cases_refund_decided", rideMoney(refund, d.currency)))
                                .font(.dorr(13, .semibold)).foregroundStyle(AppColors.success).padding(.top, 6)
                        }
                    }
                }
                if d.appealedAt != nil && d.decision == nil { Text(L("rd_cases_appealed")).font(.dorr(13, .semibold)).foregroundStyle(AppColors.warning) }
                if let e = d.evidence { evidence(e, d) }
                CaseCard {
                    Text(L("rd_cases_conversation")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                    if (d.messages ?? []).isEmpty { Text(L("rd_cases_no_messages")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.top, 4) }
                    ForEach(Array((d.messages ?? []).enumerated()), id: \.offset) { _, m in
                        let mine = m.from == "me"
                        VStack(alignment: mine ? .trailing : .leading, spacing: 2) {
                            Text(verbatim: m.body ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk)
                                .padding(.horizontal, 12).padding(.vertical, 8)
                                .background(mine ? wa.accent.opacity(0.12) : wa.settingsBackground, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                            if !mine { Text(L("rd_cases_team")).font(.dorr(10)).foregroundStyle(wa.settingsMut) }
                        }
                        .frame(maxWidth: .infinity, alignment: mine ? .trailing : .leading)
                        .padding(.top, 8)
                    }
                    if d.canMessage == true {
                        DorrTextArea(value: $text, label: L("rd_cases_write"), minHeight: 70).padding(.top, 10)
                        AccentButton(text: L("rd_cases_reply"), enabled: !text.trimmingCharacters(in: .whitespaces).isEmpty, busy: busy) {
                            let body = text.trimmingCharacters(in: .whitespacesAndNewlines)
                            act { try await RidesApi.caseMessage(uuid, ["body": .string(body)]).data }
                        }
                        .padding(.top, 8)
                    }
                }
                // R-140: another look — only where the country allows it, once.
                if d.canAppeal == true {
                    CaseCard {
                        if !appealing {
                            Text(L("rd_cases_appeal_hint")).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                            AccentButton(text: L("rd_cases_appeal")) { appealing = true }.padding(.top, 8)
                        } else {
                            DorrTextArea(value: $note, label: L("rd_cases_appeal_why"), minHeight: 90)
                            AccentButton(text: L("rd_cases_appeal_send"), enabled: note.trimmingCharacters(in: .whitespaces).count >= 3, busy: busy) {
                                let body = note.trimmingCharacters(in: .whitespacesAndNewlines)
                                act { try await RidesApi.appealCase(uuid, ["note": .string(body)]).data }
                            }
                            .padding(.top, 8)
                        }
                    }
                }
                if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger) }
            }
            .padding(16)
        }
    }

    private func evidence(_ e: RideCaseEvidenceDto, _ d: RideCaseDto) -> some View {
        CaseCard {
            Text(L("rd_cases_evidence")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 6)
            ForEach(Array((e.timeline ?? []).enumerated()), id: \.offset) { _, m in
                HStack {
                    Text(verbatim: milestoneWord(m.event)).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                    Spacer(minLength: 4)
                    if let dm = m.distanceToPickupM { Text(verbatim: L("rd_cases_from_pickup", dm)).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
                    Text(verbatim: parseRideTime(m.at).map { rideFormatterHM.string(from: $0) } ?? "").font(.dorr(12)).foregroundStyle(wa.settingsInk)
                }
                .padding(.vertical, 2)
            }
            if let w = e.waiting {
                Text(verbatim: L("rd_cases_waiting", (w.pickupS ?? 0) / 60, (w.freeS ?? 0) / 60)).font(.dorr(13)).foregroundStyle(wa.settingsInk).padding(.top, 6)
            }
            if let r = e.route { Text(verbatim: L("rd_cases_route", km(r.drivenM), km(r.plannedM))).font(.dorr(13)).foregroundStyle(wa.settingsInk) }
            ForEach(Array((e.lines ?? []).enumerated()), id: \.offset) { _, l in
                HStack {
                    Text(verbatim: l.code ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    Spacer()
                    Text(verbatim: rideMoney(l.amountMinor, d.currency)).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk)
                }
                .padding(.top, 2)
            }
        }
    }
}

private let rideFormatterHM: DateFormatter = {
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.dateFormat = "HH:mm"
    return f
}()

private func milestoneWord(_ event: String?) -> String {
    switch event {
    case "accepted": return L("rd_cases_ev_accepted")
    case "arrived": return L("rd_cases_ev_arrived")
    case "started": return L("rd_cases_ev_started")
    case "completed": return L("rd_cases_ev_completed")
    case "canceled": return L("rd_cases_ev_canceled")
    default: return L("rd_cases_ev_no_show")
    }
}

private func km(_ m: Int?) -> String { m.map { String(format: "%.1f", Double($0) / 1000) } ?? "—" }
