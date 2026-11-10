import PhotosUI
import SwiftUI
import UIKit

// androidApp `portals/PortalsScreen.kt` + the portals circle of `portals/HomeCircles.kt`.

private enum PortalPage: Equatable {
    case directory
    case mine
    case edit(PortalDto?)
    case plans(PortalDto)

    var key: String {
        switch self {
        case .directory: return "directory"
        case .mine: return "mine"
        case .edit(let p): return "edit-\(p?.id ?? "new")"
        case .plans(let p): return "plans-\(p.id)"
        }
    }
}

/// Merchant portals: the page everyone browses (search, grouped by category, most viewed first), my portals,
/// adding one (its name and description per language, the AI filling the other languages), and putting it on
/// the page with a package — paid on the one payment screen.
@MainActor
struct PortalsScreen: View {
    @Environment(\.wa) private var wa
    let onExit: () -> Void
    @State private var stack: [PortalPage] = [.directory]
    @State private var forward = true
    /// Bumped when my portals changed (saved, paid) so the lists reload.
    @State private var version = 0

    var body: some View {
        ZStack {
            page(stack.last ?? .directory)
                .id(stack.last?.key ?? "directory")
                .transition(.asymmetric(
                    insertion: .modifier(active: PortalSlide(fraction: forward ? 1.0 / 6 : -1.0 / 6), identity: PortalSlide(fraction: 0)),
                    removal: .modifier(active: PortalSlide(fraction: forward ? -0.1 : 0.1), identity: PortalSlide(fraction: 0))
                ))
        }
        .background(wa.bg.ignoresSafeArea())
    }

    @ViewBuilder private func page(_ page: PortalPage) -> some View {
        switch page {
        case .directory:
            PortalDirectory(onBack: pop, onMine: { push(.mine) }, onAdd: { push(.edit(nil)) })
        case .mine:
            MyPortals(version: version, onBack: pop, onAdd: { push(.edit(nil)) }, onEdit: { push(.edit($0)) }, onPlans: { push(.plans($0)) })
        case .edit(let portal):
            PortalEditor(portal: portal, onBack: pop, onChanged: { version += 1 }) { saved, created in
                version += 1
                withAnimation(.easeOut(duration: 0.34)) {
                    forward = false
                    stack.removeLast()
                    if stack.last != .mine { forward = true; stack.append(.mine) }
                    // A new portal goes straight to choosing how long it shows on the page.
                    if created { forward = true; stack.append(.plans(saved)) }
                }
            }
        case .plans(let portal):
            PortalPlans(portal: portal, onBack: pop) {
                version += 1
                pop()
            }
        }
    }

    private func push(_ page: PortalPage) {
        forward = true
        withAnimation(.easeOut(duration: 0.34)) { stack.append(page) }
    }

    private func pop() {
        if stack.count <= 1 { onExit(); return }
        forward = false
        withAnimation(.easeOut(duration: 0.3)) { _ = stack.removeLast() }
    }
}

/// Slides by a share of the width (mirrored in Arabic by SwiftUI) while fading.
private struct PortalSlide: ViewModifier {
    let fraction: CGFloat
    func body(content: Content) -> some View {
        GeometryReader { proxy in
            content.offset(x: proxy.size.width * fraction).opacity(fraction == 0 ? 1 : 0)
        }
    }
}

private func compact(_ n: Int) -> String {
    if n >= 1_000_000 { return String(format: "%.1fM", Double(n) / 1_000_000).replacingOccurrences(of: ".0M", with: "M") }
    if n >= 1_000 { return String(format: "%.1fK", Double(n) / 1_000).replacingOccurrences(of: ".0K", with: "K") }
    return String(n)
}

// MARK: - The page everyone sees

@MainActor
private struct PortalDirectory: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let onMine: () -> Void
    let onAdd: () -> Void
    @State private var search = ""
    /// The categories picked in the filter (none = all) — sent to the server with the search.
    @State private var selected: Set<Int> = []
    @State private var filtering = false
    @State private var categories: [CategoryDto] = []
    /// One list, 15 at a time from the server (most viewed first); the next page as its end comes into view.
    @State private var portals: [PortalDto]?
    @State private var page = 1
    @State private var hasMore = false
    @State private var loadingMore = false
    @State private var error: String?

    /// What the list is for: a new one starts again from the first page.
    private struct Query: Equatable { var search: String; var selected: Set<Int> }

    var body: some View {
        // «My portals» — the same round button as back.
        WaPage(title: L("pt_title"), onBack: onBack, actions: {
            WaOutlineCircleButton(icon: "storefront.fill", label: L("pt_mine"), action: onMine)
        }) {
            PortalsHero(onAdd: onAdd).waRise(0)
            // The search, and beside it the filter (by category, several at once).
            HStack(spacing: 8) {
                DorrTextArea(value: Binding(get: { search }, set: { search = String($0.prefix(60)) }), placeholder: L("pt_search"),
                             icon: "magnifyingglass", singleLine: true, singleLineHeight: 40)
                if !categories.isEmpty { filterButton }
            }
            .padding(.top, 12).waRise(1)
            // What's picked, in the chips' place — each with × to take it off.
            let picked = categories.filter { selected.contains($0.id) }
            if !picked.isEmpty {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(picked) { c in selectedChip(c) }
                    }
                    .padding(.vertical, 2)
                }
                .padding(.top, 10).waRise(2)
            }
            WaError(message: error)
            if let list = portals {
                if list.isEmpty {
                    WaCard {
                        WaEmpty(icon: "storefront.fill", tone: .red,
                                title: L(search.trimmingCharacters(in: .whitespaces).isEmpty && selected.isEmpty ? "pt_empty_title" : "pt_no_results"),
                                text: L("pt_empty_text")) {
                            WaButton(text: L("pt_add_mine"), icon: "plus.rectangle.on.rectangle", action: onAdd)
                        }
                    }
                    .padding(.top, 16)
                } else {
                    ForEach(Array(list.enumerated()), id: \.element.id) { i, portal in
                        PortalCard(portal: portal) { open(portal) }
                            .padding(.bottom, 8)
                            .padding(.top, i == 0 ? 12 : 0)
                            .waRise(3 + min(i, 12))
                            // The end coming into view loads the next 15.
                            .onAppear { if i >= list.count - 3 { Task { await loadMore() } } }
                    }
                    if loadingMore {
                        ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 80).padding(.bottom, 8) }
                    }
                }
            } else {
                ForEach(0..<4, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 80).padding(.top, 12) }
            }
        }
        .task { categories = (try? await DiscoverApi.categories().data) ?? [] }
        .sheet(isPresented: $filtering) {
            PortalFilterSheet(categories: categories, selected: selected) { selected = $0; filtering = false }
                .presentationDetents([.large])
                .presentationDragIndicator(.hidden)
        }
        // A new search or filter starts again from the first page (the search waits until typing stops).
        .task(id: Query(search: search, selected: selected)) {
            if !search.isEmpty {
                try? await Task.sleep(nanoseconds: 300_000_000)
                guard !Task.isCancelled else { return }
            }
            do {
                let r = try await fetch(1)
                guard !Task.isCancelled else { return }
                portals = r.data ?? []
                page = 1
                hasMore = r.pagination?.hasMorePages == true
                error = nil
            } catch {
                if error is CancellationError { return }
                self.error = error.apiFailure.message
            }
        }
    }

    private func fetch(_ p: Int) async throws -> ApiEnvelope<[PortalDto]> {
        let q = search.trimmingCharacters(in: .whitespaces)
        return try await DiscoverApi.portals(page: p, search: q.isEmpty ? nil : q, categoryIds: selected.sorted())
    }

    private func loadMore() async {
        guard hasMore, !loadingMore else { return }
        loadingMore = true
        defer { loadingMore = false }
        let asked = Query(search: search, selected: selected)
        guard let r = try? await fetch(page + 1) else { return }
        // Dropped if the search or filter changed meanwhile (that load starts from page 1).
        guard asked == Query(search: search, selected: selected) else { return }
        let seen = Set((portals ?? []).map(\.id))
        portals = (portals ?? []) + (r.data ?? []).filter { !seen.contains($0.id) }
        page += 1
        hasMore = r.pagination?.hasMorePages == true
    }

    /// The filter beside the search: the same height, a count on it while categories are picked.
    private var filterButton: some View {
        let on = !selected.isEmpty
        return Button { filtering = true } label: {
            Image(systemName: "slider.horizontal.3").font(.system(size: 16, weight: .semibold)).foregroundStyle(wa.red)
                .frame(width: 40, height: 40)
                .background(on ? wa.red.opacity(0.12) : wa.field, in: Circle())
                .overlay(Circle().stroke(on ? wa.red.opacity(0.5) : wa.line, lineWidth: 1))
                .overlay(alignment: .topTrailing) {
                    if on {
                        Text(verbatim: "\(selected.count)").font(.system(size: 9, weight: .bold)).foregroundStyle(.white)
                            .frame(width: 16, height: 16).background(wa.red, in: Circle()).overlay(Circle().stroke(wa.bg, lineWidth: 1.5))
                            .offset(x: 3, y: -3)
                    }
                }
        }
        .buttonStyle(.plain)
        .accessibilityLabel(L("pt_filter"))
    }

    /// A picked category, in the chips' place: its icon, its name, × to take it off.
    private func selectedChip(_ c: CategoryDto) -> some View {
        Button { selected.remove(c.id) } label: {
            HStack(spacing: 6) {
                CategoryIcon(url: c.icon, size: 24)
                Text(verbatim: c.name ?? "").font(.dorr(13, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                Image(systemName: "xmark").font(.system(size: 11, weight: .bold)).foregroundStyle(wa.red)
            }
            .padding(.leading, 6).padding(.trailing, 10).padding(.vertical, 6)
            .background(wa.red.opacity(0.12), in: Capsule())
            .overlay(Capsule().stroke(wa.red.opacity(0.4), lineWidth: 1))
        }
        .buttonStyle(.plain)
        .accessibilityLabel(L("pt_filter_remove"))
    }

    private func open(_ portal: PortalDto) {
        Task {
            let opened = (try? await DiscoverApi.openPortal(portal.id).data) ?? portal
            if let url = URL(string: opened.websiteUrl) { await UIApplication.shared.open(url) }
            // The count moved: show it.
            portals = portals?.map { p in
                var p = p
                if p.id == opened.id { p.viewsCount = opened.viewsCount }
                return p
            }
        }
    }
}

private struct PortalsHero: View {
    @Environment(\.wa) private var wa
    let onAdd: () -> Void

    // The icon and the title at the start, «Add your portal» at the end; a line; then the note, highlighted.
    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 10) {
                Image(systemName: "storefront.fill").font(.system(size: 21)).foregroundStyle(.white)
                    .frame(width: 44, height: 44)
                    .background(Color.white.opacity(0.18), in: Circle())
                Text(L("pt_hero")).font(.dorr(15.5, .heavy)).foregroundStyle(.white).lineLimit(2)
                    .frame(maxWidth: .infinity, alignment: .leading)
                // The same soft white highlight as the icon; compact (＋ and the words) so the title keeps to one line.
                Button(action: onAdd) {
                    HStack(spacing: 3) {
                        Image(systemName: "plus").font(.system(size: 13, weight: .bold))
                        Text(L("pt_add_short")).font(.dorr(12.5, .heavy)).lineLimit(1)
                    }
                    .foregroundStyle(.white)
                    .padding(.leading, 8).padding(.trailing, 11).padding(.vertical, 6)
                    .background(Color.white.opacity(0.18), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(Color.white.opacity(0.3), lineWidth: 1))
                }
                .buttonStyle(WaPressStyle())
            }
            Rectangle().fill(Color.white.opacity(0.22)).frame(height: 1).padding(.top, 14).padding(.bottom, 12)
            // The note: only its icon highlighted (the same soft white circle), the text plain white.
            HStack(spacing: 10) {
                Image(systemName: "info").font(.system(size: 13, weight: .bold)).foregroundStyle(.white)
                    .frame(width: 30, height: 30).background(Color.white.opacity(0.18), in: Circle())
                Text(L("pt_hero_sub")).font(.dorr(12.5)).foregroundStyle(.white)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
        .padding(16)
        .background(wa.heroGradient, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
    }
}

private struct CategoryChip: View {
    @Environment(\.wa) private var wa
    let icon: String?
    let label: String
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                if let icon {
                    AsyncImage(url: ApiClient.mediaUrl(icon)) { phase in phase.image?.resizable().scaledToFit() }
                        .frame(width: 20, height: 20)
                        .clipShape(RoundedRectangle(cornerRadius: 6, style: .continuous))
                }
                Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(selected ? .white : wa.ink)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 7)
            .background(selected ? wa.red : wa.surface, in: Capsule())
            .overlay(Capsule().stroke(selected ? .clear : wa.line, lineWidth: 1))
            .animation(.easeInOut(duration: 0.2), value: selected)
        }
        .buttonStyle(.plain)
    }
}

/// Pick the category: a search, then the list — 15 at a time, the next 15 as it scrolls — each row a soft card with
/// its icon, its name and a radio at the other end; «No category» first. Opens at full height.
private struct PortalCategorySheet: View {
    @Environment(\.wa) private var wa
    let categories: [CategoryDto]
    let selected: Int?
    let onPick: (Int?) -> Void
    @State private var query = ""
    @State private var limit = 15

    var body: some View {
        let q = query.trimmingCharacters(in: .whitespaces)
        let shown = categories.filter { q.isEmpty || ($0.name ?? "").localizedCaseInsensitiveContains(q) }
        let page = Array(shown.prefix(limit))
        PortalSheet {
            VStack(alignment: .leading, spacing: 12) {
                SheetTitle(title: L("pt_category_pick"))
                DorrTextArea(value: $query, placeholder: L("pt_category_search"), icon: "magnifyingglass", singleLine: true)
                ScrollView {
                    LazyVStack(spacing: 8) {
                        if q.isEmpty { row(nil, L("pt_category_none"), selected == nil) { onPick(nil) } }
                        ForEach(Array(page.enumerated()), id: \.element.id) { i, c in
                            row(c.icon, c.name ?? "", selected == c.id) { onPick(c.id) }
                                // Near the end → 15 more (the list is already here; it's drawn a page at a time).
                                .onAppear { if i >= page.count - 3 && limit < shown.count { limit += 15 } }
                        }
                        if shown.isEmpty {
                            Text(L("ch_no_results")).font(.dorr(13.5)).foregroundStyle(wa.mut).frame(maxWidth: .infinity).padding(.vertical, 24)
                        }
                    }
                }
            }
            .padding(.horizontal, 20).padding(.top, 6).padding(.bottom, 20)
        }
        .onChange(of: query) { _ in limit = 15 }
    }

    /// A category as a soft card: the icon and the name at the start, a radio at the end (filled on the one picked).
    private func row(_ icon: String?, _ name: String, _ on: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 12) {
                CategoryIcon(url: icon, size: 34)
                Text(verbatim: name).font(.dorr(15, on ? .heavy : .semibold)).foregroundStyle(wa.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                ZStack {
                    Circle().stroke(on ? wa.red : wa.soft, lineWidth: 2).frame(width: 22, height: 22)
                    if on { Circle().fill(wa.red).frame(width: 11, height: 11) }
                }
            }
            .padding(.horizontal, 12).padding(.vertical, 10)
            .background(on ? wa.red.opacity(0.1) : wa.field, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(on ? wa.red.opacity(0.5) : wa.line, lineWidth: 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// Filter by category: «All» first (picking it clears the rest), then the categories — several can be ticked — each a
/// soft card with a checkbox at the other end; «Done» applies. Opens at full height.
private struct PortalFilterSheet: View {
    @Environment(\.wa) private var wa
    let categories: [CategoryDto]
    let onApply: (Set<Int>) -> Void
    @State private var picked: Set<Int>

    init(categories: [CategoryDto], selected: Set<Int>, onApply: @escaping (Set<Int>) -> Void) {
        self.categories = categories
        self.onApply = onApply
        _picked = State(initialValue: selected)
    }

    var body: some View {
        PortalSheet {
            VStack(alignment: .leading, spacing: 12) {
                SheetTitle(title: L("pt_filter_title"))
                ScrollView {
                    LazyVStack(spacing: 8) {
                        row(nil, L("pt_all"), picked.isEmpty) { picked = [] }
                        ForEach(categories) { c in
                            row(c.icon, c.name ?? "", picked.contains(c.id)) {
                                if picked.contains(c.id) { picked.remove(c.id) } else { picked.insert(c.id) }
                            }
                        }
                    }
                }
                WaButton(text: L("st_done"), action: { onApply(picked) })
            }
            .padding(.horizontal, 20).padding(.top, 6).padding(.bottom, 20)
        }
    }

    /// A category to tick: a soft card, the icon and the name at the start, a checkbox at the end.
    private func row(_ icon: String?, _ name: String, _ on: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 12) {
                if let icon { CategoryIcon(url: icon, size: 34) } else {
                    Image(systemName: "square.grid.2x2.fill").font(.system(size: 16)).foregroundStyle(wa.red)
                        .frame(width: 34, height: 34).background(wa.red.opacity(0.1), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                }
                Text(verbatim: name).font(.dorr(15, on ? .heavy : .semibold)).foregroundStyle(wa.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                Image(systemName: on ? "checkmark.square.fill" : "square").font(.system(size: 22)).foregroundStyle(on ? wa.red : wa.soft)
            }
            .padding(.horizontal, 12).padding(.vertical, 10)
            .background(on ? wa.red.opacity(0.1) : wa.field, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(on ? wa.red.opacity(0.5) : wa.line, lineWidth: 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

private struct CategoryIcon: View {
    @Environment(\.wa) private var wa
    let url: String?
    let size: CGFloat

    var body: some View {
        ZStack {
            if let url {
                AsyncImage(url: ApiClient.mediaUrl(url)) { phase in phase.image?.resizable().scaledToFill() }
            } else {
                Image(systemName: "square.grid.2x2.fill").font(.system(size: size * 0.5)).foregroundStyle(wa.red)
            }
        }
        .frame(width: size, height: size)
        .background(wa.red.opacity(0.1))
        .clipShape(RoundedRectangle(cornerRadius: size * 0.28, style: .continuous))
    }
}

struct PortalLogo: View {
    @Environment(\.wa) private var wa
    let url: String?
    let size: CGFloat

    /// Its full address (as the category icons), and the shop icon on a soft red tile while it loads, when it fails,
    /// or when there's none (never an empty gap beside the name) — androidApp `PortalLogo`.
    var body: some View {
        ZStack {
            Image(systemName: "storefront.fill").font(.system(size: size * 0.4)).foregroundStyle(wa.red)
            if let link = ApiClient.mediaUrl(url) {
                AsyncImage(url: link) { phase in phase.image?.resizable().scaledToFill() }
            }
        }
        .frame(width: size, height: size)
        .background(wa.red.opacity(0.1))
        .clipShape(RoundedRectangle(cornerRadius: size / 3.5, style: .continuous))
    }
}

private struct PortalCard: View {
    @Environment(\.wa) private var wa
    let portal: PortalDto
    let action: () -> Void

    var body: some View {
        // Top: the logo, the name and the description beside it, 🌐 at the other end. Under them: the category (under
        // the logo) and, across from it, the views.
        Button(action: action) {
            VStack(alignment: .leading, spacing: 8) {
                HStack(alignment: .top, spacing: 10) {
                    PortalLogo(url: portal.logo, size: 46)
                    VStack(alignment: .leading, spacing: 1) {
                        Text(verbatim: portal.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                        if let d = portal.description, !d.trimmingCharacters(in: .whitespaces).isEmpty {
                            Text(verbatim: d).font(.dorr(12)).foregroundStyle(wa.mut).lineLimit(1)
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Image(systemName: "globe").font(.system(size: 15)).foregroundStyle(wa.soft)
                }
                HStack(spacing: 4) {
                    if let name = portal.category?.name {
                        Text(verbatim: name).font(.dorr(11, .bold)).foregroundStyle(wa.red).lineLimit(1)
                            .padding(.horizontal, 9).padding(.vertical, 2)
                            .background(wa.red.opacity(0.1), in: Capsule())
                    }
                    Spacer(minLength: 0)
                    Image(systemName: "eye.fill").font(.system(size: 11)).foregroundStyle(wa.soft)
                    Text(verbatim: L("pt_views", compact(portal.viewsCount))).font(.dorr(11.5, .semibold)).foregroundStyle(wa.soft)
                }
            }
            .padding(.horizontal, 12).padding(.vertical, 10)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(WaPressStyle(scale: 0.98))
    }
}

// MARK: - My portals

@MainActor
private struct MyPortals: View {
    @Environment(\.wa) private var wa
    let version: Int
    let onBack: () -> Void
    let onAdd: () -> Void
    let onEdit: (PortalDto) -> Void
    let onPlans: (PortalDto) -> Void
    @State private var mine: [PortalDto]?
    @State private var error: String?
    @State private var deleting: PortalDto?
    @State private var menuFor: PortalDto?
    /// «Subscription history» picked in the menu: opens once the menu has gone (one sheet at a time).
    @State private var historyNext: PortalDto?
    @State private var historyFor: PortalDto?
    @State private var limit = 15
    /// The state chips: nil = all.
    @State private var stateFilter: PortalState?
    @State private var copied = false

    var body: some View {
        WaPage(title: L("pt_mine"), onBack: onBack, cta: {
            WaButton(text: L("pt_add_new"), icon: "plus.rectangle.on.rectangle", action: onAdd)
        }) {
            WaError(message: error)
            if let list = mine {
                if list.isEmpty {
                    WaCard { WaEmpty(icon: "storefront.fill", tone: .red, title: L("pt_mine_empty"), text: L("pt_mine_empty_text")) }
                } else {
                    // At a glance: how many, how many showing now, all their views.
                    HStack(spacing: 8) {
                        statTile("storefront.fill", "\(list.count)", L("pt_stat_portals"), wa.ink)
                        statTile("checkmark.circle.fill", "\(list.filter { $0.isListed == true }.count)", L("pt_stat_active"), wa.green)
                        statTile("eye.fill", compact(list.reduce(0) { $0 + $1.viewsCount }), L("pt_stat_views"), wa.ink)
                    }
                    .waRise(0)
                    // By state — each with its count; an empty one isn't shown.
                    let counts = Dictionary(grouping: list, by: { portalState($0) }).mapValues(\.count)
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 8) {
                            stateChip(L("pt_f_all"), list.count, stateFilter == nil) { stateFilter = nil }
                            ForEach([(PortalState.active, "pt_f_active"), (.ending, "pt_f_ending"), (.ended, "pt_f_ended"), (.never, "pt_f_never"), (.off, "pt_f_off")], id: \.1) { state, key in
                                if let n = counts[state], n > 0 {
                                    stateChip(L(key), n, stateFilter == state) { stateFilter = stateFilter == state ? nil : state }
                                }
                            }
                        }
                        .padding(.vertical, 2)
                    }
                    .padding(.top, 10).padding(.bottom, 12)
                    let shown = stateFilter.map { f in list.filter { portalState($0) == f } } ?? list
                    // 15 at a time, the next 15 as the end comes into view.
                    let page = Array(shown.prefix(limit))
                    ForEach(Array(page.enumerated()), id: \.element.id) { i, portal in
                        MyPortalCard(portal: portal, onMenu: { menuFor = portal }, onRenew: { onPlans(portal) })
                            .padding(.bottom, 8)
                            .waRise(1 + i)
                            .onAppear { if i >= page.count - 3 && limit < shown.count { limit += 15 } }
                    }
                }
            } else {
                ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 96).padding(.bottom, 10) }
            }
        }
        .overlay(alignment: .bottom) {
            if copied {
                Text(L("pt_copied")).font(.dorr(13, .bold)).foregroundStyle(.white)
                    .padding(.horizontal, 16).padding(.vertical, 10)
                    .background(Color.black.opacity(0.8), in: Capsule())
                    .padding(.bottom, 110)
                    .transition(.opacity)
            }
        }
        .task(id: version) {
            do { mine = try await DiscoverApi.myPortals().data ?? [] } catch { self.error = error.apiFailure.message }
        }
        // ⋮ — what can be done with it.
        .sheet(item: Binding(get: { menuFor.map { MenuTarget(portal: $0) } }, set: { if $0 == nil { menuFor = nil } }), onDismiss: {
            if let next = historyNext { historyNext = nil; historyFor = next }
        }) { target in
            PortalMenuSheet(portal: target.portal,
                            onEdit: { menuFor = nil; onEdit(target.portal) },
                            onRenew: { menuFor = nil; onPlans(target.portal) },
                            onHistory: { historyNext = target.portal; menuFor = nil },
                            onOpen: { menuFor = nil; if let url = URL(string: target.portal.websiteUrl) { UIApplication.shared.open(url) } },
                            onCopy: {
                                menuFor = nil
                                UIPasteboard.general.string = target.portal.websiteUrl
                                withAnimation { copied = true }
                                Task { try? await Task.sleep(nanoseconds: 1_600_000_000); withAnimation { copied = false } }
                            },
                            onDelete: { menuFor = nil; deleting = target.portal })
                .presentationDetents([.height(500), .large])
                .presentationDragIndicator(.hidden)
        }
        // Every period paid for it, newest first.
        .sheet(item: Binding(get: { historyFor.map { MenuTarget(portal: $0) } }, set: { if $0 == nil { historyFor = nil } })) { target in
            PortalHistorySheet(portal: target.portal)
                .presentationDetents([.fraction(0.75), .large])
                .presentationDragIndicator(.hidden)
        }
        .overlay {
            if let portal = deleting {
                PortalConfirmDialog(icon: "trash", title: L("pt_delete_title"), text: L("pt_delete_text", portal.name ?? ""),
                                    confirm: L("pt_delete"), onDismiss: { deleting = nil }) {
                    deleting = nil
                    delete(portal)
                }
            }
        }
        .animation(.easeOut(duration: 0.18), value: deleting?.id)
    }

    private struct MenuTarget: Identifiable {
        let portal: PortalDto
        var id: String { portal.id }
    }

    /// A state chip: its name and count; the one picked in the app's red.
    private func stateChip(_ label: String, _ count: Int, _ on: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Text(verbatim: label).font(.dorr(12.5, .bold)).foregroundStyle(on ? .white : wa.ink).lineLimit(1)
                Text(verbatim: "\(count)").font(.dorr(12, .bold)).foregroundStyle(on ? .white.opacity(0.85) : wa.mut)
            }
            .padding(.horizontal, 12).padding(.vertical, 6)
            .background(on ? wa.red : wa.surface, in: Capsule())
            .overlay(Capsule().stroke(on ? .clear : wa.line, lineWidth: 1))
        }
        .buttonStyle(.plain)
    }

    /// A number at a glance with its icon and name (the top of «My portals»).
    private func statTile(_ icon: String, _ value: String, _ label: String, _ color: Color) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            HStack(spacing: 5) {
                Image(systemName: icon).font(.system(size: 12)).foregroundStyle(wa.red)
                Text(verbatim: value).font(.dorr(16, .heavy)).foregroundStyle(color).lineLimit(1)
            }
            Text(verbatim: label).font(.dorr(11)).foregroundStyle(wa.mut).lineLimit(1)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 10).padding(.vertical, 8)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.line, lineWidth: 1))
    }

    private func delete(_ portal: PortalDto) {
        deleting = nil
        Task {
            do {
                _ = try await DiscoverApi.deletePortal(portal.id)
                mine = mine?.filter { $0.id != portal.id }
            } catch {
                self.error = error.apiFailure.message
            }
        }
    }
}

/// Every portals sheet, the way the chat's sheets look: the handle in the middle and a round × beside it (at the
/// end), on the app's surface colour (androidApp `PortalSheet`). Present it with `.presentationDragIndicator(.hidden)`.
private struct PortalSheet<Content: View>: View {
    @Environment(\.wa) private var wa
    @Environment(\.dismiss) private var dismiss
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            ZStack {
                Capsule().fill(wa.soft.opacity(0.45)).frame(width: 36, height: 4)
                HStack(spacing: 0) {
                    Spacer(minLength: 0)
                    Button { dismiss() } label: {
                        Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.mut)
                            .frame(width: 32, height: 32)
                            .background(wa.ink.opacity(0.08), in: Circle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("ch_close_sheet"))
                }
            }
            .padding(.horizontal, 14).padding(.top, 10).padding(.bottom, 2)
            content()
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
        .background(wa.surface.ignoresSafeArea())
    }
}

/// A portals sheet's title.
private struct SheetTitle: View {
    @Environment(\.wa) private var wa
    let title: String

    var body: some View {
        Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
    }
}

/// Every period paid for a portal, newest first: the package and what was paid, from → to, and its state.
@MainActor
private struct PortalHistorySheet: View {
    @Environment(\.wa) private var wa
    let portal: PortalDto
    @State private var history: [PortalSubscriptionDto]?

    var body: some View {
        PortalSheet {
            VStack(alignment: .leading, spacing: 0) {
                SheetTitle(title: L("pt_history_title"))
                Text(verbatim: portal.name ?? "").font(.dorr(12.5)).foregroundStyle(wa.mut).lineLimit(1)
                Spacer().frame(height: 12)
                if let list = history {
                    if list.isEmpty {
                        Text(L("pt_history_empty")).font(.dorr(13.5)).foregroundStyle(wa.mut).multilineTextAlignment(.center)
                            .frame(maxWidth: .infinity).padding(.vertical, 28)
                    } else {
                        ScrollView {
                            LazyVStack(spacing: 8) {
                                ForEach(Array(list.enumerated()), id: \.offset) { _, sub in PortalHistoryRow(sub: sub) }
                            }
                        }
                    }
                } else {
                    ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 16).frame(height: 62).padding(.bottom, 8) }
                }
            }
            .padding(.horizontal, 20).padding(.top, 6).padding(.bottom, 20)
        }
        .task(id: portal.id) {
            history = (try? await DiscoverApi.portal(portal.id).data)?.history ?? []
        }
    }
}

private struct PortalHistoryRow: View {
    @Environment(\.wa) private var wa
    let sub: PortalSubscriptionDto

    var body: some View {
        let (color, label): (Color, String) = {
            switch sub.state {
            case "running": return (wa.green, L("pt_sub_running"))
            case "upcoming": return (Color(hex: 0x2563EB), L("pt_sub_upcoming"))
            default: return (wa.soft, L("pt_sub_ended"))
            }
        }()
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 8) {
                Text(verbatim: sub.package ?? "—").font(.dorr(14.5, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                    .frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: money(sub.amountMinor) + " " + (sub.currencyCode ?? "")).font(.dorr(14, .bold)).foregroundStyle(wa.ink)
            }
            HStack(spacing: 4) {
                Image(systemName: "calendar").font(.system(size: 12)).foregroundStyle(wa.soft)
                Text(verbatim: dayMonth(sub.startsAt) + " ← " + dayMonth(sub.endsAt)).font(.dorr(12)).foregroundStyle(wa.mut).lineLimit(1)
                    .frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: label).font(.dorr(11, .bold)).foregroundStyle(color)
                    .padding(.horizontal, 8).padding(.vertical, 2)
                    .background(color.opacity(0.14), in: Capsule())
            }
        }
        .padding(.horizontal, 12).padding(.vertical, 10)
        .background(wa.field, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.line, lineWidth: 1))
    }
}

/// Where a portal stands: off by the admin · showing until · ending within 3 days · ended · never shown.
private enum PortalState { case off, active, ending, ended, never }

private func portalState(_ p: PortalDto) -> PortalState {
    if p.status == false { return .off }
    guard let iso = p.listedUntil, let until = ISO8601DateFormatter().date(from: iso) ?? parseRideTime(iso) else { return .never }
    if until < Date() { return .ended }
    if until < Date().addingTimeInterval(3 * 86_400) { return .ending }
    return .active
}

/// «14 ديسمبر» / «14 December», in the app's language.
private func dayMonth(_ iso: String?) -> String {
    guard let iso, let date = ISO8601DateFormatter().date(from: iso) ?? parseRideTime(iso) else { return "" }
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current)
    f.dateFormat = "d MMMM"
    return f.string(from: date)
}

/// «14 يناير 2027» / «14 January 2027», in the app's language.
private func dayMonthYear(_ date: Date) -> String {
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current)
    f.dateFormat = "d MMMM yyyy"
    return f.string(from: date)
}

/// «jarir.com» from «https://www.jarir.com/…».
private func siteOf(_ url: String) -> String {
    guard let host = URL(string: url)?.host, !host.isEmpty else { return url }
    return host.hasPrefix("www.") ? String(host.dropFirst(4)) : host
}

/// The state as a soft pill (a dot and the words) in its colour.
private struct PortalStatePill: View {
    @Environment(\.wa) private var wa
    let portal: PortalDto

    var body: some View {
        let amber = Color(hex: 0xD97706)
        let (color, text): (Color, String) = {
            switch portalState(portal) {
            case .off: return (wa.soft, L("pt_disabled"))
            case .active: return (wa.green, L("pt_active_until", dayMonth(portal.listedUntil)))
            case .ending: return (amber, L("pt_ends_on", dayMonth(portal.listedUntil)))
            case .ended: return (wa.danger, L("pt_ended_on", dayMonth(portal.listedUntil)))
            case .never: return (amber, L("pt_not_listed"))
            }
        }()
        HStack(spacing: 5) {
            Circle().fill(color).frame(width: 6, height: 6)
            Text(verbatim: text).font(.dorr(11, .bold)).foregroundStyle(color).lineLimit(1)
        }
        .padding(.horizontal, 8).padding(.vertical, 3)
        .background(color.opacity(0.14), in: Capsule())
    }
}

/// One of my portals: the logo, the name and its site, ⋮ at the end; under them its state and, across, its views.
/// Ending soon → «Renew now» (quiet); ended or never shown → «Renew to show again» / «Publish» (filled).
private struct MyPortalCard: View {
    @Environment(\.wa) private var wa
    let portal: PortalDto
    let onMenu: () -> Void
    let onRenew: () -> Void

    var body: some View {
        let state = portalState(portal)
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 10) {
                PortalLogo(url: portal.logo, size: 38)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: portal.name ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                    Text(verbatim: siteOf(portal.websiteUrl)).font(.dorr(11.5)).foregroundStyle(wa.mut).lineLimit(1)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Button(action: onMenu) {
                    Image(systemName: "ellipsis").rotationEffect(.degrees(90)).font(.system(size: 15, weight: .bold)).foregroundStyle(wa.mut)
                        .frame(width: 30, height: 30).contentShape(Circle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("pt_more"))
            }
            HStack(spacing: 4) {
                PortalStatePill(portal: portal)
                Spacer(minLength: 0)
                Image(systemName: "eye.fill").font(.system(size: 10)).foregroundStyle(wa.soft)
                Text(verbatim: L("pt_views", compact(portal.viewsCount))).font(.dorr(11, .semibold)).foregroundStyle(wa.soft)
            }
            switch state {
            case .ending:
                WaButton(text: L("pt_renew_now"), style: .ghost, icon: "arrow.clockwise", action: onRenew)
            case .ended, .never:
                WaButton(text: L(state == .never ? "pt_publish" : "pt_renew_relist"), icon: "arrow.clockwise", action: onRenew)
            default:
                EmptyView()
            }
        }
        .padding(.horizontal, 10).padding(.vertical, 8)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.line, lineWidth: 1))
    }
}

/// ⋮ on one of my portals: who it is and its state, then edit · renew · history · open the site · copy the link · delete.
private struct PortalMenuSheet: View {
    @Environment(\.wa) private var wa
    let portal: PortalDto
    let onEdit: () -> Void
    let onRenew: () -> Void
    let onHistory: () -> Void
    let onOpen: () -> Void
    let onCopy: () -> Void
    let onDelete: () -> Void

    var body: some View {
        PortalSheet {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 12) {
                    PortalLogo(url: portal.logo, size: 46)
                    VStack(alignment: .leading, spacing: 4) {
                        Text(verbatim: portal.name ?? "").font(.dorr(16, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                        PortalStatePill(portal: portal)
                    }
                    Spacer(minLength: 0)
                }
                Rectangle().fill(wa.line).frame(height: 1).padding(.vertical, 12)
                row("pencil", L("pt_menu_edit"), action: onEdit)
                if portal.status != false { row("arrow.clockwise", L(portalState(portal) == .never ? "pt_publish" : "pt_menu_renew"), action: onRenew) }
                row("doc.text", L("pt_menu_history"), action: onHistory)
                row("globe", L("pt_menu_open"), action: onOpen)
                row("link", L("pt_menu_copy"), action: onCopy)
                row("trash", L("pt_menu_delete"), danger: true, action: onDelete)
                Spacer(minLength: 0)
            }
            .padding(.horizontal, 20).padding(.top, 6).padding(.bottom, 16)
        }
    }

    private func row(_ icon: String, _ text: String, danger: Bool = false, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 16)).foregroundStyle(danger ? wa.danger : wa.red)
                    .frame(width: 38, height: 38)
                    .background(danger ? wa.danger.opacity(0.12) : wa.field, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                Text(verbatim: text).font(.dorr(15, .semibold)).foregroundStyle(danger ? wa.danger : wa.ink)
                Spacer(minLength: 0)
            }
            .padding(.vertical, 6)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Add / edit

/// A yes / no question in the portals' own look (not the system's alert): the app's surface, a round icon in the danger
/// colour, the title and the text, then «Cancel» (ghost) and the red action side by side (androidApp `PortalConfirmDialog`).
private struct PortalConfirmDialog: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let text: String
    let confirm: String
    let onDismiss: () -> Void
    let onConfirm: () -> Void

    var body: some View {
        ZStack {
            Color.black.opacity(0.45).ignoresSafeArea().onTapGesture(perform: onDismiss)
            VStack(spacing: 0) {
                Image(systemName: icon).font(.system(size: 22, weight: .semibold)).foregroundStyle(wa.danger)
                    .frame(width: 56, height: 56)
                    .background(wa.danger.opacity(0.14), in: Circle())
                Text(verbatim: title).font(.dorr(17, .heavy)).foregroundStyle(wa.ink).multilineTextAlignment(.center)
                    .padding(.top, 14)
                Text(verbatim: text).font(.dorr(13.5)).foregroundStyle(wa.mut).lineSpacing(5).multilineTextAlignment(.center)
                    .fixedSize(horizontal: false, vertical: true)
                    .padding(.top, 8).padding(.bottom, 20)
                HStack(spacing: 10) {
                    WaButton(text: L("wa_cancel"), style: .ghost, compact: true, action: onDismiss)
                    Button(action: onConfirm) {
                        HStack(spacing: 6) {
                            Image(systemName: "trash").font(.system(size: 14, weight: .bold))
                            Text(verbatim: confirm).font(.dorr(13, .heavy))
                        }
                        .foregroundStyle(.white)
                        .frame(maxWidth: .infinity, minHeight: 40, maxHeight: 40)
                        .background(wa.danger, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    }
                    .buttonStyle(WaPressStyle(scale: 0.97))
                }
            }
            .padding(22)
            .frame(maxWidth: 340)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 26, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 26, style: .continuous).stroke(wa.line, lineWidth: 1))
            .padding(.horizontal, 24)
        }
        .transition(.opacity)
    }
}

@MainActor
private struct PortalEditor: View {
    @Environment(\.wa) private var wa
    let portal: PortalDto?
    let onBack: () -> Void
    /// The saved logo was deleted (right away, not on save): my portals reload.
    let onChanged: () -> Void
    let onSaved: (PortalDto, Bool) -> Void

    @State private var languages: [PortalLanguageDto]?
    @State private var categories: [CategoryDto] = []
    @State private var active = ""
    @State private var names: [String: String] = [:]
    @State private var descriptions: [String: String] = [:]
    // Empty to start: «https://» is only the hint (and added on save when it's left out).
    @State private var website = ""
    @State private var category: Int?
    @State private var logo: UIImage?
    @State private var logoData: Data?
    @State private var pickerItem: PhotosPickerItem?
    @State private var picking = false
    /// The portal's saved logo — deleted for good at once, after asking.
    @State private var savedLogo: String?
    @State private var confirmingLogoDelete = false
    @State private var logoDeleted = false
    @State private var pickingCategory = false
    @State private var busy = false
    @State private var translating = false
    @State private var error: String?
    @State private var started = false

    /// «shop.com» is taken as «https://shop.com» — nothing has to be typed before the address.
    private var url: String {
        let t = website.trimmingCharacters(in: .whitespaces)
        let lower = t.lowercased()
        return t.isEmpty || lower.hasPrefix("http://") || lower.hasPrefix("https://") ? t : "https://" + t
    }
    private var urlOk: Bool {
        url.range(of: #"^https?://[^\s/$.?#].[^\s]*$"#, options: [.regularExpression, .caseInsensitive]) != nil
    }
    private var hasName: Bool { names.values.contains { !$0.trimmingCharacters(in: .whitespaces).isEmpty } }
    /// The logo is optional: without one, the page shows the shop icon.
    private var hasShownLogo: Bool { logo != nil || savedLogo != nil }

    var body: some View {
        WaPage(title: L(portal == nil ? "pt_add_new" : "pt_edit"), onBack: onBack, cta: {
            WaButton(text: L("pt_save"), icon: "checkmark.circle.fill", enabled: urlOk && hasName && !busy, loading: busy, action: save)
        }) {
            // Logo (optional): a smaller tile with badges outside its corners — ＋ to add one (adding or editing); with a
            // logo, ✎ to change it and 🗑 to delete it.
            VStack(spacing: 10) {
                ZStack {
                    Button { picking = true } label: {
                        ZStack {
                            if let logo {
                                Image(uiImage: logo).resizable().scaledToFill()
                            } else if let link = ApiClient.mediaUrl(savedLogo) {
                                AsyncImage(url: link) { phase in phase.image?.resizable().scaledToFill() }
                            } else {
                                Image(systemName: "storefront.fill").font(.system(size: 28)).foregroundStyle(.white)
                            }
                        }
                        .frame(width: 84, height: 84)
                        .background(wa.heroGradient)
                        .clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    logoBadge(hasShownLogo ? "pencil" : "plus", color: wa.red, label: L(hasShownLogo ? "pt_logo_change" : "pt_logo_add")) { picking = true }
                        .frame(width: 84 + 12, height: 84 + 12, alignment: .bottomTrailing)
                    if hasShownLogo {
                        // A logo picked here and not saved yet just goes; the saved one is deleted for good, after asking.
                        logoBadge("trash", color: wa.danger, label: L("pt_logo_remove")) {
                            if logo != nil { logo = nil; logoData = nil } else { confirmingLogoDelete = true }
                        }
                        .frame(width: 84 + 12, height: 84 + 12, alignment: .bottomLeading)
                    }
                }
                .photosPicker(isPresented: $picking, selection: $pickerItem, matching: .images)
                Text(L("pt_logo_optional")).font(.dorr(12)).foregroundStyle(wa.mut)
            }
            .frame(maxWidth: .infinity)
            .waRise(0)

            // The category first (a little room under the logo): a field that opens a list with a search. The portal's own
            // category shows even before the list loads (or when the admin has switched it off).
            let picked = categories.first { $0.id == category } ?? portal?.category.flatMap { c in category == c.id ? c : nil }
            Button { pickingCategory = true } label: {
                HStack(spacing: 10) {
                    if let picked { CategoryIcon(url: picked.icon, size: 26) } else {
                        Image(systemName: "square.grid.2x2").font(.system(size: 17)).foregroundStyle(wa.soft)
                    }
                    Text(verbatim: picked?.name ?? L("pt_category_pick")).font(.dorr(15)).foregroundStyle(picked == nil ? wa.soft : wa.ink)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    Image(systemName: "chevron.down").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.soft)
                }
                .padding(.horizontal, 16).frame(minHeight: 50)
                .background(wa.field, in: Capsule())
                .overlay(Capsule().stroke(wa.line, lineWidth: 1))
                .contentShape(Capsule())
            }
            .buttonStyle(.plain)
            .padding(.top, 14)
            .waRise(1)

            // The website: its name and «https://…» only as the hint.
            DorrTextArea(value: Binding(get: { website }, set: { website = String($0.prefix(500)).trimmingCharacters(in: .whitespaces) }),
                         placeholder: L("pt_website_hint"), icon: "globe", error: !website.isEmpty && !urlOk, singleLine: true)
                .padding(.top, 12)
                .keyboardType(.URL)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
                .waRise(2)

            // Name + description: one tab per language (like the admin's cards), the AI fills the others.
            if let langs = languages {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(langs, id: \.code) { lang in
                            LanguageTab(lang: lang, selected: active == lang.code,
                                        filled: !(names[lang.code] ?? "").trimmingCharacters(in: .whitespaces).isEmpty) { active = lang.code }
                        }
                    }
                }
                .padding(.top, 16)
                .waRise(3)
                WaCard(padding: 14) {
                    let rtl = langs.first { $0.code == active }?.direction == "rtl"
                    VStack(spacing: 10) {
                        DorrTextArea(value: Binding(get: { names[active] ?? "" }, set: { names[active] = String($0.prefix(120)) }),
                                     placeholder: L("pt_name"), icon: "storefront.fill", singleLine: true)
                        DorrTextArea(value: Binding(get: { descriptions[active] ?? "" }, set: { descriptions[active] = String($0.prefix(2000)) }),
                                     placeholder: L("pt_description"), icon: "text.alignleft", minHeight: 96)
                    }
                    .environment(\.layoutDirection, rtl ? .rightToLeft : .leftToRight)
                    if langs.count > 1 {
                        WaButton(text: L("pt_translate"), style: .ghost, icon: "sparkles",
                                 enabled: !(names[active] ?? "").trimmingCharacters(in: .whitespaces).isEmpty && !translating,
                                 loading: translating, action: translate)
                            .padding(.top, 12)
                        WaNote(text: L("pt_translate_note")).padding(.top, 10)
                    }
                }
                .padding(.top, 10)
                .waRise(4)
            } else {
                WaSkeleton(cornerRadius: 20).frame(height: 160)
            }
            WaError(message: error)
        }
        .task {
            guard !started else { return }
            started = true
            website = portal?.websiteUrl ?? ""
            category = portal?.category?.id
            savedLogo = portal?.logo
            for t in portal?.translations ?? [] {
                names[t.locale] = t.name ?? ""
                descriptions[t.locale] = t.description ?? ""
            }
            categories = (try? await DiscoverApi.categories().data) ?? []
            var langs = (try? await DiscoverApi.portalLanguages().data) ?? []
            if langs.isEmpty { langs = [PortalLanguageDto(code: AppLocale.current, name: "", direction: nil)] }
            languages = langs
            if active.isEmpty { active = langs[0].code }
        }
        .sheet(isPresented: $pickingCategory) {
            PortalCategorySheet(categories: categories, selected: category) { category = $0; pickingCategory = false }
                .presentationDetents([.large])
                .presentationDragIndicator(.hidden)
        }
        .onChange(of: pickerItem) { item in
            guard let item else { return }
            pickerItem = nil
            Task {
                guard let raw = try? await item.loadTransferable(type: Data.self), let image = UIImage(data: raw) else { return }
                logo = image
                logoData = image.jpegData(compressionQuality: 0.85)
            }
        }
        // Deleting the saved logo: for good, right away (not on save) — then a short message.
        .overlay {
            if confirmingLogoDelete {
                PortalConfirmDialog(icon: "photo.badge.exclamationmark", title: L("pt_logo_delete_title"), text: L("pt_logo_delete_text"),
                                    confirm: L("pt_delete"), onDismiss: { confirmingLogoDelete = false }) {
                    confirmingLogoDelete = false
                    deleteLogo()
                }
            }
        }
        .animation(.easeOut(duration: 0.18), value: confirmingLogoDelete)
        .overlay(alignment: .bottom) {
            if logoDeleted {
                Text(L("pt_logo_deleted")).font(.dorr(13, .bold)).foregroundStyle(.white)
                    .padding(.horizontal, 16).padding(.vertical, 10)
                    .background(Color.black.opacity(0.8), in: Capsule())
                    .padding(.bottom, 110)
                    .transition(.opacity)
            }
        }
    }

    /// A round badge outside a corner of the logo tile (＋ / ✎ / 🗑).
    private func logoBadge(_ icon: String, color: Color, label: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 13, weight: .bold)).foregroundStyle(.white)
                .frame(width: 30, height: 30)
                .background(color, in: Circle())
                .overlay(Circle().stroke(wa.bg, lineWidth: 2.5))
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label)
    }

    private func deleteLogo() {
        guard let portal else { return }
        Task {
            do {
                _ = try await DiscoverApi.updatePortal(portal.id, fields: ["remove_logo": "1"], logo: nil)
                savedLogo = nil
                onChanged()
                withAnimation { logoDeleted = true }
                try? await Task.sleep(nanoseconds: 1_600_000_000)
                withAnimation { logoDeleted = false }
            } catch {
                self.error = error.apiFailure.message ?? L("wa_error_generic")
            }
        }
    }

    private func save() {
        busy = true
        error = nil
        Task {
            do {
                var fields = ["website_url": url]
                // Always sent: empty = «No category» (an edited portal's category can be taken off too).
                fields["category_id"] = category.map(String.init) ?? ""
                for (i, lang) in (languages ?? []).enumerated() {
                    fields["translations[\(i)][locale]"] = lang.code
                    fields["translations[\(i)][name]"] = (names[lang.code] ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
                    fields["translations[\(i)][description]"] = (descriptions[lang.code] ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
                }
                let saved = portal == nil
                    ? try await DiscoverApi.createPortal(fields: fields, logo: logoData).data
                    : try await DiscoverApi.updatePortal(portal!.id, fields: fields, logo: logoData).data
                if let saved { onSaved(saved, portal == nil) }
            } catch {
                self.error = error.apiFailure.message ?? L("wa_error_generic")
            }
            busy = false
        }
    }

    private func translate() {
        translating = true
        error = nil
        Task {
            do {
                let description = descriptions[active] ?? ""
                let result = try await DiscoverApi.translatePortal([
                    "from": active, "name": names[active] ?? "", "description": description.trimmingCharacters(in: .whitespaces).isEmpty ? nil : description,
                ]).data ?? [:]
                for (code, fields) in result {
                    if let name = fields["name"], !name.trimmingCharacters(in: .whitespaces).isEmpty { names[code] = name }
                    if let d = fields["description"], !d.trimmingCharacters(in: .whitespaces).isEmpty { descriptions[code] = d }
                }
            } catch {
                self.error = error.apiFailure.message ?? L("wa_error_generic")
            }
            translating = false
        }
    }
}

private struct LanguageTab: View {
    @Environment(\.wa) private var wa
    let lang: PortalLanguageDto
    let selected: Bool
    let filled: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Text(verbatim: lang.name.trimmingCharacters(in: .whitespaces).isEmpty ? lang.code.uppercased() : lang.name)
                    .font(.dorr(13, .bold)).foregroundStyle(selected ? .white : wa.ink)
                if filled {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 13)).foregroundStyle(selected ? .white : wa.green)
                }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 9)
            .background(selected ? wa.red : wa.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(selected ? .clear : wa.line, lineWidth: 1))
            .animation(.easeInOut(duration: 0.2), value: selected)
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Putting it on the page

@MainActor
private struct PortalPlans: View {
    @Environment(\.wa) private var wa
    let portal: PortalDto
    let onBack: () -> Void
    let onPaid: () -> Void
    @State private var packages: [PackageDto]?
    @State private var chosen: Int?
    @State private var error: String?

    var body: some View {
        WaPage(title: L("pt_plans_title"), onBack: onBack, cta: {
            WaButton(text: L("pt_continue_to_pay"), icon: "paperplane.fill", enabled: chosen != nil) {
                guard let id = chosen else { return }
                CheckoutLauncher.shared.open(CheckoutRequest(purpose: "chat_portal_listing",
                                                             reference: ["portal_id": .string(portal.id), "package_id": .int(id)]), onPaid: onPaid)
            }
        }) {
            HStack(spacing: 12) {
                PortalLogo(url: portal.logo, size: 48)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: portal.name ?? "").font(.dorr(16, .heavy)).foregroundStyle(wa.ink)
                    Text(L("pt_plans_sub")).font(.dorr(12.5)).foregroundStyle(wa.mut)
                }
            }
            .waRise(0)
            WaError(message: error)
            Spacer().frame(height: 12)
            // First, what paying does: until when the portal will show (for the plan picked below).
            if let plan = packages?.first(where: { $0.id == chosen }) {
                NewPeriodLine(portal: portal, plan: plan).padding(.bottom, 12)
            }
            if let list = packages {
                if list.isEmpty {
                    WaCard { WaEmpty(icon: "paperplane.fill", tone: .gray, title: L("pt_no_plans"), text: L("pt_no_plans_text")) }
                } else {
                    ForEach(Array(list.enumerated()), id: \.element.id) { i, p in
                        PlanCard(plan: p, selected: chosen == p.id) { chosen = p.id }
                            .padding(.bottom, 8)
                            .waRise(1 + i)
                    }
                }
            } else {
                ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 16).frame(height: 56).padding(.bottom, 8) }
            }
        }
        .task {
            do {
                let list = try await DiscoverApi.portalPackages().data ?? []
                packages = list
                chosen = list.first?.id
            } catch {
                self.error = error.apiFailure.message
            }
        }
    }
}

/// Before paying: the portal will show until … — a renewal while it's still up adds the period after the current end
/// (no day lost), otherwise it starts now. The same sum the server does (`ChatPackage::endFrom`).
private struct NewPeriodLine: View {
    @Environment(\.wa) private var wa
    let portal: PortalDto
    let plan: PackageDto

    var body: some View {
        let now = Date()
        let until = portal.listedUntil.flatMap { ISO8601DateFormatter().date(from: $0) ?? parseRideTime($0) }
        let current: Date? = until.flatMap { $0 > now ? $0 : nil }
        let start = current ?? now
        let unit: Calendar.Component = plan.period == "week" ? .weekOfYear : (plan.period == "year" ? .year : .month)
        let end = Calendar(identifier: .gregorian).date(byAdding: unit, value: plan.periodCount, to: start) ?? start
        HStack(alignment: .top, spacing: 8) {
            Image(systemName: "calendar").font(.system(size: 15)).foregroundStyle(wa.red).padding(.top, 1)
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: L("pt_new_until", dayMonthYear(end))).font(.dorr(13, .heavy)).foregroundStyle(wa.ink)
                if current != nil {
                    Text(verbatim: L("pt_new_until_sub", dayMonth(portal.listedUntil))).font(.dorr(11.5)).foregroundStyle(wa.mut)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(.horizontal, 12).padding(.vertical, 9)
        .background(wa.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.red.opacity(0.25), lineWidth: 1))
    }
}

struct PlanCard: View {
    @Environment(\.wa) private var wa
    let plan: PackageDto
    let selected: Bool
    let action: () -> Void

    var body: some View {
        // Top: the icon and the name beside it, across from them the price and the period under it. Under all that, the
        // description across the whole card (starting under the icon).
        Button(action: action) {
            VStack(alignment: .leading, spacing: 6) {
                HStack(spacing: 10) {
                    WaIconWell(icon: "paperplane.fill", tone: selected ? .red : .gray, size: 34, iconSize: 17)
                    Text(verbatim: plan.name ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.ink)
                        .lineLimit(1)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    VStack(alignment: .trailing, spacing: 0) {
                        HStack(alignment: .lastTextBaseline, spacing: 3) {
                            Text(verbatim: money(plan.amountMinor ?? 0)).font(.dorr(15, .heavy)).foregroundStyle(wa.ink)
                            Text(verbatim: plan.currencyCode ?? "").font(.dorr(10, .bold)).foregroundStyle(wa.mut)
                        }
                        .environment(\.layoutDirection, .leftToRight)
                        Text(verbatim: periodLabel(plan)).font(.dorr(11, .bold)).foregroundStyle(selected ? wa.red : wa.mut)
                    }
                }
                // The radio sits in the room the description leaves at the other end, beside its last line.
                HStack(alignment: .bottom, spacing: 8) {
                    Text(verbatim: plan.description?.trimmingCharacters(in: .whitespaces) ?? "").font(.dorr(11.5)).foregroundStyle(wa.soft)
                        .lineSpacing(2).lineLimit(2)
                        .multilineTextAlignment(.leading)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    ZStack {
                        Circle().fill(selected ? wa.red : Color.clear)
                        Circle().stroke(selected ? wa.red : wa.soft, lineWidth: 1.5)
                        if selected { Circle().fill(.white).frame(width: 8, height: 8) }
                    }
                    .frame(width: 20, height: 20)
                }
            }
            .padding(.horizontal, 12).padding(.vertical, 10)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(selected ? wa.red : wa.line, lineWidth: selected ? 1.5 : 1))
            .animation(.easeInOut(duration: 0.2), value: selected)
        }
        .buttonStyle(WaPressStyle(scale: 0.98))
    }
}

func periodLabel(_ plan: PackageDto) -> String {
    switch plan.period {
    case "week": return Lp("pt_weeks", plan.periodCount, plan.periodCount)
    case "year": return Lp("pt_years", plan.periodCount, plan.periodCount)
    default: return Lp("pt_months", plan.periodCount, plan.periodCount)
    }
}

// MARK: - Home circle

/// androidApp `HomeCircles` — the circles row on Home, drawn like the chat's stories bar: me, the merchant portals
/// circle (not a story — it opens the portals page), Dorr's own stories, then everyone's public stories (the ones I
/// haven't watched first). The stories open in the chat's own viewer (HomeStoriesLayer); more load near the end.
struct HomeCircles: View {
    @ObservedObject private var stories = HomeStories.shared
    @ObservedObject private var network = NetworkMonitor.shared
    @Environment(\.wa) private var wa
    let onOpenPortals: () -> Void

    var body: some View {
        let people = stories.feed?.people ?? []
        ScrollView(.horizontal, showsIndicators: false) {
            LazyHStack(spacing: 2) {
                if stories.feed?.enabled != false { HomeMyCircle() }
                PortalsCircle(action: onOpenPortals)
                if let dorr = stories.feed?.dorr { HomeDorrCircle(group: dorr) }
                ForEach(Array(people.enumerated()), id: \.offset) { i, group in
                    HomePersonCircle(group: group).onAppear { if i >= people.count - 3 { Task { await stories.loadMore() } } }
                }
                if stories.loadingMore { ProgressView().tint(wa.red).frame(width: 58, height: 56) }
            }
            .padding(.horizontal, 20)
        }
        .task(id: network.reconnectTick) { await stories.refresh() }
    }
}

private struct PortalsCircle: View {
    @Environment(\.wa) private var wa
    let action: () -> Void
    @State private var pop: CGFloat = 0.6

    var body: some View {
        Button(action: action) {
            VStack(spacing: 4) {
                // Always "new": the ring keeps turning, like an unseen story.
                StoryRing(size: 56) {
                    Image(systemName: "storefront.fill").font(.system(size: 21)).foregroundStyle(.white)
                        .frame(width: 46, height: 46)
                        .background(wa.heroGradient, in: Circle())
                }
                Text(L("pt_circle")).font(.dorr(11, .bold)).foregroundStyle(wa.ink).lineLimit(1).multilineTextAlignment(.center)
            }
            .frame(width: 58)
            .scaleEffect(pop)
        }
        .buttonStyle(WaPressStyle(scale: 0.92))
        .onAppear { withAnimation(.spring(response: 0.36, dampingFraction: 0.45)) { pop = 1 } }
    }
}

/// androidApp `chat/StoriesBar.kt` `StoryRing` (one unseen story): a brand sweep ring turning slowly (9 s a turn).
struct StoryRing<Content: View>: View {
    @Environment(\.wa) private var wa
    let size: CGFloat
    @ViewBuilder let content: () -> Content

    var body: some View {
        ZStack {
            TimelineView(.animation) { context in
                let angle = context.date.timeIntervalSinceReferenceDate.truncatingRemainder(dividingBy: 9) / 9 * 360
                Circle()
                    .stroke(AngularGradient(colors: [wa.red, wa.red.scaled(0.72), wa.red.scaled(0.45), wa.red], center: .center),
                            style: StrokeStyle(lineWidth: 2.6, lineCap: .round))
                    .padding(1.3)
                    .rotationEffect(.degrees(angle))
            }
            content().padding(5)
        }
        .frame(width: size, height: size)
    }
}
