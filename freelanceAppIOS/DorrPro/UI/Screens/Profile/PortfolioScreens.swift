import PhotosUI
import SwiftUI

// freelanceApp `ui/screens/PortfolioScreens.kt`.

private let maxWorks = 30
private let maxImages = 10
private let maxWorkSkills = 10

/** My portfolio as a tab: my works (first image, title, who can see it), add one, and put them in order. Up to 30. */
struct PortfolioTab: View {
    let onBack: () -> Void
    let onOpenItem: (Int) -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor

    @State private var items: [PortfolioItemDto] = []
    @State private var loaded = false
    @State private var error: String?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                SubHeader(title: L("portfolio_title"), onBack: onBack)
                VStack(alignment: .leading, spacing: 12) {
                    Text(L("portfolio_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    if !loaded && error == nil { SkeletonList() }
                    if loaded {
                        if items.count < maxWorks { SettingsPrimaryButton(text: L("portfolio_add")) { onOpenItem(0) } }
                        if items.isEmpty { Text(L("portfolio_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut) }
                        ForEach(Array(items.enumerated()), id: \.element.id) { index, item in
                            row(index, item)
                        }
                    }
                }
                .padding(.horizontal, 14)
            }
            .padding(.bottom, 16)
        }
        .messageToast(error)
        .task(id: network.reconnectTick) {
            let result = await PortfolioApi.list()
            if let list = result.value {
                items = list
                loaded = true
                error = nil
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    private func row(_ index: Int, _ item: PortfolioItemDto) -> some View {
        HStack(spacing: 10) {
            ZStack {
                wa.red.opacity(0.12)
                ProfileAuthedImage(path: item.images?.first?.path) {
                    Image(systemName: "photo").font(.system(size: 20)).foregroundStyle(wa.red)
                }
            }
            .frame(width: 64, height: 64)
            .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: item.title ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                if let specialty = item.specialty?.name, !specialty.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: specialty).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
                Text(visibilityLabel(item.visibility)).font(.dorr(12, .semibold)).foregroundStyle(wa.red)
                if item.hidden == true {
                    Text(verbatim: L("portfolio_hidden") + (item.hiddenReason.map { ": \($0)" } ?? "")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            VStack(spacing: 6) {
                Button { move(index, -1) } label: {
                    Image(systemName: "arrow.up").font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 22, height: 22)
                }
                .buttonStyle(.plain)
                .disabled(index <= 0)
                Button { move(index, 1) } label: {
                    Image(systemName: "arrow.down").font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 22, height: 22)
                }
                .buttonStyle(.plain)
                .disabled(index >= items.count - 1)
            }
        }
        .padding(10)
        .frame(maxWidth: .infinity)
        .settingsSurface(16)
        .contentShape(Rectangle())
        .onTapGesture { onOpenItem(item.id ?? 0) }
    }

    private func move(_ index: Int, _ by: Int) {
        let to = index + by
        guard items.indices.contains(to) else { return }
        let moved = items.remove(at: index)
        items.insert(moved, at: to)
        let ids = items.compactMap(\.id)
        Task {
            let result = await PortfolioApi.order(PortfolioOrderRequest(ids: ids))
            if result.value == nil { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }
}

private func visibilityLabel(_ visibility: String?) -> String {
    switch visibility {
    case "public": return L("vis_public")
    case "clients_only": return L("vis_clients")
    default: return L("vis_private")
    }
}

/// An image chosen but not sent yet.
private struct PendingImage: Identifiable {
    let id = UUID()
    let file: FilePart
}

/**
 * One work: add (itemId 0) or edit. The text fields and who can see it are saved with one request; the images (up to 10, from the
 * gallery or the camera) go up one by one after that. A work shown beyond its owner needs the rights confirmation.
 */
struct PortfolioFormScreen: View {
    let itemId: Int
    let onBack: () -> Void

    @Environment(\.wa) private var wa

    @State private var currentId: Int
    @State private var item: PortfolioItemDto?
    @State private var specialties: [MySpecialtyDto] = []
    @State private var mySkills: [MySkillDto] = []
    @State private var loaded: Bool

    @State private var title = ""
    @State private var description = ""
    @State private var completed = ""
    @State private var specialtyId: Int?
    @State private var skillIds: [Int] = []
    @State private var visibility = "private"
    @State private var rights = false
    @State private var externalUrl = ""
    @State private var videoUrl = ""
    @State private var pending: [PendingImage] = []
    @State private var busy = false
    @State private var error: String?
    @State private var confirmDelete = false
    @State private var galleryItem: PhotosPickerItem?
    @State private var cameraOpen = false

    init(itemId: Int, onBack: @escaping () -> Void) {
        self.itemId = itemId
        self.onBack = onBack
        _currentId = State(initialValue: itemId)
        _loaded = State(initialValue: itemId == 0)
    }

    private var existing: [PortfolioImageDto] { item?.images ?? [] }
    private var imageCount: Int { existing.count + pending.count }

    var body: some View {
        Page(title: L(currentId == 0 ? "portfolio_add" : "common_edit"), onBack: onBack) {
            if !loaded && error == nil { SkeletonDetail() }
            if loaded { form }
        }
        .messageToast(error)
        .profileDeleteConfirm(isPresented: $confirmDelete, onConfirm: delete)
        // One read of each list when the page opens.
        .task {
            if let list = await ListsApi.mySpecialties().value { specialties = list }
            if let list = await ListsApi.mySkills().value { mySkills = list }
            if itemId != 0 {
                let result = await PortfolioApi.list()
                if let list = result.value {
                    if let found = list.first(where: { $0.id == currentId }) { fill(found) }
                    loaded = true
                } else {
                    error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
                }
            }
        }
        .onChange(of: galleryItem) { picked in
            guard let picked else { return }
            galleryItem = nil
            Task {
                guard let data = try? await picked.loadTransferable(type: Data.self) else { return }
                take(data)
            }
        }
        .fullScreenCover(isPresented: $cameraOpen) {
            ProfileCameraPicker { data in
                cameraOpen = false
                if let data { take(data) }
            }
            .ignoresSafeArea()
        }
    }

    @ViewBuilder
    private var form: some View {
        FormIntro(icon: "briefcase.fill", title: L("pf_intro_title"), text: L("pf_intro"))
        FormGroup {
            DorrTextField(value: $title, label: L("pf_title"), icon: "briefcase.fill")
            DorrTextField(value: $description.profileTake(1000), label: L("pf_description"), icon: "text.alignleft", singleLine: false, minLines: 3)
            DateField(value: $completed, label: L("pf_completed"))
        }

        if !specialties.isEmpty {
            FormGroup {
                FieldLabel(text: L("pf_specialty"))
                ChipRow(
                    options: specialties.map { (value: String($0.id ?? 0), label: $0.name ?? "") },
                    selected: Set([specialtyId.map(String.init)].compactMap { $0 }),
                    onToggle: { value in specialtyId = specialtyId.map(String.init) == value ? nil : Int(value) }
                )
            }
        }
        if !mySkills.isEmpty {
            FormGroup {
                FieldLabel(text: L("pf_skills"))
                ChipRow(
                    options: mySkills.filter { $0.pending != true }.map { (value: String($0.id ?? 0), label: $0.name ?? "") },
                    selected: Set(skillIds.map(String.init)),
                    onToggle: { value in
                        guard let id = Int(value) else { return }
                        if skillIds.contains(id) { skillIds.removeAll { $0 == id } } else if skillIds.count < maxWorkSkills { skillIds.append(id) }
                    }
                )
            }
        }

        FormGroup {
            FieldLabel(text: L("pf_visibility"))
            ChipRow(
                options: [(value: "private", label: L("vis_private")), (value: "clients_only", label: L("vis_clients")), (value: "public", label: L("vis_public"))],
                selected: [visibility],
                onToggle: { visibility = $0 }
            )
            if visibility != "private" {
                HStack(spacing: 10) {
                    RedToggle(on: rights) { rights.toggle() }
                    Text(L("pf_rights")).font(.dorr(13)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                }
                Text(L("portfolio_verified_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
        }

        FormGroup {
            DorrTextField(value: $externalUrl, label: L("pf_external"), placeholder: "https://", icon: "link", keyboard: .URL)
                .environment(\.layoutDirection, .leftToRight)
            DorrTextField(value: $videoUrl, label: L("pf_video"), placeholder: "https://youtu.be/...", icon: "video.fill", keyboard: .URL)
                .environment(\.layoutDirection, .leftToRight)
            if item?.video?.embedUrl != nil { Text(L("pf_video_saved")).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
            Text(L("pf_video_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
        }

        FormGroup {
            FieldLabel(text: L("pf_images") + " (\(imageCount)/\(maxImages))")
            FlowLayout(spacing: 8) {
                ForEach(existing, id: \.id) { image in
                    Thumb(enabled: !busy, onRemove: { deleteExisting(image.id ?? 0) }) {
                        ProfileAuthedImage(path: image.path) { Color.clear }
                    }
                }
                ForEach(pending) { p in
                    Thumb(enabled: !busy, onRemove: { pending.removeAll { $0.id == p.id } }) {
                        if let image = UIImage(data: p.file.data) { Image(uiImage: image).resizable().scaledToFill() }
                    }
                }
            }
            if imageCount < maxImages {
                HStack(spacing: 20) {
                    PhotosPicker(selection: $galleryItem, matching: .images) {
                        Text(L("profile_photo_gallery")).font(.dorr(14, .bold)).foregroundStyle(wa.red)
                    }
                    .buttonStyle(.plain)
                    ProfileTextLink(text: L("profile_photo_camera")) { cameraOpen = true }
                }
            }
        }

        SettingsPrimaryButton(
            text: L("profile_save"),
            enabled: title.trimmingCharacters(in: .whitespacesAndNewlines).count >= 2 && (visibility == "private" || rights),
            loading: busy, action: save
        )
        if currentId != 0 {
            ProfileTextLink(text: L("common_delete")) { confirmDelete = true }.padding(8)
        }
    }

    private func fill(_ dto: PortfolioItemDto) {
        item = dto
        title = dto.title ?? ""
        description = dto.description ?? ""
        completed = dto.completedOn ?? ""
        specialtyId = dto.specialty?.id
        skillIds = (dto.skills ?? []).compactMap(\.id)
        visibility = dto.visibility ?? "private"
        rights = dto.rightsConfirmed ?? false
        externalUrl = dto.externalUrl ?? ""
        videoUrl = ""
    }

    // ---- images ----
    private func take(_ data: Data) {
        Task {
            let name = "work_\(Int64(Date().timeIntervalSince1970 * 1000))"
            let file = await Task.detached { preparePortfolioImage(data, name: name) }.value
            if let file {
                if imageCount < maxImages { pending.append(PendingImage(file: file)) }
            } else {
                error = L("login_error_generic")
            }
        }
    }

    private func deleteExisting(_ media: Int) {
        busy = true
        Task {
            let result = await PortfolioApi.deleteImage(currentId, media)
            busy = false
            if let dto = result.value { item = dto } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }

    // ---- save / delete ----
    private func save() {
        busy = true
        error = nil
        func orNull(_ text: String) -> String? { text.isEmpty ? nil : text }
        // Gson leaves a null value out of the body (the server keeps that field).
        let body: JsonBody = ([
            "title": title.trimmingCharacters(in: .whitespacesAndNewlines),
            "description": orNull(description.trimmingCharacters(in: .whitespacesAndNewlines)),
            "completed_on": orNull(completed),
            "specialty_id": specialtyId,
            "skill_ids": skillIds,
            "visibility": visibility,
            "rights_confirmed": rights && visibility != "private",
            "external_url": orNull(externalUrl.trimmingCharacters(in: .whitespacesAndNewlines)),
            "video_url": orNull(videoUrl.trimmingCharacters(in: .whitespacesAndNewlines)),
        ] as JsonBody).filter { $0.value != nil }
        Task {
            let saved = currentId == 0 ? await PortfolioApi.create(body) : await PortfolioApi.update(currentId, body)
            guard let dto = saved.value else {
                busy = false
                error = saved.errorText(L("login_error_offline")) ?? L("login_error_generic")
                return
            }
            // From here the work exists: a failed image no longer loses the rest.
            currentId = dto.id ?? currentId
            item = dto
            for p in pending {
                let up = await PortfolioApi.addImage(currentId, p.file)
                if let updated = up.value {
                    item = updated
                    pending.removeAll { $0.id == p.id }
                } else {
                    busy = false
                    error = up.errorText(L("login_error_offline")) ?? L("login_error_generic")
                    return
                }
            }
            busy = false
            onBack()
        }
    }

    private func delete() {
        busy = true
        Task {
            let failed = await PortfolioApi.delete(currentId, offline: L("login_error_offline"), generic: L("login_error_generic"))
            busy = false
            if let failed { error = failed } else { onBack() }
        }
    }
}

/// A 78pt rounded image with a small × to remove it.
private struct Thumb<Content: View>: View {
    @Environment(\.wa) private var wa
    let enabled: Bool
    let onRemove: () -> Void
    @ViewBuilder let content: () -> Content

    var body: some View {
        ZStack(alignment: .topTrailing) {
            wa.red.opacity(0.12)
            content().frame(width: 78, height: 78).clipped()
            Button(action: onRemove) {
                Image(systemName: "xmark").font(.system(size: 10, weight: .bold)).foregroundStyle(.white)
                    .frame(width: 22, height: 22)
                    .background(Color.black.opacity(0.55), in: Circle())
            }
            .buttonStyle(.plain)
            .disabled(!enabled)
            .accessibilityLabel(L("common_remove"))
            .padding(3)
        }
        .frame(width: 78, height: 78)
        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
    }
}
