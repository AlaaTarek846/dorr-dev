import PhotosUI
import SwiftUI

// freelanceApp `ui/screens/ServiceScreens.kt`.

private let MIN_DESCRIPTION = 30

// MARK: - my services

/// FL-L04, "My services": a head card with what is live and what waits, a banner to the orders waiting for an answer, the statuses to
/// filter by (with their counts), and one card per service — its cover, status, starting price, days, levels and orders. A first-time
/// freelancer gets the three steps of how it works. Read when the page opens, when the connection returns and when a notice arrives.
struct MyServicesScreen: View {
    let onBack: () -> Void
    let onOpenService: (String?) -> Void
    let onOpenOrders: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var data: MyServicesDto?
    @State private var waiting = 0
    @State private var error: String?
    @State private var closed = false
    @State private var filter = "all"

    private func load() async {
        let result = await ServicesApi.mine()
        switch result {
        case .ok(let d, _):
            data = d
            error = nil
        case .failed(let e) where e.errorCode == "freelance_feature_off":
            closed = true
        default:
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
        if let rows = await ServicesApi.orders(status: "awaiting", perPage: 50).value { waiting = rows.count }
    }

    var body: some View {
        Page(title: L("svc_title"), onBack: onBack) {
            if closed {
                EmptyState(icon: "storefront", title: L("svc_title"), body: L("svc_unavailable")).padding(.top, 80)
            } else if let d = data {
                let counts = d.counts ?? [:]
                let services = d.services ?? []
                let live = (counts["published"] ?? 0) + (counts["pending_review"] ?? 0)
                ServicesHeroCard(d: d, live: live, waiting: waiting, onNew: { onOpenService(nil) })
                if waiting > 0 { OrdersBanner(count: waiting, onOpen: onOpenOrders) }

                if services.isEmpty {
                    HowItWorks(review: d.review ?? true)
                } else {
                    let statuses = ["all", "published", "pending_review", "draft", "paused", "rejected", "suspended"]
                        .filter { $0 == "all" || (counts[$0] ?? 0) > 0 }
                    if statuses.count > 2 {
                        ServicesFilterRow(statuses: statuses, selected: filter, counts: counts, total: services.count) { filter = $0 }
                    }
                    let shown = services.filter { filter == "all" || ($0.status ?? "draft") == filter }
                    if shown.isEmpty {
                        Text(L("svc_none_filtered")).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                    }
                    ForEach(shown) { s in ServiceCard(s: s) { onOpenService(s.uuid) } }
                }
            } else if error == nil {
                SkeletonList()
            }
        }
        .messageToast(error)
        .task(id: network.reconnectTick) { await load() }
        .onReceive(Realtime.shared.changed) { Task { await load() } }
    }
}

private struct ServicesHeroCard: View {
    @Environment(\.wa) private var wa
    let d: MyServicesDto
    let live: Int
    let waiting: Int
    let onNew: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 12) {
                Image(systemName: "storefront.fill").font(.system(size: 20)).foregroundStyle(.white)
                    .frame(width: 46, height: 46)
                    .background(Color.white.opacity(0.18), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("svc_hero_title")).font(.dorr(17, .heavy)).foregroundStyle(.white)
                    Text(L("svc_hero_body")).font(.dorr(12)).foregroundStyle(.white.opacity(0.88)).lineSpacing(2)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            HStack(spacing: 8) {
                HeroStat(value: d.counts?["published"] ?? 0, label: L("svc_stat_published"))
                HeroStat(value: d.counts?["pending_review"] ?? 0, label: L("svc_stat_review"))
                HeroStat(value: waiting, label: L("svc_stat_waiting"))
            }
            HStack(spacing: 0) {
                Text(L("svc_hero_live", live, d.limits?.maxActive ?? 5)).font(.dorr(12, .semibold)).foregroundStyle(.white.opacity(0.9))
                    .frame(maxWidth: .infinity, alignment: .leading)
                Button(action: onNew) {
                    HStack(spacing: 4) {
                        Image(systemName: "plus").font(.system(size: 14, weight: .bold))
                        Text(L("svc_new")).font(.dorr(13, .heavy))
                    }
                    .foregroundStyle(wa.red)
                    .padding(.horizontal, 14)
                    .padding(.vertical, 9)
                    .background(Color.white, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(18)
        .frame(maxWidth: 520, alignment: .leading)
        .background(LinearGradient(colors: [wa.red, wa.red.opacity(0.78)], startPoint: .topLeading, endPoint: .bottomTrailing),
                    in: RoundedRectangle(cornerRadius: 24, style: .continuous))
        .frame(maxWidth: .infinity)
    }
}

private struct HeroStat: View {
    let value: Int
    let label: String

    var body: some View {
        VStack(spacing: 0) {
            Text(verbatim: "\(value)").font(.dorr(20, .black)).foregroundStyle(.white)
            Text(verbatim: label).font(.dorr(11)).foregroundStyle(.white.opacity(0.85)).lineLimit(1)
        }
        .padding(.vertical, 10)
        .padding(.horizontal, 8)
        .frame(maxWidth: .infinity)
        .background(Color.white.opacity(0.16), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// The orders waiting for my answer, as a banner that opens them (also on Home).
struct OrdersBanner: View {
    @Environment(\.wa) private var wa
    let count: Int
    let onOpen: () -> Void

    var body: some View {
        let tone = serviceTone("awaiting", night: wa.night)
        Button(action: onOpen) {
            HStack(spacing: 12) {
                Image(systemName: "tray.fill").font(.system(size: 18)).foregroundStyle(tone.ink)
                    .frame(width: 42, height: 42)
                    .background(tone.fill, in: Circle())
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("svc_orders_banner", count)).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(L("svc_orders_banner_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: "\(count)").font(.dorr(13, .heavy)).foregroundStyle(.white)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 4)
                    .background(tone.ink, in: Capsule())
            }
            .padding(14)
            .frame(maxWidth: 520)
            .settingsSurface(18)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity)
    }
}

private struct HowItWorks: View {
    @Environment(\.wa) private var wa
    let review: Bool

    var body: some View {
        InfoCard(title: L("svc_how_title"), icon: "info.circle.fill") {
            let lines = [L("svc_how_1"), review ? L("svc_how_2") : L("svc_how_2_direct"), L("svc_how_3")]
            ForEach(Array(lines.enumerated()), id: \.offset) { i, text in
                HStack(alignment: .top, spacing: 12) {
                    Text(verbatim: "\(i + 1)").font(.dorr(12, .heavy)).foregroundStyle(wa.red)
                        .frame(width: 26, height: 26)
                        .background(wa.red.opacity(0.14), in: Circle())
                    Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(.top, 3)
                }
            }
        }
        EmptyState(icon: "storefront", title: L("svc_empty_title"), body: L("svc_empty_body")).padding(.top, 12)
    }
}

private struct ServicesFilterRow: View {
    @Environment(\.wa) private var wa
    let statuses: [String]
    let selected: String
    let counts: [String: Int]
    let total: Int
    let onSelect: (String) -> Void

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(statuses, id: \.self) { s in
                    let on = s == selected
                    let n = s == "all" ? total : counts[s] ?? 0
                    Button { onSelect(s) } label: {
                        HStack(spacing: 6) {
                            Text(verbatim: s == "all" ? L("svc_all") : serviceStatusLabel(s)).font(.dorr(13, .bold))
                                .foregroundStyle(on ? Color.white : wa.settingsInk).lineLimit(1)
                            Text(verbatim: "\(n)").font(.dorr(12, .bold)).foregroundStyle(on ? Color.white.opacity(0.85) : wa.settingsMut)
                        }
                        .padding(.horizontal, 14)
                        .padding(.vertical, 8)
                        .background(on ? wa.red : wa.red.opacity(0.08), in: Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }
}

private struct ServiceCard: View {
    @Environment(\.wa) private var wa
    let s: ServiceDto
    let onClick: () -> Void

    var body: some View {
        let status = s.status ?? "draft"
        let tone = serviceTone(status, night: wa.night)
        let tiers = s.tiers ?? []
        let decimals = s.decimals ?? 2
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 0) {
                ZStack(alignment: .topLeading) {
                    wa.red.opacity(0.10)
                    if let cover = s.images?.first?.path {
                        ProfileAuthedImage(path: cover) { Color.clear }
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                            .clipped()
                    } else {
                        Image(systemName: "photo").font(.system(size: 34)).foregroundStyle(wa.red.opacity(0.6))
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                    }
                    SvcPill(text: serviceStatusLabel(status), tone: tone, solid: true).padding(10)
                }
                .frame(height: 136)
                .frame(maxWidth: .infinity)
                .clipped()
                VStack(alignment: .leading, spacing: 8) {
                    Text(verbatim: s.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    if let name = s.specialty?.name { Text(verbatim: name).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    HStack(spacing: 0) {
                        if let price = s.startingPriceMinor {
                            Text(L("svc_from", svcMoney(price, decimals, s.currency))).font(.dorr(14, .heavy)).foregroundStyle(wa.red)
                                .frame(maxWidth: .infinity, alignment: .leading)
                        } else {
                            Spacer(minLength: 0)
                        }
                        if let first = tiers.first {
                            Image(systemName: "clock").font(.system(size: 13)).foregroundStyle(wa.settingsMut)
                            Spacer().frame(width: 4)
                            Text(verbatim: daysLabel(first.deliveryDays ?? 1)).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                        }
                    }
                    FlowLayout(spacing: 6) {
                        ForEach(SERVICE_LEVELS, id: \.self) { level in
                            let on = tiers.contains { ($0.level ?? "basic") == level }
                            Text(verbatim: levelLabel(level)).font(.dorr(11, .bold))
                                .foregroundStyle(on ? wa.red : wa.settingsMut.opacity(0.5))
                                .padding(.horizontal, 8)
                                .padding(.vertical, 3)
                                .background(on ? wa.red.opacity(0.10) : .clear, in: RoundedRectangle(cornerRadius: 8, style: .continuous))
                        }
                        if (s.orders?.awaiting ?? 0) > 0 {
                            SvcPill(text: L("svc_waiting_orders", s.orders?.awaiting ?? 0), tone: serviceTone("awaiting", night: wa.night))
                        }
                        if (s.orders?.active ?? 0) > 0 {
                            SvcPill(text: L("svc_active_orders", s.orders?.active ?? 0), tone: serviceTone("active", night: wa.night))
                        }
                        if (s.orders?.completed ?? 0) > 0 {
                            SvcPill(text: L("svc_done_orders", s.orders?.completed ?? 0), tone: serviceTone("paused", night: wa.night))
                        }
                    }
                    if status == "rejected" || status == "suspended", let reason = s.statusReason, !reason.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(L("svc_reason", reason)).font(.dorr(12)).foregroundStyle(tone.ink).lineSpacing(2)
                            .padding(10)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .background(tone.fill, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    }
                }
                .padding(14)
            }
            .frame(maxWidth: 520)
            .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
            .settingsSurface(20)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity)
    }
}

// MARK: - the form

/// One level as typed: the price stays text until it is sent, so a half-typed amount is never rounded.
private struct TierForm {
    var level: String
    var enabled: Bool
    var name = ""
    var description = ""
    var price = ""
    var days = 3
    var revisions = 1
    var features: [String] = []
    var featureDraft = ""
}

/// FL-L05: a month of a monthly service (freelance.subscriptions.period_days).
private let MONTH_DAYS = 30

private struct AddonForm: Identifiable {
    let key: Int
    var title = ""
    var price = ""
    var days = 0
    var id: Int { key }
}

/// An image picked or photographed, waiting to go up after the service is saved.
private struct PendingImage: Identifiable {
    let id = UUID()
    let part: FilePart
    let preview: UIImage?
}

/// The whole form.
private struct ServiceForm {
    var title = ""
    var description = ""
    var requirements = ""
    var specialtyId: Int?
    /// FL-L05: once · monthly
    var billing = "once"
    var tiers: [TierForm] = SERVICE_LEVELS.map { TierForm(level: $0, enabled: $0 == "basic") }
    var addons: [AddonForm] = []
    var pending: [PendingImage] = []
    var tab = "basic"
    var filled = false

    func fill(_ s: ServiceDto) -> ServiceForm {
        var f = self
        let decimals = s.decimals ?? 2
        f.title = s.title ?? ""
        f.description = s.description ?? ""
        f.requirements = s.requirements ?? ""
        f.specialtyId = s.specialty?.id
        f.billing = s.billing ?? "once"
        f.tiers = SERVICE_LEVELS.map { level in
            guard let t = (s.tiers ?? []).first(where: { ($0.level ?? "basic") == level }) else { return TierForm(level: level, enabled: false) }
            return TierForm(level: level, enabled: true, name: t.name ?? "", description: t.description ?? "",
                            price: minorToInput(t.priceMinor ?? 0, decimals), days: t.deliveryDays ?? 1, revisions: t.revisions ?? 0, features: t.features ?? [])
        }
        f.addons = (s.addons ?? []).enumerated().map { i, a in
            AddonForm(key: i + 1, title: a.title ?? "", price: minorToInput(a.priceMinor ?? 0, decimals), days: a.extraDays ?? 0)
        }
        f.filled = true
        return f
    }
}

/// One service: new (`uuid` nil) or mine. Four numbered steps — about it, its levels (a segmented picker for basic / standard / premium,
/// each with its price in my currency, days, revisions and what it includes), the extras, the images — then a preview of the card
/// clients see. The text and the levels go in one request; the images go up one by one after that. A draft or one sent back is saved
/// and sent for review in one tap (or kept as a draft); an edit of a live one says it goes back to review. Pause, turn on and delete
/// sit under the form. A suspended one is shown read-only with the reason.
struct ServiceEditorScreen: View {
    let uuid: String?
    let onBack: () -> Void

    @Environment(\.wa) private var wa
    @State private var currentUuid: String?
    @State private var form = ServiceForm()
    @State private var service: ServiceDto?
    @State private var meta: MyServicesDto?
    @State private var specialties: [MySpecialtyDto] = []
    @State private var busy = false
    @State private var error: String?
    @State private var confirmDelete = false
    @State private var started = false
    @State private var galleryItem: PhotosPickerItem?
    @State private var cameraOpen = false

    init(uuid: String?, onBack: @escaping () -> Void) {
        self.uuid = uuid
        self.onBack = onBack
        _currentUuid = State(initialValue: uuid)
    }

    private var offline: String { L("login_error_offline") }
    private var generic: String { L("login_error_generic") }

    private var decimals: Int { service?.decimals ?? meta?.decimals ?? 2 }
    private var currency: String? { service?.currency ?? meta?.currency }
    private var status: String { service?.status ?? "draft" }
    private var readOnly: Bool { status == "suspended" }
    private var maxImages: Int { meta?.limits?.maxImages ?? 5 }
    private var existing: [ServiceImageDto] { service?.images ?? [] }
    private var imageCount: Int { existing.count + form.pending.count }

    // ---- checks, as the server makes them, before anything is sent
    private var enabledTiers: [TierForm] { form.tiers.filter(\.enabled) }
    private var tierPrices: [String: Int?] {
        Dictionary(uniqueKeysWithValues: enabledTiers.map { ($0.level, inputToMinor($0.price, decimals)) })
    }

    private func priceProblem(_ t: TierForm) -> String? {
        let prices = tierPrices
        guard let entry = prices[t.level], let price = entry else { return nil }
        if let min = meta?.price?.minMinor, price < min { return L("svc_price_min", svcMoney(min, decimals, currency)) }
        if let max = meta?.price?.maxMinor, price > max { return L("svc_price_max", svcMoney(max, decimals, currency)) }
        let enabled = enabledTiers
        if let i = enabled.firstIndex(where: { $0.level == t.level }), i > 0, let before = prices[enabled[i - 1].level] ?? nil, price <= before {
            return L("svc_price_order", svcMoney(before, decimals, currency))
        }
        return nil
    }

    private var problem: String? {
        let enabled = enabledTiers
        let prices = tierPrices
        if form.title.trimmingCharacters(in: .whitespacesAndNewlines).count < 5 { return L("svc_fix_title") }
        if form.description.trimmingCharacters(in: .whitespacesAndNewlines).count < MIN_DESCRIPTION { return L("svc_fix_description", MIN_DESCRIPTION) }
        if enabled.contains(where: { (prices[$0.level] ?? nil) == nil }) { return L("svc_fix_price") }
        // FL-L05: every month is delivered within the month.
        if form.billing == "monthly" && (enabled.map(\.days).max() ?? 0) + (form.addons.map(\.days).max() ?? 0) > MONTH_DAYS {
            return L("svc_fix_monthly_days", MONTH_DAYS)
        }
        return enabled.lazy.compactMap { priceProblem($0) }.first
    }

    private func start() async {
        if let rows = await ListsApi.mySpecialties().value { specialties = rows.filter { $0.isActive ?? true } }
        let m = await ServicesApi.mine()
        if let data = m.value { meta = data } else { error = m.errorText(offline) ?? generic }
        if let id = currentUuid {
            let r = await ServicesApi.show(id)
            if let s = r.value {
                service = s
                // A form already typed keeps what was typed; only a fresh one is filled from the server.
                if !form.filled { form = form.fill(s) }
            } else {
                error = r.errorText(offline) ?? generic
            }
        }
    }

    // ---- images
    private func take(_ data: Data?) {
        guard let data else { return }
        Task {
            let part = await Task.detached { preparePortfolioImage(data, name: "service_\(Int(Date().timeIntervalSince1970 * 1000))") }.value
            if let part {
                if imageCount < maxImages { form.pending.append(PendingImage(part: part, preview: UIImage(data: part.data))) }
            } else {
                error = generic
            }
        }
    }

    private func deleteExisting(_ media: Int) {
        guard let id = currentUuid else { return }
        busy = true
        Task {
            let r = await ServicesApi.deleteImage(id, media)
            busy = false
            if let s = r.value { service = s } else { error = r.errorText(offline) ?? generic }
        }
    }

    // ---- save (then the images, then send if asked)
    private func save(send: Bool) {
        if let problem { error = problem; return }
        busy = true
        error = nil
        let prices = tierPrices
        func trimmed(_ s: String) -> String { s.trimmingCharacters(in: .whitespacesAndNewlines) }
        let body = ServiceRequest(
            title: trimmed(form.title), description: trimmed(form.description),
            requirements: trimmed(form.requirements).isEmpty ? nil : trimmed(form.requirements), specialtyId: form.specialtyId,
            billing: form.billing,
            tiers: enabledTiers.map { t in
                ServiceTierRequest(level: t.level, name: trimmed(t.name).isEmpty ? nil : trimmed(t.name),
                                   description: trimmed(t.description).isEmpty ? nil : trimmed(t.description),
                                   priceMinor: (prices[t.level] ?? nil) ?? 0, deliveryDays: t.days, revisions: t.revisions, features: t.features)
            },
            addons: form.addons.filter { !trimmed($0.title).isEmpty && inputToMinor($0.price, decimals) != nil }
                .map { ServiceAddonRequest(title: trimmed($0.title), priceMinor: inputToMinor($0.price, decimals) ?? 0, extraDays: $0.days) }
        )
        Task {
            let id = currentUuid
            let saved = id == nil ? await ServicesApi.create(body) : await ServicesApi.update(id!, body)
            guard let savedService = saved.value, let savedUuid = savedService.uuid else {
                busy = false
                error = saved.errorText(offline) ?? generic
                return
            }
            // From here the service exists: a failed image no longer loses the rest.
            currentUuid = savedUuid
            service = savedService
            for image in form.pending {
                let up = await ServicesApi.addImage(savedUuid, image.part)
                if let s = up.value {
                    service = s
                    form.pending.removeAll { $0.id == image.id }
                } else {
                    busy = false
                    error = up.errorText(offline) ?? generic
                    return
                }
            }
            var message = L("svc_saved")
            if send && service?.can?.submit == true {
                let sent = await ServicesApi.act(savedUuid, "submit")
                guard let s = sent.value else {
                    busy = false
                    error = sent.errorText(offline) ?? generic
                    return
                }
                service = s
                message = s.status == "published" ? L("svc_published_now") : L("svc_sent")
            }
            busy = false
            await AppToast.show(message)
            onBack()
        }
    }

    private func act(_ action: String) {
        guard let id = currentUuid else { return }
        busy = true
        Task {
            let r = await ServicesApi.act(id, action)
            busy = false
            if let s = r.value { service = s } else { error = r.errorText(offline) ?? generic }
        }
    }

    private func delete() {
        guard let id = currentUuid else { return }
        busy = true
        Task {
            let failed = await ServicesApi.delete(id, offline: offline, generic: generic)
            busy = false
            confirmDelete = false
            if failed == nil { onBack() } else { error = failed }
        }
    }

    var body: some View {
        Page(title: currentUuid == nil ? L("svc_new_title") : L("svc_edit_title"), onBack: onBack) {
            if let m = meta, currentUuid == nil || service != nil {
                editor(m)
            } else if error == nil {
                SkeletonDetail()
            }
        }
        .messageToast(error)
        .task {
            guard !started else { return }
            started = true
            await start()
        }
        .onChange(of: galleryItem) { item in
            guard let item else { return }
            Task {
                let data = try? await item.loadTransferable(type: Data.self)
                galleryItem = nil
                take(data)
            }
        }
        .fullScreenCover(isPresented: $cameraOpen) {
            ProfileCameraPicker { data in
                cameraOpen = false
                take(data)
            }
            .ignoresSafeArea()
        }
        .dorrDialog(isPresented: $confirmDelete) {
            ConfirmDialog(icon: "trash", title: L("svc_delete_title"), message: L("svc_delete_body"), loading: busy,
                          confirmLabel: L("common_delete"), onConfirm: delete, onDismiss: { confirmDelete = false })
        }
    }

    @ViewBuilder
    private func editor(_ m: MyServicesDto) -> some View {
        if currentUuid != nil, let s = service { StateBanner(s: s) }

        // 1 — about it
        StepCard(number: 1, title: L("svc_step_about"), subtitle: L("svc_step_about_sub")) {
            DorrTextField(value: limited(\.title, 120), label: L("svc_f_title"), placeholder: L("svc_f_title_ph"), icon: "textformat", enabled: !readOnly)
            VStack(alignment: .leading, spacing: 4) {
                DorrTextField(value: limited(\.description, 5000), label: L("svc_f_description"), icon: "text.alignleft", singleLine: false, minLines: 4, enabled: !readOnly)
                let length = form.description.trimmingCharacters(in: .whitespacesAndNewlines).count
                HStack(spacing: 0) {
                    Text(L("svc_f_description_hint", MIN_DESCRIPTION)).font(.dorr(11)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                    Text(verbatim: "\(length)").font(.dorr(11, .bold)).foregroundStyle(length >= MIN_DESCRIPTION ? Color(hex: 0x16A34A) : wa.settingsMut)
                }
            }
            if !specialties.isEmpty {
                Text(L("svc_f_specialty")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                ChipRow(
                    options: specialties.compactMap { s in s.id.map { (value: String($0), label: s.name ?? "") } },
                    selected: Set([form.specialtyId.map { String($0) }].compactMap { $0 }),
                    onToggle: { v in
                        if !readOnly { form.specialtyId = form.specialtyId.map { String($0) } == v ? nil : Int(v) }
                    }
                )
            }
            VStack(alignment: .leading, spacing: 4) {
                DorrTextField(value: limited(\.requirements, 1000), label: L("svc_f_requirements"), placeholder: L("svc_f_requirements_ph"),
                              icon: "questionmark.bubble", enabled: !readOnly)
                Text(L("svc_f_requirements_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
        }

        // 2 — levels
        StepCard(number: 2, title: L("svc_step_levels"), subtitle: L("svc_step_levels_sub")) {
            // FL-L05: sold once, or every month (the client confirms and pays each month; it lasts until either side ends it with notice).
            HStack(spacing: 10) {
                Image(systemName: "repeat").font(.system(size: 18, weight: .semibold)).foregroundStyle(wa.red).frame(width: 22, height: 22)
                VStack(alignment: .leading, spacing: 2) {
                    Text(L("svc_monthly")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                    Text(L("svc_monthly_hint", MONTH_DAYS)).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                RedToggle(on: form.billing == "monthly") {
                    if !readOnly { form.billing = form.billing == "monthly" ? "once" : "monthly" }
                }
            }
            .padding(12)
            .background(wa.red.opacity(form.billing == "monthly" ? 0.10 : 0.05), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            Segmented(options: SERVICE_LEVELS.map { ($0, levelLabel($0)) }, selected: form.tab, marks: Set(enabledTiers.map(\.level))) { form.tab = $0 }
            let index = max(SERVICE_LEVELS.firstIndex(of: form.tab) ?? 0, 0)
            let tier = form.tiers[index]
            let put: (TierForm) -> Void = { t in form.tiers[index] = t }
            if tier.level != "basic" {
                HStack(spacing: 10) {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("svc_level_on")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                        if !tier.enabled { Text(L("svc_level_off_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    RedToggle(on: tier.enabled) {
                        if !readOnly { var t = tier; t.enabled.toggle(); put(t) }
                    }
                }
            }
            if tier.enabled {
                TierFields(tier: tier, currency: currency, priceProblem: priceProblem(tier), maxFeatures: m.limits?.maxFeatures ?? 10, readOnly: readOnly, put: put)
            }
        }

        // 3 — extras
        StepCard(number: 3, title: L("svc_step_extras"), subtitle: L("svc_step_extras_sub")) {
            if form.addons.isEmpty { Text(L("svc_addons_none")).font(.dorr(13)).foregroundStyle(wa.settingsMut) }
            ForEach(form.addons) { a in
                AddonFields(a: a, currency: currency, readOnly: readOnly,
                            onChange: { changed in if let i = form.addons.firstIndex(where: { $0.key == a.key }) { form.addons[i] = changed } },
                            onRemove: { form.addons.removeAll { $0.key == a.key } })
            }
            if !readOnly && form.addons.count < (m.limits?.maxAddons ?? 5) {
                OutlineAction(icon: "plus", label: L("svc_addon_add")) {
                    form.addons.append(AddonForm(key: Int(DispatchTime.now().uptimeNanoseconds)))
                }
            }
        }

        // 4 — images
        StepCard(number: 4, title: L("svc_step_images"), subtitle: L("svc_step_images_sub")) {
            Text(L("svc_images_count", imageCount, maxImages)).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
            FlowLayout(spacing: 8) {
                ForEach(Array(existing.enumerated()), id: \.offset) { i, img in
                    ServiceThumb(path: img.path, image: nil, cover: i == 0, enabled: !busy && !readOnly) { if let id = img.id { deleteExisting(id) } }
                }
                ForEach(Array(form.pending.enumerated()), id: \.element.id) { i, p in
                    ServiceThumb(path: nil, image: p.preview, cover: existing.isEmpty && i == 0, enabled: !busy) {
                        form.pending.removeAll { $0.id == p.id }
                    }
                }
                if !readOnly && imageCount < maxImages {
                    PhotosPicker(selection: $galleryItem, matching: .images) {
                        AddTile(icon: "photo.badge.plus", label: L("profile_photo_gallery"))
                    }
                    .buttonStyle(.plain)
                    Button { cameraOpen = true } label: { AddTile(icon: "camera.fill", label: L("profile_photo_camera")) }
                        .buttonStyle(.plain)
                }
            }
        }

        // How clients will see it.
        ServicePreview(form: form, coverPath: existing.first?.path, coverImage: form.pending.first?.preview, prices: tierPrices, decimals: decimals, currency: currency)

        // The buttons.
        if !readOnly {
            VStack(spacing: 10) {
                let canSend = status == "draft" || status == "rejected"
                // The floating toast is easy to miss: the reason also stays above the buttons.
                if let error {
                    Text(verbatim: error).font(.dorr(13, .bold)).foregroundStyle(serviceTone("rejected", night: wa.night).ink).lineSpacing(3)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
                if canSend {
                    SettingsPrimaryButton(text: (m.review ?? true) ? L("svc_save_send") : L("svc_save_publish"), loading: busy) { save(send: true) }
                    Button { save(send: false) } label: {
                        Text(L("svc_save_draft")).font(.dorr(14, .bold)).foregroundStyle(wa.red).padding(10)
                    }
                    .buttonStyle(.plain)
                    .disabled(busy)
                } else {
                    if (m.review ?? true) && (status == "published" || status == "paused") {
                        Text(L("svc_edit_rereview")).font(.dorr(12)).foregroundStyle(serviceTone("pending_review", night: wa.night).ink).lineSpacing(2)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    SettingsPrimaryButton(text: L("svc_save_changes"), loading: busy) { save(send: false) }
                }
            }
            .frame(maxWidth: 520)
            .frame(maxWidth: .infinity)
        }
        if let s = service {
            ManageCard(s: s, busy: busy, onPause: { act("pause") }, onResume: { act("resume") }, onDelete: { confirmDelete = true })
        }
    }

    /// A binding to a text of the form, cut at `max` characters (`it.take(max)`).
    private func limited(_ key: WritableKeyPath<ServiceForm, String>, _ max: Int) -> Binding<String> {
        Binding(get: { form[keyPath: key] }, set: { form[keyPath: key] = String($0.prefix(max)) })
    }
}

private struct StateBanner: View {
    @Environment(\.wa) private var wa
    let s: ServiceDto

    var body: some View {
        let status = s.status ?? "draft"
        let tone = serviceTone(status, night: wa.night)
        let text: String = {
            switch status {
            case "pending_review": return L("svc_state_pending_review")
            case "published": return L("svc_state_published")
            case "paused": return L("svc_state_paused")
            case "rejected": return L("svc_state_rejected")
            case "suspended": return L("svc_state_suspended")
            default: return L("svc_state_draft")
            }
        }()
        VStack(alignment: .leading, spacing: 6) {
            SvcPill(text: serviceStatusLabel(status), tone: tone, solid: true)
            Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(3)
            if let reason = s.statusReason, !reason.trimmingCharacters(in: .whitespaces).isEmpty, status == "rejected" || status == "suspended" {
                Text(L("svc_reason", reason)).font(.dorr(13, .bold)).foregroundStyle(tone.ink).lineSpacing(3)
            }
        }
        .padding(14)
        .frame(maxWidth: 520, alignment: .leading)
        .background(tone.fill, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .frame(maxWidth: .infinity)
    }
}

/// Digits, `.` and `,` only, at most 14 characters (what the price field keeps).
private func priceText(_ s: String) -> String { String(s.filter { $0.isNumber || $0 == "." || $0 == "," }.prefix(14)) }

private struct TierFields: View {
    @Environment(\.wa) private var wa
    let tier: TierForm
    let currency: String?
    let priceProblem: String?
    let maxFeatures: Int
    let readOnly: Bool
    let put: (TierForm) -> Void

    private func bind(_ get: @escaping (TierForm) -> String, _ set: @escaping (inout TierForm, String) -> Void) -> Binding<String> {
        Binding(get: { get(tier) }, set: { v in var t = tier; set(&t, v); put(t) })
    }

    private func add() {
        let text = tier.featureDraft.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty else { return }
        var t = tier
        t.features.append(String(text.prefix(80)))
        t.featureDraft = ""
        put(t)
    }

    var body: some View {
        DorrTextField(value: bind({ $0.name }, { $0.name = String($1.prefix(60)) }), label: L("svc_f_level_name"), placeholder: levelLabel(tier.level), enabled: !readOnly)
        VStack(alignment: .leading, spacing: 4) {
            DorrTextField(
                value: bind({ $0.price }, { $0.price = priceText($1) }), label: L("svc_f_price"), placeholder: "0",
                trailing: AnyView(Text(verbatim: currency ?? "").font(.dorr(13, .bold)).foregroundStyle(wa.settingsMut).padding(.trailing, 4)),
                keyboard: .decimalPad, error: priceProblem != nil, enabled: !readOnly
            )
            if let priceProblem {
                Text(verbatim: priceProblem).font(.dorr(12, .semibold)).foregroundStyle(serviceTone("rejected", night: wa.night).ink)
            }
        }
        HStack(alignment: .top, spacing: 10) {
            Stepper(label: L("svc_f_days"), value: tier.days, range: 1...365, enabled: !readOnly) { v in var t = tier; t.days = v; put(t) }
                .frame(maxWidth: .infinity)
            Stepper(label: L("svc_f_revisions"), value: tier.revisions, range: 0...50, enabled: !readOnly) { v in var t = tier; t.revisions = v; put(t) }
                .frame(maxWidth: .infinity)
        }
        DorrTextField(value: bind({ $0.description }, { $0.description = String($1.prefix(500)) }), label: L("svc_f_level_desc"),
                      singleLine: false, minLines: 2, enabled: !readOnly)

        Text(verbatim: L("svc_f_features") + " (\(tier.features.count)/\(maxFeatures))").font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
        VStack(spacing: 6) {
            ForEach(Array(tier.features.enumerated()), id: \.offset) { i, f in
                HStack(spacing: 8) {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 14)).foregroundStyle(Color(hex: 0x16A34A))
                    Text(verbatim: f).font(.dorr(13)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                    if !readOnly {
                        Button {
                            var t = tier
                            t.features.remove(at: i)
                            put(t)
                        } label: {
                            Image(systemName: "xmark").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 18, height: 18)
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel(L("common_remove"))
                    }
                }
                .padding(.horizontal, 10)
                .padding(.vertical, 8)
                .background(wa.red.opacity(0.06), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
        }
        if !readOnly && tier.features.count < maxFeatures {
            let blank = tier.featureDraft.trimmingCharacters(in: .whitespaces).isEmpty
            DorrTextField(
                value: bind({ $0.featureDraft }, { $0.featureDraft = String($1.prefix(80)) }), placeholder: L("svc_f_feature_ph"),
                trailing: AnyView(
                    Button(action: add) {
                        Text(L("svc_f_add")).font(.dorr(13, .heavy)).foregroundStyle(blank ? wa.settingsMut : wa.red).padding(8)
                    }
                    .buttonStyle(.plain)
                    .disabled(blank)
                )
            )
        }
    }
}

private struct AddonFields: View {
    @Environment(\.wa) private var wa
    let a: AddonForm
    let currency: String?
    let readOnly: Bool
    let onChange: (AddonForm) -> Void
    let onRemove: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .center, spacing: 0) {
                DorrTextField(value: Binding(get: { a.title }, set: { v in var x = a; x.title = String(v.prefix(80)); onChange(x) }),
                              label: L("svc_addon_title"), placeholder: L("svc_addon_title_ph"), enabled: !readOnly)
                    .frame(maxWidth: .infinity)
                if !readOnly {
                    Button(action: onRemove) {
                        Image(systemName: "trash").font(.system(size: 17)).foregroundStyle(wa.settingsMut).frame(width: 22, height: 22)
                    }
                    .buttonStyle(.plain)
                    .padding(.leading, 8)
                    .padding(.top, 18)
                    .accessibilityLabel(L("common_remove"))
                }
            }
            HStack(alignment: .bottom, spacing: 10) {
                DorrTextField(
                    value: Binding(get: { a.price }, set: { v in var x = a; x.price = priceText(v); onChange(x) }), label: L("svc_f_price"), placeholder: "0",
                    trailing: AnyView(Text(verbatim: currency ?? "").font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut).padding(.trailing, 2)),
                    keyboard: .decimalPad, enabled: !readOnly
                )
                .frame(maxWidth: .infinity)
                Stepper(label: L("svc_addon_days"), value: a.days, range: 0...60, enabled: !readOnly) { v in var x = a; x.days = v; onChange(x) }
                    .frame(maxWidth: .infinity)
            }
        }
        .padding(12)
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.night ? wa.line : Color(hex: 0xE5E7EB), lineWidth: 1))
    }
}

private struct OutlineAction: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 15, weight: .bold))
                Text(verbatim: label).font(.dorr(14, .heavy))
            }
            .foregroundStyle(wa.red)
            .frame(maxWidth: .infinity, minHeight: 46)
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.red.opacity(0.45), lineWidth: 1.5))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

private struct ServiceThumb: View {
    @Environment(\.wa) private var wa
    /// An image already on the server (read signed in), or…
    let path: String?
    /// …one picked and not sent yet.
    let image: UIImage?
    let cover: Bool
    let enabled: Bool
    let onRemove: () -> Void

    var body: some View {
        ZStack {
            wa.red.opacity(0.10)
            if let image {
                Image(uiImage: image).resizable().scaledToFill()
            } else if path != nil {
                ProfileAuthedImage(path: path) { Color.clear }
            }
        }
        .frame(width: 104, height: 78)
        .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
        .overlay(alignment: .bottomLeading) {
            if cover {
                Text(L("svc_cover")).font(.dorr(10, .bold)).foregroundStyle(.white)
                    .padding(.horizontal, 6)
                    .padding(.vertical, 2)
                    .background(Color.black.opacity(0.55), in: RoundedRectangle(cornerRadius: 6, style: .continuous))
                    .padding(5)
            }
        }
        .overlay(alignment: .topTrailing) {
            if enabled {
                Button(action: onRemove) {
                    Image(systemName: "xmark").font(.system(size: 11, weight: .bold)).foregroundStyle(.white)
                        .frame(width: 22, height: 22)
                        .background(Color.black.opacity(0.55), in: Circle())
                }
                .buttonStyle(.plain)
                .padding(4)
                .accessibilityLabel(L("common_remove"))
            }
        }
    }
}

private struct AddTile: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String

    var body: some View {
        VStack(spacing: 4) {
            Image(systemName: icon).font(.system(size: 19, weight: .semibold)).foregroundStyle(wa.red)
            Text(verbatim: label).font(.dorr(11, .bold)).foregroundStyle(wa.red).lineLimit(1)
        }
        .frame(width: 104, height: 78)
        .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.red.opacity(0.35), lineWidth: 1.5))
        .contentShape(Rectangle())
    }
}

/// The card a client will see in the list, drawn from what is typed now.
private struct ServicePreview: View {
    @Environment(\.wa) private var wa
    let form: ServiceForm
    let coverPath: String?
    let coverImage: UIImage?
    let prices: [String: Int?]
    let decimals: Int
    let currency: String?

    var body: some View {
        let basic = form.tiers[0]
        let blankTitle = form.title.trimmingCharacters(in: .whitespaces).isEmpty
        VStack(alignment: .leading, spacing: 8) {
            Text(L("svc_step_preview")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
            HStack(spacing: 12) {
                ZStack {
                    wa.red.opacity(0.10)
                    if coverPath != nil {
                        ProfileAuthedImage(path: coverPath) { Color.clear }
                    } else if let coverImage {
                        Image(uiImage: coverImage).resizable().scaledToFill()
                    } else {
                        Image(systemName: "photo").font(.system(size: 20)).foregroundStyle(wa.red.opacity(0.6))
                    }
                }
                .frame(width: 78, height: 78)
                .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 4) {
                    Text(verbatim: blankTitle ? L("svc_f_title_ph") : form.title).font(.dorr(14, .heavy))
                        .foregroundStyle(blankTitle ? wa.settingsMut : wa.settingsInk).lineLimit(2)
                    Text(L("svc_preview_by")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    HStack(spacing: 0) {
                        let price = (prices["basic"] ?? nil).map { L("svc_from", svcMoney($0, decimals, currency)) } ?? "—"
                        Text(verbatim: price).font(.dorr(13, .heavy)).foregroundStyle(wa.red).frame(maxWidth: .infinity, alignment: .leading)
                        Text(verbatim: daysLabel(basic.days)).font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(10)
            .settingsSurface(18)
        }
        .frame(maxWidth: 520, alignment: .leading)
        .frame(maxWidth: .infinity)
    }
}

private struct ManageCard: View {
    @Environment(\.wa) private var wa
    let s: ServiceDto
    let busy: Bool
    let onPause: () -> Void
    let onResume: () -> Void
    let onDelete: () -> Void

    var body: some View {
        let can = s.can
        if !(can?.pause ?? false) && !(can?.resume ?? false) && s.status == "suspended" {
            EmptyView()
        } else {
            VStack(spacing: 0) {
                if can?.pause ?? false { ManageRow(icon: "pause.circle.fill", label: L("svc_pause"), hint: nil, enabled: !busy, danger: false, action: onPause) }
                if can?.resume ?? false { ManageRow(icon: "play.circle.fill", label: L("svc_resume"), hint: nil, enabled: !busy, danger: false, action: onResume) }
                let canDelete = can?.delete ?? true
                ManageRow(icon: "trash", label: L("svc_delete"), hint: canDelete ? nil : L("svc_delete_blocked"), enabled: !busy && canDelete, danger: true, action: onDelete)
            }
            .padding(6)
            .frame(maxWidth: 520)
            .settingsSurface(20)
            .frame(maxWidth: .infinity)
        }
    }
}

private struct ManageRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let hint: String?
    let enabled: Bool
    let danger: Bool
    let action: () -> Void

    var body: some View {
        let ink = danger ? serviceTone("rejected", night: wa.night).ink : wa.settingsInk
        Button(action: action) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 19)).foregroundStyle(enabled ? ink : wa.settingsMut).frame(width: 22, height: 22)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: label).font(.dorr(14, .bold)).foregroundStyle(enabled ? ink : wa.settingsMut)
                    if let hint { Text(verbatim: hint).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(12)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}
