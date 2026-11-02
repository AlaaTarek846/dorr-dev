import PhotosUI
import SwiftUI

// androidApp `aichat/AiSitesScreen.kt`, `AiSitesHome.kt`, `AiSitesForm.kt`, `AiSiteFormParts.kt` — the AI website builder:
// my sites and requests, the two ways to get one (the AI now, or a person), a four-step brief (basics, story,
// contact, look: colours, logo, photos) built from the plan's allowance or bought as a standalone site behind the
// wallet PIN. The generated site opens in the browser — it is served by the server on its own link.

private enum SitesView: Equatable {
    case home
    case form(AiSiteOfferDto?)
    case detail(Int)
    case requestForm
    case requestDetail(Int)
}

let siteTypes = ["portfolio", "business", "store_showcase", "restaurant", "personal", "landing", "other"]

func siteTypeLabel(_ type: String) -> String {
    switch type {
    case "portfolio": return L("ai_sites_type_portfolio")
    case "business": return L("ai_sites_type_business")
    case "store_showcase": return L("ai_sites_type_store_showcase")
    case "restaurant": return L("ai_sites_type_restaurant")
    case "personal": return L("ai_sites_type_personal")
    case "landing": return L("ai_sites_type_landing")
    default: return L("ai_sites_type_other")
    }
}

func siteStatusLabel(_ status: String) -> String {
    switch status {
    case "generating": return L("ai_sites_status_generating")
    case "ready": return L("ai_sites_status_ready")
    case "failed": return L("ai_sites_status_failed")
    default: return L("ai_sites_status_draft")
    }
}

/// The site's state as a small chip (a spinner while it is being built).
struct SiteStatusChip: View {
    @Environment(\.wa) private var wa
    let status: String
    let disabled: Bool
    var onDark = false

    var body: some View {
        let c = wa.site
        let color: Color = disabled || status == "failed" ? c.danger : status == "ready" ? c.success : status == "generating" ? c.warn : c.mut
        let fg: Color = onDark ? .white : color
        HStack(spacing: 6) {
            if status == "generating" && !disabled {
                ProgressView().tint(fg).scaleEffect(0.5).frame(width: 11, height: 11)
            } else {
                Circle().fill(fg).frame(width: 7, height: 7)
            }
            Text(verbatim: disabled ? L("ai_sites_disabled") : siteStatusLabel(status)).font(.dorr(11.5, .semibold)).foregroundStyle(fg)
        }
        .padding(.horizontal, 10).padding(.vertical, 4)
        .background(onDark ? Color.white.opacity(0.2) : color.opacity(0.12), in: Capsule())
    }
}

struct AiSitesScreen: View {
    @Environment(\.wa) private var wa
    let onExit: () -> Void
    @State private var view = SitesView.home

    var body: some View {
        let title: String = {
            switch view {
            case .form: return L("ai_sites_new")
            case .requestForm: return L("ai_req_form_title")
            case .requestDetail: return L("ai_req_detail_title")
            default: return L("ai_sites_title")
            }
        }()
        VStack(spacing: 0) {
            SiteTopBar(title: title, onBack: { if view == .home { onExit() } else { view = .home } })
            switch view {
            case .home:
                SitesHome(onNew: { view = .form($0) }, onOpen: { view = .detail($0) }, onHuman: { view = .requestForm }, onOpenRequest: { view = .requestDetail($0) })
            case .form(let offer):
                SitesForm(offer: offer) { view = .detail($0) }
            case .detail(let id):
                SitesDetail(id: id) { view = .home }
            case .requestForm:
                SiteRequestForm { view = .requestDetail($0) }
            case .requestDetail(let id):
                SiteRequestDetail(id: id) { view = .home }
            }
        }
        .background(wa.site.bg.ignoresSafeArea())
    }
}

// MARK: - Home

private struct SitesHome: View {
    @Environment(\.wa) private var wa
    let onNew: (AiSiteOfferDto?) -> Void
    let onOpen: (Int) -> Void
    let onHuman: () -> Void
    let onOpenRequest: (Int) -> Void
    @State private var loading = true
    @State private var offers: AiSiteOffersDto?
    @State private var projects: [AiSiteProjectDto] = []
    @State private var pickOffer = false
    @State private var humanEnabled = false
    @State private var showChoice = false
    @State private var requests: [AiSiteRequestDto] = []

    var body: some View {
        let c = wa.site
        let available = (offers?.offers ?? []).filter { $0.available == true }
        let canBuild = offers != nil && offers?.enabled != false && (offers?.plan?.canCreate == true || !available.isEmpty)
        let message: String? = offers != nil && offers?.enabled == false ? L("ai_sites_unavailable") : (!canBuild && !humanEnabled ? L("ai_sites_not_included") : nil)
        Group {
            if loading {
                ProgressView().tint(wa.accent).frame(maxWidth: .infinity, maxHeight: .infinity)
            } else {
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 0) {
                        hero(canBuild: canBuild || humanEnabled, message: message) {
                            if humanEnabled { showChoice.toggle() } else if offers?.plan?.canCreate == true { onNew(nil) } else { pickOffer = true }
                        }
                        .padding(.bottom, 18)
                        if humanEnabled && showChoice {
                            SiteSectionTitle(title: L("ai_req_choice_title")).padding(.bottom, 6)
                            modeCard(L("ai_req_choice_ai_title"), L("ai_req_choice_ai_sub"), L("ai_req_choice_ai_badge"), "sparkles", enabled: canBuild) {
                                showChoice = false
                                if offers?.plan?.canCreate == true { onNew(nil) } else { pickOffer = true }
                            }
                            modeCard(L("ai_req_choice_human_title"), L("ai_req_choice_human_sub"), nil, "person.wave.2.fill", enabled: true) {
                                showChoice = false
                                onHuman()
                            }
                            .padding(.bottom, 14)
                        }
                        if !requests.isEmpty {
                            SiteSectionTitle(title: L("ai_req_my_requests")).padding(.bottom, 6)
                            ForEach(requests) { r in requestCard(r).padding(.vertical, 5) }
                            Spacer().frame(height: 14)
                        }
                        if pickOffer {
                            SiteSectionTitle(title: L("ai_sites_choose_offer")).padding(.bottom, 6)
                            ForEach(available) { offer in offerCard(offer).padding(.vertical, 5) }
                            Spacer().frame(height: 14)
                        }
                        if projects.isEmpty {
                            if requests.isEmpty {
                                VStack(spacing: 4) {
                                    Image(systemName: "globe").font(.system(size: 28)).foregroundStyle(wa.accent).frame(width: 64, height: 64).background(wa.accent.opacity(0.1), in: Circle())
                                    Text(L("ai_sites_empty_title")).font(.dorr(15, .heavy)).foregroundStyle(c.ink).padding(.top, 8)
                                    Text(L("ai_sites_empty_sub")).font(.dorr(12.5)).foregroundStyle(c.mut).multilineTextAlignment(.center)
                                }
                                .frame(maxWidth: .infinity).padding(.vertical, 28)
                            }
                        } else {
                            SiteSectionTitle(title: L("ai_sites_my_sites")).padding(.bottom, 6)
                            ForEach(projects) { p in projectCard(p).padding(.vertical, 5) }
                        }
                    }
                    .padding(.horizontal, 16).padding(.top, 6).padding(.bottom, 32)
                }
            }
        }
        .task {
            offers = try? await AiSitesApi.offers().data
            projects = (try? await AiSitesApi.projects().data) ?? []
            humanEnabled = (try? await AiSitesApi.requestInfo().data?.enabled) == true
            requests = (try? await AiSitesApi.requestList().data) ?? []
            loading = false
        }
    }

    private func hero(canBuild: Bool, message: String?, onNew: @escaping () -> Void) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            Image(systemName: "sparkles").font(.system(size: 22)).foregroundStyle(.white).frame(width: 46, height: 46).background(Color.white.opacity(0.2), in: Circle())
            Text(L("ai_sites_hero_title")).font(.dorr(22, .heavy)).foregroundStyle(.white).padding(.top, 14)
            Text(L("ai_sites_hero_sub")).font(.dorr(13.5)).foregroundStyle(.white.opacity(0.88)).lineSpacing(4).padding(.top, 4)
            if canBuild {
                Button(action: onNew) {
                    HStack(spacing: 6) {
                        Image(systemName: "plus").font(.system(size: 17, weight: .bold))
                        Text(L("ai_sites_new")).font(.dorr(15, .heavy))
                    }
                    .foregroundStyle(wa.accent)
                    .frame(maxWidth: .infinity, minHeight: 50)
                    .background(Color.white, in: RoundedRectangle(cornerRadius: 15, style: .continuous))
                }
                .buttonStyle(AiPressStyle())
                .padding(.top, 16)
            } else if let message {
                Text(verbatim: message).font(.dorr(13, .semibold)).foregroundStyle(.white).lineSpacing(3).padding(.top, 16)
            }
            VStack(alignment: .leading, spacing: 10) {
                ForEach(Array(["ai_sites_how_1", "ai_sites_how_2", "ai_sites_how_3"].enumerated()), id: \.offset) { i, key in
                    HStack(spacing: 10) {
                        Text(verbatim: "\(i + 1)").font(.dorr(12, .bold)).foregroundStyle(.white).frame(width: 24, height: 24).background(Color.white.opacity(0.25), in: Circle())
                        Text(L(key)).font(.dorr(13, .semibold)).foregroundStyle(.white.opacity(0.95))
                        Spacer(minLength: 0)
                    }
                }
            }
            .padding(.horizontal, 14).padding(.vertical, 12)
            .background(Color.white.opacity(0.1), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .padding(.top, 16)
        }
        .padding(20)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(siteGradient(wa.accent), in: RoundedRectangle(cornerRadius: 26, style: .continuous))
    }

    private func offerCard(_ offer: AiSiteOfferDto) -> some View {
        let c = wa.site
        return SiteCard(onClick: { onNew(offer) }) {
            HStack(spacing: 10) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: offer.name).font(.dorr(15, .heavy)).foregroundStyle(c.ink)
                    Text(verbatim: L("ai_sites_offer_gens", offer.generationsIncluded ?? 0)).font(.dorr(12)).foregroundStyle(c.mut)
                    if let d = offer.description, !d.isEmpty { Text(verbatim: d).font(.dorr(12)).foregroundStyle(c.mut).padding(.top, 2).multilineTextAlignment(.leading) }
                }
                Spacer(minLength: 0)
                Text(verbatim: "\(offer.price.map { sitePrice($0) } ?? "") \(offer.currency ?? "")".trimmingCharacters(in: .whitespaces)).font(.dorr(13, .heavy)).foregroundStyle(wa.accent)
                    .padding(.horizontal, 12).padding(.vertical, 8)
                    .background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
        }
    }

    private func projectCard(_ p: AiSiteProjectDto) -> some View {
        let c = wa.site
        return SiteCard(padding: 14, onClick: { onOpen(p.id) }) {
            HStack(spacing: 12) {
                SiteAvatar(title: p.title, seed: p.id)
                VStack(alignment: .leading, spacing: 6) {
                    Text(verbatim: p.title).font(.dorr(15, .heavy)).foregroundStyle(c.ink).lineLimit(1)
                    HStack(spacing: 8) {
                        SiteStatusChip(status: p.status, disabled: p.isDisabled == true)
                        if !p.versionList.isEmpty { Text(verbatim: L("ai_sites_versions_count", p.versionList.count)).font(.dorr(11.5)).foregroundStyle(c.mut) }
                    }
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.right").font(.system(size: 14, weight: .semibold)).foregroundStyle(c.mut).flipsForRightToLeftLayoutDirection(true)
            }
        }
    }

    private func modeCard(_ title: String, _ sub: String, _ badge: String?, _ icon: String, enabled: Bool, action: @escaping () -> Void) -> some View {
        let c = wa.site
        return Button(action: action) {
            HStack(spacing: 14) {
                Image(systemName: icon).font(.system(size: 24)).foregroundStyle(.white).frame(width: 52, height: 52)
                    .background {
                        if enabled { RoundedRectangle(cornerRadius: 16, style: .continuous).fill(siteGradient(wa.accent)) } else { RoundedRectangle(cornerRadius: 16, style: .continuous).fill(c.line) }
                    }
                VStack(alignment: .leading, spacing: 3) {
                    HStack(spacing: 8) {
                        Text(verbatim: title).font(.dorr(15, .heavy)).foregroundStyle(enabled ? c.ink : c.mut)
                        if let badge {
                            Text(verbatim: badge).font(.dorr(10.5, .heavy)).foregroundStyle(wa.accent).padding(.horizontal, 8).padding(.vertical, 3).background(wa.accent.opacity(0.12), in: Capsule())
                        }
                    }
                    Text(verbatim: sub).font(.dorr(12)).foregroundStyle(c.mut).lineSpacing(3).multilineTextAlignment(.leading)
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.right").font(.system(size: 14, weight: .semibold)).foregroundStyle(c.mut).flipsForRightToLeftLayoutDirection(true)
            }
            .padding(16)
            .background(c.surface, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(c.line, lineWidth: 1))
        }
        .buttonStyle(AiPressStyle())
        .disabled(!enabled)
        .padding(.vertical, 5)
    }

    private func requestCard(_ r: AiSiteRequestDto) -> some View {
        let c = wa.site
        return SiteCard(padding: 14, onClick: { onOpenRequest(r.id) }) {
            HStack(spacing: 12) {
                Image(systemName: "person.wave.2.fill").font(.system(size: 21)).foregroundStyle(wa.accent).frame(width: 46, height: 46)
                    .background(wa.accent.opacity(0.1), in: RoundedRectangle(cornerRadius: 15, style: .continuous))
                VStack(alignment: .leading, spacing: 6) {
                    Text(verbatim: r.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(c.ink).lineLimit(1)
                    HStack(spacing: 8) {
                        RequestStatusChip(name: r.status?.name ?? "", color: r.status?.color)
                        Text(verbatim: r.reference ?? "").font(.dorr(11)).foregroundStyle(c.mut)
                    }
                }
                Spacer(minLength: 0)
                if (r.unread ?? 0) > 0 {
                    Text(verbatim: "\(r.unread!)").font(.dorr(11, .bold)).foregroundStyle(.white).frame(width: 22, height: 22).background(c.danger, in: Circle())
                }
                Image(systemName: "chevron.right").font(.system(size: 14, weight: .semibold)).foregroundStyle(c.mut).flipsForRightToLeftLayoutDirection(true)
            }
        }
    }
}

/// "249.0" → "249", "249.5" → "249.5" (Kotlin prints the Double as it is; whole numbers read better without ".0").
func sitePrice(_ v: Double) -> String {
    v.truncatingRemainder(dividingBy: 1) == 0 ? String(Int(v)) : String(v)
}

// MARK: - The brief

private func siteTypeIcon(_ type: String) -> String {
    switch type {
    case "portfolio": return "briefcase.fill"
    case "business": return "building.2.fill"
    case "store_showcase": return "storefront.fill"
    case "restaurant": return "fork.knife"
    case "personal": return "person.fill"
    case "landing": return "paperplane.fill"
    default: return "square.grid.2x2.fill"
    }
}

private struct SitesForm: View {
    @Environment(\.wa) private var wa
    let offer: AiSiteOfferDto?
    let onDone: (Int) -> Void
    @State private var step = 0
    @State private var title = ""
    @State private var business = ""
    @State private var activity = ""
    @State private var siteType = "business"
    @State private var description = ""
    @State private var phone = ""
    @State private var whatsapp = ""
    @State private var email = ""
    @State private var address = ""
    @State private var primary = ""
    @State private var secondary = ""
    @State private var logo: AiLocalFile?
    @State private var images: [AiLocalFile] = []
    @State private var submitting = false
    @State private var error: String?
    @State private var askPin = false
    @State private var noPin = false

    private let steps = 4

    private var fields: [String: String] {
        var map = [
            "title": title.trimmingCharacters(in: .whitespaces), "business_name": business.trimmingCharacters(in: .whitespaces),
            "activity": activity.trimmingCharacters(in: .whitespaces), "site_type": siteType,
            "description": description.trimmingCharacters(in: .whitespacesAndNewlines), "languages[0]": "ar",
        ]
        func opt(_ key: String, _ value: String) { let v = value.trimmingCharacters(in: .whitespaces); if !v.isEmpty { map[key] = v } }
        opt("contact[phone]", phone)
        opt("contact[whatsapp]", whatsapp)
        opt("contact[email]", email)
        opt("contact[address]", address)
        opt("colors[primary]", primary)
        opt("colors[secondary]", secondary)
        if let offer { map["offer_id"] = String(offer.id) }
        return map
    }

    private func files() throws -> [ApiClient.FilePart] {
        var out = try images.map { ApiClient.FilePart(field: "images[]", filename: $0.name, mimeType: $0.mime, data: try Data(contentsOf: $0.url)) }
        if let logo { out.append(ApiClient.FilePart(field: "logo", filename: logo.name, mimeType: logo.mime, data: try Data(contentsOf: logo.url))) }
        return out
    }

    private var basicsValid: Bool { ![title, business, activity].contains { $0.trimmingCharacters(in: .whitespaces).isEmpty } }
    private var storyValid: Bool { description.trimmingCharacters(in: .whitespacesAndNewlines).count >= 10 }

    private func send() {
        error = nil
        if offer == nil {
            submitting = true
            Task {
                do { if let p = try await AiSitesApi.create(fields: fields, files: try files()).data { onDone(p.id) } } catch { self.error = error.apiFailure.message }
                submitting = false
            }
        } else {
            aiNeedsPin(noPin: { noPin = true }, askPin: { askPin = true })
        }
    }

    var body: some View {
        let c = wa.site
        let titles = [L("ai_sites_step_basics"), L("ai_sites_step_story"), L("ai_sites_step_contact"), L("ai_sites_step_look")]
        let canNext = step == 0 ? basicsValid : step == 1 ? storyValid : true
        VStack(spacing: 0) {
            // The step bar.
            VStack(spacing: 10) {
                HStack(spacing: 6) {
                    ForEach(0..<steps, id: \.self) { i in Capsule().fill(i <= step ? wa.accent : c.line).frame(height: 5) }
                }
                HStack {
                    Text(verbatim: titles[step]).font(.dorr(17, .heavy)).foregroundStyle(c.ink)
                    Spacer()
                    Text(verbatim: L("ai_sites_step_of", step + 1, steps)).font(.dorr(12)).foregroundStyle(c.mut)
                }
            }
            .padding(.horizontal, 16).padding(.vertical, 6)
            .animation(.easeInOut(duration: 0.25), value: step)

            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    switch step {
                    case 0:
                        SiteField(label: L("ai_sites_f_title"), text: $title)
                        SiteField(label: L("ai_sites_f_business"), text: $business)
                        SiteField(label: L("ai_sites_f_activity"), text: $activity)
                        SiteSectionTitle(title: L("ai_sites_f_type")).padding(.top, 6).padding(.bottom, 10)
                        LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 8), count: 3), spacing: 8) {
                            ForEach(siteTypes, id: \.self) { t in typeTile(t) }
                        }
                    case 1:
                        SiteField(label: L("ai_sites_f_description"), text: $description, lines: 7...12, helper: L("ai_sites_f_desc_hint"))
                        Text(verbatim: "\(description.trimmingCharacters(in: .whitespacesAndNewlines).count)").font(.dorr(12)).foregroundStyle(storyValid ? c.success : c.mut).padding(.top, 4)
                    case 2:
                        Text(L("ai_sites_f_contact_hint")).font(.dorr(12.5)).foregroundStyle(c.mut).lineSpacing(4).padding(.bottom, 8)
                        SiteField(label: L("ai_sites_f_phone"), text: $phone, keyboard: .phonePad)
                        SiteField(label: L("ai_sites_f_whatsapp"), text: $whatsapp, keyboard: .phonePad)
                        SiteField(label: L("ai_sites_f_email"), text: $email, keyboard: .emailAddress)
                        SiteField(label: L("ai_sites_f_address"), text: $address)
                    default:
                        SiteColorSection(primary: primary, secondary: secondary) { primary = $0; secondary = $1 }
                        SiteLogoCard(logo: $logo).padding(.top, 14)
                        SiteImagesCard(images: $images, max: 8).padding(.top, 10)
                        if let error { Text(verbatim: error).font(.dorr(12.5)).foregroundStyle(c.danger).padding(.top, 12) }
                    }
                }
                .padding(.horizontal, 16).padding(.top, 8).padding(.bottom, 16)
            }
            .scrollDismissesKeyboard(.interactively)

            HStack(spacing: 10) {
                if step > 0 { SiteSecondaryButton(label: L("ai_sites_back"), enabled: !submitting) { step -= 1 }.frame(maxWidth: 140) }
                if submitting {
                    ProgressView().tint(wa.accent).frame(maxWidth: .infinity, minHeight: 52)
                } else if step < steps - 1 {
                    SitePrimaryButton(label: L("ai_sites_next"), enabled: canNext) { step += 1 }
                } else {
                    SitePrimaryButton(label: L(offer == nil ? "ai_sites_f_submit" : "ai_sites_f_submit_buy"), enabled: basicsValid && storyValid) { send() }
                }
            }
            .padding(.horizontal, 16).padding(.vertical, 12)
            .background(c.surface.ignoresSafeArea(edges: .bottom))
        }
        .dorrDialog(isPresented: $noPin) { AiInfoDialog(message: L("ai_subscription_no_pin_set")) { noPin = false } }
        .dorrDialog(isPresented: $askPin) {
            AiWalletPinDialog(subtitle: L("ai_sites_pin_sub"), onDismiss: { askPin = false }) { pin in
                do {
                    let p = try await AiSitesApi.purchase(pin: pin, fields: fields, files: try files()).data
                    if let p { onDone(p.id) }
                    return .ok
                } catch {
                    return padFailure(error, networkError: L("ai_send_failed"))
                }
            }
        }
    }

    private func typeTile(_ type: String) -> some View {
        let c = wa.site
        let on = type == siteType
        return Button { siteType = type } label: {
            VStack(spacing: 6) {
                Image(systemName: siteTypeIcon(type)).font(.system(size: 22)).foregroundStyle(on ? wa.accent : c.mut)
                Text(verbatim: siteTypeLabel(type)).font(.dorr(11.5, on ? .bold : .medium)).foregroundStyle(on ? wa.accent : c.ink).lineLimit(1).minimumScaleFactor(0.8)
            }
            .frame(maxWidth: .infinity).padding(.vertical, 14).padding(.horizontal, 6)
            .background(on ? wa.accent.opacity(0.1) : c.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(on ? wa.accent : c.line, lineWidth: on ? 1.5 : 1))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - The look: colours, logo, photos

private let sitePalettes: [(String, String)] = [
    ("#1D4ED8", "#0EA5E9"), ("#059669", "#84CC16"), ("#DC2626", "#F97316"), ("#7C3AED", "#EC4899"),
    ("#EA580C", "#FACC15"), ("#111827", "#D4AF37"), ("#0D9488", "#38BDF8"), ("#DB2777", "#FB7185"),
]

private let siteSwatches = ["#1D4ED8", "#0EA5E9", "#0D9488", "#059669", "#84CC16", "#FACC15", "#EA580C", "#DC2626", "#DB2777", "#7C3AED", "#111827", "#D4AF37"]

private func hexColor(_ value: String) -> Color {
    guard let v = UInt32(value.trimmingCharacters(in: CharacterSet(charactersIn: "#")), radix: 16) else { return .gray }
    return Color(hex: v)
}

/// Ready colour pairs, then one row for the main and one for the second colour; «automatic» lets the AI decide.
struct SiteColorSection: View {
    @Environment(\.wa) private var wa
    let primary: String
    let secondary: String
    let onChange: (String, String) -> Void

    var body: some View {
        let c = wa.site
        VStack(alignment: .leading, spacing: 0) {
            Text(L("ai_sites_f_colors")).font(.dorr(13, .bold)).foregroundStyle(c.ink)
            Text(L("ai_sites_f_colors_hint")).font(.dorr(11.5)).foregroundStyle(c.mut)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    let auto = primary.isEmpty && secondary.isEmpty
                    Button { onChange("", "") } label: {
                        Text(L("ai_sites_f_colors_auto")).font(.dorr(12, .semibold)).foregroundStyle(c.ink).padding(.horizontal, 12).padding(.vertical, 10)
                            .background(auto ? wa.accent.opacity(0.15) : c.surface, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    ForEach(sitePalettes, id: \.0) { a, b in
                        let on = primary.caseInsensitiveCompare(a) == .orderedSame && secondary.caseInsensitiveCompare(b) == .orderedSame
                        Button { onChange(a, b) } label: {
                            HStack(spacing: -8) {
                                Circle().fill(hexColor(a)).frame(width: 26, height: 26).overlay(Circle().stroke(.white, lineWidth: 2))
                                Circle().fill(hexColor(b)).frame(width: 26, height: 26).overlay(Circle().stroke(.white, lineWidth: 2))
                            }
                            .padding(.horizontal, 10).padding(.vertical, 8)
                            .background(on ? wa.accent.opacity(0.15) : c.surface, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                            .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(on ? wa.accent : .clear, lineWidth: 2))
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
            .padding(.top, 8)
            Text(L("ai_sites_f_color_main")).font(.dorr(12)).foregroundStyle(c.mut).padding(.top, 12)
            swatches(selected: primary) { onChange($0, secondary) }
            Text(L("ai_sites_f_color_second")).font(.dorr(12)).foregroundStyle(c.mut)
            swatches(selected: secondary) { onChange(primary, $0) }
        }
        .padding(.top, 10)
    }

    private func swatches(selected: String, pick: @escaping (String) -> Void) -> some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(siteSwatches, id: \.self) { s in
                    let on = selected.caseInsensitiveCompare(s) == .orderedSame
                    Button { pick(s) } label: {
                        Circle().fill(hexColor(s)).frame(width: 34, height: 34)
                            .overlay(Circle().stroke(on ? wa.accent : Color.black.opacity(0.12), lineWidth: on ? 3 : 1))
                            .overlay { if on { Image(systemName: "checkmark").font(.system(size: 14, weight: .bold)).foregroundStyle(.white) } }
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.vertical, 6)
        }
    }
}

private struct RemoveBadge: View {
    let action: () -> Void
    var body: some View {
        Button(action: action) {
            Image(systemName: "xmark").font(.system(size: 10, weight: .bold)).foregroundStyle(.white).frame(width: 22, height: 22).background(Color.black.opacity(0.65), in: Circle())
        }
        .buttonStyle(.plain)
    }
}

private func localImage(_ file: AiLocalFile) -> Image? {
    UIImage(contentsOfFile: file.url.path).map { Image(uiImage: $0) }
}

/// The logo: a dashed drop-zone that becomes a preview with a remove button.
struct SiteLogoCard: View {
    @Environment(\.wa) private var wa
    @Binding var logo: AiLocalFile?
    @State private var item: PhotosPickerItem?

    var body: some View {
        let c = wa.site
        PhotosPicker(selection: $item, matching: .images) {
            HStack(spacing: 12) {
                ZStack {
                    wa.accent.opacity(0.08)
                    if let logo, let img = localImage(logo) { img.resizable().scaledToFit() } else {
                        Image(systemName: "photo.badge.plus").font(.system(size: 26)).foregroundStyle(wa.accent)
                    }
                }
                .frame(width: 64, height: 64).clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                VStack(alignment: .leading, spacing: 2) {
                    Text(L(logo != nil ? "ai_sites_f_logo_set" : "ai_sites_f_logo")).font(.dorr(13, .bold)).foregroundStyle(c.ink)
                    Text(L(logo != nil ? "ai_sites_f_tap_change" : "ai_sites_f_logo_hint")).font(.dorr(11.5)).foregroundStyle(c.mut).multilineTextAlignment(.leading)
                }
                Spacer(minLength: 0)
                if logo != nil { RemoveBadge { logo = nil } }
            }
            .padding(14)
            .background(c.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.accent.opacity(0.45), style: StrokeStyle(lineWidth: 1.5, dash: [6, 4])))
        }
        .buttonStyle(.plain)
        .onChange(of: item) { picked in
            guard let picked else { return }
            item = nil
            Task { if let f = await aiCopyPicked(picked) { logo = f } }
        }
    }
}

/// The photos: removable thumbnails and an add tile, up to `max`.
struct SiteImagesCard: View {
    @Environment(\.wa) private var wa
    @Binding var images: [AiLocalFile]
    let max: Int
    @State private var items: [PhotosPickerItem] = []

    var body: some View {
        let c = wa.site
        VStack(alignment: .leading, spacing: 0) {
            HStack {
                Text(L("ai_sites_f_images")).font(.dorr(13, .bold)).foregroundStyle(c.ink)
                Spacer()
                Text(verbatim: "\(images.count)/\(max)").font(.dorr(12)).foregroundStyle(c.mut)
            }
            Text(L("ai_sites_f_images_hint")).font(.dorr(11.5)).foregroundStyle(c.mut)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 10) {
                    ForEach(Array(images.enumerated()), id: \.offset) { i, f in
                        ZStack(alignment: .topTrailing) {
                            (localImage(f) ?? Image(systemName: "photo")).resizable().scaledToFill().frame(width: 78, height: 78)
                                .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                            RemoveBadge { images.remove(at: i) }.padding(4)
                        }
                    }
                    if images.count < max {
                        PhotosPicker(selection: $items, maxSelectionCount: max - images.count, matching: .images) {
                            Image(systemName: "photo.badge.plus").font(.system(size: 26)).foregroundStyle(wa.accent).frame(width: 78, height: 78)
                                .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(wa.accent.opacity(0.45), style: StrokeStyle(lineWidth: 1.5, dash: [6, 4])))
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
            .padding(.top, 10)
        }
        .padding(14)
        .background(c.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .onChange(of: items) { picked in
            guard !picked.isEmpty else { return }
            items = []
            Task {
                for p in picked { if let f = await aiCopyPicked(p) { images.append(f) } }
                images = Array(images.prefix(max))
            }
        }
    }
}
