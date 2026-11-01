import ARKit
import GLTFKit2
import SceneKit
import SwiftUI

// androidApp `aichat/AiArScreen.kt`, `AiArDesignsScreen.kt` — DORR AR: the model library («view in AR» places the model
// at its real size when the phone supports AR and falls back to a plain 3D viewer when it does not — Android hands the
// .glb to Google's Scene Viewer; here GLTFKit2 reads it and ARKit / SceneKit show it), and «my designs»: create one,
// send it to a provider for review, read the answer and quote, accept (or pay into escrow behind the wallet PIN),
// then cancel / confirm delivery / dispute. The AR editor module is not in Android's app build, so its button stays
// hidden on both.

private func arStatusLabel(_ s: String?) -> String {
    switch s {
    case "active": return L("ai_ar_st_active")
    case "submitted": return L("ai_ar_st_submitted")
    case "approved": return L("ai_ar_st_approved")
    case "accepted": return L("ai_ar_st_accepted")
    default: return L("ai_ar_st_draft")
    }
}

private func arOrderLabel(_ s: String?) -> String {
    switch s {
    case "delivered": return L("ai_ar_od_delivered")
    case "disputed": return L("ai_ar_od_disputed")
    case "released": return L("ai_ar_od_released")
    case "refunded": return L("ai_ar_od_refunded")
    default: return L("ai_ar_od_paid")
    }
}

private func arReviewLabel(_ s: String?) -> String {
    switch s {
    case "approved": return L("ai_ar_rv_approved")
    case "changes_requested": return L("ai_ar_rv_changes")
    case "rejected": return L("ai_ar_rv_rejected")
    case "withdrawn": return L("ai_ar_rv_withdrawn")
    default: return L("ai_ar_rv_pending")
    }
}

private struct ArViewing: Identifiable {
    let id = UUID()
    let file: URL
    let title: String
}

struct AiArScreen: View {
    @Environment(\.wa) private var wa
    let onExit: () -> Void
    @State private var enabled: Bool?
    @State private var notice: String?
    @State private var assets: [AiArAssetDto] = []
    @State private var busyId: Int?
    @State private var error: String?
    @State private var designsTab = false
    @State private var viewing: ArViewing?

    /// A fresh signed link (it lasts minutes), the file downloaded, then the viewer.
    private func open(_ asset: AiArAssetDto) {
        busyId = asset.id
        error = nil
        Task {
            defer { busyId = nil }
            do {
                guard let link = try await AiArApi.asset(asset.id).data?.downloadUrl, let url = URL(string: link) else {
                    error = L("ai_ar_open_failed")
                    return
                }
                var request = URLRequest(url: url)
                request.setValue("1", forHTTPHeaderField: "ngrok-skip-browser-warning")
                let (data, _) = try await URLSession.shared.data(for: request)
                let file = FileManager.default.temporaryDirectory.appendingPathComponent("ar-\(asset.id).glb")
                try data.write(to: file)
                viewing = ArViewing(file: file, title: asset.name ?? "")
            } catch {
                self.error = error.apiFailure.message ?? L("ai_ar_open_failed")
            }
        }
    }

    var body: some View {
        let c = wa.site
        VStack(spacing: 0) {
            SiteTopBar(title: L("ai_ar_title"), onBack: onExit)
            if enabled == nil {
                ProgressView().tint(wa.accent).frame(maxWidth: .infinity, maxHeight: .infinity)
            } else if designsTab {
                HStack {
                    SiteSecondaryButton(label: L("ai_ar_tab_library")) { designsTab = false }.fixedSize()
                    Spacer()
                }
                .padding(.horizontal, 16).padding(.vertical, 8)
                AiArDesignsPanel()
            } else {
                ScrollView {
                    VStack(alignment: .leading, spacing: 10) {
                        SiteCard {
                            Text(L("ai_ar_hero_title")).font(.dorr(18, .heavy)).foregroundStyle(c.ink)
                            Text(L("ai_ar_hero_sub")).font(.dorr(13)).foregroundStyle(c.mut).lineSpacing(4).padding(.top, 4)
                            if enabled != true {
                                Text(L("ai_ar_disabled")).font(.dorr(13, .semibold)).foregroundStyle(c.warn).padding(.top, 10)
                            } else {
                                SiteSecondaryButton(label: L("ai_ar_tab_designs")) { designsTab = true }.fixedSize().padding(.top, 10)
                            }
                        }
                        if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(c.warn) }
                        ForEach(assets) { a in
                            SiteCard(padding: 14) {
                                Text(verbatim: a.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(c.ink)
                                if let d = a.dimensions {
                                    Text(verbatim: L("ai_ar_dimensions", d.width ?? 0, d.height ?? 0, d.depth ?? 0)).font(.dorr(12)).foregroundStyle(c.mut).padding(.top, 2)
                                }
                                SitePrimaryButton(label: busyId == a.id ? L("ai_ar_opening") : L("ai_ar_view"), enabled: busyId == nil) { open(a) }.padding(.top, 10)
                            }
                        }
                        if enabled == true && assets.isEmpty { Text(L("ai_ar_empty")).font(.dorr(13)).foregroundStyle(c.mut) }
                        if let notice { Text(verbatim: notice).font(.dorr(11)).foregroundStyle(c.mut).lineSpacing(3) }
                    }
                    .padding(16)
                }
            }
        }
        .background(c.bg.ignoresSafeArea())
        .task {
            let info = try? await AiArApi.info().data
            enabled = info?.enabled == true
            notice = info?.measurementNotice
            if enabled == true { assets = (try? await AiArApi.assets().data) ?? [] }
        }
        .fullScreenCover(item: $viewing) { v in
            ArModelViewer(file: v.file, title: v.title) { viewing = nil }
        }
    }
}

// MARK: - The viewer

/// The model at its real size on a surface (tap to place, pinch-free: real size only), or a plain 3D viewer where the
/// phone has no AR.
private struct ArModelViewer: View {
    let file: URL
    let title: String
    let onClose: () -> Void
    @State private var scene: SCNScene?
    @State private var failed = false

    var body: some View {
        ZStack(alignment: .top) {
            Color.black.ignoresSafeArea()
            if let scene {
                if ARWorldTrackingConfiguration.isSupported {
                    ArPlacementView(model: scene).ignoresSafeArea()
                } else {
                    SceneView(scene: scene, options: [.allowsCameraControl, .autoenablesDefaultLighting]).ignoresSafeArea()
                }
            } else if failed {
                Text(L("ai_ar_open_failed")).font(.dorr(14)).foregroundStyle(.white).frame(maxWidth: .infinity, maxHeight: .infinity)
            } else {
                ProgressView().tint(.white).frame(maxWidth: .infinity, maxHeight: .infinity)
            }
            HStack {
                Button(action: onClose) {
                    Image(systemName: "xmark").font(.system(size: 17, weight: .semibold)).foregroundStyle(.white)
                        .frame(width: 42, height: 42).background(Color.black.opacity(0.4), in: Circle())
                }
                .buttonStyle(.plain)
                Text(verbatim: title).font(.dorr(15, .bold)).foregroundStyle(.white).lineLimit(1)
                Spacer()
            }
            .padding(16)
        }
        .task {
            GLTFAsset.load(with: file, options: [:]) { _, status, asset, _, _ in
                DispatchQueue.main.async {
                    if status == .complete, let asset {
                        scene = GLTFSCNSceneSource(asset: asset).defaultScene
                    } else if status == .error {
                        failed = true
                    }
                }
            }
        }
    }
}

/// ARKit: find a horizontal surface, tap to put the model there at the size the file says (metres).
private struct ArPlacementView: UIViewRepresentable {
    let model: SCNScene

    func makeCoordinator() -> Coordinator { Coordinator(model: model) }

    func makeUIView(context: Context) -> ARSCNView {
        let view = ARSCNView()
        view.autoenablesDefaultLighting = true
        view.automaticallyUpdatesLighting = true
        let config = ARWorldTrackingConfiguration()
        config.planeDetection = [.horizontal]
        view.session.run(config)
        view.addGestureRecognizer(UITapGestureRecognizer(target: context.coordinator, action: #selector(Coordinator.tap(_:))))
        context.coordinator.view = view
        return view
    }

    func updateUIView(_ view: ARSCNView, context: Context) {}

    static func dismantleUIView(_ view: ARSCNView, coordinator: Coordinator) { view.session.pause() }

    final class Coordinator: NSObject {
        let model: SCNScene
        weak var view: ARSCNView?
        private var placed: SCNNode?

        init(model: SCNScene) { self.model = model }

        @objc func tap(_ g: UITapGestureRecognizer) {
            guard let view else { return }
            let point = g.location(in: view)
            guard let query = view.raycastQuery(from: point, allowing: .estimatedPlane, alignment: .horizontal),
                  let hit = view.session.raycast(query).first else { return }
            let node = placed ?? {
                let n = SCNNode()
                model.rootNode.childNodes.forEach { n.addChildNode($0.clone()) }
                view.scene.rootNode.addChildNode(n)
                placed = n
                return n
            }()
            node.simdTransform = hit.worldTransform
        }
    }
}

// MARK: - My designs

/// Create a design, send it to a provider for review, read the answer (note and quote), accept it. While a design is
/// under review or accepted it can't be edited, so what the provider approved is what is accepted.
private struct AiArDesignsPanel: View {
    @Environment(\.wa) private var wa
    @State private var designs: [AiArExperienceDto]?
    @State private var selected: AiArExperienceDto?
    @State private var reviews: [AiArReviewDto] = []
    @State private var title = ""
    @State private var query = ""
    @State private var providers: [AiArProviderDto] = []
    @State private var chosen: AiArProviderDto?
    @State private var note = ""
    @State private var busy = false
    @State private var order: AiArOrderDto?
    @State private var payFor: AiArExperienceDto?
    @State private var noPin = false
    @State private var disputing = false
    @State private var reason = ""
    @State private var error: String?

    private func reload(keep: Int? = nil) async {
        designs = (try? await AiArApi.experiences().data) ?? []
        let id = keep ?? selected?.id
        selected = designs?.first { $0.id == id }
        if let s = selected {
            order = (try? await AiArApi.order(s.id).data) ?? nil
            reviews = (try? await AiArApi.reviews(s.id).data) ?? []
        } else {
            order = nil
            reviews = []
        }
    }

    private func act(_ block: @escaping () async throws -> Void) {
        Task {
            busy = true
            error = nil
            do { try await block() } catch { self.error = error.apiFailure.message }
            busy = false
            await reload()
        }
    }

    var body: some View {
        let c = wa.site
        ScrollView {
            VStack(alignment: .leading, spacing: 10) {
                if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(c.warn) }
                if let current = selected {
                    design(current)
                } else {
                    SiteCard {
                        SiteField(label: L("ai_ar_design_title"), text: $title)
                        SitePrimaryButton(label: L("ai_ar_design_new"), enabled: !title.trimmingCharacters(in: .whitespaces).isEmpty && !busy) {
                            let t = title.trimmingCharacters(in: .whitespaces)
                            act {
                                let created = try await AiArApi.createExperience(type: "decor", title: t).data
                                title = ""
                                selected = created
                            }
                        }
                        .padding(.top, 8)
                    }
                    ForEach(designs ?? []) { d in
                        SiteCard(padding: 14, onClick: { selected = d; Task { await reload(keep: d.id) } }) {
                            HStack {
                                Text(verbatim: d.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(c.ink).lineLimit(1)
                                Spacer()
                                Text(verbatim: arStatusLabel(d.status)).font(.dorr(12, .bold)).foregroundStyle(wa.accent)
                            }
                        }
                    }
                    if designs?.isEmpty == true { Text(L("ai_ar_designs_empty")).font(.dorr(13)).foregroundStyle(c.mut) }
                }
            }
            .padding(16)
        }
        .task { await reload() }
        .task(id: query) {
            let q = query.trimmingCharacters(in: .whitespaces)
            guard q.count >= 2 else { providers = []; return }
            providers = (try? await AiArApi.searchProviders(q).data) ?? []
        }
        .dorrDialog(isPresented: $noPin) { AiInfoDialog(message: L("ai_subscription_no_pin_set")) { noPin = false } }
        .dorrDialog(isPresented: Binding(get: { payFor != nil }, set: { if !$0 { payFor = nil } })) {
            if let target = payFor {
                AiWalletPinDialog(subtitle: L("ai_ar_pay_pin_sub"), onDismiss: { payFor = nil }) { pin in
                    do {
                        _ = try await AiArApi.acceptAndPay(target.id, pin: pin)
                        payFor = nil
                        await reload(keep: target.id)
                        return .ok
                    } catch {
                        return padFailure(error, networkError: L("ai_send_failed"))
                    }
                }
            }
        }
    }

    @ViewBuilder private func design(_ current: AiArExperienceDto) -> some View {
        let c = wa.site
        let locked = current.status == "submitted" || current.status == "accepted"
        Button { selected = nil } label: {
            Label(L("ai_ar_tab_designs"), systemImage: "chevron.left").font(.dorr(13, .bold)).foregroundStyle(wa.accent)
        }
        .buttonStyle(.plain)
        SiteCard {
            Text(verbatim: current.title ?? "").font(.dorr(17, .heavy)).foregroundStyle(c.ink)
            Text(verbatim: arStatusLabel(current.status)).font(.dorr(12, .bold)).foregroundStyle(wa.accent).padding(.top, 2)
            if let n = current.notice { Text(verbatim: n).font(.dorr(11)).foregroundStyle(c.mut).lineSpacing(3).padding(.top, 8) }
            if locked { Text(L("ai_ar_locked")).font(.dorr(12)).foregroundStyle(c.mut).padding(.top, 8) }
        }
        ForEach(reviews) { r in
            SiteCard(padding: 14) {
                Text(verbatim: arReviewLabel(r.status)).font(.dorr(14, .heavy)).foregroundStyle(c.ink)
                if let n = r.providerNote, !n.isEmpty { Text(verbatim: n).font(.dorr(13)).foregroundStyle(c.ink).padding(.top, 4) }
                if let q = r.quoteMinor { Text(verbatim: L("ai_ar_quote", worldMoney(q, r.currency))).font(.dorr(13, .semibold)).foregroundStyle(c.ink).padding(.top, 4) }
                if r.status == "pending" {
                    SiteSecondaryButton(label: L("ai_ar_withdraw"), enabled: !busy) { act { _ = try await AiArApi.withdraw(current.id, review: r.id) } }.padding(.top, 8)
                }
            }
        }
        if current.status == "approved" {
            let quote = reviews.first { $0.status == "approved" }?.quoteMinor ?? 0
            if quote > 0 {
                // A priced design is accepted by paying from the wallet; the money is held until the provider delivers.
                Text(L("ai_ar_escrow_hint")).font(.dorr(12)).foregroundStyle(c.mut).lineSpacing(3)
                SitePrimaryButton(label: L("ai_ar_pay"), enabled: !busy) { aiNeedsPin(noPin: { noPin = true }, askPin: { payFor = current }) }
            } else {
                SitePrimaryButton(label: L("ai_ar_accept"), enabled: !busy) { act { _ = try await AiArApi.accept(current.id) } }
            }
        }
        if let o = order {
            SiteCard(padding: 14) {
                Text(verbatim: arOrderLabel(o.status)).font(.dorr(14, .heavy)).foregroundStyle(c.ink)
                Text(verbatim: worldMoney(o.amountMinor, o.currency)).font(.dorr(13)).foregroundStyle(c.ink).padding(.top, 4)
                if let n = o.deliveryNote, !n.isEmpty { Text(verbatim: n).font(.dorr(13)).foregroundStyle(c.ink).padding(.top, 4) }
                if let n = o.disputeReason, !n.isEmpty { Text(verbatim: n).font(.dorr(12)).foregroundStyle(c.warn).padding(.top, 4) }
                if let n = o.resolutionNote, !n.isEmpty { Text(verbatim: n).font(.dorr(12)).foregroundStyle(c.mut).padding(.top, 4) }
                if o.status == "paid" {
                    SiteSecondaryButton(label: L("ai_ar_cancel_order"), enabled: !busy) { act { _ = try await AiArApi.cancelOrder(o.id) } }.padding(.top, 8)
                }
                if o.status == "delivered" {
                    Text(L("ai_ar_confirm_hint")).font(.dorr(12)).foregroundStyle(c.mut).padding(.top, 6)
                    SitePrimaryButton(label: L("ai_ar_confirm_delivery"), enabled: !busy) { act { _ = try await AiArApi.confirmOrder(o.id) } }.padding(.top, 8)
                    if disputing {
                        SiteField(label: L("ai_ar_dispute_reason"), text: $reason, lines: 2...4).padding(.top, 6)
                        SitePrimaryButton(label: L("ai_ar_dispute_send"), enabled: !busy && reason.trimmingCharacters(in: .whitespaces).count >= 5) {
                            let r = reason.trimmingCharacters(in: .whitespaces)
                            act { _ = try await AiArApi.disputeOrder(o.id, reason: r); reason = ""; disputing = false }
                        }
                        .padding(.top, 6)
                    } else {
                        SiteSecondaryButton(label: L("ai_ar_dispute"), enabled: !busy) { disputing = true }.padding(.top, 6)
                    }
                }
            }
        }
        if current.status == "active" || current.status == "draft" {
            SiteCard {
                SiteSectionTitle(title: L("ai_ar_send_title"), subtitle: L("ai_ar_send_sub"))
                SiteField(label: L("ai_ar_provider_search"), text: Binding(get: { query }, set: { query = $0; chosen = nil }))
                ForEach(providers) { p in
                    SiteSecondaryButton(label: (p.name ?? "") + (chosen?.id == p.id ? "  ✓" : "")) { chosen = p }.padding(.vertical, 2)
                }
                SiteField(label: L("ai_ar_note"), text: $note, lines: 2...4).padding(.top, 6)
                SitePrimaryButton(label: L("ai_ar_send"), enabled: chosen != nil && !busy) {
                    let provider = chosen!.id
                    let n = note.trimmingCharacters(in: .whitespaces)
                    act { _ = try await AiArApi.submit(current.id, providerId: provider, note: n.isEmpty ? nil : n) }
                }
                .padding(.top, 8)
            }
        }
    }
}
