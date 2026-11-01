import SwiftUI

// androidApp `chat/ChatMoneyUi.kt` (ChatPaySheet, PaidCelebration, SendMoneySheet, SplitBillSheet) and ChatToolsUi's
// `GiftPicker` — paying a request or my share of a bill with the wallet PIN, sending money straight from a one-to-one
// chat (optionally as a gift card), and splitting a bill in a group. A wrong PIN shakes; a lock shows its countdown;
// anything else (balance, limits) closes with the reason.

private let moneyGreenColors = [Color(hex: 0x10B981), Color(hex: 0x047857)]

/// A PIN problem stays on the pad (shake / countdown); anything else closes the sheet with the reason.
@MainActor
private func payFailure(_ error: Error, host: ChatHost, close: () -> Void) -> PadResult {
    let failure = error.apiFailure
    if failure.errorCode?.hasPrefix("wallet_pin") == true { return padFailure(error, networkError: L("ch_error_network")) }
    if let m = failure.message { host.showToast(m, error: true) }
    close()
    return .ok
}

/// «Pay 25.00 SAR to Alice»: the wallet PIN pad, then a real transfer.
struct ChatPaySheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let message: MessageDto
    let onDismiss: () -> Void
    let onPaid: (MessageDto) -> Void
    @State private var done = false

    var body: some View {
        if let p = message.payment {
            ChSheetCard {
                if done {
                    PaidCelebration(amount: p.money(p.due), to: message.sender?.name ?? "", title: L("ch_money_paid_title")).transition(.scale(scale: 0.9).combined(with: .opacity))
                } else {
                    WaPinPad(title: L("ch_money_pay_title", p.money(p.due)), sub: L("ch_money_pay_to", message.sender?.name ?? ""), icon: "banknote.fill") { pin in
                        do {
                            let paid = try await ChatApi.payRequest(message.id, pin: pin).data
                            withAnimation(.easeOut(duration: 0.22)) { done = true }
                            if let paid { onPaid(paid) }
                            Task {
                                try? await Task.sleep(nanoseconds: 1_600_000_000)
                                onDismiss()
                            }
                            return .ok
                        } catch {
                            return payFailure(error, host: host, close: onDismiss)
                        }
                    }
                    .frame(height: 500)
                }
            }
        }
    }
}

/// A tick that pops, a burst of rings, «Paid 25.00 SAR to Alice».
private struct PaidCelebration: View {
    @ObservedObject private var ch = Ch.shared
    let amount: String
    let to: String
    let title: String
    @State private var pop: CGFloat = 0
    @State private var burst: CGFloat = 0

    var body: some View {
        VStack(spacing: 4) {
            ZStack {
                Canvas { gc, size in
                    let r0 = min(size.width, size.height) / 2
                    let center = CGPoint(x: size.width / 2, y: size.height / 2)
                    for i in 0..<3 {
                        let t = min(max(burst - CGFloat(i) * 0.15, 0), 1)
                        let r = r0 * (0.35 + 0.65 * t)
                        gc.stroke(Path(ellipseIn: CGRect(x: center.x - r, y: center.y - r, width: r * 2, height: r * 2)), with: .color(ch.success.opacity(0.35 * (1 - t))), lineWidth: 3)
                    }
                    let dots = [Color(hex: 0x10B981), Color(hex: 0xF59E0B), Color(hex: 0x1E3A7B)]
                    for i in 0..<10 {
                        let a = Double(i) * 36 * .pi / 180
                        let r = r0 * (0.45 + 0.45 * burst)
                        let c = CGPoint(x: center.x + r * CGFloat(cos(a)), y: center.y + r * CGFloat(sin(a)))
                        gc.fill(Path(ellipseIn: CGRect(x: c.x - 4, y: c.y - 4, width: 8, height: 8)), with: .color(dots[i % 3].opacity(1 - burst)))
                    }
                }
                .frame(width: 170, height: 170)
                Image(systemName: "checkmark").font(.system(size: 40, weight: .bold)).foregroundStyle(.white)
                    .frame(width: 84, height: 84)
                    .background(LinearGradient(colors: moneyGreenColors, startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                    .scaleEffect(pop)
            }
            Text(verbatim: title).font(.dorr(20, .heavy)).foregroundStyle(ch.ink)
            Text(verbatim: amount).font(.dorr(26, .heavy)).foregroundStyle(ch.success).environment(\.layoutDirection, .leftToRight).padding(.top, 4)
            Text(L("ch_money_pay_to", to)).font(.dorr(13.5)).foregroundStyle(ch.mut)
        }
        .frame(maxWidth: .infinity).frame(height: 360)
        .onAppear {
            withAnimation(.spring(response: 0.45, dampingFraction: 0.4)) { pop = 1 }
            withAnimation(.easeOut(duration: 0.9)) { burst = 1 }
        }
    }
}

/// A big centred amount, digits only, with the decimal point.
private struct AmountField: View {
    @ObservedObject private var ch = Ch.shared
    @Binding var value: String
    @FocusState private var focused: Bool

    var body: some View {
        ZStack {
            if value.isEmpty { Text(verbatim: "0.00").font(.dorr(34, .heavy)).foregroundStyle(ch.soft) }
            TextField("", text: Binding(get: { value }, set: { v in
                value = String(v.replacingOccurrences(of: ",", with: ".").filter { $0.isNumber || $0 == "." }.prefix(12))
            }))
            .font(.dorr(34, .heavy)).foregroundStyle(ch.ink).tint(ch.success).multilineTextAlignment(.center)
            .keyboardType(.decimalPad).focused($focused)
        }
        .padding(.vertical, 16)
        .frame(maxWidth: .infinity)
        .background(ch.fieldFill, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(ch.fieldLine, lineWidth: 1))
        .environment(\.layoutDirection, .leftToRight)
        .onAppear { focused = true }
    }
}

/// Pick an occasion to send the money as a gift card (or none: a plain transfer).
private struct GiftPicker: View {
    @ObservedObject private var ch = Ch.shared
    let selected: String?
    let onPick: (String?) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(L("ch_gift_as")).font(.dorr(13, .bold)).foregroundStyle(ch.mut)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    chip(L("ch_gift_none"), selected == nil) { onPick(nil) }
                    ForEach(giftCards, id: \.key) { g in chip("\(g.emoji) \(L(g.title))", selected == g.key) { onPick(g.key) } }
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func chip(_ label: String, _ on: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(on ? .white : ch.ink)
                .padding(.horizontal, 12).padding(.vertical, 8).background(on ? ch.red : ch.surfaceMuted, in: Capsule())
        }
        .buttonStyle(.plain)
    }
}

/// «Send money» straight from a one-to-one chat: the amount and a note, then the wallet PIN. The server moves the money
/// and posts the receipt in the chat in one go; the same uuid on a retry can never pay twice.
struct SendMoneySheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    let peerName: String
    let onDismiss: () -> Void
    let onSent: (MessageDto) -> Void
    @State private var amount = ""
    @State private var note = ""
    /// 0 amount · 1 PIN · 2 done
    @State private var step = 0
    @State private var gift: String?
    @State private var uuid = UUID().uuidString

    var body: some View {
        let minor = parseAmountToMinor(amount)
        ChSheetCard {
            switch step {
            case 0:
                VStack(spacing: 0) {
                    Image(systemName: "banknote.fill").font(.system(size: 26)).foregroundStyle(.white).frame(width: 58, height: 58)
                        .background(LinearGradient(colors: moneyGreenColors, startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                    Text(L("ch_money_send_to", peerName)).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).multilineTextAlignment(.center).padding(.top, 10)
                    AmountField(value: $amount).padding(.top, 16)
                    ChField(placeholder: L("ch_money_note_hint"), text: $note, icon: "square.and.pencil", limit: 200).padding(.top, 12)
                    GiftPicker(selected: gift) { gift = $0 }.padding(.top, 12)
                    ChPrimaryButton(text: L("ch_next"), icon: "paperplane.fill", enabled: minor != nil) { withAnimation { step = 1 } }.padding(.top, 18)
                }
            case 1:
                WaPinPad(title: L("ch_money_send_title", amount), sub: L("ch_money_pay_to", peerName), icon: "banknote.fill") { pin in
                    do {
                        var body: [String: Any?] = ["amount_minor": minor ?? 0, "uuid": uuid]
                        let n = note.trimmingCharacters(in: .whitespacesAndNewlines)
                        if !n.isEmpty { body["note"] = n }
                        if let gift { body["gift"] = gift }
                        let sent = try await ChatApi.sendMoney(conversationId, pin: pin, body).data
                        withAnimation { step = 2 }
                        if let sent { onSent(sent) }
                        Task {
                            try? await Task.sleep(nanoseconds: 1_600_000_000)
                            onDismiss()
                        }
                        return .ok
                    } catch {
                        return payFailure(error, host: host, close: onDismiss)
                    }
                }
                .frame(height: 500)
            default:
                PaidCelebration(amount: amount, to: peerName, title: L("ch_money_sent_title"))
            }
        }
    }
}

/// Split a bill in a group: the total, who's in (everyone ticked to start, me included), equally or by hand. The
/// per-person amount updates as people are ticked; custom amounts must add up.
struct SplitBillSheet: View {
    @ObservedObject private var ch = Ch.shared
    let members: [MemberDto]
    let onDismiss: () -> Void
    let onSend: (_ totalMinor: Int, _ note: String?, _ equal: Bool, _ shares: [Int: Int]) -> Void
    @State private var amount = ""
    @State private var note = ""
    @State private var equal = true
    @State private var picked: [Int] = []
    @State private var custom: [Int: String] = [:]
    @State private var seeded = false

    var body: some View {
        let total = parseAmountToMinor(amount) ?? 0
        let perHead = picked.isEmpty ? 0 : total / picked.count
        let customSum = picked.reduce(0) { $0 + (parseAmountToMinor(custom[$1] ?? "") ?? 0) }
        let ready = total > 0 && picked.count >= 2 && (equal || customSum == total)
        ChSheetCard {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 12) {
                        Image(systemName: "arrow.triangle.branch").font(.system(size: 20)).foregroundStyle(.white).frame(width: 44, height: 44)
                            .background(LinearGradient(colors: moneyGreenColors, startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                        Text(L("ch_split_new")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                    }
                    AmountField(value: $amount).padding(.top, 14)
                    ChField(placeholder: L("ch_split_note_hint"), text: $note, icon: "square.and.pencil", limit: 200).padding(.top, 10)
                    // Equally / by hand.
                    HStack(spacing: 0) {
                        ForEach([(true, "ch_split_equal"), (false, "ch_split_custom")], id: \.0) { isEqual, label in
                            let on = equal == isEqual
                            Button { equal = isEqual } label: {
                                Text(L(label)).font(.dorr(13.5, .heavy)).foregroundStyle(on ? ch.ink : ch.mut).frame(maxWidth: .infinity).padding(.vertical, 10)
                                    .background(on ? ch.surface : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .padding(4)
                    .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .padding(.top, 14)
                    if members.isEmpty { ProgressView().tint(ch.red).frame(maxWidth: .infinity).padding(20) }
                    ForEach(Array(members.enumerated()), id: \.element.id) { i, member in
                        row(member, perHead: perHead).chStagger(i)
                    }
                    .padding(.top, 10)
                    if !equal && total > 0 {
                        let left = total - customSum
                        Text(left == 0 ? L("ch_split_adds_up") : L("ch_split_left", formatMinor(left, nil))).font(.dorr(12.5, .bold))
                            .foregroundStyle(left == 0 ? ch.success : ch.danger).padding(.top, 6).padding(.leading, 6)
                    }
                    ChPrimaryButton(text: L("ch_split_send"), icon: "paperplane.fill", enabled: ready) {
                        let shares: [Int: Int] = equal ? Dictionary(uniqueKeysWithValues: picked.map { ($0, 0) })
                            : Dictionary(uniqueKeysWithValues: picked.map { ($0, parseAmountToMinor(custom[$0] ?? "") ?? 0) })
                        let n = note.trimmingCharacters(in: .whitespacesAndNewlines)
                        onSend(total, n.isEmpty ? nil : n, equal, shares)
                        onDismiss()
                    }
                    .padding(.top, 16)
                }
            }
            .frame(maxHeight: 680)
        }
        // Everyone ticked, once the members arrive.
        .onAppear { seed() }
        .onChange(of: members.count) { _ in seed() }
    }

    private func seed() {
        guard !seeded, !members.isEmpty else { return }
        seeded = true
        picked = members.map(\.participantId)
    }

    private func row(_ member: MemberDto, perHead: Int) -> some View {
        let on = picked.contains(member.participantId)
        return HStack(spacing: 8) {
            Image(systemName: "checkmark").font(.system(size: 12, weight: .bold)).foregroundStyle(.white).scaleEffect(on ? 1 : 0.01)
                .frame(width: 24, height: 24)
                .background(on ? ch.success : .clear, in: Circle())
                .overlay(Circle().stroke(on ? ch.success : ch.soft, lineWidth: 2))
                .animation(.spring(response: 0.3, dampingFraction: 0.5), value: on)
            ChAvatar(url: member.profile?.avatar, name: member.profile?.name, key: member.profile?.key, size: 34)
            Text(verbatim: member.profile?.isMe == true ? L("ch_you") : member.profile?.name ?? "").font(.dorr(14, .semibold)).foregroundStyle(ch.ink).lineLimit(1)
                .frame(maxWidth: .infinity, alignment: .leading)
            if on {
                if equal {
                    Text(verbatim: formatMinor(perHead, nil)).font(.dorr(13.5, .heavy)).foregroundStyle(ch.success).environment(\.layoutDirection, .leftToRight)
                } else {
                    TextField("0.00", text: Binding(get: { custom[member.participantId] ?? "" }, set: { v in
                        custom[member.participantId] = String(v.filter { $0.isNumber || $0 == "." || $0 == "," }.prefix(10))
                    }))
                    .font(.dorr(13.5, .bold)).multilineTextAlignment(.center).keyboardType(.decimalPad)
                    .padding(.vertical, 8).frame(width: 104)
                    .background(ch.fieldFill, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
            }
        }
        .padding(.horizontal, 6).padding(.vertical, 7)
        .contentShape(Rectangle())
        .onTapGesture { if on { picked.removeAll { $0 == member.participantId } } else { picked.append(member.participantId) } }
        .padding(.vertical, 3)
    }
}
