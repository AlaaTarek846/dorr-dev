import SwiftUI
import UIKit

// driverApp `wallet/WalletHome.kt` + `wallet/WalletHistory.kt` (the DORR app's, with the captain's withdrawal in place of
// the transfer action and without Watch & Earn or the wallet QR; ported from iosApp `UI/Wallet/WalletHome.swift`).

/// Copies [text] to the clipboard (ClipboardManager on Android).
func walletCopyToClipboard(_ text: String) { UIPasteboard.general.string = text }

/// The system share sheet with [text] (`Intent.ACTION_SEND` + chooser on Android).
@MainActor
func walletShareText(_ text: String) { walletSharePayload([text]) }

@MainActor
func walletSharePayload(_ items: [Any]) {
    guard let root = UIApplication.shared.connectedScenes.compactMap({ ($0 as? UIWindowScene)?.keyWindow }).first?.rootViewController else { return }
    var top = root
    while let presented = top.presentedViewController { top = presented }
    let controller = UIActivityViewController(activityItems: items, applicationActivities: nil)
    controller.popoverPresentationController?.sourceView = top.view
    controller.popoverPresentationController?.sourceRect = CGRect(x: top.view.bounds.midX, y: top.view.bounds.midY, width: 0, height: 0)
    top.present(controller, animated: true)
}

/// driverApp `WalletHome.kt`.
@MainActor
struct WalletHome: View {
    @EnvironmentObject private var host: WalletHost
    @ObservedObject private var network = WalletReconnect.shared
    @ObservedObject private var walletCountry = WalletCountry.shared
    @Environment(\.wa) private var wa

    @State private var recent: [WalletTransactionDto]?
    @State private var loading = true
    /// The captain withdraws the earnings (the DORR app's "transfer" place) — the captain app's withdrawal sheet.
    @State private var withdrawOpen = false

    var body: some View {
        WaPage(title: L("wa_my_wallet"), onBack: { host.pop() }, actions: {
            WaOutlineCircleButton(icon: host.hideBalance ? "eye.slash.fill" : "eye.fill") { host.hideBalance.toggle() }
            // Turns once every 0.8 s while loading, back at rest as soon as it finishes.
            TimelineView(.animation(minimumInterval: nil, paused: !loading)) { context in
                WaOutlineCircleButton(icon: "arrow.clockwise") { Task { await load() } }
                    .rotationEffect(.degrees(loading ? context.date.timeIntervalSinceReferenceDate.truncatingRemainder(dividingBy: 0.8) / 0.8 * 360 : 0))
            }
        }) {
            let balance = host.balance
            Group {
                if let balance {
                    WaHeroCard(balance: balance)
                } else {
                    WaSkeleton(cornerRadius: 24).frame(height: 172)
                }
            }
            .waRise(0)

            if let balance, let current = balance.countryCode?.uppercased(), let here = walletCountry.here?.uppercased(), current != here {
                WaNote(text: L("wa_wallet_away_note", countryName(current))).padding(.top, 8)
            }

            HStack(spacing: 8) {
                HomeAction(icon: "creditcard.and.123", label: L("wa_action_topup"), index: 1) { host.push(.topup) }
                // The captain's own action: withdrawing the earnings (a rider transfers here instead).
                HomeAction(icon: "arrow.up.right", label: L("wl_withdraw"), index: 2) { withdrawOpen = true }
                HomeAction(icon: "clock.arrow.circlepath", label: L("wa_action_history"), index: 3) { host.push(.history) }
                HomeAction(icon: "gearshape.fill", label: L("wa_action_pin"), index: 4) { host.push(.pinSettings) }
            }
            .padding(.top, 12)

            if let others = balance?.otherWallets, !others.isEmpty {
                WaCard(padding: 16) {
                    HStack(spacing: 8) {
                        Image(systemName: "globe").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.ink)
                        Text(L("wa_other_wallets")).font(.dorr(14, .heavy)).foregroundStyle(wa.ink)
                    }
                    ForEach(Array(others.enumerated()), id: \.offset) { _, other in
                        // A tap switches to that wallet: its number, QR and transfers (to its own country).
                        Button { walletCountry.select(other.countryCode) } label: {
                            HStack(spacing: 0) {
                                WalletFlag(code: other.countryCode)
                                Text(countryName(other.countryCode)).font(.dorr(14, .bold)).foregroundStyle(wa.ink).padding(.leading, 10)
                                Spacer(minLength: 8)
                                Text(verbatim: "\u{2066}\(money(other.totalMinor)) \(other.currencyCode ?? "")\u{2069}").font(.dorr(14, .heavy)).foregroundStyle(wa.ink)
                                Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.soft).padding(.leading, 4)
                            }
                            .padding(.horizontal, 12)
                            .padding(.vertical, 10)
                            .background(wa.field, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                        .buttonStyle(.plain)
                        .padding(.top, 10)
                    }
                    Text(L("wa_other_wallets_note")).font(.dorr(11.5)).foregroundStyle(wa.mut).lineSpacing(4).padding(.top, 2)
                }
                .padding(.top, 8)
                .waRise(4)
            }

            WaSectionTitle(title: L("wa_recent"), topPadding: 12) {
                Button { host.push(.history) } label: {
                    HStack(spacing: 2) {
                        Text(L("wa_view_all")).font(.dorr(13, .bold))
                        Image(systemName: "chevron.forward").font(.system(size: 12, weight: .semibold))
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
                            WaButton(text: L("wa_topup_now"), icon: "plus") { host.push(.topup) }.padding(.horizontal, 40)
                        }
                    } else {
                        VStack(spacing: 0) {
                            ForEach(Array(rows.enumerated()), id: \.element.uuid) { index, tx in
                                WaTxRow(tx: tx)
                                if index < rows.count - 1 { WaDivider() }
                            }
                        }
                        .padding(.horizontal, 14)
                        .padding(.vertical, 4)
                    }
                } else {
                    VStack(spacing: 0) { ForEach(0..<3, id: \.self) { _ in WaTxSkeleton() } }.padding(.horizontal, 14)
                }
            }
            .waRise(6)
        }
        // Reloads on reconnect and when I switch to another of my wallets.
        .task(id: "\(network.reconnectTick)-\(walletCountry.selected ?? "")") { await load() }
        // `if (withdrawOpen) DriverWithdraw(…)`: the captain app's withdrawal (it presents its own sheet once loaded).
        .background {
            if withdrawOpen {
                DriverWithdraw(onClose: { withdrawOpen = false }, onDone: {
                    withdrawOpen = false
                    Task { await host.refreshBalance() }
                })
            }
        }
    }

    private func load() async {
        // No error card here on purpose: no-internet is covered by the app-wide offline screen, and a
        // reconnect auto-reloads — so a failed load just keeps the skeleton until the next attempt.
        loading = true
        await host.refreshBalance()
        let rows = try? await WalletApi.transactions(page: 1, perPage: 5).data ?? []
        if let rows { recent = rows } else if recent == nil { recent = [] }
        loading = false
    }
}

private struct HomeAction: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let index: Int
    let action: () -> Void

    var body: some View {
        Button(action: action) {
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
        .waRise(index)
    }
}

/// The balance card — same order as the reference: number + actions, total chip, amount, then the two parts.
@MainActor
private struct WaHeroCard: View {
    @EnvironmentObject private var host: WalletHost
    @Environment(\.wa) private var wa
    let balance: WalletBalanceDto
    @State private var pickWallet = false
    @State private var copied = false
    @State private var shown: Double = 0
    @State private var started = false

    var body: some View {
        let currency = balance.currencyCode ?? ""
        let hidden = host.hideBalance
        let number = balance.walletNumberFormatted ?? groupWalletNumber(balance.walletNumber ?? "")
        ZStack(alignment: .bottomLeading) {
            Circle().fill(Color.white.opacity(0.063)).frame(width: 150, height: 150).offset(x: -30, y: 50)
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 0) {
                    VStack(alignment: .leading, spacing: 2) {
                        HStack(spacing: 6) {
                            Text(L("wa_wallet_number")).font(.dorr(11, .medium)).foregroundStyle(.white.opacity(0.68)).lineLimit(1)
                            WalletFlag(code: balance.countryCode, width: 16, height: 11)
                        }
                        if !number.trimmingCharacters(in: .whitespaces).isEmpty {
                            Text(number).font(.dorr(13, .bold)).foregroundStyle(.white).lineLimit(1)
                                .environment(\.layoutDirection, .leftToRight)
                        }
                    }
                    Spacer(minLength: 8)
                    HStack(spacing: 7) {
                        HeroCircle(icon: copied ? "checkmark" : "doc.on.doc") {
                            walletCopyToClipboard(number)
                            copied = true
                            host.showToast(L("wa_number_copied"))
                            Task {
                                try? await Task.sleep(nanoseconds: 1_600_000_000)
                                copied = false
                            }
                        }
                        HeroCircle(icon: "square.and.arrow.up") { walletShareText(L("wa_qr_share_text", number)) }
                        HeroCircle(icon: "arrow.left.arrow.right") { pickWallet = true }
                    }
                    .environment(\.layoutDirection, .leftToRight)
                }

                Color.white.opacity(0.16).frame(height: 1).padding(.vertical, 10)

                HStack(spacing: 5) {
                    Image(systemName: "wallet.pass.fill").font(.system(size: 10, weight: .semibold)).foregroundStyle(.white.opacity(0.9))
                    Text(L("wa_total_balance")).font(.dorr(11, .semibold)).foregroundStyle(.white.opacity(0.92))
                }
                .padding(.horizontal, 9)
                .padding(.vertical, 4)
                .background(Color.white.opacity(0.12), in: Capsule())
                .overlay(Capsule().stroke(Color.white.opacity(0.16), lineWidth: 1))

                HStack(alignment: .lastTextBaseline, spacing: 8) {
                    if hidden {
                        Text("******").font(.dorr(28, .heavy)).foregroundStyle(.white)
                    } else {
                        Text("").modifier(CountingMoney(value: shown)).font(.dorr(28, .heavy)).kerning(-0.3).foregroundStyle(.white).lineLimit(1)
                    }
                    if !currency.isEmpty {
                        Text(currency).font(.dorr(14, .bold)).foregroundStyle(.white.opacity(0.82))
                    }
                }
                .environment(\.layoutDirection, .leftToRight)
                .padding(.top, 6)
                .padding(.bottom, 10)

                HStack(spacing: 0) {
                    HeroPart(icon: "checkmark", label: L("wa_withdrawable"), value: hidden ? "****" : "\(money(balance.withdrawableMinor)) \(currency)") { host.openSheet(.explain) }
                    Color.white.opacity(0.22).frame(width: 1, height: 34)
                    HeroPart(icon: "gift.fill", label: L("wa_spend_only"), value: hidden ? "****" : "\(money(balance.spendOnlyMinor)) \(currency)") { host.openSheet(.explain) }
                }
                .padding(.vertical, 9)
                .padding(.horizontal, 4)
                .background(Color.white.opacity(0.12), in: RoundedRectangle(cornerRadius: 18, style: .continuous))

                if balance.heldMinor > 0 {
                    HStack(spacing: 6) {
                        Image(systemName: "pause.circle.fill").font(.system(size: 14))
                        Text(verbatim: "\(L("wa_held_temporarily")) \(hidden ? "****" : money(balance.heldMinor)) \(currency)").font(.dorr(12))
                    }
                    .foregroundStyle(.white.opacity(0.92))
                    .padding(.top, 10)
                }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 12)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.heroGradient)
        .clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous))
        .shadow(color: wa.red.opacity(0.32), radius: 14, y: 6)
        .onAppear {
            guard !started else { return }
            started = true
            shown = Double(host.shownTotal)
            count(to: balance.totalMinor)
        }
        .onChange(of: balance.totalMinor) { count(to: $0) }
        .sheet(isPresented: $pickWallet) {
            WalletPickerSheet(balance: balance) { pickWallet = false }
                .environmentObject(host)
                .environment(\.walletHost, host)
                .waThemed()
        }
    }

    /// The total counts up (or down) from what the card last showed, over 0.9 s.
    private func count(to total: Int) {
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.9)) { shown = Double(total) }
        host.shownTotal = total
    }
}

/// Text showing an animated money value (the hero card's count-up).
private struct CountingMoney: AnimatableModifier {
    var value: Double
    var animatableData: Double {
        get { value }
        set { value = newValue }
    }

    func body(content: Content) -> some View {
        Text(money(Int(value.rounded())))
    }
}

private struct HeroCircle: View {
    let icon: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(.white)
                .frame(width: 32, height: 32)
                .overlay(Circle().stroke(Color.white.opacity(0.4), lineWidth: 1))
                .contentShape(Circle())
        }
        .buttonStyle(.plain)
    }
}

/// One half of the glass bar: icon + label, amount under it.
private struct HeroPart: View {
    let icon: String
    let label: String
    let value: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(alignment: .leading, spacing: 6) {
                HStack(spacing: 6) {
                    Image(systemName: icon).font(.system(size: 12, weight: .bold)).foregroundStyle(.white.opacity(0.92))
                    Text(label).font(.dorr(11)).foregroundStyle(.white.opacity(0.86)).lineLimit(1)
                }
                Text(verbatim: "\u{2066}\(value)\u{2069}").font(.dorr(14, .heavy)).foregroundStyle(.white).lineLimit(1)
            }
            .padding(.horizontal, 10)
            .frame(maxWidth: .infinity, alignment: .leading)
            .contentShape(Rectangle())
        }
        .buttonStyle(WaPressStyle(scale: 0.96))
    }
}

// MARK: - Transactions

struct WaTxSkeleton: View {
    var body: some View {
        HStack(spacing: 12) {
            WaSkeleton(cornerRadius: 15).frame(width: 44, height: 44)
            VStack(alignment: .leading, spacing: 8) {
                GeometryReader { proxy in
                    VStack(alignment: .leading, spacing: 8) {
                        WaSkeleton().frame(width: proxy.size.width * 0.55, height: 12)
                        WaSkeleton().frame(width: proxy.size.width * 0.35, height: 10)
                    }
                }
                .frame(height: 30)
            }
            WaSkeleton().frame(width: 60, height: 14)
        }
        .padding(.vertical, 12)
    }
}

/// The row used in the home list and the statement: a round brand-tinted icon, the type and its "services
/// only" badge, and at the end the signed amount with the currency and the day/time stacked under it.
struct WaTxRow: View {
    @EnvironmentObject private var host: WalletHost
    @Environment(\.wa) private var wa
    let tx: WalletTransactionDto

    var body: some View {
        let credit = tx.direction == "credit"
        let currency = host.balance?.currencyCode ?? ""
        Button { host.openSheet(.tx(tx)) } label: {
            HStack(spacing: 12) {
                WaIconWell(icon: txMeta(tx.type).icon, size: 36, iconSize: 16)
                VStack(alignment: .leading, spacing: 0) {
                    Text(tx.typeLabel).font(.dorr(13, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                    if tx.bucket == "spend_only" {
                        Text(L("wa_services_only_badge")).font(.dorr(10, .bold)).foregroundStyle(wa.red)
                            .padding(.horizontal, 7).padding(.vertical, 2)
                            .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 7, style: .continuous))
                            .padding(.top, 3)
                    }
                }
                Spacer(minLength: 8)
                VStack(alignment: .trailing, spacing: 2) {
                    HStack(alignment: .lastTextBaseline, spacing: 4) {
                        Text(verbatim: "\(credit ? "+ " : "- ")\(money(tx.amountMinor))").font(.dorr(13, .bold)).foregroundStyle(credit ? wa.green : wa.ink)
                        if !currency.isEmpty { Text(currency).font(.dorr(11)).foregroundStyle(wa.mut) }
                    }
                    .environment(\.layoutDirection, .leftToRight)
                    Text(verbatim: "\(dayText(tx.createdAt))، \(waTimeOf(tx.createdAt))").font(.dorr(11)).foregroundStyle(wa.soft)
                }
            }
            .padding(.vertical, 13)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - History

private enum HistoryFilter: CaseIterable {
    case all, inbound, outbound, spendOnly

    var label: String {
        switch self {
        case .all: return L("wa_filter_all")
        case .inbound: return L("wa_filter_in")
        case .outbound: return L("wa_filter_out")
        case .spendOnly: return L("wa_spend_only")
        }
    }

    var icon: String {
        switch self {
        case .all: return "list.bullet"
        case .inbound: return "arrow.down.left"
        case .outbound: return "arrow.up.right"
        case .spendOnly: return "gift.fill"
        }
    }

    var direction: String? {
        switch self {
        case .inbound: return "credit"
        case .outbound: return "debit"
        default: return nil
        }
    }

    var bucket: String? { self == .spendOnly ? "spend_only" : nil }
}

/// androidApp `WalletHistory.kt` — the statement: every posted movement, newest first, filterable, paged.
@MainActor
struct WalletHistory: View {
    @EnvironmentObject private var host: WalletHost
    @ObservedObject private var network = WalletReconnect.shared
    @State private var filter = HistoryFilter.all
    @State private var rows: [WalletTransactionDto] = []
    @State private var page = 1
    @State private var hasMore = false
    @State private var loading = true
    @State private var lastTick = 0

    var body: some View {
        WaPage(title: L("wa_history_title"), onBack: { host.pop() }) {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(HistoryFilter.allCases, id: \.self) { option in
                        WaChip(text: option.label, icon: option.icon, selected: filter == option) {
                            if filter != option {
                                filter = option
                                page = 1
                                rows = []
                            }
                        }
                    }
                }
            }
            .padding(.bottom, 10)

            if loading && rows.isEmpty {
                WaCard { VStack(spacing: 0) { ForEach(0..<5, id: \.self) { _ in WaTxSkeleton() } }.padding(.horizontal, 14) }
            } else if rows.isEmpty {
                WaCard { WaEmpty(icon: "clock.arrow.circlepath", title: L("wa_history_empty_title"), text: L("wa_history_empty_text")) }
            } else {
                // The same rows as the wallet's home list: amount with its currency, and the day and time under it.
                WaCard {
                    VStack(spacing: 0) {
                        ForEach(Array(rows.enumerated()), id: \.element.uuid) { index, tx in
                            WaTxRow(tx: tx)
                            if index < rows.count - 1 { WaDivider() }
                        }
                    }
                    .padding(.horizontal, 14)
                    .padding(.vertical, 4)
                }
                if hasMore {
                    WaButton(text: L("wa_show_more"), style: .ghost, loading: loading) { page += 1 }.padding(.top, 12)
                }
            }
        }
        .task { if host.balance == nil { await host.refreshBalance() } }
        // A filter change or "show more" re-runs this; page 1 replaces the list, later pages append. A
        // reconnect restarts from the first page so fresh rows replace the stale list.
        .task(id: "\(filter)-\(page)-\(network.reconnectTick)") {
            if network.reconnectTick != lastTick {
                lastTick = network.reconnectTick
                if page != 1 { page = 1; return }
            }
            await load()
        }
    }

    private func load() async {
        // No error card on purpose: offline is covered by the app-wide screen and a reconnect reloads, so a
        // failed load keeps the skeleton (loading stays true) until the next attempt succeeds.
        loading = true
        guard let envelope = try? await WalletApi.transactions(page: page, perPage: 15, direction: filter.direction, bucket: filter.bucket) else { return }
        let fresh = envelope.data ?? []
        rows = page == 1 ? fresh : rows + fresh
        hasMore = envelope.pagination?.hasMorePages == true
        loading = false
    }
}

/// Filter pill (`wa-chip`): surface with a hairline border, brand-coloured and filled when selected.
struct WaChip: View {
    @Environment(\.wa) private var wa
    let text: String
    var icon: String?
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                if let icon { Image(systemName: icon).font(.system(size: 13, weight: .semibold)) }
                Text(text).font(.dorr(13, .bold))
            }
            .foregroundStyle(selected ? .white : wa.ink)
            .padding(.horizontal, 14)
            .padding(.vertical, 7)
            .background(selected ? wa.red : wa.surface, in: Capsule())
            .overlay(Capsule().stroke(selected ? wa.red : wa.line, lineWidth: 1.5))
        }
        .buttonStyle(WaPressStyle(scale: 0.92))
    }
}
