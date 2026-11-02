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
    // "list", "new", "edit:<uuid>", "show:<uuid>", "offers:<p>", "offer:<p>:<o>", "compare:<p>:<o,o,o>", "freelancer:<p>:<o>", "chat:<p>:<o>:<room>"
    @State private var page = "list"
    @State private var listVersion = 0

    private var parts: [String] { page.components(separatedBy: ":") }

    private func back() -> String {
        let p = parts
        switch p[0] {
        case "edit", "offers": return "show:" + p[1]
        case "offer", "compare": return "offers:" + p[1]
        case "chat", "freelancer": return "offer:" + p[1] + ":" + p[2]
        case "agreement", "invites": return "show:" + p[1]
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
                                   onGone: { page = "list"; listVersion += 1 }, onOffers: { page = "offers:" + p[1] },
                                   onOpenOffer: { page = "offer:\(p[1]):\($0)" }, onAgreement: { page = "agreement:" + p[1] },
                                   onInvitations: { page = "invites:" + p[1] })
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
                case "chat":
                    FreelanceChatPage(roomUuid: p[3], onBack: { page = back() })
                case "freelancer":
                    FreelancerProfilePage(projectUuid: p[1], offerUuid: p[2], onBack: { page = back() })
                default:
                    ProjectList(version: listVersion, onExit: onExit, onNew: { page = "new" }, onOpen: { page = "show:\($0)" }, onFavorites: { page = "favorites" })
                }
            }
            .id(page)
        }
        #if DEBUG
        .task { if let start = UserDefaults.standard.string(forKey: "DorrFreelancePage") { page = start } }
        #endif
    }
}

// MARK: - Shared pieces (Card, Label, Field, TextAction, Chip — `internal` on Android)

struct FlCard<Content: View>: View {
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 8) { content() }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(14)
            .settingsSurface(18)
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
        VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: label).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            Text(verbatim: (value?.trimmingCharacters(in: .whitespacesAndNewlines)).flatMap { $0.isEmpty ? nil : $0 } ?? "—")
                .font(.dorr(14)).foregroundStyle(wa.settingsInk)
        }
    }
}

struct FlTextAction: View {
    @Environment(\.wa) private var wa
    let text: String
    var danger = false
    var fill = true
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(14, .bold)).foregroundStyle(danger ? flRed : wa.accent)
                .frame(maxWidth: fill ? .infinity : nil, alignment: .leading)
                .padding(.vertical, 10)
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// The small bold link of the agreement page (`TextLink`).
struct FlTextLink: View {
    @Environment(\.wa) private var wa
    let text: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(wa.accent).padding(.vertical, 4)
        }
        .buttonStyle(.plain)
    }
}

struct FlChip: View {
    @Environment(\.wa) private var wa
    let text: String
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(selected ? .white : wa.settingsInk).lineLimit(1)
                .padding(.horizontal, 14).padding(.vertical, 7)
                .background(selected ? wa.accent : wa.accent.opacity(0.08), in: Capsule())
        }
        .buttonStyle(.plain)
    }
}

/// A notice (green) and an error (red) line under a page's cards.
struct FlNotices: View {
    let notice: String?
    let error: String?

    var body: some View {
        if let notice { Text(verbatim: notice).font(.dorr(13, .bold)).foregroundStyle(flGreen) }
        if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(flRed) }
    }
}

/// A page still loading (spinner) or that failed to (its message).
struct FlLoading: View {
    @Environment(\.wa) private var wa
    let error: String?

    var body: some View {
        ZStack {
            if let error {
                Text(verbatim: error).font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).padding(24)
            } else {
                ProgressView().tint(wa.accent)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
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

@MainActor
private struct ProjectList: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let version: Int
    let onExit: () -> Void
    let onNew: () -> Void
    let onOpen: (String) -> Void
    let onFavorites: () -> Void
    @State private var filter: String?
    @State private var rows: [FreelanceProjectDto] = []
    @State private var page = 1
    @State private var hasMore = false
    @State private var loading = true
    @State private var error: String?

    private func load(_ next: Int) async {
        loading = true
        do {
            let envelope = try await FreelanceApi.projects(status: filter, page: next)
            if next == 1 { rows = [] }
            rows += envelope.data ?? []
            page = next
            hasMore = envelope.pagination?.hasMorePages == true
            error = nil
        } catch is CancellationError {
            return
        } catch {
            self.error = flError(error)
        }
        loading = false
    }

    var body: some View {
        VStack(spacing: 0) {
            SubHeader(title: L("fl_my_projects"), onBack: onExit)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(flFilters, id: \.self) { f in
                        FlChip(text: f.map(flStatusLabel) ?? L("fl_filter_all"), selected: filter == f) { filter = f }
                    }
                }
                .padding(.horizontal, 14).padding(.vertical, 4)
            }
            FlTextAction(text: "♥\u{FE0E}  " + L("fl_favorites"), action: onFavorites).padding(.horizontal, 16)
            ScrollView {
                LazyVStack(spacing: 10) {
                    if !loading && rows.isEmpty && error == nil {
                        VStack(spacing: 0) {
                            PinkIcon(icon: "briefcase.fill", box: 64)
                            Text(L("fl_empty_title")).font(.dorr(16, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 14)
                            Text(L("fl_empty_text")).font(.dorr(13)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).padding(.top, 6)
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.top, 60)
                    }
                    ForEach(rows) { p in ProjectCard(p: p) { onOpen(p.uuid) } }
                    if hasMore {
                        Button { Task { await load(page + 1) } } label: {
                            Text(L("fl_show_more")).font(.dorr(14, .bold)).foregroundStyle(wa.accent).frame(maxWidth: .infinity).padding(10)
                        }
                        .buttonStyle(.plain)
                    }
                    if loading { ProgressView().tint(wa.accent).padding(12) }
                    if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(flRed).frame(maxWidth: .infinity, alignment: .leading) }
                }
                .padding(.horizontal, 16).padding(.vertical, 10)
            }
            SettingsPrimaryButton(text: L("fl_new_project"), action: onNew).padding(16)
        }
        .task(id: "\(filter ?? "")|\(network.reconnectTick)|\(version)") { await load(1) }
    }
}

private struct ProjectCard: View {
    @Environment(\.wa) private var wa
    let p: FreelanceProjectDto
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 6) {
                HStack(spacing: 8) {
                    Text(verbatim: (p.title?.isEmpty == false ? p.title : nil) ?? L("fl_untitled")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Spacer(minLength: 0)
                    FlPill(text: flStatusLabel(p.state), color: flStatusColor(p.state))
                }
                if let s = p.specialty { Text(verbatim: s.name ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                if let b = budgetText(p) { Text(verbatim: b).font(.dorr(13, .semibold)).foregroundStyle(wa.accent) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(14)
            .settingsSurface(18)
        }
        .buttonStyle(.plain)
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
    let onOffers: () -> Void
    let onOpenOffer: (String) -> Void
    let onAgreement: () -> Void
    let onInvitations: () -> Void
    @State private var project: FreelanceProjectDto?
    @State private var busy = false
    @State private var error: String?
    @State private var notice: String?
    @State private var confirm: String?

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
        .dorrDialog(isPresented: Binding(get: { confirm != nil }, set: { if !$0 { confirm = nil } })) {
            if confirm == "cancel" {
                ConfirmDialog(icon: "xmark", title: L("fl_cancel_project"), message: L("fl_cancel_confirm"), loading: busy,
                              onConfirm: { act({ try await FreelanceApi.cancel(uuid, reason: nil).data }, after: { confirm = nil }) },
                              onDismiss: { confirm = nil })
            } else {
                ConfirmDialog(icon: "trash", title: L("fl_delete_draft"), message: L("fl_delete_confirm"), loading: busy,
                              onConfirm: { act({ _ = try await FreelanceApi.delete(uuid); return nil }, after: { confirm = nil; onGone() }) },
                              onDismiss: { confirm = nil })
            }
        }
    }

    @ViewBuilder
    private func content(_ p: FreelanceProjectDto) -> some View {
        let can = p.can ?? FreelanceProjectCanDto()
        let offers = p.offers ?? FreelanceOffersDto()
        let missing = p.missing ?? []
        VStack(alignment: .leading, spacing: 12) {
            FlCard {
                HStack(spacing: 8) {
                    Text(verbatim: (p.title?.isEmpty == false ? p.title : nil) ?? L("fl_untitled")).font(.dorr(17, .heavy)).foregroundStyle(wa.settingsInk)
                    Spacer(minLength: 0)
                    FlPill(text: flStatusLabel(p.state), color: flStatusColor(p.state))
                }
                if let reason = p.statusReason, !reason.isEmpty {
                    Text(verbatim: L("fl_reviewer_note", reason)).font(.dorr(13)).foregroundStyle(flStatusColor(p.state))
                }
                if p.state == "pending_review" { Text(L("fl_under_review_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                if p.state == "rejected" { Text(L("fl_rejected_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            }
            FlCard {
                FlField(label: L("fl_description"), value: p.description)
                FlField(label: L("fl_specialty"), value: p.specialty?.name)
                FlField(label: L("fl_skills"), value: (p.skills ?? []).compactMap(\.name).joined(separator: "، "))
                FlField(label: L("fl_budget"), value: budgetText(p))
                FlField(label: L("fl_duration"), value: p.durationDays.map { L("fl_days", $0) })
                FlField(label: L("fl_visibility"), value: L(p.visibility == "private" ? "fl_private" : "fl_public"))
                if let q = p.questions, !q.isEmpty {
                    FlField(label: L("fl_questions"), value: q.enumerated().map { "\($0.offset + 1). \($0.element)" }.joined(separator: "\n"))
                }
            }
            // FL-401: the agreement with the chosen freelancer.
            if let ag = p.agreement {
                FlCard {
                    HStack {
                        FlLabel(text: L("fl_agreement"))
                        Spacer()
                        Text(verbatim: agreementStatusLabel(ag.status ?? "draft")).font(.dorr(12, .bold)).foregroundStyle(agreementStatusColor(ag.status ?? "draft"))
                    }
                    FlTextAction(text: L(ag.status == "draft" || ag.status == nil ? "fl_ag_open_draft" : "fl_ag_open"), action: onAgreement)
                }
            }
            // FL-205: a private project is filled by invitation.
            if let inv = p.invitations, p.state == "published" {
                FlCard {
                    FlLabel(text: L("fl_inv_title"))
                    Text(verbatim: L("fl_inv_summary", inv.sent ?? 0, inv.accepted ?? 0, inv.declined ?? 0)).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                    FlTextAction(text: L(inv.canInvite == true ? "fl_inv_manage" : "fl_inv_see"), action: onInvitations)
                }
            }
            if p.state == "published" || p.state == "awarded" {
                FlCard {
                    FlLabel(text: L("fl_offers") + ((offers.count ?? 0) > 0 ? " (\(offers.count ?? 0))" : ""))
                    Text(verbatim: offersLine(p, offers)).font(.dorr(13, .semibold))
                        .foregroundStyle(p.state == "awarded" ? Color(hex: 0x7C3AED) : (offers.accepting == true ? flGreen : flAmber))
                    // FL-305 / FL-309: compare them and choose one.
                    if let chosen = offers.chosen { FlTextAction(text: L("fl_see_chosen")) { onOpenOffer(chosen) } }
                    if (offers.count ?? 0) > 0 { FlTextAction(text: L(p.state == "published" ? "fl_compare_choose" : "fl_see_offers"), action: onOffers) }
                    if offers.canClose == true { FlTextAction(text: L("fl_offers_close")) { act({ try await FreelanceApi.closeOffers(p.uuid).data }) } }
                    if offers.canReopen == true {
                        FlTextAction(text: L("fl_offers_reopen", offers.reopensLeft ?? 0)) { act({ try await FreelanceApi.reopenOffers(p.uuid).data }) }
                    }
                }
            }
            if let files = p.attachments, !files.isEmpty {
                FlCard {
                    FlLabel(text: L("fl_files"))
                    ForEach(files, id: \.id) { a in
                        HStack(spacing: 8) {
                            Image(systemName: "doc.text").font(.system(size: 15)).foregroundStyle(wa.accent)
                            Text(verbatim: a.name ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        }
                    }
                }
            }
            if !missing.isEmpty {
                Text(verbatim: L("fl_missing", missing.map(missingLabel).joined(separator: "، "))).font(.dorr(13)).foregroundStyle(flAmber)
            }
            FlNotices(notice: notice, error: error)
            if can.submit == true {
                SettingsPrimaryButton(text: L(p.state == "changes_requested" || p.state == "rejected" ? "fl_resubmit" : "fl_submit"),
                                      enabled: missing.isEmpty, loading: busy) {
                    act({ try await FreelanceApi.submit(p.uuid).data }, successNotice: L("fl_sent_for_review"))
                }
            }
            if can.edit == true { FlTextAction(text: L("fl_edit")) { onEdit(p.uuid) } }
            if can.withdraw == true { FlTextAction(text: L("fl_withdraw")) { act({ try await FreelanceApi.withdraw(p.uuid).data }) } }
            if can.cancel == true { FlTextAction(text: L("fl_cancel_project"), danger: true) { confirm = "cancel" } }
            if can.delete == true { FlTextAction(text: L("fl_delete_draft"), danger: true) { confirm = "delete" } }
            if let events = p.timeline, !events.isEmpty {
                FlCard {
                    FlLabel(text: L("fl_history"))
                    ForEach(Array(events.enumerated()), id: \.offset) { _, e in
                        Text(verbatim: "\(eventLabel(e.type ?? ""))  ·  \u{200E}\(String((e.at ?? "").prefix(16)).replacingOccurrences(of: "T", with: " "))")
                            .font(.dorr(12)).foregroundStyle(wa.settingsMut)
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
        var min: Int?
        if !budgetMin.trimmingCharacters(in: .whitespaces).isEmpty {
            guard let v = flParseMoney(budgetMin) else { return nil }
            min = v
        }
        var max: Int?
        if budgetType == "range", !budgetMax.trimmingCharacters(in: .whitespaces).isEmpty {
            guard let v = flParseMoney(budgetMax) else { return nil }
            max = v
        }
        func clean(_ s: String) -> String? { let t = s.trimmingCharacters(in: .whitespacesAndNewlines); return t.isEmpty ? nil : t }
        return FreelanceProjectRequest(title: clean(title), description: clean(description), specialtyId: specialty?.id, skillIds: skills.map(\.id),
                                       budgetType: budgetType, budgetMinMinor: min, budgetMaxMinor: max, durationDays: Int(duration),
                                       visibility: visibility, questions: questions.compactMap(clean))
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
                ScrollView { form.padding(.horizontal, 16) }
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
        .dorrDialog(isPresented: $pickSpecialty) {
            SpecialtyDialog(onDismiss: { pickSpecialty = false }) { picked in
                specialty = picked
                pickSpecialty = false
            }
        }
    }

    private var form: some View {
        VStack(alignment: .leading, spacing: 12) {
            FlCard {
                DorrTextArea(value: limited($title, 120), label: L("fl_title"), placeholder: L("fl_title_hint"), singleLine: true)
                DorrTextArea(value: limited($description, 5000), label: L("fl_description"), placeholder: L("fl_description_hint"), minHeight: 130)
            }
            FlCard {
                FlLabel(text: L("fl_specialty"))
                Button { pickSpecialty = true } label: {
                    Text(verbatim: specialty?.name ?? L("fl_pick_specialty")).font(.dorr(15))
                        .foregroundStyle(specialty == nil ? (wa.night ? wa.mut : Color(hex: 0xB4BAC4)) : wa.settingsInk)
                        .frame(maxWidth: .infinity, minHeight: 46, alignment: .leading)
                        .padding(.horizontal, 14)
                        .background(wa.night ? wa.bg : Color(hex: 0xFBF7F8), in: Capsule())
                        .overlay(Capsule().stroke(wa.night ? wa.line : Color(hex: 0xF3D5DB), lineWidth: 1))
                }
                .buttonStyle(.plain)
                FlLabel(text: L("fl_skills"))
                SkillPicker(selected: $skills)
            }
            FlCard {
                FlLabel(text: L("fl_budget"))
                HStack(spacing: 8) {
                    FlChip(text: L("fl_budget_fixed"), selected: budgetType == "fixed") { budgetType = "fixed" }
                    FlChip(text: L("fl_budget_range"), selected: budgetType == "range") { budgetType = "range" }
                }
                let currency = rules?.currency ?? ""
                HStack(alignment: .top, spacing: 10) {
                    DorrTextArea(value: $budgetMin, label: L(budgetType == "range" ? "fl_budget_from" : "fl_budget_amount", currency), singleLine: true)
                        .keyboardType(.decimalPad)
                    if budgetType == "range" {
                        DorrTextArea(value: $budgetMax, label: L("fl_budget_to", currency), singleLine: true).keyboardType(.decimalPad)
                    }
                }
                if let r = rules {
                    let lines = [r.minMinor.map { L("fl_budget_min_rule", flMoney($0), r.currency ?? "") },
                                 r.maxMinor.map { L("fl_budget_max_rule", flMoney($0), r.currency ?? "") }].compactMap { $0 }
                    if !lines.isEmpty { Text(verbatim: "\u{200E}" + lines.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                }
            }
            FlCard {
                DorrTextArea(value: Binding(get: { duration }, set: { duration = String($0.filter(\.isNumber).prefix(3)) }),
                             label: L("fl_duration_days"), singleLine: true)
                    .keyboardType(.numberPad)
                FlLabel(text: L("fl_visibility"))
                HStack(spacing: 8) {
                    FlChip(text: L("fl_public"), selected: visibility == "public") { visibility = "public" }
                    FlChip(text: L("fl_private"), selected: visibility == "private") { visibility = "private" }
                }
                Text(L(visibility == "private" ? "fl_private_hint" : "fl_public_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            // FL-307: questions every freelancer answers with their offer.
            FlCard {
                FlLabel(text: L("fl_questions"))
                Text(L("fl_questions_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                ForEach(questions.indices, id: \.self) { i in
                    HStack(spacing: 6) {
                        DorrTextArea(value: Binding(get: { questions.indices.contains(i) ? questions[i] : "" },
                                                    set: { if questions.indices.contains(i) { questions[i] = String($0.prefix(300)) } }),
                                     placeholder: L("fl_question_n", i + 1), singleLine: true)
                        RemoveButton { questions.remove(at: i) }
                    }
                }
                if questions.count < 5 { FlTextAction(text: L("fl_add_question")) { questions.append("") } }
            }
            FlCard {
                FlLabel(text: L("fl_files"))
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
                }
                Button { picking = true } label: {
                    HStack(spacing: 6) {
                        if uploading { ProgressView().tint(wa.accent).scaleEffect(0.8) } else {
                            Image(systemName: "paperclip").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.accent)
                        }
                        Text(L("fl_add_file")).font(.dorr(13, .bold)).foregroundStyle(wa.accent)
                    }
                    .padding(.vertical, 6)
                }
                .buttonStyle(.plain)
                .disabled(uploading)
                Text(L("fl_files_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
            if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(flRed) }
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

/// Picked skills as removable pills, and a search over the active catalog (400 ms after the last key).
@MainActor
private struct SkillPicker: View {
    @Environment(\.wa) private var wa
    @Binding var selected: [FreelanceNamedDto]
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
                DorrTextArea(value: $query, placeholder: L("fl_search_skills"), singleLine: true)
                FlowLayout(spacing: 6) {
                    ForEach(results.filter { r in !selected.contains { $0.id == r.id } }.prefix(12), id: \.id) { r in
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
        .task(id: query) {
            let q = query.trimmingCharacters(in: .whitespaces)
            guard q.count >= 2 else { results = []; return }
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
    let onDismiss: () -> Void
    let onPick: (FreelanceNamedDto) -> Void
    @State private var tree: [FreelanceSpecialtyNodeDto]?

    var body: some View {
        FlAlert(title: L("fl_pick_specialty"), content: {
            if let nodes = tree {
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) {
                        ForEach(nodes, id: \.id) { parent in
                            if (parent.children ?? []).isEmpty {
                                row(parent.name ?? "", bold: true) { onPick(FreelanceNamedDto(id: parent.id, name: parent.name)) }
                            } else {
                                Text(verbatim: parent.name ?? "").font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut).padding(.top, 10).padding(.bottom, 2)
                                ForEach(parent.children ?? [], id: \.id) { child in
                                    row(child.name ?? "", bold: false) { onPick(FreelanceNamedDto(id: child.id, name: child.name)) }
                                }
                            }
                        }
                    }
                }
                .frame(maxHeight: 420)
            } else {
                ProgressView().tint(wa.accent).frame(maxWidth: .infinity).padding(20)
            }
        }, confirm: nil, onConfirm: {}, dismiss: L("fl_close"), onDismiss: onDismiss)
        .task { tree = (try? await FreelanceApi.specialties().data) ?? [] }
    }

    private func row(_ text: String, bold: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(14, bold ? .bold : .regular)).foregroundStyle(wa.settingsInk)
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.vertical, 10).padding(.horizontal, 6)
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}
