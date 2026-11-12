import PhotosUI
import SwiftUI

// androidApp `chat/ChatChannelsUi.kt` — channels: find public ones by name or @handle (the directory grouped by
// category, most followed first), filter by category and verified, follow with one tap or preview first, create my
// own (photo, name, description, @handle, category, public or link-only); and in a channel, «Follow» for a visitor,
// mute / share for a follower.

struct ChannelsPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var query = ""
    @State private var list: [ChannelCardDto]?
    /// Nothing typed: the directory, grouped by category.
    @State private var groups: [ChannelGroupDto]?
    @State private var categories: [CategoryDto] = []
    @State private var filters: Set<String> = ["all"]
    @State private var creating = false
    @State private var previewing: ChannelCardDto?
    @State private var visibleCount = 15
    @State private var filtering = false

    /// (category id, category, channel), filtered.
    private var allChannels: [(Int?, CategoryDto?, ChannelCardDto)] {
        let raw: [(Int?, CategoryDto?, ChannelCardDto)] = groups.map { g in g.flatMap { grp in grp.channels.map { (grp.category?.id, grp.category, $0) } } }
            ?? (list ?? []).map { ($0.category?.id, $0.category, $0) }
        let allMode = filters.isEmpty || filters.contains("all")
        let cats = Set(filters.compactMap { $0.hasPrefix("cat:") ? Int($0.dropFirst(4)) : nil })
        let wantVerified = filters.contains("verified"), wantUnverified = filters.contains("unverified")
        return raw.filter { catId, _, c in
            let catOk = allMode || cats.isEmpty || catId.map(cats.contains) == true || c.category.map { cats.contains($0.id) } == true
            let verOk: Bool
            if allMode || wantVerified == wantUnverified { verOk = true } else if wantVerified { verOk = c.isVerified == true } else { verOk = c.isVerified != true }
            return catOk && verOk
        }
    }

    var body: some View {
        ZStack(alignment: .bottom) {
            PinkBackdrop().ignoresSafeArea()
            VStack(spacing: 0) {
                SubHeader(title: L("ch_channels")) { host.pop() }.padding(.horizontal, 16)
                ScrollView {
                    LazyVStack(spacing: 6) {
                        HStack(spacing: 8) {
                            ChField(placeholder: L("ch_channels_search"), text: $query, icon: "magnifyingglass", limit: 60)
                            ZStack(alignment: .topTrailing) {
                                WaCircleButton(icon: "slider.horizontal.3", label: L("ch_channels_filter")) { filtering = true }
                                let n = filters.filter { $0 != "all" }.count
                                if n > 0 {
                                    Text(verbatim: "\(n)").font(.dorr(10, .heavy)).foregroundStyle(.white).frame(width: 16, height: 16).background(ch.red, in: Circle())
                                }
                            }
                        }
                        rows
                    }
                    .padding(.horizontal, 14).padding(.top, 8).padding(.bottom, 110)
                }
            }
            createBar
        }
        .task { categories = (try? await DiscoverApi.categories().data) ?? [] }
        .task(id: query) {
            visibleCount = 15
            if !query.isEmpty { try? await Task.sleep(nanoseconds: 300_000_000) }
            guard !Task.isCancelled else { return }
            let q = query.trimmingCharacters(in: .whitespaces)
            if q.isEmpty {
                let directory = try? await DiscoverApi.channelDirectory().data
                groups = directory
                if let directory { list = directory.flatMap(\.channels) } else { list = (try? await ChatApi.discoverChannels().data) ?? [] }
            } else {
                groups = nil
                list = (try? await ChatApi.discoverChannels(search: q).data) ?? []
            }
        }
        .onChange(of: filters) { _ in visibleCount = 15 }
        .dorrDialog(isPresented: $filtering, bottom: true) {
            ChannelFilterSheet(categories: categories, current: filters, onApply: { filters = $0; filtering = false }) { filtering = false }
        }
        .dorrDialog(isPresented: Binding(get: { previewing != nil }, set: { if !$0 { previewing = nil } }), bottom: true) {
            if let c = previewing {
                ChannelPreviewSheet(host: host, channel: c) { followed in
                    previewing = nil
                    host.upsert(followed)
                    followChanged(c, true)
                    host.push(.conversation(followed.id))
                }
            }
        }
        .dorrDialog(isPresented: $creating, bottom: true) {
            ChannelCreateSheet(host: host, onDismiss: { creating = false }) { created in
                creating = false
                host.upsert(created)
                host.replace(.conversation(created.id))
            }
        }
    }

    @ViewBuilder
    private var rows: some View {
        let all = allChannels
        if list == nil {
            ForEach(0..<4, id: \.self) { _ in WaSkeleton().frame(height: 76).clipShape(RoundedRectangle(cornerRadius: 20)) }
        } else if all.isEmpty {
            Text(L("ch_channels_none")).font(.dorr(14)).foregroundStyle(ch.mut).multilineTextAlignment(.center).frame(maxWidth: .infinity).padding(30)
        } else {
            let paged = Array(all.prefix(visibleCount))
            ForEach(Array(paged.enumerated()), id: \.element.2.id) { i, item in
                let (catId, cat, channel) = item
                // A category's title over its first channel.
                if let cat, let catId, !paged[..<i].contains(where: { $0.0 == catId }) {
                    Text(verbatim: cat.name ?? "#\(catId)").font(.dorr(13, .heavy)).foregroundStyle(ch.mut)
                        .frame(maxWidth: .infinity, alignment: .leading).padding(.top, 10).padding(.horizontal, 4)
                }
                ChannelRow(host: host, channel: channel, index: i, onOpen: {
                    if channel.isFollowing == true { host.push(.conversation(channel.id)) } else { previewing = channel }
                }) { followChanged(channel, $0) }
                .onAppear { if i >= paged.count - 3, visibleCount < all.count { visibleCount = min(visibleCount + 15, all.count) } }
            }
        }
    }

    private func followChanged(_ channel: ChannelCardDto, _ followed: Bool) {
        func bump(_ c: ChannelCardDto) -> ChannelCardDto {
            guard c.id == channel.id else { return c }
            var n = c
            n.isFollowing = followed
            n.followersCount = (c.followersCount ?? 0) + (followed ? 1 : -1)
            return n
        }
        list = list?.map(bump)
        groups = groups?.map { g in ChannelGroupDto(category: g.category, channels: g.channels.map(bump)) }
    }

    private var createBar: some View {
        Button { creating = true } label: {
            ZStack {
                Text(L("ch_channel_create")).font(.dorr(15, .heavy)).foregroundStyle(.white).frame(maxWidth: .infinity)
                HStack {
                    Image(systemName: "megaphone.fill").font(.system(size: 15)).foregroundStyle(.white)
                        .frame(width: 34, height: 34).background(Color.white.opacity(0.16), in: Circle())
                    Spacer()
                }
            }
            .padding(10)
            .background(ch.headerBrush, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .shadow(color: ch.red.opacity(0.45), radius: 14, y: 4)
        }
        .buttonStyle(WaPressStyle())
        .padding(.horizontal, 16).padding(.top, 26).padding(.bottom, 16)
        .background(LinearGradient(colors: [ch.bg.opacity(0), ch.bg.opacity(0.96)], startPoint: .top, endPoint: .init(x: 0.5, y: 0.35)).ignoresSafeArea(edges: .bottom))
    }
}

private struct ChannelFilterSheet: View {
    @ObservedObject private var ch = Ch.shared
    let categories: [CategoryDto]
    let current: Set<String>
    let onApply: (Set<String>) -> Void
    let onClose: () -> Void
    @State private var verify = "all"
    @State private var categoryId: Int?
    @State private var started = false

    var body: some View {
        ChSheetCard {
            Text(L("ev_filter_title")).font(.dorr(19, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 10)
            SelectRow(icon: "checkmark.seal", title: L("ch_channels_filter_verify"), value: verifyLabel, options: [
                (L("ch_all"), { verify = "all" }), (L("ch_verified"), { verify = "verified" }), (L("ch_not_verified"), { verify = "unverified" }),
            ])
            SelectRow(icon: "square.grid.2x2", title: L("ev_f_category"), value: categories.first { $0.id == categoryId }?.name ?? L("ch_all"),
                      options: [(L("ch_all"), { categoryId = nil })] + categories.map { c in (c.name ?? "#\(c.id)", { categoryId = c.id }) })
                .padding(.top, 10)
            HStack(spacing: 10) {
                Button { onApply(["all"]) } label: {
                    Text(L("ev_place_clear")).font(.dorr(15, .bold)).foregroundStyle(ch.ink).frame(maxWidth: .infinity).padding(.vertical, 14)
                        .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                }
                .buttonStyle(.plain)
                ChPrimaryButton(text: L("ev_place_apply")) {
                    var keys = Set<String>()
                    if verify != "all" { keys.insert(verify) }
                    if let categoryId { keys.insert("cat:\(categoryId)") }
                    onApply(keys.isEmpty ? ["all"] : keys)
                }
            }
            .padding(.top, 16)
        }
        .onAppear {
            guard !started else { return }
            started = true
            verify = current.contains("verified") && !current.contains("unverified") ? "verified" : current.contains("unverified") && !current.contains("verified") ? "unverified" : "all"
            categoryId = current.first { $0.hasPrefix("cat:") }.flatMap { Int($0.dropFirst(4)) }
        }
    }

    private var verifyLabel: String {
        switch verify {
        case "verified": return L("ch_verified")
        case "unverified": return L("ch_not_verified")
        default: return L("ch_all")
        }
    }
}

/// A field that opens a list of choices (Android's `FilterSelect`).
struct SelectRow: View {
    @ObservedObject private var ch = Ch.shared
    let icon: String
    let title: String
    let value: String
    let options: [(String, () -> Void)]

    var body: some View {
        Menu {
            ForEach(Array(options.enumerated()), id: \.offset) { _, o in Button(o.0, action: o.1) }
        } label: {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 17)).foregroundStyle(ch.red)
                VStack(alignment: .leading, spacing: 0) {
                    if !title.isEmpty { Text(verbatim: title).font(.dorr(11.5)).foregroundStyle(ch.mut) }
                    Text(verbatim: value).font(.dorr(15, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                }
                Spacer()
                Image(systemName: "chevron.down").font(.system(size: 13, weight: .bold)).foregroundStyle(ch.soft)
            }
            .padding(.horizontal, 16).padding(.vertical, 12)
            .background(ch.fieldFill, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(ch.fieldLine, lineWidth: 1))
        }
    }
}

private struct ChannelRow: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let channel: ChannelCardDto
    let index: Int
    let onOpen: () -> Void
    let onFollowChanged: (Bool) -> Void
    @State private var busy = false

    var body: some View {
        let desc = channel.description?.trimmingCharacters(in: .whitespacesAndNewlines)
        let showDesc = desc.flatMap { $0.isEmpty || $0.lowercased() == channel.name?.trimmingCharacters(in: .whitespaces).lowercased() ? nil : $0 }
        Button(action: onOpen) {
            HStack(spacing: 10) {
                ChAvatar(url: channel.avatar, name: channel.name, key: channel.id, size: 40, isGroup: true)
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 4) {
                        Text(verbatim: channel.name ?? "").font(.dorr(14, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                        if channel.isVerified == true { VerifiedBadge(size: 14) }
                    }
                    if let h = channel.handle { Text(verbatim: "@\(h)").font(.dorr(12)).foregroundStyle(ch.mut).lineLimit(1) }
                    if let showDesc { Text(verbatim: showDesc).font(.dorr(11.5)).foregroundStyle(ch.soft).lineLimit(1) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                VStack(spacing: 4) {
                    FollowPill(following: channel.isFollowing == true, busy: busy, action: toggle)
                    Text(L("ch_followers", channel.followersCount ?? 0)).font(.dorr(11)).foregroundStyle(ch.mut).lineLimit(1)
                }
            }
            .padding(.horizontal, 10).padding(.vertical, 8)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
        .buttonStyle(.plain)
        .chStagger(index)
    }

    private func toggle() {
        busy = true
        Task {
            do {
                if channel.isFollowing == true {
                    _ = try await ChatApi.unfollowChannel(channel.id)
                    host.remove(channel.id)
                    onFollowChanged(false)
                } else {
                    if let c = try await ChatApi.followChannel(channel.id).data { host.upsert(c) }
                    onFollowChanged(true)
                }
            } catch {
                if let msg = error.apiFailure.message { host.showToast(msg, error: true) }
            }
            busy = false
        }
    }
}

/// «Follow» (brand) ⇄ «Following ✓» (quiet) — the tick springs in.
private struct FollowPill: View {
    @ObservedObject private var ch = Ch.shared
    let following: Bool
    let busy: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 3) {
                if following { Image(systemName: "checkmark").font(.system(size: 11, weight: .bold)).transition(.scale) }
                Text(L(following ? "ch_following" : "ch_follow")).font(.dorr(12, .bold))
            }
            .foregroundStyle(following ? ch.mut : .white)
            .padding(.horizontal, 10).padding(.vertical, 6)
            .background(following ? ch.surfaceMuted : ch.red, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            .animation(.spring(response: 0.3, dampingFraction: 0.5), value: following)
        }
        .buttonStyle(.plain)
        .disabled(busy)
    }
}

/// A channel I don't follow yet: photo, name, @handle, followers, description — and «Follow».
private struct ChannelPreviewSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let channel: ChannelCardDto
    let onFollowed: (ConversationDto) -> Void
    @State private var busy = false
    @State private var pop: CGFloat = 0.6

    var body: some View {
        ChSheetCard {
            VStack(spacing: 0) {
                ChAvatar(url: channel.avatar, name: channel.name, key: channel.id, size: 92, isGroup: true).scaleEffect(pop)
                Text(verbatim: channel.name ?? "").font(.dorr(20, .heavy)).foregroundStyle(ch.ink).multilineTextAlignment(.center).padding(.top, 12)
                Text(verbatim: [channel.handle.map { "@\($0)" }, L("ch_followers", channel.followersCount ?? 0)].compactMap { $0 }.joined(separator: "  ·  "))
                    .font(.dorr(13)).foregroundStyle(ch.mut)
                if let d = channel.description, !d.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: d).font(.dorr(13.5)).foregroundStyle(ch.mut).multilineTextAlignment(.center).lineLimit(5).padding(.top, 10)
                }
                ChPrimaryButton(text: L("ch_follow_channel"), icon: "plus", enabled: !busy) {
                    busy = true
                    Task {
                        do { if let c = try await ChatApi.followChannel(channel.id).data { onFollowed(c) } } catch {
                            if let msg = error.apiFailure.message { host.showToast(msg, error: true) }
                        }
                        busy = false
                    }
                }
                .padding(.top, 20)
            }
            .frame(maxWidth: .infinity)
        }
        .onAppear { withAnimation(.spring(response: 0.4, dampingFraction: 0.45)) { pop = 1 } }
    }
}

// MARK: - create

/// Name, description, photo, @handle, category, and public (in Discover) or link-only.
struct ChannelCreateSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    let onCreated: (ConversationDto) -> Void
    @State private var categories: [CategoryDto] = []
    @State private var category: Int?
    @State private var name = ""
    @State private var description = ""
    @State private var handle = ""
    @State private var isPublic = true
    @State private var item: PhotosPickerItem?
    @State private var photo: UIImage?
    @State private var busy = false
    @State private var pop: CGFloat = 0.7

    private var handleOk: Bool { handle.isEmpty || handle.range(of: "^[a-z0-9_]{3,32}$", options: .regularExpression) != nil }

    var body: some View {
        ChSheetCard {
            ScrollView {
                VStack(spacing: 8) {
                    Text(L("ch_channel_new")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 6)
                    ZStack {
                        PhotosPicker(selection: $item, matching: .images) {
                            ZStack {
                                if let photo { Image(uiImage: photo).resizable().scaledToFill() } else { Image(systemName: "camera.fill").font(.system(size: 30)).foregroundStyle(.white) }
                            }
                            .frame(width: 96, height: 96).background(ch.headerBrush).clipShape(Circle())
                            .shadow(color: ch.red.opacity(0.4), radius: 14, y: 4)
                        }
                        if photo != nil {
                            Button { photo = nil; item = nil } label: {
                                Image(systemName: "xmark").font(.system(size: 12, weight: .bold)).foregroundStyle(.white).frame(width: 28, height: 28).background(ch.danger, in: Circle())
                            }
                            .buttonStyle(.plain).offset(x: 40, y: -40)
                        }
                    }
                    .frame(width: 112, height: 112).scaleEffect(pop)
                    ChField(placeholder: L("ch_channel_name_hint"), text: $name, icon: "megaphone.fill", limit: 100)
                    ChField(placeholder: L("ch_group_desc_hint"), text: $description, icon: "text.alignleft", limit: 500)
                    ChField(placeholder: L("ch_channel_handle_hint"), text: Binding(get: { handle }, set: { v in
                        handle = String(v.lowercased().filter { $0.isLetter || $0.isNumber || $0 == "_" }.prefix(32))
                    }), icon: "at", limit: 32)
                    if !handleOk {
                        Text(L("ch_channel_handle_rule")).font(.dorr(12)).foregroundStyle(ch.danger).frame(maxWidth: .infinity, alignment: .leading).padding(.leading, 6)
                    }
                    SelectRow(icon: "square.grid.2x2", title: "", value: categories.first { $0.id == category }?.name ?? L("ch_channel_category_none"),
                              options: [(L("ch_channel_category_none"), { category = nil })] + categories.map { c in (c.name ?? "#\(c.id)", { category = c.id }) })
                        .padding(.top, 4)
                    HStack(spacing: 10) {
                        kind(true, "globe", "ch_channel_public", "ch_channel_public_sub")
                        kind(false, "lock.shield", "ch_channel_private", "ch_channel_private_sub")
                    }
                    .padding(.top, 4)
                    ChPrimaryButton(text: L("ch_channel_create"), icon: "megaphone.fill", enabled: !name.trimmingCharacters(in: .whitespaces).isEmpty && handleOk && !busy, action: create)
                        .padding(.top, 8)
                }
            }
            .frame(maxHeight: 640)
        }
        .task { categories = (try? await DiscoverApi.categories().data) ?? [] }
        .onAppear { withAnimation(.spring(response: 0.4, dampingFraction: 0.45)) { pop = 1 } }
        .onChange(of: item) { picked in
            Task { if let data = try? await picked?.loadTransferable(type: Data.self) { photo = UIImage(data: data) } }
        }
    }

    private func kind(_ value: Bool, _ icon: String, _ title: String, _ sub: String) -> some View {
        let on = isPublic == value
        return Button { isPublic = value } label: {
            VStack(alignment: .leading, spacing: 2) {
                HStack {
                    Image(systemName: icon).font(.system(size: 19)).foregroundStyle(on ? ch.red : ch.mut)
                    Spacer()
                    Image(systemName: on ? "largecircle.fill.circle" : "circle").font(.system(size: 19)).foregroundStyle(on ? ch.red : ch.soft)
                }
                Text(L(title)).font(.dorr(13.5, .heavy)).foregroundStyle(ch.ink).padding(.top, 2)
                Text(L(sub)).font(.dorr(11.5)).foregroundStyle(ch.mut).multilineTextAlignment(.leading)
            }
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(on ? ch.red.opacity(0.1) : ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .animation(.easeOut(duration: 0.2), value: on)
        }
        .buttonStyle(.plain)
    }

    private func create() {
        busy = true
        Task {
            do {
                var fields = ["name": name.trimmingCharacters(in: .whitespaces), "description": description.trimmingCharacters(in: .whitespaces), "is_public": isPublic ? "1" : "0"]
                if !handle.isEmpty { fields["handle"] = handle }
                if let category { fields["category_id"] = String(category) }
                let budget = ChatPrefs.shared.photoBudget()
                let file = photo.flatMap { $0.jpegData(compressionQuality: budget.quality) }.map { ChatFile(field: "avatar", filename: "avatar.jpg", mimeType: "image/jpeg", data: $0) }
                if let c = try await ChatApi.createChannel(fields: fields, avatar: file).data { onCreated(c) } else { onDismiss() }
            } catch {
                if let msg = error.apiFailure.message { host.showToast(msg, error: true) }
            }
            busy = false
        }
    }
}

// MARK: - bottom bars in a channel

/// A channel I don't follow: one big «Follow».
struct ChannelFollowBar: View {
    @ObservedObject var host: ChatHost
    let c: ConversationDto
    let onFollowed: (ConversationDto) -> Void
    @State private var busy = false

    var body: some View {
        ChPrimaryButton(text: L("ch_follow_channel"), icon: "plus", enabled: !busy) {
            busy = true
            Task {
                do {
                    if let f = try await ChatApi.followChannel(c.id).data {
                        onFollowed(f)
                        host.upsert(f)
                    }
                } catch {
                    if let msg = error.apiFailure.message { host.showToast(msg, error: true) }
                }
                busy = false
            }
        }
        .padding(12)
    }
}

/// A follower: mute / unmute (the bell rings when turned on) and share the channel.
struct ChannelFollowerBar: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let c: ConversationDto
    let onChanged: (ConversationDto) -> Void
    @State private var ring: Double = 0
    @State private var sharing = false

    var body: some View {
        HStack(spacing: 10) {
            Button(action: toggleMute) {
                HStack(spacing: 8) {
                    Image(systemName: c.isMuted == true ? "bell.slash.fill" : "bell.fill").font(.system(size: 18)).foregroundStyle(ch.red).rotationEffect(.degrees(ring * 18))
                    Text(L(c.isMuted == true ? "ch_unmute" : "ch_mute")).font(.dorr(14, .heavy)).foregroundStyle(ch.ink)
                }
                .frame(maxWidth: .infinity).padding(.vertical, 14)
                .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            }
            .buttonStyle(.plain)
            ShareLink(item: L("ch_channel_share_text", c.title ?? "", c.group?.handle.map { "@\($0)" } ?? c.title ?? "")) {
                Image(systemName: "square.and.arrow.up").font(.system(size: 18)).foregroundStyle(ch.red)
                    .frame(width: 50, height: 50).background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            }
        }
        .padding(12)
    }

    private func toggleMute() {
        let muting = c.isMuted != true
        Task {
            if let updated = try? await ChatApi.updateSettings(c.id, ["mute": muting ? "always" : "off"]).data {
                onChanged(updated)
                host.upsert(updated)
            }
            if !muting {
                for _ in 0..<3 {
                    withAnimation(.linear(duration: 0.07)) { ring = 1 }
                    try? await Task.sleep(nanoseconds: 70_000_000)
                    withAnimation(.linear(duration: 0.07)) { ring = -1 }
                    try? await Task.sleep(nanoseconds: 70_000_000)
                }
            }
            withAnimation(.linear(duration: 0.06)) { ring = 0 }
        }
    }
}
