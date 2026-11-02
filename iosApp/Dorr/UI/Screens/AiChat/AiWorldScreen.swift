import PhotosUI
import SwiftUI

// androidApp `aichat/AiWorldScreen.kt`, `AiWorldPhotosScreen.kt` — DORR AI WORLD: the Director (an idea + places →
// proposed scenes and a price), the priced plan approved before paying, «my trip videos» with confirm (wallet PIN),
// approve a scene, cancel, delete, report; and «my photos» (up to 3 photos of one person, the consent text, delete).
// A video being made is read again every 10 s, like Android.

private enum WorldView: Equatable {
    case home, director, photos
    case plan(AiWorldPlanDto, brief: String, places: [Int], profileId: Int?)
    case detail(Int)
}

/// 1000 minor units on a 2-decimal currency = "10.00 EGP".
func worldMoney(_ minor: Int?, _ currency: String?) -> String {
    let m = minor ?? 0
    return String(format: "%d.%02d %@", m / 100, m % 100, currency ?? "").trimmingCharacters(in: .whitespaces)
}

private func worldStatusLabel(_ status: String) -> String {
    switch status {
    case "awaiting_confirmation": return L("ai_world_st_awaiting")
    case "generating": return L("ai_world_st_generating")
    case "review": return L("ai_world_st_review")
    case "stitching": return L("ai_world_st_stitching")
    case "completed": return L("ai_world_st_completed")
    case "failed": return L("ai_world_st_failed")
    case "cancelled": return L("ai_world_st_cancelled")
    case "queued": return L("ai_world_st_queued")
    case "approved": return L("ai_world_st_approved")
    default: return L("ai_world_st_draft")
    }
}

struct AiWorldScreen: View {
    @Environment(\.wa) private var wa
    let onExit: () -> Void
    @State private var view = WorldView.home

    var body: some View {
        VStack(spacing: 0) {
            SiteTopBar(title: L("ai_world_title"), onBack: { if view == .home { onExit() } else { view = .home } })
            switch view {
            case .home: WorldHome(onNew: { view = .director }, onPhotos: { view = .photos }, onOpen: { view = .detail($0) })
            case .director: WorldDirector { view = .plan($0, brief: $1, places: $2, profileId: $3) }
            case .photos: WorldPhotosScreen()
            case .plan(let plan, let brief, _, let profileId): WorldPlan(plan: plan, brief: brief, profileId: profileId) { view = .detail($0) }
            case .detail(let id): WorldDetail(id: id) { view = .home }
            }
        }
        .background(wa.site.bg.ignoresSafeArea())
    }
}

private struct WorldHome: View {
    @Environment(\.wa) private var wa
    let onNew: () -> Void
    let onPhotos: () -> Void
    let onOpen: (Int) -> Void
    @State private var enabled: Bool?
    @State private var projects: [AiWorldProjectDto]?

    var body: some View {
        let c = wa.site
        Group {
            if enabled == nil || projects == nil {
                ProgressView().tint(wa.accent).frame(maxWidth: .infinity, maxHeight: .infinity)
            } else {
                ScrollView {
                    VStack(spacing: 10) {
                        SiteCard {
                            Text(L("ai_world_hero_title")).font(.dorr(18, .heavy)).foregroundStyle(c.ink)
                            Text(L("ai_world_hero_sub")).font(.dorr(13)).foregroundStyle(c.mut).lineSpacing(4).padding(.top, 4).padding(.bottom, 14)
                            if enabled == true {
                                SitePrimaryButton(label: L("ai_world_new"), action: onNew)
                                SiteSecondaryButton(label: L("ai_world_photos_title"), action: onPhotos).padding(.top, 8)
                            } else {
                                Text(L("ai_world_disabled")).font(.dorr(13, .semibold)).foregroundStyle(c.warn)
                            }
                        }
                        ForEach(projects ?? []) { p in
                            SiteCard(padding: 14, onClick: { onOpen(p.id) }) {
                                HStack {
                                    VStack(alignment: .leading, spacing: 0) {
                                        Text(verbatim: p.title?.isEmpty == false ? p.title! : L("ai_world_untitled", p.id)).font(.dorr(15, .heavy)).foregroundStyle(c.ink).lineLimit(1)
                                        Text(verbatim: L("ai_world_seconds_total", p.totalSeconds ?? 0)).font(.dorr(12)).foregroundStyle(c.mut)
                                    }
                                    Spacer()
                                    Text(verbatim: worldStatusLabel(p.status)).font(.dorr(12, .bold)).foregroundStyle(wa.accent)
                                }
                            }
                        }
                    }
                    .padding(16)
                }
            }
        }
        .task {
            enabled = (try? await AiWorldApi.info().data?.enabled) ?? false
            projects = (try? await AiWorldApi.projects().data) ?? []
        }
    }
}

private struct WorldDirector: View {
    @Environment(\.wa) private var wa
    let onPlan: (AiWorldPlanDto, String, [Int], Int?) -> Void
    @State private var brief = ""
    @State private var query = ""
    @State private var results: [AiWorldLocationDto] = []
    @State private var picked: [AiWorldLocationDto] = []
    @State private var busy = false
    @State private var error: String?
    @State private var profiles: [AiWorldProfileDto] = []
    @State private var profileId: Int?

    var body: some View {
        let c = wa.site
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                SiteSectionTitle(title: L("ai_world_step_idea"), subtitle: L("ai_world_step_idea_sub"))
                SiteField(label: L("ai_world_brief"), text: $brief, lines: 4...8).padding(.top, 8)
                SiteSectionTitle(title: L("ai_world_places"), subtitle: L("ai_world_places_sub")).padding(.top, 10)
                SiteField(label: L("ai_world_search"), text: $query)
                ForEach(results.filter { r in !picked.contains { $0.id == r.id } }) { r in
                    SiteCard(padding: 12, onClick: {
                        if picked.count < 5 { picked.append(r); results = []; query = "" }
                    }) {
                        Text(verbatim: r.name).font(.dorr(14, .bold)).foregroundStyle(c.ink)
                        Text(verbatim: [r.city, r.fidelity?.label].compactMap { $0 }.joined(separator: " • ")).font(.dorr(12)).foregroundStyle(c.mut)
                    }
                    .padding(.vertical, 4)
                }
                ForEach(picked) { p in
                    Button { picked.removeAll { $0.id == p.id } } label: {
                        HStack {
                            VStack(alignment: .leading, spacing: 0) {
                                Text(verbatim: p.name).font(.dorr(14, .bold)).foregroundStyle(c.ink)
                                if let d = p.fidelity?.description { Text(verbatim: d).font(.dorr(11.5)).foregroundStyle(c.mut).multilineTextAlignment(.leading) }
                            }
                            Spacer()
                            Text(verbatim: "✕").font(.dorr(14, .bold)).foregroundStyle(wa.accent)
                        }
                        .padding(12)
                        .background(wa.accent.opacity(0.1), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    .padding(.vertical, 4)
                }
                if !profiles.isEmpty {
                    SiteSectionTitle(title: L("ai_world_use_photos"), subtitle: L("ai_world_use_photos_sub")).padding(.top, 10)
                    ForEach(profiles) { p in
                        Button { profileId = profileId == p.id ? nil : p.id } label: {
                            HStack {
                                Text(verbatim: p.label).font(.dorr(14, .bold)).foregroundStyle(c.ink)
                                Spacer()
                                if profileId == p.id { Text(verbatim: "✓").font(.dorr(15, .heavy)).foregroundStyle(wa.accent) }
                            }
                            .padding(12)
                            .background(profileId == p.id ? wa.accent.opacity(0.12) : c.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                        .buttonStyle(.plain)
                        .padding(.vertical, 3)
                    }
                }
                if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(c.danger).padding(.top, 8) }
                Group {
                    if busy {
                        ProgressView().tint(wa.accent).frame(maxWidth: .infinity)
                    } else {
                        SitePrimaryButton(label: L("ai_world_make_plan"), enabled: brief.trimmingCharacters(in: .whitespaces).count >= 3) {
                            error = nil
                            busy = true
                            let b = brief.trimmingCharacters(in: .whitespacesAndNewlines)
                            let ids = picked.map(\.id)
                            Task {
                                do { if let plan = try await AiWorldApi.plan(brief: b, locationIds: ids).data { onPlan(plan, b, ids, profileId) } } catch { self.error = error.apiFailure.message }
                                busy = false
                            }
                        }
                    }
                }
                .padding(.top, 16)
            }
            .padding(16)
        }
        .scrollDismissesKeyboard(.interactively)
        .task { profiles = ((try? await AiWorldApi.profiles().data) ?? []).filter { $0.status == "active" } }
        .task(id: query) {
            let q = query.trimmingCharacters(in: .whitespaces)
            guard q.count >= 2 else { results = []; return }
            results = (try? await AiWorldApi.searchLocations(q).data) ?? []
        }
    }
}

private struct WorldPlan: View {
    @Environment(\.wa) private var wa
    let plan: AiWorldPlanDto
    let brief: String
    let profileId: Int?
    let onCreated: (Int) -> Void
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        let c = wa.site
        let scenes = plan.scenes ?? []
        ScrollView {
            VStack(spacing: 8) {
                SiteSectionTitle(title: L("ai_world_plan_title"), subtitle: L("ai_world_plan_sub"))
                ForEach(Array(scenes.enumerated()), id: \.offset) { i, s in
                    SiteCard(padding: 14) {
                        HStack {
                            Text(verbatim: L("ai_world_scene_n", i + 1)).font(.dorr(13, .heavy)).foregroundStyle(wa.accent)
                            Spacer()
                            Text(verbatim: L("ai_world_sec", s.seconds)).font(.dorr(12)).foregroundStyle(c.mut)
                        }
                        if let n = s.locationName { Text(verbatim: n).font(.dorr(14, .bold)).foregroundStyle(c.ink).padding(.top, 4) }
                        Text(verbatim: s.prompt).font(.dorr(12.5)).foregroundStyle(c.mut).lineSpacing(3).padding(.top, 4)
                    }
                }
                SiteCard(padding: 14) {
                    HStack {
                        Text(verbatim: L("ai_world_total", plan.totalSeconds ?? 0)).font(.dorr(14, .bold)).foregroundStyle(c.ink)
                        Spacer()
                        Text(verbatim: plan.estimateMinor.map { worldMoney($0, plan.currency) } ?? L("ai_world_no_price")).font(.dorr(16, .heavy)).foregroundStyle(wa.accent)
                    }
                    Text(L("ai_world_no_charge_yet")).font(.dorr(11.5)).foregroundStyle(c.mut).padding(.top, 6)
                }
                if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(c.danger) }
                if busy {
                    ProgressView().tint(wa.accent)
                } else {
                    SitePrimaryButton(label: L("ai_world_create"), enabled: plan.estimateMinor != nil && !scenes.isEmpty) {
                        error = nil
                        busy = true
                        Task {
                            do { if let p = try await AiWorldApi.create(title: nil, brief: brief, identityProfileId: profileId, scenes: scenes).data { onCreated(p.id) } } catch { self.error = error.apiFailure.message }
                            busy = false
                        }
                    }
                }
            }
            .padding(16)
        }
    }
}

private struct WorldDetail: View {
    @Environment(\.wa) private var wa
    @Environment(\.scenePhase) private var scenePhase
    @EnvironmentObject private var network: NetworkMonitor
    let id: Int
    let onDeleted: () -> Void
    @State private var project: AiWorldProjectDto?
    @State private var error: String?
    @State private var askPin = false
    @State private var noPin = false
    @State private var reported = false

    private func reload() async {
        do { project = try await AiWorldApi.project(id).data } catch { self.error = error.apiFailure.message }
    }

    var body: some View {
        let c = wa.site
        Group {
            if let p = project {
                ScrollView {
                    VStack(spacing: 8) {
                        SiteCard {
                            Text(verbatim: p.title?.isEmpty == false ? p.title! : L("ai_world_untitled", p.id)).font(.dorr(17, .heavy)).foregroundStyle(c.ink)
                            Text(verbatim: worldStatusLabel(p.status)).font(.dorr(13, .bold)).foregroundStyle(wa.accent).padding(.top, 4)
                            Text(verbatim: L("ai_world_price_line", worldMoney(p.estimatedMinor, p.currency))).font(.dorr(12.5)).foregroundStyle(c.mut).padding(.top, 8)
                            if (p.chargedMinor ?? 0) > 0 { Text(verbatim: L("ai_world_charged_line", worldMoney(p.chargedMinor, p.currency))).font(.dorr(12.5)).foregroundStyle(c.mut) }
                            if (p.refundedMinor ?? 0) > 0 { Text(verbatim: L("ai_world_refunded_line", worldMoney(p.refundedMinor, p.currency))).font(.dorr(12.5)).foregroundStyle(c.success) }
                        }
                        ForEach(p.scenes ?? []) { s in
                            SiteCard(padding: 14) {
                                HStack {
                                    Text(verbatim: L("ai_world_scene_n", s.position)).font(.dorr(14, .bold)).foregroundStyle(c.ink)
                                    Spacer()
                                    Text(verbatim: worldStatusLabel(s.status)).font(.dorr(12)).foregroundStyle(c.mut)
                                }
                                Text(verbatim: s.prompt).font(.dorr(12)).foregroundStyle(c.mut).lineLimit(2).padding(.top, 4)
                                if s.status == "review" {
                                    SiteSecondaryButton(label: L("ai_world_approve")) {
                                        Task { do { project = try await AiWorldApi.approve(p.id, scene: s.id).data } catch { self.error = error.apiFailure.message } }
                                    }
                                    .padding(.top, 8)
                                }
                            }
                        }
                        if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(c.danger) }
                        if p.status == "awaiting_confirmation" {
                            SitePrimaryButton(label: L("ai_world_confirm", worldMoney(p.estimatedMinor, p.currency))) {
                                error = nil
                                aiNeedsPin(noPin: { noPin = true }, askPin: { askPin = true })
                            }
                        }
                        if ["completed", "failed", "cancelled", "awaiting_confirmation"].contains(p.status) {
                            SiteSecondaryButton(label: L("ai_world_delete_video"), color: c.danger) {
                                Task { do { _ = try await AiWorldApi.deleteProject(p.id); onDeleted() } catch { self.error = error.apiFailure.message } }
                            }
                        }
                        if p.status == "completed" {
                            SiteSecondaryButton(label: L("ai_world_report"), color: c.mut) {
                                Task {
                                    do { _ = try await AiWorldApi.report(p.id, type: "complaint", notes: nil); error = nil; reported = true } catch { self.error = error.apiFailure.message }
                                }
                            }
                            if reported { Text(L("ai_world_reported")).font(.dorr(12.5)).foregroundStyle(c.success) }
                        }
                        if ["awaiting_confirmation", "generating", "review"].contains(p.status) {
                            SiteSecondaryButton(label: L("ai_world_cancel"), color: c.danger) {
                                Task { do { project = try await AiWorldApi.cancel(p.id).data } catch { self.error = error.apiFailure.message } }
                            }
                        }
                    }
                    .padding(16)
                }
                .refreshable { await reload() }
            } else {
                ProgressView().tint(wa.accent).frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .task(id: id) { await reload() }
        // While the videos are being made the status moves on the server: read it every 10 s until it settles (Android).
        .task(id: project?.status) {
            while ["generating", "stitching"].contains(project?.status ?? "") && !Task.isCancelled {
                try? await Task.sleep(nanoseconds: 10_000_000_000)
                guard !Task.isCancelled else { return }
                await reload()
            }
        }
        .onChange(of: scenePhase) { if $0 == .active { Task { await reload() } } }
        .onChange(of: network.reconnectTick) { _ in Task { await reload() } }
        .dorrDialog(isPresented: $noPin) { AiInfoDialog(message: L("ai_subscription_no_pin_set")) { noPin = false } }
        .dorrDialog(isPresented: $askPin) {
            AiWalletPinDialog(subtitle: L("ai_world_pin_sub"), onDismiss: { askPin = false }) { pin in
                do {
                    project = try await AiWorldApi.confirm(id, pin: pin).data
                    return .ok
                } catch {
                    return padFailure(error, networkError: L("ai_send_failed"))
                }
            }
        }
    }
}

/// My photos for DORR World: up to 3 photos of one person, the consent text accepted with the upload, delete any
/// time (which also stops an unfinished video using them). Photos are never shown back in the app.
private struct WorldPhotosScreen: View {
    @Environment(\.wa) private var wa
    @State private var profiles: [AiWorldProfileDto]?
    @State private var consent: AiWorldConsentTextDto?
    @State private var adding = false
    @State private var label = ""
    @State private var subject = "self"
    @State private var photos: [AiLocalFile] = []
    @State private var pickerItems: [PhotosPickerItem] = []
    @State private var agreed = false
    @State private var busy = false
    @State private var error: String?

    private func reload() async { profiles = (try? await AiWorldApi.profiles().data) ?? [] }

    var body: some View {
        let c = wa.site
        Group {
            if let list = profiles {
                ScrollView {
                    VStack(spacing: 10) {
                        SiteSectionTitle(title: L("ai_world_photos_title"), subtitle: L("ai_world_photos_sub"))
                        ForEach(list) { p in
                            SiteCard(padding: 14) {
                                HStack {
                                    VStack(alignment: .leading, spacing: 0) {
                                        Text(verbatim: p.label).font(.dorr(15, .heavy)).foregroundStyle(c.ink)
                                        Text(verbatim: L("ai_world_photos_count", p.photos ?? 0)).font(.dorr(12)).foregroundStyle(c.mut)
                                    }
                                    Spacer()
                                    Text(L(p.status == "active" ? "ai_world_photo_active" : "ai_world_photo_pending")).font(.dorr(12, .bold))
                                        .foregroundStyle(p.status == "active" ? c.success : c.warn)
                                }
                                SiteSecondaryButton(label: L("ai_world_photo_delete"), color: c.danger) {
                                    Task {
                                        do { _ = try await AiWorldApi.deleteProfile(p.id) } catch { self.error = error.apiFailure.message }
                                        await reload()
                                    }
                                }
                                .padding(.top, 10)
                            }
                        }
                        if !adding {
                            if list.count < 5 { SitePrimaryButton(label: L("ai_world_photo_add")) { adding = true } }
                            if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(c.danger) }
                        } else {
                            addForm
                        }
                    }
                    .padding(16)
                }
            } else {
                ProgressView().tint(wa.accent).frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .task {
            consent = try? await AiWorldApi.consentText().data
            await reload()
        }
        .onChange(of: pickerItems) { items in
            guard !items.isEmpty else { return }
            pickerItems = []
            Task {
                for item in items { if let f = await aiCopyPicked(item) { photos.append(f) } }
                photos = Array(photos.prefix(3))
            }
        }
    }

    private var addForm: some View {
        let c = wa.site
        // The server's attestation keys come back camel-cased with the rest of the body.
        let attestation = consent?.attestations?[camel(subject)] ?? consent?.attestations?[subject] ?? ""
        return SiteCard {
            SiteField(label: L("ai_world_photo_label"), text: $label)
            radio("self", L("ai_world_subject_self"))
            radio("other_with_permission", L("ai_world_subject_other"))
            PhotosPicker(selection: $pickerItems, maxSelectionCount: max(1, 3 - photos.count), matching: .images) {
                Text(verbatim: L("ai_world_photo_pick", photos.count)).font(.dorr(14, .bold)).foregroundStyle(wa.accent)
                    .frame(maxWidth: .infinity, minHeight: 52)
                    .background(wa.accent.opacity(0.1), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.accent.opacity(0.35), lineWidth: 1))
            }
            .disabled(photos.count >= 3)
            .padding(.top, 6)
            Text(verbatim: consent?.text ?? "").font(.dorr(12)).foregroundStyle(c.mut).lineSpacing(4).padding(.top, 10)
            Button { agreed.toggle() } label: {
                HStack(alignment: .top, spacing: 10) {
                    Image(systemName: agreed ? "checkmark.square.fill" : "square").font(.system(size: 20)).foregroundStyle(agreed ? wa.accent : c.mut)
                    Text(verbatim: attestation).font(.dorr(12.5)).foregroundStyle(c.ink).multilineTextAlignment(.leading)
                    Spacer(minLength: 0)
                }
                .padding(.vertical, 8)
            }
            .buttonStyle(.plain)
            if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(c.danger) }
            Group {
                if busy {
                    ProgressView().tint(wa.accent).frame(maxWidth: .infinity)
                } else {
                    SitePrimaryButton(label: L("ai_world_photo_save"), enabled: !label.trimmingCharacters(in: .whitespaces).isEmpty && !photos.isEmpty && agreed) { save() }
                }
            }
            .padding(.top, 8)
        }
    }

    private func radio(_ value: String, _ text: String) -> some View {
        let c = wa.site
        return Button { subject = value } label: {
            HStack(spacing: 10) {
                Image(systemName: subject == value ? "largecircle.fill.circle" : "circle").font(.system(size: 19)).foregroundStyle(subject == value ? wa.accent : c.mut)
                Text(verbatim: text).font(.dorr(13)).foregroundStyle(c.ink)
                Spacer()
            }
            .padding(.vertical, 6)
        }
        .buttonStyle(.plain)
    }

    private func save() {
        error = nil
        busy = true
        Task {
            do {
                let parts = try photos.map { ApiClient.FilePart(field: "photos[]", filename: $0.name, mimeType: $0.mime, data: try Data(contentsOf: $0.url)) }
                let created = try await AiWorldApi.createProfile(fields: ["label": label.trimmingCharacters(in: .whitespaces), "subject_type": subject], photos: parts).data
                // The box the customer ticked IS the attestation: it is only sent together with the upload.
                if let created { _ = try await AiWorldApi.consent(created.id, attestation: subject == "self" ? "own_photo" : "has_permission") }
                adding = false
                label = ""
                photos = []
                agreed = false
                await reload()
            } catch {
                self.error = error.apiFailure.message
            }
            busy = false
        }
    }
}
