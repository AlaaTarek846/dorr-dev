import SwiftUI

// androidApp `moving/MovingHome.kt` — the moving home: «طلباتي» and the button for a new request. The tabs are the
// "My rides" pill; each row is its history card with the status chip. Where the service is off in this country
// (`options.available`) the rides' unavailable card takes the place of the button, before any wizard opens; requests
// already made can still be followed.

@MainActor
struct MovingHomePage: View {
    @Environment(\.wa) private var wa
    @ObservedObject var nav: MovingNav
    @ObservedObject private var network = NetworkMonitor.shared
    let onExit: () -> Void
    @State private var tab = "active"
    @State private var rows: [MovingRequestDto]?
    @State private var page = 1
    @State private var hasMore = false
    @State private var loadingMore = false
    @State private var error: String?
    @State private var checked = false
    @State private var reloadKey = 0
    @State private var deleting: MovingRequestDto?

    var body: some View {
        MvPage(title: L("mv_title"), onBack: onExit,
               bottom: nav.serviceOff || !checked ? nil : AnyView(
                   AccentButton(text: L("mv_new_request"), icon: "plus") { nav.push(.wizard(uuid: nil, atReview: false)) }
               )) {
            VStack(spacing: 0) {
                // The tabs: a pill that fills the chosen one.
                HStack(spacing: 0) {
                    ForEach([("active", "mv_tab_active"), ("all", "mv_tab_all")], id: \.0) { key, label in
                        let on = key == tab
                        Button {
                            if !on { tab = key; rows = nil }
                        } label: {
                            Text(L(label)).font(.dorr(14, on ? .bold : .semibold)).foregroundStyle(on ? .white : wa.settingsInk)
                                .frame(maxWidth: .infinity).padding(.vertical, 10)
                                .background(on ? wa.accent : .clear, in: Capsule())
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(4)
                .background(wa.settingsCard, in: Capsule())
                .padding(.horizontal, 16)
                .animation(.easeInOut(duration: 0.24), value: tab)
                list.padding(.top, 10).frame(maxHeight: .infinity)
                if nav.serviceOff {
                    MovingUnavailablePanel {
                        checked = false
                        nav.serviceOff = false
                    }
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                }
            }
            .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32), value: nav.serviceOff)
        }
        // Is the service open here? Asked on opening, so the button for a new request shows only when it is.
        .task(id: "\(network.reconnectTick)|\(nav.serviceOff)") {
            if !nav.serviceOff {
                let options = try? await MovingApi.options().data
                if options?.available == false { nav.serviceOff = true }
            }
            checked = true
        }
        .task(id: "\(tab)|\(network.reconnectTick)|\(reloadKey)") { await load() }
        // A draft is taken back with `withdraw`: it leaves the running list, and «كل طلباتي» still shows it as withdrawn.
        .sheet(item: $deleting) { draft in
            MovingWithdrawSheet(uuid: draft.uuid, title: L("mv_draft_delete_title"), body: L("mv_draft_delete_body"), action: L("mv_draft_delete"),
                                onClose: { deleting = nil },
                                onDone: { message in
                                    deleting = nil
                                    if let message, !message.isEmpty { AppToast.show(message) }
                                    reloadKey += 1
                                })
                .dorrThemed().presentationDetents([.medium, .large])
        }
    }

    @ViewBuilder
    private var list: some View {
        if let list = rows {
            if list.isEmpty {
                ScrollView {
                    MvEmpty(icon: "box.truck.fill", text: error ?? L(nav.serviceOff ? "mv_empty_off" : "mv_empty")).frame(minHeight: 320)
                }
                .refreshable { await mvPull() }
            } else {
                ScrollView {
                    LazyVStack(spacing: 10) {
                        ForEach(Array(list.enumerated()), id: \.element.uuid) { i, row in
                            RequestCard(item: row, onDelete: row.status == "draft" ? { deleting = row } : nil) {
                                nav.push(row.status == "draft" ? .wizard(uuid: row.uuid, atReview: false) : .request(row.uuid))
                            }
                            .staggerIn(i)
                        }
                        if hasMore {
                            ProgressView().tint(wa.accent).frame(maxWidth: .infinity).padding(.vertical, 8)
                                .onAppear { loadMore() }
                        }
                        Spacer().frame(height: 16)
                    }
                    .padding(.horizontal, 16)
                }
                .refreshable { await mvPull() }
            }
        } else {
            MvLoading()
        }
    }

    private func load() async {
        error = nil
        do {
            let envelope = try await MovingApi.requests(status: tab == "active" ? "active" : nil, page: 1)
            rows = envelope.data ?? []
            page = 1
            hasMore = envelope.pagination?.hasMorePages == true
        } catch {
            let refusal = error.movingRefusal
            if refusal.code == "moving_service_off" {
                nav.serviceOff = true
                if rows == nil { rows = [] }
            } else {
                self.error = refusal.message ?? L("rd_error")
                if rows == nil { rows = [] }
            }
        }
    }

    private func loadMore() {
        guard !loadingMore, hasMore else { return }
        loadingMore = true
        Task {
            do {
                let envelope = try await MovingApi.requests(status: tab == "active" ? "active" : nil, page: page + 1)
                let known = Set((rows ?? []).map(\.uuid))
                rows = (rows ?? []) + (envelope.data ?? []).filter { !known.contains($0.uuid) }
                page += 1
                hasMore = envelope.pagination?.hasMorePages == true
            } catch {
                hasMore = false
            }
            loadingMore = false
        }
    }
}

private struct RequestCard: View {
    @Environment(\.wa) private var wa
    let item: MovingRequestDto
    let onDelete: (() -> Void)?
    let action: () -> Void

    var body: some View {
        let (label, color) = movingStatusLook(item.status, wa)
        Button(action: action) {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    Image(systemName: "box.truck.fill").font(.system(size: 18)).foregroundStyle(color)
                        .frame(width: 40, height: 40).background(color.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: loadTypeLabel(item.loadType)).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                        if let t = mvTime(item.scheduledAt) { Text(verbatim: rideWhenText(t)).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    }
                    Spacer(minLength: 0)
                    MvChip(text: label, color: color)
                }
                let from = item.pickup?.area ?? item.pickup?.address
                let to = item.dropoff?.area ?? item.dropoff?.address
                if from != nil || to != nil {
                    TripLine(dot: AppColors.brandNavy, address: from).padding(.top, 8)
                    TripLine(dot: AppColors.brandOrange, address: to)
                }
                let total = item.money?.totalMinor
                if total != nil || item.establishment?.name != nil {
                    HStack {
                        Text(verbatim: item.establishment?.name ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        Spacer()
                        if let total { Text(verbatim: mvMoney(total, item.currencyCode, item.currencyDecimals)).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk) }
                    }
                    .padding(.top, 6)
                }
                if let onDelete {
                    Button(action: onDelete) {
                        Text(L("mv_draft_delete")).font(.dorr(12, .semibold)).foregroundStyle(AppColors.danger).padding(.vertical, 4)
                    }
                    .buttonStyle(.plain)
                    .padding(.top, 6)
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(WaPressStyle())
    }
}

/// "Moving isn't available here": the rides' unavailable card, with its own words.
struct MovingUnavailablePanel: View {
    @Environment(\.wa) private var wa
    let onRetry: () -> Void
    @State private var pulse = false

    var body: some View {
        PanelCard {
            VStack(spacing: 8) {
                Image(systemName: "location.slash.fill").font(.system(size: 30)).foregroundStyle(wa.accent)
                    .frame(width: 72, height: 72).background(wa.accent.opacity(0.12), in: Circle())
                    .scaleEffect(pulse ? 1.08 : 1)
                    .onAppear { withAnimation(.easeInOut(duration: 1.1).repeatForever()) { pulse = true } }
                Text(L("mv_na_title")).font(.dorr(18, .bold)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
                Text(L("mv_na_body")).font(.dorr(14)).foregroundStyle(wa.settingsInk.opacity(0.65)).multilineTextAlignment(.center)
                AccentButton(text: L("rd_retry"), action: onRetry).padding(.top, 6)
            }
            .frame(maxWidth: .infinity)
        }
        .ignoresSafeArea(edges: .bottom)
    }
}
