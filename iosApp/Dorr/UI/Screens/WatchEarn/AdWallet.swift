import SwiftUI

/// androidApp `ui/screens/watchearn/AdWallet.kt` — the ad wallet (D12–D14): the big total, what is free and what ads
/// hold — each split by bucket in the same three colours — the ads holding money, moving money in from the wallet
/// (previewed, then the wallet PIN) and back out (previewed, the free balance only), and the statement. [fundMinor]
/// opens the fund sheet with that amount and, once funded, goes back to the page that asked (the wizard, a budget
/// top-up).
@MainActor
struct AdWallet: View {
    @EnvironmentObject private var host: WeHost
    @Environment(\.wa) private var wa
    let fundMinor: Int?
    @State private var wallet: WeAdWalletDto?
    @State private var failed = false
    @State private var reload = 0
    @State private var entries: [WeEntryDto] = []
    @State private var nextCursor: String?
    @State private var loadingMore = false
    @State private var fundOpen: Bool
    @State private var returnOpen = false
    @State private var topupOpen = false

    init(fundMinor: Int?) {
        self.fundMinor = fundMinor
        _fundOpen = State(initialValue: fundMinor != nil)
    }

    var body: some View {
        WaPage(title: L("we_ad_wallet"), onBack: { host.pop() }) {
            if let w = wallet {
                content(w)
            } else if failed {
                WaEmpty(icon: "exclamationmark.triangle.fill", tone: .red, title: L("we_load_failed"), text: L("we_load_failed_hint")) {
                    WaButton(text: L("we_retry")) { reload += 1 }.padding(.horizontal, 40)
                }
            } else {
                VStack(spacing: 12) {
                    WaSkeleton(cornerRadius: 24).frame(height: 150)
                    WaSkeleton(cornerRadius: 22).frame(height: 180)
                }
            }
        }
        .task(id: reload) { await load() }
        .weSheet(fundOpen && wallet != nil, onDismiss: { fundOpen = false }) {
            if let w = wallet {
                FundSheet(wallet: w, initialMinor: fundMinor, onDismiss: { fundOpen = false }) { fresh in
                    fundOpen = false
                    show(fresh)
                    // Opened to fund something (the wizard, more budget): back to it.
                    if fundMinor != nil { host.pop() }
                }
            }
        }
        .weSheet(returnOpen && wallet != nil, onDismiss: { returnOpen = false }) {
            if let w = wallet {
                ReturnSheet(wallet: w, onDismiss: { returnOpen = false }) { fresh in
                    returnOpen = false
                    show(fresh)
                }
            }
        }
        .weSheet(topupOpen && wallet != nil, onDismiss: { topupOpen = false }) {
            if let w = wallet {
                TopupSheet(currency: w.currency) { minor in
                    topupOpen = false
                    // The app's one payment screen prices it and takes the card (or the wallet); once paid, read the ad wallet again.
                    CheckoutLauncher.shared.open(CheckoutRequest(purpose: "watch_earn_ad_wallet", reference: ["amount_minor": .int(minor)])) {
                        host.showToast(L("we_topup_paid"))
                        reload += 1
                    }
                }
            }
        }
    }

    @ViewBuilder
    private func content(_ w: WeAdWalletDto) -> some View {
        let currency = w.currency
        TotalCard(wallet: w).waRise(0)
        HStack(spacing: 10) {
            WaButton(text: L("we_fund_from_wallet"), icon: "plus", compact: true) { fundOpen = true }
            WaButton(text: L("we_return_to_wallet"), style: .ghost, icon: "arrow.uturn.backward", enabled: w.free.totalMinor > 0, compact: true) { returnOpen = true }
        }
        .padding(.top, 12)
        .waRise(1)
        WaButton(text: L("we_topup_card"), style: .ghost, icon: "creditcard.fill", compact: true) { topupOpen = true }
            .padding(.top, 10)
            .waRise(1)

        WaSectionTitle(title: L("we_free"), topPadding: 18) {
            Text(verbatim: weMoney(w.free.totalMinor, currency)).font(.dorr(14, .heavy)).foregroundStyle(We.withdrawable).weLtr()
        }
        .waRise(2)
        WaCard(padding: 14) { WeBucketSplit(buckets: w.free, currency: currency) }.waRise(2)

        WaSectionTitle(title: L("we_locked_in_ads")) {
            Text(verbatim: weMoney(w.locked.totalMinor, currency)).font(.dorr(14, .heavy)).foregroundStyle(wa.ink).weLtr()
        }
        .waRise(3)
        WaCard(padding: 14) {
            WeBucketSplit(buckets: w.locked, currency: currency)
            if !w.ads.isEmpty {
                WaDivider().padding(.top, 8)
                ForEach(w.ads) { ad in
                    Button { host.push(.stats(adId: ad.id)) } label: {
                        HStack(spacing: 8) {
                            Image(systemName: "lock.fill").font(.system(size: 14)).foregroundStyle(wa.mut)
                            VStack(alignment: .leading, spacing: 3) {
                                Text(verbatim: ad.title).font(.dorr(13, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                                WeStatusBadge(status: ad.status)
                            }
                            Spacer(minLength: 6)
                            Text(verbatim: weMoney(ad.lockedMinor, currency)).font(.dorr(13, .heavy)).foregroundStyle(wa.ink).weLtr()
                            Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.soft)
                        }
                        .padding(.vertical, 10)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
            }
            Text(L("we_locked_note")).font(.dorr(11.5)).foregroundStyle(wa.mut).lineSpacing(4).padding(.top, 6)
        }
        .waRise(3)

        WaSectionTitle(title: L("we_statement")).waRise(4)
        WaCard {
            if entries.isEmpty {
                WaEmpty(icon: "banknote.fill", tone: .green, title: L("we_no_entries"), text: L("we_no_entries_text"))
            } else {
                VStack(spacing: 0) {
                    ForEach(Array(entries.enumerated()), id: \.element.id) { i, e in
                        EntryRow(entry: e, currency: currency)
                        if i < entries.count - 1 { WaDivider() }
                    }
                }
                .padding(.horizontal, 14)
                .padding(.vertical, 4)
            }
        }
        .waRise(4)
        if let cursor = nextCursor {
            WaButton(text: L("we_load_more"), style: .ghost, loading: loadingMore, compact: true) {
                loadingMore = true
                Task {
                    if let page = try? await WatchEarnApi.adWallet(cursor: cursor).data {
                        let known = Set(entries.map(\.id))
                        entries.append(contentsOf: page.entries.filter { !known.contains($0.id) })
                        nextCursor = page.nextCursor
                    }
                    loadingMore = false
                }
            }
            .padding(.top, 10)
        }
    }

    private func show(_ fresh: WeAdWalletDto) {
        wallet = fresh
        entries = fresh.entries
        nextCursor = fresh.nextCursor
    }

    private func load() async {
        do {
            if let fresh = try await WatchEarnApi.adWallet().data {
                show(fresh)
                failed = false
            } else {
                failed = wallet == nil
            }
        } catch {
            failed = wallet == nil
            host.showToast(error.weMessage(L("we_error_generic")), error: true)
        }
    }
}

/// The big total, counting up, on the brand gradient, with free / locked under it.
private struct TotalCard: View {
    @Environment(\.wa) private var wa
    let wallet: WeAdWalletDto

    var body: some View {
        ZStack(alignment: .bottomLeading) {
            Circle().fill(Color.white.opacity(0.07)).frame(width: 140, height: 140).offset(x: -30, y: 50)
            VStack(alignment: .leading, spacing: 0) {
                Text(L("we_ad_wallet_total")).font(.dorr(12)).foregroundStyle(.white.opacity(0.82))
                WeCountUp(target: wallet.totalMinor, format: { weMoney($0, wallet.currency) }, color: Color.white, size: 30).padding(.top, 2)
                HStack(spacing: 10) {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("we_free")).font(.dorr(11)).foregroundStyle(.white.opacity(0.85))
                        Text(verbatim: weMoney(wallet.free.totalMinor, wallet.currency)).font(.dorr(14, .heavy)).foregroundStyle(.white).weLtr()
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Color.white.opacity(0.22).frame(width: 1, height: 34)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("we_locked_in_ads")).font(.dorr(11)).foregroundStyle(.white.opacity(0.85))
                        Text(verbatim: weMoney(wallet.locked.totalMinor, wallet.currency)).font(.dorr(14, .heavy)).foregroundStyle(.white).weLtr()
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(10)
                .background(Color.white.opacity(0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .padding(.top, 12)
            }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .background(wa.heroGradient)
        .clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous))
        .shadow(color: wa.red.opacity(0.3), radius: 14, y: 6)
    }
}

private func entryMeta(_ type: String) -> (icon: String, tint: Color, label: String) {
    switch type {
    case "fund": return ("arrow.down.left", We.withdrawable, L("we_entry_fund"))
    case "topup": return ("plus", We.withdrawable, L("we_entry_topup"))
    case "return": return ("arrow.uturn.backward", We.spendOnly, L("we_entry_return"))
    case "reserve": return ("lock.fill", We.reward, L("we_entry_reserve"))
    case "release": return ("lock.open.fill", We.withdrawable, L("we_entry_release"))
    default: return ("info.circle.fill", Color(hex: 0x6B7280), L("we_entry_other"))
    }
}

/// A statement line: what happened, the ad, by bucket, the signed amount and the free balance after it.
private struct EntryRow: View {
    @Environment(\.wa) private var wa
    let entry: WeEntryDto
    let currency: WeCurrencyDto

    var body: some View {
        let meta = entryMeta(entry.type)
        // Money into the free balance is +, money out of it (back to the wallet, reserved for an ad) is −.
        let credit = entry.type == "fund" || entry.type == "topup" || entry.type == "release"
        HStack(alignment: .top, spacing: 12) {
            Image(systemName: meta.icon).font(.system(size: 16, weight: .semibold)).foregroundStyle(meta.tint)
                .frame(width: 38, height: 38)
                .background(meta.tint.opacity(0.13), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: meta.label).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink)
                if let title = entry.ad?.title { Text(verbatim: title).font(.dorr(11.5)).foregroundStyle(wa.mut).lineLimit(1) }
                WeBucketChips(buckets: entry.byBucket, currency: currency).padding(.top, 5)
                Text(verbatim: weDateTime(entry.createdAt)).font(.dorr(10.5)).foregroundStyle(wa.soft).padding(.top, 4)
            }
            Spacer(minLength: 6)
            VStack(alignment: .trailing, spacing: 0) {
                Text(verbatim: (credit ? "+ " : "− ") + weMoney(entry.amountMinor, currency))
                    .font(.dorr(13.5, .heavy)).foregroundStyle(credit ? We.withdrawable : wa.ink).weLtr()
                Text(L("we_free_after")).font(.dorr(10)).foregroundStyle(wa.soft).padding(.top, 4)
                Text(verbatim: weMoney(entry.freeAfterMinor, currency)).font(.dorr(11, .bold)).foregroundStyle(wa.mut).weLtr()
            }
        }
        .padding(.vertical, 12)
    }
}

// MARK: - fund

/// «Move from the wallet»: the amount → what each bucket gives (against the wallet's balance by bucket) → the wallet
/// PIN → done. The idempotency key is made once per attempt: kept on a dropped connection so a retry can't move it
/// twice, made anew after any answer or a new amount.
@MainActor
private struct FundSheet: View {
    @EnvironmentObject private var host: WeHost
    @Environment(\.wa) private var wa
    let wallet: WeAdWalletDto
    let initialMinor: Int?
    let onDismiss: () -> Void
    let onDone: (WeAdWalletDto) -> Void
    @State private var text: String
    @State private var preview: WeBucketsDto?
    @State private var previewing = false
    @State private var step = 0
    @State private var key = UUID().uuidString

    init(wallet: WeAdWalletDto, initialMinor: Int?, onDismiss: @escaping () -> Void, onDone: @escaping (WeAdWalletDto) -> Void) {
        self.wallet = wallet
        self.initialMinor = initialMinor
        self.onDismiss = onDismiss
        self.onDone = onDone
        _text = State(initialValue: initialMinor.map { weAmountInput($0, wallet.currency.decimals) } ?? "")
    }

    var body: some View {
        let currency = wallet.currency
        let amount = weParseAmount(text, currency.decimals)
        let available = wallet.mainWallet.totalMinor
        let tooMuch = amount.map { $0 > available } ?? false
        ZStack {
            if step == 1, let amount {
                WaPinPad(title: L("we_fund_pin_title", weMoney(amount, currency)), sub: L("we_fund_pin_sub")) { pin in
                    await fund(pin: pin, amount: amount)
                }
                .frame(height: 500)
                .padding(.horizontal, 12)
                .padding(.bottom, 16)
                .transition(.opacity.combined(with: .scale(scale: 0.94)))
            } else {
                ScrollView {
                    VStack(alignment: .leading, spacing: 12) {
                        Text(L("we_fund_from_wallet")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                        WeAmountField(text: $text, currency: currency, invalid: (!text.trimmingCharacters(in: .whitespaces).isEmpty && amount == nil) || tooMuch)
                        HStack {
                            Text(L("we_wallet_available")).font(.dorr(12.5)).foregroundStyle(wa.mut)
                            Spacer(minLength: 6)
                            Text(verbatim: weMoney(available, currency)).font(.dorr(12.5, .heavy)).foregroundStyle(tooMuch ? wa.danger : wa.ink).weLtr()
                        }
                        // Each bucket: what the wallet has, and what this move takes from it.
                        WaCard(padding: 14) {
                            HStack(spacing: 0) {
                                Text(L("we_bucket")).font(.dorr(11)).foregroundStyle(wa.soft).frame(maxWidth: .infinity, alignment: .leading)
                                Text(L("we_in_wallet")).font(.dorr(11)).foregroundStyle(wa.soft).frame(width: 92, alignment: .leading)
                                Text(L("we_takes")).font(.dorr(11)).foregroundStyle(wa.soft).frame(width: 84, alignment: .leading)
                            }
                            ForEach(WeBucket.allCases, id: \.self) { b in
                                HStack(spacing: 0) {
                                    WeDot(color: b.color)
                                    Text(b.label).font(.dorr(12.5)).foregroundStyle(wa.ink).lineLimit(1).padding(.leading, 7)
                                        .frame(maxWidth: .infinity, alignment: .leading)
                                    Text(verbatim: weAmount(b.of(wallet.mainWallet), currency.decimals)).font(.dorr(12)).foregroundStyle(wa.mut).weLtr()
                                        .frame(width: 92, alignment: .leading)
                                    Group {
                                        if let take = preview.map({ b.of($0) }) {
                                            Text(verbatim: weAmount(take, currency.decimals)).font(.dorr(12.5, .heavy)).foregroundStyle(take > 0 ? b.color : wa.soft).weLtr()
                                        } else if previewing {
                                            WaSkeleton().frame(width: 50, height: 12)
                                        } else {
                                            Text(verbatim: "—").font(.dorr(12)).foregroundStyle(wa.soft)
                                        }
                                    }
                                    .frame(width: 84, alignment: .leading)
                                }
                                .padding(.vertical, 7)
                            }
                        }
                        if tooMuch {
                            WaNote(text: L("we_fund_too_much"), icon: "exclamationmark.triangle.fill").transition(.opacity)
                        }
                        WaNote(text: L("we_fund_note"))
                        WaButton(text: L("we_continue"), icon: "lock.fill", enabled: amount != nil && !tooMuch && preview != nil) {
                            withAnimation(.easeOut(duration: 0.22)) { step = 1 }
                        }
                    }
                    .padding(.horizontal, 20)
                    .padding(.bottom, 20)
                }
                .frame(maxHeight: 560)
                .fixedSize(horizontal: false, vertical: true)
                .transition(.opacity)
            }
        }
        .animation(.easeOut(duration: 0.2), value: tooMuch)
        // What each bucket gives, asked a moment after the amount settles.
        .task(id: text) {
            preview = nil
            key = UUID().uuidString
            guard let amount, !tooMuch else { return }
            try? await Task.sleep(nanoseconds: 400_000_000)
            guard !Task.isCancelled else { return }
            previewing = true
            let take = try? await WatchEarnApi.fundPreview(WeAmountRequest(amountMinor: amount)).data?.take
            guard !Task.isCancelled else { return }
            preview = take
            previewing = false
        }
    }

    private func fund(pin: String, amount: Int) async -> PadResult {
        do {
            let fresh = try await WatchEarnApi.fund(pin: pin, WeMoveRequest(amountMinor: amount, idempotencyKey: key)).data
            key = UUID().uuidString
            host.showToast(L("we_funded"))
            if let fresh { onDone(fresh) } else { onDismiss() }
            return .ok
        } catch {
            let failure = error.apiFailure
            // A PIN problem: the pad shows it (and a lock's countdown) and stays.
            if failure.errorCode?.hasPrefix("wallet_pin") == true {
                key = UUID().uuidString
                return padFailure(error, networkError: L("we_error_network"))
            }
            // No answer at all: same key, so trying again can't move the money twice.
            if failure.httpStatus == nil { return .error(L("we_error_network")) }
            key = UUID().uuidString
            host.showToast(failure.message ?? L("we_error_generic"), error: true)
            withAnimation(.easeOut(duration: 0.22)) { step = 0 }
            return .ok
        }
    }
}

// MARK: - return

/// «Back to the wallet»: the free balance only (max = free); the preview shows what each bucket gets back.
@MainActor
private struct ReturnSheet: View {
    @EnvironmentObject private var host: WeHost
    @Environment(\.wa) private var wa
    let wallet: WeAdWalletDto
    let onDismiss: () -> Void
    let onDone: (WeAdWalletDto) -> Void
    @State private var text = ""
    @State private var preview: WeBucketsDto?
    @State private var busy = false
    @State private var key = UUID().uuidString

    var body: some View {
        let currency = wallet.currency
        let free = wallet.free.totalMinor
        let amount = weParseAmount(text, currency.decimals)
        let tooMuch = amount.map { $0 > free } ?? false
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(L("we_return_to_wallet")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                WeAmountField(text: $text, currency: currency, invalid: (!text.trimmingCharacters(in: .whitespaces).isEmpty && amount == nil) || tooMuch)
                HStack {
                    Text(L("we_free")).font(.dorr(12.5)).foregroundStyle(wa.mut)
                    Spacer(minLength: 6)
                    Button { text = weAmountInput(free, currency.decimals) } label: {
                        Text(verbatim: weMoney(free, currency)).font(.dorr(12.5, .heavy)).foregroundStyle(We.withdrawable).weLtr().padding(4)
                    }
                    .buttonStyle(.plain)
                }
                if let preview {
                    WaCard(padding: 14) {
                        Text(L("we_return_goes")).font(.dorr(13, .heavy)).foregroundStyle(wa.ink).padding(.bottom, 6)
                        WeBucketSplit(buckets: preview, currency: currency)
                    }
                    .transition(.opacity.combined(with: .move(edge: .top)))
                }
                if tooMuch {
                    WaNote(text: L("we_return_too_much"), icon: "exclamationmark.triangle.fill").transition(.opacity)
                }
                WaNote(text: L("we_locked_note"))
                WaButton(text: L("we_confirm_return"), icon: "arrow.uturn.backward", enabled: amount != nil && !tooMuch && preview != nil, loading: busy) {
                    guard let minor = amount else { return }
                    busy = true
                    Task { await send(minor) }
                }
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 20)
            .animation(.easeOut(duration: 0.22), value: preview != nil)
            .animation(.easeOut(duration: 0.22), value: tooMuch)
        }
        .frame(maxHeight: 560)
        .fixedSize(horizontal: false, vertical: true)
        .task(id: text) {
            preview = nil
            key = UUID().uuidString
            guard let amount, !tooMuch else { return }
            try? await Task.sleep(nanoseconds: 400_000_000)
            guard !Task.isCancelled else { return }
            let give = try? await WatchEarnApi.returnPreview(WeAmountRequest(amountMinor: amount)).data?.give
            guard !Task.isCancelled else { return }
            preview = give
        }
    }

    private func send(_ minor: Int) async {
        do {
            let fresh = try await WatchEarnApi.returnToWallet(WeMoveRequest(amountMinor: minor, idempotencyKey: key)).data
            key = UUID().uuidString
            host.showToast(L("we_returned"))
            if let fresh { onDone(fresh) } else { onDismiss() }
        } catch {
            let failure = error.apiFailure
            if failure.httpStatus == nil {
                host.showToast(L("we_error_network"), error: true)
            } else {
                key = UUID().uuidString
                host.showToast(failure.message ?? L("we_error_generic"), error: true)
            }
        }
        busy = false
    }
}

// MARK: - top up by card

/// «Top up by card»: an amount, then the app's payment screen (`wallet/checkouts`, purpose `watch_earn_ad_wallet`).
/// Paid by a gateway, it lands in the ad wallet as withdrawable; paid from the wallet, it moves like a fund.
private struct TopupSheet: View {
    @Environment(\.wa) private var wa
    let currency: WeCurrencyDto
    let onPay: (Int) -> Void
    @State private var text = ""

    var body: some View {
        let amount = weParseAmount(text, currency.decimals).flatMap { $0 > 0 ? $0 : nil }
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(L("we_topup_card")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                WeAmountField(text: $text, currency: currency, invalid: !text.trimmingCharacters(in: .whitespaces).isEmpty && amount == nil)
                WaNote(text: L("we_topup_card_note"))
                WaButton(text: L("we_topup_continue"), icon: "creditcard.fill", enabled: amount != nil) {
                    if let amount { onPay(amount) }
                }
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 20)
        }
        .frame(maxHeight: 560)
        .fixedSize(horizontal: false, vertical: true)
    }
}

/// An amount in the currency (Android's `AmountField`).
struct WeAmountField: View {
    @Binding var text: String
    let currency: WeCurrencyDto
    let invalid: Bool

    var body: some View {
        WeTextField(
            value: Binding(get: { text }, set: { text = weAmountChars($0) }),
            label: L("we_amount"), placeholder: "0", keyboard: .decimalPad, error: invalid, trailing: currency.code, ltr: true
        )
    }
}
