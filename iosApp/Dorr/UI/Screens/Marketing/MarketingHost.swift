import SwiftUI

/// androidApp `ui/screens/marketing/MarketingHost.kt` — the pages of the AI marketing director
/// (docs/marketing_tasks.md), the link that opens it from anywhere, and the page stack with its toast.
enum MkRoute: Equatable {
    case home
    case packages
    case project(id: String)
    case discovery(projectId: String)
    case research(runId: String)
    case plan(planId: String)
    case finance(planId: String)
    case team(projectId: String)
    // Phase B.
    case calendar(projectId: String)
    case content(projectId: String, itemId: String)
    case studio(projectId: String)
    case budgets(projectId: String)
    case results(projectId: String)
    case report(projectId: String)
    // Phase C.
    case platforms(projectId: String)
    case campaigns(projectId: String)
    case campaign(projectId: String, campaignId: String)
    // Phase D.
    case optimise(projectId: String)

    var isHome: Bool { self == .home }
}

/// Opens the section from anywhere (Account row, the Services tab's `marketing` tile).
@MainActor
final class MarketingLink: ObservableObject {
    static let shared = MarketingLink()

    @Published private(set) var open = false
    @Published var pending: MkRoute?

    func show(_ route: MkRoute? = nil) {
        pending = route
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42)) { open = true }
    }

    func close() {
        open = false
        pending = nil
    }
}

struct MkToast: Equatable {
    let text: String
    let error: Bool
    let id = UUID()
}

/// The section's page stack, its toast, and two shared signals: [status] (the package, read on open and after a
/// purchase) and [changes] — bumped by every `marketing.job.changed` / `marketing.budget.alert` push so open pages
/// reload once (no polling).
@MainActor
final class MkHost: ObservableObject {
    @Published private(set) var stack: [MkRoute] = [.home]
    @Published private(set) var pageId = 0
    @Published private(set) var forward = true
    @Published private(set) var toast: MkToast?
    @Published var dialog: WeDialogSpec?
    @Published var status: MkStatusDto?
    @Published private(set) var statusTick = 0
    @Published private(set) var changes = 0
    /// Project id of the last push (pages of another project ignore it).
    @Published private(set) var changedProject: String?
    var onExit: () -> Void
    private var toastTask: Task<Void, Never>?

    init(onExit: @escaping () -> Void) {
        self.onExit = onExit
    }

    var current: MkRoute { stack[stack.count - 1] }

    func push(_ route: MkRoute) {
        forward = true
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32)) {
            stack.append(route)
            pageId += 1
        }
    }

    func replace(_ route: MkRoute) {
        forward = true
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32)) {
            stack[stack.count - 1] = route
            pageId += 1
        }
    }

    func pop() {
        if stack.count <= 1 {
            onExit()
            return
        }
        forward = false
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.28)) {
            _ = stack.removeLast()
            pageId += 1
        }
    }

    func open(_ route: MkRoute) {
        forward = true
        var next: [MkRoute] = [.home]
        if !route.isHome { next.append(route) }
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32)) {
            stack = next
            pageId += 1
        }
    }

    func reloadStatus() { statusTick += 1 }

    func changed(_ projectId: String?) {
        changedProject = projectId
        changes += 1
    }

    /// Bumps only for pushes about [projectId] (or about any project when the push says none).
    func projectChanges(_ projectId: String) -> Int {
        changedProject == nil || changedProject == projectId ? changes : -1
    }

    func showToast(_ text: String, error: Bool = false) {
        guard !text.trimmingCharacters(in: .whitespaces).isEmpty else { return }
        withAnimation(.easeOut(duration: 0.26)) { toast = MkToast(text: text, error: error) }
        toastTask?.cancel()
        toastTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: error ? 3_400_000_000 : 2_300_000_000)
            guard !Task.isCancelled else { return }
            withAnimation(.easeIn(duration: 0.22)) { self?.toast = nil }
        }
    }

    func confirm(_ spec: WeDialogSpec) {
        withAnimation(.easeOut(duration: 0.2)) { dialog = spec }
    }

    /// androidApp `MkHost.fail`: the server's message as a red toast; with no package the packages page opens.
    @discardableResult
    func fail(_ error: Error, _ fallback: String) -> ApiFailure {
        let failure = error.apiFailure
        showToast(failure.message ?? fallback, error: true)
        if failure.errorCode == "marketing_package_required", current != .packages { push(.packages) }
        return failure
    }
}

@MainActor
struct MarketingHost: View {
    @ObservedObject private var link = MarketingLink.shared
    @StateObject private var host: MkHost
    let onExit: () -> Void

    init(onExit: @escaping () -> Void) {
        self.onExit = onExit
        _host = StateObject(wrappedValue: MkHost(onExit: onExit))
    }

    var body: some View {
        MkHostChrome(host: host)
            .onAppear {
                host.onExit = onExit
                openPending(link.pending)
            }
            .onChange(of: link.pending != nil) { _ in openPending(link.pending) }
            // One reload per job move, for every open page of that project.
            .onReceive(ChatRealtime.shared.events) { event in
                if event.name == "marketing.job.changed" { host.changed(event.str("project_id")) }
                // A platform connected (OAuth came back) or a campaign moved.
                if event.name == "marketing.integration.changed" || event.name == "marketing.campaign.changed" {
                    host.changed(event.str("project_id"))
                }
                // A budget crossed its alert line or its cap (the notification's `data` carries the project).
                if event.name == "marketing.budget.alert" {
                    var inner = event.data
                    if case .object? = event.data["data"], let nested = event.data["data"] { inner = nested }
                    host.changed(inner["project_id"]?.str)
                }
            }
    }

    private func openPending(_ route: MkRoute?) {
        guard let route else { return }
        host.open(route)
        link.pending = nil
    }
}

private struct MkHostChrome: View {
    @ObservedObject var host: MkHost
    @Environment(\.wa) private var wa

    var body: some View {
        ZStack(alignment: .top) {
            wa.bg.ignoresSafeArea()
            page(host.current)
                .id(host.pageId)
                .transition(.asymmetric(
                    insertion: .modifier(active: WeShift(fraction: host.forward ? 0.33 : -0.33, opacity: 0), identity: WeShift(fraction: 0, opacity: 1)),
                    removal: .modifier(active: WeShift(fraction: host.forward ? -0.25 : 0.25, opacity: 0), identity: WeShift(fraction: 0, opacity: 1))
                ))
            if let toast = host.toast {
                MkToastView(toast: toast)
                    .id(toast.id)
                    .transition(.move(edge: .top).combined(with: .opacity))
                    .zIndex(3)
            }
            if let dialog = host.dialog {
                ZStack {
                    Color.black.opacity(0.45).ignoresSafeArea().onTapGesture { host.dialog = nil }
                    WeConfirmDialog(spec: dialog) { withAnimation(.easeIn(duration: 0.15)) { host.dialog = nil } }
                }
                .transition(.opacity)
                .zIndex(4)
            }
        }
        .contentShape(Rectangle())
        .environmentObject(host)
    }

    @ViewBuilder
    private func page(_ route: MkRoute) -> some View {
        switch route {
        case .home: MarketingHome()
        case .packages: MarketingPackages()
        case .project(let id): MarketingProjectPage(projectId: id)
        case .discovery(let id): MarketingDiscovery(projectId: id)
        case .research(let id): MarketingResearchPage(runId: id)
        case .plan(let id): MarketingPlanPage(planId: id)
        case .finance(let id): MarketingFinance(planId: id)
        case .team(let id): MarketingTeam(projectId: id)
        case .calendar(let id): MarketingCalendar(projectId: id)
        case .content(let id, let item): MarketingContentPage(projectId: id, itemId: item)
        case .studio(let id): MarketingStudio(projectId: id)
        case .budgets(let id): MarketingBudgets(projectId: id)
        case .results(let id): MarketingResultsPage(projectId: id)
        case .report(let id): MarketingReport(projectId: id)
        case .platforms(let id): MarketingPlatforms(projectId: id)
        case .campaigns(let id): MarketingCampaigns(projectId: id)
        case .campaign(let id, let campaign): MarketingCampaignPage(projectId: id, campaignId: campaign)
        case .optimise(let id): MarketingOptimise(projectId: id)
        }
    }
}

private struct MkToastView: View {
    @Environment(\.wa) private var wa
    let toast: MkToast

    var body: some View {
        let color = toast.error ? wa.danger : wa.green
        HStack(spacing: 10) {
            Image(systemName: toast.error ? "exclamationmark.circle" : "checkmark.circle.fill").font(.system(size: 18, weight: .semibold)).foregroundStyle(color)
            Text(verbatim: toast.text).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink).multilineTextAlignment(.leading)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 12)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .shadow(color: Color(hex: 0x111928).opacity(0.25), radius: 14, y: 4)
        .padding(.horizontal, 16)
        .padding(.vertical, 10)
    }
}
