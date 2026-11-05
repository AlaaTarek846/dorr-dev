// providerApp `ui/screens/TeamScreens.kt`
import SwiftUI

/// The team (C-03, `members.manage`): who works in the establishment (owner first, invitations not answered yet marked), inviting
/// someone by phone with exactly the permissions they get (§17), changing or removing a member, a driver's licence, and the log of
/// every change. Read once on open and when the connection returns.
@MainActor
struct TeamScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpenDriverDocs: (String) -> Void

    @State private var members: [MemberDto]?
    @State private var options: WorkspaceOptionsDto?
    @State private var branches: [BranchDto] = []
    @State private var error: String?
    /// 0 = the list; 1 = a new invitation; 2 = editing `editingUuid`; 3 = the log.
    @State private var page = 0
    @State private var editingUuid: String?

    var body: some View {
        ZStack {
            switch page {
            case 1, 2:
                MemberForm(
                    member: page == 2 ? members?.first(where: { $0.uuid == editingUuid }) : nil,
                    options: options, branches: branches,
                    onBack: { page = 0 },
                    onSaved: {
                        Task {
                            await reload()
                            page = 0
                        }
                    },
                    onOpenDriverDocs: onOpenDriverDocs
                )
                .id("\(page)-\(editingUuid ?? "")")
            case 3:
                TeamLog(onBack: { page = 0 })
            default:
                list
            }
        }
        .task(id: network.reconnectTick) {
            await reload()
            if options == nil, let data = await WorkspaceApi.options(countryId: Session.shared.me?.countryId).value { options = data }
            if let data = await ManageApi.branches().value { branches = data }
        }
    }

    private var list: some View {
        Page(title: L("tm_title"), onBack: onBack) {
            if let shown = members {
                if shown.isEmpty {
                    EmptyState(icon: "person.3.fill", title: L("tm_empty_title"), body: L("tm_empty_body")).padding(.top, 30)
                } else {
                    ForEach(shown) { member in
                        MemberRow(member: member) {
                            if member.isOwner != true {
                                editingUuid = member.uuid
                                page = 2
                            }
                        }
                    }
                }
            } else if error == nil {
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
            }
            SettingsPrimaryButton(text: L("tm_invite")) { page = 1 }
            ProfileTextLink(text: L("tm_log")) { page = 3 }
        }
        .messageToast(error)
    }

    private func reload() async {
        let result = await ManageApi.members()
        if let data = result.value {
            members = data
            error = nil
        } else {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }
}

private struct MemberRow: View {
    @Environment(\.wa) private var wa
    let member: MemberDto
    let onClick: () -> Void

    var body: some View {
        Card {
            Button(action: onClick) {
                HStack(spacing: 10) {
                    AvatarImage(url: member.person?.avatar, size: 42)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: notBlank(member.person?.name) ?? ("\u{200E}" + (member.person?.phone ?? member.phone ?? "")))
                            .font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                        Text(member.isOwner == true ? L("acc_role_owner") : L("tm_permissions_count", member.permissions?.count ?? 0))
                            .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    VStack(alignment: .trailing, spacing: 4) {
                        if member.status == "invited" { StatusPill(text: L("tm_invited"), color: Amber) }
                        if member.isExclusive == true { StatusPill(text: L("ws_exclusive"), color: wa.red) }
                    }
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
        }
    }
}

/// Inviting someone (`member` nil) or changing a member: a ready-made template (branch manager, quoter, driver) ticks its permissions,
/// which can then be ticked or unticked one by one — only the ones I hold myself (the server refuses the rest anyway). Then the
/// branches (all, or some), exclusivity (set when inviting; afterwards it can only be dropped), and an optional limit above which an
/// offer waits for the approver.
@MainActor
private struct MemberForm: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var session = Session.shared
    let member: MemberDto?
    let options: WorkspaceOptionsDto?
    let branches: [BranchDto]
    let onBack: () -> Void
    let onSaved: () -> Void
    let onOpenDriverDocs: (String) -> Void

    // The phone (inviting only), in the sign-in's own field.
    @State private var countries: [CountryDto] = []
    @State private var country: CountryDto?
    @State private var menuExpanded = false
    @State private var phone = ""

    @State private var picked: [String]
    @State private var allBranches: Bool
    @State private var pickedBranches: [Int]
    @State private var exclusive: Bool
    @State private var limit: String
    @State private var busy = false
    @State private var error: String?
    @State private var removing = false

    init(member: MemberDto?, options: WorkspaceOptionsDto?, branches: [BranchDto], onBack: @escaping () -> Void, onSaved: @escaping () -> Void,
         onOpenDriverDocs: @escaping (String) -> Void) {
        self.member = member
        self.options = options
        self.branches = branches
        self.onBack = onBack
        self.onSaved = onSaved
        self.onOpenDriverDocs = onOpenDriverDocs
        _picked = State(initialValue: member?.permissions ?? [])
        _allBranches = State(initialValue: member?.allBranches ?? true)
        _pickedBranches = State(initialValue: member?.branchIds ?? [])
        _exclusive = State(initialValue: member?.isExclusive ?? false)
        _limit = State(initialValue: member?.offerLimitMinor.map { formatMinor($0, nil).replacingOccurrences(of: "\u{200E}", with: "") } ?? "")
    }

    private var inviting: Bool { member == nil }
    private var phoneLength: Int { country?.phoneLength ?? 0 }
    private var phoneOk: Bool {
        !inviting || (country != nil && !phone.trimmingCharacters(in: .whitespaces).isEmpty && (phoneLength == 0 || phone.count == phoneLength))
    }
    private var limitMinor: Int? { limit.trimmingCharacters(in: .whitespaces).isEmpty ? nil : parseMinor(limit) }
    private var limitOk: Bool { limit.trimmingCharacters(in: .whitespaces).isEmpty || limitMinor != nil }
    private var branchesOk: Bool { allBranches || !pickedBranches.isEmpty }
    private var ready: Bool { phoneOk && limitOk && branchesOk }

    private func holds(_ key: String) -> Bool { session.membership.can(key) }

    var body: some View {
        let title = L(inviting ? "tm_invite" : "tm_edit")
        Page(title: title, onBack: onBack) {
            FormIntro(icon: "person.badge.plus", title: title, text: L("tm_form_body"))
            who
            templates
            FieldLabel(text: L("tm_permissions")).frame(maxWidth: .infinity, alignment: .leading)
            Text(L("tm_permissions_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
            if let options {
                ForEach(options.orderedGroups, id: \.group) { entry in
                    Card {
                        Text(verbatim: permissionGroupLabel(entry.group)).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                        ForEach(entry.keys, id: \.self) { key in
                            PermissionRow(key: key, checked: picked.contains(key), enabled: holds(key)) { toggle(key) }
                        }
                    }
                }
            } else {
                ProgressView().tint(wa.red).frame(width: 24, height: 24)
            }
            branchesCard
            exclusivityCard
            // The highest offer this person sends without approval (only matters with offers.send).
            if picked.contains("offers.send") {
                DorrTextField(value: $limit.profileMap(decimalInput(12)), label: L("tm_offer_limit"), placeholder: L("tm_offer_limit_hint"),
                              icon: "banknote.fill", keyboard: .decimalPad, error: !limitOk)
            }
            SettingsPrimaryButton(text: L(inviting ? "tm_send_invite" : "common_save"), enabled: ready, loading: busy, action: save)
            if !inviting {
                Button { removing = true } label: {
                    HStack(spacing: 6) {
                        Image(systemName: "person.badge.minus").font(.system(size: 15, weight: .semibold)).foregroundStyle(Red)
                        Text(L(member?.status == "invited" ? "tm_cancel_invite" : "tm_remove")).font(.dorr(14, .bold)).foregroundStyle(Red)
                    }
                }
                .buttonStyle(.plain)
                .disabled(busy)
            }
        }
        .messageToast(error)
        .task {
            guard inviting else { return }
            let listed = await GeneralApi.countries().value ?? []
            countries = listed
            country = listed.first { $0.id == Session.shared.me?.countryId } ?? listed.first { $0.isDefault == true } ?? listed.first
        }
        .dorrDialog(isPresented: $removing) {
            ConfirmDialog(icon: "person.badge.minus", title: L("tm_remove"), message: L("tm_remove_message"), loading: busy,
                          confirmLabel: L("tm_remove_confirm"), onConfirm: remove, onDismiss: { if !busy { removing = false } })
        }
    }

    @ViewBuilder private var who: some View {
        if inviting {
            PhoneField(
                countries: countries,
                selectedCountryId: country?.id,
                flagCode: notBlank(country?.flag?.code) ?? country?.code ?? "",
                dialCode: country?.formattedDial ?? "",
                menuExpanded: $menuExpanded,
                onCountrySelected: {
                    country = $0
                    menuExpanded = false
                },
                phoneStartsWith: country?.phoneStartsWith ?? "",
                phoneLength: phoneLength > 0 ? phoneLength : 15,
                phone: $phone,
                canSubmit: false,
                onSubmit: {}
            )
        } else if let member {
            Card {
                Text(verbatim: member.person?.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                Text(verbatim: "\u{200E}" + (member.person?.phone ?? member.phone ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                // A driver's licence, uploaded by them or by someone with fleet.drivers.
                if member.permissions?.contains("driving.work") == true {
                    ProfileTextLink(text: L("drv_docs_title")) { onOpenDriverDocs(member.uuid) }
                }
            }
        }
    }

    // Ready-made sets: a tap ticks exactly the template's keys I may give.
    @ViewBuilder private var templates: some View {
        let list = options?.orderedTemplates ?? []
        if !list.isEmpty {
            FieldLabel(text: L("tm_templates")).frame(maxWidth: .infinity, alignment: .leading)
            ChipRow(
                options: list.map { (value: $0.template, label: templateLabel($0.template)) },
                selected: Set(list.filter { entry in !entry.keys.isEmpty && Set(entry.keys.filter { holds($0) }) == Set(picked) }.map { $0.template }),
                onToggle: { key in picked = (list.first(where: { $0.template == key })?.keys ?? []).filter { holds($0) } }
            )
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }

    // Branches: all, or the chosen ones.
    private var branchesCard: some View {
        Card {
            HStack {
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("tm_all_branches")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                    Text(L("tm_all_branches_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                RedToggle(on: allBranches) { allBranches.toggle() }
            }
            if !allBranches {
                ChipRow(
                    options: branches.map { (value: String($0.id ?? 0), label: $0.name ?? "") },
                    selected: Set(pickedBranches.map { String($0) }),
                    onToggle: { value in
                        guard let id = Int(value) else { return }
                        if let i = pickedBranches.firstIndex(of: id) { pickedBranches.remove(at: i) } else { pickedBranches.append(id) }
                    }
                )
                if pickedBranches.isEmpty { Text(L("tm_pick_branch")).font(.dorr(12)).foregroundStyle(Red) }
            }
        }
    }

    // Exclusivity, said plainly (§14): set when inviting; a member's exclusivity can only be dropped.
    @ViewBuilder private var exclusivityCard: some View {
        if inviting || member?.isExclusive == true {
            Card {
                HStack(spacing: 8) {
                    Image(systemName: "lock.fill").font(.system(size: 16, weight: .semibold)).foregroundStyle(Amber).frame(width: 20)
                    Text(L("ws_exclusive")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                    RedToggle(on: exclusive) { if inviting || exclusive { exclusive.toggle() } }
                }
                Text(L("tm_exclusive_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                if !inviting { Text(L("tm_exclusive_drop_only")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3) }
            }
        }
    }

    private func toggle(_ key: String) {
        if let i = picked.firstIndex(of: key) { picked.remove(at: i) } else { picked.append(key) }
    }

    private func save() {
        busy = true
        error = nil
        // `jsonBody`: an explicit null is how "all branches" and "no limit" are saved; a missing key would change nothing.
        var body: JsonBody = ["permissions": picked]
        if inviting {
            body["dial_code"] = country?.formattedDial ?? ""
            body["phone"] = phone
        }
        body.updateValue(allBranches ? nil : pickedBranches, forKey: "branch_ids")
        // Exclusivity is agreed when inviting; afterwards it can only be dropped (MembershipService::update).
        if inviting || !exclusive { body["is_exclusive"] = exclusive }
        body.updateValue(limitMinor, forKey: "offer_limit_minor")
        let sent = body
        Task {
            let failure: String?
            if let member {
                let result = await ManageApi.updateMember(member.uuid, sent)
                failure = result.value != nil ? nil : result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            } else {
                let result = await ManageApi.invite(sent)
                failure = result.value != nil ? nil : result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
            busy = false
            if let failure { error = failure } else { onSaved() }
        }
    }

    private func remove() {
        guard let target = member else { return }
        busy = true
        Task {
            let failed = await ManageApi.removeMember(target.uuid, offline: L("login_error_offline"), generic: L("login_error_generic"))
            busy = false
            removing = false
            if let failed { error = failed } else { onSaved() }
        }
    }
}

/// One permission: a tick box, its name and what it lets the person do; a key I don't hold is shown but can't be ticked.
private struct PermissionRow: View {
    @Environment(\.wa) private var wa
    let key: String
    let checked: Bool
    let enabled: Bool
    let onToggle: () -> Void

    var body: some View {
        let tint: Color = !enabled ? wa.settingsMut.opacity(0.4) : (checked ? wa.red : (wa.night ? wa.line : wa.settingsMut))
        Button(action: onToggle) {
            HStack(alignment: .top, spacing: 8) {
                Image(systemName: checked ? "checkmark.square.fill" : "square").font(.system(size: 18)).foregroundStyle(tint).frame(width: 22, height: 22)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: permissionLabel(key)).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk.opacity(enabled ? 1 : 0.5))
                    Text(verbatim: enabled ? permissionHint(key) : L("tm_not_yours")).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(.vertical, 2)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

/// The team's log (REQ-002): who was invited, joined, changed, removed or left — newest first, page by page.
@MainActor
private struct TeamLog: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    @StateObject private var paged = PagedRows<MemberEventDto>(
        perPage: 30,
        key: { "\($0.createdAt ?? "")|\($0.action ?? "")|\($0.member?.uuid ?? "")|\($0.actor?.name ?? "")" },
        fetch: { page in await ManageApi.memberEvents(page: page) }
    )

    var body: some View {
        Page(title: L("tm_log"), onBack: onBack, onNearEnd: { Task { await paged.loadMore() } }) {
            if !paged.loaded {
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
            } else if paged.rows.isEmpty {
                EmptyState(icon: "clock.arrow.circlepath", title: L("tm_log_empty"), body: "").padding(.top, 30)
            } else {
                ForEach(Array(paged.rows.enumerated()), id: \.offset) { _, event in
                    RowCard(icon: "clock.arrow.circlepath", title: title(event), subtitle: subtitle(event), onClick: nil)
                }
            }
            PagedFooter(paged: paged) { Task { await paged.loadMore() } }
        }
        .messageToast(paged.failure?.errorText(L("login_error_offline")))
        .task(id: network.reconnectTick) { await paged.reload() }
    }

    /// What happened to whom.
    private func title(_ event: MemberEventDto) -> String {
        [eventLabel(event.action), personText(event.member)].compactMap { $0 }.joined(separator: ": ")
    }

    /// By whom and when.
    private func subtitle(_ event: MemberEventDto) -> String {
        let by = personText(event.actor).map { L("tm_ev_by", $0) }
        return [by, dateTimeText(event.createdAt)].compactMap { $0 }.joined(separator: " · ")
    }

    private func personText(_ person: MemberEventPersonDto?) -> String? {
        notBlank(person?.name) ?? person?.phone.map { "\u{200E}" + $0 }
    }

    private func eventLabel(_ action: String?) -> String {
        switch action {
        case "invited": return L("tm_ev_invited")
        case "joined": return L("tm_ev_joined")
        case "declined": return L("tm_ev_declined")
        case "permissions_changed": return L("tm_ev_changed")
        case "removed": return L("tm_ev_removed")
        case "left": return L("tm_ev_left")
        default: return action ?? ""
        }
    }
}
