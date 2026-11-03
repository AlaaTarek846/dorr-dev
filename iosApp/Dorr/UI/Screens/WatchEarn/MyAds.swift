import SwiftUI

/// The status filters, in the order an advertiser cares about them; nil = all.
private let statusFilters: [String?] = [nil, "active", "pending_review", "paused", "exhausted", "rejected", "suspended", "ended", "stopped"]

/// androidApp `ui/screens/watchearn/MyAds.kt` — «إعلاناتي»: filter chips by status; each ad as a card with its
/// thumbnail, title, status, how much of the budget is spent, completed views and the dates. A rejected ad shows why
/// and «edit and resend». Reloads once when the server says one of my ads changed (`watch_earn.ad.changed`) — never on
/// a timer.
@MainActor
struct MyAds: View {
    @EnvironmentObject private var host: WeHost
    let initialStatus: String?
    @State private var status: String?
    @State private var ads: [WeAdDto] = []
    @State private var page = 1
    @State private var hasMore = false
    @State private var loaded = false
    @State private var loadingMore = false
    @State private var reload = 0
    @State private var started = false

    init(initialStatus: String?) {
        self.initialStatus = initialStatus
        _status = State(initialValue: initialStatus)
    }

    var body: some View {
        WaPage(title: L("we_my_ads"), onBack: { host.pop() }) {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(statusFilters, id: \.self) { s in
                        WeChip(text: s.map { weStatusLabel($0) } ?? L("we_all"), selected: status == s) {
                            if status != s {
                                status = s
                                loaded = false
                            }
                        }
                    }
                }
                .padding(.vertical, 2)
            }
            .padding(.bottom, 12)
            .waRise(0)

            if !loaded {
                VStack(spacing: 12) {
                    ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 128) }
                }
            } else if ads.isEmpty {
                WaEmpty(icon: "megaphone.fill", tone: .blue, title: L("we_no_ads_mine"), text: L("we_no_ads_mine_text")) {
                    WaButton(text: L("we_create_ad"), icon: "plus.circle.fill") { host.push(.wizard(AdForm())) }.padding(.horizontal, 30)
                }
            } else {
                VStack(spacing: 12) {
                    ForEach(Array(ads.enumerated()), id: \.element.id) { i, ad in
                        AdCard(ad: ad, onOpen: { host.push(.stats(adId: ad.id)) }, onEdit: { host.push(.wizard(AdForm(edit: ad))) })
                            .waRise(min(i, 6))
                    }
                    if hasMore {
                        WaButton(text: L("we_load_more"), style: .ghost, loading: loadingMore, compact: true) { Task { await more() } }
                    }
                    WaButton(text: L("we_create_ad"), icon: "plus.circle.fill", compact: true) { host.push(.wizard(AdForm())) }
                        .padding(.top, 4)
                }
            }
        }
        .task(id: "\(status ?? "all")|\(reload)") { await load() }
        // One reload per change the server announces.
        .onReceive(ChatRealtime.shared.events) { event in
            if event.name == "watch_earn.ad.changed" { reload += 1 }
        }
    }

    private func load() async {
        do {
            let envelope = try await WatchEarnApi.ads(status: status, page: 1)
            ads = envelope.data ?? []
            page = 1
            hasMore = envelope.pagination?.hasMorePages == true
        } catch {
            if !loaded { host.showToast(error.weMessage(L("we_error_generic")), error: true) }
        }
        loaded = true
    }

    private func more() async {
        loadingMore = true
        if let envelope = try? await WatchEarnApi.ads(status: status, page: page + 1) {
            let known = Set(ads.map(\.id))
            ads.append(contentsOf: (envelope.data ?? []).filter { !known.contains($0.id) })
            page += 1
            hasMore = envelope.pagination?.hasMorePages == true
        }
        loadingMore = false
    }
}

private struct AdCard: View {
    @Environment(\.wa) private var wa
    let ad: WeAdDto
    let onOpen: () -> Void
    let onEdit: () -> Void

    var body: some View {
        let budget = ad.budget
        let spent = budget.totalMinor > 0 ? Double(budget.spentMinor) / Double(budget.totalMinor) : 0
        Button(action: onOpen) {
            WaCard(padding: 14) {
                HStack(spacing: 12) {
                    AdThumb(ad: ad, size: 62)
                    VStack(alignment: .leading, spacing: 5) {
                        Text(verbatim: ad.title).font(.dorr(14.5, .heavy)).foregroundStyle(wa.ink).lineLimit(2).multilineTextAlignment(.leading)
                        WeStatusBadge(status: ad.status, scheduled: ad.scheduled)
                    }
                    Spacer(minLength: 0)
                }
                HStack {
                    Text(L("we_spent_of")).font(.dorr(11.5)).foregroundStyle(wa.mut)
                    Spacer(minLength: 6)
                    Text(verbatim: weMoney(budget.spentMinor, ad.currency) + " / " + weAmount(budget.totalMinor, ad.currency.decimals))
                        .font(.dorr(11.5, .bold)).foregroundStyle(wa.ink).weLtr()
                }
                .padding(.top, 12)
                WeProgressBar(fraction: spent, color: weStatusColor(ad.status, scheduled: ad.scheduled)).padding(.top, 6)
                HStack(spacing: 5) {
                    Image(systemName: "eye.fill").font(.system(size: 12)).foregroundStyle(wa.mut)
                    Text(L("we_completed_views", ad.counts.completed)).font(.dorr(12)).foregroundStyle(wa.mut)
                    Spacer(minLength: 6)
                    Text(verbatim: weDate(ad.startsAt) + " – " + weDate(ad.endsAt)).font(.dorr(11)).foregroundStyle(wa.soft).lineLimit(1)
                }
                .padding(.top, 10)
                if ad.status == "rejected" {
                    VStack(alignment: .leading, spacing: 10) {
                        HStack(alignment: .top, spacing: 8) {
                            Image(systemName: "exclamationmark.circle").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.danger)
                            VStack(alignment: .leading, spacing: 2) {
                                Text(L("we_reject_reason")).font(.dorr(12, .heavy)).foregroundStyle(wa.danger)
                                let reason = (ad.rejectReason ?? "").trimmingCharacters(in: .whitespaces)
                                Text(verbatim: reason.isEmpty ? "—" : reason).font(.dorr(12.5)).foregroundStyle(wa.ink).lineSpacing(4).multilineTextAlignment(.leading)
                            }
                            Spacer(minLength: 0)
                        }
                        .padding(12)
                        .background(wa.danger.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        WaButton(text: L("we_edit_resend"), style: .ghost, icon: "pencil", compact: true, action: onEdit)
                    }
                    .padding(.top, 12)
                    .transition(.opacity.combined(with: .move(edge: .top)))
                }
            }
        }
        .buttonStyle(WaPressStyle(scale: 0.98))
    }
}

/// The ad's picture, or a dark tile with ▶ for a video (no frame decoder in the app).
struct AdThumb: View {
    let ad: WeAdDto
    let size: CGFloat

    var body: some View {
        ZStack {
            Color(hex: 0x111827)
            if ad.media.type == "image", ad.media.url != nil {
                WeRemoteImage(url: ad.media.url)
            } else {
                Image(systemName: "play.fill").font(.system(size: size * 0.36, weight: .bold)).foregroundStyle(.white)
            }
        }
        .frame(width: size, height: size)
        .clipShape(RoundedRectangle(cornerRadius: size * 0.28, style: .continuous))
    }
}
