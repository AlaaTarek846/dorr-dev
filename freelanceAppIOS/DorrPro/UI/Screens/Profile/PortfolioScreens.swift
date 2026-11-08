import PhotosUI
import SwiftUI

// freelanceApp `ui/screens/PortfolioScreens.kt`.

private let maxWorks = 30
private let maxImages = 10
private let maxWorkSkills = 10

/// FS-209c: where the works are kept — my own (`profile/portfolio`) or a team's (`teams/{team}/portfolio`, the same fields without the
/// agreement link). The screens ask this one thing and don't care which.
private struct PortfolioSource {
    let team: String?

    func list() async -> ApiResult<[PortfolioItemDto]> {
        if let team { return await TeamPortfolioApi.list(team) }
        return await PortfolioApi.list()
    }

    func order(_ ids: [Int]) async -> ApiResult<[PortfolioItemDto]> {
        if let team { return await TeamPortfolioApi.order(team, PortfolioOrderRequest(ids: ids)) }
        return await PortfolioApi.order(PortfolioOrderRequest(ids: ids))
    }

    func create(_ body: JsonBody) async -> ApiResult<PortfolioItemDto> {
        if let team { return await TeamPortfolioApi.create(team, body) }
        return await PortfolioApi.create(body)
    }

    func update(_ id: Int, _ body: JsonBody) async -> ApiResult<PortfolioItemDto> {
        if let team { return await TeamPortfolioApi.update(team, id, body) }
        return await PortfolioApi.update(id, body)
    }

    func delete(_ id: Int, offline: String, generic: String) async -> String? {
        if let team { return await TeamPortfolioApi.delete(team, id, offline: offline, generic: generic) }
        return await PortfolioApi.delete(id, offline: offline, generic: generic)
    }

    func addImage(_ id: Int, _ image: FilePart) async -> ApiResult<PortfolioItemDto> {
        if let team { return await TeamPortfolioApi.addImage(team, id, image) }
        return await PortfolioApi.addImage(id, image)
    }

    func deleteImage(_ id: Int, _ media: Int) async -> ApiResult<PortfolioItemDto> {
        if let team { return await TeamPortfolioApi.deleteImage(team, id, media) }
        return await PortfolioApi.deleteImage(id, media)
    }
}

/**
 * My portfolio as a tab: my works (first image, title, who can see it), add one, and put them in order. Up to 30.
 * With `team` it is that team's: everybody in the team reads it, and only an Owner (of an active team) adds, changes and orders.
 */
struct PortfolioTab: View {
    let onBack: () -> Void
    let onOpenItem: (Int) -> Void
    let team: String?

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor

    private let src: PortfolioSource
    @State private var items: [PortfolioItemDto] = []
    @State private var loaded = false
    @State private var error: String?
    // A team's works are changed by its Owners only (and not while the team is suspended); my own always by me.
    @State private var canEdit: Bool

    init(onBack: @escaping () -> Void, onOpenItem: @escaping (Int) -> Void, team: String? = nil) {
        self.onBack = onBack
        self.onOpenItem = onOpenItem
        self.team = team
        src = PortfolioSource(team: team)
        _canEdit = State(initialValue: team == nil)
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                SubHeader(title: L(team == nil ? "portfolio_title" : "tpf_title"), onBack: onBack)
                VStack(alignment: .leading, spacing: 12) {
                    Text(L(team == nil ? "portfolio_hint" : (canEdit ? "tpf_hint" : "tpf_hint_member"))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    if !loaded && error == nil { SkeletonList() }
                    if loaded {
                        if canEdit && items.count < maxWorks { SettingsPrimaryButton(text: L("portfolio_add")) { onOpenItem(0) } }
                        if items.isEmpty {
                            EmptyState(icon: "photo.on.rectangle.angled", title: L(team == nil ? "portfolio_empty" : "tpf_empty"), body: L(team == nil ? "portfolio_empty_body" : "tpf_empty_body"))
                        }
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
        .task(id: "\(team ?? "")|\(network.reconnectTick)") {
            if let team, let mine = (await TeamsApi.profile(team)).value?.team {
                canEdit = mine.me?.role == "owner" && mine.status == "active"
            }
            let result = await src.list()
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
                if item.withdrawnByClient == true {
                    Text(L("rights_pf_withdrawn")).font(.dorr(11)).foregroundStyle(Color(hex: 0xD97706)).lineLimit(2)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if canEdit {
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
        }
        .padding(10)
        .frame(maxWidth: .infinity)
        .settingsSurface(16)
        .contentShape(Rectangle())
        .onTapGesture { if canEdit { onOpenItem(item.id ?? 0) } }
    }

    private func move(_ index: Int, _ by: Int) {
        let to = index + by
        guard items.indices.contains(to) else { return }
        let moved = items.remove(at: index)
        items.insert(moved, at: to)
        let ids = items.compactMap(\.id)
        Task {
            let result = await src.order(ids)
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
    /// FS-209c: a team's work when set (the team's uuid), mine when nil.
    let team: String?

    @Environment(\.wa) private var wa

    private let src: PortfolioSource
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
    // FL-L13: the finished work this one comes from (only one its client allowed), listed from the server.
    @State private var agreementUuid: String?
    @State private var sources: [PortfolioSourceDto] = []
    /// Android `rememberRightsOptions()`: `value` is nil while it loads and where the feature is off.
    @StateObject private var rightsOptions = RightsOptionsState()
    @State private var externalUrl = ""
    @State private var videoUrl = ""
    @State private var pending: [PendingImage] = []
    @State private var busy = false
    @State private var error: String?
    @State private var confirmDelete = false
    @State private var galleryItem: PhotosPickerItem?
    @State private var cameraOpen = false

    init(itemId: Int, onBack: @escaping () -> Void, team: String? = nil) {
        self.itemId = itemId
        self.onBack = onBack
        self.team = team
        src = PortfolioSource(team: team)
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
        .dorrDialog(isPresented: $confirmDelete) {
            AlertDialog(
                onDismissRequest: { confirmDelete = false },
                text: { Text(L("common_delete_confirm")) },
                confirmButton: { DialogButton(onClick: { confirmDelete = false; delete() }) { Text(L("common_delete")) } },
                dismissButton: { DialogButton(onClick: { confirmDelete = false }) { Text(L("common_cancel")) } }
            )
        }
        .rememberRightsOptions(rightsOptions)
        .task(id: rightsOptions.value != nil) {
            if rightsOptions.value != nil && itemId == 0 && team == nil, let list = (await RightsApi.portfolioSources()).value { sources = list.filter { $0.used != true } }
        }
        // One read of each list when the page opens. A team's work is filed under what the team covers (the union of its people's).
        .task {
            if let team {
                if let t = (await TeamsApi.profile(team)).value?.team {
                    specialties = (t.specialties ?? []).compactMap { n in n.name.map { MySpecialtyDto(id: n.id, name: $0) } }
                    mySkills = (t.skills ?? []).compactMap { n in n.name.map { MySkillDto(id: n.id, name: $0) } }
                }
            } else {
                if let list = await ListsApi.mySpecialties().value { specialties = list }
                if let list = await ListsApi.mySkills().value { mySkills = list }
            }
            if itemId != 0 {
                let result = await src.list()
                if let list = result.value {
                    if let found = list.first(where: { $0.id == currentId }) {
                        // What the work is already filed under stays pickable even when the team no longer covers it.
                        if let sp = found.specialty, !specialties.contains(where: { $0.id == sp.id }) {
                            specialties.append(MySpecialtyDto(id: sp.id, name: sp.name ?? ""))
                        }
                        for sk in found.skills ?? [] where !mySkills.contains(where: { $0.id == sk.id }) {
                            mySkills.append(MySkillDto(id: sk.id, name: sk.name ?? ""))
                        }
                        fill(found)
                    }
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
        // FL-L13: a finished work may be shown only when its client allowed it.
        if rightsOptions.value != nil && currentId == 0 && team == nil {
            FormGroup {
                FieldLabel(text: L("rights_pf_pick_title"))
                if sources.isEmpty {
                    Text(L("rights_pf_none")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                } else {
                    ChipRow(
                        options: sources.map { (value: $0.uuid ?? "", label: $0.title ?? "") },
                        selected: Set([agreementUuid].compactMap { $0 }),
                        onToggle: { id in
                            agreementUuid = agreementUuid == id ? nil : id
                            if agreementUuid != nil && title.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                                title = sources.first { $0.uuid == id }?.title ?? ""
                            }
                        }
                    )
                }
                Text(L("rights_pf_pick_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
        }
        if let linked = item, linked.agreement != nil {
            FormGroup {
                Text(L("rights_pf_linked")).font(.dorr(12, .semibold)).foregroundStyle(wa.red)
                if linked.withdrawnByClient == true {
                    Text(L("rights_pf_withdrawn")).font(.dorr(12)).foregroundStyle(Color(hex: 0xD97706)).lineSpacing(3)
                }
            }
        }
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
        agreementUuid = dto.agreement
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
            let result = await src.deleteImage(currentId, media)
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
        var body: JsonBody = ([
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
        if currentId == 0, let agreementUuid { body["agreement_uuid"] = agreementUuid }
        Task {
            let saved = currentId == 0 ? await src.create(body) : await src.update(currentId, body)
            guard let dto = saved.value else {
                busy = false
                error = saved.errorText(L("login_error_offline")) ?? L("login_error_generic")
                return
            }
            // From here the work exists: a failed image no longer loses the rest.
            currentId = dto.id ?? currentId
            item = dto
            for p in pending {
                let up = await src.addImage(currentId, p.file)
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
            let failed = await src.delete(currentId, offline: L("login_error_offline"), generic: L("login_error_generic"))
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
