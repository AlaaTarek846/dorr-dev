import SwiftUI
import UIKit

// freelanceApp `ui/screens/InviteFriendsScreen.kt` — Account > Share the app with friends: three steps, the freelancer's own
// invite code (`GET freelance/v1/referrals/my-code`, tap to copy) and a share button.

@MainActor
struct InviteFriendsScreen: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void

    @State private var code = ""
    @State private var loading = true
    @State private var loadError = false
    @State private var reloadKey = 0

    var body: some View {
        // The store link carries the code (Play's referrer). The app has no App Store id yet, so the same link is shared.
        let blank = code.trimmingCharacters(in: .whitespaces).isEmpty
        let storeUrl = "https://play.google.com/store/apps/details?id=com.dorr.freelance" + (blank ? "" : "&referrer=\(code)")
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("invite_title"), onBack: onBack)
                ScrollView {
                    VStack(spacing: 0) {
                        InviteHero()
                        Text(L("invite_headline")).font(.dorr(22, .heavy)).foregroundStyle(wa.settingsInk)
                            .multilineTextAlignment(.center).lineSpacing(4).padding(.horizontal, 8)
                        VStack(spacing: 0) {
                            InviteStep(icon: "square.and.arrow.up.fill", title: L("invite_step1_title"), text: L("invite_step1_body"))
                            InviteStep(icon: "person.badge.plus", title: L("invite_step2_title"), text: L("invite_step2_body"))
                            InviteStep(icon: "gift.fill", title: L("invite_step3_title"), text: L("invite_step3_body"))
                        }
                        .padding(.top, 16)
                        Group {
                            if loading {
                                SkeletonBlock(height: 56, radius: 18)
                            } else if loadError || blank {
                                VStack(spacing: 10) {
                                    Text(L("invite_code_failed")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                                        .multilineTextAlignment(.center).padding(.horizontal, 12)
                                    SettingsPrimaryButton(text: L("invite_code_retry")) { reloadKey += 1 }
                                }
                            } else {
                                InviteCodeButton(code: code) {
                                    UIPasteboard.general.string = code
                                    AppToast.show(L("invite_copied"))
                                }
                            }
                        }
                        .padding(.top, 14)
                        SettingsPrimaryButton(text: L("invite_share_now"), enabled: !blank) {
                            inviteShare(L("invite_share_body", code, storeUrl))
                        }
                        .padding(.top, 14)
                    }
                    .padding(.horizontal, 14)
                    .padding(.bottom, 24)
                }
            }
        }
        .task(id: reloadKey) {
            loading = true
            loadError = false
            do {
                code = try await SupportClient.myReferralCode().data?.code ?? ""
                loadError = code.trimmingCharacters(in: .whitespaces).isEmpty
            } catch {
                loadError = true
            }
            loading = false
        }
    }
}

/// The system share sheet with a text (Android's ACTION_SEND chooser).
private func inviteShare(_ text: String) {
    let scene = UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }.first { $0.activationState == .foregroundActive }
    guard var top = scene?.keyWindow?.rootViewController else { return }
    while let presented = top.presentedViewController { top = presented }
    let sheet = UIActivityViewController(activityItems: [text], applicationActivities: nil)
    sheet.popoverPresentationController?.sourceView = top.view
    top.present(sheet, animated: true)
}

private struct InviteCodeButton: View {
    @Environment(\.wa) private var wa
    let code: String
    let onCopy: () -> Void
    @State private var copied = false

    var body: some View {
        Button {
            onCopy()
            copied = true
        } label: {
            HStack(spacing: 12) {
                PinkIcon(icon: "square.and.arrow.up.fill", box: 40)
                VStack(alignment: .leading, spacing: 2) {
                    Text(L("invite_code_label")).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: code).font(.dorr(18, .heavy)).kerning(0.4).foregroundStyle(wa.settingsInk).lineLimit(1)
                        .environment(\.layoutDirection, .leftToRight)
                }
                Spacer(minLength: 8)
                Image(systemName: copied ? "checkmark" : "doc.on.doc").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 40, height: 40)
                    .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: Circle())
                    .accessibilityLabel(L("invite_copy"))
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 12)
            .settingsSurface(18)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        // The tick goes back to the copy icon after 1.6 s (the device clock).
        .task(id: copied) {
            guard copied else { return }
            try? await Task.sleep(nanoseconds: 1_600_000_000)
            copied = false
        }
    }
}

/// Two phones and a heart above them.
private struct InviteHero: View {
    @Environment(\.wa) private var wa

    var body: some View {
        ZStack {
            PhoneBadge(tint: wa.red.opacity(0.72)).offset(x: -22, y: 6)
            PhoneBadge(tint: wa.red).offset(x: 22, y: 6)
            Image(systemName: "heart.fill").font(.system(size: 13)).foregroundStyle(wa.red)
                .frame(width: 32, height: 32)
                .background(wa.night ? wa.darkWell : wa.red.opacity(0.16), in: Circle())
                .offset(y: -22)
        }
        .frame(maxWidth: .infinity)
        .frame(height: 118)
    }
}

private struct PhoneBadge: View {
    let tint: Color

    var body: some View {
        Image(systemName: "iphone").font(.system(size: 24, weight: .semibold)).foregroundStyle(.white)
            .frame(width: 56, height: 76)
            .background(tint, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(Color.white.opacity(0.28), lineWidth: 2))
            .shadow(color: tint.opacity(0.22), radius: 6, y: 2)
    }
}

private struct InviteStep: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let text: String

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            PinkIcon(icon: icon, box: 36)
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: title).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                Text(verbatim: text).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2)
            }
            Spacer(minLength: 0)
        }
        .padding(.vertical, 10)
    }
}
