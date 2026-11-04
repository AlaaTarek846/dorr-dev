import SwiftUI

// freelanceApp `ui/screens/RightsKit.kt`.
//
// FL-L13, ownership and licences — the pieces the offer form, the service form, the delivery sheet, the agreement and the portfolio share.
// Where the feature is off in my country the server sends `rights: null` and `GET rights` says `enabled: false`: nothing here shows.

/// The terms as typed on the offer and service forms (transfer · license · retained; a licence may be limited, a kept ownership never is).
/// (Android keeps it across a rotation with `rightsFormSaver`; on iOS a `@State` holds it.)
struct RightsForm: Equatable, Codable {
    var model = "transfer"
    var commercial = true
    var exclusive = false
    /// Months; empty = no time limit.
    var term = ""
    var territory = "worldwide"
    /// My wish to show the finished work in my portfolio (the client decides).
    var portfolio = false

    func toInput() -> RightsInputDto {
        RightsInputDto(model: model, commercial: commercial, exclusive: exclusive, termMonths: Int(term), territory: territory, portfolio: portfolio)
    }

    /// The terms as the fields of a multipart request (`rights[model]`…), the way the offer is sent.
    func toFields() -> [String: String] {
        var fields = [
            "rights[model]": model,
            "rights[commercial]": commercial ? "1" : "0",
            "rights[exclusive]": exclusive ? "1" : "0",
            "rights[territory]": territory,
            "rights[portfolio]": portfolio ? "1" : "0",
        ]
        if let months = Int(term) { fields["rights[term_months]"] = "\(months)" }
        return fields
    }
}

extension RightsInputDto {
    func toForm() -> RightsForm {
        RightsForm(model: model ?? "transfer", commercial: commercial ?? true, exclusive: exclusive ?? true,
                   term: termMonths.map { "\($0)" } ?? "", territory: territory ?? "worldwide", portfolio: portfolio ?? false)
    }
}

extension RightsSummaryDto {
    /// The terms an offer or a service already carries, back in the form (an edit).
    func toForm() -> RightsForm {
        RightsForm(model: model ?? "transfer", commercial: details?.commercial ?? true, exclusive: details?.exclusive ?? false,
                   term: details?.termMonths.map { "\($0)" } ?? "", territory: details?.territory ?? "worldwide", portfolio: portfolio ?? false)
    }
}

/// Android's `rememberRightsOptions()`: what the forms need, read once (and again when the connection comes back); `value` is nil while it
/// loads and where the feature is off. Hold it as `@StateObject private var rightsOptions = RightsOptionsState()` and attach
/// `.rememberRightsOptions(rightsOptions)` to the screen.
@MainActor
final class RightsOptionsState: ObservableObject {
    @Published private var options: RightsOptionsDto?

    var value: RightsOptionsDto? { options?.enabled == true ? options : nil }

    func load() async {
        if let o = await RightsApi.options().value { options = o }
    }
}

private struct RightsOptionsLoader: ViewModifier {
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject var state: RightsOptionsState

    func body(content: Content) -> some View {
        content.task(id: network.reconnectTick) { await state.load() }
    }
}

extension View {
    func rememberRightsOptions(_ state: RightsOptionsState) -> some View { modifier(RightsOptionsLoader(state: state)) }
}

private let RIGHTS_GREEN = Color(hex: 0x16A34A)
private let RIGHTS_AMBER = Color(hex: 0xD97706)

/// The three ways to hand over the work, then the limits that go with the one picked, my portfolio wish, and the standard text.
struct RightsEditor: View {
    @Environment(\.wa) private var wa
    let form: RightsForm
    let options: RightsOptionsDto
    var readOnly = false
    let onChange: (RightsForm) -> Void

    @State private var showText = false

    init(form: RightsForm, options: RightsOptionsDto, readOnly: Bool = false, onChange: @escaping (RightsForm) -> Void) {
        self.form = form
        self.options = options
        self.readOnly = readOnly
        self.onChange = onChange
    }

    private func change(_ edit: (inout RightsForm) -> Void) {
        var f = form
        edit(&f)
        onChange(f)
    }

    var body: some View {
        let models = options.models ?? []
        let maxTerm = options.limits?.maxTermMonths ?? 120
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                Image(systemName: "hammer.fill").font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 38, height: 38)
                    .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                VStack(alignment: .leading, spacing: 2) {
                    Text(L("rights_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(L("rights_editor_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            ForEach(Array(models.enumerated()), id: \.offset) { _, m in
                let on = form.model == m.model
                Button { change { $0.model = m.model ?? "transfer" } } label: {
                    HStack(spacing: 10) {
                        Image(systemName: on ? "largecircle.fill.circle" : "circle").font(.system(size: 19, weight: .regular))
                            .foregroundStyle(on ? wa.red : wa.settingsMut).frame(width: 22, height: 22)
                        VStack(alignment: .leading, spacing: 2) {
                            HStack(spacing: 6) {
                                Text(verbatim: m.label ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                                if m.model == (options.default?.model ?? "transfer") {
                                    Text(L("rights_default")).font(.dorr(10, .bold)).foregroundStyle(wa.red)
                                        .padding(.horizontal, 7)
                                        .padding(.vertical, 2)
                                        .background(wa.red.opacity(0.12), in: Capsule())
                                }
                            }
                            Text(verbatim: m.summary ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                                .multilineTextAlignment(.leading)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .padding(12)
                    .background(wa.red.opacity(on ? 0.08 : 0), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(on ? wa.red : wa.settingsMut.opacity(0.25), lineWidth: 1))
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .disabled(readOnly)
            }

            // A transfer is the whole thing; a licence or a kept ownership says what the client may do.
            if form.model != "transfer" {
                RightsSwitch(label: L("rights_commercial"), on: form.commercial, readOnly: readOnly) { v in change { $0.commercial = v } }
                if form.model == "license" {
                    RightsSwitch(label: L("rights_exclusive"), on: form.exclusive, readOnly: readOnly) { v in change { $0.exclusive = v } }
                    DorrTextField(
                        value: Binding(get: { form.term }, set: { v in
                            let d = String(v.filter { $0.isASCII && $0.isNumber }.prefix(3))
                            change { $0.term = (Int(d) ?? 0) > maxTerm ? "\(maxTerm)" : d }
                        }),
                        label: L("rights_term"), placeholder: L("rights_term_hint"), icon: "clock", keyboard: .numberPad
                    )
                }
                FieldLabel(text: L("rights_territory"))
                ChipRow(options: [(value: "worldwide", label: L("rights_territory_worldwide")), (value: "country", label: L("rights_territory_country"))],
                        selected: [form.territory]) { v in if !readOnly { change { $0.territory = v } } }
            }

            RightsSwitch(label: L("rights_portfolio_wish"), on: form.portfolio, readOnly: readOnly) { v in change { $0.portfolio = v } }

            if let terms = models.first(where: { $0.model == form.model })?.terms, !(terms.body ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                RightsTermsToggle(showText: $showText)
                if showText { TermsText(title: terms.title ?? "", body: terms.body ?? "", version: terms.version ?? 0) }
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(18)
    }
}

/// «Read the terms» / «Hide the terms».
private struct RightsTermsToggle: View {
    @Environment(\.wa) private var wa
    @Binding var showText: Bool

    var body: some View {
        Button { showText.toggle() } label: {
            Text(L(showText ? "rights_hide_terms" : "rights_read_terms")).font(.dorr(13, .bold)).foregroundStyle(wa.red).padding(.vertical, 4)
        }
        .buttonStyle(.plain)
    }
}

private struct RightsSwitch: View {
    @Environment(\.wa) private var wa
    let label: String
    let on: Bool
    let readOnly: Bool
    let onChange: (Bool) -> Void

    var body: some View {
        HStack(spacing: 10) {
            RedToggle(on: on) { if !readOnly { onChange(!on) } }
            Text(verbatim: label).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
        }
    }
}

private struct TermsText: View {
    @Environment(\.wa) private var wa
    let title: String
    let body_: String
    let version: Int

    init(title: String, body: String, version: Int) {
        self.title = title
        self.body_ = body
        self.version = version
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(verbatim: title).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk)
            Text(verbatim: body_).font(.dorr(12.5)).foregroundStyle(wa.settingsInk).lineSpacing(5)
            if version > 0 { Text(L("rights_terms_version", version)).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.settingsMut.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// The clauses as lines with a tick.
private struct Clauses: View {
    @Environment(\.wa) private var wa
    let lines: [String]

    var body: some View {
        ForEach(Array(lines.enumerated()), id: \.offset) { _, line in
            HStack(alignment: .top, spacing: 8) {
                Image(systemName: "checkmark.circle.fill").font(.system(size: 13, weight: .semibold)).foregroundStyle(RIGHTS_GREEN)
                    .frame(width: 16, height: 16).padding(.top, 2)
                Text(verbatim: line).font(.dorr(12.5)).foregroundStyle(wa.settingsInk).lineSpacing(4).frame(maxWidth: .infinity, alignment: .leading)
            }
        }
    }
}

/// The terms of one of my offers, as the client sees them.
struct RightsSummaryCard: View {
    @Environment(\.wa) private var wa
    let s: RightsSummaryDto

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 8) {
                Image(systemName: "hammer.fill").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red).frame(width: 18, height: 18)
                Text(L("rights_title")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: s.modelLabel ?? "").font(.dorr(11, .bold)).foregroundStyle(wa.red)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 3)
                    .background(wa.red.opacity(0.12), in: Capsule())
            }
            Text(verbatim: s.summary ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            Clauses(lines: s.clauses ?? [])
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(18)
    }
}

/// The agreement's terms, where the passing of the rights stands, and the client's leave to show the finished work.
struct AgreementRightsCard: View {
    @Environment(\.wa) private var wa
    let r: AgreementRightsDto
    let projectUuid: String
    let onAgreement: (AgreementDto) -> Void

    @State private var showText = false
    @State private var busy = false
    @State private var note: String?
    @State private var error: String?

    private func ask() {
        busy = true
        error = nil
        note = nil
        Task {
            let result = await RightsApi.askPortfolio(projectUuid)
            busy = false
            if let a = result.value {
                onAgreement(a)
                note = L("rights_portfolio_ask_done")
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    var body: some View {
        let state = r.state ?? "pending"
        let tint: Color = state == "granted" ? RIGHTS_GREEN : state == "partial" ? wa.red : RIGHTS_AMBER
        let allowed = r.portfolio?.allowed ?? false
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 8) {
                Image(systemName: "hammer.fill").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red).frame(width: 18, height: 18)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("rights_title")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: r.modelLabel ?? "").font(.dorr(12, .bold)).foregroundStyle(wa.red)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Text(L(state == "granted" ? "rights_state_granted" : state == "partial" ? "rights_state_partial" : "rights_state_pending"))
                    .font(.dorr(11, .bold)).foregroundStyle(tint)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 3)
                    .background(tint.opacity(0.12), in: Capsule())
            }
            Text(verbatim: r.summary ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            Text(L("rights_parts", r.parts?.granted ?? 0, r.parts?.total ?? 0)).font(.dorr(12, .bold)).foregroundStyle(tint)
            Clauses(lines: r.clauses ?? [])
            // Before the first part is paid the client only looks.
            if state != "granted" {
                HStack(spacing: 8) {
                    Image(systemName: "hourglass").font(.system(size: 13, weight: .semibold)).foregroundStyle(RIGHTS_AMBER).frame(width: 16, height: 16)
                    Text(L("rights_preview_only")).font(.dorr(12)).foregroundStyle(wa.settingsInk).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(10)
                .background(RIGHTS_AMBER.opacity(0.08), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }

            // The portfolio: mine to show only with the client's leave.
            VStack(alignment: .leading, spacing: 6) {
                Text(L(allowed ? "rights_portfolio_allowed" : "rights_portfolio_not_allowed"))
                    .font(.dorr(12.5, .semibold)).foregroundStyle(allowed ? RIGHTS_GREEN : wa.settingsMut).lineSpacing(4)
                if !allowed, let asked = r.portfolio?.askedAt {
                    Text(L("rights_portfolio_asked", "\u{200E}" + String(asked.prefix(10)))).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                }
                if r.can?.portfolioRequest == true {
                    ActionButton(L("rights_portfolio_ask"), "hammer.fill", .soft, enabled: !busy, loading: busy) { ask() }
                }
                if let note { Text(verbatim: note).font(.dorr(12, .bold)).foregroundStyle(RIGHTS_GREEN) }
                if let error { Text(verbatim: error).font(.dorr(12, .bold)).foregroundStyle(Color(hex: 0xDC2626)) }
            }

            if let terms = r.terms, !(terms.body ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                RightsTermsToggle(showText: $showText)
                if showText { TermsText(title: terms.title ?? "", body: terms.body ?? "", version: terms.version ?? 0) }
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(18)
    }
}

/// One delivery's rights line: my word and the outside material I listed.
struct DeliveryRightsView: View {
    @Environment(\.wa) private var wa
    let d: DeliveryRightsDto?

    var body: some View {
        if let d, d.confirmedAt != nil {
            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 6) {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 12, weight: .semibold)).foregroundStyle(RIGHTS_GREEN).frame(width: 14, height: 14)
                    Text(L("rights_delivery_confirmed")).font(.dorr(11.5, .semibold)).foregroundStyle(RIGHTS_GREEN)
                }
                let materials = d.materials ?? []
                if !materials.isEmpty {
                    Text(L("rights_materials_used")).font(.dorr(11.5, .bold)).foregroundStyle(wa.settingsMut)
                    ForEach(Array(materials.enumerated()), id: \.offset) { _, m in
                        Text(verbatim: "•  " + [m.name ?? "", m.source, m.license].compactMap { $0 }.joined(separator: " · "))
                            .font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                    }
                }
            }
        }
    }
}
