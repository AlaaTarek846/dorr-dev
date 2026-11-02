import SwiftUI

/// androidApp `HomeScreen.kt` — the brand header, the promo banners, the stories / portals circles, the services row,
/// the calendar's today card, sports, events, occasions and the quick actions (Android's order).
@MainActor
struct HomeScreen: View {
    let onOpenServices: () -> Void
    let onOpenAccount: () -> Void
    let onOpenWallet: () -> Void
    var onOpenChat: () -> Void = {}
    var onOpenNotifications: () -> Void = {}
    var onOpenService: (ServiceDto) -> Void = { _ in }
    var onOpenAi: () -> Void = {}
    var onOpenPortals: () -> Void = {}
    var onOpenMoments: () -> Void = {}
    var onOpenCalendar: () -> Void = {}

    var body: some View {
        ZStack {
            PinkBackdrop()
            ScrollView {
                VStack(spacing: 0) {
                    HomeHeader(onOpenAccount: onOpenAccount, onOpenNotifications: onOpenNotifications, onOpenWallet: onOpenWallet, onOpenChat: onOpenChat)
                    HeroBannerSlider(slides: [L("banner_slide_welcome"), L("banner_slide_offers"), L("banner_slide_book")])
                        .padding(.horizontal, 20)
                        .padding(.top, 14)
                    Spacer().frame(height: 10)
                    HomeCircles(onOpenPortals: onOpenPortals)
                    Spacer().frame(height: 10)
                    ServicesSection(onViewAll: onOpenServices, onOpenService: onOpenService, onOpenAi: onOpenAi)
                    Spacer().frame(height: 10)
                    TodayCard(onOpen: onOpenCalendar).padding(.horizontal, 20)
                    SportsHomeCard(onOpen: { SportsLink.shared.show() }).padding(.horizontal, 20).padding(.vertical, 4)
                    EventsBanner(onOpen: { EventsLink.shared.show() }).padding(.horizontal, 20).padding(.vertical, 4)
                    MomentsBanner(onOpen: onOpenMoments).padding(.horizontal, 20).padding(.vertical, 4)
                    Spacer().frame(height: 24)
                    QuickActionsRow().padding(.horizontal, 20)
                    Spacer().frame(height: 20)
                }
            }
        }
    }
}

// MARK: - Header

/// The brand-gradient header with rounded bottom corners: avatar, "welcome back" + name, wallet, chats and
/// notifications (a dot when something is unread).
@MainActor
private struct HomeHeader: View {
    @EnvironmentObject private var session: AuthSession
    @EnvironmentObject private var network: NetworkMonitor
    @Environment(\.wa) private var wa
    let onOpenAccount: () -> Void
    let onOpenNotifications: () -> Void
    let onOpenWallet: () -> Void
    let onOpenChat: () -> Void
    @State private var hasUnread = false

    var body: some View {
        let accent = wa.red
        HStack(spacing: 0) {
            Button(action: onOpenAccount) {
                HomeAvatar(user: session.user)
                    .frame(width: 36, height: 36)
                    .background(Color.white.opacity(0.18), in: Circle())
                    .clipShape(Circle())
            }
            .buttonStyle(.plain)
            VStack(alignment: .leading, spacing: 0) {
                Text(L("home_welcome_back")).font(.dorr(11, .semibold)).foregroundStyle(.white.opacity(0.86)).lineLimit(1)
                Text(verbatim: session.user?.name.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? L("account_default_user"))
                    .font(.dorr(15, .heavy)).foregroundStyle(.white).lineLimit(1)
            }
            .padding(.leading, 8)
            .padding(.trailing, 8)
            Spacer(minLength: 0)
            HomeHeaderIcon(icon: "wallet.pass.fill", label: L("wallet_title"), action: onOpenWallet)
            HomeChatButton(action: onOpenChat).padding(.leading, 6)
            HomeHeaderIcon(icon: "bell.fill", label: nil, action: onOpenNotifications)
                .overlay(alignment: .topTrailing) {
                    if hasUnread {
                        Circle().fill(.white).frame(width: 8, height: 8).overlay(Circle().stroke(accent, lineWidth: 1.5)).padding(4)
                    }
                }
                .padding(.leading, 6)
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 10)
        .background(
            LinearGradient(colors: [accent, accent.scaled(0.78)], startPoint: .topLeading, endPoint: .bottomTrailing)
                .clipShape(BottomRoundedRectangle(radius: 22))
                .ignoresSafeArea(edges: .top)
        )
        .task(id: network.reconnectTick) {
            hasUnread = ((try? await NotificationApi.unreadCount().data?.count) ?? 0) > 0
        }
    }
}

/// A rectangle with only its two bottom corners rounded (`RoundedCornerShape(bottomStart, bottomEnd)`).
struct BottomRoundedRectangle: Shape {
    let radius: CGFloat

    func path(in rect: CGRect) -> Path {
        Path(UIBezierPath(roundedRect: rect, byRoundingCorners: [.bottomLeft, .bottomRight], cornerRadii: CGSize(width: radius, height: radius)).cgPath)
    }
}

private struct HomeHeaderIcon: View {
    let icon: String
    let label: String?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(.white)
                .frame(width: 32, height: 32)
                .background(Color.white.opacity(0.18), in: Circle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label ?? "")
    }
}

/// The avatar (busting the cache with the profile's `updated_at`), or a person icon.
private struct HomeAvatar: View {
    let user: UserDto?

    var body: some View {
        let url = ApiClient.mediaUrl(user?.avatar).flatMap { URL(string: "\($0.absoluteString)?v=\(user?.updatedAt ?? "0")") }
        AsyncImage(url: url) { phase in
            if let image = phase.image {
                image.resizable().scaledToFill()
            } else {
                Image(systemName: "person.fill").font(.system(size: 15)).foregroundStyle(.white)
            }
        }
    }
}

/// androidApp `chat/HomeChatButton` — the chats button with the number of unread chats, kept live by the chat's
/// events (a new message, a read).
@MainActor
struct HomeChatButton: View {
    @EnvironmentObject private var network: NetworkMonitor
    @Environment(\.wa) private var wa
    var onBrand = true
    let action: () -> Void
    @State private var unread = 0

    var body: some View {
        Button(action: action) {
            Image(systemName: "bubble.left.fill").font(.system(size: onBrand ? 13 : 15, weight: .semibold))
                .foregroundStyle(onBrand ? .white : wa.accent)
                .frame(width: onBrand ? 32 : 34, height: onBrand ? 32 : 34)
                .background(onBrand ? Color.white.opacity(0.18) : wa.settingsCard, in: Circle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(L("ch_open_chats"))
        .overlay(alignment: .topTrailing) {
            if unread > 0 {
                ChBadge(count: unread).offset(x: 6, y: -6).transition(.scale.combined(with: .opacity))
            }
        }
        .animation(.spring(response: 0.35, dampingFraction: 0.4), value: unread > 0)
        .task(id: network.reconnectTick) { await refresh() }
        .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { e in
            if e.name == "chat.message.sent" || e.name == "chat.receipt" { Task { await refresh() } }
        }
    }

    private func refresh() async {
        if let rows = try? await ChatListApi.unreadConversations() { unread = rows }
    }
}

/// androidApp `chat/ChBadge` — a brand pill with the count ("99+" above 99) that pops when it changes.
struct ChBadge: View {
    @Environment(\.wa) private var wa
    let count: Int
    var muted = false
    @State private var pop: CGFloat = 1

    var body: some View {
        Text(verbatim: count > 99 ? "99+" : String(count))
            .font(.dorr(11.5, .heavy))
            .foregroundStyle(.white)
            .padding(.horizontal, 6)
            .frame(minWidth: 22, minHeight: 22)
            .background(muted ? wa.soft : wa.red, in: Capsule())
            .scaleEffect(pop)
            .onChange(of: count) { _ in
                pop = 0.6
                withAnimation(.spring(response: 0.3, dampingFraction: 0.35)) { pop = 1 }
            }
    }
}

// MARK: - Banners

/// androidApp `components/HeroBannerSlider` — gradient promo cards, one every 5 s, with the page dots.
private struct HeroBannerSlider: View {
    @Environment(\.wa) private var wa
    let slides: [String]
    @State private var page = 0

    var body: some View {
        VStack(spacing: 0) {
            TabView(selection: $page) {
                ForEach(slides.indices, id: \.self) { index in
                    ZStack(alignment: .bottomLeading) {
                        RoundedRectangle(cornerRadius: 14, style: .continuous)
                            .fill(LinearGradient(colors: gradient(index), startPoint: .topLeading, endPoint: .bottomTrailing))
                            .shadow(color: wa.red.opacity(0.12), radius: 8, y: 3)
                        Text(verbatim: slides[index]).font(.dorr(16, .heavy)).foregroundStyle(.white).padding(16)
                    }
                    .padding(.horizontal, 4)
                    .tag(index)
                }
            }
            .tabViewStyle(.page(indexDisplayMode: .never))
            .frame(height: 148)
            if slides.count > 1 {
                DotIndicator(count: slides.count, activeIndex: page).frame(maxWidth: .infinity).padding(.top, 10)
            }
        }
        // The banner turns itself (a screen timer — nothing is asked of the server).
        .task {
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: 5_000_000_000)
                withAnimation(.easeInOut(duration: 0.4)) { page = (page + 1) % max(slides.count, 1) }
            }
        }
    }

    private func gradient(_ index: Int) -> [Color] {
        let brand = wa.red
        switch index % 3 {
        case 1: return [brand.scaled(0.72), brand]
        case 2: return [Color(hex: 0x111928), Color(hex: 0x374151)]
        default: return [brand, brand.blendedWhite(0.35)]
        }
    }
}

// MARK: - Services

/// Each service's icon when it has no image (Material icons → SF Symbols).
func serviceIcon(_ moduleName: String?) -> String {
    switch moduleName {
    case "system_users", "driver_without_vehicle": return "person.fill"
    case "admin": return "person.badge.key.fill"
    case "admin_permission": return "lock.fill"
    case "chat": return "bubble.left.fill"
    case "passenger_ride": return "car.fill"
    case "car_rental": return "car.side.fill"
    case "driving_lessons": return "graduationcap.fill"
    case "restaurants": return "fork.knife"
    case "parcels": return "shippingbox.fill"
    case "moving": return "box.truck.fill"
    case "water_gas": return "drop.fill"
    case "fuel": return "fuelpump.fill"
    case "mechanic": return "wrench.and.screwdriver.fill"
    case "electrician": return "bolt.fill"
    case "car_wash", "cleaning": return "sparkles"
    case "printing": return "printer.fill"
    case "designers": return "paintpalette.fill"
    case "stores": return "storefront.fill"
    case "accounting_system": return "function"
    case "medical_supplies": return "cross.case.fill"
    case "hotels_flights": return "airplane"
    case "umrah_hajj_trips": return "globe"
    case "furnished_apartments": return "house.fill"
    case "chalets_resorts": return "bed.double.fill"
    case "salons": return "scissors"
    case "home_meals": return "takeoutbag.and.cup.and.straw.fill"
    case "private_chef": return "menucard.fill"
    case "event_catering": return "birthday.cake.fill"
    case "sports_venues": return "soccerball"
    case "events": return "calendar"
    case "freelance": return "briefcase.fill"
    case "ai_assistant": return "sparkles"
    default: return "square.grid.2x2.fill"
    }
}

/// Loads the dashboard services, ordered by sort order then id (children the same way).
@MainActor
final class ServicesLoader: ObservableObject {
    enum State: Equatable { case loading, error, loaded([ServiceDto]) }
    @Published private(set) var state = State.loading

    func load(homeDashboardOnly: Bool) async {
        state = .loading
        do {
            let services = (try await ServiceApi.list(home: homeDashboardOnly ? 1 : nil).data ?? [])
                .sorted { ($0.sortOrder ?? 0, $0.id) < ($1.sortOrder ?? 0, $1.id) }
                .map { service -> ServiceDto in
                    var copy = service
                    copy.children = (service.children ?? []).sorted { ($0.sortOrder ?? 0, $0.id) < ($1.sortOrder ?? 0, $1.id) }
                    return copy
                }
            state = .loaded(services)
        } catch {
            state = .error
        }
    }
}

/// androidApp `components/ServicesSection` — "Services" with a "View all" link over one row of small cards
/// (the image when the API sends one, else the service's icon). The AI assistant card opens the assistant.
@MainActor
private struct ServicesSection: View {
    @EnvironmentObject private var network: NetworkMonitor
    @Environment(\.wa) private var wa
    let onViewAll: () -> Void
    let onOpenService: (ServiceDto) -> Void
    let onOpenAi: () -> Void
    @StateObject private var loader = ServicesLoader()
    @State private var reloadKey = 0

    var body: some View {
        VStack(spacing: 0) {
            HStack {
                Text(L("services_title")).font(.dorr(20, .heavy)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary)
                Spacer()
                Button(action: onViewAll) {
                    HStack(spacing: 0) {
                        Text(L("home_stories_all")).font(.dorr(12, .bold))
                        Image(systemName: "chevron.forward").font(.system(size: 12, weight: .bold))
                    }
                    .foregroundStyle(wa.accent)
                    .padding(.leading, 8).padding(.trailing, 4).padding(.vertical, 5)
                }
                .buttonStyle(.plain)
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 8)

            switch loader.state {
            case .loading:
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(0..<4, id: \.self) { _ in SkeletonPulse(cornerRadius: 16).frame(width: 88, height: 98) }
                    }
                    .padding(.horizontal, 20)
                    .padding(.vertical, 4)
                }
                .disabled(true)
            case .error:
                ServicesError { reloadKey += 1 }.padding(.horizontal, 20)
            case .loaded(let services):
                if !services.isEmpty {
                    ScrollView(.horizontal, showsIndicators: false) {
                        LazyHStack(spacing: 8) {
                            ForEach(services) { service in
                                ServiceChip(service: service) {
                                    if service.moduleName == "ai_assistant" { onOpenAi() } else { onOpenService(service) }
                                }
                            }
                        }
                        .padding(.horizontal, 20)
                        .padding(.vertical, 4)
                    }
                }
            }
        }
        .task(id: "\(reloadKey)-\(network.reconnectTick)") { await loader.load(homeDashboardOnly: true) }
    }
}

/// One small service card: the image (or the icon) over its two-line name.
private struct ServiceChip: View {
    @Environment(\.wa) private var wa
    let service: ServiceDto
    let action: () -> Void

    var body: some View {
        let ink = wa.night ? wa.ink : AppColors.textPrimary
        Button(action: action) {
            VStack(spacing: 6) {
                ServiceAvatar(image: service.image, icon: serviceIcon(service.moduleName), size: 46)
                Text(verbatim: service.name + "\n").font(.dorr(11.5, .bold)).foregroundStyle(ink)
                    .multilineTextAlignment(.center).lineLimit(2).lineSpacing(0)
            }
            .padding(.horizontal, 6)
            .padding(.vertical, 10)
            .frame(width: 88)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.night ? wa.line : ink.opacity(0.10), lineWidth: 1))
        }
        .buttonStyle(.plain)
    }
}

/// A compact service tile: the image on a dark well (logos are often white line-art), or the icon on a
/// brand-tinted well.
struct ServiceAvatar: View {
    @Environment(\.wa) private var wa
    let image: String?
    let icon: String
    let size: CGFloat

    var body: some View {
        let hasImage = !(image ?? "").trimmingCharacters(in: .whitespaces).isEmpty
        ZStack {
            if hasImage {
                AsyncImage(url: ApiClient.mediaUrl(image)) { phase in
                    if let picture = phase.image { picture.resizable().scaledToFit() } else { Color.clear }
                }
                .padding(6)
            } else {
                Image(systemName: icon).font(.system(size: size * 0.42, weight: .semibold)).foregroundStyle(wa.accent)
            }
        }
        .frame(width: size, height: size)
        .background(hasImage ? (wa.night ? Color(hex: 0x22262E) : Color(hex: 0x2A3140)) : (wa.night ? wa.darkWell : wa.red.opacity(0.10)),
                    in: RoundedRectangle(cornerRadius: 16, style: .continuous))
    }
}

/// The grey box that pulses while services load.
struct SkeletonPulse: View {
    @Environment(\.wa) private var wa
    var cornerRadius: CGFloat = 16
    @State private var on = false

    var body: some View {
        RoundedRectangle(cornerRadius: cornerRadius, style: .continuous)
            .fill(wa.night ? wa.line : AppColors.border)
            .opacity(on ? 0.9 : 0.35)
            .onAppear { withAnimation(.linear(duration: 0.8).repeatForever(autoreverses: true)) { on = true } }
    }
}

/// "Couldn't load services" with a retry.
struct ServicesError: View {
    @Environment(\.wa) private var wa
    let onRetry: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            Image(systemName: "icloud.slash").font(.system(size: 30)).foregroundStyle(wa.night ? wa.mut : AppColors.textMuted)
            Text(L("services_error")).font(.dorr(14)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary).padding(.top, 8)
            Button(action: onRetry) {
                Text(L("services_retry")).font(.dorr(14, .semibold)).foregroundStyle(wa.accent).padding(.horizontal, 12).padding(.vertical, 8)
            }
            .buttonStyle(.plain)
        }
        .padding(20)
        .frame(maxWidth: .infinity)
        .background(AppColors.danger.opacity(0.06), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(AppColors.danger.opacity(0.2), lineWidth: 1))
    }
}

// MARK: - Quick actions

private struct QuickActionsRow: View {
    var body: some View {
        HStack(spacing: 8) {
            QuickActionCard(icon: "phone.connection.fill", label: L("home_call"))
            QuickActionCard(icon: "chart.bar.fill", label: L("home_history"))
        }
    }
}

/// A small card with a brand well and a label (still a placeholder on Android: tapping does nothing).
private struct QuickActionCard: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String

    var body: some View {
        VStack(spacing: 10) {
            Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.accent)
                .frame(width: 34, height: 34)
                .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary)
        }
        .padding(12)
        .frame(maxWidth: .infinity, minHeight: 96)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        .overlay { if wa.night { RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.line, lineWidth: 1) } }
        .shadow(color: wa.night ? .clear : wa.red.opacity(0.07), radius: 6, y: 2)
    }
}
