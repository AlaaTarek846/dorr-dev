import SwiftUI

/// freelanceApp `TermsScreen.kt` — the terms of use, shown once until accepted (`terms.accepted`): nothing else opens
/// before the freelancer agrees. Back does not skip them.
@MainActor
struct TermsScreen: View {
    @Environment(\.wa) private var wa
    let version: String
    let onAccepted: () -> Void
    @State private var loading = false
    @State private var error: String?

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                PinkIcon(icon: "hammer", box: 48)
                Text(L("terms_title")).font(.dorr(20, .heavy)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center).padding(.top, 14)
                Text(L("terms_body", version)).font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).lineSpacing(6).padding(.top, 8)
                SettingsPrimaryButton(text: L("terms_accept"), loading: loading, action: accept).padding(.top, 20)
            }
            .padding(20)
            .frame(maxWidth: 440)
            .settingsSurface(24)
            .padding(24)
        }
        .navigationBarHidden(true)
        .interactiveDismissDisabled()
        .messageToast(error)
    }

    private func accept() {
        loading = true
        error = nil
        Task {
            let result = await ProfileApi.acceptTerms(version)
            loading = false
            if case .ok(let me, _) = result {
                Session.shared.me = me
                onAccepted()
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}
