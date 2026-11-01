import SwiftUI

// androidApp `ChatDirectoryUi.kt` (VerifiedBadge, ChannelVerificationSheet), `ChatBacklogUi.kt` (ChannelReactionsSheet)
// and `ChatOrganize.kt` (ChannelInviteSheet) — a channel's ✔, the emojis its followers may react with, and inviting
// contacts to follow it.

let channelVerifiedGreen = Color(hex: 0x1FAA59)

/// The ✔ next to a verified channel's name (like WhatsApp's).
struct VerifiedBadge: View {
    var size: CGFloat = 16
    var tint: Color = channelVerifiedGreen

    var body: some View {
        Image(systemName: "checkmark.seal.fill").font(.system(size: size * 0.9)).foregroundStyle(tint).accessibilityLabel(L("ch_verified"))
    }
}

/// "Verify my channel" (owner): where its ✔ stands and the packages to buy it with — paid on the one payment screen.
struct ChannelVerificationSheet: View {
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    let onDismiss: () -> Void
    let onPaid: () -> Void
    @State private var data: VerificationDto?
    @State private var error: String?
    @State private var chosen: Int?
    @State private var pop: CGFloat = 0.4

    var body: some View {
        ChSheetCard {
            VStack(spacing: 0) {
                VerifiedBadge(size: 52)
                    .frame(width: 84, height: 84).background(channelVerifiedGreen.opacity(0.12), in: Circle())
                    .scaleEffect(pop)
                Text(L("ch_verify_title")).font(.dorr(20, .heavy)).foregroundStyle(ch.ink).padding(.top, 10)
                Text(L("ch_verify_sub")).font(.dorr(13)).foregroundStyle(ch.mut).multilineTextAlignment(.center).padding(.top, 4)
                if let d = data {
                    let status = d.verifiedByAdmin ? L("ch_verified_by_dorr") : d.isVerified ? L("ch_verified_until", shortDate(d.verifiedUntil)) : L("ch_not_verified")
                    let tone = d.isVerified ? channelVerifiedGreen : ch.mut
                    Text(verbatim: status).font(.dorr(13, .bold)).foregroundStyle(tone)
                        .padding(.horizontal, 12).padding(.vertical, 6)
                        .background(tone.opacity(0.1), in: RoundedRectangle(cornerRadius: 12))
                        .padding(.top, 12)
                    if d.packages.isEmpty {
                        Text(L("ch_verify_no_plans")).font(.dorr(13)).foregroundStyle(ch.mut).multilineTextAlignment(.center).padding(.top, 14)
                    } else {
                        VStack(spacing: 10) {
                            ForEach(d.packages) { p in PlanCard(plan: p, selected: chosen == p.id) { chosen = p.id } }
                        }
                        .padding(.top, 14)
                        ChPrimaryButton(text: L("ch_verify_continue"), icon: "checkmark.seal.fill", enabled: chosen != nil) {
                            guard let id = chosen else { return }
                            // The payment screen draws over the app: close this first.
                            onDismiss()
                            CheckoutLauncher.shared.open(CheckoutRequest(purpose: "chat_channel_verification",
                                                                         reference: ["channel_id": .string(conversationId), "package_id": .int(id)]), onPaid: onPaid)
                        }
                        .padding(.top, 6)
                    }
                } else if let error {
                    Text(verbatim: error).font(.dorr(13)).foregroundStyle(ch.danger).padding(.top, 12)
                } else {
                    WaSkeleton(cornerRadius: 20).frame(height: 150).padding(.top, 12)
                }
            }
            .frame(maxWidth: .infinity)
        }
        .task {
            withAnimation(.spring(response: 0.45, dampingFraction: 0.4)) { pop = 1 }
            do {
                data = try await DiscoverApi.channelVerification(conversationId).data
                chosen = data?.packages.first?.id
            } catch { self.error = error.apiFailure.message ?? "" }
        }
    }

    private func shortDate(_ iso: String?) -> String {
        guard let d = parseRideTime(iso) else { return "" }
        let f = DateFormatter()
        f.locale = AppLocale.locale
        f.dateStyle = .medium
        return f.string(from: d)
    }
}

/// The common emojis a channel can allow.
let channelEmojis = ["👍", "❤️", "😂", "😮", "😢", "🙏", "🔥", "👏", "🎉", "😍", "💯", "🤔"]

/// Any emoji · none · only these.
struct ChannelReactionsSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let conversationId: String
    let current: [String]?
    let onDismiss: () -> Void
    let onSaved: (ConversationDto) -> Void
    @State private var mode = "any"
    @State private var picked: [String] = []
    @State private var busy = false
    @State private var loaded = false

    var body: some View {
        ChSheetCard {
            Text(L("bk_channel_reactions")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            Text(L("bk_channel_reactions_sub")).font(.dorr(13)).foregroundStyle(ch.mut)
            HStack(spacing: 6) {
                ForEach([("any", "bk_reactions_any"), ("some", "bk_reactions_some"), ("none", "bk_reactions_none")], id: \.0) { key, label in
                    BkChip(label: L(label), selected: mode == key) { mode = key }
                }
            }
            .padding(.top, 12)
            if mode == "some" {
                FlowLayout(spacing: 8) {
                    ForEach(channelEmojis, id: \.self) { e in
                        let on = picked.contains(e)
                        Button { if on { picked.removeAll { $0 == e } } else { picked.append(e) } } label: {
                            Text(verbatim: e).font(.system(size: 22)).frame(width: 46, height: 46)
                                .background(on ? ch.red.opacity(0.15) : ch.surfaceMuted, in: Circle())
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(.top, 12)
            }
            ChPrimaryButton(text: L("ch_save"), enabled: !busy && (mode != "some" || !picked.isEmpty)) {
                busy = true
                Task {
                    let value: [String]? = mode == "any" ? nil : mode == "none" ? [] : picked
                    do {
                        if let c = try await BacklogApi.channelReactions(conversationId, value).data { onSaved(c) }
                        onDismiss()
                    } catch { host.showToast(error.apiFailure.message ?? "", error: true) }
                    busy = false
                }
            }
            .padding(.top, 18)
        }
        .onAppear {
            guard !loaded else { return }
            loaded = true
            mode = current == nil ? "any" : current!.isEmpty ? "none" : "some"
            picked = (current ?? []).isEmpty ? ["👍", "❤️"] : current!
        }
    }
}

struct BkChip: View {
    @ObservedObject private var ch = Ch.shared
    let label: String
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(selected ? .white : ch.ink).lineLimit(1)
                .padding(.horizontal, 12).padding(.vertical, 8)
                .background(selected ? ch.red : ch.surfaceMuted, in: Capsule())
        }
        .buttonStyle(.plain)
        .animation(.easeOut(duration: 0.2), value: selected)
    }
}

/// Nobody is *added* to a channel (people choose to follow it), so its admins invite instead: pick contacts on Dorr, and
/// each gets a private message with the channel's link — one tap to follow.
struct ChannelInviteSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let channel: ConversationDto
    let onDismiss: () -> Void
    @State private var contacts: [ContactDto]?
    @State private var picked: [Int] = []
    @State private var query = ""
    @State private var sending = false

    var body: some View {
        let digits = query.filter(\.isNumber)
        let q = query.trimmingCharacters(in: .whitespaces)
        let shown = (contacts ?? []).filter { q.isEmpty || $0.name.range(of: q, options: .caseInsensitive) != nil || (!digits.isEmpty && $0.phone.contains(digits)) }
        ChSheetCard {
            HStack(spacing: 8) {
                Image(systemName: "megaphone.fill").font(.system(size: 20)).foregroundStyle(ch.red)
                Text(L("ch_channel_invite_contacts")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            }
            Text(L("ch_channel_invite_sub")).font(.dorr(12.5)).foregroundStyle(ch.mut)
            ChField(placeholder: L("ch_search_hint"), text: $query, icon: "magnifyingglass", limit: 80).padding(.top, 10)
            Group {
                if contacts == nil {
                    ProgressView().tint(ch.red)
                } else if shown.isEmpty {
                    Text(L("ch_no_results")).font(.dorr(14)).foregroundStyle(ch.soft)
                } else {
                    ScrollView {
                        LazyVStack(spacing: 0) {
                            ForEach(shown) { c in row(c) }
                        }
                    }
                }
            }
            .frame(maxWidth: .infinity, minHeight: 120, maxHeight: 420)
            .padding(.top, 8)
            ChPrimaryButton(text: L("ch_channel_invite_send", picked.count), icon: "checkmark", enabled: !sending && !picked.isEmpty, action: send).padding(.top, 12)
        }
        .task {
            var seen = Set<Int>()
            contacts = ((try? await ChatContactsApi.contacts(registered: 1).data) ?? []).filter { c in
                guard let p = c.profile, !p.isMe else { return false }
                return seen.insert(p.id).inserted
            }
        }
    }

    private func row(_ c: ContactDto) -> some View {
        let id = c.profile?.id ?? 0
        let on = picked.contains(id)
        return Button {
            if on { picked.removeAll { $0 == id } } else { picked.append(id) }
        } label: {
            HStack(spacing: 12) {
                ChAvatar(url: c.profile?.avatar, name: c.name, key: c.profile?.key, size: 44)
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: c.name).font(.dorr(15, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                    Text(verbatim: ltrNumber(c.phone)).font(.dorr(12)).foregroundStyle(ch.mut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 24)).foregroundStyle(on ? ch.red : ch.soft)
            }
            .padding(.vertical, 8).padding(.horizontal, 6)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    private func send() {
        sending = true
        let people = (contacts ?? []).filter { picked.contains($0.profile?.id ?? -1) }
        Task {
            defer { sending = false }
            do {
                guard let link = try await ChatApi.invite(channel.id).data?.link else { return }
                let body = L("ch_channel_invite_text", channel.title ?? "", link)
                var sent = 0
                // One by one into each person's direct chat (a normal message, not a group add).
                for c in people {
                    guard let p = c.profile, let chat = try? await ChatApi.openDirect(participantId: p.id, participantType: p.type).data else { continue }
                    if (try? await ChatApi.send(chat.id, ["type": "text", "body": body, "uuid": UUID().uuidString])) != nil { sent += 1 }
                }
                host.showToast(L("ch_channel_invited", sent))
                onDismiss()
            } catch {
                if let m = error.apiFailure.message { host.showToast(m, error: true) }
            }
        }
    }
}
