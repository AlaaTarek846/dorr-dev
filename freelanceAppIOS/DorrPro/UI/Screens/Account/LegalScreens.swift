import SwiftUI

// freelanceApp `LegalScreens.kt` — the privacy policy and the terms, the Freelance service's legal pages
// (`SupportApi.legalPage`: public; the server falls back to the general page). `HtmlContent` is in Kit.

private let legalExitAnimMs: UInt64 = 260

struct PrivacyPolicyScreen: View {
    let onBack: () -> Void

    var body: some View {
        LegalContentScreen(legalType: "privacy", headerKey: "account_privacy", titleKey: "privacy_head", emptyKey: "privacy_empty",
                           icon: "shield.fill", onBack: onBack)
    }
}

struct TermsConditionsScreen: View {
    let onBack: () -> Void

    var body: some View {
        LegalContentScreen(legalType: "term", headerKey: "account_terms", titleKey: "terms_head", emptyKey: "terms_empty",
                           icon: "doc.text.fill", onBack: onBack)
    }
}

@MainActor
private struct LegalContentScreen: View {
    @Environment(\.wa) private var wa
    let legalType: String
    let headerKey: String
    let titleKey: String
    let emptyKey: String
    let icon: String
    let onBack: () -> Void

    @State private var policy: LegalPageDto?
    @State private var isLoading = true
    @State private var errorMessage: String?
    @State private var isExiting = false

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L(headerKey), onBack: handleBack)
                content.frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        // Leaving: a short fade and slide before the page is closed.
        .opacity(isExiting ? 0 : 1)
        .offset(x: isExiting ? -UIScreen.main.bounds.width * 0.18 : 0)
        .animation(.timingCurve(0.4, 0, 0.2, 1, duration: Double(legalExitAnimMs) / 1000), value: isExiting)
        .navigationBarHidden(true)
        .task { await loadPolicy() }
    }

    @ViewBuilder private var content: some View {
        if isLoading {
            VStack(spacing: 12) {
                SkeletonLines().frame(maxWidth: UIScreen.main.bounds.width * 0.9 - 32)
                Text(L("content_loading")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
            }
            .padding(16)
        } else if errorMessage != nil {
            VStack {
                VStack(spacing: 12) {
                    Text(L("content_error")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                    Button { Task { await loadPolicy() } } label: {
                        HStack(spacing: 6) {
                            Image(systemName: "arrow.clockwise").font(.system(size: 15, weight: .semibold))
                            Text(L("content_retry")).font(.dorr(13.5, .bold))
                        }
                        .foregroundStyle(wa.red)
                        .padding(.horizontal, 16).padding(.vertical, 8)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
                .padding(24)
                .frame(maxWidth: .infinity)
                .settingsSurface(18, elevation: 8)
                .padding(.horizontal, 14).padding(.vertical, 16)
                Spacer(minLength: 0)
            }
        } else {
            let raw = policy?.content?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
            let textColor = wa.night ? wa.settingsMut : AppColors.textSecondary
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    HStack(spacing: 10) {
                        PinkIcon(icon: icon)
                        Text(L(titleKey)).font(.dorr(18, .heavy)).foregroundStyle(wa.red)
                    }
                    if raw.isEmpty {
                        Text(L(emptyKey)).font(.dorr(13)).foregroundStyle(textColor).lineSpacing(8)
                    } else {
                        HtmlContent(html: raw, textColor: textColor, accentColor: wa.red)
                    }
                }
                .padding(16)
                .frame(maxWidth: .infinity, alignment: .leading)
                .settingsSurface(18, elevation: 8)
                .padding(.horizontal, 14)
                .padding(.top, 6)
                .padding(.bottom, 24)
            }
        }
    }

    private func handleBack() {
        if isExiting { return }
        isExiting = true
        Task {
            try? await Task.sleep(nanoseconds: legalExitAnimMs * 1_000_000)
            onBack()
        }
    }

    private func loadPolicy() async {
        isLoading = true
        errorMessage = nil
        let result = await SupportApi.legalPage(legalType)
        switch result {
        case .ok(let page, _): policy = page
        // A 200 with `data: null` is "no page yet" (the empty text), not an error.
        case .failed(let e) where e.httpStatus == 200: policy = nil
        case .failed(let e): errorMessage = e.displayMessage
        case .offline: errorMessage = L("login_error_offline")
        }
        isLoading = false
    }
}
