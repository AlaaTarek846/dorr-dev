import SwiftUI

// androidApp `freelance/FreelanceKit.kt`, `FreelancePaging.kt`, `FreelanceCancelReason.kt`, `FreelanceLink.kt` — the pieces the
// freelance screens share, in the look of the organizer form of the events: an icon tile with a title and a line, headings,
// tinted buttons, a form that opens from the bottom, skeletons while a page is read; the lists that fill page by page; the
// reasons for calling work off; and the page a tapped notification opens.

/// The head of a page or a form: an icon on a soft tile, a title and a line saying what it is for.
struct FlIntro: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    var text = ""

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: icon).font(.system(size: 22, weight: .semibold)).foregroundStyle(wa.accent)
                .frame(width: 52, height: 52)
                .background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            VStack(alignment: .leading, spacing: 3) {
                Text(verbatim: title).font(.dorr(19, .heavy)).foregroundStyle(wa.settingsInk)
                if !text.isEmpty { Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(3) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }
}

/// A heading inside a form: a small icon, the name of the group, and an optional line under it.
struct FlHeading: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String
    var hint: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            HStack(spacing: 7) {
                Image(systemName: icon).font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.accent)
                Text(verbatim: text).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
            }
            if let hint, !hint.isEmpty { Text(verbatim: hint).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3) }
        }
    }
}

enum FlTone { case primary, soft, danger, warn }

/// A button: filled for the main step, tinted for the others (red to step back, amber for a dispute).
struct FlAction: View {
    @Environment(\.wa) private var wa
    let text: String
    var icon: String?
    var tone: FlTone = .soft
    var enabled = true
    var loading = false
    let action: () -> Void

    var body: some View {
        let base: Color = tone == .danger ? flRed : (tone == .warn ? flAmber : wa.accent)
        let filled = tone == .primary
        let ink: Color = filled ? .white : base
        Button(action: action) {
            HStack(spacing: 8) {
                if loading {
                    ProgressView().tint(ink).scaleEffect(0.8)
                } else if let icon {
                    Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(ink.opacity(enabled ? 1 : 0.5))
                }
                Text(verbatim: text).font(.dorr(14, .bold)).foregroundStyle(ink.opacity(enabled ? 1 : 0.5)).multilineTextAlignment(.center)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 13).padding(.horizontal, 14)
            .background(filled ? base.opacity(enabled ? 1 : 0.45) : base.opacity(enabled ? 0.10 : 0.05), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled || loading)
    }
}

/// A small line of colour with an icon (a hint, a warning, a result).
struct FlNotice: View {
    let text: String
    let icon: String
    let color: Color

    var body: some View {
        HStack(alignment: .top, spacing: 9) {
            Image(systemName: icon).font(.system(size: 14, weight: .semibold)).foregroundStyle(color).padding(.top, 1)
            Text(verbatim: text).font(.dorr(12.5, .semibold)).foregroundStyle(color).lineSpacing(4).frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(.horizontal, 12).padding(.vertical, 10)
        .background(color.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// A figure with its name, in a tile (the project's budget, the days, the offers).
struct FlStat: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let value: String

    var body: some View {
        VStack(alignment: .leading, spacing: 3) {
            HStack(spacing: 5) {
                Image(systemName: icon).font(.system(size: 11, weight: .semibold)).foregroundStyle(wa.accent)
                Text(verbatim: label).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineLimit(1)
            }
            Text(verbatim: value).font(.dorr(13.5, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1).minimumScaleFactor(0.8)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 10).padding(.vertical, 9)
        .background(wa.night ? wa.darkWell : wa.accent.opacity(0.07), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// A form over the page (not a dialog): the same head as the organizer form, then its fields. Shown with
/// `.dorrDialog(isPresented:, bottom: true)`.
struct FlSheet<Content: View>: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let text: String
    @ViewBuilder let content: () -> Content

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                Capsule().fill(wa.settingsMut.opacity(0.35)).frame(width: 36, height: 4).frame(maxWidth: .infinity).padding(.top, 10)
                FlIntro(icon: icon, title: title, text: text)
                content()
            }
            .padding(.horizontal, 20).padding(.bottom, 34)
        }
        .scrollDismissesKeyboard(.interactively)
        .frame(maxHeight: UIScreen.main.bounds.height * 0.88)
        .fixedSize(horizontal: false, vertical: true)
        .background(wa.night ? wa.darkCard : .white)
        .clipShape(UnevenRoundedRectangleCompat(topRadius: 28))
        .contentShape(Rectangle())
        .onTapGesture {}
        .frame(maxHeight: .infinity, alignment: .bottom)
        .ignoresSafeArea(edges: .bottom)
    }
}

/// Grey blocks that stand for a card while the page is read (instead of a spinner).
struct FlSkeletonList: View {
    var count = 4
    var height: CGFloat = 104

    var body: some View {
        VStack(spacing: 10) {
            ForEach(0..<count, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: height) }
        }
        .padding(.horizontal, 16).padding(.vertical, 10)
        .frame(maxHeight: .infinity, alignment: .top)
    }
}

struct FlSkeletonDetail: View {
    var body: some View {
        VStack(spacing: 12) {
            WaSkeleton(cornerRadius: 20).frame(height: 120)
            HStack(spacing: 8) { ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 14).frame(height: 56) } }
            WaSkeleton(cornerRadius: 18).frame(height: 150)
            WaSkeleton(cornerRadius: 18).frame(height: 90)
        }
        .padding(.horizontal, 16).padding(.vertical, 6)
        .frame(maxHeight: .infinity, alignment: .top)
    }
}

struct FlTab {
    let icon: String
    let label: String
    var badge = 0
}

/// The sections of a long page, side by side: the chosen one is filled.
struct FlTabs: View {
    @Environment(\.wa) private var wa
    let tabs: [FlTab]
    let selected: Int
    let onSelect: (Int) -> Void

    var body: some View {
        HStack(spacing: 4) {
            ForEach(tabs.indices, id: \.self) { i in
                let t = tabs[i]
                let on = i == selected
                let ink: Color = on ? .white : wa.settingsMut
                Button { onSelect(i) } label: {
                    HStack(spacing: 5) {
                        Image(systemName: t.icon).font(.system(size: 13, weight: .semibold))
                        Text(verbatim: t.label).font(.dorr(12.5, .bold)).lineLimit(1)
                        if t.badge > 0 {
                            Text(verbatim: "\(t.badge)").font(.dorr(10.5, .heavy)).foregroundStyle(on ? .white : wa.accent)
                                .padding(.horizontal, 6).padding(.vertical, 1)
                                .background(on ? Color.white.opacity(0.25) : wa.accent.opacity(0.12), in: Capsule())
                        }
                    }
                    .foregroundStyle(ink)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 10)
                    .background(on ? wa.accent : .clear, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
        }
        .padding(4)
        .background(wa.night ? wa.darkCard : .white, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}

// MARK: - Paging (FreelancePaging.kt)

/// A list that fills itself page by page while the client scrolls (nothing on a timer): `reload` reads the first page and
/// replaces what is shown, `loadMore` adds the next page under it. One request at a time; a row that moved between pages is
/// not shown twice. The server says whether another page exists (`pagination.has_more_pages`). A screen calls `reset` when
/// its list changes (another filter) and `reload` when it opens, when a notice arrives and when the connection returns.
@MainActor
final class PagedRows<T: Decodable>: ObservableObject {
    @Published private(set) var rows: [T] = []
    /// The first page has been answered (rows or an error) — before that the screen shows its skeleton.
    @Published private(set) var loaded = false
    @Published private(set) var loading = false
    @Published private(set) var hasMore = false
    /// The text of the last page that failed; cleared by the next success.
    @Published private(set) var error: String?

    private let key: (T) -> AnyHashable
    private var fetch: ((Int) async throws -> ApiEnvelope<[T]>)?
    private var page = 0
    /// A reload or a new list makes the answers still on their way stale.
    private var generation = 0

    init(key: @escaping (T) -> AnyHashable) { self.key = key }

    /// Another list (a new filter): it starts again from its skeleton.
    func reset(_ fetch: @escaping (Int) async throws -> ApiEnvelope<[T]>) {
        generation += 1
        self.fetch = fetch
        rows = []
        loaded = false
        loading = false
        hasMore = false
        error = nil
        page = 0
    }

    func reload() async {
        generation += 1
        loading = false
        await load(1)
    }

    func loadMore() async {
        if loaded && hasMore && error == nil { await load(page + 1) }
    }

    /// After a failed page, the next scroll tries it again.
    func clearError() { error = nil }

    /// Changes one row where it is, without reading anything again.
    func update(where match: (T) -> Bool, _ change: (T) -> T) {
        if let i = rows.firstIndex(where: match) { rows[i] = change(rows[i]) }
    }

    /// Takes rows out where they are (a favourite removed).
    func remove(where match: (T) -> Bool) { rows.removeAll(where: match) }

    /// The row at `index` came on screen: the next page comes when the last few rows show.
    func appeared(_ index: Int) {
        if index >= rows.count - 4 { Task { await loadMore() } }
    }

    private func load(_ next: Int) async {
        guard let fetch, !loading else { return }
        let gen = generation
        loading = true
        do {
            let envelope = try await fetch(next)
            guard gen == generation else { return }
            let data = envelope.data ?? []
            if next == 1 { rows = [] }
            let seen = Set(rows.map(key))
            rows += data.filter { !seen.contains(key($0)) }
            page = next
            hasMore = envelope.pagination?.hasMorePages == true
            error = nil
        } catch {
            guard gen == generation else { return }
            if Task.isCancelled || error is CancellationError || (error as? URLError)?.code == .cancelled {
                // The screen went away or reloads: never stay on the spinner.
                loading = false
                return
            }
            self.error = flError(error)
        }
        loading = false
        loaded = true
    }
}

/// Under a paged list: a small spinner while the next page comes (or may come).
struct PagedSpinner<T: Decodable>: View {
    @Environment(\.wa) private var wa
    @ObservedObject var paged: PagedRows<T>

    var body: some View {
        if paged.loaded && !paged.rows.isEmpty && (paged.loading || paged.hasMore) && paged.error == nil {
            ProgressView().tint(wa.accent).frame(maxWidth: .infinity).padding(12)
                .onAppear { Task { await paged.loadMore() } }
        }
    }
}

/// A failed page under a list: tap to try it again.
struct PagedError<T: Decodable>: View {
    @ObservedObject var paged: PagedRows<T>

    var body: some View {
        if let error = paged.error, paged.loaded {
            Button {
                paged.clearError()
                Task { await paged.loadMore() }
            } label: {
                Text(verbatim: error).font(.dorr(13)).foregroundStyle(flRed).frame(maxWidth: .infinity).padding(10)
            }
            .buttonStyle(.plain)
        }
    }
}

// MARK: - Why work is called off (FreelanceCancelReason.kt, FL-607)

/// The client picks one of a short fixed list (the server turns it into who it points at, for staff only — nothing is charged
/// because of it) and may add a few words; "other" needs the words.
let flCancelReasons: [(String, String)] = [
    ("scope_changed", "fl_cr_scope_changed"),
    ("client_unresponsive", "fl_cr_client_unresponsive"),
    ("not_delivered", "fl_cr_not_delivered"),
    ("quality", "fl_cr_quality"),
    ("mutual", "fl_cr_mutual"),
    ("force_majeure", "fl_cr_force_majeure"),
    ("other", "fl_cr_other"),
]

/// The reason may be sent when one is picked and, for "other", a few words are written.
func flCancelReasonReady(_ code: String?, _ note: String) -> Bool {
    code != nil && (code != "other" || note.trimmingCharacters(in: .whitespacesAndNewlines).count >= 5)
}

struct CancelReasonPicker: View {
    @Environment(\.wa) private var wa
    let code: String?
    @Binding var note: String
    let onCode: (String) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(L("fl_cr_title")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            ForEach(flCancelReasons, id: \.0) { key, label in
                FlChip(text: L(label), selected: code == key) { onCode(key) }
            }
            DorrTextArea(value: Binding(get: { note }, set: { note = String($0.prefix(500)) }), placeholder: L("fl_cr_note_hint"), minHeight: 70)
        }
    }
}

// MARK: - The page a notification opens (FreelanceLink.kt)

/// A Freelance page to open when the Freelance screens show — set by a tapped notification (the in-app list or a push).
/// The value is a page of `FreelanceHost` ("show:<project>", "offers:<project>", "agreement:<project>", …).
@MainActor
final class FreelanceLink: ObservableObject {
    static let shared = FreelanceLink()

    @Published var pending: String?

    func show(_ page: String) { pending = page }

    /// The page a client's freelance notification belongs to. `event` is the delivered one ("freelance.offer.received"); the
    /// ids come from the notification's data (`project`, `offer`, `dispute`). Without a project the list opens.
    nonisolated static func pageFor(event: String?, project: String?, offer: String?, dispute: String?) -> String {
        let name = (event ?? "").hasPrefix("freelance.") ? String((event ?? "").dropFirst("freelance.".count)) : (event ?? "")
        let group = name.split(separator: ".", maxSplits: 1, omittingEmptySubsequences: false).first.map(String.init) ?? ""
        guard let project, !project.isEmpty else { return "list" }
        switch group {
        case "offer": return offer.map { $0.isEmpty ? "offers:\(project)" : "offer:\(project):\($0)" } ?? "offers:\(project)"
        case "interview": return "offers:\(project)"
        case "invitation": return "invites:\(project)"
        case "dispute": return dispute.map { $0.isEmpty ? "agreement:\(project)" : "dispute:\(project):\($0)" } ?? "agreement:\(project)"
        case "agreement", "milestone", "change", "report", "review", "payment": return "agreement:\(project)"
        case "sanction": return "list"
        default: return "show:\(project)"
        }
    }
}
