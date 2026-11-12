import Combine
import SwiftUI

// freelanceApp `ui/screens/CommunityScreens.kt`.

/*
 * FL-L12, the professional community (freelancers only, inside this app): the feed, one post with its comments, a member's profile and
 * their followers / following. Everything is read when a screen opens, on pull-to-refresh, and once after my own action — never on a timer.
 * A change made on one screen (a reaction, a save, a follow, a new comment) shows on the others through [CommunityHub.changes].
 */

private let FEED_TABS = ["for_you", "following", "specialty", "mine", "saved"]

private func tabLabel(_ tab: String) -> String {
    switch tab {
    case "following": return L("cm_tab_following")
    case "specialty": return L("cm_tab_specialty")
    case "mine": return L("cm_tab_mine")
    case "saved": return L("cm_tab_saved")
    default: return L("cm_tab_for_you")
    }
}

private func tabIcon(_ tab: String) -> String {
    switch tab {
    case "following": return "person.2.fill"
    case "specialty": return "square.grid.2x2.fill"
    case "mine": return "person.fill"
    case "saved": return "bookmark.fill"
    default: return "sparkles"
    }
}

// MARK: - the frame

/// A community page: the soft backdrop, its title with a few round action buttons and the back button, then the content.
@MainActor
struct CommunityFrame<Actions: View, Content: View>: View {
    let title: String
    /// As a tab of the bar (nil) it sits under the app header: no backdrop or back button of its own.
    let onBack: (() -> Void)?
    @ViewBuilder let actions: () -> Actions
    @ViewBuilder let content: () -> Content

    init(title: String, onBack: (() -> Void)?, @ViewBuilder actions: @escaping () -> Actions, @ViewBuilder content: @escaping () -> Content) {
        self.title = title
        self.onBack = onBack
        self.actions = actions
        self.content = content
    }

    var body: some View {
        ZStack {
            if onBack != nil { PinkBackdrop() }
            VStack(spacing: 0) {
                HStack(spacing: 8) {
                    SettingsScreenTitle(title: title).frame(maxWidth: .infinity, alignment: .leading)
                    actions()
                    if let onBack { RoundIcon(icon: "arrow.forward", description: L("common_back"), action: onBack) }
                }
                .padding(.horizontal, 14)
                .padding(.top, 14)
                .padding(.bottom, 10)
                content().frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
            }
        }
        .navigationBarHidden(true)
    }
}

extension CommunityFrame where Actions == EmptyView {
    init(title: String, onBack: (() -> Void)?, @ViewBuilder content: @escaping () -> Content) {
        self.init(title: title, onBack: onBack, actions: { EmptyView() }, content: content)
    }
}

/// A round button of a page's head (search, back…): the card colour, a soft shadow by day, a hairline at night. `mirrored` flips the icon.
@MainActor
struct RoundIcon: View {
    @Environment(\.wa) private var wa
    let icon: String
    let description: String?
    var mirrored = false
    var tint: Color?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 15, weight: .bold)).foregroundStyle(tint ?? wa.red)
                .scaleEffect(x: mirrored ? -1 : 1, y: 1)
                .frame(width: 36, height: 36)
                .background(wa.settingsCard, in: Circle())
                .overlay { if wa.night { Circle().stroke(wa.line, lineWidth: 1) } }
                .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.08), radius: 6, y: 2)
                .contentShape(Circle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(description ?? "")
    }
}

@MainActor
private struct ListFooter: View {
    @Environment(\.wa) private var wa
    let loading: Bool
    let failed: Bool
    let end: Bool
    let onRetry: () -> Void

    var body: some View {
        ZStack {
            if failed {
                Button(action: onRetry) {
                    Text(L("common_retry")).font(.dorr(13, .bold)).foregroundStyle(wa.red).padding(10)
                }
                .buttonStyle(.plain)
            } else if loading {
                SmallSpinner(size: 22)
            } else if end {
                HStack(spacing: 6) {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 14)).foregroundStyle(wa.settingsMut.opacity(0.6))
                    Text(L("cm_feed_end")).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                }
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 14)
    }
}

/// Shown when the community is off in my country.
@MainActor
private struct CommunityClosed: View {
    var body: some View {
        EmptyState(icon: "nosign", title: L("cm_closed_title"), body: L("cm_closed_body")).padding(.top, 80)
    }
}

// MARK: - the feed

@MainActor
final class CommunityFeedVm: ObservableObject {
    @Published private(set) var tab = "for_you"
    @Published private(set) var kind: String?
    @Published private(set) var specialty: Int?
    @Published private(set) var search = ""
    @Published var searchOpen = false
    @Published private(set) var closed = false
    /// Bumped when my new post lands on top: the list scrolls up to it.
    @Published private(set) var toTop = 0

    let rows = CursorRows<CommunityPostDto>(key: { $0.uuid ?? "" })

    private var job: Task<Void, Never>?
    private var jobId = 0
    private var jobActive = false
    private var searchJob: Task<Void, Never>?
    private var bag = Set<AnyCancellable>()

    init() {
        rows.fetch = { [weak self] cursor in
            guard let self else { return .offline }
            let query = self.search.trimmingCharacters(in: .whitespacesAndNewlines)
            let r = await CommunityApi.feed(tab: self.tab, kind: self.kind, specialtyId: self.specialty, search: query.count >= 2 ? query : nil, cursor: cursor)
            if case .failed(let e) = r, e.errorCode == "freelance_feature_off" { self.closed = true }
            return r
        }
        rows.objectWillChange.sink { [weak self] _ in self?.objectWillChange.send() }.store(in: &bag)
        CommunityHub.shared.changes.sink { [weak self] in self?.apply($0) }.store(in: &bag)
        launch { await self.rows.reload() }
    }

    var filtered: Bool { kind != nil || specialty != nil || search.trimmingCharacters(in: .whitespacesAndNewlines).count >= 2 }

    private func launch(_ work: @escaping @MainActor () async -> Void) {
        jobId += 1
        let id = jobId
        jobActive = true
        job = Task { [weak self] in
            await work()
            if self?.jobId == id { self?.jobActive = false }
        }
    }

    /// Pull-to-refresh: the first page again, and the home (my numbers, my limits).
    func refresh() async {
        if rows.loading { return }
        await rows.reload()
        await CommunityHub.shared.refresh()
    }

    func more() {
        if jobActive { return }
        launch { await self.rows.loadMore() }
    }

    func retry() {
        rows.retry()
        if !rows.loaded { refilter() } else { more() }
    }

    private func refilter() {
        job?.cancel()
        jobActive = false
        rows.reset()
        launch { await self.rows.reload() }
    }

    func pickTab(_ t: String) {
        if t == tab { return }
        tab = t
        refilter()
    }

    func pickKind(_ k: String?) {
        if k == kind { return }
        kind = k
        refilter()
    }

    func pickSpecialty(_ id: Int?) {
        if id == specialty { return }
        specialty = id
        refilter()
    }

    /// The search runs once the typing stops for a moment (not on a timer).
    func type(_ text: String) {
        let before = search.trimmingCharacters(in: .whitespacesAndNewlines)
        search = String(text.prefix(80))
        searchJob?.cancel()
        if before == search.trimmingCharacters(in: .whitespacesAndNewlines) { return }
        searchJob = Task { [weak self] in
            try? await Task.sleep(nanoseconds: 400_000_000)
            if Task.isCancelled { return }
            self?.refilter()
        }
    }

    func clearFilters() {
        kind = nil
        specialty = nil
        search = ""
        searchOpen = false
        refilter()
    }

    private func apply(_ change: CommunityChange) {
        switch change {
        case .postUpdated(let post):
            rows.update(where: { $0.uuid == post.uuid }) { _ in post }
        case .postDeleted(let uuid):
            rows.remove { $0.uuid == uuid }
        case .followChanged(let member, let following):
            rows.update(where: { $0.author?.uuid == member }) { p in
                var p = p
                p.author?.isFollowing = following
                return p
            }
        case .postCreated(let p):
            let fits = (tab == "for_you" || tab == "mine") && (kind == nil || kind == p.kind) && (specialty == nil || specialty == p.specialty?.id)
                && search.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
            if fits {
                rows.prepend(p)
                toTop += 1
            }
        }
    }
}

/**
 * The feed: «start a post» on top (my photo, then post / question / work), the tabs (for you · following · my specialty · mine · saved),
 * the kind and specialty filters and a search, then the posts — pulled down to refresh, and the next page as the end comes near.
 */
@MainActor
struct CommunityScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @StateObject private var vm = CommunityFeedVm()
    @ObservedObject private var hub = CommunityHub.shared
    let nav: CommunityNav
    let onBack: (() -> Void)?
    @State private var pickingSpecialty = false

    var body: some View {
        CommunityFrame(title: L("cm_title"), onBack: onBack, actions: {
            RoundIcon(icon: "magnifyingglass", description: L("cm_search"), tint: vm.searchOpen ? wa.red : wa.settingsMut) {
                vm.searchOpen.toggle()
                if !vm.searchOpen && !vm.search.isEmpty { vm.type("") }
            }
            if let me = hub.home?.me?.uuid, !me.trimmingCharacters(in: .whitespaces).isEmpty {
                Button { nav.openMember(me) } label: { AvatarImage(url: hub.home?.me?.avatarUrl, size: 36) }.buttonStyle(.plain)
            }
        }) {
            if hub.available == false || vm.closed {
                CommunityClosed()
            } else {
                feed
            }
        }
        .task { if CommunityHub.shared.home == nil { await CommunityHub.shared.refresh() } }
        // The connection came back after a failed read: read once more.
        .onChange(of: network.reconnectTick) { tick in if tick > 0 && vm.rows.failure != nil { vm.retry() } }
        .cmSheet(isPresented: $pickingSpecialty) {
            SpecialtySheet(home: hub.home, selected: vm.specialty, onPick: {
                vm.pickSpecialty($0)
                pickingSpecialty = false
            }, onDismiss: { pickingSpecialty = false })
        }
    }

    private var feed: some View {
        ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(spacing: 10) {
                    ComposerCard(home: hub.home, onCompose: nav.compose).id("composer")
                    if let h = hub.home, h.canWrite != true { WriteBlockedNote(home: h, onVerify: nav.openVerification) }
                    FeedFilters(vm: vm, home: hub.home) { pickingSpecialty = true }
                    let rows = vm.rows
                    if !rows.loaded && rows.failure == nil {
                        ForEach(0..<3, id: \.self) { _ in PostSkeleton() }
                    } else if rows.rows.isEmpty && rows.failure != nil {
                        LoadFailed(text: rows.failure?.errorText(L("login_error_offline")) ?? L("login_error_generic")) { vm.retry() }
                    } else if rows.loaded && rows.rows.isEmpty {
                        FeedEmpty(vm: vm) { nav.compose("post") }
                    } else {
                        ForEach(Array(rows.rows.enumerated()), id: \.element.uuid) { i, post in
                            PostCard(post: post, nav: nav).onAppear { if i >= rows.rows.count - 4 { vm.more() } }
                        }
                        ListFooter(loading: rows.loading && !rows.refreshing, failed: rows.failure != nil, end: !rows.hasMore) { vm.retry() }
                    }
                }
                .padding(.horizontal, 12)
                .padding(.bottom, 24)
            }
            .scrollDismissesKeyboard(.interactively)
            .refreshable { await vm.refresh() }
            .onChange(of: vm.toTop) { n in if n > 0 { withAnimation { proxy.scrollTo("composer", anchor: .top) } } }
        }
    }
}

/// LinkedIn's «start a post»: my photo and a pill, then the three kinds of post as quick buttons.
@MainActor
private struct ComposerCard: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var session = Session.shared
    let home: CommunityHomeDto?
    let onCompose: (String) -> Void

    var body: some View {
        VStack(spacing: 12) {
            HStack(spacing: 10) {
                AvatarImage(url: home?.me?.avatarUrl ?? session.me?.avatarUrl, size: 46)
                Button { onCompose("post") } label: {
                    Text(L("cm_start_post")).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsMut).lineLimit(1)
                        .padding(.horizontal, 16)
                        .frame(maxWidth: .infinity, minHeight: 46, alignment: .leading)
                        .overlay(Capsule().stroke(wa.night ? wa.line : Color(hex: 0xD1D5DB), lineWidth: 1))
                        .contentShape(Capsule())
                }
                .buttonStyle(.plain)
            }
            HStack(spacing: 0) {
                QuickKind(icon: "square.and.pencil", label: L("cm_kind_post"), tone: wa.red) { onCompose("post") }
                QuickKind(icon: "bubble.left.and.bubble.right.fill", label: L("cm_kind_question"), tone: QUESTION_TONE) { onCompose("question") }
                QuickKind(icon: "briefcase.fill", label: L("cm_kind_work"), tone: WORK_TONE) { onCompose("work") }
            }
        }
        .padding(14)
        .settingsSurface(20, elevation: 4)
    }
}

@MainActor
private struct QuickKind: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let tone: Color
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 17)).foregroundStyle(tone)
                Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 8)
            .contentShape(RoundedRectangle(cornerRadius: 12))
        }
        .buttonStyle(.plain)
    }
}

/// Why I can read but not write: not verified yet, or the staff muted me until a date.
@MainActor
struct WriteBlockedNote: View {
    @Environment(\.wa) private var wa
    let home: CommunityHomeDto
    let onVerify: () -> Void

    var body: some View {
        let tone = Color(hex: 0xD97706)
        HStack(spacing: 10) {
            Image(systemName: "lock.fill").font(.system(size: 17)).foregroundStyle(tone)
            VStack(alignment: .leading, spacing: 2) {
                if let muted = home.muted {
                    Text(L("cm_muted_until", cmDate(muted.until))).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    if let reason = muted.reason, !reason.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(L("cm_muted_reason", reason)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                } else {
                    Text(L("cm_need_verified")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    Button(action: onVerify) {
                        Text(L("cm_verify_now")).font(.dorr(12.5, .heavy)).foregroundStyle(wa.red).padding(.vertical, 2)
                    }
                    .buttonStyle(.plain)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(12)
        .background(tone.opacity(wa.night ? 0.2 : 0.1), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
    }
}

@MainActor
private struct FeedFilters: View {
    @Environment(\.wa) private var wa
    @ObservedObject var vm: CommunityFeedVm
    let home: CommunityHomeDto?
    let onSpecialty: () -> Void

    var body: some View {
        VStack(spacing: 10) {
            if vm.searchOpen || !vm.search.isEmpty {
                DorrTextField(
                    value: Binding(get: { vm.search }, set: { vm.type($0) }), placeholder: L("cm_search_hint"), icon: "magnifyingglass",
                    trailing: vm.search.isEmpty ? nil : AnyView(
                        Button { vm.type("") } label: { Image(systemName: "xmark").font(.system(size: 14, weight: .bold)).foregroundStyle(wa.settingsMut) }
                            .buttonStyle(.plain)
                    )
                )
            }
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(FEED_TABS, id: \.self) { t in FilterChip(label: tabLabel(t), icon: tabIcon(t), on: vm.tab == t, solid: true) { vm.pickTab(t) } }
                }
            }
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    let name = vm.specialty.flatMap { id in home?.specialties?.first { $0.id == id }?.name }
                    FilterChip(label: name ?? L("cm_all_specialties"), icon: "slider.horizontal.3", on: vm.specialty != nil, trailing: "chevron.down", action: onSpecialty)
                    FilterChip(label: L("cm_kind_all"), icon: nil, on: vm.kind == nil) { vm.pickKind(nil) }
                    FilterChip(label: L("cm_kind_posts"), icon: "square.and.pencil", on: vm.kind == "post") { vm.pickKind("post") }
                    FilterChip(label: L("cm_kind_questions"), icon: "bubble.left.and.bubble.right.fill", on: vm.kind == "question") { vm.pickKind("question") }
                    FilterChip(label: L("cm_kind_works"), icon: "briefcase.fill", on: vm.kind == "work") { vm.pickKind("work") }
                }
            }
        }
    }
}

@MainActor
private struct FilterChip: View {
    @Environment(\.wa) private var wa
    let label: String
    let icon: String?
    let on: Bool
    var solid = false
    var trailing: String?
    let action: () -> Void

    var body: some View {
        let accent = wa.red
        let bg: Color = on && solid ? accent : (on ? accent.opacity(0.12) : wa.settingsCard)
        let fg: Color = on && solid ? .white : (on ? accent : wa.settingsInk)
        let border: Color = on ? (solid ? accent : accent.opacity(0.5)) : (wa.night ? wa.line : Color(hex: 0xE5E7EB))
        Button(action: action) {
            HStack(spacing: 0) {
                if let icon { Image(systemName: icon).font(.system(size: 13, weight: .semibold)).padding(.trailing, 5) }
                Text(verbatim: label).font(.dorr(13, .bold)).lineLimit(1)
                if let trailing { Image(systemName: trailing).font(.system(size: 11, weight: .bold)).padding(.leading, 3) }
            }
            .foregroundStyle(fg)
            .padding(.horizontal, 13)
            .frame(height: 36)
            .background(bg, in: Capsule())
            .overlay(Capsule().stroke(border, lineWidth: 1))
            .contentShape(Capsule())
        }
        .buttonStyle(.plain)
    }
}

@MainActor
private struct FeedEmpty: View {
    @Environment(\.wa) private var wa
    @ObservedObject var vm: CommunityFeedVm
    let onCompose: () -> Void

    var body: some View {
        let style: (String, String, String) = {
            if vm.filtered { return ("magnifyingglass", "cm_empty_filtered", "cm_empty_filtered_body") }
            switch vm.tab {
            case "following": return ("person.badge.plus", "cm_empty_following", "cm_empty_following_body")
            case "specialty": return ("square.grid.2x2.fill", "cm_empty_specialty", "cm_empty_specialty_body")
            case "mine": return ("square.and.pencil", "cm_empty_mine", "cm_empty_mine_body")
            case "saved": return ("bookmark", "cm_empty_saved", "cm_empty_saved_body")
            default: return ("person.3.fill", "cm_empty_all", "cm_empty_all_body")
            }
        }()
        let action: String? = vm.filtered ? "cm_clear_filters"
            : (vm.tab == "mine" || vm.tab == "for_you" || vm.tab == "specialty" ? "cm_start_post_short" : nil)
        VStack(spacing: 0) {
            EmptyState(icon: style.0, title: L(style.1), body: L(style.2))
            if let action {
                Button { if vm.filtered { vm.clearFilters() } else { onCompose() } } label: {
                    Text(L(action)).font(.dorr(14, .heavy)).foregroundStyle(.white)
                        .padding(.horizontal, 22).padding(.vertical, 11)
                        .background(wa.red, in: Capsule())
                }
                .buttonStyle(.plain)
                .padding(.top, 16)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.top, 36)
        .padding(.bottom, 20)
    }
}

@MainActor
private struct LoadFailed: View {
    @Environment(\.wa) private var wa
    let text: String
    let onRetry: () -> Void

    var body: some View {
        VStack(spacing: 14) {
            EmptyState(icon: "icloud.slash", title: L("cm_load_failed"), body: text)
            Button(action: onRetry) {
                Text(L("common_retry")).font(.dorr(14, .heavy)).foregroundStyle(wa.red)
                    .padding(.horizontal, 22).padding(.vertical, 10)
                    .overlay(Capsule().stroke(wa.red, lineWidth: 1.5))
                    .contentShape(Capsule())
            }
            .buttonStyle(.plain)
        }
        .frame(maxWidth: .infinity)
        .padding(.top, 40)
    }
}

/// The specialties to filter by (or «all»), mine first. Present it with `.cmSheet`.
@MainActor
struct SpecialtySheet: View {
    @Environment(\.wa) private var wa
    let home: CommunityHomeDto?
    let selected: Int?
    var allowAll = true
    let onPick: (Int?) -> Void
    let onDismiss: () -> Void

    var body: some View {
        let mine = Set(home?.mySpecialties ?? [])
        let all = (home?.specialties ?? []).enumerated()
            .sorted { (mine.contains($0.element.id ?? -1) ? 0 : 1, $0.offset) < (mine.contains($1.element.id ?? -1) ? 0 : 1, $1.offset) }
            .map(\.element)
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                Text(L("cm_pick_specialty")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).padding(.bottom, 10)
                if allowAll { SheetOption(label: L("cm_all_specialties"), on: selected == nil, mine: false) { onPick(nil) } }
                ForEach(all, id: \.id) { s in
                    SheetOption(label: s.name ?? "", on: selected == s.id, mine: mine.contains(s.id ?? -1)) { onPick(s.id) }
                }
            }
            .padding(.horizontal, 18)
            .padding(.top, 24)
            .padding(.bottom, 24)
        }
        .background(wa.settingsCard.ignoresSafeArea())
    }
}

@MainActor
private struct SheetOption: View {
    @Environment(\.wa) private var wa
    let label: String
    let on: Bool
    let mine: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 8) {
                Text(verbatim: label).font(.dorr(14, on ? .heavy : .semibold)).foregroundStyle(on ? wa.red : wa.settingsInk)
                    .frame(maxWidth: .infinity, alignment: .leading)
                if mine { SoftPill(text: L("cm_my_specialty"), tone: wa.settingsMut) }
                if on { Image(systemName: "checkmark").font(.system(size: 15, weight: .bold)).foregroundStyle(wa.red) }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 13)
            .background(on ? wa.red.opacity(0.1) : .clear, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .contentShape(RoundedRectangle(cornerRadius: 14))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - one post

/// A comment with its replies as shown: the first few, then the rest page by page.
struct CmThread: Identifiable {
    var comment: CommunityCommentDto
    var replies: [CommunityCommentDto]
    var cursor: String?
    var opened = false
    var loading = false

    var id: String { comment.uuid ?? "" }
    var hidden: Int { max((comment.replies ?? 0) - replies.count, 0) }
}

@MainActor
final class PostDetailVm: ObservableObject {
    private let uuid: String
    @Published private(set) var post: CommunityPostDto?
    @Published private(set) var failure: ApiResult<CursorPage<CommunityPostDetailDto>>?
    @Published private(set) var gone = false
    @Published private(set) var refreshing = false
    @Published private(set) var threads: [CmThread] = []
    @Published private(set) var hasMore = false
    @Published private(set) var loadingMore = false
    private var cursor: String?
    private var bag = Set<AnyCancellable>()

    init(uuid: String) {
        self.uuid = uuid
        CommunityHub.shared.changes.sink { [weak self] c in
            guard let self else { return }
            switch c {
            case .postUpdated(let p): if p.uuid == uuid { self.post = p }
            case .postDeleted(let id): if id == uuid { self.gone = true }
            case .followChanged(let member, let following):
                if var p = self.post, p.author?.uuid == member {
                    p.author?.isFollowing = following
                    self.post = p
                }
            case .postCreated: break
            }
        }.store(in: &bag)
        Task { await self.load() }
        // A notice (a comment on my post, a reply) may be about this post: one reload, no timer.
        Realtime.shared.changed.sink { [weak self] in Task { await self?.load() } }.store(in: &bag)
    }

    func load() async {
        refreshing = post != nil
        let r = await CommunityApi.post(uuid)
        refreshing = false
        switch r {
        case .ok(let page, _):
            failure = nil
            post = page.data.post
            threads = (page.data.comments ?? []).map { CmThread(comment: $0, replies: $0.firstReplies ?? []) }
            cursor = page.next
            hasMore = page.next != nil
        case .failed(let e):
            if e.httpStatus == 404 { gone = true } else { failure = r }
        case .offline:
            failure = r
        }
    }

    func refresh() { Task { await load() } }

    func more() {
        if !hasMore || loadingMore { return }
        loadingMore = true
        Task {
            let r = await CommunityApi.comments(uuid, cursor: cursor)
            if case .ok(let page, _) = r {
                for c in page.data where !threads.contains(where: { $0.comment.uuid == c.uuid }) {
                    threads.append(CmThread(comment: c, replies: c.firstReplies ?? []))
                }
                cursor = page.next
                hasMore = page.hasMore
            }
            loadingMore = false
        }
    }

    func moreReplies(_ root: String) {
        guard let i = threads.firstIndex(where: { $0.comment.uuid == root }) else { return }
        let t = threads[i]
        if t.loading { return }
        threads[i].loading = true
        Task {
            let r = await CommunityApi.replies(root, cursor: t.opened ? t.cursor : nil)
            guard let j = threads.firstIndex(where: { $0.comment.uuid == root }) else { return }
            var now = threads[j]
            if case .ok(let page, _) = r {
                let merged = now.opened
                    ? now.replies + page.data.filter { n in !now.replies.contains { $0.uuid == n.uuid } }
                    : page.data + now.replies.filter { o in !page.data.contains { $0.uuid == o.uuid } }
                now.replies = merged
                now.cursor = page.next
                now.opened = true
                now.loading = false
                if !page.hasMore { now.comment.replies = merged.count }
            } else {
                now.loading = false
            }
            threads[j] = now
        }
    }

    private func changePost(_ change: (inout CommunityPostDto) -> Void) {
        guard var p = post else { return }
        change(&p)
        post = p
        CommunityHub.shared.emit(.postUpdated(p))
    }

    /// Puts a comment (top or reply) through [change] where it is.
    private func changeComment(_ uuid: String, _ change: (CommunityCommentDto) -> CommunityCommentDto) {
        for i in threads.indices {
            if threads[i].comment.uuid == uuid {
                threads[i].comment = change(threads[i].comment)
                return
            }
            if threads[i].replies.contains(where: { $0.uuid == uuid }) {
                threads[i].replies = threads[i].replies.map { $0.uuid == uuid ? change($0) : $0 }
                return
            }
        }
    }

    func send(_ body: String, replyTo: CommunityCommentDto?, editing: CommunityCommentDto?, onDone: @escaping () -> Void,
              onFail: @escaping (ApiResult<CommunityCommentDto>) -> Void) {
        Task {
            if let editing {
                let r = await CommunityApi.updateComment(editing.uuid ?? "", CommunityCommentRequest(body: body))
                if case .ok(let data, _) = r {
                    changeComment(editing.uuid ?? "") { old in
                        var c = data
                        c.firstReplies = nil
                        c.replies = old.replies
                        return c
                    }
                    onDone()
                } else {
                    onFail(r)
                }
                return
            }
            let root = replyTo.map { $0.parent ?? $0.uuid }
            let r = await CommunityApi.comment(uuid, CommunityCommentRequest(body: body, parent: replyTo?.uuid))
            guard case .ok(var c, _) = r else {
                onFail(r)
                return
            }
            c.firstReplies = nil
            if let parent = c.parent ?? root ?? nil {
                if let i = threads.firstIndex(where: { $0.comment.uuid == parent }) {
                    threads[i].replies.append(c)
                    threads[i].comment.replies = (threads[i].comment.replies ?? 0) + 1
                }
            } else {
                threads.append(CmThread(comment: c, replies: []))
            }
            changePost { $0.comments = ($0.comments ?? 0) + 1 }
            onDone()
        }
    }

    func delete(_ c: CommunityCommentDto, onDone: @escaping () -> Void, onFail: @escaping (String) -> Void, offline: String, generic: String) {
        Task {
            if let failed = await CommunityApi.deleteComment(c.uuid ?? "", offline: offline, generic: generic) {
                onFail(failed)
                return
            }
            if c.parent == nil {
                threads.removeAll { $0.comment.uuid == c.uuid }
                changePost { p in
                    p.comments = max((p.comments ?? 0) - 1 - (c.replies ?? 0), 0)
                    if p.acceptedComment == c.uuid { p.acceptedComment = nil }
                }
            } else {
                if let i = threads.firstIndex(where: { $0.comment.uuid == c.parent }) {
                    threads[i].replies.removeAll { $0.uuid == c.uuid }
                    threads[i].comment.replies = max((threads[i].comment.replies ?? 0) - 1, 0)
                }
                changePost { $0.comments = max(($0.comments ?? 0) - 1, 0) }
            }
            onDone()
        }
    }

    func react(_ c: CommunityCommentDto, _ kind: String?, onFail: @escaping (ApiResult<CommunityReactionDto>) -> Void) {
        let (n, _) = optimisticReaction(count: c.reactions ?? 0, top: [], before: c.myReaction, after: kind)
        changeComment(c.uuid ?? "") { var x = $0; x.reactions = n; x.myReaction = kind; return x }
        Task {
            let r = await CommunityApi.react("comments", c.uuid ?? "", CommunityReactionRequest(kind: kind))
            if case .ok(let data, _) = r {
                changeComment(c.uuid ?? "") { var x = $0; x.reactions = data.reactions; x.myReaction = data.myReaction; return x }
            } else {
                changeComment(c.uuid ?? "") { var x = $0; x.reactions = c.reactions; x.myReaction = c.myReaction; return x }
                onFail(r)
            }
        }
    }

    /// Marks (or, with nil, unmarks) a question's best answer; the marked comment moves to the top like the server orders it.
    func accept(_ comment: String?, onFail: @escaping (ApiResult<CommunityPostDto>) -> Void) {
        Task {
            let r = await CommunityApi.accept(uuid, CommunityAcceptRequest(comment: comment))
            guard case .ok(let p, _) = r else {
                onFail(r)
                return
            }
            post = p
            CommunityHub.shared.emit(.postUpdated(p))
            var list = threads
            for i in list.indices { list[i].comment.accepted = list[i].comment.uuid == p.acceptedComment }
            if let top = list.firstIndex(where: { $0.comment.accepted == true }), top > 0 { list.insert(list.remove(at: top), at: 0) }
            threads = list
        }
    }
}

/**
 * One post in full, then its comments (a question's best answer first and highlighted) with one level of replies, and the comment box
 * pinned at the bottom (reply-to and edit chips). The question's author can mark the best answer.
 */
@MainActor
struct CommunityPostScreen: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var hub = CommunityHub.shared
    @StateObject private var vm: PostDetailVm
    let uuid: String
    let nav: CommunityNav
    let onBack: () -> Void

    @FocusState private var focused: Bool
    @State private var replyTo: CommunityCommentDto?
    @State private var editing: CommunityCommentDto?
    @State private var text = ""
    @State private var sending = false
    @State private var inlineError: String?
    @State private var deleting: CommunityCommentDto?
    @State private var deleteBusy = false
    @State private var reporting: CommunityCommentDto?
    @State private var scrollToEnd = 0

    init(uuid: String, nav: CommunityNav, onBack: @escaping () -> Void) {
        self.uuid = uuid
        self.nav = nav
        self.onBack = onBack
        _vm = StateObject(wrappedValue: PostDetailVm(uuid: uuid))
    }

    private var offlineText: String { L("login_error_offline") }
    private var genericError: String { L("login_error_generic") }

    private func say<T>(_ r: ApiResult<T>) { AppToast.show(r.errorText(offlineText) ?? genericError) }

    var body: some View {
        CommunityFrame(title: L(vm.post?.kind == "question" ? "cm_question_title" : "cm_post_title"), onBack: onBack) {
            if vm.gone {
                EmptyState(icon: "magnifyingglass", title: L("cm_post_gone"), body: L("cm_post_gone_body")).padding(.top, 80)
            } else if vm.post == nil, let failure = vm.failure {
                LoadFailed(text: failure.errorText(offlineText) ?? genericError) { vm.refresh() }
            } else if let p = vm.post {
                VStack(spacing: 0) {
                    list(p)
                    commentBar
                }
            } else {
                VStack(spacing: 12) {
                    PostSkeleton()
                    ForEach(0..<3, id: \.self) { _ in CommentSkeleton() }
                }
                .padding(.horizontal, 12)
            }
        }
        .task { if CommunityHub.shared.home == nil { await CommunityHub.shared.refresh() } }
        .dorrDialog(isPresented: cmPresence($deleting)) {
            ConfirmDialog(icon: "trash.fill", title: L("cm_delete_comment_title"), message: L("cm_delete_comment_body"), loading: deleteBusy,
                          confirmLabel: L("common_delete"), onConfirm: {
                              guard let c = deleting else { return }
                              deleteBusy = true
                              vm.delete(c, onDone: {
                                  deleteBusy = false
                                  deleting = nil
                              }, onFail: {
                                  deleteBusy = false
                                  AppToast.show($0)
                              }, offline: offlineText, generic: genericError)
                          }, onDismiss: { if !deleteBusy { deleting = nil } })
        }
        .cmSheet(isPresented: cmPresence($reporting), fullHeight: true) {
            ReportSheet(subjectType: "comment", subject: reporting?.uuid ?? "") { reporting = nil }
        }
    }

    private func list(_ p: CommunityPostDto) -> some View {
        let canAccept = p.can?.accept == true && p.kind == "question"
        return ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(spacing: 10) {
                    PostCard(post: p, nav: nav, expanded: true, onComment: {
                        replyTo = nil
                        editing = nil
                        focused = true
                    }, onDeleted: onBack)
                    HStack(spacing: 6) {
                        Text(L("cm_comments_title")).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
                        if (p.comments ?? 0) > 0 { Text(verbatim: "(\(p.comments ?? 0))").font(.dorr(14, .bold)).foregroundStyle(wa.settingsMut) }
                        Spacer(minLength: 0)
                    }
                    .padding(.top, 6)
                    .padding(.horizontal, 4)
                    if vm.threads.isEmpty {
                        VStack(spacing: 8) {
                            Image(systemName: p.kind == "question" ? "bubble.left.and.bubble.right.fill" : "person.3.fill").font(.system(size: 32))
                                .foregroundStyle(wa.settingsMut.opacity(0.6))
                            Text(L(p.kind == "question" ? "cm_no_answers" : "cm_no_comments")).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsMut)
                                .multilineTextAlignment(.center)
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 26)
                    }
                    ForEach(Array(vm.threads.enumerated()), id: \.element.id) { i, t in
                        ThreadView(
                            t: t, post: p, canAccept: canAccept, nav: nav,
                            onReply: { c in
                                editing = nil
                                replyTo = c
                                focused = true
                            },
                            onEdit: { c in
                                replyTo = nil
                                editing = c
                                text = c.body ?? ""
                                focused = true
                            },
                            onDelete: { deleting = $0 },
                            onReport: { reporting = $0 },
                            onReact: { c, k in
                                if let why = CommunityActions.cannotWrite() { AppToast.show(why) } else { vm.react(c, k, onFail: say) }
                            },
                            onAccept: { c in vm.accept(c.accepted == true ? nil : c.uuid, onFail: say) },
                            onMoreReplies: { vm.moreReplies(t.comment.uuid ?? "") }
                        )
                        .onAppear { if i >= vm.threads.count - 4 { vm.more() } }
                    }
                    if vm.hasMore || vm.loadingMore { ListFooter(loading: true, failed: false, end: false) {} }
                    Color.clear.frame(height: 1).id("end")
                }
                .padding(.horizontal, 12)
                .padding(.bottom, 16)
            }
            .scrollDismissesKeyboard(.interactively)
            .refreshable { await vm.load() }
            .onChange(of: scrollToEnd) { _ in withAnimation { proxy.scrollTo("end", anchor: .bottom) } }
        }
    }

    private var commentBar: some View {
        CommentBar(
            home: hub.home, text: Binding(get: { text }, set: { text = $0; inlineError = nil }), replyTo: replyTo, editing: editing, busy: sending,
            error: inlineError, focus: $focused,
            onCancel: {
                if editing != nil { text = "" }
                replyTo = nil
                editing = nil
            },
            onVerify: nav.openVerification,
            onSend: send
        )
    }

    private func send() {
        let body = text.trimmingCharacters(in: .whitespacesAndNewlines)
        if body.isEmpty || sending { return }
        sending = true
        inlineError = nil
        let wasReply = replyTo != nil
        vm.send(body, replyTo: replyTo, editing: editing, onDone: {
            sending = false
            text = ""
            let edited = editing != nil
            replyTo = nil
            editing = nil
            focused = false
            // My new comment is at the end of the list: show it.
            if !edited && !wasReply {
                Task {
                    try? await Task.sleep(nanoseconds: 60_000_000)
                    scrollToEnd += 1
                }
            }
        }, onFail: { r in
            sending = false
            let msg = r.errorText(offlineText) ?? genericError
            if r.error?.httpStatus == 422 { inlineError = msg } else { AppToast.show(msg) }
        })
    }
}

@MainActor
private struct ThreadView: View {
    @Environment(\.wa) private var wa
    let t: CmThread
    let post: CommunityPostDto
    let canAccept: Bool
    let nav: CommunityNav
    let onReply: (CommunityCommentDto) -> Void
    let onEdit: (CommunityCommentDto) -> Void
    let onDelete: (CommunityCommentDto) -> Void
    let onReport: (CommunityCommentDto) -> Void
    let onReact: (CommunityCommentDto, String?) -> Void
    let onAccept: (CommunityCommentDto) -> Void
    let onMoreReplies: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            item(t.comment, canAccept && t.comment.hidden == nil, false)
            if !t.replies.isEmpty || t.hidden > 0 {
                VStack(alignment: .leading, spacing: 8) {
                    ForEach(t.replies, id: \.uuid) { r in item(r, false, true) }
                    if t.hidden > 0 || t.loading {
                        Button(action: onMoreReplies) {
                            HStack(spacing: 6) {
                                if t.loading {
                                    SmallSpinner(size: 14)
                                } else {
                                    Image(systemName: "arrowshape.turn.up.left.fill").font(.system(size: 13)).foregroundStyle(wa.red).scaleEffect(x: 1, y: -1)
                                }
                                Text(L("cm_more_replies", t.hidden)).font(.dorr(12.5, .heavy)).foregroundStyle(wa.red)
                            }
                            .padding(6)
                            .contentShape(RoundedRectangle(cornerRadius: 10))
                        }
                        .buttonStyle(.plain)
                        .disabled(t.loading)
                    }
                }
                .padding(.leading, 46)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func item(_ c: CommunityCommentDto, _ accept: Bool, _ isReply: Bool) -> some View {
        CommentItem(c: c, post: post, canAccept: accept, isReply: isReply, nav: nav, onReply: onReply, onEdit: onEdit, onDelete: onDelete,
                    onReport: onReport, onReact: onReact, onAccept: onAccept)
    }
}

@MainActor
private struct CommentItem: View {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    let c: CommunityCommentDto
    let post: CommunityPostDto
    let canAccept: Bool
    let isReply: Bool
    let nav: CommunityNav
    let onReply: (CommunityCommentDto) -> Void
    let onEdit: (CommunityCommentDto) -> Void
    let onDelete: (CommunityCommentDto) -> Void
    let onReport: (CommunityCommentDto) -> Void
    let onReact: (CommunityCommentDto, String?) -> Void
    let onAccept: (CommunityCommentDto) -> Void

    private var a: CommunityAuthorDto { c.author ?? CommunityAuthorDto() }
    private func openAuthor() { if let uuid = a.uuid { nav.openMember(uuid) } }

    var body: some View {
        let accepted = c.accepted == true && !isReply
        let bubble: Color = accepted ? WORK_TONE.opacity(wa.night ? 0.18 : 0.08) : (wa.night ? wa.darkWell : Color(hex: 0xF3F4F6))
        let shape = UnevenRoundedShape(topLeading: 4, topTrailing: 16, bottomLeading: 16, bottomTrailing: 16, rtl: direction == .rightToLeft)
        HStack(alignment: .top, spacing: 8) {
            AvatarImage(url: a.avatarUrl, size: isReply ? 30 : 38).onTapGesture(perform: openAuthor)
            VStack(alignment: .leading, spacing: 0) {
                VStack(alignment: .leading, spacing: 3) {
                    head
                    if accepted { SoftPill(text: L("cm_best_answer"), tone: WORK_TONE, icon: "checkmark.circle.fill", solid: true).padding(.vertical, 2) }
                    if let h = c.hidden { HiddenNote(underReview: false, reason: h.reason) }
                    ExpandableBody(text: c.body ?? "", key: "comment-\(c.uuid ?? "")", collapsedLines: 6, size: 13).padding(.trailing, 8)
                }
                .padding(.leading, 12)
                .padding(.trailing, 4)
                .padding(.top, 8)
                .padding(.bottom, 10)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(bubble, in: shape)
                .overlay { if accepted { shape.stroke(WORK_TONE.opacity(0.6), lineWidth: 1.5) } }
                actions.padding(.leading, 4).padding(.top, 2)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }

    private var head: some View {
        HStack(spacing: 4) {
            VStack(alignment: .leading, spacing: 0) {
                NameWithBadge(name: a.name, badge: a.badge, size: 13)
                if let headline = a.headline, !headline.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: headline).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .contentShape(Rectangle())
            .onTapGesture(perform: openAuthor)
            if a.uuid == post.author?.uuid && post.author?.uuid != nil { SoftPill(text: L("cm_author"), tone: wa.red) }
            Menu {
                let can = c.can ?? CommunityCommentCan()
                if can.edit == true { MenuLine(icon: "pencil", label: L("cm_edit_comment")) { onEdit(c) } }
                if can.delete == true { MenuLine(icon: "trash", label: L("cm_delete_comment"), danger: true) { onDelete(c) } }
                if can.report == true { MenuLine(icon: "flag.fill", label: L("cm_report_comment")) { onReport(c) } }
                if can.edit != true && can.delete != true && can.report != true {
                    MenuLine(icon: "person.fill", label: L("cm_view_profile"), action: openAuthor)
                }
            } label: {
                Image(systemName: "ellipsis").font(.system(size: 14, weight: .bold)).foregroundStyle(wa.settingsMut)
                    .frame(width: 30, height: 30).contentShape(Circle())
            }
            .accessibilityLabel(L("cm_more"))
        }
    }

    private var actions: some View {
        HStack(spacing: 0) {
            let time = cmRelativeTime(c.createdAt)
            Text(verbatim: c.edited == true ? "\(time) · \(L("cm_edited"))" : time).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            ReactButton(mine: c.myReaction, compact: true) { onReact(c, $0) }.padding(.leading, 4)
            if (c.reactions ?? 0) > 0 {
                Text(verbatim: "· \(c.reactions ?? 0)").font(.dorr(11.5, .semibold)).foregroundStyle(wa.settingsMut)
            }
            Button { onReply(c) } label: {
                Text(L("cm_reply")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut).padding(.horizontal, 6).padding(.vertical, 4)
            }
            .buttonStyle(.plain)
            .padding(.leading, 6)
            if canAccept && !isReply {
                Button { onAccept(c) } label: {
                    Text(L(c.accepted == true ? "cm_unmark_best" : "cm_mark_best")).font(.dorr(12, .heavy)).foregroundStyle(WORK_TONE).lineLimit(1)
                        .padding(.horizontal, 6).padding(.vertical, 4)
                }
                .buttonStyle(.plain)
            }
            Spacer(minLength: 0)
        }
    }
}

/// Compose's RoundedCornerShape with four different corners; `rtl` swaps start and end like Compose's start / end corners.
private struct UnevenRoundedShape: Shape {
    let topLeading: CGFloat
    let topTrailing: CGFloat
    let bottomLeading: CGFloat
    let bottomTrailing: CGFloat
    var rtl = false

    func path(in rect: CGRect) -> Path {
        let tl = rtl ? topTrailing : topLeading, tr = rtl ? topLeading : topTrailing
        let bl = rtl ? bottomTrailing : bottomLeading, br = rtl ? bottomLeading : bottomTrailing
        var p = Path()
        p.move(to: CGPoint(x: rect.minX + tl, y: rect.minY))
        p.addLine(to: CGPoint(x: rect.maxX - tr, y: rect.minY))
        p.addArc(center: CGPoint(x: rect.maxX - tr, y: rect.minY + tr), radius: tr, startAngle: .degrees(-90), endAngle: .degrees(0), clockwise: false)
        p.addLine(to: CGPoint(x: rect.maxX, y: rect.maxY - br))
        p.addArc(center: CGPoint(x: rect.maxX - br, y: rect.maxY - br), radius: br, startAngle: .degrees(0), endAngle: .degrees(90), clockwise: false)
        p.addLine(to: CGPoint(x: rect.minX + bl, y: rect.maxY))
        p.addArc(center: CGPoint(x: rect.minX + bl, y: rect.maxY - bl), radius: bl, startAngle: .degrees(90), endAngle: .degrees(180), clockwise: false)
        p.addLine(to: CGPoint(x: rect.minX, y: rect.minY + tl))
        p.addArc(center: CGPoint(x: rect.minX + tl, y: rect.minY + tl), radius: tl, startAngle: .degrees(180), endAngle: .degrees(270), clockwise: false)
        p.closeSubpath()
        return p
    }
}

@MainActor
private struct CommentSkeleton: View {
    @Environment(\.wa) private var wa

    var body: some View {
        HStack(alignment: .top, spacing: 8) {
            SkeletonBlock(height: 38, radius: 19, width: 38)
            VStack(alignment: .leading, spacing: 6) {
                SkeletonBlock(height: 11, fraction: 0.4)
                SkeletonBlock(height: 10)
                SkeletonBlock(height: 10, fraction: 0.7)
            }
            .padding(12)
            .background(wa.night ? wa.darkWell : Color(hex: 0xF3F4F6), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
    }
}

/// The comment box pinned at the bottom: a chip saying who I reply to (or that I edit), the field, the counter near the limit, send.
@MainActor
private struct CommentBar: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var session = Session.shared
    let home: CommunityHomeDto?
    let text: Binding<String>
    let replyTo: CommunityCommentDto?
    let editing: CommunityCommentDto?
    let busy: Bool
    let error: String?
    let focus: FocusState<Bool>.Binding
    let onCancel: () -> Void
    let onVerify: () -> Void
    let onSend: () -> Void

    var body: some View {
        let max = home?.limits?.commentMax ?? 1500
        let danger = Color(hex: 0xDC2626)
        VStack(alignment: .trailing, spacing: 8) {
            if let home, home.canWrite != true {
                WriteBlockedNote(home: home, onVerify: onVerify)
            } else {
                if replyTo != nil || editing != nil {
                    HStack(spacing: 6) {
                        Image(systemName: editing != nil ? "pencil" : "arrowshape.turn.up.left.fill").font(.system(size: 13)).foregroundStyle(wa.red)
                        Text(editing != nil ? L("cm_editing_comment") : L("cm_replying_to", replyTo?.author?.name ?? ""))
                            .font(.dorr(12.5, .bold)).foregroundStyle(wa.red).lineLimit(1)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        Button(action: onCancel) {
                            Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut)
                                .frame(width: 28, height: 28).contentShape(Circle())
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel(L("common_cancel"))
                    }
                    .padding(.leading, 10).padding(.trailing, 4).padding(.vertical, 4)
                    .background(wa.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                if let error {
                    Text(verbatim: error).font(.dorr(12.5, .semibold)).foregroundStyle(danger)
                        .padding(8).frame(maxWidth: .infinity, alignment: .leading)
                        .background(danger.opacity(0.08), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                }
                HStack(alignment: .bottom, spacing: 8) {
                    AvatarImage(url: home?.me?.avatarUrl ?? session.me?.avatarUrl, size: 36).padding(.bottom, 4)
                    DorrTextField(value: Binding(get: { text.wrappedValue }, set: { text.wrappedValue = String($0.prefix(max)) }),
                                  placeholder: L(replyTo != nil ? "cm_reply_hint" : "cm_comment_hint"), singleLine: false, minHeight: 44, focus: focus)
                        .frame(maxHeight: 150)
                    let ready = !text.wrappedValue.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty && !busy
                    Button(action: onSend) {
                        ZStack {
                            if busy {
                                SmallSpinner(color: .white, size: 18)
                            } else {
                                Image(systemName: editing != nil ? "checkmark" : "paperplane.fill").font(.system(size: 17, weight: .semibold)).foregroundStyle(.white)
                            }
                        }
                        .frame(width: 42, height: 42)
                        .background(ready ? wa.red : wa.red.opacity(0.35), in: Circle())
                    }
                    .buttonStyle(.plain)
                    .disabled(!ready)
                    .padding(.bottom, 2)
                    .accessibilityLabel(L("cm_send"))
                }
                if Double(text.wrappedValue.count) > Double(max) * 0.8 {
                    Text(verbatim: "\u{200E}\(text.wrappedValue.count) / \(max)").font(.dorr(11))
                        .foregroundStyle(text.wrappedValue.count >= max ? danger : wa.settingsMut)
                }
            }
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 10)
        .background(
            UnevenRoundedShape(topLeading: 22, topTrailing: 22, bottomLeading: 0, bottomTrailing: 0)
                .fill(wa.settingsCard)
                .shadow(color: Color(hex: 0x001B53).opacity(0.1), radius: 16, y: -2)
                .ignoresSafeArea(edges: .bottom)
        )
    }
}

// MARK: - a member

@MainActor
final class MemberVm: ObservableObject {
    private let uuid: String
    @Published private(set) var card: CommunityMemberDto?
    @Published private(set) var failure: ApiResult<CommunityMemberDto>?
    @Published private(set) var gone = false
    @Published private(set) var refreshing = false
    let posts: CursorRows<CommunityPostDto>
    private var job: Task<Void, Never>?
    private var jobId = 0
    private var jobActive = false
    private var bag = Set<AnyCancellable>()

    init(uuid: String) {
        self.uuid = uuid
        posts = CursorRows(key: { $0.uuid ?? "" }) { c in await CommunityApi.feed(member: uuid, cursor: c) }
        posts.objectWillChange.sink { [weak self] _ in self?.objectWillChange.send() }.store(in: &bag)
        CommunityHub.shared.changes.sink { [weak self] c in self?.apply(c) }.store(in: &bag)
        load()
    }

    private func apply(_ c: CommunityChange) {
        switch c {
        case .postUpdated(let p):
            posts.update(where: { $0.uuid == p.uuid }) { _ in p }
        case .postDeleted(let id):
            if posts.rows.contains(where: { $0.uuid == id }), var now = card {
                now.posts = Swift.max((now.posts ?? 0) - 1, 0)
                card = now
            }
            posts.remove { $0.uuid == id }
        case .postCreated(let p):
            if p.author?.uuid == uuid {
                posts.prepend(p)
                if var now = card {
                    now.posts = (now.posts ?? 0) + 1
                    card = now
                }
            }
        case .followChanged(let member, let following):
            posts.update(where: { $0.author?.uuid == member }) { var x = $0; x.author?.isFollowing = following; return x }
            if member == uuid, var now = card, (now.isFollowing ?? false) != following {
                now.isFollowing = following
                now.followers = Swift.max((now.followers ?? 0) + (following ? 1 : -1), 0)
                card = now
            }
        }
    }

    func load() {
        Task {
            refreshing = card != nil
            let r = await CommunityApi.member(uuid)
            refreshing = false
            switch r {
            case .ok(let c, _):
                card = c
                failure = nil
            case .failed(let e):
                if e.httpStatus == 404 { gone = true } else { failure = r }
            case .offline:
                failure = r
            }
        }
        job?.cancel()
        launch { await self.posts.reload() }
    }

    /// Pull-to-refresh: the card and the first page again, answered before the spinner goes.
    func reloadNow() async {
        load()
        await job?.value
    }

    private func launch(_ work: @escaping @MainActor () async -> Void) {
        jobId += 1
        let id = jobId
        jobActive = true
        job = Task { [weak self] in
            await work()
            if self?.jobId == id { self?.jobActive = false }
        }
    }

    func more() {
        if jobActive { return }
        launch { await self.posts.loadMore() }
    }

    func applyCard(_ c: CommunityMemberDto) { card = c }
}

/// A member's profile: the header (photo, name, badge, headline, specialties, bio, numbers, follow), then their posts.
@MainActor
struct CommunityMemberScreen: View {
    @Environment(\.wa) private var wa
    @StateObject private var vm: MemberVm
    let uuid: String
    let nav: CommunityNav
    let onBack: () -> Void

    init(uuid: String, nav: CommunityNav, onBack: @escaping () -> Void) {
        self.uuid = uuid
        self.nav = nav
        self.onBack = onBack
        _vm = StateObject(wrappedValue: MemberVm(uuid: uuid))
    }

    var body: some View {
        CommunityFrame(title: L("cm_profile_title"), onBack: onBack) {
            if vm.gone {
                EmptyState(icon: "magnifyingglass", title: L("cm_member_gone"), body: L("cm_member_gone_body")).padding(.top, 80)
            } else if vm.card == nil, let failure = vm.failure {
                LoadFailed(text: failure.errorText(L("login_error_offline")) ?? L("login_error_generic")) { vm.load() }
            } else {
                content
            }
        }
    }

    private var content: some View {
        let card = vm.card
        let rows = vm.posts
        let isMe = card?.isMe == true
        return ScrollView {
            LazyVStack(alignment: .leading, spacing: 10) {
                if let card {
                    MemberHeader(
                        m: card,
                        onFollow: { CommunityActions.follow(card.uuid ?? "", !(card.isFollowing ?? false), onCard: vm.applyCard) },
                        onPeople: { which in nav.openPeople(card.uuid ?? "", which) },
                        onCompose: { nav.compose("post") }
                    )
                } else {
                    MemberSkeleton()
                }
                Text(L(isMe ? "cm_my_posts" : "cm_member_posts")).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
                    .padding(.top, 6).padding(.leading, 4)
                if !rows.loaded && rows.failure == nil {
                    ForEach(0..<2, id: \.self) { _ in PostSkeleton() }
                } else if rows.loaded && rows.rows.isEmpty && rows.failure == nil {
                    EmptyState(icon: "square.and.pencil", title: L(isMe ? "cm_empty_mine" : "cm_member_no_posts"),
                               body: L(isMe ? "cm_empty_mine_body" : "cm_member_no_posts_body"))
                        .padding(.top, 24).padding(.bottom, 12)
                } else {
                    ForEach(Array(rows.rows.enumerated()), id: \.element.uuid) { i, post in
                        PostCard(post: post, nav: nav).onAppear { if i >= rows.rows.count - 4 { vm.more() } }
                    }
                    ListFooter(loading: rows.loading, failed: rows.failure != nil, end: !rows.hasMore && !rows.rows.isEmpty) {
                        rows.retry()
                        vm.more()
                    }
                }
            }
            .padding(.horizontal, 12)
            .padding(.bottom, 24)
        }
        .refreshable { await vm.reloadNow() }
    }
}

@MainActor
private struct MemberHeader: View {
    @Environment(\.wa) private var wa
    let m: CommunityMemberDto
    let onFollow: () -> Void
    let onPeople: (String) -> Void
    let onCompose: () -> Void

    var body: some View {
        let accent = wa.red
        VStack(alignment: .leading, spacing: 0) {
            ZStack(alignment: .bottomLeading) {
                VStack(spacing: 0) {
                    LinearGradient(colors: [accent, cmScaled(accent, 0.7, 0.7, 0.8), accent.opacity(0.7)], startPoint: .topLeading, endPoint: .bottomTrailing)
                        .frame(height: 88)
                    Spacer(minLength: 0)
                }
                AvatarImage(url: m.avatarUrl, size: 84)
                    .padding(4)
                    .background(wa.settingsCard, in: Circle())
                    .padding(.leading, 16)
            }
            .frame(height: 132)
            VStack(alignment: .leading, spacing: 8) {
                HStack(spacing: 5) {
                    Text(verbatim: m.name.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? L("cm_member"))
                        .font(.dorr(21, .black)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    if m.verified == true { Image(systemName: "checkmark.seal.fill").font(.system(size: 17)).foregroundStyle(Color(hex: 0x2563EB)) }
                }
                if m.badge?.name != nil { PlanBadge(badge: m.badge, large: true) }
                if let headline = m.headline, !headline.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: headline).font(.dorr(14, .medium)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                }
                let specialties = (m.specialties ?? []).enumerated()
                    .sorted { ($0.element.primary == true ? 0 : 1, $0.offset) < ($1.element.primary == true ? 0 : 1, $1.offset) }.map(\.element)
                if !specialties.isEmpty {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 6) {
                            ForEach(Array(specialties.enumerated()), id: \.offset) { _, s in
                                SoftPill(text: s.name ?? "", tone: s.primary == true ? accent : wa.settingsMut, icon: s.primary == true ? "star.fill" : nil)
                            }
                        }
                    }
                }
                if let bio = m.bio, !bio.trimmingCharacters(in: .whitespaces).isEmpty {
                    ExpandableBody(text: bio, key: "bio-\(m.uuid ?? "")", collapsedLines: 3, size: 13)
                }
                HStack(spacing: 0) {
                    StatCell(value: "\(m.posts ?? 0)", label: L("cm_stat_posts"), action: nil)
                    StatDivider()
                    StatCell(value: "\(m.followers ?? 0)", label: L("cm_stat_followers")) { onPeople("followers") }
                    StatDivider()
                    StatCell(value: "\(m.following ?? 0)", label: L("cm_stat_following")) { onPeople("following") }
                }
                .padding(.vertical, 10)
                .background(wa.night ? wa.darkWell : accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                if m.isMe == true {
                    PillButton(label: L("cm_start_post_short"), icon: "square.and.pencil", filled: true, action: onCompose)
                } else {
                    let following = m.isFollowing ?? false
                    PillButton(label: L(following ? "cm_following" : "cm_follow"), icon: following ? "checkmark" : "person.badge.plus",
                               filled: !following, action: onFollow)
                }
            }
            .padding(.horizontal, 16)
            .padding(.top, 8)
            .padding(.bottom, 16)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous))
        .settingsSurface(24)
    }
}

@MainActor
private struct StatCell: View {
    @Environment(\.wa) private var wa
    let value: String
    let label: String
    let action: (() -> Void)?

    var body: some View {
        let cell = VStack(spacing: 0) {
            Text(verbatim: value).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
            Text(verbatim: label).font(.dorr(11.5, .bold)).foregroundStyle(action != nil ? wa.red : wa.settingsMut)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 2)
        .contentShape(RoundedRectangle(cornerRadius: 10))
        if let action {
            Button(action: action) { cell }.buttonStyle(.plain)
        } else {
            cell
        }
    }
}

@MainActor
private struct StatDivider: View {
    @Environment(\.wa) private var wa

    var body: some View {
        Rectangle().fill(wa.night ? wa.line : Color(hex: 0x001B53).opacity(0.1)).frame(width: 1, height: 30)
    }
}

/// A wide pill button: filled with the app colour, or outlined.
@MainActor
struct PillButton: View {
    @Environment(\.wa) private var wa
    let label: String
    let icon: String?
    let filled: Bool
    var enabled = true
    let action: () -> Void

    var body: some View {
        let accent = wa.red
        Button(action: action) {
            HStack(spacing: 7) {
                if let icon { Image(systemName: icon).font(.system(size: 16, weight: .semibold)) }
                Text(verbatim: label).font(.dorr(15, .heavy))
            }
            .foregroundStyle(filled ? .white : accent)
            .frame(maxWidth: .infinity, minHeight: 46)
            .background(filled ? accent.opacity(enabled ? 1 : 0.5) : .clear, in: Capsule())
            .overlay { if !filled { Capsule().stroke(accent, lineWidth: 1.5) } }
            .contentShape(Capsule())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

@MainActor
private struct MemberSkeleton: View {
    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            SkeletonBlock(height: 84, radius: 42, width: 84)
            SkeletonBlock(height: 18, fraction: 0.5)
            SkeletonBlock(height: 12, fraction: 0.8)
            SkeletonBlock(height: 56, radius: 16)
            SkeletonBlock(height: 46, radius: 23)
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(24)
    }
}

// MARK: - followers / following

@MainActor
private final class PeopleVm: ObservableObject {
    let rows = CursorRows<CommunityAuthorDto>(key: { $0.uuid ?? "" })
    private var bag = Set<AnyCancellable>()

    init() { rows.objectWillChange.sink { [weak self] _ in self?.objectWillChange.send() }.store(in: &bag) }

    /// Another list (followers ↔ following): a fresh list for it, like Android's `remember(which)`.
    func show(_ uuid: String, _ which: String) async {
        rows.reset()
        rows.fetch = { c in await CommunityApi.people(uuid, which, cursor: c) }
        await rows.reload()
    }
}

/// Who follows a member, and whom they follow (two tabs), page by page.
@MainActor
struct CommunityPeopleScreen: View {
    @StateObject private var vm = PeopleVm()
    let uuid: String
    let startWhich: String
    let nav: CommunityNav
    let onBack: () -> Void
    @State private var which: String

    init(uuid: String, startWhich: String, nav: CommunityNav, onBack: @escaping () -> Void) {
        self.uuid = uuid
        self.startWhich = startWhich
        self.nav = nav
        self.onBack = onBack
        _which = State(initialValue: startWhich == "following" ? "following" : "followers")
    }

    var body: some View {
        let rows = vm.rows
        CommunityFrame(title: L("cm_people_title"), onBack: onBack) {
            VStack(spacing: 0) {
                Segmented(options: [("followers", L("cm_stat_followers")), ("following", L("cm_stat_following"))], selected: which) { which = $0 }
                    .padding(.horizontal, 12)
                    .padding(.bottom, 10)
                ScrollView {
                    LazyVStack(spacing: 8) {
                        if !rows.loaded && rows.failure == nil {
                            ForEach(0..<5, id: \.self) { _ in PersonSkeleton() }
                        } else if rows.rows.isEmpty && rows.failure != nil {
                            LoadFailed(text: rows.failure?.errorText(L("login_error_offline")) ?? L("login_error_generic")) {
                                rows.retry()
                                Task { await rows.reload() }
                            }
                        } else if rows.loaded && rows.rows.isEmpty {
                            EmptyState(icon: "person.2.fill", title: L(which == "followers" ? "cm_no_followers" : "cm_no_following"), body: L("cm_people_empty_body"))
                                .padding(.top, 50)
                        } else {
                            ForEach(Array(rows.rows.enumerated()), id: \.element.uuid) { i, person in
                                PersonRow(p: person) { if let id = person.uuid { nav.openMember(id) } }
                                    .onAppear { if i >= rows.rows.count - 4 { Task { await rows.loadMore() } } }
                            }
                            ListFooter(loading: rows.loading, failed: rows.failure != nil, end: false) {
                                rows.retry()
                                Task { await rows.loadMore() }
                            }
                        }
                    }
                    .padding(.horizontal, 12)
                    .padding(.bottom, 24)
                }
            }
        }
        .task(id: which) { await vm.show(uuid, which) }
    }
}

@MainActor
private struct PersonRow: View {
    @Environment(\.wa) private var wa
    let p: CommunityAuthorDto
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 12) {
                AvatarImage(url: p.avatarUrl, size: 48)
                VStack(alignment: .leading, spacing: 0) {
                    NameWithBadge(name: p.name, badge: p.badge)
                    if let headline = p.headline, !headline.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(verbatim: headline).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(2)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if p.isMe == true { SoftPill(text: L("cm_you"), tone: wa.red) }
            }
            .padding(12)
            .settingsSurface(18, elevation: 3)
            .contentShape(RoundedRectangle(cornerRadius: 18))
        }
        .buttonStyle(.plain)
    }
}

@MainActor
private struct PersonSkeleton: View {
    var body: some View {
        HStack(spacing: 12) {
            SkeletonBlock(height: 48, radius: 24, width: 48)
            VStack(alignment: .leading, spacing: 6) {
                SkeletonBlock(height: 12, fraction: 0.45)
                SkeletonBlock(height: 10, fraction: 0.7)
            }
        }
        .padding(12)
        .settingsSurface(18, elevation: 3)
    }
}
