import SwiftUI

// driverApp `ui/WalletExtras.kt`.

/// D-210: the last balance the server confirmed — shown, with its time, when the wallet can't be reached.
enum WalletCache {
    private static let PREFS = "dorr_wallet_cache"

    /// `WalletDto` as it is stored (Gson's `toJson` of it there).
    private struct Stored: Codable {
        var currencyCode: String?
        var currencySymbol: String?
        var walletNumber: String?
        var totalMinor: Int?
        var withdrawableMinor: Int?
        var spendOnlyMinor: Int?
        var heldMinor: Int?
    }

    static func save(_ w: WalletDto) {
        let stored = Stored(currencyCode: w.currencyCode, currencySymbol: w.currencySymbol, walletNumber: w.walletNumber,
                            totalMinor: w.totalMinor, withdrawableMinor: w.withdrawableMinor, spendOnlyMinor: w.spendOnlyMinor,
                            heldMinor: w.heldMinor)
        guard let json = try? JSONEncoder().encode(stored) else { return }
        let p = UserDefaults.standard
        p.set(String(data: json, encoding: .utf8), forKey: PREFS + ".wallet")
        p.set(Int(Date().timeIntervalSince1970 * 1000), forKey: PREFS + ".at")
    }

    /// The cached wallet and when it was confirmed (epoch ms), or nil.
    static func load() -> (WalletDto, Int)? {
        let p = UserDefaults.standard
        guard let json = p.string(forKey: PREFS + ".wallet"), let data = json.data(using: .utf8),
              let s = try? JSONDecoder().decode(Stored.self, from: data) else { return nil }
        var w = WalletDto()
        w.currencyCode = s.currencyCode
        w.currencySymbol = s.currencySymbol
        w.walletNumber = s.walletNumber
        w.totalMinor = s.totalMinor
        w.withdrawableMinor = s.withdrawableMinor
        w.spendOnlyMinor = s.spendOnlyMinor
        w.heldMinor = s.heldMinor
        return (w, p.integer(forKey: PREFS + ".at"))
    }
}

/// D-216, D-223, D-224, D-299: what stands between the captain and a withdrawal, said plainly.
struct PayoutBanners: View {
    let status: PayoutStatusDto?
    /// Epoch ms.
    let cachedAt: Int?
    let onInternational: () -> Void
    let onInvoices: () -> Void

    init(_ status: PayoutStatusDto?, cachedAt: Int?, onInternational: @escaping () -> Void, onInvoices: @escaping () -> Void) {
        self.status = status
        self.cachedAt = cachedAt
        self.onInternational = onInternational
        self.onInvoices = onInvoices
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            if let at = cachedAt {
                let f: DateFormatter = {
                    let f = DateFormatter()
                    f.locale = Locale(identifier: DriverPrefs.locale)
                    f.dateFormat = "dd/MM HH:mm"
                    return f
                }()
                Banner(L("wx_cached", f.string(from: Date(timeIntervalSince1970: Double(at) / 1000))), kind: .warning)
            }
            if let s = status {
                if s.frozen == true {
                    Banner(L("wx_frozen"), kind: .error)
                } else if s.kycOk != true {
                    Banner(L("wx_kyc"), kind: .warning)
                }
                switch s.international?.status {
                case "pending": Banner(L("wx_intl_pending"), kind: .info)
                case "rejected": Banner(L("wx_intl_rejected", s.international?.answer ?? ""), kind: .warning)
                default: EmptyView()
                }
            }
            HStack(spacing: 8) {
                SmallLink(icon: "doc.text", text: L("wx_invoices"), onClick: onInvoices)
                if let s = status, ["none", "rejected"].contains(s.international?.status ?? "") {
                    SmallLink(icon: "globe", text: L("wx_intl"), onClick: onInternational)
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 16).padding(.top, 10)
    }
}

private struct SmallLink: View {
    @Environment(\.pal) private var pal
    let icon: String
    let text: String
    let onClick: () -> Void

    var body: some View {
        HStack(spacing: 0) {
            DorrIcon(icon, size: 18, tint: pal.accent)
            HSpace(6)
            Text(text).font(.dorr(12.5, .bold)).foregroundStyle(pal.accent)
        }
        .padding(.horizontal, 12).padding(.vertical, 10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(RoundedRectangle(cornerRadius: 14).fill(pal.accent.opacity(0.08)))
        .contentShape(RoundedRectangle(cornerRadius: 14))
        .onTapGesture(perform: onClick)
    }
}

/// Material's `AlertDialog` card: the icon, the title, the text, the buttons at the end — shown through `.dorrDialog`.
private struct AlertCard<Icon: View, Content: View, Buttons: View>: View {
    @Environment(\.pal) private var pal
    let title: String
    @ViewBuilder let icon: () -> Icon
    @ViewBuilder let content: () -> Content
    @ViewBuilder let buttons: () -> Buttons

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            icon().frame(maxWidth: .infinity)
            Text(title).font(.dorr(24, .bold)).foregroundStyle(pal.ink)
                .multilineTextAlignment(.center)
                .frame(maxWidth: .infinity)
                .padding(.top, 16)
            content().padding(.top, 16)
            HStack(spacing: 8) {
                Spacer(minLength: 0)
                buttons()
            }
            .padding(.top, 24)
        }
        .padding(24)
        .frame(maxWidth: 560)
        .background(RoundedRectangle(cornerRadius: 28).fill(pal.card))
        .padding(.horizontal, 24)
    }
}

/// Material's `TextButton`: the brand colour, faded when off.
private struct DialogTextButton: View {
    @Environment(\.pal) private var pal
    let text: String
    var enabled = true
    let onClick: () -> Void

    var body: some View {
        Text(text).font(.dorr(14, .medium))
            .foregroundStyle(enabled ? pal.accent : pal.ink.opacity(0.38))
            .padding(.horizontal, 12).frame(minHeight: 40)
            .contentShape(Capsule())
            .onTapGesture { if enabled { onClick() } }
    }
}

/// D-224: ask for payouts to an account abroad. The dialog's card: show it with `.dorrDialog(isPresented:)`.
struct InternationalDialog: View {
    @Environment(\.pal) private var pal
    let onClose: () -> Void
    let onSent: () -> Void

    @State private var reason = ""
    @State private var busy = false
    @State private var error: String?

    init(onClose: @escaping () -> Void, onSent: @escaping () -> Void) {
        self.onClose = onClose
        self.onSent = onSent
    }

    var body: some View {
        AlertCard(title: L("wx_intl")) {
            DorrIcon("globe", size: 24, tint: pal.accent)
        } content: {
            VStack(alignment: .leading, spacing: 0) {
                Text(L("wx_intl_hint")).font(.dorr(13)).foregroundStyle(pal.mut)
                VSpace(8)
                DorrField(value: reason, onValueChange: { reason = String($0.prefix(500)) }, placeholder: L("wx_intl_reason"))
                if let error { Text(error).font(.dorr(12)).foregroundStyle(pal.danger) }
            }
        } buttons: {
            DialogTextButton(text: L("cancel"), onClick: onClose)
            DialogTextButton(text: L("rk_appeal_send"), enabled: reason.trimmingCharacters(in: .whitespacesAndNewlines).count >= 5 && !busy) {
                busy = true
                Task {
                    switch await DriverApi.requestInternationalPayouts(body: ["reason": reason.trimmingCharacters(in: .whitespacesAndNewlines)]) {
                    case .ok: onSent()
                    case .failed(let e): error = e.displayMessage
                    case .offline: error = L("error_offline")
                    }
                    busy = false
                }
            }
        }
    }
}

/// D-227, D-228: monthly statements of DORR's commission, with their credit / debit notes. Full screen: show it with
/// `.dorrDialog(isPresented:)` (Android's full-width `Dialog`).
struct InvoicesScreen: View {
    @Environment(\.pal) private var pal
    let wallet: WalletDto?
    let onClose: () -> Void

    @State private var months: [InvoiceDto]?
    @State private var open: InvoiceDto?

    init(_ wallet: WalletDto?, onClose: @escaping () -> Void) {
        self.wallet = wallet
        self.onClose = onClose
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                DorrIcon("arrow.backward", size: 24, tint: pal.ink)
                    .frame(width: 40, height: 40)
                    .contentShape(Circle())
                    .onTapGesture { if open != nil { open = nil } else { onClose() } }
                Text(open?.month ?? L("wx_invoices")).font(.dorr(19, .black)).foregroundStyle(pal.ink)
            }
            .padding(12)
            ScrollView {
                VStack(alignment: .leading, spacing: 10) {
                    if let chosen = open {
                        InvoiceDetail(month: chosen.month ?? "", w: wallet)
                    } else if let list = months {
                        if list.isEmpty {
                            Text(L("wx_no_invoices")).font(.dorr(16)).foregroundStyle(pal.mut).padding(24)
                        } else {
                            ForEach(Array(list.enumerated()), id: \.offset) { _, m in InvoiceCard(m: m, w: wallet) { open = m } }
                        }
                    } else {
                        CircularProgress(color: pal.accent, size: 40, lineWidth: 4).frame(maxWidth: .infinity).padding(30)
                    }
                }
                .padding(.horizontal, 16)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
        .background(pal.bg.ignoresSafeArea())
        .task { months = await DriverApi.invoices().value ?? [] }
    }
}

private struct InvoiceCard: View {
    @Environment(\.pal) private var pal
    let m: InvoiceDto
    let w: WalletDto?
    let onClick: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                DorrIcon("doc.text", size: 24, tint: pal.accent)
                HSpace(8)
                VStack(alignment: .leading, spacing: 0) {
                    Text(m.month ?? "").font(.dorr(16, .black)).foregroundStyle(pal.ink)
                    Text(m.number ?? "").font(.dorr(11)).foregroundStyle(pal.mut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Text(L("wx_trips_n", m.trips ?? 0)).font(.dorr(12)).foregroundStyle(pal.mut)
            }
            VSpace(8)
            InvoiceLine(label: L("wx_fares"), minor: m.faresMinor ?? 0, w: w)
            InvoiceLine(label: L("wx_commission"), minor: -(m.commissionMinor ?? 0), w: w)
            if (m.taxMinor ?? 0) > 0 { InvoiceLine(label: L("wx_tax"), minor: -(m.taxMinor ?? 0), w: w) }
            InvoiceLine(label: L("wx_net"), minor: m.netMinor ?? 0, w: w, bold: true)
            if (m.notesCount ?? 0) > 0 {
                Text(L("wx_notes_n", m.notesCount ?? 0)).font(.dorr(12)).foregroundStyle(pal.accent).padding(.top, 4)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface(18)
        .contentShape(RoundedRectangle(cornerRadius: 18))
        .onTapGesture(perform: onClick)
    }
}

private struct InvoiceDetail: View {
    @Environment(\.pal) private var pal
    let month: String
    let w: WalletDto?
    @State private var d: InvoiceDto?

    var body: some View {
        Group {
            if let inv = d {
                VStack(alignment: .leading, spacing: 10) {
                    InvoiceCard(m: inv, w: w) {}
                    Text(L("wx_lines")).font(.dorr(15, .black)).foregroundStyle(pal.ink)
                    VStack(alignment: .leading, spacing: 0) {
                        ForEach(Array((inv.lines ?? []).enumerated()), id: \.offset) { _, l in
                            HStack(spacing: 0) {
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(l.rideNumber ?? "").font(.dorr(13, .semibold)).foregroundStyle(pal.ink)
                                    Text(String((l.date ?? "").prefix(10))).font(.dorr(11)).foregroundStyle(pal.mut)
                                }
                                .frame(maxWidth: .infinity, alignment: .leading)
                                VStack(alignment: .trailing, spacing: 0) {
                                    Text(amount(l.fareMinor ?? 0, w)).font(.dorr(13)).foregroundStyle(pal.ink)
                                    Text("− " + amount((l.commissionMinor ?? 0) + (l.taxMinor ?? 0), w)).font(.dorr(11)).foregroundStyle(pal.danger)
                                }
                            }
                            .padding(.vertical, 4)
                        }
                    }
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .surface(16)
                    let notes = inv.notes ?? []
                    if !notes.isEmpty {
                        Text(L("wx_notes")).font(.dorr(15, .black)).foregroundStyle(pal.ink)
                        VStack(alignment: .leading, spacing: 0) {
                            ForEach(Array(notes.enumerated()), id: \.offset) { _, n in
                                let credit = n.kind == "credit"
                                let tint = credit ? pal.success : pal.danger
                                HStack(spacing: 0) {
                                    Text(L(credit ? "wx_credit_note" : "wx_debit_note")).font(.dorr(11, .bold)).foregroundStyle(tint)
                                        .padding(.horizontal, 8).padding(.vertical, 2)
                                        .background(Capsule().fill(tint.opacity(0.12)))
                                    HSpace(8)
                                    Text(n.title ?? "").font(.dorr(13)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                                    Text((credit ? "+" : "−") + amount(n.amountMinor ?? 0, w)).font(.dorr(13, .bold)).foregroundStyle(tint)
                                }
                                .padding(.vertical, 4)
                            }
                        }
                        .padding(12)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .surface(16)
                    }
                }
            } else {
                CircularProgress(color: pal.accent, size: 40, lineWidth: 4).frame(maxWidth: .infinity).padding(30)
            }
        }
        .task(id: month) { d = await DriverApi.invoice(month: month).value }
    }
}

private struct InvoiceLine: View {
    @Environment(\.pal) private var pal
    let label: String
    let minor: Int
    let w: WalletDto?
    var bold = false

    var body: some View {
        HStack(spacing: 0) {
            Text(label).font(.dorr(13, bold ? .bold : .regular)).foregroundStyle(bold ? pal.ink : pal.mut)
                .frame(maxWidth: .infinity, alignment: .leading)
            Text((minor < 0 ? "− " : "") + amount(abs(minor), w)).font(.dorr(13, bold ? .black : .semibold))
                .foregroundStyle(minor < 0 ? pal.danger : pal.ink)
        }
        .padding(.vertical, 2)
    }
}

/// D-299: the phone is lost — freeze the money with a code to the number (no sign-in). The dialog's card: show it with
/// `.dorrDialog(isPresented:)`.
struct EmergencyFreezeDialog: View {
    @Environment(\.pal) private var pal
    let dialCode: String
    let onClose: () -> Void

    @State private var phone = ""
    @State private var code = ""
    @State private var sent = false
    @State private var done = false
    @State private var busy = false
    @State private var error: String?

    init(dialCode: String, onClose: @escaping () -> Void) {
        self.dialCode = dialCode
        self.onClose = onClose
    }

    var body: some View {
        AlertCard(title: L("wx_freeze_title")) {
            DorrIcon(done ? "checkmark.shield" : "snowflake", size: 24, tint: done ? pal.success : pal.info)
        } content: {
            VStack(alignment: .leading, spacing: 0) {
                if done {
                    Text(L("wx_freeze_done")).font(.dorr(13)).foregroundStyle(pal.ink)
                } else {
                    Text(L("wx_freeze_hint")).font(.dorr(13)).foregroundStyle(pal.mut)
                    VSpace(8)
                    DorrField(value: phone, onValueChange: { phone = String($0.filter { $0.isDigit }.prefix(15)) },
                              placeholder: L("wx_freeze_phone", dialCode))
                    if sent {
                        VSpace(8)
                        DorrField(value: code, onValueChange: { code = String($0.filter { $0.isDigit }.prefix(6)) }, placeholder: L("wx_freeze_code"))
                    }
                }
                if let error { Text(error).font(.dorr(12)).foregroundStyle(pal.danger) }
            }
        } buttons: {
            if done {
                DialogTextButton(text: L("wx_ok"), onClick: onClose)
            } else {
                DialogTextButton(text: L("cancel"), onClick: onClose)
                DialogTextButton(text: L(sent ? "wx_freeze_confirm" : "wx_freeze_send"),
                                 enabled: !busy && phone.count >= 6 && (!sent || code.count >= 4)) {
                    busy = true; error = nil
                    Task {
                        var body: JsonBody = ["dial_code": dialCode, "phone": phone]
                        if sent { body["code"] = code }
                        let r = !sent ? await DriverApi.emergencyFreezeRequest(body: body) : await DriverApi.emergencyFreezeConfirm(body: body)
                        switch r {
                        case .ok: if !sent { sent = true } else { done = true }
                        case .failed(let e): error = e.displayMessage
                        case .offline: error = L("error_offline")
                        }
                        busy = false
                    }
                }
            }
        }
    }
}

private extension Character {
    /// Kotlin `Char.isDigit()`: any Unicode decimal digit (Arabic-Indic too).
    var isDigit: Bool { unicodeScalars.count == 1 && unicodeScalars.allSatisfy { CharacterSet.decimalDigits.contains($0) } }
}
