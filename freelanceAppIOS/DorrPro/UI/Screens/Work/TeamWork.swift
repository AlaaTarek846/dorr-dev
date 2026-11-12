import SwiftUI

// freelanceApp `ui/screens/TeamWork.kt`.

/*
 * FS-209b: working as a team — what a team's project page and its team page show: the banner that says whose team it is and what my place may
 * do, the team's projects and its workload, and who works on each part of a project (an Owner or a PM puts people on a part from a sheet).
 * The server already hides what a place may not see; these only explain it. Nothing runs on a timer: each list reads when shown, when a
 * notice arrives and when the connection returns.
 */

private let twAmber = Color(hex: 0xD97706)
private let twRed = Color(hex: 0xDC2626)
private let twGreen = Color(hex: 0x16A34A)

/// On the page of a team's project: whose team it is, my place in it and what that means here (the server already hides what my place may not
/// see, so this only explains why parts of the page are missing).
struct TeamAccessBanner: View {
    @Environment(\.wa) private var wa
    let access: TeamAccessDto

    /// What my place may not do here (an Owner has no limits to explain).
    private var limits: String? {
        if access.signer == true { return L("tw_banner_signer") }
        switch access.role ?? "member" {
        case "pm": return L("tw_banner_pm")
        case "member": return L("tw_banner_member")
        default: return nil
        }
    }

    var body: some View {
        let accent = wa.red
        let role = access.role ?? "member"
        HStack(alignment: .top, spacing: 12) {
            Image(systemName: "person.3.fill").font(.system(size: 18, weight: .semibold)).foregroundStyle(accent)
                .frame(width: 40, height: 40)
                .background(accent.opacity(0.14), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            VStack(alignment: .leading, spacing: 5) {
                Text(verbatim: L("tw_banner_title", access.team?.name ?? "")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                    .frame(maxWidth: .infinity, alignment: .leading)
                RolePill(role: role)
                if let limits {
                    Text(verbatim: limits).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
                if !access.can("money") {
                    Text(L("tw_banner_no_money")).font(.dorr(12.5, .semibold)).foregroundStyle(twAmber).lineSpacing(3)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(accent.opacity(0.08), in: RoundedRectangle(cornerRadius: Corners.card, style: .continuous))
    }
}

/// The «Projects» part of a team: the team's projects I reach (every one for an Owner or a PM, the ones I am on for a member), each with my
/// place on it. A card opens the project. Read when shown, when a notice arrives and when the connection returns.
struct TeamProjectsList: View {
    @EnvironmentObject private var network: NetworkMonitor
    let teamUuid: String
    let role: String?
    let onOpenProject: (String) -> Void

    @State private var rows: [TeamProjectDto]?
    @State private var failure: String?
    /// The team `rows` belong to (another team's chip resets the list).
    @State private var shown = ""

    private func load() async {
        let team = teamUuid
        let result = await TeamsApi.projects(team)
        if Task.isCancelled || team != teamUuid { return }
        if let list = result.value {
            rows = list
            failure = nil
        } else if rows == nil {
            failure = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    var body: some View {
        VStack(spacing: 12) { content }
            .frame(maxWidth: .infinity)
            .task(id: "\(teamUuid)|\(network.reconnectTick)") {
                if shown != teamUuid {
                    rows = nil
                    failure = nil
                    shown = teamUuid
                }
                await load()
            }
            .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
    }

    @ViewBuilder
    private var content: some View {
        if let list = rows {
            if list.isEmpty {
                EmptyState(icon: "hammer.fill", title: L("tw_projects_empty_title"),
                           body: L(role == "member" ? "tw_projects_empty_member" : "tw_projects_empty_body")).padding(.top, 30)
            } else {
                ForEach(list) { p in
                    TeamProjectCard(p: p) { if let uuid = p.project?.uuid { onOpenProject(uuid) } }
                }
            }
        } else if let failure {
            InlineErrorBanner(message: failure)
        } else {
            SkeletonList(count: 3)
        }
    }
}

/// One of the team's projects: its title and state, my place on it, the price when my place may see it, and who signed.
private struct TeamProjectCard: View {
    @Environment(\.wa) private var wa
    let p: TeamProjectDto
    let onClick: () -> Void

    var body: some View {
        let status = p.agreement?.status ?? "pending_approval"
        let tint = workStatusColor(status)
        let signedBy: String? = p.teamAccess?.signer == true ? nil : p.signedBy?.name
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(alignment: .top, spacing: 0) {
                    Text(verbatim: p.project?.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2).lineSpacing(3)
                        .multilineTextAlignment(.leading).frame(maxWidth: .infinity, alignment: .leading)
                    Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 20, height: 20)
                }
                HStack(spacing: 6) {
                    StatusPill(text: workStatusLabel(status), tint: tint)
                    RolePill(role: p.teamAccess?.role ?? "member")
                }
                if p.agreement?.priceMinor != nil || signedBy != nil {
                    HStack(spacing: 16) {
                        if let price = p.agreement?.priceMinor {
                            FactLine(icon: "banknote.fill", text: "\u{200E}\(formatMinor(price, nil)) \(p.agreement?.currency ?? "")", strong: true)
                        }
                        if let signedBy { FactLine(icon: "person.fill", text: L("tw_signed_by", signedBy)) }
                    }
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .appCard(Corners.card)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// The «Workload» part of a team: what each member carries on the team's running projects — the projects, the parts not over, and the tasks
/// (open, late, blocked, done). An Owner or a PM reads everybody; a member reads his own row only.
struct TeamWorkloadSection: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let teamUuid: String
    let role: String?

    @State private var rows: [TeamLoadDto]?
    @State private var failure: String?
    @State private var shown = ""

    private func load() async {
        let team = teamUuid
        let result = await TeamsApi.workload(team)
        if Task.isCancelled || team != teamUuid { return }
        if let data = result.value {
            rows = data.members ?? []
            failure = nil
        } else if rows == nil {
            failure = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    var body: some View {
        VStack(spacing: 12) {
            Text(L(role == "member" ? "tw_load_mine" : "tw_load_intro")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                .frame(maxWidth: .infinity, alignment: .leading)
            if let list = rows {
                if list.isEmpty {
                    EmptyState(icon: "person.3.fill", title: L("tw_load_empty_title"), body: L("tw_load_empty_body")).padding(.top, 30)
                } else {
                    ForEach(list) { l in LoadCard(l: l) }
                }
            } else if let failure {
                InlineErrorBanner(message: failure)
            } else {
                SkeletonList(count: 3)
            }
        }
        .frame(maxWidth: .infinity)
        .task(id: "\(teamUuid)|\(network.reconnectTick)") {
            if shown != teamUuid {
                rows = nil
                failure = nil
                shown = teamUuid
            }
            await load()
        }
        .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
    }
}

private struct LoadCard: View {
    @Environment(\.wa) private var wa
    let l: TeamLoadDto

    var body: some View {
        let role = l.role ?? "member"
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 12) {
                InitialsBadge(name: l.freelancer?.name, size: 42, tint: roleTint(role))
                Text(verbatim: l.freelancer?.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    .frame(maxWidth: .infinity, alignment: .leading)
                RolePill(role: role)
            }
            FlowLayout(spacing: 8, lineSpacing: 8) {
                LoadStat(value: l.projects ?? 0, label: L("tw_load_projects"), tint: wa.red)
                LoadStat(value: l.partsOpen ?? 0, label: L("tw_load_parts"), tint: wa.red)
                LoadStat(value: l.tasksOpen ?? 0, label: L("tw_load_tasks"), tint: wa.red)
                LoadStat(value: l.tasksOverdue ?? 0, label: L("tw_load_overdue"), tint: twRed, hot: (l.tasksOverdue ?? 0) > 0)
                LoadStat(value: l.tasksBlocked ?? 0, label: L("tw_load_blocked"), tint: twAmber, hot: (l.tasksBlocked ?? 0) > 0)
                LoadStat(value: l.tasksDone ?? 0, label: L("tw_load_done"), tint: twGreen)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .appCard(Corners.card)
    }
}

/// A number and what it counts; a late or blocked count turns on its colour when it is above zero.
private struct LoadStat: View {
    @Environment(\.wa) private var wa
    let value: Int
    let label: String
    let tint: Color
    var hot = false

    var body: some View {
        let color = value == 0 && !hot ? wa.settingsMut : tint
        VStack(spacing: 0) {
            Text(verbatim: "\u{200E}\(value)").font(.dorr(17, .heavy)).foregroundStyle(color)
            Text(verbatim: label).font(.dorr(11)).foregroundStyle(wa.settingsMut)
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
        .background(color.opacity(hot ? 0.12 : 0.07), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// The people on a part, as small chips under its title - and «Put people on it» for an Owner or a PM.
struct AssigneesRow: View {
    @Environment(\.wa) private var wa
    let people: [TeamPersonRefDto]
    let canAssign: Bool
    let onAssign: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            FlowLayout(spacing: 6, lineSpacing: 6) {
                if people.isEmpty { Text(L("tw_assign_none")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                ForEach(people) { p in
                    HStack(spacing: 6) {
                        InitialsBadge(name: p.name, size: 22)
                        Text(verbatim: p.name ?? "").font(.dorr(12, .bold)).foregroundStyle(wa.red).lineLimit(1)
                    }
                    .padding(.leading, 4)
                    .padding(.trailing, 10)
                    .padding(.vertical, 3)
                    .background(wa.red.opacity(0.10), in: Capsule())
                }
            }
            if canAssign {
                Button(action: onAssign) {
                    HStack(spacing: 6) {
                        Image(systemName: "person.crop.circle.badge.plus").font(.system(size: 14, weight: .semibold)).frame(width: 16, height: 16)
                        Text(L("tw_assign")).font(.dorr(12.5, .bold))
                    }
                    .foregroundStyle(wa.red)
                    .padding(.horizontal, 12)
                    .padding(.vertical, 7)
                    .background(wa.red.opacity(0.10), in: Capsule())
                    .contentShape(Capsule())
                }
                .buttonStyle(.plain)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

/// A rounded tick box (a person picked, a part chosen).
struct TeamCheckBox: View {
    @Environment(\.wa) private var wa
    let on: Bool

    var body: some View {
        RoundedRectangle(cornerRadius: 8, style: .continuous)
            .fill(on ? wa.red : Color.clear)
            .overlay(RoundedRectangle(cornerRadius: 8, style: .continuous).stroke(on ? wa.red : wa.settingsMut.opacity(0.4), lineWidth: 1.5))
            .overlay {
                if on { Image(systemName: "checkmark").font(.system(size: 12, weight: .bold)).foregroundStyle(.white) }
            }
            .frame(width: 24, height: 24)
    }
}

/// An Owner's or a PM's sheet to put people of the team on one part: the team's active members with a tick each; Save sends exactly the
/// ticked people (none takes everybody off). A member sees the project once he is on a part. Shown with `.cmSheet` (Android
/// `LocaleAwareBottomSheet`), which closes itself — [onSaved] is where the page reads again.
struct AssigneesSheet: View {
    @Environment(\.wa) private var wa
    let projectUuid: String
    let milestoneUuid: String
    let milestoneTitle: String
    let teamUuid: String
    let onSaved: () -> Void

    @State private var members: [TeamMemberDto]?
    @State private var picked: [String]
    @State private var busy = false
    @State private var error: String?

    init(projectUuid: String, milestoneUuid: String, milestoneTitle: String, teamUuid: String, current: [TeamPersonRefDto], onSaved: @escaping () -> Void) {
        self.projectUuid = projectUuid
        self.milestoneUuid = milestoneUuid
        self.milestoneTitle = milestoneTitle
        self.teamUuid = teamUuid
        self.onSaved = onSaved
        _picked = State(initialValue: current.compactMap(\.uuid))
    }

    private func toggle(_ uuid: String) {
        if let index = picked.firstIndex(of: uuid) { picked.remove(at: index) } else { picked.append(uuid) }
    }

    private func save() {
        busy = true
        error = nil
        Task {
            let result = await TeamsApi.setAssignees(projectUuid, milestoneUuid, AssigneesRequest(freelancers: picked))
            busy = false
            if case .ok = result { onSaved() } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(L("tw_assign_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                Text(verbatim: milestoneTitle).font(.dorr(13, .bold)).foregroundStyle(wa.red).lineLimit(2)
                Text(L("tw_assign_hint")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                if let list = members {
                    ForEach(list) { m in
                        if let uuid = m.freelancer?.uuid {
                            let on = picked.contains(uuid)
                            let role = m.role ?? "member"
                            Button { toggle(uuid) } label: {
                                HStack(spacing: 12) {
                                    InitialsBadge(name: m.freelancer?.name, size: 38, tint: roleTint(role))
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(verbatim: m.freelancer?.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                                        Text(verbatim: roleLabel(role)).font(.dorr(11.5, .semibold)).foregroundStyle(roleTint(role))
                                    }
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    TeamCheckBox(on: on)
                                }
                                .padding(.horizontal, 12)
                                .padding(.vertical, 10)
                                .frame(maxWidth: .infinity)
                                .overlay(RoundedRectangle(cornerRadius: Corners.small, style: .continuous)
                                    .stroke(on ? wa.red.opacity(0.55) : wa.settingsMut.opacity(0.16), lineWidth: 1))
                                .contentShape(RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
                            }
                            .buttonStyle(.plain)
                            .disabled(busy)
                        }
                    }
                } else if error == nil {
                    SkeletonList(count: 3)
                }
                if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(twRed).frame(maxWidth: .infinity, alignment: .leading) }
                SettingsPrimaryButton(text: L("common_save"), enabled: members != nil, loading: busy, action: save)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 18)
            .padding(.top, 24)
            .padding(.bottom, 24)
        }
        .scrollDismissesKeyboard(.interactively)
        .background(wa.settingsCard.ignoresSafeArea())
        .task(id: teamUuid) {
            let result = await TeamsApi.members(teamUuid)
            if let list = result.value?.members {
                members = list
            } else if !Task.isCancelled {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}
