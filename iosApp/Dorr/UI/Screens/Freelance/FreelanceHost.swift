import SwiftUI
import UniformTypeIdentifiers

// androidApp `freelance/FreelanceHost.kt` — freelance work for the client (FL-201 / FL-202): my projects, the
// project form (a draft saved as it is typed, its files) and one project with what can be done now (send for
// review, take it back, cancel, delete). Every project is reviewed before it is published. Nothing is asked on a
// timer: each page reads once when it opens and when the connection comes back.

/// "1234.50" from minor units (the country's currency has two decimals).
func flMoney(_ minor: Int?) -> String {
    guard let minor else { return "" }
    return String(format: "%d.%02d", minor / 100, abs(minor % 100))
}

/// Minor units from what the client typed ("1500", "1500.5"), or nil.
func flParseMoney(_ text: String) -> Int? {
    let clean = text.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: ",", with: ".")
    guard clean.range(of: #"^\d{1,9}(\.\d{1,2})?$"#, options: .regularExpression) != nil else { return nil }
    let parts = clean.split(separator: ".", omittingEmptySubsequences: false)
    let whole = Int(parts[0]) ?? 0
    let cents = parts.count > 1 ? Int(String(parts[1]).padding(toLength: 2, withPad: "0", startingAt: 0)) ?? 0 : 0
    return whole * 100 + cents
}

/// The server's message when it said one, else the generic one.
func flError(_ error: Error) -> String { error.serverMessage ?? L("fl_error_generic") }

let flGreen = Color(hex: 0x16A34A)
let flRed = Color(hex: 0xDC2626)
let flAmber = Color(hex: 0xD97706)

/// "‎1234.50 SAR" (left-to-right mark first so the number keeps its place in Arabic).
func flAmount(_ minor: Int?, _ currency: String?) -> String { "\u{200E}\(flMoney(minor)) \(currency ?? "")" }

@MainActor
struct FreelanceHost: View {
    let onExit: () -> Void
    // "list", "new", "edit:<uuid>", "show:<uuid>", "preview:<uuid>", "offers:<p>", "offer:<p>:<o>", "compare:<p>:<o,o,o>", "freelancer:<p>:<o>", "chat:<p>:<o>:<room>"
    @State private var page = "list"
    @State private var listVersion = 0
    // FL-L07: where booking a consultation was opened from (back returns there).
    @State private var bookFrom = "list"
    @ObservedObject private var link = FreelanceLink.shared

    private var parts: [String] { page.components(separatedBy: ":") }

    private func back() -> String {
        let p = parts
        switch p[0] {
        case "edit", "offers": return "show:" + p[1]
        case "offer", "compare": return "offers:" + p[1]
        case "chat", "freelancer": return "offer:" + p[1] + ":" + p[2]
        // FL-L04: the market and my orders.
        case "market", "orders": return "list"
        case "svc": return "market"
        case "order": return "orders"
        // FL-L07: consultations.
        case "consults", "cmarket": return "list"
        case "consult": return "consults"
        case "book": return bookFrom
        case "agreement", "invites", "preview": return "show:" + p[1]
        case "dispute", "dispute-new": return "agreement:" + p[1]
        default: return "list"
        }
    }

    var body: some View {
        let p = parts
        ZStack {
            PinkBackdrop()
            Group {
                switch p[0] {
                case "new":
                    ProjectForm(uuid: nil, onBack: { page = "list" }, onSaved: { page = "show:\($0)"; listVersion += 1 })
                case "edit":
                    ProjectForm(uuid: p[1], onBack: { page = "show:" + p[1] }, onSaved: { page = "show:\($0)"; listVersion += 1 })
                case "show":
                    ProjectDetails(uuid: p[1], onBack: { page = "list" }, onEdit: { page = "edit:\($0)" },
                                   onGone: { page = "list"; listVersion += 1 }, onPreview: { page = "preview:" + p[1] },
                                   onCopied: { listVersion += 1; page = "show:\($0)" }, onOffers: { page = "offers:" + p[1] },
                                   onOpenOffer: { page = "offer:\(p[1]):\($0)" }, onAgreement: { page = "agreement:" + p[1] },
                                   onInvitations: { page = "invites:" + p[1] })
                case "preview":
                    PreviewPage(uuid: p[1], onBack: { page = back() }, onEdit: { page = "edit:" + p[1] }, onSent: { listVersion += 1; page = "show:" + p[1] })
                case "invites":
                    InvitationsPage(projectUuid: p[1], onBack: { page = back() }, onOpenOffer: { page = "offer:\(p[1]):\($0)" },
                                    onMadePublic: { listVersion += 1; page = "show:" + p[1] })
                case "offers":
                    OffersPage(projectUuid: p[1], onBack: { page = back() }, onOpen: { page = "offer:\(p[1]):\($0)" },
                               onCompare: { page = "compare:\(p[1]):" + $0.joined(separator: ",") })
                case "compare":
                    ComparePage(projectUuid: p[1], ids: p[2].components(separatedBy: ","), onBack: { page = back() }, onOpen: { page = "offer:\(p[1]):\($0)" })
                case "offer":
                    OfferPage(projectUuid: p[1], offerUuid: p[2], onBack: { page = back() }, onProfile: { page = "freelancer:\(p[1]):\(p[2])" },
                              onChat: { page = "chat:\(p[1]):\(p[2]):\($0)" }, onChosen: { listVersion += 1; page = "agreement:" + p[1] })
                case "agreement":
                    AgreementPage(projectUuid: p[1], onBack: { page = back() }, onEnded: { listVersion += 1 },
                                  onDispute: { m, d in page = d.map { "dispute:\(p[1]):\($0)" } ?? "dispute-new:\(p[1]):\(m)" })
                case "dispute":
                    DisputePage(projectUuid: p[1], milestoneUuid: nil, disputeUuid: p[2], onBack: { page = back() }, onOpened: { _ in })
                case "dispute-new":
                    DisputePage(projectUuid: p[1], milestoneUuid: p[2], disputeUuid: nil, onBack: { page = back() }, onOpened: { page = "dispute:\(p[1]):\($0)" })
                case "favorites":
                    FavoritesPage(onBack: { page = "list" })
                case "market":
                    MarketPage(onBack: { page = back() }, onOpen: { page = "svc:\($0)" }, onOrders: { page = "orders" }, onConsultations: { page = "cmarket" })
                case "svc":
                    ServicePage(uuid: p[1], onBack: { page = back() }, onOrdered: { listVersion += 1; page = "order:\($0)" })
                case "cmarket":
                    ConsultationsMarketPage(onBack: { page = back() }, onOpen: { bookFrom = page; page = "book:\($0)" }, onMine: { page = "consults" })
                case "consults":
                    MyConsultationsPage(onBack: { page = back() }, onOpen: { page = "consult:\($0)" })
                case "consult":
                    ConsultationPage(uuid: p[1], onBack: { page = back() })
                case "book":
                    BookConsultationPage(offerUuid: p[1], onBack: { page = back() }, onBooked: { page = "consult:\($0)" })
                case "orders":
                    MyOrdersPage(onBack: { page = back() }, onOpen: { page = "order:\($0)" }, onBrowse: { page = "market" })
                case "order":
                    MyOrderPage(uuid: p[1], onBack: { page = back() }, onOpenWork: { page = "agreement:\($0)" }, onOrderAgain: { page = "svc:\($0)" })
                case "chat":
                    FreelanceChatPage(roomUuid: p[3], onBack: { page = back() })
                case "freelancer":
                    FreelancerProfilePage(projectUuid: p[1], offerUuid: p[2], onBack: { page = back() }, onBookConsultation: { bookFrom = page; page = "book:\($0)" })
                default:
                    ProjectList(version: listVersion, onExit: onExit, onNew: { page = "new" }, onOpen: { page = "show:\($0)" }, onFavorites: { page = "favorites" },
                                onMarket: { page = "market" }, onOrders: { page = "orders" }, onConsultations: { page = "consults" }, onConsultMarket: { page = "cmarket" })
                }
            }
            .id(page)
        }
        // A tapped notification names the page it belongs to (also when this screen is already open).
        .onAppear(perform: openLinked)
        .onChange(of: link.pending) { _ in openLinked() }
        #if DEBUG
        .task { if let start = UserDefaults.standard.string(forKey: "DorrFreelancePage") { page = start } }
        #endif
    }

    private func openLinked() {
        guard let linked = link.pending else { return }
        page = linked
        link.pending = nil
    }
}

// MARK: - Shared pieces (Card, Label, Field, TextAction, Chip — `internal` on Android)

/// A card; with a title, a heading (an icon beside it when there is one) comes first.
struct FlCard<Content: View>: View {
    var icon: String?
    var title: String?
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            if let title {
                if let icon { FlHeading(icon: icon, text: title) } else { FlLabel(text: title) }
            }
            content()
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(16)
        .settingsSurface(20)
    }
}

struct FlLabel: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View { Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk) }
}

struct FlField: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(verbatim: label).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
            Text(verbatim: (value?.trimmingCharacters(in: .whitespacesAndNewlines)).flatMap { $0.isEmpty ? nil : $0 } ?? "—")
                .font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(5)
        }
    }
}

/// Android's `TextAction`: now a tinted `FlAction` (red when it steps back).
struct FlTextAction: View {
    let text: String
    var danger = false
    var icon: String?
    var fill = true
    let action: () -> Void

    var body: some View {
        FlAction(text: text, icon: icon, tone: danger ? .danger : .soft, action: action)
    }
}

/// The small bold link of the agreement page (`TextLink`).
struct FlTextLink: View {
    @Environment(\.wa) private var wa
    let text: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(12.5, .bold)).foregroundStyle(wa.accent)
                .padding(.horizontal, 12).padding(.vertical, 7)
                .background(wa.accent.opacity(0.10), in: Capsule())
        }
        .buttonStyle(.plain)
    }
}

struct FlChip: View {
    @Environment(\.wa) private var wa
    let text: String
    let selected: Bool
    var icon: String?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                if let icon { Image(systemName: icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(selected ? .white : wa.accent) }
                Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(selected ? .white : wa.settingsInk).lineLimit(1).fixedSize()
            }
            .padding(.horizontal, 14).padding(.vertical, 8)
            .background(selected ? wa.accent : wa.accent.opacity(0.08), in: Capsule())
        }
        .buttonStyle(.plain)
    }
}

/// A notice (green) and an error (red) under a page's cards.
struct FlNotices: View {
    let notice: String?
    let error: String?

    var body: some View {
        if let notice { FlNotice(text: notice, icon: "checkmark.circle.fill", color: flGreen) }
        if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed) }
    }
}

/// What a page shows until it is read: the skeleton, or the failure when the read failed.
struct FlLoading: View {
    @Environment(\.wa) private var wa
    let error: String?

    var body: some View {
        if let error {
            Text(verbatim: error).font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).padding(24)
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
        } else {
            FlSkeletonDetail()
        }
    }
}

/// Material's AlertDialog on the Android side: a title, a body and the text buttons at the end.
struct FlAlert<Body: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    @ViewBuilder let content: () -> Body
    let confirm: String?
    var confirmColor: Color?
    var confirmEnabled = true
    let onConfirm: () -> Void
    var dismiss: String? = L("fl_not_now")
    let onDismiss: () -> Void

    var body: some View {
        DialogCard {
            VStack(alignment: .leading, spacing: 12) {
                Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                content()
                HStack(spacing: 18) {
                    Spacer()
                    if let dismiss {
                        Button(action: onDismiss) { Text(verbatim: dismiss).font(.dorr(14, .bold)).foregroundStyle(wa.settingsMut) }.buttonStyle(.plain)
                    }
                    if let confirm {
                        Button(action: onConfirm) {
                            Text(verbatim: confirm).font(.dorr(14, .bold)).foregroundStyle((confirmColor ?? wa.accent).opacity(confirmEnabled ? 1 : 0.4))
                        }
                        .buttonStyle(.plain)
                        .disabled(!confirmEnabled)
                    }
                }
                .padding(.top, 4)
            }
            .padding(20)
        }
    }
}

/// A panel from the bottom (Android's freelance `BottomPanel`): padded 18, its lines 10 apart.
struct FlBottomPanel<Content: View>: View {
    let onDismiss: () -> Void
    @ViewBuilder let content: () -> Content

    var body: some View {
        BottomPanel(onDismiss: onDismiss) {
            VStack(alignment: .leading, spacing: 10) { content() }
                .padding(18)
                .padding(.bottom, 20)
        }
    }
}

// MARK: - My projects

private let flFilters: [String?] = [nil, "draft", "pending_review", "changes_requested", "published", "awarded", "in_progress", "rejected", "cancelled"]

func flStatusLabel(_ status: String) -> String {
    switch status {
    case "draft": return L("fl_status_draft")
    case "pending_review": return L("fl_status_pending_review")
    case "changes_requested": return L("fl_status_changes_requested")
    case "rejected": return L("fl_status_rejected")
    case "published": return L("fl_status_published")
    case "awarded": return L("fl_status_awarded")
    case "in_progress": return L("fl_status_in_progress")
    case "closed": return L("fl_status_closed")
    default: return L("fl_status_cancelled")
    }
}

func flStatusColor(_ status: String) -> Color {
    switch status {
    case "published": return flGreen
    case "awarded": return Color(hex: 0x7C3AED)
    case "in_progress": return Color(hex: 0x0EA5E9)
    case "pending_review": return flAmber
    case "changes_requested": return Color(hex: 0x2563EB)
    case "rejected", "cancelled": return flRed
    default: return Color(hex: 0x6B7280)
    }
}

private func filterIcon(_ status: String?) -> String {
    switch status {
    case nil: return "square.grid.2x2.fill"
    case "draft": return "square.and.pencil"
    case "pending_review": return "hourglass"
    case "changes_requested": return "pencil"
    case "published": return "globe"
    case "awarded": return "person.2.fill"
    case "in_progress": return "arrow.triangle.2.circlepath"
    case "rejected": return "nosign"
    default: return "xmark.circle.fill"
    }
}

struct FlPill: View {
    let text: String
    let color: Color

    var body: some View {
        Text(verbatim: text).font(.dorr(11, .bold)).foregroundStyle(color).lineLimit(1)
            .padding(.horizontal, 10).padding(.vertical, 3)
            .background(color.opacity(0.12), in: Capsule())
    }
}

private func budgetText(_ p: FreelanceProjectDto) -> String? {
    guard let b = p.budget, let min = b.minMinor else { return nil }
    if b.type == "range", let max = b.maxMinor { return "\u{200E}\(flMoney(min)) – \(flMoney(max)) \(b.currency ?? "")" }
    return "\u{200E}\(flMoney(min)) \(b.currency ?? "")"
}

private func untitled(_ p: FreelanceProjectDto) -> String { (p.title?.isEmpty == false ? p.title : nil) ?? L("fl_untitled") }

@MainActor
private struct ProjectList: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let version: Int
    let onExit: () -> Void
    let onNew: () -> Void
    let onOpen: (String) -> Void
    let onFavorites: () -> Void
    var onMarket: () -> Void = {}
    var onOrders: () -> Void = {}
    var onConsultations: () -> Void = {}
    var onConsultMarket: () -> Void = {}
    @State private var filter: String?
    // A new filter is a new list (it starts from the first page); the next pages come while I scroll.
    @StateObject private var paged = PagedRows<FreelanceProjectDto>(key: { $0.uuid })

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: L("fl_my_projects"), onBack: onExit)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    FlChip(text: L("fl_favorites"), selected: false, icon: "heart.fill", action: onFavorites)
                    // FL-L07: my paid consultations.
                    FlChip(text: L("cs_market_title"), selected: false, icon: "video.bubble.left.fill", action: onConsultMarket)
                    FlChip(text: L("cs_mine_title"), selected: false, icon: "calendar.badge.checkmark", action: onConsultations)
                    ForEach(flFilters, id: \.self) { f in
                        FlChip(text: f.map(flStatusLabel) ?? L("fl_filter_all"), selected: filter == f, icon: filterIcon(f)) { filter = f }
                    }
                }
                .padding(.horizontal, 14).padding(.vertical, 4)
            }
            if !paged.loaded && paged.error == nil {
                FlSkeletonList()
            } else {
                ScrollView {
                    LazyVStack(spacing: 10) {
                        // FL-708: shown only while a warning / restriction is active or its appeal waits.
                        SanctionsCard(refreshKey: version)
                        // FL-L04: order a ready-made service instead of waiting for offers.
                        MarketBanner(onBrowse: onMarket, onOrders: onOrders)
                        if paged.rows.isEmpty && paged.error == nil {
                            WaEmpty(icon: "briefcase.fill", tone: .blue, title: L("fl_empty_title"), text: L("fl_empty_text"))
                        }
                        ForEach(Array(paged.rows.enumerated()), id: \.element.uuid) { i, p in
                            ProjectCard(p: p) { onOpen(p.uuid) }.onAppear { paged.appeared(i) }
                        }
                        PagedSpinner(paged: paged)
                        PagedError(paged: paged)
                    }
                    .padding(.horizontal, 16).padding(.vertical, 10)
                }
            }
            SettingsPrimaryButton(text: L("fl_new_project"), action: onNew).padding(16)
        }
        .task(id: "\(filter ?? "")") {
            let status = filter
            paged.reset { page in try await FreelanceApi.projects(status: status, page: page) }
        }
        .task(id: "\(filter ?? "")|\(network.reconnectTick)|\(version)") { await paged.reload() }
    }
}

private struct ProjectCard: View {
    @Environment(\.wa) private var wa
    let p: FreelanceProjectDto
    let onClick: () -> Void

    var body: some View {
        let color = flStatusColor(p.state)
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(alignment: .top, spacing: 12) {
                    Image(systemName: "briefcase.fill").font(.system(size: 18)).foregroundStyle(color)
                        .frame(width: 44, height: 44)
                        .background(color.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    VStack(alignment: .leading, spacing: 3) {
                        Text(verbatim: untitled(p)).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                        if let s = p.specialty { Text(verbatim: s.name ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    FlPill(text: flStatusLabel(p.state), color: color)
                }
                if let b = budgetText(p) {
                    HStack(spacing: 6) {
                        Image(systemName: "banknote").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.accent)
                        Text(verbatim: b).font(.dorr(13, .heavy)).foregroundStyle(wa.accent)
                    }
                }
                // FL-211: where the work stands, and what is due from me.
                if let g = p.progress {
                    FlProgressBar(done: g.approved ?? 0, total: g.total ?? 0)
                    Text(verbatim: [L("fl_progress_parts", g.approved ?? 0, g.total ?? 0),
                                    (g.inReview ?? 0) > 0 ? L("fl_progress_review", g.inReview ?? 0) : nil].compactMap { $0 }.joined(separator: "  ·  "))
                        .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                if let next = p.nextAction { NextActionLine(code: next) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(14)
            .settingsSurface(20)
        }
        .buttonStyle(.plain)
    }
}

private struct FlProgressBar: View {
    @Environment(\.wa) private var wa
    let done: Int
    let total: Int

    var body: some View {
        GeometryReader { proxy in
            ZStack(alignment: .leading) {
                Capsule().fill(wa.accent.opacity(0.15))
                if total > 0 && done > 0 {
                    Capsule().fill(wa.accent).frame(width: proxy.size.width * min(CGFloat(done) / CGFloat(total), 1))
                }
            }
        }
        .frame(height: 6)
    }
}

private struct NextActionLine: View {
    let code: String

    var body: some View {
        FlNotice(text: L("fl_next_label", nextActionLabel(code)), icon: "bell.badge.fill", color: flAmber)
    }

    private func nextActionLabel(_ code: String) -> String {
        switch code {
        case "finish_draft": return L("fl_next_finish_draft")
        case "submit": return L("fl_next_submit")
        case "fix_project": return L("fl_next_fix_project")
        case "review_offers": return L("fl_next_review_offers")
        case "send_agreement": return L("fl_next_send_agreement")
        case "review_delivery": return L("fl_next_review_delivery")
        case "fund_milestone": return L("fl_next_fund_milestone")
        default: return L("fl_next_dispute")
        }
    }
}

// MARK: - One project

private func missingLabel(_ field: String) -> String {
    switch field {
    case "title": return L("fl_title")
    case "description": return L("fl_description")
    case "specialty": return L("fl_specialty")
    case "skills": return L("fl_skills")
    case "budget": return L("fl_budget")
    default: return L("fl_duration")
    }
}

private func eventLabel(_ type: String) -> String {
    switch type {
    case "project.created": return L("fl_ev_created")
    case "project.submitted": return L("fl_ev_submitted")
    case "project.resubmitted": return L("fl_ev_resubmitted")
    case "project.withdrawn": return L("fl_ev_withdrawn")
    case "project.cost_cap_set": return L("fl_ev_cost_cap")
    case "project.completed": return L("fl_ev_completed")
    case "project.invited": return L("fl_ev_invited")
    case "project.made_public": return L("fl_ev_made_public")
    case "project.cancelled": return L("fl_ev_cancelled")
    case "project.edited_by_admin": return L("fl_ev_edited_by_admin")
    case "project.offers_closed": return L("fl_ev_offers_closed")
    case "project.offers_reopened": return L("fl_ev_offers_reopened")
    case "project.awarded": return L("fl_ev_awarded")
    case "project.started": return L("fl_ev_started")
    case "project.reopened": return L("fl_ev_reopened")
    case "project.published": return L("fl_status_published")
    case "project.rejected": return L("fl_status_rejected")
    case "project.changes_requested": return L("fl_status_changes_requested")
    default: return type
    }
}

@MainActor
private struct ProjectDetails: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let uuid: String
    let onBack: () -> Void
    let onEdit: (String) -> Void
    let onGone: () -> Void
    let onPreview: () -> Void
    let onCopied: (String) -> Void
    let onOffers: () -> Void
    let onOpenOffer: (String) -> Void
    let onAgreement: () -> Void
    let onInvitations: () -> Void
    @State private var project: FreelanceProjectDto?
    @State private var busy = false
    @State private var error: String?
    @State private var notice: String?
    @State private var confirm: String?
    // FL-607: why the project is cancelled.
    @State private var cancelCode: String?
    @State private var cancelNote = ""
    @State private var capDialog = false
    @State private var capError: String?

    private func act(_ block: @escaping () async throws -> FreelanceProjectDto?, after: (() -> Void)? = nil, successNotice: String? = nil) {
        busy = true
        error = nil
        notice = nil
        Task {
            do {
                if let result = try await block() { project = result }
                notice = successNotice
                after?()
            } catch {
                self.error = flError(error)
            }
            busy = false
        }
    }

    /// The limit changes (or goes); a refusal is told inside the dialog, not under the page.
    private func changeCap(_ block: @escaping () async throws -> FreelanceProjectDto?) {
        busy = true
        capError = nil
        Task {
            do {
                if let p = try await block() { project = p }
                capDialog = false
            } catch {
                capError = error.serverMessage ?? L("fl_cost_cap_failed")
            }
            busy = false
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: L("fl_project"), onBack: onBack)
            if let p = project {
                ScrollView { content(p).padding(.horizontal, 16) }
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: network.reconnectTick) {
            do { project = try await FreelanceApi.project(uuid).data } catch { self.error = flError(error) }
        }
        .dorrDialog(isPresented: Binding(get: { confirm == "delete" }, set: { if !$0 { confirm = nil } })) {
            ConfirmDialog(icon: "trash.fill", title: L("fl_delete_draft"), message: L("fl_delete_confirm"), loading: busy,
                          onConfirm: { act({ _ = try await FreelanceApi.delete(uuid); return nil }, after: { confirm = nil; onGone() }) },
                          onDismiss: { confirm = nil })
        }
        .dorrDialog(isPresented: Binding(get: { confirm == "cancel" }, set: { if !$0, !busy { confirm = nil } }), bottom: true) {
            FlSheet(icon: "xmark.circle.fill", title: L("fl_cancel_project"), text: L("fl_cancel_confirm")) {
                CancelReasonPicker(code: cancelCode, note: $cancelNote) { cancelCode = $0 }
                FlAction(text: L("fl_cancel_project"), icon: "xmark.circle.fill", tone: .danger, enabled: flCancelReasonReady(cancelCode, cancelNote), loading: busy) {
                    let note = cancelNote.trimmingCharacters(in: .whitespacesAndNewlines)
                    act({ try await FreelanceApi.cancel(uuid, reason: note.isEmpty ? nil : note, reasonCode: cancelCode).data }, after: { confirm = nil })
                }
                FlAction(text: L("fl_not_now")) { confirm = nil }
            }
        }
        .dorrDialog(isPresented: Binding(get: { capDialog }, set: { if !$0, !busy { capDialog = false } }), bottom: true) {
            if let p = project {
                CostCapDialog(current: p.maxCostMinor, currency: p.budget?.currency ?? "", busy: busy, error: capError,
                              onSave: { v in changeCap { try await FreelanceApi.setCostCap(uuid, maxCostMinor: v).data } },
                              onRemove: { changeCap { try await FreelanceApi.removeCostCap(uuid).data } })
            }
        }
    }

    @ViewBuilder
    private func content(_ p: FreelanceProjectDto) -> some View {
        let can = p.can ?? FreelanceProjectCanDto()
        let offers = p.offers ?? FreelanceOffersDto()
        let missing = p.missing ?? []
        let color = flStatusColor(p.state)
        VStack(alignment: .leading, spacing: 12) {
            FlCard {
                HStack(alignment: .top, spacing: 12) {
                    Image(systemName: "briefcase.fill").font(.system(size: 20)).foregroundStyle(color)
                        .frame(width: 48, height: 48)
                        .background(color.opacity(0.12), in: RoundedRectangle(cornerRadius: 15, style: .continuous))
                    VStack(alignment: .leading, spacing: 6) {
                        Text(verbatim: untitled(p)).font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk)
                        FlPill(text: flStatusLabel(p.state), color: color)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
                if let reason = p.statusReason, !reason.isEmpty { FlNotice(text: L("fl_reviewer_note", reason), icon: "info.circle.fill", color: color) }
                if p.state == "pending_review" { FlNotice(text: L("fl_under_review_hint"), icon: "hourglass", color: flAmber) }
                if p.state == "rejected" { FlNotice(text: L("fl_rejected_hint"), icon: "nosign", color: flRed) }
                if let next = p.nextAction { NextActionLine(code: next) }
                HStack(spacing: 8) {
                    FlStat(icon: "banknote", label: L("fl_budget"), value: budgetText(p) ?? "—").layoutPriority(1.4)
                    FlStat(icon: "clock", label: L("fl_duration"), value: p.durationDays.map { L("fl_days", $0) } ?? "—")
                    FlStat(icon: "doc.text.fill", label: L("fl_offers"), value: "\(offers.count ?? 0)")
                }
            }
            // FL-211: the stages of the work, each with where it stands.
            if let stages = p.milestones, !stages.isEmpty {
                FlCard(icon: "flag.fill", title: L("fl_stages")) {
                    if let g = p.progress {
                        FlProgressBar(done: g.approved ?? 0, total: g.total ?? 0)
                        Text(verbatim: L("fl_progress_parts", g.approved ?? 0, g.total ?? 0) + "  ·  " +
                             L("fl_progress_money", "\u{200E}" + flMoney(g.approvedMinor ?? 0), flMoney(g.priceMinor ?? 0), p.budget?.currency ?? ""))
                            .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                    ForEach(stages) { m in
                        let mc = milestoneStatusColor(m.status ?? "unfunded")
                        HStack(spacing: 10) {
                            Text(verbatim: "\(m.position ?? 1)").font(.dorr(12, .heavy)).foregroundStyle(.white)
                                .frame(width: 24, height: 24).background(mc, in: Circle())
                            VStack(alignment: .leading, spacing: 0) {
                                Text(verbatim: m.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                                Text(verbatim: "\u{200E}\(flMoney(m.amountMinor ?? 0)) \(p.budget?.currency ?? "")" + (m.durationDays.map { "  ·  " + L("fl_days", $0) } ?? ""))
                                    .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                            }
                            .frame(maxWidth: .infinity, alignment: .leading)
                            Text(verbatim: milestoneStatusLabel(m.status ?? "unfunded")).font(.dorr(12, .bold)).foregroundStyle(mc)
                        }
                        .padding(.horizontal, 12).padding(.vertical, 10)
                        .background(mc.opacity(0.07), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                }
            }
            // FL-401: the agreement with the chosen freelancer.
            if let ag = p.agreement {
                FlCard(icon: "person.2.fill", title: L("fl_agreement")) {
                    Text(verbatim: agreementStatusLabel(ag.status ?? "draft")).font(.dorr(13, .bold)).foregroundStyle(agreementStatusColor(ag.status ?? "draft"))
                    FlAction(text: L(ag.status == "draft" || ag.status == nil ? "fl_ag_open_draft" : "fl_ag_open"), icon: "person.2.fill", tone: .primary, action: onAgreement)
                }
            }
            // FL-205: a private project is filled by invitation.
            if let inv = p.invitations, p.state == "published" {
                FlCard(icon: "envelope.badge.fill", title: L("fl_inv_title")) {
                    Text(verbatim: L("fl_inv_summary", inv.sent ?? 0, inv.accepted ?? 0, inv.declined ?? 0)).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                    FlAction(text: L(inv.canInvite == true ? "fl_inv_manage" : "fl_inv_see"), icon: "person.badge.plus", action: onInvitations)
                }
            }
            if p.state == "published" || p.state == "awarded" {
                FlCard(icon: "doc.text.fill", title: L("fl_offers") + ((offers.count ?? 0) > 0 ? " (\(offers.count ?? 0))" : "")) {
                    FlNotice(text: offersLine(p, offers), icon: offers.accepting == true ? "checkmark.circle.fill" : "lock.fill",
                             color: p.state == "awarded" ? Color(hex: 0x7C3AED) : (offers.accepting == true ? flGreen : flAmber))
                    // FL-305 / FL-309: compare them and choose one.
                    if let chosen = offers.chosen { FlAction(text: L("fl_see_chosen"), icon: "star.fill", tone: .primary) { onOpenOffer(chosen) } }
                    if (offers.count ?? 0) > 0 {
                        FlAction(text: L(p.state == "published" ? "fl_compare_choose" : "fl_see_offers"), icon: "arrow.left.arrow.right", action: onOffers)
                    }
                    if offers.canClose == true { FlAction(text: L("fl_offers_close"), icon: "lock.fill", tone: .warn) { act({ try await FreelanceApi.closeOffers(p.uuid).data }) } }
                    if offers.canReopen == true {
                        FlAction(text: L("fl_offers_reopen", offers.reopensLeft ?? 0), icon: "lock.open.fill") { act({ try await FreelanceApi.reopenOffers(p.uuid).data }) }
                    }
                }
            }
            FlCard(icon: "doc.plaintext", title: L("fl_description")) { ProjectSummary(p: p) }
            if let files = p.attachments, !files.isEmpty {
                FlCard(icon: "paperclip", title: L("fl_files")) {
                    ForEach(files, id: \.id) { a in
                        HStack(spacing: 8) {
                            Image(systemName: "doc.text").font(.system(size: 15)).foregroundStyle(wa.accent)
                            Text(verbatim: a.name ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineLimit(1)
                            Spacer(minLength: 0)
                        }
                        .padding(.horizontal, 12).padding(.vertical, 9)
                        .background(wa.accent.opacity(0.07), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    }
                }
            }
            if !missing.isEmpty { FlNotice(text: L("fl_missing", missing.map(missingLabel).joined(separator: "، ")), icon: "exclamationmark.triangle.fill", color: flAmber) }
            FlNotices(notice: notice, error: error)

            // FL-210: a last look before it goes out (it also says what doesn't add up).
            if can.submit == true { FlAction(text: L("fl_review_and_send"), icon: "paperplane.fill", tone: .primary, enabled: missing.isEmpty, loading: busy, action: onPreview) }
            if can.edit == true { FlAction(text: L("fl_edit"), icon: "pencil") { onEdit(p.uuid) } }
            if can.setCostCap == true {
                FlAction(text: L(p.maxCostMinor == nil ? "fl_cost_cap_set" : "fl_cost_cap_change"), icon: "dollarsign.circle.fill") { capError = nil; capDialog = true }
            }
            // FL-209: ask for it again from this one.
            if can.duplicate == true {
                FlAction(text: L("fl_copy_project"), icon: "doc.on.doc") {
                    busy = true
                    error = nil
                    Task {
                        do { if let copy = try await FreelanceApi.duplicate(p.uuid).data { onCopied(copy.uuid) } } catch { self.error = flError(error) }
                        busy = false
                    }
                }
            }
            if can.withdraw == true { FlAction(text: L("fl_withdraw"), icon: "arrow.uturn.backward", tone: .warn) { act({ try await FreelanceApi.withdraw(p.uuid).data }) } }
            if can.cancel == true { FlAction(text: L("fl_cancel_project"), icon: "xmark.circle.fill", tone: .danger) { cancelCode = nil; cancelNote = ""; confirm = "cancel" } }
            if can.delete == true { FlAction(text: L("fl_delete_draft"), icon: "trash.fill", tone: .danger) { confirm = "delete" } }

            if let events = p.timeline, !events.isEmpty {
                FlCard(icon: "clock.arrow.circlepath", title: L("fl_history")) {
                    ForEach(Array(events.enumerated()), id: \.offset) { _, e in
                        HStack(alignment: .top, spacing: 8) {
                            Circle().fill(wa.accent).frame(width: 7, height: 7).padding(.top, 6)
                            VStack(alignment: .leading, spacing: 0) {
                                Text(verbatim: eventLabel(e.type ?? "")).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                                Text(verbatim: "\u{200E}" + String((e.at ?? "").prefix(16)).replacingOccurrences(of: "T", with: " ")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                            }
                        }
                    }
                }
            }
            Spacer(minLength: 20)
        }
    }

    private func offersLine(_ p: FreelanceProjectDto, _ o: FreelanceOffersDto) -> String {
        if p.state == "awarded" { return L("fl_offers_closed_chosen") }
        if o.accepting == true { return L("fl_offers_open", String((o.deadlineAt ?? "").prefix(10))) }
        switch o.closeReason {
        case "client": return L("fl_offers_closed_client")
        case "admin": return L("fl_offers_closed_admin")
        case "max_offers": return L("fl_offers_closed_full")
        default: return L("fl_offers_closed_deadline")
        }
    }
}

/// What the project says, as the freelancers read it (the details page and the last look share it).
private struct ProjectSummary: View {
    let p: FreelanceProjectDto

    var body: some View {
        FlField(label: L("fl_description"), value: p.description)
        FlField(label: L("fl_specialty"), value: p.specialty?.name)
        FlField(label: L("fl_skills"), value: (p.skills ?? []).compactMap(\.name).joined(separator: "، "))
        FlField(label: L("fl_budget"), value: budgetText(p))
        if p.maxCostMinor != nil || p.can?.setCostCap == true {
            FlField(label: L("fl_cost_cap"), value: p.maxCostMinor.map { L("fl_cost_cap_value", "\u{200E}" + flMoney($0), p.budget?.currency ?? "") } ?? L("fl_cost_cap_none"))
        }
        FlField(label: L("fl_duration"), value: p.durationDays.map { L("fl_days", $0) })
        FlField(label: L("fl_visibility"), value: L(p.visibility == "private" ? "fl_private" : "fl_public"))
        if let q = p.questions, !q.isEmpty {
            FlField(label: L("fl_questions"), value: q.enumerated().map { "\($0.offset + 1). \($0.element)" }.joined(separator: "\n"))
        }
    }
}

/// The most the client will pay in all (FL-210): typed once, lifted with one tap.
private struct CostCapDialog: View {
    let current: Int?
    let currency: String
    let busy: Bool
    let error: String?
    let onSave: (Int) -> Void
    let onRemove: () -> Void
    @State private var text = ""
    @State private var localError: String?
    @State private var started = false

    var body: some View {
        FlSheet(icon: "dollarsign.circle.fill", title: L("fl_cost_cap"), text: L("fl_cost_cap_hint")) {
            DorrTextArea(value: $text, label: L("fl_cost_cap_field", currency), icon: "banknote", singleLine: true).keyboardType(.decimalPad)
            if let e = localError ?? error { FlNotice(text: e, icon: "exclamationmark.circle", color: flRed) }
            FlAction(text: L("fl_cost_cap_save"), icon: "checkmark", tone: .primary, enabled: !busy) {
                if let v = flParseMoney(text), v > 0 {
                    localError = nil
                    UIApplication.shared.sendAction(#selector(UIResponder.resignFirstResponder), to: nil, from: nil, for: nil)
                    onSave(v)
                } else {
                    localError = L("fl_budget_invalid")
                }
            }
            if current != nil { FlAction(text: L("fl_cost_cap_remove"), icon: "trash", tone: .danger, enabled: !busy, action: onRemove) }
        }
        .onAppear {
            guard !started else { return }
            started = true
            text = flMoney(current)
        }
    }
}

// MARK: - The last look (FL-210)

@MainActor
private struct PreviewPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let uuid: String
    let onBack: () -> Void
    let onEdit: () -> Void
    let onSent: () -> Void
    @State private var preview: FreelancePreviewDto?
    @State private var busy = false
    @State private var error: String?
    @State private var capDialog = false
    @State private var capError: String?

    private func load() async {
        do {
            preview = try await FreelanceApi.preview(uuid).data
            error = nil
        } catch {
            self.error = flError(error)
        }
    }

    /// The limit changes (or goes), then the look is taken again.
    private func changeCap(_ block: @escaping () async throws -> Void) {
        busy = true
        capError = nil
        Task {
            do {
                try await block()
                capDialog = false
                await load()
            } catch {
                capError = error.serverMessage ?? L("fl_cost_cap_failed")
            }
            busy = false
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: L("fl_preview_title"), onBack: onBack)
            if let pv = preview {
                ScrollView { content(pv).padding(.horizontal, 16) }
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: network.reconnectTick) { await load() }
        .dorrDialog(isPresented: Binding(get: { capDialog }, set: { if !$0, !busy { capDialog = false } }), bottom: true) {
            if let p = preview?.project {
                CostCapDialog(current: p.maxCostMinor, currency: p.budget?.currency ?? "", busy: busy, error: capError,
                              onSave: { v in changeCap { _ = try await FreelanceApi.setCostCap(uuid, maxCostMinor: v) } },
                              onRemove: { changeCap { _ = try await FreelanceApi.removeCostCap(uuid) } })
            }
        }
    }

    @ViewBuilder
    private func content(_ pv: FreelancePreviewDto) -> some View {
        let p = pv.project
        let issues = pv.issues ?? []
        let errors = issues.filter { $0.severity == "error" }
        let warnings = issues.filter { $0.severity != "error" }
        VStack(alignment: .leading, spacing: 12) {
            FlIntro(icon: "checklist", title: untitled(p), text: L("fl_preview_intro"))
            FlCard(icon: "doc.plaintext", title: L("fl_project")) {
                ProjectSummary(p: p)
                if let files = p.attachments, !files.isEmpty { FlField(label: L("fl_files"), value: files.compactMap(\.name).joined(separator: "، ")) }
            }
            if issues.isEmpty { FlNotice(text: L("fl_preview_ok"), icon: "checkmark.circle.fill", color: flGreen) }
            if !errors.isEmpty { IssuesCard(title: L("fl_preview_errors"), issues: errors, color: flRed, icon: "exclamationmark.circle") }
            if !warnings.isEmpty { IssuesCard(title: L("fl_preview_warnings"), issues: warnings, color: flAmber, icon: "exclamationmark.triangle.fill") }
            if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed) }
            // The limit is the one thing that can be fixed from here.
            if p.can?.setCostCap == true && issues.contains(where: { $0.field == "max_cost" }) {
                FlAction(text: L("fl_cost_cap_change"), icon: "dollarsign.circle.fill") { capError = nil; capDialog = true }
            }
            FlAction(text: L("fl_preview_send"), icon: "paperplane.fill", tone: .primary, enabled: pv.canSubmit == true, loading: busy) {
                busy = true
                error = nil
                Task {
                    do {
                        _ = try await FreelanceApi.submit(uuid)
                        onSent()
                    } catch {
                        self.error = flError(error)
                    }
                    busy = false
                }
            }
            FlAction(text: L("fl_preview_fix"), icon: "pencil", action: onEdit)
            Spacer(minLength: 20)
        }
    }
}

private struct IssuesCard: View {
    let title: String
    let issues: [FreelanceIssueDto]
    let color: Color
    let icon: String

    var body: some View {
        FlCard(icon: icon, title: title) {
            ForEach(Array(issues.enumerated()), id: \.offset) { _, i in FlNotice(text: i.message ?? "", icon: icon, color: color) }
        }
    }
}

// MARK: - The form

@MainActor
private struct ProjectForm: View {
    @Environment(\.wa) private var wa
    let uuid: String?
    let onBack: () -> Void
    let onSaved: (String) -> Void
    @State private var current: String?
    @State private var loaded = false
    @State private var title = ""
    @State private var description = ""
    @State private var specialty: FreelanceNamedDto?
    @State private var skills: [FreelanceNamedDto] = []
    @State private var budgetType = "fixed"
    @State private var budgetMin = ""
    @State private var budgetMax = ""
    // FL-210: the most I pay in all (optional); a limit that was set and is emptied is lifted on save.
    @State private var maxCost = ""
    @State private var duration = ""
    @State private var visibility = "public"
    @State private var questions: [String] = []
    @State private var attachments: [FreelanceAttachmentDto] = []
    @State private var rules: FreelanceBudgetRulesDto?
    @State private var pickSpecialty = false
    @State private var picking = false
    @State private var busy = false
    @State private var uploading = false
    @State private var error: String?

    private func request() -> FreelanceProjectRequest? {
        func money(_ text: String) -> Int?? {
            let t = text.trimmingCharacters(in: .whitespaces)
            if t.isEmpty { return .some(nil) }
            guard let v = flParseMoney(t) else { return nil }
            return .some(v)
        }
        guard let min = money(budgetMin) else { return nil }
        var max: Int?
        if budgetType == "range" {
            guard let v = money(budgetMax) else { return nil }
            max = v
        }
        guard let cap = money(maxCost) else { return nil }
        if let cap, cap <= 0 { return nil }
        func clean(_ s: String) -> String? { let t = s.trimmingCharacters(in: .whitespacesAndNewlines); return t.isEmpty ? nil : t }
        return FreelanceProjectRequest(title: clean(title), description: clean(description), specialtyId: specialty?.id, skillIds: skills.map(\.id),
                                       budgetType: budgetType, budgetMinMinor: min, budgetMaxMinor: max, durationDays: Int(duration),
                                       visibility: visibility, questions: questions.compactMap(clean), maxCostMinor: cap)
    }

    /// Saves the draft; `then` gets the project's uuid (a new draft gets one on its first save).
    private func save(then: @escaping (String) -> Void) {
        guard let body = request() else { error = L("fl_budget_invalid"); return }
        busy = true
        error = nil
        Task {
            do {
                let saved = try await (current == nil ? FreelanceApi.create(body) : FreelanceApi.update(current!, body)).data
                if let saved {
                    current = saved.uuid
                    attachments = saved.attachments ?? []
                    // A blank field can't say "none" in the body (empty fields are left out), so lifting the limit is its own call.
                    if maxCost.trimmingCharacters(in: .whitespaces).isEmpty && saved.maxCostMinor != nil { _ = try await FreelanceApi.removeCostCap(saved.uuid) }
                    then(saved.uuid)
                }
            } catch {
                self.error = flError(error)
            }
            busy = false
        }
    }

    private func upload(_ url: URL) {
        // A file goes on a saved draft: save first if this is a new one.
        save { id in
            uploading = true
            Task {
                do {
                    let scoped = url.startAccessingSecurityScopedResource()
                    defer { if scoped { url.stopAccessingSecurityScopedResource() } }
                    let data = try Data(contentsOf: url)
                    let mime = UTType(filenameExtension: url.pathExtension)?.preferredMIMEType ?? "application/octet-stream"
                    let part = ApiClient.FilePart(field: "file", filename: url.lastPathComponent, mimeType: mime, data: data)
                    if let p = try await FreelanceApi.addAttachment(id, file: part).data { attachments = p.attachments ?? [] }
                } catch {
                    self.error = flError(error)
                }
                uploading = false
            }
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: L(uuid == nil ? "fl_new_project" : "fl_edit"), onBack: onBack)
            if !loaded {
                FlLoading(error: error)
            } else {
                ScrollView { form.padding(.horizontal, 16) }.scrollDismissesKeyboard(.interactively)
                SettingsPrimaryButton(text: L("fl_save_draft"), loading: busy) { save(then: onSaved) }.padding(16)
            }
        }
        .task {
            current = uuid
            guard let uuid else { loaded = true; return }
            do {
                if let p = try await FreelanceApi.project(uuid).data {
                    title = p.title ?? ""
                    description = p.description ?? ""
                    specialty = p.specialty
                    skills = p.skills ?? []
                    budgetType = p.budget?.type ?? "fixed"
                    budgetMin = flMoney(p.budget?.minMinor)
                    budgetMax = flMoney(p.budget?.maxMinor)
                    maxCost = flMoney(p.maxCostMinor)
                    duration = p.durationDays.map(String.init) ?? ""
                    visibility = p.visibility ?? "public"
                    questions = p.questions ?? []
                    attachments = p.attachments ?? []
                }
                loaded = true
            } catch {
                self.error = flError(error)
            }
        }
        // The budget limits follow the specialty (its own rule wins over the country's).
        .task(id: specialty?.id) { rules = try? await FreelanceApi.budgetRules(specialtyId: specialty?.id).data }
        .fileImporter(isPresented: $picking, allowedContentTypes: [.item]) { result in
            if case .success(let url) = result { upload(url) }
        }
        .dorrDialog(isPresented: $pickSpecialty, bottom: true) {
            SpecialtyDialog { picked in
                specialty = picked
                pickSpecialty = false
            }
        }
    }

    private var form: some View {
        VStack(alignment: .leading, spacing: 18) {
            FlIntro(icon: "briefcase.fill", title: L("fl_form_intro_title"), text: L("fl_form_intro"))
            VStack(alignment: .leading, spacing: 14) {
                DorrTextArea(value: limited($title, 120), label: L("fl_title"), placeholder: L("fl_title_hint"), icon: "textformat", singleLine: true)
                DorrTextArea(value: limited($description, 5000), label: L("fl_description"), placeholder: L("fl_description_hint"), icon: "text.alignleft", minHeight: 130)
            }
            VStack(alignment: .leading, spacing: 10) {
                FlHeading(icon: "square.grid.2x2", text: L("fl_specialty"))
                Button { pickSpecialty = true } label: {
                    HStack(spacing: 8) {
                        Image(systemName: "square.grid.2x2").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red.opacity(0.8))
                        Text(verbatim: specialty?.name ?? L("fl_pick_specialty")).font(.dorr(15))
                            .foregroundStyle(specialty == nil ? (wa.night ? wa.mut : Color(hex: 0xB4BAC4)) : wa.settingsInk)
                        Spacer(minLength: 0)
                    }
                    .frame(maxWidth: .infinity, minHeight: 46, alignment: .leading)
                    .padding(.horizontal, 14)
                    .background(wa.night ? wa.bg : Color(hex: 0xFBF7F8), in: Capsule())
                    .overlay(Capsule().stroke(wa.night ? wa.line : Color(hex: 0xF3D5DB), lineWidth: 1))
                }
                .buttonStyle(.plain)
                FlHeading(icon: "bolt.fill", text: L("fl_skills"))
                SkillPicker(selected: $skills, specialtyId: specialty?.id)
            }
            VStack(alignment: .leading, spacing: 10) {
                FlHeading(icon: "banknote", text: L("fl_budget"))
                HStack(spacing: 8) {
                    FlChip(text: L("fl_budget_fixed"), selected: budgetType == "fixed", icon: "lock.fill") { budgetType = "fixed" }
                    FlChip(text: L("fl_budget_range"), selected: budgetType == "range", icon: "arrow.left.arrow.right") { budgetType = "range" }
                }
                let currency = rules?.currency ?? ""
                HStack(alignment: .top, spacing: 10) {
                    DorrTextArea(value: $budgetMin, label: L(budgetType == "range" ? "fl_budget_from" : "fl_budget_amount", currency), icon: "banknote", singleLine: true)
                        .keyboardType(.decimalPad)
                    if budgetType == "range" {
                        DorrTextArea(value: $budgetMax, label: L("fl_budget_to", currency), icon: "banknote", singleLine: true).keyboardType(.decimalPad)
                    }
                }
                if let r = rules {
                    let lines = [r.minMinor.map { L("fl_budget_min_rule", flMoney($0), r.currency ?? "") },
                                 r.maxMinor.map { L("fl_budget_max_rule", flMoney($0), r.currency ?? "") }].compactMap { $0 }
                    if !lines.isEmpty { Text(verbatim: "\u{200E}" + lines.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                }
                // FL-210: the most I will pay in all.
                DorrTextArea(value: $maxCost, label: L("fl_cost_cap_field", currency), icon: "dollarsign.circle.fill", singleLine: true).keyboardType(.decimalPad)
                Text(L("fl_cost_cap_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            }
            VStack(alignment: .leading, spacing: 10) {
                DorrTextArea(value: Binding(get: { duration }, set: { duration = String($0.filter(\.isNumber).prefix(3)) }),
                             label: L("fl_duration_days"), icon: "clock", singleLine: true)
                    .keyboardType(.numberPad)
                FlHeading(icon: "eye.fill", text: L("fl_visibility"))
                HStack(spacing: 8) {
                    FlChip(text: L("fl_public"), selected: visibility == "public", icon: "globe") { visibility = "public" }
                    FlChip(text: L("fl_private"), selected: visibility == "private", icon: "lock.fill") { visibility = "private" }
                }
                FlNotice(text: L(visibility == "private" ? "fl_private_hint" : "fl_public_hint"), icon: "info.circle.fill", color: wa.accent)
            }
            // FL-307: questions every freelancer answers with their offer.
            VStack(alignment: .leading, spacing: 10) {
                FlHeading(icon: "questionmark.bubble.fill", text: L("fl_questions"), hint: L("fl_questions_hint"))
                ForEach(questions.indices, id: \.self) { i in
                    HStack(spacing: 6) {
                        DorrTextArea(value: Binding(get: { questions.indices.contains(i) ? questions[i] : "" },
                                                    set: { if questions.indices.contains(i) { questions[i] = String($0.prefix(300)) } }),
                                     placeholder: L("fl_question_n", i + 1), icon: "questionmark.bubble.fill", singleLine: true)
                        RemoveButton { questions.remove(at: i) }
                    }
                }
                if questions.count < 5 { FlAction(text: L("fl_add_question"), icon: "plus") { questions.append("") } }
            }
            VStack(alignment: .leading, spacing: 10) {
                FlHeading(icon: "paperclip", text: L("fl_files"), hint: L("fl_files_hint"))
                ForEach(attachments, id: \.id) { a in
                    HStack(spacing: 8) {
                        Image(systemName: "doc.text").font(.system(size: 15)).foregroundStyle(wa.accent)
                        Text(verbatim: a.name ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        Spacer(minLength: 0)
                        RemoveButton {
                            guard let id = current else { return }
                            Task {
                                do { if let p = try await FreelanceApi.removeAttachment(id, media: a.id).data { attachments = p.attachments ?? [] } }
                                catch { self.error = flError(error) }
                            }
                        }
                    }
                    .padding(.horizontal, 12).padding(.vertical, 4)
                    .background(wa.accent.opacity(0.07), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                FlAction(text: L("fl_add_file"), icon: "paperclip", loading: uploading) { picking = true }
            }
            if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed) }
            Spacer(minLength: 8)
        }
    }

    private func limited(_ binding: Binding<String>, _ max: Int) -> Binding<String> {
        Binding(get: { binding.wrappedValue }, set: { binding.wrappedValue = String($0.prefix(max)) })
    }
}

/// The small round ✕ that removes a line.
struct RemoveButton: View {
    @Environment(\.wa) private var wa
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut).frame(width: 30, height: 30).contentShape(Circle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(L("fl_remove"))
    }
}

/// Picked skills as removable pills, and under them the skills: with nothing typed, the ones of the project's specialty (as soon
/// as one is chosen); from two letters on, a search over the whole active catalog (400 ms after the last key).
@MainActor
private struct SkillPicker: View {
    @Environment(\.wa) private var wa
    @Binding var selected: [FreelanceNamedDto]
    let specialtyId: Int?
    @State private var query = ""
    @State private var results: [FreelanceNamedDto] = []

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            FlowLayout(spacing: 6) {
                ForEach(selected, id: \.id) { s in
                    Button { selected.removeAll { $0.id == s.id } } label: {
                        HStack(spacing: 4) {
                            Text(verbatim: s.name ?? "").font(.dorr(12, .bold))
                            Image(systemName: "xmark").font(.system(size: 10, weight: .bold))
                        }
                        .foregroundStyle(.white)
                        .padding(.horizontal, 12).padding(.vertical, 6)
                        .background(wa.accent, in: Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
            if selected.count < 10 {
                DorrTextArea(value: $query, placeholder: L("fl_search_skills"), icon: "magnifyingglass", singleLine: true)
                FlowLayout(spacing: 6) {
                    ForEach(results.filter { r in !selected.contains { $0.id == r.id } }.prefix(20), id: \.id) { r in
                        Button { selected.append(r); query = "" } label: {
                            HStack(spacing: 4) {
                                Image(systemName: "plus").font(.system(size: 10, weight: .bold)).foregroundStyle(wa.accent)
                                Text(verbatim: r.name ?? "").font(.dorr(12)).foregroundStyle(wa.settingsInk)
                            }
                            .padding(.horizontal, 12).padding(.vertical, 6)
                            .overlay(Capsule().stroke(wa.accent.opacity(0.4), lineWidth: 1))
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
        .task(id: "\(query)|\(specialtyId ?? 0)") {
            let q = query.trimmingCharacters(in: .whitespaces)
            if q.count < 2 {
                guard q.isEmpty, let specialtyId else { results = []; return }
                results = ((try? await FreelanceApi.skills(q: nil, specialtyId: specialtyId).data) ?? []).map { FreelanceNamedDto(id: $0.id, name: $0.name) }
                return
            }
            try? await Task.sleep(nanoseconds: 400_000_000)
            guard !Task.isCancelled else { return }
            results = ((try? await FreelanceApi.skills(q: q).data) ?? []).map { FreelanceNamedDto(id: $0.id, name: $0.name) }
        }
    }
}

/// The specialty tree, read once: a parent is a heading, its children are picked (a parent without children is picked itself).
@MainActor
private struct SpecialtyDialog: View {
    @Environment(\.wa) private var wa
    let onPick: (FreelanceNamedDto) -> Void
    @State private var tree: [FreelanceSpecialtyNodeDto]?

    var body: some View {
        FlSheet(icon: "square.grid.2x2", title: L("fl_pick_specialty"), text: "") {
            if let nodes = tree {
                ForEach(nodes, id: \.id) { parent in
                    if (parent.children ?? []).isEmpty {
                        row(parent.name ?? "", bold: true) { onPick(FreelanceNamedDto(id: parent.id, name: parent.name)) }
                    } else {
                        Text(verbatim: parent.name ?? "").font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut).padding(.top, 6)
                        ForEach(parent.children ?? [], id: \.id) { child in
                            row(child.name ?? "", bold: false) { onPick(FreelanceNamedDto(id: child.id, name: child.name)) }
                        }
                    }
                }
            } else {
                ForEach(0..<5, id: \.self) { _ in WaSkeleton(cornerRadius: 12).frame(height: 40) }
            }
        }
        .task { tree = (try? await FreelanceApi.specialties().data) ?? [] }
    }

    private func row(_ text: String, bold: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(14, bold ? .bold : .regular)).foregroundStyle(wa.settingsInk)
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(12)
                .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}
