import SwiftUI

/// androidApp `ui/screens/watchearn/WatchEarnRewards.kt` — «مكافآتي»: what is waiting and what was paid (two cards that
/// count up), then every reward — for a view or for an interaction — with its ad, amount and «available on …» /
/// «added». Rewards are spent anywhere in the app; where the country allows it they can be sent to others too.
@MainActor
struct WatchEarnRewards: View {
    @EnvironmentObject private var host: WeHost
    @Environment(\.wa) private var wa
    @State private var items: [WeRewardItemDto] = []
    @State private var waiting: Int?
    @State private var paid: Int?
    @State private var currency: WeCurrencyDto?
    @State private var nextCursor: String?
    @State private var loaded = false
    @State private var loadingMore = false
    @State private var transferable = false

    var body: some View {
        WaPage(title: L("we_my_rewards"), onBack: { host.pop() }) {
            HStack(spacing: 10) {
                SumCard(icon: "hourglass", label: L("we_rewards_waiting"), value: waiting, currency: currency, tint: We.gold)
                SumCard(icon: "checkmark.circle.fill", label: L("we_rewards_paid"), value: paid, currency: currency, tint: We.withdrawable)
            }
            .waRise(0)
            WaNote(text: L(transferable ? "we_rewards_note_transferable" : "we_rewards_note"))
                .padding(.top, 12)
                .waRise(1)

            WaSectionTitle(title: L("we_rewards_list"), topPadding: 16).waRise(2)
            WaCard {
                if !loaded {
                    VStack(spacing: 12) {
                        ForEach(0..<3, id: \.self) { _ in WaSkeleton().frame(height: 40) }
                    }
                    .padding(14)
                } else if items.isEmpty {
                    WaEmpty(icon: "trophy.fill", tone: .amber, title: L("we_no_rewards"), text: L("we_no_rewards_text")) {
                        WaButton(text: L("we_start_watching")) { host.pop() }.padding(.horizontal, 40)
                    }
                } else {
                    VStack(spacing: 0) {
                        ForEach(Array(items.enumerated()), id: \.element.id) { i, item in
                            RewardRow(item: item, currency: currency)
                            if i < items.count - 1 { WaDivider() }
                        }
                    }
                    .padding(.horizontal, 14)
                    .padding(.vertical, 4)
                }
            }
            .waRise(3)
            if let cursor = nextCursor {
                WaButton(text: L("we_load_more"), style: .ghost, loading: loadingMore, compact: true) {
                    loadingMore = true
                    Task {
                        if let page = try? await WatchEarnApi.rewards(cursor: cursor).data {
                            let known = Set(items.map(\.id))
                            items.append(contentsOf: page.items.filter { !known.contains($0.id) })
                            nextCursor = page.nextCursor
                        }
                        loadingMore = false
                    }
                }
                .padding(.top, 12)
            }
        }
        .task { await load() }
    }

    private func load() async {
        do {
            if let page = try await WatchEarnApi.rewards().data {
                waiting = page.waitingMinor
                paid = page.paidMinor
                currency = page.currency
                items = page.items
                nextCursor = page.nextCursor
            }
        } catch {
            host.showToast(error.weMessage(L("we_error_generic")), error: true)
        }
        loaded = true
        transferable = (try? await WalletApi.balance().data?.rewardsTransferable) == true
    }
}

private struct SumCard: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let value: Int?
    let currency: WeCurrencyDto?
    let tint: Color

    var body: some View {
        WaCard(padding: 14) {
            Image(systemName: icon).font(.system(size: 16, weight: .semibold)).foregroundStyle(tint)
                .frame(width: 34, height: 34)
                .background(tint.opacity(0.14), in: RoundedRectangle(cornerRadius: 11, style: .continuous))
            Text(verbatim: label).font(.dorr(12)).foregroundStyle(wa.mut).padding(.top, 10)
            if let value {
                WeCountUp(target: value, format: { weMoney($0, currency) }, color: tint, size: 18)
            } else {
                WaSkeleton().frame(width: 90, height: 22).padding(.top, 4)
            }
        }
    }
}

private struct RewardRow: View {
    @Environment(\.wa) private var wa
    let item: WeRewardItemDto
    let currency: WeCurrencyDto?

    var body: some View {
        let interaction = item.kind == "interaction"
        let released = item.releasedAt != nil
        let tint = interaction ? We.interaction : We.reward
        HStack(spacing: 12) {
            Image(systemName: interaction ? "hand.thumbsup.fill" : "eye.fill").font(.system(size: 17, weight: .semibold)).foregroundStyle(tint)
                .frame(width: 40, height: 40)
                .background(tint.opacity(0.13), in: RoundedRectangle(cornerRadius: 13, style: .continuous))
            VStack(alignment: .leading, spacing: 0) {
                Text(L(interaction ? "we_reward_interaction" : "we_reward_view")).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink)
                if let title = item.ad?.title { Text(verbatim: title).font(.dorr(12)).foregroundStyle(wa.mut).lineLimit(1) }
            }
            Spacer(minLength: 6)
            VStack(alignment: .trailing, spacing: 2) {
                Text(verbatim: "+ " + weMoney(item.amountMinor, currency)).font(.dorr(13.5, .heavy))
                    .foregroundStyle(released ? We.withdrawable : We.gold).weLtr()
                Text(released ? L("we_reward_added") : L("we_reward_available_on", weDate(item.availableAt)))
                    .font(.dorr(11)).foregroundStyle(wa.soft)
            }
        }
        .padding(.vertical, 12)
    }
}
