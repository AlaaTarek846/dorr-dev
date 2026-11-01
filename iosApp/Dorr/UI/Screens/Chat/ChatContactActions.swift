import ContactsUI
import SwiftUI

// androidApp `ContactActionsSheet.kt` — a shared contact's actions, and «Sara isn't in your contacts» on top of a chat.

/// Tapping a shared contact: call it with the phone's own dialer, save it to the phone's contacts, message it on Dorr —
/// or invite it when it isn't on Dorr yet — and copy the number. A card with several numbers lets you pick one first.
struct ContactActionsSheet: View {
    @ObservedObject private var ch = Ch.shared
    let name: String
    let phones: [String]
    let onMessage: (String) -> Void
    let onDismiss: () -> Void
    @State private var phone = ""
    /// On Dorr? nil while checking (and when not).
    @State private var onDorr: ProfileDto?
    @State private var checked = false
    @State private var inviting = false
    @State private var saving = false
    @State private var shown = false

    var body: some View {
        if inviting {
            InviteSheet(entry: ContactEntry(name: name, phone: phone)) { inviting = false; onDismiss() }
        } else {
            ChSheetCard {
                VStack(spacing: 0) {
                    ChAvatar(url: onDorr?.avatar, name: name, key: onDorr?.key ?? phone, size: 72, ring: onDorr != nil)
                    Text(verbatim: name.isEmpty ? ltrNumber(phone) : name).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).multilineTextAlignment(.center).padding(.top, 10)
                    Text(verbatim: ltrNumber(phone)).font(.dorr(14, .semibold)).foregroundStyle(ch.mut)
                    // On Dorr or not, once known.
                    Group {
                        if !checked {
                            ProgressView().tint(ch.red).scaleEffect(0.7)
                        } else if onDorr != nil {
                            Text(L("ch_contact_on_dorr")).font(.dorr(12.5, .bold)).foregroundStyle(ch.success)
                        } else {
                            Text(L("ch_contact_not_on_dorr")).font(.dorr(12.5, .semibold)).foregroundStyle(ch.soft)
                        }
                    }
                    .frame(height: 22).padding(.top, 6)
                    .animation(.easeInOut(duration: 0.2), value: checked)
                    // Several numbers: pick which one the actions use.
                    if phones.count > 1 {
                        HStack(spacing: 6) {
                            ForEach(phones, id: \.self) { p in
                                let on = p == phone
                                Button { phone = p } label: {
                                    Text(verbatim: ltrNumber(p)).font(.dorr(12.5, .bold)).foregroundStyle(on ? .white : ch.ink)
                                        .padding(.horizontal, 10).padding(.vertical, 6)
                                        .background(on ? ch.red : ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 12))
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .padding(.top, 8)
                    }
                    HStack(spacing: 0) {
                        // The phone's own call, not a Dorr call.
                        action("phone.fill", L("ch_contact_call"), Color(hex: 0x10B981), 0) {
                            if let url = URL(string: "tel:\(phone.filter { $0.isNumber || $0 == "+" })") { UIApplication.shared.open(url) }
                            onDismiss()
                        }
                        if onDorr != nil {
                            action("bubble.left.and.bubble.right.fill", L("ch_message_contact"), ch.red, 1) {
                                onDismiss()
                                onMessage(phone)
                            }
                        } else {
                            action("paperplane.fill", L("ch_invite"), Color(hex: 0x6366F1), 1, enabled: checked) { inviting = true }
                        }
                        action("person.badge.plus", L("ch_contact_save"), Color(hex: 0x0EA5E9), 2) { saving = true }
                        action("doc.on.doc.fill", L("ch_contact_copy"), Color(hex: 0xF59E0B), 3) {
                            UIPasteboard.general.string = phone
                            AppToast.show(L("ch_number_copied"))
                            onDismiss()
                        }
                    }
                    .padding(.top, 20)
                }
                .frame(maxWidth: .infinity)
                .padding(.bottom, 10)
            }
            .onAppear {
                if phone.isEmpty { phone = phones.first ?? "" }
                withAnimation(.spring(response: 0.4, dampingFraction: 0.55).delay(0.05)) { shown = true }
            }
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

    private func action(_ icon: String, _ label: String, _ color: Color, _ index: Int, enabled: Bool = true, _ tap: @escaping () -> Void) -> some View {
        Button(action: tap) {
            VStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 22)).foregroundStyle(.white)
                    .frame(width: 54, height: 54)
                    .background(LinearGradient(colors: [color, color.opacity(0.75)], startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                Text(verbatim: label).font(.dorr(12, .bold)).foregroundStyle(ch.ink).lineLimit(1)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 6)
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
        .opacity(enabled ? 1 : 0.4)
        .scaleEffect(shown ? 1 : 0.01)
        .animation(.spring(response: 0.4, dampingFraction: 0.5).delay(0.05 + Double(index) * 0.05), value: shown)
    }
}

/// The phone's own «new contact» page, filled in (Android: the contacts app's insert screen).
private struct NewPhoneContact: UIViewControllerRepresentable {
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
                    Text(L("ch_not_in_contacts", conversation.title ?? "")).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                    Text(L("ch_not_in_contacts_why")).font(.dorr(11.5)).foregroundStyle(ch.mut).lineLimit(2)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Button(action: add) {
                    Group {
                        if saving { ProgressView().tint(.white).scaleEffect(0.7) } else { Text(L("ch_add")).font(.dorr(13, .heavy)).foregroundStyle(.white) }
                    }
                    .padding(.horizontal, 14).padding(.vertical, 8)
                    .background(ch.red, in: RoundedRectangle(cornerRadius: 14))
                }
                .buttonStyle(.plain)
                .disabled(saving)
                Button { withAnimation { closed = true } } label: {
                    Image(systemName: "xmark").font(.system(size: 14, weight: .bold)).foregroundStyle(ch.soft).frame(width: 32, height: 32)
                }
                .buttonStyle(.plain)
            }
            .padding(.leading, 12).padding(.trailing, 6).padding(.vertical, 8)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .padding(.horizontal, 10).padding(.vertical, 6)
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
