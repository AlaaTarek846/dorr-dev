import SwiftUI

// freelanceApp `ui/screens/PlansScreen.kt`.

/// A price in the currency's own decimals ("34.00 SAR", "7.500 KWD", "1,350 JPY") — digits stay Latin and left-to-right in Arabic text too.
func planMoney(_ minor: Int, _ decimals: Int, _ currency: String?, _ symbol: String? = nil) -> String {
    let f = NumberFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.numberStyle = .decimal
    f.usesGroupingSeparator = true
    f.groupingSeparator = ","
    f.decimalSeparator = "."
    f.minimumFractionDigits = max(decimals, 0)
    f.maximumFractionDigits = max(decimals, 0)
    let value = Decimal(minor) / pow(Decimal(10), max(decimals, 0))
    let text = f.string(from: value as NSDecimalNumber) ?? "\(value)"
    let sign = symbol.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? (currency ?? "")
    return "\u{200E}" + [text, sign].filter { !$0.trimmingCharacters(in: .whitespaces).isEmpty }.joined(separator: " ")
}

private let LIVE: Set<String> = ["trialing", "active", "past_due"]

/// My plan (FL-L01): the plan I am on and when it renews, the free offers I have left, the plans with their prices here (monthly or
/// yearly), and my invoices. A plan is taken, changed, cancelled or resumed with one request each; the server decides what a change means
/// (a better plan starts now with the unused time taken off, a lower one waits for the end of the period), and every answer is the
/// whole page again. Read once when the page opens and after each action.
struct PlansScreen: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let onOpenWallet: () -> Void
    let onOpenInsights: () -> Void

    @State private var catalog: PlansDto?
    @State private var mine: MySubscriptionDto?
    @State private var period = "monthly"
    @State private var confirm: PlanDto?
    @State private var confirmCancel = false
    @State private var busy = false
    @State private var notice: String?
    @State private var error: String?
    @State private var needsMoney = false
    @State private var failed: String?
    @State private var packs: OfferPacksDto?
    @State private var buying: OfferPackDto?
    // One id per confirmed purchase: a double tap or a retry after a lost answer is the same purchase, never charged twice.
    @State private var buyRequest = ""

    private var offlineText: String { L("login_error_offline") }
    private var genericError: String { L("login_error_generic") }

    private func readPlans() async {
        let plans = await SubscriptionApi.plans()
        if let data = plans.value { catalog = data } else if catalog == nil { failed = plans.errorText(offlineText) ?? genericError }
    }

    private func readPacks() async {
        if let data = await SubscriptionApi.offerPacks().value { packs = data }
    }

    private func firstLoad() async {
        if let own = await SubscriptionApi.show().value { mine = own }
        await readPlans()
        await readPacks()
        // A plan already running opens on the period it was bought for.
        if let sub = mine?.subscription, LIVE.contains(sub.status ?? "") { period = sub.period ?? "monthly" }
    }

    private func act(_ call: @escaping () async -> ApiResult<MySubscriptionDto>) {
        error = nil
        notice = nil
        needsMoney = false
        busy = true
        Task {
            let result = await call()
            if let data = result.value {
                mine = data
                notice = L("plans_done")
                await readPlans()
                await readPacks()
            } else {
                error = result.errorText(offlineText) ?? genericError
                needsMoney = result.error?.errorCode?.hasSuffix("_insufficient") == true
                // Not enough in the wallet: straight to it, instead of leaving a button to find.
                if needsMoney {
                    AppToast.show(error.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? L("plans_short_go_wallet"), long: true)
                    onOpenWallet()
                }
            }
            busy = false
        }
    }

    private func buy(_ pack: OfferPackDto, _ requestId: String) {
        error = nil
        notice = nil
        needsMoney = false
        busy = true
        Task {
            let result = await SubscriptionApi.buyPack(pack.slug ?? "", BuyPackRequest(requestId: requestId))
            if let data = result.value {
                packs = data
                // The page's own numbers (free offers left, bought ones) come from the same quota.
                if mine != nil { mine?.quota = data.quota } else { mine = MySubscriptionDto(quota: data.quota) }
                notice = L("plans_done")
            } else {
                error = result.errorText(offlineText) ?? genericError
                needsMoney = result.error?.errorCode?.hasSuffix("_insufficient") == true
                if needsMoney {
                    AppToast.show(error.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? L("plans_short_go_wallet"), long: true)
                    onOpenWallet()
                }
            }
            busy = false
        }
    }

    private var live: SubscriptionDto? {
        guard let sub = mine?.subscription, LIVE.contains(sub.status ?? "") else { return nil }
        return sub
    }

    var body: some View {
        Page(title: L("plans_title"), onBack: onBack) {
            if let data = catalog {
                if !(data.enabled ?? false) || (data.plans ?? []).isEmpty {
                    EmptyState(icon: "crown.fill", title: L("plans_unavailable_title"), body: L("plans_unavailable"))
                } else {
                    content(data)
                }
            } else if let failed {
                EmptyState(icon: "crown.fill", title: L("plans_title"), body: failed)
            } else {
                SkeletonDetail()
            }
        }
        .task { await firstLoad() }
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
                            act { await SubscriptionApi.take(TakePlanRequest(plan: plan.slug ?? "", period: chosen)) }
                        }) { Text(L("plans_confirm_ok")).fontWeight(.bold) }
                    },
                    dismissButton: { DialogButton(onClick: { confirm = nil }) { Text(L("common_cancel")) } }
                )
            }
        }
        .dorrDialog(isPresented: Binding(get: { buying != nil }, set: { if !$0 { buying = nil } })) {
            if let pack = buying {
                let money = planMoney(pack.priceMinor ?? 0, packs?.decimals ?? 2, packs?.currency, packs?.currencySymbol)
                let validDays = packs?.validDays ?? 0
                AlertDialog(
                    onDismissRequest: { buying = nil },
                    title: { Text(verbatim: pack.name ?? "").fontWeight(.heavy) },
                    text: {
                        Text(verbatim: L("plans_pack_confirm", pack.offers ?? 0, money) + (validDays > 0 ? "\n" + L("plans_pack_valid", validDays) : ""))
                            .lineSpacing(4)
                    },
                    confirmButton: {
                        DialogButton(onClick: {
                            buying = nil
                            buy(pack, buyRequest)
                        }) { Text(L("plans_confirm_ok")).fontWeight(.bold) }
                    },
                    dismissButton: { DialogButton(onClick: { buying = nil }) { Text(L("common_cancel")) } }
                )
            }
        }
        .dorrDialog(isPresented: $confirmCancel) {
            AlertDialog(
                onDismissRequest: { confirmCancel = false },
                title: { Text(L("plans_cancel_confirm_title")).fontWeight(.heavy) },
                text: { Text(verbatim: L("plans_cancel_confirm", live?.plan?.name ?? "", growthLongDate(live?.periodEnd))).lineSpacing(4) },
                confirmButton: {
                    DialogButton(onClick: {
                        confirmCancel = false
                        act { await SubscriptionApi.cancel() }
                    }) { Text(L("plans_cancel_renewal")).fontWeight(.bold) }
                },
                dismissButton: { DialogButton(onClick: { confirmCancel = false }) { Text(L("common_cancel")) } }
            )
        }
    }

    @ViewBuilder
    private func content(_ data: PlansDto) -> some View {
        MyPlanCard(
            sub: live, quota: mine?.quota, busy: busy, plans: data,
            onPay: { act { await SubscriptionApi.renew() } },
            onCancel: { confirmCancel = true },
            onResume: { act { await SubscriptionApi.resume() } },
            onOpenInsights: onOpenInsights
        )

        if needsMoney {
            Card {
                Text(verbatim: error ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                SettingsPrimaryButton(text: L("plans_wallet_topup"), action: onOpenWallet)
            }
        }

        ChipRow(
            options: [(value: "monthly", label: L("plans_period_monthly")), (value: "yearly", label: L("plans_period_yearly"))],
            selected: [period],
            onToggle: { period = $0 }
        )

        ForEach(data.plans ?? []) { plan in
            PlanCard(plan: plan, plans: data, period: period, live: live, busy: busy, onChoose: { confirm = plan })
        }

        // FS-705c: the plans side by side, on every feature that works today.
        PlanComparison(features: data.features ?? [], plans: (data.plans ?? []).map { PlanColumn(slug: $0.slug ?? "", name: $0.name ?? "", mine: $0.current ?? false, perks: $0.perks ?? [:]) })

        if let p = packs, p.enabled ?? false, !(p.packs ?? []).isEmpty {
            ExtraOffersCard(p: p, busy: busy) { pack in
                buyRequest = UUID().uuidString
                buying = pack
            }
        }

        let invoices = mine?.invoices ?? []
        Card {
            FieldLabel(text: L("plans_invoices"))
            if invoices.isEmpty { Text(L("plans_invoices_none")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            ForEach(invoices) { i in
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: "\(i.plan ?? "") · " + L(invoiceKind(i.kind)))
                            .font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: [
                            growthLongDate(i.createdAt).isEmpty ? nil : growthLongDate(i.createdAt),
                            (i.creditMinor ?? 0) > 0 ? L("plans_invoice_credit", planMoney(i.creditMinor ?? 0, data.decimals ?? 2, i.currency, i.currencySymbol)) : nil,
                        ].compactMap { $0 }.joined(separator: " · "))
                        .font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    if i.status == "failed" {
                        Text(L("plans_invoice_failed")).font(.dorr(12, .bold)).foregroundStyle(wa.red)
                    } else {
                        Text(verbatim: planMoney(i.amountMinor ?? 0, data.decimals ?? 2, i.currency, i.currencySymbol)).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk)
                    }
                }
                .padding(.vertical, 4)
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

    private func confirmMessage(_ plan: PlanDto) -> String {
        let decimals = catalog?.decimals ?? 2
        let currency = catalog?.currency
        let price = (period == "yearly" ? plan.prices?.yearly : plan.prices?.monthly) ?? 0
        let periodName = L(period == "yearly" ? "plans_period_yearly" : "plans_period_monthly")
        let unit = L(period == "yearly" ? "plans_per_year" : "plans_per_month")
        let money = planMoney(price, decimals, currency, catalog?.currencySymbol)
        let name = plan.name ?? ""
        let trialDays = plan.trialDays ?? 0
        guard let sub = live else {
            return trialDays > 0 ? L("plans_confirm_trial", name, trialDays, money, unit) : L("plans_confirm_pay", name, periodName, money)
        }
        if isUpgrade(plan, period, sub) { return L("plans_confirm_upgrade", name, periodName, money) }
        return L("plans_confirm_later", name, periodName, growthLongDate(sub.periodEnd), money)
    }
}

/// Extra offers: how many bought ones are left (and when they end) and the packs to buy more — the plan with no limit has no use for them.
private struct ExtraOffersCard: View {
    @Environment(\.wa) private var wa
    let p: OfferPacksDto
    let busy: Bool
    let onBuy: (OfferPackDto) -> Void

    var body: some View {
        Card {
            FieldLabel(text: L("plans_extra_title"))
            if p.unlimited ?? false {
                Text(L("plans_extra_unlimited")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            } else {
                Text(L("plans_extra_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                let available = p.credits?.available ?? 0
                if available > 0 {
                    GrowthChip(text: L("plans_extra_left", available), size: 12, hPad: 12, vPad: 5)
                    ForEach(p.credits?.batches ?? []) { b in
                        Text(verbatim: b.expiresAt == nil
                             ? L("plans_extra_batch_open", b.remaining ?? 0, b.quantity ?? 0)
                             : L("plans_extra_batch", b.remaining ?? 0, b.quantity ?? 0, growthLongDate(b.expiresAt)))
                            .font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    }
                }
                ForEach(p.packs ?? []) { pack in
                    HStack(spacing: 0) {
                        VStack(alignment: .leading, spacing: 1) {
                            Text(verbatim: pack.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                            Text(L("plans_pack_offers", pack.offers ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        Text(verbatim: planMoney(pack.priceMinor ?? 0, p.decimals ?? 2, p.currency, p.currencySymbol)).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk)
                        Spacer().frame(width: 10)
                        Button { onBuy(pack) } label: {
                            Text(L("plans_pack_buy")).font(.dorr(13, .bold)).foregroundStyle(wa.red).padding(6)
                        }
                        .buttonStyle(.plain)
                        .disabled(busy)
                    }
                    .padding(.vertical, 2)
                }
            }
        }
    }
}

/// A better plan (or a longer period of the same one) starts now; anything else waits for the end of the period.
private func isUpgrade(_ plan: PlanDto, _ period: String, _ sub: SubscriptionDto) -> Bool {
    let rank = plan.rank ?? 0
    let mine = sub.plan?.rank ?? 0
    return rank > mine || (rank == mine && period == "yearly" && (sub.period ?? "monthly") == "monthly")
}

/// The plan I am on: its status, when it renews or ends, what waits, the free offers left, and the actions that fit its state.
private struct MyPlanCard: View {
    @Environment(\.wa) private var wa
    let sub: SubscriptionDto?
    let quota: OfferQuotaDto?
    let busy: Bool
    let plans: PlansDto
    let onPay: () -> Void
    let onCancel: () -> Void
    let onResume: () -> Void
    let onOpenInsights: () -> Void

    var body: some View {
        let current = plans.plans?.first { $0.current ?? false }
        Card {
            HStack(spacing: 0) {
                Image(systemName: "crown.fill").font(.system(size: 22)).foregroundStyle(wa.red).frame(width: 28, height: 28)
                Spacer().frame(width: 10)
                VStack(alignment: .leading, spacing: 1) {
                    Text(L("plans_current")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: sub?.plan?.name ?? current?.name ?? "").font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if let sub { PlanStatusPill(status: sub.status ?? "") }
            }
            if let sub {
                Text(verbatim: line(sub)).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                if let p = sub.pending {
                    Text(verbatim: L("plans_line_pending", p.plan?.name ?? "", periodName(p.period), growthLongDate(sub.periodEnd)))
                        .font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                }
            }
            if let q = quota, q.enabled ?? false {
                GrowthChip(
                    text: q.limit == nil ? L("plans_quota_unlimited") : L("plans_quota_left", q.left ?? 0, q.limit ?? 0),
                    size: 12, hPad: 12, vPad: 5
                )
            }
            if let q = quota, q.enabled ?? false, (q.credits ?? 0) > 0 {
                Text(L("plans_quota_extra", q.credits ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            // Phase 4: my numbers are one tap away from my plan (locked ones say what opens them).
            Button(action: onOpenInsights) {
                HStack(spacing: 8) {
                    Image(systemName: "chart.line.uptrend.xyaxis").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red).frame(width: 18, height: 18)
                    Text(L("plans_open_insights")).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                    Spacer(minLength: 0)
                }
                .padding(.vertical, 8).padding(.horizontal, 4)
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            if sub?.status == "past_due" { SettingsPrimaryButton(text: L("plans_pay_now"), loading: busy, action: onPay) }
            if let sub, sub.status != "past_due" {
                let autoRenew = sub.autoRenew ?? true
                Button { if autoRenew { onCancel() } else { onResume() } } label: {
                    Text(L(autoRenew ? "plans_cancel_renewal" : "plans_resume")).font(.dorr(13, .bold)).foregroundStyle(wa.red).padding(.vertical, 4)
                }
                .buttonStyle(.plain)
                .disabled(busy)
            }
        }
    }

    private func line(_ sub: SubscriptionDto) -> String {
        if sub.status == "trialing" { return L("plans_line_trial", growthLongDate(sub.trialEndsAt ?? sub.periodEnd)) }
        if sub.status == "past_due" { return L("plans_line_grace", growthLongDate(sub.graceUntil)) }
        if !(sub.autoRenew ?? true) { return L("plans_line_ends", growthLongDate(sub.periodEnd)) }
        return L("plans_line_renews", growthLongDate(sub.periodEnd))
    }
}

func periodName(_ period: String?) -> String { L(period == "yearly" ? "plans_period_yearly" : "plans_period_monthly") }

/// PlansScreen.kt's private `StatusPill` (named apart from the kit's `StatusPill`).
struct PlanStatusPill: View {
    let status: String

    var body: some View {
        let key: String
        switch status {
        case "trialing": key = "plans_status_trialing"
        case "past_due": key = "plans_status_past_due"
        case "expired": key = "plans_status_expired"
        default: key = "plans_status_active"
        }
        return GrowthChip(text: L(key))
    }
}

/// One plan: its price for the chosen period, what it gives, and the button that fits where I am now.
private struct PlanCard: View {
    @Environment(\.wa) private var wa
    let plan: PlanDto
    let plans: PlansDto
    let period: String
    let live: SubscriptionDto?
    let busy: Bool
    let onChoose: () -> Void

    var body: some View {
        let price = period == "yearly" ? plan.prices?.yearly : plan.prices?.monthly
        let sameAsMine = (plan.current ?? false) && live != nil && live?.period == period
        let decimals = plans.decimals ?? 2
        let b = plan.benefits ?? PlanBenefitsDto()
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
                Text(verbatim: L("plans_price_line", planMoney(price, decimals, plans.currency, plans.currencySymbol), unit)).font(.dorr(22, .heavy)).foregroundStyle(wa.settingsInk)
                if period == "yearly", let saving = plan.yearlySavingMinor, saving > 0 {
                    Text(verbatim: L("plans_saves", planMoney(saving, decimals, plans.currency, plans.currencySymbol))).font(.dorr(12, .bold)).foregroundStyle(wa.red)
                }
            } else {
                Text(L("plans_free_price")).font(.dorr(22, .heavy)).foregroundStyle(wa.settingsInk)
            }
            VStack(alignment: .leading, spacing: 6) {
                if b.offersUnlimited ?? false {
                    Benefit(text: L("plans_offers_unlimited"))
                } else if let monthly = b.offersMonthly {
                    Benefit(text: L("plans_offers_n", monthly))
                }
                Benefit(text: b.commissionBp.map { L("plans_commission", trimPercent($0)) } ?? L("plans_commission_country"))
                // Phase 4: what the plan shows and opens besides offers and commission.
                if b.badge ?? false { Benefit(text: L("plans_perk_badge", plan.name ?? "")) }
                if (b.boost ?? 0) > 0 { Benefit(text: L("plans_perk_boost")) }
                if b.analytics == 1 { Benefit(text: L("plans_perk_analytics_1")) }
                if b.analytics == 2 { Benefit(text: L("plans_perk_analytics_2")) }
                // FS-703: my clients - 0 shows nothing, 1 the clients and notes, 2 + the follow-up reminders.
                if b.crm == 1 { Benefit(text: L("plans_perk_crm_1")) }
                if b.crm == 2 { Benefit(text: L("plans_perk_crm_2")) }
                if let max = b.servicesMax { Benefit(text: L("plans_perk_services", max)) }
                if trialDays > 0 { Benefit(text: L("plans_trial", trialDays)) }
            }
            if !(plan.free ?? false) && !sameAsMine {
                SettingsPrimaryButton(text: label(trialDays), enabled: live?.status != "past_due", loading: busy, action: onChoose)
            }
        }
    }

    private func label(_ trialDays: Int) -> String {
        guard let live else { return trialDays > 0 ? L("plans_btn_trial", trialDays) : L("plans_btn_subscribe") }
        return isUpgrade(plan, period, live) ? L("plans_btn_upgrade") : L("plans_btn_switch")
    }
}

/// 800 → "8", 750 → "7.5" (hundredths of a percent).
private func trimPercent(_ bp: Int) -> String {
    let value = Decimal(bp) / 100
    return NSDecimalNumber(decimal: value).stringValue
}

struct Benefit: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: "checkmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.red).frame(width: 16, height: 16)
            Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsInk)
        }
    }
}
