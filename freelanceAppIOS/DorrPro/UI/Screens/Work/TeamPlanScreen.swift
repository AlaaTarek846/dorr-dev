import SwiftUI

// freelanceApp `ui/screens/TeamPlanScreen.kt`.

private let TEAM_LIVE: Set<String> = ["trialing", "active", "past_due"]
private let limitRed = Color(red: 220 / 255, green: 38 / 255, blue: 38 / 255)

/// A team's plan (FS-211), for its Owners: what the team holds against its room (people, projects at once, offers this month), the plan it is on
/// and when it renews, the plans with their prices (monthly or yearly), and the invoices. The price is the whole team's, charged from the wallet of
/// the Owner who subscribes: only that Owner changes the plan, pays again or turns renewal back on; any Owner turns renewal off. The server decides
/// what a change means (a better plan starts now with the unused time taken off, a lower one waits for the end of the period) and every answer is
/// the whole page again. Read when the page opens, after each action and when the connection returns - nothing runs on a timer.
struct TeamPlanScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let teamUuid: String
    let onBack: () -> Void
    let onOpenWallet: () -> Void

    @State private var page: TeamPlanPageDto?
    @State private var period = "monthly"
    @State private var seeded = false
    @State private var confirm: TeamPlanDto?
    @State private var confirmCancel = false
    @State private var busy = false
    @State private var notice: String?
    @State private var error: String?
    @State private var needsMoney = false
    @State private var failed: String?

    private var offlineText: String { L("login_error_offline") }
    private var genericError: String { L("login_error_generic") }

    private func load() async {
        let result = await TeamPlanApi.show(teamUuid)
        if let data = result.value {
            page = data
            failed = nil
            // A plan already running opens on the period it was bought for.
            if !seeded {
                seeded = true
                if let sub = data.subscription, TEAM_LIVE.contains(sub.status ?? "") { period = sub.period ?? "monthly" }
            }
        } else if page == nil {
            failed = result.errorText(offlineText) ?? genericError
        }
    }

    private func act(_ call: @escaping () async -> ApiResult<TeamPlanPageDto>) {
        error = nil
        notice = nil
        needsMoney = false
        busy = true
        Task {
            let result = await call()
            if let data = result.value {
                page = data
                notice = L("plans_done")
            } else {
                error = result.errorText(offlineText) ?? genericError
                needsMoney = result.error?.errorCode?.hasSuffix("_insufficient") == true
                if needsMoney {
                    // Not enough in the wallet: straight to it, instead of leaving a button to find.
                    AppToast.show(error.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? L("plans_short_go_wallet"), long: true)
                    onOpenWallet()
                } else {
                    // Another Owner may have changed the plan meanwhile: show what is true now.
                    await load()
                }
            }
            busy = false
        }
    }

    private var live: TeamSubscriptionDto? {
        guard let sub = page?.subscription, TEAM_LIVE.contains(sub.status ?? "") else { return nil }
        return sub
    }

    var body: some View {
        Page(title: L("tpl_title"), onBack: onBack) {
            if let data = page {
                content(data)
            } else if let failed {
                EmptyState(icon: "crown.fill", title: L("tpl_title"), body: failed)
            } else {
                SkeletonDetail()
            }
        }
        .task(id: network.reconnectTick) { await load() }
        .messageToast(notice)
        .messageToast(needsMoney ? nil : error)
        .dorrDialog(isPresented: Binding(get: { confirm != nil }, set: { if !$0 { confirm = nil } })) {
            if let plan = confirm {
                AlertDialog(
                    onDismissRequest: { confirm = nil },
                    title: { Text(verbatim: plan.name ?? "").fontWeight(.heavy) },
                    text: { Text(verbatim: confirmMessage(plan)).lineSpacing(4) },
                    confirmButton: {
                        DialogButton(onClick: {
                            let chosen = period
                            confirm = nil
                            act { await TeamPlanApi.take(teamUuid, TakePlanRequest(plan: plan.slug ?? "", period: chosen)) }
                        }) { Text(L("plans_confirm_ok")).fontWeight(.bold) }
                    },
                    dismissButton: { DialogButton(onClick: { confirm = nil }) { Text(L("common_cancel")) } }
                )
            }
        }
        .dorrDialog(isPresented: $confirmCancel) {
            AlertDialog(
                onDismissRequest: { confirmCancel = false },
                title: { Text(L("plans_cancel_confirm_title")).fontWeight(.heavy) },
                text: { Text(verbatim: L("tpl_cancel_confirm", live?.plan?.name ?? "", growthLongDate(live?.periodEnd))).lineSpacing(4) },
                confirmButton: {
                    DialogButton(onClick: {
                        confirmCancel = false
                        act { await TeamPlanApi.cancel(teamUuid) }
                    }) { Text(L("plans_cancel_renewal")).fontWeight(.bold) }
                },
                dismissButton: { DialogButton(onClick: { confirmCancel = false }) { Text(L("common_cancel")) } }
            )
        }
    }

    @ViewBuilder
    private func content(_ data: TeamPlanPageDto) -> some View {
        let plans = data.catalog?.plans ?? []
        let onSale = plans.contains { !($0.free ?? false) }

        TeamUsageCard(usage: data.usage ?? TeamUsageDto())

        if let live {
            MyTeamPlanCard(
                sub: live, busy: busy,
                onPay: { act { await TeamPlanApi.renew(teamUuid) } },
                onCancel: { confirmCancel = true },
                onResume: { act { await TeamPlanApi.resume(teamUuid) } }
            )
        }

        if needsMoney {
            Card {
                Text(verbatim: error ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                SettingsPrimaryButton(text: L("plans_wallet_topup"), action: onOpenWallet)
            }
        }

        if !onSale && live == nil {
            EmptyState(icon: "crown.fill", title: L("tpl_unavailable_title"), body: L("tpl_unavailable"))
        } else if let catalog = data.catalog {
            ChipRow(
                options: [(value: "monthly", label: L("plans_period_monthly")), (value: "yearly", label: L("plans_period_yearly"))],
                selected: [period],
                onToggle: { period = $0 }
            )
            ForEach(plans) { plan in
                TeamPlanCard(plan: plan, catalog: catalog, period: period, live: live, busy: busy, onChoose: { confirm = plan })
            }
            Text(L("tpl_footer")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(4)
        }

        let invoices = data.invoices ?? []
        if !invoices.isEmpty {
            let decimals = data.catalog?.decimals ?? 2
            Card {
                FieldLabel(text: L("plans_invoices"))
                ForEach(invoices) { i in
                    HStack {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(verbatim: "\(i.plan ?? "") · " + L(invoiceKind(i.kind)))
                                .font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                            Text(verbatim: [
                                growthLongDate(i.createdAt).isEmpty ? nil : growthLongDate(i.createdAt),
                                (i.creditMinor ?? 0) > 0 ? L("plans_invoice_credit", planMoney(i.creditMinor ?? 0, decimals, i.currency, i.currencySymbol)) : nil,
                            ].compactMap { $0 }.joined(separator: " · "))
                            .font(.dorr(11)).foregroundStyle(wa.settingsMut)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        if i.status == "failed" {
                            Text(L("plans_invoice_failed")).font(.dorr(12, .bold)).foregroundStyle(wa.red)
                        } else {
                            Text(verbatim: planMoney(i.amountMinor ?? 0, decimals, i.currency, i.currencySymbol)).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk)
                        }
                    }
                    .padding(.vertical, 4)
                }
            }
        }
    }

    private func invoiceKind(_ kind: String?) -> String {
        switch kind {
        case "upgrade": return "plans_invoice_upgrade"
        case "renewal": return "plans_invoice_renewal"
        default: return "plans_invoice_purchase"
        }
    }

    private func confirmMessage(_ plan: TeamPlanDto) -> String {
        let catalog = page?.catalog
        let price = (period == "yearly" ? plan.prices?.yearly : plan.prices?.monthly) ?? 0
        let periodName = L(period == "yearly" ? "plans_period_yearly" : "plans_period_monthly")
        let unit = L(period == "yearly" ? "plans_per_year" : "plans_per_month")
        let money = planMoney(price, catalog?.decimals ?? 2, catalog?.currency, catalog?.currencySymbol)
        let name = plan.name ?? ""
        let trialDays = plan.trialDays ?? 0
        guard let sub = live else {
            return trialDays > 0 ? L("plans_confirm_trial", name, trialDays, money, unit) : L("plans_confirm_pay", name, periodName, money)
        }
        if teamIsUpgrade(plan, period, sub) { return L("plans_confirm_upgrade", name, periodName, money) }
        return L("plans_confirm_later", name, periodName, growthLongDate(sub.periodEnd), money)
    }
}

/// A better plan (or a longer period of the same one) starts now; anything else waits for the end of the period.
private func teamIsUpgrade(_ plan: TeamPlanDto, _ period: String, _ sub: TeamSubscriptionDto) -> Bool {
    let rank = plan.rank ?? 0
    let mine = sub.plan?.rank ?? 0
    return rank > mine || (rank == mine && period == "yearly" && (sub.period ?? "monthly") == "monthly")
}

/// What the team holds against its room: three meters that turn red when a limit is reached. A limit only stops what is new.
private struct TeamUsageCard: View {
    @Environment(\.wa) private var wa
    let usage: TeamUsageDto

    var body: some View {
        Card {
            HStack(spacing: 0) {
                Image(systemName: "person.3.fill").font(.system(size: 20)).foregroundStyle(wa.red).frame(width: 28, height: 28)
                Spacer().frame(width: 10)
                Text(L("tpl_usage_title")).font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
            }
            UsageMeter(label: L("tpl_people"), used: usage.members?.used ?? 0, cap: usage.members?.max)
            UsageMeter(label: L("tpl_projects"), used: usage.projects?.used ?? 0, cap: usage.projects?.max)
            UsageMeter(label: L("tpl_offers"), used: usage.offers?.used ?? 0, cap: usage.offers?.limit)
            Text(L("tpl_usage_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(4)
        }
    }
}

private struct UsageMeter: View {
    @Environment(\.wa) private var wa
    let label: String
    let used: Int
    let cap: Int?

    var body: some View {
        let full = cap.map { used >= $0 } ?? false
        VStack(alignment: .leading, spacing: 5) {
            HStack {
                Text(verbatim: label).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: cap.map { L("tpl_of", used, $0) } ?? L("tpl_no_cap", used))
                    .font(.dorr(12, .bold)).foregroundStyle(full ? limitRed : wa.settingsMut)
            }
            if let cap, cap > 0 {
                GeometryReader { geo in
                    ZStack(alignment: .leading) {
                        Capsule().fill(wa.settingsMut.opacity(0.15))
                        Capsule().fill(full ? limitRed : wa.red).frame(width: geo.size.width * CGFloat(min(1, Double(used) / Double(cap))))
                    }
                }
                .frame(height: 6)
                if full { Text(L("tpl_full")).font(.dorr(11, .bold)).foregroundStyle(limitRed) }
            }
        }
    }
}

/// The plan the team is on: its status, when it renews or ends, what waits, who pays, and the actions that fit its state and the person looking.
private struct MyTeamPlanCard: View {
    @Environment(\.wa) private var wa
    let sub: TeamSubscriptionDto
    let busy: Bool
    let onPay: () -> Void
    let onCancel: () -> Void
    let onResume: () -> Void

    var body: some View {
        let autoRenew = sub.autoRenew ?? true
        let paidByMe = sub.paidByMe ?? false
        Card {
            HStack(spacing: 0) {
                Image(systemName: "crown.fill").font(.system(size: 22)).foregroundStyle(wa.red).frame(width: 28, height: 28)
                Spacer().frame(width: 10)
                VStack(alignment: .leading, spacing: 1) {
                    Text(L("plans_current")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: sub.plan?.name ?? "").font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                PlanStatusPill(status: sub.status ?? "")
            }
            Text(verbatim: line).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            if let p = sub.pending {
                Text(verbatim: L("plans_line_pending", p.plan?.name ?? "", periodName(p.period), growthLongDate(sub.periodEnd)))
                    .font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            }
            Text(verbatim: paidByMe ? L("tpl_paid_by_me") : L("tpl_paid_by", sub.paidBy?.name ?? ""))
                .font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            if sub.status == "past_due" && paidByMe { SettingsPrimaryButton(text: L("plans_pay_now"), loading: busy, action: onPay) }
            // Turning renewal off is any Owner's; turning it back on spends the payer's wallet, so it is the payer's.
            if sub.status != "past_due" && (autoRenew || paidByMe) {
                Button { if autoRenew { onCancel() } else { onResume() } } label: {
                    Text(L(autoRenew ? "plans_cancel_renewal" : "plans_resume")).font(.dorr(13, .bold)).foregroundStyle(wa.red).padding(.vertical, 4)
                }
                .buttonStyle(.plain)
                .disabled(busy)
            }
        }
    }

    private var line: String {
        if sub.status == "trialing" { return L("plans_line_trial", growthLongDate(sub.trialEndsAt ?? sub.periodEnd)) }
        if sub.status == "past_due" { return L("plans_line_grace", growthLongDate(sub.graceUntil)) }
        if !(sub.autoRenew ?? true) { return L("plans_line_ends", growthLongDate(sub.periodEnd)) }
        return L("plans_line_renews", growthLongDate(sub.periodEnd))
    }
}

/// One plan: its price for the chosen period, what it opens for the team, and the button that fits where the team is now.
private struct TeamPlanCard: View {
    @Environment(\.wa) private var wa
    let plan: TeamPlanDto
    let catalog: TeamPlanCatalogDto
    let period: String
    let live: TeamSubscriptionDto?
    let busy: Bool
    let onChoose: () -> Void

    var body: some View {
        let price = period == "yearly" ? plan.prices?.yearly : plan.prices?.monthly
        let sameAsMine = (plan.current ?? false) && live != nil && live?.period == period
        // Changing a running plan spends the payer's wallet: another Owner reads it but does not change it.
        let mine = live == nil || (live?.paidByMe ?? false)
        let decimals = catalog.decimals ?? 2
        let b = plan.benefits ?? TeamPlanBenefitsDto()
        let trialDays = plan.trialDays ?? 0
        Card {
            HStack(spacing: 0) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: plan.name ?? "").font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk)
                    if let d = plan.description, !d.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(verbatim: d).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if plan.current ?? false { GrowthChip(text: L("plans_current")) }
            }
            if let price {
                let unit = L(period == "yearly" ? "plans_per_year" : "plans_per_month")
                Text(verbatim: L("plans_price_line", planMoney(price, decimals, catalog.currency, catalog.currencySymbol), unit)).font(.dorr(22, .heavy)).foregroundStyle(wa.settingsInk)
                if period == "yearly", let saving = plan.yearlySavingMinor, saving > 0 {
                    Text(verbatim: L("plans_saves", planMoney(saving, decimals, catalog.currency, catalog.currencySymbol))).font(.dorr(12, .bold)).foregroundStyle(wa.red)
                }
            } else {
                Text(L("plans_free_price")).font(.dorr(22, .heavy)).foregroundStyle(wa.settingsInk)
            }
            VStack(alignment: .leading, spacing: 6) {
                Benefit(text: b.membersMax.map { L("tpl_benefit_people_n", $0) } ?? L("tpl_benefit_people_any"))
                Benefit(text: b.projectsMax.map { L("tpl_benefit_projects_n", $0) } ?? L("tpl_benefit_projects_any"))
                Benefit(text: b.offersMonthly.map { L("tpl_benefit_offers_n", $0) } ?? L("tpl_benefit_offers_any"))
                if trialDays > 0 { Benefit(text: L("plans_trial", trialDays)) }
            }
            if !(plan.free ?? false) && !sameAsMine {
                SettingsPrimaryButton(text: label(trialDays), enabled: mine && live?.status != "past_due", loading: busy, action: onChoose)
                if !mine {
                    Text(verbatim: L("tpl_payer_only", live?.paidBy?.name ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
            }
        }
    }

    private func label(_ trialDays: Int) -> String {
        guard let live else { return trialDays > 0 ? L("plans_btn_trial", trialDays) : L("plans_btn_subscribe") }
        return teamIsUpgrade(plan, period, live) ? L("plans_btn_upgrade") : L("plans_btn_switch")
    }
}
