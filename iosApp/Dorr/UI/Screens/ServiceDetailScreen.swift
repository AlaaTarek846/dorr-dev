import SwiftUI

// androidApp `ServiceDetailScreen.kt`.

extension ServiceChildDto {
    var asService: ServiceDto {
        ServiceDto(id: id, name: name, moduleName: moduleName, image: image, description: description, sortOrder: sortOrder,
                   requiresProvider: nil, hasChildren: false, children: nil)
    }
}

/// One service: its picture (or icon), what it is, its sub-services, and "coming soon".
struct ServiceDetailScreen: View {
    @Environment(\.wa) private var wa
    let service: ServiceDto
    let accentColor: Color
    let onBack: () -> Void
    var onOpenChild: (ServiceChildDto) -> Void = { _ in }

    var body: some View {
        let children = service.children ?? []
        let description = (service.description ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
            ? L("services_detail_description_fallback") : service.description!
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: service.name, onBack: onBack)
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 16) {
                        hero
                        VStack(alignment: .leading, spacing: 10) {
                            Text(L("services_detail_about")).font(.dorr(15, .bold)).foregroundStyle(wa.red)
                            HtmlContent(html: description, textColor: wa.settingsInk, accentColor: wa.red, fontSize: 14, lineSpacing: 1.35)
                        }
                        .padding(.horizontal, 18)
                        .padding(.vertical, 16)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .settingsSurface(18)
                        if !children.isEmpty {
                            Text(L("services_detail_subservices")).font(.dorr(16, .bold)).foregroundStyle(wa.settingsInk)
                                .padding(.leading, 4).padding(.top, 4)
                            ForEach(children) { child in
                                ServiceChildDetailRow(child: child) { onOpenChild(child) }
                            }
                        }
                        comingSoon.padding(.top, 4)
                    }
                    .padding(.horizontal, 16)
                    .padding(.top, 8)
                    .padding(.bottom, 32)
                }
            }
        }
    }

    private var hero: some View {
        let shape = RoundedRectangle(cornerRadius: 22, style: .continuous)
        return Group {
            if (service.image ?? "").trimmingCharacters(in: .whitespaces).isEmpty {
                ServiceAvatar(image: nil, icon: serviceIcon(service.moduleName), size: 120)
                    .padding(.vertical, 28)
                    .frame(maxWidth: .infinity)
            } else {
                AsyncImage(url: ApiClient.mediaUrl(service.image)) { phase in
                    if let image = phase.image { image.resizable().scaledToFit() } else { Color.clear }
                }
                .frame(maxWidth: .infinity)
                .frame(height: 200)
                .padding(.vertical, 24)
                .padding(.horizontal, 20)
                .background(wa.night ? Color(hex: 0x22262E) : Color(hex: 0x2A3140), in: shape)
            }
        }
        .settingsSurface(22, elevation: 8)
        .clipShape(shape)
    }

    private var comingSoon: some View {
        let accent = wa.accent
        return Text(L("services_coming_soon")).font(.dorr(14, .semibold))
            .foregroundStyle(wa.night ? wa.settingsInk : accent.opacity(0.88))
            .frame(maxWidth: .infinity)
            .padding(.vertical, 14)
            .background(accent.opacity(wa.night ? 0.14 : 0.08), in: Capsule())
            .overlay(Capsule().stroke(accent.opacity(wa.night ? 0.32 : 0.22), lineWidth: 1))
    }
}

private struct ServiceChildDetailRow: View {
    @Environment(\.wa) private var wa
    let child: ServiceChildDto
    let action: () -> Void

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 16, style: .continuous)
        Button(action: action) {
            HStack(spacing: 12) {
                ServiceAvatar(image: child.image, icon: serviceIcon(child.moduleName), size: 48)
                Text(verbatim: child.name).font(.dorr(16, .semibold)).foregroundStyle(wa.settingsInk)
                Spacer(minLength: 0)
                Image(systemName: "chevron.forward").font(.system(size: 14, weight: .bold))
                    .foregroundStyle(wa.night ? Color(hex: 0x8B93A0) : wa.settingsMut)
            }
            .padding(12)
            .background(wa.settingsCard, in: shape)
            .overlay(shape.stroke(wa.night ? wa.line : .clear, lineWidth: 1))
            .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.06), radius: 4, y: 2)
        }
        .buttonStyle(.plain)
    }
}
