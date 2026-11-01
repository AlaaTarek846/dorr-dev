import SwiftUI

/// androidApp `components/ServicesSection.kt` `palette` — a service's colour, keyed to its place in the full list.
let servicePalette: [Color] = [
    AppColors.primary, AppColors.accent, AppColors.info, AppColors.secondary,
    Color(hex: 0x8B5CF6), AppColors.warning, Color(hex: 0x3B82F6), AppColors.danger,
]

/// androidApp `ServicesScreen.kt` — every service the dashboard exposes to the app, searchable, one clean row each.
@MainActor
struct ServicesScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpenService: (ServiceDto, Color) -> Void
    let onOpenAi: () -> Void
    @StateObject private var loader = ServicesLoader()
    @State private var query = ""
    @State private var reloadKey = 0
    @FocusState private var searchFocused: Bool

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("services_title"), onBack: onBack)
                switch loader.state {
                case .loading:
                    ServicesSkeleton().padding(16).frame(maxHeight: .infinity, alignment: .top)
                case .error:
                    ServicesError { reloadKey += 1 }.padding(16).frame(maxHeight: .infinity, alignment: .top)
                case .loaded(let services):
                    let q = query.trimmingCharacters(in: .whitespaces)
                    let filtered = services.filter { q.isEmpty || $0.name.localizedCaseInsensitiveContains(q) }
                    ScrollView {
                        LazyVStack(alignment: .leading, spacing: 10) {
                            searchField
                            Text(verbatim: L("services_count", filtered.count)).font(.dorr(12))
                                .foregroundStyle(wa.night ? wa.mut : AppColors.textSecondary)
                                .padding(.leading, 4).padding(.top, 2)
                            if filtered.isEmpty {
                                Text(L("services_no_results")).font(.dorr(14)).foregroundStyle(wa.night ? wa.mut : AppColors.textSecondary)
                                    .multilineTextAlignment(.center).frame(maxWidth: .infinity).padding(.vertical, 40)
                            }
                            ForEach(filtered) { service in
                                // Same colour as on Home: keyed to the position in the full list.
                                let color = servicePalette[(services.firstIndex(of: service) ?? 0) % servicePalette.count]
                                ServiceRow(service: service) {
                                    if service.moduleName == "ai_assistant" { onOpenAi() } else { onOpenService(service, color) }
                                }
                            }
                        }
                        .padding(.horizontal, 16)
                        .padding(.top, 16)
                        .padding(.bottom, 100)
                    }
                    .scrollDismissesKeyboard(.interactively)
                }
            }
        }
        .task(id: "\(reloadKey)-\(network.reconnectTick)") { await loader.load(homeDashboardOnly: false) }
    }

    /// Material's OutlinedTextField: rounded 16, a 1-pt border that turns brand on focus.
    private var searchField: some View {
        HStack(spacing: 12) {
            Image(systemName: "magnifyingglass").font(.system(size: 17)).foregroundStyle(AppColors.textMuted)
            ZStack(alignment: .leading) {
                if query.isEmpty { Text(L("services_search_hint")).font(.dorr(16)).foregroundStyle(AppColors.textMuted) }
                TextField("", text: $query).font(.dorr(16)).foregroundStyle(wa.settingsInk).tint(wa.accent).focused($searchFocused)
            }
        }
        .padding(.horizontal, 14)
        .frame(height: 56)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous)
            .stroke(searchFocused ? wa.accent : (wa.night ? wa.line : AppColors.border), lineWidth: searchFocused ? 2 : 1))
    }
}

private struct ServiceRow: View {
    @Environment(\.wa) private var wa
    let service: ServiceDto
    let action: () -> Void

    var body: some View {
        let childCount = service.children?.count ?? 0
        Button(action: action) {
            HStack(spacing: 14) {
                ServiceAvatar(image: service.image, icon: serviceIcon(service.moduleName), size: 52)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: service.name).font(.dorr(16, .bold)).foregroundStyle(wa.settingsInk)
                    if childCount > 0 {
                        Text(verbatim: L("services_sub_count", childCount)).font(.dorr(12, .semibold))
                            .foregroundStyle(wa.night ? wa.mut : AppColors.textMuted)
                    }
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.forward").font(.system(size: 15, weight: .bold))
                    .foregroundStyle(wa.night ? Color(hex: 0x8B93A0) : Color(hex: 0xF8BCA9))
            }
            .padding(12)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(wa.night ? wa.line : .clear, lineWidth: 1))
            .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.07), radius: 6, y: 2)
        }
        .buttonStyle(.plain)
    }
}

/// androidApp `ServicesSkeleton` — two rows of pulsing boxes while the list loads.
struct ServicesSkeleton: View {
    var body: some View {
        VStack(spacing: 12) {
            ForEach(0..<2, id: \.self) { _ in
                HStack(spacing: 12) {
                    ForEach(0..<3, id: \.self) { _ in SkeletonPulse(cornerRadius: 20).frame(height: 128) }
                }
            }
        }
    }
}
