import SwiftUI

/// androidApp `ui/screens/watchearn/WatchEarnHome.kt` — the landing page: my rewards balance (the wallet's rewards +
/// what is still waiting), the big «start watching» button with how many ads wait and what each earns, three tiles
/// and «create an ad». Loads once when it opens. Also the entry points (D17): the Home banner, the chat-list card and
/// the wallet card.
@MainActor
struct WatchEarnHome: View {
    @EnvironmentObject private var host: WeHost
    @State private var feed: WeFeedDto?
    @State private var rewards: WeRewardsDto?
    @State private var walletReward: Int?
    @State private var failed = false
    @State private var loaded = false
    @State private var reload = 0

    var body: some View {
        WaPage(title: L("we_title"), onBack: { host.pop() }) {
            let currency = rewards?.currency ?? feed?.ads.first?.currency
            RewardsHero(walletReward: walletReward, waiting: rewards?.waitingMinor, currency: currency, loaded: loaded) { host.push(.rewards) }
                .waRise(0)
                .padding(.bottom, 14)

            Group {
                if let f = feed {
                    if !f.enabled {
                        WaEmpty(icon: "video.fill", tone: .gray, title: L("we_disabled_title"), text: L("we_disabled_text"))
                    } else if f.ads.isEmpty {
                        WaEmpty(icon: "hourglass", tone: .amber, title: L("we_no_ads_title"), text: L("we_no_ads_text"))
                    } else {
                        StartWatchingButton(feed: f) { host.push(.player(f)) }.waRise(1)
                    }
                } else if failed {
                    WaEmpty(icon: "play.circle.fill", tone: .red, title: L("we_load_failed"), text: L("we_load_failed_hint")) {
                        WaButton(text: L("we_retry")) { reload += 1 }.padding(.horizontal, 40)
                    }
                } else {
                    WaSkeleton(cornerRadius: 26).frame(height: 132)
                }
            }

            HStack(spacing: 10) {
                WeTile(icon: "trophy.fill", title: L("we_my_rewards"), tint: We.reward) { host.push(.rewards) }
                WeTile(icon: "megaphone.fill", title: L("we_my_ads"), tint: We.spendOnly) { host.push(.myAds()) }
                WeTile(icon: "wallet.pass.fill", title: L("we_ad_wallet"), tint: We.withdrawable) { host.push(.adWallet()) }
            }
            .padding(.top, 14)
            .waRise(2)

            CreateAdCard { host.push(.wizard(AdForm())) }
                .padding(.top, 16)
                .waRise(3)
        }
        .task(id: reload) { await load() }
    }

    private func load() async {
        failed = false
        async let f = try? WatchEarnApi.feed().data
        async let r = try? WatchEarnApi.rewards().data
        async let w = try? WalletApi.balance().data?.rewardMinor
        let (fresh, rewardsPage, reward) = await (f, r, w)
        feed = fresh
        rewards = rewardsPage
        walletReward = reward
        loaded = true
        if fresh == nil { failed = true }
        if let fresh { WatchEarnLink.shared.knownEnabled = fresh.enabled }
    }
}

/// The rewards balance on the brand gradient, counting up; what is still waiting under it.
private struct RewardsHero: View {
    @Environment(\.wa) private var wa
    let walletReward: Int?
    let waiting: Int?
    let currency: WeCurrencyDto?
    let loaded: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            ZStack(alignment: .bottomLeading) {
                Circle().fill(Color.white.opacity(0.07)).frame(width: 150, height: 150).offset(x: -36, y: 60)
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 10) {
                        Image(systemName: "trophy.fill").font(.system(size: 16, weight: .semibold)).foregroundStyle(.white)
                            .frame(width: 34, height: 34).background(We.rewardBrush, in: Circle())
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("we_title")).font(.dorr(16, .heavy)).foregroundStyle(.white)
                            Text(L("we_tagline")).font(.dorr(11.5)).foregroundStyle(.white.opacity(0.78)).lineLimit(1)
                        }
                        Spacer(minLength: 0)
                    }
                    Text(L("we_my_rewards_balance")).font(.dorr(12)).foregroundStyle(.white.opacity(0.8)).padding(.top, 14)
                    if walletReward == nil && waiting == nil && !loaded {
                        WaSkeleton().frame(width: 150, height: 30).padding(.top, 6)
                    } else {
                        WeCountUp(target: (walletReward ?? 0) + (waiting ?? 0), format: { weMoney($0, currency) }, color: Color.white, size: 28)
                            .padding(.top, 2)
                    }
                    if (waiting ?? 0) > 0 {
                        HStack(spacing: 5) {
                            Image(systemName: "hourglass").font(.system(size: 11, weight: .semibold))
                            Text(L("we_waiting_of", weMoney(waiting ?? 0, currency))).font(.dorr(11.5, .bold))
                        }
                        .foregroundStyle(.white)
                        .padding(.horizontal, 10)
                        .padding(.vertical, 4)
                        .background(Color.white.opacity(0.14), in: Capsule())
                        .padding(.top, 8)
                    }
                }
                .padding(16)
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .background(wa.heroGradient)
            .clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous))
            .shadow(color: wa.red.opacity(0.3), radius: 14, y: 6)
        }
        .buttonStyle(WaPressStyle(scale: 0.98))
    }
}

/// The big «start watching» button: a pulsing play mark, how many ads wait, and what each one can earn.
private struct StartWatchingButton: View {
    @Environment(\.wa) private var wa
    let feed: WeFeedDto
    let action: () -> Void
    @State private var ring: CGFloat = 1

    var body: some View {
        let best = feed.ads.max { ($0.earn.viewMinor + $0.earn.interactionMinor) < ($1.earn.viewMinor + $1.earn.interactionMinor) }
        Button(action: action) {
            HStack(spacing: 16) {
                ZStack {
                    Circle().fill(Color.white.opacity(0.35)).frame(width: 56, height: 56)
                        .scaleEffect(ring)
                        .opacity(Double((1.35 - ring) / 0.35))
                    Image(systemName: "play.fill").font(.system(size: 26, weight: .bold)).foregroundStyle(wa.red)
                        .frame(width: 56, height: 56).background(Color.white, in: Circle())
                }
                .frame(width: 64, height: 64)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("we_start_watching")).font(.dorr(19, .black)).foregroundStyle(.white)
                    Text(L("we_ads_waiting", feed.ads.count)).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.88))
                    if let best, best.earn.viewMinor > 0 {
                        HStack(spacing: 5) {
                            Image(systemName: "trophy.fill").font(.system(size: 11, weight: .semibold))
                            Text(L("we_earn_per_ad", weMoney(best.earn.viewMinor, best.currency))).font(.dorr(11.5, .heavy)).weLtr()
                        }
                        .foregroundStyle(.white)
                        .padding(.horizontal, 10)
                        .padding(.vertical, 4)
                        .background(We.rewardBrush, in: Capsule())
                        .padding(.top, 8)
                    }
                }
                Spacer(minLength: 0)
            }
            .padding(.horizontal, 18)
            .padding(.vertical, 20)
            .background(wa.buttonGradient)
            .clipShape(RoundedRectangle(cornerRadius: 26, style: .continuous))
            .shadow(color: wa.red.opacity(0.45), radius: 16, y: 6)
        }
        .buttonStyle(WaPressStyle(scale: 0.97))
        .onAppear {
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 1.3).repeatForever(autoreverses: false)) { ring = 1.35 }
        }
    }
}

private struct CreateAdCard: View {
    @Environment(\.wa) private var wa
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(alignment: .leading, spacing: 12) {
                HStack(spacing: 10) {
                    Image(systemName: "megaphone.fill").font(.system(size: 22, weight: .semibold)).foregroundStyle(wa.red)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("we_create_ad")).font(.dorr(15, .heavy)).foregroundStyle(wa.ink)
                        Text(L("we_create_ad_hint")).font(.dorr(12)).foregroundStyle(wa.mut)
                    }
                    Spacer(minLength: 0)
                }
                // The card itself is the button; this is its look (Android's ghost WaButton).
                HStack(spacing: 8) {
                    Image(systemName: "plus.circle.fill").font(.system(size: 15, weight: .bold))
                    Text(L("we_create_ad")).font(.dorr(13, .heavy)).lineLimit(1)
                }
                .foregroundStyle(wa.red)
                .frame(maxWidth: .infinity, minHeight: 40, maxHeight: 40)
                .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(wa.red.opacity(0.4), lineWidth: 1))
            }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(LinearGradient(colors: [wa.red.opacity(0.12), wa.red.opacity(0.03)], startPoint: .topLeading, endPoint: .bottomTrailing))
            .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(wa.red.opacity(0.25), lineWidth: 1))
        }
        .buttonStyle(WaPressStyle(scale: 0.97))
    }
}

// MARK: - entry points (D17)

/// Home: a banner card that opens the section. Hidden once the feed said the feature is off here.
@MainActor
struct WatchEarnBanner: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var link = WatchEarnLink.shared
    var insets = EdgeInsets()

    var body: some View {
        if link.knownEnabled != false {
            Button { link.show() } label: {
                HStack(spacing: 12) {
                    Image(systemName: "play.fill").font(.system(size: 20, weight: .bold)).foregroundStyle(.white)
                        .frame(width: 46, height: 46).background(We.rewardBrush, in: Circle())
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("we_title")).font(.dorr(17, .heavy)).foregroundStyle(.white)
                        Text(L("we_banner_text")).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.88)).lineLimit(2).multilineTextAlignment(.leading)
                    }
                    Spacer(minLength: 8)
                    Text(L("we_banner_cta")).font(.dorr(12, .heavy)).foregroundStyle(wa.red)
                        .padding(.horizontal, 12).padding(.vertical, 6)
                        .background(Color.white, in: Capsule())
                }
                .padding(16)
                .background(wa.heroGradient)
                .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
                .shadow(color: wa.red.opacity(0.26), radius: 10, y: 4)
            }
            .buttonStyle(WaPressStyle(scale: 0.98))
            .padding(insets)
        }
    }
}

/// Chat list: a slim card at the top, which can be hidden (it comes back from the chat settings).
@MainActor
struct WatchEarnChatCard: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var link = WatchEarnLink.shared
    @ObservedObject private var prefs = WatchEarnPrefs.shared
    var insets = EdgeInsets()

    var body: some View {
        if !prefs.chatCardHidden && link.knownEnabled != false {
            HStack(spacing: 10) {
                Button { link.show() } label: {
                    HStack(spacing: 10) {
                        Image(systemName: "play.fill").font(.system(size: 13, weight: .bold)).foregroundStyle(.white)
                            .frame(width: 30, height: 30).background(We.rewardBrush, in: Circle())
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("we_title")).font(.dorr(13, .heavy)).foregroundStyle(wa.ink)
                            Text(L("we_chat_card_text")).font(.dorr(11.5)).foregroundStyle(wa.mut).lineLimit(1)
                        }
                        Spacer(minLength: 0)
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                Button { prefs.setChatCardHidden(true) } label: {
                    Image(systemName: "xmark").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.mut)
                        .frame(width: 34, height: 34).contentShape(Circle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("we_hide"))
            }
            .padding(.leading, 12)
            .padding(.trailing, 4)
            .padding(.vertical, 8)
            .background(LinearGradient(colors: [We.gold.opacity(0.18), wa.red.opacity(0.08)], startPoint: .topLeading, endPoint: .bottomTrailing))
            .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(We.gold.opacity(0.35), lineWidth: 1))
            .padding(insets)
            .transition(.opacity.combined(with: .move(edge: .top)))
        }
    }
}

/// Wallet home: a card under the balance with my rewards balance, opening the section.
@MainActor
struct WatchEarnWalletCard: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var link = WatchEarnLink.shared
    let rewardMinor: Int
    let currencyCode: String?

    var body: some View {
        if !(link.knownEnabled == false && rewardMinor == 0) {
            Button { link.show() } label: {
                HStack(spacing: 12) {
                    Image(systemName: "play.fill").font(.system(size: 18, weight: .bold)).foregroundStyle(.white)
                        .frame(width: 42, height: 42).background(We.rewardBrush, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("we_title")).font(.dorr(14.5, .heavy)).foregroundStyle(wa.ink)
                        Text(L("we_wallet_card_text")).font(.dorr(12)).foregroundStyle(wa.mut).lineLimit(1)
                    }
                    Spacer(minLength: 6)
                    VStack(alignment: .trailing, spacing: 0) {
                        Text(L("we_bucket_reward")).font(.dorr(10.5)).foregroundStyle(wa.mut)
                        Text(verbatim: weMoney(rewardMinor, WeCurrencyDto(code: currencyCode, decimals: 2))).font(.dorr(13.5, .heavy)).foregroundStyle(We.reward).weLtr()
                    }
                }
                .padding(14)
                .background(wa.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(We.reward.opacity(0.35), lineWidth: 1))
            }
            .buttonStyle(WaPressStyle(scale: 0.97))
        }
    }
}
