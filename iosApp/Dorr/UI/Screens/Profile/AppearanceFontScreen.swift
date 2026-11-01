import SwiftUI

// androidApp `profile/AppearanceFontScreen.kt`.

private let fontPageSize = 15

@MainActor
struct AppearanceFontScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var appearance: AppearanceState
    @EnvironmentObject private var session: AuthSession
    let onBack: () -> Void

    @State private var query = ""
    @State private var debouncedQuery = ""
    @State private var chunkPage = 1
    @State private var loading = true
    @State private var savingId: Int?

    private var snap: AppearanceDto? { appearance.snapshot }
    private var selectedId: Int? { snap?.mobileAppFontId ?? snap?.font?.id }

    private var allFonts: [MobileAppFontDto] {
        let fromList = (snap?.availableFonts ?? []).filter(\.status).sorted { $0.sortOrder < $1.sortOrder }
        if !fromList.isEmpty { return fromList }
        return snap?.font.map { [$0] } ?? []
    }

    private var filtered: [MobileAppFontDto] {
        let q = debouncedQuery.trimmingCharacters(in: .whitespaces)
        guard !q.isEmpty else { return allFonts }
        return allFonts.filter { ($0.name ?? "").localizedCaseInsensitiveContains(q) || ($0.slug ?? "").localizedCaseInsensitiveContains(q) }
    }

    private enum ListState { case loading, empty, content }

    var body: some View {
        let visible = Array(filtered.prefix(chunkPage * fontPageSize))
        let hasMore = filtered.count > visible.count
        let state: ListState = loading && allFonts.isEmpty ? .loading : (visible.isEmpty ? .empty : .content)
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                FontScreenHeader(title: L("appearance_font_choose"), onBack: onBack).padding(.top, 16)
                FontSearchPill(query: $query, placeholder: L("appearance_font_search")).padding(.top, 16)
                ZStack {
                    switch state {
                    case .loading:
                        ProgressView().tint(wa.red).scaleEffect(1.2).frame(maxWidth: .infinity, maxHeight: .infinity)
                            .transition(.opacity)
                    case .empty:
                        VStack(spacing: 12) {
                            Text(L("appearance_font_empty")).font(.dorr(14)).foregroundStyle(wa.settingsMut)
                            if !debouncedQuery.trimmingCharacters(in: .whitespaces).isEmpty {
                                Button { query = "" } label: {
                                    Text(L("addr_clear_search")).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                        .transition(.opacity)
                    case .content:
                        ScrollView {
                            LazyVStack(spacing: 10) {
                                ForEach(visible) { font in
                                    FontOptionRow(font: font, selected: font.id == selectedId, busy: savingId == font.id) { select(font) }
                                }
                                if hasMore {
                                    FontShowMoreButton { chunkPage += 1 }
                                }
                            }
                            .padding(.vertical, 4)
                        }
                        .transition(.opacity)
                    }
                }
                .animation(.easeInOut(duration: 0.26), value: state)
                .padding(.top, 16)
                Spacer().frame(height: 14)
            }
            .padding(.horizontal, 20)
        }
        .task(id: query) {
            try? await Task.sleep(nanoseconds: 400_000_000)
            guard !Task.isCancelled else { return }
            debouncedQuery = query
            chunkPage = 1
        }
        .task { await loadIfNeeded() }
    }

    private func loadIfNeeded() async {
        loading = true
        defer { loading = false }
        guard let token = session.token, !token.isEmpty else { return }
        guard (snap?.availableFonts ?? []).isEmpty else { return }
        if let dto = try? await AppearanceApi.show().data, session.token == token {
            appearance.apply(dto)
        }
    }

    private func select(_ font: MobileAppFontDto) {
        guard font.id != selectedId, savingId == nil, let token = session.token, !token.isEmpty else { return }
        savingId = font.id
        Task {
            do {
                if let dto = try await AppearanceApi.update(AppearanceUpdateRequest(mobileAppFontId: font.id)).data, session.token == token {
                    appearance.apply(dto)
                    AppToast.show(L("appearance_saved"))
                    onBack()
                }
            } catch {
                AppToast.show(error.apiFailure.message ?? L("appearance_failed"))
            }
            savingId = nil
        }
    }
}

private struct FontScreenHeader: View {
    @Environment(\.wa) private var wa
    let title: String
    let onBack: () -> Void

    var body: some View {
        HStack {
            SettingsScreenTitle(title: title)
            Spacer(minLength: 8)
            Button(action: onBack) {
                Image(systemName: "chevron.forward").font(.system(size: 17, weight: .bold)).foregroundStyle(wa.red)
                    .frame(width: 42, height: 42)
                    .background(wa.settingsCard, in: Circle())
                    .overlay(Circle().stroke(wa.night ? wa.line : .clear, lineWidth: 1))
                    .shadow(color: wa.night ? .clear : Color.black.opacity(0.12), radius: 6, y: 2)
            }
            .buttonStyle(.plain)
        }
        .padding(.vertical, 4)
    }
}

private struct FontSearchPill: View {
    @Environment(\.wa) private var wa
    @Binding var query: String
    let placeholder: String

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: "magnifyingglass").font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.red)
            ZStack(alignment: .leading) {
                if query.isEmpty { Text(verbatim: placeholder).font(.dorr(14)).foregroundStyle(wa.settingsMut) }
                TextField("", text: $query).font(.dorr(14, .medium)).foregroundStyle(wa.settingsInk).tint(wa.red)
            }
            if !query.isEmpty {
                Button { query = "" } label: {
                    Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(Color(hex: 0x9CA3AF))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
        .background(wa.settingsCard, in: Capsule())
        .overlay(Capsule().stroke(wa.night ? wa.line : .clear, lineWidth: 1))
        .shadow(color: wa.night ? .clear : Color.black.opacity(0.07), radius: 6, y: 2)
    }
}

private struct FontOptionRow: View {
    @Environment(\.wa) private var wa
    let font: MobileAppFontDto
    let selected: Bool
    let busy: Bool
    let onSelect: () -> Void

    var body: some View {
        Button(action: onSelect) {
            HStack {
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: (font.name ?? "").isEmpty ? (font.slug ?? "") : font.name!)
                        .font(.dorr(14, .bold)).foregroundStyle(selected ? .white : wa.settingsInk)
                    if font.isDefault {
                        Text(L("appearance_font_default")).font(.dorr(11)).foregroundStyle(selected ? Color.white.opacity(0.85) : wa.settingsMut)
                    }
                }
                Spacer(minLength: 8)
                if busy {
                    ProgressView().tint(selected ? .white : wa.red).scaleEffect(0.8).frame(width: 18, height: 18)
                } else if selected {
                    Image(systemName: "checkmark").font(.system(size: 15, weight: .bold)).foregroundStyle(.white)
                }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 12)
            .background {
                if selected {
                    RoundedRectangle(cornerRadius: 12, style: .continuous).fill(wa.red)
                } else {
                    Color.clear.settingsSurface(12)
                }
            }
        }
        .buttonStyle(.plain)
        .allowsHitTesting(!busy)
    }
}

private struct FontShowMoreButton: View {
    @Environment(\.wa) private var wa
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(L("wallet_history_more")).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 12)
                .background(wa.settingsCard, in: Capsule())
                .overlay(Capsule().stroke(wa.night ? wa.line : wa.red.opacity(0.35), lineWidth: 1.5))
        }
        .buttonStyle(.plain)
    }
}
