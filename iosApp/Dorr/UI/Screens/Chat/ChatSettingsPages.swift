import SwiftUI

// androidApp `ui/screens/chat/ChatSettingsPage.kt` + `ChatExtraPages.kt` (privacy, blocked) + `ChatPartialUi.kt`
// (favourites with folders, comfort) + `ChatToolsUi.kt` (my status) — the chat's settings:
// an Account-style menu whose topics open their own lists, privacy (who sees what, receipts, screenshots, default
// timer, unknown callers, notification preview), blocked people with search and paging, starred messages by folder,
// and my status (emoji + words, how long, who sees it, except whom).

struct ChatSettingsPage: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var wePrefs = WatchEarnPrefs.shared
    @ObservedObject var host: ChatHost
    @State private var sheet: String?

    var body: some View {
        let section = host.chatSettingsSection
        let title: String = [
            "menu": "ch_chat_settings", "account": "ch_set_account", "privacy": "ch_set_privacy", "messages": "ch_set_messages", "org": "ch_set_org",
            "look": "ch_set_look", "data": "ch_set_data", "calls": "ch_set_calls", "biz": "ch_set_biz",
        ][section] ?? "ch_chat_settings"
        ChSettingsPage(title: L(title), onBack: { if section == "menu" { host.pop() } else { withAnimation { host.chatSettingsSection = "menu" } } }) {
            ScrollView {
                VStack(spacing: 0) {
                    switch section {
                    case "account":
                        SettingsGroup {
                            SettingsListRow(icon: "qrcode", title: L("ch_my_qr")) { host.push(.myQr) }
                            SettingsListRow(icon: "face.smiling", title: L("ch_status_title")) { host.push(.myStatus) }
                            SettingsListRow(icon: "megaphone.fill", title: L("ch_channels"), last: true) { host.push(.channels) }
                        }
                    case "privacy":
                        SettingsGroup {
                            SettingsListRow(icon: "shield.fill", title: L("ch_privacy_title")) { host.push(.privacy) }
                            SettingsListRow(icon: "lock.shield.fill", title: L("cp_privacy_center")) { host.push(.privacyCenter) }
                            SettingsListRow(icon: "nosign", title: L("ch_blocked_list")) { host.push(.blockedContacts) }
                            SettingsListRow(icon: "lock.fill", title: L("ch_locked_chats")) { ChatLock.unlock { host.filter = "locked"; host.pop() } }
                            SettingsListRow(icon: "slider.horizontal.3", title: L("at_title"), last: true) { host.push(.commRules) }
                        }
                    case "messages":
                        SettingsGroup {
                            SettingsListRow(icon: "star.fill", title: L("ch_starred_title")) { host.push(.starred) }
                            SettingsListRow(icon: "exclamationmark", title: L("ct_important")) { host.push(.important) }
                            SettingsListRow(icon: "sparkles", title: L("ct_today")) { host.push(.today) }
                            SettingsListRow(icon: "clock.arrow.circlepath", title: L("cp_catch_up"), last: true) { host.push(.catchUp) }
                        }
                    case "org":
                        SettingsGroup {
                            SettingsListRow(icon: "checkmark.circle.fill", title: L("ct_tasks")) { host.push(.tasks) }
                            SettingsListRow(icon: "pin.fill", title: L("lf_my_commitments")) { host.push(.commitments) }
                            SettingsListRow(icon: "tray.fill", title: L("li_title"), last: true) { host.push(.lifeInbox) }
                        }
                    case "look":
                        // Light / dark is the app's own setting (Settings → Appearance); the chat follows it.
                        SettingsGroup {
                            SettingsListRow(icon: "textformat.size", title: L("cp_comfort")) { sheet = "comfort" }
                            // The Watch & Earn card at the top of the chat list: hidden from its ×, shown again here.
                            SettingsListRow(icon: "play.circle.fill", title: L("we_chat_card_setting"), value: L(wePrefs.chatCardHidden ? "we_off" : "we_on"), last: true) {
                                wePrefs.setChatCardHidden(!wePrefs.chatCardHidden)
                            }
                        }
                    case "data":
                        SettingsGroup { SettingsListRow(icon: "speedometer", title: L("pf_title"), last: true) { host.push(.dataPerformance) } }
                    case "calls":
                        SettingsGroup {
                            SettingsListRow(icon: "phone.fill", title: L("ch_calls_title")) { host.push(.calls) }
                            SettingsListRow(icon: "person.3.sequence.fill", title: L("cm_title"), last: true) { host.push(.communities) }
                        }
                    case "biz":
                        SettingsGroup { SettingsListRow(icon: "storefront.fill", title: L("ch_business_title"), last: true) { host.push(.business) } }
                    default:
                        VStack(spacing: 8) {
                            ForEach(Array([("person.fill", "ch_set_account", "account"), ("shield.fill", "ch_set_privacy", "privacy"), ("bubble.left.fill", "ch_set_messages", "messages"),
                                           ("checkmark.circle.fill", "ch_set_org", "org"), ("paintpalette.fill", "ch_set_look", "look"), ("speedometer", "ch_set_data", "data"),
                                           ("phone.fill", "ch_set_calls", "calls"), ("storefront.fill", "ch_set_biz", "biz")].enumerated()), id: \.offset) { i, row in
                                SettingsCardRow(icon: row.0, title: L(row.1)) { withAnimation { host.chatSettingsSection = row.2 } }.chStagger(i)
                            }
                        }
                    }
                }
                .padding(.horizontal, 14).padding(.bottom, 24)
            }
        }
        .dorrDialog(isPresented: Binding(get: { sheet != nil }, set: { if !$0 { sheet = nil } }), bottom: true) {
            ChatComfortSheet()
        }
    }
}

/// The settings look (white cards over the pink backdrop), used by the chat's settings pages.
struct SettingsCardRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let onTap: () -> Void

    var body: some View {
        Button(action: onTap) {
            HStack(spacing: 10) {
                Image(systemName: icon).font(.system(size: 16)).foregroundStyle(wa.accent)
                    .frame(width: 34, height: 34).background(wa.accent.opacity(0.14), in: RoundedRectangle(cornerRadius: 10))
                Text(verbatim: title).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.accent.opacity(0.55))
            }
            .padding(.horizontal, 12).padding(.vertical, 10)
            .modifier(SettingsSurface(radius: 14))
        }
        .buttonStyle(.plain)
    }
}

struct SettingsGroup<Content: View>: View {
    @ViewBuilder let content: () -> Content
    var body: some View {
        VStack(spacing: 0, content: content).modifier(SettingsSurface(radius: 16)).padding(.top, 6)
    }
}

struct SettingsListRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    var value: String?
    var last = false
    let onTap: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            Button(action: onTap) {
                HStack(spacing: 10) {
                    Image(systemName: icon).font(.system(size: 16)).foregroundStyle(wa.accent)
                        .frame(width: 34, height: 34).background(wa.accent.opacity(0.14), in: RoundedRectangle(cornerRadius: 10))
                    Text(verbatim: title).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                    if let value, !value.isEmpty { Text(verbatim: value).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                    Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.accent.opacity(0.55))
                }
                .padding(.horizontal, 12).padding(.vertical, 11).contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            if !last { Rectangle().fill(wa.accent.opacity(0.08)).frame(height: 1).padding(.leading, 56) }
        }
    }
}

/// Text size, a compact chat list and upload quality, with a live preview bubble.
struct ChatComfortSheet: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject private var prefs = ChatPrefs.shared

    var body: some View {
        ChSheetCard {
            Text(L("cp_comfort")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            HStack {
                Spacer()
                Text(L("cp_preview_text")).font(.dorr(15.5 * prefs.fontScale)).foregroundStyle(ch.outText)
                    .padding(.horizontal, 14).padding(.vertical, 10).background(ch.outBubble, in: RoundedRectangle(cornerRadius: 20))
            }
            .padding(.vertical, 14)
            .animation(.easeInOut(duration: 0.2), value: prefs.fontScale)
            Text(L("cp_text_size")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut)
            HStack(spacing: 8) {
                ForEach([(0.9, "A-"), (1.0, "A"), (1.15, "A+"), (1.3, "A++")], id: \.1) { scale, label in
                    pill(label, prefs.fontScale == scale) { prefs.save(scale: scale) }
                }
            }
            .padding(.top, 6)
            ToggleRow(icon: "text.alignleft", title: L("cp_compact"), checked: prefs.compactList, subtitle: L("cp_compact_sub")) { prefs.save(compact: $0) }
                .padding(.horizontal, -14).padding(.top, 10)
            Text(L("cp_upload")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).padding(.top, 10)
            HStack(spacing: 8) {
                ForEach([("auto", "cp_upload_auto"), ("high", "cp_upload_high"), ("saver", "cp_upload_saver")], id: \.0) { key, label in
                    pill(L(label), prefs.uploadQuality == key) { prefs.save(quality: key) }
                }
            }
            .padding(.top, 6)
            Text(L("cp_upload_sub")).font(.dorr(11.5)).foregroundStyle(ch.soft).padding(.top, 6)
        }
    }

    private func pill(_ text: String, _ on: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(13.5, .bold)).foregroundStyle(on ? .white : ch.ink).frame(maxWidth: .infinity).padding(.vertical, 10)
                .background(on ? ch.red : ch.surfaceMuted, in: Capsule())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Privacy

struct ChatPrivacyPage: View {
    @Environment(\.wa) private var wa
    @ObservedObject var host: ChatHost
    @State private var privacy: PrivacyDto?
    @State private var picker: (key: String, current: String, title: String)?
    @State private var sheet: String?
    @State private var summary: PrivacySummaryDto?

    var body: some View {
        ChSettingsPage(title: L("ch_privacy_title"), onBack: { host.pop() }) {
            ScrollView {
                if let p = privacy {
                    VStack(spacing: 0) {
                        audience("eye.fill", "ch_last_seen_setting", p.lastSeen, "last_seen")
                        audience("photo.badge.plus", "ch_profile_photo_setting", p.profilePhoto, "profile_photo")
                        audience("bubble.left.fill", "ch_who_message", p.whoCanMessage, "who_can_message")
                        audience("person.3.fill", "ch_who_groups", p.whoCanAddToGroups, "who_can_add_to_groups")
                        audience("phone.fill", "ch_who_call", p.whoCanCall, "who_can_call")
                        audience("bell.badge.fill", "ch_who_urgent", p.whoCanUrgent ?? "contacts", "who_can_urgent")
                        PrivacySettingsRow(icon: "checkmark.message.fill", title: L("ch_read_receipts"), subtitle: L("ch_read_receipts_sub"), checked: p.readReceipts == true) { save("read_receipts", $0) }
                        PrivacySettingsRow(icon: "camera.viewfinder", title: L("ch_block_screenshots"), checked: p.blockScreenshots == true) { save("block_screenshots", $0) }
                        PrivacySettingsRow(icon: "timer", title: L("bk_default_timer"), value: L(timerLabel(p.defaultDisappearingSeconds)), onTap: { sheet = "timer" })
                        PrivacySettingsRow(icon: "phone.down.fill", title: L("bk_silence_unknown"), subtitle: L("bk_silence_unknown_sub"), checked: p.silenceUnknownCallers == true) { save("silence_unknown_callers", $0) }
                        let level = p.notificationPrivacy ?? (p.notificationPreview == false ? "none" : "all")
                        PrivacySettingsRow(icon: "bell.fill", title: L("ch_notification_preview"), value: L(notificationLevelLabel(level)), onTap: { sheet = "notif" })
                        PrivacyShieldCards(privacy: p, onSheet: { sheet = $0 }, call: call, onChanged: { privacy = $0 }, onSummary: { summary = $0 })
                    }
                    .padding(.horizontal, 8).padding(.vertical, 4)
                    .modifier(SettingsSurface(radius: 18, elevation: 8))
                    .padding(.horizontal, 14).padding(.top, 6).padding(.bottom, 16)
                } else {
                    RoundedRectangle(cornerRadius: 18).fill(wa.settingsCard).frame(height: 320).padding(14)
                }
            }
            .modifier(DebugScrollBottom())
        }
        .task { privacy = try? await ChatApi.privacy().data }
        .dorrDialog(isPresented: Binding(get: { sheet != nil || picker != nil }, set: { if !$0 { sheet = nil; picker = nil } }), bottom: true) { sheetView }
        .dorrDialog(isPresented: Binding(get: { summary != nil }, set: { if !$0 { summary = nil } })) {
            if let summary { PrivacySummaryDialog(summary: summary) { self.summary = nil } }
        }
    }

    @ViewBuilder
    private var sheetView: some View {
        if let picker {
            let choices = [("everyone", "ch_everyone"), ("contacts", "ch_my_contacts"), ("nobody", "ch_nobody")]
            ChoiceSheet(title: picker.title, options: choices.map { c in (L(c.1), { save(picker.key, c.0) }) }, radios: true,
                        selected: choices.firstIndex { $0.0 == picker.current } ?? 0) { self.picker = nil }
        } else if let key = sheet, key.hasPrefix("shield.") {
            PrivacyShieldSheet(key: key, privacy: privacy, onDismiss: { sheet = nil }, call: call, onChanged: { privacy = $0 })
        } else if sheet == "timer" {
            let choices: [Int?] = [nil, 86400, 604_800, 7_776_000]
            ChoiceSheet(title: L("bk_default_timer"), subtitle: L("bk_default_timer_sub"), options: choices.map { v in (L(timerLabel(v)), { save("default_disappearing_seconds", v ?? 0) }) },
                        radios: true, selected: choices.firstIndex { $0 == privacy?.defaultDisappearingSeconds } ?? 0) { sheet = nil }
        } else {
            let choices = ["all", "name", "none"]
            ChoiceSheet(title: L("ch_notification_preview"), subtitle: L("ch_notification_privacy_sub"), options: choices.map { v in (L(notificationLevelLabel(v)), { save("notification_privacy", v) }) },
                        radios: true, selected: choices.firstIndex(of: privacy?.notificationPrivacy ?? "all") ?? 0) { sheet = nil }
        }
    }

    private func audience(_ icon: String, _ titleKey: String, _ value: String?, _ key: String) -> some View {
        let v = value ?? "everyone"
        let label = v == "contacts" ? "ch_my_contacts" : v == "nobody" ? "ch_nobody" : "ch_everyone"
        return PrivacySettingsRow(icon: icon, title: L(titleKey), value: L(label), onTap: { picker = (key, v, L(titleKey)) })
    }

    private func call(_ block: @escaping () async throws -> Void) {
        Task {
            do {
                try await block()
                host.showToast(L("ch_saved"))
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"), error: true)
            }
        }
    }

    private func save(_ key: String, _ value: Any) {
        Task {
            if let fresh = try? await ChatApi.updatePrivacy([key: value]).data {
                privacy = fresh
                host.showToast(L("ch_saved"))
            }
        }
    }

    private func timerLabel(_ seconds: Int?) -> String {
        switch seconds {
        case 86400: return "bk_timer_24h"
        case 604_800: return "bk_timer_7d"
        case 7_776_000: return "bk_timer_90d"
        default: return "bk_timer_off"
        }
    }

    private func notificationLevelLabel(_ level: String) -> String { level == "name" ? "ch_notif_name" : level == "none" ? "ch_notif_none" : "ch_notif_all" }
}

/// One row of the privacy card — same type, size and toggle as Account → Notifications.
struct PrivacySettingsRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    var subtitle: String?
    var value: String?
    /// A figure at the end of the row (how many).
    var trailing: String?
    var last = false
    var checked: Bool?
    var onChecked: ((Bool) -> Void)?
    var onTap: (() -> Void)?

    init(icon: String, title: String, subtitle: String? = nil, value: String? = nil, trailing: String? = nil, last: Bool = false, checked: Bool? = nil, onTap: (() -> Void)? = nil) {
        self.icon = icon
        self.title = title
        self.subtitle = subtitle
        self.value = value
        self.trailing = trailing
        self.last = last
        self.checked = checked
        self.onTap = onTap
    }

    init(icon: String, title: String, subtitle: String? = nil, last: Bool = false, checked: Bool, onChecked: @escaping (Bool) -> Void) {
        self.icon = icon
        self.title = title
        self.subtitle = subtitle
        self.last = last
        self.checked = checked
        self.onChecked = onChecked
    }

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 10) {
                Image(systemName: icon).font(.system(size: 15)).foregroundStyle(wa.accent)
                    .frame(width: 32, height: 32).background(wa.accent.opacity(0.14), in: RoundedRectangle(cornerRadius: 10))
                VStack(alignment: .leading, spacing: 2) {
                    (Text(verbatim: title).font(.dorr(13.5, .bold)).foregroundColor(wa.settingsInk)
                        + Text(verbatim: value.map { " (\($0))" } ?? "").font(.dorr(11)).foregroundColor(wa.settingsMut))
                        .lineLimit(2)
                    if let subtitle { Text(verbatim: subtitle).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if let trailing, !trailing.isEmpty {
                    Text(verbatim: trailing).font(.dorr(13.5, .heavy)).foregroundStyle(wa.settingsInk).padding(.trailing, 2)
                }
                if let checked, let onChecked {
                    Toggle("", isOn: Binding(get: { checked }, set: onChecked)).labelsHidden().tint(wa.accent)
                } else if onTap != nil {
                    Image(systemName: "chevron.forward").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.settingsMut)
                }
            }
            .padding(.horizontal, 4).padding(.vertical, 12)
            .contentShape(Rectangle())
            .onTapGesture { onTap?() }
            if !last { Rectangle().fill(wa.night ? wa.line : Color(hex: 0xF6E4E8)).frame(height: 1) }
        }
    }
}

// MARK: - Blocked

struct BlockedContactsPage: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    @State private var query = ""
    @State private var list: [BlockDto]?
    @State private var page = 1
    @State private var hasMore = false
    @State private var loadingMore = false

    var body: some View {
        ChSettingsPage(title: L("ch_blocked_list"), onBack: { host.pop() }) {
            VStack(spacing: 0) {
                ChField(placeholder: L("ch_blocked_search"), text: $query, icon: "magnifyingglass", limit: 80).padding(.top, 6).padding(.bottom, 8)
                if let items = list {
                    if items.isEmpty {
                        ChEmptyState(icon: "nosign", title: L("ch_blocked_list"), text: L("ch_no_blocked"), animated: true).frame(maxHeight: .infinity)
                    } else {
                        ScrollView {
                            LazyVStack(spacing: 8) {
                                ForEach(Array(items.enumerated()), id: \.offset) { i, b in
                                    row(b, i).onAppear { if i >= items.count - 3 { Task { await loadPage(reset: false) } } }
                                }
                                if loadingMore { ProgressView().tint(ch.red).padding(12) }
                            }
                            .padding(.bottom, 16)
                        }
                    }
                } else {
                    ProgressView().tint(ch.red).frame(maxHeight: .infinity)
                }
            }
            .padding(.horizontal, 14)
        }
        .task(id: query) {
            try? await Task.sleep(nanoseconds: 280_000_000)
            guard !Task.isCancelled else { return }
            await loadPage(reset: true)
        }
    }

    private func row(_ b: BlockDto, _ i: Int) -> some View {
        HStack(spacing: 12) {
            ChAvatar(url: b.profile?.avatar, name: b.profile?.name, key: b.profile?.key, size: 44)
            VStack(alignment: .leading, spacing: 1) {
                Text(verbatim: b.profile?.name ?? "").font(.dorr(15, .bold)).foregroundStyle(ch.ink)
                if let phone = b.profile?.phone, !phone.isEmpty { Text(verbatim: ltrNumber(phone)).font(.dorr(12.5)).foregroundStyle(ch.mut) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            Button {
                guard let id = b.profile?.id else { return }
                Task {
                    if (try? await ChatApi.unblock(participantId: id)) != nil {
                        withAnimation { list = list?.filter { $0.profile?.id != id } }
                        host.showToast(L("ch_unblock_done"))
                    }
                }
            } label: {
                Text(L("ch_unblock")).font(.dorr(13, .heavy)).foregroundStyle(ch.red).padding(.horizontal, 12).padding(.vertical, 7)
                    .background(ch.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 12))
            }
            .buttonStyle(.plain)
        }
        .padding(12)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 18))
        .chStagger(i)
    }

    private func loadPage(reset: Bool) async {
        if !reset && (loadingMore || !hasMore) { return }
        if !reset { loadingMore = true }
        let next = reset ? 1 : page + 1
        let q = query.trimmingCharacters(in: .whitespaces)
        let envelope = try? await ChatApi.blocks(q: q.isEmpty ? nil : q, page: next, perPage: 15)
        let chunk = envelope?.data ?? []
        if reset { list = chunk } else {
            let known = Set((list ?? []).compactMap { $0.profile?.key })
            list = (list ?? []) + chunk.filter { !known.contains($0.profile?.key ?? "") }
        }
        page = next
        hasMore = envelope?.pagination?.hasMorePages == true
        if !reset { loadingMore = false }
    }
}

// MARK: - Starred

/// My favourites: all, the ones in no folder, and each folder of mine. A long press on a folder manages it.
struct StarredPage: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    @State private var folders: StarFoldersDto?
    @State private var selected: String?
    @State private var query = ""
    @State private var list: [MessageDto]?
    @State private var page = 1
    @State private var hasMore = false
    @State private var loadingMore = false
    @State private var managing: StarFolderDto?
    @State private var renaming: StarFolderDto?
    @State private var creating = false
    @State private var moving: MessageDto?

    var body: some View {
        ChSettingsPage(title: L("ch_starred_title"), onBack: { host.pop() }) {
            VStack(spacing: 0) {
                ChField(placeholder: L("ch_starred_search"), text: $query, icon: "magnifyingglass", limit: 80).padding(.horizontal, 14).padding(.bottom, 4)
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        chip(L("cp_all"), selected == nil, nil) { selected = nil }
                        chip("⭐ " + L("cp_favourites"), selected == "none", nil) { selected = "none" }
                        ForEach(folders?.folders ?? []) { f in
                            chip([f.emoji, f.name, "(\(f.count ?? 0))"].compactMap { $0 }.joined(separator: " "), selected == String(f.id), hexColor(f.color)) { selected = String(f.id) }
                                .onLongPressGesture { managing = f }
                        }
                        Button { creating = true } label: {
                            Image(systemName: "plus").font(.system(size: 17, weight: .bold)).foregroundStyle(ch.red).frame(width: 36, height: 36).background(ch.surface, in: Circle())
                        }
                        .buttonStyle(.plain)
                    }
                    .padding(.horizontal, 14).padding(.vertical, 10)
                }
                if let items = list {
                    if items.isEmpty {
                        ChEmptyState(icon: "star.fill", title: L("ch_starred_title"), text: L("ch_no_starred"), animated: true).frame(maxHeight: .infinity)
                    } else {
                        ScrollView {
                            LazyVStack(spacing: 6) {
                                ForEach(Array(items.enumerated()), id: \.element.id) { i, m in
                                    StarredMessageCard(host: host, m: m, index: i, showStar: selected == "none" || (selected == nil && m.starFolderId == nil), onTap: {
                                        host.showInChat(m.conversationId, m.id)
                                    }, onLong: { moving = m })
                                    .onAppear { if i >= items.count - 3 { Task { await loadPage(reset: false) } } }
                                }
                                if loadingMore { ProgressView().tint(ch.red).padding(16) }
                            }
                            .padding(.horizontal, 14).padding(.bottom, 16)
                        }
                    }
                } else {
                    ProgressView().tint(ch.red).frame(maxHeight: .infinity)
                }
            }
        }
        .task(id: selected) { folders = (try? await ChatMoreApi.starFolders().data) ?? StarFoldersDto() }
        .task(id: "\(selected ?? "-")|\(query)") {
            if !query.isEmpty { try? await Task.sleep(nanoseconds: 280_000_000) }
            guard !Task.isCancelled else { return }
            list = nil
            await loadPage(reset: true)
        }
        .dorrDialog(isPresented: Binding(get: { creating || managing != nil || renaming != nil || moving != nil }, set: { if !$0 { creating = false; managing = nil; renaming = nil; moving = nil } }), bottom: true) {
            sheets
        }
    }

    @ViewBuilder
    private var sheets: some View {
        if creating {
            TextInputSheet(title: L("cp_new_star_folder"), action: L("ch_save"), icon: "folder.fill", limit: 40, onDismiss: { creating = false }) { name in
                Task { _ = try? await ChatMoreApi.createStarFolder(["name": .string(name)]); await reload() }
            }
        } else if let f = renaming {
            TextInputSheet(title: L("ch_rename_folder"), initial: f.name, action: L("ch_save"), icon: "pencil", limit: 40, onDismiss: { renaming = nil }) { name in
                Task { _ = try? await ChatMoreApi.updateStarFolder(f.id, ["name": .string(name)]); await reload() }
            }
        } else if let f = managing {
            ChoiceSheet(title: f.name, options: [
                (L("ch_rename_folder"), { Task { try? await Task.sleep(nanoseconds: 350_000_000); renaming = f } }),
                (L("ch_delete_folder"), {
                    Task {
                        _ = try? await ChatMoreApi.deleteStarFolder(f.id)
                        if selected == String(f.id) { selected = nil }
                        await reload()
                    }
                }),
            ]) { managing = nil }
        } else if let m = moving {
            StarFolderSheet(onDismiss: { moving = nil }) { folderId in
                Task { _ = try? await ChatApi.star(m.id, true, folderId: folderId); await reload() }
            }
        }
    }

    private func chip(_ label: String, _ on: Bool, _ tint: Color?, _ action: @escaping () -> Void) -> some View {
        Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(on ? .white : ch.ink)
            .padding(.horizontal, 14).padding(.vertical, 8)
            .background(on ? (tint ?? ch.red) : ch.surface, in: RoundedRectangle(cornerRadius: 18))
            .onTapGesture(perform: action)
    }

    private func reload() async {
        folders = (try? await ChatMoreApi.starFolders().data) ?? StarFoldersDto()
        await loadPage(reset: true)
    }

    private func loadPage(reset: Bool) async {
        if !reset && (loadingMore || !hasMore) { return }
        if !reset { loadingMore = true }
        let next = reset ? 1 : page + 1
        let q = query.trimmingCharacters(in: .whitespaces)
        let envelope = try? await ChatMoreApi.starred(folder: selected, q: q.isEmpty ? nil : q, page: next, perPage: 15)
        let chunk = envelope?.data ?? []
        if reset { list = chunk } else {
            let known = Set((list ?? []).map(\.id))
            list = (list ?? []) + chunk.filter { !known.contains($0.id) }
        }
        page = next
        hasMore = envelope?.pagination?.hasMorePages == true
        if !reset { loadingMore = false }
    }
}

private struct StarredMessageCard: View {
    @Environment(\.wa) private var wa
    @ObservedObject var host: ChatHost
    let m: MessageDto
    let index: Int
    let showStar: Bool
    let onTap: () -> Void
    let onLong: () -> Void
    @State private var open = false

    var body: some View {
        let convo = host.find(m.conversationId)
        let senderName = m.sender?.isMe == true ? L("ch_you") : m.sender?.name ?? ""
        let title = convo?.title.flatMap { $0.isEmpty ? nil : $0 } ?? senderName
        let showSender = convo?.type == "group" && !senderName.isEmpty && senderName != title
        let (kindIcon, kindLabel) = previewOf(m.type, deleted: m.deleted)
        let preview: String = m.deleted ? L("ch_deleted")
            : m.type == "poll" ? [L("ch_poll"), m.poll?.question].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: ": ")
            : m.type == "call" ? (m.body.flatMap { $0.isEmpty ? nil : $0 } ?? L("ch_call_missed"))
            : (m.body.flatMap { $0.isEmpty ? nil : plainChatText($0) } ?? kindLabel)
        let stamp = parseRideTime(m.createdAt).map { d -> String in
            let f = DateFormatter()
            f.dateFormat = "dd/MM/yyyy"
            return f.string(from: d) + " - " + clockTime(m.createdAt)
        } ?? ""
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 8) {
                ChAvatar(url: convo?.avatar ?? m.sender?.avatar, name: title, key: convo?.peer?.key ?? m.sender?.key, size: 36, isGroup: convo?.type == "group")
                VStack(alignment: .leading, spacing: 1) {
                    HStack(spacing: 4) {
                        Text(verbatim: title).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        if showStar { Image(systemName: "star.fill").font(.system(size: 11)).foregroundStyle(Color(hex: 0xFBBF24)) }
                    }
                    if showSender { Text(verbatim: senderName).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                    if !open && !preview.isEmpty {
                        HStack(spacing: 4) {
                            if let icon = m.type == "call" ? "phone.arrow.down.left" : kindIcon, !m.deleted { Image(systemName: icon).font(.system(size: 11)).foregroundStyle(wa.settingsMut) }
                            Text(verbatim: preview).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                        }
                        .padding(.top, 2)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if !stamp.isEmpty { Text(verbatim: stamp).font(.dorr(10.5)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                Button { withAnimation(.easeInOut(duration: 0.25)) { open.toggle() } } label: {
                    Image(systemName: open ? "chevron.up" : "chevron.down").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 28, height: 28)
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L(open ? "ch_starred_collapse" : "ch_starred_expand"))
            }
            if open {
                MessageRow(m: UiMessage(dto: m), firstInRun: true, lastInRun: true, isGroup: convo?.type == "group",
                           actions: BubbleActions(onReply: { _ in }, onLongPress: { _ in }, onJumpTo: { _ in }, onRetry: { _ in }, onOpenMedia: { _, _ in },
                                                  onPayQr: { host.openWalletQr($0) }, onMessageContact: { _ in }))
                    .transition(.opacity)
            }
        }
        .padding(.horizontal, 10).padding(.vertical, 8)
        .modifier(SettingsSurface(radius: 16, elevation: 4))
        .contentShape(Rectangle())
        .onTapGesture(perform: onTap)
        .onLongPressGesture(perform: onLong)
        .chStagger(index)
    }
}

// MARK: - My status

/// Ready-made statuses: emoji + a string.
private let statusPresets = [("🏖️", "ch_status_holiday"), ("💼", "ch_status_work"), ("🚗", "ch_status_driving"), ("📵", "ch_status_busy"),
                             ("🤒", "ch_status_sick"), ("🌙", "ch_status_sleeping"), ("✈️", "ch_status_travelling"), ("🕌", "ch_status_prayer")]

/// My status (spec 90): an emoji and a few words, how long it lasts, and who sees it. Shown under my name in other
/// people's chats.
struct MyStatusPage: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    @State private var loaded: PrivacyDto?
    @State private var emoji = ""
    @State private var text = ""
    @State private var hours: Int?
    @State private var audience = "contacts"
    // Hidden from these people (WhatsApp list 54).
    @State private var except: [String] = []
    @State private var pickExcept = false
    @State private var busy = false

    var body: some View {
        let preview = [emoji.trimmingCharacters(in: .whitespaces), text.trimmingCharacters(in: .whitespaces)].filter { !$0.isEmpty }.joined(separator: " ")
        let hasStatus = loaded?.status?.active == true
        ChSettingsPage(title: L("ch_status_title"), onBack: { host.pop() }) {
            VStack(spacing: 0) {
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("ch_status_sub")).font(.dorr(13)).foregroundStyle(ch.mut)
                        HStack(spacing: 12) {
                            ChAvatar(url: nil, name: L("ch_status_sample_name"), key: "status-sample", size: 46)
                            VStack(alignment: .leading, spacing: 1) {
                                Text(L("ch_status_sample_name")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                                Text(verbatim: preview.isEmpty ? L("ch_status_hint") : preview).font(.dorr(13, preview.isEmpty ? .regular : .bold))
                                    .foregroundStyle(preview.isEmpty ? wa.settingsMut : wa.accent).lineLimit(2)
                            }
                            Spacer()
                        }
                        .padding(.horizontal, 14).padding(.vertical, 12)
                        .modifier(SettingsSurface(radius: 18, elevation: 8))
                        .padding(.top, 12)
                        HStack(spacing: 8) {
                            TextField("🙂", text: Binding(get: { emoji }, set: { emoji = String($0.prefix(4)) })).font(.system(size: 22)).multilineTextAlignment(.center)
                                .frame(width: 80, height: 52).background(ch.fieldFill, in: RoundedRectangle(cornerRadius: 18))
                                .overlay(RoundedRectangle(cornerRadius: 18).stroke(ch.fieldLine, lineWidth: 1))
                            ChField(placeholder: L("ch_status_hint"), text: $text, icon: "face.smiling", limit: 100)
                        }
                        .padding(.top, 14)
                        Menu {
                            Button(L("ch_status_pick")) { emoji = ""; text = "" }
                            ForEach(statusPresets, id: \.1) { e, key in Button("\(e) \(L(key))") { emoji = e; text = L(key) } }
                        } label: {
                            HStack {
                                let match = statusPresets.first { $0.0 == emoji && L($0.1) == text }
                                Text(verbatim: match.map { "\($0.0) \(L($0.1))" } ?? L("ch_status_pick")).font(.dorr(14, .bold)).foregroundStyle(match == nil ? ch.soft : ch.ink)
                                Spacer()
                                Image(systemName: "chevron.down").font(.system(size: 14, weight: .semibold)).foregroundStyle(ch.mut)
                            }
                            .padding(.horizontal, 16).padding(.vertical, 14)
                            .background(ch.fieldFill, in: RoundedRectangle(cornerRadius: 18))
                            .overlay(RoundedRectangle(cornerRadius: 18).stroke(ch.fieldLine, lineWidth: 1))
                        }
                        .padding(.top, 12)
                        Text(L("ch_status_for")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsMut).padding(.top, 16).padding(.bottom, 8)
                        VStack(spacing: 8) {
                            ForEach([(Optional(1), "ch_status_1h"), (4, "ch_status_4h"), (24, "ch_status_today"), (168, "ch_status_week"), (nil, "ch_status_forever")], id: \.1) { h, label in
                                choice(L(label), hours == h) { hours = h }
                            }
                        }
                        Text(L("ch_status_who")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsMut).padding(.top, 16).padding(.bottom, 8)
                        VStack(spacing: 8) {
                            ForEach([("everyone", "ch_everyone"), ("contacts", "ch_my_contacts"), ("nobody", "ch_nobody")], id: \.0) { value, label in
                                choice(L(label), audience == value) { audience = value }
                            }
                        }
                        if audience != "nobody" {
                            Button { pickExcept = true } label: {
                                HStack(spacing: 8) {
                                    Image(systemName: "person.fill.xmark").font(.system(size: 17)).foregroundStyle(ch.ink)
                                    Text(L("ch_status_except_people")).font(.dorr(14, .bold)).foregroundStyle(ch.ink).frame(maxWidth: .infinity, alignment: .leading)
                                    Text(verbatim: "\(except.count)").font(.dorr(14, .heavy)).foregroundStyle(ch.red)
                                    Image(systemName: "chevron.forward").font(.system(size: 14, weight: .semibold)).foregroundStyle(ch.soft)
                                }
                                .padding(.horizontal, 16).padding(.vertical, 14)
                                .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 18))
                                .overlay(RoundedRectangle(cornerRadius: 18).stroke(ch.line, lineWidth: 1))
                            }
                            .buttonStyle(.plain).padding(.top, 12)
                        }
                    }
                    .padding(.horizontal, 20).padding(.bottom, 12)
                }
                HStack(spacing: 10) {
                    ChPrimaryButton(text: L("ch_save"), enabled: !busy && !preview.isEmpty) { saveStatus() }
                    Button {
                        save(L("ch_status_removed")) { _ = try await ChatApi.clearStatus() }
                    } label: {
                        Text(L("ch_status_clear")).font(.dorr(15, .heavy)).foregroundStyle(ch.danger.opacity(hasStatus ? 1 : 0.45))
                            .frame(maxWidth: .infinity).padding(.vertical, 14).background(ch.danger.opacity(0.12), in: RoundedRectangle(cornerRadius: 18))
                    }
                    .buttonStyle(.plain).disabled(busy || !hasStatus)
                }
                .padding(.horizontal, 20).padding(.vertical, 12)
            }
        }
        .task {
            guard let p = try? await ChatApi.privacy().data else { return }
            loaded = p
            if p.status?.active == true { emoji = p.status?.emoji ?? ""; text = p.status?.text ?? "" }
            audience = p.status?.audience ?? "contacts"
            except = p.status?.except ?? []
        }
        .dorrDialog(isPresented: $pickExcept, bottom: true) {
            ExceptPicker(host: host, keys: except, onDismiss: { pickExcept = false }) { except = $0; pickExcept = false }
        }
    }

    private func choice(_ label: String, _ selected: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack {
                Text(verbatim: label).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                Spacer()
                Image(systemName: selected ? "largecircle.fill.circle" : "circle").font(.system(size: 20)).foregroundStyle(selected ? wa.accent : wa.settingsMut)
            }
            .padding(14)
            .modifier(SettingsSurface(radius: 18, elevation: 8))
        }
        .buttonStyle(.plain)
    }

    private func saveStatus() {
        save(L("ch_status_saved")) {
            var body: [String: Any?] = ["audience": audience, "except": except]
            if !emoji.trimmingCharacters(in: .whitespaces).isEmpty { body["emoji"] = emoji.trimmingCharacters(in: .whitespaces) }
            if !text.trimmingCharacters(in: .whitespaces).isEmpty { body["text"] = text.trimmingCharacters(in: .whitespaces) }
            if let hours { body["until"] = isoWithOffset(Date().addingTimeInterval(TimeInterval(hours * 3600))) }
            _ = try await ChatApi.setStatus(body)
        }
    }

    private func save(_ ok: String, _ call: @escaping () async throws -> Void) {
        busy = true
        Task {
            do {
                try await call()
                host.showToast(ok)
                host.pop()
            } catch {
                host.showToast(error.apiFailure.message ?? L("ch_error_network"))
            }
            busy = false
        }
    }
}

/// The people my status is hidden from: my contacts on Dorr, ticked.
private struct ExceptPicker: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var host: ChatHost
    let keys: [String]
    let onDismiss: () -> Void
    let onDone: ([String]) -> Void
    @State private var picked = Set<Int>()
    @State private var contacts: [ContactDto]?
    @State private var query = ""

    var body: some View {
        ChSheetCard {
            Text(L("ch_status_pick_contact")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            ChField(placeholder: L("ch_search_contacts"), text: $query, icon: "magnifyingglass").padding(.top, 10)
            ScrollView {
                LazyVStack(spacing: 6) {
                    ForEach((contacts ?? []).filter { query.isEmpty || $0.name.localizedCaseInsensitiveContains(query) }) { c in
                        let id = c.profile?.id ?? 0
                        ContactRow(host: host, contact: c, index: 0, trailing: {
                            Image(systemName: picked.contains(id) ? "checkmark.circle.fill" : "circle").font(.system(size: 22)).foregroundStyle(picked.contains(id) ? ch.red : ch.soft)
                        }) { if picked.contains(id) { picked.remove(id) } else { picked.insert(id) } }
                    }
                }
            }
            .frame(maxHeight: 400).padding(.top, 8)
            ChPrimaryButton(text: L("ch_save")) { onDone(picked.sorted().map { "user:\($0)" }) }.padding(.top, 12)
        }
        .task {
            picked = Set(keys.compactMap { Int($0.replacingOccurrences(of: "user:", with: "")) })
            contacts = (try? await ChatApi.contacts(registered: 1).data) ?? []
        }
    }
}

/// DEBUG `-DorrScrollBottom YES`: a page opens at its end, for screenshots of its last rows.
struct DebugScrollBottom: ViewModifier {
    func body(content: Content) -> some View {
        #if DEBUG
        if #available(iOS 17.0, *), UserDefaults.standard.bool(forKey: "DorrScrollBottom") {
            content.defaultScrollAnchor(.bottom)
        } else {
            content
        }
        #else
        content
        #endif
    }
}
