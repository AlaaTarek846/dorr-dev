import SwiftUI

/// androidApp `ui/screens/marketing/MarketingProject.kt` — one project's board: the discovery progress, the research
/// runs, the three plans, the execution pages (phase B) and the team. Reloads once per `marketing.job.changed` of this
/// project and on reconnect.
struct MarketingProjectPage: View {
    @EnvironmentObject private var host: MkHost
    @ObservedObject private var net = NetworkMonitor.shared
    @Environment(\.wa) private var wa
    let projectId: String

    @State private var project: MkProjectDto?
    @State private var discovery: MkDiscoveryDto?
    @State private var runs: [MkRunDto] = []
    @State private var plans: [MkPlanDto] = []
    @State private var jobs: [MkJobDto] = []
    @State private var failed = false
    @State private var reload = 0
    @State private var startingKind: String?
    @State private var writingType: String?
    @State private var archiving = false

    var body: some View {
        MkPage(title: project?.name ?? L("mktg_title")) {
            if project == nil && failed {
                WaEmpty(icon: "doc.text", tone: .gray, title: L("mktg_error_generic"), text: "") {
                    WaButton(text: L("mktg_retry"), style: .ghost, compact: true) { reload += 1 }
                }
            } else if let p = project {
                board(p)
            } else {
                VStack(spacing: 12) { ForEach(0..<4, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 84) } }
            }
        }
        .task(id: "\(projectId)|\(reload)|\(net.reconnectTick)|\(host.projectChanges(projectId))") { await load() }
    }

    @ViewBuilder
    private func board(_ p: MkProjectDto) -> some View {
        let abilities = p.abilities
        let active = p.status == "active"
        header(p).waRise(0)

        if let d = discovery {
            MkNavCard(icon: "questionmark.bubble.fill", tone: d.complete == true ? .green : .amber, title: L("mktg_discovery"),
                      subtitle: L(d.complete == true ? "mktg_discovery_done" : "mktg_discovery_missing"), trailing: {
                MkSmallTag(text: "\(d.answered ?? 0)/\(d.total ?? 0)", tint: d.complete == true ? wa.green : mkAmber)
            }) { host.push(.discovery(projectId: projectId)) }
            .padding(.top, 12)
            .waRise(1)
        }

        WaSectionTitle(title: L("mktg_research"))
        if abilities?.research == true && active {
            HStack(spacing: 8) {
                ForEach(["market", "competitor", "audience"], id: \.self) { kind in
                    WaButton(text: mkKindLabel(kind), style: .ghost, loading: startingKind == kind, compact: true) { Task { await startResearch(kind) } }
                }
            }
            .padding(.bottom, 10)
        }
        if runs.isEmpty {
            Text(verbatim: L("mktg_no_research")).font(.dorr(13)).foregroundStyle(wa.mut).padding(.horizontal, 4)
        } else {
            VStack(spacing: 10) {
                ForEach(runs.prefix(6)) { run in
                    RunRow(run: run, job: jobs.first { $0.type == "research" && !$0.finished }) { host.push(.research(runId: run.id)) }
                }
            }
        }

        WaSectionTitle(title: L("mktg_plans"))
        VStack(spacing: 10) {
            ForEach(["business", "marketing", "gtm"], id: \.self) { type in
                planRow(type, plan: plans.first { $0.type == type }, job: jobs.first { $0.type == "plan" && $0.planType == type },
                        canWrite: abilities?.edit == true && active)
            }
        }

        // Execution (phases B and C): calendar, ad studio, budgets, results, report, campaigns, ad platforms, optimisation — each behind its country switch.
        // (One group: a view builder takes at most ten children.)
        Group {
            let features = host.status?.features
            WaSectionTitle(title: L("mktg_execution"))
            VStack(spacing: 10) {
                if features?["calendar"] != false {
                    MkNavCard(icon: "calendar", tone: .blue, title: L("mktg_calendar"), subtitle: L("mktg_calendar_sub")) { host.push(.calendar(projectId: projectId)) }
                }
                if features?["content"] != false {
                    MkNavCard(icon: "sparkles", tone: .pink, title: L("mktg_studio"), subtitle: L("mktg_studio_sub")) { host.push(.studio(projectId: projectId)) }
                }
                if features?["budgets"] != false {
                    MkNavCard(icon: "banknote", tone: .green, title: L("mktg_budgets"), subtitle: L("mktg_budgets_sub")) { host.push(.budgets(projectId: projectId)) }
                }
                if features?["results"] != false {
                    MkNavCard(icon: "chart.line.uptrend.xyaxis", tone: .amber, title: L("mktg_results"), subtitle: L("mktg_results_sub")) { host.push(.results(projectId: projectId)) }
                    MkNavCard(icon: "doc.plaintext", tone: .red, title: L("mktg_report"), subtitle: L("mktg_report_sub")) { host.push(.report(projectId: projectId)) }
                }
                if features?["campaigns"] != false {
                    MkNavCard(icon: "megaphone.fill", tone: .pink, title: L("mktg_campaigns"), subtitle: L("mktg_campaigns_sub")) { host.push(.campaigns(projectId: projectId)) }
                }
                if features?["platforms"] != false {
                    MkNavCard(icon: "network", tone: .blue, title: L("mktg_platforms"), subtitle: L("mktg_platforms_sub")) { host.push(.platforms(projectId: projectId)) }
                }
                if features?["optimisation"] != false {
                    MkNavCard(icon: "flask.fill", tone: .green, title: L("mktg_optimise"), subtitle: L("mktg_optimise_sub")) { host.push(.optimise(projectId: projectId)) }
                }
            }
        }

        Group {
            WaSectionTitle(title: L("mktg_team"))
            MkNavCard(icon: "person.3.fill", tone: .blue, title: L("mktg_team"), subtitle: L("mktg_team_sub", p.membersCount ?? 1), trailing: {
                MkSmallTag(text: mkRoleLabel(p.myRole), tint: wa.red)
            }) { host.push(.team(projectId: projectId)) }

            if abilities?.manageTeam == true {
                let archived = p.status == "archived"
                HStack(spacing: 10) {
                    WaButton(text: L(archived ? "mktg_unarchive" : "mktg_archive"), style: .ghost, icon: archived ? "tray.and.arrow.up" : "archivebox",
                             loading: archiving, compact: true) { Task { await archive(!archived) } }
                    WaButton(text: L("mktg_delete"), style: .quiet, icon: "trash", compact: true) {
                        host.confirm(WeDialogSpec(title: L("mktg_delete_confirm_title"), text: L("mktg_delete_confirm_text"), confirm: L("mktg_delete"), danger: true) {
                            Task { await delete() }
                        })
                    }
                }
                .padding(.top, 18)
            }
        }
    }

    private func header(_ p: MkProjectDto) -> some View {
        WaCard(padding: 16) {
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text(verbatim: p.industry).font(.dorr(15, .heavy)).foregroundStyle(wa.ink)
                    Spacer()
                    if p.status == "archived" { MkSmallTag(text: L("mktg_archived_badge"), tint: wa.mut) }
                }
                let place = [(p.cities ?? []).joined(separator: "، "), mkStageLabel(p.stage)].filter { !$0.isEmpty }.joined(separator: " · ")
                Text(verbatim: place).font(.dorr(12.5)).foregroundStyle(wa.mut)
                if let budget = p.profile?.monthlyBudgetMinor {
                    Text(verbatim: "\u{2066}\(mkMoney(budget, p.currency ?? ""))\u{2069}").font(.dorr(16, .black)).foregroundStyle(wa.red).padding(.top, 6)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }

    @ViewBuilder
    private func planRow(_ type: String, plan: MkPlanDto?, job: MkJobDto?, canWrite: Bool) -> some View {
        let writing = job.map { !$0.finished } ?? false
        if let plan, let version = plan.currentVersion {
            MkNavCard(icon: "doc.text.fill", tone: .red, title: mkPlanLabel(type),
                      subtitle: writing ? L("mktg_plan_writing") : L("mktg_version", version.version) + " · " + mkDate(version.createdAt), trailing: {
                MkStatusBadge(status: plan.status)
            }) { host.push(.plan(planId: plan.id)) }
        } else {
            WaCard(padding: 14) {
                VStack(alignment: .leading, spacing: 10) {
                    HStack {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(verbatim: mkPlanLabel(type)).font(.dorr(14.5, .heavy)).foregroundStyle(wa.ink)
                            if writing {
                                Text(verbatim: L("mktg_plan_writing")).font(.dorr(12.5)).foregroundStyle(wa.mut)
                            } else if job?.status == "FAILED" {
                                Text(verbatim: L("mktg_job_failed", job?.errorCode ?? "")).font(.dorr(12)).foregroundStyle(wa.danger)
                            }
                        }
                        Spacer()
                        if canWrite && !writing {
                            WaButton(text: L(job?.status == "FAILED" ? "mktg_retry" : "mktg_plan_write"), loading: writingType == type, compact: true) {
                                Task { await write(type) }
                            }
                            .fixedSize()
                        }
                    }
                    if writing { MkBar(fraction: Double(job?.progressPct ?? 0) / 100, color: wa.red) }
                }
            }
        }
    }

    private func load() async {
        do {
            project = try await MarketingApi.project(projectId).data
            discovery = try await MarketingApi.discovery(projectId).data
            runs = try await MarketingApi.researchRuns(projectId).data ?? []
            plans = try await MarketingApi.plans(projectId).data ?? []
            jobs = try await MarketingApi.jobs(projectId).data ?? []
            failed = false
        } catch {
            if project == nil { failed = true }
            host.fail(error, L("mktg_error_generic"))
        }
    }

    private func startResearch(_ kind: String) async {
        startingKind = kind
        do {
            _ = try await MarketingApi.startResearch(projectId, MkResearchRequest(kind: kind))
            host.showToast(L("mktg_research_started"))
            reload += 1
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        startingKind = nil
    }

    private func write(_ type: String) async {
        writingType = type
        do {
            _ = try await MarketingApi.generatePlan(projectId, MkPlanRequest(type: type))
            host.showToast(L("mktg_plan_started"))
            reload += 1
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        writingType = nil
    }

    private func archive(_ archived: Bool) async {
        archiving = true
        do {
            project = try await MarketingApi.archiveProject(projectId, archived: archived).data
            host.reloadStatus()
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        archiving = false
    }

    private func delete() async {
        do {
            _ = try await MarketingApi.deleteProject(projectId)
            host.showToast(L("mktg_deleted"))
            host.reloadStatus()
            host.open(.home)
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
    }
}

private struct RunRow: View {
    let run: MkRunDto
    let job: MkJobDto?
    let action: () -> Void

    var body: some View {
        let subtitle = run.finished ? L("mktg_sources_count", run.sourcesCount ?? 0, run.findingsCount ?? 0) : mkStatusLabel(run.status)
        VStack(spacing: 6) {
            MkNavCard(icon: "chart.bar.xaxis", tone: .pink, title: mkKindLabel(run.kind), subtitle: subtitle + " · " + mkDate(run.createdAt), trailing: {
                MkStatusBadge(status: run.status)
            }, action: action)
            if !run.finished, let job {
                MkBar(fraction: Double(job.progressPct ?? 0) / 100, color: mkBlue).padding(.horizontal, 18)
            }
        }
    }
}
