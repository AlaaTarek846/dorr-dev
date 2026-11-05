import SwiftUI
import UIKit

// driverApp `ui/WalletScreen.kt` — named `DriverWalletScreen` here: Swift has one namespace per module and the wallet
// design's `WalletScreen(onExit:)` (driverApp `wallet/WalletScreen.kt`, UI/Wallet/WalletScreen.swift) has that name.

/// The captain's wallet, in the DORR app's wallet design — the same wallet as the rider's, with their earnings in it:
/// the balance card (the wallet number to copy or share, the total, what can be withdrawn and what is for services
/// only, what is held), the four actions, then the statement or the withdrawals. A negative balance is what they owe
/// the app from cash rides. It follows the server through real-time: a settlement, a payout decision or any movement
/// shows up by itself, no refreshing.
struct DriverWalletScreen: View {
    @Environment(\.pal) private var pal
    let onBack: (() -> Void)?

    @State private var wallet: WalletDto?
    @State private var rows: [WalletRowDto]?
    @State private var withdrawals: [WithdrawalDto]?
    @State private var tab = 0
    @State private var hidden = false
    @State private var page = 1
    @State private var more = true
    @State private var loadingMore = false
    @State private var withdrawOpen = false
    @State private var topupOpen = false
    @State private var filter = StatementFilter()
    @State private var detailId: String?
    // D-210, D-216..D-228: the last confirmed balance when offline, the payout safeguards, the statements.
    @State private var cachedAt: Int?
    @State private var payout: PayoutStatusDto?
    @State private var states: ServiceStatusDto?
    @State private var intlOpen = false
    @State private var invoicesOpen = false
    /// The real-time events are listened to once the first load is done (`collect` after it, in Kotlin).
    @State private var listening = false

    init(onBack: (() -> Void)?) { self.onBack = onBack }

    private func loadWallet() async {
        switch await DriverApi.wallet() {
        case .ok(let v, _): wallet = v; cachedAt = nil; WalletCache.save(v)
        default:
            if wallet == nil, let cached = WalletCache.load() { wallet = cached.0; cachedAt = cached.1 }
        }
        payout = await DriverApi.payoutStatus().value ?? payout
        states = await DriverApi.serviceStatus().value ?? states
    }

    // The statement, narrowed by the filters (D-213); the same filters apply to "show more".
    private func loadRows() async {
        if case .ok(let list, _) = await DriverApi.walletTransactions(page: 1, perPage: 25, type: filter.type, direction: filter.direction,
                                                                      from: filter.from, to: filter.to) {
            rows = list; page = 1; more = list.count >= 25
        }
        if rows == nil { rows = [] }
    }

    private func load() async {
        await loadWallet()
        await loadRows()
        if case .ok(let list, _) = await DriverApi.withdrawals(page: 1) { withdrawals = list }
    }

    var body: some View {
        let w = wallet
        let owing = (w?.totalMinor ?? 0) < 0
        ScreenScaffold(L("wl_title"), onBack: onBack, actions: {
            RoundIconButton(hidden ? "eye.slash" : "eye", L("wl_hide"), onClick: { hidden.toggle() })
        }) {
            ScrollViewReader { proxy in
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 0) {
                        Group {
                            if let w { WalletHero(w: w, hidden: hidden) } else { HeroSkeleton() }
                        }
                        .padding(.horizontal, 16).padding(.top, 4).staggerIn(0)
                        .id("hero")
                        PayoutBanners(payout, cachedAt: cachedAt, onInternational: { intlOpen = true }, onInvoices: { invoicesOpen = true })
                            .id("payout")
                        // D-330, D-338..D-340: the safe mode and the last withdrawal's state.
                        WithdrawalStateBanners(states, money: { money($0, nil) })
                            .padding(.horizontal, 16).padding(.top, 8)
                            .id("states")
                        if owing {
                            Banner(L("wl_owing"), kind: .warning)
                                .padding(.horizontal, 16).padding(.top, 10)
                                .id("owing")
                        }
                        HStack(spacing: 8) {
                            WalletAction(icon: "plus", label: L("wl_topup"), index: 1, enabled: w != nil) { topupOpen = true }
                            WalletAction(icon: "building.columns", label: L("wl_withdraw"), index: 2, enabled: (w?.withdrawableMinor ?? 0) > 0) {
                                withdrawOpen = true
                            }
                            WalletAction(icon: "clock.arrow.circlepath", label: L("wl_tab_statement"), index: 3) { showTab(0, proxy) }
                            WalletAction(icon: "banknote", label: L("wl_tab_withdrawals"), index: 4) { showTab(1, proxy) }
                        }
                        .padding(.horizontal, 16).padding(.top, 12)
                        .id("actions")
                        Segmented(
                            options: [("0", L("wl_tab_statement")), ("1", L("wl_tab_withdrawals"))],
                            selected: String(tab), onSelect: { tab = Int($0) ?? 0 }
                        )
                        .padding(.horizontal, 16).padding(.top, 18).padding(.bottom, 12).staggerIn(5)
                        .id("tabs")

                        if tab == 0 {
                            StatementFilters(filter) { filter = $0 }.id("filters")
                            if let shown = rows {
                                if shown.isEmpty {
                                    WalletEmpty(
                                        icon: "doc.text", text: L(filter.active ? "wl_empty_filtered" : "wl_empty"),
                                        action: filter.active || w == nil ? nil : { topupOpen = true }
                                    )
                                    .id("empty")
                                } else {
                                    ForEach(Array(shown.enumerated()), id: \.offset) { i, r in
                                        GroupItem(index: i, last: shown.count - 1) { TxRow(r: r, w: w) { detailId = r.uuid } }
                                            .id("tx-\(r.uuid ?? String(i))")
                                    }
                                    if more { moreButton(shown).id("more") }
                                }
                            } else {
                                Loading().id("loading")
                            }
                        } else {
                            if let shown = withdrawals {
                                if shown.isEmpty {
                                    WalletEmpty(icon: "banknote", text: L("wl_no_withdrawals"), action: nil).id("w-empty")
                                } else {
                                    ForEach(Array(shown.enumerated()), id: \.offset) { i, d in
                                        GroupItem(index: i, last: shown.count - 1) { WithdrawalRow(d: d, w: w) }
                                            .id("w\(d.id ?? i)")
                                    }
                                }
                            } else {
                                Loading().id("w-loading")
                            }
                        }
                    }
                    .padding(.bottom, 28)
                }
            }
        }
        .task {
            await loadWallet()
            withdrawals = await DriverApi.withdrawals(page: 1).value ?? []
            listening = true
        }
        .task(id: filter) { await loadRows() }
        // A settlement, a payout decision, a transfer: the wallet reloads by itself.
        .onReceive(DriverLive.shared.events) { e in
            guard listening else { return }
            if e.name.hasPrefix("wallet") || e.name.contains("withdrawal") || e.name.contains("earnings") || e.name.hasPrefix("driver.payout") {
                Task { await load() }
            }
        }
        .dorrDialog(isPresented: $intlOpen) {
            InternationalDialog(onClose: { intlOpen = false }, onSent: { intlOpen = false; Task { await loadWallet() } })
        }
        .background(
            Color.clear.dorrDialog(isPresented: $invoicesOpen) {
                InvoicesScreen(w, onClose: { invoicesOpen = false })
            }
        )
        .background(
            Color.clear.sheet(isPresented: Binding(get: { topupOpen && w != nil }, set: { if !$0 { topupOpen = false } })) {
                if let w {
                    TopupSheet(w, onClose: { topupOpen = false }, onDone: { topupOpen = false; Task { await load() } })
                        .environment(\.pal, pal)
                }
            }
        )
        .background(
            Color.clear.sheet(isPresented: Binding(get: { detailId != nil }, set: { if !$0 { detailId = nil } })) {
                if let id = detailId {
                    TransactionDetailSheet(uuid: id, w: w, onClose: { detailId = nil }).environment(\.pal, pal)
                }
            }
        )
        .background(
            Color.clear.sheet(isPresented: Binding(get: { withdrawOpen && w != nil }, set: { if !$0 { withdrawOpen = false } })) {
                if let w {
                    WithdrawSheet(w: w, onClose: { withdrawOpen = false }, onDone: {
                        withdrawOpen = false
                        tab = 1
                        Task { await load() }
                    })
                    .environment(\.pal, pal)
                }
            }
        )
    }

    /// Where the tabs sit in the list, for the actions that jump to them.
    private func showTab(_ which: Int, _ proxy: ScrollViewProxy) {
        tab = which
        withAnimation(.tween(300)) { proxy.scrollTo("tabs", anchor: .top) }
    }

    @ViewBuilder private func moreButton(_ shown: [WalletRowDto]) -> some View {
        ZStack {
            if loadingMore {
                CircularProgress(color: pal.accent, size: 24, lineWidth: 2)
            } else {
                Text(L("wl_more"))
                    .font(.dorr(13, .bold)).foregroundStyle(pal.accent)
                    .padding(.horizontal, 16).padding(.vertical, 10)
                    .bounceClick {
                        loadingMore = true
                        Task {
                            let next = await DriverApi.walletTransactions(page: page + 1, perPage: 25, type: filter.type, direction: filter.direction,
                                                                          from: filter.from, to: filter.to).value ?? []
                            if !next.isEmpty { rows = shown + next; page += 1 }
                            more = next.count >= 25
                            loadingMore = false
                        }
                    }
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.top, 12)
    }
}

// MARK: - the balance card

/// The balance card of the DORR app's wallet: number + copy / share, the total chip, the amount, the two parts, what is held.
private struct WalletHero: View {
    @Environment(\.pal) private var pal
    let w: WalletDto
    let hidden: Bool

    @State private var copied = false
    /// The total counts up to its new value.
    @State private var shown: Double = 0

    var body: some View {
        let number = w.walletNumber ?? ""
        let currency = w.currencySymbol ?? w.currencyCode ?? ""
        let brand = pal.accent
        let shape = RoundedRectangle(cornerRadius: 24)
        ZStack(alignment: .bottomLeading) {
            LinearGradient(colors: [brand, brand.darker(0.78), brand.darker(0.45)], startPoint: .topLeading, endPoint: .bottomTrailing)
            Circle().fill(Color(hex: 0xFFFFFF, alpha: 0x10 / 255.0))
                .frame(width: 150, height: 150)
                .offset(x: -30, y: 50)
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 0) {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("wl_number_label")).font(.dorr(11, .medium)).foregroundStyle(Color.white.opacity(0.68)).lineLimit(1)
                        if !number.trimmingCharacters(in: .whitespaces).isEmpty {
                            Text(number).font(.dorr(13, .bold)).foregroundStyle(.white).lineLimit(1).truncationMode(.tail)
                                .padding(.top, 2)
                                .environment(\.layoutDirection, .leftToRight)
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    if !number.trimmingCharacters(in: .whitespaces).isEmpty {
                        HStack(spacing: 7) {
                            HeroCircle(icon: copied ? "checkmark" : "doc.on.doc") {
                                walletCopyToClipboard(number)
                                copied = true
                            }
                            HeroCircle(icon: "square.and.arrow.up") { walletShareText(L("wl_share_text", number)) }
                        }
                        .environment(\.layoutDirection, .leftToRight)
                    }
                }
                if copied {
                    Text(L("wl_copied")).font(.dorr(11, .bold)).foregroundStyle(.white).padding(.top, 4)
                        .transition(.opacity.combined(with: .move(edge: .top)))
                }

                Rectangle().fill(Color.white.opacity(0.16)).frame(height: 1).padding(.vertical, 10)

                HStack(spacing: 0) {
                    DorrIcon("wallet.pass", size: 12, tint: Color.white.opacity(0.9))
                    HSpace(5)
                    Text(L("wl_balance")).font(.dorr(11, .semibold)).foregroundStyle(Color.white.opacity(0.92))
                }
                .padding(.horizontal, 9).padding(.vertical, 4)
                .background(Capsule().fill(Color.white.opacity(0.12)))
                .overlay(Capsule().strokeBorder(Color.white.opacity(0.16), lineWidth: 1))
                HStack(alignment: .lastTextBaseline, spacing: 0) {
                    CountUpFigure(value: shown, hidden: hidden)
                    if !currency.trimmingCharacters(in: .whitespaces).isEmpty {
                        HSpace(8)
                        Text(currency).font(.dorr(14, .bold)).foregroundStyle(Color.white.opacity(0.82))
                    }
                }
                .padding(.top, 6).padding(.bottom, 10)
                .environment(\.layoutDirection, .leftToRight)

                // The glass bar: what can be withdrawn | what is for the app's services only.
                HStack(spacing: 0) {
                    HeroPart(icon: "checkmark", label: L("wl_withdrawable"),
                             value: hidden ? "****" : "\(figure(w.withdrawableMinor ?? 0)) \(currency)")
                    Rectangle().fill(Color.white.opacity(0.22)).frame(width: 1, height: 34)
                    HeroPart(icon: "gift", label: L("wl_spend_only"),
                             value: hidden ? "****" : "\(figure(w.spendOnlyMinor ?? 0)) \(currency)")
                }
                .padding(.vertical, 9).padding(.horizontal, 4)
                .background(RoundedRectangle(cornerRadius: 18).fill(Color.white.opacity(0.12)))
                if (w.heldMinor ?? 0) > 0 {
                    HStack(spacing: 6) {
                        DorrIcon("pause.circle", size: 16, tint: Color.white.opacity(0.92))
                        Text(L("wl_held") + " " + (hidden ? "****" : figure(w.heldMinor ?? 0)) + " " + currency)
                            .font(.dorr(12)).foregroundStyle(Color.white.opacity(0.92))
                    }
                    .padding(.top, 10)
                }
            }
            .padding(.horizontal, 14).padding(.vertical, 12)
            .animation(.tween(220), value: copied)
        }
        .frame(maxWidth: .infinity)
        .clipShape(shape)
        .background(shape.fill(brand).shadow(color: brand.opacity(0.32), radius: 14, y: 6))
        .task(id: copied) {
            if copied {
                try? await Task.sleep(nanoseconds: 1_600_000_000)
                if !Task.isCancelled { copied = false }
            }
        }
        .onAppear { withAnimation(.tween(900)) { shown = Double(w.totalMinor ?? 0) } }
        .onChange(of: w.totalMinor) { total in withAnimation(.tween(900)) { shown = Double(total ?? 0) } }
    }
}

/// The total as it counts up (Compose `Animatable` read every frame).
private struct CountUpFigure: View, Animatable {
    var value: Double
    let hidden: Bool

    var animatableData: Double {
        get { value }
        set { value = newValue }
    }

    var body: some View {
        Text(hidden ? "******" : figure(Int(value)))
            .font(.dorr(28, .heavy)).kerning(-0.3).foregroundStyle(.white).lineLimit(1)
    }
}

private extension Color {
    /// The colour, darker (the card's gradient runs from the brand colour to a deep shade of it).
    func darker(_ factor: CGFloat) -> Color {
        var r: CGFloat = 0, g: CGFloat = 0, b: CGFloat = 0, a: CGFloat = 0
        UIColor(self).getRed(&r, green: &g, blue: &b, alpha: &a)
        return Color(.sRGB, red: Double(r * factor), green: Double(g * factor), blue: Double(b * factor), opacity: Double(a))
    }
}

private struct HeroCircle: View {
    let icon: String
    let onClick: () -> Void

    var body: some View {
        DorrIcon(icon, size: 16, tint: .white)
            .frame(width: 32, height: 32)
            .overlay(Circle().strokeBorder(Color.white.opacity(0.40), lineWidth: 1))
            .clipShape(Circle())
            .bounceClick(onClick: onClick)
    }
}

/// One half of the glass bar: icon + label, the amount under it.
private struct HeroPart: View {
    @Environment(\.layoutDirection) private var direction
    let icon: String
    let label: String
    let value: String

    var body: some View {
        let rtl = direction == .rightToLeft
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                DorrIcon(icon, size: 15, tint: Color.white.opacity(0.92))
                HSpace(6)
                Text(label).font(.dorr(11)).foregroundStyle(Color.white.opacity(0.86)).lineLimit(1).truncationMode(.tail)
            }
            Text(value).font(.dorr(14, .heavy)).foregroundStyle(.white).lineLimit(1)
                .frame(maxWidth: .infinity, alignment: rtl ? .trailing : .leading)
                .padding(.top, 6)
                .environment(\.layoutDirection, .leftToRight)
        }
        .padding(.horizontal, 10)
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

/// Until the wallet answers: the card's shape, breathing.
private struct HeroSkeleton: View {
    @Environment(\.pal) private var pal
    @State private var bright = false

    var body: some View {
        RoundedRectangle(cornerRadius: 24).fill(pal.well)
            .frame(maxWidth: .infinity)
            .frame(height: 186)
            .opacity(bright ? 0.9 : 0.45)
            .onAppear { withAnimation(.tween(800).repeatForever(autoreverses: true)) { bright = true } }
    }
}

// MARK: - actions, lists

/// One of the four tiles under the card: an icon in the brand colour over its name, in a thin brand-tinted frame.
private struct WalletAction: View {
    @Environment(\.pal) private var pal
    let icon: String
    let label: String
    let index: Int
    var enabled = true
    let onClick: () -> Void

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 16)
        VStack(spacing: 6) {
            DorrIcon(icon, size: 22, tint: pal.accent)
            Text(label).font(.dorr(11, .bold)).foregroundStyle(pal.ink).lineLimit(1).truncationMode(.tail)
        }
        .padding(.top, 10).padding(.bottom, 8).padding(.horizontal, 2)
        .frame(maxWidth: .infinity)
        .background(shape.fill(pal.card))
        .overlay(shape.strokeBorder(pal.accent.opacity(0.3), lineWidth: 1))
        .clipShape(shape)
        .bounceClick(enabled: enabled, onClick: onClick)
        .opacity(enabled ? 1 : 0.45)
        .staggerIn(index)
    }
}

/// A row of one card made of many rows: rounded at its two ends, a hairline between rows.
private struct GroupItem<Content: View>: View {
    @Environment(\.pal) private var pal
    let index: Int
    let last: Int
    @ViewBuilder let content: () -> Content

    var body: some View {
        let top: CGFloat = index == 0 ? 22 : 0
        let bottom: CGFloat = index == last ? 22 : 0
        VStack(alignment: .leading, spacing: 0) {
            if index > 0 { Rectangle().fill(pal.line.opacity(0.7)).frame(height: 1).padding(.horizontal, 14) }
            content()
        }
        .frame(maxWidth: .infinity)
        .background(pal.card)
        .clipShape(GroupShape(top: top, bottom: bottom))
        .staggerIn(min(index, 6))
        .padding(.horizontal, 16)
    }
}

/// Rounded at the top corners by `top`, at the bottom corners by `bottom` (iOS 16 has no `UnevenRoundedRectangle`).
private struct GroupShape: Shape {
    let top: CGFloat
    let bottom: CGFloat

    func path(in rect: CGRect) -> Path {
        var p = Path()
        p.move(to: CGPoint(x: rect.minX + top, y: rect.minY))
        p.addLine(to: CGPoint(x: rect.maxX - top, y: rect.minY))
        if top > 0 { p.addArc(tangent1End: CGPoint(x: rect.maxX, y: rect.minY), tangent2End: CGPoint(x: rect.maxX, y: rect.minY + top), radius: top) }
        p.addLine(to: CGPoint(x: rect.maxX, y: rect.maxY - bottom))
        if bottom > 0 { p.addArc(tangent1End: CGPoint(x: rect.maxX, y: rect.maxY), tangent2End: CGPoint(x: rect.maxX - bottom, y: rect.maxY), radius: bottom) }
        p.addLine(to: CGPoint(x: rect.minX + bottom, y: rect.maxY))
        if bottom > 0 { p.addArc(tangent1End: CGPoint(x: rect.minX, y: rect.maxY), tangent2End: CGPoint(x: rect.minX, y: rect.maxY - bottom), radius: bottom) }
        p.addLine(to: CGPoint(x: rect.minX, y: rect.minY + top))
        if top > 0 { p.addArc(tangent1End: CGPoint(x: rect.minX, y: rect.minY), tangent2End: CGPoint(x: rect.minX + top, y: rect.minY), radius: top) }
        p.closeSubpath()
        return p
    }
}

/// A statement row, as in the DORR app: an icon well for the kind, the kind and its note, the signed amount, the day and time.
private struct TxRow: View {
    @Environment(\.pal) private var pal
    let r: WalletRowDto
    let w: WalletDto?
    let onClick: () -> Void

    var body: some View {
        let credit = r.direction == "credit"
        let currency = w?.currencySymbol ?? w?.currencyCode ?? ""
        let typeLabel = r.typeLabel ?? ""
        HStack(spacing: 12) {
            IconWell(icon: txIcon(r.type ?? ""), size: 36)
            VStack(alignment: .leading, spacing: 0) {
                Text(typeLabel).font(.dorr(13, .bold)).foregroundStyle(pal.ink).lineLimit(1).truncationMode(.tail)
                if let note = r.note, !note.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty, note != typeLabel {
                    Text(note).font(.dorr(11.5)).foregroundStyle(pal.mut).lineLimit(1).truncationMode(.tail).padding(.top, 2)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            VStack(alignment: .trailing, spacing: 0) {
                HStack(alignment: .lastTextBaseline, spacing: 4) {
                    Text((credit ? "+ " : "- ") + figure(r.amountMinor ?? 0)).font(.dorr(13, .bold)).foregroundStyle(credit ? pal.success : pal.ink)
                    if !currency.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(currency).font(.dorr(11)).foregroundStyle(pal.mut)
                    }
                }
                .environment(\.layoutDirection, .leftToRight)
                Text(dayAndTime(r.createdAt)).font(.dorr(11)).foregroundStyle(pal.faint).padding(.top, 2)
            }
        }
        .padding(.horizontal, 14).padding(.vertical, 13)
        .frame(maxWidth: .infinity)
        .bounceClick(onClick: onClick)
    }
}

private struct WithdrawalRow: View {
    @Environment(\.pal) private var pal
    let d: WithdrawalDto
    let w: WalletDto?

    var body: some View {
        let color: Color = d.status == "approved" ? pal.success : d.status == "rejected" ? pal.danger : pal.warning
        let methodText = [d.method?.label, d.method?.display].compactMap { $0 }.joined(separator: " · ")
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 12) {
                IconWell(icon: d.method?.type == "mobile_wallet" ? "iphone" : "building.columns", size: 36)
                VStack(alignment: .leading, spacing: 0) {
                    Text(amount(d.amountMinor ?? 0, w)).font(.dorr(15, .heavy)).foregroundStyle(pal.ink)
                    Text(methodText.trimmingCharacters(in: .whitespaces).isEmpty ? whenText(d.createdAt) : methodText)
                        .font(.dorr(11.5)).foregroundStyle(pal.mut).lineLimit(1).truncationMode(.tail)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                VStack(alignment: .trailing, spacing: 0) {
                    Text(L(d.status == "approved" ? "wl_st_approved" : d.status == "rejected" ? "wl_st_rejected" : "wl_st_pending"))
                        .font(.dorr(11, .bold)).foregroundStyle(color)
                        .padding(.horizontal, 10).padding(.vertical, 4)
                        .background(Capsule().fill(color.opacity(0.12)))
                    Text(dayAndTime(d.createdAt)).font(.dorr(11)).foregroundStyle(pal.faint).padding(.top, 4)
                }
            }
            if let reason = d.rejectionReason { Banner(reason).padding(.top, 10) }
        }
        .padding(.horizontal, 14).padding(.vertical, 13)
        .frame(maxWidth: .infinity)
    }
}

/// An icon on a soft brand-coloured tile (the app's colours; the icon alone says what it is).
private struct IconWell: View {
    @Environment(\.pal) private var pal
    let icon: String
    let size: CGFloat

    var body: some View {
        DorrIcon(icon, size: size * 0.45, tint: pal.accent)
            .frame(width: size, height: size)
            .background(RoundedRectangle(cornerRadius: size * 0.34).fill(pal.well))
    }
}

/// Nothing here yet: a floating icon, the words, and — on the statement — the way to top up.
private struct WalletEmpty: View {
    @Environment(\.pal) private var pal
    let icon: String
    let text: String
    let action: (() -> Void)?
    @State private var up = false

    var body: some View {
        VStack(spacing: 0) {
            HeroBadge(icon).offset(y: up ? 5 : -5)
            Text(text).font(.dorr(14)).foregroundStyle(pal.mut).multilineTextAlignment(.center).padding(.top, 14)
            if let action {
                PrimaryButton(L("wl_topup"), icon: "plus", onClick: action)
                    .padding(.horizontal, 24).padding(.top, 16)
            }
        }
        .padding(.vertical, 28).padding(.horizontal, 24)
        .frame(maxWidth: .infinity)
        .background(RoundedRectangle(cornerRadius: 22).fill(pal.card))
        .padding(.horizontal, 16)
        .onAppear { withAnimation(.tween(1600).repeatForever(autoreverses: true)) { up = true } }
    }
}

private struct Loading: View {
    @Environment(\.pal) private var pal

    var body: some View {
        CircularProgress(color: pal.accent, size: 40, lineWidth: 4)
            .frame(maxWidth: .infinity)
            .padding(32)
    }
}

private func txIcon(_ type: String) -> String {
    switch type {
    case "topup": return "plus"
    case "topup_fee", "transfer_fee": return "doc.text"
    case "topup_bonus", "reward": return "gift"
    case "transfer_out": return "arrow.up.right"
    case "transfer_in": return "arrow.down.left"
    case "withdrawal": return "building.columns"
    case "refund", "reversal": return "arrow.counterclockwise"
    case "penalty": return "exclamationmark.circle"
    case "service_payment": return "bag"
    case "service_earning": return "car"
    case "commission": return "percent"
    case "tax": return "doc.plaintext"
    case "tip": return "hands.sparkles"
    case "discount_compensation": return "tag"
    case "debt_settlement": return "dollarsign.circle"
    case "manual_adjustment": return "slider.horizontal.3"
    default: return "doc.text"
    }
}

// MARK: - withdrawing

/// O-35: the withdrawal sheet, opened from the wallet copied from the DORR app (the captain's own action there).
/// Put it in the view tree while it should show; it presents its own sheet once the wallet has loaded.
struct DriverWithdraw: View {
    @Environment(\.pal) private var pal
    let onClose: () -> Void
    let onDone: () -> Void

    @State private var w: WalletDto?
    @State private var presented = false

    init(onClose: @escaping () -> Void, onDone: @escaping () -> Void) {
        self.onClose = onClose
        self.onDone = onDone
    }

    var body: some View {
        Color.clear.frame(width: 0, height: 0)
            .task {
                if case .ok(let v, _) = await DriverApi.wallet() { w = v; presented = true }
            }
            .sheet(isPresented: $presented) {
                if let w { WithdrawSheet(w: w, onClose: onClose, onDone: onDone).environment(\.pal, pal) }
            }
    }
}

/// Withdraw: pick (or add) where to, the amount, then the wallet PIN — made first if there isn't one yet.
/// The sheet's content (show it in a `.sheet`); swiping it away is `onDismissRequest` (`onClose`, not while busy).
private struct WithdrawSheet: View {
    @Environment(\.pal) private var pal
    let w: WalletDto
    let onClose: () -> Void
    let onDone: () -> Void

    @State private var methods: [WithdrawalMethodDto]?
    @State private var pinInfo: WalletPinDto?
    @State private var methodId: Int?
    @State private var adding = false
    @State private var amountText = ""
    @State private var pin = ""
    @State private var pinConfirm = ""
    @State private var busy = false
    @State private var error: String?
    // The new method's fields.
    @State private var type = "bank"
    @State private var label = ""
    @State private var bankName = ""
    @State private var holder = ""
    @State private var iban = ""
    @State private var provider = ""
    @State private var number = ""
    // D-219: the account is in someone else's name — the captain's word for it; D-223: abroad needs approval.
    @State private var askAck = false
    @State private var ack = false
    @State private var askIntl = false
    /// A callback already answered (so the sheet going away doesn't answer again).
    @State private var finished = false

    private func load() async {
        methods = await DriverApi.withdrawalMethods().value ?? []
        pinInfo = await DriverApi.walletPin().value
        if methodId == nil { methodId = methods?.first { $0.isFavorite == true }?.id ?? methods?.first?.id }
        if methods?.isEmpty ?? true { adding = true }
    }

    private func finish(_ f: () -> Void) {
        finished = true
        f()
    }

    var body: some View {
        let minor = Double(amountText.replacingOccurrences(of: ",", with: ".")).map { Int(($0 * 100).rounded()) } ?? 0
        let withdrawable = w.withdrawableMinor ?? 0
        let tooMuch = minor > withdrawable
        let needsPinSetup = pinInfo?.hasPin == false
        let pinsDiffer = needsPinSetup && pinConfirm.count == 4 && pin != pinConfirm
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(L(adding ? "wl_add_method" : "wl_withdraw")).font(.dorr(19, .heavy)).foregroundStyle(pal.ink)
                Text(L("wl_can_withdraw", amount(withdrawable, w))).font(.dorr(13)).foregroundStyle(pal.mut)
                if let list = methods, pinInfo != nil {
                    if !adding {
                        Text(L("wl_to")).font(.dorr(13, .bold)).foregroundStyle(pal.ink)
                        ForEach(Array(list.enumerated()), id: \.offset) { _, m in methodRow(m) }
                        HStack(spacing: 6) {
                            DorrIcon("plus", size: 18, tint: pal.accent)
                            Text(L("wl_add_method")).font(.dorr(13, .bold)).foregroundStyle(pal.accent)
                        }
                        .padding(.vertical, 6).padding(.horizontal, 4)
                        .bounceClick { adding = true }
                        DorrTextField(
                            value: amountText,
                            onValueChange: { amountText = String($0.filter { $0.isDigit || $0 == "." || $0 == "," }.prefix(10)) },
                            label: L("wl_amount", w.currencySymbol ?? ""), placeholder: "0.00", icon: "banknote",
                            trailing: AnyView(Chip(L("wl_all"), onClick: { amountText = String(format: "%.2f", Double(withdrawable) / 100.0) })),
                            keyboardOptions: KeyboardOptions(keyboardType: .decimalPad), error: tooMuch
                        )
                        Banner(tooMuch ? L("wl_too_much") : nil)
                    } else {
                        Segmented(options: [("bank", L("wl_bank")), ("mobile_wallet", L("wl_mobile"))], selected: type, onSelect: { type = $0 })
                        DorrTextField(value: label, onValueChange: { label = String($0.prefix(60)) }, label: L("wl_label"), icon: "pencil")
                        if type == "bank" {
                            DorrTextField(value: bankName, onValueChange: { bankName = String($0.prefix(100)) }, label: L("wl_bank_name"), icon: "building.columns")
                            DorrTextField(value: holder, onValueChange: { holder = String($0.prefix(100)) }, label: L("wl_holder"), icon: "person")
                            DorrTextField(
                                value: iban, onValueChange: { iban = String($0.filter { $0.isLetter || $0.isDigit }.uppercased().prefix(34)) },
                                label: "IBAN", placeholder: "SA00 0000 0000 0000 0000 0000", icon: "creditcard",
                                keyboardOptions: KeyboardOptions(capitalization: .characters),
                                error: !iban.isEmpty && iban.count < 15
                            )
                        } else {
                            DorrTextField(value: provider, onValueChange: { provider = String($0.prefix(100)) }, label: L("wl_provider"), icon: "iphone")
                            DorrTextField(
                                value: number, onValueChange: { number = String($0.filter { $0.isDigit }.prefix(20)) }, label: L("wl_number"), icon: "number",
                                keyboardOptions: KeyboardOptions(keyboardType: .numberPad), error: !number.isEmpty && number.count < 6
                            )
                        }
                        if askAck {
                            HStack(spacing: 0) {
                                DorrIcon(ack ? "checkmark.circle.fill" : "circle", size: 20, tint: ack ? pal.accent : pal.mut)
                                HSpace(8)
                                Text(L("wx_third_party_ack")).font(.dorr(12.5)).foregroundStyle(pal.ink)
                                    .frame(maxWidth: .infinity, alignment: .leading)
                            }
                            .padding(10)
                            .background(RoundedRectangle(cornerRadius: 14).fill(pal.warning.opacity(0.1)))
                            .bounceClick { ack.toggle() }
                        }
                        if askIntl {
                            Text(L("wx_intl_needed")).font(.dorr(13, .bold)).foregroundStyle(pal.accent)
                                .padding(.vertical, 6).padding(.horizontal, 4)
                                .bounceClick { askIntl = false; finish(onClose) }
                        }
                        if !list.isEmpty {
                            Text(L("cancel")).font(.dorr(13, .bold)).foregroundStyle(pal.mut)
                                .padding(.vertical, 6).padding(.horizontal, 4)
                                .bounceClick { adding = false }
                        }
                    }

                    // The wallet PIN: made once, asked for each time money moves.
                    if needsPinSetup { Text(L("wl_make_pin")).font(.dorr(13, .bold)).foregroundStyle(pal.ink) }
                    PinField(value: pin, label: L("wl_pin")) { pin = $0 }
                    if needsPinSetup { PinField(value: pinConfirm, label: L("wl_pin_confirm"), error: pinsDiffer) { pinConfirm = $0 } }
                    Banner(error)

                    let ready = adding
                        ? pin.count == 4 && (!needsPinSetup || pin == pinConfirm) &&
                            (type == "bank" ? !bankName.isBlank && !holder.isBlank && iban.count >= 15 : !provider.isBlank && number.count >= 6)
                        : pin.count == 4 && (!needsPinSetup || pin == pinConfirm) && methodId != nil && minor > 0 && !tooMuch
                    PrimaryButton(L(adding ? "wl_save_method" : "wl_send_request"), enabled: ready, busy: busy,
                                  icon: adding ? "checkmark" : "building.columns") {
                        busy = true
                        error = nil
                        Task { await submit(minor: minor, needsPinSetup: needsPinSetup) }
                    }
                } else {
                    Loading()
                }
            }
            .padding(.horizontal, 18).padding(.top, 24).padding(.bottom, 18)
        }
        .background(pal.card.ignoresSafeArea())
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
        .interactiveDismissDisabled(busy)
        .task { await load() }
        .onDisappear { if !finished { onClose() } }
    }

    @ViewBuilder private func methodRow(_ m: WithdrawalMethodDto) -> some View {
        let on = m.id == methodId
        let shape = RoundedRectangle(cornerRadius: 16)
        HStack(spacing: 10) {
            TileIcon(m.type == "bank" ? "building.columns" : "iphone", tint: pal.accent, box: 38)
            VStack(alignment: .leading, spacing: 0) {
                Text(m.label ?? L(m.type == "bank" ? "wl_bank" : "wl_mobile")).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                Text(m.display ?? "").font(.dorr(12)).foregroundStyle(pal.mut)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            DorrIcon(on ? "checkmark.circle.fill" : "circle", size: 22, tint: on ? pal.accent : pal.line)
            DorrIcon("trash", size: 18, tint: pal.faint)
                .frame(width: 30, height: 30)
                .clipShape(Circle())
                .bounceClick {
                    Task {
                        _ = await DriverApi.deleteWithdrawalMethod(id: m.id ?? 0)
                        if methodId == m.id { methodId = nil }
                        await load()
                    }
                }
        }
        .padding(12)
        .background(shape.fill(on ? pal.well : pal.fieldFill))
        .overlay(shape.strokeBorder(on ? pal.accent : pal.fieldLine, lineWidth: on ? 1.5 : 1))
        .clipShape(shape)
        .bounceClick { methodId = m.id }
    }

    private func submit(minor: Int, needsPinSetup: Bool) async {
        if needsPinSetup {
            let made = await DriverApi.createWalletPin(body: ["pin": pin, "pin_confirmation": pinConfirm])
            guard case .ok = made else {
                error = made.error?.displayMessage ?? L("error_offline")
                busy = false
                return
            }
            pinInfo?.hasPin = true
        }
        if adding {
            var data: [String: Any] = [:]
            if type == "bank" {
                data["bank_name"] = bankName.trimmed; data["account_holder"] = holder.trimmed; data["iban"] = iban.trimmed
            } else {
                data["provider_name"] = provider.trimmed; data["number"] = number.trimmed
            }
            var body: JsonBody = ["type": type]
            if ack { body["acknowledge_third_party"] = true }
            if !label.isBlank { body["label"] = label.trimmed }
            body["data"] = data
            switch await DriverApi.addWithdrawalMethod(pin: pin, body: body) {
            case .ok(let m, _):
                methodId = m.id; adding = false; pin = ""; pinConfirm = ""; askAck = false; ack = false
                await load()
            case .failed(let e):
                error = e.displayMessage
                if e.errorCode == "rides_payout_third_party_ack" { askAck = true }
                if e.errorCode == "rides_payout_international_off" { askIntl = true }
            case .offline:
                error = L("error_offline")
            }
        } else {
            let body: JsonBody = ["withdrawal_method_id": methodId, "amount_minor": minor]
            switch await DriverApi.withdraw(pin: pin, key: UUID().uuidString.lowercased(), body: body) {
            case .ok: finish(onDone)
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
        }
        busy = false
    }
}

private struct PinField: View {
    let value: String
    let label: String
    var error = false
    let onChange: (String) -> Void

    var body: some View {
        DorrTextField(
            value: value, onValueChange: { onChange(String($0.filter { $0.isDigit }.prefix(4))) }, label: label, placeholder: "••••",
            icon: "lock", password: true, keyboardOptions: KeyboardOptions(keyboardType: .numberPad), error: error
        )
    }
}

private extension Character {
    /// Kotlin `Char.isDigit()`: any Unicode decimal digit (Arabic-Indic too).
    var isDigit: Bool { unicodeScalars.count == 1 && unicodeScalars.allSatisfy { CharacterSet.decimalDigits.contains($0) } }
}

private extension String {
    var isBlank: Bool { trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
    var trimmed: String { trimmingCharacters(in: .whitespacesAndNewlines) }
}

// MARK: - formatting

/// "125.50" from minor units (two decimals), without the currency.
private func figure(_ minor: Int) -> String { localDecimal(Double(minor) / 100.0, places: 2) }

/// "125.50 ر.س" from minor units (the wallet's currency; two decimals).
func amount(_ minor: Int, _ w: WalletDto?) -> String {
    "\(localDecimal(Double(minor) / 100.0, places: 2)) \(w?.currencySymbol ?? w?.currencyCode ?? "")".trimmingCharacters(in: .whitespaces)
}

/// A server time in the phone's zone, the date medium and the time short, in the app's language ("" when unreadable).
func whenText(_ iso: String?) -> String {
    guard let at = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = Locale(identifier: DriverPrefs.locale)
    f.timeZone = .current
    f.dateStyle = .medium
    f.timeStyle = .short
    return f.string(from: at)
}

/// "Today, 14:05", "Yesterday, 09:10", else "12 Oct, 18:30" — in the phone's time zone.
private func dayAndTime(_ iso: String?) -> String {
    guard let at = parseInstant(iso) else { return "" }
    let time = DateFormatter()
    time.locale = Locale(identifier: "en_US_POSIX")
    time.timeZone = .current
    time.dateFormat = "HH:mm"
    let calendar = Calendar.current
    let day: String
    if calendar.isDateInToday(at) {
        day = L("wl_day_today")
    } else if calendar.isDateInYesterday(at) {
        day = L("wl_day_yesterday")
    } else {
        let f = DateFormatter()
        f.locale = Locale(identifier: DriverPrefs.locale)
        f.timeZone = .current
        f.dateFormat = "d MMM"
        day = f.string(from: at)
    }
    return day + L("list_sep") + time.string(from: at)
}
