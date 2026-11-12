import Contacts
import PhotosUI
import SwiftUI

// androidApp `ui/screens/chat/NewChatPage.kt` + `ChatPhoneLookup.kt` — starting chats: my contacts on Dorr (the phone's
// address book synced on every visit), the rest to invite (WhatsApp / Telegram / SMS / share), find by number or
// @username, add by phone with the country picker, join by a code or link, scan a QR, broadcast lists, channels, note
// to self; a new group (pick people, then name, description and photo) or adding members to one; and my QR + the
// «click to chat» link.

/// The frame of every secondary chat page: the dark hero header with the title, its actions and back.
struct ChPage<Actions: View, Content: View>: View {
    @ObservedObject private var ch = Ch.shared
    let title: String
    let onBack: () -> Void
    @ViewBuilder var actions: () -> Actions
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(spacing: 0) {
            DarkHeroPanel {
                HStack(spacing: 8) {
                    Text(verbatim: title).font(.dorr(22, .black)).foregroundStyle(.white).lineLimit(2).frame(maxWidth: .infinity, alignment: .leading)
                    actions()
                    DarkHeroButton(icon: "chevron.right", label: L("common_back"), action: onBack)
                }
            }
            .padding(.horizontal, 16)
            content().frame(maxWidth: .infinity, maxHeight: .infinity)
        }
        .background(ch.bg.ignoresSafeArea())
    }
}

extension ChPage where Actions == EmptyView {
    init(title: String, onBack: @escaping () -> Void, @ViewBuilder content: @escaping () -> Content) {
        self.init(title: title, onBack: onBack, actions: { EmptyView() }, content: content)
    }
}

/// The settings-style frame (pink backdrop + small header) some chat pages use.
struct ChSettingsPage<Content: View>: View {
    let title: String
    let onBack: () -> Void
    @ViewBuilder let content: () -> Content

    var body: some View {
        ZStack {
            PinkBackdrop().ignoresSafeArea()
            VStack(spacing: 0) {
                SubHeader(title: title, onBack: onBack).padding(.horizontal, 16)
                content().frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
    }
}

/// Someone in the phone's address book.
struct ContactEntry: Hashable {
    let name: String
    let phone: String
}

/// Every phone number in the address book, with its contact's name.
func readAddressBook() -> [ContactEntry] {
    var out: [String: ContactEntry] = [:]
    var order: [String] = []
    let request = CNContactFetchRequest(keysToFetch: [CNContactGivenNameKey, CNContactFamilyNameKey, CNContactPhoneNumbersKey] as [CNKeyDescriptor])
    try? CNContactStore().enumerateContacts(with: request) { contact, _ in
        let name = [contact.givenName, contact.familyName].filter { !$0.isEmpty }.joined(separator: " ")
        for number in contact.phoneNumbers {
            let raw = number.value.stringValue.trimmingCharacters(in: .whitespaces)
            let key = raw.filter { $0.isNumber || $0 == "+" }
            guard !key.isEmpty, out[key] == nil else { continue }
            out[key] = ContactEntry(name: name, phone: raw)
            order.append(key)
        }
    }
    return order.compactMap { out[$0] }
}

// MARK: - New chat

struct NewChatPage: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    @State private var contacts: [ContactDto]?
    @State private var query = ""
    @State private var syncing = false
    @State private var synced: Int?
    @State private var lookupError: String?
    @State private var byPhone: String?
    @State private var deviceBook: [ContactEntry] = []
    @State private var inviting: ContactEntry?
    @State private var denied = false
    @State private var joinByCode = false
    @State private var scanning = false

    var body: some View {
        let digits = query.filter { $0.isNumber || $0 == "+" }
        let looksLikeNumber = digits.count >= 8 && digits.count >= query.trimmingCharacters(in: .whitespaces).count - 2
        // «@name» — someone's username (spec 81), found without their number.
        let trimmed = query.trimmingCharacters(in: .whitespaces)
        let looksLikeUsername = trimmed.hasPrefix("@") && trimmed.count >= 4
        let all = contacts ?? []
        let onDorr = all.filter { $0.isRegistered && matches($0.name, $0.phone) }
        // Not on Dorr yet: from the server when it answered, else straight from the phone.
        let toInvite = all.isEmpty ? deviceBook : all.filter { !$0.isRegistered }.map { ContactEntry(name: $0.name, phone: $0.phone) }
        let invitees = toInvite.filter { matches($0.name, $0.phone) }
        ChSettingsPage(title: L("ch_new_chat"), onBack: { host.pop() }) {
            ScrollView {
                LazyVStack(spacing: 6) {
                    searchField(looksLikeNumber: looksLikeNumber, looksLikeUsername: looksLikeUsername)
                    HStack(spacing: 6) {
                        shortcut("person.3.fill", L("ch_new_group"), 0) { host.replace(.newGroup()) }
                        shortcut("circle.grid.3x3.fill", L("ch_by_number"), 1) { byPhone = "" }
                        shortcut("qrcode.viewfinder", L("ch_scan_qr"), 2) { scanning = true }
                        shortcut("qrcode", L("ch_my_qr"), 3) { host.push(.myQr) }
                    }
                    // A group or channel by its invite code or link (typed or pasted).
                    NewChatActionRow(icon: "key.fill", title: L("ch_join_code"), subtitle: L("ch_join_code_sub"), index: 4) { joinByCode = true }
                    // Broadcast lists: one message, each person gets it in our own chat (like WhatsApp).
                    NewChatActionRow(icon: "megaphone.fill", title: L("ch_broadcasts"), subtitle: L("ch_broadcast_hint"), index: 5) { host.push(.broadcasts) }
                    // Channels: discover, follow, or start one.
                    NewChatActionRow(icon: "megaphone.fill", title: L("ch_channels"), subtitle: L("ch_channels_hero_sub"), index: 6) { host.push(.channels) }
                    // Note to self: notes, links and files I keep for myself, on all my devices.
                    NewChatActionRow(icon: "bookmark.fill", title: L("ch_note_to_self"), subtitle: L("ch_note_to_self_sub"), index: 7) { host.openNoteToSelf() }
                    SyncCard(syncing: syncing, synced: synced, denied: denied) {
                        if denied, let url = URL(string: UIApplication.openSettingsURLString) { UIApplication.shared.open(url) } else { askAndSync() }
                    }
                    sectionTitle(L("ch_contacts_on_dorr"), onDorr.count)
                    if contacts == nil && syncing {
                        ForEach(0..<5, id: \.self) { _ in RoundedRectangle(cornerRadius: 18).fill(ch.surfaceMuted).frame(height: 64) }
                    } else if onDorr.isEmpty {
                        Text(L("ch_no_contacts")).font(.dorr(14)).foregroundStyle(ch.soft).frame(maxWidth: .infinity).padding(20)
                    } else {
                        ForEach(Array(onDorr.enumerated()), id: \.element.id) { i, c in
                            ContactRow(host: host, contact: c, index: i) { if let p = c.profile { host.openChatWith(p) } }
                        }
                    }
                    if !invitees.isEmpty {
                        sectionTitle(L("ch_invite_section"), invitees.count)
                        ForEach(Array(invitees.prefix(300).enumerated()), id: \.offset) { i, entry in
                            InviteRow(entry: entry, index: i) { inviting = entry }
                        }
                    }
                }
                .padding(14)
            }
        }
        .task {
            await loadContacts()
            // Every visit: sync the address book (new friends on Dorr show up), or ask for access once.
            switch CNContactStore.authorizationStatus(for: .contacts) {
            case .authorized: await sync()
            case .notDetermined: askAndSync()
            default: denied = true
            }
        }
        .dorrDialog(isPresented: Binding(get: { inviting != nil }, set: { if !$0 { inviting = nil } }), bottom: true) {
            if let e = inviting { InviteSheet(entry: e) { inviting = nil } }
        }
        .dorrDialog(isPresented: Binding(get: { byPhone != nil }, set: { if !$0 { byPhone = nil } }), bottom: true) {
            AddByPhoneSheet(host: host, initial: byPhone ?? "") { byPhone = nil }
        }
        .dorrDialog(isPresented: $joinByCode, bottom: true) {
            TextInputSheet(title: L("ch_join_code"), action: L("ch_join_code_go"), icon: "key.fill", limit: 300, onDismiss: { joinByCode = false }) { text in
                if let t = callLinkTokenOf(text) { host.callLinkToken = t } else if let t = inviteTokenOf(text) { host.joinToken = t } else { host.showToast(L("ch_join_code_bad")) }
            }
        }
        .fullScreenCover(isPresented: $scanning) {
            QrCameraScreen(prompt: "ch_scan_prompt") { text in
                scanning = false
                if let text { scanned(text) }
            }
        }
    }

    private func matches(_ name: String, _ phone: String) -> Bool {
        if query.trimmingCharacters(in: .whitespaces).isEmpty { return true }
        let d = query.filter(\.isNumber)
        return name.localizedCaseInsensitiveContains(query) || (!d.isEmpty && phone.filter(\.isNumber).contains(d))
    }

    private func searchField(looksLikeNumber: Bool, looksLikeUsername: Bool) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 10) {
                Image(systemName: "magnifyingglass").font(.system(size: 16)).foregroundStyle(ch.soft)
                TextField(L("ch_find_by_number"), text: $query).font(.dorr(15)).foregroundStyle(ch.ink).tint(ch.red)
                    .onChange(of: query) { _ in lookupError = nil }
                if looksLikeNumber || looksLikeUsername {
                    Button {
                        if looksLikeUsername {
                            Task {
                                do {
                                    if let p = try await ChatMoreApi.byUsername(query.trimmingCharacters(in: .whitespaces)).data { host.openChatWith(p) }
                                } catch { lookupError = error.apiFailure.message }
                            }
                        } else { byPhone = query }
                    } label: {
                        Text(L("ch_find")).font(.dorr(13, .heavy)).foregroundStyle(.white).padding(.horizontal, 12).padding(.vertical, 6)
                            .background(ch.red, in: RoundedRectangle(cornerRadius: 12))
                    }
                    .buttonStyle(.plain).transition(.scale.combined(with: .opacity))
                }
            }
            .padding(.horizontal, 16).padding(.vertical, 12)
            .background(ch.fieldFill, in: RoundedRectangle(cornerRadius: 18))
            .overlay(RoundedRectangle(cornerRadius: 18).stroke(ch.fieldLine, lineWidth: 1))
            .animation(.spring(response: 0.3, dampingFraction: 0.6), value: looksLikeNumber || looksLikeUsername)
            if let lookupError { Text(verbatim: lookupError).font(.dorr(13, .semibold)).foregroundStyle(ch.danger).padding(.leading, 8) }
        }
    }

    private func shortcut(_ icon: String, _ label: String, _ i: Int, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(spacing: 4) {
                Image(systemName: icon).font(.system(size: 15)).foregroundStyle(.white).frame(width: 36, height: 36).background(ch.headerBrush, in: Circle())
                Text(verbatim: label).font(.dorr(11, .bold)).foregroundStyle(ch.ink).lineLimit(1).minimumScaleFactor(0.8)
            }
            .frame(maxWidth: .infinity).padding(.vertical, 8)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 16))
            .shadow(color: .black.opacity(0.06), radius: 4, y: 2)
        }
        .buttonStyle(AiPressStyle())
        .chStagger(i)
    }

    private func sectionTitle(_ title: String, _ count: Int) -> some View {
        HStack {
            Text(verbatim: title).font(.dorr(13, .bold)).foregroundStyle(ch.mut)
            Spacer()
            if count > 0 { Text(verbatim: "\(count)").font(.dorr(12)).foregroundStyle(ch.soft) }
        }
        .padding(.horizontal, 8).padding(.top, 10)
    }

    private func loadContacts() async {
        // All my saved numbers (registered or not): the ones on Dorr to chat, the rest to invite.
        contacts = (try? await ChatApi.contacts(registered: 0).data) ?? contacts ?? []
    }

    private func askAndSync() {
        CNContactStore().requestAccess(for: .contacts) { granted, _ in
            Task { @MainActor in
                denied = !granted
                if granted { await sync() }
            }
        }
    }

    private func sync() async {
        syncing = true
        let book = await Task.detached { readAddressBook() }.value
        deviceBook = book
        var registered = 0
        var failed = false
        let chunks = stride(from: 0, to: book.count, by: 1500).map { Array(book[$0..<min($0 + 1500, book.count)]) }
        for (i, chunk) in chunks.enumerated() {
            do {
                if let r = try await ChatApi.syncContacts(chunk.map { ($0.name, $0.phone) }, full: book.count <= 1500).data { registered = r.count }
            } catch {
                failed = true
                if i == 0 { host.showToast(error.apiFailure.message ?? L("ch_sync_failed")) }
            }
        }
        synced = failed && registered == 0 ? nil : registered
        await loadContacts()
        syncing = false
    }

    private func scanned(_ text: String) {
        // A call link's QR: its sheet.
        if let t = callLinkTokenOf(text) { host.callLinkToken = t; return }
        // A group's invite QR: the join sheet (join / ask to join), not a person.
        if text.hasPrefix("dorr://chat/join/") || text.range(of: "^https?://[^/]+/j/", options: .regularExpression) != nil {
            if let t = inviteTokenOf(text) { host.joinToken = t }
            return
        }
        Task {
            do {
                guard let p = try await ChatApi.resolveQr(text).data else { return }
                host.openChatWith(p, draft: URLComponents(string: text)?.queryItems?.first { $0.name == "text" }?.value)
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"))
            }
        }
    }
}

/// One line of the new-chat page: an icon on a soft tile, a title, a short line and an arrow (or its own end).
private struct NewChatActionRow<Trailing: View>: View {
    @ObservedObject private var ch = Ch.shared
    let icon: String
    let title: String
    let subtitle: String
    let index: Int
    var enabled = true
    var subtitleColor: Color?
    @ViewBuilder var trailing: () -> Trailing
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 10) {
                Image(systemName: icon).font(.system(size: 15)).foregroundStyle(ch.red)
                    .frame(width: 34, height: 34).background(ch.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: title).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                    Text(verbatim: subtitle).font(.dorr(11)).foregroundStyle(subtitleColor ?? ch.ink.opacity(0.65)).lineLimit(1)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                trailing()
            }
            .padding(.horizontal, 12).padding(.vertical, 8)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .shadow(color: ch.red.opacity(0.07), radius: 6, y: 2)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
        .chStagger(index)
    }
}

extension NewChatActionRow where Trailing == AnyView {
    init(icon: String, title: String, subtitle: String, index: Int, action: @escaping () -> Void) {
        self.init(icon: icon, title: title, subtitle: subtitle, index: index, trailing: {
            AnyView(Image(systemName: "chevron.forward").font(.system(size: 12, weight: .semibold)).foregroundStyle(Ch.shared.red.opacity(0.55)))
        }, action: action)
    }
}

private struct SyncCard: View {
    @ObservedObject private var ch = Ch.shared
    let syncing: Bool
    let synced: Int?
    let denied: Bool
    let onSync: () -> Void
    @State private var spin = false

    var body: some View {
        NewChatActionRow(icon: "person.crop.circle.fill", title: L(syncing ? "ch_syncing" : "ch_sync_contacts"),
                         subtitle: denied && !syncing ? L("ch_contacts_denied") : (synced != nil && !syncing) ? L("ch_synced", synced!) : L("ch_contacts_permission"),
                         index: 8, enabled: !syncing, subtitleColor: denied && !syncing ? ch.danger : nil, trailing: {
                             Image(systemName: "arrow.clockwise").font(.system(size: 15, weight: .semibold)).foregroundStyle(ch.red)
                                 .rotationEffect(.degrees(syncing && spin ? -360 : 0))
                         }, action: onSync)
        .onChange(of: syncing) { s in
            spin = false
            if s { withAnimation(.linear(duration: 1).repeatForever(autoreverses: false)) { spin = true } }
        }
    }
}

struct ContactRow<Trailing: View>: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let contact: ContactDto
    let index: Int
    @ViewBuilder var trailing: () -> Trailing
    let onTap: () -> Void

    var body: some View {
        HStack(spacing: 12) {
            ChAvatar(url: contact.profile?.avatar, name: contact.name, key: contact.profile?.key ?? contact.phone, size: 46,
                     online: contact.profile.flatMap { host.presence[$0.key]?.online } ?? false)
            VStack(alignment: .leading, spacing: 1) {
                Text(verbatim: contact.name).font(.dorr(15, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                Text(verbatim: ltrNumber(contact.phone)).font(.dorr(12.5)).foregroundStyle(ch.mut)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            trailing()
        }
        .padding(12)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 18))
        .contentShape(Rectangle())
        .onTapGesture(perform: onTap)
        .chStagger(index)
    }
}

extension ContactRow where Trailing == EmptyView {
    init(host: ChatHost, contact: ContactDto, index: Int, onTap: @escaping () -> Void) {
        self.init(host: host, contact: contact, index: index, trailing: { EmptyView() }, onTap: onTap)
    }
}

/// Someone in my phone who isn't on Dorr yet: their initials and an «Invite» pill.
private struct InviteRow: View {
    @ObservedObject private var ch = Ch.shared
    let entry: ContactEntry
    let index: Int
    let onInvite: () -> Void

    var body: some View {
        HStack(spacing: 12) {
            ChAvatar(url: nil, name: entry.name, key: entry.phone, size: 42)
            VStack(alignment: .leading, spacing: 1) {
                Text(verbatim: entry.name.isEmpty ? ltrNumber(entry.phone) : entry.name).font(.dorr(14.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                Text(verbatim: ltrNumber(entry.phone)).font(.dorr(12)).foregroundStyle(ch.mut)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            Button(action: onInvite) {
                Text(L("ch_invite")).font(.dorr(12.5, .heavy)).foregroundStyle(ch.red).padding(.horizontal, 14).padding(.vertical, 7)
                    .background(ch.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 12))
            }
            .buttonStyle(.plain)
        }
        .padding(.horizontal, 12).padding(.vertical, 10)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 18))
        .chStagger(index)
    }
}

/// Invite someone who isn't on Dorr: WhatsApp (straight into a chat with that number, message already typed),
/// Telegram, SMS, or «More» — the phone's own share sheet.
struct InviteSheet: View {
    @ObservedObject private var ch = Ch.shared
    let entry: ContactEntry
    let onDismiss: () -> Void

    var body: some View {
        let text = L("ch_invite_text")
        let digits = entry.phone.filter { $0.isNumber || $0 == "+" }
        // wa.me wants the full international number without «+» or a trunk 0.
        let international = internationalDigits(digits, AuthSession.shared.user?.phone)
        let encoded = text.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? ""
        ChSheetCard {
            HStack(spacing: 12) {
                ChAvatar(url: nil, name: entry.name, key: entry.phone, size: 46)
                VStack(alignment: .leading, spacing: 1) {
                    Text(L("ch_invite_title", entry.name.isEmpty ? ltrNumber(entry.phone) : entry.name)).font(.dorr(16, .heavy)).foregroundStyle(ch.ink)
                    Text(verbatim: text).font(.dorr(12.5)).foregroundStyle(ch.mut).lineLimit(2)
                }
            }
            HStack {
                target("WhatsApp", Color(hex: 0x25D366), "message.fill", 0) { open("https://wa.me/\(international)?text=\(encoded)") }
                target("Telegram", Color(hex: 0x229ED9), "paperplane.fill", 1) { open("https://t.me/share/url?url=\(encoded)") }
                target("SMS", Color(hex: 0x6366F1), "text.bubble.fill", 2) { open("sms:\(digits)&body=\(encoded)") }
                target(L("ch_share_more"), Color(hex: 0xDB2777), "square.and.arrow.up", 3) { chShare(text) }
            }
            .padding(.top, 20)
        }
    }

    private func open(_ link: String) {
        onDismiss()
        if let url = URL(string: link) { UIApplication.shared.open(url) { ok in if !ok { Task { @MainActor in chShare(L("ch_invite_text")) } } } }
    }

    private func target(_ label: String, _ color: Color, _ icon: String, _ i: Int, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(spacing: 8) {
                Image(systemName: icon).font(.system(size: 23)).foregroundStyle(.white).frame(width: 58, height: 58).background(color, in: Circle())
                    .shadow(color: color.opacity(0.5), radius: 8, y: 4)
                Text(verbatim: label).font(.dorr(12, .semibold)).foregroundStyle(ch.ink).lineLimit(1)
            }
            .frame(maxWidth: .infinity)
        }
        .buttonStyle(AiPressStyle())
        .chStagger(i)
    }
}

/// The phone's share sheet with a text.
@MainActor func chShare(_ text: String) {
    let controller = UIActivityViewController(activityItems: [text], applicationActivities: nil)
    var top = UIApplication.shared.connectedScenes.compactMap { ($0 as? UIWindowScene)?.keyWindow }.first?.rootViewController
    while let presented = top?.presentedViewController { top = presented }
    Task {
        try? await Task.sleep(nanoseconds: 350_000_000)
        top?.present(controller, animated: true)
    }
}

/// «0100 000 0002» + my own «+20…» → «201000000002» (what wa.me expects).
private func internationalDigits(_ phone: String, _ myPhone: String?) -> String {
    if phone.hasPrefix("+") { return String(phone.dropFirst()) }
    if phone.hasPrefix("00") { return String(phone.dropFirst(2)) }
    // Borrow my own country code: first 1–3 digits of my number that aren't part of a local 0-number.
    let mine = myPhone.flatMap { $0.hasPrefix("+") ? String($0.dropFirst()) : nil } ?? ""
    let national = phone.hasPrefix("0") ? String(phone.dropFirst()) : phone
    let code = [3, 2, 1].map { String(mine.prefix($0)) }.first { !$0.isEmpty && (8...11).contains(mine.count - $0.count) } ?? ""
    return code + national
}

// MARK: - Add by phone

/// A number as typed, read for one country (with or without the code, a trunk 0, spaces, Arabic digits).
private struct PhoneInput {
    let country: CountryDto?
    let national: String
    let complete: Bool
    let wrongStart: Bool
    var e164: String { "+" + dial(country) + national }
}

private func dial(_ c: CountryDto?) -> String { (c?.dialCode ?? "").filter(\.isNumber) }
private func prefixes(_ c: CountryDto?) -> [String] { (c?.phoneStartsWith ?? "").split(separator: ",").map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty } }

private func readPhone(_ raw: String, _ selected: CountryDto?, _ countries: [CountryDto]) -> PhoneInput {
    let western = String(raw.map { c -> Character in
        if let v = c.unicodeScalars.first?.value, (0x660...0x669).contains(v) { return Character(String(v - 0x660)) }
        return c
    })
    let digits = western.filter(\.isNumber)
    let international = western.trimmingCharacters(in: .whitespaces).hasPrefix("+") || digits.hasPrefix("00")
    var country = selected
    var national: String
    if international {
        // The number names its own country: follow it (longest dial code wins).
        let full = digits.hasPrefix("00") ? String(digits.dropFirst(2)) : digits
        country = countries.filter { !dial($0).isEmpty && full.hasPrefix(dial($0)) }.max { dial($0).count < dial($1).count } ?? selected
        national = String(full.dropFirst(full.hasPrefix(dial(country)) ? dial(country).count : 0))
    } else {
        let d = dial(selected)
        if !d.isEmpty, let length = selected?.phoneLength, digits.count == d.count + length, digits.hasPrefix(d) {
            national = String(digits.dropFirst(d.count))
        } else {
            national = digits.hasPrefix("0") ? String(digits.dropFirst()) : digits
        }
    }
    let length = country?.phoneLength
    let starts = prefixes(country)
    let wrongStart = !national.isEmpty && !starts.isEmpty && !starts.contains { national.hasPrefix($0) || $0.hasPrefix(national) }
    // Only a whole number: the country's exact length (when it has one) and a valid first digit.
    let lengthOk = country != nil && (length.map { national.count == $0 } ?? true)
    let complete = lengthOk && national.count >= 6 && !wrongStart && (starts.isEmpty || starts.contains { national.hasPrefix($0) })
    return PhoneInput(country: country, national: national, complete: complete, wrongStart: wrongStart)
}

/// 🇸🇦 from «SA».
func flagEmoji(_ code: String?) -> String {
    guard let code = code?.uppercased(), code.count == 2 else { return "🌐" }
    return code.unicodeScalars.compactMap { UnicodeScalar(0x1F1E6 + $0.value - 65).map(String.init) }.joined()
}

private enum LookupState: Equatable {
    case idle, searching
    case found(ProfileDto)
    case notOnDorr(String)
    case failed(String)
}

/// Start a chat with a phone number: pick the country (defaults to mine), type the number any common way, and
/// «Check» lights up only once it's a whole valid number. Then: their card with «Message» and «Save to contacts» —
/// or, not on Dorr, an invite.
struct AddByPhoneSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    var initial = ""
    var onPicked: ((ProfileDto) -> Void)?
    let onDismiss: () -> Void
    @State private var countries: [CountryDto] = []
    @State private var selected: CountryDto?
    @State private var picking = false
    @State private var raw = ""
    @State private var state: LookupState = .idle
    @State private var inviting: ContactEntry?
    @State private var saved = false
    @FocusState private var focused: Bool

    init(host: ChatHost, initial: String = "", onPicked: ((ProfileDto) -> Void)? = nil, onDismiss: @escaping () -> Void) {
        self.host = host
        self.initial = initial
        self.onPicked = onPicked
        self.onDismiss = onDismiss
        _raw = State(initialValue: initial)
    }

    var body: some View {
        let input = readPhoneInput()
        let length = input.country?.phoneLength
        let tooLong = length.map { input.national.count > $0 } ?? false
        let ring: Color = input.wrongStart || tooLong ? ch.danger : input.complete ? ch.success : ch.red
        let progress: Double = (length ?? 0) == 0 ? (input.complete ? 1 : 0) : min(Double(input.national.count) / Double(length!), 1)
        ChSheetCard {
            if picking {
                CountryPicker(countries: countries) { c in
                    selected = c
                    // The typed number keeps its national part; drop a code written for the old country.
                    if raw.trimmingCharacters(in: .whitespaces).hasPrefix("+") || raw.filter(\.isNumber).hasPrefix("00") { raw = "" }
                    state = .idle
                    picking = false
                }
            } else if let inviting {
                InviteSheet(entry: inviting, onDismiss: onDismiss)
            } else {
                HStack(spacing: 12) {
                    Image(systemName: "person.crop.circle.badge.questionmark").font(.system(size: 20)).foregroundStyle(ch.red)
                        .frame(width: 42, height: 42).background(ch.red.opacity(0.12), in: Circle())
                    VStack(alignment: .leading, spacing: 1) {
                        Text(L("ch_add_by_phone")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                        Text(L("ch_add_by_phone_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut)
                    }
                }
                .padding(.bottom, 16)
                // Country + number (always left to right: numbers read that way).
                HStack(spacing: 0) {
                    Button { picking = true } label: {
                        HStack(spacing: 6) {
                            Text(verbatim: flagEmoji(input.country?.flag?.code ?? input.country?.code)).font(.system(size: 20))
                            Text(verbatim: "+" + dial(input.country)).font(.dorr(15, .heavy)).foregroundStyle(ch.ink)
                            Image(systemName: "chevron.down").font(.system(size: 12, weight: .bold)).foregroundStyle(ch.mut)
                        }
                        .padding(.leading, 14).padding(.trailing, 8).padding(.vertical, 16)
                    }
                    .buttonStyle(.plain)
                    Rectangle().fill(ch.line).frame(width: 1, height: 28)
                    ZStack(alignment: .leading) {
                        if raw.isEmpty { Text(verbatim: examplePhone(input.country)).font(.dorr(16)).foregroundStyle(ch.soft) }
                        TextField("", text: $raw).keyboardType(.phonePad).font(.dorr(16, .bold)).foregroundStyle(ch.ink).tint(ch.red).focused($focused)
                            .onChange(of: raw) { v in
                                if v.count > 24 { raw = String(v.prefix(24)) }
                                state = .idle
                                saved = false
                            }
                    }
                    .padding(.horizontal, 12)
                    // Digits so far, as a filling ring; a tick once the number is whole.
                    ZStack {
                        Circle().stroke(ring.opacity(0.18), lineWidth: 3)
                        Circle().trim(from: 0, to: progress).stroke(ring, style: StrokeStyle(lineWidth: 3, lineCap: .round)).rotationEffect(.degrees(-90))
                        if input.complete { Image(systemName: "checkmark.circle.fill").font(.system(size: 16)).foregroundStyle(ring).transition(.scale) }
                        else { Text(verbatim: "\(input.national.count)").font(.dorr(10, .bold)).foregroundStyle(ch.mut) }
                    }
                    .frame(width: 28, height: 28).padding(.trailing, 12)
                    .animation(.spring(response: 0.35, dampingFraction: 0.7), value: progress)
                }
                .background(ch.fieldFill, in: RoundedRectangle(cornerRadius: 18))
                .overlay(RoundedRectangle(cornerRadius: 18).stroke(raw.isEmpty ? ch.fieldLine : ring.opacity(0.8), lineWidth: raw.isEmpty ? 1 : 1.5))
                .environment(\.layoutDirection, .leftToRight)
                // What's missing.
                let hint: String = input.wrongStart ? L("ch_phone_wrong_start", prefixes(input.country).joined(separator: " / "))
                    : tooLong ? L("ch_phone_too_long", length!)
                    : (length != nil && !raw.isEmpty && !input.complete) ? L("ch_phone_digits_left", length! - input.national.count)
                    : L("ch_phone_any_format")
                Text(verbatim: hint).font(.dorr(12)).foregroundStyle(input.wrongStart || tooLong ? ch.danger : ch.mut).padding(.leading, 6).padding(.top, 6)
                ChPrimaryButton(text: L("ch_check_number"), icon: "magnifyingglass", enabled: input.complete && state != .searching) { check(input) }.padding(.top, 14)
                result
            }
        }
        .task {
            countries = (try? await CountryApi.list().data) ?? []
            // My own country: the one my phone number starts with, else the default.
            let mine = (AuthSession.shared.user?.phone ?? "").filter(\.isNumber)
            selected = countries.filter { !dial($0).isEmpty && mine.hasPrefix(dial($0)) }.max { dial($0).count < dial($1).count }
                ?? countries.first { $0.isDefault } ?? countries.first
            focused = true
        }
    }

    private func readPhoneInput() -> PhoneInput { readPhone(raw, selected, countries) }

    @ViewBuilder
    private var result: some View {
        switch state {
        case .idle: EmptyView()
        case .searching: ProgressView().tint(ch.red).frame(maxWidth: .infinity).padding(.top, 22)
        case .found(let p):
            VStack(spacing: 0) {
                ChAvatar(url: p.avatar, name: p.name, key: p.key, size: 72)
                Text(verbatim: p.name ?? "").font(.dorr(17, .heavy)).foregroundStyle(ch.ink).padding(.top, 10)
                Text(verbatim: ltrNumber(p.phone)).font(.dorr(13)).foregroundStyle(ch.mut)
                HStack(spacing: 10) {
                    if !p.isContact && !saved {
                        Button {
                            Task {
                                do {
                                    _ = try await ChatApi.addContact(name: (p.name ?? "").isEmpty ? (p.phone ?? "") : p.name!, phone: p.phone ?? "")
                                    saved = true
                                    host.showToast(L("ch_contact_saved"))
                                } catch { if let m = error.apiFailure.message { host.showToast(m) } }
                            }
                        } label: {
                            HStack(spacing: 6) {
                                Image(systemName: "person.badge.plus").font(.system(size: 15))
                                Text(L("ch_save_contact")).font(.dorr(13.5, .heavy))
                            }
                            .foregroundStyle(ch.red).frame(maxWidth: .infinity).padding(.vertical, 13)
                            .background(ch.surface, in: RoundedRectangle(cornerRadius: 16))
                        }
                        .buttonStyle(.plain)
                    }
                    // Picking people for a group: add them to the selection instead of opening a chat.
                    if let onPicked {
                        ChPrimaryButton(text: L("ch_add_to_group"), icon: "person.badge.plus") { onPicked(p); onDismiss() }
                    } else {
                        ChPrimaryButton(text: L("ch_message"), icon: "bubble.left.fill") { onDismiss(); host.openChatWith(p) }
                    }
                }
                .padding(.top, 14)
            }
            .frame(maxWidth: .infinity).padding(16)
            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 22))
            .padding(.top, 16)
            .transition(.move(edge: .bottom).combined(with: .opacity))
        case .notOnDorr(let e164):
            VStack(spacing: 6) {
                Text(verbatim: "🙈").font(.system(size: 34))
                Text(L("ch_number_not_on_dorr", ltrNumber(e164))).font(.dorr(14, .bold)).foregroundStyle(ch.ink).multilineTextAlignment(.center)
                ChPrimaryButton(text: L("ch_invite"), icon: "paperplane.fill") { inviting = ContactEntry(name: "", phone: e164) }.padding(.top, 6)
            }
            .frame(maxWidth: .infinity).padding(16)
            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 22))
            .padding(.top, 16)
        case .failed(let message):
            HStack(spacing: 6) {
                Image(systemName: "exclamationmark.circle").foregroundStyle(ch.danger)
                Text(verbatim: message).font(.dorr(13, .semibold)).foregroundStyle(ch.danger)
            }
            .padding(.top, 14)
        }
    }

    private func check(_ input: PhoneInput) {
        guard input.complete else { return }
        let e164 = input.e164
        withAnimation { state = .searching }
        Task {
            let next: LookupState
            do {
                if let p = try await ChatApi.lookup(["phone": e164, "country_code": input.country?.code ?? ""]).data { next = .found(p) } else { next = .notOnDorr(e164) }
            } catch {
                let f = error.apiFailure
                next = f.httpStatus == 404 ? .notOnDorr(e164) : .failed(f.message ?? L("ch_not_on_dorr"))
            }
            withAnimation(.easeOut(duration: 0.22)) { state = next }
        }
    }

    /// «5X XXX XXXX» — how long a number is and what it starts with, for the placeholder.
    private func examplePhone(_ country: CountryDto?) -> String {
        guard let length = country?.phoneLength else { return "5X XXX XXXX" }
        let start = prefixes(country).first ?? ""
        let s = start + String(repeating: "X", count: max(length - start.count, 0))
        return stride(from: 0, to: s.count, by: 3).map { i -> String in
            let a = s.index(s.startIndex, offsetBy: i), b = s.index(a, offsetBy: min(3, s.count - i))
            return String(s[a..<b])
        }.joined(separator: " ")
    }
}

private struct CountryPicker: View {
    @ObservedObject private var ch = Ch.shared
    let countries: [CountryDto]
    let onPick: (CountryDto) -> Void
    @State private var query = ""

    var body: some View {
        let d = query.filter(\.isNumber)
        let shown = countries.filter { query.isEmpty || $0.name.localizedCaseInsensitiveContains(query) || (!d.isEmpty && $0.dialCode.contains(d)) || $0.code.caseInsensitiveCompare(query) == .orderedSame }
        ChField(placeholder: L("ch_search_country"), text: $query, icon: "magnifyingglass")
        ScrollView {
            LazyVStack(spacing: 0) {
                ForEach(shown) { c in
                    Button { onPick(c) } label: {
                        HStack(spacing: 12) {
                            Text(verbatim: flagEmoji(c.flag?.code ?? c.code)).font(.system(size: 22))
                            Text(verbatim: c.name).font(.dorr(14.5, .bold)).foregroundStyle(ch.ink).frame(maxWidth: .infinity, alignment: .leading)
                            Text(verbatim: "+" + dial(c)).font(.dorr(13.5, .bold)).foregroundStyle(ch.mut).environment(\.layoutDirection, .leftToRight)
                        }
                        .padding(.horizontal, 8).padding(.vertical, 12).contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
            }
        }
        .frame(maxHeight: 420)
        .padding(.top, 8)
    }
}

// MARK: - New group / add members

struct NewGroupPage: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let addTo: String?
    // nil = still loading (skeletons), [] = none on Dorr (the empty state).
    @State private var contacts: [ContactDto]?
    @State private var selected: [ContactDto] = []
    @State private var query = ""
    @State private var byPhone = false
    @State private var step = 0
    @State private var name = ""
    @State private var description = ""
    @State private var avatarItem: PhotosPickerItem?
    @State private var avatar: UIImage?
    @State private var busy = false
    @State private var inGroupIds = Set<Int>()
    @State private var visible = 15

    var body: some View {
        ChSettingsPage(title: L(addTo != nil ? "ch_add_members" : "ch_new_group"), onBack: { if step == 1 { withAnimation { step = 0 } } else { host.pop() } }) {
            ZStack {
                if step == 0 { pickStep.transition(.move(edge: .leading).combined(with: .opacity)) } else { detailsStep.transition(.move(edge: .trailing).combined(with: .opacity)) }
            }
            .animation(.easeInOut(duration: 0.25), value: step)
        }
        .task {
            contacts = (try? await ChatApi.contacts(registered: 1).data) ?? []
            if let addTo { inGroupIds = Set(((try? await ChatApi.members(addTo).data) ?? []).compactMap { $0.profile?.id }) }
        }
        .dorrDialog(isPresented: $byPhone, bottom: true) {
            AddByPhoneSheet(host: host, onPicked: { addFound($0) }) { byPhone = false }
        }
        .onChange(of: avatarItem) { item in
            Task { if let data = try? await item?.loadTransferable(type: Data.self) { avatar = UIImage(data: data) } }
        }
    }

    private var shown: [ContactDto] {
        let d = query.filter(\.isNumber)
        return (contacts ?? []).filter { query.isEmpty || $0.name.localizedCaseInsensitiveContains(query) || (!d.isEmpty && $0.phone.filter(\.isNumber).contains(d)) }
    }

    private var pickStep: some View {
        ZStack(alignment: .bottomTrailing) {
            VStack(spacing: 0) {
                // Search + «add by number»: always there, even with an empty address book.
                HStack(spacing: 8) {
                    ChField(placeholder: L("ch_search_contacts"), text: $query, icon: "magnifyingglass")
                    Button { byPhone = true } label: {
                        Image(systemName: "circle.grid.3x3.fill").font(.system(size: 19)).foregroundStyle(.white)
                            .frame(width: 46, height: 46).background(ch.headerBrush, in: RoundedRectangle(cornerRadius: 16))
                    }
                    .buttonStyle(.plain)
                }
                .padding(.horizontal, 14).padding(.vertical, 8)
                // Newly picked people — not those already in the group.
                if !selected.isEmpty {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 10) {
                            ForEach(selected) { c in
                                VStack(spacing: 2) {
                                    ZStack(alignment: .bottomTrailing) {
                                        ChAvatar(url: c.profile?.avatar, name: c.name, key: c.profile?.key, size: 52).chPopIn(true)
                                        Image(systemName: "xmark").font(.system(size: 9, weight: .bold)).foregroundStyle(.white)
                                            .frame(width: 20, height: 20).background(ch.mut, in: Circle()).overlay(Circle().stroke(.white, lineWidth: 2))
                                    }
                                    Text(verbatim: c.name).font(.dorr(11)).foregroundStyle(ch.ink).lineLimit(1)
                                }
                                .frame(width: 62)
                                .onTapGesture { toggle(c) }
                            }
                        }
                        .padding(.horizontal, 14).padding(.vertical, 10)
                    }
                    .transition(.move(edge: .top).combined(with: .opacity))
                }
                if contacts?.isEmpty == false {
                    Text(L("ch_selected", selected.count)).font(.dorr(13, .bold)).foregroundStyle(ch.mut).frame(maxWidth: .infinity, alignment: .leading)
                        .padding(.leading, 22).padding(.vertical, 4)
                }
                if contacts == nil {
                    VStack(spacing: 8) { ForEach(0..<6, id: \.self) { _ in RoundedRectangle(cornerRadius: 18).fill(ch.surfaceMuted).frame(height: 66) } }
                        .padding(.horizontal, 14)
                    Spacer()
                } else if contacts!.isEmpty {
                    NoContactsForGroup(onByNumber: { byPhone = true }, onInvite: { chShare(L("ch_invite_text")) })
                } else if shown.isEmpty {
                    Text(L("ch_no_results")).font(.dorr(14)).foregroundStyle(ch.soft).padding(30)
                    Spacer()
                } else {
                    ScrollView {
                        LazyVStack(spacing: 6) {
                            ForEach(Array(shown.prefix(visible).enumerated()), id: \.element.id) { i, c in
                                let on = inGroup(c) || selected.contains { $0.id == c.id }
                                ContactRow(host: host, contact: c, index: i, trailing: {
                                    Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 24)).foregroundStyle(on ? ch.red : ch.soft)
                                        .animation(.spring(response: 0.3, dampingFraction: 0.4), value: on)
                                }) { toggle(c) }
                                .onAppear { if i >= visible - 3 && visible < shown.count { visible = min(visible + 15, shown.count) } }
                            }
                        }
                        .padding(.horizontal, 14).padding(.bottom, 110)
                    }
                }
            }
            if !selected.isEmpty {
                Button { if addTo != nil { submit() } else { withAnimation { step = 1 } } } label: {
                    Image(systemName: "arrow.forward").font(.system(size: 18, weight: .bold)).foregroundStyle(.white)
                        .frame(width: 46, height: 46).background(ch.headerBrush, in: Circle())
                        .shadow(color: ch.red.opacity(0.45), radius: 8, y: 3)
                }
                .buttonStyle(.plain).disabled(busy)
                .padding(16)
                .transition(.scale.combined(with: .opacity))
            }
        }
        .animation(.spring(response: 0.35, dampingFraction: 0.7), value: selected.map(\.id))
        .onChange(of: query) { _ in visible = 15 }
    }

    private var detailsStep: some View {
        VStack(spacing: 0) {
            PhotosPicker(selection: $avatarItem, matching: .images) {
                ZStack {
                    Circle().fill(ch.headerBrush)
                    if let avatar { Image(uiImage: avatar).resizable().scaledToFill() } else { Image(systemName: "camera.fill").font(.system(size: 34)).foregroundStyle(.white) }
                }
                .frame(width: 112, height: 112).clipShape(Circle())
                .shadow(color: ch.red.opacity(0.35), radius: 14, y: 6)
            }
            ChField(placeholder: L("ch_group_name_hint"), text: $name, icon: "person.3.fill", limit: 100).padding(.top, 22)
            ChField(placeholder: L("ch_group_desc_hint"), text: $description, icon: "text.alignleft", lines: 1...4, limit: 500).padding(.top, 10)
            Text(L("ch_selected", selected.count)).font(.dorr(13)).foregroundStyle(ch.mut).padding(.top, 18)
            Spacer()
            ChPrimaryButton(text: L("ch_create_group"), icon: "person.3.fill", enabled: !name.trimmingCharacters(in: .whitespaces).isEmpty && !busy) { submit() }
        }
        .padding(20)
    }

    private func inGroup(_ c: ContactDto) -> Bool { c.profile.map { inGroupIds.contains($0.id) } ?? false }

    private func toggle(_ c: ContactDto) {
        if inGroup(c) { return }
        if selected.contains(where: { $0.id == c.id }) { selected.removeAll { $0.id == c.id } } else { selected.append(c) }
    }

    /// People found by number join the list as if they were contacts (negative ids never clash).
    private func addFound(_ p: ProfileDto) {
        if !(contacts ?? []).contains(where: { $0.profile?.id == p.id }) {
            contacts = [ContactDto(id: -p.id, name: p.name ?? p.phone ?? "", phone: p.phone ?? "", isFavorite: false, source: "lookup", isRegistered: true, profile: p)] + (contacts ?? [])
        }
        if let existing = contacts?.first(where: { $0.profile?.id == p.id }), !inGroupIds.contains(p.id), !selected.contains(where: { $0.id == existing.id }) {
            selected.append(existing)
        }
    }

    private func submit() {
        busy = true
        Task {
            do {
                let ids = selected.compactMap { $0.profile?.id }
                if let addTo {
                    // The answer says who couldn't be added (their privacy): say so instead of closing as if it worked.
                    let result = try await ChatApi.addMembers(addTo, participants: ids).data ?? nil
                    let notAdded = result?.at("not_added")?.arr?.count ?? 0
                    let added = result?.at("added")?.arr?.count ?? 0
                    if notAdded > 0 { host.showToast(L("ch_not_added", notAdded) + (added == 0 ? " — " + L("ch_try_invite_link") : "")) }
                    if added > 0 || notAdded == 0 { host.pop() }
                } else {
                    var fields = ["name": name.trimmingCharacters(in: .whitespaces), "description": description.trimmingCharacters(in: .whitespaces)]
                    for (i, id) in ids.enumerated() { fields["members[\(i)]"] = String(id) }
                    let file = avatar.flatMap { $0.jpegData(compressionQuality: 0.85) }.map { ChatFile(field: "avatar", filename: "avatar.jpg", mimeType: "image/jpeg", data: $0) }
                    if let created = try await ChatApi.createGroup(fields: fields, avatar: file).data {
                        host.upsert(created.conversation)
                        if let n = created.notAdded, !n.isEmpty { host.showToast(L("ch_not_added", n.count)) }
                        host.replace(.conversation(created.conversation.id))
                    }
                }
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
            busy = false
        }
    }
}

/// Nobody from the address book is on Dorr yet: floating chat bubbles around a group icon, and the two ways forward.
private struct NoContactsForGroup: View {
    @ObservedObject private var ch = Ch.shared
    let onByNumber: () -> Void
    let onInvite: () -> Void
    @State private var float = false

    var body: some View {
        VStack(spacing: 0) {
            Spacer()
            ZStack {
                Circle().fill(ch.red.opacity(0.06)).frame(width: 170, height: 170)
                Circle().fill(ch.red.opacity(0.09)).frame(width: 128, height: 128)
                Image(systemName: "person.3.fill").font(.system(size: 36)).foregroundStyle(.white)
                    .frame(width: 88, height: 88).background(ch.headerBrush, in: Circle())
                    .shadow(color: ch.red.opacity(0.45), radius: 16, y: 6)
                    .offset(y: float ? -8 : 0)
                Text(verbatim: "💬").font(.system(size: 26)).rotationEffect(.degrees(-12)).offset(x: -62, y: -50 + (float ? 10 : 0))
                Text(verbatim: "👋").font(.system(size: 24)).offset(x: 62, y: -36 - (float ? 12 : 0))
                Text(verbatim: "🎉").font(.system(size: 22)).offset(x: -52 + (float ? 8 : 0), y: 58)
            }
            .frame(width: 190, height: 190)
            .environment(\.layoutDirection, .leftToRight)
            Text(L("ch_group_empty_title")).font(.dorr(19, .heavy)).foregroundStyle(ch.ink).multilineTextAlignment(.center).padding(.top, 10)
            Text(L("ch_group_empty_sub")).font(.dorr(13.5)).foregroundStyle(ch.mut).multilineTextAlignment(.center).padding(.top, 6)
            ChPrimaryButton(text: L("ch_add_by_phone"), icon: "circle.grid.3x3.fill", action: onByNumber).padding(.top, 22)
            Button(action: onInvite) {
                Text(L("ch_invite_friends")).font(.dorr(14.5, .heavy)).foregroundStyle(ch.red).frame(maxWidth: .infinity).padding(.vertical, 14)
                    .overlay(RoundedRectangle(cornerRadius: 18).stroke(ch.red.opacity(0.35), lineWidth: 1.5))
            }
            .buttonStyle(.plain).padding(.top, 10)
            Spacer()
        }
        .padding(.horizontal, 28)
        .onAppear { withAnimation(.easeInOut(duration: 2.6).repeatForever(autoreverses: true)) { float = true } }
    }
}

// MARK: - My QR

struct MyQrPage: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    @State private var qr: QrDto?
    // «Click to chat» (backlog 130): a message ready in the chat the link or QR opens.
    @State private var ready = ""
    @State private var link: ChatLinkDto?
    @FocusState private var typing: Bool

    var body: some View {
        ChSettingsPage(title: L("ch_my_qr_title"), onBack: { host.pop() }) {
            ScrollView {
                VStack(spacing: 0) {
                    card
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("bk_chat_link")).font(.dorr(15, .heavy)).foregroundStyle(ch.ink)
                        Text(L("bk_chat_link_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut)
                        ChField(placeholder: L("bk_chat_link_text"), text: $ready, icon: "text.bubble", limit: 500).focused($typing).padding(.top, 12)
                        if let l = link { linkCard(l) }
                    }
                    .padding(.top, 18)
                }
                .padding(.horizontal, 16).padding(.vertical, 18)
            }
        }
        .task {
            async let q = try? ChatApi.myQr().data
            async let l = try? ChatApi.chatLink(text: nil).data
            qr = await q
            link = await l
        }
        .task(id: ready) {
            guard !ready.trimmingCharacters(in: .whitespaces).isEmpty else { return }
            try? await Task.sleep(nanoseconds: 400_000_000)
            guard !Task.isCancelled else { return }
            link = (try? await ChatApi.chatLink(text: ready.trimmingCharacters(in: .whitespaces)).data) ?? link
        }
    }

    private var card: some View {
        let payload = link?.payload ?? qr?.payload
        return VStack(spacing: 0) {
            ZStack {
                RoundedRectangle(cornerRadius: 18).fill(.white)
                if let payload, let m = QrMatrix(text: payload) { QrCanvas(matrix: m).padding(typing ? 6 : 10) }
                else { RoundedRectangle(cornerRadius: 12).fill(ch.surfaceMuted).padding(10) }
            }
            .frame(width: typing ? 128 : 210, height: typing ? 128 : 210)
            if !typing {
                Text(L("ch_my_qr_sub")).font(.dorr(13.5, .bold)).foregroundStyle(.white).multilineTextAlignment(.center).padding(.horizontal, 22).padding(.top, 14)
                ZStack {
                    Line().stroke(Color.white.opacity(0.32), style: StrokeStyle(lineWidth: 1.5, dash: [5, 7])).frame(height: 1).padding(.horizontal, 16)
                    HStack {
                        Circle().fill(ch.bg).frame(width: 18, height: 18).offset(x: -9)
                        Spacer()
                        Circle().fill(ch.bg).frame(width: 18, height: 18).offset(x: 9)
                    }
                }
                .frame(height: 18).padding(.vertical, 12)
                Button {
                    Task {
                        qr = (try? await ChatApi.resetQr().data) ?? qr
                        host.showToast(L("ch_qr_reset_done"))
                    }
                } label: {
                    HStack(spacing: 7) {
                        Image(systemName: "arrow.clockwise").font(.system(size: 13, weight: .bold))
                        Text(L("ch_reset_qr")).font(.dorr(13, .bold)).lineLimit(1)
                    }
                    .foregroundStyle(.white).frame(maxWidth: .infinity).padding(12)
                    .background(Color.white.opacity(0.14), in: RoundedRectangle(cornerRadius: 18))
                }
                .buttonStyle(.plain).padding(.horizontal, 14)
            }
        }
        .padding(.top, typing ? 12 : 18).padding(.bottom, typing ? 12 : 16)
        .frame(maxWidth: .infinity)
        .background(ch.headerBrush, in: RoundedRectangle(cornerRadius: 28))
        .shadow(color: ch.red.opacity(0.3), radius: 16, y: 6)
        .animation(.easeInOut(duration: 0.25), value: typing)
    }

    private func linkCard(_ l: ChatLinkDto) -> some View {
        let hostPart = l.link.components(separatedBy: "://").last?.components(separatedBy: "/").first ?? ""
        return VStack(spacing: 12) {
            Button { chShare(l.link) } label: {
                HStack(spacing: 10) {
                    Image(systemName: "link").font(.system(size: 16)).foregroundStyle(ch.red).frame(width: 36, height: 36).background(ch.red.opacity(0.12), in: Circle())
                    VStack(alignment: .leading, spacing: 1) {
                        Text(L("ch_conversation_link")).font(.dorr(14, .bold)).foregroundStyle(ch.ink)
                        Text(verbatim: hostPart.isEmpty ? l.link : "…/\(hostPart)").font(.dorr(12.5)).foregroundStyle(ch.red).lineLimit(1)
                    }
                    Spacer()
                }
                .padding(.horizontal, 14).padding(.vertical, 12)
                .background(ch.surface, in: RoundedRectangle(cornerRadius: 18))
                .overlay(RoundedRectangle(cornerRadius: 18).stroke(ch.line, lineWidth: 1))
            }
            .buttonStyle(.plain)
            HStack(spacing: 10) {
                Button {
                    UIPasteboard.general.string = l.link
                    host.showToast(L("bk_copied"))
                } label: {
                    Label(L("ch_copy_link"), systemImage: "doc.on.doc.fill").font(.dorr(13, .heavy)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity).padding(.vertical, 12).background(ch.red, in: RoundedRectangle(cornerRadius: 16))
                }
                .buttonStyle(.plain)
                Button { chShare(l.link) } label: {
                    Label(L("ch_share_link"), systemImage: "square.and.arrow.up").font(.dorr(13, .heavy)).foregroundStyle(ch.red)
                        .frame(maxWidth: .infinity).padding(.vertical, 12).background(ch.surface, in: Capsule()).overlay(Capsule().stroke(ch.line, lineWidth: 1))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.top, 12)
    }
}

private struct Line: Shape {
    func path(in r: CGRect) -> Path {
        var p = Path()
        p.move(to: CGPoint(x: r.minX, y: r.midY))
        p.addLine(to: CGPoint(x: r.maxX, y: r.midY))
        return p
    }
}
