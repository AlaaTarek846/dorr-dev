import SwiftUI

// androidApp `ChatPerformanceUi.kt` (data & performance — spec 82, 87; usage stats — spec 83) and
// `ChatBacklogUi.kt` StoragePage (manage storage — WhatsApp list 46–48).

// MARK: - data and performance

/// Data saver, lite mode, and the way to storage and the usage stats.
struct DataPerformancePage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var saver = ChatDataSaver.shared
    @ObservedObject private var lite = ChatLite.shared
    @State private var picker: String?

    private let saverChoices = [("off", "pf_off"), ("mobile", "pf_saver_mobile"), ("always", "pf_saver_always")]
    private let liteChoices = [("auto", "pf_auto"), ("on", "pf_on"), ("off", "pf_off")]

    var body: some View {
        let saverStatus = L(saver.metered ? "pf_net_mobile" : "pf_net_wifi") + " · " + L(saver.active ? "pf_saving_now" : "pf_not_saving")
        let liteStatus = L("pf_phone_memory", String(format: "%.1f", lite.ramGb)) + " · " + L(lite.active ? "pf_lite_on_now" : "pf_lite_off_now")
        ChSettingsPage(title: L("pf_title"), onBack: { host.pop() }) {
            ScrollView {
                VStack(spacing: 0) {
                    PrivacySettingsRow(icon: "chart.bar.fill", title: L("pf_saver"), subtitle: L("pf_saver_sub") + "\n" + saverStatus,
                                       value: L(saverChoices.first { $0.0 == saver.mode }?.1 ?? "pf_off")) { picker = "saver" }
                    PrivacySettingsRow(icon: "speedometer", title: L("pf_lite"), subtitle: L("pf_lite_sub") + "\n" + liteStatus,
                                       value: L(liteChoices.first { $0.0 == lite.mode }?.1 ?? "pf_auto")) { picker = "lite" }
                    PrivacySettingsRow(icon: "trash.fill", title: L("bk_storage"), subtitle: L("bk_storage_sub")) { host.push(.storage()) }
                    PrivacySettingsRow(icon: "sdcard.fill", title: L("us_title"), subtitle: L("us_link_sub"), last: true) { host.push(.usage) }
                }
                .padding(.horizontal, 8).padding(.vertical, 4)
                .modifier(SettingsSurface(radius: 18))
                .padding(.horizontal, 14).padding(.top, 6).padding(.bottom, 16)
            }
        }
        .dorrDialog(isPresented: Binding(get: { picker != nil }, set: { if !$0 { picker = nil } }), bottom: true) {
            if picker == "saver" {
                ChoiceSheet(title: L("pf_saver"), subtitle: L("pf_saver_sub"), options: saverChoices.map { c in (L(c.1), { saver.setMode(c.0) }) },
                            radios: true, selected: saverChoices.firstIndex { $0.0 == saver.mode } ?? 0, onDismiss: { picker = nil })
            } else {
                ChoiceSheet(title: L("pf_lite"), subtitle: L("pf_lite_sub"), options: liteChoices.map { c in (L(c.1), { lite.setMode(c.0) }) },
                            radios: true, selected: liteChoices.firstIndex { $0.0 == lite.mode } ?? 0, onDismiss: { picker = nil })
            }
        }
    }
}

// MARK: - usage stats

/// What chats used on this phone since a date: messages, data (mobile / Wi-Fi), calls, storage.
struct UsagePage: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    @State private var stats: [String: Int64] = [:]
    @State private var outBytes: Int64 = 0
    @State private var imageBytes: Int64 = 0
    @State private var confirm: String?

    private func v(_ key: String) -> Int64 { stats[key] ?? 0 }
    private func data(_ kind: String, mobile: Bool) -> Int64 {
        v(ChatUsage.dataKey(kind, up: true, mobile: mobile)) + v(ChatUsage.dataKey(kind, up: false, mobile: mobile))
    }

    var body: some View {
        ChSettingsPage(title: L("us_title"), onBack: { host.pop() }) {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    hero
                    block("bubble.left.fill", L("us_messages")) {
                        row(L("us_sent"), "\(v(ChatUsage.sent))")
                        row(L("us_received"), "\(v(ChatUsage.received))")
                        row(L("us_bytes_sent"), usageBytes(v(ChatUsage.dataKey("msg", up: true, mobile: true)) + v(ChatUsage.dataKey("msg", up: true, mobile: false))))
                        row(L("us_bytes_received"), usageBytes(v(ChatUsage.dataKey("msg", up: false, mobile: true)) + v(ChatUsage.dataKey("msg", up: false, mobile: false))))
                        row(L("pf_net_mobile"), usageBytes(data("msg", mobile: true)))
                        row(L("pf_net_wifi"), usageBytes(data("msg", mobile: false)), last: true)
                    }
                    block("photo.fill", L("us_media")) {
                        row(L("us_uploaded"), usageBytes(v(ChatUsage.dataKey("media", up: true, mobile: true)) + v(ChatUsage.dataKey("media", up: true, mobile: false))))
                        row(L("us_downloaded"), usageBytes(v(ChatUsage.dataKey("media", up: false, mobile: true)) + v(ChatUsage.dataKey("media", up: false, mobile: false))))
                        row(L("pf_net_mobile"), usageBytes(data("media", mobile: true)))
                        row(L("pf_net_wifi"), usageBytes(data("media", mobile: false)), last: true)
                    }
                    let seconds = v(ChatUsage.callSeconds)
                    block("phone.fill", L("us_calls")) {
                        row(L("us_calls_out"), "\(v(ChatUsage.callsOut))")
                        row(L("us_calls_in"), "\(v(ChatUsage.callsIn))")
                        row(L("us_call_time"), L("us_hours_minutes", Int(seconds / 3600), Int((seconds % 3600) / 60)))
                        row(L("pf_net_mobile"), usageBytes(v(ChatUsage.callDataKey(mobile: true))))
                        row(L("pf_net_wifi"), usageBytes(v(ChatUsage.callDataKey(mobile: false))), last: true)
                    }
                    block("sdcard.fill", L("us_storage"), sub: L("us_storage_sub")) {
                        row(L("us_storage_images"), usageBytes(imageBytes))
                        row(L("us_storage_sent"), usageBytes(outBytes), last: true)
                    }
                    ChPrimaryButton(text: L("us_clear"), icon: "trash.fill") { confirm = "clear" }.padding(.top, 14)
                    ChPrimaryButton(text: L("us_reset"), icon: "arrow.clockwise") { confirm = "reset" }.padding(.top, 10)
                    Text(verbatim: L("us_note")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
                        .frame(maxWidth: .infinity).padding(.top, 8).padding(.bottom, 20)
                }
                .padding(16)
            }
        }
        .task {
            ChatUsage.seedDemoIfEmpty()
            stats = ChatUsage.snapshot()
            measure()
        }
        .dorrDialog(isPresented: Binding(get: { confirm != nil }, set: { if !$0 { confirm = nil } })) {
            if confirm == "clear" {
                ConfirmDialog(icon: "trash.fill", title: L("us_clear_title"), message: L("us_clear_sub"), confirmLabel: L("us_clear"), onConfirm: {
                    confirm = nil
                    clearCache()
                    host.showToast(L("us_cleared"))
                    measure()
                }, onDismiss: { confirm = nil })
            } else {
                ConfirmDialog(icon: "arrow.clockwise", title: L("us_reset_title"), message: L("us_reset_sub"), confirmLabel: L("us_reset"), onConfirm: {
                    confirm = nil
                    ChatUsage.reset()
                    stats = ChatUsage.snapshot()
                    host.showToast(L("us_reset_done"))
                }, onDismiss: { confirm = nil })
            }
        }
    }

    /// The total, mobile against Wi-Fi.
    private var hero: some View {
        let mobile = data("msg", mobile: true) + data("media", mobile: true) + v(ChatUsage.callDataKey(mobile: true))
        let wifi = data("msg", mobile: false) + data("media", mobile: false) + v(ChatUsage.callDataKey(mobile: false))
        return VStack(alignment: .leading, spacing: 0) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 7) {
                        Capsule().fill(.white.opacity(0.9)).frame(width: 3, height: 14)
                        Text(verbatim: L("us_total")).font(.dorr(12, .semibold)).foregroundStyle(.white.opacity(0.88)).lineLimit(1)
                    }
                    Text(verbatim: usageBytes(mobile + wifi)).font(.dorr(26, .heavy)).foregroundStyle(.white).padding(.top, 4)
                    if v(ChatUsage.since) > 0 {
                        HStack(spacing: 4) {
                            Image(systemName: "calendar").font(.system(size: 11))
                            Text(verbatim: L("us_since", Date(timeIntervalSince1970: Double(v(ChatUsage.since)) / 1000).formatted(date: .abbreviated, time: .omitted))).font(.dorr(11))
                        }
                        .foregroundStyle(.white.opacity(0.8)).padding(.top, 2)
                    }
                }
                Spacer(minLength: 10)
                Image(systemName: "sdcard.fill").font(.system(size: 22)).foregroundStyle(.white)
                    .frame(width: 48, height: 48)
                    .background(Color.white.opacity(0.14), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(Color.white.opacity(0.35), lineWidth: 1))
            }
            // Mobile data against Wi-Fi, as one bar.
            GeometryReader { g in
                let share = CGFloat(mobile) / CGFloat(max(mobile + wifi, 1))
                ZStack(alignment: .leading) {
                    Capsule().fill(.white.opacity(0.22))
                    if share > 0 { Capsule().fill(.white.opacity(0.92)).frame(width: g.size.width * share) }
                }
            }
            .frame(height: 5).padding(.vertical, 10)
            HStack(spacing: 0) {
                netPart("antenna.radiowaves.left.and.right", L("pf_net_mobile"), usageBytes(mobile), iconAtStart: true)
                Rectangle().fill(.white.opacity(0.28)).frame(width: 1, height: 22)
                netPart("wifi", L("pf_net_wifi"), usageBytes(wifi), iconAtStart: false)
            }
        }
        .padding(.horizontal, 14).padding(.vertical, 12)
        .background(LinearGradient(colors: [ch.red, ch.redDeep], startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 24, style: .continuous))
    }

    /// One half of the hero: the round icon on the outer edge, the name and the amount in one line.
    private func netPart(_ icon: String, _ label: String, _ value: String, iconAtStart: Bool) -> some View {
        let disc = Image(systemName: icon).font(.system(size: 11)).foregroundStyle(.white)
            .frame(width: 26, height: 26).overlay(Circle().stroke(.white.opacity(0.4), lineWidth: 1))
        let name = Text(verbatim: label).font(.dorr(11, .semibold)).foregroundStyle(.white.opacity(0.88)).lineLimit(1)
        let amount = Text(verbatim: value).font(.dorr(12, .heavy)).foregroundStyle(.white).lineLimit(1)
        return HStack(spacing: 5) {
            if iconAtStart {
                disc
                name
                amount
                Spacer(minLength: 0)
            } else {
                Spacer(minLength: 0)
                amount
                name
                disc
            }
        }
        .padding(.horizontal, 6)
        .frame(maxWidth: .infinity)
    }

    private func block<Content: View>(_ icon: String, _ title: String, sub: String? = nil, @ViewBuilder content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 10) {
                PinkIcon(icon: icon)
                Text(verbatim: title).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
            }
            .padding(.horizontal, 4).padding(.top, 18).padding(.bottom, sub == nil ? 10 : 2)
            if let sub {
                Text(verbatim: sub).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).padding(.horizontal, 4).padding(.top, 2).padding(.bottom, 10)
            }
            VStack(spacing: 0, content: content)
                .padding(.horizontal, 8).padding(.vertical, 4)
                .modifier(SettingsSurface(radius: 18))
        }
    }

    private func row(_ label: String, _ value: String, last: Bool = false) -> some View {
        VStack(spacing: 0) {
            HStack {
                Text(verbatim: label).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                Spacer(minLength: 8)
                Text(verbatim: value).font(.dorr(13.5, .heavy)).foregroundStyle(wa.settingsInk)
            }
            .padding(.horizontal, 4).padding(.vertical, 12)
            if !last { Rectangle().fill(wa.night ? wa.line : Color(hex: 0xF6E4E8)).frame(height: 1) }
        }
    }

    /// The pictures the chat keeps (the app's URL cache) and the files made for sending (shrunk videos, posters).
    private func measure() {
        imageBytes = Int64(URLCache.shared.currentDiskUsage)
        outBytes = sentFiles().reduce(0) { $0 + Int64((try? $1.resourceValues(forKeys: [.fileSizeKey]).fileSize) ?? 0) }
    }

    private func sentFiles() -> [URL] {
        let dir = FileManager.default.temporaryDirectory
        return ((try? FileManager.default.contentsOfDirectory(at: dir, includingPropertiesForKeys: [.fileSizeKey, .contentModificationDateKey])) ?? [])
            .filter { $0.lastPathComponent.hasPrefix("chat-") }
    }

    /// Frees the phone: cached pictures, and files made for sending more than 10 minutes ago (one going up stays).
    private func clearCache() {
        URLCache.shared.removeAllCachedResponses()
        let cutoff = Date().addingTimeInterval(-600)
        for file in sentFiles() {
            let modified = (try? file.resourceValues(forKeys: [.contentModificationDateKey]).contentModificationDate) ?? .distantPast
            if modified < cutoff { try? FileManager.default.removeItem(at: file) }
        }
    }
}

// MARK: - manage storage

/// My chats' files, biggest first: the large ones, the forwarded ones, all, or one chat's. Pages of 15.
struct StoragePage: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let conversationId: String?
    let title: String?
    @State private var overview: StorageDto?
    @State private var kind: String
    @State private var items: [StorageFileDto]?
    @State private var page = 1
    @State private var hasMore = false
    @State private var loadingMore = false
    @State private var selected: [String] = []
    @State private var busy = false
    @State private var confirmDelete = false

    init(host: ChatHost, conversationId: String?, title: String?) {
        self.host = host
        self.conversationId = conversationId
        self.title = title
        _kind = State(initialValue: conversationId != nil ? "all" : "large")
    }

    private var list: [StorageFileDto] { items ?? [] }
    private var allOnList: Bool { !list.isEmpty && list.allSatisfy { selected.contains($0.messageId) } && !hasMore }

    var body: some View {
        ChSettingsPage(title: title ?? L("bk_storage"), onBack: { host.pop() }) {
            VStack(spacing: 0) {
                if let o = overview { summary(o) }
                if conversationId == nil { tabs }
                if kind != "chats" && !list.isEmpty { selectAll }
                content.frame(maxHeight: .infinity)
                if !selected.isEmpty {
                    ChPrimaryButton(text: L("bk_storage_delete", selected.count), icon: "trash.fill", enabled: !busy) { confirmDelete = true }.padding(16)
                }
            }
        }
        .task(id: kind) {
            selected = []
            await load(reset: true)
        }
        .dorrDialog(isPresented: $confirmDelete) {
            ConfirmDialog(icon: "trash.fill", title: L("bk_storage_delete_title"), message: L("bk_storage_delete_sub"),
                          loading: busy, confirmLabel: L("bk_storage_delete", selected.count), onConfirm: delete, onDismiss: { if !busy { confirmDelete = false } })
        }
    }

    private func summary(_ o: StorageDto) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 6) {
                        Capsule().fill(.white.opacity(0.9)).frame(width: 3, height: 12)
                        Text(verbatim: L("bk_storage_used")).font(.dorr(11, .semibold)).foregroundStyle(.white.opacity(0.88)).lineLimit(1)
                    }
                    Text(verbatim: usageBytes(o.totalBytes ?? 0)).font(.dorr(22, .heavy)).foregroundStyle(.white)
                    Text(verbatim: L("bk_storage_files_count", o.files ?? 0)).font(.dorr(11)).foregroundStyle(.white.opacity(0.8))
                }
                Spacer(minLength: 8)
                Image(systemName: "sdcard.fill").font(.system(size: 18)).foregroundStyle(.white)
                    .frame(width: 40, height: 40)
                    .background(Color.white.opacity(0.14), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(Color.white.opacity(0.35), lineWidth: 1))
            }
            Rectangle().fill(.white.opacity(0.22)).frame(height: 1).padding(.vertical, 8)
            HStack(alignment: .top, spacing: 6) {
                Image(systemName: "info.circle.fill").font(.system(size: 12)).foregroundStyle(.white.opacity(0.9))
                Text(verbatim: L("bk_storage_sub")).font(.dorr(11)).foregroundStyle(.white.opacity(0.82)).lineLimit(2)
            }
        }
        .padding(.horizontal, 12).padding(.vertical, 10)
        .background(LinearGradient(colors: [ch.red, ch.redDeep], startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .padding(.horizontal, 16).padding(.top, 10)
    }

    private var tabs: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 6) {
                ForEach([("large", L("bk_storage_large") + (overview?.large.map { " (\($0))" } ?? "")),
                         ("forwarded", L("bk_storage_forwarded") + (overview?.forwarded.map { " (\($0))" } ?? "")),
                         ("all", L("bk_storage_all")), ("chats", L("bk_storage_chats"))], id: \.0) { tab in
                    Button {
                        guard kind != tab.0 else { return }
                        kind = tab.0
                        items = nil
                        hasMore = false
                        page = 1
                        selected = []
                    } label: {
                        Text(verbatim: tab.1).font(.dorr(13, .bold)).foregroundStyle(kind == tab.0 ? .white : ch.ink)
                            .padding(.horizontal, 14).padding(.vertical, 8)
                            .background(kind == tab.0 ? ch.red : ch.surface, in: Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 12).padding(.top, 8).padding(.bottom, 2)
        }
    }

    private var selectAll: some View {
        Button {
            if allOnList { selected = [] } else {
                Task {
                    busy = true
                    var pages = 0
                    while hasMore && pages < 40 {
                        await load(reset: false)
                        pages += 1
                    }
                    selected = Array((items ?? []).map(\.messageId).filter { !$0.isEmpty }.prefix(500))
                    busy = false
                }
            }
        } label: {
            HStack {
                Text(verbatim: L(allOnList ? "bk_storage_unselect" : "bk_storage_select_all")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                Spacer()
                if !selected.isEmpty && !allOnList { Text(verbatim: "\(selected.count)").font(.dorr(12, .heavy)).foregroundStyle(ch.red).padding(.trailing, 8) }
                Image(systemName: allOnList ? "checkmark.circle.fill" : "circle").font(.system(size: 20)).foregroundStyle(allOnList ? ch.red : ch.soft)
            }
            .padding(.horizontal, 22).padding(.vertical, 6).contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(busy)
    }

    @ViewBuilder
    private var content: some View {
        if kind == "chats" {
            let chats = overview?.chats ?? []
            if chats.isEmpty {
                ChEmptyState(icon: "sdcard.fill", title: L("bk_storage"), text: L("bk_storage_empty"), animated: true)
            } else {
                ScrollView {
                    LazyVStack(spacing: 8) {
                        ForEach(chats, id: \.conversationId) { chat in
                            Button { host.push(.storage(conversationId: chat.conversationId, title: chat.title)) } label: {
                                HStack(spacing: 10) {
                                    ChAvatar(url: nil, name: chat.title, key: chat.conversationId, size: 40, isGroup: chat.type != "direct")
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(verbatim: chat.title ?? "").font(.dorr(15, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                                        Text(verbatim: "\(chat.files ?? 0)").font(.dorr(12)).foregroundStyle(ch.mut)
                                    }
                                    Spacer(minLength: 8)
                                    Text(verbatim: usageBytes(chat.bytes ?? 0)).font(.dorr(13, .heavy)).foregroundStyle(ch.ink)
                                }
                                .padding(12)
                                .background(ch.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .padding(12)
                }
            }
        } else if items == nil {
            ProgressView().tint(ch.red).frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if list.isEmpty {
            ChEmptyState(icon: "sdcard.fill", title: L("bk_storage"), text: L("bk_storage_empty"), animated: true)
        } else {
            ScrollView {
                LazyVStack(spacing: 8) {
                    ForEach(Array(list.enumerated()), id: \.offset) { i, file in
                        fileRow(file).onAppear { if i >= list.count - 3 { Task { await load(reset: false) } } }
                    }
                    if loadingMore { ProgressView().tint(ch.red).padding(10) }
                }
                .padding(.horizontal, 12).padding(.top, 4).padding(.bottom, 12)
            }
            .id(kind)
        }
    }

    private func fileRow(_ file: StorageFileDto) -> some View {
        let on = selected.contains(file.messageId)
        return Button {
            if on { selected.removeAll { $0 == file.messageId } } else if !file.messageId.isEmpty { selected.append(file.messageId) }
        } label: {
            HStack(spacing: 10) {
                ZStack {
                    ch.surfaceMuted
                    if let thumb = file.thumbnail, !thumb.isEmpty {
                        ChatImage(url: ApiClient.mediaUrl(thumb))
                    } else {
                        Image(systemName: file.type == "video" ? "video.fill" : "doc.text.fill").foregroundStyle(ch.mut)
                    }
                }
                .frame(width: 48, height: 48).clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: file.name ?? file.type ?? "").font(.dorr(13.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                    HStack(spacing: 2) {
                        if file.forwarded == true { Image(systemName: "arrowshape.turn.up.right.fill").font(.system(size: 11)).foregroundStyle(ch.soft) }
                        Text(verbatim: [file.chat, file.createdAt.map(listTime)].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(ch.mut).lineLimit(1)
                    }
                }
                Spacer(minLength: 8)
                Text(verbatim: usageBytes(file.bytes ?? 0)).font(.dorr(13, .heavy)).foregroundStyle(ch.ink)
                Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 20)).foregroundStyle(on ? ch.red : ch.soft)
            }
            .padding(10)
            .background(on ? ch.red.opacity(0.08) : ch.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
        .buttonStyle(.plain)
    }

    private func load(reset: Bool) async {
        let forKind = kind
        if forKind == "chats" {
            if conversationId == nil { overview = try? await BacklogApi.storage().data }
            items = nil
            page = 1
            hasMore = false
            loadingMore = false
            return
        }
        if !reset && (loadingMore || !hasMore || items == nil) { return }
        if !reset { loadingMore = true }
        if reset {
            items = nil
            page = 1
            hasMore = false
            loadingMore = false
            if conversationId == nil { overview = try? await BacklogApi.storage().data }
        }
        let next = reset ? 1 : page + 1
        let envelope = try? await BacklogApi.storageFiles(kind: forKind, conversationId: conversationId, page: next)
        guard kind == forKind else { return }
        let chunk = envelope?.data?.items ?? []
        if reset {
            items = chunk
        } else {
            let known = Set((items ?? []).map(\.messageId))
            items = (items ?? []) + chunk.filter { !$0.messageId.isEmpty && !known.contains($0.messageId) }
        }
        page = next
        hasMore = envelope?.data?.hasMore == true
        if !reset { loadingMore = false }
    }

    private func delete() {
        guard !busy, !selected.isEmpty else { return }
        busy = true
        Task {
            do {
                _ = try await BacklogApi.deleteStorage(selected)
                host.showToast(L("bk_storage_deleted"))
            } catch {
                host.showToast(error.apiFailure.message ?? "", error: true)
            }
            selected = []
            confirmDelete = false
            await load(reset: true)
            busy = false
        }
    }
}
