// freelanceApp `ui/screens/AgreementRecords.kt`
import SwiftUI
import UIKit

/// My receipts of this project (FL-511): one per money that came to me from a part, newest first. Read when shown, when the agreement
/// changes (`refreshKey`) and when the connection comes back; a receipt opens with its lines and can be shared as text.
struct ProjectReceiptsCard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let refreshKey: String
    @State private var list: [ReceiptDto] = []
    @State private var open: ReceiptDto?

    var body: some View {
        VStack(spacing: 0) {
            if !list.isEmpty { card }
        }
        .task(id: "\(refreshKey)|\(network.reconnectTick)") {
            if let data = await AgreementsApi.receipts(project: projectUuid).value { list = data }
        }
        .dorrDialog(isPresented: agreementPresence($open)) {
            if let r = open { receiptDialog(r) }
        }
    }

    private var card: some View {
        Card {
            FieldLabel(text: L("rcp_title"))
            ForEach(Array(list.enumerated()), id: \.offset) { _, r in
                HStack {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: r.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: "\u{200E}\(r.number ?? "") · \(r.milestone?.title ?? "") · \(agreementTake(r.issuedAt, 10))").font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Text(verbatim: "\u{200E}\(formatMinor(r.totalMinor ?? 0, nil)) \(r.currency ?? "")").font(.dorr(14, .heavy)).foregroundStyle(wa.red)
                }
                .padding(.vertical, 6)
                .contentShape(Rectangle())
                .onTapGesture {
                    Task { open = await AgreementsApi.receipt(r.uuid ?? "").value ?? r }
                }
            }
        }
    }

    private func receiptDialog(_ r: ReceiptDto) -> some View {
        let cur = r.currency ?? ""
        return AgreementAlertCard(
            title: r.title ?? "", titleBold: true, confirmLabel: L("rcp_share"),
            onConfirm: { shareText(r.text ?? r.number ?? "") },
            dismissLabel: L("common_ok"), onDismiss: { open = nil }
        ) {
            VStack(alignment: .leading, spacing: 4) {
                ReceiptLine(label: L("rcp_number"), value: "\u{200E}\(r.number ?? "")")
                ReceiptLine(label: L("rcp_date"), value: "\u{200E}" + agreementStamp(r.issuedAt))
                ReceiptLine(label: L("rcp_part"), value: r.milestone?.title ?? "")
                if (r.amountMinor ?? 0) > 0 { ReceiptLine(label: L("ag_price"), value: "\u{200E}\(formatMinor(r.amountMinor ?? 0, nil)) \(cur)") }
                if (r.feeMinor ?? 0) > 0 { ReceiptLine(label: L("ag_commission"), value: "\u{200E}\(formatMinor(r.feeMinor ?? 0, nil)) \(cur)") }
                ReceiptLine(label: L("ag_net"), value: "\u{200E}\(formatMinor(r.totalMinor ?? 0, nil)) \(cur)")
                if let reason = r.reasonText { ReceiptLine(label: L("rcp_reason"), value: reason) }
            }
        }
    }
}

/// `Intent.ACTION_SEND` text/plain through the chooser: the share sheet over the top screen.
@MainActor
private func shareText(_ text: String) {
    guard let top = WalletTopController.find() else { return }
    let sheet = UIActivityViewController(activityItems: [text], applicationActivities: nil)
    sheet.popoverPresentationController?.sourceView = top.view
    top.present(sheet, animated: true)
}

private struct ReceiptLine: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String

    var body: some View {
        HStack(alignment: .top) {
            Text(verbatim: label).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
            Text(verbatim: value).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
        }
    }
}

/// The project's record of approvals (FL-406): every step both sides agreed to, oldest first; folded until opened.
struct ApprovalTrailCard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let refreshKey: String
    @State private var list: [TrailStepDto] = []
    @State private var shown = false

    var body: some View {
        VStack(spacing: 0) {
            if !list.isEmpty { card }
        }
        .task(id: "\(refreshKey)|\(network.reconnectTick)") {
            if let data = await AgreementsApi.trail(projectUuid).value { list = data }
        }
    }

    private var card: some View {
        Card {
            HStack(spacing: 0) {
                FieldLabel(text: L("trail_title", list.count))
                Text(L(shown ? "trail_hide" : "trail_show")).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.leading, 8)
            }
            .contentShape(Rectangle())
            .onTapGesture { shown.toggle() }
            if shown {
                ForEach(Array(list.enumerated()), id: \.offset) { _, s in
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: s.label ?? "").font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                        Text(verbatim: [L(actorKey(s)), "\u{200E}" + agreementStamp(s.at)].joined(separator: " · ")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    }
                    .padding(.vertical, 4)
                }
            }
        }
    }

    private func actorKey(_ s: TrailStepDto) -> String {
        if s.mine == true { return "trail_by_you" }
        switch s.actor {
        case "client": return "trail_by_client"
        case "admin": return "trail_by_admin"
        case "system": return "trail_by_system"
        default: return "trail_by_freelancer"
        }
    }
}
