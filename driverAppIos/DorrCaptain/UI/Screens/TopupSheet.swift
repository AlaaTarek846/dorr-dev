import SwiftUI
import UIKit

// driverApp `ui/TopupSheet.kt`.

/// Top up the wallet (the captain whose cash rides left a debt, or who wants a balance for commissions).
/// Pick how, the amount, see the fee and the bonus, enter the wallet PIN → the gateway's page opens; the
/// result arrives as a real-time event (`wallet.topup.paid / failed`) — nothing asks the server on a timer.
/// Coming back from the gateway's page checks the payment once.
/// The sheet's content (show it in a `.sheet`); swiping it away is `onDismissRequest` (`onDone` once paid, else `onClose`).
struct TopupSheet: View {
    @Environment(\.pal) private var pal
    let w: WalletDto
    let onClose: () -> Void
    let onDone: () -> Void

    @State private var methods: [DriverApi.PaymentMethodDto]?
    @State private var methodId: Int?
    @State private var amountText = ""
    @State private var quote: DriverApi.TopupQuoteDto?
    @State private var pin = ""
    @State private var otp = ""
    @State private var payment: TopupDto?
    @State private var result: String?   // "paid" | "failed"
    @State private var busy = false
    @State private var error: String?
    @State private var idempotencyKey = UUID().uuidString.lowercased()
    /// A callback already answered (so the sheet going away doesn't answer again).
    @State private var finished = false

    init(_ w: WalletDto, onClose: @escaping () -> Void, onDone: @escaping () -> Void) {
        self.w = w
        self.onClose = onClose
        self.onDone = onDone
    }

    private var minor: Int { Double(amountText.replacingOccurrences(of: ",", with: ".")).map { Int(($0 * 100).rounded()) } ?? 0 }
    private var owed: Int { (w.totalMinor ?? 0) < 0 ? -(w.totalMinor ?? 0) : 0 }

    private func finish(_ f: () -> Void) {
        finished = true
        f()
    }

    var body: some View {
        let state = result ?? (payment != nil ? "waiting" : "form")
        ScrollView {
            VStack(alignment: .leading, spacing: 10) {
                Text(L("wl_topup")).font(.dorr(19, .heavy)).foregroundStyle(pal.ink)
                ZStack {
                    VStack(alignment: .leading, spacing: 10) { content(state) }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .id(state)
                        .transition(.asymmetric(insertion: .scale.combined(with: .opacity), removal: .opacity))
                }
                .animation(.spring(response: 0.4, dampingFraction: 0.5), value: state)
            }
            .padding(.horizontal, 20).padding(.top, 24).padding(.bottom, 28)
        }
        .background(pal.card.ignoresSafeArea())
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
        .task {
            methods = (await DriverApi.paymentMethods().value ?? []).filter { $0.comingSoon != true }
            methodId = methods?.first?.id
            if owed > 0 { amountText = String(format: "%.2f", Double(owed) / 100.0) }
        }
        // The fee and the bonus for what they typed (one quote per change, after a short pause — not a loop).
        .task(id: "\(methodId ?? -1)|\(minor)") {
            quote = nil
            guard let id = methodId, minor > 0 else { return }
            try? await Task.sleep(nanoseconds: 400_000_000)
            if Task.isCancelled { return }
            let q = await DriverApi.topupQuote(body: ["payment_method_id": id, "amount_minor": minor]).value
            if !Task.isCancelled { quote = q }
        }
        // The gateway tells the server, the server tells us: paid or failed, as it happens.
        .onReceive(DriverLive.shared.events) { e in
            guard payment != nil else { return }
            switch e.name {
            case "wallet.topup.paid": result = "paid"
            case "wallet.topup.failed": result = "failed"
            default: break
            }
        }
        // Back from the gateway's page: look once.
        .onReceive(NotificationCenter.default.publisher(for: UIApplication.didBecomeActiveNotification)) { _ in
            guard let uuid = payment?.uuid, result == nil else { return }
            Task {
                switch await DriverApi.topupStatus(uuid: uuid).value?.status {
                case "paid", "completed", "success", "succeeded": result = "paid"
                case "failed", "expired", "cancelled", "canceled": result = "failed"
                default: break
                }
            }
        }
        .onDisappear { if !finished { result == "paid" ? onDone() : onClose() } }
    }

    @ViewBuilder private func content(_ state: String) -> some View {
        switch state {
        case "paid":
            VStack(spacing: 0) {
                DorrIcon("checkmark.circle.fill", size: 72, tint: pal.success)
                Text(L("wl_topup_paid")).font(.dorr(20, .heavy)).foregroundStyle(pal.ink).multilineTextAlignment(.center)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 16)
            BigButton(L("sc_done"), color: pal.navy) { finish(onDone) }
        case "failed":
            VStack(spacing: 0) {
                DorrIcon("exclamationmark.circle", size: 72, tint: pal.danger)
                Text(L("wl_topup_failed")).font(.dorr(18, .bold)).foregroundStyle(pal.ink).multilineTextAlignment(.center)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 16)
            BigButton(L("wl_try_again"), color: pal.orange) { payment = nil; result = nil; otp = "" }
        case "waiting":
            VStack(spacing: 0) {
                CircularProgress(color: pal.orange, size: 40, lineWidth: 4)
                VSpace(10)
                Text(L("wl_topup_waiting")).font(.dorr(16, .semibold)).foregroundStyle(pal.ink).multilineTextAlignment(.center)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 12)
            if let url = payment?.redirectUrl {
                BigButton(L("wl_open_payment"), color: pal.navy) { openUrl(url) }
            }
            if payment?.requiresOtp == true {
                DorrField(value: otp, onValueChange: { otp = String($0.filter { $0.isDigit }.prefix(8)) }, label: L("wl_otp"),
                          singleLine: true, keyboardOptions: KeyboardOptions(keyboardType: .numberPad))
                if let error { Text(error).font(.dorr(13)).foregroundStyle(pal.danger) }
                BigButton(L("wl_otp_confirm"), color: pal.navy, enabled: otp.count >= 4, busy: busy) {
                    busy = true
                    Task {
                        switch await DriverApi.confirmTopup(pin: pin, uuid: payment?.uuid ?? "", body: ["otp": otp]) {
                        case .ok(let r, _):
                            if r.status != "pending" { result = ["failed", "expired"].contains(r.status ?? "") ? "failed" : "paid" }
                        case .failed(let e): error = e.displayMessage
                        case .offline: error = nil
                        }
                        busy = false
                    }
                }
            }
        default:
            form
        }
    }

    @ViewBuilder private var form: some View {
        if let list = methods {
            if list.isEmpty {
                Text(L("wl_no_gateways")).font(.dorr(16)).foregroundStyle(pal.ink.opacity(0.6))
            } else {
                if owed > 0 {
                    Text(L("wl_you_owe", localDecimal(Double(owed) / 100.0, places: 2) + " " + (w.currencySymbol ?? "")))
                        .font(.dorr(13, .semibold)).foregroundStyle(pal.danger)
                }
                Text(L("wl_pay_with")).font(.dorr(12)).foregroundStyle(pal.ink)
                ForEach(Array(list.enumerated()), id: \.offset) { _, m in
                    let on = m.id == methodId
                    let shape = RoundedRectangle(cornerRadius: 14)
                    HStack(spacing: 0) {
                        if let logo = m.logoUrl, let url = URL(string: logo) {
                            AsyncImage(url: url) { image in image.resizable().scaledToFit() } placeholder: { Color.clear }
                                .frame(width: 30, height: 30)
                        } else {
                            DorrIcon("creditcard", size: 24, tint: pal.navy)
                        }
                        HSpace(10)
                        Text(m.name ?? m.gateway ?? "").font(.dorr(16, .semibold)).foregroundStyle(pal.ink)
                    }
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .overlay(shape.strokeBorder(on ? pal.orange : pal.ink.opacity(0.12), lineWidth: on ? 2 : 1))
                    .contentShape(shape)
                    .onTapGesture { methodId = m.id }
                }
                DorrField(value: amountText, onValueChange: { amountText = String($0.filter { $0.isDigit || $0 == "." || $0 == "," }.prefix(10)) },
                          label: L("wl_amount", w.currencySymbol ?? ""), singleLine: true,
                          keyboardOptions: KeyboardOptions(keyboardType: .decimalPad))
                TopupFlowRow(spacing: 8) {
                    ForEach([50, 100, 200, 500], id: \.self) { v in
                        Text("\(v)").font(.dorr(16)).foregroundStyle(pal.ink)
                            .padding(.horizontal, 16).padding(.vertical, 8)
                            .background(Capsule().fill(pal.navy.opacity(0.07)))
                            .contentShape(Capsule())
                            .onTapGesture { amountText = String(v) }
                    }
                }
                if let q = quote {
                    VStack(alignment: .leading, spacing: 0) {
                        QuoteLine(label: L("wl_q_paid"), minor: q.paidAmountMinor ?? 0, w: w)
                        if (q.feeMinor ?? 0) > 0 { QuoteLine(label: L("wl_q_fee"), minor: q.feeMinor ?? 0, w: w) }
                        if (q.bonusMinor ?? 0) > 0 { QuoteLine(label: L("wl_q_bonus"), minor: q.bonusMinor ?? 0, w: w, color: pal.success) }
                        QuoteLine(label: L("wl_q_total"), minor: q.totalCreditedMinor ?? 0, w: w, bold: true)
                    }
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(RoundedRectangle(cornerRadius: 14).fill(pal.navy.opacity(0.05)))
                }
                DorrField(value: pin, onValueChange: { pin = String($0.filter { $0.isDigit }.prefix(4)) }, label: L("wl_pin"), singleLine: true,
                          keyboardOptions: KeyboardOptions(keyboardType: .numberPad), secure: true)
                if let error { Text(error).font(.dorr(13)).foregroundStyle(pal.danger) }
                BigButton(L("wl_pay_now"), color: pal.success, enabled: methodId != nil && minor > 0 && pin.count == 4, busy: busy) {
                    busy = true
                    error = nil
                    Task {
                        let body: JsonBody = ["payment_method_id": methodId, "amount_minor": minor]
                        switch await DriverApi.topup(pin: pin, key: idempotencyKey, body: body) {
                        case .ok(let r, _):
                            payment = r
                            if let url = r.redirectUrl { openUrl(url) }
                        case .failed(let e): error = e.displayMessage
                        case .offline: error = nil
                        }
                        busy = false
                    }
                }
            }
        } else {
            CircularProgress(color: pal.orange, size: 40, lineWidth: 4)
                .frame(maxWidth: .infinity)
                .padding(24)
        }
    }

    /// `Intent.ACTION_VIEW` — the gateway's page in the browser.
    private func openUrl(_ url: String) {
        guard let u = URL(string: url) else { return }
        UIApplication.shared.open(u)
    }
}

private struct QuoteLine: View {
    @Environment(\.pal) private var pal
    let label: String
    let minor: Int
    let w: WalletDto
    var color: Color? = nil
    var bold = false

    var body: some View {
        HStack(spacing: 0) {
            Text(label).font(.dorr(13)).foregroundStyle(pal.ink.opacity(0.65)).frame(maxWidth: .infinity, alignment: .leading)
            Text("\(localDecimal(Double(minor) / 100.0, places: 2)) \(w.currencySymbol ?? "")".trimmingCharacters(in: .whitespaces))
                .font(.dorr(13, bold ? .heavy : .semibold)).foregroundStyle(color ?? pal.ink)
        }
        .padding(.vertical, 2)
    }
}

/// Compose's `FlowRow` (no space between lines).
private struct TopupFlowRow: Layout {
    var spacing: CGFloat

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let rows = arrange(subviews, width: proposal.width ?? .infinity)
        let width = rows.map { $0.width }.max() ?? 0
        return CGSize(width: proposal.width ?? width, height: rows.map { $0.height }.reduce(0, +))
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var y = bounds.minY
        for row in arrange(subviews, width: bounds.width) {
            var x = bounds.minX
            for i in row.items {
                let size = subviews[i].sizeThatFits(.unspecified)
                subviews[i].place(at: CGPoint(x: x, y: y), proposal: ProposedViewSize(size))
                x += size.width + spacing
            }
            y += row.height
        }
    }

    private func arrange(_ subviews: Subviews, width: CGFloat) -> [(items: [Int], width: CGFloat, height: CGFloat)] {
        var rows: [(items: [Int], width: CGFloat, height: CGFloat)] = []
        var current: (items: [Int], width: CGFloat, height: CGFloat) = ([], 0, 0)
        for i in subviews.indices {
            let size = subviews[i].sizeThatFits(.unspecified)
            let next = current.items.isEmpty ? size.width : current.width + spacing + size.width
            if !current.items.isEmpty && next > width {
                rows.append(current)
                current = ([i], size.width, size.height)
            } else {
                current = (current.items + [i], next, Swift.max(current.height, size.height))
            }
        }
        if !current.items.isEmpty { rows.append(current) }
        return rows
    }
}

private extension Character {
    /// Kotlin `Char.isDigit()`: any Unicode decimal digit (Arabic-Indic too).
    var isDigit: Bool { unicodeScalars.count == 1 && unicodeScalars.allSatisfy { CharacterSet.decimalDigits.contains($0) } }
}
