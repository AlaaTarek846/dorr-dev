import ContactsUI
import SwiftUI

// androidApp `ContactActionsSheet.kt` — a shared contact's actions, and «Sara isn't in your contacts» on top of a chat.

/// Tapping a shared contact: call it with the phone's own dialer, save it to the phone's contacts, message it on Dorr —
/// or invite it when it isn't on Dorr — and copy the number from the row under the buttons. The same number written
/// two ways counts once.
struct ContactActionsSheet: View {
    @ObservedObject private var ch = Ch.shared
    let name: String
    let phones: [String]
    let onMessage: (String) -> Void
    let onDismiss: () -> Void
    @State private var phone = ""
    private var numbers: [String] { distinctPhones(phones) }
    /// On Dorr? nil while checking (and when not).
    @State private var onDorr: ProfileDto?
    @State private var checked = false
    @State private var inviting = false
    @State private var saving = false

    var body: some View {
        if inviting {
            InviteSheet(entry: ContactEntry(name: name, phone: phone)) { inviting = false; onDismiss() }
        } else {
            ChSheetCard {
                VStack(spacing: 0) {
                    ChAvatar(url: onDorr?.avatar, name: name, key: onDorr?.key ?? phone, size: 72, ring: onDorr != nil)
                    Text(verbatim: name.isEmpty ? ltrNumber(phone) : name).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).multilineTextAlignment(.center).padding(.top, 10)
                    Group {
                        if !checked {
                            ProgressView().tint(ch.red).scaleEffect(0.7).frame(height: 26)
                        } else {
                            DorrStatusPill(onDorr: onDorr != nil)
                        }
                    }
                    .padding(.top, 8)
                    let there = onDorr != nil
                    HStack(spacing: 8) {
                        tile(there ? "bubble.left.and.bubble.right.fill" : "paperplane.fill", there ? L("ch_message_contact") : L("ch_invite"), there ? ch.red : Color(hex: 0x6366F1), filled: true, enabled: there || checked) {
                            if there {
                                onDismiss()
                                onMessage(phone)
                            } else {
                                inviting = true
                            }
                        }
                        tile("phone.fill", L("ch_contact_call"), Color(hex: 0x10B981), filled: false) {
                            if let url = URL(string: "tel:\(phone.filter { $0.isNumber || $0 == "+" })") { UIApplication.shared.open(url) }
                            onDismiss()
                        }
                        tile("person.badge.plus", L("ch_contact_save"), Color(hex: 0x0EA5E9), filled: false) { saving = true }
                    }
                    .padding(.top, 18)
                    if !numbers.isEmpty {
                        VStack(spacing: 8) {
                            ForEach(numbers, id: \.self) { p in
                                let picked = numbers.count > 1 && p == phone
                                HStack(spacing: 8) {
                                    VStack(alignment: .leading, spacing: 1) {
                                        Text(L("ch_phone_mobile")).font(.dorr(11, .semibold)).foregroundStyle(ch.soft)
                                        Text(verbatim: ltrNumber(p)).font(.dorr(15, .bold)).foregroundStyle(ch.ink)
                                    }
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    Button {
                                        UIPasteboard.general.string = p
                                        AppToast.show(L("ch_number_copied"))
                                    } label: {
                                        Image(systemName: "doc.on.doc").font(.system(size: 16, weight: .semibold)).foregroundStyle(Color(hex: 0xF59E0B))
                                            .frame(width: 36, height: 36)
                                    }
                                    .buttonStyle(.plain)
                                }
                                .padding(.horizontal, 14).padding(.vertical, 10)
                                .background(picked ? ch.red.opacity(0.12) : ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 16))
                                .contentShape(RoundedRectangle(cornerRadius: 16))
                                .onTapGesture { phone = p }
                            }
                        }
                        .padding(.top, 14)
                    }
                }
                .frame(maxWidth: .infinity)
                .padding(.bottom, 10)
            }
            .onAppear { if phone.isEmpty { phone = numbers.first ?? "" } }
            .task(id: phone) {
                guard !phone.isEmpty else { return }
                checked = false
                let found = try? await ChatApi.lookup(["phone": phone]).data
                onDorr = found?.isMe == true ? nil : found
                checked = true
            }
            .sheet(isPresented: $saving) {
                NewPhoneContact(name: name, phone: phone) {
                    saving = false
                    onDismiss()
                }
                .ignoresSafeArea()
            }
        }
    }

    private func tile(_ icon: String, _ label: String, _ color: Color, filled: Bool, enabled: Bool = true, _ tap: @escaping () -> Void) -> some View {
        let fg = filled ? Color.white : color
        return Button(action: tap) {
            VStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 20, weight: .semibold)).foregroundStyle(fg)
                Text(verbatim: label).font(.dorr(12, .bold)).foregroundStyle(fg).lineLimit(1)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 12)
            .background(filled ? color : ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 16))
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
        .opacity(enabled ? 1 : 0.4)
    }
}

/// «موجود على دُر» in green, «غير موجود على دُر» in the soft colour. The dot sits on the visual left.
private struct DorrStatusPill: View {
    @ObservedObject private var ch = Ch.shared
    let onDorr: Bool

    var body: some View {
        let color = onDorr ? ch.success : ch.soft
        HStack(spacing: 6) {
            Text(L(onDorr ? "ch_contact_on_dorr" : "ch_contact_not_on_dorr")).font(.dorr(12.5, .bold)).foregroundStyle(color)
            Circle().fill(color).frame(width: 6, height: 6)
        }
        .padding(.horizontal, 10).padding(.vertical, 4)
        .background(color.opacity(0.14), in: Capsule())
    }
}

/// Same digits, written with or without spaces, are one number. The first spelling is kept.
private func distinctPhones(_ phones: [String]) -> [String] {
    var seen = Set<String>()
    return phones.filter { p in
        let key = String(p.filter(\.isNumber))
        !key.isEmpty && seen.insert(key).inserted
    }
}

/// The phone's own «new contact» page, filled in (Android: the contacts app's insert screen).
struct NewPhoneContact: UIViewControllerRepresentable {
    let name: String
    let phone: String
    let onDone: () -> Void

    func makeCoordinator() -> Coordinator { Coordinator(onDone: onDone) }

    func makeUIViewController(context: Context) -> UINavigationController {
        let contact = CNMutableContact()
        contact.givenName = name
        contact.phoneNumbers = [CNLabeledValue(label: CNLabelPhoneNumberMobile, value: CNPhoneNumber(stringValue: phone))]
        let page = CNContactViewController(forNewContact: contact)
        page.contactStore = CNContactStore()
        page.delegate = context.coordinator
        return UINavigationController(rootViewController: page)
    }

    func updateUIViewController(_ controller: UINavigationController, context: Context) {}

    final class Coordinator: NSObject, CNContactViewControllerDelegate {
        let onDone: () -> Void
        init(onDone: @escaping () -> Void) { self.onDone = onDone }
        func contactViewController(_ viewController: CNContactViewController, didCompleteWith contact: CNContact?) { onDone() }
    }
}

/// Is this a one-to-one chat with someone I haven't saved? (Not a group, not my own notes.)
func conversationNeedsContact(_ c: ConversationDto?) -> Bool {
    guard let c, !c.isGroup, c.isSelf != true, c.isRequest != true, let peer = c.peer else { return false }
    return !peer.isContact && !peer.isDeleted
}

/// «Sara isn't in your contacts» — on top of a chat with someone I haven't saved, like WhatsApp, with Add (and why it
/// matters: they'll see my stories). Closable for this visit.
struct NotInContactsBanner: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let conversation: ConversationDto
    let onAdded: (ConversationDto) -> Void
    @State private var closed = false
    @State private var saving = false

    var body: some View {
        if !closed && conversationNeedsContact(conversation) {
            HStack(spacing: 10) {
                Image(systemName: "person.badge.plus").font(.system(size: 17)).foregroundStyle(ch.red)
                    .frame(width: 36, height: 36).background(ch.red.opacity(0.12), in: Circle())
                VStack(alignment: .leading, spacing: 1) {
                    Text(L("ch_not_in_contacts")).font(.dorr(13.5, .heavy)).foregroundStyle(ch.ink).lineLimit(1)
                    Text(L("ch_not_in_contacts_why")).font(.dorr(11.5)).foregroundStyle(ch.mut).lineLimit(2)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Button(action: add) {
                    Group {
                        if saving { ProgressView().tint(.white).scaleEffect(0.65) } else { Text(L("ch_add")).font(.dorr(12.5, .heavy)).foregroundStyle(.white) }
                    }
                    .padding(.horizontal, 14).padding(.vertical, 6)
                    .background(ch.red, in: Capsule())
                }
                .buttonStyle(.plain)
                .disabled(saving)
                Button { withAnimation { closed = true } } label: {
                    Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(ch.soft).frame(width: 30, height: 30)
                }
                .buttonStyle(.plain)
            }
            .padding(.leading, 10).padding(.trailing, 4).padding(.vertical, 8)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .padding(.horizontal, 12).padding(.vertical, 6)
            .transition(.move(edge: .top).combined(with: .opacity))
        }
    }

    /// Save them to my Dorr contacts — so they see my stories and appear under «Contacts on Dorr».
    private func add() {
        guard let peer = conversation.peer, let phone = peer.phone, !phone.isEmpty else { return }
        saving = true
        Task {
            do {
                _ = try await ChatApi.addContact(name: peer.accountName ?? peer.name ?? phone, phone: phone)
                if let fresh = try await ChatApi.conversation(conversation.id).data { onAdded(fresh) }
                host.showToast(L("ch_contact_added"))
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
            saving = false
        }
    }
}
