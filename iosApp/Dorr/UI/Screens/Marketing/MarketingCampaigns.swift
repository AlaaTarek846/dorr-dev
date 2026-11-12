import SwiftUI
import UIKit

/// androidApp `ui/screens/marketing/MarketingCampaigns.kt` — phase C: the ad platforms (U10), campaigns (U09) with the
/// spend delegations (D7), and one campaign's steps up to publishing it PAUSED on the platform.

private let mkObjectives = ["awareness", "traffic", "engagement", "leads", "sales"]

private func mkObjectiveLabel(_ o: String) -> String {
    ["awareness", "engagement", "leads", "sales"].contains(o) ? L("mktg_obj_\(o)") : L("mktg_obj_traffic")
}

private func mkPlatformLabel(_ p: String) -> String {
    ["google", "tiktok", "snapchat"].contains(p) ? L("mktg_pf_\(p)") : L("mktg_pf_meta")
}

private let campaignStatus: [String: (String, UInt32)] = [
    "DRAFT": ("mktg_cmp_DRAFT", 0x6B7280),
    "VALIDATED": ("mktg_cmp_VALIDATED", 0x2563EB),
    "AWAITING_APPROVAL": ("mktg_cmp_AWAITING_APPROVAL", 0xD97706),
    "APPROVED": ("mktg_cmp_APPROVED", 0x16A34A),
    "SUBMITTING": ("mktg_cmp_SUBMITTING", 0x2563EB),
    "PUBLISHED": ("mktg_cmp_PUBLISHED", 0x16A34A),
    "REJECTED": ("mktg_cmp_REJECTED", 0xDC2626),
    "FAILED": ("mktg_cmp_FAILED", 0xDC2626),
    "UNKNOWN": ("mktg_cmp_UNKNOWN", 0xD97706),
]

private struct MkCampaignStatus: View {
    let status: String

    var body: some View {
        let entry = campaignStatus[status] ?? ("mktg_cmp_DRAFT", 0x6B7280)
        MkBadge(text: L(entry.0), color: Color(hex: entry.1))
    }
}

/// A validation issue as a sentence (some carry a detail: the currency, the most days).
private func mkIssueLabel(_ issue: MkIssueDto) -> String {
    let key: String
    switch issue.code {
    case "platform_off", "integration_expired", "currency_mismatch", "budget_too_low", "start_in_past", "bad_dates",
         "no_audience", "content_not_approved":
        key = "mktg_issue_\(issue.code)"
    case "content_has_blocking_issues":
        key = "mktg_issue_content_blocking"
    default:
        key = "mktg_issue_integration_not_connected"
    }
    return L(key, issue.detail ?? "")
}

/// A day at noon (so a time zone shift never moves it).
private func mkNoon(_ date: Date) -> Date {
    calCalendar.date(bySettingHour: 12, minute: 0, second: 0, of: date) ?? date
}

private func mkAtHour(_ day: Date, _ hour: Int) -> Date {
    calCalendar.date(bySettingHour: hour, minute: 0, second: 0, of: day) ?? day
}

// MARK: - ad platforms (U10)

/// Connecting ad accounts: Meta now (Google, TikTok and Snapchat shown as coming). The owner connects; the platform's
/// own page opens in the browser, and the screen reloads when the server says the connection changed
/// (`marketing.integration.changed`) — never on a timer.
struct MarketingPlatforms: View {
    @EnvironmentObject private var host: MkHost
    @ObservedObject private var net = NetworkMonitor.shared
    @Environment(\.wa) private var wa
    let projectId: String

    @State private var project: MkProjectDto?
    @State private var data: MkIntegrationsDto?
    @State private var reload = 0
    @State private var busy: String?

    var body: some View {
        let owner = project?.abilities?.manageTeam == true
        MkPage(title: L("mktg_platforms")) {
            WaNote(text: L("mktg_platforms_note"))
            if let d = data {
                VStack(spacing: 12) {
                    ForEach(Array(d.platforms.enumerated()), id: \.offset) { i, pf in
                        platformCard(pf, integration: d.integrations.first { $0.provider == pf.provider && $0.status != "REVOKED" }, owner: owner)
                            .waRise(i)
                    }
                }
                .padding(.top, 12)
                if !owner {
                    Text(verbatim: L("mktg_owner_connects")).font(.dorr(12.5)).foregroundStyle(wa.mut).padding(.horizontal, 4).padding(.top, 10)
                }
            } else {
                VStack(spacing: 12) { ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 90) } }.padding(.top, 12)
            }
        }
        .task(id: "\(projectId)|\(reload)|\(net.reconnectTick)|\(host.projectChanges(projectId))") { await load() }
    }

    private func platformCard(_ pf: MkPlatformDto, integration: MkIntegrationDto?, owner: Bool) -> some View {
        let sub: String
        if pf.comingSoon {
            sub = L("mktg_coming_soon")
        } else if !pf.available {
            sub = L("mktg_platform_off_here")
        } else if !pf.configured {
            sub = L("mktg_platform_not_ready")
        } else if let account = integration?.account {
            sub = account.name ?? account.id
        } else {
            sub = L("mktg_platform_not_connected")
        }
        return WaCard(padding: 16) {
            HStack(spacing: 12) {
                WaIconWell(icon: "network", tone: pf.available ? .blue : .gray, size: 42, iconSize: 21)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: mkPlatformLabel(pf.provider)).font(.dorr(15, .heavy)).foregroundStyle(wa.ink)
                    Text(verbatim: sub).font(.dorr(12.5)).foregroundStyle(wa.mut).lineLimit(2)
                }
                Spacer(minLength: 6)
                if integration?.status == "CONNECTED" {
                    MkBadge(text: L("mktg_connected"), color: wa.green)
                } else if integration?.reconnectNeeded == true {
                    MkBadge(text: L("mktg_reconnect_needed"), color: wa.danger)
                } else if integration?.status == "PENDING" && integration?.availableAccounts != nil {
                    MkBadge(text: L("mktg_choose_account"), color: mkAmber)
                }
            }
            if integration?.sandbox == true {
                MkSmallTag(text: L("mktg_sandbox"), tint: mkAmber).padding(.top, 8)
            }
            // OAuth found several ad accounts: the owner picks one.
            if let integration, integration.status == "PENDING", let choices = integration.availableAccounts, owner {
                accountChoices(integration, choices).padding(.top, 10)
            }
            if owner && pf.available && pf.configured && !pf.comingSoon {
                HStack(spacing: 8) {
                    if integration?.status != "CONNECTED" {
                        WaButton(text: L(integration?.reconnectNeeded == true ? "mktg_reconnect" : "mktg_connect"), loading: busy == pf.provider, compact: true) {
                            Task { await connect(pf.provider) }
                        }
                    }
                    if let integration, integration.status != "REVOKED" {
                        WaButton(text: L("mktg_disconnect"), style: .quiet, icon: "xmark.circle", compact: true) { disconnect(integration) }
                    }
                }
                .padding(.top, 12)
            }
        }
    }

    @ViewBuilder
    private func accountChoices(_ integration: MkIntegrationDto, _ choices: [MkAdAccountDto]) -> some View {
        if choices.isEmpty {
            Text(verbatim: L("mktg_no_ad_accounts")).font(.dorr(12.5)).foregroundStyle(wa.danger)
        } else {
            VStack(alignment: .leading, spacing: 6) {
                Text(verbatim: L("mktg_choose_account")).font(.dorr(12.5, .bold)).foregroundStyle(wa.mut)
                ForEach(Array(choices.enumerated()), id: \.offset) { _, acc in
                    WaButton(text: (acc.name ?? acc.id) + (acc.currency.map { " · \($0)" } ?? ""), style: .ghost, loading: busy == acc.id, compact: true) {
                        Task { await choose(integration, acc) }
                    }
                }
            }
        }
    }

    private func load() async {
        do {
            if project == nil { project = try await MarketingApi.project(projectId).data }
            data = try await MarketingApi.integrations(projectId).data
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
    }

    private func connect(_ provider: String) async {
        busy = provider
        do {
            let r = try await MarketingApi.connectPlatform(projectId, provider: provider).data
            if let link = r?.authorizeUrl, let url = URL(string: link) {
                // The platform's own sign-in page; the server's `marketing.integration.changed` brings us back to a reload.
                host.showToast(L("mktg_platform_continue"))
                _ = await UIApplication.shared.open(url)
            } else {
                host.showToast(L("mktg_platform_connected"))
            }
            reload += 1
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = nil
    }

    private func choose(_ integration: MkIntegrationDto, _ acc: MkAdAccountDto) async {
        busy = acc.id
        do {
            _ = try await MarketingApi.chooseAdAccount(integration.id, MkAccountRequest(accountId: acc.id))
            host.showToast(L("mktg_platform_connected"))
            reload += 1
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = nil
    }

    private func disconnect(_ integration: MkIntegrationDto) {
        host.confirm(WeDialogSpec(title: L("mktg_disconnect"), text: L("mktg_disconnect_text"), confirm: L("mktg_disconnect"), danger: true) {
            Task {
                do {
                    _ = try await MarketingApi.disconnectPlatform(integration.id)
                    reload += 1
                } catch {
                    host.fail(error, L("mktg_error_generic"))
                }
            }
        })
    }
}

// MARK: - campaigns (U09) + delegations (D7)

struct MarketingCampaigns: View {
    @EnvironmentObject private var host: MkHost
    @ObservedObject private var net = NetworkMonitor.shared
    @Environment(\.wa) private var wa
    let projectId: String

    @State private var project: MkProjectDto?
    @State private var campaigns: [MkCampaignDto]?
    @State private var delegations: [MkDelegationDto] = []
    @State private var reload = 0
    @State private var creating = false
    @State private var delegating = false

    var body: some View {
        let abilities = project?.abilities
        let currency = project?.currency ?? ""
        MkPage(title: L("mktg_campaigns")) {
            WaNote(text: L("mktg_campaigns_note"))
            if abilities?.edit == true {
                WaButton(text: L("mktg_campaign_new"), icon: "plus", compact: true) { creating = true }.padding(.top, 12)
            }
            campaignList.padding(.top, 12)
            delegationList(abilities, currency)
        }
        .task(id: "\(projectId)|\(reload)|\(net.reconnectTick)|\(host.projectChanges(projectId))") { await load() }
        .weSheet(creating, onDismiss: { creating = false }) {
            MkCampaignSheet(projectId: projectId, existing: nil, currency: currency) { c in
                creating = false
                reload += 1
                host.push(.campaign(projectId: projectId, campaignId: c.id))
            }
        }
        .weSheet(delegating, onDismiss: { delegating = false }) {
            MkDelegationSheet(projectId: projectId, currency: currency) {
                delegating = false
                reload += 1
            }
        }
    }

    @ViewBuilder
    private var campaignList: some View {
        if let list = campaigns {
            if list.isEmpty {
                WaEmpty(icon: "megaphone", tone: .gray, title: L("mktg_campaigns_empty"), text: L("mktg_campaigns_empty_sub"))
            } else {
                VStack(spacing: 10) {
                    ForEach(Array(list.enumerated()), id: \.element.id) { i, c in
                        MkNavCard(icon: "megaphone.fill", tone: .pink, title: c.name,
                                  subtitle: mkMoney(c.totalBudgetMinor, c.currency) + " · " + mkObjectiveLabel(c.objective), trailing: {
                            MkCampaignStatus(status: c.status)
                        }) { host.push(.campaign(projectId: projectId, campaignId: c.id)) }
                        .waRise(i)
                    }
                }
            }
        } else {
            VStack(spacing: 12) { ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 84) } }
        }
    }

    /// Delegations: the owner sets them; everyone sees them.
    @ViewBuilder
    private func delegationList(_ abilities: MkAbilitiesDto?, _ currency: String) -> some View {
        let owner = abilities?.manageTeam == true
        WaSectionTitle(title: L("mktg_delegations")) {
            if owner {
                Button { delegating = true } label: {
                    Text(verbatim: L("mktg_add")).font(.dorr(13, .heavy)).foregroundStyle(wa.red).padding(6)
                }
                .buttonStyle(WaPressStyle(scale: 0.94))
            }
        }
        Text(verbatim: L("mktg_delegations_note")).font(.dorr(12.5)).foregroundStyle(wa.mut).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
            .padding(.horizontal, 4).padding(.bottom, 10)
        if delegations.isEmpty {
            Text(verbatim: L("mktg_no_delegations")).font(.dorr(12.5)).foregroundStyle(wa.soft).padding(.horizontal, 4)
        } else {
            VStack(spacing: 10) {
                ForEach(delegations) { d in delegationCard(d, owner: owner, currency: currency) }
            }
        }
    }

    private func delegationCard(_ d: MkDelegationDto, owner: Bool, currency: String) -> some View {
        let money = d.currency ?? currency
        let label = d.status == "active" ? "mktg_active" : (d.status == "expired" ? "mktg_expired" : "mktg_revoked")
        return WaCard(padding: 14) {
            HStack(spacing: 10) {
                WaIconWell(icon: "person.badge.shield.checkmark", tone: .amber, size: 38, iconSize: 19)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: d.name ?? "—").font(.dorr(14, .heavy)).foregroundStyle(wa.ink)
                    Text(verbatim: L("mktg_until", mkDate(d.expiresAt))).font(.dorr(12)).foregroundStyle(wa.mut)
                }
                Spacer(minLength: 6)
                MkBadge(text: L(label), color: d.status == "active" ? wa.green : wa.mut)
            }
            MkBar(fraction: d.capMinor > 0 ? Double(d.usedMinor) / Double(d.capMinor) : 0, color: mkAmber).padding(.top, 10)
            Text(verbatim: L("mktg_delegation_left", mkMoney(d.remainingMinor, money), mkMoney(d.capMinor, money))).font(.dorr(12)).foregroundStyle(wa.mut).padding(.top, 6)
            if owner && d.status == "active" {
                WaButton(text: L("mktg_revoke"), style: .quiet, icon: "lock", compact: true) { revoke(d) }
                    .fixedSize()
                    .padding(.top, 8)
            }
        }
    }

    private func load() async {
        do {
            if project == nil { project = try await MarketingApi.project(projectId).data }
            campaigns = try await MarketingApi.campaigns(projectId).data ?? []
            delegations = try await MarketingApi.delegations(projectId).data ?? []
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
    }

    private func revoke(_ d: MkDelegationDto) {
        host.confirm(WeDialogSpec(title: L("mktg_revoke"), text: L("mktg_revoke_text"), confirm: L("mktg_revoke"), danger: true) {
            Task {
                do {
                    _ = try await MarketingApi.revokeDelegation(d.id)
                    reload += 1
                } catch {
                    host.fail(error, L("mktg_error_generic"))
                }
            }
        })
    }
}

// MARK: - one campaign

/// One campaign and its steps: validate → ask for approval → approve (owner / delegated manager) → publish (one
/// Idempotency-Key per attempt, reused on retry) → created PAUSED on the platform. An unknown result is checked,
/// never re-sent blindly.
struct MarketingCampaignPage: View {
    @EnvironmentObject private var host: MkHost
    @ObservedObject private var net = NetworkMonitor.shared
    @Environment(\.wa) private var wa
    let projectId: String
    let campaignId: String

    @State private var project: MkProjectDto?
    @State private var campaign: MkCampaignDto?
    @State private var metrics: MkCampaignMetricsDto?
    @State private var issues: [MkIssueDto] = []
    @State private var busy: String?
    @State private var editing = false
    @State private var returning = false
    @State private var reload = 0
    /// One key per publish attempt of one version: a retry after a lost answer reuses it (AT-14).
    @State private var keys: [Int: String] = [:]

    var body: some View {
        MkPage(title: campaign?.name ?? L("mktg_campaigns")) {
            if let c = campaign {
                content(c)
            } else {
                VStack(spacing: 12) { ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 96) } }
            }
        }
        .task(id: "\(campaignId)|\(reload)|\(net.reconnectTick)|\(host.projectChanges(projectId))") { await load() }
        .weSheet(editing, onDismiss: { editing = false }) {
            if let c = campaign {
                MkCampaignSheet(projectId: projectId, existing: c, currency: c.currency) { updated in
                    editing = false
                    campaign = updated
                    issues = []
                    host.showToast(L("mktg_saved"))
                }
            }
        }
        .weSheet(returning, onDismiss: { returning = false }) {
            MkNoteSheet(title: L("mktg_return")) { note in
                returning = false
                guard let c = campaign else { return }
                act("reject") { try await MarketingApi.rejectCampaign(c.id, MkNoteRequest(note: note.isEmpty ? nil : note)).data }
            }
        }
    }

    @ViewBuilder
    private func content(_ c: MkCampaignDto) -> some View {
        header(c).waRise(0)
        if let a = c.approval {
            HStack(spacing: 6) {
                Image(systemName: "checkmark.seal.fill").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.green)
                Text(verbatim: L(a.byDelegation ? "mktg_approved_by_delegation" : "mktg_approved_cap", mkMoney(a.capMinor, c.currency), mkDate(a.expiresAt)))
                    .font(.dorr(12.5, .bold)).foregroundStyle(wa.green).fixedSize(horizontal: false, vertical: true)
            }
            .padding(.horizontal, 4)
            .padding(.top, 10)
        }
        if let error = mkNonBlank(c.lastError), ["FAILED", "REJECTED", "UNKNOWN", "APPROVED", "PUBLISHED"].contains(c.status) {
            WaNote(text: error == "integration_expired" ? L("mktg_issue_integration_expired") : (error == "platform_unreachable" ? L("mktg_platform_no_answer") : error))
                .padding(.top, 10)
        }
        if !issues.isEmpty && c.status == "DRAFT" {
            WaSectionTitle(title: L("mktg_fix_before_approval"))
            WaCard(padding: 14) {
                VStack(alignment: .leading, spacing: 8) {
                    ForEach(Array(issues.enumerated()), id: \.offset) { _, issue in
                        HStack(alignment: .top, spacing: 8) {
                            Image(systemName: "exclamationmark.triangle.fill").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.danger).padding(.top, 2)
                            Text(verbatim: mkIssueLabel(issue)).font(.dorr(13)).foregroundStyle(wa.ink).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
                            Spacer(minLength: 0)
                        }
                    }
                }
            }
        }
        steps(c).padding(.top, 16)
    }

    private func header(_ c: MkCampaignDto) -> some View {
        let countries = c.targeting?.strings("countries") ?? []
        let ageMin = c.targeting?.number("age_min")
        let ageMax = c.targeting?.number("age_max")
        var audience = countries.joined(separator: ", ")
        if let ageMin, let ageMax { audience += " · \(ageMin)–\(ageMax)" }
        return WaCard(padding: 16) {
            HStack(spacing: 10) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: c.name).font(.dorr(16, .heavy)).foregroundStyle(wa.ink).fixedSize(horizontal: false, vertical: true)
                    Text(verbatim: mkPlatformLabel(c.provider) + " · " + mkObjectiveLabel(c.objective) + " · v\(c.version)").font(.dorr(12.5)).foregroundStyle(wa.mut)
                }
                Spacer(minLength: 6)
                MkCampaignStatus(status: c.status)
            }
            HStack(alignment: .top, spacing: 14) {
                fact(L("mktg_daily_budget"), mkMoney(c.dailyBudgetMinor, c.currency))
                fact(L("mktg_total_budget"), mkMoney(c.totalBudgetMinor, c.currency))
            }
            .padding(.top, 14)
            Text(verbatim: mkDate(c.startAt) + " → " + mkDate(c.endAt)).font(.dorr(13, .bold)).foregroundStyle(wa.ink).padding(.top, 10)
            if !audience.isEmpty {
                Text(verbatim: "\u{2066}\(audience)\u{2069}").font(.dorr(12.5)).foregroundStyle(wa.mut)
            }
        }
    }

    private func fact(_ label: String, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(verbatim: label).font(.dorr(11.5)).foregroundStyle(wa.mut)
            Text(verbatim: "\u{2066}\(value)\u{2069}").font(.dorr(15, .black)).foregroundStyle(wa.ink).lineLimit(1).minimumScaleFactor(0.7)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    @ViewBuilder
    private func steps(_ c: MkCampaignDto) -> some View {
        let abilities = project?.abilities
        let canEdit = abilities?.edit == true
        VStack(alignment: .leading, spacing: 10) {
            switch c.status {
            case "DRAFT", "REJECTED", "FAILED":
                if canEdit {
                    WaButton(text: L("mktg_check_campaign"), icon: "checkmark.circle", loading: busy == "validate") {
                        act("validate") {
                            let checked = try await MarketingApi.validateCampaign(c.id).data
                            issues = []
                            return checked
                        }
                    }
                }
            case "VALIDATED":
                if canEdit {
                    WaButton(text: L("mktg_ask_approval"), icon: "paperplane.fill", loading: busy == "request") {
                        act("request") { try await MarketingApi.requestCampaignApproval(c.id).data }
                    }
                }
            case "AWAITING_APPROVAL":
                if abilities?.approve == true {
                    WaButton(text: L("mktg_approve_spend", mkMoney(c.totalBudgetMinor, c.currency)), icon: "checkmark.seal", loading: busy == "approve") {
                        act("approve") { try await MarketingApi.approveCampaign(c.id, MkCampaignVersionRequest(version: c.version)).data }
                    }
                    WaButton(text: L("mktg_return"), style: .ghost, icon: "arrow.uturn.backward") { returning = true }
                    Text(verbatim: L("mktg_spend_who")).font(.dorr(11.5)).foregroundStyle(wa.soft).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
                } else {
                    WaNote(text: L("mktg_waiting_spend_approval"))
                }
            case "APPROVED":
                if canEdit {
                    WaButton(text: L("mktg_publish"), icon: "paperplane.circle.fill", loading: busy == "publish") { confirmPublish(c) }
                    Text(verbatim: L("mktg_publish_paused_note")).font(.dorr(12)).foregroundStyle(wa.mut).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
                }
            case "SUBMITTING":
                WaNote(text: L("mktg_publishing"))
            case "UNKNOWN":
                WaNote(text: L("mktg_unknown_note"))
                if canEdit {
                    WaButton(text: L("mktg_check_status"), icon: "arrow.clockwise", loading: busy == "reconcile") {
                        act("reconcile") { try await MarketingApi.reconcileCampaign(c.id).data }
                    }
                }
            case "PUBLISHED":
                published(c, canSync: abilities?.results == true)
            default:
                EmptyView()
            }
            if canEdit && !["SUBMITTING", "PUBLISHED", "UNKNOWN"].contains(c.status) {
                HStack(spacing: 10) {
                    WaButton(text: L("mktg_edit"), style: .quiet, icon: "pencil", compact: true) { editing = true }
                    WaButton(text: L("mktg_delete"), style: .quiet, icon: "trash", compact: true) { confirmDelete(c) }
                }
                if c.approval != nil {
                    Text(verbatim: L("mktg_edit_drops_approval")).font(.dorr(11.5)).foregroundStyle(wa.soft).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
                }
            }
        }
    }

    @ViewBuilder
    private func published(_ c: MkCampaignDto, canSync: Bool) -> some View {
        WaCard(padding: 14) {
            Text(verbatim: L("mktg_on_platform", c.platformStatus ?? "PAUSED")).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink)
            Text(verbatim: "\u{2066}\(c.platformRef ?? "")\u{2069}").font(.dorr(12)).foregroundStyle(wa.soft)
            Text(verbatim: L("mktg_start_on_platform")).font(.dorr(12.5)).foregroundStyle(wa.mut).lineSpacing(3).fixedSize(horizontal: false, vertical: true).padding(.top, 6)
        }
        HStack(spacing: 10) {
            WaButton(text: L("mktg_check_status"), style: .ghost, icon: "arrow.clockwise", loading: busy == "reconcile", compact: true) {
                act("reconcile") { try await MarketingApi.reconcileCampaign(c.id).data }
            }
            if canSync {
                WaButton(text: L("mktg_sync_results"), style: .ghost, icon: "arrow.triangle.2.circlepath", loading: busy == "sync", compact: true) {
                    Task { await sync(c) }
                }
            }
        }
        if let m = metrics, !m.rows.isEmpty {
            WaSectionTitle(title: L("mktg_platform_results")) { MkSmallTag(text: L("mktg_basis_actual"), tint: wa.green) }
            WaCard(padding: 6) {
                VStack(spacing: 0) {
                    ForEach(Array(m.rows.prefix(14).enumerated()), id: \.offset) { i, r in
                        if i > 0 { WaDivider() }
                        HStack {
                            Text(verbatim: r.date).font(.dorr(12.5, .bold)).foregroundStyle(wa.ink)
                            Spacer(minLength: 6)
                            Text(verbatim: "\u{2066}\(mkMoney(r.spendMinor, "")) · \(r.clicks ?? 0) \(L("mktg_k_clicks"))\u{2069}").font(.dorr(12)).foregroundStyle(wa.mut)
                        }
                        .padding(10)
                    }
                }
            }
            if let synced = m.syncedAt {
                Text(verbatim: L("mktg_updated", mkDateTime(synced))).font(.dorr(11.5)).foregroundStyle(wa.soft).padding(4)
            }
        }
    }

    private func load() async {
        do {
            if project == nil { project = try await MarketingApi.project(projectId).data }
            let c = try await MarketingApi.campaign(campaignId).data
            campaign = c
            if let c, !c.validation.isEmpty { issues = c.validation }
            if c?.status == "PUBLISHED" { metrics = try await MarketingApi.campaignMetrics(campaignId).data }
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
    }

    private func act(_ key: String, _ call: @escaping () async throws -> MkCampaignDto?) {
        Task {
            busy = key
            do {
                if let c = try await call() { campaign = c }
            } catch {
                let f = host.fail(error, L("mktg_error_generic"))
                if f.errorCode == "marketing_campaign_invalid" {
                    issues = (f.data?["issues"]?.arrayValue ?? []).compactMap { el -> MkIssueDto? in
                        guard case .object = el else { return nil }
                        return MkIssueDto(code: el["code"]?.str ?? "", detail: el["detail"]?.str)
                    }
                }
                reload += 1
            }
            busy = nil
        }
    }

    private func sync(_ c: MkCampaignDto) async {
        busy = "sync"
        do {
            _ = try await MarketingApi.syncCampaignMetrics(c.id)
            host.showToast(L("mktg_saved"))
            reload += 1
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = nil
    }

    private func confirmPublish(_ c: MkCampaignDto) {
        guard c.approval != nil else { return }
        host.confirm(WeDialogSpec(
            title: L("mktg_publish"),
            text: L("mktg_publish_confirm", mkMoney(c.totalBudgetMinor, c.currency), mkPlatformLabel(c.provider)),
            confirm: L("mktg_publish")
        ) { publish(c) })
    }

    private func publish(_ c: MkCampaignDto) {
        guard let approval = c.approval else { return }
        let key = keys[c.version] ?? "mk-" + UUID().uuidString
        keys[c.version] = key
        busy = "publish"
        Task {
            do {
                let r = try await MarketingApi.publishCampaign(key: key, c.id, MkPublishRequest(
                    approvalId: approval.id, campaignVersion: c.version, expectedBudgetMinor: c.totalBudgetMinor, currency: c.currency
                )).data
                if let updated = r?.campaign { campaign = updated }
                // A finished attempt (success, refusal, unknown) frees the key; only a lost request keeps it for the retry.
                keys[c.version] = nil
                switch r?.action?.status {
                case "SUCCEEDED": host.showToast(L("mktg_published_paused"))
                case "UNKNOWN": host.showToast(L("mktg_unknown_note"), error: true)
                default: host.showToast(L("mktg_publish_refused"), error: true)
                }
            } catch {
                host.fail(error, L("mktg_error_generic"))
                reload += 1
            }
            busy = nil
        }
    }

    private func confirmDelete(_ c: MkCampaignDto) {
        host.confirm(WeDialogSpec(title: L("mktg_delete"), text: c.name, confirm: L("mktg_delete"), danger: true) {
            Task {
                do {
                    _ = try await MarketingApi.deleteCampaign(c.id)
                    host.changed(projectId)
                    host.pop()
                } catch {
                    host.fail(error, L("mktg_error_generic"))
                }
            }
        })
    }
}

/// New campaign, or a change (a new version).
private struct MkCampaignSheet: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let projectId: String
    let existing: MkCampaignDto?
    let currency: String
    let onSaved: (MkCampaignDto) -> Void

    @State private var name: String
    @State private var objective: String
    @State private var integration: String?
    @State private var content: String?
    @State private var daily: String
    @State private var start: Date
    @State private var end: Date
    @State private var countries: String
    @State private var ageMin: String
    @State private var ageMax: String
    @State private var accounts: [MkIntegrationDto] = []
    @State private var approvedAds: [MkContentDto] = []
    @State private var picking: String?
    @State private var busy = false
    @State private var filledCountry = false

    init(projectId: String, existing: MkCampaignDto?, currency: String, onSaved: @escaping (MkCampaignDto) -> Void) {
        self.projectId = projectId
        self.existing = existing
        self.currency = currency
        self.onSaved = onSaved
        let today = calToday()
        _name = State(initialValue: existing?.name ?? "")
        _objective = State(initialValue: existing?.objective ?? "traffic")
        _integration = State(initialValue: existing?.integrationId)
        _content = State(initialValue: existing?.contentId)
        _daily = State(initialValue: existing.map { mkMoney($0.dailyBudgetMinor, "").replacingOccurrences(of: ",", with: "") } ?? "")
        _start = State(initialValue: mkLocal(existing?.startAt).map { mkNoon($0) } ?? calAddDays(today, 1))
        _end = State(initialValue: mkLocal(existing?.endAt).map { mkNoon($0) } ?? calAddDays(today, 8))
        _countries = State(initialValue: existing.map { ($0.targeting?.strings("countries") ?? []).joined(separator: ",") } ?? "")
        _ageMin = State(initialValue: String(existing?.targeting?.number("age_min") ?? 18))
        _ageMax = State(initialValue: String(existing?.targeting?.number("age_max") ?? 55))
    }

    private var dailyMinor: Int? { mkParseMinor(daily) }
    private var days: Int { max(calCalendar.dateComponents([.day], from: start, to: end).day ?? 0, 0) }
    private var codes: [String] {
        countries.split(whereSeparator: { $0 == "," || $0 == " " || $0 == "،" })
            .map { $0.trimmingCharacters(in: .whitespaces).uppercased() }
            .filter { $0.count == 2 }
    }

    private var ageOk: Bool {
        let lo = Int(ageMin)
        let hi = Int(ageMax)
        return (13...65).contains(lo ?? 18) && (13...65).contains(hi ?? 65) && (lo ?? 0) <= (hi ?? 65)
    }

    var body: some View {
        let perDay = dailyMinor
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(verbatim: L(existing == nil ? "mktg_campaign_new" : "mktg_edit")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                WeTextField(value: $name, label: L("mktg_campaign_name"), limit: 120, capitalize: true)
                choices
                WeTextField(value: Binding(get: { daily }, set: { daily = String($0.prefix(12)) }), label: L("mktg_daily_budget"), placeholder: "0.00",
                            keyboard: .decimalPad, error: !daily.isEmpty && perDay == nil, trailing: currency, ltr: true)
                HStack(alignment: .top, spacing: 10) {
                    MkPickField(label: L("mktg_from"), value: mkDate(mkIso(mkAtHour(start, 9))), icon: "calendar") { picking = "start" }
                    MkPickField(label: L("mktg_to"), value: mkDate(mkIso(mkAtHour(end, 23))), icon: "calendar") { picking = "end" }
                }
                if let perDay, days > 0 {
                    Text(verbatim: L("mktg_total_will_be", mkMoney(perDay * days, currency), days)).font(.dorr(13, .heavy)).foregroundStyle(wa.red)
                }
                audience
                if existing?.approval != nil { WaNote(text: L("mktg_edit_drops_approval")) }
                WaButton(text: L("mktg_save"), enabled: name.trimmingCharacters(in: .whitespacesAndNewlines).count >= 2 && (perDay ?? 0) > 0 && days > 0 && ageOk,
                         loading: busy) { Task { await save() } }
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 20)
        }
        .frame(maxHeight: UIScreen.main.bounds.height * 0.85)
        .fixedSize(horizontal: false, vertical: true)
        .task(id: projectId) { await loadChoices() }
        .dorrDialog(isPresented: Binding(get: { picking != nil }, set: { if !$0 { picking = nil } })) {
            CalDatePickerDialog(initial: picking == "end" ? end : start, onDismiss: { picking = nil }) { picked in
                let today = calToday()
                if picking == "end" {
                    end = picked > start ? picked : calAddDays(start, 1)
                } else {
                    start = calKey(picked) < calKey(today) ? today : picked
                    if !(end > start) { end = calAddDays(start, 1) }
                }
                picking = nil
            }
        }
    }

    @ViewBuilder
    private var choices: some View {
        Text(verbatim: L("mktg_objective")).font(.dorr(12.5, .bold)).foregroundStyle(wa.mut)
        WeFlow(spacing: 8) {
            ForEach(mkObjectives, id: \.self) { o in
                WeChip(text: mkObjectiveLabel(o), selected: objective == o) { objective = o }
            }
        }
        Text(verbatim: L("mktg_ad_account")).font(.dorr(12.5, .bold)).foregroundStyle(wa.mut)
        if accounts.isEmpty {
            Text(verbatim: L("mktg_no_connected_account")).font(.dorr(12.5)).foregroundStyle(mkAmber).fixedSize(horizontal: false, vertical: true)
        } else {
            WeFlow(spacing: 8) {
                ForEach(accounts) { a in
                    WeChip(text: a.account?.name ?? a.account?.id ?? a.provider, selected: integration == a.id) { integration = a.id }
                }
            }
        }
    }

    @ViewBuilder
    private var audience: some View {
        WeTextField(value: Binding(get: { countries }, set: { countries = String($0.uppercased().prefix(80)) }), label: L("mktg_countries"), placeholder: "SA, AE", ltr: true)
        HStack(alignment: .top, spacing: 10) {
            WeTextField(value: Binding(get: { ageMin }, set: { ageMin = String($0.filter(\.isNumber).prefix(2)) }), label: L("mktg_age_from"), keyboard: .numberPad, ltr: true)
            WeTextField(value: Binding(get: { ageMax }, set: { ageMax = String($0.filter(\.isNumber).prefix(2)) }), label: L("mktg_age_to"), keyboard: .numberPad, ltr: true)
        }
        if !approvedAds.isEmpty {
            Text(verbatim: L("mktg_link_ad")).font(.dorr(12.5, .bold)).foregroundStyle(wa.mut)
            WeFlow(spacing: 8) {
                WeChip(text: L("mktg_none"), selected: content == nil) { content = nil }
                ForEach(approvedAds) { a in
                    WeChip(text: String(a.title.prefix(28)), selected: content == a.id) { content = a.id }
                }
            }
        }
    }

    private func loadChoices() async {
        if existing == nil && !filledCountry {
            filledCountry = true
            countries = host.status?.country?.code ?? ""
        }
        accounts = ((try? await MarketingApi.integrations(projectId).data)?.integrations ?? []).filter { $0.status == "CONNECTED" }
        approvedAds = (try? await MarketingApi.contentList(projectId, status: "APPROVED,SCHEDULED,PUBLISHED", kind: "ad").data) ?? []
        if integration == nil { integration = accounts.first?.id }
    }

    private func save() async {
        busy = true
        let startTime = calSameDay(start, Date()) ? Date().addingTimeInterval(30 * 60) : mkAtHour(start, 9)
        var payload: [String: JSONValue] = [
            "name": .string(name.trimmingCharacters(in: .whitespacesAndNewlines)),
            "objective": .string(objective),
            "daily_budget_minor": .int(dailyMinor ?? 0),
            "start_at": .string(mkIso(startTime)),
            "end_at": .string(mkIso(mkAtHour(end, 23))),
        ]
        if let existing { payload["version"] = .int(existing.version) }
        // Always both keys: "" when none (Laravel turns "" into null, which clears the link).
        payload["integration"] = .string(integration ?? "")
        payload["content"] = .string(content ?? "")
        var targeting: [String: JSONValue] = ["countries": .array(codes.map { JSONValue.string($0) })]
        if let lo = Int(ageMin) { targeting["age_min"] = .int(lo) }
        if let hi = Int(ageMax) { targeting["age_max"] = .int(hi) }
        payload["targeting"] = .object(targeting)
        do {
            let saved: MkCampaignDto?
            if let existing {
                saved = try await MarketingApi.updateCampaign(existing.id, payload).data
            } else {
                saved = try await MarketingApi.createCampaign(projectId, payload).data
            }
            if let saved { onSaved(saved) }
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}

private struct MkDelegationSheet: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let projectId: String
    let currency: String
    let onSaved: () -> Void

    @State private var managers: [MkMemberDto] = []
    @State private var member: Int?
    @State private var cap = ""
    @State private var until = calAddDays(calToday(), 30)
    @State private var picking = false
    @State private var busy = false

    var body: some View {
        let capMinor = mkParseMinor(cap)
        VStack(alignment: .leading, spacing: 12) {
            Text(verbatim: L("mktg_delegation_new")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
            Text(verbatim: L("mktg_delegations_note")).font(.dorr(12.5)).foregroundStyle(wa.mut).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
            if managers.isEmpty {
                Text(verbatim: L("mktg_no_managers")).font(.dorr(12.5)).foregroundStyle(mkAmber).fixedSize(horizontal: false, vertical: true)
            } else {
                WeFlow(spacing: 8) {
                    ForEach(managers) { m in
                        WeChip(text: m.name ?? m.phone ?? "", selected: member == m.id) { member = m.id }
                    }
                }
            }
            WeTextField(value: Binding(get: { cap }, set: { cap = String($0.prefix(14)) }), label: L("mktg_delegation_cap"), placeholder: "0.00",
                        keyboard: .decimalPad, error: !cap.isEmpty && capMinor == nil, trailing: currency, ltr: true)
            MkPickField(label: L("mktg_valid_until"), value: mkDate(mkIso(mkAtHour(until, 23))), icon: "calendar") { picking = true }
            WaButton(text: L("mktg_save"), enabled: member != nil && (capMinor ?? 0) >= 100, loading: busy) { Task { await save() } }
        }
        .padding(.horizontal, 20)
        .padding(.bottom, 20)
        .task(id: projectId) {
            managers = ((try? await MarketingApi.members(projectId).data) ?? []).filter { $0.role == "manager" }
        }
        .dorrDialog(isPresented: $picking) {
            CalDatePickerDialog(initial: until, onDismiss: { picking = false }) { picked in
                let today = calToday()
                let first = calAddDays(today, 1)
                let last = calAddDays(today, 90)
                until = calKey(picked) < calKey(first) ? first : (calKey(picked) > calKey(last) ? last : picked)
                picking = false
            }
        }
    }

    private func save() async {
        guard let id = member else { return }
        busy = true
        do {
            _ = try await MarketingApi.createDelegation(projectId, MkDelegationRequest(memberId: id, capMinor: mkParseMinor(cap) ?? 0, expiresAt: mkIso(mkAtHour(until, 23))))
            onSaved()
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}
