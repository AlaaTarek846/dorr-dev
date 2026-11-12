// freelanceApp `ui/screens/WalletEarnings.kt`
import SwiftUI

/// My earnings from projects (FL-509 / FL-505): read once when shown, when a notice arrives and when the connection comes back.
@MainActor
final class WalletEarningsModel: ObservableObject {
    @Published var data: EarningsDto?

    func load() async {
        if let value = await AgreementsApi.earnings().value { data = value }
    }
}

/// Android `rememberEarnings()`: the earnings model, (re)read on open, on a reconnect and on a live notice.
@MainActor
private struct EarningsLoader: ViewModifier {
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject var model: WalletEarningsModel

    func body(content: Content) -> some View {
        content
            .task(id: network.reconnectTick) { await model.load() }
            .onReceive(Realtime.shared.changed) { _ in Task { @MainActor in await model.load() } }
    }
}

/// On the wallet home: what waits its days and what is on its way, with a way to the list.
@MainActor
struct ProjectEarningsCard: View {
    @Environment(\.wa) private var wa
    let hidden: Bool
    let onOpen: () -> Void
    @StateObject private var model = WalletEarningsModel()

    var body: some View {
        Group {
            if let e = model.data, (e.pendingMinor ?? 0) != 0 || (e.expectedMinor ?? 0) != 0 || !(e.recent ?? []).isEmpty {
                card(e)
            }
        }
        .modifier(EarningsLoader(model: model))
    }

    private func card(_ e: EarningsDto) -> some View {
        let money = { (minor: Int) -> String in hidden ? "••••" : "\u{200E}\(formatMinor(minor, nil)) \(e.currency ?? "")" }
        return Button(action: onOpen) {
            WaCard(padding: 16) {
                HStack(spacing: 0) {
                    Image(systemName: "briefcase.fill").font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.red)
                    Text(L("er_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.ink).frame(maxWidth: .infinity, alignment: .leading).padding(.leading, 8)
                    Image(systemName: "chevron.forward").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.red)
                }
                HStack(spacing: 12) {
                    EarningsPart(label: L("er_pending"), value: money(e.pendingMinor ?? 0))
                    EarningsPart(label: L("er_expected"), value: money(e.expectedMinor ?? 0))
                }
                .padding(.top, 10)
                if let next = e.nextReleaseAt {
                    Text(L("er_next", "\u{200E}" + String(next.prefix(10)))).font(.dorr(11)).foregroundStyle(wa.mut).padding(.top, 6)
                }
            }
            .multilineTextAlignment(.leading)
        }
        .buttonStyle(.plain)
        .padding(.top, 12)
    }
}

@MainActor
private struct EarningsPart: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(label).font(.dorr(12)).foregroundStyle(wa.mut)
            Text(verbatim: value).font(.dorr(16, .heavy)).foregroundStyle(wa.ink)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

/// The list: every earning from a part, released or waiting its day.
@MainActor
struct ProjectEarningsPage: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    @StateObject private var model = WalletEarningsModel()

    var body: some View {
        WaPage(title: L("er_title"), onBack: onBack) {
            if let e = model.data { content(e) }
        }
        .modifier(EarningsLoader(model: model))
    }

    @ViewBuilder private func content(_ e: EarningsDto) -> some View {
        let cur = e.currency ?? ""
        let amount = { (minor: Int?) -> String in "\u{200E}\(formatMinor(minor ?? 0, nil)) \(cur)" }
        WaCard(padding: 16) {
            HStack(spacing: 12) {
                EarningsPart(label: L("er_available"), value: amount(e.availableMinor))
                EarningsPart(label: L("er_pending"), value: amount(e.pendingMinor))
            }
            HStack(spacing: 12) {
                EarningsPart(label: L("er_expected"), value: amount(e.expectedMinor))
                EarningsPart(label: L("er_earned"), value: amount(e.earnedMinor))
            }
            .padding(.top, 10)
            Text(L("er_note")).font(.dorr(11)).foregroundStyle(wa.mut).padding(.top, 8)
        }
        // FL-703: my numbers and what clients said.
        MyRatingCard()
        WaSectionTitle(title: L("er_list"))
        let recent = e.recent ?? []
        if recent.isEmpty {
            WaEmpty(icon: "briefcase.fill", tone: .gray, title: L("er_empty_title"), text: L("er_empty_text"))
        }
        ForEach(Array(recent.enumerated()), id: \.offset) { _, r in
            WaCard(padding: 14) {
                HStack(spacing: 0) {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: r.project?.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.ink)
                        Text(verbatim: r.milestone ?? "").font(.dorr(12)).foregroundStyle(wa.mut)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Text(verbatim: "\u{200E}\(formatMinor(r.amountMinor ?? 0, nil)) \(r.currency ?? "")")
                        .font(.dorr(15, .heavy)).foregroundStyle(r.status == "released" ? wa.green : wa.ink)
                }
                Text(statusText(r)).font(.dorr(11)).foregroundStyle(wa.mut).padding(.top, 4)
            }
            .padding(.bottom, 8)
        }
    }

    private func statusText(_ r: EarningDto) -> String {
        switch r.status {
        case "pending": return L("er_s_pending", "\u{200E}" + String((r.releaseAt ?? "").prefix(10)))
        case "released": return L("er_s_released", "\u{200E}" + String((r.releasedAt ?? "").prefix(10)))
        default: return L("er_s_cancelled")
        }
    }
}
