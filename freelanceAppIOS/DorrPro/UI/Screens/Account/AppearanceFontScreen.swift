import SwiftUI

// freelanceApp `AppearanceFontScreen.kt` — the app font: the fonts on offer (searched, 15 at a time with "show more"); a tap
// saves it on the account (`PUT appearance {mobile_app_font_id}`) and goes back.

private let fontPageSize = 15

private enum FontListState { case loading, empty, content }

@MainActor
struct AppearanceFontScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var appearance: AppearanceState
    let onBack: () -> Void

    @State private var query = ""
    @State private var debouncedQuery = ""
    @State private var chunkPage = 1
    @State private var loading = true
    @State private var savingId: Int?

    private var snap: AppearanceDto? { appearance.snapshot }
    private var selectedId: Int? { snap?.mobileAppFontId ?? snap?.font?.id }

    private var allFonts: [MobileAppFontDto] {
        let fromList = (snap?.availableFonts ?? []).filter { $0.status == true }.sorted { ($0.sortOrder ?? 0) < ($1.sortOrder ?? 0) }
        if !fromList.isEmpty { return fromList }
        return snap?.font.map { [$0] } ?? []
    }

    private var filtered: [MobileAppFontDto] {
        let q = debouncedQuery.trimmingCharacters(in: .whitespaces)
        if q.isEmpty { return allFonts }
        return allFonts.filter { ($0.name ?? "").localizedCaseInsensitiveContains(q) || ($0.slug ?? "").localizedCaseInsensitiveContains(q) }
    }

    var body: some View {
        let filtered = filtered
        let visibleFonts = Array(filtered.prefix(chunkPage * fontPageSize))
        let hasMore = filtered.count > visibleFonts.count
        let listState: FontListState = loading && allFonts.isEmpty ? .loading : visibleFonts.isEmpty ? .empty : .content
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                FontScreenHeader(title: L("appearance_font_choose"), onBack: onBack).padding(.top, 16)
                FontSearchPill(query: $query, placeholder: L("appearance_font_search")).padding(.top, 16)
                Group {
                    switch listState {
                    case .loading:
                        ProgressView().tint(wa.red).scaleEffect(1.3).frame(maxWidth: .infinity, maxHeight: .infinity)
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
                    case .content:
                        ScrollView {
                            LazyVStack(spacing: 10) {
                                ForEach(visibleFonts) { font in
                                    FontOptionRow(font: font, selected: font.id == selectedId, busy: savingId == font.id) { selectFont(font) }
                                }
                                if hasMore {
                                    FontShowMoreButton(loading: false) { chunkPage += 1 }
                                }
                            }
                            .padding(.vertical, 4)
                        }
                    }
                }
                .padding(.top, 16)
                .frame(maxHeight: .infinity)
                .animation(.easeInOut(duration: 0.26), value: listState)
                Spacer().frame(height: 14)
            }
            .padding(.horizontal, 20)
        }
        .navigationBarHidden(true)
        // The search waits 400 ms after the last key.
        .task(id: query) {
            try? await Task.sleep(nanoseconds: 400_000_000)
            if Task.isCancelled { return }
            debouncedQuery = query
            chunkPage = 1
        }
        .task {
            loading = true
            if snap?.availableFonts?.isEmpty ?? true, let dto = await AppearanceApi.show().value { appearance.apply(dto) }
            loading = false
        }
    }

    private func selectFont(_ font: MobileAppFontDto) {
        if font.id == selectedId || savingId != nil { return }
        savingId = font.id
        Task {
            let result = await AppearanceApi.update(["mobile_app_font_id": font.id])
            if case .ok(let dto, _) = result {
                appearance.apply(dto)
                AppToast.show(L("appearance_saved"))
                onBack()
            } else {
                AppToast.show(result.errorText(L("login_error_offline")) ?? L("appearance_failed"))
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
            SettingsScreenTitle(title: title).frame(maxWidth: .infinity, alignment: .leading)
            Button(action: onBack) {
                // KeyboardArrowRight (auto-mirrored): it points to the edge it sits on.
                Image(systemName: "chevron.forward").font(.system(size: 17, weight: .bold)).foregroundStyle(wa.red)
                    .frame(width: 42, height: 42)
                    .background(wa.settingsCard, in: Circle())
                    .overlay { if wa.night { Circle().stroke(wa.line, lineWidth: 1) } }
                    .shadow(color: wa.night ? .clear : .black.opacity(0.12), radius: 6, y: 2)
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("common_back"))
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
                    .autocorrectionDisabled().textInputAutocapitalization(.never)
            }
            if !query.isEmpty {
                Button { query = "" } label: {
                    Image(systemName: "xmark").font(.system(size: 14, weight: .semibold)).foregroundStyle(Color(hex: 0x9CA3AF))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
        .background(wa.settingsCard, in: Capsule())
        .overlay { if wa.night { Capsule().stroke(wa.line, lineWidth: 1) } }
        .shadow(color: wa.night ? .clear : .black.opacity(0.07), radius: 6, y: 2)
    }
}

private struct FontOptionRow: View {
    @Environment(\.wa) private var wa
    let font: MobileAppFontDto
    let selected: Bool
    let busy: Bool
    let onSelect: () -> Void

    var body: some View {
        let label = (font.name ?? "").trimmingCharacters(in: .whitespaces).isEmpty ? (font.slug ?? "") : (font.name ?? "")
        Button(action: onSelect) {
            HStack {
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: label).font(.dorr(14, .bold)).foregroundStyle(selected ? .white : wa.settingsInk)
                    if font.isDefault == true {
                        Text(L("appearance_font_default")).font(.dorr(11)).foregroundStyle(selected ? .white.opacity(0.85) : wa.settingsMut)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if busy {
                    ProgressView().tint(selected ? .white : wa.red).frame(width: 18, height: 18)
                } else if selected {
                    Image(systemName: "checkmark").font(.system(size: 15, weight: .bold)).foregroundStyle(.white)
                }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 12)
            .modifier(FontRowSurface(selected: selected))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(busy)
    }
}

/// The brand fill when chosen, else the settings card.
private struct FontRowSurface: ViewModifier {
    @Environment(\.wa) private var wa
    let selected: Bool

    func body(content: Content) -> some View {
        if selected {
            content.background(wa.red, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        } else {
            content.settingsSurface(12)
        }
    }
}

private struct FontShowMoreButton: View {
    @Environment(\.wa) private var wa
    let loading: Bool
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            ZStack {
                if loading {
                    ProgressView().tint(wa.red).frame(width: 20, height: 20)
                } else {
                    Text(L("wallet_history_more")).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                }
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 12)
            .background(wa.settingsCard, in: Capsule())
            .overlay(Capsule().stroke(wa.night ? wa.line : wa.red.opacity(0.35), lineWidth: 1.5))
        }
        .buttonStyle(.plain)
        .disabled(loading)
    }
}
