import PhotosUI
import SwiftUI

// androidApp `moments/MomentsTogether.kt` — DORR Moments together and kept: group cards everyone signs (spec 163) and
// capsules — albums of the messages I chose to keep for an occasion (166).

private func capsuleDay(_ iso: String?) -> String {
    guard let iso, let d = parseRideTime(iso) else { return "" }
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.dateStyle = .medium
    return f.string(from: d)
}

/// Kept files: photos as thumbnails, voices as play buttons.
private struct KeptFiles: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var voice = VoicePlayer.shared
    let files: [CollabFileDto]
    let key: String

    var body: some View {
        if !files.isEmpty {
            HStack(spacing: 6) {
                ForEach(Array(files.enumerated()), id: \.offset) { i, f in
                    if f.mimeType?.hasPrefix("image/") == true {
                        ChatImage(url: ApiClient.mediaUrl(f.url)).frame(width: 72, height: 72).clipShape(RoundedRectangle(cornerRadius: 12))
                    } else {
                        let id = "\(key)#\(i)"
                        Button { if let u = ApiClient.mediaUrl(f.url) { voice.toggle(id, u) } } label: {
                            Image(systemName: voice.isPlaying(id) ? "pause.fill" : "play.fill").font(.system(size: 18)).foregroundStyle(.white).frame(width: 40, height: 40).background(wa.red, in: Circle())
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
            .padding(.top, 6)
        }
    }
}

// MARK: - group cards

struct CollabCardsPage: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let onOpen: (String) -> Void
    @State private var cards: [CollabCardDto]?
    @State private var creating = false

    var body: some View {
        WaPage(title: L("mo_collab_title"), onBack: onBack, hasCta: true, actions: { EmptyView() }, cta: {
            WaButton(text: L("mo_collab_new"), icon: "plus") { creating = true }
        }) {
            Text(L("mo_collab_sub")).font(.dorr(13)).foregroundStyle(wa.mut).padding(.bottom, 10)
            if let list = cards {
                if list.isEmpty {
                    WaEmpty(icon: "person.3.fill", tone: .gray, title: L("mo_collab_empty"), text: L("mo_collab_empty_sub")) { EmptyView() }
                }
                ForEach(Array(list.enumerated()), id: \.element.id) { i, c in
                    let members = c.members ?? []
                    Button { onOpen(c.id) } label: {
                        HStack(spacing: 12) {
                            Text(verbatim: c.look?.emoji ?? "✨").font(.system(size: 24)).frame(width: 50, height: 50)
                                .background(momentGradient(c.look?.primaryColor, c.look?.secondaryColor), in: RoundedRectangle(cornerRadius: 14))
                            VStack(alignment: .leading, spacing: 1) {
                                Text(verbatim: c.title).font(.dorr(15, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                                Text(verbatim: L("mo_collab_for", c.recipient?.name ?? "") + "  ·  " + L("mo_collab_signed", members.filter { $0.signed == true }.count, members.count))
                                    .font(.dorr(12.5)).foregroundStyle(wa.mut).lineLimit(1)
                            }
                            Spacer()
                            if c.status == "sent" { Image(systemName: "checkmark.circle.fill").font(.system(size: 18)).foregroundStyle(Color(hex: 0x10B981)) }
                        }
                        .padding(12)
                        .background(wa.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    .padding(.bottom, 8)
                    .waRise(i)
                }
            } else {
                ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 84).padding(.bottom, 10) }
            }
        }
        .task { cards = (try? await MomentsChatApi.collabCards().data) ?? [] }
        .sheet(isPresented: $creating) {
            CreateCollabSheet(onDismiss: { creating = false }) { id in
                creating = false
                onOpen(id)
            }
            .dorrThemed()
        }
    }
}

private struct CreateCollabSheet: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var store = MomentsStore.shared
    let onDismiss: () -> Void
    let onCreated: (String) -> Void
    @State private var choice: CardChoice?
    @State private var recipient: ContactDto?
    @State private var title = ""
    @State private var members: [Int] = []
    @State private var picking = false
    @State private var contacts: [ContactDto] = []
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        let choices = cardChoices(store.center)
        ScrollView {
            VStack(alignment: .leading, spacing: 10) {
                Text(L("mo_collab_new")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                if let c = choice {
                    CardFace(look: {
                        var look = c.look
                        look.title = title.trimmingCharacters(in: .whitespaces).isEmpty ? c.name : title.trimmingCharacters(in: .whitespaces)
                        return look
                    }())
                }
                SelectRow(icon: "sparkles", title: L("mo_occasion"), value: choice.map { "\($0.look.emoji ?? "✨")  \($0.name)" } ?? "",
                          options: choices.map { c in ("\(c.look.emoji ?? "✨")  \(c.name)", { choice = c }) })
                ChField(placeholder: L("mo_card_title_hint"), text: $title, icon: "pencil", limit: 120)
                SelectRow(icon: "person.fill", title: L("mo_collab_recipient"), value: recipient?.name ?? L("mo_collab_pick_person"), options: contacts.map { c in
                    (c.name, {
                        recipient = c
                        if let id = c.profile?.id { members.removeAll { $0 == id } }
                    })
                })
                Button { picking = true } label: {
                    HStack(spacing: 12) {
                        Image(systemName: "person.badge.plus").font(.system(size: 16)).foregroundStyle(wa.red)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("mo_collab_invite")).font(.dorr(11.5)).foregroundStyle(wa.mut)
                            Text(L("mo_collab_members", members.count)).font(.dorr(15, .bold)).foregroundStyle(wa.ink)
                        }
                        Spacer()
                    }
                    .padding(.horizontal, 16).padding(.vertical, 12)
                    .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                }
                .buttonStyle(.plain)
                WaError(message: error)
                WaButton(text: L("mo_collab_start"), icon: "person.3.fill", enabled: !busy && recipient != nil && choice != nil, loading: busy, action: start).padding(.top, 6)
            }
            .padding(20)
        }
        .task {
            if store.center == nil { await store.refresh() }
            if choice == nil { choice = cardChoices(store.center).first }
            contacts = ((try? await ChatContactsApi.contacts(registered: 1).data) ?? []).filter { $0.profile != nil }
        }
        .sheet(isPresented: $picking) {
            PeoplePickerSheet(initial: Set(members), onDismiss: { picking = false }) { ids in
                members = ids
                picking = false
            }
            .chatAppearance()
        }
    }

    private func start() {
        guard let rid = recipient?.profile?.id else { return }
        busy = true
        error = nil
        Task {
            var body: [String: JSONValue] = ["recipient_id": .int(rid), "members": .array(members.filter { $0 != rid }.map { .int($0) })]
            if let m = choice?.momentId { body["moment_id"] = .int(m) }
            if let k = choice?.kind { body["personal_kind"] = .string(k) }
            let t = title.trimmingCharacters(in: .whitespaces)
            if !t.isEmpty { body["title"] = .string(t) }
            do { if let c = try await MomentsChatApi.createCollab(body).data { onCreated(c.id) } } catch { self.error = error.apiFailure.message }
            busy = false
        }
    }
}

struct CollabCardPage: View {
    @Environment(\.wa) private var wa
    let id: String
    let onBack: () -> Void
    @StateObject private var recorder = ChatVoiceRecorder()
    @State private var card: CollabCardDto?
    @State private var error: String?
    @State private var text = ""
    @State private var voice: ChatVoiceRecorder.Clip?
    @State private var photo: ChatLocalFile?
    @State private var photoItem: PhotosPickerItem?
    @State private var busy = false
    @State private var inviting = false

    var body: some View {
        WaPage(title: card?.title ?? L("mo_collab_title"), onBack: onBack, actions: { EmptyView() }, cta: { EmptyView() }) {
            if let c = card { content(c) } else {
                WaError(message: error)
                ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 90).padding(.bottom, 10) }
            }
        }
        .task(id: id) {
            do { take(try await MomentsChatApi.collabCard(id).data) } catch { self.error = error.apiFailure.message }
        }
        .onChange(of: photoItem) { item in
            guard let item else { return }
            photoItem = nil
            Task {
                guard let data = try? await item.loadTransferable(type: Data.self), let img = UIImage(data: data), let jpeg = img.jpegData(compressionQuality: 0.85) else { return }
                let url = FileManager.default.temporaryDirectory.appendingPathComponent("card-\(UUID().uuidString).jpg")
                if (try? jpeg.write(to: url)) != nil { photo = ChatLocalFile(url: url, mime: "image/jpeg", name: url.lastPathComponent) }
            }
        }
        .sheet(isPresented: $inviting) {
            PeoplePickerSheet(initial: [], onDismiss: { inviting = false }) { ids in
                inviting = false
                act { try await MomentsChatApi.inviteCollab(id, members: ids).data }
            }
            .chatAppearance()
        }
        .onDisappear { if recorder.recording { recorder.cancel() } }
    }

    private func take(_ c: CollabCardDto?) {
        card = c
        if let c, text.isEmpty { text = c.mine?.text ?? "" }
    }

    private func act(_ block: @escaping () async throws -> CollabCardDto?) {
        busy = true
        error = nil
        Task {
            do { if let c = try await block() { take(c) } } catch { self.error = error.apiFailure.message }
            busy = false
        }
    }

    @ViewBuilder
    private func content(_ c: CollabCardDto) -> some View {
        let members = c.members ?? []
        CardFace(look: {
            var look = c.look ?? personalLooks.last!.1
            look.title = c.title
            return look
        }()) {
            Text(L("mo_collab_for", c.recipient?.name ?? "")).font(.dorr(13)).foregroundStyle(.white.opacity(0.9))
        }
        .waRise(0)
        if c.status != "collecting" {
            Text(L(c.status == "sent" ? "mo_collab_was_sent" : "mo_collab_was_cancelled")).font(.dorr(13, .bold)).foregroundStyle(wa.mut).padding(.top, 10)
        }
        WaSectionTitle(title: L("mo_collab_signed", members.filter { $0.signed == true }.count, members.count)).waRise(1)
        ForEach(Array(members.enumerated()), id: \.offset) { i, m in
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    ChAvatar(url: m.profile?.avatar, name: m.profile?.name, key: m.profile?.key, size: 34)
                    Text(verbatim: m.profile?.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.ink).frame(maxWidth: .infinity, alignment: .leading)
                    if m.signed == true { Image(systemName: "checkmark.circle.fill").font(.system(size: 17)).foregroundStyle(Color(hex: 0x10B981)) } else {
                        Text(L("mo_collab_waiting")).font(.dorr(12)).foregroundStyle(wa.mut)
                    }
                    if c.isOrganiser == true && c.status == "collecting" && m.profile?.isMe != true, let uid = m.profile?.id {
                        Button { act { try await MomentsChatApi.removeCollabMember(c.id, user: uid).data } } label: {
                            Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.mut)
                        }
                        .buttonStyle(.plain)
                    }
                }
                if let t = m.text { Text(verbatim: t).font(.dorr(13.5)).foregroundStyle(wa.ink).padding(.top, 6) }
                KeptFiles(files: m.files ?? [], key: "\(c.id)-\(m.profile?.key ?? "")")
            }
            .padding(12)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .padding(.bottom, 8)
            .waRise(2 + i)
        }
        if c.status == "collecting", let mine = c.mine {
            WaSectionTitle(title: L("mo_collab_my_part")).waRise(3)
            ChField(placeholder: L("mo_card_text_hint"), text: $text, lines: 3...8, limit: 1000)
            HStack(spacing: 8) {
                if recorder.recording {
                    chip("stop.fill", L("mo_card_voice_stop", durationText(recorder.elapsedMs)), active: true) { voice = recorder.finish() }
                } else if let v = voice {
                    chip("trash", L("mo_card_voice_done", durationText(v.durationMs)), active: true) { voice = nil }
                } else {
                    chip("mic.fill", L("mo_card_voice")) {
                        if recorder.permitted { _ = recorder.start() } else {
                            AVAudioSession.sharedInstance().requestRecordPermission { ok in if ok { Task { @MainActor in _ = recorder.start() } } }
                        }
                    }
                }
                if photo != nil {
                    chip("trash", L("mo_collab_photo_added"), active: true) { photo = nil }
                } else {
                    PhotosPicker(selection: $photoItem, matching: .images) { chipLabel("photo.fill", L("mo_collab_photo"), active: false) }
                }
            }
            .padding(.top, 8)
            WaButton(text: L(mine.signed == true ? "mo_collab_update" : "mo_collab_sign"), icon: "checkmark.circle.fill",
                     enabled: !busy && !recorder.recording && (!text.trimmingCharacters(in: .whitespaces).isEmpty || voice != nil || photo != nil), loading: busy) {
                sign(c.id)
            }
            .padding(.top, 10)
        }
        WaError(message: error)
        if c.isOrganiser == true && c.status == "collecting" {
            WaButton(text: L("mo_collab_invite"), style: .ghost, icon: "person.badge.plus") { inviting = true }.padding(.top, 14)
            WaButton(text: L("mo_collab_send"), icon: "paperplane.fill", enabled: !busy && members.contains { $0.signed == true }) {
                busy = true
                Task {
                    do {
                        _ = try await MomentsChatApi.sendCollab(c.id)
                        AppToast.show(L("mo_card_sent"))
                        take(try? await MomentsChatApi.collabCard(c.id).data)
                    } catch { self.error = error.apiFailure.message }
                    busy = false
                }
            }
            .padding(.top, 8)
            WaButton(text: L("mo_collab_cancel"), style: .quiet, icon: "xmark.circle") {
                Task {
                    do {
                        _ = try await MomentsChatApi.cancelCollab(c.id)
                        onBack()
                    } catch { self.error = error.apiFailure.message }
                }
            }
            .padding(.top, 6)
        }
    }

    private func sign(_ id: String) {
        let t = text.trimmingCharacters(in: .whitespacesAndNewlines)
        let v = voice, p = photo
        act {
            var files: [ApiClient.FilePart] = []
            if let v, let data = try? Data(contentsOf: v.url) { files.append(ApiClient.FilePart(field: "voice", filename: v.url.lastPathComponent, mimeType: "audio/mp4", data: data)) }
            if let p, let data = try? Data(contentsOf: p.url) { files.append(ApiClient.FilePart(field: "photo", filename: p.name, mimeType: p.mime, data: data)) }
            let fresh = try await MomentsChatApi.contribute(id, fields: ["text": t], files: files).data
            voice = nil
            photo = nil
            return fresh
        }
    }

    private func chipLabel(_ icon: String, _ label: String, active: Bool) -> some View {
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 15))
            Text(verbatim: label).font(.dorr(13, .bold))
        }
        .foregroundStyle(active ? .white : wa.ink)
        .padding(.horizontal, 14).padding(.vertical, 9)
        .background(active ? wa.red : wa.surface, in: Capsule())
    }

    private func chip(_ icon: String, _ label: String, active: Bool = false, _ action: @escaping () -> Void) -> some View {
        Button(action: action) { chipLabel(icon, label, active: active) }.buttonStyle(.plain)
    }
}

// MARK: - capsules

private let capsuleEmojis = ["🌙", "🎂", "💍", "🎓", "🎉", "❤️", "🌸", "✈️", "👶", "⭐"]

struct CapsulesPage: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let onOpen: (String) -> Void
    @State private var capsules: [CapsuleDto]?
    @State private var creating = false

    var body: some View {
        WaPage(title: L("mo_capsules"), onBack: onBack, hasCta: true, actions: { EmptyView() }, cta: {
            WaButton(text: L("mo_capsule_new"), icon: "plus") { creating = true }
        }) {
            Text(L("mo_capsules_sub")).font(.dorr(13)).foregroundStyle(wa.mut).padding(.bottom, 10).padding(.leading, 2)
            if let list = capsules {
                if list.isEmpty {
                    WaEmpty(icon: "archivebox.fill", tone: .gray, title: L("mo_capsules_empty"), text: L("mo_capsules_empty_sub")) { EmptyView() }
                } else {
                    VStack(spacing: 0) {
                        ForEach(Array(list.enumerated()), id: \.element.id) { i, c in
                            CapsuleRow(c: c) { onOpen(c.id) }
                            if i != list.count - 1 { Rectangle().fill(wa.line).frame(height: 1).padding(.horizontal, 8) }
                        }
                    }
                    .padding(.horizontal, 6).padding(.vertical, 2)
                    .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.line, lineWidth: 1))
                }
            } else {
                ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 16).frame(height: 56).padding(.bottom, 8) }
            }
        }
        .task { capsules = (try? await MomentsChatApi.capsules().data) ?? [] }
        .sheet(isPresented: $creating) {
            NewCapsuleSheet(onDismiss: { creating = false }) { c in
                creating = false
                onOpen(c.id)
            }
            .dorrThemed()
        }
    }
}

private struct CapsuleRow: View {
    @Environment(\.wa) private var wa
    let c: CapsuleDto
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(spacing: 10) {
                Text(verbatim: c.emoji ?? "📦").font(.system(size: 16)).frame(width: 34, height: 34).background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 10))
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: c.title).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                    Text(L("mo_capsule_count", c.itemsCount ?? 0)).font(.dorr(11)).foregroundStyle(wa.mut)
                }
                Spacer()
            }
            .padding(.horizontal, 4).padding(.vertical, 10).contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

private struct NewCapsuleSheet: View {
    @Environment(\.wa) private var wa
    let onDismiss: () -> Void
    let onCreated: (CapsuleDto) -> Void
    @State private var title = ""
    @State private var emoji = capsuleEmojis[0]
    @State private var error: String?
    @State private var busy = false

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(L("mo_capsule_new")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
            SelectRow(icon: "sparkles", title: L("mo_emoji"), value: emoji, options: capsuleEmojis.map { e in (e, { emoji = e }) })
            ChField(placeholder: L("mo_capsule_name"), text: $title, icon: "pencil", limit: 120)
            WaError(message: error)
            WaButton(text: L("mo_save"), enabled: !busy && !title.trimmingCharacters(in: .whitespaces).isEmpty, loading: busy) {
                busy = true
                error = nil
                Task {
                    do { if let c = try await MomentsChatApi.createCapsule(title: title.trimmingCharacters(in: .whitespaces), emoji: emoji).data { onCreated(c) } } catch {
                        self.error = error.apiFailure.message
                    }
                    busy = false
                }
            }
            .padding(.top, 6)
            Spacer()
        }
        .padding(20)
    }
}

struct CapsulePage: View {
    @Environment(\.wa) private var wa
    let id: String
    let onBack: () -> Void
    @State private var capsule: CapsuleDto?
    @State private var error: String?

    var body: some View {
        WaPage(title: capsule.map { "\($0.emoji ?? "📦")  \($0.title)" } ?? L("mo_capsules"), onBack: onBack, actions: { EmptyView() }, cta: { EmptyView() }) {
            WaError(message: error)
            if let c = capsule {
                let items = c.items ?? []
                if items.isEmpty { WaEmpty(icon: "archivebox.fill", tone: .gray, title: L("mo_capsule_empty"), text: L("mo_capsule_empty_sub")) { EmptyView() } }
                ForEach(Array(items.enumerated()), id: \.element.id) { i, item in
                    VStack(alignment: .leading, spacing: 4) {
                        HStack {
                            Text(verbatim: [item.senderName, capsuleDay(item.originalAt).isEmpty ? nil : capsuleDay(item.originalAt)].compactMap { $0 }.joined(separator: "  ·  "))
                                .font(.dorr(11, .bold)).foregroundStyle(wa.mut)
                            Spacer()
                            Button {
                                Task { if let fresh = try? await MomentsChatApi.removeFromCapsule(c.id, item: item.id).data { capsule = fresh } }
                            } label: { Image(systemName: "trash").font(.system(size: 15)).foregroundStyle(wa.mut) }
                            .buttonStyle(.plain)
                        }
                        if let t = item.text, !t.trimmingCharacters(in: .whitespaces).isEmpty { Text(verbatim: t).font(.dorr(13.5)).foregroundStyle(wa.ink) }
                        KeptFiles(files: item.files ?? [], key: "cap-\(item.id)")
                        if let n = item.note { Text(verbatim: "📝 \(n)").font(.dorr(12)).foregroundStyle(wa.red).padding(.top, 2) }
                    }
                    .padding(12)
                    .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.line, lineWidth: 1))
                    .padding(.bottom, 8)
                    .waRise(i)
                }
                WaButton(text: L("mo_capsule_delete"), style: .quiet, icon: "trash") {
                    Task { if (try? await MomentsChatApi.deleteCapsule(c.id)) != nil { onBack() } }
                }
                .padding(.top, 10)
            } else {
                ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 90).padding(.bottom, 10) }
            }
        }
        .task(id: id) {
            do { capsule = try await MomentsChatApi.capsule(id).data } catch { self.error = error.apiFailure.message }
        }
    }
}

/// «Keep in a capsule» on a message: pick one (or start one), with an optional note.
struct CapsulePickerSheet: View {
    @ObservedObject private var ch = Ch.shared
    let messageId: String
    let onDismiss: () -> Void
    @State private var capsules: [CapsuleDto]?
    @State private var note = ""
    @State private var creating = false
    @State private var error: String?
    @State private var title = ""
    @State private var emoji = capsuleEmojis[0]

    var body: some View {
        ChSheetCard {
            Text(L(creating ? "mo_capsule_new" : "mo_capsule_keep")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            if creating {
                SelectRow(icon: "sparkles", title: L("mo_emoji"), value: emoji, options: capsuleEmojis.map { e in (e, { emoji = e }) }).padding(.top, 10)
                ChField(placeholder: L("mo_capsule_name"), text: $title, icon: "pencil", limit: 120).padding(.top, 10)
                ChPrimaryButton(text: L("mo_save"), enabled: !title.trimmingCharacters(in: .whitespaces).isEmpty) {
                    Task {
                        do { if let c = try await MomentsChatApi.createCapsule(title: title.trimmingCharacters(in: .whitespaces), emoji: emoji).data { keep(c.id) } } catch {
                            self.error = error.apiFailure.message
                        }
                    }
                }
                .padding(.top, 14)
            } else {
                Text(L("mo_capsule_keep_sub")).font(.dorr(13)).foregroundStyle(ch.mut).padding(.top, 4)
                ChField(placeholder: L("mo_capsule_note"), text: $note, icon: "pencil", limit: 300).padding(.top, 10)
                if let list = capsules {
                    ScrollView {
                        VStack(spacing: 0) {
                            ForEach(list) { c in
                                Button { keep(c.id) } label: {
                                    HStack(spacing: 10) {
                                        Text(verbatim: c.emoji ?? "📦").font(.system(size: 16)).frame(width: 34, height: 34).background(ch.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 10))
                                        Text(verbatim: c.title).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                                        Text(L("mo_capsule_count", c.itemsCount ?? 0)).font(.dorr(11)).foregroundStyle(ch.mut)
                                    }
                                    .padding(.horizontal, 4).padding(.vertical, 10).contentShape(Rectangle())
                                }
                                .buttonStyle(.plain)
                                Rectangle().fill(ch.line).frame(height: 1).padding(.horizontal, 8)
                            }
                            Button { creating = true } label: {
                                HStack(spacing: 10) {
                                    Image(systemName: "plus").font(.system(size: 17, weight: .semibold)).foregroundStyle(ch.red)
                                    Text(L("mo_capsule_new")).font(.dorr(13.5, .bold)).foregroundStyle(ch.red)
                                    Spacer()
                                }
                                .padding(.horizontal, 4).padding(.vertical, 10).contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                        }
                        .padding(.horizontal, 6)
                    }
                    .frame(maxHeight: 340)
                    .background(ch.surface, in: RoundedRectangle(cornerRadius: 16))
                    .overlay(RoundedRectangle(cornerRadius: 16).stroke(ch.line, lineWidth: 1))
                    .padding(.top, 10)
                } else {
                    ProgressView().tint(ch.red).frame(maxWidth: .infinity).padding(16)
                }
            }
            if let error { Text(verbatim: error).font(.dorr(12.5)).foregroundStyle(ch.danger).padding(.top, 6) }
        }
        .task { capsules = (try? await MomentsChatApi.capsules().data) ?? [] }
    }

    private func keep(_ capsuleId: String) {
        Task {
            do {
                let n = note.trimmingCharacters(in: .whitespacesAndNewlines)
                _ = try await MomentsChatApi.addToCapsule(capsuleId, messageId: messageId, note: n.isEmpty ? nil : n)
                AppToast.show(L("mo_capsule_kept"))
                onDismiss()
            } catch { self.error = error.apiFailure.message }
        }
    }
}
