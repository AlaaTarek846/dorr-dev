import SwiftUI

// freelanceApp `SecurityScreen.kt` — Settings → Security (spec items 28–29), through SupportApi's security calls.

/// A security log answer has another page when it came full (SupportClient's `list(20)`).
private let eventsPerPage = 20

private let warningRed = Color(hex: 0xD93A3A)
private let safeGreen = Color(hex: 0x1F9D55)

/**
 * Settings → Security: the devices this account is signed in on — sign any other one out, or all of them at once — and
 * the account's security log (sign-ins, wrong codes, devices signed out, wallet PIN locks), warnings marked in red.
 */
@MainActor
struct SecurityScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void

    @State private var devices: [SecurityDeviceDto] = []
    @State private var events: [SecurityEventDto] = []
    @State private var loadingDevices = true
    @State private var loadingEvents = true
    @State private var failed = false
    @State private var page = 1
    @State private var hasMore = false
    @State private var warningsOnly = false
    @State private var busy = false
    @State private var confirmDevice: SecurityDeviceDto?
    @State private var confirmOthers = false

    private struct EventsKey: Equatable { let tick: Int; let warnings: Bool }

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("security_title"), onBack: onBack)
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 10) { rows }
                        .padding(.horizontal, 14)
                        .padding(.top, 6)
                        .padding(.bottom, 24)
                }
            }
        }
        .navigationBarHidden(true)
        .task(id: network.reconnectTick) { await loadDevices() }
        .task(id: EventsKey(tick: network.reconnectTick, warnings: warningsOnly)) { await loadEvents(1) }
        .dorrDialog(isPresented: Binding(get: { confirmDevice != nil }, set: { if !$0 { confirmDevice = nil } })) {
            if let device = confirmDevice {
                ConfirmDialog(icon: "rectangle.portrait.and.arrow.right", title: L("security_sign_out"),
                              message: L("security_sign_out_confirm", device.deviceName ?? ""), loading: busy,
                              confirmLabel: L("security_sign_out"), onConfirm: { signOut(device) },
                              onDismiss: { if !busy { confirmDevice = nil } })
            }
        }
        .dorrDialog(isPresented: $confirmOthers) {
            ConfirmDialog(icon: "rectangle.portrait.and.arrow.right", title: L("security_sign_out_others"),
                          message: L("security_sign_out_others_confirm"), loading: busy,
                          confirmLabel: L("security_sign_out"), onConfirm: signOutOthers,
                          onDismiss: { if !busy { confirmOthers = false } })
        }
    }

    @ViewBuilder private var rows: some View {
        // ---------------------------------------------------------------- devices
        SectionTitle(text: L("security_devices"), icon: "laptopcomputer.and.iphone")

        if loadingDevices && devices.isEmpty {
            SkeletonList(count: 2)
        } else if failed && devices.isEmpty {
            Hint(text: L("security_load_failed"))
        } else {
            VStack(spacing: 0) {
                ForEach(Array(devices.enumerated()), id: \.element.id) { index, device in
                    DeviceRow(device: device, enabled: !busy) { confirmDevice = device }
                    if index != devices.count - 1 { Rectangle().fill(wa.night ? wa.line : Color(hex: 0xF6E4E8)).frame(height: 1) }
                }
            }
            .frame(maxWidth: .infinity)
            .settingsSurface(18, elevation: 6)
            if devices.contains(where: { $0.current != true }) {
                SettingsPrimaryButton(text: L("security_sign_out_others"), enabled: !busy, loading: busy && confirmOthers) { confirmOthers = true }
            }
        }

        // ---------------------------------------------------------------- security log
        HStack(spacing: 0) {
            SectionTitle(text: L("security_log"), icon: "shield.fill").frame(maxWidth: .infinity, alignment: .leading)
            FilterChip(text: L("security_all"), selected: !warningsOnly) { warningsOnly = false }
            Spacer().frame(width: 6)
            FilterChip(text: L("security_warnings_only"), selected: warningsOnly) { warningsOnly = true }
        }
        .padding(.top, 8)

        if loadingEvents && events.isEmpty {
            SkeletonList(count: 2)
        } else if events.isEmpty {
            Hint(text: L("security_log_empty"))
        } else {
            ForEach(events) { EventRow(event: $0) }
            if hasMore {
                Group {
                    if loadingEvents {
                        ProgressView().tint(wa.red).frame(width: 22, height: 22)
                    } else {
                        Button { Task { await loadEvents(page + 1) } } label: {
                            Text(L("security_load_more")).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                                .padding(.horizontal, 16).padding(.vertical, 8).contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                }
                .frame(maxWidth: .infinity)
                .padding(.top, 4)
            }
        }
    }

    private func loadDevices() async {
        loadingDevices = true
        if let rows = await SupportApi.securityDevices().value {
            devices = rows
            failed = false
        } else {
            failed = true
        }
        loadingDevices = false
    }

    private func loadEvents(_ next: Int) async {
        loadingEvents = true
        let warnings = warningsOnly
        if let rows = await SupportApi.securityEvents(page: next, warnings: warnings ? 1 : nil).value {
            if next == 1 { events.removeAll() }
            events.append(contentsOf: rows)
            page = next
            hasMore = rows.count >= eventsPerPage
        } else if next == 1 {
            failed = true
        }
        loadingEvents = false
    }

    private func signOut(_ device: SecurityDeviceDto) {
        busy = true
        Task {
            let result = await SupportApi.signOutDevice(device.id ?? 0)
            switch result {
            case .ok(let res, let message):
                devices = res.devices ?? []
                let text = (message ?? "").trimmingCharacters(in: .whitespaces)
                AppToast.show(text.isEmpty ? L("security_signed_out") : text)
                await loadEvents(1)
            default:
                AppToast.show(result.error?.displayMessage ?? L("security_load_failed"), long: true)
            }
            busy = false
            confirmDevice = nil
        }
    }

    private func signOutOthers() {
        busy = true
        Task {
            let result = await SupportApi.signOutOtherDevices()
            switch result {
            case .ok(let res, let message):
                devices = res.devices ?? []
                AppToast.show(message ?? "")
                await loadEvents(1)
            default:
                AppToast.show(result.error?.displayMessage ?? L("security_load_failed"), long: true)
            }
            busy = false
            confirmOthers = false
        }
    }
}

private struct DeviceRow: View {
    @Environment(\.wa) private var wa
    let device: SecurityDeviceDto
    let enabled: Bool
    let onSignOut: () -> Void

    var body: some View {
        HStack(spacing: 10) {
            PinkIcon(icon: platformIcon(device.platform))
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 6) {
                    Text(verbatim: device.deviceName ?? "").font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    if device.current == true {
                        Text(L("security_this_device")).font(.dorr(10, .bold)).foregroundStyle(safeGreen)
                            .padding(.horizontal, 6).padding(.vertical, 2)
                            .background(safeGreen.opacity(0.12), in: RoundedRectangle(cornerRadius: 8, style: .continuous))
                    }
                }
                Text(verbatim: [device.platformLabel, device.appVersion.map { "v\($0)" }, device.countryCode].compactMap { $0 }.joined(separator: " · "))
                    .font(.dorr(11)).foregroundStyle(wa.settingsMut).padding(.top, 2)
                Text(L("security_last_active", formatWhen(device.lastActiveAt))).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if device.current != true {
                Button(action: onSignOut) {
                    Text(L("security_sign_out")).font(.dorr(12, .bold)).foregroundStyle(warningRed)
                        .padding(.horizontal, 10).padding(.vertical, 6)
                        .overlay(RoundedRectangle(cornerRadius: 10, style: .continuous).stroke(warningRed.opacity(0.35), lineWidth: 1))
                        .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .disabled(!enabled)
            }
        }
        .padding(12)
    }
}

private struct EventRow: View {
    @Environment(\.wa) private var wa
    let event: SecurityEventDto

    var body: some View {
        let warning = event.warning == true
        let tint = warning ? warningRed : safeGreen
        HStack(spacing: 0) {
            Image(systemName: warning ? "exclamationmark.triangle.fill" : "checkmark.shield.fill")
                .font(.system(size: 16, weight: .semibold)).foregroundStyle(tint)
                .frame(width: 34, height: 34)
                .background(tint.opacity(0.12), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: event.label ?? "").font(.dorr(13, .bold)).foregroundStyle(warning ? tint : wa.settingsInk)
                if let description = event.description { Text(verbatim: description).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
                Text(verbatim: [event.deviceName, event.ip, event.countryCode].compactMap { $0 }.joined(separator: " · "))
                    .font(.dorr(10.5)).foregroundStyle(wa.settingsMut).lineLimit(1)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.leading, 10)
            Text(verbatim: formatWhen(event.createdAt)).font(.dorr(10.5)).foregroundStyle(wa.settingsMut).padding(.leading, 8)
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 10)
        .settingsSurface(14, elevation: 3)
    }
}

private struct SectionTitle: View {
    @Environment(\.wa) private var wa
    let text: String
    let icon: String

    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red)
            Text(verbatim: text).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
        }
        .padding(.horizontal, 4)
    }
}

private struct FilterChip: View {
    @Environment(\.wa) private var wa
    let text: String
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(11.5, .bold)).foregroundStyle(selected ? .white : wa.settingsMut)
                .padding(.horizontal, 10).padding(.vertical, 5)
                .background(selected ? wa.red : wa.settingsCard, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

private struct Hint: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        Text(verbatim: text).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.vertical, 18).padding(.horizontal, 8)
    }
}

private func platformIcon(_ platform: String?) -> String {
    switch platform {
    case "android": return "candybarphone"
    case "ios": return "iphone"
    case "web": return "desktopcomputer"
    default: return "laptopcomputer.and.iphone"
    }
}

/// "12/10 14:05" in the phone's own time zone.
private func formatWhen(_ iso: String?) -> String {
    guard let iso else { return "—" }
    guard let date = parseInstant(iso) else { return iso }
    let formatter = DateFormatter()
    formatter.locale = Locale(identifier: "en_US_POSIX")
    formatter.dateFormat = "d/M HH:mm"
    return formatter.string(from: date)
}
