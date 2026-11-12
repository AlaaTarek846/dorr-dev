// providerApp `ui/screens/WalletScreens.kt`
import SwiftUI
import UIKit

/// `CoreHttp.newIdempotencyKey()`.
func newIdempotencyKey() -> String { UUID().uuidString.lowercased() }

/// The wallet once unlocked, in the DORR app's wallet look: the home (the gradient balance card, four actions, the latest
/// movements) and its pages — top up, withdraw, statement, settings. Back from a page goes home first. Nothing is asked on a
/// timer: a page reads once when it opens (and when the connection comes back), and a live notice reloads once.
@MainActor
struct WalletHomeHost: View {
    let onBack: () -> Void
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var page = "home"
    @State private var balance: WalletBalanceDto?
    @State private var hidden = false
    @State private var sheetTx: WalletTransactionDto?
    @State private var explain = false

    var body: some View {
        content
            .task(id: network.reconnectTick) { await reload() }
            .onReceive(Realtime.shared.changed) { _ in Task { @MainActor in await reload() } }
            .onChange(of: page) { if $0 == "home" { Task { @MainActor in await reload() } } }
            .sheet(item: Binding(get: { sheetTx.map(WlSheetTx.init) }, set: { sheetTx = $0?.tx })) { item in
                WlTxSheet(tx: item.tx, currency: balance?.currencyCode ?? "") { sheetTx = nil }
                    .environment(\.wa, wa)
                    .presentationDetents([.medium, .large])
            }
            .sheet(isPresented: $explain) {
                WlExplainSheet { explain = false }
                    .environment(\.wa, wa)
                    .presentationDetents([.medium, .large])
            }
    }

    @ViewBuilder private var content: some View {
        let home = { page = "home" }
        let currency = balance?.currencyCode ?? ""
        switch page {
        case "topup": WlWalletTopup(balance: balance, hidden: hidden, onBack: home, onStatement: { page = "history" }, onPaid: { /* the home reloads */ })
        case "withdraw": WlWalletWithdraw(balance: balance, onBack: home)
        case "history": WlWalletHistory(currency: currency, onBack: home, onOpen: { sheetTx = $0 })
        case "security": WalletSecurity(onBack: home)
        default:
            WlWalletHome(balance: balance, hidden: hidden, onHide: { hidden.toggle() }, onBack: onBack, onRefresh: { /* re-read below */ },
                         onOpen: { page = $0 }, onTx: { sheetTx = $0 }, onExplain: { explain = true })
        }
    }

    private func reload() async {
        if let data = await WalletApi.balance().value { balance = data }
    }
}

private struct WlSheetTx: Identifiable {
    let tx: WalletTransactionDto
    var id: String { tx.uuid ?? "" }
}

@MainActor
private struct WlWalletHome: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let balance: WalletBalanceDto?
    let hidden: Bool
    let onHide: () -> Void
    let onBack: () -> Void
    let onRefresh: () -> Void
    let onOpen: (String) -> Void
    let onTx: (WalletTransactionDto) -> Void
    let onExplain: () -> Void
    @State private var recent: [WalletTransactionDto]?

    var body: some View {
        let currency = balance?.currencyCode ?? ""
        WaPage(title: L("wa_my_wallet"), onBack: onBack, actions: {
            WaOutlineCircleButton(icon: hidden ? "eye.slash" : "eye", action: onHide)
            WaOutlineCircleButton(icon: "arrow.clockwise") {
                onRefresh()
                Task { @MainActor in await load() }
            }
        }) {
            Group {
                if let balance { WlHeroCard(balance: balance, hidden: hidden, onExplain: onExplain) }
                else { WaSkeleton(cornerRadius: 24).frame(height: 172) }
            }
            .waRise(0)

            HStack(spacing: 8) {
                WlHomeAction(label: L("wa_action_topup"), icon: "plus.rectangle.on.rectangle", index: 1) { onOpen("topup") }
                WlHomeAction(label: L("wl_withdraw"), icon: "building.columns.fill", index: 2) { onOpen("withdraw") }
                WlHomeAction(label: L("wa_action_history"), icon: "clock.arrow.circlepath", index: 3) { onOpen("history") }
                WlHomeAction(label: L("wa_action_pin"), icon: "gearshape.fill", index: 4) { onOpen("security") }
            }
            .padding(.top, 12)

            WaSectionTitle(title: L("wa_recent"), topPadding: 12) {
                Button { onOpen("history") } label: {
                    HStack(spacing: 2) {
                        Text(L("wa_view_all")).font(.dorr(13, .bold))
                        Image(systemName: "chevron.forward").font(.system(size: 12, weight: .bold))
                    }
                    .foregroundStyle(wa.red)
                }
                .buttonStyle(.plain)
            }
            .waRise(5)

            WaCard {
                if let rows = recent {
                    if rows.isEmpty {
                        WaEmpty(icon: "sparkles", tone: .red, title: L("wa_no_tx"), text: L("wa_no_tx_hint")) {
                            WaButton(text: L("wa_topup_now"), icon: "plus") { onOpen("topup") }.padding(.horizontal, 40)
                        }
                    } else {
                        VStack(spacing: 0) {
                            ForEach(Array(rows.enumerated()), id: \.offset) { index, tx in
                                WlTxRow(tx: tx, currency: currency) { onTx(tx) }
                                if index < rows.count - 1 { WaDivider() }
                            }
                        }
                        .padding(.horizontal, 14)
                        .padding(.vertical, 4)
                    }
                } else {
                    VStack(spacing: 0) { ForEach(0..<3, id: \.self) { _ in WlTxSkeleton() } }.padding(.horizontal, 14)
                }
            }
            .waRise(6)
        }
        .task(id: "\(network.reconnectTick)|\(balance?.totalMinor ?? -1)") { await load() }
    }

    private func load() async {
        if let data = await WalletApi.transactions(page: 1, perPage: 5).value { recent = data }
    }
}

@MainActor
private struct WlHomeAction: View {
    @Environment(\.wa) private var wa
    let label: String
    let icon: String
    let index: Int
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            VStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 19, weight: .semibold)).foregroundStyle(wa.red).frame(height: 22)
                Text(label).font(.dorr(11, .bold)).foregroundStyle(wa.ink).lineLimit(1)
            }
            .padding(.top, 10)
            .padding(.bottom, 8)
            .padding(.horizontal, 2)
            .frame(maxWidth: .infinity)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.red.opacity(0.3), lineWidth: 1))
        }
        .buttonStyle(WaPressStyle(scale: 0.93))
        .frame(maxWidth: .infinity)
        .waRise(index)
    }
}

/// The total counting up to its value (Android `Animatable` over 900 ms).
@MainActor
private struct WlCountingMoney: View, Animatable {
    var value: Double
    var animatableData: Double {
        get { value }
        set { value = newValue }
    }

    var body: some View {
        Text(verbatim: money(Int(value))).font(.dorr(28, .heavy)).kerning(-0.3).foregroundStyle(.white).lineLimit(1)
    }
}

/// The balance card of the DORR app: the wallet number with copy, the total (counting up), then withdrawable | services only.
@MainActor
private struct WlHeroCard: View {
    @Environment(\.wa) private var wa
    @Environment(\.walletHost) private var host
    let balance: WalletBalanceDto
    let hidden: Bool
    let onExplain: () -> Void
    @State private var copied = false
    @State private var shown: Double = 0

    var body: some View {
        let currencyCode = balance.currencyCode ?? ""
        let number = balance.walletNumberFormatted ?? ""
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(L("wa_wallet_number")).font(.dorr(11, .medium)).foregroundStyle(.white.opacity(0.68)).lineLimit(1)
                    if !number.isEmpty {
                        Text(verbatim: "\u{2066}\(number)\u{2069}").font(.dorr(13, .bold)).foregroundStyle(.white).lineLimit(1)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if !number.isEmpty {
                    WlHeroCircle(icon: copied ? "checkmark" : "doc.on.doc") {
                        UIPasteboard.general.string = number
                        copied = true
                        host?.showToast(L("wa_number_copied"))
                    }
                }
            }
            Color.white.opacity(0.16).frame(height: 1).padding(.vertical, 10)
            HStack(spacing: 5) {
                Image(systemName: "wallet.pass.fill").font(.system(size: 10)).foregroundStyle(.white.opacity(0.9))
                Text(L("wa_total_balance")).font(.dorr(11, .semibold)).foregroundStyle(.white.opacity(0.92))
            }
            .padding(.horizontal, 9)
            .padding(.vertical, 4)
            .background(Color.white.opacity(0.12), in: Capsule())
            .overlay(Capsule().stroke(Color.white.opacity(0.16), lineWidth: 1))
            HStack(alignment: .lastTextBaseline, spacing: 8) {
                if hidden {
                    Text(verbatim: "******").font(.dorr(28, .heavy)).foregroundStyle(.white)
                } else {
                    WlCountingMoney(value: shown)
                }
                if !currencyCode.isEmpty {
                    Text(verbatim: currencyCode).font(.dorr(14, .bold)).foregroundStyle(.white.opacity(0.82))
                }
            }
            .environment(\.layoutDirection, .leftToRight)
            .padding(.top, 6)
            .padding(.bottom, 10)
            HStack(spacing: 0) {
                WlHeroPart(icon: "checkmark", label: L("wa_withdrawable"),
                           value: hidden ? "****" : "\(money(balance.withdrawableMinor ?? 0)) \(currencyCode)", onClick: onExplain)
                Color.white.opacity(0.22).frame(width: 1, height: 34)
                WlHeroPart(icon: "gift.fill", label: L("wa_spend_only"),
                           value: hidden ? "****" : "\(money(balance.spendOnlyMinor ?? 0)) \(currencyCode)", onClick: onExplain)
            }
            .padding(.vertical, 9)
            .padding(.horizontal, 4)
            .background(Color.white.opacity(0.12), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            if (balance.heldMinor ?? 0) > 0 {
                HStack(spacing: 6) {
                    Image(systemName: "pause.circle.fill").font(.system(size: 14))
                    Text(verbatim: L("wa_held_temporarily") + " " + (hidden ? "****" : money(balance.heldMinor ?? 0)) + " " + currencyCode).font(.dorr(12))
                }
                .foregroundStyle(.white.opacity(0.92))
                .padding(.top, 10)
            }
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 12)
        .background {
            ZStack(alignment: .bottomLeading) {
                RoundedRectangle(cornerRadius: 24, style: .continuous).fill(wa.heroGradient)
                Circle().fill(Color.white.opacity(0.06)).frame(width: 150, height: 150).offset(x: -30, y: 50)
            }
            .clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous))
            .shadow(color: wa.red.opacity(0.32), radius: 14, y: 6)
        }
        .onAppear { withAnimation(.easeInOut(duration: 0.9)) { shown = Double(balance.totalMinor ?? 0) } }
        .onChange(of: balance.totalMinor) { total in withAnimation(.easeInOut(duration: 0.9)) { shown = Double(total ?? 0) } }
        .task(id: copied) {
            guard copied else { return }
            try? await Task.sleep(nanoseconds: 1_600_000_000)
            copied = false
        }
    }
}

@MainActor
private struct WlHeroCircle: View {
    let icon: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 14, weight: .semibold)).foregroundStyle(.white)
                .frame(width: 32, height: 32)
                .overlay(Circle().stroke(Color.white.opacity(0.4), lineWidth: 1))
                .contentShape(Circle())
        }
        .buttonStyle(.plain)
    }
}

@MainActor
private struct WlHeroPart: View {
    let icon: String
    let label: String
    let value: String
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 6) {
                    Image(systemName: icon).font(.system(size: 12, weight: .semibold)).foregroundStyle(.white.opacity(0.92))
                    Text(label).font(.dorr(11)).foregroundStyle(.white.opacity(0.86)).lineLimit(1)
                }
                Text(verbatim: "\u{2066}\(value)\u{2069}").font(.dorr(14, .heavy)).foregroundStyle(.white).lineLimit(1)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.top, 6)
            }
            .padding(.horizontal, 10)
            .contentShape(Rectangle())
        }
        .buttonStyle(WaPressStyle(scale: 0.96))
        .frame(maxWidth: .infinity)
    }
}

// MARK: - movements

@MainActor
private struct WlTxSkeleton: View {
    var body: some View {
        HStack(spacing: 12) {
            WaSkeleton(cornerRadius: 15).frame(width: 44, height: 44)
            GeometryReader { proxy in
                VStack(alignment: .leading, spacing: 8) {
                    WaSkeleton().frame(width: proxy.size.width * 0.55, height: 12)
                    WaSkeleton().frame(width: proxy.size.width * 0.35, height: 10)
                }
                .frame(maxHeight: .infinity)
            }
            .frame(height: 44)
            WaSkeleton().frame(width: 60, height: 14)
        }
        .padding(.vertical, 12)
    }
}

/// The home and statement row: a round brand-tinted icon, the type, and the signed amount with the day and time under it.
@MainActor
private struct WlTxRow: View {
    @Environment(\.wa) private var wa
    let tx: WalletTransactionDto
    let currency: String
    let onClick: () -> Void

    var body: some View {
        let type = tx.type ?? ""
        let credit = (tx.direction ?? "credit") == "credit"
        Button(action: onClick) {
            HStack(spacing: 12) {
                WaIconWell(icon: txMeta(type).icon, tone: .red, size: 36, iconSize: 16)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: (tx.typeLabel ?? "").isEmpty ? type : (tx.typeLabel ?? "")).font(.dorr(13, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                    if tx.bucket == "spend_only" {
                        Text(L("wa_services_only_badge")).font(.dorr(10, .bold)).foregroundStyle(wa.red)
                            .padding(.horizontal, 7).padding(.vertical, 2)
                            .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 7, style: .continuous))
                            .padding(.top, 3)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                VStack(alignment: .trailing, spacing: 0) {
                    HStack(alignment: .lastTextBaseline, spacing: 4) {
                        Text(verbatim: (credit ? "+ " : "- ") + money(tx.amountMinor ?? 0)).font(.dorr(13, .bold)).foregroundStyle(credit ? wa.green : wa.ink)
                        if !currency.isEmpty { Text(verbatim: currency).font(.dorr(11)).foregroundStyle(wa.mut) }
                    }
                    .environment(\.layoutDirection, .leftToRight)
                    Text(verbatim: dayText(tx.createdAt) + "، " + timeOf(tx.createdAt)).font(.dorr(11)).foregroundStyle(wa.soft).padding(.top, 2)
                }
            }
            .padding(.vertical, 13)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// One movement, in a sheet: its kind, the signed amount, the day, the balance bucket, the balance after, the note, its number.
@MainActor
private struct WlTxSheet: View {
    @Environment(\.wa) private var wa
    let tx: WalletTransactionDto
    let currency: String
    let onClose: () -> Void

    var body: some View {
        let type = tx.type ?? ""
        let credit = (tx.direction ?? "credit") == "credit"
        let spend = tx.bucket == "spend_only"
        let label = (tx.typeLabel ?? "").isEmpty ? type : (tx.typeLabel ?? "")
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                VStack(spacing: 0) {
                    WaIconWell(icon: txMeta(type).icon, tone: .red, size: 60, iconSize: 28)
                    Text(verbatim: label).font(.dorr(18, .heavy)).foregroundStyle(wa.ink).multilineTextAlignment(.center).padding(.top, 10)
                    Text(verbatim: "\u{2066}" + (credit ? "+ " : "− ") + money(tx.amountMinor ?? 0) + " " + currency + "\u{2069}")
                        .font(.dorr(26, .heavy)).foregroundStyle(credit ? wa.green : wa.danger).padding(.top, 6)
                }
                .frame(maxWidth: .infinity)
                Spacer().frame(height: 14)
                WaKeyValue(label: L("wa_date"), value: dayText(tx.createdAt) + " · " + timeOf(tx.createdAt), icon: "calendar")
                WaDivider()
                WaKeyValue(label: L("wa_bucket"), value: L(spend ? "wa_spend_only" : "wa_withdrawable"), icon: spend ? "gift.fill" : "building.columns.fill")
                WaDivider()
                WaKeyValue(label: L("wa_balance_after"), value: money(tx.balanceAfterMinor ?? 0) + " " + currency, ltr: true, icon: "wallet.pass.fill")
                if let note = tx.note, !note.trimmingCharacters(in: .whitespaces).isEmpty, note != tx.typeLabel {
                    WaDivider()
                    WaKeyValue(label: L("wa_note"), value: note, icon: "text.alignleft")
                }
                WaDivider()
                WaKeyValue(label: L("wa_tx_number"), value: String((tx.uuid ?? "").prefix(8)), valueColor: wa.soft, icon: "number")
                if spend && credit {
                    Spacer().frame(height: 12)
                    WaNote(text: L("wa_spend_only_received_note"))
                }
                Spacer().frame(height: 14)
                WaButton(text: L("wa_close"), style: .ghost, action: onClose)
            }
            .padding(.horizontal, 20)
            .padding(.top, 24)
            .padding(.bottom, 26)
        }
        .background(wa.surface.ignoresSafeArea())
    }
}

@MainActor
private struct WlExplainSheet: View {
    @Environment(\.wa) private var wa
    let onClose: () -> Void

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                VStack(spacing: 0) {
                    WaIconWell(icon: "wallet.pass.fill", tone: .red, size: 60, iconSize: 28)
                    Text(L("wa_explain_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink).padding(.top, 10)
                }
                .frame(maxWidth: .infinity)
                WlExplainRow(icon: "building.columns.fill", tone: .green, title: L("wa_withdrawable"), text: L("wa_explain_withdrawable"))
                WlExplainRow(icon: "gift.fill", tone: .pink, title: L("wa_spend_only"), text: L("wa_explain_spend_only"))
                WlExplainRow(icon: "pause.circle.fill", tone: .amber, title: L("wa_held"), text: L("wa_explain_held"))
                Spacer().frame(height: 8)
                WaButton(text: L("wa_understood"), action: onClose)
            }
            .padding(.horizontal, 20)
            .padding(.top, 24)
            .padding(.bottom, 26)
        }
        .background(wa.surface.ignoresSafeArea())
    }
}

@MainActor
private struct WlExplainRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let tone: Tone
    let title: String
    let text: String

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            WaIconWell(icon: icon, tone: tone)
            VStack(alignment: .leading, spacing: 0) {
                Text(title).font(.dorr(14, .heavy)).foregroundStyle(wa.ink)
                Text(text).font(.dorr(12.5)).foregroundStyle(wa.mut).lineSpacing(6)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(.vertical, 12)
    }
}

// MARK: - the statement

@MainActor
private struct WlWalletHistory: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let currency: String
    let onBack: () -> Void
    let onOpen: (WalletTransactionDto) -> Void
    @State private var filter = "all"
    // A new filter is a new list (it starts from the first page); more comes while I scroll.
    @StateObject private var paged = PagedRows<WalletTransactionDto>(perPage: 15, key: { $0.uuid ?? "" })

    var body: some View {
        let rows = paged.rows
        let loading = !paged.loaded
        WaPage(title: L("wa_history_title"), onBack: onBack, onNearEnd: { Task { @MainActor in await paged.loadMore() } }) {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach([("all", "wa_filter_all", "list.bullet"), ("credit", "wa_filter_in", "arrow.down.left"),
                             ("debit", "wa_filter_out", "arrow.up.right"), ("spend_only", "wa_spend_only", "gift.fill")], id: \.0) { value, label, icon in
                        WaChip(text: L(label), icon: icon, selected: filter == value) {
                            if filter != value { filter = value }
                        }
                    }
                }
                .padding(.vertical, 2)
            }
            .padding(.bottom, 10)
            if loading && rows.isEmpty {
                WaCard { VStack(spacing: 0) { ForEach(0..<5, id: \.self) { _ in WlTxSkeleton() } }.padding(.horizontal, 14) }
            } else if rows.isEmpty {
                WaCard { WaEmpty(icon: "clock.arrow.circlepath", tone: .gray, title: L("wa_history_empty_title"), text: L("wa_history_empty_text")) }
            } else {
                WaCard {
                    VStack(spacing: 0) {
                        ForEach(Array(rows.enumerated()), id: \.offset) { index, tx in
                            WlTxRow(tx: tx, currency: currency) { onOpen(tx) }
                            if index < rows.count - 1 { WaDivider() }
                        }
                    }
                    .padding(.horizontal, 14)
                    .padding(.vertical, 4)
                }
                Spacer().frame(height: 12)
                PagedFooter(paged: paged) { Task { @MainActor in await paged.loadMore() } }
            }
        }
        .task(id: "\(filter)|\(network.reconnectTick)") {
            let current = filter
            paged.reset { page in
                await WalletApi.transactions(page: page, perPage: 15,
                                             direction: current == "credit" || current == "debit" ? current : nil,
                                             bucket: current == "spend_only" ? current : nil)
            }
            await paged.reload()
        }
    }
}

// MARK: - topping up

private let quickAmounts = [50, 100, 200, 500]

/// Top up, in the DORR app's look: the amount on the gradient card (with quick amounts and the expected balance), the payment
/// methods, the live quote in the bottom dock, the PIN on the pad, then the hand-off — the test bank in an in-app layer, a real
/// gateway in the browser — and a waiting / OTP / paid / failed page. The payment is checked when the app comes back to the
/// front, when the test bank layer closes, and on a live notice; never on a timer.
@MainActor
private struct WlWalletTopup: View {
    @Environment(\.wa) private var wa
    @Environment(\.walletHost) private var host
    @Environment(\.scenePhase) private var scenePhase
    @EnvironmentObject private var network: NetworkMonitor
    let balance: WalletBalanceDto?
    let hidden: Bool
    let onBack: () -> Void
    let onStatement: () -> Void
    let onPaid: () -> Void

    @State private var methods: [PaymentMethodDto]?
    @State private var methodId: Int?
    @State private var amountText = ""
    @State private var quote: TopupQuoteDto?
    // Same key on a retry of the *same* request, so a dropped connection can't double-charge.
    @State private var key = newIdempotencyKey()
    @State private var askPin = false
    // Kept in memory only for this attempt (the OTP step needs it again).
    @State private var attemptPin: String?
    @State private var payment: TopupPaymentDto?
    @State private var gateway = ""
    @State private var browserUrl: String?
    @State private var otp = ""
    @State private var otpBusy = false
    @State private var currentBalance: WalletBalanceDto?
    @State private var balanceTaken = false

    private var currency: String { balance?.currencyCode ?? "" }
    private var amountMinor: Int? { parseAmountToMinor(amountText) }

    var body: some View {
        content
            .onAppear {
                if !balanceTaken { currentBalance = balance; balanceTaken = true }
            }
            .task(id: network.reconnectTick) {
                if let data = await WalletApi.paymentMethods().value {
                    methods = data
                    if methodId == nil { methodId = data.first { $0.comingSoon != true }?.id ?? nil }
                }
            }
            // The quote follows the amount and the method (after a short pause, so typing "100" is one request, not three).
            .task(id: "\(amountMinor ?? -1)|\(methodId ?? -1)") {
                quote = nil
                guard let minor = amountMinor, let id = methodId else { return }
                try? await Task.sleep(nanoseconds: 400_000_000)
                if Task.isCancelled { return }
                let result = await WalletApi.quote(TopupRequest(paymentMethodId: id, amountMinor: minor))
                if Task.isCancelled { return }
                if let data = result.value { quote = data } else { host?.showError(result.errorText(L("wa_error_network")) ?? L("wa_error_generic")) }
                key = newIdempotencyKey()
            }
            .onChange(of: scenePhase) { phase in
                if phase == .active && payment?.status == "pending" { Task { @MainActor in await check() } }
            }
            .onReceive(Realtime.shared.changed) { _ in
                if payment != nil { Task { @MainActor in await check() } }
            }
    }

    @ViewBuilder private var content: some View {
        if let started = payment {
            ZStack {
                WlTopupStage(
                    payment: started, gateway: gateway, currency: currency, balanceNow: currentBalance,
                    otp: Binding(get: { otp }, set: { otp = String(asciiDigits($0).prefix(8)) }), otpBusy: otpBusy,
                    onConfirmOtp: { confirmOtp(started) },
                    onReopen: {
                        if let url = started.redirectUrl { if gateway == "sandbox" { browserUrl = url } else { openUrl(url) } }
                    },
                    onCheck: { Task { @MainActor in await check() } },
                    onDone: { attemptPin = nil; onBack() },
                    onStatement: { attemptPin = nil; onStatement() },
                    onRetry: { attemptPin = nil; payment = nil; otp = ""; browserUrl = nil; key = newIdempotencyKey() },
                    onCancel: { attemptPin = nil; payment = nil; otp = ""; browserUrl = nil },
                    onBack: onBack
                )
                GatewayLayer(url: browserUrl) {
                    browserUrl = nil
                    Task { @MainActor in await check() }
                }
            }
        } else if askPin {
            WaPage(title: L("wa_topup_title"), onBack: { askPin = false }, scroll: false) {
                WaPinPad(title: L("wa_pin_enter_title"), sub: L("wa_pin_topup_sub")) { pin in await start(pin) }
                    .frame(maxHeight: .infinity)
            }
        } else {
            form
        }
    }

    private var form: some View {
        let paying = methods?.first { $0.id == methodId }
        return WaPage(title: L("wa_topup_title"), onBack: onBack, ctaReserve: 220, ctaDock: true, cta: {
            QuoteCard(
                amountMinor: quote?.paidAmountMinor ?? amountMinor ?? 0,
                feeMinor: quote?.feeMinor ?? 0,
                bonusMinor: quote?.bonusMinor ?? 0,
                currency: currency,
                methodName: paying?.name ?? paying?.code
            )
            WaButton(text: L("wa_continue_pay"), icon: "chevron.backward", enabled: quote != nil, iconAfter: true, spread: true) { askPin = true }
            WaCtaHint(text: L("wa_secure_payments"), icon: "checkmark.shield.fill")
        }) {
            let added = quote?.totalCreditedMinor ?? amountMinor ?? 0
            let expected = (currentBalance?.totalMinor ?? 0) + added
            WaAmountEntry(
                label: L("wa_topup_how_much"),
                amountText: $amountText,
                currency: currency,
                quick: quickAmounts,
                invalid: !amountText.trimmingCharacters(in: .whitespaces).isEmpty && amountMinor == nil,
                hero: true,
                subtitle: L("wa_topup_how_much_sub"),
                footerLabel: L("wa_topup_expected"),
                footerValue: hidden ? "****" : money(expected) + " " + currency
            )
            .waRise(0)
            HStack(spacing: 0) {
                VStack(alignment: .leading, spacing: 1) {
                    Text(L("wa_pay_method")).font(.dorr(13, .bold)).foregroundStyle(wa.ink)
                    Text(L("wa_pay_method_sub")).font(.dorr(11)).foregroundStyle(wa.mut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if let n = methods?.count { Text(L("wa_pay_methods_n", n)).font(.dorr(11)).foregroundStyle(wa.mut) }
            }
            .padding(.top, 18)
            .padding(.bottom, 8)
            .waRise(1)
            VStack(spacing: 0) {
                if let list = methods {
                    if list.isEmpty {
                        WaCard { WaEmpty(icon: "banknote.fill", tone: .gray, title: L("wa_no_methods"), text: L("wa_no_methods_text")) }
                    } else {
                        ForEach(Array(list.enumerated()), id: \.offset) { _, method in
                            MethodCard(method: method, selected: method.id == methodId) {
                                if method.comingSoon == true { host?.showToast(L("wa_method_coming_soon_toast")) } else { methodId = method.id }
                            }
                        }
                    }
                } else {
                    ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 16).frame(height: 48).padding(.bottom, 8) }
                }
            }
            .waRise(2)
        }
    }

    private func start(_ pin: String) async -> PadResult {
        guard let id = methodId, let minor = amountMinor else { return .error(L("wa_error_generic")) }
        let result = await WalletApi.startTopup(pin: pin, key: key, TopupRequest(paymentMethodId: id, amountMinor: minor))
        switch result {
        case .ok(let data, _):
            key = newIdempotencyKey()
            attemptPin = pin
            gateway = methods?.first { $0.id == id }?.gateway ?? ""
            payment = data
            askPin = false
            if data.status == "pending", let url = data.redirectUrl {
                if gateway == "sandbox" { browserUrl = url } else { openUrl(url) }
            }
            return .ok
        // No answer from the server: keep the key, so a retry is safe.
        case .offline:
            return .error(L("wa_error_network"))
        case .failed(let error):
            if error.errorCode?.hasPrefix("wallet_pin") != true { key = newIdempotencyKey() }
            return padFailure(result, L("wa_error_network"), L("wa_error_generic"))
        }
    }

    private func confirmOtp(_ started: TopupPaymentDto) {
        guard let pin = attemptPin else { return }
        otpBusy = true
        Task { @MainActor in
            let result = await WalletApi.confirmTopup(pin: pin, started.uuid ?? "", OtpBody(otp: otp))
            if let data = result.value {
                payment = data
                if data.status == "paid" {
                    if let fresh = await WalletApi.balance().value { currentBalance = fresh }
                    onPaid()
                }
            } else {
                host?.showError(result.errorText(L("wa_error_network")) ?? L("wa_error_generic"))
            }
            otpBusy = false
        }
    }

    private func check() async {
        guard let uuid = payment?.uuid else { return }
        guard let data = await WalletApi.topup(uuid).value else { return }
        if data.status == "paid" && payment?.status != "paid" {
            browserUrl = nil
            if let fresh = await WalletApi.balance().value { currentBalance = fresh }
            onPaid()
        }
        payment = data
    }
}

@MainActor
private struct WlTopupStage: View {
    @Environment(\.wa) private var wa
    let payment: TopupPaymentDto
    let gateway: String
    let currency: String
    let balanceNow: WalletBalanceDto?
    @Binding var otp: String
    let otpBusy: Bool
    let onConfirmOtp: () -> Void
    let onReopen: () -> Void
    let onCheck: () -> Void
    let onDone: () -> Void
    let onStatement: () -> Void
    let onRetry: () -> Void
    let onCancel: () -> Void
    let onBack: () -> Void

    var body: some View {
        WaPage(title: L("wa_topup_title"), onBack: onBack, scroll: false) {
            ZStack {
                stage
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
    }

    @ViewBuilder private var stage: some View {
        let status = payment.status ?? "pending"
        if status == "paid" {
            WaConfetti()
            let amount = payment.amountMinor ?? 0, fee = payment.feeMinor ?? 0, bonus = payment.bonusMinor ?? 0
            WaStatusColumn {
                WaSeal()
                WaStatusTitle(text: L("wa_topup_done_title"))
                HStack(alignment: .lastTextBaseline, spacing: 6) {
                    Text(verbatim: "+ " + money(amount + bonus - fee)).font(.dorr(34, .heavy)).kerning(-0.5).foregroundStyle(wa.ink)
                    Text(verbatim: currency).font(.dorr(15, .bold)).foregroundStyle(wa.mut)
                }
                .environment(\.layoutDirection, .leftToRight)
                WaCard(padding: 14) {
                    WaKeyValue(label: L("wa_recap_paid"), value: money(amount) + " " + currency, ltr: true)
                    if fee > 0 { WaDivider(); WaKeyValue(label: L("wa_quote_fee"), value: "− " + money(fee) + " " + currency, ltr: true) }
                    if bonus > 0 { WaDivider(); WaKeyValue(label: L("wa_recap_bonus"), value: "+ " + money(bonus) + " " + currency, ltr: true) }
                    WaDivider()
                    WaKeyValue(label: L("wa_recap_balance_now"), value: balanceNow.map { money($0.totalMinor ?? 0) + " " + currency } ?? "…", ltr: true)
                }
                .padding(.top, 16)
                .padding(.bottom, 6)
                Spacer().frame(height: 12)
                WaButton(text: L("wa_done"), action: onDone)
                WaButton(text: L("wa_view_statement"), style: .quiet, action: onStatement)
            }
        } else if status == "pending" && payment.requiresOtp == true {
            WaStatusColumn {
                WaPulse(icon: "phone.fill")
                WaStatusTitle(text: L("wa_otp_title"))
                WaStatusText(text: L("wa_otp_text"))
                Spacer().frame(height: 16)
                OtpField(value: $otp)
                Spacer().frame(height: 12)
                WaButton(text: L("wa_otp_confirm"), enabled: otp.count >= 4, loading: otpBusy, action: onConfirmOtp)
                WaButton(text: L("wa_cancel"), style: .quiet, action: onCancel)
            }
        } else if status == "pending" {
            WaStatusColumn {
                WaPulse(icon: "banknote.fill")
                HStack(spacing: 0) {
                    Text(L("wa_waiting_title")).font(.dorr(22, .heavy)).foregroundStyle(wa.ink)
                    WaLoadingDots()
                }
                .padding(.top, 18)
                .padding(.bottom, 6)
                WaStatusText(text: L("wa_waiting_text"))
                Spacer().frame(height: 12)
                if payment.redirectUrl != nil {
                    WaButton(text: L("wa_open_payment_page"), style: .ghost, icon: "arrow.up.right.square", action: onReopen)
                }
                WaButton(text: L("wl_check"), style: .ghost, icon: "arrow.clockwise", action: onCheck)
                WaButton(text: L("wa_cancel"), style: .quiet, action: onCancel)
            }
        } else {
            WaStatusColumn {
                WaSeal(bad: true)
                WaStatusTitle(text: L("wa_topup_failed_title"))
                WaStatusText(text: L("wa_topup_failed_text"))
                Spacer().frame(height: 12)
                WaButton(text: L("wa_try_again"), icon: "arrow.clockwise", action: onRetry)
                WaButton(text: L("wa_back_to_wallet"), style: .quiet, action: onBack)
            }
        }
    }
}

// MARK: - withdrawing

/// Withdraw, in the wallet look: where to (a bank account or a mobile wallet — added with the PIN), the amount on the gradient
/// card (up to what can be withdrawn), the PIN on the pad, then the request — the money is held until an admin pays it out.
/// Under it, my requests with their status and the transfer receipt once paid.
@MainActor
private struct WlWalletWithdraw: View {
    @Environment(\.wa) private var wa
    @Environment(\.walletHost) private var host
    @EnvironmentObject private var network: NetworkMonitor
    let balance: WalletBalanceDto?
    let onBack: () -> Void

    // Its own copy of the balance: a request holds money, so what can be withdrawn changes on this page.
    @State private var current: WalletBalanceDto?
    @State private var balanceTaken = false
    @State private var methods: [WithdrawalMethodDto]?
    @StateObject private var requests = PagedRows<WithdrawalDto>(perPage: 25, key: { $0.id ?? 0 }, fetch: { page in await WalletApi.withdrawals(page: page) })
    @State private var methodId: Int?
    @State private var adding = false
    @State private var type = "bank"
    @State private var label = ""
    @State private var bankName = ""
    @State private var holder = ""
    @State private var iban = ""
    @State private var provider = ""
    @State private var number = ""
    @State private var amountText = ""
    @State private var key = newIdempotencyKey()
    // Which action the pad is open for: "method" (save the destination) or "withdraw" (send the request).
    @State private var pinFor: String?

    private var currency: String { current?.currencyCode ?? "" }
    private var withdrawable: Int { current?.withdrawableMinor ?? 0 }
    private var minor: Int? { parseAmountToMinor(amountText) }
    private var tooMuch: Bool { minor.map { $0 > withdrawable } ?? false }

    var body: some View {
        content
            .onAppear {
                if !balanceTaken { current = balance; balanceTaken = true }
            }
            .task(id: network.reconnectTick) { await load() }
            .onReceive(Realtime.shared.changed) { _ in Task { @MainActor in await load() } }
            // A different request is a different key (the same key with another amount would be refused as a conflict).
            .onChange(of: amountText) { _ in key = newIdempotencyKey() }
            .onChange(of: methodId) { _ in key = newIdempotencyKey() }
    }

    @ViewBuilder private var content: some View {
        if let action = pinFor {
            WaPage(title: L("wl_wd_title"), onBack: { pinFor = nil }, scroll: false) {
                WaPinPad(title: L("wa_pin_enter_title"), sub: L(action == "method" ? "wl_wd_save" : "wl_wd_send")) { pin in
                    await submit(action, pin)
                }
                .frame(maxHeight: .infinity)
            }
        } else {
            form
        }
    }

    private var form: some View {
        let canSend = methodId != nil && minor != nil && !tooMuch
        let canSave = type == "bank"
            ? !bankName.trimmingCharacters(in: .whitespaces).isEmpty && !holder.trimmingCharacters(in: .whitespaces).isEmpty && iban.count >= 15
            : !provider.trimmingCharacters(in: .whitespaces).isEmpty && number.count >= 6
        return WaPage(title: L("wl_wd_title"), onBack: onBack, hasCta: true, onNearEnd: { Task { @MainActor in await requests.loadMore() } }, actions: { EmptyView() }, cta: {
            if adding {
                WaButton(text: L("wl_wd_save"), icon: "checkmark", enabled: canSave) { pinFor = "method" }
            } else {
                WaButton(text: L("wl_wd_send"), icon: "building.columns.fill", enabled: canSend) { pinFor = "withdraw" }
            }
            WaCtaHint(text: L("wa_secure_payments"), icon: "lock.fill")
        }) {
            if !adding {
                WaAmountEntry(
                    label: L("wl_wd_title"),
                    amountText: $amountText,
                    currency: currency,
                    invalid: !amountText.trimmingCharacters(in: .whitespaces).isEmpty && minor == nil,
                    hero: true,
                    subtitle: L("wl_wd_can", money(withdrawable) + " " + currency),
                    footerLabel: L("wa_withdrawable"),
                    footerValue: money(withdrawable) + " " + currency
                )
                .waRise(0)
                if tooMuch { Text(L("wl_wd_too_much")).font(.dorr(12)).foregroundStyle(wa.danger).padding(.top, 6) }
                HStack {
                    Spacer()
                    WaChip(text: L("wl_all"), selected: minor == withdrawable && withdrawable > 0) {
                        amountText = String(format: "%ld.%02ld", withdrawable / 100, withdrawable % 100)
                    }
                }
                .padding(.top, 8)
            }

            WaSectionTitle(title: L(adding ? "wl_wd_add" : "wl_wd_to"), topPadding: 14)
            if methods == nil {
                ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 16).frame(height: 48).padding(.bottom, 8) }
            }
            if adding {
                addForm
            } else {
                ForEach(Array((methods ?? []).enumerated()), id: \.offset) { _, m in
                    WlDestinationCard(m: m, selected: methodId == m.id, onClick: { methodId = m.id }, onDelete: {
                        Task { @MainActor in
                            if let failed = await WalletApi.done({ await WalletApi.deleteWithdrawalMethod(m.id ?? 0) },
                                                                 offline: L("wa_error_network"), generic: L("wa_error_generic")) {
                                host?.showError(failed)
                            }
                            await load()
                        }
                    })
                }
                Button { adding = true } label: {
                    HStack(spacing: 6) {
                        Image(systemName: "plus").font(.system(size: 15, weight: .bold))
                        Text(L("wl_wd_add")).font(.dorr(13, .bold))
                    }
                    .foregroundStyle(wa.red)
                    .padding(.vertical, 6)
                    .padding(.horizontal, 4)
                }
                .buttonStyle(.plain)
            }

            WaSectionTitle(title: L("wl_wd_requests"), topPadding: 16)
            WaCard {
                if requests.rows.isEmpty {
                    WaEmpty(icon: "banknote.fill", tone: .gray, title: L("wl_wd_none"), text: L("wl_wd_can", money(withdrawable) + " " + currency))
                } else {
                    VStack(spacing: 0) {
                        ForEach(Array(requests.rows.enumerated()), id: \.offset) { index, r in
                            WlWithdrawalRow(r: r, currency: currency) { openReceipt(r) }
                            if index < requests.rows.count - 1 { WaDivider() }
                        }
                    }
                    .padding(.horizontal, 14)
                    .padding(.vertical, 4)
                }
            }
            PagedFooter(paged: requests) { Task { @MainActor in await requests.loadMore() } }
        }
    }

    @ViewBuilder private var addForm: some View {
        HStack(spacing: 8) {
            WaChip(text: L("wl_bank"), icon: "building.columns.fill", selected: type == "bank") { type = "bank" }
            WaChip(text: L("wl_mobile"), icon: "phone.fill", selected: type == "mobile_wallet") { type = "mobile_wallet" }
        }
        .padding(.bottom, 10)
        WaCard(padding: 16) {
            VStack(spacing: 12) {
                DorrTextField(value: Binding(get: { label }, set: { label = String($0.prefix(60)) }), label: L("wl_label"))
                if type == "bank" {
                    DorrTextField(value: Binding(get: { bankName }, set: { bankName = String($0.prefix(100)) }), label: L("wl_bank_name"))
                    DorrTextField(value: Binding(get: { holder }, set: { holder = String($0.prefix(100)) }), label: L("wl_holder"))
                    DorrTextField(value: Binding(get: { iban }, set: { iban = String($0.filter { $0.isLetter || $0.isNumber }.uppercased().prefix(34)) }),
                                  label: "IBAN", placeholder: "SA00 0000 0000 0000 0000 0000", error: !iban.isEmpty && iban.count < 15)
                } else {
                    DorrTextField(value: Binding(get: { provider }, set: { provider = String($0.prefix(100)) }), label: L("wl_provider"))
                    DorrTextField(value: Binding(get: { number }, set: { number = String(asciiDigits($0).prefix(20)) }), label: L("wl_number"),
                                  keyboard: .numberPad, error: !number.isEmpty && number.count < 6)
                }
            }
        }
        if !(methods ?? []).isEmpty {
            WaButton(text: L("wl_cancel_add"), style: .quiet) { adding = false }
        }
    }

    private func load() async {
        // A failed read keeps what is shown (it must not look like "no destinations yet" and open the add form).
        if let list = await WalletApi.withdrawalMethods().value {
            methods = list
            if methodId == nil || !list.contains(where: { $0.id == methodId }) {
                methodId = list.first { $0.isFavorite == true }?.id ?? list.first?.id ?? nil
            }
            if list.isEmpty { adding = true }
        }
        await requests.reload()
        if let data = await WalletApi.balance().value { current = data }
    }

    private func submit(_ action: String, _ pin: String) async -> PadResult {
        if action == "method" {
            var data: JsonBody = [:]
            if type == "bank" {
                data = ["bank_name": bankName.trimmingCharacters(in: .whitespaces), "account_holder": holder.trimmingCharacters(in: .whitespaces),
                        "iban": iban.trimmingCharacters(in: .whitespaces)]
            } else {
                data = ["provider_name": provider.trimmingCharacters(in: .whitespaces), "number": number.trimmingCharacters(in: .whitespaces)]
            }
            var body: JsonBody = ["type": type, "data": data.mapValues { $0 ?? NSNull() }]
            if !label.trimmingCharacters(in: .whitespaces).isEmpty { body["label"] = label.trimmingCharacters(in: .whitespaces) }
            let result = await WalletApi.addWithdrawalMethod(pin: pin, body)
            guard let saved = result.value else { return padFailure(result, L("wa_error_network"), L("wa_error_generic")) }
            methodId = saved.id
            adding = false
            bankName = ""; holder = ""; iban = ""; provider = ""; number = ""; label = ""
            pinFor = nil
            Task { @MainActor in await load() }
            return .ok
        }
        let body: JsonBody = ["withdrawal_method_id": methodId, "amount_minor": minor]
        let result = await WalletApi.withdraw(pin: pin, key: key, body)
        guard result.value != nil else { return padFailure(result, L("wa_error_network"), L("wa_error_generic")) }
        amountText = ""
        key = newIdempotencyKey()
        pinFor = nil
        host?.showToast(L("wl_wd_sent"))
        Task { @MainActor in await load() }
        return .ok
    }

    private func openReceipt(_ r: WithdrawalDto) {
        Task { @MainActor in
            let file = await downloadReceipt(r.id ?? 0)
            if file == nil || !openCachedFile(file!) { host?.showError(L("wa_error_generic")) }
        }
    }
}

@MainActor
private struct WlDestinationCard: View {
    @Environment(\.wa) private var wa
    let m: WithdrawalMethodDto
    let selected: Bool
    let onClick: () -> Void
    let onDelete: () -> Void

    var body: some View {
        let label = m.label?.trimmingCharacters(in: .whitespaces) ?? ""
        HStack(spacing: 10) {
            WaIconWell(icon: m.type == "bank" ? "building.columns.fill" : "phone.fill", tone: .red, size: 36, iconSize: 16)
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: label.isEmpty ? (m.display ?? "") : label).font(.dorr(13, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                if !label.isEmpty {
                    Text(verbatim: "\u{2066}\(m.display ?? "")\u{2069}").font(.dorr(11)).foregroundStyle(wa.mut).lineLimit(1)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            Button(action: onDelete) {
                Image(systemName: "trash").font(.system(size: 15)).foregroundStyle(wa.soft).frame(width: 30, height: 30).contentShape(Circle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("wl_delete_method"))
            ZStack {
                Circle().fill(selected ? wa.red : .clear)
                Circle().stroke(selected ? wa.red : (wa.night ? wa.line : Color(hex: 0xD1D5DB)), lineWidth: 1.5)
                if selected { Image(systemName: "checkmark").font(.system(size: 10, weight: .heavy)).foregroundStyle(.white) }
            }
            .frame(width: 20, height: 20)
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 10)
        .background(selected ? (wa.night ? wa.darkWell : wa.red.opacity(0.06)) : wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(selected ? wa.red : wa.line, lineWidth: 1))
        .contentShape(Rectangle())
        .onTapGesture(perform: onClick)
        .padding(.bottom, 8)
    }
}

@MainActor
private struct WlWithdrawalRow: View {
    @Environment(\.wa) private var wa
    let r: WithdrawalDto
    let currency: String
    let onReceipt: () -> Void

    var body: some View {
        let (label, tint): (String, Color) = {
            switch r.status {
            case "approved": return (L("wl_wd_approved"), wa.green)
            case "rejected": return (L("wl_wd_rejected"), wa.danger)
            default: return (L("wl_wd_pending"), Color(hex: 0xD97706))
            }
        }()
        let methodLabel = r.method?.label?.trimmingCharacters(in: .whitespaces) ?? ""
        HStack(spacing: 12) {
            WaIconWell(icon: "building.columns.fill", tone: .red, size: 36, iconSize: 16)
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: methodLabel.isEmpty ? (r.method?.display ?? "") : methodLabel).font(.dorr(13, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                Text(verbatim: dayText(r.createdAt) + "، " + timeOf(r.createdAt)).font(.dorr(11)).foregroundStyle(wa.soft).padding(.top, 2)
                if let reason = r.rejectionReason, !reason.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: reason).font(.dorr(11)).foregroundStyle(wa.mut).padding(.top, 2)
                }
                if r.hasReceipt == true {
                    Button(action: onReceipt) {
                        Text(L("wl_wd_receipt")).font(.dorr(12, .bold)).foregroundStyle(wa.red)
                    }
                    .buttonStyle(.plain)
                    .padding(.top, 4)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            VStack(alignment: .trailing, spacing: 0) {
                Text(verbatim: "\u{2066}- " + money(r.amountMinor ?? 0) + " " + (r.currencyCode ?? currency) + "\u{2069}").font(.dorr(13, .bold)).foregroundStyle(wa.ink)
                Text(label).font(.dorr(10, .bold)).foregroundStyle(tint)
                    .padding(.horizontal, 8).padding(.vertical, 2)
                    .background(tint.opacity(0.12), in: Capsule())
                    .padding(.top, 4)
            }
        }
        .padding(.vertical, 12)
    }
}

/// Downloads a withdrawal's receipt (signed in, private) into the cache, to open it in a viewer.
private func downloadReceipt(_ id: Int) async -> URL? {
    guard let data = await WalletApi.receipt(id) else { return nil }
    // The type from the file's first bytes (Android reads the response's Content-Type); a PDF by default.
    let bytes = [UInt8](data.prefix(4))
    let ext: String
    if bytes.starts(with: [0x89, 0x50, 0x4E, 0x47]) { ext = "png" }
    else if bytes.starts(with: [0xFF, 0xD8]) { ext = "jpg" }
    else { ext = "pdf" }
    let dir = FileManager.default.temporaryDirectory.appendingPathComponent("proofs", isDirectory: true)
    try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
    let file = dir.appendingPathComponent("receipt_\(id).\(ext)")
    do {
        try data.write(to: file, options: .atomic)
        return file
    } catch {
        return nil
    }
}
