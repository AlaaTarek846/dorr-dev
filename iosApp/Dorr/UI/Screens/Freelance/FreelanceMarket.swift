import SwiftUI

// androidApp `freelance/FreelanceMarket.kt` — FL-L04, ready-made services for the client: the market (search, specialties, sort),
// one service (its images, the freelancer, the packages side by side, extras to tick, the freelancer's question, a live total with
// the fee and a sticky order bar), the order confirmation (nothing is paid before the freelancer accepts), my orders and one order
// with where it stands; FL-L05 monthly subscriptions; FL-L10 waiting in line while the freelancer is full. Each page reads when it
// opens, when the connection returns and when a freelance notice arrives (`reconnectTick`) — never on a timer.

private let mkLevels = ["basic", "standard", "premium"]
private let mkGreen = Color(hex: 0x16A34A)
private let mkAmber = Color(hex: 0xD97706)
private let mkRed = Color(hex: 0xDC2626)
private let mkSlate = Color(hex: 0x64748B)

/// "1,250.00 SAR" in the currency's own decimals; digits stay Latin and left-to-right inside Arabic text.
func mkMoney(_ minor: Int?, _ decimals: Int?, _ currency: String?) -> String {
    let d = decimals ?? 2
    let value = Decimal(minor ?? 0) / pow(Decimal(10), d)
    let f = NumberFormatter()
    f.locale = Locale(identifier: "en_US")
    f.numberStyle = .decimal
    f.minimumFractionDigits = d
    f.maximumFractionDigits = d
    let text = f.string(from: value as NSDecimalNumber) ?? "0"
    return "\u{200E}" + ((currency ?? "").isEmpty ? text : "\(text) \(currency!)")
}

private func mkLevelName(_ level: String?) -> String {
    switch level {
    case "standard": return L("mk_level_standard")
    case "premium": return L("mk_level_premium")
    default: return L("mk_level_basic")
    }
}

private func mkDays(_ n: Int) -> String { n == 1 ? L("mk_day_one") : L("mk_days", n) }

/// "Fri 30 Oct" (with the time when asked) in the phone's zone.
private func mkDay(_ iso: String?, time: Bool = false) -> String {
    guard let d = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.dateFormat = time ? "EEE d MMM, HH:mm" : "EEE d MMM"
    return f.string(from: d)
}

private struct MkPill: View {
    @Environment(\.wa) private var wa
    let text: String
    let color: Color
    var solid = false

    var body: some View {
        HStack(spacing: 6) {
            Circle().fill(solid ? Color.white : color).frame(width: 6, height: 6)
            Text(verbatim: text).font(.dorr(11.5, .bold)).foregroundStyle(solid ? .white : color).lineLimit(1)
        }
        .padding(.horizontal, 10).padding(.vertical, 4)
        .background(solid ? color : color.opacity(wa.night ? 0.22 : 0.12), in: Capsule())
    }
}

struct MkAvatar: View {
    @Environment(\.wa) private var wa
    let url: String?
    let name: String?
    var size: CGFloat = 34

    var body: some View {
        ZStack {
            wa.accent.opacity(0.14)
            if let u = ApiClient.mediaUrl(url) {
                AsyncImage(url: u) { $0.resizable().scaledToFill() } placeholder: { Color.clear }
            } else {
                Text(verbatim: String((name ?? "").trimmingCharacters(in: .whitespaces).prefix(1)).uppercased().ifEmpty("?"))
                    .font(.dorr(size / 2.4, .heavy)).foregroundStyle(wa.accent)
            }
        }
        .frame(width: size, height: size)
        .clipShape(Circle())
    }
}

private extension String {
    func ifEmpty(_ other: String) -> String { isEmpty ? other : self }
}

private struct MkSection<Content: View>: View {
    @Environment(\.wa) private var wa
    var title: String?
    var icon: String?
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            if let title {
                HStack(spacing: 8) {
                    if let icon { Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.accent) }
                    Text(verbatim: title).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                }
            }
            content()
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(16)
        .settingsSurface(20)
    }
}

private struct MkLine: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String
    var strong = false

    var body: some View {
        HStack {
            Text(verbatim: label).font(.dorr(strong ? 15 : 13, strong ? .heavy : .medium)).foregroundStyle(strong ? wa.settingsInk : wa.settingsMut)
            Spacer(minLength: 8)
            Text(verbatim: value).font(.dorr(strong ? 17 : 13, strong ? .heavy : .semibold)).foregroundStyle(strong ? wa.accent : wa.settingsInk)
        }
    }
}

private struct MkFact: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String

    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.accent)
            Text(verbatim: text).font(.dorr(12.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 10).padding(.vertical, 9)
        .background(wa.night ? wa.darkWell : wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
    }
}

private struct MkDivider: View {
    @Environment(\.wa) private var wa
    var body: some View { Rectangle().fill(wa.night ? wa.line : Color(hex: 0xF0F1F4)).frame(height: 1) }
}

// MARK: - badges (FreelanceKit.kt)

/// FL-L01 phase 4: the badge of the plan a freelancer holds, by their name. `compact` is the small one for cards and lists; the
/// full one is for a profile. Nothing is drawn when their plan has no badge.
struct PlanBadge: View {
    @Environment(\.wa) private var wa
    let badge: FreelancePlanBadgeDto?
    var compact = true

    var body: some View {
        if let badge {
            let plan = badge.plan ?? ""
            let colors: [Color] = plan == "pro" ? [Color(hex: 0xF59E0B), Color(hex: 0xD97706)]
                : plan == "plus" ? [Color(hex: 0x818CF8), Color(hex: 0x6366F1)] : [wa.accent, wa.accent.opacity(0.8)]
            let label = (badge.name?.isEmpty == false ? badge.name : nil) ?? plan.capitalized
            HStack(spacing: compact ? 3 : 5) {
                Image(systemName: "rosette").font(.system(size: compact ? 9 : 12, weight: .bold))
                Text(verbatim: label).font(.dorr(compact ? 10 : 12, .heavy)).lineLimit(1)
            }
            .foregroundStyle(.white)
            .padding(.horizontal, compact ? 7 : 11).padding(.vertical, compact ? 2 : 4)
            .background(LinearGradient(colors: colors, startPoint: .topLeading, endPoint: .bottomTrailing), in: Capsule())
            .accessibilityElement(children: .ignore)
            .accessibilityLabel(L("fl_plan_badge_desc", label))
        }
    }
}

/// FL-L08: the skills a freelancer passed the test of, as small verified chips (at most `max`, then «+n»). The full one is for a
/// profile, where the score shows too.
struct SkillBadges: View {
    let badges: [FreelanceSkillBadgeDto]
    var max = 2
    var compact = true

    var body: some View {
        if !badges.isEmpty {
            let tone = Color(hex: 0x0D9488)
            FlowLayout(spacing: 5, lineSpacing: 4) {
                ForEach(Array(badges.prefix(max).enumerated()), id: \.offset) { _, b in
                    let label = b.name ?? ""
                    HStack(spacing: 3) {
                        Image(systemName: "checkmark.seal.fill").font(.system(size: compact ? 9 : 12))
                        Text(verbatim: compact ? label : "\(label) · \(b.scorePercent ?? 0)%").font(.dorr(compact ? 10 : 12, .heavy)).lineLimit(1)
                    }
                    .foregroundStyle(tone)
                    .padding(.horizontal, compact ? 7 : 10).padding(.vertical, compact ? 2 : 4)
                    .background(tone.opacity(0.12), in: Capsule())
                    .accessibilityElement(children: .ignore)
                    .accessibilityLabel(L("fl_skill_badge_desc", label))
                }
                if badges.count > max {
                    Text(verbatim: "+\(badges.count - max)").font(.dorr(compact ? 10 : 12, .heavy)).foregroundStyle(tone).padding(.vertical, 2)
                }
            }
        }
    }
}

/// A quiet line under the filters that says why a list is in the order it is.
struct FlHint: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        HStack(alignment: .top, spacing: 6) {
            Image(systemName: "info.circle.fill").font(.system(size: 12)).foregroundStyle(wa.settingsMut).padding(.top, 1)
            Text(verbatim: text).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineSpacing(2).frame(maxWidth: .infinity, alignment: .leading)
        }
    }
}

// MARK: - the entry on «My projects»

/// The door to the market on the projects list: a soft gradient card, and «My orders» beside the button.
struct MarketBanner: View {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    let onBrowse: () -> Void
    let onOrders: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 12) {
                Image(systemName: "storefront.fill").font(.system(size: 20)).foregroundStyle(.white)
                    .frame(width: 44, height: 44).background(Color.white.opacity(0.2), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("mk_banner_title")).font(.dorr(16, .heavy)).foregroundStyle(.white)
                    Text(L("mk_banner_body")).font(.dorr(12)).foregroundStyle(.white.opacity(0.9)).lineSpacing(2)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            HStack(spacing: 8) {
                Button(action: onBrowse) {
                    HStack(spacing: 6) {
                        Text(L("mk_banner_cta")).font(.dorr(14, .heavy))
                        Image(systemName: "arrow.forward").font(.system(size: 13, weight: .bold))
                    }
                    .foregroundStyle(wa.accent)
                    .frame(maxWidth: .infinity).padding(.vertical, 11)
                    .background(Color.white, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                .buttonStyle(.plain)
                Button(action: onOrders) {
                    HStack(spacing: 6) {
                        Image(systemName: "doc.text.fill").font(.system(size: 13))
                        Text(L("mk_my_orders")).font(.dorr(13, .bold))
                    }
                    .foregroundStyle(.white)
                    .padding(.horizontal, 14).padding(.vertical, 11)
                    .background(Color.white.opacity(0.2), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(16)
        .background(LinearGradient(colors: [wa.accent, wa.accent.opacity(0.75)], startPoint: .topLeading, endPoint: .bottomTrailing),
                    in: RoundedRectangle(cornerRadius: 22, style: .continuous))
    }
}

// MARK: - the market

@MainActor
struct MarketPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpen: (String) -> Void
    let onOrders: () -> Void
    var onConsultations: () -> Void = {}
    @State private var typed = ""
    @State private var search = ""
    @State private var specialty: Int?
    @State private var sort = "recommended"
    @State private var fast = false
    @State private var specialties: [FreelanceSpecialtyNodeDto] = []
    @State private var closed = false
    @StateObject private var paged = PagedRows<MarketServiceCardDto>(key: { $0.uuid })

    private var listKey: String { "\(search)|\(specialty ?? 0)|\(sort)|\(fast)" }

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: L("mk_title"), onBack: onBack)
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 12) {
                    HStack(spacing: 6) {
                        DorrTextArea(value: Binding(get: { typed }, set: { typed = String($0.prefix(80)) }), placeholder: L("mk_search"), icon: "magnifyingglass", singleLine: true)
                        if !typed.isEmpty { RemoveButton { typed = "" } }
                    }
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 8) {
                            // FL-L07: consultations are a list of their own, next to the services.
                            FlChip(text: L("cs_market_title"), selected: false, icon: "video.bubble.left.fill", action: onConsultations)
                            FlChip(text: L("mk_my_orders"), selected: false, icon: "doc.text.fill", action: onOrders)
                            FlChip(text: L("mk_fast"), selected: fast, icon: "bolt.fill") { fast.toggle() }
                            ForEach([("recommended", "mk_sort_recommended"), ("newest", "mk_sort_newest"), ("price_low", "mk_sort_price_low"),
                                     ("price_high", "mk_sort_price_high"), ("popular", "mk_sort_popular")], id: \.0) { value, label in
                                FlChip(text: L(label), selected: sort == value) { sort = value }
                            }
                        }
                    }
                    if !specialties.isEmpty {
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 8) {
                                FlChip(text: L("mk_all"), selected: specialty == nil) { specialty = nil }
                                ForEach(specialties, id: \.id) { s in
                                    FlChip(text: s.name ?? "", selected: specialty == s.id) { specialty = specialty == s.id ? nil : s.id }
                                }
                            }
                        }
                    }
                    if sort == "recommended" && !closed { FlHint(text: L("mk_sort_recommended_hint")).padding(.horizontal, 2) }
                    if closed {
                        WaEmpty(icon: "storefront.fill", tone: .blue, title: L("mk_title"), text: L("mk_closed"))
                    } else if !paged.loaded {
                        ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 230) }
                    } else if paged.rows.isEmpty && paged.error == nil {
                        WaEmpty(icon: "magnifyingglass", tone: .blue, title: L("mk_empty_title"), text: L("mk_empty_body"))
                    } else {
                        ForEach(Array(paged.rows.enumerated()), id: \.element.uuid) { i, s in
                            ServiceTile(s: s) { onOpen(s.uuid) }.onAppear { paged.appeared(i) }
                        }
                    }
                    PagedSpinner(paged: paged)
                    if !closed { PagedError(paged: paged) }
                }
                .padding(.horizontal, 16).padding(.vertical, 6)
            }
            .scrollDismissesKeyboard(.interactively)
        }
        // The search goes out once typing pauses.
        .task(id: typed) {
            try? await Task.sleep(nanoseconds: 400_000_000)
            guard !Task.isCancelled else { return }
            search = typed.trimmingCharacters(in: .whitespaces)
        }
        .task { specialties = (try? await FreelanceApi.specialties().data) ?? [] }
        .task(id: listKey) {
            let (q, sp, so, f) = (search, specialty, sort, fast)
            paged.reset { page in
                do {
                    return try await FreelanceServicesApi.browse(search: q.isEmpty ? nil : q, specialtyId: sp, maxDays: f ? 3 : nil, sort: so, page: page)
                } catch {
                    if error.apiFailure.httpStatus == 403 { await MainActor.run { closed = true } }
                    throw error
                }
            }
        }
        .task(id: "\(listKey)|\(network.reconnectTick)") { await paged.reload() }
    }
}

private struct ServiceTile: View {
    @Environment(\.wa) private var wa
    let s: MarketServiceCardDto
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 0) {
                ZStack {
                    wa.accent.opacity(0.10)
                    if let cover = ApiClient.mediaUrl(s.cover) {
                        AsyncImage(url: cover) { $0.resizable().scaledToFill() } placeholder: { Color.clear }
                    } else {
                        Image(systemName: "photo").font(.system(size: 36)).foregroundStyle(wa.accent.opacity(0.55))
                    }
                }
                .frame(maxWidth: .infinity)
                .aspectRatio(16 / 9, contentMode: .fit)
                .clipped()
                .overlay(alignment: .topLeading) {
                    if let name = s.specialty?.name {
                        Text(verbatim: name).font(.dorr(11, .bold)).foregroundStyle(.white)
                            .padding(.horizontal, 10).padding(.vertical, 4).background(Color.black.opacity(0.45), in: Capsule()).padding(10)
                    }
                }
                .overlay(alignment: .bottomLeading) {
                    // FL-L05: sold by the month.
                    if s.billing == "monthly" {
                        HStack(spacing: 4) {
                            Image(systemName: "calendar.badge.clock").font(.system(size: 11))
                            Text(L("mks_monthly")).font(.dorr(11, .bold))
                        }
                        .foregroundStyle(.white).padding(.horizontal, 10).padding(.vertical, 4).background(wa.accent, in: Capsule()).padding(10)
                    }
                }
                .overlay(alignment: .topTrailing) {
                    // FL-L10: the freelancer is full right now — ordering waits for a free place.
                    if s.atCapacity == true {
                        Text(L("mk_full_pill")).font(.dorr(11, .bold)).foregroundStyle(.white)
                            .padding(.horizontal, 10).padding(.vertical, 4).background(mkAmber, in: Capsule()).padding(10)
                    }
                }
                VStack(alignment: .leading, spacing: 10) {
                    HStack(spacing: 8) {
                        MkAvatar(url: s.freelancer?.avatarUrl, name: s.freelancer?.name, size: 28)
                        HStack(spacing: 6) {
                            Text(verbatim: s.freelancer?.name ?? "").font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut).lineLimit(1)
                            PlanBadge(badge: s.freelancer?.badge)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        if (s.completedOrders ?? 0) > 0 { Text(L("mk_done_n", s.completedOrders ?? 0)).font(.dorr(11, .bold)).foregroundStyle(mkGreen) }
                    }
                    Text(verbatim: s.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2).lineSpacing(3).multilineTextAlignment(.leading)
                    MkDivider()
                    HStack(spacing: 4) {
                        Image(systemName: "clock").font(.system(size: 12)).foregroundStyle(wa.settingsMut)
                        Text(verbatim: s.deliveryDays.map(mkDays) ?? "").font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                        if let start = s.startingPriceMinor {
                            let price = mkMoney(start, s.decimals, s.currency)
                            Text(verbatim: s.billing == "monthly" ? L("mks_from_month", price) : L("mk_from", price)).font(.dorr(15, .heavy)).foregroundStyle(wa.accent)
                        }
                    }
                }
                .padding(14)
            }
            .settingsSurface(22)
            .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - one service

@MainActor
struct ServicePage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let uuid: String
    let onBack: () -> Void
    let onOrdered: (String) -> Void
    @State private var service: MarketServiceDto?
    @State private var error: String?
    @State private var level = "basic"
    @State private var picked: [Int] = []
    @State private var note = ""
    @State private var quote: MarketQuoteDto?
    @State private var confirming = false
    @State private var busy = false
    @State private var expanded = false
    @State private var page = 0
    // One id per order attempt: a retry after a lost answer is the same order, never a second one.
    @State private var requestId = UUID().uuidString

    private func place() {
        guard let s = service else { return }
        busy = true
        let n = note.trimmingCharacters(in: .whitespacesAndNewlines)
        Task {
            do {
                let order = try await FreelanceServicesApi.order(s.uuid, level: level, addons: picked, note: n.isEmpty ? nil : n, requestId: requestId).data
                busy = false
                confirming = false
                requestId = UUID().uuidString
                AppToast.show(L("mk_ordered_toast"))
                if let order { onOrdered(order.uuid) }
            } catch {
                busy = false
                confirming = false
                self.error = error.apiFailure.message ?? L("fl_error_generic")
            }
        }
    }

    // FL-L10: join or leave the line; the answer is where I stand now.
    private func waitlist(_ join: Bool) {
        guard let current = service else { return }
        busy = true
        Task {
            do {
                let cap = try await (join ? FreelanceServicesApi.joinWaitlist(current.uuid) : FreelanceServicesApi.leaveWaitlist(current.uuid)).data
                if let cap { service?.capacity = cap }
                error = nil
            } catch {
                self.error = error.apiFailure.message ?? L("fl_error_generic")
            }
            busy = false
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: service?.title ?? L("mk_title"), onBack: onBack)
            if let s = service, let tier = (s.tiers ?? []).first(where: { $0.level == level }) ?? s.tiers?.first {
                ScrollView { content(s, tier).padding(.horizontal, 16).padding(.bottom, 16) }.scrollDismissesKeyboard(.interactively)
                orderBar(s, tier)
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: network.reconnectTick) {
            do {
                let s = try await FreelanceServicesApi.service(uuid).data
                service = s
                if let s, !(s.tiers ?? []).contains(where: { $0.level == level }) { level = s.tiers?.first?.level ?? "basic" }
                error = nil
            } catch {
                self.error = error.apiFailure.message ?? L("fl_error_generic")
            }
        }
        // The total with the fee follows the package and the extras.
        .task(id: "\(service?.uuid ?? "")|\(level)|\(picked)") {
            guard service != nil else { return }
            try? await Task.sleep(nanoseconds: 200_000_000)
            guard !Task.isCancelled else { return }
            if let q = try? await FreelanceServicesApi.quote(uuid, level: level, addons: picked).data { quote = q }
        }
        .dorrDialog(isPresented: Binding(get: { confirming }, set: { if !$0 && !busy { confirming = false } }), bottom: true) {
            if let s = service { confirmSheet(s) }
        }
    }

    @ViewBuilder
    private func content(_ s: MarketServiceDto, _ tier: MarketTierDto) -> some View {
        let images = s.images ?? []
        VStack(alignment: .leading, spacing: 14) {
            // The images, swiped.
            if !images.isEmpty {
                TabView(selection: $page) {
                    ForEach(Array(images.enumerated()), id: \.offset) { i, img in
                        AsyncImage(url: ApiClient.mediaUrl(img.url)) { $0.resizable().scaledToFill() } placeholder: { wa.accent.opacity(0.08) }
                            .tag(i).clipped()
                    }
                }
                .tabViewStyle(.page(indexDisplayMode: images.count > 1 ? .always : .never))
                .aspectRatio(16 / 10, contentMode: .fit)
                .background(wa.accent.opacity(0.08))
                .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
            }
            // The title and what it stands on.
            VStack(alignment: .leading, spacing: 6) {
                if let name = s.specialty?.name { MkPill(text: name, color: wa.accent) }
                Text(verbatim: s.title ?? "").font(.dorr(20, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                if (s.completedOrders ?? 0) > 0 { Text(L("mk_done_n", s.completedOrders ?? 0)).font(.dorr(12, .bold)).foregroundStyle(mkGreen) }
            }
            if let f = s.freelancer { MkFreelancerCard(f: f) }
            MkSection(title: L("mk_about"), icon: "text.alignleft") {
                Text(verbatim: s.description ?? "").font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(6).lineLimit(expanded ? nil : 5)
                if (s.description ?? "").count > 240 {
                    Button { withAnimation { expanded.toggle() } } label: { Text(L(expanded ? "mk_less" : "mk_more")).font(.dorr(13, .bold)).foregroundStyle(wa.accent) }
                        .buttonStyle(.plain)
                }
            }
            // The packages.
            MkSection(title: L("mk_choose_level"), icon: "bag.fill") {
                if (s.tiers ?? []).count > 1 { packageTabs(s) }
                HStack(alignment: .top) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: (tier.name?.isEmpty == false ? tier.name : nil) ?? mkLevelName(tier.level)).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
                        if let d = tier.description, !d.isEmpty { Text(verbatim: d).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3) }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    VStack(alignment: .trailing, spacing: 0) {
                        Text(verbatim: mkMoney(tier.priceMinor, s.decimals, s.currency)).font(.dorr(20, .black)).foregroundStyle(wa.accent)
                        if s.billing == "monthly" { Text(L("mks_per_month")).font(.dorr(11, .bold)).foregroundStyle(wa.settingsMut) }
                    }
                }
                HStack(spacing: 8) {
                    MkFact(icon: "clock", text: mkDays(tier.deliveryDays ?? 1))
                    MkFact(icon: "arrow.counterclockwise", text: (tier.revisions ?? 0) > 0 ? L("mk_revisions", tier.revisions ?? 0) : L("mk_no_revisions"))
                }
                if let features = tier.features, !features.isEmpty {
                    Text(L("mk_includes")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut).padding(.top, 4)
                    ForEach(features, id: \.self) { f in
                        HStack(spacing: 8) {
                            Image(systemName: "checkmark.circle.fill").font(.system(size: 15)).foregroundStyle(mkGreen)
                            Text(verbatim: f).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                        }
                    }
                }
            }
            // FL-L05: how a monthly subscription runs, said before subscribing.
            if let t = s.subscription { subscriptionTerms(t) }
            // Extras to tick.
            if let addons = s.addons, !addons.isEmpty {
                MkSection(title: L("mk_extras"), icon: "plus.circle") {
                    ForEach(addons, id: \.id) { a in
                        let on = picked.contains(a.id)
                        Button {
                            if on { picked.removeAll { $0 == a.id } } else { picked.append(a.id) }
                        } label: {
                            HStack(spacing: 10) {
                                Image(systemName: on ? "checkmark.square.fill" : "square").font(.system(size: 20)).foregroundStyle(on ? wa.accent : wa.settingsMut)
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(verbatim: a.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                                    if (a.extraDays ?? 0) > 0 { Text(verbatim: L("mk_extra_days", mkDays(a.extraDays ?? 0))).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                                }
                                .frame(maxWidth: .infinity, alignment: .leading)
                                Text(verbatim: "+" + mkMoney(a.priceMinor, s.decimals, s.currency)).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk)
                            }
                            .padding(12)
                            .background(on ? wa.accent.opacity(0.06) : .clear, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(on ? wa.accent : (wa.night ? wa.line : Color(hex: 0xE8EAEE)), lineWidth: 1.5))
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
            // The freelancer's question, or a note.
            MkSection(title: L(s.requirements != nil ? "mk_question" : "mk_note"), icon: "questionmark.bubble.fill") {
                if let q = s.requirements {
                    Text(verbatim: q).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                        .frame(maxWidth: .infinity, alignment: .leading).padding(12)
                        .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                DorrTextArea(value: Binding(get: { note }, set: { note = String($0.prefix(3000)) }), label: s.requirements != nil ? L("mk_answer") : nil,
                             placeholder: L("mk_note_hint"), minHeight: 90)
            }
            // What it comes to.
            if let q = quote {
                MkSection(title: L("mk_total"), icon: "banknote") {
                    MkLine(label: L("mk_price"), value: mkMoney(q.priceMinor, q.decimals, q.currency))
                    if (q.clientFeeMinor ?? 0) > 0 { MkLine(label: L("mk_fee"), value: mkMoney(q.clientFeeMinor, q.decimals, q.currency)) }
                    MkLine(label: L("mk_delivery"), value: mkDays(q.deliveryDays ?? 0))
                    MkDivider()
                    MkLine(label: L("mk_total"), value: mkMoney(q.totalMinor, q.decimals, q.currency), strong: true)
                }
            }
            WaitlistCard(cap: s.capacity ?? MarketCapacityDto(), busy: busy) { waitlist(false) }
            if let error { FlNotice(text: error, icon: "xmark", color: mkRed) }
        }
    }

    private func packageTabs(_ s: MarketServiceDto) -> some View {
        HStack(spacing: 4) {
            ForEach(mkLevels.compactMap { l in (s.tiers ?? []).first { $0.level == l } }, id: \.level) { t in
                let on = t.level == level
                Button { level = t.level ?? "basic" } label: {
                    VStack(spacing: 0) {
                        Text(verbatim: mkLevelName(t.level)).font(.dorr(13, .heavy)).foregroundStyle(on ? .white : wa.settingsInk).lineLimit(1)
                        Text(verbatim: mkMoney(t.priceMinor, s.decimals, nil)).font(.dorr(11, .semibold)).foregroundStyle(on ? .white.opacity(0.85) : wa.settingsMut).lineLimit(1)
                    }
                    .frame(maxWidth: .infinity).padding(.vertical, 8)
                    .background(on ? wa.accent : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
        }
        .padding(4)
        .background(wa.night ? wa.darkWell : wa.accent.opacity(0.08), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
    }

    /// How a monthly subscription runs, on the service page before subscribing.
    private func subscriptionTerms(_ t: MarketSubscriptionTermsDto) -> some View {
        MkSection(title: L("mks_terms_title"), icon: "calendar.badge.clock") {
            ForEach([L("mks_term_month", t.periodDays ?? 30), L("mks_term_confirm", t.renewWindowDays ?? 3), L("mks_term_notice", t.noticeDays ?? 7), L("mks_term_price")], id: \.self) { line in
                HStack(alignment: .top, spacing: 8) {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 14)).foregroundStyle(mkGreen).padding(.top, 2)
                    Text(verbatim: line).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                }
            }
        }
    }

    /// The sticky order bar.
    private func orderBar(_ s: MarketServiceDto, _ tier: MarketTierDto) -> some View {
        let cap = s.capacity ?? MarketCapacityDto()
        let mine = cap.mine
        return HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 0) {
                Text(L("mk_total")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                Text(verbatim: quote.map { mkMoney($0.totalMinor, $0.decimals, $0.currency) } ?? mkMoney(tier.priceMinor, s.decimals, s.currency))
                    .font(.dorr(18, .black)).foregroundStyle(wa.settingsInk).lineLimit(1).minimumScaleFactor(0.7)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            Group {
                if cap.full == true && mine == nil {
                    // Full, and I am not in line yet: wait instead of ordering.
                    SettingsPrimaryButton(text: L("mk_wait_join"), loading: busy) { waitlist(true) }
                } else if cap.full == true && mine?.status == "waiting" {
                    SettingsPrimaryButton(text: L("mk_wait_position", mine?.position ?? 0), enabled: false) {}
                } else {
                    SettingsPrimaryButton(text: L(s.billing == "monthly" ? "mks_subscribe" : "mk_order_now")) {
                        if s.requirements != nil && note.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                            error = L("mk_answer_needed")
                        } else {
                            error = nil
                            confirming = true
                        }
                    }
                }
            }
            .frame(maxWidth: .infinity)
            .layoutPriority(1.3)
        }
        .padding(.horizontal, 16).padding(.vertical, 12)
        .background(wa.night ? wa.darkCard : .white)
    }

    private func confirmSheet(_ s: MarketServiceDto) -> some View {
        let tier = (s.tiers ?? []).first { $0.level == level } ?? s.tiers?.first
        let monthly = s.billing == "monthly"
        return FlSheet(icon: "checkmark.seal.fill", title: L(monthly ? "mks_confirm_title" : "mk_confirm_title"),
                       text: L(monthly ? "mks_confirm_body" : "mk_confirm_body", s.answerHours ?? 48)) {
            VStack(alignment: .leading, spacing: 8) {
                Text(verbatim: s.title ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                MkLine(label: mkLevelName(tier?.level), value: mkMoney(tier?.priceMinor, s.decimals, s.currency))
                ForEach((s.addons ?? []).filter { picked.contains($0.id) }, id: \.id) { a in
                    MkLine(label: "+ " + (a.title ?? ""), value: mkMoney(a.priceMinor, s.decimals, s.currency))
                }
                if let q = quote {
                    if (q.clientFeeMinor ?? 0) > 0 { MkLine(label: L("mk_fee"), value: mkMoney(q.clientFeeMinor, q.decimals, q.currency)) }
                    MkLine(label: L("mk_delivery"), value: mkDays(q.deliveryDays ?? 0))
                    MkLine(label: L("mk_total"), value: mkMoney(q.totalMinor, q.decimals, q.currency), strong: true)
                }
            }
            .padding(14)
            .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            FlNotice(text: L("mk_confirm_safe"), icon: "lock.fill", color: mkGreen)
            SettingsPrimaryButton(text: L("mk_confirm_cta"), loading: busy, action: place)
        }
    }
}

/// FL-L10: where I stand with a freelancer who caps their running projects — full (and how many wait), my place in line, or the
/// place kept for me with the time it is kept until. Nothing is drawn while there is room and I am not in line.
private struct WaitlistCard: View {
    @Environment(\.wa) private var wa
    let cap: MarketCapacityDto
    let busy: Bool
    let onLeave: () -> Void

    var body: some View {
        if let mine = cap.mine, mine.status == "offered" {
            FlNotice(text: L("mk_wait_turn", mkDay(mine.holdUntil, time: true)), icon: "checkmark.seal.fill", color: mkGreen)
        } else if let mine = cap.mine, mine.status == "waiting" {
            VStack(spacing: 10) {
                HStack(spacing: 12) {
                    Text(verbatim: mine.position.map(String.init) ?? "-").font(.dorr(20, .black)).foregroundStyle(.white).frame(width: 46, height: 46).background(mkAmber, in: Circle())
                    VStack(alignment: .leading, spacing: 2) {
                        Text(L("mk_wait_in_line")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                        Text(L("mk_wait_in_line_body")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
                FlAction(text: L("mk_wait_leave"), icon: "xmark", tone: .danger, loading: busy, action: onLeave)
            }
            .padding(14)
            .background(mkAmber.opacity(0.10), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        } else if cap.full == true {
            FlNotice(text: (cap.waiting ?? 0) > 0 ? L("mk_wait_full_n", cap.waiting ?? 0) : L("mk_wait_full"), icon: "hourglass", color: mkAmber)
        }
    }
}

private struct MkFreelancerCard: View {
    @Environment(\.wa) private var wa
    let f: MarketFreelancerDto

    var body: some View {
        HStack(spacing: 12) {
            MkAvatar(url: f.avatarUrl, name: f.name, size: 48)
            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: 4) {
                    Text(verbatim: f.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Image(systemName: "checkmark.seal.fill").font(.system(size: 14)).foregroundStyle(Color(hex: 0x0EA5E9))
                    PlanBadge(badge: f.badge)
                }
                if let h = f.headline, !h.isEmpty { Text(verbatim: h).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                SkillBadges(badges: f.skillBadges ?? [])
                HStack(spacing: 6) {
                    if let r = f.rating {
                        Image(systemName: "star.fill").font(.system(size: 12)).foregroundStyle(Color(hex: 0xF59E0B))
                        Text(verbatim: String(format: "%.1f", r)).font(.dorr(12, .heavy)).foregroundStyle(wa.settingsInk)
                        Text(L("mk_reviews", f.reviewsCount ?? 0)).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    } else {
                        Text(L("mk_rating_none")).font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut)
                    }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            VStack(alignment: .trailing, spacing: 3) {
                Text(L("mk_projects", f.completedProjects ?? 0)).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                if let on = f.onTimeRate { Text(L("mk_on_time", on)).font(.dorr(11, .bold)).foregroundStyle(mkGreen) }
            }
        }
        .padding(14)
        .settingsSurface(20)
    }
}

// MARK: - my orders

private let orderTabs = ["awaiting", "active", "completed", "ended"]

private func orderStatus(_ status: String?) -> (String, Color) {
    switch status {
    case "active": return (L("mko_status_active"), Color(hex: 0x0EA5E9))
    case "completed": return (L("mko_status_completed"), mkGreen)
    case "declined": return (L("mko_status_declined"), mkRed)
    case "expired": return (L("mko_status_expired"), mkSlate)
    case "cancelled": return (L("mko_status_cancelled"), mkSlate)
    default: return (L("mko_status_awaiting"), mkAmber)
    }
}

private func leftText(_ o: MarketOrderDto) -> String? {
    guard o.state == "awaiting", let at = parseInstant(o.answerBy) else { return nil }
    let left = at.timeIntervalSinceNow
    if left <= 0 { return L("mko_left_over") }
    if left >= 3600 { return L("mko_left_hours", Int(left / 3600)) }
    return L("mko_left_minutes", max(Int(left / 60), 1))
}

@MainActor
struct MyOrdersPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpen: (String) -> Void
    let onBrowse: () -> Void
    @State private var tab = 0
    @StateObject private var paged = PagedRows<MarketOrderDto>(key: { $0.uuid })
    // FL-L05: my monthly subscriptions.
    @StateObject private var subs = PagedRows<MarketSubscriptionDto>(key: { $0.uuid })

    private var monthly: Bool { tab == orderTabs.count }

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: L("mko_title"), onBack: onBack)
            FlTabs(tabs: [
                FlTab(icon: "hourglass", label: L("mko_tab_awaiting")),
                FlTab(icon: "bolt.fill", label: L("mko_tab_active")),
                FlTab(icon: "checkmark.seal.fill", label: L("mko_tab_completed")),
                FlTab(icon: "tray.fill", label: L("mko_tab_ended")),
                FlTab(icon: "calendar.badge.clock", label: L("mks_tab")),
            ], selected: tab) { tab = $0 }
            .padding(.horizontal, 16).padding(.vertical, 4)
            if monthly {
                SubscriptionsList(subs: subs, onOpen: onOpen, onBrowse: onBrowse)
            } else {
                ScrollView {
                    LazyVStack(spacing: 10) {
                        if !paged.loaded {
                            ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 130) }
                        } else if paged.rows.isEmpty && paged.error == nil {
                            WaEmpty(icon: "doc.text.fill", tone: .blue, title: L("mko_empty_title"), text: L("mko_empty_body")) {
                                FlAction(text: L("mko_browse"), icon: "storefront.fill", tone: .primary, action: onBrowse)
                            }
                        } else {
                            ForEach(Array(paged.rows.enumerated()), id: \.element.uuid) { i, o in
                                OrderTile(o: o) { onOpen(o.uuid) }.onAppear { paged.appeared(i) }
                            }
                        }
                        PagedSpinner(paged: paged)
                        PagedError(paged: paged)
                    }
                    .padding(.horizontal, 16).padding(.vertical, 10)
                }
            }
        }
        .task(id: tab) {
            guard !monthly else { return }
            let status = orderTabs[tab]
            paged.reset { page in try await FreelanceServicesApi.orders(status: status, page: page) }
        }
        .task {
            subs.reset { page in try await FreelanceServicesApi.subscriptions(page: page) }
        }
        .task(id: "\(tab)|\(network.reconnectTick)") { if monthly { await subs.reload() } else { await paged.reload() } }
    }
}

private struct OrderTile: View {
    @Environment(\.wa) private var wa
    let o: MarketOrderDto
    let onClick: () -> Void

    var body: some View {
        let (label, color) = orderStatus(o.status)
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(alignment: .top, spacing: 10) {
                    Image(systemName: "bag.fill").font(.system(size: 17)).foregroundStyle(color)
                        .frame(width: 42, height: 42).background(color.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    VStack(alignment: .leading, spacing: 3) {
                        Text(verbatim: o.service?.title ?? "").font(.dorr(14.5, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2).multilineTextAlignment(.leading)
                        Text(verbatim: [mkLevelName(o.level), o.freelancer?.name].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    MkPill(text: label, color: color)
                }
                HStack(spacing: 4) {
                    Image(systemName: "clock").font(.system(size: 12)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: mkDays(o.deliveryDays ?? 0)).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                    Text(verbatim: mkMoney(o.money?.totalMinor, o.decimals, o.currency)).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                }
                if let left = leftText(o) { FlNotice(text: left, icon: "hourglass", color: mkAmber) }
            }
            .padding(14)
            .settingsSurface(20)
        }
        .buttonStyle(.plain)
    }
}

// MARK: - one order

@MainActor
struct MyOrderPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let uuid: String
    let onBack: () -> Void
    let onOpenWork: (String) -> Void
    let onOrderAgain: (String) -> Void
    @State private var order: MarketOrderDto?
    @State private var error: String?
    @State private var asking = false
    @State private var busy = false

    private func cancel() {
        busy = true
        Task {
            do {
                order = try await FreelanceServicesApi.cancel(uuid).data
                AppToast.show(L("mko_cancelled_toast"))
            } catch {
                self.error = error.apiFailure.message ?? L("fl_error_generic")
            }
            busy = false
            asking = false
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: L("mko_detail"), onBack: onBack)
            if let o = order {
                ScrollView { content(o).padding(.horizontal, 16).padding(.bottom, 20) }
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: network.reconnectTick) {
            do {
                order = try await FreelanceServicesApi.orderOf(uuid).data
                error = nil
            } catch {
                self.error = error.apiFailure.message ?? L("fl_error_generic")
            }
        }
        .dorrDialog(isPresented: Binding(get: { asking }, set: { if !$0 && !busy { asking = false } })) {
            ConfirmDialog(icon: "xmark", title: L("mko_cancel_title"), message: L("mko_cancel_body"), loading: busy, confirmLabel: L("mko_cancel"),
                          onConfirm: cancel, onDismiss: { asking = false })
        }
    }

    @ViewBuilder
    private func content(_ o: MarketOrderDto) -> some View {
        VStack(alignment: .leading, spacing: 14) {
            StatusHero(o: o)
            MkSection(title: L("mko_summary"), icon: "bag.fill") {
                HStack(spacing: 8) {
                    MkPill(text: mkLevelName(o.level), color: wa.accent)
                    if let t = o.tierName, !t.isEmpty { Text(verbatim: t).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk) }
                }
                ForEach(o.features ?? [], id: \.self) { f in
                    HStack(spacing: 8) {
                        Image(systemName: "checkmark.circle.fill").font(.system(size: 14)).foregroundStyle(mkGreen)
                        Text(verbatim: f).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                    }
                }
                ForEach(Array((o.addons ?? []).enumerated()), id: \.offset) { _, a in
                    HStack(spacing: 8) {
                        Image(systemName: "plus.circle").font(.system(size: 14)).foregroundStyle(wa.accent)
                        Text(verbatim: a.title ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                        Text(verbatim: mkMoney(a.priceMinor, o.decimals, o.currency)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                }
                HStack(spacing: 8) {
                    MkFact(icon: "clock", text: mkDays(o.deliveryDays ?? 0))
                    MkFact(icon: "arrow.counterclockwise", text: (o.revisions ?? 0) > 0 ? L("mk_revisions", o.revisions ?? 0) : L("mk_no_revisions"))
                }
                if let n = o.note, !n.isEmpty {
                    Text(L("mko_your_note")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: n).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(5)
                        .frame(maxWidth: .infinity, alignment: .leading).padding(10)
                        .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
            }
            MkSection(title: L("mko_money"), icon: "banknote") {
                MkLine(label: L("mk_price"), value: mkMoney(o.money?.priceMinor, o.decimals, o.currency))
                if (o.money?.clientFeeMinor ?? 0) > 0 { MkLine(label: L("mk_fee"), value: mkMoney(o.money?.clientFeeMinor, o.decimals, o.currency)) }
                MkDivider()
                MkLine(label: L("mk_total"), value: mkMoney(o.money?.totalMinor, o.decimals, o.currency), strong: true)
                if o.state == "awaiting" { Text(L("mko_pay_later")).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
            }
            if let error { FlNotice(text: error, icon: "xmark", color: mkRed) }
            if (o.state == "active" || o.state == "completed"), let project = o.project {
                SettingsPrimaryButton(text: L("mko_open_work")) { onOpenWork(project) }
            } else if o.can?.cancel == true {
                FlAction(text: L("mko_cancel"), icon: "xmark", tone: .danger) { asking = true }
            } else if let service = o.service?.uuid, ["declined", "expired", "cancelled"].contains(o.state) {
                FlAction(text: L("mko_order_again"), icon: "arrow.counterclockwise", tone: .primary) { onOrderAgain(service) }
            }
        }
    }
}

/// Where the order stands: a pill, a line of what it means, and four steps (ordered → accepted → paid → done).
private struct StatusHero: View {
    @Environment(\.wa) private var wa
    let o: MarketOrderDto

    var body: some View {
        let (label, color) = orderStatus(o.status)
        let ended = ["declined", "expired", "cancelled"].contains(o.state)
        let (title, text): (String, String) = {
            switch o.state {
            case "active": return (L("mko_active_title"), L("mko_active_body"))
            case "completed": return (L("mko_completed_title"), L("mko_completed_body"))
            case "declined": return (label, L("mko_declined_body"))
            case "expired": return (label, L("mko_expired_body"))
            case "cancelled": return (label, L("mko_cancelled_body"))
            default: return (L("mko_awaiting_title", o.freelancer?.name ?? ""), L("mko_awaiting_body"))
            }
        }()
        // How far along: 1 ordered, 2 accepted, 4 done (paid is read on the work's page).
        let reached = o.state == "active" ? 2 : (o.state == "completed" ? 4 : 1)
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                MkPill(text: label, color: color, solid: true)
                Spacer()
                // FL-L05: one month of a subscription.
                if let sub = o.subscription {
                    MkPill(text: L("mks_month_n", sub.cycle ?? 1), color: wa.accent)
                } else {
                    Text(L("mko_service_badge")).font(.dorr(11, .semibold)).foregroundStyle(wa.settingsMut)
                }
            }
            Text(verbatim: o.service?.title ?? "").font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(4)
            if !ended {
                HStack(spacing: 0) {
                    ForEach(Array(["mko_step_ordered", "mko_step_accepted", "mko_step_paid", "mko_step_done"].enumerated()), id: \.offset) { i, s in
                        let done = i < reached
                        VStack(spacing: 4) {
                            ZStack {
                                Circle().fill(done ? wa.accent : wa.accent.opacity(0.14))
                                if done {
                                    Image(systemName: "checkmark").font(.system(size: 11, weight: .bold)).foregroundStyle(.white)
                                } else {
                                    Text(verbatim: "\(i + 1)").font(.dorr(11, .heavy)).foregroundStyle(wa.accent)
                                }
                            }
                            .frame(width: 24, height: 24)
                            Text(L(s)).font(.dorr(11, done ? .bold : .medium)).foregroundStyle(done ? wa.settingsInk : wa.settingsMut).lineLimit(1)
                        }
                        .frame(maxWidth: .infinity)
                    }
                }
            }
            VStack(alignment: .leading, spacing: 4) {
                Text(verbatim: title).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                Text(verbatim: text).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                if let left = leftText(o) { Text(verbatim: left).font(.dorr(12.5, .bold)).foregroundStyle(mkAmber) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(12)
            .background(color.opacity(wa.night ? 0.18 : 0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        }
        .padding(16)
        .settingsSurface(22)
    }
}

// MARK: - FL-L05: monthly subscriptions

/// My monthly subscriptions: each with the service, the freelancer, the month it is in and what a month costs, then where it stands
/// — confirm the next month when it opens (paid from the wallet in the same tap), the day this month ends, or the ending asked and its
/// day. I can end one with notice (said before asking) or take my ending back. Tapping opens the month running now.
@MainActor
private struct SubscriptionsList: View {
    @Environment(\.wa) private var wa
    @ObservedObject var subs: PagedRows<MarketSubscriptionDto>
    let onOpen: (String) -> Void
    let onBrowse: () -> Void
    @State private var busy: String?
    @State private var renewing: MarketSubscriptionDto?
    @State private var ending: MarketSubscriptionDto?
    @State private var error: String?

    private func act(_ s: MarketSubscriptionDto, _ action: String) {
        busy = s.uuid
        Task {
            do {
                if action == "renew" { _ = try await FreelanceServicesApi.renewSubscription(s.uuid) } else { _ = try await FreelanceServicesApi.subscriptionAct(s.uuid, action: action) }
                error = nil
                AppToast.show(L(action == "renew" ? "mks_renew_done" : (action == "cancel" ? "mks_cancel_done" : "mks_keep_done")))
                await subs.reload()
            } catch {
                self.error = error.apiFailure.message ?? L("fl_error_generic")
            }
            busy = nil
            renewing = nil
            ending = nil
        }
    }

    var body: some View {
        ScrollView {
            LazyVStack(spacing: 10) {
                if let error { FlNotice(text: error, icon: "xmark", color: mkRed) }
                if !subs.loaded {
                    ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 150) }
                } else if subs.rows.isEmpty && subs.error == nil {
                    WaEmpty(icon: "calendar.badge.clock", tone: .blue, title: L("mks_empty_title"), text: L("mks_empty_body")) {
                        FlAction(text: L("mko_browse"), icon: "storefront.fill", tone: .primary, action: onBrowse)
                    }
                } else {
                    ForEach(Array(subs.rows.enumerated()), id: \.element.uuid) { i, s in
                        SubscriptionTile(s: s, busy: busy == s.uuid, onOpen: { if let m = s.current?.uuid { onOpen(m) } },
                                         onRenew: { renewing = s }, onCancel: { ending = s }, onKeep: { act(s, "keep") })
                            .onAppear { subs.appeared(i) }
                    }
                }
                PagedSpinner(paged: subs)
                PagedError(paged: subs)
            }
            .padding(.horizontal, 16).padding(.vertical, 10)
        }
        .dorrDialog(isPresented: Binding(get: { renewing != nil }, set: { if !$0 && busy == nil { renewing = nil } }), bottom: true) {
            if let s = renewing {
                FlSheet(icon: "calendar.badge.clock", title: L("mks_renew_title"), text: L("mks_renew_body")) {
                    VStack(alignment: .leading, spacing: 8) {
                        Text(verbatim: s.service?.title ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                        MkLine(label: L("mks_month_n", (s.cycle ?? 1) + 1), value: mkMoney(s.money?.priceMinor, s.decimals, s.currency))
                        if (s.money?.clientFeeMinor ?? 0) > 0 { MkLine(label: L("mk_fee"), value: mkMoney(s.money?.clientFeeMinor, s.decimals, s.currency)) }
                        MkLine(label: L("mk_total"), value: mkMoney(s.money?.totalMinor, s.decimals, s.currency), strong: true)
                    }
                    .padding(14)
                    .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    FlNotice(text: L("mks_renew_safe"), icon: "lock.fill", color: mkGreen)
                    SettingsPrimaryButton(text: L("mks_renew_cta"), loading: busy == s.uuid) { act(s, "renew") }
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { ending != nil }, set: { if !$0 { ending = nil } })) {
            if let s = ending {
                ConfirmDialog(icon: "calendar.badge.exclamationmark", title: L("mks_cancel_title"), message: L("mks_cancel_body", s.noticeDays ?? 7),
                              loading: busy == s.uuid, confirmLabel: L("mks_cancel"), onConfirm: { act(s, "cancel") }, onDismiss: { ending = nil })
            }
        }
    }
}

private struct SubscriptionTile: View {
    @Environment(\.wa) private var wa
    let s: MarketSubscriptionDto
    let busy: Bool
    let onOpen: () -> Void
    let onRenew: () -> Void
    let onCancel: () -> Void
    let onKeep: () -> Void

    var body: some View {
        let (label, color): (String, Color) = s.status == "active" ? (L("mks_status_active"), mkGreen)
            : (s.status == "pending" ? (L("mks_status_pending"), mkAmber) : (L("mks_status_ended"), mkSlate))
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .top, spacing: 10) {
                Image(systemName: "calendar.badge.clock").font(.system(size: 17)).foregroundStyle(color)
                    .frame(width: 42, height: 42).background(color.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 3) {
                    Text(verbatim: s.service?.title ?? "").font(.dorr(14.5, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    Text(verbatim: [mkLevelName(s.level), s.freelancer?.name].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                MkPill(text: label, color: color)
            }
            HStack {
                Text(L("mks_month_n", s.cycle ?? 1)).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                Spacer()
                Text(L("mks_price_month", mkMoney(s.money?.totalMinor, s.decimals, s.currency))).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
            }
            if s.status == "ended" {
                FlNotice(text: L(endReason(s.endReason)), icon: "calendar.badge.exclamationmark", color: mkSlate)
            } else if let cancel = s.cancel {
                FlNotice(text: L(cancel.by == "client" ? "mks_ending_me" : "mks_ending_freelancer", mkDay(cancel.endsAt)), icon: "calendar.badge.exclamationmark", color: mkAmber)
            } else if s.renew?.open == true {
                FlNotice(text: L("mks_renew_due", mkDay(s.renew?.dueBy)), icon: "hourglass", color: mkAmber)
            } else if s.status == "active" {
                FlNotice(text: L("mks_month_ends", mkDay(s.periodEndsAt)), icon: "clock", color: mkGreen)
            } else {
                FlNotice(text: L("mks_pending_hint"), icon: "hourglass", color: mkAmber)
            }
            if s.can?.renew == true { SettingsPrimaryButton(text: L("mks_renew_cta"), loading: busy, action: onRenew) }
            if s.can?.keep == true {
                FlAction(text: L("mks_keep"), icon: "arrow.counterclockwise", tone: .primary, loading: busy, action: onKeep)
            } else if s.can?.cancel == true {
                FlAction(text: L("mks_cancel"), icon: "calendar.badge.exclamationmark", tone: .danger, action: onCancel)
            }
        }
        .padding(14)
        .settingsSurface(20)
        .contentShape(Rectangle())
        .onTapGesture { if s.current != nil { onOpen() } }
    }

    private func endReason(_ reason: String?) -> String {
        switch reason {
        case "cancelled_client": return "mks_end_me"
        case "cancelled_freelancer": return "mks_end_freelancer"
        case "not_renewed": return "mks_end_not_renewed"
        default: return "mks_end_first"
        }
    }
}
