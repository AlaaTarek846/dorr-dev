import SwiftUI

// freelanceApp `ui/screens/ServiceOrderScreens.kt`.

private let ORDER_TABS = ["awaiting", "active", "completed", "ended", "monthly"]

/// FL-L04, the orders clients placed on my services: four lists (waiting for my answer first, in progress, completed, ended), each card
/// with the service, the level, the client, what I get and — while it waits — the time left to answer by the phone's clock. Read when
/// the page opens, when the list changes, when the connection returns and when a notice arrives; pages fill in while scrolling.
struct ServiceOrdersScreen: View {
    let onBack: () -> Void
    let onOpenOrder: (String) -> Void
    var embedded: Bool = false

    @EnvironmentObject private var network: NetworkMonitor
    @State private var tab = "awaiting"
    @State private var pagedTab: String?
    @StateObject private var paged = PagedRows<ServiceOrderDto>(perPage: 15, key: { $0.uuid ?? "" })
    // FL-L05: the monthly subscriptions to my services (active first).
    @StateObject private var subs = PagedRows<ServiceSubscriptionDto>(perPage: 15, key: { $0.uuid ?? "" }) { page in
        await ServicesApi.subscriptions(page: page, perPage: 15)
    }

    private var monthly: Bool { tab == "monthly" }

    private func reload() async {
        if monthly {
            await subs.reload()
        } else {
            // `remember(tab)`: another tab is another list, which starts from its skeleton.
            if pagedTab != tab {
                let status = tab
                pagedTab = tab
                paged.reset { page in await ServicesApi.orders(status: status, page: page, perPage: 15) }
            }
            await paged.reload()
        }
    }

    private struct LoadKey: Equatable { let tab: String; let tick: Int }

    var body: some View {
        Page(title: L("svo_title"), onBack: onBack, onNearEnd: { Task { if monthly { await subs.loadMore() } else { await paged.loadMore() } } }, embedded: embedded) {
            let waiting = tab == "awaiting" && !paged.rows.isEmpty
            FilterChips(options: ORDER_TABS.map { FilterOption(key: $0, label: orderTabLabel($0), dot: $0 == "awaiting" && waiting) }, selected: tab, onSelect: { tab = $0 })
            if monthly {
                SubscriptionList(subs: subs, onOpenOrder: onOpenOrder)
            } else {
                if !paged.loaded && paged.failure == nil {
                    SkeletonList()
                } else if paged.loaded && paged.rows.isEmpty && paged.failure == nil {
                    EmptyState(icon: "tray", title: tab == "awaiting" ? L("svo_empty_awaiting") : L("svo_empty_other"), body: L("svo_empty_body"))
                        .padding(.top, 70)
                } else {
                    ForEach(paged.rows, id: \.id) { o in OrderCard(o: o) { if let id = o.uuid { onOpenOrder(id) } } }
                }
                PagedFooter(paged: paged) { Task { await paged.loadMore() } }
            }
        }
        .messageToast(monthly ? nil : paged.failure.map { $0.errorText(L("login_error_offline")) ?? L("login_error_generic") })
        .task(id: LoadKey(tab: tab, tick: network.reconnectTick)) { await reload() }
        .onReceive(Realtime.shared.changed) { Task { await reload() } }
    }
}

private func orderTabLabel(_ tab: String) -> String {
    switch tab {
    case "active": return L("svo_tab_active")
    case "completed": return L("svo_tab_completed")
    case "ended": return L("svo_tab_ended")
    case "monthly": return L("sub_tab")
    default: return L("svo_tab_awaiting")
    }
}

private func orderStatusLabel(_ status: String) -> String {
    switch status {
    case "active": return L("svo_status_active")
    case "completed": return L("svo_status_completed")
    case "declined": return L("svo_status_declined")
    case "expired": return L("svo_status_expired")
    case "cancelled": return L("svo_status_cancelled")
    default: return L("svo_status_awaiting")
    }
}

private func orderTone(_ status: String, night: Bool) -> SvcTone {
    serviceTone(status == "cancelled" || status == "expired" ? "paused" : status, night: night)
}

/// "5 h left to answer", or nil when it isn't waiting.
private func leftText(_ o: ServiceOrderDto) -> String? {
    guard (o.status ?? "awaiting") == "awaiting", let left = timeLeft(o.answerBy) else { return nil }
    if left <= 0 { return L("svo_left_ended") }
    let hours = Int(left / 3600)
    if hours >= 1 { return L("svo_left_hours", hours) }
    return L("svo_left_minutes", max(Int(left / 60), 1))
}

private struct OrderCard: View {
    @Environment(\.wa) private var wa
    let o: ServiceOrderDto
    let onClick: () -> Void

    var body: some View {
        let status = o.status ?? "awaiting"
        let tone = orderTone(status, night: wa.night)
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(alignment: .top, spacing: 10) {
                    VStack(alignment: .leading, spacing: 4) {
                        Text(verbatim: o.service?.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                        Text(verbatim: [levelLabel(o.level ?? "basic"), o.tierName].compactMap { $0 }.filter { !$0.trimmingCharacters(in: .whitespaces).isEmpty }.joined(separator: " · "))
                            .font(.dorr(12, .bold)).foregroundStyle(wa.red)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    VStack(alignment: .trailing, spacing: 4) {
                        SvcPill(text: orderStatusLabel(status), tone: tone)
                        // FL-L05: one month of a subscription.
                        if let sub = o.subscription {
                            SvcPill(text: L("sub_month_n", sub.cycle ?? 1), tone: SvcTone(ink: wa.red, fill: wa.red.opacity(0.10)))
                        }
                    }
                }
                HStack(spacing: 10) {
                    SvcInitial(name: o.client?.name)
                    Text(verbatim: o.client?.name ?? "").font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    Image(systemName: "clock").font(.system(size: 13)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: daysLabel(o.deliveryDays ?? 0)).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                }
                HStack(spacing: 0) {
                    Text(L("svo_you_get", svcMoney(o.money?.netMinor ?? 0, o.decimals ?? 2, o.currency))).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    if let addons = o.addons, !addons.isEmpty {
                        Text(verbatim: "+\(addons.count)").font(.dorr(12, .heavy)).foregroundStyle(wa.red)
                    }
                }
                .padding(.horizontal, 12)
                .padding(.vertical, 10)
                .background(wa.red.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                if let text = leftText(o) {
                    let warn = serviceTone("awaiting", night: wa.night)
                    HStack(spacing: 6) {
                        Image(systemName: "hourglass").font(.system(size: 14)).foregroundStyle(warn.ink)
                        Text(verbatim: text).font(.dorr(12, .bold)).foregroundStyle(warn.ink)
                    }
                }
            }
            .padding(14)
            .frame(maxWidth: 520, alignment: .leading)
            .settingsSurface(20)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity)
    }
}

/// One order. While it waits: the time left (and the hour it ends), the client, what was ordered (the level and what it includes, the
/// extras, the revisions and days), the client's answer to my question, the money (price, commission, what I get), what happens after
/// accepting, then Accept (asked once more) or Decline (with an optional reason). Once accepted it opens the work — the agreement screen.
struct ServiceOrderScreen: View {
    let uuid: String
    let onBack: () -> Void
    let onOpenWork: (String) -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var order: ServiceOrderDto?
    @State private var error: String?
    @State private var busy = false
    @State private var asking = false
    @State private var declining = false
    @State private var reason = ""

    private func load() async {
        let r = await ServicesApi.order(uuid)
        if let o = r.value {
            order = o
            error = nil
        } else {
            error = r.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    private func answer(_ action: String) {
        busy = true
        Task {
            let trimmed = reason.trimmingCharacters(in: .whitespacesAndNewlines)
            let r = await ServicesApi.answer(uuid, action, OrderAnswerRequest(reason: trimmed.isEmpty ? nil : trimmed))
            busy = false
            asking = false
            declining = false
            if let o = r.value {
                order = o
                await AppToast.show(action == "accept" ? L("svo_accepted") : L("svo_declined_done"))
            } else {
                error = r.errorText(L("login_error_offline")) ?? L("login_error_generic")
                await load()
            }
        }
    }

    var body: some View {
        Page(title: L("svo_detail_title"), onBack: onBack) {
            if let o = order {
                content(o)
            } else if error == nil {
                SkeletonDetail()
            }
        }
        .messageToast(error)
        .task(id: network.reconnectTick) { await load() }
        .onReceive(Realtime.shared.changed) { Task { await load() } }
        .dorrDialog(isPresented: Binding(get: { asking && order != nil }, set: { if !$0 { asking = false } })) {
            if let o = order {
                ConfirmDialog(icon: "checkmark.seal", title: L("svo_accept_title"),
                              message: L("svo_accept_body", o.service?.title ?? "", daysLabel(o.deliveryDays ?? 0)), loading: busy,
                              confirmLabel: L("svo_accept"), onConfirm: { answer("accept") }, onDismiss: { asking = false })
            }
        }
        .dorrDialog(isPresented: Binding(get: { declining }, set: { if !$0 && !busy { declining = false } })) {
            AlertDialog(
                onDismissRequest: { if !busy { declining = false } },
                title: { Text(L("svo_decline_title")).fontWeight(.heavy) },
                text: {
                    DorrTextField(value: Binding(get: { reason }, set: { reason = String($0.prefix(500)) }), label: L("svo_decline_reason"),
                                  singleLine: false, minLines: 3)
                },
                confirmButton: { DialogButton(onClick: { answer("decline") }, enabled: !busy) { Text(L("svo_decline_confirm")).fontWeight(.bold) } },
                dismissButton: { DialogButton(onClick: { declining = false }, enabled: !busy) { Text(L("common_cancel")) } }
            )
        }
    }

    @ViewBuilder
    private func content(_ o: ServiceOrderDto) -> some View {
        let status = o.status ?? "awaiting"
        let decimals = o.decimals ?? 2
        OrderHead(o: o)
        InfoCard(title: L("svo_ordered"), icon: "bag.fill") {
            HStack(spacing: 8) {
                SvcPill(text: levelLabel(o.level ?? "basic"), tone: SvcTone(ink: wa.red, fill: wa.red.opacity(0.10)))
                if let tier = o.tierName, !tier.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: tier).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                }
            }
            ForEach(Array((o.features ?? []).enumerated()), id: \.offset) { _, f in
                HStack(spacing: 8) {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 14)).foregroundStyle(Color(hex: 0x16A34A))
                    Text(verbatim: f).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                }
            }
            if let addons = o.addons, !addons.isEmpty {
                Text(L("svo_extras")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut).padding(.top, 4)
                ForEach(Array(addons.enumerated()), id: \.offset) { _, a in
                    HStack(spacing: 8) {
                        Image(systemName: "plus.circle").font(.system(size: 14)).foregroundStyle(wa.red)
                        let days = a.extraDays ?? 0
                        Text(verbatim: (a.title ?? "") + (days > 0 ? " · +\(daysLabel(days))" : "")).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        Text(verbatim: svcMoney(a.priceMinor ?? 0, decimals, o.currency)).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                    }
                }
            }
            HStack(spacing: 8) {
                Fact(icon: "clock", text: daysLabel(o.deliveryDays ?? 0))
                let revisions = o.revisions ?? 0
                Fact(icon: "arrow.counterclockwise", text: revisions > 0 ? L("svc_revisions", revisions) : L("svc_no_revisions"))
            }
            .padding(.top, 4)
        }
        if let note = o.note, !note.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            InfoCard(title: L("svo_note"), icon: "quote.opening") {
                Text(verbatim: note).font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(wa.red.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
        }
        InfoCard(title: L("svo_money"), icon: "banknote.fill") {
            MoneyLine(label: L("svo_price"), amount: svcMoney(o.money?.priceMinor ?? 0, decimals, o.currency))
            MoneyLine(label: L("svo_commission"), amount: "− " + svcMoney(o.money?.commissionMinor ?? 0, decimals, o.currency), muted: true)
            Rectangle().fill(wa.night ? wa.line : Color(hex: 0xEEF0F3)).frame(height: 1)
            MoneyLine(label: L("svo_net"), amount: svcMoney(o.money?.netMinor ?? 0, decimals, o.currency), strong: true)
            Text(L("svo_money_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineSpacing(2)
        }
        if status == "awaiting" { NextSteps() }

        if o.can?.accept ?? false {
            VStack(spacing: 10) {
                SettingsPrimaryButton(text: L("svo_accept"), loading: busy && !declining) { asking = true }
                Button { declining = true } label: {
                    Text(L("svo_decline")).font(.dorr(15, .heavy)).foregroundStyle(wa.red)
                        .frame(maxWidth: .infinity, minHeight: 50)
                        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(wa.night ? wa.line : wa.red.opacity(0.3), lineWidth: 1.5))
                        .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .disabled(busy)
            }
            .frame(maxWidth: 520)
            .frame(maxWidth: .infinity)
        } else if (status == "active" || status == "completed"), let project = o.project {
            SettingsPrimaryButton(text: L("svo_open_work")) { onOpenWork(project) }.frame(maxWidth: 520).frame(maxWidth: .infinity)
        } else if status == "declined" || status == "expired" || status == "cancelled" {
            Text(L(status == "declined" ? "svo_ended_declined" : status == "expired" ? "svo_ended_expired" : "svo_ended_cancelled"))
                .font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
    }
}

private struct OrderHead: View {
    @Environment(\.wa) private var wa
    let o: ServiceOrderDto

    var body: some View {
        let tone = orderTone(o.status ?? "awaiting", night: wa.night)
        VStack(alignment: .leading, spacing: 10) {
            SvcPill(text: orderStatusLabel(o.status ?? "awaiting"), tone: tone, solid: true)
            Text(verbatim: o.service?.title ?? "").font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(4)
            HStack(spacing: 10) {
                SvcInitial(name: o.client?.name)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("svo_client")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: o.client?.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: longDate(o.createdAt)).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
            if let text = leftText(o) {
                let warn = serviceTone("awaiting", night: wa.night)
                HStack(spacing: 10) {
                    Image(systemName: "hourglass").font(.system(size: 18)).foregroundStyle(warn.ink)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: text).font(.dorr(14, .heavy)).foregroundStyle(warn.ink)
                        if let by = o.answerBy {
                            Text(L("svo_answer_by", "\(longDate(by)) \(timeOf(by))")).font(.dorr(12)).foregroundStyle(warn.ink.opacity(0.85))
                        }
                    }
                    Spacer(minLength: 0)
                }
                .padding(12)
                .background(warn.fill, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
        }
        .padding(16)
        .frame(maxWidth: 520, alignment: .leading)
        .settingsSurface(22)
        .frame(maxWidth: .infinity)
    }
}

private struct Fact: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String

    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 14)).foregroundStyle(wa.red)
            Text(verbatim: text).font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 9)
        .frame(maxWidth: .infinity)
        .background(wa.red.opacity(0.06), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
    }
}

/// FL-L05: the monthly subscriptions to my services. Each card: the service and level, the client, the month it is in, what I get each
/// month, when this month ends (and whether the client can confirm the next one), or the ending asked with its day; ended ones say why.
/// I can end one with notice (said before asking) or take my ending back. Tapping opens the month running now.
private struct SubscriptionList: View {
    @ObservedObject var subs: PagedRows<ServiceSubscriptionDto>
    let onOpenOrder: (String) -> Void

    @State private var busy: String?
    @State private var ending: ServiceSubscriptionDto?
    @State private var error: String?

    private func act(_ s: ServiceSubscriptionDto, _ action: String) {
        guard let id = s.uuid else { return }
        busy = id
        Task {
            let r = await ServicesApi.subscriptionAct(id, action)
            busy = nil
            ending = nil
            if r.value != nil {
                await AppToast.show(action == "cancel" ? L("sub_cancel_done") : L("sub_keep_done"))
                await subs.reload()
            } else {
                error = r.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    var body: some View {
        Group {
            if !subs.loaded && subs.failure == nil {
                SkeletonList()
            } else if subs.loaded && subs.rows.isEmpty && subs.failure == nil {
                EmptyState(icon: "repeat", title: L("sub_empty"), body: L("sub_empty_body")).padding(.top, 70)
            } else {
                ForEach(subs.rows, id: \.id) { s in
                    SubscriptionCard(s: s, busy: busy != nil && busy == s.uuid,
                                     onOpen: { if let id = s.current?.uuid { onOpenOrder(id) } },
                                     onCancel: { ending = s }, onKeep: { act(s, "keep") })
                }
            }
            PagedFooter(paged: subs) { Task { await subs.loadMore() } }
        }
        .messageToast(error ?? subs.failure.map { $0.errorText(L("login_error_offline")) ?? L("login_error_generic") })
        .dorrDialog(isPresented: Binding(get: { ending != nil }, set: { if !$0 { ending = nil } })) {
            if let s = ending {
                ConfirmDialog(icon: "calendar.badge.minus", title: L("sub_cancel_title"), message: L("sub_cancel_body", s.noticeDays ?? 7),
                              loading: busy != nil && busy == s.uuid, confirmLabel: L("sub_cancel"),
                              onConfirm: { act(s, "cancel") }, onDismiss: { ending = nil })
            }
        }
    }
}

private struct SubscriptionCard: View {
    @Environment(\.wa) private var wa
    let s: ServiceSubscriptionDto
    let busy: Bool
    let onOpen: () -> Void
    let onCancel: () -> Void
    let onKeep: () -> Void

    var body: some View {
        let status = s.status ?? "pending"
        let tone = serviceTone(status == "active" ? "active" : status == "pending" ? "awaiting" : "paused", night: wa.night)
        let canKeep = s.can?.keep ?? false
        let canCancel = s.can?.cancel ?? false
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .top, spacing: 10) {
                VStack(alignment: .leading, spacing: 4) {
                    Text(verbatim: s.service?.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    Text(verbatim: [levelLabel(s.level ?? "basic"), s.tierName].compactMap { $0 }.filter { !$0.trimmingCharacters(in: .whitespaces).isEmpty }.joined(separator: " · "))
                        .font(.dorr(12, .bold)).foregroundStyle(wa.red)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                SvcPill(text: L(status == "active" ? "sub_status_active" : status == "pending" ? "sub_status_pending" : "sub_status_ended"), tone: tone)
            }
            HStack(spacing: 10) {
                SvcInitial(name: s.client?.name)
                Text(verbatim: s.client?.name ?? "").font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    .frame(maxWidth: .infinity, alignment: .leading)
                Image(systemName: "repeat").font(.system(size: 13)).foregroundStyle(wa.settingsMut)
                Text(L("sub_month_n", s.cycle ?? 1)).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
            }
            Text(L("sub_you_get", svcMoney(s.money?.netMinor ?? 0, s.decimals ?? 2, s.currency))).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                .padding(.horizontal, 12)
                .padding(.vertical, 10)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(wa.red.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            if status == "ended" {
                SubNote(icon: "calendar.badge.minus", text: L(endReason(s.endReason)), tone: serviceTone("paused", night: wa.night))
            } else if let cancel = s.cancel {
                SubNote(icon: "calendar.badge.minus", text: L(cancel.by == "client" ? "sub_ending_client" : "sub_ending_me", longDate(cancel.endsAt)),
                        tone: serviceTone("awaiting", night: wa.night))
            } else if status == "active" {
                SubNote(icon: "clock", text: L(s.renew?.open == true ? "sub_renew_open" : "sub_month_ends", longDate(s.periodEndsAt)),
                        tone: serviceTone("active", night: wa.night))
            } else {
                SubNote(icon: "hourglass", text: L("sub_pending_hint"), tone: serviceTone("awaiting", night: wa.night))
            }
            if canCancel || canKeep {
                Button { if canKeep { onKeep() } else { onCancel() } } label: {
                    Text(L(canKeep ? "sub_keep" : "sub_cancel")).font(.dorr(14, .heavy))
                        .foregroundStyle(canKeep ? wa.red : serviceTone("rejected", night: wa.night).ink)
                        .frame(maxWidth: .infinity, minHeight: 44)
                        .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.night ? wa.line : wa.red.opacity(0.3), lineWidth: 1.5))
                        .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .disabled(busy)
            }
        }
        .padding(14)
        .frame(maxWidth: 520, alignment: .leading)
        .settingsSurface(20)
        .contentShape(Rectangle())
        .onTapGesture { if s.current != nil { onOpen() } }
        .frame(maxWidth: .infinity)
    }
}

private struct SubNote: View {
    let icon: String
    let text: String
    let tone: SvcTone

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: icon).font(.system(size: 14)).foregroundStyle(tone.ink)
            Text(verbatim: text).font(.dorr(12, .bold)).foregroundStyle(tone.ink).lineSpacing(2)
            Spacer(minLength: 0)
        }
        .padding(10)
        .background(tone.fill, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
    }
}

private func endReason(_ reason: String?) -> String {
    switch reason {
    case "cancelled_client": return "sub_end_client"
    case "cancelled_freelancer": return "sub_end_me"
    case "not_renewed": return "sub_end_not_renewed"
    default: return "sub_end_first"
    }
}

/// Accept → the client pays into the hold → I deliver → approved and paid: the order's road, as a short timeline.
private struct NextSteps: View {
    @Environment(\.wa) private var wa

    var body: some View {
        let steps = ["svo_next_1", "svo_next_2", "svo_next_3", "svo_next_4"]
        let icons = ["checkmark.seal", "banknote.fill", "briefcase.fill", "checkmark.circle.fill"]
        InfoCard(title: L("svo_next_title"), icon: "point.topleft.down.curvedto.point.bottomright.up") {
            VStack(alignment: .leading, spacing: 0) {
                ForEach(0..<steps.count, id: \.self) { i in
                    HStack(alignment: .top, spacing: 12) {
                        VStack(spacing: 0) {
                            Image(systemName: icons[i]).font(.system(size: 13)).foregroundStyle(i == 0 ? Color.white : wa.red)
                                .frame(width: 28, height: 28)
                                .background(i == 0 ? wa.red : wa.red.opacity(0.12), in: Circle())
                            if i < steps.count - 1 { Rectangle().fill(wa.red.opacity(0.18)).frame(width: 2, height: 16) }
                        }
                        Text(L(steps[i])).font(.dorr(13, i == 0 ? .bold : .medium)).foregroundStyle(i == 0 ? wa.settingsInk : wa.settingsMut)
                            .padding(.top, 5)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                }
            }
        }
    }
}
