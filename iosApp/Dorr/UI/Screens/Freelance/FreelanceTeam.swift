// androidApp `ui/screens/freelance/FreelanceTeam.kt`
import SwiftUI

// FS-104: a project's team (docs/modules/freelance/PERMISSIONS.md). The owner invites Dorr users with one role each; the person invited
// answers; a member opens the owner's project as usual and the server hides what his role may not see or do.

/// The roles in the order the owner picks from (the most common first).
let teamRoles = ["reviewer", "manager", "collaborator", "finance", "delegate"]

private let delegateDays = [7, 14, 30, 90]

func teamRoleLabel(_ role: String?) -> String {
    switch role {
    case "manager": return L("fs_tm_role_manager")
    case "reviewer": return L("fs_tm_role_reviewer")
    case "finance": return L("fs_tm_role_finance")
    case "delegate": return L("fs_tm_role_delegate")
    case "owner": return L("fs_tm_role_owner")
    default: return L("fs_tm_role_collaborator")
    }
}

private func teamRoleLine(_ role: String?) -> String {
    switch role {
    case "manager": return L("fs_tm_role_manager_line")
    case "reviewer": return L("fs_tm_role_reviewer_line")
    case "finance": return L("fs_tm_role_finance_line")
    case "delegate": return L("fs_tm_role_delegate_line")
    default: return L("fs_tm_role_collaborator_line")
    }
}

/// What a role may do, told to the person who holds it (the invitation, the banner on the owner's project).
private func teamRoleLineForMember(_ role: String?) -> String {
    switch role {
    case "manager": return L("fs_tm_you_manager")
    case "reviewer": return L("fs_tm_you_reviewer")
    case "finance": return L("fs_tm_you_finance")
    case "delegate": return L("fs_tm_you_delegate")
    default: return L("fs_tm_you_collaborator")
    }
}

func teamRoleIcon(_ role: String?) -> String {
    switch role {
    case "manager": return "person.badge.key.fill"
    case "reviewer": return "checklist"
    case "finance": return "building.columns.fill"
    case "delegate": return "checkmark.seal.fill"
    case "owner": return "star.fill"
    default: return "eye.fill"
    }
}

func teamRoleTint(_ role: String?) -> Color {
    switch role {
    case "manager": return Color(hex: 0x3B82F6)
    case "reviewer": return Color(hex: 0x8B5CF6)
    case "finance": return Color(hex: 0x0EA5E9)
    case "delegate": return Color(hex: 0xD97706)
    case "owner": return Color(hex: 0xEC4899)
    default: return Color(hex: 0x64748B)
    }
}

/// «7 days», «14 days» — Arabic counts 3 to 10 differently.
private func teamDays(_ n: Int) -> String { L((3...10).contains(n) ? "fs_tm_days_few" : "fs_tm_days_many", n) }

private struct RolePill: View {
    let role: String?
    var small = false

    var body: some View {
        let tint = teamRoleTint(role)
        HStack(spacing: 4) {
            Image(systemName: teamRoleIcon(role)).font(.system(size: small ? 10 : 12, weight: .semibold))
            Text(verbatim: teamRoleLabel(role)).font(.dorr(small ? 10.5 : 11.5, .bold)).lineLimit(1)
        }
        .foregroundStyle(tint)
        .padding(.horizontal, small ? 8 : 10).padding(.vertical, small ? 2 : 4)
        .background(tint.opacity(0.12), in: Capsule())
    }
}

private struct TeamAvatar: View {
    let name: String?
    let tint: Color

    var body: some View {
        let first = (name ?? "").trimmingCharacters(in: .whitespaces).prefix(1).uppercased()
        Text(verbatim: first.isEmpty ? "؟" : first).font(.dorr(18, .heavy)).foregroundStyle(tint)
            .frame(width: 44, height: 44).background(tint.opacity(0.14), in: Circle())
    }
}

private struct StatusTag: View {
    let text: String
    let color: Color
    let icon: String

    var body: some View {
        HStack(spacing: 3) {
            Image(systemName: icon).font(.system(size: 11, weight: .semibold))
            Text(verbatim: text).font(.dorr(11.5, .semibold))
        }
        .foregroundStyle(color)
    }
}

private struct DelegateLimitLine: View {
    let max: Int?
    let currency: String
    let endsAt: String?
    let over: Bool

    var body: some View {
        if over {
            FlNotice(text: L("fs_tm_delegate_over"), icon: "calendar.badge.exclamationmark", color: flRed)
        } else if let max {
            FlNotice(text: L("fs_tm_delegate_line", "\u{200E}\(flMoney(max)) \(flCurrencySign(currency))", "\u{200E}" + flLocalDateTime(endsAt ?? "")),
                     icon: "checkmark.seal.fill", color: flAmber)
        }
    }
}

// MARK: - The owner's team page

struct ProjectTeamPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let project: String
    let onBack: () -> Void
    @State private var page: TeamPageDto?
    @State private var error: String?
    @State private var version = 0
    @State private var inviting = false
    @State private var editing: TeamMemberDto?
    @State private var notice: String?

    var body: some View {
        VStack(spacing: 0) {
            let members = page?.members ?? []
            FlPageHeader(title: L("fs_tm_title"), onBack: onBack,
                         trailing: page != nil && members.count < (page?.max ?? 10)
                            ? AnyView(FlHeaderAction(text: L("fs_tm_invite"), icon: "person.badge.plus") { notice = nil; inviting = true }) : nil)
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 10) {
                    FlHint(text: L("fs_tm_intro"))
                    if let notice { FlNotice(text: notice, icon: "checkmark.circle.fill", color: flGreen) }
                    if let p = page {
                        if members.isEmpty {
                            WaEmpty(icon: "person.3.fill", tone: .pink, title: L("fs_tm_empty_title"), text: L("fs_tm_empty"))
                            FlAction(text: L("fs_tm_invite"), icon: "person.badge.plus", tone: .primary) { inviting = true }
                        } else {
                            Text(verbatim: L("fs_tm_count", members.filter { $0.status == "active" }.count, p.max ?? 10))
                                .font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut).padding(.horizontal, 4)
                            ForEach(members) { m in
                                MemberRow(m: m, currency: p.currency ?? "") { notice = nil; editing = m }
                            }
                        }
                    } else if let error {
                        FlRetry(message: error)
                    } else {
                        ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 84) }
                    }
                    RolesGuide()
                }
                .padding(.horizontal, 16).padding(.vertical, 10)
            }
            .refreshable { network.noticeArrived() }
        }
        .task(id: "\(network.reconnectTick)|\(version)") { await load() }
        .dorrDialog(isPresented: $inviting, bottom: true) {
            TeamRoleSheet(member: nil, currency: page?.currency ?? "", onDismiss: { inviting = false }) { phone, role, max, days in
                let r = try await FreelanceTeamApi.invite(project, phone: phone, role: role, maxAmountMinor: max, days: days)
                page = r.data
                notice = r.message
            }
        }
        .dorrDialog(isPresented: Binding(get: { editing != nil }, set: { if !$0 { editing = nil } }), bottom: true) {
            if let m = editing {
                TeamRoleSheet(member: m, currency: page?.currency ?? "", onDismiss: { editing = nil },
                              submit: { _, role, max, days in page = try await FreelanceTeamApi.update(project, member: m.uuid, role: role, maxAmountMinor: max, days: days).data },
                              onRemove: { page = try await FreelanceTeamApi.remove(project, member: m.uuid).data })
            }
        }
    }

    private func load() async {
        do {
            page = try await FreelanceTeamApi.team(project).data
            error = nil
        } catch {
            if Task.isCancelled { return }
            self.error = flError(error)
        }
    }
}

private struct MemberRow: View {
    @Environment(\.wa) private var wa
    let m: TeamMemberDto
    let currency: String
    let onTap: () -> Void

    var body: some View {
        Button(action: onTap) {
            VStack(alignment: .leading, spacing: 8) {
                HStack(spacing: 12) {
                    TeamAvatar(name: m.name, tint: teamRoleTint(m.role))
                    VStack(alignment: .leading, spacing: 3) {
                        Text(verbatim: (m.name ?? "").isEmpty ? "—" : m.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        Text(verbatim: "\u{200E}" + (m.phone ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Image(systemName: "chevron.left").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut).flipsForRightToLeftLayoutDirection(true)
                }
                HStack(spacing: 6) {
                    RolePill(role: m.role)
                    switch m.status {
                    case "invited": StatusTag(text: L("fs_tm_status_invited"), color: flAmber, icon: "hourglass")
                    case "expired": StatusTag(text: L("fs_tm_status_expired"), color: flRed, icon: "calendar.badge.exclamationmark")
                    default: StatusTag(text: L("fs_tm_status_active"), color: flGreen, icon: "checkmark.circle.fill")
                    }
                }
                if m.role == "delegate" { DelegateLimitLine(max: m.maxAmountMinor, currency: currency, endsAt: m.endsAt, over: m.delegationOver ?? false) }
            }
            .padding(14)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .settingsSurface(FlCorners.card)
    }
}

/// Each role in one line — shown under the team so the owner picks knowing what he gives.
private struct RolesGuide: View {
    @Environment(\.wa) private var wa
    @State private var open = false

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Button { open.toggle() } label: {
                HStack(spacing: 8) {
                    Image(systemName: "info.circle.fill").font(.system(size: 17)).foregroundStyle(wa.accent)
                    Text(verbatim: L("fs_tm_roles_guide")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                    Image(systemName: open ? "chevron.up" : "chevron.down").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut)
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            if open {
                ForEach(teamRoles, id: \.self) { r in
                    HStack(alignment: .top, spacing: 10) {
                        Image(systemName: teamRoleIcon(r)).font(.system(size: 15, weight: .semibold)).foregroundStyle(teamRoleTint(r)).padding(.top, 2)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(verbatim: teamRoleLabel(r)).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                            Text(verbatim: teamRoleLine(r)).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                        }
                    }
                }
                Text(verbatim: L("fs_tm_owner_stays")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(FlCorners.card)
    }
}

/// Inviting someone (member nil: a phone first) or changing a member's role. A delegate needs his limit and length; the sheet says that a
/// delegate pays from the owner's wallet. `submit` throws what the server refused; the sheet shows it and stays open.
@MainActor
private struct TeamRoleSheet: View {
    @Environment(\.wa) private var wa
    let member: TeamMemberDto?
    let currency: String
    let onDismiss: () -> Void
    let submit: (String, String, Int?, Int?) async throws -> Void
    var onRemove: (() async throws -> Void)?
    @State private var phone = ""
    @State private var role: String
    @State private var max: String
    @State private var days = 30
    @State private var busy = false
    @State private var error: String?
    @State private var removing = false

    init(member: TeamMemberDto?, currency: String, onDismiss: @escaping () -> Void, submit: @escaping (String, String, Int?, Int?) async throws -> Void, onRemove: (() async throws -> Void)? = nil) {
        self.member = member
        self.currency = currency
        self.onDismiss = onDismiss
        self.submit = submit
        self.onRemove = onRemove
        _role = State(initialValue: member?.role ?? "reviewer")
        _max = State(initialValue: member?.maxAmountMinor.map { flMoney($0) } ?? "")
    }

    private var ready: Bool {
        (member != nil || phone.filter(\.isNumber).count >= 8) && (role != "delegate" || (flParseMoney(max) ?? 0) > 0)
    }

    var body: some View {
        if removing, let member, let onRemove {
            let active = member.status == "active"
            ConfirmDialog(icon: "person.badge.minus", title: L(active ? "fs_tm_remove" : "fs_tm_cancel_invite"), message: L("fs_tm_remove_confirm", member.name ?? ""),
                          loading: busy, confirmLabel: L(active ? "fs_tm_remove" : "fs_tm_cancel_invite"),
                          onConfirm: { run { try await onRemove() } }, onDismiss: { removing = false })
        } else {
            FlSheet(icon: member == nil ? "person.badge.plus" : "person.badge.key.fill",
                    title: member == nil ? L("fs_tm_invite_title") : (member?.name ?? ""),
                    text: member == nil ? L("fs_tm_invite_body") : L("fs_tm_change_role_body"),
                    footer: AnyView(VStack(spacing: 10) {
                        if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed) }
                        FlAction(text: L(member == nil ? "fs_tm_send" : "fs_tm_save"), icon: member == nil ? "paperplane.fill" : "square.and.arrow.down", tone: .primary,
                                 enabled: ready && !busy, loading: busy) {
                            run { try await submit(phone.trimmingCharacters(in: .whitespaces), role, role == "delegate" ? flParseMoney(max) : nil, role == "delegate" ? days : nil) }
                        }
                        if onRemove != nil {
                            FlAction(text: L(member?.status == "active" ? "fs_tm_remove" : "fs_tm_cancel_invite"), icon: "person.badge.minus", tone: .danger, enabled: !busy) { removing = true }
                        }
                    })) {
                if member == nil {
                    DorrTextArea(value: Binding(get: { phone }, set: { phone = String($0.filter { $0.isNumber || $0 == "+" || $0 == " " }.prefix(20)) }),
                                 label: L("fs_tm_phone"), placeholder: "+9665…", icon: "phone.fill", singleLine: true)
                        .keyboardType(.phonePad)
                        .environment(\.layoutDirection, .leftToRight)
                }
                Text(verbatim: L("fs_tm_role")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                ForEach(teamRoles, id: \.self) { r in RoleOption(role: r, selected: role == r) { role = r } }
                if role == "delegate" {
                    DorrTextArea(value: Binding(get: { max }, set: { max = String($0.prefix(12)) }), label: L("fs_tm_limit", flCurrencySign(currency)), icon: "banknote", singleLine: true)
                        .keyboardType(.decimalPad)
                    Text(verbatim: L("fs_tm_days")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    LazyVGrid(columns: [GridItem(.adaptive(minimum: 96), spacing: 8, alignment: .leading)], alignment: .leading, spacing: 8) {
                        ForEach(delegateDays, id: \.self) { d in FlChip(text: teamDays(d), selected: days == d, icon: "calendar") { days = d } }
                    }
                    FlNotice(text: L("fs_tm_delegate_warn"), icon: "exclamationmark.triangle.fill", color: flAmber)
                }
            }
        }
    }

    private func run(_ block: @escaping () async throws -> Void) {
        busy = true
        error = nil
        Task {
            do {
                try await block()
                onDismiss()
            } catch {
                self.error = flError(error)
                removing = false
            }
            busy = false
        }
    }
}

private struct RoleOption: View {
    @Environment(\.wa) private var wa
    let role: String
    let selected: Bool
    let onTap: () -> Void

    var body: some View {
        let tint = teamRoleTint(role)
        Button(action: onTap) {
            HStack(spacing: 12) {
                Image(systemName: teamRoleIcon(role)).font(.system(size: 17, weight: .semibold)).foregroundStyle(tint)
                    .frame(width: 38, height: 38).background(tint.opacity(0.14), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: teamRoleLabel(role)).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: teamRoleLine(role)).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2).multilineTextAlignment(.leading)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Image(systemName: selected ? "largecircle.fill.circle" : "circle").font(.system(size: 20)).foregroundStyle(selected ? tint : wa.settingsMut)
            }
            .padding(12)
            .background(selected ? tint.opacity(0.10) : .clear, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(selected ? tint : wa.settingsMut.opacity(0.25), lineWidth: selected ? 1.5 : 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - On the owner's project page

/// The owner's way into his team, on the project's details.
struct ProjectTeamCard: View {
    @Environment(\.wa) private var wa
    let team: FreelanceTeamSummaryDto
    let onOpen: () -> Void

    var body: some View {
        Button(action: onOpen) {
            HStack(spacing: 12) {
                Image(systemName: "person.3.fill").font(.system(size: 18, weight: .semibold)).foregroundStyle(FlTints.favorites)
                    .frame(width: 44, height: 44).background(FlTints.favorites.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: L("fs_tm_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                    let members = team.members ?? 0, invited = team.invited ?? 0
                    Text(verbatim: members + invited == 0 ? L("fs_tm_card_empty") : L("fs_tm_card_line", members, invited))
                        .font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Image(systemName: "chevron.left").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut).flipsForRightToLeftLayoutDirection(true)
            }
            .padding(14)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .settingsSurface(FlCorners.card)
    }
}

/// On a page of someone else's project: whose it is, my role, and what that lets me do (a delegate: his limit and date).
struct TeamAccessBanner: View {
    @Environment(\.wa) private var wa
    let access: FreelanceAccessDto
    let currency: String
    var onLeave: (() -> Void)?

    var body: some View {
        if !access.isOwner {
            let tint = teamRoleTint(access.role)
            VStack(alignment: .leading, spacing: 8) {
                HStack(spacing: 10) {
                    Image(systemName: teamRoleIcon(access.role)).font(.system(size: 19, weight: .semibold)).foregroundStyle(tint)
                    Text(verbatim: L("fs_tm_banner", access.owner?.name ?? "", teamRoleLabel(access.role)))
                        .font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
                }
                Text(verbatim: teamRoleLineForMember(access.role)).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                if access.role == "delegate" {
                    DelegateLimitLine(max: access.maxAmountMinor, currency: currency, endsAt: access.endsAt, over: access.delegationOver ?? false)
                }
                if access.money == false {
                    HStack(spacing: 6) {
                        Image(systemName: "eye.slash").font(.system(size: 12))
                        Text(verbatim: L("fs_tm_money_hidden")).font(.dorr(11.5))
                    }
                    .foregroundStyle(wa.settingsMut)
                }
                if let onLeave {
                    Button(action: onLeave) { Text(verbatim: L("fs_tm_leave")).font(.dorr(12.5, .bold)).foregroundStyle(flRed).padding(.vertical, 4) }
                        .buttonStyle(.plain)
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(tint.opacity(0.08), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(tint.opacity(0.25), lineWidth: 1))
        }
    }
}

// MARK: - The projects I was brought into

struct SharedProjectsPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpen: (String) -> Void
    @State private var invitations: [TeamInvitationDto]?
    @State private var projects: [FreelanceProjectDto]?
    @State private var error: String?
    @State private var version = 0
    @State private var busy: String?
    @State private var notice: String?

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: L("fs_tm_shared_title"), onBack: onBack)
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 10) {
                    if let notice { FlNotice(text: notice, icon: "checkmark.circle.fill", color: flGreen) }
                    if let inv = invitations, let list = projects {
                        if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed) }
                        if !inv.isEmpty {
                            FlSectionHeader(title: L("fs_tm_invitations"))
                            ForEach(inv) { i in
                                InvitationCard(inv: i, busy: busy == i.uuid, onAccept: { answer(i, "accept") }, onDecline: { answer(i, "decline") })
                            }
                        }
                        if list.isEmpty && inv.isEmpty {
                            WaEmpty(icon: "person.3.fill", tone: .pink, title: L("fs_tm_shared_empty_title"), text: L("fs_tm_shared_empty"))
                        }
                        if !list.isEmpty {
                            FlSectionHeader(title: L("fs_tm_shared_projects")).padding(.top, 4)
                            ForEach(list) { p in SharedProjectRow(p: p) { onOpen(p.uuid) } }
                        }
                    } else if let error {
                        FlRetry(message: error)
                    } else {
                        ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 96) }
                    }
                }
                .padding(.horizontal, 16).padding(.vertical, 10)
            }
            .refreshable { network.noticeArrived() }
        }
        .task(id: "\(network.reconnectTick)|\(version)") { await load() }
    }

    private func load() async {
        do {
            invitations = try await FreelanceTeamApi.invitations().data?.invitations ?? []
            projects = try await FreelanceTeamApi.shared().data ?? []
            error = nil
        } catch {
            if Task.isCancelled { return }
            self.error = flError(error)
        }
    }

    private func answer(_ inv: TeamInvitationDto, _ answer: String) {
        busy = inv.uuid
        Task {
            do {
                let r = try await FreelanceTeamApi.respond(inv.uuid, answer: answer)
                notice = r.message
                version += 1
                if answer == "accept", let p = r.data?.project { onOpen(p) }
            } catch {
                self.error = flError(error)
            }
            busy = nil
        }
    }
}

private struct InvitationCard: View {
    @Environment(\.wa) private var wa
    let inv: TeamInvitationDto
    let busy: Bool
    let onAccept: () -> Void
    let onDecline: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 12) {
                TeamAvatar(name: inv.owner?.name, tint: teamRoleTint(inv.role))
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: (inv.project?.title ?? "").isEmpty ? L("fl_untitled") : inv.project?.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    Text(verbatim: L("fs_tm_invited_by", inv.owner?.name ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            RolePill(role: inv.role)
            Text(verbatim: teamRoleLineForMember(inv.role)).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            if inv.role == "delegate" { DelegateLimitLine(max: inv.maxAmountMinor, currency: inv.currency ?? "", endsAt: inv.endsAt, over: false) }
            if let exp = inv.expiresAt {
                Text(verbatim: L("fs_tm_expires", "\u{200E}" + flLocalDateTime(exp))).font(.dorr(11.5, .semibold)).foregroundStyle(flAmber)
            }
            HStack(spacing: 10) {
                FlAction(text: L("fs_tm_accept"), icon: "checkmark", tone: .primary, enabled: !busy, loading: busy, action: onAccept).layoutPriority(1.4)
                FlAction(text: L("fs_tm_decline"), enabled: !busy, action: onDecline)
            }
        }
        .padding(14)
        .settingsSurface(FlCorners.card)
    }
}

private struct SharedProjectRow: View {
    @Environment(\.wa) private var wa
    let p: FreelanceProjectDto
    let onTap: () -> Void

    var body: some View {
        Button(action: onTap) {
            VStack(alignment: .leading, spacing: 8) {
                HStack(spacing: 8) {
                    Text(verbatim: (p.title ?? "").isEmpty ? L("fl_untitled") : p.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    Image(systemName: "chevron.left").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut).flipsForRightToLeftLayoutDirection(true)
                }
                HStack(spacing: 8) {
                    if let a = p.access { RolePill(role: a.role, small: true) }
                    Text(verbatim: [p.access?.owner?.name.map { L("fs_tm_owner_line", $0) }, p.statusLabel].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: "  ·  "))
                        .font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
            }
            .padding(14)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .settingsSurface(FlCorners.card)
    }
}
