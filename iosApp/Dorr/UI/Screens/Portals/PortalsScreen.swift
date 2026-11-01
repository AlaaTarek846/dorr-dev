import PhotosUI
import SwiftUI
import UIKit

// androidApp `portals/PortalsScreen.kt` + the portals circle of `portals/HomeCircles.kt`.

private enum PortalPage: Equatable {
    case directory
    case mine
    case edit(PortalDto?)
    case plans(PortalDto)

    var key: String {
        switch self {
        case .directory: return "directory"
        case .mine: return "mine"
        case .edit(let p): return "edit-\(p?.id ?? "new")"
        case .plans(let p): return "plans-\(p.id)"
        }
    }
}

/// Merchant portals: the page everyone browses (search, grouped by category, most viewed first), my portals,
/// adding one (its name and description per language, the AI filling the other languages), and putting it on
/// the page with a package — paid on the one payment screen.
@MainActor
struct PortalsScreen: View {
    @Environment(\.wa) private var wa
    let onExit: () -> Void
    @State private var stack: [PortalPage] = [.directory]
    @State private var forward = true
    /// Bumped when my portals changed (saved, paid) so the lists reload.
    @State private var version = 0

    var body: some View {
        ZStack {
            page(stack.last ?? .directory)
                .id(stack.last?.key ?? "directory")
                .transition(.asymmetric(
                    insertion: .modifier(active: PortalSlide(fraction: forward ? 1.0 / 6 : -1.0 / 6), identity: PortalSlide(fraction: 0)),
                    removal: .modifier(active: PortalSlide(fraction: forward ? -0.1 : 0.1), identity: PortalSlide(fraction: 0))
                ))
        }
        .background(wa.bg.ignoresSafeArea())
    }

    @ViewBuilder private func page(_ page: PortalPage) -> some View {
        switch page {
        case .directory:
            PortalDirectory(onBack: pop, onMine: { push(.mine) }, onAdd: { push(.edit(nil)) })
        case .mine:
            MyPortals(version: version, onBack: pop, onAdd: { push(.edit(nil)) }, onEdit: { push(.edit($0)) }, onPlans: { push(.plans($0)) })
        case .edit(let portal):
            PortalEditor(portal: portal, onBack: pop) { saved, created in
                version += 1
                withAnimation(.easeOut(duration: 0.34)) {
                    forward = false
                    stack.removeLast()
                    if stack.last != .mine { forward = true; stack.append(.mine) }
                    // A new portal goes straight to choosing how long it shows on the page.
                    if created { forward = true; stack.append(.plans(saved)) }
                }
            }
        case .plans(let portal):
            PortalPlans(portal: portal, onBack: pop) {
                version += 1
                pop()
            }
        }
    }

    private func push(_ page: PortalPage) {
        forward = true
        withAnimation(.easeOut(duration: 0.34)) { stack.append(page) }
    }

    private func pop() {
        if stack.count <= 1 { onExit(); return }
        forward = false
        withAnimation(.easeOut(duration: 0.3)) { _ = stack.removeLast() }
    }
}

/// Slides by a share of the width (mirrored in Arabic by SwiftUI) while fading.
private struct PortalSlide: ViewModifier {
    let fraction: CGFloat
    func body(content: Content) -> some View {
        GeometryReader { proxy in
            content.offset(x: proxy.size.width * fraction).opacity(fraction == 0 ? 1 : 0)
        }
    }
}

private func compact(_ n: Int) -> String {
    if n >= 1_000_000 { return String(format: "%.1fM", Double(n) / 1_000_000).replacingOccurrences(of: ".0M", with: "M") }
    if n >= 1_000 { return String(format: "%.1fK", Double(n) / 1_000).replacingOccurrences(of: ".0K", with: "K") }
    return String(n)
}

private func shortDate(_ iso: String?) -> String {
    guard let date = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.dateStyle = .medium
    f.timeStyle = .none
    return f.string(from: date)
}

// MARK: - The page everyone sees

@MainActor
private struct PortalDirectory: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let onMine: () -> Void
    let onAdd: () -> Void
    @State private var search = ""
    @State private var category: Int?
    @State private var categories: [CategoryDto] = []
    @State private var groups: [PortalGroupDto]?
    @State private var error: String?

    var body: some View {
        WaPage(title: L("pt_title"), onBack: onBack, actions: {
            WaCircleButton(icon: "storefront.fill", label: L("pt_mine"), action: onMine)
        }) {
            PortalsHero(onAdd: onAdd).waRise(0)
            DorrTextArea(value: Binding(get: { search }, set: { search = String($0.prefix(60)) }), placeholder: L("pt_search"),
                         icon: "magnifyingglass", singleLine: true)
                .padding(.top, 12).waRise(1)
            if !categories.isEmpty {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        CategoryChip(icon: nil, label: L("pt_all"), selected: category == nil) { category = nil }
                        ForEach(categories) { c in
                            CategoryChip(icon: c.icon, label: c.name ?? "", selected: category == c.id) { category = category == c.id ? nil : c.id }
                        }
                    }
                    .padding(.vertical, 2)
                }
                .padding(.top, 10).waRise(2)
            }
            WaError(message: error)
            if let list = groups {
                if list.isEmpty {
                    WaCard {
                        WaEmpty(icon: "storefront.fill", tone: .red,
                                title: L(search.trimmingCharacters(in: .whitespaces).isEmpty ? "pt_empty_title" : "pt_no_results"),
                                text: L("pt_empty_text")) {
                            WaButton(text: L("pt_add_mine"), icon: "plus.rectangle.on.rectangle", action: onAdd)
                        }
                    }
                    .padding(.top, 16)
                } else {
                    ForEach(Array(list.enumerated()), id: \.offset) { gi, group in
                        HStack(spacing: 8) {
                            CategoryIcon(url: group.category?.icon, size: 26)
                            Text(verbatim: group.category?.name ?? L("pt_other")).font(.dorr(17, .heavy)).foregroundStyle(wa.ink)
                            Spacer(minLength: 0)
                            Text(verbatim: "\(group.portals.count)").font(.dorr(13, .bold)).foregroundStyle(wa.mut)
                        }
                        .padding(.top, 18).padding(.bottom, 8)
                        .waRise(3 + gi)
                        ForEach(Array(group.portals.enumerated()), id: \.element.id) { i, portal in
                            PortalCard(portal: portal) { open(portal) }
                                .padding(.bottom, 10)
                                .waRise(4 + gi + i)
                        }
                    }
                }
            } else {
                ForEach(0..<4, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 80).padding(.top, 12) }
            }
        }
        .task { categories = (try? await DiscoverApi.categories().data) ?? [] }
        .task(id: "\(search)|\(category ?? -1)") {
            if !search.isEmpty {
                try? await Task.sleep(nanoseconds: 300_000_000)
                guard !Task.isCancelled else { return }
            }
            do {
                let q = search.trimmingCharacters(in: .whitespaces)
                groups = try await DiscoverApi.portals(search: q.isEmpty ? nil : q, categoryId: category).data ?? []
                error = nil
            } catch {
                if error is CancellationError { return }
                self.error = error.apiFailure.message
            }
        }
    }

    private func open(_ portal: PortalDto) {
        Task {
            let opened = (try? await DiscoverApi.openPortal(portal.id).data) ?? portal
            if let url = URL(string: opened.websiteUrl) { await UIApplication.shared.open(url) }
            // The count moved: show it.
            groups = groups?.map { g in
                var copy = g
                copy.portals = g.portals.map { p in
                    var p = p
                    if p.id == opened.id { p.viewsCount = opened.viewsCount }
                    return p
                }
                return copy
            }
        }
    }
}

private struct PortalsHero: View {
    @Environment(\.wa) private var wa
    let onAdd: () -> Void

    var body: some View {
        HStack(spacing: 0) {
            Image(systemName: "storefront.fill").font(.system(size: 26)).foregroundStyle(.white)
                .frame(width: 54, height: 54)
                .background(Color.white.opacity(0.18), in: Circle())
            Spacer().frame(width: 12)
            VStack(alignment: .leading, spacing: 0) {
                Text(L("pt_hero")).font(.dorr(16, .heavy)).foregroundStyle(.white)
                Text(L("pt_hero_sub")).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.85))
            }
            Spacer(minLength: 8)
            Button(action: onAdd) {
                Text(L("pt_add_short")).font(.dorr(13, .heavy)).foregroundStyle(wa.red)
                    .padding(.horizontal, 12).padding(.vertical, 9)
                    .background(Color.white, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            .buttonStyle(WaPressStyle())
        }
        .padding(16)
        .background(wa.heroGradient, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
    }
}

private struct CategoryChip: View {
    @Environment(\.wa) private var wa
    let icon: String?
    let label: String
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                if let icon {
                    AsyncImage(url: ApiClient.mediaUrl(icon)) { phase in phase.image?.resizable().scaledToFit() }
                        .frame(width: 20, height: 20)
                        .clipShape(RoundedRectangle(cornerRadius: 6, style: .continuous))
                }
                Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(selected ? .white : wa.ink)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 7)
            .background(selected ? wa.red : wa.surface, in: Capsule())
            .overlay(Capsule().stroke(selected ? .clear : wa.line, lineWidth: 1))
            .animation(.easeInOut(duration: 0.2), value: selected)
        }
        .buttonStyle(.plain)
    }
}

private struct CategoryIcon: View {
    @Environment(\.wa) private var wa
    let url: String?
    let size: CGFloat

    var body: some View {
        ZStack {
            if let url {
                AsyncImage(url: ApiClient.mediaUrl(url)) { phase in phase.image?.resizable().scaledToFill() }
            } else {
                Image(systemName: "square.grid.2x2.fill").font(.system(size: size * 0.5)).foregroundStyle(wa.red)
            }
        }
        .frame(width: size, height: size)
        .background(wa.red.opacity(0.1))
        .clipShape(RoundedRectangle(cornerRadius: size * 0.28, style: .continuous))
    }
}

struct PortalLogo: View {
    @Environment(\.wa) private var wa
    let url: String?
    let size: CGFloat

    var body: some View {
        ZStack {
            if let url, let link = URL(string: url) {
                AsyncImage(url: link) { phase in phase.image?.resizable().scaledToFill() }
            } else {
                Image(systemName: "storefront.fill").font(.system(size: size * 0.4)).foregroundStyle(wa.soft)
            }
        }
        .frame(width: size, height: size)
        .background(wa.field)
        .clipShape(RoundedRectangle(cornerRadius: size / 3.5, style: .continuous))
    }
}

private struct PortalCard: View {
    @Environment(\.wa) private var wa
    let portal: PortalDto
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 12) {
                PortalLogo(url: portal.logo, size: 60)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: portal.name ?? "").font(.dorr(15.5, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                    if let d = portal.description, !d.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(verbatim: d).font(.dorr(12.5)).foregroundStyle(wa.mut).lineLimit(2).lineSpacing(1).multilineTextAlignment(.leading)
                    }
                    HStack(spacing: 0) {
                        if let name = portal.category?.name {
                            Text(verbatim: name).font(.dorr(11, .bold)).foregroundStyle(wa.red)
                                .padding(.horizontal, 8).padding(.vertical, 2)
                                .background(wa.red.opacity(0.1), in: Capsule())
                            Spacer().frame(width: 8)
                        }
                        Image(systemName: "eye.fill").font(.system(size: 11)).foregroundStyle(wa.soft)
                        Spacer().frame(width: 3)
                        Text(verbatim: L("pt_views", compact(portal.viewsCount))).font(.dorr(11.5, .semibold)).foregroundStyle(wa.soft)
                    }
                    .padding(.top, 6)
                }
                Spacer(minLength: 0)
                Image(systemName: "globe").font(.system(size: 16)).foregroundStyle(wa.soft)
            }
            .padding(12)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
        }
        .buttonStyle(WaPressStyle(scale: 0.98))
    }
}

// MARK: - My portals

@MainActor
private struct MyPortals: View {
    @Environment(\.wa) private var wa
    let version: Int
    let onBack: () -> Void
    let onAdd: () -> Void
    let onEdit: (PortalDto) -> Void
    let onPlans: (PortalDto) -> Void
    @State private var mine: [PortalDto]?
    @State private var error: String?
    @State private var deleting: PortalDto?

    var body: some View {
        WaPage(title: L("pt_mine"), onBack: onBack, cta: {
            WaButton(text: L("pt_add_new"), icon: "plus.rectangle.on.rectangle", action: onAdd)
        }) {
            WaError(message: error)
            if let list = mine {
                if list.isEmpty {
                    WaCard { WaEmpty(icon: "storefront.fill", tone: .red, title: L("pt_mine_empty"), text: L("pt_mine_empty_text")) }
                } else {
                    ForEach(Array(list.enumerated()), id: \.element.id) { i, portal in
                        WaCard(padding: 14) {
                            HStack(spacing: 12) {
                                PortalLogo(url: portal.logo, size: 52)
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(verbatim: portal.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                                    Text(verbatim: portal.websiteUrl).font(.dorr(12)).foregroundStyle(wa.mut).lineLimit(1)
                                }
                                Spacer(minLength: 0)
                                WaCircleButton(icon: "trash", tint: wa.danger) { deleting = portal }
                            }
                            ListingBadge(portal: portal).padding(.top, 10)
                            HStack(spacing: 10) {
                                WaButton(text: L("pt_edit"), style: .ghost, icon: "pencil") { onEdit(portal) }
                                WaButton(text: L(portal.isListed == true ? "pt_renew" : "pt_publish"), icon: "paperplane.fill",
                                         enabled: portal.status != false) { onPlans(portal) }
                            }
                            .padding(.top, 10)
                        }
                        .padding(.bottom, 12)
                        .waRise(i)
                    }
                }
            } else {
                ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 138).padding(.bottom, 12) }
            }
        }
        .task(id: version) {
            do { mine = try await DiscoverApi.myPortals().data ?? [] } catch { self.error = error.apiFailure.message }
        }
        .alert(L("pt_delete_title"), isPresented: Binding(get: { deleting != nil }, set: { if !$0 { deleting = nil } }), presenting: deleting) { portal in
            Button(L("pt_delete"), role: .destructive) { delete(portal) }
            Button(L("wa_cancel"), role: .cancel) { deleting = nil }
        } message: { portal in
            Text(verbatim: L("pt_delete_text", portal.name ?? ""))
        }
    }

    private func delete(_ portal: PortalDto) {
        deleting = nil
        Task {
            do {
                _ = try await DiscoverApi.deletePortal(portal.id)
                mine = mine?.filter { $0.id != portal.id }
            } catch {
                self.error = error.apiFailure.message
            }
        }
    }
}

private struct ListingBadge: View {
    @Environment(\.wa) private var wa
    let portal: PortalDto

    var body: some View {
        let (icon, fg, bg, text): (String, Color, Color, String) = {
            if portal.status == false {
                return ("eye.slash.fill", wa.mut, wa.night ? wa.darkWell : Color(hex: 0xF3F4F6), L("pt_disabled"))
            }
            if portal.isListed == true {
                return ("checkmark.circle.fill", wa.green, wa.green.opacity(0.12), L("pt_listed_until", shortDate(portal.listedUntil)))
            }
            return ("eye.slash.fill", Color(hex: 0xB45309), Color(hex: 0xF59E0B).opacity(0.14), L("pt_not_listed"))
        }()
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 14))
            Text(verbatim: text).font(.dorr(12.5, .bold))
            if portal.isListed == true {
                Image(systemName: "eye.fill").font(.system(size: 12)).padding(.leading, 2)
                Text(verbatim: compact(portal.viewsCount)).font(.dorr(12, .bold))
            }
        }
        .foregroundStyle(fg)
        .padding(.horizontal, 10)
        .padding(.vertical, 6)
        .background(bg, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
    }
}

// MARK: - Add / edit

@MainActor
private struct PortalEditor: View {
    @Environment(\.wa) private var wa
    let portal: PortalDto?
    let onBack: () -> Void
    let onSaved: (PortalDto, Bool) -> Void

    @State private var languages: [PortalLanguageDto]?
    @State private var categories: [CategoryDto] = []
    @State private var active = ""
    @State private var names: [String: String] = [:]
    @State private var descriptions: [String: String] = [:]
    @State private var website = "https://"
    @State private var category: Int?
    @State private var logo: UIImage?
    @State private var logoData: Data?
    @State private var pickerItem: PhotosPickerItem?
    @State private var busy = false
    @State private var translating = false
    @State private var error: String?
    @State private var started = false

    private var urlOk: Bool {
        website.trimmingCharacters(in: .whitespaces).range(of: #"^https?://[^\s/$.?#].[^\s]*$"#, options: [.regularExpression, .caseInsensitive]) != nil
    }
    private var hasName: Bool { names.values.contains { !$0.trimmingCharacters(in: .whitespaces).isEmpty } }
    private var hasLogo: Bool { logoData != nil || portal?.logo != nil }

    var body: some View {
        WaPage(title: L(portal == nil ? "pt_add_new" : "pt_edit"), onBack: onBack, cta: {
            WaButton(text: L("pt_save"), icon: "checkmark.circle.fill", enabled: urlOk && hasName && hasLogo && !busy, loading: busy, action: save)
        }) {
            // Logo.
            VStack(spacing: 6) {
                PhotosPicker(selection: $pickerItem, matching: .images) {
                    ZStack {
                        if let logo {
                            Image(uiImage: logo).resizable().scaledToFill()
                        } else if let url = portal?.logo, let link = URL(string: url) {
                            AsyncImage(url: link) { phase in phase.image?.resizable().scaledToFill() }
                        } else {
                            Image(systemName: "camera.fill").font(.system(size: 30)).foregroundStyle(.white)
                        }
                    }
                    .frame(width: 104, height: 104)
                    .background(wa.heroGradient)
                    .clipShape(RoundedRectangle(cornerRadius: 30, style: .continuous))
                }
                .buttonStyle(.plain)
                Text(L("pt_logo")).font(.dorr(12)).foregroundStyle(wa.mut)
            }
            .frame(maxWidth: .infinity)
            .waRise(0)

            WaSectionTitle(title: L("pt_website")).waRise(1)
            DorrTextArea(value: Binding(get: { website }, set: { website = String($0.prefix(500)).trimmingCharacters(in: .whitespaces) }),
                         placeholder: "https://", icon: "globe", error: website.count > 8 && !urlOk, singleLine: true)
                .keyboardType(.URL)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
                .waRise(1)

            if !categories.isEmpty {
                WaSectionTitle(title: L("pt_category")).waRise(2)
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(categories) { c in
                            CategoryChip(icon: c.icon, label: c.name ?? "", selected: category == c.id) { category = category == c.id ? nil : c.id }
                        }
                    }
                }
                .waRise(2)
            }

            // Name + description: one tab per language (like the admin's cards), the AI fills the others.
            WaSectionTitle(title: L("pt_name_desc")).waRise(3)
            if let langs = languages {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(langs, id: \.code) { lang in
                            LanguageTab(lang: lang, selected: active == lang.code,
                                        filled: !(names[lang.code] ?? "").trimmingCharacters(in: .whitespaces).isEmpty) { active = lang.code }
                        }
                    }
                }
                .waRise(3)
                WaCard(padding: 14) {
                    let rtl = langs.first { $0.code == active }?.direction == "rtl"
                    VStack(spacing: 10) {
                        DorrTextArea(value: Binding(get: { names[active] ?? "" }, set: { names[active] = String($0.prefix(120)) }),
                                     label: L("pt_name"), icon: "storefront.fill", singleLine: true)
                        DorrTextArea(value: Binding(get: { descriptions[active] ?? "" }, set: { descriptions[active] = String($0.prefix(2000)) }),
                                     label: L("pt_description"), minHeight: 96)
                    }
                    .environment(\.layoutDirection, rtl ? .rightToLeft : .leftToRight)
                    if langs.count > 1 {
                        WaButton(text: L("pt_translate"), style: .ghost, icon: "sparkles",
                                 enabled: !(names[active] ?? "").trimmingCharacters(in: .whitespaces).isEmpty && !translating,
                                 loading: translating, action: translate)
                            .padding(.top, 12)
                        WaNote(text: L("pt_translate_note")).padding(.top, 10)
                    }
                }
                .padding(.top, 10)
                .waRise(4)
            } else {
                WaSkeleton(cornerRadius: 20).frame(height: 160)
            }
            WaError(message: error)
        }
        .task {
            guard !started else { return }
            started = true
            website = portal?.websiteUrl ?? "https://"
            category = portal?.category?.id
            for t in portal?.translations ?? [] {
                names[t.locale] = t.name ?? ""
                descriptions[t.locale] = t.description ?? ""
            }
            categories = (try? await DiscoverApi.categories().data) ?? []
            var langs = (try? await DiscoverApi.portalLanguages().data) ?? []
            if langs.isEmpty { langs = [PortalLanguageDto(code: AppLocale.current, name: "", direction: nil)] }
            languages = langs
            if active.isEmpty { active = langs[0].code }
        }
        .onChange(of: pickerItem) { item in
            guard let item else { return }
            pickerItem = nil
            Task {
                guard let raw = try? await item.loadTransferable(type: Data.self), let image = UIImage(data: raw) else { return }
                logo = image
                logoData = image.jpegData(compressionQuality: 0.85)
            }
        }
    }

    private func save() {
        busy = true
        error = nil
        Task {
            do {
                var fields = ["website_url": website.trimmingCharacters(in: .whitespaces)]
                if let category { fields["category_id"] = String(category) }
                for (i, lang) in (languages ?? []).enumerated() {
                    fields["translations[\(i)][locale]"] = lang.code
                    fields["translations[\(i)][name]"] = (names[lang.code] ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
                    fields["translations[\(i)][description]"] = (descriptions[lang.code] ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
                }
                let saved = portal == nil
                    ? try await DiscoverApi.createPortal(fields: fields, logo: logoData).data
                    : try await DiscoverApi.updatePortal(portal!.id, fields: fields, logo: logoData).data
                if let saved { onSaved(saved, portal == nil) }
            } catch {
                self.error = error.apiFailure.message ?? L("wa_error_generic")
            }
            busy = false
        }
    }

    private func translate() {
        translating = true
        error = nil
        Task {
            do {
                let description = descriptions[active] ?? ""
                let result = try await DiscoverApi.translatePortal([
                    "from": active, "name": names[active] ?? "", "description": description.trimmingCharacters(in: .whitespaces).isEmpty ? nil : description,
                ]).data ?? [:]
                for (code, fields) in result {
                    if let name = fields["name"], !name.trimmingCharacters(in: .whitespaces).isEmpty { names[code] = name }
                    if let d = fields["description"], !d.trimmingCharacters(in: .whitespaces).isEmpty { descriptions[code] = d }
                }
            } catch {
                self.error = error.apiFailure.message ?? L("wa_error_generic")
            }
            translating = false
        }
    }
}

private struct LanguageTab: View {
    @Environment(\.wa) private var wa
    let lang: PortalLanguageDto
    let selected: Bool
    let filled: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Text(verbatim: lang.name.trimmingCharacters(in: .whitespaces).isEmpty ? lang.code.uppercased() : lang.name)
                    .font(.dorr(13, .bold)).foregroundStyle(selected ? .white : wa.ink)
                if filled {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 13)).foregroundStyle(selected ? .white : wa.green)
                }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 9)
            .background(selected ? wa.red : wa.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(selected ? .clear : wa.line, lineWidth: 1))
            .animation(.easeInOut(duration: 0.2), value: selected)
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Putting it on the page

@MainActor
private struct PortalPlans: View {
    @Environment(\.wa) private var wa
    let portal: PortalDto
    let onBack: () -> Void
    let onPaid: () -> Void
    @State private var packages: [PackageDto]?
    @State private var chosen: Int?
    @State private var error: String?

    var body: some View {
        WaPage(title: L("pt_plans_title"), onBack: onBack, cta: {
            WaButton(text: L("pt_continue_to_pay"), icon: "paperplane.fill", enabled: chosen != nil) {
                guard let id = chosen else { return }
                CheckoutLauncher.shared.open(CheckoutRequest(purpose: "chat_portal_listing",
                                                             reference: ["portal_id": .string(portal.id), "package_id": .int(id)]), onPaid: onPaid)
            }
        }) {
            HStack(spacing: 12) {
                PortalLogo(url: portal.logo, size: 48)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: portal.name ?? "").font(.dorr(16, .heavy)).foregroundStyle(wa.ink)
                    Text(L("pt_plans_sub")).font(.dorr(12.5)).foregroundStyle(wa.mut)
                }
            }
            .waRise(0)
            if portal.isListed == true { WaNote(text: L("pt_renew_note", shortDate(portal.listedUntil))).padding(.top, 10) }
            WaError(message: error)
            Spacer().frame(height: 10)
            if let list = packages {
                if list.isEmpty {
                    WaCard { WaEmpty(icon: "paperplane.fill", tone: .gray, title: L("pt_no_plans"), text: L("pt_no_plans_text")) }
                } else {
                    ForEach(Array(list.enumerated()), id: \.element.id) { i, p in
                        PlanCard(plan: p, selected: chosen == p.id) { chosen = p.id }
                            .padding(.bottom, 10)
                            .waRise(1 + i)
                    }
                }
            } else {
                ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 20).frame(height: 66).padding(.bottom, 10) }
            }
        }
        .task {
            do {
                let list = try await DiscoverApi.portalPackages().data ?? []
                packages = list
                chosen = list.first?.id
            } catch {
                self.error = error.apiFailure.message
            }
        }
    }
}

struct PlanCard: View {
    @Environment(\.wa) private var wa
    let plan: PackageDto
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 12) {
                WaIconWell(icon: "paperplane.fill", tone: selected ? .red : .gray)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: plan.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.ink)
                    Text(verbatim: periodLabel(plan)).font(.dorr(12.5)).foregroundStyle(wa.mut)
                    if let d = plan.description, !d.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(verbatim: d).font(.dorr(12)).foregroundStyle(wa.soft).lineLimit(2).multilineTextAlignment(.leading)
                    }
                }
                Spacer(minLength: 0)
                HStack(alignment: .lastTextBaseline, spacing: 3) {
                    Text(verbatim: money(plan.amountMinor ?? 0)).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                    Text(verbatim: plan.currencyCode ?? "").font(.dorr(11, .bold)).foregroundStyle(wa.mut)
                }
                .environment(\.layoutDirection, .leftToRight)
            }
            .padding(16)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(selected ? wa.red : wa.line, lineWidth: selected ? 2 : 1))
            .animation(.easeInOut(duration: 0.2), value: selected)
        }
        .buttonStyle(WaPressStyle(scale: 0.98))
    }
}

func periodLabel(_ plan: PackageDto) -> String {
    switch plan.period {
    case "week": return Lp("pt_weeks", plan.periodCount, plan.periodCount)
    case "year": return Lp("pt_years", plan.periodCount, plan.periodCount)
    default: return Lp("pt_months", plan.periodCount, plan.periodCount)
    }
}

// MARK: - Home circle

/// androidApp `HomeCircles` — the circles row on Home, drawn like the chat's stories bar: me, the merchant portals
/// circle (not a story — it opens the portals page), Dorr's own stories, then everyone's public stories (the ones I
/// haven't watched first). The stories open in the chat's own viewer (HomeStoriesLayer); more load near the end.
struct HomeCircles: View {
    @ObservedObject private var stories = HomeStories.shared
    @ObservedObject private var network = NetworkMonitor.shared
    @Environment(\.wa) private var wa
    let onOpenPortals: () -> Void

    var body: some View {
        let people = stories.feed?.people ?? []
        ScrollView(.horizontal, showsIndicators: false) {
            LazyHStack(spacing: 2) {
                if stories.feed?.enabled != false { HomeMyCircle() }
                PortalsCircle(action: onOpenPortals)
                if let dorr = stories.feed?.dorr { HomeDorrCircle(group: dorr) }
                ForEach(Array(people.enumerated()), id: \.offset) { i, group in
                    HomePersonCircle(group: group).onAppear { if i >= people.count - 3 { Task { await stories.loadMore() } } }
                }
                if stories.loadingMore { ProgressView().tint(wa.red).frame(width: 58, height: 56) }
            }
            .padding(.horizontal, 20)
        }
        .task(id: network.reconnectTick) { await stories.refresh() }
    }
}

private struct PortalsCircle: View {
    @Environment(\.wa) private var wa
    let action: () -> Void
    @State private var pop: CGFloat = 0.6

    var body: some View {
        Button(action: action) {
            VStack(spacing: 4) {
                // Always "new": the ring keeps turning, like an unseen story.
                StoryRing(size: 56) {
                    Image(systemName: "storefront.fill").font(.system(size: 21)).foregroundStyle(.white)
                        .frame(width: 46, height: 46)
                        .background(wa.heroGradient, in: Circle())
                }
                Text(L("pt_circle")).font(.dorr(11, .bold)).foregroundStyle(wa.ink).lineLimit(1).multilineTextAlignment(.center)
            }
            .frame(width: 58)
            .scaleEffect(pop)
        }
        .buttonStyle(WaPressStyle(scale: 0.92))
        .onAppear { withAnimation(.spring(response: 0.36, dampingFraction: 0.45)) { pop = 1 } }
    }
}

/// androidApp `chat/StoriesBar.kt` `StoryRing` (one unseen story): a brand sweep ring turning slowly (9 s a turn).
struct StoryRing<Content: View>: View {
    @Environment(\.wa) private var wa
    let size: CGFloat
    @ViewBuilder let content: () -> Content

    var body: some View {
        ZStack {
            TimelineView(.animation) { context in
                let angle = context.date.timeIntervalSinceReferenceDate.truncatingRemainder(dividingBy: 9) / 9 * 360
                Circle()
                    .stroke(AngularGradient(colors: [wa.red, wa.red.scaled(0.72), wa.red.scaled(0.45), wa.red], center: .center),
                            style: StrokeStyle(lineWidth: 2.6, lineCap: .round))
                    .padding(1.3)
                    .rotationEffect(.degrees(angle))
            }
            content().padding(5)
        }
        .frame(width: size, height: size)
    }
}
