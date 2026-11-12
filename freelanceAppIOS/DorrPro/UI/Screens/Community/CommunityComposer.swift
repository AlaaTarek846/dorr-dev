import PhotosUI
import SwiftUI

// freelanceApp `ui/screens/CommunityComposer.kt`.

/*
 * FL-L12: writing a post (or editing mine). The kind (a post, a question, or a finished work from my portfolio), the text within the
 * server's limits, the specialty (my primary one first), up to 4 images from the gallery, and for a work the item from my portfolio. The
 * community rules show once. Can't write (not verified / muted) → a page saying why.
 */

/// An image picked for a new post: the upload part (scaled JPEG) and its preview.
private struct CmPendingImage: Identifiable {
    let id = UUID()
    let part: FilePart
    let preview: UIImage?
}

/// [editUuid] nil = a new post of [startKind]; else editing that post of mine (its text, specialty and images to remove).
@MainActor
struct CommunityComposeScreen: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var hub = CommunityHub.shared
    let startKind: String
    let editUuid: String?
    let nav: CommunityNav
    let onBack: () -> Void

    @State private var kind: String
    @State private var bodyText = ""
    @State private var specialtyId: Int?
    @State private var specialtyTouched = false
    @State private var workUuid: String?
    @State private var pending: [CmPendingImage] = []
    @State private var removed: [Int] = []
    @State private var filled = false

    @State private var original: CommunityPostDto?
    @State private var loadFailed: String?
    @State private var portfolio: [PortfolioItemDto]?
    @State private var busy = false
    @State private var inlineError: String?
    @State private var showRules = false
    @State private var pickingSpecialty = false
    @State private var confirmLeave = false
    @State private var picked: [PhotosPickerItem] = []
    @State private var galleryOpen = false

    init(startKind: String, editUuid: String?, nav: CommunityNav, onBack: @escaping () -> Void) {
        self.startKind = startKind
        self.editUuid = editUuid
        self.nav = nav
        self.onBack = onBack
        _kind = State(initialValue: ["post", "question", "work"].contains(startKind) ? startKind : "post")
    }

    private var editing: Bool { editUuid != nil }
    private var offlineText: String { L("login_error_offline") }
    private var genericError: String { L("login_error_generic") }

    private var limits: CommunityLimitsDto? { hub.home?.limits }
    private var maxImages: Int { limits?.maxImages ?? 4 }
    private var existing: [CommunityImageDto] { (original?.images ?? []).filter { !removed.contains($0.id ?? -1) } }
    private var imageCount: Int { existing.count + pending.count }
    private var text: String { bodyText.trimmingCharacters(in: .whitespacesAndNewlines) }
    private var bodyMin: Int { limits?.bodyMin ?? 10 }
    private var bodyMax: Int { limits?.bodyMax ?? 3000 }
    private var postsLeft: Int? { limits.map { max(($0.postsPerDay ?? 5) - ($0.postsToday ?? 0), 0) } }
    private var dayFull: Bool { !editing && postsLeft == 0 }
    private var ready: Bool {
        !busy && (bodyMin...max(bodyMin, bodyMax)).contains(text.count) && (editing || kind != "work" || workUuid != nil) && !dayFull
            && (!editing || original != nil)
    }
    private var dirty: Bool { !text.isEmpty || !pending.isEmpty || workUuid != nil }

    var body: some View {
        CommunityFrame(title: L(editing ? "cm_edit_title" : "cm_new_title"), onBack: { if dirty && !editing { confirmLeave = true } else { onBack() } }, actions: {
            if hub.home == nil || hub.home?.canWrite == true {
                Button(action: submit) {
                    ZStack {
                        if busy {
                            SmallSpinner(color: .white, size: 16)
                        } else {
                            Text(L(editing ? "cm_save_changes" : "cm_publish")).font(.dorr(14, .heavy)).foregroundStyle(.white)
                        }
                    }
                    .padding(.horizontal, 16)
                    .frame(height: 36)
                    .background(ready ? wa.red : wa.red.opacity(0.35), in: Capsule())
                }
                .buttonStyle(.plain)
                .disabled(!ready)
            }
        }) {
            if let h = hub.home, h.canWrite != true {
                CannotWrite(home: h, onVerify: nav.openVerification)
            } else if loadFailed != nil && original == nil {
                EmptyState(icon: "info.circle.fill", title: L("cm_load_failed"), body: loadFailed ?? "").padding(.top, 80)
            } else if (editing && original == nil) || hub.home == nil {
                VStack(spacing: 12) {
                    SkeletonBlock(height: 64, radius: 18)
                    SkeletonBlock(height: 80, radius: 18)
                    SkeletonBlock(height: 200, radius: 18)
                }
                .padding(14)
            } else if let h = hub.home {
                form(h)
            }
        }
        .task { if CommunityHub.shared.home == nil { await CommunityHub.shared.refresh() } }
        // Editing: the post as it is now; its text fills the field once.
        .task(id: editUuid) { await loadOriginal() }
        // A new post starts on my primary specialty; the rules show the first time.
        .task(id: hub.home) { homeArrived() }
        .task(id: kind) { await loadPortfolio() }
        .photosPicker(isPresented: $galleryOpen, selection: $picked, matching: .images)
        .onChange(of: picked) { items in
            guard !items.isEmpty else { return }
            picked = []
            addImages(items)
        }
        .cmSheet(isPresented: $pickingSpecialty) {
            SpecialtySheet(home: hub.home, selected: specialtyId, allowAll: false, onPick: {
                specialtyId = $0
                specialtyTouched = true
                pickingSpecialty = false
            }, onDismiss: { pickingSpecialty = false })
        }
        .cmSheet(isPresented: Binding(get: { showRules }, set: { if !$0 { rulesDone() } })) {
            RulesSheet(rules: hub.home?.rules ?? [], onDone: rulesDone)
        }
        .dorrDialog(isPresented: $confirmLeave) {
            ConfirmDialog(icon: "xmark", title: L("cm_leave_title"), message: L("cm_leave_body"), confirmLabel: L("cm_leave_yes"),
                          onConfirm: {
                              confirmLeave = false
                              leave()
                          }, onDismiss: { confirmLeave = false })
        }
    }

    // MARK: the form

    private func form(_ homeNow: CommunityHomeDto) -> some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                meRow(homeNow)
                if editing {
                    if let o = original { KindPill(kind: o.kind, label: o.kindLabel) }
                } else {
                    HStack(spacing: 8) {
                        KindCard(key: "post", icon: "square.and.pencil", tone: wa.red, title: L("cm_kind_post"), hint: L("cm_kind_post_hint"), on: kind == "post") { pickKind("post") }
                        KindCard(key: "question", icon: "bubble.left.and.bubble.right.fill", tone: QUESTION_TONE, title: L("cm_kind_question"),
                                 hint: L("cm_kind_question_hint"), on: kind == "question") { pickKind("question") }
                        KindCard(key: "work", icon: "briefcase.fill", tone: WORK_TONE, title: L("cm_kind_work"), hint: L("cm_kind_work_hint"), on: kind == "work") { pickKind("work") }
                    }
                }
                textBlock
                if kind == "work" {
                    if editing {
                        if let w = original?.work { WorkCard(work: w) }
                    } else {
                        WorkPicker(items: portfolio, selected: workUuid, onPick: {
                            workUuid = $0
                            inlineError = nil
                        }, onOpenPortfolio: nav.openPortfolio)
                    }
                }
                imagesBlock
                HStack(spacing: 0) {
                    if !editing, let left = postsLeft {
                        Text(dayFull ? L("cm_day_full", limits?.postsPerDay ?? 5) : L("cm_day_left", left))
                            .font(.dorr(12, .semibold)).foregroundStyle(dayFull ? Color(hex: 0xDC2626) : wa.settingsMut)
                    }
                    Spacer(minLength: 0)
                    Button { showRules = true } label: {
                        HStack(spacing: 5) {
                            Image(systemName: "hammer.fill").font(.system(size: 13))
                            Text(L("cm_rules_link")).font(.dorr(12.5, .heavy))
                        }
                        .foregroundStyle(wa.red)
                        .padding(.horizontal, 8).padding(.vertical, 6)
                        .contentShape(Capsule())
                    }
                    .buttonStyle(.plain)
                }
                SettingsPrimaryButton(text: L(editing ? "cm_save_changes" : "cm_publish"), enabled: ready, loading: busy, action: submit)
            }
            .padding(.horizontal, 14)
            .padding(.bottom, 28)
        }
        .scrollDismissesKeyboard(.interactively)
    }

    private func meRow(_ homeNow: CommunityHomeDto) -> some View {
        let me = homeNow.me ?? CommunityMemberDto()
        let name = specialtyId.flatMap { id in homeNow.specialties?.first { $0.id == id }?.name ?? me.specialties?.first { $0.id == id }?.name }
        return HStack(spacing: 10) {
            AvatarImage(url: me.avatarUrl, size: 46)
            VStack(alignment: .leading, spacing: 5) {
                NameWithBadge(name: me.name, badge: me.badge)
                Button { pickingSpecialty = true } label: {
                    HStack(spacing: 5) {
                        Image(systemName: "tag.fill").font(.system(size: 12))
                        Text(verbatim: name ?? L("cm_pick_specialty")).font(.dorr(12, .bold)).lineLimit(1)
                        Image(systemName: "chevron.down").font(.system(size: 11, weight: .bold))
                    }
                    .foregroundStyle(wa.red)
                    .padding(.horizontal, 10).padding(.vertical, 5)
                    .overlay(Capsule().stroke(wa.red.opacity(0.5), lineWidth: 1))
                    .contentShape(Capsule())
                }
                .buttonStyle(.plain)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(12)
        .settingsSurface(20, elevation: 3)
    }

    private var textBlock: some View {
        let danger = Color(hex: 0xDC2626)
        let hint: String = {
            switch kind {
            case "question": return L("cm_body_hint_question")
            case "work": return L("cm_body_hint_work")
            default: return L("cm_body_hint_post")
            }
        }()
        return VStack(alignment: .leading, spacing: 6) {
            DorrTextField(value: Binding(get: { bodyText }, set: {
                bodyText = String($0.prefix(bodyMax))
                inlineError = nil
            }), placeholder: hint, singleLine: false, minLines: 7, error: inlineError != nil)
            HStack(spacing: 0) {
                if text.count < bodyMin {
                    Text(L("cm_body_min", bodyMin)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                }
                Spacer(minLength: 0)
                Text(verbatim: "\u{200E}\(bodyText.count) / \(bodyMax)").font(.dorr(11.5, .semibold))
                    .foregroundStyle(bodyText.count >= bodyMax ? danger : wa.settingsMut)
            }
            .padding(.horizontal, 6)
            if let msg = inlineError {
                HStack(spacing: 8) {
                    Image(systemName: "lock.fill").font(.system(size: 15)).foregroundStyle(danger)
                    Text(verbatim: msg).font(.dorr(13, .semibold)).foregroundStyle(danger).lineSpacing(4)
                    Spacer(minLength: 0)
                }
                .padding(12)
                .background(danger.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(danger.opacity(0.35), lineWidth: 1))
            }
        }
    }

    private var imagesBlock: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 6) {
                Image(systemName: "photo.badge.plus").font(.system(size: 15)).foregroundStyle(wa.red)
                Text(L("cm_images_title")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: "\u{200E}\(imageCount) / \(maxImages)").font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
            }
            ImageThumbs(
                existing: existing, pending: pending, canAdd: !editing && imageCount < maxImages, busy: busy,
                onRemoveExisting: { removed.append($0) }, onRemovePending: { id in pending.removeAll { $0.id == id } },
                onAdd: { galleryOpen = true }
            )
            if editing { Text(L("cm_images_edit_note")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut) }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20, elevation: 3)
    }

    // MARK: actions

    private func pickKind(_ k: String) {
        kind = k
        inlineError = nil
    }

    private func loadOriginal() async {
        guard let editUuid else { return }
        let r = await CommunityApi.post(editUuid)
        if case .ok(let page, _) = r {
            original = page.data.post
            if !filled {
                bodyText = page.data.post?.body ?? ""
                specialtyId = page.data.post?.specialty?.id
                kind = page.data.post?.kind ?? kind
                filled = true
            }
        } else {
            loadFailed = r.errorText(offlineText) ?? genericError
        }
    }

    private func homeArrived() {
        guard let h = hub.home else { return }
        if !editing && !specialtyTouched && specialtyId == nil {
            specialtyId = h.me?.specialties?.first { $0.primary == true }?.id ?? h.mySpecialties?.first
        }
        if h.canWrite == true && !editing && !CommunityHub.shared.rulesSeen() { showRules = true }
    }

    private func loadPortfolio() async {
        guard kind == "work" && !editing && portfolio == nil else { return }
        if case .ok(let list, _) = await PortfolioApi.list() {
            portfolio = list.filter { $0.uuid != nil && $0.hidden != true && $0.withdrawnByClient != true && $0.visibility != "private" }
        } else {
            portfolio = []
        }
    }

    private func addImages(_ items: [PhotosPickerItem]) {
        let room = max(maxImages - imageCount, 0)
        if items.count > room { AppToast.show(L("cm_images_max", maxImages)) }
        let stamp = Int(Date().timeIntervalSince1970 * 1000)
        for (i, item) in items.prefix(room).enumerated() {
            Task {
                let data = try? await item.loadTransferable(type: Data.self)
                let part = data.flatMap { preparePortfolioImage($0, name: "community_\(stamp)_\(i)") }
                guard let part else {
                    AppToast.show(genericError)
                    return
                }
                if pending.count + existing.count < maxImages {
                    pending.append(CmPendingImage(part: part.asField("images[]"), preview: UIImage(data: part.data)))
                }
            }
        }
    }

    private func rulesDone() {
        CommunityHub.shared.markRulesSeen()
        showRules = false
    }

    private func leave() {
        pending.removeAll()
        onBack()
    }

    private func submit() {
        if !ready { return }
        busy = true
        inlineError = nil
        Task {
            let r: ApiResult<CommunityPostDto>
            if let editUuid {
                r = await CommunityApi.update(editUuid, CommunityPostUpdate(body: text, specialtyId: specialtyId, removeImages: removed.isEmpty ? nil : removed))
            } else {
                var fields = ["kind": kind, "body": text]
                if let specialtyId { fields["specialty_id"] = String(specialtyId) }
                if kind == "work", let workUuid { fields["portfolio_item"] = workUuid }
                r = await CommunityApi.create(fields, pending.map(\.part))
            }
            busy = false
            switch r {
            case .ok(let post, let message):
                pending.removeAll()
                CommunityHub.shared.emit(editing ? .postUpdated(post) : .postCreated(post))
                if let message, !message.trimmingCharacters(in: .whitespaces).isEmpty { AppToast.show(message) }
                Task { await CommunityHub.shared.refresh() }
                onBack()
            default:
                let msg = r.errorText(offlineText) ?? genericError
                // The server's refusals of what I wrote (contact details, too short, a bad work) show under the text; anything else as a toast.
                if r.error?.httpStatus == 422 { inlineError = msg } else { AppToast.show(msg) }
            }
        }
    }
}

@MainActor
private struct KindCard: View {
    @Environment(\.wa) private var wa
    let key: String
    let icon: String
    let tone: Color
    let title: String
    let hint: String
    let on: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(spacing: 5) {
                Image(systemName: icon).font(.system(size: 16, weight: .semibold)).foregroundStyle(on ? .white : tone)
                    .frame(width: 36, height: 36)
                    .background(tone.opacity(on ? 1 : 0.12), in: Circle())
                    .accessibilityLabel(key)
                Text(verbatim: title).font(.dorr(13, .heavy)).foregroundStyle(on ? tone : wa.settingsInk).lineLimit(1)
                Text(verbatim: hint).font(.dorr(10.5)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).lineLimit(2)
            }
            .padding(.horizontal, 8)
            .padding(.vertical, 12)
            .frame(maxWidth: .infinity)
            .background(on ? tone.opacity(wa.night ? 0.22 : 0.1) : wa.settingsCard, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous)
                .stroke(on ? tone : (wa.night ? wa.line : Color(hex: 0xE5E7EB)), lineWidth: on ? 2 : 1))
            .contentShape(RoundedRectangle(cornerRadius: 16))
        }
        .buttonStyle(.plain)
    }
}

@MainActor
private struct ImageThumbs: View {
    @Environment(\.wa) private var wa
    let existing: [CommunityImageDto]
    let pending: [CmPendingImage]
    let canAdd: Bool
    let busy: Bool
    let onRemoveExisting: (Int) -> Void
    let onRemovePending: (UUID) -> Void
    let onAdd: () -> Void

    var body: some View {
        let fill = wa.night ? wa.darkWell : wa.red.opacity(0.06)
        FlowLayout(spacing: 8) {
            ForEach(existing, id: \.id) { img in
                Thumb(onRemove: busy ? nil : { onRemoveExisting(img.id ?? -1) }) { CommunityPhoto(image: img).background(fill) }
            }
            ForEach(pending) { p in
                Thumb(onRemove: busy ? nil : { onRemovePending(p.id) }) {
                    ZStack {
                        fill
                        if let preview = p.preview { Image(uiImage: preview).resizable().scaledToFill() }
                    }
                }
            }
            if canAdd {
                Button(action: onAdd) {
                    VStack(spacing: 4) {
                        Image(systemName: "photo.badge.plus").font(.system(size: 22)).foregroundStyle(wa.red)
                        Text(L("cm_add_image")).font(.dorr(11, .bold)).foregroundStyle(wa.red)
                    }
                    .frame(width: 92, height: 92)
                    .background(fill, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.red.opacity(0.45), lineWidth: 1.5))
                    .contentShape(RoundedRectangle(cornerRadius: 14))
                }
                .buttonStyle(.plain)
                .disabled(busy)
            }
        }
    }
}

@MainActor
private struct Thumb<Content: View>: View {
    let onRemove: (() -> Void)?
    @ViewBuilder let content: () -> Content

    var body: some View {
        ZStack(alignment: .topTrailing) {
            content().frame(width: 92, height: 92).clipped()
            if let onRemove {
                Button(action: onRemove) {
                    Image(systemName: "xmark").font(.system(size: 11, weight: .bold)).foregroundStyle(.white)
                        .frame(width: 24, height: 24)
                        .background(Color.black.opacity(0.6), in: Circle())
                }
                .buttonStyle(.plain)
                .padding(5)
                .accessibilityLabel(L("common_remove"))
            }
        }
        .frame(width: 92, height: 92)
        .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// For a «work» post: one item of my portfolio that others may see (not «only me», not hidden, not taken back by its client).
@MainActor
private struct WorkPicker: View {
    @Environment(\.wa) private var wa
    let items: [PortfolioItemDto]?
    let selected: String?
    let onPick: (String) -> Void
    let onOpenPortfolio: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 6) {
                Image(systemName: "briefcase.fill").font(.system(size: 15)).foregroundStyle(WORK_TONE)
                Text(L("cm_pick_work")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
            }
            if let items {
                if items.isEmpty {
                    HStack(spacing: 10) {
                        Image(systemName: "briefcase").font(.system(size: 24)).foregroundStyle(wa.settingsMut)
                        Text(L("cm_no_works")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    PillButton(label: L("cm_open_portfolio"), icon: "briefcase.fill", filled: false, action: onOpenPortfolio)
                } else {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 10) {
                            ForEach(Array(items.enumerated()), id: \.offset) { _, item in tile(item) }
                        }
                    }
                    Text(L("cm_works_note")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                }
            } else {
                HStack(spacing: 10) { ForEach(0..<3, id: \.self) { _ in SkeletonBlock(height: 130, radius: 14, width: 130) } }
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20, elevation: 3)
    }

    private func tile(_ item: PortfolioItemDto) -> some View {
        let on = item.uuid == selected
        return Button { if let uuid = item.uuid { onPick(uuid) } } label: {
            VStack(alignment: .leading, spacing: 0) {
                ZStack(alignment: .topTrailing) {
                    ZStack {
                        WORK_TONE.opacity(0.1)
                        if let cover = item.images?.first?.path {
                            ProfileAuthedImage(path: cover) {
                                Image(systemName: "briefcase.fill").font(.system(size: 24)).foregroundStyle(WORK_TONE)
                            }
                        } else {
                            Image(systemName: "briefcase.fill").font(.system(size: 24)).foregroundStyle(WORK_TONE)
                        }
                    }
                    .frame(width: 130, height: 90)
                    .clipped()
                    if on {
                        Image(systemName: "checkmark.circle.fill").font(.system(size: 20)).foregroundStyle(WORK_TONE)
                            .frame(width: 22, height: 22)
                            .background(Color.white, in: Circle())
                            .padding(6)
                    }
                }
                Text(verbatim: item.title ?? "").font(.dorr(12.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    .padding(8)
                    .frame(width: 130, alignment: .leading)
            }
            .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous)
                .stroke(on ? WORK_TONE : (wa.night ? wa.line : Color(hex: 0xE5E7EB)), lineWidth: on ? 2 : 1))
            .contentShape(RoundedRectangle(cornerRadius: 14))
        }
        .buttonStyle(.plain)
    }
}

/// Not verified, or muted by the staff until a date: reading stays open, writing waits.
@MainActor
private struct CannotWrite: View {
    let home: CommunityHomeDto
    let onVerify: () -> Void

    var body: some View {
        let muted = home.muted
        let text: String = {
            guard let muted else { return L("cm_need_verified_body") }
            var out = L("cm_muted_until", cmDate(muted.until))
            if let reason = muted.reason, !reason.trimmingCharacters(in: .whitespaces).isEmpty { out += "\n" + L("cm_muted_reason", reason) }
            return out
        }()
        VStack(spacing: 0) {
            EmptyState(icon: muted != nil ? "lock.fill" : "checkmark.shield.fill", title: L("cm_cannot_write_title"), body: text)
            if muted == nil {
                PillButton(label: L("cm_verify_now"), icon: "checkmark.shield.fill", filled: true, action: onVerify).padding(.top, 18)
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 24)
        .padding(.top, 70)
    }
}

/// The community rules, from the server, shown once before the first post (and from «community rules» any time).
@MainActor
private struct RulesSheet: View {
    @Environment(\.wa) private var wa
    let rules: [String]
    let onDone: () -> Void

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                HStack(spacing: 12) {
                    Image(systemName: "hammer.fill").font(.system(size: 20)).foregroundStyle(wa.red)
                        .frame(width: 48, height: 48)
                        .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 15, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("cm_rules_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                        Text(L("cm_rules_sub")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                    }
                    Spacer(minLength: 0)
                }
                ForEach(Array(rules.enumerated()), id: \.offset) { i, rule in
                    HStack(alignment: .top, spacing: 10) {
                        Text(verbatim: "\(i + 1)").font(.dorr(12, .heavy)).foregroundStyle(.white)
                            .frame(width: 24, height: 24)
                            .background(wa.red, in: Circle())
                        Text(verbatim: rule).font(.dorr(13.5)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                }
                SettingsPrimaryButton(text: L("cm_rules_ok"), action: onDone).padding(.top, 4)
            }
            .padding(.horizontal, 20)
            .padding(.top, 24)
            .padding(.bottom, 24)
        }
        .background(wa.settingsCard.ignoresSafeArea())
    }
}
