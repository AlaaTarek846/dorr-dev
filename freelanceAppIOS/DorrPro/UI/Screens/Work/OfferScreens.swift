// freelanceApp `ui/screens/OfferScreens.kt`
import SwiftUI
import UniformTypeIdentifiers

private let MAX_OFFER_FILES = 5
private let DETAILS_MIN = 30
private let red = Color(hex: 0xDC2626)
private let amber = Color(hex: 0xD97706)
private let green = Color(hex: 0x16A34A)

/// A file picked from the phone, kept until the offer is sent.
final class PickedFile {
    let name: String
    let type: String
    let bytes: Data

    init(name: String, type: String, bytes: Data) {
        self.name = name
        self.type = type
        self.bytes = bytes
    }
}

/// A file chosen with `.fileImporter`, read into memory (its name and type as the phone gives them).
func readFile(_ url: URL) -> PickedFile? {
    let scoped = url.startAccessingSecurityScopedResource()
    defer { if scoped { url.stopAccessingSecurityScopedResource() } }
    guard let data = try? Data(contentsOf: url) else { return nil }
    let type = UTType(filenameExtension: url.pathExtension)?.preferredMIMEType ?? "application/octet-stream"
    return PickedFile(name: url.lastPathComponent.isEmpty ? "file" : url.lastPathComponent, type: type, bytes: data)
}

func offerStatusColor(_ status: String) -> Color {
    switch status {
    case "accepted": return Color(hex: 0x16A34A)
    case "shortlisted": return Color(hex: 0x2563EB)
    case "viewed": return Color(hex: 0xD97706)
    case "rejected", "withdrawn": return Color(hex: 0xDC2626)
    default: return Color(hex: 0x6B7280)
    }
}

func offerStatusLabel(_ status: String) -> String {
    switch status {
    case "viewed": return L("offer_status_viewed")
    case "shortlisted": return L("offer_status_shortlisted")
    case "accepted": return L("offer_status_accepted")
    case "rejected": return L("offer_status_rejected")
    case "withdrawn": return L("offer_status_withdrawn")
    default: return L("offer_status_submitted")
    }
}

/// The string key of a step of the offer's timeline, nil for one the app does not name.
private func offerEventKey(_ type: String?) -> String? {
    switch type {
    case "offer.submitted": return "offer_ev_submitted"
    case "offer.viewed": return "offer_ev_viewed"
    case "offer.shortlisted": return "offer_ev_shortlisted"
    case "offer.rejected": return "offer_ev_rejected"
    case "offer.accepted": return "offer_ev_accepted"
    case "offer.edited": return "offer_ev_edited"
    case "offer.withdrawn": return "offer_ev_withdrawn"
    default: return nil
    }
}

private func blank(_ s: String) -> Bool { s.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }

private func trimmed(_ s: String) -> String { s.trimmingCharacters(in: .whitespacesAndNewlines) }

/// A small brand pill (the quota lines of the form).
private struct OfferPill: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        Text(verbatim: text).font(.dorr(12, .bold)).foregroundStyle(wa.red)
            .padding(.horizontal, 12).padding(.vertical, 5)
            .background(wa.red.opacity(0.10), in: Capsule())
            .frame(maxWidth: .infinity, alignment: .leading)
    }
}

/// What the price leaves me after the commission.
private struct BreakdownBox: View {
    @Environment(\.wa) private var wa
    let b: OfferBreakdownDto
    var radius: CGFloat = 16
    var pad: CGFloat = 14

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(L("offer_commission", "\u{200E}\(formatMinor(b.commissionMinor ?? 0, nil)) \(b.currency ?? "")")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            Text(L("offer_net", "\u{200E}\(formatMinor(b.netMinor ?? 0, nil)) \(b.currency ?? "")")).font(.dorr(15, .heavy)).foregroundStyle(wa.red)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(pad)
        .background(wa.red.opacity(0.08), in: RoundedRectangle(cornerRadius: radius, style: .continuous))
    }
}

/// One earlier version of the offer, in the history.
private struct RevisionBox: View {
    @Environment(\.wa) private var wa
    let r: OfferRevisionDto
    let currency: String

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(L("offer_revision", r.number ?? 0, "\u{200E}\(formatMinor(r.priceMinor ?? 0, nil)) \(currency)", r.durationDays ?? 0))
                .font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk)
            Text(verbatim: r.details ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(3)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(10)
        .background(wa.settingsMut.opacity(0.08), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
    }
}

// MARK: - sending an offer

/// Sending an offer (FL-301): first whether I may (verified, taking work, the project open, not offered already, not full, quota left —
/// the server says which, and the form is not shown), then the price with what it leaves me after the commission (asked 400 ms after the
/// last key), the time it takes, the details and up to 5 files. Sent once; back on the project page it shows as my offer.
@MainActor
struct OfferFormScreen: View {
    let projectUuid: String
    let onBack: () -> Void
    let onSent: () -> Void
    let onOpenProfile: () -> Void
    let onOpenPlans: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor

    @State private var preview: OfferPreviewDto?
    @State private var breakdown: OfferBreakdownDto?
    @State private var price = ""
    @State private var duration = ""
    @State private var details = ""
    @State private var files: [PickedFile] = []
    @State private var busy = false
    @State private var error: String?

    @State private var checks: [OfferCheckDto] = []
    @State private var templates: [OfferTemplateDto] = []
    // FL-307: the client's questions, one answer each.
    @State private var questions: [String] = []
    @State private var answers: [String] = []
    @State private var showTemplates = false
    @State private var savingTemplate = false
    @State private var templateNotice: String?
    @State private var picking = false

    var body: some View {
        Page(title: L("offer_title"), onBack: onBack) {
            if let p = preview {
                if let blocked = p.blocked {
                    blockedView(blocked, p)
                } else {
                    form(p)
                }
            } else if error == nil {
                SkeletonDetail()
            }
        }
        .messageToast(error)
        .messageToast(templateNotice)
        .task(id: "\(projectUuid)|\(network.reconnectTick)") {
            let result = await OffersApi.preview(projectUuid)
            if let data = result.value { preview = data } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
            if let list = await OffersApi.templates().value { templates = list }
            if let q = await ProjectsApi.show(projectUuid).value?.questions {
                questions = q
                while answers.count < q.count { answers.append("") }
            }
        }
        // FL-304: what looks weak, asked once the typing stops (not on a timer).
        .task(id: "\(price)|\(duration)|\(details)|\(files.count)") {
            if blank(details) && blank(price) { checks = []; return }
            try? await Task.sleep(nanoseconds: 700_000_000)
            if Task.isCancelled { return }
            let body = OfferCheckRequest(priceMinor: parseMinor(price), durationDays: Int(duration), details: trimmed(details), files: files.count)
            if let data = await OffersApi.check(projectUuid, body).value { checks = data.checks ?? [] }
        }
        // What the price leaves me, asked once the typing stops.
        .task(id: price) {
            guard let minor = parseMinor(price) else { breakdown = nil; return }
            try? await Task.sleep(nanoseconds: 400_000_000)
            if Task.isCancelled { return }
            if let b = await OffersApi.preview(projectUuid, priceMinor: minor).value?.breakdown { breakdown = b }
        }
        .fileImporter(isPresented: $picking, allowedContentTypes: [.item]) { result in
            if case .success(let url) = result, files.count < MAX_OFFER_FILES, let f = readFile(url) { files.append(f) }
        }
        .dorrDialog(isPresented: $showTemplates) { templatesDialog }
        .dorrDialog(isPresented: $savingTemplate) {
            SaveTemplateDialog(details: trimmed(details), onSaved: { t in
                templates = [t] + templates
                templateNotice = L("offer_template_saved")
                savingTemplate = false
            }, onDismiss: { savingTemplate = false })
        }
    }

    @ViewBuilder
    private func blockedView(_ blocked: OfferBlockedDto, _ p: OfferPreviewDto) -> some View {
        // The server's reason in its own words, and nothing to fill in.
        let code = blocked.code ?? ""
        let verify = code.contains("verif") || code.contains("kyc") || code.contains("approved")
        let icon: String = verify ? "checkmark.shield.fill"
            : code == "offer_already_sent" ? "checkmark.circle.fill"
            : code == "offer_quota_exhausted" ? "hourglass"
            : code == "project_invite_only" ? "lock.fill"
            : "nosign"
        VStack(spacing: 0) {
            Image(systemName: icon).font(.system(size: 34, weight: .semibold)).foregroundStyle(amber)
                .frame(width: 76, height: 76)
                .background(amber.opacity(0.16), in: Circle())
                .frame(width: 104, height: 104)
                .background(amber.opacity(0.10), in: Circle())
            Spacer().frame(height: 22)
            Text(L("offer_blocked_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
            Spacer().frame(height: 10)
            Text(verbatim: blocked.message ?? "").font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).lineSpacing(6)
            Spacer().frame(height: 24)
            if verify {
                SettingsPrimaryButton(text: L("offer_go_profile"), action: onOpenProfile)
            } else if code == "offer_quota_exhausted" && p.quota?.packs == true {
                SettingsPrimaryButton(text: L("offer_buy_more"), action: onOpenPlans)
            } else {
                SettingsPrimaryButton(text: L("common_back"), action: onBack)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.top, 60)
        .padding(.horizontal, 12)
    }

    @ViewBuilder
    private func form(_ p: OfferPreviewDto) -> some View {
        let detailsLength = trimmed(details).count
        // The same look as the user app's forms: a short intro, then fields with an icon each.
        HStack(spacing: 12) {
            Image(systemName: "tag.fill").font(.system(size: 22, weight: .semibold)).foregroundStyle(wa.red)
                .frame(width: 52, height: 52)
                .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            VStack(alignment: .leading, spacing: 3) {
                Text(L("offer_form_intro_title")).font(.dorr(19, .heavy)).foregroundStyle(wa.settingsInk)
                Text(L("offer_form_intro")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        let quota = p.quota
        if let credits = quota?.credits, credits > 0 { OfferPill(text: L("offer_quota_extra", credits)) }
        if quota?.enabled == true && quota?.limit == nil { OfferPill(text: L("offer_quota_unlimited")) }
        if let left = quota?.left { OfferPill(text: L("offer_quota_left", left, quota?.limit ?? left)) }
        DorrTextField(value: $price, label: L("offer_price"), placeholder: "0.00", icon: "banknote.fill", keyboard: .decimalPad)
        if let min = p.minPriceMinor {
            Text(L("offer_price_min", "\u{200E}\(formatMinor(min, nil))")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        if let b = breakdown { BreakdownBox(b: b) }
        DorrTextField(value: Binding(get: { duration }, set: { duration = String($0.filter(\.isWholeNumberDigit).prefix(3)) }),
                      label: L("offer_duration"), icon: "clock.fill", keyboard: .numberPad)
        DorrTextField(value: Binding(get: { details }, set: { details = String($0.prefix(3000)) }),
                      label: L("offer_details"), placeholder: L("offer_details_hint"), icon: "text.alignleft", singleLine: false, minLines: 5)
        Text(L("offer_details_count", detailsLength, DETAILS_MIN)).font(.dorr(11.5)).foregroundStyle(detailsLength < DETAILS_MIN ? wa.settingsMut : green)
            .frame(maxWidth: .infinity, alignment: .leading)
        // FL-304: start from a kept text, or keep this one.
        if !templates.isEmpty || detailsLength >= DETAILS_MIN {
            HStack(spacing: 10) {
                if !templates.isEmpty { softChip(L("offer_use_template")) { showTemplates = true } }
                if detailsLength >= DETAILS_MIN { softChip(L("offer_save_template")) { savingTemplate = true } }
                Spacer(minLength: 0)
            }
        }
        if !questions.isEmpty {
            FieldLabel(text: L("offer_questions")).frame(maxWidth: .infinity, alignment: .leading)
            ForEach(Array(questions.enumerated()), id: \.offset) { i, q in
                DorrTextField(
                    value: Binding(get: { i < answers.count ? answers[i] : "" }, set: { if i < answers.count { answers[i] = String($0.prefix(1000)) } }),
                    label: "\(i + 1). \(q)", placeholder: L("offer_answer"), icon: "bubble.left.and.bubble.right.fill", singleLine: false, minLines: 2
                )
            }
        }
        FieldLabel(text: L("offer_files")).frame(maxWidth: .infinity, alignment: .leading)
        VStack(spacing: 8) {
            ForEach(Array(files.enumerated()), id: \.offset) { index, f in
                HStack(spacing: 8) {
                    Image(systemName: "doc.text.fill").font(.system(size: 15)).foregroundStyle(wa.red)
                    Text(verbatim: f.name).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                    Button { files.remove(at: index) } label: {
                        Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut).frame(width: 28, height: 28).contentShape(Circle())
                    }
                    .buttonStyle(.plain)
                }
            }
            if files.count < MAX_OFFER_FILES {
                Button { picking = true } label: {
                    HStack(spacing: 6) {
                        Image(systemName: "paperclip").font(.system(size: 15, weight: .semibold))
                        Text(L("offer_add_file")).font(.dorr(13, .bold))
                    }
                    .foregroundStyle(wa.red)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
                    .background(wa.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity)
        .settingsSurface(18)
        if !checks.isEmpty {
            VStack(alignment: .leading, spacing: 4) {
                Text(L("offer_checks_title")).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk)
                ForEach(Array(checks.enumerated()), id: \.offset) { _, c in
                    Text(verbatim: "•  " + (c.message ?? "")).font(.dorr(13)).foregroundStyle(c.level == "error" ? red : amber).lineSpacing(4)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(14)
            .background(amber.opacity(0.08), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
        SettingsPrimaryButton(
            text: L("offer_send"),
            enabled: parseMinor(price) != nil && (Int(duration) ?? 0) > 0 && detailsLength >= DETAILS_MIN
                && !checks.contains { $0.level == "error" } && answers.prefix(questions.count).allSatisfy { trimmed($0).count >= 2 },
            loading: busy,
            action: send
        )
    }

    private func softChip(_ text: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                .padding(.horizontal, 14).padding(.vertical, 7)
                .background(wa.red.opacity(0.10), in: Capsule())
        }
        .buttonStyle(.plain)
    }

    private var templatesDialog: some View {
        DialogCard {
            VStack(alignment: .leading, spacing: 14) {
                Text(L("offer_templates")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                ScrollView {
                    VStack(spacing: 10) {
                        ForEach(Array(templates.enumerated()), id: \.offset) { _, t in
                            HStack(spacing: 0) {
                                Button {
                                    details = t.details ?? ""
                                    showTemplates = false
                                } label: {
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(verbatim: t.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                                        Text(verbatim: t.details ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(2)
                                    }
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    .padding(6)
                                    .contentShape(Rectangle())
                                }
                                .buttonStyle(.plain)
                                Button {
                                    guard let id = t.id else { return }
                                    Task { if await OffersApi.deleteTemplate(id).value != nil { templates = templates.filter { $0.id != id } } }
                                } label: {
                                    Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut).frame(width: 30, height: 30).contentShape(Circle())
                                }
                                .buttonStyle(.plain)
                                .accessibilityLabel(L("offer_template_delete"))
                            }
                        }
                    }
                }
                .frame(maxHeight: 360)
                .fixedSize(horizontal: false, vertical: true)
                HStack {
                    Spacer()
                    Button(L("common_cancel")) { showTemplates = false }.font(.dorr(14, .bold)).foregroundStyle(wa.settingsMut)
                }
            }
            .padding(20)
        }
    }

    private func send() {
        guard let minor = parseMinor(price) else { error = L("offer_price_invalid"); return }
        busy = true
        error = nil
        var fields: [String: String] = ["price_minor": String(minor), "duration_days": duration, "details": trimmed(details)]
        for (i, a) in answers.enumerated() { fields["answers[\(i)]"] = trimmed(a) }
        let parts = files.map { FilePart(field: "files[]", filename: $0.name, mimeType: $0.type, data: $0.bytes) }
        Task {
            let result = await OffersApi.send(projectUuid, fields: fields, files: parts)
            busy = false
            if result.value != nil { onSent() } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}

/// Keeping the details typed as a reusable text: its name, then save.
private struct SaveTemplateDialog: View {
    @Environment(\.wa) private var wa
    let details: String
    let onSaved: (OfferTemplateDto) -> Void
    let onDismiss: () -> Void
    @State private var name = ""
    @State private var saveError: String?

    var body: some View {
        DialogCard {
            VStack(alignment: .leading, spacing: 14) {
                Text(L("offer_save_template")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                VStack(alignment: .leading, spacing: 6) {
                    DorrTextField(value: Binding(get: { name }, set: { name = String($0.prefix(80)) }), placeholder: L("offer_template_name"))
                    if let saveError { Text(verbatim: saveError).font(.dorr(12)).foregroundStyle(red) }
                }
                HStack(spacing: 16) {
                    Spacer()
                    Button(L("common_cancel"), action: onDismiss).font(.dorr(14, .bold)).foregroundStyle(wa.settingsMut)
                    Button(L("offer_template_keep")) {
                        Task {
                            let result = await OffersApi.saveTemplate(OfferTemplateRequest(title: trimmed(name), details: details))
                            if let t = result.value { onSaved(t) } else { saveError = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
                        }
                    }
                    .font(.dorr(14, .bold))
                    .foregroundStyle(wa.red.opacity(blank(name) ? 0.4 : 1))
                    .disabled(blank(name))
                }
            }
            .padding(20)
        }
    }
}

// MARK: - my offers

/// All my offers, newest first, fifteen at a time while I scroll (read when the page opens, when a notice arrives and when the connection returns).
@MainActor
struct MyOffersScreen: View {
    let onBack: () -> Void
    let onOpenProject: (String) -> Void
    var asTab = false

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @StateObject private var paged = PagedRows<OfferDto>(perPage: 15, key: { $0.uuid ?? "" }, fetch: { page in await OffersApi.list(page: page, perPage: 15) })
    @State private var filter = "all"

    private static let statuses = ["all", "submitted", "viewed", "shortlisted", "accepted", "rejected", "withdrawn"]

    var body: some View {
        Group {
            if asTab {
                TabPage(title: L("offer_my_offers"), onBack: onBack, onNearEnd: nearEnd) { content }
            } else {
                Page(title: L("offer_my_offers"), onBack: onBack, onNearEnd: nearEnd) { content }
            }
        }
        .messageToast(paged.failure.map { $0.errorText(L("login_error_offline")) ?? L("login_error_generic") })
        .task(id: network.reconnectTick) { await paged.reload() }
        // A notice arrived: one reload of the first page.
        .onReceive(Realtime.shared.changed) { _ in Task { await paged.reload() } }
    }

    private func nearEnd() { Task { await paged.loadMore() } }

    @ViewBuilder
    private var content: some View {
        let all: [OfferDto]? = paged.loaded ? paged.rows : nil
        let list = all?.filter { filter == "all" || ($0.status ?? "submitted") == filter }
        if let all, !all.isEmpty {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(Self.statuses, id: \.self) { s in
                        ProjectChip(text: s == "all" ? L("projects_all") : offerStatusLabel(s), selected: filter == s, icon: Self.icon(s)) { filter = s }
                    }
                }
            }
        }
        if all == nil && paged.failure == nil {
            SkeletonList()
        } else if let all, all.isEmpty, paged.failure == nil {
            EmptyState(icon: "tag.fill", title: L("offer_empty_title"), body: L("offer_empty_body")).padding(.top, 90)
        } else if let list, list.isEmpty, !paged.hasMore {
            Text(L("offer_none_filtered")).font(.dorr(14)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
        } else if let list {
            ForEach(list) { o in
                OfferListCard(offer: o) { if let uuid = o.project?.uuid { onOpenProject(uuid) } }
            }
        }
        PagedFooter(paged: paged) { Task { await paged.loadMore() } }
    }

    private static func icon(_ s: String) -> String {
        switch s {
        case "submitted": return "paperplane.fill"
        case "viewed": return "eye.fill"
        case "shortlisted": return "star.fill"
        case "accepted": return "checkmark.circle.fill"
        case "rejected": return "xmark.circle.fill"
        case "withdrawn": return "arrow.uturn.backward"
        default: return "square.grid.2x2.fill"
        }
    }
}

// MARK: - changing an offer

/// Changing an offer that is still open (FL-302): the price (with what it leaves me), the days and the details. What it said before is
/// kept for the history.
@MainActor
struct EditOfferScreen: View {
    let projectUuid: String
    let onBack: () -> Void
    let onDone: () -> Void

    @Environment(\.wa) private var wa
    @State private var offer: OfferDto?
    @State private var price = ""
    @State private var duration = ""
    @State private var details = ""
    @State private var breakdown: OfferBreakdownDto?
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        Page(title: L("offer_edit"), onBack: onBack) {
            if let current = offer {
                let detailsLength = trimmed(details).count
                Card {
                    FieldLabel(text: L("offer_price"))
                    DorrTextField(value: $price, keyboard: .decimalPad)
                    if let b = breakdown { BreakdownBox(b: b, radius: 14, pad: 12) }
                    DorrTextField(value: Binding(get: { duration }, set: { duration = String($0.filter(\.isWholeNumberDigit).prefix(3)) }),
                                  label: L("offer_duration"), keyboard: .numberPad)
                    DorrTextField(value: Binding(get: { details }, set: { details = String($0.prefix(3000)) }),
                                  label: L("offer_details"), singleLine: false, minLines: 5)
                    Text(L("offer_details_count", detailsLength, DETAILS_MIN)).font(.dorr(11)).foregroundStyle(detailsLength < DETAILS_MIN ? wa.settingsMut : green)
                }
                Text(L("offer_edit_note")).font(.dorr(12)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                SettingsPrimaryButton(
                    text: L("offer_save_changes"),
                    enabled: parseMinor(price) != nil && (Int(duration) ?? 0) > 0 && detailsLength >= DETAILS_MIN,
                    loading: busy
                ) { save(current) }
            } else if error == nil {
                SkeletonDetail()
            }
        }
        .messageToast(error)
        .task(id: projectUuid) {
            let result = await OffersApi.mine(projectUuid)
            if let current = result.value?.offer {
                offer = current
                price = formatMinor(current.priceMinor ?? 0, nil)
                duration = String(current.durationDays ?? 0)
                details = current.details ?? ""
            } else if let e = result.error {
                error = e.displayMessage
            } else {
                error = L("login_error_generic")
            }
        }
        .task(id: price) {
            guard let minor = parseMinor(price) else { breakdown = nil; return }
            try? await Task.sleep(nanoseconds: 400_000_000)
            if Task.isCancelled { return }
            if let b = await OffersApi.preview(projectUuid, priceMinor: minor).value?.breakdown { breakdown = b }
        }
    }

    private func save(_ current: OfferDto) {
        guard let minor = parseMinor(price) else { error = L("offer_price_invalid"); return }
        busy = true
        error = nil
        Task {
            let result = await OffersApi.update(current.uuid ?? "", OfferUpdateRequest(priceMinor: minor, durationDays: Int(duration) ?? 0, details: trimmed(details)))
            busy = false
            if result.value != nil { onDone() } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}

/// An offer in my offers: small and easy to scan - what it is on, its state, the price and the time; the whole card opens the offer's page.
struct OfferListCard: View {
    @Environment(\.wa) private var wa
    let offer: OfferDto
    let onClick: () -> Void

    var body: some View {
        let status = offer.status ?? "submitted"
        let tint = offerStatusColor(status)
        Button(action: onClick) {
            HStack(spacing: 0) {
                Image(systemName: "tag.fill").font(.system(size: 17)).foregroundStyle(tint)
                    .frame(width: 40, height: 40)
                    .background(tint.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                Spacer().frame(width: 10)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: offer.project?.title ?? L("offer_mine")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Text(verbatim: "\u{200E}\(formatMinor(offer.priceMinor ?? 0, nil)) \(offer.currency ?? "")  ·  " + L("projects_days", offer.durationDays ?? 0))
                        .font(.dorr(12, .bold)).foregroundStyle(wa.red).lineLimit(1)
                    if let day = offer.createdAt?.prefix(10) { Text(verbatim: "\u{200E}\(day)").font(.dorr(11)).foregroundStyle(wa.settingsMut) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Spacer().frame(width: 8)
                Text(verbatim: offerStatusLabel(status)).font(.dorr(11, .bold)).foregroundStyle(tint)
                    .padding(.horizontal, 9).padding(.vertical, 3)
                    .background(tint.opacity(0.12), in: Capsule())
                Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 20, height: 20)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .frame(maxWidth: .infinity)
            .settingsSurface(16)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - one offer

/// One offer of mine with everything, in sections: what it is on and where it stands, the money and the time, the details, my answers, the
/// files, where it has been, the agreement and the chat when there are, changing or withdrawing it while that is allowed - and a button to the project.
@MainActor
struct OfferDetailScreen: View {
    let projectUuid: String
    let onBack: () -> Void
    let onOpenProject: () -> Void
    let onEdit: () -> Void
    let onChat: (String) -> Void
    let onAgreement: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var offer: OfferDto?
    @State private var error: String?
    @State private var confirmWithdraw = false
    @State private var busy = false
    @State private var history: [OfferRevisionDto]?
    @State private var showHistory = false

    var body: some View {
        Page(title: L("offer_detail_title"), onBack: onBack) {
            if let o = offer {
                content(o)
            } else if error == nil {
                SkeletonDetail()
            }
        }
        .messageToast(error)
        .task(id: "\(network.reconnectTick)|\(projectUuid)") { await load() }
        .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
        .alert("", isPresented: $confirmWithdraw) {
            Button(L("offer_withdraw"), role: .destructive) { withdraw() }
            Button(L("common_cancel"), role: .cancel) {}
        } message: {
            Text(L("offer_withdraw_confirm"))
        }
    }

    private func load() async {
        let result = await OffersApi.mine(projectUuid)
        if let current = result.value?.offer {
            offer = current
            error = nil
        } else {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    private func withdraw() {
        guard let current = offer, !busy else { return }
        busy = true
        Task {
            let result = await OffersApi.withdraw(current.uuid ?? "")
            busy = false
            confirmWithdraw = false
            if let data = result.value { offer = data } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }

    @ViewBuilder
    private func content(_ o: OfferDto) -> some View {
        let status = o.status ?? "submitted"
        let tint = offerStatusColor(status)
        let currency = o.currency ?? ""
        let money = "\u{200E}" + formatMinor(o.priceMinor ?? 0, nil) + " " + currency

        // The offer and where it stands.
        VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 12) {
                Image(systemName: "tag.fill").font(.system(size: 19)).foregroundStyle(tint)
                    .frame(width: 44, height: 44)
                    .background(tint.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 4) {
                    Text(verbatim: o.project?.title ?? L("offer_mine")).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    Text(verbatim: offerStatusLabel(status)).font(.dorr(11, .bold)).foregroundStyle(tint)
                        .padding(.horizontal, 10).padding(.vertical, 3)
                        .background(tint.opacity(0.12), in: Capsule())
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            HStack(spacing: 8) {
                OfferStat(value: money, label: L("offer_stat_price")).frame(maxWidth: .infinity)
                OfferStat(value: "\u{200E}" + formatMinor(o.netMinor ?? 0, nil) + " " + currency, label: L("offer_stat_net")).frame(maxWidth: .infinity)
                OfferStat(value: L("projects_days", o.durationDays ?? 0), label: L("offer_stat_time")).frame(maxWidth: .infinity)
            }
            if let day = o.createdAt?.prefix(10) {
                Text(L("offer_sent_on", "\u{200E}\(day)")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20)

        if let reason = o.statusReason, !blank(reason) {
            Text(L("offer_reject_reason", reason)).font(.dorr(13)).foregroundStyle(red).lineSpacing(4)
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(14)
                .background(red.opacity(0.08), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }

        OfferSection(title: L("offer_section_details")) {
            Text(verbatim: o.details ?? "").font(.dorr(13.5)).foregroundStyle(wa.settingsInk).lineSpacing(6)
        }

        let answers = o.answers ?? []
        if !answers.isEmpty {
            OfferSection(title: L("offer_section_answers")) {
                ForEach(Array(answers.enumerated()), id: \.offset) { _, a in
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: a.question ?? "").font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                        Text(verbatim: blank(a.answer ?? "") ? "—" : (a.answer ?? "")).font(.dorr(13.5)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                    }
                }
            }
        }

        let attachments = o.attachments ?? []
        if !attachments.isEmpty {
            OfferSection(title: L("offer_attached_files")) {
                ForEach(Array(attachments.enumerated()), id: \.offset) { _, f in
                    HStack(spacing: 8) {
                        Image(systemName: "paperclip").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red)
                        Text(verbatim: f.name ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    }
                }
            }
        }

        // Where it has been: sent, seen, shortlisted … with the day, on a line.
        let steps: [(key: String, day: String)] = (o.timeline ?? []).compactMap { e in
            offerEventKey(e.type).map { ($0, String((e.at ?? "").prefix(10))) }
        }
        if !steps.isEmpty {
            OfferSection(title: L("offer_section_timeline")) {
                ForEach(Array(steps.enumerated()), id: \.offset) { index, step in
                    let last = index == steps.count - 1
                    HStack(spacing: 12) {
                        Circle().fill(last ? tint : wa.settingsMut.opacity(0.35)).frame(width: 10, height: 10)
                        Text(L(step.key)).font(.dorr(13, last ? .bold : .medium)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                        Text(verbatim: "\u{200E}\(step.day)").font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                    }
                }
                if let revisions = o.revisions, revisions > 0 {
                    Button {
                        showHistory.toggle()
                        if history == nil, let uuid = o.uuid { Task { if let list = await OffersApi.revisions(uuid).value { history = list } } }
                    } label: {
                        Text(L("offer_edited_n", revisions)).font(.dorr(12, .bold)).foregroundStyle(wa.red).padding(.vertical, 4)
                    }
                    .buttonStyle(.plain)
                }
                if showHistory, let history {
                    ForEach(Array(history.enumerated()), id: \.offset) { _, r in RevisionBox(r: r, currency: currency) }
                }
            }
        }

        // The agreement and the chat, as rows that open them.
        if let ag = o.agreement {
            let agStatus = ag.status ?? "pending_approval"
            OfferLinkRow(icon: "doc.text.fill", title: L("ag_title"), trailing: agreementStatusText(agStatus),
                         tint: agStatus == "pending_approval" ? amber : wa.red, onClick: onAgreement)
        }
        if let room = o.chat {
            let unread = room.unread ?? 0
            OfferLinkRow(icon: "bubble.left.and.bubble.right.fill", title: L("chat_open"), trailing: unread > 0 ? String(unread) : "", tint: wa.red) {
                onChat(room.uuid ?? "")
            }
        }

        SettingsPrimaryButton(text: L("offer_view_project"), action: onOpenProject)
        if o.can?.edit == true {
            Button(action: onEdit) {
                Text(L("offer_edit")).font(.dorr(14, .bold)).foregroundStyle(wa.red)
                    .frame(maxWidth: .infinity).padding(.vertical, 13)
                    .background(wa.red.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            .buttonStyle(.plain)
        }
        if o.can?.withdraw == true {
            Button { confirmWithdraw = true } label: {
                Text(L("offer_withdraw")).font(.dorr(14, .bold)).foregroundStyle(red)
                    .frame(maxWidth: .infinity).padding(.vertical, 13)
                    .background(red.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            .buttonStyle(.plain)
        }
    }
}

// MARK: - shared parts

/// A titled card of the offer page.
struct OfferSection<Content: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(verbatim: title).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
            content()
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(18)
    }
}

/// One number of the offer (the price, what it leaves me, the time) on a soft tile.
struct OfferStat: View {
    @Environment(\.wa) private var wa
    let value: String
    let label: String

    var body: some View {
        VStack(spacing: 3) {
            Text(verbatim: value).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1).minimumScaleFactor(0.8).multilineTextAlignment(.center)
            Text(verbatim: label).font(.dorr(10.5)).foregroundStyle(wa.settingsMut).lineLimit(1)
        }
        .frame(maxWidth: .infinity)
        .padding(.horizontal, 6)
        .padding(.vertical, 10)
        .background(wa.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// A row that opens the agreement or the chat of the offer.
struct OfferLinkRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let trailing: String
    let tint: Color
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(spacing: 0) {
                Image(systemName: icon).font(.system(size: 16)).foregroundStyle(tint)
                    .frame(width: 36, height: 36)
                    .background(tint.opacity(0.12), in: RoundedRectangle(cornerRadius: 11, style: .continuous))
                Spacer().frame(width: 12)
                Text(verbatim: title).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                if !trailing.isEmpty { Text(verbatim: trailing).font(.dorr(12, .bold)).foregroundStyle(tint) }
                Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 20, height: 20)
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 12)
            .settingsSurface(16)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}
