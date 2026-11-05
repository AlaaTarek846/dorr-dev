import SwiftUI

/// androidApp `WalletScreen.kt` — the wallet, as one visit: PIN gate first (every time it is entered —
/// leaving re-locks it), then a small stack of pages that slide over each other, with bottom sheets and a
/// toast on top. It is drawn inside the main screen, above the tab content and below the tab bar.
@MainActor
struct WalletScreen: View {
    @StateObject private var host: WalletHost
    @State private var unlocked = false

    init(onExit: @escaping () -> Void) {
        #if DEBUG
        if let start = DebugFlags.walletStart {
            _host = StateObject(wrappedValue: WalletHost(start: start.route, onExit: onExit))
            _unlocked = State(initialValue: start.unlocked)
            return
        }
        #endif
        _host = StateObject(wrappedValue: WalletHost(onExit: onExit))
    }

    var body: some View {
        WalletChrome(host: host) {
            if !unlocked {
                WaGate(onUnlocked: { withAnimation(.easeOut(duration: 0.3)) { unlocked = true } }, onCancel: { host.onExit() })
            } else {
                WalletPagesFor(host: host)
                    .task {
                        // A wallet QR tapped in a chat: straight to its transfer confirmation.
                        guard let qr = WalletDeepLink.consumeQr() else { return }
                        do {
                            if let who = try await WalletApi.transferLookup(TransferLookupRequest(mode: "qr", qr: qr)).data {
                                host.push(.transferConfirm(who))
                            }
                        } catch {
                            if let message = error.apiFailure.message { host.showToast(message) }
                        }
                    }
                WaSheetHost(host: host)
            }
        }
        // Swiping to the dollar wallet must not outlive this visit (the freelance launcher resets it itself when it closes).
        .onDisappear { if TopupLauncher.shared.pending == nil { WalletCountry.setInternational(false) } }
        #if DEBUG
        // A debug route opened straight on an inner page: load the balance the home page would have.
        .task {
            guard DebugFlags.walletRoute != nil else { return }
            await host.refreshBalance()
            if let sheet = DebugFlags.sheet() { host.openSheet(sheet) }
        }
        #endif
    }
}

/// What every wallet visit wraps its pages in: the wallet palette, the host in the environment, the
/// page background, and the toast on top of everything (the PIN gate included).
struct WalletChrome<Content: View>: View {
    @ObservedObject var host: WalletHost
    @ViewBuilder let content: () -> Content

    var body: some View {
        ZStack(alignment: .top) {
            WalletBackground()
            content()
            WaToastHost(host: host)
        }
        .environmentObject(host)
        .environment(\.walletHost, host)
        .waThemed()
    }
}

private struct WalletBackground: View {
    @Environment(\.wa) private var wa

    var body: some View { wa.bg.ignoresSafeArea() }
}

/// The page stack: pages come in from the "next" edge of the reading direction and leave towards the other one.
@MainActor
struct WalletPagesFor: View {
    @ObservedObject var host: WalletHost

    var body: some View {
        ZStack {
            page(host.current)
                .id(host.stack.count)
                .transition(.asymmetric(
                    insertion: .modifier(active: PageShift(fraction: host.forward ? 1 : -1, opacity: 0), identity: PageShift(fraction: 0, opacity: 1))
                        .animation(.easeOut(duration: 0.34)),
                    removal: .modifier(active: PageShift(fraction: host.forward ? -0.6 : 0.6, opacity: 0), identity: PageShift(fraction: 0, opacity: 1))
                        .animation(.easeOut(duration: 0.22))
                ))
        }
    }

    @ViewBuilder
    private func page(_ route: WaRoute) -> some View {
        switch route {
        case .home: WalletHome()
        case .topup: WalletTopup()
        case .transfer: WalletTransfer()
        case .transferConfirm(let who): WalletTransferConfirm(who: who)
        case .myQr: WalletMyQr()
        case .scanner: WalletScanner()
        case .history: WalletHistory()
        case .pinSettings: WalletPinSettings()
        case .checkout(let state): WalletCheckout(state: state)
        case .topupAmount(let minor): WalletTopup(initialAmount: minorToInput(minor))
        }
    }
}

/// Slides a page by a sixth of the screen (towards the reading direction's "next" edge) while fading.
/// SwiftUI mirrors `offset(x:)` in right-to-left layouts itself, so Arabic slides the other way unaided.
private struct PageShift: ViewModifier {
    let fraction: CGFloat
    let opacity: Double

    func body(content: Content) -> some View {
        content
            .offset(x: fraction * UIScreen.main.bounds.width / 6)
            .opacity(opacity)
    }
}

/// A notification at the top, over everything (the PIN gate included), so it never hides the keypad or the buttons.
struct WaToastHost: View {
    @ObservedObject var host: WalletHost
    @Environment(\.wa) private var wa

    var body: some View {
        VStack {
            if let message = host.toast {
                HStack(spacing: 10) {
                    Image(systemName: host.toastError ? "exclamationmark.circle" : "checkmark.circle.fill")
                        .font(.system(size: 16, weight: .semibold))
                    Text(message).font(.dorr(13.5, .semibold)).lineSpacing(3).multilineTextAlignment(.leading)
                }
                .foregroundStyle(.white)
                .padding(.horizontal, 18)
                .padding(.vertical, 12)
                .background(host.toastError ? wa.danger : Color(hex: 0x111928), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .shadow(color: Color(hex: 0x111928).opacity(0.27), radius: 12, y: 4)
                .padding(.horizontal, 16)
                .padding(.top, 10)
                .transition(.move(edge: .top).combined(with: .opacity))
                .onTapGesture { host.toast = nil }
            }
            Spacer()
        }
        .animation(.easeOut(duration: 0.3), value: host.toast)
        .allowsHitTesting(host.toast != nil)
    }
}

// MARK: - The PIN gate

/// Asks for the PIN before the wallet opens. With a PIN it verifies it (and offers "forgot your PIN?");
/// without one it walks the person through the recovery-method choice and then creating the PIN.
@MainActor
struct WaGate: View {
    @EnvironmentObject private var network: NetworkMonitor
    let onUnlocked: () -> Void
    let onCancel: () -> Void

    @State private var status: PinStatusDto?
    @State private var failed: String?
    @State private var attempt = 0
    @State private var forgot = false
    /// The PIN typed at the gate when it turned out to be the reset value 0000: it has to be replaced first.
    @State private var resetPin: String?
    /// The PIN that verified fine but came from a device this wallet has never opened from before — kept
    /// only long enough to resend it as X-Wallet-Pin while proving the phone (wallet policy bend 3).
    @State private var untrustedDevicePin: String?
    @State private var autoPrompted = false

    var body: some View {
        content.task(id: "\(attempt)-\(network.reconnectTick)") { await load() }
    }

    @ViewBuilder
    private var content: some View {
        if failed == nil, let current = status, current.isFrozen {
            WaFrozenPage(status: current, onExit: onCancel, onLifted: { attempt += 1 })
        } else if failed == nil, let current = status, !current.hasPin {
            WaPinSetupPage(onExit: onCancel, onDone: onUnlocked, title: L("wa_gate_create_title"), showSaved: false)
        } else if let resetPin {
            WaForcePinChangePage(current: resetPin, onExit: onCancel, onDone: onUnlocked)
        } else if let untrustedDevicePin {
            WaDeviceTrustPage(pin: untrustedDevicePin, onExit: onCancel, onDone: onUnlocked)
        } else if forgot {
            WaForgotPinPage(status: status, onExit: { forgot = false }, onDone: { forgot = false; attempt += 1 })
        } else {
            WaPage(title: L("wa_my_wallet"), onBack: onCancel, scroll: false) {
                if let failed {
                    WaEmpty(icon: "exclamationmark.triangle.fill", title: L("wa_load_failed"), text: failed) {
                        WaButton(text: L("wa_retry"), style: .ghost, icon: "arrow.clockwise") { attempt += 1 }.padding(.horizontal, 60)
                    }
                    .frame(maxHeight: .infinity)
                } else if let current = status {
                    pad(current)
                } else {
                    WaSkeleton(cornerRadius: 24).frame(height: 300).padding(24).frame(maxHeight: .infinity)
                }
            }
        }
    }

    private func pad(_ current: PinStatusDto) -> some View {
        let lockedUntil = parseInstant(current.lockedUntil)
        return VStack(spacing: 0) {
            WaPinPad(title: L("wa_gate_enter_title"), sub: L("wa_gate_enter_sub"), lockedUntil: lockedUntil) { pin in
                await completeWithPin(pin)
            }
            .frame(maxHeight: .infinity)
            .waRise(0)
            if WaBiometric.isEnabled {
                WaButton(text: L("wa_biometric_unlock_button"), style: .ghost, icon: biometricIcon, action: unlockWithBiometric)
            }
            WaButton(text: L("wa_forgot_link"), style: .quiet) { forgot = true }.padding(.bottom, 6)
        }
        .padding(.horizontal, 8)
        .onAppear {
            // Opening the wallet shows the prompt at once (once per visit; a cancel leaves the PIN pad and the button).
            let lockedNow = (lockedUntil ?? .distantPast) > Date()
            if !autoPrompted && !lockedNow && WaBiometric.isEnabled {
                autoPrompted = true
                unlockWithBiometric()
            }
        }
    }

    private func load() async {
        failed = nil
        status = nil
        do {
            let loaded = try await WalletApi.pinStatus().data
            if loaded == nil { failed = L("wa_error_network") } else { status = loaded }
        } catch {
            let failure = error.apiFailure
            // Offline is covered by the app-wide screen and a reconnect reloads (skeleton meanwhile);
            // a real server answer gets the retry card.
            if failure.httpStatus != nil { failed = failure.message ?? L("wa_error_network") }
        }
    }

    private func completeWithPin(_ pin: String) async -> PadResult {
        let verified: PinVerifyDto?
        do {
            verified = try await WalletApi.verifyPin(pin).data
        } catch {
            let failure = error.apiFailure
            // Froze between loading this screen and typing the PIN: reload so the frozen branch takes over.
            if failure.errorCode == "wallet_pin_frozen" {
                attempt += 1
                return .reset
            }
            // A temporary lock: show the countdown, refuse any more attempts until it ends on its own —
            // never keep asking the server during the wait.
            if failure.errorCode == "wallet_pin_locked", let until = parseInstant(failure.lockedUntil) {
                return .locked(until)
            }
            return .error(failure.httpStatus == nil ? L("wa_error_network") : failure.message ?? L("wa_error_network"))
        }
        if verified?.mustChange == true {
            resetPin = pin
        } else if verified?.deviceTrusted == false {
            untrustedDevicePin = pin
        } else {
            onUnlocked()
        }
        return .ok
    }

    /// The stored PIN, opened by Face ID / Touch ID. A PIN that no longer matches the server's is dropped,
    /// rather than failing silently on every future attempt.
    private func unlockWithBiometric() {
        WaBiometric.unlock { pin in
            guard let pin else { return }
            Task {
                if case .error = await completeWithPin(pin) { WaBiometric.disable() }
            }
        }
    }
}

// MARK: - Sheets

/// Bottom sheets over the wallet pages: dim backdrop, a panel that slides up with a grab handle.
/// Tapping the backdrop closes it, exactly like the preview.
struct WaSheetHost: View {
    @ObservedObject var host: WalletHost
    @Environment(\.wa) private var wa

    var body: some View {
        GeometryReader { proxy in
            ZStack(alignment: .bottom) {
                if host.sheet != nil {
                    Color(hex: 0x111928).opacity(0.5)
                        .ignoresSafeArea()
                        .onTapGesture { host.closeSheet() }
                        .transition(.opacity)
                }
                if let sheet = host.sheet {
                    panel(sheet, available: proxy.size.height)
                        .transition(.move(edge: .bottom).combined(with: .opacity))
                }
            }
            .frame(width: proxy.size.width, height: proxy.size.height, alignment: .bottom)
        }
    }

    @ViewBuilder
    private func panel(_ sheet: WaSheet, available: CGFloat) -> some View {
        let isPin: Bool = { if case .pin = sheet { return true } else { return false } }()
        // Handle (5) + its gap (16) + the panel's own padding (10 top, 26 bottom).
        let chrome: CGFloat = 57
        // Android: the PIN sheet is 520 tall, the others at most 620 and scrolling; never above the screen.
        let room = available - 24
        VStack(spacing: 0) {
            Capsule().fill(wa.night ? wa.line : Color(hex: 0xE5E7EB)).frame(width: 42, height: 5)
            Spacer().frame(height: 16)
            if isPin {
                sheetContent(sheet).frame(height: min(520, room) - chrome)
            } else {
                // As tall as its content up to the cap; past it, the content scrolls.
                ViewThatFits(in: .vertical) {
                    sheetContent(sheet)
                    ScrollView(showsIndicators: false) { sheetContent(sheet) }
                }
                .frame(maxHeight: min(620, room) - chrome)
            }
        }
        .padding(.horizontal, 20)
        .padding(.top, 10)
        .padding(.bottom, 26)
        .frame(maxWidth: .infinity)
        .background(
            UnevenRoundedRectangleCompat(topRadius: 30).fill(wa.surface).ignoresSafeArea(edges: .bottom)
        )
    }

    @ViewBuilder
    private func sheetContent(_ sheet: WaSheet) -> some View {
        switch sheet {
        case .explain: ExplainSheet(host: host)
        case .tx(let tx): TxSheet(tx: tx, host: host)
        case .pin(let pin): WaPinSheetContent(sheet: pin, host: host)
        }
    }
}

private struct ExplainRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let tone: Tone
    let title: String
    let text: String

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            WaIconWell(icon: icon, tone: tone)
            VStack(alignment: .leading, spacing: 0) {
                Text(title).font(.dorr(14, .heavy)).foregroundStyle(wa.ink)
                Text(text).font(.dorr(12.5)).foregroundStyle(wa.mut).lineSpacing(5).fixedSize(horizontal: false, vertical: true)
            }
            Spacer(minLength: 0)
        }
        .padding(.vertical, 12)
    }
}

private struct ExplainSheet: View {
    @Environment(\.wa) private var wa
    let host: WalletHost

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            VStack(spacing: 10) {
                WaIconWell(icon: "wallet.pass.fill", size: 60, iconSize: 28)
                Text(L("wa_explain_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
            }
            .frame(maxWidth: .infinity)
            ExplainRow(icon: "building.columns.fill", tone: .green, title: L("wa_withdrawable"), text: L("wa_explain_withdrawable"))
            ExplainRow(icon: "gift.fill", tone: .pink, title: L("wa_spend_only"), text: L("wa_explain_spend_only"))
            ExplainRow(icon: "pause.circle.fill", tone: .amber, title: L("wa_held"), text: L("wa_explain_held"))
            WaButton(text: L("wa_understood")) { host.closeSheet() }.padding(.top, 8)
        }
    }
}

private struct TxSheet: View {
    @Environment(\.wa) private var wa
    let tx: WalletTransactionDto
    let host: WalletHost

    var body: some View {
        let credit = tx.direction == "credit"
        let spend = tx.bucket == "spend_only"
        let currency = host.balance?.currencyCode ?? ""
        VStack(alignment: .leading, spacing: 0) {
            VStack(spacing: 0) {
                // The brand-tinted well, like the rows of the list this sheet opens from.
                WaIconWell(icon: txMeta(tx.type).icon, size: 60, iconSize: 28)
                Text(tx.typeLabel).font(.dorr(18, .heavy)).foregroundStyle(wa.ink).multilineTextAlignment(.center).padding(.top, 10)
                Text(verbatim: "\(credit ? "+ " : "− ")\(money(tx.amountMinor)) \(currency)")
                    .font(.dorr(26, .heavy))
                    .foregroundStyle(credit ? wa.green : wa.danger)
                    .environment(\.layoutDirection, .leftToRight)
                    .padding(.top, 6)
            }
            .frame(maxWidth: .infinity)
            Spacer().frame(height: 14)
            WaKeyValue(label: L("wa_date"), value: "\(dayText(tx.createdAt)) · \(timeOf(tx.createdAt))", icon: "calendar")
            WaDivider()
            WaKeyValue(label: L("wa_bucket"), value: L(spend ? "wa_spend_only" : "wa_withdrawable"), icon: spend ? "gift.fill" : "building.columns.fill")
            WaDivider()
            WaKeyValue(label: L("wa_balance_after"), value: "\(money(tx.balanceAfterMinor)) \(currency)", ltr: true, icon: "wallet.pass.fill")
            if let party = tx.counterparty {
                WaDivider()
                // The masked phone is isolated so its dots and digits keep their order inside Arabic text.
                WaKeyValue(
                    label: L(credit ? "wa_from" : "wa_to"),
                    value: [party.name, party.phone.map { "\u{2066}\($0)\u{2069}" }].compactMap { $0 }.joined(separator: "  "),
                    icon: credit ? "arrow.down.left" : "arrow.up.right"
                )
            }
            if let note = tx.note, !note.trimmingCharacters(in: .whitespaces).isEmpty, note != tx.typeLabel {
                WaDivider()
                WaKeyValue(label: L("wa_note"), value: note, icon: "text.alignleft")
            }
            WaDivider()
            WaKeyValue(label: L("wa_tx_number"), value: String(tx.uuid.prefix(8)), valueColor: wa.soft, icon: "number")
            if spend && credit {
                WaNote(text: L("wa_spend_only_received_note")).padding(.top, 12)
            }
            WaButton(text: L("wa_close"), style: .ghost) { host.closeSheet() }.padding(.top, 14)
        }
    }
}

// MARK: - Other ways in

/// The PIN screen reached from Account → Settings → Wallet → Change PIN, outside a wallet visit: same page,
/// its own little host (so the toast and back handling work), leaving straight back to the settings menu.
@MainActor
struct WalletPinSettingsScreen: View {
    @StateObject private var host: WalletHost

    init(onBack: @escaping () -> Void) {
        _host = StateObject(wrappedValue: WalletHost(start: .pinSettings, onExit: onBack))
    }

    var body: some View {
        WalletChrome(host: host) { WalletPinSettings() }
    }
}
