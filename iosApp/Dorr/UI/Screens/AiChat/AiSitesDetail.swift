import SwiftUI
import UIKit

// androidApp `aichat/AiSitesDetail.kt`, `AiSiteHostingCard.kt`, `AiSiteEditPacksCard.kt`, `AiSiteSourceCard.kt` — one
// site: open / copy its link, retry a failed first build, then three tabs — edit (ask for changes, buy edit packs),
// versions (restore an older one), publish (the source ZIP, hosting on its own address: plans, name check, pay with
// the PIN, publish, auto-renew, renew). While a build runs it is read again every 3 s, like Android.

struct SitesDetail: View {
    @Environment(\.wa) private var wa
    @Environment(\.scenePhase) private var scenePhase
    @EnvironmentObject private var network: NetworkMonitor
    let id: Int
    let onDeleted: () -> Void
    @State private var project: AiSiteProjectDto?
    @State private var instruction = ""
    @State private var busy = false
    @State private var error: String?
    @State private var confirmDelete = false
    @State private var editsBlocked = false
    @State private var tab = 0

    private func refresh() async { if let p = try? await AiSitesApi.project(id).data { project = p } }

    /// The project or one of its versions is not settled yet.
    private var isBuilding: Bool {
        guard let p = project else { return true }
        return p.status == "generating" || p.versionList.contains { $0.status == "processing" || $0.status == "pending" }
    }

    private func act(_ block: @escaping () async throws -> AiSiteProjectDto?) {
        busy = true
        error = nil
        Task {
            do { if let p = try await block() { project = p } } catch { self.error = error.apiFailure.message }
            busy = false
        }
    }

    var body: some View {
        let c = wa.site
        Group {
            if let p = project {
                let hasSite = p.versionList.contains { $0.isCurrent == true }
                let idle = p.isDisabled != true && p.status != "generating"
                let tabbed = hasSite && p.isDisabled != true
                let section = tabbed ? tab : 1
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) {
                        hero(p)
                        if p.status == "generating" { building.padding(.top, 14) }
                        if let message = p.lastErrorMessage ?? p.lastError {
                            Text(verbatim: message).font(.dorr(12.5)).foregroundStyle(c.danger).lineSpacing(4)
                                .frame(maxWidth: .infinity, alignment: .leading).padding(14)
                                .background(c.danger.opacity(0.1), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                                .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(c.danger.opacity(0.3), lineWidth: 1))
                                .padding(.top, 14)
                        }
                        if let error { Text(verbatim: error).font(.dorr(12.5)).foregroundStyle(c.danger).padding(.top, 10) }
                        if let url = p.previewUrl {
                            HStack(spacing: 10) {
                                SitePrimaryButton(label: L("ai_sites_open")) { if let u = URL(string: url) { UIApplication.shared.open(u) } }
                                SiteSecondaryButton(label: L("ai_sites_copy_link")) {
                                    UIPasteboard.general.string = url
                                    AppToast.shared.show(L("ai_sites_link_copied"))
                                }
                                .frame(maxWidth: 150)
                            }
                            .padding(.top, 14)
                        }
                        if p.status == "failed" && !hasSite {
                            SitePrimaryButton(label: L("ai_sites_retry"), enabled: !busy) { act { try await AiSitesApi.retry(id).data } }.padding(.top, 12)
                        }
                        if tabbed { tabs.padding(.top, 16) }
                        switch section {
                        case 0:
                            if idle {
                                SiteCard {
                                    SiteSectionTitle(title: L("ai_sites_edit_title"), subtitle: L("ai_sites_edit_sub"))
                                    if editsBlocked { Text(L("ai_sites_packs_blocked_hint")).font(.dorr(12)).foregroundStyle(c.danger).lineSpacing(3).padding(.top, 8) }
                                    SiteField(label: L("ai_sites_edit_hint"), text: $instruction, lines: 3...6).padding(.top, 8)
                                    SitePrimaryButton(label: L("ai_sites_edit_send"), enabled: !busy && !editsBlocked && instruction.trimmingCharacters(in: .whitespaces).count >= 3) {
                                        let text = instruction.trimmingCharacters(in: .whitespacesAndNewlines)
                                        instruction = ""
                                        act { try await AiSitesApi.edit(id, instruction: text).data }
                                    }
                                    .padding(.top, 6)
                                }
                                .padding(.top, 14)
                                SiteEditPacksCard(projectId: p.id, reloadKey: p.versionList.map { "\($0.id)\($0.status)" }.joined(), onBought: { Task { await refresh() } }) { editsBlocked = $0 }
                                    .padding(.top, 14)
                            }
                        case 1:
                            if !p.versionList.isEmpty {
                                SiteCard {
                                    SiteSectionTitle(title: L("ai_sites_versions")).padding(.bottom, 4)
                                    ForEach(p.versionList) { v in
                                        versionRow(v, canRestore: v.isCurrent != true && v.status == "completed" && idle) {
                                            act { try await AiSitesApi.restore(id, number: v.number).data }
                                        }
                                    }
                                }
                                .padding(.top, 14)
                            }
                        default:
                            SiteSourceCard(projectId: p.id).padding(.top, 14)
                            SiteHostingCard(projectId: p.id).padding(.top, 14)
                        }
                        SiteSecondaryButton(label: L("ai_sites_delete"), color: c.danger) { confirmDelete = true }.padding(.top, 20)
                    }
                    .padding(.horizontal, 16).padding(.bottom, 28)
                }
                .scrollDismissesKeyboard(.interactively)
                .refreshable { await refresh() }
            } else {
                ProgressView().tint(wa.accent).frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .task(id: id) { await refresh() }
        // Like Android: while the server is still building, read it again every 3 s (keyed on `building`, so it
        // starts again by itself when an edit or retry flips the site back to «generating»).
        .task(id: "\(id)|\(isBuilding)") {
            while isBuilding && !Task.isCancelled {
                try? await Task.sleep(nanoseconds: 3_000_000_000)
                guard !Task.isCancelled else { return }
                await refresh()
            }
        }
        .onChange(of: scenePhase) { if $0 == .active { Task { await refresh() } } }
        .onChange(of: network.reconnectTick) { _ in Task { await refresh() } }
        .dorrDialog(isPresented: $confirmDelete) {
            AiConfirmDialog(title: L("ai_sites_delete"), message: L("ai_sites_delete_confirm"), onConfirm: {
                confirmDelete = false
                Task { if (try? await AiSitesApi.delete(id)) != nil { onDeleted() } }
            }, onDismiss: { confirmDelete = false })
        }
    }

    private func hero(_ p: AiSiteProjectDto) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 14) {
                Text(verbatim: String(p.title.trimmingCharacters(in: .whitespaces).prefix(1)).uppercased()).font(.dorr(24, .heavy)).foregroundStyle(.white)
                    .frame(width: 56, height: 56).background(Color.white.opacity(0.2), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                VStack(alignment: .leading, spacing: 6) {
                    Text(verbatim: p.title).font(.dorr(20, .heavy)).foregroundStyle(.white).lineLimit(2)
                    SiteStatusChip(status: p.status, disabled: p.isDisabled == true, onDark: true)
                }
                Spacer(minLength: 0)
            }
            if let left = p.generationsLeft {
                Text(verbatim: L("ai_sites_generations_left", left)).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.88)).padding(.top, 14)
            }
        }
        .padding(20)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(siteGradient(wa.accent), in: RoundedRectangle(cornerRadius: 26, style: .continuous))
    }

    private var building: some View {
        let c = wa.site
        return SiteCard {
            Text(L("ai_sites_building_title")).font(.dorr(15, .heavy)).foregroundStyle(c.ink)
            ProgressView().progressViewStyle(.linear).tint(wa.accent).padding(.top, 10)
            Text(L("ai_sites_building")).font(.dorr(12.5)).foregroundStyle(c.mut).lineSpacing(4).padding(.top, 10)
        }
    }

    /// Edit / versions / publishing as three tabs.
    private var tabs: some View {
        let c = wa.site
        let items: [(String, String)] = [("pencil", "ai_sites_tab_edit"), ("clock.arrow.circlepath", "ai_sites_tab_versions"), ("globe", "ai_sites_tab_publish")]
        return HStack(spacing: 4) {
            ForEach(Array(items.enumerated()), id: \.offset) { i, item in
                let on = i == tab
                Button { tab = i } label: {
                    HStack(spacing: 6) {
                        Image(systemName: item.0).font(.system(size: 14))
                        Text(L(item.1)).font(.dorr(12.5, on ? .heavy : .semibold)).lineLimit(1)
                    }
                    .foregroundStyle(on ? .white : c.mut)
                    .frame(maxWidth: .infinity).padding(.vertical, 11)
                    .background { if on { RoundedRectangle(cornerRadius: 14, style: .continuous).fill(siteGradient(wa.accent)) } }
                }
                .buttonStyle(.plain)
            }
        }
        .padding(5)
        .background(c.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(c.line, lineWidth: 1))
        .animation(.easeInOut(duration: 0.2), value: tab)
    }

    private func versionRow(_ v: AiSiteVersionDto, canRestore: Bool, onRestore: @escaping () -> Void) -> some View {
        let c = wa.site
        let current = v.isCurrent == true
        return HStack(spacing: 12) {
            Text(verbatim: "\(v.number)").font(.dorr(13, .heavy)).foregroundStyle(current ? .white : wa.accent)
                .frame(width: 34, height: 34).background(current ? wa.accent : wa.accent.opacity(0.1), in: Circle())
            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 8) {
                    Text(verbatim: L("ai_sites_version_n", v.number)).font(.dorr(13.5, .bold)).foregroundStyle(c.ink)
                    if current {
                        Text(L("ai_sites_current")).font(.dorr(10.5, .bold)).foregroundStyle(c.success).padding(.horizontal, 8).padding(.vertical, 2).background(c.success.opacity(0.14), in: Capsule())
                    }
                }
                if let i = v.instruction { Text(verbatim: i).font(.dorr(11.5)).foregroundStyle(c.mut).lineLimit(2) }
                if v.status == "failed" { Text(L("ai_sites_status_failed")).font(.dorr(11.5)).foregroundStyle(c.danger) }
            }
            Spacer(minLength: 0)
            if canRestore {
                Button(action: onRestore) {
                    Text(L("ai_sites_restore")).font(.dorr(12, .bold)).foregroundStyle(wa.accent).padding(.horizontal, 12).padding(.vertical, 8)
                        .background(wa.accent.opacity(0.1), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                .buttonStyle(.plain)
                .disabled(busy)
            }
        }
        .padding(.vertical, 7)
    }
}

// MARK: - Hosting

private enum HostingPin: Equatable {
    case subscribe(AiSiteHostingPlanDto, String)
    case renew
}

/// Hosting on its own address (monthly / yearly, priced per country): pick a plan and a name, pay from the wallet
/// (PIN), then publish the version the public sees, switch auto-renew or renew by hand. Editing never changes the
/// public version by itself.
private struct SiteHostingCard: View {
    @Environment(\.wa) private var wa
    let projectId: Int
    @State private var loading = true
    @State private var plans: AiSiteHostingPlansDto?
    @State private var hosting: AiSiteHostingDto?
    @State private var selected: AiSiteHostingPlanDto?
    @State private var name = ""
    @State private var nameCheck: String?
    @State private var nameOk = false
    @State private var busy = false
    @State private var error: String?
    @State private var pin: HostingPin?
    @State private var noPin = false

    private func act(_ block: @escaping () async throws -> AiSiteHostingDto?) {
        busy = true
        error = nil
        Task {
            do { if let h = try await block() { hosting = h } } catch { self.error = error.apiFailure.message }
            busy = false
        }
    }

    var body: some View {
        let c = wa.site
        SiteCard {
            HStack(spacing: 10) {
                Image(systemName: "globe").font(.system(size: 18)).foregroundStyle(wa.accent).frame(width: 36, height: 36)
                    .background(wa.accent.opacity(0.1), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                Text(L("ai_sites_hosting_title")).font(.dorr(15, .heavy)).foregroundStyle(c.ink)
            }
            .padding(.bottom, 12)
            if loading {
                ProgressView().tint(wa.accent)
            } else if let current = hosting {
                hosted(current)
            } else if let info = plans, info.enabled != false, (info.plans ?? []).contains(where: { $0.available == true }) {
                offer(info)
            } else {
                Text(L("ai_sites_hosting_unavailable")).font(.dorr(12.5)).foregroundStyle(c.mut)
            }
            if let error { Text(verbatim: error).font(.dorr(12.5)).foregroundStyle(c.danger).padding(.top, 8) }
        }
        .task(id: projectId) {
            plans = try? await AiSitesApi.hostingPlans().data
            hosting = (try? await AiSitesApi.hosting(projectId).data)?.hosting
            loading = false
        }
        .dorrDialog(isPresented: $noPin) { AiInfoDialog(message: L("ai_subscription_no_pin_set")) { noPin = false } }
        .dorrDialog(isPresented: Binding(get: { pin != nil }, set: { if !$0 { pin = nil } })) {
            if let action = pin {
                AiWalletPinDialog(subtitle: L("ai_sites_hosting_pin_sub"), onDismiss: { pin = nil }) { code in
                    do {
                        switch action {
                        case .subscribe(let plan, let n): hosting = try await AiSitesApi.hostingSubscribe(projectId, pin: code, planId: plan.id, subdomain: n).data
                        case .renew: hosting = try await AiSitesApi.hostingRenew(projectId, pin: code).data
                        }
                        return .ok
                    } catch {
                        return padFailure(error, networkError: L("ai_send_failed"))
                    }
                }
            }
        }
    }

    @ViewBuilder private func hosted(_ current: AiSiteHostingDto) -> some View {
        let c = wa.site
        let live = current.isLive == true
        let auto = current.autoRenew == true
        Text(verbatim: current.url).font(.dorr(13, .semibold)).foregroundStyle(wa.accent).environment(\.layoutDirection, .leftToRight)
        Text(L(live ? "ai_sites_hosting_live" : "ai_sites_hosting_offline")).font(.dorr(12.5)).foregroundStyle(live ? c.mut : c.danger)
        if let ends = current.endsAt { Text(verbatim: L("ai_sites_hosting_until", String(ends.prefix(10)))).font(.dorr(12)).foregroundStyle(c.mut) }
        Text(L(auto ? "ai_sites_hosting_auto_on" : "ai_sites_hosting_auto_off")).font(.dorr(12)).foregroundStyle(c.mut)
        if current.status == "grace" { Text(L("ai_sites_hosting_grace")).font(.dorr(12)).foregroundStyle(c.danger) }
        if live {
            SitePrimaryButton(label: L("ai_sites_hosting_open")) { if let u = URL(string: current.url) { UIApplication.shared.open(u) } }.padding(.top, 10)
        }
        if live && current.hasUnpublishedChanges == true {
            Text(L("ai_sites_hosting_unpublished")).font(.dorr(12)).foregroundStyle(c.mut).padding(.top, 8)
            SitePrimaryButton(label: L("ai_sites_hosting_publish"), enabled: !busy) { act { try await AiSitesApi.hostingPublish(projectId).data } }
        }
        HStack(spacing: 8) {
            smallButton(L("ai_sites_hosting_renew")) { aiNeedsPin(noPin: { noPin = true }, askPin: { pin = .renew }) }
            smallButton(L(auto ? "ai_sites_hosting_stop_auto" : "ai_sites_hosting_start_auto")) { act { try await AiSitesApi.hostingAutoRenew(projectId, !auto).data } }
        }
        .padding(.top, 8)
    }

    private func smallButton(_ label: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: label).font(.dorr(12.5, .bold)).foregroundStyle(wa.accent).frame(maxWidth: .infinity).padding(10)
                .background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
        .disabled(busy)
    }

    @ViewBuilder private func offer(_ info: AiSiteHostingPlansDto) -> some View {
        let c = wa.site
        let available = (info.plans ?? []).filter { $0.available == true }
        Text(L("ai_sites_hosting_intro")).font(.dorr(12.5)).foregroundStyle(c.mut).lineSpacing(4)
        HStack(spacing: 10) {
            ForEach(available) { plan in
                let on = selected?.id == plan.id
                Button { selected = plan } label: {
                    VStack(alignment: .leading, spacing: 0) {
                        HStack(spacing: 8) {
                            Circle().stroke(on ? wa.accent : c.mut, lineWidth: 2).frame(width: 18, height: 18)
                                .overlay { if on { Circle().fill(wa.accent).frame(width: 8, height: 8) } }
                            Text(verbatim: plan.name).font(.dorr(13, .bold)).foregroundStyle(c.ink).lineLimit(1)
                        }
                        Text(verbatim: plan.price.map { sitePrice($0) } ?? "").font(.dorr(22, .heavy)).foregroundStyle(on ? wa.accent : c.ink).padding(.top, 10)
                        Text(verbatim: "\(plan.currency ?? "") / " + L(plan.period == "yearly" ? "ai_sites_hosting_yearly" : "ai_sites_hosting_monthly")).font(.dorr(11.5)).foregroundStyle(c.mut)
                    }
                    .padding(14)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(on ? wa.accent.opacity(0.1) : c.field, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(on ? wa.accent : c.line, lineWidth: on ? 1.5 : 1))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.top, 12)
        SiteField(label: L("ai_sites_hosting_name"), text: Binding(get: { name }, set: { name = $0.lowercased().trimmingCharacters(in: .whitespaces); nameOk = false; nameCheck = nil }), keyboard: .URL)
            .textInputAutocapitalization(.never)
            .padding(.top, 14)
        // The address is always Latin: left-to-right so the dots and slashes keep their order.
        HStack(spacing: 8) {
            Image(systemName: "globe").font(.system(size: 16)).foregroundStyle(wa.accent)
            Text(verbatim: info.mode == "subdomain" ? "\(name.isEmpty ? "name" : name).\(info.domain ?? "")" : "sites/\(name.isEmpty ? "name" : name)")
                .font(.dorr(13, .semibold)).foregroundStyle(c.ink).lineLimit(1)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 12).padding(.vertical, 10)
        .background(c.field, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        .environment(\.layoutDirection, .leftToRight)
        .padding(.top, 4)
        if let nameCheck, !nameCheck.isEmpty { Text(verbatim: nameCheck).font(.dorr(12)).foregroundStyle(nameOk ? c.success : c.danger).padding(.top, 8) }
        if nameOk { Text(L("ai_sites_hosting_name_ok")).font(.dorr(12, .semibold)).foregroundStyle(c.success).padding(.top, 8) }
        Group {
            if !nameOk {
                SitePrimaryButton(label: L("ai_sites_hosting_check"), enabled: !busy && name.count >= 3) {
                    busy = true
                    Task {
                        do {
                            let r = try await AiSitesApi.hostingCheck(name).data
                            nameOk = r?.available == true
                            nameCheck = nameOk ? "" : r?.message
                        } catch {
                            nameCheck = error.apiFailure.message
                        }
                        busy = false
                    }
                }
            } else {
                SitePrimaryButton(label: L("ai_sites_hosting_subscribe"), enabled: !busy && selected != nil) {
                    if let plan = selected { let n = name; aiNeedsPin(noPin: { noPin = true }, askPin: { pin = .subscribe(plan, n) }) }
                }
                if selected == nil { Text(L("ai_sites_hosting_pick_plan")).font(.dorr(11.5)).foregroundStyle(c.mut).padding(.top, 6) }
            }
        }
        .padding(.top, 14)
    }
}

// MARK: - Edit packs

/// More AI edits for this one site once its own allowance is used up, paid from the wallet behind the PIN.
/// `reloadKey` changes after every build / edit so «edits left» stays current; `onState` says whether it is out.
private struct SiteEditPacksCard: View {
    @Environment(\.wa) private var wa
    let projectId: Int
    let reloadKey: String
    let onBought: () -> Void
    let onState: (Bool) -> Void
    @State private var data: AiSiteEditPacksDto?
    @State private var picked: AiSiteOfferDto?
    @State private var noPin = false
    @State private var bought = false
    @State private var loading = true

    var body: some View {
        let c = wa.site
        let packs = (data?.packs ?? []).filter { $0.available == true }
        let blocked = data?.edits?.blocked == true
        Group {
            if !loading && (!packs.isEmpty || blocked) {
                SiteCard {
                    HStack(spacing: 10) {
                        Image(systemName: "sparkles").font(.system(size: 18)).foregroundStyle(wa.accent).frame(width: 36, height: 36)
                            .background(wa.accent.opacity(0.1), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                        VStack(alignment: .leading, spacing: 0) {
                            SiteSectionTitle(title: L("ai_sites_packs_title"))
                            Text(verbatim: blocked ? L("ai_sites_packs_empty") : L("ai_sites_packs_left", data?.edits?.total ?? 0))
                                .font(.dorr(12, blocked ? .semibold : .regular)).foregroundStyle(blocked ? c.danger : c.mut)
                        }
                    }
                    if bought { Text(L("ai_sites_packs_bought")).font(.dorr(12.5, .semibold)).foregroundStyle(c.success).padding(.top, 10) }
                    VStack(spacing: 8) {
                        ForEach(packs) { pack in
                            Button {
                                bought = false
                                aiNeedsPin(noPin: { noPin = true }, askPin: { picked = pack })
                            } label: {
                                HStack {
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(verbatim: pack.name).font(.dorr(14, .bold)).foregroundStyle(c.ink)
                                        Text(verbatim: L("ai_sites_packs_edits", pack.generationsIncluded ?? 0)).font(.dorr(12)).foregroundStyle(c.mut)
                                    }
                                    Spacer()
                                    Text(verbatim: "\(pack.price.map { sitePrice($0) } ?? "") \(pack.currency ?? "")".trimmingCharacters(in: .whitespaces)).font(.dorr(13, .heavy)).foregroundStyle(wa.accent)
                                        .padding(.horizontal, 12).padding(.vertical, 8).background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                                }
                                .padding(14)
                                .background(c.field, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                                .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(c.line, lineWidth: 1))
                            }
                            .buttonStyle(AiPressStyle())
                        }
                    }
                    .padding(.top, 12)
                }
            }
        }
        .task(id: "\(projectId)|\(reloadKey)") {
            if let d = try? await AiSitesApi.editPacks(projectId).data {
                data = d
                onState(d.edits?.blocked == true)
            }
            loading = false
        }
        .dorrDialog(isPresented: $noPin) { AiInfoDialog(message: L("ai_subscription_no_pin_set")) { noPin = false } }
        .dorrDialog(isPresented: Binding(get: { picked != nil }, set: { if !$0 { picked = nil } })) {
            if let target = picked {
                AiWalletPinDialog(subtitle: L("ai_sites_packs_pin_sub", target.name), onDismiss: { picked = nil }) { pin in
                    do {
                        let updated = try await AiSitesApi.buyEditPack(projectId, pin: pin, offerId: target.id).data
                        data = updated
                        onState(updated?.edits?.blocked == true)
                        bought = true
                        onBought()
                        return .ok
                    } catch {
                        return padFailure(error, networkError: L("ai_send_failed"))
                    }
                }
            }
        }
    }
}

// MARK: - The source ZIP

/// The site's files as a ZIP. The admin decides whether it is on, free or paid (once per site, wallet + PIN), so the
/// card only shows what is possible. Saved through the share sheet (Files, AirDrop…) — Android saves to Downloads.
private struct SiteSourceCard: View {
    @Environment(\.wa) private var wa
    let projectId: Int
    @State private var status: AiSiteSourceDownloadDto?
    @State private var askPin = false
    @State private var noPin = false
    @State private var busy = false
    @State private var message: String?
    @State private var failed = false

    @MainActor private func download() {
        busy = true
        message = nil
        Task {
            do {
                let data = try await AiSitesApi.downloadSource(projectId)
                let file = FileManager.default.temporaryDirectory.appendingPathComponent("site-\(projectId).zip")
                try data.write(to: file)
                failed = false
                message = L("ai_sites_src_saved")
                presentShare(file)
            } catch {
                failed = true
                message = error.apiFailure.message ?? L("ai_sites_src_failed")
            }
            busy = false
        }
    }

    var body: some View {
        let c = wa.site
        Group {
            if let s = status, s.enabled == true {
                SiteCard {
                    HStack(spacing: 12) {
                        Image(systemName: "doc.zipper").font(.system(size: 20)).foregroundStyle(wa.accent).frame(width: 40, height: 40)
                            .background(wa.accent.opacity(0.1), in: RoundedRectangle(cornerRadius: 13, style: .continuous))
                        VStack(alignment: .leading, spacing: 2) {
                            Text(L("ai_sites_src_title")).font(.dorr(15, .heavy)).foregroundStyle(c.ink)
                            Text(L("ai_sites_src_sub")).font(.dorr(12)).foregroundStyle(c.mut).lineSpacing(3)
                        }
                        Spacer(minLength: 0)
                        let badge: (String, Color)? = s.free == true ? (L("ai_sites_src_free"), c.success) : s.unlocked == true ? (L("ai_sites_src_unlocked"), c.success)
                            : s.price.map { ("\(sitePrice($0)) \(s.currency ?? "")".trimmingCharacters(in: .whitespaces), wa.accent) }
                        if let badge {
                            Text(verbatim: badge.0).font(.dorr(11.5, .heavy)).foregroundStyle(badge.1).padding(.horizontal, 10).padding(.vertical, 5).background(badge.1.opacity(0.13), in: Capsule())
                        }
                    }
                    Group {
                        if busy {
                            ProgressView().tint(wa.accent).frame(maxWidth: .infinity, minHeight: 52)
                        } else if s.canDownload == true {
                            SitePrimaryButton(label: L("ai_sites_src_download")) { download() }
                        } else if s.purchasable == true {
                            SitePrimaryButton(label: L("ai_sites_src_unlock", "\(s.price.map { sitePrice($0) } ?? "") \(s.currency ?? "")".trimmingCharacters(in: .whitespaces))) {
                                aiNeedsPin(noPin: { noPin = true }, askPin: { askPin = true })
                            }
                        } else {
                            Text(L("ai_sites_src_unavailable")).font(.dorr(12.5)).foregroundStyle(c.mut)
                        }
                    }
                    .padding(.top, 14)
                    if let message { Text(verbatim: message).font(.dorr(12.5, .semibold)).foregroundStyle(failed ? c.danger : c.success).padding(.top, 10) }
                }
            }
        }
        .task(id: projectId) { status = try? await AiSitesApi.sourceDownload(projectId).data }
        .dorrDialog(isPresented: $noPin) { AiInfoDialog(message: L("ai_subscription_no_pin_set")) { noPin = false } }
        .dorrDialog(isPresented: $askPin) {
            AiWalletPinDialog(subtitle: L("ai_sites_src_pin_sub"), onDismiss: { askPin = false }) { pin in
                do {
                    if let updated = try await AiSitesApi.buySourceDownload(projectId, pin: pin).data { status = updated }
                    message = nil
                    return .ok
                } catch {
                    return padFailure(error, networkError: L("ai_send_failed"))
                }
            }
        }
    }
}

/// The system share sheet for a file, from the top-most screen.
@MainActor
func presentShare(_ file: URL) {
    guard let root = UIApplication.shared.connectedScenes.compactMap({ ($0 as? UIWindowScene)?.keyWindow }).first?.rootViewController else { return }
    var top = root
    while let next = top.presentedViewController { top = next }
    let sheet = UIActivityViewController(activityItems: [file], applicationActivities: nil)
    sheet.popoverPresentationController?.sourceView = top.view
    top.present(sheet, animated: true)
}
