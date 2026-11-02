import SwiftUI

// freelanceApp `ui/screens/FaqSheet.kt` — the FAQs (`GET freelance/v1/faqs`) in a bottom panel: a search box, then each
// question as a card that opens on its answer.
//
// Present it like the Contact panel: `.dorrDialog(isPresented: $showFaq, bottom: true) { FaqSheet(onDismiss: { showFaq = false }) }`.

@MainActor
struct FaqSheet: View {
    @Environment(\.wa) private var wa
    let onDismiss: () -> Void

    @State private var faqs: [FaqDto]?
    @State private var isLoading = true
    @State private var errorMessage: String?
    @State private var searchQuery = ""
    @State private var openIds: Set<Int> = []

    private var filteredFaqs: [FaqDto] {
        let list = faqs ?? []
        guard !searchQuery.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return list }
        let q = searchQuery.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return list.filter { ($0.question ?? "").lowercased().contains(q) || ($0.answer ?? "").lowercased().contains(q) }
    }

    var body: some View {
        SupportBottomPanel(maxHeight: 620 + supportSafeBottom) {
            VStack(spacing: 0) {
                // Header
                HStack(spacing: 10) {
                    PinkIcon(icon: "questionmark.circle.fill")
                    SettingsScreenTitle(title: L("faq_title"))
                    Spacer(minLength: 0)
                    Button(action: onDismiss) {
                        Image(systemName: "xmark").font(.system(size: 12, weight: .bold)).foregroundStyle(wa.settingsMut)
                            .frame(width: 28, height: 28)
                            .background(wa.settingsInk.opacity(0.06), in: Circle())
                    }
                    .buttonStyle(.plain)
                }
                .padding(.bottom, 12)

                // Search bar (if not loading and not empty)
                if !isLoading && errorMessage == nil && !(faqs ?? []).isEmpty {
                    HStack(spacing: 8) {
                        Image(systemName: "magnifyingglass").font(.system(size: 15)).foregroundStyle(wa.settingsMut)
                        ZStack(alignment: .leading) {
                            if searchQuery.isEmpty {
                                Text(L("faq_search_hint")).font(.dorr(13.5)).foregroundStyle(wa.settingsMut.opacity(0.7))
                            }
                            TextField("", text: $searchQuery).font(.dorr(13.5)).foregroundStyle(wa.settingsInk).tint(wa.red)
                        }
                        if !searchQuery.isEmpty {
                            Button { searchQuery = "" } label: {
                                Image(systemName: "xmark").font(.system(size: 12, weight: .bold)).foregroundStyle(wa.settingsMut)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .padding(.horizontal, 12)
                    .padding(.vertical, 10)
                    .settingsSurface(12)
                    .padding(.bottom, 12)
                }

                // Content area
                Group {
                    if isLoading {
                        VStack(spacing: 10) {
                            SkeletonLines(count: 4).padding(.horizontal, 16)
                            Text(L("content_loading")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                        }
                        .frame(maxWidth: .infinity, minHeight: 180)
                    } else if errorMessage != nil {
                        VStack(spacing: 10) {
                            Text(L("content_error")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                            Button(action: loadFaqs) {
                                HStack(spacing: 6) {
                                    Image(systemName: "arrow.clockwise").font(.system(size: 14, weight: .semibold))
                                    Text(L("content_retry")).font(.dorr(13, .bold))
                                }
                                .foregroundStyle(wa.red)
                                .padding(.horizontal, 14)
                                .padding(.vertical, 6)
                            }
                            .buttonStyle(.plain)
                        }
                        .padding(20)
                        .frame(maxWidth: .infinity)
                        .settingsSurface(14)
                        .padding(.vertical, 16)
                    } else if filteredFaqs.isEmpty {
                        Text(L(searchQuery.isEmpty ? "faq_empty" : "faq_empty_search")).font(.dorr(13.5)).foregroundStyle(wa.settingsMut)
                            .frame(maxWidth: .infinity, minHeight: 140)
                    } else {
                        ScrollView {
                            VStack(spacing: 8) {
                                ForEach(filteredFaqs, id: \.id) { faq in
                                    let id = faq.id ?? 0
                                    FaqRow(item: faq, isOpen: openIds.contains(id)) {
                                        withAnimation(.easeInOut(duration: 0.25)) {
                                            if openIds.contains(id) { openIds.remove(id) } else { openIds.insert(id) }
                                        }
                                    }
                                }
                            }
                            .padding(.vertical, 6)
                        }
                    }
                }
            }
            .padding(.horizontal, 14)
            .padding(.top, 18)
            .padding(.bottom, 24 + supportSafeBottom)
        }
        .task { await load() }
    }

    private func loadFaqs() { Task { await load() } }

    private func load() async {
        isLoading = true
        errorMessage = nil
        do {
            faqs = try await SupportClient.faqs().data ?? []
        } catch {
            errorMessage = error.apiFailure().message ?? L("content_error")
        }
        isLoading = false
    }
}

private struct FaqRow: View {
    @Environment(\.wa) private var wa
    let item: FaqDto
    let isOpen: Bool
    let onToggle: () -> Void

    var body: some View {
        Button(action: onToggle) {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    PinkIcon(icon: "questionmark.circle.fill")
                    Text(verbatim: item.question ?? "").font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                        .multilineTextAlignment(.leading).lineSpacing(3)
                    Spacer(minLength: 0)
                    Image(systemName: "chevron.down").font(.system(size: 14, weight: .bold))
                        .foregroundStyle(wa.night ? Color(hex: 0x8B93A0) : Color(hex: 0xF8BCA9))
                        .rotationEffect(.degrees(isOpen ? 180 : 0))
                }
                if isOpen {
                    Text(verbatim: item.answer ?? "").font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(5)
                        .multilineTextAlignment(.leading)
                        .padding(.top, 10)
                        .padding(.leading, 44)
                        .transition(.opacity.combined(with: .move(edge: .top)))
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .settingsSurface(14)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}
