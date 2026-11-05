import PhotosUI
import SwiftUI

// androidApp `moments/MomentCards.kt` — greeting cards (DORR Moments, spec 161–167): the composer — occasion look, my
// words (or three AI suggestions to start from), my own voice, photos, a surprise time, a time in the recipient's day,
// a wallet gift — and the card as it shows in the chat.

/// Where the composer starts: an occasion, one of my own kinds, a title.
struct CardPreset: Equatable {
    var momentId: Int?
    var personalKind: String?
    var title: String?
}

/// My own kinds — the same looks the server gives them.
let personalLooks: [(String, CardLookDto)] = [
    ("birthday", CardLookDto(kind: "personal", theme: "birthday", primaryColor: "#EC4899", secondaryColor: "#8B5CF6", emoji: "🎂", animation: "balloons")),
    ("anniversary", CardLookDto(kind: "personal", theme: "anniversary", primaryColor: "#BE123C", secondaryColor: "#FDA4AF", emoji: "💍", animation: "hearts")),
    ("graduation", CardLookDto(kind: "personal", theme: "graduation", primaryColor: "#1E3A8A", secondaryColor: "#FBBF24", emoji: "🎓", animation: "confetti")),
    ("wedding", CardLookDto(kind: "personal", theme: "wedding", primaryColor: "#9D174D", secondaryColor: "#FBCFE8", emoji: "💒", animation: "petals")),
    ("baby", CardLookDto(kind: "personal", theme: "baby", primaryColor: "#0EA5E9", secondaryColor: "#FBCFE8", emoji: "👶", animation: "balloons")),
    ("other", CardLookDto(kind: "personal", theme: "other", primaryColor: "#001B53", secondaryColor: "#FA7552", emoji: "✨", animation: "confetti")),
]

/// One thing to make a card for: an occasion from the catalog, or one of my kinds.
struct CardChoice: Equatable, Hashable {
    let momentId: Int?
    let kind: String?
    let name: String
    let look: CardLookDto
}

@MainActor func cardChoices(_ center: MomentsCenterDto?) -> [CardChoice] {
    var seen = Set<Int>()
    let occasions = ((center?.active ?? []) + (center?.upcoming ?? [])).filter { seen.insert($0.id).inserted }.map { m in
        CardChoice(momentId: m.id, kind: nil, name: m.name ?? "", look: CardLookDto(momentId: m.id, kind: m.kind, theme: m.theme, title: m.name, primaryColor: m.primaryColor,
                                                                               secondaryColor: m.secondaryColor, emoji: m.emoji, animation: m.animation, cardImage: m.cardImage))
    }
    return occasions + personalLooks.map { CardChoice(momentId: nil, kind: $0.0, name: personalKindLabel($0.0), look: $0.1) }
}

private func whenText(_ d: Date) -> String {
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.dateStyle = .medium
    f.timeStyle = .short
    return f.string(from: d)
}

// MARK: - the card face

/// The occasion's face: its gradient (or the admin's card image), animation, emoji and title.
struct CardFace<Content: View>: View {
    let look: CardLookDto
    /// nil: my Moments setting.
    var effects: String?
    @ViewBuilder var content: () -> Content

    var body: some View {
        ZStack {
            momentGradient(look.primaryColor, look.secondaryColor)
            if let image = look.cardImage { ChatImage(url: ApiClient.mediaUrl(image)) }
            MomentEffect(animation: look.animation, emoji: look.emoji, primary: look.primaryColor, secondary: look.secondaryColor, effects: effects ?? MomentsStore.shared.center?.effects ?? "full")
            VStack(spacing: 8) {
                Text(verbatim: look.emoji ?? "✨").font(.system(size: 40))
                if let heading = look.title, !heading.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: heading).font(.dorr(18, .heavy)).foregroundStyle(.white).multilineTextAlignment(.center).lineLimit(2)
                        .shadow(color: .black.opacity(0.7), radius: 6, y: 1)
                }
                content()
            }
            .padding(.horizontal, 18).padding(.vertical, 16)
        }
        .frame(minHeight: 156)
        .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
    }
}

extension CardFace where Content == EmptyView {
    init(look: CardLookDto, effects: String? = nil) {
        self.init(look: look, effects: effects) { EmptyView() }
    }
}

// MARK: - the composer

/// Make a card. With no `conversationId` it asks who for first (a saved contact → our chat). `canGift`: a one-to-one
/// chat, where the wallet can send money.
struct MomentCardSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var store = MomentsStore.shared
    let conversationId: String?
    let peerName: String?
    let canGift: Bool
    var preset: CardPreset?
    var toUser: ProfileDto?
    let onDismiss: () -> Void
    var onSent: (MessageDto?) -> Void = { _ in }

    @StateObject private var recorder = ChatVoiceRecorder()
    @State private var chatId: String?
    @State private var name: String?
    @State private var direct = false
    @State private var choice: CardChoice?
    @State private var title = ""
    @State private var body_ = ""
    @State private var aiOpen = false
    @State private var voice: ChatVoiceRecorder.Clip?
    @State private var photos: [ChatLocalFile] = []
    @State private var photoItems: [PhotosPickerItem] = []
    @State private var revealAt: Date?
    @State private var sendAt: Date?
    @State private var theirZone = true
    @State private var giftOn = false
    @State private var gift = ""
    /// reveal · send
    @State private var picking: String?
    /// 0 compose · 1 PIN
    @State private var step = 0
    @State private var busy = false
    @State private var error: String?
    @State private var started = false

    private var giftMinor: Int? { giftOn && direct && sendAt == nil ? parseAmountToMinor(gift) : nil }

    var body: some View {
        Group {
            if let which = picking {
                ScheduleSheet(onDismiss: { picking = nil }) { at in
                    if which == "reveal" { revealAt = at } else { sendAt = at; giftOn = false }
                    picking = nil
                }
            } else if chatId == nil && toUser != nil {
                ChSheetCard { ProgressView().tint(ch.red).frame(maxWidth: .infinity).padding(40) }
            } else if chatId == nil {
                WhoFor { c in open(c.profile, name: c.name) }
            } else if step == 1 {
                ChSheetCard {
                    WaPinPad(title: L("mo_card_gift_pin", formatMinor(giftMinor ?? 0, nil)), sub: name ?? "", icon: "gift.fill") { pin in
                        do {
                            try await send(pin: pin)
                            return .ok
                        } catch {
                            let failure = error.apiFailure
                            if failure.errorCode?.hasPrefix("wallet_pin") == true { return padFailure(error, networkError: L("ch_error_network")) }
                            self.error = failure.message
                            step = 0
                            return .ok
                        }
                    }
                    .frame(height: 500)
                }
            } else {
                compose
            }
        }
        .task {
            if store.center == nil { await store.refresh() }
            if !started {
                started = true
                chatId = conversationId
                name = peerName
                direct = canGift
                title = preset?.title ?? ""
            }
            // From a date of mine tied to a person: straight to our chat.
            if chatId == nil, let toUser { open(toUser, name: toUser.name) }
            pickChoice()
        }
        .onChange(of: store.center) { _ in pickChoice() }
        .onChange(of: photoItems) { items in
            guard !items.isEmpty else { return }
            photoItems = []
            Task {
                for item in items where photos.count < 10 {
                    guard let data = try? await item.loadTransferable(type: Data.self), let image = UIImage(data: data) else { continue }
                    let budget = ChatPrefs.shared.photoBudget()
                    guard let jpeg = image.jpegData(compressionQuality: budget.quality) else { continue }
                    let url = FileManager.default.temporaryDirectory.appendingPathComponent("card-\(UUID().uuidString).jpg")
                    if (try? jpeg.write(to: url)) != nil { photos.append(ChatLocalFile(url: url, mime: "image/jpeg", name: url.lastPathComponent)) }
                }
            }
        }
        .onDisappear { if recorder.recording { recorder.cancel() } }
    }

    /// The occasion's look with my title (or its own name).
    private func titled(_ c: CardChoice) -> CardLookDto {
        var look = c.look
        let t = title.trimmingCharacters(in: .whitespaces)
        look.title = t.isEmpty ? c.name : t
        return look
    }

    private func pickChoice() {
        let choices = cardChoices(store.center)
        guard choice == nil, !choices.isEmpty else { return }
        choice = choices.first { preset?.momentId != nil && $0.momentId == preset?.momentId }
            ?? choices.first { preset?.personalKind != nil && $0.kind == preset?.personalKind }
            ?? choices.first
    }

    private func open(_ profile: ProfileDto?, name person: String?) {
        guard let profile else { return }
        Task {
            do {
                if let conv = try await ChatApi.openDirect(participantId: profile.id, participantType: profile.type).data {
                    chatId = conv.id
                    name = person
                    direct = true
                }
            } catch { self.error = error.apiFailure.message }
        }
    }

    private var compose: some View {
        let choices = cardChoices(store.center)
        return ChSheetCard {
            ScrollView {
                VStack(alignment: .leading, spacing: 10) {
                    Text(verbatim: (name ?? "").isEmpty ? L("mo_card_title") : L("mo_card_to", name ?? "")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                    if let c = choice { CardFace(look: titled(c)) }
                    SelectRow(icon: "sparkles", title: L("mo_occasion"), value: choice.map { "\($0.look.emoji ?? "✨")  \($0.name)" } ?? "",
                              options: choices.map { c in ("\(c.look.emoji ?? "✨")  \(c.name)", { choice = c }) })
                    ChField(placeholder: L("mo_card_title_hint"), text: $title, icon: "pencil", limit: 120)
                    ChField(placeholder: L("mo_card_text_hint"), text: $body_, icon: "pencil", lines: 3...8, limit: 2000)
                    Button { withAnimation { aiOpen.toggle() } } label: {
                        HStack(spacing: 6) {
                            Image(systemName: "sparkles").font(.system(size: 14))
                            Text(L("mo_card_ai")).font(.dorr(13, .bold))
                        }
                        .foregroundStyle(ch.red).padding(.horizontal, 12).padding(.vertical, 7).background(ch.red.opacity(0.1), in: Capsule())
                    }
                    .buttonStyle(.plain)
                    if aiOpen { GreetingSuggestions(choice: choice, name: name) { body_ = $0; aiOpen = false } }
                    actionRow(recorder.recording ? "stop.fill" : voice != nil ? "trash" : "mic.fill",
                              recorder.recording ? L("mo_card_voice_stop", durationText(recorder.elapsedMs)) : voice.map { L("mo_card_voice_done", durationText($0.durationMs)) } ?? L("mo_card_voice")) {
                        if recorder.recording { voice = recorder.finish() } else if voice != nil {
                            if let v = voice { try? FileManager.default.removeItem(at: v.url) }
                            voice = nil
                        } else if recorder.permitted { _ = recorder.start() } else {
                            AVAudioSession.sharedInstance().requestRecordPermission { ok in if ok { Task { @MainActor in _ = recorder.start() } } }
                        }
                    }
                    PhotosPicker(selection: $photoItems, maxSelectionCount: max(10 - photos.count, 1), matching: .images) {
                        rowLabel("photo.fill", photos.isEmpty ? L("mo_card_photos") : "\(photos.count)/10")
                    }
                    .disabled(photos.count >= 10)
                    if !photos.isEmpty {
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 6) {
                                ForEach(photos, id: \.url) { f in
                                    ZStack(alignment: .topTrailing) {
                                        if let img = UIImage(contentsOfFile: f.url.path) { Image(uiImage: img).resizable().scaledToFill().frame(width: 64, height: 64).clipShape(RoundedRectangle(cornerRadius: 12)) }
                                        Button { photos.removeAll { $0.url == f.url } } label: {
                                            Image(systemName: "xmark").font(.system(size: 10, weight: .bold)).foregroundStyle(.white).frame(width: 20, height: 20).background(Color.black.opacity(0.55), in: Circle())
                                        }
                                        .buttonStyle(.plain).padding(3)
                                    }
                                }
                            }
                        }
                    }
                    VStack(spacing: 0) {
                        toggleLine("lock.fill", L("mo_card_surprise"), revealAt.map { L("mo_card_surprise_at", whenText($0)) } ?? L("mo_card_surprise_sub"), checked: revealAt != nil) { on in
                            if on { picking = "reveal" } else { revealAt = nil }
                        }
                        toggleLine("clock.fill", L("mo_card_later"), sendAt.map { L("mo_card_later_at", whenText($0)) } ?? L("mo_card_later_sub"), checked: sendAt != nil) { on in
                            if on { picking = "send" } else { sendAt = nil }
                        }
                        if sendAt != nil && direct {
                            SelectRow(icon: "clock", title: L("mo_card_later"), value: theirZone ? L("mo_card_their_time", name ?? "") : L("mo_card_my_time"),
                                      options: [(L("mo_card_their_time", name ?? ""), { theirZone = true }), (L("mo_card_my_time"), { theirZone = false })])
                                .padding(.vertical, 6)
                        }
                        if direct {
                            toggleLine("gift.fill", L("mo_card_gift"), L(sendAt != nil ? "mo_card_gift_now_only" : "mo_card_gift_sub"), checked: giftOn && sendAt == nil, enabled: sendAt == nil) { giftOn = $0 }
                            if giftOn && sendAt == nil {
                                ChField(placeholder: L("mo_card_gift_amount"), text: Binding(get: { gift }, set: { gift = String($0.filter { $0.isNumber || $0 == "." }.prefix(12)) }),
                                        icon: "banknote", limit: 12, keyboard: .decimalPad)
                                    .padding(.vertical, 8)
                            }
                        }
                    }
                    .padding(.horizontal, 8).padding(.vertical, 4)
                    .background(ch.surfaceMuted.opacity(0.6), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    if let error { Text(verbatim: error).font(.dorr(12.5)).foregroundStyle(ch.danger) }
                    ChPrimaryButton(text: L(sendAt != nil ? "mo_card_schedule" : "mo_card_send"), icon: "paperplane.fill",
                                    enabled: !busy && choice != nil && !recorder.recording && (!giftOn || sendAt != nil || giftMinor != nil)) {
                        if giftMinor != nil { step = 1; return }
                        busy = true
                        error = nil
                        Task {
                            do { try await send(pin: nil) } catch { self.error = error.apiFailure.message }
                            busy = false
                        }
                    }
                    .padding(.top, 6)
                }
            }
            .frame(maxHeight: 700)
        }
    }

    private func rowLabel(_ icon: String, _ text: String) -> some View {
        HStack(spacing: 12) {
            Image(systemName: icon).font(.system(size: 16)).foregroundStyle(ch.red)
            Text(verbatim: text).font(.dorr(14.5)).foregroundStyle(ch.ink)
            Spacer()
        }
        .padding(.horizontal, 16).padding(.vertical, 14)
        .background(ch.fieldFill, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(ch.fieldLine, lineWidth: 1))
    }

    private func actionRow(_ icon: String, _ text: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) { rowLabel(icon, text) }.buttonStyle(.plain)
    }

    private func toggleLine(_ icon: String, _ title: String, _ sub: String, checked: Bool, enabled: Bool = true, _ onChange: @escaping (Bool) -> Void) -> some View {
        HStack(spacing: 10) {
            Image(systemName: icon).font(.system(size: 15)).foregroundStyle(ch.red).frame(width: 32, height: 32).background(ch.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 10))
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: title).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink)
                Text(verbatim: sub).font(.dorr(11)).foregroundStyle(ch.mut).lineLimit(2)
            }
            Spacer()
            Toggle("", isOn: Binding(get: { checked }, set: { if enabled { onChange($0) } })).labelsHidden().tint(ch.red).disabled(!enabled)
        }
        .padding(.horizontal, 4).padding(.vertical, 12)
        .opacity(enabled ? 1 : 0.45)
    }

    private func send(pin: String?) async throws {
        guard let id = chatId, let c = choice else { return }
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd HH:mm"
        var fields: [String: String] = ["uuid": UUID().uuidString]
        if let m = c.momentId { fields["moment_id"] = String(m) }
        if let k = c.kind { fields["personal_kind"] = k }
        let t = title.trimmingCharacters(in: .whitespaces)
        if !t.isEmpty { fields["title"] = t }
        let b = body_.trimmingCharacters(in: .whitespacesAndNewlines)
        if !b.isEmpty { fields["text"] = b }
        if let revealAt { fields["reveal_at"] = isoWithOffset(revealAt) }
        if let sendAt {
            fields["send_at"] = f.string(from: sendAt)
            fields["schedule_zone"] = theirZone && direct ? "recipient" : "mine"
        }
        if let giftMinor { fields["gift_amount_minor"] = String(giftMinor) }
        var files: [ApiClient.FilePart] = []
        if let v = voice, let data = try? Data(contentsOf: v.url) { files.append(ApiClient.FilePart(field: "voice", filename: v.url.lastPathComponent, mimeType: "audio/mp4", data: data)) }
        for p in photos { if let data = try? Data(contentsOf: p.url) { files.append(ApiClient.FilePart(field: "photos[]", filename: p.name, mimeType: p.mime, data: data)) } }
        let data = try await MomentsChatApi.sendCard(id, pin: pin, fields: fields, files: files).data
        let message = (data ?? nil).flatMap { $0.at("type") != nil ? $0.decode(MessageDto.self) : nil }
        AppToast.show(L(message == nil ? "mo_card_scheduled" : "mo_card_sent"))
        onSent(message)
        onDismiss()
    }
}

/// No chat yet: pick the person (saved contacts on Dorr).
private struct WhoFor: View {
    @ObservedObject private var ch = Ch.shared
    let onPick: (ContactDto) -> Void
    @State private var contacts: [ContactDto]?
    @State private var query = ""

    var body: some View {
        ChSheetCard {
            Text(L("mo_card_who")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 10)
            ChField(placeholder: L("ch_search_contacts"), text: $query, icon: "magnifyingglass", limit: 80)
            if let list = contacts {
                let q = query.trimmingCharacters(in: .whitespaces)
                ScrollView {
                    LazyVStack(spacing: 0) {
                        ForEach(list.filter { q.isEmpty || $0.name.localizedCaseInsensitiveContains(q) || $0.phone.contains(q) }) { c in
                            Button { onPick(c) } label: {
                                HStack(spacing: 10) {
                                    ChAvatar(url: c.profile?.avatar, name: c.name, key: c.profile?.key, size: 34)
                                    Text(verbatim: c.name).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink)
                                    Spacer()
                                }
                                .padding(.vertical, 10).padding(.horizontal, 4).contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
                .frame(maxHeight: 460)
                .padding(.top, 10)
            } else {
                ProgressView().tint(ch.red).frame(maxWidth: .infinity).padding(20)
            }
        }
        .task { contacts = ((try? await ChatApi.contacts(registered: 1).data) ?? []).filter { $0.profile != nil } }
    }
}

/// «Write it for me»: who they are to me and the tone → three greetings to start from.
private struct GreetingSuggestions: View {
    @ObservedObject private var ch = Ch.shared
    let choice: CardChoice?
    let name: String?
    let onUse: (String) -> Void
    @State private var relation = "friend"
    @State private var tone = "warm"
    @State private var loading = false
    @State private var error: String?
    @State private var results: [String] = []

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            pills([("family", "mo_rel_family"), ("friend", "mo_rel_friend"), ("partner", "mo_rel_partner"), ("work", "mo_rel_work"), ("other", "mo_rel_other")], relation) { relation = $0 }
            pills([("warm", "mo_tone_warm"), ("formal", "mo_tone_formal"), ("funny", "mo_tone_funny"), ("short", "mo_tone_short"), ("poetic", "mo_tone_poetic")], tone) { tone = $0 }
            ChPrimaryButton(text: L("mo_card_ai_go"), icon: "sparkles", enabled: !loading && choice != nil, action: go).padding(.top, 2)
            if let error { Text(verbatim: error).font(.dorr(12.5)).foregroundStyle(Color(hex: 0xDC2626)) }
            ForEach(results, id: \.self) { g in
                Button { onUse(g) } label: {
                    Text(verbatim: g).font(.dorr(13.5)).foregroundStyle(ch.ink).multilineTextAlignment(.leading).frame(maxWidth: .infinity, alignment: .leading).padding(12)
                        .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(12)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(ch.line, lineWidth: 1))
    }

    private func pills(_ options: [(String, String)], _ selected: String, _ pick: @escaping (String) -> Void) -> some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 6) {
                ForEach(options, id: \.0) { key, label in
                    let on = key == selected
                    Button { pick(key) } label: {
                        Text(L(label)).font(.dorr(12.5, .bold)).foregroundStyle(on ? .white : ch.ink).padding(.horizontal, 12).padding(.vertical, 6)
                            .background(on ? ch.red : .clear, in: Capsule()).overlay(Capsule().stroke(on ? ch.red : ch.line, lineWidth: 1))
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    private func go() {
        loading = true
        error = nil
        Task {
            var body: [String: JSONValue] = ["relation": .string(relation), "tone": .string(tone)]
            if let m = choice?.momentId { body["moment_id"] = .int(m) }
            if let k = choice?.kind { body["kind"] = .string(k) }
            if let name, !name.trimmingCharacters(in: .whitespaces).isEmpty { body["name"] = .string(String(name.prefix(60))) }
            do { results = try await MomentsChatApi.greetings(body).data?.greetings ?? [] } catch { self.error = error.apiFailure.message }
            loading = false
        }
    }
}

// MARK: - in the chat

/// A greeting card message. Sealed (someone's surprise, not time yet): a wrapped gift with a countdown, nothing else —
/// then «open it» fetches the real card. A group card lists everyone's part with their voice / photo; a gift shows its
/// amount.
struct MomentCardBubble<Footer: View>: View {
    @ObservedObject private var voicePlayer = VoicePlayer.shared
    let dto: MessageDto
    let mine: Bool
    let onOpenSealed: () -> Void
    let onOpenPhoto: (Int) -> Void
    @ViewBuilder let footer: () -> Footer
    @State private var pop: CGFloat = 0.85

    var body: some View {
        let card = dto.meta?.at("card")
        let look = card?.decode(CardLookDto.self) ?? CardLookDto()
        let revealAt = card?.str("reveal_at").flatMap(parseRideTime)
        let effects = (MomentsStore.shared.center?.effects ?? "full") == "off" ? "off" : "light"
        VStack(spacing: 0) {
            CardFace(look: look, effects: effects) {
                if dto.sealed == true {
                    SealedCountdown(revealAt: revealAt, onOpen: onOpenSealed)
                } else {
                    if mine, let revealAt, revealAt > Date() {
                        Text(L("mo_card_sealed_mine", whenText(revealAt))).font(.dorr(11.5)).foregroundStyle(.white.opacity(0.9)).multilineTextAlignment(.center)
                    }
                    VStack(spacing: 0) {
                        if let b = dto.body, !b.trimmingCharacters(in: .whitespaces).isEmpty {
                            Text(verbatim: b).font(.dorr(13.5)).foregroundStyle(.white).multilineTextAlignment(.center)
                                .shadow(color: .black.opacity(0.7), radius: 5, y: 1)
                                .frame(maxWidth: .infinity).padding(.horizontal, 12).padding(.vertical, 10)
                                .background(Color.white.opacity(0.16), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                                .padding(.top, 8)
                        }
                        let contributions = card?.at("contributions")?.arr ?? []
                        let used = Set(contributions.flatMap { c in (c.at("files")?.arr ?? []).compactMap { $0.at("attachment")?.dbl.map(Int.init) } })
                        ForEach(Array(contributions.enumerated()), id: \.offset) { _, c in
                            let files = (c.at("files")?.arr ?? []).compactMap { $0.at("attachment")?.dbl.map(Int.init) }
                            VStack(spacing: 2) {
                                if let n = c.str("name") { Text(verbatim: n).font(.dorr(12.5, .heavy)).foregroundStyle(.white).multilineTextAlignment(.center).shadow(color: .black.opacity(0.7), radius: 5, y: 1) }
                                if let t = c.str("text") { Text(verbatim: t).font(.dorr(13.5)).foregroundStyle(.white).multilineTextAlignment(.center).shadow(color: .black.opacity(0.7), radius: 5, y: 1) }
                                attachments(files)
                            }
                            .frame(maxWidth: .infinity)
                            .padding(.horizontal, 12).padding(.vertical, 10)
                            .background(Color.white.opacity(0.16), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                            .padding(.top, 8)
                        }
                        attachments(Array((dto.attachments ?? []).indices).filter { !used.contains($0) })
                        if let g = card?.at("gift") {
                            ZStack {
                                Text(verbatim: formatMinor(Int(g.at("amount_minor")?.dbl ?? 0), g.str("currency_code")))
                                    .font(.dorr(16, .heavy)).foregroundStyle(.white)
                                    .shadow(color: .black.opacity(0.7), radius: 5, y: 1)
                                HStack { Spacer(); Text(verbatim: "🎁").font(.system(size: 18)) }
                            }
                            .environment(\.layoutDirection, .leftToRight)
                            .frame(maxWidth: .infinity).padding(.horizontal, 12).padding(.vertical, 8)
                            .background(Color.white.opacity(0.16), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                            .padding(.top, 8)
                        }
                    }
                    .scaleEffect(pop)
                    .onAppear { withAnimation(.spring(response: 0.4, dampingFraction: 0.45)) { pop = 1 } }
                }
            }
            footer().frame(maxWidth: .infinity, alignment: .trailing).padding(.horizontal, 6)
        }
        .frame(width: 236)
    }

    /// The card's photos (a strip) and voices (play buttons), by their index in the message.
    @ViewBuilder
    private func attachments(_ indices: [Int]) -> some View {
        let all = dto.attachments ?? []
        let files = indices.compactMap { i in all.indices.contains(i) ? (i, all[i]) : nil }
        let photos = files.filter { $0.1.mimeType?.hasPrefix("image/") == true }
        let voices = files.filter { $0.1.mimeType?.hasPrefix("image/") != true }
        ForEach(voices, id: \.0) { i, a in
            let key = "\(dto.id)#\(i)"
            let progress = voicePlayer.playingId == key ? voicePlayer.progress : 0
            let bars = (0..<26).map { 22 + (($0 * 37) % 68) }
            Button { if let u = ApiClient.mediaUrl(a.url) { voicePlayer.toggle(key, u) } } label: {
                HStack(spacing: 8) {
                    Image(systemName: voicePlayer.isPlaying(key) ? "pause.fill" : "play.fill").font(.system(size: 14, weight: .bold))
                        .foregroundStyle(.white).frame(width: 34, height: 34).background(Color.white.opacity(0.22), in: Circle())
                    HStack(alignment: .center, spacing: 2) {
                        ForEach(Array(bars.enumerated()), id: \.offset) { i, v in
                            Capsule().fill(Double(i) / Double(bars.count) < progress ? Color.white : Color.white.opacity(0.45))
                                .frame(width: 2.5, height: 6 + CGFloat(v) / 100 * 18)
                        }
                    }
                    .frame(maxWidth: .infinity, maxHeight: 26)
                    if let ms = a.durationMs {
                        let s = Int(ms / 1000)
                        Text(verbatim: String(format: "%02d:%02d", s / 60, s % 60)).font(.dorr(12, .bold)).foregroundStyle(.white)
                            .shadow(color: .black.opacity(0.7), radius: 4, y: 1)
                    }
                    Image(systemName: "mic.fill").font(.system(size: 13)).foregroundStyle(.white)
                }
                .environment(\.layoutDirection, .leftToRight)
                .padding(.horizontal, 10).padding(.vertical, 6)
                .background(Color.white.opacity(0.16), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            .buttonStyle(.plain)
            .padding(.top, 8)
        }
        if !photos.isEmpty {
            HStack(spacing: 4) {
                ForEach(Array(photos.prefix(3).enumerated()), id: \.offset) { n, p in
                    Button { onOpenPhoto(p.0) } label: {
                        ZStack {
                            ChatImage(url: ApiClient.mediaUrl(p.1.thumbnail ?? p.1.url))
                            if n == 2 && photos.count > 3 {
                                Color.black.opacity(0.45)
                                Text(verbatim: "+\(photos.count - 3)").font(.dorr(18, .heavy)).foregroundStyle(.white)
                            }
                        }
                        .frame(maxWidth: .infinity).frame(height: 64).clipShape(RoundedRectangle(cornerRadius: 12))
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.top, 8)
        }
    }
}

private struct SealedCountdown: View {
    let revealAt: Date?
    let onOpen: () -> Void
    @State private var wiggle: Double = 0

    var body: some View {
        TimelineView(.periodic(from: .now, by: 1)) { ctx in
            let left = revealAt.map { $0.timeIntervalSince(ctx.date) } ?? 0
            VStack(spacing: 4) {
                Text(verbatim: "🎁").font(.system(size: 54)).rotationEffect(.degrees(wiggle * 8)).padding(.top, 6)
                Text(L("mo_card_sealed")).font(.dorr(14, .bold)).foregroundStyle(.white).multilineTextAlignment(.center)
                if left <= 0 {
                    Button(action: onOpen) {
                        Text(L("mo_card_open")).font(.dorr(14, .heavy)).foregroundStyle(Color(hex: 0x111928)).padding(.horizontal, 22).padding(.vertical, 9).background(Color.white, in: Capsule())
                    }
                    .buttonStyle(.plain)
                    .padding(.top, 10)
                } else {
                    let s = Int(left)
                    let d = s / 86400, h = s / 3600 % 24, m = s / 60 % 60, sec = s % 60
                    Text(verbatim: d > 0 ? String(format: "%dd  %02d:%02d:%02d", d, h, m, sec) : String(format: "%02d:%02d:%02d", h, m, sec))
                        .font(.dorr(22, .heavy)).foregroundStyle(.white).environment(\.layoutDirection, .leftToRight).padding(.top, 6)
                }
            }
        }
        .task {
            while !Task.isCancelled {
                for v in [1.0, -1.0, 0.0] {
                    withAnimation(.easeInOut(duration: 0.14)) { wiggle = v }
                    try? await Task.sleep(nanoseconds: 140_000_000)
                }
                try? await Task.sleep(nanoseconds: 2_200_000_000)
            }
        }
    }
}
