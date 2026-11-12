import SwiftUI

/// freelanceApp `NoInternetScreen.kt` — full-screen and blocking while there is no connection: a pulsing
/// halo, the two tips, and "Try again" (which asks the system, then says so if still offline).
@MainActor
struct NoInternetScreen: View {
    @EnvironmentObject private var network: NetworkMonitor
    @Environment(\.wa) private var wa
    @State private var isChecking = false
    @State private var showStillOfflineMessage = false
    @State private var pulse = false

    var body: some View {
        let ink = wa.night ? wa.ink : AppColors.textPrimary
        ZStack {
            PinkBackdrop()
            ScrollView {
                VStack(spacing: 0) {
                    ZStack {
                        Circle()
                            .fill(RadialGradient(colors: [wa.red.opacity(pulse ? 0.35 : 0.15), wa.red.opacity(0)], center: .center, startRadius: 0, endRadius: 65))
                            .frame(width: 130, height: 130)
                            .scaleEffect(pulse ? 1.12 : 1)
                        Image(systemName: "wifi.slash").font(.system(size: 40, weight: .semibold)).foregroundStyle(wa.red)
                            .frame(width: 96, height: 96)
                            .background(wa.night ? Color(hex: 0x3B1E22) : Color(hex: 0xFDE8E8), in: Circle())
                            .overlay(Circle().stroke(wa.red.opacity(0.3), lineWidth: 2))
                            .accessibilityLabel(L("offline_title"))
                    }
                    .frame(width: 140, height: 140)
                    .onAppear { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 1.4).repeatForever(autoreverses: true)) { pulse = true } }

                    Text(L("offline_title"))
                        .font(.dorr(22, .bold)).foregroundStyle(ink).multilineTextAlignment(.center).padding(.top, 24)
                    Text(L("offline_body"))
                        .font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).lineSpacing(5)
                        .padding(.horizontal, 12).padding(.top, 10)

                    VStack(spacing: 14) {
                        TipItem(icon: "wifi", title: L("offline_tip_network_title"), desc: L("offline_tip_network_body"))
                        TipItem(icon: "airplane", title: L("offline_tip_airplane_title"), desc: L("offline_tip_airplane_body"))
                    }
                    .padding(16)
                    .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.night ? wa.line : AppColors.border, lineWidth: 1))
                    .padding(.top, 28)

                    if showStillOfflineMessage {
                        Text(L("offline_still"))
                            .font(.dorr(12, .medium))
                            .foregroundStyle(wa.night ? wa.darkAccent : Color(hex: 0xB91C1C))
                            .multilineTextAlignment(.center)
                            .padding(.horizontal, 14)
                            .padding(.vertical, 10)
                            .frame(maxWidth: .infinity)
                            .background(wa.night ? wa.darkWell : Color(hex: 0xFFEBEB), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                            .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(wa.night ? wa.darkAccent : Color(hex: 0xF87171), lineWidth: 1))
                            .padding(.top, 24)
                            .transition(.opacity)
                    }

                    Button(action: retry) {
                        HStack(spacing: isChecking ? 10 : 8) {
                            if isChecking {
                                ProgressView().tint(.white)
                                Text(L("offline_checking")).font(.dorr(16, .bold))
                            } else {
                                Image(systemName: "arrow.clockwise").font(.system(size: 17, weight: .semibold))
                                Text(L("common_retry")).font(.dorr(16, .bold))
                            }
                        }
                        .foregroundStyle(.white)
                        .frame(maxWidth: .infinity, minHeight: 52)
                        .background(wa.red.opacity(isChecking ? 0.85 : 1), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        .shadow(color: .black.opacity(0.12), radius: 2, y: 1)
                    }
                    .buttonStyle(.plain)
                    .padding(.top, showStillOfflineMessage ? 12 : 24)
                }
                .frame(maxWidth: 440)
                .padding(.horizontal, 24)
                .padding(.vertical, 32)
                .frame(maxWidth: .infinity)
            }
        }
        // Intercept all touches so nothing underneath receives them.
        .contentShape(Rectangle())
        .animation(.easeOut(duration: 0.2), value: showStillOfflineMessage)
    }

    private func retry() {
        guard !isChecking else { return }
        isChecking = true
        showStillOfflineMessage = false
        Task {
            try? await Task.sleep(nanoseconds: 400_000_000) // brief touch feedback
            let online = network.refresh()
            isChecking = false
            if !online { showStillOfflineMessage = true }
        }
    }
}

private struct TipItem: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let desc: String

    var body: some View {
        HStack(spacing: 14) {
            Image(systemName: icon).font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.accent)
                .frame(width: 40, height: 40)
                .background(wa.night ? wa.darkWell : Color(hex: 0xF3F4F6), in: Circle())
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: title).font(.dorr(14, .semibold)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary)
                Text(verbatim: desc).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            Spacer(minLength: 0)
        }
    }
}
