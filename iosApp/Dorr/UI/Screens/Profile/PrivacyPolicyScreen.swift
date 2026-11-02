import SwiftUI

/// androidApp `profile/PrivacyPolicyScreen.kt` — `PrivacyPolicyScreen` / `TermsConditionsScreen`: the active
/// legal page as formatted HTML, with loading and retry states; leaving slides it out.
@MainActor
struct LegalContentScreen: View {
    enum Kind {
        case privacy, terms

        var type: String { self == .privacy ? "privacy" : "term" }
        var header: String { L(self == .privacy ? "account_privacy" : "account_terms") }
        var title: String { L(self == .privacy ? "privacy_head" : "terms_head") }
        var empty: String { L(self == .privacy ? "privacy_empty" : "terms_empty") }
        var icon: String { self == .privacy ? "shield.fill" : "doc.text.fill" }
    }

    @Environment(\.wa) private var wa
    let kind: Kind
    let onBack: () -> Void

    @State private var policy: LegalPageDto?
    @State private var isLoading = true
    @State private var errorMessage: String?
    @State private var isExiting = false

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: kind.header, onBack: handleBack)
                Group {
                    if isLoading {
                        VStack(spacing: 12) {
                            ProgressView().tint(wa.red).scaleEffect(1.2)
                            Text(L("content_loading")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                        }
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                    } else if errorMessage != nil {
                        VStack(spacing: 12) {
                            Text(L("content_error")).font(.dorr(15, .bold)).foregroundStyle(wa.night ? wa.ink : AppColors.textPrimary)
                            Button { Task { await load() } } label: {
                                HStack(spacing: 6) {
                                    Image(systemName: "arrow.clockwise").font(.system(size: 15, weight: .semibold))
                                    Text(L("content_retry")).font(.dorr(13.5, .bold))
                                }
                                .foregroundStyle(wa.red)
                                .padding(.horizontal, 16)
                                .padding(.vertical, 8)
                            }
                            .buttonStyle(.plain)
                        }
                        .padding(24)
                        .frame(maxWidth: .infinity)
                        .settingsSurface(18, elevation: 8)
                        .padding(.horizontal, 14)
                        .padding(.vertical, 16)
                        .frame(maxHeight: .infinity, alignment: .top)
                    } else {
                        ScrollView {
                            VStack(alignment: .leading, spacing: 0) {
                                HStack(spacing: 10) {
                                    PinkIcon(icon: kind.icon)
                                    Text(kind.title).font(.dorr(18, .heavy)).foregroundStyle(wa.red)
                                }
                                let content = policy?.content?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
                                Group {
                                    if content.isEmpty {
                                        Text(kind.empty).font(.dorr(13)).lineSpacing(10).foregroundStyle(wa.night ? wa.settingsMut : AppColors.textSecondary)
                                    } else {
                                        HtmlContent(html: content, textColor: wa.night ? wa.settingsMut : AppColors.textSecondary, accentColor: wa.red)
                                    }
                                }
                                .padding(.top, 12)
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
                .frame(maxHeight: .infinity)
            }
        }
        // Leaving: fade and slide 18 % towards the start over 260 ms, then close.
        .opacity(isExiting ? 0 : 1)
        .offset(x: isExiting ? -UIScreen.main.bounds.width * 0.18 : 0)
        .task { await load() }
    }

    private func handleBack() {
        guard !isExiting else { return }
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.26)) { isExiting = true }
        Task {
            try? await Task.sleep(nanoseconds: 260_000_000)
            onBack()
        }
    }

    private func load() async {
        isLoading = true
        errorMessage = nil
        do {
            policy = try await ContentApi.legalPage(type: kind.type).data
        } catch {
            errorMessage = error.localizedDescription
        }
        isLoading = false
    }
}
