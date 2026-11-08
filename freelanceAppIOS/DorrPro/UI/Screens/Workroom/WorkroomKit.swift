import SwiftUI

// freelanceApp `ui/screens/workroom/WorkroomKit.kt` — one list of the Workroom (tasks, files, decisions, the timeline): pages of
// `WORK_PAGE` rows, read again as a whole on open, on a live event, on a notice and when the connection returns. On iOS the paging itself is
// `PagedRows` (a newer read replaces an older one that is still on its way, so a filter changed in the middle of a read is never lost), and
// nothing here runs on a timer.

/// What a Workroom tab shows when it has no rows: an icon, a title and a line.
struct WorkEmpty {
    let icon: String
    let title: String
    let text: String
}

/// Waits for a burst of changes to settle (a live event, a typed word) and then runs once — an answer to what happened, never a poll.
@MainActor
final class WorkDebouncer {
    private var task: Task<Void, Never>?

    func fire(after nanoseconds: UInt64 = 350_000_000, _ action: @escaping @MainActor () -> Void) {
        task?.cancel()
        task = Task {
            try? await Task.sleep(nanoseconds: nanoseconds)
            if Task.isCancelled { return }
            action()
        }
    }

    func cancel() {
        task?.cancel()
        task = nil
    }
}

/// The round «add» button of a tab (bottom corner, over the list).
struct WorkFab: View {
    @Environment(\.wa) private var wa
    let label: String
    var icon = "plus"
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 8) {
                Image(systemName: icon).font(.system(size: 16, weight: .bold)).frame(width: 20, height: 20)
                Text(verbatim: label).font(.dorr(14, .heavy))
            }
            .foregroundStyle(.white)
            .padding(.horizontal, 18)
            .padding(.vertical, 14)
            .background(wa.red, in: Capsule())
            .shadow(color: wa.night ? Color.clear : wa.red.opacity(0.4), radius: 10, y: 4)
            .contentShape(Capsule())
        }
        .buttonStyle(.plain)
    }
}

/// A row of a list with its place (the key a `ForEach` follows, and the index the paging reads).
private struct FeedRow<T>: Identifiable {
    let id: String
    let index: Int
    let item: T
}

/// The body of a Workroom tab: `header` over the rows (a skeleton before the first read, an empty page, the rows, a footer for the next page)
/// and a round button when the person may add. The first read's failure is shown with «try again»; pulling the list down reads it again.
struct FeedList<T, Header: View, Row: View>: View {
    @Environment(\.wa) private var wa
    @ObservedObject var feed: PagedRows<T>
    let keyOf: (T) -> String
    let empty: WorkEmpty
    var fabLabel: String?
    var onFab: (() -> Void)?
    @ViewBuilder let header: () -> Header
    @ViewBuilder let row: (Int, T) -> Row

    var body: some View {
        ZStack(alignment: .bottomTrailing) {
            ScrollView {
                LazyVStack(spacing: 10) {
                    header()
                    rows
                    PagedFooter(paged: feed) { Task { await feed.loadMore() } }
                }
                .padding(.horizontal, 16)
                .padding(.top, 4)
                .padding(.bottom, onFab != nil ? 104 : 28)
            }
            .refreshable { await feed.reload() }
            .scrollDismissesKeyboard(.interactively)
            if let onFab, let fabLabel {
                WorkFab(label: fabLabel, action: onFab).padding(16)
            }
        }
    }

    @ViewBuilder
    private var rows: some View {
        if !feed.loaded && feed.failure == nil {
            SkeletonList(count: 3)
        } else if feed.rows.isEmpty, let failure = feed.failure {
            Button {
                feed.retry()
                Task { await feed.reload() }
            } label: {
                let text = (failure.errorText(L("login_error_offline")) ?? L("login_error_generic")) + "  ·  " + L("common_retry")
                Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(wa.red).multilineTextAlignment(.center)
                    .frame(maxWidth: .infinity)
                    .padding(24)
                    .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
        } else if feed.rows.isEmpty {
            EmptyState(icon: empty.icon, title: empty.title, body: empty.text).padding(.top, 28)
        } else {
            ForEach(items) { r in
                row(r.index, r.item).onAppear { feed.appeared(r.index) }
            }
        }
    }

    private var items: [FeedRow<T>] {
        feed.rows.enumerated().map { pair in FeedRow(id: keyOf(pair.element), index: pair.offset, item: pair.element) }
    }
}
