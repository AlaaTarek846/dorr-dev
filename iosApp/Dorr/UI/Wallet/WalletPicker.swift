import SwiftUI

/*
 * androidApp `WalletPicker.kt` — which of my wallets I'm using (one per country, docs/wallet-plan.md §7).
 * The one of where I am opens by default; my other wallets (a Saudi one while I'm in Egypt…) are a tap
 * away — chosen, it shows its own number and QR, receives, and sends to its own country's numbers and wallets.
 */

/// "Wallet: 🇪🇬 Egypt · EGP — change". When it isn't the wallet of where I am, a note says what that means:
/// it pays its own country's numbers and wallets only.
@MainActor
struct WalletPickerCard: View {
    @EnvironmentObject private var host: WalletHost
    @ObservedObject private var walletCountry = WalletCountry.shared
    @Environment(\.wa) private var wa
    let balance: WalletBalanceDto
    @State private var open = false

    var body: some View {
        let current = balance.countryCode?.uppercased()
        let here = walletCountry.here?.uppercased()
        let away = current != nil && here != nil && current != here
        VStack(spacing: 0) {
            Button { open = true } label: {
                HStack(spacing: 0) {
                    WalletFlag(code: current)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("wa_wallet_of", countryName(current))).font(.dorr(14.5, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                        if away {
                            Text(L("wa_wallet_away", countryName(here))).font(.dorr(12)).foregroundStyle(wa.red).lineLimit(1)
                        }
                    }
                    .padding(.leading, 10)
                    Spacer(minLength: 8)
                    HStack(spacing: 4) {
                        Image(systemName: "arrow.left.arrow.right").font(.system(size: 12, weight: .bold))
                        Text(L("wa_wallet_change")).font(.dorr(12.5, .heavy))
                    }
                    .foregroundStyle(wa.red)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 6)
                    .background(wa.red.opacity(0.1), in: Capsule())
                }
                .padding(.horizontal, 14)
                .padding(.vertical, 11)
                .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(away ? wa.red.opacity(0.35) : wa.line, lineWidth: 1))
            }
            .buttonStyle(WaPressStyle(scale: 0.98))
            if away {
                WaNote(text: L("wa_wallet_away_note", countryName(current))).padding(.top, 8).transition(.opacity)
            }
        }
        .animation(.easeOut(duration: 0.25), value: away)
        .sheet(isPresented: $open) {
            WalletPickerSheet(balance: balance) { open = false }
                .environmentObject(host)
                .environment(\.walletHost, host)
                .waThemed()
        }
    }
}

/// My wallets: the one of where I am first, then the others (with their balances), then my number's country.
@MainActor
struct WalletPickerSheet: View {
    @EnvironmentObject private var host: WalletHost
    @EnvironmentObject private var session: AuthSession
    @ObservedObject private var walletCountry = WalletCountry.shared
    @Environment(\.wa) private var wa
    let balance: WalletBalanceDto
    let onDismiss: () -> Void

    var body: some View {
        let current = balance.countryCode?.uppercased()
        let here = walletCountry.here?.uppercased()
        let others = Dictionary((balance.otherWallets ?? []).map { ($0.countryCode?.uppercased() ?? "", $0) }, uniquingKeysWith: { first, _ in first })
        let phoneCountry = session.user?.country?.code?.uppercased()
        var options: [String] = []
        for code in [here, current] + (balance.otherWallets ?? []).map({ $0.countryCode?.uppercased() }) + [phoneCountry] {
            if let code, !options.contains(code) { options.append(code) }
        }

        return ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                Text(L("wa_wallet_pick_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                Text(L("wa_wallet_pick_sub")).font(.dorr(12.5)).foregroundStyle(wa.mut).lineSpacing(3)
                Spacer().frame(height: 12)
                ForEach(Array(options.enumerated()), id: \.element) { index, code in
                    let on = code == current
                    let other = others[code]
                    let amount: String = on
                        ? "\(money(balance.totalMinor)) \(balance.currencyCode ?? "")"
                        : other.map { "\(money($0.totalMinor)) \($0.currencyCode ?? "")" } ?? L("wa_wallet_new")
                    Button {
                        if !on {
                            walletCountry.select(code)
                            Task { await host.refreshBalance() }
                        }
                        onDismiss()
                    } label: {
                        HStack(spacing: 0) {
                            WalletFlag(code: code)
                            VStack(alignment: .leading, spacing: 0) {
                                HStack(spacing: 0) {
                                    Text(countryName(code)).font(.dorr(15, .heavy)).foregroundStyle(wa.ink)
                                    if code == here {
                                        Image(systemName: "location.fill").font(.system(size: 11)).foregroundStyle(wa.mut).padding(.leading, 6)
                                        Text(" " + L("wa_wallet_you_are_here")).font(.dorr(11)).foregroundStyle(wa.mut)
                                    }
                                }
                                Text(amount).font(.dorr(12.5)).foregroundStyle(wa.mut)
                            }
                            .padding(.leading, 12)
                            Spacer(minLength: 8)
                            if on { Image(systemName: "checkmark.circle.fill").font(.system(size: 20)).foregroundStyle(wa.red) }
                        }
                        .padding(14)
                        .background(on ? wa.red.opacity(0.08) : wa.field, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(on ? wa.red : .clear, lineWidth: 1.5))
                    }
                    .buttonStyle(.plain)
                    .padding(.bottom, 8)
                    .waRise(index)
                }
                Text(L("wa_wallet_rule")).font(.dorr(11.5)).foregroundStyle(wa.soft).lineSpacing(3).padding(.top, 4)
            }
            .padding(.horizontal, 20)
            .padding(.top, 24)
            .padding(.bottom, 26)
        }
        .background(wa.surface.ignoresSafeArea())
        .presentationDetents([.medium, .large])
        .presentationDragIndicator(.visible)
    }
}
