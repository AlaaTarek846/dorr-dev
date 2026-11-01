import SwiftUI

// androidApp `chat/ChatPartialUi.kt` (CatchUpPage, ChatMoneyPage, DecisionRoomPage, PrivacyCenterPage, UsernameSheet) —
// «what I missed» across my chats, the money in one chat, the decision room (arguments for and against), every privacy
// choice in one place, and my @username.

// MARK: - what I missed

struct CatchUpPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var range = 24
    @State private var query = ""
    @State private var data: CatchUpDto?
    @State private var unread: [UnreadChatDto] = []
    @State private var page = 1
    @State private var hasMore = false
    @State private var loadingMore = false

    var body: some View {
        ChSettingsPage(title: L("cp_catch_up"), onBack: { host.pop() }) {
            ChField(placeholder: L("cp_search"), text: $query, icon: "magnifyingglass", limit: 80).padding(.horizontal, 14).padding(.bottom, 8)
            HStack(spacing: 6) {
                ForEach([(8, "cp_8h"), (24, "cp_24h"), (72, "cp_3d"), (168, "cp_week")], id: \.0) { h, key in
                    let on = range == h
                    Button { range = h } label: {
                        HStack(spacing: 4) {
                            Image(systemName: "clock").font(.system(size: 11))
                            Text(L(key)).font(.dorr(11.5, .bold)).lineLimit(1)
                        }
                        .foregroundStyle(on ? .white : ch.ink)
                        .frame(maxWidth: .infinity).padding(.vertical, 6)
                        .background(on ? ch.red : ch.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                        .animation(.easeOut(duration: 0.2), value: on)
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 14).padding(.bottom, 8)
            if let d = data {
                ScrollView { content(d).padding(.horizontal, 14).padding(.bottom, 24) }
            } else {
                ProgressView().tint(ch.red).frame(maxHeight: .infinity)
            }
        }
        .task(id: "\(range)|\(query)") {
            if !query.trimmingCharacters(in: .whitespaces).isEmpty { try? await Task.sleep(nanoseconds: 280_000_000) }
            guard !Task.isCancelled else { return }
            data = nil
            unread = []
            page = 1
            hasMore = false
            await load(reset: true)
        }
    }

    @ViewBuilder
    private func content(_ d: CatchUpDto) -> some View {
        let empty = (d.mentions ?? []).isEmpty && (d.replies ?? []).isEmpty && (d.urgent ?? []).isEmpty && (d.owed ?? []).isEmpty
            && (d.missedCalls ?? []).isEmpty && (d.reminders ?? []).isEmpty && (d.decisions ?? []).isEmpty && unread.isEmpty
        LazyVStack(alignment: .leading, spacing: 0) {
            if empty {
                Text(L("cp_caught_up")).font(.dorr(15, .bold)).foregroundStyle(ch.mut).multilineTextAlignment(.center).frame(maxWidth: .infinity).padding(.top, 40)
            } else {
                refs(L("cp_sec_urgent"), "bell.badge.fill", ch.danger, d.urgent ?? [])
                refs(L("cp_sec_mentions"), "at", ch.red, d.mentions ?? [])
                refs(L("cp_sec_replies"), "arrowshape.turn.up.left.fill", Color(hex: 0x2563EB), d.replies ?? [])
                refs(L("cp_sec_owed"), "brain.head.profile", Color(hex: 0xD97706), d.owed ?? [])
                if let calls = d.missedCalls, !calls.isEmpty {
                    section(L("cp_sec_calls"), "phone.arrow.down.left", ch.danger)
                    ForEach(calls) { c in
                        RefCard(top: [c.caller, c.chat == c.caller ? nil : c.chat].compactMap { $0 }.joined(separator: " · "),
                                text: L(c.type == "video" ? "cp_video_call" : "cp_voice_call"), at: c.createdAt) { open(c.conversationId, nil) }
                    }
                }
                refs(L("cp_sec_reminders"), "bell.badge.fill", Color(hex: 0x7C3AED), d.reminders ?? [])
                if let decisions = d.decisions, !decisions.isEmpty {
                    section(L("cp_sec_decisions"), "hammer.fill", Color(hex: 0x16A34A))
                    ForEach(decisions) { x in RefCard(top: x.chat ?? "", text: x.title, at: x.deadlineAt) { host.push(.decisionRoom(x.id)) } }
                }
                if !unread.isEmpty {
                    section(L("cp_sec_unread"), "eye.fill", ch.mut)
                    ForEach(Array(unread.enumerated()), id: \.offset) { i, u in
                        UnreadCatchRow(host: host, u: u) { open(u.conversationId, nil) }
                            .onAppear { if i >= unread.count - 3 { Task { await load(reset: false) } } }
                    }
                }
            }
            if loadingMore { ProgressView().tint(ch.red).frame(maxWidth: .infinity).padding(16) }
        }
    }

    @ViewBuilder
    private func refs(_ title: String, _ icon: String, _ tint: Color, _ list: [MsgRefDto]) -> some View {
        if !list.isEmpty {
            section("\(title) (\(list.count))", icon, tint)
            ForEach(Array(list.enumerated()), id: \.offset) { _, r in
                RefCard(top: [r.chat, r.sender == r.chat ? nil : r.sender].compactMap { $0 }.joined(separator: " · "),
                        text: r.note.map { "📝 \($0) · \(r.excerpt ?? "")" } ?? r.excerpt ?? "", at: r.createdAt) { open(r.conversationId, r.messageId) }
            }
        }
    }

    private func section(_ title: String, _ icon: String, _ tint: Color) -> some View {
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 14)).foregroundStyle(tint)
            Text(verbatim: title).font(.dorr(13.5, .heavy)).foregroundStyle(ch.ink)
        }
        .padding(.top, 14).padding(.bottom, 8)
    }

    private func open(_ c: String?, _ m: String?) {
        guard let c else { return }
        if let m { host.focusRequest = (c, m) }
        host.push(.conversation(c))
    }

    private func load(reset: Bool) async {
        if !reset && (loadingMore || !hasMore) { return }
        if !reset { loadingMore = true }
        let next = reset ? 1 : page + 1
        let since = ISO8601DateFormatter().string(from: Date().addingTimeInterval(-Double(range) * 3600))
        let q = query.trimmingCharacters(in: .whitespaces)
        let chunk = try? await ChatMoreApi.catchUp(since: since, q: q.isEmpty ? nil : q, page: next, perPage: 15).data
        if reset {
            data = chunk ?? CatchUpDto()
            unread = chunk?.unreadChats ?? []
        } else {
            let known = Set(unread.compactMap(\.conversationId))
            unread += (chunk?.unreadChats ?? []).filter { !known.contains($0.conversationId ?? "") }
        }
        page = next
        hasMore = chunk?.hasMore == true
        if !reset { loadingMore = false }
    }
}

private struct RefCard: View {
    @ObservedObject private var ch = Ch.shared
    let top: String
    let text: String
    let at: String?
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text(verbatim: top).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                    Spacer()
                    if let at { Text(verbatim: listTime(at)).font(.dorr(11)).foregroundStyle(ch.mut) }
                }
                if !text.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: text).font(.dorr(13)).foregroundStyle(ch.mut).lineLimit(2).multilineTextAlignment(.leading)
                }
            }
            .padding(.horizontal, 12).padding(.vertical, 11)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .shadow(color: .black.opacity(0.05), radius: 4, y: 2)
        }
        .buttonStyle(.plain)
        .padding(.bottom, 8)
    }
}

private struct UnreadCatchRow: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let u: UnreadChatDto
    let onClick: () -> Void

    var body: some View {
        let convo = u.conversationId.flatMap { host.find($0) }
        let title = u.chat.flatMap { $0.isEmpty ? nil : $0 } ?? convo?.title ?? ""
        let last = convo?.lastMessage
        let kind = last.map { previewOf($0.type ?? "", deleted: $0.isDeleted == true) } ?? (nil, "")
        let preview: String = {
            guard let last else { return "" }
            if last.isDeleted == true { return L("ch_deleted") }
            if let b = last.body?.trimmingCharacters(in: .whitespacesAndNewlines), !b.isEmpty { return b }
            return kind.1
        }()
        Button(action: onClick) {
            HStack(spacing: 10) {
                ChAvatar(url: convo?.avatar, name: title, key: convo?.peer?.key ?? u.conversationId, size: 40, isGroup: convo?.type == "group")
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: title).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                    if !preview.isEmpty {
                        HStack(spacing: 4) {
                            if let icon = kind.0, (last?.body ?? "").isEmpty { Image(systemName: icon).font(.system(size: 12)).foregroundStyle(ch.mut) }
                            Text(verbatim: preview).font(.dorr(12.5)).foregroundStyle(ch.mut).lineLimit(1)
                        }
                    }
                }
                Spacer(minLength: 8)
                if u.mention == true {
                    Image(systemName: "at").font(.system(size: 11, weight: .bold)).foregroundStyle(.white).frame(width: 20, height: 20).background(Ch.mention, in: Circle())
                }
                if (u.unread ?? 0) > 0 { ChBadge(count: u.unread ?? 0, muted: convo?.isMuted == true) }
            }
            .padding(.horizontal, 12).padding(.vertical, 11)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .shadow(color: .black.opacity(0.05), radius: 4, y: 2)
        }
        .buttonStyle(.plain)
        .padding(.bottom, 8)
    }
}

// MARK: - 66 money in a chat

/// My wallet balance and the money in this chat: transfers, requests, splits, and what's owed.
struct ChatMoneyPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let conversationId: String
    @State private var items: [MoneyItemDto] = []
    @State private var totals = MoneyTotalsDto()
    @State private var hasMore = false
    @State private var page = 1
    @State private var loaded = false
    @State private var loadingMore = false
    @State private var wallet: WalletBalanceDto?

    var body: some View {
        ChSettingsPage(title: L("cp_money"), onBack: { host.pop() }) {
            if !loaded {
                ProgressView().tint(ch.red).frame(maxHeight: .infinity)
            } else {
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 0) {
                        balanceCard
                        let cur = totals.currency
                        HStack(spacing: 8) {
                            total("arrow.down.left", L("cp_received"), formatMinor(Int(totals.receivedMinor ?? 0), cur), Color(hex: 0x16A34A), iconFirst: true)
                            total("arrow.up.right", L("cp_sent"), formatMinor(Int(totals.sentMinor ?? 0), cur), ch.red, iconFirst: false)
                        }
                        .padding(.top, 8)
                        HStack(spacing: 8) {
                            total("clock", L("cp_i_owe"), formatMinor(Int(totals.iOweMinor ?? 0), cur), Color(hex: 0x94A3B8), iconFirst: true)
                            total("creditcard", L("cp_owed_to_me"), formatMinor(Int(totals.owedToMeMinor ?? 0), cur), Color(hex: 0x94A3B8), iconFirst: false)
                        }
                        .padding(.top, 8)
                        Text(L("cp_history")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).padding(.top, 16).padding(.bottom, 8)
                        if items.isEmpty { Text(L("cp_no_money")).font(.dorr(13.5)).foregroundStyle(ch.soft) }
                        ForEach(items, id: \.messageId) { m in historyRow(m) }
                        if hasMore {
                            ChPrimaryButton(text: L("cp_show_more"), enabled: !loadingMore) { page += 1 }.padding(.top, 8)
                        }
                    }
                    .padding(.horizontal, 14).padding(.bottom, 24)
                }
            }
        }
        .task(id: conversationId) { wallet = try? await WalletApi.balance().data }
        .task(id: page) {
            if page > 1 { loadingMore = true }
            let data = (try? await ChatMoreApi.money(conversationId, page: page, perPage: 15).data) ?? ChatMoneyDto()
            totals = data.totals ?? MoneyTotalsDto()
            items = page == 1 ? (data.items ?? []) : items + (data.items ?? [])
            hasMore = data.hasMore == true
            loaded = true
            loadingMore = false
        }
    }

    private var balanceCard: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack {
                chip {
                    Image(systemName: "wallet.pass.fill").font(.system(size: 11)).foregroundStyle(.white.opacity(0.9))
                    Text(L("wa_total_balance")).font(.dorr(11, .semibold)).foregroundStyle(.white.opacity(0.92))
                }
                Spacer()
                let number = wallet?.walletNumberFormatted ?? wallet?.walletNumber ?? ""
                if !number.isEmpty {
                    chip {
                        WalletFlag(code: wallet?.countryCode, width: 16, height: 11)
                        Text(verbatim: number).font(.dorr(11, .bold)).foregroundStyle(.white).lineLimit(1).environment(\.layoutDirection, .leftToRight)
                    }
                }
            }
            Rectangle().fill(Color.white.opacity(0.16)).frame(height: 1).padding(.vertical, 10)
            HStack(alignment: .lastTextBaseline, spacing: 8) {
                Text(verbatim: wallet.map { formatMinor($0.totalMinor, nil) } ?? "—").font(.dorr(22, .heavy)).foregroundStyle(.white)
                if let code = wallet?.currencyCode, !code.isEmpty { Text(verbatim: code).font(.dorr(13, .bold)).foregroundStyle(.white.opacity(0.82)) }
            }
            .environment(\.layoutDirection, .leftToRight)
        }
        .padding(.horizontal, 14).padding(.vertical, 12)
        .background(ch.headerBrush, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }

    private func chip<C: View>(@ViewBuilder _ content: () -> C) -> some View {
        HStack(spacing: 5, content: content)
            .padding(.horizontal, 9).padding(.vertical, 4)
            .background(Color.white.opacity(0.12), in: Capsule())
            .overlay(Capsule().stroke(Color.white.opacity(0.16), lineWidth: 1))
    }

    private func total(_ icon: String, _ label: String, _ value: String, _ tint: Color, iconFirst: Bool) -> some View {
        VStack(alignment: .leading, spacing: 5) {
            HStack(spacing: 6) {
                let well = Image(systemName: icon).font(.system(size: 12, weight: .bold)).foregroundStyle(tint).frame(width: 26, height: 26).background(tint.opacity(0.2), in: Circle())
                let title = Text(verbatim: label).font(.dorr(12, .bold)).foregroundStyle(tint)
                if iconFirst { well; title; Spacer(minLength: 0) } else { title; Spacer(minLength: 0); well }
            }
            Text(verbatim: value).font(.dorr(14, .heavy)).foregroundStyle(ch.ink)
        }
        .padding(.horizontal, 10).padding(.vertical, 8)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(tint.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }

    private func historyRow(_ m: MoneyItemDto) -> some View {
        let label: String = {
            switch m.type {
            case "money_request": return L("cp_t_request")
            case "bill_split": return L("cp_t_split")
            default: return L(m.isMine == true ? "cp_t_sent" : "cp_t_received")
            }
        }()
        return Button { host.showInChat(conversationId, m.messageId) } label: {
            HStack(spacing: 10) {
                Image(systemName: "banknote.fill").font(.system(size: 18)).foregroundStyle(m.isMine == true ? ch.red : Color(hex: 0x16A34A))
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: label).font(.dorr(14, .bold)).foregroundStyle(ch.ink)
                    Text(verbatim: [m.note, m.status, listTime(m.createdAt)].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(ch.mut).lineLimit(1)
                }
                Spacer()
                Text(verbatim: formatMinor(Int(m.amountMinor ?? 0), m.currency)).font(.dorr(14, .heavy)).foregroundStyle(ch.ink)
            }
            .padding(12)
            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        }
        .buttonStyle(.plain)
        .padding(.bottom, 6)
    }
}

// MARK: - 153 the decision room

/// The question, its options and votes, everyone's arguments for / against, and the admin's decision.
struct DecisionRoomPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let id: String
    @State private var room: DecisionRoomDto?
    @State private var text = ""
    @State private var stance = "pro"
    @State private var option: Int?
    @State private var error: String?

    var body: some View {
        ChPage(title: L("cp_room"), onBack: { host.pop() }) {
            if let r = room {
                ScrollView { content(r).padding(16) }
            } else {
                ProgressView().tint(ch.red)
            }
        }
        .task(id: id) { await load() }
    }

    private func load() async { room = try? await ChatMoreApi.decisionRoom(id).data }

    private func content(_ r: DecisionRoomDto) -> some View {
        let options = r.options ?? []
        let arguments = r.arguments ?? []
        let total = max(options.reduce(0) { $0 + ($1.votes ?? 0) }, 1)
        let (tint, label): (Color, String) = {
            if r.status == "approved" { return (Color(hex: 0x16A34A), L("ch_decision_approved")) }
            if r.status == "rejected" { return (ch.danger, L("ch_decision_rejected")) }
            if r.isClosed == true { return (ch.mut, L("cp_room_closed")) }
            return (Color(hex: 0xF59E0B), L("ch_decision_open"))
        }()
        return VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: r.title).font(.dorr(20, .heavy)).foregroundStyle(ch.ink)
            if let d = r.description { Text(verbatim: d).font(.dorr(14)).foregroundStyle(ch.mut).padding(.top, 4) }
            HStack(spacing: 6) {
                Text(verbatim: label).font(.dorr(12, .heavy)).foregroundStyle(tint).padding(.horizontal, 10).padding(.vertical, 4).background(tint.opacity(0.12), in: Capsule())
                if let dl = r.deadlineAt { Text(L("cp_deadline", listTime(dl))).font(.dorr(12)).foregroundStyle(ch.mut) }
            }
            .padding(.top, 8)
            if let o = r.outcome { Text(verbatim: "→ \(o)").font(.dorr(15, .heavy)).foregroundStyle(Color(hex: 0x16A34A)).padding(.top, 8) }

            // Options with votes and their arguments.
            VStack(spacing: 8) {
                ForEach(options) { o in
                    VStack(alignment: .leading, spacing: 0) {
                        HStack {
                            Text(verbatim: o.text).font(.dorr(15, .bold)).foregroundStyle(ch.ink)
                            Spacer()
                            Text(verbatim: "\(o.votes ?? 0)").font(.dorr(14, .heavy)).foregroundStyle(ch.red)
                        }
                        GeometryReader { g in
                            ZStack(alignment: .leading) {
                                Capsule().fill(ch.surfaceMuted)
                                Capsule().fill(ch.red).frame(width: g.size.width * CGFloat(o.votes ?? 0) / CGFloat(total))
                            }
                        }
                        .frame(height: 6).padding(.top, 6)
                        ForEach(arguments.filter { $0.optionId == String(o.id) }) { a in ArgumentLine(a: a) }
                    }
                    .padding(12)
                    .background(ch.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                }
            }
            .padding(.top, 10)
            let general = arguments.filter { $0.optionId == nil }
            if !general.isEmpty {
                Text(L("cp_room_general")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).padding(.top, 6)
                ForEach(general) { a in ArgumentLine(a: a) }
            }

            // Add mine.
            if r.isClosed != true {
                Text(L("cp_room_add")).font(.dorr(12.5, .bold)).foregroundStyle(ch.mut).padding(.top, 16).padding(.bottom, 6)
                HStack(spacing: 8) {
                    ForEach([("pro", "cp_pro"), ("con", "cp_con"), ("note", "cp_note")], id: \.0) { k, l in
                        pill(L(l), stance == k) { stance = k }
                    }
                }
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 6) {
                        pill(L("cp_room_whole"), option == nil, grow: false) { option = nil }
                        ForEach(options) { o in pill(o.text, option == o.id, grow: false) { option = o.id } }
                    }
                }
                .padding(.top, 8)
                ChField(placeholder: L("cp_room_hint"), text: $text, lines: 1...4, limit: 500).padding(.top, 8)
                if let error { Text(verbatim: error).font(.dorr(12.5)).foregroundStyle(ch.danger) }
                ChPrimaryButton(text: L("cp_room_post"), enabled: !text.trimmingCharacters(in: .whitespaces).isEmpty) {
                    Task {
                        do {
                            room = try await ChatMoreApi.argue(id, stance: stance, text: text.trimmingCharacters(in: .whitespacesAndNewlines), optionId: option.map(String.init)).data
                            text = ""
                            error = nil
                        } catch { self.error = error.apiFailure.message }
                    }
                }
                .padding(.top, 8)
            }
            if r.canDecide == true && r.status == "open" {
                HStack(spacing: 8) {
                    ChPrimaryButton(text: L("ch_decision_approve")) { decide(true) }
                    Button { decide(false) } label: {
                        Text(L("ch_decision_reject")).font(.dorr(15, .bold)).foregroundStyle(ch.danger).frame(maxWidth: .infinity).padding(.vertical, 14)
                            .background(ch.danger.opacity(0.1), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    .buttonStyle(.plain)
                }
                .padding(.top, 14)
            }
        }
    }

    private func decide(_ approve: Bool) {
        Task {
            _ = try? await OrganizeApi.decide(id, approve: approve)
            await load()
        }
    }

    private func pill(_ label: String, _ on: Bool, grow: Bool = true, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(on ? .white : ch.ink).lineLimit(1)
                .padding(.horizontal, 12).padding(.vertical, 8)
                .frame(maxWidth: grow ? .infinity : nil)
                .background(on ? ch.red : ch.surfaceMuted, in: Capsule())
        }
        .buttonStyle(.plain)
    }
}

private struct ArgumentLine: View {
    @ObservedObject private var ch = Ch.shared
    let a: DecisionArgumentDto

    var body: some View {
        let (icon, tint): (String, Color) = {
            switch a.stance {
            case "pro": return ("hand.thumbsup.fill", Color(hex: 0x16A34A))
            case "con": return ("hand.thumbsdown.fill", ch.danger)
            default: return ("text.alignleft", ch.mut)
            }
        }()
        HStack(alignment: .top, spacing: 6) {
            Image(systemName: icon).font(.system(size: 13)).foregroundStyle(tint).padding(.top, 2)
            VStack(alignment: .leading, spacing: 0) {
                if let by = a.by?.name { Text(verbatim: by).font(.dorr(11.5, .bold)).foregroundStyle(tint) }
                Text(verbatim: a.text).font(.dorr(13.5)).foregroundStyle(ch.ink)
            }
        }
        .padding(.top, 6)
    }
}

// MARK: - the privacy center

private func audience(_ value: String?) -> String {
    switch value {
    case "nobody": return L("cp_aud_nobody")
    case "contacts": return L("cp_aud_contacts")
    default: return L("cp_aud_everyone")
    }
}

private struct CenterTopic {
    let icon: String
    let title: String
    let rows: [(String, String)]
    var note: String?
    var value: String?
    var direct = false
    let onPick: () -> Void
}

/// Every privacy choice in one place, each a tap away from where it's changed.
struct PrivacyCenterPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var data: PrivacyCenterDto?
    @State private var username = false
    @State private var sheet: Int?

    var body: some View {
        ChSettingsPage(title: L("cp_privacy_center"), onBack: { host.pop() }) {
            if let d = data {
                let list = topics(d)
                ScrollView {
                    VStack(alignment: .leading, spacing: 8) {
                        VStack(spacing: 0) {
                            ForEach(Array(list.enumerated()), id: \.offset) { i, t in
                                PrivacySettingsRow(icon: t.icon, title: t.title, value: t.value, last: i == list.count - 1) {
                                    if t.direct { t.onPick() } else { sheet = i }
                                }
                            }
                        }
                        .padding(.horizontal, 8).padding(.vertical, 4)
                        .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                        .shadow(color: .black.opacity(0.05), radius: 4, y: 2)
                        Text(L("cp_pc_devices")).font(.dorr(12)).foregroundStyle(ch.mut)
                    }
                    .padding(.horizontal, 14).padding(.top, 6).padding(.bottom, 16)
                }
                .dorrDialog(isPresented: Binding(get: { sheet != nil }, set: { if !$0 { sheet = nil } }), bottom: true) {
                    if let i = sheet, list.indices.contains(i) {
                        let t = list[i]
                        ChoiceSheet(title: t.title, subtitle: t.note, options: t.rows.map { r in (r.0, t.onPick) }, values: t.rows.map(\.1)) { sheet = nil }
                    }
                }
            } else {
                ProgressView().tint(ch.red).frame(maxHeight: .infinity)
            }
        }
        .task { data = try? await ChatMoreApi.privacyCenter().data }
        .dorrDialog(isPresented: $username, bottom: true) {
            UsernameSheet(host: host, onDismiss: { username = false }) { u in data?.username = u }
        }
    }

    /// Map keys come back camel-cased by the snake decoder (`last_seen` → `lastSeen`).
    private func topics(_ d: PrivacyCenterDto) -> [CenterTopic] {
        let sees = d.whoSees ?? [:]
        let can = d.whoCan ?? [:]
        func on(_ m: [String: Bool]?) -> String { L(m?["on"] == true ? "cp_on" : m?["scheduled"] == true ? "cp_scheduled" : "ch_off") }
        return [
            CenterTopic(icon: "eye.fill", title: L("cp_pc_who_sees"), rows: [
                (L("cp_pc_last_seen"), audience(sees["lastSeen"]?.str)), (L("cp_pc_photo"), audience(sees["profilePhoto"]?.str)),
                (L("cp_pc_status"), audience(sees["status"]?.str)), (L("cp_pc_receipts"), L(sees["readReceipts"]?.bool == false ? "ch_off" : "cp_on")),
            ]) { host.push(.privacy) },
            CenterTopic(icon: "shield.fill", title: L("cp_pc_who_can"), rows: [
                (L("cp_pc_message"), audience(can["message"])), (L("cp_pc_groups"), audience(can["addToGroups"])),
                (L("cp_pc_call"), audience(can["call"])), (L("cp_pc_urgent"), audience(can["urgent"])),
            ]) { host.push(.privacy) },
            CenterTopic(icon: "bell.badge.fill", title: L("cp_pc_notifications"), rows: [
                (L("cp_pc_preview"), d.notifications ?? ""), (L("cp_pc_privacy_mode"), on(d.privacyMode)),
                (L("cp_pc_quiet"), on(d.quiet)), (L("cp_pc_screenshots"), L(d.blockScreenshots == true ? "cp_blocked_word" : "cp_allowed")),
            ]) { host.push(.privacy) },
            CenterTopic(icon: "lock.fill", title: L("cp_pc_chats"), rows: [
                (L("cp_pc_circles"), "\(d.circles?["count"] ?? 0) (\(L("cp_pc_locked_n", d.circles?["locked"] ?? 0)))"),
                (L("cp_pc_locked_chats"), "\(d.lockedChats ?? 0)"), (L("cp_pc_pin_chats"), "\(d.pinLockedChats ?? 0)"), (L("cp_pc_blocked"), "\(d.blocked ?? 0)"),
            ]) {
                host.filter = "locked"
                host.pop()
            },
            CenterTopic(icon: "at", title: L("cp_username"), rows: [], value: d.username.flatMap { $0.isEmpty ? nil : "@\($0)" }, direct: true) { username = true },
            CenterTopic(icon: "brain.head.profile", title: L("cp_pc_ai"), rows: [(L("cp_pc_ai_on"), L(d.ai?["enabled"]?.bool == true ? "cp_on" : "ch_off"))],
                        note: d.ai?["reads"]?.str) {},
        ]
    }
}

/// My @username — how people find me without my number.
struct UsernameSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let onDismiss: () -> Void
    var onSaved: (String?) -> Void = { _ in }
    @State private var value = ""
    @State private var hasUsername = false
    @State private var error: String?
    @State private var busy = false

    var body: some View {
        ChSheetCard {
            Text(L("cp_username")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            Text(L("cp_username_sub")).font(.dorr(13)).foregroundStyle(ch.mut).padding(.bottom, 12)
            ChField(placeholder: L("cp_username_hint"), text: Binding(get: { value }, set: { v in
                value = String(v.lowercased().filter { $0.isLetter || $0.isNumber || $0 == "_" }.prefix(32))
            }), icon: "at", limit: 32)
            if let error { Text(verbatim: error).font(.dorr(12.5)).foregroundStyle(ch.danger).padding(.top, 6) }
            HStack(spacing: 8) {
                ChPrimaryButton(text: L("ch_save"), enabled: value.count >= 3 && !busy) { save(value) }
                if hasUsername {
                    Button { save(nil) } label: {
                        Text(L("cp_username_remove")).font(.dorr(15, .bold)).foregroundStyle(ch.danger).padding(.horizontal, 18).padding(.vertical, 14)
                            .background(ch.danger.opacity(0.1), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    .disabled(busy)
                }
            }
            .padding(.top, 14)
        }
        .task {
            value = (try? await ChatMoreApi.username().data?.username) ?? ""
            hasUsername = !value.isEmpty
        }
    }

    private func save(_ name: String?) {
        busy = true
        error = nil
        Task {
            do {
                // An empty string, not null: the server needs the field to clear it.
                let saved = try await ChatMoreApi.setUsername(name ?? "").data
                onSaved(saved?.username)
                host.showToast(L(name == nil ? "cp_username_removed" : "cp_username_saved"))
                onDismiss()
            } catch {
                self.error = error.apiFailure.message
            }
            busy = false
        }
    }
}

// MARK: - A folder's look

let lookColors: [String?] = [nil, "#2563EB", "#16A34A", "#D97706", "#DB2777", "#7C3AED", "#0891B2", "#DC2626"]
private let folderEmojis: [String?] = [nil, "💼", "👨‍👩‍👧", "❤️", "🎓", "🏠", "⚽", "✈️", "🛒", "⭐"]

/// A colour and an emoji for a folder's chip.
struct FolderLookSheet: View {
    @ObservedObject private var ch = Ch.shared
    let folder: FolderDto
    let onDismiss: () -> Void
    let onSaved: (FolderDto) -> Void
    @State private var color: String?
    @State private var emoji: String?
    @State private var loaded = false

    var body: some View {
        ChSheetCard {
            Text(L("cp_folder_look")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            // Preview of the chip.
            Text(verbatim: [emoji, folder.name].compactMap { $0 }.joined(separator: " ")).font(.dorr(14, .bold)).foregroundStyle(.white)
                .padding(.horizontal, 18).padding(.vertical, 9)
                .background(Color(hexString: color) ?? ch.red, in: RoundedRectangle(cornerRadius: 19))
                .padding(.top, 12)
            FlowLayout(spacing: 10, lineSpacing: 10) {
                ForEach(Array(lookColors.enumerated()), id: \.offset) { _, c in
                    Button { color = c } label: {
                        Circle().fill(Color(hexString: c) ?? ch.red).frame(width: 34, height: 34)
                            .overlay(Circle().stroke(color == c ? ch.ink.opacity(0.5) : .clear, lineWidth: 3))
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.top, 14)
            FlowLayout(spacing: 8, lineSpacing: 8) {
                ForEach(Array(folderEmojis.enumerated()), id: \.offset) { _, e in
                    Button { emoji = e } label: {
                        ZStack {
                            Circle().fill(emoji == e ? ch.red.opacity(0.16) : ch.surfaceMuted)
                            if let e { Text(verbatim: e).font(.system(size: 19)) } else { Image(systemName: "xmark").font(.system(size: 15, weight: .bold)).foregroundStyle(ch.mut) }
                        }
                        .frame(width: 40, height: 40)
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.top, 12)
            ChPrimaryButton(text: L("ch_save")) {
                Task {
                    if var updated = try? await ChatApi.renameFolder(folder.id, ["color": color ?? "", "emoji": emoji ?? ""]).data {
                        updated.conversationIds = folder.conversationIds
                        onSaved(updated)
                    }
                    onDismiss()
                }
            }
            .padding(.top, 16)
        }
        .onAppear {
            guard !loaded else { return }
            loaded = true
            color = folder.color
            emoji = folder.emoji
        }
    }
}
