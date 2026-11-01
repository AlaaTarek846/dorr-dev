import PhotosUI
import SwiftUI
import UIKit

// androidApp `events/EventsScreen.kt`.
// DORR Discover: public events from trusted sources — the admin and verified organizers — around me, in places I
// follow and where I'm travelling. Each event shows its own local time and mine; "interested" puts it in my
// calendar and tells me when it changes.

/// Opening Discover from anywhere: the home banner, the calendar, a notification.
@MainActor
final class EventsLink: ObservableObject {
    static let shared = EventsLink()
    @Published var open = false
    @Published var eventId: String?

    func show(_ id: String? = nil) {
        eventId = id
        open = true
    }

    func close() {
        open = false
        eventId = nil
    }
}

/// Discover's home, kept between visits; `version` bumps when something changes (interested…).
@MainActor
final class EventsStore: ObservableObject {
    static let shared = EventsStore()
    @Published var home: DiscHomeDto?
    @Published var off = false
    @Published private(set) var version = 0
    private var cities: [DiscCityDto]?

    func refresh() async {
        do {
            if let fresh = try await EventsApi.home(timezone: evZone()).data { home = fresh }
            off = false
        } catch {
            off = error.apiFailure.httpStatus == 403
        }
    }

    /// Every city Discover covers (for travelling and following).
    func allCities() async -> [DiscCityDto] {
        if let cities { return cities }
        let list = (try? await EventsApi.cities().data) ?? []
        if !list.isEmpty { cities = list }
        return list
    }

    func changed() async {
        version += 1
        await refresh()
        await CalendarStore.shared.changed()
    }
}

indirect enum EvPage: Equatable {
    case home
    case browse(categoryId: Int?, place: EvPlace)
    case travel, ask, saved, settings, organizer, submit
    case event(String)

    var key: String {
        switch self {
        case .home: return "home"
        case .browse(let c, let p): return "browse-\(c ?? -1)-\(p.regionId ?? -1)"
        case .travel: return "travel"
        case .ask: return "ask"
        case .saved: return "saved"
        case .settings: return "settings"
        case .organizer: return "organizer"
        case .submit: return "submit"
        case .event(let id): return "event-\(id)"
        }
    }
}

@MainActor
struct EventsScreen: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var link = EventsLink.shared
    let onExit: () -> Void
    @State private var stack: [EvPage] = [.home]
    @State private var forward = true

    var body: some View {
        ZStack {
            page(stack.last ?? .home)
                .id("\(stack.count)-\(stack.last?.key ?? "")")
                .transition(.asymmetric(
                    insertion: .modifier(active: EvSlide(fraction: forward ? 1.0 / 6 : -1.0 / 6), identity: EvSlide(fraction: 0)),
                    removal: .modifier(active: EvSlide(fraction: forward ? -0.1 : 0.1), identity: EvSlide(fraction: 0))
                ))
        }
        .background(wa.bg.ignoresSafeArea())
        .onAppear { openLinked() }
        .onChange(of: link.eventId) { _ in openLinked() }
    }

    private func openLinked() {
        guard let id = link.eventId else { return }
        if stack.last != .event(id) { go(.event(id)) }
        link.eventId = nil
    }

    @ViewBuilder private func page(_ p: EvPage) -> some View {
        switch p {
        case .home: EvHome(onBack: back, go: go)
        case .browse(let c, let place): EvBrowse(startCategory: c, startPlace: place, onBack: back, go: go)
        case .travel: EvTravel(onBack: back, go: go)
        case .ask: EvAsk(onBack: back, go: go)
        case .saved: EvSaved(onBack: back, go: go)
        case .settings: EvSettings(onBack: back)
        case .organizer: EvOrganizer(onBack: back, go: go)
        case .submit: EvSubmit(onBack: back)
        case .event(let id): EventDetail(id: id, onBack: back)
        }
    }

    private func go(_ p: EvPage) {
        forward = true
        withAnimation(.easeOut(duration: 0.32)) { stack.append(p) }
    }

    private func back() {
        if stack.count <= 1 { onExit(); return }
        forward = false
        withAnimation(.easeOut(duration: 0.28)) { _ = stack.removeLast() }
    }
}

private struct EvSlide: ViewModifier {
    let fraction: CGFloat
    func body(content: Content) -> some View {
        GeometryReader { proxy in content.offset(x: proxy.size.width * fraction).opacity(fraction == 0 ? 1 : 0) }
    }
}

// MARK: - Frame

/// Every Discover page: the dark header panel the calendar has (title, round translucent buttons, back last), an
/// optional hero under the title, then the page — or, for the inner pages, the settings header.
struct EvFrame<Content: View, Actions: View, Hero: View, Cta: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    let onBack: () -> Void
    var scroll = true
    var settingsHeader = false
    var hasCta = false
    var hasHero = false
    @ViewBuilder var actions: () -> Actions
    @ViewBuilder var hero: () -> Hero
    @ViewBuilder var cta: () -> Cta
    @ViewBuilder var content: () -> Content

    var body: some View {
        if settingsHeader {
            ZStack(alignment: .bottom) {
                PinkBackdrop()
                VStack(spacing: 0) {
                    SubHeader(title: title, onBack: onBack)
                    if scroll {
                        ScrollView {
                            VStack(alignment: .leading, spacing: 0, content: content)
                                .padding(.horizontal, 14)
                                .padding(.top, 6)
                                .padding(.bottom, hasCta ? 110 : 16)
                        }
                        .scrollDismissesKeyboard(.interactively)
                    } else {
                        VStack(alignment: .leading, spacing: 0, content: content)
                            .padding(.horizontal, 14)
                            .padding(.top, 6)
                            .frame(maxHeight: .infinity, alignment: .top)
                    }
                }
                if hasCta {
                    VStack(spacing: 0, content: cta)
                        .padding(.horizontal, 16)
                        .padding(.top, 26)
                        .padding(.bottom, 16)
                        .background(LinearGradient(colors: [wa.bg.opacity(0), wa.bg.opacity(0.96)], startPoint: .top, endPoint: UnitPoint(x: 0.5, y: 0.4)).ignoresSafeArea(edges: .bottom))
                }
            }
        } else {
            WaPage(title: title, onBack: onBack, scroll: scroll, showHeader: false, hasCta: hasCta, actions: { EmptyView() }, cta: cta) {
                DarkHeroPanel {
                    HStack {
                        Text(verbatim: title).font(.dorr(22, .black)).foregroundStyle(.white).lineLimit(2)
                        Spacer(minLength: 8)
                        HStack(spacing: 8) {
                            actions()
                            DarkHeroButton(icon: "arrow.forward", label: L("common_back"), action: onBack)
                        }
                    }
                    if hasHero { hero() }
                }
                Spacer().frame(height: 14)
                content()
            }
        }
    }
}

extension EvFrame where Actions == EmptyView, Hero == EmptyView, Cta == EmptyView {
    init(title: String, onBack: @escaping () -> Void, scroll: Bool = true, settingsHeader: Bool = false, @ViewBuilder content: @escaping () -> Content) {
        self.init(title: title, onBack: onBack, scroll: scroll, settingsHeader: settingsHeader, actions: { EmptyView() }, hero: { EmptyView() }, cta: { EmptyView() }, content: content)
    }
}

extension EvFrame where Actions == EmptyView, Hero == EmptyView {
    init(title: String, onBack: @escaping () -> Void, settingsHeader: Bool = false, hasCta: Bool, @ViewBuilder cta: @escaping () -> Cta, @ViewBuilder content: @escaping () -> Content) {
        self.init(title: title, onBack: onBack, settingsHeader: settingsHeader, hasCta: hasCta, actions: { EmptyView() }, hero: { EmptyView() }, cta: cta, content: content)
    }
}

// MARK: - Home

@MainActor
private struct EvHome: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var store = EventsStore.shared
    let onBack: () -> Void
    let go: (EvPage) -> Void

    var body: some View {
        let home = store.home
        EvFrame(title: L("ev_title"), onBack: onBack, hasHero: !store.off && home != nil, actions: {
            DarkHeroButton(icon: "heart", label: L("ev_saved")) { go(.saved) }
            DarkHeroButton(icon: "gearshape.fill", label: L("ev_settings")) { go(.settings) }
        }, hero: {
            if let home {
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("ev_hero_title")).font(.dorr(21, .heavy)).foregroundStyle(.white).padding(.top, 14)
                    Text(L("ev_hero_sub")).font(.dorr(13)).foregroundStyle(.white.opacity(0.85))
                    if home.ai {
                        Button { go(.ask) } label: {
                            HStack(spacing: 8) {
                                Image(systemName: "sparkles").font(.system(size: 16, weight: .semibold)).foregroundStyle(evPurple)
                                Text(L("ev_ask_hint")).font(.dorr(13.5)).foregroundStyle(wa.mut).lineLimit(1)
                                Spacer(minLength: 0)
                            }
                            .padding(.horizontal, 14).padding(.vertical, 11)
                            .background(Color.white, in: Capsule())
                        }
                        .buttonStyle(.plain)
                        .padding(.top, 14)
                    }
                    Spacer().frame(height: 4)
                }
            }
        }, cta: { EmptyView() }) {
            if store.off {
                WaCard { WaEmpty(icon: "safari", tone: .gray, title: L("ev_off_title"), text: L("ev_off_text")) }
            } else if let home {
                HomeContent(home: home, go: go)
            } else {
                WaSkeleton(cornerRadius: 26).frame(height: 150)
                HStack(spacing: 10) { ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(width: 196, height: 176) } }.padding(.top, 16)
            }
        }
        .task { await store.refresh() }
    }
}

private struct HomeContent: View {
    @Environment(\.wa) private var wa
    let home: DiscHomeDto
    let go: (EvPage) -> Void

    var body: some View {
        // Quick ways in.
        HStack(spacing: 6) {
            QuickTile(emoji: "✈️", label: L("ev_travel")) { go(.travel) }
            QuickTile(emoji: "🔎", label: L("ev_browse")) { go(.browse(categoryId: nil, place: EvPlace())) }
            QuickTile(emoji: "❤️", label: L("ev_saved")) { go(.saved) }
            if home.canSubmit { QuickTile(emoji: "🎤", label: L("ev_organizer")) { go(.organizer) } }
        }
        .padding(.top, 10)
        .waRise(1)

        // The regions of my country with something on — more load as the strip scrolls.
        RegionsStrip(first: home.regions, rise: 2) { r in go(.browse(categoryId: nil, place: EvPlace(regionId: r.id, regionName: r.name))) }
        if !home.categories.isEmpty {
            CategoriesStrip(all: home.categories, rise: 3) { c in go(.browse(categoryId: c.id, place: EvPlace())) }
        }
        ForEach(Array(home.sections.enumerated()), id: \.element.key) { i, section in
            HomeSection(section: section, rise: 4 + i, go: go)
        }
        if home.sections.isEmpty {
            WaCard {
                WaEmpty(icon: "calendar", tone: .blue, title: L("ev_empty_title"), text: L("ev_empty_text")) {
                    WaButton(text: L("ev_travel"), style: .ghost, icon: "airplane") { go(.travel) }
                }
            }
            .padding(.top, 18)
        }
        Spacer().frame(height: 24)
    }
}

private struct StripMoreArrow: View {
    @Environment(\.wa) private var wa
    let action: () -> Void
    var body: some View {
        Button(action: action) {
            Image(systemName: "chevron.forward").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.mut).frame(width: 28, height: 28)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(L("ev_section_more"))
    }
}

/// The kinds, 15 at a time as the strip scrolls; the arrow moves one screen on.
private struct CategoriesStrip: View {
    let all: [DiscCategoryDto]
    let rise: Int
    let onOpen: (DiscCategoryDto) -> Void
    @State private var shown = 15
    @State private var target: Int?

    var body: some View {
        let items = Array(all.prefix(shown))
        VStack(alignment: .leading, spacing: 0) {
            WaSectionTitle(title: L("ev_categories")) {
                if shown < all.count || items.count > 4 {
                    StripMoreArrow {
                        let next = min((target ?? 0) + 4, all.count - 1)
                        if next >= shown { shown = min(shown + 15, all.count) }
                        target = next
                    }
                }
            }
            .waRise(rise)
            ScrollViewReader { proxy in
                ScrollView(.horizontal, showsIndicators: false) {
                    LazyHStack(spacing: 6) {
                        ForEach(Array(items.enumerated()), id: \.element.id) { i, c in
                            CategoryTile(c: c) { onOpen(c) }
                                .id(i)
                                .onAppear { if i >= items.count - 3 && shown < all.count { shown = min(shown + 15, all.count) } }
                        }
                    }
                }
                .onChange(of: target) { t in if let t { withAnimation { proxy.scrollTo(t, anchor: .leading) } } }
            }
            .waRise(rise)
        }
    }
}

/// One home strip: the first page came with the home, the next 15 load as the row scrolls.
@MainActor
private struct HomeSection: View {
    let section: DiscSectionDto
    let rise: Int
    let go: (EvPage) -> Void
    @State private var events: [DiscEventDto] = []
    @State private var page = 1
    @State private var more = false
    @State private var loading = false
    @State private var target: Int?

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            WaSectionTitle(title: sectionTitle(section.key)) {
                if more || events.count > 2 {
                    StripMoreArrow {
                        let next = (target ?? 0) + 2
                        if next >= events.count { Task { await load() } }
                        target = min(next, max(events.count - 1, 0))
                    }
                }
            }
            .waRise(rise)
            // Two cards a screen (the page's width less its 16-pt sides and the gap).
            let cardW = (UIScreen.main.bounds.width - 32 - 10) / 2
            ScrollViewReader { reader in
                ScrollView(.horizontal, showsIndicators: false) {
                        HStack(alignment: .top, spacing: 10) {
                            ForEach(Array(events.enumerated()), id: \.element.id) { i, e in
                                EventCard(e: e) { go(.event(e.id)) }
                                    .frame(width: cardW)
                                    .id(i)
                                    .onAppear { if i >= events.count - 3 { Task { await load() } } }
                            }
                        }
                    }
                .onChange(of: target) { t in if let t { withAnimation { reader.scrollTo(t, anchor: .leading) } } }
            }
            .waRise(rise)
        }
        .onAppear {
            if events.isEmpty {
                events = section.items
                more = section.hasMore
            }
        }
        .onChange(of: section) { s in
            events = s.items
            page = 1
            more = s.hasMore
        }
    }

    private func load() async {
        guard !loading, more else { return }
        loading = true
        do {
            let r = try await EventsApi.section(section.key, timezone: evZone(), page: page + 1)
            let known = Set(events.map(\.id))
            events += (r.data ?? []).filter { !known.contains($0.id) }
            page += 1
            more = r.pagination?.hasMorePages == true
        } catch {
            more = false
        }
        loading = false
    }
}

private func sectionTitle(_ key: String) -> String {
    switch key {
    case "for_you": return L("ev_sec_for_you")
    case "this_week": return L("ev_sec_this_week")
    case "weekend": return L("ev_sec_weekend")
    case "free": return L("ev_sec_free")
    case "popular": return L("ev_sec_popular")
    case "followed": return L("ev_sec_followed")
    default: return key
    }
}

private struct QuickTile: View {
    @Environment(\.wa) private var wa
    let emoji: String
    let label: String
    let action: () -> Void
    var body: some View {
        Button(action: action) {
            VStack(spacing: 2) {
                Text(verbatim: emoji).font(.system(size: 20))
                Text(verbatim: label).font(.dorr(11.5, .bold)).foregroundStyle(wa.ink).lineLimit(1)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 8)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Browse

extension EvWhen {
    /// The Arab weekend: Friday and Saturday.
    var range: (String?, String?) {
        let cal = Calendar(identifier: .gregorian)
        let today = calToday()
        switch self {
        case .any: return (nil, nil)
        case .today: return (calKey(today), calKey(today))
        case .weekend:
            let weekday = cal.component(.weekday, from: today) // 6 = Friday, 7 = Saturday
            let fri: Date = weekday == 7 ? calAddDays(today, -1) : calAddDays(today, (6 - weekday + 7) % 7)
            return (calKey(max(fri, today)), calKey(calAddDays(fri, 1)))
        case .week: return (calKey(today), calKey(calAddDays(today, 6)))
        case .month: return (calKey(today), calKey(calAddDays(today, 30)))
        }
    }
}

@MainActor
private struct EvBrowse: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var store = EventsStore.shared
    let startCategory: Int?
    let startPlace: EvPlace
    let onBack: () -> Void
    let go: (EvPage) -> Void
    @State private var filter = EvFilter()
    @State private var filterOpen = false
    @State private var query = ""
    @State private var items: [DiscEventDto] = []
    @State private var page = 1
    @State private var more = false
    @State private var loading = true
    @State private var started = false

    var body: some View {
        let title = filter.place.cityName ?? filter.place.regionName ?? L("ev_browse")
        EvFrame(title: title, onBack: onBack, settingsHeader: true) {
            HStack(spacing: 8) {
                DorrTextArea(value: Binding(get: { query }, set: { query = String($0.prefix(100)) }), placeholder: L("ev_search_hint"), icon: "magnifyingglass", singleLine: true)
                // The place filter: country → region → city.
                ZStack(alignment: .topTrailing) {
                    WaCircleButton(icon: "slider.horizontal.3", label: L("ev_place_title")) { filterOpen = true }
                    if filter.count > 0 {
                        Text(verbatim: String(filter.count)).font(.dorr(10, .heavy)).foregroundStyle(.white)
                            .padding(.horizontal, 6).padding(.vertical, 1)
                            .background(wa.red, in: Capsule())
                    }
                }
            }
            .waRise(0)
            EvFilterChips(filter: filter, kind: store.home?.categories.first { $0.id == filter.categoryId }) { filter = $0 }
            Spacer().frame(height: 14)
            if loading && items.isEmpty {
                ForEach(0..<4, id: \.self) { _ in WaSkeleton(cornerRadius: 20).frame(height: 76).padding(.bottom, 10) }
            } else if items.isEmpty {
                WaCard {
                    WaEmpty(icon: "calendar", tone: .gray, title: L("ev_none_title"), text: L("ev_none_text")) {
                        if filter.place.isSet { WaButton(text: L("ev_place_clear"), style: .ghost) { filter.place = EvPlace() } }
                    }
                }
            } else {
                // Infinite scroll: the next 15 load when the last rows are on screen.
                LazyVStack(spacing: 10) {
                    ForEach(Array(items.enumerated()), id: \.element.id) { i, e in
                        EventRow(e: e) { go(.event(e.id)) }
                            .waRise(min(i, 6))
                            .onAppear { if i >= items.count - 4 && more && !loading { Task { await load(page + 1) } } }
                    }
                    if more || loading {
                        ProgressView().tint(wa.red).padding(.vertical, 12)
                    } else {
                        Text(L("ev_list_end")).font(.dorr(12)).foregroundStyle(wa.mut).frame(maxWidth: .infinity).padding(.vertical, 10)
                    }
                }
            }
        }
        .onAppear {
            guard !started else { return }
            started = true
            filter = EvFilter(place: startPlace, categoryId: startCategory, family: store.home?.preferences?.familyOnly == true)
        }
        // A new filter starts over from the first page.
        .task(id: "\(started)|\(String(describing: filter))|\(query)") {
            guard started else { return }
            if !query.isEmpty { try? await Task.sleep(nanoseconds: 300_000_000); guard !Task.isCancelled else { return } }
            await load(1)
        }
        .sheet(isPresented: $filterOpen) {
            EvFilterSheet(current: filter, categories: store.home?.categories ?? [], myCountryCode: store.home?.country,
                          onApply: { filter = $0; filterOpen = false }, onClose: { filterOpen = false }).dorrThemed()
        }
    }

    private func load(_ next: Int) async {
        loading = true
        let (from, to) = filter.whenPick.range
        let place = filter.place
        let q = query.trimmingCharacters(in: .whitespaces)
        do {
            let r = try await EventsApi.events(timezone: evZone(), cityId: place.cityId, categoryId: filter.categoryId,
                                               regionId: place.cityId == nil ? place.regionId : nil,
                                               countryId: place.cityId == nil && place.regionId == nil ? place.countryId : nil,
                                               from: from, to: to, free: filter.free ? 1 : nil, family: filter.family ? 1 : nil,
                                               q: q.isEmpty ? nil : q, sort: filter.popular ? "popular" : nil, page: next)
            if next == 1 { items = [] }
            let known = Set(items.map(\.id))
            items += (r.data ?? []).filter { !known.contains($0.id) }
            page = next
            more = r.pagination?.hasMorePages == true
        } catch {
            if error is CancellationError { return }
            if next == 1 { items = [] }
            more = false
        }
        loading = false
    }
}

struct EventList: View {
    let items: [DiscEventDto]
    let loading: Bool
    let onOpen: (DiscEventDto) -> Void
    var empty: String?
    var onInterest: ((DiscEventDto) -> Void)?

    var body: some View {
        if loading {
            ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 20).frame(height: 76).padding(.bottom, 10) }
        } else if items.isEmpty {
            WaCard { WaEmpty(icon: "calendar", tone: .gray, title: L("ev_none_title"), text: empty ?? L("ev_none_text")) }
        } else {
            ForEach(Array(items.enumerated()), id: \.element.id) { i, e in
                EventRow(e: e, onInterest: onInterest) { onOpen(e) }.padding(.bottom, 10).waRise(min(i, 6))
            }
        }
    }
}

// MARK: - Travel

@MainActor
private struct EvTravel: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let go: (EvPage) -> Void
    @State private var country: DiscCountryDto?
    @State private var region: DiscRegionDto?
    @State private var countries: [DiscCountryDto] = []
    @State private var regions: [DiscRegionDto] = []
    @State private var from = calAddDays(calToday(), 7)
    @State private var to = calAddDays(calToday(), 10)
    @State private var picking: String?
    @State private var result: DiscTravelDto?
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        EvFrame(title: L("ev_travel_title"), onBack: onBack, settingsHeader: true, hasCta: true, cta: {
            WaButton(text: L("ev_travel_go"), icon: "magnifyingglass", enabled: country != nil, loading: busy, action: search)
        }) {
            WaNote(text: L("ev_travel_sub"), icon: "airplane").waRise(0)
            VStack(spacing: 14) {
                EvSelect(label: L("ev_country"), icon: "globe", value: country.map(countryName) ?? L("ev_pick_country"),
                         options: countries.map { c in (countryName(c), { country = c; result = nil }) }, selected: country.map(countryName))
                EvSelect(label: L("ev_region"), icon: "map.fill", value: region?.name ?? L("ev_all_regions"),
                         options: [(L("ev_all_regions"), { region = nil; result = nil })] + regions.map { r in (r.name ?? "", { region = r; result = nil }) },
                         selected: region?.name, enabled: country != nil)
                HStack(spacing: 8) {
                    PickRow(emoji: "🛫", label: L("ev_from"), value: dayText(from)) { picking = "from" }
                    PickRow(emoji: "🛬", label: L("ev_to"), value: dayText(to)) { picking = "to" }
                }
            }
            .padding(.top, 14)
            .waRise(1)
            if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(wa.danger).padding(.top, 10) }
            Spacer().frame(height: 14)
            if let found = result {
                if let z = found.place?.timezone ?? found.city?.timezone {
                    WaNote(text: L("ev_travel_zone_note", zoneCity(z))).padding(.bottom, 4)
                }
                WaSectionTitle(title: L("ev_travel_found", found.items.count))
                EventList(items: found.items, loading: false, onOpen: { go(.event($0.id)) }, empty: L("ev_travel_none"))
            } else {
                // Before a search: the empty state, asking where to.
                WaCard { WaEmpty(icon: "airplane", tone: .gray, title: L("ev_travel_start_title"), text: L("ev_travel_start_text")) }
            }
            Spacer().frame(height: 24)
        }
        .task { countries = await EvPlaces.countries() }
        // The regions of the chosen country only; changing the country clears the region.
        .task(id: country?.id) {
            region = nil
            regions = []
            if let id = country?.id { regions = await EvPlaces.regions(id) }
        }
        .dorrDialog(isPresented: Binding(get: { picking != nil }, set: { if !$0 { picking = nil } })) {
            CalDatePickerDialog(initial: picking == "from" ? from : to, onDismiss: { picking = nil }) { d in
                if picking == "from" {
                    from = d
                    if to < d { to = d }
                } else {
                    to = d < from ? from : d
                }
                picking = nil
            }
        }
    }

    private func search() {
        guard let c = country else { return }
        busy = true
        error = nil
        Task {
            do {
                result = try await EventsApi.travel(from: calKey(from), to: calKey(to), timezone: evZone(), regionId: region?.id, countryId: region == nil ? c.id : nil).data
            } catch {
                self.error = error.apiFailure.message
                result = nil
            }
            busy = false
        }
    }
}

/// The app's field (rounded, hairline, label above) — tapped to open its picker instead of typing.
struct PickRow: View {
    @Environment(\.wa) private var wa
    let emoji: String
    let label: String
    let value: String
    let action: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 8)
            Button(action: action) {
                HStack(spacing: 8) {
                    Text(verbatim: emoji).font(.system(size: 16))
                    Text(verbatim: value).font(.dorr(15)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Spacer(minLength: 0)
                }
                .padding(.horizontal, 14)
                .frame(height: 46)
                .background(wa.night ? wa.bg : Color(hex: 0xFBF7F8), in: Capsule())
                .overlay(Capsule().stroke(wa.night ? wa.line : Color(hex: 0xF3D5DB), lineWidth: 1))
            }
            .buttonStyle(.plain)
        }
        .frame(maxWidth: .infinity)
    }
}

// MARK: - Ask DORR AI

@MainActor
private struct EvAsk: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let go: (EvPage) -> Void
    @State private var text = ""
    @State private var answer: DiscAskDto?
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        EvFrame(title: L("ev_ask_title"), onBack: onBack, settingsHeader: true) {
            HStack(spacing: 8) {
                DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(500)) }), placeholder: L("ev_ask_hint"), icon: "sparkles",
                             singleLine: true, onSubmit: { ask(text) })
                Button { ask(text) } label: {
                    Image(systemName: "paperplane.fill").font(.system(size: 15)).foregroundStyle(.white)
                        .flipsForRightToLeftLayoutDirection(true)
                        .frame(width: 38, height: 38)
                        .background(evPurple, in: Circle())
                }
                .buttonStyle(WaPressStyle(scale: 0.9))
                .accessibilityLabel(L("ev_ask_send"))
            }
            .waRise(0)
            if answer == nil && !busy {
                Text(L("ev_ask_try")).font(.dorr(12.5, .bold)).foregroundStyle(wa.mut).padding(.top, 16).padding(.bottom, 6)
                ForEach(["ev_ask_ex1", "ev_ask_ex2", "ev_ask_ex3"], id: \.self) { key in
                    let example = L(key)
                    Button {
                        text = example
                        ask(example)
                    } label: {
                        Text(verbatim: "✨  \(example)").font(.dorr(14)).foregroundStyle(wa.ink).multilineTextAlignment(.leading)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .padding(14)
                            .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    .padding(.bottom, 8)
                }
                WaNote(text: L("ev_ask_note")).padding(.top, 6)
            }
            if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(wa.danger).padding(.top, 10) }
            if busy {
                ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 20).frame(height: 76).padding(.bottom, 10) }.padding(.top, 14)
            }
            if let a = answer, !busy { answerView(a) }
            Spacer().frame(height: 24)
        }
    }

    private func chips(_ f: DiscAskFiltersDto?) -> [String] {
        var out: [String] = []
        if let city = f?.city { out.append("📍 \(city)") }
        if let cats = f?.categories, !cats.isEmpty { out.append(cats.joined(separator: " · ")) }
        if let from = f?.from {
            let to: String? = f?.to == from ? nil : f?.to
            out.append("📅 " + [from, to].compactMap { $0 }.joined(separator: " → "))
        }
        if f?.free == true { out.append("🎟️ " + L("ev_free")) }
        if f?.family == true { out.append("👨‍👩‍👧 " + L("ev_family")) }
        return out
    }

    @ViewBuilder private func answerView(_ a: DiscAskDto) -> some View {
        let list = chips(a.filters)
        if !list.isEmpty {
            Text(L("ev_ask_understood")).font(.dorr(12, .bold)).foregroundStyle(wa.mut).padding(.top, 14).padding(.bottom, 6)
            FlowLayout { ForEach(list, id: \.self) { EvChip(text: $0, selected: true) {} } }
        }
        Spacer().frame(height: 12)
        EventList(items: a.items, loading: false, onOpen: { go(.event($0.id)) }, empty: L("ev_ask_none"))
    }

    private func ask(_ q: String) {
        let clean = q.trimmingCharacters(in: .whitespacesAndNewlines)
        guard clean.count >= 2, !busy else { return }
        busy = true
        error = nil
        Task {
            do {
                answer = try await EventsApi.ask(clean, timezone: evZone()).data
            } catch {
                self.error = error.apiFailure.message
            }
            busy = false
        }
    }
}

// MARK: - Saved

@MainActor
private struct EvSaved: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let go: (EvPage) -> Void
    @State private var past = false
    @State private var items: [DiscEventDto] = []
    @State private var page = 1
    @State private var more = false
    @State private var loading = true
    @State private var loadingMore = false

    var body: some View {
        EvFrame(title: L("ev_saved"), onBack: onBack, settingsHeader: true) {
            HStack(spacing: 0) {
                ForEach([(false, "ev_upcoming"), (true, "ev_past")], id: \.0) { p, label in
                    Button { past = p } label: {
                        Text(L(label)).font(.dorr(14, .bold)).foregroundStyle(p == past ? .white : wa.ink)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 10)
                            .background(p == past ? wa.red : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(4)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            Spacer().frame(height: 14)
            EventList(items: items, loading: loading && items.isEmpty, onOpen: { go(.event($0.id)) }, empty: L("ev_saved_empty"),
                      onInterest: { e in if !e.interested { items.removeAll { $0.id == e.id } } })
            if more {
                WaButton(text: L("ev_more"), style: .ghost, loading: loadingMore) { if !loadingMore { Task { await load(page + 1) } } }
                    .padding(.top, 4)
            }
            Spacer().frame(height: 24)
        }
        .task(id: past) { await load(1) }
    }

    private func load(_ next: Int) async {
        if next == 1 { loading = true; items = [] } else { loadingMore = true }
        do {
            let r = try await EventsApi.interests(timezone: evZone(), past: past ? 1 : 0, page: next)
            if next == 1 { items = [] }
            let known = Set(items.map(\.id))
            items += (r.data ?? []).filter { !known.contains($0.id) }
            page = next
            more = r.pagination?.hasMorePages == true
        } catch {
            if next == 1 { items = [] }
            more = false
        }
        loading = false
        loadingMore = false
    }
}

// MARK: - Settings

private enum EvSub { case menu, interests, alerts, follows }

/// Discover's settings: a menu of three rows like the calendar's, each opening its own page.
@MainActor
private struct EvSettings: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var store = EventsStore.shared
    let onBack: () -> Void
    @State private var prefs = DiscPrefsDto()
    @State private var follows: [DiscFollowDto] = []
    @State private var adding = false
    @State private var sub = EvSub.menu
    @State private var kinds: [DiscCategoryDto] = []
    @State private var kindsPage = 1
    @State private var kindsMore = false
    @State private var kindsLoading = false
    @State private var started = false

    var body: some View {
        let title = L(sub == .menu ? "ev_settings" : (sub == .interests ? "ev_my_interests" : (sub == .alerts ? "ev_alerts" : "ev_follows")))
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: title) { if sub == .menu { onBack() } else { sub = .menu } }
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) { content }
                        .padding(.horizontal, 14)
                        .padding(.top, 6)
                        .padding(.bottom, 16)
                }
            }
        }
        .onAppear {
            guard !started else { return }
            started = true
            prefs = store.home?.preferences ?? DiscPrefsDto()
            follows = store.home?.follows ?? []
        }
        .onDisappear { Task { await store.refresh() } }
        .sheet(isPresented: $adding) {
            // Places I follow are regions now (cities followed before still show).
            RegionPickerSheet(myCountryCode: store.home?.country, followed: Set(follows.filter { $0.kind == "region" }.map(\.targetId)),
                              onPick: { r in
                                  adding = false
                                  Task {
                                      if let list = try? await EventsApi.follow(kind: "region", targetId: r.id).data { follows = list }
                                      await store.refresh()
                                  }
                              }, onClose: { adding = false })
                .dorrThemed()
        }
    }

    @ViewBuilder private var content: some View {
        switch sub {
        case .menu:
            CalSettingsRow(icon: "star.circle.fill", title: L("ev_my_interests"), subtitle: L("ev_menu_interests_sub")) { sub = .interests }
            CalSettingsRow(icon: "bell.badge.fill", title: L("ev_alerts"), subtitle: L("ev_menu_alerts_sub")) { sub = .alerts }
            CalSettingsRow(icon: "mappin.circle.fill", title: L("ev_follows"), subtitle: L("ev_menu_follows_sub")) { sub = .follows }
        case .interests:
            Text(L("ev_my_interests_sub")).font(.dorr(12)).foregroundStyle(wa.mut).padding(.bottom, 10)
            SettingsToggleCard {
                ForEach(Array(kinds.enumerated()), id: \.element.id) { index, c in
                    let on = prefs.categories.contains(c.id)
                    Button {
                        var p = prefs
                        p.categories = on ? p.categories.filter { $0 != c.id } : p.categories + [c.id]
                        save(p)
                    } label: {
                        HStack(spacing: 10) {
                            Text(verbatim: c.emoji ?? "🎟️").font(.system(size: 17))
                                .frame(width: 34, height: 34)
                                .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                            Text(verbatim: c.name ?? "").font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                            Spacer()
                            if on { Image(systemName: "checkmark.circle.fill").font(.system(size: 18)).foregroundStyle(wa.red) }
                        }
                        .padding(.horizontal, 4).padding(.vertical, 12)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    if index != kinds.count - 1 || kindsMore { (wa.night ? wa.line : Color(hex: 0xF6E4E8)).frame(height: 1) }
                }
            }
            .waRise(0)
            .task { if kinds.isEmpty { await loadKinds(1) } }
            if kindsMore {
                WaButton(text: L("ev_more"), style: .ghost, loading: kindsLoading) { if !kindsLoading { Task { await loadKinds(kindsPage + 1) } } }
                    .padding(.top, 10)
            }
        case .alerts:
            SettingsToggleCard {
                SettingsToggleRow(icon: "bell.badge.fill", title: L("ev_alerts_on"), help: L("ev_alerts_sub"), on: prefs.alerts, divider: true) { on in
                    var p = prefs; p.alerts = on; save(p)
                }
                SettingsToggleRow(icon: "figure.2.and.child.holdinghands", title: L("ev_family_only"), help: nil, on: prefs.familyOnly, divider: false) { on in
                    var p = prefs; p.familyOnly = on; save(p)
                }
            }
            .waRise(0)
            if prefs.alerts {
                WaSectionTitle(title: L("ev_alert_days"), topPadding: 16).waRise(1)
                // One choice: an icon well, the days, a ✓ on the chosen one.
                SettingsToggleCard {
                    ForEach(Array([3, 7, 14, 30].enumerated()), id: \.element) { index, d in
                        Button {
                            var p = prefs; p.alertDays = d; save(p)
                        } label: {
                            HStack(spacing: 10) {
                                PinkIcon(icon: "calendar")
                                Text(verbatim: L("ev_days_ahead", d)).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                                Spacer()
                                if prefs.alertDays == d { Image(systemName: "checkmark.circle.fill").font(.system(size: 18)).foregroundStyle(wa.red) }
                            }
                            .padding(.horizontal, 4).padding(.vertical, 12)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        if index != 3 { (wa.night ? wa.line : Color(hex: 0xF6E4E8)).frame(height: 1) }
                    }
                }
                .waRise(1)
            }
        case .follows:
            // The add button on top, the places under it.
            WaButton(text: L("ev_follow_add"), icon: "plus") { adding = true }
            Text(L("ev_follows_sub")).font(.dorr(12)).foregroundStyle(wa.mut).padding(.vertical, 10)
            ForEach(follows) { f in
                HStack(spacing: 10) {
                    WalletFlag(code: f.country)
                    Text(verbatim: f.name ?? "").font(.dorr(13.5, .bold)).foregroundStyle(wa.ink)
                    Spacer()
                    Button {
                        Task {
                            if let list = try? await EventsApi.unfollow(f.id).data { follows = list }
                            await store.refresh()
                        }
                    } label: {
                        Image(systemName: "xmark").font(.system(size: 14, weight: .bold)).foregroundStyle(wa.mut).frame(width: 28, height: 28)
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("ev_unfollow"))
                }
                .padding(.horizontal, 12)
                .padding(.vertical, 10)
                .settingsSurface(14)
                .padding(.bottom, 8)
            }
            if follows.isEmpty { Text(L("ev_follows_empty")).font(.dorr(13)).foregroundStyle(wa.soft).padding(.vertical, 8) }
        }
    }

    private func loadKinds(_ next: Int) async {
        if next > 1 { kindsLoading = true }
        if let r = try? await EventsApi.categories(page: next) {
            if next == 1 { kinds = [] }
            let known = Set(kinds.map(\.id))
            kinds += (r.data ?? []).filter { !known.contains($0.id) }
            kindsPage = next
            kindsMore = r.pagination?.hasMorePages == true
        } else if next > 1 {
            kindsMore = false
        }
        kindsLoading = false
    }

    private func save(_ p: DiscPrefsDto) {
        prefs = p
        store.home?.preferences = p
        Task {
            let body: [String: JSONValue] = [
                "categories": .array(p.categories.map { .int($0) }), "alerts": .bool(p.alerts),
                "alert_days": .int(p.alertDays), "family_only": .bool(p.familyOnly),
            ]
            if let saved = try? await EventsApi.savePreferences(body).data {
                prefs = saved
                store.home?.preferences = saved
            }
        }
    }
}

// MARK: - Organizers

@MainActor
private struct EvOrganizer: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var store = EventsStore.shared
    let onBack: () -> Void
    let go: (EvPage) -> Void
    @State private var data: DiscOrganizerHomeDto?
    @State private var events: [DiscEventDto] = []
    @State private var page = 1
    @State private var more = false
    @State private var loadingMore = false
    @State private var editing = false
    @State private var query = ""
    @State private var reload = 0

    var body: some View {
        let org = data?.organizer
        let canAdd = data?.canSubmit == true && org != nil && org?.status != "rejected" && org?.status != "suspended"
        let showCta = canAdd && !editing
        ZStack(alignment: .bottom) {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("ev_organizer")) { if editing { editing = false } else { onBack() } }
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) {
                        if data == nil {
                            WaSkeleton(cornerRadius: 20).frame(height: 72)
                        } else if org == nil || editing {
                            OrganizerForm(existing: org) { editing = false; reload += 1 }
                        } else if let org {
                            profile(org)
                        }
                        Spacer().frame(height: 24)
                    }
                    .padding(.horizontal, 14)
                    .padding(.top, 6)
                    .padding(.bottom, showCta ? 110 : 16)
                }
                .scrollDismissesKeyboard(.interactively)
            }
            if showCta {
                WaButton(text: L("ev_org_add_event"), icon: "plus") { go(.submit) }
                    .padding(.horizontal, 16)
                    .padding(.top, 26)
                    .padding(.bottom, 16)
                    .background(LinearGradient(colors: [wa.bg.opacity(0), wa.bg.opacity(0.96)], startPoint: .top, endPoint: UnitPoint(x: 0.5, y: 0.4)).ignoresSafeArea(edges: .bottom))
            }
        }
        .task(id: "\(reload)|\(store.version)|\(query)") {
            if !query.isEmpty { try? await Task.sleep(nanoseconds: 300_000_000); guard !Task.isCancelled else { return } }
            await loadEvents(1)
        }
    }

    @ViewBuilder private func profile(_ org: DiscOrganizerDto) -> some View {
        let label: String = {
            switch org.status {
            case "verified": return L("ev_org_verified")
            case "rejected": return L("ev_org_rejected")
            case "suspended": return L("ev_org_suspended")
            default: return L("ev_org_pending")
            }
        }()
        HStack(spacing: 10) {
            Text(verbatim: "🎤").font(.system(size: 26))
                .frame(width: 56, height: 56)
                .background(wa.settingsCard, in: Circle())
                .overlay(Circle().stroke(wa.night ? wa.line : .clear, lineWidth: 1))
                .shadow(color: wa.night ? .clear : wa.red.opacity(0.1), radius: 6, y: 2)
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 6) {
                    Text(verbatim: org.name).font(.dorr(16, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    if org.verified || org.status == "verified" {
                        Image(systemName: "checkmark").font(.system(size: 8, weight: .heavy)).foregroundStyle(.white)
                            .frame(width: 15, height: 15).background(wa.red, in: Circle())
                            .accessibilityLabel(label)
                    }
                }
                if let phone = org.phone, !phone.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: "\u{2066}\(phone)\u{2069}").font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
                Text(verbatim: label).font(.dorr(11, .semibold)).foregroundStyle(wa.accent).lineLimit(1)
                    .padding(.horizontal, 8).padding(.vertical, 2)
                    .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: Capsule())
                    .padding(.top, 4)
            }
            Spacer(minLength: 8)
            Button { editing = true } label: {
                Image(systemName: "pencil").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.accent)
                    .frame(width: 36, height: 36)
                    .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("ev_org_edit"))
        }
        .waRise(0)
        if let note = org.reviewNote, !note.trimmingCharacters(in: .whitespaces).isEmpty {
            Text(verbatim: note).font(.dorr(12)).lineSpacing(4).foregroundStyle(wa.settingsMut).padding(.top, 8)
        }
        WaNote(text: L(org.verified ? "ev_org_verified_note" : "ev_org_pending_note")).padding(.top, 10)
        if data?.canSubmit == false { WaNote(text: L("ev_org_closed")).padding(.top, 8) }
        WaSectionTitle(title: L("ev_org_my_events"), topPadding: 16)
        DorrTextArea(value: Binding(get: { query }, set: { query = String($0.prefix(100)) }), placeholder: L("ev_org_search_hint"), icon: "magnifyingglass", singleLine: true)
            .padding(.bottom, 10)
        if events.isEmpty {
            WaCard {
                WaEmpty(icon: "calendar", tone: .gray, title: L("ev_none_title"),
                        text: L(query.trimmingCharacters(in: .whitespaces).isEmpty ? "ev_org_no_events" : "ev_org_search_none"))
            }
            .padding(.top, 4)
        } else {
            ForEach(Array(events.enumerated()), id: \.element.id) { i, e in
                EventRow(e: e, review: e.reviewStatus) { go(.event(e.id)) }.padding(.bottom, 8).waRise(min(i, 6))
            }
            if more {
                WaButton(text: L("ev_more"), style: .ghost, loading: loadingMore) { if !loadingMore { Task { await loadEvents(page + 1) } } }
                    .padding(.top, 4)
            }
        }
    }

    private func loadEvents(_ next: Int) async {
        if next > 1 { loadingMore = true }
        let q = query.trimmingCharacters(in: .whitespaces)
        do {
            let r = try await EventsApi.organizer(timezone: evZone(), page: next, q: q.isEmpty ? nil : q)
            data = r.data ?? DiscOrganizerHomeDto()
            if next == 1 { events = [] }
            let known = Set(events.map(\.id))
            events += (r.data?.events ?? []).filter { !known.contains($0.id) }
            page = next
            more = r.pagination?.hasMorePages == true
        } catch {
            if next == 1 && data == nil { data = DiscOrganizerHomeDto() }
            if next > 1 { more = false }
        }
        loadingMore = false
    }
}

@MainActor
private struct OrganizerForm: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var session: AuthSession
    let existing: DiscOrganizerDto?
    let onDone: () -> Void
    @State private var name = ""
    @State private var about = ""
    @State private var website = ""
    @State private var localPhone = ""
    @State private var dial = "+966"
    @State private var flag = "sa"
    @State private var phoneLength = 9
    @State private var phoneStartsWith = "5"
    @State private var email = ""
    @State private var busy = false
    @State private var error: String?
    @State private var started = false

    var body: some View {
        if existing == nil {
            Text(verbatim: "🎤").font(.system(size: 40)).waRise(0)
            Text(L("ev_org_intro_title")).font(.dorr(20, .heavy)).foregroundStyle(wa.ink).waRise(0)
            Text(L("ev_org_intro")).font(.dorr(13.5)).foregroundStyle(wa.mut).padding(.top, 4).padding(.bottom, 14).waRise(0)
        }
        VStack(alignment: .leading, spacing: 14) {
            DorrTextArea(value: Binding(get: { name }, set: { name = String($0.prefix(120)) }), label: L("ev_org_name"), placeholder: L("ev_org_name_hint"),
                         icon: "person.text.rectangle", singleLine: true)
            DorrTextArea(value: Binding(get: { about }, set: { about = String($0.prefix(1000)) }), label: L("ev_org_about"), placeholder: L("ev_org_about_hint"),
                         icon: "info.circle", minHeight: 90)
            DorrTextArea(value: Binding(get: { website }, set: { website = String($0.prefix(255)) }), label: L("ev_org_website"), placeholder: L("ev_org_website_hint"),
                         icon: "globe", singleLine: true)
                .keyboardType(.URL).textInputAutocapitalization(.never).autocorrectionDisabled()
            VStack(alignment: .leading, spacing: 0) {
                Text(L("ev_org_phone")).font(.dorr(13, .bold)).foregroundStyle(wa.ink).padding(.bottom, 8)
                OrgPhoneField(flag: flag, dial: dial, phone: localPhone, phoneLength: phoneLength, phoneStartsWith: phoneStartsWith) {
                    localPhone = String($0.filter(\.isNumber).prefix(phoneLength))
                }
            }
            VStack(alignment: .leading, spacing: 0) {
                Text(L("ev_org_email")).font(.dorr(13, .bold)).foregroundStyle(wa.ink).padding(.bottom, 8)
                DorrTextArea(value: Binding(get: { email }, set: { email = String($0.prefix(191)) }), placeholder: L("ev_org_email_hint"), icon: "envelope.fill", singleLine: true)
                    .keyboardType(.emailAddress).textInputAutocapitalization(.never).autocorrectionDisabled()
                    .environment(\.layoutDirection, .leftToRight)
            }
        }
        if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(wa.danger).padding(.top, 8) }
        WaNote(text: L("ev_org_review_note")).padding(.top, 12)
        WaButton(text: L(existing == nil ? "ev_org_apply" : "ev_save"), enabled: name.trimmingCharacters(in: .whitespaces).count >= 2, loading: busy, action: submit)
            .padding(.top, 14)
            .onAppear(perform: setUp)
    }

    private func setUp() {
        guard !started else { return }
        started = true
        name = existing?.name ?? ""
        about = existing?.about ?? ""
        website = existing?.website ?? ""
        email = existing?.email ?? ""
        if let country = CountryCache.resolveForPhone(existing?.phone) ?? CountryCache.resolveForPhone(session.user?.phone) ?? CountryCache.pickDefault() {
            let digits = country.dialCode.filter(\.isNumber)
            if !digits.isEmpty { dial = "+" + digits }
            let iso = (country.flag?.code ?? country.code).lowercased()
            if iso.count == 2 { flag = iso }
            phoneLength = country.phoneLength ?? phoneLength
            phoneStartsWith = country.phoneStartsWith ?? ""
            let all = (existing?.phone ?? "").filter(\.isNumber)
            localPhone = String((!digits.isEmpty && all.hasPrefix(digits) ? String(all.dropFirst(digits.count)) : all).prefix(phoneLength))
        }
    }

    private func submit() {
        busy = true
        error = nil
        Task {
            var body = ["name": name.trimmingCharacters(in: .whitespaces)]
            let fullPhone = localPhone.isEmpty ? "" : dial + localPhone
            for (k, v) in [("about", about), ("website", website), ("phone", fullPhone), ("email", email)] where !v.trimmingCharacters(in: .whitespaces).isEmpty {
                body[k] = v.trimmingCharacters(in: .whitespaces)
            }
            do {
                _ = try await EventsApi.applyOrganizer(body)
                onDone()
            } catch {
                self.error = error.apiFailure.message
            }
            busy = false
        }
    }
}

private struct OrgPhoneField: View {
    @Environment(\.wa) private var wa
    let flag: String
    let dial: String
    let phone: String
    let phoneLength: Int
    let phoneStartsWith: String
    let onPhone: (String) -> Void
    @FocusState private var focused: Bool

    var body: some View {
        let placeholder = phoneStartsWith.isEmpty ? String(repeating: "0", count: max(phoneLength, 1))
            : phoneStartsWith + String(repeating: "*", count: max(phoneLength - phoneStartsWith.count, 0))
        HStack(spacing: 8) {
            Image(systemName: "phone.fill").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red.opacity(0.8))
            (wa.night ? wa.line : Color(hex: 0xE5E7EB)).frame(width: 1, height: 18)
            FlagImage(code: flag)
            Text(verbatim: dial).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
            (wa.night ? wa.line : Color(hex: 0xE5E7EB)).frame(width: 1, height: 18)
            ZStack(alignment: .leading) {
                if phone.isEmpty { Text(verbatim: placeholder).font(.dorr(14, .medium)).kerning(0.5).foregroundStyle(Color(hex: 0xC5CAD3)) }
                TextField("", text: Binding(get: { phone }, set: onPhone)).font(.dorr(14, .medium)).kerning(0.5)
                    .foregroundStyle(wa.settingsInk).keyboardType(.phonePad).tint(wa.red).focused($focused)
            }
        }
        .padding(.horizontal, 14)
        .frame(height: 46)
        .background(wa.night ? wa.bg : (focused ? wa.surface : Color(hex: 0xFBF7F8)), in: Capsule())
        .overlay(Capsule().stroke(focused ? wa.red : (wa.night ? wa.line : Color(hex: 0xF3D5DB)), lineWidth: focused ? 1.5 : 1))
        .environment(\.layoutDirection, .leftToRight)
    }
}

/// A new event from an organizer: its own local time where it happens; reviewed unless the organizer is verified.
@MainActor
private struct EvSubmit: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var store = EventsStore.shared
    let onBack: () -> Void
    @State private var title = ""
    @State private var categoryId: Int?
    @State private var city: DiscCityDto?
    @State private var date = calAddDays(calToday(), 7)
    @State private var time = Calendar.current.date(bySettingHour: 20, minute: 0, second: 0, of: Date()) ?? Date()
    @State private var endTime: Date?
    @State private var venue = ""
    @State private var address = ""
    @State private var free = true
    @State private var price = ""
    @State private var booking = ""
    @State private var source = ""
    @State private var familyOk = false
    @State private var description = ""
    @State private var cover: UIImage?
    @State private var coverData: Data?
    @State private var coverItem: PhotosPickerItem?
    @State private var coverPicking = false
    @State private var picking: String?
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        let categories = store.home?.categories ?? []
        let kindName: (DiscCategoryDto) -> String = { [$0.emoji, $0.name].compactMap { $0 }.joined(separator: " ") }
        EvFrame(title: L("ev_org_add_event"), onBack: onBack, settingsHeader: true, hasCta: true, cta: {
            WaButton(text: L("ev_submit"), icon: "plus", enabled: title.trimmingCharacters(in: .whitespaces).count >= 3 && categoryId != nil && city != nil,
                     loading: busy, action: submit)
        }) {
            CoverField(cover: cover, onPick: { coverPicking = true }, onRemove: { cover = nil; coverData = nil })
            VStack(alignment: .leading, spacing: 14) {
                DorrTextArea(value: Binding(get: { title }, set: { title = String($0.prefix(160)) }), label: L("ev_f_title"), placeholder: L("ev_f_title_hint"),
                             icon: "textformat", singleLine: true)
                let kind = categories.first { $0.id == categoryId }
                EvSelect(label: L("ev_f_category"), icon: "square.grid.2x2.fill", value: kind.map(kindName) ?? L("ev_f_category"),
                         options: categories.map { c in (kindName(c), { categoryId = c.id }) }, selected: kind.map(kindName))
                PickRow(emoji: "📍", label: L("ev_f_city"), value: city.map { [$0.name, $0.country].compactMap { $0 }.joined(separator: " · ") } ?? L("ev_pick_city")) { picking = "city" }
                HStack(spacing: 8) {
                    PickRow(emoji: "📅", label: L("ev_f_date"), value: dayText(date)) { picking = "date" }.layoutPriority(1.3)
                    PickRow(emoji: "🕗", label: L("ev_f_time"), value: shortTime(time)) { picking = "time" }
                }
                VStack(alignment: .leading, spacing: 6) {
                    PickRow(emoji: "🏁", label: L("ev_f_end"), value: endTime.map(shortTime) ?? L("ev_f_end_none")) { picking = "end" }
                    if let z = city?.timezone { Text(L("ev_f_local_hint", zoneCity(z))).font(.dorr(12)).foregroundStyle(wa.mut) }
                }
                DorrTextArea(value: Binding(get: { venue }, set: { venue = String($0.prefix(160)) }), label: L("ev_f_venue"), placeholder: L("ev_f_venue_hint"),
                             icon: "mappin.and.ellipse", singleLine: true)
                DorrTextArea(value: Binding(get: { address }, set: { address = String($0.prefix(255)) }), label: L("ev_f_address"), placeholder: L("ev_f_address_hint"),
                             icon: "map", singleLine: true)
                WaCard(padding: 8) {
                    // Side by side: two short switches share a row.
                    HStack(spacing: 0) {
                        ToggleLine(title: L("ev_free"), on: free) { free = $0 }
                        ToggleLine(title: L("ev_family"), on: familyOk) { familyOk = $0 }
                    }
                }
                if !free {
                    let currency = (city?.currency?.symbol ?? "").isEmpty ? (city?.currency?.code ?? "") : city!.currency!.symbol!
                    DorrTextArea(value: Binding(get: { price }, set: { price = String($0.prefix(80)) }), label: L("ev_f_price"), placeholder: L("ev_f_price_hint"),
                                 icon: "banknote", singleLine: true)
                        .keyboardType(.decimalPad)
                        .overlay(alignment: .bottomTrailing) {
                            if !currency.isEmpty { Text(verbatim: currency).font(.dorr(13, .bold)).foregroundStyle(wa.mut).padding(.trailing, 14).padding(.bottom, 14) }
                        }
                }
                DorrTextArea(value: Binding(get: { booking }, set: { booking = String($0.prefix(500)) }), label: L("ev_f_booking"), placeholder: L("ev_f_booking_hint"),
                             icon: "ticket.fill", singleLine: true)
                    .keyboardType(.URL).textInputAutocapitalization(.never)
                DorrTextArea(value: Binding(get: { source }, set: { source = String($0.prefix(500)) }), label: L("ev_f_source"), placeholder: L("ev_f_source_hint"),
                             icon: "link", singleLine: true)
                    .keyboardType(.URL).textInputAutocapitalization(.never)
                DorrTextArea(value: Binding(get: { description }, set: { description = String($0.prefix(5000)) }), label: L("ev_f_description"),
                             placeholder: L("ev_f_description_hint"), icon: "text.alignleft", minHeight: 90)
            }
            .padding(.top, 14)
            if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(wa.danger).padding(.top, 10) }
            Spacer().frame(height: 24)
        }
        .photosPicker(isPresented: $coverPicking, selection: $coverItem, matching: .images)
        .onChange(of: coverItem) { item in
            guard let item else { return }
            coverItem = nil
            Task {
                guard let data = await evCoverData(item) else { return }
                coverData = data
                cover = UIImage(data: data)
            }
        }
        .sheet(isPresented: Binding(get: { picking == "city" }, set: { if !$0 { picking = nil } })) {
            CityPickerSheet(onDismiss: { picking = nil }) { city = $0; picking = nil }.dorrThemed()
        }
        .dorrDialog(isPresented: Binding(get: { picking == "date" }, set: { if !$0 { picking = nil } })) {
            CalDatePickerDialog(initial: date, onDismiss: { picking = nil }) { date = $0; picking = nil }
        }
        .dorrDialog(isPresented: Binding(get: { picking == "time" || picking == "end" }, set: { if !$0 { picking = nil } })) {
            CalTimePickerDialog(initial: picking == "end" ? (endTime ?? time.addingTimeInterval(7200)) : time, onDismiss: { picking = nil }) { t in
                if picking == "end" { endTime = t } else { time = t }
                picking = nil
            }
        }
    }

    private func shortTime(_ d: Date) -> String {
        let f = DateFormatter()
        f.locale = Locale(identifier: AppLocale.current + "@numbers=latn")
        f.timeStyle = .short
        return f.string(from: d)
    }

    private func hm(_ d: Date) -> String {
        let c = Calendar.current.dateComponents([.hour, .minute], from: d)
        return String(format: "%02d:%02d", c.hour ?? 0, c.minute ?? 0)
    }

    private func submit() {
        busy = true
        error = nil
        var body: [String: JSONValue] = [
            "title": .string(title.trimmingCharacters(in: .whitespaces)),
            "starts_at": .string("\(calKey(date)) \(hm(time))"),
            "is_free": .bool(free),
            "family_friendly": .bool(familyOk),
        ]
        if let categoryId { body["category_id"] = .int(categoryId) }
        if let city { body["city_id"] = .int(city.id) }
        if let endTime {
            let endsNextDay = hm(endTime) < hm(time)
            body["ends_at"] = .string("\(calKey(endsNextDay ? calAddDays(date, 1) : date)) \(hm(endTime))")
        }
        if !free && !price.trimmingCharacters(in: .whitespaces).isEmpty { body["price_text"] = .string(price.trimmingCharacters(in: .whitespaces)) }
        for (k, v) in [("venue", venue), ("address", address), ("booking_url", booking), ("source_url", source), ("description", description)]
            where !v.trimmingCharacters(in: .whitespaces).isEmpty {
            body[k] = .string(v.trimmingCharacters(in: .whitespacesAndNewlines))
        }
        Task {
            do {
                let e = try await EventsApi.submit(body, cover: coverData, timezone: evZone()).data
                AppToast.show(L(e?.reviewStatus == "approved" ? "ev_submit_live" : "ev_submit_pending"), long: true)
                await EventsStore.shared.changed()
                onBack()
            } catch {
                self.error = error.apiFailure.message
            }
            busy = false
        }
    }
}

/// The cover box of the new-event form: a dashed invitation, or the picked photo with change / remove.
private struct CoverField: View {
    @Environment(\.wa) private var wa
    let cover: UIImage?
    let onPick: () -> Void
    let onRemove: () -> Void

    var body: some View {
        Button(action: onPick) {
            ZStack {
                wa.field
                if let cover {
                    Image(uiImage: cover).resizable().scaledToFill()
                } else {
                    VStack(spacing: 0) {
                        Image(systemName: "photo.badge.plus").font(.system(size: 30)).foregroundStyle(wa.red)
                        Text(L("ev_cover_add")).font(.dorr(14, .bold)).foregroundStyle(wa.ink).padding(.top, 6)
                        Text(L("ev_cover_hint")).font(.dorr(11.5)).foregroundStyle(wa.mut)
                    }
                }
            }
            .aspectRatio(16 / 9, contentMode: .fit)
            .frame(maxWidth: .infinity)
            .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
            .overlay {
                if cover == nil {
                    RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(wa.line, style: StrokeStyle(lineWidth: 1.5, dash: [6, 4]))
                }
            }
        }
        .buttonStyle(.plain)
        .overlay(alignment: .topTrailing) {
            if cover != nil {
                HStack(spacing: 8) {
                    ForEach([("pencil", onPick), ("xmark", onRemove)], id: \.0) { icon, action in
                        Button(action: action) {
                            Image(systemName: icon).font(.system(size: 15, weight: .bold)).foregroundStyle(.white)
                                .frame(width: 34, height: 34).background(Color.black.opacity(0.55), in: Circle())
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(10)
            }
        }
    }
}
