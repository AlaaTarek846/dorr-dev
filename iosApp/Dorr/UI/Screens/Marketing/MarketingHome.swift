import SwiftUI

/// androidApp `ui/screens/marketing/MarketingHome.kt` — the landing page: is it open here, my package, my projects
/// (pages of 15, search), a new project; and the packages page.
struct MarketingHome: View {
    @EnvironmentObject private var host: MkHost
    @ObservedObject private var net = NetworkMonitor.shared
    @Environment(\.wa) private var wa

    @State private var statusError = false
    @State private var projects: [MkProjectDto] = []
    @State private var page = 1
    @State private var hasMore = false
    @State private var loaded = false
    @State private var loadingMore = false
    @State private var query = ""
    @State private var reload = 0
    @State private var creating = false

    var body: some View {
        let status = host.status
        let open = status?.available == true
        let subscribed = status?.subscription?.active == true
        Group {
            if open && subscribed {
                MkPage(title: L("mktg_title"), cta: {
                    WaButton(text: L("mktg_new_project"), icon: "plus") { creating = true }
                }, content: { content(status) })
            } else {
                MkPage(title: L("mktg_title")) { content(status) }
            }
        }
        .task(id: "\(host.statusTick)|\(net.reconnectTick)") { await loadStatus() }
        .task(id: "\(open)|\(query)|\(reload)|\(net.reconnectTick)") {
            guard open else { return }
            if !query.isEmpty { try? await Task.sleep(nanoseconds: 350_000_000) }
            guard !Task.isCancelled else { return }
            await loadProjects()
        }
        .weSheet(creating, onDismiss: { creating = false }) {
            NewProjectSheet { created in
                creating = false
                reload += 1
                host.reloadStatus()
                host.push(.project(id: created.id))
            }
        }
    }

    @ViewBuilder
    private func content(_ status: MkStatusDto?) -> some View {
        if status == nil && statusError {
            WaEmpty(icon: "megaphone", tone: .gray, title: L("mktg_error_generic"), text: "") {
                WaButton(text: L("mktg_retry"), style: .ghost, compact: true) { host.reloadStatus() }
            }
        } else if status == nil {
            VStack(spacing: 12) {
                WaSkeleton(cornerRadius: 22).frame(height: 120)
                ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 70) }
            }
        } else if status?.available != true {
            WaEmpty(icon: "globe", tone: .blue, title: L("mktg_unavailable_title"), text: L("mktg_unavailable_text"))
        } else {
            PackageCard()
            if status?.researchReady != true {
                WaNote(text: L("mktg_research_not_ready")).padding(.top, 10)
            }
            WaSectionTitle(title: L("mktg_projects"))
            WeTextField(value: $query, placeholder: L("mktg_search_projects"), icon: "magnifyingglass", limit: 100)
                .padding(.bottom, 12)
            if !loaded {
                VStack(spacing: 10) { ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 70) } }
            } else if projects.isEmpty {
                WaEmpty(icon: "building.2", tone: .red, title: L("mktg_no_projects_title"), text: L("mktg_no_projects_text"))
            } else {
                VStack(spacing: 10) {
                    ForEach(Array(projects.enumerated()), id: \.element.id) { i, p in
                        ProjectRow(project: p) { host.push(.project(id: p.id)) }.waRise(min(i, 6))
                    }
                    if hasMore {
                        WaButton(text: L("mktg_load_more"), style: .ghost, loading: loadingMore, compact: true) { Task { await loadMore() } }
                    }
                }
            }
        }
    }

    private func loadStatus() async {
        statusError = false
        do {
            host.status = try await MarketingApi.status().data
        } catch {
            if host.status == nil { statusError = true }
        }
    }

    private func loadProjects() async {
        if let env = try? await MarketingApi.projects(q: query.isEmpty ? nil : query, page: 1) {
            projects = env.data ?? []
            page = 1
            hasMore = env.pagination?.hasMorePages == true
        }
        loaded = true
    }

    private func loadMore() async {
        loadingMore = true
        do {
            let env = try await MarketingApi.projects(q: query.isEmpty ? nil : query, page: page + 1)
            for p in env.data ?? [] where !projects.contains(where: { $0.id == p.id }) { projects.append(p) }
            page += 1
            hasMore = env.pagination?.hasMorePages == true
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        loadingMore = false
    }
}

/// My package: active until …, what is used of the period — or the way to subscribe.
private struct PackageCard: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa

    var body: some View {
        let sub = host.status?.subscription
        let active = sub?.active == true
        WaCard(padding: 16) {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 12) {
                    WaIconWell(icon: "crown.fill", tone: active ? .green : .amber)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: active ? (sub?.pkg?.name ?? L("mktg_package")) : L("mktg_no_package_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.ink)
                        Text(verbatim: active ? L("mktg_active_until", mkDate(sub?.paidUntil ?? sub?.endsAt)) : L("mktg_no_package_text"))
                            .font(.dorr(12.5)).foregroundStyle(wa.mut).fixedSize(horizontal: false, vertical: true)
                    }
                }
                if active {
                    VStack(spacing: 6) {
                        ForEach([("projects", "mktg_limit_projects"), ("research_runs", "mktg_limit_research_runs"), ("plan_generations", "mktg_limit_plan_generations"), ("content_generations", "mktg_limit_content_generations")], id: \.0) { key, label in
                            let used = sub?.usage?[key] ?? 0
                            let limit = sub?.limits?[key] ?? nil
                            HStack {
                                Text(verbatim: L(label, "")).font(.dorr(12.5)).foregroundStyle(wa.mut)
                                Spacer()
                                Text(verbatim: L("mktg_used_of", used, limit.map(String.init) ?? L("mktg_unlimited"))).font(.dorr(12.5, .bold)).foregroundStyle(wa.ink)
                            }
                            if let limit, limit > 0 { MkBar(fraction: Double(used) / Double(limit), color: used >= limit ? wa.danger : wa.red) }
                        }
                    }
                    .padding(.top, 12)
                    WaButton(text: L("mktg_renew"), style: .ghost, compact: true) { host.push(.packages) }.padding(.top, 12)
                } else {
                    WaButton(text: L("mktg_see_packages"), compact: true) { host.push(.packages) }.padding(.top, 12)
                }
            }
        }
    }
}

private struct ProjectRow: View {
    @Environment(\.wa) private var wa
    let project: MkProjectDto
    let action: () -> Void

    var body: some View {
        let subtitle = [project.industry, project.cities?.first, mkStageLabel(project.stage)].compactMap { $0 }.joined(separator: " · ")
        let archived = project.status == "archived"
        MkNavCard(icon: "building.2.fill", tone: archived ? .gray : .red, title: project.name, subtitle: subtitle, trailing: {
            if archived { MkSmallTag(text: L("mktg_archived_badge"), tint: wa.mut) } else { MkSmallTag(text: mkRoleLabel(project.myRole), tint: wa.red) }
        }, action: action)
    }
}

private struct NewProjectSheet: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let onCreated: (MkProjectDto) -> Void
    @State private var name = ""
    @State private var industry = ""
    @State private var city = ""
    @State private var stage = "idea"
    @State private var busy = false

    var body: some View {
        let valid = name.trimmingCharacters(in: .whitespaces).count >= 2 && industry.trimmingCharacters(in: .whitespaces).count >= 2
        VStack(alignment: .leading, spacing: 12) {
            Text(verbatim: L("mktg_new_project")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
            WeTextField(value: $name, label: L("mktg_project_name"), icon: "building.2", limit: 120, capitalize: true)
            WeTextField(value: $industry, label: L("mktg_industry"), placeholder: L("mktg_industry_hint"), icon: "sparkles", limit: 120, capitalize: true)
            WeTextField(value: $city, label: L("mktg_city"), icon: "building.columns", limit: 100, capitalize: true)
            Text(verbatim: L("mktg_stage")).font(.dorr(12.5, .bold)).foregroundStyle(wa.mut)
            HStack(spacing: 8) {
                ForEach(["idea", "launch", "growth"], id: \.self) { s in
                    WeChip(text: mkStageLabel(s), selected: stage == s) { stage = s }
                }
            }
            WaButton(text: L("mktg_create"), enabled: valid, loading: busy) { Task { await create() } }.padding(.top, 4)
        }
    }

    private func create() async {
        busy = true
        let trimmedCity = city.trimmingCharacters(in: .whitespaces)
        do {
            let body = MkProjectRequest(name: name.trimmingCharacters(in: .whitespaces), industry: industry.trimmingCharacters(in: .whitespaces),
                                        stage: stage, cities: trimmedCity.isEmpty ? nil : [trimmedCity])
            if let created = try await MarketingApi.createProject(body).data {
                host.showToast(L("mktg_created"))
                onCreated(created)
            }
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}

/// The packages sold here, each bought on the app's one payment screen (card or wallet).
struct MarketingPackages: View {
    @EnvironmentObject private var host: MkHost

    var body: some View {
        let status = host.status
        let packages = status?.packages ?? []
        MkPage(title: L("mktg_packages")) {
            if status == nil {
                VStack(spacing: 12) { ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 160) } }
            } else if packages.isEmpty {
                WaEmpty(icon: "crown", tone: .gray, title: L("mktg_packages"), text: L("mktg_no_packages"))
            } else {
                VStack(spacing: 12) {
                    ForEach(Array(packages.enumerated()), id: \.element.id) { i, pkg in
                        PackageOffer(pkg: pkg, active: status?.subscription?.pkg?.code == pkg.code) {
                            let purpose = status?.checkoutPurpose ?? "marketing_package"
                            CheckoutLauncher.shared.open(CheckoutRequest(purpose: purpose, reference: ["package": .string(pkg.code)])) {
                                host.showToast(L("mktg_paid"))
                                host.reloadStatus()
                                host.pop()
                            }
                        }
                        .waRise(i)
                    }
                }
            }
        }
    }
}

private struct PackageOffer: View {
    @Environment(\.wa) private var wa
    let pkg: MkPackageDto
    let active: Bool
    let onBuy: () -> Void

    var body: some View {
        let unlimited = L("mktg_unlimited")
        let limit: (String) -> String = { key in (pkg.limits?[key] ?? nil).map(String.init) ?? unlimited }
        WaCard(padding: 18) {
            VStack(alignment: .leading, spacing: 0) {
                HStack(alignment: .center) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: pkg.name ?? pkg.code).font(.dorr(17, .heavy)).foregroundStyle(wa.ink)
                        Text(verbatim: L("mktg_per_days", pkg.periodDays ?? 30)).font(.dorr(12.5)).foregroundStyle(wa.mut)
                    }
                    Spacer()
                    Text(verbatim: "\u{2066}\(mkMoney(pkg.priceMinor, pkg.currencyCode ?? ""))\u{2069}").font(.dorr(18, .black)).foregroundStyle(wa.red)
                }
                if let description = pkg.description, !description.isEmpty {
                    Text(verbatim: description).font(.dorr(13)).foregroundStyle(wa.mut).padding(.top, 8).fixedSize(horizontal: false, vertical: true)
                }
                MkBullets(items: [
                    L("mktg_limit_projects", limit("projects")),
                    L("mktg_limit_research_runs", limit("research_runs")),
                    L("mktg_limit_plan_generations", limit("plan_generations")),
                    L("mktg_limit_content_generations", limit("content_generations")),
                    L("mktg_limit_members", limit("members")),
                ])
                .padding(.top, 12)
                WaButton(text: L(active ? "mktg_renew" : "mktg_buy"), style: active ? .ghost : .primary, action: onBuy).padding(.top, 14)
            }
        }
    }
}
