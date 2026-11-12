import SwiftUI
import UIKit

// driverApp `ui/SettingsScreen.kt` — the captain's settings, the terms (`LegalDialog`, `TermsGate`) and `AlertPrefs`.
// Also Material's `AlertDialog` look (`AlertDialogCard` + `AlertTextButton`), shared by the account screens.

/**
 * The captain's settings (docs/rides_tasks.md D-304, D-307, D-313, D-314, D-316..D-318): how a request rings,
 * the text size, the phones trusted with the wallet, a new number, the terms in force, and closing the account.
 */
struct SettingsScreen: View {
    let onBack: () -> Void
    let onClosed: () -> Void

    @Environment(\.pal) private var pal
    @ObservedObject private var prefs = DriverPrefs.shared
    @State private var alerts: AlertsDto?
    @State private var devices: [DeviceDto] = []
    @State private var legal: [LegalDocDto] = []
    @State private var phoneOpen = false
    @State private var phoneBusy = false
    @State private var closeOpen = false
    @State private var reading: LegalDocDto?

    init(onBack: @escaping () -> Void, onClosed: @escaping () -> Void) {
        self.onBack = onBack
        self.onClosed = onClosed
    }

    private func saveAlerts(_ body: JsonBody) {
        Task {
            if let a = await DriverApi.saveAlerts(body: body).value {
                alerts = a
                AlertPrefs.save(a)
            }
        }
    }

    var body: some View {
        ScreenScaffold(L("st_title"), onBack: onBack) {
            ScrollView {
                VStack(spacing: 14) {
                    // D-304: how a request rings.
                    StSection(icon: "bell.badge.fill", title: L("st_alerts")) {
                        if let a = alerts {
                            StToggleLine(label: L("st_sound"), on: a.sound ?? true) { on in saveAlerts(["sound": on]) }
                            StToggleLine(label: L("st_vibration"), on: a.vibration ?? true) { on in saveAlerts(["vibration": on]) }
                            StToggleLine(label: L("st_voice"), on: a.voice ?? false) { on in saveAlerts(["voice": on]) }
                        }
                    }
                    // D-307: the text size.
                    StSection(icon: "textformat.size", title: L("st_text_size")) {
                        HStack(spacing: 8) {
                            ForEach([(CGFloat(0.9), "st_small"), (1, "st_normal"), (1.15, "st_large"), (1.3, "st_xlarge")], id: \.1) { scale, label in
                                let on = abs(prefs.fontScale - scale) < 0.01
                                Text(L(label))
                                    .font(.dorr(13 * scale, .bold))
                                    .foregroundStyle(on ? Color.white : pal.ink)
                                    .padding(.horizontal, 12)
                                    .padding(.vertical, 8)
                                    .background(Capsule().fill(on ? pal.accent : pal.bg))
                                    .contentShape(Capsule())
                                    .onTapGesture { prefs.chooseFontScale(scale) }
                            }
                        }
                    }
                    // D-313: phones trusted with the wallet.
                    StSection(icon: "lock.iphone", title: L("st_devices")) {
                        if devices.isEmpty { Text(L("st_no_devices")).font(.dorr(13)).foregroundStyle(pal.mut) }
                        ForEach(Array(devices.enumerated()), id: \.offset) { _, d in
                            let current = d.current ?? false
                            HStack(spacing: 0) {
                                DorrIcon("iphone", size: 20, tint: current ? pal.success : pal.mut)
                                HSpace(8)
                                VStack(alignment: .leading, spacing: 0) {
                                    Text((d.device ?? "") + (current ? " · " + L("st_this_phone") : ""))
                                        .font(.dorr(13, .semibold)).foregroundStyle(pal.ink)
                                    Text(stWhenText(d.lastSeenAt)).font(.dorr(11)).foregroundStyle(pal.mut)
                                }
                                .frame(maxWidth: .infinity, alignment: .leading)
                                if !current {
                                    DorrIcon("trash", size: 22, tint: pal.danger)
                                        .contentShape(Rectangle())
                                        .onTapGesture {
                                            Task { devices = await DriverApi.forgetDevice(id: d.id ?? 0).value ?? devices }
                                        }
                                }
                            }
                            .padding(.vertical, 4)
                        }
                    }
                    // D-314: a new number.
                    StSection(icon: "iphone", title: L("st_phone")) {
                        Text(L("st_phone_hint")).font(.dorr(12.5)).foregroundStyle(pal.mut)
                        VSpace(8)
                        Text(L("st_phone_change")).font(.dorr(16, .bold)).foregroundStyle(pal.accent)
                            .padding(.vertical, 4)
                            .contentShape(Rectangle())
                            .onTapGesture { phoneOpen = true }
                    }
                    // D-317: the terms in force, their version and my acceptance.
                    StSection(icon: "hammer.fill", title: L("st_terms")) {
                        ForEach(Array(legal.enumerated()), id: \.offset) { _, doc in
                            let needs = doc.needsAcceptance ?? false
                            HStack(spacing: 0) {
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(doc.title ?? "").font(.dorr(16, .semibold)).foregroundStyle(pal.ink)
                                    Text(L("st_version", String((doc.updatedAt ?? "").prefix(10)))).font(.dorr(11)).foregroundStyle(pal.mut)
                                }
                                .frame(maxWidth: .infinity, alignment: .leading)
                                Text(L(needs ? "st_needs_ok" : "st_accepted"))
                                    .font(.dorr(12, .bold))
                                    .foregroundStyle(needs ? pal.warning : pal.success)
                            }
                            .padding(.vertical, 6)
                            .frame(maxWidth: .infinity)
                            .contentShape(Rectangle())
                            .onTapGesture { reading = doc }
                        }
                    }
                    // D-316: closing.
                    Text(L("st_close")).font(.dorr(16, .bold)).foregroundStyle(pal.danger)
                        .padding(.horizontal, 16)
                        .padding(.vertical, 10)
                        .contentShape(Capsule())
                        .onTapGesture { closeOpen = true }
                        .frame(maxWidth: .infinity)
                }
                .padding(16)
            }
        }
        .task {
            alerts = await DriverApi.alerts().value
            devices = await DriverApi.devices().value ?? []
            legal = await DriverApi.legal().value ?? []
        }
        .dorrDialog(isPresented: $phoneOpen, dismissible: !phoneBusy) {
            PhoneChangeDialog(busy: $phoneBusy, onClose: { phoneOpen = false })
        }
        .background(
            Color.clear.dorrDialog(isPresented: $closeOpen) {
                CloseAccountDialog(onClose: { closeOpen = false }, onClosed: onClosed)
            }
        )
        .background(
            Color.clear.dorrDialog(isPresented: Binding(get: { reading != nil }, set: { if !$0 { reading = nil } })) {
                if let doc = reading {
                    LegalDialog(doc: doc, onClose: { reading = nil }, onAccepted: { legal = $0; reading = nil })
                }
            }
        )
    }
}

private struct StSection<Content: View>: View {
    @Environment(\.pal) private var pal
    let icon: String
    let title: String
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                DorrIcon(icon, size: 20, tint: pal.accent)
                HSpace(8)
                Text(title).font(.dorr(15, .black)).foregroundStyle(pal.ink)
            }
            VSpace(10)
            content()
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface(20)
    }
}

private struct StToggleLine: View {
    @Environment(\.pal) private var pal
    let label: String
    let on: Bool
    let onChange: (Bool) -> Void

    var body: some View {
        HStack(spacing: 0) {
            Text(label).font(.dorr(14)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
            DorrToggle(on: on, onChange: onChange)
        }
        .padding(.vertical, 4)
    }
}

private struct PhoneChangeDialog: View {
    @Environment(\.pal) private var pal
    @Binding var busy: Bool
    let onClose: () -> Void
    @State private var phone = ""
    @State private var pin = ""
    @State private var code = ""
    @State private var sent = false
    @State private var done = false
    @State private var error: String?

    var body: some View {
        AlertDialogCard(title: { Text(L("st_phone_change")).font(.dorr(24, .bold)) }, text: {
            VStack(alignment: .leading, spacing: 0) {
                if done {
                    Text(L("st_phone_done"))
                } else {
                    DorrField(value: phone, onValueChange: { phone = String($0.filter(\.isNumber).prefix(15)) },
                              placeholder: L("st_new_phone"), keyboardOptions: KeyboardOptions(keyboardType: .phonePad), enabled: !sent)
                    VSpace(6)
                    if !sent {
                        DorrField(value: pin, onValueChange: { pin = String($0.filter(\.isNumber).prefix(4)) },
                                  placeholder: L("wl_pin"), keyboardOptions: KeyboardOptions(keyboardType: .numberPad))
                    } else {
                        DorrField(value: code, onValueChange: { code = String($0.filter(\.isNumber).prefix(6)) },
                                  placeholder: L("wx_freeze_code"), keyboardOptions: KeyboardOptions(keyboardType: .numberPad))
                    }
                }
                if let error { Text(error).font(.dorr(12)).foregroundStyle(pal.danger) }
            }
        }, buttons: {
            if !done { AlertTextButton(L("cancel"), onClick: onClose) }
            if done {
                AlertTextButton(L("wx_ok"), onClick: onClose)
            } else {
                AlertTextButton(L(sent ? "wx_freeze_confirm_generic" : "wx_freeze_send"),
                                enabled: !busy && phone.count >= 8 && (!sent || code.count >= 4)) {
                    busy = true
                    error = nil
                    Task {
                        let r: ApiResult<JSONAny> = !sent
                            ? await DriverApi.startPhoneChange(pin: pin.count == 4 ? pin : nil, body: ["phone": phone])
                            : await DriverApi.confirmPhoneChange(body: ["code": code])
                        switch r {
                        case .ok: if !sent { sent = true } else { done = true }
                        case .failed(let e): error = e.displayMessage
                        case .offline: error = L("error_offline")
                        }
                        busy = false
                    }
                }
            }
        })
    }
}

/** D-316, D-226: what blocks closing; then closing, for good. */
private struct CloseAccountDialog: View {
    @Environment(\.pal) private var pal
    let onClose: () -> Void
    let onClosed: () -> Void
    @State private var check: CloseCheckDto?
    @State private var reason = ""
    @State private var error: String?

    var body: some View {
        AlertDialogCard(icon: { DorrIcon("exclamationmark.triangle.fill", size: 24, tint: pal.danger) },
                        title: { Text(L("st_close")).font(.dorr(24, .bold)) }, text: {
            VStack(alignment: .leading, spacing: 0) {
                if let c = check {
                    if !(c.canClose ?? false) {
                        Text(L("st_close_blocked")).font(.dorr(13))
                        ForEach(Array((c.blockers ?? []).enumerated()), id: \.offset) { _, b in
                            Text("• " + (b.label ?? "")).font(.dorr(13)).foregroundStyle(pal.danger).padding(.top, 4)
                        }
                    } else {
                        Text(L("st_close_warn")).font(.dorr(13))
                        VSpace(8)
                        DorrField(value: reason, onValueChange: { reason = String($0.prefix(300)) }, placeholder: L("st_close_reason"))
                    }
                } else {
                    Text("…")
                }
                if let error { Text(error).font(.dorr(12)).foregroundStyle(pal.danger) }
            }
        }, buttons: {
            AlertTextButton(L("cancel"), onClick: onClose)
            if check?.canClose == true {
                AlertTextButton(L("st_close_now"), color: pal.danger, enabled: reason.trimmingCharacters(in: .whitespacesAndNewlines).count >= 3) {
                    Task {
                        switch await DriverApi.closeAccount(body: ["reason": reason.trimmingCharacters(in: .whitespacesAndNewlines), "confirm": true]) {
                        case .ok: onClosed()
                        case .failed(let e): error = e.displayMessage
                        case .offline: error = L("error_offline")
                        }
                    }
                }
            }
        })
        .task { check = await DriverApi.closeCheck().value }
    }
}

/** D-317, D-318: a page in force, and accepting this version. */
struct LegalDialog: View {
    @Environment(\.pal) private var pal
    let doc: LegalDocDto
    let onClose: () -> Void
    let onAccepted: ([LegalDocDto]) -> Void

    init(doc: LegalDocDto, onClose: @escaping () -> Void, onAccepted: @escaping ([LegalDocDto]) -> Void) {
        self.doc = doc
        self.onClose = onClose
        self.onAccepted = onAccepted
    }

    var body: some View {
        let page = VStack(alignment: .leading, spacing: 0) {
            Text(L("st_version", String((doc.updatedAt ?? "").prefix(10)))).font(.dorr(11)).foregroundStyle(pal.mut)
            VSpace(6)
            Text(htmlToText(doc.content ?? "")).font(.dorr(13))
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        AlertDialogCard(title: { Text(doc.title ?? "").font(.dorr(24, .bold)) }, text: {
            // `heightIn(max = 420.dp)` + `verticalScroll`: as tall as the text, scrolling past 420.
            ViewThatFits(in: .vertical) {
                page
                ScrollView { page }
            }
            .frame(maxHeight: 420)
        }, buttons: {
            if doc.needsAcceptance == true {
                AlertTextButton(L("st_accept")) {
                    Task {
                        if let all = await DriverApi.acceptLegal(body: ["types": [doc.type ?? ""]]).value { onAccepted(all) }
                    }
                }
            } else {
                AlertTextButton(L("wx_ok"), onClick: onClose)
            }
        })
    }
}

/** D-318: on opening, newer terms than the ones accepted are shown first. */
struct TermsGate: View {
    @State private var pending: [LegalDocDto] = []

    init() {}

    var body: some View {
        Color.clear
            .frame(width: 0, height: 0)
            .task { pending = (await DriverApi.legal().value ?? []).filter { $0.needsAcceptance == true } }
            .dorrDialog(isPresented: Binding(get: { !pending.isEmpty }, set: { if !$0 { pending = Array(pending.dropFirst()) } })) {
                if let doc = pending.first {
                    LegalDialog(doc: doc, onClose: { pending = Array(pending.dropFirst()) },
                                onAccepted: { all in pending = all.filter { $0.needsAcceptance == true } })
                }
            }
    }
}

/** D-304: how a request rings — kept on the phone so the ring follows it at once. */
enum AlertPrefs {
    private static let prefs = "dorr_alerts."

    static func save(_ a: AlertsDto) {
        let store = UserDefaults.standard
        store.set(a.sound ?? true, forKey: prefs + "sound")
        store.set(a.vibration ?? true, forKey: prefs + "vibration")
        store.set(a.voice ?? false, forKey: prefs + "voice")
    }

    static func sound() -> Bool { UserDefaults.standard.object(forKey: prefs + "sound") as? Bool ?? true }

    static func vibration() -> Bool { UserDefaults.standard.object(forKey: prefs + "vibration") as? Bool ?? true }
}

/// `WalletScreen.kt` `whenText`: the date (medium) and time (short) in the phone's zone, in the app's language.
private func stWhenText(_ iso: String?) -> String {
    guard let at = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = Locale(identifier: DriverPrefs.locale)
    f.dateStyle = .medium
    f.timeStyle = .short
    return f.string(from: at)
}

/// `Html.fromHtml(html, FROM_HTML_MODE_COMPACT).toString()`: the page's words without the tags.
private func htmlToText(_ html: String) -> String {
    guard !html.isEmpty, let data = html.data(using: .utf8),
          let text = try? NSAttributedString(data: data, options: [
              .documentType: NSAttributedString.DocumentType.html,
              .characterEncoding: String.Encoding.utf8.rawValue,
          ], documentAttributes: nil).string
    else { return html }
    return text.trimmingCharacters(in: .whitespacesAndNewlines)
}

// MARK: - Material's AlertDialog

/// Material 3's `AlertDialog` as the captain app draws it (container `Pal.card`): the icon on top (title then centred), the
/// title (headlineSmall), the text (bodyMedium in `Pal.mut`), and the buttons at the end — dismiss, then confirm. Show it
/// with `.dorrDialog(isPresented:) { AlertDialogCard(…) }`.
struct AlertDialogCard<Icon: View, Title: View, Content: View, Buttons: View>: View {
    @Environment(\.pal) private var pal
    private let icon: Icon?
    private let title: Title
    private let content: Content
    private let buttons: Buttons

    init(@ViewBuilder icon: () -> Icon, @ViewBuilder title: () -> Title, @ViewBuilder text: () -> Content,
         @ViewBuilder buttons: () -> Buttons) {
        self.icon = icon()
        self.title = title()
        self.content = text()
        self.buttons = buttons()
    }

    var body: some View {
        let centred = icon != nil
        VStack(alignment: centred ? .center : .leading, spacing: 0) {
            if let icon { icon.padding(.bottom, 16) }
            title
                .font(.dorr(24))
                .foregroundStyle(pal.ink)
                .multilineTextAlignment(centred ? .center : .leading)
                .frame(maxWidth: .infinity, alignment: centred ? .center : .leading)
                .padding(.bottom, 16)
            content
                .font(.dorr(14))
                .foregroundStyle(pal.mut)
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.bottom, 24)
            HStack(spacing: 8) {
                Spacer(minLength: 0)
                buttons
            }
        }
        .padding(24)
        .frame(maxWidth: 560)
        .background(RoundedRectangle(cornerRadius: 28).fill(pal.card))
        .padding(.horizontal, 24)
    }
}

extension AlertDialogCard where Icon == EmptyView {
    init(@ViewBuilder title: () -> Title, @ViewBuilder text: () -> Content, @ViewBuilder buttons: () -> Buttons) {
        self.icon = nil
        self.title = title()
        self.content = text()
        self.buttons = buttons()
    }
}

/// Material's `TextButton { Text(…) }` (labelLarge; the brand colour, faded when off). `color` set = the Text's own colour,
/// which stays when the button is off (as on Android).
struct AlertTextButton: View {
    @Environment(\.pal) private var pal
    let text: String
    var color: Color? = nil
    var weight: Font.Weight = .semibold
    var enabled = true
    let onClick: () -> Void

    init(_ text: String, color: Color? = nil, weight: Font.Weight = .semibold, enabled: Bool = true, onClick: @escaping () -> Void) {
        self.text = text
        self.color = color
        self.weight = weight
        self.enabled = enabled
        self.onClick = onClick
    }

    var body: some View {
        Button(action: onClick) {
            Text(text)
                .font(.dorr(14, weight))
                .foregroundStyle(color ?? (enabled ? pal.accent : pal.ink.opacity(0.38)))
                .padding(.horizontal, 12)
                .frame(minHeight: 40)
                .contentShape(Capsule())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}
