import SwiftUI

// androidApp `freelance/FreelanceRights.kt` — FL-L13, ownership and licences as the client sees them: the terms on an offer and a ready-made
// service (what the freelancer says), the terms kept in the agreement with how far the rights have passed (a part is theirs once approved
// and paid; before that the client only previews and reviews) and the leave to show the finished work in the portfolio, the freelancer's
// word with each delivery, and reporting a work shown on Dorr that is not the freelancer's own. Everything here is nil-safe: the feature
// off in a country sends no `rights`.

func rightsModelColor(_ model: String?) -> Color {
    switch model {
    case "transfer": return flGreen
    case "license": return Color(hex: 0x2563EB)
    default: return flAmber
    }
}

/// The lines of the terms, each with a small tick.
private struct RightsClauses: View {
    @Environment(\.wa) private var wa
    let clauses: [String]

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            ForEach(Array(clauses.enumerated()), id: \.offset) { _, line in
                HStack(alignment: .top, spacing: 8) {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 13)).foregroundStyle(wa.accent).padding(.top, 2)
                    Text(verbatim: line).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(4).frame(maxWidth: .infinity, alignment: .leading)
                }
            }
        }
    }
}

/// On an offer or a service: who owns the work once it is paid, in the freelancer's words. Nothing when the feature is off.
struct RightsCard: View {
    @Environment(\.wa) private var wa
    let rights: FreelanceRightsSummaryDto?

    var body: some View {
        if let r = rights {
            FlCard(icon: "checkmark.shield.fill", title: L("rights_title")) {
                FlPill(text: r.modelLabel ?? "", color: rightsModelColor(r.model))
                if let s = r.summary, !s.isEmpty { Text(verbatim: s).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(4) }
                RightsClauses(clauses: r.clauses ?? [])
                if r.portfolio == true { FlNotice(text: L("rights_portfolio_wish"), icon: "info.circle.fill", color: wa.accent) }
            }
        }
    }
}

/// In the agreement: the model and how far the rights have passed, the lines, the standard text kept with it (opens in place), the note that
/// before a part is paid the client only looks, and the switch that lets the freelancer show the finished work in their portfolio.
/// `onPortfolio` sends the new answer and the screen takes the agreement that comes back.
struct AgreementRightsCard: View {
    @Environment(\.wa) private var wa
    let r: FreelanceAgreementRightsDto
    let busy: Bool
    let onPortfolio: (Bool) -> Void
    @State private var text = false

    var body: some View {
        let state = r.state ?? "pending"
        let stateColor: Color = state == "granted" ? flGreen : (state == "partial" ? Color(hex: 0x2563EB) : flAmber)
        let can = r.can ?? FreelanceRightsCanDto()
        let allowed = r.portfolio?.allowed == true
        FlCard(icon: "checkmark.shield.fill", title: L("rights_title")) {
            HStack(spacing: 8) {
                FlPill(text: r.modelLabel ?? "", color: rightsModelColor(r.model))
                FlPill(text: L(state == "granted" ? "rights_state_granted" : (state == "partial" ? "rights_state_partial" : "rights_state_pending")), color: stateColor)
            }
            if let s = r.summary, !s.isEmpty { Text(verbatim: s).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(4) }
            if let p = r.parts, (p.total ?? 0) > 0 {
                Text(verbatim: L("rights_parts_n", p.granted ?? 0, p.total ?? 0)).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
            }
            RightsClauses(clauses: r.clauses ?? [])
            // Before the first part is approved and paid the client only previews and reviews.
            if state != "granted" { FlNotice(text: L("rights_preview_only"), icon: "hourglass", color: flAmber) }
            if let body = r.terms?.body, !body.isEmpty {
                VStack(alignment: .leading, spacing: 6) {
                    Button { withAnimation(.easeInOut(duration: 0.2)) { text.toggle() } } label: {
                        Text(verbatim: L(text ? "rights_terms_hide" : "rights_terms_show")).font(.dorr(13, .bold)).foregroundStyle(wa.accent).padding(.vertical, 4)
                    }
                    .buttonStyle(.plain)
                    if text {
                        VStack(alignment: .leading, spacing: 6) {
                            if let t = r.terms?.title, !t.isEmpty { Text(verbatim: t).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk) }
                            Text(verbatim: body).font(.dorr(12.5)).foregroundStyle(wa.settingsInk).lineSpacing(5)
                            if let v = r.terms?.version, v > 0 { Text(verbatim: L("rights_terms_version", v)).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(12)
                        .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                }
            }
            // The client's leave to show the finished work in the freelancer's portfolio: off until they say so, and taken back at once.
            if can.portfolioToggle == true {
                HStack(spacing: 10) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: L("rights_portfolio_title")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: L("rights_portfolio_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Toggle("", isOn: Binding(get: { allowed }, set: { onPortfolio($0) })).labelsHidden().tint(wa.accent).disabled(busy)
                }
                if r.portfolio?.askedAt != nil && !allowed { FlNotice(text: L("rights_portfolio_asked"), icon: "hourglass", color: wa.accent) }
            }
        }
    }
}

/// With a delivered version: the freelancer's word that the work is theirs and the outside material they listed (name · source · licence).
struct DeliveryRightsLines: View {
    @Environment(\.wa) private var wa
    let rights: FreelanceDeliveryRightsDto?

    var body: some View {
        if let r = rights, let at = r.confirmedAt {
            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: 6) {
                    Image(systemName: "checkmark.shield.fill").font(.system(size: 12)).foregroundStyle(flGreen)
                    Text(verbatim: L("rights_delivery_confirmed", "\u{200E}" + String(at.prefix(10)))).font(.dorr(11.5, .semibold)).foregroundStyle(flGreen)
                }
                let materials = r.materials ?? []
                if !materials.isEmpty {
                    Text(verbatim: L("rights_materials_title")).font(.dorr(11, .bold)).foregroundStyle(wa.settingsMut)
                    ForEach(Array(materials.enumerated()), id: \.offset) { _, m in
                        HStack(alignment: .top, spacing: 6) {
                            Image(systemName: "paperclip").font(.system(size: 11)).foregroundStyle(wa.settingsMut).padding(.top, 2)
                            Text(verbatim: [m.name, m.source, m.license].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " · "))
                                .font(.dorr(12)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                        }
                    }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.top, 2)
        }
    }
}

/// "Report this work": a work shown on Dorr (a portfolio item or a ready-made service) that is not the freelancer's own. The server refuses a
/// repeat by the same person (409) and a work that is not shown (404); its words are shown as they are.
@MainActor
struct ReportWorkSheet: View {
    let subjectType: String
    let subject: String
    let onDismiss: () -> Void
    @State private var details = ""
    @State private var busy = false
    @State private var error: String?
    @State private var done: String?

    var body: some View {
        FlSheet(icon: "flag.fill", title: L("rights_report_title"), text: L("rights_report_body")) {
            if let done {
                FlNotice(text: done.isEmpty ? L("rights_report_title") : done, icon: "checkmark.circle.fill", color: flGreen)
                FlAction(text: L("common_cancel"), action: onDismiss)
            } else {
                DorrTextArea(value: Binding(get: { details }, set: { details = String($0.prefix(2000)) }), placeholder: L("rights_report_hint"), icon: "flag.fill", minHeight: 110)
                if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed) }
                FlAction(text: L("rights_report_send"), icon: "paperplane.fill", tone: .primary, enabled: details.trimmingCharacters(in: .whitespacesAndNewlines).count >= 10, loading: busy) {
                    busy = true
                    error = nil
                    let text = details.trimmingCharacters(in: .whitespacesAndNewlines)
                    Task {
                        do { done = try await FreelanceRightsApi.claim(subjectType: subjectType, subject: subject, details: text).message ?? "" } catch { self.error = flError(error) }
                        busy = false
                    }
                }
            }
        }
    }
}

/// A file that is only a preview for now: name, size and the lock line; nothing to tap, nothing to download.
struct PreviewOnlyFile: View {
    @Environment(\.wa) private var wa
    let f: FreelanceDeliveryFileDto

    var body: some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(verbatim: "📎 \(f.name ?? "")" + ((f.size ?? 0) > 0 ? " · \((f.size ?? 0) / 1024) KB" : "")).font(.dorr(12)).foregroundStyle(wa.settingsInk)
            Text(verbatim: "🔒 " + L("rights_preview_locked")).font(.dorr(11.5, .semibold)).foregroundStyle(flAmber)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(10)
        .background(RoundedRectangle(cornerRadius: 12, style: .continuous).fill(wa.settingsMut.opacity(0.08)))
    }
}

/// An in-app viewer for a preview image: pinch to zoom, drag to move; no share, no save, no Quick Look.
struct RightsImageViewer: View {
    let image: UIImage
    let onClose: () -> Void
    @State private var scale: CGFloat = 1
    @State private var base: CGFloat = 1
    @State private var offset: CGSize = .zero
    @State private var dragBase: CGSize = .zero

    var body: some View {
        ZStack(alignment: .topTrailing) {
            Color.black.ignoresSafeArea()
            Image(uiImage: image).resizable().scaledToFit()
                .scaleEffect(scale).offset(offset)
                .gesture(MagnificationGesture().onChanged { scale = min(5, max(1, base * $0)) }.onEnded { _ in base = scale; if scale == 1 { offset = .zero; dragBase = .zero } })
                .simultaneousGesture(DragGesture().onChanged { if scale > 1 { offset = CGSize(width: dragBase.width + $0.translation.width, height: dragBase.height + $0.translation.height) } }.onEnded { _ in dragBase = offset })
            Button(action: onClose) {
                Image(systemName: "xmark").font(.system(size: 16, weight: .bold)).foregroundStyle(.white)
                    .frame(width: 36, height: 36).background(Circle().fill(Color.black.opacity(0.5)))
            }
            .padding(16)
        }
        .screenshotShield(true)
    }
}
