import SwiftUI

// androidApp `chat/ChatLifeInboxUi.kt` — the life inbox (spec 147): not how many messages came, but what needs
// something from me — an answer, a payment, an approval, a follow-up, an appointment. Each row opens where it is.

private struct InboxSection {
    let key: String
    let emoji: String
    let title: String
    let color: Color
}

private let inboxOrder = [
    InboxSection(key: "reply", emoji: "💬", title: "li_reply", color: Color(hex: 0x2563EB)),
    InboxSection(key: "pay", emoji: "💸", title: "li_pay", color: Color(hex: 0x16A34A)),
    InboxSection(key: "approve", emoji: "✅", title: "li_approve", color: Color(hex: 0xF59E0B)),
    InboxSection(key: "follow", emoji: "📌", title: "li_follow", color: Color(hex: 0x7C3AED)),
    InboxSection(key: "appointments", emoji: "🗓️", title: "li_appointments", color: Color(hex: 0x0891B2)),
]

private extension Optional where Wrapped == LifeInboxCountsDto {
    func of(_ key: String) -> Int {
        switch key {
        case "reply": return self?.reply ?? 0
        case "pay": return self?.pay ?? 0
        case "approve": return self?.approve ?? 0
        case "follow": return self?.follow ?? 0
        default: return self?.appointments ?? 0
        }
    }
}

private extension LifeInboxDto {
    func items(_ key: String) -> [LifeItemDto] {
        switch key {
        case "reply": return reply ?? []
        case "pay": return pay ?? []
        case "approve": return approve ?? []
        case "follow": return follow ?? []
        default: return appointments ?? []
        }
    }
}

private extension LifeItemDto {
    var rowKey: String { id ?? messageId ?? "\(kind ?? "")-\(at ?? "")-\(title ?? "")" }
}

struct LifeInboxPage: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    @State private var data: LifeInboxDto?
    @State private var filter: String?
    @State private var items: [LifeItemDto] = []
    @State private var page = 1
    @State private var hasMore = false
    @State private var loadingMore = false
    @State private var loadedSection: String?
    @State private var query = ""
    @State private var shownTotal = 0

    var body: some View {
        ChSettingsPage(title: L("li_title"), onBack: { host.pop() }) {
            VStack(spacing: 0) {
                ChField(placeholder: L("li_search"), text: $query, icon: "magnifyingglass", limit: 80).padding(.vertical, 4)
                if let d = data {
                    content(d)
                } else {
                    ForEach(0..<4, id: \.self) { _ in RoundedRectangle(cornerRadius: 22).fill(ch.surfaceMuted).frame(height: 80).padding(.top, 16) }
                    Spacer()
                }
            }
            .padding(.horizontal, 16)
        }
        .task(id: query) {
            if !query.trimmingCharacters(in: .whitespaces).isEmpty { try? await Task.sleep(nanoseconds: 280_000_000) }
            guard !Task.isCancelled else { return }
            let body = (try? await LifeApi.lifeInbox(perPage: 15, q: q).data) ?? LifeInboxDto()
            data = body
            let first = inboxOrder.first { body.counts.of($0.key) > 0 }?.key
            if filter == nil || body.counts.of(filter!) == 0 { filter = first }
            if let section = filter ?? first {
                items = body.items(section)
                page = 1
                hasMore = body.counts.of(section) > items.count
                loadedSection = section
            } else {
                items = []
                page = 1
                hasMore = false
                loadedSection = nil
            }
        }
        .onChange(of: filter) { section in
            guard let section, data != nil, section != loadedSection else { return }
            Task { await load(section, reset: true) }
        }
    }

    private var q: String? { let t = query.trimmingCharacters(in: .whitespaces); return t.isEmpty ? nil : t }

    @ViewBuilder
    private func content(_ d: LifeInboxDto) -> some View {
        let chips = inboxOrder.filter { d.counts.of($0.key) > 0 }
        HStack(spacing: 8) {
            Image(systemName: "tray.fill").font(.system(size: 14)).foregroundStyle(.white).frame(width: 28, height: 28).background(Color.white.opacity(0.18), in: RoundedRectangle(cornerRadius: 9))
            Text(L("li_needs_you")).font(.dorr(13.5, .heavy)).foregroundStyle(.white).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
            Text(verbatim: (d.total ?? 0) == 0 ? L("li_nothing") : L("li_n_things", shownTotal)).font(.dorr(13.5, .heavy)).foregroundStyle(.white)
                .contentTransition(.numericText())
        }
        .padding(.horizontal, 12).padding(.vertical, 10)
        .background(LinearGradient(colors: [ch.red, ch.redDeep], startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .padding(.top, 12)
        .task(id: d.total) { await countUp(d.total ?? 0) }
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(chips, id: \.key) { s in
                    let on = filter == s.key
                    Button { filter = s.key } label: {
                        HStack(spacing: 6) {
                            Text(verbatim: s.emoji).font(.system(size: 14))
                            Text(verbatim: "\(d.counts.of(s.key))").font(.dorr(14, .heavy)).foregroundStyle(on ? .white : s.color)
                            Text(L(s.title)).font(.dorr(12.5, .bold)).foregroundStyle(on ? .white : ch.ink).lineLimit(1)
                        }
                        .padding(.horizontal, 12).padding(.vertical, 10)
                        .background(on ? s.color : ch.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    .buttonStyle(.plain)
                }
            }
        }
        .padding(.top, 12)
        if (d.messageRequests ?? 0) > 0 {
            Button { host.showRequests() } label: {
                HStack(spacing: 10) {
                    Image(systemName: "bubble.left.and.exclamationmark.bubble.right.fill").font(.system(size: 14)).foregroundStyle(ch.red)
                        .frame(width: 32, height: 32).background(ch.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 10))
                    Text(L("ch_requests_banner")).font(.dorr(14, .bold)).foregroundStyle(ch.ink).frame(maxWidth: .infinity, alignment: .leading)
                    ChBadge(count: d.messageRequests ?? 0)
                }
                .padding(14)
                .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            }
            .buttonStyle(.plain)
            .padding(.top, 12)
        }
        if (d.total ?? 0) == 0 {
            ChEmptyState(icon: "tray.fill", title: L("li_empty_title"), text: L("li_empty_text")).padding(.top, 20)
            Spacer()
        } else {
            let color = inboxOrder.first { $0.key == filter }?.color ?? ch.red
            ScrollView {
                LazyVStack(spacing: 0) {
                    ForEach(Array(items.enumerated()), id: \.element.rowKey) { i, item in
                        InboxRow(item: item, color: color, index: i) { open(item) }
                            .onAppear { if i >= items.count - 3, let s = filter { Task { await load(s, reset: false) } } }
                    }
                    if loadingMore { ProgressView().tint(ch.red).padding(16) }
                }
                .padding(.top, 14).padding(.bottom, 24)
            }
        }
    }

    private func countUp(_ target: Int) async {
        let start = shownTotal
        let steps = 20
        for s in 1...steps {
            try? await Task.sleep(nanoseconds: 35_000_000)
            withAnimation { shownTotal = start + (target - start) * s / steps }
        }
    }

    private func open(_ item: LifeItemDto) {
        if item.kind == "decision", let id = item.id {
            host.push(.decisionRoom(id))
        } else if ["appointment", "task"].contains(item.kind ?? "") {
            host.push(item.kind == "task" ? .tasks : .commitments)
        } else if let c = item.conversationId {
            if let m = item.messageId { host.focusRequest = (c, m) }
            host.push(.conversation(c))
        }
    }

    private func load(_ section: String, reset: Bool) async {
        if !reset && (loadingMore || !hasMore) { return }
        if !reset { loadingMore = true }
        let next = reset ? 1 : page + 1
        let envelope = try? await LifeApi.lifeInbox(section: section, page: next, perPage: 15, q: q)
        if let body = envelope?.data { data = body }
        let chunk = envelope?.data?.items(section) ?? []
        if reset { items = chunk } else {
            let known = Set(items.map(\.rowKey))
            items += chunk.filter { !known.contains($0.rowKey) }
        }
        page = next
        hasMore = envelope?.pagination?.hasMorePages == true
        loadedSection = section
        if !reset { loadingMore = false }
    }
}

private func kindLabel(_ kind: String?) -> String {
    let known = ["needs_reply", "mention", "money_request", "bill_split", "commitment", "handoff", "agreement", "join_request", "decision",
                 "my_commitment", "my_handoff", "waiting_for", "task"]
    return L("li_k_" + (known.contains(kind ?? "") ? kind! : "appointment"))
}

private struct InboxRow: View {
    @ObservedObject private var ch = Ch.shared
    let item: LifeItemDto
    let color: Color
    let index: Int
    let onClick: () -> Void
    @State private var open = false
    @State private var enter: CGFloat = 0

    var body: some View {
        let late = item.late == true
        let tint = late ? ch.danger : color
        let whenText = item.allDay == true ? item.date : lifeWhen(item.dueAt ?? (["appointment", "task"].contains(item.kind ?? "") ? item.at : nil))
        let line = [whenText, item.location].compactMap { $0 }.joined(separator: " · ")
        Button(action: onClick) {
            HStack(spacing: 12) {
                Capsule().fill(tint).frame(width: 4, height: 36)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: [kindLabel(item.kind), item.by ?? item.chat].compactMap { $0 }.joined(separator: " · ")).font(.dorr(11.5, .bold)).foregroundStyle(tint).lineLimit(1)
                    Text(verbatim: (item.title ?? "").isEmpty ? "—" : item.title!).font(.dorr(14.5, .semibold)).foregroundStyle(ch.ink).lineLimit(open ? 8 : 1)
                        .multilineTextAlignment(.leading).padding(.top, 2)
                    if !line.isEmpty { Text(verbatim: (late ? "⏰ " : "") + line).font(.dorr(12)).foregroundStyle(late ? ch.danger : ch.mut) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                VStack(spacing: 4) {
                    Button { withAnimation { open.toggle() } } label: {
                        Image(systemName: open ? "chevron.up" : "chevron.down").font(.system(size: 13, weight: .semibold)).foregroundStyle(ch.mut)
                            .frame(width: 28, height: 28).background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 8))
                    }
                    .buttonStyle(.plain)
                    if let minor = item.amountMinor {
                        Text(verbatim: String(format: "%.2f %@", locale: Locale(identifier: "en_US_POSIX"), Double(minor) / 100, item.currency ?? ""))
                            .font(.dorr(12, .heavy)).foregroundStyle(Color(hex: 0x16A34A)).lineLimit(1)
                    }
                }
            }
            .padding(14)
            .background(ch.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(.plain)
        .padding(.bottom, 8)
        .opacity(enter)
        .offset(y: (1 - enter) * 18)
        .onAppear { withAnimation(.easeOut(duration: 0.36).delay(min(Double(index) * 0.04, 0.32))) { enter = 1 } }
    }
}
