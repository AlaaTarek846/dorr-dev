import SwiftUI
import UniformTypeIdentifiers

// androidApp `freelance/FreelanceHost.kt` — freelance work for the client (FL-201 / FL-202): the hub (`FreelanceHome`)
// and the pages that open over it — my projects, the project form (a draft saved as it is typed, its files) and one
// project with what can be done now (send for review, take it back, cancel, delete), the services, consultations and the
// rest. Every project is reviewed before it is published. Nothing is asked on a timer: each page reads once when it opens
// and when the connection comes back.

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
func flAmount(_ minor: Int?, _ currency: String?) -> String { "\u{200E}\(flMoney(minor)) \(flCurrencySign(currency))" }

/// A currency code as the sign people know ("SAR" shows as «﷼»); any other code stays as it is. Mirrors Android's `CurrencyLabelAdapter`.
func flCurrencySign(_ code: String?) -> String {
    let c = (code ?? "").trimmingCharacters(in: .whitespaces)
    return c.uppercased() == "SAR" ? "\u{FDFC}" : c
}

@MainActor
struct FreelanceHost: View {
    let onExit: () -> Void
    // The open pages (see `FlStack`): the hub first, the page on screen last.
    @State private var stack = FlStack.start
    @State private var listVersion = 0
    @ObservedObject private var link = FreelanceLink.shared

    private func go(_ next: FlStack) { stack = next }
    private func open(_ route: FlRoute) { go(stack.open(route)) }
    private func replace(_ route: FlRoute) { go(stack.replace(route)) }
    private func back() { if stack.routes.count > 1 { go(stack.back()) } else { onExit() } }

    var body: some View {
        ZStack {
            PinkBackdrop()
            // The hub and the lists stay alive under the page on screen, so they keep what they hold (a filter, the scroll)
            // until they are left; a page of one item is drawn afresh when it is back on screen (see `FlRoute.keepsAlive`).
            ForEach(stack.routes.filter { $0.keepsAlive || $0 == stack.top }, id: \.key) { route in
                let onTop = route == stack.top
                page(route)
                    .opacity(onTop ? 1 : 0)
                    .allowsHitTesting(onTop)
                    .accessibilityHidden(!onTop)
            }
        }
        // FL-L13: no screenshots or recordings of the freelance work section (Android FLAG_SECURE twin; see StoryViewer).
        .screenshotShield(true)
        // A tapped notification names the page it belongs to (also when this screen is already open).
        .onAppear(perform: openLinked)
        .onChange(of: link.pending) { _ in openLinked() }
        #if DEBUG
        .task { if let start = UserDefaults.standard.string(forKey: "DorrFreelancePage") { stack = FlStack.read(start) } }
        #endif
    }

    @ViewBuilder
    private func page(_ route: FlRoute) -> some View {
        switch route {
        case .home:
            FreelanceHome(version: listVersion, onExit: onExit, open: open)
        case .projects:
            ProjectList(version: listVersion, onBack: back, onNew: { open(.newProject) }, onOpen: { open(.project($0)) })
        case .newProject:
            ProjectForm(uuid: nil, onBack: back, onSaved: { listVersion += 1; replace(.project($0)) })
        case .editProject(let p):
            ProjectForm(uuid: p, onBack: back, onSaved: { listVersion += 1; replace(.project($0)) })
        case .project(let p):
            ProjectDetails(uuid: p, onBack: back, onEdit: { open(.editProject($0)) },
                           onGone: { listVersion += 1; back() }, onPreview: { open(.preview(p)) },
                           onCopied: { listVersion += 1; replace(.project($0)) }, onOffers: { open(.offers(p)) },
                           onOpenOffer: { open(.offer(p, $0)) }, onAgreement: { open(.agreement(p)) },
                           onInvitations: { open(.invitations(p)) })
        case .preview(let p):
            PreviewPage(uuid: p, onBack: back, onEdit: { replace(.editProject(p)) }, onSent: { listVersion += 1; back() })
        case .invitations(let p):
            InvitationsPage(projectUuid: p, onBack: back, onOpenOffer: { open(.offer(p, $0)) }, onMadePublic: { listVersion += 1; back() })
        case .offers(let p):
            OffersPage(projectUuid: p, onBack: back, onOpen: { open(.offer(p, $0)) }, onCompare: { open(.compare(p, $0)) })
        case .compare(let p, let ids):
            ComparePage(projectUuid: p, ids: ids, onBack: back, onOpen: { open(.offer(p, $0)) },
                        onChosen: { listVersion += 1; go(stack.after({ $0 == .project(p) }, .agreement(p))) })
        case .offer(let p, let o):
            // The work is agreed: its agreement opens over the project (back from it is the project, not the offers).
            OfferPage(projectUuid: p, offerUuid: o, onBack: back, onProfile: { open(.freelancer(p, o)) },
                      onChat: { open(.chat(p, o, $0)) }, onChosen: { listVersion += 1; go(stack.after({ $0 == .project(p) }, .agreement(p))) })
        case .agreement(let p):
            AgreementPage(projectUuid: p, onBack: back, onEnded: { listVersion += 1 },
                          onDispute: { m, d in open(d.map { .dispute(p, $0) } ?? .newDispute(p, m)) })
        case .dispute(let p, let d):
            DisputePage(projectUuid: p, milestoneUuid: nil, disputeUuid: d, onBack: back, onOpened: { _ in })
        case .newDispute(let p, let m):
            DisputePage(projectUuid: p, milestoneUuid: m, disputeUuid: nil, onBack: back, onOpened: { replace(.dispute(p, $0)) })
        case .favorites:
            FavoritesPage(onBack: back)
        case .receipts:
            ReceiptsPage(onBack: back)
        // «Services»: the ready-made services and the consultations as two parts of one page.
        case .services, .consultMarket:
            ServicesPage(start: route == .consultMarket ? 1 : 0, onBack: back, onOpenService: { open(.service($0)) }, onOrders: { open(.orders) },
                         onBook: { open(.book($0)) }, onMine: { open(.consultations) })
        case .service(let u):
            ServicePage(uuid: u, onBack: back, onOrdered: { listVersion += 1; replace(.order($0)) })
        case .consultation(let u):
            ConsultationPage(uuid: u, onBack: back)
        case .book(let o):
            BookConsultationPage(offerUuid: o, onBack: back, onBooked: { replace(.consultation($0)) })
        case .specialOffer(let u):
            SpecialOfferPage(uuid: u, onBack: back,
                             onOpenWork: { listVersion += 1; open(.agreement($0)) }, onOpenOrder: { listVersion += 1; open(.order($0)) },
                             onOpenConsultation: { open(.consultation($0)) }, onBook: { special, consult in open(.specialBook(special, consult)) })
        case .specialBook(let special, let o):
            BookConsultationPage(offerUuid: o, onBack: back, onBooked: { listVersion += 1; replace(.consultation($0)) }, special: special)
        case .loyalty:
            LoyaltyPage(onBack: back)
        // «My activity»: my orders, my consultations and the special offers as three parts of one page.
        case .orders, .consultations, .specialOffers:
            ActivityPage(start: route == .consultations ? 1 : (route == .specialOffers ? 2 : 0), onBack: back,
                         onOpenOrder: { open(.order($0)) }, onBrowse: { open(.services) },
                         onOpenConsultation: { open(.consultation($0)) }, onOpenSpecial: { open(.specialOffer($0)) })
        case .order(let u):
            MyOrderPage(uuid: u, onBack: back, onOpenWork: { open(.agreement($0)) }, onOrderAgain: { open(.service($0)) })
        case .chat(_, _, let room):
            FreelanceChatPage(roomUuid: room, onBack: back)
        case .freelancer(let p, let o):
            FreelancerProfilePage(projectUuid: p, offerUuid: o, onBack: back, onBookConsultation: { open(.book($0)) })
        }
    }

    private func openLinked() {
        guard let linked = link.pending else { return }
        stack = FlStack.read(linked)
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
    /// true: the text may take two lines and the chip fills its share of the row (several chips side by side, a long label is not cut).
    var fit = false
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                if let icon { Image(systemName: icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(selected ? .white : wa.accent) }
                if fit { Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(selected ? .white : wa.settingsInk).lineLimit(2).multilineTextAlignment(.center) }
                else { Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(selected ? .white : wa.settingsInk).lineLimit(1).fixedSize() }
            }
            .frame(maxWidth: fit ? .infinity : nil)
            .padding(.horizontal, 14).padding(.vertical, 8)
            // A comfortable finger (40pt at least), read out with whether it is the one picked.
            .frame(minHeight: 40)
            .background(selected ? wa.accent : wa.accent.opacity(0.08), in: Capsule())
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(selected ? [.isSelected] : [])
    }
}

/// A count in the app's language digits (a bare "\(n)" would stay Latin next to «٣ سنين»).
func flNum(_ n: Int) -> String {
    let f = NumberFormatter()
    f.numberStyle = .none
    f.locale = Locale(identifier: AppLocale.current)
    return f.string(from: NSNumber(value: n)) ?? String(n)
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
    let error: String?

    var body: some View {
        if let error {
            FlRetry(message: error).frame(maxHeight: .infinity, alignment: .top)
        } else {
            FlSkeletonDetail()
        }
    }
}

/// androidApp `FlRetry` — a read that failed: what went wrong and «Try again», which reads the screens on show once more (the
/// same as a pull — never a timer).
struct FlRetry: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let message: String

    var body: some View {
        VStack(spacing: 12) {
            Image(systemName: "icloud.slash").font(.system(size: 24, weight: .semibold)).foregroundStyle(wa.accent)
                .frame(width: 56, height: 56).background(wa.accent.opacity(0.10), in: Circle())
                .accessibilityHidden(true)
            Text(verbatim: message).font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).lineSpacing(3)
            FlAction(text: L("flx_retry"), icon: "arrow.clockwise") { network.noticeArrived() }.frame(width: 200)
        }
        .frame(maxWidth: .infinity)
        .padding(24)
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

/// The statuses under «Running» and under «Ended» (nil: all of that part).
private let flActiveFilters: [String?] = [nil, "draft", "pending_review", "changes_requested", "published", "awarded", "in_progress"]
private let flEndedFilters: [String?] = [nil, "closed", "rejected", "cancelled"]

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
    case "closed": return "checkmark.seal.fill"
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
    if b.type == "range", let max = b.maxMinor { return "\u{200E}\(flMoney(min)) – \(flMoney(max)) \(flCurrencySign(b.currency))" }
    return "\u{200E}\(flMoney(min)) \(flCurrencySign(b.currency))"
}

private func untitled(_ p: FreelanceProjectDto) -> String { (p.title?.isEmpty == false ? p.title : nil) ?? L("fl_untitled") }

/// My projects (the client redesign, batch C): «Running» and «Ended» side by side, the statuses of that part as chips, «New project»
/// in the head, pulling down reads the list again. Each card says where the project stands and, on a coloured line, what is wanted
/// from me.
@MainActor
private struct ProjectList: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let version: Int
    let onBack: () -> Void
    let onNew: () -> Void
    let onOpen: (String) -> Void
    @State private var ended = false
    @State private var filter: String?
    // A new part or filter is a new list (it starts from the first page); the next pages come while I scroll.
    @StateObject private var paged = PagedRows<FreelanceProjectDto>(key: { $0.uuid })

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: L("fl_my_projects"), onBack: onBack,
                         trailing: AnyView(FlHeaderAction(text: L("fl_new_project"), icon: "plus", action: onNew)))
            FlTabs(tabs: [FlTab(icon: "arrow.triangle.2.circlepath", label: L("flp_scope_active")), FlTab(icon: "archivebox.fill", label: L("flp_scope_ended"))],
                   selected: ended ? 1 : 0) { i in
                if (i == 1) != ended { ended = i == 1; filter = nil }
            }
            .padding(.horizontal, 16)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(ended ? flEndedFilters : flActiveFilters, id: \.self) { f in
                        FlChip(text: f.map(flStatusLabel) ?? L("fl_filter_all"), selected: filter == f, icon: filterIcon(f)) { filter = f }
                    }
                }
                .padding(.horizontal, 14).padding(.vertical, 10)
            }
            if !paged.loaded && paged.error == nil {
                FlSkeletonList()
            } else {
                ScrollView {
                    LazyVStack(spacing: 12) {
                        // FL-708: shown only while a warning / restriction is active or its appeal waits.
                        SanctionsCard(refreshKey: version)
                        if paged.rows.isEmpty && paged.error == nil {
                            WaEmpty(icon: ended ? "archivebox.fill" : "briefcase.fill", tone: .blue,
                                    title: L(ended ? "flp_empty_ended_title" : "fl_empty_title"), text: L(ended ? "flp_empty_ended_text" : "fl_empty_text"))
                            if !ended && filter == nil { FlAction(text: L("flh_new_action"), icon: "plus", tone: .primary, action: onNew) }
                        }
                        ForEach(Array(paged.rows.enumerated()), id: \.element.uuid) { i, p in
                            ProjectCard(p: p) { onOpen(p.uuid) }.onAppear { paged.appeared(i) }
                        }
                        PagedSpinner(paged: paged)
                        PagedError(paged: paged)
                    }
                    .padding(.horizontal, 16).padding(.top, 2).padding(.bottom, 20)
                }
                // Pulling down reads the list again (the client's own gesture, not a timer).
                .refreshable { await paged.reload() }
            }
        }
        .task(id: "\(ended)|\(filter ?? "")") {
            let status = filter
            let scope = ended ? "ended" : "active"
            paged.reset { page in try await FreelanceApi.projects(status: status, page: page, scope: scope) }
        }
        .task(id: "\(ended)|\(filter ?? "")|\(network.reconnectTick)|\(version)") { await paged.reload() }
    }
}

/// A project on a list (my projects, the hub): its status on its colour, the title and specialty, the budget, the days and the
/// offers, the parts done when the work is running, and — on a coloured line under it — what is wanted from me now.
struct ProjectCard: View {
    @Environment(\.wa) private var wa
    let p: FreelanceProjectDto
    let onClick: () -> Void

    var body: some View {
        let color = flStatusColor(p.state)
        let offers = p.offers?.count ?? 0
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 0) {
                VStack(alignment: .leading, spacing: 10) {
                    HStack(alignment: .top, spacing: 12) {
                        Image(systemName: p.state == "published" && p.visibility == "private" ? "lock.fill" : filterIcon(p.state)).font(.system(size: 18)).foregroundStyle(color)
                            .frame(width: 44, height: 44)
                            .background(color.opacity(0.12), in: RoundedRectangle(cornerRadius: FlCorners.icon, style: .continuous))
                        VStack(alignment: .leading, spacing: 3) {
                            Text(verbatim: untitled(p)).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                            if let s = p.specialty { Text(verbatim: s.name ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        FlPill(text: flStatusLabel(p.state), color: color)
                    }
                    HStack(spacing: 14) {
                        if let b = budgetText(p) { CardFact(icon: "banknote", text: b, color: wa.accent, bold: true) }
                        if let d = p.durationDays { CardFact(icon: "clock", text: L("fl_days", d), color: wa.settingsMut) }
                        if (p.state == "published" || p.state == "awarded") && offers > 0 { CardFact(icon: "doc.text.fill", text: L("flp_offers_n", offers), color: flGreen, bold: true) }
                    }
                    // FL-211: where the work stands.
                    if let g = p.progress {
                        FlProgressBar(done: g.approved ?? 0, total: g.total ?? 0)
                        Text(verbatim: [L("fl_progress_parts", g.approved ?? 0, g.total ?? 0),
                                        (g.inReview ?? 0) > 0 ? L("fl_progress_review", g.inReview ?? 0) : nil].compactMap { $0 }.joined(separator: "  ·  "))
                            .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                }
                .padding(14)
                // What is due from me, on its own line at the foot of the card.
                if let next = p.nextAction {
                    HStack(spacing: 8) {
                        Image(systemName: "bell.badge.fill").font(.system(size: 13))
                        Text(verbatim: flNextActionLabel(next)).font(.dorr(12.5, .heavy)).lineLimit(1)
                        Spacer(minLength: 0)
                    }
                    .foregroundStyle(flAmber)
                    .padding(.horizontal, 14).padding(.vertical, 10)
                    .background(flAmber.opacity(0.10))
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .settingsSurface(FlCorners.card)
            .clipShape(RoundedRectangle(cornerRadius: FlCorners.card, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .combine)
    }
}

/// A small fact on a project card: an icon and a line.
private struct CardFact: View {
    let icon: String
    let text: String
    let color: Color
    var bold = false

    var body: some View {
        HStack(spacing: 5) {
            Image(systemName: icon).font(.system(size: 12, weight: .semibold))
            Text(verbatim: text).font(.dorr(12.5, bold ? .heavy : .semibold)).lineLimit(1)
        }
        .foregroundStyle(color)
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
        FlNotice(text: L("fl_next_label", flNextActionLabel(code)), icon: "bell.badge.fill", color: flAmber)
    }
}

/// What is wanted from the client now (`next_action`), in words.
private func flNextActionLabel(_ code: String) -> String {
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
    // The part of the page on show (nil: the one where the next step is), and «More».
    @State private var tab: String?
    @State private var more = false

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
            FlPageHeader(title: L("fl_project"), onBack: onBack)
            if let p = project {
                ScrollView { content(p).padding(.horizontal, 16) }
                footer(p)
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: network.reconnectTick) {
            do { project = try await FreelanceApi.project(uuid).data } catch { self.error = flError(error) }
        }
        .dorrDialog(isPresented: Binding(get: { more }, set: { if !$0 { more = false } }), bottom: true) {
            if let p = project {
                FlSheet(icon: "ellipsis.circle.fill", title: L("flp_more_title"), text: untitled(p)) {
                    ForEach(moreSteps(p, primary: primaryStep(p)?.key), id: \.key) { step in
                        FlAction(text: step.text, icon: step.icon, tone: step.tone, enabled: !busy) { more = false; step.action() }
                    }
                }
            }
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

    /// A step on the project page: the main one at the foot, the others under «More».
    private struct Step {
        let key: String
        let text: String
        let icon: String
        var tone: FlTone = .soft
        var enabled = true
        let action: () -> Void
    }

    /// The one step that moves the project on from where it stands (nil when nothing is up to me).
    private func primaryStep(_ p: FreelanceProjectDto) -> Step? {
        let can = p.can ?? FreelanceProjectCanDto()
        // FL-210: a last look before it goes out (it also says what doesn't add up).
        if can.submit == true { return Step(key: "submit", text: L("fl_review_and_send"), icon: "paperplane.fill", tone: .primary, enabled: (p.missing ?? []).isEmpty, action: onPreview) }
        if p.state == "published" && (p.offers?.count ?? 0) > 0 { return Step(key: "offers", text: L("fl_compare_choose"), icon: "arrow.left.arrow.right", tone: .primary, action: onOffers) }
        if let ag = p.agreement {
            return Step(key: "agreement", text: L(ag.status == "draft" || ag.status == nil ? "fl_ag_open_draft" : "fl_ag_open"), icon: "person.2.fill", tone: .primary, action: onAgreement)
        }
        if p.state == "published" && p.invitations?.canInvite == true { return Step(key: "invitations", text: L("fl_inv_manage"), icon: "person.badge.plus", tone: .primary, action: onInvitations) }
        if can.edit == true { return Step(key: "edit", text: L("fl_edit"), icon: "pencil", tone: .primary) { onEdit(p.uuid) } }
        return nil
    }

    /// What else can be done with the project, in the order it is wanted (stepping back last).
    private func moreSteps(_ p: FreelanceProjectDto, primary: String?) -> [Step] {
        let can = p.can ?? FreelanceProjectCanDto()
        var out: [Step] = []
        if can.edit == true && primary != "edit" { out.append(Step(key: "edit", text: L("fl_edit"), icon: "pencil") { onEdit(p.uuid) }) }
        if let inv = p.invitations, p.state == "published", primary != "invitations" {
            out.append(Step(key: "invitations", text: L(inv.canInvite == true ? "fl_inv_manage" : "fl_inv_see"), icon: "person.badge.plus", action: onInvitations))
        }
        if can.setCostCap == true {
            out.append(Step(key: "cap", text: L(p.maxCostMinor == nil ? "fl_cost_cap_set" : "fl_cost_cap_change"), icon: "dollarsign.circle.fill") { capError = nil; capDialog = true })
        }
        // FL-209: ask for it again from this one.
        if can.duplicate == true {
            out.append(Step(key: "copy", text: L("fl_copy_project"), icon: "doc.on.doc") {
                busy = true
                error = nil
                Task {
                    do { if let copy = try await FreelanceApi.duplicate(p.uuid).data { onCopied(copy.uuid) } } catch { self.error = flError(error) }
                    busy = false
                }
            })
        }
        if can.withdraw == true { out.append(Step(key: "withdraw", text: L("fl_withdraw"), icon: "arrow.uturn.backward", tone: .warn) { act({ try await FreelanceApi.withdraw(p.uuid).data }) }) }
        if can.cancel == true { out.append(Step(key: "cancel", text: L("fl_cancel_project"), icon: "xmark.circle.fill", tone: .danger) { cancelCode = nil; cancelNote = ""; confirm = "cancel" }) }
        if can.delete == true { out.append(Step(key: "delete", text: L("fl_delete_draft"), icon: "trash.fill", tone: .danger) { confirm = "delete" }) }
        return out
    }

    /// The parts of the page: the details always; the offers while they are taken (or there are some); the work once agreed.
    private func tabs(_ p: FreelanceProjectDto) -> [String] {
        var out = ["details"]
        if p.state == "published" || p.state == "awarded" || (p.offers?.count ?? 0) > 0 { out.append("offers") }
        if p.agreement != nil || !(p.milestones ?? []).isEmpty { out.append("work") }
        return out
    }

    private func currentTab(_ p: FreelanceProjectDto, _ tabs: [String]) -> String {
        if let tab, tabs.contains(tab) { return tab }
        let wanted: String?
        switch p.nextAction {
        case "review_offers": wanted = "offers"
        case "send_agreement", "review_delivery", "fund_milestone", "dispute": wanted = "work"
        default: wanted = nil
        }
        if let wanted, tabs.contains(wanted) { return wanted }
        return "details"
    }

    /// The main step for where the project stands, always in reach; the rest under «More».
    @ViewBuilder
    private func footer(_ p: FreelanceProjectDto) -> some View {
        let primary = primaryStep(p)
        let others = moreSteps(p, primary: primary?.key)
        if primary != nil || !others.isEmpty {
            HStack(spacing: 10) {
                if let primary {
                    FlAction(text: primary.text, icon: primary.icon, tone: .primary, enabled: primary.enabled && !busy, loading: busy && primary.key == "submit", action: primary.action)
                }
                if !others.isEmpty {
                    if primary == nil {
                        FlAction(text: L("flp_more_title"), icon: "ellipsis") { more = true }
                    } else {
                        Button { more = true } label: {
                            Image(systemName: "ellipsis").font(.system(size: 17, weight: .bold)).foregroundStyle(wa.accent)
                                .frame(width: 48, height: 48)
                                .background(wa.accent.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel(L("flp_more"))
                    }
                }
            }
            .padding(.horizontal, 16).padding(.vertical, 10)
            .background(wa.night ? wa.darkCard : Color.white)
        }
    }

    @ViewBuilder
    private func content(_ p: FreelanceProjectDto) -> some View {
        let offers = p.offers ?? FreelanceOffersDto()
        let missing = p.missing ?? []
        let primary = primaryStep(p)?.key
        let parts = tabs(p)
        let current = currentTab(p, parts)
        VStack(alignment: .leading, spacing: 12) {
            ProjectHero(p: p)
            if parts.count > 1 {
                FlTabs(tabs: parts.map { (t: String) -> FlTab in
                    switch t {
                    case "offers": return FlTab(icon: "doc.text.fill", label: L("flp_tab_offers"), badge: p.state == "published" ? (offers.count ?? 0) : 0)
                    case "work": return FlTab(icon: "person.2.fill", label: L("flp_tab_work"))
                    default: return FlTab(icon: "doc.plaintext", label: L("flp_tab_details"))
                    }
                }, selected: parts.firstIndex(of: current) ?? 0) { tab = parts[$0] }
            }
            FlNotices(notice: notice, error: error)

            switch current {
            case "offers":
                FlCard(icon: "doc.text.fill", title: L("fl_offers") + ((offers.count ?? 0) > 0 ? " (\(offers.count ?? 0))" : "")) {
                    FlNotice(text: offersLine(p, offers), icon: offers.accepting == true ? "checkmark.circle.fill" : "lock.fill",
                             color: p.state == "awarded" ? Color(hex: 0x7C3AED) : (offers.accepting == true ? flGreen : flAmber))
                    // FL-305 / FL-309: compare them and choose one.
                    if let chosen = offers.chosen { FlAction(text: L("fl_see_chosen"), icon: "star.fill", tone: .primary) { onOpenOffer(chosen) } }
                    if (offers.count ?? 0) > 0 && primary != "offers" {
                        FlAction(text: L(p.state == "published" ? "fl_compare_choose" : "fl_see_offers"), icon: "arrow.left.arrow.right", action: onOffers)
                    }
                    if (offers.count ?? 0) == 0 { Text(L("flp_no_offers_yet")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(3) }
                    if offers.canClose == true { FlAction(text: L("fl_offers_close"), icon: "lock.fill", tone: .warn) { act({ try await FreelanceApi.closeOffers(p.uuid).data }) } }
                    if offers.canReopen == true {
                        FlAction(text: L("fl_offers_reopen", offers.reopensLeft ?? 0), icon: "lock.open.fill") { act({ try await FreelanceApi.reopenOffers(p.uuid).data }) }
                    }
                }
            case "work":
                // FL-401: the agreement with the chosen freelancer.
                if let ag = p.agreement {
                    FlCard(icon: "person.2.fill", title: L("fl_agreement")) {
                        Text(verbatim: agreementStatusLabel(ag.status ?? "draft")).font(.dorr(13, .bold)).foregroundStyle(agreementStatusColor(ag.status ?? "draft"))
                        if primary != "agreement" {
                            FlAction(text: L(ag.status == "draft" || ag.status == nil ? "fl_ag_open_draft" : "fl_ag_open"), icon: "person.2.fill", tone: .primary, action: onAgreement)
                        }
                    }
                }
                // FL-211: the stages of the work, each with where it stands.
                if let stages = p.milestones, !stages.isEmpty { StagesCard(p: p, stages: stages) }
            default:
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
                // FL-205: a private project is filled by invitation.
                if let inv = p.invitations, p.state == "published" {
                    FlCard(icon: "envelope.badge.fill", title: L("fl_inv_title")) {
                        Text(verbatim: L("fl_inv_summary", inv.sent ?? 0, inv.accepted ?? 0, inv.declined ?? 0)).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                        if primary != "invitations" { FlAction(text: L(inv.canInvite == true ? "fl_inv_manage" : "fl_inv_see"), icon: "person.badge.plus", action: onInvitations) }
                    }
                }
                if !missing.isEmpty { FlNotice(text: L("fl_missing", missing.map(missingLabel).joined(separator: "، ")), icon: "exclamationmark.triangle.fill", color: flAmber) }
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
            }
            Spacer(minLength: 16)
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

/// The head of the project page: a band of its status colour, the status, title and specialty, three figures and what the staff said.
private struct ProjectHero: View {
    @Environment(\.wa) private var wa
    let p: FreelanceProjectDto

    var body: some View {
        let c = flStatusColor(p.state)
        VStack(alignment: .leading, spacing: 0) {
            c.frame(height: 5)
            VStack(alignment: .leading, spacing: 10) {
                HStack(spacing: 8) {
                    FlPill(text: flStatusLabel(p.state), color: c)
                    if let s = p.specialty { Text(verbatim: s.name ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                }
                Text(verbatim: untitled(p)).font(.dorr(19, .heavy)).foregroundStyle(wa.settingsInk)
                HStack(spacing: 8) {
                    FlStat(icon: "banknote", label: L("fl_budget"), value: budgetText(p) ?? "—").layoutPriority(1.4)
                    FlStat(icon: "clock", label: L("fl_duration"), value: p.durationDays.map { L("fl_days", $0) } ?? "—")
                    FlStat(icon: "doc.text.fill", label: L("fl_offers"), value: "\(p.offers?.count ?? 0)")
                }
                if let g = p.progress {
                    FlProgressBar(done: g.approved ?? 0, total: g.total ?? 0)
                    Text(verbatim: L("fl_progress_parts", g.approved ?? 0, g.total ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                if let reason = p.statusReason, !reason.isEmpty { FlNotice(text: L("fl_reviewer_note", reason), icon: "info.circle.fill", color: c) }
                if p.state == "pending_review" { FlNotice(text: L("fl_under_review_hint"), icon: "hourglass", color: flAmber) }
                if p.state == "rejected" { FlNotice(text: L("fl_rejected_hint"), icon: "nosign", color: flRed) }
                if let next = p.nextAction { NextActionLine(code: next) }
            }
            .padding(16)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(FlCorners.card)
        .clipShape(RoundedRectangle(cornerRadius: FlCorners.card, style: .continuous))
    }
}

/// FL-211: the stages of the work, each with where it stands, joined by a line the way the work runs, and the money passed so far.
private struct StagesCard: View {
    @Environment(\.wa) private var wa
    let p: FreelanceProjectDto
    let stages: [FreelanceProjectPartDto]

    var body: some View {
        FlCard(icon: "flag.fill", title: L("fl_stages")) {
            if let g = p.progress {
                FlProgressBar(done: g.approved ?? 0, total: g.total ?? 0)
                Text(verbatim: L("fl_progress_parts", g.approved ?? 0, g.total ?? 0) + "  ·  " +
                     L("fl_progress_money", "\u{200E}" + flMoney(g.approvedMinor ?? 0), flMoney(g.priceMinor ?? 0), p.budget?.currency ?? ""))
                    .font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            ForEach(Array(stages.enumerated()), id: \.offset) { i, m in
                let mc = milestoneStatusColor(m.status ?? "unfunded")
                HStack(alignment: .top, spacing: 10) {
                    VStack(spacing: 0) {
                        Text(verbatim: "\(m.position ?? 1)").font(.dorr(12, .heavy)).foregroundStyle(.white)
                            .frame(width: 26, height: 26).background(mc, in: Circle())
                        if i < stages.count - 1 { mc.opacity(0.35).frame(width: 2, height: 30) }
                    }
                    VStack(alignment: .leading, spacing: 0) {
                        HStack {
                            Text(verbatim: m.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                            Spacer(minLength: 6)
                            Text(verbatim: milestoneStatusLabel(m.status ?? "unfunded")).font(.dorr(12, .bold)).foregroundStyle(mc)
                        }
                        Text(verbatim: "\u{200E}\(flMoney(m.amountMinor ?? 0)) \(p.budget?.currency ?? "")" + (m.durationDays.map { "  ·  " + L("fl_days", $0) } ?? ""))
                            .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                    .padding(.top, 2)
                }
            }
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
            FlPageHeader(title: L("fl_preview_title"), onBack: onBack)
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
/// androidApp `FormProblem` — one line under a field: what is wrong with what was typed.
private struct FormProblem: View {
    let text: String

    var body: some View {
        HStack(alignment: .top, spacing: 6) {
            Image(systemName: "exclamationmark.circle").font(.system(size: 13)).foregroundStyle(Color(hex: 0xDC2626))
            Text(verbatim: text).font(.dorr(12)).foregroundStyle(Color(hex: 0xDC2626)).lineSpacing(3)
        }
    }
}

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
    /// What the lowest budget costs in all (the client fee included), asked of the server a moment after typing stops.
    @State private var lowCost: FreelanceCostQuoteDto?
    @State private var pickSpecialty = false
    @State private var picking = false
    @State private var busy = false
    @State private var uploading = false
    @State private var error: String?
    @State private var initial: String?
    @State private var leaving = false

    private func moneyOrNil(_ text: String) -> Int? {
        let t = text.trimmingCharacters(in: .whitespaces)
        return t.isEmpty ? nil : flParseMoney(t)
    }

    /// What is wrong with the money, said next to the field as it is typed — so it is fixed before the draft is saved, not found at review.
    private var amountProblem: String? {
        let low = moneyOrNil(budgetMin)
        let high = budgetType == "range" ? moneyOrNil(budgetMax) : nil
        if let low, let min = rules?.minMinor, low < min { return L("fl_err_below_min", flMoney(min), rules?.currency ?? "") }
        if let top = high ?? low, let max = rules?.maxMinor, top > max { return L("fl_err_above_max", flMoney(max), rules?.currency ?? "") }
        return nil
    }

    private var rangeProblem: String? {
        if budgetType == "range", let low = moneyOrNil(budgetMin), let high = moneyOrNil(budgetMax), high <= low { return L("fl_err_range") }
        return nil
    }

    private var capProblem: String? {
        guard let cap = moneyOrNil(maxCost), let low = moneyOrNil(budgetMin) else { return nil }
        if cap < low { return L("fl_err_cap_below") }
        if let q = lowCost, q.totalMinor > cap { return L("fl_err_cap_below_fees", flMoney(q.totalMinor), q.currency ?? "") }
        return nil
    }

    /// A form left as it was opens no question; a changed one asks before it is left (a back tap would lose the changes).
    private var signature: String {
        [title, description, specialty.map { "\($0.id)" } ?? "", skills.map { "\($0.id)" }.joined(separator: ","), budgetType, budgetMin, budgetMax,
         maxCost, duration, visibility, questions.joined(separator: "\u{1F}")].joined(separator: "\u{1E}")
    }

    private var dirty: Bool { initial != nil && signature != initial }

    private func tryBack() { if dirty { leaving = true } else { onBack() } }

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
        if let problem = amountProblem ?? rangeProblem ?? capProblem { error = problem; return }
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
            FlPageHeader(title: L(uuid == nil ? "fl_new_project" : "fl_edit"), onBack: tryBack)
            if !loaded {
                FlLoading(error: error)
            } else {
                ScrollView { form.padding(.horizontal, 16) }.scrollDismissesKeyboard(.interactively)
                SettingsPrimaryButton(text: L("fl_save_draft"), loading: busy) { save(then: onSaved) }.padding(16)
            }
        }
        .onChange(of: loaded) { now in if now && initial == nil { initial = signature } }
        .dorrDialog(isPresented: $leaving) {
            ConfirmDialog(icon: "square.and.arrow.down.fill", title: L("fl_leave_title"), message: L("fl_leave_body"),
                          confirmLabel: L("fl_leave_save"), dismissLabel: L("fl_leave_keep"), confirmTint: wa.accent,
                          extraLabel: L("fl_leave_discard"), onExtra: { leaving = false; onBack() },
                          onConfirm: { leaving = false; save(then: onSaved) },
                          onDismiss: { leaving = false })
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
        .task(id: "\(moneyOrNil(budgetMin) ?? 0)|\(specialty?.id ?? 0)") {
            lowCost = nil
            guard let low = moneyOrNil(budgetMin), low > 0 else { return }
            try? await Task.sleep(nanoseconds: 400_000_000)
            if Task.isCancelled { return }
            lowCost = try? await FreelanceApi.costQuote(amountMinor: low, specialtyId: specialty?.id).data
        }
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
                if let p = amountProblem ?? rangeProblem { FormProblem(text: p) }
                if let r = rules {
                    let lines = [r.minMinor.map { L("fl_budget_min_rule", flMoney($0), r.currency ?? "") },
                                 r.maxMinor.map { L("fl_budget_max_rule", flMoney($0), r.currency ?? "") }].compactMap { $0 }
                    if !lines.isEmpty { Text(verbatim: "\u{200E}" + lines.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                }
                // FL-210: the most I will pay in all.
                DorrTextArea(value: $maxCost, label: L("fl_cost_cap_field", currency), icon: "dollarsign.circle.fill", singleLine: true).keyboardType(.decimalPad)
                if let p = capProblem { FormProblem(text: p) }
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
