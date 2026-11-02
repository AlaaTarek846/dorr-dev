// freelanceApp `ui/screens/DisputeScreen.kt`
import SwiftUI

private let disputeReasons = ["not_as_agreed", "not_delivered", "late", "quality", "out_of_scope", "no_response", "other"]

private func reasonLabel(_ reason: String) -> String {
    switch reason {
    case "not_as_agreed": return L("dp_r_not_as_agreed")
    case "not_delivered": return L("dp_r_not_delivered")
    case "late": return L("dp_r_late")
    case "quality": return L("dp_r_quality")
    case "out_of_scope": return L("dp_r_out_of_scope")
    case "no_response": return L("dp_r_no_response")
    default: return L("dp_r_other")
    }
}

/// A dispute on a part of my work (FL-704): opening one (`disputeUuid` nil), or following it — what each side added, DORR's decision and
/// when it takes effect, one appeal. Read on open, when a notice arrives and when the connection comes back.
struct DisputeScreen: View {
    let projectUuid: String
    let milestoneUuid: String?
    let disputeUuid: String?
    let onBack: () -> Void
    let onOpened: (String) -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var dispute: DisputeDto?
    @State private var error: String?
    @State private var busy = false
    @State private var reason: String?
    @State private var text = ""
    @State private var appealing = false

    private let red = Color(hex: 0xDC2626)

    var body: some View {
        Page(title: L("dp_title"), onBack: onBack) {
            if disputeUuid == nil {
                openForm
            } else if let d = dispute {
                follow(d)
            } else if error == nil {
                SkeletonDetail()
            }
        }
        .messageToast(error)
        .task(id: "\(projectUuid)|\(disputeUuid ?? "")|\(network.reconnectTick)") { await load() }
        .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
    }

    @ViewBuilder private var openForm: some View {
        FormIntro(icon: "hammer.fill", title: L("dp_open"), text: L("dp_open_note"))
        FieldLabel(text: L("dp_reason_label"))
        FlowLayout(spacing: 8) {
            ForEach(disputeReasons, id: \.self) { r in
                AgreementPill(text: reasonLabel(r), on: reason == r, vertical: 8) { reason = r }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        DorrTextField(value: agreementLimit($text, 3000), label: L("dp_details_hint"), icon: "text.alignleft", singleLine: false, minLines: 5)
        SettingsPrimaryButton(text: L("dp_open"), enabled: reason != nil && text.agreementTrimmed.count >= 20 && milestoneUuid != nil, loading: busy) {
            guard let milestoneUuid, let reason else { return }
            call({ await AgreementsApi.openDispute(projectUuid, milestoneUuid, DisputeOpenRequest(reason: reason, details: text.agreementTrimmed)) }) {
                onOpened($0.uuid ?? "")
            }
        }
    }

    @ViewBuilder private func follow(_ d: DisputeDto) -> some View {
        let statusKey: String = {
            switch d.status {
            case "decided": return "dp_s_decided"
            case "appealed": return "dp_s_appealed"
            case "resolved": return "dp_s_resolved"
            default: return "dp_s_open"
            }
        }()
        Card {
            Text(L(statusKey)).font(.dorr(15, .heavy)).foregroundStyle(d.status == "resolved" ? Color(hex: 0x16A34A) : Color(hex: 0xD97706))
            if let m = d.milestone {
                Text(verbatim: "\(m.title ?? "")  ·  \u{200E}\(formatMinor(m.amountMinor ?? 0, nil)) \(d.currency ?? "")").font(.dorr(13)).foregroundStyle(wa.settingsInk)
            }
            Text(verbatim: L(d.mine == true ? "dp_by_me" : "dp_by_them") + "  ·  " + (d.reasonLabel ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            Text(verbatim: d.details ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk)
            Text(L("dp_frozen")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
        }
        if let dec = d.decision {
            Card {
                FieldLabel(text: L("dp_decision"))
                Text(verbatim: dec.type == "refund" ? L("dp_d_refund") : dec.type == "release" ? L("dp_d_release") : L("dp_d_split", (dec.freelancerBp ?? 0) / 100))
                    .font(.dorr(14, .bold)).foregroundStyle(wa.red)
                if let note = dec.note { Text(verbatim: note).font(.dorr(13)).foregroundStyle(wa.settingsInk) }
                if d.status == "decided", let at = dec.executesAt {
                    Text(verbatim: L("dp_runs_at", "\u{200E}" + String(at.prefix(10)))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
            }
        }
        Card {
            FieldLabel(text: L("dp_evidence"))
            ForEach(Array((d.evidence ?? []).enumerated()), id: \.offset) { _, e in
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: L(e.mine == true ? "dp_you" : "dp_them") + "  ·  \u{200E}" + agreementTake(e.at, 10)).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: e.body ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk)
                }
                .padding(10)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(e.mine == true ? wa.red.opacity(0.06) : Color(hex: 0x6B7280).opacity(0x0F / 255.0),
                            in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            if d.can?.evidence == true {
                DorrTextField(value: agreementLimit($text, 3000), label: L("dp_add_hint"), icon: "text.alignleft", singleLine: false, minLines: 3)
                let ready = !busy && text.agreementTrimmed.count >= 5
                Text(L("dp_add")).font(.dorr(14, .bold)).foregroundStyle(wa.red)
                    .padding(.vertical, 6)
                    .contentShape(Rectangle())
                    .onTapGesture {
                        guard ready else { return }
                        call({ await AgreementsApi.disputeEvidence(projectUuid, d.uuid ?? "", TextRequest(body: text.agreementTrimmed)) }) {
                            dispute = $0
                            text = ""
                        }
                    }
            }
        }
        if d.can?.appeal == true {
            if !appealing {
                Text(L("dp_appeal")).font(.dorr(14, .bold)).foregroundStyle(red)
                    .padding(.vertical, 8)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .contentShape(Rectangle())
                    .onTapGesture { appealing = true; text = "" }
            } else {
                Card {
                    Text(L("dp_appeal_note")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    DorrTextField(value: agreementLimit($text, 2000), label: L("dp_appeal_hint"), icon: "text.alignleft", singleLine: false, minLines: 3)
                    SettingsPrimaryButton(text: L("dp_appeal_send"), enabled: text.agreementTrimmed.count >= 20, loading: busy) {
                        call({ await AgreementsApi.appealDispute(projectUuid, d.uuid ?? "", TextRequest(reason: text.agreementTrimmed)) }) {
                            dispute = $0
                            appealing = false
                            text = ""
                        }
                    }
                }
            }
        }
    }

    private func load() async {
        guard let disputeUuid else { return }
        let result = await AgreementsApi.dispute(projectUuid, disputeUuid)
        if let data = result.value { dispute = data } else if dispute == nil { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
    }

    private func call(_ block: @escaping () async -> ApiResult<DisputeDto>, after: ((DisputeDto) -> Void)? = nil) {
        busy = true
        error = nil
        Task {
            let result = await block()
            if let data = result.value {
                if let after { after(data) } else { dispute = data }
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
            busy = false
        }
    }
}
