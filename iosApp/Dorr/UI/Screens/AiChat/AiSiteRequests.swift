import SwiftUI
import UniformTypeIdentifiers

// androidApp `aichat/AiSiteRequestUi.kt`, `AiSiteRequestForm.kt`, `AiSiteRequestDetail.kt` — a site built by a person:
// a four-step brief (business, pages and features, look, contact + budget + files), then the request: where it
// stands on the journey, the quote (accept with the first payment behind the PIN, or decline), the countdown,
// instalments as they are released, milestones, the handover, and the chat with the team. The request is
// read every 20 s and the chat every 8 s while its tab is open, like Android.

/// The dashboard names a status colour the Bootstrap way; mapped onto the app's palette.
private func requestColor(_ name: String?, _ c: SitePalette, _ accent: Color) -> Color {
    switch name {
    case "success": return c.success
    case "danger": return c.danger
    case "warning": return c.warn
    case "info": return Color(hex: 0x0EA5E9)
    case "primary": return accent
    default: return c.mut
    }
}

struct RequestStatusChip: View {
    @Environment(\.wa) private var wa
    let name: String
    let color: String?
    var onDark = false

    var body: some View {
        let tint = requestColor(color, wa.site, wa.accent)
        let fg: Color = onDark ? .white : tint
        HStack(spacing: 6) {
            Circle().fill(fg).frame(width: 7, height: 7)
            Text(verbatim: name).font(.dorr(11.5, .semibold)).foregroundStyle(fg)
        }
        .padding(.horizontal, 10).padding(.vertical, 4)
        .background(onDark ? Color.white.opacity(0.2) : tint.opacity(0.12), in: Capsule())
    }
}

/// Pills that wrap onto several lines; several or one at a time.
private struct PillGroup: View {
    @Environment(\.wa) private var wa
    let options: [String]
    let selected: Set<String>
    var multi = true
    let onChange: (Set<String>) -> Void

    var body: some View {
        let c = wa.site
        FlowLayout(spacing: 8) {
            ForEach(options, id: \.self) { o in
                let on = selected.contains(o)
                Button {
                    onChange(!multi ? [o] : on ? selected.subtracting([o]) : selected.union([o]))
                } label: {
                    HStack(spacing: 5) {
                        if on { Image(systemName: "checkmark").font(.system(size: 12, weight: .bold)) }
                        Text(verbatim: o).font(.dorr(12.5, on ? .bold : .medium))
                    }
                    .foregroundStyle(on ? wa.accent : c.ink)
                    .padding(.horizontal, 14).padding(.vertical, 9)
                    .background(on ? wa.accent.opacity(0.12) : c.surface, in: Capsule())
                    .overlay(Capsule().stroke(on ? wa.accent : c.line, lineWidth: on ? 1.5 : 1))
                }
                .buttonStyle(.plain)
            }
        }
    }
}

// MARK: - The brief

private let reqPages: [(String, String)] = [("home", "ai_req_page_home"), ("about", "ai_req_page_about"), ("services", "ai_req_page_services"), ("products", "ai_req_page_products"),
                                             ("gallery", "ai_req_page_gallery"), ("blog", "ai_req_page_blog"), ("contact", "ai_req_page_contact"), ("pricing", "ai_req_page_pricing"),
                                             ("faq", "ai_req_page_faq"), ("booking", "ai_req_page_booking")]
private let reqFeatures: [(String, String)] = [("contact_form", "ai_req_feat_contact_form"), ("whatsapp", "ai_req_feat_whatsapp"), ("booking", "ai_req_feat_booking"),
                                                ("payments", "ai_req_feat_payments"), ("store", "ai_req_feat_store"), ("multilang", "ai_req_feat_multilang"),
                                                ("dashboard", "ai_req_feat_dashboard"), ("cms", "ai_req_feat_cms"), ("seo", "ai_req_feat_seo"), ("analytics", "ai_req_feat_analytics"),
                                                ("chat", "ai_req_feat_chat"), ("maps", "ai_req_feat_maps"), ("newsletter", "ai_req_feat_newsletter"), ("social", "ai_req_feat_social")]
private let reqGoals: [(String, String)] = [("leads", "ai_req_goal_leads"), ("sales", "ai_req_goal_sales"), ("brand", "ai_req_goal_brand"), ("bookings", "ai_req_goal_bookings"),
                                             ("info", "ai_req_goal_info"), ("portfolio", "ai_req_goal_portfolio")]
private let reqLangs: [(String, String)] = [("ar", "ai_req_lang_ar"), ("en", "ai_req_lang_en"), ("fr", "ai_req_lang_fr"), ("other", "ai_req_lang_other")]
private let reqMaxFiles = 6

/// Everything a developer needs to quote and start without a first call. Chips store the localized label so the
/// dashboard reads naturally; the files travel in the same request.
struct SiteRequestForm: View {
    @Environment(\.wa) private var wa
    let onDone: (Int) -> Void
    @State private var step = 0
    @State private var busy = false
    @State private var error: String?
    @State private var name = ""
    @State private var type = "business"
    @State private var description = ""
    @State private var audience = ""
    @State private var goals: Set<String> = []
    @State private var pages: Set<String> = []
    @State private var features: Set<String> = []
    @State private var languages: Set<String> = []
    @State private var style = ""
    @State private var colors = ""
    @State private var refs = ""
    @State private var hasLogo: Bool?
    @State private var hasDomain: Bool?
    @State private var domain = ""
    @State private var contentReady: String?
    @State private var budgetMin = ""
    @State private var budgetMax = ""
    @State private var deadline = ""
    @State private var contactName = ""
    @State private var phone = ""
    @State private var method: String?
    @State private var contactTime = ""
    @State private var notes = ""
    @State private var files: [AiLocalFile] = []
    @State private var picking = false

    private let steps = 4

    private var canNext: Bool {
        switch step {
        case 0: return !name.trimmingCharacters(in: .whitespaces).isEmpty && description.trimmingCharacters(in: .whitespacesAndNewlines).count >= 10
        case 3: return !contactName.trimmingCharacters(in: .whitespaces).isEmpty && phone.filter(\.isNumber).count >= 7
        default: return true
        }
    }

    private func submit() {
        busy = true
        error = nil
        var fields: [String: String] = [:]
        func put(_ k: String, _ v: String) { let t = v.trimmingCharacters(in: .whitespacesAndNewlines); if !t.isEmpty { fields[k] = t } }
        func list(_ k: String, _ items: [String]) { for (i, v) in items.enumerated() { fields["\(k)[\(i)]"] = v } }
        put("business_name", name); put("site_type", type); put("business_description", description); put("target_audience", audience)
        list("goals", Array(goals)); list("pages", Array(pages)); list("features", Array(features)); list("languages", Array(languages))
        put("style_notes", style); put("colors", colors)
        list("reference_links", Array(refs.split(whereSeparator: { "\n ,".contains($0) }).map { String($0).trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }.prefix(5)))
        if let hasLogo { fields["has_logo"] = hasLogo ? "1" : "0" }
        if let hasDomain { fields["has_domain"] = hasDomain ? "1" : "0" }
        put("domain_name", domain)
        if let contentReady { put("content_ready", contentReady) }
        put("budget_min", budgetMin); put("budget_max", budgetMax); put("deadline_days", deadline)
        put("contact_name", contactName); put("contact_phone", phone)
        if let method { put("contact_method", method) }
        put("contact_time", contactTime); put("notes", notes)
        let sent = fields
        Task {
            do {
                let parts = try files.map { ApiClient.FilePart(field: "files[]", filename: $0.name, mimeType: $0.mime, data: try Data(contentsOf: $0.url)) }
                if let created = try await AiSitesApi.createRequest(fields: sent, files: parts).data { onDone(created.id) } else { error = L("ai_req_send_failed") }
            } catch {
                let m = error.apiFailure.message
                self.error = (m?.isEmpty == false) ? m : L("ai_req_send_failed")
            }
            busy = false
        }
    }

    var body: some View {
        let c = wa.site
        let titles = [L("ai_req_step_1"), L("ai_req_step_2"), L("ai_req_step_3"), L("ai_req_step_4")]
        let yes = L("ai_req_yes"), no = L("ai_req_no")
        let ready: [(String, String)] = [("ready", L("ai_req_content_ready")), ("partial", L("ai_req_content_partial")), ("none", L("ai_req_content_none"))]
        let methods: [(String, String)] = [("call", L("ai_req_m_call")), ("whatsapp", L("ai_req_m_whatsapp")), ("chat", L("ai_req_m_chat")), ("email", L("ai_req_m_email"))]
        VStack(spacing: 0) {
            VStack(spacing: 10) {
                HStack(spacing: 6) { ForEach(0..<steps, id: \.self) { i in Capsule().fill(i <= step ? wa.accent : c.line).frame(height: 5) } }
                HStack {
                    Text(verbatim: titles[step]).font(.dorr(17, .heavy)).foregroundStyle(c.ink)
                    Spacer()
                    Text(verbatim: L("ai_sites_step_of", step + 1, steps)).font(.dorr(12)).foregroundStyle(c.mut)
                }
            }
            .padding(.horizontal, 16).padding(.vertical, 6)
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    switch step {
                    case 0:
                        SiteField(label: L("ai_req_business_name"), text: $name)
                        label(L("ai_sites_f_type"))
                        PillGroup(options: siteTypes.map(siteTypeLabel), selected: [siteTypeLabel(type)], multi: false) { picked in
                            if let t = siteTypes.first(where: { picked.contains(siteTypeLabel($0)) }) { type = t }
                        }
                        SiteField(label: L("ai_req_business_desc"), text: $description, lines: 4...8, helper: L("ai_req_business_desc_hint")).padding(.top, 6)
                        SiteField(label: L("ai_req_audience"), text: $audience, lines: 2...4)
                        label(L("ai_req_goals"))
                        PillGroup(options: reqGoals.map { L($0.1) }, selected: goals) { goals = $0 }
                    case 1:
                        label(L("ai_req_pages"), L("ai_req_pick_all"))
                        PillGroup(options: reqPages.map { L($0.1) }, selected: pages) { pages = $0 }
                        label(L("ai_req_features"), L("ai_req_pick_all"))
                        PillGroup(options: reqFeatures.map { L($0.1) }, selected: features) { features = $0 }
                        label(L("ai_req_languages"))
                        PillGroup(options: reqLangs.map { L($0.1) }, selected: languages) { languages = $0 }
                        label(L("ai_req_content_ready_opt"))
                        PillGroup(options: ready.map(\.1), selected: Set(ready.filter { $0.0 == contentReady }.map(\.1)), multi: false) { picked in
                            contentReady = ready.first { picked.contains($0.1) }?.0
                        }
                    case 2:
                        SiteField(label: L("ai_req_style"), text: $style, lines: 3...6, helper: L("ai_req_style_hint"))
                        SiteField(label: L("ai_req_colors"), text: $colors)
                        SiteField(label: L("ai_req_refs"), text: $refs, lines: 2...4, keyboard: .URL, helper: L("ai_req_refs_hint")).textInputAutocapitalization(.never)
                        label(L("ai_req_has_logo"))
                        PillGroup(options: [yes, no], selected: hasLogo.map { [$0 ? yes : no] } ?? [], multi: false) { hasLogo = $0.first == yes }
                        label(L("ai_req_has_domain"))
                        PillGroup(options: [yes, no], selected: hasDomain.map { [$0 ? yes : no] } ?? [], multi: false) { hasDomain = $0.first == yes }
                        if hasDomain == true { SiteField(label: L("ai_req_domain_name"), text: $domain, keyboard: .URL).textInputAutocapitalization(.never) }
                    default:
                        SiteField(label: L("ai_req_contact_name"), text: $contactName)
                        SiteField(label: L("ai_req_contact_phone"), text: $phone, keyboard: .phonePad)
                        label(L("ai_req_contact_method"))
                        PillGroup(options: methods.map(\.1), selected: Set(methods.filter { $0.0 == method }.map(\.1)), multi: false) { picked in
                            method = methods.first { picked.contains($0.1) }?.0
                        }
                        SiteField(label: L("ai_req_contact_time"), text: $contactTime).padding(.top, 6)
                        HStack(spacing: 10) {
                            SiteField(label: L("ai_req_budget_min"), text: Binding(get: { budgetMin }, set: { budgetMin = $0.filter { $0.isNumber || $0 == "." } }), keyboard: .decimalPad)
                            SiteField(label: L("ai_req_budget_max"), text: Binding(get: { budgetMax }, set: { budgetMax = $0.filter { $0.isNumber || $0 == "." } }), keyboard: .decimalPad)
                        }
                        SiteField(label: L("ai_req_deadline"), text: Binding(get: { deadline }, set: { deadline = $0.filter(\.isNumber) }), keyboard: .numberPad)
                        SiteField(label: L("ai_req_notes"), text: $notes, lines: 3...6)
                        label(L("ai_req_attach"), L("ai_req_attach_hint", reqMaxFiles))
                        ForEach(Array(files.enumerated()), id: \.offset) { i, f in
                            HStack(spacing: 8) {
                                Image(systemName: "paperclip").foregroundStyle(c.mut)
                                Text(verbatim: f.name).font(.dorr(12.5)).foregroundStyle(c.ink).lineLimit(1)
                                Spacer()
                                Button { files.remove(at: i) } label: { Image(systemName: "xmark").foregroundStyle(c.danger) }.buttonStyle(.plain)
                            }
                            .padding(.horizontal, 12).padding(.vertical, 10)
                            .background(c.surface, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                            .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(c.line, lineWidth: 1))
                            .padding(.bottom, 6)
                        }
                        if files.count < reqMaxFiles { SiteSecondaryButton(label: L("ai_req_add_file")) { picking = true } }
                    }
                }
                .padding(.horizontal, 16).padding(.bottom, 16)
            }
            .scrollDismissesKeyboard(.interactively)
            if let error {
                Text(verbatim: error).font(.dorr(12.5, .semibold)).foregroundStyle(c.danger).lineSpacing(3)
                    .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 16).padding(.vertical, 10).background(c.danger.opacity(0.1))
            }
            HStack(spacing: 10) {
                if step > 0 { SiteSecondaryButton(label: L("ai_sites_back"), color: c.mut, enabled: !busy) { step -= 1 }.frame(maxWidth: 140) }
                if busy {
                    ProgressView().tint(wa.accent).frame(maxWidth: .infinity, minHeight: 52)
                } else {
                    SitePrimaryButton(label: L(step == steps - 1 ? "ai_req_send" : "ai_sites_next"), enabled: canNext) { if step == steps - 1 { submit() } else { step += 1 } }
                }
            }
            .padding(16)
            .background(c.bg)
        }
        .fileImporter(isPresented: $picking, allowedContentTypes: [.item], allowsMultipleSelection: true) { result in
            if case .success(let urls) = result {
                for u in urls.prefix(reqMaxFiles - files.count) { if let f = aiCopyDocument(u) { files.append(f) } }
            }
        }
    }

    private func label(_ text: String, _ hint: String? = nil) -> some View {
        let c = wa.site
        return VStack(alignment: .leading, spacing: 2) {
            Text(verbatim: text).font(.dorr(13.5, .bold)).foregroundStyle(c.ink)
            if let hint { Text(verbatim: hint).font(.dorr(11.5)).foregroundStyle(c.mut) }
        }
        .padding(.top, 14).padding(.bottom, 8)
    }
}

// MARK: - The request

private func reqMoney(_ value: Double?, _ currency: String?) -> String {
    let v = value ?? 0
    let text = v.truncatingRemainder(dividingBy: 1) == 0 ? String(Int64(v)) : String(format: "%.2f", v)
    return "\(text) \(currency ?? "")".trimmingCharacters(in: .whitespaces)
}

private func reqDate(_ iso: String?) -> String {
    guard let d = parseRideTime(iso) else { return "" }
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current)
    f.dateStyle = .medium
    return f.string(from: d)
}

private func reqTime(_ iso: String?) -> String {
    guard let d = parseRideTime(iso) else { return "" }
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current)
    f.dateStyle = .short
    f.timeStyle = .short
    return f.string(from: d)
}

/// 0…4 on the journey: received, quoted, building, delivered, done.
private func journeyIndex(_ code: String) -> Int {
    switch code {
    case "quote_sent", "quote_declined": return 1
    case "in_progress", "on_hold": return 2
    case "delivered": return 3
    case "completed": return 4
    default: return 0
    }
}

struct SiteRequestDetail: View {
    @Environment(\.wa) private var wa
    @Environment(\.scenePhase) private var scenePhase
    @EnvironmentObject private var network: NetworkMonitor
    let id: Int
    let onClosed: () -> Void
    @State private var request: AiSiteRequestDto?
    @State private var loadFailed = false
    @State private var tab = 0
    @State private var busy = false
    @State private var message: String?
    @State private var failed = false
    @State private var pinAction: ((String) async throws -> AiSiteRequestDto?)?
    @State private var pinSub = ""
    @State private var noPin = false
    @State private var decline = false
    @State private var reason = ""
    @State private var messages: [AiRequestMessageDto] = []
    @State private var draft = ""

    private func refresh() async {
        do {
            let r = try await AiSitesApi.request(id).data
            request = r
            loadFailed = r == nil
        } catch {
            if request == nil { loadFailed = true }
        }
    }

    private func refreshMessages() async {
        if let list = try? await AiSitesApi.requestMessages(id).data { messages = list }
    }

    private func withPin(_ subtitle: String, _ action: @escaping (String) async throws -> AiSiteRequestDto?) {
        aiNeedsPin(noPin: { noPin = true }, askPin: { pinSub = subtitle; pinAction = action })
    }

    private func act(_ call: @escaping () async throws -> AiSiteRequestDto?) {
        busy = true
        message = nil
        Task {
            do {
                if let r = try await call() { request = r }
                failed = false
            } catch {
                failed = true
                message = error.apiFailure.message
            }
            busy = false
        }
    }

    var body: some View {
        let c = wa.site
        Group {
            if let r = request {
                VStack(spacing: 0) {
                    ScrollViewReader { reader in
                        ScrollView {
                            VStack(alignment: .leading, spacing: 14) {
                                RequestHero(r: r)
                                tabs(unread: r.unread ?? 0)
                                switch tab {
                                case 0: overview(r)
                                case 1: payments(r)
                                default:
                                    if messages.isEmpty { Text(L("ai_req_chat_empty")).font(.dorr(13)).foregroundStyle(c.mut).frame(maxWidth: .infinity).padding(.vertical, 24) }
                                    ForEach(messages) { m in bubble(m) }
                                    Color.clear.frame(height: 1).id("end")
                                }
                                if let message { Text(verbatim: message).font(.dorr(12.5, .semibold)).foregroundStyle(failed ? c.danger : c.success) }
                            }
                            .padding(.horizontal, 16).padding(.top, 6).padding(.bottom, 24)
                        }
                        .scrollDismissesKeyboard(.interactively)
                        .refreshable { await refresh() }
                        .onChange(of: messages.count) { _ in if tab == 2 { withAnimation { reader.scrollTo("end", anchor: .bottom) } } }
                    }
                    if tab == 2 && !(r.status?.final == true && r.code != "completed") { composer }
                }
            } else {
                VStack {
                    if loadFailed { Text(L("ai_req_load_failed")).foregroundStyle(c.mut) } else { ProgressView().tint(wa.accent) }
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        // Like Android: the request every 20 s, the chat every 8 s while its tab is open.
        .task(id: id) {
            while !Task.isCancelled {
                await refresh()
                try? await Task.sleep(nanoseconds: 20_000_000_000)
            }
        }
        .task(id: "\(id)|\(tab)") {
            while tab == 2 && !Task.isCancelled {
                await refreshMessages()
                try? await Task.sleep(nanoseconds: 8_000_000_000)
            }
        }
        .onChange(of: scenePhase) { if $0 == .active { Task { await refresh() } } }
        .onChange(of: network.reconnectTick) { _ in Task { await refresh() } }
        .dorrDialog(isPresented: $decline) { declineDialog }
        .dorrDialog(isPresented: $noPin) { AiInfoDialog(message: L("ai_subscription_no_pin_set")) { noPin = false } }
        .dorrDialog(isPresented: Binding(get: { pinAction != nil }, set: { if !$0 { pinAction = nil } })) {
            if let action = pinAction {
                AiWalletPinDialog(subtitle: pinSub, onDismiss: { pinAction = nil }) { pin in
                    do {
                        if let r = try await action(pin) { request = r }
                        message = nil
                        return .ok
                    } catch {
                        return padFailure(error, networkError: L("ai_send_failed"))
                    }
                }
            }
        }
    }

    private func tabs(unread: Int) -> some View {
        let c = wa.site
        let items: [(String, String)] = [("square.grid.2x2.fill", "ai_req_tab_overview"), ("creditcard.fill", "ai_req_tab_payments"), ("bubble.left.and.bubble.right.fill", "ai_req_tab_chat")]
        return HStack(spacing: 4) {
            ForEach(Array(items.enumerated()), id: \.offset) { i, item in
                let on = i == tab
                Button { tab = i } label: {
                    HStack(spacing: 6) {
                        Image(systemName: item.0).font(.system(size: 14))
                        Text(L(item.1)).font(.dorr(12.5, on ? .heavy : .semibold)).lineLimit(1)
                        if i == 2 && unread > 0 && !on { Circle().fill(c.danger).frame(width: 8, height: 8) }
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
    }

    private func infoLine(_ label: String, _ value: String) -> some View {
        let c = wa.site
        return HStack {
            Text(verbatim: label).font(.dorr(12.5)).foregroundStyle(c.mut)
            Spacer()
            Text(verbatim: value).font(.dorr(13, .bold)).foregroundStyle(c.ink)
        }
        .padding(.vertical, 5)
    }

    @ViewBuilder private func overview(_ r: AiSiteRequestDto) -> some View {
        let c = wa.site
        let currency = r.currency
        let firstAmount = (r.installments ?? []).filter { $0.trigger == "on_accept" }.reduce(0) { $0 + ($1.amount ?? 0) }
        if let q = r.quote {
            SiteCard {
                SiteSectionTitle(title: L("ai_req_quote_title"))
                Text(verbatim: reqMoney(q.amount, currency)).font(.dorr(28, .heavy)).foregroundStyle(wa.accent).padding(.top, 10)
                infoLine(L("ai_req_duration"), L("ai_req_days", q.durationDays ?? 0)).padding(.top, 6)
                if r.code == "quote_sent" { infoLine(L("ai_req_valid_until"), reqDate(q.validUntil)) }
                if let n = q.notes, !n.isEmpty { Text(verbatim: n).font(.dorr(12.5)).foregroundStyle(c.mut).lineSpacing(4).padding(.top, 6) }
                if r.code == "quote_sent" {
                    if q.canAccept == true {
                        SitePrimaryButton(label: L("ai_req_accept", reqMoney(firstAmount, currency)), enabled: !busy) {
                            withPin(L("ai_req_pin_accept")) { pin in try await AiSitesApi.acceptQuote(id, pin: pin).data }
                        }
                        .padding(.top, 16)
                        SiteSecondaryButton(label: L("ai_req_decline"), color: c.danger, enabled: !busy) { decline = true }.padding(.top, 8)
                    } else {
                        Text(L("ai_req_quote_expired")).font(.dorr(12.5, .semibold)).foregroundStyle(c.danger).padding(.top, 16)
                    }
                }
            }
        }
        if r.quote != nil && r.code != "quote_sent" {
            SiteCard {
                SiteSectionTitle(title: L("ai_req_payments_summary")).padding(.bottom, 8)
                infoLine(L("ai_req_total"), reqMoney(r.payments?.total, currency))
                infoLine(L("ai_req_paid"), reqMoney(r.payments?.paid, currency))
                infoLine(L("ai_req_remaining"), reqMoney(r.payments?.remaining, currency))
                let total = r.payments?.total ?? 0
                let fraction = total > 0 ? min(max((r.payments?.paid ?? 0) / total, 0), 1) : 0
                GeometryReader { g in
                    ZStack(alignment: .leading) {
                        Capsule().fill(c.line)
                        Capsule().fill(c.success).frame(width: g.size.width * fraction)
                    }
                }
                .frame(height: 7)
                .padding(.top, 10)
            }
        }
        if let why = r.declineReason, !why.isEmpty, r.code == "quote_declined" {
            SiteCard {
                Text(L("ai_req_your_reason")).font(.dorr(12)).foregroundStyle(c.mut)
                Text(verbatim: why).font(.dorr(13)).foregroundStyle(c.ink).padding(.top, 4)
            }
        }
        if let d = r.delivery {
            SiteCard {
                SiteSectionTitle(title: L("ai_req_handover_title"), subtitle: L("ai_req_handover_sub"))
                if let n = d.notes, !n.isEmpty { Text(verbatim: n).font(.dorr(13)).foregroundStyle(c.ink).lineSpacing(4).padding(.top, 8) }
                if let u = d.url, !u.isEmpty {
                    SitePrimaryButton(label: L("ai_req_open_site")) { if let url = URL(string: u) { UIApplication.shared.open(url) } }.padding(.top, 12)
                }
            }
        }
        if ["submitted", "under_review", "quote_sent", "quote_declined"].contains(r.code) {
            SiteSecondaryButton(label: L("ai_req_cancel_request"), color: c.danger, enabled: !busy) { act { try await AiSitesApi.cancelRequest(id).data } }
        }
    }

    @ViewBuilder private func payments(_ r: AiSiteRequestDto) -> some View {
        let c = wa.site
        let installments = r.installments ?? []
        let milestones = r.milestones ?? []
        if installments.isEmpty && milestones.isEmpty {
            SiteCard { Text(L("ai_req_nothing_yet")).font(.dorr(13)).foregroundStyle(c.mut).lineSpacing(4) }
        }
        if !installments.isEmpty {
            SiteCard {
                SiteSectionTitle(title: L("ai_req_payments_title"), subtitle: L("ai_req_payments_sub")).padding(.bottom, 6)
                ForEach(installments) { p in installmentRow(p, currency: r.currency) }
            }
        }
        if !milestones.isEmpty {
            SiteCard {
                SiteSectionTitle(title: L("ai_req_schedule_title"), subtitle: L("ai_req_schedule_sub")).padding(.bottom, 6)
                ForEach(milestones) { m in
                    let done = m.status == "delivered"
                    HStack(alignment: .top, spacing: 12) {
                        Image(systemName: done ? "checkmark.circle.fill" : "circle").font(.system(size: 20)).foregroundStyle(done ? c.success : c.mut)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(verbatim: m.title ?? "").font(.dorr(14, .bold)).foregroundStyle(c.ink)
                            if let d = m.description, !d.isEmpty { Text(verbatim: d).font(.dorr(12)).foregroundStyle(c.mut) }
                            let sub = done ? L("ai_req_ms_delivered") : m.status == "in_progress" ? L("ai_req_ms_working") : m.dueAt != nil ? L("ai_req_ms_due", reqDate(m.dueAt)) : ""
                            if !sub.isEmpty { Text(verbatim: sub).font(.dorr(11.5, .semibold)).foregroundStyle(done ? c.success : c.mut).padding(.top, 1) }
                        }
                        Spacer(minLength: 0)
                    }
                    .padding(.vertical, 8)
                }
            }
        }
    }

    private func installmentRow(_ p: AiRequestInstallmentDto, currency: String?) -> some View {
        let c = wa.site
        let status = p.status ?? ""
        let color: Color = status == "paid" ? c.success : status == "due" ? c.warn : c.mut
        let sub: String = {
            switch status {
            case "paid": return L("ai_req_paid_on", reqDate(p.paidAt))
            case "due": return L("ai_req_due_now")
            default:
                switch p.trigger {
                case "on_milestone": return L("ai_req_when_milestone")
                case "on_delivery": return L("ai_req_when_delivery")
                default: return L("ai_req_when_accept")
                }
            }
        }()
        return HStack(spacing: 12) {
            Image(systemName: status == "paid" ? "checkmark.circle.fill" : "circle").font(.system(size: 22)).foregroundStyle(color)
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: p.title ?? "").font(.dorr(14, .bold)).foregroundStyle(c.ink)
                Text(verbatim: sub).font(.dorr(11.5)).foregroundStyle(status == "due" ? c.warn : c.mut)
            }
            Spacer(minLength: 0)
            VStack(alignment: .trailing, spacing: 6) {
                Text(verbatim: reqMoney(p.amount, currency)).font(.dorr(13.5, .heavy)).foregroundStyle(c.ink)
                if p.canPay == true {
                    Button { withPin(L("ai_req_pin_pay")) { pin in try await AiSitesApi.payInstallment(id, installment: p.id, pin: pin).data } } label: {
                        Text(L("ai_req_pay")).font(.dorr(12, .bold)).foregroundStyle(.white).padding(.horizontal, 16).padding(.vertical, 7)
                            .background(siteGradient(wa.accent), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    .disabled(busy)
                }
            }
        }
        .padding(.vertical, 8)
    }

    @ViewBuilder private func bubble(_ m: AiRequestMessageDto) -> some View {
        let c = wa.site
        if m.sender == "system" {
            Text(verbatim: m.body ?? "").font(.dorr(11.5)).foregroundStyle(c.mut).lineSpacing(3).multilineTextAlignment(.center)
                .padding(.horizontal, 12).padding(.vertical, 6)
                .background(c.field, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                .frame(maxWidth: .infinity)
        } else {
            let mine = m.sender == "customer"
            VStack(alignment: mine ? .trailing : .leading, spacing: 3) {
                Text(verbatim: m.body ?? "").font(.dorr(13.5)).foregroundStyle(mine ? .white : c.ink).lineSpacing(4)
                    .padding(.horizontal, 14).padding(.vertical, 10)
                    .background(mine ? wa.accent : c.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    .overlay { if !mine { RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(c.line, lineWidth: 1) } }
                    .frame(maxWidth: 300, alignment: mine ? .trailing : .leading)
                Text(verbatim: (mine ? "" : L("ai_req_team") + " · ") + reqTime(m.createdAt)).font(.dorr(10.5)).foregroundStyle(c.mut).padding(.horizontal, 4)
            }
            .frame(maxWidth: .infinity, alignment: mine ? .trailing : .leading)
        }
    }

    private var composer: some View {
        let c = wa.site
        let blank = draft.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        return HStack(alignment: .bottom, spacing: 10) {
            SiteField(label: L("ai_req_write_message"), text: $draft, lines: 1...4)
            Button {
                let text = draft.trimmingCharacters(in: .whitespacesAndNewlines)
                draft = ""
                Task {
                    do { messages = try await AiSitesApi.sendRequestMessage(id, body: text).data ?? messages } catch { draft = text; message = error.apiFailure.message; failed = true }
                }
            } label: {
                Image(systemName: "paperplane.fill").font(.system(size: 19)).foregroundStyle(.white).flipsForRightToLeftLayoutDirection(true)
                    .frame(width: 52, height: 52).background(blank ? wa.accent.opacity(0.35) : wa.accent, in: Circle())
            }
            .buttonStyle(.plain)
            .disabled(blank || busy)
            .padding(.bottom, 7)
        }
        .padding(.horizontal, 16).padding(.vertical, 10)
        .background(c.bg)
    }

    private var declineDialog: some View {
        let c = wa.site
        return VStack(alignment: .leading, spacing: 0) {
            Text(L("ai_req_decline_title")).font(.dorr(16, .heavy)).foregroundStyle(c.ink)
            Text(L("ai_req_decline_sub")).font(.dorr(12.5)).foregroundStyle(c.mut).padding(.top, 4).padding(.bottom, 8)
            SiteField(label: L("ai_req_decline_reason"), text: $reason, lines: 3...5)
            HStack(spacing: 10) {
                SiteSecondaryButton(label: L("ai_req_cancel"), color: c.mut) { decline = false }
                SiteSecondaryButton(label: L("ai_req_decline_confirm"), color: c.danger) {
                    decline = false
                    let why = reason
                    act { try await AiSitesApi.declineQuote(id, reason: why).data }
                }
            }
            .padding(.top, 10)
        }
        .padding(20)
        .background(c.surface, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
        .padding(.horizontal, 24)
        .contentShape(Rectangle())
        .onTapGesture {}
    }
}

private struct RequestHero: View {
    @Environment(\.wa) private var wa
    let r: AiSiteRequestDto

    var body: some View {
        let code = r.code
        let due = parseRideTime(r.timeline?.dueAt)
        let running = (code == "in_progress" || code == "on_hold") && due != nil
        VStack(alignment: .leading, spacing: 0) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: r.title ?? "").font(.dorr(20, .heavy)).foregroundStyle(.white).lineLimit(2)
                    Text(verbatim: r.reference ?? "").font(.dorr(12)).foregroundStyle(.white.opacity(0.8))
                }
                Spacer(minLength: 8)
                RequestStatusChip(name: r.status?.name ?? "", color: r.status?.color, onDark: true)
            }
            Group {
                if running, let due {
                    // The time left on the phone's clock (the due date is known).
                    TimelineView(.periodic(from: .now, by: 60)) { ctx in
                        let diff = Int(abs(due.timeIntervalSince(ctx.date)))
                        if due > ctx.date {
                            HStack(spacing: 12) {
                                Image(systemName: "timer").font(.system(size: 26)).foregroundStyle(.white)
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(L("ai_req_time_left")).font(.dorr(11.5)).foregroundStyle(.white.opacity(0.8))
                                    Text(verbatim: L("ai_req_days_hours", diff / 86400, (diff % 86400) / 3600)).font(.dorr(22, .heavy)).foregroundStyle(.white)
                                }
                                Spacer(minLength: 0)
                            }
                            .padding(14)
                            .background(Color.white.opacity(0.14), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        } else {
                            heroText(L("ai_req_hero_overdue"))
                        }
                    }
                } else {
                    heroText(heroMessage(code))
                }
            }
            .padding(.top, 14)
            if !["rejected", "cancelled"].contains(code) { journey(code).padding(.top, 16) }
        }
        .padding(18)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(siteGradient(wa.accent), in: RoundedRectangle(cornerRadius: 26, style: .continuous))
    }

    private func heroMessage(_ code: String) -> String {
        switch code {
        case "quote_sent": return L("ai_req_hero_quote")
        case "delivered": return L("ai_req_hero_delivered")
        case "completed": return L("ai_req_hero_completed")
        case "quote_declined": return L("ai_req_hero_declined")
        case "rejected", "cancelled": return L("ai_req_hero_closed")
        default: return L("ai_req_hero_review")
        }
    }

    private func heroText(_ text: String) -> some View {
        Text(verbatim: text).font(.dorr(13.5, .semibold)).foregroundStyle(.white).lineSpacing(4)
    }

    private func journey(_ code: String) -> some View {
        let labels = ["ai_req_j_received", "ai_req_j_quote", "ai_req_j_build", "ai_req_j_deliver", "ai_req_j_done"]
        let at = journeyIndex(code)
        return HStack(spacing: 0) {
            ForEach(Array(labels.enumerated()), id: \.offset) { i, key in
                VStack(spacing: 5) {
                    ZStack {
                        Circle().fill(i <= at ? Color.white : Color.white.opacity(0.25)).frame(width: 22, height: 22)
                        if i < at || code == "completed" {
                            Image(systemName: "checkmark.circle.fill").font(.system(size: 22)).foregroundStyle(wa.accent)
                        } else {
                            Text(verbatim: "\(i + 1)").font(.dorr(11, .heavy)).foregroundStyle(i <= at ? wa.accent : .white)
                        }
                    }
                    Text(L(key)).font(.dorr(10.5, .semibold)).foregroundStyle(.white.opacity(i <= at ? 1 : 0.6)).lineLimit(1).minimumScaleFactor(0.8)
                }
                .frame(maxWidth: .infinity)
            }
        }
    }
}
